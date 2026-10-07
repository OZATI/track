<?php
// Minha conta (nucleo do admin): a foto do perfil (o lapis na borda da bolinha), a senha, a
// aparencia e o Sair. E de todo usuario, de qualquer painel. Num admin com a pasta conta/ na raiz
// (ex.: admin.engdesk.pro/conta/), mora la: conta/index.php define TRACK_BASE e inclui este
// arquivo. Sem ela, fica aqui no painel. Os formularios postam no proprio endereco.

require __DIR__ . '/lib/util.php';
require __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/perfil.php';

exigir_login(null);
$usuario = (string)usuario_atual();
$erros = [];
$aviso = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $acao = (string)($_POST['acao'] ?? '');
    if (!csrf_valido()) {
        $erros[] = 'Sessão expirada. Recarregue a página.';
    } elseif ($acao === 'foto') {
        $f = $_FILES['foto'] ?? null;
        $r = is_array($f) && ($f['error'] ?? 1) === UPLOAD_ERR_OK && is_uploaded_file($f['tmp_name']) ? avatar_salvar($usuario, $f['tmp_name']) : 'escolha uma foto (até 3 MB)';
        if ($r === true) {
            $aviso = 'Foto do perfil salva.';
        } else {
            $erros[] = 'A foto não foi salva: ' . $r . '.';
        }
    } elseif ($acao === 'tirar_foto') {
        avatar_apagar($usuario);
        $aviso = 'Foto do perfil tirada.';
    } elseif ($acao === 'trocar_senha') {
        $usuarios = track_usuarios();
        $nova = (string)($_POST['nova'] ?? '');
        if (!dentro_do_limite('senha:' . hash('sha256', $usuario), 5, 900)) {
            $erros[] = 'Muitas tentativas de trocar a senha. Espere 15 minutos.';
        } elseif (!password_verify((string)($_POST['atual'] ?? ''), $usuarios[$usuario] ?? '')) {
            $erros[] = 'A senha atual não confere.';
        } elseif ($nova === '' || $nova !== (string)($_POST['nova2'] ?? '')) {
            $erros[] = 'Digite a nova senha e repita igual.';
        } elseif ($problema = senha_problema($nova, $usuario)) {
            $erros[] = $problema;
        } else {
            $cfg = track_config();
            $usuarios[$usuario] = password_hash($nova, PASSWORD_DEFAULT);
            unset($cfg['senha_hash']); // configuracao antiga, de uma senha so, vira lista de usuarios
            $cfg['usuarios'] = $usuarios;
            if (track_salvar_config($cfg)) {
                $aviso = 'Senha trocada.';
            } else {
                $erros[] = 'Não foi possível gravar a configuração. Confira as permissões de ' . track_pasta_dados() . '.';
            }
        }
    } else {
        $erros[] = 'Ação inválida.';
    }
}

pagina_inicio('Minha conta');
casca_inicio('conta');
admin_cabecalho('conta');
$csrf = '<input type="hidden" name="csrf" value="' . e(token_csrf()) . '">';
?>
<main class="cfg">
  <?php foreach ($erros as $erro): ?><p class="erro"><?= e($erro) ?></p><?php endforeach; ?>
  <?php if ($aviso): ?><p class="aviso-ok"><?= e($aviso) ?></p><?php endif; ?>
  <h1 class="cfg-titulo">Minha conta <span class="suave">· <?= e($usuario) ?></span></h1>

  <div class="cfg-grade">
    <section class="cartao" id="perfil">
      <h2><?= com_info('Perfil', 'A foto aparece na bolinha da barra lateral e na lista de usuários. Só quem entrou no admin vê. Qualquer imagem, de qualquer tamanho: o navegador corta no centro, reduz para 512 x 512 e comprime até 64 KB.') ?></h2>
      <div class="perfil-linha">
        <form method="post" action="" enctype="multipart/form-data" class="perfil-foto" data-foto>
          <?= $csrf ?><input type="hidden" name="acao" value="foto">
          <?= avatar_html($usuario, 72, admin_base()) ?>
          <label class="perfil-lapis" title="Trocar a foto"><input type="file" name="foto" accept="image/*" data-foto-arquivo aria-label="Trocar a foto do perfil"><?= icone('lapis', 14) ?></label>
          <noscript><button type="submit" class="discreto neutro">Enviar</button></noscript>
        </form>
        <div class="perfil-info"><b><?= e($usuario) ?></b>
          <span class="suave" data-foto-aviso aria-live="polite">Toque no lápis para trocar a foto.</span>
          <?php if (is_file(avatar_arquivo($usuario))): ?>
          <form method="post" action=""><?= $csrf ?><input type="hidden" name="acao" value="tirar_foto"><button type="submit" class="discreto">Tirar a foto</button></form>
          <?php endif; ?>
        </div></div>
      <form method="post" action="<?= e(admin_base()) ?>sair.php" id="form-sair" class="perfil-sair"><?= $csrf ?>
        <button type="submit" class="discreto neutro" title="Encerrar a sessão neste navegador"><?= icone('sair', 14) ?> Sair do admin</button></form>
    </section>

    <section class="cartao">
      <h2><?= com_info('Trocar a minha senha', 'Pelo menos 10 caracteres e diferente do usuário. Uma frase curta é mais fácil de lembrar e mais difícil de adivinhar. Depois de 5 tentativas erradas, espere 15 minutos.') ?></h2>
      <form method="post" action="" class="form-senha">
        <?= $csrf ?><input type="hidden" name="acao" value="trocar_senha">
        <label>Senha atual <input type="password" name="atual" autocomplete="current-password" required></label>
        <label>Nova senha <input type="password" name="nova" autocomplete="new-password" minlength="10" required></label>
        <label>Repita a nova senha <input type="password" name="nova2" autocomplete="new-password" minlength="10" required></label>
        <button type="submit">Trocar senha</button>
      </form>
    </section>

    <section class="cartao">
      <h2><?= com_info('Aparência', 'Claro, escuro ou qualquer cor de fundo (até o preto puro, #000000). O resto das cores se ajusta à escolhida. Vale só para o seu usuário, em todos os aparelhos e em todos os painéis; também abre pela paleta da barra lateral.') ?></h2>
      <?= tema_form(destino_seguro((string)($_SERVER['REQUEST_URI'] ?? '')), admin_base()) ?>
    </section>
  </div>
</main>
<?php
casca_fim();
pagina_fim();
