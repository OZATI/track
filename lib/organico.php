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
function organico_grupo(?string $source, ?string $medium, ?string $term, ?string $referrer = null, ?string $campaign = null): ?array
{
    $c = canal($source, $medium, $term, $referrer, $campaign);
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
    $primeiros = consulta($db, 'SELECT e.visitante, e.utm_source, e.utm_medium, e.utm_campaign, e.utm_term, e.referrer, e.dominio, e.pagina
                                FROM eventos e JOIN (SELECT visitante AS v, MIN(id) AS primeiro FROM eventos WHERE em >= ? AND em < ? GROUP BY visitante) f
                                  ON f.primeiro = e.id', [$de, $ate]);
    $clicou = [];
    foreach (consulta($db, "SELECT DISTINCT visitante FROM eventos WHERE nome = 'CliqueCheckout' AND em >= ? AND em < ?", [$de, $ate]) as $c) {
        $clicou[$c['visitante']] = true;
    }
    $paginas = [];
    $visOrganicos = 0;
    foreach ($primeiros as $p) {
        $g = organico_grupo($p['utm_source'], $p['utm_medium'], $p['utm_term'], $p['referrer'], $p['utm_campaign']);
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
        $g = organico_grupo($v['utm_source'], $v['utm_medium'], $v['utm_term'], null, $v['utm_campaign']);
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
        . botao_atualizar('./?' . http_build_query(['aba' => 'organico', 'periodo' => $periodo]), 'Atualizar agora: busca o Instagram e as vendas na Kiwify') . '</div>';

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
        . resumo_barras(array_map(fn($l) => $l['vendas'], $porLink), fn($v) => $v . ' venda(s)')
        // Etiquetas que o proprio Instagram poe: explicadas embaixo da lista
        . (preg_grep('/^ig \/ social/', array_keys($porLink)) ? '<p class="suave legenda">ig / social: marca que o próprio Instagram põe no link clicado dentro do app (bio, story ou post). Sem o ID de uma campanha, é visita orgânica do Instagram.</p>' : '')
        . (preg_grep('/^organico \/ instagram-bio/', array_keys($porLink)) ? '<p class="suave legenda">organico / instagram-bio: o link da bio com a nossa etiqueta (engdesk.pro/ig ou a marca link_in_bio).</p>' : '')
        . '</section>';
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
    $explica = 'Seguidores, alcance, visualizações, interações e os posts e reels. Vem da API do Instagram; os números de um dia podem levar até 48 horas para fechar.';
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
    $noPeriodo = (int)consulta_ig($db, 'SELECT COUNT(*) AS n FROM ig_media WHERE publicado_em >= ? AND publicado_em < ?', [$de, $ate])[0]['n'];

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
            . numero($n($t['toques']), 'Toques nos botões de contato', 'Toques nos botões de contato do perfil (ligar, e-mail, endereço, mensagem). O Instagram não informa os cliques no link da bio: esses o painel mede como visitantes vindos do Instagram, no funil abaixo.');
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
        echo '<section class="bloco">' . titulo('Do perfil à venda', 'O caminho de quem viu o perfil até comprar sem anúncio. O alcance vem do Instagram; os outros passos, do painel e da Kiwify. O Instagram não informa os cliques no link da bio: o painel mede quem chegou por ele.')
            . resumo_funil([
                'Alcance' => [(int)$t['alcance'], 'Contas que viram algum conteúdo do perfil (soma dos dias).'],
                'Visitantes vindos do Instagram' => [$vis, 'Aparelhos que chegaram às páginas pelo link da bio ou outro link do Instagram sem anúncio, medidos pelo painel. É o clique no link da bio que o Instagram não informa.'],
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

    organico_feed($db, $periodo);
}

// Feed: os 50 posts mais recentes, com a capa e os numeros de cada um desde a publicacao.
// Ordena e filtra pelo endereco (feed_ordem, feed_formato) e troca para tabela (feed_ver).
const FEED_ORDENS = ['recentes' => 'Recentes', 'alcance' => 'Alcance', 'engajamento' => 'Engajamento', 'salvos' => 'Salvos',
    'compartilhamentos' => 'Compartilhamentos', 'vendas' => 'Vendas em 48 h'];
const FEED_FORMATOS = ['todos' => 'Todos', 'reels' => 'Reels', 'carrossel' => 'Carrossel', 'foto' => 'Foto', 'video' => 'Vídeo'];
const FEED_DICAS = [
    'Alcance' => 'Contas diferentes que viram o post.',
    'Visualizações' => 'Vezes que o post foi visto, contando repetições.',
    'Curtidas' => 'Curtidas no post.',
    'Comentários' => 'Comentários no post.',
    'Salvos' => 'Quantas vezes o post foi salvo: sinal forte de conteúdo útil.',
    'Compartilhamentos' => 'Quantas vezes o post foi enviado ou compartilhado.',
    'Engajamento' => 'Interações (curtidas, comentários, salvos e compartilhamentos) ÷ alcance.',
    'Visitas ao perfil' => 'Visitas ao perfil a partir do post (posts de feed).',
    'Seguiram' => 'Contas que começaram a seguir a partir do post (posts de feed).',
    'Tempo médio' => 'Quanto tempo, em média, cada pessoa assistiu o reel.',
    'Vendas em 48 h' => 'Vendas pela bio (Instagram sem anúncio) aprovadas até 48 horas depois da publicação. Mostra coincidência no tempo, não prova que o post vendeu: com dois posts no mesmo intervalo, a venda conta para os dois.',
];

function organico_formato(array $p): string
{
    return $p['produto'] === 'REELS' ? 'reels' : (['CAROUSEL_ALBUM' => 'carrossel', 'VIDEO' => 'video', 'IMAGE' => 'foto'][$p['tipo']] ?? 'foto');
}

function organico_feed(PDO $db, string $periodo): void
{
    $posts = consulta_ig($db, 'SELECT * FROM ig_media ORDER BY publicado_em DESC LIMIT 50', []);
    $explica = 'Os 50 posts e reels mais recentes do perfil, com os números de cada um desde a publicação (não só os do período). Clique na capa para abrir no Instagram.';
    if (!$posts) {
        echo '<section class="bloco" id="feed">' . titulo('Feed', $explica) . '<p class="suave">Nenhum post encontrado ainda.</p></section>';
        return;
    }

    // Vendas pela bio (Instagram sem anuncio) nas 48 horas depois de cada post
    $horas = [];
    foreach (consulta_ig($db, 'SELECT * FROM vendas WHERE recebida_em >= ?', [end($posts)['publicado_em']]) as $v) {
        $g = organico_grupo($v['utm_source'], $v['utm_medium'], $v['utm_term'], null, $v['utm_campaign']);
        if (aprovada($v) && !eh_bump($v) && $g && strpos($g[0], 'org:instagram') === 0) {
            $horas[] = strtotime(($v['aprovada_em'] ?: $v['recebida_em']) . ' UTC');
        }
    }
    foreach ($posts as &$p) {
        $ini = strtotime($p['publicado_em'] . ' UTC');
        $p['vendas48'] = count(array_filter($horas, fn($h) => $h >= $ini && $h < $ini + 172800));
        $p['formato'] = organico_formato($p);
        $p['engaj'] = (int)$p['alcance'] > 0 && $p['interacoes'] !== null ? $p['interacoes'] / $p['alcance'] : null;
    }
    unset($p);

    $n = fn($v) => $v === null ? '—' : number_format((float)$v, 0, ',', '.');
    $pct = fn($v) => $v === null ? '—' : number_format($v * 100, 1, ',', '.') . '%';
    organico_formatos($posts, $n, $pct);

    $ordem = (string)($_GET['feed_ordem'] ?? '');
    $ordem = isset(FEED_ORDENS[$ordem]) ? $ordem : 'recentes';
    $formato = (string)($_GET['feed_formato'] ?? '');
    $formato = isset(FEED_FORMATOS[$formato]) ? $formato : 'todos';
    $tabela = ($_GET['feed_ver'] ?? '') === 'tabela';
    $lista = $formato === 'todos' ? $posts : array_values(array_filter($posts, fn($p) => $p['formato'] === $formato));
    $campo = ['alcance' => 'alcance', 'engajamento' => 'engaj', 'salvos' => 'salvos', 'compartilhamentos' => 'compartilhamentos', 'vendas' => 'vendas48'][$ordem] ?? null;
    if ($campo) {
        usort($lista, fn($a, $b) => [(float)$b[$campo], $b['publicado_em']] <=> [(float)$a[$campo], $a['publicado_em']]);
    }
    $atuais = ['aba' => 'organico', 'periodo' => $periodo, 'feed_ordem' => $ordem, 'feed_formato' => $formato, 'feed_ver' => $tabela ? 'tabela' : ''];
    $link = fn(array $mudar) => './?' . http_build_query(array_filter($mudar + $atuais, fn($v) => $v !== '' && $v !== 'recentes' && $v !== 'todos')) . '#feed';
    $contagem = array_count_values(array_column($posts, 'formato'));

    echo '<section class="bloco" id="feed">' . titulo('Feed', $explica) . '<div class="feed-controles">'
        . '<div class="segmentos" aria-label="Ordenar o feed">';
    foreach (FEED_ORDENS as $k => $rot) {
        echo '<a href="' . e($link(['feed_ordem' => $k])) . '" class="' . ($ordem === $k ? 'atual' : '') . '">' . e($rot) . '</a>';
    }
    echo '</div><div class="segmentos" aria-label="Formato">';
    foreach (FEED_FORMATOS as $k => $rot) {
        if ($k === 'todos' || !empty($contagem[$k])) {
            echo '<a href="' . e($link(['feed_formato' => $k])) . '" class="' . ($formato === $k ? 'atual' : '') . '">' . e($rot) . ' <span class="suave">' . ($k === 'todos' ? count($posts) : $contagem[$k]) . '</span></a>';
        }
    }
    echo '</div><div class="segmentos" aria-label="Ver como">'
        . '<a href="' . e($link(['feed_ver' => ''])) . '" class="' . (!$tabela ? 'atual' : '') . '">Grade</a>'
        . '<a href="' . e($link(['feed_ver' => 'tabela'])) . '" class="' . ($tabela ? 'atual' : '') . '">Tabela</a></div></div>';

    $abre = fn(array $p, string $dentro, string $classe) => $p['link']
        ? '<a class="' . $classe . '" href="' . e($p['link']) . '" target="_blank" rel="noopener noreferrer">' . $dentro . '</a>'
        : '<div class="' . $classe . '">' . $dentro . '</div>';
    $legenda = fn(array $p) => texto(preg_replace('/\s+/u', ' ', (string)$p['legenda']), 110) . (mb_strlen((string)$p['legenda']) > 110 ? '…' : '');
    $tempo = fn(array $p) => $p['tempo_medio_ms'] !== null ? number_format($p['tempo_medio_ms'] / 1000, 1, ',', '.') . ' s' : '—';

    if ($tabela) {
        echo '<div class="tabela"><table><tr><th>Post</th>';
        foreach (['Alcance', 'Visualizações', 'Curtidas', 'Comentários', 'Salvos', 'Compartilhamentos', 'Visitas ao perfil', 'Seguiram', 'Engajamento', 'Tempo médio', 'Vendas em 48 h'] as $c) {
            echo '<th>' . com_info($c, FEED_DICAS[$c]) . '</th>';
        }
        echo '</tr>';
        foreach ($lista as $p) {
            $leg = $legenda($p);
            $rot = '<strong>' . e(FEED_FORMATOS[$p['formato']]) . '</strong> <span class="suave">' . e(data_local($p['publicado_em'], 'd/m/Y H:i')) . '</span>'
                . ($leg !== '' ? '<br><span class="suave">' . e($leg) . '</span>' : '');
            echo '<tr><td class="quebra">' . $abre($p, $rot, 'abre') . '</td><td>' . $n($p['alcance']) . '</td><td>' . $n($p['visualizacoes']) . '</td>'
                . '<td>' . $n($p['curtidas']) . '</td><td>' . $n($p['comentarios']) . '</td><td>' . $n($p['salvos']) . '</td><td>' . $n($p['compartilhamentos']) . '</td>'
                . '<td>' . $n($p['visitas_perfil']) . '</td><td>' . $n($p['seguiram']) . '</td><td>' . e($pct($p['engaj'])) . '</td><td>' . e($tempo($p)) . '</td>'
                . '<td>' . ($p['vendas48'] ? '<span class="selo ok">' . $p['vendas48'] . '</span>' : '0') . '</td></tr>';
        }
        echo '</table></div></section>';
        return;
    }

    // Grade: legenda com o (i) de cada numero, uma vez so, e os cartoes
    echo '<p class="feed-legenda">';
    foreach (['Alcance', 'Visualizações', 'Salvos', 'Compartilhamentos', 'Engajamento', 'Tempo médio', 'Vendas em 48 h'] as $i => $c) {
        echo ($i ? ' · ' : '') . com_info($c, FEED_DICAS[$c]);
    }
    echo '</p><div class="feed">';
    foreach ($lista as $p) {
        $capa = is_file(ig_arquivo_miniatura($p['id']))
            ? '<img src="midia.php?id=' . e($p['id']) . '" alt="" loading="lazy">'
            : '<span class="sem-capa">' . icone('instagram', 28) . '</span>';
        $capa .= '<span class="post-tipo">' . e(FEED_FORMATOS[$p['formato']]) . '</span>'
            . ($p['vendas48'] ? '<span class="post-vendas">' . $p['vendas48'] . ' venda' . ($p['vendas48'] > 1 ? 's' : '') . ' em 48 h</span>' : '');
        $ultimo = $p['formato'] === 'reels' ? ['Tempo médio', $tempo($p)] : ['Visitas ao perfil', $n($p['visitas_perfil'])];
        $num = [['Alcance', $n($p['alcance'])], ['Visualizações', $n($p['visualizacoes'])], ['Curtidas', $n($p['curtidas'])], ['Comentários', $n($p['comentarios'])],
            ['Salvos', $n($p['salvos'])], ['Compartilhamentos', $n($p['compartilhamentos'])], ['Engajamento', $pct($p['engaj'])], $ultimo];
        $leg = $legenda($p);
        echo '<article class="post">' . $abre($p, $capa, 'post-capa') . '<div class="post-corpo">'
            . '<p class="post-data">' . e(data_local($p['publicado_em'], 'd/m/Y H:i')) . '</p>'
            . ($leg !== '' ? '<p class="post-legenda" title="' . e($leg) . '">' . e($leg) . '</p>' : '')
            . '<dl class="post-num">';
        foreach ($num as [$rot, $valor]) {
            echo '<div><dt title="' . e($rot) . '">' . e($rot) . '</dt><dd>' . e($valor) . '</dd></div>';
        }
        echo '</dl></div></article>';
    }
    if (!$lista) {
        echo '<p class="suave">Nenhum post deste formato entre os 50 mais recentes.</p>';
    }
    echo '</div></section>';
}

// Qual formato funciona melhor: media por post de cada formato, entre os 50 mais recentes
function organico_formatos(array $posts, callable $n, callable $pct): void
{
    $f = [];
    foreach ($posts as $p) {
        $g = $f[$p['formato']] ?? ['posts' => 0, 'com' => 0, 'alcance' => 0, 'vis' => 0, 'salvos' => 0, 'comp' => 0, 'inter' => 0, 'vendas' => 0];
        $g['posts']++;
        $g['vendas'] += $p['vendas48'];
        if ($p['alcance'] !== null) {
            $g['com']++;
            $g['alcance'] += (int)$p['alcance'];
            $g['vis'] += (int)$p['visualizacoes'];
            $g['salvos'] += (int)$p['salvos'];
            $g['comp'] += (int)$p['compartilhamentos'];
            $g['inter'] += (int)$p['interacoes'];
        }
        $f[$p['formato']] = $g;
    }
    uasort($f, fn($a, $b) => ($b['com'] ? $b['alcance'] / $b['com'] : 0) <=> ($a['com'] ? $a['alcance'] / $a['com'] : 0));
    $media = fn(array $g, string $c) => $g['com'] ? $n($g[$c] / $g['com']) : '—';
    echo '<section class="bloco">' . titulo('Qual formato funciona melhor', 'Média por post de cada formato, entre os 50 posts mais recentes (só os que já têm os números do Instagram). Do formato que mais alcança para o que menos.')
        . '<div class="tabela"><table><tr><th>Formato</th><th>' . com_info('Posts', 'Quantos posts desse formato entre os 50 mais recentes.') . '</th>'
        . '<th>' . com_info('Alcance médio', FEED_DICAS['Alcance'] . ' Média por post.') . '</th>'
        . '<th>' . com_info('Visualizações médias', FEED_DICAS['Visualizações'] . ' Média por post.') . '</th>'
        . '<th>' . com_info('Engajamento', 'Interações ÷ alcance, somando os posts do formato.') . '</th>'
        . '<th>' . com_info('Salvos médios', FEED_DICAS['Salvos'] . ' Média por post.') . '</th>'
        . '<th>' . com_info('Compartilhamentos médios', FEED_DICAS['Compartilhamentos'] . ' Média por post.') . '</th>'
        . '<th>' . com_info('Vendas em 48 h', FEED_DICAS['Vendas em 48 h']) . '</th></tr>';
    foreach ($f as $k => $g) {
        echo '<tr><td><strong>' . e(FEED_FORMATOS[$k]) . '</strong></td><td>' . $g['posts'] . '</td><td>' . $media($g, 'alcance') . '</td><td>' . $media($g, 'vis') . '</td>'
            . '<td>' . e($pct($g['alcance'] ? $g['inter'] / $g['alcance'] : null)) . '</td><td>' . $media($g, 'salvos') . '</td><td>' . $media($g, 'comp') . '</td>'
            . '<td>' . ($g['vendas'] ? '<span class="selo ok">' . $g['vendas'] . '</span>' : '0') . '</td></tr>';
    }
    echo '</table></div></section>';
}
