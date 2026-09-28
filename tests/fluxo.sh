#!/usr/bin/env bash
# Teste do fluxo completo, em localhost: instalacao, coleta, webhook da Kiwify, login,
# telas, filtros e protecoes. Nao toca em nenhum site real.
#
# Uso:  bash tests/fluxo.sh                 (php no PATH)
#       PHP=/caminho/php.exe PHP_FLAGS="-d extension_dir=ext -d extension=pdo_sqlite" bash tests/fluxo.sh

set -u
PHP="${PHP:-php}"
PHP_FLAGS="${PHP_FLAGS:-}"
RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
DADOS="$(mktemp -d)"
PORTA="${PORTA:-8799}"
URL="http://127.0.0.1:$PORTA"
JAR="$DADOS/cookies.txt"
FALHAS=0

confere() { if [ "$1" = "0" ]; then echo "  ok     $2"; else echo "  FALHA  $2"; FALHAS=$((FALHAS + 1)); fi; }
tem() { grep -q -- "$1" <<<"$2"; echo $?; }

# No Git Bash do Windows, o PHP nativo precisa do caminho no formato do Windows
export TRACK_DADOS="$(cygpath -w "$DADOS" 2>/dev/null || echo "$DADOS")"
# shellcheck disable=SC2086
"$PHP" $PHP_FLAGS -S "127.0.0.1:$PORTA" -t "$RAIZ" >"$DADOS/servidor.log" 2>&1 &
SERVIDOR=$!
trap 'kill $SERVIDOR 2>/dev/null; rm -rf "$DADOS"' EXIT
sleep 1

echo "Instalação"
r=$(curl -s -c "$JAR" -b "$JAR" "$URL/instalar.php")
csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' <<<"$r" | head -1 | grep -o '[a-f0-9]\{32\}')
confere "$(tem 'Instalar o painel' "$r")" "tela de instalação abre enquanto não há configuração"
r=$(curl -s -c "$JAR" -b "$JAR" --data-urlencode "csrf=$csrf" --data-urlencode "senha=senha-de-teste-123" \
    --data-urlencode "senha2=senha-de-teste-123" --data-urlencode $'origens=http://site.test\nhttps://site.test' \
    --data-urlencode "dias=30" "$URL/instalar.php")
confere "$(tem 'Painel instalado' "$r")" "instalação grava a configuração"
CHAVE=$(grep -o 'chave=[a-f0-9]*' <<<"$r" | head -1 | cut -d= -f2)
confere "$([ ${#CHAVE} -eq 48 ]; echo $?)" "chave do webhook gerada (48 caracteres)"
confere "$([ -f "$DADOS/config.php" ]; echo $?)" "config.php fora do projeto (na pasta de dados)"
code=$(curl -s -o /dev/null -w '%{http_code}' "$URL/instalar.php")
confere "$([ "$code" = "404" ]; echo $?)" "instalação fechada depois de instalado ($code)"

echo "Coleta de eventos"
ANUNCIO='"utms":{"utm_source":"MetaAds","utm_medium":"conjunto 5|111","utm_campaign":"TL 1|120","utm_content":"cv 05|222","utm_term":"Instagram_Reels"}'
r=$(curl -s -c "$JAR" -b "$JAR" -H "Origin: http://site.test" -H "User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 18_2 like Mac OS X) AppleWebKit/605.1.15 Instagram 350.0" \
    -H "X-Forwarded-For: 177.10.20.30" --data "{\"evento\":\"PageView\",\"url\":\"http://site.test/drivedeprojetos/?utm_source=MetaAds\",\"referrer\":\"https://l.instagram.com/?u=x\",$ANUNCIO}" "$URL/coletar.php")
VID=$(grep -o '"vid":"[a-f0-9]*"' <<<"$r" | cut -d'"' -f4)
confere "$([ ${#VID} -eq 32 ]; echo $?)" "PageView aceito e visitante criado"
confere "$(grep -q 'trk_vid' "$JAR"; echo $?)" "cookie trk_vid gravado pelo servidor"
r=$(curl -s -c "$JAR" -b "$JAR" -H "Origin: http://site.test" --data "{\"evento\":\"CliqueCheckout\",\"detalhe\":\"<script>alert(1)</script>\",\"url\":\"http://site.test/drivedeprojetos/\",$ANUNCIO}" "$URL/coletar.php")
VID2=$(grep -o '"vid":"[a-f0-9]*"' <<<"$r" | cut -d'"' -f4)
confere "$([ "$VID" = "$VID2" ]; echo $?)" "mesmo visitante no clique (cookie)"
curl -s -H "Origin: http://site.test" --data '{"evento":"WhatsApp","url":"http://site.test/bio/"}' "$URL/coletar.php" >/dev/null
code=$(curl -s -o /dev/null -w '%{http_code}' -H "Origin: https://invasor.test" --data '{"evento":"PageView","url":"https://invasor.test/"}' "$URL/coletar.php")
confere "$([ "$code" = "403" ]; echo $?)" "site não autorizado é recusado ($code)"
code=$(curl -s -o /dev/null -w '%{http_code}' -H "Origin: http://site.test" --data '{"evento":"PageView","url":"https://outro.test/x"}' "$URL/coletar.php")
confere "$([ "$code" = "400" ]; echo $?)" "página de outro domínio é recusada ($code)"
code=$(curl -s -o /dev/null -w '%{http_code}' -H "Origin: http://site.test" --data '{"evento":"<b>x</b>","url":"http://site.test/"}' "$URL/coletar.php")
confere "$([ "$code" = "400" ]; echo $?)" "nome de evento inválido é recusado ($code)"

echo "Webhook da Kiwify"
code=$(curl -s -o /dev/null -w '%{http_code}' --data '{"order_id":"x"}' "$URL/kiwify.php")
confere "$([ "$code" = "401" ]; echo $?)" "sem a chave é recusado ($code)"
code=$(curl -s -o /dev/null -w '%{http_code}' --data '{"order_id":"x"}' "$URL/kiwify.php?chave=errada")
confere "$([ "$code" = "401" ]; echo $?)" "chave errada é recusada ($code)"
VENDA="{\"order_id\":\"pedido-1\",\"order_status\":\"paid\",\"webhook_event_type\":\"order_approved\",\"payment_method\":\"pix\",
  \"Product\":{\"product_name\":\"Drive de Projetos 2.0\"},\"Customer\":{\"full_name\":\"Nome Real\",\"email\":\"real@exemplo.com\"},
  \"Commissions\":{\"charge_amount\":5949},
  \"TrackingParameters\":{\"sck\":\"trk_$VID\",\"utm_source\":\"MetaAds\",\"utm_medium\":\"conjunto 5|111\",\"utm_campaign\":\"TL 1|120\"}}"
code=$(curl -s -o /dev/null -w '%{http_code}' --data "$VENDA" "$URL/kiwify.php?chave=$CHAVE")
confere "$([ "$code" = "200" ]; echo $?)" "venda com o identificador gravada ($code)"
code=$(curl -s -o /dev/null -w '%{http_code}' --data "$VENDA" "$URL/kiwify.php?chave=$CHAVE")
confere "$([ "$code" = "200" ]; echo $?)" "reenvio da mesma venda não duplica ($code)"
curl -s --data '{"order_id":"pedido-2","order_status":"paid","Product":{"product_name":"Drive"},"Commissions":{"charge_amount":"67.00"},"TrackingParameters":{"utm_source":"organico","utm_medium":"whatsapp"}}' "$URL/kiwify.php?chave=$CHAVE" >/dev/null
confere "$(grep -rq 'real@exemplo.com' "$DADOS"/track.sqlite*; [ $? -ne 0 ]; echo $?)" "e-mail e nome do comprador não são gravados (LGPD)"

echo "Painel"
destino=$(curl -s -o /dev/null -w '%{redirect_url}' "$URL/index.php")
confere "$(tem 'entrar.php' "$destino")" "painel exige login (vai para $destino)"
r=$(curl -s -c "$JAR" -b "$JAR" "$URL/entrar.php")
csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' <<<"$r" | head -1 | grep -o '[a-f0-9]\{32\}')
r=$(curl -s -c "$JAR" -b "$JAR" --data-urlencode "csrf=$csrf" --data-urlencode "senha=errada-123456" "$URL/entrar.php")
confere "$(tem 'Senha incorreta' "$r")" "senha errada não entra"
csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' <<<"$r" | head -1 | grep -o '[a-f0-9]\{32\}')
destino=$(curl -s -o /dev/null -w '%{redirect_url}' -c "$JAR" -b "$JAR" --data-urlencode "csrf=$csrf" --data-urlencode "senha=senha-de-teste-123" "$URL/entrar.php")
confere "$([ "${destino%/}" = "$URL" ]; echo $?)" "login com a senha certa (vai para $destino)"
r=$(curl -s -b "$JAR" "$URL/index.php?periodo=tudo")
confere "$(tem 'Vendas em que os dados batem' "$r")" "tela de conferência abre"
confere "$(tem '>1 (50%)<' "$r")" "uma venda bate e a outra fica sem visitante (50%)"
confere "$(tem 'Sem visitante' "$r")" "venda sem identificador aparece como 'Sem visitante'"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=vendas&periodo=tudo")
confere "$(tem 'R$ 59,49' "$r")" "valor em centavos exibido em reais"
confere "$(tem 'R$ 67,00' "$r")" "valor com ponto decimal exibido em reais"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=eventos&periodo=tudo")
confere "$(tem '&lt;script&gt;alert(1)&lt;/script&gt;' "$r")" "texto vindo da página é escapado (sem XSS)"
confere "$(grep -q '<script>alert(1)' <<<"$r"; [ $? -ne 0 ]; echo $?)" "nenhum script injetado na tela"
confere "$(tem '177.10.20.0' "$r")" "IP guardado parcial (último número zerado)"
confere "$(tem 'iPhone · Instagram (app)' "$r")" "aparelho e navegador reconhecidos"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=eventos&periodo=tudo&dominio=site.test")
confere "$(tem '<option value="/drivedeprojetos/"' "$r")" "caixa de páginas lista as páginas do domínio escolhido"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=eventos&periodo=tudo&dominio=site.test&pagina=/bio/")
confere "$(tem 'WhatsApp' "$r")" "filtro por página mostra os eventos dela"
confere "$(grep -q 'CliqueCheckout</strong>' <<<"$r"; [ $? -ne 0 ]; echo $?)" "filtro por página esconde os eventos das outras"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=visitantes&periodo=tudo&v=$VID")
confere "$(tem 'Linha do tempo (2 eventos)' "$r")" "detalhe do visitante com a linha do tempo"
confere "$(tem 'Bate' "$r")" "detalhe do visitante mostra a venda conferida"

echo "Proteções"
for i in 1 2 3 4 5 6; do r=$(curl -s -c "$DADOS/j2" -b "$DADOS/j2" "$URL/entrar.php"); c=$(grep -o '[a-f0-9]\{32\}' <<<"$r" | head -1); r=$(curl -s -c "$DADOS/j2" -b "$DADOS/j2" --data "csrf=$c&senha=errada" "$URL/entrar.php"); done
confere "$(tem 'Muitas tentativas' "$r")" "login bloqueia depois de 5 senhas erradas"

echo
if [ "$FALHAS" -eq 0 ]; then echo "TUDO OK"; else echo "$FALHAS FALHA(S)"; echo "--- log do servidor:"; tail -20 "$DADOS/servidor.log"; fi
exit "$FALHAS"
