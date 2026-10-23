<?php
// Conectar com Facebook: o painel recebe o token da Meta pelo login do Facebook, sem colar.
//
// Quem cuida das integracoes clica em "Conectar com Facebook" (tela Meta Ads), entra na Meta e
// volta com o token (meta-conectar.php). O painel guarda o nome e o e-mail de quem conectou
// (permissao email), so para saber quem ligou a conta. O login do painel continua por usuario e
// senha (decisao de 28/09/2026): o Facebook aqui so conecta a conta de anuncios.
//
// O app da Meta do negocio: o ID nao e segredo. A chave secreta (App Secret) troca o codigo pelo
// token e confere a assinatura dos avisos do webhook (lib/meta_interacoes.php). Vem da variavel
// de ambiente TRACK_META_APP_SECRET ou, sem ela, da configuracao (fora da pasta publica), como
// as outras chaves; nunca volta para a tela. Com o Login do Facebook para Empresas, a
// configuracao do login (config_id) escolhe as permissoes e o tipo de token (o de usuario do
// sistema nao vence); sem ela, o login pede META_LOGIN_PERMISSOES e o token vence em 60 dias.

require_once __DIR__ . '/meta_api.php';

// Permissoes do login comum (sem config_id). As de comentarios e mensagens do webhook entram
// pela configuracao do login ou por TRACK_META_LOGIN_PERMISSOES.
const META_LOGIN_PERMISSOES = 'email,ads_read,ads_management,pages_show_list,pages_read_engagement,instagram_basic,instagram_manage_insights';
const META_LOGIN_VALIDADE = 600; // segundos entre o clique e a volta da Meta

// O app da Meta: ['id', 'segredo', 'config_id', 'permissoes', 'segredo_no_ambiente'], ou null
// enquanto faltar o ID ou a chave secreta
function meta_app(): ?array
{
    $c = track_config()['meta_app'] ?? [];
    $c = is_array($c) ? $c : [];
    $id = (string)(getenv('TRACK_META_APP_ID') ?: ($c['id'] ?? ''));
    $segredo = (string)(getenv('TRACK_META_APP_SECRET') ?: ($c['segredo'] ?? ''));
    if (!meta_app_id_valido($id) || !meta_app_segredo_valido($segredo)) {
        return null;
    }
    $config = (string)(getenv('TRACK_META_LOGIN_CONFIG') ?: ($c['config_id'] ?? ''));
    return [
        'id' => $id,
        'segredo' => $segredo,
        'config_id' => meta_app_id_valido($config) ? $config : '',
        'permissoes' => (string)(getenv('TRACK_META_LOGIN_PERMISSOES') ?: META_LOGIN_PERMISSOES),
        'segredo_no_ambiente' => (bool)getenv('TRACK_META_APP_SECRET'),
    ];
}

function meta_app_id_valido(string $id): bool
{
    return (bool)preg_match('/^\d{5,25}$/', $id);
}

// A chave secreta do app tem 32 caracteres hexadecimais
function meta_app_segredo_valido(string $segredo): bool
{
    return (bool)preg_match('/^[a-f0-9]{32}$/i', $segredo);
}

// Endereco absoluto de uma tela do painel (a pasta da tela que esta rodando). A Meta so aceita
// o endereco de volta que estiver cadastrado no app, entao um Host falso nao completa o login.
function meta_url_painel(string $arquivo): string
{
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $host = preg_match('/^[a-z0-9.-]+(:\d{1,5})?$/i', $host) ? strtolower($host) : 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    return (https() ? 'https' : 'http') . '://' . $host . $dir . '/' . $arquivo;
}

// Endereco de volta do login: cadastre igual no app (Login do Facebook → Configuracoes →
// URIs de redirecionamento do OAuth validos)
function meta_login_volta(): string
{
    return meta_url_painel('meta-conectar.php');
}

function meta_login_url(array $app, string $estado): string
{
    $q = ['client_id' => $app['id'], 'redirect_uri' => meta_login_volta(), 'state' => $estado, 'response_type' => 'code'];
    if ($app['config_id'] !== '') {
        $q['config_id'] = $app['config_id'];
        $q['override_default_response_type'] = 'true';
    } else {
        $q['scope'] = $app['permissoes'];
    }
    return 'https://www.facebook.com/' . META_API_VERSAO . '/dialog/oauth?' . http_build_query($q);
}

// POST no /oauth/access_token (troca do codigo e troca pelo token longo). A chave secreta vai no
// corpo, nunca na URL. Devolve [status, corpo].
function meta_oauth(array $campos): array
{
    if (!dentro_do_limite('meta-api', meta_api_limite_minuto(), 60)) {
        return [META_ADIADA, ['error' => ['message' => 'Limite interno de consultas à Meta atingido. Tente de novo em um minuto.']]];
    }
    $ch = curl_init(meta_api_base() . '/oauth/access_token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($campos),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
    ]);
    $resposta = curl_exec($ch);
    $status = $resposta === false ? 0 : (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $json = is_string($resposta) ? json_decode($resposta, true) : null;
    return [$status, is_array($json) ? $json : null];
}

// Troca o codigo da volta pelo token (o longo, quando o token e de usuario) e le quem conectou.
// Devolve ['ok', 'erro', 'token', 'vence_em' (UTC ou null = nao vence), 'nome', 'email'].
function meta_login_concluir(array $app, string $codigo): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'erro' => 'O PHP deste servidor está sem a extensão curl.'];
    }
    [$st, $c] = meta_oauth(['client_id' => $app['id'], 'client_secret' => $app['segredo'], 'redirect_uri' => meta_login_volta(), 'code' => $codigo]);
    $token = (string)($c['access_token'] ?? '');
    if ($st !== 200 || $token === '') {
        $m = rtrim(meta_api_mensagem($c), '.');
        return ['ok' => false, 'erro' => 'A Meta não trocou o código do login pelo token' . ($m ? ': ' . $m : '') . '. Tente conectar de novo.'];
    }
    $expira = (int)($c['expires_in'] ?? 0);
    // Token de usuario vence em horas: troca pelo de 60 dias. Falhou, fica o que veio.
    if ($app['config_id'] === '' || $expira > 0) {
        [$st, $l] = meta_oauth(['grant_type' => 'fb_exchange_token', 'client_id' => $app['id'], 'client_secret' => $app['segredo'], 'fb_exchange_token' => $token]);
        if ($st === 200 && !empty($l['access_token'])) {
            $token = (string)$l['access_token'];
            $expira = (int)($l['expires_in'] ?? 0);
        }
    }
    // Quem conectou: so nome e e-mail (sem a permissao email, a Meta nao manda o e-mail)
    [$st, $eu] = meta_api_get('/me', ['fields' => 'id,name,email'], $token);
    $email = (string)($eu['email'] ?? '');
    return [
        'ok' => true,
        'token' => $token,
        'vence_em' => $expira > 0 ? gmdate('Y-m-d H:i:s', time() + $expira) : null,
        'nome' => $st === 200 ? texto($eu['name'] ?? '', 120) : '',
        'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? texto($email, 160) : '',
    ];
}

// Contas de anuncios que o token enxerga: [['conta' => numero, 'nome' => texto]]
function meta_login_contas(string $token): array
{
    [$st, $c] = meta_api_get('/me/adaccounts', ['fields' => 'account_id,name', 'limit' => '50'], $token);
    $contas = [];
    foreach ($st === 200 ? (array)($c['data'] ?? []) : [] as $a) {
        $conta = meta_api_conta((string)($a['account_id'] ?? $a['id'] ?? ''));
        if (preg_match('/^\d{6,20}$/', $conta)) {
            $contas[$conta] = ['conta' => $conta, 'nome' => texto($a['name'] ?? '', 120)];
        }
    }
    return array_values($contas);
}

// Confere o token na conta escolhida (meta_api_testar) e guarda, com quem conectou e quando
// vence. Devolve [ok, mensagem para a tela].
function meta_login_salvar(array $login, string $conta): array
{
    $r = meta_api_testar($login['token'], $conta);
    if (!$r['ok']) {
        return [false, $r['erro'] . ' Nada foi salvo.'];
    }
    $cfg = track_config();
    $contaAntes = (string)($cfg['meta_api']['conta'] ?? '');
    $cfg['meta_api'] = [
        'token' => $login['token'],
        'conta' => $conta,
        'conta_nome' => $r['conta_nome'],
        'moeda' => $r['moeda'],
        'permissoes' => $r['permissoes'],
        'salva_em' => agora_utc(),
        'salva_por' => usuario_atual(),
        'origem' => 'facebook',
        'vence_em' => $login['vence_em'],
        'conectado_por' => array_filter(['nome' => $login['nome'], 'email' => $login['email']]),
    ];
    if (!track_salvar_config($cfg)) {
        return [false, 'Não foi possível gravar a configuração. Confira as permissões de ' . track_pasta_dados() . '.'];
    }
    if ($contaAntes !== $conta) { // outra conta de anuncios: o historico de gasto e outro
        meta_historico_zerar();
    }
    return [true, 'Conectado com o Facebook. Conta ' . $r['conta_nome'] . ' conferida e salva.'
        . ($r['edita'] ? ' Este token também pode ' . implode(', ', $r['edita']) . '.' : '')];
}
