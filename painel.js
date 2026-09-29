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
})();
