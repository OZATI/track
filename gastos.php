<?php
// Cadastro das despesas do Financeiro (lib/financeiro.php): cadastrar, editar e apagar, e volta
// para a tela de antes com o resultado. So POST, com login e o token do formulario.

require __DIR__ . '/lib/util.php';
require_once __DIR__ . '/lib/vendas.php';
require_once __DIR__ . '/lib/financeiro.php';

exigir_login();
header('Cache-Control: no-store');
$volta = destino_seguro((string)($_POST['volta'] ?? ''));
if ($volta === './') {
    $volta = './?aba=financeiro';
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ./?aba=financeiro');
    exit;
}
$db = track_db();
$id = ctype_digit((string)($_POST['id'] ?? '')) ? (int)$_POST['id'] : 0;
$acao = (string)($_POST['acao'] ?? '');
if (!csrf_valido()) {
    aviso_definir('Sessão expirada. Recarregue a página e tente de novo.', 'erro');
} elseif (!dentro_do_limite('gastos:' . ip_conexao(), 60, 600)) {
    aviso_definir('Muitas alterações seguidas. Espere alguns minutos.', 'erro');
} elseif ($acao === 'apagar' && $id) {
    $st = $db->prepare('DELETE FROM gastos WHERE id = ?');
    $st->execute([$id]);
    aviso_definir($st->rowCount() ? 'Despesa apagada.' : 'Essa despesa já não existe.', $st->rowCount() ? 'ok' : 'erro');
} elseif ($acao === 'salvar') {
    $descricao = texto($_POST['descricao'] ?? '', 120);
    $categoria = texto($_POST['categoria'] ?? '', 40);
    $valor = fin_centavos((string)($_POST['valor'] ?? ''));
    $repete = isset(FIN_REPETE[$_POST['repete'] ?? '']) ? $_POST['repete'] : '';
    $inicio = (string)($_POST['inicio'] ?? '');
    $fim = (string)($_POST['fim'] ?? '');
    $fim = $repete !== 'unico' && fin_data_valida($fim) ? $fim : null;
    if ($descricao === '' || $valor === null || $repete === '' || !fin_data_valida($inicio)) {
        aviso_definir('Confira a despesa: descrição, valor (ex.: 49,90), quando repete e a data.', 'erro');
    } elseif ($fim !== null && $fim < $inicio) {
        aviso_definir('A data final vem antes da data da despesa.', 'erro');
    } elseif ($id) {
        $st = $db->prepare('UPDATE gastos SET descricao = ?, categoria = ?, valor = ?, repete = ?, inicio = ?, fim = ?, atualizado_em = ? WHERE id = ?');
        $st->execute([$descricao, $categoria ?: null, $valor, $repete, $inicio, $fim, agora_utc(), $id]);
        aviso_definir($st->rowCount() ? 'Despesa salva.' : 'Essa despesa já não existe.', $st->rowCount() ? 'ok' : 'erro');
    } else {
        $db->prepare('INSERT INTO gastos (descricao, categoria, valor, repete, inicio, fim, criado_por, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$descricao, $categoria ?: null, $valor, $repete, $inicio, $fim, (string)usuario_atual(), agora_utc()]);
        aviso_definir('Despesa cadastrada.');
    }
} else {
    aviso_definir('Pedido inválido.', 'erro');
}
header('Location: ' . $volta);
