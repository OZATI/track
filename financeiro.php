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

$periodo = periodo_da_tela('mes'); // o mesmo seletor e o mesmo periodo lembrado do UTM
$aba = isset(FIN_ABAS[$_GET['aba'] ?? '']) ? (string)$_GET['aba'] : 'dre';
$db = track_db();

pagina_inicio('DRE');
if ($aba !== 'despesas') {
    grade_lapis($aba === 'fluxo' ? 'fluxo' : 'financeiro'); // DRE e fluxo sao montaveis: o lapis na barra lateral
}
casca_inicio('financeiro');
barra_periodo('financeiro.php', $periodo, ['aba' => $aba]);
echo '<nav class="abas abas-nucleo" aria-label="DRE"><span class="abas-titulo">DRE · Resultado da empresa</span>';
foreach (FIN_ABAS as $k => [$rot, $ico]) {
    echo '<a href="' . e('financeiro.php?' . http_build_query(['aba' => $k])) . '"' . ($k === $aba ? ' class="atual" aria-current="page"' : '') . '>' . icone($ico) . e($rot) . '</a>';
}
echo '</nav>';
echo '<main>';
financeiro_render($db, $periodo, $aba);
echo '</main>';
casca_fim();
pagina_fim();
