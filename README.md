# Track — painel de rastreio e conferência de vendas

Painel próprio que registra **cada evento de cada visitante** nas páginas de venda e liga a **venda da Kiwify** a quem a fez. Serve para saber, venda por venda, se a origem que a página mandou é a mesma que a Kiwify e a UTMify registraram.

Um painel atende vários sites: no topo, escolha o **site** e depois a **página**. A tela inicial é a tabela de **Tráfego**: por origem (source / medium / campaign), quantos visitantes, visualizações, cliques no checkout, cliques no WhatsApp, vendas aprovadas e conversão, e embaixo o mesmo por página.

- PHP 8.1+ e SQLite, sem build, sem framework, sem dependência. Roda na hospedagem compartilhada da Hostinger.
- Não manda nada para Meta, Google ou UTMify. Só guarda e mostra.
- Usado por: `engdesk.pro` (Drive de Projetos). Instalação guiada pela skill `rastreio` do Claude Code.

---

## Como funciona

```
Página de venda (engdesk.pro/drivedeprojetos/)
  └─ t.js ──► track.engdesk.pro/coletar.php    PageView, CliqueCheckout, WhatsApp, Botao
       │        (cookie trk_vid = o visitante)
       └─ põe sck=trk_<visitante> no link da Kiwify
                         │
Kiwify (pay.kiwify.com.br) ── webhook ──► track.engdesk.pro/kiwify.php?chave=...
                                            venda + etiquetas + sck (volta o visitante)

track.engdesk.pro  (login)  →  Conferência · Vendas · Visitantes · Eventos
```

**Visitante** é um navegador num aparelho, identificado pelo cookie `trk_vid`, que **o servidor** grava. Quando o painel é do mesmo site das páginas (`track.engdesk.pro` para `engdesk.pro`), o cookie vale para o domínio inteiro e o Safari não o apaga. Para um site diferente (ex.: `ortopaz.com.br` mandando para `track.engdesk.pro`), o `t.js` guarda o identificador no navegador, e o Safari pode apagar em 7 dias. Por isso o ideal é um painel por domínio: `track.<domínio>`.

**Conferência:** para cada venda aprovada, o painel pega o último `CliqueCheckout` daquele visitante (com as etiquetas do **link** que foi para a Kiwify) e compara com as etiquetas que a Kiwify gravou no pedido:

| Resultado | Significa |
|---|---|
| **Bate** | A página mandou e a Kiwify gravou a mesma origem e campanha |
| **Diferente** | A Kiwify gravou outra coisa. A venda mostra as duas |
| **Sem visitante** | O pedido chegou sem o `sck`: link direto da Kiwify, outro aparelho ou página sem o `t.js` |
| **Sem clique registrado** | O visitante foi reconhecido, mas o clique não chegou ao painel (rede caiu no clique) |

---

## Instalação na Hostinger

1. **Subdomínio:** hPanel → Domínios → Subdomínios → criar `track`. Anote a pasta dele (ex.: `public_html/track`).
2. **Git:** hPanel → Avançado → Git → repositório `https://github.com/OZATI/track.git`, branch `main`, diretório = a pasta do subdomínio. Implantar. (Repositório privado: adicionar a chave SSH que o hPanel mostra em GitHub → OZATI/track → Settings → Deploy keys.)
3. **Instalar, em até 1 hora depois do deploy:** abrir `https://track.<domínio>/instalar.php`, definir a senha (10+ caracteres), os sites que vão mandar eventos (ex.: `https://engdesk.pro`) e a retenção em dias. A tela mostra **uma vez** a URL do webhook.
4. **Kiwify:** Apps → Webhooks → criar com a URL do passo 3 e os eventos *compra aprovada, compra reembolsada, chargeback, Pix gerado, compra recusada*, para os produtos das páginas.
5. **Páginas de venda:** no fim do `<body>`, **depois** do script de atribuição da página:
   ```html
   <script src="https://track.<domínio>/t.js" defer></script>
   ```
   A página precisa ter o script da UTMify com `data-utmify-prevent-xcod-sck`, para a UTMify não sobrescrever o `sck`.
6. **Conferir:** abrir a página de venda, clicar no botão de compra e ver o visitante e o clique em **Eventos**. Fazer uma compra de teste e ver a venda em **Vendas** com a conferência.

### Dentro de um admin que já existe (ex.: `admin.engdesk.pro/utm/`)

Em vez de um subdomínio próprio, o painel pode morar numa subpasta de um admin, com a barra lateral **CMS | UTM**:

1. Copie os arquivos de execução deste repositório para a subpasta do admin (ex.: `engdesk/admin/utm/`): `*.php`, `lib/`, `t.js`, `painel.js`, `.htaccess`. Crie `VERSAO` com o commit do `OZATI/track` copiado. Não edite a cópia: mude aqui e copie de novo.
2. O deploy do próprio site publica a subpasta. Em até 1 hora, abra `https://admin.<domínio>/utm/instalar.php` e preencha também o **Link do CMS** (ex.: `../`). Ele liga a barra lateral.
3. A URL do webhook e o `<script>` que a instalação mostra já vêm com a subpasta (`/utm/kiwify.php`, `/utm/t.js`).

O painel tem a própria senha. O login do admin (ex.: Supabase) não é reaproveitado nesta versão.

**Onde ficam os dados:** `.../track-dados/` (config.php e track.sqlite), ao lado do `public_html`, fora de qualquer site e do deploy. Em outro servidor, defina a variável `TRACK_DADOS`.

**Passou 1 hora sem instalar?** Faça um novo deploy e abra o `instalar.php` logo em seguida, ou crie `track-dados/config.php` à mão:

```php
<?php return [
  'senha_hash'    => '...',               // php -r "echo password_hash('SENHA', PASSWORD_DEFAULT);"
  'chave_webhook' => '...',               // php -r "echo bin2hex(random_bytes(24));"
  'origens'       => ['https://engdesk.pro'],
  'dias_retencao' => 90,
  'menu_cms'      => '',                  // ex.: '../' dentro de um admin (barra lateral CMS | UTM)
  'fuso'          => 'America/Sao_Paulo',
];
```

**Atualizar:** novo commit na `main` e Implantar no hPanel. Dados e configuração não são tocados.

---

## Privacidade (LGPD)

| Guarda | Não guarda |
|---|---|
| Identificador aleatório do visitante (cookie `trk_vid`, 180 dias) | Nome, e-mail, telefone ou CPF do comprador (o webhook traz, o painel descarta) |
| IP **parcial** (IPv4 sem o último número; IPv6 só o prefixo /48) | IP completo |
| Aparelho, sistema e navegador | O user agent completo |
| Página, evento, etiquetas UTM e o site de onde a pessoa veio (sem os parâmetros) | Os parâmetros do endereço de origem |
| Pedido, produto, valor, status e etiquetas da venda | Dados de pagamento |

Tudo é apagado depois de `dias_retencao` (padrão 90). Cite o painel e o cookie na política de privacidade do site.

## Segurança

- Painel com senha (`password_hash`), sessão `HttpOnly` + `SameSite=Strict`, token em todo formulário, 5 senhas erradas bloqueiam o IP por 15 minutos.
- Coleta só aceita os sites da lista, confere que a página é do mesmo domínio que chamou, valida o nome do evento e limita 240 eventos por minuto por IP.
- Webhook só grava com a chave secreta da URL (a Kiwify não documenta assinatura dos avisos).
- Toda saída escapada (sem XSS), SQL só com parâmetros, CSP restrita, `noindex`, pastas `lib/`, `tests/` e arquivos `.md/.sqlite` bloqueados no `.htaccess`.
- Instalação só abre enquanto não há configuração e por 1 hora depois do deploy.

## Testes

```bash
bash tests/fluxo.sh
# PHP fora do PATH (Windows, PHP portátil):
PHP=/caminho/php.exe PHP_FLAGS="-d extension_dir=ext -d extension=pdo_sqlite -d extension=mbstring" bash tests/fluxo.sh
```

Sobe um servidor local com dados temporários e confere instalação, coleta, webhook, LGPD, login, tabela de tráfego, barra lateral, conferência, filtros por site e página, XSS e limite de login. Não toca em nenhum site real.

## Limites

- Troca de navegador ou de aparelho gera dois visitantes: a venda chega como **Sem visitante** ou ligada ao segundo.
- IP não identifica pessoa (operadoras colocam muita gente no mesmo IP). Quem identifica o visitante é o cookie.
- Vendas anteriores à instalação não têm o `sck` e aparecem como **Sem visitante**.
