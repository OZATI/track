<?php
// Miniatura (ou imagem maior, tam=g) do criativo de um anuncio, para o gestor. lib/meta_criativo.php
// baixa, reduz e regrava pelo GD; o arquivo fica na pasta de dados, fora do site, com o nome so do id
// numerico do anuncio. Sai daqui so para quem entrou e tem o acesso UTM (o mesmo do gestor). E da mesma
// origem do painel: a CSP continua com img-src 'self' data:, e quem abre o painel nao fala com a Meta.
// O v= da tela muda quando o criativo muda, por isso o cache do navegador pode ser longo.

require __DIR__ . '/lib/util.php';
require_once __DIR__ . '/lib/meta_criativo.php';

if (!track_config() || !logado() || !usuario_pode('utm')) {
    http_response_code(403);
    exit;
}
session_write_close(); // as miniaturas carregam em paralelo: nao prende a sessao

// So texto: ?id[]=1 vira 404, sem aviso do PHP
$id = is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
$arq = meta_criativo_arquivo($id, ($_GET['tam'] ?? null) === 'g');
if ($arq === '' || !is_file($arq)) {
    http_response_code(404);
    exit;
}
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($arq));
header('Cache-Control: private, max-age=604800');
header('X-Content-Type-Options: nosniff');
readfile($arq);
