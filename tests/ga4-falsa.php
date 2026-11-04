<?php
// Google falso (token OAuth e Data API do GA4), so para o tests/fluxo.sh (servidor embutido do
// PHP, com este arquivo como roteador). Confere a assinatura do JWT com a chave publica de
// TRACK_GA4_CHAVE_PUBLICA, como o Google faz, e devolve relatorios fixos.
//
//   propriedade 111111111 -> site (www.site.test e site.test)
//   propriedade 222222222 -> app (app.site.test), com limiar
//   propriedade 999999999 -> 403 sem acesso;  888888888 -> 403 API desligada
//   client_email recusada@... -> 400 invalid_grant (chave apagada no Google Cloud)

header('Content-Type: application/json');
$rota = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$b64 = fn(string $s) => base64_decode(strtr($s, '-_', '+/'));
function falha(int $status, array $corpo): void
{
    http_response_code($status);
    echo json_encode($corpo);
    exit;
}

if ($rota === '/token' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $partes = explode('.', (string)($_POST['assertion'] ?? ''));
    $publica = openssl_pkey_get_public((string)@file_get_contents((string)getenv('TRACK_GA4_CHAVE_PUBLICA')));
    if (($_POST['grant_type'] ?? '') !== 'urn:ietf:params:oauth:grant-type:jwt-bearer' || count($partes) !== 3 || !$publica
        || openssl_verify($partes[0] . '.' . $partes[1], $b64($partes[2]), $publica, OPENSSL_ALGO_SHA256) !== 1) {
        falha(400, ['error' => 'invalid_grant', 'error_description' => 'Invalid JWT Signature.']);
    }
    $c = json_decode($b64($partes[1]), true);
    if (($c['aud'] ?? '') !== 'https://oauth2.googleapis.com/token' || ($c['scope'] ?? '') !== 'https://www.googleapis.com/auth/analytics.readonly'
        || ($c['exp'] ?? 0) - ($c['iat'] ?? 0) > 3600 || strpos((string)($c['iss'] ?? ''), 'recusada@') === 0) {
        falha(400, ['error' => 'invalid_grant', 'error_description' => 'Invalid grant: account not found']);
    }
    echo json_encode(['access_token' => 'tok-ga4', 'expires_in' => 3599, 'token_type' => 'Bearer']);
    exit;
}

if (!preg_match('~^/v1beta/properties/(\d+)(:runReport|/metadata)$~', $rota, $m)) {
    falha(404, ['error' => ['code' => 404, 'message' => 'rota desconhecida']]);
}
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer tok-ga4') {
    falha(401, ['error' => ['code' => 401, 'message' => 'Request had invalid authentication credentials.', 'status' => 'UNAUTHENTICATED']]);
}
[$prop, $tipo] = [$m[1], $m[2]];
if ($prop === '999999999') {
    falha(403, ['error' => ['code' => 403, 'message' => 'User does not have sufficient permissions for this property.', 'status' => 'PERMISSION_DENIED']]);
}
if ($prop === '888888888') {
    falha(403, ['error' => ['code' => 403, 'message' => 'Google Analytics Data API has not been used in project 123 before or it is disabled.', 'status' => 'PERMISSION_DENIED',
        'details' => [['reason' => 'SERVICE_DISABLED']]]]);
}

if ($tipo === '/metadata') {
    echo json_encode(['name' => "properties/$prop/metadata",
        'dimensions' => [['apiName' => 'eventName', 'uiName' => 'Event name'], ['apiName' => 'customEvent:formato', 'uiName' => 'formato', 'customDefinition' => true]],
        'metrics' => [['apiName' => 'eventCount', 'uiName' => 'Event count']]]);
    exit;
}

$pedido = json_decode((string)file_get_contents('php://input'), true) ?: [];
$dims = array_column($pedido['dimensions'] ?? [], 'name');
$tz = new DateTimeZone('America/Sao_Paulo');
$hoje = (new DateTime('today', $tz))->format('Ymd');
$ontem = (new DateTime('yesterday', $tz))->format('Ymd');
$linhas = [];
if (!$dims) { // teste de conexao: so o total
    $linhas = [[[], [1234]]];
} elseif ($dims === ['date', 'hostName', 'eventName']) {
    $linhas = $prop === '111111111' ? [
        [[$hoje, 'www.site.test', 'page_view'], [90, 40, 0, 0]],
        [[$ontem, 'site.test', 'page_view'], [50, 25, 0, 0]],
        [[$hoje, 'www.site.test', 'begin_checkout'], [9, 8, 0, 0]],
        [[$hoje, 'www.site.test', 'scroll'], [30, 20, 0, 0]],
    ] : [
        [[$hoje, 'app.site.test', 'page_view'], [40, 10, 0, 0]],
        [[$hoje, 'app.site.test', 'purchase'], [2, 2, 134, 2]],
        [[$hoje, 'app.site.test', 'baixar_arquivo'], [5, 3, 0, 0]],
    ];
} elseif ($dims === ['date', 'hostName', 'pagePath']) {
    $linhas = $prop === '111111111' ? [
        [[$hoje, 'www.site.test', '/index.html'], [60, 30]],
        [[$hoje, 'site.test', '/'], [30, 20]],
        [[$ontem, 'site.test', '/drivedeprojetos/'], [50, 25]],
    ] : [[[$hoje, 'app.site.test', '/'], [40, 10]]];
} elseif ($dims === ['date', 'hostName', 'sessionSource', 'sessionMedium', 'sessionCampaignName']) {
    $linhas = $prop === '111111111' ? [
        [[$hoje, 'www.site.test', 'MetaAds', 'Conjunto|123', 'Campanha|456'], [70, 60, 0]],
        [[$hoje, 'www.site.test', 'google', 'organic', '(organic)'], [20, 18, 0]],
    ] : [[[$hoje, 'app.site.test', '(direct)', '(none)', '(direct)'], [12, 10, 2]]];
} else {
    falha(400, ['error' => ['code' => 400, 'message' => 'Dimensoes inesperadas: ' . implode(',', $dims), 'status' => 'INVALID_ARGUMENT']]);
}
echo json_encode([
    'rows' => array_map(fn($l) => ['dimensionValues' => array_map(fn($v) => ['value' => $v], $l[0]),
        'metricValues' => array_map(fn($v) => ['value' => (string)$v], $l[1])], $linhas),
    'rowCount' => count($linhas),
    'metadata' => ['currencyCode' => 'BRL', 'timeZone' => 'America/Sao_Paulo'] + ($prop === '222222222' ? ['subjectToThresholding' => true] : []),
    'propertyQuota' => ['tokensPerDay' => ['consumed' => 12, 'remaining' => 199988]],
    'kind' => 'analyticsData#runReport',
]);
