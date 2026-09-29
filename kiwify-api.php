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
    } elseif (!dentro_do_limite('kiwify-api:' . ip_cliente(), 20, 600)) {
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
<div class="topo"><h1>UTM · API Kiwify</h1></div>
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
        <?= $sync['erro'] ? '<br><span class="erro">' . e($sync['erro']) . '</span>' : '' ?></td></tr>
    </table></div>
    <p class="suave">Com o painel aberto, as vendas são buscadas pela API a cada 10 minutos, junto com o webhook. O botão <strong>Atualizar vendas</strong> fica nas abas Tráfego, Conferência e Vendas.</p>
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
</main>
<?php
casca_fim();
pagina_fim();
