<?php
// Painel da Bio (lib/bio.php): os links e os textos da pagina de links do Instagram, com as
// visitas e os cliques de cada link. Cadastrar, editar, ligar e desligar, mudar a ordem e
// apagar; os textos, o WhatsApp e as redes da pagina. Acesso "bio" (Usuarios).

require __DIR__ . '/lib/util.php';
require __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/bio.php';

exigir_login('bio');
header('Cache-Control: no-store');

$periodo = periodo_da_tela('30d'); // o mesmo seletor e o mesmo periodo lembrado do UTM
$volta = 'bio.php?' . http_build_query(['periodo' => $periodo]);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $acao = (string)($_POST['acao'] ?? '');
    $id = ctype_digit((string)($_POST['id'] ?? '')) ? (int)$_POST['id'] : 0;
    if (!csrf_valido()) {
        aviso_definir('Sessão expirada. Recarregue a página e tente de novo.', 'erro');
    } elseif ($acao === 'salvar') {
        [$ok, $msg] = bio_salvar_link($_POST, $id ?: null);
        aviso_definir($msg, $ok ? 'ok' : 'erro');
        if (!$ok) {
            $volta .= $id ? '&editar=' . $id : '';
            $_SESSION['bio_form'] = array_intersect_key($_POST, array_flip(['titulo', 'subtitulo', 'url', 'icone', 'cor', 'destaque', 'slug', 'principal']));
        }
    } elseif ($acao === 'ativo' && $id) {
        $ativo = ($_POST['ativo'] ?? '') === '1';
        aviso_definir(bio_ativar_link($id, $ativo) ? ($ativo ? 'Link ligado: volta a aparecer na página.' : 'Link desligado: sai da página, sem ser apagado.') : 'Esse link já não existe.');
    } elseif ($acao === 'mover' && $id) {
        bio_mover_link($id, (int)($_POST['dir'] ?? 0));
    } elseif ($acao === 'apagar' && $id) {
        aviso_definir(bio_apagar_link($id) ? 'Link apagado. Os cliques dele continuam em Eventos.' : 'Esse link já não existe.');
    } elseif ($acao === 'config') {
        [$ok, $msg] = bio_salvar_config($_POST);
        aviso_definir($msg, $ok ? 'ok' : 'erro');
    }
    header('Location: ' . $volta . ($acao === 'config' ? '#pagina' : ($acao === 'salvar' && !empty($_SESSION['bio_form']) ? '#link' : '')));
    exit;
}

$cfg = bio_config();
$links = bio_links();
[$de, $ate] = periodo_utc($periodo);
[$visitas, $pessoas, $clicaram, $porSlug, $serie, $dias] = bio_numeros($de, $ate);
$cliques = array_sum(array_column($porSlug, 0));
$campeao = null;
foreach ($links as $l) {
    if (($porSlug[$l['slug']][0] ?? 0) > ($campeao ? $porSlug[$campeao['slug']][0] : 0)) {
        $campeao = $l;
    }
}
$editar = ctype_digit((string)($_GET['editar'] ?? '')) ? bio_link((int)$_GET['editar']) : null;
$form = $_SESSION['bio_form'] ?? null;
unset($_SESSION['bio_form']);
$val = fn(string $k, string $padrao = '') => (string)($form[$k] ?? ($editar[$k] ?? $padrao));
$csrf = '<input type="hidden" name="csrf" value="' . e(token_csrf()) . '">';
$pct = fn(int $a, int $b) => $b ? $a * 100 / $b : null;
$num = fn(float $v, int $c = 1) => number_format($v, $c, ',', '.');

pagina_inicio('Bio');
casca_inicio('bio');
barra_periodo('bio.php', $periodo);
echo '<nav class="abas abas-nucleo" aria-label="Bio"><span class="abas-titulo">Bio · Página de links</span>'
    . '<a href="bio.php" class="atual" aria-current="page">' . icone('link') . 'Links</a>'
    . ($cfg['endereco'] !== '' ? '<a href="' . e($cfg['endereco']) . '" target="_blank" rel="noopener">' . icone('externo') . 'Ver a página</a>' : '')
    . '</nav>';
?>
<main>
<?php
if ($aviso = aviso_pegar()) {
    echo '<p class="' . ($aviso[1] === 'erro' ? 'erro' : 'aviso-ok') . '">' . e($aviso[0]) . '</p>';
}
echo '<div class="bio-topo"><span class="suave">' . e('Visitas da página ' . implode('', array_slice(bio_caminho($cfg), 1)) . ' e cliques nos links (t.js do painel).') . '</span></div>';

echo cartoes_kpi([
    cartao_kpi('Visitas da Bio', (string)$visitas, $pessoas . ' pessoa' . ($pessoas === 1 ? '' : 's') . ' no período', 'visitantes', 'Vezes que a página da bio foi aberta (PageView do t.js).'),
    cartao_kpi('Cliques nos links', (string)$cliques, $clicaram === 1 ? '1 pessoa clicou' : $clicaram . ' pessoas clicaram', 'link', 'Cliques nos links da página (evento BioClique).'),
    cartao_kpi('Taxa de clique', ($t = $pct($clicaram, $pessoas)) === null ? '—' : $num($t) . '%', 'de quem abriu, quantos clicaram', 'atividade', 'Pessoas que clicaram em algum link ÷ pessoas que abriram a página.'),
    cartao_kpi('Link campeão', $campeao ? (string)$campeao['titulo'] : '—', $campeao ? ($porSlug[$campeao['slug']][0] . ' clique' . ($porSlug[$campeao['slug']][0] === 1 ? '' : 's')) : 'sem cliques no período', 'estrela'),
]);

// Tabela inteligente dos links, na ordem da pagina
echo tabela_card_inicio('bio-links', 'Links da página', count($links), ['icone' => 'link', 'busca' => 'Buscar link', 'por_pagina' => 25,
    'dica' => 'Na ordem em que aparecem na página. Os do próprio site ganham as etiquetas da bio (organico / instagram-bio / bio / o nome do link), e a venda que vier dali aparece como orgânica da bio. Desligar tira da página sem apagar.',
    'acoes' => '<a class="botao" href="#link">' . icone('lapis', 14) . 'Novo link</a>']);
echo '<table><thead><tr><th class="nome">Link</th><th data-col="endereco">Endereço</th><th data-col="cliques">' . com_info('Cliques', 'Cliques no período (e quantas pessoas).') . '</th>'
    . '<th data-col="taxa">' . com_info('% das visitas', 'Pessoas que clicaram neste link ÷ pessoas que abriram a página.') . '</th>'
    . '<th data-col="semana">' . com_info('Últimos 7 dias', 'Cliques por dia na última semana.') . '</th><th data-col="ordem">Ordem</th><th data-filtro data-col="ativo">Na página</th><th class="acoes"></th></tr></thead><tbody>';
$total = count($links);
foreach ($links as $n => $l) {
    $ativo = (bool)(int)$l['ativo'];
    [$cl, $pe] = $porSlug[$l['slug']] ?? [0, 0];
    $semana = array_map(fn($d) => (int)($serie[$l['slug']][$d] ?? 0), $dias);
    $rotDias = array_map(fn($d, $v) => (new DateTime($d))->format('d/m') . ': ' . $v . ' clique' . ($v === 1 ? '' : 's'), $dias, $semana);
    $endereco = (string)$l['url'] === '' && $l['icone'] === 'whatsapp' ? ($cfg['whatsapp'] !== '' ? 'WhatsApp ' . $cfg['whatsapp'] : 'WhatsApp (cadastre o número abaixo)') : (string)$l['url'];
    $mover = fn(int $dir, string $ico, string $rot, bool $pode) => '<form method="post" action="' . e($volta) . '" class="form-linha">' . $csrf . '<input type="hidden" name="acao" value="mover"><input type="hidden" name="id" value="' . (int)$l['id'] . '">'
        . '<input type="hidden" name="dir" value="' . $dir . '"><button type="submit" class="discreto neutro tcard-icone" aria-label="' . e($rot) . '" title="' . e($rot) . '"' . ($pode ? '' : ' disabled') . '>' . icone($ico, 14) . '</button></form>';
    $chave = '<form method="post" action="' . e($volta) . '" class="form-linha">' . $csrf . '<input type="hidden" name="acao" value="ativo"><input type="hidden" name="id" value="' . (int)$l['id'] . '">'
        . '<input type="hidden" name="ativo" value="' . ($ativo ? '0' : '1') . '"><button type="submit" class="chave' . ($ativo ? ' ligada' : '') . '" role="switch" aria-checked="' . ($ativo ? 'true' : 'false') . '"'
        . ' aria-label="' . e(($ativo ? 'Desligar ' : 'Ligar ') . $l['titulo']) . '" title="' . ($ativo ? 'Na página: clique para tirar' : 'Fora da página: clique para mostrar') . '"><span></span></button></form>';
    echo '<tr><td class="nome quebra"><b>' . e($l['titulo']) . '</b>' . celula_situacao($ativo, 'Na página', 'Fora da página')
        . ($l['destaque'] ? ' ' . celula_selo((string)$l['destaque'], ['ambar' => 'laranja', 'verde' => 'verde', 'whatsapp' => 'verde'][$l['cor']] ?? 'azul') : '')
        . ($l['subtitulo'] ? '<br><span class="suave">' . e($l['subtitulo']) . '</span>' : '') . '</td>'
        . '<td class="quebra"><code>' . e($endereco) . '</code><br><span class="suave">' . e($l['slug']) . '</span></td>'
        . '<td>' . $cl . ($pe ? ' <span class="suave">(' . $pe . ')</span>' : '') . '</td>'
        . '<td>' . celula_anel($pct($pe, $pessoas)) . '</td>'
        . '<td>' . celula_mini_grafico($semana, $rotDias) . '</td>'
        . '<td><span class="bio-ordem">' . $mover(-1, 'seta-cima', 'Subir', $n > 0) . $mover(1, 'seta-baixo', 'Descer', $n < $total - 1) . '</span></td>'
        . '<td data-valor="' . ($ativo ? 'Na página' : 'Fora da página') . '">' . $chave . '</td>'
        . '<td class="acoes">' . menu_linha([
            '<a href="' . e($volta . '&editar=' . (int)$l['id'] . '#link') . '">' . icone('lapis', 14) . 'Editar</a>',
            '<form method="post" action="' . e($volta) . '" data-confirma="' . e('Apagar o link "' . $l['titulo'] . '"?') . '">' . $csrf . '<input type="hidden" name="acao" value="apagar"><input type="hidden" name="id" value="' . (int)$l['id'] . '">'
                . '<button type="submit" class="perigo">' . icone('lixo', 14) . 'Apagar</button></form>',
        ], 'Ações do link') . '</td></tr>';
}
if (!$links) {
    echo '<tr><td colspan="8" class="suave">Nenhum link ainda. Cadastre o primeiro abaixo.</td></tr>';
}
echo '</tbody></table>' . tabela_card_fim('link', 'links');

// Formulario do link (novo ou editar)
$opcoes = fn(array $lista, string $atual) => implode('', array_map(fn($k, $rot) => '<option value="' . e($k) . '"' . ($k === $atual ? ' selected' : '') . '>' . e($rot) . '</option>', array_keys($lista), $lista));
echo '<section class="cartao" id="link"><h2>' . ($editar ? 'Editar o link "' . e($editar['titulo']) . '"' : 'Novo link') . '</h2>'
    . '<form method="post" action="' . e($volta) . '" class="bio-form">' . $csrf . '<input type="hidden" name="acao" value="salvar">' . ($editar ? '<input type="hidden" name="id" value="' . (int)$editar['id'] . '">' : '')
    . '<label>Título <input name="titulo" required maxlength="80" value="' . e($val('titulo')) . '" placeholder="Ex.: Drive de Projetos (+200 Projetos)"></label>'
    . '<label>Descrição <input name="subtitulo" maxlength="160" value="' . e($val('subtitulo')) . '" placeholder="Uma linha embaixo do título"></label>'
    . '<label>' . com_info('Endereço', 'https://..., mailto:, tel: ou um caminho do próprio site (ex.: /app/), que ganha as etiquetas da bio. Com o ícone WhatsApp e o endereço vazio, usa o WhatsApp cadastrado abaixo.') . ' <input name="url" maxlength="500" value="' . e($val('url')) . '" placeholder="/app/ ou https://..." inputmode="url"></label>'
    . '<label>' . com_info('Etiqueta', 'O selo no canto do cartão (ex.: Mais vendido). Vazio: sem selo.') . ' <input name="destaque" maxlength="30" value="' . e($val('destaque')) . '"></label>'
    . '<label>Ícone <select name="icone">' . $opcoes(BIO_ICONES, $val('icone', 'link')) . '</select></label>'
    . '<label>Cor <select name="cor">' . $opcoes(BIO_CORES, $val('cor', 'azul')) . '</select></label>'
    . '<label>' . com_info('Nome no rastreio', 'Vai no utm_content e no evento do clique. Vazio: sai do título. Mudar depois separa os cliques antigos dos novos.') . ' <input name="slug" maxlength="40" value="' . e($val('slug')) . '" placeholder="ex.: catalogo"></label>'
    . '<label class="caixa"><input type="checkbox" name="principal" value="1"' . ($val('principal') ? ' checked' : '') . '> Cartão em destaque (o primeiro, com a borda)</label>'
    . '<div class="linha-botoes"><button type="submit">' . ($editar ? 'Salvar o link' : 'Criar o link') . '</button>' . ($editar ? '<a href="' . e($volta) . '">Cancelar</a>' : '') . '</div></form></section>';

// Textos, WhatsApp e redes da pagina
echo '<section class="cartao" id="pagina"><h2>' . com_info('Textos e redes da página', 'O que aparece no topo e no rodapé da página de links. O WhatsApp vale para o botão verde, o ícone do rodapé e os links com o ícone WhatsApp sem endereço. Sem número, eles saem da página.') . '</h2>'
    . '<form method="post" action="' . e($volta) . '" class="bio-form">' . $csrf . '<input type="hidden" name="acao" value="config">'
    . '<label>Nome <input name="nome" maxlength="200" value="' . e($cfg['nome']) . '"></label>'
    . '<label>Selo <input name="selo" maxlength="200" value="' . e($cfg['selo']) . '" placeholder="Ex.: Drive de Projetos • Engenharia Civil"></label>'
    . '<label>Chamada (em negrito) <input name="chamada" maxlength="200" value="' . e($cfg['chamada']) . '"></label>'
    . '<label>Texto <input name="texto" maxlength="300" value="' . e($cfg['texto']) . '"></label>'
    . '<label>' . com_info('WhatsApp', 'Com o código do país e o DDD, só números (ex.: 5581987654321).') . ' <input name="whatsapp" inputmode="tel" maxlength="20" value="' . e($cfg['whatsapp']) . '" placeholder="5581987654321"></label>'
    . '<label>Mensagem do WhatsApp <input name="whatsapp_msg" maxlength="200" value="' . e($cfg['whatsapp_msg']) . '"></label>'
    . '<label>Instagram <input name="instagram" maxlength="200" value="' . e($cfg['instagram']) . '" placeholder="https://www.instagram.com/..."></label>'
    . '<label>LinkedIn <input name="linkedin" maxlength="200" value="' . e($cfg['linkedin']) . '" placeholder="https://www.linkedin.com/in/..."></label>'
    . '<label>' . com_info('Endereço da página', 'Onde a página está no ar (ex.: https://engdesk.pro/bio/). Serve para contar as visitas e para o botão Ver a página.') . ' <input name="endereco" maxlength="200" value="' . e($cfg['endereco']) . '" placeholder="https://seusite.com/bio/"></label>'
    . '<div class="linha-botoes"><button type="submit">Salvar textos e redes</button></div></form></section>';
?>
</main>
<?php
casca_fim();
pagina_fim();
