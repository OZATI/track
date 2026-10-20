<?php
// Webhook da Meta: quem comentou ou mandou mensagem na pagina do Facebook e no Instagram da
// marca, e de qual anuncio veio. Alimenta a tela Comentarios e mensagens (meta-interacoes.php).
//
// - meta-webhook.php recebe os avisos. So grava aviso assinado com a chave secreta do app
//   (X-Hub-Signature-256, lib/meta_login.php); o token de verificacao e gerado aqui.
// - Campos para assinar no app: Instagram → comments, messages, mentions; Pagina → feed,
//   messages. A Meta so manda aviso com o app publicado.
// - Nome e foto de quem mandou mensagem: recurso Business Asset User Profile Access. A Meta so
//   devolve depois que a pessoa escreveu para a marca e, fora quem tem funcao no app, so com o
//   app aprovado na analise. No comentario do Instagram o @ ja vem no aviso; no da pagina, o
//   nome vem no aviso quando o recurso esta liberado.
//
// LGPD: so o minimo para atender e saber a origem. Id, @, nome e o endereco da foto na Meta
// (vence em dias; a foto nao e baixada, meta-foto.php so repassa). Texto so do comentario, que
// e publico; o da mensagem privada nao e gravado. Tudo some depois de dias_retencao.

require_once __DIR__ . '/meta_login.php';
require_once __DIR__ . '/instagram_sync.php'; // ig_api_chave(), ig_url_midia_ok()

const META_PERFIL_INTERVALO = 2 * 86400; // a foto da Meta vence em dias: busca de novo depois disso

// Token de verificacao do webhook (a Meta manda de volta ao cadastrar a URL). '' = nao gerado.
function meta_webhook_token(): string
{
    $t = track_config()['meta_webhook']['verificar'] ?? '';
    return is_string($t) && preg_match('/^[a-f0-9]{32,64}$/', $t) ? $t : '';
}

// O aviso veio da Meta: HMAC-SHA256 do corpo com a chave secreta do app
function meta_webhook_assinatura_ok(string $corpo, string $cabecalho, string $segredo): bool
{
    if ($segredo === '' || !preg_match('/^sha256=([a-f0-9]{64})$/i', trim($cabecalho), $m)) {
        return false;
    }
    return hash_equals(hash_hmac('sha256', $corpo, $segredo), strtolower($m[1]));
}

// Ids da Meta: numeros (pagina, conta, pessoa, post do Instagram, anuncio)...
function meta_id($v): ?string
{
    return (is_string($v) || is_int($v)) && preg_match('/^\d{1,30}$/', (string)$v) ? (string)$v : null;
}

// ...post e comentario da pagina ("<pagina>_<post>")...
function meta_id_post($v): ?string
{
    return is_string($v) && preg_match('/^\d{1,30}(_\d{1,30})?$/', $v) ? $v : null;
}

// ...e o id da mensagem (letras, numeros e alguns sinais)
function meta_id_mensagem($v): ?string
{
    return is_string($v) && preg_match('/^[A-Za-z0-9_\-=.:+\/]{1,200}$/', $v) ? $v : null;
}

// @ do Instagram: letras, numeros, ponto e sublinhado
function meta_usuario($v): ?string
{
    return is_string($v) && preg_match('/^[A-Za-z0-9._]{1,30}$/', $v) ? $v : null;
}

// Hora do aviso: segundos ou milissegundos, dentro do razoavel (senao, agora)
function meta_quando($v): int
{
    $t = is_numeric($v) ? (int)$v : 0;
    $t = $t > 100000000000 ? intdiv($t, 1000) : $t;
    return $t > 1100000000 && $t < time() + 86400 ? $t : time();
}

function meta_interacao_linha(string $canal, string $tipo, string $id, int $quando, ?string $conta, array $campos): array
{
    return ['id' => $id, 'canal' => $canal, 'tipo' => $tipo, 'em' => gmdate('Y-m-d H:i:s', $quando), 'conta_id' => $conta] + $campos
        + ['pessoa_id' => null, 'pessoa_usuario' => null, 'pessoa_nome' => null, 'midia_id' => null, 'texto' => null, 'anuncio_id' => null, 'apagar' => false];
}

// Um aviso do webhook vira linhas de meta_interacoes. Ignora o que nao e comentario, mencao ou
// mensagem de alguem de fora (leitura, entrega, reacao, o que a propria marca escreveu).
function meta_interacoes_do_aviso(array $aviso): array
{
    $objeto = $aviso['object'] ?? '';
    if (!in_array($objeto, ['instagram', 'page'], true)) {
        return [];
    }
    $canal = $objeto === 'instagram' ? 'instagram' : 'facebook';
    $linhas = [];
    foreach (array_slice(is_array($aviso['entry'] ?? null) ? $aviso['entry'] : [], 0, 200) as $entrada) {
        if (!is_array($entrada)) {
            continue;
        }
        $conta = meta_id($entrada['id'] ?? '');
        foreach (array_slice(is_array($entrada['changes'] ?? null) ? $entrada['changes'] : [], 0, 200) as $mudanca) {
            $l = is_array($mudanca) ? meta_interacao_mudanca($canal, $conta, $mudanca, meta_quando($entrada['time'] ?? 0)) : null;
            if ($l) {
                $linhas[] = $l;
            }
        }
        foreach (array_slice(is_array($entrada['messaging'] ?? null) ? $entrada['messaging'] : [], 0, 200) as $ev) {
            $l = is_array($ev) ? meta_interacao_mensagem($canal, $conta, $ev) : null;
            if ($l) {
                $linhas[] = $l;
            }
        }
    }
    return $linhas;
}

// Comentario (Instagram: comments e live_comments; pagina: feed com item comment) e mencao
function meta_interacao_mudanca(string $canal, ?string $conta, array $mudanca, int $quando): ?array
{
    $campo = $mudanca['field'] ?? '';
    $v = is_array($mudanca['value'] ?? null) ? $mudanca['value'] : [];
    $de = is_array($v['from'] ?? null) ? $v['from'] : [];
    if ($canal === 'instagram' && in_array($campo, ['comments', 'live_comments'], true)) {
        $id = meta_id($v['id'] ?? '');
        $pessoa = meta_id($de['id'] ?? '');
        if (!$id || ($pessoa !== null && $pessoa === $conta)) {
            return null;
        }
        $midia = is_array($v['media'] ?? null) ? $v['media'] : [];
        return meta_interacao_linha('instagram', 'comentario', 'ig-c:' . $id, $quando, $conta, [
            'pessoa_id' => $pessoa, 'pessoa_usuario' => meta_usuario($de['username'] ?? ''),
            'midia_id' => meta_id($midia['id'] ?? ''), 'texto' => texto($v['text'] ?? '', 300) ?: null,
            'anuncio_id' => meta_id($midia['ad_id'] ?? ''),
        ]);
    }
    if ($canal === 'instagram' && $campo === 'mentions') {
        $id = meta_id($v['comment_id'] ?? '') ?? meta_id($v['media_id'] ?? '');
        return $id ? meta_interacao_linha('instagram', 'mencao', 'ig-m:' . $id, $quando, $conta, ['midia_id' => meta_id($v['media_id'] ?? '')]) : null;
    }
    if ($canal === 'facebook' && $campo === 'feed' && ($v['item'] ?? '') === 'comment') {
        $id = meta_id_post($v['comment_id'] ?? '');
        $pessoa = meta_id($de['id'] ?? '');
        if (!$id || ($pessoa !== null && $pessoa === $conta)) {
            return null;
        }
        $linha = meta_interacao_linha('facebook', 'comentario', 'fb-c:' . $id, isset($v['created_time']) ? meta_quando($v['created_time']) : $quando, $conta, [
            'pessoa_id' => $pessoa, 'pessoa_nome' => texto($de['name'] ?? '', 120) ?: null,
            'midia_id' => meta_id_post($v['post_id'] ?? ''), 'texto' => texto($v['message'] ?? '', 300) ?: null,
        ]);
        $linha['apagar'] = ($v['verb'] ?? '') === 'remove'; // comentario apagado na Meta sai daqui tambem
        return in_array($v['verb'] ?? '', ['add', 'edited', 'remove'], true) ? $linha : null;
    }
    return null;
}

// Mensagem no Direct ou no Messenger, com o anuncio de origem quando veio de "enviar mensagem"
function meta_interacao_mensagem(string $canal, ?string $conta, array $ev): ?array
{
    $msg = is_array($ev['message'] ?? null) ? $ev['message'] : null;
    $ref = $ev['referral'] ?? ($msg['referral'] ?? ($ev['postback']['referral'] ?? null));
    $ref = is_array($ref) ? $ref : [];
    if (($msg && !empty($msg['is_echo'])) || (!$msg && !$ref)) {
        return null;
    }
    $pessoa = meta_id($ev['sender']['id'] ?? '');
    if (!$pessoa || $pessoa === $conta) {
        return null;
    }
    $quando = meta_quando($ev['timestamp'] ?? 0);
    $mid = $msg ? meta_id_mensagem($msg['mid'] ?? '') : null;
    return meta_interacao_linha($canal, 'mensagem', $mid ? 'msg:' . $mid : 'ref:' . $pessoa . ':' . $quando, $quando, $conta, [
        'pessoa_id' => $pessoa,
        'anuncio_id' => strtoupper((string)($ref['source'] ?? '')) === 'ADS' ? meta_id($ref['ad_id'] ?? '') : null,
    ]);
}

// Grava as linhas novas (aviso repetido nao duplica; comentario editado atualiza o texto;
// apagado sai). Devolve quantas mudaram.
function meta_interacoes_gravar(array $linhas): int
{
    $db = track_db();
    $novo = $db->prepare('INSERT INTO meta_interacoes (id, canal, tipo, em, recebida_em, conta_id, pessoa_id, pessoa_usuario, pessoa_nome, midia_id, texto, anuncio_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT (id) DO UPDATE SET texto = COALESCE(excluded.texto, meta_interacoes.texto),
            pessoa_nome = COALESCE(meta_interacoes.pessoa_nome, excluded.pessoa_nome)
        WHERE excluded.texto IS NOT meta_interacoes.texto');
    $apaga = $db->prepare('DELETE FROM meta_interacoes WHERE id = ?');
    $n = 0;
    $agora = agora_utc();
    foreach ($linhas as $l) {
        if ($l['apagar']) {
            $apaga->execute([$l['id']]);
            $n += $apaga->rowCount();
            continue;
        }
        $novo->execute([$l['id'], $l['canal'], $l['tipo'], $l['em'], $agora, $l['conta_id'], $l['pessoa_id'], $l['pessoa_usuario'],
            $l['pessoa_nome'], $l['midia_id'], $l['texto'], $l['anuncio_id']]);
        $n += $novo->rowCount();
    }
    return $n;
}

// Token da pagina que recebeu (ou da pagina ligada ao Instagram que recebeu), pelo token da API
// Meta. So em memoria, nunca gravado.
function meta_token_pagina(?string $conta): ?string
{
    static $tokens = null;
    $k = meta_api_chave();
    if (!$k || !$conta) {
        return null;
    }
    if ($tokens === null) {
        $tokens = [];
        [$st, $c] = meta_api_get('/me/accounts', ['fields' => 'id,access_token,instagram_business_account{id}', 'limit' => '100'], $k['token']);
        foreach ($st === 200 ? (array)($c['data'] ?? []) : [] as $p) {
            $t = is_array($p) ? (string)($p['access_token'] ?? '') : '';
            if ($t === '') {
                continue;
            }
            if ($id = meta_id($p['id'] ?? '')) {
                $tokens[$id] = $t;
            }
            if ($ig = meta_id($p['instagram_business_account']['id'] ?? '')) {
                $tokens[$ig] = $t;
            }
        }
    }
    return $tokens[$conta] ?? null;
}

// Nome, @ e foto de uma pessoa que mandou mensagem. Devolve [true, perfil] ou [false, motivo].
function meta_perfil(string $canal, ?string $conta, string $pessoa): array
{
    $campos = $canal === 'instagram' ? 'name,username,profile_pic' : 'name,profile_pic';
    $ig = $canal === 'instagram' ? ig_api_chave() : null;
    if ($ig && ($ig['modo'] ?? 'instagram') === 'instagram') {
        [$st, $c] = ig_api_get(ig_ctx($ig), '/' . $pessoa, ['fields' => $campos]);
        $m = ig_api_mensagem($c);
    } else {
        $token = meta_token_pagina($conta);
        if ($token === null) {
            return [false, 'o token da API Meta não enxerga a página (ou o Instagram) que recebeu a mensagem'];
        }
        [$st, $c] = meta_api_get('/' . $pessoa, ['fields' => $campos], $token);
        $m = meta_api_mensagem($c);
    }
    if ($st !== 200 || (empty($c['name']) && empty($c['username']))) {
        return [false, $m ?: 'a Meta não devolveu nome nem foto (precisa do recurso Business Asset User Profile Access liberado)'];
    }
    $foto = (string)($c['profile_pic'] ?? '');
    return [true, [
        'nome' => texto($c['name'] ?? '', 120) ?: null,
        'usuario' => meta_usuario($c['username'] ?? ''),
        'foto' => $foto !== '' && strlen($foto) <= 2000 && ig_url_midia_ok($foto) ? $foto : null,
    ]];
}

// Busca nome e foto de quem mandou mensagem e ainda nao tem (ou tem ha mais de 2 dias): no
// maximo $max pessoas por vez. Falha nao apaga o que ja havia e espera 2 dias para tentar de
// novo. Devolve ['buscadas', 'achadas', 'erro'].
function meta_interacoes_completar(int $max = 10): array
{
    $db = track_db();
    $antes = gmdate('Y-m-d H:i:s', time() - META_PERFIL_INTERVALO);
    $pessoas = consulta($db, "SELECT pessoa_id, canal, MAX(conta_id) AS conta_id FROM meta_interacoes
        WHERE tipo = 'mensagem' AND pessoa_id IS NOT NULL GROUP BY pessoa_id, canal
        HAVING MAX(COALESCE(perfil_em, '')) < ? ORDER BY MAX(em) DESC LIMIT " . max(1, min(25, $max)), [$antes]);
    $achou = $db->prepare('UPDATE meta_interacoes SET pessoa_nome = COALESCE(?, pessoa_nome), pessoa_usuario = COALESCE(?, pessoa_usuario),
        pessoa_foto = ?, perfil_em = ? WHERE pessoa_id = ? AND canal = ?');
    $falhou = $db->prepare('UPDATE meta_interacoes SET perfil_em = ? WHERE pessoa_id = ? AND canal = ?');
    $r = ['buscadas' => 0, 'achadas' => 0, 'erro' => null];
    foreach ($pessoas as $p) {
        [$ok, $perfil] = meta_perfil($p['canal'], $p['conta_id'], $p['pessoa_id']);
        $r['buscadas']++;
        if ($ok) {
            $achou->execute([$perfil['nome'], $perfil['usuario'], $perfil['foto'], agora_utc(), $p['pessoa_id'], $p['canal']]);
            $r['achadas']++;
        } else {
            $falhou->execute([agora_utc(), $p['pessoa_id'], $p['canal']]);
            $r['erro'] = $perfil;
        }
    }
    definir_ajuste('meta_perfil_erro', $r['erro'] ? texto($r['erro'], 240) : null);
    return $r;
}

// Ultimas interacoes, com o anuncio e a campanha de origem quando o painel conhece o anuncio
function meta_interacoes_lista(int $limite = 100): array
{
    return consulta(track_db(), 'SELECT i.*, a.nome AS anuncio_nome, c.id AS campanha_id, c.nome AS campanha_nome
        FROM meta_interacoes i
        LEFT JOIN meta_objetos a ON a.id = i.anuncio_id AND a.nivel = \'ad\'
        LEFT JOIN meta_objetos c ON c.id = a.campanha_id AND c.nivel = \'campaign\'
        ORDER BY i.em DESC LIMIT ' . max(1, min(500, $limite)));
}
