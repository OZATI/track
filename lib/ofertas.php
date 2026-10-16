<?php
// Catalogo de produtos/ofertas Kiwify e ticket ponderado por objeto do Gestor.

function oferta_chave(?string $produtoId, ?string $ofertaId, string $nome): string
{
    $base = trim((string)$produtoId);
    if ($base === '') {
        $base = 'nome-' . substr(hash('sha256', mb_strtolower(trim($nome))), 0, 20);
    }
    return $base . ':' . (trim((string)$ofertaId) !== '' ? trim((string)$ofertaId) : 'principal');
}

// Inteiros da Kiwify estao em centavos; valores decimais sao reais.
function oferta_centavos($valor): ?int
{
    if (is_int($valor) || (is_string($valor) && preg_match('/^\d+$/', $valor))) {
        $n = (int)$valor;
    } elseif ((is_float($valor) || is_string($valor)) && is_numeric($valor)) {
        $n = (int)round((float)$valor * 100);
    } else {
        return null;
    }
    return $n > 0 && $n <= 100000000 ? $n : null;
}

function oferta_registrar(PDO $db, ?string $produtoId, ?string $ofertaId, string $nome, ?int $precoBase, string $tipo = 'principal'): ?string
{
    $nome = trim($nome);
    if ($nome === '') {
        return null;
    }
    $id = oferta_chave($produtoId, $ofertaId, $nome);
    $tipo = in_array($tipo, ['principal', 'bump', 'upsell'], true) ? $tipo : 'principal';
    $db->prepare('INSERT INTO ofertas (id, produto_id, oferta_kiwify_id, nome, preco_base, tipo, ativo, atualizado_em)
        VALUES (?, ?, ?, ?, ?, ?, 1, ?)
        ON CONFLICT(id) DO UPDATE SET produto_id = COALESCE(excluded.produto_id, ofertas.produto_id),
        oferta_kiwify_id = COALESCE(excluded.oferta_kiwify_id, ofertas.oferta_kiwify_id), nome = excluded.nome,
        preco_base = COALESCE(excluded.preco_base, ofertas.preco_base), atualizado_em = excluded.atualizado_em')
        ->execute([$id, $produtoId ?: null, $ofertaId ?: null, $nome, $precoBase, $tipo, agora_utc()]);
    return $id;
}

function oferta_campanha(PDO $db, string $campanhaId): ?array
{
    $st = $db->prepare('SELECT o.* FROM campanha_ofertas c JOIN ofertas o ON o.id = c.oferta_id WHERE c.campanha_id = ?');
    $st->execute([$campanhaId]);
    $r = $st->fetch();
    return $r ?: null;
}

function ticket_objeto(PDO $db, string $nivel, string $id, string $dia1, string $dia2): array
{
    $utm = ['campanha' => 'utm_campaign', 'conjunto' => 'utm_medium', 'anuncio' => 'utm_content'];
    $campanhaId = $nivel === 'campanha' ? $id : '';
    if ($nivel === 'conjunto' || $nivel === 'anuncio') {
        $campo = $utm[$nivel];
        $st = $db->prepare('SELECT campanha_id FROM meta_objetos WHERE id = ?');
        $st->execute([$id]);
        $campanhaId = (string)($st->fetchColumn() ?: '');
    } elseif ($nivel === 'conta') {
        $campo = null;
    } else {
        $campo = $utm['campanha'];
    }
    if (substr($dia1, 0, 4) === '0000') {
        $de = '0001-01-01 00:00:00';
    } else {
        $utc = new DateTimeZone('UTC');
        $de = (new DateTime($dia1, fuso()))->setTimezone($utc)->format('Y-m-d H:i:s');
    }
    if (substr($dia2, 0, 4) === '9999') {
        $ate = '9999-12-31 23:59:59';
    } else {
        $utc = $utc ?? new DateTimeZone('UTC');
        $ate = (new DateTime($dia2, fuso()))->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
    }
    $sql = 'SELECT * FROM vendas WHERE recebida_em >= ? AND recebida_em < ? AND pedido_pai IS NULL AND (tipo IS NULL OR tipo NOT IN (\'bump\',\'order_bump\'))';
    $params = [$de, $ate];
    if ($campo !== null) {
        $sql .= " AND $campo LIKE ?";
        $params[] = '%|' . $id;
    }
    $vendas = array_values(array_filter(consulta($db, $sql, $params), fn($v) => aprovada($v) && ($campo === null || gestor_id_utm($v[$campo] ?? '') === $id)));
    $vendas = array_values(array_filter($vendas, 'venda_no_filtro'));
    $grupos = [];
    $ofertasPorId = [];
    foreach (consulta($db, 'SELECT * FROM ofertas') as $o) {
        $ofertasPorId[$o['id']] = $o;
    }
    $associacoes = [];
    foreach ($vendas as $v) {
        $camp = gestor_id_utm($v['utm_campaign'] ?? '') ?? '';
        if (!array_key_exists($camp, $associacoes)) {
            $associacoes[$camp] = $camp !== '' ? oferta_campanha($db, $camp) : null;
        }
        $offerId = (string)($v['oferta_id'] ?? '');
        $oferta = $ofertasPorId[$offerId] ?? null;
        if ($oferta && $oferta['tipo'] !== 'principal') {
            continue;
        }
        $preco = (int)($v['preco_base'] ?? 0);
        if ($preco <= 0 && $oferta) {
            $preco = (int)($oferta['preco_base'] ?? 0);
        }
        if ($preco <= 0 && $associacoes[$camp]) {
            $preco = (int)($associacoes[$camp]['preco_base'] ?? 0);
        }
        if ($preco <= 0) {
            $preco = (int)($v['valor'] ?? 0); // fallback legado quando a Kiwify nao enviou preco-base
        }
        if ($preco <= 0) {
            continue;
        }
        $key = $offerId !== '' ? $offerId : ('legado:' . mb_strtolower((string)($v['produto'] ?? '')));
        if (!isset($grupos[$key])) {
            $grupos[$key] = ['id' => $offerId, 'nome' => $oferta['nome'] ?? ($v['oferta_nome'] ?: $v['produto'] ?: 'Oferta'), 'soma' => 0, 'vendas' => 0];
        }
        $grupos[$key]['soma'] += $preco;
        $grupos[$key]['vendas']++;
    }
    if (!$grupos && $campanhaId !== '') {
        $configurada = oferta_campanha($db, $campanhaId);
        if ($configurada && (int)$configurada['preco_base'] > 0) {
            $grupos[$configurada['id']] = ['id' => $configurada['id'], 'nome' => $configurada['nome'], 'soma' => (int)$configurada['preco_base'], 'vendas' => 1, 'configurada' => true];
        }
    }
    $quantidade = array_sum(array_column($grupos, 'vendas'));
    $ticket = $quantidade ? array_sum(array_column($grupos, 'soma')) / $quantidade : null;
    foreach ($grupos as &$g) {
        $g['pct'] = $quantidade ? $g['vendas'] * 100 / $quantidade : 0;
        $g['preco'] = $g['vendas'] ? $g['soma'] / $g['vendas'] : $g['soma'];
    }
    unset($g);
    $listaGrupos = array_values($grupos);
    $rotulo = !$listaGrupos ? 'defina a oferta' : (count($listaGrupos) > 1 ? 'mix: ' . implode(' · ', array_map(fn($g) => number_format($g['pct'], 0, ',', '.') . '% ' . reais((int)round($g['preco'])), $listaGrupos)) : (string)($listaGrupos[0]['nome'] ?? ''));
    return ['ticket' => $ticket, 'mix' => array_values($grupos), 'vendas' => $quantidade, 'rotulo' => $rotulo, 'campanha_id' => $campanhaId];
}

function ticket_objeto_texto(array $r): string
{
    return (string)($r['rotulo'] ?? '');
}
