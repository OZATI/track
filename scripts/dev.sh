#!/usr/bin/env bash
# Painel local para trabalhar na tela e no servidor: http://127.0.0.1:3001
# Usa a Meta, a Kiwify e o Instagram simulados dos testes (tests/*-falsa.php): nao toca em
# nenhum site, conta ou API real. Os dados ficam fora do repositorio, em ../track-dados-dev
# (como no servidor, fora da pasta publica). Apagar essa pasta recomeca do zero.
#
# Uso:  bash scripts/dev.sh                   (php no PATH)
#       PHP=/caminho/php.exe bash scripts/dev.sh
# Na primeira vez instala e semeia vendas de exemplo. Entrar: usuario kenio, senha senha-local-123.

set -u
PHP="${PHP:-php}"
RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
PORTA="${PORTA:-3001}"
URL="http://127.0.0.1:$PORTA"
DADOS="${DADOS_DEV:-$RAIZ/../track-dados-dev}"
mkdir -p "$DADOS"
DADOS="$(cd "$DADOS" && pwd)"
JAR="$DADOS/cookies-dev.txt"

# PHP portatil do Windows: as extensoes ficam na pasta ext ao lado do php.exe
if [ -z "${PHP_FLAGS:-}" ]; then
  EXT="$(dirname "$(command -v "$PHP")")/ext"
  PHP_FLAGS=""
  if [ -d "$EXT" ]; then
    PHP_FLAGS="-d extension_dir=$(cygpath -w "$EXT" 2>/dev/null || echo "$EXT") -d extension=pdo_sqlite -d extension=mbstring -d extension=curl -d extension=openssl"
  fi
fi

# No Git Bash do Windows, o PHP nativo precisa do caminho no formato do Windows
export TRACK_DADOS="$(cygpath -w "$DADOS" 2>/dev/null || echo "$DADOS")"
export TRACK_KIWIFY_API="http://127.0.0.1:$((PORTA + 1))/v1"
export TRACK_META_API="http://127.0.0.1:$((PORTA + 2))/graph"
export TRACK_IG_API="http://127.0.0.1:$((PORTA + 3))/ig"
export TRACK_IG_MIDIA_HOST="127.0.0.1:$((PORTA + 3))"
export TRACK_META_LIMITE_MINUTO=200
export TRACK_IG_LIMITE_MINUTO=200

# shellcheck disable=SC2086
"$PHP" $PHP_FLAGS -S "127.0.0.1:$((PORTA + 1))" "$RAIZ/tests/kiwify-falsa.php" >"$DADOS/kiwify-falsa.log" 2>&1 &
FALSAS=$!
# shellcheck disable=SC2086
"$PHP" $PHP_FLAGS -S "127.0.0.1:$((PORTA + 2))" "$RAIZ/tests/meta-falsa.php" >"$DADOS/meta-falsa.log" 2>&1 &
FALSAS="$FALSAS $!"
# shellcheck disable=SC2086
"$PHP" $PHP_FLAGS -S "127.0.0.1:$((PORTA + 3))" "$RAIZ/tests/instagram-falsa.php" >"$DADOS/instagram-falsa.log" 2>&1 &
FALSAS="$FALSAS $!"
# shellcheck disable=SC2086
"$PHP" $PHP_FLAGS -d display_errors=1 -S "127.0.0.1:$PORTA" -t "$RAIZ" &
SERVIDOR=$!
# shellcheck disable=SC2086
trap 'kill $SERVIDOR $FALSAS 2>/dev/null' EXIT INT TERM
sleep 1

csrf_de() { grep -o 'name="csrf" value="[a-f0-9]*"' | head -1 | grep -o '[a-f0-9]\{32\}'; }

if [ ! -f "$DADOS/config.php" ]; then
  echo "Primeira vez: instalando e semeando vendas de exemplo"
  csrf=$(curl -s -c "$JAR" -b "$JAR" "$URL/instalar.php" | csrf_de)
  r=$(curl -s -c "$JAR" -b "$JAR" --data-urlencode "csrf=$csrf" --data-urlencode "usuario=kenio" --data-urlencode "senha=senha-local-123" \
      --data-urlencode "senha2=senha-local-123" --data-urlencode $'origens=http://site.test\nhttp://127.0.0.1:3000' --data-urlencode "dias=90" "$URL/instalar.php")
  CHAVE=$(printf '%s\n' "$r" | grep -o 'chave=[a-f0-9]*' | head -1 | cut -d= -f2)
  venda() { # pedido, valor em centavos, pagamento, campanha, conjunto, anuncio
    curl -s -o /dev/null --data "{\"order_id\":\"$1\",\"order_status\":\"paid\",\"webhook_event_type\":\"order_approved\",\"payment_method\":\"$3\",
      \"Product\":{\"product_name\":\"Drive de Projetos 2.0\"},\"Commissions\":{\"charge_amount\":$2},
      \"TrackingParameters\":{\"utm_source\":\"MetaAds\",\"utm_campaign\":\"$4\",\"utm_medium\":\"$5\",\"utm_content\":\"$6\",\"utm_term\":\"Instagram_Reels\"}}" "$URL/kiwify.php?chave=$CHAVE"
  }
  # TL 1: R$ 50 de gasto e 2 vendas de R$ 67 (CPA R$ 25, dentro dos 60% do ticket: verde)
  venda dev-1 6700 pix "TL 1|120120" "conjunto 5|111111" "cv 05|222222"
  venda dev-2 6700 credit_card "TL 1|120120" "conjunto 5|111111" "cv 05|222222"
  # FREE: R$ 10 de gasto e 1 venda de R$ 15 (CPA R$ 10, acima dos 60% de R$ 15: vermelho)
  venda dev-3 1500 pix "FREE|555555" "conjunto free|444444" "cv free|333333"
  curl -s -o /dev/null --data '{"order_id":"dev-4","order_status":"paid","payment_method":"pix","Product":{"product_name":"Drive de Projetos 2.0"},"Commissions":{"charge_amount":6700},"TrackingParameters":{"utm_source":"organico","utm_medium":"instagram-bio"}}' "$URL/kiwify.php?chave=$CHAVE"
  # Entra, liga a Meta simulada (token so de leitura) e busca o gasto
  csrf=$(curl -s -c "$JAR" -b "$JAR" "$URL/entrar.php" | csrf_de)
  curl -s -o /dev/null -c "$JAR" -b "$JAR" --data-urlencode "csrf=$csrf" --data-urlencode "usuario=kenio" --data-urlencode "senha=senha-local-123" "$URL/entrar.php"
  csrf=$(curl -s -b "$JAR" "$URL/meta-api.php" | csrf_de)
  curl -s -o /dev/null -b "$JAR" --data-urlencode "csrf=$csrf" --data-urlencode "acao=salvar" --data-urlencode "token=TokenLeitura0000000000000000000000000000000000" \
      --data-urlencode "conta=act_587364236934346" "$URL/meta-api.php"
  curl -s -o /dev/null -b "$JAR" --data-urlencode "csrf=$csrf" --data-urlencode "volta=./?aba=gestor&periodo=tudo" "$URL/sincronizar.php"
  echo "Pronto."
fi

echo "Painel local: $URL  (usuario kenio, senha senha-local-123)  ·  Ctrl+C para parar"
wait "$SERVIDOR"
