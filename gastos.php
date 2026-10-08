<?php
// Cadastro das despesas do Financeiro (lib/financeiro.php): cadastrar, editar, pausar e apagar, e volta
// para a tela de antes com o resultado. So POST, com login e o token do formulario.

require __DIR__ . '/lib/util.php';
require_once __DIR__ . '/lib/vendas.php';
require_once __DIR__ . '/lib/financeiro.php';

exigir_login('financeiro');
header('Cache-Control: no-store');
$volta = grade_volta_segura((string)($_POST['volta'] ?? '')); // so um endereco do proprio painel (financeiro.php?...)
if ($volta === './') {
    $volta = 'financeiro.php?aba=despesas';
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: financeiro.php');
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
} elseif ($acao === 'ativo' && $id) {
    $ativo = ($_POST['ativo'] ?? '') === '1' ? 1 : 0;
    $st = $db->prepare('UPDATE gastos SET ativo = ?, atualizado_em = ? WHERE id = ?');
    $st->execute([$ativo, agora_utc(), $id]);
    aviso_definir($st->rowCount() ? ($ativo ? 'Despesa ativa: volta a contar.' : 'Despesa pausada: para de contar até ser ativada.') : 'Essa despesa já não existe.', $st->rowCount() ? 'ok' : 'erro');
} elseif ($acao === 'imposto') {
    // Imposto sobre o faturamento (o DRE estima o imposto da nota): o regime e o jeito, % sobre o
    // faturamento (com a aliquota) ou valor fixo por mes (o DAS do MEI)
    $regime = isset(FIN_REGIMES[$_POST['regime'] ?? '']) ? (string)$_POST['regime'] : '';
    $modo = isset(FIN_IMPOSTO_MODOS[$_POST['modo'] ?? '']) ? (string)$_POST['modo'] : ($regime === 'mei' ? 'fixo' : 'percentual');
    $txt = str_replace(',', '.', trim((string)($_POST['aliquota'] ?? '')));
    $pct = $txt === '' ? 0.0 : (is_numeric($txt) ? (float)$txt : -1.0);
    $fixo = trim((string)($_POST['fixo'] ?? '')) === '' ? 0 : fin_centavos((string)$_POST['fixo']);
    if ($regime === '' || ($regime !== 'nenhum' && $modo === 'percentual' && ($pct <= 0 || $pct > 50)) || ($regime !== 'nenhum' && $modo === 'fixo' && !$fixo)) {
        aviso_definir('Confira o imposto: escolha o regime e informe a alíquota em % (de 0 a 50, ex.: 6 ou 8,93) ou o valor fixo por mês (ex.: 81,05).', 'erro');
    } else {
        definir_ajuste('fin_regime', $regime);
        definir_ajuste('fin_imposto_modo', $modo);
        definir_ajuste('fin_imposto_pct', (string)round(max(0.0, $pct), 4));
        definir_ajuste('fin_imposto_fixo', (string)(int)$fixo);
        aviso_definir('Imposto sobre o faturamento salvo: ' . ($regime === 'nenhum' ? 'Não calcular' : fin_imposto_texto(fin_imposto_config())) . '.');
    }
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
