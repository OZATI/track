/*
 * Painel de rastreio (OZATI/track): manda os eventos da pagina para o painel e liga a
 * venda da Kiwify ao visitante.
 *
 * Uso, no fim do <body>, DEPOIS do script de atribuicao da pagina:
 *   <script src="https://track.SEU-DOMINIO/t.js" defer></script>
 *
 * - PageView ao abrir; CliqueCheckout, WhatsApp e Botao nos cliques (todos, e um log).
 * - Poe sck=trk_<visitante> no link do checkout: a Kiwify devolve o sck na venda, e o
 *   painel liga a compra a quem clicou. A pagina precisa ter o script da UTMify com
 *   data-utmify-prevent-xcod-sck, para a UTMify nao sobrescrever o sck.
 * - Nao manda nada para Meta, Google ou UTMify. So para o painel.
 */
(function () {
  'use strict';

  var script = document.currentScript;
  if (!script || !window.fetch) return;
  var PAINEL = new URL(script.src).origin;
  var CHAVE = 'trk_vid';
  var vid = null;
  try { vid = localStorage.getItem(CHAVE); } catch (e) {}

  // Etiquetas de um endereco (padrao: a pagina). No clique do checkout, as do LINK da
  // Kiwify: e o que a pagina de fato mandou, e o painel compara com o que a Kiwify gravou.
  function etiquetas(endereco) {
    var q, o = {};
    try { q = new URL(endereco || location.href).searchParams; } catch (e) { return o; }
    ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'].forEach(function (k) {
      var v = q.get(k);
      if (v) o[k] = v;
    });
    return o;
  }

  function ehCheckout(a) {
    var href = a.getAttribute('href') || '';
    return a.classList.contains('js-checkout') || href.indexOf('pay.kiwify.com.br') !== -1;
  }

  // O identificador vai no sck do link da Kiwify. Roda depois do script de atribuicao
  // da pagina (que remonta o link no clique), por isso escuta no document.
  function marcar(a) {
    if (!vid) return;
    try {
      var u = new URL(a.href);
      u.searchParams.set('sck', 'trk_' + vid);
      a.href = u.toString();
    } catch (e) {}
  }
  function marcarTodos() {
    Array.prototype.forEach.call(document.querySelectorAll('a'), function (a) { if (ehCheckout(a)) marcar(a); });
  }

  function enviar(evento, detalhe, endereco) {
    var corpo = JSON.stringify({
      evento: evento,
      detalhe: detalhe || '',
      url: location.href.split('#')[0],
      referrer: document.referrer,
      utms: etiquetas(endereco),
      vid: vid
    });
    try {
      fetch(PAINEL + '/coletar.php', {
        method: 'POST',
        body: corpo,
        credentials: 'include',
        keepalive: true,
        headers: { 'Content-Type': 'text/plain' }
      }).then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) {
          if (d && d.vid) {
            vid = d.vid;
            try { localStorage.setItem(CHAVE, vid); } catch (e) {}
            marcarTodos();
          }
        })
        .catch(function () {});
    } catch (e) {}
  }

  function alvo(e) {
    return e.target && e.target.closest ? e.target.closest('a, button') : null;
  }

  document.addEventListener('pointerdown', function (e) {
    var a = alvo(e);
    if (a && a.tagName === 'A' && ehCheckout(a)) marcar(a);
  });

  document.addEventListener('click', function (e) {
    var a = alvo(e);
    if (!a) return;
    var href = a.getAttribute('href') || '';
    var texto = (a.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 80);
    if (a.tagName === 'A' && ehCheckout(a)) {
      marcar(a);
      enviar('CliqueCheckout', texto, a.href);
    } else if (/wa\.me|api\.whatsapp\.com|whatsapp:/i.test(href)) {
      enviar('WhatsApp', texto);
    } else if (a.hasAttribute('data-botao')) {
      enviar('Botao', a.getAttribute('data-botao'));
    }
  });

  enviar('PageView');
  marcarTodos();
})();
