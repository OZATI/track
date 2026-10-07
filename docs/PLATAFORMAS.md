# Plataformas: políticas, validação e conexão

O que a Meta e o Google exigem para o track conectar as contas de anúncio de quem usa o painel, e como o produto cumpre. É o "núcleo de políticas" que vai junto com o código para cada instalação nova. Levantado em 07/10/2026; confira na documentação oficial antes de cada pedido de revisão, porque as regras mudam.

## 1. Princípios

- **Conectar é dentro do painel já logado.** A entrada no painel continua por usuário e senha (núcleo). "Continuar com o Facebook" e "Continuar com o Google" só ligam a conta de anúncios, em **Integrações**.
- **Para leigos:** o caminho principal é um botão. Colar token fica como "modo avançado", para quem já tem um.
- **Os dados não passam pela OZATI.** O conector só faz o login e renova o acesso. Cada instalação guarda o próprio token e chama a Meta e o Google direto. Nenhum dado de anúncio, venda ou pessoa vai para o conector.
- **Só o necessário:** cada permissão pedida tem uma tela que a usa (seção 3.2 e 4.2). Permissão sem uso é motivo de recusa na revisão e risco à toa.

## 2. O conector

Para existir o botão de login, alguém registra um **app na Meta** e um **app no Google**. O segredo desses apps (*app secret*, *client secret*) **não pode ir no código distribuído**. Por isso o login passa por um conector:

```
painel do cliente (admin.site.com/utm)                conector (conectar.ozati.co)              Meta / Google
  Integrações → [Continuar com o Facebook] ──1──►  /meta/inicio?inst=<id>&volta=<url>  ──2──►  tela de login e permissões
                                                   /meta/retorno  ◄──3── code
  /conector-retorno.php ◄──4── código de uso único, assinado (HMAC com a chave da instalação)
  troca o código pelo token ──5──► /meta/token  (o conector entrega e esquece)
  guarda o token em track-dados/ e chama a Meta e o Google direto
```

- **Etapa I2 (só a EngDesk):** o conector roda dentro do próprio painel, com o app da EngDesk. O segredo fica em `track-dados/`, fora da pasta pública.
- **Etapa I3 (clientes):** o mesmo código publicado em `conectar.ozati.co`, com os apps da OZATI. Cada instalação escolhe "conector OZATI" na configuração.
- **Meta:** o token de sistema do Login for Business (*business integration system user*) não vence. A instalação guarda e usa direto.
- **Google:** o token de acesso dura 1 hora. A instalação guarda o *refresh token* e pede ao conector um token novo quando precisa. O conector junta o *client secret*, pede ao Google, devolve e não guarda nada.
- **Desconectar** (botão no cartão) apaga o token da instalação e os dados daquela plataforma, e revoga o acesso na plataforma.

## 3. Meta

### 3.1 O app

- Tipo **Business**, no Business Manager de quem distribui (EngDesk na etapa I2; **OZATI** na I3).
- Produto **Facebook Login for Business**, com uma **configuração**:
  - tipo de token **System-user access token** (não vence);
  - ativos: contas de anúncio, páginas e contas do Instagram;
  - as permissões da seção 3.2.
- Em Configurações básicas:
  - **URL da Política de Privacidade** (seção 5);
  - **URL dos Termos**;
  - ícone 1024×1024;
  - categoria;
  - **retorno de exclusão de dados** (*data deletion callback*, seção 3.4);
  - domínio do app.

### 3.2 Permissões e por quê

| Permissão | Tela que usa | Para quê |
|---|---|---|
| `ads_read` | Gestor, Resumo, análise diária | Ler gasto, impressões, cliques, conversões e público |
| `ads_management` | Gestor (ligar, pausar, orçamento, programação) | Mudar status e orçamento, sempre com confirmação e histórico (`meta_alteracoes`) |
| `business_management` | Integrações | Listar as contas de anúncio do portfólio para a pessoa escolher |
| `pages_show_list`, `pages_read_engagement` | Orgânico | Achar a conta do Instagram ligada à página (dependência das de anúncio) |
| `instagram_basic`, `instagram_manage_insights` | Orgânico | Seguidores, alcance e os números de cada post |

A **API de Conversões** (CAPI, compra enviada pelo servidor) usa o token do pixel, que fica no cartão da Meta. Ela não pede permissão de login.

### 3.3 Acesso e revisão

- **Acesso Standard:** funciona só para quem tem papel no app (administrador, desenvolvedor, testador) e para os ativos do próprio negócio. Isso basta para a EngDesk: o Allan entra como testador.
- **Acesso Advanced** (outras empresas): **App Review** de cada permissão. A Meta pede:
  - um vídeo do login completo;
  - a escolha da conta;
  - os números aparecendo (gasto, impressões, cliques, conversões, alcance);
  - para `ads_management`, a edição com confirmação.
- **Verificação do negócio** (CNPJ e documentos da empresa) antes do Advanced.
- **Marketing API:** a conta do app começa no nível de desenvolvimento, com limite baixo. O "Ads Management Standard Access" pede histórico de chamadas sem erro (no levantamento: 1.500 chamadas em 15 dias, com menos de 15% de erro). Confira a regra na hora do pedido.
- **Verificação anual do uso de dados** (*Data Use Checkup*): responder todo ano, senão o app perde as permissões.

### 3.4 Exclusão de dados

A Meta exige, para app com login, uma **URL de retorno de exclusão** ou uma página de instruções:
- ela manda um POST com um `signed_request` (HMAC-SHA256 com o *app secret*) e o `user_id`;
- o conector responde com um JSON `{ "url": "<página para acompanhar>", "confirmation_code": "<código>" }`.

Como o conector não guarda dados, a página de acompanhamento explica que os dados ficam no painel de cada empresa e que o botão **Desconectar** apaga tudo daquela plataforma.

## 4. Google

### 4.1 O projeto

- Projeto no Google Cloud de quem distribui. **Não use o projeto do Login com Google do site**: o escopo do Google Ads é sensível e mudaria a tela de consentimento dos clientes do site.
- APIs ativadas: **Google Ads API** e **Data Manager API** (compras enviadas pelo servidor).
- **Tela de consentimento:**
  - Externa, publicada ("Em produção");
  - página inicial, Política de Privacidade e Termos num **domínio verificado** (Search Console);
  - logo;
  - e-mail de suporte.
- **Cliente OAuth do tipo Web**, com as URLs de retorno do conector.

### 4.2 Escopos e por quê

| Escopo | Tela que usa | Para quê |
|---|---|---|
| `https://www.googleapis.com/auth/adwords` (**sensível**) | Gestor do Google, Resumo | Ler campanhas, gasto, cliques e conversões; ligar, pausar e mudar orçamento com confirmação |
| `https://www.googleapis.com/auth/datamanager` | Vendas (servidor) | Enviar a compra com o `gclid`, o valor e o número do pedido |

### 4.3 Verificação e acesso

- **Antes da verificação:**
  - aparece a tela "o Google não verificou este app";
  - o limite é de **100 usuários**.

  Para a EngDesk na etapa I2 isso serve: entra pelo "Avançado".
- **Verificação OAuth** (gratuita, de 3 a 5 dias úteis para escopo sensível). O Google pede:
  - a justificativa de cada escopo;
  - um **vídeo no YouTube** com o login, a tela de consentimento em inglês e o uso de cada escopo no painel;
  - a Política de Privacidade com o aviso de Uso Limitado (seção 5).
- **Acesso à Google Ads API, por projeto e dividido entre todos os clientes:**
  - Test: só contas de teste;
  - **Explorer**: 2.880 operações por dia em contas reais, quase sempre aprovado sozinho;
  - Basic: 15.000 por dia, depois da verificação da marca;
  - **Standard**: ilimitado, com revisão manual de uns 10 dias úteis.

  Com clientes, peça o Standard.
- **Mudanças de 2026:**
  - o token de desenvolvedor acabou em 09/09/2026, e o acesso se pede no Cloud Console;
  - desde 15/06/2026, integração nova envia conversão pela Data Manager API, e não mais pelo `UploadClickConversions`.

### 4.4 Uso Limitado

A [Política de Dados do Usuário dos Serviços de API do Google](https://developers.google.com/terms/api-services-user-data-policy) proíbe:
- vender os dados;
- usar os dados para publicidade fora do que o usuário pediu;
- repassar os dados a terceiros, salvo o necessário para a função ou exigência legal;
- deixar pessoas lerem os dados sem consentimento, salvo segurança ou lei;
- usar os dados para treinar modelos gerais de IA.

O painel usa os dados do Google Ads só para os relatórios e o gestor da própria empresa.

## 5. Textos obrigatórios

**Aviso de Uso Limitado** (vai na Política de Privacidade e na página inicial do app):

> EN: *"&lt;App&gt;'s use and transfer to any other app of information received from Google APIs will adhere to the [Google API Services User Data Policy](https://developers.google.com/terms/api-services-user-data-policy), including the Limited Use requirements."*
>
> PT: *"A utilização e a transferência de informações recebidas das APIs do Google para qualquer outra aplicação obedecerão à Política de dados do utilizador dos serviços de API do Google, incluindo os requisitos de utilização limitada."*

**Política de Privacidade:** use o modelo em [`modelos/privacidade.html`](../modelos/privacidade.html). Troque os campos entre chaves e tire as seções do que o site não usa. Exemplo publicado: `engdesk.pro/privacidade/`.

## 6. O que o código já cumpre

| Exigência | Onde |
|---|---|
| Chaves fora da pasta pública | `track-dados/config.php` (`lib/config.php`), bloqueada no `.htaccess` |
| Só o necessário de cada pessoa | Vendas sem nome, e-mail, telefone ou CPF (`kiwify.php`); IP parcial nos visitantes |
| Proteção contra bloqueio das APIs | Limite por minuto e pausa automática (`lib/meta_api.php`, `lib/kiwify_api.php`) |
| Edição só com confirmação e histórico | `lib/gestor_editar.php`, `meta_alteracoes` |
| Desconectar | Remover token em cada tela de Integrações (a revogação na plataforma vem com o conector) |
| Retenção | `dias_retencao` na instalação (padrão 90 dias) |

## 7. Pendências

1. Conector (etapa I2): `lib/conector*.php` com o fluxo da seção 2, o retorno de exclusão da Meta e a renovação do Google.
2. Botões "Continuar com o Facebook" e "Continuar com o Google" nos cartões de Integrações, com o token colado indo para "modo avançado".
3. Política de Privacidade e Termos da OZATI em `ozati.co`, para os apps da etapa I3.
4. Verificação do negócio da OZATI na Meta e vídeos de revisão das duas plataformas.
