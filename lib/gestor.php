<?php
// Gestor de anuncios: contas, campanhas, conjuntos e anuncios da Meta com gasto, vendas,
// faturamento, lucro, CPA e ROI, no desenho do gestor da UTMify.
//
// Gasto, status e orcamento vem da Meta (lib/meta_sync.php). Vendas vem da Kiwify (webhook e
// API) e se ligam ao anuncio pelo ID que a etiqueta carrega depois do "|" (utm_campaign =
// campanha, utm_medium = conjunto, utm_content = anuncio). So leitura: ligar, pausar e mudar
// orcamento continuam no Gerenciador de Anuncios da Meta (o token do painel so le).
//
// Contas iguais as da UTMify, para os numeros baterem:
//   imposto  = 12,15% do gasto (o que a Meta cobra a mais no Brasil; config meta_imposto_pct)
//   lucro    = faturamento liquido - imposto - gasto
//   ROI      = (faturamento liquido - imposto) / gasto   (acima de 1, a campanha se paga)
//   CPA      = gasto / vendas;  custo por checkout = gasto / inicios de checkout na Meta

require_once __DIR__ . '/meta_sync.php';

const GESTOR_NIVEIS = [
    'contas' => ['Contas', null, null, 'conta'],
    'campanhas' => ['Campanhas', 'campanha_id', 'utm_campaign', 'campaign'],
    'conjuntos' => ['Conjuntos', 'conjunto_id', 'utm_medium', 'adset'],
    'anuncios' => ['Anúncios', 'anuncio_id', 'utm_content', 'ad'],
];

// ID da Meta que a etiqueta carrega depois do "|" ("TL 1|120245..." -> "120245...")
function gestor_id_utm(?string $v): ?string
{
    $partes = explode('|', (string)$v);
    $id = trim((string)end($partes));
    return count($partes) > 1 && preg_match('/^\d{6,25}$/', $id) ? $id : null;
}

// Periodo da tela em dias do fuso (o gasto da Meta vem por dia)
function gestor_dias(string $periodo): array
{
    if ($periodo === 'tudo') {
        return ['0000-01-01', '9999-12-31'];
    }
    [$de, $ate] = periodo_utc($periodo);
    $utc = new DateTimeZone('UTC');
    $d1 = (new DateTime($de, $utc))->setTimezone(fuso());
    $d2 = (new DateTime($ate, $utc))->setTimezone(fuso())->modify('-1 day');
    return [$d1->format('Y-m-d'), $d2->format('Y-m-d')];
}

function gestor_imposto_pct(): float
{
    $p = track_config()['meta_imposto_pct'] ?? 12.15;
    return is_numeric($p) ? max(0.0, min(50.0, (float)$p)) : 12.15;
}

function gestor_status(?array $obj): string
{
    if (!$obj) {
        return '<span class="suave">—</span>';
    }
    $s = (string)$obj['status_efetivo'];
    if ($s === 'ACTIVE') {
        return '<span class="status-meta ativo" title="Ativo na Meta">Ativo</span>';
    }
    if (in_array($s, ['PAUSED', 'CAMPAIGN_PAUSED', 'ADSET_PAUSED', 'ARCHIVED', 'DELETED'], true)) {
        return '<span class="status-meta" title="' . e($s) . '">Pausado</span>';
    }
    return '<span class="status-meta alerta" title="' . e($s) . '">' . e(ucfirst(strtolower(str_replace('_', ' ', $s)))) . '</span>';
}

function gestor_orcamento(?array $obj): string
{
    if (!$obj) {
        return '<span class="suave">—</span>';
    }
    if ($obj['orcamento_diario']) {
        return e(reais((int)$obj['orcamento_diario'])) . '<br><span class="suave">Diário</span>';
    }
    if ($obj['orcamento_total']) {
        return e(reais((int)$obj['orcamento_total'])) . '<br><span class="suave">Total</span>';
    }
    return '<span class="suave">—</span>';
}

function gestor_render(PDO $db, string $periodo, string $de, string $ate, array $parLink): void
{
    $nivel = isset(GESTOR_NIVEIS[$_GET['nivel'] ?? '']) ? $_GET['nivel'] : 'campanhas';
    [$rotuloNivel, $colGasto, $campoUtm, $nivelMeta] = GESTOR_NIVEIS[$nivel];
    $busca = mb_strtolower(texto($_GET['q'] ?? '', 80));
    $stFiltro = in_array($_GET['st'] ?? '', ['ativos', 'pausados'], true) ? $_GET['st'] : '';
    $temMeta = (bool)meta_api_chave();
    $pct = gestor_imposto_pct();
    [$dia1, $dia2] = gestor_dias($periodo);

    // Campanhas, conjuntos e anuncios conhecidos da Meta
    $objetos = [];
    foreach (consulta($db, 'SELECT * FROM meta_objetos', []) as $o) {
        $objetos[$o['id']] = $o;
    }

    // Gasto no periodo, por objeto do nivel
    $linhas = [];
    $nova = fn() => ['gasto' => 0, 'checkouts' => 0, 'vendas' => 0, 'fat' => 0, 'pend' => 0, 'nome_utm' => null];
    $sqlGasto = $colGasto
        ? "SELECT $colGasto AS id, SUM(gasto) AS gasto, SUM(checkouts) AS checkouts FROM meta_gasto WHERE dia >= ? AND dia <= ? GROUP BY $colGasto"
        : "SELECT 'conta' AS id, SUM(gasto) AS gasto, SUM(checkouts) AS checkouts FROM meta_gasto WHERE dia >= ? AND dia <= ?";
    foreach (consulta($db, $sqlGasto, [$dia1, $dia2]) as $g) {
        if ($g['id'] === null || (int)$g['gasto'] + (int)$g['checkouts'] === 0) {
            continue;
        }
        $linhas[$g['id']] = ['gasto' => (int)$g['gasto'], 'checkouts' => (int)$g['checkouts']] + $nova();
    }

    // Vendas no periodo, ligadas pelo ID da etiqueta
    $naoTrackeadas = 0;
    $conhecidas = array_filter($objetos, fn($o) => $o['nivel'] === 'campaign');
    foreach (consulta($db, 'SELECT * FROM vendas WHERE recebida_em >= ? AND recebida_em < ?', [$de, $ate]) as $v) {
        $principal = !eh_bump($v);
        $aprov = aprovada($v);
        $camp = gestor_id_utm($v['utm_campaign']);
        if ($aprov && $principal && (!$camp || ($conhecidas && !isset($conhecidas[$camp])))) {
            $naoTrackeadas++;
        }
        $id = $campoUtm ? gestor_id_utm($v[$campoUtm]) : ($camp ? 'conta' : null);
        if (!$id) {
            continue;
        }
        $l = $linhas[$id] ?? $nova();
        if ($aprov) {
            $l['fat'] += (int)($v['valor_liquido'] ?? $v['valor'] ?? 0);
            $l['vendas'] += $principal ? 1 : 0;
        } elseif ($principal && situacao($v)[0] === 'Aguardando pagamento') {
            $l['pend']++;
        }
        $l['nome_utm'] = $l['nome_utm'] ?? ($campoUtm ? nome_curto($v[$campoUtm]) : null);
        $linhas[$id] = $l;
    }

    // Objetos ativos aparecem mesmo sem gasto no periodo (como na UTMify)
    if ($nivelMeta !== 'conta') {
        foreach ($objetos as $id => $o) {
            if ($o['nivel'] === $nivelMeta && $o['status_efetivo'] === 'ACTIVE' && !isset($linhas[$id])) {
                $linhas[$id] = $nova();
            }
        }
    }

    // Nome, filtros e ordem
    $contaNome = meta_api_chave()['conta_nome'] ?? 'Conta de anúncios';
    $tabela = [];
    foreach ($linhas as $id => $l) {
        $obj = $objetos[$id] ?? null;
        $nome = $nivel === 'contas' ? $contaNome : ($obj['nome'] ?? $l['nome_utm'] ?? (string)$id);
        if ($busca !== '' && mb_strpos(mb_strtolower($nome), $busca) === false) {
            continue;
        }
        $ativo = $obj && $obj['status_efetivo'] === 'ACTIVE';
        if (($stFiltro === 'ativos' && !$ativo) || ($stFiltro === 'pausados' && (!$obj || $ativo))) {
            continue;
        }
        $pai = null;
        if ($obj && $nivel === 'conjuntos') {
            $pai = $objetos[$obj['campanha_id']]['nome'] ?? null;
        } elseif ($obj && $nivel === 'anuncios') {
            $pai = $objetos[$obj['conjunto_id']]['nome'] ?? null;
        }
        $tabela[] = ['id' => (string)$id, 'nome' => $nome, 'pai' => $pai, 'obj' => $nivel === 'contas' ? null : $obj] + $l;
    }
    usort($tabela, fn($a, $b) => [$b['gasto'], $b['fat']] <=> [$a['gasto'], $a['fat']]);

    // Topo: niveis, aviso de nao trackeadas, ultima busca e Atualizar
    $link = fn(array $extra) => './?' . http_build_query(['aba' => 'gestor', 'periodo' => $periodo] + $extra);
    echo '<div class="gestor-niveis">';
    $icones = ['contas' => 'conta', 'campanhas' => 'campanha', 'conjuntos' => 'conjunto', 'anuncios' => 'anuncio'];
    foreach (GESTOR_NIVEIS as $n => [$rot]) {
        echo '<a href="' . e($link(['nivel' => $n])) . '" class="' . ($nivel === $n ? 'atual' : '') . '">' . icone($icones[$n], 18) . e($rot) . '</a>';
    }
    echo '</div>';

    $meta = meta_sync_estado();
    $vencida = meta_sync_vencida() || kiwify_sync_vencida();
    echo '<div class="barra-vendas" id="sync"' . ($vencida ? ' data-sync="1"' : '') . '>';
    if ($naoTrackeadas) {
        echo '<a class="selo alerta" href="' . e('./?' . http_build_query(['aba' => 'vendas', 'periodo' => $periodo])) . '" title="Vendas aprovadas sem o ID de uma campanha da Meta: orgânico, link da bio, link direto ou etiqueta quebrada">'
            . '⚠ ' . $naoTrackeadas . ' venda(s) não trackeada(s)</a>';
    }
    $quando = $meta['ok_em'] ? 'gasto da Meta atualizado em ' . data_local($meta['ok_em'], 'd/m H:i') : ($temMeta ? 'primeira busca na Meta ainda não feita' : '');
    echo '<span class="suave" data-sync-texto>' . e($quando) . '</span>';
    echo '<form method="post" action="sincronizar.php"><input type="hidden" name="csrf" value="' . e(token_csrf()) . '">'
        . '<input type="hidden" name="volta" value="' . e($link(['nivel' => $nivel])) . '"><button type="submit">' . icone('atualizar', 14) . ' Atualizar</button></form></div>';
    if (!$temMeta) {
        echo '<p class="aviso-meta">Sem a conta de anúncios conectada, o gestor mostra só as vendas por campanha. Para ver <strong>gasto, lucro, CPA e ROI</strong>, conecte na aba <a href="meta-api.php">API Meta</a>.</p>';
    } elseif ($meta['adiada']) {
        echo '<p class="suave">Busca na Meta adiada: ' . e($meta['adiada']) . '</p>';
    } elseif ($meta['erro']) {
        echo '<p class="erro">Última busca na Meta falhou: ' . e($meta['erro']) . '</p>';
    }

    // Filtros do nivel
    echo '<form class="gestor-filtros" method="get" action="./"><input type="hidden" name="aba" value="gestor"><input type="hidden" name="periodo" value="' . e($periodo) . '">'
        . '<input type="hidden" name="nivel" value="' . e($nivel) . '">'
        . '<label>Nome<input type="search" name="q" value="' . e($busca) . '" placeholder="Filtrar por nome"></label>'
        . '<label>Status<select name="st"><option value="">Qualquer</option><option value="ativos"' . ($stFiltro === 'ativos' ? ' selected' : '') . '>Ativos</option>'
        . '<option value="pausados"' . ($stFiltro === 'pausados' ? ' selected' : '') . '>Pausados</option></select></label>'
        . '<button type="submit" class="discreto neutro">Filtrar</button></form>';

    // Tabela
    $num = fn(float $v) => number_format($v, 2, ',', '.');
    $cor = fn(float $v) => $v > 0 ? 'positivo' : ($v < 0 ? 'negativo' : '');
    $singular = ['contas' => 'Conta', 'campanhas' => 'Campanha', 'conjuntos' => 'Conjunto', 'anuncios' => 'Anúncio'][$nivel];
    echo '<div class="tabela gestor"><table><tr><th>Status</th><th>' . e($singular) . '</th><th>Orçamento</th><th>Gasto</th><th>Vendas</th>'
        . '<th title="Líquido da Kiwify (depois das taxas), com order bump">Faturamento</th><th title="Faturamento − imposto de ' . e($num($pct)) . '% sobre o gasto − gasto">Lucro</th>'
        . '<th title="Gasto ÷ vendas">CPA</th><th title="(Faturamento − imposto) ÷ gasto. Acima de 1, se paga">ROI</th>'
        . '<th title="Gasto ÷ inícios de checkout que a Meta contou">Custo por checkout</th><th title="Pix gerado e ainda não pago">Pix pendentes</th></tr>';
    $t = ['gasto' => 0, 'checkouts' => 0, 'vendas' => 0, 'fat' => 0, 'pend' => 0];
    foreach ($tabela as $r) {
        foreach ($t as $c => $_) {
            $t[$c] += $r[$c];
        }
        $imposto = (int)round($r['gasto'] * $pct / 100);
        $lucro = $r['fat'] - $imposto - $r['gasto'];
        $roi = $r['gasto'] ? ($r['fat'] - $imposto) / $r['gasto'] : null;
        echo '<tr><td>' . gestor_status($r['obj']) . '</td>'
            . '<td class="quebra"><strong>' . e($r['nome']) . '</strong>' . ($r['pai'] ? '<br><span class="suave">' . e($r['pai']) . '</span>' : '') . '</td>'
            . '<td>' . gestor_orcamento($r['obj']) . '</td>'
            . '<td>' . e(reais($r['gasto'])) . '</td><td>' . $r['vendas'] . '</td><td>' . e(reais($r['fat'])) . '</td>'
            . '<td class="' . $cor($lucro) . '">' . e(reais($lucro)) . '</td>'
            . '<td>' . ($r['vendas'] ? e(reais((int)round($r['gasto'] / $r['vendas']))) : 'N/A') . '</td>'
            . '<td class="' . ($roi === null ? '' : $cor($roi - 1)) . '">' . ($roi === null ? 'N/A' : e($num($roi))) . '</td>'
            . '<td>' . ($r['checkouts'] ? e(reais((int)round($r['gasto'] / $r['checkouts']))) : 'N/A') . '</td>'
            . '<td>' . ($r['pend'] ?: '') . '</td></tr>';
    }
    if ($tabela) {
        $imposto = (int)round($t['gasto'] * $pct / 100);
        $lucro = $t['fat'] - $imposto - $t['gasto'];
        $roi = $t['gasto'] ? ($t['fat'] - $imposto) / $t['gasto'] : null;
        echo '<tr class="total"><td></td><td><strong>' . count($tabela) . ' ' . e(mb_strtolower($rotuloNivel)) . '</strong></td><td></td>'
            . '<td><strong>' . e(reais($t['gasto'])) . '</strong></td><td><strong>' . $t['vendas'] . '</strong></td><td><strong>' . e(reais($t['fat'])) . '</strong></td>'
            . '<td class="' . $cor($lucro) . '"><strong>' . e(reais($lucro)) . '</strong></td>'
            . '<td><strong>' . ($t['vendas'] ? e(reais((int)round($t['gasto'] / $t['vendas']))) : 'N/A') . '</strong></td>'
            . '<td class="' . ($roi === null ? '' : $cor($roi - 1)) . '"><strong>' . ($roi === null ? 'N/A' : e($num($roi))) . '</strong></td>'
            . '<td><strong>' . ($t['checkouts'] ? e(reais((int)round($t['gasto'] / $t['checkouts']))) : 'N/A') . '</strong></td>'
            . '<td><strong>' . ($t['pend'] ?: '') . '</strong></td></tr>';
    } else {
        echo '<tr><td colspan="11" class="suave">Nada no período.' . ($temMeta ? '' : ' Conecte a API Meta para ver o gasto.') . '</td></tr>';
    }
    echo '</table></div>';
    echo '<p class="suave legenda">Contas iguais às da UTMify: faturamento é o líquido da Kiwify (com order bump); lucro desconta o gasto e o imposto de '
        . e($num($pct)) . '% que a Meta cobra sobre ele; ROI acima de 1 quer dizer que a campanha se paga. Ligar, pausar e mudar orçamento: no Gerenciador de Anúncios da Meta (o painel só lê).</p>';
}
