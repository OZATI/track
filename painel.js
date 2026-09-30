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

  // App: o service worker deixa instalar o painel e mostra as notificacoes (sw.php)
  var sw = null;
  if ('serviceWorker' in navigator) {
    sw = navigator.serviceWorker.register('sw.php', { scope: './' }).catch(function () { return null; });
  }
  var instalavel = null;
  var instalado = window.matchMedia && window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
  var app = document.querySelector('[data-app]');
  function mostrarApp() {
    if (!app) { return; }
    var estado = app.querySelector('[data-app-estado]');
    var botao = app.querySelector('[data-instalar]');
    var ios = /iPhone|iPad|iPod/.test(navigator.userAgent);
    botao.hidden = !instalavel || instalado;
    app.querySelector('[data-ios]').hidden = !ios || instalado;
    app.querySelector('[data-outro]').hidden = ios || instalado || !!instalavel;
    estado.textContent = instalado ? 'O painel já está instalado como app neste aparelho.'
      : (instalavel ? 'Pode instalar o painel como app neste aparelho.' : 'Veja abaixo como instalar neste aparelho.');
  }
  window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); instalavel = e; mostrarApp(); });
  window.addEventListener('appinstalled', function () { instalado = true; instalavel = null; mostrarApp(); });
  if (app) {
    app.querySelector('[data-instalar]').addEventListener('click', function () {
      if (!instalavel) { return; }
      instalavel.prompt();
      instalavel.userChoice.then(function () { instalavel = null; mostrarApp(); });
    });
    mostrarApp();
  }

  // Notificacoes neste aparelho (Configuracoes): ligar, testar e desligar
  var push = document.querySelector('[data-push]');
  function chamar(acao, corpo) {
    return fetch('notificacoes.php?acao=' + acao, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'X-CSRF': token ? token.value : '', 'Content-Type': 'application/json' },
      body: JSON.stringify(corpo || {})
    }).then(function (r) { return r.json(); });
  }
  function chaveBytes(b64) {
    var s = (b64 + '===='.slice(b64.length % 4 || 4)).replace(/-/g, '+').replace(/_/g, '/');
    var bin = atob(s), out = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) { out[i] = bin.charCodeAt(i); }
    return out;
  }
  function nomeAparelho() {
    var ua = navigator.userAgent;
    var so = /iPhone|iPad/.test(ua) ? 'iPhone' : (/Android/.test(ua) ? 'Android' : (/Mac/.test(ua) ? 'Mac' : (/Windows/.test(ua) ? 'Windows' : 'Computador')));
    var nav = /Edg\//.test(ua) ? 'Edge' : (/Chrome\//.test(ua) ? 'Chrome' : (/Firefox\//.test(ua) ? 'Firefox' : (/Safari\//.test(ua) ? 'Safari' : 'navegador')));
    return so + ' · ' + nav + (instalado ? ' (app)' : '');
  }
  if (push && sw) {
    var estadoP = push.querySelector('[data-push-estado]');
    var ligar = push.querySelector('[data-push-ligar]');
    var testar = push.querySelector('[data-push-testar]');
    var desligar = push.querySelector('[data-push-desligar]');
    var avisar = function (t) { estadoP.textContent = t; };
    var atualizar = function () {
      sw.then(function (reg) {
        if (!reg || !('PushManager' in window) || !('Notification' in window)) {
          avisar(/iPhone|iPad/.test(navigator.userAgent) && !instalado
            ? 'No iPhone, as notificações funcionam com o painel instalado na Tela de Início (veja em Aplicativo).'
            : 'Este navegador não recebe notificações. Use o Chrome, o Edge, o Firefox ou o Safari atualizado.');
          return;
        }
        reg.pushManager.getSubscription().then(function (sub) {
          var negado = Notification.permission === 'denied';
          ligar.hidden = !!sub || negado;
          testar.hidden = desligar.hidden = !sub;
          avisar(sub ? 'Ligadas neste aparelho.' : (negado ? 'As notificações estão bloqueadas neste navegador. Libere nas configurações do site (cadeado na barra de endereço) e recarregue.' : 'Desligadas neste aparelho.'));
        });
      });
    };
    ligar.addEventListener('click', function () {
      avisar('Ligando…');
      Notification.requestPermission().then(function (perm) {
        if (perm !== 'granted') { atualizar(); return null; }
        // ready: o service worker ja ativo (na primeira visita ele ainda esta instalando)
        return Promise.all([navigator.serviceWorker.ready, chamar('chave')]).then(function (r) {
          if (!r[1].ok) { throw new Error(r[1].erro || 'Não foi possível ligar.'); }
          return r[0].pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: chaveBytes(r[1].chave) });
        }).then(function (sub) {
          var j = sub.toJSON();
          j.aparelho = nomeAparelho();
          return chamar('inscrever', j);
        }).then(function (r) {
          if (r && !r.ok) { throw new Error(r.erro); }
          atualizar();
        });
      }).catch(function (e) { avisar(e && e.message ? e.message : 'Não foi possível ligar as notificações.'); });
    });
    testar.addEventListener('click', function () {
      avisar('Enviando…');
      chamar('testar').then(function (r) { avisar(r.ok ? 'Enviado. A notificação deve chegar em instantes.' : (r.erro || 'Falhou.')); });
    });
    desligar.addEventListener('click', function () {
      sw.then(function (reg) { return reg.pushManager.getSubscription(); }).then(function (sub) {
        if (!sub) { return null; }
        return chamar('cancelar', { endpoint: sub.endpoint }).then(function () { return sub.unsubscribe(); });
      }).then(atualizar);
    });
    atualizar();
  }

  // Atualizar (so o icone): gira enquanto a busca roda
  var atualizar = document.querySelectorAll('.form-atualizar');
  for (var fa = 0; fa < atualizar.length; fa++) {
    atualizar[fa].addEventListener('submit', function (e) {
      var b = e.target.querySelector('.botao-icone');
      if (b) { b.classList.add('girando'); b.setAttribute('aria-busy', 'true'); }
    });
  }

  // Formularios marcados com data-auto (filtros do Resumo) enviam ao mudar
  var autos = document.querySelectorAll('form[data-auto]');
  for (var a = 0; a < autos.length; a++) {
    autos[a].addEventListener('change', function () { this.submit(); });
  }

  // (i) dentro de link ou aba: o clique mostra a explicacao em vez de navegar
  document.addEventListener('click', function (e) {
    var i = e.target.closest && e.target.closest('.info');
    if (i && i.closest('a')) { e.preventDefault(); e.stopPropagation(); mostrar(i); }
  }, true);

  // Gestor: ligar ou pausar na Meta sempre pergunta antes
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (f && f.classList && f.classList.contains('chave-form') && !window.confirm(f.getAttribute('data-confirma') || 'Confirmar?')) {
      e.preventDefault();
    }
  });
  // Gestor: marcar todos e contar os marcados
  var todos = document.querySelector('[data-sel-todos]');
  var conta = document.querySelector('[data-sel-conta]');
  var caixas = document.querySelectorAll('input[data-sel]');
  function contar() {
    var n = 0;
    for (var i = 0; i < caixas.length; i++) { if (caixas[i].checked) { n++; } }
    if (conta) { conta.textContent = String(n); }
    if (todos) { todos.checked = n > 0 && n === caixas.length; todos.indeterminate = n > 0 && n < caixas.length; }
  }
  if (caixas.length) {
    for (var i = 0; i < caixas.length; i++) { caixas[i].addEventListener('change', contar); }
    if (todos) {
      todos.addEventListener('change', function () {
        for (var j = 0; j < caixas.length; j++) { caixas[j].checked = todos.checked; }
        contar();
      });
    }
    contar();
  }

  // Gestor: seletor de colunas. A lista da direita (na ordem dela) vai no formulario; a da
  // esquerda so marca e desmarca. Arrastar, subir, descer e tirar mudam a ordem.
  var painelCols = document.querySelector('[data-colunas]');
  if (painelCols) {
    var lista = painelCols.querySelector('[data-colunas-ordem]');
    var marcas = painelCols.querySelectorAll('[data-colunas-todas] input[type=checkbox]');
    var inicial = lista.innerHTML;
    var estado = [];
    for (var m = 0; m < marcas.length; m++) { estado.push(marcas[m].checked); marcas[m].removeAttribute('name'); }
    var modelo = lista.querySelector('li');
    function rotulo(k) {
      var b = painelCols.querySelector('[data-colunas-todas] label[data-coluna="' + k + '"] b');
      return b ? b.textContent : k;
    }
    function novoItem(k) {
      var li;
      if (modelo) { li = modelo.cloneNode(true); } else {
        li = document.createElement('li');
        li.innerHTML = '<span class="alca" aria-hidden="true">\u2630</span><span></span><button type="button" class="discreto" data-sobe aria-label="Subir">\u2191</button><button type="button" class="discreto" data-desce aria-label="Descer">\u2193</button><button type="button" class="discreto" data-tira aria-label="Tirar">\u00d7</button>';
      }
      li.setAttribute('draggable', 'true');
      li.setAttribute('data-coluna', k);
      li.children[1].textContent = rotulo(k);
      return li;
    }
    function marcar(k, sim) {
      var c = painelCols.querySelector('[data-colunas-todas] input[value="' + k + '"]');
      if (c) { c.checked = sim; }
    }
    for (var n = 0; n < marcas.length; n++) {
      marcas[n].addEventListener('change', function () {
        var k = this.value;
        var li = lista.querySelector('li[data-coluna="' + k + '"]');
        if (this.checked && !li) { lista.appendChild(novoItem(k)); }
        if (!this.checked && li) { li.parentNode.removeChild(li); }
      });
    }
    lista.addEventListener('click', function (e) {
      var bt = e.target.closest && e.target.closest('button');
      if (!bt) { return; }
      var li = bt.closest('li');
      if (bt.hasAttribute('data-sobe') && li.previousElementSibling) { lista.insertBefore(li, li.previousElementSibling); }
      if (bt.hasAttribute('data-desce') && li.nextElementSibling) { lista.insertBefore(li.nextElementSibling, li); }
      if (bt.hasAttribute('data-tira')) { marcar(li.getAttribute('data-coluna'), false); li.parentNode.removeChild(li); }
    });
    var arrastado = null;
    lista.addEventListener('dragstart', function (e) {
      arrastado = e.target.closest('li');
      if (arrastado) { arrastado.classList.add('arrastando'); e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setData('text/plain', ''); } catch (x) {} }
    });
    lista.addEventListener('dragover', function (e) {
      var alvo = e.target.closest && e.target.closest('li');
      if (!arrastado || !alvo || alvo === arrastado) { return; }
      e.preventDefault();
      var r = alvo.getBoundingClientRect();
      lista.insertBefore(arrastado, e.clientY > r.top + r.height / 2 ? alvo.nextSibling : alvo);
    });
    lista.addEventListener('dragend', function () { if (arrastado) { arrastado.classList.remove('arrastando'); } arrastado = null; });
    var busca = painelCols.querySelector('[data-colunas-busca]');
    var sem = function (t) { return t.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, ''); };
    if (busca) {
      busca.addEventListener('input', function () {
        var q = sem(busca.value);
        var ls = painelCols.querySelectorAll('[data-colunas-todas] label');
        for (var i = 0; i < ls.length; i++) { ls[i].style.display = sem(ls[i].textContent).indexOf(q) === -1 ? 'none' : ''; }
      });
      busca.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); } });
    }
    painelCols.querySelector('[data-colunas-cancela]').addEventListener('click', function () {
      lista.innerHTML = inicial;
      for (var i = 0; i < marcas.length; i++) { marcas[i].checked = estado[i]; }
      painelCols.closest('details').open = false;
    });
    painelCols.addEventListener('submit', function () {
      var velhos = painelCols.querySelectorAll('input[data-col-ordem]');
      for (var i = 0; i < velhos.length; i++) { velhos[i].parentNode.removeChild(velhos[i]); }
      var itens = lista.querySelectorAll('li');
      for (var j = 0; j < itens.length; j++) {
        var h = document.createElement('input');
        h.type = 'hidden'; h.name = 'cols[]'; h.value = itens[j].getAttribute('data-coluna'); h.setAttribute('data-col-ordem', '');
        painelCols.appendChild(h);
      }
    });
  }

  // Gestor: largura das colunas. Na borda do titulo aparece a linha; arrastar muda a largura
  // (guardada neste navegador, por nivel); dois cliques na linha voltam ao tamanho normal.
  var tabelaL = document.querySelector('table[data-larguras]');
  if (tabelaL) {
    var chaveL = 'gestor-larguras-' + tabelaL.getAttribute('data-larguras');
    var guardadas = {};
    try { guardadas = JSON.parse(localStorage.getItem(chaveL) || '{}') || {}; } catch (x) { guardadas = {}; }
    var ths = tabelaL.querySelectorAll('th[data-col]');
    function aplicar(th, px) {
      th.style.width = px ? px + 'px' : '';
      th.style.minWidth = px ? px + 'px' : '';
      th.style.maxWidth = px ? px + 'px' : '';
      th.classList.toggle('largura-fixa', !!px);
    }
    function guardar() { try { localStorage.setItem(chaveL, JSON.stringify(guardadas)); } catch (x) {} }
    for (var t = 0; t < ths.length; t++) {
      (function (th) {
        var col = th.getAttribute('data-col');
        if (guardadas[col]) { aplicar(th, guardadas[col]); }
        var alca = document.createElement('span');
        alca.className = 'redim';
        alca.setAttribute('aria-hidden', 'true');
        alca.title = 'Arraste para mudar a largura (dois cliques: tamanho normal)';
        th.appendChild(alca);
        alca.addEventListener('pointerdown', function (e) {
          e.preventDefault();
          var x0 = e.clientX, w0 = th.getBoundingClientRect().width;
          alca.classList.add('ativo');
          alca.setPointerCapture(e.pointerId);
          function mover(ev) { aplicar(th, Math.max(28, Math.round(w0 + ev.clientX - x0))); }
          function soltar() {
            alca.classList.remove('ativo');
            alca.removeEventListener('pointermove', mover);
            alca.removeEventListener('pointerup', soltar);
            guardadas[col] = Math.round(th.getBoundingClientRect().width);
            guardar();
          }
          alca.addEventListener('pointermove', mover);
          alca.addEventListener('pointerup', soltar);
        });
        alca.addEventListener('dblclick', function () { aplicar(th, 0); delete guardadas[col]; guardar(); });
        alca.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); });
      })(ths[t]);
    }
  }
})();
