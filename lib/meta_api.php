<?php
// Cliente da API de Marketing da Meta (Graph API), so para LER: quanto cada anuncio gastou,
// para o painel calcular ROI e ROAS ao lado das vendas.
//
// Token de usuario do sistema, colado uma vez na aba API Meta e guardado na configuracao, fora
// da pasta publica. Precisa de ads_read. Token que tambem pode editar (ads_management,
// business_management) e aceito com aviso: decisao de 29/09/2026, para o gestor poder ligar,
// pausar e mudar orcamento no futuro. Ate la o painel so le; toda edicao, quando existir, pede
// confirmacao e fica registrada. Mesma protecao contra bloqueio da API da Kiwify: limite por
// minuto e pausa automatica quando a Meta avisa que passou do limite.

const META_API_VERSAO = 'v23.0';
const META_ADIADA = -1;

// Permissoes de escrita: aceitas, mas a tela avisa que o token pode editar
const META_PERMISSOES_EDICAO = [
    'ads_management' => 'gerenciar anúncios (orçamento, campanhas, pausar)',
    'business_management' => 'gerenciar o negócio',
    'pages_manage_ads' => 'gerenciar anúncios da página',
];

// Em teste, TRACK_META_API aponta para um servidor falso local
function meta_api_base(): string
{
    return rtrim(getenv('TRACK_META_API') ?: 'https://graph.facebook.com/' . META_API_VERSAO, '/');
}

function meta_api_chave(): ?array
{
    $k = track_config()['meta_api'] ?? null;
    return is_array($k) && !empty($k['token']) && !empty($k['conta']) ? $k : null;
}

// Conta de anuncios so com os numeros (aceita "act_123...")
function meta_api_conta(string $conta): string
{
    return preg_replace('/^act_/i', '', trim($conta));
}

function meta_api_formato_valido(string $token, string $conta): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_\-.]{40,800}$/', $token) && (bool)preg_match('/^\d{6,20}$/', $conta);
}

function meta_api_limite_minuto(): int
{
    return max(1, (int)(getenv('TRACK_META_LIMITE_MINUTO') ?: 30));
}

function meta_api_uso(int $janela): int
{
    $st = track_db()->prepare("SELECT COUNT(*) FROM limites WHERE chave = 'meta-api' AND em >= ?");
    $st->execute([time() - $janela]);
    return (int)$st->fetchColumn();
}

function meta_api_pausa_ate(): int
{
    $ate = (int)(ajuste('meta_api_pausa_ate') ?? 0);
    return $ate > time() ? $ate : 0;
}

// Mensagem de erro da Meta, curta (vai para a tela, escapada)
function meta_api_mensagem(?array $corpo): string
{
    $m = $corpo['error']['message'] ?? '';
    return is_string($m) ? texto($m, 200) : '';
}

// GET na Graph API. O token vai no cabecalho, nunca na URL (URL cai em log).
// Devolve [status, corpo]. 0 = sem conexao; META_ADIADA = nao chamou (limite ou pausa).
function meta_api_get(string $rota, array $consulta, string $token): array
{
    if ($ate = meta_api_pausa_ate()) {
        return [META_ADIADA, ['error' => ['message' => 'A Meta pediu uma pausa nas consultas; o painel volta sozinho às ' . data_local(gmdate('Y-m-d H:i:s', $ate), 'H:i') . '.']]];
    }
    if (!dentro_do_limite('meta-api', meta_api_limite_minuto(), 60)) {
        return [META_ADIADA, ['error' => ['message' => 'Limite interno de ' . meta_api_limite_minuto() . ' consultas por minuto à Meta atingido (proteção contra bloqueio).']]];
    }
    $ch = curl_init(meta_api_base() . $rota . ($consulta ? '?' . http_build_query($consulta) : ''));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
    ]);
    $resposta = curl_exec($ch);
    $status = $resposta === false ? 0 : (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $json = is_string($resposta) ? json_decode($resposta, true) : null;
    $json = is_array($json) ? $json : null;

    // Limite da Meta: HTTP 429 ou os codigos de "muitas chamadas" (4, 17, 32, 613, 80000-80014)
    $codigo = (int)($json['error']['code'] ?? 0);
    if ($status === 429 || in_array($codigo, [4, 17, 32, 613], true) || ($codigo >= 80000 && $codigo <= 80014)) {
        $pausa = min(3600, max(300, 2 * (int)(ajuste('meta_api_pausa_seg') ?? 150)));
        definir_ajuste('meta_api_pausa_seg', (string)$pausa);
        definir_ajuste('meta_api_pausa_ate', (string)(time() + $pausa));
        return [META_ADIADA, ['error' => ['message' => 'A Meta avisou que passou do limite de consultas. O painel espera ' . intdiv($pausa, 60) . ' minutos e tenta sozinho.']]];
    }
    if ($status >= 200 && $status < 300) {
        definir_ajuste('meta_api_pausa_seg', null);
    }
    return [$status, $json];
}

// Valor da Meta ("1539.22", na moeda da conta) em centavos
function meta_centavos($v): int
{
    return is_numeric($v) ? (int)round((float)$v * 100) : 0;
}

// Confere o token de ponta a ponta: vale, so le, enxerga a conta e le o gasto.
// Devolve ['ok', 'erro', 'conta_nome', 'moeda', 'permissoes', 'gasto_7d' (centavos)].
function meta_api_testar(string $token, string $conta): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'erro' => 'O PHP deste servidor está sem a extensão curl.'];
    }
    [$st, $c] = meta_api_get('/me', ['fields' => 'id,name'], $token);
    if ($st === META_ADIADA) {
        return ['ok' => false, 'adiada' => true, 'erro' => meta_api_mensagem($c)];
    }
    if ($st === 0) {
        return ['ok' => false, 'erro' => 'Sem conexão com a API da Meta. Tente de novo em instantes.'];
    }
    if ($st !== 200) {
        $m = meta_api_mensagem($c);
        return ['ok' => false, 'erro' => 'A Meta recusou o token' . ($m ? ': ' . $m : '') . '.'];
    }

    // Permissoes concedidas ao token. Se a Meta nao listar, segue (a conta confere o acesso).
    $permissoes = [];
    [$st, $c] = meta_api_get('/me/permissions', [], $token);
    if ($st === 200 && is_array($c['data'] ?? null)) {
        foreach ($c['data'] as $p) {
            if (($p['status'] ?? '') === 'granted' && is_string($p['permission'] ?? null)) {
                $permissoes[] = $p['permission'];
            }
        }
    }
    $edita = array_values(array_intersect_key(META_PERMISSOES_EDICAO, array_flip($permissoes)));
    if ($permissoes && !in_array('ads_read', $permissoes, true)) {
        return ['ok' => false, 'erro' => 'Este token não tem a permissão "ads_read" (ler anúncios). Gere outro marcando "ads_read".'];
    }

    [$st, $c] = meta_api_get('/act_' . $conta, ['fields' => 'name,currency,account_status'], $token);
    if ($st === META_ADIADA) {
        return ['ok' => false, 'adiada' => true, 'erro' => meta_api_mensagem($c)];
    }
    if ($st !== 200 || empty($c['name'])) {
        $m = meta_api_mensagem($c);
        return ['ok' => false, 'erro' => 'O token entrou, mas não enxerga a conta de anúncios ' . $conta . ($m ? ': ' . $m : '')
            . '. Confira o número da conta e se o usuário do sistema tem acesso a ela.'];
    }
    $nome = texto($c['name'], 120);
    $moeda = texto($c['currency'] ?? '', 5);

    [$st, $ins] = meta_api_get('/act_' . $conta . '/insights', ['fields' => 'spend', 'date_preset' => 'last_7d'], $token);
    if ($st !== 200) {
        $m = meta_api_mensagem($ins);
        return ['ok' => false, 'adiada' => $st === META_ADIADA, 'erro' => 'A conta respondeu, mas a leitura do gasto falhou' . ($m ? ': ' . $m : '') . '.'];
    }
    $gasto = 0;
    foreach ((array)($ins['data'] ?? []) as $linha) {
        $gasto += meta_centavos($linha['spend'] ?? 0);
    }
    return ['ok' => true, 'conta_nome' => $nome, 'moeda' => $moeda, 'permissoes' => $permissoes, 'edita' => $edita, 'gasto_7d' => $gasto];
}
