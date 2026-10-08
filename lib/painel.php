<?php
// Resumo montavel: os numeros e graficos do periodo em blocos que cada pessoa monta como quiser
// (o lapis da barra lateral), no estilo do Power BI e do modo de edicao da UTMify. O motor das telas
// montaveis e o lib/grade.php; aqui fica o que e do Resumo:
//
// - O catalogo de blocos (resumo_blocos): titulo, categoria, desenho, o (i) com a conta, o tamanho
//   na grade (largura e altura, com o minimo e o maximo), o icone e de que integracao depende. Os
//   numeros saem de resumo_dados() (as mesmas contas do gestor) e as despesas, do Financeiro.
// - Comparacao: cada numero mostra a variacao contra o periodo anterior do mesmo tamanho (7 dias
//   contra os 7 de antes, este mes contra os mesmos dias do mes passado) e a linhazinha do periodo.
// - Integracoes: cada bloco diz de onde vem (resumo_fontes: API Meta, t.js nas paginas, despesas do
//   Financeiro, eventos da VSL). Numa conta nova, sem a fonte ligada, o bloco diz o que falta e leva
//   para onde liga, em vez de mostrar zero como se fosse resultado.
// - O layout padrao (RESUMO_PADRAO) segue o Resumo da UTMify, com os graficos novos.
//
// O Painel (aba propria, 07/10/2026) virou este Resumo: o layout salvo nele continua valendo.

require_once __DIR__ . '/resumo.php';
require_once __DIR__ . '/financeiro.php';
require_once __DIR__ . '/grade.php';
require_once __DIR__ . '/graficos.php';

const RESUMO_CATEGORIAS = ['geral' => 'Números', 'graficos' => 'Gráficos', 'impostos' => 'Impostos e taxas', 'site' => 'WhatsApp e site'];

// Layout padrao do computador: [tipo, x, y, largura, altura] na grade de 12 colunas
const RESUMO_PADRAO = [
    ['NetRevenue', 0, 0, 3, 1], ['Spend', 3, 0, 3, 1], ['Roi', 6, 0, 2, 1], ['RoiTracked', 8, 0, 2, 1], ['Profit', 10, 0, 2, 1],
    ['SalesByPayment', 0, 1, 4, 3], ['ProfitMargin', 4, 1, 2, 1], ['Cpa', 6, 1, 2, 1], ['MetaAdsTax', 8, 1, 2, 1], ['Arpu', 10, 1, 2, 1],
    ['PendingCommission', 4, 2, 4, 1], ['RefundedCommission', 8, 2, 4, 1],
    ['GrossRevenue', 4, 3, 4, 1], ['Fees', 8, 3, 4, 1],
    ['RevenueByDay', 0, 4, 8, 3], ['RoiGauge', 8, 4, 4, 3],
    ['ApprovalRate', 0, 7, 4, 2], ['SalesByProduct', 4, 7, 4, 2], ['SalesBySource', 8, 7, 4, 2],
    ['ConversionFunnel', 0, 9, 8, 3], ['SalesByDayOfWeek', 8, 9, 4, 3],
    ['SiteFunnel', 0, 12, 8, 3], ['TrackingQuality', 8, 12, 4, 3],
    ['ProfitByDay', 0, 15, 8, 3], ['OutsideAds', 8, 15, 4, 3],
    ['SalesHeatmap', 0, 18, 12, 3],
    ['VslFunnel', 0, 21, 12, 4],
    ['SalesByHour', 0, 25, 12, 3],
    ['RevenueInvestmentProfitByHour', 0, 28, 12, 3],
];

// tipo => [titulo, categoria, desenho (numero ou grafico), dica, [w, h, minW, minH, maxW, maxH], icone,
// fontes de que depende]. As vendas (webhook da Kiwify) valem para todos e nao entram na lista.
function resumo_blocos(): array
{
    static $w = null;
    if ($w !== null) {
        return $w;
    }
    $fontes = [
        'meta' => ['Spend', 'Roi', 'RoiTracked', 'Roas', 'Profit', 'NetProfit', 'ProfitMargin', 'Cpa', 'MetaAdsTax', 'TotalTax', 'ConversionFunnel', 'ProfitByHour', 'RevenueInvestmentProfitByHour', 'CostPerLead', 'ProfitByDay', 'RoiGauge'],
        'site' => ['SiteFunnel', 'Visitors', 'CheckoutClicks', 'LeadCount', 'Conversations', 'CostPerLead'],
        'financeiro' => ['CustomSpendings', 'NetProfit'],
        'vsl' => ['VslFunnel'],
    ];
    $p = number_format(gestor_imposto_pct(), 2, ',', '.') . '%';
    $num = [3, 1, 2, 1, 6, 1];
    $pequeno = [2, 1, 2, 1, 4, 1];
    $lista = [4, 3, 3, 2, 12, 6];
    $graf = [8, 3, 4, 2, 12, 6];
    $w = [
        // Numeros
        'NetRevenue' => ['Faturamento líquido', 'geral', 'numero', 'O que a Kiwify repassa (depois das taxas) das vendas aprovadas no período, com order bump. A seta compara com o período anterior do mesmo tamanho.', $num, 'carteira'],
        'GrossRevenue' => ['Faturamento bruto', 'geral', 'numero', 'Valor cobrado do comprador nas vendas aprovadas, antes das taxas da Kiwify, com order bump. Bruto − taxas = o faturamento líquido, que é a base do lucro e do ROI.', $num, 'carteira'],
        'Spend' => ['Gasto com anúncios', 'geral', 'numero', 'Quanto a Meta cobrou pelos anúncios no período, sem o imposto.', $num, 'meta'],
        'Roi' => ['ROI geral', 'geral', 'numero', '(Faturamento líquido − imposto da Meta) ÷ gasto, a conta da UTMify e do gestor, com todas as vendas aprovadas (anúncio, orgânico e direto). Vermelho abaixo de 1, laranja de 1 até 2, verde de 2 para cima. O ROI com as outras despesas da empresa fica no DRE.', $pequeno, 'grafico'],
        'RoiTracked' => ['ROI rastreado', 'geral', 'numero', 'Só as vendas com o ID de uma campanha da Meta: (faturamento delas − imposto) ÷ gasto. A diferença para o ROI geral é o retorno que veio de orgânico, direto ou venda sem etiqueta.', $pequeno, 'grafico'],
        'Roas' => ['ROAS', 'geral', 'numero', 'Faturamento líquido ÷ gasto, sem o imposto (o retorno sobre o gasto que a Meta mostra; a mesma conta da coluna do gestor).', $pequeno, 'grafico'],
        'Profit' => ['Lucro', 'geral', 'numero', 'Faturamento líquido − gasto − imposto da Meta (' . $p . ' sobre o gasto).', $num, 'vendas'],
        'NetProfit' => ['Lucro depois das despesas', 'geral', 'numero', 'Lucro − as outras despesas da empresa no período (as do DRE). É o resultado do DRE.', $num, 'vendas'],
        'ProfitMargin' => ['Margem', 'geral', 'numero', 'Lucro ÷ (faturamento líquido − imposto da Meta), como na UTMify e na planilha: quanto de cada real que voltou sobra.', $pequeno, 'atividade'],
        'Cpa' => ['CPA', 'geral', 'numero', 'Gasto ÷ vendas aprovadas. Order bump não conta como outra venda.', $pequeno, 'campanha'],
        'Arpu' => ['Ticket médio', 'geral', 'numero', 'Faturamento líquido ÷ vendas aprovadas (quanto cada comprador deixa, com order bump). Na UTMify, ARPU. O painel não guarda quem comprou (LGPD), então conta por venda.', $pequeno, 'usuario'],
        'ApprovedSales' => ['Vendas aprovadas', 'geral', 'numero', 'Vendas aprovadas no período, sem contar o order bump.', $num, 'ok'],
        'PendingCommission' => ['Vendas pendentes', 'geral', 'numero', 'Pix ou boleto gerado e ainda não pago, no valor cobrado.', $num, 'calendario'],
        'RefundedCommission' => ['Vendas reembolsadas', 'geral', 'numero', 'Reembolsos no período, no valor cobrado (sem os chargebacks, que têm o bloco deles).', $num, 'restaurar'],
        'SalesChargeback' => ['Chargebacks', 'geral', 'numero', 'Contestações no cartão no período, no valor cobrado.', $num, 'restaurar'],
        'RefundRate' => ['Taxa de reembolso', 'geral', 'numero', 'Vendas reembolsadas ÷ (aprovadas + reembolsadas e chargebacks) no período.', $pequeno, 'restaurar'],
        'ChargebackRate' => ['Taxa de chargeback', 'geral', 'numero', 'Chargebacks ÷ (aprovadas + reembolsadas e chargebacks) no período.', $pequeno, 'restaurar'],
        'CustomSpendings' => ['Outras despesas', 'geral', 'numero', 'Despesas da empresa cadastradas no DRE que caem no período (as pausadas não contam).', $num, 'lista'],
        // Graficos
        'RevenueByDay' => ['Faturamento × investimento por dia', 'graficos', 'grafico', 'Faturamento líquido das vendas aprovadas e o investimento (gasto na Meta + imposto) em cada dia do período. Período de um dia: hora a hora. Mais de 3 meses: mês a mês.', $graf, 'grafico'],
        'ProfitByDay' => ['Lucro por dia', 'graficos', 'grafico', 'Faturamento líquido − gasto − imposto da Meta em cada dia do período: verde ganhou, vermelho perdeu. Período de um dia: hora a hora.', $graf, 'vendas'],
        'RoiGauge' => ['ROI no mostrador', 'graficos', 'grafico', 'O ROI geral num mostrador de 0 a 4: vermelho abaixo de 1 (o anúncio não se paga), laranja de 1 até 2, verde de 2 para cima.', [4, 3, 3, 2, 6, 4], 'grafico'],
        'SalesHeatmap' => ['Mapa de calor das vendas', 'graficos', 'grafico', 'Vendas aprovadas (sem order bump) por dia da semana e hora da aprovação, no horário de Brasília. Quanto mais forte a casa, mais vendas: mostra quando o público compra, para concentrar o orçamento.', [12, 3, 6, 3, 12, 5], 'calendario'],
        'SalesByPayment' => ['Vendas por pagamento', 'graficos', 'grafico', 'Vendas aprovadas (sem contar order bump) por forma de pagamento.', [4, 3, 3, 3, 12, 6], 'carteira'],
        'SalesByProduct' => ['Vendas por produto', 'graficos', 'grafico', 'Pedidos aprovados de cada produto, com os order bumps (cada bump conta no seu produto).', $lista, 'lista'],
        'RevenueByProduct' => ['Faturamento por produto', 'graficos', 'grafico', 'Faturamento líquido de cada produto nas vendas aprovadas, e a fatia de cada um no total.', $lista, 'carteira'],
        'SalesBySource' => ['Vendas por canal', 'graficos', 'grafico', 'Vendas aprovadas pelo canal que o painel identificou na etiqueta (anúncio no Instagram ou no Facebook, orgânico, direto...).', $lista, 'trafego'],
        'SalesByPlacement' => ['Vendas por posicionamento', 'graficos', 'grafico', 'Vendas aprovadas pelo posicionamento do anúncio (utm_term: Feed, Reels, Stories...).', $lista, 'anuncio'],
        'ApprovalRate' => ['Taxa de aprovação', 'graficos', 'grafico', 'Aprovadas ÷ pedidos criados com cada forma de pagamento (cartão recusado e Pix não pago derrubam a taxa).', $lista, 'ok'],
        'OutsideAds' => ['Vendas fora de anúncio', 'graficos', 'grafico', 'Vendas aprovadas sem o ID de uma campanha da Meta (a UTMify chama de não trackeadas), pelo tipo: orgânico, anúncio compartilhado, direto e outras origens.', [4, 3, 3, 3, 12, 6], 'folha'],
        'TrackingQuality' => ['Qualidade do rastreio', 'graficos', 'grafico', 'Quanto das vendas aprovadas o painel conseguiu explicar: com origem, ligadas a uma campanha, ligadas a um visitante e sem origem. Quanto maior, mais dá para confiar no ROI rastreado.', $lista, 'conferencia'],
        'ConversionFunnel' => ['Funil da Meta', 'graficos', 'grafico', 'Do clique no anúncio até a venda aprovada. Cliques, visualizações e inícios de checkout vêm da Meta; vendas, da Kiwify (só as com o ID de uma campanha).', [8, 3, 4, 3, 12, 6], 'meta'],
        'SiteFunnel' => ['Funil do site', 'graficos', 'grafico', 'Medido pelo próprio painel (t.js nas páginas), sem depender da Meta: visitantes, cliques no checkout, vendas iniciadas e aprovadas.', [8, 3, 4, 3, 12, 6], 'visitantes'],
        'VslFunnel' => ['Funil da VSL', 'graficos', 'grafico', 'Retenção do vídeo e conversão em vendas nas páginas com VSL (eventos VSL_Play, VSL_50 e VSL_Pitch do t.js): deu play, metade, oferta, clique no checkout e compra.', [12, 4, 6, 3, 12, 8], 'atividade'],
        'SalesByDayOfWeek' => ['Vendas por dia da semana', 'graficos', 'grafico', 'Vendas aprovadas em cada dia da semana (dia da aprovação, horário de Brasília), em % do período.', [4, 3, 3, 3, 12, 6], 'calendario'],
        'SalesByHour' => ['Vendas por horário', 'graficos', 'grafico', 'Percentual das vendas aprovadas em cada hora do dia (hora da aprovação, no horário de Brasília).', [12, 3, 4, 3, 12, 6], 'grafico'],
        'ProfitByHour' => ['Lucro por horário', 'graficos', 'grafico', 'Faturamento − gasto − imposto da Meta em cada hora do dia (os dias do período somados pela hora): verde ganhou, vermelho perdeu. Mostra os horários em que o anúncio só gasta.', [12, 3, 4, 3, 12, 6], 'vendas'],
        'RevenueInvestmentProfitByHour' => ['Faturamento × investimento × lucro por hora', 'graficos', 'grafico', 'Soma hora a hora ao longo do dia (os dias do período somados pela hora). Investimento = gasto na Meta; lucro já desconta o imposto.', [12, 4, 4, 3, 12, 6], 'grafico'],
        // Impostos e taxas
        'MetaAdsTax' => ['Imposto da Meta', 'impostos', 'numero', 'Impostos que a Meta cobra sobre o gasto com anúncios no Brasil: ' . $p . '.', $pequeno, 'conferencia'],
        'Fees' => ['Taxas da Kiwify', 'impostos', 'numero', 'Faturamento bruto − líquido: o que a Kiwify ficou de taxa nas vendas aprovadas. O imposto da Meta (' . $p . ' do gasto) é outra coisa: conta como custo do anúncio.', $num, 'lista'],
        'TotalTax' => ['Imposto e taxas', 'impostos', 'numero', 'Imposto da Meta + taxas da Kiwify no período.', $num, 'conferencia'],
        // WhatsApp e site
        'LeadCount' => ['Leads (WhatsApp)', 'site', 'numero', 'Pessoas que clicaram no WhatsApp nas páginas com o painel (t.js).', $num, 'eventos'],
        'CostPerLead' => ['Custo por lead', 'site', 'numero', 'Gasto com anúncios ÷ pessoas que clicaram no WhatsApp.', $num, 'campanha'],
        'Conversations' => ['Cliques no WhatsApp', 'site', 'numero', 'Cliques no botão ou link do WhatsApp (todos, mesmo da mesma pessoa).', $pequeno, 'eventos'],
        'Visitors' => ['Visitantes', 'site', 'numero', 'Aparelhos diferentes que abriram as páginas com o painel.', $num, 'visitantes'],
        'CheckoutClicks' => ['Clicaram no checkout', 'site', 'numero', 'Visitantes que clicaram no botão de compra, e a % dos visitantes.', $num, 'eventos'],
    ];
    foreach ($w as $t => $def) {
        $w[$t][6] = array_keys(array_filter($fontes, fn($lista) => in_array($t, $lista, true)));
    }
    return $w;
}

// O que cada fonte precisa estar ligado, e onde ligar: [ligada, o que falta, link, nome na lista]
function resumo_fontes(): array
{
    static $f = null;
    if ($f !== null) {
        return $f;
    }
    $db = track_db();
    $f = [
        'meta' => [(bool)meta_api_chave(), 'Conecte a conta de anúncios', 'meta-api.php', 'API Meta'],
        'site' => [(bool)$db->query('SELECT 1 FROM eventos LIMIT 1')->fetchColumn(), 'Ponha o t.js nas páginas', '', 't.js nas páginas'],
        'financeiro' => [(bool)$db->query('SELECT 1 FROM gastos LIMIT 1')->fetchColumn(), 'Cadastre as despesas', usuario_pode('financeiro') ? 'financeiro.php' : '', 'despesas do DRE'],
        'vsl' => [(bool)$db->query("SELECT 1 FROM eventos WHERE nome = 'VSL_Play' LIMIT 1")->fetchColumn(), 'Mande os eventos da VSL pelo t.js', '', 'eventos da VSL'],
    ];
    return $f;
}

// A tela para o motor (lib/grade.php)
function resumo_grade(): array
{
    return [
        'id' => 'resumo',
        'titulo' => 'o Resumo',
        'acesso' => 'utm',
        'categorias' => RESUMO_CATEGORIAS,
        'blocos' => resumo_blocos(),
        'padrao' => RESUMO_PADRAO,
        'fontes' => resumo_fontes(),
        'desenhar' => 'resumo_bloco',
    ];
}

// Despesas do Financeiro que caem no periodo (em "Tudo", de 2020 ate hoje)
function painel_despesas(PDO $db, string $dia1, string $dia2): int
{
    $hoje = (new DateTime('today', fuso()))->format('Y-m-d');
    $d1 = max($dia1, '2020-01-01');
    $d2 = min($dia2, $hoje);
    $total = 0;
    foreach (fin_gastos($db) as $g) {
        if ((int)($g['ativo'] ?? 1)) {
            $total += count(fin_ocorrencias($g, $d1, $d2)) * (int)$g['valor'];
        }
    }
    return $total;
}

// As series do periodo, dia a dia (mes a mes acima de 3 meses), e o mapa de calor das vendas:
// [chaves, fat, bruto, investimento, lucro, vendas, calor[dia da semana 0-6][hora]]
function resumo_series(PDO $db, string $periodo, string $de, string $ate, array $fCanais = []): array
{
    $tz = fuso();
    $hoje = (new DateTime('today', $tz))->format('Y-m-d');
    [$dia1, $dia2] = gestor_dias($periodo);
    $dia2 = min($dia2, $hoje);
    if ($periodo === 'tudo') {
        $prim = array_filter([(string)valor($db, 'SELECT MIN(dia) FROM meta_gasto', []), ($v = valor($db, 'SELECT MIN(recebida_em) FROM vendas', [])) ? data_local((string)$v, 'Y-m-d') : '']);
        $dia1 = $prim ? min($prim) : $hoje;
    }
    $dia1 = min($dia1, $dia2);
    $porMes = (int)(new DateTime($dia1))->diff(new DateTime($dia2))->days > 92;
    $fmt = $porMes ? 'Y-m' : 'Y-m-d';
    $vazio = [];
    for ($d = new DateTime($dia1); $d->format('Y-m-d') <= $dia2; $d->modify('+1 day')) {
        $vazio[$d->format($fmt)] = 0;
    }
    $fat = $bruto = $gasto = $vendas = $vazio;
    $calor = array_fill(0, 7, array_fill(0, 24, 0));
    $primeira = array_key_first($vazio);
    $ultima = array_key_last($vazio);
    foreach (consulta($db, 'SELECT * FROM vendas WHERE recebida_em >= ? AND recebida_em < ?', [$de, $ate]) as $v) {
        $cn = canal($v['utm_source'], $v['utm_medium'], $v['utm_term'], null, $v['utm_campaign']);
        if (!aprovada($v) || !venda_no_filtro($v) || ($fCanais && !in_array($cn[0], $fCanais, true))) {
            continue;
        }
        $quando = (new DateTime($v['aprovada_em'] ?: $v['recebida_em'], new DateTimeZone('UTC')))->setTimezone($tz);
        $k = $quando->format($fmt);
        $k = isset($vazio[$k]) ? $k : ($k < $primeira ? $primeira : $ultima); // aprovada depois do fim do periodo
        $fat[$k] += (int)($v['valor_liquido'] ?? $v['valor'] ?? 0);
        $bruto[$k] += (int)($v['valor'] ?? 0);
        if (!eh_bump($v)) {
            $vendas[$k]++;
            $calor[(int)$quando->format('N') - 1][(int)$quando->format('G')]++;
        }
    }
    foreach (consulta($db, 'SELECT dia, SUM(gasto) AS g FROM meta_gasto WHERE dia >= ? AND dia <= ? GROUP BY dia', [$dia1, $dia2]) as $r) {
        $k = $porMes ? substr($r['dia'], 0, 7) : $r['dia'];
        if (isset($gasto[$k])) {
            $gasto[$k] += (int)$r['g'];
        }
    }
    $pct = gestor_imposto_pct();
    $invest = $lucro = [];
    foreach ($vazio as $k => $_) {
        $invest[$k] = $gasto[$k] + (int)round($gasto[$k] * $pct / 100);
        $lucro[$k] = $fat[$k] - $invest[$k];
    }
    return ['chaves' => array_keys($vazio), 'fat' => array_values($fat), 'bruto' => array_values($bruto), 'gasto' => array_values($gasto),
        'invest' => array_values($invest), 'lucro' => array_values($lucro), 'vendas' => array_values($vendas), 'calor' => $calor];
}

// Os numeros do Resumo para os blocos: resumo_dados + despesas, o periodo anterior e as series
function resumo_ctx(PDO $db, string $periodo, string $de, string $ate, array $fCanais = []): array
{
    $d = resumo_dados($db, $periodo, $de, $ate, $fCanais);
    $d['db'] = $db;
    $d['periodo'] = $periodo;
    $d['de'] = $de;
    $d['ate'] = $ate;
    $d['despesas'] = painel_despesas($db, $d['dia1'], $d['dia2']);
    $d['ateHora'] = $periodo === 'hoje' ? (int)(new DateTime('now', fuso()))->format('G') : 23;
    $d['serie'] = resumo_series($db, $periodo, $de, $ate, $fCanais);
    // Um dia so: as series viram hora a hora (ate a hora de agora, se for hoje)
    if (count($d['serie']['chaves']) === 1) {
        $pct = $d['pct'];
        $horas = range(0, $d['ateHora']);
        $inv = fn(int $h) => ($d['gastoHora'][$h] ?? 0) + (int)round(($d['gastoHora'][$h] ?? 0) * $pct / 100);
        $d['serie'] = ['chaves' => array_map(fn($h) => sprintf('%02dh', $h), $horas), 'fat' => array_map(fn($h) => (int)($d['fatHora'][$h] ?? 0), $horas),
            'bruto' => [], 'gasto' => array_map(fn($h) => (int)($d['gastoHora'][$h] ?? 0), $horas), 'invest' => array_map($inv, $horas),
            'lucro' => array_map(fn($h) => (int)($d['fatHora'][$h] ?? 0) - $inv($h), $horas), 'vendas' => array_map(fn($h) => (int)($d['porHora'][$h] ?? 0), $horas),
            'calor' => $d['serie']['calor'], 'porHora' => true];
    }
    // Periodo anterior: so compara quando ele tem algum dado (venda, gasto ou visita); conta nova ou
    // periodo antes do comeco nao vira "subiu de 0"
    $ant = grade_periodo_anterior($periodo);
    $d['antes'] = null;
    if ($ant !== null) {
        [$ade, $aate] = periodo_utc($ant);
        $a = resumo_dados($db, $ant, $ade, $aate, $fCanais);
        if ($a['fatBruto'] || $a['pendN'] || $a['reembN'] || $a['gasto'] || $a['visitantes'] || $a['conversas']) {
            $d['antes'] = $a;
            $d['antesNome'] = periodo_rotulo($ant);
        }
    }
    return $d;
}

// Funil da VSL do periodo: o geral (varias paginas) e o de cada pagina com VSL
function resumo_vsl(PDO $db, string $de, string $ate, string $dia1, string $dia2): string
{
    $paginasVsl = consulta($db, "SELECT DISTINCT pagina FROM eventos WHERE nome LIKE 'VSL_%' AND em >= ? AND em < ? ORDER BY pagina", [$de, $ate]);
    $totalVslPlay = (int)valor($db, "SELECT COUNT(DISTINCT visitante) FROM eventos WHERE nome = 'VSL_Play' AND em >= ? AND em < ?", [$de, $ate]);
    if (!$totalVslPlay) {
        return '<p class="suave">Ninguém deu play na VSL no período.</p>';
    }
    $h = '';
    if (count($paginasVsl) > 1) {
        $vsl50 = (int)valor($db, "SELECT COUNT(DISTINCT visitante) FROM eventos WHERE nome = 'VSL_50' AND em >= ? AND em < ?", [$de, $ate]);
        $vslPitch = (int)valor($db, "SELECT COUNT(DISTINCT visitante) FROM eventos WHERE nome = 'VSL_Pitch' AND em >= ? AND em < ?", [$de, $ate]);
        $vslCk = (int)valor($db, "SELECT COUNT(DISTINCT e.visitante) FROM eventos e
                                  JOIN eventos v ON v.visitante = e.visitante AND v.nome = 'VSL_Play' AND v.em >= ? AND v.em < ?
                                  WHERE e.nome = 'CliqueCheckout' AND e.em >= ? AND e.em < ?", [$de, $ate, $de, $ate]);
        $vslAprov = (int)valor($db, "SELECT COUNT(DISTINCT vd.visitante) FROM vendas vd
                                     JOIN eventos e ON e.visitante = vd.visitante AND e.nome = 'VSL_Play' AND e.em >= ? AND e.em < ?
                                     WHERE vd.aprovada_em IS NOT NULL AND vd.aprovada_em >= ? AND vd.aprovada_em < ?", [$de, $ate, $dia1 . ' 00:00:00', $dia2 . ' 23:59:59']);
        $h .= '<h3 class="pw-sub">Todas as páginas</h3>' . resumo_funil([
            'Deu play / som' => [$totalVslPlay, 'Visitantes únicos que iniciaram ou ativaram o som da VSL.'],
            'Metade (50%)' => [$vsl50, 'Visitantes únicos que assistiram pelo menos 50% do vídeo.'],
            'Oferta (Pitch)' => [$vslPitch, 'Visitantes únicos que assistiram até o momento da oferta.'],
            'Clicou no checkout' => [$vslCk, 'Visitantes que assistiram à VSL e clicaram no botão de compra.'],
            'Comprou (aprovada)' => [$vslAprov, 'Vendas aprovadas de visitantes que assistiram à VSL.'],
        ]);
    }
    foreach ($paginasVsl as $row) {
        $pPath = $row['pagina'];
        $pNome = $pPath;
        if (in_array($pPath, ['/drivedeprojetos', '/drivedeprojetos/', '/drivedeprojetos/index.html'], true)) {
            $pNome = 'Drive de Projetos (Principal · R$ 67)';
        } elseif (str_contains($pPath, '/vsl')) {
            $pNome = 'Drive de Projetos (VSL Teste · R$ 97)';
        }
        $pPlay = (int)valor($db, "SELECT COUNT(DISTINCT visitante) FROM eventos WHERE nome = 'VSL_Play' AND pagina = ? AND em >= ? AND em < ?", [$pPath, $de, $ate]);
        if ($pPlay === 0) {
            continue;
        }
        $p50 = (int)valor($db, "SELECT COUNT(DISTINCT visitante) FROM eventos WHERE nome = 'VSL_50' AND pagina = ? AND em >= ? AND em < ?", [$pPath, $de, $ate]);
        $pPitch = (int)valor($db, "SELECT COUNT(DISTINCT visitante) FROM eventos WHERE nome = 'VSL_Pitch' AND pagina = ? AND em >= ? AND em < ?", [$pPath, $de, $ate]);
        $pCk = (int)valor($db, "SELECT COUNT(DISTINCT e.visitante) FROM eventos e
                                JOIN eventos v ON v.visitante = e.visitante AND v.nome = 'VSL_Play' AND v.pagina = ? AND v.em >= ? AND v.em < ?
                                WHERE e.nome = 'CliqueCheckout' AND e.pagina = ? AND e.em >= ? AND e.em < ?", [$pPath, $de, $ate, $pPath, $de, $ate]);
        $pAprov = (int)valor($db, "SELECT COUNT(DISTINCT vd.visitante) FROM vendas vd
                                   JOIN eventos e ON e.visitante = vd.visitante AND e.nome = 'VSL_Play' AND e.pagina = ? AND e.em >= ? AND e.em < ?
                                   WHERE vd.aprovada_em IS NOT NULL AND vd.aprovada_em >= ? AND vd.aprovada_em < ?", [$pPath, $de, $ate, $dia1 . ' 00:00:00', $dia2 . ' 23:59:59']);
        $h .= '<h3 class="pw-sub">' . e($pNome) . ' ' . info('Retenção da VSL e conversão só na página ' . $pPath . '.') . '</h3>' . resumo_funil([
            'Deu play / som' => [$pPlay, 'Visitantes que iniciaram ou ativaram o som da VSL nesta página.'],
            'Metade (50%)' => [$p50, 'Assistiram pelo menos 50% do vídeo nesta página.'],
            'Oferta (Pitch)' => [$pPitch, 'Assistiram até a oferta (pitch) nesta página.'],
            'Clicou no checkout' => [$pCk, 'Clicaram no checkout nesta página após assistir.'],
            'Comprou (aprovada)' => [$pAprov, 'Vendas aprovadas de quem assistiu à VSL nesta página.'],
        ]);
    }
    return $h;
}

// O miolo de cada bloco com os numeros do periodo ($d: resumo_ctx)
function resumo_bloco(string $tipo, array $d): string
{
    $n = fn(float $v, int $c = 2) => number_format($v, $c, ',', '.');
    $cor = fn(?float $v, float $lim = 0) => $v === null ? '' : ($v >= $lim ? 'positivo' : 'negativo');
    $plural = fn(int $q, string $um, string $varios) => $q . ' ' . ($q === 1 ? $um : $varios);
    $pctTxt = fn(?float $v) => $v === null ? '—' : $n($v, 1) . '%';
    $aprov = (int)$d['aprovadas'];
    $reembSo = (int)$d['reembN'] - (int)$d['cbN'];
    $base = $aprov + (int)$d['reembN'];
    $taxas = max(0, (int)$d['fatBruto'] - (int)$d['fat']);
    $a = $d['antes'];
    $s = $d['serie'];
    // Numero do bloco: o valor, a seta contra o periodo anterior e a linhazinha do periodo
    $num = function (string $valor, string $sub = '', string $classe = '', string $delta = '', string $mini = ''): string {
        return '<div class="pw-valor"><div class="pw-valor-txt"><b class="pw-num' . ($classe !== '' ? ' ' . $classe : '') . '">' . e($valor) . '</b>'
            . ($sub !== '' || $delta !== '' ? '<small>' . $delta . ($delta !== '' && $sub !== '' ? ' ' : '') . e($sub) . '</small>' : '') . '</div>' . $mini . '</div>';
    };
    // Seta: $f calcula o numero a partir de um resumo_dados (o de agora e o do periodo anterior)
    $delta = function (callable $f, bool $menor = false, bool $neutro = false, ?callable $txt = null) use ($d, $a): string {
        $antes = $a ? $f($a) : null;
        $agora = $f($d);
        if (!$a || (!$agora && !$antes)) { // sem periodo anterior, ou zero nos dois: nada a comparar
            return '';
        }
        return gestor_delta($agora, $antes, $menor, $neutro, ($antes === null ? 'N/A' : ($txt ? $txt($antes) : number_format((float)$antes, 2, ',', '.'))) . ' (' . $d['antesNome'] . ')');
    };
    $mini = fn(string $serie, string $nome) => grafico_mini($s[$serie] ?? [], array_map(fn($k, $v) => grafico_rotulo((string)$k) . ': ' . ($serie === 'vendas' ? $v : reais((int)$v)), $s['chaves'], $s[$serie] ?? []), $nome);
    $din = fn($v) => reais((int)round((float)$v));
    $lista = fn(array $itens, callable $valor, int $total) => resumo_lista_aneis(array_map(fn($k, $v) => [e((string)$k), $valor($v), $total ? $v * 100 / $total : null], array_keys($itens), $itens));
    $fora = './?' . http_build_query(['aba' => 'vendas', 'periodo' => $d['periodo'], 'filtro' => 'fora']);
    switch ($tipo) {
        case 'NetRevenue':
            return $num(reais($d['fat']), $plural($aprov, 'venda aprovada', 'vendas aprovadas'), '', $delta(fn($x) => $x['fat'], false, false, $din), $mini('fat', 'Faturamento líquido'));
        case 'GrossRevenue':
            return $num(reais($d['fatBruto']), '', '', $delta(fn($x) => $x['fatBruto'], false, false, $din), $mini('bruto', 'Faturamento bruto'));
        case 'Spend':
            return $num(reais($d['gasto']), 'com imposto: ' . reais($d['investido']), '', $delta(fn($x) => $x['gasto'], false, true, $din), $mini('gasto', 'Gasto com anúncios'));
        case 'Roi':
            return $num($d['roi'] === null ? 'N/A' : $n($d['roi']), '', cor_roi($d['roi']), $delta(fn($x) => $x['roi']));
        case 'RoiTracked':
            return $num($d['roiMeta'] === null ? 'N/A' : $n($d['roiMeta']), '', cor_roi($d['roiMeta']), $delta(fn($x) => $x['roiMeta']));
        case 'Roas':
            return $num($d['gasto'] ? $n($d['fat'] / $d['gasto']) : 'N/A', '', '', $delta(fn($x) => $x['gasto'] ? $x['fat'] / $x['gasto'] : null));
        case 'Profit':
            return $num(reais($d['lucro']), '', $d['lucro'] ? $cor((float)$d['lucro']) : '', $delta(fn($x) => $x['lucro'], false, false, $din), $mini('lucro', 'Lucro'));
        case 'NetProfit':
            $l = $d['lucro'] - $d['despesas'];
            return $num(reais($l), 'despesas: ' . reais($d['despesas']), $l ? $cor((float)$l) : '');
        case 'ProfitMargin':
            $m = margem_pct($d['fat'], $d['lucro'], $d['imposto']);
            return $num($m === null ? '—' : $n($m, 1) . '%', '', $d['fat'] ? $cor((float)$d['lucro']) : '', $delta(fn($x) => margem_pct($x['fat'], $x['lucro'], $x['imposto']), false, false, fn($v) => $n($v, 1) . '%'));
        case 'Cpa':
            return $num($aprov ? reais((int)round($d['gasto'] / $aprov)) : 'N/A', '', '', $delta(fn($x) => $x['aprovadas'] ? $x['gasto'] / $x['aprovadas'] : null, true, false, $din));
        case 'Arpu':
            return $num($aprov ? reais((int)round($d['fat'] / $aprov)) : 'N/A', '', '', $delta(fn($x) => $x['aprovadas'] ? $x['fat'] / $x['aprovadas'] : null, false, false, $din));
        case 'ApprovedSales':
            return $num((string)$aprov, '', '', $delta(fn($x) => $x['aprovadas'], false, false, fn($v) => (string)$v), $mini('vendas', 'Vendas aprovadas'));
        case 'PendingCommission':
            return $num(reais($d['pendValor']), $plural((int)$d['pendN'], 'pedido', 'pedidos'));
        case 'RefundedCommission':
            return $num(reais($d['reembValor'] - $d['cbValor']), $plural($reembSo, 'venda', 'vendas'), $d['reembValor'] - $d['cbValor'] ? 'negativo' : '', $delta(fn($x) => $x['reembValor'] - $x['cbValor'], true, false, $din));
        case 'SalesChargeback':
            return $num(reais($d['cbValor']), $plural((int)$d['cbN'], 'venda', 'vendas'), $d['cbValor'] ? 'negativo' : '');
        case 'RefundRate':
            return $num($pctTxt($base ? $reembSo * 100 / $base : null));
        case 'ChargebackRate':
            return $num($pctTxt($base ? $d['cbN'] * 100 / $base : null));
        case 'CustomSpendings':
            return $num(reais($d['despesas']));
        case 'MetaAdsTax':
            return $num(reais($d['imposto']));
        case 'Fees':
            return $num(reais($taxas), $d['fatBruto'] ? $n($taxas * 100 / $d['fatBruto'], 1) . '% do bruto' : '');
        case 'TotalTax':
            return $num(reais($d['imposto'] + $taxas));
        case 'LeadCount':
            return $num((string)$d['leads'], $plural((int)$d['conversas'], 'clique', 'cliques'), '', $delta(fn($x) => $x['leads'], false, false, fn($v) => (string)$v));
        case 'CostPerLead':
            return $num($d['leads'] ? reais((int)round($d['gasto'] / $d['leads'])) : 'N/A', '', '', $delta(fn($x) => $x['leads'] ? $x['gasto'] / $x['leads'] : null, true, false, $din));
        case 'Conversations':
            return $num((string)$d['conversas']);
        case 'Visitors':
            return $num((string)$d['visitantes'], '', '', $delta(fn($x) => $x['visitantes'], false, false, fn($v) => (string)$v));
        case 'CheckoutClicks':
            return $num((string)$d['clicaram'], $d['visitantes'] ? $n($d['clicaram'] * 100 / $d['visitantes'], 1) . '% dos visitantes' : '');
        case 'RevenueByDay':
            return grafico_area($s['chaves'], [['Faturamento', '#1D6FF2', $s['fat']], ['Investimento', '#D97706', $s['invest']]], 'Faturamento e investimento ' . (!empty($s['porHora']) ? 'por hora' : 'por dia'));
        case 'ProfitByDay':
            return grafico_barras($s['chaves'], $s['lucro'], 'Lucro');
        case 'RoiGauge':
            return grafico_velocimetro($d['roi'], 'ROI rastreado: ' . ($d['roiMeta'] === null ? 'N/A' : $n($d['roiMeta'])));
        case 'SalesHeatmap':
            return grafico_mapa_calor($s['calor']);
        case 'VslFunnel':
            return resumo_vsl($d['db'], $d['de'], $d['ate'], $d['dia1'], $d['dia2']);
        case 'SalesByPayment':
            return resumo_rosca($d['porPagamento'], ['Pix' => '#1D6FF2', 'Cartão' => '#60A5FA', 'Boleto' => '#F59E0B', 'Outros' => '#9CA3AF'], 'Total');
        case 'SalesByProduct':
            arsort($d['porProduto']);
            return $lista($d['porProduto'], fn($v) => $v, array_sum($d['porProduto']));
        case 'RevenueByProduct':
            arsort($d['fatProduto']);
            return $lista($d['fatProduto'], fn($v) => reais((int)$v), (int)array_sum($d['fatProduto']));
        case 'SalesBySource':
            $c = $d['porCanal'];
            uasort($c, fn($x, $y) => $y['n'] <=> $x['n']);
            return resumo_lista_aneis(array_map(fn($x) => [selo_canal($x['canal'], false), $x['n'], $aprov ? $x['n'] * 100 / $aprov : null], array_values($c)));
        case 'SalesByPlacement':
            arsort($d['porTermo']);
            return $lista($d['porTermo'], fn($v) => $v, $aprov);
        case 'ApprovalRate':
            return resumo_lista_aneis(array_map(fn($m) => [e($m), ($d['aprovPorMeio'][$m] ?? 0) . '/' . ($d['tentativas'][$m] ?? 0),
                !empty($d['tentativas'][$m]) ? ($d['aprovPorMeio'][$m] ?? 0) * 100 / $d['tentativas'][$m] : null], ['Cartão', 'Pix', 'Boleto']));
        case 'OutsideAds':
            return (array_sum($d['foraTipo'])
                ? resumo_rosca($d['foraTipo'], ['Orgânico' => '#16A34A', 'Anúncio compartilhado' => '#1D6FF2', 'Direto / sem origem' => '#9CA3AF', 'Outras origens' => '#F59E0B'], 'Fora')
                : '<p class="suave">Nenhuma venda fora de anúncio no período.</p>') . '<a class="rc-link" href="' . e($fora) . '">Ver cada uma e o motivo</a>';
        case 'TrackingQuality':
            $q = fn(int $v) => $aprov ? $v * 100 / $aprov : null;
            return resumo_lista_aneis([
                ['Com origem identificada ' . info('Vendas com etiqueta de anúncio, orgânica ou de outra origem: tudo menos "Direto / sem origem".'), $d['comOrigem'], $q((int)$d['comOrigem'])],
                ['Ligadas a uma campanha ' . info('Vendas com o ID de uma campanha da Meta: entram no gestor de anúncios e no ROI rastreado.'), $d['metaAprovadas'], $q((int)$d['metaAprovadas'])],
                ['Ligadas a um visitante ' . info('Vendas que o painel ligou a um aparelho que visitou as páginas (parâmetro sck no checkout).'), $d['siteAprovadas'], $q((int)$d['siteAprovadas'])],
                ['Sem origem ' . info('Chegaram sem etiqueta nenhuma: link de checkout enviado à mão, e-mail, troca de aparelho.'), $d['semOrigem'], $q((int)$d['semOrigem'])],
            ], 'Nenhuma venda aprovada no período.') . '<a class="rc-link" href="' . e($fora) . '">Ver as vendas fora de anúncio</a>';
        case 'ConversionFunnel':
            return resumo_funil([
                'Cliques' => [(int)$d['g']['cliques'], 'Cliques no link do anúncio (Meta).'],
                "Visuali\u{00AD}zações" => [(int)$d['g']['vis'], 'Visualizações da página de destino que a Meta contou (a página carregou).'],
                'Inícios de checkout' => [(int)$d['g']['ics'], 'InitiateCheckout contados pela Meta (checkout aberto na Kiwify).'],
                'Vendas iniciadas' => [(int)$d['metaIniciadas'], 'Pedidos criados na Kiwify vindos de anúncio, pagos ou não.'],
                'Vendas aprovadas' => [(int)$d['metaAprovadas'], 'Pedidos aprovados vindos de anúncio.'],
            ]);
        case 'SiteFunnel':
            return resumo_funil([
                'Visitantes' => [(int)$d['visitantes'], 'Aparelhos diferentes que abriram as páginas com o painel.'],
                'Clicaram no checkout' => [(int)$d['clicaram'], 'Visitantes que clicaram no botão de compra.'],
                'Vendas iniciadas' => [(int)$d['siteIniciadas'], 'Pedidos criados na Kiwify que o painel ligou a um visitante.'],
                'Vendas aprovadas' => [(int)$d['siteAprovadas'], 'Desses, os aprovados.'],
            ]);
        case 'SalesByDayOfWeek':
            return grafico_colunas($d['porSemana'], 'Vendas por dia da semana');
        case 'SalesByHour':
            $itens = $titulos = $extras = [];
            for ($h = 0; $h < 24; $h++) {
                $k = sprintf('%02dh', $h);
                $itens[$k] = (int)($d['porHora'][$h] ?? 0);
                $titulos[$k] = sprintf('%02dh às %02dh', $h, ($h + 1) % 24);
                $extras[$k] = !empty($d['fatHora'][$h]) ? ['Faturamento ' . reais((int)$d['fatHora'][$h])] : [];
            }
            return grafico_colunas($itens, 'Vendas por horário', $titulos, $extras, 3);
        case 'ProfitByHour':
            $horas = range(0, 23);
            return grafico_barras(array_map(fn($h) => sprintf('%02dh', $h), $horas), array_map(fn($h) => (int)($d['lucroHora'][$h] ?? 0), $horas), 'Lucro');
        case 'RevenueInvestmentProfitByHour':
            $horas = range(0, (int)$d['ateHora']);
            $serie = fn(array $ac) => array_map(fn($h) => (int)($ac[$h] ?? 0), $horas);
            return grafico_area(array_map(fn($h) => sprintf('%02dh', $h), $horas), [['Faturamento', '#1D6FF2', $serie($d['acF'])], ['Investimento', '#D97706', $serie($d['acG'])], ['Lucro', '#16A34A', $serie($d['acL'])]], 'Faturamento, investimento e lucro acumulados por hora');
    }
    return '';
}
