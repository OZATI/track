<?php
// Como o painel le uma venda da Kiwify: situacao em portugues, aprovada ou nao, order bump
// e valor em reais. Usado pelas telas, pelo webhook, pela busca na API e pelas notificacoes.

function reais(?int $centavos): string
{
    return $centavos === null ? '' : 'R$ ' . number_format($centavos / 100, 2, ',', '.');
}

// Status da Kiwify em portugues. Aceita o status do pedido ou o tipo do evento.
function situacao(array $v): array
{
    $s = strtolower((string)$v['status']);
    $ev = strtolower((string)$v['evento']);
    if (in_array($s, ['paid', 'approved', 'completed', 'complete'], true) || in_array($ev, ['order_approved', 'compra_aprovada'], true)) {
        return ['Aprovada', 'ok'];
    }
    if (in_array($s, ['refunded'], true) || in_array($ev, ['order_refunded', 'compra_reembolsada'], true)) {
        return ['Reembolsada', 'erro'];
    }
    if (in_array($s, ['chargedback', 'chargeback'], true) || $ev === 'chargeback') {
        return ['Chargeback', 'erro'];
    }
    if (in_array($s, ['refused'], true) || in_array($ev, ['order_rejected', 'compra_recusada'], true)) {
        return ['Recusada', 'neutro'];
    }
    if (in_array($s, ['waiting_payment', 'pending'], true) || in_array($ev, ['pix_created', 'billet_created', 'pix_gerado', 'boleto_gerado'], true)) {
        return ['Aguardando pagamento', 'alerta'];
    }
    return [$v['status'] ?: ($v['evento'] ?: '—'), 'neutro'];
}

// Order bump e um pedido a parte na Kiwify, ligado ao principal: entra no faturamento,
// mas nao conta como outra venda nem outra conferencia
function eh_bump(array $v): bool
{
    return ($v['tipo'] ?? '') === 'bump' || !empty($v['pedido_pai']);
}

function aprovada(array $v): bool
{
    return situacao($v)[0] === 'Aprovada';
}
