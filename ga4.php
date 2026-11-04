<?php
// Google Analytics 4: ligar por conta de servico (so leitura) e conferir os numeros do GA4 com os
// do painel, endereco por endereco: visualizacoes, cliques no checkout, compras, paginas e
// origens, mais o mapa de todos os eventos que o GA4 recebe e o que o proprio Google diz dos
// numeros (fuso, limiar, cota). A chave privada nunca volta para a tela.
// API e busca: lib/ga4_api.php e lib/ga4_sync.php; contas da conferencia: lib/ga4_conferencia.php.

require __DIR__ . '/lib/util.php';
require __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/ga4_conferencia.php';
require_once __DIR__ . '/lib/integracoes.php';

exigir_login('integracoes');
const GA4_MAX_PROPRIEDADES = 3;
$erros = [];
$aviso = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $cfg = track_config();
    $acao = (string)($_POST['acao'] ?? '');

    if (!csrf_valido()) {
        $erros[] = 'Sessão expirada. Recarregue a página.';
    } elseif (!dentro_do_limite('ga4:' . ip_conexao(), 20, 600)) {
        $erros[] = 'Muitas tentativas. Espere alguns minutos.';
    } elseif ($acao === 'salvar') {
        // Arquivo novo, ou o ja salvo (para trocar so as propriedades)
        $json = trim((string)($_POST['conta'] ?? ''));
        $salva = $cfg['ga4'] ?? null;
        if ($json !== '') {
            $conta = ga4_ler_conta_servico($json);
        } elseif (is_array($salva) && !empty($salva['private_key'])) {
            $conta = ['ok' => true, 'client_email' => $salva['client_email'], 'private_key' => $salva['private_key'], 'projeto' => $salva['projeto'] ?? ''];
        } else {
            $conta = ['ok' => false, 'erro' => 'Cole o conteúdo do arquivo JSON da chave da conta de serviço.'];
        }
        if (!$conta['ok']) {
            $erros[] = $conta['erro'];
        }
        $props = [];
        for ($i = 1; $i <= GA4_MAX_PROPRIEDADES; $i++) {
            $id = preg_replace('/\s+/', '', (string)($_POST["prop{$i}_id"] ?? '')) ?? '';
            if ($id === '') {
                continue;
            }
            if ($erro = ga4_propriedade_erro($id)) {
                $erros[] = $erro;
            } elseif (isset($props[$id])) {
                $erros[] = 'A propriedade ' . $id . ' está repetida.';
            } else {
                $props[$id] = ['id' => $id, 'nome' => texto($_POST["prop{$i}_nome"] ?? '', 30)];
            }
        }
        if (!$props && !$erros) {
            $erros[] = 'Informe o ID de pelo menos uma propriedade do GA4.';
        }
        if (!$erros) {
            $r = ga4_api_testar($conta['client_email'], $conta['private_key'], array_values($props));
            if (!$r['ok']) {
                $erros[] = $r['erro'] . ' Nada foi salvo.';
            } else {
                $cfg['ga4'] = [
                    'client_email' => $conta['client_email'],
                    'private_key' => $conta['private_key'],
                    'projeto' => $conta['projeto'],
                    'propriedades' => array_values($props),
                    'salva_em' => agora_utc(),
                    'salva_por' => usuario_atual(),
                ];
                if (track_salvar_config($cfg)) {
                    foreach (['ga4_sync_ok_em', 'ga4_sync_tentativa', 'ga4_sync_erro', 'ga4_sync_adiada', 'ga4_api_token'] as $c) {
                        definir_ajuste($c, null);
                    }
                    $resumo = array_map(fn($p) => ($p['nome'] ?: $p['id']) . ': ' . number_format($r['propriedades'][$p['id']]['eventos'], 0, ',', '.') . ' eventos nos últimos 7 dias', array_values($props));
                    $aviso = 'Conta de serviço conferida e salva. ' . implode(' · ', $resumo) . '. Os números entram na próxima busca (botão Atualizar agora, ou sozinho em até 1 hora).';
                } else {
                    $erros[] = 'Não foi possível gravar a configuração. Confira as permissões de ' . track_pasta_dados() . '.';
                }
            }
        }
    } elseif ($acao === 'testar') {
        $k = ga4_api_chave();
        if (!$k) {
            $erros[] = 'Nenhuma conta de serviço salva.';
        } else {
            $r = ga4_api_testar($k['client_email'], $k['private_key'], $k['propriedades']);
            if ($r['ok']) {
                $aviso = 'Conexão OK com ' . count($k['propriedades']) . ' propriedade(s).';
            } else {
                $erros[] = $r['erro'];
            }
        }
    } elseif ($acao === 'atualizar') {
        if (!ga4_api_chave()) {
            $erros[] = 'Nenhuma conta de serviço salva.';
        } elseif (time() - (int)(ajuste('ga4_sync_tentativa') ?? 0) < 60) {
            $aviso = 'Os números acabaram de ser buscados. Espere um minuto para buscar de novo.';
        } else {
            $r = ga4_sincronizar();
            if ($r['ok']) {
                $aviso = 'Números do GA4 atualizados.';
            } else {
                $erros[] = $r['erro'];
            }
        }
    } elseif ($acao === 'remover') {
        unset($cfg['ga4']);
        if (track_salvar_config($cfg)) {
            ga4_apagar_dados();
            $aviso = 'Conexão removida e números do GA4 apagados do painel. Se não for usar mais, apague a chave no Google Cloud e tire o acesso da conta de serviço no GA4.';
        } else {
            $erros[] = 'Não foi possível gravar a configuração. Confira as permissões de ' . track_pasta_dados() . '.';
        }
    } else {
        $erros[] = 'Ação inválida.';
    }
}

$k = ga4_api_chave();
$periodo = periodo_da_tela('7d');
$n = fn($v) => number_format((float)$v, 0, ',', '.');
$rs = fn($v) => 'R$ ' . number_format((float)$v, 2, ',', '.');
$selo = function (?int $ga4, ?int $painel): string {
    [$t, $c] = ga4_diferenca($ga4, $painel);
    return '<span class="selo ' . e($c) . '">' . e($t) . '</span>';
};

pagina_inicio('Google Analytics 4');
casca_inicio('integracoes');
integracoes_topo('rastreio', 'Google Analytics 4');
if ($k) {
    barra_periodo('ga4.php', $periodo);
}
?>
<main class="ga4">
  <?php foreach ($erros as $erro): ?><p class="erro"><?= e($erro) ?></p><?php endforeach; ?>
  <?php if ($aviso): ?><p class="aviso-ok"><?= e($aviso) ?></p><?php endif; ?>

  <?php if ($k):
      $hosts = ga4_conf_por_host($periodo);
      $temGa4 = (bool)valor(track_db(), 'SELECT 1 FROM ga4_eventos LIMIT 1');
      [$d1, $d2] = periodo_dias($periodo);
      $recente = $d2 >= (new DateTime('yesterday', fuso()))->format('Y-m-d');
  ?>
  <?php if (!$temGa4): ?>
  <p class="suave">Ainda sem números do GA4 no painel. Clique em <strong>Atualizar agora</strong>, no fim da página, ou espere a busca automática (de hora em hora).</p>
  <?php endif; ?>
  <?php if ($recente): ?>
  <p class="suave">O período inclui hoje ou ontem: no GA4 esses dias ainda mudam (o Google leva até 48 horas para fechar um dia). Para conferir, prefira dias fechados.</p>
  <?php endif; ?>

  <h2><?= com_info('Conferência por endereço', 'Os mesmos acontecimentos contados pelos dois lados: o painel (t.js nas páginas) e o GA4 (gtag). O GA4 de 10% a 35% abaixo é o normal; acima do painel, ou muito abaixo, indica página sem uma das tags ou evento duplicado.') ?></h2>
  <?php foreach ($hosts as $host => $h):
      $temPar = false;
      foreach (GA4_PARES as [$pe, $ge]) { $temPar = $temPar || isset($h['painel'][$pe]) || isset($h['ga4'][$ge]); }
      if (!$temPar) { continue; }
  ?>
  <div class="tabela"><table>
    <thead><tr><th><?= e($host) ?></th><th>GA4</th><th>Painel</th><th><?= com_info('Diferença', 'Quanto o GA4 contou a mais (+) ou a menos (−) que o painel. Até 10%: bate. Até 35% a menos: normal (bloqueadores). Fora disso, vale investigar.') ?></th></tr></thead>
    <tbody>
    <?php foreach (GA4_PARES as [$pe, $ge, $rot, $dica]):
        $g = isset($h['ga4'][$ge]) ? $h['ga4'][$ge][0] : null;
        $p = isset($h['painel'][$pe]) ? $h['painel'][$pe] : null;
        if ($g === null && $p === null) { continue; }
    ?>
      <tr><td><?= com_info($rot, $dica) ?><br><small class="suave"><code><?= e($ge) ?></code> × <code><?= e($pe) ?></code></small></td>
        <td><?= $g === null ? '<span class="suave">sem o evento</span>' : e($n($g)) ?></td>
        <td><?= $p === null ? '<span class="suave">sem o evento</span>' : e($n($p)) ?></td>
        <td><?= $selo($g ?? 0, $p ?? 0) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endforeach; ?>

  <?php $c = ga4_conf_compras($periodo); ?>
  <div class="tabela"><table>
    <thead><tr><th><?= com_info('Compras', 'O purchase do GA4 sai no checkout próprio quando o pagamento é aprovado, uma vez por pedido. Do lado do painel, os pedidos aprovados do checkout próprio no período. Vendas da Kiwify não mandam purchase para o GA4.') ?></th><th>GA4 <code>purchase</code></th><th>Checkout próprio</th><th>Diferença</th></tr></thead>
    <tbody>
      <tr><td>Quantidade</td><td><?= e($n($c['ga4'][0])) ?></td><td><?= $c['painel'] ? e($n($c['painel'][0])) : '<span class="suave">sem checkout próprio</span>' ?></td><td><?= $c['painel'] ? $selo($c['ga4'][0], $c['painel'][0]) : '' ?></td></tr>
      <tr><td>Valor</td><td><?= e($rs($c['ga4'][1])) ?></td><td><?= $c['painel'] ? e($rs($c['painel'][1])) : '' ?></td><td><?= $c['painel'] ? $selo((int)round($c['ga4'][1]), (int)round($c['painel'][1])) : '' ?></td></tr>
    </tbody>
  </table></div>

  <h2><?= com_info('Eventos que o GA4 recebe', 'Engenharia reversa do GA4: cada evento que chegou no período, de onde ele vem (automático do Google, medição otimizada, recomendado ou personalizado do site) e o que significa. Evento-chave é o que está marcado como conversão no GA4 (Administrador → Eventos-chave).') ?></h2>
  <?php foreach ($hosts as $host => $h): if (!$h['ga4']) { continue; } ?>
  <div class="tabela"><table>
    <thead><tr><th><?= e($host) ?></th><th>De onde vem</th><th>O que é</th><th>Vezes</th><th>Valor</th><th><?= com_info('Chave', 'Sim quando o GA4 conta este evento como evento-chave (conversão).') ?></th></tr></thead>
    <tbody>
    <?php foreach ($h['ga4'] as $ev => [$vezes, $valor, $chave]): [$origem, $explica] = ga4_evento_origem((string)$ev); ?>
      <tr><td><code><?= e($ev) ?></code></td><td><span class="selo <?= $origem === 'Personalizado' ? 'alerta' : 'neutro' ?>"><?= e($origem) ?></span></td>
        <td class="suave ga4-explica"><?= e($explica) ?></td><td><?= e($n($vezes)) ?></td><td><?= $valor > 0 ? e($rs($valor)) : '' ?></td><td><?= $chave > 0 ? 'sim' : '' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endforeach; ?>

  <?php $paginas = ga4_conf_paginas($periodo); if ($paginas): ?>
  <h2><?= com_info('Páginas mais vistas', 'Visualizações de cada página no GA4 (screenPageViews) e no painel (PageView). Página com GA4 e sem painel: falta o t.js. Com painel e sem GA4: falta o gtag.') ?></h2>
  <div class="tabela"><table>
    <thead><tr><th>Página</th><th>GA4</th><th>Painel</th><th>Diferença</th></tr></thead>
    <tbody>
    <?php foreach ($paginas as $pg): ?>
      <tr><td><span class="suave"><?= e($pg['host']) ?></span><?= e($pg['pagina']) ?></td><td><?= e($n($pg['ga4'])) ?></td><td><?= e($n($pg['painel'])) ?></td><td><?= $selo($pg['ga4'], $pg['painel']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>

  <?php $origens = ga4_conf_origens($periodo); if ($origens): ?>
  <h2><?= com_info('Origens', 'Sessões do GA4 por origem (sessionSource) e visitas do painel por utm_source (um visitante por dia). Só comparam as origens com o mesmo nome nos dois: anúncio com etiqueta (ex.: metaads) bate; orgânico tem nomes diferentes (GA4: google, (direct); painel: organico, sem etiqueta).') ?></h2>
  <div class="tabela"><table>
    <thead><tr><th>Origem</th><th>Sessões GA4</th><th>Visitas painel</th><th><?= com_info('Eventos-chave', 'Conversões do GA4 nas sessões desta origem.') ?></th><th>Diferença</th></tr></thead>
    <tbody>
    <?php foreach ($origens as $o): ?>
      <tr><td><?= e($o['origem']) ?></td><td><?= $o['ga4'] === null ? '<span class="suave">—</span>' : e($n($o['ga4'])) ?></td>
        <td><?= $o['painel'] === null ? '<span class="suave">—</span>' : e($n($o['painel'])) ?></td><td><?= $o['chave'] ? e($n($o['chave'])) : '' ?></td>
        <td><?= $selo($o['ga4'], $o['painel']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>

  <h2><?= com_info('Como o GA4 conta', 'O que o próprio Google informa junto de cada relatório, e as regras que explicam as diferenças para o painel.') ?></h2>
  <div class="tabela"><table>
    <thead><tr><th>Propriedade</th><th><?= com_info('Fuso', 'O dia do GA4 é o do fuso da propriedade. Se não for o mesmo do painel, os dias não batem nas pontas.') ?></th><th>Moeda</th>
      <th><?= com_info('Limiar', 'Com o Google Signals ligado e pouco volume, o GA4 esconde números pequenos para proteger a identidade (thresholding). Sim = algum número pode estar faltando.') ?></th>
      <th><?= com_info('Linha (other)', 'Sim quando o relatório passou do limite de linhas e o GA4 juntou o resto numa linha "(other)".') ?></th>
      <th><?= com_info('Cota do dia', 'Tokens da Data API gastos e restantes hoje nesta propriedade (200.000 por dia na versão padrão).') ?></th>
      <th><?= com_info('Personalizadas', 'Dimensões e métricas registradas em Administrador → Definições personalizadas. Parâmetro de evento sem registro não aparece nos relatórios.') ?></th></tr></thead>
    <tbody>
    <?php foreach ($k['propriedades'] as $p): $i = ga4_info($p['id']); $pers = ga4_personalizadas($p['id']); ?>
      <tr><td><?= e($p['nome'] ?: 'Propriedade') ?> <span class="suave"><?= e($p['id']) ?></span></td>
        <td><?= e($i['fuso'] ?? '') ?: '<span class="suave">—</span>' ?><?= !empty($i['fuso']) && $i['fuso'] !== fuso()->getName() ? ' <span class="selo alerta">painel: ' . e(fuso()->getName()) . '</span>' : '' ?></td>
        <td><?= e($i['moeda'] ?? '') ?></td>
        <td><?= isset($i['limiar']) ? ($i['limiar'] ? '<span class="selo alerta">sim</span>' : 'não') : '' ?></td>
        <td><?= isset($i['outros']) ? ($i['outros'] ? '<span class="selo alerta">sim</span>' : 'não') : '' ?></td>
        <td><?= !empty($i['cota_dia']) ? e($n($i['cota_dia']['gasto'])) . ' <span class="suave">gastos · ' . e($n($i['cota_dia']['resta'])) . ' restam</span>' : '' ?></td>
        <td><?php if ($pers === null): ?><span class="suave">—</span><?php elseif (!$pers['lista']): ?><span class="suave">nenhuma</span><?php else: ?>
          <?= implode(', ', array_map(fn($d) => '<code>' . e($d['api']) . '</code>', $pers['lista'])) ?><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <ul class="suave">
    <li>O GA4 conta <strong>eventos</strong>. Sessão termina com 30 minutos sem atividade; usuário é o cookie <code>_ga</code> (aparelho e navegador), não a pessoa.</li>
    <li>Bloqueador de anúncio, Brave, Safari com proteção e quem sai antes de o gtag carregar não chegam ao GA4. O painel recebe pelo próprio domínio, por isso conta mais.</li>
    <li>Origem no GA4 vem das UTMs do link (ou do site anterior). Sem UTM: <code>google / organic</code>, <code>(direct) / (none)</code>, <code>instagram.com / referral</code>.</li>
    <li><code>purchase</code> com o mesmo <code>transaction_id</code> conta uma vez só. Recarregar a página de pedido aprovado não duplica.</li>
  </ul>

  <section class="cartao">
    <h2>Conexão</h2>
    <div class="tabela"><table>
      <tr><th>Conta de serviço</th><td><code><?= e($k['client_email']) ?></code></td></tr>
      <tr><th>Chave privada</th><td class="suave">guardada, não aparece mais</td></tr>
      <?php if (!empty($k['projeto'])): ?><tr><th>Projeto no Google Cloud</th><td><?= e($k['projeto']) ?></td></tr><?php endif; ?>
      <tr><th>Propriedades</th><td><?= e(implode(' · ', array_map(fn($p) => ($p['nome'] ?: 'Propriedade') . ' ' . $p['id'], $k['propriedades']))) ?></td></tr>
      <tr><th>Salva</th><td><?= e(data_local($k['salva_em'] ?? null, 'd/m/Y H:i')) ?> <span class="suave">por <?= e($k['salva_por'] ?? '') ?></span></td></tr>
      <?php $sync = ga4_sync_estado(); ?>
      <tr><th>Última busca</th><td><?= $sync['ok_em'] ? e(data_local($sync['ok_em'], 'd/m/Y H:i')) : '<span class="suave">ainda não feita</span>' ?>
        <?= $sync['adiada'] ? '<br><span class="suave">Adiada: ' . e($sync['adiada']) . '</span>' : ($sync['erro'] ? '<br><span class="erro">' . e($sync['erro']) . '</span>' : '') ?></td></tr>
      <?php $pausa = ga4_api_pausa_ate(); ?>
      <tr><th>Uso da API</th><td><?= ga4_api_uso(3600) ?> chamada(s) na última hora
        <br><span class="suave">Limite interno: <?= ga4_api_limite_minuto() ?> por minuto. Busca sozinha de hora em hora.</span>
        <?= $pausa ? '<br><span class="erro">O Google pediu uma pausa: o painel volta a buscar sozinho às ' . e(data_local(gmdate('Y-m-d H:i:s', $pausa), 'H:i')) . '.</span>' : '' ?></td></tr>
    </table></div>
    <form method="post" action="ga4.php" class="linha-botoes">
      <input type="hidden" name="csrf" value="<?= e(token_csrf()) ?>">
      <button type="submit" name="acao" value="atualizar">Atualizar agora</button>
      <button type="submit" name="acao" value="testar" class="discreto">Testar conexão</button>
      <button type="submit" name="acao" value="remover" class="discreto">Remover conexão</button>
    </form>
  </section>
  <?php endif; ?>

  <section class="cartao">
    <h2><?= $k ? 'Trocar a chave ou as propriedades' : 'Conectar o Google Analytics 4' ?></h2>
    <?php if (!$k): ?>
    <p class="suave">O painel lê o GA4 por uma <strong>conta de serviço</strong>: um acesso só de leitura, sem login de ninguém. Uma vez:</p>
    <ol class="passos suave">
      <li>No <strong>Google Cloud</strong> (console.cloud.google.com), no projeto da empresa: <strong>APIs e serviços → Biblioteca → Google Analytics Data API → Ativar</strong>.</li>
      <li><strong>IAM e administrador → Contas de serviço → Criar conta de serviço</strong> (ex.: painel-ga4), sem papel no projeto.</li>
      <li>Na conta criada: <strong>Chaves → Adicionar chave → Criar nova chave → JSON</strong>. O arquivo baixa sozinho.</li>
      <li>No <strong>GA4</strong>, em cada propriedade: <strong>Administrador → Gerenciamento de acesso da propriedade → + → Adicionar usuários</strong>, com o e-mail da conta (termina em .iam.gserviceaccount.com), papel <strong>Leitor</strong>, sem notificar.</li>
      <li>Abra o arquivo .json num editor de texto, copie tudo e cole abaixo. Depois de salvo aqui, apague o arquivo do computador.</li>
    </ol>
    <?php endif; ?>
    <form method="post" action="ga4.php" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= e(token_csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <label>Arquivo JSON da chave<?= $k ? ' (em branco: continua a chave salva)' : '' ?>
        <textarea name="conta" rows="5" spellcheck="false" autocomplete="off" placeholder='{"type": "service_account", ...}'<?= $k ? '' : ' required' ?>></textarea></label>
      <?php $salvas = $k['propriedades'] ?? []; $padrao = ['Site', 'App', '']; ?>
      <?php for ($i = 1; $i <= GA4_MAX_PROPRIEDADES; $i++): $p = $salvas[$i - 1] ?? ['id' => '', 'nome' => $padrao[$i - 1]]; ?>
      <div class="linha-botoes">
        <label>Nome <input type="text" name="prop<?= $i ?>_nome" value="<?= e($p['nome']) ?>" maxlength="30"></label>
        <label><span>ID da propriedade <?= $i === 1 ? info('Só números. No GA4: Administrador → Detalhes da propriedade. Não é o ID da métrica (G-...). Site e app podem ser propriedades diferentes ou fluxos da mesma propriedade: o painel separa por endereço.') : '' ?></span>
          <input type="text" name="prop<?= $i ?>_id" value="<?= e($p['id']) ?>" inputmode="numeric" autocomplete="off" placeholder="123456789"<?= $i === 1 ? ' required' : '' ?>></label>
      </div>
      <?php endfor; ?>
      <button type="submit">Conferir e salvar</button>
    </form>
  </section>
</main>
<?php
casca_fim();
pagina_fim();
