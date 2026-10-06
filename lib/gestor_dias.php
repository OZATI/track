<?php
// Analise diaria de uma campanha, de um conjunto (publico) ou de um anuncio (criativo): o botao
// do grafico que aparece ao passar o mouse na linha do gestor. O objeto dia a dia, como a aba
// CAMPANHAS da planilha (uma linha por dia), com as mesmas contas do gestor. De cima para baixo: o cabecalho (chave, nome, orcamento com o lapis,
// atualizar e colunas), a tabela, os numeros e graficos, e a programacao do orcamento.
// As colunas mudam como no gestor (modelos Campanha, Conjunto e Criativo) e ficam guardadas por
// nivel; o padrao sao as 17 da planilha, na ordem dela, justas para caber na tela sem rolar para
// o lado (pedido do Allan). Clicar no titulo ordena (painel.js). A linha de hoje e ao vivo: o que
// ja entrou ate a ultima busca na Meta e na Kiwify.
//
// O orcamento de cada dia vem de meta_orcamento_dia, que o painel grava a cada busca na Meta (a
// Meta so informa o orcamento de agora). Dia de antes do painel guardar fica sem orcamento.
// Campanha com orcamento nos conjuntos (sem orcamento proprio): a soma dos conjuntos no dia.

require_once __DIR__ . '/gestor_analise.php';
require_once __DIR__ . '/orcamento.php';

// Colunas da aba CAMPANHAS da planilha, na ordem dela: chave do gestor => titulo curto
const GESTOR_DIAS_COLUNAS = ['orcamento' => 'Orçamento', 'gasto' => 'Gastos', 'vendas' => 'Vendas', 'fat' => 'Faturamento', 'lucro' => 'Lucro',
    'cpa' => 'CPA', 'roi' => 'ROI', 'cpi' => 'CPI', 'ic' => 'IC', 'cpv' => 'CPV', 'cpc' => 'CPC', 'cliques' => 'Cliques', 'ctr' => 'CTR',
    'impressoes' => 'Impressões', 'cpm' => 'CPM', 'visualizacoes' => 'Vis. de página', 'margem' => 'Margem'];
// Titulos curtos das outras colunas, para a tabela caber na tela
const GESTOR_DIAS_CURTOS = ['hook' => 'Hook', 'hold' => 'Hold', 'video_3s' => 'Vis. 3 s', 'ret25' => 'Ret. 25%', 'ret50' => 'Ret. 50%', 'ret75' => 'Ret. 75%',
    'ret100' => 'Ret. 100%', 'alcance' => 'Alcance', 'frequencia' => 'Freq.', 'fat_bruto' => 'Fat. bruto', 'taxas' => 'Taxas', 'reemb_fat' => 'Fat. reemb.',
    'reemb' => 'Reemb.', 'recusadas' => 'Recusadas', 'pend' => 'Pix pend.', 'imposto' => 'Imposto'];

// Niveis da analise (?nivel= no endereco; campanha sem nada): [nivel na Meta, coluna do
// meta_gasto, etiqueta da venda com o ID, nome, artigo, modelo de colunas padrao]
const GESTOR_DIAS_NIVEIS = [
    'campanha' => ['campaign', 'campanha_id', 'utm_campaign', 'campanha', 'a', 'campanha'],
    'conjunto' => ['adset', 'conjunto_id', 'utm_medium', 'conjunto', 'o', 'conjunto'],
    'anuncio' => ['ad', 'anuncio_id', 'utm_content', 'anúncio', 'o', 'criativo'],
];

// Nivel do endereco (campanha, conjunto ou anuncio)
function gestor_dias_nivel(): string
{
    $n = $_GET['nivel'] ?? '';
    return is_string($n) && isset(GESTOR_DIAS_NIVEIS[$n]) ? $n : 'campanha';
}

// Primeiro dia com gasto ou venda do objeto (para "Tudo"), ou null
function gestor_dias_inicio(PDO $db, string $id, string $nivel = 'campanha'): ?string
{
    [, $col, $utm] = GESTOR_DIAS_NIVEIS[$nivel];
    $dia = consulta($db, "SELECT MIN(dia) AS d FROM meta_gasto WHERE $col = ?", [$id])[0]['d'] ?? null;
    $tz = fuso();
    foreach (consulta($db, "SELECT recebida_em, $utm AS u FROM vendas WHERE $utm LIKE ? ORDER BY recebida_em LIMIT 50", ['%|' . $id]) as $v) {
        if (gestor_id_utm($v['u']) === $id) {
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

// Linhas por dia do objeto: [dia => linha do gestor]. Gasto e video da Meta por dia, alcance do
// dia (conjunto e campanha); vendas pelo dia em que chegaram (horario de Brasilia), pelo ID na
// etiqueta do nivel, com o filtro de produto do topo.
function gestor_dias_linhas(PDO $db, string $id, string $dia1, string $dia2, string $nivel = 'campanha'): array
{
    [, $col, $utm] = GESTOR_DIAS_NIVEIS[$nivel];
    $linhas = [];
    for ($d = new DateTime($dia1); $d->format('Y-m-d') <= $dia2; $d->modify('+1 day')) {
        $linhas[$d->format('Y-m-d')] = gestor_linha_nova();
    }
    $somas = array_merge(['gasto', 'checkouts', 'cliques', 'impressoes', 'visualizacoes'], GESTOR_VIDEO);
    $sql = 'SELECT dia, ' . implode(', ', array_map(fn($c) => "SUM($c) AS $c", $somas)) . " FROM meta_gasto WHERE $col = ? AND dia >= ? AND dia <= ? GROUP BY dia";
    foreach (consulta($db, $sql, [$id, $dia1, $dia2]) as $g) {
        foreach ($somas as $c) {
            $linhas[$g['dia']][$c] = (int)$g[$c];
        }
    }
    if ($nivel !== 'anuncio') {
        foreach (consulta($db, 'SELECT dia, alcance FROM meta_alcance WHERE objeto_id = ? AND dia >= ? AND dia <= ?', [$id, $dia1, $dia2]) as $a) {
            if (isset($linhas[$a['dia']])) {
                $linhas[$a['dia']]['alcance'] = (int)$a['alcance'];
            }
        }
    }
    $tz = fuso();
    $utc = new DateTimeZone('UTC');
    $de = (new DateTime($dia1, $tz))->setTimezone($utc)->format('Y-m-d H:i:s');
    $ate = (new DateTime($dia2, $tz))->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
    foreach (consulta($db, "SELECT * FROM vendas WHERE recebida_em >= ? AND recebida_em < ? AND $utm LIKE ?", [$de, $ate, '%|' . $id]) as $v) {
        if (gestor_id_utm($v[$utm]) !== $id || !venda_no_filtro($v)) {
            continue;
        }
        $dia = (new DateTime($v['recebida_em'], $utc))->setTimezone($tz)->format('Y-m-d');
        if (isset($linhas[$dia])) {
            gestor_somar_venda($linhas[$dia], $v);
        }
    }
    return $linhas;
}

// Colunas da analise: as do gestor (menos o ID, que fica no cabecalho) e, em campanha e conjunto,
// alcance e frequencia, que so existem por dia (no periodo, o alcance vem da Meta)
function gestor_dias_colunas(float $pct, string $nivel): array
{
    $todas = gestor_colunas($pct);
    unset($todas['id']);
    $num = fn(float $v, int $c = 2) => number_format($v, $c, ',', '.');
    if ($nivel !== 'anuncio') {
        $todas['alcance'] = ['Alcance', 'Pessoas diferentes que viram os anúncios no dia (a Meta conta cada pessoa uma vez por dia). No período, a mesma pessoa aparece em vários dias: a linha do período traz o alcance do período inteiro, da Meta.',
            fn($r) => $r['alcance'] ? number_format((int)$r['alcance'], 0, ',', '.') : '<span class="suave">—</span>', false];
        $todas['frequencia'] = ['Frequência', 'Impressões ÷ alcance: quantas vezes, em média, cada pessoa viu os anúncios. Acima de 3, o público está cansando: hora de renovar o criativo ou abrir o público.',
            fn($r) => $r['frequencia'] === null ? '<span class="suave">—</span>' : '<span class="' . ($r['frequencia'] > 3 ? 'medio' : '') . '">' . $num($r['frequencia']) . '</span>', false];
    }
    // Orcamento numa linha so (o "Diario" vai na dica): a tabela tem que caber na tela
    $todas['orcamento'][2] = function (array $r): string {
        $o = $r['obj'];
        if (!$o || (!$o['orcamento_diario'] && !$o['orcamento_total'])) {
            return '<span class="suave">—</span>';
        }
        return $o['orcamento_diario'] ? e(reais((int)$o['orcamento_diario']))
            : e(reais((int)$o['orcamento_total'])) . ' <small class="suave">total</small>';
    };
    return $todas;
}

function gestor_dias_render(PDO $db, string $periodo): void
{
    $nivel = gestor_dias_nivel();
    [$nivelMeta, , , $nome1, $art, $modelo] = GESTOR_DIAS_NIVEIS[$nivel];
    $id = is_string($_GET['id'] ?? null) && preg_match('/^\d{3,25}$/', $_GET['id']) ? $_GET['id'] : '';
    $st = $db->prepare('SELECT * FROM meta_objetos WHERE id = ? AND nivel = ?');
    $st->execute([$id, $nivelMeta]);
    $obj = $id !== '' ? ($st->fetch(PDO::FETCH_ASSOC) ?: null) : null;
    $voltar = './?' . http_build_query(['aba' => 'gestor', 'periodo' => $periodo]);
    $inicio = $id !== '' ? gestor_dias_inicio($db, $id, $nivel) : null;
    if (!$obj && $inicio === null) {
        echo '<p><a href="' . e($voltar) . '">← Gestor de anúncios</a></p><p>' . e(ucfirst($nome1)) . ' não encontrad' . $art . ' entre ' . ($art === 'a' ? 'as' : 'os') . ' da conta de anúncios. Atualize o gestor e tente de novo.</p>';
        return;
    }
    $nome = $obj['nome'] ?? $id;
    $pct = gestor_imposto_pct();
    $tz = fuso();
    $hoje = (new DateTime('today', $tz))->format('Y-m-d');

    // Dias do periodo do topo; em "Tudo", do primeiro dia ate hoje (no maximo 1 ano)
    [$dia1, $dia2] = gestor_dias($periodo);
    if ($periodo === 'tudo') {
        $dia1 = $inicio ?? $hoje;
        $dia2 = $hoje;
    }
    $dia2 = min($dia2, $hoje);
    // Antes do primeiro dia nao ha o que mostrar (eram linhas zeradas)
    if ($inicio !== null && $dia1 < $inicio) {
        $dia1 = min($inicio, $dia2);
    }
    $limite = (new DateTime($dia2))->modify('-365 days')->format('Y-m-d');
    $dia1 = max($dia1, $limite);

    // Orcamento de cada dia: a campanha (ou a soma dos conjuntos dela) e o conjunto; o anuncio nao tem
    $conjuntos = $nivel === 'campanha' ? array_column(consulta($db, "SELECT id FROM meta_objetos WHERE nivel = 'adset' AND campanha_id = ?", [$id]), 'id') : [];
    $orcamentos = $obj && $nivel !== 'anuncio' ? gestor_dias_orcamentos($db, $obj, $conjuntos, $dia1, $dia2) : [];
    $linhas = $dia1 <= $dia2 ? gestor_dias_linhas($db, $id, $dia1, $dia2, $nivel) : [];
    $alcance = $nivel !== 'anuncio' && $linhas ? meta_alcance_periodo($id, $dia1, $dia2) : null;

    // Colunas: escolhidas como no gestor, guardadas por nivel; o padrao e o modelo do nivel
    $todas = gestor_dias_colunas($pct, $nivel);
    $base = array_filter(['aba' => 'campanha', 'nivel' => $nivel === 'campanha' ? null : $nivel, 'id' => $id, 'periodo' => $periodo]);
    $colunas = gestor_colunas_escolhidas($todas, 'dias_colunas_' . $nivelMeta, GESTOR_MODELOS[$modelo][1]);
    $curto = GESTOR_DIAS_COLUNAS + GESTOR_DIAS_CURTOS;

    // Trilha: o gestor e, acima do conjunto ou do anuncio, a campanha e o conjunto (com a analise deles)
    $volta = './?' . http_build_query($base);
    if ($aviso = aviso_pegar()) {
        echo '<p class="' . ($aviso[1] === 'erro' ? 'erro' : 'aviso-ok') . '">' . e($aviso[0]) . '</p>';
    }
    $pais = '';
    foreach ([['campanha', $obj['campanha_id'] ?? null], ['conjunto', $nivel === 'anuncio' ? ($obj['conjunto_id'] ?? null) : null]] as [$nPai, $idPai]) {
        if ($nivel === $nPai || !$idPai) {
            continue;
        }
        $nomePai = consulta($db, 'SELECT nome FROM meta_objetos WHERE id = ?', [$idPai])[0]['nome'] ?? $idPai;
        $pais .= ' <span class="suave">›</span> <a href="' . e('./?' . http_build_query(array_filter(['aba' => 'campanha', 'nivel' => $nPai === 'campanha' ? null : $nPai, 'id' => $idPai, 'periodo' => $periodo]))) . '">' . e($nomePai) . '</a>';
    }
    $meta = meta_sync_estado();
    $vencida = meta_sync_vencida() || kiwify_sync_vencida();
    // Abaixo do nome: o proximo nivel no gestor (conjuntos da campanha, anuncios do conjunto)
    $proximo = ['campanha' => ['Ver os conjuntos', ['nivel' => 'conjuntos', 'campanha' => $id]],
        'conjunto' => ['Ver os anúncios', ['nivel' => 'anuncios', 'campanha' => $obj['campanha_id'] ?? '', 'conjunto' => $id]],
        'anuncio' => ['Ver no gestor', ['nivel' => 'anuncios', 'campanha' => $obj['campanha_id'] ?? '', 'conjunto' => $obj['conjunto_id'] ?? '']]][$nivel];
    echo '<p class="trilha"><a href="' . e($voltar) . '">Gestor de anúncios</a>' . $pais . ' <span class="suave">›</span> <span>Análise diária' . ($nivel === 'campanha' ? '' : ' do ' . ($nivel === 'conjunto' ? 'conjunto' : 'anúncio')) . '</span></p>'
        . '<section class="bloco campanha-cab"><div class="campanha-topo"><div class="campanha-nome">'
        . ($obj ? gestor_chave($obj, $volta, gestor_pode_editar()) : '')
        . ($nivel === 'campanha' ? '' : '<span class="selo neutro">' . e($nivel === 'conjunto' ? 'Público' : 'Criativo') . '</span>')
        . '<h2>' . e($nome) . '</h2>'
        . ($obj ? orc_inline($obj, $volta) : '')
        . '</div><div class="barra-vendas" id="sync"' . ($vencida ? ' data-sync="1"' : '') . '><span data-sync-texto>'
        . e($meta['ok_em'] ? 'Gasto da Meta atualizado em ' . data_local($meta['ok_em'], 'd/m H:i') : 'Gasto da Meta ainda não buscado') . '</span>'
        . botao_atualizar($volta, 'Atualizar agora: busca o gasto na Meta e as vendas na Kiwify', 'meta') . '</div></div>'
        . '<div class="campanha-linha"><span class="suave">' . e(($dia1 === $dia2 ? (new DateTime($dia1))->format('d/m/Y') : (new DateTime($dia1))->format('d/m') . ' a ' . (new DateTime($dia2))->format('d/m/Y'))) . '</span>'
        . '<span class="suave">ID <code>' . e($id) . '</code></span>'
        . '<a href="' . e('./?' . http_build_query(array_filter(['aba' => 'gestor', 'periodo' => $periodo] + $proximo[1]))) . '">' . e($proximo[0]) . '</a>'
        . gestor_colunas_seletor($todas, $colunas, 'Dia', $base, './?' . http_build_query($base + ['cols' => 'padrao']))
        . '</div></section>';

    // Tabela logo embaixo do cabecalho: o total do periodo em cima e os dias em ordem (hoje, ao
    // vivo, por ultimo). Cada celula leva o numero em data-v, para ordenar na tela.
    $valor = function (string $k, array $r): string {
        if ($k === 'orcamento') {
            $o = $r['obj'];
            $v = $o ? ((int)$o['orcamento_diario'] ?: (int)$o['orcamento_total']) : 0;
            return $v ? (string)$v : '';
        }
        $v = $r[['ic' => 'checkouts'][$k] ?? $k] ?? null;
        return is_int($v) || is_float($v) ? (string)round((float)$v, 4) : '';
    };
    echo '<div class="tabela gestor dias"><table data-ordenar="dias-' . e($nivelMeta) . '"><thead><tr><th class="nome dia" data-col="dia" data-tipo="data" data-inicial="asc"><button type="button" class="ordena">Data</button>'
        . info('Dia da semana e data (horário de Brasília). O gasto da Meta é do dia inteiro; as vendas, do dia em que chegaram. Clique no título de qualquer coluna para ordenar; de novo, inverte.') . '</th>';
    foreach ($colunas as $k) {
        // Sem o com_info (que gruda o (i) na palavra): aqui o (i) desce para a linha de baixo
        // quando falta espaco, e a coluna fica da largura do numero
        echo '<th data-col="' . e($k) . '" data-tipo="num"><button type="button" class="ordena">' . e($curto[$k] ?? $todas[$k][0]) . '</button>' . info($todas[$k][0] . ': ' . $todas[$k][1]) . '</th>';
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
    // Alcance do periodo: o da Meta (somar os dias contaria a mesma pessoa varias vezes)
    $total['alcance'] = $alcance['alcance'] ?? 0;
    $t = gestor_metricas(['id' => $id, 'obj' => null] + $total, $pct);
    echo '<tr class="total"><td class="nome dia">Período <span class="suave">(' . count($linhas) . ' dia' . (count($linhas) === 1 ? '' : 's') . ')</span></td>';
    foreach ($colunas as $k) {
        echo '<td>' . ($k === 'orcamento' ? '' : $todas[$k][2]($t)) . '</td>';
    }
    echo '</tr></thead><tbody>';
    foreach ($linhas as $dia => $l) {
        $d = new DateTime($dia);
        $aoVivo = $dia === $hoje;
        $orc = $orcamentos[$dia] ?? ($aoVivo && $nivel !== 'anuncio' ? $obj : null);
        $r = gestor_metricas(['id' => $id, 'obj' => $orc] + $l, $pct);
        echo '<tr' . ($aoVivo ? ' class="ao-vivo-linha"' : '') . '><td class="nome dia" data-v="' . e($dia) . '"><span class="suave">' . ANALISE_SEMANA[(int)$d->format('N')] . '</span> ' . e($d->format('d/m'))
            . ($aoVivo ? '<span class="ao-vivo" title="O dia ainda não acabou: os números mudam a cada busca na Meta e na Kiwify">ao vivo</span>' : '') . '</td>';
        foreach ($colunas as $k) {
            echo '<td data-v="' . $valor($k, $r) . '">' . $todas[$k][2]($r) . '</td>';
        }
        echo '</tr>';
    }
    if (!$linhas) {
        echo '<tr><td colspan="' . (1 + count($colunas)) . '" class="suave">Nenhum dia no período.</td></tr>';
    }
    echo '</tbody></table></div>';
    $legendaNivel = ['campanha' => 'Um dia por linha, como na planilha de campanhas', 'conjunto' => 'O conjunto (público) um dia por linha', 'anuncio' => 'O anúncio (criativo) um dia por linha'][$nivel];
    echo '<p class="suave legenda">' . e($legendaNivel) . '; muda o período no topo e as colunas no botão Colunas. Clique no título para ordenar (de novo, inverte). CPI = custo por início de checkout; IC = inícios de checkout; CPV = custo por visualização de página. ROI: vermelho abaixo de 1, laranja de 1 até 2, verde de 2 para cima. '
        . ($nivel === 'anuncio' ? 'Hook = visualizações de 3 s ÷ impressões; Hold = ThruPlay ÷ 3 s; retenção = quem chegou àquele ponto do vídeo ÷ quem deu play.'
            : 'O orçamento de cada dia é o que o painel viu na Meta naquele dia; antes de o painel começar a guardar, fica em branco.'
            . ($nivel === 'conjunto' ? ' Alcance e frequência do período vêm da Meta (a soma dos dias contaria a mesma pessoa mais de uma vez).' : '')) . '</p>';

    // Numeros, graficos, leitura e quem compra (lib/gestor_analise.php)
    gestor_analise_render($db, $id, $obj, $linhas, $pct, $dia1, $dia2, $nivel, $alcance);

    // Embaixo: programar o orcamento e o historico (lib/orcamento.php); o anuncio nao tem orcamento
    if ($nivel !== 'anuncio') {
        echo orc_bloco($id, $volta, $nivel);
    }
}
