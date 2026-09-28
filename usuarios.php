<?php
// Quem entra no painel. Todo usuario logado pode dar acesso a outra pessoa, tirar o
// acesso de alguem (menos o proprio) e trocar a propria senha. A senha de outra pessoa
// e definida por quem cria o acesso e trocada por ela depois, aqui mesmo.

require __DIR__ . '/lib/util.php';
require __DIR__ . '/lib/layout.php';

exigir_login();
$eu = usuario_atual();
$erros = [];
$aviso = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $cfg = track_config();
    $usuarios = track_usuarios();
    $acao = (string)($_POST['acao'] ?? '');

    if (!csrf_valido()) {
        $erros[] = 'Sessão expirada. Recarregue a página.';
    } elseif ($acao === 'adicionar') {
        $novo = strtolower(trim((string)($_POST['usuario'] ?? '')));
        $senha = (string)($_POST['senha'] ?? '');
        if (!usuario_valido($novo)) {
            $erros[] = 'Usuário: de 2 a 40 letras minúsculas, números, ponto, hífen ou sublinhado.';
        } elseif (isset($usuarios[$novo])) {
            $erros[] = 'Já existe o usuário ' . $novo . '.';
        } elseif ($senha === '' || $senha !== (string)($_POST['senha2'] ?? '')) {
            $erros[] = 'Defina a senha e repita igual.';
        } else {
            $usuarios[$novo] = password_hash($senha, PASSWORD_DEFAULT);
            $aviso = 'Acesso criado para ' . $novo . '. Passe a senha para a pessoa; ela pode trocar aqui depois de entrar.';
        }
    } elseif ($acao === 'remover') {
        $alvo = (string)($_POST['usuario'] ?? '');
        if ($alvo === $eu) {
            $erros[] = 'Você não pode tirar o seu próprio acesso.';
        } elseif (!isset($usuarios[$alvo])) {
            $erros[] = 'Usuário não encontrado.';
        } else {
            unset($usuarios[$alvo]);
            $aviso = 'Acesso de ' . $alvo . ' removido.';
        }
    } elseif ($acao === 'trocar_senha') {
        $nova = (string)($_POST['nova'] ?? '');
        if (!password_verify((string)($_POST['atual'] ?? ''), $usuarios[$eu] ?? '')) {
            $erros[] = 'A senha atual não confere.';
        } elseif ($nova === '' || $nova !== (string)($_POST['nova2'] ?? '')) {
            $erros[] = 'Digite a nova senha e repita igual.';
        } else {
            $usuarios[$eu] = password_hash($nova, PASSWORD_DEFAULT);
            $aviso = 'Senha trocada.';
        }
    } else {
        $erros[] = 'Ação inválida.';
    }

    if (!$erros) {
        unset($cfg['senha_hash']); // configuracao antiga, de uma senha so, vira lista de usuarios
        $cfg['usuarios'] = $usuarios;
        if (!track_salvar_config($cfg)) {
            $erros[] = 'Não foi possível gravar a configuração. Confira as permissões de ' . track_pasta_dados() . '.';
            $aviso = '';
        }
    }
}

pagina_inicio('Usuários');
casca_inicio();
?>
<div class="topo"><h1>UTM · Usuários</h1></div>
<?php abas_painel('usuarios'); ?>
<main>
  <?php foreach ($erros as $erro): ?><p class="erro"><?= e($erro) ?></p><?php endforeach; ?>
  <?php if ($aviso): ?><p class="aviso-ok"><?= e($aviso) ?></p><?php endif; ?>

  <section class="cartao">
    <h2>Quem entra</h2>
    <div class="tabela"><table>
      <tr><th>Usuário</th><th></th></tr>
      <?php foreach (array_keys(track_usuarios()) as $u): ?>
        <tr><td><?= e($u) ?><?= $u === $eu ? ' <span class="suave">(você)</span>' : '' ?></td>
          <td><?php if ($u !== $eu): ?>
            <form method="post" action="usuarios.php">
              <input type="hidden" name="csrf" value="<?= e(token_csrf()) ?>">
              <input type="hidden" name="acao" value="remover">
              <input type="hidden" name="usuario" value="<?= e($u) ?>">
              <button type="submit" class="discreto">Tirar acesso</button>
            </form>
          <?php endif; ?></td></tr>
      <?php endforeach; ?>
    </table></div>
  </section>

  <section class="cartao">
    <h2>Dar acesso a alguém</h2>
    <form method="post" action="usuarios.php">
      <input type="hidden" name="csrf" value="<?= e(token_csrf()) ?>">
      <input type="hidden" name="acao" value="adicionar">
      <label>Usuário (ex.: allan) <input type="text" name="usuario" autocomplete="off" autocapitalize="none" spellcheck="false" required></label>
      <label>Senha <input type="password" name="senha" autocomplete="new-password" required></label>
      <label>Repita a senha <input type="password" name="senha2" autocomplete="new-password" required></label>
      <button type="submit">Criar acesso</button>
    </form>
  </section>

  <section class="cartao">
    <h2>Trocar a minha senha</h2>
    <form method="post" action="usuarios.php">
      <input type="hidden" name="csrf" value="<?= e(token_csrf()) ?>">
      <input type="hidden" name="acao" value="trocar_senha">
      <label>Senha atual <input type="password" name="atual" autocomplete="current-password" required></label>
      <label>Nova senha <input type="password" name="nova" autocomplete="new-password" required></label>
      <label>Repita a nova senha <input type="password" name="nova2" autocomplete="new-password" required></label>
      <button type="submit">Trocar senha</button>
    </form>
  </section>
</main>
<?php
casca_fim();
pagina_fim();
