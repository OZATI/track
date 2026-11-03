<?php
require_once __DIR__ . '/pagamentos/mercadopago.php';

// Tabelas separadas: não mudam as vendas Kiwify nem guardam comprador em vendas.
function pag_schema(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS csh_pedidos (
        id TEXT PRIMARY KEY, usuario TEXT NOT NULL, projeto TEXT NOT NULL, total INTEGER NOT NULL,
        itens TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'novo', provider_id TEXT UNIQUE,
        metodo TEXT, pix TEXT, qr TEXT, criado INTEGER NOT NULL, atualizado INTEGER NOT NULL,
        entregue INTEGER NOT NULL DEFAULT 0, origem TEXT NOT NULL DEFAULT '{}');
        CREATE INDEX IF NOT EXISTS csh_usuario ON csh_pedidos(usuario, criado);
        CREATE TABLE IF NOT EXISTS csh_compradores (
        pedido TEXT PRIMARY KEY, nome TEXT NOT NULL, email TEXT NOT NULL, telefone TEXT NOT NULL,
        aviso TEXT NOT NULL, apagar_em INTEGER NOT NULL, atribuicao TEXT NOT NULL DEFAULT '{}');
        CREATE TABLE IF NOT EXISTS csh_capi (
        pedido TEXT PRIMARY KEY, evento_em INTEGER NOT NULL, enviado INTEGER NOT NULL DEFAULT 0,
        proxima INTEGER NOT NULL DEFAULT 0, tentativas INTEGER NOT NULL DEFAULT 0);");
}

function pag_obter(PDO $db, string $id): ?array
{
    $stmt = $db->prepare('SELECT * FROM csh_pedidos WHERE id=?');
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function pag_preparar(PDO $db, string $user, string $project, array $items, array $buyer, array $origin): array
{
    if (!$items || count($items) > 30) throw new InvalidArgumentException('Escolha os arquivos.');
    usort($items, fn($a, $b) => strcmp($a['id'], $b['id']));
    $total = array_sum(array_column($items, 'centavos'));
    if ($total < 1 || $total > 99999999) throw new InvalidArgumentException('Valor fora do limite.');
    $encoded = json_encode($items, JSON_THROW_ON_ERROR);
    $db->exec('BEGIN IMMEDIATE');
    try {
        // Clique duplo, outra aba ou resposta perdida: retoma o mesmo pedido aberto.
        $s = $db->prepare("SELECT * FROM csh_pedidos WHERE usuario=? AND projeto=? AND status IN ('novo','criando','incerto','pendente') ORDER BY criado DESC LIMIT 1");
        $s->execute([$user, $project]);
        $old = $s->fetch(PDO::FETCH_ASSOC);
        if ($old) {
            if ($old['itens'] !== $encoded) throw new DomainException('Já existe um pedido aberto deste projeto. Retome o pagamento antes de alterar os arquivos.');
            $db->exec('COMMIT');
            return $old;
        }
        $s = $db->prepare('SELECT COUNT(*) FROM csh_pedidos WHERE usuario=? AND criado>?');
        $s->execute([$user, time() - 3600]);
        if ((int)$s->fetchColumn() >= 10) throw new DomainException('Limite de tentativas. Tente mais tarde.');
        $id = bin2hex(random_bytes(16));
        $attribution = array_intersect_key($origin, array_flip(['fbp','fbc']));
        unset($origin['fbp'], $origin['fbc']);
        $s = $db->prepare('INSERT INTO csh_pedidos(id,usuario,projeto,total,itens,criado,atualizado,origem) VALUES(?,?,?,?,?,?,?,?)');
        $s->execute([$id, $user, $project, $total, $encoded, time(), time(), json_encode($origin, JSON_THROW_ON_ERROR)]);
        $s = $db->prepare('INSERT INTO csh_compradores VALUES(?,?,?,?,?,?,?)');
        $s->execute([$id, $buyer['nome'], $buyer['email'], $buyer['telefone'], 'checkout-v1', time() + 30 * 86400, json_encode($attribution)]);
        $db->exec('COMMIT');
        return pag_obter($db, $id);
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
}

function pag_aplicar(PDO $db, array $order, array $raw): array
{
    $next = pag_mp_normalizar($raw, $order);
    if (!empty($order['provider_id']) && $order['provider_id'] !== $next['provider_id']) throw new RuntimeException('ID divergente.');
    // Nunca devolve um pedido final para pendente por uma resposta atrasada.
    $s = $db->prepare("UPDATE csh_pedidos SET provider_id=?, status=?, pix=?, qr=?, atualizado=?, entregue=CASE WHEN status=? THEN entregue ELSE 0 END
        WHERE id=? AND NOT (status IN ('aprovada','reembolsada','chargeback') AND ? IN ('pendente','recusada','expirada'))
        AND NOT (status IN ('reembolsada','chargeback') AND ?='aprovada')");
    $s->execute([$next['provider_id'], $next['status'], $next['pix'], $next['qr'], time(), $next['status'],
        $order['id'], $next['status'], $next['status']]);
    if ($next['status'] === 'aprovada') {
        $db->prepare('INSERT OR IGNORE INTO csh_capi(pedido,evento_em) VALUES(?,?)')->execute([$order['id'],time()]);
        $db->prepare('UPDATE csh_compradores SET apagar_em=(SELECT evento_em+7776000 FROM csh_capi WHERE pedido=?) WHERE pedido=?')->execute([$order['id'],$order['id']]);
    }
    return pag_obter($db, $order['id']);
}

function pag_cobrar(PDO $db, array $cfg, array $order, array $payment, ?callable $http = null): array
{
    if ($order['status'] !== 'novo') return $order;
    if ((int)$order['criado'] < time() - 3600) {
        $db->prepare("UPDATE csh_pedidos SET status='expirada' WHERE id=? AND status='novo'")->execute([$order['id']]);
        return pag_obter($db,$order['id']);
    }
    $s = $db->prepare('SELECT * FROM csh_compradores WHERE pedido=?');
    $s->execute([$order['id']]);
    $buyer = $s->fetch(PDO::FETCH_ASSOC);
    if (!$buyer) throw new DomainException('Pedido expirado.');
    $body = pag_mp_payload($order, $buyer, $payment, (int)($cfg['max_parcelas'] ?? 1));
    // Reserva atômica ANTES da chamada externa. Timeout não autoriza outra cobrança/provedor.
    $s = $db->prepare("UPDATE csh_pedidos SET status='criando',metodo=?,atualizado=? WHERE id=? AND status='novo'");
    $s->execute([$payment['metodo'], time(), $order['id']]);
    if (!$s->rowCount()) return pag_obter($db, $order['id']);
    try {
        [$status, $raw] = ($http ?? 'pag_mp_http')($cfg, 'POST', '', $body, $order['id']);
        if ($status >= 200 && $status < 300) return pag_aplicar($db, $order, $raw);
        // Mesmo um 5xx pode ocorrer depois da cobrança. Não troca de provedor.
        $state = in_array($status, [400, 401, 403, 422], true) ? 'recusada' : 'incerto';
        $db->prepare("UPDATE csh_pedidos SET status=?,atualizado=? WHERE id=? AND status='criando'")->execute([$state, time(), $order['id']]);
    } catch (Throwable $e) {
        $db->prepare("UPDATE csh_pedidos SET status='incerto',atualizado=? WHERE id=? AND status='criando'")->execute([time(), $order['id']]);
    }
    return pag_obter($db, $order['id']);
}

function pag_publico(array $order): array
{
    return ['id' => $order['id'], 'status' => $order['status'], 'total' => (int)$order['total'],
        'itens' => json_decode($order['itens'], true), 'pix' => $order['pix'] ?? '', 'qr' => $order['qr'] ?? '',
        'entregue' => (bool)$order['entregue'], 'metodo' => $order['metodo'] ?? null];
}
