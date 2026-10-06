<?php
// Recebe os eventos das paginas de venda (enviados pelo t.js).
//
// Identidade do visitante: cookie trk_vid gravado por ESTE servidor. Quando a pagina
// e do mesmo site do painel (engdesk.pro -> track.engdesk.pro), o cookie vale para o
// dominio inteiro e o Safari nao o apaga. Para sites diferentes, o t.js guarda o id no
// navegador e manda junto (o Safari pode apagar em 7 dias).

require __DIR__ . '/lib/util.php';

header('Cache-Control: no-store');
$cfg = track_config();

$origem = $_SERVER['HTTP_ORIGIN'] ?? '';
$permitida = $cfg && in_array($origem, $cfg['origens'] ?? [], true);
if ($permitida) {
    header('Access-Control-Allow-Origin: ' . $origem);
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(204);
    exit;
}
if (!$cfg) {
    responder_json(503, ['ok' => false]);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !$permitida) {
    responder_json(403, ['ok' => false]);
}
if (!dentro_do_limite('coleta:' . ip_conexao(), 240, 60)) {
    responder_json(429, ['ok' => false]);
}

$corpo = json_decode((string)file_get_contents('php://input', false, null, 0, 16384), true);
if (!is_array($corpo)) {
    responder_json(400, ['ok' => false]);
}

$nome = texto($corpo['evento'] ?? '', 60);
if (!preg_match('/^[A-Za-z][A-Za-z0-9_:\-]{0,59}$/', $nome)) {
    responder_json(400, ['ok' => false]);
}

// A pagina informada tem de ser do mesmo dominio que fez o pedido (Origin)
$url = texto($corpo['url'] ?? '', 800);
$hostUrl = strtolower((string)parse_url($url, PHP_URL_HOST));
$hostOrigem = strtolower((string)parse_url($origem, PHP_URL_HOST));
if ($hostUrl === '' || $hostUrl !== $hostOrigem) {
    responder_json(400, ['ok' => false]);
}
$dominio = preg_replace('/^www\./', '', $hostUrl);
// Uma pagina so com ou sem a barra do fim (pagina_normal, em lib/util.php)
$pagina = mb_substr(pagina_normal((string)parse_url($url, PHP_URL_PATH)), 0, 200);

$utms = [];
$recebidas = is_array($corpo['utms'] ?? null) ? $corpo['utms'] : [];
foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $k) {
    $v = texto($recebidas[$k] ?? '', 300);
    $utms[$k] = $v === '' ? null : $v;
}

// De onde veio: so dominio e caminho, sem os parametros (podem ter dado pessoal)
$ref = texto($corpo['referrer'] ?? '', 800);
$referrer = null;
if ($ref !== '' && ($h = parse_url($ref, PHP_URL_HOST))) {
    $referrer = mb_substr(strtolower($h) . (string)parse_url($ref, PHP_URL_PATH), 0, 300);
}

$detalhe = texto($corpo['detalhe'] ?? '', 200);

// Visitante: cookie do servidor > id que o navegador guardou > novo
$vid = $_COOKIE['trk_vid'] ?? '';
if (!is_string($vid) || !preg_match('/^[a-f0-9]{32}$/', $vid)) {
    $vid = is_string($corpo['vid'] ?? null) ? $corpo['vid'] : '';
}
if (!preg_match('/^[a-f0-9]{32}$/', $vid)) {
    $vid = bin2hex(random_bytes(16));
}

$hostPainel = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
$mesmoSite = $hostPainel !== '' && dominio_registravel($hostPainel) === dominio_registravel($dominio);
$opcoes = [
    'expires' => time() + 180 * 86400,
    'path' => '/',
    'secure' => https(),
    'httponly' => true,
    'samesite' => 'Lax',
];
if ($mesmoSite && filter_var($hostPainel, FILTER_VALIDATE_IP) === false && str_contains($hostPainel, '.')) {
    $opcoes['domain'] = '.' . dominio_registravel($hostPainel);
}
setcookie('trk_vid', $vid, $opcoes);

[$dispositivo, $sistema, $navegador] = aparelho(texto($_SERVER['HTTP_USER_AGENT'] ?? '', 500));
$ip = ip_parcial(ip_cliente());
$agora = agora_utc();

$db = track_db();
$db->prepare('INSERT OR IGNORE INTO visitantes (id, criado_em, visto_em, dispositivo, sistema, navegador, ip) VALUES (?, ?, ?, ?, ?, ?, ?)')
    ->execute([$vid, $agora, $agora, $dispositivo, $sistema, $navegador, $ip]);
$db->prepare('UPDATE visitantes SET visto_em = ?, dispositivo = ?, sistema = ?, navegador = ?, ip = ? WHERE id = ?')
    ->execute([$agora, $dispositivo, $sistema, $navegador, $ip, $vid]);
$db->prepare('INSERT INTO eventos (visitante, em, dominio, pagina, nome, detalhe, utm_source, utm_medium, utm_campaign, utm_content, utm_term, referrer, ip, dispositivo)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
    ->execute([$vid, $agora, $dominio, $pagina, $nome, $detalhe ?: null,
        $utms['utm_source'], $utms['utm_medium'], $utms['utm_campaign'], $utms['utm_content'], $utms['utm_term'],
        $referrer, $ip, $dispositivo . ' · ' . $navegador]);

if (random_int(1, 200) === 1) {
    limpar_antigos();
}

responder_json(200, ['ok' => true, 'vid' => $vid]);
