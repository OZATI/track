<?php
// Painel: conferencia de vendas, vendas, visitantes e eventos, filtrados por dominio,
// pagina e periodo. Ver README.md.

require __DIR__ . '/lib/util.php';
require __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/kiwify_sync.php';
require_once __DIR__ . '/lib/gestor.php';
require_once __DIR__ . '/lib/resumo.php';

exigir_login();
$db = track_db();

// ---------------------------------------------------------------- filtros
$abasValidas = ['geral', 'trafego', 'gestor', 'resumo', 'vendas', 'visitantes', 'eventos'];
$aba = in_array($_GET['aba'] ?? '', $abasValidas, true) ? $_GET['aba'] : 'geral';
$periodosValidos = ['hoje', 'ontem', '7d', '30d', 'tudo'];
$periodo = in_array($_GET['periodo'] ?? '', $periodosValidos, true) ? $_GET['periodo'] : '7d';

$dominios = $db->query('SELECT DISTINCT dominio FROM eventos ORDER BY dominio')->fetchAll(PDO::FETCH_COLUMN);
$dominio = in_array($_GET['dominio'] ?? '', $dominios, true) ? $_GET['dominio'] : '';
$paginas = [];
if ($dominio !== '') {
    $st = $db->prepare('SELECT DISTINCT pagina FROM eventos WHERE dominio = ? ORDER BY pagina');
    $st->execute([$dominio]);
    $paginas = $st->fetchAll(PDO::FETCH_COLUMN);
}
$pagina = in_array($_GET['pagina'] ?? '', $paginas, true) ? $_GET['pagina'] : '';
$filtro = ['dominio' => $dominio, 'pagina' => $pagina, 'periodo' => $periodo];
[$de, $ate] = periodo_utc($periodo);

// Colunas de WhatsApp so para site/pagina que ja teve clique no WhatsApp (em qualquer
// periodo). Quem vende so pelo checkout (ex.: EngDesk) nao ve coluna sempre zerada.
$parW = [];
$sqlW = "SELECT 1 FROM eventos WHERE nome = 'WhatsApp'";
if ($dominio !== '') {
    $sqlW .= ' AND dominio = :dom';
    $parW[':dom'] = $dominio;
}
if ($pagina !== '') {
    $sqlW .= ' AND pagina = :pag';
    $parW[':pag'] = $pagina;
}
$temWhats = (bool)valor($db, $sqlW . ' LIMIT 1', $parW);

// Condicao dos eventos no filtro
$condEv = 'e.em >= :de AND e.em < :ate';
$parEv = [':de' => $de, ':ate' => $ate];
if ($dominio !== '') {
    $condEv .= ' AND e.dominio = :dom';
    $parEv[':dom'] = $dominio;
}
if ($pagina !== '') {
    $condEv .= ' AND e.pagina = :pag';
    $parEv[':pag'] = $pagina;
}

// Vendas no filtro: com dominio escolhido, so as de visitantes que passaram por ele
$condVd = 'v.recebida_em >= :de AND v.recebida_em < :ate';
$parVd = [':de' => $de, ':ate' => $ate];
if ($dominio !== '') {
    $condVd .= ' AND v.visitante IN (SELECT visitante FROM eventos WHERE dominio = :dom' . ($pagina !== '' ? ' AND pagina = :pag' : '') . ')';
    $parVd[':dom'] = $dominio;
    if ($pagina !== '') {
        $parVd[':pag'] = $pagina;
    }
}

function consulta(PDO $db, string $sql, array $par): array
{
    $st = $db->prepare($sql);
    $st->execute($par);
    return $st->fetchAll();
}

function valor(PDO $db, string $sql, array $par)
{
    $st = $db->prepare($sql);
    $st->execute($par);
    return $st->fetchColumn();
}

function reais(?int $centavos): string
{
    return $centavos === null ? '' : 'R$ ' . number_format($centavos / 100, 2, ',', '.');
}

// Status da Kiwify em portugues. Aceita o status do pedido ou o tipo do evento.
function situacao(array $v): array
{
    $s = strtolower((string)$v['status']);
    $ev = strtolower((string)$v['evento']);
    if (in_array($s, ['paid', 'approved', 'completed', 'complete'], true) || in_array($ev, ['order_approved', 'compra_aprovada'], true)) {
        return ['Aprovada', 'ok'];
    }
    if (in_array($s, ['refunded'], true) || in_array($ev, ['order_refunded', 'compra_reembolsada'], true)) {
        return ['Reembolsada', 'erro'];
    }
    if (in_array($s, ['chargedback', 'chargeback'], true) || $ev === 'chargeback') {
        return ['Chargeback', 'erro'];
    }
    if (in_array($s, ['refused'], true) || in_array($ev, ['order_rejected', 'compra_recusada'], true)) {
        return ['Recusada', 'neutro'];
    }
    if (in_array($s, ['waiting_payment', 'pending'], true) || in_array($ev, ['pix_created', 'billet_created', 'pix_gerado', 'boleto_gerado'], true)) {
        return ['Aguardando pagamento', 'alerta'];
    }
    return [$v['status'] ?: ($v['evento'] ?: '—'), 'neutro'];
}

// Order bump e um pedido a parte na Kiwify, ligado ao principal: entra no faturamento,
// mas nao conta como outra venda nem outra conferencia
function eh_bump(array $v): bool
{
    return ($v['tipo'] ?? '') === 'bump' || !empty($v['pedido_pai']);
}

function aprovada(array $v): bool
{
    return situacao($v)[0] === 'Aprovada';
}

// Como a venda chegou ao painel
function chegada(?string $fonte): array
{
    switch ($fonte) {
        case 'ambos':
            return ['Webhook + API', 'ok'];
        case 'api':
            return ['Só pela API', 'alerta'];
        default:
            return ['Só webhook', 'neutro'];
    }
}

// Barra das vendas da Kiwify: de onde vem, ultima busca na API, botao de atualizar.
// data-sync pede ao painel.js uma busca em segundo plano (ultima com mais de 10 min).
function barra_vendas(array $parLink, string $aba): void
{
    if (!kiwify_api_chave()) {
        echo '<p class="suave barra-vendas">Vendas só pelo webhook da Kiwify. Para buscar também pela API (e pegar o que o webhook não entregar), cadastre a chave na aba <a href="kiwify-api.php">API Kiwify</a>.</p>';
        return;
    }
    $s = kiwify_sync_estado();
    if ($s['ok_em']) {
        $novas = (int)($s['resumo']['novas'] ?? 0);
        $texto = 'Vendas da Kiwify atualizadas em ' . data_local($s['ok_em'], 'd/m H:i') . ($novas ? ' · ' . $novas . ' nova(s)' : '');
    } else {
        $texto = 'Vendas da Kiwify: primeira busca pela API ainda não feita';
    }
    $volta = './?' . http_build_query(['aba' => $aba] + $parLink);
    echo '<div class="barra-vendas" id="sync"' . (kiwify_sync_vencida() ? ' data-sync="1"' : '') . '>'
        . '<span class="suave" data-sync-texto>' . e($texto) . '</span>'
        . '<form method="post" action="sincronizar.php"><input type="hidden" name="csrf" value="' . e(token_csrf()) . '">'
        . '<input type="hidden" name="volta" value="' . e($volta) . '"><button type="submit" class="discreto neutro">Atualizar vendas</button></form>'
        . '</div>';
    if ($s['adiada']) {
        echo '<p class="suave">Busca adiada: ' . e($s['adiada']) . '</p>';
    } elseif ($s['erro']) {
        echo '<p class="erro">Última busca na API falhou: ' . e($s['erro']) . '</p>';
    }
}

// Nome do evento em portugues (o t.js manda PageView, CliqueCheckout, WhatsApp e Botao)
function nome_evento(string $n): string
{
    return ['PageView' => 'Visualização', 'CliqueCheckout' => 'Clique no checkout', 'WhatsApp' => 'Clique no WhatsApp', 'Botao' => 'Clique em botão'][$n] ?? $n;
}

// Nome da campanha ou do anuncio sem o ID da Meta ("TL 1|120245..." vira "TL 1")
function nome_curto(?string $v): ?string
{
    $n = trim(explode('|', (string)$v)[0]);
    return $n === '' ? null : $n;
}

function origem(?string $source, ?string $medium, ?string $campaign): string
{
    $partes = array_filter([$source, $medium, $campaign], fn($x) => $x !== null && $x !== '');
    return $partes ? implode(' / ', $partes) : '';
}

// Conferencia: compara as etiquetas que a pagina mandou no ultimo clique de checkout
// (antes da venda) com as que a Kiwify gravou no pedido.
function conferir(PDO $db, array $v): array
{
    if (!$v['visitante']) {
        return ['Sem visitante', 'alerta', 'A venda chegou sem o identificador: link direto da Kiwify, outro aparelho ou página sem o t.js.'];
    }
    $st = $db->prepare("SELECT * FROM eventos WHERE visitante = ? AND nome = 'CliqueCheckout' AND em <= datetime(?, '+10 minutes') ORDER BY em DESC LIMIT 1");
    $st->execute([$v['visitante'], $v['recebida_em']]);
    $clique = $st->fetch();
    if (!$clique) {
        return ['Sem clique registrado', 'alerta', 'O visitante foi identificado, mas o clique no checkout não chegou ao painel.'];
    }
    $mandou = origem($clique['utm_source'], $clique['utm_medium'], $clique['utm_campaign']);
    $gravou = origem($v['utm_source'], $v['utm_medium'], $v['utm_campaign']);
    if ((string)$clique['utm_source'] === (string)$v['utm_source'] && (string)$clique['utm_campaign'] === (string)$v['utm_campaign']) {
        return ['Bate', 'ok', 'A página mandou ' . ($mandou ?: 'sem etiqueta') . ' e a Kiwify gravou o mesmo.'];
    }
    return ['Diferente', 'erro', 'A página mandou ' . ($mandou ?: 'sem etiqueta') . ', a Kiwify gravou ' . ($gravou ?: 'sem etiqueta') . '.'];
}

$parLink = ['dominio' => $dominio, 'pagina' => $pagina, 'periodo' => $periodo];
function link_visitante(string $vid, array $parLink): string
{
    return '?' . http_build_query(['aba' => 'visitantes', 'v' => $vid] + $parLink);
}

// Origem de um visitante, pelo primeiro evento dele: etiquetas, ou o site de onde veio
function rotulo_origem(array $ev): string
{
    if (!empty($ev['utm_source'])) {
        return origem($ev['utm_source'], $ev['utm_medium'], $ev['utm_campaign']);
    }
    if (!empty($ev['referrer'])) {
        return 'orgânico · ' . explode('/', $ev['referrer'])[0];
    }
    return 'direto (sem origem)';
}

pagina_inicio('Painel');
casca_inicio();
barra_topo($filtro, $dominios, $paginas, $aba);
echo '<main>';
if (in_array($aba, ['trafego', 'resumo', 'vendas'], true)) {
    barra_vendas($parLink, $aba);
}

// ---------------------------------------------------------------- detalhe de um visitante
$vid = $_GET['v'] ?? '';
if ($aba === 'visitantes' && is_string($vid) && preg_match('/^[a-f0-9]{32}$/', $vid)) {
    $st = $db->prepare('SELECT * FROM visitantes WHERE id = ?');
    $st->execute([$vid]);
    $vis = $st->fetch();
    if (!$vis) {
        echo '<p>Visitante não encontrado.</p></main>';
        casca_fim();
        pagina_fim();
        exit;
    }
    echo '<h2>Visitante <code>' . e(substr($vid, 0, 10)) . '</code></h2>';
    echo '<p class="suave">' . e($vis['dispositivo'] . ' · ' . $vis['sistema'] . ' · ' . $vis['navegador'] . ' · IP ' . $vis['ip']) . ' · primeira visita ' . e(data_local($vis['criado_em'])) . '</p>';

    $vendas = consulta($db, 'SELECT * FROM vendas WHERE visitante = ? ORDER BY recebida_em', [$vid]);
    if ($vendas) {
        echo '<h2>Vendas</h2><div class="tabela"><table><tr><th>Quando</th><th>Produto</th><th>Valor</th><th>Situação</th><th>Kiwify gravou</th><th>Conferência</th></tr>';
        foreach ($vendas as $v) {
            [$sit, $cls] = situacao($v);
            [$conf, $ccls, $explica] = conferir($db, $v);
            echo '<tr><td>' . e(data_local($v['recebida_em'])) . '</td><td>' . e($v['produto']) . '</td><td>' . e(reais($v['valor'])) . '</td>'
                . '<td><span class="selo ' . $cls . '">' . e($sit) . '</span></td><td>' . e(origem($v['utm_source'], $v['utm_medium'], $v['utm_campaign'])) . '</td>'
                . '<td class="quebra"><span class="selo ' . $ccls . '">' . e($conf) . '</span> <span class="suave">' . e($explica) . '</span></td></tr>';
        }
        echo '</table></div>';
    }

    $eventos = consulta($db, 'SELECT * FROM eventos WHERE visitante = ? ORDER BY em', [$vid]);
    echo '<h2>Linha do tempo (' . count($eventos) . ' eventos)</h2><div class="tabela"><table><tr><th>Quando</th><th>Evento</th><th>Domínio / página</th><th>Etiquetas</th><th>Veio de</th><th>Aparelho</th></tr>';
    foreach ($eventos as $ev) {
        echo '<tr><td>' . e(data_local($ev['em'])) . '</td><td><strong>' . e($ev['nome']) . '</strong>' . ($ev['detalhe'] ? ' <span class="suave">' . e($ev['detalhe']) . '</span>' : '') . '</td>'
            . '<td>' . e($ev['dominio'] . $ev['pagina']) . '</td><td>' . e(origem($ev['utm_source'], $ev['utm_medium'], $ev['utm_campaign'])) . '</td>'
            . '<td>' . e($ev['referrer']) . '</td><td>' . e($ev['dispositivo'] . ' · ' . $ev['ip']) . '</td></tr>';
    }
    echo '</table></div></main>';
    casca_fim();
    pagina_fim();
    exit;
}

// ---------------------------------------------------------------- trafego (tela inicial)
if ($aba === 'trafego') {
    $primeiros = consulta($db, "SELECT e.visitante, e.utm_source, e.utm_medium, e.utm_campaign, e.utm_term, e.referrer
                                FROM eventos e
                                JOIN (SELECT e.visitante AS v, MIN(e.id) AS primeiro FROM eventos e WHERE $condEv GROUP BY e.visitante) f
                                  ON f.primeiro = e.id", $parEv);
    $contas = [];
    foreach (consulta($db, "SELECT e.visitante, SUM(e.nome = 'PageView') AS pv, SUM(e.nome = 'CliqueCheckout') AS ck, SUM(e.nome = 'WhatsApp') AS wa
                            FROM eventos e WHERE $condEv GROUP BY e.visitante", $parEv) as $c) {
        $contas[$c['visitante']] = $c;
    }
    // Vendas = pedidos principais aprovados; faturamento = tudo aprovado, com order bump
    $vendasPorVisitante = [];
    $fatPorVisitante = [];
    $semVisitante = 0;
    $fatSemVisitante = 0;
    foreach (consulta($db, "SELECT v.* FROM vendas v WHERE $condVd", $parVd) as $v) {
        if (!aprovada($v)) {
            continue;
        }
        $n = eh_bump($v) ? 0 : 1;
        if ($v['visitante']) {
            $vendasPorVisitante[$v['visitante']] = ($vendasPorVisitante[$v['visitante']] ?? 0) + $n;
            $fatPorVisitante[$v['visitante']] = ($fatPorVisitante[$v['visitante']] ?? 0) + (int)$v['valor'];
        } else {
            $semVisitante += $n;
            $fatSemVisitante += (int)$v['valor'];
        }
    }

    // As mesmas contas de cada visitante, agrupadas de dois jeitos: por canal (Instagram,
    // Facebook, organico...) e pela origem detalhada (source / medium / campaign)
    $porCanal = [];
    $linhas = [];
    $somar = function (array &$grupo, string $chave, array $cn, array $soma): void {
        $g = $grupo[$chave] ?? ['canal' => $cn, 'vis' => 0, 'pv' => 0, 'ck' => 0, 'wa' => 0, 'vendas' => 0, 'fat' => 0];
        foreach ($soma as $campo => $n) {
            $g[$campo] += $n;
        }
        $grupo[$chave] = $g;
    };
    foreach ($primeiros as $p) {
        $c = $contas[$p['visitante']] ?? ['pv' => 0, 'ck' => 0, 'wa' => 0];
        $soma = ['vis' => 1, 'pv' => (int)$c['pv'], 'ck' => (int)$c['ck'] > 0 ? 1 : 0, 'wa' => (int)$c['wa'] > 0 ? 1 : 0,
            'vendas' => $vendasPorVisitante[$p['visitante']] ?? 0, 'fat' => $fatPorVisitante[$p['visitante']] ?? 0];
        $cn = canal($p['utm_source'], $p['utm_medium'], $p['utm_term'], $p['referrer']);
        $somar($porCanal, $cn[0], $cn, $soma);
        // Na origem detalhada, o icone e so pela etiqueta (a mesma campanha roda no Instagram e no Facebook)
        $somar($linhas, rotulo_origem($p), canal($p['utm_source'], $p['utm_medium'], null, $p['referrer']), $soma);
    }
    uasort($linhas, fn($a, $b) => [$b['vis'], $b['vendas']] <=> [$a['vis'], $a['vendas']]);
    uksort($porCanal, fn($a, $b) => array_search($a, CANAIS_ORDEM, true) <=> array_search($b, CANAIS_ORDEM, true));
    $taxa = fn($n, $d) => $d ? number_format($n * 100 / $d, 1, ',', '') . '%' : '—';

    $tabelaTrafego = function (string $cabecalho, array $grupo, callable $primeiraCelula) use ($temWhats, $taxa): void {
        echo '<div class="tabela"><table><tr><th>' . e($cabecalho) . '</th><th>Visitantes ' . info('Aparelhos diferentes.') . '</th><th>Visualizações ' . info('Páginas abertas.') . '</th>'
            . '<th>Clicaram no checkout ' . info('Visitantes que clicaram no botão de compra.') . '</th>' . ($temWhats ? '<th>Clicaram no WhatsApp</th>' : '')
            . '<th>Vendas aprovadas ' . info('Vendas aprovadas desses visitantes (sem contar order bump).') . '</th><th>Faturamento ' . info('Valor cobrado das vendas aprovadas, com order bump.') . '</th>'
            . '<th>Conversão ' . info('Vendas aprovadas ÷ visitantes.') . '</th></tr>';
        $t = ['vis' => 0, 'pv' => 0, 'ck' => 0, 'wa' => 0, 'vendas' => 0, 'fat' => 0];
        foreach ($grupo as $k => $l) {
            foreach ($t as $campo => $_) {
                $t[$campo] += $l[$campo];
            }
            echo '<tr><td class="quebra">' . $primeiraCelula((string)$k, $l) . '</td><td>' . $l['vis'] . '</td><td>' . $l['pv'] . '</td><td>' . $l['ck'] . '</td><td>' . ($temWhats ? $l['wa'] . '</td><td>' : '')
                . ($l['vendas'] ? '<span class="selo ok">' . $l['vendas'] . '</span>' : '0') . '</td><td>' . e($l['fat'] ? reais($l['fat']) : '—') . '</td><td>' . e($taxa($l['vendas'], $l['vis'])) . '</td></tr>';
        }
        if ($grupo) {
            echo '<tr><td><strong>Total</strong></td><td><strong>' . $t['vis'] . '</strong></td><td><strong>' . $t['pv'] . '</strong></td><td><strong>' . $t['ck'] . '</strong></td><td><strong>'
                . ($temWhats ? $t['wa'] . '</strong></td><td><strong>' : '') . $t['vendas'] . '</strong></td><td><strong>' . e(reais($t['fat'])) . '</strong></td><td><strong>' . e($taxa($t['vendas'], $t['vis'])) . '</strong></td></tr>';
        } else {
            echo '<tr><td colspan="' . ($temWhats ? 8 : 7) . '" class="suave">Nenhuma visita no período. As visitas chegam pelo t.js instalado nas páginas.</td></tr>';
        }
        echo '</table></div>';
    };

    $alvo = $dominio === '' ? 'todos os sites' : $dominio . ($pagina === '' ? ' (todas as páginas)' : $pagina);
    echo titulo('Tráfego por canal · ' . $alvo, 'Visitantes agrupados pelo canal da primeira visita no período: anúncio no Instagram ou no Facebook, orgânico, direto...');
    $tabelaTrafego('Canal', $porCanal, fn($k, $l) => selo_canal($l['canal'], false));
    echo titulo('Tráfego por origem · ' . $alvo, 'As mesmas visitas pela etiqueta completa (source / medium / campaign) da primeira visita.');
    $tabelaTrafego('Origem (source / medium / campaign)', $linhas, fn($k, $l) => com_icone_canal($l['canal'], $k));
    if ($semVisitante) {
        echo '<p class="suave">Mais ' . $semVisitante . ' venda(s) aprovada(s), ' . e(reais($fatSemVisitante)) . ', sem visitante no período (outra página, link direto da Kiwify ou outro aparelho): veja a aba Conferência.</p>';
    }

    if ($pagina === '') {
        $porPagina = consulta($db, "SELECT e.dominio, e.pagina, COUNT(DISTINCT e.visitante) AS vis, SUM(e.nome = 'PageView') AS pv,
                                           COUNT(DISTINCT CASE WHEN e.nome = 'CliqueCheckout' THEN e.visitante END) AS ck,
                                           COUNT(DISTINCT CASE WHEN e.nome = 'WhatsApp' THEN e.visitante END) AS wa
                                    FROM eventos e WHERE $condEv GROUP BY e.dominio, e.pagina ORDER BY vis DESC", $parEv);
        echo titulo('Tráfego por página', 'Visitantes e cliques no botão de compra em cada página com o painel.') . '<div class="tabela"><table><tr><th>Página</th><th>Visitantes</th><th>Visualizações</th><th>Clicaram no checkout</th>' . ($temWhats ? '<th>Clicaram no WhatsApp</th>' : '') . '<th>Taxa de clique no checkout</th></tr>';
        foreach ($porPagina as $p) {
            echo '<tr><td>' . e($p['dominio'] . $p['pagina']) . '</td><td>' . (int)$p['vis'] . '</td><td>' . (int)$p['pv'] . '</td><td>' . (int)$p['ck'] . '</td><td>'
                . ($temWhats ? (int)$p['wa'] . '</td><td>' : '') . e($taxa((int)$p['ck'], (int)$p['vis'])) . '</td></tr>';
        }
        if (!$porPagina) {
            echo '<tr><td colspan="6" class="suave">Nenhuma visita no período.</td></tr>';
        }
        echo '</table></div>';
    }
}

// ---------------------------------------------------------------- resumo (tela inicial)
if ($aba === 'geral') {
    resumo_render($db, $periodo, $de, $ate);
}

// ---------------------------------------------------------------- gestor de anuncios
if ($aba === 'gestor') {
    gestor_render($db, $periodo, $de, $ate, $parLink);
}

// ---------------------------------------------------------------- conferencia (resumo)
if ($aba === 'resumo') {
    $visitantes = (int)valor($db, "SELECT COUNT(DISTINCT e.visitante) FROM eventos e WHERE $condEv", $parEv);
    $pageviews = (int)valor($db, "SELECT COUNT(*) FROM eventos e WHERE $condEv AND e.nome = 'PageView'", $parEv);
    $cliques = (int)valor($db, "SELECT COUNT(DISTINCT e.visitante) FROM eventos e WHERE $condEv AND e.nome = 'CliqueCheckout'", $parEv);
    $whats = (int)valor($db, "SELECT COUNT(DISTINCT e.visitante) FROM eventos e WHERE $condEv AND e.nome = 'WhatsApp'", $parEv);
    $vendas = consulta($db, "SELECT v.* FROM vendas v WHERE $condVd ORDER BY v.recebida_em DESC", $parVd);

    $aprovadas = array_values(array_filter($vendas, fn($v) => aprovada($v) && !eh_bump($v)));
    $faturamento = array_sum(array_map(fn($v) => aprovada($v) ? (int)$v['valor'] : 0, $vendas));
    $contagem = ['Bate' => 0, 'Diferente' => 0, 'Sem visitante' => 0, 'Sem clique registrado' => 0];
    foreach ($aprovadas as $i => $v) {
        $c = conferir($db, $v);
        $aprovadas[$i]['_conf'] = $c;
        $contagem[$c[0]]++;
    }
    $total = count($aprovadas);
    $pct = fn($n) => $total ? round($n * 100 / $total) . '%' : '—';

    echo '<div class="numeros">'
        . numero((string)$visitantes, 'Visitantes (aparelhos)', 'Aparelhos diferentes que abriram as páginas com o painel (cookie do painel).')
        . numero((string)$pageviews, 'Visualizações de página', 'Quantas vezes as páginas foram abertas.')
        . numero((string)$cliques, 'Visitantes que clicaram no checkout', 'Visitantes que clicaram no botão de compra.')
        . ($temWhats ? numero((string)$whats, 'Visitantes que clicaram no WhatsApp', 'Visitantes que clicaram no botão do WhatsApp.') : '')
        . numero((string)$total, 'Vendas aprovadas', 'Pedidos principais aprovados no período. Order bump não conta como outra venda.')
        . numero(reais($faturamento), 'Faturamento aprovado (com order bump)', 'Valor cobrado das vendas aprovadas, com order bump (antes das taxas da Kiwify).')
        . numero($contagem['Bate'] . ' (' . $pct($contagem['Bate']) . ')', 'Vendas em que os dados batem', 'Vendas em que a campanha do clique na página é a mesma que a Kiwify gravou: origem confirmada.')
        . '</div>';

    echo titulo('Conferência das vendas aprovadas', 'Para cada venda aprovada, compara a campanha que o painel viu no clique do botão de compra com a que a Kiwify gravou.') . '<div class="tabela"><table><tr><th>Resultado</th><th>Vendas</th><th>O que significa</th></tr>';
    $sentido = [
        'Bate' => 'Origem confirmada: a campanha que o painel viu no clique do botão de compra é a mesma que a Kiwify gravou na venda (e que a UTMify usa).',
        'Diferente' => 'O painel viu uma campanha no clique e a Kiwify gravou outra. Abra a venda para ver as duas: a UTMify pode estar atribuindo errado.',
        'Sem visitante' => 'A venda não passou pela página com o painel: veio de outra página (ex.: drivedeprojetos.com/allan), de link direto da Kiwify, de outro aparelho, ou é de antes da instalação.',
        'Sem clique registrado' => 'O painel reconheceu a pessoa, mas o clique no botão de compra não chegou (ex.: a internet caiu no clique).',
    ];
    foreach ($contagem as $rotulo => $n) {
        echo '<tr><td>' . e($rotulo) . '</td><td>' . $n . ' (' . e($pct($n)) . ')</td><td class="quebra suave">' . e($sentido[$rotulo]) . '</td></tr>';
    }
    echo '</table></div>';

    // Como cada venda aprovada chegou: com a API ligada, "Só pela API" = o webhook falhou
    if (kiwify_api_chave()) {
        $chegou = ['Webhook + API' => 0, 'Só pela API' => 0, 'Só webhook' => 0];
        foreach ($aprovadas as $v) {
            $chegou[chegada($v['fonte'] ?? null)[0]]++;
        }
        $sentidoChegada = [
            'Webhook + API' => 'Chegou pelos dois caminhos: tudo certo.',
            'Só pela API' => 'O webhook não entregou; a busca pela API trouxe. Se aparecer muito, confira o webhook na Kiwify (Apps → Webhooks).',
            'Só webhook' => 'Chegou pelo webhook e a API ainda não buscou (ou a venda é mais antiga que a busca).',
        ];
        echo titulo('Como as vendas aprovadas chegaram ao painel', 'Webhook da Kiwify, busca pela API ou os dois. Muita venda só pela API indica webhook com problema.') . '<div class="tabela"><table><tr><th>Caminho</th><th>Vendas</th><th>O que significa</th></tr>';
        foreach ($chegou as $rotulo => $n) {
            echo '<tr><td>' . e($rotulo) . '</td><td>' . $n . ' (' . e($pct($n)) . ')</td><td class="quebra suave">' . e($sentidoChegada[$rotulo]) . '</td></tr>';
        }
        echo '</table></div>';
    }

    // Faturamento de cada venda com os order bumps dela
    $fatVenda = [];
    foreach ($vendas as $v) {
        if (aprovada($v)) {
            $dono = eh_bump($v) && $v['pedido_pai'] ? $v['pedido_pai'] : $v['pedido'];
            $fatVenda[$dono] = ($fatVenda[$dono] ?? 0) + (int)$v['valor'];
        }
    }
    $porCanal = [];
    $porOrigem = [];
    foreach ($aprovadas as $v) {
        $cn = canal($v['utm_source'], $v['utm_medium'], $v['utm_term']);
        $fat = $fatVenda[$v['pedido']] ?? (int)$v['valor'];
        $porCanal[$cn[0]] = ['canal' => $cn, 'n' => ($porCanal[$cn[0]]['n'] ?? 0) + 1, 'fat' => ($porCanal[$cn[0]]['fat'] ?? 0) + $fat];
        $k = origem($v['utm_source'], $v['utm_medium'], null) ?: '(sem etiqueta)';
        $porOrigem[$k] = ['canal' => canal($v['utm_source'], $v['utm_medium']), 'n' => ($porOrigem[$k]['n'] ?? 0) + 1, 'fat' => ($porOrigem[$k]['fat'] ?? 0) + $fat];
    }
    uksort($porCanal, fn($a, $b) => array_search($a, CANAIS_ORDEM, true) <=> array_search($b, CANAIS_ORDEM, true));
    uasort($porOrigem, fn($a, $b) => [$b['n'], $b['fat']] <=> [$a['n'], $a['fat']]);

    echo titulo('Vendas aprovadas por canal', 'Pelo canal da etiqueta que a Kiwify gravou na venda (a mesma que a UTMify usa).') . '<div class="tabela"><table><tr><th>Canal</th><th>Vendas</th><th>Faturamento (com order bump)</th></tr>';
    foreach ($porCanal as $g) {
        echo '<tr><td>' . selo_canal($g['canal'], false) . '</td><td>' . $g['n'] . ' (' . e($pct($g['n'])) . ')</td><td>' . e(reais($g['fat'])) . '</td></tr>';
    }
    if (!$porCanal) {
        echo '<tr><td colspan="3" class="suave">Nenhuma venda aprovada no período.</td></tr>';
    }
    echo '</table></div>';
    echo titulo('Detalhe por origem / meio', 'As mesmas vendas pela etiqueta source / medium.') . '<div class="tabela"><table><tr><th>Origem / meio</th><th>Vendas</th><th>Faturamento (com order bump)</th></tr>';
    foreach ($porOrigem as $k => $g) {
        echo '<tr><td class="quebra">' . com_icone_canal($g['canal'], (string)$k) . '</td><td>' . $g['n'] . '</td><td>' . e(reais($g['fat'])) . '</td></tr>';
    }
    if (!$porOrigem) {
        echo '<tr><td colspan="3" class="suave">Nenhuma venda aprovada no período.</td></tr>';
    }
    echo '</table></div>';
}

// ---------------------------------------------------------------- vendas
if ($aba === 'vendas') {
    $vendas = consulta($db, "SELECT v.*, vi.dispositivo, vi.navegador FROM vendas v LEFT JOIN visitantes vi ON vi.id = v.visitante WHERE $condVd ORDER BY v.recebida_em DESC LIMIT 500", $parVd);
    // Filtro "fora de anuncio" (link do gestor): vendas aprovadas sem o ID de uma campanha da Meta, com o motivo
    $soFora = ($_GET['filtro'] ?? '') === 'fora';
    if ($soFora) {
        $vendas = array_values(array_filter($vendas, fn($v) => aprovada($v) && !eh_bump($v) && gestor_id_utm($v['utm_campaign']) === null));
        echo '<p class="suave"><a href="' . e('./?' . http_build_query(['aba' => 'vendas'] + $parLink)) . '">← Todas as vendas</a></p>';
    }
    echo ($soFora
            ? titulo('Vendas fora de anúncio (' . count($vendas) . ')', 'Vendas aprovadas sem o ID de uma campanha da Meta na etiqueta. A UTMify chama de "não trackeadas". Orgânica é normal ficar fora; as outras mostram o que ajustar.')
            : '<h2>Vendas (' . count($vendas) . ' pedidos)</h2>')
        . '<div class="tabela"><table><tr><th>Quando</th><th>Pedido</th><th>Produto</th><th>Valor</th><th>Situação</th><th>Chegou por ' . info('Canal pela etiqueta que a Kiwify gravou: anúncio no Instagram ou no Facebook, orgânico, direto...') . '</th>'
        . '<th>Etiquetas na Kiwify</th><th>Visitante ' . info('Quem o painel reconheceu na página antes da compra (pelo sck). Clique para ver o caminho.') . '</th>'
        . ($soFora ? '<th>Por quê</th>' : '<th>Conferência ' . info('Compara a campanha do clique na página com a que a Kiwify gravou. Bate = origem confirmada.') . '</th>')
        . '<th>Recebida via ' . info('Como a venda chegou ao painel: webhook da Kiwify, busca pela API ou os dois.') . '</th></tr>';
    foreach ($vendas as $v) {
        [$sit, $cls] = situacao($v);
        [$via, $vcls] = chegada($v['fonte'] ?? null);
        $vis = $v['visitante']
            ? '<a href="' . e(link_visitante($v['visitante'], $parLink)) . '">' . e(substr($v['visitante'], 0, 8)) . '</a> <span class="suave">' . e($v['dispositivo'] . ' · ' . $v['navegador']) . '</span>'
            : '<span class="suave">—</span>';
        if ($soFora) {
            $confCel = '<span class="suave">' . e(gestor_motivo_fora($v)[1]) . '</span>';
        } elseif (eh_bump($v)) {
            $confCel = '<span class="suave">Order bump: conferido no pedido principal</span>';
        } else {
            [$conf, $ccls, $explica] = conferir($db, $v);
            $confCel = '<span class="selo ' . $ccls . '">' . e($conf) . '</span> <span class="suave">' . e($explica) . '</span>';
        }
        echo '<tr><td>' . e(data_local($v['recebida_em'])) . '</td><td><code>' . e($v['referencia'] ?: $v['pedido']) . '</code></td>'
            . '<td>' . e($v['produto']) . (eh_bump($v) ? ' <span class="selo neutro">order bump</span>' : '') . '</td><td>' . e(reais($v['valor'])) . '</td>'
            . '<td><span class="selo ' . $cls . '">' . e($sit) . '</span></td><td>' . selo_canal(canal($v['utm_source'], $v['utm_medium'], $v['utm_term'])) . '</td>'
            . '<td class="suave">' . e(origem($v['utm_source'], $v['utm_medium'], $v['utm_campaign'])) . '</td>'
            . '<td>' . $vis . '</td><td class="quebra">' . $confCel . '</td><td><span class="selo ' . $vcls . '">' . e($via) . '</span></td></tr>';
    }
    if (!$vendas) {
        echo '<tr><td colspan="10" class="suave">Nenhuma venda no período. As vendas chegam pelo webhook da Kiwify e, com a chave cadastrada, pela API.</td></tr>';
    }
    echo '</table></div>';
}

// ---------------------------------------------------------------- visitantes
if ($aba === 'visitantes') {
    $lista = consulta($db, "SELECT e.visitante, MIN(e.em) AS primeiro, MAX(e.em) AS ultimo, COUNT(*) AS total,
                                   SUM(e.nome = 'CliqueCheckout') AS cliques, SUM(e.nome = 'WhatsApp') AS whats
                            FROM eventos e WHERE $condEv GROUP BY e.visitante ORDER BY ultimo DESC LIMIT 300", $parEv);
    echo '<h2>Visitantes (' . count($lista) . ')</h2><div class="tabela"><table><tr><th>Última atividade</th><th>Visitante</th><th>Aparelho</th><th>IP</th><th>Chegou por</th><th>Eventos</th><th>Checkout</th>' . ($temWhats ? '<th>WhatsApp</th>' : '') . '<th>Venda</th></tr>';
    foreach ($lista as $l) {
        $st = $db->prepare('SELECT * FROM visitantes WHERE id = ?');
        $st->execute([$l['visitante']]);
        $vis = $st->fetch() ?: [];
        $st = $db->prepare("SELECT * FROM eventos e WHERE e.visitante = :v AND $condEv ORDER BY e.em LIMIT 1");
        $st->execute([':v' => $l['visitante']] + $parEv);
        $primeiro = $st->fetch();
        $chegou = origem($primeiro['utm_source'] ?? null, $primeiro['utm_medium'] ?? null, $primeiro['utm_campaign'] ?? null) ?: ($primeiro['referrer'] ?? '') ?: 'direto / sem origem';
        $temVenda = (int)valor($db, 'SELECT COUNT(*) FROM vendas WHERE visitante = ?', [$l['visitante']]);
        echo '<tr><td>' . e(data_local($l['ultimo'])) . '</td><td><a href="' . e(link_visitante($l['visitante'], $parLink)) . '">' . e(substr($l['visitante'], 0, 8)) . '</a></td>'
            . '<td>' . e(($vis['dispositivo'] ?? '') . ' · ' . ($vis['navegador'] ?? '')) . '</td><td>' . e($vis['ip'] ?? '') . '</td><td>'
            . com_icone_canal(canal($primeiro['utm_source'] ?? null, $primeiro['utm_medium'] ?? null, $primeiro['utm_term'] ?? null, $primeiro['referrer'] ?? null), $chegou) . '</td>'
            . '<td>' . (int)$l['total'] . '</td><td>' . ((int)$l['cliques'] ? (int)$l['cliques'] : '') . '</td>' . ($temWhats ? '<td>' . ((int)$l['whats'] ? (int)$l['whats'] : '') . '</td>' : '')
            . '<td>' . ($temVenda ? '<span class="selo ok">' . $temVenda . '</span>' : '') . '</td></tr>';
    }
    if (!$lista) {
        echo '<tr><td colspan="9" class="suave">Nenhum visitante no período.</td></tr>';
    }
    echo '</table></div>';
}

// ---------------------------------------------------------------- eventos
if ($aba === 'eventos') {
    // Quantos de cada tipo no filtro; cada numero filtra a tabela por aquele evento
    $tipos = [];
    foreach (consulta($db, "SELECT e.nome, COUNT(*) AS n FROM eventos e WHERE $condEv GROUP BY e.nome ORDER BY n DESC", $parEv) as $t) {
        $tipos[$t['nome']] = (int)$t['n'];
    }
    // Quem comprou: visitantes do filtro com venda principal aprovada
    $compradores = [];
    foreach (consulta($db, "SELECT v.* FROM vendas v WHERE v.visitante IN (SELECT DISTINCT e.visitante FROM eventos e WHERE $condEv)", $parEv) as $v) {
        if (aprovada($v) && !eh_bump($v)) {
            $compradores[$v['visitante']] = true;
        }
    }
    $pedido = is_string($_GET['evento'] ?? null) ? $_GET['evento'] : '';
    $soCompradores = $pedido === 'compraram';
    $evento = isset($tipos[$pedido]) ? $pedido : '';
    $cond = $condEv . ($evento !== '' ? ' AND e.nome = :nome' : '');
    $par = $parEv + ($evento !== '' ? [':nome' => $evento] : []);
    if ($soCompradores) {
        $marcas = [];
        foreach (array_keys($compradores) as $i => $vid) {
            $marcas[] = ':c' . $i;
            $par[':c' . $i] = $vid;
        }
        $cond .= $marcas ? ' AND e.visitante IN (' . implode(',', $marcas) . ')' : ' AND 0';
    }
    $total = (int)valor($db, "SELECT COUNT(*) FROM eventos e WHERE $cond", $par);
    $porPagina = 200;
    $pg = min(max(1, (int)($_GET['p'] ?? 1)), max(1, (int)ceil($total / $porPagina)));
    $lista = consulta($db, "SELECT e.*, vi.sistema FROM eventos e LEFT JOIN visitantes vi ON vi.id = e.visitante
                            WHERE $cond ORDER BY e.em DESC, e.id DESC LIMIT $porPagina OFFSET " . (($pg - 1) * $porPagina), $par);

    $linkEventos = fn(array $extra) => './?' . http_build_query(['aba' => 'eventos'] + $parLink + $extra);
    echo '<div class="filtro-eventos"><a href="' . e($linkEventos([])) . '" class="' . ($evento === '' && !$soCompradores ? 'atual' : '') . '">Todos <b>' . array_sum($tipos) . '</b></a>';
    foreach ($tipos as $nome => $n) {
        echo '<a href="' . e($linkEventos(['evento' => $nome])) . '" class="' . ($evento === $nome ? 'atual' : '') . '">' . e(nome_evento($nome)) . ' <b>' . $n . '</b></a>';
    }
    echo '<a href="' . e($linkEventos(['evento' => 'compraram'])) . '" class="compra' . ($soCompradores ? ' atual' : '') . '" title="Pessoas deste período com venda aprovada. Clique para ver o caminho delas até a compra.">Compraram <b>' . count($compradores) . '</b></a>';
    echo '</div>';

    $de1 = $total ? ($pg - 1) * $porPagina + 1 : 0;
    $titulo = $soCompradores ? 'Eventos de quem comprou' : ($evento !== '' ? nome_evento($evento) : 'Eventos');
    echo '<h2>' . e($titulo) . ' <span class="suave">(' . $de1 . '–' . (($pg - 1) * $porPagina + count($lista)) . ' de ' . $total . ')</span></h2>';
    echo '<div class="tabela"><table><tr><th>Quando</th><th>Evento</th><th>Página</th><th>Canal</th><th>Campanha · anúncio · posicionamento</th><th>Veio de</th><th>Aparelho</th><th>Visitante</th></tr>';
    foreach ($lista as $ev) {
        $anuncio = implode(' · ', array_filter([nome_curto($ev['utm_campaign']), nome_curto($ev['utm_content']), $ev['utm_term'] ? str_replace('_', ' ', $ev['utm_term']) : null]));
        $aparelho = implode(' · ', array_filter([$ev['dispositivo'], ($ev['sistema'] ?? '') !== 'Outro' ? $ev['sistema'] : null, $ev['ip']]));
        echo '<tr><td>' . e(data_local($ev['em'])) . '</td><td><strong>' . e(nome_evento($ev['nome'])) . '</strong>' . ($ev['detalhe'] ? ' <span class="suave">' . e($ev['detalhe']) . '</span>' : '') . '</td>'
            . '<td>' . e($ev['dominio'] . $ev['pagina']) . '</td>'
            . '<td>' . selo_canal(canal($ev['utm_source'], $ev['utm_medium'], $ev['utm_term'], $ev['referrer'])) . '</td>'
            . '<td class="quebra">' . ($anuncio !== '' ? e($anuncio) : '<span class="suave">—</span>') . '</td>'
            . '<td>' . e($ev['referrer']) . '</td><td>' . e($aparelho) . '</td>'
            . '<td><a href="' . e(link_visitante($ev['visitante'], $parLink)) . '">' . e(substr($ev['visitante'], 0, 8)) . '</a>'
            . (isset($compradores[$ev['visitante']]) ? ' <span class="selo ok">Comprou</span>' : '') . '</td></tr>';
    }
    if (!$lista) {
        echo '<tr><td colspan="8" class="suave">Nenhum evento no período.</td></tr>';
    }
    echo '</table></div>';
    if ($total > $porPagina) {
        echo '<p class="linha-botoes">'
            . ($pg > 1 ? '<a href="' . e($linkEventos(($soCompradores ? ['evento' => 'compraram'] : ($evento !== '' ? ['evento' => $evento] : [])) + ['p' => $pg - 1])) . '">← Mais recentes</a>' : '')
            . ($pg * $porPagina < $total ? '<a href="' . e($linkEventos(($soCompradores ? ['evento' => 'compraram'] : ($evento !== '' ? ['evento' => $evento] : [])) + ['p' => $pg + 1])) . '">Mais antigos →</a>' : '')
            . '</p>';
    }
}

echo '</main>';
casca_fim();
pagina_fim();
