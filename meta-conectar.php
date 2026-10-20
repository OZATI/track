<?php
// Conectar com Facebook (lib/meta_login.php), em tres passos neste arquivo:
//
// 1. POST, pelo botao da tela Meta Ads (login, acesso a Integracoes e token do formulario):
//    guarda um estado aleatorio na sessao e manda para o login da Meta.
// 2. A Meta volta aqui com ?code=&state=. O cookie da sessao e SameSite=Strict e nao vem
//    nesta volta, que chega de outro site: a pagina so repassa para o passo 3 pelo proprio
//    painel, sem abrir sessao (abrir criaria outra e derrubaria o login de quem esta conectando).
// 3. ?concluir=1: confere o estado (uso unico, 10 minutos), troca o codigo pelo token e guarda.
//    Com mais de uma conta de anuncios, a escolha fica na tela Meta Ads; ate la, o token fica
//    so na sessao.

require __DIR__ . '/lib/util.php';
require_once __DIR__ . '/lib/meta_login.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    exigir_login('integracoes');
    $app = meta_app();
    if (!csrf_valido()) {
        aviso_definir('Sessão expirada. Recarregue a página e tente de novo.', 'erro');
    } elseif (!$app) {
        aviso_definir('Cadastre o ID e a chave secreta do app da Meta antes de conectar.', 'erro');
    } elseif (!dentro_do_limite('meta-login:' . ip_conexao(), 10, 600)) {
        aviso_definir('Muitas tentativas. Espere alguns minutos.', 'erro');
    } else {
        $estado = bin2hex(random_bytes(16));
        $_SESSION['meta_login_estado'] = ['valor' => $estado, 'em' => time()];
        header('Location: ' . meta_login_url($app, $estado));
        exit;
    }
    header('Location: meta-api.php');
    exit;
}

if (!isset($_GET['concluir'])) {
    // Passo 2: so repassa o que a Meta mandou, se tiver cara do que a Meta manda
    $q = ['concluir' => '1'];
    foreach (['code' => 2000, 'state' => 64, 'error' => 60] as $campo => $max) {
        $v = $_GET[$campo] ?? '';
        if (is_string($v) && $v !== '' && strlen($v) <= $max && preg_match('/^[A-Za-z0-9_\-.]+$/', $v)) {
            $q[$campo] = $v;
        }
    }
    $destino = 'meta-conectar.php?' . http_build_query($q);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="robots" content="noindex"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta http-equiv="refresh" content="0;url=' . e($destino) . '"><title>Conectando</title>'
        . '<body style="font:15px/1.5 system-ui,sans-serif;max-width:480px;margin:15vh auto;padding:0 20px">'
        . '<p>Voltando para o painel… Se nada acontecer, <a href="' . e($destino) . '">continue aqui</a>.</p>';
    exit;
}

// Passo 3
exigir_login('integracoes');
$guardado = $_SESSION['meta_login_estado'] ?? null;
unset($_SESSION['meta_login_estado'], $_SESSION['meta_login_pendente']);
$estado = $_GET['state'] ?? '';
$codigo = $_GET['code'] ?? '';
$app = meta_app();
if (!is_array($guardado) || !is_string($estado) || $estado === '' || !hash_equals((string)($guardado['valor'] ?? ''), $estado)
    || time() - (int)($guardado['em'] ?? 0) > META_LOGIN_VALIDADE) {
    aviso_definir('O login do Facebook expirou ou não começou neste painel. Clique em Conectar com Facebook de novo.', 'erro');
} elseif (!empty($_GET['error']) || !is_string($codigo) || $codigo === '') {
    aviso_definir('A conexão com o Facebook foi cancelada. Nada mudou.', 'erro');
} elseif (!$app) {
    aviso_definir('Cadastre o ID e a chave secreta do app da Meta antes de conectar.', 'erro');
} else {
    $login = meta_login_concluir($app, $codigo);
    if (!$login['ok']) {
        aviso_definir($login['erro'], 'erro');
    } else {
        $contas = meta_login_contas($login['token']);
        $numeros = array_column($contas, 'conta');
        $atual = meta_api_chave()['conta'] ?? '';
        $conta = in_array($atual, $numeros, true) ? $atual : (count($numeros) === 1 ? $numeros[0] : '');
        if ($conta !== '') {
            [$ok, $msg] = meta_login_salvar($login, $conta);
            aviso_definir($msg, $ok ? 'ok' : 'erro');
        } elseif (!$contas) {
            aviso_definir('O login deu certo, mas a Meta não mostrou nenhuma conta de anúncios para este usuário. Nada foi salvo.', 'erro');
        } else {
            $_SESSION['meta_login_pendente'] = ['login' => $login, 'contas' => $contas, 'em' => time()];
            aviso_definir('Login feito. Escolha abaixo a conta de anúncios que o painel vai ler.');
        }
    }
}
header('Location: meta-api.php');
