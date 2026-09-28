<?php
// Onde ficam os dados e a configuracao do painel: FORA da pasta publica.
//
// Na Hostinger o painel fica em .../public_html/<pasta do subdominio>, e o deploy por
// Git pode reescrever essa pasta. Os dados vao para .../track-dados, ao lado do
// public_html, onde nenhum dominio publica nada e nenhum deploy mexe.
// Em outro servidor, defina a variavel de ambiente TRACK_DADOS com o caminho.

function track_pasta_dados(): string
{
    $env = getenv('TRACK_DADOS');
    if ($env) {
        return rtrim($env, '/\\');
    }
    $dir = __DIR__;
    for ($i = 0; $i < 8; $i++) {
        $pai = dirname($dir);
        if ($pai === $dir) {
            break;
        }
        $dir = $pai;
        if (basename($dir) === 'public_html') {
            return dirname($dir) . DIRECTORY_SEPARATOR . 'track-dados';
        }
    }
    // Sem public_html acima (teste local): pasta dados/ dentro do projeto, bloqueada no .htaccess
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'dados';
}

function track_arquivo_config(): string
{
    return track_pasta_dados() . DIRECTORY_SEPARATOR . 'config.php';
}

// null = painel ainda nao instalado (instalar.php cria o arquivo)
function track_config(): ?array
{
    static $cfg = false;
    if ($cfg === false) {
        $arq = track_arquivo_config();
        $cfg = is_file($arq) ? (require $arq) : null;
    }
    return is_array($cfg) ? $cfg : null;
}
