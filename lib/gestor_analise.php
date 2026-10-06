<?php
// Analise diaria, parte de cima (pedido do Allan de 06/10/2026): os numeros da campanha no
// periodo, o grafico de compradores e ROI por dia, a leitura da campanha (dicas com as regras
// que o Allan usa para otimizar), o grafico de bolsa do ROI desde o inicio da campanha (uma vela
// por dia; o ROI aparece ao passar o mouse) e quem compra por sexo e idade (dados da Meta).
//
// Vela de um dia: abre no ROI acumulado da campanha ate o dia anterior e fecha no acumulado ate
// o fim do dia (como o preco de uma acao); o pavio vai ate o ROI do proprio dia, o que puxou o
// acumulado para cima ou para baixo. Verde se o acumulado subiu, vermelho se caiu. A primeira
// vela abre no 1 (o ponto em que a campanha se paga): verde se comecou se pagando.
// Mesmas contas do gestor: ROI = (faturamento - imposto) / gasto.

require_once __DIR__ . '/meta_publico.php';

const ANALISE_SEMANA = ['', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb', 'dom'];

function analise_dia(string $dia): string
{
    $d = new DateTime($dia);
    return ANALISE_SEMANA[(int)$d->format('N')] . ' ' . $d->format('d/m');
}

function analise_num(?float $v, int $casas = 2): string
{
    return $v === null ? 'N/A' : number_format($v, $casas, ',', '.');
}

// Primeiro dia do "desde o inicio": o primeiro gasto ou venda da campanha, mas nao antes do que o
// painel ainda guarda (vendas mais antigas que a retencao ja foram apagadas) nem de 1 ano atras
function analise_inicio(PDO $db, string $id): ?string
{
    $primeiro = gestor_dias_inicio($db, $id);
    if ($primeiro === null) {
        return null;
    }
    $dias = max(7, (int)((track_config() ?? [])['dias_retencao'] ?? 90));
    $hoje = new DateTime('today', fuso());
    return max($primeiro, (clone $hoje)->modify('-' . min($dias - 1, 365) . ' days')->format('Y-m-d'));
}

// Uma vela por dia com gasto ou venda: [dia, abre, fecha, max, min, roi do dia, metricas do dia]
function analise_velas(array $linhas, float $pct): array
{
    $velas = [];
    $f = $g = 0;
    $antes = null;
    foreach ($linhas as $dia => $l) {
        if ($l['gasto'] === 0 && $l['fat'] === 0 && $l['vendas'] === 0) {
            continue; // campanha parada no dia: sem vela
        }
        $f += $l['fat'];
        $g += $l['gasto'];
        $fecha = roi_campanha($f, $g, (int)round($g * $pct / 100));
        if ($fecha === null) {
            continue;
        }
        $m = gestor_metricas(['id' => '', 'obj' => null] + $l, $pct);
        $abre = $antes ?? 1.0;
        $pontos = array_filter([$abre, $fecha, $m['roi']], fn($v) => $v !== null);
        $velas[] = [$dia, $abre, $fecha, max($pontos), min($pontos), $m['roi'], $m, $antes === null];
        $antes = $fecha;
    }
    return $velas;
}

// Grafico de bolsa do ROI (SVG). Linhas tracejadas no 1 (a campanha se paga) e no 2 (verde).
function analise_svg_velas(array $velas): string
{
    $n = count($velas);
    if (!$n) {
        return '<p class="suave">Sem gasto nem venda desde o início para desenhar.</p>';
    }
    $L = 720; $A = 230; $esq = 34; $dir = 8; $cima = 10; $baixo = 24;
    $todos = array_merge(array_column($velas, 3), array_column($velas, 4));
    $hi = min(8.0, max(2.5, max($todos) * 1.05));
    $lo = max(-1.0, min(0.0, min($todos) * 1.05));
    $y = fn(float $v) => round($cima + ($hi - max($lo, min($hi, $v))) * ($A - $cima - $baixo) / ($hi - $lo), 1);
    $passo = ($L - $esq - $dir) / $n;
    $larg = max(2, min(12, $passo * 0.62));
    $svg = '<svg class="grafico velas" viewBox="0 0 ' . $L . ' ' . $A . '" role="img" aria-label="ROI acumulado da campanha, um dia por vela">';
    foreach (array_unique([0, 1, 2, $hi > 4 ? 4 : null, $hi > 6 ? 6 : null]) as $ref) {
        if ($ref === null || $ref < $lo || $ref > $hi) {
            continue;
        }
        $svg .= '<line x1="' . $esq . '" x2="' . ($L - $dir) . '" y1="' . $y($ref) . '" y2="' . $y($ref) . '" class="' . ($ref === 1 ? 'ref-1' : ($ref === 2 ? 'ref-2' : 'grade-l')) . '"></line>'
            . '<text x="' . ($esq - 6) . '" y="' . ($y($ref) + 4) . '" text-anchor="end">' . $ref . '</text>';
    }
    $cada = (int)ceil($n / 8);
    foreach ($velas as $i => [$dia, $abre, $fecha, $max, $min, $roiDia, $m, $primeira]) {
        $x = $esq + $passo * ($i + 0.5);
        $sobe = $fecha >= $abre;
        $topo = $y(max($abre, $fecha));
        $alt = max(1.5, $y(min($abre, $fecha)) - $topo);
        $dica = dica_attr(analise_dia($dia), ['ROI do dia ' . analise_num($roiDia), 'Acumulado ' . analise_num($fecha) . ($primeira ? ' (primeiro dia)' : ' (era ' . analise_num($abre) . ')'),
            'Gasto ' . reais($m['gasto']) . ' · ' . $m['vendas'] . ' venda' . ($m['vendas'] === 1 ? '' : 's'), 'Lucro ' . reais($m['lucro'])]);
        $svg .= '<g class="vela ' . ($sobe ? 'sobe' : 'desce') . '"' . $dica . '>'
            . '<rect class="vela-alvo" x="' . round($x - $passo / 2, 1) . '" y="0" width="' . round($passo, 1) . '" height="' . ($A - $baixo) . '"></rect>'
            . '<line x1="' . round($x, 1) . '" x2="' . round($x, 1) . '" y1="' . $y($max) . '" y2="' . $y($min) . '"></line>'
            . '<rect x="' . round($x - $larg / 2, 1) . '" y="' . $topo . '" width="' . round($larg, 1) . '" height="' . round($alt, 1) . '" rx="1"></rect></g>';
        if ($i % $cada === 0 || $i === $n - 1) {
            $svg .= '<text x="' . round($x, 1) . '" y="' . ($A - 6) . '" text-anchor="middle">' . e((new DateTime($dia))->format('d/m')) . '</text>';
        }
    }
    return $svg . '</svg>';
}

// Compradores (barras) e ROI do dia (linha), no periodo da tela
function analise_svg_compradores(array $linhas, float $pct): string
{
    $L = 720; $A = 200; $esq = 26; $dir = 34; $cima = 12; $baixo = 24;
    $n = count($linhas);
    if (!$n) {
        return '';
    }
    $dias = [];
    foreach ($linhas as $dia => $l) {
        $dias[$dia] = gestor_metricas(['id' => '', 'obj' => null] + $l, $pct);
    }
    $maxV = max(1, max(array_column($dias, 'vendas')));
    $rois = array_filter(array_column($dias, 'roi'), fn($v) => $v !== null);
    $hiR = min(8.0, max(2.5, $rois ? max($rois) * 1.1 : 2.5));
    $loR = $rois ? max(-1.0, min(0.0, min($rois))) : 0.0;
    $h = $A - $cima - $baixo;
    $yV = fn(int $v) => round($cima + $h - $v * $h / $maxV, 1);
    $yR = fn(float $v) => round($cima + ($hiR - max($loR, min($hiR, $v))) * $h / ($hiR - $loR), 1);
    $passo = ($L - $esq - $dir) / $n;
    $svg = '<svg class="grafico compradores" viewBox="0 0 ' . $L . ' ' . $A . '" role="img" aria-label="Compradores e ROI por dia">'
        . '<line x1="' . $esq . '" x2="' . ($L - $dir) . '" y1="' . $yR(1) . '" y2="' . $yR(1) . '" class="ref-1"></line>'
        . '<text x="' . ($L - $dir + 6) . '" y="' . ($yR(1) + 4) . '">1</text>'
        . ($hiR >= 2 ? '<line x1="' . $esq . '" x2="' . ($L - $dir) . '" y1="' . $yR(2) . '" y2="' . $yR(2) . '" class="ref-2"></line><text x="' . ($L - $dir + 6) . '" y="' . ($yR(2) + 4) . '">2</text>' : '')
        . '<text x="' . ($esq - 6) . '" y="' . ($yV($maxV) + 4) . '" text-anchor="end">' . $maxV . '</text>';
    $pontos = [];
    $cada = (int)ceil($n / 10);
    $i = 0;
    foreach ($dias as $dia => $m) {
        $x = $esq + $passo * ($i + 0.5);
        $larg = max(3, min(28, $passo * 0.55));
        $dica = dica_attr(analise_dia($dia), [$m['vendas'] . ' comprador' . ($m['vendas'] === 1 ? '' : 'es'), 'ROI ' . analise_num($m['roi']), 'Gasto ' . reais($m['gasto'])]);
        $svg .= '<g class="dia-graf"' . $dica . '><rect class="vela-alvo" x="' . round($x - $passo / 2, 1) . '" y="0" width="' . round($passo, 1) . '" height="' . ($A - $baixo) . '"></rect>'
            . ($m['vendas'] ? '<rect class="barra" x="' . round($x - $larg / 2, 1) . '" y="' . $yV($m['vendas']) . '" width="' . round($larg, 1) . '" height="' . round($cima + $h - $yV($m['vendas']), 1) . '" rx="2"></rect>' : '')
            . ($m['roi'] !== null ? '<circle cx="' . round($x, 1) . '" cy="' . $yR($m['roi']) . '" r="3.5" class="ponto ' . cor_roi($m['roi']) . '"></circle>' : '') . '</g>';
        if ($m['roi'] !== null) {
            $pontos[] = round($x, 1) . ',' . $yR($m['roi']);
        }
        if ($i % $cada === 0 || $i === $n - 1) {
            $svg .= '<text x="' . round($x, 1) . '" y="' . ($A - 6) . '" text-anchor="middle">' . e((new DateTime($dia))->format('d/m')) . '</text>';
        }
        $i++;
    }
    if (count($pontos) > 1) {
        $svg .= '<polyline class="linha-roi" points="' . implode(' ', $pontos) . '"></polyline>';
    }
    return $svg . '</svg>';
}

// Ticket do produto principal: valor cobrado medio das vendas aprovadas sem order bump, da
// campanha no periodo (ou de todas as vendas dos ultimos 30 dias, se a campanha nao vendeu)
function analise_ticket(PDO $db, string $id, string $dia1, string $dia2): ?float
{
    $tz = fuso();
    $utc = new DateTimeZone('UTC');
    $de = (new DateTime($dia1, $tz))->setTimezone($utc)->format('Y-m-d H:i:s');
    $ate = (new DateTime($dia2, $tz))->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
    $medio = function (array $vendas): ?float {
        $v = array_values(array_filter($vendas, fn($x) => aprovada($x) && !eh_bump($x) && venda_no_filtro($x)));
        return $v ? array_sum(array_map(fn($x) => (int)$x['valor'], $v)) / count($v) : null;
    };
    $daCampanha = array_filter(consulta($db, 'SELECT * FROM vendas WHERE recebida_em >= ? AND recebida_em < ? AND utm_campaign LIKE ?', [$de, $ate, '%|' . $id]),
        fn($v) => gestor_id_utm($v['utm_campaign']) === $id);
    return $medio($daCampanha) ?? $medio(consulta($db, 'SELECT * FROM vendas WHERE recebida_em >= ?', [gmdate('Y-m-d H:i:s', time() - 30 * 86400)]));
}

// Leitura da campanha: [[classe (ok, alerta, erro, neutro), texto]], com as regras do Allan
// (reuniao de 30/09): custo por IC ate 10% do ticket e aceitavel; CTR bom e de 2% para cima;
// campanha barata que nao vende pode ser "ponto de contato"; subir orcamento aos poucos.
function analise_leitura(PDO $db, string $id, array $linhas, ?array $obj, float $pct, string $dia1, string $dia2): array
{
    $hoje = (new DateTime('today', fuso()))->format('Y-m-d');
    $soma = function (array $ls) use ($pct): array {
        $t = gestor_linha_nova();
        foreach ($ls as $l) {
            foreach ($t as $c => $v) {
                if (is_int($v)) {
                    $t[$c] += $l[$c];
                }
            }
        }
        return gestor_metricas(['id' => '', 'obj' => null] + $t, $pct);
    };
    $t = $soma($linhas);
    $dicas = [];
    if ($t['gasto'] === 0) {
        return [['neutro', 'Sem gasto no período: escolha outro período no topo para ler a campanha.']];
    }
    // 1. ROI do periodo (arredondado como aparece na tela: "2,00" ja e verde)
    $roi = $t['roi'] === null ? null : round($t['roi'], 2);
    $dicas[] = [$roi === null ? 'neutro' : ['negativo' => 'erro', 'medio' => 'alerta', 'positivo' => 'ok'][cor_roi($roi)],
        'ROI do período ' . analise_num($roi) . ($roi === null ? '.' : ($roi >= 2 ? ': de 2 para cima, a campanha se paga com folga.' : ($roi >= 1 ? ': se paga, mas com margem curta (entre 1 e 2).' : ': abaixo de 1, não se pagou no período.')))];
    // Dias fechados (hoje ainda esta pela metade), do mais antigo para o mais novo
    $fechados = array_filter($linhas, fn($dia) => $dia < $hoje, ARRAY_FILTER_USE_KEY);
    $comGasto = array_filter($fechados, fn($l) => $l['gasto'] > 0);
    // 2. Tendencia: os 3 ultimos dias com gasto contra os 3 de antes
    if (count($comGasto) >= 6) {
        $ult = $soma(array_slice($comGasto, -3, 3, true))['roi'];
        $ant = $soma(array_slice($comGasto, -6, 3, true))['roi'];
        if ($ult !== null && $ant !== null && abs($ult - $ant) >= 0.1) {
            $dicas[] = [$ult > $ant ? 'ok' : 'alerta', 'Nos últimos 3 dias o ROI ' . ($ult > $ant ? 'subiu' : 'caiu') . ' de ' . analise_num($ant) . ' para ' . analise_num($ult) . '.'];
        }
    }
    // 3. Custo por IC contra 10% do ticket
    $ticket = analise_ticket($db, $id, $dia1, $dia2);
    $limite = $ticket ? $ticket / 10 : null;
    if ($t['cpi'] !== null && $limite) {
        $barato = $t['cpi'] <= $limite;
        $dicas[] = [$barato ? 'ok' : 'alerta', 'Custo por IC ' . reais((int)round($t['cpi'])) . ($barato ? ': dentro dos 10% do ticket (' : ': acima dos 10% do ticket (') . reais((int)round($limite)) . ')'
            . ($barato ? ', aceitável.' : '. O início de checkout está saindo caro: confira a página e a oferta.')];
    }
    // 4. CTR contra os 2% de referencia
    if ($t['ctr'] !== null) {
        $dicas[] = [$t['ctr'] >= 2 ? 'ok' : 'alerta', 'CTR ' . analise_num($t['ctr']) . '%' . ($t['ctr'] >= 2 ? ': gancho bom (2% ou mais).' : ': abaixo dos 2% de referência. Gancho fraco: vale testar outro criativo.')];
    }
    // 5. Dias seguidos sem se pagar (dos mais recentes para tras)
    $seguidos = 0;
    foreach (array_reverse($comGasto, true) as $l) {
        $r = $soma([$l])['roi'];
        if ($r === null || $r >= 1) {
            break;
        }
        $seguidos++;
    }
    if ($seguidos >= 2) {
        $barato = $t['cpi'] !== null && $limite && $t['cpi'] <= $limite;
        $dicas[] = $barato
            ? ['alerta', 'ROI abaixo de 1 há ' . $seguidos . ' dias, mas o custo por IC está barato: pode ser um ponto de contato (o clique que vende depois, em outra campanha). Olhe antes de pausar.']
            : ['erro', 'ROI abaixo de 1 há ' . $seguidos . ' dias e o custo por IC não está barato: candidata a pausar.'];
    }
    // 6. Escalar: ROI de 2 para cima nos 3 ultimos dias com gasto
    $orc = $obj['orcamento_diario'] ?? null;
    if (count($comGasto) >= 3 && ($r3 = $soma(array_slice($comGasto, -3, 3, true))['roi']) !== null && $r3 >= 2) {
        $dicas[] = ['ok', 'ROI de ' . analise_num($r3) . ' nos últimos 3 dias: candidata a escalar.'
            . ($orc ? ' Suba no máximo 20% por vez (de ' . reais((int)$orc) . ' para ' . reais((int)round($orc * 1.2)) . ') para não reiniciar o aprendizado da Meta.' : ' Suba o orçamento aos poucos (até 20% por vez) para não reiniciar o aprendizado da Meta.')];
    }
    // 7. Melhores horarios: as vendas aprovadas da campanha nos ultimos 30 dias, por hora
    $horas = array_fill(0, 24, 0);
    $tz = fuso();
    foreach (consulta($db, 'SELECT * FROM vendas WHERE recebida_em >= ? AND utm_campaign LIKE ?', [gmdate('Y-m-d H:i:s', time() - 30 * 86400), '%|' . $id]) as $v) {
        if (gestor_id_utm($v['utm_campaign']) === $id && aprovada($v) && !eh_bump($v) && venda_no_filtro($v)) {
            $horas[(int)(new DateTime($v['aprovada_em'] ?: $v['recebida_em'], new DateTimeZone('UTC')))->setTimezone($tz)->format('G')]++;
        }
    }
    $total = array_sum($horas);
    if ($total >= 5) {
        $melhor = 0;
        $janela = -1;
        for ($h = 0; $h < 24; $h++) {
            $s = $horas[$h] + $horas[($h + 1) % 24] + $horas[($h + 2) % 24];
            if ($s > $melhor) {
                [$melhor, $janela] = [$s, $h];
            }
        }
        $dicas[] = ['neutro', $melhor . ' de ' . $total . ' vendas dos últimos 30 dias saíram entre ' . $janela . 'h e ' . (($janela + 3) % 24) . 'h: bom horário para ter mais orçamento.'];
    }
    if ($t['pend']) {
        $dicas[] = ['neutro', $t['pend'] . ' Pix ou boleto gerado e ainda não pago no período: dá para chamar no WhatsApp pela aba Vendas.'];
    }
    return $dicas;
}

// Quem compra pela Meta: pizza por sexo e lista por idade
function analise_publico_html(array $p): string
{
    $porSexo = array_fill_keys(array_values(META_SEXOS), 0);
    $porIdade = [];
    foreach ($p['linhas'] as [$idade, $sexo, $compras]) {
        $porSexo[META_SEXOS[$sexo] ?? 'Não informado'] += $compras;
        $porIdade[$idade] = ($porIdade[$idade] ?? 0) + $compras;
    }
    $total = array_sum($porSexo);
    $html = '';
    if ($p['erro'] && !$p['linhas']) {
        return '<p class="suave">' . e($p['erro']) . '</p>';
    }
    if (!$total) {
        $html .= '<p class="suave">A Meta não registrou compras desta campanha no período. A divisão por sexo depende do evento Purchase chegar à Meta (pixel da Kiwify ou UTMify).</p>';
    } else {
        ksort($porIdade);
        $porIdade = array_filter($porIdade);
        $html .= resumo_rosca($porSexo, ['Mulheres' => '#C13584', 'Homens' => '#1D6FF2', 'Não informado' => '#9CA3AF'], 'Compras')
            . '<p class="suave analise-sub">Por idade</p>'
            . resumo_lista_aneis(array_map(fn($i, $n) => [e($i) . ' anos', $n, $n * 100 / $total], array_keys($porIdade), $porIdade));
    }
    if ($p['erro']) {
        $html .= '<p class="suave">' . e($p['erro']) . '</p>';
    }
    return $html . ($p['buscado_em'] ? '<small class="rc-rodape">Dados da Meta de ' . e(data_local($p['buscado_em'], 'd/m H:i')) . '</small>' : '');
}

function gestor_analise_render(PDO $db, string $id, ?array $obj, array $linhas, float $pct, string $dia1, string $dia2): void
{
    $total = gestor_linha_nova();
    foreach ($linhas as $l) {
        foreach ($total as $c => $v) {
            if (is_int($v)) {
                $total[$c] += $l[$c];
            }
        }
    }
    $t = gestor_metricas(['id' => '', 'obj' => null] + $total, $pct);
    $ticket = analise_ticket($db, $id, $dia1, $dia2);
    $hoje = (new DateTime('today', fuso()))->format('Y-m-d');

    // Numeros do periodo
    echo '<div class="rgrade analise">'
        . resumo_cartao((string)$t['vendas'], 'Compradores', 'Vendas aprovadas da campanha no período (order bump não conta como outro comprador). O filtro de produto do topo vale aqui.', '', $t['pend'] ? $t['pend'] . ' Pix pendente' . ($t['pend'] === 1 ? '' : 's') : '', 'c2')
        . resumo_cartao(analise_num($t['roi']), 'ROI', '(Faturamento − imposto da Meta) ÷ gasto, como na UTMify. Vermelho abaixo de 1, laranja de 1 até 2, verde de 2 para cima.', cor_roi($t['roi']), '', 'c2')
        . resumo_cartao(reais($t['lucro']), 'Lucro', 'Faturamento líquido − gasto − imposto da Meta.', $t['lucro'] < 0 ? 'negativo' : ($t['lucro'] > 0 ? 'positivo' : ''), '', 'c2')
        . resumo_cartao(reais($t['gasto']), 'Gasto', 'Quanto a Meta cobrou no período, sem o imposto.', '', 'faturou ' . reais($t['fat']), 'c2')
        . resumo_cartao($t['cpa'] === null ? 'N/A' : reais((int)round($t['cpa'])), 'CPA', 'Gasto ÷ compradores.', '', '', 'c2')
        . resumo_cartao($t['cpi'] === null ? 'N/A' : reais((int)round($t['cpi'])), 'Custo por IC', 'Gasto ÷ inícios de checkout. A regra do Allan: até 10% do ticket é aceitável.', $t['cpi'] !== null && $ticket ? ($t['cpi'] <= $ticket / 10 ? 'positivo' : 'medio') : '', $ticket ? '10% do ticket: ' . reais((int)round($ticket / 10)) : '', 'c2')
        . '</div>';

    // Compradores e ROI por dia + leitura da campanha
    $leitura = '<ul class="leitura">';
    foreach (analise_leitura($db, $id, $linhas, $obj, $pct, $dia1, $dia2) as [$classe, $texto]) {
        $leitura .= '<li class="' . e($classe) . '">' . e($texto) . '</li>';
    }
    $leitura .= '</ul>';
    echo '<div class="rgrade analise"><section class="rc c8"><div class="rc-cab"><span>Compradores e ROI por dia</span>'
        . info('Barras: quantas pessoas compraram em cada dia do período. Pontos e linha: o ROI do dia (vermelho abaixo de 1, laranja até 2, verde de 2 para cima). As linhas tracejadas marcam o ROI 1 e o 2. Passe o mouse no dia para ver os números.') . '</div>'
        . analise_svg_compradores($linhas, $pct) . '</section>'
        . '<section class="rc c4"><div class="rc-cab"><span>Leitura da campanha</span>'
        . info('Dicas a partir dos números do período, com as regras de otimização do Allan: custo por IC até 10% do ticket, CTR de 2% para cima, ponto de contato antes de pausar e subir o orçamento até 20% por vez. São sugestões: a decisão continua sua.') . '</div>' . $leitura . '</section></div>';

    // Grafico de bolsa desde o inicio + quem compra
    $inicio = analise_inicio($db, $id);
    $velas = $inicio ? analise_velas(gestor_dias_linhas($db, $id, $inicio, $hoje), $pct) : [];
    $publico = meta_publico_campanha($id, $dia1, min($dia2, $hoje));
    $dias = max(7, (int)((track_config() ?? [])['dias_retencao'] ?? 90));
    echo '<div class="rgrade analise"><section class="rc c8"><div class="rc-cab"><span>ROI desde o início' . ($inicio ? ' <span class="suave">(desde ' . e((new DateTime($inicio))->format('d/m/Y')) . ')</span>' : '') . '</span>'
        . info('Como o gráfico de uma ação: cada vela é um dia. Ela abre no ROI acumulado da campanha até o dia anterior e fecha no acumulado até o fim do dia; o pavio vai até o ROI do próprio dia, o que puxou o acumulado. A primeira vela abre no 1, o ponto em que a campanha se paga. Verde: o acumulado subiu; vermelho: caiu. Dia sem gasto nem venda fica sem vela. Passe o mouse para ver o ROI. Começa no primeiro dia da campanha que o painel ainda guarda (as vendas ficam ' . $dias . ' dias).') . '</div>'
        . analise_svg_velas($velas) . '</section>'
        . '<section class="rc c4"><div class="rc-cab"><span>Quem compra</span>'
        . info('Compras que a Meta atribui a esta campanha no período, por sexo e idade. A Kiwify não pergunta o sexo do comprador, então a fonte é a Meta (evento Purchase do pixel): o total pode ser diferente das vendas da Kiwify. Atualiza a cada 3 horas.') . '</div>'
        . analise_publico_html($publico) . '</section></div>';
}
