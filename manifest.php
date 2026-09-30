<?php
// Manifesto do app (instalar o painel no celular ou no computador). O nome vem de
// Configuracoes; os icones, do site que hospeda o painel (icone-app-*.png na pasta de cima)
// ou os do proprio painel.

require __DIR__ . '/lib/util.php';
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-cache');
$nome = (string)((track_config() ?? [])['app_nome'] ?? 'Painel de vendas');
$doSite = is_file(__DIR__ . '/../icone-app-512.png');
$icone = fn(int $t) => $doSite ? '../icone-app-' . $t . '.png' : 'app-' . $t . '.png';
echo json_encode([
    'name' => $nome,
    'short_name' => mb_substr($nome, 0, 12),
    'description' => 'Vendas, anúncios e rastreio, com notificação de cada venda.',
    'lang' => 'pt-BR',
    'start_url' => './?aba=geral',
    'scope' => './',
    'display' => 'standalone',
    'background_color' => '#F7F8FA',
    'theme_color' => '#FFFFFF',
    'icons' => [
        ['src' => $icone(192), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $icone(512), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $icone(512), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
