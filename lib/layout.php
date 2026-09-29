<?php
// HTML comum do painel. Sem framework; CSS aqui, JS em painel.js (a CSP so aceita script do proprio painel).

function pagina_inicio(string $titulo): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
    ?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($titulo) ?> · Rastreio</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
<style>
/* Mesmo design do CMS da OZATI (engdesk admin/css/admin.css): tema claro, azul de
   marca, Space Grotesk nos titulos, Inter no texto e JetBrains Mono em numeros e
   cabecalhos de tabela. Painel e CMS lado a lado parecem uma ferramenta so. */
:root{
  --fundo:#F6F8FB; --cartao:#FFFFFF; --cartao-2:#F1F5F9; --hover:#EEF3FB;
  --texto:#0B1B2E; --suave:#55697F; --apagado:#7C8EA3; --linha:#E3E9F0;
  --marca:#1D6FF2; --marca-hover:#155FD6;
  --ok:#0B7A3D; --alerta:#B45309; --erro:#C0392B;
  --f-titulo:"Space Grotesk",system-ui,sans-serif; --f-texto:"Inter",system-ui,-apple-system,"Segoe UI",sans-serif; --f-mono:"JetBrains Mono",ui-monospace,Consolas,monospace;
  --r-sm:6px; --r-md:10px; --r-lg:14px;
  --sombra:0 1px 2px rgba(11,27,46,.04), 0 10px 28px rgba(11,27,46,.06);
}
*{ box-sizing:border-box; }
body{ margin:0; font:13.5px/1.45 var(--f-texto); background:var(--fundo); color:var(--texto); }
a{ color:var(--marca); }
code, pre{ font-family:var(--f-mono); font-size:12px; }
pre{ background:var(--cartao-2); border:1px solid var(--linha); padding:10px 12px; border-radius:var(--r-sm); overflow-x:auto; white-space:pre-wrap; word-break:break-all; }
.topo{ position:sticky; top:0; z-index:5; background:var(--cartao); border-bottom:1px solid var(--linha); padding:12px 28px; display:flex; flex-wrap:wrap; gap:12px; align-items:end; }
.topo h1{ font-family:var(--f-titulo); font-size:15px; font-weight:700; margin:0 12px 0 0; align-self:center; white-space:nowrap; }
.topo label{ display:flex; flex-direction:column; font-size:11px; color:var(--suave); gap:3px; font-weight:500; }
select, input, textarea, button{ font:inherit; color:inherit; }
select, input, textarea{ background:var(--cartao-2); border:1px solid var(--linha); border-radius:8px; padding:7px 10px; min-width:170px; }
select:focus, input:focus, textarea:focus{ outline:none; border-color:var(--marca); background:var(--cartao); box-shadow:0 0 0 3px rgba(29,111,242,.12); }
button{ background:var(--marca); color:#fff; border:0; border-radius:8px; padding:8px 14px; font-weight:600; cursor:pointer; transition:background .16s ease; }
button:hover{ background:var(--marca-hover); }
.abas{ display:flex; align-items:center; gap:6px; padding:10px 28px; background:var(--cartao); border-bottom:1px solid var(--linha); overflow-x:auto; scrollbar-width:none; }
.abas::-webkit-scrollbar{ display:none; }
.abas a{ padding:7px 12px; border-radius:8px; border:1px solid transparent; text-decoration:none; color:var(--suave); font-size:13px; font-weight:500; white-space:nowrap; transition:background .16s ease, color .16s ease; }
.abas a:hover{ background:#EEF2F7; color:var(--texto); }
.abas a.atual{ background:rgba(29,111,242,.08); border-color:rgba(29,111,242,.35); color:var(--marca); font-weight:600; }
main{ padding:24px 28px 60px; }
.numeros{ display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:12px; margin-bottom:20px; }
.numero{ background:var(--cartao); border:1px solid var(--linha); border-radius:var(--r-lg); padding:14px 16px; box-shadow:var(--sombra); }
.numero b{ display:block; font-family:var(--f-titulo); font-size:24px; font-weight:700; letter-spacing:-.01em; }
.numero span{ color:var(--suave); font-size:12px; }
.tabela{ overflow-x:auto; background:var(--cartao); border:1px solid var(--linha); border-radius:var(--r-lg); margin-bottom:20px; box-shadow:var(--sombra); }
table{ border-collapse:collapse; width:100%; font-size:13px; }
th, td{ text-align:left; padding:10px 14px; border-bottom:1px solid var(--linha); vertical-align:top; white-space:nowrap; }
th{ background:var(--cartao-2); font-family:var(--f-mono); font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.08em; color:var(--suave); }
tr:hover td{ background:var(--hover); }
td.quebra{ white-space:normal; min-width:220px; }
tr:last-child td{ border-bottom:0; }
.selo{ display:inline-block; padding:1px 8px; border-radius:99px; font-size:11.5px; font-weight:600; border:1px solid currentColor; }
.selo.ok{ color:var(--ok); background:rgba(18,161,80,.07); } .selo.alerta{ color:var(--alerta); background:rgba(245,166,35,.09); }
.selo.erro{ color:var(--erro); background:rgba(224,72,61,.07); } .selo.neutro{ color:var(--suave); }
.suave{ color:var(--suave); }
h2{ font-family:var(--f-titulo); font-size:16px; font-weight:700; letter-spacing:-.01em; margin:24px 0 10px; }
.caixa-login{ max-width:380px; margin:10vh auto; background:var(--cartao); border:1px solid var(--linha); border-radius:var(--r-lg); padding:28px; box-shadow:var(--sombra); }
.caixa-login.larga{ max-width:720px; margin-top:5vh; }
.caixa-login h1{ font-family:var(--f-titulo); font-size:20px; margin-top:0; }
.caixa-login label{ display:flex; flex-direction:column; gap:4px; margin-bottom:12px; color:var(--suave); font-size:13px; font-weight:500; }
.caixa-login input, .caixa-login textarea{ width:100%; }
.erro{ color:var(--erro); }
.aviso-ok{ color:var(--ok); }
.cartao{ background:var(--cartao); border:1px solid var(--linha); border-radius:var(--r-lg); padding:18px 20px; margin-bottom:16px; max-width:600px; box-shadow:var(--sombra); }
.cartao h2{ margin-top:0; }
.cartao label{ display:flex; flex-direction:column; gap:4px; margin-bottom:10px; color:var(--suave); font-size:13px; font-weight:500; }
.cartao input{ width:100%; }
.cartao .tabela{ box-shadow:none; }
button.discreto{ background:var(--cartao); color:var(--erro); border:1px solid var(--linha); padding:4px 10px; font-weight:500; }
button.discreto:hover{ background:var(--cartao-2); }
.linha-botoes{ display:flex; gap:8px; flex-wrap:wrap; }
.linha-botoes button.discreto{ padding:8px 14px; }
.barra-vendas{ display:flex; flex-wrap:wrap; gap:8px 14px; align-items:center; margin:0 0 16px; }
.barra-vendas form{ margin:0; }
button.discreto.neutro{ color:var(--texto); }
.casca{ display:flex; min-height:100vh; }
.conteudo{ flex:1; min-width:0; }
.lateral{ width:68px; flex:none; background:#061424; display:flex; flex-direction:column; align-items:center; gap:6px; padding:14px 0; position:sticky; top:0; height:100vh; }
.lateral a{ width:52px; padding:8px 0 6px; border-radius:10px; display:flex; flex-direction:column; align-items:center; gap:3px; color:#9FB0C3; text-decoration:none; font-size:10.5px; font-weight:600; letter-spacing:.02em; transition:background .16s ease, color .16s ease; }
.lateral a:hover{ background:rgba(255,255,255,.07); color:#fff; }
.lateral a.atual{ background:var(--marca); color:#fff; }
.lateral svg{ width:20px; height:20px; }
.filtros{ display:flex; flex-wrap:wrap; gap:12px; align-items:end; }
.conta{ margin-left:auto; align-self:center; display:flex; align-items:center; gap:10px; }
.conta form{ margin:0; }
.conta-nome{ font-weight:600; }
.selo-admin{ background:linear-gradient(135deg,#F5A623,#E0821A); color:#2D1A00; font-weight:800; font-size:10px; padding:2px 7px; border-radius:4px; letter-spacing:.08em; text-transform:uppercase; }
.abas-titulo{ font-family:var(--f-titulo); font-weight:700; font-size:14px; margin-right:10px; white-space:nowrap; }
.abas a{ display:inline-flex; align-items:center; gap:7px; }
.abas-espaco{ margin-left:auto; }
.ico{ flex:none; }
button .ico{ vertical-align:-2px; }
.canal{ display:inline-flex; align-items:center; gap:6px; white-space:nowrap; }
.canal-instagram .ico{ color:#C13584; } .canal-facebook .ico{ color:#1877F2; } .canal-meta .ico{ color:#0866FF; }
.canal-google .ico{ color:#EA4335; } .canal-organico .ico{ color:#12A150; } .canal-outros .ico{ color:#7C5CFF; } .canal-direto .ico{ color:var(--apagado); }
@media (max-width:640px){ .topo, .abas{ padding-left:16px; padding-right:16px; } main{ padding:16px; } select{ min-width:140px; } .conta{ margin-left:0; }
  .casca{ flex-direction:column; } .lateral{ width:auto; height:auto; flex-direction:row; justify-content:center; position:static; padding:6px; } }
</style>
</head>
<body>
<?php
}

function pagina_fim(): void
{
    // ?v= pela data do arquivo: dentro de um site cujo .htaccess manda cachear .js por
    // 1 ano (ex.: engdesk.pro), sem isso a mudanca no painel.js nao chegaria.
    echo '<script src="painel.js?v=' . (int)@filemtime(__DIR__ . '/../painel.js') . '"></script></body></html>';
}

// Barra lateral com CMS e UTM, quando o painel mora dentro de um admin (config "menu_cms").
// Sem essa configuracao (painel avulso em track.dominio), nao aparece.
function casca_inicio(): void
{
    $cms = track_config()['menu_cms'] ?? '';
    if ($cms === '') {
        return;
    }
    $iconeCms = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>';
    $iconeUtm = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>';
    echo '<div class="casca"><nav class="lateral" aria-label="Seções do admin">'
        . '<a href="' . e($cms) . '" title="CMS">' . $iconeCms . '<span>CMS</span></a>'
        . '<a href="./" class="atual" title="UTM" aria-current="page">' . $iconeUtm . '<span>UTM</span></a>'
        . '</nav><div class="conteudo">';
}

function casca_fim(): void
{
    if ((track_config()['menu_cms'] ?? '') !== '') {
        echo '</div></div>';
    }
}

// Icones de linha (mesmo traco dos icones do CMS). So SVG inline: a CSP nao carrega imagem de fora.
function icone(string $nome, int $tam = 16): string
{
    $p = [
        'trafego' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'conferencia' => '<path d="M9 11l3 3 8-8"/><path d="M20 12v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h9"/>',
        'vendas' => '<path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
        'visitantes' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'eventos' => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
        'chave' => '<circle cx="7.5" cy="15.5" r="4.5"/><path d="M10.7 12.3L21 2M16 7l3 3M14 9l2 2"/>',
        'usuario' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'sair' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
        'atualizar' => '<path d="M21 12a9 9 0 1 1-2.64-6.36L21 8"/><path d="M21 3v5h-5"/>',
        'instagram' => '<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><path d="M17.5 6.5h.01"/>',
        'facebook' => '<path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/>',
        'meta' => '<path d="M2 15c0-5 2.5-9 5-9 3 0 5 7 7.5 10.5 1.3 1.7 2.3 2.5 3.5 2.5 2 0 4-2 4-5.5S20 6 17 6c-3 0-5.5 5-7.5 8.5C8 17 6.5 19 5 19c-2 0-3-2-3-4z"/>',
        'google' => '<path d="M20.5 12H12M20.5 12a8.5 8.5 0 1 1-2.5-6"/>',
        'folha' => '<path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.5 19 2c1 2 2 4.2 2 8 0 5.5-4.8 10-10 10z"/><path d="M2 21c0-3 1.9-5.4 5.1-6C9.5 14.5 12 13 13 12"/>',
        'link' => '<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>',
        'direto' => '<path d="M7 17L17 7M8 7h9v9"/>',
    ];
    return '<svg class="ico" width="' . $tam . '" height="' . $tam . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$nome] ?? '') . '</svg>';
}

// Meio organico em portugues (etiquetas da limpeza da pagina: organico / <meio> / ...)
function rotulo_meio(string $m): string
{
    $nomes = ['instagram-bio' => 'Instagram (bio)', 'instagram' => 'Instagram', 'google' => 'Google', 'whatsapp' => 'WhatsApp',
        'facebook' => 'Facebook', 'ia' => 'IA (ChatGPT e outros)', 'site' => 'outro site', 'direto' => 'direto', 'email' => 'e-mail', 'youtube' => 'YouTube'];
    return $nomes[$m] ?? $m;
}

// Canal de uma origem, para agrupar e mostrar com icone: [chave, rotulo, icone, detalhe].
// Anuncio da Meta vira Instagram ou Facebook pelo posicionamento (utm_term, ex.:
// Instagram_Reels); sem posicionamento, "Meta". Organico leva a folha. Sem etiqueta,
// o site de onde a pessoa veio (so para visitantes; venda nao tem referrer).
function canal(?string $source, ?string $medium = null, ?string $term = null, ?string $referrer = null): array
{
    $s = strtolower(trim((string)$source));
    $m = strtolower(trim((string)$medium));
    $t = strtolower(trim((string)$term));
    $pago = strpos($m, '|') !== false || in_array($m, ['cpc', 'ppc', 'paid', 'paid_social', 'ads'], true);
    if (in_array($s, ['organico', 'orgânico', 'organic'], true)) {
        return ['organico', 'Orgânico', 'folha', rotulo_meio($m)];
    }
    if ($s === 'ig') { // marca automatica do Instagram no link da bio
        return ['organico', 'Orgânico', 'folha', 'Instagram (bio)'];
    }
    if (in_array($s, ['metaads', 'meta', 'fb', 'facebookads', 'instagramads'], true) || (in_array($s, ['facebook', 'instagram'], true) && $pago)) {
        if (strpos($t, 'instagram') === 0 || ($t === '' && in_array($s, ['instagram', 'instagramads'], true))) {
            return ['instagram', 'Instagram · anúncio', 'instagram', ''];
        }
        if (preg_match('/^(facebook|messenger|audience)/', $t) || ($t === '' && $s === 'facebook')) {
            return ['facebook', 'Facebook · anúncio', 'facebook', ''];
        }
        return ['meta', 'Meta · anúncio', 'meta', ''];
    }
    if (in_array($s, ['googleads', 'google-ads', 'adwords'], true) || ($s === 'google' && $pago)) {
        return ['google', 'Google · anúncio', 'google', ''];
    }
    if (in_array($s, ['facebook', 'instagram', 'google', 'whatsapp', 'youtube'], true)) {
        return ['organico', 'Orgânico', 'folha', rotulo_meio($s)];
    }
    if ($s !== '') {
        return ['outros', 'Outras origens', 'link', (string)$source];
    }
    $host = strtolower(explode('/', (string)$referrer)[0]);
    if ($host !== '') {
        $mapa = ['instagram' => 'Instagram', 'facebook' => 'Facebook', 'fb.' => 'Facebook', 'google' => 'Google', 'whatsapp' => 'WhatsApp',
            'wa.me' => 'WhatsApp', 'youtube' => 'YouTube', 'chatgpt' => 'IA (ChatGPT e outros)', 'openai' => 'IA (ChatGPT e outros)',
            'perplexity' => 'IA (ChatGPT e outros)', 'gemini' => 'IA (ChatGPT e outros)', 'claude' => 'IA (ChatGPT e outros)'];
        foreach ($mapa as $trecho => $nome) {
            if (strpos($host, $trecho) !== false) {
                return ['organico', 'Orgânico', 'folha', $nome];
            }
        }
        return ['organico', 'Orgânico', 'folha', preg_replace('/^www\./', '', $host)];
    }
    return ['direto', 'Direto / sem origem', 'direto', ''];
}

// Ordem dos canais nas tabelas agrupadas
const CANAIS_ORDEM = ['instagram', 'facebook', 'meta', 'google', 'organico', 'outros', 'direto'];

function selo_canal(array $c, bool $comDetalhe = true): string
{
    return '<span class="canal canal-' . e($c[0]) . '">' . icone($c[2]) . '<span>' . e($c[1]) . '</span>'
        . ($comDetalhe && $c[3] !== '' ? '<span class="suave">· ' . e($c[3]) . '</span>' : '') . '</span>';
}

// Um texto qualquer (ex.: a origem detalhada) com o icone do canal na frente
function com_icone_canal(array $c, string $texto): string
{
    return '<span class="canal canal-' . e($c[0]) . '">' . icone($c[2]) . '<span>' . e($texto) . '</span></span>';
}

// Conta de quem esta no painel e o Sair, no canto direito do topo (como no CMS)
function conta_topo(): string
{
    return '<div class="conta"><span class="selo-admin">Admin</span><span class="conta-nome">' . e((string)usuario_atual()) . '</span>'
        . '<form method="post" action="sair.php" id="form-sair"><input type="hidden" name="csrf" value="' . e(token_csrf()) . '">'
        . '<button type="submit" class="discreto neutro" title="Encerrar a sessão neste navegador">' . icone('sair', 14) . ' Sair</button></form></div>';
}

// Topo das telas sem filtro (Usuarios, API Kiwify)
function topo_pagina(): void
{
    echo '<header class="topo">' . conta_topo() . '</header>';
}

// Barra do topo: dominio e, dependendo dele, a pagina, e o periodo. A conta fica a direita.
function barra_topo(array $filtro, array $dominios, array $paginas, string $aba): void
{
    $periodos = ['hoje' => 'Hoje', 'ontem' => 'Ontem', '7d' => 'Últimos 7 dias', '30d' => 'Últimos 30 dias', 'tudo' => 'Tudo'];
    ?>
<header class="topo">
<form class="filtros" method="get" action="./" id="filtros">
  <input type="hidden" name="aba" value="<?= e($aba) ?>">
  <label>Site
    <select name="dominio" data-reinicia="pagina">
      <option value="">Todos os sites</option>
      <?php foreach ($dominios as $d): ?>
        <option value="<?= e($d) ?>"<?= $filtro['dominio'] === $d ? ' selected' : '' ?>><?= e($d) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Página
    <select name="pagina"<?= $filtro['dominio'] === '' ? ' disabled' : '' ?>>
      <option value="">Todas as páginas</option>
      <?php foreach ($paginas as $p): ?>
        <option value="<?= e($p) ?>"<?= $filtro['pagina'] === $p ? ' selected' : '' ?>><?= e($p) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Período
    <select name="periodo">
      <?php foreach ($periodos as $v => $rotulo): ?>
        <option value="<?= e($v) ?>"<?= $filtro['periodo'] === $v ? ' selected' : '' ?>><?= e($rotulo) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <noscript><button type="submit">Filtrar</button></noscript>
</form>
<?= conta_topo() ?>
</header>
<?php
    abas_painel($aba, $filtro);
}

// Abas: titulo e as telas de dados a esquerda (com icone, como no CMS); as de
// configuracao (API Kiwify e Usuarios) no canto direito. $filtro vazio: links sem filtro.
function abas_painel(string $aba, array $filtro = []): void
{
    $abas = ['trafego' => ['Tráfego', 'trafego'], 'resumo' => ['Conferência', 'conferencia'], 'vendas' => ['Vendas', 'vendas'],
        'visitantes' => ['Visitantes', 'visitantes'], 'eventos' => ['Eventos', 'eventos']];
    echo '<nav class="abas" aria-label="Seções do painel UTM"><span class="abas-titulo">UTM · Rastreio de vendas</span>';
    foreach ($abas as $id => [$rotulo, $ico]) {
        $q = http_build_query(['aba' => $id] + array_intersect_key($filtro, ['dominio' => 1, 'pagina' => 1, 'periodo' => 1]));
        echo '<a href="./?' . e($q) . '" class="' . ($aba === $id ? 'atual' : '') . '">' . icone($ico) . e($rotulo) . '</a>';
    }
    echo '<span class="abas-espaco"></span>';
    echo '<a href="kiwify-api.php" class="' . ($aba === 'kiwify-api' ? 'atual' : '') . '">' . icone('chave') . 'API Kiwify</a>';
    echo '<a href="usuarios.php" class="' . ($aba === 'usuarios' ? 'atual' : '') . '">' . icone('usuario') . 'Usuários</a></nav>';
}
