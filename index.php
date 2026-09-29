<?php
// Painel: conferencia de vendas, vendas, visitantes e eventos, filtrados por dominio,
// pagina e periodo. Ver README.md.

require __DIR__ . '/lib/util.php';
require __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/kiwify_sync.php';

exigir_login();
$db = track_db();

// ---------------------------------------------------------------- filtros
$abasValidas = ['trafego', 'resumo', 'vendas', 'visitantes', 'eventos'];
$aba = in_array($_GET['aba'] ?? '', $abasValidas, true) ? $_GET['aba'] : 'trafego';
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
    $texto = 'Vendas da Kiwify pelo webhook e pela API';
    if ($s['ok_em']) {
        $texto .= ' · última busca na API: ' . data_local($s['ok_em'], 'd/m H:i');
        if ($s['resumo']) {
            $texto .= ' (' . (int)$s['resumo']['novas'] . ' nova(s), ' . (int)$s['resumo']['atualizadas'] . ' atualizada(s))';
        }
    } else {
        $texto .= ' · primeira busca na API ainda não feita (traz os últimos 89 dias)';
    }
    $volta = './?' . http_build_query(['aba' => $aba] + $parLink);
    echo '<div class="barra-vendas" id="sync"' . (kiwify_sync_vencida() ? ' data-sync="1"' : '') . '>'
        . '<span class="suave" data-sync-texto>' . e($texto) . '</span>'
        . '<form method="post" action="sincronizar.php"><input type="hidden" name="csrf" value="' . e(token_csrf()) . '">'
        . '<input type="hidden" name="volta" value="' . e($volta) . '"><button type="submit" class="discreto neutro">Atualizar vendas</button></form>'
        . '</div>';
    if ($s['erro']) {
        echo '<p class="erro">Última busca na API falhou: ' . e($s['erro']) . '</p>';
    }
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
    $primeiros = consulta($db, "SELECT e.visitante, e.utm_source, e.utm_medium, e.utm_campaign, e.referrer
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

    $linhas = [];
    foreach ($primeiros as $p) {
        $k = rotulo_origem($p);
        $c = $contas[$p['visitante']] ?? ['pv' => 0, 'ck' => 0, 'wa' => 0];
        $l = $linhas[$k] ?? ['vis' => 0, 'pv' => 0, 'ck' => 0, 'wa' => 0, 'vendas' => 0, 'fat' => 0];
        $l['vis']++;
        $l['pv'] += (int)$c['pv'];
        $l['ck'] += (int)$c['ck'] > 0 ? 1 : 0;
        $l['wa'] += (int)$c['wa'] > 0 ? 1 : 0;
        $l['vendas'] += $vendasPorVisitante[$p['visitante']] ?? 0;
        $l['fat'] += $fatPorVisitante[$p['visitante']] ?? 0;
        $linhas[$k] = $l;
    }
    uasort($linhas, fn($a, $b) => [$b['vis'], $b['vendas']] <=> [$a['vis'], $a['vendas']]);
    $taxa = fn($n, $d) => $d ? number_format($n * 100 / $d, 1, ',', '') . '%' : '—';

    $alvo = $dominio === '' ? 'todos os sites' : $dominio . ($pagina === '' ? ' (todas as páginas)' : $pagina);
    echo '<h2>Tráfego por origem · ' . e($alvo) . '</h2>';
    echo '<div class="tabela"><table><tr><th>Origem (source / medium / campaign)</th><th>Visitantes</th><th>Visualizações</th><th>Clicaram no checkout</th>' . ($temWhats ? '<th>Clicaram no WhatsApp</th>' : '') . '<th>Vendas aprovadas</th><th>Faturamento</th><th>Conversão</th></tr>';
    $t = ['vis' => 0, 'pv' => 0, 'ck' => 0, 'wa' => 0, 'vendas' => 0, 'fat' => 0];
    foreach ($linhas as $k => $l) {
        foreach ($t as $campo => $_) {
            $t[$campo] += $l[$campo];
        }
        echo '<tr><td class="quebra">' . e($k) . '</td><td>' . $l['vis'] . '</td><td>' . $l['pv'] . '</td><td>' . $l['ck'] . '</td><td>' . ($temWhats ? $l['wa'] . '</td><td>' : '')
            . ($l['vendas'] ? '<span class="selo ok">' . $l['vendas'] . '</span>' : '0') . '</td><td>' . e($l['fat'] ? reais($l['fat']) : '—') . '</td><td>' . e($taxa($l['vendas'], $l['vis'])) . '</td></tr>';
    }
    if ($linhas) {
        echo '<tr><td><strong>Total</strong></td><td><strong>' . $t['vis'] . '</strong></td><td><strong>' . $t['pv'] . '</strong></td><td><strong>' . $t['ck'] . '</strong></td><td><strong>'
            . ($temWhats ? $t['wa'] . '</strong></td><td><strong>' : '') . $t['vendas'] . '</strong></td><td><strong>' . e(reais($t['fat'])) . '</strong></td><td><strong>' . e($taxa($t['vendas'], $t['vis'])) . '</strong></td></tr>';
    } else {
        echo '<tr><td colspan="' . ($temWhats ? 8 : 7) . '" class="suave">Nenhuma visita no período. As visitas chegam pelo t.js instalado nas páginas.</td></tr>';
    }
    echo '</table></div>';
    if ($semVisitante) {
        echo '<p class="suave">Mais ' . $semVisitante . ' venda(s) aprovada(s), ' . e(reais($fatSemVisitante)) . ', sem visitante no período (outra página, link direto da Kiwify ou outro aparelho): veja a aba Conferência.</p>';
    }

    if ($pagina === '') {
        $porPagina = consulta($db, "SELECT e.dominio, e.pagina, COUNT(DISTINCT e.visitante) AS vis, SUM(e.nome = 'PageView') AS pv,
                                           COUNT(DISTINCT CASE WHEN e.nome = 'CliqueCheckout' THEN e.visitante END) AS ck,
                                           COUNT(DISTINCT CASE WHEN e.nome = 'WhatsApp' THEN e.visitante END) AS wa
                                    FROM eventos e WHERE $condEv GROUP BY e.dominio, e.pagina ORDER BY vis DESC", $parEv);
        echo '<h2>Tráfego por página</h2><div class="tabela"><table><tr><th>Página</th><th>Visitantes</th><th>Visualizações</th><th>Clicaram no checkout</th>' . ($temWhats ? '<th>Clicaram no WhatsApp</th>' : '') . '<th>Taxa de clique no checkout</th></tr>';
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

    $numeros = [
        [$visitantes, 'Visitantes (aparelhos)'],
        [$pageviews, 'Visualizações de página'],
        [$cliques, 'Visitantes que clicaram no checkout'],
    ];
    if ($temWhats) {
        $numeros[] = [$whats, 'Visitantes que clicaram no WhatsApp'];
    }
    $numeros[] = [$total, 'Vendas aprovadas'];
    $numeros[] = [reais($faturamento), 'Faturamento aprovado (com order bump)'];
    $numeros[] = [$contagem['Bate'] . ' (' . $pct($contagem['Bate']) . ')', 'Vendas em que os dados batem'];
    echo '<div class="numeros">';
    foreach ($numeros as [$n, $rotulo]) {
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
        echo '<h2>Como as vendas aprovadas chegaram ao painel</h2><div class="tabela"><table><tr><th>Caminho</th><th>Vendas</th><th>O que significa</th></tr>';
        foreach ($chegou as $rotulo => $n) {
            echo '<tr><td>' . e($rotulo) . '</td><td>' . $n . ' (' . e($pct($n)) . ')</td><td class="quebra suave">' . e($sentidoChegada[$rotulo]) . '</td></tr>';
        }
        echo '</table></div>';
    }

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
    echo '<h2>Vendas (' . count($vendas) . ' pedidos)</h2><div class="tabela"><table><tr><th>Quando</th><th>Pedido</th><th>Produto</th><th>Valor</th><th>Situação</th><th>Kiwify gravou</th><th>Visitante</th><th>Chegou por</th><th>Conferência</th></tr>';
    foreach ($vendas as $v) {
        [$sit, $cls] = situacao($v);
        [$via, $vcls] = chegada($v['fonte'] ?? null);
        $vis = $v['visitante']
            ? '<a href="' . e(link_visitante($v['visitante'], $parLink)) . '">' . e(substr($v['visitante'], 0, 8)) . '</a> <span class="suave">' . e($v['dispositivo'] . ' · ' . $v['navegador']) . '</span>'
            : '<span class="suave">—</span>';
        if (eh_bump($v)) {
            $confCel = '<span class="suave">Order bump: conferido no pedido principal</span>';
        } else {
            [$conf, $ccls, $explica] = conferir($db, $v);
            $confCel = '<span class="selo ' . $ccls . '">' . e($conf) . '</span> <span class="suave">' . e($explica) . '</span>';
        }
        echo '<tr><td>' . e(data_local($v['recebida_em'])) . '</td><td><code>' . e($v['referencia'] ?: $v['pedido']) . '</code></td>'
            . '<td>' . e($v['produto']) . (eh_bump($v) ? ' <span class="selo neutro">order bump</span>' : '') . '</td><td>' . e(reais($v['valor'])) . '</td>'
            . '<td><span class="selo ' . $cls . '">' . e($sit) . '</span></td><td>' . e(origem($v['utm_source'], $v['utm_medium'], $v['utm_campaign'])) . '</td>'
            . '<td>' . $vis . '</td><td><span class="selo ' . $vcls . '">' . e($via) . '</span></td><td class="quebra">' . $confCel . '</td></tr>';
    }
    if (!$vendas) {
        echo '<tr><td colspan="9" class="suave">Nenhuma venda no período. As vendas chegam pelo webhook da Kiwify e, com a chave cadastrada, pela API.</td></tr>';
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
            . '<td>' . e(($vis['dispositivo'] ?? '') . ' · ' . ($vis['navegador'] ?? '')) . '</td><td>' . e($vis['ip'] ?? '') . '</td><td>' . e($chegou) . '</td>'
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
casca_fim();
pagina_fim();
