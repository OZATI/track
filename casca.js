// Lateral, barra de topo e filtro dos painéis do Track: o comportamento do que lib/casca.php desenha (casca_lateral, casca_topo, casca_filtro).
// Escrito a partir de admin/js/cms-lateral.js do CMS (15/11/2026); o CMS passa a consumir esta cópia.
// Sem este arquivo a lateral e o filtro continuam funcionando: os grupos abrem e fecham (details), os links levam às telas e o filtro é um
// <details> com os campos nativos e o Aplicar; só a pesquisa (Ctrl K), o recolher, o voltar e os extras do filtro dependem dele.
// - Recolher a lateral: guardado neste navegador (track_cas_lat). A barra de topo ganha o botão de mostrar de volta. Ctrl B (⌘ B no Mac).
// - Pesquisa (Ctrl K ou Cmd K): uma janela (dialog) que filtra as seções do menu e vai para a escolhida. Setas, Enter e Esc.
// - Voltar: history.back(); sem histórico, vai para o início do painel.
// - Telas que trocam de seção sem recarregar avisam com o evento cas:secao ({detail:{id}}): a lateral abre o grupo e a trilha acompanha.
// - Filtro único: atalhos de período, Personalizado com validação, listas com busca e "Só este", contagem, Aplicar só quando mudou,
//   foco preso no painel, Esc e clique fora fecham, folha de baixo no celular (CSS).
// - O painel.js troca o corpo da página ao filtrar e ordenar (navegar): ele chama window.Casca.ligar() depois de cada troca, e este arquivo
//   solta os ouvintes do documento antes de ligar de novo (nada fica em dobro).
(function () {
  'use strict';
  var CHAVE = 'track_cas_lat';
  var ouvintes = [];
  var lat = null, pagina = null, topo = null;
  var dlg = null, campo = null, ul = null, aviso = null, opcoes = [], ativo = 0, quemAbriu = null;

  function ouvir(alvo, ev, fn, cap) { alvo.addEventListener(ev, fn, cap); ouvintes.push([alvo, ev, fn, cap]); }
  var observador = null; // acompanha a largura da barra de topo (etiquetas dos filtros)
  function soltar() {
    ouvintes.forEach(function (o) { o[0].removeEventListener(o[1], o[2], o[3]); });
    ouvintes = [];
    if (observador) { observador.disconnect(); observador = null; }
  }
  var gravada = function () { try { return localStorage.getItem(CHAVE) === '1'; } catch (e) { return false; } };
  var gravar = function (v) { try { localStorage.setItem(CHAVE, v ? '1' : '0'); } catch (e) { /* sem armazenamento: so nao lembra */ } };
  var todos = function (seletor, raiz) { return Array.prototype.slice.call((raiz || document).querySelectorAll(seletor)); };
  var norm = function (t) { return String(t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); };
  var mac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent || '');
  var larga = window.matchMedia ? window.matchMedia('(min-width: 1000px)') : { matches: true };
  function editavel(el) {
    if (!el || el === document.body) { return false; }
    var t = el.tagName;
    if (t === 'TEXTAREA' || t === 'SELECT') { return true; }
    if (t === 'INPUT') { return !/^(button|checkbox|radio|submit|reset|range|color|file|image)$/i.test(el.type); }
    return !!(el.isContentEditable || (el.closest && el.closest('[contenteditable=""], [contenteditable="true"], [role="textbox"], [role="combobox"], [role="searchbox"]')));
  }

  // =============================================================== lateral e barra de topo
  function recolher(v, foco) {
    pagina.classList.toggle('lat-recolhida', v);
    todos('[data-cas-recolher]').forEach(function (b) { b.setAttribute('aria-expanded', v ? 'false' : 'true'); });
    gravar(v);
    if (foco) {
      var alvo = document.querySelector(v ? '.cas-topo-menu' : '.cas-lat-recolher');
      if (alvo) { alvo.focus(); }
    }
  }
  function espelhar(d) {
    var r = d.querySelector(':scope > summary');
    if (r) { r.setAttribute('aria-expanded', d.open ? 'true' : 'false'); }
  }
  function atualizarSecao(id) {
    var a = lat.querySelector('[data-cas-secao="' + id + '"]');
    if (!a) { return; }
    todos('.cas-lat-grupo').forEach(function (g) { g.classList.toggle('tem-ativo', g.contains(a)); });
    var g = a.closest('details');
    if (g) { g.open = true; }
    if (!topo) { return; }
    var nome = topo.querySelector('[data-cas-topo-nome]');
    var grupo = topo.querySelector('[data-cas-topo-grupo]');
    var ico = topo.querySelector('[data-cas-topo-ico]');
    if (nome) { nome.textContent = a.getAttribute('data-titulo') || ''; }
    if (grupo) { grupo.textContent = a.getAttribute('data-grupo') || ''; }
    var pai = a.closest('details');
    var svg = a.querySelector('svg') || (pai && pai.querySelector('summary svg'));
    if (ico && svg) { ico.innerHTML = ''; ico.appendChild(svg.cloneNode(true)); }
  }

  // pesquisa (Ctrl K)
  function itens() {
    return Array.prototype.slice.call(lat.querySelectorAll('a[data-cas-secao], .cas-lat-pe a')).map(function (a) {
      var pai = a.closest('details'); // o item de submenu nao tem icone: usa o do grupo
      return { a: a, rot: a.getAttribute('data-titulo') || a.textContent.trim(), grupo: a.getAttribute('data-grupo') || 'Painel', svg: a.querySelector('svg') || (pai && pai.querySelector('summary svg')) };
    });
  }
  function montar() {
    dlg = document.createElement('dialog');
    dlg.className = 'cas-pal';
    dlg.setAttribute('aria-label', 'Ir para uma seção do painel');
    dlg.innerHTML = '<div class="cas-pal-campo"></div><ul class="cas-pal-lista" id="cas-pal-lista" role="listbox" aria-label="Seções"></ul>'
      + '<div class="cas-pal-pe"><span>↑ ↓ para escolher, Enter para ir</span><span>Esc fecha</span></div>'
      + '<p class="visually-hidden" role="status" aria-live="polite" data-cas-pal-aviso></p>';
    var cp = dlg.querySelector('.cas-pal-campo');
    cp.innerHTML = '<svg class="ico" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/></svg>'
      + '<input type="text" role="combobox" aria-expanded="true" aria-controls="cas-pal-lista" aria-autocomplete="list" autocomplete="off" spellcheck="false" aria-label="Ir para" placeholder="Ir para… (Resumo, Tráfego, Vendas…)">';
    campo = cp.querySelector('input');
    campo.placeholder = lat.getAttribute('data-cas-placeholder') || campo.placeholder; // cada lateral diz o que dá para procurar nela
    ul = dlg.querySelector('ul');
    aviso = dlg.querySelector('[data-cas-pal-aviso]');
    document.body.appendChild(dlg);
    campo.addEventListener('input', function () { desenhar(campo.value); });
    campo.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        if (opcoes.length) { mover((ativo + (e.key === 'ArrowDown' ? 1 : -1) + opcoes.length) % opcoes.length); }
      } else if (e.key === 'Home' || e.key === 'End') {
        if (opcoes.length && campo.value === '') { e.preventDefault(); mover(e.key === 'Home' ? 0 : opcoes.length - 1); }
      } else if (e.key === 'Enter') {
        e.preventDefault();
        ir(ativo);
      }
    });
    ul.addEventListener('click', function (e) {
      var li = e.target.closest('li[data-i]');
      if (li) { ir(+li.getAttribute('data-i')); }
    });
    dlg.addEventListener('click', function (e) { if (e.target === dlg) { dlg.close(); } });
    dlg.addEventListener('close', function () { if (quemAbriu && quemAbriu.focus) { quemAbriu.focus(); } });
  }
  function mover(i) {
    ativo = i;
    Array.prototype.forEach.call(ul.children, function (li, k) {
      var sim = k === i;
      li.setAttribute('aria-selected', sim ? 'true' : 'false');
      if (sim) { campo.setAttribute('aria-activedescendant', li.id); li.scrollIntoView({ block: 'nearest' }); }
    });
  }
  function desenhar(q) {
    var n = norm(q).trim();
    opcoes = itens().filter(function (o) { return !n || norm(o.rot + ' ' + o.grupo).indexOf(n) !== -1; });
    ul.innerHTML = '';
    if (!opcoes.length) {
      ul.innerHTML = '<li class="cas-pal-vazio" role="presentation">Nenhuma seção com “' + String(q).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }) + '”.</li>';
      campo.removeAttribute('aria-activedescendant');
      aviso.textContent = 'Nenhum resultado.';
      return;
    }
    opcoes.forEach(function (o, i) {
      var li = document.createElement('li');
      li.id = 'cas-pal-i' + i;
      li.className = 'cas-pal-op';
      li.setAttribute('role', 'option');
      li.setAttribute('data-i', i);
      if (o.svg) { li.appendChild(o.svg.cloneNode(true)); }
      var t = document.createElement('span'); t.textContent = o.rot; li.appendChild(t);
      var g = document.createElement('small'); g.textContent = o.grupo; li.appendChild(g);
      ul.appendChild(li);
    });
    mover(0);
    aviso.textContent = opcoes.length + (opcoes.length === 1 ? ' resultado.' : ' resultados.');
  }
  function ir(i) {
    var o = opcoes[i];
    if (!o) { return; }
    dlg.close();
    quemAbriu = null;
    o.a.click();
  }
  function abrir() {
    if (!dlg) { montar(); }
    if (dlg.open) { return; }
    quemAbriu = document.activeElement;
    campo.value = '';
    desenhar('');
    dlg.showModal();
    campo.focus();
  }

  function lateral() {
    todos('.cas-lat details').forEach(espelhar);
    ouvir(document, 'toggle', function (e) {
      if (e.target && e.target.matches && e.target.matches('.cas-lat details')) { espelhar(e.target); }
    }, true); // o toggle nao borbulha: captura
    todos('[data-cas-busca], [data-cas-recolher], [data-cas-voltar]').forEach(function (b) { b.hidden = false; }); // controles que so existem com JavaScript
    recolher(gravada(), false);
    ouvir(document, 'click', function (e) {
      var b = e.target.closest && e.target.closest('[data-cas-recolher]');
      if (b) { recolher(!pagina.classList.contains('lat-recolhida'), true); }
    });
    // Ctrl B (⌘ B no Mac): so com o foco FORA de campos editaveis (Ctrl B e negrito num campo de texto), sem janela aberta e com a lateral a vista
    if (mac) { todos('[data-cas-tecla-ctrl]').forEach(function (k) { k.textContent = '⌘'; }); }
    ouvir(document, 'keydown', function (e) {
      if (String(e.key).toLowerCase() !== 'b' || e.altKey || e.shiftKey || e.defaultPrevented || e.ctrlKey === e.metaKey) { return; }
      if (!larga.matches || editavel(document.activeElement) || document.querySelector('dialog[open]')) { return; }
      e.preventDefault();
      var foco = document.activeElement;
      recolher(!pagina.classList.contains('lat-recolhida'), !!(foco && (lat.contains(foco) || (foco.matches && foco.matches('[data-cas-recolher]')))));
    });
    // dica do botao (so CSS): Esc a esconde enquanto o mouse ou o foco continuam nele (WCAG 1.4.13)
    var dicas = todos('.cas-tip');
    ouvir(document, 'keydown', function (e) {
      if (e.key === 'Escape') { dicas.forEach(function (t) { t.classList.add('sem-dica'); }); }
    });
    dicas.forEach(function (t) {
      ['mouseleave', 'focusout'].forEach(function (ev) { t.addEventListener(ev, function () { t.classList.remove('sem-dica'); }); });
    });
    ouvir(document, 'click', function (e) {
      var b = e.target.closest && e.target.closest('[data-cas-voltar]');
      if (!b) { return; }
      var inicio = topo && topo.querySelector('a.cas-topo-bt');
      if (window.history.length > 1) { window.history.back(); } else if (inicio) { window.location.href = inicio.href; }
    });
    ouvir(document, 'cas:secao', function (e) { if (e.detail) { atualizarSecao(e.detail.id); } });
    if (typeof window.HTMLDialogElement !== 'function') {
      todos('[data-cas-busca]').forEach(function (b) { b.hidden = true; });
      return;
    }
    ouvir(document, 'keydown', function (e) {
      if ((e.ctrlKey || e.metaKey) && !e.altKey && !e.shiftKey && String(e.key).toLowerCase() === 'k') {
        e.preventDefault();
        abrir();
      }
    });
    ouvir(document, 'click', function (e) {
      if (e.target.closest && e.target.closest('[data-cas-busca]')) { abrir(); }
    });
  }

  // =============================================================== tema "Sistema"
  // Segue o tema do aparelho (prefers-color-scheme) NO MOMENTO de escolher e grava como Claro ou Escuro, pelo mesmo POST do tema.php (nada de campo
  // novo). Seguir o aparelho o tempo todo precisaria de uma preferência guardada: fica para uma etapa com servidor.
  function sistema(grade) {
    if (grade.querySelector('.tema-sistema')) { return; }
    var escuro = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    var alvo = grade.querySelector('button[name="pronto"][value="' + (escuro ? 'escuro' : 'claro') + '"]');
    if (!alvo) { return; }
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'tema-pronto tema-sistema';
    b.title = 'Usa o tema do seu aparelho agora (' + (escuro ? 'escuro' : 'claro') + ')';
    b.textContent = 'Sistema (agora: ' + (escuro ? 'escuro' : 'claro') + ')';
    b.addEventListener('click', function () { alvo.click(); });
    grade.appendChild(b);
  }
  function tema() {
    todos('.tema-prontos').forEach(sistema);
    todos('.cas-cfg-tema [data-tema-cor]').forEach(function (i) { // cor livre dentro de Configurações: grava ao escolher (a do trilho já faz isso no painel.js)
      i.addEventListener('change', function () { i.form.submit(); });
    });
  }

  // =============================================================== filtro único
  function focaveis(raiz) {
    return todos('a[href], button, input, select, textarea, summary', raiz).filter(function (e) {
      return !e.disabled && e.type !== 'hidden' && !e.hidden && e.getAttribute('tabindex') !== '-1' && !e.closest('[hidden]') && (e.offsetWidth || e.offsetHeight || e.getClientRects().length);
    });
  }
  function filtro(det) {
    var form = det.closest('form');
    var painel = det.querySelector('.cas-filtro-painel');
    var botao = det.querySelector('summary');
    var aplicar = det.querySelector('[data-cas-aplicar]');
    var erro = det.querySelector('[data-cas-erro]');
    var selP = det.querySelector('[data-cas-periodo]');
    var datas = det.querySelector('[data-cas-datas]');
    var de = datas && datas.querySelector('input[name="de"]');
    var ate = datas && datas.querySelector('input[name="ate"]');
    var presets = det.querySelector('[data-cas-presets]');
    if (!form || !painel || !botao) { return; }
    det.classList.add('cas-js');
    var fundo = document.createElement('div');
    fundo.className = 'cas-filtro-fundo';
    det.appendChild(fundo);
    fundo.addEventListener('click', function () { fechar(true); });

    function estado() { return new URLSearchParams(new FormData(form)).toString(); }
    var inicial = estado();
    function mudou() { if (aplicar) { aplicar.disabled = estado() === inicial; } }

    // ---- periodo: atalhos (chips) por cima do select, que continua sendo o campo que vale
    function pintarPresets() {
      if (!presets) { return; }
      todos('button', presets).forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-v') === selP.value ? 'true' : 'false'); });
    }
    function mostrarDatas() {
      if (!datas) { return; }
      var sim = selP.value === 'personalizado';
      datas.hidden = !sim;
      todos('input', datas).forEach(function (i) { i.disabled = !sim; });
      if (!sim && erro) { erro.hidden = true; erro.textContent = ''; }
    }
    if (selP && presets) {
      todos('option', selP).forEach(function (o) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'cas-filtro-chip';
        b.setAttribute('data-v', o.value);
        b.textContent = o.value === 'personalizado' ? 'Personalizado' : o.textContent;
        b.addEventListener('click', function () {
          selP.value = o.value;
          pintarPresets(); mostrarDatas(); mudou();
          if (o.value === 'personalizado' && de) { de.focus(); }
        });
        presets.appendChild(b);
      });
      presets.hidden = false;
      selP.setAttribute('tabindex', '-1');
      selP.setAttribute('aria-hidden', 'true');
      selP.classList.add('cas-filtro-sel-oculto');
      pintarPresets();
    }
    if (selP) { selP.addEventListener('change', function () { pintarPresets(); mostrarDatas(); }); }
    function validar() {
      if (!selP || selP.value !== 'personalizado' || !de || !ate) { return ''; }
      if (!de.value || !ate.value) { return 'Escolha as duas datas: a inicial e a final.'; }
      if (de.value > ate.value) { return 'A data final precisa ser igual ou depois da inicial.'; }
      if (ate.max && ate.value > ate.max) { return 'A data final não pode ser no futuro.'; }
      return '';
    }
    function mostrarErro(msg) {
      if (!erro) { return; }
      erro.textContent = msg;
      erro.hidden = !msg;
      [de, ate].forEach(function (i) { if (i) { if (msg) { i.setAttribute('aria-invalid', 'true'); i.setAttribute('aria-describedby', erro.id || (erro.id = 'cas-filtro-erro')); } else { i.removeAttribute('aria-invalid'); } } });
    }

    // ---- listas (Produto, Fonte): Todos, Só este, busca quando passa de 8 itens e a contagem
    todos('[data-cas-lista]', det).forEach(function (fs) {
      var todosBox = fs.querySelector('[data-multi-todos]');
      var caixas = todos('input[type="checkbox"]', fs).filter(function (c) { return c !== todosBox; });
      var cont = fs.querySelector('[data-cas-cont]');
      var um = fs.getAttribute('data-um') || 'item', varios = fs.getAttribute('data-varios') || 'itens';
      function contar() {
        var m = caixas.filter(function (c) { return c.checked; }).length;
        if (todosBox) { todosBox.checked = !m; }
        if (cont) { cont.textContent = m ? '· ' + m + ' de ' + caixas.length + (m === 1 ? ' ' + um : ' ' + varios) : ''; }
      }
      contar();
      fs.addEventListener('change', function (e) {
        if (e.target === todosBox) { if (todosBox.checked) { caixas.forEach(function (c) { c.checked = false; }); } else if (!caixas.some(function (c) { return c.checked; })) { todosBox.checked = true; } }
        contar();
      });
      caixas.forEach(function (c) {
        var rot = c.closest('label');
        if (!rot) { return; }
        var so = document.createElement('button');
        so.type = 'button';
        so.className = 'cas-filtro-so';
        so.textContent = 'Só este';
        so.setAttribute('aria-label', 'Só ' + rot.textContent.trim());
        so.addEventListener('click', function (e) {
          e.preventDefault();
          caixas.forEach(function (x) { x.checked = x === c; });
          contar(); mudou();
        });
        rot.appendChild(so);
      });
      if (caixas.length > 8) {
        var busca = document.createElement('input');
        busca.type = 'search';
        busca.className = 'cas-filtro-busca';
        busca.placeholder = 'Buscar';
        busca.setAttribute('aria-label', 'Buscar em ' + (fs.querySelector('legend') ? fs.querySelector('legend').firstChild.textContent.trim() : 'lista'));
        busca.addEventListener('input', function () {
          var q = norm(busca.value).trim();
          caixas.forEach(function (c) { var r = c.closest('label'); if (r) { r.hidden = !!q && norm(r.textContent).indexOf(q) === -1; } });
        });
        busca.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); } }); // Enter na busca nao envia o filtro
        var primeiro = fs.querySelector('.multi-todos');
        fs.insertBefore(busca, primeiro);
      }
    });
    // Site mudou: as paginas dele so chegam depois de Aplicar
    todos('select[data-reinicia]', det).forEach(function (s) {
      s.addEventListener('change', function () {
        var alvo = form.elements[s.getAttribute('data-reinicia')];
        if (!alvo || alvo.tagName !== 'SELECT') { return; }
        alvo.innerHTML = '';
        var o = document.createElement('option');
        o.value = '';
        o.textContent = 'Aplique para escolher a página';
        alvo.appendChild(o);
        alvo.disabled = false;
      });
    });

    // ---- o que acontece dentro do painel nao chega ao form (o painel.js envia o filtro a cada change: aqui so o Aplicar envia)
    ['change', 'input'].forEach(function (ev) {
      painel.addEventListener(ev, function (e) { e.stopPropagation(); mostrarErro(''); mudou(); });
    });
    mudou();

    // ---- abrir e fechar, foco preso
    function fechar(volta) {
      if (!det.open) { return; }
      det.open = false;
      if (volta) { botao.focus(); }
    }
    det.addEventListener('toggle', function () {
      botao.setAttribute('aria-expanded', det.open ? 'true' : 'false');
      if (det.open) {
        var f = focaveis(painel)[0];
        if (f) { f.focus(); }
      }
    });
    botao.setAttribute('aria-expanded', det.open ? 'true' : 'false');
    painel.addEventListener('keydown', function (e) {
      if (e.key !== 'Tab') { return; }
      var f = focaveis(painel);
      if (!f.length) { return; }
      var primeiro = f[0], ultimo = f[f.length - 1];
      if (e.shiftKey && (document.activeElement === primeiro || !painel.contains(document.activeElement))) { e.preventDefault(); ultimo.focus(); }
      else if (!e.shiftKey && document.activeElement === ultimo) { e.preventDefault(); primeiro.focus(); }
    });
    ouvir(document, 'keydown', function (e) {
      if (e.key === 'Escape' && det.open) { e.preventDefault(); fechar(true); }
    });
    ouvir(document, 'click', function (e) {
      if (det.open && !det.contains(e.target)) { fechar(false); }
    });
    form.addEventListener('submit', function (e) {
      var msg = validar();
      if (msg) {
        e.preventDefault();
        e.stopImmediatePropagation();
        mostrarErro(msg);
        var alvo = (!de || !de.value) ? de : ate;
        if (alvo) { alvo.focus(); }
      }
    });
  }

  // Etiquetas dos filtros ativos na barra de uma linha: se nao couberem na largura, somem todas (classe cas-chips-fora) e fica o contador do botao.
  // Mede de novo quando a barra muda de largura (janela, lateral recolhida) e quando as fontes terminam de carregar.
  function etiquetas() {
    var g = document.querySelector('[data-cas-chips]');
    if (!g || !topo) { return; }
    function medir() {
      g.classList.remove('cas-chips-fora');
      if (g.scrollWidth > g.clientWidth + 1) { g.classList.add('cas-chips-fora'); }
    }
    medir();
    if (window.ResizeObserver) {
      observador = new ResizeObserver(medir);
      observador.observe(topo);
    } else {
      ouvir(window, 'resize', medir);
    }
    if (document.fonts && document.fonts.ready) { document.fonts.ready.then(medir); }
  }

  function ligar() {
    soltar();
    dlg = campo = ul = aviso = null;
    opcoes = []; ativo = 0; quemAbriu = null;
    lat = document.querySelector('[data-cas-lat]');
    pagina = lat ? lat.parentElement : null;
    topo = document.querySelector('[data-cas-topo]');
    if (lat) { lateral(); }
    tema();
    todos('[data-cas-filtro]').forEach(filtro);
    etiquetas();
  }
  window.Casca = { ligar: ligar };
  ligar();
})();
