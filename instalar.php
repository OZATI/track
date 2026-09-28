<?php
// Primeira configuracao do painel. Cria a senha, a chave do webhook e a lista de sites
// autorizados, e grava tudo em track-dados/config.php, FORA da pasta publica.
//
// Seguranca: so funciona enquanto nao existe configuracao, e so por 1 hora depois do
// deploy (hora deste arquivo no servidor). Passou o prazo sem instalar: faca um novo
// deploy, ou crie o config.php a mao (modelo no README).

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
    echo '<main class="caixa-login"><h1>Instalação expirada</h1><p>A instalação só fica aberta por 1 hora depois do deploy. Faça um novo deploy e abra esta página logo em seguida, ou crie o <code>config.php</code> à mão (README).</p></main>';
    pagina_fim();
    exit;
}

$erros = [];
$pronto = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
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
    if (mb_strlen($senha) < 10) {
        $erros[] = 'A senha precisa de pelo menos 10 caracteres.';
    }
    if ($senha !== (string)($_POST['senha2'] ?? '')) {
        $erros[] = 'As senhas não conferem.';
    }
    if (!$origens) {
        $erros[] = 'Informe pelo menos um site (ex.: https://engdesk.pro).';
    }
    if ($dias < 7 || $dias > 400) {
        $erros[] = 'Retenção entre 7 e 400 dias.';
    }

    if (!$erros) {
        $config = [
            'senha_hash' => password_hash($senha, PASSWORD_DEFAULT),
            'chave_webhook' => bin2hex(random_bytes(24)),
            'origens' => array_values(array_unique($origens)),
            'dias_retencao' => $dias,
            'menu_cms' => $menuCms,
            'fuso' => 'America/Sao_Paulo',
            'criado_em' => agora_utc(),
        ];
        $dir = track_pasta_dados();
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        // Se a pasta de dados ficou dentro do site (teste local), bloqueia na web
        @file_put_contents($dir . DIRECTORY_SEPARATOR . '.htaccess', "Require all denied\n");
        $ok = @file_put_contents(track_arquivo_config(), "<?php\n// Gerado pelo instalar.php. Nao versionar.\nreturn " . var_export($config, true) . ";\n", LOCK_EX);
        if ($ok === false) {
            $erros[] = 'Não foi possível gravar em ' . $dir . '. Confira as permissões da pasta.';
        } else {
            @chmod(track_arquivo_config(), 0640);
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
  <p>Guarde estes dados. A chave do webhook aparece só agora.</p>
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
    <label>Senha do painel (mínimo 10 caracteres) <input type="password" name="senha" autocomplete="new-password" required></label>
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
