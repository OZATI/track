# CRM: clientes, pedidos e reembolsos

**Situação:** planejamento, 07/10/2026. Nada construído.

O painel deixa de ser só o rastreio de um site e vira o **instrumento de gestão** da venda. O CRM é o módulo de quem atende: cada cliente, cada pedido e o que fazer com ele (verificar o pagamento, cancelar, reembolsar e reenviar o acesso). O primeiro uso é o **marketplace de projetos** da EngDesk, os produtos do CMS: venda mais cara, para pessoa física e para empresa (B2C e B2B), com atendimento personalizado, pelo checkout transparente próprio (`engdesk/docs/CHECKOUT_TRANSPARENTE.md`).

## 1. Decisões (Kenio, 07/10/2026)

| # | Decisão |
|---|---|
| C1 | **Módulo próprio, CRM** (acesso `crm`), ao lado de CMS, UTM, DRE, BIO e INTEGRAÇÕES. **Não** fica dentro do DRE. |
| C2 | **Guardar todos os dados do cliente** que a venda e o atendimento usam: nome, e-mail, telefone, CPF ou CNPJ, empresa e endereço (o da nota fiscal). Mais a **jornada completa**: as UTMs e os eventos de cada visita, até a compra. Isso substitui a seção 7 do `CHECKOUT_TRANSPARENTE.md`, que guardava só nome, e-mail e telefone. |
| C3 | **Reembolso e cancelamento pelo painel**, no checkout próprio: Mercado Pago agora e Wiven depois, pela camada `lib/pagamentos/`. |

**Continua proibido:** dado de cartão (número, validade, código). O formulário seguro do provedor devolve um token, e o número nunca passa pelo nosso servidor. É exigência do PCI DSS, não opção.

## 2. Por que não fica dentro do DRE

| | DRE | CRM |
|---|---|---|
| Natureza | números somados do período (relatório) | pessoa a pessoa, pedido a pedido (operação) |
| Quem usa | dono, sócio, contador | atendimento, vendas |
| Ações | nenhuma (só lê) | reembolsar, cancelar e reenviar, que **não têm volta** |
| Dado pessoal | nenhum | todos (seção 4) |

**Ligação entre os dois:** a linha "(−) Reembolsos e chargebacks" do DRE abre o CRM já filtrado nesses pedidos, e o pedido no CRM mostra o efeito no resultado.

## 3. Telas

| Aba | O que mostra | Ações |
|---|---|---|
| **Clientes** | Tabela inteligente: nome, tipo (PF ou PJ), e-mail e telefone mascarados, nº de pedidos, total pago, último pedido, origem da primeira visita | abrir o cliente |
| **Cliente (detalhe)** | Dados completos (cada abertura fica registrada), pedidos, **jornada** (linha do tempo com cada visita, UTM, página, clique e o pagamento) e notas do atendimento | editar dados, nota, WhatsApp (abre na hora, nunca em massa), exportar os dados (LGPD), apagar ou anonimizar |
| **Pedidos** | Todos os pedidos: itens (os projetos do CMS), valor, provedor, situação (pendente, aprovado, recusado, expirado, reembolsado, chargeback), datas | **verificar o pagamento** (consulta a API na hora), reenviar o Pix ou o link, **cancelar** o pendente, **reembolsar** (total ou parcial, com o motivo), reenviar o acesso |
| **Reembolsos** | Pedidos de reembolso e o histórico: prazo da garantia (7 dias), motivo, quem fez, valor e situação no provedor | aprovar ou recusar o pedido de reembolso |
| **Registro** | Tudo o que foi feito: quem, o quê, quando e o resultado no provedor | só leitura |

## 4. Dados

**Tabelas novas** (banco do painel, fora da pasta pública):
- **`clientes`**:
  - `id`, `tipo` (pf ou pj), `nome`, `email`, `telefone`, `documento` (CPF ou CNPJ, **cifrado** com chave da configuração), `empresa`, `endereco` (JSON da nota);
  - `consentimento` (texto e data do aviso), `primeira_origem` (UTMs da primeira visita), `criado_em`, `apagar_em`.
- **`cliente_visitantes`**: cliente ↔ visitante do `t.js` (um cliente pode ter vários aparelhos). É o que monta a jornada a partir de `eventos`.
- **`pedidos`** e **`pedido_itens`**: o pedido do checkout próprio, com os projetos, o preço vindo do servidor, o provedor, a situação e a chave `<provedor>:<id>`.
- **`pagamentos`**: cada movimento no provedor (cobrança, aprovação, recusa, reembolso, chargeback), com o valor e a data.
- **`crm_registro`**: cada ação e cada abertura de dado completo (usuário, ação, alvo, resultado).
- **`crm_notas`**: notas do atendimento.

**Na tela:**
- Nas listas, e-mail, telefone e documento aparecem mascarados.
- Completos, só no detalhe, e cada abertura vai para o registro.
- Nada pessoal entra em URL, log, evento do Pixel ou `custom_data`. Para a Meta (CAPI), só e-mail e telefone em hash SHA-256.

## 5. Ações: regras

- **Verificar o pagamento:** `consultar($id)` da camada de pagamentos. A situação sempre vem da API, nunca da tela.
- **Cancelar** um Pix, boleto ou pedido pendente: `cancelar($id)` (função nova na camada).
- **Reembolsar:**
  - `estornar($id, $valor)`, com motivo obrigatório e confirmação com o valor;
  - acesso próprio `crm_reembolsar`;
  - limite de valor por dia, em Configurações;
  - registro de tudo.

  Reembolso aprovado: **tira o acesso** ao projeto no portal (Supabase, `permissoes_usuario`) e entra na linha de reembolsos do DRE.
- **Chargeback:** chega pelo webhook. Marca o pedido, tira o acesso e avisa quem tem `crm`.
- **Reenviar o acesso:** reenvia o e-mail ou o link do portal. Não mexe no pagamento.
- **Kiwify** (Drive de Projetos, enquanto estiver lá): o botão "Reembolsar na Kiwify" abre o pedido no painel dela. O painel continua recusando chave da Kiwify com permissão de reembolso (`KIWIFY_ESCOPOS_PROIBIDOS`).

## 6. LGPD

- **Base legal:** a execução da compra e o atendimento; a obrigação legal para os dados da nota fiscal.
- **Aviso no checkout** e Política de Privacidade atualizada, dizendo que guardamos para atender, emitir nota e reembolsar. A seção 2 da `engdesk/privacidade/` ganha os dados novos.
- **Retenção:**
  - cliente com compra paga: 5 anos (prazo fiscal);
  - pedido não pago: 30 dias;
  - a tarefa agendada apaga o que venceu.
- **Direitos:** exportar os dados do cliente (JSON) e apagar a pedido. Com nota emitida, **anonimiza**: o pedido e os valores ficam, e nome, contato e documento saem.
- **Quem vê:** só quem tem `crm`. Reembolsar pede `crm_reembolsar`.

## 7. Divisão do trabalho (dois terminais no mesmo repositório)

| Parte | Quem | Arquivos |
|---|---|---|
| Camada de pagamentos: Mercado Pago (Orders), webhook, `consultar`, `cancelar`, `estornar`, CAPI | terminal 1 | `lib/pagamentos*.php`, `pagamento.php` |
| CRM: tabelas, telas, jornada, ações (chamando a camada), LGPD, registro | terminal 2 | `crm.php`, `lib/crm*.php`, migração nova |
| DRE em 3 abas (DRE, Fluxo de caixa, Despesas), com as linhas que faltam | terminal 1 | `financeiro.php`, `lib/financeiro.php` |
| Contrato entre os dois | os dois | as funções da seção 5 e as tabelas `pedidos` e `pagamentos` (quem cria a migração combina antes) |

## 8. Fases

| Fase | Entrega | Pronto quando |
|---|---|---|
| 1 | Tabelas e telas do CRM (Clientes, Pedidos, detalhe com a jornada), alimentadas por pedidos de teste e pelo provedor simulado | um pedido de teste aparece com o cliente e a jornada inteira |
| 2 | Ações com o Mercado Pago (verificar, cancelar, reembolsar), o registro e o acesso no Supabase | reembolso de teste ponta a ponta, com o acesso retirado e a linha no DRE |
| 3 | Reembolsos (pedidos, garantia), notas, exportar e apagar (LGPD) | um pedido de exclusão anonimiza sem perder o fiscal |
| 4 | Wiven na mesma camada | reembolso pela Wiven igual ao do Mercado Pago |

## 9. Em aberto

1. **Kiwify agora:** guardar já os clientes das vendas da Kiwify (o webhook traz nome, e-mail, telefone e CPF), para o CRM nascer com dados reais? Hoje o painel descarta esses dados de propósito.
2. **Nota fiscal:** qual sistema emite? Define o endereço e os dados de PJ que o checkout pede.
3. **Reembolso:** limite de valor por dia e quem pode reembolsar.
4. **Venda B2B:** proposta ou orçamento antes do pagamento (link de pagamento enviado pelo atendimento), ou só o checkout?
