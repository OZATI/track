<?php
// Comentarios e mensagens: quem interagiu com a pagina e o Instagram da marca, pelo webhook da
// Meta (lib/meta_interacoes.php), e de qual anuncio veio. Nome e foto de quem mandou mensagem
// pelo recurso Business Asset User Profile Access, buscados so quando alguem pede (botao), para
// a tela abrir sem chamar a Meta.

require __DIR__ . '/lib/util.php';
require __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/perfil.php';
require_once __DIR__ . '/lib/meta_interacoes.php';

exigir_login();
header('Cache-Control: no-store');
$erros = [];
$aviso = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_valido()) {
        $erros[] = 'Sessão expirada. Recarregue a página.';
    } elseif (!dentro_do_limite('meta-perfis:' . ip_conexao(), 6, 600)) {
        $erros[] = 'Muitas buscas seguidas. Espere alguns minutos.';
    } elseif (($_POST['acao'] ?? '') === 'perfis') {
        $r = meta_interacoes_completar(10);
        if (!$r['buscadas']) {
            $aviso = 'Nada para buscar: quem mandou mensagem já tem nome e foto (ou foi buscado nos últimos 2 dias).';
        } elseif ($r['achadas']) {
            $aviso = 'Nome e foto de ' . $r['achadas'] . ' de ' . $r['buscadas'] . ' pessoa(s) atualizados.' . ($r['erro'] ? ' As outras: ' . $r['erro'] . '.' : '');
        } else {
            $erros[] = 'A Meta não devolveu nome nem foto: ' . $r['erro'] . '.';
        }
    }
}

$lista = meta_interacoes_lista(200);
$dias = max(7, (int)(track_config()['dias_retencao'] ?? 90));
$ultimo = ajuste('meta_webhook_aviso_em');
$erroPerfil = ajuste('meta_perfil_erro');
$tipos = ['comentario' => 'Comentário', 'mensagem' => 'Mensagem', 'mencao' => 'Menção'];

// Foto (meta-foto.php) por cima da inicial: com a foto vencida, fica a inicial
function interacao_pessoa(array $i): string
{
    $nome = (string)($i['pessoa_nome'] ?? '');
    $usuario = (string)($i['pessoa_usuario'] ?? '');
    $rotulo = $nome !== '' ? $nome : ($usuario !== '' ? '@' . $usuario : 'Sem nome');
    $cor = hexdec(substr(md5((string)$i['pessoa_id'] . $rotulo), 0, 2)) * 360 / 256;
    $h = '<span class="int-foto"><span class="avatar avatar-letra" style="--av:hsl(' . (int)$cor . ' 55% 45%);width:32px;height:32px;font-size:14px" aria-hidden="true">'
        . e(mb_strtoupper(mb_substr(ltrim($rotulo, '@'), 0, 1))) . '</span>'
        . (!empty($i['pessoa_foto']) ? '<img src="meta-foto.php?' . e(http_build_query(['p' => $i['pessoa_id']])) . '" width="32" height="32" alt="" loading="lazy">' : '')
        . '</span>';
    return $h . '<span class="int-nome"><b>' . e($rotulo) . '</b>' . ($nome !== '' && $usuario !== '' ? '<small class="suave">@' . e($usuario) . '</small>' : '') . '</span>';
}

// Origem: campanha e anuncio quando o painel conhece o anuncio; so o numero quando nao conhece
function interacao_origem(array $i): string
{
    if (empty($i['anuncio_id'])) {
        return '<span class="suave">Orgânico</span>';
    }
    if (!empty($i['campanha_nome'])) {
        return '<a href="./?' . e(http_build_query(['aba' => 'gestor', 'periodo' => 'tudo', 'nivel' => 'anuncios', 'campanha' => $i['campanha_id']])) . '">'
            . e($i['campanha_nome']) . '</a><small class="suave">' . e($i['anuncio_nome'] ?? '') . '</small>';
    }
    return 'Anúncio <code>' . e($i['anuncio_id']) . '</code><small class="suave">ainda não está no gestor</small>';
}

pagina_inicio('Comentários e mensagens');
casca_inicio('utm');
topo_pagina();
abas_painel('organico');
?>
<style>
.int-pessoa{ display:flex; align-items:center; gap:8px; min-width:180px; }
.int-foto{ position:relative; width:32px; height:32px; flex:none; }
.int-foto img{ position:absolute; inset:0; border-radius:50%; object-fit:cover; }
.int-nome, .int-origem{ display:flex; flex-direction:column; }
.int-texto{ min-width:220px; max-width:420px; white-space:pre-line; }
</style>
<main>
  <?php foreach ($erros as $erro): ?><p class="erro"><?= e($erro) ?></p><?php endforeach; ?>
  <?php if ($aviso): ?><p class="aviso-ok"><?= e($aviso) ?></p><?php endif; ?>
  <h1>Comentários e mensagens</h1>
  <p class="suave">Quem comentou, mencionou ou mandou mensagem para a página do Facebook e o Instagram da marca, e o anúncio de onde veio. Chega pelo webhook da Meta (<a href="meta-api.php#webhook">Integrações → Meta Ads</a>). O texto das mensagens privadas não é guardado; tudo some depois de <?= (int)$dias ?> dias.</p>
  <p class="suave"><?= $ultimo ? 'Último aviso da Meta: ' . e(data_local($ultimo, 'd/m/Y H:i')) . '.' : 'Nenhum aviso da Meta recebido ainda.' ?>
    <?= $erroPerfil ? ' Última busca de nome e foto: ' . e($erroPerfil) . '.' : '' ?></p>
  <form method="post" class="linha-botoes">
    <input type="hidden" name="csrf" value="<?= e(token_csrf()) ?>">
    <button type="submit" name="acao" value="perfis">Buscar nomes e fotos</button>
    <span class="suave">De quem mandou mensagem (até 10 por vez). Precisa do recurso Business Asset User Profile Access liberado no app.</span>
  </form>
  <section class="bloco">
    <div class="tabela"><table>
      <thead><tr><th>Quando</th><th>Onde</th><th>Pessoa</th><th>O quê</th><th>Origem</th></tr></thead>
      <tbody>
      <?php foreach ($lista as $i): ?>
        <tr>
          <td><?= e(data_local($i['em'], 'd/m H:i')) ?></td>
          <td><?= e($i['canal'] === 'instagram' ? 'Instagram' : 'Facebook') ?><br><small class="suave"><?= e($tipos[$i['tipo']] ?? $i['tipo']) ?></small></td>
          <td><span class="int-pessoa"><?= interacao_pessoa($i) ?></span></td>
          <td class="int-texto"><?= $i['tipo'] === 'mensagem' ? '<span class="suave">mensagem (o texto não é guardado)</span>' : ($i['texto'] !== null ? e($i['texto']) : '<span class="suave">sem texto</span>') ?></td>
          <td><span class="int-origem"><?= interacao_origem($i) ?></span></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$lista): ?><tr><td colspan="5" class="suave">Nada ainda. Configure o webhook em Integrações → Meta Ads e assine, no app da Meta, os campos comments, messages e mentions (Instagram) e feed e messages (página).</td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </section>
</main>
<?php
casca_fim();
pagina_fim();
