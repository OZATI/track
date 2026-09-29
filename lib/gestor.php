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

// Numeros derivados de uma linha (ou do total), com as contas da UTMify
function gestor_metricas(array $r, float $pct): array
{
    $imposto = (int)round($r['gasto'] * $pct / 100);
    $lucro = $r['fat'] - $imposto - $r['gasto'];
    $div = fn($a, $b) => $b ? $a / $b : null;
    return $r + [
        'imposto' => $imposto,
        'lucro' => $lucro,
        'roi' => $div($r['fat'] - $imposto, $r['gasto']),
        'cpa' => $div($r['gasto'], $r['vendas']),
        'cpi' => $div($r['gasto'], $r['checkouts']),
        'cpv' => $div($r['gasto'], $r['visualizacoes']),
        'cpc' => $div($r['gasto'], $r['cliques']),
        'ctr' => $div($r['cliques'] * 100, $r['impressoes']),
        'cpm' => $div($r['gasto'] * 1000, $r['impressoes']),
        'margem' => $div($lucro * 100, $r['fat']),
    ];
}

// Colunas do gestor: chave => [titulo, explicacao do (i), celula, aparece por padrao]
function gestor_colunas(float $pct): array
{
    $num = fn(float $v, int $c = 2) => number_format($v, $c, ',', '.');
    $din = fn(?float $v) => $v === null ? 'N/A' : e(reais((int)round($v)));
    $cor = fn(?float $v, float $zero = 0) => $v === null ? '' : ($v > $zero ? 'positivo' : ($v < $zero ? 'negativo' : ''));
    $p = $num($pct);
    return [
        'orcamento' => ['Orçamento', 'Orçamento diário ou total definido na Meta (da campanha ou do conjunto).', fn($r) => gestor_orcamento($r['obj']), true],
        'gasto' => ['Gasto', 'Quanto a Meta cobrou no período, sem o imposto.', fn($r) => e(reais($r['gasto'])), true],
        'vendas' => ['Vendas', 'Vendas aprovadas ligadas pelo ID da etiqueta. Order bump não conta como outra venda.', fn($r) => (string)$r['vendas'], true],
        'fat' => ['Faturamento', 'Líquido da Kiwify (depois das taxas) das vendas aprovadas, com order bump.', fn($r) => e(reais($r['fat'])), true],
        'lucro' => ['Lucro', 'Faturamento − gasto − imposto da Meta (' . $p . '%).', fn($r) => '<span class="' . $cor($r['lucro']) . '">' . e(reais($r['lucro'])) . '</span>', true],
        'cpa' => ['CPA', 'Custo por venda: gasto ÷ vendas.', fn($r) => $din($r['cpa']), true],
        'roi' => ['ROI', '(Faturamento − imposto) ÷ gasto. Acima de 1, se paga. Mesma conta da UTMify.', fn($r) => $r['roi'] === null ? 'N/A' : '<span class="' . $cor($r['roi'], 1) . '">' . $num($r['roi']) . '</span>', true],
        'cpi' => ['CPI', 'Custo por início de checkout: gasto ÷ ICs.', fn($r) => $din($r['cpi']), true],
        'ic' => ['IC', 'Inícios de checkout (InitiateCheckout) que a Meta contou.', fn($r) => (string)$r['checkouts'], true],
        'cpv' => ['CPV', 'Custo por visualização da página: gasto ÷ visualizações.', fn($r) => $din($r['cpv']), false],
        'cpc' => ['CPC', 'Custo por clique no link: gasto ÷ cliques.', fn($r) => $din($r['cpc']), true],
        'cliques' => ['Cliques', 'Cliques no link do anúncio.', fn($r) => (string)$r['cliques'], false],
        'ctr' => ['CTR', 'Cliques no link ÷ impressões.', fn($r) => $r['ctr'] === null ? 'N/A' : $num($r['ctr']) . '%', true],
        'impressoes' => ['Impressões', 'Vezes que o anúncio apareceu.', fn($r) => number_format($r['impressoes'], 0, ',', '.'), false],
        'cpm' => ['CPM', 'Custo por mil impressões.', fn($r) => $din($r['cpm']), false],
        'visualizacoes' => ['Vis. de pág.', 'Visualizações da página de destino que a Meta contou (a página carregou).', fn($r) => (string)$r['visualizacoes'], false],
        'margem' => ['Margem', 'Lucro ÷ faturamento.', fn($r) => $r['margem'] === null ? 'N/A' : '<span class="' . $cor($r['margem']) . '">' . $num($r['margem'], 1) . '%</span>', true],
        'imposto' => ['Imposto Meta', 'Impostos que a Meta cobra sobre o gasto no Brasil (' . $p . '%).', fn($r) => e(reais($r['imposto'])), false],
        'pend' => ['Pix pendentes', 'Pix ou boleto gerado e ainda não pago.', fn($r) => $r['pend'] ? (string)$r['pend'] : '', true],
        'reemb_fat' => ['Fat. reembolsado', 'Valor cobrado das vendas reembolsadas ou com chargeback.', fn($r) => $r['reemb_fat'] ? e(reais($r['reemb_fat'])) : '', false],
        'reemb' => ['Vendas reemb.', 'Vendas reembolsadas ou com chargeback.', fn($r) => $r['reemb'] ? (string)$r['reemb'] : '', false],
        'recusadas' => ['Vendas recusadas', 'Pagamentos recusados (cartão).', fn($r) => $r['recusadas'] ? (string)$r['recusadas'] : '', false],
        'id' => ['ID', 'ID da campanha, do conjunto ou do anúncio na Meta.', fn($r) => $r['id'] === 'conta' ? '' : '<code>' . e($r['id']) . '</code>', false],
    ];
}

// Colunas escolhidas: as do formulario (e salva), as salvas, ou as padrao
function gestor_colunas_escolhidas(array $todas): array
{
    if (isset($_GET['cols']) && is_array($_GET['cols'])) {
        $escolha = array_values(array_intersect(array_keys($todas), $_GET['cols']));
        definir_ajuste('gestor_colunas', json_encode($escolha));
        return $escolha;
    }
    $salvas = json_decode((string)ajuste('gestor_colunas'), true);
    if (is_array($salvas) && $salvas) {
        return array_values(array_intersect(array_keys($todas), $salvas));
    }
    return array_keys(array_filter($todas, fn($c) => $c[3]));
}

// Por que uma venda aprovada ficou fora de anuncio (pelo canal da etiqueta)
function gestor_motivo_fora(array $v): array
{
    $cn = canal($v['utm_source'], $v['utm_medium'], $v['utm_term']);
    switch ($cn[0]) {
        case 'organico':
            return [$cn, 'Venda orgânica' . ($cn[3] !== '' ? ' (' . $cn[3] . ')' : '') . ': não veio de anúncio. Normal ficar fora das campanhas.'];
        case 'compartilhado':
            return [$cn, 'Link do anúncio aberto fora da entrega paga (post compartilhado): a Meta não preencheu a campanha.'];
        case 'direto':
            return [$cn, 'Sem etiqueta nenhuma: link direto do checkout, e-mail da Kiwify, outra página ou troca de aparelho.'];
        default:
            return [$cn, 'Etiqueta sem o ID de uma campanha da Meta (ex.: etiqueta manual ou de outra ferramenta).'];
    }
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
    $todas = gestor_colunas($pct);
    $colunas = gestor_colunas_escolhidas($todas);

    // Campanhas, conjuntos e anuncios conhecidos da Meta
    $objetos = [];
    foreach (consulta($db, 'SELECT * FROM meta_objetos', []) as $o) {
        $objetos[$o['id']] = $o;
    }

    // Gasto e numeros da Meta no periodo, por objeto do nivel
    $linhas = [];
    $nova = fn() => ['gasto' => 0, 'checkouts' => 0, 'cliques' => 0, 'impressoes' => 0, 'visualizacoes' => 0, 'vendas' => 0, 'fat' => 0,
        'pend' => 0, 'reemb' => 0, 'reemb_fat' => 0, 'recusadas' => 0, 'nome_utm' => null];
    $campos = 'SUM(gasto) AS gasto, SUM(checkouts) AS checkouts, SUM(cliques) AS cliques, SUM(impressoes) AS impressoes, SUM(visualizacoes) AS visualizacoes';
    $sqlGasto = $colGasto
        ? "SELECT $colGasto AS id, $campos FROM meta_gasto WHERE dia >= ? AND dia <= ? GROUP BY $colGasto"
        : "SELECT 'conta' AS id, $campos FROM meta_gasto WHERE dia >= ? AND dia <= ?";
    foreach (consulta($db, $sqlGasto, [$dia1, $dia2]) as $g) {
        if ($g['id'] === null || (int)$g['gasto'] + (int)$g['impressoes'] === 0) {
            continue;
        }
        $linhas[$g['id']] = array_map('intval', array_intersect_key($g, $nova())) + $nova();
    }

    // Vendas no periodo, ligadas pelo ID da etiqueta; as de fora de anuncio, com o motivo
    $fora = [];
    $conhecidas = array_filter($objetos, fn($o) => $o['nivel'] === 'campaign');
    foreach (consulta($db, 'SELECT * FROM vendas WHERE recebida_em >= ? AND recebida_em < ?', [$de, $ate]) as $v) {
        $principal = !eh_bump($v);
        $aprov = aprovada($v);
        $sit = situacao($v)[0];
        $camp = gestor_id_utm($v['utm_campaign']);
        if ($aprov && $principal && (!$camp || ($conhecidas && !isset($conhecidas[$camp])))) {
            $motivo = gestor_motivo_fora($v)[0];
            $fora[$motivo[1]] = ($fora[$motivo[1]] ?? 0) + 1;
        }
        $id = $campoUtm ? gestor_id_utm($v[$campoUtm]) : ($camp ? 'conta' : null);
        if (!$id) {
            continue;
        }
        $l = $linhas[$id] ?? $nova();
        if ($aprov) {
            $l['fat'] += (int)($v['valor_liquido'] ?? $v['valor'] ?? 0);
            $l['vendas'] += $principal ? 1 : 0;
        } elseif ($principal && $sit === 'Aguardando pagamento') {
            $l['pend']++;
        } elseif (in_array($sit, ['Reembolsada', 'Chargeback'], true)) {
            $l['reemb_fat'] += (int)$v['valor'];
            $l['reemb'] += $principal ? 1 : 0;
        } elseif ($principal && $sit === 'Recusada') {
            $l['recusadas']++;
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
        $tabela[] = gestor_metricas(['id' => (string)$id, 'nome' => $nome, 'pai' => $pai, 'obj' => $nivel === 'contas' ? null : $obj] + $l, $pct);
    }
    usort($tabela, fn($a, $b) => [$b['gasto'], $b['fat']] <=> [$a['gasto'], $a['fat']]);

    // Topo: niveis, vendas fora de anuncio, ultima busca e Atualizar
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
    if ($fora) {
        $detalhe = [];
        foreach ($fora as $rot => $n) {
            $detalhe[] = $n . ' ' . mb_strtolower($rot);
        }
        echo '<a class="selo alerta" href="' . e('./?' . http_build_query(['aba' => 'vendas', 'periodo' => $periodo, 'filtro' => 'fora'])) . '">'
            . array_sum($fora) . ' venda(s) fora de anúncio</a>'
            . info('Vendas aprovadas sem o ID de uma campanha da Meta (a UTMify chama de "não trackeadas"): ' . implode(', ', $detalhe)
                . '. Orgânica é normal ficar fora. Clique para ver cada uma e o motivo.');
    }
    $quando = $meta['ok_em'] ? 'gasto da Meta atualizado em ' . data_local($meta['ok_em'], 'd/m H:i') : ($temMeta ? 'primeira busca na Meta ainda não feita' : '');
    echo '<span data-sync-texto>' . e($quando) . '</span>';
    echo '<form method="post" action="sincronizar.php"><input type="hidden" name="csrf" value="' . e(token_csrf()) . '">'
        . '<input type="hidden" name="volta" value="' . e($link(['nivel' => $nivel])) . '"><button type="submit">Atualizar</button></form></div>';
    if (!$temMeta) {
        echo '<p class="aviso-meta">Sem a conta de anúncios conectada, o gestor mostra só as vendas por campanha. Para ver gasto, lucro, CPA e ROI, conecte na aba <a href="meta-api.php">API Meta</a>.</p>';
    } elseif ($meta['adiada']) {
        echo '<p class="suave">Busca na Meta adiada: ' . e($meta['adiada']) . '</p>';
    } elseif ($meta['erro']) {
        echo '<p class="erro">Última busca na Meta falhou: ' . e($meta['erro']) . '</p>';
    }

    // Filtros do nivel e escolha de colunas
    echo '<form class="gestor-filtros" method="get" action="./"><input type="hidden" name="aba" value="gestor"><input type="hidden" name="periodo" value="' . e($periodo) . '">'
        . '<input type="hidden" name="nivel" value="' . e($nivel) . '">'
        . '<label>Nome<input type="search" name="q" value="' . e($busca) . '" placeholder="Filtrar por nome"></label>'
        . '<label>Status<select name="st"><option value="">Qualquer</option><option value="ativos"' . ($stFiltro === 'ativos' ? ' selected' : '') . '>Ativos</option>'
        . '<option value="pausados"' . ($stFiltro === 'pausados' ? ' selected' : '') . '>Pausados</option></select></label>'
        . '<button type="submit" class="discreto neutro">Filtrar</button>'
        . '<details class="colunas"><summary>Colunas</summary><div>';
    foreach ($todas as $k => [$tit, $dica]) {
        echo '<label title="' . e($dica) . '"><input type="checkbox" name="cols[]" value="' . e($k) . '"' . (in_array($k, $colunas, true) ? ' checked' : '') . '> ' . e($tit) . '</label>';
    }
    echo '<button type="submit">Aplicar</button></div></details></form>';

    // Tabela
    $singular = ['contas' => 'Conta', 'campanhas' => 'Campanha', 'conjuntos' => 'Conjunto', 'anuncios' => 'Anúncio'][$nivel];
    echo '<div class="tabela gestor"><table><tr><th>Status ' . info('Situação na Meta agora: ativo, pausado ou com problema (ex.: reprovado).') . '</th><th>' . e($singular) . '</th>';
    foreach ($colunas as $k) {
        echo '<th>' . com_info($todas[$k][0], $todas[$k][1]) . '</th>';
    }
    echo '</tr>';
    $total = $nova();
    foreach ($tabela as $r) {
        foreach ($total as $c => $v) {
            if (is_int($v)) {
                $total[$c] += $r[$c];
            }
        }
        echo '<tr><td>' . gestor_status($r['obj']) . '</td>'
            . '<td class="quebra"><strong>' . e($r['nome']) . '</strong>' . ($r['pai'] ? '<br><span class="suave">' . e($r['pai']) . '</span>' : '') . '</td>';
        foreach ($colunas as $k) {
            echo '<td>' . $todas[$k][2]($r) . '</td>';
        }
        echo '</tr>';
    }
    if ($tabela) {
        $t = gestor_metricas(['id' => '', 'obj' => null] + $total, $pct);
        echo '<tr class="total"><td></td><td>' . count($tabela) . ' ' . e(mb_strtolower($rotuloNivel)) . '</td>';
        foreach ($colunas as $k) {
            echo '<td>' . ($k === 'orcamento' || $k === 'id' ? '' : $todas[$k][2]($t)) . '</td>';
        }
        echo '</tr>';
    } else {
        echo '<tr><td colspan="' . (2 + count($colunas)) . '" class="suave">Nada no período.' . ($temMeta ? '' : ' Conecte a API Meta para ver o gasto.') . '</td></tr>';
    }
    echo '</table></div>';
    echo '<p class="suave legenda">Faturamento líquido da Kiwify, com order bump. Lucro = faturamento − gasto − imposto da Meta (' . e(number_format($pct, 2, ',', '.')) . '%). '
        . 'Para ligar, pausar ou mudar orçamento, use o Gerenciador de Anúncios da Meta.</p>';
}
