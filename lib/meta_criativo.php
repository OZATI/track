<?php
// O criativo de cada anuncio, para o gestor (nivel Anuncios): a miniatura da linha e a previa do popup.
//
// Miniatura: a busca na Meta (lib/meta_sync.php) ja lista os anuncios; a mesma listagem traz o criativo
// (id, tipo e a miniatura de 192 px), sem chamada a mais. O servidor baixa a imagem so de anuncio novo ou
// que trocou de criativo, no maximo META_CRIATIVOS_POR_BUSCA por busca e dentro de um prazo, aceita so
// JPEG, PNG ou WebP do CDN da Meta, com tamanho maximo, e regrava pelo GD (sai sem os metadados). O
// arquivo fica na pasta de dados, fora do site, com o nome so do id numerico do anuncio, e sai por
// meta-criativo.php para quem entrou e tem o acesso UTM. Assim a CSP do painel continua com img-src 'self'
// data:, o endereco da Meta que vence nao importa e quem abre o painel nao fala com o CDN da Meta.
//
// Previa: meta-previa.php chama a Meta so quando o popup abre (o status, os textos do criativo e a previa
// oficial, /{anuncio}/previews), com o mesmo token de leitura e pela mesma protecao contra bloqueio de
// lib/meta_api.php, e guarda a resposta por META_PREVIA_CACHE segundos, para ir e voltar pelas setas nao
// repetir chamadas. A previa da Meta e um iframe de https://www.facebook.com: daqui sai so o endereco dele,
// conferido, nunca o HTML da Meta. Se a Meta falhar ou o iframe nao for aceito, a tela usa a reserva: a
// nossa imagem guardada em tamanho maior, os textos do criativo (texto puro) e o link do Gerenciador.
// Nada de dado pessoal: nem comentarios, nem quem interagiu.

require_once __DIR__ . '/meta_api.php';

const META_CRIATIVO_LADO = 192;        // miniatura da linha (40 px na tela, 2x, e a previa de 192)
const META_CRIATIVO_GRANDE = 600;      // a imagem da reserva do popup
const META_CRIATIVO_BYTES = 8388608;   // 8 MB: maior que isso nao e miniatura, para no meio
const META_CRIATIVO_PIXELS = 12000000; // teto de pixels para abrir (12 MP); menor se a memoria do PHP nao der
const META_CRIATIVO_FALHAS = 3;        // falhou 3 vezes com o mesmo criativo: para de tentar ate ele mudar
const META_PREVIA_CACHE = 600;         // 10 minutos

// Campos do criativo na listagem de anuncios (lib/meta_sync.php)
const META_CRIATIVO_CAMPOS = 'creative.thumbnail_width(192).thumbnail_height(192){id,thumbnail_url,image_url,object_type,video_id}';

// Formatos da previa: o nome na tela e o ad_format da Meta (o primeiro; o feed tenta o do Facebook se o
// do Instagram nao vier)
const META_PREVIA_FORMATOS = [
    'feed' => ['Feed', ['INSTAGRAM_STANDARD', 'MOBILE_FEED_STANDARD']],
    'stories' => ['Stories', ['INSTAGRAM_STORY']],
    'reels' => ['Reels', ['INSTAGRAM_REELS']],
];

// O que a previa pede do anuncio: o status e o criativo (textos e a imagem maior)
// So os campos de texto do criativo (minimo): nada de pagina, links ou quem interagiu
const META_PREVIA_CAMPOS = 'effective_status,creative.thumbnail_width(600).thumbnail_height(600){id,object_type,video_id,thumbnail_url,image_url,title,body,call_to_action_type,'
    . 'object_story_spec{link_data{message,name,description,call_to_action,child_attachments{name}},video_data{message,title,link_description,call_to_action}},'
    . 'asset_feed_spec{bodies,titles,descriptions,call_to_action_types}}';
const META_PREVIA_CAMPOS_SIMPLES = 'effective_status,creative{id,object_type,video_id,thumbnail_url,image_url,title,body,call_to_action_type,object_story_spec,asset_feed_spec}';

// So os numeros de um id da Meta (o mesmo que meta_so_numeros(), sem depender de lib/meta_sync.php)
function meta_criativo_num($v): ?string
{
    $n = preg_replace('/\D/', '', is_scalar($v) ? (string)$v : '');
    return $n === '' ? null : $n;
}

function meta_criativos_por_busca(): int
{
    return max(1, (int)(getenv('TRACK_META_CRIATIVOS_POR_BUSCA') ?: 24));
}

function meta_criativo_prazo(): int
{
    return 12;
}

// Quantos pixels uma imagem pode ter para o GD abrir sem estourar a memoria do PHP: cerca de 10 bytes por
// pixel (a imagem aberta e a copia), no maximo META_CRIATIVO_PIXELS (seguranca, 13/11, A1)
function meta_criativo_pixels_max(): int
{
    $limite = trim((string)ini_get('memory_limit'));
    if ($limite === '' || $limite === '-1') {
        return META_CRIATIVO_PIXELS;
    }
    $n = (int)$limite;
    $mult = ['k' => 1024, 'm' => 1048576, 'g' => 1073741824][strtolower(substr($limite, -1))] ?? 1;
    $livre = $n * $mult - memory_get_usage(true);
    return max(0, min(META_CRIATIVO_PIXELS, intdiv($livre, 10)));
}

// Arquivo da miniatura (ou da imagem maior) de um anuncio. '' se o id nao for so numeros.
function meta_criativo_arquivo(string $anuncio, bool $grande = false): string
{
    if (!preg_match('/^\d{6,25}\z/', $anuncio)) {
        return '';
    }
    return track_pasta_dados() . DIRECTORY_SEPARATOR . 'meta-criativo' . DIRECTORY_SEPARATOR . $anuncio . ($grande ? '-g' : '') . '.jpg';
}

// So imagem do CDN da Meta (Facebook e Instagram), em HTTPS. O endereco vem da API, mas o servidor nao
// busca nada fora disso. Em teste, TRACK_META_MIDIA_HOST libera o servidor falso local.
function meta_url_midia_ok(string $url): bool
{
    $p = parse_url($url);
    if (!is_array($p) || isset($p['user']) || isset($p['pass'])) {
        return false;
    }
    $host = strtolower((string)($p['host'] ?? ''));
    $teste = getenv('TRACK_META_MIDIA_HOST');
    if ($teste && ($p['scheme'] ?? '') === 'http' && $host . (isset($p['port']) ? ':' . $p['port'] : '') === $teste) {
        return true;
    }
    return ($p['scheme'] ?? '') === 'https' && !isset($p['port']) && (bool)preg_match('/(^|\.)(fbcdn\.net|cdninstagram\.com)$/', $host);
}

// Tipo do criativo para a tela: video, carrossel ou imagem
function meta_criativo_tipo(array $c): string
{
    if (!empty($c['video_id']) || strtoupper((string)($c['object_type'] ?? '')) === 'VIDEO' || !empty($c['object_story_spec']['video_data'])) {
        return 'video';
    }
    $filhos = $c['object_story_spec']['link_data']['child_attachments'] ?? null;
    if (is_array($filhos) && count($filhos) > 1) {
        return 'carrossel';
    }
    return 'imagem';
}

// Baixa a imagem, confere que e JPEG, PNG ou WebP, reduz para caber em $lado x $lado e regrava como JPEG
// pelo GD (sem os metadados). Devolve true ou o motivo da falha (curto, sem o endereco).
function meta_criativo_baixar(string $anuncio, string $url, bool $grande = false)
{
    $arq = meta_criativo_arquivo($anuncio, $grande);
    if ($arq === '') {
        return 'anúncio sem número';
    }
    if (!meta_url_midia_ok($url)) {
        return 'imagem fora do CDN da Meta';
    }
    if (!function_exists('imagecreatefromstring')) {
        return 'o PHP do servidor está sem a biblioteca de imagem (GD)';
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => $grande ? 8 : 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        CURLOPT_NOPROGRESS => false,
        CURLOPT_XFERINFOFUNCTION => fn($c, $total, $baixado) => ($total > META_CRIATIVO_BYTES || $baixado > META_CRIATIVO_BYTES) ? 1 : 0,
    ]);
    $bin = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if (!is_string($bin)) {
        return 'download interrompido (sem conexão, fora do prazo ou acima de 8 MB)';
    }
    if ($status !== 200) {
        return 'o CDN da Meta respondeu ' . $status;
    }
    $info = @getimagesizefromstring($bin);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        return 'o arquivo não é uma imagem JPEG, PNG ou WebP';
    }
    if ($info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > meta_criativo_pixels_max()) {
        return 'imagem grande demais (' . $info[0] . 'x' . $info[1] . ')';
    }
    $im = @imagecreatefromstring($bin);
    unset($bin);
    if (!$im) {
        return 'o PHP não conseguiu abrir a imagem';
    }
    $lado = $grande ? META_CRIATIVO_GRANDE : META_CRIATIVO_LADO;
    $w = imagesx($im);
    $h = imagesy($im);
    $escala = min(1, $lado / max($w, $h));
    $nw = max(1, (int)round($w * $escala));
    $nh = max(1, (int)round($h * $escala));
    $novo = imagecreatetruecolor($nw, $nh);
    // PNG ou WebP com transparencia: fundo branco, como a Meta mostra
    imagefill($novo, 0, 0, imagecolorallocate($novo, 255, 255, 255));
    imagecopyresampled($novo, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($im);
    if (!is_dir(dirname($arq)) && !@mkdir(dirname($arq), 0750, true)) {
        imagedestroy($novo);
        return 'sem permissão para criar a pasta das miniaturas';
    }
    $tmp = $arq . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $ok = imagejpeg($novo, $tmp, 82);
    imagedestroy($novo);
    if (!$ok || !@rename($tmp, $arq)) {
        @unlink($tmp);
        return 'não foi possível gravar a miniatura';
    }
    return true;
}

// Grava o criativo que veio na listagem de um anuncio. Devolve o endereco da miniatura quando ela precisa
// ser baixada (anuncio novo, criativo trocado ou arquivo que sumiu), ou null.
function meta_criativo_registrar(PDO $db, string $anuncio, $criativo): ?string
{
    if (!is_array($criativo) || !($cid = meta_criativo_num($criativo['id'] ?? ''))) {
        return null;
    }
    $st = $db->prepare('SELECT criativo_id, criativo_tipo, criativo_baixado, criativo_falhas FROM meta_objetos WHERE id = ?');
    $st->execute([$anuncio]);
    $antes = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $st->closeCursor();
    $tipo = meta_criativo_tipo($criativo);
    if (($antes['criativo_id'] ?? null) !== $cid) {
        $db->prepare('UPDATE meta_objetos SET criativo_id = ?, criativo_tipo = ?, criativo_baixado = NULL, criativo_grande = NULL, criativo_falhas = 0 WHERE id = ?')
            ->execute([$cid, $tipo, $anuncio]);
        $antes = ['criativo_baixado' => null, 'criativo_falhas' => 0];
    } elseif ($tipo === 'video' && ($antes['criativo_tipo'] ?? '') !== 'video') {
        // O carrossel so se descobre ao abrir a previa (lib abaixo); a listagem nao desfaz isso
        $db->prepare('UPDATE meta_objetos SET criativo_tipo = ? WHERE id = ?')->execute([$tipo, $anuncio]);
    }
    $url = (string)($criativo['thumbnail_url'] ?? '') ?: (string)($criativo['image_url'] ?? '');
    $falta = ($antes['criativo_baixado'] ?? null) !== $cid || !is_file(meta_criativo_arquivo($anuncio));
    return $url !== '' && $falta && (int)($antes['criativo_falhas'] ?? 0) < META_CRIATIVO_FALHAS ? $url : null;
}

// Baixa as miniaturas que faltam, ate o limite por busca e o prazo. Devolve quantas baixou.
function meta_criativos_baixar(PDO $db, array $fila): int
{
    // Primeiro o que ainda nao falhou: uma imagem ruim nao toma a vez das outras em toda busca
    $falhas = $criativoDe = [];
    if ($fila) {
        $ids = array_map('strval', array_keys($fila));
        $st = $db->prepare('SELECT id, criativo_falhas, criativo_id FROM meta_objetos WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $falhas[$l['id']] = (int)$l['criativo_falhas'];
            $criativoDe[$l['id']] = (string)$l['criativo_id'];
        }
        $st->closeCursor();
    }
    $ordem = array_keys($fila);
    $pos = array_flip($ordem);
    usort($ordem, fn($a, $b) => ((int)($falhas[$a] ?? 0) <=> (int)($falhas[$b] ?? 0)) ?: ($pos[$a] <=> $pos[$b]));
    $prazo = microtime(true) + meta_criativo_prazo();
    $tentadas = $baixadas = 0;
    // Marca baixado so se o criativo ainda for o que entrou na fila: outra busca pode ter trocado no meio
    $ok = $db->prepare('UPDATE meta_objetos SET criativo_baixado = criativo_id, criativo_falhas = 0 WHERE id = ? AND criativo_id = ?');
    $falhou = $db->prepare('UPDATE meta_objetos SET criativo_falhas = criativo_falhas + 1 WHERE id = ?');
    foreach ($ordem as $anuncio) {
        $url = $fila[$anuncio];
        if ($tentadas >= meta_criativos_por_busca() || microtime(true) >= $prazo) {
            break;
        }
        $tentadas++;
        // A tentativa conta como falha ANTES do download: se o PHP cair no meio (memoria), ela ja esta contada e,
        // depois de META_CRIATIVO_FALHAS, a imagem nao e tentada de novo. O sucesso zera.
        $falhou->execute([(string)$anuncio]);
        $r = meta_criativo_baixar((string)$anuncio, $url);
        if ($r === true) {
            $ok->execute([(string)$anuncio, $criativoDe[$anuncio] ?? '']);
            $baixadas++;
        } else {
            definir_ajuste('meta_criativo_erro', $r);
        }
    }
    if ($baixadas && $baixadas === $tentadas) {
        definir_ajuste('meta_criativo_erro', null);
    }
    return $baixadas;
}

// O criativo de uma linha do gestor (a linha do meta_objetos de um anuncio):
//   ['src' => endereco local da miniatura ou null, 'tipo' => 'imagem'|'video'|'carrossel'], ou null.
// src null com tipo: a Meta mandou o criativo, mas a miniatura nao veio (a tela mostra o quadrado neutro
// com o tipo). null: nada se sabe do criativo (a tela mostra o quadrado neutro sem o tipo). O v= muda
// quando o criativo muda (a imagem fica 7 dias no cache do navegador).
function meta_criativo_linha(?array $obj): ?array
{
    if (!$obj || ($obj['nivel'] ?? '') !== 'ad' || empty($obj['criativo_id'])) {
        return null;
    }
    $id = (string)$obj['id'];
    $tem = ($obj['criativo_baixado'] ?? null) === $obj['criativo_id'] && is_file(meta_criativo_arquivo($id));
    $tipo = in_array($obj['criativo_tipo'] ?? '', ['imagem', 'video', 'carrossel'], true) ? $obj['criativo_tipo'] : 'imagem';
    return ['src' => $tem ? 'meta-criativo.php?id=' . $id . '&v=' . $obj['criativo_id'] : null, 'tipo' => $tipo];
}

// ---------------------------------------------------------------- previa do popup

// Selo do status, pelo effective_status da Meta (o anuncio pode estar ligado com o conjunto pausado)
function meta_previa_status(?string $efetivo): array
{
    $s = strtoupper((string)$efetivo);
    $mapa = [
        'ACTIVE' => ['ativo', 'Ativo'],
        'PAUSED' => ['pausado', 'Pausado'],
        'ADSET_PAUSED' => ['conjunto_pausado', 'Conjunto pausado'],
        'CAMPAIGN_PAUSED' => ['campanha_pausada', 'Campanha pausada'],
        'PENDING_REVIEW' => ['em_analise', 'Em análise'],
        'IN_PROCESS' => ['em_analise', 'Em análise'],
        'PREAPPROVED' => ['em_analise', 'Em análise'],
        'DISAPPROVED' => ['reprovado', 'Reprovado'],
        'WITH_ISSUES' => ['com_problema', 'Com problema'],
        'PENDING_BILLING_INFO' => ['com_problema', 'Com problema'],
        'ARCHIVED' => ['arquivado', 'Arquivado'],
        'DELETED' => ['arquivado', 'Arquivado'],
    ];
    [$selo, $texto] = $mapa[$s] ?? ['com_problema', 'Com problema'];
    return ['selo' => $selo, 'texto' => $texto];
}

// Rotulo do botao (call_to_action da Meta), em portugues
function meta_previa_botao(string $cta): string
{
    $mapa = [
        'LEARN_MORE' => 'Saiba mais', 'SHOP_NOW' => 'Comprar agora', 'BUY_NOW' => 'Comprar agora', 'SIGN_UP' => 'Cadastre-se',
        'SUBSCRIBE' => 'Assinar', 'DOWNLOAD' => 'Baixar', 'GET_OFFER' => 'Obter oferta', 'ORDER_NOW' => 'Pedir agora',
        'CONTACT_US' => 'Fale conosco', 'SEND_MESSAGE' => 'Enviar mensagem', 'WHATSAPP_MESSAGE' => 'Enviar mensagem pelo WhatsApp',
        'MESSAGE_PAGE' => 'Enviar mensagem', 'APPLY_NOW' => 'Inscreva-se', 'BOOK_TRAVEL' => 'Reservar', 'WATCH_MORE' => 'Assistir mais',
        'SEE_MORE' => 'Ver mais', 'GET_QUOTE' => 'Pedir orçamento', 'NO_BUTTON' => '',
    ];
    $cta = strtoupper(trim($cta));
    if ($cta === '') {
        return '';
    }
    return $mapa[$cta] ?? texto(ucfirst(strtolower(str_replace('_', ' ', $cta))), 40);
}

// Os textos do criativo (texto principal, titulo, descricao e botao), em texto puro. Le os campos do
// criativo, do object_story_spec (link ou video) e do asset_feed_spec (criativo dinamico), nessa ordem.
function meta_previa_textos(array $c): array
{
    $oss = is_array($c['object_story_spec'] ?? null) ? $c['object_story_spec'] : [];
    $link = is_array($oss['link_data'] ?? null) ? $oss['link_data'] : [];
    $video = is_array($oss['video_data'] ?? null) ? $oss['video_data'] : [];
    $afs = is_array($c['asset_feed_spec'] ?? null) ? $c['asset_feed_spec'] : [];
    $primeiro = function (...$vals): string {
        foreach ($vals as $v) {
            if (is_array($v)) {
                $v = $v[0]['text'] ?? ($v[0] ?? '');
            }
            if (is_string($v) && trim($v) !== '') {
                return $v;
            }
        }
        return '';
    };
    // Texto principal pode ter quebra de linha: guarda as quebras, tira o resto dos caracteres de controle
    $corpo = $primeiro($c['body'] ?? null, $link['message'] ?? null, $video['message'] ?? null, $afs['bodies'] ?? null);
    $corpo = mb_substr(trim(preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', ' ', str_replace("\r", '', $corpo)) ?? ''), 0, 2200);
    return [
        'texto' => $corpo,
        'titulo' => texto($primeiro($c['title'] ?? null, $link['name'] ?? null, $video['title'] ?? null, $afs['titles'] ?? null), 300),
        'descricao' => texto($primeiro($link['description'] ?? null, $video['link_description'] ?? null, $afs['descriptions'] ?? null), 300),
        'botao' => meta_previa_botao($primeiro($c['call_to_action_type'] ?? null, $link['call_to_action']['type'] ?? null,
            $video['call_to_action']['type'] ?? null, $afs['call_to_action_types'] ?? null)),
    ];
}

// Endereco do iframe da previa, tirado do HTML que a Meta devolve. So o endereco sai daqui, e so se for o
// da previa da Meta (https://www.facebook.com/ads/api/preview_iframe.php?...), o unico que a CSP
// (frame-src) e a tela aceitam. Devolve ['src', 'tam' => [largura, altura]] ou null.
function meta_previa_iframe($html): ?array
{
    if (!is_string($html) || !preg_match('/<iframe\b[^>]*?\bsrc\s*=\s*"([^"]{20,3000})"/i', $html, $m)) {
        return null;
    }
    $src = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (!preg_match('~^https://www\.facebook\.com/ads/api/preview_iframe\.php\?[A-Za-z0-9_\-.%&=+]+\z~', $src)) {
        return null;
    }
    $tam = null;
    if (preg_match('/\bwidth\s*=\s*"(\d{2,4})"/i', $html, $w) && preg_match('/\bheight\s*=\s*"(\d{2,4})"/i', $html, $h)) {
        $tam = [min(1000, max(200, (int)$w[1])), min(1400, max(200, (int)$h[1]))];
    }
    return ['src' => $src, 'tam' => $tam];
}

function meta_previa_cache_ler(string $anuncio, string $chave): ?array
{
    $st = track_db()->prepare('SELECT dados FROM meta_previas WHERE anuncio_id = ? AND chave = ? AND em >= ?');
    $st->execute([$anuncio, $chave, time() - META_PREVIA_CACHE]);
    $d = $st->fetchColumn();
    $d = is_string($d) ? json_decode($d, true) : null;
    return is_array($d) ? $d : null;
}

function meta_previa_cache_gravar(string $anuncio, string $chave, array $dados): void
{
    $db = track_db();
    $db->prepare('INSERT OR REPLACE INTO meta_previas (anuncio_id, chave, dados, em) VALUES (?, ?, ?, ?)')
        ->execute([$anuncio, $chave, json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), time()]);
    // Faxina de vez em quando: o que passou de um dia sai
    if (random_int(1, 50) === 1) {
        $db->prepare('DELETE FROM meta_previas WHERE em < ?')->execute([time() - 86400]);
    }
}

// Detalhes do anuncio que a previa usa (o que vai para o cache): status, tipo, textos e a imagem maior
// $com600: o pedido levou o thumbnail_width(600); entao a miniatura ja vem com 600 px e e ela que se baixa (o
// image_url e o original do anunciante, que pode ser enorme). Sem isso, o image_url, se houver.
function meta_previa_detalhes(array $c, bool $com600 = true): array
{
    $criativo = is_array($c['creative'] ?? null) ? $c['creative'] : [];
    $mini = (string)($criativo['thumbnail_url'] ?? '');
    $orig = (string)($criativo['image_url'] ?? '');
    $img = $com600 ? ($mini ?: $orig) : ($orig ?: $mini);
    return [
        'status' => texto($c['effective_status'] ?? '', 40),
        'criativo_id' => meta_criativo_num($criativo['id'] ?? '') ?? '',
        'tipo' => $criativo ? meta_criativo_tipo($criativo) : '',
        'textos' => meta_previa_textos($criativo),
        'img' => meta_url_midia_ok($img) ? $img : '',
    ];
}

// A previa so chama a Meta se sobrar folga no limite por minuto de lib/meta_api.php: a busca e o ligar e
// pausar do gestor usam o mesmo limite, e o popup nao pode tirar a vez deles (seguranca, 13/11, A2)
function meta_previa_orcamento_ok(): bool
{
    $limite = meta_api_limite_minuto();
    return meta_api_uso(60) < $limite - min(10, intdiv($limite, 3));
}

function meta_previa_cache_apagar(string $anuncio, string $chave): void
{
    track_db()->prepare('DELETE FROM meta_previas WHERE anuncio_id = ? AND chave = ?')->execute([$anuncio, $chave]);
}

// Previa de um formato pela rota /{anuncio}/previews (o modo separado). Devolve o iframe, ['falhou' => true],
// ou null quando nao chamou (pausa, limite ou sem folga): nao guarda, tenta de novo depois.
function meta_previa_formato(string $anuncio, string $formato, string $token): ?array
{
    foreach (META_PREVIA_FORMATOS[$formato][1] as $adFormat) {
        if (!meta_previa_orcamento_ok()) {
            return null;
        }
        [$st, $c] = meta_api_get('/' . $anuncio . '/previews', ['ad_format' => $adFormat], $token);
        if ($st === META_ADIADA) {
            return null;
        }
        if ($st === 200 && ($f = meta_previa_iframe($c['data'][0]['body'] ?? null))) {
            return $f;
        }
    }
    return ['falhou' => true];
}

// A resposta inteira de meta-previa.php para um anuncio (linha do meta_objetos) e um formato ('' = o
// primeiro que a Meta tiver). $podeChamar: o limite de aberturas por usuario deixa chamar a Meta.
function meta_previa(array $obj, string $formato, bool $podeChamar = true): array
{
    $id = (string)$obj['id'];
    $db = track_db();
    $k = meta_api_chave();
    $aviso = null;
    $det = meta_previa_cache_ler($id, '');
    $ocupada = 'A Meta está ocupada com a busca do painel: mostrando a reserva por um minuto.';

    if (!$det && !$k) {
        $aviso = 'A Meta não está conectada: mostrando a reserva.';
    } elseif (!$det && meta_previa_cache_ler($id, 'detalhes_falhou')) {
        $aviso = 'A Meta não mostrou este anúncio agora: mostrando a reserva.';
    } elseif (!$det && !$podeChamar) {
        $aviso = 'Muitas prévias abertas em pouco tempo: mostrando a reserva por alguns minutos.';
    } elseif (!$det && !meta_previa_orcamento_ok()) {
        $aviso = $ocupada;
    } elseif (!$det) {
        // Modo junto: uma chamada traz o status, o criativo e as previas dos tres formatos (apelidos com
        // .as()). Se a Meta recusar o pedido (erro de campo, 100) e os detalhes sozinhos passarem, o problema
        // e o pedido junto: o modo separado vale por um dia (os detalhes numa chamada e cada formato na sua,
        // so quando pedido). Erro de conexao ou da Meta nao troca o modo.
        $separado = (int)(ajuste('meta_previa_separado_ate') ?? 0) > time();
        $tentarSeparado = $separado;
        $c = null;
        $com600 = true;
        if (!$separado) {
            $campos = META_PREVIA_CAMPOS;
            foreach (META_PREVIA_FORMATOS as $f => [, $formatosMeta]) {
                $campos .= ',previews.ad_format(' . $formatosMeta[0] . ').as(previa_' . $f . '){body}';
            }
            [$st, $c] = meta_api_get('/' . $id, ['fields' => $campos], $k['token']);
            if ($st === 200 && is_array($c)) {
                foreach (META_PREVIA_FORMATOS as $f => $x) {
                    $p = meta_previa_iframe($c['previa_' . $f]['data'][0]['body'] ?? null);
                    meta_previa_cache_gravar($id, $f, $p ?: ['falhou' => true]);
                }
            } elseif ($st === META_ADIADA) {
                $aviso = meta_api_mensagem($c) ?: 'A Meta pediu uma pausa: mostrando a reserva.';
                $c = null;
            } elseif ($st === 400 && (int)($c['error']['code'] ?? 0) === 100) {
                $tentarSeparado = true;
                $c = null;
            } else {
                $aviso = 'A Meta não respondeu a prévia agora: mostrando a reserva.';
                $c = null;
            }
        }
        if ($tentarSeparado && $aviso === null) {
            $st = META_ADIADA;
            if (meta_previa_orcamento_ok()) {
                [$st, $c] = meta_api_get('/' . $id, ['fields' => META_PREVIA_CAMPOS], $k['token']);
                if ($st === 400 && meta_previa_orcamento_ok()) {
                    [$st, $c] = meta_api_get('/' . $id, ['fields' => META_PREVIA_CAMPOS_SIMPLES], $k['token']);
                    $com600 = false;
                }
            } else {
                $c = ['error' => ['message' => $ocupada]];
            }
            if ($st === 200 && is_array($c)) {
                if (!$separado) {
                    definir_ajuste('meta_previa_separado_ate', (string)(time() + 86400));
                    $separado = true;
                }
            } else {
                if ($st === META_ADIADA) {
                    $aviso = meta_api_mensagem($c) ?: 'A Meta pediu uma pausa: mostrando a reserva.';
                } else {
                    // Anuncio que a Meta nao mostra (apagado, sem acesso): guarda a falha por 10 minutos
                    if ($st === 400) {
                        meta_previa_cache_gravar($id, 'detalhes_falhou', ['em' => time()]);
                    }
                    $aviso = 'A Meta não mostrou este anúncio agora: mostrando a reserva.';
                }
                $c = null;
            }
        }
        if ($c) {
            $det = meta_previa_detalhes($c, $com600);
            $det['separado'] = $separado;
            meta_previa_cache_gravar($id, '', $det);
            // O status de agora vale tambem para o gestor; o carrossel so se descobre aqui
            if ($det['status'] !== '') {
                $db->prepare("UPDATE meta_objetos SET status_efetivo = ? WHERE id = ? AND nivel = 'ad'")->execute([$det['status'], $id]);
            }
            if ($det['tipo'] !== '' && $det['criativo_id'] === (string)($obj['criativo_id'] ?? '')) {
                $db->prepare("UPDATE meta_objetos SET criativo_tipo = ? WHERE id = ? AND nivel = 'ad'")->execute([$det['tipo'], $id]);
                $obj['criativo_tipo'] = $det['tipo'];
            }
        }
    }

    // Os formatos: os que a Meta devolveu. No modo separado, o que ainda nao foi pedido tambem aparece
    // (so se sabe ao pedir); o que falhou sai.
    $previas = [];
    foreach (array_keys(META_PREVIA_FORMATOS) as $f) {
        $previas[$f] = meta_previa_cache_ler($id, $f);
    }
    $separado = !empty($det['separado']);
    if ($formato === '') {
        foreach ($previas as $f => $p) {
            if ($p && empty($p['falhou'])) {
                $formato = $f;
                break;
            }
        }
        $formato = $formato ?: 'feed';
    }
    if ($det && $separado && $previas[$formato] === null && $k && $aviso === null && $podeChamar) {
        $p = meta_previa_formato($id, $formato, $k['token']);
        if ($p !== null) {
            meta_previa_cache_gravar($id, $formato, $p);
            $previas[$formato] = $p;
        } else {
            $aviso = 'A Meta pediu uma pausa: mostrando a reserva.';
        }
    }
    $formatos = [];
    foreach (META_PREVIA_FORMATOS as $f => [$nome]) {
        $p = $previas[$f];
        if (($p && empty($p['falhou'])) || ($p === null && $separado && $det)) {
            $formatos[] = ['id' => $f, 'nome' => $nome];
        }
    }
    $atual = $previas[$formato] ?? null;
    $iframe = $atual && empty($atual['falhou']) ? $atual : null;

    // Reserva: a imagem maior (baixada uma vez, ao abrir a previa), senao a miniatura; os textos; o Gerenciador
    $img = null;
    $cid = (string)($obj['criativo_id'] ?? '');
    $arqG = meta_criativo_arquivo($id, true);
    $temG = $cid !== '' && ($obj['criativo_grande'] ?? null) === $cid && is_file($arqG);
    // (falhou: so tenta de novo depois de 10 minutos, pelo mesmo cache da previa)
    // A tentativa fica marcada ANTES do download: se o PHP cair no meio, a proxima abertura nao tenta de novo
    // por 10 minutos e mostra a miniatura (seguranca, 13/11, A1)
    if (!$temG && $det && ($det['img'] ?? '') !== '' && $cid !== '' && ($det['criativo_id'] ?? '') === $cid
        && !meta_previa_cache_ler($id, 'grande_falhou')) {
        meta_previa_cache_gravar($id, 'grande_falhou', ['em' => time()]);
        if (meta_criativo_baixar($id, $det['img'], true) === true) {
            $db->prepare('UPDATE meta_objetos SET criativo_grande = ? WHERE id = ?')->execute([$cid, $id]);
            meta_previa_cache_apagar($id, 'grande_falhou');
            $temG = true;
        }
    }
    if ($temG) {
        $img = 'meta-criativo.php?id=' . $id . '&tam=g&v=' . $cid;
    } else {
        $img = meta_criativo_linha($obj)['src'] ?? null;
    }
    $conta = preg_replace('/\D/', '', (string)($k['conta'] ?? ''));
    $tipo = $det['tipo'] ?? '';
    $tipo = in_array($tipo, ['imagem', 'video', 'carrossel'], true) ? $tipo : (meta_criativo_linha($obj)['tipo'] ?? null);

    return [
        'ok' => true,
        'id' => $id,
        'status' => meta_previa_status($det['status'] ?? $obj['status_efetivo'] ?? null),
        'formatos' => $formatos,
        'formato' => $formato,
        'iframe_src' => $iframe['src'] ?? null,
        'iframe_tam' => $iframe['tam'] ?? null,
        'reserva' => [
            'img' => $img,
            'tipo' => $tipo,
            'texto' => $det['textos']['texto'] ?? '',
            'titulo' => $det['textos']['titulo'] ?? '',
            'descricao' => $det['textos']['descricao'] ?? '',
            'botao' => $det['textos']['botao'] ?? '',
            'gerenciador' => $conta !== '' ? 'https://adsmanager.facebook.com/adsmanager/manage/ads?act=' . $conta . '&selected_ad_ids=' . $id : null,
        ],
        'aviso' => $aviso,
    ];
}
