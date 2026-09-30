<?php
// Organico: o que vende sem anuncio. Visitas vem do proprio painel (t.js), pela primeira
// visita de cada visitante no periodo; vendas vem da etiqueta que a Kiwify gravou. Os dois
// agrupados pelo mesmo canal organico (bio do Instagram, Instagram, Google, WhatsApp, IA,
// outros sites) e, a parte, o que chegou sem origem nenhuma.
//
// O perfil do Instagram (seguidores, alcance, toques nos links do perfil, posts e reels) vem
// da API do Instagram (lib/instagram_sync.php), com o token da aba API Instagram; sem ele, o
// bloco explica como conectar.

require_once __DIR__ . '/resumo.php';
require_once __DIR__ . '/instagram_sync.php';

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
function organico_svg_dias(array $porDia, string $unidade = 'venda(s)', string $nome = 'Vendas orgânicas por dia'): string
{
    $L = 1400; $A = 170; $esq = 10; $baixo = 22; $cima = 18;
    $n = count($porDia);
    $max = max(1, ...array_values($porDia));
    $larg = ($L - 2 * $esq) / max(1, $n);
    $svg = '<svg class="grafico" viewBox="0 0 ' . $L . ' ' . $A . '" role="img" aria-label="' . e($nome) . '">';
    $i = 0;
    $passoRotulo = max(1, (int)ceil($n / 15));
    foreach ($porDia as $dia => $v) {
        $x = $esq + $i * $larg;
        $alt = $v * ($A - $cima - $baixo) / $max;
        $rot = (new DateTime($dia))->format('d/m');
        if ($v) {
            $svg .= '<rect x="' . round($x + $larg * .15, 1) . '" y="' . round($A - $baixo - $alt, 1) . '" width="' . round($larg * .7, 1) . '" height="' . round($alt, 1) . '" class="barra"><title>'
                . $rot . ': ' . number_format($v, 0, ',', '.') . ' ' . e($unidade) . '</title></rect>'
                . '<text x="' . round($x + $larg / 2, 1) . '" y="' . round($A - $baixo - $alt - 5, 1) . '" text-anchor="middle">' . number_format($v, 0, ',', '.') . '</text>';
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

    // Barra de atualizacao: vendas da Kiwify e perfil do Instagram
    $k = kiwify_sync_estado();
    $ig = ig_sync_estado();
    $partes = [];
    if ($k['ok_em']) {
        $partes[] = 'vendas atualizadas em ' . data_local($k['ok_em'], 'd/m H:i');
    }
    if ($ig['ok_em']) {
        $partes[] = 'Instagram em ' . data_local($ig['ok_em'], 'd/m H:i');
    }
    $vencida = ig_sync_vencida() || kiwify_sync_vencida();
    echo '<div class="barra-vendas" id="sync"' . ($vencida ? ' data-sync="1"' : '') . '><span data-sync-texto>' . e(ucfirst(implode(' · ', $partes))) . '</span>'
        . '<form method="post" action="sincronizar.php"><input type="hidden" name="csrf" value="' . e(token_csrf()) . '">'
        . '<input type="hidden" name="volta" value="' . e('./?' . http_build_query(['aba' => 'organico', 'periodo' => $periodo])) . '"><button type="submit">Atualizar</button></form></div>';

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

    organico_instagram($db, $periodo, $grupos);
}

// Perfil do Instagram: numeros da conta no periodo, o caminho do perfil ate a venda (com as
// visitas e vendas que o painel mediu), alcance por dia e cada post ou reel.
// $grupos = canais organicos da aba, com visitas, cliques no checkout e vendas.
function organico_instagram(PDO $db, string $periodo, array $grupos): void
{
    $perfil = ig_perfil();
    $estado = ig_sync_estado();
    $explica = 'Seguidores, alcance, visualizações, toques nos links do perfil e os posts e reels do período. Vem da API do Instagram; os números de um dia podem levar até 48 horas para fechar.';
    if (!$perfil) {
        echo '<section class="bloco">' . titulo('Perfil do Instagram', $explica) . '<p class="suave">'
            . (ig_api_chave() ? e($estado['erro'] ?: 'Primeira busca no Instagram em andamento: os números aparecem em instantes.')
                : 'Conecte o perfil na aba <a href="instagram-api.php">API Instagram</a> para ver aqui seguidores, alcance, toques no link da bio e os posts e reels que mais engajam, ao lado das visitas e vendas que vieram do perfil.')
            . '</p></section>';
        return;
    }
    $n = fn($v) => number_format((int)$v, 0, ',', '.');
    [$d1, $d2] = gestor_dias($periodo);
    $st = $db->prepare('SELECT COUNT(buscado_em) AS dias, SUM(alcance) AS alcance, SUM(visualizacoes) AS vis, SUM(contas_engajadas) AS eng,
            SUM(interacoes) AS inter, SUM(toques_links) AS toques, SUM(seguiram) AS seguiram, SUM(deixaram) AS deixaram, COUNT(seguiram) AS dias_seguir
        FROM ig_dia WHERE dia >= ? AND dia <= ?');
    $st->execute([$d1, $d2]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    $temInsights = (int)$t['dias'] > 0;

    [$de, $ate] = periodo_utc($periodo);
    $posts = consulta_ig($db, 'SELECT * FROM ig_media WHERE publicado_em >= ? AND publicado_em < ? ORDER BY publicado_em DESC LIMIT 60', [$de, $ate]);
    $noPeriodo = count($posts);

    // Numeros
    $saldo = (int)$t['dias_seguir'] ? (int)$t['seguiram'] - (int)$t['deixaram'] : null;
    echo '<section class="bloco">' . titulo('Perfil do Instagram · @' . $perfil['usuario'], $explica);
    if ($estado['erro']) {
        echo '<p class="erro">Última busca no Instagram falhou: ' . e($estado['erro']) . '</p>';
    }
    if (!$temInsights) {
        echo '<p class="suave">' . e($estado['insights_erro'] ?: ($estado['ok_em'] ? 'Sem números da conta neste período.' : 'Buscando os números da conta.'))
            . ' Seguidores e posts aparecem mesmo assim.</p>';
    }
    echo '<div class="numeros">'
        . numero($n($perfil['seguidores'] ?? 0), 'Seguidores', 'Seguidores do perfil na última busca.');
    if ($temInsights) {
        echo numero($saldo === null ? 'N/A' : ($saldo >= 0 ? '+' : '−') . $n(abs($saldo)), 'Saldo de seguidores', $saldo === null
                ? 'O Instagram só informa quem seguiu e quem deixou de seguir em perfis com 100 seguidores ou mais.'
                : 'Começaram a seguir (' . $n($t['seguiram']) . ') menos deixaram de seguir (' . $n($t['deixaram']) . ') no período.')
            . numero($n($t['alcance']), 'Alcance', 'Contas diferentes que viram algum conteúdo do perfil, somadas dia a dia: quem viu em dois dias conta duas vezes.')
            . numero($n($t['vis']), 'Visualizações', 'Quantas vezes posts, reels e stories foram vistos no período, contando repetições.')
            . numero($n($t['inter']), 'Interações', 'Curtidas, comentários, salvamentos, compartilhamentos e respostas no período.')
            . numero($n($t['eng']), 'Contas engajadas', 'Contas que interagiram com o conteúdo, somadas dia a dia.')
            . numero($n($t['toques']), 'Toques nos links do perfil', 'Toques no link da bio e nos botões de contato do perfil: o começo do caminho até o site.');
    }
    echo numero((string)$noPeriodo, 'Posts no período', 'Posts e reels publicados no período. Stories não entram.') . '</div></section>';

    // Do perfil a venda, e alcance por dia
    if ($temInsights) {
        $vis = $ck = $vendas = 0;
        foreach ($grupos as $chave => $g) {
            if (strpos($chave, 'org:instagram') === 0) {
                $vis += $g['vis'];
                $ck += $g['ck'];
                $vendas += $g['vendas'];
            }
        }
        echo '<section class="bloco">' . titulo('Do perfil à venda', 'O caminho de quem viu o perfil até comprar sem anúncio. Os dois primeiros passos vêm do Instagram; os outros, do painel e da Kiwify.')
            . resumo_funil([
                'Alcance' => [(int)$t['alcance'], 'Contas que viram algum conteúdo do perfil (soma dos dias).'],
                'Toques nos links do perfil' => [(int)$t['toques'], 'Toques no link da bio e nos botões de contato.'],
                'Visitantes vindos do Instagram' => [$vis, 'Aparelhos que chegaram às páginas pelo link da bio ou outro link do Instagram sem anúncio, medidos pelo painel.'],
                'Clicaram no checkout' => [$ck, 'Desses visitantes, quantos clicaram no botão de compra.'],
                'Vendas' => [$vendas, 'Vendas aprovadas com a etiqueta do Instagram sem anúncio (bio e outros links). Order bump não conta como outra venda.'],
            ]) . '</section>';
        $porDia = [];
        if ($periodo !== 'hoje') {
            $ini = $periodo === 'tudo' ? (new DateTime('today', fuso()))->modify('-' . (IG_SYNC_DIAS - 1) . ' days')->format('Y-m-d') : $d1;
            $fim = $periodo === 'tudo' ? (new DateTime('today', fuso()))->format('Y-m-d') : $d2;
            for ($d = new DateTime($ini); $d->format('Y-m-d') <= $fim; $d->modify('+1 day')) {
                $porDia[$d->format('Y-m-d')] = 0;
            }
            $st = $db->prepare('SELECT dia, alcance FROM ig_dia WHERE dia >= ? AND dia <= ?');
            $st->execute([$ini, $fim]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
                $porDia[$l['dia']] = (int)$l['alcance'];
            }
        }
        echo '<section class="bloco">' . titulo('Alcance por dia', 'Contas diferentes que viram algum conteúdo do perfil em cada dia' . ($periodo === 'tudo' ? ' (últimos ' . IG_SYNC_DIAS . ' dias)' : '') . '.')
            . ($porDia ? organico_svg_dias($porDia, 'contas alcançadas', 'Alcance por dia') : '<p class="suave">Escolha um período de mais de um dia.</p>') . '</section>';
    }

    // Posts e reels: os do periodo, do maior alcance para o menor; sem post no periodo, os mais recentes
    $recentes = !$posts;
    if ($recentes) {
        $posts = consulta_ig($db, 'SELECT * FROM ig_media ORDER BY publicado_em DESC LIMIT 6', []);
    } else {
        usort($posts, fn($a, $b) => [(int)$b['alcance'], $b['publicado_em']] <=> [(int)$a['alcance'], $a['publicado_em']]);
    }
    $cel = fn($v) => $v === null ? '<span class="suave">—</span>' : e($n($v));
    $tipos = ['CAROUSEL_ALBUM' => 'Carrossel', 'VIDEO' => 'Vídeo', 'IMAGE' => 'Foto'];
    echo '<section class="bloco">' . titulo('Posts e reels', 'Cada post ou reel com os números dele desde a publicação (não só os do período). Clique para abrir no Instagram.')
        . ($recentes ? '<p class="suave">Nenhum post no período. Abaixo, os mais recentes.</p>' : '')
        . '<div class="tabela"><table><tr><th>Post</th>'
        . '<th>' . com_info('Alcance', 'Contas diferentes que viram o post.') . '</th>'
        . '<th>' . com_info('Visualizações', 'Vezes que o post foi visto, contando repetições.') . '</th>'
        . '<th>' . com_info('Curtidas', 'Curtidas no post.') . '</th>'
        . '<th>' . com_info('Comentários', 'Comentários no post.') . '</th>'
        . '<th>' . com_info('Salvos', 'Quantas vezes o post foi salvo.') . '</th>'
        . '<th>' . com_info('Compartilhamentos', 'Quantas vezes o post foi enviado ou compartilhado.') . '</th>'
        . '<th>' . com_info('Visitas ao perfil', 'Visitas ao perfil a partir do post (posts de feed).') . '</th>'
        . '<th>' . com_info('Seguiram', 'Contas que começaram a seguir a partir do post (posts de feed).') . '</th>'
        . '<th>' . com_info('Engajamento', 'Interações (curtidas, comentários, salvos e compartilhamentos) ÷ alcance.') . '</th>'
        . '<th>' . com_info('Tempo médio', 'Quanto tempo, em média, cada pessoa assistiu o reel.') . '</th></tr>';
    foreach ($posts as $p) {
        $tipo = $p['produto'] === 'REELS' ? 'Reels' : ($tipos[$p['tipo']] ?? 'Post');
        $leg = texto(preg_replace('/\s+/u', ' ', (string)$p['legenda']), 90);
        $rot = '<strong>' . e($tipo) . '</strong> <span class="suave">' . e(data_local($p['publicado_em'], 'd/m H:i')) . '</span>'
            . ($leg !== '' ? '<br><span class="suave">' . e($leg) . (mb_strlen((string)$p['legenda']) > 90 ? '…' : '') . '</span>' : '');
        $engaj = (int)$p['alcance'] > 0 && $p['interacoes'] !== null ? number_format($p['interacoes'] * 100 / $p['alcance'], 1, ',', '.') . '%' : '—';
        $tempo = $p['tempo_medio_ms'] !== null ? number_format($p['tempo_medio_ms'] / 1000, 1, ',', '.') . ' s' : '—';
        echo '<tr><td class="quebra">' . ($p['link'] ? '<a class="abre" href="' . e($p['link']) . '" target="_blank" rel="noopener noreferrer">' . $rot . '</a>' : $rot) . '</td>'
            . '<td>' . $cel($p['alcance']) . '</td><td>' . $cel($p['visualizacoes']) . '</td><td>' . $cel($p['curtidas']) . '</td><td>' . $cel($p['comentarios']) . '</td>'
            . '<td>' . $cel($p['salvos']) . '</td><td>' . $cel($p['compartilhamentos']) . '</td><td>' . $cel($p['visitas_perfil']) . '</td><td>' . $cel($p['seguiram']) . '</td>'
            . '<td>' . e($engaj) . '</td><td>' . e($tempo) . '</td></tr>';
    }
    if (!$posts) {
        echo '<tr><td colspan="11" class="suave">Nenhum post encontrado ainda.</td></tr>';
    }
    echo '</table></div></section>';
}
