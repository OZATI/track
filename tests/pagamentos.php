<?php
require_once __DIR__ . '/../lib/pagamentos.php';
require_once __DIR__ . '/../lib/pagamentos/capi.php';
$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; }
function rejects(callable $fn, string $label): void { try { $fn(); } catch (Throwable $e) { check(true, $label); return; } check(false, $label); }
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
pag_schema($db);
$buyer = ['nome'=>'Comprador teste','email'=>'teste@example.test','telefone'=>'11999999999'];
$items = [['id'=>'item-1','nome'=>'RVT','centavos'=>2990]];
$order = pag_preparar($db, 'user-1','PRJ-001',$items,$buyer,['utm_source'=>'teste']);
check($order['total'] === 2990, 'Preço em centavos');
check(pag_preparar($db,'user-1','PRJ-001',$items,$buyer,[])['id'] === $order['id'], 'Preparação idempotente');
rejects(fn()=>pag_preparar($db,'user-1','PRJ-001',[['id'=>'item-2','centavos'=>500]],$buyer,[]),'Impede troca de cesta em pedido aberto');
foreach (['-1','1.001','1e2',null,[], 'NaN'] as $bad) rejects(fn()=>pag_centavos($bad),'Recusa valor inválido');
check(pag_centavos('29.9')===2990 && pag_centavos('0.01')===1,'Decimais exatos');
$payload = pag_mp_payload($order,$buyer,['metodo'=>'pix']);
check($payload['transactions']['payments'][0]['expiration_time']==='PT30M','Pix 30 minutos');
check($payload['total_amount']==='29.90','Valor enviado como string');
rejects(fn()=>pag_mp_payload($order,$buyer,['metodo'=>'cartao','token'=>'token','bandeira'=>'visa','tipo'=>'credit_card','parcelas'=>12]),'Parcelamento limitado');
// Medição de qualidade (05/11/2026): nome do comprador, itens e nome na fatura
$cartao = ['metodo'=>'cartao','token'=>'tok123','bandeira'=>'visa','tipo'=>'credit_card','parcelas'=>1];
check($payload['payer']['first_name']==='Comprador' && $payload['payer']['last_name']==='teste','Nome do comprador dividido');
check(pag_mp_nome_pagador('')===[] && pag_mp_nome_pagador('  Kenio ')===['first_name'=>'Kenio'],'Sem nome ou nome único');
check(!preg_match('/[<>"]/', implode(' ', pag_mp_nome_pagador('<b>Ana</b> "Lima"'))),'Nome sem sinal de HTML');
check($payload['items']===[['title'=>'PRJ-001 - RVT','description'=>'Arquivo digital','unit_price'=>'29.90','quantity'=>1,'external_code'=>'item-1']],'Itens com o preço do pedido');
check(!isset(pag_mp_payload($order,$buyer,$cartao)['transactions']['payments'][0]['payment_method']['statement_descriptor']),'Sem descritor definido pelo site, não manda');
define('PAG_MP_DESCRITOR', 'DRIVEPROJETOS');
check(pag_mp_payload($order,$buyer,$cartao)['transactions']['payments'][0]['payment_method']['statement_descriptor']==='DRIVEPROJETOS','Nome na fatura no cartão');
check(!isset($payload['transactions']['payments'][0]['payment_method']['statement_descriptor']) &&
    !isset(pag_mp_payload($order,$buyer,['metodo'=>'pix'])['transactions']['payments'][0]['payment_method']['statement_descriptor']),'Pix sem nome na fatura');
$plano = pag_mp_itens(['projeto'=>'plano-pdf-vitalicio','total'=>9700,'itens'=>json_encode([['id'=>'plano:pdf-vitalicio','nome'=>'Acesso vitalício aos PDFs','centavos'=>9700]])]);
check($plano[0]['title']==='Acesso vitalício aos PDFs' && $plano[0]['external_code']==='plano-pdf-vitalicio','Plano sem código de projeto e sem ":"');
rejects(fn()=>pag_mp_payload(array_replace($order,['total'=>1]),$buyer,['metodo'=>'pix']),'Itens que não somam o total não viram cobrança');
check(!isset(pag_mp_payload(array_replace($order,['itens'=>'[]']),$buyer,['metodo'=>'pix'])['items']),'Pedido sem itens sai como antes');
$raw = ['id'=>'ORD1234567890ABCDE','external_reference'=>$order['id'],'total_amount'=>'29.90',
    'status'=>'action_required','status_detail'=>'waiting_transfer','country_code'=>'BRA',
    'transactions'=>['payments'=>[['payment_method'=>['qr_code'=>'PIX_TESTE','qr_code_base64'=>'abc']]]]];
$calls = 0;
$fake = function($cfg,$method,$path,$body,$key) use (&$calls,$raw,$order) {
    $calls++; check($method==='POST' && $key===$order['id'],'Chave de idempotência estável'); return [201,$raw];
};
$pending = pag_cobrar($db,[],$order,['metodo'=>'pix'],$fake);
check($pending['status']==='pendente' && $pending['pix']==='PIX_TESTE','Pix criado');
pag_cobrar($db,[],$order,['metodo'=>'pix'],$fake);
check($calls===1,'Clique duplo com snapshot antigo não cobra de novo');
$approved = array_replace($raw,['status'=>'processed','status_detail'=>'accredited','total_paid_amount'=>'29.90']);
$paid = pag_aplicar($db,$pending,$approved);
check($paid['status']==='aprovada','Confirma valor aprovado');
pag_aplicar($db,$pending,$approved);
check((int)$db->query('SELECT COUNT(*) FROM csh_pedidos')->fetchColumn()===1,'Webhook repetido não duplica pedido');
check(pag_aplicar($db,$paid,$raw)['status']==='aprovada','Resposta pendente atrasada não regride aprovação');
foreach ([['total_amount'=>'1.00'],['external_reference'=>'outra'],['country_code'=>'ARG'],['id'=>'ORD9999999999999']] as $change) {
    rejects(fn()=>pag_aplicar($db,$paid,array_replace($approved,$change)),'Ordem adulterada recusada');
}
check(pag_mp_normalizar(array_replace($approved,['total_paid_amount'=>'1.00']),$order)['status']!=='aprovada','Valor insuficiente não libera acesso');
$refunded = pag_aplicar($db,$paid,array_replace($raw,['status'=>'refunded','status_detail'=>'refunded']));
check($refunded['status']==='reembolsada','Reembolso');
check(pag_aplicar($db,$refunded,$approved)['status']==='reembolsada','Aprovação atrasada não reativa reembolso');
$uncertain = pag_preparar($db,'user-2','PRJ-001',$items,$buyer,[]);
$timeouts = 0;
$timeout = function() use (&$timeouts) { $timeouts++; return [0,[]]; };
$uncertain = pag_cobrar($db,[],$uncertain,['metodo'=>'pix'],$timeout);
check($uncertain['status']==='incerto','Timeout é incerto');
pag_cobrar($db,[],$uncertain,['metodo'=>'pix'],$timeout);
check($timeouts===1,'Timeout não gera outra cobrança');
$failed = pag_preparar($db,'user-3','PRJ-001',$items,$buyer,[]);
$failed = pag_cobrar($db,[],$failed,['metodo'=>'cartao','token'=>'TOKEN_SECRETO','bandeira'=>'visa','tipo'=>'credit_card','parcelas'=>1,'identificacao'=>['type'=>'CPF','number'=>'12345678901']],fn()=>[422,[]]);
check($failed['status']==='recusada','Rejeição definitiva');
$stored = json_encode($db->query('SELECT * FROM csh_pedidos')->fetchAll(PDO::FETCH_ASSOC));
check(!str_contains($stored,'TOKEN_SECRETO') && !str_contains($stored,'12345678901') && !str_contains($stored,'teste@example'),'Pedido não guarda cartão/documento/comprador');
check(!str_contains(json_encode(pag_publico($paid)),'user-1'),'Resposta pública não expõe usuário');
$id = $raw['id']; $ts = '1793640000000'; $req = 'request-123'; $secret = 'TEST_ONLY';
$sig = hash_hmac('sha256','id:'.strtolower($id).';request-id:'.$req.';ts:'.$ts.';',$secret);
check(pag_mp_assinatura($id,$req,'ts='.$ts.',v1='.$sig,$secret),'Assinatura válida e ID normalizado');
check(!pag_mp_assinatura($id,$req,'ts='.$ts.',v1='.$sig,'errada'),'Assinatura inválida');
check(!pag_mp_assinatura($id,'outra','ts='.$ts.',v1='.$sig,$secret),'Request ID divergente');
check(!pag_mp_assinatura($id,$req,'ts='.$ts.',v1='.$sig.',v1='.$sig,$secret),'Assinatura ambígua recusada');
$capiOrder = pag_preparar($db,'user-4','PRJ-001',$items,$buyer,['fbp'=>'fb.1.123456.123456']);
$capiOrder = pag_aplicar($db,$capiOrder,array_replace($approved,['id'=>'ORDCAPI1234567890','external_reference'=>$capiOrder['id']]));
$capiCalls = 0;
$send = function($cfg,$payload) use (&$capiCalls) {
    $capiCalls++;
    check($payload['data'][0]['event_id']==='mp:ORDCAPI1234567890','Purchase com ID estável');
    check($payload['data'][0]['user_data']['em'][0]===hash('sha256','teste@example.test'),'Email SHA256');
    check($payload['data'][0]['user_data']['ph'][0]===hash('sha256','5511999999999'),'Telefone SHA256 normalizado');
    check(!str_contains(json_encode($payload),'teste@example'),'Purchase sem PII em texto');
    return true;
};
$capiCfg=['meta_token'=>'teste','meta_pixel'=>'123','meta_version'=>'v23.0','ambiente'=>'teste'];
check(pag_capi_processar($db,$capiCfg,$send)===0,'Teste sem código não polui Meta');
$capiCfg['meta_test_code']='TEST123';
check(pag_capi_processar($db,$capiCfg,$send)===1,'Outbox envia aprovado');
pag_capi_processar($db,$capiCfg,$send);
check($capiCalls===1,'Purchase não reenvia após sucesso');
check(!str_contains($capiOrder['origem'],'fbp'),'Cookie Meta só no comprador');
echo "$checks verificações passaram. Nenhuma chamada externa.\n";
