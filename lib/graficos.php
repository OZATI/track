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
//
// Desenho (10/11/2026, pedido do Kenio: "melhora os graficos conforme essa lib", o Tabler, que usa o
// ApexCharts): linha em curva suave (grafico_curva), traco de 2 px, area em degrade leve, mira
// vertical com os marcadores de cada serie ao passar o mouse, barras com a ponta arredondada e no
// maximo 24 px de largura, grade fina e solida. Sem biblioteca: continua tudo no servidor.

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

// Curva suave que passa por todos os pontos sem criar pico ou vale que nao existe (spline monotona,
// Fritsch-Carlson): o "smooth" do ApexCharts sem o exagero da curva comum. $p: [[x, y], ...] em ordem
// de x. $continua: comeca com L (emenda num caminho ja aberto, como o da area) em vez de M.
function grafico_curva(array $p, bool $continua = false): string
{
    $p = array_values($p);
    $n = count($p);
    if (!$n) {
        return '';
    }
    $d = ($continua ? 'L' : 'M') . $p[0][0] . ',' . $p[0][1];
    if ($n < 3) {
        return $n === 2 ? $d . ' L' . $p[1][0] . ',' . $p[1][1] : $d;
    }
    $dx = [];
    $m = [];
    for ($i = 0; $i < $n - 1; $i++) {
        $dx[$i] = $p[$i + 1][0] - $p[$i][0];
        $m[$i] = $dx[$i] ? ($p[$i + 1][1] - $p[$i][1]) / $dx[$i] : 0.0;
    }
    $t = [$m[0]];
    for ($i = 1; $i < $n - 1; $i++) {
        $t[$i] = $m[$i - 1] * $m[$i] <= 0 ? 0.0 : ($m[$i - 1] + $m[$i]) / 2; // virada: tangente reta
    }
    $t[$n - 1] = $m[$n - 2];
    for ($i = 0; $i < $n - 1; $i++) { // limita as tangentes: a curva nao passa do ponto
        if ($m[$i] == 0) {
            $t[$i] = $t[$i + 1] = 0.0;
            continue;
        }
        $a = $t[$i] / $m[$i];
        $b = $t[$i + 1] / $m[$i];
        if ($a * $a + $b * $b > 9) {
            $k = 3 / sqrt($a * $a + $b * $b);
            $t[$i] = $k * $a * $m[$i];
            $t[$i + 1] = $k * $b * $m[$i];
        }
    }
    for ($i = 0; $i < $n - 1; $i++) {
        $h = $dx[$i] / 3;
        $d .= ' C' . round($p[$i][0] + $h, 1) . ',' . round($p[$i][1] + $t[$i] * $h, 1) . ' '
            . round($p[$i + 1][0] - $h, 1) . ',' . round($p[$i + 1][1] - $t[$i + 1] * $h, 1) . ' ' . $p[$i + 1][0] . ',' . $p[$i + 1][1];
    }
    return $d;
}

// Marcador redondo num SVG esticado (preserveAspectRatio="none"): um traco de comprimento zero com a
// ponta redonda e vector-effect, que fica um circulo perfeito em qualquer tamanho do bloco
function grafico_marca(float $x, float $y, string $classe, string $cor = ''): string
{
    return '<path class="' . $classe . '"' . ($cor !== '' ? ' stroke="' . e($cor) . '"' : '') . ' d="M' . $x . ',' . $y . 'h0" vector-effect="non-scaling-stroke"></path>';
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
        // Degrade leve (uma lavada da cor, nunca um bloco cheio), como a area do ApexCharts
        $svg .= '<linearGradient id="ga' . $n . '-' . $k . '" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="' . e($cor) . '" stop-opacity=".22"/><stop offset="100%" stop-color="' . e($cor) . '" stop-opacity="0"/></linearGradient>';
    }
    $svg .= '</defs>' . $grade;
    $zero = $y(0);
    $marcas = []; // [indice => [[x, y, cor], ...]]: os marcadores que acendem na mira
    foreach ($series as $k => [, $cor, $v]) {
        $pts = [];
        foreach (array_values($v) as $i => $c) {
            $pts[] = [$x($i), $y((float)$c)];
            $marcas[$i][] = [$x($i), $y((float)$c), $cor];
        }
        if ($qtd === 1) { // um dia so: uma reta na largura toda
            $pts = [[0, $pts[0][1]], [1000, $pts[0][1]]];
        }
        $svg .= '<path class="graf-area" fill="url(#ga' . $n . '-' . $k . ')" d="M' . $pts[0][0] . ',' . $zero . ' ' . grafico_curva($pts, true) . ' L' . end($pts)[0] . ',' . $zero . ' Z"></path>'
            . '<path class="graf-linha" stroke="' . e($cor) . '" vector-effect="non-scaling-stroke" d="' . grafico_curva($pts) . '"></path>';
    }
    // Por cima das linhas, em cada ponto: a mira (linha vertical) e o marcador de cada serie, que acendem
    // ao passar o mouse ou no foco; o alvo transparente leva a dica com todas as series
    $larg = $qtd === 1 ? 1000 : 1000 / ($qtd - 1);
    foreach (array_values($chaves) as $i => $ch) {
        $linhas = array_map(fn($s) => $s[0] . ': ' . reais((int)($s[2][$i] ?? 0)), $series);
        $x0 = max(0, $x($i) - $larg / 2);
        $svg .= '<g class="graf-ponto"><line class="graf-mira" x1="' . $x($i) . '" x2="' . $x($i) . '" y1="0" y2="1000" vector-effect="non-scaling-stroke"></line>';
        foreach ($marcas[$i] ?? [] as [$mx, $my, $mcor]) {
            $svg .= grafico_marca($mx, $my, 'graf-marca-anel') . grafico_marca($mx, $my, 'graf-marca', $mcor);
        }
        $svg .= '<rect class="vela-alvo" x="' . round($x0, 1) . '" y="0" width="' . round(min(1000, $x($i) + $larg / 2) - $x0, 1) . '" height="1000"' . dica_attr(grafico_rotulo((string)$ch), $linhas) . '></rect></g>';
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
    // As barras vao em HTML por cima da grade: no SVG esticado o canto redondo sairia torto. A ponta do
    // dado e redonda (em cima no lucro, embaixo no prejuizo) e a base reta, no maximo 24 px de largura.
    $barras = '';
    foreach (array_values($chaves) as $i => $ch) {
        $v = $vals[$i] ?? 0;
        $svg .= '<g class="dia-graf"' . dica_attr(grafico_rotulo((string)$ch), [$nome . ': ' . reais($v)]) . '>'
            . '<rect class="vela-alvo" x="' . round($i * $larg, 1) . '" y="0" width="' . round($larg, 1) . '" height="1000"></rect></g>';
        if ($v) {
            $topo = $v > 0 ? $y($v) : $zero;
            $barras .= '<i class="' . ($v > 0 ? 'sobe' : 'desce') . '" style="left:' . round(($i + .5) * 100 / $qtd, 2) . '%;top:' . round($topo / 10, 2)
                . '%;height:' . round(abs($y($v) - $zero) / 10, 2) . '%"></i>';
        }
    }
    return '<div class="pw-graf"><div class="graf-corpo"><div class="graf-y">' . $eixo . '</div><div class="graf-plot">' . $svg . '</svg>'
        . '<div class="graf-barras" aria-hidden="true" style="--larg:' . round(68 / $qtd, 2) . '%">' . $barras . '</div>'
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
            // --t: atraso da entrada, em onda da madrugada de segunda para a noite de domingo (lib/layout.php)
            $h .= '<i class="' . ($v ? 'calor' : 'calor-vazio') . '"' . ($v ? ' style="--q:' . round(.18 + .82 * $v / $max, 2) . ';--t:' . ($hr * 16 + $d * 36) . 'ms"' : '')
                . dica_attr($nome . ' · ' . sprintf('%02dh às %02dh', $hr, ($hr + 1) % 24), [$v ? $v . ' venda' . ($v === 1 ? '' : 's') . ' · ' . number_format($v * 100 / $total, 1, ',', '.') . '% do período' : 'Nenhuma venda']) . '></i>';
        }
    }
    $h .= '<span></span>';
    for ($hr = 0; $hr < 24; $hr++) {
        $h .= '<span class="calor-hora">' . ($hr % 3 === 0 ? sprintf('%02dh', $hr) : '') . '</span>';
    }
    return $h . '</div>';
}

// Regua do CPA (o bloco CPA do Resumo, ao lado do "71% do ticket"): a barra enche a trilha de 0 ate
// o CPA do periodo (a trilha vai ate o ticket; passou dele, enche toda), verde ate a meta
// (CPA_PCT_TICKET% do ticket, a mesma regra da cor do numero) e vermelha no que passou dela. A meta e
// um corte na trilha, com a escala embaixo (0%, a meta, 100%). A dica traz os valores em reais e o mix.
function grafico_regua_cpa(float $cpa, float $ticket, string $mix = ''): string
{
    $pct = $cpa * 100 / $ticket;
    $limite = (float)cpa_limite($ticket);
    $dentro = round($cpa) <= round($limite);
    $pctTxt = number_format($pct, 0, ',', '.') . '% do ticket';
    $linhas = ['CPA: ' . reais((int)round($cpa)) . ' (' . $pctTxt . ')', 'Meta: até ' . reais((int)round($limite)) . ' (' . CPA_PCT_TICKET . '% do ticket)',
        'Ticket médio: ' . reais((int)round($ticket)), $mix];
    return '<div class="cpa-regua' . ($dentro ? '' : ' acima') . '" style="--meta:' . CPA_PCT_TICKET . '%" role="img" aria-label="CPA em ' . e($pctTxt) . ', meta até ' . CPA_PCT_TICKET . '%"'
        . dica_attr('CPA × ticket', $linhas) . '>'
        // O degrade da barra tem o tamanho da trilha inteira, entao o verde acaba na meta qualquer que seja o CPA
        . '<span class="cpa-trilho"><i class="cpa-cheio" style="width:' . round(min(100, $pct), 1) . '%;--trilho:' . round(10000 / max(1, min(100, $pct)), 1) . '%"></i></span>'
        . '<span class="cpa-escala"><span>0%</span><span class="cpa-escala-meta">' . CPA_PCT_TICKET . '%</span><span>100%</span></span></div>';
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
        $pts[] = [round($i * 1000 / ($n - 1), 1), round(40 + ($max - $v) * 920 / $faixa, 1)];
    }
    $base = round(40 + $max * 920 / $faixa, 1);
    return '<svg class="pw-mini' . (array_sum($vals) < 0 ? ' ruim' : '') . '" viewBox="0 0 1000 1000" preserveAspectRatio="none" role="img" aria-label="' . e($nome) . ' no período"'
        . dica_attr($nome, array_slice($rotulos, -10)) . '>'
        . '<path class="mini-area" d="M0,' . $base . ' ' . grafico_curva($pts, true) . ' L1000,' . $base . ' Z"></path>'
        . '<path class="mini-linha" vector-effect="non-scaling-stroke" d="' . grafico_curva($pts) . '"></path></svg>';
}
