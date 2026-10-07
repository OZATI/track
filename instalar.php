<?php
// Primeira configuracao do painel. Cria a senha, a chave do webhook e a lista de sites
// autorizados, e grava tudo em track-dados/config.php, FORA da pasta publica.
//
// Seguranca: so funciona enquanto nao existe configuracao, e so por 1 hora depois do
// deploy (hora deste arquivo no servidor). Passou o prazo sem instalar: salve este arquivo
// de novo no servidor (reabre por 1 hora), ou crie o config.php a mao (modelo no README).

require __DIR__ . '/lib/util.php';
require __DIR__ . '/lib/layout.php';

sessao_iniciar(); // antes de qualquer HTML (o token do formulario depende da sessao)

if (track_config()) {
    http_response_code(404);
    pagina_inicio('Já instalado');
    echo '<main class="caixa-login"><h1>Painel já instalado</h1><p><a href="./">Entrar no painel</a></p></main>';
    pagina_fim();
    exit;
}
if (time() - (int)filemtime(__FILE__) > 3600 && !getenv('TRACK_DADOS')) {
    http_response_code(403);
    pagina_inicio('Instalação expirada');
    echo '<main class="caixa-login"><h1>Instalação expirada</h1><p>A instalação só fica aberta por 1 hora depois que este arquivo chega ao servidor. Abra o <code>instalar.php</code> no Gerenciador de Arquivos e salve sem mudar nada (reabre por 1 hora), ou crie o <code>config.php</code> à mão (README).</p></main>';
    pagina_fim();
    exit;
}

$erros = [];
$pronto = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $usuario = strtolower(trim((string)($_POST['usuario'] ?? '')));
    $senha = (string)($_POST['senha'] ?? '');
    $origens = [];
    foreach (preg_split('/\s+/', (string)($_POST['origens'] ?? '')) as $o) {
        $o = rtrim(trim($o), '/');
        if ($o === '') {
            continue;
        }
        if (!preg_match('#^https?://[a-z0-9.-]+(:\d+)?$#i', $o)) {
            $erros[] = 'Endereço inválido: ' . $o . ' (use só https://dominio, sem caminho).';
            continue;
        }
        $origens[] = strtolower($o);
    }
    $dias = (int)($_POST['dias'] ?? 90);
    // Painel dentro de um admin (ex.: admin.engdesk.pro/utm/): link do CMS liga a barra lateral CMS | UTM
    $menuCms = trim((string)($_POST['menu_cms'] ?? ''));
    if ($menuCms !== '' && !preg_match('#^(https://[a-z0-9.-]+(/[^\s"<>]*)?|/[^\s"<>]*|\.\./[^\s"<>]*)$#i', $menuCms)) {
        $erros[] = 'Link do CMS inválido (use https://..., /caminho ou ../).';
    }

    if (!csrf_valido()) {
        $erros[] = 'Sessão expirada. Recarregue a página.';
    }
    if (!usuario_valido($usuario)) {
        $erros[] = 'Usuário: de 2 a 40 letras minúsculas, números, ponto, hífen ou sublinhado.';
    }
    if ($senha === '') {
        $erros[] = 'Defina a senha do painel.';
    }
    if ($senha !== (string)($_POST['senha2'] ?? '')) {
        $erros[] = 'As senhas não conferem.';
    } elseif ($senha !== '' && ($problema = senha_problema($senha, (string)($_POST['usuario'] ?? '')))) {
        $erros[] = $problema;
    }
    if (!$origens) {
        $erros[] = 'Informe pelo menos um site (ex.: https://engdesk.pro).';
    }
    if ($dias < 7 || $dias > 400) {
        $erros[] = 'Retenção entre 7 e 400 dias.';
    }

    if (!$erros) {
        $config = [
            'usuarios' => [$usuario => password_hash($senha, PASSWORD_DEFAULT)],
            'chave_webhook' => bin2hex(random_bytes(24)),
            'origens' => array_values(array_unique($origens)),
            'dias_retencao' => $dias,
            'menu_cms' => $menuCms,
            'fuso' => 'America/Sao_Paulo',
            'criado_em' => agora_utc(),
        ];
        if (!track_salvar_config($config)) {
            $erros[] = 'Não foi possível gravar em ' . track_pasta_dados() . '. Confira as permissões da pasta.';
        } else {
            track_db(); // cria o banco
            $pronto = $config;
        }
    }
}

$host = $_SERVER['HTTP_HOST'] ?? 'track.seu-dominio';
$esquema = https() ? 'https' : 'http';
pagina_inicio('Instalação');
?>
<main class="caixa-login larga">
<?php if ($pronto): ?>
  <h1>Painel instalado</h1>
  <p>Guarde estes dados. A chave do webhook aparece só agora. Para dar acesso a mais alguém, use a aba <strong>Usuários</strong>.</p>
  <h2>1. Webhook da Kiwify</h2>
  <p>Kiwify → Apps → Webhooks → criar, com os eventos <strong>compra aprovada, compra reembolsada, chargeback, Pix gerado e compra recusada</strong>:</p>
  <pre><?= e($esquema . '://' . $host . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\') . '/kiwify.php?chave=' . $pronto['chave_webhook']) ?></pre>
  <h2>2. Nas páginas de venda</h2>
  <p>No fim do <code>&lt;body&gt;</code>, depois do script de atribuição:</p>
  <pre><?= e('<script src="' . $esquema . '://' . $host . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\') . '/t.js" defer></script>') ?></pre>
  <p>Sites autorizados a mandar eventos: <?= e(implode(', ', $pronto['origens'])) ?></p>
  <p><a href="entrar.php">Entrar no painel</a></p>
<?php else: ?>
  <h1>Instalar o painel de rastreio</h1>
  <p>Os dados ficam em <code><?= e(track_pasta_dados()) ?></code>, fora da pasta pública.</p>
  <?php foreach ($erros as $erro): ?><p class="erro"><?= e($erro) ?></p><?php endforeach; ?>
  <form method="post" action="instalar.php">
    <input type="hidden" name="csrf" value="<?= e(token_csrf()) ?>">
    <label>Seu usuário (ex.: kenio) <input type="text" name="usuario" value="<?= e($_POST['usuario'] ?? '') ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required></label>
    <label>Senha <input type="password" name="senha" autocomplete="new-password" required></label>
    <label>Repita a senha <input type="password" name="senha2" autocomplete="new-password" required></label>
    <label>Sites que vão mandar eventos, um por linha
      <textarea name="origens" rows="3" placeholder="https://engdesk.pro" required><?= e($_POST['origens'] ?? '') ?></textarea></label>
    <label>Guardar os dados por quantos dias (LGPD) <input type="number" name="dias" value="<?= e($_POST['dias'] ?? '90') ?>" min="7" max="400"></label>
    <label>Link do CMS (opcional: só se o painel fica dentro de um admin, ex.: ../)
      <input type="text" name="menu_cms" value="<?= e($_POST['menu_cms'] ?? '') ?>" placeholder="../"></label>
    <button type="submit">Instalar</button>
  </form>
<?php endif; ?>
</main>
<?php
pagina_fim();
