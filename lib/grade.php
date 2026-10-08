<?php
// Telas montaveis: o motor que deixa qualquer tela do admin ser montada por quem usa, no estilo do
// Power BI e do modo de edicao da UTMify. Comecou no Painel do UTM (07/10/2026), que virou o Resumo,
// e vale para todas as telas: hoje o Resumo e o Financeiro.
//
// Cada tela montavel e descrita por um array (exemplos: resumo_grade() em lib/painel.php e
// financeiro_grade() em lib/financeiro.php):
//   id          chave da tela no layout salvo ('resumo', 'financeiro'...)
//   titulo      nome da tela (vai na barra de edicao)
//   acesso      o acesso que abre a tela (TRACK_ACESSOS): quem grava o layout precisa dele
//   categorias  [chave => nome] das secoes da biblioteca de blocos
//   blocos      tipo => [titulo, categoria, desenho ('numero' ou 'grafico'), dica (a conta, no (i)),
//               [w, h, minW, minH, maxW, maxH] na grade de 12 colunas, icone, [fontes de que depende]]
//   padrao      [[tipo, x, y, w, h], ...]: o layout de quem nunca mexeu (no computador)
//   desenhar    fn(string $tipo, array $ctx): string, o miolo de cada bloco com os numeros do periodo
//   fontes      [fonte => [ligada, o que falta, link, nome]] (opcional): integracao desligada aparece
//               no bloco como "o que falta ligar", em vez de zero
//
// O motor faz o resto:
// - Ver: o servidor desenha a grade com CSS Grid (12 colunas no computador, 2 no celular), sem JS.
// - Montar (montar=1 no endereco da tela, pelo lapis da barra lateral): GridStack (gridstack.js, MIT,
//   copiado no painel por causa da CSP) com a biblioteca de blocos do lado (a conta de cada um ao
//   passar o mouse; os que ja estao na tela ficam apagados), a barra de edicao (Computador | Celular,
//   Voltar ao padrao, Cancelar, Salvar) e os blocos com os dados de verdade. Grava o painel-salvar.php.
// - Layout por pessoa, por tela e por aparelho, na tabela painel_layouts (banco v15), com a chave
//   "<tela>:<aparelho>". As linhas antigas, so com o aparelho, sao do Painel, que virou o Resumo.
//
// Tela nova: monte o array numa funcao, registre em GRADE_TELAS (o painel-salvar.php usa para
// validar), chame grade_lapis('<id>') antes de casca_inicio() (o lapis aparece na barra lateral) e
// grade_render($tela, $ctx) no lugar dos blocos fixos.

const GRADE_COLUNAS = ['computador' => 12, 'celular' => 2];
const GRADE_LINHA_PX = 110;
// id da tela => [arquivos em lib/ que ela usa, funcao que devolve o array da tela]
const GRADE_TELAS = [
    'resumo' => [['painel.php'], 'resumo_grade'],
    'financeiro' => [['resumo.php', 'financeiro.php'], 'financeiro_grade'],
    'fluxo' => [['resumo.php', 'financeiro.php'], 'fluxo_grade'],
];

// A tela aberta e montavel: a tela chama antes de casca_inicio() e a barra lateral mostra o lapis
// (grade_lapis_html). Sem chamada, nao ha lapis.
function grade_lapis(?string $tela = null): ?string
{
    static $t = null;
    if ($tela !== null) {
        $t = $tela;
    }
    return $t;
}

// O endereco da tela aberta, sem o modo de edicao ("./?aba=geral&periodo=7d", "financeiro.php?...")
function grade_aqui(): string
{
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $caminho = (string)parse_url($uri, PHP_URL_PATH);
    $arquivo = substr($caminho, -1) === '/' || $caminho === '' ? './' : basename($caminho);
    if (!preg_match('/^(\.\/|[a-z0-9_-]+\.php)$/', $arquivo)) {
        $arquivo = './';
    }
    parse_str((string)parse_url($uri, PHP_URL_QUERY), $q);
    unset($q['montar'], $q['aparelho']);
    return $arquivo . ($q ? '?' . http_build_query($q) : '');
}

function grade_url(string $url, array $mais): string
{
    return $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($mais);
}

// Volta depois de gravar: so um endereco do proprio painel ("./?..." ou "<tela>.php?...")
function grade_volta_segura(string $volta): string
{
    return strlen($volta) <= 1500 && preg_match('#^(\./|[a-z0-9_-]+\.php)(\?[^\s\\\\]*)?$#', $volta) ? $volta : './';
}

function grade_editando(): bool
{
    return ($_GET['montar'] ?? '') === '1';
}

function grade_aparelho(): string
{
    return ($_GET['aparelho'] ?? '') === 'celular' ? 'celular' : 'computador';
}

// Periodo anterior do mesmo tamanho, como periodo personalizado ("2026-09-24_2026-09-30"), ou null.
// "Este mes" compara com os mesmos dias do mes passado; "Hoje" e "Tudo" nao comparam (o dia esta
// pela metade e o gasto da Meta vem por dia inteiro).
function grade_periodo_anterior(string $periodo): ?string
{
    if (in_array($periodo, ['hoje', 'tudo'], true)) {
        return null;
    }
    [$d1, $d2] = periodo_dias($periodo);
    $ini = new DateTime($d1);
    if ($periodo === 'mes') {
        $a1 = (clone $ini)->modify('first day of last month');
        $a2 = (clone $a1)->modify('+' . ((int)(new DateTime($d2))->format('j') - 1) . ' days');
        $fim = (clone $a1)->modify('last day of this month');
        $a2 = $a2 > $fim ? $fim : $a2;
    } elseif ($periodo === 'mes_passado') {
        $a1 = (clone $ini)->modify('first day of last month');
        $a2 = (clone $ini)->modify('-1 day');
    } else {
        $n = (int)$ini->diff(new DateTime($d2))->days + 1;
        $a1 = (clone $ini)->modify('-' . $n . ' days');
        $a2 = (clone $ini)->modify('-1 day');
    }
    return $a1->format('Y-m-d') . '_' . $a2->format('Y-m-d');
}

// O lapis da barra lateral: monta a tela aberta; montando, volta para ela (com a confirmacao do
// painel.js quando ha mudanca sem salvar)
function grade_lapis_html(): string
{
    if (grade_lapis() === null) {
        return '';
    }
    $aqui = grade_aqui();
    if (grade_editando()) {
        return '<a class="conta-icone lapis-tela atual" aria-current="true" href="' . e($aqui) . '" data-recarrega data-painel-cancelar title="Sair da edição sem salvar (Esc)" aria-label="Sair da edição">' . icone('lapis', 19) . '</a>';
    }
    return '<a class="conta-icone lapis-tela" href="' . e(grade_url($aqui, ['montar' => 1])) . '" data-recarrega title="Montar esta tela: arrastar, aumentar, tirar e acrescentar blocos" aria-label="Montar esta tela">' . icone('lapis', 19) . '</a>';
}

// O array da tela pelo id (para gravar sem desenhar a tela)
function grade_tela(string $id): ?array
{
    $t = GRADE_TELAS[$id] ?? null;
    if (!$t) {
        return null;
    }
    foreach ($t[0] as $arq) {
        require_once __DIR__ . '/' . $arq;
    }
    return is_callable($t[1]) ? ($t[1])() : null;
}

// Layout valido: so blocos da tela, sem repetir, no tamanho permitido e dentro da grade
function grade_validar(array $tela, array $itens, string $aparelho): array
{
    $cols = GRADE_COLUNAS[$aparelho] ?? 12;
    $blocos = $tela['blocos'];
    $vistos = [];
    $saida = [];
    foreach (array_slice($itens, 0, 80) as $i) {
        $t = is_array($i) ? (string)($i['tipo'] ?? '') : '';
        if (!isset($blocos[$t]) || isset($vistos[$t])) {
            continue;
        }
        [$w0, $h0, $minW, $minH, $maxW, $maxH] = $blocos[$t][4];
        if ($cols < 12) {
            [$minW, $maxW] = [1, $cols];
            $w0 = $blocos[$t][2] === 'numero' ? 1 : $cols;
        }
        $w = max($minW, min($maxW, $cols, (int)($i['w'] ?? $w0)));
        $h = max($minH, min($maxH, (int)($i['h'] ?? $h0)));
        $saida[] = ['tipo' => $t, 'x' => max(0, min($cols - $w, (int)($i['x'] ?? 0))), 'y' => max(0, min(500, (int)($i['y'] ?? 0))), 'w' => $w, 'h' => $h];
        $vistos[$t] = true;
    }
    usort($saida, fn($a, $b) => [$a['y'], $a['x']] <=> [$b['y'], $b['x']]);
    return $saida;
}

function grade_padrao(array $tela): array
{
    return grade_validar($tela, array_map(fn($p) => ['tipo' => $p[0], 'x' => $p[1], 'y' => $p[2], 'w' => $p[3], 'h' => $p[4]], $tela['padrao']), 'computador');
}

// Celular a partir do computador: de cima para baixo, numeros dois por linha e graficos na
// largura toda
function grade_para_celular(array $tela, array $itens): array
{
    $saida = [];
    $y = 0;
    $meia = null; // linha com a metade direita livre
    foreach ($itens as $i) {
        $b = $tela['blocos'][$i['tipo']] ?? null;
        if (!$b) {
            continue;
        }
        if ($b[2] === 'numero') {
            if ($meia !== null) {
                $saida[] = ['tipo' => $i['tipo'], 'x' => 1, 'y' => $meia, 'w' => 1, 'h' => 1];
                $meia = null;
            } else {
                $saida[] = ['tipo' => $i['tipo'], 'x' => 0, 'y' => $y, 'w' => 1, 'h' => 1];
                $meia = $y++;
            }
        } else {
            $meia = null;
            $h = max($b[4][3], min(4, $i['h']));
            $saida[] = ['tipo' => $i['tipo'], 'x' => 0, 'y' => $y, 'w' => 2, 'h' => $h];
            $y += $h;
        }
    }
    return $saida;
}

function grade_salvo(array $tela, string $usuario, string $aparelho): ?array
{
    $st = track_db()->prepare('SELECT layout FROM painel_layouts WHERE usuario = ? AND aparelho = ?');
    $st->execute([$usuario, $tela['id'] . ':' . $aparelho]);
    $j = $st->fetchColumn();
    if ($j === false && $tela['id'] === 'resumo') {
        $st->execute([$usuario, $aparelho]); // o Painel (07/10/2026) virou o Resumo montavel
        $j = $st->fetchColumn();
    }
    $itens = $j === false ? null : json_decode((string)$j, true);
    return is_array($itens) ? grade_validar($tela, $itens, $aparelho) : null;
}

// O layout de cada aparelho: o salvo, ou o padrao (no celular, o do computador arrumado)
function grade_layout(array $tela, string $usuario, string $aparelho): array
{
    $salvo = grade_salvo($tela, $usuario, $aparelho);
    if ($salvo !== null) {
        return $salvo;
    }
    $computador = grade_salvo($tela, $usuario, 'computador') ?? grade_padrao($tela);
    return $aparelho === 'celular' ? grade_para_celular($tela, $computador) : $computador;
}

// Grava o layout (o que vier e validado). [ok, mensagem]
function grade_salvar(array $tela, string $usuario, string $aparelho, string $json): array
{
    if (!isset(GRADE_COLUNAS[$aparelho])) {
        return [false, 'Aparelho inválido.'];
    }
    $itens = json_decode($json, true);
    if (!is_array($itens)) {
        return [false, 'Não deu para ler a tela montada. Recarregue e tente de novo.'];
    }
    $layout = grade_validar($tela, $itens, $aparelho);
    $db = track_db();
    $db->prepare('INSERT INTO painel_layouts (usuario, aparelho, layout, atualizado_em) VALUES (?, ?, ?, ?)
        ON CONFLICT (usuario, aparelho) DO UPDATE SET layout = excluded.layout, atualizado_em = excluded.atualizado_em')
        ->execute([$usuario, $tela['id'] . ':' . $aparelho, json_encode($layout), agora_utc()]);
    if ($tela['id'] === 'resumo') { // a linha antiga do Painel nao volta a valer
        $db->prepare('DELETE FROM painel_layouts WHERE usuario = ? AND aparelho = ?')->execute([$usuario, $aparelho]);
    }
    return [true, $tela['titulo'] . ' salvo (' . $aparelho . ', ' . count($layout) . ' ' . (count($layout) === 1 ? 'bloco' : 'blocos') . ').'];
}

function grade_apagar(array $tela, string $usuario, string $aparelho): void
{
    $st = track_db()->prepare('DELETE FROM painel_layouts WHERE usuario = ? AND aparelho = ?');
    $st->execute([$usuario, $tela['id'] . ':' . $aparelho]);
    if ($tela['id'] === 'resumo') {
        $st->execute([$usuario, $aparelho]);
    }
}

// O bloco: titulo com o (i), icone no canto e o miolo. Integracao desligada: diz o que falta e leva
// para onde liga.
function grade_cartao(array $tela, string $tipo, array $ctx): string
{
    [$titulo, , $desenho, $dica, , $ico, $precisa] = $tela['blocos'][$tipo] + [6 => []];
    $fontes = $tela['fontes'] ?? [];
    $falta = array_values(array_filter($precisa, fn($f) => isset($fontes[$f]) && !$fontes[$f][0]));
    $aviso = '';
    if ($falta) {
        [, $txt, $href] = $fontes[$falta[0]];
        $aviso = '<small class="pw-aviso">' . icone('link', 12) . ($href !== '' ? '<a href="' . e($href) . '">' . e($txt) . '</a>' : e($txt)) . ' para ver este número.</small>';
    }
    return '<div class="pw pw-' . $desenho . ($falta ? ' pw-sem-fonte' : '') . '" data-tipo="' . e($tipo) . '"><div class="pw-cab"><span class="pw-tit"><span>' . e($titulo) . '</span>' . info($dica) . '</span>'
        . '<span class="kpi-ico" aria-hidden="true">' . icone($ico, 15) . '</span></div><div class="pw-corpo">' . ($falta && $desenho === 'numero' ? '' : ($tela['desenhar'])($tipo, $ctx)) . $aviso . '</div></div>';
}

// Desenha a tela: a grade (ver) ou o editor (montar=1)
function grade_render(array $tela, array $ctx): void
{
    $usuario = (string)usuario_atual();
    if (!grade_editando()) {
        $cel = [];
        foreach (grade_layout($tela, $usuario, 'celular') as $i) {
            $cel[$i['tipo']] = $i;
        }
        $desk = grade_layout($tela, $usuario, 'computador');
        $noDesk = array_column($desk, 'tipo');
        echo '<div class="painel-grade" data-grade="' . e($tela['id']) . '">';
        foreach (array_merge($desk, array_values(array_filter($cel, fn($i) => !in_array($i['tipo'], $noDesk, true)))) as $i) {
            $m = $cel[$i['tipo']] ?? null;
            $soCel = !in_array($i['tipo'], $noDesk, true);
            $estilo = $soCel ? '' : '--c:' . ($i['x'] + 1) . ';--r:' . ($i['y'] + 1) . ';--w:' . $i['w'] . ';--h:' . $i['h'] . ';';
            $estilo .= $m ? '--mc:' . ($m['x'] + 1) . ';--mr:' . ($m['y'] + 1) . ';--mw:' . $m['w'] . ';--mh:' . $m['h'] . ';--mo:' . ($m['y'] * 2 + $m['x']) . ';' : '';
            echo '<div class="painel-item' . ($m ? '' : ' so-computador') . ($soCel ? ' so-celular' : '') . '" style="' . $estilo . '">' . grade_cartao($tela, $i['tipo'], $ctx) . '</div>';
        }
        echo '</div>';
        return;
    }

    // Editor (GridStack): a barra de cima, a biblioteca de blocos e a grade do aparelho
    $aqui = grade_aqui();
    $aparelho = grade_aparelho();
    $cols = GRADE_COLUNAS[$aparelho];
    $layout = grade_layout($tela, $usuario, $aparelho);
    $padrao = $aparelho === 'celular' ? grade_para_celular($tela, grade_padrao($tela)) : grade_padrao($tela);
    $presentes = array_column($layout, 'tipo');
    $blocos = $tela['blocos'];
    $nomeFonte = array_map(fn($f) => $f[3] ?? '', $tela['fontes'] ?? []);
    $tam = function (string $t) use ($blocos, $cols): array {
        [$w0, $h0, $minW, $minH, $maxW, $maxH] = $blocos[$t][4];
        return $cols < 12 ? [1, $minH, $cols, $maxH, $blocos[$t][2] === 'numero' ? 1 : $cols, $h0] : [$minW, $minH, $maxW, $maxH, $w0, $h0];
    };
    $attrs = function (string $t, ?array $pos = null) use ($tam): string {
        [$minW, $minH, $maxW, $maxH, $w, $h] = $tam($t);
        $a = ' gs-id="' . e($t) . '" gs-min-w="' . $minW . '" gs-min-h="' . $minH . '" gs-max-w="' . $maxW . '" gs-max-h="' . $maxH . '"';
        return $a . ($pos ? ' gs-x="' . $pos['x'] . '" gs-y="' . $pos['y'] . '" gs-w="' . $pos['w'] . '" gs-h="' . $pos['h'] . '"' : ' gs-w="' . $w . '" gs-h="' . $h . '"');
    };
    $tirar = '<button type="button" class="painel-tirar" data-painel-tirar aria-label="Tirar da tela" title="Tirar da tela">' . icone('fechar', 14) . '</button>';
    $trocar = fn(string $ap, string $rot, string $ico) => '<a href="' . e(grade_url($aqui, ['montar' => 1, 'aparelho' => $ap])) . '" data-recarrega data-painel-troca' . ($ap === $aparelho ? ' class="atual" aria-current="true"' : '') . '>' . icone($ico, 14) . e($rot) . '</a>';
    echo '<form class="painel-barra" method="post" action="' . e(admin_base()) . 'painel-salvar.php" data-painel-form data-recarrega>'
        . '<input type="hidden" name="csrf" value="' . e(token_csrf()) . '"><input type="hidden" name="tela" value="' . e($tela['id']) . '"><input type="hidden" name="aparelho" value="' . e($aparelho) . '">'
        . '<input type="hidden" name="volta" value="' . e($aqui) . '"><input type="hidden" name="layout" value="">'
        . '<span class="painel-barra-txt">' . icone('lapis', 15) . 'Você está montando ' . e($tela['titulo']) . '<span class="painel-barra-pc">para:</span><span class="painel-barra-cel">no celular</span></span>'
        . '<span class="segmentos">' . $trocar('computador', 'Computador', 'colunas') . $trocar('celular', 'Celular', 'anuncio') . '</span>'
        . '<span class="painel-barra-acoes"><button type="button" class="discreto neutro" data-painel-padrao>' . icone('restaurar', 14) . 'Voltar ao padrão</button>'
        . '<a class="botao discreto neutro" href="' . e($aqui) . '" data-recarrega data-painel-cancelar>Cancelar</a><button type="submit">' . icone('ok', 14) . 'Salvar</button></span></form>';
    echo '<p class="suave painel-dica">Arraste os blocos da lista para a tela. Na tela, arraste o bloco para mudar de lugar e o canto de baixo para mudar o tamanho; o X tira. Esc cancela, Ctrl+S salva.</p>';
    echo '<div class="painel-edicao" data-painel-editar data-painel-colunas="' . $cols . '" data-linha="' . (GRADE_LINHA_PX + 12) . '" data-padrao="' . e((string)json_encode($padrao)) . '">'
        . '<aside class="painel-biblioteca" aria-label="Blocos disponíveis"><h2>Blocos disponíveis</h2>'
        . '<label class="tcard-busca">' . icone('busca', 14) . '<input type="search" data-painel-busca placeholder="Buscar bloco" aria-label="Buscar bloco"></label>';
    foreach ($tela['categorias'] as $cat => $rot) {
        echo '<details open><summary>' . e($rot) . '</summary><div class="painel-lista">';
        foreach ($blocos as $t => $b) {
            if ($b[1] !== $cat) {
                continue;
            }
            $usado = in_array($t, $presentes, true);
            $precisa = array_filter(array_map(fn($f) => $nomeFonte[$f] ?? '', $b[6] ?? []));
            $dicaLista = $b[3] . ($precisa ? ' Precisa: ' . implode(' e ', $precisa) . '.' : '');
            echo '<div class="grid-stack-item painel-novo' . ($usado ? ' usado' : '') . '" data-tipo="' . e($t) . '"' . $attrs($t) . ($usado ? ' aria-disabled="true"' : '')
                . ' data-dica-titulo="' . e($b[0]) . '" data-dica="' . e($dicaLista) . '" data-dica-botao>'
                . '<div class="grid-stack-item-content"><span class="painel-novo-ico" aria-hidden="true">' . icone($b[5], 14) . '</span><span>' . e($b[0]) . '</span>'
                . ($usado ? '<small class="painel-novo-usado">na tela</small>' : '') . '</div></div>';
        }
        echo '</div></details>';
    }
    echo '</aside><div class="painel-moldura' . ($aparelho === 'celular' ? ' celular' : '') . '"><div class="grid-stack">';
    foreach ($layout as $i) {
        echo '<div class="grid-stack-item"' . $attrs($i['tipo'], $i) . '><div class="grid-stack-item-content">' . $tirar . grade_cartao($tela, $i['tipo'], $ctx) . '</div></div>';
    }
    echo '</div></div></div><div hidden>';
    // Modelos de todos os blocos (o que entra ao arrastar da lista ou ao voltar ao padrao)
    foreach (array_keys($blocos) as $t) {
        echo '<template data-painel-modelo="' . e($t) . '">' . $tirar . grade_cartao($tela, $t, $ctx) . '</template>';
    }
    echo '</div><style>' . (string)@file_get_contents(__DIR__ . '/gridstack.css') . '</style>'
        . '<script src="' . e(admin_base()) . 'gridstack.js?v=' . (int)@filemtime(__DIR__ . '/../gridstack.js') . '"></script>';
}
