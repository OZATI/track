<?php
// Orcamento pelo painel (lib/orcamento.php): mudar agora, programar e apagar programacao, e
// volta para a tela de antes com o resultado. So POST, com login e o token do formulario; a
// confirmacao (com o aviso de mais de 20%) aparece antes, na tela (painel.js).

require __DIR__ . '/lib/util.php';
require_once __DIR__ . '/lib/vendas.php';
require_once __DIR__ . '/lib/orcamento.php';

exigir_login();
header('Cache-Control: no-store');
$volta = destino_seguro((string)($_POST['volta'] ?? ''));
if ($volta === './') {
    $volta = './?aba=gestor';
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ./?aba=gestor');
    exit;
}
$usuario = (string)usuario_atual();
$acao = (string)($_POST['acao'] ?? '');
if (!csrf_valido()) {
    aviso_definir('Sessão expirada. Recarregue a página e tente de novo.', 'erro');
} elseif (!dentro_do_limite('meta-orcamento:' . ip_conexao(), 20, 600)) {
    aviso_definir('Muitas alterações seguidas. Espere alguns minutos.', 'erro');
} elseif ($acao === 'mudar') {
    $id = (string)($_POST['objeto'] ?? '');
    $valor = fin_centavos((string)($_POST['valor'] ?? ''));
    [$ok, $msg] = preg_match('/^\d{3,25}$/', $id) && $valor !== null ? orc_mudar($id, $valor, $usuario) : [false, 'Confira o novo orçamento (ex.: 45,00).'];
    aviso_definir($msg, $ok ? 'ok' : 'erro');
} elseif ($acao === 'programar') {
    [$ok, $msg] = orc_programar($_POST, $usuario);
    aviso_definir($msg, $ok ? 'ok' : 'erro');
} elseif ($acao === 'apagar' && ctype_digit((string)($_POST['id'] ?? ''))) {
    aviso_definir(orc_apagar((int)$_POST['id']) ? 'Programação apagada.' : 'Essa programação já não existe.');
} else {
    aviso_definir('Pedido inválido.', 'erro');
}
header('Location: ' . $volta);
