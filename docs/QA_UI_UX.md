# QA de interface e experiência do painel (07/10/2026)

Revisão das 21 telas do painel (UTM, Integrações, Financeiro, Bio, Minha conta e Usuários), no computador (1280 px) e no celular (390 px), nos temas claro e escuro. Código: `OZATI/track` em `5872d8e`, com o painel editável, os gráficos novos e Integrações.

## Como foi feito

- **Ambiente local** com dados de exemplo realistas: 30 dias, 520 visitantes, 663 eventos, 66 pedidos (Pix, cartão, boleto, reembolso, Pix pendente, order bump), 3 campanhas da Meta com gasto por dia e 4 despesas. Tudo entrou pelos caminhos reais (`coletar.php` e o webhook da Kiwify), e depois as datas foram espalhadas.
- **Robô de QA** (Chrome sem interface, tudo fora do 127.0.0.1 bloqueado). Em cada tela ele procura:
  - erros de JavaScript e de rede;
  - rolagem lateral e elementos fora da tela;
  - alvos de toque menores que 32 px;
  - controles sem nome acessível;
  - contraste abaixo do WCAG AA;
  - texto menor que 12 px e texto cortado.

  Ele também tira um print da página inteira.
- **Revisão visual** dos prints, tela a tela.

**O que está bom:**
- nenhum erro de JavaScript;
- nenhuma rolagem lateral da página em nenhuma tela;
- o tema escuro é consistente;
- as vendas no celular viram cartões;
- os gráficos têm `role="img"` com descrição.

**Alarmes falsos do robô, descartados:**
- "fora da tela" no menu Mais do celular e no painel de Colunas;
- "sem rótulo" nos botões de tema e nos links do menu Mais.

Os dois são conteúdo de `<details>` fechado.

## Severidade

- **Alta:** esconde ou distorce a informação, ou deixa a pessoa sem saber o que está vendo.
- **Média:** atrapalha o uso ou a leitura, mas dá para contornar.
- **Baixa:** acabamento.

## Achados

### Alta

| # | Tela | Problema | Sugestão |
|---|---|---|---|
| UX-01 | Resumo e Financeiro, no celular | **Os títulos dos cartões de número saem cortados:** "Faturament…", "Gasto com…", "ROI rastrea…", "Imposto da…", "Vendas pen…", "Vendas ree…", "Taxas da Ki…" e "Outras des…". Quem lê não sabe qual número está vendo, e há dois "Faturament…" (líquido e bruto). | Título em até 2 linhas (`line-clamp: 2`) no lugar das reticências, ou nomes curtos no celular ("Fat. líquido"). |
| UX-02 | Gestor e Análise diária, no celular | **A tabela mostra só Status, Campanha e Orçamento** (na análise, Data, Orçamento e Gastos). Lucro, ROI, CPA e vendas ficam à direita, sem sinal de que existe mais. A coluna Orçamento da análise é quase toda "—" e ocupa espaço. | No celular, um cartão por campanha com os 4 números principais (gasto, vendas, lucro, ROI) e "ver tudo". Ou a primeira coluna fixa, com sombra na borda que rola. |
| UX-03 | Resumo, Financeiro e total do Gestor | **"▲ de 0" quando o período anterior não tem dados.** A seta verde sugere melhora, e o texto é enigmático. No Gestor, "novo" aparece embaixo de cada célula da tabela e do ranking. | Sem dado anterior, não mostrar a comparação. Se precisar, um aviso só no topo: "sem dados no período anterior para comparar". |
| UX-04 | Tráfego (por origem), Conferência (detalhe) e Vendas (conferência) | **IDs crus na tela:** "MetaAds / Visitou 7d\|120200000000201 / TL 2 \| Drive \| Remarketing 7d\|120200000000002". É difícil de ler e quebra linha no celular. | Mostrar só os nomes ("TL 2 · Visitou 7d"). O ID fica no hover ou no "copiar". |

### Média

| # | Tela | Problema | Sugestão |
|---|---|---|---|
| UX-05 | Resumo e Financeiro (computador e celular) | **Cartões com 40 a 60% de espaço em branco:** Taxa de aprovação, Vendas por produto, Vendas por canal, Despesas por categoria, os funis, Vendas por dia da semana e Quem compra. A altura da grade é fixa, e no celular cartões de cerca de 300 px mostram 3 linhas. | No celular, altura pelo conteúdo. No layout padrão do computador, alturas menores para as listas curtas. |
| UX-06 | Usuários, no celular | **Sete cartões "Podem abrir X: 1 de 1"** ocupam cerca de 900 px antes da lista e do formulário, que são o que a pessoa veio fazer. Bio, Financeiro e Integrações usam o mesmo ícone de "✓". | Um cartão só ("1 usuário · acesso a tudo") ou nenhum. Lista e formulário no alto. |
| UX-07 | Gestor e Análise diária | **Parágrafo longo de ajuda embaixo da tabela.** É uma parede de texto, e no celular são 10 linhas. | Levar para o (i) do título ou para um "Como ler esta tabela" recolhido. |
| UX-08 | Gráficos no celular (Análise diária, Resumo, Financeiro) | **Rótulos dos eixos ilegíveis** (cerca de 6 a 8 px) em Compradores e ROI por dia, ROI desde o início e Vendas por dia da semana. **As datas do fim do eixo se sobrepõem** ("02/1006/10", "06/107/10") em Faturamento × investimento, Saldo por dia e ROI desde o início. | Menos rótulos quando a largura é pequena, e não desenhar o último se ele encostar no anterior. |
| UX-09 | Todas, no celular | **Alvos de toque pequenos:**<br>- ícones (i) de 15×15 (dezenas por tela);<br>- paginação ‹ › de cerca de 24 px;<br>- pílulas de período do Financeiro e da Bio com 28 px de altura;<br>- chave de status do gestor de 34×20;<br>- caixas de seleção de 13×13 em Usuários;<br>- "Mostrar a URL do webhook" com 20 px.<br><br>O WCAG 2.2 AA pede pelo menos 24×24, e o ideal no celular é 44×44. | Área de toque maior com espaço invisível em volta (`padding` ou `::before`), sem mudar o desenho. |
| UX-10 | Todas | **Contraste um pouco abaixo do AA (4,5:1):**<br>- texto suave (cabeçalhos de tabela, legendas, selo neutro) com 4,39:1;<br>- links com 4,29:1 no claro e 4,32:1 no escuro;<br>- select desabilitado ("Todas as páginas") com 2,31:1 no claro e 3,29:1 no escuro;<br>- **botões azuis com texto branco no escuro com 3,68:1**. | Ajustar os tokens `--suave` e `--marca` (e o azul do botão no escuro) resolve quase tudo de uma vez. |
| UX-11 | Todas | **Texto menor que 12 px:**<br>- rótulos da barra lateral com 10,5 px;<br>- barra de baixo do celular com 11 px;<br>- setas de comparação com 11,5 px. | Mínimo de 12 px. Na barra lateral, 11 a 12 px com o ícone um pouco menor. |
| UX-12 | Tráfego | **A "%" muda de sentido entre cartões vizinhos:**<br>- em Checkout por canal, é a taxa dentro do canal;<br>- em Vendas por canal, é a conversão;<br>- em Faturamento por canal, é a parte do total.<br><br>Só o (i) explica. | Dizer na própria linha: "21,6% dos visitantes" e "36,1% do total". |
| UX-13 | Resumo, Vendas e Usuários, no celular | **Cartões de número com padrões diferentes:** o Resumo usa 2 colunas (e corta), Vendas e Usuários usam 1 coluna com cartões altos, que empurram a lista para baixo. | Um padrão só: 2 colunas compactas, com o título em até 2 linhas. |
| UX-14 | Gestor, no computador | **A tabela passa da largura** (IC e as colunas seguintes cortadas à direita), sem sombra nem sinal de rolagem. A barra de ferramentas só tem ícones (Colunas, ordenar, gráfico, expandir, tela cheia), e o **"Colunas" fica sem nome acessível** (`.gestor-ferr .colunas summary span{display:none}`). | Sombra na borda que rola. `aria-label` e `title` nos ícones, e trocar o `display:none` por texto só para leitor de tela. |
| UX-15 | Gestor, no celular | **As abas Contas, Campanhas, Conjuntos e Anúncios saem cortadas** (Anúncios some), sem sinal de rolagem. | Ícone com texto curto, ou um select no celular. |

### Baixa

| # | Tela | Problema | Sugestão |
|---|---|---|---|
| UX-16 | Tráfego, Conferência e Vendas | Espaço antes do ponto em "cadastre a chave em Integrações → Kiwify ." No celular, o link quebra a linha sozinho. | Tirar o espaço, ou pôr o ponto dentro da mesma linha do link. |
| UX-17 | Resumo (Vendas por pagamento) | A legenda mostra "Outros 0 0%". | Esconder a categoria zerada. |
| UX-18 | Análise diária e Gestor | "Gastos" numa tela e "Gasto" na outra, para a mesma métrica. | Um nome só. |
| UX-19 | Tráfego, Conferência, Vendas, Visitantes e Eventos | O filtro "Página" fica desabilitado sem dizer por quê (só no (i)). | Texto "Escolha um site" no próprio campo. |
| UX-20 | Gestor (Ranking) | Com todas as campanhas no prejuízo, "1º" fica para a que perdeu menos. "Mov." e "Variação" repetem "novo". | Destacar quando todas estão no prejuízo. Com UX-03, o "novo" sai. |
| UX-21 | Todas | Aviso no console: `apple-mobile-web-app-capable` está obsoleto. | Acrescentar `<meta name="mobile-web-app-capable" content="yes">`. |
| UX-22 | Painel avulso (sem admin) | `/favicon.ico` dá 404; as telas não têm `<link rel="icon">`. No admin da EngDesk não acontece (o admin tem favicon). | `<link rel="icon">` apontando para o `app-192.png` do painel. |
| UX-23 | Financeiro e Bio | O período usa pílulas, que no celular quebram linha ("Tudo" sozinho embaixo), e o UTM usa o seletor do topo: são dois padrões de período. | Usar o mesmo seletor de período do UTM. |

## Ordem sugerida

1. **Rápidos:** UX-10 (tokens de contraste), UX-03 ("▲ de 0" e "novo"), UX-16, UX-17, UX-18, UX-21 e UX-22. Mudam pouco código e aparecem em todas as telas.
2. **Celular:** UX-01, UX-13 e UX-06 (cartões de número); UX-02 e UX-15 (gestor no celular); UX-09 e UX-11 (toque e texto).
3. **Leitura:** UX-04 (nomes no lugar dos IDs), UX-05 (espaço vazio), UX-07, UX-08, UX-12, UX-14, UX-19, UX-20 e UX-23.

**Donos:**
- o Resumo, o Financeiro, a grade e os gráficos (UX-01, 05, 08, 13, 17, 23) são do trabalho do painel editável;
- o Gestor, as tabelas, os tokens e o núcleo (UX-02, 03, 04, 06, 07, 09, 10, 11, 14, 15, 16, 18 a 22) podem andar em paralelo.

Combinar antes quem pega o quê, porque os dois terminais usam a mesma pasta.
