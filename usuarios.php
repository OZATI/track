<?php
// Quem entra no admin e o que cada um pode abrir: os paineis (UTM e, num admin com CMS, o CMS)
// e "Usuarios" (esta tela: dar e tirar acessos). Marcar ou desmarcar ja salva. Ninguem tira o
// proprio acesso nem o proprio "Usuarios" (sempre fica alguem que cuida dos acessos). A senha
// de outra pessoa e definida por quem cria o acesso e trocada por ela depois, aqui mesmo.

require __DIR__ . '/lib/util.php';
require __DIR__ . '/lib/layout.php';

exigir_login('usuarios');
$eu = usuario_atual();
$erros = [];
$aviso = '';
$todosAcessos = track_acessos();
$lerAcessos = fn($v) => array_values(array_intersect(array_keys($todosAcessos), array_map('strval', (array)$v)));

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $cfg = track_config();
    $usuarios = track_usuarios();
    $acessos = is_array($cfg['acessos'] ?? null) ? $cfg['acessos'] : [];
    $acao = (string)($_POST['acao'] ?? '');
    // Guarda a lista; com tudo marcado, tira a lista (pode tudo, inclusive o que vier depois)
    $definir = function (string $u, array $lista) use (&$acessos, $todosAcessos) {
        if (count($lista) === count($todosAcessos)) {
            unset($acessos[$u]);
        } else {
            $acessos[$u] = $lista;
        }
    };

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
            $lista = $lerAcessos($_POST['acessos'] ?? []);
            $definir($novo, $lista);
            $aviso = 'Acesso criado para ' . $novo . ($lista ? ' (' . implode(', ', array_map(fn($a) => $todosAcessos[$a], $lista)) . ')' : ', ainda sem nenhum painel') . '. Passe a senha para a pessoa; ela pode trocar aqui depois de entrar.';
        }
    } elseif ($acao === 'acessos') {
        $alvo = (string)($_POST['usuario'] ?? '');
        $lista = $lerAcessos($_POST['acessos'] ?? []);
        if (!isset($usuarios[$alvo])) {
            $erros[] = 'Usuário não encontrado.';
        } else {
            if ($alvo === $eu && !in_array('usuarios', $lista, true)) {
                $lista[] = 'usuarios'; // o proprio "Usuarios" nao sai
            }
            $definir($alvo, $lerAcessos($lista));
            $aviso = 'Acessos de ' . $alvo . ' salvos.';
        }
    } elseif ($acao === 'remover') {
        $alvo = (string)($_POST['usuario'] ?? '');
        if ($alvo === $eu) {
            $erros[] = 'Você não pode tirar o seu próprio acesso.';
        } elseif (!isset($usuarios[$alvo])) {
            $erros[] = 'Usuário não encontrado.';
        } else {
            unset($usuarios[$alvo], $acessos[$alvo]);
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
        $cfg['acessos'] = $acessos;
        if (!track_salvar_config($cfg)) {
            $erros[] = 'Não foi possível gravar a configuração. Confira as permissões de ' . track_pasta_dados() . '.';
            $aviso = '';
        }
    }
}

// Caixas dos acessos de um usuario (a do proprio "Usuarios" fica travada)
$caixas = function (string $u, array $marcados) use ($todosAcessos, $eu): string {
    $h = '';
    foreach ($todosAcessos as $k => $rot) {
        $trava = $u === $eu && $k === 'usuarios';
        $h .= '<label class="acesso"><input type="checkbox" name="acessos[]" value="' . e($k) . '"' . (in_array($k, $marcados, true) ? ' checked' : '') . ($trava ? ' disabled' : '') . '> ' . e($rot) . '</label>';
        if ($trava) {
            $h .= '<input type="hidden" name="acessos[]" value="usuarios">';
        }
    }
    return $h;
};

pagina_inicio('Usuários');
casca_inicio();
?>
<?php topo_pagina(); ?>
<?php abas_painel('usuarios'); ?>
<main>
  <?php foreach ($erros as $erro): ?><p class="erro"><?= e($erro) ?></p><?php endforeach; ?>
  <?php if ($aviso): ?><p class="aviso-ok"><?= e($aviso) ?></p><?php endif; ?>

  <section class="cartao">
    <h2><?= com_info('Quem entra', 'Um login só para o admin inteiro. Marque o que cada pessoa pode abrir: ' . implode(', ', $todosAcessos) . '. "Usuários" é esta tela, de dar e tirar acessos. Marcar ou desmarcar já salva.') ?></h2>
    <div class="tabela"><table>
      <tr><th>Usuário</th><th>Pode abrir</th><th></th></tr>
      <?php foreach (array_keys(track_usuarios()) as $u): ?>
        <tr><td class="usuario-nome"><?= avatar_html($u, 24) ?> <?= e($u) ?><?= $u === $eu ? ' <span class="suave">(você)</span>' : '' ?></td>
          <td><form method="post" action="usuarios.php" class="acessos" data-auto>
              <input type="hidden" name="csrf" value="<?= e(token_csrf()) ?>"><input type="hidden" name="acao" value="acessos"><input type="hidden" name="usuario" value="<?= e($u) ?>">
              <?= $caixas($u, usuario_acessos($u)) ?>
              <noscript><button type="submit" class="discreto neutro">Salvar</button></noscript>
            </form></td>
          <td><?php if ($u !== $eu): ?>
            <form method="post" action="usuarios.php" data-confirma="<?= e('Tirar o acesso de ' . $u . ' ao admin?') ?>">
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
    <form method="post" action="usuarios.php" class="usuario-novo">
      <input type="hidden" name="csrf" value="<?= e(token_csrf()) ?>">
      <input type="hidden" name="acao" value="adicionar">
      <label>Usuário (ex.: allan) <input type="text" name="usuario" autocomplete="off" autocapitalize="none" spellcheck="false" required></label>
      <label>Senha <input type="password" name="senha" autocomplete="new-password" required></label>
      <label>Repita a senha <input type="password" name="senha2" autocomplete="new-password" required></label>
      <fieldset class="acessos"><legend>Pode abrir</legend><?= $caixas('', array_values(array_diff(array_keys($todosAcessos), ['usuarios']))) ?></fieldset>
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
