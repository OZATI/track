<?php
// Outbox: execute pelo cron. Nunca dispara Purchase da página nem ao criar Pix.
function pag_capi_payload(array $order, array $buyer, int $eventTime, string $source): array
{
    $phone = preg_replace('/\D/', '', $buyer['telefone']);
    if (strlen($phone) <= 11) $phone = '55' . $phone;
    $user = ['em' => [hash('sha256', strtolower(trim($buyer['email'])))], 'ph' => [hash('sha256',$phone)]];
    $attribution = json_decode($buyer['atribuicao'] ?? '{}', true) ?: [];
    foreach (['fbp','fbc'] as $key) {
        if (preg_match('/^fb\.[0-9]+\.[0-9]+\.[A-Za-z0-9_.-]{1,200}$/D', $attribution[$key] ?? '')) $user[$key] = $attribution[$key];
    }
    return ['data' => [['event_name'=>'Purchase','event_id'=>'mp:'.$order['provider_id'],
        'event_time'=>$eventTime,'action_source'=>'website','event_source_url'=>$source.'/checkout/?p='.rawurlencode($order['projeto']),
        'user_data'=>$user,'custom_data'=>['currency'=>'BRL','value'=>$order['total']/100,
            'content_ids'=>[$order['projeto']],'content_type'=>'product']]]];
}

function pag_capi_enviar(array $cfg, array $payload): bool
{
    if (!preg_match('/^v[0-9]{2}\.0$/D', $cfg['meta_version'] ?? '') || !preg_match('/^[0-9]+$/D',$cfg['meta_pixel']??'')) return false;
    $ch = curl_init('https://graph.facebook.com/'.$cfg['meta_version'].'/'.$cfg['meta_pixel'].'/events');
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$cfg['meta_token']],
        CURLOPT_POSTFIELDS=>json_encode($payload),CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    $data = json_decode($raw ?: '',true);
    return $code===200 && (int)($data['events_received']??0)===1;
}

function pag_capi_processar(PDO $db, array $cfg, ?callable $send = null): int
{
    if (empty($cfg['meta_token']) || empty($cfg['meta_pixel']) || empty($cfg['meta_version'])) return 0;
    // Homologação só envia ao Test Events, nunca ao relatório real.
    if (($cfg['ambiente']??'teste') !== 'producao' && empty($cfg['meta_test_code'])) return 0;
    $s = $db->prepare("SELECT e.*, p.* FROM csh_capi e JOIN csh_pedidos p ON p.id=e.pedido
        WHERE e.enviado=0 AND e.proxima<=? AND e.evento_em>=? AND p.status='aprovada' LIMIT 20");
    $s->execute([time(),time()-86400]);
    $sent = 0;
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $claim = $db->prepare('UPDATE csh_capi SET proxima=?,tentativas=tentativas+1 WHERE pedido=? AND enviado=0 AND proxima=?');
        $claim->execute([time()+300,$row['id'],$row['proxima']]);
        if (!$claim->rowCount()) continue;
        $b = $db->prepare('SELECT * FROM csh_compradores WHERE pedido=?'); $b->execute([$row['id']]);
        $buyer = $b->fetch(PDO::FETCH_ASSOC); if (!$buyer) continue;
        $payload = pag_capi_payload($row,$buyer,(int)$row['evento_em'],$cfg['origem']??'https://engdesk.pro');
        if (!empty($cfg['meta_test_code'])) $payload['test_event_code']=$cfg['meta_test_code'];
        if (($send ?? 'pag_capi_enviar')($cfg,$payload)) {
            $db->prepare('UPDATE csh_capi SET enviado=1 WHERE pedido=?')->execute([$row['id']]); $sent++;
        }
    }
    return $sent;
}
