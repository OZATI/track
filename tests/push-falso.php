<?php
// Servico de push falso, usado pelo tests/fluxo.sh no lugar do servico do navegador (Google,
// Apple, Mozilla). Decifra a mensagem com a chave do "aparelho" de teste e anota numa linha
// JSON por envio: caminho, cabecalhos do VAPID e da cifra, e o titulo e o corpo decifrados.
// /push/410 responde 410 (inscricao cancelada pelo navegador); o resto, 201.
//
// Pasta do log e da chave do aparelho: PUSH_FALSO_DIR (push.log e aparelho.json).

$dir = (string)getenv('PUSH_FALSO_DIR');
$caminho = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$corpo = (string)file_get_contents('php://input');
$h = array_change_key_case(getallheaders(), CASE_LOWER);

$msg = null;
$ap = json_decode((string)@file_get_contents($dir . '/aparelho.json'), true);
if (is_array($ap) && strlen($corpo) > 37) {
    $ua = openssl_pkey_get_private($ap['pem']);
    $uaPub = base64_decode(strtr($ap['p256dh'], '-_', '+/'));
    $auth = base64_decode(strtr($ap['auth'], '-_', '+/'));
    $sal = substr($corpo, 0, 16);
    $idlen = ord($corpo[20]);
    $asPub = substr($corpo, 21, $idlen);
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $asPub;
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    $segredo = openssl_pkey_derive(openssl_pkey_get_public($pem), $ua, 32);
    if ($segredo !== false) {
        $ikm = hash_hkdf('sha256', $segredo, 32, "WebPush: info\0" . $uaPub . $asPub, $auth);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $sal);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $sal);
        $claro = openssl_decrypt(substr($corpo, 21 + $idlen, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($corpo, -16));
        $msg = $claro === false ? null : json_decode(rtrim($claro, "\x02"), true);
    }
}

$linha = [
    'caminho' => $caminho,
    'vapid' => (bool)preg_match('/^vapid t=[\w-]+\.[\w-]+\.[\w-]+, k=[\w-]{87}$/', $h['authorization'] ?? ''),
    'cifra' => $h['content-encoding'] ?? '',
    'ttl' => $h['ttl'] ?? '',
    'titulo' => $msg['titulo'] ?? '(nao decifrou)',
    'corpo' => $msg['corpo'] ?? '',
];
file_put_contents($dir . '/push.log', json_encode($linha, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
http_response_code(substr($caminho, -4) === '/410' ? 410 : 201);
