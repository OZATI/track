<?php
// Configuracoes de quem esta no painel (o link no nome, no canto de cima): instalar o painel
// como app e as notificacoes de venda e de relatorio (lib/push.php). As preferencias sao por
// usuario; a inscricao e por aparelho (notificacoes.php, pelo painel.js).

require __DIR__ . '/lib/util.php';
require __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/push.php';

exigir_login();
$usuario = (string)usuario_atual();
$erros = [];
$aviso = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_valido()) {
        $erros[] = 'Sessão expirada. Recarregue a página.';
    } elseif (($_POST['acao'] ?? '') === 'salvar') {
        push_salvar_prefs($usuario, [
            'aprovadas' => ($_POST['aprovadas'] ?? '') === '1', 'pendentes' => ($_POST['pendentes'] ?? '') === '1',
            'valor' => ($_POST['valor'] ?? '') === '1', 'produto' => ($_POST['produto'] ?? '') === '1',
            'campanha' => ($_POST['campanha'] ?? '') === '1', 'canal' => ($_POST['canal'] ?? '') === '1', 'nome' => ($_POST['nome'] ?? '') === '1',
            'relatorio_horas' => (array)($_POST['horas'] ?? []), 'relatorio_padrao' => (string)($_POST['padrao'] ?? ''),
        ]);
        $nome = texto($_POST['app_nome'] ?? '', 30);
        $cfg = track_config();
        if ($nome !== '' && $nome !== ($cfg['app_nome'] ?? '')) {
            $cfg['app_nome'] = $nome;
            track_salvar_config($cfg);
        }
        $aviso = 'Configurações salvas.';
    }
}

$p = push_prefs($usuario);
$aparelhos = push_aparelhos($usuario);
$cronUltimo = ajuste('cron_ultimo');
$cronVivo = $cronUltimo && strtotime($cronUltimo . ' UTC') >= time() - 20 * 60;
$exemplo = ['pedido' => 'exemplo', 'produto' => 'Drive de Projetos 2.0', 'valor' => 6700, 'pagamento' => 'pix',
    'utm_source' => 'MetaAds', 'utm_medium' => 'conjunto|111', 'utm_term' => 'Instagram_Reels', 'utm_campaign' => 'TL 1|120120'];
$previaVenda = push_msg_venda($exemplo, 'aprovada', ['aprovadas' => true] + $p);
$previaRelatorio = push_com_nome(relatorio_msg(['fat' => 71062, 'vendas' => 9, 'gasto' => 29815, 'imposto' => 3623, 'lucro' => 37624, 'roi' => 2.13], $p['relatorio_padrao'], 23), $p);
$nomeApp = track_config()['app_nome'] ?? 'Painel de vendas';

// Uma opcao Mostrar/Esconder ou Habilitado/Desabilitado, com o (i)
$opcao = function (string $campo, string $rotulo, string $dica, bool $ligado, string $sim, string $nao): string {
    return '<label class="cfg-campo"><span>' . com_info($rotulo, $dica) . '</span><select name="' . e($campo) . '" data-previa>'
        . '<option value="1"' . ($ligado ? ' selected' : '') . '>' . e($sim) . '</option><option value="0"' . (!$ligado ? ' selected' : '') . '>' . e($nao) . '</option></select></label>';
};
$previa = fn(array $m) => '<div class="cfg-previa"><span class="cfg-previa-icone">' . icone('trafego', 18) . '</span><div><b>' . e($m['titulo']) . '</b>'
    . ($m['corpo'] !== '' ? '<span>' . e($m['corpo']) . '</span>' : '') . '</div></div>';

pagina_inicio('Configurações');
casca_inicio();
topo_pagina();
abas_painel('configuracoes');
?>
<main class="cfg">
  <?php foreach ($erros as $erro): ?><p class="erro"><?= e($erro) ?></p><?php endforeach; ?>
  <?php if ($aviso): ?><p class="aviso-ok"><?= e($aviso) ?></p><?php endif; ?>
  <h1 class="cfg-titulo">Configurações <span class="suave">· <?= e($usuario) ?></span></h1>

  <div class="cfg-grade">
    <section class="cartao" data-app>
      <h2><?= com_info('Aplicativo', 'Instala o painel como um app no celular ou no computador: abre em tela cheia, com ícone próprio, e recebe as notificações.') ?></h2>
      <p class="suave" data-app-estado>Veja abaixo como instalar neste aparelho.</p>
      <button type="button" data-instalar hidden>Instalar o app</button>
      <p class="suave" data-ios hidden>No iPhone: toque em <strong>Compartilhar</strong> e em <strong>Adicionar à Tela de Início</strong>. As notificações no iPhone só funcionam com o app instalado assim.</p>
      <p class="suave" data-outro hidden>Neste navegador, use o menu e a opção <strong>Instalar app</strong> ou <strong>Adicionar à tela inicial</strong>. No computador, o Chrome e o Edge mostram o botão de instalar na barra de endereço.</p>
    </section>

    <section class="cartao" data-push>
      <h2><?= com_info('Notificações neste aparelho', 'Liga as notificações só neste aparelho. Ligue em cada celular ou computador em que quiser receber.') ?></h2>
      <p class="suave" data-push-estado>Verificando…</p>
      <div class="linha-botoes">
        <button type="button" data-push-ligar hidden>Ligar notificações</button>
        <button type="button" data-push-testar class="discreto neutro" hidden>Enviar um teste</button>
        <button type="button" data-push-desligar class="discreto" hidden>Desligar neste aparelho</button>
      </div>
      <?php if ($aparelhos): ?>
      <p class="suave cfg-aparelhos"><?= count($aparelhos) ?> aparelho(s) seu(s) com notificações:
        <?= e(implode(', ', array_map(fn($a) => ($a['aparelho'] ?: 'aparelho') . ' (desde ' . data_local($a['criado_em'], 'd/m') . ')', $aparelhos))) ?></p>
      <?php endif; ?>
    </section>

    <section class="cartao">
      <h2><?= com_info('Aparência', 'Claro, escuro ou qualquer cor de fundo (até o preto puro, #000000). O resto das cores se ajusta à escolhida. Vale só para o seu usuário, em todos os aparelhos; também abre pela paleta no topo.') ?></h2>
      <?= tema_form('configuracoes.php') ?>
    </section>
  </div>

  <form method="post" action="configuracoes.php" class="cfg-grade">
    <input type="hidden" name="csrf" value="<?= e(token_csrf()) ?>"><input type="hidden" name="acao" value="salvar">
    <section class="cartao">
      <h2><?= com_info('Notificações de venda', 'Chegam na hora em que a venda entra no painel (pelo webhook da Kiwify ou pela busca na API).') ?></h2>
      <p class="suave">Seja avisado sempre que entrar uma venda:</p>
      <?= $opcao('aprovadas', 'Enviar vendas aprovadas', 'Avisa quando o pagamento é aprovado.', $p['aprovadas'], 'Habilitado', 'Desabilitado') ?>
      <?= $opcao('pendentes', 'Enviar vendas pendentes', 'Avisa quando alguém gera um Pix ou boleto, antes de pagar.', $p['pendentes'], 'Habilitado', 'Desabilitado') ?>
      <?= $opcao('valor', 'Valor da venda', 'Mostra o valor cobrado na notificação. Esconda se o celular fica à vista de outras pessoas.', $p['valor'], 'Mostrar', 'Esconder') ?>
      <?= $opcao('produto', 'Nome do produto', 'Mostra o produto vendido no título.', $p['produto'], 'Mostrar', 'Esconder') ?>
      <?= $opcao('canal', 'Canal da venda', 'Mostra de onde a venda veio (Instagram · anúncio, orgânico, direto...). Só o nosso painel tem.', $p['canal'], 'Mostrar', 'Esconder') ?>
      <?= $opcao('campanha', 'Campanha (utm_campaign)', 'Mostra o nome da campanha da Meta que trouxe a venda.', $p['campanha'], 'Mostrar', 'Esconder') ?>
      <?= $opcao('nome', 'Nome do painel', 'Começa o título pelo nome do app (campo Nome do app, em Notificações de relatório). Útil para quem acompanha mais de um painel no mesmo celular.', $p['nome'], 'Mostrar', 'Esconder') ?>
      <h3>Prévia</h3>
      <?= $previa($previaVenda) ?>
    </section>

    <section class="cartao">
      <h2><?= com_info('Notificações de relatório', 'Um resumo do dia nos horários escolhidos. Dependem da tarefa agendada da hospedagem (a cada 5 minutos).') ?></h2>
      <?php if (!$cronVivo): ?>
      <p class="erro">A tarefa agendada ainda não está rodando no servidor<?= $cronUltimo ? ' (última vez em ' . e(data_local($cronUltimo, 'd/m H:i')) . ')' : '' ?>: os relatórios não saem até ela ser ligada na hospedagem.</p>
      <?php endif; ?>
      <p class="suave">Horários:</p>
      <div class="cfg-horas">
        <?php foreach (PUSH_HORAS as $h): ?>
        <label class="cfg-chave"><span><?= e(($h === 8 ? 'Das 08:00 (resultado de ontem)' : 'Das ' . $h . ':00')) ?></span>
          <input type="checkbox" name="horas[]" value="<?= $h ?>"<?= in_array($h, $p['relatorio_horas'], true) ? ' checked' : '' ?>><i aria-hidden="true"></i></label>
        <?php endforeach; ?>
      </div>
      <p class="suave">Padrão da notificação:</p>
      <div class="cfg-padroes">
        <label><input type="radio" name="padrao" value="lucro"<?= $p['relatorio_padrao'] === 'lucro' ? ' checked' : '' ?>> <b>Status de lucro</b> <span class="suave">quanto lucrou até a hora</span></label>
        <label><input type="radio" name="padrao" value="detalhado"<?= $p['relatorio_padrao'] === 'detalhado' ? ' checked' : '' ?>> <b>Resumo detalhado</b> <span class="suave">faturamento, gasto, lucro, ROI e vendas</span></label>
        <label><input type="radio" name="padrao" value="criativo"<?= $p['relatorio_padrao'] === 'criativo' ? ' checked' : '' ?>> <b>Notificações criativas</b> <span class="suave">uma frase para cada horário, com o lucro e as vendas</span></label>
      </div>
      <h3>Prévia (23h)</h3>
      <?= $previa($previaRelatorio) ?>
      <label class="cfg-campo"><span><?= com_info('Nome do app', 'O nome que aparece embaixo do ícone quando o painel é instalado.') ?></span>
        <input type="text" name="app_nome" maxlength="30" value="<?= e($nomeApp) ?>"></label>
      <div class="linha-botoes"><button type="submit">Salvar</button></div>
    </section>
  </form>
</main>
<?php
casca_fim();
pagina_fim();
