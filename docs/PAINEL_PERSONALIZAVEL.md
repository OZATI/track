# Estudo: painel de métricas personalizável, divisão por plataforma e Financeiro separado

**Situação:** estudo (07/10/2026), nada construído. Pedido de 06/10:
- um painel de métricas que cada pessoa monta como quer, no estilo do Power BI e do modo de edição da UTMify;
- a separação por plataforma (Meta, Google, TikTok e Taboola);
- novos gráficos, cada um com o seu caso de uso;
- um painel Financeiro só para o dinheiro da empresa, porque o UTM está ficando grande.

É a base do OZATI tracking como produto: o mesmo painel serve a qualquer site e a qualquer conjunto de plataformas.

---

## 1. O que muda para quem usa

- **Modo edição:**
  - o Resumo (ou uma aba nova, "Meu painel") ganha o botão **Editar**;
  - na edição, cada cartão e cada gráfico pode ser arrastado, aumentado, diminuído ou tirado;
  - uma **biblioteca** do lado mostra as métricas que faltam, por categoria, com busca;
  - **Salvar**, **Cancelar** e **Voltar ao padrão**.
- **Um layout por pessoa e por aparelho** (computador e celular): no celular, a ordem é outra e tudo fica em uma coluna.
- **Por plataforma:** Resumo geral (todas somadas), Meta, Google, TikTok e Taboola, cada uma com o seu gestor de anúncios e as suas métricas, como a UTMify faz.
- **Financeiro** sai do UTM e vira um painel próprio na barra lateral, para caixa, despesas, impostos e relatórios.

## 2. Registro de widgets (o catálogo)

Cada métrica ou gráfico vira uma entrada num **registro** em PHP. A tela, a biblioteca do modo edição e o (i) de cada cartão saem desse registro.

| Campo | Para quê | Exemplo |
|---|---|---|
| `id` | chave no layout salvo | `roi_geral` |
| `titulo` | o nome no cartão | ROI geral |
| `categoria` | onde fica na biblioteca | Geral, Gráficos, Impostos, WhatsApp, Recorrência |
| `tipo` | como desenha | número, rosca, barras, funil, linha acumulada, mapa de calor, lista com anel, velas |
| `formula` | o texto do (i) | (faturamento líquido − imposto da Meta) ÷ gasto |
| `tamanho` | mínimo e máximo na grade (colunas × linhas) | mín 2×1, máx 4×2 |
| `formato` | como mostra o valor | reais, número, %, razão (2,08) |
| `cor` | regra de cor | ROI: vermelho < 1, laranja 1 a 2, verde ≥ 2 |
| `plataformas` | onde faz sentido | todas, ou só Meta (hook rate) |
| `desenhar` | a função PHP que devolve o HTML | `widget_roi_geral($ctx)` |

**Catálogo inicial**, quase tudo já calculado hoje em `lib/resumo.php`, `lib/gestor*.php` e `lib/financeiro.php`:
- **Geral:**
  - faturamento líquido e bruto;
  - gasto;
  - ROI geral e rastreado;
  - ROAS;
  - lucro e margem;
  - CPA;
  - ticket médio (ARPU);
  - vendas pendentes, reembolsos e chargebacks;
  - leads (cliques no WhatsApp).
- **Gráficos:**
  - vendas por pagamento, por produto, por fonte e por posicionamento;
  - taxa de aprovação por forma de pagamento;
  - funil da Meta e funil do site;
  - vendas por dia da semana e por horário;
  - lucro por hora;
  - faturamento acumulado do dia;
  - velas do ROI.
- **Impostos:**
  - imposto da Meta;
  - taxas da Kiwify (ou do provedor de pagamento);
  - imposto sobre o faturamento (Simples ou outro, em %, na configuração);
  - lucro depois de tudo.
- **WhatsApp:**
  - cliques no WhatsApp por página e por origem;
  - vendas de quem clicou no WhatsApp antes.
- **Recorrência** (quando houver assinatura):
  - assinaturas ativas;
  - receita recorrente do mês;
  - cancelamentos.

## 3. Os gráficos novos e quando usar cada um

| Gráfico | Responde | Decisão que ajuda a tomar | Formato |
|---|---|---|---|
| Vendas por **dia da semana × horário** | quando as pessoas compram | concentrar orçamento nos melhores horários (programação de orçamento, que o painel já faz) | mapa de calor (7 linhas × 24 colunas), cor pela quantidade |
| **Faturamento acumulado do dia** contra ontem e a média de 7 dias | o dia está acima ou abaixo do normal, agora | mexer cedo no orçamento ou na campanha, sem esperar o fim do dia | linha acumulada, com as duas referências tracejadas |
| **Lucro por hora** | em que hora o anúncio dá prejuízo | cortar horário que só gasta | barras por hora, verde e vermelho |
| **Taxa de aprovação** por forma de pagamento | quanto do cartão é recusado | trocar o provedor, ligar a reserva, oferecer o Pix | barras empilhadas: aprovada, recusada e pendente |
| **Vendas por posicionamento** (Feed, Reels, Stories...) | onde o anúncio vende | tirar posicionamento que não vende | barras horizontais, ordenadas |
| **Vendas por produto e por fonte** | o que vende e de onde | foco de oferta e de canal | lista com anel (participação) |
| **Funil da Meta** (impressão → clique → página → checkout → venda) | em que etapa perde gente | criativo (clique), página (checkout) ou oferta (venda) | funil com a taxa entre as etapas |
| **Rosca** de pagamento e de fora de anúncio | a divisão de um total com poucas fatias | entender a mistura | rosca, até 5 fatias (com mais, use barras) |
| **Velas do ROI** | o ROI subiu ou caiu em cada dia | o momento de escalar ou pausar | velas, como já existe na análise diária |

Regras de desenho:
- número grande para o que se olha de relance;
- rosca só com poucas fatias;
- barras para comparar categorias;
- linha para tempo;
- funil para etapas;
- mapa de calor para dia × hora.

Todo gráfico tem o (i) com a conta e a decisão que ele ajuda a tomar.

## 4. Modo edição no nosso jeito de construir

O painel é PHP com JavaScript puro, sem build. A CSP só deixa rodar `.js` do próprio site (`script-src 'self'`).

**Desenho recomendado: o servidor desenha, o navegador só arruma.**
1. O layout salvo é uma lista: `[{widget, x, y, w, h, opcoes}]`, numa grade de 12 colunas.
2. A tela é desenhada pelo PHP, como hoje. Cada widget sai na posição dele (`grid-column` e `grid-row`), com os números calculados no servidor. Sem JavaScript, o painel continua aparecendo.
3. O modo edição (JavaScript) só move, redimensiona, tira e acrescenta. Ao salvar, manda o layout novo (POST com CSRF), e a tela é desenhada de novo pela navegação suave.
4. Widget novo, vindo da biblioteca: o servidor desenha. Sem endpoint de dados à parte, sem duplicar conta no JavaScript.

**Onde guardar:**
- tabela `paineis`, com `usuario`, `aparelho` (computador ou celular), `nome` e `layout` (JSON validado contra o registro), mais as datas;
- sem layout salvo, vale o padrão, que é o Resumo de hoje.

**Biblioteca de arrastar** (decisão em aberto, seção 8):
- **A. GridStack.js:**
  - licença MIT, sem jQuery desde a versão 2;
  - copiada para dentro do painel, porque a CSP não deixa carregar de CDN;
  - faz arrastar, redimensionar, colisão e celular prontos;
  - custa dezenas de KB e uma dependência no projeto, que hoje não tem nenhuma.
- **B. Grade própria:**
  - CSS Grid com arrastar e redimensionar por eventos de ponteiro;
  - umas 300 linhas no `painel.js` ou num `grade.js`;
  - mantém o "sem dependência", com menos recursos (sem empurrar os vizinhos automaticamente);
  - recomendada para a primeira versão.

## 5. Divisão por plataforma

Hoje o gasto é só da Meta (`lib/meta_sync.php` → `meta_gasto`). O desenho é o mesmo para as outras:

| Peça | Hoje (Meta) | Genérico |
|---|---|---|
| Gasto por dia e por objeto | `meta_gasto` | `anuncio_gasto` com a coluna `plataforma` (`meta`, `google`, `tiktok`, `taboola`) |
| Objetos (campanha, conjunto, anúncio) | `meta_objetos` | `anuncio_objetos` com `plataforma` |
| Busca na API | `meta_sync.php` | um arquivo por plataforma com as mesmas funções: buscar objetos, gasto por dia e status, e mudar status e orçamento quando a API deixar |
| Ligação venda → anúncio | ID depois do `|` na etiqueta | o mesmo, com a fonte dizendo a plataforma (`utm_source=MetaAds`, `GoogleAds`, `TikTokAds`, `Taboola`) |

**Etiquetas nos anúncios de cada plataforma** (a conferir na documentação de cada uma na hora de construir):
- **Google:** ValueTrack (`{campaignid}`, `{adgroupid}`, `{creative}`), com o auto-tagging (`gclid`) ligado.
- **TikTok:** macros como `__CAMPAIGN_ID__`, `__AID__` (grupo) e `__CID__` (anúncio).
- **Taboola:** macros como `{campaign_id}`, `{campaign_item_id}` e `{site}`.

**Esforço:**
- **Google Ads:** a API pede token de desenvolvedor aprovado e OAuth, e é a mais pesada.
- **TikTok e Taboola:** um token de app.

**Na tela:**
- a barra de abas ganha as plataformas ligadas;
- o Resumo geral soma todas;
- cada aba de plataforma tem o gestor dela (o mesmo componente do gestor da Meta, com as colunas que a plataforma tem);
- o filtro de plataforma vale no painel editável (cada widget pode ser "todas" ou uma plataforma).

## 6. Financeiro como módulo próprio

O Financeiro já calcula entradas, anúncios, despesas, saldo, ROI geral e o fluxo por dia, e já tem as despesas na tabela inteligente. Como módulo:
- **Lugar:** um painel na barra lateral (acesso `financeiro`), fora das abas do UTM.
- **Telas:**
  - caixa (o fluxo de hoje);
  - despesas (a tabela inteligente);
  - impostos e taxas (imposto da Meta, taxas do provedor, imposto sobre o faturamento);
  - relatórios do mês e do ano, com exportação em CSV.
- **Recorrência:** despesas fixas e, quando houver assinaturas, a receita recorrente.
- **Ligação:** com o módulo de Pagamentos (o provedor que cobrou e a taxa de cada um) quando ele existir.

## 7. Fases sugeridas

| Fase | Entrega | Pronto quando |
|---|---|---|
| 1 | Registro de widgets; o Resumo desenhado a partir do layout padrão (sem mudança visível) | o Resumo igual ao de hoje, vindo do registro |
| 2 | Modo edição: mover, redimensionar, tirar e acrescentar; salvar por pessoa e por aparelho; voltar ao padrão | cada pessoa com o seu layout, no computador e no celular |
| 3 | Gráficos novos: dia da semana × horário, acumulado do dia, lucro por hora, aprovação por pagamento, posicionamento | cada um com o (i) e a decisão que ajuda a tomar |
| 4 | Financeiro como módulo próprio | o Financeiro na barra lateral, com as despesas e os impostos |
| 5 | Plataformas: a camada genérica de anúncio, depois a primeira plataforma nova | o gasto dela no Resumo geral e o gestor dela na aba |

## 8. Decisões em aberto

1. O **Resumo vira o painel editável**, ou o editável é uma aba nova ("Meu painel") e o Resumo fica fixo?
2. **Layout por pessoa** ou um layout da empresa (e cada pessoa pode copiar)?
3. **GridStack (A)** ou a **grade própria (B)**?
4. **Qual plataforma primeiro** depois da Meta: Google, TikTok ou Taboola? Hoje há campanha rodando em qual?
5. **Imposto sobre o faturamento:** qual regime (Simples, lucro presumido) e qual percentual entra no lucro depois de tudo?
