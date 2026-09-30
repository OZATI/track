<?php
// Teste da criptografia do Web Push (lib/push.php), usado pelo tests/fluxo.sh: cifra como o
// servidor, decifra como o navegador (RFC 8291) e confere a assinatura do JWT do VAPID.
// Imprime "ok" em cada conferencia ou "FALHA".

require __DIR__ . '/../lib/util.php';
require __DIR__ . '/../lib/push.php';

$saida = [];
$confere = function (bool $ok, string $o) use (&$saida) {
    $saida[] = ($ok ? 'ok ' : 'FALHA ') . $o;
};

// "Navegador": par de chaves e segredo de autenticacao
[$pemUa, $uaPub] = push_novo_par();
$auth = random_bytes(16);
$texto = json_encode(['titulo' => 'Venda aprovada! | Drive de Projetos', 'corpo' => 'R$ 67,00 · Instagram · anúncio'], JSON_UNESCAPED_UNICODE);
$corpo = push_cifrar($texto, b64u($uaPub), b64u($auth));

// Decifra como o navegador faria
$sal = substr($corpo, 0, 16);
$rs = unpack('N', substr($corpo, 16, 4))[1];
$idlen = ord($corpo[20]);
$asPub = substr($corpo, 21, $idlen);
$cifrado = substr($corpo, 21 + $idlen, -16);
$tag = substr($corpo, -16);
$segredo = openssl_pkey_derive(openssl_pkey_get_public(push_pem_publica($asPub)), openssl_pkey_get_private($pemUa), 32);
$ikm = hash_hkdf('sha256', $segredo, 32, "WebPush: info\0" . $uaPub . $asPub, $auth);
$cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $sal);
$nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $sal);
$claro = openssl_decrypt($cifrado, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
$confere($rs === 4096 && $idlen === 65, 'cabecalho aes128gcm (rs 4096, chave de 65 bytes)');
$confere($claro === $texto . "\x02", 'mensagem cifrada volta igual, com o delimitador de ultimo registro');

// Chave publica em PEM le de volta a mesma chave
$det = openssl_pkey_get_details(openssl_pkey_get_public(push_pem_publica($uaPub)))['ec'];
$confere("\x04" . str_pad($det['x'], 32, "\0", STR_PAD_LEFT) . str_pad($det['y'], 32, "\0", STR_PAD_LEFT) === $uaPub, 'chave publica crua em PEM e de volta');

// JWT do VAPID: assinatura ES256 de 64 bytes que confere com a chave publica
[$pemV, $pubV] = push_novo_par();
$jwt = push_jwt('https://fcm.googleapis.com/fcm/send/abc', ['privada' => $pemV, 'publica' => b64u($pubV)]);
[$a, $b, $c] = explode('.', $jwt);
$sig = b64u_dec($c);
$r = ltrim(substr($sig, 0, 32), "\0");
$s = ltrim(substr($sig, 32), "\0");
$int = fn($x) => "\x02" . chr(strlen((ord($x[0]) & 0x80) ? "\0" . $x : $x)) . ((ord($x[0]) & 0x80) ? "\0" . $x : $x);
$der = "\x30" . chr(strlen($int($r) . $int($s))) . $int($r) . $int($s);
$claims = json_decode(b64u_dec($b), true);
$confere(strlen($sig) === 64, 'assinatura do JWT com 64 bytes (r e s)');
$confere(openssl_verify($a . '.' . $b, $der, push_pem_publica($pubV), OPENSSL_ALGO_SHA256) === 1, 'assinatura do JWT confere com a chave publica do VAPID');
$confere(($claims['aud'] ?? '') === 'https://fcm.googleapis.com' && ($claims['exp'] ?? 0) > time(), 'JWT para o servico certo (aud) e ainda valido (exp)');

// So servicos de push dos navegadores
putenv('TRACK_PUSH_HOST');
$lista = ['https://fcm.googleapis.com/fcm/send/x', 'https://web.push.apple.com/x', 'https://updates.push.services.mozilla.com/wpush/v2/x',
    'https://wns2-by3p.notify.windows.com/w/?token=x', 'http://fcm.googleapis.com/x', 'https://evil.test/x', 'https://fcm.googleapis.com.evil.test/x'];
$confere(implode('', array_map(fn($u) => push_endpoint_ok($u) ? 's' : 'n', $lista)) === 'ssssnnn', 'endereco de push so dos servicos dos navegadores, em HTTPS');

echo implode("\n", $saida), "\n";
