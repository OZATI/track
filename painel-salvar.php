<?php
// Grava a tela montada (lib/grade.php) de quem esta logado: a tela (resumo, financeiro...), o
// aparelho (computador ou celular) e o layout, e volta para a tela. So POST, com login, o acesso da
// tela e o token do formulario. "padrao" apaga o salvo: a tela volta ao padrao.

require __DIR__ . '/lib/util.php';
require __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/grade.php';

exigir_login(null);
$tela = grade_tela((string)($_POST['tela'] ?? 'resumo'));
exigir_login($tela['acesso'] ?? 'utm');
header('Cache-Control: no-store');
$volta = grade_volta_segura((string)($_POST['volta'] ?? ''));
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !$tela) {
    header('Location: ' . $volta);
    exit;
}
$aparelho = ($_POST['aparelho'] ?? '') === 'celular' ? 'celular' : 'computador';
if (!csrf_valido()) {
    aviso_definir('Sessão expirada. Recarregue a página e tente de novo.', 'erro');
} elseif (($_POST['acao'] ?? '') === 'padrao') {
    grade_apagar($tela, (string)usuario_atual(), $aparelho);
    aviso_definir(ucfirst($tela['titulo']) . ' do ' . $aparelho . ' de volta ao padrão.');
} else {
    [$ok, $msg] = grade_salvar($tela, (string)usuario_atual(), $aparelho, (string)($_POST['layout'] ?? ''));
    aviso_definir(ucfirst($msg), $ok ? 'ok' : 'erro');
    if (!$ok) {
        $volta = grade_url($volta, ['montar' => 1, 'aparelho' => $aparelho]);
    }
}
header('Location: ' . $volta);
