# Arquitetura do OZATI/track

O track é a **base de um admin** para sites que vendem: um núcleo (login, usuários, conta, visual e componentes) e módulos que se ligam a ele (UTM, Bio e, em seguida, Pagamentos e Financeiro). É feito para ser reproduzido em outros sites, com outra interface por cima, e vendido como produto. Tudo o que é genérico mora aqui; o que é de um site (produto, preço, página, textos) mora no repositório do site.

Regras de sempre:
- PHP 8.1+ e SQLite.
- JavaScript puro, sem build, sem framework, sem dependência.
- Roda em hospedagem compartilhada (Hostinger).

---

## 1. As três camadas

```
┌────────────────────────── site (ex.: engdesk) ──────────────────────────┐
│  páginas públicas (venda, bio)   CMS do site (admin/)   integração        │
│        │ t.js, bio_dados()            │ admin_lateral, admin_css_base,    │
│        ▼                              ▼ tabela.js                         │
├────────────────────────── módulos (track) ──────────────────────────────┤
│  UTM: rastreio, vendas, gestor da Meta, orgânico, financeiro             │
│  Bio: links e textos da página de links                                   │
│  Pagamentos (planejado): Mercado Pago + Wiven, Purchase pela CAPI        │
├────────────────────────── núcleo (track) ───────────────────────────────┤
│  login e sessão · usuários e acessos · Minha conta · barra lateral        │
│  tema · componentes (cartões, tabela inteligente, menu "...")             │
│  navegação suave · banco e migrações · CSP e segurança                    │
└──────────────────────────────────────────────────────────────────────────┘
```

## 2. Núcleo

| Peça | Onde | O que faz |
|---|---|---|
| Configuração e dados | `lib/config.php`, `lib/db.php` | Pasta de dados fora do `public_html` (`track-dados/`, ou `TRACK_DADOS`). SQLite com migrações numeradas (`track_migrar_para`, hoje v15). `ajustes` guarda as escolhas do painel (chave e valor). |
| Utilitários | `lib/util.php` | Escape (`e()`), texto de fora (`texto()`), sessão, CSRF, limite de tentativas, períodos e fuso, `exigir_login($acesso)`. |
| Acessos | `lib/admin.php` | `TRACK_ACESSOS` (cms, utm, usuarios, bio, financeiro, integracoes); `config['acessos'][usuario]`; quem não tem lista abre tudo. `track_acessos_migrar()` dá um acesso novo, uma vez, a quem já tinha o que ele substitui (`acessos_v`). Endereços das telas do núcleo (`admin_url`), na raiz do admin quando existem as pastas `conta/` e `usuarios/`. Regra da senha. |
| Visual comum | `lib/admin_tela.php` | `admin_css_base()` (tokens do tema, barra lateral, cartões, tabela inteligente, menu "..."), `admin_lateral()` (painéis liberados e a conta) e `admin_cabecalho()`. O CMS do site usa os mesmos. |
| Tema | `lib/tema.php` | Claro, escuro, pretão ou cor livre, por usuário. |
| Telas do núcleo | `conta.php`, `usuarios.php`, `entrar.php`, `sair.php`, `instalar.php` | Minha conta (foto, senha, aparência, sair), Usuários (quem entra e o que abre), login e instalação. |
| Componentes | `lib/componentes.php`, `tabela.js` | `cartao_kpi`/`cartoes_kpi`; a tabela inteligente (`tabela_card_inicio`/`fim`: busca, Filtros por `data-filtro`, Ocultos, Por página, restaurar); células prontas (selo, situação, anel, mini gráfico, data, prazo); `menu_linha` (o "...", que abre por cima da tabela). `Tabela.lembrar()` guarda busca, filtros e página quando a tela é redesenhada. |
| Integrações | `integracoes.php`, `lib/integracoes.php` | As contas de fora num lugar só (barra lateral, clipe, acesso `integracoes`), no desenho da UTMify: abas Anúncios, Vendas, Orgânico e Rastreio, e um cartão por plataforma com o estado (conectado, conta, última busca, erro). As telas de cada uma (`meta-api.php`, `kiwify-api.php`, `instagram-api.php`) abrem dentro dela, com `integracoes_topo()`. **Plataforma nova:** uma entrada em `integracoes_registro()` (aba, tela ou `null` para "em breve", função de estado) e o logo em `integracao_logo()`. **Aba nova:** uma linha em `INTEGRACOES_ABAS`. O que cada plataforma exige (políticas e validação): `docs/PLATAFORMAS.md`. |
| Navegação suave | `painel.js` | Troca a tela sem recarregar (GET busca; POST envia e segue o redirecionamento), guarda a rolagem e a página das tabelas, e liga os componentes de novo a cada troca (`iniciar()`). A barra de carregando corre no alto também quando abre outra página inteira, e a tela nova entra com uma animação curta. |
| Telas montáveis | `lib/grade.php`, `lib/graficos.php`, `painel-salvar.php` | Qualquer tela vira blocos que cada pessoa monta pelo lápis da barra lateral (GridStack no modo de montar, CSS Grid para ver). Layout por pessoa, tela e aparelho (`painel_layouts`, chave `<tela>:<aparelho>`). A tela descreve os blocos num array (título, categoria, a conta no (i), tamanhos, ícone, integração de que depende) e o motor faz o resto. Hoje: Resumo (`resumo_grade()`, `lib/painel.php`) e Financeiro (`financeiro_grade()`). Gráficos que enchem o bloco: área, barras, mapa de calor, mostrador do ROI e a linhazinha dos números, com a seta contra o período anterior. |
| Segurança | `.htaccess`, todas as telas | CSP com `script-src 'self'` (nenhum script embutido nem `onclick`), CSRF em todo POST, POST seguido de redirecionamento, SQL só com parâmetros, `lib/`, `tests/`, `scripts/` e dados bloqueados. |

## 3. Módulos

### UTM (rastreio e vendas)
- **Rastreio:** `t.js` nas páginas manda PageView, CliqueCheckout, WhatsApp, Botao, BioClique e os da VSL para `coletar.php` (só dos sites autorizados).
- **Vendas:**
  - `kiwify.php` é o webhook; `lib/kiwify_api.php` e `lib/kiwify_sync.php` buscam pela API;
  - a conferência liga a venda ao visitante pelo `sck`.
- **Gestor de anúncios:** `lib/gestor*.php`, `lib/meta_*.php` e `lib/orcamento.php`, no padrão da UTMify, com:
  - colunas, ordenação e comparação;
  - ranking;
  - análise diária;
  - as Ações das marcadas (Gerenciador, ativar/desativar, orçamento por valor ou %);
  - o orçamento programado (`cron.php`).
- **Orgânico e Instagram:** `lib/organico*.php` e `lib/instagram_*.php`.
- **Resumo:** tela montável (o antigo Painel, aba própria por um dia, virou o Resumo em 07/10/2026; o endereço `?aba=painel` leva para ele).
- **Financeiro:** virou módulo próprio em 07/10/2026 (`financeiro.php`, acesso `financeiro`, na barra lateral), com o caixa em blocos montáveis e o cadastro das despesas (`lib/financeiro.php`, `gastos.php`). É onde entram os pagamentos próprios.
- **Notificações e app:** `lib/push.php`, `sw.php`, `manifest.php` e `notificacoes.php`.

### Bio
- `lib/bio.php` guarda os links (tabela `bio_links`) e os textos (`ajustes`, prefixo `bio_`).
- A tela `bio.php` (acesso `bio`) mostra visitas e cliques por link.
- A página pública fica no site e chama `bio_dados($dominio)`.
- **Etiquetas:** links do próprio site ganham `organico / instagram-bio / bio / <nome do link>`.
- **WhatsApp:** o número vem do painel.

### Pagamentos (planejado)
- Desenho em `engdesk/docs/CHECKOUT_TRANSPARENTE.md`.
- `lib/pagamentos/`, com as mesmas cinco funções por provedor: criar cobrança, consultar, validar o webhook, normalizar a situação e estornar.
- **Provedores:** Mercado Pago (API Orders) como principal e Wiven sempre configurada como reserva.
- **Purchase:** pela API de Conversões a partir do servidor.

## 4. Integração com um site

1. **Copiar, não implantar à parte:** `bash scripts/copiar-para.sh ../<site>/admin/utm`. Leva as telas `.php`, os `.js` da raiz e `lib/`, mais o `VERSAO` com o commit. Configuração e dados nunca vão junto. O site publica a cópia pelo deploy que já tem. Nunca edite a cópia.
2. **Telas do núcleo na raiz do admin:** `admin/conta/index.php` e `admin/usuarios/index.php` só definem `TRACK_BASE` (ex.: `'../utm/'`) e incluem a tela daqui.
3. **CMS do site:** o CMS usa:
   - `admin_css_base()` e `admin_lateral()` (lado do servidor);
   - `utm/tabela.js` (tabela inteligente e menu "...");
   - o mesmo login, conferido por `cms_exigir_acesso`.
   - O link do CMS fica na configuração (`menu_cms`).
4. **Páginas públicas:**
   - `t.js` no fim do `<body>`;
   - a página da bio inclui `admin/utm/lib/bio.php` e chama `bio_dados()`. Sem o painel, mostra os links padrão dela.
5. **Login do admin inteiro:** a sessão vale para o site todo (cookie `track_sessao`, caminho `/`).

## 5. Como acrescentar um módulo

1. **Banco:** uma migração nova em `lib/db.php` (próxima versão) com as tabelas do módulo.
2. **Lógica:** `lib/<modulo>.php`. Funções puras, sem saída; tudo o que vem de fora passa por `texto()` e é validado.
3. **Tela:** `<modulo>.php` com:
   - `exigir_login('<acesso>')`;
   - POST com `csrf_valido()` e, depois, o redirecionamento;
   - `pagina_inicio`, `casca_inicio('<modulo>')`, `cartoes_kpi` e `tabela_card_inicio`/`fim`.
4. **Acesso:** a chave em `TRACK_ACESSOS` e o item na barra lateral (`admin_lateral`).
5. **Testes:** em `tests/fluxo.sh`, com serviços de fora simulados (`tests/*-falsa.php`). Nunca tocar em site, conta ou API real.

## 6. Próximos passos de produto

- **Mais telas montáveis:** Orgânico, Bio e Tráfego entram no mesmo motor: uma função `<tela>_grade()`, a entrada em `GRADE_TELAS` e `grade_lapis('<tela>')` antes de `casca_inicio()`. Tabelas de cadastro (despesas, links da Bio) ficam fora da grade.

- **Módulos por site:** hoje um módulo aparece para quem tem o acesso. Falta ligar e desligar o módulo por site (`config['modulos']`) e esconder o acesso dele em Usuários quando estiver desligado.
- **Financeiro como módulo próprio:** caixa, despesas, impostos, taxas, recorrência e relatórios, com painel na barra lateral. O estudo está em `docs/PAINEL_PERSONALIZAVEL.md`.
- **Plataformas de anúncio:**
  - a mesma camada de "fonte de anúncio" do `meta_sync.php` para Google, TikTok e Taboola;
  - cada uma com o seu gestor e o Resumo somando todas (mesmo estudo).

## 7. O que ainda é da EngDesk dentro do track

Levantamento de 07/10/2026. A meta é que nada disto fique no código genérico.

| Onde | O quê | Para onde |
|---|---|---|
| `lib/resumo.php` (funil da VSL) | Nomes das páginas `/drivedeprojetos` ("Principal · R$ 67") e `/vsl` ("VSL Teste · R$ 97") | Uma configuração "nome de cada página" (Configurações do UTM), usada no funil e nas listas de página. Sem nome, mostra o caminho. Pendente de decisão, para o Resumo da EngDesk não perder os nomes de hoje. |
| `lib/organico.php` (legenda) | Exemplos `engdesk.pro/ig` e `engdesk.pro/story` | Texto genérico (feito em 07/10/2026). |
| `lib/gestor_analise.php` | "A regra do Allan" no CPI e no CTR | Texto genérico, com a régua de referência (feito em 07/10/2026). Os limites (10% do ticket, CTR de 2%) podem virar configuração. |
| `meta-api.php`, `instalar.php` | Exemplos "DRIVE DE PROJETOS" e `engdesk.pro` | Exemplos genéricos (feito em 07/10/2026). |
| `tests/fluxo.sh` | Dados de teste com o Drive de Projetos | Pode ficar: são dados de exemplo dos testes. |
