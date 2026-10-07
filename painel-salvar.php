<?php
// Grava o painel editavel (lib/painel.php) de quem esta logado, no aparelho escolhido
// (computador ou celular), e volta para o painel. So POST, com login e o token do formulario.
// "padrao" apaga o salvo: o painel volta ao padrao.

require __DIR__ . '/lib/util.php';
require __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/painel.php';

exigir_login();
header('Cache-Control: no-store');
$periodo = periodo_valido((string)($_POST['periodo'] ?? '')) ? (string)$_POST['periodo'] : '7d';
$volta = './?' . http_build_query(['aba' => 'painel', 'periodo' => $periodo]);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ' . $volta);
    exit;
}
$aparelho = ($_POST['aparelho'] ?? '') === 'celular' ? 'celular' : 'computador';
if (!csrf_valido()) {
    aviso_definir('Sessão expirada. Recarregue a página e tente de novo.', 'erro');
} elseif (($_POST['acao'] ?? '') === 'padrao') {
    painel_apagar((string)usuario_atual(), $aparelho);
    aviso_definir('Painel do ' . $aparelho . ' de volta ao padrão.');
} else {
    [$ok, $msg] = painel_salvar((string)usuario_atual(), $aparelho, (string)($_POST['layout'] ?? ''));
    aviso_definir($msg, $ok ? 'ok' : 'erro');
    if (!$ok) {
        $volta .= '&editar=1&aparelho=' . $aparelho;
    }
}
header('Location: ' . $volta);
