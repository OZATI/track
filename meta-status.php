<?php
// Chave de status do gestor de anuncios: liga ou pausa na Meta (lib/gestor_editar.php) e volta
// para a tela de antes com o resultado. So POST, com login e o token do formulario; a
// confirmacao aparece antes, na tela (painel.js).

require __DIR__ . '/lib/util.php';
require_once __DIR__ . '/lib/gestor_editar.php';

exigir_login();
header('Cache-Control: no-store');
$volta = destino_seguro((string)($_POST['volta'] ?? ''));
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ./?aba=gestor');
    exit;
}
if (!csrf_valido()) {
    aviso_definir('Sessão expirada. Recarregue a página e tente de novo.', 'erro');
} elseif (!dentro_do_limite('meta-status:' . ip_conexao(), 20, 600)) {
    aviso_definir('Muitas alterações seguidas. Espere alguns minutos.', 'erro');
} else {
    $id = (string)($_POST['id'] ?? '');
    [$ok, $msg] = preg_match('/^\d{3,25}$/', $id)
        ? gestor_mudar_status($id, (string)($_POST['status'] ?? ''), (string)usuario_atual())
        : [false, 'Item inválido.'];
    aviso_definir($msg, $ok ? 'ok' : 'erro');
}
header('Location: ' . $volta);
