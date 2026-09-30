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
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
/* Visual de ferramenta de trabalho: neutro, denso e sem enfeite. Azul da marca so no que
   e acao ou estado atual (botao principal, aba aberta, link). Sem sombra, sem pilula, sem
   icone repetido em toda aba. Inter em tudo, com numeros alinhados (tabular-nums). */
:root{
  --fundo:#F7F8FA; --cartao:#FFFFFF; --cartao-2:#F3F4F6; --hover:#F7F9FC;
  --texto:#111827; --suave:#6B7280; --apagado:#9CA3AF; --linha:#E5E7EB; --linha-forte:#D1D5DB;
  --marca:#1D6FF2; --marca-hover:#155FD6;
  --ok:#15803D; --alerta:#B45309; --erro:#B91C1C;
  --f-texto:"Inter",system-ui,-apple-system,"Segoe UI",sans-serif; --f-titulo:var(--f-texto); --f-mono:"JetBrains Mono",ui-monospace,Consolas,monospace;
  --r-sm:6px; --r-md:8px; --r-lg:8px;
}
*{ box-sizing:border-box; }
body{ margin:0; font:13px/1.5 var(--f-texto); background:var(--fundo); color:var(--texto); font-feature-settings:"tnum" 1; }
a{ color:var(--marca); text-decoration:none; } a:hover{ text-decoration:underline; }
code, pre{ font-family:var(--f-mono); font-size:12px; }
pre{ background:var(--cartao-2); border:1px solid var(--linha); padding:10px 12px; border-radius:var(--r-sm); overflow-x:auto; white-space:pre-wrap; word-break:break-all; }
select, input, textarea, button{ font:inherit; color:inherit; }
select, input, textarea{ background:var(--cartao); border:1px solid var(--linha-forte); border-radius:var(--r-sm); padding:6px 9px; min-width:160px; }
select:focus, input:focus, textarea:focus{ outline:2px solid rgba(29,111,242,.35); outline-offset:0; border-color:var(--marca); }
button{ background:var(--marca); color:#fff; border:1px solid var(--marca); border-radius:var(--r-sm); padding:6px 12px; font-weight:500; cursor:pointer; }
button:hover{ background:var(--marca-hover); }
button.discreto{ background:var(--cartao); color:var(--erro); border-color:var(--linha-forte); padding:4px 10px; }
button.discreto:hover{ background:var(--cartao-2); }
button:disabled, button:disabled:hover{ background:var(--cartao-2); color:var(--suave); border-color:var(--linha-forte); cursor:not-allowed; }
button.discreto.neutro{ color:var(--texto); }
.ico{ flex:none; } button .ico{ vertical-align:-2px; }
.info{ display:inline-flex; align-items:center; justify-content:center; width:14px; height:14px; margin-left:3px; border:1px solid var(--linha-forte); border-radius:50%; font:600 9px/1 var(--f-texto); color:var(--suave); cursor:help; vertical-align:1px; text-transform:none; }
.nw{ white-space:nowrap; }
.info:hover, .info:focus{ color:var(--marca); border-color:var(--marca); outline:none; }
.dica{ position:fixed; z-index:50; max-width:300px; background:#111827; color:#fff; font-size:12px; line-height:1.45; padding:7px 10px; border-radius:6px; pointer-events:none; }

/* Topo: filtros a esquerda, conta a direita */
.topo{ position:sticky; top:0; z-index:5; background:var(--cartao); border-bottom:1px solid var(--linha); padding:10px 24px; display:flex; flex-wrap:wrap; gap:12px; align-items:end; }
.topo label, .gestor-filtros label{ display:flex; flex-direction:column; font-size:12px; color:var(--suave); gap:3px; }
.filtros{ display:flex; flex-wrap:wrap; gap:10px; align-items:end; }
.conta{ margin-left:auto; align-self:center; display:flex; align-items:center; gap:10px; color:var(--suave); }
.conta form{ margin:0; }
.conta-nome{ color:var(--texto); font-weight:500; }

/* Abas: texto, com traco embaixo da aberta */
.abas{ display:flex; align-items:stretch; gap:20px; padding:0 24px; background:var(--cartao); border-bottom:1px solid var(--linha); overflow-x:auto; scrollbar-width:none; }
.abas::-webkit-scrollbar{ display:none; }
.abas-titulo{ font-weight:600; padding:11px 8px 11px 0; margin-right:4px; white-space:nowrap; align-self:center; }
.abas a{ display:inline-flex; align-items:center; padding:11px 0; border-bottom:2px solid transparent; color:var(--suave); font-weight:500; white-space:nowrap; }
.abas a:hover{ color:var(--texto); text-decoration:none; }
.abas a.atual{ color:var(--texto); border-bottom-color:var(--marca); }
.abas .ico{ display:none; }
.abas-espaco{ margin-left:auto; }

main{ padding:20px 24px 56px; }
h2{ font-size:14px; font-weight:600; margin:24px 0 8px; }
.suave{ color:var(--suave); }
.erro{ color:var(--erro); } .aviso-ok{ color:var(--ok); }

/* Numeros do topo: uma faixa so, dividida */
.numeros{ display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); margin-bottom:20px; background:var(--cartao); border:1px solid var(--linha); border-radius:var(--r-md); overflow:hidden; }
.numero{ display:flex; flex-direction:column-reverse; justify-content:flex-end; gap:2px; padding:12px 16px; border-right:1px solid var(--linha); border-bottom:1px solid var(--linha); margin:0 -1px -1px 0; }
.numero b{ font-size:20px; font-weight:600; }
.numero span{ color:var(--suave); font-size:12px; }
.numero small{ color:var(--suave); font-size:12px; font-weight:400; }

/* Tabelas */
.tabela{ overflow-x:auto; background:var(--cartao); border:1px solid var(--linha); border-radius:var(--r-md); margin-bottom:20px; }
table{ border-collapse:collapse; width:100%; }
th, td{ text-align:left; padding:8px 12px; border-bottom:1px solid var(--linha); vertical-align:top; white-space:nowrap; }
th{ font-size:12px; font-weight:500; color:var(--suave); background:var(--cartao-2); }
tr:hover td{ background:var(--hover); }
tr:last-child td{ border-bottom:0; }
td.quebra{ white-space:normal; min-width:220px; }
.selo{ display:inline-block; padding:0 6px; border-radius:4px; font-size:12px; font-weight:500; line-height:20px; }
.selo.ok{ color:var(--ok); background:#ECFDF3; } .selo.alerta{ color:var(--alerta); background:#FFF7ED; }
.selo.erro{ color:var(--erro); background:#FEF2F2; } .selo.neutro{ color:var(--suave); background:var(--cartao-2); }

/* Caixas de formulario */
.cartao{ background:var(--cartao); border:1px solid var(--linha); border-radius:var(--r-md); padding:16px 18px; margin-bottom:16px; max-width:600px; }
.cartao h2{ margin-top:0; }
.cartao label, .caixa-login label{ display:flex; flex-direction:column; gap:4px; margin-bottom:10px; color:var(--suave); font-size:12px; }
.cartao input, .caixa-login input, .caixa-login textarea{ width:100%; }
.caixa-login{ max-width:360px; margin:12vh auto; background:var(--cartao); border:1px solid var(--linha); border-radius:var(--r-md); padding:24px; }
.caixa-login.larga{ max-width:680px; margin-top:6vh; }
.caixa-login h1{ font-size:18px; font-weight:600; margin-top:0; }
.linha-botoes{ display:flex; gap:8px; flex-wrap:wrap; }
.linha-botoes button.discreto{ padding:6px 12px; }
.passos{ margin:0 0 14px; padding-left:18px; } .passos li{ margin-bottom:3px; }

/* Barra de atualizacao das vendas e do gasto */
.barra-vendas{ display:flex; flex-wrap:wrap; gap:8px 12px; align-items:center; margin:0 0 14px; color:var(--suave); }
.barra-vendas form{ margin:0; }

/* Barra lateral CMS | UTM */
.casca{ display:flex; min-height:100vh; }
.conteudo{ flex:1; min-width:0; }
.lateral{ width:64px; flex:none; background:#0B1B2E; display:flex; flex-direction:column; align-items:center; gap:4px; padding:12px 0; position:sticky; top:0; height:100vh; }
.lateral a{ width:48px; padding:8px 0 6px; border-radius:var(--r-sm); display:flex; flex-direction:column; align-items:center; gap:3px; color:#9CA3AF; font-size:10.5px; font-weight:500; }
.lateral a:hover{ background:rgba(255,255,255,.06); color:#fff; text-decoration:none; }
.lateral a.atual{ background:rgba(255,255,255,.1); color:#fff; }
.lateral svg{ width:20px; height:20px; }

/* Gestor de anuncios */
.gestor-niveis{ display:inline-flex; background:var(--cartao-2); border:1px solid var(--linha); border-radius:var(--r-sm); padding:2px; margin:0 0 14px; }
.gestor-niveis a{ padding:5px 14px; border-radius:4px; color:var(--suave); font-weight:500; }
.gestor-niveis a:hover{ color:var(--texto); text-decoration:none; }
.gestor-niveis a.atual{ background:var(--cartao); color:var(--texto); box-shadow:0 0 0 1px var(--linha); }
.gestor-niveis .ico{ display:none; }
.gestor-filtros{ display:flex; flex-wrap:wrap; gap:10px; align-items:end; margin:0 0 14px; }
.colunas{ position:relative; }
.colunas summary{ list-style:none; cursor:pointer; padding:6px 12px; border:1px solid var(--linha-forte); border-radius:var(--r-sm); background:var(--cartao); color:var(--texto); }
.colunas summary::-webkit-details-marker{ display:none; }
.colunas > div{ position:absolute; z-index:10; left:0; top:calc(100% + 4px); display:grid; grid-template-columns:repeat(2,minmax(150px,1fr)); gap:4px 18px; min-width:340px; padding:12px 14px;
  background:var(--cartao); border:1px solid var(--linha); border-radius:var(--r-md); box-shadow:0 8px 24px rgba(17,24,39,.08); }
.colunas label{ flex-direction:row !important; align-items:center; gap:6px !important; color:var(--texto) !important; font-size:13px !important; }
.colunas input{ min-width:0; }
.colunas button{ grid-column:1 / -1; justify-self:start; margin-top:6px; }
.tabela.gestor td, .tabela.gestor th{ text-align:right; } .tabela.gestor td:nth-child(-n+2), .tabela.gestor th:nth-child(-n+2){ text-align:left; }
.tabela.gestor td.quebra{ min-width:200px; }
.tabela.gestor tr.total td{ background:var(--cartao-2); font-weight:600; }
.positivo{ color:var(--ok); } .negativo{ color:var(--erro); }
.tabela.gestor a.ordena{ color:inherit; } .tabela.gestor a.ordena.atual{ color:var(--texto); font-weight:600; }
.tabela.gestor a.abre{ color:var(--texto); } .tabela.gestor a.abre:hover strong{ color:var(--marca); text-decoration:underline; }
.delta{ font-size:11px; color:var(--suave); white-space:nowrap; } .delta.bom{ color:var(--ok); } .delta.ruim{ color:var(--erro); }
.trilha{ margin:0 0 12px; }
.status-meta{ display:inline-flex; align-items:center; gap:6px; color:var(--suave); }
.status-meta::before{ content:""; width:7px; height:7px; border-radius:50%; background:var(--apagado); }
.status-meta.ativo{ color:var(--texto); } .status-meta.ativo::before{ background:#16A34A; }
.status-meta.alerta{ color:var(--alerta); } .status-meta.alerta::before{ background:#F59E0B; }
.aviso-meta{ background:var(--cartao); border:1px solid var(--linha); border-left:3px solid var(--marca); border-radius:var(--r-sm); padding:8px 12px; }
.legenda{ font-size:12px; }
.grafico{ display:block; width:100%; height:auto; margin-top:4px; }
.grafico text{ font:11px var(--f-texto); fill:var(--suave); }
.grafico .grade-l{ stroke:var(--linha); stroke-width:1; }
.grafico .barra{ fill:var(--marca); }
.legenda-grafico{ display:flex; align-items:center; gap:6px; margin:0; color:var(--suave); font-size:12px; }
.legenda-grafico i{ display:inline-block; width:12px; height:3px; margin-left:10px; }
.legenda-grafico i:first-child{ margin-left:0; }

/* Resumo: blocos lado a lado, barras simples no lugar de grafico de rosca */
.grade{ display:grid; grid-template-columns:repeat(auto-fit,minmax(320px,1fr)); gap:16px; margin-bottom:16px; }
.bloco{ background:var(--cartao); border:1px solid var(--linha); border-radius:var(--r-md); padding:14px 16px; }
.bloco h2{ margin:0 0 10px; }
.barras{ display:grid; grid-template-columns:minmax(90px,max-content) 1fr auto; gap:6px 12px; align-items:center; }
.barras .rot{ color:var(--texto); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:240px; }
.barras .trilho{ height:8px; background:var(--cartao-2); border-radius:2px; overflow:hidden; }
.barras .trilho i{ display:block; height:100%; background:var(--marca); border-radius:2px; }
.barras .val{ text-align:right; white-space:nowrap; }
.barras .val span{ color:var(--suave); margin-left:8px; }
.funil{ display:grid; grid-auto-flow:column; grid-auto-columns:minmax(0,1fr); border:1px solid var(--linha); border-radius:var(--r-sm); }
.funil > div{ padding:10px 12px; border-right:1px solid var(--linha); min-width:0; }
.funil > div:last-child{ border-right:0; }
.bloco + .bloco, .grade + .bloco, .bloco + .grade{ margin-top:16px; }
.grade > .bloco{ margin-top:0; }
.funil > div > span{ display:block; color:var(--suave); font-size:12px; }
.funil b{ display:block; font-size:18px; font-weight:600; }
.funil em{ font-style:normal; color:var(--suave); font-size:12px; }
.funil .trilho{ height:4px; background:var(--cartao-2); margin-top:8px; }
.funil .trilho i{ display:block; height:100%; background:var(--marca); }

/* Filtro por tipo de evento */
.filtro-eventos{ display:flex; flex-wrap:wrap; gap:4px 18px; margin:0 0 4px; }
.filtro-eventos a{ color:var(--suave); padding:2px 0; border-bottom:2px solid transparent; }
.filtro-eventos a b{ color:var(--texto); font-weight:600; margin-left:4px; }
.filtro-eventos a:hover{ color:var(--texto); text-decoration:none; }
.filtro-eventos a.atual{ color:var(--texto); border-bottom-color:var(--marca); }
.filtro-eventos a.compra b{ color:var(--ok); }

/* Canal: icone pequeno, cor so no icone */
.canal{ display:inline-flex; align-items:center; gap:6px; white-space:nowrap; }
.canal .ico{ width:14px; height:14px; }
.canal-instagram .ico{ color:#C13584; } .canal-facebook .ico, .canal-meta .ico, .canal-compartilhado .ico{ color:#1877F2; }
.canal-google .ico{ color:#EA4335; } .canal-organico .ico{ color:#16A34A; } .canal-outros .ico, .canal-direto .ico{ color:var(--apagado); }

@media (max-width:640px){ .topo, .abas{ padding-left:16px; padding-right:16px; } main{ padding:16px; } select{ min-width:130px; } .conta{ margin-left:0; }
  .grade{ grid-template-columns:1fr; }
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
        'conta' => '<rect x="3" y="4" width="18" height="5" rx="1"/><path d="M5 9v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V9M10 13h4"/>',
        'campanha' => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M12 11v5M9.5 13.5h5"/>',
        'conjunto' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'anuncio' => '<rect x="6" y="2" width="12" height="20" rx="2"/><path d="M11 18h2"/>',
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
        // Etiqueta com {{...}} escrito: o link do anuncio foi aberto fora da entrega paga
        // (post compartilhado, link copiado). A Meta nao preencheu nada, nem o posicionamento.
        if (strpos($m . $t, '{{') !== false) {
            return ['compartilhado', 'Anúncio compartilhado', 'link', 'link do anúncio aberto fora da entrega paga'];
        }
        // Anuncio pago, mas a etiqueta nao disse onde apareceu (Facebook ou Instagram): utm_term
        // vazio ou outro valor (ex.: "an", Audience Network). O detalhe mostra o que chegou.
        return ['meta', 'Anúncio sem posicionamento', 'meta', $t === '' ? 'utm_term vazio' : 'utm_term "' . texto((string)$term, 40) . '"'];
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
const CANAIS_ORDEM = ['instagram', 'facebook', 'meta', 'compartilhado', 'google', 'organico', 'outros', 'direto'];

// Canais que o nome nao explica: o (i) ao lado diz o que sao
const CANAIS_DICA = [
    'meta' => 'Veio de anúncio da Meta (a etiqueta traz a campanha), mas o utm_term, que diz onde o anúncio apareceu, chegou vazio ou com um valor que não é Facebook nem Instagram (ex.: "an", da Audience Network). Por isso não entra em Facebook nem em Instagram. Campanha, conjunto e anúncio continuam valendo no Gestor de anúncios. Para diminuir: o link do anúncio precisa ter utm_term={{placement}}.',
    'compartilhado' => 'Link de anúncio aberto fora da entrega paga (post compartilhado, link copiado, prévia do anúncio): as etiquetas chegaram com {{...}} escrito, sem o que a Meta preencheria. Não dá para ligar a uma campanha.',
    'direto' => 'Chegou sem etiqueta e sem site de origem: link digitado ou salvo, app que esconde de onde veio, link direto do checkout ou troca de aparelho entre o clique e a compra.',
];

function selo_canal(array $c, bool $comDetalhe = true): string
{
    return '<span class="canal canal-' . e($c[0]) . '"' . ($c[3] !== '' ? ' title="' . e($c[3]) . '"' : '') . '>' . icone($c[2]) . '<span>' . e($c[1]) . '</span>'
        . (isset(CANAIS_DICA[$c[0]]) ? info(CANAIS_DICA[$c[0]]) : '')
        . ($comDetalhe && $c[3] !== '' ? '<span class="suave">· ' . e($c[3]) . '</span>' : '') . '</span>';
}

// (i) com a explicacao de um numero ou bloco: aparece ao passar o mouse ou focar pelo
// teclado (painel.js desenha a caixa por cima de tudo, sem ser cortada pela tabela)
function info(string $texto): string
{
    return '<span class="info" tabindex="0" role="img" aria-label="' . e($texto) . '" data-dica="' . e($texto) . '">i</span>';
}

// Rotulo com o (i) grudado na ultima palavra (nunca cai sozinho na linha de baixo)
function com_info(string $texto, string $dica): string
{
    $corte = strrpos($texto, ' ');
    $inicio = $corte === false ? '' : substr($texto, 0, $corte + 1);
    $fim = $corte === false ? $texto : substr($texto, $corte + 1);
    return e($inicio) . '<span class="nw">' . e($fim) . '&nbsp;' . info($dica) . '</span>';
}

// Titulo de bloco com o (i)
function titulo(string $texto, string $dica, string $tag = 'h2'): string
{
    return '<' . $tag . '>' . com_info($texto, $dica) . '</' . $tag . '>';
}

// Numero do topo com rotulo e (i). $valor ja vem pronto para a tela (texto)
function numero(string $valor, string $rotulo, string $dica, string $classe = ''): string
{
    return '<div class="numero"><b class="' . e($classe) . '">' . e($valor) . '</b><span>' . com_info($rotulo, $dica) . '</span></div>';
}

// Um texto qualquer (ex.: a origem detalhada) com o icone do canal na frente
function com_icone_canal(array $c, string $texto): string
{
    return '<span class="canal canal-' . e($c[0]) . '">' . icone($c[2]) . '<span>' . e($texto) . '</span></span>';
}

// Conta de quem esta no painel e o Sair, no canto direito do topo (como no CMS)
function conta_topo(): string
{
    return '<div class="conta"><span class="conta-nome">' . e((string)usuario_atual()) . '</span>'
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
    $soPeriodo = in_array($aba, ['gestor', 'geral', 'organico'], true); // no gestor, no resumo e no organico, site e pagina nao se aplicam
    ?>
<header class="topo">
<form class="filtros" method="get" action="./" id="filtros">
  <input type="hidden" name="aba" value="<?= e($aba) ?>">
  <?php if (!$soPeriodo): ?>
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
  <?php elseif (is_string($_GET['nivel'] ?? null) && preg_match('/^[a-z]{5,10}$/', $_GET['nivel'])): ?>
  <input type="hidden" name="nivel" value="<?= e($_GET['nivel']) ?>">
  <?php endif; ?>
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
    $abas = ['geral' => ['Resumo', 'trafego'], 'trafego' => ['Tráfego', 'trafego'], 'gestor' => ['Gestor de anúncios', 'meta'], 'organico' => ['Orgânico', 'folha'], 'resumo' => ['Conferência', 'conferencia'], 'vendas' => ['Vendas', 'vendas'],
        'visitantes' => ['Visitantes', 'visitantes'], 'eventos' => ['Eventos', 'eventos']];
    echo '<nav class="abas" aria-label="Seções do painel UTM"><span class="abas-titulo">UTM · Rastreio de vendas</span>';
    foreach ($abas as $id => [$rotulo, $ico]) {
        $q = http_build_query(['aba' => $id] + array_intersect_key($filtro, ['dominio' => 1, 'pagina' => 1, 'periodo' => 1]));
        echo '<a href="./?' . e($q) . '" class="' . ($aba === $id ? 'atual' : '') . '">' . icone($ico) . e($rotulo) . '</a>';
    }
    echo '<span class="abas-espaco"></span>';
    echo '<a href="kiwify-api.php" class="' . ($aba === 'kiwify-api' ? 'atual' : '') . '">' . icone('chave') . 'API Kiwify</a>';
    echo '<a href="meta-api.php" class="' . ($aba === 'meta-api' ? 'atual' : '') . '">' . icone('meta') . 'API Meta</a>';
    echo '<a href="instagram-api.php" class="' . ($aba === 'instagram-api' ? 'atual' : '') . '">' . icone('instagram') . 'API Instagram</a>';
    echo '<a href="usuarios.php" class="' . ($aba === 'usuarios' ? 'atual' : '') . '">' . icone('usuario') . 'Usuários</a></nav>';
}
