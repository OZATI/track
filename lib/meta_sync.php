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
const META_HIST_JANELA = 89;  // dias por consulta, como na Kiwify
const META_HIST_MESES = 37;   // a Meta guarda os numeros dos anuncios por 37 meses
const META_HIST_VAZIAS = 4;   // janelas seguidas sem gasto (quase um ano) = chegou no comeco da conta

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

// Gasto de $de a $ate (inclusive, no fuso da conta): por anuncio e por dia (com impressoes,
// cliques, checkouts e video) e por hora do dia. Troca o que ja havia nesses dias. $nomes (o
// historico): pede tambem os nomes, para guardar o que ja saiu da lista da Meta.
function meta_sync_gasto(string $conta, string $token, DateTime $de, DateTime $ate, bool $nomes = false): array
{
    $db = track_db();
    $r = meta_listar($conta . '/insights', [
        'level' => 'ad',
        'time_increment' => '1',
        'time_range' => json_encode(['since' => $de->format('Y-m-d'), 'until' => $ate->format('Y-m-d')]),
        'fields' => 'ad_id,adset_id,campaign_id,spend,impressions,inline_link_clicks,actions,video_play_actions,video_thruplay_watched_actions,'
            . 'video_p25_watched_actions,video_p50_watched_actions,video_p75_watched_actions,video_p100_watched_actions'
            . ($nomes ? ',ad_name,adset_name,campaign_name' : ''),
        'limit' => '500',
    ], $token);
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
    // Historico: campanha, conjunto e anuncio que ja sairam da lista da Meta entram com o nome que
    // veio no gasto (os que ja estao guardados ficam como estao)
    $novoObj = $db->prepare('INSERT OR IGNORE INTO meta_objetos (id, nivel, nome, status, status_efetivo, campanha_id, conjunto_id, atualizado_em)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
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
        if ($nomes) {
            $camp = meta_so_numeros($l['campaign_id'] ?? '');
            $conj = meta_so_numeros($l['adset_id'] ?? '');
            foreach ([['campaign', $camp, $l['campaign_name'] ?? '', $camp, null], ['adset', $conj, $l['adset_name'] ?? '', $camp, $conj], ['ad', $ad, $l['ad_name'] ?? '', $camp, $conj]] as [$nivel, $id, $nome, $c, $s]) {
                if ($id && texto($nome, 300) !== '') {
                    $novoObj->execute([$id, $nivel, texto($nome, 300), 'ARCHIVED', 'ARCHIVED', $c, $s, agora_utc()]);
                }
            }
        }
    }
    $db->commit();
    $anuncios = count($r['itens']);

    // 3. Gasto por hora do dia (grafico acumulado do Resumo). Se a conta nao liberar esse
    //    detalhe, o resto da busca continua valendo.
    $r = meta_listar($conta . '/insights', [
        'level' => 'account',
        'time_increment' => '1',
        'time_range' => json_encode(['since' => $de->format('Y-m-d'), 'until' => $ate->format('Y-m-d')]),
        'breakdowns' => 'hourly_stats_aggregated_by_advertiser_time_zone',
        'fields' => 'spend',
        'limit' => '500',
    ], $token);
    $horas = 0;
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
        $horas = count($r['itens']);
    }
    return ['ok' => true, 'anuncios' => $anuncios, 'horas' => $horas];
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

    // 2 e 3. Gasto por anuncio e por dia e gasto por hora (datas no fuso da conta de anuncios)
    $ate = new DateTime('today', fuso());
    $de = (clone $ate)->modify($completa ? '-' . (META_HIST_JANELA - 1) . ' days' : '-2 days');
    $r = meta_sync_gasto($conta, $k['token'], $de, $ate);
    if (!$r['ok']) {
        return $r;
    }
    $linhas = $r['horas'];

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

// ---------------------------------------------------------------- historico
// A busca normal le os ultimos 89 dias. O historico volta no tempo, uma janela de 89 dias por
// passo, ate o comeco da conta (META_HIST_VAZIAS janelas seguidas sem gasto) ou ate onde a Meta
// guarda (META_HIST_MESES). Grava o gasto por anuncio, por dia e por hora, como a busca normal, e o
// nome do que ja saiu da lista da Meta. Roda em segundo plano (sincronizar.php e cron.php), com a
// mesma trava da busca normal. Estado nos ajustes, como o da Kiwify (lib/kiwify_sync.php):
//   meta_hist_ate (ate onde, para tras, ja buscou; as janelas terminam no dia anterior),
//   meta_hist_vazias, meta_hist_fim e meta_hist_erro
function meta_historico_pendente(): bool
{
    return meta_api_chave() !== null && !ajuste('meta_hist_fim');
}

function meta_historico_estado(): array
{
    $primeiro = track_db()->query('SELECT MIN(dia) FROM meta_gasto WHERE gasto > 0')->fetchColumn();
    return ['ate' => ajuste('meta_hist_ate'), 'fim' => ajuste('meta_hist_fim'), 'erro' => ajuste('meta_hist_erro'),
        'primeiro' => $primeiro ? (string)$primeiro : null];
}

// Um passo: uma janela. ['ok', 'dias', 'fim'] ou o erro.
function meta_historico_passo(): array
{
    $k = meta_api_chave();
    if (!$k || ajuste('meta_hist_fim')) {
        return ['ok' => true, 'dias' => 0, 'fim' => true];
    }
    $db = track_db();
    $db->exec('BEGIN IMMEDIATE');
    if (time() - (int)(ajuste('meta_sync_rodando') ?? 0) < 180) {
        $db->exec('COMMIT');
        return ['ok' => false, 'ocupado' => true, 'erro' => 'Já há uma busca na Meta em andamento.'];
    }
    definir_ajuste('meta_sync_rodando', (string)time());
    $db->exec('COMMIT');
    try {
        // A primeira janela termina no dia antes do inicio da busca normal (hoje - 88)
        $ate = new DateTime((ajuste('meta_hist_ate') ?: (new DateTime('today', fuso()))->modify('-' . (META_HIST_JANELA - 1) . ' days')->format('Y-m-d')), fuso());
        $ultimo = (clone $ate)->modify('-1 day');
        // A Meta recusa periodo que comeca a mais de 37 meses de hoje: um dia de folga
        $piso = (new DateTime('today', fuso()))->modify('-' . META_HIST_MESES . ' months')->modify('+1 day');
        if ($ultimo < $piso) {
            definir_ajuste('meta_hist_fim', agora_utc());
            return ['ok' => true, 'dias' => 0, 'fim' => true];
        }
        $primeiro = max($piso, (clone $ultimo)->modify('-' . (META_HIST_JANELA - 1) . ' days'));
        $r = meta_sync_gasto('/act_' . $k['conta'], $k['token'], $primeiro, $ultimo, true);
        if (!$r['ok']) {
            if (empty($r['adiada'])) {
                definir_ajuste('meta_hist_erro', $r['erro']);
            }
            return $r;
        }
        definir_ajuste('meta_hist_erro', null);
        $st = $db->prepare('SELECT COUNT(*) FROM meta_gasto WHERE dia >= ? AND dia <= ? AND gasto > 0');
        $st->execute([$primeiro->format('Y-m-d'), $ultimo->format('Y-m-d')]);
        $vazias = (int)$st->fetchColumn() ? 0 : (int)(ajuste('meta_hist_vazias') ?? 0) + 1;
        definir_ajuste('meta_hist_vazias', (string)$vazias);
        definir_ajuste('meta_hist_ate', $primeiro->format('Y-m-d'));
        $fim = $vazias >= META_HIST_VAZIAS || $primeiro <= $piso;
        if ($fim) {
            definir_ajuste('meta_hist_fim', agora_utc());
        }
        return ['ok' => true, 'dias' => $r['anuncios'], 'fim' => $fim];
    } finally {
        definir_ajuste('meta_sync_rodando', null);
    }
}

function meta_historico_texto(): string
{
    $h = meta_historico_estado();
    $data = fn(?string $d) => $d ? (new DateTime(substr($d, 0, 10)))->format('d/m/Y') : '';
    if ($h['fim']) {
        return 'Histórico completo' . ($h['primeiro'] ? ': gasto desde ' . $data($h['primeiro']) : '');
    }
    return $h['ate'] ? 'Buscando o histórico de gasto: já foi até ' . $data($h['ate']) : 'Histórico de gasto: começa na próxima busca';
}
