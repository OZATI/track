<?php
// Casca dos paineis (lateral da secao, barra de topo e pesquisa Ctrl K): o componente do padrao de interface
// dos paineis (guia interno de interface dos paineis, 15/11/2026). Escrito a partir da lateral e da barra de topo do
// CMS no ar (admin.engdesk.pro); o Track e a fonte e o CMS passa a consumir esta copia (scripts/copiar-para.sh), sem tokens duplicados.
//   lib/casca.php  markup (este arquivo)     lib/casca.css  desenho, so por variavel     casca.js  Ctrl K, recolher, voltar
// O CSS vai inline (casca_css), como o admin_css_base(): a lib/ inteira e copiada, sem arquivo novo na raiz para a copia levar.
// Largura >= 1000 px: a lateral e a barra de topo; abaixo disso as abas do painel (.abas) e a barra do polegar, como antes.

// CSS da casca (so quando a pagina usa a casca nova)
function casca_css(): string
{
    $arq = __DIR__ . '/casca.css';
    return is_file($arq) ? (string)file_get_contents($arq) : '';
}

// Lateral da secao. $itens: lista de
//   link:  ['id' => 'geral', 'rot' => 'Resumo', 'ico' => 'resumo', 'href' => './?aba=geral']
//   grupo: ['id' => 'trafego', 'rot' => 'Tráfego', 'ico' => 'trafego', 'sub' => [['id' => ..., 'rot' => ..., 'href' => ...], ...]]
// $atual: o id da secao aberta (marca o item e abre o grupo). $rotulo: o aria-label da navegacao. $pe: HTML do rodape (links, sem JS).
// $opt: 'cab' (HTML entre a marca e a busca: voltar e cartao da conta), 'busca' (rotulo do botao de pesquisa) e 'placeholder' (da janela do Ctrl K).
// $marca: HTML do logo e do nome (casca_marca), na linha do botao de recolher a navegacao, no topo da lateral.
// A busca, o recolher e o voltar nascem com hidden: o casca.js os liga (sem JS, grupos e links continuam valendo).
function casca_lateral(array $itens, string $atual, string $rotulo, string $pe = '', string $marca = '', array $opt = []): string
{
    $abre = function (array $it, string $classe, string $grupo) use ($atual): string {
        $sim = $it['id'] === $atual;
        return '<a class="' . $classe . ($sim ? ' ativo' : '') . '" href="' . e($it['href']) . '" data-cas-secao="' . e($it['id']) . '" data-titulo="' . e($it['rot']) . '"'
            . ' data-grupo="' . e($grupo) . '" data-ico="' . e((string)($it['ico'] ?? '')) . '"' . ($sim ? ' aria-current="page"' : '') . '>';
    };
    $lista = '';
    foreach ($itens as $it) {
        if (isset($it['sub'])) {
            $aberto = in_array($atual, array_column($it['sub'], 'id'), true);
            $sub = '';
            foreach ($it['sub'] as $s) {
                $sub .= '<li>' . $abre($s + ['ico' => $it['ico']], 'cas-lat-sub', $it['rot']) . '<span>' . e($s['rot']) . '</span></a></li>';
            }
            $lista .= '<li><details class="cas-lat-grupo' . ($aberto ? ' tem-ativo' : '') . '" data-cas-grupo="' . e($it['id']) . '"' . ($aberto ? ' open' : '') . '>'
                . '<summary class="cas-lat-link">' . icone($it['ico'], 20) . '<span>' . e($it['rot']) . '</span>' . icone('seta-baixo', 16) . '</summary>'
                . '<ul class="cas-lat-subs">' . $sub . '</ul></details></li>';
        } else {
            $lista .= '<li>' . $abre($it, 'cas-lat-link', $rotulo) . icone($it['ico'], 20) . '<span>' . e($it['rot']) . '</span></a></li>';
        }
    }
    return '<aside class="cas-lat" id="cas-lat" aria-label="' . e($rotulo) . '" data-cas-lat' . (isset($opt['placeholder']) ? ' data-cas-placeholder="' . e($opt['placeholder']) . '"' : '') . '>'
        . '<div class="cas-lat-marca">' . $marca . casca_recolher_botao('cas-lat-recolher', 'Recolher navegação', true) . '</div>'
        . ($opt['cab'] ?? '')
        . '<div class="cas-lat-topo">'
        . '<button type="button" class="cas-lat-busca' . (isset($opt['busca']) ? ' cas-lat-busca-longa' : '') . '" data-cas-busca hidden aria-haspopup="dialog" aria-keyshortcuts="Control+K">'
        . icone('busca', 16) . '<span>' . e($opt['busca'] ?? 'Pesquisar') . '</span><kbd aria-hidden="true">Ctrl K</kbd></button>'
        . '</div>'
        . '<nav class="cas-lat-nav" aria-label="Seções de ' . e($rotulo) . '"><ul class="cas-lat-lista">' . $lista . '</ul></nav>'
        . ($pe !== '' ? '<div class="cas-lat-pe">' . $pe . '</div>' : '')
        . '</aside>';
}

// Logo e nome na linha do botao de recolher (o ultimo argumento de casca_lateral). $logo: endereco da imagem (decorativa, alt vazio).
function casca_marca(string $nome, string $href, string $logo = ''): string
{
    return '<a class="cas-lat-logo" href="' . e($href) . '" title="' . e($nome) . '">'
        . ($logo !== '' ? '<img src="' . e($logo) . '" width="24" height="24" alt="">' : '') . '<span>' . e($nome) . '</span></a>';
}

// Botao de recolher/expandir a navegacao, com a dica ao passar o mouse ou focar ("Recolher navegacao" e o atalho Ctrl B; no Mac o casca.js
// troca por ⌘ B). A dica e do CSS (nao title, que some em segundos); aria-label, aria-expanded, aria-controls e aria-keyshortcuts dizem o mesmo ao
// leitor de tela. Nasce com hidden: o casca.js liga (sem ele a lateral continua aberta e os links valem). $aberta: o estado de partida do
// aria-expanded (a lateral comeca aberta; o JS acerta pelo que o navegador lembrou).
function casca_recolher_botao(string $classe, string $rotulo, bool $aberta): string
{
    $id = 'cas-dica-' . ($aberta ? 'recolher' : 'expandir');
    return '<span class="cas-tip">'
        . '<button type="button" class="' . e($classe) . '" data-cas-recolher hidden aria-expanded="' . ($aberta ? 'true' : 'false') . '" aria-controls="cas-lat"'
        . ' aria-label="' . e($rotulo) . '" aria-keyshortcuts="Control+B Meta+B" aria-describedby="' . $id . '">' . icone('painel', 18) . '</button>'
        . '<span class="cas-dica" role="tooltip" id="' . $id . '" aria-hidden="true">' . e($rotulo) . '<span class="cas-dica-teclas"><kbd data-cas-tecla-ctrl>Ctrl</kbd><kbd>B</kbd></span></span>'
        . '</span>';
}

// Um link do rodape da lateral (entra no Ctrl K junto com os itens)
function casca_lateral_pe(string $id, string $rot, string $ico, string $href, string $grupo, bool $atual): string
{
    return '<a class="cas-lat-link' . ($atual ? ' ativo' : '') . '" href="' . e($href) . '" data-cas-secao="' . e($id) . '" data-titulo="' . e($rot) . '" data-grupo="' . e($grupo) . '" data-ico="' . e($ico) . '"'
        . ($atual ? ' aria-current="page"' : '') . '>' . icone($ico, 20) . '<span>' . e($rot) . '</span></a>';
}

// Barra de topo: voltar e inicio juntos numa pilula, a trilha "grupo > tela" e, a direita, a acao de contexto ($acao: HTML pronto).
// O voltar so aparece com JavaScript (history.back); sem ele, o inicio basta. $titulo: o nome da tela na trilha e o <h1> da pagina
// (um so por tela); false quando a propria tela ja tem o seu <h1>.
function casca_topo(string $grupo, string $rot, string $ico, string $home, string $acao = '', bool $titulo = true): string
{
    return '<div class="cas-topo" data-cas-topo>'
        . casca_recolher_botao('cas-topo-menu', 'Expandir navegação', false)
        . '<div class="cas-topo-par" role="group" aria-label="Navegação">'
        . '<button type="button" class="cas-topo-bt" data-cas-voltar hidden aria-label="Voltar" title="Voltar">' . icone('voltar', 18) . '</button>'
        . '<a class="cas-topo-bt" href="' . e($home) . '" aria-label="Início do painel" title="Início do painel">' . icone('inicio', 18) . '</a>'
        . '</div>'
        . '<nav class="cas-topo-trilha" aria-label="Você está em"><ol>'
        . '<li class="cas-topo-ico" aria-hidden="true" data-cas-topo-ico>' . icone($ico, 18) . '</li>'
        . '<li class="cas-topo-grupo"><span data-cas-topo-grupo>' . e($grupo) . '</span></li>'
        . '<li class="cas-topo-sep" aria-hidden="true">' . icone('avancar', 14) . '</li>'
        . '<li class="cas-topo-atual" aria-current="location">' . ($titulo ? '<h1 data-cas-topo-nome>' . e($rot) . '</h1>' : '<span data-cas-topo-nome>' . e($rot) . '</span>') . '</li>'
        . '</ol></nav>'
        . ($acao !== '' ? '<div class="cas-topo-acao">' . $acao . '</div>' : '')
        . '</div>';
}

// ---------------------------------------------------------------- o painel UTM
// As oito secoes do UTM (antes as abas do alto), em tres grupos. Os links levam os filtros de cada tela (abas_filtro).
function casca_utm_itens(array $filtro = []): array
{
    $l = fn(string $id, string $rot) => ['id' => $id, 'rot' => $rot, 'href' => './?' . http_build_query(['aba' => $id] + abas_filtro($id, $filtro))];
    return [
        ['id' => 'geral', 'rot' => 'Resumo', 'ico' => 'resumo', 'href' => $l('geral', '')['href']],
        ['id' => 'g-trafego', 'rot' => 'Tráfego', 'ico' => 'trafego', 'sub' => [$l('trafego', 'Tráfego'), $l('visitantes', 'Visitantes'), $l('eventos', 'Eventos')]],
        ['id' => 'g-anuncios', 'rot' => 'Anúncios', 'ico' => 'anuncios', 'sub' => [$l('gestor', 'Gestor de anúncios'), $l('organico', 'Orgânico')]],
        ['id' => 'g-vendas', 'rot' => 'Vendas', 'ico' => 'vendas', 'sub' => [$l('vendas', 'Vendas'), $l('resumo', 'Conferência')]],
    ];
}

// A secao do UTM aberta, pela tela: o index.php pela ?aba= (a analise diaria "campanha" e parte do gestor), as outras pelo nome
function casca_utm_atual(): string
{
    $tela = basename((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH));
    if ($tela === 'meta-interacoes.php') {
        return 'organico';
    }
    if ($tela === 'configuracoes.php') {
        return 'configuracoes';
    }
    $aba = is_string($_GET['aba'] ?? null) ? $_GET['aba'] : 'geral';
    $aba = $aba === 'campanha' ? 'gestor' : $aba;
    return in_array($aba, ['geral', 'trafego', 'visitantes', 'eventos', 'gestor', 'organico', 'vendas', 'resumo'], true) ? $aba : 'geral';
}

// Lateral e barra de topo do UTM, para abrir a coluna do conteudo (casca_inicio)
function casca_utm(): string
{
    $filtro = is_array($GLOBALS['filtro'] ?? null) ? $GLOBALS['filtro'] : []; // o index.php guarda os filtros da tela aqui
    $atual = casca_utm_atual();
    $itens = casca_utm_itens($filtro);
    $rot = 'Resumo';
    $grupo = 'UTM';
    $ico = 'resumo';
    foreach ($itens as $it) {
        if (isset($it['sub'])) {
            foreach ($it['sub'] as $s) {
                if ($s['id'] === $atual) {
                    [$rot, $grupo, $ico] = [$s['rot'], $it['rot'], $it['ico']];
                }
            }
        }
    }
    if ($atual === 'configuracoes') {
        [$rot, $grupo, $ico] = ['Configurações', 'UTM', 'config'];
    }
    $pe = casca_lateral_pe('configuracoes', 'Configurações', 'config', admin_base() . 'configuracoes.php', 'UTM', $atual === 'configuracoes');
    // configuracoes.php e meta-interacoes.php ja trazem o proprio <h1>
    $cfg = track_config() ?? [];
    $marca = casca_marca((string)($cfg['app_nome'] ?? '') ?: 'Painel', './', admin_base() . 'app-192.png');
    // O filtro unico (periodo, site, pagina, produto e fonte) mora no canto direito da barra de topo, numa linha so: as etiquetas dos filtros ativos
    // (com x) e, depois delas, o botao curto "Filtros" com o contador. As abas que nao tem filtro ficam sem ele.
    $f = casca_utm_filtro();
    $GLOBALS['casca_filtro_topo'] = $f !== null;
    if ($f) {
        $f['compacto'] = true; // o botao so diz "Filtros" (e quantos); o que esta ativo vai nas etiquetas ao lado
    }
    return casca_lateral($itens, $atual, 'UTM', $pe, $marca) . casca_topo($grupo, $rot, $ico, './', $f ? casca_filtro_chips($f) . casca_filtro($f) : '', !in_array($atual, ['configuracoes'], true) && basename((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH)) !== 'meta-interacoes.php');
}

// ---------------------------------------------------------------- Configuracoes (lateral propria, no padrao da casca)
// Ao entrar em Configuracoes o trilho de paineis encolhe para so os icones (casca.css, .cas-config) e a lateral vira a de Configuracoes:
// "Configuracoes" (volta para a tela de onde se veio), pesquisa (Ctrl K), cartao de quem entrou e as secoes. O que cada secao mostra e
// as regras de gravacao continuam as das telas (conta.php, usuarios.php, integracoes.php, configuracoes.php): aqui so a navegacao.
const CASCA_CONFIG_SECOES = ['aparencia', 'notificacoes', 'aplicativo', 'orcamento', 'marco'];

// A tela de onde a pessoa veio: ?de= (caminho do proprio painel, validado) ou, na primeira entrada, o Referer do mesmo site que nao seja
// uma tela de Configuracoes. Sem nenhum dos dois, o inicio do painel.
function casca_config_de(): string
{
    $de = is_string($_GET['de'] ?? null) ? destino_seguro($_GET['de']) : './';
    if ($de === './' && !isset($_GET['de'])) {
        $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        $p = parse_url($ref);
        if ($ref !== '' && ($p['host'] ?? '') !== '' && $host !== '' && strcasecmp((string)$p['host'] . (isset($p['port']) ? ':' . $p['port'] : ''), $host) === 0) {
            $alvo = ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
            if (!preg_match('~/(configuracoes|conta|usuarios|integracoes|ga4|kiwify-api|meta-api|instagram-api)\.php~', $alvo)) {
                $de = destino_seguro($alvo);
            }
        }
    }
    return $de;
}

// A secao aberta: pela tela; em configuracoes.php, pelo ?s=
function casca_config_atual(): string
{
    $tela = basename((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH));
    if ($tela === 'conta.php') {
        return 'conta';
    }
    if ($tela === 'usuarios.php') {
        return 'usuarios';
    }
    if ($tela !== 'configuracoes.php') {
        return 'integracoes';
    }
    $s = is_string($_GET['s'] ?? null) ? $_GET['s'] : '';
    return in_array($s, CASCA_CONFIG_SECOES, true) ? $s : 'notificacoes';
}

// Parametros que as secoes carregam: a de configuracoes.php (?s=) e a volta (?de=). Usado nos links e no action dos formularios.
function casca_config_qs(string $secao, string $de): string
{
    return http_build_query(['s' => $secao] + ($de !== './' ? ['de' => $de] : []));
}

// As secoes que a pessoa pode abrir (o mesmo acesso de cada tela: o filtro por aba continua valendo)
function casca_config_itens(string $de): array
{
    $b = admin_base();
    $com = fn(string $href) => $href . ($de !== './' ? (strpos($href, '?') === false ? '?' : '&') . http_build_query(['de' => $de]) : '');
    $it = [['id' => 'conta', 'rot' => 'Minha conta', 'ico' => 'usuario', 'href' => $com(admin_url('conta'))]];
    if (usuario_pode('usuarios')) {
        $it[] = ['id' => 'usuarios', 'rot' => 'Usuários e acessos', 'ico' => 'usuarios', 'href' => $com(admin_url('usuarios'))];
    }
    if (usuario_pode('utm')) {
        foreach (['aparencia' => ['Aparência e tema', 'paleta'], 'notificacoes' => ['Notificações', 'sino'], 'aplicativo' => ['Aplicativo', 'grade'],
            'orcamento' => ['Orçamento pelo painel', 'carteira'], 'marco' => ['Antes × depois', 'calendario']] as $id => [$rot, $ico]) {
            $it[] = ['id' => $id, 'rot' => $rot, 'ico' => $ico, 'href' => $b . 'configuracoes.php?' . casca_config_qs($id, $de)];
        }
    }
    if (usuario_pode('integracoes')) {
        $it[] = ['id' => 'integracoes', 'rot' => 'Integrações', 'ico' => 'clipe', 'href' => $com($b . 'integracoes.php')];
    }
    if (is_file(dirname(__DIR__, 2) . '/guia/index.php')) {
        $it[] = ['id' => 'guia', 'rot' => 'Guia', 'ico' => 'guia', 'href' => admin_caminho($b, 'guia', true)];
    }
    return $it;
}

// Lateral, barra de topo e (abaixo de 1000 px) as abas das secoes, para abrir a coluna do conteudo
function casca_config(): string
{
    $de = casca_config_de();
    $atual = casca_config_atual();
    $itens = casca_config_itens($de);
    $rot = 'Configurações';
    $ico = 'config';
    foreach ($itens as $i) {
        if ($i['id'] === $atual) {
            [$rot, $ico] = [$i['rot'], $i['ico']];
        }
    }
    $u = (string)usuario_atual();
    $cab = '<a class="cas-lat-voltar" href="' . e($de) . '" aria-label="Voltar para a tela de onde você veio">' . icone('voltar', 18) . '<span>Configurações</span></a>'
        . '<div class="cas-lat-conta">' . avatar_html($u, 36, admin_base()) . '<span class="cas-lat-conta-txt"><b>' . e($u) . '</b><small>Usuário logado</small></span></div>';
    $cfg = track_config() ?? [];
    $marca = casca_marca((string)($cfg['app_nome'] ?? '') ?: 'Painel', $de, admin_base() . 'app-192.png');
    $abas = '<nav class="abas abas-nucleo abas-cfg" aria-label="Seções de Configurações"><span class="abas-titulo">Configurações</span>';
    foreach ($itens as $i) {
        $abas .= '<a href="' . e($i['href']) . '"' . ($i['id'] === $atual ? ' class="atual" aria-current="page"' : '') . '>' . icone($i['ico']) . e($i['rot']) . '</a>';
    }
    $abas .= '</nav>';
    $titulo = $atual === 'usuarios'; // conta.php, configuracoes.php e integracoes ja trazem o proprio <h1>
    return casca_lateral($itens, $atual, 'Configurações', '', $marca, ['cab' => $cab, 'busca' => 'Pesquisar configurações', 'placeholder' => 'Ir para… (Minha conta, Notificações, Integrações…)'])
        . casca_topo('Configurações', $rot, $ico, $de, '', $titulo) . $abas;
}

// ---------------------------------------------------------------- filtro unico (periodo, site, pagina, produto e fonte de trafego)
// Um botao so, no canto direito da barra de topo, que resume o que esta ativo e abre um painel com tudo junto. Os campos, os nomes, o metodo GET e as
// regras de antes (form#filtros) NAO mudam: nenhuma consulta, parametro ou gravacao nova. Sem JavaScript o painel e um <details> com os campos nativos e
// o Aplicar; o casca.js liga os atalhos de periodo, a busca das listas, a contagem, o foco preso e o Aplicar so quando algo mudou.
// $o: 'acao', 'ocultos' [nome => valor], 'periodo', 'padrao' (o periodo de partida da tela),
//     'selects' [['nome','rotulo','dica','opcoes' (valor => rotulo; '' = todos),'valor','reinicia','desabilitado']],
//     'listas'  [['nome','rotulo','dica','opcoes','marcados','todos' (rotulo do Todos),'vazio_rotulo' (no resumo),'nomes' [singular, plural],'vazio' (sem itens)]]

// Os parametros do endereco com o estado de agora (todos explicitos: o painel lembra o que nao vem no endereco, entao "limpar" tem de mandar vazio).
// $muda sobrepoe; null apaga.
function casca_filtro_q(array $o, array $muda = []): array
{
    $q = $o['ocultos'] ?? [];
    foreach ($o['selects'] ?? [] as $s) {
        $q[$s['nome']] = (string)$s['valor'];
    }
    $per = (string)$o['periodo'];
    $d = periodo_datas($per);
    $q = $q + ($d ? ['periodo' => 'personalizado', 'de' => $d[0], 'ate' => $d[1]] : ['periodo' => $per]);
    foreach ($o['listas'] ?? [] as $l) {
        $q[$l['nome']] = $l['marcados'] ?: [''];
    }
    return array_filter($muda + $q, fn($v) => $v !== null);
}

function casca_filtro_url(array $o, array $muda = []): string
{
    return (string)($o['acao'] ?? './') . '?' . http_build_query(casca_filtro_q($o, $muda));
}

// Os filtros fora do padrao, cada um com o endereco que o tira: [rotulo, href]
function casca_filtro_ativos(array $o): array
{
    $per = (string)$o['periodo'];
    $padrao = (string)($o['padrao'] ?? '7d');
    $a = [];
    if ($per !== $padrao) {
        $a[] = [periodo_rotulo($per), casca_filtro_url($o, ['periodo' => $padrao, 'de' => null, 'ate' => null])];
    }
    foreach ($o['selects'] ?? [] as $s) {
        if ((string)$s['valor'] !== '') {
            $a[] = [(string)($s['opcoes'][$s['valor']] ?? $s['valor']), casca_filtro_url($o, [$s['nome'] => ''] + (isset($s['reinicia']) ? [$s['reinicia'] => ''] : []))];
        }
    }
    foreach ($o['listas'] ?? [] as $l) {
        foreach ($l['marcados'] as $m) {
            $resto = array_values(array_diff($l['marcados'], [$m]));
            $a[] = [(string)($l['opcoes'][$m] ?? $m), casca_filtro_url($o, [$l['nome'] => $resto ?: ['']])];
        }
    }
    return $a;
}

// O resumo do botao: "Últimos 30 dias · Todos os produtos · Todas as fontes"
function casca_filtro_resumo(array $o): string
{
    $p = [periodo_rotulo((string)$o['periodo'])];
    foreach ($o['selects'] ?? [] as $s) {
        if ((string)$s['valor'] !== '') {
            $p[] = (string)($s['opcoes'][$s['valor']] ?? $s['valor']);
        }
    }
    foreach ($o['listas'] ?? [] as $l) {
        $n = count($l['marcados']);
        $p[] = $n === 0 ? $l['vazio_rotulo'] : ($n === 1 ? (string)($l['opcoes'][$l['marcados'][0]] ?? $l['marcados'][0]) : $n . ' ' . $l['nomes'][1]);
    }
    return implode(' · ', $p);
}

// "Limpar": o padrao da tela (o periodo de partida, nenhum site, pagina, produto ou fonte)
function casca_filtro_limpar_url(array $o): string
{
    $m = ['periodo' => (string)($o['padrao'] ?? '7d'), 'de' => null, 'ate' => null];
    foreach ($o['selects'] ?? [] as $s) {
        $m[$s['nome']] = '';
    }
    foreach ($o['listas'] ?? [] as $l) {
        $m[$l['nome']] = [''];
    }
    return casca_filtro_url($o, $m);
}

function casca_filtro(array $o): string
{
    $GLOBALS['casca_filtro_usado'] = true; // pagina_fim carrega o casca.js
    $per = (string)$o['periodo'];
    $datas = periodo_datas($per);
    [$d1, $d2] = $datas ?? periodo_dias($per);
    $hoje = (new DateTime('today', fuso()))->format('Y-m-d');
    if ($per === 'tudo') {
        [$d1, $d2] = [(new DateTime('today', fuso()))->modify('-29 days')->format('Y-m-d'), $hoje];
    }
    $ativos = casca_filtro_ativos($o);
    $n = count($ativos);
    $resumo = casca_filtro_resumo($o);
    $compacto = !empty($o['compacto']); // barra de uma linha (UTM): o botao e curto e as etiquetas dizem o que esta ativo
    $h = '<form class="filtros cas-filtro-form" method="get" action="' . e((string)($o['acao'] ?? './')) . '" id="filtros">';
    foreach ($o['ocultos'] ?? [] as $k => $v) {
        $h .= '<input type="hidden" name="' . e((string)$k) . '" value="' . e((string)$v) . '">';
    }
    $h .= '<details class="cas-filtro' . ($n ? ' cas-filtro-ativo' : '') . '" data-cas-filtro>'
        . '<summary class="cas-filtro-botao" aria-haspopup="dialog" aria-controls="cas-filtro-painel" aria-label="Filtros: ' . e($resumo) . ($n ? ' (' . $n . ' fora do padrão)' : '') . '" title="' . e($resumo) . '">'
        . icone('filtro', 16) . '<span class="cas-filtro-resumo">' . ($compacto ? 'Filtros' : e($resumo)) . '</span>' . ($n ? '<span class="cas-filtro-n" aria-hidden="true">' . $n . '</span>' : '')
        . '</summary>'
        . '<div class="cas-filtro-painel" id="cas-filtro-painel" role="dialog" aria-label="Filtros"><span class="cas-filtro-alca" aria-hidden="true"></span><div class="cas-filtro-corpo">';
    // Periodo: o select nativo e o que vale; o JS poe os atalhos (chips) por cima dele
    $h .= '<section class="cas-filtro-sec"><h3 class="cas-filtro-tit">' . com_info('Período', 'Vale para todas as telas e fica lembrado. Escolha um dos prontos ou, em Personalizado, o primeiro e o último dia (os dois entram). Hoje e Tudo não comparam com o período anterior.') . '</h3>'
        . '<div class="cas-filtro-presets" data-cas-presets hidden></div>'
        . '<select name="periodo" class="cas-filtro-sel" data-cas-periodo data-nativo aria-label="Período">';
    foreach (PERIODOS as $v => $rotulo) {
        $h .= '<option value="' . e($v) . '"' . ($per === $v ? ' selected' : '') . '>' . e($rotulo) . '</option>';
    }
    $h .= '<option value="personalizado"' . ($datas ? ' selected' : '') . '>De uma data a outra…</option></select>'
        . '<div class="cas-filtro-datas" data-cas-datas' . ($datas ? '' : ' hidden') . '>'
        . '<label>De <input type="date" name="de" value="' . e($d1) . '" max="' . e($hoje) . '"' . ($datas ? '' : ' disabled') . '></label>'
        . '<label>Até <input type="date" name="ate" value="' . e($d2) . '" min="' . e($d1) . '" max="' . e($hoje) . '"' . ($datas ? '' : ' disabled') . '></label>'
        . '</div><p class="cas-filtro-erro" role="alert" data-cas-erro hidden></p></section>';
    foreach ($o['selects'] ?? [] as $s) {
        $h .= '<section class="cas-filtro-sec"><label class="cas-filtro-campo"><span class="cas-filtro-tit">' . com_info($s['rotulo'], $s['dica']) . '</span>'
            . '<select name="' . e($s['nome']) . '" data-nativo class="cas-filtro-sel"' . (isset($s['reinicia']) ? ' data-reinicia="' . e($s['reinicia']) . '"' : '') . (!empty($s['desabilitado']) ? ' disabled' : '') . '>';
        foreach ($s['opcoes'] as $v => $r) {
            $h .= '<option value="' . e((string)$v) . '"' . ((string)$v === (string)$s['valor'] ? ' selected' : '') . '>' . e((string)$r) . '</option>';
        }
        $h .= '</select></label></section>';
    }
    foreach ($o['listas'] ?? [] as $l) {
        $m = array_values(array_filter($l['marcados'], fn($v) => isset($l['opcoes'][$v])));
        $h .= '<fieldset class="cas-filtro-sec cas-filtro-lista" data-cas-lista data-um="' . e($l['nomes'][0]) . '" data-varios="' . e($l['nomes'][1]) . '">'
            . '<legend class="cas-filtro-tit">' . com_info($l['rotulo'], $l['dica']) . ' <small class="cas-filtro-cont" data-cas-cont></small></legend>'
            . '<input type="hidden" name="' . e($l['nome']) . '[]" value="">'
            . '<label class="multi-todos"><input type="checkbox" data-multi-todos' . (!$m ? ' checked' : '') . '>' . e($l['todos']) . '</label>';
        foreach ($l['opcoes'] as $v => $r) {
            $h .= '<label><input type="checkbox" name="' . e($l['nome']) . '[]" value="' . e((string)$v) . '"' . (in_array((string)$v, $m, true) ? ' checked' : '') . '>' . e((string)$r) . '</label>';
        }
        if (!$l['opcoes']) {
            $h .= '<p class="suave">' . e((string)($l['vazio'] ?? 'Nada ainda.')) . '</p>';
        }
        $h .= '</fieldset>';
    }
    $h .= '</div><div class="cas-filtro-rodape"><a class="cas-filtro-limpar" href="' . e(casca_filtro_limpar_url($o)) . '">Limpar</a>'
        . '<button type="submit" class="cas-filtro-aplicar" data-cas-aplicar>Aplicar</button></div></div></details></form>';
    return $h;
}

// Os filtros ativos como etiquetas removiveis (cada uma e um link que tira aquele filtro), na mesma linha da barra de topo, antes do botao de filtro.
// So os que fogem do padrao; sem nenhum, nada. Se nao couberem na largura, o casca.js as esconde e o contador do botao fica como aviso.
function casca_filtro_chips(array $o): string
{
    $a = casca_filtro_ativos($o);
    if (!$a) {
        return '';
    }
    $h = '<div class="cas-filtro-chips" role="group" aria-label="Filtros ativos" data-cas-chips>';
    foreach ($a as [$rot, $href]) {
        $h .= '<a class="cas-chip" href="' . e($href) . '" title="Tirar este filtro">' . e($rot) . '<span class="cas-chip-x" aria-hidden="true">×</span><span class="visually-hidden"> (tirar este filtro)</span></a>';
    }
    return $h . '</div>';
}

// O filtro do UTM, com o que cada aba tem hoje (barra_topo): site e pagina onde fazem sentido, periodo, produto e, no Resumo, a fonte de trafego.
// Null fora do index.php (o filtro mora nas variaveis dele).
function casca_utm_filtro(): ?array
{
    $g = $GLOBALS;
    if (basename((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH)) !== 'index.php' && basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) !== 'index.php') {
        return null;
    }
    if (!isset($g['filtro'], $g['dominios'], $g['paginas'], $g['aba'], $g['produtosConhecidos']) || !is_array($g['filtro'])) {
        return null;
    }
    $aba = (string)$g['aba'];
    $f = $g['filtro'];
    $soPeriodo = in_array($aba, ['gestor', 'campanha', 'geral', 'organico', 'financeiro'], true);
    $comProduto = !in_array($aba, ['visitantes', 'eventos', 'financeiro'], true);
    $ocultos = ['aba' => $aba];
    if ($soPeriodo && is_string($_GET['nivel'] ?? null) && preg_match('/^[a-z]{5,10}$/', $_GET['nivel'])) {
        $ocultos['nivel'] = $_GET['nivel'];
    }
    if ($aba === 'campanha' && is_string($_GET['id'] ?? null) && preg_match('/^\d{3,25}$/', $_GET['id'])) {
        $ocultos['id'] = $_GET['id'];
    }
    $o = ['acao' => './', 'ocultos' => $ocultos, 'periodo' => (string)$f['periodo'], 'padrao' => '7d', 'selects' => [], 'listas' => []];
    if (!$soPeriodo) {
        $dom = (string)$f['dominio'];
        $o['selects'][] = ['nome' => 'dominio', 'rotulo' => 'Site', 'dica' => 'O site em que a visita aconteceu (onde o t.js do painel está instalado). Todos os sites: soma tudo. Vale para tráfego, conferência, vendas, visitantes e eventos.',
            'opcoes' => ['' => 'Todos os sites'] + array_combine($g['dominios'], $g['dominios']), 'valor' => $dom, 'reinicia' => 'pagina'];
        $o['selects'][] = ['nome' => 'pagina', 'rotulo' => 'Página', 'dica' => 'Uma página do site escolhido (/drivedeprojetos e /drivedeprojetos/ são a mesma). Escolha um site para ver as páginas dele.',
            'opcoes' => ['' => $dom === '' ? 'Escolha um site antes' : 'Todas as páginas'] + array_combine($g['paginas'], $g['paginas']), 'valor' => (string)$f['pagina'], 'desabilitado' => $dom === ''];
    }
    if ($comProduto) {
        $o['listas'][] = ['nome' => 'produto', 'rotulo' => 'Produto', 'dica' => 'Vale para as vendas de todas as telas: faturamento, vendas, CPA, ROI e lucro. Marque o produto principal (ou mais de um, como o Drive antigo e o novo) para tirar os order bumps da conta: cada order bump é outro produto na Kiwify. O gasto da Meta continua o das campanhas.',
            'opcoes' => array_combine($g['produtosConhecidos'], $g['produtosConhecidos']) ?: [], 'marcados' => array_values((array)$f['produto']), 'todos' => 'Todos (com order bump)',
            'vazio_rotulo' => 'Todos os produtos', 'nomes' => ['produto', 'produtos'], 'vazio' => 'Nenhuma venda ainda.'];
    }
    if ($aba === 'geral' && function_exists('resumo_canais')) {
        $o['listas'][] = ['nome' => 'canal', 'rotulo' => 'Fonte de tráfego', 'dica' => 'Só no Resumo. Canal da venda, pela etiqueta que a Kiwify gravou: anúncio no Instagram ou no Facebook, orgânico, direto... Marque uma ou mais; Todas soma tudo. O gasto e o funil da Meta continuam os da conta toda.',
            'opcoes' => resumo_canais(), 'marcados' => resumo_canais_marcados(), 'todos' => 'Todas as fontes', 'vazio_rotulo' => 'Todas as fontes', 'nomes' => ['fonte', 'fontes'], 'vazio' => 'Nada ainda.'];
    }
    return $o;
}
