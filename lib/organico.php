<?php
// Organico: o que vende sem anuncio. Visitas vem do proprio painel (t.js), pela primeira
// visita de cada visitante no periodo; vendas vem da etiqueta que a Kiwify gravou. Os dois
// agrupados pelo mesmo canal organico (bio do Instagram, Instagram, Google, WhatsApp, IA,
// outros sites) e, a parte, o que chegou sem origem nenhuma.
//
// O perfil do Instagram (seguidores, alcance, posts) entra quando o token da Meta tiver as
// permissoes do Instagram; ate la o bloco explica o que falta.

require_once __DIR__ . '/resumo.php';

// Grupo organico de uma origem: [chave, rotulo] ou null quando e anuncio
function organico_grupo(?string $source, ?string $medium, ?string $term, ?string $referrer = null): ?array
{
    $c = canal($source, $medium, $term, $referrer);
    if ($c[0] === 'organico') {
        $rot = $c[3] !== '' ? $c[3] : 'Orgânico';
        return ['org:' . mb_strtolower($rot), mb_strtoupper(mb_substr($rot, 0, 1)) . mb_substr($rot, 1)];
    }
    if ($c[0] === 'direto') {
        return ['direto', 'Direto / sem origem'];
    }
    return null;
}

// Barras por dia (SVG). $porDia = ['Y-m-d' => n]
function organico_svg_dias(array $porDia): string
{
    $L = 1400; $A = 170; $esq = 10; $baixo = 22; $cima = 18;
    $n = count($porDia);
    $max = max(1, ...array_values($porDia));
    $larg = ($L - 2 * $esq) / max(1, $n);
    $svg = '<svg class="grafico" viewBox="0 0 ' . $L . ' ' . $A . '" role="img" aria-label="Vendas orgânicas por dia">';
    $i = 0;
    $passoRotulo = max(1, (int)ceil($n / 15));
    foreach ($porDia as $dia => $v) {
        $x = $esq + $i * $larg;
        $alt = $v * ($A - $cima - $baixo) / $max;
        $rot = (new DateTime($dia))->format('d/m');
        if ($v) {
            $svg .= '<rect x="' . round($x + $larg * .15, 1) . '" y="' . round($A - $baixo - $alt, 1) . '" width="' . round($larg * .7, 1) . '" height="' . round($alt, 1) . '" class="barra"><title>'
                . $rot . ': ' . $v . ' venda(s)</title></rect>'
                . '<text x="' . round($x + $larg / 2, 1) . '" y="' . round($A - $baixo - $alt - 5, 1) . '" text-anchor="middle">' . $v . '</text>';
        }
        if ($i % $passoRotulo === 0) {
            $svg .= '<text x="' . round($x + $larg / 2, 1) . '" y="' . ($A - 6) . '" text-anchor="middle">' . $rot . '</text>';
        }
        $i++;
    }
    return $svg . '</svg>';
}

function organico_render(PDO $db, string $periodo, string $de, string $ate): void
{
    $tz = fuso();
    $grupos = [];
    $novo = fn(string $rot) => ['rot' => $rot, 'vis' => 0, 'ck' => 0, 'vendas' => 0, 'fat' => 0];

    // Visitas: primeira visita de cada visitante no periodo, e quem clicou no checkout
    $primeiros = consulta($db, 'SELECT e.visitante, e.utm_source, e.utm_medium, e.utm_term, e.referrer, e.dominio, e.pagina
                                FROM eventos e JOIN (SELECT visitante AS v, MIN(id) AS primeiro FROM eventos WHERE em >= ? AND em < ? GROUP BY visitante) f
                                  ON f.primeiro = e.id', [$de, $ate]);
    $clicou = [];
    foreach (consulta($db, "SELECT DISTINCT visitante FROM eventos WHERE nome = 'CliqueCheckout' AND em >= ? AND em < ?", [$de, $ate]) as $c) {
        $clicou[$c['visitante']] = true;
    }
    $paginas = [];
    $visOrganicos = 0;
    foreach ($primeiros as $p) {
        $g = organico_grupo($p['utm_source'], $p['utm_medium'], $p['utm_term'], $p['referrer']);
        if (!$g) {
            continue;
        }
        $grupos[$g[0]] = $grupos[$g[0]] ?? $novo($g[1]);
        $grupos[$g[0]]['vis']++;
        $grupos[$g[0]]['ck'] += isset($clicou[$p['visitante']]) ? 1 : 0;
        if ($g[0] !== 'direto') {
            $visOrganicos++;
            $pag = $p['dominio'] . $p['pagina'];
            $paginas[$pag] = $paginas[$pag] ?? ['vis' => 0, 'ck' => 0];
            $paginas[$pag]['vis']++;
            $paginas[$pag]['ck'] += isset($clicou[$p['visitante']]) ? 1 : 0;
        }
    }

    // Vendas: pela etiqueta que a Kiwify gravou
    $vendasOrg = $fatOrg = $fatTotal = 0;
    $porLink = [];
    $porDia = [];
    if ($periodo !== 'hoje') {
        [$d1, $d2] = gestor_dias($periodo);
        if ($periodo === 'tudo') {
            $d1 = (new DateTime('today', $tz))->modify('-29 days')->format('Y-m-d');
            $d2 = (new DateTime('today', $tz))->format('Y-m-d');
        }
        for ($d = new DateTime($d1); $d->format('Y-m-d') <= $d2; $d->modify('+1 day')) {
            $porDia[$d->format('Y-m-d')] = 0;
        }
    }
    foreach (consulta($db, 'SELECT * FROM vendas WHERE recebida_em >= ? AND recebida_em < ?', [$de, $ate]) as $v) {
        if (!aprovada($v)) {
            continue;
        }
        $liquido = (int)($v['valor_liquido'] ?? $v['valor'] ?? 0);
        $fatTotal += $liquido;
        $g = organico_grupo($v['utm_source'], $v['utm_medium'], $v['utm_term']);
        if (!$g) {
            continue;
        }
        $principal = !eh_bump($v);
        $grupos[$g[0]] = $grupos[$g[0]] ?? $novo($g[1]);
        $grupos[$g[0]]['fat'] += $liquido;
        $grupos[$g[0]]['vendas'] += $principal ? 1 : 0;
        if ($g[0] === 'direto') {
            continue;
        }
        $fatOrg += $liquido;
        $vendasOrg += $principal ? 1 : 0;
        $link = origem($v['utm_source'], $v['utm_medium'], $v['utm_campaign']) ?: '(sem etiqueta)';
        $porLink[$link] = $porLink[$link] ?? ['vendas' => 0, 'fat' => 0];
        $porLink[$link]['vendas'] += $principal ? 1 : 0;
        $porLink[$link]['fat'] += $liquido;
        $dia = (new DateTime($v['aprovada_em'] ?: $v['recebida_em'], new DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m-d');
        if ($principal && isset($porDia[$dia])) {
            $porDia[$dia]++;
        }
    }

    // Numeros
    $pct = fn($a, $b) => $b ? number_format($a * 100 / $b, 1, ',', '.') . '%' : '—';
    echo '<div class="numeros">'
        . numero((string)$vendasOrg, 'Vendas orgânicas', 'Vendas aprovadas sem anúncio (bio do Instagram, Google, WhatsApp, IA, outros sites), pela etiqueta que a Kiwify gravou. Order bump não conta como outra venda.')
        . numero(reais($fatOrg), 'Faturamento orgânico', 'Líquido da Kiwify das vendas orgânicas aprovadas, com order bump.')
        . numero($pct($fatOrg, $fatTotal), 'Do faturamento total', 'Quanto do faturamento líquido do período veio sem anúncio.')
        . numero((string)$visOrganicos, 'Visitantes orgânicos', 'Aparelhos que chegaram às páginas sem anúncio (pela etiqueta ou pelo site de onde vieram), medidos pelo painel.')
        . numero($pct($vendasOrg, $visOrganicos), 'Conversão orgânica', 'Vendas orgânicas ÷ visitantes orgânicos. As vendas vêm da Kiwify e as visitas do painel: é uma aproximação.')
        . numero($vendasOrg ? reais((int)round($fatOrg / $vendasOrg)) : 'N/A', 'Ticket médio orgânico', 'Faturamento orgânico ÷ vendas orgânicas.')
        . '</div>';

    // Canais organicos
    uasort($grupos, fn($a, $b) => ($a['rot'] === 'Direto / sem origem') <=> ($b['rot'] === 'Direto / sem origem') ?: [$b['vendas'], $b['vis']] <=> [$a['vendas'], $a['vis']]);
    echo '<section class="bloco">' . titulo('Canais orgânicos', 'Visitas medidas pelo painel e vendas pela etiqueta da Kiwify, no mesmo canal. "Direto / sem origem" aparece à parte: pode ser orgânico sem etiqueta, link direto do checkout ou troca de aparelho.')
        . '<div class="tabela"><table><tr><th>Canal</th><th>Visitantes ' . info('Primeira visita no período vinda deste canal.') . '</th>'
        . '<th>Clicaram no checkout ' . info('Desses visitantes, quantos clicaram no botão de compra.') . '</th>'
        . '<th>Vendas ' . info('Vendas aprovadas com a etiqueta deste canal.') . '</th><th>Faturamento ' . info('Líquido da Kiwify, com order bump.') . '</th>'
        . '<th>Conversão ' . info('Vendas ÷ visitantes do canal.') . '</th></tr>';
    foreach ($grupos as $k => $g) {
        $ico = $k === 'direto' ? ['direto', '', 'direto', ''] : ['organico', '', 'folha', ''];
        echo '<tr><td>' . com_icone_canal($ico, $g['rot']) . '</td><td>' . $g['vis'] . '</td><td>' . $g['ck'] . '</td><td>' . ($g['vendas'] ? '<span class="selo ok">' . $g['vendas'] . '</span>' : '0') . '</td>'
            . '<td>' . e($g['fat'] ? reais($g['fat']) : '—') . '</td><td>' . e($pct($g['vendas'], $g['vis'])) . '</td></tr>';
    }
    if (!$grupos) {
        echo '<tr><td colspan="6" class="suave">Nenhuma visita ou venda orgânica no período.</td></tr>';
    }
    echo '</table></div></section>';

    // Links que vendem e paginas de entrada
    echo '<div class="grade">';
    arsort($porLink);
    echo '<section class="bloco">' . titulo('Links orgânicos que vendem', 'Vendas orgânicas pela etiqueta completa (source / medium / campaign): mostra qual link da bio, do WhatsApp ou de outra ação trouxe a venda.')
        . resumo_barras(array_map(fn($l) => $l['vendas'], $porLink), fn($v) => $v . ' venda(s)') . '</section>';
    uasort($paginas, fn($a, $b) => $b['vis'] <=> $a['vis']);
    $entrada = [];
    foreach ($paginas as $pag => $p) {
        $entrada[$pag . ' · ' . $p['ck'] . ' no checkout'] = $p['vis'];
    }
    echo '<section class="bloco">' . titulo('Páginas de entrada orgânica', 'Página em que o visitante orgânico chegou e quantos deles clicaram no botão de compra.')
        . resumo_barras($entrada, fn($v) => $v . ' visitante(s)') . '</section>';
    echo '</div>';

    if ($porDia) {
        echo '<section class="bloco">' . titulo('Vendas orgânicas por dia', 'Vendas orgânicas aprovadas em cada dia' . ($periodo === 'tudo' ? ' (últimos 30 dias)' : '') . ', pelo horário de Brasília.')
            . organico_svg_dias($porDia) . '</section>';
    }

    echo '<section class="bloco">' . titulo('Perfil do Instagram', 'Seguidores, alcance e os posts e reels que mais engajam e levam gente à página. Vem da API do Instagram.')
        . '<p class="suave">Para trazer os números do perfil, o app da Meta precisa do caso de uso <strong>Gerenciar mensagens e conteúdo no Instagram</strong>, '
        . 'com a conta do Instagram ligada ao portfólio, e o token com as permissões <strong>instagram_basic</strong> e <strong>instagram_manage_insights</strong>. '
        . 'Depois é só trocar o token na aba <a href="meta-api.php">API Meta</a>.</p></section>';
}
