<?php
// Aba Trafego: o trafego por canal como painel (cartoes em vez de planilha). Numeros do topo,
// a rosca dos visitantes por canal e um cartao por canal com a participacao, o caminho
// visitante -> checkout -> venda e o faturamento. A planilha continua em "Ver em tabela".

require_once __DIR__ . '/resumo.php'; // resumo_cartao, resumo_rosca

// Cor de cada canal (a mesma dos icones)
const CANAIS_COR = ['instagram' => '#C13584', 'facebook' => '#1877F2', 'meta' => '#4F46E5', 'compartilhado' => '#60A5FA',
    'google' => '#EA4335', 'organico' => '#16A34A', 'outros' => '#F59E0B', 'direto' => '#9CA3AF'];

function trafego_pct(int $n, int $d): string
{
    return $d ? number_format($n * 100 / $d, 1, ',', '.') . '%' : '—';
}

// $porCanal = [id do canal => ['canal' => canal(), 'vis', 'pv', 'ck', 'wa', 'vendas', 'fat']]
function trafego_painel_canais(array $porCanal, bool $temWhats): string
{
    if (!$porCanal) {
        return '<p class="suave">Nenhuma visita no período. As visitas chegam pelo t.js instalado nas páginas.</p>';
    }
    $t = ['vis' => 0, 'pv' => 0, 'ck' => 0, 'wa' => 0, 'vendas' => 0, 'fat' => 0];
    foreach ($porCanal as $l) {
        foreach ($t as $k => $_) {
            $t[$k] += $l[$k];
        }
    }

    $html = '<div class="rgrade">'
        . resumo_cartao((string)$t['vis'], 'Visitantes', 'Aparelhos diferentes que abriram as páginas no período, pelo canal da primeira visita.', '', $t['pv'] . ' visualizações')
        . resumo_cartao((string)$t['ck'], 'Clicaram no checkout', 'Visitantes que clicaram no botão de compra.', '', trafego_pct($t['ck'], $t['vis']) . ' dos visitantes')
        . resumo_cartao((string)$t['vendas'], 'Vendas aprovadas', 'Vendas aprovadas desses visitantes (sem contar order bump).', '', 'conversão de ' . trafego_pct($t['vendas'], $t['vis']))
        . resumo_cartao(reais($t['fat']), 'Faturamento', 'Valor cobrado das vendas aprovadas desses visitantes, com order bump.', '',
            $t['vendas'] ? reais(intdiv($t['fat'], $t['vendas'])) . ' por venda' : 'sem venda ligada a visitante')
        . '</div>';

    // Canal com a maior conversao (com pelo menos 1 venda)
    $melhor = null;
    foreach ($porCanal as $k => $l) {
        if ($l['vendas'] && ($melhor === null || $l['vendas'] / $l['vis'] > $porCanal[$melhor]['vendas'] / $porCanal[$melhor]['vis'])) {
            $melhor = $k;
        }
    }

    $partes = [];
    $cores = [];
    $cartoes = '';
    foreach ($porCanal as $k => $l) {
        $cor = CANAIS_COR[$k] ?? '#9CA3AF';
        $partes[$l['canal'][1]] = $l['vis'];
        $cores[$l['canal'][1]] = $cor;
        $passo = fn(int $n, string $rot, string $dica) => '<div class="cc-passo"><span>' . com_info($rot, $dica) . '</span><b>' . $n . '</b>'
            . '<i><em style="width:' . ($l['vis'] ? round(min(100, $n * 100 / $l['vis']), 1) : 0) . '%"></em></i><small>' . e(trafego_pct($n, $l['vis'])) . '</small></div>';
        $cartoes .= '<article class="cc" style="--cor:' . e($cor) . '">'
            . '<div class="cc-cab">' . selo_canal($l['canal'], false) . ($k === $melhor && count($porCanal) > 1 ? '<span class="selo ok">melhor conversão</span>' : '') . '</div>'
            . '<div class="cc-num"><b>' . $l['vis'] . '</b><span>visitante' . ($l['vis'] === 1 ? '' : 's') . ' · ' . e(trafego_pct($l['vis'], $t['vis'])) . ' do total</span></div>'
            . '<div class="cc-passos">'
            . $passo($l['ck'], 'Checkout', 'Visitantes deste canal que clicaram no botão de compra (e a % dos visitantes do canal).')
            . ($temWhats ? $passo($l['wa'], 'WhatsApp', 'Visitantes deste canal que clicaram no WhatsApp.') : '')
            . $passo($l['vendas'], 'Vendas', 'Vendas aprovadas dos visitantes deste canal, sem order bump (e a conversão sobre os visitantes).')
            . '</div>'
            . '<div class="cc-pe"><span>' . com_info('Faturamento', 'Valor cobrado das vendas aprovadas deste canal, com order bump.') . '<b>' . e($l['fat'] ? reais($l['fat']) : '—') . '</b></span>'
            . '<span>' . com_info('Conversão', 'Vendas aprovadas ÷ visitantes do canal.') . '<b>' . e(trafego_pct($l['vendas'], $l['vis'])) . '</b></span></div>'
            . '</article>';
    }

    return $html . '<div class="rgrade"><section class="rc c4"><div class="rc-cab"><span>Visitantes por canal</span>'
        . info('Como os visitantes do período se dividem entre os canais da primeira visita. Cada cartão ao lado mostra o caminho até a venda.') . '</div>'
        . resumo_rosca($partes, $cores, 'Visitantes') . '</section>'
        . '<section class="c8 cc-grade">' . $cartoes . '</section></div>';
}
