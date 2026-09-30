<?php
// Perfil do Instagram na aba Organico: seguidores, alcance, toques nos links do perfil e os
// posts e reels que mais engajam. O painel so le. Dois caminhos (lib/instagram_api.php):
// - usar o token da aba API Meta, se ele tiver as permissoes do Instagram (nao vence);
// - colar um token do login do Instagram (IGAA...), que o painel renova sozinho.

require __DIR__ . '/lib/util.php';
require __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/kiwify_api.php'; // mascarar()
require_once __DIR__ . '/lib/instagram_sync.php';

exigir_login();
$erros = [];
$aviso = '';
$escolher = [];

// Grava a conexao e esquece erros e descobertas da anterior (a proxima busca comeca do zero)
function ig_gravar(array $cfg, array $conexao, array $r): bool
{
    $cfg['instagram_api'] = $conexao + [
        'usuario_id' => $r['usuario_id'],
        'usuario' => $r['usuario'],
        'nome' => $r['nome'],
        'tipo' => $r['tipo'],
        'insights' => $r['insights'],
        'salva_em' => agora_utc(),
        'salva_por' => usuario_atual(),
    ];
    if (!track_salvar_config($cfg)) {
        return false;
    }
    foreach (['ig_sync_tentativa', 'ig_sync_erro', 'ig_sync_adiada', 'ig_insights_erro', 'ig_insights_erro_em', 'ig_metricas', 'ig_seguir_fora_em', 'ig_renovar_erro'] as $a) {
        definir_ajuste($a, null);
    }
    return true;
}

function ig_aviso_salvo(array $r, string $como): string
{
    return 'Conta @' . $r['usuario'] . ' conectada ' . $como . ': ' . number_format($r['seguidores'], 0, ',', '.') . ' seguidores e '
        . number_format($r['posts'], 0, ',', '.') . ' posts.'
        . ($r['insights'] ? ' Os números aparecem na aba Orgânico em instantes.' : ' Atenção: ' . $r['erro_insights'] . '. Sem ela, o painel mostra só seguidores, curtidas e comentários.');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $cfg = track_config();
    $acao = (string)($_POST['acao'] ?? '');

    if (!csrf_valido()) {
        $erros[] = 'Sessão expirada. Recarregue a página.';
    } elseif (!dentro_do_limite('ig-api-tela:' . ip_cliente(), 20, 600)) {
        $erros[] = 'Muitas tentativas. Espere alguns minutos.';
    } elseif ($acao === 'usar_meta') {
        $meta = meta_api_chave();
        if (!$meta) {
            $erros[] = 'Salve primeiro o token na aba API Meta.';
        } else {
            $d = ig_api_contas_meta($meta['token']);
            $pedido = (string)($_POST['ig_id'] ?? '');
            $conta = null;
            foreach ($d['ok'] ? $d['contas'] : [] as $c) {
                if ($c['id'] === $pedido || ($pedido === '' && count($d['contas']) === 1)) {
                    $conta = $c;
                }
            }
            if (!$d['ok']) {
                $erros[] = $d['erro'];
            } elseif (!$conta) {
                $escolher = $d['contas'];
            } else {
                $r = ig_api_testar(ig_ctx(['modo' => 'facebook', 'ig_id' => $conta['id'], 'token' => $meta['token']]));
                if (!$r['ok']) {
                    $erros[] = $r['erro'] . ' Nada foi salvo.';
                } elseif (ig_gravar($cfg, ['modo' => 'facebook', 'ig_id' => $conta['id'], 'pagina' => $conta['pagina']], $r)) {
                    $aviso = ig_aviso_salvo($r, 'pelo token da API Meta');
                } else {
                    $erros[] = 'Não foi possível gravar a configuração. Confira as permissões de ' . track_pasta_dados() . '.';
                }
            }
        }
    } elseif ($acao === 'salvar') {
        // Acha o token no que foi colado (com espaco, aspas, "access_token=" ou JSON em volta)
        $colado = (string)($_POST['token'] ?? '');
        $token = ig_extrair_token($colado);
        if ($token === null) {
            $erros[] = ig_explicar_colado($colado);
        } elseif (strpos($token, 'EAA') === 0) {
            $erros[] = 'Esse é um token da Meta (começa com "EAA"), não do login do Instagram. Cole-o na aba API Meta e, aqui, use a opção 1: "Usar o token da API Meta".';
        } else {
            $r = ig_api_testar(ig_ctx(['token' => $token]));
            if (!$r['ok']) {
                $erros[] = $r['erro'] . ' Nada foi salvo.';
            } elseif (ig_gravar($cfg, ['modo' => 'instagram', 'token' => $token, 'vence_em' => gmdate('Y-m-d H:i:s', time() + 60 * 86400)], $r)) {
                // Token gerado no painel da Meta vale 60 dias; o painel renova antes
                $aviso = ig_aviso_salvo($r, 'pelo token do Instagram');
            } else {
                $erros[] = 'Não foi possível gravar a configuração. Confira as permissões de ' . track_pasta_dados() . '.';
            }
        }
    } elseif ($acao === 'testar') {
        $k = ig_api_chave();
        if (!$k) {
            $erros[] = 'Nenhuma conta do Instagram conectada.';
        } else {
            $r = ig_api_testar(ig_ctx($k));
            if ($r['ok']) {
                $aviso = 'Conexão OK. @' . $r['usuario'] . ': ' . number_format($r['seguidores'], 0, ',', '.') . ' seguidores.'
                    . ($r['insights'] ? ' Insights liberados.' : ' Sem insights: ' . $r['erro_insights'] . '.');
                if ($r['insights'] !== ($k['insights'] ?? null)) {
                    $cfg['instagram_api']['insights'] = $r['insights'];
                    track_salvar_config($cfg);
                }
            } else {
                $erros[] = $r['erro'];
            }
        }
    } elseif ($acao === 'remover') {
        unset($cfg['instagram_api']);
        if (track_salvar_config($cfg)) {
            $aviso = 'Instagram desconectado do painel. Os números já buscados continuam na aba Orgânico.';
        } else {
            $erros[] = 'Não foi possível gravar a configuração. Confira as permissões de ' . track_pasta_dados() . '.';
        }
    } else {
        $erros[] = 'Ação inválida.';
    }
}

$chave = ig_api_chave();
$salva = track_config()['instagram_api'] ?? null; // salva, mesmo sem o token da API Meta
$pelaMeta = is_array($salva) && ($salva['modo'] ?? '') === 'facebook';
$temMeta = (bool)meta_api_chave();
$estado = ig_sync_estado();
pagina_inicio('API Instagram');
casca_inicio();
topo_pagina();
abas_painel('instagram-api');
?>
<main>
  <?php foreach ($erros as $erro): ?><p class="erro"><?= e($erro) ?></p><?php endforeach; ?>
  <?php if ($aviso): ?><p class="aviso-ok"><?= e($aviso) ?></p><?php endif; ?>

  <?php if ($escolher): ?>
  <section class="cartao">
    <h2>Qual conta do Instagram?</h2>
    <p class="suave">O token da API Meta enxerga mais de uma. Escolha a da marca:</p>
    <form method="post" action="instagram-api.php" class="linha-botoes">
      <input type="hidden" name="csrf" value="<?= e(token_csrf()) ?>">
      <input type="hidden" name="acao" value="usar_meta">
      <?php foreach ($escolher as $c): ?>
      <button type="submit" name="ig_id" value="<?= e($c['id']) ?>">@<?= e($c['usuario']) ?> <span class="suave">(<?= e($c['pagina']) ?>)</span></button>
      <?php endforeach; ?>
    </form>
  </section>
  <?php endif; ?>

  <?php if ($salva): ?>
  <section class="cartao">
    <h2>Instagram conectado</h2>
    <?php if (!$chave): ?><p class="erro">O token da aba API Meta foi removido: sem ele, o painel não busca mais no Instagram. Salve um token lá de novo.</p><?php endif; ?>
    <div class="tabela"><table>
      <tr><th>Conta do Instagram</th><td>@<?= e($salva['usuario'] ?? '') ?> <span class="suave">(<?= e(trim(($salva['nome'] ?? '') . ' · ' . mb_strtolower($salva['tipo'] ?? ''), ' ·')) ?>)</span></td></tr>
      <tr><th>Acesso</th><td><?= $pelaMeta
        ? 'Token da <a href="meta-api.php">API Meta</a>' . (!empty($salva['pagina']) ? ' <span class="suave">· pela página ' . e($salva['pagina']) . '</span>' : '') . '<br><span class="suave">Não vence. Trocou o token lá, o Instagram usa o novo.</span>'
        : 'Token do Instagram <code>' . e(mascarar((string)($salva['token'] ?? ''))) . '</code> <span class="suave">o resto não aparece mais</span>' ?></td></tr>
      <tr><th>Insights</th><td><?= !empty($salva['insights']) && !$estado['insights_erro'] ? '<span class="selo ok">Liberados</span>'
        : '<span class="selo alerta">Sem insights</span> <span class="suave">' . e($estado['insights_erro'] ?: 'O token não tem a permissão de insights do Instagram.') . '</span>' ?></td></tr>
      <tr><th>Salvo</th><td><?= e(data_local($salva['salva_em'] ?? null, 'd/m/Y H:i')) ?> <span class="suave">por <?= e($salva['salva_por'] ?? '') ?></span></td></tr>
      <?php if (!$pelaMeta): ?>
      <tr><th>Validade</th><td><?= !empty($salva['vence_em']) ? 'até ' . e(data_local($salva['vence_em'], 'd/m/Y')) : '—' ?>
        <span class="suave">· o painel renova sozinho a cada 7 dias<?= !empty($salva['renovado_em']) ? ' (última: ' . e(data_local($salva['renovado_em'], 'd/m H:i')) . ')' : '' ?></span>
        <?= ($re = ajuste('ig_renovar_erro')) ? '<br><span class="erro">A última renovação falhou: ' . e($re) . '. Se o token vencer, gere outro.</span>' : '' ?></td></tr>
      <?php endif; ?>
      <tr><th>Última busca</th><td><?= $estado['ok_em'] ? e(data_local($estado['ok_em'], 'd/m H:i')) : 'ainda não feita' ?>
        <?= $estado['erro'] ? '<br><span class="erro">' . e($estado['erro']) . '</span>' : '' ?>
        <?= $estado['adiada'] ? '<br><span class="suave">' . e($estado['adiada']) . '</span>' : '' ?></td></tr>
      <?php $pausa = ig_api_pausa_ate(); ?>
      <tr><th>Uso da API</th><td><?= ig_api_uso(60) ?> consulta(s) no último minuto · <?= ig_api_uso(3600) ?> na última hora
        <br><span class="suave">Limite interno: <?= ig_api_limite_minuto() ?> por minuto.</span>
        <?= $pausa ? '<br><span class="erro">O Instagram pediu uma pausa: o painel volta sozinho às ' . e(data_local(gmdate('Y-m-d H:i:s', $pausa), 'H:i')) . '.</span>' : '' ?></td></tr>
    </table></div>
    <form method="post" action="instagram-api.php" class="linha-botoes">
      <input type="hidden" name="csrf" value="<?= e(token_csrf()) ?>">
      <?php if ($chave): ?><button type="submit" name="acao" value="testar">Testar conexão</button><?php endif; ?>
      <button type="submit" name="acao" value="remover" class="discreto">Desconectar</button>
    </form>
  </section>
  <?php endif; ?>

  <section class="cartao">
    <h2><?= $salva ? 'Trocar a conexão' : 'Conectar o perfil do Instagram' ?></h2>
    <p class="suave">Serve para a aba <strong>Orgânico</strong> mostrar seguidores, alcance, visualizações, <strong>toques no link da bio</strong> e os posts e reels que mais engajam, ao lado das visitas e vendas que vieram do perfil. O painel só lê.</p>

    <h3>Opção 1 (recomendada): usar o token da API Meta</h3>
    <p class="suave">Um token só para anúncios e Instagram, que não vence.</p>
    <ol class="suave passos">
      <li>No app da Meta, deixe disponíveis <strong>instagram_basic</strong>, <strong>instagram_manage_insights</strong>, <strong>pages_show_list</strong> e <strong>pages_read_engagement</strong>.</li>
      <li><strong>business.facebook.com</strong> → Configurações do negócio → Contas: o Instagram da marca ligado à <strong>página do Facebook</strong> do portfólio.</li>
      <li>Usuários do sistema → o usuário do painel → <strong>Atribuir ativos</strong>: a página e a conta do Instagram (só ver).</li>
      <li><strong>Gerar novo token</strong> marcando, além do que já tinha, as quatro permissões acima. Cole na aba <a href="meta-api.php">API Meta</a> e revogue o token antigo.</li>
      <li>Volte aqui e clique no botão abaixo.</li>
    </ol>
    <form method="post" action="instagram-api.php" class="linha-botoes">
      <input type="hidden" name="csrf" value="<?= e(token_csrf()) ?>">
      <input type="hidden" name="acao" value="usar_meta">
      <button type="submit"<?= $temMeta ? '' : ' disabled' ?>>Usar o token da API Meta</button>
      <?= $temMeta ? '' : '<span class="suave">Salve primeiro um token na aba API Meta.</span>' ?>
    </form>

    <h3>Opção 2: token do login do Instagram</h3>
    <ol class="suave passos">
      <li><strong>developers.facebook.com</strong> → seu app → <strong>Funções do app → Funções → Adicionar pessoas → Testador do Instagram</strong> → digite o @ da marca.</li>
      <li>No Instagram da marca, aceite o convite: pelo computador, <strong>instagram.com/accounts/manage_access</strong> → <strong>Convites de testador</strong> → Aceitar.</li>
      <li>No app → Casos de uso → <strong>Gerenciar mensagens e conteúdo no Instagram</strong> → <strong>Permissões e recursos</strong>: adicione <strong>instagram_business_manage_insights</strong> (sem ela não vêm alcance nem visualizações).</li>
      <li><strong>Configuração da API com login do Instagram → 2. Gerar tokens de acesso → Adicionar conta</strong> → entre com o Instagram da marca e permita.</li>
      <li>Clique em <strong>Gerar token</strong> ao lado da conta e copie. Ele começa com <strong>IGAA</strong>. Nome, ID e chave secreta do app, que aparecem no topo da mesma tela, não são o token.</li>
      <li>Cole aqui, direto, sem passar por WhatsApp ou e-mail.</li>
    </ol>
    <form method="post" action="instagram-api.php" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= e(token_csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <label>Token de acesso do Instagram <input type="password" name="token" autocomplete="off" spellcheck="false" required></label>
      <button type="submit">Conferir e salvar</button>
    </form>
  </section>
</main>
<?php
casca_fim();
pagina_fim();
