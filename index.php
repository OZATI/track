<?php
// Painel: conferencia de vendas, vendas, visitantes e eventos, filtrados por dominio,
// pagina e periodo. Ver README.md.

require __DIR__ . '/lib/util.php';
require __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/kiwify_sync.php';
require_once __DIR__ . '/lib/vendas.php';
require_once __DIR__ . '/lib/gestor.php';
require_once __DIR__ . '/lib/resumo.php';
require_once __DIR__ . '/lib/organico.php';
require_once __DIR__ . '/lib/trafego.php';
require_once __DIR__ . '/lib/financeiro.php';
require_once __DIR__ . '/lib/painel.php';

exigir_login();
$db = track_db();

// ---------------------------------------------------------------- filtros
$abasValidas = ['geral', 'painel', 'trafego', 'gestor', 'campanha', 'financeiro', 'organico', 'resumo', 'vendas', 'visitantes', 'eventos'];
$aba = in_array($_GET['aba'] ?? '', $abasValidas, true) ? $_GET['aba'] : 'geral';
// Site, pagina, periodo e produto ficam lembrados na sessao: trocar de aba, abrir Configuracoes
// ou uma API e voltar nao zera o filtro. Parametro presente no endereco (mesmo vazio) vale e e guardado.
sessao_iniciar();
$lembrado = is_array($_SESSION['track_filtro'] ?? null) ? $_SESSION['track_filtro'] : [];
$escolhido = fn(string $k): string => array_key_exists($k, $_GET) ? (is_string($_GET[$k]) ? $_GET[$k] : '') : (string)($lembrado[$k] ?? '');
// Periodo: um dos prontos, o personalizado do endereco ("2026-09-25_2026-09-28") ou o que o
// filtro manda ao escolher "De uma data a outra" (periodo=personalizado com de e ate)
$periodo = $escolhido('periodo');
if ($periodo === 'personalizado') {
    $periodo = periodo_personalizado($_GET['de'] ?? null, $_GET['ate'] ?? null) ?? '';
}
$periodo = periodo_valido($periodo) ? $periodo : '7d';
// O Financeiro virou modulo proprio (financeiro.php, na barra lateral): o endereco antigo leva para la
if ($aba === 'financeiro') {
    header('Location: financeiro.php?' . http_build_query(['periodo' => $periodo]));
    exit;
}

$dominios = $db->query('SELECT DISTINCT dominio FROM eventos ORDER BY dominio')->fetchAll(PDO::FETCH_COLUMN);
$dominio = in_array($escolhido('dominio'), $dominios, true) ? $escolhido('dominio') : '';
$paginas = [];
if ($dominio !== '') {
    $st = $db->prepare('SELECT DISTINCT pagina FROM eventos WHERE dominio = ? ORDER BY pagina');
    $st->execute([$dominio]);
    $paginas = $st->fetchAll(PDO::FETCH_COLUMN);
}
// Endereco antigo com a barra no fim ("/drivedeprojetos/") cai na mesma pagina
$pedida = $escolhido('pagina');
$pagina = $pedida !== '' && in_array(pagina_normal($pedida), $paginas, true) ? pagina_normal($pedida) : '';
// Produtos: varios de uma vez. O formulario sempre manda produto[] (mesmo vazio, para dar
// para desmarcar todos); so valem os que ja venderam.
$produtosConhecidos = produtos_conhecidos($db);
$pedidos = array_key_exists('produto', $_GET) ? (array)$_GET['produto'] : ($lembrado['produto'] ?? []);
$produtos = array_values(array_intersect($produtosConhecidos, array_filter($pedidos, 'is_string')));
produto_filtro($produtos);
$filtro = ['dominio' => $dominio, 'pagina' => $pagina, 'periodo' => $periodo, 'produto' => $produtos];
$_SESSION['track_filtro'] = $filtro;
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
// Produtos do filtro do topo (o order bump e outro produto: escolher o principal o tira)
if ($produtos) {
    $marcas = [];
    foreach ($produtos as $i => $p) {
        $marcas[] = ':prod' . $i;
        $parVd[':prod' . $i] = $p;
    }
    $condVd .= ' AND v.produto IN (' . implode(', ', $marcas) . ')';
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
        echo '<p class="suave barra-vendas">Vendas só pelo webhook da Kiwify. Para buscar também pela API (e pegar o que o webhook não entregar), cadastre a chave em <a href="kiwify-api.php">Integrações → Kiwify</a>.</p>';
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
        . botao_atualizar($volta, 'Atualizar vendas: busca agora na API da Kiwify (no máximo uma vez por minuto)')
        . '</div>';
    if ($s['adiada']) {
        echo '<p class="suave">Busca adiada: ' . e($s['adiada']) . '</p>';
    } elseif ($s['erro']) {
        echo '<p class="erro">Última busca na API falhou: ' . e($s['erro']) . '</p>';
    }
}

// Nome do evento em portugues (o t.js manda PageView, CliqueCheckout, WhatsApp e Botao, e VSL)
function nome_evento(string $n): string
{
    return [
        'PageView' => 'Visualização',
        'CliqueCheckout' => 'Clique no checkout',
        'WhatsApp' => 'Clique no WhatsApp',
        'Botao' => 'Clique em botão',
        'BioClique' => 'Clique na Bio',
        'VSL_Play' => 'VSL: Início / Som',
        'VSL_25' => 'VSL: 25% assistido',
        'VSL_50' => 'VSL: 50% assistido',
        'VSL_75' => 'VSL: 75% assistido',
        'VSL_Pitch' => 'VSL: Chegou na Oferta (Pitch)',
        'VSL_100' => 'VSL: 100% concluído',
    ][$n] ?? $n;
}

// O (i) de cada tipo de evento: o que e e quando acontece
function dica_evento(string $n): string
{
    return [
        'PageView' => 'Página aberta: conta cada vez que alguém abre uma página com o t.js do painel.',
        'CliqueCheckout' => 'Clique no botão de compra (classe js-checkout). O link vai para a Kiwify com as etiquetas e o sck, que liga a venda ao visitante.',
        'WhatsApp' => 'Clique no botão ou link do WhatsApp.',
        'Botao' => 'Clique em botão marcado com data-botao que não leva ao checkout (ex.: ver planos, perguntas, rolar até a oferta).',
        'BioClique' => 'Clique num link da página de links da bio (data-bio no link). O detalhe é o nome do link no painel da Bio.',
        'VSL_Play' => 'Visitante clicou no vídeo para ativar o som ou iniciou a reprodução ativa da VSL.',
        'VSL_25' => 'Visitante assistiu pelo menos 25% do vídeo da VSL.',
        'VSL_50' => 'Visitante assistiu pelo menos 50% do vídeo da VSL.',
        'VSL_75' => 'Visitante assistiu pelo menos 75% do vídeo da VSL.',
        'VSL_Pitch' => 'Visitante assistiu até o momento em que a oferta e o preço foram revelados.',
        'VSL_100' => 'Visitante assistiu a VSL até o final.',
    ][$n] ?? 'Evento enviado pela página com o nome ' . $n . '.';
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

$parLink = ['dominio' => $dominio, 'pagina' => $pagina, 'periodo' => $periodo, 'produto' => $produtos];
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
barra_topo($filtro, $dominios, $paginas, $aba, $produtosConhecidos);
echo '<main>';
// Aviso do botao Atualizar (ex.: limite de atualizacoes seguidas). O gestor mostra o dele.
if (!in_array($aba, ['gestor', 'campanha'], true) && ($aviso = aviso_pegar())) {
    echo '<p class="' . ($aviso[1] === 'erro' ? 'erro' : 'aviso-ok') . '">' . e($aviso[0]) . '</p>';
}
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
        echo titulo('Vendas', 'Vendas ligadas a este aparelho pelo sck do checkout.') . '<div class="tabela lista"><table><tr><th>' . com_info('Quando', 'Quando a venda chegou ao painel (horário de Brasília).') . '</th><th>' . com_info('Produto', 'Produto comprado.') . '</th><th>' . com_info('Valor', 'Valor cobrado do comprador (antes das taxas da Kiwify).') . '</th><th>' . com_info('Situação', 'Situação do pagamento na Kiwify.') . '</th><th>' . com_info('Kiwify gravou', 'A campanha que a Kiwify gravou no pedido.') . '</th><th>' . com_info('Conferência', 'Se a campanha da Kiwify bate com a do clique no botão de compra.') . '</th></tr>';
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
    echo titulo('Linha do tempo (' . count($eventos) . ' eventos)', 'Tudo o que este aparelho fez nas páginas, na ordem.') . '<div class="tabela lista"><table><tr><th>' . com_info('Quando', 'Quando aconteceu (horário de Brasília).') . '</th><th>' . com_info('Evento', 'O que a pessoa fez: abriu a página, clicou num botão, no checkout ou no WhatsApp.') . '</th><th>' . com_info('Domínio / página', 'Onde aconteceu.') . '</th><th>' . com_info('Etiquetas', 'As UTMs que a página tinha no endereço naquele momento.') . '</th><th>' . com_info('Veio de', 'O site de onde a pessoa veio (referrer), sem os parâmetros.') . '</th><th>' . com_info('Aparelho', 'Sistema e navegador do aparelho (ex.: iPhone · Instagram).') . '</th></tr>';
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
        $cn = canal($p['utm_source'], $p['utm_medium'], $p['utm_term'], $p['referrer'], $p['utm_campaign']);
        $somar($porCanal, $cn[0], $cn, $soma);
        // Na origem detalhada, o icone e so pela etiqueta (a mesma campanha roda no Instagram e no Facebook)
        $somar($linhas, rotulo_origem($p), canal($p['utm_source'], $p['utm_medium'], null, $p['referrer'], $p['utm_campaign']), $soma);
    }
    uasort($linhas, fn($a, $b) => [$b['vis'], $b['vendas']] <=> [$a['vis'], $a['vendas']]);
    uksort($porCanal, fn($a, $b) => array_search($a, CANAIS_ORDEM, true) <=> array_search($b, CANAIS_ORDEM, true));
    $taxa = fn($n, $d) => $d ? number_format($n * 100 / $d, 1, ',', '') . '%' : '—';

    $tabelaTrafego = function (string $cabecalho, array $grupo, callable $primeiraCelula) use ($temWhats, $taxa): void {
        echo '<div class="tabela"><table><tr><th>' . e($cabecalho) . '</th><th>Visitantes ' . info('Aparelhos diferentes.') . '</th><th>Visualizações ' . info('Páginas abertas.') . '</th>'
            . '<th>Clicaram no checkout ' . info('Visitantes que clicaram no botão de compra.') . '</th>' . ($temWhats ? '<th>' . com_info('Clicaram no WhatsApp', 'Visitantes que clicaram no WhatsApp.') . '</th>' : '')
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
    echo trafego_painel_canais($porCanal, $temWhats);
    if ($porCanal) {
        echo '<details class="ver-tabela"><summary>Ver em tabela</summary>';
        $tabelaTrafego('Canal', $porCanal, fn($k, $l) => selo_canal($l['canal'], false));
        echo '</details>';
    }
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
        echo titulo('Tráfego por página', 'Visitantes e cliques no botão de compra em cada página com o painel.') . '<div class="tabela"><table><tr><th>' . com_info('Página', 'Página com o t.js do painel.') . '</th><th>' . com_info('Visitantes', 'Aparelhos diferentes que abriram a página.') . '</th><th>' . com_info('Visualizações', 'Vezes que a página foi aberta.') . '</th><th>' . com_info('Clicaram no checkout', 'Visitantes que clicaram no botão de compra.') . '</th>' . ($temWhats ? '<th>' . com_info('Clicaram no WhatsApp', 'Visitantes que clicaram no WhatsApp.') . '</th>' : '') . '<th>' . com_info('Taxa de clique no checkout', 'Clicaram no checkout ÷ visitantes.') . '</th></tr>';
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
// ---------------------------------------------------------------- painel editavel
if ($aba === 'painel') {
    painel_render($db, $periodo, $de, $ate);
}

if ($aba === 'geral') {
    resumo_render($db, $periodo, $de, $ate);
}

// ---------------------------------------------------------------- organico
if ($aba === 'organico') {
    organico_render($db, $periodo, $de, $ate);
}

// ---------------------------------------------------------------- gestor de anuncios
if ($aba === 'gestor') {
    gestor_render($db, $periodo, $de, $ate, $parLink);
}

// ---------------------------------------------------------------- analise diaria de uma campanha
if ($aba === 'campanha') {
    gestor_dias_render($db, $periodo);
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

    echo titulo('Conferência das vendas aprovadas', 'Para cada venda aprovada, compara a campanha que o painel viu no clique do botão de compra com a que a Kiwify gravou.') . '<div class="tabela lista"><table><tr><th>' . com_info('Resultado', 'O que deu a comparação entre o clique e a venda.') . '</th><th>' . com_info('Vendas', 'Vendas aprovadas com esse resultado.') . '</th><th>' . com_info('O que significa', 'O que cada resultado quer dizer e o que fazer.') . '</th></tr>';
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
        echo titulo('Como as vendas aprovadas chegaram ao painel', 'Webhook da Kiwify, busca pela API ou os dois. Muita venda só pela API indica webhook com problema.') . '<div class="tabela"><table><tr><th>' . com_info('Caminho', 'Webhook da Kiwify, busca pela API ou os dois.') . '</th><th>' . com_info('Vendas', 'Vendas aprovadas que chegaram por esse caminho.') . '</th><th>' . com_info('O que significa', 'O que cada caminho quer dizer.') . '</th></tr>';
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
        $cn = canal($v['utm_source'], $v['utm_medium'], $v['utm_term'], null, $v['utm_campaign']);
        $fat = $fatVenda[$v['pedido']] ?? (int)$v['valor'];
        $porCanal[$cn[0]] = ['canal' => $cn, 'n' => ($porCanal[$cn[0]]['n'] ?? 0) + 1, 'fat' => ($porCanal[$cn[0]]['fat'] ?? 0) + $fat];
        $k = origem($v['utm_source'], $v['utm_medium'], null) ?: '(sem etiqueta)';
        $porOrigem[$k] = ['canal' => canal($v['utm_source'], $v['utm_medium'], null, null, $v['utm_campaign']), 'n' => ($porOrigem[$k]['n'] ?? 0) + 1, 'fat' => ($porOrigem[$k]['fat'] ?? 0) + $fat];
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
    // Cartoes do periodo (como os do Financeiro): pedidos, aprovadas, faturamento e aguardando
    if (!$soFora) {
        $aprovadas = array_filter($vendas, fn($v) => aprovada($v));
        echo cartoes_kpi([
            cartao_kpi('Pedidos', (string)count($vendas), 'no período, pagos ou não', 'vendas'),
            cartao_kpi('Aprovadas', (string)count(array_filter($aprovadas, fn($v) => !eh_bump($v))), 'vendas, sem contar o order bump', 'ok'),
            cartao_kpi('Faturamento', reais(array_sum(array_map(fn($v) => (int)$v['valor'], $aprovadas))), 'cobrado nas aprovadas, com order bump', 'carteira'),
            cartao_kpi('Aguardando', (string)count(array_filter($vendas, fn($v) => !eh_bump($v) && situacao($v)[0] === 'Aguardando pagamento')), 'Pix ou boleto gerado e não pago', 'calendario'),
        ]);
    }
    echo tabela_card_inicio($soFora ? 'vendas-fora' : 'vendas', $soFora ? 'Vendas fora de anúncio' : 'Vendas', count($vendas), ['icone' => 'vendas', 'classe' => 'lista',
            'busca' => 'Buscar pedido, produto, etiqueta ou visitante',
            'dica' => $soFora ? 'Vendas aprovadas sem o ID de uma campanha da Meta na etiqueta. A UTMify chama de "não trackeadas". Orgânica é normal ficar fora; as outras mostram o que ajustar.'
                : 'Todos os pedidos do período (até 500), pagos ou não, com o canal, as etiquetas e como chegaram. Filtros por produto, situação, canal e caminho.'])
        . '<table><thead><tr><th>' . com_info('Quando', 'Quando a venda chegou ao painel (horário de Brasília).') . '</th><th>' . com_info('Pedido', 'Código do pedido na Kiwify.') . '</th><th data-filtro data-col="produto">' . com_info('Produto', 'Produto comprado. Order bump aparece como pedido próprio.') . '</th><th data-col="valor">' . com_info('Valor', 'Valor cobrado do comprador (antes das taxas da Kiwify).') . '</th><th data-filtro data-col="situacao">' . com_info('Situação', 'Situação do pagamento na Kiwify: aprovada, aguardando, recusada, reembolsada.') . '</th><th data-filtro data-col="canal">Chegou por ' . info('Canal pela etiqueta que a Kiwify gravou: anúncio no Instagram ou no Facebook, orgânico, direto...') . '</th>'
        . '<th data-secundaria data-col="etiquetas">' . com_info('Etiquetas na Kiwify', 'As UTMs que a Kiwify gravou no pedido (source / medium / campaign / content / term).') . '</th><th data-secundaria data-col="visitante">Visitante ' . info('Quem o painel reconheceu na página antes da compra (pelo sck). Clique para ver o caminho.') . '</th>'
        . ($soFora ? '<th data-col="motivo">' . com_info('Por quê', 'Motivo de a venda não estar ligada a uma campanha.') . '</th>' : '<th data-filtro data-col="conferencia">Conferência ' . info('Compara a campanha do clique na página com a que a Kiwify gravou. Bate = origem confirmada.') . '</th>')
        . '<th data-secundaria data-filtro data-col="via">Recebida via ' . info('Como a venda chegou ao painel: webhook da Kiwify, busca pela API ou os dois.') . '</th></tr></thead><tbody>';
    foreach ($vendas as $v) {
        [$sit, $cls] = situacao($v);
        [$via, $vcls] = chegada($v['fonte'] ?? null);
        $vis = $v['visitante']
            ? '<a href="' . e(link_visitante($v['visitante'], $parLink)) . '">' . e(substr($v['visitante'], 0, 8)) . '</a> <span class="suave">' . e($v['dispositivo'] . ' · ' . $v['navegador']) . '</span>'
            : '<span class="suave">—</span>';
        $confValor = '';
        if ($soFora) {
            $confCel = '<span class="suave">' . e(gestor_motivo_fora($v)[1]) . '</span>';
        } elseif (eh_bump($v)) {
            $confCel = '<span class="suave">Order bump: conferido no pedido principal</span>';
            $confValor = 'Order bump';
        } else {
            [$conf, $ccls, $explica] = conferir($db, $v);
            $confCel = '<span class="selo ' . $ccls . '">' . e($conf) . '</span> <span class="suave">' . e($explica) . '</span>';
            $confValor = $conf;
        }
        $cn = canal($v['utm_source'], $v['utm_medium'], $v['utm_term'], null, $v['utm_campaign']);
        echo '<tr><td>' . e(data_local($v['recebida_em'])) . '</td><td><code>' . e($v['referencia'] ?: $v['pedido']) . '</code></td>'
            . '<td data-valor="' . e((string)$v['produto']) . '">' . e($v['produto']) . (eh_bump($v) ? ' <span class="selo neutro">order bump</span>' : '') . '</td><td>' . e(reais($v['valor'])) . '</td>'
            . '<td><span class="selo ' . $cls . '">' . e($sit) . '</span></td><td data-valor="' . e($cn[1]) . '">' . selo_canal($cn) . '</td>'
            . '<td class="suave">' . e(origem($v['utm_source'], $v['utm_medium'], $v['utm_campaign'])) . '</td>'
            . '<td>' . $vis . '</td><td class="quebra"' . ($confValor !== '' ? ' data-valor="' . e($confValor) . '"' : '') . '>' . $confCel . '</td><td><span class="selo ' . $vcls . '">' . e($via) . '</span></td></tr>';
    }
    if (!$vendas) {
        echo '<tr><td colspan="10" class="suave">Nenhuma venda no período. As vendas chegam pelo webhook da Kiwify e, com a chave cadastrada, pela API.</td></tr>';
    }
    echo '</tbody></table>' . tabela_card_fim('pedido', 'pedidos');
}

// ---------------------------------------------------------------- visitantes
if ($aba === 'visitantes') {
    $lista = consulta($db, "SELECT e.visitante, MIN(e.em) AS primeiro, MAX(e.em) AS ultimo, COUNT(*) AS total,
                                   SUM(e.nome = 'CliqueCheckout') AS cliques, SUM(e.nome = 'WhatsApp') AS whats
                            FROM eventos e WHERE $condEv GROUP BY e.visitante ORDER BY ultimo DESC LIMIT 300", $parEv);
    echo tabela_card_inicio('visitantes', 'Visitantes', count($lista), ['icone' => 'visitantes', 'classe' => 'lista', 'busca' => 'Buscar visitante, aparelho ou origem',
            'dica' => 'Cada aparelho que abriu as páginas no período (os 300 mais recentes). Clique no visitante para ver o caminho dele. Filtros por aparelho, canal, checkout e venda.'])
        . '<table><thead><tr><th>' . com_info('Última atividade', 'O último evento deste aparelho no período.') . '</th><th>' . com_info('Visitante', 'Identificador anônimo do aparelho (cookie trk_vid).') . '</th><th data-filtro data-col="aparelho">' . com_info('Aparelho', 'Sistema e navegador do aparelho (ex.: iPhone · Instagram).') . '</th><th data-secundaria data-col="ip">' . com_info('IP', 'IP parcial (sem o último número), só para diferenciar aparelhos.') . '</th><th data-filtro data-col="canal">' . com_info('Chegou por', 'Canal e origem da primeira visita no período.') . '</th><th data-col="eventos">' . com_info('Eventos', 'Quantos eventos este aparelho teve.') . '</th><th data-filtro data-col="checkout">' . com_info('Checkout', 'Se clicou no botão de compra.') . '</th>' . ($temWhats ? '<th data-filtro data-col="whatsapp">' . com_info('WhatsApp', 'Se clicou no WhatsApp.') . '</th>' : '') . '<th data-filtro data-col="venda">' . com_info('Venda', 'Se tem venda ligada a ele (pelo sck do checkout).') . '</th></tr></thead><tbody>';
    foreach ($lista as $l) {
        $st = $db->prepare('SELECT * FROM visitantes WHERE id = ?');
        $st->execute([$l['visitante']]);
        $vis = $st->fetch() ?: [];
        $st = $db->prepare("SELECT * FROM eventos e WHERE e.visitante = :v AND $condEv ORDER BY e.em LIMIT 1");
        $st->execute([':v' => $l['visitante']] + $parEv);
        $primeiro = $st->fetch();
        $chegou = origem($primeiro['utm_source'] ?? null, $primeiro['utm_medium'] ?? null, $primeiro['utm_campaign'] ?? null) ?: ($primeiro['referrer'] ?? '') ?: 'direto / sem origem';
        $temVenda = (int)valor($db, 'SELECT COUNT(*) FROM vendas WHERE visitante = ?', [$l['visitante']]);
        $cn = canal($primeiro['utm_source'] ?? null, $primeiro['utm_medium'] ?? null, $primeiro['utm_term'] ?? null, $primeiro['referrer'] ?? null, $primeiro['utm_campaign'] ?? null);
        $simNao = fn(int $n) => ' data-valor="' . ($n ? 'Sim' : 'Não') . '"';
        echo '<tr><td>' . e(data_local($l['ultimo'])) . '</td><td><a href="' . e(link_visitante($l['visitante'], $parLink)) . '">' . e(substr($l['visitante'], 0, 8)) . '</a></td>'
            . '<td data-valor="' . e((string)($vis['dispositivo'] ?? '')) . '">' . e(($vis['dispositivo'] ?? '') . ' · ' . ($vis['navegador'] ?? '')) . '</td><td>' . e($vis['ip'] ?? '') . '</td>'
            . '<td data-valor="' . e($cn[1]) . '">' . com_icone_canal($cn, $chegou) . '</td>'
            . '<td>' . (int)$l['total'] . '</td><td' . $simNao((int)$l['cliques']) . '>' . ((int)$l['cliques'] ? (int)$l['cliques'] : '') . '</td>' . ($temWhats ? '<td' . $simNao((int)$l['whats']) . '>' . ((int)$l['whats'] ? (int)$l['whats'] : '') . '</td>' : '')
            . '<td' . $simNao($temVenda) . '>' . ($temVenda ? '<span class="selo ok">' . $temVenda . '</span>' : '') . '</td></tr>';
    }
    if (!$lista) {
        echo '<tr><td colspan="9" class="suave">Nenhum visitante no período.</td></tr>';
    }
    echo '</tbody></table>' . tabela_card_fim('visitante', 'visitantes');
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
    echo '<div class="filtro-eventos"><a href="' . e($linkEventos([])) . '" class="' . ($evento === '' && !$soCompradores ? 'atual' : '') . '">Todos <b>' . array_sum($tipos) . '</b>' . info('Todos os eventos do período: visitas, cliques em botões, no checkout e no WhatsApp.') . '</a>';
    foreach ($tipos as $nome => $n) {
        echo '<a href="' . e($linkEventos(['evento' => $nome])) . '" class="' . ($evento === $nome ? 'atual' : '') . '">' . e(nome_evento($nome)) . ' <b>' . $n . '</b>' . info(dica_evento($nome)) . '</a>';
    }
    echo '<a href="' . e($linkEventos(['evento' => 'compraram'])) . '" class="compra' . ($soCompradores ? ' atual' : '') . '">Compraram <b>' . count($compradores) . '</b>' . info('Pessoas deste período com venda aprovada ligada a elas (pelo sck do checkout). Clique para ver o caminho delas até a compra.') . '</a>';
    echo '</div>';

    $de1 = $total ? ($pg - 1) * $porPagina + 1 : 0;
    $titulo = $soCompradores ? 'Eventos de quem comprou' : ($evento !== '' ? nome_evento($evento) : 'Eventos');
    // Tabela inteligente com os 200 eventos desta pagina (busca, filtros e colunas na hora); as
    // paginas de 200 continuam embaixo (Mais recentes, Mais antigos)
    echo tabela_card_inicio('eventos', $titulo . ' (' . $de1 . '–' . (($pg - 1) * $porPagina + count($lista)) . ' de ' . $total . ')', count($lista), ['icone' => 'eventos', 'classe' => 'lista', 'por_pagina' => 0,
            'busca' => 'Buscar página, campanha, origem ou visitante',
            'dica' => 'Cada coisa que alguém fez nas páginas com o painel, da mais nova para a mais antiga, 200 por vez. Filtros por evento, página, canal e aparelho.'])
        . '<table><thead><tr><th>' . com_info('Quando', 'Quando aconteceu (horário de Brasília).') . '</th><th data-filtro data-col="evento">' . com_info('Evento', 'O que a pessoa fez: abriu a página, clicou num botão, no checkout ou no WhatsApp.') . '</th><th data-filtro data-col="pagina">' . com_info('Página', 'Página onde aconteceu.') . '</th><th data-filtro data-col="canal">' . com_info('Canal', 'Canal pela etiqueta do endereço ou pelo site de origem.') . '</th><th data-col="anuncio">' . com_info('Campanha · anúncio · posicionamento', 'Da etiqueta do anúncio, quando a visita veio de anúncio.') . '</th><th data-col="referrer">' . com_info('Veio de', 'O site de onde a pessoa veio (referrer), sem os parâmetros.') . '</th><th data-filtro data-col="aparelho">' . com_info('Aparelho', 'Sistema e navegador do aparelho (ex.: iPhone · Instagram).') . '</th><th data-col="visitante">' . com_info('Visitante', 'Identificador anônimo do aparelho. Clique para ver o caminho dele.') . '</th></tr></thead><tbody>';
    foreach ($lista as $ev) {
        $anuncio = implode(' · ', array_filter([nome_curto($ev['utm_campaign']), nome_curto($ev['utm_content']), $ev['utm_term'] ? str_replace('_', ' ', $ev['utm_term']) : null]));
        $aparelho = implode(' · ', array_filter([$ev['dispositivo'], ($ev['sistema'] ?? '') !== 'Outro' ? $ev['sistema'] : null, $ev['ip']]));
        $cn = canal($ev['utm_source'], $ev['utm_medium'], $ev['utm_term'], $ev['referrer'], $ev['utm_campaign']);
        echo '<tr><td>' . e(data_local($ev['em'])) . '</td><td data-valor="' . e(nome_evento($ev['nome'])) . '"><strong>' . e(nome_evento($ev['nome'])) . '</strong>' . ($ev['detalhe'] ? ' <span class="suave">' . e($ev['detalhe']) . '</span>' : '') . '</td>'
            . '<td>' . e($ev['dominio'] . $ev['pagina']) . '</td>'
            . '<td data-valor="' . e($cn[1]) . '">' . selo_canal($cn) . '</td>'
            . '<td class="quebra">' . ($anuncio !== '' ? e($anuncio) : '<span class="suave">—</span>') . '</td>'
            . '<td>' . e($ev['referrer']) . '</td><td data-valor="' . e((string)$ev['dispositivo']) . '">' . e($aparelho) . '</td>'
            . '<td><a href="' . e(link_visitante($ev['visitante'], $parLink)) . '">' . e(substr($ev['visitante'], 0, 8)) . '</a>'
            . (isset($compradores[$ev['visitante']]) ? ' <span class="selo ok">Comprou</span>' : '') . '</td></tr>';
    }
    if (!$lista) {
        echo '<tr><td colspan="8" class="suave">Nenhum evento no período.</td></tr>';
    }
    echo '</tbody></table>' . tabela_card_fim('evento', 'eventos');
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
