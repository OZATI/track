<?php
// Aba Trafego: o trafego por canal como painel, no mesmo desenho do Resumo. Numeros do topo,
// a rosca dos visitantes por canal e um bloco por metrica (checkout, vendas, faturamento...)
// com a lista de canais e o anel da %. A planilha continua em "Ver em tabela".

require_once __DIR__ . '/resumo.php'; // resumo_cartao, resumo_rosca, resumo_lista_aneis

// Cor de cada canal na rosca (a mesma dos icones)
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

    // Um bloco por metrica, com a lista de canais: [rotulo, numero, %]
    $bloco = function (string $titulo, string $dica, callable $linha, string $rodape = '') use ($porCanal): string {
        $linhas = [];
        foreach ($porCanal as $l) {
            $linhas[] = $linha($l);
        }
        return '<section class="rc c4"><div class="rc-cab"><span>' . e($titulo) . '</span>' . info($dica) . '</div>'
            . resumo_lista_aneis($linhas) . ($rodape !== '' ? '<small class="rc-rodape">' . $rodape . '</small>' : '') . '</section>';
    };
    $pct = fn(int $n, int $d): ?float => $d ? $n * 100 / $d : null;

    // Canal com a maior conversao (com pelo menos 1 venda)
    $melhor = null;
    foreach ($porCanal as $k => $l) {
        if ($l['vendas'] && ($melhor === null || $l['vendas'] / $l['vis'] > $porCanal[$melhor]['vendas'] / $porCanal[$melhor]['vis'])) {
            $melhor = $k;
        }
    }
    $rodapeVendas = $melhor !== null && count($porCanal) > 1
        ? 'Melhor conversão: <b>' . e($porCanal[$melhor]['canal'][1]) . '</b> (' . e(trafego_pct($porCanal[$melhor]['vendas'], $porCanal[$melhor]['vis'])) . ')'
        : '';

    $partes = [];
    $cores = [];
    foreach ($porCanal as $k => $l) {
        $partes[$l['canal'][1]] = $l['vis'];
        $cores[$l['canal'][1]] = CANAIS_COR[$k] ?? '#9CA3AF';
    }
    $rot = fn(array $l) => selo_canal($l['canal'], false);

    $html .= '<div class="rgrade"><section class="rc c4 r2"><div class="rc-cab"><span>Visitantes por canal</span>'
        . info('Como os visitantes do período se dividem entre os canais da primeira visita.') . '</div>'
        . resumo_rosca($partes, $cores, 'Visitantes') . '</section>'
        . $bloco('Checkout por canal', 'Visitantes de cada canal que clicaram no botão de compra. A % é sobre os visitantes do próprio canal.',
            fn($l) => [$rot($l), $l['ck'], $pct($l['ck'], $l['vis'])])
        . $bloco('Vendas por canal', 'Vendas aprovadas dos visitantes de cada canal, sem order bump. A % é a conversão: vendas ÷ visitantes do canal.',
            fn($l) => [$rot($l), $l['vendas'], $pct($l['vendas'], $l['vis'])], $rodapeVendas)
        . $bloco('Faturamento por canal', 'Valor cobrado das vendas aprovadas de cada canal, com order bump. A % é a parte do faturamento total.',
            fn($l) => [$rot($l), $l['fat'] ? reais($l['fat']) : '—', $pct($l['fat'], $t['fat'])])
        . ($temWhats
            ? $bloco('WhatsApp por canal', 'Visitantes de cada canal que clicaram no WhatsApp. A % é sobre os visitantes do próprio canal.',
                fn($l) => [$rot($l), $l['wa'], $pct($l['wa'], $l['vis'])])
            : $bloco('Visualizações por canal', 'Páginas abertas pelos visitantes de cada canal. A % é a parte do total de visualizações.',
                fn($l) => [$rot($l), $l['pv'], $pct($l['pv'], $t['pv'])]))
        . '</div>';
    return $html;
}
