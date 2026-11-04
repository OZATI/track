<?php
// Busca no GA4, para a conferencia de ga4.php: por dia e por endereco (host), quantas vezes
// cada evento aconteceu, as visualizacoes de cada pagina e as sessoes de cada origem.
//
// Uma vez por hora (cron.php e o painel aberto, em segundo plano) e no botao da tela. A primeira
// busca traz os ultimos GA4_SYNC_DIAS dias; as seguintes releem os ultimos GA4_SYNC_REVER (o
// GA4 leva ate 48 horas para fechar um dia). Os numeros ficam guardados (sem dado de pessoa:
// so contagens) e saem depois de GA4_GUARDAR_DIAS. Toda consulta passa por lib/ga4_api.php.

require_once __DIR__ . '/ga4_api.php';

const GA4_SYNC_INTERVALO = 3600;
const GA4_SYNC_DIAS = 28;
const GA4_SYNC_REVER = 3;
const GA4_GUARDAR_DIAS = 400;

// Os tres relatorios, com as colunas na ordem em que a Data API devolve
const GA4_RELATORIOS = [
    'eventos' => ['dims' => ['date', 'hostName', 'eventName'], 'mets' => ['eventCount', 'totalUsers', 'eventValue', 'keyEvents']],
    'paginas' => ['dims' => ['date', 'hostName', 'pagePath'], 'mets' => ['screenPageViews', 'totalUsers']],
    'origens' => ['dims' => ['date', 'hostName', 'sessionSource', 'sessionMedium', 'sessionCampaignName'], 'mets' => ['sessions', 'totalUsers', 'keyEvents']],
];

function ga4_sync_vencida(): bool
{
    return ga4_api_chave() !== null && time() - (int)(ajuste('ga4_sync_tentativa') ?? 0) >= GA4_SYNC_INTERVALO;
}

function ga4_sync_estado(): array
{
    return ['ok_em' => ajuste('ga4_sync_ok_em'), 'erro' => ajuste('ga4_sync_erro'), 'adiada' => ajuste('ga4_sync_adiada')];
}

// O que a ultima busca contou de cada propriedade: fuso, moeda, limiar, linha "(other)", cota
function ga4_info(string $propriedade): array
{
    $i = json_decode((string)ajuste('ga4_info:' . $propriedade), true);
    return is_array($i) ? $i : [];
}

// Dimensoes e metricas personalizadas registradas na propriedade (lidas uma vez por dia)
function ga4_personalizadas(string $propriedade): ?array
{
    $m = json_decode((string)ajuste('ga4_meta:' . $propriedade), true);
    return is_array($m) ? $m : null;
}

function ga4_sincronizar(): array
{
    $k = ga4_api_chave();
    if (!$k) {
        return ['ok' => false, 'erro' => 'Nenhuma conta de serviço do GA4 salva.'];
    }
    $db = track_db();
    $db->exec('BEGIN IMMEDIATE');
    if (time() - (int)(ajuste('ga4_sync_rodando') ?? 0) < 180) {
        $db->exec('COMMIT');
        return ['ok' => false, 'ocupado' => true, 'erro' => 'Já há uma busca no GA4 em andamento.'];
    }
    definir_ajuste('ga4_sync_rodando', (string)time());
    definir_ajuste('ga4_sync_tentativa', (string)time());
    $db->exec('COMMIT');

    try {
        $r = ga4_sync_buscar($k);
    } finally {
        definir_ajuste('ga4_sync_rodando', null);
    }
    if ($r['ok']) {
        definir_ajuste('ga4_sync_ok_em', $r['inicio']);
        definir_ajuste('ga4_sync_erro', null);
        definir_ajuste('ga4_sync_adiada', null);
    } elseif (!empty($r['adiada'])) {
        definir_ajuste('ga4_sync_adiada', $r['erro']);
    } else {
        definir_ajuste('ga4_sync_erro', $r['erro']);
        definir_ajuste('ga4_sync_adiada', null);
    }
    return $r;
}

// Um relatorio inteiro (de 10.000 em 10.000 linhas). Devolve ['ok', 'linhas', 'meta', 'cota'] ou
// ['ok' => false, 'status', 'corpo'].
function ga4_sync_relatorio(string $propriedade, array $def, string $de, string $ate, string $token): array
{
    $linhas = [];
    $meta = [];
    $cota = null;
    for ($pagina = 0, $offset = 0; $pagina < 10; $pagina++) {
        [$status, $corpo] = ga4_api_relatorio($propriedade, [
            'dateRanges' => [['startDate' => $de, 'endDate' => $ate]],
            'dimensions' => array_map(fn($n) => ['name' => $n], $def['dims']),
            'metrics' => array_map(fn($n) => ['name' => $n], $def['mets']),
            'limit' => 10000,
            'offset' => $offset,
        ], $token);
        if ($status !== 200 || !is_array($corpo)) {
            return ['ok' => false, 'status' => $status, 'corpo' => $corpo];
        }
        foreach ((array)($corpo['rows'] ?? []) as $l) {
            $linhas[] = [array_map(fn($v) => (string)($v['value'] ?? ''), (array)($l['dimensionValues'] ?? [])),
                array_map(fn($v) => (float)($v['value'] ?? 0), (array)($l['metricValues'] ?? []))];
        }
        $meta = $meta ?: (array)($corpo['metadata'] ?? []);
        $cota = $cota ?? ($corpo['propertyQuota'] ?? null);
        $offset += count((array)($corpo['rows'] ?? []));
        if (!($corpo['rows'] ?? null) || $offset >= (int)($corpo['rowCount'] ?? 0)) {
            break;
        }
    }
    return ['ok' => true, 'linhas' => $linhas, 'meta' => $meta, 'cota' => $cota];
}

// Endereco como o painel guarda o dominio: minusculas e sem o "www."
function ga4_host(string $h): string
{
    return preg_replace('/^www\./', '', strtolower(texto($h, 120))) ?? '';
}

function ga4_sync_buscar(array $k): array
{
    $inicio = agora_utc();
    $t = ga4_api_token_salvo($k);
    if (!$t['ok']) {
        return $t + ['inicio' => $inicio];
    }
    $token = $t['token'];
    $db = track_db();
    $hoje = new DateTime('today', fuso());
    $limite = (clone $hoje)->modify('-89 days');
    foreach ($k['propriedades'] as $p) {
        $id = (string)$p['id'];
        $ultimo = valor($db, 'SELECT MAX(dia) FROM ga4_eventos WHERE propriedade = ?', [$id]);
        $de = $ultimo ? (new DateTime((string)$ultimo, fuso()))->modify('-' . (GA4_SYNC_REVER - 1) . ' days') : (clone $hoje)->modify('-' . (GA4_SYNC_DIAS - 1) . ' days');
        $de = max($de, $limite)->format('Y-m-d');
        $ate = $hoje->format('Y-m-d');

        $dados = [];
        $meta = [];
        $cota = null;
        foreach (GA4_RELATORIOS as $nome => $def) {
            $r = ga4_sync_relatorio($id, $def, $de, $ate, $token);
            if (!$r['ok'] && $r['status'] === 401) { // token guardado vencido ou revogado: pede outro uma vez
                $t = ga4_api_token_salvo($k, true);
                if (!$t['ok']) {
                    return $t + ['inicio' => $inicio];
                }
                $token = $t['token'];
                $r = ga4_sync_relatorio($id, $def, $de, $ate, $token);
            }
            if (!$r['ok']) {
                return ['ok' => false, 'inicio' => $inicio, 'adiada' => in_array($r['status'], [GA4_ADIADA, 429], true),
                    'erro' => ($p['nome'] !== '' ? $p['nome'] . ': ' : '') . ga4_api_erro($r['status'], $r['corpo'], $id, $k['client_email'])];
            }
            $dados[$nome] = $r['linhas'];
            $meta = $meta ?: $r['meta'];
            $cota = $r['cota'] ?? $cota;
        }

        $dia = fn(string $d) => preg_match('/^(\d{4})(\d{2})(\d{2})$/', $d, $m) ? "$m[1]-$m[2]-$m[3]" : null;
        $db->exec('BEGIN IMMEDIATE');
        foreach (['ga4_eventos', 'ga4_paginas', 'ga4_origens'] as $tabela) {
            $db->prepare("DELETE FROM $tabela WHERE propriedade = ? AND dia >= ?")->execute([$id, $de]);
        }
        // Duas linhas do GA4 podem virar a mesma do painel (www., /index.html, barra no fim): somam
        $ev = $db->prepare('INSERT INTO ga4_eventos (propriedade, dia, host, evento, n, usuarios, valor, chave) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT (propriedade, dia, host, evento) DO UPDATE SET n = n + excluded.n, usuarios = usuarios + excluded.usuarios,
            valor = valor + excluded.valor, chave = chave + excluded.chave');
        foreach ($dados['eventos'] as [$d, $m]) {
            if ($dd = $dia($d[0])) {
                $ev->execute([$id, $dd, ga4_host($d[1]), texto($d[2], 60), (int)$m[0], (int)$m[1], round($m[2], 2), (int)$m[3]]);
            }
        }
        $pg = $db->prepare('INSERT INTO ga4_paginas (propriedade, dia, host, pagina, visualizacoes, usuarios) VALUES (?, ?, ?, ?, ?, ?)
            ON CONFLICT (propriedade, dia, host, pagina) DO UPDATE SET visualizacoes = visualizacoes + excluded.visualizacoes, usuarios = usuarios + excluded.usuarios');
        foreach ($dados['paginas'] as [$d, $m]) {
            if ($dd = $dia($d[0])) {
                $pg->execute([$id, $dd, ga4_host($d[1]), pagina_normal(texto($d[2], 300)), (int)$m[0], (int)$m[1]]);
            }
        }
        $og = $db->prepare('INSERT INTO ga4_origens (propriedade, dia, host, origem, midia, campanha, sessoes, usuarios, chave) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT (propriedade, dia, host, origem, midia, campanha) DO UPDATE SET sessoes = sessoes + excluded.sessoes,
            usuarios = usuarios + excluded.usuarios, chave = chave + excluded.chave');
        foreach ($dados['origens'] as [$d, $m]) {
            if ($dd = $dia($d[0])) {
                $og->execute([$id, $dd, ga4_host($d[1]), strtolower(texto($d[2], 120)), strtolower(texto($d[3], 160)), texto($d[4], 160), (int)$m[0], (int)$m[1], (int)$m[2]]);
            }
        }
        $db->exec('COMMIT');

        // O que o proprio Google diz sobre os numeros (metadata da resposta)
        definir_ajuste('ga4_info:' . $id, json_encode([
            'fuso' => texto($meta['timeZone'] ?? '', 60),
            'moeda' => texto($meta['currencyCode'] ?? '', 8),
            'limiar' => !empty($meta['subjectToThresholding']),
            'outros' => !empty($meta['dataLossFromOtherRow']),
            'amostra' => !empty($meta['samplingMetadatas']),
            'cota_dia' => isset($cota['tokensPerDay']) ? ['gasto' => (int)($cota['tokensPerDay']['consumed'] ?? 0), 'resta' => (int)($cota['tokensPerDay']['remaining'] ?? 0)] : null,
            'de' => $de,
            'em' => $inicio,
        ]));

        // Dimensoes e metricas personalizadas: uma vez por dia (mudam pouco)
        $pers = ga4_personalizadas($id);
        if (!$pers || strtotime(($pers['em'] ?? '') . ' UTC') < time() - 86400) {
            [$status, $corpo] = ga4_api_metadados($id, $token);
            if ($status === 200 && is_array($corpo)) {
                $lista = [];
                foreach (['dimensions' => 'dimensão', 'metrics' => 'métrica'] as $campo => $tipo) {
                    foreach ((array)($corpo[$campo] ?? []) as $d) {
                        if (!empty($d['customDefinition'])) {
                            $lista[] = ['api' => texto($d['apiName'] ?? '', 80), 'nome' => texto($d['uiName'] ?? '', 80), 'tipo' => $tipo];
                        }
                    }
                }
                definir_ajuste('ga4_meta:' . $id, json_encode(['em' => $inicio, 'lista' => $lista]));
            }
        }
    }

    // Propriedade que saiu da lista e numero velho saem do banco
    $ids = array_map(fn($p) => (string)$p['id'], $k['propriedades']);
    $marcas = implode(',', array_fill(0, count($ids), '?'));
    $velho = (clone $hoje)->modify('-' . GA4_GUARDAR_DIAS . ' days')->format('Y-m-d');
    foreach (['ga4_eventos', 'ga4_paginas', 'ga4_origens'] as $tabela) {
        $db->prepare("DELETE FROM $tabela WHERE propriedade NOT IN ($marcas) OR dia < ?")->execute([...$ids, $velho]);
    }
    return ['ok' => true, 'inicio' => $inicio];
}

// Desconectar: apaga os numeros e o que foi guardado da conexao
function ga4_apagar_dados(): void
{
    $db = track_db();
    foreach (['ga4_eventos', 'ga4_paginas', 'ga4_origens'] as $tabela) {
        $db->exec("DELETE FROM $tabela");
    }
    $db->exec("DELETE FROM ajustes WHERE chave LIKE 'ga4\\_%' ESCAPE '\\'");
}
