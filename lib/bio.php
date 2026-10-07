<?php
// Painel da Bio (modulo do track): os links e os textos da pagina de links da bio do Instagram,
// com as visitas e os cliques de cada link. A tela e bio.php; a pagina publica fica no site e
// chama bio_dados() para desenhar no visual dela (ex.: engdesk/bio/index.php).
//
// - Link do proprio site ganha as etiquetas da bio (organico / instagram-bio / bio / <slug>):
//   a venda que vier dali aparece como organica da bio no painel e na UTMify.
// - Cada clique vira o evento BioClique (t.js, com data-bio="<slug>" no link); as visitas sao
//   os PageView da pagina da bio. Nada de nome, e-mail ou telefone de quem clica.
// - Endereco so https, http, mailto, tel ou caminho do proprio site ("/app/"): nunca
//   javascript: ou data:. O WhatsApp sai do numero cadastrado aqui (wa.me), com a mensagem.

require_once __DIR__ . '/util.php';

// Icones que a pagina sabe desenhar (o desenho e da pagina; aqui so o nome)
const BIO_ICONES = ['camadas' => 'Camadas (catálogo)', 'caixa' => 'Caixa (combo)', 'grade' => 'Grade (biblioteca)', 'planilha' => 'Planilha',
    'whatsapp' => 'WhatsApp', 'video' => 'Vídeo', 'estrela' => 'Estrela', 'link' => 'Link'];
// Cor do icone e da etiqueta de destaque
const BIO_CORES = ['azul' => 'Azul', 'ambar' => 'Âmbar', 'verde' => 'Verde', 'whatsapp' => 'Verde WhatsApp'];
// Textos e redes da pagina (em ajustes, com o prefixo bio_)
const BIO_CONFIG = ['nome' => '', 'selo' => '', 'chamada' => '', 'texto' => '', 'whatsapp' => '', 'whatsapp_msg' => 'Olá! Vim pela página de links e gostaria de tirar uma dúvida.',
    'instagram' => '', 'linkedin' => '', 'endereco' => ''];

// Consultas do modulo (as do painel ficam no index.php, que a pagina publica nao carrega)
function bio_consulta(string $sql, array $par = []): array
{
    $st = track_db()->prepare($sql);
    $st->execute($par);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function bio_valor(string $sql, array $par = [])
{
    $st = track_db()->prepare($sql);
    $st->execute($par);
    return $st->fetchColumn();
}

// Endereco aceito num link: https, http, mailto, tel ou caminho do proprio site
function bio_url_valida(string $url): bool
{
    if ($url === '' || strlen($url) > 500 || preg_match('/[\x00-\x20\x7F"<>\\\\]/', $url)) {
        return false;
    }
    if ($url[0] === '/') {
        return !isset($url[1]) || ($url[1] !== '/' && $url[1] !== '\\'); // "//outro.site" nao
    }
    return (bool)preg_match('~^(https?://[a-z0-9.-]+(:\d+)?([/?#][^\s]*)?|mailto:[^\s@]+@[^\s@]+|tel:\+?[0-9() -]{6,20})$~i', $url);
}

// Rede social: so https
function bio_url_rede(string $url): bool
{
    return $url === '' || (bio_url_valida($url) && stripos($url, 'https://') === 0);
}

// Slug do link (vai no utm_content e no evento): letras, numeros e hifen
function bio_slug(string $t): string
{
    $t = strtolower((string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t));
    $t = trim((string)preg_replace('/[^a-z0-9]+/', '-', $t), '-');
    return substr($t, 0, 40) ?: 'link';
}

function bio_config(): array
{
    $c = [];
    foreach (BIO_CONFIG as $k => $padrao) {
        $v = ajuste('bio_' . $k);
        $c[$k] = $v === null ? $padrao : $v;
    }
    return $c;
}

function bio_salvar_config(array $d): array
{
    $c = [];
    foreach (BIO_CONFIG as $k => $padrao) {
        $c[$k] = texto($d[$k] ?? '', $k === 'texto' ? 300 : 200);
    }
    $c['whatsapp'] = preg_replace('/\D/', '', $c['whatsapp']);
    if ($c['whatsapp'] !== '' && (strlen($c['whatsapp']) < 10 || strlen($c['whatsapp']) > 15)) {
        return [false, 'WhatsApp: o número com o código do país e o DDD, só números (ex.: 5581987654321).'];
    }
    foreach (['instagram' => 'Instagram', 'linkedin' => 'LinkedIn', 'endereco' => 'Endereço da página'] as $k => $rot) {
        if (!bio_url_rede($c[$k])) {
            return [false, $rot . ': use um endereço https:// completo.'];
        }
    }
    foreach ($c as $k => $v) {
        definir_ajuste('bio_' . $k, $v);
    }
    return [true, 'Textos e redes da página salvos.'];
}

function bio_links(bool $soAtivos = false): array
{
    return bio_consulta('SELECT * FROM bio_links' . ($soAtivos ? ' WHERE ativo = 1' : '') . ' ORDER BY ordem, id', []);
}

function bio_link(int $id): ?array
{
    $st = track_db()->prepare('SELECT * FROM bio_links WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Cria ou edita um link. [ok, mensagem]
function bio_salvar_link(array $d, ?int $id = null): array
{
    $titulo = texto($d['titulo'] ?? '', 80);
    $url = trim(texto($d['url'] ?? '', 500));
    $icone = isset(BIO_ICONES[$d['icone'] ?? '']) ? $d['icone'] : 'link';
    $cor = isset(BIO_CORES[$d['cor'] ?? '']) ? $d['cor'] : 'azul';
    if ($titulo === '') {
        return [false, 'Dê um título ao link.'];
    }
    if ($url === '' && $icone !== 'whatsapp') {
        return [false, 'Informe o endereço do link (ex.: /app/ ou https://...).'];
    }
    if ($url !== '' && !bio_url_valida($url)) {
        return [false, 'Endereço inválido: use https://, http://, mailto:, tel: ou um caminho do site como /app/.'];
    }
    $db = track_db();
    $slug = bio_slug(texto($d['slug'] ?? '', 40) ?: $titulo);
    $base = $slug;
    for ($n = 2; ; $n++) {
        $st = $db->prepare('SELECT id FROM bio_links WHERE slug = ?' . ($id ? ' AND id <> ?' : ''));
        $st->execute($id ? [$slug, $id] : [$slug]);
        if (!$st->fetchColumn()) {
            break;
        }
        $slug = substr($base, 0, 36) . '-' . $n;
    }
    $campos = [$slug, $titulo, texto($d['subtitulo'] ?? '', 160) ?: null, $icone, $cor, $url, texto($d['destaque'] ?? '', 30) ?: null,
        !empty($d['principal']) ? 1 : 0, agora_utc()];
    if ($id) {
        $st = $db->prepare('UPDATE bio_links SET slug = ?, titulo = ?, subtitulo = ?, icone = ?, cor = ?, url = ?, destaque = ?, principal = ?, atualizado_em = ? WHERE id = ?');
        $st->execute(array_merge($campos, [$id]));
        return $st->rowCount() ? [true, 'Link "' . $titulo . '" salvo.'] : [false, 'Esse link já não existe.'];
    }
    $ordem = (int)bio_valor('SELECT COALESCE(MAX(ordem), 0) + 1 FROM bio_links', []);
    $db->prepare('INSERT INTO bio_links (slug, titulo, subtitulo, icone, cor, url, destaque, principal, criado_em, ativo, ordem) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)')
        ->execute(array_merge($campos, [$ordem]));
    return [true, 'Link "' . $titulo . '" criado no fim da lista.'];
}

function bio_apagar_link(int $id): bool
{
    $st = track_db()->prepare('DELETE FROM bio_links WHERE id = ?');
    $st->execute([$id]);
    return $st->rowCount() > 0;
}

function bio_ativar_link(int $id, bool $ativo): bool
{
    $st = track_db()->prepare('UPDATE bio_links SET ativo = ?, atualizado_em = ? WHERE id = ?');
    $st->execute([$ativo ? 1 : 0, agora_utc(), $id]);
    return $st->rowCount() > 0;
}

// Sobe (-1) ou desce (+1) um link na pagina: troca de lugar com o vizinho
function bio_mover_link(int $id, int $dir): void
{
    $lista = array_column(bio_links(), 'id');
    $i = array_search($id, array_map('intval', $lista), true);
    $j = $i === false ? false : $i + ($dir < 0 ? -1 : 1);
    if ($i === false || $j < 0 || $j >= count($lista)) {
        return;
    }
    [$lista[$i], $lista[$j]] = [$lista[$j], $lista[$i]];
    $db = track_db();
    $st = $db->prepare('UPDATE bio_links SET ordem = ? WHERE id = ?');
    foreach ($lista as $n => $lid) {
        $st->execute([$n + 1, $lid]);
    }
}

// Primeira vez: a pagina do site passa os links e os textos que ela ja tinha, para o painel
// comecar igual a pagina (so quando nao ha nenhum link e nunca foi feito)
function bio_semear(array $links, array $config): void
{
    if (ajuste('bio_semeado') !== null || (int)bio_valor('SELECT COUNT(*) FROM bio_links', [])) {
        return;
    }
    definir_ajuste('bio_semeado', agora_utc());
    foreach ($links as $l) {
        bio_salvar_link($l);
    }
    foreach ($config as $k => $v) {
        if (isset(BIO_CONFIG[$k]) && ajuste('bio_' . $k) === null) {
            definir_ajuste('bio_' . $k, (string)$v);
        }
    }
}

// Link do WhatsApp com a mensagem (vazio sem numero)
function bio_whatsapp_url(string $numero, string $msg): string
{
    $numero = preg_replace('/\D/', '', $numero);
    return $numero === '' ? '' : 'https://wa.me/' . $numero . ($msg !== '' ? '?text=' . rawurlencode($msg) : '');
}

// Endereco final do link: o do proprio site ganha as etiquetas da bio (sem apagar as que ele ja
// tem); os de fora ficam como estao. $dominio: o do site (ex.: engdesk.pro)
function bio_url_final(string $url, string $slug, string $dominio): string
{
    $proprio = $url !== '' && ($url[0] === '/'
        || ($dominio !== '' && preg_match('~^https?://(www\.)?' . preg_quote($dominio, '~') . '([/:?#]|$)~i', $url)));
    if (!$proprio) {
        return $url;
    }
    $partes = explode('#', $url, 2);
    $q = [];
    $base = $partes[0];
    if (($p = strpos($base, '?')) !== false) {
        parse_str(substr($base, $p + 1), $q);
        $base = substr($base, 0, $p);
    }
    $q += ['utm_source' => 'organico', 'utm_medium' => 'instagram-bio', 'utm_campaign' => 'bio', 'utm_content' => $slug];
    return $base . '?' . http_build_query($q) . (isset($partes[1]) ? '#' . $partes[1] : '');
}

// Tudo o que a pagina publica precisa, ja pronto: textos, redes, WhatsApp e os links ativos
function bio_dados(string $dominio = ''): array
{
    $c = bio_config();
    $whats = bio_whatsapp_url($c['whatsapp'], $c['whatsapp_msg']);
    $links = [];
    foreach (bio_links(true) as $l) {
        $doWhats = (string)$l['url'] === '' && $l['icone'] === 'whatsapp';
        $url = $doWhats ? $whats : bio_url_final((string)$l['url'], (string)$l['slug'], $dominio);
        if ($url === '') {
            continue; // WhatsApp sem numero cadastrado: o link nao aparece
        }
        // De fora (abre em outra aba): WhatsApp e endereco de outro site (o do proprio site ganhou etiquetas)
        $externo = $doWhats || ((bool)preg_match('~^https?://~i', $url) && $url === (string)$l['url']);
        $links[] = ['slug' => $l['slug'], 'titulo' => $l['titulo'], 'subtitulo' => (string)$l['subtitulo'], 'icone' => $l['icone'], 'cor' => $l['cor'],
            'destaque' => (string)$l['destaque'], 'principal' => (bool)$l['principal'], 'url' => $url, 'externo' => $externo];
    }
    return ['config' => $c, 'whatsapp' => $whats, 'links' => $links];
}

// Caminho da pagina da bio para contar as visitas (do endereco cadastrado; padrao /bio)
function bio_caminho(array $c): array
{
    $p = parse_url($c['endereco'] ?: '/bio');
    return [strtolower((string)($p['host'] ?? '')), rtrim((string)($p['path'] ?? '/bio'), '/') ?: '/'];
}

// Visitas e cliques no periodo: [visitas, pessoas, pessoas que clicaram, cliques por slug
// [n, pessoas], serie dos ultimos 7 dias por slug]
function bio_numeros(string $de, string $ate): array
{
    [$host, $pagina] = bio_caminho(bio_config());
    $condDom = $host !== '' ? ' AND (dominio = :dom OR dominio = :www)' : '';
    $par = [':de' => $de, ':ate' => $ate] + ($host !== '' ? [':dom' => $host, ':www' => 'www.' . $host] : []);
    $v = bio_consulta("SELECT COUNT(*) AS n, COUNT(DISTINCT visitante) AS pessoas FROM eventos WHERE nome = 'PageView' AND pagina = :pag AND em >= :de AND em < :ate$condDom", $par + [':pag' => $pagina])[0];
    $porSlug = [];
    foreach (bio_consulta("SELECT detalhe, COUNT(*) AS n, COUNT(DISTINCT visitante) AS pessoas FROM eventos WHERE nome = 'BioClique' AND em >= :de AND em < :ate GROUP BY detalhe", [':de' => $de, ':ate' => $ate]) as $c) {
        $porSlug[(string)$c['detalhe']] = [(int)$c['n'], (int)$c['pessoas']];
    }
    $clicaram = (int)bio_valor("SELECT COUNT(DISTINCT visitante) FROM eventos WHERE nome = 'BioClique' AND em >= :de AND em < :ate", [':de' => $de, ':ate' => $ate]);
    // Ultimos 7 dias (no fuso do painel), um ponto por dia
    $dias = [];
    for ($i = 6; $i >= 0; $i--) {
        $dias[] = (new DateTime('today', fuso()))->modify("-$i day")->format('Y-m-d');
    }
    $desde = (new DateTime($dias[0], fuso()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $serie = [];
    foreach (bio_consulta("SELECT detalhe, em FROM eventos WHERE nome = 'BioClique' AND em >= ?", [$desde]) as $c) {
        $dia = (new DateTime($c['em'], new DateTimeZone('UTC')))->setTimezone(fuso())->format('Y-m-d');
        $serie[(string)$c['detalhe']][$dia] = ($serie[(string)$c['detalhe']][$dia] ?? 0) + 1;
    }
    return [(int)$v['n'], (int)$v['pessoas'], $clicaram, $porSlug, $serie, $dias];
}
