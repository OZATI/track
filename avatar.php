<?php
// Foto do perfil de um usuario do painel (lib/perfil.php). Fica na pasta de dados, fora do site;
// so quem entrou no painel ve.

require __DIR__ . '/lib/util.php';
require_once __DIR__ . '/lib/perfil.php';

if (!track_config() || !logado()) {
    http_response_code(403);
    exit;
}
session_write_close();

$u = (string)($_GET['u'] ?? '');
$arq = usuario_valido($u) && isset(track_usuarios()[$u]) ? avatar_arquivo($u) : '';
if ($arq === '' || !is_file($arq)) {
    http_response_code(404);
    exit;
}
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($arq));
header('Cache-Control: private, max-age=2592000');
readfile($arq);
