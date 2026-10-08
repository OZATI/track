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
$db = track_db();

pagina_inicio('DRE');
grade_lapis('financeiro'); // o caixa e montavel: o lapis aparece na barra lateral
casca_inicio('financeiro');
barra_periodo('financeiro.php', $periodo);
echo '<nav class="abas abas-nucleo" aria-label="DRE"><span class="abas-titulo">DRE · Resultado da empresa</span>'
    . '<a href="financeiro.php" class="atual" aria-current="page">' . icone('carteira') . 'Caixa e despesas</a></nav>';
echo '<main>';
financeiro_render($db, $periodo);
echo '</main>';
casca_fim();
pagina_fim();
