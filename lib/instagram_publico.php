<?php
// Publico do Instagram: sexo, idade, cidades e paises de quem segue o perfil, de quem viu
// algum conteudo (contas alcancadas) e de quem interagiu (contas engajadas).
//
// O Instagram entrega so o total de cada grupo (nunca quem e quem) e so para perfis com 100
// seguidores ou mais. Muda devagar: busca uma vez por dia (15 consultas) e guarda em
// ajustes ('ig_publico'). Alcancadas e engajadas: o mes corrente (timeframe=this_month).

const IG_PUBLICOS = [
    'seguidores' => ['follower_demographics', 'Seguidores', 'quem segue o perfil hoje'],
    'alcancadas' => ['reached_audience_demographics', 'Contas alcançadas', 'quem viu algum conteúdo do perfil neste mês'],
    'engajadas' => ['engaged_audience_demographics', 'Contas engajadas', 'quem curtiu, comentou, salvou, compartilhou ou respondeu neste mês'],
];
const IG_PUBLICO_QUEBRAS = ['gender', 'age', 'age,gender', 'city', 'country'];
const IG_PUBLICO_INTERVALO = 86400;
const IG_IDADES = ['13-17', '18-24', '25-34', '35-44', '45-54', '55-64', '65+'];
const IG_SEXOS = ['F' => 'Mulheres', 'M' => 'Homens', 'U' => 'Não informado'];
const IG_PAISES = ['BR' => 'Brasil', 'PT' => 'Portugal', 'US' => 'Estados Unidos', 'AO' => 'Angola', 'MZ' => 'Moçambique', 'AR' => 'Argentina',
    'PY' => 'Paraguai', 'UY' => 'Uruguai', 'CL' => 'Chile', 'CO' => 'Colômbia', 'PE' => 'Peru', 'BO' => 'Bolívia', 'MX' => 'México',
    'ES' => 'Espanha', 'IT' => 'Itália', 'FR' => 'França', 'DE' => 'Alemanha', 'GB' => 'Reino Unido', 'JP' => 'Japão', 'CA' => 'Canadá'];

function ig_publico(): ?array
{
    $p = json_decode((string)ajuste('ig_publico'), true);
    return is_array($p) && isset($p['publicos']) ? $p : null;
}

function ig_publico_vencido(): bool
{
    $p = ig_publico();
    return !$p || time() - (int)strtotime($p['em'] . ' UTC') >= IG_PUBLICO_INTERVALO;
}

// Uma quebra de um publico: [grupo => total], do maior para o menor
function ig_publico_buscar(array $ctx, string $metrica, string $quebra, bool $mes): array
{
    $q = ['metric' => $metrica, 'period' => 'lifetime', 'metric_type' => 'total_value', 'breakdown' => $quebra];
    if ($mes) {
        $q['timeframe'] = 'this_month';
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
    return ['dados' => array_slice($grupos, 0, 60, true)];
}

// Busca os tres publicos. false = parou no limite de consultas (tenta na proxima busca).
function ig_sync_publico(array $ctx): bool
{
    $publicos = [];
    foreach (IG_PUBLICOS as $id => [$metrica]) {
        $p = [];
        foreach (IG_PUBLICO_QUEBRAS as $quebra) {
            $r = ig_publico_buscar($ctx, $metrica, $quebra, $id !== 'seguidores');
            if (!empty($r['adiada'])) {
                return false;
            }
            if (isset($r['erro'])) {
                // Sem a quebra, o resto do publico tambem nao vem (conta pequena, permissao)
                $p = ['erro' => $r['erro']];
                break;
            }
            $p[str_replace(',', '_', $quebra)] = $r['dados'];
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
