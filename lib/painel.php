<?php
// Painel editavel (aba Painel): os numeros e graficos do periodo em cartoes que cada pessoa
// arruma como quiser, no estilo do Power BI e do modo de edicao da UTMify.
//
// - Registro de widgets (painel_widgets): titulo, categoria, desenho, o (i) com a conta e o
//   tamanho na grade (largura e altura, com o minimo e o maximo). Os numeros saem de
//   resumo_dados() (as mesmas contas do Resumo e do gestor) e as despesas, do Financeiro.
// - Layout por pessoa e por aparelho, na tabela painel_layouts: computador numa grade de 12
//   colunas, celular numa de 2. Sem layout salvo vale o padrao (PAINEL_PADRAO); o do celular
//   sai do do computador.
// - Integracoes: cada metrica diz de onde vem (painel_fontes: API Meta, t.js nas paginas,
//   despesas do Financeiro). Numa conta nova, sem a fonte ligada, o cartao diz o que falta e
//   leva para onde liga, em vez de mostrar zero como se fosse resultado.
// - Ver: o servidor desenha a grade com CSS Grid, sem JavaScript. Editar (?aba=painel&editar=1):
//   a grade vira GridStack (gridstack.js, MIT, copiado para dentro do painel por causa da CSP),
//   com a biblioteca de metricas do lado para arrastar, o X para tirar, Salvar, Cancelar e
//   Voltar ao padrao. Quem grava e o painel-salvar.php.

require_once __DIR__ . '/resumo.php';
require_once __DIR__ . '/financeiro.php';

const PAINEL_CATEGORIAS = ['geral' => 'Geral', 'graficos' => 'Gráficos', 'impostos' => 'Impostos e taxas', 'site' => 'WhatsApp e site'];
const PAINEL_COLUNAS = ['computador' => 12, 'celular' => 2];
const PAINEL_LINHA_PX = 110;

// Layout padrao do computador: [tipo, x, y, largura, altura] (o Resumo da UTMify)
const PAINEL_PADRAO = [
    ['NetRevenue', 0, 0, 4, 1], ['Spend', 4, 0, 3, 1], ['Roi', 7, 0, 2, 1], ['Profit', 9, 0, 3, 1],
    ['SalesByPayment', 0, 1, 4, 3], ['ProfitMargin', 4, 1, 3, 1], ['Cpa', 7, 1, 2, 1], ['MetaAdsTax', 9, 1, 3, 1],
    ['PendingCommission', 4, 2, 4, 1], ['Arpu', 8, 2, 4, 1],
    ['RefundedCommission', 4, 3, 4, 1], ['ApprovalRate', 8, 3, 4, 3],
    ['SalesByProduct', 0, 4, 4, 2], ['SalesBySource', 4, 4, 4, 2],
    ['ConversionFunnel', 0, 6, 7, 3], ['SalesByDayOfWeek', 7, 6, 5, 3],
    ['SalesByHour', 0, 9, 12, 3],
    ['RevenueInvestmentProfitByHour', 0, 12, 12, 4],
];

// tipo => [titulo, categoria, desenho (numero ou grafico), dica, [w, h, minW, minH, maxW, maxH], icone,
// fontes de que depende]. As vendas (webhook da Kiwify) valem para todas e nao entram na lista.
function painel_widgets(): array
{
    static $w = null;
    if ($w !== null) {
        return $w;
    }
    $fontes = [
        'meta' => ['Spend', 'Roi', 'RoiTracked', 'Roas', 'Profit', 'NetProfit', 'ProfitMargin', 'Cpa', 'MetaAdsTax', 'TotalTax', 'ConversionFunnel', 'ProfitByHour', 'RevenueInvestmentProfitByHour', 'CostPerLead'],
        'site' => ['SiteFunnel', 'Visitors', 'CheckoutClicks', 'LeadCount', 'Conversations', 'CostPerLead'],
        'financeiro' => ['CustomSpendings', 'NetProfit'],
    ];
    $p = number_format(gestor_imposto_pct(), 2, ',', '.') . '%';
    $num = [3, 1, 3, 1, 4, 1];
    $pequeno = [2, 1, 2, 1, 3, 1];
    $lista = [4, 2, 3, 2, 12, 6];
    $w = [
        // Geral
        'NetRevenue' => ['Faturamento líquido', 'geral', 'numero', 'O que a Kiwify repassa (depois das taxas) das vendas aprovadas no período, com order bump.', [4, 1, 3, 1, 4, 1], 'carteira'],
        'GrossRevenue' => ['Faturamento bruto', 'geral', 'numero', 'Valor cobrado do comprador nas vendas aprovadas, antes das taxas da Kiwify, com order bump.', [4, 1, 3, 1, 4, 1], 'carteira'],
        'Spend' => ['Gasto com anúncios', 'geral', 'numero', 'Quanto a Meta cobrou pelos anúncios no período, sem o imposto.', $num, 'meta'],
        'Roi' => ['ROI geral', 'geral', 'numero', '(Faturamento líquido − imposto da Meta) ÷ gasto, a conta da UTMify e do gestor, com todas as vendas aprovadas. Vermelho abaixo de 1, laranja de 1 até 2, verde de 2 para cima.', $pequeno, 'grafico'],
        'RoiTracked' => ['ROI rastreado', 'geral', 'numero', 'Só as vendas com o ID de uma campanha da Meta: (faturamento delas − imposto) ÷ gasto.', $pequeno, 'grafico'],
        'Roas' => ['ROAS', 'geral', 'numero', 'Faturamento líquido ÷ gasto, sem o imposto (o retorno sobre o gasto que a Meta mostra; a mesma conta da coluna do gestor).', $pequeno, 'grafico'],
        'Profit' => ['Lucro', 'geral', 'numero', 'Faturamento líquido − gasto − imposto da Meta (' . $p . ' sobre o gasto).', $num, 'vendas'],
        'NetProfit' => ['Lucro depois das despesas', 'geral', 'numero', 'Lucro − as outras despesas da empresa no período (as do Financeiro). É o saldo do Financeiro.', $num, 'vendas'],
        'ProfitMargin' => ['Margem', 'geral', 'numero', 'Lucro ÷ (faturamento líquido − imposto da Meta): quanto de cada real que voltou sobra.', [3, 1, 2, 1, 4, 1], 'atividade'],
        'Cpa' => ['CPA', 'geral', 'numero', 'Gasto ÷ vendas aprovadas. Order bump não conta como outra venda.', [2, 1, 2, 1, 6, 1], 'campanha'],
        'Arpu' => ['Ticket médio', 'geral', 'numero', 'Faturamento líquido ÷ vendas aprovadas (quanto cada comprador deixa, com order bump). Na UTMify, ARPU. O painel não guarda quem comprou (LGPD), então conta por venda.', [4, 1, 3, 1, 4, 1], 'usuario'],
        'ApprovedSales' => ['Vendas aprovadas', 'geral', 'numero', 'Vendas aprovadas no período, sem contar o order bump.', [3, 1, 2, 1, 4, 1], 'ok'],
        'PendingCommission' => ['Vendas pendentes', 'geral', 'numero', 'Pix ou boleto gerado e ainda não pago, no valor cobrado.', [4, 1, 3, 1, 4, 1], 'calendario'],
        'RefundedCommission' => ['Vendas reembolsadas', 'geral', 'numero', 'Reembolsos no período, no valor cobrado (sem os chargebacks, que têm o cartão deles).', [4, 1, 3, 1, 4, 1], 'restaurar'],
        'SalesChargeback' => ['Chargebacks', 'geral', 'numero', 'Contestações no cartão no período, no valor cobrado.', $num, 'restaurar'],
        'RefundRate' => ['Taxa de reembolso', 'geral', 'numero', 'Vendas reembolsadas ÷ (aprovadas + reembolsadas e chargebacks) no período.', $pequeno, 'restaurar'],
        'ChargebackRate' => ['Taxa de chargeback', 'geral', 'numero', 'Chargebacks ÷ (aprovadas + reembolsadas e chargebacks) no período.', $pequeno, 'restaurar'],
        'CustomSpendings' => ['Outras despesas', 'geral', 'numero', 'Despesas da empresa cadastradas no Financeiro que caem no período (as pausadas não contam).', [3, 1, 2, 1, 6, 1], 'lista'],
        // Graficos
        'SalesByPayment' => ['Vendas por pagamento', 'graficos', 'grafico', 'Vendas aprovadas (sem contar order bump) por forma de pagamento.', [4, 3, 4, 3, 12, 6], 'carteira'],
        'SalesByProduct' => ['Vendas por produto', 'graficos', 'grafico', 'Pedidos aprovados de cada produto, com os order bumps (cada bump conta no seu produto).', $lista, 'lista'],
        'RevenueByProduct' => ['Faturamento por produto', 'graficos', 'grafico', 'Faturamento líquido de cada produto nas vendas aprovadas, e a fatia de cada um no total.', $lista, 'carteira'],
        'SalesBySource' => ['Vendas por canal', 'graficos', 'grafico', 'Vendas aprovadas pelo canal que o painel identificou na etiqueta (anúncio no Instagram ou no Facebook, orgânico, direto...).', $lista, 'trafego'],
        'SalesByPlacement' => ['Vendas por posicionamento', 'graficos', 'grafico', 'Vendas aprovadas pelo posicionamento do anúncio (utm_term: Feed, Reels, Stories...).', $lista, 'anuncio'],
        'ApprovalRate' => ['Taxa de aprovação', 'graficos', 'grafico', 'Aprovadas ÷ pedidos criados com cada forma de pagamento (cartão recusado e Pix não pago derrubam a taxa).', [4, 3, 3, 2, 12, 6], 'ok'],
        'OutsideAds' => ['Vendas fora de anúncio', 'graficos', 'grafico', 'Vendas aprovadas sem o ID de uma campanha da Meta, pelo tipo: orgânico, anúncio compartilhado, direto e outras origens.', [4, 3, 4, 3, 12, 6], 'folha'],
        'TrackingQuality' => ['Qualidade do rastreio', 'graficos', 'grafico', 'Quanto das vendas aprovadas o painel conseguiu explicar: com origem, ligadas a uma campanha, ligadas a um visitante e sem origem.', [4, 3, 3, 3, 12, 6], 'conferencia'],
        'ConversionFunnel' => ['Funil da Meta', 'graficos', 'grafico', 'Do clique no anúncio até a venda aprovada. Cliques, visualizações e inícios de checkout vêm da Meta; vendas, da Kiwify (só as com o ID de uma campanha).', [7, 3, 4, 3, 12, 6], 'meta'],
        'SiteFunnel' => ['Funil do site', 'graficos', 'grafico', 'Medido pelo próprio painel (t.js nas páginas): visitantes, cliques no checkout, vendas iniciadas e aprovadas.', [7, 3, 4, 3, 12, 6], 'visitantes'],
        'SalesByDayOfWeek' => ['Vendas por dia da semana', 'graficos', 'grafico', 'Vendas aprovadas em cada dia da semana (dia da aprovação, horário de Brasília), em % do período.', [5, 3, 4, 3, 12, 6], 'calendario'],
        'SalesByHour' => ['Vendas por horário', 'graficos', 'grafico', 'Percentual das vendas aprovadas em cada hora do dia (hora da aprovação, no horário de Brasília).', [12, 3, 4, 3, 12, 6], 'grafico'],
        'ProfitByHour' => ['Lucro por horário', 'graficos', 'grafico', 'Faturamento − gasto − imposto da Meta em cada hora do dia (os dias do período somados pela hora): verde ganhou, vermelho perdeu. Mostra os horários em que o anúncio só gasta.', [12, 3, 4, 3, 12, 6], 'vendas'],
        'RevenueInvestmentProfitByHour' => ['Faturamento × investimento × lucro por hora', 'graficos', 'grafico', 'Soma hora a hora ao longo do dia (os dias do período somados pela hora). Investimento = gasto na Meta; lucro já desconta o imposto.', [12, 4, 4, 3, 12, 6], 'grafico'],
        // Impostos e taxas
        'MetaAdsTax' => ['Imposto da Meta', 'impostos', 'numero', 'Impostos que a Meta cobra sobre o gasto com anúncios no Brasil: ' . $p . '.', $num, 'conferencia'],
        'Fees' => ['Taxas da Kiwify', 'impostos', 'numero', 'Faturamento bruto − líquido: o que a Kiwify ficou de taxa nas vendas aprovadas.', $num, 'lista'],
        'TotalTax' => ['Imposto e taxas', 'impostos', 'numero', 'Imposto da Meta + taxas da Kiwify no período.', $num, 'conferencia'],
        // WhatsApp e site
        'LeadCount' => ['Leads (WhatsApp)', 'site', 'numero', 'Pessoas que clicaram no WhatsApp nas páginas com o painel (t.js).', [3, 1, 2, 1, 6, 1], 'eventos'],
        'CostPerLead' => ['Custo por lead', 'site', 'numero', 'Gasto com anúncios ÷ pessoas que clicaram no WhatsApp.', [3, 1, 2, 1, 6, 1], 'campanha'],
        'Conversations' => ['Cliques no WhatsApp', 'site', 'numero', 'Cliques no botão ou link do WhatsApp (todos, mesmo da mesma pessoa).', $pequeno, 'eventos'],
        'Visitors' => ['Visitantes', 'site', 'numero', 'Aparelhos diferentes que abriram as páginas com o painel.', [3, 1, 2, 1, 4, 1], 'visitantes'],
        'CheckoutClicks' => ['Clicaram no checkout', 'site', 'numero', 'Visitantes que clicaram no botão de compra, e a % dos visitantes.', [3, 1, 2, 1, 4, 1], 'eventos'],
    ];
    foreach ($w as $t => $def) {
        $w[$t][6] = array_keys(array_filter($fontes, fn($lista) => in_array($t, $lista, true)));
    }
    return $w;
}

// O que cada fonte precisa estar ligado, e onde ligar: [ligada, o que falta, link]
function painel_fontes(): array
{
    static $f = null;
    if ($f !== null) {
        return $f;
    }
    $db = track_db();
    $f = [
        'meta' => [(bool)meta_api_chave(), 'Conecte a conta de anúncios', 'meta-api.php'],
        'site' => [(bool)$db->query('SELECT 1 FROM eventos LIMIT 1')->fetchColumn(), 'Ponha o t.js nas páginas', ''],
        'financeiro' => [(bool)$db->query('SELECT 1 FROM gastos LIMIT 1')->fetchColumn(), 'Cadastre as despesas', 'financeiro.php'],
    ];
    return $f;
}

const PAINEL_FONTE_NOME = ['meta' => 'API Meta', 'site' => 't.js nas páginas', 'financeiro' => 'despesas do Financeiro'];

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

// ---------------------------------------------------------------- layouts

// Layout valido: so tipos do registro, sem repetir, no tamanho permitido e dentro da grade
function painel_validar(array $itens, string $aparelho): array
{
    $cols = PAINEL_COLUNAS[$aparelho] ?? 12;
    $ws = painel_widgets();
    $vistos = [];
    $saida = [];
    foreach (array_slice($itens, 0, 80) as $i) {
        $t = is_array($i) ? (string)($i['tipo'] ?? '') : '';
        if (!isset($ws[$t]) || isset($vistos[$t])) {
            continue;
        }
        [$w0, $h0, $minW, $minH, $maxW, $maxH] = $ws[$t][4];
        if ($cols < 12) {
            [$minW, $maxW] = [1, $cols];
            $w0 = $ws[$t][2] === 'numero' ? 1 : $cols;
        }
        $w = max($minW, min($maxW, $cols, (int)($i['w'] ?? $w0)));
        $h = max($minH, min($maxH, (int)($i['h'] ?? $h0)));
        $saida[] = ['tipo' => $t, 'x' => max(0, min($cols - $w, (int)($i['x'] ?? 0))), 'y' => max(0, min(500, (int)($i['y'] ?? 0))), 'w' => $w, 'h' => $h];
        $vistos[$t] = true;
    }
    usort($saida, fn($a, $b) => [$a['y'], $a['x']] <=> [$b['y'], $b['x']]);
    return $saida;
}

function painel_padrao(): array
{
    return array_map(fn($p) => ['tipo' => $p[0], 'x' => $p[1], 'y' => $p[2], 'w' => $p[3], 'h' => $p[4]], PAINEL_PADRAO);
}

// Celular a partir do computador: na ordem de cima para baixo, numeros dois por linha e
// graficos na largura toda
function painel_para_celular(array $itens): array
{
    $ws = painel_widgets();
    $saida = [];
    $y = 0;
    $meia = null; // linha com a metade direita livre
    foreach ($itens as $i) {
        if (($ws[$i['tipo']][2] ?? '') === 'numero') {
            if ($meia !== null) {
                $saida[] = ['tipo' => $i['tipo'], 'x' => 1, 'y' => $meia, 'w' => 1, 'h' => 1];
                $meia = null;
            } else {
                $saida[] = ['tipo' => $i['tipo'], 'x' => 0, 'y' => $y, 'w' => 1, 'h' => 1];
                $meia = $y++;
            }
        } else {
            $meia = null;
            $h = max($ws[$i['tipo']][4][3], min(4, $i['h']));
            $saida[] = ['tipo' => $i['tipo'], 'x' => 0, 'y' => $y, 'w' => 2, 'h' => $h];
            $y += $h;
        }
    }
    return $saida;
}

function painel_salvo(string $usuario, string $aparelho): ?array
{
    $st = track_db()->prepare('SELECT layout FROM painel_layouts WHERE usuario = ? AND aparelho = ?');
    $st->execute([$usuario, $aparelho]);
    $j = $st->fetchColumn();
    $itens = $j === false ? null : json_decode((string)$j, true);
    return is_array($itens) ? painel_validar($itens, $aparelho) : null;
}

// O layout de cada aparelho: o salvo, ou o padrao (no celular, o do computador arrumado)
function painel_layout(string $usuario, string $aparelho): array
{
    $salvo = painel_salvo($usuario, $aparelho);
    if ($salvo !== null) {
        return $salvo;
    }
    $computador = painel_salvo($usuario, 'computador') ?? painel_validar(painel_padrao(), 'computador');
    return $aparelho === 'celular' ? painel_para_celular($computador) : $computador;
}

// Grava o layout (o que vier e validado). [ok, mensagem]
function painel_salvar(string $usuario, string $aparelho, string $json): array
{
    if (!isset(PAINEL_COLUNAS[$aparelho])) {
        return [false, 'Aparelho inválido.'];
    }
    $itens = json_decode($json, true);
    if (!is_array($itens)) {
        return [false, 'Não deu para ler o painel. Recarregue e tente de novo.'];
    }
    $layout = painel_validar($itens, $aparelho);
    track_db()->prepare('INSERT INTO painel_layouts (usuario, aparelho, layout, atualizado_em) VALUES (?, ?, ?, ?)
        ON CONFLICT (usuario, aparelho) DO UPDATE SET layout = excluded.layout, atualizado_em = excluded.atualizado_em')
        ->execute([$usuario, $aparelho, json_encode($layout), agora_utc()]);
    return [true, 'Painel salvo (' . ($aparelho === 'celular' ? 'celular' : 'computador') . ', ' . count($layout) . ' ' . (count($layout) === 1 ? 'cartão' : 'cartões') . ').'];
}

function painel_apagar(string $usuario, string $aparelho): void
{
    track_db()->prepare('DELETE FROM painel_layouts WHERE usuario = ? AND aparelho = ?')->execute([$usuario, $aparelho]);
}

// ---------------------------------------------------------------- desenho

// O miolo de cada widget com os numeros do periodo ($d: resumo_dados + despesas + ateHora)
function painel_corpo(string $tipo, array $d): string
{
    $n = fn(float $v, int $c = 2) => number_format($v, $c, ',', '.');
    $cor = fn(?float $v, float $lim = 0) => $v === null ? '' : ($v >= $lim ? 'positivo' : 'negativo');
    $num = fn(string $valor, string $sub = '', string $classe = '') => '<b class="pw-num' . ($classe !== '' ? ' ' . $classe : '') . '">' . e($valor) . '</b>' . ($sub !== '' ? '<small>' . e($sub) . '</small>' : '');
    $plural = fn(int $q, string $um, string $varios) => $q . ' ' . ($q === 1 ? $um : $varios);
    $pctTxt = fn(?float $v) => $v === null ? '—' : $n($v, 1) . '%';
    $aprov = (int)$d['aprovadas'];
    $reembSo = (int)$d['reembN'] - (int)$d['cbN'];
    $base = $aprov + (int)$d['reembN'];
    $taxas = max(0, (int)$d['fatBruto'] - (int)$d['fat']);
    $lista = fn(array $itens, callable $valor, int $total) => resumo_lista_aneis(array_map(fn($k, $v) => [e((string)$k), $valor($v), $total ? $v * 100 / $total : null], array_keys($itens), $itens));
    switch ($tipo) {
        case 'NetRevenue':
            return $num(reais($d['fat']), $plural($aprov, 'venda aprovada', 'vendas aprovadas'));
        case 'GrossRevenue':
            return $num(reais($d['fatBruto']));
        case 'Spend':
            return $num(reais($d['gasto']), 'com imposto: ' . reais($d['investido']));
        case 'Roi':
            return $num($d['roi'] === null ? 'N/A' : $n($d['roi']), '', cor_roi($d['roi']));
        case 'RoiTracked':
            return $num($d['roiMeta'] === null ? 'N/A' : $n($d['roiMeta']), '', cor_roi($d['roiMeta']));
        case 'Roas':
            return $num($d['gasto'] ? $n($d['fat'] / $d['gasto']) : 'N/A');
        case 'Profit':
            return $num(reais($d['lucro']), '', $d['lucro'] ? $cor((float)$d['lucro']) : '');
        case 'NetProfit':
            $l = $d['lucro'] - $d['despesas'];
            return $num(reais($l), 'despesas: ' . reais($d['despesas']), $l ? $cor((float)$l) : '');
        case 'ProfitMargin':
            $m = margem_pct($d['fat'], $d['lucro'], $d['imposto']);
            return $num($m === null ? '—' : $n($m, 1) . '%', '', $d['fat'] ? $cor((float)$d['lucro']) : '');
        case 'Cpa':
            return $num($aprov ? reais((int)round($d['gasto'] / $aprov)) : 'N/A');
        case 'Arpu':
            return $num($aprov ? reais((int)round($d['fat'] / $aprov)) : 'N/A');
        case 'ApprovedSales':
            return $num((string)$aprov);
        case 'PendingCommission':
            return $num(reais($d['pendValor']), $plural((int)$d['pendN'], 'pedido', 'pedidos'));
        case 'RefundedCommission':
            return $num(reais($d['reembValor'] - $d['cbValor']), $plural($reembSo, 'venda', 'vendas'), $d['reembValor'] - $d['cbValor'] ? 'negativo' : '');
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
            return $num((string)$d['leads'], $plural((int)$d['conversas'], 'clique', 'cliques'));
        case 'CostPerLead':
            return $num($d['leads'] ? reais((int)round($d['gasto'] / $d['leads'])) : 'N/A');
        case 'Conversations':
            return $num((string)$d['conversas']);
        case 'Visitors':
            return $num((string)$d['visitantes']);
        case 'CheckoutClicks':
            return $num((string)$d['clicaram'], $d['visitantes'] ? $n($d['clicaram'] * 100 / $d['visitantes'], 1) . '% dos visitantes' : '');
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
            uasort($c, fn($a, $b) => $b['n'] <=> $a['n']);
            return resumo_lista_aneis(array_map(fn($x) => [selo_canal($x['canal'], false), $x['n'], $aprov ? $x['n'] * 100 / $aprov : null], array_values($c)));
        case 'SalesByPlacement':
            arsort($d['porTermo']);
            return $lista($d['porTermo'], fn($v) => $v, $aprov);
        case 'ApprovalRate':
            return resumo_lista_aneis(array_map(fn($m) => [e($m), ($d['aprovPorMeio'][$m] ?? 0) . '/' . ($d['tentativas'][$m] ?? 0),
                !empty($d['tentativas'][$m]) ? ($d['aprovPorMeio'][$m] ?? 0) * 100 / $d['tentativas'][$m] : null], ['Cartão', 'Pix', 'Boleto']));
        case 'OutsideAds':
            return array_sum($d['foraTipo'])
                ? resumo_rosca($d['foraTipo'], ['Orgânico' => '#16A34A', 'Anúncio compartilhado' => '#1D6FF2', 'Direto / sem origem' => '#9CA3AF', 'Outras origens' => '#F59E0B'], 'Fora')
                : '<p class="suave">Nenhuma venda fora de anúncio no período.</p>';
        case 'TrackingQuality':
            $q = fn(int $v) => $aprov ? $v * 100 / $aprov : null;
            return resumo_lista_aneis([['Com origem identificada', $d['comOrigem'], $q((int)$d['comOrigem'])], ['Ligadas a uma campanha', $d['metaAprovadas'], $q((int)$d['metaAprovadas'])],
                ['Ligadas a um visitante', $d['siteAprovadas'], $q((int)$d['siteAprovadas'])], ['Sem origem', $d['semOrigem'], $q((int)$d['semOrigem'])]], 'Nenhuma venda aprovada no período.');
        case 'ConversionFunnel':
            return resumo_funil([
                'Cliques' => [(int)$d['g']['cliques'], 'Cliques no link do anúncio (Meta).'],
                "Visuali\u{00AD}zações" => [(int)$d['g']['vis'], 'Visualizações da página de destino que a Meta contou.'],
                'Inícios de checkout' => [(int)$d['g']['ics'], 'InitiateCheckout contados pela Meta.'],
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
            return resumo_svg_colunas($d['porSemana'], 'Vendas por dia da semana');
        case 'SalesByHour':
            return resumo_svg_barras($d['porHora'], $d['fatHora']);
        case 'ProfitByHour':
            return painel_svg_lucro_hora($d['lucroHora']);
        case 'RevenueInvestmentProfitByHour':
            return '<p class="legenda-grafico"><i style="background:#D97706"></i>Investimento <i style="background:#1D6FF2"></i>Faturamento <i style="background:#16A34A"></i>Lucro</p>'
                . resumo_svg_linhas([['Faturamento', '#1D6FF2', $d['acF']], ['Investimento', '#D97706', $d['acG']], ['Lucro', '#16A34A', $d['acL']]], (int)$d['ateHora']);
    }
    return '';
}

// Lucro de cada hora (nao acumulado): barra verde para cima, vermelha para baixo
function painel_svg_lucro_hora(array $porHora): string
{
    $L = 1400; $A = 200; $esq = 10; $baixo = 22; $cima = 16;
    $max = max(1, ...array_map('abs', array_values($porHora) ?: [0]));
    $meio = $cima + ($A - $cima - $baixo) / 2;
    $metade = ($A - $cima - $baixo) / 2;
    $larg = ($L - 2 * $esq) / 24;
    $svg = '<svg class="grafico" viewBox="0 0 ' . $L . ' ' . $A . '" role="img" aria-label="Lucro por horário">'
        . '<line x1="' . $esq . '" x2="' . ($L - $esq) . '" y1="' . round($meio, 1) . '" y2="' . round($meio, 1) . '" class="grade-l"></line>';
    for ($h = 0; $h < 24; $h++) {
        $v = (int)($porHora[$h] ?? 0);
        $x = $esq + $h * $larg;
        if ($v) {
            $alt = abs($v) * $metade / $max;
            $y = $v > 0 ? $meio - $alt : $meio;
            $svg .= '<g class="dia-graf"' . dica_attr(sprintf('%02dh às %02dh', $h, ($h + 1) % 24), ['Lucro ' . reais($v)]) . '>'
                . '<rect class="vela-alvo" x="' . round($x, 1) . '" y="0" width="' . round($larg, 1) . '" height="' . ($A - $baixo) . '"></rect>'
                . '<rect x="' . round($x + 6, 1) . '" y="' . round($y, 1) . '" width="' . round($larg - 12, 1) . '" height="' . round(max(1, $alt), 1) . '" rx="3" class="' . ($v > 0 ? 'barra-ok' : 'barra-ruim') . '"></rect></g>';
        }
        $svg .= '<text x="' . round($x + $larg / 2, 1) . '" y="' . ($A - 6) . '" text-anchor="middle">' . sprintf('%02d', $h) . '</text>';
    }
    return $svg . '</svg>';
}

// Cartao do widget: titulo com o (i), icone no canto e o miolo
function painel_cartao(string $tipo, array $d): string
{
    [$titulo, , $desenho, $dica, , $ico, $precisa] = painel_widgets()[$tipo];
    // Fonte desligada (conta nova): diz o que falta e leva para onde liga
    $falta = array_values(array_filter($precisa, fn($f) => !painel_fontes()[$f][0]));
    $aviso = '';
    if ($falta) {
        [, $txt, $href] = painel_fontes()[$falta[0]];
        $aviso = '<small class="pw-aviso">' . icone('link', 12) . ($href !== '' ? '<a href="' . e($href) . '">' . e($txt) . '</a>' : e($txt)) . ' para ver este número.</small>';
    }
    return '<div class="pw pw-' . $desenho . ($falta ? ' pw-sem-fonte' : '') . '" data-tipo="' . e($tipo) . '"><div class="pw-cab"><span class="pw-tit"><span>' . e($titulo) . '</span>' . info($dica) . '</span>'
        . '<span class="kpi-ico" aria-hidden="true">' . icone($ico, 15) . '</span></div><div class="pw-corpo">' . ($falta && $desenho === 'numero' ? '' : painel_corpo($tipo, $d)) . $aviso . '</div></div>';
}

function painel_render(PDO $db, string $periodo, string $de, string $ate): void
{
    $usuario = (string)usuario_atual();
    $editar = ($_GET['editar'] ?? '') === '1';
    $aparelho = ($_GET['aparelho'] ?? '') === 'celular' ? 'celular' : 'computador';
    $d = resumo_dados($db, $periodo, $de, $ate);
    $d['despesas'] = painel_despesas($db, $d['dia1'], $d['dia2']);
    $d['ateHora'] = $periodo === 'hoje' ? (int)(new DateTime('now', fuso()))->format('G') : 23;
    $ws = painel_widgets();
    $aqui = './?' . http_build_query(['aba' => 'painel', 'periodo' => $periodo]);

    if ($aviso = aviso_pegar()) {
        echo '<p class="' . ($aviso[1] === 'erro' ? 'erro' : 'aviso-ok') . '">' . e($aviso[0]) . '</p>';
    }
    if (!$editar) {
        $meta = meta_sync_estado();
        $k = kiwify_sync_estado();
        $datas = array_filter([$k['ok_em'], $meta['ok_em']]);
        echo '<section class="bloco painel-cab"><h2>' . com_info('Painel', 'Os números do período escolhido no topo, nos cartões que você escolher e na ordem que quiser. Editar: arraste, aumente, diminua, tire e acrescente métricas. Cada pessoa tem o seu, um para o computador e outro para o celular.') . '</h2>'
            . '<div class="barra-vendas" id="sync"' . (meta_sync_vencida() || kiwify_sync_vencida() ? ' data-sync="1"' : '') . '><span data-sync-texto>' . e(ucfirst($datas ? resumo_ha(min($datas)) : 'ainda não atualizado')) . '</span>'
            . botao_atualizar($aqui) . '<a class="botao" href="' . e($aqui . '&editar=1') . '" data-recarrega>' . icone('lapis', 14) . 'Editar painel</a></div></section>';
        $cel = [];
        foreach (painel_layout($usuario, 'celular') as $i) {
            $cel[$i['tipo']] = $i;
        }
        $desk = painel_layout($usuario, 'computador');
        $noDesk = array_column($desk, 'tipo');
        echo '<div class="painel-grade">';
        foreach (array_merge($desk, array_values(array_filter($cel, fn($i) => !in_array($i['tipo'], $noDesk, true)))) as $i) {
            $m = $cel[$i['tipo']] ?? null;
            $soCel = !in_array($i['tipo'], $noDesk, true);
            $estilo = $soCel ? '' : '--c:' . ($i['x'] + 1) . ';--r:' . ($i['y'] + 1) . ';--w:' . $i['w'] . ';--h:' . $i['h'] . ';';
            $estilo .= $m ? '--mc:' . ($m['x'] + 1) . ';--mr:' . ($m['y'] + 1) . ';--mw:' . $m['w'] . ';--mh:' . $m['h'] . ';' : '';
            echo '<div class="painel-item' . ($m ? '' : ' so-computador') . ($soCel ? ' so-celular' : '') . '" style="' . $estilo . '">' . painel_cartao($i['tipo'], $d) . '</div>';
        }
        echo '</div>';
        return;
    }

    // Modo edicao (GridStack): a barra de cima, a biblioteca de metricas e a grade do aparelho
    $layout = painel_layout($usuario, $aparelho);
    $cols = PAINEL_COLUNAS[$aparelho];
    $padrao = $aparelho === 'celular' ? painel_para_celular(painel_validar(painel_padrao(), 'computador')) : painel_validar(painel_padrao(), 'computador');
    $presentes = array_column($layout, 'tipo');
    $tam = function (string $t) use ($ws, $cols): array {
        [, , $min_w, $min_h, $max_w, $max_h] = $ws[$t][4];
        return $cols < 12 ? [1, $min_h, $cols, $max_h, $ws[$t][2] === 'numero' ? 1 : $cols, $ws[$t][4][1]]
            : [$min_w, $min_h, $max_w, $max_h, $ws[$t][4][0], $ws[$t][4][1]];
    };
    $attrs = function (string $t, ?array $pos = null) use ($tam): string {
        [$minW, $minH, $maxW, $maxH, $w, $h] = $tam($t);
        $a = ' gs-id="' . e($t) . '" gs-min-w="' . $minW . '" gs-min-h="' . $minH . '" gs-max-w="' . $maxW . '" gs-max-h="' . $maxH . '"';
        return $a . ($pos ? ' gs-x="' . $pos['x'] . '" gs-y="' . $pos['y'] . '" gs-w="' . $pos['w'] . '" gs-h="' . $pos['h'] . '"' : ' gs-w="' . $w . '" gs-h="' . $h . '"');
    };
    $tirar = '<button type="button" class="painel-tirar" data-painel-tirar aria-label="Tirar do painel" title="Tirar do painel">' . icone('fechar', 14) . '</button>';
    $trocar = fn(string $ap, string $rot, string $ico) => '<a href="' . e($aqui . '&editar=1&aparelho=' . $ap) . '" data-recarrega data-painel-troca' . ($ap === $aparelho ? ' class="atual" aria-current="true"' : '') . '>' . icone($ico, 14) . e($rot) . '</a>';
    echo '<form class="painel-barra" method="post" action="painel-salvar.php" data-painel-form data-recarrega>'
        . '<input type="hidden" name="csrf" value="' . e(token_csrf()) . '"><input type="hidden" name="aparelho" value="' . e($aparelho) . '"><input type="hidden" name="periodo" value="' . e($periodo) . '"><input type="hidden" name="layout" value="">'
        . '<span class="painel-barra-txt">' . icone('lapis', 15) . 'Você está editando este painel para:</span>'
        . '<span class="segmentos">' . $trocar('computador', 'Computador', 'colunas') . $trocar('celular', 'Celular', 'anuncio') . '</span>'
        . '<span class="painel-barra-acoes"><button type="button" class="discreto neutro" data-painel-padrao>' . icone('restaurar', 14) . 'Voltar ao padrão</button>'
        . '<a class="botao discreto neutro" href="' . e($aqui) . '" data-recarrega data-painel-cancelar>Cancelar</a><button type="submit">' . icone('ok', 14) . 'Salvar</button></span></form>';
    echo '<p class="suave painel-dica">Arraste as métricas da lista para o painel. No painel, arraste o cartão para mudar de lugar e o canto de baixo para mudar o tamanho; o X tira. Esc cancela, Ctrl+S salva.</p>';
    echo '<div class="painel-edicao" data-painel-editar data-painel-colunas="' . $cols . '" data-linha="' . (PAINEL_LINHA_PX + 12) . '" data-padrao="' . e((string)json_encode($padrao)) . '">'
        . '<aside class="painel-biblioteca" aria-label="Métricas disponíveis"><h2>Métricas disponíveis</h2>'
        . '<label class="tcard-busca">' . icone('busca', 14) . '<input type="search" data-painel-busca placeholder="Buscar métrica" aria-label="Buscar métrica"></label>';
    foreach (PAINEL_CATEGORIAS as $cat => $rot) {
        echo '<details open><summary>' . e($rot) . '</summary><div class="painel-lista">';
        foreach ($ws as $t => $w) {
            if ($w[1] !== $cat) {
                continue;
            }
            $usado = in_array($t, $presentes, true);
            $dicaLista = $w[3] . ($w[6] ? ' Precisa: ' . implode(' e ', array_map(fn($f) => PAINEL_FONTE_NOME[$f], $w[6])) . '.' : '');
            echo '<div class="grid-stack-item painel-novo' . ($usado ? ' usado' : '') . '" data-tipo="' . e($t) . '"' . $attrs($t) . ($usado ? ' aria-disabled="true"' : '')
                . ' data-dica-titulo="' . e($w[0]) . '" data-dica="' . e($dicaLista) . '" data-dica-botao>'
                . '<div class="grid-stack-item-content"><span class="painel-novo-ico" aria-hidden="true">' . icone($w[5], 14) . '</span><span>' . e($w[0]) . '</span>'
                . ($usado ? '<small class="painel-novo-usado">no painel</small>' : '') . '</div></div>';
        }
        echo '</div></details>';
    }
    echo '</aside><div class="painel-moldura' . ($aparelho === 'celular' ? ' celular' : '') . '"><div class="grid-stack">';
    foreach ($layout as $i) {
        echo '<div class="grid-stack-item"' . $attrs($i['tipo'], $i) . '><div class="grid-stack-item-content">' . $tirar . painel_cartao($i['tipo'], $d) . '</div></div>';
    }
    echo '</div></div></div><div hidden>';
    // Modelos de todas as metricas (o que entra ao arrastar da lista ou ao voltar ao padrao)
    foreach (array_keys($ws) as $t) {
        echo '<template data-painel-modelo="' . e($t) . '">' . $tirar . painel_cartao($t, $d) . '</template>';
    }
    echo '</div><style>' . (string)@file_get_contents(__DIR__ . '/gridstack.css') . '</style>'
        . '<script src="' . e(admin_base()) . 'gridstack.js?v=' . (int)@filemtime(__DIR__ . '/../gridstack.js') . '"></script>';
}
