<?php
// Analise diaria de uma campanha (o botao "Analise diaria" que aparece ao passar o mouse na
// campanha do gestor): a campanha dia a dia, como a aba CAMPANHAS da planilha (uma linha por
// dia), com as mesmas colunas e as mesmas contas do gestor. A ultima linha e hoje, ao vivo: o
// que ja entrou ate a ultima busca na Meta e na Kiwify.
//
// O orcamento de cada dia vem de meta_orcamento_dia, que o painel grava a cada busca na Meta (a
// Meta so informa o orcamento de agora). Dia de antes do painel guardar fica sem orcamento.
// Campanha com orcamento nos conjuntos (sem orcamento proprio): a soma dos conjuntos no dia.

// Primeiro dia com gasto ou venda da campanha (para "Tudo"), ou null
function gestor_dias_inicio(PDO $db, string $id): ?string
{
    $dia = consulta($db, 'SELECT MIN(dia) AS d FROM meta_gasto WHERE campanha_id = ?', [$id])[0]['d'] ?? null;
    $tz = fuso();
    foreach (consulta($db, "SELECT recebida_em, utm_campaign FROM vendas WHERE utm_campaign LIKE ? ORDER BY recebida_em LIMIT 50", ['%|' . $id]) as $v) {
        if (gestor_id_utm($v['utm_campaign']) === $id) {
            $d = (new DateTime($v['recebida_em'], new DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m-d');
            $dia = $dia === null || $d < $dia ? $d : $dia;
            break;
        }
    }
    return $dia;
}

// Orcamento de cada dia: [dia => ['orcamento_diario' => centavos, 'orcamento_total' => centavos]]
function gestor_dias_orcamentos(PDO $db, array $obj, array $conjuntos, string $dia1, string $dia2): array
{
    $orc = [];
    foreach (consulta($db, 'SELECT * FROM meta_orcamento_dia WHERE objeto_id = ? AND dia >= ? AND dia <= ?', [$obj['id'], $dia1, $dia2]) as $o) {
        $orc[$o['dia']] = ['orcamento_diario' => $o['orcamento_diario'], 'orcamento_total' => $o['orcamento_total']];
    }
    // Sem orcamento na campanha: soma o dos conjuntos naquele dia
    if (!$orc && $conjuntos) {
        $marcas = implode(', ', array_fill(0, count($conjuntos), '?'));
        foreach (consulta($db, "SELECT dia, SUM(orcamento_diario) AS d, SUM(orcamento_total) AS t FROM meta_orcamento_dia
                                WHERE objeto_id IN ($marcas) AND dia >= ? AND dia <= ? GROUP BY dia", array_merge($conjuntos, [$dia1, $dia2])) as $o) {
            $orc[$o['dia']] = ['orcamento_diario' => (int)$o['d'] ?: null, 'orcamento_total' => (int)$o['t'] ?: null];
        }
    }
    return $orc;
}

// Linhas por dia da campanha: [dia => linha do gestor]. Gasto da Meta por dia; vendas pelo
// dia em que chegaram (horario de Brasilia), com o filtro de produto do topo.
function gestor_dias_linhas(PDO $db, string $id, string $dia1, string $dia2): array
{
    $linhas = [];
    for ($d = new DateTime($dia1); $d->format('Y-m-d') <= $dia2; $d->modify('+1 day')) {
        $linhas[$d->format('Y-m-d')] = gestor_linha_nova();
    }
    foreach (consulta($db, 'SELECT dia, SUM(gasto) AS gasto, SUM(checkouts) AS checkouts, SUM(cliques) AS cliques, SUM(impressoes) AS impressoes, SUM(visualizacoes) AS visualizacoes
                            FROM meta_gasto WHERE campanha_id = ? AND dia >= ? AND dia <= ? GROUP BY dia', [$id, $dia1, $dia2]) as $g) {
        foreach (['gasto', 'checkouts', 'cliques', 'impressoes', 'visualizacoes'] as $c) {
            $linhas[$g['dia']][$c] = (int)$g[$c];
        }
    }
    $tz = fuso();
    $utc = new DateTimeZone('UTC');
    $de = (new DateTime($dia1, $tz))->setTimezone($utc)->format('Y-m-d H:i:s');
    $ate = (new DateTime($dia2, $tz))->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
    foreach (consulta($db, 'SELECT * FROM vendas WHERE recebida_em >= ? AND recebida_em < ? AND utm_campaign LIKE ?', [$de, $ate, '%|' . $id]) as $v) {
        if (gestor_id_utm($v['utm_campaign']) !== $id || !venda_no_filtro($v)) {
            continue;
        }
        $dia = (new DateTime($v['recebida_em'], $utc))->setTimezone($tz)->format('Y-m-d');
        if (isset($linhas[$dia])) {
            gestor_somar_venda($linhas[$dia], $v);
        }
    }
    return $linhas;
}

function gestor_dias_render(PDO $db, string $periodo): void
{
    $id = is_string($_GET['id'] ?? null) && preg_match('/^\d{3,25}$/', $_GET['id']) ? $_GET['id'] : '';
    $st = $db->prepare("SELECT * FROM meta_objetos WHERE id = ? AND nivel = 'campaign'");
    $st->execute([$id]);
    $obj = $id !== '' ? ($st->fetch(PDO::FETCH_ASSOC) ?: null) : null;
    $voltar = './?' . http_build_query(['aba' => 'gestor', 'periodo' => $periodo]);
    $inicio = $id !== '' ? gestor_dias_inicio($db, $id) : null;
    if (!$obj && $inicio === null) {
        echo '<p><a href="' . e($voltar) . '">← Gestor de anúncios</a></p><p>Campanha não encontrada entre as da conta de anúncios. Atualize o gestor e tente de novo.</p>';
        return;
    }
    $nome = $obj['nome'] ?? $id;
    $pct = gestor_imposto_pct();
    $tz = fuso();
    $hoje = (new DateTime('today', $tz))->format('Y-m-d');

    // Dias do periodo do topo; em "Tudo", do primeiro dia da campanha ate hoje (no maximo 1 ano)
    [$dia1, $dia2] = gestor_dias($periodo);
    if ($periodo === 'tudo') {
        $dia1 = $inicio ?? $hoje;
        $dia2 = $hoje;
    }
    $dia2 = min($dia2, $hoje);
    $limite = (new DateTime($dia2))->modify('-365 days')->format('Y-m-d');
    $dia1 = max($dia1, $limite);

    $conjuntos = array_column(consulta($db, "SELECT id FROM meta_objetos WHERE nivel = 'adset' AND campanha_id = ?", [$id]), 'id');
    $orcamentos = $obj ? gestor_dias_orcamentos($db, $obj, $conjuntos, $dia1, $dia2) : [];
    $linhas = $dia1 <= $dia2 ? gestor_dias_linhas($db, $id, $dia1, $dia2) : [];
    $todas = gestor_colunas($pct);
    $colunas = gestor_colunas_escolhidas($todas);

    // Cabecalho: voltar, nome, status (a chave liga e pausa), orcamento de agora, atualizar
    $volta = './?' . http_build_query(['aba' => 'campanha', 'id' => $id, 'periodo' => $periodo]);
    if ($aviso = aviso_pegar()) {
        echo '<p class="' . ($aviso[1] === 'erro' ? 'erro' : 'aviso-ok') . '">' . e($aviso[0]) . '</p>';
    }
    $meta = meta_sync_estado();
    $vencida = meta_sync_vencida() || kiwify_sync_vencida();
    echo '<p class="trilha"><a href="' . e($voltar) . '">Gestor de anúncios</a> <span class="suave">›</span> <span>Análise diária</span></p>'
        . '<section class="bloco campanha-cab"><div class="campanha-topo"><div class="campanha-nome">'
        . ($obj ? gestor_chave($obj, $volta, gestor_pode_editar()) : '')
        . '<h2>' . e($nome) . '</h2>'
        . ($obj ? '<span class="suave campanha-orc">' . str_replace('<br>', ' ', gestor_orcamento($obj)) . '</span>' : '')
        . '</div><div class="barra-vendas" id="sync"' . ($vencida ? ' data-sync="1"' : '') . '><span data-sync-texto>'
        . e($meta['ok_em'] ? 'Gasto da Meta atualizado em ' . data_local($meta['ok_em'], 'd/m H:i') : 'Gasto da Meta ainda não buscado') . '</span>'
        . botao_atualizar($volta, 'Atualizar agora: busca o gasto na Meta e as vendas na Kiwify', 'meta') . '</div></div>'
        . '<p class="suave">' . e(($dia1 === $dia2 ? (new DateTime($dia1))->format('d/m/Y') : (new DateTime($dia1))->format('d/m') . ' a ' . (new DateTime($dia2))->format('d/m/Y')))
        . ' · um dia por linha, como na planilha de campanhas. Muda o período no topo. '
        . '<a href="' . e('./?' . http_build_query(['aba' => 'gestor', 'periodo' => $periodo, 'nivel' => 'conjuntos', 'campanha' => $id])) . '">Ver os conjuntos</a></p></section>';

    // Tabela: total do periodo em cima, os dias em ordem e hoje (ao vivo) por ultimo
    echo '<div class="tabela gestor dias"><table data-larguras="dias"><tr><th class="nome dia">' . com_info('Dia', 'Dia da semana e data (horário de Brasília). O gasto da Meta é do dia inteiro; as vendas, do dia em que chegaram.') . '</th>';
    foreach ($colunas as $k) {
        echo '<th data-col="' . e($k) . '">' . com_info($todas[$k][0], $todas[$k][1]) . '</th>';
    }
    echo '</tr>';
    $total = gestor_linha_nova();
    foreach ($linhas as $l) {
        foreach ($total as $c => $v) {
            if (is_int($v)) {
                $total[$c] += $l[$c];
            }
        }
    }
    $t = gestor_metricas(['id' => $id, 'obj' => null] + $total, $pct);
    echo '<tr class="total"><td class="nome dia">Período <span class="suave">(' . count($linhas) . ' dia' . (count($linhas) === 1 ? '' : 's') . ')</span></td>';
    foreach ($colunas as $k) {
        echo '<td>' . ($k === 'orcamento' || $k === 'id' ? '' : $todas[$k][2]($t)) . '</td>';
    }
    echo '</tr>';
    $semana = ['', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb', 'dom'];
    foreach ($linhas as $dia => $l) {
        $d = new DateTime($dia);
        $aoVivo = $dia === $hoje;
        $orc = $orcamentos[$dia] ?? ($aoVivo ? $obj : null);
        $r = gestor_metricas(['id' => $id, 'obj' => $orc] + $l, $pct);
        echo '<tr' . ($aoVivo ? ' class="ao-vivo-linha"' : '') . '><td class="nome dia"><span class="suave">' . $semana[(int)$d->format('N')] . '</span> ' . e($d->format('d/m'))
            . ($aoVivo ? ' <span class="ao-vivo" title="O dia ainda não acabou: os números mudam a cada busca na Meta e na Kiwify">ao vivo</span>' : '') . '</td>';
        foreach ($colunas as $k) {
            echo '<td>' . $todas[$k][2]($r) . '</td>';
        }
        echo '</tr>';
    }
    if (!$linhas) {
        echo '<tr><td colspan="' . (1 + count($colunas)) . '" class="suave">Nenhum dia no período.</td></tr>';
    }
    echo '</table></div>';
    echo '<p class="suave legenda">As colunas são as mesmas do gestor (escolha em Colunas, lá). ROI: vermelho abaixo de 1, laranja de 1 até 2, verde de 2 para cima. '
        . 'O orçamento de cada dia é o que o painel viu na Meta naquele dia; antes de o painel começar a guardar, fica em branco.</p>';
}
