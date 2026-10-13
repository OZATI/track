# CSH: o caixa do checkout próprio

**Situação:** planejamento, 13/10/2026. Nada construído. Pedido do Kenio: um painel **CSH** (caixa), fora do DRE, para o checkout transparente próprio, com o Mercado Pago e a Wiven, e pronto para escala (clientes ligando as próprias contas pelo login de cada plataforma).

**Base deste documento** (não repete, só liga as peças):
- [`engdesk/docs/CHECKOUT_TRANSPARENTE.md`](../../engdesk/docs/CHECKOUT_TRANSPARENTE.md): o caminho da compra, a camada de provedores (`lib/pagamentos/`), o Mercado Pago pela API Orders, a Wiven de reserva, a CAPI, a LGPD e os testes. **Continua valendo inteiro.**
- [`CRM.md`](CRM.md): clientes, pedidos e reembolsos. As tabelas `pedidos` e `pagamentos` são as mesmas do CSH.
- [`PLATAFORMAS.md`](PLATAFORMAS.md): o conector (etapas I2 e I3) para Meta e Google. O Mercado Pago entra nele (seção 3).

---

## 1. O que é

**CSH** é um módulo próprio na barra lateral, com sigla como DRE e BIO, e acesso `csh`. Responde: **o dinheiro entrou, onde está e quando cai?** Mostra cada cobrança do checkout próprio, o provedor que cobrou, a taxa, o líquido, a data em que o dinheiro libera, a saúde de cada provedor e a configuração do checkout.

| | DRE | CSH | CRM |
|---|---|---|---|
| Pergunta | a empresa deu lucro? | o dinheiro entrou, onde está, quando cai? | quem é o cliente e o que fazer com ele? |
| Natureza | relatório do período | operação do dinheiro, cobrança a cobrança | atendimento, pessoa a pessoa |
| Dado pessoal | nenhum | **nenhum** (pedido e produto; o comprador fica no CRM) | todos (CRM.md, seção 4) |
| Ações | nenhuma | verificar pagamento, trocar provedor, testar conexão | reembolsar, cancelar, reenviar acesso (CRM.md, C3) |

**Ligações:**
- A venda aprovada continua indo para `vendas`, agora com a coluna `provedor`. O DRE e o Resumo passam a contar as vendas próprias sem mudar o código deles.
- A linha do CSH abre o pedido no CRM, quando ele existir. O reembolso continua no CRM (decisão C3), com o acesso `crm_reembolsar`. O CSH mostra o reembolso, não faz.
- A conexão de cada provedor fica em **Integrações → Vendas**, onde o Mercado Pago e a Wiven já aparecem como "em breve" (`lib/integracoes.php`).

## 2. Telas

Tela montável (o mesmo motor do Resumo e do DRE: `lib/grade.php`), no estilo do Resumo, com o (i) em cada número.

| Aba | O que mostra |
|---|---|
| **Caixa** (blocos) | Recebido no período (bruto aprovado), taxas dos provedores, líquido, **a liberar** (aprovado e ainda não disponível, com a data), Pix gerados e não pagos, taxa de aprovação por meio (Pix e cartão), recusas de cartão por motivo, reembolsos e chargebacks (valor, com link para o CRM), por provedor (rosca), **saúde dos provedores** (principal agora, última resposta, falhas na última hora, trocas automáticas) |
| **Cobranças** | Tabela inteligente: data, pedido, produto, meio, parcelas, provedor, bruto, taxa, líquido, situação, libera em. Ação: **verificar o pagamento** (consulta a API na hora) |
| **Provedores** | Principal e reserva, **Trocar agora**, ambiente (teste ou produção) de cada um, validade do Pix, parcelas, e o registro de falhas e trocas |

## 3. Conexões: o "login" de cada plataforma

A regra é a do `PLATAFORMAS.md`: entra-se no painel com usuário e senha; o login da plataforma só **liga a conta**, por um botão em Integrações. Colar a chave fica como modo avançado.

| Plataforma | Como conecta | O que a instalação guarda | Onde está |
|---|---|---|---|
| **Mercado Pago** | "Conectar com o Mercado Pago" (OAuth com PKCE, com a conta do Mercado Livre ou do Mercado Pago de quem vende) | token de acesso (180 dias), *refresh token* e a *public key* do vendedor | **novo**, entra no conector (abaixo) |
| **Wiven** | chave da API colada uma vez, em Integrações | a chave, fora da pasta pública | depende da documentação (seção 4) |
| **Meta** | "Continuar com o Facebook" (Login for Business) | token de sistema (não vence) | `PLATAFORMAS.md`, seção 3 |
| **Google** | "Continuar com o Google" | *refresh token* | `PLATAFORMAS.md`, seção 4 |

### Mercado Pago pelo conector

- **O app:** criado em "Suas integrações" do Mercado Pago, pela conta da EngDesk na etapa I2 e pela da OZATI na I3, com **PKCE ligado**. O *client secret* fica só no conector, nunca no código distribuído.
- **O caminho:**
  1. Integrações → **Conectar com o Mercado Pago** → conector `/mercadopago/inicio`;
  2. `auth.mercadopago.com/authorization` com `client_id`, `response_type=code`, `platform_id=mp`, `state`, `redirect_uri` e `code_challenge`;
  3. o retorno traz um `code` de uso único, que vale cerca de 10 minutos;
  4. o conector troca o `code` em `POST /oauth/token` (com o *client secret* e o `code_verifier`) e entrega à instalação um código assinado, como na Meta e no Google.
- **Renovação:** o token de acesso dura 180 dias, e o *refresh token* cerca de 6 meses. A renovação pede o *client secret*, então passa pelo conector, como no Google. O `cron.php` renova antes de vencer e avisa no celular se falhar.
- **O dinheiro cai na conta do cliente, nunca na da OZATI.** A cobrança sai com o token do vendedor. Assim a OZATI não fica no meio do dinheiro: não vira subcredenciadora (regra do Banco Central) nem responde pelo estorno do cliente. Se um dia a OZATI cobrar comissão por venda, o Mercado Pago tem o `marketplace_fee`. É decisão em aberto, com contador e jurídico.
- **Avisos (webhook):** confirmar na construção se, para quem conectou por OAuth, o aviso vai para a URL do app (de todos os vendedores, com o `user_id`) ou para uma URL por cobrança. Se for a do app, o conector repassa o aviso à instalação certa pelo `user_id`, sem guardar o conteúdo, e a instalação consulta a API antes de gravar (regra de sempre).
- **Mercado Livre não é Mercado Pago:** o login é a mesma conta, mas a API de pagamentos é a do Mercado Pago. Ligar a **loja** do Mercado Livre (vendas no marketplace) é outro produto e fica fora do CSH.

## 4. Wiven

Em 13/10/2026, a Wiven continua sem documentação pública de API (nada indexado). Sem ela:
- não há OAuth: a conexão é a chave colada, no padrão de chave das integrações (colada uma vez, fora do site, nunca no navegador);
- a regra "nunca ficar com um provedor só" (`CHECKOUT_TRANSPARENTE.md`, seção 5) não se cumpre em produção.

**Proposta:** pedir a documentação, as credenciais de teste e como os avisos são assinados. Se não vier até o fim da fase 1, a reserva passa a ser o **Asaas**, que já tem o contrato documentado neste ecossistema (`engdesk/docs/QA_AUDIT_ASAAS.md`) e API pública. A camada de provedores não muda: é um arquivo com as mesmas cinco funções.

## 5. Escala: análise do produto

### Como está hoje

- PHP 8.1+ e SQLite, sem build. **Uma instalação por site**, copiada para dentro do repositório dele (`engdesk/admin/utm`) e publicada na hospedagem compartilhada.
- Login por instalação, com usuário e senha.
- Contas de fora por **token colado** (Meta, Kiwify, Instagram). O conector ainda não existe.
- Bem feito para um site: chaves fora da pasta pública, CSP rígida, CSRF, LGPD por desenho, 736 testes contra serviços falsos.

### O caminho para clientes, com menos reescrita

1. **Uma instalação por cliente, num servidor só (VPS).**
   - O código já lê a pasta de dados de `TRACK_DADOS`. No VPS, cada cliente ganha um subdomínio (`<cliente>.painel.ozati.co`) e uma pasta de dados própria; o mesmo código atende todos.
   - **Isolamento por desenho:** um banco por cliente. Um erro ou vazamento não atravessa clientes, e apagar um cliente é apagar uma pasta (LGPD).
   - **Sem reescrever as consultas** com `cliente_id`, que é a parte cara e arriscada de virar multi-cliente.
   - **Uma atualização para todos:** o deploy é no VPS, não uma cópia por site. A cópia para dentro do site continua para quem quiser hospedar sozinho.
2. **Conector central** (`conectar.ozati.co`) para Meta, Google e Mercado Pago. Os segredos dos apps ficam só nele.
3. **Provisionamento:** um script que cria a pasta, a configuração e o primeiro usuário. Hoje é o `instalar.php`, à mão.
4. **Tarefas agendadas:** um cron no VPS que passa por todas as instalações. Hoje é um `cron.php` por site.
5. **Chaves cifradas:** no VPS, as chaves de cada instalação ficam cifradas com uma chave do servidor. Hoje ficam em texto no `config.php`, fora da pasta pública.
6. **Cópia de segurança por instalação**, fora do servidor.

### Gargalos que travam a escala (resolver antes de vender)

| Gargalo | Por quê | O que fazer |
|---|---|---|
| Revisões das plataformas | Meta Advanced e verificação do negócio; verificação OAuth do Google; acesso Standard da Google Ads API | `PLATAFORMAS.md`, seções 3.3 e 4.3; o Mercado Pago também avalia a qualidade da integração |
| Cota do Google Ads **dividida entre todos os clientes** | o acesso Explorer dá 2.880 operações por dia para o projeto inteiro | pedir o Standard antes do primeiro cliente |
| Avisos do Mercado Pago pelo app | podem chegar todos numa URL só | conector repassa pelo `user_id` (seção 3) |
| Wiven sem documentação | sem reserva, um bloqueio de conta para a venda | seção 4 |
| Kiwify por chave colada | a API dela usa a chave da conta (client_id e secret), sem botão de login para apps de fora | continua colada e só de leitura (o painel já recusa chave que reembolsa) |

## 6. Quem executa

**Agente `caixa`** (`~/.claude/agents/caixa.md`, do usuário, como o `seguranca` e o `rastreio-paginas`, para servir aos repositórios do track e dos sites). Escopo: camada de pagamentos, provedores, aviso (`pagamento.php`), CSH, cartão do Mercado Pago em Integrações e o conector do Mercado Pago. As telas do CRM são de outro terminal (CRM.md, seção 7). No fim de cada fase, ele chama o `seguranca`.

**Revisão do agente que já existe** (`ozati.co/.claude/agents/ozati-mercadopago.md`), que **não** serve para o track sem estes ajustes, por isso o `caixa` é outro arquivo:

| No `ozati-mercadopago` | Problema para o track | No `caixa` |
|---|---|---|
| Lê `PRODUCT.md` e guarda o token em `domains/ozati.co/private/` | caminhos do ozati.co | `track-dados/` e os docs deste repositório |
| "Orders ou Payments, escolha uma" | aqui já está decidido | API Orders (decidido em 06/10/2026) |
| Avisos dos tópicos `payment` e `chargebacks` | com a Orders, o tópico é **Order** (`order.processed` e as outras ações) | tópico Order, assinatura `x-signature` conferida |
| Um provedor só | aqui são dois, com reserva automática | camada com as cinco funções, reserva e "Trocar agora" |
| Estorno automático em 7 dias | aqui o reembolso é pelo CRM, com motivo e confirmação (C3) | o `caixa` entrega o `estornar()`; o botão é do CRM |
| Revisão pelo `ozati-qa` | não existe no track | `seguranca` + `bash tests/fluxo.sh` |
| Não fala de teste automatizado | o track testa tudo contra serviços falsos | `tests/pagamento-falso.php`, nunca conta real |

## 7. Fases

| Fase | Entrega | Pronto quando |
|---|---|---|
| 0 | App do Mercado Pago da EngDesk com credenciais e usuários de teste; resposta da Wiven; decisões da seção 8 | as chaves de teste respondem |
| 1 | Camada `lib/pagamentos/` com o Mercado Pago (Orders, Pix e cartão) e um provedor falso; migração com `vendas.provedor`, `pedidos` e `pagamentos` (combinada com o CRM); aviso em `pagamento.php` | compra de teste ponta a ponta em `127.0.0.1:3001` contra o falso e, depois, no ambiente de teste do Mercado Pago |
| 2 | Tela **CSH** (acesso `csh`, barra lateral, as três abas); cartão do Mercado Pago em Integrações com a chave colada (modo avançado) | o CSH mostra a compra de teste, com taxa, líquido e "libera em" |
| 3 | **Conectar com o Mercado Pago** pelo conector (etapa I2, dentro do painel), com a renovação no `cron.php` | conectar, desconectar e renovar, com o token nunca na tela |
| 4 | Reserva (Wiven ou Asaas), troca automática e manual, avisos de falha | com o principal "derrubado" no teste, a reserva cobra e o CSH avisa |
| 5 | Purchase pela CAPI; a página da EngDesk vira para o checkout novo (fases 3 e 4 do `CHECKOUT_TRANSPARENTE.md`) | uma semana sem venda perdida |
| 6 | Escala: VPS, conector central, provisionamento (seção 5) | um segundo cliente instalado por script, sem mexer no código |

## 8. Decisões em aberto

1. **Conta do Mercado Pago:** qual conta recebe (Allan ou EngDesk), e quem cria o app em "Suas integrações" com o login dela.
2. **Wiven:** prazo para a documentação; sem ela, o Asaas vira a reserva?
3. **Ordem:** CSH antes do CRM? Proposta: sim, porque sem pagamento próprio não há pedido para o CRM atender.
4. **Comissão da OZATI por venda** (`marketplace_fee`), para a etapa de clientes.
5. **VPS:** quando sair da hospedagem compartilhada, e qual provedor.
6. As da seção 11 do `CHECKOUT_TRANSPARENTE.md` (parcelas e juros, order bump, quem envia o acesso, nota fiscal, tempo da Kiwify de reserva).
