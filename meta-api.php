<?php
// Token da API de Marketing da Meta: colar uma vez, ou Conectar com Facebook
// (meta-conectar.php), conferir e guardar fora da pasta publica. Precisa de ads_read; token que
// tambem edita e aceito com aviso (lib/meta_api.php). Serve para o painel ler o gasto dos
// anuncios e calcular ROI e ROAS, e, com ads_management, para o gestor ligar, pausar e mudar
// orcamento. Aqui tambem ficam o app da Meta (ID e chave secreta, lib/meta_login.php) e o
// webhook de comentarios e mensagens (lib/meta_interacoes.php).

require __DIR__ . '/lib/util.php';
require __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/kiwify_api.php'; // mascarar()
require_once __DIR__ . '/lib/meta_api.php';
require_once __DIR__ . '/lib/meta_interacoes.php';
require_once __DIR__ . '/lib/integracoes.php';

exigir_login('integracoes');
$erros = [];
$aviso = '';
if ($a = aviso_pegar()) {
    if ($a[1] === 'erro') {
        $erros[] = $a[0];
    } else {
        $aviso = $a[0];
    }
}
// Login do Facebook feito e conta de anuncios por escolher (meta-conectar.php): token so na sessao
$pendente = $_SESSION['meta_login_pendente'] ?? null;
if (!is_array($pendente) || time() - (int)($pendente['em'] ?? 0) > META_LOGIN_VALIDADE) {
    unset($_SESSION['meta_login_pendente']);
    $pendente = null;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $cfg = track_config();
    $acao = (string)($_POST['acao'] ?? '');

    if (!csrf_valido()) {
        $erros[] = 'Sessão expirada. Recarregue a página.';
    } elseif (!dentro_do_limite('meta-api-tela:' . ip_conexao(), 20, 600)) {
        $erros[] = 'Muitas tentativas. Espere alguns minutos.';
    } elseif ($acao === 'escolher_conta') {
        $conta = meta_api_conta((string)($_POST['conta'] ?? ''));
        if (!$pendente) {
            $erros[] = 'O login do Facebook expirou. Clique em Conectar com Facebook de novo.';
        } elseif (!in_array($conta, array_column($pendente['contas'], 'conta'), true)) {
            $erros[] = 'Escolha uma das contas de anúncios da lista.';
        } else {
            [$ok, $msg] = meta_login_salvar($pendente['login'], $conta);
            if ($ok) {
                $aviso = $msg;
            } else {
                $erros[] = $msg;
            }
            unset($_SESSION['meta_login_pendente']);
            $pendente = null;
        }
    } elseif ($acao === 'cancelar_login') {
        unset($_SESSION['meta_login_pendente']);
        $pendente = null;
        $aviso = 'Conexão cancelada. Nada foi salvo.';
    } elseif ($acao === 'app_salvar') {
        $atual = is_array($cfg['meta_app'] ?? null) ? $cfg['meta_app'] : [];
        $id = trim((string)($_POST['app_id'] ?? ''));
        $configId = trim((string)($_POST['config_id'] ?? ''));
        $noAmbiente = (bool)getenv('TRACK_META_APP_SECRET');
        // Chave em branco: fica a que ja estava (ela nunca volta para a tela)
        $segredo = $noAmbiente ? '' : (trim((string)($_POST['app_segredo'] ?? '')) ?: (string)($atual['segredo'] ?? ''));
        if (!meta_app_id_valido($id) || ($configId !== '' && !meta_app_id_valido($configId))) {
            $erros[] = 'O ID do app e o ID da configuração do login só têm números (Painel de apps da Meta → Configurações do app → Básico).';
        } elseif (!$noAmbiente && !meta_app_segredo_valido($segredo)) {
            $erros[] = 'A chave secreta do app tem 32 letras e números (Configurações do app → Básico → Chave secreta do app → Mostrar). Nada foi salvo.';
        } else {
            $cfg['meta_app'] = array_filter(['id' => $id, 'segredo' => $segredo, 'config_id' => $configId]);
            if (track_salvar_config($cfg)) {
                $aviso = 'App da Meta salvo.' . ($noAmbiente ? '' : ' A chave secreta fica só no servidor e não aparece mais.');
            } else {
                $erros[] = 'Não foi possível gravar a configuração. Confira as permissões de ' . track_pasta_dados() . '.';
            }
        }
    } elseif ($acao === 'app_remover') {
        unset($cfg['meta_app']);
        if (track_salvar_config($cfg)) {
            $aviso = 'App da Meta removido do painel. O Conectar com Facebook e o webhook param até cadastrar de novo.';
        } else {
            $erros[] = 'Não foi possível gravar a configuração. Confira as permissões de ' . track_pasta_dados() . '.';
        }
    } elseif ($acao === 'webhook_token') {
        $cfg['meta_webhook'] = ['verificar' => bin2hex(random_bytes(20)), 'gerado_em' => agora_utc(), 'gerado_por' => usuario_atual()];
        if (track_salvar_config($cfg)) {
            $aviso = 'Token de verificação gerado. Cole no app da Meta, com a URL de callback, e clique em Verificar e salvar.';
        } else {
            $erros[] = 'Não foi possível gravar a configuração. Confira as permissões de ' . track_pasta_dados() . '.';
        }
    } elseif ($acao === 'salvar') {
        // Copia da Meta as vezes vem com quebra de linha ou espaco no meio: tira tudo
        $token = preg_replace('/\s+/', '', (string)($_POST['token'] ?? ''));
        $conta = meta_api_conta((string)($_POST['conta'] ?? ''));
        if (!meta_api_formato_valido($token, $conta)) {
            $erros[] = 'Confira os campos: o token é um texto longo de letras e números, e a conta de anúncios só tem números (ex.: 587364236934346).';
        } else {
            $r = meta_api_testar($token, $conta);
            if (!$r['ok']) {
                $erros[] = $r['erro'] . ' Nada foi salvo.';
            } else {
                $contaAntes = (string)($cfg['meta_api']['conta'] ?? '');
                $cfg['meta_api'] = [
                    'token' => $token,
                    'conta' => $conta,
                    'conta_nome' => $r['conta_nome'],
                    'moeda' => $r['moeda'],
                    'permissoes' => $r['permissoes'],
                    'salva_em' => agora_utc(),
                    'salva_por' => usuario_atual(),
                ];
                if (track_salvar_config($cfg)) {
                    if ($contaAntes !== $conta) { // outra conta de anuncios: o historico de gasto e outro
                        meta_historico_zerar();
                    }
                    $aviso = 'Token conferido e salvo. Conta ' . $r['conta_nome'] . ': ' . reais_simples($r['gasto_7d'], $r['moeda']) . ' investidos nos últimos 7 dias.'
                        . ($r['edita'] ? ' Atenção: este token também pode ' . implode(', ', $r['edita']) . '. Ele fica só aqui no painel; não o compartilhe.' : '');
                } else {
                    $erros[] = 'Não foi possível gravar a configuração. Confira as permissões de ' . track_pasta_dados() . '.';
                }
            }
        }
    } elseif ($acao === 'testar') {
        $k = meta_api_chave();
        if (!$k) {
            $erros[] = 'Nenhum token salvo.';
        } else {
            $r = meta_api_testar($k['token'], $k['conta']);
            if ($r['ok']) {
                $aviso = 'Conexão OK. ' . $r['conta_nome'] . ': ' . reais_simples($r['gasto_7d'], $r['moeda']) . ' investidos nos últimos 7 dias.';
            } else {
                $erros[] = $r['erro'];
            }
        }
    } elseif ($acao === 'remover') {
        unset($cfg['meta_api']);
        if (track_salvar_config($cfg)) {
            $aviso = 'Token removido do painel. Se não for usar mais, revogue-o também na Meta (Configurações do negócio → Usuários do sistema).';
        } else {
            $erros[] = 'Não foi possível gravar a configuração. Confira as permissões de ' . track_pasta_dados() . '.';
        }
    } else {
        $erros[] = 'Ação inválida.';
    }
}

// Valor em centavos na moeda da conta (BRL vira R$)
function reais_simples(int $centavos, string $moeda): string
{
    return ($moeda === 'BRL' || $moeda === '' ? 'R$ ' : $moeda . ' ') . number_format($centavos / 100, 2, ',', '.');
}

$chave = meta_api_chave();
$app = meta_app();
$appCfg = is_array(track_config()['meta_app'] ?? null) ? track_config()['meta_app'] : [];
$webhookToken = meta_webhook_token();
pagina_inicio('Meta Ads');
casca_inicio('integracoes');
integracoes_topo('anuncios', 'Meta Ads');
?>
<main>
  <?php foreach ($erros as $erro): ?><p class="erro"><?= e($erro) ?></p><?php endforeach; ?>
  <?php if ($aviso): ?><p class="aviso-ok"><?= e($aviso) ?></p><?php endif; ?>

  <?php if ($pendente): ?>
  <section class="cartao">
    <h2>Escolha a conta de anúncios</h2>
    <p class="suave">Login feito<?= !empty($pendente['login']['email']) ? ' como ' . e($pendente['login']['email']) : '' ?>. Este usuário enxerga mais de uma conta: qual o painel vai ler?</p>
    <form method="post" action="meta-api.php" class="linha-botoes">
      <input type="hidden" name="csrf" value="<?= e(token_csrf()) ?>">
      <select name="conta" aria-label="Conta de anúncios">
        <?php foreach ($pendente['contas'] as $c): ?><option value="<?= e($c['conta']) ?>"><?= e($c['nome'] . ' · ' . $c['conta']) ?></option><?php endforeach; ?>
      </select>
      <button type="submit" name="acao" value="escolher_conta">Conferir e salvar</button>
      <button type="submit" name="acao" value="cancelar_login" class="discreto">Cancelar</button>
    </form>
  </section>
  <?php endif; ?>

  <?php if ($chave): ?>
  <section class="cartao">
    <h2>Token salvo</h2>
    <div class="tabela"><table>
      <tr><th>Conta de anúncios</th><td><?= e($chave['conta_nome'] ?? '') ?> <span class="suave">(<?= e($chave['conta']) ?> · <?= e($chave['moeda'] ?? '') ?>)</span></td></tr>
      <tr><th>Token</th><td><code><?= e(mascarar($chave['token'])) ?></code> <span class="suave">o resto não aparece mais</span></td></tr>
      <tr><th>Permissões</th><td><?= e(implode(', ', $chave['permissoes'] ?? []) ?: 'a Meta não listou (a leitura da conta funcionou)') ?>
        <?php $podeEditar = array_intersect_key(META_PERMISSOES_EDICAO, array_flip($chave['permissoes'] ?? [])); ?>
        <?= $podeEditar ? '<br><span class="selo alerta">Pode editar</span> <span class="suave">' . e(implode(', ', $podeEditar)) . '. O Gestor de anúncios usa para ligar, pausar e mudar o orçamento, sempre com confirmação.</span>' : '' ?></td></tr>
      <tr><th>Salvo</th><td><?= e(data_local($chave['salva_em'] ?? null, 'd/m/Y H:i')) ?> <span class="suave">por <?= e($chave['salva_por'] ?? '') ?></span></td></tr>
      <?php if (($chave['origem'] ?? '') === 'facebook'): ?>
      <tr><th>Conectado com o Facebook</th><td><?= e(implode(' · ', $chave['conectado_por'] ?? []) ?: 'a Meta não informou nome nem e-mail') ?>
        <br><span class="suave"><?= !empty($chave['vence_em']) ? 'Vence em ' . e(data_local($chave['vence_em'], 'd/m/Y')) . ': conecte de novo antes disso.' : 'Token sem vencimento.' ?></span></td></tr>
      <?php endif; ?>
      <tr><th>Histórico de gasto</th><td><?= e(meta_historico_texto()) ?>
        <?= ajuste('meta_hist_erro') ? '<br><span class="erro">' . e(ajuste('meta_hist_erro')) . '</span>' : '' ?>
        <br><span class="suave">A Meta guarda os números dos anúncios por 37 meses. O painel volta no tempo de 89 em 89 dias, em segundo plano, e guarda o gasto de cada dia (com o nome das campanhas que já saíram da conta).</span></td></tr>
      <?php $pausa = meta_api_pausa_ate(); ?>
      <tr><th>Uso da API</th><td><?= meta_api_uso(60) ?> consulta(s) no último minuto · <?= meta_api_uso(3600) ?> na última hora
        <br><span class="suave">Limite interno: <?= meta_api_limite_minuto() ?> por minuto.</span>
        <?= $pausa ? '<br><span class="erro">A Meta pediu uma pausa: o painel volta sozinho às ' . e(data_local(gmdate('Y-m-d H:i:s', $pausa), 'H:i')) . '.</span>' : '' ?></td></tr>
    </table></div>
    <form method="post" action="meta-api.php" class="linha-botoes">
      <input type="hidden" name="csrf" value="<?= e(token_csrf()) ?>">
      <button type="submit" name="acao" value="testar">Testar conexão</button>
      <button type="submit" name="acao" value="remover" class="discreto">Remover token</button>
    </form>
  </section>
  <?php endif; ?>

  <section class="cartao">
    <h2><?= $chave ? 'Trocar o token' : 'Conectar a conta de anúncios da Meta' ?></h2>
    <p class="suave">Serve para o painel ler <strong>quanto cada anúncio gastou</strong> e mostrar lucro e ROI ao lado das vendas. Precisa de <strong>ads_read</strong>. Com <strong>ads_management</strong>, o Gestor de anúncios também liga, pausa e muda o orçamento (sempre com confirmação).</p>
    <?php if ($app): ?>
    <form method="post" action="meta-conectar.php" class="linha-botoes">
      <input type="hidden" name="csrf" value="<?= e(token_csrf()) ?>">
      <button type="submit">Conectar com Facebook</button>
      <span class="suave">Entra na Meta e volta com o token, sem colar nada. O painel guarda o nome e o e-mail de quem conectou.</span>
    </form>
    <p class="suave">Ou gere o token do usuário do sistema e cole abaixo:</p>
    <?php else: ?>
    <p class="suave">Para usar o botão <strong>Conectar com Facebook</strong>, cadastre antes o app da Meta, mais abaixo. Ou gere o token do usuário do sistema e cole aqui:</p>
    <?php endif; ?>
    <ol class="suave passos">
      <li><strong>business.facebook.com</strong> → Configurações do negócio → Usuários → <strong>Usuários do sistema</strong> → Adicionar (nome: Painel UTM, função: Funcionário).</li>
      <li>No usuário criado: <strong>Atribuir ativos</strong> → Contas de anúncios → a sua conta de anúncios → só <strong>Ver desempenho</strong>. Para o Instagram na aba Orgânico, atribua também a página do Facebook e a conta do Instagram.</li>
      <li><strong>Gerar novo token</strong> → escolha o app do negócio → validade <strong>Nunca</strong> → marque <strong>ads_read</strong> (e as de edição, se for usar). Para o Instagram, marque também <strong>instagram_basic</strong>, <strong>instagram_manage_insights</strong>, <strong>pages_show_list</strong> e <strong>pages_read_engagement</strong> → Gerar.</li>
      <li>Copie o token e cole aqui, direto, sem passar por WhatsApp ou e-mail.</li>
    </ol>
    <form method="post" action="meta-api.php" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= e(token_csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <label>Token de acesso <input type="password" name="token" autocomplete="off" spellcheck="false" required></label>
      <label>Número da conta de anúncios <input type="text" name="conta" autocomplete="off" inputmode="numeric" placeholder="587364236934346" value="<?= e($chave['conta'] ?? '') ?>" required></label>
      <button type="submit">Conferir e salvar</button>
    </form>
  </section>

  <section class="cartao" id="app">
    <h2>App da Meta</h2>
    <p class="suave">O app do negócio no Painel de apps da Meta. Serve para o <strong>Conectar com Facebook</strong> e para conferir a assinatura dos avisos do <strong>webhook</strong>. A chave secreta fica só no servidor e não volta para a tela<?= getenv('TRACK_META_APP_SECRET') ? '; aqui ela vem da variável de ambiente TRACK_META_APP_SECRET' : ' (ou defina a variável de ambiente TRACK_META_APP_SECRET)' ?>.</p>
    <div class="tabela"><table>
      <tr><th>Situação</th><td><?= $app ? '<span class="selo ok">Cadastrado</span> app ' . e($app['id']) . ($app['config_id'] ? ' · login para empresas (configuração ' . e($app['config_id']) . ')' : ' · login comum') : '<span class="selo alerta">Não cadastrado</span>' ?></td></tr>
      <tr><th>Endereço de volta do login</th><td class="quebra"><code><?= e(meta_login_volta()) ?></code><br><span class="suave">Cadastre igual em Login do Facebook → Configurações → URIs de redirecionamento do OAuth válidos.</span></td></tr>
      <tr><th>Permissões do login</th><td class="quebra"><?= $app && $app['config_id'] ? 'as da configuração do login, no Painel de apps' : e(str_replace(',', ', ', $app['permissoes'] ?? META_LOGIN_PERMISSOES)) ?></td></tr>
    </table></div>
    <form method="post" action="meta-api.php#app" autocomplete="off" class="cfg-grade">
      <input type="hidden" name="csrf" value="<?= e(token_csrf()) ?>">
      <label>ID do app <input type="text" name="app_id" inputmode="numeric" value="<?= e($app['id'] ?? ($appCfg['id'] ?? '')) ?>" required></label>
      <?php if (!getenv('TRACK_META_APP_SECRET')): ?>
      <label>Chave secreta do app <input type="password" name="app_segredo" autocomplete="off" spellcheck="false" placeholder="<?= !empty($appCfg['segredo']) ? 'cadastrada (em branco, fica a mesma)' : '32 letras e números' ?>"></label>
      <?php endif; ?>
      <label>ID da configuração do login (opcional) <input type="text" name="config_id" inputmode="numeric" value="<?= e($appCfg['config_id'] ?? '') ?>" placeholder="Login do Facebook para Empresas"></label>
      <p class="linha-botoes">
        <button type="submit" name="acao" value="app_salvar">Salvar app</button>
        <?php if ($appCfg): ?><button type="submit" name="acao" value="app_remover" class="discreto" formnovalidate>Remover app</button><?php endif; ?>
      </p>
    </form>
  </section>

  <section class="cartao" id="webhook">
    <h2>Webhook: comentários e mensagens</h2>
    <p class="suave">A Meta avisa o painel na hora em que alguém comenta, menciona ou manda mensagem para a página e o Instagram da marca. Os avisos aparecem em <a href="meta-interacoes.php">Comentários e mensagens</a>, com o anúncio de origem. A Meta só manda aviso com o app <strong>publicado</strong>.</p>
    <div class="tabela"><table>
      <tr><th>URL de callback</th><td><code><?= e(meta_url_painel('meta-webhook.php')) ?></code></td></tr>
      <tr><th>Verificar token</th><td><?= $webhookToken ? '<code>' . e($webhookToken) . '</code>' : '<span class="suave">ainda não gerado</span>' ?></td></tr>
      <tr><th>Situação</th><td><?php
        $verificado = ajuste('meta_webhook_verificado_em');
        $ultimo = ajuste('meta_webhook_aviso_em');
        echo $app ? '' : '<span class="selo alerta">Sem o app da Meta</span> <span class="suave">sem a chave secreta, nenhum aviso é gravado.</span><br>';
        echo $verificado ? 'A Meta conferiu a URL em ' . e(data_local($verificado, 'd/m/Y H:i')) . '.' : '<span class="suave">A Meta ainda não conferiu a URL.</span>';
        echo $ultimo ? ' Último aviso: ' . e(data_local($ultimo, 'd/m/Y H:i')) . '.' : '';
      ?></td></tr>
    </table></div>
    <form method="post" action="meta-api.php#webhook" class="linha-botoes">
      <input type="hidden" name="csrf" value="<?= e(token_csrf()) ?>">
      <button type="submit" name="acao" value="webhook_token"<?= $webhookToken ? ' class="discreto"' : '' ?>><?= $webhookToken ? 'Gerar outro token' : 'Gerar token de verificação' ?></button>
    </form>
    <ol class="suave passos">
      <li>No Painel de apps da Meta, em <strong>Webhooks</strong>: cole a <strong>URL de callback</strong> e o <strong>Verificar token</strong> e clique em <strong>Verificar e salvar</strong>. O painel precisa estar no ar nesse endereço.</li>
      <li>Assine os campos <strong>comments</strong>, <strong>messages</strong> e <strong>mentions</strong> (Instagram) e <strong>feed</strong> e <strong>messages</strong> (Página).</li>
      <li>Permissões: <strong>instagram_manage_comments</strong>, <strong>instagram_manage_messages</strong>, <strong>pages_messaging</strong> e <strong>pages_read_user_content</strong>. Nome e foto de quem escreve: recurso <strong>Business Asset User Profile Access</strong>. Fora de quem tem função no app, tudo isso só vale depois da análise do app e da verificação da empresa.</li>
    </ol>
  </section>
</main>
<?php
casca_fim();
pagina_fim();
