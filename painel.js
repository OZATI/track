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
  var sair = document.querySelector('[data-sair]');
  var formSair = document.getElementById('form-sair');
  if (sair && formSair) {
    sair.addEventListener('click', function (e) {
      e.preventDefault();
      formSair.submit();
    });
  }
})();
