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
@media (max-width:640px){ .topo, .abas{ padding-left:16px; padding-right:16px; } main{ padding:16px; } select{ min-width:140px; }
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

// Barra do topo: dominio e, dependendo dele, a pagina. Mais periodo e sair.
function barra_topo(array $filtro, array $dominios, array $paginas, string $aba): void
{
    $periodos = ['hoje' => 'Hoje', 'ontem' => 'Ontem', '7d' => 'Últimos 7 dias', '30d' => 'Últimos 30 dias', 'tudo' => 'Tudo'];
    ?>
<form class="topo" method="get" action="./" id="filtros">
  <h1>UTM · <?= e(['trafego' => 'Tráfego', 'resumo' => 'Conferência', 'vendas' => 'Vendas', 'visitantes' => 'Visitantes', 'eventos' => 'Eventos'][$aba] ?? 'Tráfego') ?></h1>
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
<?php
    abas_painel($aba, $filtro);
}

// Abas do painel, API Kiwify, Usuarios e Sair. $filtro vazio (tela de usuarios): links sem filtro.
function abas_painel(string $aba, array $filtro = []): void
{
    echo '<form class="topo-sair" method="post" action="sair.php" id="form-sair"><input type="hidden" name="csrf" value="' . e(token_csrf()) . '"></form>';
    $abas = ['trafego' => 'Tráfego', 'resumo' => 'Conferência', 'vendas' => 'Vendas', 'visitantes' => 'Visitantes', 'eventos' => 'Eventos'];
    echo '<nav class="abas">';
    foreach ($abas as $id => $rotulo) {
        $q = http_build_query(['aba' => $id] + array_intersect_key($filtro, ['dominio' => 1, 'pagina' => 1, 'periodo' => 1]));
        echo '<a href="./?' . e($q) . '" class="' . ($aba === $id ? 'atual' : '') . '">' . e($rotulo) . '</a>';
    }
    echo '<a href="kiwify-api.php" class="' . ($aba === 'kiwify-api' ? 'atual' : '') . '">API Kiwify</a>';
    echo '<a href="usuarios.php" class="' . ($aba === 'usuarios' ? 'atual' : '') . '">Usuários</a>';
    echo '<a href="#" data-sair>Sair (' . e((string)usuario_atual()) . ')</a></nav>';
}
