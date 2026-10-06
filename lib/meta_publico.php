<?php
// Quem compra pela Meta, por sexo e idade, numa campanha e num periodo (a "pizza do sexo de quem
// mais compra" da analise diaria). A Kiwify nao pergunta o sexo do comprador: a unica fonte e
// a Meta, que divide as compras que ELA atribui ao anuncio (evento Purchase do pixel). Por isso
// o total pode ser diferente das vendas da Kiwify.
//
// Uma consulta por campanha e periodo (/<campanha>/insights com breakdowns=age,gender), guardada
// em meta_publico: 3 horas se o periodo inclui hoje, 24 horas se ja fechou.

require_once __DIR__ . '/meta_sync.php';

const META_SEXOS = ['female' => 'Mulheres', 'male' => 'Homens', 'unknown' => 'Não informado'];
const META_COMPRA = ['offsite_conversion.fb_pixel_purchase', 'purchase', 'omni_purchase'];

// Alcance de um conjunto ou campanha no periodo inteiro (o alcance nao soma de um dia para o
// outro: a mesma pessoa conta em cada dia). Guardado em meta_publico como o quem compra.
// ['alcance', 'impressoes', 'frequencia', 'buscado_em'] ou null (sem token ou a Meta nao respondeu)
function meta_alcance_periodo(string $id, string $dia1, string $dia2): ?array
{
    static $memo = [];
    $chave = 'alcance|' . $id . '|' . $dia1 . '|' . $dia2;
    if (array_key_exists($chave, $memo)) {
        return $memo[$chave];
    }
    $db = track_db();
    $st = $db->prepare('SELECT * FROM meta_publico WHERE chave = ?');
    $st->execute([$chave]);
    $guardado = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    $hoje = (new DateTime('today', fuso()))->format('Y-m-d');
    $validade = $dia2 >= $hoje ? 3 * 3600 : 24 * 3600;
    $ler = function (?array $g): ?array {
        $d = $g ? json_decode($g['dados'], true) : null;
        return is_array($d) && $d['alcance'] > 0 ? $d + ['frequencia' => $d['impressoes'] / $d['alcance'], 'buscado_em' => $g['buscado_em']] : null;
    };
    if ($guardado && time() - strtotime($guardado['buscado_em'] . ' UTC') < $validade) {
        return $memo[$chave] = $ler($guardado);
    }
    $k = meta_api_chave();
    $r = $k ? meta_listar('/' . $id . '/insights', [
        'time_range' => json_encode(['since' => $dia1, 'until' => min($dia2, $hoje)]),
        'fields' => 'reach,impressions',
        'limit' => '5',
    ], $k['token']) : ['ok' => false];
    if (!$r['ok'] || !$r['itens']) {
        return $memo[$chave] = $ler($guardado);
    }
    $agora = agora_utc();
    $dados = ['alcance' => (int)($r['itens'][0]['reach'] ?? 0), 'impressoes' => (int)($r['itens'][0]['impressions'] ?? 0)];
    $db->prepare('INSERT OR REPLACE INTO meta_publico (chave, dados, buscado_em) VALUES (?, ?, ?)')->execute([$chave, json_encode($dados), $agora]);
    return $memo[$chave] = $ler(['dados' => json_encode($dados), 'buscado_em' => $agora]);
}

// [ok, erro, linhas [[idade, sexo, compras, gasto em centavos]], buscado_em]
function meta_publico_campanha(string $id, string $dia1, string $dia2): array
{
    $db = track_db();
    $chave = $id . '|' . $dia1 . '|' . $dia2;
    $st = $db->prepare('SELECT * FROM meta_publico WHERE chave = ?');
    $st->execute([$chave]);
    $guardado = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    $hoje = (new DateTime('today', fuso()))->format('Y-m-d');
    $validade = $dia2 >= $hoje ? 3 * 3600 : 24 * 3600;
    $resposta = fn(?array $g, ?string $erro) => ['ok' => $g !== null, 'erro' => $erro,
        'linhas' => $g ? (json_decode($g['dados'], true) ?: []) : [], 'buscado_em' => $g['buscado_em'] ?? null];
    if ($guardado && time() - strtotime($guardado['buscado_em'] . ' UTC') < $validade) {
        return $resposta($guardado, null);
    }
    $k = meta_api_chave();
    if (!$k) {
        return $resposta($guardado, 'Conecte a conta de anúncios na aba API Meta para ver quem compra por sexo e idade.');
    }
    $r = meta_listar('/' . $id . '/insights', [
        'breakdowns' => 'age,gender',
        'time_range' => json_encode(['since' => $dia1, 'until' => min($dia2, $hoje)]),
        'fields' => 'spend,actions',
        'limit' => '200',
    ], $k['token']);
    if (!$r['ok']) {
        return $resposta($guardado, 'A Meta não respondeu agora: ' . ($r['erro'] ?? 'tente de novo mais tarde') . '.');
    }
    $linhas = [];
    foreach ($r['itens'] as $l) {
        $compras = 0;
        foreach ((array)($l['actions'] ?? []) as $a) {
            if (in_array($a['action_type'] ?? '', META_COMPRA, true)) {
                $compras = max($compras, (int)($a['value'] ?? 0)); // a Meta repete o numero em mais de um tipo
            }
        }
        $sexo = isset(META_SEXOS[$l['gender'] ?? '']) ? $l['gender'] : 'unknown';
        $linhas[] = [texto($l['age'] ?? '', 12) ?: '?', $sexo, $compras, meta_centavos($l['spend'] ?? 0)];
    }
    $agora = agora_utc();
    $db->prepare('INSERT OR REPLACE INTO meta_publico (chave, dados, buscado_em) VALUES (?, ?, ?)')->execute([$chave, json_encode($linhas), $agora]);
    // Limpeza: as consultas de mais de 7 dias nao servem mais
    $db->prepare('DELETE FROM meta_publico WHERE buscado_em < ?')->execute([gmdate('Y-m-d H:i:s', time() - 7 * 86400)]);
    return ['ok' => true, 'erro' => null, 'linhas' => $linhas, 'buscado_em' => $agora];
}
