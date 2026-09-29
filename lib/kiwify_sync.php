<?php
// Busca as vendas pela API da Kiwify e grava na mesma tabela do webhook.
//
// Primeira busca: os ultimos 89 dias (limite da API: 90), ou menos se a retencao for
// menor. Depois, so o que mudou desde a ultima busca (filtro updated_at da API), o que
// traz venda nova, Pix pago, reembolso e chargeback. Uma vez por dia, os 89 dias de novo. Quem chama: o painel, em segundo
// plano, quando a ultima busca tem mais de 10 minutos, e o botao "Atualizar vendas".
//
// LGPD: igual ao webhook. Nome, e-mail, telefone, CPF e endereco do comprador vem na
// resposta e sao descartados aqui; so pedido, produto, valor, status e etiquetas ficam.

require_once __DIR__ . '/kiwify_api.php';

const KIWIFY_SYNC_INTERVALO = 600;  // segundos entre buscas automaticas
const KIWIFY_SYNC_PAGINAS = 30;     // teto por busca (100 vendas por pagina)

// Busca automatica vencida? (e nao ha outra em andamento)
function kiwify_sync_vencida(): bool
{
    if (!kiwify_api_chave()) {
        return false;
    }
    $ultima = (int)(ajuste('kiwify_sync_tentativa') ?? 0);
    return time() - $ultima >= KIWIFY_SYNC_INTERVALO;
}

// Estado para a tela: quando buscou, o que trouxe, erro
function kiwify_sync_estado(): array
{
    $resumo = json_decode((string)ajuste('kiwify_sync_resumo'), true);
    return [
        'ok_em' => ajuste('kiwify_sync_ok_em'),
        'resumo' => is_array($resumo) ? $resumo : null,
        'erro' => ajuste('kiwify_sync_erro'),
        'adiada' => ajuste('kiwify_sync_adiada'), // limite interno ou pausa: nao e erro
    ];
}

// $completa = ignora a ultima busca e rele o periodo todo
function kiwify_sincronizar(bool $completa = false): array
{
    $k = kiwify_api_chave();
    if (!$k) {
        return ['ok' => false, 'erro' => 'Nenhuma chave da API salva.'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'erro' => 'O PHP deste servidor está sem a extensão curl.'];
    }
    $db = track_db();

    // Trava: uma busca por vez (a outra aba ou o botao esperam a proxima)
    $db->exec('BEGIN IMMEDIATE');
    $emAndamento = (int)(ajuste('kiwify_sync_rodando') ?? 0);
    if (time() - $emAndamento < 120) {
        $db->exec('COMMIT');
        return ['ok' => false, 'ocupado' => true, 'erro' => 'Já há uma busca em andamento. Tente de novo em instantes.'];
    }
    definir_ajuste('kiwify_sync_rodando', (string)time());
    definir_ajuste('kiwify_sync_tentativa', (string)time());
    $db->exec('COMMIT');

    try {
        $r = kiwify_sync_buscar($k, $completa);
    } finally {
        definir_ajuste('kiwify_sync_rodando', null);
    }
    if ($r['ok']) {
        definir_ajuste('kiwify_sync_ok_em', $r['inicio']);
        if ($r['completa']) {
            definir_ajuste('kiwify_sync_completa_em', (string)time());
        }
        definir_ajuste('kiwify_sync_resumo', json_encode(['novas' => $r['novas'], 'atualizadas' => $r['atualizadas'], 'lidas' => $r['lidas'], 'completa' => $r['completa']]));
        definir_ajuste('kiwify_sync_erro', null);
        definir_ajuste('kiwify_sync_adiada', null);
    } elseif (!empty($r['adiada'])) {
        definir_ajuste('kiwify_sync_adiada', $r['erro']);
    } else {
        definir_ajuste('kiwify_sync_erro', $r['erro']);
        definir_ajuste('kiwify_sync_adiada', null);
    }
    return $r;
}

function kiwify_sync_buscar(array $k, bool $completa): array
{
    $inicio = agora_utc();
    $t = kiwify_api_token_salvo($k);
    if (!$t['ok']) {
        return $t;
    }
    $tokenNovo = false;
    $dias = min(89, max(7, (int)(track_config()['dias_retencao'] ?? 90)));
    $agora = new DateTime('now', new DateTimeZone('UTC'));
    $consulta = [
        'start_date' => kiwify_api_data((clone $agora)->modify("-$dias days")),
        'end_date' => kiwify_api_data((clone $agora)->modify('+1 day')),
        'view_full_sale_details' => 'true',
        'page_size' => '100',
    ];
    $ultimaOk = ajuste('kiwify_sync_ok_em');
    // Uma vez por dia rele tudo: a lista vem por ordem de atualizacao, e venda que muda
    // durante uma busca longa pode pular de pagina
    $ultimaCompleta = (int)(ajuste('kiwify_sync_completa_em') ?? 0);
    $completa = $completa || !$ultimaOk || time() - $ultimaCompleta > 86400;
    if (!$completa) {
        // Folga de 15 minutos: venda que mudou durante a busca anterior entra de novo
        $desde = new DateTime($ultimaOk, new DateTimeZone('UTC'));
        $consulta['updated_at_start_date'] = kiwify_api_data($desde->modify('-15 minutes'));
        $consulta['updated_at_end_date'] = $consulta['end_date'];
    }

    $novas = $atualizadas = $lidas = $vistas = 0;
    for ($pagina = 1; $pagina <= KIWIFY_SYNC_PAGINAS; $pagina++) {
        [$status, $corpo] = kiwify_api_get('/sales', $consulta + ['page_number' => (string)$pagina], $t['token'], $k['account_id']);
        if ($status === 401 && !$tokenNovo) {
            // Token guardado venceu ou foi revogado: pede outro uma vez e repete a pagina
            $tokenNovo = true;
            $t = kiwify_api_token_salvo($k, true);
            if (!$t['ok']) {
                return $t;
            }
            $pagina--;
            continue;
        }
        if ($status === KIWIFY_ADIADA || $status === 429) {
            $m = $status === 429 ? 'A Kiwify pediu uma pausa nas buscas (muitas chamadas). O painel espera e tenta sozinho.' : kiwify_api_mensagem($corpo);
            return ['ok' => false, 'adiada' => true, 'erro' => $m . ($lidas ? ' ' . $lidas . ' venda(s) já gravadas.' : '')];
        }
        if ($status !== 200) {
            $m = kiwify_api_mensagem($corpo);
            $erro = $status === 0 ? 'Sem conexão com a API da Kiwify.' : 'A API da Kiwify respondeu HTTP ' . $status . ($m ? ': ' . $m : '') . '.';
            return ['ok' => false, 'erro' => $erro . ($lidas ? ' ' . $lidas . ' venda(s) gravadas antes do erro.' : '')];
        }
        $lista = is_array($corpo['data'] ?? null) ? $corpo['data'] : [];
        $vistas += count($lista);
        foreach ($lista as $venda) {
            if (!is_array($venda)) {
                continue;
            }
            $r = kiwify_sync_gravar($venda);
            if ($r === 'nova') {
                $novas++;
            } elseif ($r === 'atualizada') {
                $atualizadas++;
            }
            $lidas++;
        }
        // count = total do periodo; para quando ja veio tudo (ou a pagina veio vazia)
        if (!$lista || $vistas >= (int)($corpo['pagination']['count'] ?? 0)) {
            break;
        }
    }
    return ['ok' => true, 'inicio' => $inicio, 'novas' => $novas, 'atualizadas' => $atualizadas, 'lidas' => $lidas, 'completa' => $completa];
}

// Data da Kiwify (ISO, UTC) no formato do banco
function kiwify_sync_data($iso): ?string
{
    if (!is_string($iso) || $iso === '') {
        return null;
    }
    try {
        return (new DateTime($iso))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return null;
    }
}

// Grava uma venda da API. Devolve 'nova', 'atualizada' ou 'igual'.
function kiwify_sync_gravar(array $v): string
{
    $pedido = texto($v['id'] ?? '', 100);
    if ($pedido === '') {
        return 'igual';
    }
    $tr = is_array($v['tracking'] ?? null) ? $v['tracking'] : [];
    $campo = function (string $c) use ($tr) {
        $x = texto($tr[$c] ?? '', 300);
        return $x === '' ? null : $x;
    };
    $sck = $campo('sck');
    $visitante = ($sck && preg_match('/^trk_([a-f0-9]{32})$/', $sck, $m)) ? $m[1] : null;
    $valor = $v['payment']['charge_amount'] ?? null;
    $valor = is_int($valor) || (is_string($valor) && ctype_digit($valor)) ? (int)$valor : null;
    $criada = kiwify_sync_data($v['created_at'] ?? null) ?? agora_utc();

    $linha = [
        'referencia' => texto($v['reference'] ?? '', 40) ?: null,
        'status' => texto($v['status'] ?? '', 40),
        'produto' => texto($v['product']['name'] ?? '', 200),
        'valor' => $valor,
        'pagamento' => texto($v['payment_method'] ?? '', 40),
        'tipo' => texto($v['type'] ?? '', 20) ?: null,
        'pedido_pai' => texto($v['parent_order_id'] ?? '', 100) ?: null,
        'aprovada_em' => kiwify_sync_data($v['approved_date'] ?? null),
    ];

    $db = track_db();
    $st = $db->prepare('SELECT status, fonte, valor, referencia, tipo, aprovada_em FROM vendas WHERE pedido = ?');
    $st->execute([$pedido]);
    $antes = $st->fetch();

    $db->prepare('INSERT INTO vendas (pedido, evento, status, produto, valor, pagamento, recebida_em, atualizada_em, visitante, sck, src,
                                      utm_source, utm_medium, utm_campaign, utm_content, utm_term,
                                      referencia, tipo, pedido_pai, fonte, aprovada_em)
                  VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'api\', ?)
                  ON CONFLICT (pedido) DO UPDATE SET
                      status = excluded.status,
                      atualizada_em = CASE WHEN vendas.status IS excluded.status THEN vendas.atualizada_em ELSE excluded.atualizada_em END,
                      produto = COALESCE(NULLIF(excluded.produto, \'\'), vendas.produto),
                      valor = COALESCE(excluded.valor, vendas.valor),
                      pagamento = COALESCE(NULLIF(excluded.pagamento, \'\'), vendas.pagamento),
                      visitante = COALESCE(vendas.visitante, excluded.visitante),
                      sck = COALESCE(vendas.sck, excluded.sck),
                      src = COALESCE(vendas.src, excluded.src),
                      utm_source = COALESCE(vendas.utm_source, excluded.utm_source),
                      utm_medium = COALESCE(vendas.utm_medium, excluded.utm_medium),
                      utm_campaign = COALESCE(vendas.utm_campaign, excluded.utm_campaign),
                      utm_content = COALESCE(vendas.utm_content, excluded.utm_content),
                      utm_term = COALESCE(vendas.utm_term, excluded.utm_term),
                      referencia = COALESCE(excluded.referencia, vendas.referencia),
                      tipo = COALESCE(excluded.tipo, vendas.tipo),
                      pedido_pai = COALESCE(excluded.pedido_pai, vendas.pedido_pai),
                      aprovada_em = COALESCE(excluded.aprovada_em, vendas.aprovada_em),
                      fonte = CASE WHEN vendas.fonte IN (\'webhook\', \'ambos\') THEN \'ambos\' ELSE \'api\' END')
        ->execute([$pedido, $linha['status'], $linha['produto'], $linha['valor'], $linha['pagamento'], $criada, agora_utc(),
            $visitante, $sck, $campo('src'), $campo('utm_source'), $campo('utm_medium'), $campo('utm_campaign'), $campo('utm_content'), $campo('utm_term'),
            $linha['referencia'], $linha['tipo'], $linha['pedido_pai'], $linha['aprovada_em']]);

    if (!$antes) {
        return 'nova';
    }
    $mudou = (string)$antes['status'] !== $linha['status']
        || ($antes['fonte'] !== 'ambos' && $antes['fonte'] !== 'api')
        || $antes['referencia'] === null || $antes['tipo'] === null;
    return $mudou ? 'atualizada' : 'igual';
}
