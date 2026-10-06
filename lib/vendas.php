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

// Produtos escolhidos no filtro do topo ([] = todos, com os order bumps). Da para marcar
// mais de um (ex.: o Drive antigo e o Drive 2.0). O index.php define, pelo endereco ou pelo
// que ficou lembrado na sessao; as telas so leem.
function produto_filtro(?array $definir = null): array
{
    static $produtos = [];
    if ($definir !== null) {
        $produtos = array_values(array_unique(array_filter($definir, fn($p) => is_string($p) && $p !== '')));
    }
    return $produtos;
}

// A venda entra no filtro de produto do topo? Escolher o produto principal deixa os order
// bumps de fora (cada bump e outro produto na Kiwify).
function venda_no_filtro(array $v): bool
{
    $p = produto_filtro();
    return !$p || in_array((string)($v['produto'] ?? ''), $p, true);
}

// Produtos que ja venderam (para o filtro do topo), em ordem alfabetica
function produtos_conhecidos(PDO $db): array
{
    return $db->query("SELECT DISTINCT produto FROM vendas WHERE produto IS NOT NULL AND produto <> '' ORDER BY produto")->fetchAll(PDO::FETCH_COLUMN);
}

// ROI e margem com as contas da UTMify (e da planilha de campanhas): o imposto que a Meta
// cobra sobre o gasto sai do faturamento.
//   ROI    = (faturamento liquido - imposto) / gasto        (acima de 1, se paga)
//   margem = lucro / (faturamento liquido - imposto), em %   (sem venda: N/A)
function roi_campanha(int $fat, int $gasto, int $imposto): ?float
{
    return $gasto > 0 ? ($fat - $imposto) / $gasto : null;
}

function margem_pct(int $fat, int $lucro, int $imposto): ?float
{
    $base = $fat - $imposto;
    return $fat > 0 && $base > 0 ? $lucro * 100 / $base : null;
}
