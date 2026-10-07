<?php
// Perfil de quem usa o painel: a foto da bolinha no topo (ou a inicial do nome, numa cor fixa
// por usuario). A foto fica na pasta de dados, fora do site, e so sai por avatar.php para quem
// entrou no painel. Com a biblioteca de imagem (GD), corta no centro e reduz para 256 px; sem
// ela, aceita so JPEG pequeno, como as capas do Instagram (lib/instagram_sync.php).

const AVATAR_LADO = 512;
const AVATAR_LIMITE = 65536; // 64 KB: o que a foto pronta pode ter
const AVATAR_MAX_BYTES = 3 * 1024 * 1024;

function avatar_arquivo(string $usuario): string
{
    return track_pasta_dados() . '/avatares/' . $usuario . '.jpg';
}

// Grava a foto enviada. Devolve true ou o motivo de nao ter gravado.
function avatar_salvar(string $usuario, string $tmp): bool|string
{
    if (!usuario_valido($usuario)) {
        return 'usuário inválido';
    }
    $bin = @file_get_contents($tmp);
    if (!is_string($bin) || $bin === '' || strlen($bin) > AVATAR_MAX_BYTES) {
        return 'a foto precisa ter até 3 MB';
    }
    $info = @getimagesizefromstring($bin);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        return 'a foto precisa ser JPEG, PNG ou WebP';
    }
    $arq = avatar_arquivo($usuario);
    if (!is_dir(dirname($arq)) && !@mkdir(dirname($arq), 0750, true)) {
        return 'sem permissão para criar a pasta das fotos';
    }
    $tmpArq = $arq . '.tmp';
    if ($info[2] === IMAGETYPE_JPEG && strlen($bin) <= AVATAR_LIMITE && $info[0] <= 1024 && abs($info[0] - $info[1]) <= 2) {
        // Ja veio pronta do navegador (quadrada, JPEG, ate 64 KB): grava como esta
        $ok = file_put_contents($tmpArq, $bin) !== false;
    } elseif (function_exists('imagecreatefromstring')) {
        $im = @imagecreatefromstring($bin);
        if (!$im) {
            return 'não foi possível abrir a foto';
        }
        // Quadrado do centro, reduzido, e a qualidade baixando ate caber em 64 KB
        $w = imagesx($im);
        $h = imagesy($im);
        $lado = min($w, $h);
        $ok = false;
        foreach ([AVATAR_LADO, 384, 256] as $tam) {
            $novo = imagecreatetruecolor($tam, $tam);
            imagecopyresampled($novo, $im, 0, 0, (int)(($w - $lado) / 2), (int)(($h - $lado) / 2), $tam, $tam, $lado, $lado);
            foreach ([88, 80, 72, 64, 56] as $q) {
                ob_start();
                imagejpeg($novo, null, $q);
                $jpeg = (string)ob_get_clean();
                if (strlen($jpeg) <= AVATAR_LIMITE) {
                    $ok = file_put_contents($tmpArq, $jpeg) !== false;
                    break 2;
                }
            }
            imagedestroy($novo);
        }
        imagedestroy($im);
    } else {
        return 'o servidor não reduz fotos: abra esta tela com o JavaScript ligado (o navegador prepara a foto) ou envie um JPEG quadrado de até 64 KB';
    }
    if (!$ok || !@rename($tmpArq, $arq)) {
        @unlink($tmpArq);
        return 'não foi possível gravar a foto';
    }
    return true;
}

function avatar_apagar(string $usuario): void
{
    if (usuario_valido($usuario)) {
        @unlink(avatar_arquivo($usuario));
    }
}

// A bolinha: a foto, ou a inicial numa cor que vem do nome (sempre a mesma para a pessoa)
function avatar_html(string $usuario, int $tam = 30, string $base = ''): string
{
    $arq = usuario_valido($usuario) ? avatar_arquivo($usuario) : '';
    if ($arq !== '' && is_file($arq)) {
        return '<img class="avatar" src="' . e($base) . 'avatar.php?' . e(http_build_query(['u' => $usuario, 'v' => filemtime($arq)])) . '" width="' . $tam . '" height="' . $tam . '" alt="">';
    }
    $cor = hexdec(substr(md5($usuario), 0, 2)) * 360 / 256;
    return '<span class="avatar avatar-letra" style="--av:hsl(' . (int)$cor . ' 55% 45%);width:' . $tam . 'px;height:' . $tam . 'px;font-size:' . max(12, (int)round($tam * .42)) . 'px" aria-hidden="true">'
        . e(mb_strtoupper(mb_substr($usuario, 0, 1))) . '</span>';
}
