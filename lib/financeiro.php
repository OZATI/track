<?php
// DRE (o modulo "financeiro"; na tela, DRE gerencial): o resultado da empresa no periodo, em tres abas:
// a DRE (receita bruta - reembolsos - imposto sobre o faturamento = receita liquida - custos variaveis =
// margem de contribuicao - despesas fixas = resultado, com o ponto de equilibrio), o fluxo de caixa
// (entradas e saidas por dia, como as abas FLUXO e LUCRO da planilha do Allan) e as despesas.
// Entradas = faturamento liquido de todas as vendas aprovadas (qualquer produto e origem).
// Saidas = o que a Meta cobrou (gasto + imposto) e as outras despesas cadastradas aqui
// (hospedagem, ferramentas, equipe...), unicas ou que se repetem todo mes ou todo ano.
//   ROI geral = entradas / saidas   (o "ROI GERAL" da aba FLUXO; acima de 1, a empresa se paga)
// O cadastro grava em gastos.php (POST com login e o token do formulario).
// O caixa e uma tela montavel (lib/grade.php): numeros, graficos e o fluxo dia a dia em blocos que
// cada pessoa arruma pelo lapis da barra lateral; o cadastro das despesas fica embaixo, fixo.

require_once __DIR__ . '/grade.php';
require_once __DIR__ . '/graficos.php';

const FIN_REPETE = ['unico' => 'Só uma vez', 'mensal' => 'Todo mês', 'anual' => 'Todo ano'];
const FIN_CATEGORIAS = ['Hospedagem', 'Ferramentas', 'Equipe', 'Impostos', 'Taxas', 'Outros'];

// "R$ 1.234,56", "1234,56", "40" ou "40.5" em centavos (ou null se nao for um valor)
function fin_centavos(string $txt): ?int
{
    $t = preg_replace('/[^\d,.]/', '', $txt) ?? '';
    if ($t === '') {
        return null;
    }
    if (strpos($t, ',') !== false) {
        $t = str_replace(',', '.', str_replace('.', '', $t)); // 1.234,56 -> 1234.56
    } elseif (substr_count($t, '.') > 1 || preg_match('/\.\d{3}$/', $t)) {
        $t = str_replace('.', '', $t); // 1.234 -> 1234
    }
    if (!is_numeric($t)) {
        return null;
    }
    $c = (int)round((float)$t * 100);
    return $c > 0 && $c <= 100000000 ? $c : null; // ate R$ 1 milhao por lancamento
}

function fin_data_valida($d): bool
{
    return is_string($d) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

// Dias em que uma despesa cai entre $dia1 e $dia2 (os dois inclusive). Todo mes: no mesmo dia
// do mes do inicio (dia 31 cai no ultimo dia dos meses mais curtos); todo ano: no mesmo dia e mes.
function fin_ocorrencias(array $g, string $dia1, string $dia2): array
{
    $inicio = (string)$g['inicio'];
    $fim = $g['fim'] ? min((string)$g['fim'], $dia2) : $dia2;
    if ($inicio > $fim) {
        return [];
    }
    if ($g['repete'] === 'unico') {
        return $inicio >= $dia1 && $inicio <= $dia2 ? [$inicio] : [];
    }
    [$a, $m, $d] = array_map('intval', explode('-', $inicio));
    $passo = $g['repete'] === 'anual' ? 12 : 1;
    $dias = [];
    for ($i = 0; $i < 2400; $i += $passo) { // ate 200 anos de repeticao
        $mes = ($m - 1 + $i) % 12 + 1;
        $ano = $a + intdiv($m - 1 + $i, 12);
        $ultimo = (int)(new DateTime(sprintf('%04d-%02d-01', $ano, $mes)))->format('t');
        $dia = sprintf('%04d-%02d-%02d', $ano, $mes, min($d, $ultimo));
        if ($dia > $fim) {
            break;
        }
        if ($dia >= $dia1) {
            $dias[] = $dia;
        }
    }
    return $dias;
}

function fin_gastos(PDO $db): array
{
    return consulta($db, 'SELECT * FROM gastos ORDER BY inicio DESC, id DESC', []);
}

// Entradas e saidas por dia: [dia => ['entradas', 'anuncios', 'despesas', 'bruto', 'gasto']]. Entradas e
// o liquido das vendas aprovadas (bruto: o valor cobrado); anuncios e o gasto da Meta com o imposto.
function fin_por_dia(PDO $db, string $dia1, string $dia2, array $gastos): array
{
    $tz = fuso();
    $utc = new DateTimeZone('UTC');
    $pct = gestor_imposto_pct();
    $dias = [];
    $vazio = ['entradas' => 0, 'anuncios' => 0, 'despesas' => 0, 'bruto' => 0, 'gasto' => 0];
    $de = (new DateTime($dia1, $tz))->setTimezone($utc)->format('Y-m-d H:i:s');
    $ate = (new DateTime($dia2, $tz))->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
    foreach (consulta($db, 'SELECT * FROM vendas WHERE recebida_em >= ? AND recebida_em < ?', [$de, $ate]) as $v) {
        if (!aprovada($v)) {
            continue;
        }
        $dia = (new DateTime($v['recebida_em'], $utc))->setTimezone($tz)->format('Y-m-d');
        $dias[$dia] = $dias[$dia] ?? $vazio;
        $dias[$dia]['entradas'] += (int)($v['valor_liquido'] ?? $v['valor'] ?? 0);
        $dias[$dia]['bruto'] += (int)($v['valor'] ?? 0);
    }
    foreach (consulta($db, 'SELECT dia, SUM(gasto) AS g FROM meta_gasto WHERE dia >= ? AND dia <= ? GROUP BY dia', [$dia1, $dia2]) as $g) {
        $dias[$g['dia']] = $dias[$g['dia']] ?? $vazio;
        $dias[$g['dia']]['anuncios'] += (int)$g['g'] + (int)round((int)$g['g'] * $pct / 100);
        $dias[$g['dia']]['gasto'] += (int)$g['g'];
    }
    foreach ($gastos as $g) {
        if (!(int)($g['ativo'] ?? 1)) {
            continue; // despesa pausada nao conta
        }
        foreach (fin_ocorrencias($g, $dia1, $dia2) as $dia) {
            $dias[$dia] = $dias[$dia] ?? $vazio;
            $dias[$dia]['despesas'] += (int)$g['valor'];
        }
    }
    ksort($dias);
    return $dias;
}

// Dias do caixa no periodo: em "Tudo", do primeiro movimento (venda, gasto ou despesa) ate hoje,
// no maximo 1 ano para tras
function fin_dias(PDO $db, string $periodo, array $gastos): array
{
    $hoje = (new DateTime('today', fuso()))->format('Y-m-d');
    [$dia1, $dia2] = gestor_dias($periodo);
    if ($periodo === 'tudo') {
        $primeiros = array_filter([
            consulta($db, 'SELECT MIN(dia) AS d FROM meta_gasto', [])[0]['d'] ?? null,
            ($v = consulta($db, 'SELECT MIN(recebida_em) AS d FROM vendas', [])[0]['d'] ?? null) ? data_local($v, 'Y-m-d') : null,
            $gastos ? min(array_column($gastos, 'inicio')) : null,
        ]);
        $dia1 = max($primeiros ? min($primeiros) : $hoje, (new DateTime($hoje))->modify('-365 days')->format('Y-m-d'));
        $dia2 = $hoje;
    }
    return [$dia1, $dia2];
}

// Somas do caixa: entradas, anuncios, despesas, saldo e ROI (entradas / saidas), mais o bruto, o gasto
// e o imposto da Meta separados (para a DRE)
function fin_somas(array $porDia): array
{
    $soma = fn(string $c) => array_sum(array_column($porDia, $c));
    $entradas = $soma('entradas');
    $saidas = $soma('anuncios') + $soma('despesas');
    return ['entradas' => $entradas, 'anuncios' => $soma('anuncios'), 'despesas' => $soma('despesas'), 'saldo' => $entradas - $saidas, 'roi' => $saidas ? $entradas / $saidas : null,
        'bruto' => $soma('bruto'), 'gasto' => $soma('gasto'), 'imposto' => $soma('anuncios') - $soma('gasto')];
}

// Despesas que caem no periodo, por categoria (as pausadas nao contam), da maior para a menor. $tipos:
// so as que repetem assim ('unico' sao as variaveis; 'mensal' e 'anual', as fixas)
function fin_categorias(array $gastos, string $dia1, string $dia2, array $tipos = []): array
{
    $cats = [];
    foreach ($gastos as $g) {
        if ($tipos && !in_array($g['repete'], $tipos, true)) {
            continue;
        }
        if ((int)($g['ativo'] ?? 1) && ($q = count(fin_ocorrencias($g, $dia1, $dia2)))) {
            $cat = trim((string)$g['categoria']) !== '' ? (string)$g['categoria'] : 'Sem categoria';
            $cats[$cat] = ($cats[$cat] ?? 0) + $q * (int)$g['valor'];
        }
    }
    arsort($cats);
    return $cats;
}

// Imposto sobre o faturamento (a nota fiscal), configurado na aba Despesas, de um dos dois jeitos:
// - % sobre o faturamento (Simples Nacional, Lucro Presumido: a aliquota efetiva que o contador passar),
//   sobre a receita bruta menos os reembolsos;
// - valor fixo por mes (MEI: o DAS), dividido pelos dias do mes (o periodo leva a parte dos dias dele).
// O regime e so o nome (o MEI pode ser desenquadrado e virar Simples: troca o regime e o jeito).
const FIN_REGIMES = ['nenhum' => 'Não calcular', 'mei' => 'MEI', 'simples' => 'Simples Nacional', 'presumido' => 'Lucro Presumido', 'outro' => 'Outro'];
const FIN_IMPOSTO_MODOS = ['percentual' => '% sobre o faturamento', 'fixo' => 'Valor fixo por mês'];

// ['regime', 'modo' (percentual ou fixo), 'pct' (%), 'fixo' (centavos por mes)]
function fin_imposto_config(): array
{
    $regime = (string)(ajuste('fin_regime') ?? 'nenhum');
    $regime = isset(FIN_REGIMES[$regime]) ? $regime : 'nenhum';
    $modo = (string)(ajuste('fin_imposto_modo') ?? '');
    $modo = isset(FIN_IMPOSTO_MODOS[$modo]) ? $modo : ($regime === 'mei' ? 'fixo' : 'percentual');
    return ['regime' => $regime, 'modo' => $modo, 'pct' => max(0.0, min(50.0, (float)(ajuste('fin_imposto_pct') ?? 0))), 'fixo' => max(0, (int)(ajuste('fin_imposto_fixo') ?? 0))];
}

// O imposto sobre o faturamento no periodo: % sobre (bruta - reembolsos) ou o fixo do mes dividido
// pelos dias (cada dia do periodo leva 1/dias do mes dele)
function fin_imposto_periodo(array $cfg, int $base, string $dia1, string $dia2): int
{
    if ($cfg['regime'] === 'nenhum') {
        return 0;
    }
    if ($cfg['modo'] === 'percentual') {
        return (int)round($base * $cfg['pct'] / 100);
    }
    $hoje = (new DateTime('today', fuso()))->format('Y-m-d');
    $total = 0.0;
    for ($d = new DateTime(max($dia1, '2020-01-01')); $d->format('Y-m-d') <= min($dia2, $hoje); $d->modify('+1 day')) {
        $total += $cfg['fixo'] / (int)$d->format('t');
    }
    return (int)round($total);
}

// O jeito do imposto em palavras ("MEI · R$ 81,05 por mês", "Simples Nacional · 6,00%")
function fin_imposto_texto(array $cfg): string
{
    if ($cfg['regime'] === 'nenhum') {
        return 'não configurado';
    }
    return FIN_REGIMES[$cfg['regime']] . ' · ' . ($cfg['modo'] === 'fixo' ? reais($cfg['fixo']) . ' por mês' : number_format($cfg['pct'], 2, ',', '.') . '% do faturamento');
}

// Os numeros da DRE no periodo (DRE gerencial): receita bruta (todas as vendas pagas, mesmo as que
// voltaram) - reembolsos e chargebacks - imposto sobre o faturamento = receita liquida - custos
// variaveis (taxas da plataforma, anuncios, imposto da Meta e as despesas "So uma vez") = margem de
// contribuicao - despesas fixas (as que se repetem todo mes ou todo ano) = resultado. O ponto de
// equilibrio e a receita liquida que paga as fixas com a margem de contribuicao de agora.
function fin_dre_dados(PDO $db, string $dia1, string $dia2, array $gastos, array $somas): array
{
    $tz = fuso();
    $utc = new DateTimeZone('UTC');
    $de = (new DateTime($dia1, $tz))->setTimezone($utc)->format('Y-m-d H:i:s');
    $ate = (new DateTime($dia2, $tz))->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
    $devolvido = $nDevolvidas = 0;
    foreach (consulta($db, 'SELECT * FROM vendas WHERE recebida_em >= ? AND recebida_em < ?', [$de, $ate]) as $v) {
        if (in_array(situacao($v)[0], ['Reembolsada', 'Chargeback'], true)) {
            $devolvido += (int)($v['valor'] ?? 0);
            $nDevolvidas += eh_bump($v) ? 0 : 1;
        }
    }
    $imposto = fin_imposto_config();
    $bruta = (int)$somas['bruto'] + $devolvido;
    $impFat = fin_imposto_periodo($imposto, $bruta - $devolvido, $dia1, $dia2);
    $liquida = $bruta - $devolvido - $impFat;
    $taxas = (int)$somas['bruto'] - (int)$somas['entradas'];
    $variaveis = fin_categorias($gastos, $dia1, $dia2, ['unico']);
    $fixas = fin_categorias($gastos, $dia1, $dia2, ['mensal', 'anual']);
    $mc = $liquida - $taxas - (int)$somas['gasto'] - (int)$somas['imposto'] - array_sum($variaveis);
    $fixasTotal = array_sum($fixas);
    $resultado = $mc - $fixasTotal;
    $mcPct = $liquida > 0 ? $mc / $liquida : null;
    // Sem despesa fixa nao ha ponto de equilibrio a mostrar (qualquer venda ja paga as fixas)
    $equilibrio = $fixasTotal > 0 && $mcPct !== null && $mcPct > 0 ? (int)round($fixasTotal / $mcPct) : null;
    return ['bruta' => $bruta, 'devolvido' => $devolvido, 'nDevolvidas' => $nDevolvidas, 'imposto' => $imposto, 'impFat' => $impFat,
        'liquida' => $liquida, 'taxas' => $taxas, 'gasto' => (int)$somas['gasto'], 'impMeta' => (int)$somas['imposto'], 'variaveis' => $variaveis,
        'mc' => $mc, 'mcPct' => $mcPct, 'fixas' => $fixas, 'fixasTotal' => $fixasTotal, 'resultado' => $resultado, 'equilibrio' => $equilibrio];
}

// Layout padrao da aba DRE no computador: [tipo, x, y, largura, altura]
const FIN_PADRAO = [
    ['IncomeStatement', 0, 0, 6, 6], ['DreResult', 6, 0, 3, 1], ['DreMargin', 9, 0, 3, 1],
    ['DreNet', 6, 1, 3, 1], ['DreContribution', 9, 1, 3, 1],
    ['BreakEven', 6, 2, 3, 1], ['CashRoi', 9, 2, 3, 1],
    ['CostStructure', 6, 3, 6, 3],
    ['ExpensesByCategory', 0, 6, 4, 2], ['CashRoiGauge', 4, 6, 4, 2], ['DreGross', 8, 6, 4, 1], ['Refunds', 8, 7, 4, 1],
];

// Layout padrao da aba Fluxo de caixa
const FLUXO_PADRAO = [
    ['CashIn', 0, 0, 3, 1], ['AdsCost', 3, 0, 3, 1], ['Expenses', 6, 0, 2, 1], ['Balance', 8, 0, 2, 1], ['CashMargin', 10, 0, 2, 1],
    ['CashFlowChart', 0, 1, 8, 3], ['CumulativeBalance', 8, 1, 4, 3],
    ['BalanceByDay', 0, 4, 12, 3],
    ['CashFlowTable', 0, 7, 12, 4],
];

// Os blocos das duas abas montaveis do DRE (a DRE e o fluxo de caixa)
function fin_blocos(): array
{
    $p = number_format(gestor_imposto_pct(), 2, ',', '.') . '%';
    $num = [3, 1, 2, 1, 6, 1];
    $pequeno = [2, 1, 2, 1, 4, 1];
    $graf = [6, 3, 4, 2, 12, 6];
    return [
        'IncomeStatement' => ['DRE do período', 'resultado', 'grafico', 'Demonstração do resultado gerencial. Receita bruta (todas as vendas pagas no período, com order bump) − reembolsos e chargebacks − imposto sobre o faturamento = receita líquida. − Custos variáveis (taxas da plataforma, anúncios, imposto sobre os anúncios e as despesas de uma vez só) = margem de contribuição. − Despesas fixas (as que se repetem todo mês ou ano) = resultado. A % é sobre a receita líquida; a seta compara com o período anterior do mesmo tamanho, quando ele tem dados.', [6, 6, 5, 4, 12, 10], 'lista'],
        'DreResult' => ['Resultado do período', 'resultado', 'numero', 'Margem de contribuição − despesas fixas: o que sobra para a empresa no período (a última linha da DRE).', $num, 'vendas'],
        'DreMargin' => ['Margem do resultado', 'resultado', 'numero', 'Resultado ÷ receita líquida.', $pequeno, 'atividade'],
        'DreNet' => ['Receita líquida', 'resultado', 'numero', 'Receita bruta − reembolsos e chargebacks − imposto sobre o faturamento.', $num, 'carteira'],
        'DreGross' => ['Receita bruta', 'resultado', 'numero', 'Valor cobrado de todas as vendas pagas no período, com order bump, inclusive as que depois foram reembolsadas.', $num, 'carteira'],
        'Refunds' => ['Reembolsos e chargebacks', 'resultado', 'numero', 'Valor cobrado das vendas do período que foram reembolsadas ou contestadas no cartão.', $num, 'restaurar'],
        'DreContribution' => ['Margem de contribuição', 'resultado', 'numero', 'Receita líquida − custos variáveis (taxas, anúncios, imposto sobre eles e despesas de uma vez só): o que cada venda deixa para pagar as despesas fixas.', $num, 'atividade'],
        'BreakEven' => ['Ponto de equilíbrio', 'resultado', 'numero', 'A receita líquida que paga as despesas fixas do período com a margem de contribuição de agora (despesas fixas ÷ margem de contribuição em %). Embaixo, quanto dela já entrou.', $num, 'conferencia'],
        'CashRoi' => ['ROI geral', 'resultado', 'numero', 'Entradas ÷ saídas, como o ROI GERAL da aba FLUXO: quanto voltou para cada real que saiu, contando todas as despesas. Vermelho abaixo de 1, laranja de 1 até 2, verde de 2 para cima.', $pequeno, 'grafico'],
        'CashRoiGauge' => ['ROI no mostrador', 'graficos', 'grafico', 'O ROI geral do caixa (entradas ÷ saídas) num mostrador de 0 a 4: vermelho abaixo de 1, laranja de 1 até 2, verde de 2 para cima.', [4, 3, 2, 2, 6, 4], 'grafico'],
        'CostStructure' => ['Para onde vai a receita', 'graficos', 'grafico', 'A receita bruta do período dividida entre reembolsos, impostos (da nota e dos anúncios), taxas da plataforma, anúncios, despesas e o resultado, em % da receita bruta.', [6, 3, 4, 3, 12, 6], 'grafico'],
        'ExpensesByCategory' => ['Despesas por categoria', 'graficos', 'grafico', 'As despesas cadastradas do período (sem os anúncios) por categoria, e a fatia de cada uma.', [4, 2, 3, 2, 12, 6], 'lista'],
        'CashIn' => ['Entradas', 'caixa', 'numero', 'Faturamento líquido (o que a plataforma repassa) de todas as vendas aprovadas no período, com order bump, de qualquer produto e origem.', $num, 'carteira'],
        'AdsCost' => ['Anúncios', 'caixa', 'numero', 'O que a Meta cobrou: gasto + imposto de ' . $p . ' sobre ele.', $num, 'meta'],
        'Expenses' => ['Outras despesas', 'caixa', 'numero', 'Despesas cadastradas na aba Despesas que caem no período (as que se repetem contam em cada mês ou ano).', $num, 'lista'],
        'Balance' => ['Saldo', 'caixa', 'numero', 'Entradas − saídas (anúncios + outras despesas): o que sobrou no caixa no período.', $num, 'vendas'],
        'CashMargin' => ['Margem do caixa', 'caixa', 'numero', 'Saldo ÷ entradas: quanto de cada real que entrou sobra depois de todas as saídas.', $pequeno, 'atividade'],
        'CashFlowChart' => ['Entradas × saídas por dia', 'graficos', 'grafico', 'O que entrou (vendas aprovadas, líquido) e o que saiu (anúncios com imposto + despesas) em cada dia com movimento.', [8, 3, 4, 2, 12, 6], 'grafico'],
        'BalanceByDay' => ['Saldo por dia', 'graficos', 'grafico', 'Entradas − saídas de cada dia com movimento: verde sobrou, vermelho faltou.', $graf, 'vendas'],
        'CumulativeBalance' => ['Saldo acumulado', 'graficos', 'grafico', 'A soma dos saldos desde o primeiro dia do período: mostra se o caixa cresce ou encolhe.', $graf, 'atividade'],
        'CashFlowTable' => ['Fluxo de caixa', 'graficos', 'grafico', 'Um dia por linha, só os dias com movimento: entradas, saídas e o saldo acumulado desde o começo do período.', [8, 4, 6, 3, 12, 12], 'calendario'],
    ];
}

// A aba DRE para o motor (lib/grade.php): a demonstracao e os numeros do resultado
function financeiro_grade(): array
{
    $todos = fin_blocos();
    $aqui = ['IncomeStatement', 'DreResult', 'DreMargin', 'DreNet', 'DreGross', 'Refunds', 'DreContribution', 'BreakEven', 'CashRoi', 'CashRoiGauge', 'CostStructure', 'ExpensesByCategory'];
    return [
        'id' => 'financeiro',
        'titulo' => 'o DRE',
        'acesso' => 'financeiro',
        'categorias' => ['resultado' => 'Resultado', 'graficos' => 'Gráficos'],
        'blocos' => array_intersect_key($todos, array_flip($aqui)),
        'padrao' => FIN_PADRAO,
        'desenhar' => 'financeiro_bloco',
    ];
}

// A aba Fluxo de caixa: entradas e saidas dia a dia e o saldo acumulado
function fluxo_grade(): array
{
    $todos = fin_blocos();
    $aqui = ['CashIn', 'AdsCost', 'Expenses', 'Balance', 'CashMargin', 'CashFlowChart', 'BalanceByDay', 'CumulativeBalance', 'CashFlowTable'];
    return [
        'id' => 'fluxo',
        'titulo' => 'o Fluxo de caixa',
        'acesso' => 'financeiro',
        'categorias' => ['caixa' => 'Caixa', 'graficos' => 'Gráficos'],
        'blocos' => array_intersect_key($todos, array_flip($aqui)),
        'padrao' => FLUXO_PADRAO,
        'desenhar' => 'financeiro_bloco',
    ];
}

// Os numeros do DRE e do caixa para os blocos: o periodo e o anterior (para a seta, so quando ele tem
// movimento: conta nova nao vira "subiu de 0")
function financeiro_ctx(PDO $db, string $periodo, array $gastos): array
{
    [$dia1, $dia2] = fin_dias($db, $periodo, $gastos);
    $porDia = fin_por_dia($db, $dia1, $dia2, $gastos);
    $somas = fin_somas($porDia);
    $ctx = ['periodo' => $periodo, 'dia1' => $dia1, 'dia2' => $dia2, 'porDia' => $porDia, 'somas' => $somas, 'antes' => null, 'antesNome' => '',
        'categorias' => fin_categorias($gastos, $dia1, $dia2), 'dre' => fin_dre_dados($db, $dia1, $dia2, $gastos, $somas), 'dreAntes' => null];
    if (($ant = grade_periodo_anterior($periodo)) !== null) {
        [$a1, $a2] = periodo_dias($ant);
        $antes = fin_somas(fin_por_dia($db, $a1, $a2, $gastos));
        $dreAntes = fin_dre_dados($db, $a1, $a2, $gastos, $antes);
        if ($antes['entradas'] || $antes['anuncios'] || $antes['despesas'] || $dreAntes['devolvido']) {
            $ctx['antes'] = $antes;
            $ctx['dreAntes'] = $dreAntes;
            $ctx['antesNome'] = periodo_rotulo($ant);
        }
    }
    return $ctx;
}

// O miolo de cada bloco do DRE e do caixa ($c: financeiro_ctx)
function financeiro_bloco(string $tipo, array $c): string
{
    $s = $c['somas'];
    $a = $c['antes'];
    $r = $c['dre'];
    $ra = $c['dreAntes'];
    $n = fn(float $v, int $k = 2) => number_format($v, $k, ',', '.');
    $num = fn(string $valor, string $classe = '', string $delta = '', string $sub = '') => '<div class="pw-valor"><div class="pw-valor-txt"><b class="pw-num' . ($classe !== '' ? ' ' . $classe : '') . '">' . e($valor) . '</b>'
        . ($delta !== '' || $sub !== '' ? '<small>' . $delta . ($delta !== '' && $sub !== '' ? ' ' : '') . e($sub) . '</small>' : '') . '</div></div>';
    // Seta contra o periodo anterior do mesmo tamanho (caixa e DRE)
    $delta = fn(string $k, bool $menor = false, bool $neutro = false) => $a && ($s[$k] || $a[$k]) ? gestor_delta($s[$k] === null ? null : (float)$s[$k], $a[$k] === null ? null : (float)$a[$k], $menor, $neutro,
        ($a[$k] === null ? 'N/A' : ($k === 'roi' ? $n($a[$k]) : reais((int)$a[$k]))) . ' (' . $c['antesNome'] . ')') : '';
    $deltaDre = fn(string $k, bool $menor = false) => $ra && ($r[$k] || $ra[$k]) ? gestor_delta((float)$r[$k], (float)$ra[$k], $menor, false, reais((int)$ra[$k]) . ' (' . $c['antesNome'] . ')') : '';
    $dias = array_keys($c['porDia']);
    $col = fn(string $k) => array_values(array_map(fn($m) => $k === 'saidas' ? $m['anuncios'] + $m['despesas'] : ($k === 'saldo' ? $m['entradas'] - $m['anuncios'] - $m['despesas'] : $m[$k]), $c['porDia']));
    $sinal = fn(int $v) => $v < 0 ? 'negativo' : ($v > 0 ? 'positivo' : '');
    $pctDe = fn(int $v, int $base) => $base ? $n($v * 100 / $base, 1) . '%' : '—';
    $nada = '<p class="suave">Nenhum movimento no período.</p>';
    switch ($tipo) {
        case 'IncomeStatement':
            return fin_dre_html($c);
        case 'DreResult':
            return $num(reais($r['resultado']), $sinal($r['resultado']), $deltaDre('resultado'));
        case 'DreMargin':
            return $num($r['liquida'] ? $pctDe($r['resultado'], $r['liquida']) : '—', $r['liquida'] ? $sinal($r['resultado']) : '');
        case 'DreNet':
            return $num(reais($r['liquida']), '', $deltaDre('liquida'));
        case 'DreGross':
            return $num(reais($r['bruta']), '', $deltaDre('bruta'));
        case 'Refunds':
            return $num(reais($r['devolvido']), $r['devolvido'] ? 'negativo' : '', $deltaDre('devolvido', true), $r['nDevolvidas'] . ' venda' . ($r['nDevolvidas'] === 1 ? '' : 's'));
        case 'DreContribution':
            return $num(reais($r['mc']), $sinal($r['mc']), $deltaDre('mc'), $r['liquida'] ? $pctDe($r['mc'], $r['liquida']) . ' da receita líquida' : '');
        case 'BreakEven':
            if (!$r['fixasTotal']) {
                return $num('—', '', '', 'sem despesas fixas no período');
            }
            if ($r['equilibrio'] === null) {
                return $num('Sem margem', 'negativo', '', 'a margem de contribuição não paga as fixas');
            }
            return $num(reais($r['equilibrio']), $r['liquida'] >= $r['equilibrio'] ? 'positivo' : '', '', $pctDe($r['liquida'], $r['equilibrio']) . ' atingido');
        case 'CostStructure':
            // Cada parte em reais e em % da receita bruta (o resultado so quando sobra)
            $partes = array_filter(['Reembolsos e chargebacks' => $r['devolvido'], 'Impostos (nota e anúncios)' => $r['impFat'] + $r['impMeta'], 'Taxas da plataforma' => $r['taxas'],
                'Anúncios' => $r['gasto'], 'Despesas' => array_sum($r['variaveis']) + $r['fixasTotal'], 'Resultado' => max(0, $r['resultado'])], fn($v) => $v > 0);
            return resumo_lista_aneis(array_map(fn($k, $v) => [e($k), reais($v), $r['bruta'] ? $v * 100 / $r['bruta'] : null], array_keys($partes), $partes), 'Nada no período.');
        case 'CashIn':
            return $num(reais($s['entradas']), '', $delta('entradas'));
        case 'AdsCost':
            return $num(reais($s['anuncios']), '', $delta('anuncios', false, true));
        case 'Expenses':
            return $num(reais($s['despesas']), '', $delta('despesas', true));
        case 'Balance':
            return $num(reais($s['saldo']), $sinal($s['saldo']), $delta('saldo'));
        case 'CashRoi':
            return $num($s['roi'] === null ? 'N/A' : $n($s['roi']), cor_roi($s['roi']), $delta('roi'));
        case 'CashMargin':
            return $num($s['entradas'] ? $n($s['saldo'] * 100 / $s['entradas'], 1) . '%' : '—', $s['entradas'] ? $sinal($s['saldo']) : '');
        case 'CashFlowChart':
            return $dias ? grafico_area($dias, [['Entradas', '#16A34A', $col('entradas')], ['Saídas', '#DC2626', $col('saidas')]], 'Entradas e saídas por dia') : $nada;
        case 'BalanceByDay':
            return $dias ? grafico_barras($dias, $col('saldo'), 'Saldo') : $nada;
        case 'CumulativeBalance':
            $acum = 0;
            $serie = array_map(function ($v) use (&$acum) { return $acum += $v; }, $col('saldo'));
            return $dias ? grafico_area($dias, [['Saldo acumulado', '#1D6FF2', $serie]], 'Saldo acumulado') : $nada;
        case 'CashRoiGauge':
            return grafico_velocimetro($s['roi'], 'Entradas ÷ saídas');
        case 'ExpensesByCategory':
            $tot = array_sum($c['categorias']);
            return resumo_lista_aneis(array_map(fn($k, $v) => [e((string)$k), reais($v), $tot ? $v * 100 / $tot : null], array_keys($c['categorias']), $c['categorias']), 'Nenhuma despesa no período.');
        case 'CashFlowTable':
            $semana = ['', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb', 'dom'];
            $h = '<div class="tabela gestor pw-tabela"><table><tr><th class="nome">Dia</th><th>' . com_info('Entradas', 'Vendas aprovadas do dia, líquido da plataforma.') . '</th><th>' . com_info('Anúncios', 'Gasto na Meta + imposto.') . '</th><th>' . com_info('Despesas', 'Despesas cadastradas que caem no dia.') . '</th>'
                . '<th>' . com_info('Saldo do dia', 'Entradas − anúncios − despesas.') . '</th><th>' . com_info('Acumulado', 'Soma dos saldos desde o primeiro dia do período.') . '</th><th>' . com_info('ROI do dia', 'Entradas ÷ saídas do dia.') . '</th></tr>';
            $acum = 0;
            foreach ($c['porDia'] as $dia => $m) {
                $saiu = $m['anuncios'] + $m['despesas'];
                $sd = $m['entradas'] - $saiu;
                $acum += $sd;
                $roi = $saiu ? $m['entradas'] / $saiu : null;
                $d = new DateTime($dia);
                $h .= '<tr><td class="nome"><span class="suave">' . $semana[(int)$d->format('N')] . '</span> ' . e($d->format('d/m/Y')) . '</td><td>' . e(reais($m['entradas'])) . '</td><td>' . e(reais($m['anuncios'])) . '</td>'
                    . '<td>' . ($m['despesas'] ? e(reais($m['despesas'])) : '<span class="suave">—</span>') . '</td><td><span class="' . $sinal($sd) . '">' . e(reais($sd)) . '</span></td>'
                    . '<td><span class="' . ($acum < 0 ? 'negativo' : 'positivo') . '">' . e(reais($acum)) . '</span></td><td>' . ($roi === null ? 'N/A' : '<span class="' . cor_roi($roi) . '">' . $n($roi) . '</span>') . '</td></tr>';
            }
            if (!$c['porDia']) {
                $h .= '<tr><td colspan="7" class="suave">Nenhum movimento no período.</td></tr>';
            }
            return $h . '</table></div>';
    }
    return '';
}

// A DRE do periodo em linhas (fin_dre_dados), com os grupos de custos variaveis e despesas fixas. Em
// cada linha, a % sobre a receita liquida e a seta contra o periodo anterior (so quando ele tem dados).
// A linha de reembolsos leva ao CRM filtrado, quando o CRM existe (crm_link_reembolsos).
function fin_dre_html(array $c): string
{
    $r = $c['dre'];
    $ra = $c['dreAntes'];
    $antes = fn(string $k) => $ra ? (int)$ra[$k] : null;
    $regime = fin_imposto_texto($r['imposto']);
    $linkReemb = function_exists('crm_link_reembolsos') ? (string)crm_link_reembolsos($c['dia1'], $c['dia2']) : '';
    $linkImposto = $r['imposto']['regime'] === 'nenhum' ? 'financeiro.php?' . http_build_query(['aba' => 'despesas', 'periodo' => $c['periodo']]) . '#imposto' : '';
    // [rotulo, valor, antes ou null, tipo (receita, deducao, subtotal, resultado, grupo), sobe e ruim?, link, nota embaixo do nome]
    $linhas = [
        ['Receita bruta', $r['bruta'], $antes('bruta'), 'receita', false, ''],
        ['(−) Reembolsos e chargebacks', $r['devolvido'], $antes('devolvido'), 'deducao', true, $linkReemb],
        ['(−) Impostos sobre o faturamento', $r['impFat'], $antes('impFat'), 'deducao', true, $linkImposto, $regime],
        ['(=) Receita líquida', $r['liquida'], $antes('liquida'), 'subtotal', false, ''],
        ['Custos variáveis', 0, null, 'grupo', false, ''],
        ['(−) Taxas da plataforma', $r['taxas'], $antes('taxas'), 'deducao', true, ''],
        ['(−) Anúncios', $r['gasto'], $antes('gasto'), 'deducao', true, ''],
        ['(−) Imposto sobre os anúncios', $r['impMeta'], $antes('impMeta'), 'deducao', true, ''],
    ];
    $porCat = function (string $k) use ($r, $ra, &$linhas) {
        $cats = $r[$k];
        foreach (array_keys($ra[$k] ?? []) as $cat) {
            $cats[$cat] = $cats[$cat] ?? 0; // categoria que so teve despesa no periodo anterior
        }
        foreach ($cats as $cat => $v) {
            $linhas[] = ['(−) ' . $cat, (int)$v, $ra ? (int)($ra[$k][$cat] ?? 0) : null, 'deducao', true, ''];
        }
    };
    $porCat('variaveis');
    $linhas[] = ['(=) Margem de contribuição', $r['mc'], $antes('mc'), 'subtotal', false, ''];
    $linhas[] = ['Despesas fixas', 0, null, 'grupo', false, ''];
    $porCat('fixas');
    if (!$r['fixas'] && !($ra['fixas'] ?? [])) {
        $linhas[] = ['(−) Nenhuma despesa fixa', 0, $ra ? 0 : null, 'deducao', true, ''];
    }
    $linhas[] = ['(=) Resultado do período', $r['resultado'], $antes('resultado'), 'resultado', false, ''];
    $liq = $r['liquida'];
    $pct = fn(int $v) => $liq ? number_format($v * 100 / $liq, 1, ',', '.') . '%' : '—';
    $h = '<div class="tabela pw-tabela"><table class="dre"><thead><tr><th class="nome">Conta</th><th>' . ($ra ? com_info('Valor', 'A seta compara com o período anterior do mesmo tamanho (' . $c['antesNome'] . ').') : 'Valor') . '</th>'
        . '<th>' . com_info('% da receita', 'Sobre a receita líquida: quanto de cada real que entrou vai para cada linha.') . '</th></tr></thead><tbody>';
    foreach ($linhas as $l) {
        [$rot, $v, $vAntes, $tipo, $menor, $link, $nota] = $l + [6 => ''];
        if ($tipo === 'grupo') {
            $h .= '<tr class="dre-grupo"><td colspan="3">' . e($rot) . '</td></tr>';
            continue;
        }
        $cor = in_array($tipo, ['resultado', 'subtotal'], true) && $v < 0 ? 'negativo' : ($tipo === 'resultado' && $v > 0 ? 'positivo' : '');
        $seta = $ra ? gestor_delta((float)$v, $vAntes === null ? null : (float)$vAntes, $menor, false, reais((int)$vAntes) . ' (' . $c['antesNome'] . ')') : '';
        $nome = ($link !== '' ? '<a href="' . e($link) . '">' . e($rot) . '</a>' : e($rot)) . ($nota !== '' ? '<small class="dre-nota">' . e($nota) . '</small>' : '');
        $h .= '<tr class="dre-' . $tipo . '"><td class="nome">' . $nome . '</td><td class="dre-valor"><span class="' . $cor . '">' . e(($tipo === 'deducao' && $v ? '− ' : '') . reais($v)) . '</span>'
            . ($seta !== '' ? ' ' . $seta : '') . '</td><td class="suave">' . e($pct($v)) . '</td></tr>';
    }
    $margem = $liq ? $r['resultado'] * 100 / $liq : null;
    $pe = $r['equilibrio'] === null ? ($r['fixasTotal'] ? 'a margem de contribuição não paga as despesas fixas' : 'sem despesas fixas no período')
        : reais($r['equilibrio']) . ' de receita líquida (' . number_format($liq * 100 / max(1, $r['equilibrio']), 0, ',', '.') . '% atingido)';
    return $h . '</tbody></table></div><p class="dre-margem"><span>' . com_info('Margem do resultado', 'Resultado ÷ receita líquida.') . ' <b class="' . ($margem === null ? '' : ($margem < 0 ? 'negativo' : 'positivo')) . '">'
        . e($margem === null ? '—' : number_format($margem, 1, ',', '.') . '%') . '</b></span><span class="dre-pe"><span>' . com_info('Ponto de equilíbrio', 'A receita líquida que paga as despesas fixas com a margem de contribuição de agora: despesas fixas ÷ margem de contribuição em %.') . '</span> ' . e($pe) . '</span></p>';
}

// Abas do DRE: a demonstracao (montavel), o fluxo de caixa (montavel) e o cadastro das despesas
const FIN_ABAS = ['dre' => ['DRE', 'lista'], 'fluxo' => ['Fluxo de caixa', 'grafico'], 'despesas' => ['Despesas', 'carteira']];

function financeiro_render(PDO $db, string $periodo, string $aba = 'dre'): void
{
    $gastos = fin_gastos($db);
    if ($aviso = aviso_pegar()) {
        echo '<p class="' . ($aviso[1] === 'erro' ? 'erro' : 'aviso-ok') . '">' . e($aviso[0]) . '</p>';
    }
    if ($aba === 'despesas') {
        fin_despesas_render($periodo, $gastos);
        return;
    }
    $ctx = financeiro_ctx($db, $periodo, $gastos);
    $tela = $aba === 'fluxo' ? fluxo_grade() : financeiro_grade();
    // Montando a tela (o lapis da barra lateral): so a barra de edicao e os blocos
    if (grade_editando()) {
        grade_render($tela, $ctx);
        return;
    }
    [$dia1, $dia2] = [$ctx['dia1'], $ctx['dia2']];
    $titulo = $aba === 'fluxo'
        ? com_info('Fluxo de caixa', 'O que entrou (vendas aprovadas, o líquido da plataforma) e o que saiu (Meta com o imposto e as despesas cadastradas) em cada dia, e o saldo acumulado. Vale para a empresa toda: o filtro de produto não entra aqui. O lápis da barra lateral monta a tela.')
        : com_info('DRE', 'Demonstração do resultado gerencial da empresa no período: da receita bruta ao resultado, com a margem de contribuição e o ponto de equilíbrio. Vale para a empresa toda: o filtro de produto não entra aqui. O imposto sobre o faturamento se configura na aba Despesas. O lápis da barra lateral monta a tela.');
    echo '<section class="bloco resumo-cab"><div class="resumo-topo"><h2>' . $titulo . '</h2>'
        . '<span class="suave">' . e((new DateTime($dia1))->format('d/m/Y') . ($dia1 === $dia2 ? '' : ' a ' . (new DateTime($dia2))->format('d/m/Y'))) . '</span></div></section>';
    grade_render($tela, $ctx);
}

// Aba Despesas: o imposto sobre o faturamento, os cartoes do mes, a tabela das despesas e o formulario
function fin_despesas_render(string $periodo, array $gastos): void
{
    $hoje = (new DateTime('today', fuso()))->format('Y-m-d');
    $volta = 'financeiro.php?' . http_build_query(['aba' => 'despesas', 'periodo' => $periodo]);
    $imp = fin_imposto_config();
    echo '<form method="post" action="gastos.php" class="bloco fin-imposto" id="imposto"><h2>' . com_info('Imposto sobre o faturamento', 'O imposto da nota fiscal, que o DRE desconta da receita bruta (menos os reembolsos) para chegar à receita líquida. MEI: o DAS é um valor fixo por mês (o DRE divide pelos dias). Simples Nacional e Lucro Presumido: a alíquota efetiva sobre o faturamento que o contador passar. Mudou de regime (ex.: desenquadramento do MEI)? Troque aqui; vale para todos os períodos.') . '</h2>'
        . '<input type="hidden" name="csrf" value="' . e(token_csrf()) . '"><input type="hidden" name="acao" value="imposto"><input type="hidden" name="volta" value="' . e($volta) . '">'
        . '<div class="fin-campos"><label><span>Regime</span><select name="regime">';
    foreach (FIN_REGIMES as $k => $rot) {
        echo '<option value="' . e($k) . '"' . ($imp['regime'] === $k ? ' selected' : '') . '>' . e($rot) . '</option>';
    }
    echo '</select></label><label><span>Como calcular</span><select name="modo">';
    foreach (FIN_IMPOSTO_MODOS as $k => $rot) {
        echo '<option value="' . e($k) . '"' . ($imp['modo'] === $k ? ' selected' : '') . '>' . e($rot) . '</option>';
    }
    echo '</select></label><label data-imposto="percentual"><span>' . com_info('Alíquota (%)', 'A alíquota efetiva sobre o faturamento, ex.: 6 ou 8,93.') . '</span>'
        . '<input name="aliquota" inputmode="decimal" value="' . e($imp['pct'] ? number_format($imp['pct'], 2, ',', '') : '') . '" placeholder="0,00"></label>'
        . '<label data-imposto="fixo"><span>' . com_info('Valor por mês (R$)', 'O DAS do MEI (ou outro imposto fixo) de cada mês, ex.: 81,05.') . '</span>'
        . '<input name="fixo" inputmode="decimal" value="' . e($imp['fixo'] ? number_format($imp['fixo'] / 100, 2, ',', '.') : '') . '" placeholder="0,00"></label>'
        . '<div class="linha-botoes"><button type="submit">Salvar</button></div></div></form>';

    // Despesas cadastradas e o formulario (novo ou editar)
    $editar = null;
    if (is_string($_GET['editar'] ?? null) && ctype_digit($_GET['editar'])) {
        foreach ($gastos as $g) {
            if ((int)$g['id'] === (int)$_GET['editar']) {
                $editar = $g;
            }
        }
    }
    // Despesas: os cartoes do mes e a tabela inteligente (como "Custos gerais")
    $mes1 = (new DateTime('first day of this month', fuso()))->format('Y-m-d');
    $mes2 = (new DateTime('last day of this month', fuso()))->format('Y-m-d');
    $doMes = fn(array $g) => (int)($g['ativo'] ?? 1) ? count(fin_ocorrencias($g, $mes1, $mes2)) * (int)$g['valor'] : 0;
    $totalMes = array_sum(array_map($doMes, $gastos));
    $ativosMes = count(array_filter($gastos, fn($g) => $doMes($g) > 0));
    $fixoMensal = array_sum(array_map(fn($g) => (int)($g['ativo'] ?? 1) && $g['repete'] === 'mensal' && (!$g['fim'] || $g['fim'] >= $hoje) ? (int)$g['valor'] : 0, $gastos));
    echo cartoes_kpi([
        cartao_kpi('Custos cadastrados', (string)count($gastos), 'fixos e variáveis na sua conta', 'carteira'),
        cartao_kpi('Ativos neste mês', (string)$ativosMes, 'de ' . count($gastos) . ' cadastrado' . (count($gastos) === 1 ? '' : 's'), 'atividade'),
        cartao_kpi('Total do mês', reais($totalMes), 'o que incide neste mês', 'calendario'),
        cartao_kpi('Fixo mensal', reais($fixoMensal), 'recorrente todo mês', 'repetir'),
    ]);
    // Historico de cada despesa: os ultimos 6 meses
    $meses = [];
    for ($i = 5; $i >= 0; $i--) {
        $d = (new DateTime('first day of this month', fuso()))->modify('-' . $i . ' month');
        $meses[] = [$d->format('Y-m-01'), $d->format('Y-m-t'), $d->format('m/Y')];
    }
    $tipos = ['mensal' => ['Fixo', 'azul'], 'anual' => ['Anual', 'roxo'], 'unico' => ['Variável', 'laranja']];
    $csrf = '<input type="hidden" name="csrf" value="' . e(token_csrf()) . '"><input type="hidden" name="volta" value="' . e($volta) . '">';
    echo tabela_card_inicio('despesas', 'Despesas', count($gastos), ['icone' => 'lista', 'busca' => 'Buscar por nome ou categoria', 'por_pagina' => 10,
        'dica' => 'Os gastos da empresa fora dos anúncios. Cadastre uma vez: as que se repetem entram sozinhas em cada mês (ou ano), até a data final, se tiver. Pausar tira a despesa da conta sem apagar.']);
    echo '<table><thead><tr><th class="nome">Nome</th><th data-filtro data-col="tipo">Tipo</th><th data-filtro data-col="categoria">Categoria</th><th data-col="valor">Valor</th>'
        . '<th data-col="participacao">' . com_info('Participação', 'Quanto essa despesa pesa no total do mês.') . '</th><th data-col="historico">' . com_info('Histórico', 'O que a despesa somou em cada um dos últimos 6 meses.') . '</th>'
        . '<th data-col="criado">Criado em</th><th data-col="ate">Válido até</th><th data-filtro data-col="ativo">' . com_info('Custo ativo', 'Desligado, a despesa fica cadastrada mas para de contar no DRE.') . '</th><th class="acoes"></th></tr></thead><tbody>';
    foreach ($gastos as $g) {
        $ativo = (bool)(int)($g['ativo'] ?? 1);
        $noMes = $doMes($g);
        $serie = array_map(fn($m) => count(fin_ocorrencias($g, $m[0], $m[1])) * (int)$g['valor'], $meses);
        $rotulos = array_map(fn($m, $v) => $m[2] . ': ' . reais($v), $meses, $serie);
        [$tipo, $cor] = $tipos[$g['repete']] ?? [FIN_REPETE[$g['repete']] ?? $g['repete'], 'cinza'];
        $chave = '<form method="post" action="gastos.php" class="form-linha">' . $csrf . '<input type="hidden" name="acao" value="ativo"><input type="hidden" name="id" value="' . (int)$g['id'] . '">'
            . '<input type="hidden" name="ativo" value="' . ($ativo ? '0' : '1') . '"><button type="submit" class="chave' . ($ativo ? ' ligada' : '') . '" role="switch" aria-checked="' . ($ativo ? 'true' : 'false') . '"'
            . ' aria-label="' . e(($ativo ? 'Pausar ' : 'Ativar ') . $g['descricao']) . '" title="' . ($ativo ? 'Ativa: clique para pausar' : 'Pausada: clique para ativar') . '"><span></span></button></form>';
        echo '<tr><td class="nome quebra"><b>' . e($g['descricao']) . '</b>' . celula_situacao($ativo, 'Ativa', 'Pausada') . '</td>'
            . '<td>' . celula_selo($tipo, $cor) . '</td>'
            . '<td>' . ($g['categoria'] ? celula_selo((string)$g['categoria']) : '<span class="suave">—</span>') . '</td>'
            . '<td>' . e(reais((int)$g['valor'])) . '</td>'
            . '<td>' . ($noMes && $totalMes ? celula_anel($noMes * 100 / $totalMes) : '<span class="suave">—</span>') . '</td>'
            . '<td>' . celula_mini_grafico($serie, $rotulos) . '</td>'
            . '<td>' . celula_data(data_local($g['criado_em'], 'm/Y')) . '</td>'
            . '<td>' . ($g['repete'] === 'unico' ? celula_data((new DateTime($g['inicio']))->format('d/m/Y')) : celula_prazo($g['fim'])) . '</td>'
            . '<td data-valor="' . ($ativo ? 'Ativa' : 'Pausada') . '">' . $chave . '</td>'
            . '<td class="acoes"><a class="botao-ico" href="' . e($volta . '&editar=' . (int)$g['id'] . '#despesa') . '" title="Editar ' . e($g['descricao']) . '" aria-label="' . e('Editar ' . $g['descricao']) . '">' . icone('lapis', 15) . '</a>' . menu_linha([
                '<a href="' . e($volta . '&editar=' . (int)$g['id'] . '#despesa') . '">' . icone('lapis', 14) . 'Editar</a>',
                '<form method="post" action="gastos.php" data-confirma="' . e('Apagar a despesa "' . $g['descricao'] . '"?') . '">' . $csrf
                    . '<input type="hidden" name="acao" value="apagar"><input type="hidden" name="id" value="' . (int)$g['id'] . '"><button type="submit" class="perigo">' . icone('lixo', 14) . 'Apagar</button></form>',
            ], 'Ações da despesa') . '</td></tr>';
    }
    if (!$gastos) {
        echo '<tr><td colspan="10" class="suave">Nenhuma despesa cadastrada. Use o formulário abaixo (ex.: hospedagem, ferramentas, equipe).</td></tr>';
    }
    echo '</tbody></table>' . tabela_card_fim('despesa', 'despesas');

    $categorias = array_values(array_unique(array_merge(FIN_CATEGORIAS, array_filter(array_column($gastos, 'categoria')))));
    $valor = $editar ? number_format((int)$editar['valor'] / 100, 2, ',', '.') : '';
    // Na largura toda, como os outros blocos; "Ate" so aparece quando a despesa se repete (painel.js)
    $repete = $editar['repete'] ?? 'unico';
    echo '<form method="post" action="gastos.php" class="bloco fin-form" id="despesa" data-despesa><h2>' . ($editar ? 'Editar despesa' : 'Nova despesa') . '</h2>'
        . '<input type="hidden" name="csrf" value="' . e(token_csrf()) . '"><input type="hidden" name="acao" value="salvar"><input type="hidden" name="volta" value="' . e($volta) . '">'
        . ($editar ? '<input type="hidden" name="id" value="' . (int)$editar['id'] . '">' : '')
        . '<div class="fin-campos"><label class="fin-desc"><span>Descrição</span><input name="descricao" maxlength="120" required value="' . e($editar['descricao'] ?? '') . '" placeholder="Ex.: Hospedagem Hostinger"></label>'
        . '<label><span>Categoria</span><input name="categoria" maxlength="40" list="fin-categorias" value="' . e($editar['categoria'] ?? '') . '" placeholder="Ex.: Ferramentas"></label>'
        . '<label><span>Valor (R$)</span><input name="valor" inputmode="decimal" required value="' . e($valor) . '" placeholder="0,00"></label>'
        . '<label><span>Repete</span><select name="repete" data-repete>';
    foreach (FIN_REPETE as $k => $rot) {
        echo '<option value="' . $k . '"' . ($repete === $k ? ' selected' : '') . '>' . e($rot) . '</option>';
    }
    echo '</select></label><label><span>' . com_info('Data', 'Dia em que a despesa cai. Nas que se repetem, o primeiro dia (todo mês cai nesse mesmo dia).') . '</span><input type="date" name="inicio" required value="' . e($editar['inicio'] ?? $hoje) . '"></label>'
        . '<label data-ate' . ($repete === 'unico' ? ' hidden' : '') . '><span>' . com_info('Até (opcional)', 'O último dia em que a despesa se repete. Em branco, repete sem fim.') . '</span><input type="date" name="fim" value="' . e((string)($editar['fim'] ?? '')) . '"></label></div>'
        . '<datalist id="fin-categorias">' . implode('', array_map(fn($c) => '<option value="' . e($c) . '">', $categorias)) . '</datalist>'
        . '<div class="linha-botoes"><button type="submit">' . ($editar ? 'Salvar' : 'Cadastrar') . '</button>'
        . ($editar ? '<a href="' . e($volta) . '">Cancelar</a>' : '') . '</div></form>';
}
