<?php
// Nucleo do admin, a parte logica. Vale para o painel UTM, para o CMS do site e para os outros
// modulos: quem pode abrir o que (os acessos de cada usuario), a regra da senha e os enderecos das
// telas do nucleo (Minha conta e Usuarios). A parte visual (barra lateral, CSS comum e o
// cabecalho do nucleo) fica em lib/admin_tela.php.
//
// Enderecos: num admin com as pastas conta/ e usuarios/ ao lado da pasta do painel (ex.:
// admin.engdesk.pro/conta/), as telas do nucleo moram la. O index.php de cada pasta so define
// TRACK_BASE (o caminho ate a pasta do painel, ex.: '../utm/') e inclui a tela daqui. Sem essas
// pastas (painel avulso), elas ficam no proprio painel: conta.php e usuarios.php.

// O que cada usuario pode abrir no admin: os paineis (UTM, DRE, a BIO e, quando o painel mora num
// admin com CMS, o CMS), "usuarios" (dar e tirar acessos) e "integracoes" (ligar as contas de fora:
// Meta, Google, Kiwify, Instagram). Fica em $cfg['acessos'][usuario]; usuario sem a lista (os de
// antes) pode tudo.
const TRACK_ACESSOS = ['cms' => 'CMS', 'utm' => 'UTM', 'usuarios' => 'Usuários', 'bio' => 'BIO', 'financeiro' => 'DRE', 'integracoes' => 'Integrações'];

// Acesso novo para quem ja tinha o que ele substitui. Ate 07/10/2026 as chaves (API Kiwify, Meta e
// Instagram) abriam com o acesso UTM; agora ficam em Integracoes. Quem tinha UTM numa lista propria
// ganha Integracoes uma vez (config "acessos_v" = 2), para ninguem perder o que podia. Depois disso,
// tirar Integracoes de alguem vale.
function track_acessos_migrar(): void
{
    $cfg = track_config();
    if (!$cfg || (int)($cfg['acessos_v'] ?? 1) >= 2) {
        return;
    }
    foreach ((array)($cfg['acessos'] ?? []) as $u => $lista) {
        if (is_array($lista) && in_array('utm', $lista, true) && !in_array('integracoes', $lista, true)) {
            $cfg['acessos'][$u][] = 'integracoes';
        }
    }
    $cfg['acessos_v'] = 2;
    track_salvar_config($cfg);
}

// Os acessos que existem neste admin: o CMS so quando ha o link dele (config "menu_cms")
function track_acessos(): array
{
    $a = TRACK_ACESSOS;
    if (((track_config() ?? [])['menu_cms'] ?? '') === '') {
        unset($a['cms']);
    }
    return $a;
}

// Lista do que o usuario pode abrir (todos os acessos que existem, se nao houver lista)
function usuario_acessos(string $u): array
{
    $lista = (track_config() ?? [])['acessos'][$u] ?? null;
    return is_array($lista) ? array_values(array_intersect(array_keys(track_acessos()), $lista)) : array_keys(track_acessos());
}

function usuario_pode(string $acesso, ?string $u = null): bool
{
    $u = $u ?? (function_exists('usuario_atual') ? usuario_atual() : null);
    if ($u === null) {
        return false;
    }
    $lista = (track_config() ?? [])['acessos'][$u] ?? null;
    return !is_array($lista) || in_array($acesso, $lista, true);
}

// Caminho da tela atual ate a pasta do painel: '' dentro dele, '../utm/' nas telas do nucleo
// que moram na raiz do admin
function admin_base(): string
{
    return defined('TRACK_BASE') ? (string)TRACK_BASE : '';
}

// O site tem as telas do nucleo na raiz do admin (a pasta conta/ ao lado da pasta do painel)?
function admin_nucleo_na_raiz(): bool
{
    static $r = null;
    return $r ?? ($r = is_file(dirname(__DIR__, 2) . '/conta/index.php'));
}

// Endereco de uma tela do nucleo ('conta' ou 'usuarios') a partir da tela atual. $base: o
// caminho ate a pasta do painel (padrao: o da tela atual, admin_base())
function admin_url(string $tela, ?string $base = null): string
{
    return admin_caminho($base ?? admin_base(), $tela, admin_nucleo_na_raiz());
}

// A conta do endereco (separada para o teste): na raiz, uma pasta acima do painel; avulso, o
// arquivo do painel. "utm/../conta/" vira "conta/".
function admin_caminho(string $base, string $tela, bool $naRaiz): string
{
    $url = $naRaiz ? $base . '../' . $tela . '/' : $base . $tela . '.php';
    do {
        $url = (string)preg_replace('~(^|/)(?!\.\./)[^/]+/\.\./~', '$1', $url, 1, $n);
    } while ($n);
    return $url;
}

// Regra da senha (criar acesso, trocar a senha, instalar): pelo menos 10 caracteres e diferente
// do usuario. Devolve o problema, para mostrar, ou null.
function senha_problema(string $senha, string $usuario): ?string
{
    if (mb_strlen($senha) < 10) {
        return 'A senha precisa ter pelo menos 10 caracteres.';
    }
    if ($usuario !== '' && mb_strtolower(trim($senha)) === mb_strtolower(trim($usuario))) {
        return 'A senha não pode ser igual ao usuário.';
    }
    return null;
}
