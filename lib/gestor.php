<?php
// Gestor de anuncios: contas, campanhas, conjuntos e anuncios da Meta com gasto, vendas,
// faturamento, lucro, CPA e ROI, no desenho do gestor da UTMify.
//
// Gasto, status e orcamento vem da Meta (lib/meta_sync.php). Vendas vem da Kiwify (webhook e
// API) e se ligam ao anuncio pelo ID que a etiqueta carrega depois do "|" (utm_campaign =
// campanha, utm_medium = conjunto, utm_content = anuncio). Com um token que tambem gerencia
// (ads_management), a chave de status liga e pausa na Meta (lib/gestor_editar.php).
//
// Contas iguais as da UTMify e as da planilha de campanhas, para os numeros baterem:
//   imposto  = 12,15% do gasto (o que a Meta cobra a mais no Brasil; config meta_imposto_pct)
//   lucro    = faturamento liquido - imposto - gasto
//   ROI      = (faturamento liquido - imposto) / gasto   (acima de 1, a campanha se paga)
//   margem   = lucro / (faturamento liquido - imposto)
//   CPA      = gasto / vendas;  custo por checkout = gasto / inicios de checkout na Meta
// O historico dia a dia (vista "Dia a dia", lib/gestor_dias.php) usa as mesmas contas.

require_once __DIR__ . '/meta_sync.php';
require_once __DIR__ . '/gestor_editar.php';
require_once __DIR__ . '/gestor_ranking.php';
require_once __DIR__ . '/gestor_dias.php';

// O (i) de cada nivel do gestor
const GESTOR_NIVEIS_DICA = [
    'contas' => 'A conta de anúncios da Meta inteira: o total de tudo o que está rodando.',
    'campanhas' => 'Cada campanha: o objetivo e, quando é ela que controla, o orçamento. As vendas se ligam pelo ID que a etiqueta traz no utm_campaign.',
    'conjuntos' => 'Cada conjunto de anúncios: público, posicionamento e orçamento. Ligado pelo ID no utm_medium.',
    'anuncios' => 'Cada anúncio: o criativo que a pessoa viu. Ligado pelo ID no utm_content.',
];

// Campos do video no meta_gasto (somam de um dia para o outro)
const GESTOR_VIDEO = ['plays', 'video_3s', 'thruplay', 'p25', 'p50', 'p75', 'p100'];

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
    return periodo_dias($periodo);
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

// Chave de status: liga ou pausa na Meta (meta-status.php), com confirmacao. Sem permissao de
// edicao, a chave aparece sem clique. Status efetivo diferente (ex.: pausado pela campanha,
// em analise) aparece ao lado.
function gestor_chave(?array $obj, string $volta, bool $pode): string
{
    if (!$obj || !isset(GESTOR_NIVEL_META[$obj['nivel']]) || !in_array($obj['status'], ['ACTIVE', 'PAUSED'], true)) {
        return gestor_status($obj);
    }
    $ligado = $obj['status'] === 'ACTIVE';
    $acao = ($ligado ? 'Pausar ' : 'Ligar ') . GESTOR_NIVEL_META[$obj['nivel']] . ' "' . $obj['nome'] . '" na Meta?';
    $titulo = $pode ? ($ligado ? 'Ligado na Meta. Clique para pausar.' : 'Pausado na Meta. Clique para ligar.')
        : 'O token da API Meta só lê: para ligar e pausar por aqui, gere um token com ads_management.';
    $html = '<form method="post" action="meta-status.php" class="chave-form" data-confirma="' . e($acao) . '">'
        . '<input type="hidden" name="csrf" value="' . e(token_csrf()) . '"><input type="hidden" name="id" value="' . e($obj['id']) . '">'
        . '<input type="hidden" name="status" value="' . ($ligado ? 'PAUSED' : 'ACTIVE') . '"><input type="hidden" name="volta" value="' . e($volta) . '">'
        . '<button type="submit" class="chave' . ($ligado ? ' ligada' : '') . '" role="switch" aria-checked="' . ($ligado ? 'true' : 'false') . '"'
        . ' aria-label="' . e($ligado ? 'Pausar' : 'Ligar') . '" title="' . e($titulo) . '"' . ($pode ? '' : ' disabled') . '><span></span></button></form>';
    if ($obj['status_efetivo'] !== $obj['status']) {
        $html .= gestor_status($obj);
    }
    return $html;
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
        'taxas' => max(0, ($r['fat_bruto'] ?? 0) - $r['fat']),
        // Conta da UTMify e da planilha (a mesma do ROI do Resumo)
        'roi' => roi_campanha($r['fat'], $r['gasto'], $imposto),
        'roas' => $div($r['fat'], $r['gasto']),
        'cpa' => $div($r['gasto'], $r['vendas']),
        'cpi' => $div($r['gasto'], $r['checkouts']),
        'cpv' => $div($r['gasto'], $r['visualizacoes']),
        'cpc' => $div($r['gasto'], $r['cliques']),
        'ctr' => $div($r['cliques'] * 100, $r['impressoes']),
        'cpm' => $div($r['gasto'] * 1000, $r['impressoes']),
        'margem' => margem_pct($r['fat'], $lucro, $imposto),
    ] + gestor_metricas_video($r);
}

// Video e alcance: hook rate = 3 s ÷ impressoes; hold rate = ThruPlay ÷ 3 s; retencao = quem
// chegou a 25/50/75/100% ÷ quem deu play; frequencia = impressoes ÷ alcance (so por dia ou com
// o alcance do periodo, da Meta). Anuncio sem video fica sem esses numeros (N/A), nao com 0%.
function gestor_metricas_video(array $r): array
{
    $plays = (int)($r['plays'] ?? 0);
    $tres = (int)($r['video_3s'] ?? 0);
    $video = $plays + $tres > 0;
    $pct = fn(int $a, int $b) => $video && $b ? $a * 100 / $b : null;
    return [
        'hook' => $pct($tres, (int)$r['impressoes']),
        'hold' => $pct((int)($r['thruplay'] ?? 0), $tres),
        'ret25' => $pct((int)($r['p25'] ?? 0), $plays),
        'ret50' => $pct((int)($r['p50'] ?? 0), $plays),
        'ret75' => $pct((int)($r['p75'] ?? 0), $plays),
        'ret100' => $pct((int)($r['p100'] ?? 0), $plays),
        'frequencia' => !empty($r['alcance']) ? $r['impressoes'] / $r['alcance'] : null,
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
        'fat_bruto' => ['Fat. bruto', 'Valor cobrado do comprador nas vendas aprovadas, antes das taxas da Kiwify, com order bump.', fn($r) => e(reais($r['fat_bruto'])), false],
        'taxas' => ['Taxas Kiwify', 'Faturamento bruto − faturamento líquido: o que a Kiwify ficou de taxa nas vendas aprovadas.', fn($r) => e(reais($r['taxas'])), false],
        'lucro' => ['Lucro', 'Faturamento − gasto − imposto da Meta (' . $p . '%).', fn($r) => '<span class="' . $cor($r['lucro']) . '">' . e(reais($r['lucro'])) . '</span>', true],
        'cpa' => ['CPA', 'Custo por venda: gasto ÷ vendas.', fn($r) => $din($r['cpa']), true],
        'roi' => ['ROI', '(Faturamento − imposto da Meta) ÷ gasto: quanto voltou para cada real gasto. É a conta da UTMify e da planilha de campanhas. Vermelho abaixo de 1 (não se paga), laranja de 1 até 2, verde de 2 para cima.', fn($r) => $r['roi'] === null ? 'N/A' : '<span class="' . cor_roi($r['roi']) . '">' . $num($r['roi']) . '</span>', true],
        'roas' => ['ROAS', 'Faturamento ÷ gasto, sem o imposto. É o retorno sobre o gasto que a Meta mostra.', fn($r) => $r['roas'] === null ? 'N/A' : '<span class="' . $cor($r['roas'], 1) . '">' . $num($r['roas']) . '</span>', false],
        'cpi' => ['Custo por IC', 'Custo por início de checkout (finalização de compra iniciada): gasto ÷ ICs.', fn($r) => $din($r['cpi']), true],
        'ic' => ['IC', 'Inícios de checkout (finalização de compra iniciada) que a Meta contou.', fn($r) => (string)$r['checkouts'], true],
        'cpv' => ['Custo por vis. de página', 'Custo por visualização da página de destino: gasto ÷ visualizações.', fn($r) => $din($r['cpv']), false],
        'cpc' => ['CPC', 'Custo por clique no link: gasto ÷ cliques.', fn($r) => $din($r['cpc']), true],
        'cliques' => ['Cliques', 'Cliques no link do anúncio.', fn($r) => (string)$r['cliques'], false],
        'ctr' => ['CTR', 'Cliques no link ÷ impressões.', fn($r) => $r['ctr'] === null ? 'N/A' : $num($r['ctr']) . '%', true],
        'impressoes' => ['Impressões', 'Vezes que o anúncio apareceu.', fn($r) => number_format($r['impressoes'], 0, ',', '.'), false],
        'cpm' => ['CPM', 'Custo por mil impressões.', fn($r) => $din($r['cpm']), false],
        'visualizacoes' => ['Vis. de pág.', 'Visualizações da página de destino que a Meta contou (a página carregou).', fn($r) => (string)$r['visualizacoes'], false],
        'margem' => ['Margem', 'Lucro ÷ (faturamento − imposto da Meta), como na UTMify e na planilha: quanto de cada real que voltou sobrou.', fn($r) => $r['margem'] === null ? 'N/A' : '<span class="' . $cor($r['margem']) . '">' . $num($r['margem'], 1) . '%</span>', true],
        'hook' => ['Hook rate', 'Visualizações de 3 segundos ÷ impressões: quantos pararam no vídeo. De 30% para cima, o começo do vídeo segura. Anúncio sem vídeo fica N/A.', fn($r) => $r['hook'] === null ? 'N/A' : $num($r['hook'], 1) . '%', false],
        'hold' => ['Hold rate', 'ThruPlay ÷ visualizações de 3 segundos: dos que pararam, quantos assistiram 15 segundos (ou o vídeo inteiro, se for mais curto).', fn($r) => $r['hold'] === null ? 'N/A' : $num($r['hold'], 1) . '%', false],
        'video_3s' => ['Vis. de 3 s', 'Visualizações de 3 segundos ou mais do vídeo.', fn($r) => number_format((int)$r['video_3s'], 0, ',', '.'), false],
        'thruplay' => ['ThruPlay', 'Visualizações de 15 segundos (ou do vídeo inteiro, se for mais curto).', fn($r) => number_format((int)$r['thruplay'], 0, ',', '.'), false],
        'ret25' => ['Retenção 25%', 'Quem chegou a 25% do vídeo ÷ quem deu play.', fn($r) => $r['ret25'] === null ? 'N/A' : $num($r['ret25'], 1) . '%', false],
        'ret50' => ['Retenção 50%', 'Quem chegou à metade do vídeo ÷ quem deu play.', fn($r) => $r['ret50'] === null ? 'N/A' : $num($r['ret50'], 1) . '%', false],
        'ret75' => ['Retenção 75%', 'Quem chegou a 75% do vídeo ÷ quem deu play.', fn($r) => $r['ret75'] === null ? 'N/A' : $num($r['ret75'], 1) . '%', false],
        'ret100' => ['Retenção 100%', 'Quem assistiu o vídeo até o fim ÷ quem deu play.', fn($r) => $r['ret100'] === null ? 'N/A' : $num($r['ret100'], 1) . '%', false],
        'imposto' => ['Imposto Meta', 'Impostos que a Meta cobra sobre o gasto no Brasil (' . $p . '%).', fn($r) => e(reais($r['imposto'])), false],
        'pend' => ['Pix pendentes', 'Pix ou boleto gerado e ainda não pago.', fn($r) => $r['pend'] ? (string)$r['pend'] : '', true],
        'reemb_fat' => ['Fat. reembolsado', 'Valor cobrado das vendas reembolsadas ou com chargeback.', fn($r) => $r['reemb_fat'] ? e(reais($r['reemb_fat'])) : '', false],
        'reemb' => ['Vendas reemb.', 'Vendas reembolsadas ou com chargeback.', fn($r) => $r['reemb'] ? (string)$r['reemb'] : '', false],
        'recusadas' => ['Vendas recusadas', 'Pagamentos recusados (cartão).', fn($r) => $r['recusadas'] ? (string)$r['recusadas'] : '', false],
        'id' => ['ID', 'ID da campanha, do conjunto ou do anúncio na Meta.', fn($r) => $r['id'] === 'conta' ? '' : '<code>' . e($r['id']) . '</code>', false],
    ];
}

// Colunas escolhidas: as do formulario (e salva), as salvas, ou as padrao. $chave: onde fica
// guardada (o gestor tem a sua e a analise diaria, uma por nivel); $padrao: a lista padrao, se
// nao for a das colunas marcadas como padrao
function gestor_colunas_escolhidas(array $todas, string $chave = 'gestor_colunas', ?array $padrao = null): array
{
    // Na ordem escolhida (o seletor de colunas manda na ordem da lista da direita)
    $validas = fn(array $v) => array_values(array_unique(array_filter($v, fn($k) => is_string($k) && isset($todas[$k]))));
    if (($_GET['cols'] ?? null) === 'padrao') {
        definir_ajuste($chave, null);
    } elseif (isset($_GET['cols']) && is_array($_GET['cols'])) {
        $escolha = $validas($_GET['cols']);
        if ($escolha) {
            definir_ajuste($chave, json_encode($escolha));
            return $escolha;
        }
    }
    $salvas = json_decode((string)ajuste($chave), true);
    if (is_array($salvas) && ($salvas = $validas($salvas))) {
        return $salvas;
    }
    return $padrao !== null ? $validas($padrao) : array_keys(array_filter($todas, fn($c) => $c[3]));
}

// Modelos de colunas (botoes no seletor): o que olhar em cada nivel. Campanha: as 17 colunas da
// aba CAMPANHAS da planilha, na ordem dela. Conjunto (publico): alcance, frequencia e custo de
// alcancar e converter. Criativo (anuncio): o video (hook, hold, retencao), o clique e o que a
// pagina faz com ele. Coluna que nao existe na tela (alcance no gestor) fica fora do modelo.
const GESTOR_MODELOS = [
    'campanha' => ['Campanha', ['orcamento', 'gasto', 'vendas', 'fat', 'lucro', 'cpa', 'roi', 'cpi', 'ic', 'cpv', 'cpc', 'cliques', 'ctr', 'impressoes', 'cpm', 'visualizacoes', 'margem']],
    'conjunto' => ['Conjunto', ['orcamento', 'gasto', 'vendas', 'fat', 'lucro', 'cpa', 'roi', 'alcance', 'frequencia', 'impressoes', 'cpm', 'ctr', 'cpc', 'cliques', 'ic', 'cpi', 'visualizacoes']],
    'criativo' => ['Criativo', ['gasto', 'vendas', 'fat', 'roi', 'cpa', 'hook', 'hold', 'ret50', 'ret100', 'ctr', 'cpc', 'cliques', 'ic', 'cpi', 'visualizacoes', 'cpv', 'cpm']],
];

// Seletor de colunas (como o da UTMify), numa janela no meio da tela: a esquerda todas, com
// busca; a direita as escolhidas, na ordem da tabela; em cima, os modelos. Sem JavaScript, as
// caixas da esquerda ja funcionam. $campos: os campos que o formulario repete (nome => valor ou
// lista de valores); $fixa: a primeira coluna, que nao sai (Campanha, Dia...)
function gestor_colunas_seletor(array $todas, array $colunas, string $fixa, array $campos, string $hrefPadrao): string
{
    $h = '<details class="colunas" id="colunas"><summary title="Colunas: escolher o que aparece na tabela">' . icone('colunas', 14) . '<span>Colunas</span></summary><form class="colunas-painel" method="get" action="./" data-colunas>';
    foreach ($campos as $nome => $valor) {
        foreach (is_array($valor) ? $valor : [$valor] as $v) {
            $h .= '<input type="hidden" name="' . e($nome) . (is_array($valor) ? '[]' : '') . '" value="' . e((string)$v) . '">';
        }
    }
    $h .= '<div class="colunas-cab"><strong>Personalize as colunas</strong><span class="suave">Marque as colunas e arraste para mudar a ordem, ou comece por um modelo.</span>'
        . '<div class="colunas-modelos"><span class="suave">' . com_info('Modelos', 'Campanha: as 17 colunas da planilha de campanhas, na ordem dela. Conjunto (público): alcance, frequência e o custo de alcançar e de converter o público. Criativo (anúncio): o vídeo (hook rate, hold rate e retenção), o clique e o que a página faz com ele. O modelo só monta a lista: dá para ajustar antes de salvar.') . '</span>';
    foreach (GESTOR_MODELOS as [$rot, $lista]) {
        $lista = array_values(array_filter($lista, fn($k) => isset($todas[$k])));
        $h .= '<button type="button" class="chip" data-modelo="' . e((string)json_encode($lista)) . '" aria-pressed="' . ($lista === $colunas ? 'true' : 'false') . '">' . e($rot) . '</button>';
    }
    $h .= '</div></div><div class="colunas-lista"><input type="search" placeholder="Buscar coluna" aria-label="Buscar coluna" data-colunas-busca><div data-colunas-todas>';
    foreach ($todas as $k => [$tit, $dica]) {
        $h .= '<label data-coluna="' . e($k) . '"><input type="checkbox" name="cols[]" value="' . e($k) . '"' . (in_array($k, $colunas, true) ? ' checked' : '') . '>'
            . '<span><b>' . e($tit) . '</b><small>' . e($dica) . '</small></span></label>';
    }
    $h .= '</div></div><div class="colunas-escolhidas"><div class="colunas-fixa">' . e($fixa) . '</div><ol data-colunas-ordem>';
    foreach ($colunas as $k) {
        $h .= '<li draggable="true" data-coluna="' . e($k) . '"><span class="alca" aria-hidden="true">☰</span><span>' . e($todas[$k][0]) . '</span>'
            . '<button type="button" class="discreto neutro" data-sobe aria-label="Subir">↑</button><button type="button" class="discreto neutro" data-desce aria-label="Descer">↓</button>'
            . '<button type="button" class="discreto" data-tira aria-label="Tirar">×</button></li>';
    }
    return $h . '</ol></div><div class="colunas-pe"><a href="' . e($hrefPadrao) . '">Voltar ao padrão</a>'
        . '<button type="button" class="discreto neutro" data-colunas-cancela>Cancelar</button><button type="submit">Salvar</button></div></form></details>';
}

// Por que uma venda aprovada ficou fora de anuncio (pelo canal da etiqueta)
function gestor_motivo_fora(array $v): array
{
    $cn = canal($v['utm_source'], $v['utm_medium'], $v['utm_term'], null, $v['utm_campaign']);
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


function gestor_linha_nova(): array
{
    return ['gasto' => 0, 'checkouts' => 0, 'cliques' => 0, 'impressoes' => 0, 'visualizacoes' => 0, 'vendas' => 0, 'fat' => 0, 'fat_bruto' => 0,
        'pend' => 0, 'reemb' => 0, 'reemb_fat' => 0, 'recusadas' => 0, 'nome_utm' => null, 'pai_camp' => null, 'pai_conj' => null]
        + array_fill_keys(GESTOR_VIDEO, 0) + ['alcance' => 0];
}

// Soma uma venda na linha: aprovada entra no faturamento (liquido e bruto) e, se nao for order
// bump, conta como venda; Pix pendente, reembolso e recusa vao para as colunas deles
function gestor_somar_venda(array &$l, array $v): void
{
    $principal = !eh_bump($v);
    $sit = situacao($v)[0];
    if ($sit === 'Aprovada') {
        $l['fat'] += (int)($v['valor_liquido'] ?? $v['valor'] ?? 0);
        $l['fat_bruto'] += (int)($v['valor'] ?? 0);
        $l['vendas'] += $principal ? 1 : 0;
    } elseif ($principal && $sit === 'Aguardando pagamento') {
        $l['pend']++;
    } elseif (in_array($sit, ['Reembolsada', 'Chargeback'], true)) {
        $l['reemb_fat'] += (int)$v['valor'];
        $l['reemb'] += $principal ? 1 : 0;
    } elseif ($principal && $sit === 'Recusada') {
        $l['recusadas']++;
    }
}

// Soma gasto (Meta) e vendas (Kiwify) por objeto do nivel num periodo. Guarda tambem a
// campanha e o conjunto de cada linha (para abrir campanha -> conjuntos -> anuncios).
// Devolve [linhas por id, vendas fora de anuncio por motivo].
function gestor_agregar(PDO $db, string $nivel, string $dia1, string $dia2, string $de, string $ate, array $conhecidas): array
{
    [, $colGasto, $campoUtm] = GESTOR_NIVEIS[$nivel];
    $linhas = [];
    $campos = 'SUM(gasto) AS gasto, SUM(checkouts) AS checkouts, SUM(cliques) AS cliques, SUM(impressoes) AS impressoes, SUM(visualizacoes) AS visualizacoes, '
        . implode(', ', array_map(fn($c) => "SUM($c) AS $c", GESTOR_VIDEO));
    $pais = ['conjunto_id' => ', MAX(campanha_id) AS pai_camp', 'anuncio_id' => ', MAX(campanha_id) AS pai_camp, MAX(conjunto_id) AS pai_conj'][$colGasto] ?? '';
    $sql = $colGasto
        ? "SELECT $colGasto AS id, $campos $pais FROM meta_gasto WHERE dia >= ? AND dia <= ? GROUP BY $colGasto"
        : "SELECT 'conta' AS id, $campos FROM meta_gasto WHERE dia >= ? AND dia <= ?";
    foreach (consulta($db, $sql, [$dia1, $dia2]) as $g) {
        if ($g['id'] === null || (int)$g['gasto'] + (int)$g['impressoes'] === 0) {
            continue;
        }
        $l = gestor_linha_nova();
        foreach (array_merge(['gasto', 'checkouts', 'cliques', 'impressoes', 'visualizacoes'], GESTOR_VIDEO) as $c) {
            $l[$c] = (int)$g[$c];
        }
        $l['pai_camp'] = $g['pai_camp'] ?? null;
        $l['pai_conj'] = $g['pai_conj'] ?? null;
        $linhas[$g['id']] = $l;
    }

    $fora = [];
    foreach (consulta($db, 'SELECT * FROM vendas WHERE recebida_em >= ? AND recebida_em < ?', [$de, $ate]) as $v) {
        if (!venda_no_filtro($v)) {
            continue;
        }
        $camp = gestor_id_utm($v['utm_campaign']);
        if (aprovada($v) && !eh_bump($v) && (!$camp || ($conhecidas && !isset($conhecidas[$camp])))) {
            $motivo = gestor_motivo_fora($v)[0];
            $fora[$motivo[1]] = ($fora[$motivo[1]] ?? 0) + 1;
        }
        $id = $campoUtm ? gestor_id_utm($v[$campoUtm]) : ($camp ? 'conta' : null);
        if (!$id) {
            continue;
        }
        $l = $linhas[$id] ?? gestor_linha_nova();
        gestor_somar_venda($l, $v);
        $l['nome_utm'] = $l['nome_utm'] ?? ($campoUtm ? nome_curto($v[$campoUtm]) : null);
        $l['pai_camp'] = $l['pai_camp'] ?? $camp;
        $l['pai_conj'] = $l['pai_conj'] ?? gestor_id_utm($v['utm_medium']);
        $linhas[$id] = $l;
    }
    return [$linhas, $fora];
}

// Periodo anterior do mesmo tamanho, para comparar: [dia1, dia2, de UTC, ate UTC] ou null.
// "Hoje" nao compara: o dia ainda esta pela metade e o gasto por anuncio vem por dia inteiro.
function gestor_periodo_anterior(string $periodo): ?array
{
    if (!in_array($periodo, ['ontem', '7d', '30d'], true)) {
        return null;
    }
    [$de] = periodo_utc($periodo);
    [$d1, $d2] = gestor_dias($periodo);
    $dias = (int)(new DateTime($d1))->diff(new DateTime($d2))->days + 1;
    $utc = new DateTimeZone('UTC');
    return [
        (new DateTime($d1))->modify("-$dias days")->format('Y-m-d'),
        (new DateTime($d1))->modify('-1 day')->format('Y-m-d'),
        (new DateTime($de, $utc))->modify("-$dias days")->format('Y-m-d H:i:s'),
        $de,
    ];
}

// Seta de variacao contra o periodo de comparacao. $melhorMenor: custos (subir e ruim);
// $neutro: gasto e impressoes (nem bom nem ruim). $antesTxt vai na dica ao passar o mouse.
// Antes zero: nao da para calcular %, entao mostra "de 0".
function gestor_delta(?float $atual, ?float $antes, bool $melhorMenor = false, bool $neutro = false, string $antesTxt = ''): string
{
    if ($atual === null || $antes === null) {
        return '';
    }
    $dica = ' title="' . e('Antes: ' . ($antesTxt !== '' ? $antesTxt : number_format($antes, 2, ',', '.'))) . '"';
    $classe = fn(bool $subiu) => $neutro ? '' : (($melhorMenor ? !$subiu : $subiu) ? 'bom' : 'ruim');
    // Antes era zero: nao ha % para mostrar. "era 0" diz o que aconteceu (o "de 0" confundia, QA UX-03)
    if (abs($antes) < 0.0001) {
        if (abs($atual) < 0.0001) {
            return '';
        }
        return '<small class="delta ' . $classe($atual > 0) . '"' . $dica . '>' . ($atual > 0 ? '▲' : '▼') . ' era 0</small>';
    }
    $pct = ($atual - $antes) * 100 / abs($antes);
    if (abs($pct) < 0.5) {
        return '<small class="delta"' . $dica . '>=</small>';
    }
    return '<small class="delta ' . $classe($pct > 0) . '"' . $dica . '>' . ($pct > 0 ? '▲' : '▼') . ' ' . number_format(abs($pct), 0, ',', '.') . '%</small>';
}

// Valor de ordenacao de uma linha por coluna (null vai para o fim)
function gestor_valor_ordem(array $r, string $col)
{
    if ($col === 'nome') {
        return mb_strtolower($r['nome']);
    }
    if ($col === 'orcamento') {
        return $r['obj'] ? ($r['obj']['orcamento_diario'] ?: $r['obj']['orcamento_total']) : null;
    }
    $campo = ['ic' => 'checkouts'][$col] ?? $col;
    return $r[$campo] ?? null;
}

function gestor_render(PDO $db, string $periodo, string $de, string $ate, array $parLink): void
{
    $nivel = isset(GESTOR_NIVEIS[$_GET['nivel'] ?? '']) ? $_GET['nivel'] : 'campanhas';
    [$rotuloNivel, , , $nivelMeta] = GESTOR_NIVEIS[$nivel];
    $busca = mb_strtolower(texto($_GET['q'] ?? '', 80));
    $stFiltro = in_array($_GET['st'] ?? '', ['ativos', 'pausados'], true) ? $_GET['st'] : '';
    $idOk = fn($v) => is_string($v) && preg_match('/^\d{3,25}$/', $v) ? $v : '';
    $fCamp = in_array($nivel, ['conjuntos', 'anuncios'], true) ? $idOk($_GET['campanha'] ?? '') : '';
    $fConj = $nivel === 'anuncios' ? $idOk($_GET['conjunto'] ?? '') : '';
    // Selecao (caixas de marcar): as campanhas marcadas filtram conjuntos e anuncios; os
    // conjuntos marcados filtram os anuncios. O caminho aberto pelo nome tem prioridade.
    $lista = fn($v) => array_values(array_unique(array_filter(array_map($idOk, is_array($v) ? array_slice($v, 0, 100) : []))));
    $selCamp = $lista($_GET['campanhas'] ?? []);
    $selConj = $lista($_GET['conjuntos'] ?? []);
    $filtroCamp = $fCamp !== '' ? [$fCamp] : ($nivel !== 'campanhas' && $nivel !== 'contas' ? $selCamp : []);
    $filtroConj = $fConj !== '' ? [$fConj] : ($nivel === 'anuncios' ? $selConj : []);
    $pode = gestor_pode_editar();
    $temMeta = (bool)meta_api_chave();
    $pct = gestor_imposto_pct();
    [$dia1, $dia2] = gestor_dias($periodo);
    $todas = gestor_colunas($pct);
    $colunas = gestor_colunas_escolhidas($todas);
    $ordem = ($_GET['ordem'] ?? '') === 'nome' || isset($todas[$_GET['ordem'] ?? '']) ? $_GET['ordem'] : 'gasto';
    $dir = ($_GET['dir'] ?? '') === 'asc' ? 'asc' : 'desc';

    // Campanhas, conjuntos e anuncios conhecidos da Meta
    $objetos = [];
    foreach (consulta($db, 'SELECT * FROM meta_objetos', []) as $o) {
        $objetos[$o['id']] = $o;
    }
    $conhecidas = array_filter($objetos, fn($o) => $o['nivel'] === 'campaign');

    [$linhas, $fora] = gestor_agregar($db, $nivel, $dia1, $dia2, $de, $ate, $conhecidas);
    $modoComp = gestor_comparar_modo($periodo);
    $anterior = gestor_periodo_comparacao($periodo, $modoComp);
    $antes = $anterior ? gestor_agregar($db, $nivel, $anterior[0], $anterior[1], $anterior[2], $anterior[3], $conhecidas)[0] : [];
    $rotuloComp = $anterior ? GESTOR_COMPARAR[$modoComp][1] . ' (' . (new DateTime($anterior[0]))->format('d/m') . ' a ' . (new DateTime($anterior[1]))->format('d/m') . ')' : '';
    // Periodo de comparacao sem nenhum dado (conta nova, ou antes de o painel existir): nada de setas
    // nem de "novo" em cada celula; um aviso so na legenda (QA UX-03)
    $compara = $anterior && $antes;
    $criterio = gestor_rank_criterio();

    // Objetos ativos aparecem mesmo sem gasto no periodo (como na UTMify)
    if ($nivelMeta !== 'conta') {
        foreach ($objetos as $id => $o) {
            if ($o['nivel'] === $nivelMeta && $o['status_efetivo'] === 'ACTIVE' && !isset($linhas[$id])) {
                $linhas[$id] = gestor_linha_nova();
            }
        }
    }

    // Nome, filtros (busca, status, campanha/conjunto aberto) e ordem
    $contaNome = meta_api_chave()['conta_nome'] ?? 'Conta de anúncios';
    $tabela = [];
    foreach ($linhas as $id => $l) {
        $obj = $objetos[$id] ?? null;
        $camp = $obj['campanha_id'] ?? $l['pai_camp'];
        $conj = $nivel === 'anuncios' ? ($obj['conjunto_id'] ?? $l['pai_conj']) : ($nivel === 'conjuntos' ? (string)$id : null);
        if (($filtroCamp && !in_array((string)$camp, $filtroCamp, true)) || ($filtroConj && !in_array((string)$conj, $filtroConj, true))) {
            continue;
        }
        $nome = $nivel === 'contas' ? $contaNome : ($obj['nome'] ?? $l['nome_utm'] ?? (string)$id);
        if ($busca !== '' && mb_strpos(mb_strtolower($nome), $busca) === false) {
            continue;
        }
        $ativo = $obj && $obj['status_efetivo'] === 'ACTIVE';
        if (($stFiltro === 'ativos' && !$ativo) || ($stFiltro === 'pausados' && (!$obj || $ativo))) {
            continue;
        }
        $pai = null;
        if ($nivel === 'conjuntos') {
            $pai = $objetos[$camp]['nome'] ?? null;
        } elseif ($nivel === 'anuncios') {
            $pai = $objetos[$conj]['nome'] ?? null;
        }
        $r = gestor_metricas(['id' => (string)$id, 'nome' => $nome, 'pai' => $pai, 'camp' => $camp, 'conj' => $conj, 'obj' => $nivel === 'contas' ? null : $obj] + $l, $pct);
        $r['antes'] = isset($antes[$id]) ? gestor_metricas($antes[$id], $pct) : null;
        $tabela[] = $r;
    }
    usort($tabela, function ($a, $b) use ($ordem, $dir) {
        $va = gestor_valor_ordem($a, $ordem);
        $vb = gestor_valor_ordem($b, $ordem);
        if ($va === null || $vb === null) {
            return ($va === null) <=> ($vb === null); // sem valor vai para o fim
        }
        return $dir === 'asc' ? $va <=> $vb : $vb <=> $va;
    });

    // Links: nivel e filtros atuais mais o que mudar
    $base = ['aba' => 'gestor', 'periodo' => $periodo];
    $sel = array_filter(['campanhas' => $selCamp, 'conjuntos' => $selConj]);
    $atuais = $base + array_filter(['nivel' => $nivel, 'q' => $busca, 'st' => $stFiltro, 'campanha' => $fCamp, 'conjunto' => $fConj, 'ordem' => $ordem, 'dir' => $dir]) + $sel;
    $link = fn(array $mudar) => './?' . http_build_query(array_filter($mudar + $atuais, fn($v) => $v !== null && $v !== '' && $v !== []));
    $singular = ['contas' => 'Conta', 'campanhas' => 'Campanha', 'conjuntos' => 'Conjunto', 'anuncios' => 'Anúncio'][$nivel];
    $fem = in_array($nivel, ['contas', 'campanhas'], true);
    // Caixas de marcar (menos em Contas): as marcadas vao para o menu Acoes; em campanhas e
    // conjuntos, levam a selecao ao proximo nivel (as abas de cima e o "Ver" das Acoes)
    $campoSel = $nivel === 'contas' ? null : $nivel;
    $marcadas = ['campanhas' => $selCamp, 'conjuntos' => $selConj][$nivel] ?? [];
    $volta = $link([]);
    $icones = ['contas' => 'conta', 'campanhas' => 'campanha', 'conjuntos' => 'conjunto', 'anuncios' => 'anuncio'];

    // Gerenciador de Anuncios da Meta: o nivel, ja com as marcadas (ou a linha) selecionadas
    $contaMeta = preg_replace('/\D/', '', (string)(meta_api_chave()['conta'] ?? ''));
    $gerTela = ['conjuntos' => 'adsets', 'anuncios' => 'ads'][$nivel] ?? 'campaigns';
    $gerCampo = ['campanhas' => 'selected_campaign_ids', 'conjuntos' => 'selected_adset_ids', 'anuncios' => 'selected_ad_ids'][$nivel] ?? '';
    $gerBase = $contaMeta !== '' ? 'https://adsmanager.facebook.com/adsmanager/manage/' . $gerTela . '?act=' . $contaMeta : '';

    // Linha de cima: os niveis (com icone) e, a direita, as vendas fora de anuncio, quando foi a
    // ultima busca na Meta e o Atualizar. As abas levam a selecao junto (marcou campanhas,
    // Conjuntos mostra so os delas).
    echo '<div class="gestor-topo"><nav class="gestor-niveis" aria-label="Níveis do gestor">';
    foreach (GESTOR_NIVEIS as $n => [$rot]) {
        echo '<a href="' . e('./?' . http_build_query($base + ['nivel' => $n] + $sel)) . '" class="' . ($nivel === $n ? 'atual' : '') . '">' . icone($icones[$n], 16) . e($rot) . info(GESTOR_NIVEIS_DICA[$n]) . '</a>';
    }
    echo '</nav>';
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
    echo botao_atualizar($volta, 'Atualizar agora: busca o gasto na Meta e as vendas na Kiwify', 'meta') . '</div></div>';
    if ($aviso = aviso_pegar()) {
        echo '<p class="' . ($aviso[1] === 'erro' ? 'erro' : 'aviso-ok') . '">' . e($aviso[0]) . '</p>';
    }
    if (!$temMeta) {
        echo '<p class="aviso-meta">Sem a conta de anúncios conectada, o gestor mostra só as vendas por campanha. Para ver gasto, lucro, CPA e ROI, conecte em <a href="meta-api.php">Integrações → Meta Ads</a>.</p>';
    } elseif ($meta['adiada']) {
        echo '<p class="suave">Busca na Meta adiada: ' . e($meta['adiada']) . '</p>';
    } elseif ($meta['erro']) {
        echo '<p class="erro">Última busca na Meta falhou: ' . e($meta['erro']) . '</p>';
    }

    // Selecao que esta filtrando este nivel
    $nomes = fn(array $ids) => implode(', ', array_map(fn($i) => $objetos[$i]['nome'] ?? $i, array_slice($ids, 0, 3))) . (count($ids) > 3 ? ' e mais ' . (count($ids) - 3) : '');
    if ($fCamp === '' && $fConj === '' && ($filtroCamp || $filtroConj)) {
        $partes = [];
        if ($filtroCamp) {
            $partes[] = count($filtroCamp) . ' campanha(s) marcada(s): ' . $nomes($filtroCamp);
        }
        if ($filtroConj) {
            $partes[] = count($filtroConj) . ' conjunto(s) marcado(s): ' . $nomes($filtroConj);
        }
        echo '<p class="trilha">Mostrando só ' . e(implode(' · ', $partes)) . ' · <a href="' . e('./?' . http_build_query($base + ['nivel' => $nivel])) . '">limpar seleção</a></p>';
    }

    // Caminho aberto (campanha -> conjunto)
    if ($fCamp !== '' || $fConj !== '') {
        $trilha = ['<a href="' . e('./?' . http_build_query($base + ['nivel' => 'campanhas'])) . '">Todas as campanhas</a>'];
        if ($fCamp !== '') {
            $trilha[] = '<a href="' . e('./?' . http_build_query($base + ['nivel' => 'conjuntos', 'campanha' => $fCamp])) . '">' . e($objetos[$fCamp]['nome'] ?? $fCamp) . '</a>';
        }
        if ($fConj !== '') {
            $trilha[] = '<span>' . e($objetos[$fConj]['nome'] ?? $fConj) . '</span>';
        }
        echo '<p class="trilha">' . implode(' <span class="suave">›</span> ', $trilha) . '</p>';
    }

    // Nome abre o proximo nivel: campanha -> conjuntos dela -> anuncios do conjunto
    $nomeLink = function (array $r) use ($nivel, $base): string {
        $html = '<strong>' . e($r['nome']) . '</strong>';
        if ($nivel === 'campanhas') {
            return '<a class="abre" href="' . e('./?' . http_build_query($base + ['nivel' => 'conjuntos', 'campanha' => $r['id']])) . '" title="Ver os conjuntos desta campanha">' . $html . '</a>';
        }
        if ($nivel === 'conjuntos') {
            return '<a class="abre" href="' . e('./?' . http_build_query($base + array_filter(['nivel' => 'anuncios', 'campanha' => (string)$r['camp'], 'conjunto' => $r['id']]))) . '" title="Ver os anúncios deste conjunto">' . $html . '</a>';
        }
        return $html;
    };

    // Barra da tabela, numa linha so (como a da UTMify). A esquerda, o nome e o status do nivel e
    // o Comparar com, que aplicam sozinhos (status e comparar ao escolher, nome ao sair da caixa
    // ou com Enter). A direita, as marcadas, Colunas, Ordenar, Grafico comparativo, Acoes e o
    // modo foco, so com o icone (o nome vem na dica).
    $nomeNivel = ['contas' => 'Nome da conta', 'campanhas' => 'Nome da campanha', 'conjuntos' => 'Nome do conjunto', 'anuncios' => 'Nome do anúncio'][$nivel];
    $stNivel = ['contas' => 'Status da conta', 'campanhas' => 'Status da campanha', 'conjuntos' => 'Status do conjunto', 'anuncios' => 'Status do anúncio'][$nivel];
    $fixos = $atuais;
    unset($fixos['q'], $fixos['st']);
    if ($campoSel) {
        unset($fixos[$campoSel]); // as marcadas deste nivel ficam nas caixas
    }
    $form = '<form class="gestor-filtros" method="get" action="./" data-auto>';
    foreach ($fixos as $k => $v) {
        foreach ((array)$v as $item) {
            $form .= '<input type="hidden" name="' . e(is_array($v) ? $k . '[]' : $k) . '" value="' . e((string)$item) . '">';
        }
    }
    $form .= '<label class="tcard-busca" title="Mostra só as que têm este texto no nome. Aplica ao sair da caixa ou com Enter.">' . icone('busca', 14)
        . '<input type="search" name="q" value="' . e($busca) . '" placeholder="' . e($nomeNivel) . '" aria-label="' . e($nomeNivel) . '"></label>'
        . '<label class="gestor-campo"><span>' . e($stNivel) . '</span><select name="st"><option value="">Todos</option>'
        . '<option value="ativos"' . ($stFiltro === 'ativos' ? ' selected' : '') . '>' . ($fem ? 'Ativas' : 'Ativos') . '</option>'
        . '<option value="pausados"' . ($stFiltro === 'pausados' ? ' selected' : '') . '>' . ($fem ? 'Pausadas' : 'Pausados') . '</option></select></label>';
    // Comparar com: o periodo que as setas da tabela e o ranking usam (fica lembrado)
    $modos = gestor_comparar_modos($periodo);
    $dataCurta = fn(string $d) => (new DateTime($d))->format('d/m');
    if ($modos) {
        $form .= '<label class="gestor-campo"' . ($anterior ? ' title="' . e($dataCurta($dia1) . ' a ' . $dataCurta($dia2) . ' × ' . $dataCurta($anterior[0]) . ' a ' . $dataCurta($anterior[1])) . '"' : '') . '><span>'
            . com_info('Comparar com', 'As setas ▲▼ da tabela e o movimento do ranking comparam cada campanha com este período. Período anterior: os dias logo antes, do mesmo tamanho. Semana ou mês passado: os mesmos dias, uma semana ou um mês antes. Verde = melhorou, vermelho = piorou (em custo, cair é bom), cinza = gasto (nem bom nem ruim). "novo" = não rodou no período comparado; "de 0" = antes era zero. Passe o mouse na seta para ver o valor de antes.')
            . '</span><select name="comparar">';
        foreach ($modos as $m) {
            $form .= '<option value="' . e($m) . '"' . ($m === $modoComp ? ' selected' : '') . '>' . e(GESTOR_COMPARAR[$m][0]) . '</option>';
        }
        $form .= '</select></label>';
    } else {
        $form .= '<span class="suave gestor-campo">' . com_info('Sem comparação', $periodo === 'hoje'
            ? 'Hoje ainda não terminou, e o gasto da Meta vem por dia inteiro: comparar agora daria números enganosos. Escolha outro período no topo (ontem, 7 dias, este mês, de uma data a outra...).'
            : 'Em Tudo não existe um período anterior para comparar. Escolha outro período no topo (ontem, 7 dias, este mês, de uma data a outra...).')
            . ' neste período</span>';
    }
    $form .= '<noscript><button type="submit" class="discreto neutro">Aplicar</button></noscript></form>';

    $ferr = '<div class="tcard-ferr gestor-ferr">';
    if ($campoSel) {
        $ferr .= '<span class="gestor-marcadas" data-sel-chip' . ($marcadas ? '' : ' hidden') . '><b data-sel-conta>' . count($marcadas) . '</b> <span data-sel-rotulo>'
            . (count($marcadas) === 1 ? ($fem ? 'marcada' : 'marcado') : ($fem ? 'marcadas' : 'marcados')) . '</span>'
            . '<button type="button" class="discreto neutro" data-sel-limpa aria-label="Desmarcar todas" title="Desmarcar todas">' . icone('fechar', 13) . '</button></span>';
    }
    // Colunas (com os modelos Campanha, Conjunto e Criativo)
    $ferr .= gestor_colunas_seletor($todas, $colunas, $singular,
        ['aba' => 'gestor', 'periodo' => $periodo, 'nivel' => $nivel] + array_filter(['q' => $busca, 'st' => $stFiltro, 'campanha' => $fCamp, 'conjunto' => $fConj, 'ordem' => $ordem, 'dir' => $dir]) + $sel,
        $link(['cols' => 'padrao']));
    // Ordenar: as colunas da tabela; a mesma de novo inverte
    $ordenar = [];
    foreach (['nome' => $singular] + ($colunas ? array_combine($colunas, array_map(fn($k) => $todas[$k][0], $colunas)) : []) as $col => $tit) {
        $atual = $ordem === $col;
        $ordenar[] = '<a href="' . e($link(['ordem' => $col, 'dir' => $atual && $dir === 'desc' ? 'asc' : 'desc'])) . '"' . ($atual ? ' class="atual"' : '') . '>'
            . icone($atual ? ($dir === 'desc' ? 'seta-baixo' : 'seta-cima') : 'ordenar', 14) . e($tit) . '</a>';
    }
    $ferr .= menu_linha($ordenar, 'Ordenar (de novo na mesma coluna, inverte)', 'ordenar', 'gestor-menu');
    if ($campoSel) {
        $umV = ['campanhas' => ['campanha', 'campanhas'], 'conjuntos' => ['conjunto', 'conjuntos'], 'anuncios' => ['anúncio', 'anúncios']][$nivel];
        $ferr .= '<button type="button" class="botao-icone" data-grafico-abre aria-label="Gráfico comparativo" data-dica-titulo="Gráfico comparativo" data-dica="Marque de 1 a 5 na tabela e compare dia a dia: lucro, ROI, faturamento, gasto, vendas ou CPA." data-dica-botao>' . icone('grafico', 16) . '</button>';
        // Acoes das marcadas (a seta para baixo)
        $csrf = '<input type="hidden" name="csrf" value="' . e(token_csrf()) . '"><input type="hidden" name="volta" value="' . e($volta) . '">';
        $acoes = ['<p class="menu-nota" data-sel-nota>Marque uma ou mais na tabela.</p>'];
        if ($gerBase !== '') {
            $acoes[] = '<a href="' . e($gerBase) . '" target="_blank" rel="noopener" data-gerenciador="' . e($gerBase) . '" data-gerenciador-campo="' . e($gerCampo) . '">' . icone('externo', 14) . 'Abrir no Gerenciador</a>';
        }
        $acoes[] = '<button type="button" data-grafico-abre data-precisa-sel>' . icone('grafico', 14) . 'Gráfico comparativo</button>';
        $acoes[] = '<button type="button" data-copiar-ids data-precisa-sel>' . icone('copiar', 14) . '<span>Copiar ID</span></button>';
        $acoes[] = '<button type="button" data-fixar data-precisa-sel>' . icone('fixar', 14) . '<span>Fixar no topo</span></button>';
        $acoes[] = '<button type="button" data-so-marcadas disabled>' . icone('filtro', 14) . '<span>Filtrar selecionadas</span></button>';
        $ver = $nivel === 'campanhas' ? ['conjuntos' => 'Ver conjuntos delas', 'anuncios' => 'Ver anúncios delas'] : ($nivel === 'conjuntos' ? ['anuncios' => 'Ver anúncios deles'] : []);
        foreach ($ver as $n => $rot) {
            $acoes[] = '<button type="submit" form="form-sel" name="nivel" value="' . $n . '" data-precisa-sel>' . icone($icones[$n], 14) . e($rot) . '</button>';
        }
        $acoes[] = '<hr>';
        if ($pode) {
            foreach (['ACTIVE' => ['ligar', 'Ativar', 'Ligar'], 'PAUSED' => ['pausar', 'Desativar', 'Pausar']] as $st => [$ico, $rot, $verbo]) {
                $acoes[] = '<form method="post" action="meta-status.php" data-massa="' . $verbo . '">' . $csrf . '<input type="hidden" name="status" value="' . $st . '">'
                    . '<button type="submit" data-precisa-sel>' . icone($ico, 14) . $rot . '</button></form>';
            }
            if ($nivel !== 'anuncios') {
                $acoes[] = '<button type="button" data-orc-massa-abre data-precisa-sel>' . icone('carteira', 14) . 'Alterar orçamento</button>'
                    . '<form method="post" action="meta-orcamento.php" class="orc-massa" data-orc-massa hidden>' . $csrf . '<input type="hidden" name="acao" value="mudar_varios">'
                    . '<div class="orc-massa-modo"><label><input type="radio" name="modo" value="valor" checked>Valor (R$)</label><label><input type="radio" name="modo" value="pct">Percentual (%)</label></div>'
                    . '<div class="orc-massa-linha"><input name="valor" inputmode="decimal" required autocomplete="off" placeholder="50,00" aria-label="Novo orçamento diário">'
                    . '<button type="submit">Aplicar</button></div>'
                    . '<small class="suave" data-orc-massa-nota>Orçamento diário de cada marcada, até ' . e(reais(orc_teto())) . ' por dia. No percentual, 10 sobe 10% e -10 desce 10%.</small></form>';
            }
        } else {
            $acoes[] = '<p class="menu-nota">Ativar, desativar e mudar o orçamento por aqui pedem um token da API Meta com ads_management (Integrações → Meta Ads).</p>';
        }
        $ferr .= menu_linha($acoes, 'Ações das marcadas', 'seta-baixo', 'gestor-menu gestor-acoes');
    }
    $ferr .= '<button type="button" class="botao-icone" data-foco aria-pressed="false" aria-label="Modo foco" data-dica-titulo="Modo foco" data-dica="Deixa a tela só com o gestor. Esc ou este botão volta." data-dica-botao>' . icone('foco', 16) . '</button></div>';

    // Formulario da selecao (ver os conjuntos ou anuncios das marcadas): as caixas da tabela e os
    // botoes "Ver" do menu Acoes apontam para ele
    echo '<form id="form-sel" method="get" action="./" hidden><input type="hidden" name="aba" value="gestor"><input type="hidden" name="periodo" value="' . e($periodo) . '">';
    if ($nivel === 'conjuntos') {
        foreach ($selCamp as $c) {
            echo '<input type="hidden" name="campanhas[]" value="' . e($c) . '">';
        }
    }
    echo '</form>';

    // Serie dia a dia de cada linha (grafico comparativo e curva do ranking): de 2 a 92 dias
    // (em Tudo, Hoje e Ontem, so a posicao e o valor)
    $diasPeriodo = [];
    $series = [];
    $qtdDias = $periodo === 'tudo' ? 0 : (int)(new DateTime($dia1))->diff(new DateTime($dia2))->days + 1;
    if ($nivel !== 'contas' && $qtdDias >= 2 && $qtdDias <= 92) {
        for ($d = new DateTime($dia1); $d->format('Y-m-d') <= $dia2; $d->modify('+1 day')) {
            $diasPeriodo[] = $d->format('Y-m-d');
        }
        $series = gestor_serie($db, $nivel, $dia1, $dia2, $de, $ate);
    }

    // A tabela inteligente: a barra em cima, linhas por pagina embaixo, rolagem por dentro com o
    // cabecalho e o total fixos (a pagina continua rolando para o ranking e o historico)
    echo '<section class="tcard gestor-card" data-tabela="gestor-' . e($nivel) . '" data-por-pagina="25" data-gestor-nivel="' . e($nivel) . '"'
        . ($campoSel ? ' data-um="' . e($umV[0]) . '" data-varios="' . e($umV[1]) . '"' . ($fem ? ' data-fem="1"' : '') : '') . '>'
        . '<header class="tcard-cab gestor-cab">' . $form . $ferr . '</header>';
    if ($campoSel) {
        $graf = '';
        if ($diasPeriodo) {
            $dados = ['dias' => $diasPeriodo, 'imposto' => $pct, 's' => new stdClass()];
            foreach ($tabela as $r) {
                if (isset($series[$r['id']])) {
                    $s = $series[$r['id']];
                    $dados['s']->{$r['id']} = [
                        array_map(fn($d) => (int)($s[$d]['gasto'] ?? 0), $diasPeriodo),
                        array_map(fn($d) => (int)($s[$d]['fat'] ?? 0), $diasPeriodo),
                        array_map(fn($d) => (int)($s[$d]['vendas'] ?? 0), $diasPeriodo),
                    ];
                }
            }
            $graf = ' data-serie="' . e((string)json_encode($dados)) . '"';
        }
        echo '<div class="gestor-grafico" data-grafico hidden' . $graf . '><div class="gg-cab"><strong>' . icone('grafico', 15) . 'Gráfico comparativo</strong>'
            . '<div class="segmentos gg-metricas" role="group" aria-label="O que comparar">';
        foreach (['lucro' => 'Lucro', 'roi' => 'ROI', 'fat' => 'Faturamento', 'gasto' => 'Gasto', 'vendas' => 'Vendas', 'cpa' => 'CPA'] as $k => $rot) {
            echo '<button type="button" data-metrica="' . $k . '" aria-pressed="' . ($k === 'lucro' ? 'true' : 'false') . '">' . $rot . '</button>';
        }
        echo '</div><label class="gg-acum"><input type="checkbox" data-acumulado>Acumulado</label>'
            . '<button type="button" class="discreto neutro tcard-icone" data-grafico-fecha aria-label="Fechar o gráfico">' . icone('fechar', 15) . '</button></div>'
            . '<div class="gg-corpo" data-grafico-corpo></div></div>';
    }

    // Cabecalho: clicar no titulo ordena (de novo, inverte)
    $cab = function (string $col, string $titulo, string $dica = '') use ($ordem, $dir, $link): string {
        $atual = $ordem === $col;
        $novoDir = $atual && $dir === 'desc' ? 'asc' : 'desc';
        $seta = $atual ? ($dir === 'desc' ? ' ↓' : ' ↑') : '';
        return '<th data-col="' . e($col) . '"' . ($col === 'nome' ? ' class="nome"' : '') . '><a class="ordena' . ($atual ? ' atual' : '') . '" href="' . e($link(['ordem' => $col, 'dir' => $novoDir])) . '">' . e($titulo) . $seta . '</a>'
            . ($dica !== '' ? '&nbsp;' . info($dica) : '') . '</th>';
    };
    echo '<div class="tabela gestor tcard-corpo"><table data-larguras="' . e($nivel) . '"><thead><tr>' . ($campoSel ? '<th class="marca"><input type="checkbox" data-sel-todos aria-label="Marcar todas desta página"></th>' : '')
        . '<th class="st">Status ' . info($pode ? 'Situação na Meta agora. A chave liga ou pausa na Meta, com confirmação; cada mudança fica registrada no histórico abaixo.' : 'Situação na Meta agora: ativo, pausado ou com problema (ex.: reprovado). Para ligar e pausar por aqui, o token da API Meta precisa de ads_management.') . '</th>' . $cab('nome', $singular);
    foreach ($colunas as $k) {
        echo $cab($k, $todas[$k][0], $todas[$k][1]);
    }
    echo '</tr></thead><tbody>';

    // Variacao contra o periodo de comparacao, em toda coluna de numero:
    // coluna => [campo, menor e melhor (custos), neutro (volume de gasto)]
    $deltas = ['gasto' => ['gasto', false, true], 'vendas' => ['vendas', false, false], 'fat' => ['fat', false, false], 'lucro' => ['lucro', false, false],
        'roi' => ['roi', false, false], 'roas' => ['roas', false, false], 'cpa' => ['cpa', true, false], 'cpi' => ['cpi', true, false], 'ic' => ['checkouts', false, false],
        'cpv' => ['cpv', true, false], 'cpc' => ['cpc', true, false], 'cliques' => ['cliques', false, false], 'ctr' => ['ctr', false, false],
        'impressoes' => ['impressoes', false, true], 'cpm' => ['cpm', true, false], 'visualizacoes' => ['visualizacoes', false, false],
        'margem' => ['margem', false, false], 'imposto' => ['imposto', false, true], 'pend' => ['pend', false, true],
        'hook' => ['hook', false, false], 'hold' => ['hold', false, false], 'video_3s' => ['video_3s', false, false], 'thruplay' => ['thruplay', false, false],
        'ret25' => ['ret25', false, false], 'ret50' => ['ret50', false, false], 'ret75' => ['ret75', false, false], 'ret100' => ['ret100', false, false]];
    $celDelta = function (string $k, array $r, ?array $antesR) use ($deltas, $todas, $compara): string {
        if (!$compara || !isset($deltas[$k]) || $antesR === null) {
            return ''; // linha que nao rodou antes: o "novo" vai uma vez so, ao lado do nome
        }
        [$campo, $menor, $neutro] = $deltas[$k];
        $txt = trim(html_entity_decode(strip_tags($todas[$k][2]($antesR)), ENT_QUOTES, 'UTF-8'));
        $d = gestor_delta(isset($r[$campo]) ? (float)$r[$campo] : null, isset($antesR[$campo]) ? (float)$antesR[$campo] : null, $menor, $neutro, $txt);
        return $d !== '' ? '<br>' . $d : '';
    };
    // Botao da analise diaria (so o icone do grafico; o nome vem na dica), que aparece ao passar
    // o mouse na linha: campanha, conjunto (publico) e anuncio (criativo)
    $diaria = ['campanhas' => [null, 'A campanha dia a dia, com os gráficos e o orçamento'], 'conjuntos' => ['conjunto', 'O conjunto (público) dia a dia: alcance, frequência e quem compra'],
        'anuncios' => ['anuncio', 'O anúncio (criativo) dia a dia: hook rate, hold rate e retenção do vídeo']][$nivel] ?? null;
    $total = gestor_linha_nova();
    $totalAntes = gestor_linha_nova();
    foreach ($tabela as $r) {
        foreach ($total as $c => $v) {
            if (is_int($v)) {
                $total[$c] += $r[$c];
                $totalAntes[$c] += (int)($antes[$r['id']][$c] ?? 0);
            }
        }
        $num = preg_match('/^\d{3,25}$/', $r['id']) === 1;
        $hrefDiaria = $diaria && $num ? './?' . http_build_query(array_filter(['aba' => 'campanha', 'nivel' => $diaria[0], 'id' => $r['id'], 'periodo' => $periodo])) : '';
        $analise = $hrefDiaria !== ''
            ? '<a class="analise" href="' . e($hrefDiaria) . '" aria-label="Análise diária" data-dica-titulo="Análise diária" data-dica="' . e($diaria[1]) . '" data-dica-botao>' . icone('grafico', 15) . '</a>'
            : '';
        // O "..." da linha: analise, o proximo nivel, o Gerenciador da Meta, copiar e fixar
        $itens = [];
        if ($hrefDiaria !== '') {
            $itens[] = '<a href="' . e($hrefDiaria) . '">' . icone('grafico', 14) . 'Análise diária</a>';
        }
        if ($nivel === 'campanhas' && $num) {
            $itens[] = '<a href="' . e('./?' . http_build_query($base + ['nivel' => 'conjuntos', 'campanha' => $r['id']])) . '">' . icone('conjunto', 14) . 'Ver conjuntos</a>';
        } elseif ($nivel === 'conjuntos' && $num) {
            $itens[] = '<a href="' . e('./?' . http_build_query($base + array_filter(['nivel' => 'anuncios', 'campanha' => (string)$r['camp'], 'conjunto' => $r['id']]))) . '">' . icone('anuncio', 14) . 'Ver anúncios</a>';
        }
        if ($gerBase !== '' && ($num || $nivel === 'contas')) {
            $itens[] = '<a href="' . e($gerBase . ($num && $gerCampo !== '' ? '&' . $gerCampo . '=' . $r['id'] : '')) . '" target="_blank" rel="noopener">' . icone('externo', 14) . 'Abrir no Gerenciador</a>';
        }
        if ($num) {
            $itens[] = '<button type="button" data-copiar-id="' . e($r['id']) . '">' . icone('copiar', 14) . '<span>Copiar ID</span></button>';
            $itens[] = '<button type="button" data-fixar-id="' . e($r['id']) . '">' . icone('fixar', 14) . '<span>Fixar no topo</span></button>';
        }
        $acoesLinha = $analise . ($itens ? menu_linha($itens, 'Mais ações') : '');
        $orcProprio = $r['obj'] && (int)($r['obj']['orcamento_diario'] ?? 0) > 0 ? (int)$r['obj']['orcamento_diario'] : 0;
        echo '<tr' . ($num ? ' data-id="' . e($r['id']) . '"' : '') . '>' . ($campoSel ? '<td class="marca">' . ($num ? '<input type="checkbox" form="form-sel" name="' . $campoSel . '[]" value="' . e($r['id']) . '" data-sel'
                . (in_array($r['id'], $marcadas, true) ? ' checked' : '') . ($orcProprio ? ' data-orc="' . $orcProprio . '"' : '') . ' aria-label="Marcar ' . e($r['nome']) . '">' : '') . '</td>' : '')
            . '<td class="st">' . ($nivel === 'contas' ? gestor_status($r['obj']) : gestor_chave($r['obj'], $volta, $pode)) . '</td>'
            . '<td class="quebra nome">' . $nomeLink($r) . ($compara && $r['antes'] === null && (($r['gasto'] ?? 0) || ($r['vendas'] ?? 0)) ? ' <small class="delta novo" title="Não rodou no período comparado">novo</small>' : '') . ($acoesLinha !== '' ? '<span class="linha-acoes">' . $acoesLinha . '</span>' : '') . ($r['pai'] ? '<br><span class="suave">' . e($r['pai']) . '</span>' : '') . '</td>';
        foreach ($colunas as $k) {
            echo '<td>' . $todas[$k][2]($r) . $celDelta($k, $r, $r['antes']) . '</td>';
        }
        echo '</tr>';
    }
    if (!$tabela) {
        echo '<tr><td colspan="' . (2 + ($campoSel ? 1 : 0) + count($colunas)) . '" class="suave">Nada no período.' . ($temMeta ? '' : ' Conecte a API Meta para ver o gasto.') . '</td></tr>';
    }
    echo '</tbody>';
    if ($tabela) {
        $t = gestor_metricas(['id' => '', 'obj' => null] + $total, $pct);
        $tAntes = gestor_metricas(['id' => '', 'obj' => null] + $totalAntes, $pct);
        echo '<tfoot><tr class="total">' . ($campoSel ? '<td class="marca"></td>' : '') . '<td class="st"></td><td class="nome">' . count($tabela) . ' ' . e(mb_strtolower(count($tabela) === 1 ? $singular : $rotuloNivel)) . '</td>';
        foreach ($colunas as $k) {
            echo '<td>' . ($k === 'orcamento' || $k === 'id' ? '' : $todas[$k][2]($t) . $celDelta($k, $t, $tAntes)) . '</td>';
        }
        echo '</tr></tfoot>';
    }
    echo '</table>' . tabela_card_fim(mb_strtolower($singular), mb_strtolower($rotuloNivel));

    // Embaixo da tabela (some no modo foco): a legenda, o ranking e o historico
    echo '<div class="gestor-pos">';
    $comparacao = $compara
        ? 'As setas comparam com ' . $rotuloComp . ': verde melhorou, vermelho piorou (em custo, cair é bom), cinza é volume de gasto; "novo" não rodou antes. Passe o mouse na seta para ver o valor de antes. '
        : ($anterior ? 'Sem setas de comparação: não há dados para ' . $rotuloComp . '. ' : '');
    echo ajuda_tabela(e($comparacao) . 'Clique no nome da campanha para ver os conjuntos, e no conjunto para ver os anúncios; passe o mouse na linha para a análise diária e o "…" (Gerenciador da Meta, copiar ID, fixar no topo); marque várias e use a seta das Ações (ver as delas, gráfico comparativo, ativar, desativar e orçamento); clique no título da coluna para ordenar. '
        . 'Faturamento líquido da Kiwify, com order bump. Lucro = faturamento − gasto − imposto da Meta (' . e(number_format($pct, 2, ',', '.')) . '%).');

    // Ranking (painel de bolsa), embaixo da tabela: da melhor para a pior, com a curva do periodo
    $antesMetricas = $compara ? array_map(fn($l) => gestor_metricas($l, $pct), $antes) : null;
    if ($nivel !== 'contas') {
        echo gestor_ranking_html($tabela, $antesMetricas, $criterio, $nivel, $series, $diasPeriodo, $pct, $link, $nomeLink, $rotuloComp);
    }

    // Historico: quem ligou ou pausou o que, pelo painel
    $hist = gestor_historico(10);
    if ($hist) {
        echo '<section class="bloco">' . titulo('Alterações feitas pelo painel', 'As últimas vezes que alguém ligou, pausou ou mudou o orçamento na Meta por aqui (na hora ou por programação), com o resultado. Mudanças feitas direto no Gerenciador de Anúncios não aparecem.')
            . '<div class="tabela"><table><tr><th>' . com_info('Quando', 'Quando a mudança foi feita (horário de Brasília).') . '</th><th>' . com_info('Quem', 'Usuário do admin que fez a mudança.') . '</th><th>' . com_info('O quê', 'Campanha, conjunto ou anúncio.') . '</th><th>' . com_info('Mudança', 'De que status para qual.') . '</th><th>' . com_info('Resultado', 'Feito: a Meta aceitou. Recusado: a Meta não deixou, com o motivo.') . '</th></tr>';
        $nomeSt = ['ACTIVE' => 'ligado', 'PAUSED' => 'pausado'];
        foreach ($hist as $h) {
            echo '<tr><td>' . e(data_local($h['em'], 'd/m H:i')) . '</td><td>' . e($h['usuario']) . '</td>'
                . '<td class="quebra">' . e(ucfirst(GESTOR_NIVEL_META[$h['nivel']] ?? $h['nivel'])) . ' <strong>' . e((string)$h['nome']) . '</strong></td>'
                . '<td>' . e(orc_rotulo($h['para']) !== null ? 'orçamento ' . orc_rotulo($h['de']) . ' → ' . orc_rotulo($h['para']) : ($nomeSt[$h['de']] ?? '—') . ' → ' . ($nomeSt[$h['para']] ?? $h['para'])) . '</td>'
                . '<td>' . ($h['ok'] ? '<span class="selo ok">Feito</span>' : '<span class="selo erro">Recusado</span> <span class="suave">' . e((string)$h['erro']) . '</span>') . '</td></tr>';
        }
        echo '</table></div></section>';
    }
    echo '</div>';
}
