<?php
// Cliente da API do Instagram, so para LER o perfil da marca: seguidores, posts e reels e os
// insights (alcance, visualizacoes, interacoes, toques nos links do perfil). Alimenta o bloco
// "Perfil do Instagram" da aba Organico. Dois caminhos, com os mesmos numeros:
//
// - 'facebook': o proprio token da tela Meta Ads (Integracoes) (usuario do sistema, nao vence), com
//   instagram_basic e instagram_manage_insights e a conta do Instagram ligada a pagina do
//   portfolio. Consultas em graph.facebook.com, na conta /<id do Instagram>.
// - 'instagram': token do login do Instagram (IGAA...), gerado no painel de apps da Meta e
//   colado na tela Instagram (Integracoes). Consultas em graph.instagram.com, em /me. Vale 60 dias; o
//   painel renova sozinho a cada 7.
//
// A chave secreta do app nao e usada. Mesma protecao contra bloqueio das outras APIs: limite
// por minuto e pausa automatica quando a API avisa que passou do limite.

require_once __DIR__ . '/meta_api.php';

const IG_API_VERSAO = 'v23.0';
const IG_ADIADA = -1;
const IG_RENOVAR_APOS = 7 * 86400;

// Em teste, TRACK_IG_API aponta para um servidor falso local (sem a versao no fim)
function ig_api_host(): string
{
    return rtrim(getenv('TRACK_IG_API') ?: 'https://graph.instagram.com', '/');
}

// Configuracao salva, ja com o token certo (no caminho 'facebook', o da tela Meta Ads (Integracoes))
function ig_api_chave(): ?array
{
    $k = track_config()['instagram_api'] ?? null;
    if (!is_array($k)) {
        return null;
    }
    if (($k['modo'] ?? 'instagram') === 'facebook') {
        $meta = meta_api_chave();
        return $meta && preg_match('/^\d{6,25}$/', (string)($k['ig_id'] ?? '')) ? ['token' => $meta['token']] + $k : null;
    }
    return !empty($k['token']) ? $k : null;
}

// Com quem e como falar: token, caminho e a conta ("/me" ou "/<id do Instagram>")
function ig_ctx(array $k): array
{
    $modo = ($k['modo'] ?? 'instagram') === 'facebook' ? 'facebook' : 'instagram';
    return ['token' => (string)$k['token'], 'modo' => $modo, 'conta' => $modo === 'facebook' ? '/' . $k['ig_id'] : '/me'];
}

function ig_campos_perfil(string $modo): string
{
    return $modo === 'facebook' ? 'id,username,name,followers_count,follows_count,media_count'
        : 'user_id,username,name,account_type,followers_count,follows_count,media_count';
}

function ig_api_formato_valido(string $token): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_\-.|]{40,1000}$/', $token);
}

// Acha o token no que foi colado: tira espacos, quebras de linha e caracteres invisiveis e
// aceita aspas, "access_token=" ou o JSON inteiro em volta. null = nada com cara de token.
function ig_extrair_token(string $colado): ?string
{
    $t = preg_replace('/[\s\p{Cf}]+/u', '', $colado) ?? preg_replace('/\s+/', '', $colado);
    if (preg_match('/(?:IGAA|IGQV|EAA)[A-Za-z0-9_\-]{30,1000}/', $t, $m)) {
        return $m[0];
    }
    return preg_match('/^[A-Za-z0-9_\-]{100,1000}$/', $t) ? $t : null;
}

// Diz o que foi colado no lugar do token (sem repetir o texto: so o tamanho e o comeco)
function ig_explicar_colado(string $colado): string
{
    $t = trim(preg_replace('/[\s\p{Cf}]+/u', '', $colado) ?? $colado);
    $onde = ' O token sai do app da Meta → Instagram → Configuração da API com login do Instagram → 2. Gerar tokens de acesso → Gerar token.'
        . ' Começa com "IGAA" e tem mais de 150 caracteres. Ou use a opção 1, com o token da API Meta.';
    if ($t === '') {
        return 'Cole o token.' . $onde;
    }
    if (preg_match('/^[a-f0-9]{32}$/i', $t)) {
        return 'Isso é a chave secreta do app (32 caracteres), não o token. Ela não é usada aqui e não deve ser compartilhada.' . $onde;
    }
    if (preg_match('/^\d{5,25}$/', $t)) {
        return 'Isso é um número de ID (' . strlen($t) . ' dígitos), do app ou da conta, não o token.' . $onde;
    }
    return 'O texto colado tem ' . mb_strlen($t) . ' caracteres e começa com "' . mb_substr($t, 0, 4) . '": não parece um token.' . $onde;
}

function ig_api_limite_minuto(): int
{
    return max(1, (int)(getenv('TRACK_IG_LIMITE_MINUTO') ?: 30));
}

function ig_api_uso(int $janela): int
{
    $st = track_db()->prepare("SELECT COUNT(*) FROM limites WHERE chave = 'ig-api' AND em >= ?");
    $st->execute([time() - $janela]);
    return (int)$st->fetchColumn();
}

function ig_api_pausa_ate(): int
{
    $ate = (int)(ajuste('ig_api_pausa_ate') ?? 0);
    return $ate > time() ? $ate : 0;
}

// Mensagem de erro da API, curta (vai para a tela, escapada)
function ig_api_mensagem(?array $corpo): string
{
    $m = $corpo['error']['message'] ?? '';
    return is_string($m) ? texto($m, 200) : '';
}

// GET na API. O token vai no cabecalho, nunca na URL (URL cai em log).
// Devolve [status, corpo]. 0 = sem conexao; IG_ADIADA = nao chamou (limite ou pausa).
// $raiz: rota sem a versao (so /refresh_access_token).
function ig_api_get(array $ctx, string $rota, array $consulta = [], bool $raiz = false): array
{
    if ($ate = ig_api_pausa_ate()) {
        return [IG_ADIADA, ['error' => ['message' => 'O Instagram pediu uma pausa nas consultas; o painel volta sozinho às ' . data_local(gmdate('Y-m-d H:i:s', $ate), 'H:i') . '.']]];
    }
    if (!dentro_do_limite('ig-api', ig_api_limite_minuto(), 60)) {
        return [IG_ADIADA, ['error' => ['message' => 'Limite interno de ' . ig_api_limite_minuto() . ' consultas por minuto ao Instagram atingido (proteção contra bloqueio).']]];
    }
    $base = $ctx['modo'] === 'facebook' ? meta_api_base() : ig_api_host() . ($raiz ? '' : '/' . IG_API_VERSAO);
    $ch = curl_init($base . $rota . ($consulta ? '?' . http_build_query($consulta) : ''));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $ctx['token'], 'Accept: application/json'],
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

    // Limite: HTTP 429 ou os codigos de "muitas chamadas" da Graph API (4, 17, 32, 613)
    $codigo = (int)($json['error']['code'] ?? 0);
    if ($status === 429 || in_array($codigo, [4, 17, 32, 613], true)) {
        $pausa = min(3600, max(300, 2 * (int)(ajuste('ig_api_pausa_seg') ?? 150)));
        definir_ajuste('ig_api_pausa_seg', (string)$pausa);
        definir_ajuste('ig_api_pausa_ate', (string)(time() + $pausa));
        return [IG_ADIADA, ['error' => ['message' => 'O Instagram avisou que passou do limite de consultas. O painel espera ' . intdiv($pausa, 60) . ' minutos e tenta sozinho.']]];
    }
    if ($status >= 200 && $status < 300) {
        definir_ajuste('ig_api_pausa_seg', null);
    }
    return [$status, $json];
}

// Valor de um insight, no formato de conta (total_value) ou de post (values[0])
function ig_valor(array $item): ?int
{
    $v = $item['total_value']['value'] ?? ($item['values'][0]['value'] ?? null);
    return is_numeric($v) ? (int)$v : null;
}

// Confere o acesso: enxerga a conta profissional e le os insights.
// Devolve ['ok', 'erro', 'usuario_id', 'usuario', 'nome', 'tipo', 'seguidores', 'posts', 'insights', 'erro_insights'].
function ig_api_testar(array $ctx): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'erro' => 'O PHP deste servidor está sem a extensão curl.'];
    }
    [$st, $c] = ig_api_get($ctx, $ctx['conta'], ['fields' => ig_campos_perfil($ctx['modo'])]);
    if ($st === IG_ADIADA) {
        return ['ok' => false, 'adiada' => true, 'erro' => ig_api_mensagem($c)];
    }
    if ($st === 0) {
        return ['ok' => false, 'erro' => 'Sem conexão com a API do Instagram. Tente de novo em instantes.'];
    }
    if ($st !== 200 || empty($c['username'])) {
        $m = ig_api_mensagem($c);
        $dica = '';
        if ($ctx['modo'] === 'instagram' && (stripos($m, 'parse') !== false || stripos($m, 'invalid') !== false)) {
            $dica = ' O texto colado tem ' . strlen($ctx['token']) . ' caracteres e começa com "' . substr($ctx['token'], 0, 4)
                . '". O token do Instagram começa com "IGAA" e tem mais de 150 caracteres: gere de novo e use o botão Copiar da própria Meta. A chave secreta do app não serve aqui.';
        }
        return ['ok' => false, 'erro' => 'O Instagram recusou o token' . ($m ? ': ' . $m : '') . '.' . $dica];
    }
    $r = [
        'ok' => true,
        'usuario_id' => preg_replace('/\D/', '', (string)($c['user_id'] ?? $c['id'] ?? '')),
        'usuario' => texto($c['username'], 60),
        'nome' => texto($c['name'] ?? '', 120),
        'tipo' => texto($c['account_type'] ?? '', 30),
        'seguidores' => (int)($c['followers_count'] ?? 0),
        'posts' => (int)($c['media_count'] ?? 0),
        'insights' => false,
        'erro_insights' => '',
    ];

    // Sem a permissao de insights, o painel ainda mostra seguidores e posts (curtidas e
    // comentarios), e avisa
    $permissao = $ctx['modo'] === 'facebook' ? 'instagram_manage_insights' : 'instagram_business_manage_insights';
    [$st, $c] = ig_api_get($ctx, $ctx['conta'] . '/insights', ['metric' => 'reach', 'period' => 'day', 'metric_type' => 'total_value']);
    if ($st === 200) {
        $r['insights'] = true;
    } else {
        $r['erro_insights'] = $st === IG_ADIADA ? ig_api_mensagem($c) : 'sem a permissão "' . $permissao . '"' . (($m = ig_api_mensagem($c)) ? ' (' . $m . ')' : '');
    }
    return $r;
}

// Contas do Instagram que o token da tela Meta Ads (Integracoes) enxerga, pelas paginas do portfolio.
// Devolve ['ok', 'erro', 'contas' => [['id', 'usuario', 'pagina']]].
function ig_api_contas_meta(string $tokenMeta): array
{
    $falta = [];
    [$st, $c] = meta_api_get('/me/permissions', [], $tokenMeta);
    if ($st === 200 && is_array($c['data'] ?? null)) {
        $tem = [];
        foreach ($c['data'] as $p) {
            if (($p['status'] ?? '') === 'granted') {
                $tem[] = $p['permission'] ?? '';
            }
        }
        $falta = array_values(array_diff(['instagram_basic', 'instagram_manage_insights', 'pages_show_list'], $tem));
    }
    [$st, $c] = meta_api_get('/me/accounts', ['fields' => 'name,instagram_business_account{id,username}', 'limit' => '100'], $tokenMeta);
    if ($st === META_ADIADA) {
        return ['ok' => false, 'erro' => meta_api_mensagem($c)];
    }
    $contas = [];
    foreach ($st === 200 ? (array)($c['data'] ?? []) : [] as $pag) {
        $ig = $pag['instagram_business_account'] ?? null;
        if (is_array($ig) && preg_match('/^\d{6,25}$/', (string)($ig['id'] ?? ''))) {
            $contas[] = ['id' => (string)$ig['id'], 'usuario' => texto($ig['username'] ?? '', 60), 'pagina' => texto($pag['name'] ?? '', 120)];
        }
    }
    if ($contas) {
        return ['ok' => true, 'contas' => $contas];
    }
    $m = $st === 200 ? '' : meta_api_mensagem($c);
    return ['ok' => false, 'erro' => 'O token da API Meta não enxerga nenhuma conta do Instagram' . ($m ? ' (' . $m . ')' : '') . '.'
        . ($falta ? ' Faltam as permissões: ' . implode(', ', $falta) . '.' : '')
        . ' Confira se o Instagram está ligado à página do Facebook no portfólio e se a página e o Instagram estão atribuídos ao usuário do sistema.'];
}

// Renova o token do login do Instagram quando o ultimo tem mais de 7 dias (o Instagram so
// renova token com mais de 24 horas). Grava o novo na configuracao. Falha nao apaga o token
// atual. O token da API Meta (usuario do sistema) nao vence e nao passa por aqui.
function ig_api_renovar(array $k): array
{
    if (($k['modo'] ?? 'instagram') !== 'instagram') {
        return $k;
    }
    $ultima = strtotime((string)($k['renovado_em'] ?? $k['salva_em'] ?? '') . ' UTC') ?: 0;
    if (time() - $ultima < IG_RENOVAR_APOS) {
        return $k;
    }
    [$st, $c] = ig_api_get(ig_ctx($k), '/refresh_access_token', ['grant_type' => 'ig_refresh_token'], true);
    $novo = (string)($c['access_token'] ?? '');
    if ($st !== 200 || !ig_api_formato_valido($novo)) {
        if ($st !== IG_ADIADA) {
            definir_ajuste('ig_renovar_erro', ig_api_mensagem($c) ?: 'HTTP ' . $st);
        }
        return $k;
    }
    $cfg = track_config();
    $cfg['instagram_api']['token'] = $novo;
    $cfg['instagram_api']['renovado_em'] = agora_utc();
    $cfg['instagram_api']['vence_em'] = gmdate('Y-m-d H:i:s', time() + max(86400, (int)($c['expires_in'] ?? 5184000)));
    if (track_salvar_config($cfg)) {
        definir_ajuste('ig_renovar_erro', null);
        return $cfg['instagram_api'];
    }
    return $k;
}
