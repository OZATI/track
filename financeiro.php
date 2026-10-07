<?php
// Modulo Financeiro (acesso "financeiro", na barra lateral): o caixa da empresa no periodo
// (entradas, anuncios, despesas, saldo e ROI geral, dia a dia) e o cadastro das despesas
// (lib/financeiro.php; gravar, editar, pausar e apagar em gastos.php). Saiu das abas do UTM
// para ser o lugar do dinheiro: e onde entram os pagamentos proprios (lib/pagamentos/, em
// docs/ARQUITETURA.md) quando forem construidos.

require __DIR__ . '/lib/util.php';
require __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/vendas.php';
require_once __DIR__ . '/lib/resumo.php';
require_once __DIR__ . '/lib/financeiro.php';

exigir_login('financeiro');
header('Cache-Control: no-store');

$periodo = (string)($_GET['periodo'] ?? '');
if ($periodo === 'personalizado') {
    $periodo = periodo_personalizado($_GET['de'] ?? null, $_GET['ate'] ?? null) ?? '';
}
$periodo = periodo_valido($periodo) ? $periodo : 'mes';
$db = track_db();

pagina_inicio('Financeiro');
casca_inicio('financeiro');
$periodos = ['hoje' => 'Hoje', '7d' => '7 dias', 'mes' => 'Este mês', 'mes_passado' => 'Mês passado', '30d' => '30 dias', 'tudo' => 'Tudo'];
echo '<nav class="abas abas-nucleo" aria-label="Financeiro"><span class="abas-titulo">Financeiro · Caixa da empresa</span>'
    . '<a href="financeiro.php" class="atual" aria-current="page">' . icone('carteira') . 'Caixa e despesas</a></nav>';
echo '<main><div class="bio-topo"><div class="segmentos" role="group" aria-label="Período">';
foreach ($periodos as $k => $rot) {
    if (periodo_valido($k)) {
        echo '<a href="' . e('financeiro.php?periodo=' . $k) . '"' . ($k === $periodo ? ' class="atual" aria-current="true"' : '') . '>' . e($rot) . '</a>';
    }
}
echo '</div></div>';
financeiro_render($db, $periodo);
echo '</main>';
casca_fim();
pagina_fim();
