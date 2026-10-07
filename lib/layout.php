<?php
// HTML comum do painel. Sem framework; CSS aqui, JS em painel.js (a CSP so aceita script do proprio painel).

require_once __DIR__ . '/tema.php';
require_once __DIR__ . '/perfil.php';
require_once __DIR__ . '/admin_tela.php'; // nucleo do admin: CSS comum, barra lateral, cabecalho
require_once __DIR__ . '/componentes.php'; // cartoes com icone e a tabela inteligente


function pagina_inicio(string $titulo): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
    ?><!doctype html>
<html lang="pt-BR"<?= tema_atributos() ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title><?= e($titulo) ?> · Rastreio</title>
<link rel="manifest" href="<?= e(admin_base()) ?>manifest.php">
<meta name="theme-color" content="<?= e(tema_atual()) ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?= e((string)((track_config() ?? [])['app_nome'] ?? 'Painel')) ?>">
<link rel="apple-touch-icon" href="<?= e(admin_base()) ?><?= is_file(__DIR__ . '/../../apple-touch-icon.png') ? '../apple-touch-icon.png' : 'app-192.png' ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
<?= admin_css_base() ?>
/* Visual de ferramenta de trabalho: neutro, denso e sem enfeite. Azul da marca so no que
   e acao ou estado atual (botao principal, aba aberta, link). Sem sombra, sem pilula, sem
   icone repetido em toda aba. Inter em tudo, com numeros alinhados (tabular-nums). */
.defs-graficos{ position:absolute; width:0; height:0; overflow:hidden; }
body{ margin:0; font:13px/1.5 var(--f-texto); background:var(--fundo); color:var(--texto); font-feature-settings:"tnum" 1; }
a{ color:var(--marca); text-decoration:none; } a:hover{ text-decoration:underline; }
code, pre{ font-family:var(--f-mono); font-size:12px; }
pre{ background:var(--cartao-2); border:1px solid var(--linha); padding:10px 12px; border-radius:var(--r-sm); overflow-x:auto; white-space:pre-wrap; word-break:break-all; }
select, input, textarea, button{ font:inherit; color:inherit; }
select, input, textarea{ background:var(--cartao); border:1px solid var(--linha-forte); border-radius:var(--r-sm); padding:6px 9px; min-width:160px; }
/* Botoes e campos numa altura so (--alt), o principal e o secundario do mesmo tamanho: muda a
   cor, nunca o tamanho. Botao pequeno (acao dentro de tabela): .pequeno */
select, input:not([type=checkbox]):not([type=radio]):not([type=color]):not([type=range]){ min-height:var(--alt); }
/* Caixa de marcar e opcao: sem a largura minima dos campos de texto */
input[type=checkbox], input[type=radio]{ min-width:0; width:auto; padding:0; accent-color:var(--marca); }
select:focus, input:focus, textarea:focus{ outline:2px solid rgba(29,111,242,.35); outline-offset:0; border-color:var(--marca); }
button, .botao{ display:inline-flex; align-items:center; justify-content:center; gap:6px; min-height:var(--alt); padding:0 14px; background:var(--marca); color:#fff; border:1px solid var(--marca); border-radius:var(--r-sm); font-size:13px; font-weight:500; line-height:1.2; cursor:pointer; text-decoration:none; }
button:hover, .botao:hover{ background:var(--marca-hover); text-decoration:none; }
button.discreto{ background:var(--cartao); color:var(--erro); border-color:var(--linha-forte); }
button.pequeno{ min-height:28px; padding:0 10px; font-size:12px; }
button.discreto:hover{ background:var(--cartao-2); }
button:disabled, button:disabled:hover{ background:var(--cartao-2); color:var(--suave); border-color:var(--linha-forte); cursor:not-allowed; }
button.discreto.neutro{ color:var(--texto); }
.ico{ flex:none; } button .ico{ vertical-align:-2px; }
.info{ display:inline-flex; flex:none; align-items:center; justify-content:center; width:15px; height:15px; margin-left:4px; color:var(--apagado); cursor:help; vertical-align:-3px; border-radius:50%; }
.info svg{ width:100%; height:100%; fill:none; stroke:currentColor; stroke-width:1.3; stroke-linecap:round; }
.info svg .ponto-i{ fill:currentColor; stroke:none; }
.nw{ white-space:nowrap; }
.info:hover, .info:focus-visible, .info[aria-describedby]{ color:var(--marca); outline:none; }
.info:focus-visible{ box-shadow:0 0 0 2px var(--realce); }
.dica{ position:fixed; z-index:60; max-width:300px; background:#111827; color:#fff; font-size:12px; line-height:1.45; padding:8px 11px; border-radius:8px; pointer-events:none; box-shadow:0 8px 24px rgba(0,0,0,.25); }
.dica b{ display:block; font-size:12.5px; font-weight:600; margin:0 0 3px; }
.dica span{ display:block; white-space:pre-line; color:#E5E7EB; }
:root[data-tema=escuro] .dica{ background:color-mix(in srgb, var(--base), #fff 16%); border:1px solid var(--linha-forte); }

/* Seletores (painel.js): todo select do painel no mesmo padrao do Produto. O <select> fica
   escondido no formulario; a lista abre embaixo (ou em cima) e, no celular, como painel de baixo */
.sel{ position:relative; min-width:0; max-width:100%; }
.sel-nativo{ position:absolute !important; width:1px !important; height:1px !important; min-width:0 !important; padding:0 !important; border:0 !important; opacity:0; pointer-events:none; }
.sel-botao, .multi > summary{ display:flex; align-items:center; justify-content:space-between; gap:10px; width:100%; min-width:160px; max-width:300px; min-height:var(--alt); padding:0 10px; background:var(--cartao); color:var(--texto); border:1px solid var(--linha-forte); border-radius:var(--r-sm); font:inherit; font-weight:400; text-align:left; cursor:pointer; }
.sel-botao:hover, .multi > summary:hover{ background:var(--cartao); border-color:var(--apagado); }
.sel-botao[aria-expanded=true], .multi[open] > summary{ border-color:var(--marca); box-shadow:0 0 0 3px var(--realce); }
.sel-botao:disabled{ background:var(--cartao-2); color:var(--apagado); cursor:not-allowed; }
.sel-texto{ overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.sel-seta{ flex:none; width:14px; height:14px; fill:none; stroke:var(--suave); stroke-width:1.8; stroke-linecap:round; stroke-linejoin:round; transition:transform .15s; }
.sel-botao[aria-expanded=true] .sel-seta{ transform:rotate(180deg); }
.sel-painel{ position:absolute; z-index:45; left:0; top:calc(100% + 4px); min-width:100%; width:max-content; max-width:min(380px, calc(100vw - 24px)); max-height:320px; overflow:auto; padding:5px; background:var(--cartao); border:1px solid var(--linha-forte); border-radius:10px; box-shadow:0 12px 32px var(--sombra); outline:none; }
.sel-painel.acima{ top:auto; bottom:calc(100% + 4px); }
.sel-painel[hidden], .sel-fundo[hidden]{ display:none !important; }
.sel-op{ display:flex; align-items:center; gap:8px; padding:7px 10px; border-radius:7px; color:var(--texto); font-size:13px; cursor:pointer; white-space:nowrap; }
.sel-op.ativo{ background:var(--hover); }
.sel-op[aria-selected=true]{ color:var(--marca); font-weight:600; }
.sel-op[aria-selected=true]::after{ content:""; margin-left:auto; width:6px; height:11px; border:solid var(--marca); border-width:0 2px 2px 0; transform:rotate(45deg) translate(-2px,-1px); }
.sel-op[aria-disabled=true]{ color:var(--apagado); cursor:default; }
.sel-fundo{ position:fixed; inset:0; z-index:44; background:rgba(15,23,42,.45); }
.sel-painel.folha, .periodo-painel.folha{ position:fixed; left:0; right:0; bottom:0; top:auto; width:auto; max-width:none; max-height:75vh; border-radius:16px 16px 0 0; padding:10px 10px calc(12px + env(safe-area-inset-bottom)); }
.sel-painel.folha .sel-op{ padding:13px 14px; font-size:15px; }
/* Periodo: prontos a esquerda e o calendario a direita (no celular, um embaixo do outro) */
.campo-periodo .sel-botao{ min-width:190px; }
.campo-periodo .sel-botao .ico{ flex:none; color:var(--suave); }
.periodo-painel{ display:flex; width:auto; max-width:calc(100vw - 24px); max-height:none; padding:0; overflow:visible; }
.periodo-prontos{ display:flex; flex-direction:column; gap:2px; min-width:150px; padding:8px; border-right:1px solid var(--linha); }
.periodo-prontos button{ justify-content:flex-start; min-height:0; background:none; border:0; color:var(--texto); text-align:left; padding:7px 10px; border-radius:7px; font-weight:400; }
.periodo-prontos button:hover{ background:var(--hover); }
.periodo-prontos button.atual{ background:var(--realce); color:var(--marca); font-weight:600; }
.periodo-cal{ padding:10px 12px 12px; }
.periodo-pe{ display:flex; align-items:center; gap:8px; margin-top:10px; padding-top:10px; border-top:1px solid var(--linha); }
.periodo-pe span{ margin-right:auto; color:var(--suave); font-size:12px; }
.cal{ width:252px; user-select:none; }
.cal-cab{ display:flex; align-items:center; justify-content:space-between; margin:0 0 6px; }
.cal-cab b{ font-size:13px; font-weight:600; }
.cal-cab button{ width:30px; height:30px; min-height:0; padding:0; background:none; border:0; color:var(--suave); border-radius:7px; font-size:18px; line-height:1; }
.cal-cab button:hover{ background:var(--hover); color:var(--texto); }
.cal-sem, .cal-dias{ display:grid; grid-template-columns:repeat(7,1fr); text-align:center; }
.cal-sem span{ font-size:11px; color:var(--apagado); padding:4px 0; }
.cal-dia{ height:34px; min-height:0; padding:0; margin:1px 0; background:none; border:0; border-radius:8px; color:var(--texto); font-weight:400; font-variant-numeric:tabular-nums; }
.cal-dia:hover:not(:disabled){ background:var(--hover); }
.cal-dia.fora{ color:var(--apagado); }
.cal-dia.hoje{ box-shadow:inset 0 0 0 1px var(--linha-forte); }
.cal-dia.no-intervalo{ background:var(--realce); border-radius:0; }
.cal-dia.inicio, .cal-dia.fim{ background:var(--marca); color:#fff; font-weight:600; }
.cal-dia.inicio{ border-radius:8px 0 0 8px; } .cal-dia.fim{ border-radius:0 8px 8px 0; } .cal-dia.inicio.fim{ border-radius:8px; }
.cal .cal-dia:disabled, .cal .cal-dia:disabled:hover{ background:none; color:var(--linha-forte); border:0; cursor:not-allowed; }
.campo-data .sel-botao{ min-width:150px; }
@media (max-width:640px){
  .periodo-painel.folha{ flex-direction:column; overflow:auto; }
  .periodo-prontos{ flex-direction:row; flex-wrap:wrap; border-right:0; border-bottom:1px solid var(--linha); }
  .periodo-prontos button{ padding:8px 12px; border:1px solid var(--linha); border-radius:999px; }
  .cal{ width:100%; } .cal-dia{ height:42px; }
  .multi[open] > summary::before{ content:""; position:fixed; inset:0; z-index:44; background:rgba(15,23,42,.45); }
  .multi-painel{ position:fixed; left:0; right:0; bottom:0; top:auto; z-index:45; width:auto; max-width:none; max-height:75vh; border-radius:16px 16px 0 0; padding:10px 10px calc(12px + env(safe-area-inset-bottom)); }
  .multi-painel label{ padding:12px 10px; font-size:15px; }
}
body.com-folha{ overflow:hidden; }
/* Topo: conta, foto e a seta que recolhe a barra de cima (lembrado num cookie) */
section[id], .cartao[id]{ scroll-margin-top:84px; }
/* Usuarios: o que cada um pode abrir */
form.acessos, fieldset.acessos{ display:flex; flex-wrap:wrap; align-items:center; gap:6px 16px; margin:0; }
fieldset.acessos{ border:1px solid var(--linha); border-radius:var(--r-sm); padding:8px 12px; }
fieldset.acessos legend{ padding:0 4px; font-size:12px; color:var(--suave); }
label.acesso{ display:inline-flex !important; flex-direction:row !important; align-items:center; gap:6px; font-size:13px !important; color:var(--texto) !important; cursor:pointer; }
.usuario-nome{ white-space:nowrap; } .usuario-nome .avatar{ vertical-align:middle; margin-right:6px; }
/* Navegacao suave: enquanto a tela nova chega, uma linha corre no alto e o conteudo esmaece */
.navegando body::after{ content:""; position:fixed; z-index:100; top:0; left:0; width:35%; height:2px; background:var(--marca); animation:navegando 1s ease-in-out infinite; }
.navegando main{ opacity:.6; transition:opacity .15s .12s; }
@keyframes navegando{ from{ transform:translateX(-100%); } to{ transform:translateX(300%); } }
@media (prefers-reduced-motion:reduce){ .navegando body::after{ animation:none; width:100%; opacity:.6; } }
/* Topo: filtros a esquerda, conta a direita */
.topo{ position:sticky; top:0; z-index:5; background:var(--cartao); border-bottom:1px solid var(--linha); padding:10px 24px; display:flex; flex-wrap:wrap; gap:12px; align-items:end; }
.topo label, .gestor-filtros label, .topo .campo{ display:flex; flex-direction:column; font-size:12px; color:var(--suave); gap:3px; }
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
.numeros{ display:grid; grid-template-columns:repeat(auto-fit,minmax(112px,1fr)); margin-bottom:20px; background:var(--cartao); border:1px solid var(--linha); border-radius:var(--r-md); overflow:hidden; }
.numero{ display:flex; flex-direction:column-reverse; justify-content:flex-end; gap:2px; padding:12px 12px; min-width:0; border-right:1px solid var(--linha); border-bottom:1px solid var(--linha); margin:0 -1px -1px 0; }
.numero b{ font-size:18px; font-weight:600; white-space:nowrap; }
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
/* Nas listas (Vendas, Eventos) o texto longo (conferencia, motivo) ganha mais largura: linhas mais baixas */
.tcard-corpo.lista td.quebra{ min-width:340px; }
.selo{ display:inline-block; padding:0 6px; border-radius:4px; font-size:12px; font-weight:500; line-height:20px; }
.selo.ok{ color:var(--ok); background:var(--fundo-ok); } .selo.alerta{ color:var(--alerta); background:var(--fundo-alerta); }
.selo.erro{ color:var(--erro); background:var(--fundo-erro); } .selo.neutro{ color:var(--suave); background:var(--cartao-2); }

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

/* Gestor de anuncios */
.gestor-niveis, .segmentos{ display:inline-flex; background:var(--cartao-2); border:1px solid var(--linha); border-radius:var(--r-sm); padding:2px; margin:0 0 14px; }
.gestor-niveis a, .segmentos a{ padding:5px 14px; border-radius:4px; color:var(--suave); font-weight:500; }
.gestor-niveis a:hover, .segmentos a:hover{ color:var(--texto); text-decoration:none; }
.gestor-niveis a.atual, .segmentos a.atual{ background:var(--cartao); color:var(--texto); box-shadow:0 0 0 1px var(--linha); }
.segmentos{ flex-wrap:wrap; margin:0; }
.segmentos a{ padding:4px 10px; }
/* Feed do Instagram (aba Organico) */
.feed-controles{ display:flex; flex-wrap:wrap; gap:8px 12px; margin:0 0 10px; }
.feed-legenda{ margin:0 0 12px; font-size:12px; color:var(--suave); }
.feed{ display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:12px; }
.post{ display:flex; flex-direction:column; border:1px solid var(--linha); border-radius:var(--r-md); overflow:hidden; background:var(--cartao); }
.post-capa{ position:relative; display:block; aspect-ratio:4/5; overflow:hidden; background:var(--cartao-2); }
.post-capa img{ position:absolute; inset:0; width:100%; height:100%; object-fit:cover; }
.post-capa .sem-capa{ position:absolute; inset:0; display:flex; align-items:center; justify-content:center; color:var(--apagado); }
.post-tipo, .post-vendas{ position:absolute; left:8px; padding:2px 7px; border-radius:4px; font-size:11px; font-weight:600; }
.post-tipo{ top:8px; background:rgba(17,24,39,.78); color:#fff; }
.post-vendas{ bottom:8px; background:#DCFCE7; color:#166534; }
.post-corpo{ display:flex; flex-direction:column; gap:6px; padding:10px 12px 12px; }
.post-data{ margin:0; font-size:12px; color:var(--suave); }
.post-legenda{ margin:0; font-size:12px; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
.post-num{ display:grid; grid-template-columns:1fr 1fr; gap:8px 12px; margin:2px 0 0; }
.post-num div{ min-width:0; }
.post-num dt{ font-size:11px; color:var(--suave); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.post-num dd{ margin:0; font-size:14px; font-weight:600; font-variant-numeric:tabular-nums; }
/* Gestor no padrao da UTMify: os niveis com icone e a atualizacao na mesma linha; a barra da
   tabela numa linha so (nome, status, comparar e os botoes de icone); o menu Acoes das marcadas,
   o grafico comparativo e o modo foco */
.gestor-topo{ display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:8px 16px; margin:0 0 14px; }
.gestor-topo .gestor-niveis, .gestor-topo .barra-vendas{ margin:0; }
.gestor-niveis a{ display:inline-flex; align-items:center; gap:7px; }
.gestor-niveis .ico{ color:var(--apagado); } .gestor-niveis a.atual .ico{ color:var(--marca); }
.gestor-filtros{ display:flex; flex-wrap:wrap; gap:10px; align-items:end; margin:0 0 14px; }
.gestor-cab{ padding:10px 12px; gap:8px; }
.gestor-cab .gestor-filtros{ align-items:center; gap:8px; margin:0; }
.gestor-cab .tcard-busca input{ width:230px; }
.gestor-cab .tcard-busca{ flex-direction:row; gap:0; }
/* Tabela inteligente: o corpo encosta no cabecalho e no rodape do cartao (sem a margem da .tabela) */
.tabela.tcard-corpo{ margin:0; border:0; border-radius:0; }
.gestor-cab .gestor-campo{ display:inline-flex; flex-direction:row; align-items:center; gap:6px; margin:0; font-size:12px; color:var(--suave); white-space:nowrap; }
.gestor-ferr{ gap:6px; }
.gestor-ferr .colunas summary{ width:var(--alt); padding:0; justify-content:center; }
.gestor-ferr .colunas summary span{ display:none; }
.gestor-menu > summary{ width:var(--alt); height:var(--alt); border:1px solid var(--linha-forte); background:var(--cartao); }
.gestor-menu > summary:hover, .gestor-menu[open] > summary{ color:var(--marca); border-color:var(--marca); background:var(--hover); }
.gestor-acoes .menu-linha-painel{ width:min(290px, calc(100vw - 16px)); }
.gestor-marcadas{ display:inline-flex; align-items:center; gap:4px; height:var(--alt); padding:0 3px 0 11px; border:1px solid var(--marca); border-radius:999px; background:var(--realce); color:var(--marca); font-size:12px; white-space:nowrap; }
.gestor-marcadas button{ min-height:26px; width:26px; padding:0; border-radius:999px; color:var(--marca); }
.botao-icone[aria-pressed=true]{ color:var(--marca); border-color:var(--marca); background:var(--realce); }
.menu-nota{ margin:0; padding:6px 10px 8px; color:var(--suave); font-size:12px; line-height:1.4; }
.menu-linha-painel hr{ margin:5px 0; border:0; border-top:1px solid var(--linha); }
.menu-linha-painel button:disabled, .menu-linha-painel [aria-disabled=true]{ opacity:.45; cursor:not-allowed; background:none; }
.menu-linha-painel a.atual{ font-weight:600; } .menu-linha-painel a.atual .ico{ color:var(--marca); }
.orc-massa{ display:flex; flex-direction:column; gap:7px; padding:6px 10px 8px; }
.orc-massa-modo{ display:flex; gap:14px; }
.orc-massa-modo label{ display:inline-flex; align-items:center; gap:5px; margin:0; color:var(--texto); font-size:12px; }
.orc-massa-linha{ display:flex; gap:6px; } .orc-massa-linha input{ flex:1; min-width:0; }
.menu-linha-painel .orc-massa button{ width:auto; justify-content:center; padding:0 14px; background:var(--marca); color:#fff; font-weight:600; }
.menu-linha-painel .orc-massa button:hover{ background:var(--marca); filter:brightness(1.08); }
.orc-massa small{ font-size:11px; line-height:1.4; }
/* O "..." da linha aparece ao passar o mouse, ao lado da analise diaria (no toque, sempre) */
.tabela.gestor .linha-acoes{ display:inline-flex; align-items:center; gap:2px; margin-left:8px; vertical-align:-6px; }
.tabela.gestor .linha-acoes a.analise{ margin-left:0; }
.tabela.gestor .linha-acoes .menu-linha > summary{ width:24px; height:22px; }
.tabela.gestor .linha-acoes .menu-linha{ opacity:0; transition:opacity .12s; }
.tabela.gestor tr:hover .linha-acoes .menu-linha, .tabela.gestor .linha-acoes .menu-linha[open], .tabela.gestor .linha-acoes .menu-linha:focus-within{ opacity:1; }
@media (hover:none){ .tabela.gestor .linha-acoes .menu-linha{ opacity:1; } }
.tabela.gestor tr.marcada > td{ background:var(--realce); }
.tabela.gestor tr.fixada > td.nome{ box-shadow:inset 3px 0 0 var(--marca); }
.tcard-corpo tfoot td{ position:sticky; bottom:0; z-index:2; }
/* Grafico comparativo das marcadas */
.gestor-grafico{ padding:12px 16px 14px; border-bottom:1px solid var(--linha); }
.gg-cab{ display:flex; flex-wrap:wrap; align-items:center; gap:8px 12px; margin:0 0 8px; }
.gg-cab strong{ display:inline-flex; align-items:center; gap:6px; font-size:14px; }
.gg-cab [data-grafico-fecha]{ margin-left:auto; }
.gg-metricas button{ min-height:28px; padding:0 10px; border:0; border-radius:4px; background:none; color:var(--suave); font-size:12px; font-weight:500; }
.gg-metricas button:hover{ color:var(--texto); background:none; }
.gg-metricas button[aria-pressed=true]{ background:var(--cartao); color:var(--texto); box-shadow:0 0 0 1px var(--linha); }
.gg-acum{ display:inline-flex !important; flex-direction:row !important; align-items:center; gap:6px; margin:0; font-size:12px; color:var(--suave); }
.gg-svg{ display:block; width:100%; height:auto; max-height:300px; }
.gg-grade{ stroke:var(--linha); stroke-width:1; } .gg-zero{ stroke:var(--linha-forte); stroke-width:1; stroke-dasharray:4 3; }
.gg-eixo{ fill:var(--suave); font-size:11px; }
.gg-linha{ fill:none; stroke:var(--gg); stroke-width:2; stroke-linejoin:round; stroke-linecap:round; }
.gg-ponto{ fill:var(--cartao); stroke:var(--gg); stroke-width:2; }
.gg-legenda{ display:flex; flex-wrap:wrap; gap:6px 18px; margin:8px 0 0; padding:0; list-style:none; font-size:12px; }
.gg-legenda li{ display:inline-flex; align-items:center; gap:6px; }
.gg-legenda i{ width:10px; height:10px; border-radius:3px; background:var(--gg); }
.gg-legenda b{ font-weight:600; font-variant-numeric:tabular-nums; }
.gg-c0{ --gg:#2563EB; } .gg-c1{ --gg:#16A34A; } .gg-c2{ --gg:#EA580C; } .gg-c3{ --gg:#9333EA; } .gg-c4{ --gg:#DB2777; }
/* Painel editavel (lib/painel.php). Ver: CSS Grid com a posicao de cada cartao (--c --r --w --h no
   computador, --mc --mr --mw --mh no celular). Editar: GridStack, a biblioteca do lado e a barra */
.painel-cab{ display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:8px 16px; }
.painel-cab h2{ margin:0; }
.painel-cab .barra-vendas{ margin:0; }
.painel-grade{ display:grid; grid-template-columns:repeat(12, minmax(0, 1fr)); grid-auto-rows:110px; gap:12px; }
.painel-item{ grid-column:var(--c) / span var(--w); grid-row:var(--r) / span var(--h); min-width:0; min-height:0; }
.painel-item.so-celular{ display:none; }
@media (max-width:767px){
  .painel-grade{ grid-template-columns:repeat(2, minmax(0, 1fr)); gap:10px; }
  .painel-item{ grid-column:var(--mc) / span var(--mw); grid-row:var(--mr) / span var(--mh); }
  .painel-item.so-computador{ display:none; }
  .painel-item.so-celular{ display:block; }
}
.pw{ display:flex; flex-direction:column; gap:6px; height:100%; min-height:0; padding:14px 16px; background:var(--cartao); border:1px solid var(--linha); border-radius:var(--r-md); overflow:hidden; }
.pw-cab{ display:flex; justify-content:space-between; align-items:flex-start; gap:8px; color:var(--suave); font-size:13px; font-weight:500; flex:none; }
.pw-tit{ display:inline-flex; align-items:center; gap:6px; min-width:0; }
.pw-tit > span:first-child{ overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.pw-corpo{ flex:1; min-height:0; overflow:auto; }
.pw-numero .pw-corpo{ display:flex; flex-direction:column; justify-content:flex-end; overflow:hidden; }
.pw-num{ font-size:24px; font-weight:600; line-height:1.2; font-variant-numeric:tabular-nums; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.pw-corpo > small{ color:var(--suave); font-size:12px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.pw-corpo .grafico{ width:100%; height:auto; }
.pw-corpo .rosca-caixa{ margin:0; }
.barra-ok{ fill:var(--ok); } .barra-ruim{ fill:var(--erro); }
.painel-barra{ position:sticky; top:0; z-index:40; display:flex; flex-wrap:wrap; align-items:center; gap:8px 14px; margin:0 0 10px; padding:10px 14px; background:var(--realce); border:1px solid var(--marca); border-radius:var(--r-md); }
.painel-barra-txt{ display:inline-flex; align-items:center; gap:6px; font-weight:500; }
.painel-barra .segmentos a{ display:inline-flex; align-items:center; gap:6px; }
.painel-barra-acoes{ display:inline-flex; flex-wrap:wrap; gap:8px; margin-left:auto; }
.painel-dica{ margin:0 0 12px; font-size:12px; }
.painel-edicao{ display:grid; grid-template-columns:260px minmax(0, 1fr); gap:16px; align-items:start; }
.painel-biblioteca{ position:sticky; top:72px; max-height:calc(100vh - 90px); overflow:auto; padding:12px; background:var(--cartao); border:1px solid var(--linha); border-radius:var(--r-md); }
.painel-biblioteca h2{ margin:0 0 10px; font-size:14px; }
.painel-biblioteca .tcard-busca{ margin:0 0 10px; }
.painel-biblioteca .tcard-busca input{ width:100%; }
.painel-biblioteca details{ margin:0 0 8px; }
.painel-biblioteca summary{ cursor:pointer; padding:6px 2px; color:var(--suave); font-size:12px; font-weight:600; text-transform:uppercase; letter-spacing:.03em; }
.painel-lista{ display:flex; flex-direction:column; gap:6px; }
.painel-novo{ position:static !important; width:auto !important; height:auto !important; cursor:grab; }
.painel-novo > .grid-stack-item-content{ position:static !important; display:flex; align-items:center; gap:8px; padding:8px 10px; border:1px dashed var(--linha-forte); border-radius:var(--r-sm); background:var(--cartao-2); font-size:13px; }
.painel-novo:hover > .grid-stack-item-content{ border-color:var(--marca); border-width:2px; padding:7px 9px; }
.painel-novo-ico{ display:inline-flex; color:var(--suave); }
.painel-novo.fora-da-busca{ display:none !important; }
.painel-novo.usado{ pointer-events:none; cursor:default; }
.painel-novo.usado > .grid-stack-item-content{ opacity:.45; border-style:solid; }
.painel-novo-usado{ margin-left:auto; color:var(--suave); font-size:11px; }
.pw-aviso{ display:flex; align-items:center; gap:5px; margin-top:auto; color:var(--suave); font-size:12px; white-space:normal !important; }
.pw-sem-fonte .pw-corpo{ display:flex; flex-direction:column; justify-content:flex-end; }
.painel-moldura{ min-width:0; }
.painel-moldura.celular{ width:390px; max-width:100%; margin:0 auto; padding:10px; border:1px solid var(--linha-forte); border-radius:24px; background:var(--fundo); }
.painel-moldura .grid-stack{ min-height:400px; border:1px dashed var(--linha); border-radius:var(--r-md); }
.painel-moldura .grid-stack-item-content{ overflow:hidden !important; }
.painel-moldura .grid-stack-item > .grid-stack-item-content{ cursor:move; }
.painel-tirar{ position:absolute; top:8px; right:8px; z-index:5; width:26px; min-height:26px; padding:0; border-radius:999px; background:var(--cartao); color:var(--suave); border:1px solid var(--linha-forte); opacity:0; transition:opacity .12s; }
.painel-tirar:hover{ background:var(--fundo-erro); color:var(--erro); border-color:var(--erro); }
.grid-stack-item:hover .painel-tirar, .painel-tirar:focus-visible{ opacity:1; }
.grid-stack-item:hover .pw .kpi-ico{ visibility:hidden; }
@media (hover:none){ .painel-tirar{ opacity:1; } }
.grid-stack-placeholder > .placeholder-content{ background:var(--realce) !important; border:2px dashed var(--marca); border-radius:var(--r-md); }
@media (max-width:900px){ .painel-edicao{ grid-template-columns:1fr; } .painel-biblioteca{ position:static; max-height:none; } }
/* Painel da Bio (bio.php) */
.bio-topo{ display:flex; flex-wrap:wrap; align-items:center; gap:8px 14px; margin:0 0 14px; }
.bio-ordem{ display:inline-flex; gap:4px; }
.bio-ordem .tcard-icone{ min-height:28px; width:28px; }
.bio-form{ display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:12px 16px; align-items:end; }
.bio-form label{ display:flex; flex-direction:column; gap:4px; margin:0; font-size:12px; color:var(--suave); }
.bio-form label.caixa{ flex-direction:row; align-items:center; gap:8px; color:var(--texto); font-size:13px; }
.bio-form .linha-botoes{ grid-column:1 / -1; margin:0; }
/* Modo foco: a tela fica so com o gestor (sem a barra lateral, as abas, o ranking e o historico) */
html.modo-foco .lateral, html.modo-foco .abas, html.modo-foco .nav-celular, html.modo-foco .gestor-pos{ display:none !important; }
html.modo-foco .gestor-card .tcard-corpo{ max-height:calc(100vh - 220px); }
.colunas{ position:relative; }
.colunas summary{ list-style:none; display:inline-flex; align-items:center; gap:6px; cursor:pointer; min-height:var(--alt); padding:0 14px; border:1px solid var(--linha-forte); border-radius:var(--r-sm); background:var(--cartao); color:var(--texto); font-size:13px; }
.colunas summary:hover{ border-color:var(--apagado); }
.colunas summary .ico{ color:var(--suave); }
.colunas summary::-webkit-details-marker{ display:none; }
.colunas[open] > summary::before{ content:""; position:fixed; inset:0; z-index:65; background:rgba(15,23,42,.45); cursor:default; }
.colunas-painel{ position:fixed; z-index:70; left:50%; top:50%; transform:translate(-50%,-50%); width:min(820px, calc(100vw - 32px)); max-height:min(82vh, 760px); display:grid; grid-template-columns:1fr 1fr; grid-template-rows:auto minmax(0,1fr) auto; gap:12px 18px; padding:16px 18px; background:var(--cartao); border:1px solid var(--linha-forte); border-radius:var(--r-md); box-shadow:0 10px 30px var(--sombra); }
.colunas-cab{ grid-column:1 / -1; display:flex; flex-direction:column; gap:2px; }
.colunas-cab strong{ font-size:15px; }
.colunas-modelos{ display:flex; flex-wrap:wrap; align-items:center; gap:6px; margin-top:8px; font-size:12px; }
button.chip{ min-height:28px; padding:0 12px; border:1px solid var(--linha-forte); border-radius:999px; background:var(--cartao-2); color:var(--texto); font-size:12px; font-weight:500; }
button.chip:hover{ border-color:var(--apagado); background:var(--cartao-2); }
button.chip[aria-pressed=true]{ border-color:var(--marca); background:var(--realce); color:var(--marca); }
.colunas-lista, .colunas-escolhidas{ min-height:0; overflow:auto; }
.colunas-lista input[type=search]{ width:100%; margin-bottom:8px; }
.colunas-lista label{ display:flex; align-items:flex-start; gap:8px; padding:6px 4px; border-radius:var(--r-sm); cursor:pointer; }
.colunas-lista label:hover{ background:var(--hover); }
.colunas-lista label input{ flex:none; margin-top:3px; }
.colunas-lista b{ display:block; font-weight:600; font-size:13px; }
.colunas-lista small{ display:block; color:var(--suave); font-size:11px; line-height:1.35; }
.colunas-fixa, .colunas-escolhidas li{ display:flex; align-items:center; gap:6px; padding:6px 8px; margin:0 0 6px; border:1px solid var(--linha); border-radius:var(--r-sm); background:var(--cartao-2); font-size:13px; }
.colunas-fixa{ color:var(--suave); padding-left:30px; }
.colunas-escolhidas ol{ list-style:none; margin:0; padding:0; }
.colunas-escolhidas li > span:nth-child(2){ flex:1; }
.colunas-escolhidas li.arrastando{ opacity:.45; }
.colunas-escolhidas .alca{ width:16px; color:var(--apagado); cursor:grab; }
.colunas-escolhidas li button{ min-height:26px; padding:0 8px; font-size:12px; }
.colunas-escolhidas li button.neutro{ color:var(--suave); }
.colunas-escolhidas li button.neutro:hover{ color:var(--texto); }
.colunas-pe{ grid-column:1 / -1; display:flex; align-items:center; gap:8px; justify-content:flex-end; border-top:1px solid var(--linha); padding-top:12px; }
.colunas-pe a{ margin-right:auto; font-size:13px; }
@media (max-width:640px){ .colunas-painel{ grid-template-columns:1fr; top:auto; bottom:0; left:0; transform:none; width:100%; max-height:85vh; border-radius:16px 16px 0 0; } }
.tabela.gestor td, .tabela.gestor th{ text-align:right; } .tabela.gestor .nome, .tabela.gestor .st{ text-align:left; }
/* Caixa de marcar e chave de status: colunas estreitas e fixas (sem arrastar a largura) */
.tabela.gestor .marca{ width:48px; min-width:48px; max-width:48px; padding-left:16px; padding-right:12px; text-align:center; }
.tabela.gestor .marca input{ margin:0; vertical-align:middle; }
.tabela.gestor .st{ width:1%; white-space:nowrap; padding-left:12px; padding-right:16px; }
/* Largura das colunas: a linha aparece na borda do titulo ao passar o mouse; arrastar muda */
.tabela.gestor th{ position:relative; }
.redim{ position:absolute; top:0; right:-4px; width:9px; height:100%; cursor:col-resize; z-index:2; touch-action:none; }
.redim::after{ content:""; position:absolute; left:4px; top:18%; bottom:18%; width:1px; background:transparent; }
.tabela.gestor th:hover .redim::after{ background:var(--linha-forte); }
.redim:hover::after, .redim.ativo::after{ background:var(--marca) !important; width:2px; left:3px; top:0; bottom:0; }
.tabela.gestor th.largura-fixa, .tabela.gestor td.largura-fixa{ overflow:hidden; text-overflow:ellipsis; }
.tabela.gestor td.quebra{ min-width:200px; }
.tabela.gestor tr.total td{ background:var(--cartao-2); font-weight:600; }
.positivo{ color:var(--ok); } .negativo{ color:var(--erro); } .medio{ color:var(--laranja); }
/* Gestor: botao da analise diaria, que aparece ao passar o mouse na campanha (no toque, sempre) */
/* Botao da analise diaria: so o icone do grafico (o nome vem na dica), na linha ao passar o mouse */
a.analise{ display:inline-flex; align-items:center; justify-content:center; width:24px; height:22px; margin-left:8px; border:1px solid var(--linha-forte); border-radius:var(--r-sm); background:var(--cartao); color:var(--suave); vertical-align:-5px; opacity:0; transition:opacity .12s, color .12s, border-color .12s; }
a.analise:hover{ color:var(--marca); border-color:var(--marca); text-decoration:none; }
.tabela.gestor tr:hover a.analise, a.analise:focus-visible{ opacity:1; }
@media (hover:none){ a.analise{ opacity:1; } }
/* Analise diaria da campanha: um dia por linha (como a planilha); hoje, ao vivo, por ultimo */
.campanha-cab{ margin:0 0 14px; }
.campanha-cab p{ margin:8px 0 0; }
.campanha-topo{ display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; gap:8px 16px; }
.campanha-topo .barra-vendas{ margin:0; }
.campanha-nome{ display:flex; flex-wrap:wrap; align-items:center; gap:4px 10px; min-width:0; }
.campanha-nome h2{ margin:0; font-size:17px; }
/* Orcamento na linha do nome: o lapis aparece ao passar o mouse (no toque, sempre) */
.campanha-orc{ display:inline-flex; align-items:center; gap:5px; padding:3px 8px; border:1px solid transparent; border-radius:var(--r-sm); color:var(--suave); font-size:13px; white-space:nowrap; }
.campanha-orc b{ color:var(--texto); font-weight:600; }
.campanha-orc small{ font-size:12px; }
summary.campanha-orc{ list-style:none; cursor:pointer; }
summary.campanha-orc::-webkit-details-marker{ display:none; }
summary.campanha-orc .ico{ color:var(--suave); opacity:0; transition:opacity .12s; }
.campanha-cab:hover summary.campanha-orc .ico, summary.campanha-orc:focus-visible .ico{ opacity:1; }
summary.campanha-orc:hover{ border-color:var(--linha-forte); background:var(--cartao-2); }
summary.campanha-orc:hover .ico{ color:var(--marca); }
@media (hover:none){ summary.campanha-orc .ico{ opacity:1; } }
.orc-inline[open] > summary{ display:none; }
.orc-inline-form{ display:flex; flex-wrap:wrap; align-items:center; gap:6px; }
.orc-inline-form input[name=valor]{ width:110px; min-width:0; padding:0 8px; font-weight:600; }
.orc-inline-form .sel{ max-width:260px; }
.orc-inline-form button{ width:var(--alt); height:var(--alt); padding:0; }
.campanha-linha{ display:flex; flex-wrap:wrap; align-items:center; gap:6px 16px; margin-top:10px; font-size:13px; }
.campanha-linha code{ font-size:12px; }
.campanha-linha .colunas{ margin-left:auto; }
.tabela.gestor td.dia, .tabela.gestor th.dia{ text-align:left; }
.tabela.gestor tr.total td.dia{ font-weight:600; }
.tabela.gestor tr.ao-vivo-linha td{ background:var(--hover); }
/* Analise diaria, em cima: graficos (velas como as de bolsa, compradores e ROI) e a leitura */
.rgrade.analise .rc{ min-height:0; }
.grafico.velas, .grafico.compradores{ margin-top:6px; }
.grafico .vela-alvo{ fill:transparent; cursor:help; }
.grafico .vela:hover .vela-alvo, .grafico .dia-graf:hover .vela-alvo, .grafico .vela:focus .vela-alvo, .grafico .dia-graf:focus .vela-alvo{ fill:var(--hover); }
.grafico .vela:focus, .grafico .dia-graf:focus{ outline:none; }
.grafico .vela.sobe rect:not(.vela-alvo){ fill:var(--ok); } .grafico .vela.desce rect:not(.vela-alvo){ fill:var(--erro); }
.grafico .vela.sobe line{ stroke:var(--ok); } .grafico .vela.desce line{ stroke:var(--erro); }
.grafico .vela line{ stroke-width:1.2; }
.grafico .ref-1{ stroke:var(--erro); stroke-width:1; stroke-dasharray:3 3; opacity:.6; }
.grafico .ref-2{ stroke:var(--ok); stroke-width:1; stroke-dasharray:3 3; opacity:.6; }
.grafico .linha-roi{ fill:none; stroke:var(--suave); stroke-width:1.5; stroke-linejoin:round; pointer-events:none; }
.grafico .ponto{ fill:var(--apagado); pointer-events:none; } .grafico .ponto.positivo{ fill:var(--ok); } .grafico .ponto.medio{ fill:var(--laranja); } .grafico .ponto.negativo{ fill:var(--erro); }
.grafico .dia-graf .barra{ fill:url(#grad-azul-v); opacity:.9; }
.leitura{ list-style:none; margin:4px 0 0; padding:0; display:flex; flex-direction:column; gap:9px; font-size:13px; line-height:1.4; }
.leitura li{ position:relative; padding-left:16px; }
.leitura li::before{ content:""; position:absolute; left:0; top:6px; width:8px; height:8px; border-radius:50%; background:var(--apagado); }
.leitura li.ok::before{ background:var(--ok); } .leitura li.alerta::before{ background:var(--laranja); } .leitura li.erro::before{ background:var(--erro); } .leitura li.neutro::before{ background:var(--marca); }
.analise-sub{ margin:6px 0 0; font-size:12px; }
/* Retencao do video (analise do anuncio): barras com o gradiente, de quem deu play ate o fim */
.retencao{ list-style:none; margin:4px 0 8px; padding:0; display:flex; flex-direction:column; gap:9px; }
.retencao li{ display:grid; grid-template-columns:78px 1fr 44px; align-items:center; gap:10px; font-size:13px; cursor:default; }
.retencao li > span:first-child{ color:var(--suave); }
.retencao b{ text-align:right; font-weight:600; }
.ret-barra{ height:12px; border-radius:6px; background:var(--cartao-2); overflow:hidden; }
.ret-barra i{ display:block; height:100%; border-radius:6px; background:linear-gradient(90deg, var(--grad-a), var(--grad-b)); }
/* Orcamento na analise diaria: mudar agora e programar lado a lado */
.orcamento h3{ font-size:13px; margin:16px 0 8px; }
.orc-novo > summary{ list-style:none; display:inline-flex; align-items:center; gap:6px; min-height:var(--alt); padding:0 14px; border:1px solid var(--marca); border-radius:var(--r-sm); background:var(--marca); color:#fff; font-size:13px; font-weight:500; cursor:pointer; }
.orc-novo > summary::-webkit-details-marker{ display:none; }
.orc-novo > summary:hover{ background:var(--marca-hover); }
.orc-novo[open] > summary{ background:var(--cartao-2); color:var(--texto); border:1px solid var(--linha-forte); }
.orc-novo .orc-form{ max-width:620px; margin-top:10px; }
.orc-grade{ display:grid; grid-template-columns:repeat(auto-fit,minmax(300px,1fr)); gap:12px 24px; }
.orc-form{ display:flex; flex-direction:column; gap:8px; padding:12px 14px; border:1px solid var(--linha); border-radius:var(--r-md); background:var(--cartao-2); }
.orc-form h3{ margin:0 0 2px; }
.orc-form label{ display:flex; flex-direction:column; gap:3px; font-size:12px; color:var(--suave); }
.orc-form select, .orc-form input:not([type=checkbox]):not([type=radio]){ width:100%; min-width:0; }
.orc-form button{ align-self:flex-start; }
.orc-linha{ display:flex; flex-wrap:wrap; gap:8px 12px; }
.orc-linha > label:not(.orc-op){ flex:1 1 120px; }
.orc-dias{ display:flex; flex-wrap:wrap; gap:4px 12px; }
.orc-form label.orc-op{ flex-direction:row; align-items:center; gap:5px; color:var(--texto); font-size:13px; }
/* Analise diaria: justa, para as 17 colunas da planilha caberem na tela sem rolar para o lado */
.tabela.dias table{ font-size:12px; }
.tabela.dias th, .tabela.dias td{ padding:6px 5px; }
.tabela.dias th{ white-space:normal; line-height:1.25; vertical-align:bottom; }
.tabela.dias th:first-child, .tabela.dias td:first-child{ padding-left:12px; }
.tabela.dias th:last-child, .tabela.dias td:last-child{ padding-right:12px; }
.tabela.dias .info{ width:13px; height:13px; }
.tabela.dias th .info{ display:flex; margin:3px 0 0 auto; } .tabela.dias th:first-child .info{ margin-left:0; }
.tabela.dias td.dia{ white-space:nowrap; }
/* Titulo que ordena na tela: botao com cara de texto e a seta da ordem */
button.ordena{ display:inline; min-height:0; padding:0; border:0; background:none; color:inherit; font:inherit; font-weight:inherit; text-align:inherit; cursor:pointer; }
button.ordena:hover{ background:none; color:var(--texto); }
th[aria-sort] > button.ordena{ color:var(--texto); font-weight:600; }
th[aria-sort=ascending] > button.ordena::after{ content:" ↑"; }
th[aria-sort=descending] > button.ordena::after{ content:" ↓"; }
.tabela.dias .ao-vivo{ display:flex; margin:1px 0 0; font-size:11px; }
.ao-vivo{ display:inline-flex; align-items:center; gap:5px; margin-left:6px; color:var(--ok); font-size:12px; font-weight:600; }
.ao-vivo::before{ content:""; width:7px; height:7px; border-radius:50%; background:var(--ok); animation:pulsar 1.6s ease-in-out infinite; }
@keyframes pulsar{ 50%{ opacity:.35; } }
@media (prefers-reduced-motion:reduce){ .ao-vivo::before{ animation:none; } }
/* Financeiro: formulario da despesa na largura toda (como os outros blocos), campos numa grade */
.fin-form{ margin-top:16px; }
.fin-form h2{ margin:0 0 12px; }
.fin-campos{ display:grid; grid-template-columns:minmax(220px,2fr) repeat(5,minmax(130px,1fr)); gap:12px; align-items:end; margin:0 0 14px; }
.fin-campos label{ display:flex; flex-direction:column; gap:4px; min-width:0; font-size:12px; color:var(--suave); }
.fin-campos input, .fin-campos select{ width:100%; min-width:0; }
.fin-campos [hidden]{ display:none; }
@media (max-width:1100px){ .fin-campos{ grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); } .fin-campos .fin-desc{ grid-column:1 / -1; } }
.form-linha{ display:inline; margin:0 0 0 8px; }
/* Filtro de periodo: as duas datas so aparecem no "De uma data a outra" */
.datas{ display:contents; } .datas[hidden]{ display:none; } [data-so][hidden]{ display:none !important; }
.topo input[type=date]{ min-width:0; }
/* Filtro de produto do topo: varios de uma vez, numa lista que abre como um select */
.multi{ position:relative; }
.multi > summary{ list-style:none; position:relative; padding-right:30px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.multi > summary::-webkit-details-marker{ display:none; }
.multi > summary::after{ content:""; position:absolute; right:12px; top:50%; width:6px; height:6px; border-right:1.8px solid var(--suave); border-bottom:1.8px solid var(--suave); transform:translateY(-70%) rotate(45deg); }

.multi-painel{ position:absolute; z-index:45; left:0; top:calc(100% + 4px); min-width:100%; width:max-content; max-width:min(380px, calc(100vw - 24px)); max-height:60vh; overflow:auto; padding:5px; background:var(--cartao); border:1px solid var(--linha-forte); border-radius:10px; box-shadow:0 12px 32px var(--sombra); color:var(--texto); font-size:13px; }
.multi-painel label{ display:flex; flex-direction:row; align-items:center; gap:8px; padding:6px 8px; border-radius:var(--r-sm); color:var(--texto); font-size:13px; cursor:pointer; }
.multi-painel label:hover{ background:var(--hover); }
.multi-painel .multi-todos{ font-weight:600; border-bottom:1px solid var(--linha); border-radius:var(--r-sm) var(--r-sm) 0 0; margin-bottom:4px; padding-bottom:9px; }
.sel-multi .multi-painel{ max-height:60vh; }
.tabela.gestor a.ordena{ color:inherit; } .tabela.gestor a.ordena.atual{ color:var(--texto); font-weight:600; }
.tabela.gestor a.abre{ color:var(--texto); } .tabela.gestor a.abre:hover strong{ color:var(--marca); text-decoration:underline; }
.delta{ font-size:11px; color:var(--suave); white-space:nowrap; } .delta.bom{ color:var(--ok); } .delta.ruim{ color:var(--erro); } .delta.novo{ color:var(--marca); }
/* Gestor: comparar com (no alto) e o ranking (painel de bolsa) */
@media (max-width:640px){ .sel-botao, .multi > summary, .campo-periodo .sel-botao, .campo-data .sel-botao{ min-width:0; } }
.ranking-bloco{ margin:0 0 16px; }
.ranking-criterios{ margin:2px 0 6px; align-self:flex-start; }
.ranking{ list-style:none; margin:0; padding:0; }
.ranking li{ display:grid; grid-template-columns:36px 52px minmax(0,1fr) 116px 76px 110px; align-items:center; gap:10px; padding:8px 2px; border-bottom:1px solid var(--linha); font-size:13px; font-variant-numeric:tabular-nums; }
.ranking li:last-child{ border-bottom:0; }
.ranking .rk-cab{ padding-top:2px; font-size:12px; color:var(--suave); }
.ranking .rk-num{ text-align:right; }
.ranking b.rk-num{ font-size:14px; font-weight:600; }
.rk-pos{ font-weight:600; color:var(--suave); }
.ranking li:nth-child(2) .rk-pos{ color:var(--texto); }
.rk-nome{ display:flex; align-items:baseline; gap:6px; min-width:0; }
.rk-nome a, .rk-nome strong{ overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.rk-nome small{ overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.rk-st{ flex:none; width:7px; height:7px; border-radius:50%; background:var(--linha-forte); align-self:center; }
.rk-st.on{ background:var(--ok); }
.rk-curva{ display:block; width:100%; height:28px; overflow:visible; }
.rk-curva polyline{ fill:none; stroke:var(--apagado); stroke-width:1.5; stroke-linejoin:round; stroke-linecap:round; }
.rk-curva.bom polyline{ stroke:var(--ok); } .rk-curva.ruim polyline{ stroke:var(--erro); }
.rk-zero{ stroke:var(--linha-forte); stroke-width:1; stroke-dasharray:2 2; vector-effect:non-scaling-stroke; }
@media (max-width:760px){
  .ranking li{ grid-template-columns:30px minmax(0,1fr) auto; grid-template-areas:"pos nome val" "mov graf var"; row-gap:2px; }
  .ranking .rk-cab{ display:none; }
  .rk-pos{ grid-area:pos; } .rk-nome{ grid-area:nome; } .ranking b.rk-num{ grid-area:val; }
  .rk-mov{ grid-area:mov; } .rk-graf{ grid-area:graf; } .ranking span.rk-num{ grid-area:var; }
}
.trilha{ margin:0 0 12px; }
/* Botao so com icone (Atualizar): quadrado, gira enquanto busca */
.botao-icone{ display:inline-flex; align-items:center; justify-content:center; width:var(--alt); height:var(--alt); padding:0; background:var(--cartao); color:var(--suave); border:1px solid var(--linha-forte); border-radius:var(--r-sm); }
.botao-icone:hover{ background:var(--hover); color:var(--marca); border-color:var(--marca); }
.botao-icone.girando svg{ animation:girar .8s linear infinite; }
@keyframes girar{ to{ transform:rotate(360deg); } }
@media (prefers-reduced-motion:reduce){ .botao-icone.girando svg{ animation:none; } }
/* Aparencia: a paleta no topo abre os temas prontos e a cor livre (como na UTMify) */
.cartao .tema-form{ max-width:340px; }
/* Link do nome no topo: Configuracoes */
a.conta-nome{ display:inline-flex; align-items:center; gap:6px; color:var(--texto); text-decoration:none; padding:4px 8px; border-radius:var(--r-sm); }
a.conta-nome:hover{ background:var(--hover); text-decoration:none; }
a.conta-nome .conta-cfg{ color:var(--suave); font-size:12px; }
@media (max-width:640px){ a.conta-nome .conta-cfg{ display:none; } }
/* Configuracoes: perfil (foto) e o Sair */
.perfil-linha{ display:flex; align-items:center; gap:16px; margin:0 0 14px; }
.perfil-linha b{ display:block; font-size:15px; margin:0 0 4px; }
.perfil-linha form{ display:inline-flex; }
/* Foto do perfil: o lapis na borda da bolinha troca a foto */
.perfil-foto{ position:relative; display:inline-flex; flex:none; margin:0; }
.perfil-foto .avatar{ box-shadow:0 0 0 1px var(--linha); }
.perfil-lapis{ position:absolute; right:-2px; bottom:-2px; display:inline-flex !important; align-items:center; justify-content:center; width:28px; height:28px; margin:0 !important; border-radius:50%; background:var(--marca); color:#fff !important; border:2px solid var(--cartao); cursor:pointer; }
.perfil-lapis:hover{ background:var(--marca-hover); }
.perfil-lapis:focus-within{ outline:2px solid var(--marca); outline-offset:2px; }
.perfil-lapis input{ position:absolute; width:1px; height:1px; opacity:0; }
.perfil-info{ display:flex; flex-direction:column; align-items:flex-start; gap:4px; }
.perfil-info .discreto{ min-height:28px; padding:0 10px; font-size:12px; }
.botao-arquivo{ display:inline-flex !important; flex-direction:row !important; align-items:center; gap:6px; margin:0 !important; min-height:var(--alt); padding:0 14px; background:var(--marca); color:#fff !important; border-radius:var(--r-sm); font-weight:500; font-size:13px !important; cursor:pointer; }
.botao-arquivo:hover{ background:var(--marca-hover); }
.botao-arquivo input{ position:absolute; width:1px; height:1px; opacity:0; }
.perfil-sair{ border-top:1px solid var(--linha); padding-top:12px; }
/* Configuracoes */
.cfg-titulo{ font-size:20px; margin:0 0 14px; }
.cfg-grade{ display:grid; grid-template-columns:repeat(auto-fit,minmax(340px,1fr)); gap:16px; margin:0 0 16px; align-items:start; }
.cfg-grade .cartao{ max-width:none; margin:0; }
.cfg-grade h3{ font-size:14px; margin:18px 0 8px; }
.cfg-campo{ display:flex; flex-direction:column; gap:4px; margin:0 0 12px; font-size:13px; }
.cfg-campo select, .cfg-campo input{ max-width:340px; }
.cfg-horas{ display:flex; flex-direction:column; gap:10px; margin:0 0 14px; }
.cfg-chave{ display:flex; align-items:center; justify-content:space-between; gap:12px; max-width:340px; cursor:pointer; }
.cfg-chave input{ position:absolute; opacity:0; width:1px; height:1px; }
.cfg-chave i{ position:relative; flex:none; width:36px; height:20px; border-radius:10px; background:var(--linha-forte); transition:background .15s; }
.cfg-chave i::after{ content:""; position:absolute; top:2px; left:2px; width:16px; height:16px; border-radius:50%; background:#fff; box-shadow:0 1px 2px rgba(0,0,0,.2); transition:left .15s; }
.cfg-chave input:checked + i{ background:var(--marca); } .cfg-chave input:checked + i::after{ left:18px; }
.cfg-chave input:focus-visible + i{ outline:2px solid var(--marca); outline-offset:2px; }
.cfg-padroes{ display:flex; flex-direction:column; gap:8px; max-width:420px; margin:0 0 6px; }
.cfg-padroes label{ display:flex; flex-wrap:wrap; align-items:center; gap:6px; padding:10px 12px; border:1px solid var(--linha); border-radius:var(--r-sm); cursor:pointer; }
.cfg-padroes label:has(input:checked){ border-color:var(--marca); background:var(--realce); }
.cfg-previa{ display:flex; gap:10px; align-items:flex-start; max-width:380px; padding:10px 12px; border-radius:12px; background:#111827; color:#fff; }
.cfg-previa-icone{ flex:none; display:flex; align-items:center; justify-content:center; width:34px; height:34px; border-radius:8px; background:var(--marca); color:#fff; }
.cfg-previa b{ display:block; font-size:13px; } .cfg-previa span{ font-size:12px; color:#D1D5DB; }
.cfg-aparelhos{ margin-top:10px; }
/* Dentro de cartao, label e coluna e input ocupa a linha: aqui, interruptor e opcao ficam na linha do texto */
.cartao .cfg-chave, .cartao .cfg-padroes label{ flex-direction:row; margin-bottom:0; color:var(--texto); font-size:13px; }
.cartao .cfg-padroes label{ align-items:center; }
.cartao input[type=checkbox], .cartao input[type=radio]{ width:auto; }
.cfg-previa + .cfg-campo{ margin-top:16px; }
/* Gestor: chave liga/pausa e caixas de marcar */
.chave-form{ display:inline-block; margin:0 6px 0 0; vertical-align:middle; }
.chave{ position:relative; display:inline-block; width:34px; height:20px; min-height:0; padding:0; border-radius:10px; border:0; background:var(--linha-forte); cursor:pointer; }
.chave:hover{ background:var(--apagado); }
.chave span{ position:absolute; top:2px; left:2px; width:16px; height:16px; border-radius:50%; background:#fff; box-shadow:0 1px 2px rgba(0,0,0,.2); transition:left .15s; }
.chave.ligada{ background:var(--marca); } .chave.ligada:hover{ background:var(--marca-hover); }
.chave.ligada span{ left:16px; }
.chave:disabled, .chave:disabled:hover{ opacity:.55; cursor:not-allowed; background:var(--linha-forte); }
.chave.ligada:disabled{ background:var(--marca); }
.chave:focus-visible{ outline:2px solid var(--marca); outline-offset:2px; }
th.marca, td.marca{ width:34px; text-align:center; }
.status-meta{ display:inline-flex; align-items:center; gap:6px; color:var(--suave); }
.status-meta::before{ content:""; width:7px; height:7px; border-radius:50%; background:var(--apagado); }
.status-meta.ativo{ color:var(--texto); } .status-meta.ativo::before{ background:#16A34A; }
.status-meta.alerta{ color:var(--alerta); } .status-meta.alerta::before{ background:#F59E0B; }
.aviso-meta{ background:var(--cartao); border:1px solid var(--linha); border-left:3px solid var(--marca); border-radius:var(--r-sm); padding:8px 12px; }
.legenda{ font-size:12px; }
.grafico{ display:block; width:100%; height:auto; margin-top:4px; }
.grafico text{ font:11px var(--f-texto); fill:var(--suave); }
.grafico .grade-l{ stroke:var(--linha); stroke-width:1; }
.grafico .barra{ fill:url(#grad-azul-v); }
.grafico .barra-f{ fill:#C13584; } .grafico .barra-m{ fill:#1D6FF2; }
/* Organico: quem segue x quem interage */
.publico-comp{ list-style:none; margin:4px 0 0; padding:0; display:flex; flex-direction:column; gap:8px; font-size:13px; }
.publico-comp li{ display:grid; grid-template-columns:minmax(0,1fr) 58px 66px 80px; align-items:center; gap:8px; font-variant-numeric:tabular-nums; }
.publico-comp li > span:not(:first-child){ text-align:right; }
.publico-comp .pc-cab{ font-size:12px; color:var(--suave); }
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
/* Resumo como o da UTMify: grade de 12 colunas, cartoes, rosca e aneis */
.resumo-cab{ margin-bottom:12px; }
.resumo-topo{ display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; gap:8px 16px; }
.resumo-topo h2{ margin:0; font-size:17px; }
.resumo-topo .barra-vendas{ margin:0; }
.resumo-filtros{ display:flex; flex-wrap:wrap; gap:10px 14px; margin-top:12px; }
.resumo-filtros label{ display:flex; flex-direction:column; gap:3px; font-size:12px; color:var(--suave); min-width:200px; }
.resumo-aviso{ margin:10px 0 0; font-size:13px; }
.rgrade{ display:grid; grid-template-columns:repeat(12,minmax(0,1fr)); gap:12px; margin:0 0 12px; }
.rgrade > *{ min-width:0; margin:0 !important; }
.rgrade .c2{ grid-column:span 2; } .rgrade .c3{ grid-column:span 3; } .rgrade .c4{ grid-column:span 4; } .rgrade .c6{ grid-column:span 6; } .rgrade .c8{ grid-column:span 8; } .rgrade .c12{ grid-column:1 / -1; } .rgrade .r2{ grid-row:span 2; }
.rc{ display:flex; flex-direction:column; gap:6px; padding:14px 16px; background:var(--cartao); border:1px solid var(--linha); border-radius:var(--r-md); }
.rc-cab{ display:flex; justify-content:space-between; align-items:flex-start; gap:8px; color:var(--suave); font-size:13px; font-weight:500; }
.rc-tit{ display:inline-flex; align-items:center; gap:6px; min-width:0; }
.rc > b{ font-size:24px; font-weight:600; line-height:1.2; font-variant-numeric:tabular-nums; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.rc > small{ color:var(--suave); font-size:12px; }
.rc-link{ margin-top:auto; font-size:13px; }
.rosca-caixa{ display:flex; flex-direction:column; align-items:center; gap:14px; flex:1; justify-content:center; }
.rosca{ width:min(200px,70%); height:auto; }
.rosca-fundo{ fill:none; stroke:var(--cartao-2); stroke-width:6; }
.rosca-rot{ font:4px var(--f-texto); fill:var(--suave); text-anchor:middle; }
.rosca-num{ font:600 7px var(--f-texto); fill:var(--texto); text-anchor:middle; }
.rosca-leg{ display:flex; flex-wrap:wrap; justify-content:center; gap:6px 14px; margin:0; padding:0; list-style:none; font-size:13px; }
.rosca-leg i{ display:inline-block; width:10px; height:10px; border-radius:50%; margin-right:5px; vertical-align:-1px; }
/* Rodape de um bloco do Resumo/Trafego (ex.: melhor conversao) */
.rc-rodape{ margin-top:auto; padding-top:10px; border-top:1px solid var(--linha); color:var(--suave); font-size:12px; }
.rc-rodape b{ color:var(--texto); font-weight:600; }
.ver-tabela{ margin:0 0 20px; } .ver-tabela > summary{ display:inline-block; cursor:pointer; color:var(--marca); font-weight:500; margin:0 0 10px; }
.lista-aneis{ list-style:none; margin:4px 0 0; padding:0; display:flex; flex-direction:column; gap:10px; }
.lista-aneis li{ display:grid; grid-template-columns:minmax(0,1fr) auto 24px 52px; align-items:center; gap:10px; font-size:13px; }
.lista-aneis .rot{ min-width:0; overflow:hidden; text-overflow:ellipsis; }
.lista-aneis .n{ color:var(--suave); font-variant-numeric:tabular-nums; }
.lista-aneis .pct{ text-align:right; font-weight:600; font-variant-numeric:tabular-nums; }
.anel{ width:24px; height:24px; }
.anel-fundo{ fill:none; stroke:var(--cartao-2); stroke-width:5; }
.anel-valor{ fill:none; stroke:var(--marca); stroke-width:5; }
@media (max-width:1100px){
  .rgrade{ grid-template-columns:repeat(6,minmax(0,1fr)); }
  .rgrade .c2{ grid-column:span 2; } .rgrade .c3, .rgrade .c4, .rgrade .c6{ grid-column:span 3; } .rgrade .c8{ grid-column:span 6; } .rgrade .r2{ grid-row:auto; }
  .rgrade > section.c4, .rgrade > section.c8{ grid-column:span 6; }
}
@media (max-width:640px){
  .rgrade{ grid-template-columns:repeat(2,minmax(0,1fr)); gap:8px; }
  .rgrade > *{ grid-column:1 / -1 !important; }
  .rgrade > .rc.c2, .rgrade > .rc.c3, .rgrade > .rc.c6{ grid-column:span 1 !important; }
  .rc > b{ font-size:19px; }
  .resumo-filtros label{ min-width:0; flex:1 1 140px; }
}
/* Funil em fluxo (resumo_funil) */
.fluxo-cab, .fluxo-pct, .fluxo-pe{ display:grid; grid-template-columns:repeat(var(--n),minmax(0,1fr)); text-align:center; }
.fluxo-cab > span{ display:flex; flex-wrap:wrap; justify-content:center; align-items:center; gap:0 2px; min-width:0; padding:0 6px 10px; font-size:13px; font-weight:600; color:var(--suave); overflow-wrap:anywhere; }
.fluxo-cab .nw{ display:inline-flex; align-items:center; white-space:nowrap; }
.fluxo-corpo{ position:relative; height:150px; --escala:1.5px; }
.fluxo-corpo svg{ position:absolute; inset:0; width:100%; height:100%; }
.fluxo-corpo line{ stroke:var(--linha-forte); stroke-width:1; }
.fluxo-pct{ position:absolute; inset:0; align-items:center; pointer-events:none; }
.fluxo-pct b{ font-size:20px; font-weight:700; font-variant-numeric:tabular-nums; }
.fluxo-pct b.dentro{ color:#fff; }
.fluxo-pct b.fora{ color:var(--texto); transform:translateY(calc(var(--h) * var(--escala) * -0.5 - 14px)); }
.fluxo-pe b{ padding-top:10px; font-size:16px; font-weight:600; font-variant-numeric:tabular-nums; }
@media (max-width:640px){ .fluxo-cab span{ font-size:11px; padding:0 2px 8px; } .fluxo-pct b{ font-size:13px; } .fluxo-pe b{ font-size:13px; } .fluxo-corpo{ height:110px; --escala:1.1px; } }
.bloco + .bloco, .grade + .bloco, .bloco + .grade{ margin-top:16px; }
.grade > .bloco{ margin-top:0; }

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

@media (max-width:640px){ .topo, .abas{ padding-left:16px; padding-right:16px; } main{ padding:16px; } select{ min-width:130px; }
  .grade{ grid-template-columns:1fr; } }
/* ---------------------------------------------------------------- celular
   Navegacao de app: barra fixa embaixo (Resumo, Trafego, Gestor, Vendas e Mais), topo
   compacto e sem grudar, listas em cartoes e a primeira coluna das tabelas fixa. */
.nav-celular{ display:none; }
@media (max-width:760px){
  body{ padding-bottom:calc(64px + env(safe-area-inset-bottom)); }
  .abas{ display:none; }
  .topo{ position:static; padding:10px 16px; gap:8px; }
  .filtros{ display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); width:100%; gap:8px; }
  .filtros select{ width:100%; min-width:0; }
  .filtros .campo, .multi > summary{ width:100%; min-width:0; max-width:none; }
  .nav-celular{ display:flex; position:fixed; left:0; right:0; bottom:0; z-index:30; background:var(--cartao); border-top:1px solid var(--linha);
    padding:4px 4px env(safe-area-inset-bottom); box-shadow:0 -4px 16px rgba(17,24,39,.06); }
  .nav-celular > a, .nav-mais > summary{ flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:2px; min-height:52px;
    color:var(--suave); font-size:11px; font-weight:500; text-decoration:none; border-radius:var(--r-md); list-style:none; cursor:pointer; -webkit-tap-highlight-color:transparent; }
  .nav-mais{ flex:1; display:flex; }
  .nav-mais > summary::-webkit-details-marker{ display:none; }
  .nav-celular > a.atual, .nav-mais.atual > summary, .nav-mais[open] > summary{ color:var(--marca); }
  .nav-celular > a.atual .ico, .nav-mais.atual > summary .ico{ stroke-width:2.2; }
  .nav-mais-painel{ position:fixed; left:8px; right:8px; bottom:calc(68px + env(safe-area-inset-bottom)); background:var(--cartao); border:1px solid var(--linha);
    border-radius:12px; box-shadow:0 12px 32px var(--sombra); padding:6px; display:grid; grid-template-columns:1fr 1fr; gap:2px; max-height:70vh; overflow:auto; }
  .nav-mais-painel a{ display:flex; align-items:center; gap:10px; padding:12px; border-radius:var(--r-md); color:var(--texto); font-weight:500; text-decoration:none; }
  .nav-mais-painel a:hover, .nav-mais-painel a:active{ background:var(--hover); }
  .nav-mais-painel a.atual{ color:var(--marca); background:var(--realce); }
  .nav-mais-painel .ico{ color:var(--suave); } .nav-mais-painel a.atual .ico{ color:var(--marca); }
  .nav-mais-painel hr{ grid-column:1 / -1; border:0; border-top:1px solid var(--linha); margin:4px 0; }
  /* Primeira coluna fixa ao rolar a tabela para o lado */
  .tabela:not(.lista) tr > :first-child{ position:sticky; left:0; z-index:1; }
  .tabela:not(.lista) td:first-child{ background:var(--cartao); box-shadow:1px 0 0 var(--linha); }
  .tabela:not(.lista) th:first-child{ background:var(--cartao-2); box-shadow:1px 0 0 var(--linha); }
  .tabela.gestor tr > :first-child{ position:static; box-shadow:none; }
  .tabela.gestor .nome{ position:sticky; left:0; z-index:1; background:var(--cartao); box-shadow:1px 0 0 var(--linha); }
  .tabela.gestor th.nome, .tabela.gestor tr.total td.nome{ background:var(--cartao-2); }
  .tabela.gestor td.quebra{ min-width:140px; max-width:180px; white-space:normal; }
  /* Listas (vendas, visitantes, eventos, conferencia): cada linha vira um cartao */
  .tabela.lista table, .tabela.lista tbody, .tabela.lista tr, .tabela.lista td{ display:block; width:100%; }
  .tabela.lista tr:first-child{ display:none; }
  .tabela.lista tr{ padding:10px 0; border-bottom:1px solid var(--linha); }
  .tabela.lista tr:last-child{ border-bottom:0; }
  .tabela.lista td{ display:flex; justify-content:space-between; align-items:baseline; gap:12px; border:0; padding:3px 14px; white-space:normal; text-align:right; }
  .tabela.lista td::before{ content:attr(data-rotulo); flex:none; max-width:45%; color:var(--suave); font-size:12px; text-align:left; }
  .tabela.lista td:first-child{ font-weight:600; }
  .tabela.lista td:empty, .tabela.lista td.secundaria{ display:none; }
  .tabela.lista td[colspan]{ display:block; text-align:left; } .tabela.lista td[colspan]::before{ content:none; }
  .rosca{ width:min(170px,60%); }
  /* Gestor: niveis numa linha (rola para o lado) e filtros compactos */
  .gestor-niveis{ display:flex; max-width:100%; overflow-x:auto; scrollbar-width:none; }
  .gestor-niveis a{ white-space:nowrap; padding:6px 10px; }
  .gestor-filtros{ display:grid; grid-template-columns:minmax(0,1fr) minmax(0,120px) auto; gap:8px; width:100%; }
  .gestor-filtros input, .gestor-filtros select{ min-width:0; width:100%; }
  .gestor-cab .gestor-filtros{ grid-template-columns:minmax(0,1fr) minmax(0,1fr); }
  .gestor-cab .tcard-busca{ grid-column:1 / -1; }
  .gestor-cab .tcard-busca input{ width:100%; }
  .gestor-cab .gestor-campo{ flex-direction:column; align-items:stretch; gap:3px; min-width:0; }
  .gestor-ferr{ width:100%; margin-left:0; }
  .gestor-topo .barra-vendas{ width:100%; }
  /* Funil: titulo quebra em duas linhas e o (i) vai embaixo dele (palavra longa nao invade a vizinha) */
  .fluxo-cab span{ font-size:10.5px; line-height:1.3; padding:0 1px 8px; overflow-wrap:anywhere; hyphens:auto; }
  .fluxo-cab .nw{ white-space:normal; }
  .fluxo-cab .info{ display:flex; margin:3px auto 0; }
}
@media (max-width:640px){
  select, input, textarea{ font-size:16px; }
  input[type=checkbox], input[type=radio]{ font-size:inherit; }
}
/* Telas do nucleo (Minha conta, Usuarios): as abas do nucleo continuam a mostra no celular */
@media (max-width:760px){ .abas.abas-nucleo{ display:flex; padding-left:16px; padding-right:16px; } }
</style>
</head>
<body>
<svg class="defs-graficos" aria-hidden="true" focusable="false"><defs>
<linearGradient id="grad-azul-v" x1="0" y1="0" x2="0" y2="1"><stop offset="0" style="stop-color:var(--grad-b)"></stop><stop offset="1" style="stop-color:var(--grad-a)"></stop></linearGradient>
<linearGradient id="grad-azul-h" x1="0" y1="0" x2="1" y2="0"><stop offset="0" style="stop-color:var(--grad-a)"></stop><stop offset="1" style="stop-color:var(--grad-b)"></stop></linearGradient>
</defs></svg>
<?php
}

function pagina_fim(): void
{
    // ?v= pela data do arquivo: dentro de um site cujo .htaccess manda cachear .js por
    // 1 ano (ex.: engdesk.pro), sem isso a mudanca no painel.js nao chegaria.
    echo '<script src="' . e(admin_base()) . 'tabela.js?v=' . (int)@filemtime(__DIR__ . '/../tabela.js') . '"></script>'
        . '<script src="' . e(admin_base()) . 'painel.js?v=' . (int)@filemtime(__DIR__ . '/../painel.js') . '"></script></body></html>';
}


// $aqui: o painel (utm) ou a tela do nucleo (conta, usuarios) aberta, marcada na barra lateral
function casca_inicio(string $aqui = 'utm'): void
{
    echo '<noscript><style>.lateral-itens{ display:flex !important; }</style></noscript><div class="casca">' . admin_lateral($aqui) . '<div class="conteudo">';
}

function casca_fim(): void
{
    echo '</div></div>';
}

// Icones de linha (mesmo traco dos icones do CMS). So SVG inline: a CSP nao carrega imagem de fora.
function icone(string $nome, int $tam = 16): string
{
    $p = [
        'trafego' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'guia' => '<path d="M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2V5z"/><path d="M4 19a2 2 0 0 1 2-2h13"/><path d="M9 7h6M9 11h4"/>',
        'resumo' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'mais' => '<circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/>',
        'config' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
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
        'carteira' => '<path d="M19 7V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-3"/><path d="M21 9h-6a3 3 0 0 0 0 6h6z"/><path d="M15.5 12h.01"/>',
        'calendario' => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/>',
        'filtro' => '<path d="M3 5h18l-7 8v5l-4 2v-7z"/>',
        'seta-cima' => '<path d="M6 15l6-6 6 6"/>',
        'grafico' => '<path d="M4 19V9M10 19V5M16 19v-6M22 19H2"/><path d="M4 9l6-4 6 8 5-5"/>',
        'usuarios' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'busca' => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
        'olho-fechado' => '<path d="M17.94 17.94A10 10 0 0 1 12 20C5 20 1 12 1 12a18.5 18.5 0 0 1 5.06-5.94M9.9 4.24A9 9 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24M1 1l22 22"/>',
        'restaurar' => '<path d="M3 12a9 9 0 1 0 2.64-6.36L3 8"/><path d="M3 3v5h5"/>',
        'lista' => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
        'infinito' => '<path d="M18.18 8a4 4 0 1 1 0 8c-2.18 0-3.72-2-6.18-4s-4-4-6.18-4a4 4 0 1 0 0 8c2.18 0 3.72-2 6.18-4s4-4 6.18-4z"/>',
        'reticencias' => '<circle cx="5" cy="12" r="1.2"/><circle cx="12" cy="12" r="1.2"/><circle cx="19" cy="12" r="1.2"/>',
        'atividade' => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
        'repetir' => '<path d="M17 2l4 4-4 4"/><path d="M3 11V9a3 3 0 0 1 3-3h15M7 22l-4-4 4-4"/><path d="M21 13v2a3 3 0 0 1-3 3H3"/>',
        'externo' => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14L21 3"/>',
        'copiar' => '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
        'fixar' => '<path d="M12 17v5M9 10.76V5h6v5.76l2 3.24H7z"/><path d="M8 2h8"/>',
        'foco' => '<path d="M8 3H5a2 2 0 0 0-2 2v3M21 8V5a2 2 0 0 0-2-2h-3M3 16v3a2 2 0 0 0 2 2h3M16 21h3a2 2 0 0 0 2-2v-3"/>',
        'ligar' => '<path d="M9 11l3 3 8-8"/><rect x="3" y="3" width="18" height="18" rx="2"/>',
        'pausar' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 9l6 6M15 9l-6 6"/>',
        'lixo' => '<path d="M3 6h18M8 6V4h8v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>',
        'lapis' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
        'ok' => '<path d="M20 6L9 17l-5-5"/>',
        'fechar' => '<path d="M18 6L6 18M6 6l12 12"/>',
        'colunas' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16M15 4v16"/>',
        'ordenar' => '<path d="M7 4v16M4 17l3 3 3-3M17 20V4M14 7l3-3 3 3"/>',
        'seta-baixo' => '<path d="M6 9l6 6 6-6"/>',
        'grade' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 10h18M10 10v11"/>',
        'estrela' => '<path d="M12 2l3.1 6.3 6.9 1-5 4.9 1.2 6.8L12 17.8 5.8 21l1.2-6.8-5-4.9 6.9-1z"/>',
        'paleta' => '<path d="M12 22a10 10 0 1 1 10-10c0 2.2-1.8 3.5-4 3.5h-1.6a1.9 1.9 0 0 0-1.4 3.2A2 2 0 0 1 12 22z"/><circle cx="7.5" cy="10.5" r="1"/><circle cx="10.5" cy="6.5" r="1"/><circle cx="15.5" cy="6.5" r="1"/><circle cx="17.5" cy="10.5" r="1"/>',
    ];
    return '<svg class="ico" width="' . $tam . '" height="' . $tam . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$nome] ?? '') . '</svg>';
}

// Meio organico em portugues (etiquetas da limpeza da pagina: organico / <meio> / ...)
function rotulo_meio(string $m): string
{
    $nomes = ['instagram-bio' => 'Instagram (bio)', 'instagram-stories' => 'Instagram (stories)', 'instagram' => 'Instagram', 'google' => 'Google', 'whatsapp' => 'WhatsApp',
        'facebook' => 'Facebook', 'ia' => 'IA (ChatGPT e outros)', 'site' => 'outro site', 'direto' => 'direto', 'email' => 'e-mail', 'youtube' => 'YouTube'];
    return $nomes[$m] ?? $m;
}

// Canal de uma origem, para agrupar e mostrar com icone: [chave, rotulo, icone, detalhe].
// Anuncio da Meta vira Instagram ou Facebook pelo posicionamento (utm_term, ex.:
// Instagram_Reels); sem posicionamento, "Meta". Organico leva a folha. Sem etiqueta,
// o site de onde a pessoa veio (so para visitantes; venda nao tem referrer).
function canal(?string $source, ?string $medium = null, ?string $term = null, ?string $referrer = null, ?string $campaign = null): array
{
    $s = strtolower(trim((string)$source));
    $m = strtolower(trim((string)$medium));
    $t = strtolower(trim((string)$term));
    $pago = strpos($m, '|') !== false || in_array($m, ['cpc', 'ppc', 'paid', 'paid_social', 'ads'], true);
    // O Instagram troca utm_source e utm_medium do link por "ig" e "social" e mantem o resto:
    // com a campanha da Meta ("Nome|120..."), e anuncio, mesmo parecendo organico
    $anuncioTrocado = 'o Instagram trocou a origem para ig / social; a campanha é de anúncio';
    $idCampanha = (bool)preg_match('/\|\s*\d{6,25}\s*$/', (string)$campaign);
    if (in_array($s, ['organico', 'orgânico', 'organic'], true)) {
        return ['organico', 'Orgânico', 'folha', rotulo_meio($m)];
    }
    if ($s === 'ig') { // marca automatica do Instagram no link da bio
        return $idCampanha ? ['instagram', 'Instagram · anúncio', 'instagram', $anuncioTrocado] : ['organico', 'Orgânico', 'folha', 'Instagram (bio)'];
    }
    if ($idCampanha && in_array($s, ['facebook', 'instagram'], true) && !$pago) {
        return $s === 'instagram' ? ['instagram', 'Instagram · anúncio', 'instagram', $anuncioTrocado]
            : ['facebook', 'Facebook · anúncio', 'facebook', 'origem "facebook" sem marca de anúncio, mas a campanha é de anúncio'];
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

// O (i) ao lado de cada canal: de onde vem e em que condicao a visita ou venda cai nele
const CANAIS_DICA = [
    'instagram' => 'Veio de um anúncio pago que apareceu no Instagram (feed, stories, reels, explorar). Condição: o link traz a campanha do anúncio e o posicionamento Instagram (utm_term), ou o Instagram trocou a origem para ig / social mas a campanha tem o ID da Meta.',
    'facebook' => 'Veio de um anúncio pago que apareceu no Facebook, no Messenger ou na Audience Network. Condição: o link traz a campanha do anúncio e o posicionamento Facebook (utm_term).',
    'google' => 'Veio de um anúncio do Google Ads. Condição: o link traz utm_source=google (ou googleads) com meio pago, como cpc.',
    'organico' => 'Chegou sem anúncio: link da bio, post, story, Direct, WhatsApp, busca do Google, IA ou outro site. Condição: o link diz que é orgânico (ex.: organico / instagram-bio, organico / instagram-stories) ou a pessoa veio de um site sem etiqueta de anúncio. O detalhe ao lado diz de onde.',
    'outros' => 'O link tem etiqueta, mas não é de anúncio da Meta, do Google nem orgânica (ex.: e-mail marketing, parceiro, outra ferramenta). O detalhe mostra a origem escrita no link.',
    'meta' => 'Veio de anúncio da Meta (a etiqueta traz a campanha), mas o utm_term, que diz onde o anúncio apareceu, chegou vazio ou com um valor que não é Facebook nem Instagram (ex.: "an", da Audience Network). Por isso não entra em Facebook nem em Instagram. Campanha, conjunto e anúncio continuam valendo no Gestor de anúncios. Para diminuir: o link do anúncio precisa ter utm_term={{placement}}.',
    'compartilhado' => 'Link de anúncio aberto fora da entrega paga (post compartilhado, link copiado, prévia do anúncio): as etiquetas chegaram com {{...}} escrito, sem o que a Meta preencheria. Não dá para ligar a uma campanha.',
    'direto' => 'Chegou sem etiqueta e sem site de origem: link digitado ou salvo, app que esconde de onde veio, link direto do checkout ou troca de aparelho entre o clique e a compra.',
];

// Uma explicacao so por canal: o detalhe vai dentro do (i), nunca num title (a dica do
// navegador abria por cima da do (i) e as duas ficavam ilegiveis)
function selo_canal(array $c, bool $comDetalhe = true): string
{
    $dica = CANAIS_DICA[$c[0]] ?? '';
    if (!$comDetalhe && $c[3] !== '') {
        $dica = trim($dica . ' Neste caso: ' . $c[3] . '.');
    }
    return '<span class="canal canal-' . e($c[0]) . '">' . icone($c[2]) . '<span>' . e($c[1]) . '</span>'
        . ($dica !== '' ? info($dica) : '')
        . ($comDetalhe && $c[3] !== '' ? '<span class="suave">· ' . e($c[3]) . '</span>' : '') . '</span>';
}

// (i) com a explicacao de um numero ou bloco, o mesmo icone em SVG em todo o painel. A dica
// aparece ao passar o mouse ou no foco do teclado; clicar ou tocar fixa ate clicar fora ou Esc
// (painel.js desenha uma caixa so, por cima de tudo, sem ser cortada pela tabela)
function info(string $texto): string
{
    return '<span class="info" tabindex="0" role="button" aria-label="' . e($texto) . '" data-dica="' . e($texto) . '">'
        . '<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><circle cx="8" cy="8" r="6.6"></circle><path d="M8 7.3v4"></path><circle cx="8" cy="4.9" r=".55" class="ponto-i"></circle></svg></span>';
}

// Atributos de uma dica com titulo (primeira linha em negrito) e linhas (graficos)
function dica_attr(string $titulo, array $linhas): string
{
    return ' data-dica-titulo="' . e($titulo) . '" data-dica="' . str_replace("\n", '&#10;', e(implode("\n", array_filter($linhas, fn($l) => $l !== '')))) . '" tabindex="0"';
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


// Botao de atualizar (so o icone, com a dica): busca na hora e volta para $volta
// $foco: a busca que vai primeiro (kiwify, meta ou instagram), a da tela em que o botao esta
function botao_atualizar(string $volta, string $dica = 'Atualizar agora: busca as vendas na Kiwify, o gasto na Meta e o Instagram', string $foco = ''): string
{
    return '<form method="post" action="sincronizar.php" class="form-atualizar"><input type="hidden" name="csrf" value="' . e(token_csrf()) . '">'
        . ($foco !== '' ? '<input type="hidden" name="foco" value="' . e($foco) . '">' : '')
        . '<input type="hidden" name="volta" value="' . e($volta) . '"><button type="submit" class="botao-icone" title="' . e($dica) . '" aria-label="Atualizar">'
        . icone('atualizar', 16) . '</button></form>';
}

// Topo das telas sem filtro (Usuarios, API Kiwify): a conta mora na barra lateral, entao nada
function topo_pagina(): void
{
}

// Lista de varios (Produto, Fonte de trafego): caixas, com "Todos" no topo. O painel.js troca
// pelo seletor do painel e aplica ao fechar; sem JavaScript fica o <details> e o Filtrar.
// $opcoes: valor => rotulo; $nomes: [singular, plural] para o "3 produtos" do botao.
function filtro_multi(string $nome, array $opcoes, array $marcados, string $rotuloTodos, array $nomes = ['item', 'itens'], string $vazio = 'Nada ainda.'): string
{
    $m = array_values(array_filter($marcados, fn($v) => isset($opcoes[$v])));
    $rot = !$m ? $rotuloTodos : (count($m) === 1 ? $opcoes[$m[0]] : count($m) . ' ' . $nomes[1]);
    $h = '<details class="multi" data-multi data-um="' . e($nomes[0]) . '" data-varios="' . e($nomes[1]) . '"><summary>' . e($rot) . '</summary><div class="multi-painel">'
        . '<input type="hidden" name="' . e($nome) . '[]" value="">'
        . '<label class="multi-todos"><input type="checkbox" data-multi-todos' . (!$m ? ' checked' : '') . '>' . e($rotuloTodos) . '</label>';
    foreach ($opcoes as $v => $r) {
        $h .= '<label><input type="checkbox" name="' . e($nome) . '[]" value="' . e((string)$v) . '"' . (in_array((string)$v, $m, true) ? ' checked' : '') . '>' . e($r) . '</label>';
    }
    if (!$opcoes) {
        $h .= '<p class="suave">' . e($vazio) . '</p>';
    }
    return $h . '</div></details>';
}

// Cor do ROI, como na planilha de campanhas: abaixo de 1 vermelho, de 1 ate 2 laranja, 2 ou
// mais verde. Compara o numero como aparece na tela (2 casas): "2,00" e verde.
function cor_roi(?float $roi): string
{
    if ($roi === null) {
        return '';
    }
    $r = round($roi, 2);
    return $r < 1 ? 'negativo' : ($r < 2 ? 'medio' : 'positivo');
}

// Barra do topo, que vale para todas as telas e fica lembrada: site e pagina (onde fazem
// sentido), periodo (prontos ou de uma data a outra) e produto. A conta fica a direita.
function barra_topo(array $filtro, array $dominios, array $paginas, string $aba, array $produtos = []): void
{
    $soPeriodo = in_array($aba, ['gestor', 'campanha', 'geral', 'painel', 'organico', 'financeiro'], true); // nessas telas, site e pagina nao se aplicam
    $comProduto = !in_array($aba, ['visitantes', 'eventos', 'financeiro'], true); // telas sem venda (e o Financeiro, que e a empresa toda)
    $marcados = $filtro['produto'] ?? [];
    $datas = periodo_datas($filtro['periodo']);
    // As datas do personalizado comecam no periodo que esta na tela
    [$d1, $d2] = $datas ?? periodo_dias($filtro['periodo']);
    $hoje = (new DateTime('today', fuso()))->format('Y-m-d');
    if ($filtro['periodo'] === 'tudo') {
        [$d1, $d2] = [(new DateTime('today', fuso()))->modify('-29 days')->format('Y-m-d'), $hoje];
    }
    ?>
<header class="topo">
<form class="filtros" method="get" action="./" id="filtros">
  <input type="hidden" name="aba" value="<?= e($aba) ?>">
  <?php if (!$soPeriodo): ?>
  <label><span><?= com_info('Site', 'O site em que a visita aconteceu (onde o t.js do painel está instalado). Todos os sites: soma tudo. Vale para tráfego, conferência, vendas, visitantes e eventos.') ?></span>
    <select name="dominio" data-reinicia="pagina">
      <option value="">Todos os sites</option>
      <?php foreach ($dominios as $d): ?>
        <option value="<?= e($d) ?>"<?= $filtro['dominio'] === $d ? ' selected' : '' ?>><?= e($d) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><span><?= com_info('Página', 'Uma página do site escolhido (/drivedeprojetos e /drivedeprojetos/ são a mesma). Escolha um site para ver as páginas dele.') ?></span>
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
  <?php if ($aba === 'campanha' && is_string($_GET['id'] ?? null) && preg_match('/^\d{3,25}$/', $_GET['id'])): ?>
  <input type="hidden" name="id" value="<?= e($_GET['id']) ?>">
  <?php endif; ?>
  <div class="campo campo-periodo" data-periodo-campo data-rotulo="<?= e(periodo_rotulo($filtro['periodo'])) ?>" data-de="<?= e($d1) ?>" data-ate="<?= e($d2) ?>" data-hoje="<?= e($hoje) ?>">
    <span><?= com_info('Período', 'Vale para todas as telas e fica lembrado. Escolha um dos prontos ou, no calendário, o primeiro e o último dia (os dois entram). Hoje e Tudo não comparam com o período anterior.') ?></span>
    <select name="periodo" data-periodo data-nativo aria-label="Período">
      <?php foreach (PERIODOS as $v => $rotulo): ?>
        <option value="<?= e($v) ?>"<?= $filtro['periodo'] === $v ? ' selected' : '' ?>><?= e($rotulo) ?></option>
      <?php endforeach; ?>
      <option value="personalizado"<?= $datas ? ' selected' : '' ?>>De uma data a outra…</option>
    </select>
    <span class="datas" data-datas<?= $datas ? '' : ' hidden' ?>>
      <label>De <input type="date" name="de" value="<?= e($d1) ?>" max="<?= e($hoje) ?>"<?= $datas ? '' : ' disabled' ?>></label>
      <label>Até <input type="date" name="ate" value="<?= e($d2) ?>" min="<?= e($d1) ?>" max="<?= e($hoje) ?>"<?= $datas ? '' : ' disabled' ?>></label>
      <noscript><button type="submit" class="discreto neutro">Filtrar</button></noscript>
    </span>
  </div>
  <?php if ($comProduto): ?>
  <div class="campo"><span><?= com_info('Produto', 'Vale para as vendas de todas as telas: faturamento, vendas, CPA, ROI e lucro. Marque o produto principal (ou mais de um, como o Drive antigo e o novo) para tirar os order bumps da conta: cada order bump é outro produto na Kiwify. O gasto da Meta continua o das campanhas.') ?></span>
    <?= filtro_multi('produto', array_combine($produtos, $produtos) ?: [], $marcados, 'Todos (com order bump)', ['produto', 'produtos'], 'Nenhuma venda ainda.') ?>
  </div>
  <?php endif; ?>
  <noscript><button type="submit">Filtrar</button></noscript>
</form>
</header>
<?php
    abas_painel($aba, $filtro);
}

// Filtros que o link de cada aba leva: site e pagina so onde valem (no Resumo, Gestor, Financeiro e
// Organico eles nao entram na conta, e o endereco nao deve sugerir o contrario)
function abas_filtro(string $aba, array $filtro): array
{
    $leva = in_array($aba, ['gestor', 'campanha', 'geral', 'painel', 'organico', 'financeiro'], true) ? ['periodo' => 1, 'produto' => 1] : ['dominio' => 1, 'pagina' => 1, 'periodo' => 1, 'produto' => 1];
    return array_intersect_key($filtro, $leva);
}

// Abas: titulo e as telas de dados a esquerda (com icone, como no CMS); as de
// configuracao (API Kiwify e Usuarios) no canto direito. $filtro vazio: links sem filtro.
function abas_painel(string $aba, array $filtro = []): void
{
    $abas = ['geral' => ['Resumo', 'resumo'], 'painel' => ['Painel', 'grade'], 'trafego' => ['Tráfego', 'trafego'], 'gestor' => ['Gestor de anúncios', 'meta'], 'organico' => ['Orgânico', 'folha'], 'resumo' => ['Conferência', 'conferencia'], 'vendas' => ['Vendas', 'vendas'],
        'visitantes' => ['Visitantes', 'visitantes'], 'eventos' => ['Eventos', 'eventos']];
    if (!usuario_pode('utm')) {
        return;
    }
    $aba = $aba === 'campanha' ? 'gestor' : $aba; // a analise diaria e parte do gestor
    echo '<nav class="abas" aria-label="Seções do painel UTM"><span class="abas-titulo">UTM · Rastreio de vendas</span>';
    foreach ($abas as $id => [$rotulo, $ico]) {
        $q = http_build_query(['aba' => $id] + abas_filtro($id, $filtro));
        echo '<a href="./?' . e($q) . '" class="' . ($aba === $id ? 'atual' : '') . '">' . icone($ico) . e($rotulo) . '</a>';
    }
    echo '<span class="abas-espaco"></span>';
    // Guia do admin (ex.: admin.engdesk.pro/guia): so quando o site que hospeda o painel tem um
    if (is_file(__DIR__ . '/../../guia/index.php')) {
        echo '<a href="../guia/">' . icone('guia') . 'Guia</a>';
    }
    echo '<a href="kiwify-api.php" class="' . ($aba === 'kiwify-api' ? 'atual' : '') . '">' . icone('chave') . 'API Kiwify</a>';
    echo '<a href="meta-api.php" class="' . ($aba === 'meta-api' ? 'atual' : '') . '">' . icone('meta') . 'API Meta</a>';
    echo '<a href="instagram-api.php" class="' . ($aba === 'instagram-api' ? 'atual' : '') . '">' . icone('instagram') . 'API Instagram</a>';
    echo '<a href="configuracoes.php" class="' . ($aba === 'configuracoes' ? 'atual' : '') . '">' . icone('config') . 'Configurações</a></nav>';

    // Celular: barra fixa embaixo com as 4 telas mais usadas; o resto em "Mais"
    $link = fn(string $id) => './?' . http_build_query(['aba' => $id] + abas_filtro($id, $filtro));
    $item = fn(string $href, string $id, string $ico, string $rotulo) => '<a href="' . e($href) . '"' . ($aba === $id ? ' class="atual" aria-current="page"' : '') . '>' . icone($ico, 20) . '<span>' . e($rotulo) . '</span></a>';
    $mais = [['painel', $link('painel'), 'grade', 'Painel'], ['organico', $link('organico'), 'folha', 'Orgânico'], ['resumo', $link('resumo'), 'conferencia', 'Conferência'],
        ['visitantes', $link('visitantes'), 'visitantes', 'Visitantes'], ['eventos', $link('eventos'), 'eventos', 'Eventos']];
    $config = [['configuracoes', 'configuracoes.php', 'config', 'Configurações'], ['kiwify-api', 'kiwify-api.php', 'chave', 'API Kiwify'],
        ['meta-api', 'meta-api.php', 'meta', 'API Meta'], ['instagram-api', 'instagram-api.php', 'instagram', 'API Instagram']];
    if (usuario_pode('usuarios')) {
        $config[] = ['usuarios', admin_url('usuarios'), 'usuarios', 'Usuários'];
    }
    if (is_file(__DIR__ . '/../../guia/index.php')) {
        $config[] = ['guia', '../guia/', 'guia', 'Guia'];
    }
    $noMais = in_array($aba, array_column(array_merge($mais, $config), 0), true);
    echo '<nav class="nav-celular" aria-label="Navegação do painel">' . $item($link('geral'), 'geral', 'resumo', 'Resumo') . $item($link('trafego'), 'trafego', 'trafego', 'Tráfego')
        . $item($link('gestor'), 'gestor', 'meta', 'Gestor') . $item($link('vendas'), 'vendas', 'vendas', 'Vendas')
        . '<details class="nav-mais' . ($noMais ? ' atual' : '') . '"><summary>' . icone('mais', 20) . '<span>Mais</span></summary><div class="nav-mais-painel">';
    foreach ($mais as [$id, $href, $ico, $rot]) {
        echo $item($href, $id, $ico, $rot);
    }
    echo '<hr>';
    foreach ($config as [$id, $href, $ico, $rot]) {
        echo $item($href, $id, $ico, $rot);
    }
    echo '</div></details></nav>';
}
