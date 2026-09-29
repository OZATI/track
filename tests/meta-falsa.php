<?php
// Graph API da Meta falsa, so para o tests/fluxo.sh (servidor embutido do PHP, com este
// arquivo como roteador). Imita /me, /me/permissions, /act_<conta> e /act_<conta>/insights.
//
//   token TokenLeitura...   -> so ads_read
//   token TokenGerencia...  -> ads_read + ads_management (o painel deve recusar)
//   conta 587364236934346   -> DRIVE DE PROJETOS; outra conta -> erro 100

header('Content-Type: application/json');
$rota = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$token = preg_replace('/^Bearer\s+/i', '', $_SERVER['HTTP_AUTHORIZATION'] ?? '');
$erro = function (int $http, int $codigo, string $msg) {
    http_response_code($http);
    echo json_encode(['error' => ['message' => $msg, 'type' => 'OAuthException', 'code' => $codigo]]);
    exit;
};

if (strpos($token, 'TokenLeitura') !== 0 && strpos($token, 'TokenGerencia') !== 0) {
    $erro(400, 190, 'Invalid OAuth access token - Cannot parse access token');
}
if ($rota === '/graph/me') {
    echo json_encode(['id' => '100', 'name' => 'Painel UTM']);
    exit;
}
if ($rota === '/graph/me/permissions') {
    $dados = [['permission' => 'ads_read', 'status' => 'granted']];
    if (strpos($token, 'TokenGerencia') === 0) {
        $dados[] = ['permission' => 'ads_management', 'status' => 'granted'];
    }
    echo json_encode(['data' => $dados]);
    exit;
}
if ($rota === '/graph/act_587364236934346') {
    echo json_encode(['name' => 'DRIVE DE PROJETOS', 'currency' => 'BRL', 'account_status' => 1, 'id' => 'act_587364236934346']);
    exit;
}
if ($rota === '/graph/act_587364236934346/insights') {
    echo json_encode(['data' => [['spend' => '1539.22', 'date_start' => '2026-09-22', 'date_stop' => '2026-09-28']]]);
    exit;
}
$erro(400, 100, 'Unsupported get request. Object with ID does not exist, cannot be loaded due to missing permissions');
