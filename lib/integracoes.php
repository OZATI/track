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
        'meta' => ['nome' => 'Meta Ads', 'aba' => 'anuncios', 'tela' => 'meta-api.php', 'estado' => 'integracao_estado_meta',
            'descricao' => 'Facebook e Instagram: gasto, campanhas, orçamento e público.'],
        'google' => ['nome' => 'Google Ads', 'aba' => 'anuncios', 'tela' => null, 'breve' => 'Em construção',
            'descricao' => 'Pesquisa, YouTube e Display: gasto e campanhas, com a compra enviada pelo servidor.'],
        'kwai' => ['nome' => 'Kwai Ads', 'aba' => 'anuncios', 'tela' => null, 'breve' => 'Em breve', 'descricao' => 'Gasto e campanhas do Kwai.'],
        'tiktok' => ['nome' => 'TikTok Ads', 'aba' => 'anuncios', 'tela' => null, 'breve' => 'Em breve', 'descricao' => 'Gasto e campanhas do TikTok.'],
        'taboola' => ['nome' => 'Taboola', 'aba' => 'anuncios', 'tela' => null, 'breve' => 'Em breve', 'descricao' => 'Gasto e campanhas da Taboola.'],
        'kiwify' => ['nome' => 'Kiwify', 'aba' => 'vendas', 'tela' => 'kiwify-api.php', 'estado' => 'integracao_estado_kiwify',
            'descricao' => 'Vendas pelo webhook, na hora, e pela API, de 10 em 10 minutos.'],
        'mercadopago' => ['nome' => 'Mercado Pago', 'aba' => 'vendas', 'tela' => null, 'breve' => 'Em breve', 'descricao' => 'Checkout próprio com Pix e cartão.'],
        'wiven' => ['nome' => 'Wiven', 'aba' => 'vendas', 'tela' => null, 'breve' => 'Em breve', 'descricao' => 'Reserva do checkout próprio.'],
        'instagram' => ['nome' => 'Instagram', 'aba' => 'organico', 'tela' => 'instagram-api.php', 'estado' => 'integracao_estado_instagram',
            'descricao' => 'Seguidores, alcance, toques no link da bio e os posts que mais engajam.'],
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
    ];
    return '<svg class="integ-logo" width="' . $tam . '" height="' . $tam . '" viewBox="0 0 24 24" aria-hidden="true">' . ($p[$id] ?? $q('#9CA3AF', $letra('?'))) . '</svg>';
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
        $corpo = integracao_logo($id) . '<span class="integ-texto"><b>' . e($i['nome']) . '</b>'
            . '<small>' . e(implode(' · ', $linhas)) . '</small>'
            . ($s['erro'] ? '<small class="integ-erro">' . e(texto((string)$s['erro'], 160)) . '</small>' : '') . '</span>' . $selo;
        $h .= '<li class="integ-item' . ($tela ? '' : ' integ-breve') . '" data-integracao="' . e($id) . '">'
            . ($tela ? '<a href="' . e($tela) . '">' . $corpo . '<span class="integ-seta">' . icone('avancar', 18) . '</span></a>' : '<div>' . $corpo . '</div>')
            . '</li>';
    }
    return $h . '</ul>';
}
