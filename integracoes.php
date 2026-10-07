<?php
// Integracoes: as contas de fora que o painel usa, por aba (Anuncios, Vendas, Organico) e o
// que vai nas paginas e nos anuncios (Rastreio). Cada cartao abre a tela da plataforma.
// Registro, estados e logos: lib/integracoes.php.

require __DIR__ . '/lib/util.php';
require __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/integracoes.php';

exigir_login('integracoes');

$aba = (string)($_GET['aba'] ?? 'anuncios');
if (!isset(INTEGRACOES_ABAS[$aba])) {
    $aba = 'anuncios';
}

// Endereco do painel, para o codigo do t.js na aba Rastreio
$https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
$host = preg_match('/^[a-z0-9.\-]+(:\d+)?$/i', (string)($_SERVER['HTTP_HOST'] ?? '')) ? (string)$_SERVER['HTTP_HOST'] : 'SEU-PAINEL';
$pasta = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
$painel = ($https ? 'https://' : 'http://') . $host . $pasta;

pagina_inicio('Integrações');
casca_inicio('integracoes');
integracoes_topo($aba);
?>
<main class="integ">
  <p class="suave integ-intro"><?= e(INTEGRACOES_ABAS[$aba][2]) ?></p>

  <?php if ($aba !== 'rastreio'): ?>
  <?= integracoes_lista($aba) ?>
  <?php else: ?>
  <section class="cartao integ-cartao">
    <h2><?= com_info('Código do painel nas páginas', 'Conta as visitas e os cliques de cada página e põe no link do checkout o código do visitante (sck), para a venda voltar ligada a ele. Vai no fim do body, depois do script de atribuição da página.') ?></h2>
    <textarea class="integ-codigo" readonly rows="2" spellcheck="false" aria-label="Código do t.js"><?= e('<script src="' . $painel . '/t.js" defer></script>') ?></textarea>
    <?php $origens = (array)((track_config() ?? [])['origens'] ?? []); ?>
    <p class="suave">Sites autorizados a mandar eventos: <?= $origens ? e(implode(', ', $origens)) : 'nenhum ainda' ?>.</p>
  </section>

  <section class="cartao integ-cartao">
    <h2><?= com_info('Parâmetros de URL dos anúncios', 'Cada anúncio leva a campanha, o conjunto e o anúncio no link, com o ID depois do "|". É assim que a venda volta ligada ao anúncio certo, aqui e na UTMify.') ?></h2>
    <h3 class="integ-sub"><?= integracao_logo('meta', 18) ?> Meta Ads <span class="suave">· campo "Parâmetros de URL" do anúncio</span></h3>
    <textarea class="integ-codigo" readonly rows="3" spellcheck="false" aria-label="Parâmetros da Meta">utm_source=MetaAds&amp;utm_campaign={{campaign.name}}|{{campaign.id}}&amp;utm_medium={{adset.name}}|{{adset.id}}&amp;utm_content={{ad.name}}|{{ad.id}}&amp;utm_term={{placement}}</textarea>
    <h3 class="integ-sub"><?= integracao_logo('google', 18) ?> Google Ads <span class="suave">· "Sufixo do URL final" da conta, com a marcação automática ligada</span></h3>
    <textarea class="integ-codigo" readonly rows="3" spellcheck="false" aria-label="Parâmetros do Google">utm_source=GoogleAds&amp;utm_campaign=GoogleAds|{campaignid}&amp;utm_medium=GoogleAds|{adgroupid}&amp;utm_content=GoogleAds|{creative}&amp;utm_term={network}</textarea>
    <p class="suave">O Google não tem o nome da campanha no link, só o ID. O nome vem da conta conectada.</p>
  </section>
  <?php endif; ?>
</main>
<?php
casca_fim();
pagina_fim();
