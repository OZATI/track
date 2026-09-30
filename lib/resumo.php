<?php
// Resumo: a primeira tela do painel. Os numeros do periodo (faturamento, gasto, lucro, ROI,
// margem, CPA, imposto, ticket medio, pendentes e reembolsos), o funil da Meta, o funil do
// site, vendas por pagamento, produto e fonte, e dois graficos por hora. Mesmas contas do
// gestor de anuncios (lib/gestor.php), que sao as da UTMify. Todo numero tem o (i).
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

// Funil em faixas: [rotulo => [valor, dica]]; a % e sempre sobre o primeiro passo
function resumo_funil(array $passos): string
{
    $primeiro = (float)(reset($passos)[0] ?? 0);
    $html = '<div class="funil">';
    foreach ($passos as $rotulo => [$v, $dica]) {
        $html .= '<div><span>' . com_info($rotulo, $dica) . '</span><b>' . number_format((float)$v, 0, ',', '.') . '</b>'
            . '<em>' . e(resumo_pct((float)$v, $primeiro)) . '</em>'
            . '<div class="trilho"><i style="width:' . ($primeiro > 0 ? min(100, round($v * 100 / $primeiro, 1)) : 0) . '%"></i></div></div>';
    }
    return $html . '</div>';
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
        $svg .= '<polyline fill="none" stroke="' . $cor . '" stroke-width="2" points="' . implode(' ', $pts) . '"><title>' . e($rotulo) . '</title></polyline>';
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

function resumo_render(PDO $db, string $periodo, string $de, string $ate): void
{
    $pct = gestor_imposto_pct();
    [$dia1, $dia2] = gestor_dias($periodo);
    $temMeta = (bool)meta_api_chave();
    $num = fn(float $v, int $casas = 2) => number_format($v, $casas, ',', '.');
    $tz = fuso();

    // Meta: gasto e funil no periodo
    $g = consulta($db, 'SELECT SUM(gasto) AS gasto, SUM(cliques) AS cliques, SUM(visualizacoes) AS vis, SUM(checkouts) AS ics FROM meta_gasto WHERE dia >= ? AND dia <= ?', [$dia1, $dia2])[0];
    $gasto = (int)$g['gasto'];
    $imposto = (int)round($gasto * $pct / 100);

    // Kiwify: vendas do periodo
    $fat = $aprovadas = $pendValor = $pendN = $reembValor = $reembN = 0;
    $metaIniciadas = $metaAprovadas = $siteIniciadas = $siteAprovadas = 0;
    $porPagamento = $porProduto = $porFonte = $porHora = $fatHora = [];
    $tentativas = $aprovPorMeio = [];
    $meios = ['pix' => 'Pix', 'credit_card' => 'Cartão', 'boleto' => 'Boleto'];
    foreach (consulta($db, 'SELECT * FROM vendas WHERE recebida_em >= ? AND recebida_em < ?', [$de, $ate]) as $v) {
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
            $porProduto[$v['produto'] ?: '—'] = ($porProduto[$v['produto'] ?: '—'] ?? 0) + 1;
            $quando = new DateTime($v['aprovada_em'] ?: $v['recebida_em'], new DateTimeZone('UTC'));
            $h = (int)$quando->setTimezone($tz)->format('G');
            $fatHora[$h] = ($fatHora[$h] ?? 0) + $liquido;
            if ($principal) {
                $aprovadas++;
                $porPagamento[$meio] = ($porPagamento[$meio] ?? 0) + 1;
                $aprovPorMeio[$meio] = ($aprovPorMeio[$meio] ?? 0) + 1;
                $cn = canal($v['utm_source'], $v['utm_medium'], $v['utm_term'], null, $v['utm_campaign']);
                $porFonte[$cn[1]] = ($porFonte[$cn[1]] ?? 0) + 1;
                $porHora[$h] = ($porHora[$h] ?? 0) + 1;
                $metaAprovadas += $daMeta ? 1 : 0;
                $siteAprovadas += $v['visitante'] ? 1 : 0;
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

    // Site: visitantes e cliques no checkout (t.js)
    $visitantes = (int)valor($db, 'SELECT COUNT(DISTINCT visitante) FROM eventos WHERE em >= ? AND em < ?', [$de, $ate]);
    $clicaram = (int)valor($db, "SELECT COUNT(DISTINCT visitante) FROM eventos WHERE nome = 'CliqueCheckout' AND em >= ? AND em < ?", [$de, $ate]);

    // Barra de atualizacao (mesma do gestor)
    $meta = meta_sync_estado();
    $vencida = meta_sync_vencida() || kiwify_sync_vencida();
    $k = kiwify_sync_estado();
    $partes = [];
    if ($k['ok_em']) {
        $partes[] = 'vendas atualizadas em ' . data_local($k['ok_em'], 'd/m H:i');
    }
    if ($meta['ok_em']) {
        $partes[] = 'gasto em ' . data_local($meta['ok_em'], 'd/m H:i');
    }
    echo '<div class="barra-vendas" id="sync"' . ($vencida ? ' data-sync="1"' : '') . '><span data-sync-texto>' . e(ucfirst(implode(' · ', $partes))) . '</span>'
        . '<form method="post" action="sincronizar.php"><input type="hidden" name="csrf" value="' . e(token_csrf()) . '">'
        . '<input type="hidden" name="volta" value="' . e('./?' . http_build_query(['aba' => 'geral', 'periodo' => $periodo])) . '"><button type="submit">Atualizar</button></form></div>';
    if (!$temMeta) {
        echo '<p class="aviso-meta">Sem a conta de anúncios conectada, gasto, lucro, ROI e o funil da Meta ficam zerados. Conecte na aba <a href="meta-api.php">API Meta</a>.</p>';
    }

    // Numeros
    $roi = $gasto ? ($fat - $imposto) / $gasto : null;
    echo '<div class="numeros">'
        . numero(reais($fat), 'Faturamento líquido', 'Soma do que a Kiwify repassa (depois das taxas) das vendas aprovadas no período, com order bump.')
        . numero(reais($gasto), 'Gasto com anúncios', 'Quanto a Meta cobrou pelos anúncios no período, sem o imposto.')
        . numero(reais($lucro), 'Lucro', 'Faturamento líquido − gasto − imposto da Meta (' . $num($pct) . '% sobre o gasto).', $lucro >= 0 ? 'positivo' : 'negativo')
        . numero($roi === null ? 'N/A' : $num($roi), 'ROI', '(Faturamento líquido − imposto) ÷ gasto. Acima de 1, os anúncios se pagam. Mesma conta da UTMify.', $roi === null ? '' : ($roi >= 1 ? 'positivo' : 'negativo'))
        . numero($fat ? $num($lucro * 100 / $fat, 1) . '%' : '—', 'Margem', 'Lucro ÷ faturamento líquido: quanto de cada real vendido sobra.')
        . numero($aprovadas ? reais((int)round($gasto / $aprovadas)) : 'N/A', 'CPA', 'Gasto ÷ vendas aprovadas. Order bump não conta como outra venda.')
        . numero(reais($imposto), 'Imposto da Meta', 'Impostos que a Meta cobra sobre o gasto com anúncios no Brasil: ' . $num($pct) . '%.')
        . numero($aprovadas ? reais((int)round($fat / $aprovadas)) : 'N/A', 'Ticket médio', 'Faturamento líquido ÷ vendas aprovadas (quanto cada comprador deixa, com order bump).')
        . numero((string)$aprovadas, 'Vendas aprovadas', 'Pedidos principais aprovados no período. Order bump entra no faturamento, não como outra venda.')
        . numero(reais($pendValor), 'Pendentes', $pendN . ' Pix ou boleto gerado e ainda não pago, no valor cobrado.')
        . numero(reais($reembValor), 'Reembolsadas', $reembN . ' reembolso(s) ou chargeback(s) no período, no valor cobrado.', $reembValor ? 'negativo' : '')
        . '</div>';

    // Funis (um por linha: cinco passos lado a lado precisam da largura toda)
    echo '<section class="bloco">' . titulo('Funil da Meta', 'Do clique no anúncio até a venda aprovada. Cliques, visualizações e inícios de checkout vêm da Meta; vendas, da Kiwify (só as com o ID de uma campanha).')
        . resumo_funil([
            'Cliques' => [(int)$g['cliques'], 'Cliques no link do anúncio (Meta).'],
            'Visualizações' => [(int)$g['vis'], 'Visualizações da página de destino que a Meta contou (a página carregou).'],
            'Inícios de checkout' => [(int)$g['ics'], 'InitiateCheckout contados pela Meta (checkout aberto na Kiwify).'],
            'Vendas iniciadas' => [$metaIniciadas, 'Pedidos criados na Kiwify vindos de anúncio, pagos ou não.'],
            'Vendas aprovadas' => [$metaAprovadas, 'Pedidos aprovados vindos de anúncio.'],
        ]) . '</section>';
    echo '<section class="bloco">' . titulo('Funil do site', 'Medido pelo próprio painel (t.js nas páginas), sem depender da Meta. Vale para qualquer origem.')
        . resumo_funil([
            'Visitantes' => [$visitantes, 'Aparelhos diferentes que abriram as páginas com o painel.'],
            'Clicaram no checkout' => [$clicaram, 'Visitantes que clicaram no botão de compra.'],
            'Vendas iniciadas' => [$siteIniciadas, 'Pedidos criados na Kiwify que o painel ligou a um visitante.'],
            'Vendas aprovadas' => [$siteAprovadas, 'Desses, os aprovados.'],
        ]) . '</section>';

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
    echo '<section class="bloco">' . titulo('Faturamento, investimento e lucro por hora (acumulado)', 'Soma hora a hora ao longo do dia' . ($hoje ? '' : ' (os dias do período somados pela hora)') . '. Investimento = gasto na Meta; lucro já desconta o imposto.')
        . '<p class="legenda-grafico"><i style="background:#1D6FF2"></i>Faturamento <i style="background:#D97706"></i>Investimento <i style="background:#16A34A"></i>Lucro</p>'
        . resumo_svg_linhas([['Faturamento', '#1D6FF2', $acF], ['Investimento', '#D97706', $acG], ['Lucro', '#16A34A', $acL]], $ateHora) . '</section>';
    echo '<section class="bloco">' . titulo('Vendas por horário', 'Percentual das vendas aprovadas em cada hora do dia (hora da aprovação, no horário de Brasília).')
        . resumo_svg_barras($porHora) . '</section>';

    // Pagamento, aprovacao, produto e fonte
    echo '<div class="grade">';
    echo '<section class="bloco">' . titulo('Vendas por pagamento', 'Vendas aprovadas por forma de pagamento.')
        . resumo_barras($porPagamento, fn($v) => $v . '<span>' . e(resumo_pct($v, $aprovadas)) . '</span>') . '</section>';
    $taxas = [];
    foreach (['Cartão', 'Pix', 'Boleto'] as $m) {
        if (!empty($tentativas[$m])) {
            $taxas[$m] = ($aprovPorMeio[$m] ?? 0) * 100 / $tentativas[$m];
        }
    }
    echo '<section class="bloco">' . titulo('Taxa de aprovação', 'Aprovadas ÷ pedidos criados com cada forma de pagamento (cartão recusado e Pix não pago derrubam a taxa).')
        . resumo_barras($taxas, fn($v) => e(number_format($v, 1, ',', '') . '%')) . '</section>';
    arsort($porProduto);
    echo '<section class="bloco">' . titulo('Vendas por produto', 'Pedidos aprovados de cada produto, com os order bumps (cada bump conta no seu produto).')
        . resumo_barras($porProduto, fn($v) => $v . '<span>' . e(resumo_pct($v, array_sum($porProduto))) . '</span>') . '</section>';
    arsort($porFonte);
    echo '<section class="bloco">' . titulo('Vendas por fonte', 'Vendas aprovadas pelo canal que a Kiwify gravou na etiqueta (anúncio no Instagram, no Facebook, orgânico...).')
        . resumo_barras($porFonte, fn($v) => $v . '<span>' . e(resumo_pct($v, $aprovadas)) . '</span>') . '</section>';
    echo '</div>';
}
