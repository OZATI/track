<?php
// Foto de quem mandou mensagem (lib/meta_interacoes.php), so para quem entrou no painel. O
// endereco vem do banco (nunca da URL pedida), so do CDN da Meta, e a imagem passa direto: nao
// e gravada em lugar nenhum. A foto da Meta vence em dias; vencida, a tela mostra a inicial.

require __DIR__ . '/lib/util.php';
require_once __DIR__ . '/lib/meta_interacoes.php';

if (!track_config() || !logado()) {
    http_response_code(403);
    exit;
}
session_write_close(); // as fotos carregam em paralelo: nao prende a sessao

$pessoa = meta_id($_GET['p'] ?? '');
$url = $pessoa ? (string)valor(track_db(), 'SELECT pessoa_foto FROM meta_interacoes WHERE pessoa_id = ? AND pessoa_foto IS NOT NULL ORDER BY perfil_em DESC LIMIT 1', [$pessoa]) : '';
if ($url === '' || !ig_url_midia_ok($url)) {
    http_response_code(404);
    exit;
}
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
    CURLOPT_NOPROGRESS => false,
    // Mais de 2 MB: para no meio (nao e uma foto de perfil)
    CURLOPT_XFERINFOFUNCTION => fn($c, $total, $baixado) => $baixado > 2 * 1024 * 1024 ? 1 : 0,
]);
$bin = curl_exec($ch);
$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);
$info = is_string($bin) && $status === 200 ? @getimagesizefromstring($bin) : false;
$tipos = [IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png', IMAGETYPE_WEBP => 'image/webp'];
if (!$info || !isset($tipos[$info[2]])) {
    http_response_code(404);
    exit;
}
header('Content-Type: ' . $tipos[$info[2]]);
header('Content-Length: ' . strlen($bin));
header('Cache-Control: private, max-age=3600');
echo $bin;
