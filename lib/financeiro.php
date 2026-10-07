<?php
// Financeiro: o caixa da empresa no periodo, como as abas FLUXO e LUCRO da planilha do Allan.
// Entradas = faturamento liquido de todas as vendas aprovadas (qualquer produto e origem).
// Saidas = o que a Meta cobrou (gasto + imposto) e as outras despesas cadastradas aqui
// (hospedagem, ferramentas, equipe...), unicas ou que se repetem todo mes ou todo ano.
//   ROI geral = entradas / saidas   (o "ROI GERAL" da aba FLUXO; acima de 1, a empresa se paga)
// O cadastro grava em gastos.php (POST com login e o token do formulario).

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

// Entradas e saidas por dia: [dia => ['entradas', 'anuncios', 'despesas']]
function fin_por_dia(PDO $db, string $dia1, string $dia2, array $gastos): array
{
    $tz = fuso();
    $utc = new DateTimeZone('UTC');
    $pct = gestor_imposto_pct();
    $dias = [];
    $vazio = ['entradas' => 0, 'anuncios' => 0, 'despesas' => 0];
    $de = (new DateTime($dia1, $tz))->setTimezone($utc)->format('Y-m-d H:i:s');
    $ate = (new DateTime($dia2, $tz))->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
    foreach (consulta($db, 'SELECT * FROM vendas WHERE recebida_em >= ? AND recebida_em < ?', [$de, $ate]) as $v) {
        if (!aprovada($v)) {
            continue;
        }
        $dia = (new DateTime($v['recebida_em'], $utc))->setTimezone($tz)->format('Y-m-d');
        $dias[$dia] = $dias[$dia] ?? $vazio;
        $dias[$dia]['entradas'] += (int)($v['valor_liquido'] ?? $v['valor'] ?? 0);
    }
    foreach (consulta($db, 'SELECT dia, SUM(gasto) AS g FROM meta_gasto WHERE dia >= ? AND dia <= ? GROUP BY dia', [$dia1, $dia2]) as $g) {
        $dias[$g['dia']] = $dias[$g['dia']] ?? $vazio;
        $dias[$g['dia']]['anuncios'] += (int)$g['g'] + (int)round((int)$g['g'] * $pct / 100);
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

function financeiro_render(PDO $db, string $periodo): void
{
    $gastos = fin_gastos($db);
    $hoje = (new DateTime('today', fuso()))->format('Y-m-d');
    [$dia1, $dia2] = gestor_dias($periodo);
    if ($periodo === 'tudo') {
        // Do primeiro movimento (venda, gasto ou despesa) ate hoje, no maximo 1 ano para tras
        $primeiros = array_filter([
            consulta($db, 'SELECT MIN(dia) AS d FROM meta_gasto', [])[0]['d'] ?? null,
            ($v = consulta($db, 'SELECT MIN(recebida_em) AS d FROM vendas', [])[0]['d'] ?? null) ? data_local($v, 'Y-m-d') : null,
            $gastos ? min(array_column($gastos, 'inicio')) : null,
        ]);
        $dia1 = max($primeiros ? min($primeiros) : $hoje, (new DateTime($hoje))->modify('-365 days')->format('Y-m-d'));
        $dia2 = $hoje;
    }
    $porDia = fin_por_dia($db, $dia1, $dia2, $gastos);
    $soma = fn(string $c) => array_sum(array_column($porDia, $c));
    $entradas = $soma('entradas');
    $anuncios = $soma('anuncios');
    $despesas = $soma('despesas');
    $saidas = $anuncios + $despesas;
    $saldo = $entradas - $saidas;
    $roi = $saidas ? $entradas / $saidas : null;
    $num = fn(float $v, int $c = 2) => number_format($v, $c, ',', '.');
    $volta = 'financeiro.php?' . http_build_query(['periodo' => $periodo]);

    if ($aviso = aviso_pegar()) {
        echo '<p class="' . ($aviso[1] === 'erro' ? 'erro' : 'aviso-ok') . '">' . e($aviso[0]) . '</p>';
    }
    echo '<section class="bloco resumo-cab"><div class="resumo-topo"><h2>' . com_info('Financeiro', 'O caixa da empresa no período, como as abas FLUXO e LUCRO da planilha: tudo o que entrou (vendas aprovadas, líquido da Kiwify) e tudo o que saiu (Meta com imposto e as despesas cadastradas aqui embaixo). Vale para a empresa toda: o filtro de produto não entra aqui.') . '</h2>'
        . '<span class="suave">' . e((new DateTime($dia1))->format('d/m/Y') . ($dia1 === $dia2 ? '' : ' a ' . (new DateTime($dia2))->format('d/m/Y'))) . '</span></div></section>';
    echo '<div class="rgrade">'
        . resumo_cartao(reais($entradas), 'Entradas', 'Faturamento líquido (o que a Kiwify repassa) de todas as vendas aprovadas no período, com order bump, de qualquer produto e origem.', '', '', 'c3')
        . resumo_cartao(reais($anuncios), 'Anúncios', 'O que a Meta cobrou: gasto + imposto de ' . $num(gestor_imposto_pct()) . '% sobre ele.', '', '', 'c3')
        . resumo_cartao(reais($despesas), 'Outras despesas', 'Despesas cadastradas aqui que caem no período (as que se repetem contam em cada mês ou ano).', '', '', 'c2')
        . resumo_cartao(reais($saldo), 'Saldo', 'Entradas − saídas (anúncios + outras despesas): o lucro de verdade da empresa no período.', $saldo < 0 ? 'negativo' : ($saldo > 0 ? 'positivo' : ''), '', 'c2')
        . resumo_cartao($roi === null ? 'N/A' : $num($roi), 'ROI geral', 'Entradas ÷ saídas, como o ROI GERAL da aba FLUXO: quanto voltou para cada real que saiu, contando todas as despesas. Vermelho abaixo de 1, laranja de 1 até 2, verde de 2 para cima.', cor_roi($roi), '', 'c2')
        . '</div>';

    // Fluxo de caixa dia a dia (so os dias com movimento)
    $semana = ['', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb', 'dom'];
    echo titulo('Fluxo de caixa', 'Um dia por linha, só os dias com movimento: entradas, saídas e o saldo acumulado desde o começo do período.')
        . '<div class="tabela gestor"><table><tr><th class="nome">Dia</th><th>' . com_info('Entradas', 'Vendas aprovadas do dia, líquido da Kiwify.') . '</th><th>' . com_info('Anúncios', 'Gasto na Meta + imposto.') . '</th><th>' . com_info('Despesas', 'Despesas cadastradas que caem no dia.') . '</th>'
        . '<th>' . com_info('Saldo do dia', 'Entradas − anúncios − despesas.') . '</th><th>' . com_info('Acumulado', 'Soma dos saldos desde o primeiro dia do período.') . '</th><th>' . com_info('ROI do dia', 'Entradas ÷ saídas do dia.') . '</th></tr>';
    $acum = 0;
    foreach ($porDia as $dia => $m) {
        $saiu = $m['anuncios'] + $m['despesas'];
        $s = $m['entradas'] - $saiu;
        $acum += $s;
        $r = $saiu ? $m['entradas'] / $saiu : null;
        $d = new DateTime($dia);
        echo '<tr><td class="nome"><span class="suave">' . $semana[(int)$d->format('N')] . '</span> ' . e($d->format('d/m/Y')) . '</td><td>' . e(reais($m['entradas'])) . '</td><td>' . e(reais($m['anuncios'])) . '</td>'
            . '<td>' . ($m['despesas'] ? e(reais($m['despesas'])) : '<span class="suave">—</span>') . '</td><td><span class="' . ($s < 0 ? 'negativo' : ($s > 0 ? 'positivo' : '')) . '">' . e(reais($s)) . '</span></td>'
            . '<td><span class="' . ($acum < 0 ? 'negativo' : 'positivo') . '">' . e(reais($acum)) . '</span></td><td>' . ($r === null ? 'N/A' : '<span class="' . cor_roi($r) . '">' . $num($r) . '</span>') . '</td></tr>';
    }
    if (!$porDia) {
        echo '<tr><td colspan="7" class="suave">Nenhum movimento no período.</td></tr>';
    }
    echo '</table></div>';

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
        . '<th data-col="criado">Criado em</th><th data-col="ate">Válido até</th><th data-filtro data-col="ativo">' . com_info('Custo ativo', 'Desligado, a despesa fica cadastrada mas para de contar no Financeiro.') . '</th><th class="acoes"></th></tr></thead><tbody>';
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
            . '<td class="acoes">' . menu_linha([
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
