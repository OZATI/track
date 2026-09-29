<?php
// Busca as vendas pela API da Kiwify (lib/kiwify_sync.php).
//
// Duas formas, sempre com login e token do formulario:
// - painel.js, em segundo plano, com o cabecalho X-CSRF: responde JSON. So busca se a
//   ultima busca tem mais de 10 minutos.
// - botao "Atualizar vendas" (formulario): busca na hora e volta para a tela de antes.
//   Com completa=1, rele o periodo todo.

require __DIR__ . '/lib/util.php';
require_once __DIR__ . '/lib/kiwify_sync.php';

header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    responder_json(405, ['ok' => false]);
}
$json = isset($_SERVER['HTTP_X_CSRF']);
if (!track_config() || !logado()) {
    $json ? responder_json(401, ['ok' => false]) : header('Location: entrar.php');
    exit;
}
if (!csrf_valido()) {
    $json ? responder_json(403, ['ok' => false]) : header('Location: ./');
    exit;
}
session_write_close(); // a busca pode demorar: nao prende as outras abas do painel

if ($json) {
    if (!kiwify_sync_vencida()) {
        responder_json(200, ['ok' => true, 'buscou' => false]);
    }
    $r = kiwify_sincronizar();
    responder_json(200, ['ok' => $r['ok'], 'buscou' => true, 'novas' => $r['novas'] ?? 0, 'atualizadas' => $r['atualizadas'] ?? 0]);
}

// Botao: no maximo uma busca por minuto (para todos os usuarios) e uma releitura completa
// a cada 10 minutos. Clique a mais so volta para a tela, sem erro: as vendas acabaram de
// ser buscadas. O limite por IP so segura abuso.
$ultima = (int)(ajuste('kiwify_sync_tentativa') ?? 0);
if (time() - $ultima >= 60 && dentro_do_limite('sincronizar:' . ip_cliente(), 30, 600)) {
    $completa = ($_POST['completa'] ?? '') === '1' && time() - (int)(ajuste('kiwify_sync_completa_em') ?? 0) >= 600;
    kiwify_sincronizar($completa);
}
header('Location: ' . destino_seguro((string)($_POST['volta'] ?? '')));
