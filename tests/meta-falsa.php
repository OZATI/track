<?php
// Graph API da Meta falsa, so para o tests/fluxo.sh (servidor embutido do PHP, com este
// arquivo como roteador). Imita /me, /me/permissions, /act_<conta> e /act_<conta>/insights.
//
//   token TokenLeitura...   -> so ads_read
//   token TokenGerencia...  -> ads_read + ads_management (aceito, com aviso)
//   token TokenInstagram... -> ads_read + permissoes do Instagram; a pagina tem o @engdesk,
//                              e as consultas do Instagram vao para tests/instagram-falsa.php
//   conta 587364236934346   -> DRIVE DE PROJETOS; outra conta -> erro 100

header('Content-Type: application/json');
$rota = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$token = preg_replace('/^Bearer\s+/i', '', $_SERVER['HTTP_AUTHORIZATION'] ?? '');
$erro = function (int $http, int $codigo, string $msg) {
    http_response_code($http);
    echo json_encode(['error' => ['message' => $msg, 'type' => 'OAuthException', 'code' => $codigo]]);
    exit;
};

if (strpos($token, 'TokenLeitura') !== 0 && strpos($token, 'TokenGerencia') !== 0 && strpos($token, 'TokenInstagram') !== 0) {
    $erro(400, 190, 'Invalid OAuth access token - Cannot parse access token');
}
$comInstagram = strpos($token, 'TokenInstagram') === 0;
// Ligar e pausar pelo gestor: so o token que pode editar (TokenGerencia)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && preg_match('~^/graph/(\d+)$~', $rota)) {
    if (strpos($token, 'TokenGerencia') !== 0) {
        $erro(403, 200, '(#200) Requires ads_management permission to manage the object');
    }
    if (!in_array($_POST['status'] ?? '', ['ACTIVE', 'PAUSED'], true)) {
        $erro(400, 100, '(#100) Invalid parameter');
    }
    echo json_encode(['success' => true]);
    exit;
}
if ($rota === '/graph/me/accounts') {
    $pagina = ['id' => '300300', 'name' => 'EngDesk'];
    if ($comInstagram) {
        $pagina['instagram_business_account'] = ['id' => '17841400000000000', 'username' => 'engdesk'];
    }
    echo json_encode(['data' => [$pagina]]);
    exit;
}
// Conta do Instagram pelo login do Facebook: mesmas respostas da API do Instagram falsa
if (preg_match('~^/graph/(17841400000000000|179\d+)(/.*)?$~', $rota, $m)) {
    if (!$comInstagram) {
        $erro(400, 10, 'Application does not have permission for this action');
    }
    $novo = $m[1] === '17841400000000000' ? '/ig/v23.0/me' . ($m[2] ?? '') : '/ig/v23.0/' . $m[1] . ($m[2] ?? '');
    $_SERVER['REQUEST_URI'] = $novo . (($q = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY)) ? '?' . $q : '');
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer IGAATeste';
    require __DIR__ . '/instagram-falsa.php';
    exit;
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
    if ($comInstagram) {
        foreach (['instagram_basic', 'instagram_manage_insights', 'pages_show_list', 'pages_read_engagement'] as $p) {
            $dados[] = ['permission' => $p, 'status' => 'granted'];
        }
    }
    echo json_encode(['data' => $dados]);
    exit;
}
if ($rota === '/graph/act_587364236934346') {
    echo json_encode(['name' => 'DRIVE DE PROJETOS', 'currency' => 'BRL', 'account_status' => 1, 'id' => 'act_587364236934346']);
    exit;
}
// Gestor de anuncios: TL 1 ativa (conjunto 5 / cv 05) e FREE pausada
$listas = [
    '/graph/act_587364236934346/campaigns' => [
        ['id' => '120120', 'name' => 'TL 1', 'status' => 'ACTIVE', 'effective_status' => 'ACTIVE', 'daily_budget' => '4000'],
        ['id' => '555555', 'name' => 'FREE', 'status' => 'PAUSED', 'effective_status' => 'PAUSED', 'daily_budget' => '3000'],
    ],
    '/graph/act_587364236934346/adsets' => [
        ['id' => '111111', 'name' => 'conjunto 5', 'status' => 'ACTIVE', 'effective_status' => 'ACTIVE', 'campaign_id' => '120120'],
        ['id' => '444444', 'name' => 'conjunto free', 'status' => 'PAUSED', 'effective_status' => 'CAMPAIGN_PAUSED', 'campaign_id' => '555555'],
    ],
    '/graph/act_587364236934346/ads' => [
        ['id' => '222222', 'name' => 'cv 05', 'status' => 'ACTIVE', 'effective_status' => 'ACTIVE', 'adset_id' => '111111', 'campaign_id' => '120120'],
        ['id' => '333333', 'name' => 'cv free', 'status' => 'PAUSED', 'effective_status' => 'CAMPAIGN_PAUSED', 'adset_id' => '444444', 'campaign_id' => '555555'],
    ],
];
if (isset($listas[$rota])) {
    echo json_encode(['data' => $listas[$rota], 'paging' => ['cursors' => ['before' => 'a', 'after' => 'b']]]);
    exit;
}
// Analise diaria: compras da campanha TL 1 por idade e sexo (a FREE nao vendeu)
if (preg_match('~^/graph/(\d+)/insights$~', $rota, $m) && ($_GET['breakdowns'] ?? '') === 'age,gender') {
    $compra = fn(int $n) => [['action_type' => 'offsite_conversion.fb_pixel_purchase', 'value' => (string)$n], ['action_type' => 'omni_purchase', 'value' => (string)$n]];
    echo json_encode(['data' => $m[1] === '120120' ? [
        ['age' => '25-34', 'gender' => 'female', 'spend' => '20.00', 'actions' => $compra(2)],
        ['age' => '25-34', 'gender' => 'male', 'spend' => '15.00', 'actions' => $compra(1)],
        ['age' => '35-44', 'gender' => 'female', 'spend' => '10.00'],
        ['age' => '45-54', 'gender' => 'unknown', 'spend' => '5.00'],
    ] : [['age' => '25-34', 'gender' => 'male', 'spend' => '10.00']]]);
    exit;
}
if ($rota === '/graph/act_587364236934346/insights') {
    if (($_GET['breakdowns'] ?? '') === 'hourly_stats_aggregated_by_advertiser_time_zone') {
        // Gasto da conta por hora de hoje
        $hoje = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
        echo json_encode(['data' => [
            ['spend' => '20.00', 'hourly_stats_aggregated_by_advertiser_time_zone' => '00:00:00 - 00:59:59', 'date_start' => $hoje, 'date_stop' => $hoje],
            ['spend' => '40.00', 'hourly_stats_aggregated_by_advertiser_time_zone' => '01:00:00 - 01:59:59', 'date_start' => $hoje, 'date_stop' => $hoje],
        ]]);
        exit;
    }
    if (($_GET['level'] ?? '') === 'ad') {
        // Gasto de hoje por anuncio (dia no fuso da conta)
        $hoje = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
        echo json_encode(['data' => [
            ['ad_id' => '222222', 'adset_id' => '111111', 'campaign_id' => '120120', 'spend' => '50.00', 'impressions' => '4000', 'inline_link_clicks' => '60',
                'actions' => [['action_type' => 'offsite_conversion.fb_pixel_initiate_checkout', 'value' => '3'], ['action_type' => 'omni_initiated_checkout', 'value' => '3'],
                    ['action_type' => 'landing_page_view', 'value' => '50']],
                'date_start' => $hoje, 'date_stop' => $hoje],
            ['ad_id' => '333333', 'adset_id' => '444444', 'campaign_id' => '555555', 'spend' => '10.00', 'impressions' => '900', 'inline_link_clicks' => '4',
                'date_start' => $hoje, 'date_stop' => $hoje],
        ]]);
        exit;
    }
    echo json_encode(['data' => [['spend' => '1539.22', 'date_start' => '2026-09-22', 'date_stop' => '2026-09-28']]]);
    exit;
}
$erro(400, 100, 'Unsupported get request. Object with ID does not exist, cannot be loaded due to missing permissions');
