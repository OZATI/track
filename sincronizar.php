<?php
// Busca as vendas pela API da Kiwify (lib/kiwify_sync.php), o gasto na Meta e o perfil do
// Instagram, cada um no seu intervalo.
//
// Duas formas, sempre com login e token do formulario:
// - painel.js, em segundo plano, com o cabecalho X-CSRF: responde JSON. So busca se a
//   ultima busca tem mais de 10 minutos.
// - botao "Atualizar vendas" (formulario): busca na hora e volta para a tela de antes.
//   Com completa=1, rele o periodo todo.

require __DIR__ . '/lib/util.php';
require_once __DIR__ . '/lib/kiwify_sync.php';
require_once __DIR__ . '/lib/meta_sync.php';
require_once __DIR__ . '/lib/instagram_sync.php';

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
@set_time_limit(120);  // Kiwify, Meta e Instagram em sequencia podem passar de 30 s
ignore_user_abort(true); // sair da pagina no meio nao interrompe a busca

// Kiwify (vendas) e Meta (gasto, para o gestor de anuncios), cada uma com o seu intervalo
if ($json) {
    $resposta = ['ok' => true, 'buscou' => false, 'novas' => 0, 'atualizadas' => 0];
    if (kiwify_sync_vencida()) {
        $r = kiwify_sincronizar();
        $resposta = ['ok' => $r['ok'], 'buscou' => true, 'novas' => $r['novas'] ?? 0, 'atualizadas' => $r['atualizadas'] ?? 0];
    }
    if (meta_sync_vencida()) {
        $r = meta_sincronizar();
        $resposta['buscou'] = true;
        $resposta['ok'] = $resposta['ok'] && $r['ok'];
        $resposta['atualizadas'] += $r['ok'] ? 1 : 0; // gasto novo: a tela recarrega
    }
    if (ig_sync_vencida()) {
        $r = ig_sincronizar();
        $resposta['buscou'] = true;
        $resposta['ok'] = $resposta['ok'] && ($r['ok'] || !empty($r['ocupado']));
        $resposta['atualizadas'] += $r['ok'] ? 1 : 0;
    }
    responder_json(200, $resposta);
}

// Botao: no maximo uma busca por minuto em cada API (para todos os usuarios) e uma
// releitura completa das vendas a cada 10 minutos. Clique a mais so volta para a tela, sem
// erro: os dados acabaram de ser buscados. O limite por IP so segura abuso.
if (dentro_do_limite('sincronizar:' . ip_cliente(), 30, 600)) {
    if (time() - (int)(ajuste('kiwify_sync_tentativa') ?? 0) >= 60) {
        $completa = ($_POST['completa'] ?? '') === '1' && time() - (int)(ajuste('kiwify_sync_completa_em') ?? 0) >= 600;
        kiwify_sincronizar($completa);
    }
    if (meta_api_chave() && time() - (int)(ajuste('meta_sync_tentativa') ?? 0) >= 60) {
        meta_sincronizar();
    }
    if (ig_api_chave() && time() - (int)(ajuste('ig_sync_tentativa') ?? 0) >= 60) {
        ig_sincronizar();
    }
}
header('Location: ' . destino_seguro((string)($_POST['volta'] ?? '')));
