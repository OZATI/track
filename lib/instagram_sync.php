<?php
// Busca no Instagram, para o bloco "Perfil do Instagram" da aba Organico: seguidores, os
// numeros da conta por dia (alcance, visualizacoes, interacoes, toques nos links do perfil,
// quem seguiu e quem deixou de seguir) e cada post ou reel com os insights dele.
//
// Uma vez por hora com o painel aberto, em segundo plano, e no botao Atualizar. Os dias
// ainda sem numero e os 3 ultimos (o Instagram leva ate 48 horas para fechar um dia) sao
// buscados de novo; os posts dos ultimos 14 dias, a cada hora, e os mais velhos, uma vez
// por dia. Se o limite interno de consultas acabar no meio, o que veio fica gravado e a
// proxima busca continua em 2 minutos. Toda consulta passa por lib/instagram_api.php.

require_once __DIR__ . '/instagram_api.php';

const IG_SYNC_INTERVALO = 3600;
const IG_SYNC_DIAS = 28;
const IG_METRICAS_CONTA = ['reach', 'views', 'accounts_engaged', 'total_interactions', 'profile_links_taps'];
const IG_COLUNAS_CONTA = ['reach' => 'alcance', 'views' => 'visualizacoes', 'accounts_engaged' => 'contas_engajadas',
    'total_interactions' => 'interacoes', 'profile_links_taps' => 'toques_links'];

function ig_sync_vencida(): bool
{
    if (!ig_api_chave()) {
        return false;
    }
    $intervalo = ajuste('ig_sync_pendente') ? 120 : IG_SYNC_INTERVALO;
    return time() - (int)(ajuste('ig_sync_tentativa') ?? 0) >= $intervalo;
}

function ig_sync_estado(): array
{
    return ['ok_em' => ajuste('ig_sync_ok_em'), 'erro' => ajuste('ig_sync_erro'), 'adiada' => ajuste('ig_sync_adiada'),
        'insights_erro' => ajuste('ig_insights_erro'), 'pendente' => ajuste('ig_sync_pendente')];
}

// Perfil gravado na ultima busca: usuario, nome, seguidores, seguindo, posts
function ig_perfil(): ?array
{
    $p = json_decode((string)ajuste('ig_perfil'), true);
    return is_array($p) ? $p : null;
}

function ig_sincronizar(): array
{
    $k = ig_api_chave();
    if (!$k) {
        return ['ok' => false, 'erro' => 'Nenhum token do Instagram salvo.'];
    }
    $db = track_db();
    $db->exec('BEGIN IMMEDIATE');
    if (time() - (int)(ajuste('ig_sync_rodando') ?? 0) < 180) {
        $db->exec('COMMIT');
        return ['ok' => false, 'ocupado' => true, 'erro' => 'Já há uma busca no Instagram em andamento.'];
    }
    definir_ajuste('ig_sync_rodando', (string)time());
    definir_ajuste('ig_sync_tentativa', (string)time());
    $db->exec('COMMIT');

    try {
        $r = ig_sync_buscar($k);
    } finally {
        definir_ajuste('ig_sync_rodando', null);
    }
    if ($r['ok']) {
        definir_ajuste('ig_sync_ok_em', $r['inicio']);
        definir_ajuste('ig_sync_erro', null);
        definir_ajuste('ig_sync_adiada', null);
        definir_ajuste('ig_sync_pendente', !empty($r['pendente']) ? '1' : null);
    } elseif (!empty($r['adiada'])) {
        definir_ajuste('ig_sync_adiada', $r['erro']);
    } else {
        definir_ajuste('ig_sync_erro', $r['erro']);
        definir_ajuste('ig_sync_adiada', null);
    }
    return $r;
}

// Inicio e fim de um dia de Brasilia, em segundos (o fim nunca passa de agora)
function ig_limites_dia(string $dia): array
{
    $ini = new DateTime($dia . ' 00:00:00', fuso());
    $fim = (clone $ini)->modify('+1 day');
    return [$ini->getTimestamp(), min($fim->getTimestamp(), time())];
}

// "2026-09-29T12:00:00+0000" -> "2026-09-29 12:00:00" (UTC)
function ig_data_utc($v): ?string
{
    $t = is_string($v) ? strtotime($v) : false;
    return $t ? gmdate('Y-m-d H:i:s', $t) : null;
}

function ig_sync_buscar(array $k): array
{
    $inicio = agora_utc();
    $ctx = ig_ctx(ig_api_renovar($k));
    $db = track_db();

    // 1. Perfil: seguidores de hoje ficam gravados no dia (a curva de seguidores sai daqui)
    [$st, $c] = ig_api_get($ctx, $ctx['conta'], ['fields' => ig_campos_perfil($ctx['modo'])]);
    if ($st !== 200 || empty($c['username'])) {
        $m = ig_api_mensagem($c);
        return ['ok' => false, 'adiada' => $st === IG_ADIADA,
            'erro' => $st === 0 ? 'Sem conexão com a API do Instagram.' : 'O Instagram recusou a consulta do perfil' . ($m ? ': ' . $m : '') . '. Se o token foi revogado, gere outro' . ($ctx['modo'] === 'facebook' ? ' e cole na aba API Meta.' : ' na aba API Instagram.')];
    }
    $seguidores = (int)($c['followers_count'] ?? 0);
    definir_ajuste('ig_perfil', json_encode([
        'usuario' => texto($c['username'], 60), 'nome' => texto($c['name'] ?? '', 120), 'tipo' => texto($c['account_type'] ?? '', 30),
        'seguidores' => $seguidores, 'seguindo' => (int)($c['follows_count'] ?? 0), 'posts' => (int)($c['media_count'] ?? 0),
    ], JSON_UNESCAPED_UNICODE));
    $hoje = (new DateTime('today', fuso()))->format('Y-m-d');
    $db->prepare('INSERT INTO ig_dia (dia, seguidores) VALUES (?, ?) ON CONFLICT (dia) DO UPDATE SET seguidores = excluded.seguidores')
        ->execute([$hoje, $seguidores]);

    // 2. Posts e reels (os 50 mais recentes): aparecem ja na primeira busca
    [$st, $c] = ig_api_get($ctx, $ctx['conta'] . '/media', ['fields' => 'id,caption,media_type,media_product_type,permalink,timestamp,like_count,comments_count', 'limit' => '50']);
    if ($st === IG_ADIADA) {
        return ['ok' => true, 'inicio' => $inicio, 'dias' => 0, 'posts' => 0, 'pendente' => true];
    }
    if ($st !== 200) {
        $m = ig_api_mensagem($c);
        return ['ok' => false, 'erro' => 'O Instagram recusou a lista de posts' . ($m ? ': ' . $m : '') . '.'];
    }
    $gravar = $db->prepare('INSERT INTO ig_media (id, tipo, produto, legenda, link, publicado_em, curtidas, comentarios, atualizado_em)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT (id) DO UPDATE SET tipo = excluded.tipo, produto = excluded.produto, legenda = excluded.legenda, link = excluded.link,
            publicado_em = excluded.publicado_em, curtidas = excluded.curtidas, comentarios = excluded.comentarios, atualizado_em = excluded.atualizado_em');
    $posts = 0;
    foreach ((array)($c['data'] ?? []) as $p) {
        if (!is_array($p)) {
            continue;
        }
        $id = preg_replace('/\D/', '', (string)($p['id'] ?? ''));
        $link = (string)($p['permalink'] ?? '');
        if ($id === '') {
            continue;
        }
        $gravar->execute([$id, texto($p['media_type'] ?? '', 20), texto($p['media_product_type'] ?? '', 20), texto($p['caption'] ?? '', 300),
            preg_match('~^https://(www\.)?instagram\.com/~', $link) ? texto($link, 200) : null, ig_data_utc($p['timestamp'] ?? null),
            (int)($p['like_count'] ?? 0), (int)($p['comments_count'] ?? 0), agora_utc()]);
        $posts++;
    }

    // 3. Numeros da conta por dia, depois os insights de cada post
    $pendente = false;
    $dias = 0;
    $semInsights = ajuste('ig_insights_erro') && time() - (int)(ajuste('ig_insights_erro_em') ?? 0) < 86400;
    if (!$semInsights) {
        [$dias, $pendente, $erro] = ig_sync_dias($ctx, $hoje, $seguidores);
        if ($erro !== null) {
            definir_ajuste('ig_insights_erro', $erro);
            definir_ajuste('ig_insights_erro_em', (string)time());
            $semInsights = true;
        } elseif ($dias) {
            definir_ajuste('ig_insights_erro', null);
        }
    }
    if (!$semInsights && !$pendente) {
        $pendente = ig_sync_posts($ctx);
    }
    return ['ok' => true, 'inicio' => $inicio, 'dias' => $dias, 'posts' => $posts, 'pendente' => $pendente];
}

// Metricas da conta que o Instagram aceita para esta conta (algumas ficam de fora em conta
// pequena ou mudam de nome). Descoberto uma vez e guardado.
function ig_metricas_conta(): array
{
    $m = json_decode((string)ajuste('ig_metricas'), true);
    return is_array($m) && $m ? array_values(array_intersect(IG_METRICAS_CONTA, $m)) : IG_METRICAS_CONTA;
}

// Devolve [dias gravados, parou no limite?, erro de insights ou null]
function ig_sync_dias(array $ctx, string $hoje, int $seguidores): array
{
    $db = track_db();
    $ja = [];
    foreach ($db->query('SELECT dia, buscado_em FROM ig_dia WHERE buscado_em IS NOT NULL') as $l) {
        $ja[$l['dia']] = $l['buscado_em'];
    }
    $gravar = $db->prepare('INSERT INTO ig_dia (dia, alcance, visualizacoes, contas_engajadas, interacoes, toques_links, seguiram, deixaram, buscado_em)
        VALUES (:dia, :alcance, :visualizacoes, :contas_engajadas, :interacoes, :toques_links, :seguiram, :deixaram, :buscado_em)
        ON CONFLICT (dia) DO UPDATE SET alcance = excluded.alcance, visualizacoes = excluded.visualizacoes, contas_engajadas = excluded.contas_engajadas,
            interacoes = excluded.interacoes, toques_links = excluded.toques_links, seguiram = COALESCE(excluded.seguiram, ig_dia.seguiram),
            deixaram = COALESCE(excluded.deixaram, ig_dia.deixaram), buscado_em = excluded.buscado_em');
    // Quem seguiu e quem deixou de seguir: o Instagram so informa para 100 seguidores ou mais
    $seguirFora = $seguidores < 100 || time() - (int)(ajuste('ig_seguir_fora_em') ?? 0) < 86400;
    $feitos = 0;
    for ($i = 0; $i < IG_SYNC_DIAS; $i++) {
        $dia = (new DateTime($hoje, fuso()))->modify('-' . $i . ' days')->format('Y-m-d');
        // Dia ja buscado depois de fechado (2 dias depois) nao muda mais
        $fechado = (new DateTime($dia, fuso()))->modify('+3 days')->format('Y-m-d');
        if (isset($ja[$dia]) && $i > 2 && data_local($ja[$dia], 'Y-m-d') >= $fechado) {
            continue;
        }
        [$de, $ate] = ig_limites_dia($dia);
        $janela = ['period' => 'day', 'metric_type' => 'total_value', 'since' => (string)$de, 'until' => (string)$ate];

        $metricas = ig_metricas_conta();
        [$st, $c] = ig_api_get($ctx, $ctx['conta'] . '/insights', ['metric' => implode(',', $metricas)] + $janela);
        if ($st === IG_ADIADA) {
            return [$feitos, true, null];
        }
        if ($st !== 200) {
            // Descobre uma a uma quais metricas esta conta aceita
            $ok = [];
            $motivo = ig_api_mensagem($c);
            foreach (IG_METRICAS_CONTA as $m) {
                [$st1, $c1] = ig_api_get($ctx, $ctx['conta'] . '/insights', ['metric' => $m] + $janela);
                if ($st1 === IG_ADIADA) {
                    return [$feitos, true, null];
                }
                if ($st1 === 200) {
                    $ok[] = $m;
                }
            }
            if (!$ok) {
                return [$feitos, false, 'O Instagram não liberou os insights' . ($motivo ? ': ' . $motivo : '') . '. O token precisa da permissão "'
                    . ($ctx['modo'] === 'facebook' ? 'instagram_manage_insights' : 'instagram_business_manage_insights') . '".'];
            }
            definir_ajuste('ig_metricas', json_encode($ok));
            [$st, $c] = ig_api_get($ctx, $ctx['conta'] . '/insights', ['metric' => implode(',', $ok)] + $janela);
            if ($st !== 200) {
                return [$feitos, $st === IG_ADIADA, null];
            }
        }
        $linha = [':dia' => $dia, ':alcance' => null, ':visualizacoes' => null, ':contas_engajadas' => null, ':interacoes' => null,
            ':toques_links' => null, ':seguiram' => null, ':deixaram' => null, ':buscado_em' => agora_utc()];
        foreach ((array)($c['data'] ?? []) as $item) {
            $col = IG_COLUNAS_CONTA[$item['name'] ?? ''] ?? null;
            if ($col && is_array($item)) {
                $linha[':' . $col] = ig_valor($item);
            }
        }

        if (!$seguirFora) {
            [$st, $c] = ig_api_get($ctx, $ctx['conta'] . '/insights', ['metric' => 'follows_and_unfollows', 'breakdown' => 'follow_type'] + $janela);
            if ($st === IG_ADIADA) {
                $gravar->execute($linha);
                return [$feitos + 1, true, null];
            }
            if ($st === 200) {
                foreach ((array)($c['data'][0]['total_value']['breakdowns'][0]['results'] ?? []) as $res) {
                    $tipo = $res['dimension_values'][0] ?? '';
                    if ($tipo === 'FOLLOWER') {
                        $linha[':seguiram'] = (int)($res['value'] ?? 0);
                    } elseif ($tipo === 'NON_FOLLOWER') {
                        $linha[':deixaram'] = (int)($res['value'] ?? 0);
                    }
                }
                $linha[':seguiram'] = $linha[':seguiram'] ?? 0;
                $linha[':deixaram'] = $linha[':deixaram'] ?? 0;
            } else {
                definir_ajuste('ig_seguir_fora_em', (string)time());
                $seguirFora = true;
            }
        }
        $gravar->execute($linha);
        $feitos++;
    }
    return [$feitos, false, null];
}

// Insights dos posts: novos e dos ultimos 14 dias a cada hora, os mais velhos (ate 90 dias)
// uma vez por dia. Devolve true se parou no limite de consultas.
function ig_sync_posts(array $ctx): bool
{
    $db = track_db();
    $agora = time();
    $posts = consulta_ig($db, 'SELECT id, produto, tipo FROM ig_media
        WHERE publicado_em >= ? AND (insights_em IS NULL OR (publicado_em >= ? AND insights_em < ?) OR insights_em < ?)
        ORDER BY publicado_em DESC LIMIT 40', [
        gmdate('Y-m-d H:i:s', $agora - 90 * 86400), gmdate('Y-m-d H:i:s', $agora - 14 * 86400),
        gmdate('Y-m-d H:i:s', $agora - 3600), gmdate('Y-m-d H:i:s', $agora - 86400),
    ]);
    $gravar = $db->prepare('UPDATE ig_media SET alcance = ?, visualizacoes = ?, salvos = ?, compartilhamentos = ?, interacoes = ?,
        visitas_perfil = ?, seguiram = ?, tempo_medio_ms = ?, insights_em = ? WHERE id = ?');
    foreach ($posts as $p) {
        $reels = $p['produto'] === 'REELS';
        $metricas = $reels ? ['reach', 'views', 'saved', 'shares', 'total_interactions', 'ig_reels_avg_watch_time']
            : ['reach', 'views', 'saved', 'shares', 'total_interactions', 'profile_visits', 'follows'];
        [$st, $c] = ig_api_get($ctx, '/' . $p['id'] . '/insights', ['metric' => implode(',', $metricas)]);
        if ($st !== 200 && $st !== IG_ADIADA) {
            // Post antigo ou de um tipo que nao aceita alguma metrica: tenta o basico
            [$st, $c] = ig_api_get($ctx, '/' . $p['id'] . '/insights', ['metric' => 'reach,saved,shares,total_interactions']);
        }
        if ($st === IG_ADIADA) {
            return true;
        }
        $v = [];
        foreach ($st === 200 ? (array)($c['data'] ?? []) : [] as $item) {
            if (is_array($item) && is_string($item['name'] ?? null)) {
                $v[$item['name']] = ig_valor($item);
            }
        }
        $gravar->execute([$v['reach'] ?? null, $v['views'] ?? null, $v['saved'] ?? null, $v['shares'] ?? null, $v['total_interactions'] ?? null,
            $v['profile_visits'] ?? null, $v['follows'] ?? null, $v['ig_reels_avg_watch_time'] ?? null, agora_utc(), $p['id']]);
    }
    return false;
}

function consulta_ig(PDO $db, string $sql, array $par): array
{
    $st = $db->prepare($sql);
    $st->execute($par);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
