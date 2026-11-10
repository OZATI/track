# Padrão do admin (para replicar em qualquer projeto)

Pedido do Kenio, 10/11/2026: "entende o padrão bem feito para replicar em todo e qualquer projeto, esse
que temos é bom para fazer isso". O modelo é o admin do EngDesk (`admin.engdesk.pro`), feito com este
repositório como núcleo. Já usam o padrão: EngDesk, COP (`admin.centroodontologicopaulista.com.br`) e
Fitness Flag (`admin.fitnessflag.com`).

## 1. Estrutura de pastas

O admin mora num subdomínio próprio (`admin.<domínio>`), com a raiz numa pasta `admin/` do repositório
do projeto:

```
admin/
  index.php              entrada do primeiro módulo do projeto (no EngDesk, o CMS)
  utm/                   CÓPIA deste repositório (scripts/copiar-para.sh). Nunca editar lá.
  conta/index.php        define TRACK_BASE '../utm/' e inclui utm/conta.php
  usuarios/index.php     define TRACK_BASE '../utm/' e inclui utm/usuarios.php
  lib/<projeto>.php      require utm/lib/util.php e utm/lib/layout.php; regras do projeto
  lib/<projeto>_tela.php a moldura do módulo: secoes, menu, cabecalho, casca (modelo: engdesk/admin/lib/cms_tela.php)
  css/, js/              o que é só do projeto
```

- Toda página do admin fora de `utm/` define `TRACK_BASE` (`'utm/'` na raiz, `'../utm/'` numa subpasta)
  antes do `require`: sem ele, a barra lateral aponta UTM, DRE, BIO e Integrações para endereços errados
  e o `tabela.js`/`painel.js` dão 404 (bug achado no EngDesk em 10/11/2026).
- Dados e chaves ficam em `TRACK_DADOS` / `track-dados`, fora do `public_html`. Nada de chave no JS.
- Login é o do admin (usuário e senha, só da equipe), sem cadastro na tela de entrada.

## 2. Navegação (a mesma em todo módulo)

- **Barra lateral** (`admin_lateral`): os painéis (CMS, UTM, DRE, BIO, Integrações) em cima e a conta
  embaixo. Ícone com nome curto.
- **Menu do módulo no alto, ACIMA dos filtros**, com ícones (a partir de 1280 px), em grupos, o mesmo em
  todas as telas do módulo e no mesmo lugar (fica preso no alto ao rolar). Uma função só desenha o menu
  (no EngDesk, `cms_nav`); tela nova entra na lista de seções, nunca com menu próprio.
- **Cabeçalho da página** igual em todas as telas: "PROJETO / grupo" pequeno, o título, a descrição e as
  ações à direita (`cms_cabecalho`).
- Abas que são a mesma página (só muda o `#`) entram no histórico: o Voltar do navegador volta para a
  aba anterior e o título da janela diz a aba.
- **Celular (até 760 px):** em cima, uma barra fixa só com o painel aberto e a foto; tocar abre o menu
  em lista ("Painéis" e "Conta e ferramentas", cada item com ícone e nome escrito, tela escurecida, toque
  fora fecha). As seções do módulo vão para a **barra do polegar** embaixo (`.nav-celular`: 4 seções e
  "Mais"), componente do núcleo (`admin_css_base`). Área de toque de 44 px ou mais.

## 3. Visual

- **Tabler 1.7.4** (MIT) é a base de componentes (`TABLER_CSS`, `vendor/`), com as cores do tema do
  admin (claro, escuro, pretão, cor livre) em `admin_css_base`. Detalhes em `docs/TABLER.md`.
- Sóbrio: cor só no dado e no estado atual; sem faixa colorida em cartão; o estilo dos blocos do Resumo
  (cartão de número, lista com anel, rosca).
- Todo número e todo bloco tem o **(i)** com a explicação (interruptor dos (i) na barra lateral).
- Gráficos de `lib/graficos.php` (curva suave, mira com marcadores, barras de ponta redonda): nunca
  biblioteca de fora (a CSP não deixa e o print precisa do SVG do servidor).
- Duas formas de tela:
  - com o visual do painel: `pagina_inicio()` + a casca do módulo + `pagina_fim()` (os (i), o período e
    as tabelas inteligentes do `painel.js`);
  - com os componentes do Tabler (card, form-control, form-switch, table card-table): a página inteira
    do módulo (no EngDesk, `cms_pagina_inicio`/`cms_pagina_fim`, Checkout e E-mails).

## 4. Montar o admin num projeto novo

1. `bash ../track/scripts/copiar-para.sh admin/utm` (pede o track commitado; grava o `VERSAO`).
2. Criar `admin/conta/` e `admin/usuarios/` (duas linhas cada, como no EngDesk).
3. Copiar do EngDesk a moldura do módulo (`admin/lib/cms_tela.php`, `admin/js/cms-nav.js`,
   `admin/css/tabler-engdesk.css`) e trocar a lista de seções e os nomes.
4. Abrir `utm/instalar.php` no servidor: cria o primeiro usuário e a pasta de dados.
5. Publicar pelo deploy que o projeto já tem (FTP ou Git), nunca criando outra integração.

## 5. Atualizar o núcleo nos projetos

Mudança no núcleo é feita aqui, com teste (`bash tests/fluxo.sh`, tudo OK) e commit; depois, em cada
projeto: `bash ../track/scripts/copiar-para.sh admin/utm`, testes do projeto e publicação.

## 6. QA antes de publicar

- Larguras 1440, 1280, 1024, 768 e 390; tema claro e escuro.
- Usuário com acesso restrito (sem Financeiro, sem Integrações): o menu não mostra o que ele não abre e
  o servidor barra.
- Cliques reais nas abas e no Voltar do navegador; console sem erro; sem rolagem lateral.
- Com o Tabler ligado e desligado, nenhuma tela do painel muda de tamanho (medidor em `docs/TABLER.md`).

## Próximo passo do padrão

A moldura do módulo (menu, cabeçalho, casca e a barra do polegar do módulo) ainda mora no EngDesk
(`cms_tela.php`). Quando o segundo projeto usar (OZATI), ela sobe para o núcleo como função genérica
(lista de seções → menu, cabeçalho e barra), e cada projeto só declara as seções.
