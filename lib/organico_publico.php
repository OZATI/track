<?php
// Aba Organico: o publico do Instagram em painel, no desenho do Resumo. Sexo, idade (por
// sexo), cidades e paises de quem segue, de quem viu e de quem interagiu, e a comparacao
// entre quem segue e quem interage (para onde o conteudo esta puxando).

require_once __DIR__ . '/resumo.php';
require_once __DIR__ . '/instagram_publico.php';

const IG_SEXO_COR = ['Mulheres' => '#C13584', 'Homens' => '#1D6FF2', 'Não informado' => '#9CA3AF'];

function organico_pct(int $n, int $total): ?float
{
    return $total ? $n * 100 / $total : null;
}

function organico_pct_txt(?float $p): string
{
    return $p === null ? '—' : number_format($p, 1, ',', '.') . '%';
}

// Idade por sexo: colunas lado a lado (mulheres e homens) em cada faixa, com a % da faixa
function organico_svg_idade(array $idade, array $idadeSexo): string
{
    $faixas = array_values(array_filter(IG_IDADES, fn($f) => ($idade[$f] ?? 0) > 0));
    if (!$faixas) {
        return '<p class="suave">Sem dados de idade.</p>';
    }
    $total = array_sum($idade);
    $L = 460; $A = 210; $esq = 6; $baixo = 22; $cima = 18;
    $porSexo = $idadeSexo !== [];
    $max = 1;
    foreach ($faixas as $f) {
        $max = max($max, $porSexo ? max($idadeSexo[$f . '|F'] ?? 0, $idadeSexo[$f . '|M'] ?? 0) : $idade[$f]);
    }
    $larg = ($L - 2 * $esq) / count($faixas);
    $alto = $A - $cima - $baixo;
    $svg = '<svg class="grafico" viewBox="0 0 ' . $L . ' ' . $A . '" role="img" aria-label="Idade' . ($porSexo ? ' por sexo' : '') . '">';
    for ($i = 1; $i <= 4; $i++) {
        $yy = round($cima + $alto * ($i - 1) / 4, 1);
        $svg .= '<line x1="' . $esq . '" x2="' . ($L - $esq) . '" y1="' . $yy . '" y2="' . $yy . '" class="grade-l"></line>';
    }
    foreach ($faixas as $i => $f) {
        $x = $esq + $i * $larg;
        $barras = $porSexo ? ['F' => $idadeSexo[$f . '|F'] ?? 0, 'M' => $idadeSexo[$f . '|M'] ?? 0] : ['' => $idade[$f]];
        $bl = $larg * ($porSexo ? .3 : .56);
        $x0 = $x + ($larg - $bl * count($barras)) / 2;
        $topo = $A - $baixo;
        foreach (array_values($barras) as $j => $n) {
            $sexo = array_keys($barras)[$j];
            $h = $n * $alto / $max;
            $topo = min($topo, $A - $baixo - $h);
            if ($n) {
                $svg .= '<rect x="' . round($x0 + $j * $bl, 1) . '" y="' . round($A - $baixo - $h, 1) . '" width="' . round($bl - 2, 1) . '" height="' . round($h, 1)
                    . '" class="barra' . ($sexo !== '' ? ' barra-' . strtolower($sexo) : '') . '"><title>' . e($f . ($sexo !== '' ? ' · ' . IG_SEXOS[$sexo] : '') . ': ' . $n) . '</title></rect>';
            }
        }
        $svg .= '<text x="' . round($x + $larg / 2, 1) . '" y="' . round($topo - 6, 1) . '" text-anchor="middle">' . e(organico_pct_txt(organico_pct($idade[$f], $total))) . '</text>'
            . '<text x="' . round($x + $larg / 2, 1) . '" y="' . ($A - 6) . '" text-anchor="middle">' . e($f) . '</text>';
    }
    $svg .= '</svg>';
    if ($porSexo) {
        $svg .= '<ul class="rosca-leg"><li><i style="background:' . IG_SEXO_COR['Mulheres'] . '"></i>Mulheres</li><li><i style="background:' . IG_SEXO_COR['Homens'] . '"></i>Homens</li></ul>';
    }
    return $svg;
}

// Lista do Resumo (numero, anel e %) a partir de [grupo => total]
function organico_lista(array $grupos, callable $rotulo, int $limite): string
{
    $total = array_sum($grupos);
    $linhas = [];
    foreach (array_slice($grupos, 0, $limite, true) as $k => $n) {
        $linhas[] = [e($rotulo((string)$k)), number_format($n, 0, ',', '.'), organico_pct($n, $total)];
    }
    return resumo_lista_aneis($linhas, 'Sem dados.');
}

// Quem segue x quem interage: a fatia de cada grupo nos dois publicos
function organico_comparar_publicos(array $seg, array $eng): string
{
    $linhas = [];
    $fatia = fn(array $g, string $k) => organico_pct($g[$k] ?? 0, array_sum($g));
    foreach (['F' => 'Mulheres', 'M' => 'Homens'] as $k => $rot) {
        $linhas[] = [$rot, $fatia($seg['gender'] ?? [], $k), $fatia($eng['gender'] ?? [], $k)];
    }
    foreach (IG_IDADES as $f) {
        $a = $fatia($seg['age'] ?? [], $f);
        $b = $fatia($eng['age'] ?? [], $f);
        if (max($a ?? 0, $b ?? 0) >= 3) {
            $linhas[] = [$f . ' anos', $a, $b];
        }
    }
    $html = '<ul class="publico-comp"><li class="pc-cab"><span></span><span>' . com_info('Seguem', 'A fatia do grupo entre os seguidores.') . '</span>'
        . '<span>' . com_info('Interagem', 'A fatia do grupo entre as contas engajadas neste mês.') . '</span>'
        . '<span>' . com_info('Diferença', 'Em pontos percentuais. Positivo: o grupo interage mais do que o peso dele nos seguidores; é para quem o conteúdo está puxando.') . '</span></li>';
    foreach ($linhas as [$rot, $a, $b]) {
        $dif = $a !== null && $b !== null ? $b - $a : null;
        $html .= '<li><span>' . e($rot) . '</span><span>' . e(organico_pct_txt($a)) . '</span><span>' . e(organico_pct_txt($b)) . '</span><span>'
            . ($dif === null ? '—' : (abs($dif) < 0.5 ? '<small class="delta">=</small>' : '<small class="delta">' . ($dif > 0 ? '▲ +' : '▼ −') . number_format(abs($dif), 1, ',', '.') . ' p.p.</small>'))
            . '</span></li>';
    }
    return $html . '</ul>';
}

function organico_publico(string $periodo): void
{
    $explica = 'Sexo, idade, cidades e países de quem segue o perfil, de quem viu algum conteúdo e de quem interagiu. O Instagram entrega só o total de cada grupo (nunca quem é quem), só para perfis com 100 seguidores ou mais, e o painel atualiza uma vez por dia. Contas alcançadas e engajadas: o mês corrente.';
    $dados = ig_publico();
    $escolha = is_string($_GET['publico'] ?? null) && isset(IG_PUBLICOS[$_GET['publico']]) ? $_GET['publico'] : 'seguidores';
    $abas = '';
    foreach (IG_PUBLICOS as $id => [, $rot]) {
        $abas .= '<a href="' . e('./?' . http_build_query(['aba' => 'organico', 'periodo' => $periodo, 'publico' => $id]) . '#publico') . '" class="' . ($id === $escolha ? 'atual' : '') . '">' . e($rot) . '</a>';
    }
    echo '<section class="bloco" id="publico">' . titulo('Público do Instagram', $explica)
        . '<div class="segmentos" role="group" aria-label="Qual público">' . $abas . '</div>';
    if (!$dados) {
        echo '<p class="suave">O público vem na próxima busca no Instagram (botão Atualizar, no alto da aba).</p></section>';
        return;
    }
    $p = $dados['publicos'][$escolha] ?? [];
    [, $rotulo, $quem] = IG_PUBLICOS[$escolha];
    if (!$p || isset($p['erro'])) {
        echo '<p class="suave">O Instagram não entregou ' . e(mb_strtolower($rotulo)) . (($p['erro'] ?? '') !== '' ? ': ' . e($p['erro']) : '')
            . '. Ele só mostra o público de perfis com 100 seguidores ou mais' . ($escolha !== 'seguidores' ? ', e de quem teve alcance ou interação neste mês' : '') . '.</p></section>';
        return;
    }
    $sexo = $p['gender'] ?? [];
    $idade = $p['age'] ?? [];
    $cidades = $p['city'] ?? [];
    $paises = $p['country'] ?? [];
    $totalSexo = array_sum($sexo);
    $faixaTop = $idade ? array_key_first($idade) : null;
    $cidadeTop = $cidades ? array_key_first($cidades) : null;

    echo '<p class="suave">' . e(ucfirst($quem)) . ' · atualizado ' . e(resumo_ha($dados['em'])) . '</p>'
        . '<div class="rgrade">'
        . resumo_cartao(organico_pct_txt(organico_pct($sexo['F'] ?? 0, $totalSexo)), 'Mulheres', 'Fatia de mulheres em ' . $quem . '.', '', number_format($sexo['F'] ?? 0, 0, ',', '.') . ' contas')
        . resumo_cartao(organico_pct_txt(organico_pct($sexo['M'] ?? 0, $totalSexo)), 'Homens', 'Fatia de homens em ' . $quem . '.', '', number_format($sexo['M'] ?? 0, 0, ',', '.') . ' contas')
        . resumo_cartao($faixaTop ? $faixaTop . ' anos' : '—', 'Faixa de idade principal', 'A faixa de idade com mais contas.', '', $faixaTop ? organico_pct_txt(organico_pct($idade[$faixaTop], array_sum($idade))) . ' do total' : '')
        . resumo_cartao($cidadeTop ? ig_cidade($cidadeTop) : '—', 'Cidade principal', 'A cidade com mais contas (o Instagram lista as 45 maiores).', '', $cidadeTop ? organico_pct_txt(organico_pct($cidades[$cidadeTop], array_sum($cidades))) . ' das contas com cidade' : '')
        . '</div>';

    $partes = [];
    foreach (IG_SEXOS as $k => $rot) {
        if (($sexo[$k] ?? 0) > 0) {
            $partes[$rot] = $sexo[$k];
        }
    }
    echo '<div class="rgrade">'
        . '<section class="rc c4"><div class="rc-cab"><span>Sexo</span>' . info('Como ' . $quem . ' se divide por sexo. "Não informado": o Instagram não sabe.') . '</div>'
        . ($partes ? resumo_rosca($partes, IG_SEXO_COR, $rotulo) : '<p class="suave">Sem dados.</p>') . '</section>'
        . '<section class="rc c8"><div class="rc-cab"><span>Idade por sexo</span>' . info('Contas em cada faixa de idade, separadas em mulheres e homens. Em cima, a % da faixa no total.') . '</div>'
        . organico_svg_idade($idade, $p['age_gender'] ?? []) . '</section>'
        . '</div><div class="rgrade">'
        . '<section class="rc c4"><div class="rc-cab"><span>Cidades</span>' . info('As cidades com mais contas. A % é sobre as contas com cidade informada.') . '</div>'
        . organico_lista($cidades, 'ig_cidade', 8) . '</section>'
        . '<section class="rc c4"><div class="rc-cab"><span>Países</span>' . info('Os países com mais contas.') . '</div>'
        . organico_lista($paises, 'ig_pais', 6) . '</section>';
    $seg = $dados['publicos']['seguidores'] ?? [];
    $eng = $dados['publicos']['engajadas'] ?? [];
    echo '<section class="rc c4"><div class="rc-cab"><span>Quem segue × quem interage</span>' . info('Compara a fatia de cada grupo entre os seguidores e entre as contas que interagiram neste mês. Mostra para quem o conteúdo está puxando, útil também para o público dos anúncios.') . '</div>'
        . ($seg && $eng && !isset($seg['erro']) && !isset($eng['erro']) ? organico_comparar_publicos($seg, $eng) : '<p class="suave">Precisa dos seguidores e das contas engajadas deste mês.</p>')
        . '</section></div></section>';
}
