<?php
// Checkout Transparente: Orders, nunca Preferences/Payments.
// Transporte injetável apenas por código PHP nos testes; nenhuma URL vem da requisição.
function pag_mp_http(array $cfg, string $method, string $path, ?array $body = null, string $key = ''): array
{
    $headers = ['Authorization: Bearer ' . $cfg['access_token'], 'Content-Type: application/json'];
    if ($key !== '') $headers[] = 'X-Idempotency-Key: ' . $key;
    $ch = curl_init('https://api.mercadopago.com/v1/orders' . $path);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => false]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    return [$raw === false ? 0 : $status, $raw === false ? [] : (json_decode($raw, true) ?: [])];
}

function pag_centavos($value): int
{
    if (!is_scalar($value) || !preg_match('/^(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,2}))?$/D', (string)$value, $m)) {
        throw new InvalidArgumentException('Valor inválido.');
    }
    return (int)$m[1] * 100 + (int)str_pad($m[2] ?? '', 2, '0');
}

function pag_mp_payload(array $order, array $buyer, array $payment, int $maxInstallments = 1): array
{
    $amount = number_format($order['total'] / 100, 2, '.', '');
    $method = ['id' => 'pix', 'type' => 'bank_transfer'];
    if (($payment['metodo'] ?? '') === 'cartao') {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $payment['token'] ?? '') ||
            !preg_match('/^[a-z0-9_]{1,40}$/D', $payment['bandeira'] ?? '') ||
            !in_array($payment['tipo'] ?? '', ['credit_card', 'debit_card'], true) ||
            !is_int($payment['parcelas'] ?? null) || $payment['parcelas'] < 1 ||
            $payment['parcelas'] > $maxInstallments) {
            throw new InvalidArgumentException('Dados de pagamento inválidos.');
        }
        $method = ['id' => $payment['bandeira'], 'type' => $payment['tipo'],
            'token' => $payment['token'], 'installments' => $payment['parcelas']];
    } elseif (($payment['metodo'] ?? '') !== 'pix') {
        throw new InvalidArgumentException('Forma de pagamento inválida.');
    }
    $transaction = ['amount' => $amount, 'payment_method' => $method];
    if ($method['id'] === 'pix') $transaction['expiration_time'] = 'PT30M';
    $payer = ['email' => $buyer['email']];
    // Documento exigido pelo Brick só transita na API. Não entra no banco nem em logs.
    if (isset($payment['identificacao'])) {
        $doc = $payment['identificacao'];
        if (!is_array($doc) || !in_array($doc['type'] ?? '', ['CPF', 'CNPJ'], true) ||
            !preg_match('/^(?:[0-9]{11}|[0-9]{14})$/D', $doc['number'] ?? '')) {
            throw new InvalidArgumentException('Documento inválido.');
        }
        $payer['identification'] = $doc;
    }
    return ['type' => 'online', 'processing_mode' => 'automatic', 'total_amount' => $amount,
        'external_reference' => $order['id'], 'payer' => $payer,
        'transactions' => ['payments' => [$transaction]]];
}

function pag_mp_normalizar(array $raw, array $order): array
{
    if (!preg_match('/^ORD[A-Z0-9]{10,60}$/D', $raw['id'] ?? '') ||
        ($raw['external_reference'] ?? '') !== $order['id'] ||
        pag_centavos($raw['total_amount'] ?? '') !== (int)$order['total'] ||
        (isset($raw['country_code']) && $raw['country_code'] !== 'BRA')) {
        throw new RuntimeException('Ordem divergente.');
    }
    $state = $raw['status'] ?? '';
    $detail = $raw['status_detail'] ?? '';
    $status = 'pendente';
    if ($state === 'processed' && $detail === 'accredited' &&
        pag_centavos($raw['total_paid_amount'] ?? '0') >= (int)$order['total']) $status = 'aprovada';
    if (in_array($state, ['failed', 'canceled'], true)) $status = $detail === 'expired' ? 'expirada' : 'recusada';
    if ($state === 'refunded' || $detail === 'refunded' || $detail === 'partially_refunded') $status = 'reembolsada';
    if ($state === 'charged_back' || $detail === 'charged_back' || $detail === 'in_process_chargeback') $status = 'chargeback';
    $method = $raw['transactions']['payments'][0]['payment_method'] ?? [];
    return ['provider_id' => $raw['id'], 'status' => $status,
        'pix' => (string)($method['qr_code'] ?? ''), 'qr' => (string)($method['qr_code_base64'] ?? '')];
}

function pag_mp_assinatura(string $id, string $request, string $signature, string $secret): bool
{
    if ($secret === '' || !preg_match('/^ORD[A-Z0-9]{10,60}$/Di', $id) ||
        !preg_match('/^[a-zA-Z0-9-]{1,100}$/D', $request)) return false;
    $fields = [];
    foreach (explode(',', $signature) as $part) {
        $pair = explode('=', trim($part), 2);
        if (count($pair) !== 2 || isset($fields[$pair[0]])) return false;
        $fields[$pair[0]] = $pair[1];
    }
    if (!preg_match('/^[0-9]{10,13}$/D', $fields['ts'] ?? '') ||
        !preg_match('/^[a-f0-9]{64}$/D', $fields['v1'] ?? '')) return false;
    // MP normaliza IDs alfanuméricos em minúsculas no manifesto. Reentregas são idempotentes.
    $manifest = 'id:' . strtolower($id) . ';request-id:' . $request . ';ts:' . $fields['ts'] . ';';
    return hash_equals(hash_hmac('sha256', $manifest, $secret), $fields['v1']);
}
