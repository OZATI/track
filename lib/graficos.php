<?php
// Graficos dos blocos das telas montaveis (lib/grade.php), feitos no servidor: a CSP do painel nao
// carrega biblioteca de fora. Cada ponto, barra ou casa leva a dica (dica_attr) com o numero; as
// cores saem das variaveis do tema (claro, escuro, pretao ou cor livre).
//
// Os graficos enchem o bloco: o desenho e um SVG esticado (preserveAspectRatio="none", com o traco
// de largura fixa) e os rotulos dos eixos sao HTML por cima, em %. Aumentar ou diminuir o bloco no
// modo de edicao muda o grafico sem desenhar de novo e sem deformar o texto.
//
// - grafico_area: linhas por dia (ou mes, ou hora) com a area em degrade embaixo.
// - grafico_barras: uma barra por dia, verde para cima e vermelha para baixo (lucro, saldo).
// - grafico_mapa_calor: dia da semana x hora em casas (CSS Grid), mais forte onde vende mais.
// - grafico_velocimetro: o ROI num mostrador de 0 a 4, com as faixas vermelha, laranja e verde.
// - grafico_mini: a linhazinha dentro de um cartao de numero (a tendencia do periodo).

// Rotulo curto de reais para o eixo ("R$ 1,2 mil")
function grafico_reais_curto(int $centavos): string
{
    $v = $centavos / 100;
    if (abs($v) >= 1000) {
        return 'R$ ' . number_format($v / 1000, abs($v) >= 10000 ? 0 : 1, ',', '.') . ' mil';
    }
    return 'R$ ' . number_format($v, 0, ',', '.');
}

// Rotulo do eixo: dia "07/10", mes "10/26" ou o que ja vem pronto ("14h")
function grafico_rotulo(string $chave): string
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $chave)) {
        return substr($chave, 8, 2) . '/' . substr($chave, 5, 2);
    }
    if (preg_match('/^\d{4}-\d{2}$/', $chave)) {
        return substr($chave, 5, 2) . '/' . substr($chave, 2, 2);
    }
    return $chave;
}

// Passo "redondo" do eixo (1, 2, 2,5 ou 5 vezes uma potencia de 10) para umas 4 linhas de grade
function grafico_passo(float $faixa, int $linhas = 4): float
{
    if ($faixa <= 0) {
        return 1;
    }
    $bruto = $faixa / $linhas;
    $pot = 10 ** floor(log10($bruto));
    foreach ([1, 2, 2.5, 5] as $m) {
        if ($m * $pot >= $bruto) {
            return $m * $pot;
        }
    }
    return 10 * $pot;
}

// Eixos e grade comuns: [min, max, passo, html do eixo Y, linhas da grade no SVG de 0 a 1000]
function grafico_eixo_y(array $valores): array
{
    $vals = $valores ?: [0];
    $topo = max(0, ...$vals);
    $baixo = min(0, ...$vals);
    $passo = grafico_passo(($topo - $baixo) ?: 10000);
    $max = $passo * ceil($topo / $passo) ?: $passo;
    $min = $passo * floor($baixo / $passo);
    $eixo = '';
    $grade = '';
    for ($c = $min; $c <= $max + 0.5; $c += $passo) {
        $p = round(($max - $c) * 100 / ($max - $min), 2);
        $eixo .= '<span style="top:' . $p . '%">' . e(grafico_reais_curto((int)round($c))) . '</span>';
        $grade .= '<line x1="0" x2="1000" y1="' . ($p * 10) . '" y2="' . ($p * 10) . '" class="' . (abs($c) < 0.5 ? 'grade-zero' : 'grade-l') . '" vector-effect="non-scaling-stroke"></line>';
    }
    return [$min, $max, $eixo, $grade];
}

// Rotulos do eixo X: no maximo 5, espalhados por igual, o primeiro encostado na esquerda e o
// ultimo na direita (nunca passam da borda nem encostam um no outro). No bloco estreito (celular)
// ficam so o primeiro, o do meio e o ultimo (classe "opc", CSS).
function grafico_eixo_x(array $chaves, callable $pos): string
{
    $chaves = array_values($chaves);
    $qtd = count($chaves);
    $m = min($qtd, 5);
    $h = '';
    $vistos = [];
    for ($k = 0; $k < $m; $k++) {
        $i = $m === 1 ? 0 : (int)round($k * ($qtd - 1) / ($m - 1));
        if (isset($vistos[$i])) {
            continue;
        }
        $vistos[$i] = true;
        $classe = $m === 1 ? '' : ($k === 0 ? 'ini' : ($k === $m - 1 ? 'fim' : ($k % 2 ? 'opc' : '')));
        $h .= '<span' . ($classe !== '' ? ' class="' . $classe . '"' : '') . ' style="left:' . round($pos($i) / 10, 2) . '%">' . e(grafico_rotulo((string)$chaves[$i])) . '</span>';
    }
    return '<div class="graf-x">' . $h . '</div>';
}

// Colunas em HTML (vendas por dia da semana ou por hora): a coluna, a % do total em cima e o rotulo
// embaixo, legiveis em qualquer largura; a dica de cada coluna traz o numero. $itens: [rotulo => n];
// $titulos: [rotulo => titulo da dica]; $extras: [rotulo => [linhas a mais na dica]]; $cada: um
// rotulo a cada tantas colunas (as 24 horas).
function grafico_colunas(array $itens, string $nome, array $titulos = [], array $extras = [], int $cada = 1): string
{
    $total = array_sum($itens);
    $max = max(1, 0, ...array_values($itens));
    $muitas = count($itens) > 12;
    $h = '<div class="pw-graf graf-colunas' . ($muitas ? ' muitas' : '') . '" role="img" aria-label="' . e($nome) . '" style="--n:' . count($itens) . '">';
    $i = 0;
    foreach ($itens as $rot => $n) {
        $n = (int)$n;
        $pct = $total ? $n * 100 / $total : 0;
        $dica = dica_attr($titulos[$rot] ?? (string)$rot, array_merge([$n ? $n . ' venda' . ($n === 1 ? '' : 's') . ' · ' . number_format($pct, 1, ',', '.') . '% do período' : 'Nenhuma venda'], $extras[$rot] ?? []));
        $h .= '<div class="col"' . $dica . '><span class="trilho">' . ($n && !$muitas ? '<b>' . e(number_format($pct, $pct >= 10 ? 0 : 1, ',', '.')) . '%</b>' : '')
            . '<i style="--p:' . round($n * 100 / $max, 1) . '"></i></span><span class="rot">' . ($i % $cada === 0 ? e((string)$rot) : '') . '</span></div>';
        $i++;
    }
    return $h . '</div>';
}

// $chaves: ['2026-10-01', ...] (ou meses, ou horas); $series: [[rotulo, cor, [centavos na ordem das chaves]]]
function grafico_area(array $chaves, array $series, string $nome): string
{
    static $n = 0;
    $n++;
    $qtd = count($chaves);
    if (!$qtd) {
        return '<p class="suave">Nada no período.</p>';
    }
    $todos = [];
    foreach ($series as [, , $v]) {
        array_push($todos, ...array_values($v));
    }
    [$min, $max, $eixo, $grade] = grafico_eixo_y($todos);
    $x = fn(int $i) => $qtd === 1 ? 500 : round($i * 1000 / ($qtd - 1), 1);
    $y = fn(float $c) => round(($max - $c) * 1000 / ($max - $min), 1);
    $svg = '<svg viewBox="0 0 1000 1000" preserveAspectRatio="none" role="img" aria-label="' . e($nome) . '"><defs>';
    foreach ($series as $k => [, $cor]) {
        $svg .= '<linearGradient id="ga' . $n . '-' . $k . '" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="' . e($cor) . '" stop-opacity=".3"/><stop offset="100%" stop-color="' . e($cor) . '" stop-opacity="0"/></linearGradient>';
    }
    $svg .= '</defs>' . $grade;
    // Alvo de cada ponto (a coluna acende ao passar o mouse), embaixo das linhas: a dica com todas as series
    $larg = $qtd === 1 ? 1000 : 1000 / ($qtd - 1);
    foreach (array_values($chaves) as $i => $ch) {
        $linhas = array_map(fn($s) => $s[0] . ': ' . reais((int)($s[2][$i] ?? 0)), $series);
        $x0 = max(0, $x($i) - $larg / 2);
        $svg .= '<rect class="vela-alvo" x="' . round($x0, 1) . '" y="0" width="' . round(min(1000, $x($i) + $larg / 2) - $x0, 1) . '" height="1000"' . dica_attr(grafico_rotulo((string)$ch), $linhas) . '></rect>';
    }
    $zero = $y(0);
    foreach ($series as $k => [, $cor, $v]) {
        $pts = [];
        foreach (array_values($v) as $i => $c) {
            $pts[] = $x($i) . ',' . $y((float)$c);
        }
        if ($qtd === 1) { // um dia so: uma reta na largura toda
            $pts = ['0,' . explode(',', $pts[0])[1], '1000,' . explode(',', $pts[0])[1]];
        }
        $svg .= '<polygon fill="url(#ga' . $n . '-' . $k . ')" points="' . explode(',', $pts[0])[0] . ',' . $zero . ' ' . implode(' ', $pts) . ' ' . explode(',', end($pts))[0] . ',' . $zero . '"></polygon>'
            . '<polyline class="graf-linha" stroke="' . e($cor) . '" vector-effect="non-scaling-stroke" points="' . implode(' ', $pts) . '"></polyline>';
    }
    $legenda = '<p class="graf-legenda">' . implode('', array_map(fn($s) => '<span><i style="background:' . e($s[1]) . '"></i>' . e($s[0]) . '</span>', $series)) . '</p>';
    return '<div class="pw-graf">' . $legenda . '<div class="graf-corpo"><div class="graf-y">' . $eixo . '</div><div class="graf-plot">' . $svg . '</svg>' . grafico_eixo_x($chaves, $x) . '</div></div></div>';
}

// Uma barra por dia: verde quando positivo, vermelha quando negativo
function grafico_barras(array $chaves, array $valores, string $nome): string
{
    $qtd = count($chaves);
    if (!$qtd) {
        return '<p class="suave">Nada no período.</p>';
    }
    $vals = array_map('intval', array_values($valores));
    [$min, $max, $eixo, $grade] = grafico_eixo_y($vals);
    $y = fn(float $c) => round(($max - $c) * 1000 / ($max - $min), 1);
    $larg = 1000 / $qtd;
    $zero = $y(0);
    $svg = '<svg viewBox="0 0 1000 1000" preserveAspectRatio="none" role="img" aria-label="' . e($nome) . '">' . $grade;
    foreach (array_values($chaves) as $i => $ch) {
        $v = $vals[$i] ?? 0;
        $x = $i * $larg;
        $svg .= '<g class="dia-graf"' . dica_attr(grafico_rotulo((string)$ch), [$nome . ': ' . reais($v)]) . '>'
            . '<rect class="vela-alvo" x="' . round($x, 1) . '" y="0" width="' . round($larg, 1) . '" height="1000"></rect>';
        if ($v) {
            $topo = $v > 0 ? $y($v) : $zero;
            $svg .= '<rect x="' . round($x + $larg * .16, 1) . '" y="' . $topo . '" width="' . round(max(2, $larg * .68), 1) . '" height="' . round(max(4, abs($y($v) - $zero)), 1) . '" class="' . ($v > 0 ? 'barra-ok' : 'barra-ruim') . '"></rect>';
        }
        $svg .= '</g>';
    }
    return '<div class="pw-graf"><div class="graf-corpo"><div class="graf-y">' . $eixo . '</div><div class="graf-plot">' . $svg . '</svg>'
        . grafico_eixo_x($chaves, fn(int $i) => ($i + .5) * $larg) . '</div></div></div>';
}

// Dia da semana (linhas, seg a dom) x hora (colunas, 0 a 23): $grade[dia 0-6][hora] = vendas. Casas
// em CSS Grid: enchem o bloco em qualquer tamanho.
function grafico_mapa_calor(array $grade): string
{
    $dias = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];
    $max = 1;
    foreach ($grade as $horas) {
        $max = max($max, ...(array_values($horas) ?: [0]));
    }
    $total = array_sum(array_map('array_sum', $grade));
    if (!$total) {
        return '<p class="suave">Nenhuma venda aprovada no período.</p>';
    }
    $h = '<div class="pw-graf mapa-calor" role="img" aria-label="Vendas por dia da semana e hora">';
    foreach ($dias as $d => $nome) {
        $h .= '<span class="calor-dia">' . $nome . '</span>';
        for ($hr = 0; $hr < 24; $hr++) {
            $v = (int)($grade[$d][$hr] ?? 0);
            $h .= '<i class="' . ($v ? 'calor' : 'calor-vazio') . '"' . ($v ? ' style="--q:' . round(.18 + .82 * $v / $max, 2) . '"' : '')
                . dica_attr($nome . ' · ' . sprintf('%02dh às %02dh', $hr, ($hr + 1) % 24), [$v ? $v . ' venda' . ($v === 1 ? '' : 's') . ' · ' . number_format($v * 100 / $total, 1, ',', '.') . '% do período' : 'Nenhuma venda']) . '></i>';
        }
    }
    $h .= '<span></span>';
    for ($hr = 0; $hr < 24; $hr++) {
        $h .= '<span class="calor-hora">' . ($hr % 3 === 0 ? sprintf('%02dh', $hr) : '') . '</span>';
    }
    return $h . '</div>';
}

// Mostrador do ROI de 0 a 4: vermelho abaixo de 1, laranja de 1 a 2, verde de 2 para cima
function grafico_velocimetro(?float $roi, string $sub = ''): string
{
    $cx = 120; $cy = 116; $r = 92;
    $ponto = function (float $v, float $raio = 0) use ($cx, $cy, $r): array {
        $ang = M_PI * (1 - max(0.0, min(4.0, $v)) / 4); // 0 na esquerda, 4 na direita
        $raio = $raio ?: $r;
        return [round($cx + $raio * cos($ang), 1), round($cy - $raio * sin($ang), 1)];
    };
    $arco = function (float $de, float $ate, string $classe) use ($ponto, $r): string {
        [$x1, $y1] = $ponto($de);
        [$x2, $y2] = $ponto($ate);
        return '<path d="M' . $x1 . ',' . $y1 . ' A' . $r . ',' . $r . ' 0 0 1 ' . $x2 . ',' . $y2 . '" class="' . $classe . '"></path>';
    };
    $svg = '<svg class="velocimetro" viewBox="0 0 240 150" role="img" aria-label="ROI ' . e($roi === null ? 'sem gasto' : number_format($roi, 2, ',', '.')) . '">'
        . $arco(0, 0.97, 'vel-ruim') . $arco(1.03, 1.97, 'vel-medio') . $arco(2.03, 4, 'vel-bom');
    foreach ([0, 1, 2, 3, 4] as $marca) {
        [$tx, $ty] = $ponto($marca, $r - 24);
        $svg .= '<text x="' . $tx . '" y="' . round($ty + 4, 1) . '" text-anchor="middle" class="vel-marca">' . $marca . '</text>';
    }
    if ($roi !== null) {
        [$px, $py] = $ponto($roi, $r - 48);
        $svg .= '<line x1="' . $cx . '" y1="' . $cy . '" x2="' . $px . '" y2="' . $py . '" class="vel-ponteiro" style="--de:-' . round(max(0.0, min(4.0, $roi)) * 45) . 'deg"></line>';
    }
    $svg .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="4" class="vel-centro"></circle>'
        . '<text x="' . $cx . '" y="' . ($cy + 30) . '" text-anchor="middle" class="vel-valor ' . e(cor_roi($roi)) . '">' . e($roi === null ? 'N/A' : number_format($roi, 2, ',', '.')) . '</text></svg>';
    return '<div class="pw-graf vel-caixa">' . $svg . ($sub !== '' ? '<small class="vel-sub">' . e($sub) . '</small>' : '') . '</div>';
}

// A linhazinha do cartao de numero: a serie do periodo, com a area embaixo (aceita negativo)
function grafico_mini(array $serie, array $rotulos, string $nome): string
{
    $n = count($serie);
    if ($n < 2) {
        return '';
    }
    $vals = array_values($serie);
    $max = max(0, ...$vals);
    $min = min(0, ...$vals);
    $faixa = ($max - $min) ?: 1;
    $pts = [];
    foreach ($vals as $i => $v) {
        $pts[] = round($i * 1000 / ($n - 1), 1) . ',' . round(40 + ($max - $v) * 920 / $faixa, 1);
    }
    $base = round(40 + $max * 920 / $faixa, 1);
    return '<svg class="pw-mini' . (array_sum($vals) < 0 ? ' ruim' : '') . '" viewBox="0 0 1000 1000" preserveAspectRatio="none" role="img" aria-label="' . e($nome) . ' no período"'
        . dica_attr($nome, array_slice($rotulos, -10)) . '>'
        . '<polygon class="mini-area" points="0,' . $base . ' ' . implode(' ', $pts) . ' 1000,' . $base . '"></polygon>'
        . '<polyline class="mini-linha" vector-effect="non-scaling-stroke" points="' . implode(' ', $pts) . '"></polyline></svg>';
}
