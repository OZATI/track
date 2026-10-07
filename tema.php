<?php
// Grava a aparencia de quem esta no painel (lib/tema.php): um dos prontos ou uma cor livre, e
// volta para a tela de antes. So POST, com login e o token do formulario.

require __DIR__ . '/lib/util.php';
require_once __DIR__ . '/lib/layout.php';

exigir_login(null); // a aparencia e do admin inteiro
header('Cache-Control: no-store');
$volta = destino_seguro((string)($_POST['volta'] ?? ''));
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ./');
    exit;
}
$pronto = TEMA_PRONTOS[$_POST['pronto'] ?? ''][1] ?? null;
$cor = $pronto ?? tema_cor($_POST['cor'] ?? null);
if (!csrf_valido()) {
    aviso_definir('Sessão expirada. Recarregue a página e tente de novo.', 'erro');
} elseif ($cor === null) {
    aviso_definir('Cor inválida. Use o formato #RRGGBB, como #000000.', 'erro');
} else {
    definir_ajuste('tema:' . usuario_atual(), $cor === TEMA_PADRAO ? null : $cor);
}
header('Location: ' . $volta);
