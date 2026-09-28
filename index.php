<?php
// Painel: conferencia de vendas, vendas, visitantes e eventos, filtrados por dominio,
// pagina e periodo. Ver README.md.

require __DIR__ . '/lib/util.php';
require __DIR__ . '/lib/layout.php';

exigir_login();
$db = track_db();

// ---------------------------------------------------------------- filtros
$abasValidas = ['resumo', 'vendas', 'visitantes', 'eventos'];
$aba = in_array($_GET['aba'] ?? '', $abasValidas, true) ? $_GET['aba'] : 'resumo';
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

pagina_inicio('Painel');
barra_topo($filtro, $dominios, $paginas, $aba);
echo '<main>';

// ---------------------------------------------------------------- detalhe de um visitante
$vid = $_GET['v'] ?? '';
if ($aba === 'visitantes' && is_string($vid) && preg_match('/^[a-f0-9]{32}$/', $vid)) {
    $st = $db->prepare('SELECT * FROM visitantes WHERE id = ?');
    $st->execute([$vid]);
    $vis = $st->fetch();
    if (!$vis) {
        echo '<p>Visitante não encontrado.</p></main>';
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
    pagina_fim();
    exit;
}

// ---------------------------------------------------------------- conferencia (resumo)
if ($aba === 'resumo') {
    $visitantes = (int)valor($db, "SELECT COUNT(DISTINCT e.visitante) FROM eventos e WHERE $condEv", $parEv);
    $pageviews = (int)valor($db, "SELECT COUNT(*) FROM eventos e WHERE $condEv AND e.nome = 'PageView'", $parEv);
    $cliques = (int)valor($db, "SELECT COUNT(DISTINCT e.visitante) FROM eventos e WHERE $condEv AND e.nome = 'CliqueCheckout'", $parEv);
    $whats = (int)valor($db, "SELECT COUNT(DISTINCT e.visitante) FROM eventos e WHERE $condEv AND e.nome = 'WhatsApp'", $parEv);
    $vendas = consulta($db, "SELECT v.* FROM vendas v WHERE $condVd ORDER BY v.recebida_em DESC", $parVd);

    $aprovadas = array_values(array_filter($vendas, fn($v) => situacao($v)[0] === 'Aprovada'));
    $contagem = ['Bate' => 0, 'Diferente' => 0, 'Sem visitante' => 0, 'Sem clique registrado' => 0];
    foreach ($aprovadas as $i => $v) {
        $c = conferir($db, $v);
        $aprovadas[$i]['_conf'] = $c;
        $contagem[$c[0]]++;
    }
    $total = count($aprovadas);
    $pct = fn($n) => $total ? round($n * 100 / $total) . '%' : '—';

    echo '<div class="numeros">';
    foreach ([
        [$visitantes, 'Visitantes (aparelhos)'],
        [$pageviews, 'Visualizações de página'],
        [$cliques, 'Visitantes que clicaram no checkout'],
        [$whats, 'Visitantes que clicaram no WhatsApp'],
        [$total, 'Vendas aprovadas'],
        [$contagem['Bate'] . ' (' . $pct($contagem['Bate']) . ')', 'Vendas em que os dados batem'],
    ] as [$n, $rotulo]) {
        echo '<div class="numero"><b>' . e($n) . '</b><span>' . e($rotulo) . '</span></div>';
    }
    echo '</div>';

    echo '<h2>Conferência das vendas aprovadas</h2><div class="tabela"><table><tr><th>Resultado</th><th>Vendas</th><th>O que significa</th></tr>';
    $sentido = [
        'Bate' => 'A etiqueta que a página mandou para a Kiwify é a mesma que ficou no pedido.',
        'Diferente' => 'A Kiwify gravou outra etiqueta. Abra a venda para ver as duas.',
        'Sem visitante' => 'Pedido sem o identificador do painel: link direto da Kiwify, troca de aparelho ou página sem o t.js.',
        'Sem clique registrado' => 'O visitante foi reconhecido, mas o clique no checkout não chegou (ex.: rede caiu no clique).',
    ];
    foreach ($contagem as $rotulo => $n) {
        echo '<tr><td>' . e($rotulo) . '</td><td>' . $n . ' (' . e($pct($n)) . ')</td><td class="quebra suave">' . e($sentido[$rotulo]) . '</td></tr>';
    }
    echo '</table></div>';

    $porOrigem = [];
    foreach ($aprovadas as $v) {
        $k = origem($v['utm_source'], $v['utm_medium'], null) ?: '(sem etiqueta)';
        $porOrigem[$k] = ($porOrigem[$k] ?? 0) + 1;
    }
    arsort($porOrigem);
    echo '<h2>Vendas aprovadas por origem (como a Kiwify gravou)</h2><div class="tabela"><table><tr><th>Origem / meio</th><th>Vendas</th></tr>';
    foreach ($porOrigem as $k => $n) {
        echo '<tr><td>' . e($k) . '</td><td>' . $n . '</td></tr>';
    }
    if (!$porOrigem) {
        echo '<tr><td colspan="2" class="suave">Nenhuma venda aprovada no período.</td></tr>';
    }
    echo '</table></div>';
}

// ---------------------------------------------------------------- vendas
if ($aba === 'vendas') {
    $vendas = consulta($db, "SELECT v.*, vi.dispositivo, vi.navegador FROM vendas v LEFT JOIN visitantes vi ON vi.id = v.visitante WHERE $condVd ORDER BY v.recebida_em DESC LIMIT 500", $parVd);
    echo '<h2>Vendas (' . count($vendas) . ')</h2><div class="tabela"><table><tr><th>Quando</th><th>Pedido</th><th>Produto</th><th>Valor</th><th>Situação</th><th>Kiwify gravou</th><th>Visitante</th><th>Conferência</th></tr>';
    foreach ($vendas as $v) {
        [$sit, $cls] = situacao($v);
        [$conf, $ccls, $explica] = conferir($db, $v);
        $vis = $v['visitante']
            ? '<a href="' . e(link_visitante($v['visitante'], $parLink)) . '">' . e(substr($v['visitante'], 0, 8)) . '</a> <span class="suave">' . e($v['dispositivo'] . ' · ' . $v['navegador']) . '</span>'
            : '<span class="suave">—</span>';
        echo '<tr><td>' . e(data_local($v['recebida_em'])) . '</td><td><code>' . e($v['pedido']) . '</code></td><td>' . e($v['produto']) . '</td><td>' . e(reais($v['valor'])) . '</td>'
            . '<td><span class="selo ' . $cls . '">' . e($sit) . '</span></td><td>' . e(origem($v['utm_source'], $v['utm_medium'], $v['utm_campaign'])) . '</td>'
            . '<td>' . $vis . '</td><td class="quebra"><span class="selo ' . $ccls . '">' . e($conf) . '</span> <span class="suave">' . e($explica) . '</span></td></tr>';
    }
    if (!$vendas) {
        echo '<tr><td colspan="8" class="suave">Nenhuma venda no período. As vendas chegam pelo webhook da Kiwify.</td></tr>';
    }
    echo '</table></div>';
}

// ---------------------------------------------------------------- visitantes
if ($aba === 'visitantes') {
    $lista = consulta($db, "SELECT e.visitante, MIN(e.em) AS primeiro, MAX(e.em) AS ultimo, COUNT(*) AS total,
                                   SUM(e.nome = 'CliqueCheckout') AS cliques, SUM(e.nome = 'WhatsApp') AS whats
                            FROM eventos e WHERE $condEv GROUP BY e.visitante ORDER BY ultimo DESC LIMIT 300", $parEv);
    echo '<h2>Visitantes (' . count($lista) . ')</h2><div class="tabela"><table><tr><th>Última atividade</th><th>Visitante</th><th>Aparelho</th><th>IP</th><th>Chegou por</th><th>Eventos</th><th>Checkout</th><th>WhatsApp</th><th>Venda</th></tr>';
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
            . '<td>' . e(($vis['dispositivo'] ?? '') . ' · ' . ($vis['navegador'] ?? '')) . '</td><td>' . e($vis['ip'] ?? '') . '</td><td>' . e($chegou) . '</td>'
            . '<td>' . (int)$l['total'] . '</td><td>' . ((int)$l['cliques'] ? (int)$l['cliques'] : '') . '</td><td>' . ((int)$l['whats'] ? (int)$l['whats'] : '') . '</td>'
            . '<td>' . ($temVenda ? '<span class="selo ok">' . $temVenda . '</span>' : '') . '</td></tr>';
    }
    if (!$lista) {
        echo '<tr><td colspan="9" class="suave">Nenhum visitante no período.</td></tr>';
    }
    echo '</table></div>';
}

// ---------------------------------------------------------------- eventos
if ($aba === 'eventos') {
    $lista = consulta($db, "SELECT e.* FROM eventos e WHERE $condEv ORDER BY e.em DESC LIMIT 300", $parEv);
    echo '<h2>Últimos eventos (' . count($lista) . ')</h2><div class="tabela"><table><tr><th>Quando</th><th>Evento</th><th>Domínio / página</th><th>Etiquetas</th><th>Veio de</th><th>Aparelho</th><th>Visitante</th></tr>';
    foreach ($lista as $ev) {
        echo '<tr><td>' . e(data_local($ev['em'])) . '</td><td><strong>' . e($ev['nome']) . '</strong>' . ($ev['detalhe'] ? ' <span class="suave">' . e($ev['detalhe']) . '</span>' : '') . '</td>'
            . '<td>' . e($ev['dominio'] . $ev['pagina']) . '</td><td>' . e(origem($ev['utm_source'], $ev['utm_medium'], $ev['utm_campaign'])) . '</td>'
            . '<td>' . e($ev['referrer']) . '</td><td>' . e($ev['dispositivo'] . ' · ' . $ev['ip']) . '</td>'
            . '<td><a href="' . e(link_visitante($ev['visitante'], $parLink)) . '">' . e(substr($ev['visitante'], 0, 8)) . '</a></td></tr>';
    }
    if (!$lista) {
        echo '<tr><td colspan="7" class="suave">Nenhum evento no período.</td></tr>';
    }
    echo '</table></div>';
}

echo '</main>';
pagina_fim();
