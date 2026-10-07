# Track — painel de rastreio e conferência de vendas

Painel próprio que registra **cada evento de cada visitante** nas páginas de venda e liga a **venda da Kiwify** a quem a fez. Serve para saber, venda por venda, se a origem que a página mandou é a mesma que a Kiwify e a UTMify registraram.

Um painel atende vários sites: no topo, escolha o **site** e depois a **página**. A tela inicial é a tabela de **Tráfego**: por origem (source / medium / campaign), quantos visitantes, visualizações, cliques no checkout, cliques no WhatsApp, vendas aprovadas e conversão, e embaixo o mesmo por página.

- PHP 8.1+ e SQLite, sem build, sem framework, sem dependência. Roda na hospedagem compartilhada da Hostinger.
- Não manda nada para Meta, Google ou UTMify. Só guarda e mostra.
- Com login por usuário e senha, para quantas pessoas precisar (**Usuários**, na barra lateral), com bloqueio após 5 tentativas erradas. Cada usuário abre só o que for liberado: o CMS (quando o painel mora num admin com CMS), o UTM e a própria tela de Usuários.
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

**Orgânico**: o que vende sem anúncio. Vendas, faturamento e conversão orgânicos; canais (bio do Instagram, Instagram, Google, WhatsApp, IA, outros sites e, à parte, direto) com visitantes do painel e vendas da Kiwify lado a lado; links orgânicos que vendem; páginas de entrada; vendas orgânicas por dia. **Perfil do Instagram** (aba **API Instagram**): seguidores e saldo do período, alcance, visualizações, interações, contas engajadas e toques nos botões de contato (o Instagram não informa cliques no link da bio: o painel mede quem chegou por ele); o caminho do perfil até a venda (alcance → visitantes vindos do Instagram → cliques no checkout → vendas); alcance por dia; qual formato funciona melhor (reels, carrossel, foto); e o **feed** dos 50 posts mais recentes, com a capa e alcance, visualizações, curtidas, comentários, salvos, compartilhamentos, engajamento, tempo médio do reel e vendas pela bio nas 48 horas seguintes, em grade ou tabela, com ordenação e filtro por formato. **Público do Instagram**: sexo, idade por sexo, cidades e países dos seguidores, das contas alcançadas e das contas engajadas no mês, e a comparação entre quem segue e quem interage (em pontos percentuais). O Instagram entrega só o total de cada grupo, para perfis com 100 seguidores ou mais; o painel busca uma vez por dia. As capas são baixadas do CDN do Instagram, reduzidas e guardadas na pasta de dados; `midia.php` só entrega para quem entrou no painel. Dois jeitos de conectar: o próprio token da **API Meta** com `instagram_basic`, `instagram_manage_insights`, `pages_show_list` e `pages_read_engagement` (não vence), ou um token do login do Instagram (`IGAA…`, com `instagram_business_basic` e `instagram_business_manage_insights`), que o painel renova sozinho a cada 7 dias. A chave secreta do app não é usada.

**Gestor de anúncios** (aba própria, no desenho do gestor da UTMify): contas, campanhas, conjuntos e anúncios da Meta com status, orçamento, gasto, vendas, faturamento líquido, lucro, CPA, ROI, custo por início de checkout e Pix pendentes. O gasto vem da Meta (aba **API Meta**, token só de leitura `ads_read`); as vendas se ligam ao anúncio pelo ID que a etiqueta carrega depois do `|`. Contas iguais às da UTMify: lucro desconta o gasto e o imposto de 12,15% que a Meta cobra sobre ele; ROI = (faturamento − imposto) ÷ gasto. Clicar na campanha abre os conjuntos dela, e o conjunto abre os anúncios; clicar no título da coluna ordena; setas comparam com o período anterior do mesmo tamanho, a semana ou o mês passado (menos em Hoje e Tudo). ROI em cores, como na planilha de campanhas: vermelho abaixo de 1, laranja de 1 até 2, verde de 2 para cima. O ranking (painel de bolsa) fica embaixo da tabela. Colunas à parte para faturamento bruto e taxas da Kiwify. Com um token que também gerencia (`ads_management`), a chave de status liga e pausa na Meta, com confirmação e histórico.

**Orçamento pelo painel** (na análise diária): mudar o orçamento diário da campanha ou de um conjunto na Meta na hora (o **lápis** ao lado do orçamento, na linha do nome da campanha), ou programar (botão **Nova programação**, embaixo) (todo dia num horário, nos dias da semana marcados, ou uma vez numa data e hora). Precisa do token com `ads_management`. Travas: só campanhas e conjuntos da conta com orçamento diário, nunca acima do teto (Configurações, padrão R$ 300,00 por dia), confirmação na tela com a variação e aviso acima de 20% (a Meta pode reiniciar o aprendizado). A tarefa agendada (`cron.php`) aplica as programações: a diária até 30 minutos depois do horário (tarefa parada não muda fora de hora), a de uma vez até 2 horas depois. Cada mudança fica no histórico, com quem fez ou programou, e a programação avisa no celular.

**Análise diária** (o botão do gráfico que aparece ao passar o mouse na campanha, no conjunto ou no anúncio): o objeto dia a dia, logo embaixo do cabeçalho, uma linha por dia como na planilha, com o orçamento que o painel viu na Meta em cada dia (guardado a cada busca); hoje aparece por último, ao vivo. Clicar no título da coluna ordena (data do mais recente, número do maior, de novo inverte; fica lembrado neste navegador). O botão **Colunas** é o mesmo do gestor, com os modelos **Campanha** (as 17 colunas da planilha, o padrão), **Conjunto** e **Criativo**; a escolha fica guardada. Embaixo da tabela, os números da campanha no período (compradores, ROI, lucro, gasto, CPA, custo por IC), o gráfico de compradores e ROI por dia, a **leitura da campanha** (dicas com as regras de otimização: custo por IC até 10% do ticket, CTR de 2% para cima, ponto de contato antes de pausar, subir o orçamento até 20% por vez, melhores horários de venda), o **gráfico de bolsa do ROI desde o início** (uma vela por dia: abre no ROI acumulado até a véspera, fecha no do fim do dia; o ROI aparece ao passar o mouse) e **quem compra** por sexo e idade, com as compras que a Meta atribui à campanha (a Kiwify não pergunta o sexo).

**Análise do conjunto (público) e do anúncio (criativo):** a mesma tela, com o modelo de colunas do nível. No conjunto: **alcance** e **frequência** por dia (da Meta, por dia; na linha do período, o alcance do período inteiro, porque a mesma pessoa conta em vários dias), cartões de ROI, CPA, alcance, frequência, CPM e CTR, e a leitura do público (frequência acima de 3 cansa o público; CPM contra a média da conta). No anúncio: **hook rate** (visualizações de 3 s ÷ impressões), **hold rate** (ThruPlay ÷ 3 s) e a **retenção do vídeo** (quem chegou a 25, 50, 75 e 100% ÷ quem deu play), com a leitura do criativo (hook abaixo de 30%; clique que não vira checkout). As colunas do vídeo também existem no gestor; alcance e frequência só na análise diária. Os dados do vídeo e do alcance chegam na busca completa na Meta (uma vez por dia, 88 dias para trás).

**Filtros do topo**, que valem para todas as telas e ficam lembrados: período (hoje, ontem, 7 e 30 dias, este mês, mês passado, tudo ou de uma data a outra) e produto, com mais de um de uma vez (ex.: o Drive antigo e o novo, sem os order bumps).

**Seletores e calendário:** todo seletor do painel abre no mesmo padrão (no celular, como painel de baixo, com opções grandes para o dedo), com teclado (↑ ↓ Home End Enter Esc e busca digitando). O **Período** abre os períodos prontos e um calendário do mês para escolher o começo e o fim; datas do Financeiro e do Programar usam o mesmo calendário, e a hora do Programar vai de 15 em 15 minutos. Sem JavaScript, ficam os seletores e as datas do navegador.

**Filtros sem botão Aplicar e sem recarregar:** escolher já aplica. As listas de vários (Produto no topo, Fonte de tráfego no Resumo) têm **Todos** como a primeira caixa e aplicam ao fechar (clicar fora); o período aplica ao escolher um pronto ou, no calendário, ao clicar fora (Esc desiste). A tela troca no lugar, sem recarregar e sem voltar para o topo: filtros, abas, níveis e ordem do gestor, a chave de ligar e pausar, o orçamento, as despesas e o atualizar buscam a tela nova em segundo plano. O endereço, o voltar e o avançar continuam funcionando. Sem JavaScript, tudo funciona do jeito normal.

**Aparência** (paleta no topo e Configurações): claro, escuro (no tom da UTMify), pretão (#000000) ou qualquer outra cor de fundo; cartões, linhas e realces saem da cor escolhida e o texto fica claro ou escuro sozinho, para continuar legível. Cada usuário escolhe a sua.

**Financeiro**: o caixa da empresa no período, como as abas FLUXO e LUCRO da planilha. Entradas (vendas aprovadas, líquido da Kiwify), anúncios (gasto + imposto), outras despesas cadastradas ali (únicas, todo mês ou todo ano), saldo, ROI geral (entradas ÷ saídas) e o fluxo de caixa dia a dia.

**Núcleo do admin** (`lib/admin.php`, a lógica; `lib/admin_tela.php`, o visual): o que vale para o admin inteiro, não só para o UTM — login, **Usuários** (quem entra e o que cada um abre), **Minha conta** (`conta.php`: foto com o lápis na borda, trocar a senha, aparência e Sair), a barra lateral e o CSS comum, que o CMS do site também usa. As telas do núcleo têm o cabeçalho **Admin**, sem as abas do UTM. Num admin com as pastas `conta/` e `usuarios/` ao lado da pasta do painel, elas moram na raiz (ex.: `admin.engdesk.pro/conta/`): o `index.php` de cada pasta só define `TRACK_BASE` (o caminho até a pasta do painel, ex.: `'../utm/'`) e inclui a tela daqui (`require __DIR__ . '/../utm/conta.php';`). Sem essas pastas, ficam no próprio painel.

**Barra lateral do admin:** em cima os painéis (CMS e UTM, os liberados para o usuário) e embaixo a conta: Usuários, aparência, a engrenagem (Minha conta) e a foto. No celular, vira uma linha no alto, recolhida (a seta abre); os filtros ficam sempre à mostra.

A **foto do perfil** (Minha conta) aceita qualquer imagem, de qualquer tamanho: o navegador corta no centro, reduz para 512 × 512 e comprime em JPEG até 64 KB (lendo o arquivo com `createImageBitmap`, que a CSP do painel aceita), então funciona mesmo com o PHP sem a biblioteca de imagem. Fica na pasta de dados, fora do site, e só sai para quem entrou.

**Configurações do UTM** (a aba Configurações, no canto direito das abas do painel): **instalar o painel como app** no celular ou no computador (tela cheia, ícone próprio; no iPhone, por Compartilhar → Adicionar à Tela de Início) e as **notificações**. Venda aprovada e Pix/boleto gerado chegam na hora em que a venda entra (webhook ou busca na API), uma vez por situação, sem order bump; cada usuário escolhe mostrar ou esconder valor, produto, canal e campanha. Relatórios às 08h (resultado de ontem), 12h, 18h e 23h, no padrão **Status de lucro** ou **Resumo detalhado**. As notificações usam o Web Push dos navegadores (RFC 8291 e VAPID, sem serviço de terceiros): a chave privada fica na configuração, fora da pasta pública, e o painel só envia para os serviços de push do Google, Apple, Mozilla e Microsoft. Os relatórios e as buscas com o painel fechado dependem da **tarefa agendada** da hospedagem, a cada 5 minutos:

```bash
php /caminho/do/painel/cron.php
```

No hPanel: Avançado → Cron Jobs → Personalizado, `*/5 * * * *`. O `cron.php` só roda pela linha de comando (pelo navegador responde 404).

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

O `?volta=` só aceita caminho do próprio site. Quem entra e sai do admin se controla em **Usuários** (barra lateral): dar acesso, marcar o que cada um abre (CMS, UTM, Usuários; marcar já salva) e tirar acesso (o de outra pessoa). A própria senha se troca em **Minha conta**, com limite de 5 tentativas em 15 minutos. Toda senha (instalar, criar acesso, trocar) tem pelo menos 10 caracteres e não pode ser igual ao usuário. Ninguém tira o próprio acesso nem o próprio Usuários. Quem não tinha a lista (os de antes) abre tudo. O CMS do admin confere o mesmo acesso (`cms_exigir_acesso` no engdesk).

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
- Notificações: inscrição só com login e o token no cabeçalho `X-CSRF`, endereço só dos serviços de push dos navegadores (HTTPS), mensagem cifrada ponta a ponta para o aparelho; inscrição cancelada pelo navegador (404/410) é apagada. A notificação não leva nome nem e-mail do comprador.

## Testes

```bash
bash tests/fluxo.sh
# PHP fora do PATH (Windows, PHP portátil):
PHP=/caminho/php.exe PHP_FLAGS="-d extension_dir=ext -d extension=pdo_sqlite -d extension=mbstring -d extension=curl -d extension=openssl" bash tests/fluxo.sh
```

Sobe um servidor local com dados temporários e confere instalação, coleta, webhook, LGPD, login, tabela de tráfego, barra lateral, conferência, filtros por site e página, XSS, limite de login, a tela da API da Kiwify (contra uma API falsa, `tests/kiwify-falsa.php`), as da Meta e do Instagram (`tests/meta-falsa.php`, `tests/instagram-falsa.php`) e as notificações, contra um serviço de push falso que decifra cada mensagem (`tests/push-falso.php`). Não toca em nenhum site real, na Kiwify, na Meta nem nos serviços de push. Precisa das extensões `pdo_sqlite`, `mbstring`, `curl` e `openssl` (no PHP portátil do Windows, o teste acha o `extras/ssl/openssl.cnf` sozinho).

## Contribuir

Issues e pull requests são bem-vindos. Antes de abrir um PR, rode `bash tests/fluxo.sh` e mantenha a regra do projeto: PHP e JavaScript puros, sem build e sem dependência. Falha de segurança: não abra issue pública, escreva para a OZATI pelo [ozati.co](https://ozati.co).

## Licença

MIT. Veja `LICENSE`.

## Limites

- Troca de navegador ou de aparelho gera dois visitantes: a venda chega como **Sem visitante** ou ligada ao segundo.
- IP não identifica pessoa (operadoras colocam muita gente no mesmo IP). Quem identifica o visitante é o cookie.
- Vendas anteriores à instalação não têm o `sck` e aparecem como **Sem visitante**.
