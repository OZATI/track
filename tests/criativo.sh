#!/usr/bin/env bash
# Teste do criativo do gestor: a miniatura (lib/meta_criativo.php pela busca de lib/meta_sync.php e
# meta-criativo.php) e a previa do popup (meta-previa.php), em localhost, com a Meta falsa
# (tests/meta-falsa.php) e o CDN falso dela. Nao toca em nenhum site, conta ou API real.
#
# Uso:  bash tests/criativo.sh                 (php no PATH, com o GD)
#       PHP=/caminho/php.exe bash tests/criativo.sh

set -u
PHP="${PHP:-php}"
RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
DADOS="$(mktemp -d)"
PORTA="${PORTA:-8850}"
URL="http://127.0.0.1:$PORTA"
JAR="$DADOS/cookies.txt"
J2="$DADOS/cookies-semutm.txt"
FALHAS=0

# PHP portatil do Windows: as extensoes ficam na pasta ext ao lado do php.exe. So pede a que ainda nao
# estiver carregada (carregar de novo gera aviso). O GD e obrigatorio: e ele que regrava as miniaturas.
if [ -z "${PHP_FLAGS:-}" ]; then
  EXT="$(dirname "$(command -v "$PHP")")/ext"
  PHP_FLAGS=""
  if [ -d "$EXT" ]; then
    CARREGADOS="$("$PHP" -m 2>/dev/null | tr 'A-Z' 'a-z')"
    for x in pdo_sqlite mbstring curl openssl gd; do
      printf '%s
' "$CARREGADOS" | grep -qx "$x" || PHP_FLAGS="$PHP_FLAGS -d extension=$x"
    done
    [ -n "$PHP_FLAGS" ] && PHP_FLAGS="-d extension_dir=$(cygpath -w "$EXT" 2>/dev/null || echo "$EXT")$PHP_FLAGS"
  fi
fi

confere() { if [ "$1" = "0" ]; then echo "  ok     $2"; else echo "  FALHA  $2"; FALHAS=$((FALHAS + 1)); fi; }
tem() { printf '%s\n' "$2" | grep -q -- "$1"; echo $?; }
nao_tem() { printf '%s\n' "$2" | grep -q -- "$1"; [ $? -ne 0 ]; echo $?; }
igual() { [ "$1" = "$2" ]; echo $?; }
win() { cygpath -w "$1" 2>/dev/null || echo "$1"; }

export TRACK_DADOS="$(win "$DADOS")"
PORTA_META=$((PORTA + 2))
export TRACK_META_API="http://127.0.0.1:$PORTA_META/graph"
export TRACK_META_MIDIA_HOST="127.0.0.1:$PORTA_META"
export TRACK_META_LIMITE_MINUTO=200
export TRACK_META_CRIATIVOS_POR_BUSCA=4
export META_FALSA_CENARIO="$(win "$DADOS/cenario.txt")"
export META_FALSA_LOG="$(win "$DADOS/meta.log")"
: >"$DADOS/cenario.txt"
: >"$DADOS/meta.log"
# shellcheck disable=SC2086
"$PHP" $PHP_FLAGS -S "127.0.0.1:$PORTA_META" "$RAIZ/tests/meta-falsa.php" >"$DADOS/meta-falsa.log" 2>&1 &
META_FALSA=$!
ERROS_PHP="$(win "$DADOS/php-erros.log")"
# shellcheck disable=SC2086
"$PHP" $PHP_FLAGS -d display_errors=0 -d log_errors=1 -d error_reporting=-1 -d "error_log=$ERROS_PHP" -S "127.0.0.1:$PORTA" -t "$RAIZ" >"$DADOS/servidor.log" 2>&1 &
SERVIDOR=$!
trap 'kill $SERVIDOR $META_FALSA 2>/dev/null; rm -rf "$DADOS"' EXIT
sleep 1

# PHP na linha de comando, na pasta do painel, com os mesmos dados e a mesma Meta falsa
php_cli() { (cd "$RAIZ" && "$PHP" $PHP_FLAGS -d display_errors=1 -d error_reporting=-1 -r "require 'lib/util.php'; require_once 'lib/meta_sync.php'; $1"); }
sincronizar() { php_cli 'echo json_encode(meta_sincronizar(true));'; }
sql() { php_cli "echo json_encode(consulta(track_db(), \"$1\", []), JSON_UNESCAPED_SLASHES);"; }
linha() { php_cli "echo json_encode(meta_criativo_linha(consulta(track_db(), 'SELECT * FROM meta_objetos WHERE id = ?', ['$1'])[0] ?? null), JSON_UNESCAPED_SLASHES);"; }
pedidos() { grep -c -- "$1" "$DADOS/meta.log"; }
cenario() { printf '%s\n' "$@" >"$DADOS/cenario.txt"; }
previa() { curl -s -b "${2:-$JAR}" "$URL/meta-previa.php?$1"; }
campo() { php_cli "\$j = json_decode(file_get_contents('php://stdin'), true); \$v = \$j; foreach (explode('.', '$1') as \$p) { \$v = is_array(\$v) ? (\$v[\$p] ?? null) : null; } echo is_array(\$v) ? json_encode(\$v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : var_export(\$v, true);"; }

echo "Preparo: instalação, login e a Meta conectada"
r=$(curl -s -c "$JAR" -b "$JAR" "$URL/instalar.php")
csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' < <(printf '%s\n' "$r") | head -1 | grep -o '[a-f0-9]\{32\}')
r=$(curl -s -c "$JAR" -b "$JAR" --data-urlencode "csrf=$csrf" --data-urlencode "usuario=Kenio" --data-urlencode "senha=senha-de-teste-123" \
    --data-urlencode "senha2=senha-de-teste-123" --data-urlencode $'origens=http://site.test' --data-urlencode "dias=30" "$URL/instalar.php")
confere "$(tem 'Painel instalado' "$r")" "painel instalado"
r=$(curl -s -c "$JAR" -b "$JAR" "$URL/entrar.php")
csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' < <(printf '%s\n' "$r") | head -1 | grep -o '[a-f0-9]\{32\}')
curl -s -o /dev/null -c "$JAR" -b "$JAR" --data-urlencode "csrf=$csrf" --data-urlencode "usuario=kenio" --data-urlencode "senha=senha-de-teste-123" "$URL/entrar.php"
r=$(curl -s -c "$JAR" -b "$JAR" "$URL/meta-api.php")
csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' < <(printf '%s\n' "$r") | head -1 | grep -o '[a-f0-9]\{32\}')
curl -s -o /dev/null -b "$JAR" --data-urlencode "csrf=$csrf" --data-urlencode "acao=salvar" --data-urlencode "token=TokenLeitura0000000000000000000000000000000000" \
    --data-urlencode "conta=587364236934346" "$URL/meta-api.php"
confere "$(grep -q 'TokenLeitura' "$DADOS/config.php"; echo $?)" "token de leitura da Meta salvo"
# Um usuario sem o acesso UTM (so BIO), para conferir o controle de acesso
php_cli '$c = track_config(); $c["usuarios"]["semutm"] = password_hash("senha-de-teste-456", PASSWORD_DEFAULT); $c["acessos"]["semutm"] = ["bio"]; track_salvar_config($c);'
r=$(curl -s -c "$J2" -b "$J2" "$URL/entrar.php")
csrf2=$(grep -o 'name="csrf" value="[a-f0-9]*"' < <(printf '%s\n' "$r") | head -1 | grep -o '[a-f0-9]\{32\}')
curl -s -o /dev/null -c "$J2" -b "$J2" --data-urlencode "csrf=$csrf2" --data-urlencode "usuario=semutm" --data-urlencode "senha=senha-de-teste-456" "$URL/entrar.php"

echo "Miniatura: a busca na Meta"
cenario criativos
r=$(sincronizar)
confere "$(tem '"ok":true' "$r")" "a busca termina bem com os criativos ($r)"
confere "$(igual "$(pedidos 'fields=id%2Cname%2Cstatus%2Ceffective_status%2Cadset_id%2Ccampaign_id%2Ccreative.thumbnail_width%28192%29.thumbnail_height%28192%29')" 1)" "o criativo vem na MESMA listagem de anúncios, com a miniatura de 192 px (nenhuma chamada a mais)"
confere "$(igual "$(pedidos '^/cdn/')" 4)" "no máximo 4 downloads por busca (TRACK_META_CRIATIVOS_POR_BUSCA)"
confere "$(tem '"criativos":2' "$r")" "na 1ª busca, 2 dos 4 baixados (o arquivo que não é imagem e o de 9 MB falham)"
r=$(sql "SELECT id, criativo_id, criativo_tipo, criativo_baixado, criativo_falhas FROM meta_objetos WHERE id IN ('222222','333333','444002') ORDER BY id")
confere "$(tem '"id":"222222","criativo_id":"900222","criativo_tipo":"video","criativo_baixado":null' "$r")" "vídeo sem miniatura: o tipo fica gravado, nada baixado"
confere "$(tem '"id":"333333","criativo_id":"900333","criativo_tipo":"imagem","criativo_baixado":"900333"' "$r")" "imagem: baixada e marcada com o id do criativo"
confere "$(tem '"id":"444002","criativo_id":"9444002","criativo_tipo":"imagem","criativo_baixado":null,"criativo_falhas":1' "$r")" "arquivo que não é imagem: recusado e contado como falha"
confere "$([ -f "$DADOS/meta-criativo/333333.jpg" ] && [ ! -e "$DADOS/meta-criativo/444002.jpg" ] && [ ! -e "$DADOS/meta-criativo/444003.jpg" ]; echo $?)" "arquivo com o id numérico do anúncio, na pasta de dados; nada dos recusados"
r=$(php_cli "\$i = getimagesize('$(win "$DADOS/meta-criativo/333333.jpg" | sed 's/\\/\\\\/g')'); echo \$i[0] . 'x' . \$i[1] . ' ' . \$i['mime'];")
confere "$(igual "$r" '192x192 image/jpeg')" "miniatura regravada em JPEG de 192 px ($r)"
confere "$(curl -s "http://127.0.0.1:$PORTA_META/cdn/exif.jpg" | grep -q -a 'GPS-SEGREDO-DO-TESTE'; echo $?)" "(controle) a imagem de origem tem o bloco EXIF com o texto"
confere "$(grep -q -a 'GPS-SEGREDO-DO-TESTE' "$DADOS/meta-criativo/444001.jpg"; [ $? -ne 0 ]; echo $?)" "regravada pelo GD: o EXIF não sobra"
r=$(php_cli "echo ajuste('meta_criativo_erro');")
confere "$(tem 'acima de 8 MB' "$r")" "o motivo da última falha fica guardado ($r)"

r=$(sincronizar)
confere "$(igual "$(pedidos '/cdn/a333333.jpg')" 1)" "a 2ª busca não baixa de novo o que já está guardado"
confere "$(tem '"criativos":3' "$r")" "a 2ª busca começa pelo que ainda não falhou (3 baixados)"
confere "$(igual "$(pedidos 'example.com')" 0)" "CDN de fora (example.com): nem chega a ser pedido"
r=$(sql "SELECT criativo_falhas FROM meta_objetos WHERE id = '444004'")
confere "$(tem '"criativo_falhas":1' "$r")" "CDN de fora: recusado e contado como falha"
r=$(php_cli "\$i = getimagesize('$(win "$DADOS/meta-criativo/444005.jpg" | sed 's/\\/\\\\/g')'); echo \$i[0] . 'x' . \$i[1] . ' ' . \$i['mime'];")
confere "$(igual "$r" '192x96 image/jpeg')" "PNG aceito e regravado em JPEG, na proporção ($r)"
for i in 1 2 3 4; do sincronizar >/dev/null; done
confere "$(igual "$(pedidos '/cdn/texto.jpg')" 3)" "3 falhas com o mesmo criativo: para de tentar"
r=$(sql "SELECT COUNT(*) AS n FROM meta_objetos WHERE nivel = 'ad' AND criativo_baixado IS NOT NULL")
confere "$(tem '"n":6' "$r")" "no fim, as 6 imagens boas baixadas, dentro do limite por busca"

echo "Miniatura: o contrato da linha (meta_criativo_linha)"
r=$(linha 333333)
confere "$(igual "$r" '{"src":"meta-criativo.php?id=333333&v=900333","tipo":"imagem"}')" "imagem: src local com o id e o v= do criativo ($r)"
confere "$(printf '%s' "$r" | php_cli '$j = json_decode(file_get_contents("php://stdin"), true); echo preg_match("/^meta-criativo\.php\?[A-Za-z0-9=&._-]+$/", $j["src"]) ? "sim" : "nao";' | grep -q sim; echo $?)" "o src casa com o formato que a tela aceita"
confere "$(igual "$(linha 222222)" '{"src":null,"tipo":"video"}')" "vídeo sem miniatura: src null com o tipo"
confere "$(igual "$(linha 444002)" '{"src":null,"tipo":"imagem"}')" "imagem que não baixou: src null com o tipo"
confere "$(igual "$(linha 111111)" 'null')" "conjunto (não é anúncio): null"
confere "$(igual "$(linha 999999)" 'null')" "anúncio desconhecido: null"

echo "Miniatura: troca de criativo e Meta que recusa os campos"
cenario criativos troca
sincronizar >/dev/null
confere "$(igual "$(linha 333333)" '{"src":"meta-criativo.php?id=333333&v=900334","tipo":"imagem"}')" "anúncio que trocou de criativo: baixa de novo e o v= muda"
confere "$(igual "$(pedidos '/cdn/b333333.jpg')" 1)" "a imagem nova foi pedida uma vez"
cenario criativos troca recusa_criativo
r=$(sincronizar)
confere "$(tem '"ok":true' "$r")" "Meta recusa os campos do criativo: a busca do gestor continua"
confere "$(tem 'recusou os campos do criativo' "$(php_cli "echo ajuste('meta_criativo_erro');")")" "e o motivo fica guardado"
confere "$(igual "$(linha 333333)" '{"src":"meta-criativo.php?id=333333&v=900334","tipo":"imagem"}')" "a miniatura que já estava continua"
cenario criativos troca bomba
r=$(sincronizar)
confere "$(tem '"ok":true' "$r")" "imagem de 40 MP em poucos bytes (PNG): a busca termina bem, sem derrubar o PHP"
confere "$(igual "$(pedidos '/cdn/bomba.jpg')" 1)" "(controle) a imagem foi pedida"
r=$(sql "SELECT criativo_falhas, criativo_baixado FROM meta_objetos WHERE id = '444009'")
confere "$(tem '"criativo_falhas":1,"criativo_baixado":null' "$r")" "recusada pelo tamanho antes de abrir, e a falha fica contada ($r)"
confere "$(tem 'grande demais (6320x6320)' "$(php_cli "echo ajuste('meta_criativo_erro');")")" "o motivo diz o tamanho"
confere "$([ ! -e "$DADOS/meta-criativo/444009.jpg" ]; echo $?)" "nada gravado"
r=$(cd "$RAIZ" && "$PHP" $PHP_FLAGS -d memory_limit=64M -r "require 'lib/util.php'; require_once 'lib/meta_criativo.php'; echo meta_criativo_pixels_max();")
confere "$([ "$r" -gt 1000000 ] && [ "$r" -lt 7000000 ]; echo $?)" "com 64 MB de memória, o teto cai para o que cabe ($r pixels)"
r=$(cd "$RAIZ" && "$PHP" $PHP_FLAGS -d memory_limit=-1 -r "require 'lib/util.php'; require_once 'lib/meta_criativo.php'; echo meta_criativo_pixels_max();")
confere "$(igual "$r" 12000000)" "sem limite de memória, o teto é 12 MP"
cenario criativos troca

echo "Miniatura: meta-criativo.php"
code=$(curl -s -o /dev/null -w '%{http_code}' "$URL/meta-criativo.php?id=333333&v=900334")
confere "$(igual "$code" 403)" "sem login: 403"
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$J2" "$URL/meta-criativo.php?id=333333&v=900334")
confere "$(igual "$code" 403)" "usuário sem o acesso UTM: 403"
h=$(curl -s -D - -o "$DADOS/baixada.jpg" -b "$JAR" "$URL/meta-criativo.php?id=333333&v=900334")
confere "$(tem 'Content-Type: image/jpeg' "$h")" "com login e acesso UTM: a imagem (JPEG)"
confere "$(tem 'Cache-Control: private' "$h")" "cache só no navegador de quem entrou (private)"
confere "$(cmp -s "$DADOS/baixada.jpg" "$DADOS/meta-criativo/333333.jpg"; echo $?)" "o arquivo é o guardado"
for id in '..%2Fconfig' '333333.jpg' '222222' '12345' '' '333333%0A'; do
  code=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$URL/meta-criativo.php?id=$id")
  confere "$(igual "$code" 404)" "id '$id': 404"
done
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$URL/meta-criativo.php?id%5B%5D=333333")
confere "$(igual "$code" 404)" "id em lista (id[]=): 404"
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$URL/meta-criativo.php?id=333333&tam=g")
confere "$(igual "$code" 404)" "imagem maior antes de abrir a prévia: 404"

echo "Prévia: meta-previa.php"
code=$(curl -s -o "$DADOS/r.json" -w '%{http_code}' "$URL/meta-previa.php?id=333333")
confere "$(igual "$code" 403)" "sem login: 403"
confere "$(tem '"ok":false' "$(cat "$DADOS/r.json")")" "com {ok: false, erro}"
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$J2" "$URL/meta-previa.php?id=333333")
confere "$(igual "$code" 403)" "usuário sem o acesso UTM: 403"
for id in 999999 abc '111111'; do
  code=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$URL/meta-previa.php?id=$id")
  confere "$(igual "$code" 404)" "id '$id' (não é anúncio conhecido): 404"
done
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$URL/meta-previa.php?id%5B%5D=333333&formato%5B%5D=feed")
confere "$(igual "$code" 404)" "id e formato em lista: 404, sem aviso do PHP"
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$URL/meta-previa.php?id=333333%0A")
confere "$(igual "$code" 404)" "id com quebra de linha no fim: 404"
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -X POST "$URL/meta-previa.php?id=333333")
confere "$(igual "$code" 405)" "POST: 405 (só leitura)"

antes=$(pedidos '^/graph/333333')
h=$(curl -s -D - -o "$DADOS/r.json" -b "$JAR" "$URL/meta-previa.php?id=333333")
r=$(cat "$DADOS/r.json")
confere "$(tem 'application/json' "$h")" "resposta JSON"
confere "$(tem 'Cache-Control: no-store' "$h")" "sem cache no navegador (no-store)"
confere "$(igual "$(pedidos '^/graph/333333')" $((antes + 1)))" "uma chamada só traz status, textos e as prévias"
confere "$(igual "$(printf '%s' "$r" | campo status.selo)" "'campanha_pausada'")" "selo pelo effective_status (campanha pausada)"
confere "$(igual "$(printf '%s' "$r" | campo formatos)" '[{"id":"feed","nome":"Feed"},{"id":"stories","nome":"Stories"}]')" "formatos: só os que a Meta devolveu"
confere "$(igual "$(printf '%s' "$r" | campo formato)" "'feed'")" "sem formato pedido: o primeiro"
confere "$(igual "$(printf '%s' "$r" | campo iframe_src)" "'https://www.facebook.com/ads/api/preview_iframe.php?d=AQ333333-instagram_standard&t=AQteste'")" "iframe_src: só o endereço da prévia da Meta (sem o HTML, &amp; já decodificado)"
confere "$(igual "$(printf '%s' "$r" | campo iframe_tam)" '[540,690]')" "iframe_tam"
confere "$(igual "$(printf '%s' "$r" | campo reserva.texto)" "'<script>alert(1)</script> Projeto pronto
em duas linhas'")" "texto principal em texto puro (a tela põe com textContent), com a quebra de linha"
confere "$(igual "$(printf '%s' "$r" | campo reserva.titulo)" "'Casa térrea 10x20'")" "título"
confere "$(igual "$(printf '%s' "$r" | campo reserva.descricao)" "'Plantas e 3D'")" "descrição"
confere "$(igual "$(printf '%s' "$r" | campo reserva.botao)" "'Saiba mais'")" "botão em português"
confere "$(igual "$(printf '%s' "$r" | campo reserva.tipo)" "'carrossel'")" "o carrossel se descobre ao abrir a prévia"
confere "$(igual "$(printf '%s' "$r" | campo reserva.gerenciador)" "'https://adsmanager.facebook.com/adsmanager/manage/ads?act=587364236934346&selected_ad_ids=333333'")" "link do Gerenciador"
confere "$(igual "$(printf '%s' "$r" | campo reserva.img)" "'meta-criativo.php?id=333333&tam=g&v=900334'")" "imagem maior baixada ao abrir a prévia"
confere "$(nao_tem 'page_id\|300300\|object_story_spec' "$r")" "nada do criativo cru nem id de página sai na resposta"
r2=$(php_cli "\$i = getimagesize('$(win "$DADOS/meta-criativo/333333-g.jpg" | sed 's/\\/\\\\/g')'); echo \$i[0] . 'x' . \$i[1];")
confere "$(igual "$r2" '600x600')" "a imagem maior tem até 600 px ($r2)"
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$URL/meta-criativo.php?id=333333&tam=g&v=900334")
confere "$(igual "$code" 200)" "a imagem maior sai por meta-criativo.php"
confere "$(igual "$(linha 333333)" '{"src":"meta-criativo.php?id=333333&v=900334","tipo":"carrossel"}')" "a linha passa a mostrar carrossel"
sincronizar >/dev/null
confere "$(igual "$(linha 333333)" '{"src":"meta-criativo.php?id=333333&v=900334","tipo":"carrossel"}')" "a busca seguinte não desfaz o carrossel"

r=$(previa "id=333333&formato=stories")
confere "$(igual "$(pedidos '^/graph/333333')" $((antes + 1)))" "trocar de formato dentro de 10 minutos: nenhuma chamada a mais"
confere "$(igual "$(printf '%s' "$r" | campo iframe_tam)" '[320,570]')" "Stories com o tamanho dele"
r=$(previa "id=333333&formato=reels")
confere "$(igual "$(printf '%s' "$r" | campo iframe_src)" 'NULL')" "formato que a Meta não devolveu: iframe null"
confere "$(tem '"titulo":"Casa térrea 10x20"' "$r")" "e a reserva continua lá"
previa "id=333333" >/dev/null
confere "$(igual "$(pedidos '^/graph/333333')" $((antes + 1)))" "voltar ao mesmo anúncio pelas setas: nenhuma chamada a mais"

r=$(previa "id=222222")
confere "$(igual "$(printf '%s' "$r" | campo status)" '{"selo":"ativo","texto":"Ativo"}')" "anúncio ativo: selo Ativo"
confere "$(igual "$(printf '%s' "$r" | campo formatos)" '[{"id":"feed","nome":"Feed"},{"id":"reels","nome":"Reels"}]')" "vídeo: Feed e Reels"
confere "$(igual "$(printf '%s' "$r" | campo reserva.botao)" "'Comprar agora'")" "botão do vídeo"
confere "$(igual "$(printf '%s' "$r" | campo reserva.tipo)" "'video'")" "tipo vídeo"
confere "$(igual "$(printf '%s' "$r" | campo reserva.img)" "'meta-criativo.php?id=222222&tam=g&v=900222'")" "vídeo sem miniatura na listagem: a imagem maior vem da prévia"
r=$(sql "SELECT status_efetivo FROM meta_objetos WHERE id = '333333'")
confere "$(tem 'CAMPAIGN_PAUSED' "$r")" "o status lido ao abrir vale também para o gestor"

echo "Prévia: modo separado, endereço estranho e pausa da Meta"
php_cli "track_db()->exec('DELETE FROM meta_previas');"
cenario criativos troca sem_junto
antes=$(pedidos '^/graph/222222')
r=$(previa "id=222222")
confere "$(tem '"ok":true' "$r")" "Meta recusa o pedido junto: a prévia sai pelo modo separado"
confere "$(igual "$(printf '%s' "$r" | campo iframe_src)" "'https://www.facebook.com/ads/api/preview_iframe.php?d=AQ222222-instagram_standard&t=AQteste'")" "feed pelo /previews"
confere "$(igual "$(printf '%s' "$r" | campo formatos)" '[{"id":"feed","nome":"Feed"},{"id":"stories","nome":"Stories"},{"id":"reels","nome":"Reels"}]')" "no modo separado, o que ainda não foi pedido aparece"
confere "$(tem '[0-9]' "$(php_cli "echo ajuste('meta_previa_separado_ate');")")" "o modo separado fica valendo por um dia"
r=$(previa "id=222222&formato=stories")
confere "$(igual "$(printf '%s' "$r" | campo iframe_src)" 'NULL')" "formato que a Meta recusa: iframe null, reserva"
confere "$(igual "$(printf '%s' "$r" | campo formatos)" '[{"id":"feed","nome":"Feed"},{"id":"reels","nome":"Reels"}]')" "e ele sai da lista"
n=$(pedidos '^/graph/222222')
previa "id=222222&formato=stories" >/dev/null
confere "$(igual "$(pedidos '^/graph/222222')" "$n")" "o formato recusado não é pedido de novo dentro de 10 minutos"
php_cli "track_db()->exec('DELETE FROM meta_previas'); definir_ajuste('meta_previa_separado_ate', null);"
cenario criativos troca iframe_mau
r=$(previa "id=333333")
confere "$(igual "$(printf '%s' "$r" | campo formatos)" '[{"id":"stories","nome":"Stories"}]')" "feed com endereço que não é o da Meta: recusado, sai da lista"
confere "$(igual "$(printf '%s' "$r" | campo formato)" "'stories'")" "e o popup abre no próximo formato bom"
r=$(previa "id=333333&formato=feed")
confere "$(igual "$(printf '%s' "$r" | campo iframe_src)" 'NULL')" "pedindo o feed: iframe null"
confere "$(tem '"titulo":"Casa térrea 10x20"' "$r")" "e a reserva aparece"
php_cli "track_db()->exec('DELETE FROM meta_previas'); definir_ajuste('meta_api_pausa_ate', (string)(time() + 600));"
n=$(pedidos '^/graph/')
r=$(previa "id=333333")
confere "$(igual "$(pedidos '^/graph/')" "$n")" "Meta em pausa: nenhuma chamada"
confere "$(tem '"aviso":"A Meta pediu uma pausa' "$r")" "o aviso diz por quê"
confere "$(igual "$(printf '%s' "$r" | campo status.selo)" "'campanha_pausada'")" "o status vem do que já está guardado"
confere "$(igual "$(printf '%s' "$r" | campo reserva.img)" "'meta-criativo.php?id=333333&tam=g&v=900334'")" "a imagem guardada aparece: o anúncio nunca some"
php_cli "definir_ajuste('meta_api_pausa_ate', null);"

echo "Prévia: o limite da Meta é dividido com a busca e o ligar e pausar"
php_cli "track_db()->exec('DELETE FROM meta_previas'); \$st = track_db()->prepare(\"INSERT INTO limites (chave, em) VALUES ('meta-api', ?)\"); for (\$i = 0; \$i < 190; \$i++) { \$st->execute([time()]); }"
n=$(pedidos '^/graph/')
r=$(previa "id=222222")
confere "$(igual "$(pedidos '^/graph/')" "$n")" "sem folga no limite por minuto (190 de 200 usados): a prévia não chama a Meta"
confere "$(tem 'ocupada com a busca' "$r")" "o aviso diz por quê, e a reserva aparece ($(printf '%s' "$r" | campo reserva.tipo))"
php_cli "track_db()->exec(\"DELETE FROM limites WHERE chave = 'meta-api'\");"
php_cli "track_db()->exec(\"INSERT INTO meta_objetos (id, nivel, nome, status, status_efetivo) VALUES ('555001', 'ad', 'apagado', 'ARCHIVED', 'ARCHIVED')\"); definir_ajuste('meta_previa_separado_ate', null);"
n=$(pedidos '^/graph/555001')
r=$(previa "id=555001")
confere "$(tem '"ok":true' "$r")" "anúncio que a Meta não mostra (apagado): a prévia responde com a reserva"
confere "$(igual "$(printf '%s' "$r" | campo status.selo)" "'arquivado'")" "status guardado: arquivado"
confere "$(igual "$(pedidos '^/graph/555001')" $((n + 3)))" "tentou o pedido junto e os detalhes (3 chamadas)"
confere "$(igual "$(php_cli "echo (int)(ajuste('meta_previa_separado_ate') ?? 0) > time() ? 'ligado' : 'desligado';")" 'desligado')" "o erro de um anúncio não liga o modo separado para todos"
previa "id=555001" >/dev/null
confere "$(igual "$(pedidos '^/graph/555001')" $((n + 3)))" "a falha fica guardada 10 minutos: abrir de novo não chama a Meta"

echo "CSP"
# O frame-src vem num commit separado (o seguranca decide): sem ele, a tela usa a reserva. Com ele, so a Meta.
fs=$(grep -o "frame-src [^;]*;" "$RAIZ/.htaccess")
confere "$([ -z "$fs" ] || [ "$fs" = "frame-src https://www.facebook.com/ads/api/preview_iframe.php;" ]; echo $?)" "frame-src ausente ou só o caminho da prévia da Meta (${fs:-ausente})"
confere "$(grep -q "img-src 'self' data:;" "$RAIZ/.htaccess"; echo $?)" "img-src continua 'self' data:"

echo "Tela do popup e da linha (lib/layout.php e painel.js)"
# Revisao de tela (14/11/2026, QA da Conferencia): os alvos de toque medem 44 px de verdade, a reserva fica aberta por padrao e
# as regras que a seguranca aprovou continuam valendo (texto da Meta so com textContent, iframe sem popups e sem referrer).
LAYOUT="$RAIZ/lib/layout.php"; PAINEL="$RAIZ/painel.js"
tem_css() { grep -Eq "$1" "$LAYOUT"; echo $?; }
confere "$(tem_css '\.ver-anuncio\{[^}]*width:44px; height:44px')" "o olho do anúncio mede 44 x 44"
confere "$(tem_css '\.criativo-mini\{[^}]*width:44px; height:44px')" "a miniatura mede 44 x 44"
confere "$(tem_css '\.ap-nav \.botao-icone, \.anuncio-pop \.ap-fechar\{ width:44px; height:44px')" "setas e fechar do popup medem 44 x 44 no computador"
confere "$(tem_css '\.ap-formatos button\{ min-height:44px')" "as abas de formato têm 44 px de altura"
confere "$(tem_css '\.ap-ger\{[^}]*min-height:44px')" "\"Abrir no Gerenciador\" tem 44 px de altura"
confere "$(tem_css 'hover:none\), \(max-width:767px\)\{ \.tabela\.gestor \.ver-anuncio\{ opacity:1')" "o olho fica sempre à vista sem mouse e em tela estreita"
confere "$(tem_css 'pointer:coarse\), \(max-width:767px\)\{')" "a linha do anúncio empilha também em tela estreita (o nome não quebra)"
confere "$(grep -q "d.open = true" "$PAINEL"; echo $?)" "a reserva (imagem e textos do criativo) fica aberta por padrão"
confere "$(grep -q "sandbox', 'allow-scripts allow-same-origin'" "$PAINEL"; echo $?)" "o iframe mantém o sandbox sem popups"
confere "$(grep -q "referrerpolicy', 'no-referrer'" "$PAINEL"; echo $?)" "o iframe mantém o referrerpolicy no-referrer"
confere "$(grep -q "PREVIA_META = /^https:" "$PAINEL"; echo $?)" "a checagem do endereço da prévia (PREVIA_META) continua"
confere "$(grep -q "vizinho.focus()" "$PAINEL"; echo $?)" "o foco passa ao outro botão antes de um deles ser desabilitado (as setas do teclado seguem valendo)"
confere "$(grep -q "if (pop.open) { return; } // o evento chega depois" "$PAINEL"; echo $?)" "o evento close nao esvazia um popup que ja reabriu"
confere "$(grep -q "\.tabela\.gestor \.menu-linha-painel button{ min-height:44px; }" "$LAYOUT"; echo $?)" "os itens dos menus das acoes e da linha (com o Abrir no Gerenciador) tem 44 px de alvo"
confere "$(grep -q "(pointer:coarse), (max-width:767px){ \.gestor-menu \.menu-linha-painel a" "$LAYOUT"; echo $?)" "o menu Ordenar tem 44 px por item so no celular e no toque"
confere "$(grep -q "if (!e.repeat) { apAndar(-1); }" "$PAINEL" && grep -q "if (!e.repeat) { apAndar(1); }" "$PAINEL"; echo $?)" "segurar a seta nao repete a troca de anuncio (nao gasta o limite de aberturas da previa)"
confere "$(grep -q "\.gestor-ferr{ --alt:44px; }" "$LAYOUT"; echo $?)" "no toque e no celular os botoes da barra do gestor (Colunas, Ordenar, Acoes...) tem 44 px"
confere "$(grep -q "\.linha-acoes details\.menu-linha > summary{ width:44px; height:44px; }" "$LAYOUT"; echo $?)" "no toque e no celular o ... da linha tem a caixa de 44 x 44"
confere "$(grep -q "\.gestor-menu \.menu-linha-painel, \.tabela\.gestor \.menu-linha-painel{ max-height:calc(100vh - 110px); overflow-y:auto;" "$LAYOUT"; echo $?)" "os menus do gestor rolam por dentro em tela baixa (nenhum item fora do alcance)"
confere "$(grep -q "ouvir(document, 'keydown', function (e) {" "$PAINEL" && awk '/As setas andam entre os anuncios/,/apAndar\(1\)/' "$PAINEL" | grep -q "pop.open"; echo $?)" "as setas do teclado andam entre os anúncios com o popup aberto, mesmo com o foco no body"
confere "$(grep -q "data-ver-anuncio') && window.matchMedia" "$PAINEL"; echo $?)" "o tooltip do olho não aparece em tela estreita"
confere "$(tem_css 'html:has\(\.nav-celular\)\{ scroll-padding-bottom')" "a barra do polegar não esconde o que recebe o foco (scroll-padding-bottom)"
conta_html=$(awk '/function apReserva\(/,/function apEscala\(/' "$PAINEL" | grep -c "innerHTML")
confere "$(igual "$conta_html" 0)" "o popup não usa innerHTML: tudo o que vem da Meta entra com textContent"
css_ap=$(grep -E '\.ap-alt|\.ver-anuncio|\.criativo-mini' "$LAYOUT")
confere "$(printf '%s' "$css_ap" | grep -Eqi 'url\(|@import|https?:|<script'; echo $((1 - $?)))" "o CSS novo do popup não tem url(), @import, endereço http nem script"

echo "Busca em segundo plano e menu lateral (painel.js)"
confere "$([ "$(grep -c "var parar = " "$PAINEL")" = 1 ]; echo $?)" "so existe uma \"var parar =\" (a do orcamento em massa); a da sincronizacao nao e mais sobrescrita"
confere "$(grep -q "var pararGiro = function" "$PAINEL"; echo $?)" "a sincronizacao tem a propria funcao (pararGiro)"
confere "$([ "$(grep -c "pararGiro();" "$PAINEL")" = 2 ]; echo $?)" "pararGiro roda no fim da busca e no erro dela"
confere "$(grep -q "querySelectorAll(.noscript.), function (ns)" "$PAINEL"; echo $?)" "a troca do corpo tira os <noscript> (o style do menu lateral nao passa a valer)"
confere "$(grep -q "adiado = semAncora(location.href)" "$PAINEL" && grep -q "e.target.id === .anuncio-pop." "$PAINEL"; echo $?)" "a troca do corpo da atualizacao em segundo plano espera o popup do anuncio fechar"
confere "$(grep -q "op.manter && op.substituir && popAberto && popAberto.open" "$PAINEL"; echo $?)" "so a atualizacao em segundo plano adia (manter + substituir)"

echo "Avisos do PHP"
avisos=$(grep -E 'PHP (Warning|Notice|Deprecated|Fatal)' "$DADOS/php-erros.log" 2>/dev/null | sed 's/^\[[^]]*\] //' | sort -u | head -5)
confere "$([ -z "$avisos" ]; echo $?)" "nenhum aviso do PHP no servidor${avisos:+: $avisos}"

echo
if [ "$FALHAS" -eq 0 ]; then echo "TUDO OK"; else echo "$FALHAS FALHA(S)"; fi
exit "$FALHAS"
