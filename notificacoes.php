<?php
// Inscricao deste aparelho nas notificacoes (lib/push.php). So JSON por POST, com login e o
// cabecalho X-CSRF. Acoes:
//   chave     -> chave publica do painel, para o navegador inscrever o aparelho
//   inscrever -> guarda a inscricao (endereco do servico de push do navegador e as chaves)
//   cancelar  -> apaga a inscricao deste aparelho
//   testar    -> manda uma notificacao de teste para os aparelhos de quem pediu

require __DIR__ . '/lib/util.php';
require_once __DIR__ . '/lib/push.php';

header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    responder_json(405, ['ok' => false]);
}
if (!track_config() || !logado()) {
    responder_json(401, ['ok' => false, 'erro' => 'Sessão expirada. Entre de novo.']);
}
if (!csrf_valido()) {
    responder_json(403, ['ok' => false, 'erro' => 'Sessão expirada. Recarregue a página.']);
}
if (!dentro_do_limite('notificacoes:' . ip_cliente(), 30, 600)) {
    responder_json(429, ['ok' => false, 'erro' => 'Muitas tentativas. Espere alguns minutos.']);
}
$usuario = (string)usuario_atual();
$e = json_decode(file_get_contents('php://input', false, null, 0, 16384) ?: '{}', true);
$e = is_array($e) ? $e : [];
$db = track_db();

switch ((string)($_GET['acao'] ?? '')) {
    case 'chave':
        $v = push_vapid();
        if (!$v) {
            responder_json(500, ['ok' => false, 'erro' => 'O PHP do servidor não conseguiu criar as chaves das notificações (extensão openssl).']);
        }
        responder_json(200, ['ok' => true, 'chave' => $v['publica']]);

    case 'inscrever':
        $endpoint = (string)($e['endpoint'] ?? '');
        $p256dh = (string)($e['keys']['p256dh'] ?? '');
        $auth = (string)($e['keys']['auth'] ?? '');
        if (!push_endpoint_ok($endpoint) || strlen($endpoint) > 1000 || strlen(b64u_dec($p256dh)) !== 65 || strlen(b64u_dec($auth)) !== 16) {
            responder_json(400, ['ok' => false, 'erro' => 'Inscrição inválida. Tente de novo.']);
        }
        // Quem manda as notificacoes (o "sub" do VAPID): o endereco deste painel
        $cfg = track_config();
        if (empty($cfg['push_contato'])) {
            $cfg['push_contato'] = 'https://' . preg_replace('/[^a-z0-9.\-:]/i', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
            track_salvar_config($cfg);
        }
        $db->prepare('INSERT INTO push_inscricoes (usuario, endpoint, p256dh, auth, aparelho, criado_em) VALUES (?, ?, ?, ?, ?, ?)
                      ON CONFLICT (endpoint) DO UPDATE SET usuario = excluded.usuario, p256dh = excluded.p256dh, auth = excluded.auth,
                          aparelho = excluded.aparelho, falhas = 0')
            ->execute([$usuario, $endpoint, $p256dh, $auth, texto($e['aparelho'] ?? '', 80), agora_utc()]);
        responder_json(200, ['ok' => true]);

    case 'cancelar':
        $db->prepare('DELETE FROM push_inscricoes WHERE endpoint = ? AND usuario = ?')->execute([(string)($e['endpoint'] ?? ''), $usuario]);
        responder_json(200, ['ok' => true]);

    case 'testar':
        $aparelhos = push_aparelhos($usuario);
        if (!$aparelhos) {
            responder_json(400, ['ok' => false, 'erro' => 'Nenhum aparelho seu está com as notificações ligadas.']);
        }
        $ok = 0;
        foreach ($aparelhos as $a) {
            $st = push_enviar($a, ['titulo' => 'Notificações ligadas', 'corpo' => 'É assim que as vendas vão chegar neste aparelho.', 'url' => './configuracoes.php', 'tag' => 'teste']);
            $ok += ($st >= 200 && $st < 300) ? 1 : 0;
        }
        responder_json(200, ['ok' => $ok > 0, 'enviadas' => $ok, 'aparelhos' => count($aparelhos),
            'erro' => $ok ? '' : 'O serviço de notificações do navegador recusou o envio. Desligue e ligue de novo neste aparelho.']);

    default:
        responder_json(400, ['ok' => false, 'erro' => 'Ação inválida.']);
}
