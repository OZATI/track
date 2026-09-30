<?php
// Resumo: a primeira tela do painel, montada como o Resumo da UTMify (cartoes grandes,
// rosca de pagamento, aneis de aprovacao, produto e canal, funil com dia da semana e os
// graficos por hora), com o que so o painel tem: ROI geral e rastreado, funil do site e a
// qualidade do rastreio. Filtros de produto e canal valem para as vendas; o gasto e o da
// conta toda. Mesmas contas do gestor de anuncios (lib/gestor.php). Todo numero tem o (i).
//
// Graficos em SVG montado aqui: a CSP do painel nao carrega biblioteca de fora.

require_once __DIR__ . '/gestor.php';

function resumo_pct(float $parte, float $todo): string
{
    if ($todo <= 0) {
        return '—';
    }
    // Taxa pequena (ex.: 10 visitas de 90 mil de alcance) nao vira "0,0%"
    $p = $parte * 100 / $todo;
    return number_format($p, $p > 0 && $p < 0.1 ? 3 : ($p > 0 && $p < 1 ? 2 : 1), ',', '') . '%';
}

// Lista com barra: [rotulo => valor]; $texto formata o numero da direita
function resumo_barras(array $itens, callable $texto): string
{
    if (!$itens) {
        return '<p class="suave">Nada no período.</p>';
    }
    $max = max($itens) ?: 1;
    $html = '<div class="barras">';
    foreach ($itens as $rotulo => $v) {
        $html .= '<span class="rot" title="' . e((string)$rotulo) . '">' . e((string)$rotulo) . '</span>'
            . '<span class="trilho"><i style="width:' . round($v * 100 / $max, 1) . '%"></i></span>'
            . '<span class="val">' . $texto($v) . '</span>';
    }
    return $html . '</div>';
}

// Funil em fluxo (como o da UTMify): a faixa afunila de um passo para o outro, com a % sobre
// o primeiro passo no meio e o numero embaixo. [rotulo => [valor, dica]]; o nome leva o (i).
// O desenho e um SVG esticado na largura (sem texto dentro); textos ficam no HTML.
function resumo_funil(array $passos): string
{
    static $seq = 0;
    $seq++;
    $n = max(1, count($passos));
    $vals = array_map(fn($p) => max(0.0, (float)$p[0]), array_values($passos));
    $max = max(1.0, ...$vals);
    $primeiro = $vals[0] ?? 0.0;
    $larg = 1000 / $n;
    $alt = fn(float $v) => max(3.0, $v * 94 / $max); // passo zerado ainda aparece como um fio
    $cima = fn(float $v) => round(50 - $alt($v) / 2, 2);
    $baixo = fn(float $v) => round(50 + $alt($v) / 2, 2);
    $x = fn(float $v) => round($v, 2);

    // Contorno: em cada passo a faixa fica reta ate o meio e desce/sobe ate o comeco do proximo
    $d = 'M0,' . $cima($vals[0]);
    for ($i = 0; $i < $n; $i++) {
        $x0 = $i * $larg;
        $d .= ' L' . $x($x0 + $larg * .5) . ',' . $cima($vals[$i]);
        $d .= $i < $n - 1
            ? ' C' . $x($x0 + $larg * .8) . ',' . $cima($vals[$i]) . ' ' . $x($x0 + $larg * .85) . ',' . $cima($vals[$i + 1]) . ' ' . $x($x0 + $larg) . ',' . $cima($vals[$i + 1])
            : ' L1000,' . $cima($vals[$i]);
    }
    $d .= ' L1000,' . $baixo($vals[$n - 1]);
    for ($i = $n - 1; $i >= 0; $i--) {
        $x0 = $i * $larg;
        if ($i < $n - 1) {
            $d .= ' C' . $x($x0 + $larg * .85) . ',' . $baixo($vals[$i + 1]) . ' ' . $x($x0 + $larg * .8) . ',' . $baixo($vals[$i]) . ' ' . $x($x0 + $larg * .5) . ',' . $baixo($vals[$i]);
        }
        $d .= ' L' . $x($x0) . ',' . $baixo($vals[$i]);
    }
    $linhas = '';
    for ($i = 1; $i < $n; $i++) {
        $linhas .= '<line x1="' . $x($i * $larg) . '" y1="0" x2="' . $x($i * $larg) . '" y2="100" vector-effect="non-scaling-stroke"></line>';
    }

    $html = '<div class="fluxo" style="--n:' . $n . '"><div class="fluxo-cab">';
    foreach ($passos as $rotulo => [, $dica]) {
        $html .= '<span>' . com_info((string)$rotulo, $dica) . '</span>';
    }
    $html .= '</div><div class="fluxo-corpo"><svg viewBox="0 0 1000 100" preserveAspectRatio="none" aria-hidden="true">'
        . '<defs><linearGradient id="fluxo' . $seq . '" x1="0" x2="1" y1="0" y2="0"><stop offset="0" style="stop-color:var(--marca)"></stop><stop offset="1" style="stop-color:var(--marca-hover)"></stop></linearGradient></defs>'
        . '<path d="' . $d . ' Z" fill="url(#fluxo' . $seq . ')"></path>' . $linhas . '</svg><div class="fluxo-pct">';
    foreach ($vals as $v) {
        // Faixa grossa: a % vai dentro, em branco; faixa fina: logo acima dela, pela altura
        $html .= $alt($v) >= 24 ? '<b class="dentro">' . e(resumo_pct($v, $primeiro)) . '</b>'
            : '<b class="fora" style="--h:' . round($alt($v), 1) . '">' . e(resumo_pct($v, $primeiro)) . '</b>';
    }
    $html .= '</div></div><div class="fluxo-pe">';
    foreach ($vals as $v) {
        $html .= '<b>' . number_format($v, 0, ',', '.') . '</b>';
    }
    return $html . '</div></div>';
}

// Grafico de linhas por hora (0 a 23). $series = [[rotulo, cor, [hora => centavos]]]
function resumo_svg_linhas(array $series, int $ateHora): string
{
    $L = 1400; $A = 260; $esq = 80; $dir = 10; $cima = 12; $baixo = 26;
    $max = 1;
    foreach ($series as [, , $v]) {
        $max = max($max, ...array_map('abs', $v));
    }
    $min = 0;
    foreach ($series as [, , $v]) {
        $min = min($min, ...$v);
    }
    $passo = ($L - $esq - $dir) / 23;
    $y = fn($c) => $cima + ($max - $c) * ($A - $cima - $baixo) / (($max - $min) ?: 1);
    $svg = '<svg class="grafico" viewBox="0 0 ' . $L . ' ' . $A . '" role="img" aria-label="Gráfico por hora">';
    for ($i = 0; $i <= 4; $i++) {
        $c = $min + ($max - $min) * $i / 4;
        $yy = round($y($c), 1);
        $svg .= '<line x1="' . $esq . '" x2="' . ($L - $dir) . '" y1="' . $yy . '" y2="' . $yy . '" class="grade-l"/>'
            . '<text x="' . ($esq - 8) . '" y="' . ($yy + 4) . '" text-anchor="end">' . e(reais((int)round($c))) . '</text>';
    }
    for ($h = 0; $h < 24; $h += 2) {
        $svg .= '<text x="' . round($esq + $h * $passo, 1) . '" y="' . ($A - 6) . '" text-anchor="middle">' . sprintf('%02d h', $h) . '</text>';
    }
    foreach ($series as [$rotulo, $cor, $v]) {
        $pts = [];
        for ($h = 0; $h <= $ateHora; $h++) {
            $pts[] = round($esq + $h * $passo, 1) . ',' . round($y($v[$h] ?? 0), 1);
        }
        // Area de leve embaixo da linha, ate o zero (como no grafico da UTMify)
        $zero = round($y(0), 1);
        $svg .= '<polygon fill="' . $cor . '" fill-opacity="0.08" stroke="none" points="' . round($esq, 1) . ',' . $zero . ' ' . implode(' ', $pts) . ' ' . round($esq + $ateHora * $passo, 1) . ',' . $zero . '"></polygon>'
            . '<polyline fill="none" stroke="' . $cor . '" stroke-width="2" points="' . implode(' ', $pts) . '"><title>' . e($rotulo) . '</title></polyline>';
    }
    return $svg . '</svg>';
}

// Barras por hora com a % em cima
function resumo_svg_barras(array $porHora): string
{
    $L = 1400; $A = 190; $esq = 10; $baixo = 22; $cima = 18;
    $total = array_sum($porHora);
    $max = max(1, ...array_values($porHora));
    $larg = ($L - 2 * $esq) / 24;
    $svg = '<svg class="grafico" viewBox="0 0 ' . $L . ' ' . $A . '" role="img" aria-label="Vendas por horário">';
    for ($h = 0; $h < 24; $h++) {
        $n = $porHora[$h] ?? 0;
        $x = $esq + $h * $larg;
        $alt = $n * ($A - $cima - $baixo) / $max;
        if ($n) {
            $svg .= '<rect x="' . round($x + 6, 1) . '" y="' . round($A - $baixo - $alt, 1) . '" width="' . round($larg - 12, 1) . '" height="' . round($alt, 1) . '" class="barra"><title>'
                . sprintf('%02d h', $h) . ': ' . $n . ' venda(s)</title></rect>'
                . '<text x="' . round($x + $larg / 2, 1) . '" y="' . round($A - $baixo - $alt - 5, 1) . '" text-anchor="middle">' . e(resumo_pct($n, $total)) . '</text>';
        }
        $svg .= '<text x="' . round($x + $larg / 2, 1) . '" y="' . ($A - 6) . '" text-anchor="middle">' . sprintf('%02d', $h) . '</text>';
    }
    return $svg . '</svg>';
}

// Cartao de numero (como os da UTMify): nome e (i) em cima, valor grande embaixo
function resumo_cartao(string $valor, string $rotulo, string $dica, string $classe = '', string $sub = '', string $larg = 'c3'): string
{
    return '<div class="rc ' . $larg . '"><div class="rc-cab"><span>' . e($rotulo) . '</span>' . info($dica) . '</div>'
        . '<b class="' . e($classe) . '">' . e($valor) . '</b>' . ($sub !== '' ? '<small>' . e($sub) . '</small>' : '') . '</div>';
}

// Anel pequeno de porcentagem (0 a 100)
function resumo_anel(?float $pct): string
{
    $p = $pct === null ? 0 : max(0.0, min(100.0, $pct));
    return '<svg class="anel" viewBox="0 0 36 36" aria-hidden="true"><circle cx="18" cy="18" r="15.915" class="anel-fundo"></circle>'
        . '<circle cx="18" cy="18" r="15.915" class="anel-valor" stroke-dasharray="' . round($p, 2) . ' ' . round(100 - $p, 2) . '" stroke-dashoffset="25"></circle></svg>';
}

// Lista com numero, anel e %: [[rotulo_html, n, pct ou null]]
function resumo_lista_aneis(array $linhas, string $vazio = 'Nada no período.'): string
{
    if (!$linhas) {
        return '<p class="suave">' . e($vazio) . '</p>';
    }
    $html = '<ul class="lista-aneis">';
    foreach ($linhas as [$rotulo, $n, $pct]) {
        $html .= '<li><span class="rot">' . $rotulo . '</span><span class="n">' . e((string)$n) . '</span>' . resumo_anel($pct)
            . '<span class="pct">' . ($pct === null ? 'N/A' : e(number_format($pct, 1, ',', '.') . '%')) . '</span></li>';
    }
    return $html . '</ul>';
}

// Rosca: [rotulo => n], com o total no meio e a legenda embaixo
function resumo_rosca(array $partes, array $cores, string $centro): string
{
    $total = array_sum($partes);
    $svg = '<svg class="rosca" viewBox="0 0 42 42" role="img" aria-label="' . e($centro . ': ' . $total) . '"><circle cx="21" cy="21" r="15.915" class="rosca-fundo"></circle>';
    $ini = 25.0;
    foreach ($partes as $rot => $n) {
        if (!$n || !$total) {
            continue;
        }
        $p = $n * 100 / $total;
        $svg .= '<circle cx="21" cy="21" r="15.915" fill="none" stroke="' . e($cores[$rot] ?? '#9CA3AF') . '" stroke-width="6" stroke-dasharray="' . round($p, 3) . ' ' . round(100 - $p, 3)
            . '" stroke-dashoffset="' . round($ini, 3) . '"><title>' . e($rot . ': ' . $n) . '</title></circle>';
        $ini -= $p;
    }
    $svg .= '<text x="21" y="19.5" class="rosca-rot">' . e($centro) . '</text><text x="21" y="26" class="rosca-num">' . $total . '</text></svg>';
    $leg = '<ul class="rosca-leg">';
    foreach ($partes as $rot => $n) {
        $leg .= '<li><i style="background:' . e($cores[$rot] ?? '#9CA3AF') . '"></i>' . e($rot) . ' <b>' . $n . '</b> <span class="suave">' . e($total ? number_format($n * 100 / $total, 0) . '%' : '—') . '</span></li>';
    }
    return '<div class="rosca-caixa">' . $svg . $leg . '</ul></div>';
}

// Colunas com a % em cima (ex.: vendas por dia da semana). $itens = [rotulo => n]
function resumo_svg_colunas(array $itens, string $nome): string
{
    $L = 460; $A = 200; $esq = 6; $baixo = 22; $cima = 18;
    $total = array_sum($itens);
    $max = max(1, ...array_values($itens));
    $larg = ($L - 2 * $esq) / max(1, count($itens));
    $svg = '<svg class="grafico" viewBox="0 0 ' . $L . ' ' . $A . '" role="img" aria-label="' . e($nome) . '">';
    for ($i = 1; $i <= 4; $i++) {
        $yy = round($cima + ($A - $cima - $baixo) * ($i - 1) / 4, 1);
        $svg .= '<line x1="' . $esq . '" x2="' . ($L - $esq) . '" y1="' . $yy . '" y2="' . $yy . '" class="grade-l"></line>';
    }
    $i = 0;
    foreach ($itens as $rot => $n) {
        $x = $esq + $i * $larg;
        $alt = $n * ($A - $cima - $baixo) / $max;
        if ($n) {
            $svg .= '<rect x="' . round($x + $larg * .22, 1) . '" y="' . round($A - $baixo - $alt, 1) . '" width="' . round($larg * .56, 1) . '" height="' . round($alt, 1) . '" class="barra"><title>'
                . e($rot) . ': ' . $n . ' venda(s)</title></rect>'
                . '<text x="' . round($x + $larg / 2, 1) . '" y="' . round($A - $baixo - $alt - 6, 1) . '" text-anchor="middle">' . e(resumo_pct($n, $total)) . '</text>';
        }
        $svg .= '<text x="' . round($x + $larg / 2, 1) . '" y="' . ($A - 6) . '" text-anchor="middle">' . e($rot) . '</text>';
        $i++;
    }
    return $svg . '</svg>';
}

// "há 3 minutos" a partir de uma data UTC
function resumo_ha(?string $utc): string
{
    if (!$utc) {
        return 'ainda não atualizado';
    }
    $seg = time() - (strtotime($utc . ' UTC') ?: time());
    if ($seg < 60) {
        return 'atualizado agora';
    }
    if ($seg < 3600) {
        $m = intdiv($seg, 60);
        return 'atualizado há ' . $m . ' minuto' . ($m > 1 ? 's' : '');
    }
    if ($seg < 86400) {
        $h = intdiv($seg, 3600);
        return 'atualizado há ' . $h . ' hora' . ($h > 1 ? 's' : '');
    }
    return 'atualizado em ' . data_local($utc, 'd/m H:i');
}

function resumo_render(PDO $db, string $periodo, string $de, string $ate): void
{
    $pct = gestor_imposto_pct();
    [$dia1, $dia2] = gestor_dias($periodo);
    $temMeta = (bool)meta_api_chave();
    $num = fn(float $v, int $casas = 2) => number_format($v, $casas, ',', '.');
    $tz = fuso();

    // Filtros do Resumo (como os da UTMify): produto e canal. Valem para as vendas; o gasto e
    // o funil da Meta sao da conta toda (a Meta nao divide o gasto por produto).
    $fProduto = texto($_GET['produto'] ?? '', 200);
    $canaisNome = ['instagram' => 'Instagram · anúncio', 'facebook' => 'Facebook · anúncio', 'meta' => 'Anúncio sem posicionamento',
        'compartilhado' => 'Anúncio compartilhado', 'google' => 'Google · anúncio', 'organico' => 'Orgânico', 'outros' => 'Outras origens', 'direto' => 'Direto / sem origem'];
    $fCanal = isset($canaisNome[$_GET['canal'] ?? '']) ? $_GET['canal'] : '';

    // Meta: gasto e funil no periodo
    $g = consulta($db, 'SELECT SUM(gasto) AS gasto, SUM(cliques) AS cliques, SUM(visualizacoes) AS vis, SUM(checkouts) AS ics FROM meta_gasto WHERE dia >= ? AND dia <= ?', [$dia1, $dia2])[0];
    $gasto = (int)$g['gasto'];
    $imposto = (int)round($gasto * $pct / 100);

    // Kiwify: vendas do periodo
    $fat = $fatMeta = $aprovadas = $pendValor = $pendN = $reembValor = $reembN = 0;
    $metaIniciadas = $metaAprovadas = $siteIniciadas = $siteAprovadas = $comOrigem = $semOrigem = 0;
    $porPagamento = ['Pix' => 0, 'Cartão' => 0, 'Boleto' => 0, 'Outros' => 0];
    $porProduto = $porCanal = $porHora = $fatHora = [];
    $porSemana = ['Seg' => 0, 'Ter' => 0, 'Qua' => 0, 'Qui' => 0, 'Sex' => 0, 'Sáb' => 0, 'Dom' => 0];
    $tentativas = $aprovPorMeio = [];
    $meios = ['pix' => 'Pix', 'credit_card' => 'Cartão', 'boleto' => 'Boleto'];
    $produtos = [];
    foreach (consulta($db, 'SELECT * FROM vendas WHERE recebida_em >= ? AND recebida_em < ?', [$de, $ate]) as $v) {
        $produtos[$v['produto'] ?: '—'] = true;
        $cn = canal($v['utm_source'], $v['utm_medium'], $v['utm_term'], null, $v['utm_campaign']);
        if (($fProduto !== '' && ($v['produto'] ?: '—') !== $fProduto) || ($fCanal !== '' && $cn[0] !== $fCanal)) {
            continue;
        }
        $principal = !eh_bump($v);
        $sit = situacao($v)[0];
        $liquido = (int)($v['valor_liquido'] ?? $v['valor'] ?? 0);
        $meio = $meios[strtolower((string)$v['pagamento'])] ?? 'Outros';
        $daMeta = gestor_id_utm($v['utm_campaign']) !== null;
        if ($principal) {
            $metaIniciadas += $daMeta ? 1 : 0;
            $siteIniciadas += $v['visitante'] ? 1 : 0;
            if (in_array($sit, ['Aprovada', 'Recusada', 'Aguardando pagamento', 'Reembolsada', 'Chargeback'], true)) {
                $tentativas[$meio] = ($tentativas[$meio] ?? 0) + 1;
            }
        }
        if (aprovada($v)) {
            $fat += $liquido;
            $fatMeta += $daMeta ? $liquido : 0;
            $porProduto[$v['produto'] ?: '—'] = ($porProduto[$v['produto'] ?: '—'] ?? 0) + 1;
            $quando = (new DateTime($v['aprovada_em'] ?: $v['recebida_em'], new DateTimeZone('UTC')))->setTimezone($tz);
            $h = (int)$quando->format('G');
            $fatHora[$h] = ($fatHora[$h] ?? 0) + $liquido;
            if ($principal) {
                $aprovadas++;
                $porPagamento[$meio]++;
                $aprovPorMeio[$meio] = ($aprovPorMeio[$meio] ?? 0) + 1;
                $porCanal[$cn[0]] = ['canal' => $cn, 'n' => ($porCanal[$cn[0]]['n'] ?? 0) + 1];
                $porHora[$h] = ($porHora[$h] ?? 0) + 1;
                $porSemana[array_keys($porSemana)[(int)$quando->format('N') - 1]]++;
                $metaAprovadas += $daMeta ? 1 : 0;
                $siteAprovadas += $v['visitante'] ? 1 : 0;
                $cn[0] === 'direto' ? $semOrigem++ : $comOrigem++;
            }
        } elseif ($principal && $sit === 'Aguardando pagamento') {
            $pendValor += (int)$v['valor'];
            $pendN++;
        } elseif (in_array($sit, ['Reembolsada', 'Chargeback'], true)) {
            $reembValor += (int)$v['valor'];
            $reembN += $principal ? 1 : 0;
        }
    }
    $lucro = $fat - $gasto - $imposto;
    // ROI geral: tudo o que voltou (anuncio, organico, direto, rastreado ou nao) sobre tudo o
    // que foi investido (gasto + imposto da Meta). O rastreado usa so as vendas com o ID de
    // uma campanha, para ver quanto do retorno o painel liga aos anuncios.
    $investido = $gasto + $imposto;
    $roi = $investido ? $fat / $investido : null;
    $roiMeta = $investido ? $fatMeta / $investido : null;
    $cor = fn(?float $v, float $limite = 0) => $v === null ? '' : ($v >= $limite ? 'positivo' : 'negativo');

    // Site: visitantes e cliques no checkout (t.js)
    $visitantes = (int)valor($db, 'SELECT COUNT(DISTINCT visitante) FROM eventos WHERE em >= ? AND em < ?', [$de, $ate]);
    $clicaram = (int)valor($db, "SELECT COUNT(DISTINCT visitante) FROM eventos WHERE nome = 'CliqueCheckout' AND em >= ? AND em < ?", [$de, $ate]);

    // Cabecalho: titulo, ultima atualizacao, Atualizar e os filtros
    $meta = meta_sync_estado();
    $k = kiwify_sync_estado();
    $vencida = meta_sync_vencida() || kiwify_sync_vencida();
    $datas = array_filter([$k['ok_em'], $meta['ok_em']]);
    $quando = $datas ? resumo_ha(min($datas)) : 'ainda não atualizado';
    ksort($produtos);
    if ($fProduto !== '') {
        $produtos[$fProduto] = true;
    }
    $opcoes = fn(array $lista, string $atual, string $todos) => '<option value="">' . e($todos) . '</option>'
        . implode('', array_map(fn($val, $rot) => '<option value="' . e((string)$val) . '"' . ((string)$val === $atual ? ' selected' : '') . '>' . e($rot) . '</option>', array_keys($lista), $lista));
    echo '<section class="bloco resumo-cab"><div class="resumo-topo"><h2>' . com_info('Resumo', 'Os números do período escolhido no topo. As vendas vêm da Kiwify (webhook e API), o gasto e o funil da Meta, e as visitas do próprio painel.') . '</h2>'
        . '<div class="barra-vendas" id="sync"' . ($vencida ? ' data-sync="1"' : '') . '><span data-sync-texto>' . e(ucfirst($quando)) . '</span>'
        . botao_atualizar('./?' . http_build_query(array_filter(['aba' => 'geral', 'periodo' => $periodo, 'produto' => $fProduto, 'canal' => $fCanal]))) . '</div></div>'
        . '<form class="resumo-filtros" method="get" action="./" data-auto><input type="hidden" name="aba" value="geral"><input type="hidden" name="periodo" value="' . e($periodo) . '">'
        . '<label>Produto<select name="produto">' . $opcoes(array_combine(array_keys($produtos), array_keys($produtos)), $fProduto, 'Qualquer') . '</select></label>'
        . '<label>Canal da venda<select name="canal">' . $opcoes($canaisNome, $fCanal, 'Qualquer') . '</select></label>'
        . '<noscript><button type="submit" class="discreto neutro">Filtrar</button></noscript></form>';
    if ($fProduto !== '' || $fCanal !== '') {
        echo '<p class="suave resumo-aviso">Filtro ligado: faturamento, vendas e lucro contam só ' . e(trim(($fProduto !== '' ? $fProduto : '') . ($fProduto !== '' && $fCanal !== '' ? ' · ' : '') . ($fCanal !== '' ? $canaisNome[$fCanal] : '')))
            . '. O gasto e o funil da Meta continuam os da conta toda. <a href="' . e('./?' . http_build_query(['aba' => 'geral', 'periodo' => $periodo])) . '">Tirar o filtro</a></p>';
    }
    echo '</section>';
    if (!$temMeta) {
        echo '<p class="aviso-meta">Sem a conta de anúncios conectada, gasto, lucro, ROI e o funil da Meta ficam zerados. Conecte na aba <a href="meta-api.php">API Meta</a>.</p>';
    }

    // Numeros, pagamento e taxas (grade como a da UTMify)
    $taxa = fn(string $m) => !empty($tentativas[$m]) ? ($aprovPorMeio[$m] ?? 0) * 100 / $tentativas[$m] : null;
    echo '<div class="rgrade">'
        . resumo_cartao(reais($fat), 'Faturamento líquido', 'Soma do que a Kiwify repassa (depois das taxas) das vendas aprovadas no período, com order bump.', '', $aprovadas . ' venda' . ($aprovadas === 1 ? '' : 's') . ' aprovada' . ($aprovadas === 1 ? '' : 's'), 'c3')
        . resumo_cartao(reais($gasto), 'Gasto com anúncios', 'Quanto a Meta cobrou pelos anúncios no período, sem o imposto.', '', 'investimento com imposto: ' . reais($investido), 'c3')
        . resumo_cartao($roi === null ? 'N/A' : $num($roi), 'ROI geral', 'Tudo o que voltou ÷ tudo o que foi investido: faturamento líquido de todas as vendas aprovadas (anúncio, orgânico e direto, rastreadas ou não) ÷ (gasto na Meta + imposto). Acima de 1, o investimento se paga.', $cor($roi, 1), '', 'c2')
        . resumo_cartao($roiMeta === null ? 'N/A' : $num($roiMeta), 'ROI rastreado', 'Só as vendas com o ID de uma campanha da Meta ÷ (gasto + imposto). A diferença para o ROI geral é o retorno que veio de orgânico, direto ou venda sem etiqueta.', $cor($roiMeta, 1), '', 'c2')
        . resumo_cartao(reais($lucro), 'Lucro', 'Faturamento líquido − gasto − imposto da Meta (' . $num($pct) . '% sobre o gasto).', $lucro ? $cor((float)$lucro) : '', '', 'c2')
        . '<section class="rc c4 r2"><div class="rc-cab"><span>Vendas por pagamento</span>' . info('Vendas aprovadas (sem contar order bump) por forma de pagamento.') . '</div>'
        . resumo_rosca($porPagamento, ['Pix' => '#1D6FF2', 'Cartão' => '#60A5FA', 'Boleto' => '#F59E0B', 'Outros' => '#9CA3AF'], 'Total') . '</section>'
        . resumo_cartao($fat ? $num($lucro * 100 / $fat, 1) . '%' : '—', 'Margem', 'Lucro ÷ faturamento líquido: quanto de cada real vendido sobra.', $fat ? $cor((float)$lucro) : '', '', 'c2')
        . resumo_cartao($aprovadas ? reais((int)round($gasto / $aprovadas)) : 'N/A', 'CPA', 'Gasto ÷ vendas aprovadas. Order bump não conta como outra venda.', '', '', 'c2')
        . resumo_cartao(reais($imposto), 'Imposto da Meta', 'Impostos que a Meta cobra sobre o gasto com anúncios no Brasil: ' . $num($pct) . '%.', '', '', 'c2')
        . resumo_cartao($aprovadas ? reais((int)round($fat / $aprovadas)) : 'N/A', 'Ticket médio', 'Faturamento líquido ÷ vendas aprovadas (quanto cada comprador deixa, com order bump). Na UTMify, ARPU.', '', '', 'c2')
        . resumo_cartao(reais($pendValor), 'Vendas pendentes', 'Pix ou boleto gerado e ainda não pago, no valor cobrado.', '', $pendN . ' pedido' . ($pendN === 1 ? '' : 's'), 'c4')
        . resumo_cartao(reais($reembValor), 'Vendas reembolsadas', 'Reembolsos e chargebacks no período, no valor cobrado.', $reembValor ? 'negativo' : '', $reembN . ' venda' . ($reembN === 1 ? '' : 's'), 'c4')
        . '</div>';

    // Taxa de aprovacao, produto e canal
    arsort($porProduto);
    $totProd = array_sum($porProduto);
    uasort($porCanal, fn($a, $b) => $b['n'] <=> $a['n']);
    echo '<div class="rgrade">'
        . '<section class="rc c4"><div class="rc-cab"><span>Taxa de aprovação</span>' . info('Aprovadas ÷ pedidos criados com cada forma de pagamento (cartão recusado e Pix não pago derrubam a taxa).') . '</div>'
        . resumo_lista_aneis(array_map(fn($m) => [e($m), (string)($aprovPorMeio[$m] ?? 0) . '/' . ($tentativas[$m] ?? 0), $taxa($m)], ['Cartão', 'Pix', 'Boleto'])) . '</section>'
        . '<section class="rc c4"><div class="rc-cab"><span>Vendas por produto</span>' . info('Pedidos aprovados de cada produto, com os order bumps (cada bump conta no seu produto).') . '</div>'
        . resumo_lista_aneis(array_map(fn($p, $n) => [e($p), $n, $totProd ? $n * 100 / $totProd : null], array_keys($porProduto), $porProduto)) . '</section>'
        . '<section class="rc c4"><div class="rc-cab"><span>Vendas por canal</span>' . info('Vendas aprovadas pelo canal que o painel identificou na etiqueta. O (i) de cada canal diz quando a venda cai nele.') . '</div>'
        . resumo_lista_aneis(array_map(fn($c) => [selo_canal($c['canal'], false), $c['n'], $aprovadas ? $c['n'] * 100 / $aprovadas : null], array_values($porCanal))) . '</section>'
        . '</div>';

    // Funis, dia da semana e qualidade do rastreio
    echo '<div class="rgrade"><section class="bloco c8">' . titulo('Funil da Meta', 'Do clique no anúncio até a venda aprovada. Cliques, visualizações e inícios de checkout vêm da Meta; vendas, da Kiwify (só as com o ID de uma campanha).')
        . resumo_funil([
            'Cliques' => [(int)$g['cliques'], 'Cliques no link do anúncio (Meta).'],
            "Visuali\u{00AD}zações" => [(int)$g['vis'], 'Visualizações da página de destino que a Meta contou (a página carregou).'],
            'Inícios de checkout' => [(int)$g['ics'], 'InitiateCheckout contados pela Meta (checkout aberto na Kiwify).'],
            'Vendas iniciadas' => [$metaIniciadas, 'Pedidos criados na Kiwify vindos de anúncio, pagos ou não.'],
            'Vendas aprovadas' => [$metaAprovadas, 'Pedidos aprovados vindos de anúncio.'],
        ]) . '</section>'
        . '<section class="bloco c4">' . titulo('Vendas por dia da semana', 'Vendas aprovadas em cada dia da semana (dia da aprovação, horário de Brasília), em % do período.')
        . resumo_svg_colunas($porSemana, 'Vendas por dia da semana') . '</section></div>';
    $fora = './?' . http_build_query(['aba' => 'vendas', 'periodo' => $periodo, 'filtro' => 'fora']);
    echo '<div class="rgrade"><section class="bloco c8">' . titulo('Funil do site', 'Medido pelo próprio painel (t.js nas páginas), sem depender da Meta. Vale para qualquer origem.')
        . resumo_funil([
            'Visitantes' => [$visitantes, 'Aparelhos diferentes que abriram as páginas com o painel.'],
            'Clicaram no checkout' => [$clicaram, 'Visitantes que clicaram no botão de compra.'],
            'Vendas iniciadas' => [$siteIniciadas, 'Pedidos criados na Kiwify que o painel ligou a um visitante.'],
            'Vendas aprovadas' => [$siteAprovadas, 'Desses, os aprovados.'],
        ]) . '</section>'
        . '<section class="rc c4"><div class="rc-cab"><span>Qualidade do rastreio</span>' . info('Quanto das vendas aprovadas o painel conseguiu explicar. Quanto maior, mais dá para confiar no ROI rastreado e nas decisões por campanha. É o que a UTMify chama de vendas trackeadas, com a origem orgânica e a do site junto.') . '</div>'
        . resumo_lista_aneis([
            ['Com origem identificada ' . info('Vendas com etiqueta de anúncio, orgânica ou de outra origem: tudo menos "Direto / sem origem".'), $comOrigem, $aprovadas ? $comOrigem * 100 / $aprovadas : null],
            ['Ligadas a uma campanha ' . info('Vendas com o ID de uma campanha da Meta: entram no gestor de anúncios e no ROI rastreado.'), $metaAprovadas, $aprovadas ? $metaAprovadas * 100 / $aprovadas : null],
            ['Ligadas a um visitante ' . info('Vendas que o painel ligou a um aparelho que visitou as páginas (parâmetro sck no checkout).'), $siteAprovadas, $aprovadas ? $siteAprovadas * 100 / $aprovadas : null],
            ['Sem origem ' . info('Chegaram sem etiqueta nenhuma: link de checkout enviado à mão, e-mail, troca de aparelho.'), $semOrigem, $aprovadas ? $semOrigem * 100 / $aprovadas : null],
        ], 'Nenhuma venda aprovada no período.')
        . '<a class="rc-link" href="' . e($fora) . '">Ver as vendas fora de anúncio</a></section></div>';

    // Graficos por hora
    $hoje = $periodo === 'hoje';
    $ateHora = $hoje ? (int)(new DateTime('now', $tz))->format('G') : 23;
    $gastoHora = [];
    foreach (consulta($db, 'SELECT hora, SUM(gasto) AS g FROM meta_gasto_hora WHERE dia >= ? AND dia <= ? GROUP BY hora', [$dia1, $dia2]) as $r) {
        $gastoHora[(int)$r['hora']] = (int)$r['g'];
    }
    $acF = $acG = $acL = [];
    $f = $gg = 0;
    for ($h = 0; $h < 24; $h++) {
        $f += $fatHora[$h] ?? 0;
        $gg += $gastoHora[$h] ?? 0;
        $acF[$h] = $f;
        $acG[$h] = $gg;
        $acL[$h] = $f - $gg - (int)round($gg * $pct / 100);
    }
    echo '<section class="bloco">' . titulo('Vendas por horário', 'Percentual das vendas aprovadas em cada hora do dia (hora da aprovação, no horário de Brasília).')
        . resumo_svg_barras($porHora) . '</section>';
    echo '<section class="bloco">' . titulo('Faturamento × investimento × lucro por hora (acumulado)', 'Soma hora a hora ao longo do dia' . ($hoje ? '' : ' (os dias do período somados pela hora)') . '. Investimento = gasto na Meta; lucro já desconta o imposto.')
        . '<p class="legenda-grafico"><i style="background:#D97706"></i>Investimento <i style="background:#1D6FF2"></i>Faturamento <i style="background:#16A34A"></i>Lucro</p>'
        . resumo_svg_linhas([['Faturamento', '#1D6FF2', $acF], ['Investimento', '#D97706', $acG], ['Lucro', '#16A34A', $acL]], $ateHora) . '</section>';
}
