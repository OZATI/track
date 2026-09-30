<?php
// Notificacoes do painel no celular e no computador (Web Push: RFC 8030, 8291 e 8292), sem
// biblioteca de fora.
//
// - Chaves VAPID (P-256) do painel: criadas na primeira vez e guardadas na configuracao, fora
//   da pasta publica. A publica vai para o navegador inscrever o aparelho.
// - Cada aparelho inscrito (push_inscricoes) guarda o endereco do servico de push do navegador
//   (Google, Apple, Mozilla, Microsoft) e as chaves dele. So esses servicos sao aceitos: o
//   painel nunca manda nada para um endereco qualquer.
// - A mensagem vai cifrada (aes128gcm): o servico de push entrega sem conseguir ler.
// - Preferencias por usuario em ajustes 'notif:<usuario>' (o que avisar e o que mostrar).

require_once __DIR__ . '/vendas.php';
require_once __DIR__ . '/layout.php'; // canal()

const PUSH_PREFS_PADRAO = [
    'aprovadas' => true, 'pendentes' => false,
    'valor' => true, 'produto' => true, 'campanha' => false, 'canal' => true, 'nome' => false,
    'relatorio_horas' => [12, 18, 23], 'relatorio_padrao' => 'lucro',
];
const PUSH_PADROES = ['lucro', 'detalhado', 'criativo'];
const PUSH_HORAS = [8, 12, 18, 23];

function b64u(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function b64u_dec(string $s): string
{
    return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
}

// Chave publica P-256 crua (65 bytes: 0x04, x, y) em PEM, para o openssl
function push_pem_publica(string $crua): string
{
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $crua;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

// Par novo de chaves P-256: [PEM da privada, publica crua]
function push_novo_par(): array
{
    $k = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    openssl_pkey_export($k, $pem);
    $d = openssl_pkey_get_details($k)['ec'];
    return [$pem, "\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT)];
}

// Chaves VAPID do painel (criadas na primeira vez)
function push_vapid(): ?array
{
    $cfg = track_config();
    $v = $cfg['push_vapid'] ?? null;
    if (is_array($v) && !empty($v['privada']) && !empty($v['publica'])) {
        return $v;
    }
    [$pem, $crua] = push_novo_par();
    $cfg['push_vapid'] = ['privada' => $pem, 'publica' => b64u($crua), 'criada_em' => agora_utc()];
    return track_salvar_config($cfg) ? $cfg['push_vapid'] : null;
}

// Assinatura ES256: o openssl devolve DER (SEQUENCE de dois INTEGER); o JWT quer r e s crus
function push_der_para_crua(string $der): string
{
    $i = 2;
    $partes = [];
    for ($n = 0; $n < 2; $n++) {
        $tam = ord($der[$i + 1]);
        $partes[] = str_pad(ltrim(substr($der, $i + 2, $tam), "\0"), 32, "\0", STR_PAD_LEFT);
        $i += 2 + $tam;
    }
    return $partes[0] . $partes[1];
}

// JWT do VAPID: quem manda (sub) e para qual servico (aud), valido por 12 horas
function push_jwt(string $endpoint, array $vapid): string
{
    $u = parse_url($endpoint);
    $aud = $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
    $contato = track_config()['push_contato'] ?? 'https://github.com/OZATI/track';
    $a = b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $b = b64u(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => $contato], JSON_UNESCAPED_SLASHES));
    openssl_sign($a . '.' . $b, $der, $vapid['privada'], OPENSSL_ALGO_SHA256);
    return $a . '.' . $b . '.' . b64u(push_der_para_crua($der));
}

// Cifra a mensagem para um aparelho (RFC 8291, aes128gcm, um registro so)
function push_cifrar(string $texto, string $p256dh, string $auth): string
{
    $uaPub = b64u_dec($p256dh);
    $authSegredo = b64u_dec($auth);
    [$pemEfemera, $asPub] = push_novo_par();
    $segredo = openssl_pkey_derive(openssl_pkey_get_public(push_pem_publica($uaPub)), openssl_pkey_get_private($pemEfemera), 32);
    $ikm = hash_hkdf('sha256', $segredo, 32, "WebPush: info\0" . $uaPub . $asPub, $authSegredo);
    $sal = random_bytes(16);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $sal);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $sal);
    $cifrado = openssl_encrypt($texto . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    return $sal . pack('N', 4096) . chr(65) . $asPub . $cifrado . $tag;
}

// So os servicos de push dos navegadores, em HTTPS. Em teste, TRACK_PUSH_HOST libera o falso.
function push_endpoint_ok(string $endpoint): bool
{
    $p = parse_url($endpoint);
    $host = strtolower((string)($p['host'] ?? ''));
    $teste = getenv('TRACK_PUSH_HOST');
    if ($teste && $host . (isset($p['port']) ? ':' . $p['port'] : '') === $teste) {
        return true;
    }
    return ($p['scheme'] ?? '') === 'https'
        && (bool)preg_match('/(^|\.)(fcm\.googleapis\.com|push\.apple\.com|push\.services\.mozilla\.com|notify\.windows\.com)$/', $host);
}

// Envia uma mensagem para um aparelho. Devolve o status HTTP (0 = sem conexao, -1 = recusado aqui).
function push_enviar(array $insc, array $msg, int $ttl = 3600): int
{
    $vapid = push_vapid();
    if (!$vapid || !push_endpoint_ok((string)$insc['endpoint']) || !function_exists('curl_init')) {
        return -1;
    }
    $corpo = push_cifrar(json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $insc['p256dh'], $insc['auth']);
    $ch = curl_init($insc['endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $corpo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'TTL: ' . $ttl,
            'Urgency: high',
            'Authorization: vapid t=' . push_jwt($insc['endpoint'], $vapid) . ', k=' . $vapid['publica'],
        ],
    ]);
    curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $db = track_db();
    if ($status >= 200 && $status < 300) {
        $db->prepare('UPDATE push_inscricoes SET ultimo_ok = ?, falhas = 0 WHERE id = ?')->execute([agora_utc(), $insc['id']]);
    } elseif ($status === 404 || $status === 410) {
        // O navegador cancelou a inscricao (app desinstalado, permissao tirada)
        $db->prepare('DELETE FROM push_inscricoes WHERE id = ?')->execute([$insc['id']]);
    } else {
        $db->prepare('UPDATE push_inscricoes SET falhas = falhas + 1 WHERE id = ?')->execute([$insc['id']]);
    }
    return $status;
}

function push_prefs(string $usuario): array
{
    $p = json_decode((string)ajuste('notif:' . $usuario), true);
    return (is_array($p) ? $p : []) + PUSH_PREFS_PADRAO;
}

function push_salvar_prefs(string $usuario, array $p): void
{
    $limpo = [];
    foreach (['aprovadas', 'pendentes', 'valor', 'produto', 'campanha', 'canal', 'nome'] as $k) {
        $limpo[$k] = !empty($p[$k]);
    }
    $limpo['relatorio_horas'] = array_values(array_intersect(PUSH_HORAS, array_map('intval', (array)($p['relatorio_horas'] ?? []))));
    $limpo['relatorio_padrao'] = in_array($p['relatorio_padrao'] ?? '', PUSH_PADROES, true) ? $p['relatorio_padrao'] : 'lucro';
    definir_ajuste('notif:' . $usuario, json_encode($limpo));
}

// Aparelhos inscritos, por usuario
function push_aparelhos(?string $usuario = null): array
{
    $sql = 'SELECT * FROM push_inscricoes' . ($usuario !== null ? ' WHERE usuario = ?' : '') . ' ORDER BY id';
    $st = track_db()->prepare($sql);
    $st->execute($usuario !== null ? [$usuario] : []);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// Manda para os aparelhos de cada usuario, com a mensagem montada pelas preferencias dele.
// $montar($prefs) devolve a mensagem ou null (este usuario nao quer este aviso).
function push_para_usuarios(callable $montar): int
{
    $enviadas = 0;
    $porUsuario = [];
    foreach (push_aparelhos() as $a) {
        $porUsuario[$a['usuario']][] = $a;
    }
    foreach ($porUsuario as $usuario => $aparelhos) {
        $msg = $montar(push_prefs((string)$usuario));
        if ($msg === null) {
            continue;
        }
        foreach ($aparelhos as $a) {
            $st = push_enviar($a, $msg);
            $enviadas += ($st >= 200 && $st < 300) ? 1 : 0;
        }
    }
    return $enviadas;
}

// "Nome do painel" ligado: o titulo comeca pelo nome do app (ajuda quem tem mais de um painel)
function push_com_nome(?array $msg, array $p): ?array
{
    if ($msg !== null && !empty($p['nome'])) {
        $msg['titulo'] = (track_config()['app_nome'] ?? 'Painel de vendas') . ' - ' . $msg['titulo'];
    }
    return $msg;
}

// Texto da notificacao de uma venda, pelas preferencias
function push_msg_venda(array $v, string $tipo, array $p): ?array
{
    if (($tipo === 'aprovada' && !$p['aprovadas']) || ($tipo === 'pendente' && !$p['pendentes'])) {
        return null;
    }
    $titulo = $tipo === 'aprovada' ? 'Venda aprovada!' : (strtolower((string)$v['pagamento']) === 'boleto' ? 'Boleto gerado' : 'Pix gerado');
    if ($p['produto'] && $v['produto']) {
        $titulo .= ' | ' . $v['produto'];
    }
    $corpo = [];
    if ($p['valor']) {
        $corpo[] = reais((int)$v['valor']);
    }
    if ($p['canal']) {
        $corpo[] = canal($v['utm_source'], $v['utm_medium'], $v['utm_term'], null, $v['utm_campaign'])[1];
    }
    if ($p['campanha'] && $v['utm_campaign']) {
        $corpo[] = trim(explode('|', (string)$v['utm_campaign'])[0]);
    }
    return push_com_nome(['titulo' => $titulo, 'corpo' => implode(' · ', $corpo), 'url' => './?aba=vendas', 'tag' => 'venda-' . $v['pedido'] . '-' . $tipo], $p);
}

// Avisa de uma venda nova ou que mudou (webhook ou busca na API). Uma vez por situacao e so
// pedido principal (order bump nao e outra venda). Pela API, so venda recente: a primeira busca
// nao dispara aviso das vendas antigas. O webhook ($aoVivo) chega na hora, entao sempre vale
// (um Pix de ontem pago agora avisa).
function push_avisar_venda(string $pedido, bool $aoVivo = false): int
{
    $db = track_db();
    $st = $db->prepare('SELECT * FROM vendas WHERE pedido = ?');
    $st->execute([$pedido]);
    $v = $st->fetch(PDO::FETCH_ASSOC);
    if (!$v || eh_bump($v) || !push_aparelhos()) {
        return 0;
    }
    $sit = situacao($v)[0];
    $tipo = $sit === 'Aprovada' ? 'aprovada' : ($sit === 'Aguardando pagamento' ? 'pendente' : null);
    $quando = strtotime(($tipo === 'aprovada' ? ($v['aprovada_em'] ?: $v['recebida_em']) : $v['recebida_em']) . ' UTC') ?: 0;
    if ($tipo === null || ($v['notificado'] ?? '') === $tipo || ($v['notificado'] ?? '') === 'aprovada' || (!$aoVivo && $quando < time() - 3 * 3600)) {
        return 0;
    }
    $db->prepare('UPDATE vendas SET notificado = ? WHERE pedido = ?')->execute([$tipo, $pedido]);
    return push_para_usuarios(fn($p) => push_msg_venda($v, $tipo, $p));
}

// ---------------------------------------------------------------- relatorios

// Numeros de um dia (Brasilia): faturamento liquido, vendas, gasto, imposto, lucro e ROI
function relatorio_totais(string $dia): array
{
    $db = track_db();
    $tz = fuso();
    $de = (new DateTime($dia . ' 00:00:00', $tz))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $ate = (new DateTime($dia . ' 00:00:00', $tz))->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $fat = $vendas = 0;
    $st = $db->prepare('SELECT * FROM vendas WHERE recebida_em >= ? AND recebida_em < ?');
    $st->execute([$de, $ate]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $v) {
        if (aprovada($v)) {
            $fat += (int)($v['valor_liquido'] ?? $v['valor'] ?? 0);
            $vendas += eh_bump($v) ? 0 : 1;
        }
    }
    $st = $db->prepare('SELECT COALESCE(SUM(gasto), 0) FROM meta_gasto WHERE dia = ?');
    $st->execute([$dia]);
    $gasto = (int)$st->fetchColumn();
    $pct = track_config()['meta_imposto_pct'] ?? 12.15;
    $imposto = (int)round($gasto * (is_numeric($pct) ? (float)$pct : 12.15) / 100);
    return ['fat' => $fat, 'vendas' => $vendas, 'gasto' => $gasto, 'imposto' => $imposto, 'lucro' => $fat - $gasto - $imposto,
        'roi' => $gasto + $imposto ? $fat / ($gasto + $imposto) : null];
}

// Texto do relatorio de um horario, no estilo escolhido: "lucro" (curto), "detalhado" (todos
// os numeros) ou "criativo" (frase de cada horario, com o lucro e as vendas)
function relatorio_msg(array $t, string $padrao, int $hora): array
{
    $lucrou = $t['lucro'] >= 0;
    $valor = reais(abs($t['lucro']));
    $vendas = $t['vendas'] . ' venda' . ($t['vendas'] === 1 ? '' : 's');
    if ($padrao === 'criativo') {
        [$titulo, $corpo] = match ($hora) {
            8 => ['Bom dia!', $lucrou ? 'Ontem rendeu ' . $valor . ' de lucro com ' . $vendas . '. Bora repetir?' : 'Ontem ficou ' . $valor . ' no vermelho. Hoje é dia de virar o jogo.'],
            12 => ['Hora do almoço', $lucrou ? 'Meio-dia e o caixa já tem ' . $valor . ' de lucro (' . $vendas . ').' : 'Meio-dia com ' . $valor . ' no vermelho. A tarde decide.'],
            18 => ['Fim de tarde', $lucrou ? $valor . ' de lucro até agora, com ' . $vendas . '. A noite costuma vender bem.' : $valor . ' no vermelho até agora. Ainda dá tempo de virar.'],
            default => [$lucrou ? 'Mandou bem!' : 'Fechamento do dia', $lucrou ? 'O dia está fechando com ' . $valor . ' de lucro e ' . $vendas . '.' : 'O dia está fechando com ' . $valor . ' no vermelho. Amanhã a gente ajusta.'],
        };
    } elseif ($padrao === 'detalhado') {
        $titulo = $hora === 8 ? 'Resumo de ontem' : ($hora === 23 ? 'Resumo do dia' : 'Parcial das ' . $hora . 'h');
        $corpo = 'Faturamento ' . reais($t['fat']) . ' · Gasto ' . reais($t['gasto']) . ' · Lucro ' . reais($t['lucro'])
            . ($t['roi'] !== null ? ' · ROI ' . number_format($t['roi'], 2, ',', '.') : '') . ' · ' . $t['vendas'] . ' venda' . ($t['vendas'] === 1 ? '' : 's');
    } elseif ($hora === 8) {
        $titulo = 'Bom dia!';
        $corpo = $lucrou ? 'Ontem você lucrou ' . reais($t['lucro']) . '.' : 'Ontem fechou com prejuízo de ' . reais(-$t['lucro']) . '.';
    } elseif ($hora === 23) {
        $titulo = $lucrou ? 'Parabéns!' : 'O dia está terminando';
        $corpo = $lucrou ? 'O dia está terminando e você lucrou ' . reais($t['lucro']) . '!' : 'O dia está terminando com prejuízo de ' . reais(-$t['lucro']) . '.';
    } else {
        $titulo = 'Parcial das ' . $hora . 'h';
        $corpo = $lucrou ? 'Até agora você lucrou ' . reais($t['lucro']) . ' hoje.' : 'Até agora o dia está com prejuízo de ' . reais(-$t['lucro']) . '.';
    }
    return ['titulo' => $titulo, 'corpo' => $corpo, 'url' => './?aba=geral&periodo=' . ($hora === 8 ? 'ontem' : 'hoje'), 'tag' => 'relatorio-' . $hora];
}

// Manda o relatorio do horario, uma vez por dia e horario, para quem escolheu esse horario
function relatorio_enviar_se_hora(?int $horaForcada = null): int
{
    $agora = new DateTime('now', fuso());
    $hora = $horaForcada ?? (int)$agora->format('G');
    if (!in_array($hora, PUSH_HORAS, true) || (!$horaForcada && (int)$agora->format('i') >= 30)) {
        return 0;
    }
    $chave = 'relatorio:' . $agora->format('Y-m-d') . ':' . $hora;
    if (ajuste($chave)) {
        return 0;
    }
    definir_ajuste($chave, agora_utc());
    track_db()->prepare("DELETE FROM ajustes WHERE chave LIKE 'relatorio:%' AND chave < ?")
        ->execute(['relatorio:' . (clone $agora)->modify('-7 days')->format('Y-m-d')]);
    $dia = $hora === 8 ? (clone $agora)->modify('-1 day')->format('Y-m-d') : $agora->format('Y-m-d');
    $t = relatorio_totais($dia);
    return push_para_usuarios(fn($p) => in_array($hora, $p['relatorio_horas'], true) ? push_com_nome(relatorio_msg($t, $p['relatorio_padrao'], $hora), $p) : null);
}
