<?php
// Busca na Meta, para o gestor de anuncios: campanhas, conjuntos e anuncios (nome, status e
// orcamento) e o gasto de cada anuncio por dia (com impressoes, cliques e inicios de checkout).
//
// Primeira busca e uma vez por dia: ultimos 89 dias. Nas outras, so os ultimos 3 dias, que
// ainda mudam. Com o painel aberto, a cada 15 minutos, em segundo plano, e no botao
// Atualizar. Toda consulta passa pela protecao contra bloqueio de lib/meta_api.php.

require_once __DIR__ . '/meta_api.php';

const META_SYNC_INTERVALO = 900;
const META_SYNC_PAGINAS = 20;

function meta_sync_vencida(): bool
{
    if (!meta_api_chave()) {
        return false;
    }
    return time() - (int)(ajuste('meta_sync_tentativa') ?? 0) >= META_SYNC_INTERVALO;
}

function meta_sync_estado(): array
{
    return ['ok_em' => ajuste('meta_sync_ok_em'), 'erro' => ajuste('meta_sync_erro'), 'adiada' => ajuste('meta_sync_adiada')];
}

function meta_sincronizar(bool $completa = false): array
{
    $k = meta_api_chave();
    if (!$k) {
        return ['ok' => false, 'erro' => 'Nenhum token da Meta salvo.'];
    }
    $db = track_db();
    $db->exec('BEGIN IMMEDIATE');
    if (time() - (int)(ajuste('meta_sync_rodando') ?? 0) < 180) {
        $db->exec('COMMIT');
        return ['ok' => false, 'ocupado' => true, 'erro' => 'Já há uma busca na Meta em andamento.'];
    }
    definir_ajuste('meta_sync_rodando', (string)time());
    definir_ajuste('meta_sync_tentativa', (string)time());
    $db->exec('COMMIT');

    try {
        $r = meta_sync_buscar($k, $completa);
    } finally {
        definir_ajuste('meta_sync_rodando', null);
    }
    if ($r['ok']) {
        definir_ajuste('meta_sync_ok_em', $r['inicio']);
        if ($r['completa']) {
            definir_ajuste('meta_sync_completa_em', (string)time());
        }
        definir_ajuste('meta_sync_erro', null);
        definir_ajuste('meta_sync_adiada', null);
    } elseif (!empty($r['adiada'])) {
        definir_ajuste('meta_sync_adiada', $r['erro']);
    } else {
        definir_ajuste('meta_sync_erro', $r['erro']);
        definir_ajuste('meta_sync_adiada', null);
    }
    return $r;
}

// Todas as paginas de uma lista da Graph API, pelo cursor "after"
function meta_listar(string $rota, array $consulta, string $token): array
{
    $itens = [];
    $depois = null;
    for ($i = 0; $i < META_SYNC_PAGINAS; $i++) {
        [$st, $c] = meta_api_get($rota, $consulta + ($depois ? ['after' => $depois] : []), $token);
        if ($st !== 200) {
            $m = meta_api_mensagem($c);
            return ['ok' => false, 'adiada' => $st === META_ADIADA,
                'erro' => $st === 0 ? 'Sem conexão com a API da Meta.' : ($m ?: 'A Meta respondeu HTTP ' . $st . '.')];
        }
        foreach ((array)($c['data'] ?? []) as $x) {
            if (is_array($x)) {
                $itens[] = $x;
            }
        }
        $depois = $c['paging']['cursors']['after'] ?? null;
        if (!$depois || empty($c['paging']['next'])) {
            break;
        }
    }
    return ['ok' => true, 'itens' => $itens];
}

function meta_so_numeros($v): ?string
{
    $n = preg_replace('/\D/', '', (string)$v);
    return $n === '' ? null : $n;
}

function meta_sync_buscar(array $k, bool $completa): array
{
    $inicio = agora_utc();
    $completa = $completa || !ajuste('meta_sync_ok_em') || time() - (int)(ajuste('meta_sync_completa_em') ?? 0) > 86400;
    $conta = '/act_' . $k['conta'];
    $db = track_db();

    // 1. Campanhas, conjuntos e anuncios: nome, status e orcamento (a Meta manda em centavos)
    $niveis = [
        'campaign' => ['/campaigns', 'id,name,status,effective_status,daily_budget,lifetime_budget'],
        'adset' => ['/adsets', 'id,name,status,effective_status,daily_budget,lifetime_budget,campaign_id'],
        'ad' => ['/ads', 'id,name,status,effective_status,adset_id,campaign_id'],
    ];
    $gravar = $db->prepare('INSERT INTO meta_objetos (id, nivel, nome, status, status_efetivo, campanha_id, conjunto_id, orcamento_diario, orcamento_total, atualizado_em)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT (id) DO UPDATE SET nivel = excluded.nivel, nome = excluded.nome, status = excluded.status,
            status_efetivo = excluded.status_efetivo, campanha_id = excluded.campanha_id, conjunto_id = excluded.conjunto_id,
            orcamento_diario = excluded.orcamento_diario, orcamento_total = excluded.orcamento_total, atualizado_em = excluded.atualizado_em');
    // Orcamento de hoje de cada campanha e conjunto: o dia a dia do gestor mostra o de cada dia
    $hoje = (new DateTime('today', fuso()))->format('Y-m-d');
    $gravarOrc = $db->prepare('INSERT OR REPLACE INTO meta_orcamento_dia (dia, objeto_id, orcamento_diario, orcamento_total) VALUES (?, ?, ?, ?)');
    $objetos = 0;
    foreach ($niveis as $nivel => [$rota, $campos]) {
        $r = meta_listar($conta . $rota, ['fields' => $campos, 'limit' => '200'], $k['token']);
        if (!$r['ok']) {
            return $r;
        }
        foreach ($r['itens'] as $o) {
            $id = meta_so_numeros($o['id'] ?? '');
            if (!$id) {
                continue;
            }
            $orcDia = (int)($o['daily_budget'] ?? 0);
            $orcTotal = (int)($o['lifetime_budget'] ?? 0);
            $gravar->execute([$id, $nivel, texto($o['name'] ?? '', 300), texto($o['status'] ?? '', 30), texto($o['effective_status'] ?? '', 40),
                $nivel === 'campaign' ? $id : meta_so_numeros($o['campaign_id'] ?? ''),
                $nivel === 'adset' ? $id : ($nivel === 'ad' ? meta_so_numeros($o['adset_id'] ?? '') : null),
                $orcDia ?: null, $orcTotal ?: null, agora_utc()]);
            if ($orcDia || $orcTotal) {
                $gravarOrc->execute([$hoje, $id, $orcDia ?: null, $orcTotal ?: null]);
            }
            $objetos++;
        }
    }

    // 2. Gasto por anuncio e por dia (datas no fuso da conta de anuncios)
    $ate = new DateTime('today', fuso());
    $de = (clone $ate)->modify($completa ? '-88 days' : '-2 days');
    $r = meta_listar($conta . '/insights', [
        'level' => 'ad',
        'time_increment' => '1',
        'time_range' => json_encode(['since' => $de->format('Y-m-d'), 'until' => $ate->format('Y-m-d')]),
        'fields' => 'ad_id,adset_id,campaign_id,spend,impressions,inline_link_clicks,actions,video_play_actions,video_thruplay_watched_actions,'
            . 'video_p25_watched_actions,video_p50_watched_actions,video_p75_watched_actions,video_p100_watched_actions',
        'limit' => '500',
    ], $k['token']);
    if (!$r['ok']) {
        return $r;
    }
    $db->beginTransaction();
    $db->prepare('DELETE FROM meta_gasto WHERE dia >= ? AND dia <= ?')->execute([$de->format('Y-m-d'), $ate->format('Y-m-d')]);
    $ins = $db->prepare('INSERT OR REPLACE INTO meta_gasto (dia, anuncio_id, conjunto_id, campanha_id, gasto, impressoes, cliques, checkouts, visualizacoes,
                             plays, video_3s, thruplay, p25, p50, p75, p100)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    // Video: cada campo vem como uma lista de acoes (video_view); a Meta pode repetir o numero
    $video = function ($lista): int {
        $n = 0;
        foreach ((array)$lista as $a) {
            $n = max($n, (int)($a['value'] ?? 0));
        }
        return $n;
    };
    foreach ($r['itens'] as $l) {
        $ad = meta_so_numeros($l['ad_id'] ?? '');
        $dia = (string)($l['date_start'] ?? '');
        if (!$ad || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia)) {
            continue;
        }
        // Inicio de checkout: a Meta pode mandar o mesmo numero em mais de um tipo de acao.
        // video_view nas acoes: as visualizacoes de 3 segundos.
        $checkouts = $visualizacoes = $tres = 0;
        foreach ((array)($l['actions'] ?? []) as $a) {
            $tipo = $a['action_type'] ?? '';
            if (in_array($tipo, ['offsite_conversion.fb_pixel_initiate_checkout', 'initiate_checkout', 'omni_initiated_checkout'], true)) {
                $checkouts = max($checkouts, (int)($a['value'] ?? 0));
            } elseif (in_array($tipo, ['landing_page_view', 'omni_landing_page_view'], true)) {
                $visualizacoes = max($visualizacoes, (int)($a['value'] ?? 0));
            } elseif ($tipo === 'video_view') {
                $tres = max($tres, (int)($a['value'] ?? 0));
            }
        }
        $ins->execute([$dia, $ad, meta_so_numeros($l['adset_id'] ?? ''), meta_so_numeros($l['campaign_id'] ?? ''),
            meta_centavos($l['spend'] ?? 0), (int)($l['impressions'] ?? 0), (int)($l['inline_link_clicks'] ?? 0), $checkouts, $visualizacoes,
            $video($l['video_play_actions'] ?? []), $tres, $video($l['video_thruplay_watched_actions'] ?? []), $video($l['video_p25_watched_actions'] ?? []),
            $video($l['video_p50_watched_actions'] ?? []), $video($l['video_p75_watched_actions'] ?? []), $video($l['video_p100_watched_actions'] ?? [])]);
    }
    $db->commit();

    // 3. Gasto por hora do dia (grafico acumulado do Resumo). Se a conta nao liberar esse
    //    detalhe, o resto da busca continua valendo.
    $r = meta_listar($conta . '/insights', [
        'level' => 'account',
        'time_increment' => '1',
        'time_range' => json_encode(['since' => $de->format('Y-m-d'), 'until' => $ate->format('Y-m-d')]),
        'breakdowns' => 'hourly_stats_aggregated_by_advertiser_time_zone',
        'fields' => 'spend',
        'limit' => '500',
    ], $k['token']);
    if ($r['ok']) {
        $db->beginTransaction();
        $db->prepare('DELETE FROM meta_gasto_hora WHERE dia >= ? AND dia <= ?')->execute([$de->format('Y-m-d'), $ate->format('Y-m-d')]);
        $insH = $db->prepare('INSERT OR REPLACE INTO meta_gasto_hora (dia, hora, gasto) VALUES (?, ?, ?)');
        foreach ($r['itens'] as $l) {
            $dia = (string)($l['date_start'] ?? '');
            $faixa = (string)($l['hourly_stats_aggregated_by_advertiser_time_zone'] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia) && preg_match('/^(\d{2}):/', $faixa, $m)) {
                $insH->execute([$dia, (int)$m[1], meta_centavos($l['spend'] ?? 0)]);
            }
        }
        $db->commit();
    }
    $linhas = count($r['itens']);

    // 4. Alcance por dia de cada conjunto e campanha (analise diaria do publico). O alcance nao
    //    soma de um dia para o outro (a mesma pessoa conta em cada dia), entao vem por dia. Se a
    //    conta nao liberar, o resto da busca continua valendo.
    $insA = $db->prepare('INSERT OR REPLACE INTO meta_alcance (dia, objeto_id, alcance, impressoes) VALUES (?, ?, ?, ?)');
    foreach (['adset' => 'adset_id', 'campaign' => 'campaign_id'] as $nivelA => $campoA) {
        $rA = meta_listar($conta . '/insights', [
            'level' => $nivelA,
            'time_increment' => '1',
            'time_range' => json_encode(['since' => $de->format('Y-m-d'), 'until' => $ate->format('Y-m-d')]),
            'fields' => $campoA . ',reach,impressions',
            'limit' => '500',
        ], $k['token']);
        if (!$rA['ok']) {
            continue;
        }
        $db->beginTransaction();
        foreach ($rA['itens'] as $l) {
            $idA = meta_so_numeros($l[$campoA] ?? '');
            $dia = (string)($l['date_start'] ?? '');
            if ($idA && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia)) {
                $insA->execute([$dia, $idA, (int)($l['reach'] ?? 0), (int)($l['impressions'] ?? 0)]);
            }
        }
        $db->commit();
    }
    return ['ok' => true, 'inicio' => $inicio, 'completa' => $completa, 'objetos' => $objetos, 'linhas' => $linhas];
}
