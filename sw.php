<?php
// Service worker do painel: deixa instalar como app e mostra as notificacoes (lib/push.php).
// Servido por PHP para nunca ficar preso em cache (o site pode mandar cachear .js por 1 ano).
// Nada fica guardado no aparelho: sem internet, so uma tela avisando.

require __DIR__ . '/lib/util.php';
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Content-Type-Options: nosniff');
$icone = is_file(__DIR__ . '/../icone-app-192.png') ? '../icone-app-192.png' : 'app-192.png';
?>
'use strict';
var ICONE = new URL(<?= json_encode($icone) ?>, self.registration.scope).href;
var OFFLINE = '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sem conexão</title>'
  + '<body style="font:16px system-ui,sans-serif;padding:32px;color:#111827;background:#F7F8FA"><h1 style="font-size:20px">Sem conexão</h1>'
  + '<p>O painel precisa de internet. Assim que a conexão voltar, recarregue.</p></body>';

self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (e) { e.waitUntil(self.clients.claim()); });

// So abrir pagina (GET): sem internet, uma tela simples em vez do erro do navegador.
// Formularios (login, salvar, ligar campanha) passam direto, sem o service worker.
self.addEventListener('fetch', function (e) {
  if (e.request.mode !== 'navigate' || e.request.method !== 'GET') { return; }
  e.respondWith(fetch(e.request).catch(function () {
    return new Response(OFFLINE, { headers: { 'Content-Type': 'text/html; charset=utf-8' } });
  }));
});

self.addEventListener('push', function (e) {
  var d = {};
  try { d = e.data ? e.data.json() : {}; } catch (x) { d = {}; }
  e.waitUntil(self.registration.showNotification(d.titulo || 'Painel', {
    body: d.corpo || '', icon: ICONE, badge: ICONE, tag: d.tag || undefined, renotify: !!d.tag,
    data: { url: d.url || './' }
  }));
});

// Clique na notificacao: abre (ou traz para frente) o painel na tela certa
self.addEventListener('notificationclick', function (e) {
  e.notification.close();
  var url = new URL((e.notification.data && e.notification.data.url) || './', self.registration.scope).href;
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (lista) {
    for (var i = 0; i < lista.length; i++) {
      if (lista[i].url.indexOf(self.registration.scope) === 0 && 'focus' in lista[i]) {
        return lista[i].navigate(url).then(function (c) { return c && c.focus(); })
          .catch(function () { return self.clients.openWindow(url); });
      }
    }
    return self.clients.openWindow(url);
  }));
});
