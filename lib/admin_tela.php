<?php
// Nucleo do admin, a parte visual: o CSS comum (cores do tema, botoes e campos, rolagem, barra
// lateral, aparencia, foto), a barra lateral com os paineis e a conta, e o cabecalho das telas do
// nucleo. O painel UTM (lib/layout.php), o CMS do site e os outros modulos usam os mesmos.
// A parte logica (acessos, senha, enderecos) fica em lib/admin.php.

require_once __DIR__ . '/tema.php';
require_once __DIR__ . '/perfil.php';

// CSS do admin inteiro, que o painel UTM e o CMS usam iguais: as cores do tema (claro, escuro,
// pretao ou a cor escolhida, lib/tema.php), a altura dos botoes e campos, as barras de rolagem,
// a barra lateral (paineis e conta), a aparencia e a foto. O CMS (admin/index.php no engdesk)
// imprime este CSS antes do proprio, que usa estas variaveis.
function admin_css_base(): string
{
    return <<<'CSS'
/* hidden sempre esconde: o display:inline-flex dos botoes e o flex dos rotulos passavam por cima */
[hidden]{ display:none !important; }
/* Cartao de numero com o icone no canto (lib/componentes.php, cartao_kpi) */
.kpis{ display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:12px; margin:0 0 16px; }
.kpi{ display:flex; flex-direction:column; gap:4px; min-width:0; padding:14px 16px; background:var(--cartao); border:1px solid var(--linha); border-radius:var(--r-md); }
.kpi-cab{ display:flex; justify-content:space-between; align-items:flex-start; gap:8px; color:var(--texto); font-size:13px; font-weight:500; }
.kpi-ico{ display:inline-flex; flex:none; align-items:center; justify-content:center; width:28px; height:28px; border:1px solid var(--linha); border-radius:var(--r-sm); background:var(--cartao-2); color:var(--suave); }
.kpi > b{ margin-top:6px; font-size:26px; font-weight:600; line-height:1.2; font-variant-numeric:tabular-nums; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.kpi > small{ color:var(--suave); font-size:12px; }
/* Tabela inteligente (lib/componentes.php e tabela.js): cabecalho com busca, Filtros e Ocultos;
   a tabela rola por dentro com o titulo fixo; embaixo, Mostrando e Por pagina */
.tcard{ background:var(--cartao); border:1px solid var(--linha); border-radius:var(--r-md); margin:0 0 20px; }
.tcard-cab{ display:flex; flex-wrap:wrap; align-items:center; gap:10px 12px; padding:12px 16px; border-bottom:1px solid var(--linha); }
.tcard-ico{ display:inline-flex; flex:none; align-items:center; justify-content:center; width:32px; height:32px; border:1px solid var(--linha); border-radius:var(--r-sm); background:var(--cartao-2); color:var(--suave); }
.tcard-cab h2{ margin:0; font-size:15px; font-weight:600; }
.tcard-conta{ min-width:22px; padding:0 7px; border-radius:6px; background:var(--cartao-2); color:var(--suave); font-size:12px; line-height:22px; text-align:center; font-variant-numeric:tabular-nums; }
.tcard-ferr{ position:relative; margin-left:auto; display:flex; flex-wrap:wrap; align-items:center; gap:8px; }
.tcard-busca{ position:relative; display:flex; align-items:center; margin:0; color:var(--suave); }
.tcard-busca .ico{ position:absolute; left:10px; pointer-events:none; }
.tcard-busca input{ width:260px; min-width:0; padding-left:32px; }
.tcard-n{ min-width:18px; padding:0 5px; border-radius:5px; background:var(--cartao-2); color:var(--suave); font-size:11px; line-height:18px; text-align:center; }
.tcard-icone{ width:var(--alt); padding:0 !important; }
.tcard-corpo{ margin:0; border:0; border-radius:0; max-height:min(72vh, 780px); overflow:auto; }
.tcard-corpo thead th, .tcard-corpo tr:first-child > th{ position:sticky; top:0; z-index:3; }
.tcard-pe{ display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:10px; padding:10px 16px; border-top:1px solid var(--linha); color:var(--suave); font-size:12px; }
.tcard-paginas{ display:inline-flex; align-items:center; gap:8px; }
.tcard-paginas .tcard-icone{ min-height:28px; width:28px; }
.tcard-por{ display:inline-flex !important; flex-direction:row !important; align-items:center; gap:8px; margin:0; font-size:12px; color:var(--suave); }
.tcard-por .sel-botao{ min-width:84px; }
.tcard-pop{ position:absolute; right:0; top:calc(100% + 6px); z-index:30; min-width:240px; max-width:min(340px, calc(100vw - 24px)); max-height:60vh; overflow:auto; padding:8px; background:var(--cartao); border:1px solid var(--linha-forte); border-radius:10px; box-shadow:0 12px 32px var(--sombra); }
.tcard-pop b{ display:block; padding:6px 8px 4px; font-size:12px; font-weight:600; color:var(--suave); }
.tcard-pop label{ display:flex; align-items:center; gap:8px; margin:0; padding:6px 8px; border-radius:var(--r-sm); color:var(--texto); font-size:13px; cursor:pointer; }
.tcard-pop label:hover{ background:var(--hover); }
.tcard-pop .tcard-todos{ font-weight:600; }
.col-oculta{ display:none !important; }
@media (max-width:640px){ .tcard-ferr{ width:100%; margin-left:0; } .tcard-busca{ flex:1 1 100%; } .tcard-busca input{ width:100%; } }
/* Celulas prontas da tabela inteligente */
.selo-p{ display:inline-flex; align-items:center; gap:6px; padding:1px 8px; border:1px solid var(--linha); border-radius:6px; background:var(--cartao-2); color:var(--texto); font-size:12px; line-height:20px; white-space:nowrap; }
.selo-p i{ width:6px; height:6px; border-radius:50%; background:var(--marca); }
.selo-verde i{ background:var(--ok); } .selo-laranja i{ background:var(--alerta); } .selo-vermelho i{ background:var(--erro); } .selo-roxo i{ background:#8B5CF6; } .selo-cinza i{ background:var(--apagado); }
.ponto{ display:inline-block; width:8px; height:8px; margin-left:6px; border-radius:50%; vertical-align:1px; }
.ponto.ok{ background:var(--ok); box-shadow:0 0 0 3px color-mix(in srgb, var(--ok) 22%, transparent); } .ponto.off{ background:var(--apagado); }
.anel-p{ display:inline-flex; align-items:center; gap:6px; padding:1px 8px; border:1px solid var(--linha); border-radius:6px; font-variant-numeric:tabular-nums; white-space:nowrap; }
.anel-p svg{ width:16px; height:16px; }
.anel-p .anel-fundo{ fill:none; stroke:var(--linha-forte); stroke-width:5; }
.anel-p .anel-valor{ fill:none; stroke:var(--erro); stroke-width:5; stroke-linecap:round; }
.mini-graf{ display:block; width:120px; height:28px; }
.mini-graf .mini-linha{ fill:none; stroke:var(--ok); stroke-width:1.6; vector-effect:non-scaling-stroke; }
.mini-graf .mini-area{ fill:color-mix(in srgb, var(--ok) 16%, transparent); stroke:none; }
.data-c{ display:inline-flex; align-items:center; gap:6px; white-space:nowrap; } .data-c .ico{ color:var(--suave); }
.prazo{ display:inline-flex; align-items:center; gap:6px; padding:1px 8px; border:1px solid color-mix(in srgb, var(--marca) 45%, transparent); border-radius:6px; background:var(--realce); color:var(--marca); font-size:12px; line-height:20px; white-space:nowrap; }
.menu-linha{ position:relative; display:inline-block; }
.menu-linha > summary{ list-style:none; display:inline-flex; align-items:center; justify-content:center; width:30px; height:30px; border-radius:var(--r-sm); color:var(--suave); cursor:pointer; }
.menu-linha > summary::-webkit-details-marker{ display:none; }
.menu-linha > summary:hover, .menu-linha[open] > summary{ background:var(--hover); color:var(--texto); }
.menu-linha-painel{ position:absolute; right:0; top:calc(100% + 4px); z-index:20; min-width:180px; padding:5px; background:var(--cartao); border:1px solid var(--linha-forte); border-radius:10px; box-shadow:0 12px 32px var(--sombra); }
.menu-linha-painel a, .menu-linha-painel button{ display:flex; width:100%; justify-content:flex-start; align-items:center; gap:8px; min-height:34px; padding:0 10px; border:0; border-radius:var(--r-sm); background:none; color:var(--texto); font-size:13px; font-weight:400; text-align:left; text-decoration:none; }
.menu-linha-painel a:hover, .menu-linha-painel button:hover{ background:var(--hover); text-decoration:none; }
.menu-linha-painel .perigo{ color:var(--erro); }
.menu-linha-painel form{ margin:0; }
.menu-linha-painel .ico{ color:var(--suave); } .menu-linha-painel .perigo .ico{ color:var(--erro); }
:root{
  --fundo:#F7F8FA; --cartao:#FFFFFF; --cartao-2:#F3F4F6; --hover:#F7F9FC;
  --texto:#111827; --suave:#6B7280; --apagado:#9CA3AF; --linha:#E5E7EB; --linha-forte:#D1D5DB;
  --marca:#1D6FF2; --marca-hover:#155FD6;
  --ok:#15803D; --alerta:#B45309; --erro:#B91C1C; --laranja:#C2410C;
  --lateral:#0B1B2E; --realce:#EEF4FF; --fundo-ok:#ECFDF3; --fundo-alerta:#FFF7ED; --fundo-erro:#FEF2F2; --sombra:rgba(17,24,39,.12);
  --grad-a:#1D6FF2; --grad-b:#60A5FA; --rolagem:#C9CED6; --rolagem-hover:#9CA3AF;
  --f-texto:"Inter",system-ui,-apple-system,"Segoe UI",sans-serif; --f-titulo:var(--f-texto); --f-mono:"JetBrains Mono",ui-monospace,Consolas,monospace;
  --r-sm:6px; --r-md:8px; --r-lg:8px;
}
/* Aparencia (lib/tema.php): a cor base escolhida vira o fundo e o resto sai dela. Base escura:
   texto claro, cartoes e linhas um pouco mais claros que o fundo, cores de estado mais vivas
   (no escuro, o verde e o vermelho da planilha precisam de mais brilho para contrastar). */
:root[data-tema=claro][data-base]{
  --fundo:var(--base); --cartao:color-mix(in srgb, var(--base), #fff 75%); --cartao-2:color-mix(in srgb, var(--base), #000 3%);
  --hover:color-mix(in srgb, var(--base), #fff 45%); --linha:color-mix(in srgb, var(--base), #000 9%); --linha-forte:color-mix(in srgb, var(--base), #000 16%);
}
:root[data-tema=escuro]{
  color-scheme:dark;
  --fundo:var(--base); --cartao:color-mix(in srgb, var(--base), #fff 5%); --cartao-2:color-mix(in srgb, var(--base), #fff 9%);
  --hover:color-mix(in srgb, var(--base), #fff 7%); --linha:color-mix(in srgb, var(--base), #fff 13%); --linha-forte:color-mix(in srgb, var(--base), #fff 22%);
  --texto:#E5E7EB; --suave:#9CA3AF; --apagado:#6B7280;
  --marca:#3B82F6; --marca-hover:#2563EB;
  --ok:#22C55E; --alerta:#F59E0B; --erro:#F05252; --laranja:#FB923C;
  --lateral:color-mix(in srgb, var(--base), #000 35%); --realce:rgba(59,130,246,.16);
  --fundo-ok:rgba(34,197,94,.14); --fundo-alerta:rgba(245,158,11,.14); --fundo-erro:rgba(240,82,82,.14); --sombra:rgba(0,0,0,.55);
  --grad-a:#2563EB; --grad-b:#93C5FD; --rolagem:color-mix(in srgb, var(--base), #fff 22%); --rolagem-hover:color-mix(in srgb, var(--base), #fff 38%);
}
:root[data-tema=escuro] .lateral{ border-right:1px solid var(--linha); }
*{ box-sizing:border-box; }
/* Barras de rolagem finas e suaves, nas cores do tema (no lugar das nativas) */
*{ scrollbar-width:thin; scrollbar-color:var(--rolagem) transparent; }
::-webkit-scrollbar{ width:8px; height:8px; }
::-webkit-scrollbar-track{ background:transparent; }
::-webkit-scrollbar-thumb{ background:var(--rolagem); border-radius:8px; border:2px solid transparent; background-clip:padding-box; }
::-webkit-scrollbar-thumb:hover{ background:var(--rolagem-hover); background-clip:padding-box; }
::-webkit-scrollbar-corner{ background:transparent; }
:root{ --alt:34px; }
@media (pointer:coarse){ :root{ --alt:40px; } }
.avatar{ display:inline-flex; flex:none; align-items:center; justify-content:center; border-radius:50%; object-fit:cover; }
.avatar-letra{ background:var(--av); color:#fff; font-weight:600; line-height:1; }
.casca{ display:flex; min-height:100vh; }
.conteudo{ flex:1; min-width:0; }
/* Barra lateral do admin: os paineis em cima e a conta embaixo (no celular, uma linha no alto) */
.lateral{ width:64px; flex:none; z-index:20; background:var(--lateral); display:flex; flex-direction:column; align-items:center; padding:12px 0; position:sticky; top:0; height:100vh; color:#9CA3AF; }
.lateral-alterna{ display:none; }
.lateral-itens{ flex:1; min-height:0; width:100%; display:flex; flex-direction:column; align-items:center; justify-content:space-between; }
.lateral-paineis, .lateral-conta{ display:flex; flex-direction:column; align-items:center; gap:4px; }
.lateral-paineis a{ width:48px; padding:8px 0 6px; border-radius:var(--r-sm); display:flex; flex-direction:column; align-items:center; gap:3px; color:#9CA3AF; font-size:10.5px; font-weight:500; }
.lateral-paineis a:hover{ background:rgba(255,255,255,.06); color:#fff; text-decoration:none; }
.lateral-paineis a.atual{ background:rgba(255,255,255,.1); color:#fff; }
.lateral-paineis svg{ width:20px; height:20px; }
.lateral-conta{ gap:6px; }
.lateral .conta-icone, .lateral .tema-menu > summary{ display:inline-flex; align-items:center; justify-content:center; width:40px; height:40px; border-radius:var(--r-sm); color:#9CA3AF; }
.lateral .conta-icone:hover, .lateral .tema-menu > summary:hover, .lateral .tema-menu[open] > summary{ background:rgba(255,255,255,.08); color:#fff; text-decoration:none; }
.lateral .conta-icone.atual{ background:rgba(255,255,255,.1); color:#fff; }
.lateral .conta-perfil{ display:inline-flex; align-items:center; gap:8px; padding:3px; margin-top:4px; border-radius:999px; color:#fff; font-weight:500; }
.lateral .conta-perfil:hover{ background:rgba(255,255,255,.08); text-decoration:none; }
.lateral .conta-perfil > span:not(.avatar){ display:none; }
.lateral .tema-painel{ left:calc(100% + 10px); right:auto; top:auto; bottom:0; color:var(--texto); }
.tema-menu{ position:relative; }
.tema-menu > summary{ list-style:none; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; width:32px; height:32px; border-radius:var(--r-sm); color:var(--suave); }
.tema-menu > summary::-webkit-details-marker{ display:none; }
.tema-menu > summary:hover, .tema-menu[open] > summary{ background:var(--hover); color:var(--marca); }
.tema-painel{ position:absolute; z-index:40; right:0; top:calc(100% + 6px); width:300px; padding:14px; background:var(--cartao); border:1px solid var(--linha-forte); border-radius:var(--r-md); box-shadow:0 10px 30px var(--sombra); }
.tema-painel h3{ margin:0 0 10px; font-size:11px; font-weight:600; letter-spacing:.08em; text-transform:uppercase; color:var(--suave); }
.tema-prontos{ display:grid; grid-template-columns:repeat(3,1fr); gap:8px; margin:0 0 12px; }
.tema-pronto{ display:flex; flex-direction:column; align-items:center; gap:6px; min-height:0; padding:8px 6px; background:var(--cartao); color:var(--texto); border:1px solid var(--linha-forte); border-radius:var(--r-md); font-weight:500; }
.tema-pronto:hover{ background:var(--hover); border-color:var(--marca); }
.tema-pronto.atual{ border:2px solid var(--marca); padding:7px 5px; }
.tema-previa{ display:grid; grid-template-columns:30% 1fr; grid-template-rows:repeat(3,1fr); gap:3px; width:64px; height:42px; padding:5px; border-radius:6px; background:var(--p); border:1px solid rgba(127,127,127,.3); }
.tema-previa i{ grid-column:2; border-radius:2px; background:color-mix(in srgb, var(--p), #000 12%); }
.tema-previa[data-escuro="1"] i{ background:color-mix(in srgb, var(--p), #fff 22%); }
.tema-previa i:first-child{ grid-row:1 / -1; grid-column:1; }
.tema-livre{ display:flex; flex-direction:column; gap:6px; font-size:12px; color:var(--suave); }
.tema-livre > span:last-child{ display:flex; align-items:center; gap:8px; }
.tema-livre input[type=color]{ width:44px; min-width:0; height:32px; padding:2px; cursor:pointer; }
@media (max-width:760px){
  .casca{ flex-direction:column; }
  .lateral{ width:auto; height:auto; position:relative; flex-direction:column; align-items:stretch; padding:0; }
  .lateral-alterna{ display:flex; align-items:center; gap:10px; width:100%; min-height:48px; padding:max(6px, env(safe-area-inset-top)) 16px 6px; background:none; border:0; border-radius:0; color:#E5E7EB; font-size:14px; }
  .lateral-alterna:hover{ background:rgba(255,255,255,.04); }
  .lateral-alterna > svg:first-child{ width:18px; height:18px; color:#9CA3AF; }
  .lateral-alterna .avatar{ margin-left:auto; }
  .lateral-alterna .seta{ width:14px; height:14px; fill:none; stroke:currentColor; stroke-width:1.8; stroke-linecap:round; transition:transform .2s; }
  .lateral.aberta .lateral-alterna .seta{ transform:rotate(180deg); }
  .lateral-itens{ display:none; }
  .lateral.aberta .lateral-itens{ display:flex; flex-direction:row; flex-wrap:wrap; justify-content:space-between; align-items:center; gap:10px; padding:2px 12px 12px; }
  .lateral-paineis, .lateral-conta{ flex-direction:row; }
  .lateral-paineis a{ flex-direction:row; width:auto; padding:8px 14px; gap:6px; font-size:13px; }
  .lateral-paineis svg{ width:18px; height:18px; }
  .lateral .conta-perfil{ margin:0; padding-right:12px; }
  .lateral .conta-perfil > span:not(.avatar){ display:inline; }
  .lateral .tema-painel{ position:fixed; left:12px; right:12px; top:auto; bottom:calc(76px + env(safe-area-inset-bottom)); width:auto; }
}
@media (display-mode:standalone) and (min-width:761px){ .lateral{ padding-top:max(12px, env(safe-area-inset-top)); } }
CSS;
}

// Barra lateral do admin inteiro: em cima os paineis que o usuario pode abrir (CMS, quando o
// painel mora num admin com CMS, config "menu_cms", e UTM); embaixo a conta, que e do admin
// todo: Usuarios (quem pode), aparencia, Configuracoes e a foto de quem entrou. No celular vira
// uma linha no alto, recolhida (a seta abre); os filtros do topo ficam sempre a mostra.
// $aqui: o painel aberto (utm, cms) ou a tela do nucleo (conta, usuarios); $utm: caminho ate a
// pasta do painel UTM (padrao: admin_base(); 'utm/' no CMS); $cms: o link do CMS.
function admin_lateral(string $aqui = 'utm', ?string $utm = null, ?string $cms = null): string
{
    $utm = $utm ?? admin_base();
    $cms = $cms ?? (string)((track_config() ?? [])['menu_cms'] ?? '');
    $u = (string)usuario_atual();
    $paineis = [];
    if ($cms !== '' && usuario_pode('cms')) {
        $paineis['cms'] = ['CMS', $cms, '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>'];
    }
    if (usuario_pode('utm')) {
        $paineis['utm'] = ['UTM', $utm === '' ? './' : $utm, '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>'];
    }
    $atual = $paineis[$aqui] ?? ['Admin', './', icone(['usuarios' => 'usuarios'][$aqui] ?? 'usuario', 20)];
    $marca = fn(string $tela) => $aqui === $tela ? ' atual" aria-current="page' : '';
    $h = '<nav class="lateral" aria-label="Admin" data-lateral><input type="hidden" id="csrf-painel" value="' . e(token_csrf()) . '">'
        . '<button type="button" class="lateral-alterna" data-lateral-alterna aria-expanded="false" aria-controls="lateral-itens" aria-label="Abrir o menu do admin">'
        . $atual[2] . '<b>' . e($atual[0]) . '</b>' . avatar_html($u, 26, $utm) . '<svg class="seta" viewBox="0 0 16 16" aria-hidden="true"><path d="M4 6l4 4 4-4"/></svg></button>'
        . '<div class="lateral-itens" id="lateral-itens"><div class="lateral-paineis">';
    foreach ($paineis as $k => [$rot, $href, $ico]) {
        $h .= '<a href="' . e($href) . '"' . ($k === $aqui ? ' class="atual" aria-current="page"' : '') . ' title="' . e($rot) . '">' . $ico . '<span>' . e($rot) . '</span></a>';
    }
    $aquiUrl = destino_seguro((string)($_SERVER['REQUEST_URI'] ?? ''));
    $h .= '</div><div class="lateral-conta">';
    if (usuario_pode('usuarios')) {
        $h .= '<a class="conta-icone' . $marca('usuarios') . '" href="' . e(admin_url('usuarios', $utm)) . '" title="Usuários: quem entra e o que cada um pode abrir" aria-label="Usuários">' . icone('usuarios', 19) . '</a>';
    }
    $h .= '<details class="tema-menu"><summary title="Aparência: claro, escuro ou outra cor de fundo" aria-label="Aparência">' . icone('paleta', 19) . '</summary>'
        . '<div class="tema-painel"><h3>Aparência</h3>' . tema_form($aquiUrl === './' ? './' : $aquiUrl, $utm) . '</div></details>'
        . '<a class="conta-icone' . $marca('conta') . '" href="' . e(admin_url('conta', $utm)) . '" title="Minha conta: foto, senha, aparência e sair" aria-label="Minha conta">' . icone('config', 19) . '</a>'
        . '<a class="conta-perfil" href="' . e(admin_url('conta', $utm) . '#perfil') . '" title="' . e($u) . ': foto do perfil e sair">' . avatar_html($u, 32, $utm) . '<span>' . e($u) . '</span></a>'
        . '</div></div></nav>';
    return $h;
}

// Cabecalho das telas do nucleo (Minha conta, Usuarios): "Admin" e as abas do nucleo, no lugar
// das abas do painel UTM (essas telas sao do admin inteiro). No celular continua a mostra.
function admin_cabecalho(string $atual): void
{
    $abas = ['conta' => ['Minha conta', 'usuario']];
    if (usuario_pode('usuarios')) {
        $abas['usuarios'] = ['Usuários', 'usuarios'];
    }
    echo '<nav class="abas abas-nucleo" aria-label="Admin"><span class="abas-titulo">Admin</span>';
    foreach ($abas as $tela => [$rot, $ico]) {
        echo '<a href="' . e(admin_url($tela)) . '"' . ($tela === $atual ? ' class="atual" aria-current="page"' : '') . '>' . icone($ico) . e($rot) . '</a>';
    }
    echo '</nav>';
}
