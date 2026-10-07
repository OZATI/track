<?php
// Componentes de tela do admin (nucleo: valem para o UTM, os outros modulos e o CMS do site).
//
// - cartao_kpi(): o cartao de numero com o icone num quadradinho no canto ("Custos cadastrados").
// - Tabela inteligente: tabela_card_inicio() e tabela_card_fim() em volta de uma <table>. Em cima,
//   o icone, o titulo, a contagem, a busca, Filtros (das colunas com data-filtro no <th>), Ocultos
//   (colunas escondidas, com a contagem) e restaurar; embaixo, "Mostrando 1-10 de N" e Por pagina.
//   A tabela rola por dentro (cabecalho fixo) e a pagina continua rolando embaixo dela.
//   O comportamento fica no tabela.js; sem JavaScript, a tabela aparece inteira.
// - Celulas prontas: etiqueta com ponto de cor, ponto de situacao, anel de %, mini grafico,
//   data com icone, prazo ("Sem prazo") e o menu "..." de cada linha.
// O CSS fica no CSS comum do admin (admin_css_base, lib/admin_tela.php).

// Cartao de numero. $dica vira o (i) do titulo; $icone, o quadradinho do canto.
function cartao_kpi(string $titulo, string $valor, string $sub = '', string $icone = '', string $dica = '', string $classe = ''): string
{
    return '<div class="kpi"><div class="kpi-cab"><span>' . ($dica !== '' ? com_info($titulo, $dica) : e($titulo)) . '</span>'
        . ($icone !== '' ? '<span class="kpi-ico" aria-hidden="true">' . icone($icone, 15) . '</span>' : '') . '</div>'
        . '<b' . ($classe !== '' ? ' class="' . e($classe) . '"' : '') . '>' . e($valor) . '</b>'
        . ($sub !== '' ? '<small>' . e($sub) . '</small>' : '') . '</div>';
}

// Grade de cartoes de numero (quantos couberem na largura, todos do mesmo tamanho)
function cartoes_kpi(array $cartoes): string
{
    return '<div class="kpis">' . implode('', $cartoes) . '</div>';
}

// Comeco da tabela inteligente. $chave: guarda as escolhas (colunas ocultas, por pagina) neste
// navegador. $o: icone, busca (o texto de exemplo), dica (o (i) do titulo), acoes (HTML antes da
// busca), por_pagina (padrao 25; 0 = todas), filtros e ocultos (true ou false).
function tabela_card_inicio(string $chave, string $titulo, int $total, array $o = []): string
{
    $o += ['icone' => 'lista', 'busca' => 'Buscar', 'dica' => '', 'acoes' => '', 'por_pagina' => 25, 'filtros' => true, 'ocultos' => true];
    return '<section class="tcard" data-tabela="' . e($chave) . '" data-por-pagina="' . (int)$o['por_pagina'] . '">'
        . '<header class="tcard-cab"><span class="tcard-ico" aria-hidden="true">' . icone($o['icone'], 16) . '</span>'
        . '<h2>' . ($o['dica'] !== '' ? com_info($titulo, $o['dica']) : e($titulo)) . '</h2>'
        . '<span class="tcard-conta" data-tabela-conta>' . $total . '</span>'
        . '<div class="tcard-ferr">' . $o['acoes']
        . '<label class="tcard-busca">' . icone('busca', 14) . '<input type="search" data-tabela-busca placeholder="' . e($o['busca']) . '" aria-label="' . e($o['busca']) . '"></label>'
        . ($o['filtros'] ? '<button type="button" class="discreto neutro" data-tabela-filtros hidden>' . icone('filtro', 14) . '<span>Filtros</span><span class="tcard-n" data-tabela-n-filtros hidden></span></button>' : '')
        . ($o['ocultos'] ? '<button type="button" class="discreto neutro" data-tabela-ocultos hidden>' . icone('olho-fechado', 14) . '<span>Ocultos</span><span class="tcard-n" data-tabela-n-ocultos>0</span></button>' : '')
        . '<button type="button" class="discreto neutro tcard-icone" data-tabela-restaurar hidden aria-label="Restaurar a tabela" data-dica="Restaurar: sem busca, sem filtro e com todas as colunas" data-dica-botao>' . icone('restaurar', 15) . '</button>'
        . '</div></header><div class="tabela tcard-corpo">';
}

// Fim da tabela inteligente. $um e $varios: como contar ("custo", "custos")
function tabela_card_fim(string $um = 'linha', string $varios = 'linhas'): string
{
    $opcoes = '';
    foreach ([10, 25, 50, 100, 0] as $n) {
        $opcoes .= '<option value="' . $n . '">' . ($n ? $n : 'Todas') . '</option>';
    }
    return '</div><footer class="tcard-pe" hidden><span data-tabela-mostrando data-um="' . e($um) . '" data-varios="' . e($varios) . '"></span>'
        . '<span class="tcard-paginas" data-tabela-paginas></span>'
        . '<label class="tcard-por"><span>Por página</span><select data-tabela-por-pagina aria-label="Linhas por página">' . $opcoes . '</select></label></footer></section>';
}

// Etiqueta com o ponto de cor (Fixo, Aluguel...). $cor: azul, verde, laranja, vermelho, roxo ou cinza
function celula_selo(string $texto, string $cor = 'azul'): string
{
    return '<span class="selo-p selo-' . e($cor) . '"><i aria-hidden="true"></i>' . e($texto) . '</span>';
}

// O ponto de situacao ao lado do nome (verde: ativo; cinza: parado)
function celula_situacao(bool $ativo, string $sim = 'Ativo', string $nao = 'Pausado'): string
{
    return '<span class="ponto ' . ($ativo ? 'ok' : 'off') . '" role="img" aria-label="' . e($ativo ? $sim : $nao) . '"></span>';
}

// Anel de porcentagem com o numero (Participacao)
function celula_anel(?float $pct): string
{
    if ($pct === null) {
        return '<span class="suave">—</span>';
    }
    $p = max(0.0, min(100.0, $pct));
    return '<span class="anel-p"><svg viewBox="0 0 36 36" aria-hidden="true"><circle cx="18" cy="18" r="15.915" class="anel-fundo"></circle>'
        . '<circle cx="18" cy="18" r="15.915" class="anel-valor" stroke-dasharray="' . round($p, 2) . ' ' . round(100 - $p, 2) . '" stroke-dashoffset="25"></circle></svg>'
        . number_format($pct, 1, ',', '.') . '%</span>';
}

// Mini grafico de linha com a area embaixo (Historico). $serie: valores do mais antigo ao mais novo;
// $rotulos: o que aparece na dica de cada ponto (mesma ordem).
function celula_mini_grafico(array $serie, array $rotulos = []): string
{
    $n = count($serie);
    if (!$n) {
        return '<span class="suave">—</span>';
    }
    $L = 120;
    $A = 28;
    $max = max(1, max($serie));
    $pontos = [];
    foreach (array_values($serie) as $i => $v) {
        $x = $n === 1 ? $L / 2 : round($i * $L / ($n - 1), 1);
        $pontos[] = $x . ',' . round($A - 3 - ($v * ($A - 6) / $max), 1);
    }
    if ($n === 1) {
        array_unshift($pontos, '0,' . explode(',', $pontos[0])[1]);
        $pontos[] = $L . ',' . explode(',', $pontos[0])[1];
    }
    $dica = $rotulos ? ' data-dica-titulo="Histórico" data-dica="' . e(implode("\n", $rotulos)) . '" tabindex="0"' : '';
    return '<svg class="mini-graf" viewBox="0 0 ' . $L . ' ' . $A . '" preserveAspectRatio="none" role="img" aria-label="Histórico"' . $dica . '>'
        . '<polygon class="mini-area" points="0,' . $A . ' ' . implode(' ', $pontos) . ' ' . $L . ',' . $A . '"></polygon>'
        . '<polyline class="mini-linha" points="' . implode(' ', $pontos) . '"></polyline></svg>';
}

// Data com o icone do calendario
function celula_data(string $texto): string
{
    return '<span class="data-c">' . icone('calendario', 13) . e($texto) . '</span>';
}

// Prazo: "Sem prazo" (com o infinito) ou a data
function celula_prazo(?string $fim): string
{
    return $fim ? celula_data((new DateTime($fim))->format('d/m/Y')) : '<span class="prazo">' . icone('infinito', 13) . 'Sem prazo</span>';
}

// O "..." da linha, com as acoes (links ou formularios ja montados)
function menu_linha(array $itens, string $rotulo = 'Ações'): string
{
    return '<details class="menu-linha"><summary aria-label="' . e($rotulo) . '" title="' . e($rotulo) . '">' . icone('reticencias', 16) . '</summary>'
        . '<div class="menu-linha-painel" role="menu">' . implode('', $itens) . '</div></details>';
}
