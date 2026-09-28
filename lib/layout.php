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
<style>
:root{ --fundo:#f5f6f8; --cartao:#fff; --texto:#1c2330; --suave:#667085; --linha:#e4e7ec; --marca:#1d6ff2; --ok:#12805c; --alerta:#b54708; --erro:#c01048; }
@media (prefers-color-scheme: dark){ :root{ --fundo:#0f1318; --cartao:#171c23; --texto:#e7ebf0; --suave:#98a2b3; --linha:#2a313b; --marca:#5b9bff; --ok:#3ccb95; --alerta:#f5a15b; --erro:#ff6b98; } }
*{ box-sizing:border-box; }
body{ margin:0; font:14px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; background:var(--fundo); color:var(--texto); }
a{ color:var(--marca); }
code, pre{ font-family:ui-monospace,SFMono-Regular,Consolas,monospace; font-size:12.5px; }
pre{ background:var(--cartao); border:1px solid var(--linha); padding:10px 12px; border-radius:8px; overflow-x:auto; white-space:pre-wrap; word-break:break-all; }
.topo{ position:sticky; top:0; z-index:5; background:var(--cartao); border-bottom:1px solid var(--linha); padding:10px 16px; display:flex; flex-wrap:wrap; gap:10px; align-items:end; }
.topo h1{ font-size:16px; margin:0 12px 0 0; align-self:center; }
.topo label{ display:flex; flex-direction:column; font-size:11.5px; color:var(--suave); gap:2px; }
select, input, textarea, button{ font:inherit; color:inherit; }
select, input, textarea{ background:var(--fundo); border:1px solid var(--linha); border-radius:8px; padding:6px 8px; min-width:170px; }
button{ background:var(--marca); color:#fff; border:0; border-radius:8px; padding:7px 14px; cursor:pointer; }
.topo .sair{ margin-left:auto; align-self:center; }
.topo .sair button{ background:transparent; color:var(--suave); border:1px solid var(--linha); }
.abas{ display:flex; gap:4px; padding:10px 16px 0; flex-wrap:wrap; }
.abas a{ padding:7px 12px; border-radius:8px 8px 0 0; text-decoration:none; color:var(--suave); border:1px solid transparent; }
.abas a.atual{ background:var(--cartao); color:var(--texto); border-color:var(--linha); border-bottom-color:var(--cartao); }
main{ padding:16px; }
.numeros{ display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:10px; margin-bottom:16px; }
.numero{ background:var(--cartao); border:1px solid var(--linha); border-radius:10px; padding:12px; }
.numero b{ display:block; font-size:22px; }
.numero span{ color:var(--suave); font-size:12px; }
.tabela{ overflow-x:auto; background:var(--cartao); border:1px solid var(--linha); border-radius:10px; margin-bottom:16px; }
table{ border-collapse:collapse; width:100%; }
th, td{ text-align:left; padding:8px 10px; border-bottom:1px solid var(--linha); vertical-align:top; white-space:nowrap; }
th{ font-size:12px; color:var(--suave); font-weight:600; }
td.quebra{ white-space:normal; min-width:220px; }
tr:last-child td{ border-bottom:0; }
.selo{ display:inline-block; padding:1px 8px; border-radius:99px; font-size:12px; border:1px solid currentColor; }
.selo.ok{ color:var(--ok); } .selo.alerta{ color:var(--alerta); } .selo.erro{ color:var(--erro); } .selo.neutro{ color:var(--suave); }
.suave{ color:var(--suave); }
h2{ font-size:15px; margin:20px 0 8px; }
.caixa-login{ max-width:380px; margin:10vh auto; background:var(--cartao); border:1px solid var(--linha); border-radius:12px; padding:24px; }
.caixa-login.larga{ max-width:720px; margin-top:5vh; }
.caixa-login h1{ font-size:20px; margin-top:0; }
.caixa-login label{ display:flex; flex-direction:column; gap:4px; margin-bottom:12px; color:var(--suave); font-size:13px; }
.caixa-login input, .caixa-login textarea{ width:100%; }
.erro{ color:var(--erro); }
@media (max-width:640px){ .topo .sair{ margin-left:0; } select{ min-width:140px; } }
</style>
</head>
<body>
<?php
}

function pagina_fim(): void
{
    echo '<script src="painel.js"></script></body></html>';
}

// Barra do topo: dominio e, dependendo dele, a pagina. Mais periodo e sair.
function barra_topo(array $filtro, array $dominios, array $paginas, string $aba): void
{
    $periodos = ['hoje' => 'Hoje', 'ontem' => 'Ontem', '7d' => 'Últimos 7 dias', '30d' => 'Últimos 30 dias', 'tudo' => 'Tudo'];
    ?>
<form class="topo" method="get" action="./" id="filtros">
  <h1>Rastreio</h1>
  <input type="hidden" name="aba" value="<?= e($aba) ?>">
  <label>Domínio
    <select name="dominio" data-reinicia="pagina">
      <option value="">Todos os domínios</option>
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
<form class="topo-sair" method="post" action="sair.php" id="form-sair"><input type="hidden" name="csrf" value="<?= e(token_csrf()) ?>"></form>
<?php
    $abas = ['resumo' => 'Conferência', 'vendas' => 'Vendas', 'visitantes' => 'Visitantes', 'eventos' => 'Eventos'];
    echo '<nav class="abas">';
    foreach ($abas as $id => $rotulo) {
        $q = http_build_query(['aba' => $id, 'dominio' => $filtro['dominio'], 'pagina' => $filtro['pagina'], 'periodo' => $filtro['periodo']]);
        echo '<a href="?' . e($q) . '" class="' . ($aba === $id ? 'atual' : '') . '">' . e($rotulo) . '</a>';
    }
    echo '<a href="#" data-sair>Sair</a></nav>';
}
