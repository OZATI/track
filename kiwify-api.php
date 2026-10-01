<?php
// Chave da API da Kiwify: colar uma vez, conferir e guardar fora da pasta publica.
// O client_secret nunca volta para a tela. So aceita chave que le vendas; chave com
// permissao de reembolsar, financeiro, afiliados ou webhooks e recusada.

require __DIR__ . '/lib/util.php';
require __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/kiwify_sync.php';

exigir_login();
$erros = [];
$aviso = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $cfg = track_config();
    $acao = (string)($_POST['acao'] ?? '');

    if (!csrf_valido()) {
        $erros[] = 'Sessão expirada. Recarregue a página.';
    } elseif (!dentro_do_limite('kiwify-api:' . ip_conexao(), 20, 600)) {
        $erros[] = 'Muitas tentativas. Espere alguns minutos.';
    } elseif ($acao === 'salvar') {
        $clientId = trim((string)($_POST['client_id'] ?? ''));
        $secret = trim((string)($_POST['client_secret'] ?? ''));
        $conta = trim((string)($_POST['account_id'] ?? ''));
        if (!kiwify_api_formato_valido($clientId, $secret, $conta)) {
            $erros[] = 'Confira os três campos: o client_id tem o formato xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx, e client_secret e account_id só têm letras e números.';
        } else {
            $r = kiwify_api_testar($clientId, $secret, $conta);
            if (!$r['ok']) {
                $erros[] = $r['erro'] . ' Nada foi salvo.';
            } else {
                $cfg['kiwify_api'] = [
                    'client_id' => $clientId,
                    'client_secret' => $secret,
                    'account_id' => $conta,
                    'escopos' => $r['escopos'],
                    'salva_em' => agora_utc(),
                    'salva_por' => usuario_atual(),
                ];
                if (track_salvar_config($cfg)) {
                    // Chave nova: a proxima busca rele os ultimos 89 dias, e ja na proxima tela
                    definir_ajuste('kiwify_sync_ok_em', null);
                    definir_ajuste('kiwify_sync_tentativa', null);
                    definir_ajuste('kiwify_sync_erro', null);
                    definir_ajuste('kiwify_sync_adiada', null);
                    definir_ajuste('kiwify_api_token', null);
                    $aviso = 'Chave conferida e salva.' . ($r['vendas'] !== null ? ' A API encontrou ' . $r['vendas'] . ' venda(s) entre ontem e hoje.' : '')
                        . ' Ao abrir o painel, ele busca as vendas dos últimos 89 dias.';
                } else {
                    $erros[] = 'Não foi possível gravar a configuração. Confira as permissões de ' . track_pasta_dados() . '.';
                }
            }
        }
    } elseif ($acao === 'testar') {
        $k = kiwify_api_chave();
        if (!$k) {
            $erros[] = 'Nenhuma chave salva.';
        } else {
            $r = kiwify_api_testar($k['client_id'], $k['client_secret'], $k['account_id']);
            if ($r['ok']) {
                $aviso = 'Conexão OK.' . ($r['vendas'] !== null ? ' A API encontrou ' . $r['vendas'] . ' venda(s) entre ontem e hoje.' : '');
            } else {
                $erros[] = $r['erro'];
            }
        }
    } elseif ($acao === 'remover') {
        unset($cfg['kiwify_api']);
        definir_ajuste('kiwify_api_token', null);
        if (track_salvar_config($cfg)) {
            $aviso = 'Chave removida do painel. Se não for usar mais, apague-a também na Kiwify (Apps → API).';
        } else {
            $erros[] = 'Não foi possível gravar a configuração. Confira as permissões de ' . track_pasta_dados() . '.';
        }
    } else {
        $erros[] = 'Ação inválida.';
    }
}

$chave = kiwify_api_chave();
pagina_inicio('API Kiwify');
casca_inicio();
?>
<?php topo_pagina(); ?>
<?php abas_painel('kiwify-api'); ?>
<main>
  <?php foreach ($erros as $erro): ?><p class="erro"><?= e($erro) ?></p><?php endforeach; ?>
  <?php if ($aviso): ?><p class="aviso-ok"><?= e($aviso) ?></p><?php endif; ?>

  <?php if ($chave): ?>
  <section class="cartao">
    <h2>Chave salva</h2>
    <div class="tabela"><table>
      <tr><th>client_id</th><td><code><?= e(mascarar($chave['client_id'])) ?></code></td></tr>
      <tr><th>client_secret</th><td class="suave">guardado, não aparece mais</td></tr>
      <tr><th>account_id</th><td><code><?= e(mascarar($chave['account_id'])) ?></code></td></tr>
      <tr><th>Permissões</th><td><?= e(implode(', ', $chave['escopos'] ?? []) ?: '—') ?></td></tr>
      <tr><th>Salva</th><td><?= e(data_local($chave['salva_em'] ?? null, 'd/m/Y H:i')) ?> <span class="suave">por <?= e($chave['salva_por'] ?? '') ?></span></td></tr>
      <?php $sync = kiwify_sync_estado(); ?>
      <tr><th>Última busca de vendas</th><td><?= $sync['ok_em'] ? e(data_local($sync['ok_em'], 'd/m/Y H:i')) : '<span class="suave">ainda não feita</span>' ?>
        <?= $sync['adiada'] ? '<br><span class="suave">Adiada: ' . e($sync['adiada']) . '</span>' : ($sync['erro'] ? '<br><span class="erro">' . e($sync['erro']) . '</span>' : '') ?></td></tr>
      <?php $pausa = kiwify_api_pausa_ate(); ?>
      <tr><th>Uso da API</th><td><?= kiwify_api_uso(60) ?> chamada(s) no último minuto · <?= kiwify_api_uso(3600) ?> na última hora
        <br><span class="suave">Limite interno: <?= kiwify_api_limite_minuto() ?> por minuto (a Kiwify aceita 100).</span>
        <?= $pausa ? '<br><span class="erro">A Kiwify pediu uma pausa: o painel volta a buscar sozinho às ' . e(data_local(gmdate('Y-m-d H:i:s', $pausa), 'H:i')) . '.</span>' : '' ?></td></tr>
    </table></div>
    <p class="suave">Com o painel aberto, as vendas são buscadas pela API a cada 10 minutos, junto com o webhook. O botão <strong>Atualizar vendas</strong> fica nas abas Tráfego, Conferência e Vendas e busca no máximo uma vez por minuto. Se a Kiwify pedir pausa (limite de chamadas), o painel espera sozinho, sem insistir.</p>
    <form method="post" action="kiwify-api.php" class="linha-botoes">
      <input type="hidden" name="csrf" value="<?= e(token_csrf()) ?>">
      <button type="submit" name="acao" value="testar">Testar conexão</button>
      <button type="submit" name="acao" value="remover" class="discreto">Remover chave</button>
    </form>
  </section>
  <?php endif; ?>

  <section class="cartao">
    <h2><?= $chave ? 'Trocar a chave' : 'Colar a chave da API' ?></h2>
    <p class="suave">Na Kiwify: <strong>Apps → API → Criar API Key</strong>, com o nome do painel e <strong>só "Vendas"</strong> marcado.
      Copie os três campos e cole aqui, direto, sem passar por WhatsApp ou e-mail. O painel confere a chave na Kiwify antes de salvar
      e recusa chave com permissão de reembolsar, financeiro, afiliados ou webhooks.</p>
    <form method="post" action="kiwify-api.php" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= e(token_csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <label>client_secret <input type="password" name="client_secret" autocomplete="off" spellcheck="false" required></label>
      <label>client_id <input type="text" name="client_id" autocomplete="off" autocapitalize="none" spellcheck="false" placeholder="00000000-0000-0000-0000-000000000000" required></label>
      <label>account_id <input type="text" name="account_id" autocomplete="off" autocapitalize="none" spellcheck="false" required></label>
      <button type="submit">Conferir e salvar</button>
    </form>
  </section>

  <?php
  // Webhook: a venda chega na hora. Se as vendas recentes vieram so pela API, ele parou
  // (URL antiga depois de troca de dominio, webhook apagado ou evento desmarcado).
  $db = track_db();
  $ultimoWebhook = $db->query("SELECT MAX(recebida_em) FROM vendas WHERE fonte IN ('webhook', 'ambos')")->fetchColumn() ?: null;
  $st = $db->prepare("SELECT COUNT(*) FROM vendas WHERE fonte = 'api' AND recebida_em >= ?");
  $st->execute([gmdate('Y-m-d H:i:s', time() - 7 * 86400)]);
  $soApi = (int)$st->fetchColumn();
  $parado = $soApi > 0 && (!$ultimoWebhook || strtotime($ultimoWebhook . ' UTC') < time() - 7 * 86400);
  $urlWebhook = (https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\')
      . '/kiwify.php?chave=' . (track_config()['chave_webhook'] ?? '');
  ?>
  <section class="cartao">
    <h2><?= com_info('Webhook da Kiwify', 'Com o webhook, a Kiwify avisa o painel na hora de cada venda. A API confere de 10 em 10 minutos e completa o que faltar; as duas juntas não deixam venda de fora.') ?></h2>
    <?php if ($parado): ?>
    <p class="erro">O webhook não está chegando: <?= $soApi ?> venda(s) dos últimos 7 dias vieram só pela API. Na Kiwify, em Apps → Webhooks, confira se a URL é a de baixo (a do domínio antigo não funciona mais) e se as compras aprovadas, recusadas, reembolsadas e o Pix gerado estão marcados.</p>
    <?php endif; ?>
    <div class="tabela"><table>
      <tr><th>Última venda pelo webhook</th><td><?= $ultimoWebhook ? e(data_local($ultimoWebhook, 'd/m/Y H:i')) : 'nenhuma ainda' ?></td></tr>
      <tr><th>Vendas só pela API (7 dias)</th><td><?= $soApi ?></td></tr>
    </table></div>
    <details><summary>Mostrar a URL do webhook</summary>
      <pre><?= e($urlWebhook) ?></pre>
      <p class="suave">A chave no fim da URL é secreta: cole só na Kiwify, em Apps → Webhooks.</p>
    </details>
  </section>
</main>
<?php
casca_fim();
pagina_fim();
