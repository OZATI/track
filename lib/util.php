<?php
// Utilitarios do painel: saida segura, horario, IP, aparelho, limites e sessao.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// Tudo que vai para o HTML passa por aqui (XSS)
function e($valor): string
{
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function responder_json(int $status, array $corpo): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Texto de fora (pagina, webhook): so string, sem caractere de controle, com tamanho maximo
function texto($valor, int $max): string
{
    if (!is_string($valor) && !is_int($valor) && !is_float($valor)) {
        return '';
    }
    $t = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string)$valor) ?? '');
    return mb_substr($t, 0, $max);
}

// Horario guardado sempre em UTC; a tela converte para o fuso da configuracao
function agora_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

function fuso(): DateTimeZone
{
    $cfg = track_config();
    try {
        return new DateTimeZone($cfg['fuso'] ?? 'America/Sao_Paulo');
    } catch (Exception $e) {
        return new DateTimeZone('America/Sao_Paulo');
    }
}

function data_local(?string $utc, string $formato = 'd/m H:i:s'): string
{
    if (!$utc) {
        return '';
    }
    $d = new DateTime($utc, new DateTimeZone('UTC'));
    return $d->setTimezone(fuso())->format($formato);
}

// Caminho da pagina como o painel guarda: minusculas, sem "/index.html" e sem a barra do fim
// ("/drivedeprojetos/" e "/drivedeprojetos" sao a mesma pagina). A raiz continua "/".
function pagina_normal(string $caminho): string
{
    $p = strtolower($caminho);
    $p = preg_replace('~/index\.html?$~', '/', $p) ?? $p;
    $p = $p === '/' ? '/' : rtrim($p, '/');
    return $p === '' ? '/' : $p;
}

// Periodos prontos do filtro do topo. Alem deles, o personalizado: "2026-09-25_2026-09-28"
// (do primeiro ao ultimo dia, os dois inclusive), que vai no endereco como os outros.
const PERIODOS = ['hoje' => 'Hoje', 'ontem' => 'Ontem', '7d' => 'Últimos 7 dias', '30d' => 'Últimos 30 dias',
    'mes' => 'Este mês', 'mes_passado' => 'Mês passado', 'tudo' => 'Tudo'];

// Dias do periodo personalizado ("2026-09-25_2026-09-28") ou null
function periodo_datas(string $periodo): ?array
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})_(\d{4})-(\d{2})-(\d{2})$/', $periodo, $m)
        || !checkdate((int)$m[2], (int)$m[3], (int)$m[1]) || !checkdate((int)$m[5], (int)$m[6], (int)$m[4])) {
        return null;
    }
    [$d1, $d2] = explode('_', $periodo);
    return $d1 <= $d2 && $d1 >= '2000-01-01' ? [$d1, $d2] : null;
}

function periodo_valido(string $periodo): bool
{
    return isset(PERIODOS[$periodo]) || periodo_datas($periodo) !== null;
}

// Periodo personalizado a partir das duas datas do filtro (em qualquer ordem) ou null
function periodo_personalizado($de, $ate): ?string
{
    if (!is_string($de) || !is_string($ate)) {
        return null;
    }
    $p = min($de, $ate) . '_' . max($de, $ate);
    return periodo_datas($p) !== null ? $p : null;
}

// Nome do periodo para a tela: "Este mês" ou "25/09 a 28/09"
function periodo_rotulo(string $periodo): string
{
    if (isset(PERIODOS[$periodo])) {
        return PERIODOS[$periodo];
    }
    [$d1, $d2] = periodo_datas($periodo) ?? ['', ''];
    $f = fn(string $d) => (new DateTime($d))->format(substr($d1, 0, 4) === substr($d2, 0, 4) ? 'd/m' : 'd/m/Y');
    return $d1 === '' ? '' : ($d1 === $d2 ? $f($d1) : $f($d1) . ' a ' . $f($d2));
}

// Primeiro e ultimo dia do periodo no fuso ("Tudo": do comeco ao fim dos tempos)
function periodo_dias(string $periodo): array
{
    if ($periodo === 'tudo') {
        return ['0000-01-01', '9999-12-31'];
    }
    [$de, $ate] = periodo_utc($periodo);
    $utc = new DateTimeZone('UTC');
    $d1 = (new DateTime($de, $utc))->setTimezone(fuso());
    $d2 = (new DateTime($ate, $utc))->setTimezone(fuso())->modify('-1 day');
    return [$d1->format('Y-m-d'), $d2->format('Y-m-d')];
}

// Periodo da tela (um de PERIODOS ou o personalizado) em limites UTC
function periodo_utc(string $periodo): array
{
    $tz = fuso();
    $inicioHoje = new DateTime('today', $tz);
    $datas = periodo_datas($periodo);
    if ($datas) {
        $de = new DateTime($datas[0], $tz);
        $ate = (new DateTime($datas[1], $tz))->modify('+1 day');
        $periodo = 'personalizado';
    }
    switch ($periodo) {
        case 'personalizado':
            break;
        case 'ontem':
            $de = (clone $inicioHoje)->modify('-1 day');
            $ate = $inicioHoje;
            break;
        case '7d':
            $de = (clone $inicioHoje)->modify('-6 days');
            $ate = (clone $inicioHoje)->modify('+1 day');
            break;
        case '30d':
            $de = (clone $inicioHoje)->modify('-29 days');
            $ate = (clone $inicioHoje)->modify('+1 day');
            break;
        case 'mes':
            $de = (clone $inicioHoje)->modify('first day of this month');
            $ate = (clone $inicioHoje)->modify('+1 day');
            break;
        case 'mes_passado':
            $de = (clone $inicioHoje)->modify('first day of last month');
            $ate = (clone $inicioHoje)->modify('first day of this month');
            break;
        case 'tudo':
            return ['1970-01-01 00:00:00', '2999-12-31 23:59:59'];
        default: // hoje
            $de = $inicioHoje;
            $ate = (clone $inicioHoje)->modify('+1 day');
    }
    $utc = new DateTimeZone('UTC');
    return [$de->setTimezone($utc)->format('Y-m-d H:i:s'), $ate->setTimezone($utc)->format('Y-m-d H:i:s')];
}

// IP para SEGURANCA (chave dos limites de tentativa): o da conexao. O
// X-Forwarded-For e escrito por quem faz o pedido; confiar nele deixava trocar de
// "IP" a cada tentativa e passar pelo limite do login. So vale quando a conexao vem
// de um proxy listado em 'proxies' na configuracao (ex.: um CDN na frente do
// painel), e entao conta o ultimo IP antes dos proxies conhecidos.
function ip_conexao(): string
{
    $remoto = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $cfg = track_config();
    $proxies = is_array($cfg['proxies'] ?? null) ? $cfg['proxies'] : [];
    if ($proxies && in_array($remoto, $proxies, true)) {
        $cadeia = array_reverse(array_map('trim', explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))));
        foreach ($cadeia as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) && !in_array($ip, $proxies, true)) {
                return $ip;
            }
        }
    }
    return $remoto;
}

// IP do visitante para EXIBIR (aparelho, IP parcial). Atras do CDN da Hostinger o
// REMOTE_ADDR pode ser do CDN; o X-Forwarded-For traz o do visitante. Nao use para
// seguranca: quem faz o pedido escreve o X-Forwarded-For (use ip_conexao()).
function ip_cliente(): string
{
    $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($xff !== '') {
        $primeiro = trim(explode(',', $xff)[0]);
        if (filter_var($primeiro, FILTER_VALIDATE_IP)) {
            return $primeiro;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// LGPD: guarda o IP parcial (IPv4 sem o ultimo numero, IPv6 so o prefixo /48).
// Da para ver operadora e regiao, nao a casa da pessoa.
function ip_parcial(string $ip): string
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $p = explode('.', $ip);
        return $p[0] . '.' . $p[1] . '.' . $p[2] . '.0';
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $bin = inet_pton($ip);
        return inet_ntop(substr($bin, 0, 6) . str_repeat("\0", 10)) . '/48';
    }
    return '';
}

// Aparelho, sistema e navegador a partir do user agent (o suficiente para conferencia)
function aparelho(string $ua): array
{
    $dispositivo = 'Computador';
    $sistema = 'Outro';
    if (preg_match('/iPhone/i', $ua)) {
        $dispositivo = 'iPhone';
    } elseif (preg_match('/iPad/i', $ua)) {
        $dispositivo = 'iPad';
    } elseif (preg_match('/Android/i', $ua)) {
        $dispositivo = preg_match('/Mobile/i', $ua) ? 'Android' : 'Tablet Android';
    }
    if (preg_match('/OS (\d+)[._](\d+)/', $ua, $m) && preg_match('/iPhone|iPad/i', $ua)) {
        $sistema = 'iOS ' . $m[1] . '.' . $m[2];
    } elseif (preg_match('/Android (\d+(\.\d+)?)/', $ua, $m)) {
        $sistema = 'Android ' . $m[1];
    } elseif (preg_match('/Windows/i', $ua)) {
        $sistema = 'Windows';
    } elseif (preg_match('/Mac OS X/i', $ua)) {
        $sistema = 'macOS';
    } elseif (preg_match('/Linux/i', $ua)) {
        $sistema = 'Linux';
    }
    $navegador = 'Outro';
    $regras = [
        '/Instagram/i' => 'Instagram (app)',
        '/FBAN|FBAV|FB_IAB/i' => 'Facebook (app)',
        '/WhatsApp/i' => 'WhatsApp (app)',
        '/Edg\//' => 'Edge',
        '/SamsungBrowser/' => 'Samsung',
        '/OPR\/|Opera/' => 'Opera',
        '/CriOS|Chrome\//' => 'Chrome',
        '/FxiOS|Firefox\//' => 'Firefox',
        '/Safari\//' => 'Safari',
    ];
    foreach ($regras as $re => $nome) {
        if (preg_match($re, $ua)) {
            $navegador = $nome;
            break;
        }
    }
    return [$dispositivo, $sistema, $navegador];
}

// "Mesmo site" = mesmo dominio registravel (engdesk.pro e track.engdesk.pro).
// Cookie de mesmo site o Safari aceita; de site diferente, bloqueia.
function dominio_registravel(string $host): string
{
    $host = strtolower(preg_replace('/^www\./', '', $host));
    $partes = explode('.', $host);
    $n = count($partes);
    if ($n <= 2) {
        return $host;
    }
    $duplos = ['com.br', 'net.br', 'org.br', 'gov.br', 'edu.br', 'art.br', 'blog.br', 'eco.br', 'co.uk', 'com.pt', 'com.ar', 'com.mx'];
    $ultimos2 = $partes[$n - 2] . '.' . $partes[$n - 1];
    if (in_array($ultimos2, $duplos, true) && $n >= 3) {
        return $partes[$n - 3] . '.' . $ultimos2;
    }
    return $ultimos2;
}

// Limite de requisicoes por chave (IP + rota), numa janela de segundos
function dentro_do_limite(string $chave, int $maximo, int $janela): bool
{
    $db = track_db();
    $desde = time() - $janela;
    $st = $db->prepare('SELECT COUNT(*) FROM limites WHERE chave = ? AND em >= ?');
    $st->execute([$chave, $desde]);
    if ((int)$st->fetchColumn() >= $maximo) {
        return false;
    }
    $db->prepare('INSERT INTO limites (chave, em) VALUES (?, ?)')->execute([$chave, time()]);
    return true;
}

// Para o login: consulta sem registrar (so a tentativa errada conta, com registrar_tentativa)
function limite_atingido(string $chave, int $maximo, int $janela): bool
{
    $st = track_db()->prepare('SELECT COUNT(*) FROM limites WHERE chave = ? AND em >= ?');
    $st->execute([$chave, time() - $janela]);
    return (int)$st->fetchColumn() >= $maximo;
}

function registrar_tentativa(string $chave): void
{
    track_db()->prepare('INSERT INTO limites (chave, em) VALUES (?, ?)')->execute([$chave, time()]);
}

// Apaga o que passou do prazo de retencao (LGPD). Roda de vez em quando, no meio das coletas.
function limpar_antigos(): void
{
    $cfg = track_config();
    $dias = max(7, (int)($cfg['dias_retencao'] ?? 90));
    $corte = gmdate('Y-m-d H:i:s', time() - $dias * 86400);
    $db = track_db();
    $db->prepare('DELETE FROM eventos WHERE em < ?')->execute([$corte]);
    $db->prepare('DELETE FROM vendas WHERE recebida_em < ?')->execute([$corte]);
    $db->prepare('DELETE FROM visitantes WHERE visto_em < ?')->execute([$corte]);
    $db->prepare('DELETE FROM limites WHERE em < ?')->execute([time() - 86400]);
}

function https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function sessao_iniciar(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('track_sessao');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

// Logado = sessao aberta por um usuario que ainda existe (remover o usuario derruba a sessao)
function logado(): bool
{
    return usuario_atual() !== null;
}

function usuario_atual(): ?string
{
    sessao_iniciar();
    $u = $_SESSION['track_usuario'] ?? null;
    return !empty($_SESSION['track_ok']) && is_string($u) && isset(track_usuarios()[$u]) ? $u : null;
}

// Nome de usuario: minusculas, numeros, ponto, hifen e sublinhado
function usuario_valido(string $u): bool
{
    return (bool)preg_match('/^[a-z0-9][a-z0-9._-]{1,39}$/', $u);
}

// Para onde ir depois do login: so caminho do proprio site (sem host, sem //), senao ./
function destino_seguro(string $volta): string
{
    if ($volta !== '' && strlen($volta) <= 1500 && preg_match('#^(/(?![/\\\\])|\.\./|\./)[^\s\\\\]*$#', $volta)) {
        return $volta;
    }
    return './';
}

// Aviso de uma tela para a proxima (depois de um POST que redireciona): [texto, 'ok' ou 'erro']
function aviso_definir(string $texto, string $tipo = 'ok'): void
{
    sessao_iniciar();
    $_SESSION['track_aviso'] = [$texto, $tipo === 'erro' ? 'erro' : 'ok'];
}

function aviso_pegar(): ?array
{
    sessao_iniciar();
    $a = $_SESSION['track_aviso'] ?? null;
    unset($_SESSION['track_aviso']);
    return is_array($a) ? $a : null;
}

// $acesso: o que a tela pede (padrao: o painel UTM; null = so ter entrado, como Configuracoes)
function exigir_login(?string $acesso = 'utm'): void
{
    if (!track_config()) {
        header('Location: instalar.php');
        exit;
    }
    if (!logado()) {
        header('Location: entrar.php');
        exit;
    }
    if ($acesso !== null && !usuario_pode($acesso)) {
        // Usuario so do CMS que abriu o UTM: vai para o CMS
        $cms = (track_config() ?? [])['menu_cms'] ?? '';
        if ($acesso === 'utm' && $cms !== '' && usuario_pode('cms')) {
            header('Location: ' . $cms);
            exit;
        }
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Sem acesso</title>'
            . '<body style="font:15px/1.5 system-ui,sans-serif;max-width:480px;margin:15vh auto;padding:0 20px">'
            . '<h1 style="font-size:20px">Sem acesso a esta parte do admin</h1><p>O seu usuário não tem acesso a ' . e(TRACK_ACESSOS[$acesso] ?? $acesso)
            . '. Peça a quem cuida dos usuários para liberar.</p><p><a href="' . e(admin_url('conta')) . '">Minha conta</a></p>'
            . '<form method="post" action="' . e(admin_base()) . 'sair.php"><input type="hidden" name="csrf" value="' . e(token_csrf()) . '"><button type="submit">Sair</button></form>';
        exit;
    }
}

function token_csrf(): string
{
    sessao_iniciar();
    if (empty($_SESSION['track_csrf'])) {
        $_SESSION['track_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['track_csrf'];
}

function csrf_valido(): bool
{
    sessao_iniciar();
    // Formulario manda no campo csrf; chamada JSON (fetch), no cabecalho X-CSRF
    $enviado = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
    return is_string($enviado) && !empty($_SESSION['track_csrf']) && hash_equals($_SESSION['track_csrf'], $enviado);
}
