# Track — painel de rastreio e conferência de vendas

Painel próprio que registra **cada evento de cada visitante** nas páginas de venda e liga a **venda da Kiwify** a quem a fez. Serve para saber, venda por venda, se a origem que a página mandou é a mesma que a Kiwify e a UTMify registraram.

Um painel atende vários sites: no topo, escolha o **site** e depois a **página**. A tela inicial é a tabela de **Tráfego**: por origem (source / medium / campaign), quantos visitantes, visualizações, cliques no checkout, cliques no WhatsApp, vendas aprovadas e conversão, e embaixo o mesmo por página.

- PHP 8.1+ e SQLite, sem build, sem framework, sem dependência. Roda na hospedagem compartilhada da Hostinger.
- Não manda nada para Meta, Google ou UTMify. Só guarda e mostra.
- Com login por usuário e senha, para quantas pessoas precisar (aba **Usuários**), com bloqueio após 5 tentativas erradas.
- Código aberto, licença MIT. Feito pela [OZATI](https://ozati.co).

**Por que não Umami, Plausible ou Matomo?** Eles medem visitas. Este painel existe para a pergunta seguinte: a **venda** que caiu na Kiwify veio de qual clique, e a etiqueta que a página mandou chegou inteira no pedido? Para isso ele liga o visitante ao pedido pelo `sck` e confere venda por venda.

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
Kiwify API (public-api.kiwify.com) ◄── painel busca a cada 10 min (com a chave da aba API Kiwify)
                                            o que o webhook não entregou, Pix pago, reembolso

track.engdesk.pro  (login)  →  Conferência · Vendas · Visitantes · Eventos
```

**Resumo** (primeira tela): faturamento líquido, gasto, lucro, ROI, margem, CPA, imposto da Meta, ticket médio, pendentes e reembolsos; funil da Meta (cliques → visualizações → inícios de checkout → vendas) e funil do site (visitantes → cliques no checkout → vendas); faturamento, investimento e lucro por hora (acumulado); vendas por horário, pagamento (com taxa de aprovação), produto e fonte. Todo número tem o (i) com a conta.

**Orgânico**: o que vende sem anúncio. Vendas, faturamento e conversão orgânicos; canais (bio do Instagram, Instagram, Google, WhatsApp, IA, outros sites e, à parte, direto) com visitantes do painel e vendas da Kiwify lado a lado; links orgânicos que vendem; páginas de entrada; vendas orgânicas por dia. **Perfil do Instagram** (aba **API Instagram**): seguidores e saldo do período, alcance, visualizações, interações, contas engajadas e toques nos botões de contato (o Instagram não informa cliques no link da bio: o painel mede quem chegou por ele); o caminho do perfil até a venda (alcance → visitantes vindos do Instagram → cliques no checkout → vendas); alcance por dia; qual formato funciona melhor (reels, carrossel, foto); e o **feed** dos 50 posts mais recentes, com a capa e alcance, visualizações, curtidas, comentários, salvos, compartilhamentos, engajamento, tempo médio do reel e vendas pela bio nas 48 horas seguintes, em grade ou tabela, com ordenação e filtro por formato. As capas são baixadas do CDN do Instagram, reduzidas e guardadas na pasta de dados; `midia.php` só entrega para quem entrou no painel. Dois jeitos de conectar: o próprio token da **API Meta** com `instagram_basic`, `instagram_manage_insights`, `pages_show_list` e `pages_read_engagement` (não vence), ou um token do login do Instagram (`IGAA…`, com `instagram_business_basic` e `instagram_business_manage_insights`), que o painel renova sozinho a cada 7 dias. A chave secreta do app não é usada.

**Gestor de anúncios** (aba própria, no desenho do gestor da UTMify): contas, campanhas, conjuntos e anúncios da Meta com status, orçamento, gasto, vendas, faturamento líquido, lucro, CPA, ROI, custo por início de checkout e Pix pendentes. O gasto vem da Meta (aba **API Meta**, token só de leitura `ads_read`); as vendas se ligam ao anúncio pelo ID que a etiqueta carrega depois do `|`. Contas iguais às da UTMify: lucro desconta o gasto e o imposto de 12,15% que a Meta cobra sobre ele; ROI = (faturamento − imposto) ÷ gasto. Clicar na campanha abre os conjuntos dela, e o conjunto abre os anúncios; clicar no título da coluna ordena; em Ontem, 7 e 30 dias, setas comparam com o período anterior do mesmo tamanho. Ligar, pausar e mudar orçamento continuam no Gerenciador de Anúncios da Meta: o painel só lê.

**Visitante** é um navegador num aparelho, identificado pelo cookie `trk_vid`, que **o servidor** grava. Quando o painel é do mesmo site das páginas (`track.engdesk.pro` para `engdesk.pro`), o cookie vale para o domínio inteiro e o Safari não o apaga. Para um site diferente (ex.: `ortopaz.com.br` mandando para `track.engdesk.pro`), o `t.js` guarda o identificador no navegador, e o Safari pode apagar em 7 dias. Por isso o ideal é um painel por domínio: `track.<domínio>`.

**Conferência:** para cada venda aprovada, o painel pega o último `CliqueCheckout` daquele visitante (com as etiquetas do **link** que foi para a Kiwify) e compara com as etiquetas que a Kiwify gravou no pedido:

| Resultado | Significa |
|---|---|
| **Bate** | A página mandou e a Kiwify gravou a mesma origem e campanha |
| **Diferente** | A Kiwify gravou outra coisa. A venda mostra as duas |
| **Sem visitante** | O pedido chegou sem o `sck`: link direto da Kiwify, outro aparelho ou página sem o `t.js` |
| **Sem clique registrado** | O visitante foi reconhecido, mas o clique não chegou ao painel (rede caiu no clique) |

---

## Instalação

Este repositório é a **base** do painel. O jeito recomendado é copiar o painel para dentro do repositório do site que já existe e publicar junto com ele, pelo deploy que o site já tem. Não precisa de integração nova na hospedagem.

### Dentro de um site que já existe (recomendado)

Exemplo real: `admin.engdesk.pro/utm/`, dentro do admin do site, com a barra lateral **CMS | UTM**.

1. **Copiar**, com os dois repositórios lado a lado:
   ```bash
   bash scripts/copiar-para.sh ../engdesk/admin/utm
   ```
   Vão só os arquivos de execução, mais um `VERSAO` com o commit daqui. Configuração e dados nunca vão junto.
2. **Publicar:** commit no repositório do site e o deploy de sempre dele (na Hostinger, o mesmo Git que já publica o site).
3. **Instalar, em até 1 hora depois do deploy:** abrir `https://<site>/<pasta>/instalar.php` e definir o seu usuário e a senha, os sites que vão mandar eventos (ex.: `https://engdesk.pro`), a retenção em dias e, se o painel estiver dentro de um admin, o **Link do CMS** (ex.: `../`), que liga a barra lateral. A tela mostra **uma vez** a URL do webhook.
4. **Kiwify:** Apps → Webhooks → criar com a URL do passo 3 e os eventos *compra aprovada, compra reembolsada, chargeback, Pix gerado, compra recusada*, para os produtos das páginas.
5. **Páginas de venda:** no fim do `<body>`, **depois** do script de atribuição da página:
   ```html
   <script src="https://<site>/<pasta>/t.js" defer></script>
   ```
   A página precisa ter o script da UTMify com `data-utmify-prevent-xcod-sck`, para a UTMify não sobrescrever o `sck`.
6. **Conferir:** abrir a página de venda, clicar no botão de compra e ver o visitante e o clique em **Eventos**. Fazer uma compra de teste e ver a venda em **Vendas** com a conferência.
7. **API da Kiwify (recomendado):** na Kiwify, Apps → API → Criar API Key com **só "Vendas"** marcado. No painel, aba **API Kiwify**, colar `client_secret`, `client_id` e `account_id` direto (sem WhatsApp ou e-mail). O painel confere a chave na Kiwify antes de salvar. Com a chave, o painel busca as vendas pela API: na primeira vez, os últimos 89 dias; depois, a cada 10 minutos com o painel aberto, só o que mudou; uma vez por dia, os 89 dias de novo. A venda que o webhook não entregou aparece como **Só pela API** na Conferência. Botão **Atualizar vendas** nas abas Tráfego, Conferência e Vendas.

**Atualizar:** melhore o painel aqui, commite, rode o `copiar-para.sh` de novo e publique o site. Nunca edite a cópia: a próxima cópia apagaria a mudança.

### Num subdomínio próprio (site sem repositório)

1. hPanel → Domínios → Subdomínios → criar `track`. Anote a pasta dele (ex.: `public_html/track`).
2. hPanel → Avançado → Git → repositório `https://github.com/OZATI/track.git` (público, não precisa de chave), branch `main`, diretório = a pasta do subdomínio. Implantar.
3. Siga os passos 3 a 6 acima, com `https://track.<domínio>/`.

**Login do admin inteiro.** Dentro de um admin, o login do painel pode ser o login de todo o admin: a sessão vale para o site todo (cookie `track_sessao`, caminho `/`). Uma página do admin em PHP confere assim:

```php
require __DIR__ . '/utm/lib/util.php';
if (!logado()) {
    header('Location: utm/entrar.php?volta=' . rawurlencode($_SERVER['REQUEST_URI']));
    exit;
}
// usuario_atual() diz quem entrou; token_csrf() vai no cabeçalho X-CSRF das chamadas fetch
```

O `?volta=` só aceita caminho do próprio site. Quem entra e sai do painel se controla na aba **Usuários**: dar acesso, tirar acesso (o de outra pessoa) e trocar a própria senha.

**Onde ficam os dados:** `.../track-dados/` (config.php e track.sqlite), ao lado do `public_html`, fora de qualquer site e do deploy. Em outro servidor, defina a variável `TRACK_DADOS`.

**Passou 1 hora sem instalar?** O prazo conta da hora em que o `instalar.php` foi gravado no servidor (deploy que não muda o arquivo não reabre). Abra o `instalar.php` no Gerenciador de Arquivos da Hostinger e salve sem mudar nada, o que reabre por 1 hora, ou crie `track-dados/config.php` à mão:

```php
<?php return [
  'usuarios'      => ['kenio' => '...'],  // php -r "echo password_hash('SENHA', PASSWORD_DEFAULT);"
  'chave_webhook' => '...',               // php -r "echo bin2hex(random_bytes(24));"
  'origens'       => ['https://engdesk.pro'],
  'dias_retencao' => 90,
  'menu_cms'      => '',                  // ex.: '../' dentro de um admin (barra lateral CMS | UTM)
  'fuso'          => 'America/Sao_Paulo',
];
```

**Atualizar no subdomínio próprio:** novo commit na `main` e Implantar no hPanel. Dados e configuração não são tocados.

---

## Privacidade (LGPD)

| Guarda | Não guarda |
|---|---|
| Identificador aleatório do visitante (cookie `trk_vid`, 180 dias) | Nome, e-mail, telefone ou CPF do comprador (o webhook traz, o painel descarta) |
| IP **parcial** (IPv4 sem o último número; IPv6 só o prefixo /48) | IP completo |
| Aparelho, sistema e navegador | O user agent completo |
| Página, evento, etiquetas UTM e o site de onde a pessoa veio (sem os parâmetros) | Os parâmetros do endereço de origem |
| Pedido, produto, valor, status e etiquetas da venda (pelo webhook e pela API) | Dados de pagamento e do comprador que a API da Kiwify devolve |
| Números do perfil do Instagram (contagens por dia e por post, legenda dos posts da marca) | Quem seguiu, curtiu, comentou ou viu |

Tudo é apagado depois de `dias_retencao` (padrão 90). Cite o painel e o cookie na política de privacidade do site.

## Segurança

- Login por usuário e senha (`password_hash`), sessão `HttpOnly` + `SameSite=Strict`, token em todo formulário, 5 senhas erradas bloqueiam o IP por 15 minutos. Usuário inexistente e senha errada dão a mesma resposta, no mesmo tempo. Tirar o acesso de alguém derruba a sessão dele na hora.
- Coleta só aceita os sites da lista, confere que a página é do mesmo domínio que chamou, valida o nome do evento e limita 240 eventos por minuto por IP.
- Webhook só grava com a chave secreta da URL (a Kiwify não documenta assinatura dos avisos).
- Chave da API da Kiwify: só entra depois de conferida na Kiwify, e só se for de leitura (recusa chave com permissão de reembolsar, financeiro, afiliados, webhooks ou parcelado). Fica na configuração, fora da pasta pública; o `client_secret` nunca volta para a tela.
- Toda saída escapada (sem XSS), SQL só com parâmetros, CSP restrita, `noindex`, pastas `lib/`, `tests/`, `scripts/` e arquivos `.md/.sqlite` bloqueados no `.htaccess`.
- Instalação só abre enquanto não há configuração e por 1 hora depois do deploy.

## Testes

```bash
bash tests/fluxo.sh
# PHP fora do PATH (Windows, PHP portátil):
PHP=/caminho/php.exe PHP_FLAGS="-d extension_dir=ext -d extension=pdo_sqlite -d extension=mbstring" bash tests/fluxo.sh
```

Sobe um servidor local com dados temporários e confere instalação, coleta, webhook, LGPD, login, tabela de tráfego, barra lateral, conferência, filtros por site e página, XSS, limite de login e a tela da API da Kiwify (contra uma API falsa, `tests/kiwify-falsa.php`). Não toca em nenhum site real nem na Kiwify. Precisa das extensões `pdo_sqlite`, `mbstring` e `curl`.

## Contribuir

Issues e pull requests são bem-vindos. Antes de abrir um PR, rode `bash tests/fluxo.sh` e mantenha a regra do projeto: PHP e JavaScript puros, sem build e sem dependência. Falha de segurança: não abra issue pública, escreva para a OZATI pelo [ozati.co](https://ozati.co).

## Licença

MIT. Veja `LICENSE`.

## Limites

- Troca de navegador ou de aparelho gera dois visitantes: a venda chega como **Sem visitante** ou ligada ao segundo.
- IP não identifica pessoa (operadoras colocam muita gente no mesmo IP). Quem identifica o visitante é o cookie.
- Vendas anteriores à instalação não têm o `sck` e aparecem como **Sem visitante**.
