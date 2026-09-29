// Filtros do topo: mudar uma caixa ja atualiza o painel. Mudar o dominio zera a pagina.
(function () {
  'use strict';
  var form = document.getElementById('filtros');
  if (form) {
    form.addEventListener('change', function (e) {
      var alvo = e.target;
      if (alvo && alvo.getAttribute('data-reinicia') === 'pagina' && form.elements.pagina) {
        form.elements.pagina.value = '';
      }
      form.submit();
    });
  }
  // Vendas pela API da Kiwify em segundo plano (so quando a ultima busca tem mais de
  // 10 minutos). Chegou venda nova ou mudou alguma: recarrega a tela com os numeros.
  var sync = document.querySelector('[data-sync]');
  var token = document.querySelector('#form-sair input[name=csrf]');
  if (sync && token && window.fetch) {
    var texto = sync.querySelector('[data-sync-texto]');
    if (texto) { texto.textContent = 'Buscando vendas na API da Kiwify…'; }
    fetch('sincronizar.php', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF': token.value } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d && d.buscou && (d.novas || d.atualizadas || !d.ok)) { location.reload(); return; }
        if (texto) { texto.textContent = 'Vendas da Kiwify pelo webhook e pela API · nada novo agora'; }
      })
      .catch(function () {
        if (texto) { texto.textContent = 'Não foi possível buscar as vendas agora. Use o botão Atualizar vendas.'; }
      });
  }

  // (i): caixa com a explicacao, fixa na tela (nao e cortada por tabela com rolagem)
  var caixa = null;
  function mostrar(alvo) {
    var texto = alvo.getAttribute('data-dica');
    if (!texto) { return; }
    if (!caixa) { caixa = document.createElement('div'); caixa.className = 'dica'; caixa.setAttribute('role', 'tooltip'); document.body.appendChild(caixa); }
    caixa.textContent = texto;
    caixa.style.display = 'block';
    var r = alvo.getBoundingClientRect();
    var larg = caixa.offsetWidth, alt = caixa.offsetHeight;
    var x = Math.min(Math.max(8, r.left + r.width / 2 - larg / 2), window.innerWidth - larg - 8);
    var y = r.bottom + 6 + alt > window.innerHeight ? r.top - alt - 6 : r.bottom + 6;
    caixa.style.left = x + 'px';
    caixa.style.top = y + 'px';
  }
  function esconder() { if (caixa) { caixa.style.display = 'none'; } }
  document.addEventListener('mouseover', function (e) { var a = e.target.closest && e.target.closest('[data-dica]'); if (a) { mostrar(a); } });
  document.addEventListener('mouseout', function (e) { if (e.target.closest && e.target.closest('[data-dica]')) { esconder(); } });
  document.addEventListener('focusin', function (e) { if (e.target.getAttribute && e.target.getAttribute('data-dica')) { mostrar(e.target); } });
  document.addEventListener('focusout', esconder);
  window.addEventListener('scroll', esconder, true);
})();
