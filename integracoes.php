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

if (function_exists('track_host_integrations')) {
    track_host_integrations($aba);
    exit;
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
<main class="integ store-container">
  <!-- Topo de Busca Estilo Shopify App Store -->
  <div class="store-topbar">
    <div class="store-search-wrap">
      <span class="store-search-icon" aria-hidden="true"><?= icone('buscar', 18) ?></span>
      <input type="search" id="integ-busca" class="store-search-input" placeholder="Buscar integrações, webhooks e plataformas..." autocomplete="off" spellcheck="false" aria-label="Buscar integrações">
      <kbd class="store-search-kbd" title="Pressione / para buscar">/</kbd>
    </div>
    <div class="store-topbar-stats">
      <span class="store-stat-pill"><span class="store-stat-dot"></span> <?= count(integracoes_registro()) ?> plataformas disponíveis</span>
    </div>
  </div>

  <?php if ($aba !== 'rastreio'): ?>
  <!-- Banner Hero "Built for Track" Estilo Shopify App Store -->
  <?= integracoes_hero() ?>

  <div class="store-section-header">
    <div>
      <h2 class="store-section-title"><?= e(INTEGRACOES_ABAS[$aba][0]) ?> recomendadas para sua operação</h2>
      <p class="suave store-section-sub"><?= e(INTEGRACOES_ABAS[$aba][2]) ?></p>
    </div>
  </div>

  <div class="store-layout-grid">
    <div class="store-apps-col">
      <?= integracoes_lista($aba) ?>
    </div>

    <!-- Coluna Lateral Editorial (Card Coral + Status de Conexões) -->
    <aside class="store-editorial-col" aria-label="Guias e Status">
      <div class="store-editorial-card">
        <span class="store-editorial-kicker">Guia Oficial de Rastreio</span>
        <h3 class="store-editorial-title">Arquitetura de Conversão 2026: Webhooks + CAPI sem perdas</h3>
        <p class="store-editorial-text">Entenda como o identificador único <code>sck</code> e a reconciliação inteligente por API garantem 100% de precisão no ROI real por anúncio.</p>
        <a href="integracoes.php?aba=rastreio" class="store-editorial-btn">
          Ver guia técnico <?= icone('avancar', 15) ?>
        </a>
      </div>

      <div class="store-status-card cartao">
        <h3 class="store-status-title">Status da sua conta</h3>
        <ul class="store-status-list">
          <?php
          $reg = integracoes_registro();
          $principais = ['meta', 'kiwify', 'instagram', 'google'];
          foreach ($principais as $pid) {
              if (!isset($reg[$pid])) continue;
              $item = $reg[$pid];
              $st = $item['tela'] ? integracao_estado($item) : ['ligada' => false, 'erro' => null];
              echo '<li class="store-status-item">'
                  . integracao_logo($pid, 24)
                  . '<span class="store-status-name">' . e($item['nome']) . '</span>'
                  . '<span class="store-status-val">';
              if (!$item['tela']) {
                  echo '<span class="selo neutro sm">' . e($item['breve'] ?? 'Em breve') . '</span>';
              } elseif ($st['erro']) {
                  echo '<span class="selo erro sm">Erro</span>';
              } elseif ($st['ligada']) {
                  echo '<span class="selo ok sm">Conectado</span>';
              } else {
                  echo '<span class="selo alerta sm">Desconectado</span>';
              }
              echo '</span></li>';
          }
          ?>
        </ul>
        <div class="store-status-foot">
          <small class="suave">Sincronização contínua via Graph API e Webhook.</small>
        </div>
      </div>
    </aside>
  </div>

  <?php else: ?>
  <!-- Aba Rastreio: Ferramentas do Desenvolvedor -->
  <div class="store-section-header">
    <div>
      <h2 class="store-section-title">Instalação e Rastreio Técnico</h2>
      <p class="suave store-section-sub"><?= e(INTEGRACOES_ABAS[$aba][2]) ?></p>
    </div>
  </div>

  <div class="store-dev-grid">
    <section class="cartao integ-cartao store-dev-card">
      <div class="store-dev-card-head">
        <div class="store-dev-icon"><?= icone('codigo', 20) ?></div>
        <div>
          <h2><?= com_info('Código do painel nas páginas', 'Conta as visitas e os cliques de cada página e põe no link do checkout o código do visitante (sck), para a venda voltar ligada a ele. Vai no fim do body, depois do script de atribuição da página.') ?></h2>
          <p class="suave">Insira esta tag antes do fechamento <code>&lt;/body&gt;</code> em todas as páginas da sua oferta.</p>
        </div>
      </div>
      <div class="store-code-box">
        <textarea class="integ-codigo" readonly rows="2" spellcheck="false" aria-label="Código do t.js"><?= e('<script src="' . $painel . '/t.js" defer></script>') ?></textarea>
        <button type="button" class="btn store-copy-btn" onclick="copiarCodigo(this)">Copiar script</button>
      </div>
      <?php $origens = (array)((track_config() ?? [])['origens'] ?? []); ?>
      <p class="suave store-dev-origens">Domínios autorizados a enviar dados: <b><?= $origens ? e(implode(', ', $origens)) : 'nenhum ainda' ?></b>.</p>
    </section>

    <section class="cartao integ-cartao store-dev-card">
      <div class="store-dev-card-head">
        <div class="store-dev-icon"><?= icone('link', 20) ?></div>
        <div>
          <h2><?= com_info('Parâmetros de URL dos anúncios', 'Cada anúncio leva a campanha, o conjunto e o anúncio no link, com o ID depois do "|". É assim que a venda volta ligada ao anúncio certo, aqui e na UTMify.') ?></h2>
          <p class="suave">Configure os modelos de rastreamento oficiais nos gerenciadores de anúncios.</p>
        </div>
      </div>

      <div class="store-dev-param-block">
        <h3 class="integ-sub"><?= integracao_logo('meta', 18) ?> Meta Ads <span class="suave">· campo "Parâmetros de URL" do anúncio</span></h3>
        <div class="store-code-box">
          <textarea class="integ-codigo" readonly rows="3" spellcheck="false" aria-label="Parâmetros da Meta">utm_source=MetaAds&amp;utm_campaign={{campaign.name}}|{{campaign.id}}&amp;utm_medium={{adset.name}}|{{adset.id}}&amp;utm_content={{ad.name}}|{{ad.id}}&amp;utm_term={{placement}}</textarea>
          <button type="button" class="btn store-copy-btn" onclick="copiarCodigo(this)">Copiar parâmetros</button>
        </div>
      </div>

      <div class="store-dev-param-block">
        <h3 class="integ-sub"><?= integracao_logo('google', 18) ?> Google Ads <span class="suave">· "Sufixo do URL final" da conta, com a marcação automática ligada</span></h3>
        <div class="store-code-box">
          <textarea class="integ-codigo" readonly rows="3" spellcheck="false" aria-label="Parâmetros do Google">utm_source=GoogleAds&amp;utm_campaign=GoogleAds|{campaignid}&amp;utm_medium=GoogleAds|{adgroupid}&amp;utm_content=GoogleAds|{creative}&amp;utm_term={network}</textarea>
          <button type="button" class="btn store-copy-btn" onclick="copiarCodigo(this)">Copiar parâmetros</button>
        </div>
        <p class="suave">O Google não tem o nome da campanha no link, só o ID. O nome vem da conta conectada.</p>
      </div>
    </section>
  </div>
  <?php endif; ?>

  <script>
  (function(){
    var busca = document.getElementById('integ-busca');
    if (busca) {
      busca.addEventListener('input', function(e) {
        var q = (e.target.value || '').toLowerCase().trim();
        var itens = document.querySelectorAll('.integ-item');
        itens.forEach(function(it) {
          var t = (it.textContent || '').toLowerCase();
          it.style.display = (!q || t.indexOf(q) !== -1) ? '' : 'none';
        });
      });
      window.addEventListener('keydown', function(e) {
        if (e.key === '/' && document.activeElement !== busca && !['INPUT','TEXTAREA','SELECT'].includes(document.activeElement.tagName)) {
          e.preventDefault();
          busca.focus();
        }
      });
    }
    window.copiarCodigo = function(btn) {
      var box = btn.closest('.store-code-box');
      if (!box) return;
      var ta = box.querySelector('textarea');
      if (!ta) return;
      navigator.clipboard.writeText(ta.value).then(function() {
        var orig = btn.textContent;
        btn.textContent = 'Copiado!';
        btn.classList.add('btn-copiado');
        setTimeout(function(){ btn.textContent = orig; btn.classList.remove('btn-copiado'); }, 2000);
      });
    };
  })();
  </script>
</main>
<?php
casca_fim();
pagina_fim();
