# Gestor: a miniatura e a prévia do criativo

Pedido do Kenio (13/11/2026), no nível Anúncios do gestor: ver o criativo de cada anúncio, e não só o nome. O
servidor é do Alicerce: `lib/meta_criativo.php`, `meta-criativo.php`, `meta-previa.php` e a busca em
`lib/meta_sync.php`. A tela é do Nanquim: `lib/gestor.php`, `lib/layout.php` e `painel.js`.

## A miniatura da linha

- **De onde vem.** A listagem de anúncios que a busca já faz (`/act_<conta>/ads`) passa a pedir também o
  criativo: o id, o tipo e a miniatura de 192 px. Não há chamada a mais à API da Meta.
  - Se a Meta recusar esses campos, a listagem sai como antes e o gestor segue sem as miniaturas.
  - O motivo fica no ajuste `meta_criativo_erro`.
- **O download.** O servidor baixa a imagem:
  - só de anúncio novo ou que trocou de criativo;
  - no máximo 24 por busca, dentro de 12 segundos (o resto vem na próxima busca), começando pelas que ainda
    não falharam;
  - só JPEG, PNG ou WebP, do CDN da Meta, em HTTPS, até 8 MB;
  - só imagem que cabe na memória do PHP: o tamanho em pixels é lido antes de abrir (até 12 MP, menos se a
    memória for pouca);
  - depois de gravar o resultado da busca, para uma falha no download nunca desfazer a busca.
- **A imagem guardada.**
  - É regravada pelo GD em JPEG de até 192 px, sem os metadados.
  - Fica na pasta de dados, fora do site: `meta-criativo/<id do anúncio>.jpg`.
  - Cada tentativa conta como falha antes do download, e o sucesso zera. Depois de 3 falhas com o mesmo
    criativo, o servidor para de tentar até o criativo mudar.
- **A entrega.** `meta-criativo.php?id=<anúncio>&v=<criativo>` entrega a imagem só para quem entrou e tem o
  acesso UTM. Três ganhos:
  - a CSP continua com `img-src 'self' data:`;
  - o endereço da Meta, que vence, não importa;
  - quem abre o painel não fala com o CDN da Meta.
- **Para a tela.** `meta_criativo_linha($obj)` devolve um destes três:
  - `['src' => 'meta-criativo.php?id=…&v=…', 'tipo' => 'imagem'|'video'|'carrossel']`;
  - `src` null com o tipo (a Meta mandou o criativo sem a imagem): o quadrado neutro com o tipo;
  - `null` (nada se sabe do criativo): o quadrado neutro sem o tipo.

## A prévia do popup (o olho)

- **Quando chama a Meta.** `meta-previa.php?id=<anúncio>&formato=feed|stories|reels` só lê, por GET, com o mesmo
  login e acesso UTM. Ele chama a Meta só quando o popup abre ou troca de anúncio ou de formato, com o mesmo
  token de leitura, sem permissão nova.
- **Como chama.** Uma chamada traz o status (`effective_status`), os textos do criativo e a prévia oficial dos
  três formatos (`/{anúncio}/previews`: `INSTAGRAM_STANDARD`, `INSTAGRAM_STORY` e `INSTAGRAM_REELS`).
  - Se a Meta recusar o pedido junto por erro de campo e os detalhes sozinhos passarem, por um dia vale o modo
    separado: os detalhes numa chamada e cada formato na sua, só quando pedido. Erro de um anúncio só (apagado,
    sem acesso) não troca o modo: fica guardado por 10 minutos e o popup mostra a reserva.
- **Limites.**
  - A resposta fica guardada por 10 minutos (`meta_previas`), então ir e voltar pelas setas não repete
    chamadas.
  - Tudo passa pela mesma proteção contra bloqueio das outras consultas (limite por minuto e pausa quando a
    Meta pede). A prévia só chama a Meta se sobrar folga nesse limite: ela deixa 10 chamadas por minuto para a
    busca e para o ligar e pausar do gestor.
  - Cada usuário pode abrir até 120 prévias em 10 minutos; acima disso, o popup mostra a reserva sem chamar a
    Meta.
- **O iframe.** A prévia da Meta é um iframe de `https://www.facebook.com`.
  - O servidor devolve só o endereço dele, conferido, nunca o HTML da Meta.
  - A CSP do painel ganha `frame-src https://www.facebook.com/ads/api/preview_iframe.php` (só o caminho da
    prévia), num commit separado. O seguranca aceitou (13/11/2026). Ela tem de ser publicada junto com o
    `meta-previa.php`. Sem ela, ou se a Meta falhar, a tela mostra a reserva.
  - O iframe sai com `sandbox="allow-scripts allow-same-origin"` (sem abrir janelas: um clique dentro da prévia
    não leva à página de venda nem dispara o Pixel), `referrerpolicy="no-referrer"` e um título.
  - **Privacidade de quem abre o painel.** As miniaturas nunca falam com a Meta. A prévia, sim, e só ao clicar no
    olho: o navegador de quem abre fala com o facebook.com e manda o IP, o navegador e, se houver, os cookies do
    Facebook dessa pessoa. Aceito para a equipe interna.
- **A reserva** (o anúncio nunca deixa de aparecer):
  - a nossa imagem guardada em tamanho maior (até 600 px, baixada uma vez ao abrir a prévia);
  - os textos do criativo (texto principal, título, descrição e botão), em texto puro;
  - o link "Abrir no Gerenciador".
- **Nada de dado pessoal:** nem comentários, nem quem interagiu. Do criativo saem só os textos do próprio
  anúncio.

## Testes

- `bash tests/criativo.sh`: a busca, o download (limite, prazo, tipos, EXIF, CDN de fora, troca de criativo,
  Meta que recusa os campos), os dois endpoints (acesso, 404, cache e formatos) e a reserva. Usa a Meta falsa
  (`tests/meta-falsa.php`), que serve também o CDN falso.
- No painel local (`scripts/dev.sh`), o "cv free" vem com imagem e o "cv 05" só com o tipo (vídeo).
