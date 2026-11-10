# Tabler no painel

Desde 10/11/2026 (pedido do Kenio: "instale também no painel UTM e demais em todo o nosso painel").

## O que é

- [Tabler](https://tabler.io) `@tabler/core` **1.7.4**, licença MIT, só o CSS:
  `vendor/tabler/1.7.4/dist/css/tabler.min.css` (+ `LICENSE`). O painel não usa o JavaScript do Tabler.
- Carregado por `pagina_inicio()` (`lib/layout.php`) **antes** do CSS do painel, que continua mandando.
  O caminho fica na constante `TABLER_CSS` (`lib/admin_tela.php`), a partir de `admin_base()`.
- `scripts/copiar-para.sh` leva a pasta `vendor/` para a cópia de cada site.
- Atualizar = outra pasta de versão (`vendor/tabler/<nova>/`) e trocar `TABLER_CSS`. Nunca sobrescrever.

## Cores e tema

`admin_css_base()` liga as variáveis do Tabler (`--tblr-*`) às do tema do admin (claro, escuro, pretão
ou cor livre): `card`, `form-control`, `btn`, `table` e `badge` do Tabler já nascem com a cara do painel.
O `<html>` leva `data-bs-theme="light|dark"` pelo tema escolhido; o `painel.js` mantém o atributo na
troca de tela sem recarregar e na prévia da cor livre.

## Reboot

O reboot do Tabler mudava o que o painel herdava do navegador. Medido em 10/11/2026 com o Tabler ligado
e desligado em todas as telas (Resumo, Tráfego, Gestor, Orgânico, Conferência, Vendas, Visitantes,
Eventos, Configurações, DRE, Bio, Integrações, Conta, Usuários, login): voltaram ao que eram, com
`:where()` (especificidade baixa, as regras do painel continuam valendo):

- títulos: altura de linha 1,5 e peso 700; campos e botões sem classe com 13 px (`admin_css_base`);
- parágrafo com margem de 1em, `code` sem fundo, listas com o recuo do navegador, caixas de marcar com a
  margem do navegador e `legend` sem o float (`lib/layout.php`, só nas telas do painel);
- `.avatar` sem a borda interna.

Sobrou de diferença: a linha do `hr` do gestor na cor da borda do tema e o `kbd` da busca de
Integrações 2 px menor.

## Navegação

As abas do UTM (`abas_painel`) têm o desenho do navbar do Tabler e do menu do CMS do engdesk: 48 px,
ícone apagado (a aberta na cor da marca) a partir de 1280 px, título "UTM · Rastreio de vendas" só a
partir de 1600 px. A navegação vem **antes** do filtro (`barra_topo`), que continua preso no alto ao rolar.

## Gráficos

`lib/graficos.php` segue o desenho dos gráficos do Tabler (ApexCharts), sem biblioteca (a CSP não deixa
e o print do Resumo precisa do SVG do servidor): linha em curva suave monótona (`grafico_curva`, não
cria pico que não existe), traço de 2 px, área em degradê leve, mira vertical com o marcador de cada
série (8 px, anel da cor do cartão) ao passar o mouse, barras do lucro em HTML com a ponta de 4 px
redonda e no máximo 24 px, colunas sólidas na cor da marca e a rosca com vão entre as fatias.
