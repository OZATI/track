<?php
// Login do painel: uma senha de administrador, definida no instalar.php.

require __DIR__ . '/lib/util.php';
require __DIR__ . '/lib/layout.php';

$cfg = track_config();
if (!$cfg) {
    header('Location: instalar.php');
    exit;
}
if (logado()) {
    header('Location: ./');
    exit;
}

$erro = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $chave = 'login:' . ip_cliente();
    if (!csrf_valido()) {
        $erro = 'Sessão expirada. Tente de novo.';
    } elseif (limite_atingido($chave, 5, 900)) {
        $erro = 'Muitas tentativas. Espere 15 minutos.';
    } elseif (password_verify((string)($_POST['senha'] ?? ''), $cfg['senha_hash'] ?? '')) {
        session_regenerate_id(true);
        $_SESSION['track_ok'] = true;
        header('Location: ./');
        exit;
    } else {
        registrar_tentativa($chave);
        $erro = 'Senha incorreta.';
    }
}

pagina_inicio('Entrar');
?>
<main class="caixa-login">
  <h1>Painel de rastreio</h1>
  <form method="post" action="entrar.php">
    <input type="hidden" name="csrf" value="<?= e(token_csrf()) ?>">
    <label>Senha <input type="password" name="senha" autocomplete="current-password" required autofocus></label>
    <?php if ($erro): ?><p class="erro"><?= e($erro) ?></p><?php endif; ?>
    <button type="submit">Entrar</button>
  </form>
</main>
<?php
pagina_fim();
