<?php
// Cliente da API de dados do Google Analytics 4 (Data API v1beta), so leitura.
//
// O acesso e por conta de servico: um "robo" do Google Cloud que recebe o papel Leitor na
// propriedade do GA4 (Administrador > Gerenciamento de acesso da propriedade). O arquivo JSON
// da chave e colado uma vez em ga4.php e fica na configuracao, FORA da pasta publica; a chave
// privada nunca volta para a tela. Sem tela de consentimento e sem verificacao do Google: a
// conta so le o que alguem deu a ela. Escopo: analytics.readonly.
//
// Cada busca usa um token de 1 hora, pedido com um JWT assinado pela chave (RS256) e guardado
// no banco. Cota do GA4 numa propriedade padrao: 200.000 tokens de cota por dia e 40.000 por
// hora; cada relatorio gasta poucos. O painel faz no maximo GA4_LIMITE_MINUTO chamadas por
// minuto e para sozinho se o Google responder 429.

const GA4_ESCOPO = 'https://www.googleapis.com/auth/analytics.readonly';
const GA4_AUD = 'https://oauth2.googleapis.com/token';
const GA4_ADIADA = -1; // chamada que o painel nao fez (limite interno ou pausa)

// Em teste, TRACK_GA4_API aponta para um servidor falso local (token e relatorios). O token_uri
// do arquivo JSON e ignorado: endereco fixo do Google, para um arquivo adulterado nao mandar a
// assinatura para outro lugar.
function ga4_api_base(): string
{
    return rtrim(getenv('TRACK_GA4_API') ?: 'https://analyticsdata.googleapis.com', '/');
}

function ga4_token_url(): string
{
    $env = getenv('TRACK_GA4_API');
    return $env ? rtrim($env, '/') . '/token' : GA4_AUD;
}

// Conexao salva: client_email, private_key, projeto e as propriedades [['id', 'nome']], ou null
function ga4_api_chave(): ?array
{
    $k = track_config()['ga4'] ?? null;
    return is_array($k) && !empty($k['client_email']) && !empty($k['private_key']) && !empty($k['propriedades']) ? $k : null;
}

// Le o arquivo JSON da conta de servico. Devolve ['ok', 'erro', 'client_email', 'private_key', 'projeto'].
function ga4_ler_conta_servico(string $json): array
{
    if (strlen($json) > 20000) {
        return ['ok' => false, 'erro' => 'O texto colado é grande demais para um arquivo de chave.'];
    }
    $j = json_decode($json, true);
    if (!is_array($j)) {
        return ['ok' => false, 'erro' => 'Cole o conteúdo inteiro do arquivo .json da chave (começa com { e termina com }).'];
    }
    if (($j['type'] ?? '') !== 'service_account') {
        return ['ok' => false, 'erro' => 'Este arquivo não é de conta de serviço (falta "type": "service_account"). No Google Cloud: IAM e administrador → Contas de serviço → a conta → Chaves → Adicionar chave → JSON.'];
    }
    $email = $j['client_email'] ?? '';
    if (!is_string($email) || !preg_match('/^[a-z0-9][a-z0-9-]{4,62}@[a-z0-9.-]+\.gserviceaccount\.com$/', $email)) {
        return ['ok' => false, 'erro' => 'O client_email do arquivo não é de uma conta de serviço do Google.'];
    }
    if (!function_exists('openssl_sign')) {
        return ['ok' => false, 'erro' => 'O PHP deste servidor está sem a extensão openssl, necessária para assinar o pedido ao Google.'];
    }
    $chave = $j['private_key'] ?? '';
    if (!is_string($chave) || strpos($chave, 'PRIVATE KEY') === false || !openssl_pkey_get_private($chave)) {
        return ['ok' => false, 'erro' => 'A chave privada do arquivo não abriu. Baixe uma chave nova (JSON) e cole o arquivo inteiro, sem editar.'];
    }
    return ['ok' => true, 'client_email' => $email, 'private_key' => $chave, 'projeto' => texto($j['project_id'] ?? '', 60)];
}

// ID da propriedade: so numeros (Administrador > Detalhes da propriedade). Devolve o erro ou null.
// O ID da metrica (G-XXXX, do fluxo de dados) e outra coisa e e recusado com a explicacao.
function ga4_propriedade_erro(string $id): ?string
{
    if (preg_match('/^\d{6,12}$/', $id)) {
        return null;
    }
    if (preg_match('/^G-[A-Z0-9]+$/i', $id)) {
        return '"' . $id . '" é o ID da métrica do fluxo de dados. Aqui vai o ID da propriedade, só números: no GA4, Administrador → Detalhes da propriedade.';
    }
    return 'O ID da propriedade tem só números (de 6 a 12). No GA4: Administrador → Detalhes da propriedade.';
}

function ga4_api_limite_minuto(): int
{
    return max(1, (int)(getenv('TRACK_GA4_LIMITE_MINUTO') ?: 30));
}

// Chamadas feitas ao Google na janela (para a tela)
function ga4_api_uso(int $janela): int
{
    $st = track_db()->prepare("SELECT COUNT(*) FROM limites WHERE chave = 'ga4-api' AND em >= ?");
    $st->execute([time() - $janela]);
    return (int)$st->fetchColumn();
}

// Ate quando o Google pediu pausa (0 = sem pausa)
function ga4_api_pausa_ate(): int
{
    $ate = (int)(ajuste('ga4_api_pausa_ate') ?? 0);
    return $ate > time() ? $ate : 0;
}

// Requisicao HTTP. Devolve [status, corpo decodificado ou null]. Status 0 = sem conexao;
// GA4_ADIADA = nao chamou (limite interno ou pausa pedida pelo Google). 429: 5 minutos de
// pausa, dobrando a cada novo 429 ate 1 hora.
function ga4_api_http(string $metodo, string $url, array $cabecalhos, ?string $corpo = null): array
{
    if ($ate = ga4_api_pausa_ate()) {
        return [GA4_ADIADA, ['error' => ['message' => 'O Google pediu uma pausa (cota do GA4); o painel volta a buscar sozinho às ' . data_local(gmdate('Y-m-d H:i:s', $ate), 'H:i') . '.']]];
    }
    if (!dentro_do_limite('ga4-api', ga4_api_limite_minuto(), 60)) {
        return [GA4_ADIADA, ['error' => ['message' => 'Limite interno de ' . ga4_api_limite_minuto() . ' chamadas por minuto ao GA4 atingido. A próxima busca sai em até 1 minuto.']]];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $metodo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $cabecalhos,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
    ]);
    if ($corpo !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $corpo);
    }
    $resposta = curl_exec($ch);
    $status = $resposta === false ? 0 : (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($status === 429) {
        $pausa = min(3600, max(300, 2 * (int)(ajuste('ga4_api_pausa_seg') ?? 150)));
        definir_ajuste('ga4_api_pausa_seg', (string)$pausa);
        definir_ajuste('ga4_api_pausa_ate', (string)(time() + $pausa));
    } elseif ($status >= 200 && $status < 300) {
        definir_ajuste('ga4_api_pausa_seg', null);
    }
    $json = is_string($resposta) ? json_decode($resposta, true) : null;
    return [$status, is_array($json) ? $json : null];
}

// Mensagem de erro do Google, curta e sem caractere estranho (vai para a tela, escapada)
function ga4_api_mensagem(?array $corpo): string
{
    $m = $corpo['error']['message'] ?? $corpo['error_description'] ?? $corpo['error'] ?? '';
    return is_string($m) ? texto($m, 200) : '';
}

function ga4_b64url(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

// Troca a chave da conta de servico por um token de 1 hora. Devolve ['ok', 'token', 'validade', 'erro'].
function ga4_api_token(string $email, string $chave): array
{
    $agora = time();
    $jwt = ga4_b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.'
        . ga4_b64url(json_encode(['iss' => $email, 'scope' => GA4_ESCOPO, 'aud' => GA4_AUD, 'iat' => $agora, 'exp' => $agora + 3600]));
    $assinatura = '';
    if (!openssl_sign($jwt, $assinatura, $chave, OPENSSL_ALGO_SHA256)) {
        return ['ok' => false, 'erro' => 'Não foi possível assinar o pedido com a chave salva.'];
    }
    [$status, $corpo] = ga4_api_http('POST', ga4_token_url(), ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt . '.' . ga4_b64url($assinatura)]));
    if ($status === 0) {
        return ['ok' => false, 'erro' => 'Sem conexão com o Google. Tente de novo em instantes.'];
    }
    if ($status === GA4_ADIADA || $status === 429) {
        return ['ok' => false, 'adiada' => true, 'erro' => $status === 429 ? 'O Google pediu uma pausa (muitas chamadas). O painel espera e tenta sozinho.' : ga4_api_mensagem($corpo)];
    }
    $token = $corpo['access_token'] ?? '';
    if ($status !== 200 || !is_string($token) || $token === '') {
        $m = ga4_api_mensagem($corpo);
        return ['ok' => false, 'erro' => 'O Google recusou a chave da conta de serviço (apagada ou desativada no Google Cloud?)' . ($m ? ': ' . $m : '') . '.'];
    }
    return ['ok' => true, 'token' => $token, 'validade' => min(3600, max(300, (int)($corpo['expires_in'] ?? 3600)))];
}

// Token guardado para as buscas: economiza uma chamada por busca. Fica no banco, fora da pasta
// publica, e so vale para a mesma chave. $novo = pede outro (ex.: o Google recusou o guardado).
function ga4_api_token_salvo(array $k, bool $novo = false): array
{
    $dono = hash('sha256', $k['client_email'] . '|' . $k['private_key']);
    $salvo = json_decode((string)ajuste('ga4_api_token'), true);
    if (!$novo && is_array($salvo) && ($salvo['dono'] ?? '') === $dono && (int)($salvo['expira'] ?? 0) > time() + 120) {
        return ['ok' => true, 'token' => (string)$salvo['token']];
    }
    $t = ga4_api_token($k['client_email'], $k['private_key']);
    if ($t['ok']) {
        definir_ajuste('ga4_api_token', json_encode(['token' => $t['token'], 'expira' => time() + $t['validade'], 'dono' => $dono]));
    } elseif (empty($t['adiada'])) {
        definir_ajuste('ga4_api_token', null);
    }
    return $t;
}

// Relatorio (runReport). Devolve [status, corpo]. Pede junto a cota gasta (returnPropertyQuota).
function ga4_api_relatorio(string $propriedade, array $pedido, string $token): array
{
    return ga4_api_http('POST', ga4_api_base() . '/v1beta/properties/' . rawurlencode($propriedade) . ':runReport',
        ['Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Accept: application/json'],
        json_encode($pedido + ['returnPropertyQuota' => true]));
}

// Dimensoes e metricas da propriedade, inclusive as personalizadas (getMetadata)
function ga4_api_metadados(string $propriedade, string $token): array
{
    return ga4_api_http('GET', ga4_api_base() . '/v1beta/properties/' . rawurlencode($propriedade) . '/metadata',
        ['Authorization: Bearer ' . $token, 'Accept: application/json']);
}

// Erro de uma chamada a Data API, em texto para a tela, com o que fazer
function ga4_api_erro(int $status, ?array $corpo, string $propriedade, string $email): string
{
    $m = ga4_api_mensagem($corpo);
    if ($status === 0) {
        return 'Sem conexão com o Google. Tente de novo em instantes.';
    }
    if ($status === GA4_ADIADA) {
        return $m;
    }
    if ($status === 429) {
        return 'O Google pediu uma pausa (cota do GA4). O painel espera e tenta sozinho.';
    }
    if ($status === 403 && preg_match('/has not been used|is disabled|SERVICE_DISABLED/i', json_encode($corpo) ?: '')) {
        return 'A Google Analytics Data API está desligada no projeto da conta de serviço. No Google Cloud: APIs e serviços → Biblioteca → "Google Analytics Data API" → Ativar.';
    }
    if ($status === 403 || $status === 404) {
        return 'A conta de serviço não tem acesso à propriedade ' . $propriedade . '. No GA4: Administrador → Gerenciamento de acesso da propriedade → + → Adicionar usuários → ' . $email . ', papel Leitor, sem notificar.';
    }
    return 'O GA4 respondeu com erro (HTTP ' . $status . ')' . ($m ? ': ' . $m : '') . '.';
}

// Confere a conexao de ponta a ponta: token e um relatorio pequeno em cada propriedade.
// Devolve ['ok', 'erro', 'propriedades' => [id => ['eventos' => n dos ultimos 7 dias, 'fuso', 'moeda']]].
function ga4_api_testar(string $email, string $chave, array $propriedades): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'erro' => 'O PHP deste servidor está sem a extensão curl, necessária para falar com o Google.'];
    }
    $t = ga4_api_token($email, $chave);
    if (!$t['ok']) {
        return $t;
    }
    $saida = [];
    foreach ($propriedades as $p) {
        [$status, $corpo] = ga4_api_relatorio($p['id'], [
            'dateRanges' => [['startDate' => '7daysAgo', 'endDate' => 'today']],
            'metrics' => [['name' => 'eventCount']],
            'limit' => 1,
        ], $t['token']);
        if ($status !== 200) {
            return ['ok' => false, 'adiada' => $status === GA4_ADIADA || $status === 429, 'erro' => ($p['nome'] !== '' ? $p['nome'] . ': ' : '') . ga4_api_erro($status, $corpo, $p['id'], $email)];
        }
        $saida[$p['id']] = ['eventos' => (int)($corpo['rows'][0]['metricValues'][0]['value'] ?? 0),
            'fuso' => texto($corpo['metadata']['timeZone'] ?? '', 60), 'moeda' => texto($corpo['metadata']['currencyCode'] ?? '', 8)];
    }
    return ['ok' => true, 'propriedades' => $saida];
}
