<?php
// Webhook da Meta: comentarios, mencoes e mensagens da pagina e do Instagram da marca
// (lib/meta_interacoes.php). No app da Meta, em Webhooks, cadastre a URL de callback e o token
// de verificacao que a tela Meta Ads (Integracoes) mostra.
//
// GET: a Meta confere o token de verificacao e o painel devolve o hub.challenge.
// POST: so grava aviso assinado com a chave secreta do app (X-Hub-Signature-256). Sem a chave
// cadastrada, nada e gravado.

require __DIR__ . '/lib/util.php';
require_once __DIR__ . '/lib/meta_interacoes.php';

header('Cache-Control: no-store');
if (!track_config()) {
    http_response_code(503);
    exit;
}
$metodo = $_SERVER['REQUEST_METHOD'] ?? '';

if ($metodo === 'GET') {
    // O PHP troca o ponto do nome por sublinhado: hub.mode chega como hub_mode
    $token = meta_webhook_token();
    $enviado = $_GET['hub_verify_token'] ?? '';
    $desafio = $_GET['hub_challenge'] ?? '';
    if (!dentro_do_limite('meta-webhook-verificar:' . ip_conexao(), 20, 600)) {
        http_response_code(429);
        exit;
    }
    if ($token !== '' && ($_GET['hub_mode'] ?? '') === 'subscribe' && is_string($enviado) && hash_equals($token, $enviado)
        && is_string($desafio) && preg_match('/^[A-Za-z0-9_\-]{1,200}$/', $desafio)) {
        definir_ajuste('meta_webhook_verificado_em', agora_utc());
        header('Content-Type: text/plain; charset=utf-8');
        echo $desafio;
        exit;
    }
    http_response_code(403);
    exit;
}

if ($metodo !== 'POST') {
    http_response_code(405);
    exit;
}
if (!dentro_do_limite('meta-webhook', 600, 60)) {
    http_response_code(429);
    exit;
}
$app = meta_app();
$corpo = (string)file_get_contents('php://input', false, null, 0, 524288);
if (!$app || !meta_webhook_assinatura_ok($corpo, (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ''), $app['segredo'])) {
    responder_json(403, ['ok' => false]);
}
$aviso = json_decode($corpo, true);
if (!is_array($aviso)) {
    responder_json(400, ['ok' => false]);
}
$n = meta_interacoes_gravar(meta_interacoes_do_aviso($aviso));
definir_ajuste('meta_webhook_aviso_em', agora_utc());
responder_json(200, ['ok' => true, 'gravadas' => $n]);
