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
        . '<path d="' . $d . ' Z" fill="url(#grad-azul-h)"></path>' . $linhas . '</svg><div class="fluxo-pct">';
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

// Icone de cada cartao de numero, pelo nome (Resumo, Financeiro, analise diaria, Trafego e Organico)
const RESUMO_ICONES = [
    'Faturamento líquido' => 'carteira', 'Faturamento bruto' => 'carteira', 'Faturamento' => 'carteira', 'Entradas' => 'carteira',
    'Gasto com anúncios' => 'meta', 'Gasto' => 'meta', 'Anúncios' => 'meta', 'Imposto da Meta' => 'conferencia',
    'ROI geral' => 'grafico', 'ROI rastreado' => 'grafico', 'ROI' => 'grafico', 'Lucro' => 'vendas', 'Saldo' => 'vendas', 'Margem' => 'atividade',
    'CPA' => 'campanha', 'Custo por IC' => 'campanha', 'CPC' => 'campanha', 'CPM' => 'campanha', 'CTR' => 'link',
    'Ticket médio' => 'usuario', 'Vendas pendentes' => 'calendario', 'Vendas reembolsadas' => 'restaurar', 'Taxas da Kiwify' => 'lista', 'Outras despesas' => 'lista',
    'Compradores' => 'usuarios', 'Visitantes' => 'visitantes', 'Clicaram no checkout' => 'eventos', 'Vendas aprovadas' => 'ok',
    'Alcance' => 'visitantes', 'Frequência' => 'repetir', 'Hook rate' => 'foco', 'Hold rate' => 'atividade',
    'Mulheres' => 'usuario', 'Homens' => 'usuario', 'Faixa de idade principal' => 'calendario', 'Cidade principal' => 'direto',
];

// Cartao de numero (como os da UTMify): nome e (i) em cima, o icone no canto e o valor grande
// embaixo
function resumo_cartao(string $valor, string $rotulo, string $dica, string $classe = '', string $sub = '', string $larg = 'c3'): string
{
    $ico = RESUMO_ICONES[$rotulo] ?? '';
    return '<div class="rc ' . $larg . '"><div class="rc-cab">' . ($ico !== ''
            ? '<span class="rc-tit"><span>' . e($rotulo) . '</span>' . info($dica) . '</span><span class="kpi-ico" aria-hidden="true">' . icone($ico, 15) . '</span>'
            : '<span>' . e($rotulo) . '</span>' . info($dica)) . '</div>'
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
            . '" stroke-dashoffset="' . round($ini, 3) . '"' . dica_attr((string)$rot, [$n . ' · ' . number_format($p, 0) . '%']) . '></circle>';
        $ini -= $p;
    }
    $svg .= '<text x="21" y="19.5" class="rosca-rot">' . e($centro) . '</text><text x="21" y="26" class="rosca-num">' . $total . '</text></svg>';
    $leg = '<ul class="rosca-leg">';
    foreach ($partes as $rot => $n) {
        if (!$n && $total) {
            continue; // categoria zerada nao entra na legenda ("Outros 0 0%")
        }
        $leg .= '<li><i style="background:' . e($cores[$rot] ?? '#9CA3AF') . '"></i>' . e($rot) . ' <b>' . $n . '</b> <span class="suave">' . e($total ? number_format($n * 100 / $total, 0) . '%' : '—') . '</span></li>';
    }
    return '<div class="rosca-caixa">' . $svg . $leg . '</ul></div>';
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

// Os numeros do periodo (os do Resumo e do painel editavel, lib/painel.php): vendas da Kiwify com
// os filtros de produto e fonte, gasto e funil da Meta, visitas do painel e as series por hora.
// Devolve um array com os nomes das variaveis (o Resumo faz extract()).
function resumo_dados(PDO $db, string $periodo, string $de, string $ate, array $fCanais = []): array
{
    $pct = gestor_imposto_pct();
    [$dia1, $dia2] = gestor_dias($periodo);
    $tz = fuso();

    // Meta: gasto e funil no periodo
    $g = consulta($db, 'SELECT SUM(gasto) AS gasto, SUM(cliques) AS cliques, SUM(visualizacoes) AS vis, SUM(checkouts) AS ics FROM meta_gasto WHERE dia >= ? AND dia <= ?', [$dia1, $dia2])[0];
    $gasto = (int)$g['gasto'];
    $imposto = (int)round($gasto * $pct / 100);

    // Kiwify: vendas do periodo
    $fat = $fatBruto = $fatMeta = $aprovadas = $pendValor = $pendN = $reembValor = $reembN = $cbValor = $cbN = 0;
    $foraTipo = ['Orgânico' => 0, 'Anúncio compartilhado' => 0, 'Direto / sem origem' => 0, 'Outras origens' => 0];
    $metaIniciadas = $metaAprovadas = $siteIniciadas = $siteAprovadas = $comOrigem = $semOrigem = 0;
    $porPagamento = ['Pix' => 0, 'Cartão' => 0, 'Boleto' => 0, 'Outros' => 0];
    $porProduto = $fatProduto = $porTermo = $porCanal = $porHora = $fatHora = [];
    $porSemana = ['Seg' => 0, 'Ter' => 0, 'Qua' => 0, 'Qui' => 0, 'Sex' => 0, 'Sáb' => 0, 'Dom' => 0];
    $tentativas = $aprovPorMeio = [];
    $meios = ['pix' => 'Pix', 'credit_card' => 'Cartão', 'boleto' => 'Boleto'];
    foreach (consulta($db, 'SELECT * FROM vendas WHERE recebida_em >= ? AND recebida_em < ?', [$de, $ate]) as $v) {
        $cn = canal($v['utm_source'], $v['utm_medium'], $v['utm_term'], null, $v['utm_campaign']);
        if (!venda_no_filtro($v) || ($fCanais && !in_array($cn[0], $fCanais, true))) {
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
            $fatBruto += (int)($v['valor'] ?? 0);
            $fatMeta += $daMeta ? $liquido : 0;
            $porProduto[$v['produto'] ?: '—'] = ($porProduto[$v['produto'] ?: '—'] ?? 0) + 1;
            $fatProduto[$v['produto'] ?: '—'] = ($fatProduto[$v['produto'] ?: '—'] ?? 0) + $liquido;
            $quando = (new DateTime($v['aprovada_em'] ?: $v['recebida_em'], new DateTimeZone('UTC')))->setTimezone($tz);
            $h = (int)$quando->format('G');
            $fatHora[$h] = ($fatHora[$h] ?? 0) + $liquido;
            if ($principal) {
                $aprovadas++;
                $porPagamento[$meio]++;
                $aprovPorMeio[$meio] = ($aprovPorMeio[$meio] ?? 0) + 1;
                $porCanal[$cn[0]] = ['canal' => $cn, 'n' => ($porCanal[$cn[0]]['n'] ?? 0) + 1];
                $porHora[$h] = ($porHora[$h] ?? 0) + 1;
                $termo = trim((string)$v['utm_term']) !== '' && strpos((string)$v['utm_term'], '{') === false ? str_replace('_', ' ', (string)$v['utm_term']) : 'Sem posicionamento';
                $porTermo[$termo] = ($porTermo[$termo] ?? 0) + 1;
                $porSemana[array_keys($porSemana)[(int)$quando->format('N') - 1]]++;
                $metaAprovadas += $daMeta ? 1 : 0;
                $siteAprovadas += $v['visitante'] ? 1 : 0;
                $cn[0] === 'direto' ? $semOrigem++ : $comOrigem++;
                // Fora de anuncio (sem o ID de uma campanha), pelo tipo: o grafico de pizza
                if (!$daMeta) {
                    $tipo = ['organico' => 'Orgânico', 'compartilhado' => 'Anúncio compartilhado', 'direto' => 'Direto / sem origem'][gestor_motivo_fora($v)[0][0]] ?? 'Outras origens';
                    $foraTipo[$tipo]++;
                }
            }
        } elseif ($principal && $sit === 'Aguardando pagamento') {
            $pendValor += (int)$v['valor'];
            $pendN++;
        } elseif (in_array($sit, ['Reembolsada', 'Chargeback'], true)) {
            $reembValor += (int)$v['valor'];
            $reembN += $principal ? 1 : 0;
            if ($sit === 'Chargeback') {
                $cbValor += (int)$v['valor'];
                $cbN += $principal ? 1 : 0;
            }
        }
    }
    $lucro = $fat - $gasto - $imposto;
    // ROI geral: tudo o que voltou (anuncio, organico, direto, rastreado ou nao), com a conta da
    // UTMify e do gestor: (faturamento - imposto da Meta) / gasto. O rastreado usa so as vendas
    // com o ID de uma campanha, para ver quanto do retorno o painel liga aos anuncios.
    $investido = $gasto + $imposto;
    $roi = roi_campanha($fat, $gasto, $imposto);
    $roiMeta = roi_campanha($fatMeta, $gasto, $imposto);

    // Site: visitantes e cliques no checkout (t.js)
    $visitantes = (int)valor($db, 'SELECT COUNT(DISTINCT visitante) FROM eventos WHERE em >= ? AND em < ?', [$de, $ate]);
    $clicaram = (int)valor($db, "SELECT COUNT(DISTINCT visitante) FROM eventos WHERE nome = 'CliqueCheckout' AND em >= ? AND em < ?", [$de, $ate]);
    // Leads: quem clicou no WhatsApp (pessoas diferentes) e os cliques
    $leads = (int)valor($db, "SELECT COUNT(DISTINCT visitante) FROM eventos WHERE nome = 'WhatsApp' AND em >= ? AND em < ?", [$de, $ate]);
    $conversas = (int)valor($db, "SELECT COUNT(*) FROM eventos WHERE nome = 'WhatsApp' AND em >= ? AND em < ?", [$de, $ate]);

    $gastoHora = [];
    foreach (consulta($db, 'SELECT hora, SUM(gasto) AS g FROM meta_gasto_hora WHERE dia >= ? AND dia <= ? GROUP BY hora', [$dia1, $dia2]) as $r) {
        $gastoHora[(int)$r['hora']] = (int)$r['g'];
    }
    $acF = $acG = $acL = $lucroHora = [];
    $f = $gg = 0;
    for ($h = 0; $h < 24; $h++) {
        $f += $fatHora[$h] ?? 0;
        $gg += $gastoHora[$h] ?? 0;
        $acF[$h] = $f;
        $acG[$h] = $gg;
        $acL[$h] = $f - $gg - (int)round($gg * $pct / 100);
        $lucroHora[$h] = ($fatHora[$h] ?? 0) - ($gastoHora[$h] ?? 0) - (int)round(($gastoHora[$h] ?? 0) * $pct / 100);
    }
    return compact('pct', 'dia1', 'dia2', 'g', 'gasto', 'imposto', 'fat', 'fatBruto', 'fatMeta', 'aprovadas', 'pendValor', 'pendN', 'reembValor', 'reembN', 'cbValor', 'cbN', 'foraTipo', 'metaIniciadas', 'metaAprovadas', 'siteIniciadas', 'siteAprovadas', 'comOrigem', 'semOrigem', 'porPagamento', 'porProduto', 'fatProduto', 'porTermo', 'porCanal', 'porHora', 'fatHora', 'porSemana', 'tentativas', 'aprovPorMeio', 'lucro', 'investido', 'roi', 'roiMeta', 'visitantes', 'clicaram', 'leads', 'conversas', 'gastoHora', 'acF', 'acG', 'acL', 'lucroHora');
}

function resumo_render(PDO $db, string $periodo, string $de, string $ate): void
{
    require_once __DIR__ . '/painel.php';
    $temMeta = (bool)meta_api_chave();

    // Filtros das vendas: produto (no topo, vale para todas as telas) e fonte de trafego (aqui,
    // como na UTMify). O gasto e o funil da Meta sao da conta toda (a Meta nao divide o gasto
    // por produto nem por fonte).
    $fProduto = implode(', ', produto_filtro());
    $canaisNome = ['instagram' => 'Instagram · anúncio', 'facebook' => 'Facebook · anúncio', 'meta' => 'Anúncio sem posicionamento',
        'compartilhado' => 'Anúncio compartilhado', 'google' => 'Google · anúncio', 'organico' => 'Orgânico', 'outros' => 'Outras origens', 'direto' => 'Direto / sem origem'];
    // Fonte de trafego: varias de uma vez (canal[]); a forma antiga (canal=organico) ainda vale
    $fCanais = array_values(array_unique(array_filter(array_map('strval', (array)($_GET['canal'] ?? [])), fn($c) => isset($canaisNome[$c]))));

    // Montando a tela (o lapis da barra lateral): so a barra de edicao e os blocos
    if (grade_editando()) {
        grade_render(resumo_grade(), resumo_ctx($db, $periodo, $de, $ate, $fCanais));
        return;
    }

    // Cabecalho: titulo, ultima atualizacao, Atualizar e os filtros
    $meta = meta_sync_estado();
    $k = kiwify_sync_estado();
    $vencida = meta_sync_vencida() || kiwify_sync_vencida();
    $datas = array_filter([$k['ok_em'], $meta['ok_em']]);
    $quando = $datas ? resumo_ha(min($datas)) : 'ainda não atualizado';
    echo '<section class="bloco resumo-cab"><div class="resumo-topo"><h2>' . com_info('Resumo', 'Os números do período escolhido no topo. As vendas vêm da Kiwify (webhook e API), o gasto e o funil da Meta, e as visitas do próprio painel. O lápis da barra lateral monta a tela: arraste, aumente, tire e acrescente blocos. Cada pessoa tem a sua, uma para o computador e outra para o celular.') . '</h2>'
        . '<div class="barra-vendas" id="sync"' . ($vencida ? ' data-sync="1"' : '') . '><span data-sync-texto>' . e(ucfirst($quando)) . '</span>'
        . botao_atualizar('./?' . http_build_query(array_filter(['aba' => 'geral', 'periodo' => $periodo, 'canal' => $fCanais]))) . '</div></div>'
        . '<form class="resumo-filtros" method="get" action="./" data-auto><input type="hidden" name="aba" value="geral"><input type="hidden" name="periodo" value="' . e($periodo) . '">'
        . '<div class="campo"><span>' . com_info('Fonte de tráfego', 'Canal da venda, pela etiqueta que a Kiwify gravou: anúncio no Instagram ou no Facebook, orgânico, direto... Marque uma ou mais; Todas soma tudo. Aplica ao fechar a lista. O produto se escolhe no topo e vale para todas as telas.') . '</span>'
        . filtro_multi('canal', $canaisNome, $fCanais, 'Todas as fontes', ['fonte', 'fontes']) . '</div>'
        . '<noscript><button type="submit" class="discreto neutro">Filtrar</button></noscript></form>';
    if ($fProduto !== '' || $fCanais) {
        $nomesCanais = implode(', ', array_map(fn($c) => $canaisNome[$c], $fCanais));
        echo '<p class="suave resumo-aviso">Filtro ligado: faturamento, vendas e lucro contam só ' . e(trim(($fProduto !== '' ? $fProduto : '') . ($fProduto !== '' && $fCanais ? ' · ' : '') . $nomesCanais))
            . '. O gasto e o funil da Meta continuam os da conta toda. <a href="' . e('./?' . http_build_query(['aba' => 'geral', 'periodo' => $periodo, 'produto' => ['']])) . '">Tirar o filtro</a></p>';
    }
    echo '</section>';
    if (!$temMeta) {
        echo '<p class="aviso-meta">Sem a conta de anúncios conectada, gasto, lucro, ROI e o funil da Meta ficam zerados. Conecte em <a href="meta-api.php">Integrações → Meta Ads</a>.</p>';
    }

    // Os blocos: a grade que cada pessoa monta (lib/grade.php e lib/painel.php; o lapis da barra
    // lateral abre o modo de montar)
    grade_render(resumo_grade(), resumo_ctx($db, $periodo, $de, $ate, $fCanais));
}
