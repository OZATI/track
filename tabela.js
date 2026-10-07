// Tabela inteligente (lib/componentes.php: tabela_card_inicio e tabela_card_fim). Vale para o painel
// UTM e para o CMS do site: Tabela.ligar(raiz) depois de desenhar a tela.
// - Busca: filtra as linhas na hora, sem acento e sem diferenca de maiuscula.
// - Filtros: listas de varios (com "Todos") das colunas com data-filtro no <th>.
// - Ocultos: marca e desmarca colunas; a contagem fica no botao.
// - Por pagina (10, 25, 50, 100 ou Todas), com "Mostrando 1-10 de N" e as setas de pagina.
// - Restaurar: sem busca, sem filtro, todas as colunas.
// As colunas ocultas e o por pagina ficam guardados neste navegador, por tabela. A linha do
// total (tr.total) e a da tabela vazia (td com colspan) ficam sempre. Quem reordena as linhas
// (o painel.js, ao clicar no titulo) avisa com o evento "tabela:mudou" na <table>; a linha com a
// classe "fora-filtro" (posta por quem filtra por fora, ex.: so as marcadas do gestor) fica de fora.
// Tabela.lembrar() guarda a pagina, a busca e os filtros de cada tabela para a proxima vez que ela
// for desenhada (depois de ligar, pausar ou mudar o orcamento no painel, ou do CMS redesenhar a
// tela, a tabela volta como estava).
// Tambem aqui: o menu "..." (menu_linha), um aberto por vez, que abre junto do botao por cima de
// tudo (a rolagem da tabela nao corta) e fecha ao clicar fora, rolar a tela ou com o Esc.
(function () {
  'use strict';
  function sem(t) { return String(t || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/\s+/g, ' ').trim(); }
  function ler(chave) { try { return JSON.parse(localStorage.getItem('tabela:' + chave) || '{}') || {}; } catch (x) { return {}; } }
  function guardar(chave, v) { try { localStorage.setItem('tabela:' + chave, JSON.stringify(v)); } catch (x) {} }
  function rotulo(th) {
    var c = th.cloneNode(true);
    Array.prototype.forEach.call(c.querySelectorAll('.info, .redim'), function (i) { i.parentNode.removeChild(i); });
    return c.textContent.replace(/\s+/g, ' ').replace(/[↑↓]/g, '').trim();
  }
  var aberto = null;
  var guardados = {};
  function fecharPop() { if (aberto) { aberto.pop.hidden = true; aberto.botao.setAttribute('aria-expanded', 'false'); aberto = null; } }
  document.addEventListener('click', function (e) { if (aberto && !aberto.pop.contains(e.target) && !aberto.botao.contains(e.target)) { fecharPop(); } });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && aberto) { var b = aberto.botao; fecharPop(); b.focus(); } });

  function ligarCard(card) {
    if (card.__tabela) { return; }
    var tabela = card.querySelector('table');
    if (!tabela) { return; }
    card.__tabela = true;
    var chave = card.getAttribute('data-tabela');
    var salvo = ler(chave);
    var padraoPorPagina = parseInt(card.getAttribute('data-por-pagina') || '25', 10);
    var cab = tabela.tHead && tabela.tHead.rows.length ? tabela.tHead.rows[0] : tabela.rows[0];
    var ths = Array.prototype.slice.call(cab.cells);
    var g = guardados[chave] || {};
    delete guardados[chave];
    var estado = { busca: g.busca || '', filtros: g.filtros || {}, ocultas: salvo.ocultas || [], porPagina: salvo.porPagina !== undefined ? salvo.porPagina : padraoPorPagina, pagina: g.pagina || 0 };
    card.__estado = estado;
    var busca = card.querySelector('[data-tabela-busca]');
    var porPagina = card.querySelector('[data-tabela-por-pagina]');
    var mostrando = card.querySelector('[data-tabela-mostrando]');
    var paginas = card.querySelector('[data-tabela-paginas]');
    var conta = card.querySelector('[data-tabela-conta]');
    var pe = card.querySelector('.tcard-pe');
    var bFiltros = card.querySelector('[data-tabela-filtros]');
    var bOcultos = card.querySelector('[data-tabela-ocultos]');
    var bRestaurar = card.querySelector('[data-tabela-restaurar]');
    var ferr = card.querySelector('.tcard-ferr');

    function linhas() {
      var todas = [];
      Array.prototype.forEach.call(tabela.tBodies, function (tb) { Array.prototype.forEach.call(tb.rows, function (tr) { todas.push(tr); }); });
      return todas.filter(function (tr) { return tr !== cab && !tr.classList.contains('total') && !tr.querySelector('td[colspan]') && !tr.querySelector('th'); });
    }
    // Coluna de cada celula, contando colspan
    function celulaNa(tr, idx) {
      var col = 0;
      for (var i = 0; i < tr.cells.length; i++) {
        if (col === idx) { return tr.cells[i]; }
        col += tr.cells[i].colSpan || 1;
        if (col > idx) { return null; }
      }
      return null;
    }
    function chaveCol(th, i) { return th.getAttribute('data-col') || ('c' + i); }
    var colFiltro = ths.map(function (th, i) { return th.hasAttribute('data-filtro') ? i : -1; }).filter(function (i) { return i >= 0; });
    // Colunas que podem sumir: as com titulo, menos a primeira e as de marcar
    var colOcultavel = ths.map(function (th, i) { return i > 0 && !th.classList.contains('marca') && rotulo(th) !== '' ? i : -1; }).filter(function (i) { return i >= 0; });
    function valorFiltro(tr, i) { var td = celulaNa(tr, i); return td ? (td.getAttribute('data-valor') || td.textContent.replace(/\s+/g, ' ').trim()) : ''; }

    function aplicar() {
      var q = sem(estado.busca);
      var todas = linhas();
      var visiveis = todas.filter(function (tr) {
        if (tr.classList.contains('fora-filtro')) { return false; }
        if (q && sem(tr.textContent).indexOf(q) === -1) { return false; }
        for (var k in estado.filtros) {
          if (estado.filtros[k].length && estado.filtros[k].indexOf(valorFiltro(tr, +k)) === -1) { return false; }
        }
        return true;
      });
      var n = visiveis.length;
      var pp = estado.porPagina > 0 ? estado.porPagina : n || 1;
      var paginasN = Math.max(1, Math.ceil(n / pp));
      if (estado.pagina >= paginasN) { estado.pagina = paginasN - 1; }
      var ini = estado.pagina * pp, fim = Math.min(n, ini + pp);
      todas.forEach(function (tr) { tr.hidden = true; });
      visiveis.slice(ini, fim).forEach(function (tr) { tr.hidden = false; });
      // Colunas ocultas
      var ocultasIdx = ths.map(function (th, i) { return estado.ocultas.indexOf(chaveCol(th, i)) !== -1 ? i : -1; }).filter(function (i) { return i >= 0; });
      Array.prototype.forEach.call(tabela.rows, function (tr) {
        var col = 0;
        for (var c = 0; c < tr.cells.length; c++) {
          var span = tr.cells[c].colSpan || 1;
          tr.cells[c].classList.toggle('col-oculta', span === 1 && ocultasIdx.indexOf(col) !== -1);
          col += span;
        }
      });
      if (conta) { conta.textContent = String(n); }
      if (mostrando) {
        mostrando.textContent = n ? 'Mostrando ' + (ini + 1) + '–' + fim + ' de ' + n + ' ' + (n === 1 ? mostrando.getAttribute('data-um') : mostrando.getAttribute('data-varios'))
          : 'Nada encontrado' + (q || Object.keys(estado.filtros).some(function (k) { return estado.filtros[k].length; }) ? ' com essa busca ou esses filtros' : '');
      }
      if (paginas) {
        paginas.textContent = '';
        if (paginasN > 1) {
          var ant = document.createElement('button'), prox = document.createElement('button'), txt = document.createElement('span');
          ant.type = prox.type = 'button';
          ant.className = prox.className = 'discreto neutro tcard-icone';
          ant.textContent = '‹'; prox.textContent = '›';
          ant.setAttribute('aria-label', 'Página anterior'); prox.setAttribute('aria-label', 'Próxima página');
          ant.disabled = estado.pagina === 0; prox.disabled = estado.pagina >= paginasN - 1;
          ant.addEventListener('click', function () { estado.pagina--; aplicar(); });
          prox.addEventListener('click', function () { estado.pagina++; aplicar(); });
          txt.textContent = (estado.pagina + 1) + ' de ' + paginasN;
          paginas.appendChild(ant); paginas.appendChild(txt); paginas.appendChild(prox);
        }
      }
      var nFiltros = Object.keys(estado.filtros).filter(function (k) { return estado.filtros[k].length; }).length;
      var marcaF = card.querySelector('[data-tabela-n-filtros]');
      if (marcaF) { marcaF.hidden = !nFiltros; marcaF.textContent = String(nFiltros); }
      var marcaO = card.querySelector('[data-tabela-n-ocultos]');
      if (marcaO) { marcaO.textContent = String(estado.ocultas.length); }
      if (bRestaurar) { bRestaurar.hidden = !(q || nFiltros || estado.ocultas.length); }
      guardar(chave, { ocultas: estado.ocultas, porPagina: estado.porPagina });
    }

    // Painel que abre embaixo do botao (Filtros, Ocultos)
    function pop(botao, montar) {
      var p = document.createElement('div');
      p.className = 'tcard-pop';
      p.hidden = true;
      p.setAttribute('role', 'dialog');
      ferr.appendChild(p);
      botao.setAttribute('aria-expanded', 'false');
      botao.addEventListener('click', function (e) {
        e.stopPropagation();
        if (aberto && aberto.pop === p) { fecharPop(); return; }
        fecharPop();
        montar(p);
        p.hidden = false;
        botao.setAttribute('aria-expanded', 'true');
        aberto = { pop: p, botao: botao };
      });
    }
    function caixa(texto, marcado, aoMudar, forte) {
      var l = document.createElement('label');
      if (forte) { l.className = 'tcard-todos'; }
      var c = document.createElement('input');
      c.type = 'checkbox';
      c.checked = marcado;
      c.addEventListener('change', function () { aoMudar(c.checked); });
      l.appendChild(c);
      l.appendChild(document.createTextNode(texto));
      return l;
    }

    if (bFiltros && colFiltro.length) {
      bFiltros.hidden = false;
      pop(bFiltros, function (p) {
        p.textContent = '';
        colFiltro.forEach(function (i) {
          var titulo = document.createElement('b');
          titulo.textContent = rotulo(ths[i]);
          p.appendChild(titulo);
          var valores = [];
          linhas().forEach(function (tr) { var v = valorFiltro(tr, i); if (v && valores.indexOf(v) === -1) { valores.push(v); } });
          valores.sort(function (a, b) { return a.localeCompare(b, 'pt-BR'); });
          var marcados = estado.filtros[i] || [];
          var todos = caixa('Todos', !marcados.length, function (sim) {
            if (sim) { estado.filtros[i] = []; estado.pagina = 0; aplicar(); montarDeNovo(); }
            else { todos.querySelector('input').checked = true; }
          }, true);
          p.appendChild(todos);
          valores.forEach(function (v) {
            p.appendChild(caixa(v, marcados.indexOf(v) !== -1, function (sim) {
              var lista = (estado.filtros[i] || []).slice();
              if (sim) { lista.push(v); } else { lista = lista.filter(function (x) { return x !== v; }); }
              estado.filtros[i] = lista;
              estado.pagina = 0;
              aplicar();
              todos.querySelector('input').checked = !lista.length;
            }));
          });
        });
        function montarDeNovo() { Array.prototype.forEach.call(p.querySelectorAll('input'), function (c) { if (!c.parentNode.classList.contains('tcard-todos')) { c.checked = false; } }); }
      });
    }
    if (bOcultos && colOcultavel.length > 1) {
      bOcultos.hidden = false;
      pop(bOcultos, function (p) {
        p.textContent = '';
        var titulo = document.createElement('b');
        titulo.textContent = 'Colunas à mostra';
        p.appendChild(titulo);
        colOcultavel.forEach(function (i) {
          var k = chaveCol(ths[i], i);
          p.appendChild(caixa(rotulo(ths[i]), estado.ocultas.indexOf(k) === -1, function (sim) {
            estado.ocultas = estado.ocultas.filter(function (x) { return x !== k; });
            if (!sim) { estado.ocultas.push(k); }
            aplicar();
          }));
        });
      });
    }
    if (busca) {
      busca.value = estado.busca;
      busca.addEventListener('input', function () { estado.busca = busca.value; estado.pagina = 0; aplicar(); });
      busca.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); } });
    }
    if (porPagina) {
      porPagina.value = String(estado.porPagina);
      if (porPagina.value !== String(estado.porPagina)) { porPagina.value = String(padraoPorPagina); estado.porPagina = padraoPorPagina; }
      porPagina.dispatchEvent(new Event('change'));
      porPagina.addEventListener('change', function () {
        var pp = parseInt(porPagina.value, 10) || 0;
        if (pp !== estado.porPagina) { estado.porPagina = pp; estado.pagina = 0; }
        aplicar();
      });
    }
    if (bRestaurar) {
      bRestaurar.addEventListener('click', function () {
        estado.busca = ''; estado.filtros = {}; estado.ocultas = []; estado.pagina = 0;
        if (busca) { busca.value = ''; }
        fecharPop();
        aplicar();
      });
    }
    tabela.addEventListener('tabela:mudou', aplicar);
    if (pe) { pe.hidden = false; }
    aplicar();
  }

  // Menu "...": o painel fica fixo na tela, embaixo do botao (ou em cima, se faltar espaco)
  function posicionarMenu(d) {
    var p = d.querySelector('.menu-linha-painel'), s = d.querySelector('summary');
    if (!p || !s) { return; }
    p.style.position = 'fixed'; p.style.right = 'auto'; p.style.left = '0px'; p.style.top = '0px'; p.style.visibility = 'hidden';
    var r = s.getBoundingClientRect(), w = p.offsetWidth, h = p.offsetHeight;
    var top = r.bottom + 4;
    if (top + h > window.innerHeight - 8 && r.top - h - 4 >= 8) { top = r.top - h - 4; }
    p.style.left = Math.max(8, Math.min(r.right - w, window.innerWidth - w - 8)) + 'px';
    p.style.top = Math.max(8, top) + 'px';
    p.style.maxHeight = (window.innerHeight - 16) + 'px';
    p.style.overflow = 'auto';
    p.style.visibility = '';
  }
  function fecharMenus(menos) {
    Array.prototype.forEach.call(document.querySelectorAll('details.menu-linha[open]'), function (d) { if (d !== menos) { d.open = false; } });
  }
  document.addEventListener('click', function (e) {
    var dentro = e.target.closest && e.target.closest('details.menu-linha');
    fecharMenus(dentro);
  });
  document.addEventListener('toggle', function (e) {
    var d = e.target;
    if (d.matches && d.matches('details.menu-linha') && d.open) { fecharMenus(d); posicionarMenu(d); }
  }, true);
  document.addEventListener('scroll', function (e) {
    var t = e.target;
    if (t && t.nodeType === 1 && t.closest('.menu-linha-painel')) { return; }
    fecharMenus(null);
  }, true);
  window.addEventListener('resize', function () { fecharMenus(null); });
  document.addEventListener('keydown', function (e) {
    var aberto = document.querySelector('details.menu-linha[open]');
    if (e.key === 'Escape' && aberto) { aberto.open = false; aberto.querySelector('summary').focus(); }
  });

  window.Tabela = {
    ligar: function (raiz) { Array.prototype.forEach.call((raiz || document).querySelectorAll('[data-tabela]'), ligarCard); },
    lembrar: function (raiz) {
      Array.prototype.forEach.call((raiz || document).querySelectorAll('[data-tabela]'), function (card) {
        var e = card.__estado;
        if (e) { guardados[card.getAttribute('data-tabela')] = { pagina: e.pagina, busca: e.busca, filtros: JSON.parse(JSON.stringify(e.filtros)) }; }
      });
    },
    posicionarMenu: posicionarMenu
  };
})();
