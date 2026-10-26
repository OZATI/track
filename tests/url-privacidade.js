// Confere o conteúdo realmente enviado pelo coletor sem rede nem dependências de navegador.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const requests = [];
const context = {
  URL, JSON, Array,
  location: { href: 'https://site.test/oferta?email=pessoa%40exemplo.test&token=segredo&utm_source=MetaAds&utm_campaign=Campanha%7C123#privado' },
  document: {
    currentScript: { src: 'https://admin.test/utm/t.js' },
    referrer: 'https://origem.test/artigo?token=outro-segredo#trecho',
    querySelectorAll: () => [],
    addEventListener: () => {}
  },
  localStorage: { getItem: () => null, setItem: () => {} },
  fetch: (url, options) => {
    requests.push({ url, body: JSON.parse(options.body) });
    return Promise.resolve({ ok: false });
  }
};
context.window = context;
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '..', 't.js'), 'utf8'), context);
assert.equal(requests[0].body.url, 'https://site.test/oferta');
assert.equal(requests[0].body.referrer, 'https://origem.test/artigo');
assert.equal(requests[0].body.utms.utm_source, 'MetaAds');
assert.equal(requests[0].body.utms.utm_campaign, 'Campanha|123');
assert.ok(!JSON.stringify(requests[0]).includes('segredo'));
assert.ok(!JSON.stringify(requests[0]).includes('pessoa'));
context.trk('CliqueCheckout', 'Comprar', 'https://pay.kiwify.com.br/oferta?cpf=123456&utm_content=Criativo%7C456');
assert.equal(requests[1].body.utms.utm_content, 'Criativo|456');
assert.ok(!JSON.stringify(requests[1]).includes('cpf'));
console.log('URLs sem query/fragmento e atribuição UTM preservada.');
