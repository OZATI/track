<?php
// Webhook de vendas da Kiwify.
//
// Cadastre na Kiwify (Apps > Webhooks) a URL que o instalar.php mostra, com a chave:
//   https://track.SEU-DOMINIO/kiwify.php?chave=CHAVE
// A documentacao da Kiwify nao descreve assinatura dos avisos, entao a protecao e a
// chave secreta na URL: sem ela, nada e gravado.
//
// LGPD: nome, e-mail, telefone e CPF do comprador NAO sao gravados. So o pedido, o
// produto, o valor, o status e as etiquetas de rastreio.

require __DIR__ . '/lib/util.php';

header('Cache-Control: no-store');
$cfg = track_config();
if (!$cfg) {
    responder_json(503, ['ok' => false]);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    responder_json(405, ['ok' => false]);
}
$chave = $_GET['chave'] ?? '';
if (!is_string($chave) || empty($cfg['chave_webhook']) || !hash_equals($cfg['chave_webhook'], $chave)) {
    responder_json(401, ['ok' => false]);
}
if (!dentro_do_limite('kiwify:' . ip_cliente(), 300, 60)) {
    responder_json(429, ['ok' => false]);
}

$dados = json_decode((string)file_get_contents('php://input', false, null, 0, 262144), true);
if (!is_array($dados)) {
    responder_json(400, ['ok' => false]);
}
// Conforme a versao, a Kiwify manda o pedido na raiz ou dentro de "order"
$p = (isset($dados['order']) && is_array($dados['order'])) ? $dados['order'] : $dados;

$pedido = texto($p['order_id'] ?? $p['id'] ?? $p['order_ref'] ?? $p['reference'] ?? '', 100);
if ($pedido === '') {
    responder_json(400, ['ok' => false]);
}
$evento = texto($dados['webhook_event_type'] ?? $p['webhook_event_type'] ?? '', 60);
$status = texto($p['order_status'] ?? $p['status'] ?? '', 40);
$produto = texto($p['Product']['product_name'] ?? $p['product']['name'] ?? $p['product_name'] ?? '', 200);
$pagamento = texto($p['payment_method'] ?? '', 40);

// Valor em centavos. A Kiwify manda inteiro em centavos (5949); com ponto decimal, e em reais.
$bruto = $p['Commissions']['charge_amount'] ?? $p['payment']['charge_amount'] ?? $p['charge_amount'] ?? null;
$valor = null;
if (is_int($bruto) || (is_string($bruto) && preg_match('/^\d+$/', $bruto))) {
    $valor = (int)$bruto;
} elseif (is_float($bruto) || (is_string($bruto) && is_numeric($bruto))) {
    $valor = (int)round((float)$bruto * 100);
}

$t = $p['TrackingParameters'] ?? $p['tracking'] ?? [];
$t = is_array($t) ? $t : [];
$campo = function (string $k) use ($t) {
    $v = texto($t[$k] ?? '', 300);
    return $v === '' ? null : $v;
};
$sck = $campo('sck');
$referencia = texto($p['order_ref'] ?? $p['reference'] ?? '', 40);
$visitante = ($sck && preg_match('/^trk_([a-f0-9]{32})$/', $sck, $m)) ? $m[1] : null;

$agora = agora_utc();
$db = track_db();
// fonte: 'webhook'; se a busca pela API ja tinha trazido a venda, vira 'ambos'
$db->prepare('INSERT INTO vendas (pedido, evento, status, produto, valor, pagamento, recebida_em, atualizada_em, visitante, sck, src,
                                  utm_source, utm_medium, utm_campaign, utm_content, utm_term, referencia, fonte)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'webhook\')
              ON CONFLICT (pedido) DO UPDATE SET
                  fonte = CASE WHEN vendas.fonte IN (\'api\', \'ambos\') THEN \'ambos\' ELSE \'webhook\' END,
                  referencia = COALESCE(vendas.referencia, NULLIF(excluded.referencia, \'\')),
                  evento = excluded.evento,
                  status = excluded.status,
                  atualizada_em = excluded.atualizada_em,
                  produto = COALESCE(NULLIF(excluded.produto, \'\'), vendas.produto),
                  valor = COALESCE(excluded.valor, vendas.valor),
                  pagamento = COALESCE(NULLIF(excluded.pagamento, \'\'), vendas.pagamento),
                  visitante = COALESCE(vendas.visitante, excluded.visitante)')
    ->execute([$pedido, $evento, $status, $produto, $valor, $pagamento, $agora, $agora, $visitante, $sck, $campo('src'),
        $campo('utm_source'), $campo('utm_medium'), $campo('utm_campaign'), $campo('utm_content'), $campo('utm_term'), $referencia]);

responder_json(200, ['ok' => true]);
