<?php
// Cliente da API da Kiwify (https://docs.kiwify.com.br/api-reference/general).
//
// A chave (client_id, client_secret e account_id) fica na configuracao, FORA da pasta
// publica, e nunca volta para a tela. Serve so para LER vendas: a conferencia diaria
// busca pela API o que o webhook nao entregou. Chave com permissao de reembolsar,
// financeiro, afiliados ou webhooks e recusada: vazou, alguem poderia mexer na conta.

// Em teste, TRACK_KIWIFY_API aponta para um servidor falso local
function kiwify_api_base(): string
{
    $env = getenv('TRACK_KIWIFY_API');
    return rtrim($env ?: 'https://public-api.kiwify.com/v1', '/');
}

// Permissoes que a chave do painel nao pode ter (escrita ou dinheiro)
const KIWIFY_ESCOPOS_PROIBIDOS = [
    'sales_refund' => 'Reembolsar vendas',
    'financial' => 'Financeiro',
    'affiliates' => 'Afiliados',
    'webhooks' => 'Webhooks',
    'scheduled_installments' => 'Parcelado',
];

// Chave salva no painel, ou null
function kiwify_api_chave(): ?array
{
    $k = track_config()['kiwify_api'] ?? null;
    return is_array($k) && !empty($k['client_id']) && !empty($k['client_secret']) && !empty($k['account_id']) ? $k : null;
}

function kiwify_api_formato_valido(string $clientId, string $secret, string $conta): bool
{
    return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $clientId)
        && (bool)preg_match('/^[A-Za-z0-9]{20,200}$/', $secret)
        && (bool)preg_match('/^[A-Za-z0-9]{6,60}$/', $conta);
}

// Requisicao HTTP. Devolve [status, corpo decodificado ou null]. Status 0 = sem conexao.
function kiwify_api_http(string $metodo, string $url, array $cabecalhos, ?string $corpo = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $metodo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $cabecalhos,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
    ]);
    if ($corpo !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $corpo);
    }
    $resposta = curl_exec($ch);
    $status = $resposta === false ? 0 : (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $json = is_string($resposta) ? json_decode($resposta, true) : null;
    return [$status, is_array($json) ? $json : null];
}

// Mensagem de erro da Kiwify, curta e sem caractere estranho (vai para a tela, escapada)
function kiwify_api_mensagem(?array $corpo): string
{
    $m = $corpo['message'] ?? $corpo['error_description'] ?? $corpo['error'] ?? '';
    return is_string($m) ? texto($m, 160) : '';
}

// Troca client_id + client_secret por um token. Devolve ['ok', 'token', 'escopos', 'erro'].
function kiwify_api_token(string $clientId, string $secret): array
{
    [$status, $corpo] = kiwify_api_http('POST', kiwify_api_base() . '/oauth/token',
        ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        http_build_query(['client_id' => $clientId, 'client_secret' => $secret]));
    if ($status === 0) {
        return ['ok' => false, 'erro' => 'Sem conexão com a API da Kiwify. Tente de novo em instantes.'];
    }
    $token = $corpo['access_token'] ?? '';
    if ($status !== 200 || !is_string($token) || $token === '') {
        $m = kiwify_api_mensagem($corpo);
        return ['ok' => false, 'erro' => 'A Kiwify recusou a chave (confira client_id e client_secret)' . ($m ? ': ' . $m : '') . '.'];
    }
    // Permissoes: vem no campo scope; se nao vier, no proprio token (JWT)
    $escopo = $corpo['scope'] ?? null;
    if (!is_string($escopo)) {
        $partes = explode('.', $token);
        $carga = isset($partes[1]) ? json_decode((string)base64_decode(strtr($partes[1], '-_', '+/')), true) : null;
        $escopo = is_array($carga) && is_string($carga['scope'] ?? null) ? $carga['scope'] : '';
    }
    $escopos = array_values(array_filter(preg_split('/[\s,]+/', $escopo) ?: []));
    return ['ok' => true, 'token' => $token, 'escopos' => $escopos];
}

// Data para start_date/end_date. A Kiwify le data sem hora como meia-noite em UTC
// (21h em Brasilia) e o end_date nao entra; com hora ISO em UTC, o corte e exato.
// Visto na API real em 29/09/2026.
function kiwify_api_data(DateTime $d): string
{
    return (clone $d)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.000\Z');
}

// GET numa rota da API com o token e a conta
function kiwify_api_get(string $rota, array $consulta, string $token, string $conta): array
{
    return kiwify_api_http('GET', kiwify_api_base() . $rota . ($consulta ? '?' . http_build_query($consulta) : ''),
        ['Authorization: Bearer ' . $token, 'x-kiwify-account-id: ' . $conta, 'Accept: application/json']);
}

// Confere a chave de ponta a ponta: token, permissoes e leitura de vendas.
// Devolve ['ok', 'erro', 'escopos', 'vendas' (quantas ontem e hoje)].
function kiwify_api_testar(string $clientId, string $secret, string $conta): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'erro' => 'O PHP deste servidor está sem a extensão curl, necessária para falar com a Kiwify.'];
    }
    $t = kiwify_api_token($clientId, $secret);
    if (!$t['ok']) {
        return $t;
    }
    $proibidas = array_values(array_intersect_key(KIWIFY_ESCOPOS_PROIBIDOS, array_flip($t['escopos'])));
    if ($proibidas) {
        return ['ok' => false, 'escopos' => $t['escopos'], 'erro' => 'Esta chave tem permissões demais: ' . implode(', ', $proibidas)
            . '. Apague-a na Kiwify e crie outra marcando só "Vendas".'];
    }
    if ($t['escopos'] && !in_array('sales', $t['escopos'], true)) {
        return ['ok' => false, 'escopos' => $t['escopos'], 'erro' => 'Esta chave não tem a permissão "Vendas". Crie outra marcando só "Vendas".'];
    }
    $hoje = new DateTime('today', fuso());
    [$status, $corpo] = kiwify_api_get('/sales', [
        'start_date' => kiwify_api_data((clone $hoje)->modify('-1 day')),
        'end_date' => kiwify_api_data((clone $hoje)->modify('+1 day')),
        'page_size' => '1',
        'page_number' => '1',
    ], $t['token'], $conta);
    if ($status === 0) {
        return ['ok' => false, 'erro' => 'Sem conexão com a API da Kiwify. Tente de novo em instantes.'];
    }
    if ($status !== 200) {
        $m = kiwify_api_mensagem($corpo);
        $dica = in_array($status, [401, 403, 404], true) ? ' Confira o account_id.' : '';
        return ['ok' => false, 'escopos' => $t['escopos'], 'erro' => 'A chave entrou, mas a leitura de vendas falhou (HTTP ' . $status . ')' . ($m ? ': ' . $m : '') . '.' . $dica];
    }
    $n = $corpo['pagination']['count'] ?? null;
    return ['ok' => true, 'escopos' => $t['escopos'], 'vendas' => is_numeric($n) ? (int)$n : null];
}

// Mostra so o comeco e o fim de um identificador (nunca o client_secret)
function mascarar(string $v): string
{
    return strlen($v) <= 8 ? str_repeat('•', strlen($v)) : substr($v, 0, 4) . '…' . substr($v, -4);
}
