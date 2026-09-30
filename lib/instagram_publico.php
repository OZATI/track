<?php
// Publico do Instagram: sexo, idade, cidades e paises de quem segue o perfil, de quem viu
// algum conteudo (contas alcancadas) e de quem interagiu (contas engajadas).
//
// O Instagram entrega so o total de cada grupo (nunca quem e quem) e so para perfis com 100
// seguidores ou mais. Muda devagar: busca uma vez por dia e guarda em ajustes ('ig_publico').
// Alcancadas e engajadas pedem um periodo (timeframe): o painel tenta o mes corrente e, se
// vier vazio, os outros que o Instagram aceita. Veio vazio ou com erro: tenta de novo na
// proxima busca (depois de 10 minutos).

const IG_PUBLICOS = [
    'seguidores' => ['follower_demographics', 'Seguidores', 'quem segue o perfil hoje'],
    'alcancadas' => ['reached_audience_demographics', 'Contas alcançadas', 'quem viu algum conteúdo do perfil'],
    'engajadas' => ['engaged_audience_demographics', 'Contas engajadas', 'quem curtiu, comentou, salvou, compartilhou ou respondeu'],
];
const IG_PUBLICO_QUEBRAS = ['gender', 'age', 'age,gender', 'city', 'country'];
const IG_PUBLICO_PERIODOS = ['this_month' => 'neste mês', 'last_30_days' => 'nos últimos 30 dias', 'this_week' => 'nesta semana'];
const IG_PUBLICO_INTERVALO = 86400;
const IG_PUBLICO_REPETIR = 600; // vazio: de novo na proxima busca (automatica, de hora em hora, ou o botao)
const IG_IDADES = ['13-17', '18-24', '25-34', '35-44', '45-54', '55-64', '65+'];
const IG_SEXOS = ['F' => 'Mulheres', 'M' => 'Homens', 'U' => 'Não informado'];
const IG_PAISES = ['BR' => 'Brasil', 'PT' => 'Portugal', 'US' => 'Estados Unidos', 'AO' => 'Angola', 'MZ' => 'Moçambique', 'CV' => 'Cabo Verde',
    'AR' => 'Argentina', 'PY' => 'Paraguai', 'UY' => 'Uruguai', 'CL' => 'Chile', 'CO' => 'Colômbia', 'PE' => 'Peru', 'BO' => 'Bolívia',
    'VE' => 'Venezuela', 'EC' => 'Equador', 'MX' => 'México', 'CA' => 'Canadá', 'ES' => 'Espanha', 'IT' => 'Itália', 'FR' => 'França',
    'DE' => 'Alemanha', 'GB' => 'Reino Unido', 'IE' => 'Irlanda', 'NL' => 'Países Baixos', 'BE' => 'Bélgica', 'CH' => 'Suíça',
    'PL' => 'Polônia', 'CN' => 'China', 'JP' => 'Japão', 'IN' => 'Índia', 'AE' => 'Emirados Árabes', 'AU' => 'Austrália'];

function ig_publico(): ?array
{
    $p = json_decode((string)ajuste('ig_publico'), true);
    return is_array($p) && isset($p['publicos']) ? $p : null;
}

// Publico sem nenhum numero (erro ou resposta vazia)
function ig_publico_vazio(array $p): bool
{
    if (isset($p['erro'])) {
        return true;
    }
    foreach (IG_PUBLICO_QUEBRAS as $q) {
        if (!empty($p[str_replace(',', '_', $q)])) {
            return false;
        }
    }
    return true;
}

function ig_publico_vencido(): bool
{
    $p = ig_publico();
    if (!$p) {
        return true;
    }
    $idade = time() - (int)strtotime($p['em'] . ' UTC');
    // Com erro: de novo na proxima busca. Resposta sem numero (o Instagram nao tem o publico
    // desse periodo para a conta): a cada 6 horas, para nao gastar consulta a toa.
    $comErro = array_filter($p['publicos'], fn($x) => isset($x['erro']));
    $semNumero = array_filter($p['publicos'], 'ig_publico_vazio');
    return $idade >= IG_PUBLICO_INTERVALO || ($comErro && $idade >= IG_PUBLICO_REPETIR) || ($semNumero && $idade >= 6 * 3600);
}

// Uma quebra de um publico: ['dados' => [grupo => total]] (do maior para o menor),
// ['erro' => ...] ou ['adiada' => true]. $periodo: timeframe (so alcancadas e engajadas).
function ig_publico_buscar(array $ctx, string $metrica, string $quebra, ?string $periodo): array
{
    $q = ['metric' => $metrica, 'period' => 'lifetime', 'metric_type' => 'total_value', 'breakdown' => $quebra];
    if ($periodo !== null) {
        $q['timeframe'] = $periodo;
    }
    [$st, $c] = ig_api_get($ctx, $ctx['conta'] . '/insights', $q);
    if ($st === IG_ADIADA) {
        return ['adiada' => true];
    }
    if ($st !== 200) {
        return ['erro' => ig_api_mensagem($c) ?: ($st === 0 ? 'sem conexão com o Instagram' : 'HTTP ' . $st)];
    }
    $grupos = [];
    foreach ((array)($c['data'][0]['total_value']['breakdowns'][0]['results'] ?? []) as $r) {
        $chave = implode('|', array_map(fn($v) => texto((string)$v, 80), (array)($r['dimension_values'] ?? [])));
        if ($chave !== '' && is_numeric($r['value'] ?? null)) {
            $grupos[$chave] = (int)$r['value'];
        }
    }
    arsort($grupos);
    // Resposta sem numero: guarda o comeco dela (so a estrutura das metricas) para o diagnostico
    return ['dados' => array_slice($grupos, 0, 60, true)] + ($grupos ? [] : ['resposta' => texto((string)json_encode($c, JSON_UNESCAPED_UNICODE), 300)]);
}

// Busca os tres publicos. false = parou no limite de consultas (tenta na proxima busca).
function ig_sync_publico(array $ctx): bool
{
    $publicos = [];
    foreach (IG_PUBLICOS as $id => [$metrica]) {
        $periodos = $id === 'seguidores' ? [null] : array_keys(IG_PUBLICO_PERIODOS);
        $p = [];
        foreach ($periodos as $periodo) {
            $p = $periodo !== null ? ['periodo' => $periodo] : [];
            foreach (IG_PUBLICO_QUEBRAS as $quebra) {
                $r = ig_publico_buscar($ctx, $metrica, $quebra, $periodo);
                if (!empty($r['adiada'])) {
                    return false;
                }
                if (isset($r['erro'])) {
                    $p['erro'] = $r['erro'];
                    break;
                }
                $p[str_replace(',', '_', $quebra)] = $r['dados'];
                if (isset($r['resposta'])) {
                    $p['resposta'] = $r['resposta'];
                }
                // Sem sexo nem idade neste periodo: nao adianta pedir cidade e pais, tenta o proximo
                if ($quebra === 'age' && !$p['gender'] && !$p['age']) {
                    break;
                }
            }
            if (!ig_publico_vazio($p)) {
                unset($p['resposta']);
                break;
            }
        }
        $publicos[$id] = $p;
    }
    definir_ajuste('ig_publico', json_encode(['em' => agora_utc(), 'publicos' => $publicos], JSON_UNESCAPED_UNICODE));
    return true;
}

// "São Paulo, São Paulo (state)" -> "São Paulo"
function ig_cidade(string $v): string
{
    return trim(explode(',', $v)[0]);
}

function ig_pais(string $codigo): string
{
    return IG_PAISES[strtoupper($codigo)] ?? $codigo;
}
