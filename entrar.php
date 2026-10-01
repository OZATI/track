<?php
// Login do painel: usuario e senha (usuarios criados no instalar.php e em usuarios.php).
// Dentro de um admin (config menu_cms), este e o login do admin inteiro: o CMS manda
// para ca com ?volta= e confere a mesma sessao.

require __DIR__ . '/lib/util.php';
require __DIR__ . '/lib/layout.php';

$cfg = track_config();
if (!$cfg) {
    header('Location: instalar.php');
    exit;
}
$volta = destino_seguro((string)($_POST['volta'] ?? $_GET['volta'] ?? ''));
if (logado()) {
    header('Location: ' . $volta);
    exit;
}

// Hash de uma senha aleatoria: usuario que nao existe leva o mesmo tempo para ser
// recusado, e a resposta nao entrega quem esta cadastrado.
const HASH_NINGUEM = '$2y$12$39pYEW3u79i1RX0MJ5vxNOELAIjKU5dsG02WFtbFHXg2Yjov9Y/mC';

$erro = '';
$usuario = strtolower(trim((string)($_POST['usuario'] ?? '')));
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // Dois limites: por conexao (5 erros em 15 min) e por usuario (10 em 15 min, de
    // qualquer lugar). O segundo segura quem troca de IP; o preco e que um ataque
    // pode travar o login daquele usuario por 15 minutos.
    $chave = 'login:' . ip_conexao();
    $chaveConta = 'login-conta:' . hash('sha256', $usuario);
    $hash = track_usuarios()[$usuario] ?? null;
    if (!csrf_valido()) {
        $erro = 'Sessão expirada. Tente de novo.';
    } elseif (limite_atingido($chave, 5, 900) || limite_atingido($chaveConta, 10, 900)) {
        $erro = 'Muitas tentativas. Espere 15 minutos.';
    } elseif (password_verify((string)($_POST['senha'] ?? ''), $hash ?? HASH_NINGUEM) && $hash !== null) {
        session_regenerate_id(true);
        $_SESSION['track_ok'] = true;
        $_SESSION['track_usuario'] = $usuario;
        header('Location: ' . $volta);
        exit;
    } else {
        registrar_tentativa($chave);
        registrar_tentativa($chaveConta);
        $erro = 'Usuário ou senha incorretos.';
    }
}

pagina_inicio('Entrar');
?>
<main class="caixa-login">
  <h1><?= !empty($cfg['menu_cms']) ? 'Entrar no admin' : 'Painel de rastreio' ?></h1>
  <form method="post" action="entrar.php">
    <input type="hidden" name="csrf" value="<?= e(token_csrf()) ?>">
    <input type="hidden" name="volta" value="<?= e($volta) ?>">
    <label>Usuário <input type="text" name="usuario" value="<?= e($usuario) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus></label>
    <label>Senha <input type="password" name="senha" autocomplete="current-password" required></label>
    <?php if ($erro): ?><p class="erro"><?= e($erro) ?></p><?php endif; ?>
    <button type="submit">Entrar</button>
  </form>
</main>
<?php
pagina_fim();
