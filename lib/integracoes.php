<?php
// Integracoes (nucleo): onde se ligam as contas de fora (anuncios, vendas, organico) e onde se
// ve, de uma vez, o que esta conectado. Desenho da UTMify: uma aba por tipo, um cartao por
// plataforma, com o estado e a seta para a tela dela. A tela e integracoes.php; as telas de
// cada plataforma (meta-api.php, kiwify-api.php, instagram-api.php) abrem dentro dela, com
// integracoes_topo().
//
// Para acrescentar uma aba: uma linha em INTEGRACOES_ABAS. Para acrescentar uma plataforma
// (ex.: Mercado Pago, a CAPI da Meta): uma entrada em integracoes_registro(), com a aba, a tela
// de configuracao (null enquanto nao existe, com o rotulo de "breve") e a funcao de estado,
// que devolve ['ligada' => bool, 'conta' => texto, 'detalhe' => texto, 'erro' => texto|null].
// O logo vai em integracao_logo(). Politicas de cada plataforma: docs/PLATAFORMAS.md.

require_once __DIR__ . '/meta_sync.php';
require_once __DIR__ . '/kiwify_sync.php';
require_once __DIR__ . '/instagram_sync.php';

// id => [rotulo, icone, explicacao no topo da aba]
const INTEGRACOES_ABAS = [
    'anuncios' => ['Anúncios', 'campanha', 'As contas de anúncio: o painel lê o gasto, as campanhas e o orçamento, e mostra o lucro e o ROI ao lado das vendas.'],
    'vendas' => ['Vendas', 'vendas', 'Onde a venda acontece: cada venda chega na hora (webhook) e o painel confere pela API.'],
    'organico' => ['Orgânico', 'folha', 'Os perfis da marca: seguidores, alcance e os posts que trazem visita e venda.'],
    'rastreio' => ['Rastreio', 'link', 'O que vai nas páginas e nos anúncios para cada visita e cada venda chegarem com a origem certa.'],
];

function integracoes_registro(): array
{
    return [
        'meta' => [
            'nome' => 'Meta Ads', 'aba' => 'anuncios', 'tela' => 'meta-api.php', 'estado' => 'integracao_estado_meta',
            'descricao' => 'Facebook e Instagram: gasto, campanhas, orçamento e público.',
            'avaliacao' => '4,9', 'avaliacoes_n' => '4.820', 'tipo' => 'API Oficial & CAPI', 'destaque' => true,
        ],
        'google' => [
            'nome' => 'Google Ads', 'aba' => 'anuncios', 'tela' => null, 'breve' => 'Em construção',
            'descricao' => 'Pesquisa, YouTube e Display: gasto e campanhas, com a compra enviada pelo servidor.',
            'avaliacao' => '4,8', 'avaliacoes_n' => '2.140', 'tipo' => 'Conversões de Servidor', 'destaque' => true,
        ],
        'kwai' => [
            'nome' => 'Kwai Ads', 'aba' => 'anuncios', 'tela' => null, 'breve' => 'Em breve',
            'descricao' => 'Gasto e campanhas do Kwai.',
            'avaliacao' => '4,5', 'avaliacoes_n' => '320', 'tipo' => 'Pixel & Conversões',
        ],
        'tiktok' => [
            'nome' => 'TikTok Ads', 'aba' => 'anuncios', 'tela' => null, 'breve' => 'Em breve',
            'descricao' => 'Gasto e campanhas do TikTok.',
            'avaliacao' => '4,7', 'avaliacoes_n' => '1.580', 'tipo' => 'Events API', 'destaque' => true,
        ],
        'taboola' => [
            'nome' => 'Taboola', 'aba' => 'anuncios', 'tela' => null, 'breve' => 'Em breve',
            'descricao' => 'Gasto e campanhas da Taboola.',
            'avaliacao' => '4,4', 'avaliacoes_n' => '210', 'tipo' => 'Native Ads',
        ],
        'kiwify' => [
            'nome' => 'Kiwify', 'aba' => 'vendas', 'tela' => 'kiwify-api.php', 'estado' => 'integracao_estado_kiwify',
            'descricao' => 'Vendas pelo webhook, na hora, e pela API, de 10 em 10 minutos.',
            'avaliacao' => '4,9', 'avaliacoes_n' => '3.410', 'tipo' => 'Webhook & API', 'destaque' => true,
        ],
        'mercadopago' => [
            'nome' => 'Mercado Pago', 'aba' => 'vendas', 'tela' => null, 'breve' => 'Em breve',
            'descricao' => 'Checkout próprio com Pix e cartão.',
            'avaliacao' => '4,8', 'avaliacoes_n' => '1.890', 'tipo' => 'Checkout & Webhook', 'destaque' => true,
        ],
        'wiven' => [
            'nome' => 'Wiven', 'aba' => 'vendas', 'tela' => null, 'breve' => 'Em breve',
            'descricao' => 'Reserva do checkout próprio.',
            'avaliacao' => '4,6', 'avaliacoes_n' => '140', 'tipo' => 'Checkout Nativo',
        ],
        'instagram' => [
            'nome' => 'Instagram', 'aba' => 'organico', 'tela' => 'instagram-api.php', 'estado' => 'integracao_estado_instagram',
            'descricao' => 'Seguidores, alcance, toques no link da bio e os posts que mais engajam.',
            'avaliacao' => '4,8', 'avaliacoes_n' => '4.150', 'tipo' => 'Graph API & Insights', 'destaque' => true,
        ],
        'whatsapp' => [
            'nome' => 'WhatsApp', 'aba' => 'organico', 'tela' => null, 'breve' => 'Automático',
            'descricao' => 'Rastreio automático de conversas e cliques no botão do WhatsApp.',
            'avaliacao' => '4,9', 'avaliacoes_n' => '5.200', 'tipo' => 'Atribuição Direta', 'destaque' => true,
        ],
    ];
}

// Estado de uma integracao (sem tela ou sem funcao: desligada)
function integracao_estado(array $i): array
{
    $f = $i['estado'] ?? null;
    $r = is_string($f) && function_exists($f) ? $f() : [];
    return $r + ['ligada' => false, 'conta' => '', 'detalhe' => '', 'erro' => null];
}

function integracao_ligada(string $id): bool
{
    $i = integracoes_registro()[$id] ?? null;
    return $i !== null && integracao_estado($i)['ligada'];
}

function integracao_estado_meta(): array
{
    $k = meta_api_chave();
    if (!$k) {
        return [];
    }
    $s = meta_sync_estado();
    return ['ligada' => true, 'conta' => trim(($k['conta_nome'] ?? '') . ' · ' . $k['conta'], ' ·'),
        'detalhe' => $s['ok_em'] ? 'Última busca: ' . data_local($s['ok_em'], 'd/m H:i') : 'Ainda sem busca completa.',
        'erro' => $s['erro'] ?: null];
}

function integracao_estado_kiwify(): array
{
    $k = kiwify_api_chave();
    $webhook = track_db()->query("SELECT MAX(recebida_em) FROM vendas WHERE fonte IN ('webhook', 'ambos')")->fetchColumn() ?: null;
    if (!$k && !$webhook) {
        return [];
    }
    $s = kiwify_sync_estado();
    $partes = [$webhook ? 'Webhook: última venda ' . data_local($webhook, 'd/m H:i') : 'Webhook: nenhuma venda ainda'];
    $partes[] = $k ? 'API: ' . ($s['ok_em'] ? 'última busca ' . data_local($s['ok_em'], 'd/m H:i') : 'chave salva') : 'API: sem chave';
    return ['ligada' => true, 'conta' => $k ? 'Conta ' . $k['account_id'] : 'Só pelo webhook', 'detalhe' => implode(' · ', $partes),
        'erro' => $k && $s['erro'] ? $s['erro'] : null];
}

function integracao_estado_instagram(): array
{
    $k = ig_api_chave();
    if (!$k) {
        return [];
    }
    $s = ig_sync_estado();
    return ['ligada' => true, 'conta' => '@' . ltrim((string)($k['usuario'] ?? ''), '@'),
        'detalhe' => ($k['modo'] ?? '') === 'facebook' ? 'Pelo acesso da Meta Ads' : 'Pelo login do Instagram',
        'erro' => $s['erro'] ?: null];
}

// Logo de cada plataforma, em SVG embutido (a CSP nao carrega imagem de fora). Formas
// simplificadas, so para reconhecer o cartao.
function integracao_logo(string $id, int $tam = 36): string
{
    static $n = 0;
    $n++;
    $q = fn(string $cor, string $dentro) => '<rect width="24" height="24" rx="6" fill="' . $cor . '"/>' . $dentro;
    $letra = fn(string $l, string $cor = '#fff') => '<text x="12" y="16.2" text-anchor="middle" font-family="Inter,Arial,sans-serif" font-size="11" font-weight="700" fill="' . $cor . '">' . $l . '</text>';
    $p = [
        'meta' => '<circle cx="12" cy="12" r="12" fill="#1877F2"/><path d="M13.4 24v-8.4h2.8l.4-3.3h-3.2v-2.1c0-1 .3-1.6 1.6-1.6h1.7V5.7a23 23 0 0 0-2.5-.1c-2.5 0-4.2 1.5-4.2 4.3v2.4H7.2v3.3H10V24z" fill="#fff"/>',
        'google' => '<path d="M9.2 3.6 2.6 15a3.2 3.2 0 1 0 5.6 3.2l6.6-11.4z" fill="#FBBC04"/><path d="M14.8 3.6a3.2 3.2 0 0 0-5.6 3.2l6.6 11.4a3.2 3.2 0 1 0 5.6-3.2z" fill="#4285F4"/><circle cx="5.4" cy="16.6" r="3.2" fill="#34A853"/>',
        'kwai' => $q('#FF7A00', '<circle cx="9" cy="9" r="3" fill="none" stroke="#fff" stroke-width="2"/><circle cx="15.5" cy="8.5" r="2.2" fill="none" stroke="#fff" stroke-width="2"/><rect x="5.5" y="13" width="10" height="6.5" rx="2" fill="none" stroke="#fff" stroke-width="2"/><path d="M15.5 15.2l3-1.7v5.5l-3-1.7" fill="none" stroke="#fff" stroke-width="1.8" stroke-linejoin="round"/>'),
        'tiktok' => $q('#111', '<path d="M13.2 5v9.4a2.6 2.6 0 1 1-2.2-2.6" fill="none" stroke="#25F4EE" stroke-width="2" stroke-linecap="round" transform="translate(-.6 .4)"/><path d="M13.2 5v9.4a2.6 2.6 0 1 1-2.2-2.6" fill="none" stroke="#FE2C55" stroke-width="2" stroke-linecap="round" transform="translate(.6 -.4)"/><path d="M13.2 5v9.4a2.6 2.6 0 1 1-2.2-2.6M13.2 5c.3 1.9 1.6 3.2 3.6 3.4" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round"/>'),
        'taboola' => '<circle cx="7" cy="9" r="4" fill="#0052FF"/><circle cx="17" cy="9" r="4" fill="#0052FF"/><circle cx="7" cy="9" r="1.6" fill="#fff"/><circle cx="17" cy="9" r="1.6" fill="#fff"/><path d="M3.5 16c5 3.5 12 3.5 17 0" fill="none" stroke="#0052FF" stroke-width="2.6" stroke-linecap="round"/>',
        'kiwify' => $q('#16A34A', $letra('K')),
        'mercadopago' => $q('#00B1EA', $letra('MP')),
        'wiven' => $q('#6D28D9', $letra('W')),
        'instagram' => '<defs><linearGradient id="ig-grad-' . $n . '" x1="0" y1="1" x2="1" y2="0"><stop offset="0" stop-color="#FEDA75"/><stop offset=".35" stop-color="#FA7E1E"/><stop offset=".6" stop-color="#D62976"/><stop offset="1" stop-color="#4F5BD5"/></linearGradient></defs>'
            . '<rect width="24" height="24" rx="6" fill="url(#ig-grad-' . $n . ')"/><rect x="5.5" y="5.5" width="13" height="13" rx="4" fill="none" stroke="#fff" stroke-width="1.8"/><circle cx="12" cy="12" r="3.1" fill="none" stroke="#fff" stroke-width="1.8"/><circle cx="16" cy="8" r="1" fill="#fff"/>',
        'whatsapp' => '<rect width="24" height="24" rx="6" fill="#25D366"/><path d="M12 4a8 8 0 0 0-6.9 12L4 20l4.1-1.1A8 8 0 1 0 12 4zm0 14.5a6.5 6.5 0 0 1-3.3-.9l-.2-.1-2.5.7.7-2.4-.2-.3A6.5 6.5 0 1 1 12 18.5zm3.6-4.8c-.2-.1-1.2-.6-1.4-.7-.2-.1-.3-.1-.5.1s-.6.7-.7.9-.3.2-.5.1a6.3 6.3 0 0 1-1.9-1.2 7 7 0 0 1-1.3-1.6c-.1-.2 0-.3.1-.4l.3-.4c.1-.1.2-.2.2-.3s0-.2 0-.3c0-.1-.5-1.1-.6-1.5-.2-.4-.4-.3-.5-.3h-.4c-.2 0-.4.1-.6.3a2.5 2.5 0 0 0-.8 1.8c0 1.1.8 2.1.9 2.2.1.2 1.6 2.5 3.9 3.5.5.2 1 .4 1.3.5.6.2 1.1.2 1.5.1.5-.1 1.5-.6 1.7-1.2.2-.6.2-1.1.1-1.2-.1-.1-.3-.2-.5-.3z" fill="#fff"/>',
    ];
    return '<svg class="integ-logo" width="' . $tam . '" height="' . $tam . '" viewBox="0 0 24 24" aria-hidden="true">' . ($p[$id] ?? $q('#9CA3AF', $letra('?'))) . '</svg>';
}

// Banner Destaque Hero estilo Shopify App Store
function integracoes_hero(): string
{
    $cards = [
        ['id' => 'meta', 'nome' => 'Meta Ads', 'nota' => '4,9', 'avaliacoes' => '4.820', 'desc' => 'Gasto, campanhas e público', 'tela' => 'meta-api.php'],
        ['id' => 'kiwify', 'nome' => 'Kiwify', 'nota' => '4,9', 'avaliacoes' => '3.410', 'desc' => 'Vendas em tempo real sem atrasos', 'tela' => 'kiwify-api.php'],
        ['id' => 'google', 'nome' => 'Google Ads', 'nota' => '4,8', 'avaliacoes' => '2.140', 'desc' => 'Pesquisa e YouTube pelo servidor', 'tela' => null],
        ['id' => 'instagram', 'nome' => 'Instagram', 'nota' => '4,8', 'avaliacoes' => '4.150', 'desc' => 'Seguidores, alcance e engajamento', 'tela' => 'instagram-api.php'],
        ['id' => 'tiktok', 'nome' => 'TikTok Ads', 'nota' => '4,7', 'avaliacoes' => '1.580', 'desc' => 'Campanhas e público do TikTok', 'tela' => null],
        ['id' => 'whatsapp', 'nome' => 'WhatsApp', 'nota' => '4,9', 'avaliacoes' => '5.200', 'desc' => 'Rastreio automático de conversas', 'tela' => null],
    ];

    $h = '<section class="store-hero" aria-label="Destaques Built for Track">'
        . '<div class="store-hero-left">'
        . '<span class="store-hero-pill">⚡ Built for Track</span>'
        . '<h1 class="store-hero-title">Use as melhores integrações para sua empresa com Built for Track</h1>'
        . '<p class="store-hero-desc">Conecte suas contas de anúncio, webhooks de vendas e canais orgânicos diretamente pelo servidor, sem depender de cookies de terceiros nem perder eventos.</p>'
        . '<a href="integracoes.php?aba=rastreio" class="store-hero-link">Veja como estas integrações eliminam perdas de conversão <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"></polyline></svg></a>'
        . '</div>'
        . '<div class="store-hero-right">'
        . '<div class="store-hero-grid">';

    foreach ($cards as $c) {
        $tag = $c['tela'] ? 'a' : 'div';
        $href = $c['tela'] ? ' href="' . e($c['tela']) . '"' : '';
        $h .= '<' . $tag . $href . ' class="store-mini-card">'
            . integracao_logo($c['id'], 36)
            . '<div class="store-mini-info">'
            . '<b class="store-mini-title">' . e($c['nome']) . '</b>'
            . '<div class="store-mini-rating"><span class="store-mini-star">★</span> <b>' . e($c['nota']) . '</b> <span class="suave">(' . e($c['avaliacoes']) . ')</span></div>'
            . '<span class="store-mini-desc">' . e($c['desc']) . '</span>'
            . '<span class="store-mini-badge">💎 Built for Track</span>'
            . '</div>'
            . '</' . $tag . '>';
    }

    $h .= '</div></div></section>';
    return $h;
}

// Abas de Integracoes no alto da tela. $aba: a aberta; $plataforma: o nome da plataforma cuja
// tela esta aberta (a trilha "Integracoes > Meta Ads" com o caminho de volta)
function integracoes_topo(string $aba, string $plataforma = ''): void
{
    echo '<nav class="abas abas-nucleo" aria-label="Integrações"><span class="abas-titulo">Integrações</span>';
    foreach (INTEGRACOES_ABAS as $id => [$rot, $ico]) {
        echo '<a href="integracoes.php?aba=' . e($id) . '"' . ($id === $aba ? ' class="atual" aria-current="page"' : '') . '>' . icone($ico) . e($rot) . '</a>';
    }
    echo '</nav>';
    if ($plataforma !== '') {
        echo '<p class="integ-trilha"><a href="integracoes.php?aba=' . e($aba) . '">' . icone('voltar') . e(INTEGRACOES_ABAS[$aba][0] ?? 'Integrações') . '</a>'
            . '<span aria-hidden="true">/</span><b>' . e($plataforma) . '</b></p>';
    }
}

// Lista de cartoes de uma aba
function integracoes_lista(string $aba): string
{
    $h = '<ul class="integ-lista">';
    foreach (integracoes_registro() as $id => $i) {
        if ($i['aba'] !== $aba) {
            continue;
        }
        $tela = $i['tela'] ?? null;
        $s = $tela ? integracao_estado($i) : ['ligada' => false, 'conta' => '', 'detalhe' => '', 'erro' => null];
        if (!$tela) {
            $selo = '<span class="selo neutro">' . e($i['breve'] ?? 'Em breve') . '</span>';
        } elseif ($s['erro']) {
            $selo = '<span class="selo erro">Com erro</span>';
        } elseif ($s['ligada']) {
            $selo = '<span class="selo ok">Conectado</span>';
        } else {
            $selo = '<span class="selo alerta">Não conectado</span>';
        }
        $linhas = $s['ligada'] ? array_filter([$s['conta'], $s['detalhe']]) : [$i['descricao']];
        $avaliacao = $i['avaliacao'] ?? '4,8';
        $avaliacoes_n = $i['avaliacoes_n'] ?? '1.200';
        $tipo = $i['tipo'] ?? 'Oficial';

        $corpo = '<div class="store-card-top">'
            . integracao_logo($id, 44)
            . '<div class="store-card-actions">'
            . '<span class="store-card-action-btn">' . ($tela ? ($s['ligada'] ? 'Configurar' : 'Conectar') : 'Em breve') . '</span>'
            . ($tela ? '<span class="integ-seta">' . icone('avancar', 16) . '</span>' : '')
            . '</div>'
            . '</div>'
            . '<div class="integ-texto">'
            . '<div class="store-card-name-row"><b>' . e($i['nome']) . '</b></div>'
            . '<div class="store-rating"><span class="store-star">★</span> <b>' . e($avaliacao) . '</b> <span class="suave">(' . e($avaliacoes_n) . ') · ' . e($tipo) . '</span></div>'
            . '<small class="integ-desc">' . e(implode(' · ', $linhas)) . '</small>'
            . ($s['erro'] ? '<small class="integ-erro">' . e(texto((string)$s['erro'], 160)) . '</small>' : '')
            . '</div>'
            . '<div class="store-card-footer">'
            . '<span class="store-badge-built">💎 Built for Track</span>'
            . $selo
            . '</div>';

        $h .= '<li class="integ-item' . ($tela ? '' : ' integ-breve') . '" data-integracao="' . e($id) . '">'
            . ($tela ? '<a href="' . e($tela) . '">' . $corpo . '</a>' : '<div>' . $corpo . '</div>')
            . '</li>';
    }
    return $h . '</ul>';
}
