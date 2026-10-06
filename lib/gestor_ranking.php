<?php
// Gestor: comparacao entre periodos e o ranking das campanhas (como um painel de bolsa).
//
// - Comparar com: periodo anterior do mesmo tamanho, mesmos dias da semana passada, do mes
//   passado ou sem comparacao. Vale para as setas da tabela e para o ranking.
// - Ranking: da melhor para a pior pelo criterio escolhido (lucro, ROI, vendas, faturamento
//   ou CPA), com a posicao, quantas posicoes subiu ou caiu contra a comparacao, a variacao
//   do valor e a curva do periodo (acumulado dia a dia).

const GESTOR_COMPARAR = [
    'anterior' => ['Período anterior', 'o período anterior do mesmo tamanho'],
    'semana' => ['Semana passada', 'os mesmos dias da semana passada'],
    'mes' => ['Mês passado', 'os mesmos dias do mês passado'],
    'nenhuma' => ['Sem comparação', ''],
];

// Criterio do ranking: [titulo, explicacao do (i), menor e melhor]
const GESTOR_RANK = [
    'lucro' => ['Lucro', 'Faturamento − gasto − imposto da Meta. O que sobrou de verdade.', false],
    'roi' => ['ROI', '(Faturamento − imposto) ÷ gasto, como na UTMify. Acima de 1, a campanha paga o que gasta; de 2 para cima, verde.', false],
    'vendas' => ['Vendas', 'Vendas aprovadas (sem order bump).', false],
    'fat' => ['Faturamento', 'Faturamento líquido da Kiwify, com order bump.', false],
    'cpa' => ['CPA', 'Custo por venda: gasto ÷ vendas. Aqui, menor é melhor.', true],
];

// Modos de comparacao que fazem sentido para o periodo. "Hoje" nao compara (o dia ainda esta
// pela metade e o gasto por anuncio vem por dia inteiro) e "Tudo" nao tem periodo anterior.
// "Semana passada" so ate 7 dias; mais que isso, os dias se sobrepoem.
function gestor_comparar_modos(string $periodo): array
{
    if (in_array($periodo, ['hoje', 'tudo'], true) || !periodo_valido($periodo)) {
        return [];
    }
    [$d1, $d2] = gestor_dias($periodo);
    $dias = (int)(new DateTime($d1))->diff(new DateTime($d2))->days + 1;
    return $dias > 7 ? ['anterior', 'mes', 'nenhuma'] : ['anterior', 'semana', 'mes', 'nenhuma'];
}

// Modo escolhido: o do endereco, o lembrado na sessao ou o periodo anterior
function gestor_comparar_modo(string $periodo): string
{
    $pedido = is_string($_GET['comparar'] ?? null) && isset(GESTOR_COMPARAR[$_GET['comparar']]) ? $_GET['comparar'] : null;
    if ($pedido !== null) {
        $_SESSION['gestor_comparar'] = $pedido;
    }
    $modo = $pedido ?? ($_SESSION['gestor_comparar'] ?? 'anterior');
    $validos = gestor_comparar_modos($periodo);
    return in_array($modo, $validos, true) ? $modo : ($validos ? 'anterior' : 'nenhuma');
}

// Periodo de comparacao: [dia1, dia2, de UTC, ate UTC] ou null
function gestor_periodo_comparacao(string $periodo, string $modo): ?array
{
    if ($modo === 'nenhuma' || !gestor_comparar_modos($periodo)) {
        return null;
    }
    [$de, $ate] = periodo_utc($periodo);
    [$d1, $d2] = gestor_dias($periodo);
    $dias = (int)(new DateTime($d1))->diff(new DateTime($d2))->days + 1;
    $passo = ['semana' => '-7 days', 'mes' => '-1 month'][$modo] ?? "-$dias days";
    $utc = new DateTimeZone('UTC');
    return [
        (new DateTime($d1))->modify($passo)->format('Y-m-d'),
        (new DateTime($d2))->modify($passo)->format('Y-m-d'),
        (new DateTime($de, $utc))->modify($passo)->format('Y-m-d H:i:s'),
        (new DateTime($ate, $utc))->modify($passo)->format('Y-m-d H:i:s'),
    ];
}

// Criterio do ranking: o do endereco, o lembrado ou lucro
function gestor_rank_criterio(): string
{
    $pedido = is_string($_GET['rank'] ?? null) && isset(GESTOR_RANK[$_GET['rank']]) ? $_GET['rank'] : null;
    if ($pedido !== null) {
        $_SESSION['gestor_rank'] = $pedido;
    }
    $c = $pedido ?? ($_SESSION['gestor_rank'] ?? 'lucro');
    return isset(GESTOR_RANK[$c]) ? $c : 'lucro';
}

// Gasto, faturamento e vendas por objeto e por dia (para a curva do ranking)
function gestor_serie(PDO $db, string $nivel, string $dia1, string $dia2, string $de, string $ate): array
{
    [, $colGasto, $campoUtm] = GESTOR_NIVEIS[$nivel];
    if (!$colGasto) {
        return [];
    }
    $s = [];
    foreach (consulta($db, "SELECT $colGasto AS id, dia, SUM(gasto) AS gasto FROM meta_gasto WHERE dia >= ? AND dia <= ? GROUP BY $colGasto, dia", [$dia1, $dia2]) as $g) {
        if ($g['id'] !== null) {
            $s[$g['id']][$g['dia']]['gasto'] = (int)$g['gasto'];
        }
    }
    $utc = new DateTimeZone('UTC');
    $tz = fuso();
    foreach (consulta($db, 'SELECT * FROM vendas WHERE recebida_em >= ? AND recebida_em < ?', [$de, $ate]) as $v) {
        $id = gestor_id_utm($v[$campoUtm]);
        if (!$id || !aprovada($v) || !venda_no_filtro($v)) {
            continue;
        }
        $dia = (new DateTime($v['recebida_em'], $utc))->setTimezone($tz)->format('Y-m-d');
        $s[$id][$dia]['fat'] = ($s[$id][$dia]['fat'] ?? 0) + (int)($v['valor_liquido'] ?? $v['valor'] ?? 0);
        $s[$id][$dia]['vendas'] = ($s[$id][$dia]['vendas'] ?? 0) + (eh_bump($v) ? 0 : 1);
    }
    return $s;
}

// Curva acumulada do criterio, dia a dia: [valor ou null]
function gestor_curva(array $porDia, array $dias, string $criterio, float $pct): array
{
    $g = $f = $n = 0;
    $curva = [];
    foreach ($dias as $d) {
        $g += $porDia[$d]['gasto'] ?? 0;
        $f += $porDia[$d]['fat'] ?? 0;
        $n += $porDia[$d]['vendas'] ?? 0;
        $imposto = (int)round($g * $pct / 100);
        $curva[] = match ($criterio) {
            'lucro' => (float)($f - $g - $imposto),
            'fat' => (float)$f,
            'vendas' => (float)$n,
            'roi' => roi_campanha($f, $g, $imposto),
            'cpa' => $n ? $g / $n : null,
        };
    }
    return $curva;
}

// Mini grafico de linha (como a cotacao de uma acao). $classe: bom, ruim ou vazio.
function gestor_sparkline(array $valores, string $classe, string $rotulo): string
{
    $pts = array_filter($valores, fn($v) => $v !== null);
    if (count($pts) < 2) {
        return '';
    }
    $min = min($pts);
    $max = max($pts);
    $faixa = ($max - $min) ?: 1;
    $n = count($valores);
    $xy = [];
    foreach ($valores as $i => $v) {
        if ($v !== null) {
            $xy[] = round($i * 100 / max(1, $n - 1), 1) . ',' . round(26 - ($v - $min) * 24 / $faixa, 1);
        }
    }
    $zero = $min < 0 && $max > 0 ? '<line x1="0" x2="100" y1="' . round(26 - (0 - $min) * 24 / $faixa, 1) . '" y2="' . round(26 - (0 - $min) * 24 / $faixa, 1) . '" class="rk-zero"></line>' : '';
    return '<svg class="rk-curva ' . e($classe) . '" viewBox="0 0 100 28" preserveAspectRatio="none" role="img" aria-label="' . e($rotulo) . '">'
        . $zero . '<polyline points="' . implode(' ', $xy) . '" vector-effect="non-scaling-stroke"></polyline></svg>';
}

// Ordem do ranking: ids do melhor para o pior (so quem teve gasto ou venda)
function gestor_rank_ordem(array $linhas, string $criterio): array
{
    $menor = GESTOR_RANK[$criterio][2];
    $ativos = array_filter($linhas, fn($r) => $r['gasto'] > 0 || $r['vendas'] > 0 || $r['fat'] > 0);
    uasort($ativos, function ($a, $b) use ($criterio, $menor) {
        $va = $a[$criterio];
        $vb = $b[$criterio];
        if ($va === null || $vb === null) {
            return ($va === null) <=> ($vb === null);
        }
        return ($menor ? $va <=> $vb : $vb <=> $va) ?: $b['fat'] <=> $a['fat'];
    });
    return array_keys($ativos);
}

function gestor_rank_valor(?float $v, string $criterio): string
{
    if ($v === null) {
        return 'N/A';
    }
    return match ($criterio) {
        'roi' => number_format($v, 2, ',', '.'),
        'vendas' => (string)(int)$v,
        default => reais((int)round($v)),
    };
}

// O quadro do ranking. $tabela: linhas da tabela (com 'antes'); $antesPorId: metricas do
// periodo de comparacao de todos os objetos; $link: monta o endereco com o que mudar.
function gestor_ranking_html(array $tabela, ?array $antesPorId, string $criterio, string $nivel, array $series, array $dias, float $pct,
                             callable $link, callable $linkNome, string $rotuloComp): string
{
    $porId = [];
    foreach ($tabela as $r) {
        $porId[$r['id']] = $r;
    }
    $ordem = gestor_rank_ordem($porId, $criterio);
    $nomeNivel = ['campanhas' => 'das campanhas', 'conjuntos' => 'dos conjuntos', 'anuncios' => 'dos anúncios'][$nivel] ?? '';
    [$tituloCrit, $dicaCrit, $menor] = GESTOR_RANK[$criterio];

    // Posicao no periodo de comparacao, entre os mesmos objetos
    $posAntes = [];
    if ($antesPorId !== null) {
        $antes = array_intersect_key($antesPorId, $porId);
        foreach (gestor_rank_ordem($antes, $criterio) as $i => $id) {
            $posAntes[$id] = $i + 1;
        }
    }

    $criterios = '';
    foreach (GESTOR_RANK as $k => [$rot]) {
        $criterios .= '<a href="' . e($link(['rank' => $k])) . '" class="' . ($k === $criterio ? 'atual' : '') . '">' . e($rot) . '</a>';
    }
    $html = '<section class="rc ranking-bloco"><div class="rc-cab"><span>Ranking ' . e($nomeNivel) . '</span>'
        . info('Da melhor para a pior pelo critério escolhido, como um painel de bolsa: posição, quantas posições subiu ou caiu contra ' . ($rotuloComp ?: 'a comparação') . ', o valor, a variação e a curva do período (acumulado dia a dia). Só entra quem teve gasto ou venda no período.') . '</div>'
        . '<div class="segmentos ranking-criterios" role="group" aria-label="Critério do ranking">' . $criterios . '</div>';
    if (!$ordem) {
        return $html . '<p class="suave">Nenhuma com gasto ou venda no período.</p></section>';
    }
    $html .= '<ol class="ranking"><li class="rk-cab"><span>' . com_info('#', 'Posição no ranking.') . '</span>'
        . '<span>' . com_info('Mov.', $antesPorId !== null ? 'Quantas posições subiu (▲) ou caiu (▼) contra ' . $rotuloComp . '. "novo": não rodou nesse período.' : 'Escolha um período de comparação para ver quem subiu e quem caiu.') . '</span>'
        . '<span>' . com_info(['campanhas' => 'Campanha', 'conjuntos' => 'Conjunto', 'anuncios' => 'Anúncio'][$nivel] ?? 'Nome', 'Clique para abrir o próximo nível.') . '</span>'
        . '<span class="rk-num">' . com_info($tituloCrit, $dicaCrit) . '</span>'
        . '<span class="rk-num">' . com_info('Variação', $antesPorId !== null ? 'Quanto o ' . $tituloCrit . ' mudou em % contra ' . $rotuloComp . '. Verde melhorou, vermelho piorou' . ($menor ? ' (no CPA, cair é bom)' : '') . '.' : 'Sem período de comparação.') . '</span>'
        . '<span>' . com_info('Curva', 'O ' . $tituloCrit . ' acumulado dia a dia no período, como o gráfico de uma ação.') . '</span></li>';

    foreach ($ordem as $i => $id) {
        $r = $porId[$id];
        $pos = $i + 1;
        if ($antesPorId === null) {
            $mov = '';
        } elseif (!isset($posAntes[$id])) {
            $mov = '<small class="delta novo">novo</small>';
        } else {
            $dif = $posAntes[$id] - $pos;
            $mov = $dif > 0 ? '<small class="delta bom">▲ ' . $dif . '</small>' : ($dif < 0 ? '<small class="delta ruim">▼ ' . -$dif . '</small>' : '<small class="delta">=</small>');
        }
        $antes = $antesPorId !== null ? ($antesPorId[$id] ?? null) : null;
        $var = $antesPorId === null ? '' : ($antes === null ? '<small class="delta novo">novo</small>'
            : gestor_delta($r[$criterio] === null ? null : (float)$r[$criterio], $antes[$criterio] === null ? null : (float)$antes[$criterio], $menor, false, gestor_rank_valor($antes[$criterio], $criterio)));
        $classe = str_contains($var, 'delta bom') ? 'bom' : (str_contains($var, 'delta ruim') ? 'ruim' : '');
        $curva = isset($series[$id]) ? gestor_sparkline(gestor_curva($series[$id], $dias, $criterio, $pct), $classe, $tituloCrit . ' acumulado no período') : '';
        $v = $r[$criterio];
        $cor = in_array($criterio, ['lucro'], true) ? ($v < 0 ? 'negativo' : 'positivo') : ($criterio === 'roi' ? cor_roi($v === null ? null : (float)$v) : '');
        $ativo = $r['obj'] && ($r['obj']['status_efetivo'] ?? '') === 'ACTIVE';
        $html .= '<li><span class="rk-pos">' . $pos . 'º</span><span class="rk-mov">' . $mov . '</span>'
            . '<span class="rk-nome"><i class="rk-st' . ($ativo ? ' on' : '') . '" title="' . ($ativo ? 'Ativa na Meta' : 'Pausada ou sem status') . '"></i>' . $linkNome($r)
            . ($r['pai'] ? '<small class="suave">' . e($r['pai']) . '</small>' : '') . '</span>'
            . '<b class="rk-num ' . $cor . '">' . e(gestor_rank_valor($v === null ? null : (float)$v, $criterio)) . '</b>'
            . '<span class="rk-num">' . $var . '</span><span class="rk-graf">' . $curva . '</span></li>';
    }
    $sem = count($porId) - count($ordem);
    return $html . '</ol>' . ($sem ? '<small class="rc-rodape">Mais ' . $sem . ' sem gasto nem venda no período (ficam na tabela abaixo).</small>' : '') . '</section>';
}
