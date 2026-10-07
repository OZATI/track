<?php
// Gestor de anuncios: ligar e pausar campanhas, conjuntos e anuncios na Meta pelo painel.
//
// Decisao de 29/09/2026: o token da API Meta pode ter ads_management; com ele, a chave de
// status do gestor muda o status na Meta. Sempre com confirmacao na tela (painel.js), so
// objetos que o painel ja conhece da conta (meta_objetos), e cada tentativa fica registrada
// (quem, quando, o que, de/para e o resultado) em meta_alteracoes. Sem ads_management, a
// chave aparece desligada e o painel so le.

require_once __DIR__ . '/meta_api.php';

const GESTOR_NIVEL_META = ['campaign' => 'a campanha', 'adset' => 'o conjunto', 'ad' => 'o anúncio'];

function gestor_pode_editar(): bool
{
    $k = meta_api_chave();
    return $k !== null && in_array('ads_management', $k['permissoes'] ?? [], true);
}

// Muda o status de um objeto conhecido para ACTIVE ou PAUSED. Devolve [ok, mensagem].
function gestor_mudar_status(string $id, string $novo, string $usuario): array
{
    $db = track_db();
    $st = $db->prepare('SELECT * FROM meta_objetos WHERE id = ?');
    $st->execute([$id]);
    $obj = $st->fetch(PDO::FETCH_ASSOC);
    if (!$obj || !isset(GESTOR_NIVEL_META[$obj['nivel']]) || !in_array($novo, ['ACTIVE', 'PAUSED'], true)) {
        return [false, 'Não encontrei esse item entre as campanhas, conjuntos e anúncios da conta. Atualize o gestor e tente de novo.'];
    }
    $k = meta_api_chave();
    if (!$k || !gestor_pode_editar()) {
        return [false, 'O token da API Meta só lê. Para ligar e pausar pelo painel, gere um token com ads_management.'];
    }
    $nome = (string)$obj['nome'];
    $acao = $novo === 'ACTIVE' ? 'ligar' : 'pausar';
    [$status, $c] = meta_api_post('/' . $id, ['status' => $novo], $k['token']);
    $ok = $status === 200 && !empty($c['success']);
    $erro = $ok ? null : (meta_api_mensagem($c) ?: ($status === 0 ? 'sem conexão com a Meta' : 'a Meta respondeu HTTP ' . $status));
    $db->prepare('INSERT INTO meta_alteracoes (em, usuario, nivel, objeto_id, nome, de, para, ok, erro) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([agora_utc(), $usuario, $obj['nivel'], $id, $nome, $obj['status'], $novo, $ok ? 1 : 0, $erro]);
    if (!$ok) {
        return [false, 'A Meta não deixou ' . $acao . ' ' . GESTOR_NIVEL_META[$obj['nivel']] . ' "' . $nome . '": ' . $erro . '.'];
    }

    // Reflete na hora; a proxima busca na Meta confirma. Filhos de campanha ou conjunto
    // pausado ficam "pausados pela campanha/conjunto" e voltam com ele.
    $db->beginTransaction();
    $db->prepare('UPDATE meta_objetos SET status = ?, status_efetivo = ? WHERE id = ?')->execute([$novo, $novo, $id]);
    if ($obj['nivel'] !== 'ad') {
        $col = $obj['nivel'] === 'campaign' ? 'campanha_id' : 'conjunto_id';
        $marca = $obj['nivel'] === 'campaign' ? 'CAMPAIGN_PAUSED' : 'ADSET_PAUSED';
        if ($novo === 'PAUSED') {
            $db->prepare("UPDATE meta_objetos SET status_efetivo = ? WHERE $col = ? AND id <> ? AND status_efetivo = 'ACTIVE'")->execute([$marca, $id, $id]);
        } else {
            $db->prepare("UPDATE meta_objetos SET status_efetivo = 'ACTIVE' WHERE $col = ? AND id <> ? AND status = 'ACTIVE' AND status_efetivo = ?")->execute([$id, $id, $marca]);
        }
    }
    $db->commit();
    return [true, ucfirst(GESTOR_NIVEL_META[$obj['nivel']]) . ' "' . $nome . '" foi ' . ($novo === 'ACTIVE' ? 'ligad' : 'pausad') . ($obj['nivel'] === 'campaign' ? 'a' : 'o') . ' na Meta.'];
}

// IDs que vieram do formulario (menu Acoes das marcadas): so numeros, sem repetir
function gestor_ids_post($v): array
{
    return array_values(array_unique(array_filter(is_array($v) ? $v : [$v], fn($i) => is_string($i) && preg_match('/^\d{3,25}$/', $i))));
}

// Liga ou pausa varias de uma vez (menu Acoes das marcadas): tenta todas e conta. [tudo certo, mensagem]
function gestor_mudar_status_varios(array $ids, string $novo, string $usuario): array
{
    if (!gestor_pode_editar()) {
        return [false, 'O token da API Meta só lê. Para ligar e pausar pelo painel, gere um token com ads_management.'];
    }
    if (count($ids) === 1) {
        return gestor_mudar_status((string)$ids[0], $novo, $usuario);
    }
    $feitos = 0;
    $erros = [];
    foreach ($ids as $id) {
        [$ok, $msg] = gestor_mudar_status((string)$id, $novo, $usuario);
        if ($ok) {
            $feitos++;
        } else {
            $erros[] = $msg;
        }
    }
    return [!$erros, ($novo === 'ACTIVE' ? 'Ligados' : 'Pausados') . ' na Meta: ' . $feitos . ' de ' . count($ids) . '.'
        . ($erros ? ' ' . $erros[0] . (count($erros) > 1 ? ' (e mais ' . (count($erros) - 1) . ')' : '') : '')];
}

// Ultimas alteracoes feitas pelo painel
function gestor_historico(int $n = 10): array
{
    $st = track_db()->prepare('SELECT * FROM meta_alteracoes ORDER BY id DESC LIMIT ?');
    $st->bindValue(1, $n, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
