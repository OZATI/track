// Painel: filtros, seletores, dicas e o resto da tela. Tudo o que liga eventos na tela fica em
// iniciar(), que roda de novo quando a tela e trocada sem recarregar (navegacao suave, no fim
// do arquivo): filtros, abas, ordenar e os formularios buscam a tela nova e trocam o corpo da
// pagina, sem voltar para o topo. Os ouvintes de document e window passam por ouvir(), para
// serem soltos antes da troca.
// Filtros: escolher ja aplica (sem botao Aplicar). Mudar o dominio zera a pagina. Listas de
// varios (Produto, Fonte de trafego) e o periodo aplicam ao fechar, clicando fora.
(function () {
  'use strict';
  var ouvintes = [];
  function ouvir(alvo, tipo, fn, op) { alvo.addEventListener(tipo, fn, op); ouvintes.push([alvo, tipo, fn, op]); }
  function soltarOuvintes() {
    for (var i = 0; i < ouvintes.length; i++) { ouvintes[i][0].removeEventListener(ouvintes[i][1], ouvintes[i][2], ouvintes[i][3]); }
    ouvintes = [];
  }
  // Envia um formulario pelo mesmo caminho do clique (a navegacao suave pega o "submit")
  function enviar(f) {
    var ev;
    try { ev = new Event('submit', { bubbles: true, cancelable: true }); } catch (x) { f.submit(); return; }
    if (f.dispatchEvent(ev)) { f.submit(); }
  }
  // App: o service worker deixa instalar o painel e mostra as notificacoes (sw.php). Uma vez so.
  var sw = 'serviceWorker' in navigator ? navigator.serviceWorker.register('sw.php', { scope: './' }).catch(function () { return null; }) : null;

  function iniciar() {
  var form = document.getElementById('filtros');
  if (form) {
    var datas = form.querySelector('[data-datas]');
    var mostrarDatas = function (sim) {
      if (!datas) { return; }
      datas.hidden = !sim;
      var ds = datas.querySelectorAll('input');
      for (var i = 0; i < ds.length; i++) { ds[i].disabled = !sim; }
    };
    form.addEventListener('change', function (e) {
      var alvo = e.target;
      if (alvo.closest && alvo.closest('[data-multi], .sel-multi')) { return; }
      if (alvo.hasAttribute('data-periodo')) {
        if (alvo.value === 'personalizado') {
          mostrarDatas(true);
          var primeira = datas && datas.querySelector('input');
          if (primeira) { primeira.focus(); }
          return;
        }
        mostrarDatas(false);
      }
      // Escolheu o "De": abre o "Até"; o filtro roda quando o "Até" e escolhido
      if (alvo.type === 'date' && alvo.name === 'de' && form.elements.ate) {
        form.elements.ate.min = alvo.value;
        form.elements.ate.focus();
        try { if (form.elements.ate.showPicker) { form.elements.ate.showPicker(); } } catch (x) {}
        return;
      }
      if (alvo.type === 'date' && datas && Array.prototype.some.call(datas.querySelectorAll('input'), function (d) { return !d.value; })) { return; }
      if (alvo.getAttribute('data-reinicia') === 'pagina' && form.elements.pagina) {
        form.elements.pagina.value = '';
      }
      enviar(form);
    });
  }
  // Vendas pela API da Kiwify em segundo plano (so quando a ultima busca tem mais de
  // 10 minutos). Chegou venda nova ou mudou alguma: recarrega a tela com os numeros.
  var sync = document.querySelector('[data-sync]');
  var token = document.getElementById('csrf-painel') || document.querySelector('#form-sair input[name=csrf]');
  if (sync && token && window.fetch) {
    var texto = sync.querySelector('[data-sync-texto]');
    if (texto) { texto.textContent = 'Buscando vendas na API da Kiwify…'; }
    fetch('sincronizar.php', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF': token.value } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d && d.buscou && (d.novas || d.atualizadas || !d.ok)) { navegar(location.href, { manter: true, substituir: true }); return; }
        if (texto) { texto.textContent = 'Vendas da Kiwify pelo webhook e pela API · nada novo agora'; }
      })
      .catch(function () {
        if (texto) { texto.textContent = 'Não foi possível buscar as vendas agora. Use o botão Atualizar vendas.'; }
      });
  }

  // Dicas do (i) e dos graficos: uma caixa so, fixa na tela (nao e cortada por tabela com
  // rolagem). Mouse: aparece ao passar e some ao sair. Clique ou toque: fixa ate clicar fora
  // ou apertar Esc (no celular nao existe "passar o mouse"). Teclado: aparece no foco.
  // data-dica-titulo vira a primeira linha, em negrito; o texto respeita as quebras de linha.
  var caixa = null, alvoDica = null, fixa = false;
  function posicionar() {
    if (!caixa || !alvoDica) { return; }
    var r = alvoDica.getBoundingClientRect();
    var larg = caixa.offsetWidth, alt = caixa.offsetHeight;
    var x = Math.min(Math.max(8, r.left + r.width / 2 - larg / 2), window.innerWidth - larg - 8);
    var y = r.bottom + 8 + alt > window.innerHeight ? r.top - alt - 8 : r.bottom + 8;
    caixa.style.left = x + 'px';
    caixa.style.top = Math.max(8, y) + 'px';
  }
  function mostrar(alvo) {
    var texto = alvo.getAttribute('data-dica');
    if (!texto) { return; }
    if (!caixa) { caixa = document.createElement('div'); caixa.className = 'dica'; caixa.id = 'dica-painel'; caixa.setAttribute('role', 'tooltip'); document.body.appendChild(caixa); }
    caixa.textContent = '';
    var titulo = alvo.getAttribute('data-dica-titulo');
    if (titulo) { var b = document.createElement('b'); b.textContent = titulo; caixa.appendChild(b); }
    var corpo = document.createElement('span'); corpo.textContent = texto; caixa.appendChild(corpo);
    if (alvoDica && alvoDica !== alvo) { alvoDica.removeAttribute('aria-describedby'); }
    alvoDica = alvo;
    alvo.setAttribute('aria-describedby', 'dica-painel');
    caixa.style.display = 'block';
    posicionar();
  }
  function esconder() {
    if (caixa) { caixa.style.display = 'none'; }
    if (alvoDica) { alvoDica.removeAttribute('aria-describedby'); }
    alvoDica = null;
    fixa = false;
  }
  var comDica = function (el) { return el && el.closest ? el.closest('[data-dica]') : null; };
  ouvir(document, 'pointerover', function (e) { var a = comDica(e.target); if (e.pointerType === 'mouse' && a && !fixa) { mostrar(a); } });
  ouvir(document, 'pointerout', function (e) { var a = comDica(e.target); if (e.pointerType === 'mouse' && a && !fixa && !a.contains(e.relatedTarget)) { esconder(); } });
  ouvir(document, 'focusin', function (e) { var a = comDica(e.target); if (a && !fixa) { mostrar(a); } });
  ouvir(document, 'focusout', function () { if (!fixa) { esconder(); } });
  // Clique ou toque no (i) ou num grafico fixa a dica; dentro de link, aba ou rotulo, nao navega.
  // Botao com dica (data-dica-botao, ex.: a analise diaria so com o icone): o clique e do botao.
  ouvir(document, 'click', function (e) {
    var a = comDica(e.target);
    if (a && a.hasAttribute('data-dica-botao')) { esconder(); return; }
    if (a) {
      if (a.closest('a, button, summary, label')) { e.preventDefault(); e.stopPropagation(); }
      if (fixa && alvoDica === a) { esconder(); } else { mostrar(a); fixa = true; }
      return;
    }
    if (fixa) { esconder(); }
  }, true);
  ouvir(document, 'keydown', function (e) { if (e.key === 'Escape' && alvoDica) { esconder(); } });
  ouvir(window, 'scroll', function () { if (fixa) { posicionar(); } else { esconder(); } }, true);
  ouvir(window, 'resize', function () { if (fixa) { posicionar(); } });

  // Instalar como app (o service worker e registrado uma vez, la em cima)
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
  ouvir(window, 'beforeinstallprompt', function (e) { e.preventDefault(); instalavel = e; mostrarApp(); });
  ouvir(window, 'appinstalled', function () { instalado = true; instalavel = null; mostrarApp(); });
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

  // Celular: listas viram cartoes; cada celula leva o titulo da coluna (data-rotulo)
  var listas = document.querySelectorAll('.tabela.lista table');
  for (var li = 0; li < listas.length; li++) {
    var linhasL = listas[li].rows;
    if (!linhasL.length) { continue; }
    var rotulos = [], secundarias = [];
    for (var hc = 0; hc < linhasL[0].cells.length; hc++) {
      secundarias.push(linhasL[0].cells[hc].hasAttribute('data-secundaria'));
      var th = linhasL[0].cells[hc].cloneNode(true);
      var infos = th.querySelectorAll('.info');
      for (var ii = 0; ii < infos.length; ii++) { infos[ii].parentNode.removeChild(infos[ii]); }
      rotulos.push(th.textContent.replace(/\s+/g, ' ').trim());
    }
    for (var r = 1; r < linhasL.length; r++) {
      var col = 0;
      for (var c = 0; c < linhasL[r].cells.length; c++) {
        var cel = linhasL[r].cells[c];
        if (cel.colSpan === 1 && rotulos[col]) { cel.setAttribute('data-rotulo', rotulos[col]); }
        if (cel.colSpan === 1 && secundarias[col]) { cel.classList.add('secundaria'); }
        col += cel.colSpan;
      }
    }
  }

  // Menu "Mais" da barra de baixo: fecha ao tocar fora ou ao escolher
  var navMais = document.querySelector('.nav-mais');
  if (navMais) {
    ouvir(document, 'click', function (e) { if (navMais.open && !navMais.contains(e.target)) { navMais.open = false; } });
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
    autos[a].addEventListener('change', function (e) { if (!e.target.closest('.sel-multi')) { enviar(this); } });
  }


  // Aparencia: a cor livre mostra a previa enquanto arrasta e grava ao escolher. Mesmo limite
  // de luminosidade do lib/tema.php: base escura, texto claro.
  function luminosidade(hex) {
    var c = [1, 3, 5].map(function (i) {
      var v = parseInt(hex.substr(i, 2), 16) / 255;
      return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
    });
    return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
  }
  var cores = document.querySelectorAll('[data-tema-cor]');
  for (var tc = 0; tc < cores.length; tc++) {
    cores[tc].addEventListener('input', function () {
      var raiz = document.documentElement;
      raiz.style.setProperty('--base', this.value);
      raiz.setAttribute('data-base', '');
      raiz.setAttribute('data-tema', luminosidade(this.value) < 0.4 ? 'escuro' : 'claro');
      var hex = this.closest('form').querySelector('[data-tema-hex]');
      if (hex) { hex.textContent = this.value.toUpperCase(); }
    });
    cores[tc].addEventListener('change', function () { enviar(this.form); });
  }
  var menuTema = document.querySelector('.tema-menu');
  if (menuTema) {
    ouvir(document, 'click', function (e) { if (menuTema.open && !menuTema.contains(e.target)) { menuTema.open = false; } });
  }

  // Pergunta antes: ligar ou pausar na Meta (gestor), apagar despesa (financeiro) e mudar o
  // orcamento (com a variacao; mais de 20% de uma vez pode reiniciar o aprendizado da Meta)
  function reais(c) { return 'R$ ' + (c / 100).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }
  function centavos(t) {
    t = String(t).replace(/[^\d,.]/g, '');
    if (t.indexOf(',') !== -1) { t = t.replace(/\./g, '').replace(',', '.'); } else if (/\.\d{3}$/.test(t)) { t = t.replace(/\./g, ''); }
    var v = Math.round(parseFloat(t) * 100);
    return isNaN(v) ? null : v;
  }
  ouvir(document, 'submit', function (e) {
    var f = e.target;
    if (f && f.hasAttribute && f.hasAttribute('data-orcamento')) {
      var ob = f.elements.objeto;
      var op = ob.tagName === 'SELECT' ? ob.options[ob.selectedIndex] : ob;
      var atual = parseInt(op.getAttribute('data-atual'), 10), novo = centavos(f.elements.valor.value);
      if (novo === null) { return; }
      var pct = atual ? Math.round((novo - atual) * 100 / atual) : 0;
      var msg = 'Mudar o orçamento de ' + reais(atual) + ' para ' + reais(novo) + ' por dia (' + (pct > 0 ? '+' : '') + pct + '%) na Meta?';
      if (Math.abs(pct) > 20) { msg += '\n\nMais de 20% de uma vez: a Meta pode reiniciar o aprendizado da campanha.'; }
      if (!window.confirm(msg)) { e.preventDefault(); }
      return;
    }
    if (f && f.hasAttribute && f.hasAttribute('data-confirma') && !window.confirm(f.getAttribute('data-confirma') || 'Confirmar?')) {
      e.preventDefault();
    }
  });
  // Orcamento no cabecalho da analise diaria: o lapis abre o campo na linha, ja selecionado;
  // o X, Esc ou clicar fora fecha; com varios conjuntos, escolher o conjunto traz o valor dele
  Array.prototype.forEach.call(document.querySelectorAll('details.orc-inline'), function (d) {
    var f = d.querySelector('form'), campo = f.elements.valor, ob = f.elements.objeto;
    var inicial = campo.value;
    d.addEventListener('toggle', function () {
      if (d.open) { campo.focus(); campo.select(); } else { campo.value = inicial; }
    });
    d.querySelector('[data-orc-fecha]').addEventListener('click', function () { d.open = false; });
    f.addEventListener('keydown', function (e) { if (e.key === 'Escape') { d.open = false; d.querySelector('summary').focus(); } });
    ouvir(document, 'click', function (e) { if (d.open && !d.contains(e.target) && !e.target.closest('.sel-painel, .sel-fundo')) { d.open = false; } });
    if (ob && ob.tagName === 'SELECT') {
      ob.addEventListener('change', function () {
        var c = parseInt(ob.options[ob.selectedIndex].getAttribute('data-atual'), 10) || 0;
        campo.value = (c / 100).toFixed(2).replace('.', ',');
        inicial = campo.value;
      });
    }
  });
  // Financeiro: "Ate" so aparece na despesa que se repete
  var despesa = document.querySelector('[data-despesa]');
  if (despesa) {
    var rep = despesa.querySelector('[data-repete]'), ate = despesa.querySelector('[data-ate]');
    if (rep && ate) { rep.addEventListener('change', function () { ate.hidden = rep.value === 'unico'; }); }
  }
  // Programar orcamento: "Repete" mostra os dias da semana; "Uma vez", a data
  var progs = document.querySelectorAll('[data-programar]');
  for (var pg = 0; pg < progs.length; pg++) {
    (function (f) {
      var mostrar = function () {
        var tipo = f.querySelector('input[name=tipo]:checked');
        var partes = f.querySelectorAll('[data-so]');
        for (var i = 0; i < partes.length; i++) { partes[i].hidden = !tipo || partes[i].getAttribute('data-so') !== tipo.value; }
      };
      f.addEventListener('change', mostrar);
      mostrar();
    })(progs[pg]);
  }
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
    // Modelos (Campanha, Conjunto, Criativo): montam a lista da direita; salvar continua
    // sendo no Salvar. O modelo igual a lista de agora fica marcado.
    var modelos = painelCols.querySelectorAll('[data-modelo]');
    function marcarModelo() {
      var agora = Array.prototype.map.call(lista.querySelectorAll('li'), function (li) { return li.getAttribute('data-coluna'); }).join(',');
      for (var i = 0; i < modelos.length; i++) {
        var ks = []; try { ks = JSON.parse(modelos[i].getAttribute('data-modelo')); } catch (x) {}
        modelos[i].setAttribute('aria-pressed', ks.join(',') === agora ? 'true' : 'false');
      }
    }
    for (var mo = 0; mo < modelos.length; mo++) {
      modelos[mo].addEventListener('click', function () {
        var ks = []; try { ks = JSON.parse(this.getAttribute('data-modelo')); } catch (x) { return; }
        lista.innerHTML = '';
        for (var i = 0; i < marcas.length; i++) { marcas[i].checked = ks.indexOf(marcas[i].value) !== -1; }
        for (var j = 0; j < ks.length; j++) { lista.appendChild(novoItem(ks[j])); }
        marcarModelo();
      });
    }
    painelCols.addEventListener('change', marcarModelo);
    lista.addEventListener('click', function () { setTimeout(marcarModelo, 0); });
    lista.addEventListener('dragend', marcarModelo);
    painelCols.querySelector('[data-colunas-cancela]').addEventListener('click', function () {
      lista.innerHTML = inicial;
      for (var i = 0; i < marcas.length; i++) { marcas[i].checked = estado[i]; }
      marcarModelo();
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

  // Tabela que ordena na tela (table[data-ordenar], ex.: a analise diaria): clicar no titulo
  // ordena; de novo, inverte. Numero comeca do maior, texto de A a Z e data do mais recente. O
  // valor vem em data-v (sem data-v, o texto da celula); celula sem valor fica sempre no fim. So
  // as linhas do corpo mudam (o total fica em cima). A escolha fica guardada neste navegador.
  function valorCelula(td, tipo) {
    var v = td.hasAttribute('data-v') ? td.getAttribute('data-v') : td.textContent.trim();
    if (tipo !== 'num') { return v; }
    if (!td.hasAttribute('data-v')) { v = v.replace(/[\u2212\u2013]/g, '-').replace(/[^\d,.-]/g, '').replace(/\./g, '').replace(',', '.'); }
    return v === '' || isNaN(parseFloat(v)) ? '' : parseFloat(v);
  }
  Array.prototype.forEach.call(document.querySelectorAll('table[data-ordenar]'), function (tab) {
    var corpo = tab.tBodies[0];
    if (!corpo) { return; }
    var chave = 'ordem-' + tab.getAttribute('data-ordenar');
    var ths = tab.querySelectorAll('th[data-tipo]');
    var linhas = Array.prototype.slice.call(corpo.rows);
    if (linhas.length < 2) { return; }
    function ordenar(th, dir) {
      var i = th.cellIndex, tipo = th.getAttribute('data-tipo');
      var lista = linhas.slice().sort(function (a, b) {
        var va = a.cells[i] ? valorCelula(a.cells[i], tipo) : '', vb = b.cells[i] ? valorCelula(b.cells[i], tipo) : '';
        if (va === '' || vb === '') { return va === vb ? 0 : (va === '' ? 1 : -1); }
        var c = tipo === 'num' ? va - vb : String(va).localeCompare(String(vb), 'pt-BR', { sensitivity: 'base', numeric: true });
        return dir === 'asc' ? c : -c;
      });
      for (var j = 0; j < lista.length; j++) { corpo.appendChild(lista[j]); }
      for (var k = 0; k < ths.length; k++) { ths[k].removeAttribute('aria-sort'); }
      th.setAttribute('aria-sort', dir === 'asc' ? 'ascending' : 'descending');
    }
    Array.prototype.forEach.call(ths, function (th) {
      if (th.hasAttribute('data-inicial')) { th.setAttribute('aria-sort', th.getAttribute('data-inicial') === 'asc' ? 'ascending' : 'descending'); }
      var bt = th.querySelector('.ordena');
      if (!bt) { return; }
      bt.addEventListener('click', function () {
        var primeiro = th.getAttribute('data-tipo') === 'texto' ? 'asc' : 'desc';
        var agora = th.getAttribute('aria-sort');
        var dir = agora === (primeiro === 'asc' ? 'ascending' : 'descending') ? (primeiro === 'asc' ? 'desc' : 'asc') : primeiro;
        ordenar(th, dir);
        try { localStorage.setItem(chave, JSON.stringify({ col: th.getAttribute('data-col'), dir: dir })); } catch (x) {}
      });
    });
    var salva = null;
    try { salva = JSON.parse(localStorage.getItem(chave) || 'null'); } catch (x) { salva = null; }
    if (salva && salva.col) {
      var thS = tab.querySelector('th[data-tipo][data-col="' + salva.col + '"]');
      if (thS) { ordenar(thS, salva.dir === 'asc' ? 'asc' : 'desc'); }
    }
  });

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
  // ---------------------------------------------------------------- seletores
  // Todo <select> do painel no mesmo padrao do Produto: botao com o texto e a seta, e uma lista
  // propria (embaixo, ou em cima se faltar espaco; no celular, um painel de baixo com fundo
  // escuro e opcoes grandes). O <select> continua no formulario, escondido: o envio e o
  // "change" dos filtros seguem iguais. Teclado: setas, Home, End, Enter, Esc e busca digitando.
  // data-nativo: fica o nativo (o periodo tem o seletor proprio, com calendario).
  var celular = window.matchMedia ? window.matchMedia('(max-width: 640px)') : { matches: false };
  var aberto = null, fundo = null, seqSel = 0;
  function abrirPainel(painel, caixa, fechar) {
    if (aberto && aberto.painel !== painel) { aberto.fechar(false); }
    aberto = { painel: painel, caixa: caixa, fechar: fechar };
    painel.hidden = false;
    painel.classList.remove('acima');
    if (celular.matches) {
      painel.classList.add('folha');
      if (!fundo) {
        fundo = document.createElement('div');
        fundo.className = 'sel-fundo';
        fundo.addEventListener('click', function () { if (aberto) { aberto.fechar(false); } });
        document.body.appendChild(fundo);
      }
      fundo.hidden = false;
      document.body.classList.add('com-folha');
    } else {
      painel.classList.remove('folha');
      var r = caixa.getBoundingClientRect();
      var precisa = Math.min(painel.scrollHeight, 340) + 12;
      if (window.innerHeight - r.bottom < precisa && r.top > window.innerHeight - r.bottom) { painel.classList.add('acima'); }
      // Nao deixa a lista sair da tela pela direita
      painel.style.left = '';
      var pr = painel.getBoundingClientRect();
      if (pr.right > window.innerWidth - 8) { painel.style.left = Math.min(0, window.innerWidth - 8 - pr.right) + 'px'; }
    }
  }
  function fecharPainel(painel) {
    painel.hidden = true;
    if (fundo) { fundo.hidden = true; }
    document.body.classList.remove('com-folha');
    if (aberto && aberto.painel === painel) { aberto = null; }
  }
  ouvir(document, 'click', function (e) { if (aberto && !aberto.caixa.contains(e.target) && !aberto.painel.contains(e.target)) { aberto.fechar(false); } });
  function seta() { return '<svg class="sel-seta" viewBox="0 0 16 16" aria-hidden="true"><path d="M4 6l4 4 4-4"/></svg>'; }

  function seletor(sel) {
    if (sel.hasAttribute('data-nativo') || sel.multiple || sel.getAttribute('data-feito')) { return; }
    sel.setAttribute('data-feito', '1');
    var id = 'sel' + (++seqSel);
    var caixa = document.createElement('div');
    caixa.className = 'sel';
    var botao = document.createElement('button');
    botao.type = 'button';
    botao.className = 'sel-botao';
    botao.setAttribute('aria-haspopup', 'listbox');
    botao.setAttribute('aria-expanded', 'false');
    botao.setAttribute('aria-controls', id);
    var texto = document.createElement('span');
    texto.className = 'sel-texto';
    botao.appendChild(texto);
    botao.insertAdjacentHTML('beforeend', seta());
    var rotulo = sel.closest('label');
    if (rotulo) { botao.setAttribute('aria-label', (rotulo.firstElementChild && rotulo.firstElementChild !== sel ? rotulo.firstElementChild.textContent : rotulo.textContent).replace(/\s+/g, ' ').trim()); }
    var lista = document.createElement('div');
    lista.className = 'sel-painel';
    lista.id = id;
    lista.setAttribute('role', 'listbox');
    lista.tabIndex = -1;
    lista.hidden = true;
    sel.parentNode.insertBefore(caixa, sel.nextSibling);
    caixa.appendChild(botao);
    caixa.appendChild(lista);
    sel.classList.add('sel-nativo');
    sel.tabIndex = -1;
    sel.setAttribute('aria-hidden', 'true');
    var ativo = -1, busca = '', buscaT = null;
    function atualizar() {
      var o = sel.options[sel.selectedIndex];
      texto.textContent = o ? o.textContent : '';
      botao.disabled = sel.disabled;
    }
    function marcar(i) {
      var ops = lista.children;
      if (ativo >= 0 && ops[ativo]) { ops[ativo].classList.remove('ativo'); }
      ativo = i;
      if (ops[i]) {
        ops[i].classList.add('ativo');
        lista.setAttribute('aria-activedescendant', ops[i].id);
        if (ops[i].scrollIntoView) { ops[i].scrollIntoView({ block: 'nearest' }); }
      }
    }
    function montar() {
      lista.textContent = '';
      Array.prototype.forEach.call(sel.options, function (o, i) {
        var d = document.createElement('div');
        d.className = 'sel-op';
        d.id = id + '-' + i;
        d.setAttribute('role', 'option');
        d.setAttribute('aria-selected', o.selected ? 'true' : 'false');
        if (o.disabled) { d.setAttribute('aria-disabled', 'true'); }
        d.textContent = o.textContent;
        d.addEventListener('click', function (e) { e.stopPropagation(); escolher(i); });
        d.addEventListener('mousemove', function () { if (ativo !== i) { marcar(i); } });
        lista.appendChild(d);
      });
    }
    function fechar(foco) {
      botao.setAttribute('aria-expanded', 'false');
      fecharPainel(lista);
      if (foco) { botao.focus(); }
    }
    function abrir() {
      montar();
      botao.setAttribute('aria-expanded', 'true');
      abrirPainel(lista, caixa, fechar);
      marcar(Math.max(0, sel.selectedIndex));
      lista.focus({ preventScroll: true });
    }
    function escolher(i) {
      var o = sel.options[i];
      if (!o || o.disabled) { return; }
      fechar(true);
      if (sel.selectedIndex !== i) {
        sel.selectedIndex = i;
        atualizar();
        sel.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }
    botao.addEventListener('click', function (e) { e.stopPropagation(); if (lista.hidden) { abrir(); } else { fechar(true); } });
    botao.addEventListener('keydown', function (e) {
      if (['ArrowDown', 'ArrowUp', 'Enter', ' '].indexOf(e.key) !== -1) { e.preventDefault(); abrir(); }
    });
    lista.addEventListener('keydown', function (e) {
      var n = sel.options.length;
      if (e.key === 'ArrowDown') { e.preventDefault(); marcar(Math.min(n - 1, ativo + 1)); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); marcar(Math.max(0, ativo - 1)); }
      else if (e.key === 'Home') { e.preventDefault(); marcar(0); }
      else if (e.key === 'End') { e.preventDefault(); marcar(n - 1); }
      else if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); escolher(ativo); }
      else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); fechar(true); }
      else if (e.key === 'Tab') { fechar(false); }
      else if (e.key.length === 1) {
        busca += e.key.toLowerCase();
        clearTimeout(buscaT);
        buscaT = setTimeout(function () { busca = ''; }, 700);
        for (var i = 0; i < n; i++) {
          if (sel.options[i].textContent.trim().toLowerCase().indexOf(busca) === 0) { marcar(i); break; }
        }
      }
    });
    sel.addEventListener('change', atualizar);
    atualizar();
  }
  Array.prototype.forEach.call(document.querySelectorAll('select'), seletor);

  // Listas de varios (Produto no topo, Fonte de trafego no Resumo): o mesmo botao e o mesmo painel
  // dos seletores (no celular, painel de baixo). "Todos" e a primeira caixa: marcar Todos
  // desmarca o resto; marcar um item desmarca Todos; sem nenhum, volta para Todos. Aplica ao
  // fechar (clicar fora, tocar no fundo ou Esc), se mudou alguma coisa. Sem JavaScript, fica o
  // <details> com as caixas e o botao Filtrar.
  Array.prototype.forEach.call(document.querySelectorAll('details[data-multi]'), function (multi) {
    var f = multi.closest('form');
    var painel = multi.querySelector('.multi-painel');
    if (!f || !painel) { return; }
    var todos = painel.querySelector('[data-multi-todos]');
    var marcas = Array.prototype.filter.call(painel.querySelectorAll('input[type=checkbox]'), function (c) { return c !== todos; });
    var nomes = { um: multi.getAttribute('data-um') || 'item', varios: multi.getAttribute('data-varios') || 'itens' };
    var caixa = document.createElement('div');
    caixa.className = 'sel sel-multi';
    var botao = document.createElement('button');
    botao.type = 'button';
    botao.className = 'sel-botao';
    botao.setAttribute('aria-haspopup', 'dialog');
    botao.setAttribute('aria-expanded', 'false');
    botao.innerHTML = '<span class="sel-texto"></span>' + seta();
    var rotuloTodos = todos ? todos.parentNode.textContent.trim() : 'Todos';
    var estado = function () { return marcas.map(function (c) { return c.checked ? '1' : '0'; }).join(''); };
    var mostrar = function () {
      var m = marcas.filter(function (c) { return c.checked; });
      if (todos) { todos.checked = !m.length; }
      botao.querySelector('.sel-texto').textContent = !m.length ? rotuloTodos : (m.length === 1 ? m[0].parentNode.textContent.trim() : m.length + ' ' + nomes.varios);
    };
    painel.classList.add('sel-painel');
    painel.setAttribute('role', 'dialog');
    painel.hidden = true;
    multi.parentNode.insertBefore(caixa, multi);
    caixa.appendChild(botao);
    caixa.appendChild(painel);
    multi.parentNode.removeChild(multi);
    var inicial = estado();
    mostrar();
    if (todos) {
      todos.addEventListener('change', function () {
        if (todos.checked) { marcas.forEach(function (c) { c.checked = false; }); }
        mostrar();
      });
    }
    marcas.forEach(function (c) { c.addEventListener('change', mostrar); });
    var fechar = function (foco, desistir) {
      botao.setAttribute('aria-expanded', 'false');
      fecharPainel(painel);
      if (foco) { botao.focus(); }
      if (!desistir && estado() !== inicial) { inicial = estado(); enviar(f); }
    };
    botao.addEventListener('click', function (e) {
      e.stopPropagation();
      if (painel.hidden) { botao.setAttribute('aria-expanded', 'true'); abrirPainel(painel, caixa, fechar); } else { fechar(true); }
    });
    painel.addEventListener('keydown', function (e) { if (e.key === 'Escape') { e.stopPropagation(); fechar(true); } });
  });

  // ---------------------------------------------------------------- calendario
  var MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
  function doisDig(n) { return (n < 10 ? '0' : '') + n; }
  function iso(d) { return d.getFullYear() + '-' + doisDig(d.getMonth() + 1) + '-' + doisDig(d.getDate()); }
  function lerIso(t) { var p = String(t || '').split('-'); return p.length === 3 ? new Date(+p[0], +p[1] - 1, +p[2]) : null; }
  function br(t, ano) { var p = String(t || '').split('-'); return p.length === 3 ? p[2] + '/' + p[1] + (ano ? '/' + p[0] : '') : ''; }
  // Calendario de um mes: um dia (intervalo: false) ou do primeiro ao ultimo (intervalo: true).
  // o: {intervalo, de, ate, min, max, aoEscolher(de, ate)}
  function calendario(o) {
    var el = document.createElement('div');
    el.className = 'cal';
    var base = lerIso(o.ate || o.de) || new Date();
    var mes = new Date(base.getFullYear(), base.getMonth(), 1);
    var de = o.de || '', ate = o.ate || '', escolhendo = false, sobre = '';
    function desenhar() {
      var hoje = iso(new Date());
      var h = '<div class="cal-cab"><button type="button" data-mes="-1" aria-label="Mês anterior">‹</button><b>' + MESES[mes.getMonth()].charAt(0).toUpperCase() + MESES[mes.getMonth()].slice(1) + ' de ' + mes.getFullYear() + '</b><button type="button" data-mes="1" aria-label="Próximo mês">›</button></div>'
        + '<div class="cal-sem"><span>Dom</span><span>Seg</span><span>Ter</span><span>Qua</span><span>Qui</span><span>Sex</span><span>Sáb</span></div><div class="cal-dias">';
      var d = new Date(mes.getFullYear(), mes.getMonth(), 1 - mes.getDay());
      var fim = escolhendo && sobre ? (sobre < de ? de : sobre) : ate;
      var ini = escolhendo && sobre && sobre < de ? sobre : de;
      for (var i = 0; i < 42; i++) {
        var t = iso(d), cls = 'cal-dia';
        if (d.getMonth() !== mes.getMonth()) { cls += ' fora'; }
        if (t === hoje) { cls += ' hoje'; }
        if (o.intervalo) {
          if (ini && t === ini) { cls += ' inicio'; }
          if (fim && t === fim) { cls += ' fim'; }
          if (ini && fim && t > ini && t < fim) { cls += ' no-intervalo'; }
        } else if (t === de) { cls += ' inicio fim'; }
        var fora = (o.min && t < o.min) || (o.max && t > o.max);
        h += '<button type="button" class="' + cls + '" data-dia="' + t + '"' + (fora ? ' disabled' : '') + ' aria-label="' + br(t, true) + '">' + d.getDate() + '</button>';
        d.setDate(d.getDate() + 1);
      }
      el.innerHTML = h + '</div>';
    }
    el.addEventListener('click', function (e) {
      e.stopPropagation();
      var m = e.target.closest('[data-mes]');
      if (m) { mes.setMonth(mes.getMonth() + (+m.getAttribute('data-mes'))); desenhar(); return; }
      var b = e.target.closest('[data-dia]');
      if (!b || b.disabled) { return; }
      var t = b.getAttribute('data-dia');
      if (!o.intervalo) { de = ate = t; desenhar(); o.aoEscolher(t, t); return; }
      if (!escolhendo) { de = t; ate = ''; escolhendo = true; }
      else { if (t < de) { ate = de; de = t; } else { ate = t; } escolhendo = false; sobre = ''; }
      desenhar();
      o.aoEscolher(de, ate);
    });
    el.addEventListener('mouseover', function (e) {
      var b = e.target.closest && e.target.closest('[data-dia]');
      if (escolhendo && b && b.getAttribute('data-dia') !== sobre) { sobre = b.getAttribute('data-dia'); desenhar(); }
    });
    desenhar();
    return el;
  }

  // ---------------------------------------------------------------- periodo do topo
  // Um botao so ("Ultimos 7 dias", "25/09 a 06/10"): os prontos a esquerda e o calendario do
  // primeiro ao ultimo dia a direita. Manda periodo=personalizado com de e ate (o index.php
  // ja entende). Sem JavaScript, ficam o select e as duas datas.
  var campoP = document.querySelector('[data-periodo-campo]');
  if (campoP && form) {
    var selP = campoP.querySelector('select[name=periodo]');
    var datasP = campoP.querySelector('[data-datas]');
    var caixaP = document.createElement('div');
    caixaP.className = 'sel';
    var botaoP = document.createElement('button');
    botaoP.type = 'button';
    botaoP.className = 'sel-botao';
    botaoP.setAttribute('aria-haspopup', 'dialog');
    botaoP.setAttribute('aria-expanded', 'false');
    botaoP.setAttribute('aria-label', 'Período: ' + campoP.getAttribute('data-rotulo'));
    botaoP.innerHTML = '<svg class="ico" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/></svg><span class="sel-texto"></span>' + seta();
    botaoP.querySelector('.sel-texto').textContent = campoP.getAttribute('data-rotulo');
    var painelP = document.createElement('div');
    painelP.className = 'sel-painel periodo-painel';
    painelP.setAttribute('role', 'dialog');
    painelP.setAttribute('aria-label', 'Escolher o período');
    painelP.hidden = true;
    var prontos = document.createElement('div');
    prontos.className = 'periodo-prontos';
    Array.prototype.forEach.call(selP.options, function (o) {
      if (o.value === 'personalizado') { return; }
      var b = document.createElement('button');
      b.type = 'button';
      b.textContent = o.textContent;
      if (o.selected) { b.className = 'atual'; }
      b.addEventListener('click', function (e) {
        e.stopPropagation();
        selP.value = o.value;
        Array.prototype.forEach.call(datasP.querySelectorAll('input'), function (i) { i.disabled = true; });
        fecharP(false, true);
        botaoP.querySelector('.sel-texto').textContent = o.textContent;
        enviar(form);
      });
      prontos.appendChild(b);
    });
    // Calendario: o primeiro e o ultimo dia; clicar fora aplica (um dia so tambem vale). Esc desiste.
    var de0 = campoP.getAttribute('data-de'), ate0 = campoP.getAttribute('data-ate'), hojeP = campoP.getAttribute('data-hoje');
    var de = de0, ate = ate0;
    var escolhido = document.createElement('span');
    var mostrarEscolha = function () {
      escolhido.textContent = de && ate ? (de === ate ? br(de, true) : br(de) + ' a ' + br(ate, true)) + ' · clique fora para aplicar' : 'Agora o último dia (ou clique fora para ver só esse dia)';
    };
    var mexeu = false;
    var cal = calendario({ intervalo: true, de: de, ate: ate, max: hojeP, aoEscolher: function (a, b) { de = a; ate = b; mexeu = true; mostrarEscolha(); } });
    var ladoCal = document.createElement('div');
    ladoCal.className = 'periodo-cal';
    ladoCal.appendChild(cal);
    var pe = document.createElement('div');
    pe.className = 'periodo-pe';
    pe.appendChild(escolhido);
    ladoCal.appendChild(pe);
    painelP.appendChild(prontos);
    painelP.appendChild(ladoCal);
    escolhido.textContent = 'Escolha um pronto ou, no calendário, o primeiro e o último dia';
    var aplicarP = function () {
      var fim = ate || de;
      if (!de || (de === de0 && fim === ate0 && selP.value === 'personalizado')) { return; }
      selP.value = 'personalizado';
      Array.prototype.forEach.call(datasP.querySelectorAll('input'), function (i) { i.disabled = false; });
      form.elements.de.value = de;
      form.elements.ate.value = fim;
      botaoP.querySelector('.sel-texto').textContent = de === fim ? br(de, true) : br(de) + ' a ' + br(fim);
      enviar(form);
    };
    // desistir: Esc (fica o que estava); fechar de outro jeito (clique fora, tocar no fundo) aplica
    var fecharP = function (foco, desistir) {
      botaoP.setAttribute('aria-expanded', 'false');
      fecharPainel(painelP);
      if (foco) { botaoP.focus(); }
      if (!desistir && mexeu) { mexeu = false; aplicarP(); }
    };
    botaoP.addEventListener('click', function (e) {
      e.stopPropagation();
      if (painelP.hidden) { mexeu = false; botaoP.setAttribute('aria-expanded', 'true'); abrirPainel(painelP, caixaP, fecharP); } else { fecharP(true); }
    });
    painelP.addEventListener('keydown', function (e) { if (e.key === 'Escape') { e.stopPropagation(); fecharP(true, true); } });
    caixaP.appendChild(botaoP);
    caixaP.appendChild(painelP);
    selP.classList.add('sel-nativo');
    selP.tabIndex = -1;
    selP.setAttribute('aria-hidden', 'true');
    datasP.hidden = true;
    datasP.setAttribute('data-sem-js', '');
    campoP.appendChild(caixaP);
  }

  // ---------------------------------------------------------------- datas soltas
  // Campos de data fora do topo (Financeiro, Programar orcamento): o mesmo calendario, de um dia
  Array.prototype.forEach.call(document.querySelectorAll('input[type=date]'), function (inp) {
    if (inp.closest('[data-datas]')) { return; }
    var caixa = document.createElement('div');
    caixa.className = 'sel campo-data';
    var botao = document.createElement('button');
    botao.type = 'button';
    botao.className = 'sel-botao';
    botao.setAttribute('aria-haspopup', 'dialog');
    botao.setAttribute('aria-expanded', 'false');
    botao.innerHTML = '<span class="sel-texto"></span><svg class="ico" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/></svg>';
    var texto = botao.querySelector('.sel-texto');
    var painel = document.createElement('div');
    painel.className = 'sel-painel';
    painel.setAttribute('role', 'dialog');
    painel.hidden = true;
    var mostrar = function () { texto.textContent = inp.value ? br(inp.value, true) : (inp.required ? 'Escolher a data' : 'Sem data'); };
    var fechar = function (foco) { botao.setAttribute('aria-expanded', 'false'); fecharPainel(painel); if (foco) { botao.focus(); } };
    var cal = calendario({ intervalo: false, de: inp.value, min: inp.min, max: inp.max, aoEscolher: function (d) {
      inp.value = d; mostrar(); fechar(true); inp.dispatchEvent(new Event('change', { bubbles: true }));
    } });
    painel.appendChild(cal);
    if (!inp.required) {
      var limpar = document.createElement('button');
      limpar.type = 'button';
      limpar.className = 'discreto neutro';
      limpar.textContent = 'Sem data';
      limpar.style.marginTop = '8px';
      limpar.addEventListener('click', function (e) { e.stopPropagation(); inp.value = ''; mostrar(); fechar(true); });
      painel.appendChild(limpar);
    }
    botao.addEventListener('click', function (e) {
      e.stopPropagation();
      if (painel.hidden) { botao.setAttribute('aria-expanded', 'true'); abrirPainel(painel, caixa, fechar); } else { fechar(true); }
    });
    painel.addEventListener('keydown', function (e) { if (e.key === 'Escape') { e.stopPropagation(); fechar(true); } });
    inp.parentNode.insertBefore(caixa, inp.nextSibling);
    caixa.appendChild(botao);
    caixa.appendChild(painel);
    inp.type = 'hidden';
    mostrar();
  });

  // ---------------------------------------------------------------- barra lateral
  // No celular, a barra do admin (CMS, UTM, aparencia, configuracoes e perfil) fica recolhida
  // numa linha so; a seta abre e fecha. Os filtros ficam sempre a mostra. No computador, a
  // barra e a coluna da esquerda, sempre aberta.
  var lateral = document.querySelector('[data-lateral]');
  if (lateral) {
    var alterna = lateral.querySelector('[data-lateral-alterna]');
    if (alterna) {
      alterna.addEventListener('click', function () {
        var sim = !lateral.classList.contains('aberta');
        lateral.classList.toggle('aberta', sim);
        alterna.setAttribute('aria-expanded', sim ? 'true' : 'false');
      });
    }
    ouvir(document, 'click', function (e) {
      if (lateral.classList.contains('aberta') && !lateral.contains(e.target)) { lateral.classList.remove('aberta'); if (alterna) { alterna.setAttribute('aria-expanded', 'false'); } }
    });
  }

  // Esc fecha o que estiver aberto: colunas, produto, aparencia
  ouvir(document, 'keydown', function (e) {
    if (e.key !== 'Escape') { return; }
    if (aberto) { aberto.fechar(true); return; }
    Array.prototype.forEach.call(document.querySelectorAll('details.colunas[open], details.multi[open], details.tema-menu[open], details.orc-inline[open]'), function (d) { d.open = false; });
    var lat = document.querySelector('[data-lateral].aberta');
    if (lat) { lat.classList.remove('aberta'); }
  });
  // Foto do perfil: qualquer imagem, de qualquer tamanho. O navegador corta no centro, reduz
  // para 512 x 512 (nitida na bolinha mesmo em tela de iPhone) e comprime em JPEG ate caber em
  // 64 KB, baixando a qualidade aos poucos (e o tamanho, se precisar). O servidor so confere.
  var foto = document.querySelector('[data-foto-arquivo]');
  if (foto) {
    foto.addEventListener('change', function () {
      var arq = foto.files && foto.files[0];
      var aviso = foto.form.querySelector('[data-foto-aviso]');
      var dizer = function (t) { if (aviso) { aviso.textContent = t; } };
      if (!arq) { return; }
      dizer('Preparando a foto…');
      comprimirFoto(arq, 64 * 1024).then(function (jpeg) {
        var dados = new FormData(foto.form);
        dados.set('foto', jpeg, 'perfil.jpg');
        dizer('Enviando…');
        navegar(foto.form.action, { corpo: dados, manter: true });
      }).catch(function () { dizer('Não consegui abrir essa imagem. Tente uma foto em JPEG ou PNG.'); });
    });
  }
  }

  function comprimirFoto(arq, limite) {
    return new Promise(function (ok, falha) {
      var img = new Image();
      var url = URL.createObjectURL(arq);
      img.onload = function () {
        URL.revokeObjectURL(url);
        var lado = Math.min(img.naturalWidth, img.naturalHeight);
        if (!lado) { falha(); return; }
        var tentar = function (tam, q) {
          var c = document.createElement('canvas');
          c.width = c.height = tam;
          var g = c.getContext('2d');
          g.imageSmoothingQuality = 'high';
          g.fillStyle = '#fff';
          g.fillRect(0, 0, tam, tam);
          g.drawImage(img, (img.naturalWidth - lado) / 2, (img.naturalHeight - lado) / 2, lado, lado, 0, 0, tam, tam);
          c.toBlob(function (b) {
            if (!b) { falha(); return; }
            if (b.size <= limite) { ok(b); return; }
            if (q > 0.5) { tentar(tam, Math.round((q - 0.08) * 100) / 100); return; }
            if (tam > 256) { tentar(Math.round(tam * 0.8), 0.86); return; }
            falha();
          }, 'image/jpeg', q);
        };
        tentar(Math.min(512, lado), 0.9);
      };
      img.onerror = function () { URL.revokeObjectURL(url); falha(); };
      img.src = url;
    });
  }

  // ---------------------------------------------------------------- navegacao suave
  // Filtros, abas, ordenar, chaves e os formularios do painel trocam a tela sem recarregar: o
  // painel busca a tela nova (ou envia o formulario e segue o redirecionamento), troca o corpo da
  // pagina e liga tudo de novo, no mesmo ponto da rolagem. Mudou de tela (outra aba, outra
  // campanha): volta para cima. O endereco, o voltar e o avancar continuam funcionando. Outra
  // pagina (Configuracoes, CMS, sair), erro ou navegador antigo: vai do jeito normal.
  var navegacao = 0;
  function caminho(u) { return u.pathname.replace(/index\.php$/, ''); }
  function mesmaPagina(u) { return u.origin === location.origin && caminho(u) === caminho(location); }
  // A mesma tela: mesma aba e, na analise diaria, o mesmo objeto (os niveis do gestor, a ordem e
  // os filtros nao contam: a rolagem fica onde estava)
  function tela(u) {
    var aba = u.searchParams.get('aba') || 'geral';
    return aba + (aba === 'campanha' ? '|' + (u.searchParams.get('id') || '') + '|' + (u.searchParams.get('nivel') || '') : '');
  }
  function trocar(doc) {
    var raiz = document.documentElement, nova = doc.documentElement;
    ['class', 'data-tema', 'data-base', 'style'].forEach(function (a) {
      var v = nova.getAttribute(a);
      if (v === null) { raiz.removeAttribute(a); } else { raiz.setAttribute(a, v); }
    });
    document.title = doc.title;
    var estilosNovos = doc.head.querySelectorAll('style'), estilos = document.head.querySelectorAll('style');
    for (var i = 0; i < estilosNovos.length; i++) {
      if (!estilos[i]) { document.head.appendChild(estilosNovos[i].cloneNode(true)); } else if (estilos[i].textContent !== estilosNovos[i].textContent) { estilos[i].textContent = estilosNovos[i].textContent; }
    }
    Array.prototype.forEach.call(doc.body.querySelectorAll('script'), function (sc) { sc.parentNode.removeChild(sc); });
    soltarOuvintes();
    raiz.replaceChild(document.adoptNode(doc.body), document.body);
  }
  function navegar(url, op) {
    op = op || {};
    var alvo = new URL(url, location.href);
    if (!window.fetch || !window.DOMParser || !history.pushState) {
      if (op.corpo && op.form) { op.form.submit(); } else { location.href = alvo.href; }
      return;
    }
    var seq = ++navegacao;
    var y = window.scrollY, antes = new URL(location.href);
    document.documentElement.classList.add('navegando');
    var pedido = op.corpo ? fetch(alvo.href, { method: 'POST', body: op.corpo, credentials: 'same-origin' }) : fetch(alvo.href, { credentials: 'same-origin' });
    pedido.then(function (r) {
      var final = new URL(r.url || alvo.href);
      if (alvo.hash && !final.hash) { final.hash = alvo.hash; }
      if (!mesmaPagina(final) || (r.headers.get('content-type') || '').indexOf('text/html') === -1) { location.href = final.href; return null; }
      return r.text().then(function (html) { return [final, html]; });
    }).then(function (res) {
      if (!res || seq !== navegacao) { return; }
      var doc = new DOMParser().parseFromString(res[1], 'text/html');
      if (!doc.body || !doc.body.children.length) { location.href = res[0].href; return; }
      var manter = op.manter !== undefined ? op.manter : tela(res[0]) === tela(antes);
      if (op.historico !== false) {
        try { history.replaceState({ y: y }, ''); } catch (x) {}
        var igual = res[0].href === antes.href;
        history[op.substituir || igual ? 'replaceState' : 'pushState']({ y: 0 }, '', res[0].href);
        if ('scrollRestoration' in history) { history.scrollRestoration = 'manual'; }
      }
      trocar(doc);
      ultima = semAncora(location.href);
      iniciar();
      var ancora = res[0].hash && document.getElementById(res[0].hash.slice(1));
      if (op.y !== undefined) { window.scrollTo(0, op.y); } else if (ancora) { ancora.scrollIntoView(); } else { window.scrollTo(0, manter ? y : 0); }
    }).catch(function () {
      // Erro de rede: o formulario enviado pode ter ido; recarrega a tela que estava, sem reenviar
      location.href = op.corpo ? location.href : alvo.href;
    }).then(function () {
      if (seq === navegacao) { document.documentElement.classList.remove('navegando'); }
    });
  }
  // Links da mesma pagina do painel (abas, niveis do gestor, ordenar, trilha...). Depois dos
  // ouvintes da tela (a dica do (i) num link cancela o clique).
  window.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
    var a = e.target.closest && e.target.closest('a[href]');
    if (!a || a.target || a.hasAttribute('download') || a.hasAttribute('data-recarrega')) { return; }
    var u = new URL(a.href, location.href);
    if (!mesmaPagina(u)) { return; }
    if (u.hash && u.search === location.search) { return; } // ancora na mesma tela
    e.preventDefault();
    navegar(u.href);
  });
  // Formularios: filtros (GET) buscam a tela nova; os de acao (POST: chave, orcamento, despesa,
  // atualizar...) enviam e mostram a tela para onde o painel voltaria. Depois da confirmacao.
  window.addEventListener('submit', function (e) {
    var f = e.target;
    if (e.defaultPrevented || !f || f.tagName !== 'FORM' || f.target || f.hasAttribute('data-recarrega')) { return; }
    var botao = e.submitter || null;
    var metodo = ((botao && botao.getAttribute('formmethod')) || f.getAttribute('method') || 'get').toLowerCase();
    var acao = new URL((botao && botao.getAttribute('formaction')) || f.getAttribute('action') || location.href, location.href);
    if (acao.origin !== location.origin) { return; }
    var dados = new FormData(f);
    if (botao && botao.name) { dados.append(botao.name, botao.value); }
    if (metodo === 'get') {
      if (!mesmaPagina(acao)) { return; }
      acao.search = new URLSearchParams(dados).toString();
      e.preventDefault();
      navegar(acao.href);
      return;
    }
    e.preventDefault();
    navegar(acao.href, { corpo: dados, form: f });
  });
  // Voltar e avancar: busca a tela daquele endereco e volta para a rolagem de la. So a ancora
  // mudou (ex.: #perfil): fica com o navegador.
  var semAncora = function (h) { return String(h).split('#')[0]; };
  var ultima = semAncora(location.href);
  window.addEventListener('popstate', function (e) {
    if (semAncora(location.href) === ultima) { return; }
    navegar(location.href, { historico: false, y: (e.state && e.state.y) || 0 });
  });

  iniciar();
})();
