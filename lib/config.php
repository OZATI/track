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
function track_config(bool $recarregar = false): ?array
{
    static $cfg = false;
    if ($cfg === false || $recarregar) {
        $arq = track_arquivo_config();
        $cfg = is_file($arq) ? (require $arq) : null;
    }
    return is_array($cfg) ? $cfg : null;
}

// Quem entra no painel: ['usuario' => hash da senha]. Configuracao antiga, de uma
// senha so (senha_hash), vale como o usuario "admin".
function track_usuarios(): array
{
    $cfg = track_config() ?? [];
    if (!empty($cfg['usuarios']) && is_array($cfg['usuarios'])) {
        return $cfg['usuarios'];
    }
    return !empty($cfg['senha_hash']) ? ['admin' => $cfg['senha_hash']] : [];
}

// Grava a configuracao inteira. Arquivo temporario + rename: quem estiver lendo nunca
// pega o arquivo pela metade.
function track_salvar_config(array $cfg): bool
{
    $dir = track_pasta_dados();
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
        return false;
    }
    // Se a pasta de dados ficou dentro do site (teste local), bloqueia na web
    if (!is_file($dir . DIRECTORY_SEPARATOR . '.htaccess')) {
        @file_put_contents($dir . DIRECTORY_SEPARATOR . '.htaccess', "Require all denied
");
    }
    $arq = track_arquivo_config();
    $tmp = $arq . '.tmp';
    $conteudo = "<?php
// Gerado pelo painel. Nao versionar.
return " . var_export($cfg, true) . ";
";
    if (@file_put_contents($tmp, $conteudo, LOCK_EX) === false || !@rename($tmp, $arq)) {
        @unlink($tmp);
        return false;
    }
    @chmod($arq, 0640);
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($arq, true);
    }
    track_config(true);
    return true;
}
