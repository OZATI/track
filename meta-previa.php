<?php
// Previa de um anuncio para o popup do gestor (o olho da linha, nivel Anuncios). So le, por GET:
// meta-previa.php?id=<id do anuncio>&formato=feed|stories|reels (sem formato: o primeiro que a Meta tiver).
// O painel.js chama ao abrir o popup e ao trocar de anuncio ou de formato. A logica, o cache de 10 minutos
// e a reserva estao em lib/meta_criativo.php.
//
// Resposta (JSON): {ok, id, status {selo, texto}, formatos [{id, nome}], formato, iframe_src, iframe_tam,
// reserva {img, tipo, texto, titulo, descricao, botao, gerenciador}, aviso}. Os textos sao texto puro: a
// tela poe com textContent. 403 sem acesso e 404 para anuncio que nao existe, com {ok: false, erro}.

require __DIR__ . '/lib/util.php';
require_once __DIR__ . '/lib/meta_criativo.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (!track_config() || !logado() || !usuario_pode('utm')) {
    responder_json(403, ['ok' => false, 'erro' => 'Sem acesso ao painel UTM. Entre de novo ou peça o acesso.']);
}
$usuario = (string)usuario_atual();
session_write_close();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    responder_json(405, ['ok' => false, 'erro' => 'Só leitura.']);
}

// So texto: ?id[]=1 vira 404, sem aviso do PHP
$id = is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
$obj = preg_match('/^\d{6,25}\z/', $id)
    ? (consulta(track_db(), "SELECT * FROM meta_objetos WHERE id = ? AND nivel = 'ad'", [$id])[0] ?? null)
    : null;
if (!$obj) {
    responder_json(404, ['ok' => false, 'erro' => 'Anúncio não encontrado. Atualize o gestor e tente de novo.']);
}
$formato = is_string($_GET['formato'] ?? null) ? $_GET['formato'] : '';
if (!isset(META_PREVIA_FORMATOS[$formato])) {
    $formato = '';
}
// Cada abertura conta para o usuario: passou de 120 em 10 minutos, o popup mostra a reserva (sem chamar a
// Meta) ate a janela andar. A Meta tem ainda o limite geral de lib/meta_api.php.
$podeChamar = dentro_do_limite('meta-previa:' . $usuario, 120, 600);
responder_json(200, meta_previa($obj, $formato, $podeChamar));
