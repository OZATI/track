<?php
// Capa de um post do Instagram, para o feed da aba Organico. As imagens ficam na pasta de
// dados, fora do site (lib/instagram_sync.php baixa e reduz); so quem entrou no painel ve.
// Sao da mesma origem do painel, entao a CSP continua sem liberar nenhum site de fora.

require __DIR__ . '/lib/util.php';
require_once __DIR__ . '/lib/instagram_sync.php';

if (!track_config() || !logado()) {
    http_response_code(403);
    exit;
}
session_write_close(); // as capas carregam em paralelo: nao prende a sessao

$id = (string)($_GET['id'] ?? '');
$arq = preg_match('/^\d{6,25}$/', $id) ? ig_arquivo_miniatura($id) : '';
if ($arq === '' || !is_file($arq)) {
    http_response_code(404);
    exit;
}
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($arq));
header('Cache-Control: private, max-age=604800');
readfile($arq);
