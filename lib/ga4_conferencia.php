<?php
// Conferencia GA4 x painel (tela ga4.php): os numeros guardados por lib/ga4_sync.php ao lado dos
// eventos do t.js e dos pedidos do checkout proprio, e o mapa dos eventos que o GA4 recebe, com
// de onde cada um vem. Nada aqui chama o Google.

require_once __DIR__ . '/ga4_sync.php';

// Eventos que o painel e o GA4 contam do mesmo jeito: [evento do painel, evento do GA4, rotulo, dica]
const GA4_PARES = [
    ['PageView', 'page_view', 'Visualizações de página', 'Cada página aberta. O painel conta pelo t.js; o GA4, pelo gtag. GA4 de 10% a 35% abaixo é o normal: bloqueador de anúncio, navegador com proteção (Brave, Safari) e quem sai antes de o gtag carregar não chegam ao Google.'],
    ['CliqueCheckout', 'begin_checkout', 'Cliques no checkout', 'Clique no botão de comprar. Nas páginas de venda os dois contam uma vez por visita. Diferença grande aqui, com as visualizações batendo, indica botão sem o evento num dos dois.'],
];

// Eventos conhecidos do GA4: [de onde vem, o que significa]. O resto e personalizado (do site).
const GA4_EVENTOS_CONHECIDOS = [
    'first_visit' => ['Automático', 'Primeira visita deste aparelho e navegador (cookie _ga novo).'],
    'session_start' => ['Automático', 'Começo de uma sessão. 30 minutos sem atividade encerram a anterior.'],
    'user_engagement' => ['Automático', 'A página ficou em primeiro plano por pelo menos 1 segundo; é o que mede o tempo de engajamento.'],
    'page_view' => ['Medição otimizada', 'Página aberta. No app de uma página só, cada troca de tela que o site manda.'],
    'scroll' => ['Medição otimizada', 'Rolou até 90% da página.'],
    'click' => ['Medição otimizada', 'Clique num link para fora do site.'],
    'file_download' => ['Medição otimizada', 'Clique num link de arquivo (pdf, zip, dwg e outros).'],
    'form_start' => ['Medição otimizada', 'Começou a preencher um formulário.'],
    'form_submit' => ['Medição otimizada', 'Enviou um formulário.'],
    'view_search_results' => ['Medição otimizada', 'Abriu uma página de resultado de busca do site.'],
    'video_start' => ['Medição otimizada', 'Começou um vídeo do YouTube incorporado.'],
    'video_progress' => ['Medição otimizada', 'Passou de 10%, 25%, 50% ou 75% de um vídeo do YouTube incorporado.'],
    'video_complete' => ['Medição otimizada', 'Terminou um vídeo do YouTube incorporado.'],
    'view_item' => ['Recomendado', 'Viu um produto (página de venda ou projeto aberto).'],
    'view_item_list' => ['Recomendado', 'Viu uma lista de produtos.'],
    'select_item' => ['Recomendado', 'Escolheu um produto de uma lista.'],
    'add_to_cart' => ['Recomendado', 'Pôs um produto no carrinho.'],
    'add_to_wishlist' => ['Recomendado', 'Marcou um produto como favorito.'],
    'begin_checkout' => ['Recomendado', 'Começou a compra (clique em comprar).'],
    'add_payment_info' => ['Recomendado', 'Escolheu a forma de pagamento no checkout.'],
    'add_shipping_info' => ['Recomendado', 'Informou a entrega no checkout.'],
    'purchase' => ['Recomendado', 'Compra aprovada. O transaction_id evita contar a mesma compra duas vezes.'],
    'refund' => ['Recomendado', 'Compra devolvida.'],
    'generate_lead' => ['Recomendado', 'Virou contato (lead).'],
    'sign_up' => ['Recomendado', 'Criou a conta.'],
    'login' => ['Recomendado', 'Entrou na conta.'],
    'search' => ['Recomendado', 'Buscou algo no site (search_term).'],
    'share' => ['Recomendado', 'Compartilhou um conteúdo.'],
    'select_content' => ['Recomendado', 'Escolheu um conteúdo.'],
    'tutorial_begin' => ['Recomendado', 'Começou o passo a passo de boas-vindas.'],
    'tutorial_complete' => ['Recomendado', 'Terminou o passo a passo de boas-vindas.'],
];

function ga4_evento_origem(string $evento): array
{
    return GA4_EVENTOS_CONHECIDOS[$evento] ?? ['Personalizado', 'Evento próprio do site. Os parâmetros dele só viram coluna nos relatórios do GA4 depois de registrados como dimensão personalizada.'];
}

// Contagens por endereco no periodo: [host => ['ga4' => [evento => [n, valor, chave]], 'painel' => [evento => n]]]
function ga4_conf_por_host(string $periodo): array
{
    $db = track_db();
    [$d1, $d2] = periodo_dias($periodo);
    [$de, $ate] = periodo_utc($periodo);
    $hosts = [];
    foreach (consulta($db, 'SELECT host, evento, SUM(n) n, SUM(valor) valor, SUM(chave) chave FROM ga4_eventos WHERE dia BETWEEN ? AND ? GROUP BY host, evento', [$d1, $d2]) as $l) {
        $hosts[$l['host']]['ga4'][$l['evento']] = [(int)$l['n'], (float)$l['valor'], (int)$l['chave']];
    }
    foreach (consulta($db, 'SELECT dominio, nome, COUNT(*) n FROM eventos WHERE em >= ? AND em < ? GROUP BY dominio, nome', [$de, $ate]) as $l) {
        $hosts[$l['dominio']]['painel'][$l['nome']] = (int)$l['n'];
    }
    foreach ($hosts as &$h) {
        $h += ['ga4' => [], 'painel' => []];
        uasort($h['ga4'], fn($a, $b) => $b[0] <=> $a[0]);
    }
    unset($h);
    // Mais movimentado primeiro
    uasort($hosts, fn($a, $b) => (array_sum(array_column($b['ga4'], 0)) + array_sum($b['painel'])) <=> (array_sum(array_column($a['ga4'], 0)) + array_sum($a['painel'])));
    return $hosts;
}

// Compras: purchase do GA4 (todos os enderecos) x pedidos aprovados do checkout proprio (csh_pedidos).
// ['ga4' => [n, valor], 'painel' => [n, valor] ou null se o painel nao tem checkout proprio]
function ga4_conf_compras(string $periodo): array
{
    $db = track_db();
    [$d1, $d2] = periodo_dias($periodo);
    [$de, $ate] = periodo_utc($periodo);
    $g = consulta($db, "SELECT COALESCE(SUM(n), 0) n, COALESCE(SUM(valor), 0) valor FROM ga4_eventos WHERE evento = 'purchase' AND dia BETWEEN ? AND ?", [$d1, $d2])[0];
    $painel = null;
    if (valor($db, "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'csh_pedidos'")) {
        $p = consulta($db, "SELECT COUNT(*) n, COALESCE(SUM(total), 0) total FROM csh_pedidos WHERE status = 'aprovada' AND criado >= ? AND criado < ?",
            [strtotime($de . ' UTC'), strtotime($ate . ' UTC')])[0];
        $painel = [(int)$p['n'], (int)$p['total'] / 100];
    }
    return ['ga4' => [(int)$g['n'], (float)$g['valor']], 'painel' => $painel];
}

// Paginas mais vistas: GA4 (screenPageViews) x painel (PageView), por endereco e caminho
function ga4_conf_paginas(string $periodo, int $max = 20): array
{
    $db = track_db();
    [$d1, $d2] = periodo_dias($periodo);
    [$de, $ate] = periodo_utc($periodo);
    $l = [];
    foreach (consulta($db, 'SELECT host, pagina, SUM(visualizacoes) v FROM ga4_paginas WHERE dia BETWEEN ? AND ? GROUP BY host, pagina', [$d1, $d2]) as $r) {
        $l[$r['host'] . $r['pagina']] = ['host' => $r['host'], 'pagina' => $r['pagina'], 'ga4' => (int)$r['v'], 'painel' => 0];
    }
    foreach (consulta($db, "SELECT dominio, pagina, COUNT(*) n FROM eventos WHERE nome = 'PageView' AND em >= ? AND em < ? GROUP BY dominio, pagina", [$de, $ate]) as $r) {
        $c = $r['dominio'] . $r['pagina'];
        $l[$c] = ($l[$c] ?? ['host' => $r['dominio'], 'pagina' => $r['pagina'], 'ga4' => 0]);
        $l[$c]['painel'] = (int)$r['n'];
    }
    uasort($l, fn($a, $b) => max($b['ga4'], $b['painel']) <=> max($a['ga4'], $a['painel']));
    return array_slice(array_values($l), 0, $max);
}

// Origens: sessoes do GA4 por origem (sessionSource) x visitas do painel por utm_source (um
// visitante por dia, o mais perto de uma sessao). So batem as origens com o mesmo nome nos dois.
function ga4_conf_origens(string $periodo, int $max = 15): array
{
    $db = track_db();
    [$d1, $d2] = periodo_dias($periodo);
    [$de, $ate] = periodo_utc($periodo);
    $l = [];
    foreach (consulta($db, 'SELECT origem, SUM(sessoes) s, SUM(chave) k FROM ga4_origens WHERE dia BETWEEN ? AND ? GROUP BY origem', [$d1, $d2]) as $r) {
        $l[$r['origem']] = ['origem' => $r['origem'], 'ga4' => (int)$r['s'], 'chave' => (int)$r['k'], 'painel' => null];
    }
    $painel = consulta($db, "SELECT LOWER(COALESCE(NULLIF(utm_source, ''), '(sem etiqueta)')) o, COUNT(DISTINCT visitante || substr(em, 1, 10)) n
        FROM eventos WHERE nome = 'PageView' AND em >= ? AND em < ? GROUP BY o", [$de, $ate]);
    foreach ($painel as $r) {
        $l[$r['o']] = ($l[$r['o']] ?? ['origem' => $r['o'], 'ga4' => null, 'chave' => 0]);
        $l[$r['o']]['painel'] = (int)$r['n'];
    }
    uasort($l, fn($a, $b) => max((int)$b['ga4'], (int)$b['painel']) <=> max((int)$a['ga4'], (int)$a['painel']));
    return array_slice(array_values($l), 0, $max);
}

// Diferenca do GA4 em relacao ao painel: [texto, classe do selo]
function ga4_diferenca(?int $ga4, ?int $painel): array
{
    if ($ga4 === null || $painel === null || ($ga4 === 0 && $painel === 0)) {
        return ['—', 'neutro'];
    }
    if ($painel === 0) {
        return ['só no GA4', 'alerta'];
    }
    $d = ($ga4 - $painel) / $painel * 100;
    $txt = ($d > 0 ? '+' : '') . number_format($d, 0, ',', '.') . '%';
    if (abs($d) <= 10) {
        return [$txt . ' · bate', 'ok'];
    }
    if ($d < 0 && $d >= -35) {
        return [$txt . ' · normal', 'neutro'];
    }
    return [$txt . ($d < 0 ? ' · GA4 muito abaixo' : ' · GA4 acima'), $d < 0 ? 'erro' : 'alerta'];
}
