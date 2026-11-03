<?php
// Regressão: o cadastro deve mostrar os mesmos valores do DRE/fluxo no período escolhido.
// Banco temporário exclusivo; nenhum dado real é lido.
$dir = sys_get_temp_dir() . '/track-despesas-' . bin2hex(random_bytes(6));
mkdir($dir, 0700);
putenv('TRACK_DADOS=' . $dir);
require __DIR__ . '/../lib/util.php';
require __DIR__ . '/../lib/layout.php';
require __DIR__ . '/../lib/vendas.php';
require __DIR__ . '/../lib/resumo.php';
require_once __DIR__ . '/../lib/financeiro.php';
sessao_iniciar();
$db = track_db();
$gastos = [];
foreach ([
    ['Mensal dia 31', 'mensal', '2024-01-31', null, 10000, 1],
    ['Única', 'unico', '2024-02-15', null, 5000, 1],
    ['Anual', 'anual', '2023-02-10', null, 20000, 1],
    ['Pausada', 'mensal', '2024-01-10', null, 90000, 0],
    ['Futura', 'mensal', '2024-04-10', null, 80000, 1],
    ['Encerrada', 'mensal', '2023-01-10', '2024-01-31', 70000, 1],
] as $i => [$descricao, $repete, $inicio, $fim, $valor, $ativo]) {
    $gastos[] = compact('descricao', 'repete', 'inicio', 'fim', 'valor', 'ativo') + ['id' => $i + 1, 'categoria' => 'Teste', 'criado_em' => '2024-01-01 12:00:00'];
}
function conferir(bool $ok, string $mensagem): void {
    if (!$ok) throw new RuntimeException($mensagem);
}
$de = '2024-02-01';
$ate = '2024-02-29';
$somas = fin_somas(fin_por_dia($db, $de, $ate, $gastos));
$dre = fin_dre_dados($db, $de, $ate, $gastos, $somas);
conferir($somas['despesas'] === 35000, 'Fluxo: mensal + única + anual, sem pausada/futura/encerrada');
conferir($dre['fixasTotal'] === 30000 && array_sum($dre['variaveis']) === 5000, 'DRE: classificação e totais');
conferir(fin_ocorrencias($gastos[0], $de, $ate) === ['2024-02-29'], 'Recorrência no último dia de fevereiro bissexto');
ob_start();
fin_despesas_render('2024-02-01_2024-02-29', $gastos, fin_dias($db, '2024-02-01_2024-02-29', $gastos));
$html = ob_get_clean();
conferir(str_contains($html, '01/02/2024 a 29/02/2024'), 'Datas selecionadas visíveis');
conferir(str_contains($html, reais(35000)) && str_contains($html, reais(30000)), 'Totais do cadastro batem com fluxo e DRE');
conferir(str_contains($html, '02/2024:') && !str_contains($html, '03/2024:'), 'Histórico termina no mês selecionado');
conferir(str_contains($html, 'periodo=2024-02-01_2024-02-29'), 'Links e retorno mantêm período');
conferir(substr_count($html, 'R$ 0,00 no período') === 3, 'Pausada, futura e encerrada zeradas na tabela');
ob_start();
fin_despesas_render('mes', [], [$de, $ate]);
$vazio = ob_get_clean();
conferir(str_contains($vazio, 'Cadastrar a primeira despesa'), 'Estado vazio com ação');
echo "OK: despesas, DRE e fluxo consistentes; datas, recorrência, pausa, links e estado vazio.\n";
// Arquivos de teste permanecem só no diretório temporário exclusivo (SQLite pode estar aberto no Windows).
