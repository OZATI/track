<?php
// Graph API da Meta falsa, so para o tests/fluxo.sh (servidor embutido do PHP, com este
// arquivo como roteador). Imita /me, /me/permissions, /act_<conta> e /act_<conta>/insights.
//
//   token TokenLeitura...   -> so ads_read
//   token TokenGerencia...  -> ads_read + ads_management (aceito, com aviso)
//   token TokenInstagram... -> ads_read + permissoes do Instagram; a pagina tem o @engdesk,
//                              e as consultas do Instagram vao para tests/instagram-falsa.php
//   conta 587364236934346   -> DRIVE DE PROJETOS; outra conta -> erro 100
//
// Conectar com Facebook (meta-conectar.php), com o app ficticio 1234567890 e a chave secreta
// abaixo: o codigo "codigo-bom" vira um token curto (TokenGerenciaCurto...), que troca pelo
// longo (TokenGerenciaLongo...); com ele, /me traz a Pessoa Teste com e-mail ficticio e
// /me/adaccounts duas contas. Nome e foto de quem mandou mensagem (webhook): o token da pagina
// (TokenPagina..., em /me/accounts) le a pessoa 7000000000000001; a 7000000000000002 da erro.
//
// Criativo e previa do gestor (lib/meta_criativo.php): na listagem de anuncios, o cv free (333333) vem com
// uma imagem (miniatura no CDN falso, /cdn/<nome>.jpg, servido por este mesmo arquivo) e o cv 05 (222222)
// so com o tipo, video, sem miniatura. /<anuncio> traz o status, os textos e as previas (iframe); e
// /<anuncio>/previews, cada formato. O CDN falso so vale no painel com TRACK_META_MIDIA_HOST.
// META_FALSA_CENARIO (arquivo) liga casos do tests/criativo.sh, uma palavra por linha:
//   criativos       -> anuncios a mais: EXIF, arquivo que nao e imagem, 9 MB, CDN de fora, PNG e 3 comuns
//   troca           -> o cv free troca de criativo
//   recusa_criativo -> a listagem com os campos do criativo da erro (a busca refaz sem eles)
//   sem_junto       -> o pedido com as previas juntas da erro (modo separado)
//   iframe_mau      -> a previa do feed volta com endereco que nao e o da Meta
//   bomba           -> um anuncio a mais (444009) cuja imagem e um PNG que diz ter 6320 x 6320 (40 MP) em poucos bytes
// META_FALSA_LOG (arquivo): uma linha por pedido, para o teste contar as chamadas.

const FALSA_APP_ID = '1234567890';
const FALSA_APP_SEGREDO = '0123456789abcdef0123456789abcdef';

$rota = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($log = getenv('META_FALSA_LOG')) {
    @file_put_contents($log, $rota . '?' . (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY) . "\n", FILE_APPEND | LOCK_EX);
}
$cenario = ($arqCenario = getenv('META_FALSA_CENARIO')) && is_file($arqCenario) ? array_values(array_filter(array_map('trim', file($arqCenario)))) : [];
// CDN falso das miniaturas dos criativos
if (preg_match('~^/cdn/([a-z0-9]+)\.jpg$~', $rota, $m)) {
    $jpeg = function (int $w, int $h, int $cor): string {
        if (!function_exists('imagecreatetruecolor')) {
            return base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
        }
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, $cor);
        ob_start();
        imagejpeg($im, null, 80);
        imagedestroy($im);
        return (string)ob_get_clean();
    };
    header('Content-Type: image/jpeg');
    if ($m[1] === 'bomba') {
        // So o cabecalho do PNG (IHDR com CRC certo) e o fim: o tamanho se le sem abrir a imagem
        header('Content-Type: image/png');
        $pedaco = fn(string $tipo, string $dado) => pack('N', strlen($dado)) . $tipo . $dado . pack('N', crc32($tipo . $dado));
        echo "\x89PNG\r\n\x1a\n" . $pedaco('IHDR', pack('NNCCCCC', 6320, 6320, 8, 2, 0, 0, 0))
            . $pedaco('IDAT', (string)gzcompress(str_repeat("\0", 6320 * 3 + 1), 9)) . $pedaco('IEND', '');
        exit;
    }
    if ($m[1] === 'texto') {
        echo '<html><body>isto nao e uma imagem</body></html>';
    } elseif ($m[1] === 'grande') {
        header('Content-Length: ' . (9 * 1024 * 1024));
        echo str_repeat("\xFF", 9 * 1024 * 1024);
    } elseif ($m[1] === 'png' && function_exists('imagepng')) {
        header('Content-Type: image/png');
        $im = imagecreatetruecolor(300, 150);
        imagefill($im, 0, 0, 0x2E7D32);
        imagepng($im);
        imagedestroy($im);
    } elseif ($m[1] === 'exif') {
        // JPEG com um bloco EXIF (APP1) com um texto que nao pode sobrar depois de regravar
        $bin = $jpeg(640, 480, 0x8E24AA);
        $dado = "Exif\0\0MM\0*\0\0\0\x08\0\0GPS-SEGREDO-DO-TESTE";
        echo substr($bin, 0, 2) . "\xFF\xE1" . pack('n', strlen($dado) + 2) . $dado . substr($bin, 2);
    } else {
        echo $jpeg(600, 600, 0x1D6FF2);
    }
    exit;
}
header('Content-Type: application/json');
$token = preg_replace('/^Bearer\s+/i', '', $_SERVER['HTTP_AUTHORIZATION'] ?? '');
$erro = function (int $http, int $codigo, string $msg) {
    http_response_code($http);
    echo json_encode(['error' => ['message' => $msg, 'type' => 'OAuthException', 'code' => $codigo]]);
    exit;
};

// Troca do codigo do login e troca pelo token longo: sem token no cabecalho, chave no corpo
if ($rota === '/graph/oauth/access_token') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || isset($_GET['client_secret'])) {
        $erro(400, 100, 'A chave secreta veio na URL');
    }
    if (($_POST['client_id'] ?? '') !== FALSA_APP_ID || ($_POST['client_secret'] ?? '') !== FALSA_APP_SEGREDO) {
        $erro(400, 1, 'Error validating client secret.');
    }
    if (($_POST['grant_type'] ?? '') === 'fb_exchange_token') {
        if (strpos((string)($_POST['fb_exchange_token'] ?? ''), 'TokenGerenciaCurto') !== 0) {
            $erro(400, 190, 'Invalid OAuth access token');
        }
        echo json_encode(['access_token' => 'TokenGerenciaLongo' . str_repeat('L', 40), 'token_type' => 'bearer', 'expires_in' => 5184000]);
        exit;
    }
    if (($_POST['code'] ?? '') !== 'codigo-bom' || empty($_POST['redirect_uri'])) {
        $erro(400, 100, 'Invalid verification code format.');
    }
    echo json_encode(['access_token' => 'TokenGerenciaCurto' . str_repeat('C', 40), 'token_type' => 'bearer', 'expires_in' => 3600]);
    exit;
}
// Perfil de quem mandou mensagem, pelo token da pagina
if (strpos($token, 'TokenPagina') === 0) {
    if ($rota === '/graph/7000000000000001') {
        $porta = (int)($_SERVER['SERVER_PORT'] ?? 0) + 1; // a API do Instagram falsa serve as imagens
        echo json_encode(['id' => '7000000000000001', 'name' => 'Cliente Teste', 'profile_pic' => 'http://127.0.0.1:' . $porta . '/midia/555.jpg']);
        exit;
    }
    $erro(400, 100, 'Unsupported get request. Object does not exist or cannot be loaded due to missing permissions');
}

if (strpos($token, 'TokenLeitura') !== 0 && strpos($token, 'TokenGerencia') !== 0 && strpos($token, 'TokenInstagram') !== 0) {
    $erro(400, 190, 'Invalid OAuth access token - Cannot parse access token');
}
$comInstagram = strpos($token, 'TokenInstagram') === 0;
if ($rota === '/graph/me' && strpos($token, 'TokenGerenciaLongo') === 0) {
    echo json_encode(['id' => '100', 'name' => 'Pessoa Teste'] + (strpos($_GET['fields'] ?? '', 'email') !== false ? ['email' => 'pessoa.teste@example.com'] : []));
    exit;
}
if ($rota === '/graph/me/adaccounts') {
    echo json_encode(['data' => [
        ['account_id' => '587364236934346', 'id' => 'act_587364236934346', 'name' => 'DRIVE DE PROJETOS'],
        ['account_id' => '999888777666', 'id' => 'act_999888777666', 'name' => 'OUTRA CONTA'],
    ]]);
    exit;
}
// Ligar e pausar pelo gestor: so o token que pode editar (TokenGerencia)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && preg_match('~^/graph/(\d+)$~', $rota)) {
    if (strpos($token, 'TokenGerencia') !== 0) {
        $erro(403, 200, '(#200) Requires ads_management permission to manage the object');
    }
    if (isset($_POST['daily_budget'])) {
        if (!ctype_digit((string)$_POST['daily_budget']) || (int)$_POST['daily_budget'] < 100) {
            $erro(400, 100, '(#100) Invalid parameter');
        }
        echo json_encode(['success' => true]);
        exit;
    }
    if (!in_array($_POST['status'] ?? '', ['ACTIVE', 'PAUSED'], true)) {
        $erro(400, 100, '(#100) Invalid parameter');
    }
    echo json_encode(['success' => true]);
    exit;
}
if ($rota === '/graph/me/accounts') {
    $pagina = ['id' => '300300', 'name' => 'EngDesk', 'access_token' => 'TokenPagina' . str_repeat('P', 40)];
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
// Criativo e previa do gestor (lib/meta_criativo.php)
$cdn = 'http://' . $_SERVER['HTTP_HOST'] . '/cdn/';
$criativos = [
    '222222' => ['id' => '900222', 'object_type' => 'VIDEO', 'video_id' => '800222'],
    '333333' => in_array('troca', $cenario, true)
        ? ['id' => '900334', 'object_type' => 'SHARE', 'thumbnail_url' => $cdn . 'b333333.jpg']
        : ['id' => '900333', 'object_type' => 'SHARE', 'thumbnail_url' => $cdn . 'a333333.jpg', 'image_url' => $cdn . 'a333333.jpg'],
];
$extras = [];
if (in_array('criativos', $cenario, true)) {
    foreach (['444001' => 'exif', '444002' => 'texto', '444003' => 'grande', '444005' => 'png', '444006' => 'c444006', '444007' => 'c444007', '444008' => 'c444008'] as $ad => $arq) {
        $criativos[$ad] = ['id' => '9' . $ad, 'object_type' => 'PHOTO', 'thumbnail_url' => $cdn . $arq . '.jpg'];
    }
    $criativos['444004'] = ['id' => '9444004', 'object_type' => 'PHOTO', 'thumbnail_url' => 'https://example.com/fora.jpg'];
    if (in_array('bomba', $cenario, true)) {
        $criativos['444009'] = ['id' => '9444009', 'object_type' => 'PHOTO', 'thumbnail_url' => $cdn . 'bomba.jpg'];
    }
    ksort($criativos);
    foreach (array_keys($criativos) as $ad) {
        if (strpos((string)$ad, '444') === 0) {
            $extras[] = ['id' => (string)$ad, 'name' => 'teste ' . $ad, 'status' => 'ACTIVE', 'effective_status' => 'ACTIVE', 'adset_id' => '111111', 'campaign_id' => '120120'];
        }
    }
}
$iframe = function (string $ad, string $formato) use ($cenario): string {
    $feed = $formato === 'INSTAGRAM_STANDARD';
    $src = $feed && in_array('iframe_mau', $cenario, true)
        ? 'https://example.com/ads/api/preview_iframe.php?d=' . $ad
        : 'https://www.facebook.com/ads/api/preview_iframe.php?d=AQ' . $ad . '-' . strtolower($formato) . '&amp;t=AQteste';
    return '<iframe src="' . $src . '" width="' . ($feed ? 540 : 320) . '" height="' . ($feed ? 690 : 570) . '" scrolling="yes" style="border: none;"></iframe>';
};
// Que formatos cada anuncio tem: o cv 05 (video) feed e reels; o cv free feed e stories
$formatosDe = ['222222' => ['INSTAGRAM_STANDARD', 'INSTAGRAM_REELS'], '333333' => ['INSTAGRAM_STANDARD', 'INSTAGRAM_STORY']];
if (preg_match('~^/graph/(\d{6})/previews$~', $rota, $m)) {
    $f = (string)($_GET['ad_format'] ?? '');
    if (!in_array($f, $formatosDe[$m[1]] ?? ['INSTAGRAM_STANDARD'], true)) {
        $erro(400, 100, 'Invalid parameter: ad format not supported for this ad');
    }
    echo json_encode(['data' => [['body' => $iframe($m[1], $f)]]]);
    exit;
}
if (preg_match('~^/graph/(\d{6})$~', $rota, $m) && isset($criativos[$m[1]])) {
    $campos = (string)($_GET['fields'] ?? '');
    if (strpos($campos, 'previews.') !== false && in_array('sem_junto', $cenario, true)) {
        $erro(400, 100, '(#100) Unknown path components');
    }
    $c = $criativos[$m[1]] + ['thumbnail_url' => $cdn . 'g' . $m[1] . '.jpg'];
    if ($m[1] === '333333') {
        // Carrossel de 3 cartoes, com texto que traz HTML (tem de chegar como texto puro)
        $c['object_story_spec'] = ['page_id' => '300300', 'link_data' => [
            'message' => "<script>alert(1)</script> Projeto pronto\nem duas linhas", 'name' => 'Casa térrea 10x20',
            'description' => 'Plantas e 3D', 'call_to_action' => ['type' => 'LEARN_MORE'],
            'child_attachments' => [['name' => 'a'], ['name' => 'b'], ['name' => 'c']]]];
    } else {
        $c['object_story_spec'] = ['page_id' => '300300', 'video_data' => ['message' => 'Veja o projeto', 'title' => 'cv 05', 'call_to_action' => ['type' => 'SHOP_NOW']]];
    }
    $r = ['id' => $m[1], 'effective_status' => $m[1] === '333333' ? 'CAMPAIGN_PAUSED' : 'ACTIVE', 'creative' => $c];
    if (preg_match_all('~previews\.ad_format\((\w+)\)\.as\((\w+)\)~', $campos, $pp, PREG_SET_ORDER)) {
        foreach ($pp as [, $f, $apelido]) {
            if (in_array($f, $formatosDe[$m[1]] ?? ['INSTAGRAM_STANDARD'], true)) {
                $r[$apelido] = ['data' => [['body' => $iframe($m[1], $f)]]];
            }
        }
    }
    echo json_encode($r);
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
if ($rota === '/graph/act_587364236934346/ads') {
    $listas[$rota] = array_merge($listas[$rota], $extras);
    if (strpos((string)($_GET['fields'] ?? ''), 'creative') !== false) {
        if (in_array('recusa_criativo', $cenario, true)) {
            $erro(400, 100, '(#100) Tried accessing nonexisting field (creative)');
        }
        foreach ($listas[$rota] as &$ad) {
            if (isset($criativos[$ad['id']])) {
                $ad['creative'] = $criativos[$ad['id']];
            }
        }
        unset($ad);
    }
}
if (isset($listas[$rota])) {
    echo json_encode(['data' => $listas[$rota], 'paging' => ['cursors' => ['before' => 'a', 'after' => 'b']]]);
    exit;
}
// Analise diaria de publico: alcance do periodo inteiro do conjunto 5 e da TL 1
if (preg_match('~^/graph/(\d+)/insights$~', $rota, $m) && ($_GET['fields'] ?? '') === 'reach,impressions') {
    echo json_encode(['data' => in_array($m[1], ['111111', '120120'], true) ? [['reach' => '2000', 'impressions' => '4000']] : []]);
    exit;
}
// Analise diaria: compras da campanha TL 1 (e do conjunto 5 dela) por idade e sexo (a FREE nao vendeu)
if (preg_match('~^/graph/(\d+)/insights$~', $rota, $m) && ($_GET['breakdowns'] ?? '') === 'age,gender') {
    $compra = fn(int $n) => [['action_type' => 'offsite_conversion.fb_pixel_purchase', 'value' => (string)$n], ['action_type' => 'omni_purchase', 'value' => (string)$n]];
    echo json_encode(['data' => in_array($m[1], ['120120', '111111'], true) ? [
        ['age' => '25-34', 'gender' => 'female', 'spend' => '20.00', 'actions' => $compra(2)],
        ['age' => '25-34', 'gender' => 'male', 'spend' => '15.00', 'actions' => $compra(1)],
        ['age' => '35-44', 'gender' => 'female', 'spend' => '10.00'],
        ['age' => '45-54', 'gender' => 'unknown', 'spend' => '5.00'],
    ] : [['age' => '25-34', 'gender' => 'male', 'spend' => '10.00']]]);
    exit;
}
if ($rota === '/graph/act_587364236934346/insights') {
    // Como a Meta: so os dias dentro do time_range (o historico pede janelas antigas). Ha 150 dias,
    // um gasto antigo do cv 05, para o historico achar.
    $periodo = json_decode((string)($_GET['time_range'] ?? ''), true);
    $no = fn(string $dia) => !is_array($periodo) || ($dia >= ($periodo['since'] ?? '') && $dia <= ($periodo['until'] ?? ''));
    $antigo = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->modify('-150 days')->format('Y-m-d');
    if (($_GET['breakdowns'] ?? '') === 'hourly_stats_aggregated_by_advertiser_time_zone') {
        // Gasto da conta por hora de hoje
        $hoje = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
        echo json_encode(['data' => $no($hoje) ? [
            ['spend' => '20.00', 'hourly_stats_aggregated_by_advertiser_time_zone' => '00:00:00 - 00:59:59', 'date_start' => $hoje, 'date_stop' => $hoje],
            ['spend' => '40.00', 'hourly_stats_aggregated_by_advertiser_time_zone' => '01:00:00 - 01:59:59', 'date_start' => $hoje, 'date_stop' => $hoje],
        ] : []]);
        exit;
    }
    // Alcance de hoje por conjunto e por campanha
    if (in_array($_GET['level'] ?? '', ['adset', 'campaign'], true)) {
        $hoje = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
        $campo = $_GET['level'] === 'adset' ? 'adset_id' : 'campaign_id';
        echo json_encode(['data' => [
            [$campo => $_GET['level'] === 'adset' ? '111111' : '120120', 'reach' => '2500', 'impressions' => '4000', 'date_start' => $hoje, 'date_stop' => $hoje],
        ]]);
        exit;
    }
    if (($_GET['level'] ?? '') === 'ad') {
        // Gasto de hoje por anuncio (dia no fuso da conta); o cv 05 e um video
        $hoje = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
        $vv = fn(int $n) => [['action_type' => 'video_view', 'value' => (string)$n]];
        if (!$no($hoje)) {
            echo json_encode(['data' => $no($antigo) ? [['ad_id' => '222222', 'adset_id' => '111111', 'campaign_id' => '120120', 'spend' => '30.00', 'impressions' => '1500',
                'inline_link_clicks' => '20', 'ad_name' => 'cv 05', 'adset_name' => 'conjunto 5', 'campaign_name' => 'TL 1', 'date_start' => $antigo, 'date_stop' => $antigo]] : []]);
            exit;
        }
        echo json_encode(['data' => [
            ['ad_id' => '222222', 'adset_id' => '111111', 'campaign_id' => '120120', 'spend' => '50.00', 'impressions' => '4000', 'inline_link_clicks' => '60',
                'actions' => [['action_type' => 'offsite_conversion.fb_pixel_initiate_checkout', 'value' => '3'], ['action_type' => 'omni_initiated_checkout', 'value' => '3'],
                    ['action_type' => 'landing_page_view', 'value' => '50'], ['action_type' => 'video_view', 'value' => '1000']],
                'video_play_actions' => $vv(2000), 'video_thruplay_watched_actions' => $vv(250), 'video_p25_watched_actions' => $vv(1200),
                'video_p50_watched_actions' => $vv(800), 'video_p75_watched_actions' => $vv(500), 'video_p100_watched_actions' => $vv(300),
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
