<?php
// API da Kiwify falsa, so para o tests/fluxo.sh (servidor embutido do PHP, com este
// arquivo como roteador). Imita /v1/oauth/token e /v1/sales com respostas fixas.
//
//   client_secret SegredoLeitura...  -> token com permissao so de vendas
//   client_secret SegredoPerigoso... -> token com reembolso e financeiro
//   account_id    ContaCerta123      -> le as vendas; outra conta -> 401

header('Content-Type: application/json');
$rota = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($rota === '/v1/oauth/token' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $secret = $_POST['client_secret'] ?? '';
    $escopos = [
        'SegredoLeitura0000000000000000' => 'sales stats',
        'SegredoPerigoso000000000000000' => 'stats products sales sales_refund financial',
    ];
    if (!preg_match('/^[0-9a-f-]{36}$/', $_POST['client_id'] ?? '') || !isset($escopos[$secret])) {
        http_response_code(400);
        echo json_encode(['error' => 'auth_error', 'message' => 'Invalid client: client is invalid']);
        exit;
    }
    echo json_encode(['access_token' => 'tok-' . md5($secret), 'token_type' => 'Bearer', 'expires_in' => 86400, 'scope' => $escopos[$secret]]);
    exit;
}

if ($rota === '/v1/sales' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $data = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/';
    if (!preg_match($data, $_GET['start_date'] ?? '') || !preg_match($data, $_GET['end_date'] ?? '')) {
        http_response_code(400);
        echo json_encode(['message' => 'start_date e end_date obrigatorios']);
        exit;
    }
    foreach (['updated_at_start_date', 'updated_at_end_date'] as $p) {
        if (isset($_GET[$p]) && !preg_match($data, $_GET[$p])) {
            http_response_code(400);
            echo json_encode(['message' => "$p fora do formato"]);
            exit;
        }
    }
    if ((int)($_GET['page_size'] ?? 10) > 100) {
        http_response_code(400);
        echo json_encode(['message' => 'ValidationError: "page_size" must be less than or equal to 100']);
        exit;
    }
    if (($_SERVER['HTTP_X_KIWIFY_ACCOUNT_ID'] ?? '') === 'ContaLimite123') {
        // Conta que "estourou" o limite da Kiwify: 429 com Retry-After
        http_response_code(429);
        header('Retry-After: 120');
        echo json_encode(['message' => 'Too Many Requests']);
        exit;
    }
    if (strpos($_SERVER['HTTP_AUTHORIZATION'] ?? '', 'Bearer tok-') !== 0 || ($_SERVER['HTTP_X_KIWIFY_ACCOUNT_ID'] ?? '') !== 'ContaCerta123') {
        http_response_code(401);
        echo json_encode(['message' => 'Unauthorized']);
        exit;
    }
    // Vendas que o teste grava em <TRACK_DADOS>/kiwify-falsa-vendas.json. Paginas de no
    // maximo 2, para exercitar a paginacao; filtro updated_at como o da Kiwify.
    $arq = rtrim((string)getenv('TRACK_DADOS'), '/\\') . DIRECTORY_SEPARATOR . 'kiwify-falsa-vendas.json';
    $vendas = is_file($arq) ? (json_decode((string)file_get_contents($arq), true) ?: []) : [];
    if (isset($_GET['updated_at_start_date'])) {
        $vendas = array_values(array_filter($vendas, fn($v) => $v['updated_at'] >= $_GET['updated_at_start_date']));
    }
    $tamanho = min(2, max(1, (int)($_GET['page_size'] ?? 10)));
    $pagina = max(1, (int)($_GET['page_number'] ?? 1));
    echo json_encode(['pagination' => ['count' => count($vendas), 'page_number' => $pagina, 'page_size' => $tamanho],
        'data' => array_slice($vendas, ($pagina - 1) * $tamanho, $tamanho)]);
    exit;
}

http_response_code(404);
echo json_encode(['message' => 'Not found']);
