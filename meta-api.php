<?php
// Token da API de Marketing da Meta: colar uma vez, conferir e guardar fora da pasta
// publica. Precisa de ads_read; token que tambem edita e aceito com aviso (lib/meta_api.php).
// Serve para o painel ler o gasto dos anuncios e calcular ROI e ROAS.

require __DIR__ . '/lib/util.php';
require __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/kiwify_api.php'; // mascarar()
require_once __DIR__ . '/lib/meta_api.php';

exigir_login();
$erros = [];
$aviso = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $cfg = track_config();
    $acao = (string)($_POST['acao'] ?? '');

    if (!csrf_valido()) {
        $erros[] = 'Sessão expirada. Recarregue a página.';
    } elseif (!dentro_do_limite('meta-api-tela:' . ip_conexao(), 20, 600)) {
        $erros[] = 'Muitas tentativas. Espere alguns minutos.';
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
pagina_inicio('API Meta');
casca_inicio();
topo_pagina();
abas_painel('meta-api');
?>
<main>
  <?php foreach ($erros as $erro): ?><p class="erro"><?= e($erro) ?></p><?php endforeach; ?>
  <?php if ($aviso): ?><p class="aviso-ok"><?= e($aviso) ?></p><?php endif; ?>

  <?php if ($chave): ?>
  <section class="cartao">
    <h2>Token salvo</h2>
    <div class="tabela"><table>
      <tr><th>Conta de anúncios</th><td><?= e($chave['conta_nome'] ?? '') ?> <span class="suave">(<?= e($chave['conta']) ?> · <?= e($chave['moeda'] ?? '') ?>)</span></td></tr>
      <tr><th>Token</th><td><code><?= e(mascarar($chave['token'])) ?></code> <span class="suave">o resto não aparece mais</span></td></tr>
      <tr><th>Permissões</th><td><?= e(implode(', ', $chave['permissoes'] ?? []) ?: 'a Meta não listou (a leitura da conta funcionou)') ?>
        <?php $podeEditar = array_intersect_key(META_PERMISSOES_EDICAO, array_flip($chave['permissoes'] ?? [])); ?>
        <?= $podeEditar ? '<br><span class="selo alerta">Pode editar</span> <span class="suave">' . e(implode(', ', $podeEditar)) . '. Por enquanto o painel só lê.</span>' : '' ?></td></tr>
      <tr><th>Salvo</th><td><?= e(data_local($chave['salva_em'] ?? null, 'd/m/Y H:i')) ?> <span class="suave">por <?= e($chave['salva_por'] ?? '') ?></span></td></tr>
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
    <p class="suave">Serve para o painel ler <strong>quanto cada anúncio gastou</strong> e mostrar lucro e ROI ao lado das vendas. Precisa de <strong>ads_read</strong>. Com ads_management, o gestor poderá, quando essa função existir, ligar, pausar e mudar orçamento (sempre com confirmação).</p>
    <ol class="suave passos">
      <li><strong>business.facebook.com</strong> → Configurações do negócio → Usuários → <strong>Usuários do sistema</strong> → Adicionar (nome: Painel UTM, função: Funcionário).</li>
      <li>No usuário criado: <strong>Atribuir ativos</strong> → Contas de anúncios → DRIVE DE PROJETOS → só <strong>Ver desempenho</strong>. Para o Instagram na aba Orgânico, atribua também a página do Facebook e a conta do Instagram.</li>
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
</main>
<?php
casca_fim();
pagina_fim();
