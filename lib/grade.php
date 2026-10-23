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
// - Print (22/10/2026): mais dois "aparelhos", post (1:1) e story (9:16), que sao a imagem para rede
//   social e WhatsApp. Montam como os outros (Computador | Celular | Print) num quadro de tamanho
//   fixo; a camera da barra de cima pede o quadro (grade_print_render) e o painel.js o transforma em
//   PNG 4K (2160 px de largura), escondendo o que a pessoa marcar (nomes dos produtos, valores em
//   reais). O padrao e a selecao da tela ($tela['print']), sem nome de produto.
// - Censura (23/10/2026): o olho de cada bloco no editor (ao lado do X). O bloco marcado fica borrado
//   no modo compartilhar (o olho da barra de cima, para gravar video ou mostrar a tela) e no print.
//   Vale para a tela toda, em todos os aparelhos, na linha "<tela>:censura" da mesma tabela.
//
// Tela nova: monte o array numa funcao, registre em GRADE_TELAS (o painel-salvar.php usa para
// validar), chame grade_lapis('<id>') antes de casca_inicio() (o lapis aparece na barra lateral) e
// grade_render($tela, $ctx) no lugar dos blocos fixos.

const GRADE_COLUNAS = ['computador' => 12, 'celular' => 2, 'post' => 4, 'story' => 4];
const GRADE_LINHA_PX = 110;
// Print: formato => [nome, largura e altura do quadro em px de tela, linhas da grade]. O PNG sai em 4x
// (2160 px de largura, 4K). Quadro: 22 px de margem, cabecalho de 44, grade de linhas de
// 96 px com 8 de espaco e rodape de 24, com 10 entre eles (post: 4 linhas cabem em 540; story: 8 em 960).
const GRADE_PRINT = ['post' => ['Post 1:1', 540, 540, 4], 'story' => ['Story 9:16', 540, 960, 8]];
const GRADE_PRINT_LINHA_PX = 96;
const GRADE_PRINT_ESPACO_PX = 8;
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
    unset($q['montar'], $q['aparelho'], $q['print']);
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
    $a = is_string($_GET['aparelho'] ?? null) ? $_GET['aparelho'] : '';
    return isset(GRADE_COLUNAS[$a]) ? $a : 'computador';
}

// Nome do aparelho nas mensagens ("Resumo salvo (print post, 6 blocos)")
function grade_aparelho_nome(string $aparelho): string
{
    return isset(GRADE_PRINT[$aparelho]) ? 'print ' . strtolower(explode(' ', GRADE_PRINT[$aparelho][0])[0]) : $aparelho;
}

// Tamanho do bloco no aparelho: [w, h, minW, minH, maxW, maxH]. Celular: numero em meia largura,
// grafico na largura toda. Print: numero em meia largura (um quarto fica apertado), nada passa da
// altura do quadro.
function grade_tamanho(array $bloco, string $aparelho): array
{
    [$w0, $h0, $minW, $minH, $maxW, $maxH] = $bloco[4];
    $cols = GRADE_COLUNAS[$aparelho] ?? 12;
    $numero = $bloco[2] === 'numero';
    if (isset(GRADE_PRINT[$aparelho])) {
        $linhas = GRADE_PRINT[$aparelho][3];
        $minH = min($minH, $linhas);
        return [$numero ? 2 : $cols, max($minH, min($h0, $linhas)), 2, $minH, $cols, max($minH, min($maxH, $linhas))];
    }
    if ($cols < 12) {
        return [$numero ? 1 : $cols, $h0, 1, $minH, $cols, $maxH];
    }
    return [$w0, $h0, $minW, $minH, $maxW, $maxH];
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

// Layout valido: so blocos da tela, sem repetir, no tamanho permitido e dentro da grade (no print,
// o que passa da altura do quadro fica de fora)
function grade_validar(array $tela, array $itens, string $aparelho): array
{
    $cols = GRADE_COLUNAS[$aparelho] ?? 12;
    $linhas = GRADE_PRINT[$aparelho][3] ?? null;
    $blocos = $tela['blocos'];
    $vistos = [];
    $saida = [];
    foreach (array_slice($itens, 0, 80) as $i) {
        $t = is_array($i) ? (string)($i['tipo'] ?? '') : '';
        if (!isset($blocos[$t]) || isset($vistos[$t])) {
            continue;
        }
        [$w0, $h0, $minW, $minH, $maxW, $maxH] = grade_tamanho($blocos[$t], $aparelho);
        $w = max($minW, min($maxW, $cols, (int)($i['w'] ?? $w0)));
        $h = max($minH, min($maxH, (int)($i['h'] ?? $h0)));
        $y = max(0, min(500, (int)($i['y'] ?? 0)));
        if ($linhas !== null && $y + $h > $linhas) {
            continue;
        }
        $saida[] = ['tipo' => $t, 'x' => max(0, min($cols - $w, (int)($i['x'] ?? 0))), 'y' => $y, 'w' => $w, 'h' => $h];
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

// Print a partir do computador: de cima para baixo, numeros dois por linha e graficos na largura
// toda (ate 3 linhas), enquanto couber no quadro
function grade_para_print(array $tela, array $itens, string $aparelho): array
{
    $linhas = GRADE_PRINT[$aparelho][3];
    $saida = [];
    $y = 0;
    $meia = null; // linha com a metade direita livre
    foreach ($itens as $i) {
        $b = $tela['blocos'][$i['tipo']] ?? null;
        if (!$b) {
            continue;
        }
        [, $h0, , $minH] = grade_tamanho($b, $aparelho);
        if ($b[2] === 'numero') {
            if ($meia !== null) {
                $saida[] = ['tipo' => $i['tipo'], 'x' => 2, 'y' => $meia, 'w' => 2, 'h' => 1];
                $meia = null;
            } elseif ($y < $linhas) {
                $saida[] = ['tipo' => $i['tipo'], 'x' => 0, 'y' => $y, 'w' => 2, 'h' => 1];
                $meia = $y++;
            }
            continue;
        }
        $h = max($minH, min($h0, 3));
        if ($y + $h <= $linhas) {
            $meia = null;
            $saida[] = ['tipo' => $i['tipo'], 'x' => 0, 'y' => $y, 'w' => 4, 'h' => $h];
            $y += $h;
        }
    }
    return $saida;
}

// O padrao do print: a selecao da tela para o formato ($tela['print'][formato], lugares com
// alternativas), com a primeira alternativa de cada lugar cujas fontes estao ligadas. Tela sem
// selecao: o layout do computador arrumado no quadro.
function grade_print_padrao(array $tela, array $computador, string $aparelho): array
{
    $selecao = $tela['print'][$aparelho] ?? null;
    if ($selecao === null) {
        return grade_para_print($tela, $computador, $aparelho);
    }
    $fontes = $tela['fontes'] ?? [];
    $itens = [];
    foreach ($selecao as [$tipos, $x, $y, $w, $h]) {
        foreach ($tipos as $t) {
            $falta = array_filter($tela['blocos'][$t][6] ?? [], fn($f) => isset($fontes[$f]) && !$fontes[$f][0]);
            if (isset($tela['blocos'][$t]) && !$falta) {
                $itens[] = ['tipo' => $t, 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h];
                break;
            }
        }
    }
    return grade_validar($tela, $itens, $aparelho);
}

// Os blocos censurados (o olho) da tela, de quem esta logado
function grade_censura(array $tela, string $usuario): array
{
    static $cache = [];
    $chave = $tela['id'] . ':censura';
    if (!isset($cache[$usuario][$chave])) {
        $st = track_db()->prepare('SELECT layout FROM painel_layouts WHERE usuario = ? AND aparelho = ?');
        $st->execute([$usuario, $chave]);
        $l = json_decode((string)$st->fetchColumn(), true);
        $cache[$usuario][$chave] = is_array($l) ? array_values(array_intersect(array_keys($tela['blocos']), array_filter($l, 'is_string'))) : [];
    }
    return $cache[$usuario][$chave];
}

// Grava o olho junto com o Salvar do editor. $json: {"presentes": [...], "marcados": [...]}, os
// blocos que estavam na grade e os marcados entre eles; quem nao estava na grade fica como estava.
function grade_salvar_censura(array $tela, string $usuario, string $json): void
{
    $d = json_decode($json, true);
    if (!is_array($d) || !is_array($d['presentes'] ?? null) || !is_array($d['marcados'] ?? null)) {
        return;
    }
    $presentes = array_intersect(array_keys($tela['blocos']), array_filter($d['presentes'], 'is_string'));
    $marcados = array_intersect($presentes, array_filter($d['marcados'], 'is_string'));
    $nova = array_values(array_unique(array_merge(array_diff(grade_censura($tela, $usuario), $presentes), $marcados)));
    track_db()->prepare('INSERT INTO painel_layouts (usuario, aparelho, layout, atualizado_em) VALUES (?, ?, ?, ?)
        ON CONFLICT (usuario, aparelho) DO UPDATE SET layout = excluded.layout, atualizado_em = excluded.atualizado_em')
        ->execute([$usuario, $tela['id'] . ':censura', json_encode($nova), agora_utc()]);
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

// O layout de cada aparelho: o salvo, ou o padrao (no celular e no print, o do computador arrumado)
function grade_layout(array $tela, string $usuario, string $aparelho): array
{
    $salvo = grade_salvo($tela, $usuario, $aparelho);
    if ($salvo !== null) {
        return $salvo;
    }
    $computador = grade_salvo($tela, $usuario, 'computador') ?? grade_padrao($tela);
    if (isset(GRADE_PRINT[$aparelho])) {
        return grade_print_padrao($tela, $computador, $aparelho);
    }
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
    return [true, $tela['titulo'] . ' salvo (' . grade_aparelho_nome($aparelho) . ', ' . count($layout) . ' ' . (count($layout) === 1 ? 'bloco' : 'blocos') . ').'];
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
    $censura = grade_censura($tela, $usuario);
    $marca = fn(string $t) => in_array($t, $censura, true) ? ' data-censura' : '';
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
            echo '<div class="painel-item' . ($m ? '' : ' so-computador') . ($soCel ? ' so-celular' : '') . '" style="' . $estilo . '"' . $marca($i['tipo']) . '>' . grade_cartao($tela, $i['tipo'], $ctx) . '</div>';
        }
        echo '</div>';
        return;
    }

    // Editor (GridStack): a barra de cima, a biblioteca de blocos e a grade do aparelho
    $aqui = grade_aqui();
    $aparelho = grade_aparelho();
    $cols = GRADE_COLUNAS[$aparelho];
    $print = GRADE_PRINT[$aparelho] ?? null;
    $layout = grade_layout($tela, $usuario, $aparelho);
    $padrao = match (true) {
        $print !== null => grade_print_padrao($tela, grade_padrao($tela), $aparelho),
        $aparelho === 'celular' => grade_para_celular($tela, grade_padrao($tela)),
        default => grade_padrao($tela),
    };
    $presentes = array_column($layout, 'tipo');
    $blocos = $tela['blocos'];
    $nomeFonte = array_map(fn($f) => $f[3] ?? '', $tela['fontes'] ?? []);
    $tam = function (string $t) use ($blocos, $aparelho): array {
        [$w0, $h0, $minW, $minH, $maxW, $maxH] = grade_tamanho($blocos[$t], $aparelho);
        return [$minW, $minH, $maxW, $maxH, $w0, $h0];
    };
    $attrs = function (string $t, ?array $pos = null) use ($tam): string {
        [$minW, $minH, $maxW, $maxH, $w, $h] = $tam($t);
        $a = ' gs-id="' . e($t) . '" gs-min-w="' . $minW . '" gs-min-h="' . $minH . '" gs-max-w="' . $maxW . '" gs-max-h="' . $maxH . '"';
        return $a . ($pos ? ' gs-x="' . $pos['x'] . '" gs-y="' . $pos['y'] . '" gs-w="' . $pos['w'] . '" gs-h="' . $pos['h'] . '"' : ' gs-w="' . $w . '" gs-h="' . $h . '"');
    };
    // O X tira o bloco; o olho, ao lado, marca o bloco para ficar borrado no modo compartilhar e no print
    $tirar = fn(string $t) => '<button type="button" class="painel-tirar" data-painel-tirar aria-label="Tirar da tela" title="Tirar da tela">' . icone('fechar', 14) . '</button>'
        . '<button type="button" class="painel-censura" data-painel-censura aria-pressed="' . ($marca($t) !== '' ? 'true' : 'false') . '" aria-label="Censurar ao compartilhar"'
        . ' title="Censurar ao compartilhar: no modo compartilhar e no print, o bloco fica borrado">' . icone('olho', 14) . icone('olho-fechado', 14) . '</button>';
    $trocar = fn(string $ap, string $rot, string $ico, ?bool $atual = null) => '<a href="' . e(grade_url($aqui, ['montar' => 1, 'aparelho' => $ap])) . '" data-recarrega data-painel-troca' . (($atual ?? $ap === $aparelho) ? ' class="atual" aria-current="true"' : '') . '>' . icone($ico, 14) . e($rot) . '</a>';
    $formatos = '';
    if ($print !== null) {
        foreach (GRADE_PRINT as $f => $p) {
            $formatos .= $trocar($f, $p[0], $f === 'post' ? 'quadrado' : 'anuncio');
        }
        $formatos = '<span class="segmentos painel-formatos" aria-label="Formato do print">' . $formatos . '</span>';
    }
    echo '<form class="painel-barra" method="post" action="' . e(admin_base()) . 'painel-salvar.php" data-painel-form data-recarrega>'
        . '<input type="hidden" name="csrf" value="' . e(token_csrf()) . '"><input type="hidden" name="tela" value="' . e($tela['id']) . '"><input type="hidden" name="aparelho" value="' . e($aparelho) . '">'
        . '<input type="hidden" name="volta" value="' . e($aqui) . '"><input type="hidden" name="layout" value=""><input type="hidden" name="censura" value="">'
        . '<span class="painel-barra-txt">' . icone('lapis', 15) . 'Você está montando ' . e($tela['titulo']) . ($print !== null ? '<span>para o print:</span>' : '<span class="painel-barra-pc">para:</span><span class="painel-barra-cel">no celular</span>') . '</span>'
        . '<span class="segmentos">' . $trocar('computador', 'Computador', 'colunas') . $trocar('celular', 'Celular', 'anuncio') . $trocar('post', 'Print', 'camera', $print !== null) . '</span>'
        . $formatos
        . '<span class="painel-barra-acoes"><button type="button" class="discreto neutro" data-painel-padrao>' . icone('restaurar', 14) . 'Voltar ao padrão</button>'
        . '<a class="botao discreto neutro" href="' . e($aqui) . '" data-recarrega data-painel-cancelar>Cancelar</a><button type="submit">' . icone('ok', 14) . 'Salvar</button></span></form>';
    echo '<p class="suave painel-dica">' . ($print !== null
        ? 'O que estiver dentro do quadro sai na imagem: ' . e($print[0]) . ' em 4K. O quadro tem tamanho fixo: para pôr outro bloco, tire um (o X) e arraste o novo da lista. O olho borra o bloco; o (i) não aparece no print. Depois de salvar, gere a imagem pela câmera da barra de cima. Esc cancela, Ctrl+S salva. <strong data-print-espaco></strong>'
        : 'Arraste os blocos da lista para a tela. Na tela, arraste o bloco para mudar de lugar e o canto de baixo para mudar o tamanho; o X tira e o olho censura o bloco no modo compartilhar. Esc cancela, Ctrl+S salva.') . '</p>';
    // Linha do GridStack = a da tela + o espaco entre cartoes (a margem e metade do espaco): o cartao
    // fica do mesmo tamanho que na tela ou no print
    [$linhaPx, $espaco] = $print !== null ? [GRADE_PRINT_LINHA_PX, GRADE_PRINT_ESPACO_PX] : [GRADE_LINHA_PX, 12];
    echo '<div class="painel-edicao" data-painel-editar data-painel-colunas="' . $cols . '" data-linha="' . ($linhaPx + $espaco) . '" data-margem="' . intdiv($espaco, 2) . '"'
        . ($print !== null ? ' data-linhas-max="' . $print[3] . '"' : '') . ' data-padrao="' . e((string)json_encode($padrao)) . '">'
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
    echo '</aside><div class="painel-moldura' . ($aparelho === 'celular' ? ' celular' : '') . ($print !== null ? ' print' : '') . '">';
    if ($print !== null) { // o quadro do print, com o cabecalho e o rodape que saem na imagem
        $info = grade_print_info($tela);
        echo '<div class="print-tela" style="width:' . $print[1] . 'px;height:' . $print[2] . 'px">' . grade_print_cab($info);
    }
    echo '<div class="grid-stack">';
    foreach ($layout as $i) {
        echo '<div class="grid-stack-item"' . $attrs($i['tipo'], $i) . $marca($i['tipo']) . '><div class="grid-stack-item-content">' . $tirar($i['tipo']) . grade_cartao($tela, $i['tipo'], $ctx) . '</div></div>';
    }
    echo '</div>' . ($print !== null ? grade_print_rodape($info) . '</div>' : '') . '</div></div><div hidden>';
    // Modelos de todos os blocos (o que entra ao arrastar da lista ou ao voltar ao padrao)
    foreach (array_keys($blocos) as $t) {
        echo '<template data-painel-modelo="' . e($t) . '">' . $tirar($t) . grade_cartao($tela, $t, $ctx) . '</template>';
    }
    echo '</div><style>' . (string)@file_get_contents(__DIR__ . '/gridstack.css') . '</style>'
        . '<script src="' . e(admin_base()) . 'gridstack.js?v=' . (int)@filemtime(__DIR__ . '/../gridstack.js') . '"></script>';
}

// ---------------------------------------------------------------- print
// O pedido do quadro do print: ./?aba=geral&print=post devolve so o quadro, sem a pagina (a camera
// da barra de cima pede e o painel.js transforma em PNG)
function grade_print_formato(): ?string
{
    $f = is_string($_GET['print'] ?? null) ? $_GET['print'] : '';
    return isset(GRADE_PRINT[$f]) ? $f : null;
}

// O que o cabecalho e o rodape do print dizem: a tela, o periodo com as datas, os filtros, o nome do
// painel e quando foi gerado. $extra: filtro proprio da tela (no Resumo, a fonte de trafego).
function grade_print_info(array $tela, string $extra = ''): array
{
    sessao_iniciar();
    $periodo = (string)($_SESSION['track_filtro']['periodo'] ?? '7d');
    $periodo = periodo_valido($periodo) ? $periodo : '7d';
    $rotulo = periodo_rotulo($periodo);
    if ($periodo !== 'tudo' && isset(PERIODOS[$periodo])) {
        [$d1, $d2] = periodo_dias($periodo);
        $f = fn(string $d) => (new DateTime($d))->format('d/m/Y');
        $rotulo .= ' · ' . ($d1 === $d2 ? $f($d1) : $f($d1) . ' a ' . $f($d2));
    }
    return [
        'titulo' => $tela['nome'] ?? ucfirst((string)preg_replace('/^(o|a) /', '', $tela['titulo'])),
        'periodo' => $rotulo,
        'filtros' => implode(' · ', array_filter([function_exists('produto_filtro') ? implode(', ', produto_filtro()) : '', $extra])),
        'marca' => (string)((track_config() ?? [])['app_nome'] ?? 'Painel'),
        'gerado' => (new DateTime('now', fuso()))->format('d/m/Y \à\s H:i'),
    ];
}

function grade_print_cab(array $info): string
{
    return '<header class="print-cab"><span class="print-cab-txt"><strong>' . e($info['titulo']) . '</strong><span>' . e($info['periodo']) . '</span></span>'
        . '<span class="print-marca">' . e($info['marca']) . '</span></header>';
}

function grade_print_rodape(array $info): string
{
    return '<footer class="print-rodape"><span>' . ($info['filtros'] !== '' ? 'Filtro: ' . e($info['filtros']) : '') . '</span><span>Gerado em ' . e($info['gerado']) . '</span></footer>';
}

// O quadro do print com os blocos do layout de print de quem esta logado. $produtos: nome => apelido
// ("Produto A"), que o painel.js usa quando a pessoa escolhe esconder os nomes dos produtos.
function grade_print_render(array $tela, array $ctx, string $formato, array $info, array $produtos = []): void
{
    [, $w, $h, $linhas] = GRADE_PRINT[$formato];
    echo '<div class="print-tela" data-print-tela data-formato="' . e($formato) . '" data-produtos="' . e((string)json_encode($produtos, JSON_UNESCAPED_UNICODE)) . '" style="width:' . $w . 'px;height:' . $h . 'px">'
        . graficos_defs() . grade_print_cab($info) . '<div class="print-grade" style="--linhas:' . $linhas . '">';
    $censura = grade_censura($tela, (string)usuario_atual());
    foreach (grade_layout($tela, (string)usuario_atual(), $formato) as $i) {
        echo '<div class="print-item" style="--c:' . ($i['x'] + 1) . ';--r:' . ($i['y'] + 1) . ';--w:' . $i['w'] . ';--h:' . $i['h'] . '"'
            . (in_array($i['tipo'], $censura, true) ? ' data-censura' : '') . '>' . grade_cartao($tela, $i['tipo'], $ctx) . '</div>';
    }
    echo '</div>' . grade_print_rodape($info) . '</div>';
}
