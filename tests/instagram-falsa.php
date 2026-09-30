<?php
// API do Instagram falsa, so para o tests/fluxo.sh (servidor embutido do PHP, com este
// arquivo como roteador). Imita /me, /me/insights, /me/media, /<post>/insights,
// /refresh_access_token e o CDN das capas dos posts (/midia/<id>.jpg).
//
//   token IGAATeste... ou IGAARenovado...  -> tudo liberado
//   token IGAASemInsights...               -> perfil e posts, insights recusados
//   token IGAASemViews...                  -> a metrica "views" da conta e recusada
//   outro                                  -> erro 190

$rota = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// CDN das capas: uma imagem 600 x 750 (ou um JPEG minimo, sem a biblioteca de imagem)
if (preg_match('~^/midia/\d+\.jpg$~', $rota)) {
    header('Content-Type: image/jpeg');
    if (function_exists('imagecreatetruecolor')) {
        $im = imagecreatetruecolor(600, 750);
        imagefill($im, 0, 0, imagecolorallocate($im, 29, 111, 242));
        imagejpeg($im, null, 70);
    } else {
        echo base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
    }
    exit;
}
header('Content-Type: application/json');
parse_str((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $q);
$token = preg_replace('/^Bearer\s+/i', '', $_SERVER['HTTP_AUTHORIZATION'] ?? '');
$erro = function (int $http, int $codigo, string $msg) {
    http_response_code($http);
    echo json_encode(['error' => ['message' => $msg, 'type' => 'OAuthException', 'code' => $codigo]]);
    exit;
};
$de = fn(string $prefixo) => strpos($token, $prefixo) === 0;

if (!$de('IGAATeste') && !$de('IGAARenovado') && !$de('IGAASemInsights') && !$de('IGAASemViews')) {
    $erro(400, 190, 'Invalid OAuth access token - Cannot parse access token');
}
if ($rota === '/ig/refresh_access_token') {
    echo json_encode(['access_token' => 'IGAARenovado' . str_repeat('0', 60), 'token_type' => 'bearer', 'expires_in' => 5184000]);
    exit;
}
if ($rota === '/ig/v23.0/me') {
    echo json_encode(['user_id' => '17841400000000000', 'username' => 'engdesk', 'name' => 'EngDesk', 'account_type' => 'BUSINESS',
        'followers_count' => 1234, 'follows_count' => 50, 'media_count' => 2]);
    exit;
}
$metricas = explode(',', (string)($q['metric'] ?? ''));
if ($rota === '/ig/v23.0/me/insights') {
    if ($de('IGAASemInsights')) {
        $erro(403, 10, 'Application does not have permission for this action');
    }
    if ($de('IGAASemViews') && in_array('views', $metricas, true)) {
        $erro(400, 100, '(#100) metric[1] must be one of the following values: reach, accounts_engaged, total_interactions, profile_links_taps');
    }
    if (($q['metric'] ?? '') === 'follows_and_unfollows') {
        echo json_encode(['data' => [['name' => 'follows_and_unfollows', 'period' => 'day', 'total_value' => ['breakdowns' => [[
            'dimension_keys' => ['follow_type'],
            'results' => [['dimension_values' => ['FOLLOWER'], 'value' => 5], ['dimension_values' => ['NON_FOLLOWER'], 'value' => 2]],
        ]]]]]]);
        exit;
    }
    $valores = ['reach' => 100, 'views' => 300, 'accounts_engaged' => 20, 'total_interactions' => 40, 'profile_links_taps' => 7];
    $dados = [];
    foreach ($metricas as $m) {
        if (!isset($valores[$m])) {
            $erro(400, 100, '(#100) Invalid metric');
        }
        $dados[] = ['name' => $m, 'period' => 'day', 'total_value' => ['value' => $valores[$m]]];
    }
    echo json_encode(['data' => $dados]);
    exit;
}
if ($rota === '/ig/v23.0/me/media') {
    $cdn = 'http://' . (getenv('TRACK_IG_MIDIA_HOST') ?: $_SERVER['HTTP_HOST']) . '/midia/';
    echo json_encode(['data' => [
        ['id' => '17900000000000001', 'caption' => 'Reel do Drive de Projetos', 'media_type' => 'VIDEO', 'media_product_type' => 'REELS',
            'permalink' => 'https://www.instagram.com/reel/abc/', 'timestamp' => gmdate('Y-m-d\TH:i:s+0000', time() - 3600), 'like_count' => 50, 'comments_count' => 5,
            'media_url' => $cdn . 'video.mp4', 'thumbnail_url' => $cdn . '17900000000000001.jpg'],
        ['id' => '17900000000000002', 'caption' => 'Post da planta', 'media_type' => 'IMAGE', 'media_product_type' => 'FEED',
            'permalink' => 'https://www.instagram.com/p/def/', 'timestamp' => gmdate('Y-m-d\TH:i:s+0000', time() - 2 * 86400), 'like_count' => 20, 'comments_count' => 2,
            'media_url' => $cdn . '17900000000000002.jpg'],
    ]]);
    exit;
}
if (preg_match('~^/ig/v23\.0/(\d+)/insights$~', $rota, $m)) {
    if ($de('IGAASemInsights')) {
        $erro(403, 10, 'Application does not have permission for this action');
    }
    $reels = $m[1] === '17900000000000001';
    $valores = $reels
        ? ['reach' => 1000, 'views' => 2500, 'saved' => 30, 'shares' => 12, 'total_interactions' => 97, 'ig_reels_avg_watch_time' => 8500]
        : ['reach' => 400, 'views' => 600, 'saved' => 10, 'shares' => 3, 'total_interactions' => 35, 'profile_visits' => 15, 'follows' => 4];
    $dados = [];
    foreach ($metricas as $n) {
        if (!isset($valores[$n])) {
            $erro(400, 100, '(#100) The Media Insights API does not support the ' . $n . ' metric for this media product type.');
        }
        $dados[] = ['name' => $n, 'period' => 'lifetime', 'values' => [['value' => $valores[$n]]]];
    }
    echo json_encode(['data' => $dados]);
    exit;
}
$erro(404, 803, 'Rota desconhecida: ' . $rota);
