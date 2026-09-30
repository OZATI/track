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
# Texto como aparece na tela (sem as tags e com espaco no lugar de &nbsp;)
sem_tags() { sed -e 's/<[^>]*>//g' -e 's/&nbsp;/ /g' <<<"$1"; }

# No Git Bash do Windows, o PHP nativo precisa do caminho no formato do Windows
export TRACK_DADOS="$(cygpath -w "$DADOS" 2>/dev/null || echo "$DADOS")"
# API da Kiwify falsa (tests/kiwify-falsa.php): o painel nunca fala com a Kiwify de verdade
PORTA_API=$((PORTA + 1))
export TRACK_KIWIFY_API="http://127.0.0.1:$PORTA_API/v1"
# shellcheck disable=SC2086
"$PHP" $PHP_FLAGS -S "127.0.0.1:$PORTA_API" "$RAIZ/tests/kiwify-falsa.php" >"$DADOS/api-falsa.log" 2>&1 &
API_FALSA=$!
# Graph API da Meta falsa (tests/meta-falsa.php)
PORTA_META=$((PORTA + 2))
export TRACK_META_API="http://127.0.0.1:$PORTA_META/graph"
# shellcheck disable=SC2086
"$PHP" $PHP_FLAGS -S "127.0.0.1:$PORTA_META" "$RAIZ/tests/meta-falsa.php" >"$DADOS/meta-falsa.log" 2>&1 &
META_FALSA=$!
# API do Instagram falsa (tests/instagram-falsa.php), com limite folgado para a busca inteira
PORTA_IG=$((PORTA + 3))
export TRACK_IG_API="http://127.0.0.1:$PORTA_IG/ig"
export TRACK_IG_LIMITE_MINUTO=200
# Limite folgado para a Meta falsa: a sequencia de testes passa das 30 consultas por minuto
export TRACK_META_LIMITE_MINUTO=200
export TRACK_IG_MIDIA_HOST="127.0.0.1:$PORTA_IG"
# shellcheck disable=SC2086
"$PHP" $PHP_FLAGS -S "127.0.0.1:$PORTA_IG" "$RAIZ/tests/instagram-falsa.php" >"$DADOS/instagram-falsa.log" 2>&1 &
IG_FALSA=$!
# O PHP portatil do Windows precisa do openssl.cnf para criar as chaves das notificacoes
if [ -z "${OPENSSL_CONF:-}" ]; then
  CNF="$(dirname "$(command -v "$PHP")")/extras/ssl/openssl.cnf"
  if [ -f "$CNF" ]; then export OPENSSL_CONF="$(cygpath -w "$CNF" 2>/dev/null || echo "$CNF")"; fi
fi
# Servico de push falso (tests/push-falso.php): as notificacoes nunca saem para o Google ou a Apple
PORTA_PUSH=$((PORTA + 4))
export TRACK_PUSH_HOST="127.0.0.1:$PORTA_PUSH"
export PUSH_FALSO_DIR="$TRACK_DADOS"
# shellcheck disable=SC2086
"$PHP" $PHP_FLAGS -S "127.0.0.1:$PORTA_PUSH" "$RAIZ/tests/push-falso.php" >"$DADOS/push-falso.log" 2>&1 &
PUSH_FALSO=$!
# shellcheck disable=SC2086
"$PHP" $PHP_FLAGS -S "127.0.0.1:$PORTA" -t "$RAIZ" >"$DADOS/servidor.log" 2>&1 &
SERVIDOR=$!
trap 'kill $SERVIDOR $API_FALSA $META_FALSA $IG_FALSA $PUSH_FALSO 2>/dev/null; rm -rf "$DADOS"' EXIT
sleep 1

echo "Instalação"
r=$(curl -s -c "$JAR" -b "$JAR" "$URL/instalar.php")
csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' <<<"$r" | head -1 | grep -o '[a-f0-9]\{32\}')
confere "$(tem 'Instalar o painel' "$r")" "tela de instalação abre enquanto não há configuração"
r=$(curl -s -c "$JAR" -b "$JAR" --data-urlencode "csrf=$csrf" --data-urlencode "usuario=Kenio" --data-urlencode "senha=senha-de-teste-123" \
    --data-urlencode "senha2=senha-de-teste-123" --data-urlencode $'origens=http://site.test\nhttps://site.test' \
    --data-urlencode "dias=30" --data-urlencode "menu_cms=../" "$URL/instalar.php")
confere "$(tem 'Painel instalado' "$r")" "instalação grava a configuração"
CHAVE=$(grep -o 'chave=[a-f0-9]*' <<<"$r" | head -1 | cut -d= -f2)
confere "$([ ${#CHAVE} -eq 48 ]; echo $?)" "chave do webhook gerada (48 caracteres)"
confere "$([ -f "$DADOS/config.php" ]; echo $?)" "config.php fora do projeto (na pasta de dados)"
code=$(curl -s -o /dev/null -w '%{http_code}' "$URL/instalar.php")
confere "$([ "$code" = "404" ]; echo $?)" "instalação fechada depois de instalado ($code)"

echo "Coleta de eventos"
ANUNCIO='"utms":{"utm_source":"MetaAds","utm_medium":"conjunto 5|111111","utm_campaign":"TL 1|120120","utm_content":"cv 05|222222","utm_term":"Instagram_Reels"}'
r=$(curl -s -c "$JAR" -b "$JAR" -H "Origin: http://site.test" -H "User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 18_2 like Mac OS X) AppleWebKit/605.1.15 Instagram 350.0" \
    -H "X-Forwarded-For: 177.10.20.30" --data "{\"evento\":\"PageView\",\"url\":\"http://site.test/drivedeprojetos/?utm_source=MetaAds\",\"referrer\":\"https://l.instagram.com/?u=x\",$ANUNCIO}" "$URL/coletar.php")
VID=$(grep -o '"vid":"[a-f0-9]*"' <<<"$r" | cut -d'"' -f4)
confere "$([ ${#VID} -eq 32 ]; echo $?)" "PageView aceito e visitante criado"
confere "$(grep -q 'trk_vid' "$JAR"; echo $?)" "cookie trk_vid gravado pelo servidor"
r=$(curl -s -c "$JAR" -b "$JAR" -H "Origin: http://site.test" --data "{\"evento\":\"CliqueCheckout\",\"detalhe\":\"<script>alert(1)</script>\",\"url\":\"http://site.test/drivedeprojetos/\",$ANUNCIO}" "$URL/coletar.php")
VID2=$(grep -o '"vid":"[a-f0-9]*"' <<<"$r" | cut -d'"' -f4)
confere "$([ "$VID" = "$VID2" ]; echo $?)" "mesmo visitante no clique (cookie)"
curl -s -H "Origin: http://site.test" --data '{"evento":"WhatsApp","url":"http://site.test/bio/"}' "$URL/coletar.php" >/dev/null
curl -s -H "Origin: http://site.test" --data '{"evento":"PageView","url":"http://site.test/drivedeprojetos/","referrer":"https://www.google.com/search?q=drive"}' "$URL/coletar.php" >/dev/null
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
  \"TrackingParameters\":{\"sck\":\"trk_$VID\",\"utm_source\":\"MetaAds\",\"utm_medium\":\"conjunto 5|111111\",\"utm_campaign\":\"TL 1|120120\"}}"
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
r=$(curl -s -c "$JAR" -b "$JAR" --data-urlencode "csrf=$csrf" --data-urlencode "usuario=kenio" --data-urlencode "senha=errada-123456" "$URL/entrar.php")
confere "$(tem 'Usuário ou senha incorretos' "$r")" "senha errada não entra"
csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' <<<"$r" | head -1 | grep -o '[a-f0-9]\{32\}')
r=$(curl -s -c "$JAR" -b "$JAR" --data-urlencode "csrf=$csrf" --data-urlencode "usuario=ninguem" --data-urlencode "senha=senha-de-teste-123" "$URL/entrar.php")
confere "$(tem 'Usuário ou senha incorretos' "$r")" "usuário inexistente recebe a mesma mensagem"
csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' <<<"$r" | head -1 | grep -o '[a-f0-9]\{32\}')
destino=$(curl -s -o /dev/null -w '%{redirect_url}' -c "$JAR" -b "$JAR" --data-urlencode "csrf=$csrf" --data-urlencode "usuario=kenio" --data-urlencode "senha=senha-de-teste-123" --data-urlencode "volta=//invasor.test/" "$URL/entrar.php")
confere "$([ "${destino%/}" = "$URL" ]; echo $?)" "login certo; volta para outro site é ignorada (vai para $destino)"
r=$(curl -s -b "$JAR" "$URL/index.php?periodo=tudo")
confere "$(tem 'Funil do site' "$(sem_tags "$r")")" "tela inicial é o Resumo"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=trafego&periodo=tudo")
confere "$(tem 'Tráfego por origem' "$r")" "aba Tráfego abre"
confere "$(tem 'MetaAds / conjunto 5|111111 / TL 1|120120</span></span></td><td>1</td>' "$r")" "tabela de tráfego mostra a origem do anúncio com 1 visitante"
confere "$(tem 'Tráfego por canal' "$r")" "tráfego agrupado por canal"
confere "$(tem 'canal-instagram' "$r")" "anúncio com posicionamento Instagram_Reels vira canal Instagram"
confere "$(tem '<span>Orgânico</span>' "$r")" "visita vinda do Google sem etiqueta vira canal Orgânico (folha)"
confere "$(tem '<span>Direto / sem origem</span>' "$r")" "visita sem etiqueta nem site de origem vira Direto"
confere "$(tem '<span>Direto / sem origem</span><span class="info"' "$r")" "Direto / sem origem tem o (i) explicando"
confere "$(tem 'id="form-sair"' "$r")" "Sair fica no topo, com a conta"
confere "$(grep -q 'data-sair' <<<"$r"; [ $? -ne 0 ]; echo $?)" "Sair não é mais uma aba"
confere "$(tem 'orgânico · www.google.com' "$r")" "visita sem etiqueta vinda do Google aparece como orgânico · www.google.com"
confere "$(tem 'direto (sem origem)' "$r")" "visita sem etiqueta e sem site de origem aparece como direto"
confere "$(grep -q 'q=drive' <<<"$r"; [ $? -ne 0 ]; echo $?)" "parâmetros do site de origem não são guardados (LGPD)"
confere "$(tem 'Tráfego por página' "$(sem_tags "$r")")" "tabela por página aparece"
confere "$(tem 'Clicaram no WhatsApp' "$(sem_tags "$r")")" "coluna WhatsApp aparece quando o site já teve clique no WhatsApp"
r2=$(curl -s -b "$JAR" "$URL/index.php?periodo=tudo&dominio=site.test&pagina=/drivedeprojetos/")
confere "$(grep -q 'Clicaram no WhatsApp' <<<"$(sem_tags "$r2")"; [ $? -ne 0 ]; echo $?)" "página que só vende pelo checkout não mostra coluna de WhatsApp"
confere "$(tem 'class="lateral"' "$r")" "barra lateral CMS | UTM aparece quando há link do CMS"
confere "$(tem 'href="../" title="CMS"' "$r")" "ícone CMS aponta para o link configurado"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=resumo&periodo=tudo")
confere "$(tem 'Vendas em que os dados batem' "$(sem_tags "$r")")" "tela de conferência abre"
confere "$(tem '>1 (50%)<' "$r")" "uma venda bate e a outra fica sem visitante (50%)"
confere "$(tem 'Sem visitante' "$r")" "venda sem identificador aparece como 'Sem visitante'"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=vendas&periodo=tudo")
confere "$(tem 'R$ 59,49' "$r")" "valor em centavos exibido em reais"
confere "$(tem 'R$ 67,00' "$r")" "valor com ponto decimal exibido em reais"
confere "$(tem '<span>Anúncio sem posicionamento</span>' "$r")" "venda de anúncio sem posicionamento fica como tal, não como Facebook ou Instagram"
confere "$(tem '<span>Anúncio sem posicionamento</span><span class="info" tabindex="0" role="img" aria-label="Veio de anúncio da Meta' "$r")" "anúncio sem posicionamento tem o (i) explicando"
confere "$(tem '<span class="suave">· utm_term ' "$r")" "anúncio sem posicionamento mostra o utm_term que chegou"
confere "$(tem '<span>Orgânico</span><span class="info"[^>]*>i</span><span class="suave">· WhatsApp</span>' "$r")" "venda orgânica mostra o meio (WhatsApp)"
confere "$(tem 'data-dica="Chegou sem anúncio: link da bio' "$r")" "canal Orgânico tem o (i) dizendo de onde vem e em que condição"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=eventos&periodo=tudo")
confere "$(tem '&lt;script&gt;alert(1)&lt;/script&gt;' "$r")" "texto vindo da página é escapado (sem XSS)"
confere "$(tem 'data-dica="Página aberta: conta cada vez' "$r")" "filtro de eventos com o (i) de cada tipo"
confere "$(grep -q '<script>alert(1)' <<<"$r"; [ $? -ne 0 ]; echo $?)" "nenhum script injetado na tela"
confere "$(tem '177.10.20.0' "$r")" "IP guardado parcial (último número zerado)"
confere "$(tem 'iPhone · Instagram (app)' "$r")" "aparelho e navegador reconhecidos"
confere "$(tem 'TL 1 · cv 05 · Instagram Reels' "$r")" "evento mostra campanha, anúncio e posicionamento sem o ID"
confere "$(tem 'Clique no checkout <b>1</b>' "$r")" "resumo conta os eventos por tipo"
confere "$(tem '<span class="selo ok">Comprou</span>' "$r")" "visitante com venda aprovada aparece como Comprou"
confere "$(tem 'Compraram <b>1</b>' "$r")" "resumo mostra quantos compraram"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=eventos&periodo=tudo&evento=compraram")
confere "$(tem 'Eventos de quem comprou <span class="suave">(1–2 de 2)' "$r")" "Compraram mostra só o caminho de quem comprou"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=eventos&periodo=tudo&evento=CliqueCheckout")
confere "$(grep -q 'Visualização</strong>' <<<"$r"; [ $? -ne 0 ]; echo $?)" "filtro por tipo de evento mostra só os cliques no checkout"
confere "$(tem 'Clique no checkout</strong>' "$r")" "filtro por tipo de evento mantém o evento escolhido"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=eventos&periodo=tudo&dominio=site.test")
confere "$(tem '<option value="/drivedeprojetos/"' "$r")" "caixa de páginas lista as páginas do domínio escolhido"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=eventos&periodo=tudo&dominio=site.test&pagina=/bio/")
confere "$(tem 'WhatsApp' "$r")" "filtro por página mostra os eventos dela"
confere "$(grep -q 'Clique no checkout</strong>' <<<"$r"; [ $? -ne 0 ]; echo $?)" "filtro por página esconde os eventos das outras"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=visitantes&periodo=tudo&v=$VID")
confere "$(tem 'Linha do tempo (2 eventos)' "$(sem_tags "$r")")" "detalhe do visitante com a linha do tempo"
confere "$(tem 'Bate' "$r")" "detalhe do visitante mostra a venda conferida"

echo "Usuários e login do admin"
r=$(curl -s -b "$JAR" "$URL/usuarios.php")
confere "$(tem 'kenio <span class="suave">(você)' "$r")" "tela de usuários lista quem entra"
csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' <<<"$r" | head -1 | grep -o '[a-f0-9]\{32\}')
r=$(curl -s -b "$JAR" --data-urlencode "acao=adicionar" --data-urlencode "usuario=allan" --data-urlencode "senha=outra-senha" --data-urlencode "senha2=outra-senha" "$URL/usuarios.php")
confere "$(tem 'Sessão expirada' "$r")" "criar acesso sem o token é recusado"
r=$(curl -s -b "$JAR" --data-urlencode "csrf=$csrf" --data-urlencode "acao=adicionar" --data-urlencode "usuario=allan" --data-urlencode "senha=outra-senha" --data-urlencode "senha2=outra-senha" "$URL/usuarios.php")
confere "$(tem 'Acesso criado para allan' "$r")" "kenio dá acesso ao allan"
J3="$DADOS/j3"
r=$(curl -s -c "$J3" -b "$J3" "$URL/entrar.php?volta=/admin/")
c=$(grep -o 'name="csrf" value="[a-f0-9]*"' <<<"$r" | head -1 | grep -o '[a-f0-9]\{32\}')
destino=$(curl -s -o /dev/null -w '%{redirect_url}' -c "$J3" -b "$J3" --data-urlencode "csrf=$c" --data-urlencode "usuario=allan" --data-urlencode "senha=outra-senha" --data "volta=%2Fadmin%2F" "$URL/entrar.php")
confere "$(tem '/admin/$' "$destino")" "allan entra e volta para a página que pediu (/admin/)"
r=$(curl -s -b "$J3" "$URL/usuarios.php")
c=$(grep -o 'name="csrf" value="[a-f0-9]*"' <<<"$r" | head -1 | grep -o '[a-f0-9]\{32\}')
r=$(curl -s -b "$J3" --data-urlencode "csrf=$c" --data-urlencode "acao=remover" --data-urlencode "usuario=allan" "$URL/usuarios.php")
confere "$(tem 'não pode tirar o seu próprio acesso' "$r")" "ninguém tira o próprio acesso"
r=$(curl -s -b "$JAR" --data-urlencode "csrf=$csrf" --data-urlencode "acao=remover" --data-urlencode "usuario=allan" "$URL/usuarios.php")
confere "$(tem 'Acesso de allan removido' "$r")" "kenio tira o acesso do allan"
destino=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$J3" "$URL/index.php")
confere "$(tem 'entrar.php' "$destino")" "sessão de quem perdeu o acesso cai na hora"
r=$(curl -s -b "$JAR" --data-urlencode "csrf=$csrf" --data-urlencode "acao=trocar_senha" --data-urlencode "atual=errada" --data-urlencode "nova=x" --data-urlencode "nova2=x" "$URL/usuarios.php")
confere "$(tem 'senha atual não confere' "$r")" "trocar a senha exige a senha atual"

echo "API da Kiwify"
CID="a1b2c3d4-0000-4000-8000-000000000001"
# Vendas que a API falsa devolve: pedido-1 (o webhook ja trouxe), api-2 (so a API tem),
# api-3 (order bump do api-2) e api-4 (Pix nao pago). O comprador vem junto, como na Kiwify.
AGORA=$(date -u +%Y-%m-%dT%H:%M:%S.000Z)
RASTREIO="\"sck\":\"trk_$VID\",\"utm_source\":\"MetaAds\",\"utm_medium\":\"conjunto 5|111111\",\"utm_campaign\":\"TL 1|120120\",\"utm_content\":\"cv 05|222222\""
COMPRADOR='"customer":{"name":"Nome Da API","email":"cliente-api@exemplo.com","cpf":"99999999999","mobile":"+5511999999999"}'
cat >"$DADOS/kiwify-falsa-vendas.json" <<EOF
[
 {"id":"pedido-1","reference":"RefUm01","type":"product","parent_order_id":null,"status":"paid","payment_method":"pix","created_at":"$AGORA","updated_at":"$AGORA","approved_date":"$AGORA",
  "product":{"name":"Drive de Projetos 2.0"},"payment":{"charge_amount":5949,"net_amount":5000},"tracking":{$RASTREIO},$COMPRADOR},
 {"id":"api-2","reference":"RefDois2","type":"product","parent_order_id":null,"status":"paid","payment_method":"credit_card","created_at":"$AGORA","updated_at":"$AGORA","approved_date":"$AGORA",
  "product":{"name":"Drive de Projetos 2.0"},"payment":{"charge_amount":6700,"net_amount":6000},"tracking":{$RASTREIO},$COMPRADOR},
 {"id":"api-3","reference":"RefBump3","type":"bump","parent_order_id":"api-2","status":"paid","payment_method":"credit_card","created_at":"$AGORA","updated_at":"$AGORA","approved_date":"$AGORA",
  "product":{"name":"Memorial Descritivo"},"payment":{"charge_amount":2990,"net_amount":2500},"tracking":{$RASTREIO},$COMPRADOR},
 {"id":"api-4","reference":"RefPix04","type":"product","parent_order_id":null,"status":"waiting_payment","payment_method":"pix","created_at":"$AGORA","updated_at":"$AGORA","approved_date":null,
  "product":{"name":"Drive de Projetos"},"payment":{"charge_amount":6700},"tracking":{"utm_source":"FB","utm_medium":"{{adset.name}}|{{adset.id}}","utm_term":"{{placement}}"},$COMPRADOR}
]
EOF
destino=$(curl -s -o /dev/null -w '%{redirect_url}' "$URL/kiwify-api.php")
confere "$(tem 'entrar.php' "$destino")" "tela da API exige login"
r=$(curl -s -b "$JAR" "$URL/kiwify-api.php")
confere "$(tem 'Colar a chave da API' "$r")" "tela da API abre com o formulário"
confere "$(tem 'href="kiwify-api.php" class="atual"' "$r")" "aba API Kiwify aparece marcada"
csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' <<<"$r" | head -1 | grep -o '[a-f0-9]\{32\}')
salvar() { curl -s -b "$JAR" --data-urlencode "csrf=$1" --data-urlencode "acao=salvar" --data-urlencode "client_id=$2" \
    --data-urlencode "client_secret=$3" --data-urlencode "account_id=$4" "$URL/kiwify-api.php"; }
r=$(salvar "" "$CID" SegredoLeitura0000000000000000 ContaCerta123)
confere "$(tem 'Sessão expirada' "$r")" "salvar sem o token é recusado"
r=$(salvar "$csrf" "nao-e-uuid" SegredoLeitura0000000000000000 ContaCerta123)
confere "$(tem 'Confira os três campos' "$r")" "client_id fora do formato é recusado"
r=$(salvar "$csrf" "$CID" SegredoErrado00000000000000000 ContaCerta123)
confere "$(tem 'A Kiwify recusou a chave' "$r")" "chave que a Kiwify recusa não é salva"
r=$(salvar "$csrf" "$CID" SegredoPerigoso000000000000000 ContaCerta123)
confere "$(tem 'permissões demais: Reembolsar vendas, Financeiro' "$r")" "chave com reembolso e financeiro é recusada"
r=$(salvar "$csrf" "$CID" SegredoLeitura0000000000000000 ContaErrada999)
confere "$(tem 'Confira o account_id' "$r")" "account_id errado é recusado"
confere "$(grep -q 'client_secret' "$DADOS/config.php"; [ $? -ne 0 ]; echo $?)" "nenhuma chave recusada foi gravada"
r=$(salvar "$csrf" "$CID" SegredoLeitura0000000000000000 ContaCerta123)
confere "$(tem 'Chave conferida e salva. A API encontrou 4 venda' "$r")" "chave só de vendas é conferida e salva"
confere "$(grep -q 'SegredoLeitura' <<<"$r"; [ $? -ne 0 ]; echo $?)" "client_secret não volta para a tela"
confere "$(tem 'a1b2…0001' "$r")" "client_id aparece mascarado"
confere "$(grep -q 'SegredoLeitura' "$DADOS/config.php"; echo $?)" "chave gravada na configuração, fora do projeto"
r=$(curl -s -b "$JAR" --data-urlencode "csrf=$csrf" --data-urlencode "acao=testar" "$URL/kiwify-api.php")
confere "$(tem 'Conexão OK' "$r")" "testar conexão com a chave salva"

echo "Vendas pela API"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=vendas&periodo=tudo")
confere "$(tem 'id="sync" data-sync="1"' "$r")" "com a chave nova, o painel pede a busca em segundo plano"
code=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H "X-CSRF: x" "$URL/sincronizar.php")
confere "$([ "$code" = "401" ]; echo $?)" "busca sem login é recusada ($code)"
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -X POST -H "X-CSRF: errado" "$URL/sincronizar.php")
confere "$([ "$code" = "403" ]; echo $?)" "busca sem o token certo é recusada ($code)"
r=$(curl -s -b "$JAR" -X POST -H "X-CSRF: $csrf" "$URL/sincronizar.php")
confere "$(tem '"buscou":true,"novas":3,"atualizadas":1' "$r")" "busca em segundo plano: 3 novas e pedido-1 atualizado, em 2 páginas ($r)"
r=$(curl -s -b "$JAR" -X POST -H "X-CSRF: $csrf" "$URL/sincronizar.php")
confere "$(tem '"buscou":false' "$r")" "nova busca automática espera 10 minutos"
confere "$(grep -rq 'cliente-api@exemplo.com\|Nome Da API\|99999999999' "$DADOS"/track.sqlite*; [ $? -ne 0 ]; echo $?)" "dados do comprador vindos da API não são gravados (LGPD)"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=vendas&periodo=tudo")
confere "$(tem 'Vendas (5 pedidos)' "$(sem_tags "$r")")" "vendas da API somam com as do webhook, sem duplicar"
confere "$(tem 'RefDois2' "$r")" "pedido aparece pela referência curta da Kiwify"
confere "$(tem 'Só pela API' "$r")" "venda que só a API trouxe fica marcada"
confere "$(tem 'Webhook + API' "$r")" "venda que chegou pelos dois caminhos fica marcada"
confere "$(tem 'order bump</span>' "$r")" "order bump aparece marcado"
confere "$(tem 'Vendas da Kiwify atualizadas em' "$r")" "barra mostra a última busca"
confere "$(grep -q 'data-sync="1"' <<<"$r"; [ $? -ne 0 ]; echo $?)" "sem busca pendente, a tela não pede outra"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=trafego&periodo=tudo")
confere "$(tem '<span class="selo ok">2</span></td><td>R$ 156,39' "$r")" "tráfego: 2 vendas do anúncio (bump fora da contagem) e faturamento com bump"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=resumo&periodo=tudo")
confere "$(tem 'R$ 223,39' "$r")" "conferência: faturamento aprovado com order bump"
confere "$(tem 'Só pela API</td><td>1 (33%)' "$r")" "conferência mostra a venda que o webhook não entregou"
r=$(curl -s -b "$JAR" -o /dev/null -w '%{redirect_url}' --data-urlencode "csrf=$csrf" --data-urlencode "completa=1" \
    --data-urlencode "volta=./?aba=vendas&periodo=tudo" "$URL/sincronizar.php")
confere "$(tem '/?aba=vendas&periodo=tudo' "$r")" "botão Atualizar vendas volta para a mesma tela"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=vendas&periodo=tudo")
confere "$(grep -q 'Última busca na API falhou\|Muitas buscas' <<<"$r"; [ $? -ne 0 ]; echo $?)" "clique repetido no botão não vira erro (espera 1 minuto em silêncio)"
r=$(curl -s -b "$JAR" "$URL/kiwify-api.php")
confere "$(tem 'chamada(s) no último minuto' "$r")" "aba API Kiwify mostra o uso da API"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=vendas&periodo=tudo")
confere "$(tem 'Vendas (5 pedidos)' "$(sem_tags "$r")")" "busca completa de novo não duplica"
curl -s --data '{"order_id":"api-2","order_ref":"RefDois2","order_status":"paid","webhook_event_type":"order_approved"}' "$URL/kiwify.php?chave=$CHAVE" >/dev/null
r=$(curl -s -b "$JAR" "$URL/index.php?aba=resumo&periodo=tudo")
confere "$(tem 'Só pela API</td><td>0 (0%)' "$r")" "webhook atrasado junta com a venda da API (Webhook + API)"
confere "$(tem 'Webhook + API</td><td>2 (67%)' "$r")" "duas vendas pelos dois caminhos"

r=$(curl -s -b "$JAR" --data-urlencode "csrf=$csrf" --data-urlencode "acao=remover" "$URL/kiwify-api.php")
confere "$(tem 'Chave removida do painel' "$r")" "remover a chave"
confere "$(grep -q 'SegredoLeitura' "$DADOS/config.php"; [ $? -ne 0 ]; echo $?)" "chave removida sai da configuração"

echo "API da Meta"
destino=$(curl -s -o /dev/null -w '%{redirect_url}' "$URL/meta-api.php")
confere "$(tem 'entrar.php' "$destino")" "tela da API Meta exige login"
r=$(curl -s -b "$JAR" "$URL/meta-api.php")
confere "$(tem 'Conectar a conta de anúncios da Meta' "$r")" "tela da API Meta abre com o passo a passo"
confere "$(tem 'href="meta-api.php" class="atual"' "$r")" "aba API Meta aparece marcada"
meta() { curl -s -b "$JAR" --data-urlencode "csrf=$1" --data-urlencode "acao=salvar" --data-urlencode "token=$2" --data-urlencode "conta=$3" "$URL/meta-api.php"; }
LEITURA="TokenLeitura0000000000000000000000000000000000"
r=$(meta "" "$LEITURA" 587364236934346)
confere "$(tem 'Sessão expirada' "$r")" "salvar token sem o token do formulário é recusado"
r=$(meta "$csrf" "TokenFalso000000000000000000000000000000000000" 587364236934346)
confere "$(tem 'A Meta recusou o token' "$r")" "token que a Meta recusa não é salvo"
confere "$(tem 'O texto colado tem 46 caracteres e começa com &quot;Tok&quot;' "$r")" "erro de token ilegível diz o tamanho e o começo do que foi colado"
r=$(meta "$csrf" "$LEITURA" 111222333444)
confere "$(tem 'não enxerga a conta de anúncios 111222333444' "$r")" "conta de anúncios sem acesso é recusada"
confere "$(grep -q 'TokenLeitura\|TokenFalso' "$DADOS/config.php"; [ $? -ne 0 ]; echo $?)" "nenhum token recusado foi gravado"
r=$(meta "$csrf" "TokenGerencia00000000000000000000000000000000" 587364236934346)
confere "$(tem 'Token conferido e salvo.*Atenção: este token também pode gerenciar anúncios' "$r")" "token que pode editar é aceito, com aviso"
confere "$(tem 'Pode editar</span>' "$r")" "aba API Meta marca que o token pode editar"
r=$(meta "$csrf" "$LEITURA" act_587364236934346)
confere "$(tem 'Token conferido e salvo. Conta DRIVE DE PROJETOS: R$ 1.539,22 investidos nos últimos 7 dias' "$r")" "token só de leitura é conferido e salvo, com o gasto de 7 dias"
confere "$(grep -q "$LEITURA" <<<"$r"; [ $? -ne 0 ]; echo $?)" "token da Meta não volta para a tela"
confere "$(tem 'consulta(s) no último minuto' "$r")" "aba API Meta mostra o uso da API"

echo "Gestor de anúncios"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=gestor&periodo=tudo")
confere "$(tem 'id="sync" data-sync="1"' "$r")" "gestor pede a busca na Meta em segundo plano"
curl -s -o /dev/null -b "$JAR" --data-urlencode "csrf=$csrf" --data-urlencode "volta=./?aba=gestor&periodo=tudo" "$URL/sincronizar.php"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=gestor&periodo=tudo")
confere "$(tem 'gasto da Meta atualizado em' "$r")" "botão Atualizar busca o gasto na Meta"
confere "$(tem '<strong>TL 1</strong>' "$r")" "campanha aparece com o nome da Meta"
linha=$(grep -o '<strong>TL 1</strong>.*</tr>' <<<"$r" | head -1 | sed 's#</tr>.*##')
confere "$(tem 'R$ 40,00<br><span class="suave">Diário' "$linha")" "orçamento diário da campanha"
confere "$(tem '<td>R$ 50,00</td><td>2</td><td>R$ 135,00</td>' "$linha")" "gasto, 2 vendas (bump fora) e faturamento líquido com bump"
confere "$(tem 'positivo">R$ 78,92' "$linha")" "lucro desconta gasto e imposto de 12,15% (igual à UTMify)"
confere "$(tem 'R$ 25,00</td><td><span class="positivo">2,41</span></td><td>R$ 16,67</td><td>3</td><td>R$ 0,83</td><td>1,50%</td><td><span class="positivo">58,5%</span>' "$linha")" "CPA, ROI, CPI, IC, CPC, CTR e margem"
confere "$(tem 'aria-checked="true"[^<]*><span></span></button></form></td><td class="quebra nome"><a class="abre" href="./?aba=gestor&amp;periodo=tudo&amp;nivel=conjuntos&amp;campanha=120120"' "$r")" "campanha ativa na Meta, com o nome abrindo os conjuntos dela"
confere "$(tem 'negativo">R$ -11,22' "$r")" "campanha pausada com gasto e sem venda aparece no prejuízo"
confere "$(tem '1 venda(s) fora de anúncio' "$r")" "venda orgânica conta como fora de anúncio"
confere "$(tem 'data-dica="Vendas aprovadas sem o ID de uma campanha da Meta (a UTMify chama de &quot;não trackeadas&quot;): 1 orgânico' "$r")" "(i) do aviso diz o motivo de cada uma"
confere "$(tem 'data-dica="Faturamento − gasto − imposto da Meta (12,15%).' "$r")" "coluna Lucro tem o (i) com a conta"
confere "$(grep -q 'name="dominio"' <<<"$r"; [ $? -ne 0 ]; echo $?)" "no gestor o topo só tem o período"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=gestor&periodo=tudo&nivel=conjuntos")
confere "$(tem '<strong>conjunto 5</strong></a><br><span class="suave">TL 1</span>' "$r")" "conjunto mostra a campanha dele"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=gestor&periodo=tudo&nivel=anuncios")
confere "$(tem '<strong>cv 05</strong><br><span class="suave">conjunto 5</span></td><td><span class="suave">—</span></td><td>R$ 50,00</td><td>2</td><td>R$ 135,00</td>' "$r")" "anúncio com as vendas que têm o ID dele (a API completou a do webhook)"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=gestor&periodo=tudo&nivel=contas")
confere "$(tem '<strong>DRIVE DE PROJETOS</strong>' "$r")" "nível conta com o nome da conta de anúncios"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=gestor&periodo=tudo&st=pausados")
confere "$(tem '<strong>FREE</strong>' "$r")" "filtro de pausados mostra a FREE"
confere "$(grep -q '<strong>TL 1</strong>' <<<"$r"; [ $? -ne 0 ]; echo $?)" "filtro de pausados esconde a TL 1"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=gestor&periodo=tudo&q=free")
confere "$(grep -q '<strong>TL 1</strong>' <<<"$r"; [ $? -ne 0 ]; echo $?)" "busca por nome filtra as campanhas"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=gestor&periodo=tudo&nivel=conjuntos&campanha=555555")
confere "$(tem '<strong>conjunto free</strong>' "$r")" "abrir a campanha mostra só os conjuntos dela"
confere "$(grep -q '<strong>conjunto 5</strong>' <<<"$r"; [ $? -ne 0 ]; echo $?)" "conjuntos de outra campanha ficam de fora"
confere "$(tem 'Todas as campanhas</a> <span class="suave">›</span> <a' "$r")" "caminho aberto: Todas as campanhas › FREE"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=gestor&periodo=tudo&nivel=anuncios&campanha=120120&conjunto=111111")
confere "$(tem '<strong>cv 05</strong>' "$r")" "abrir o conjunto mostra só os anúncios dele"
confere "$(grep -q '<strong>cv free</strong>' <<<"$r"; [ $? -ne 0 ]; echo $?)" "anúncios de outro conjunto ficam de fora"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=gestor&periodo=tudo")
confere "$([ "$(grep -o '<strong>\(TL 1\|FREE\)</strong>' <<<"$r" | head -1)" = '<strong>TL 1</strong>' ]; echo $?)" "ordem padrão: maior gasto primeiro"
confere "$(tem '>Gasto ↓</a>' "$r")" "coluna ordenada marcada com a seta"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=gestor&periodo=tudo&ordem=gasto&dir=asc")
confere "$([ "$(grep -o '<strong>\(TL 1\|FREE\)</strong>' <<<"$r" | head -1)" = '<strong>FREE</strong>' ]; echo $?)" "clicar no título inverte a ordem"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=gestor&periodo=7d")
confere "$(tem 'As setas comparam com o período anterior' "$r")" "com 7 dias, o gestor compara com os 7 dias anteriores"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=vendas&periodo=tudo")
confere "$(tem '<span>Anúncio compartilhado</span>' "$r")" "etiqueta com {{placement}} vira anúncio compartilhado"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=vendas&periodo=tudo&filtro=fora")
confere "$(tem 'Vendas fora de anúncio (1)' "$(sem_tags "$r")")" "link do aviso lista só as vendas fora de anúncio"
confere "$(tem 'Venda orgânica (WhatsApp): não veio de anúncio' "$r")" "cada venda fora de anúncio mostra o motivo"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=gestor&periodo=tudo&cols[]=gasto&cols[]=cpm&cols[]=impressoes")
confere "$(tem '>CPM</a>' "$r")" "escolher colunas mostra a coluna pedida"
confere "$(grep -q '>Lucro</a>' <<<"$r"; [ $? -ne 0 ]; echo $?)" "escolher colunas esconde as outras"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=gestor&periodo=tudo")
confere "$(tem '>CPM</a>' "$r")" "escolha de colunas fica salva"
confere "$(tem '<td>R$ 50,00</td><td>R$ 12,50</td><td>4.000</td>' "$r")" "colunas na ordem escolhida (gasto, CPM, impressões), com CPM e impressões calculados"
confere "$(tem 'Personalize as colunas' "$r")" "seletor de colunas como o da UTMify"
confere "$(tem '<li draggable="true" data-coluna="gasto">.*<li draggable="true" data-coluna="cpm">.*<li draggable="true" data-coluna="impressoes">' "$(tr -d '\n' <<<"$r")")" "lista da direita na ordem da tabela"
confere "$(tem '<th data-col="cpm">' "$r")" "títulos com a coluna marcada para mudar a largura"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=gestor&periodo=tudo&cols=padrao")
confere "$(tem '>Lucro</a>' "$r")" "voltar ao padrão traz as colunas de sempre"
curl -s -o /dev/null -b "$JAR" "$URL/index.php?aba=gestor&periodo=tudo&cols[]=orcamento&cols[]=gasto&cols[]=vendas&cols[]=fat&cols[]=lucro&cols[]=cpa&cols[]=roi"

echo "Gestor: ligar, pausar e selecionar"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=gestor&periodo=tudo")
confere "$(tem 'Campanhas<span class="info"[^>]*data-dica="Cada campanha' "$r")" "níveis do gestor com o (i)"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=gestor&periodo=tudo")
confere "$(tem 'class="chave ligada" role="switch" aria-checked="true" aria-label="Pausar" title="O token da API Meta só lê' "$r")" "token só de leitura: chave aparece, sem clique"
status() { curl -s -o /dev/null -b "$JAR" --data-urlencode "csrf=$1" --data-urlencode "id=$2" --data-urlencode "status=$3" --data-urlencode "volta=./?aba=gestor&periodo=tudo" "$URL/meta-status.php"; curl -s -b "$JAR" "$URL/index.php?aba=gestor&periodo=tudo"; }
r=$(status "$csrf" 120120 PAUSED)
confere "$(tem 'O token da API Meta só lê' "$(sem_tags "$r")")" "sem ads_management, a Meta nem é chamada"
meta "$csrf" "TokenGerencia00000000000000000000000000000000" 587364236934346 >/dev/null
r=$(curl -s -b "$JAR" "$URL/index.php?aba=gestor&periodo=tudo")
confere "$(tem 'class="chave ligada" role="switch" aria-checked="true" aria-label="Pausar" title="Ligado na Meta. Clique para pausar."' "$r")" "token que edita: chave clicável"
confere "$(tem 'data-confirma="Pausar a campanha &quot;TL 1&quot; na Meta?"' "$r")" "pausar pede confirmação na tela"
r=$(status "" 120120 PAUSED)
confere "$(tem 'Sessão expirada' "$r")" "sem o token do formulário não muda nada"
r=$(status "$csrf" 999999 PAUSED)
confere "$(tem 'Não encontrei esse item' "$(sem_tags "$r")")" "item que não é da conta é recusado"
r=$(status "$csrf" 120120 DELETED)
confere "$(tem 'Não encontrei esse item' "$(sem_tags "$r")")" "só ligar ou pausar (nada de apagar)"
r=$(status "$csrf" 120120 PAUSED)
confere "$(tem 'A campanha &quot;TL 1&quot; foi pausada na Meta.' "$r")" "pausar a campanha na Meta"
confere "$(tem 'class="chave" role="switch" aria-checked="false" aria-label="Ligar"' "$r")" "chave da campanha fica desligada"
confere "$(tem 'Alterações feitas pelo painel' "$(sem_tags "$r")")" "histórico das alterações"
confere "$(tem 'A campanha.*TL 1.*ligado → pausado.*Feito' "$(sem_tags "$r" | tr -d '\n')")" "histórico diz quem, o quê, de/para e o resultado"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=gestor&periodo=tudo&nivel=conjuntos&campanha=120120")
confere "$(tem 'title="CAMPAIGN_PAUSED">Pausado</span>' "$r")" "conjunto da campanha pausada fica pausado pela campanha"
r=$(status "$csrf" 120120 ACTIVE)
confere "$(tem 'A campanha &quot;TL 1&quot; foi ligada na Meta.' "$r")" "ligar de novo"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=gestor&periodo=tudo&nivel=conjuntos&campanha=120120")
confere "$(grep -q 'title="CAMPAIGN_PAUSED"' <<<"$r"; [ $? -ne 0 ]; echo $?)" "conjunto volta com a campanha"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=gestor&periodo=tudo&campanhas[]=555555")
confere "$(tem 'name="campanhas\[\]" value="555555" data-sel checked' "$r")" "campanha marcada continua marcada"
confere "$(tem 'href="./?aba=gestor&amp;periodo=tudo&amp;nivel=conjuntos&amp;campanhas%5B0%5D=555555"' "$r")" "aba Conjuntos leva a seleção"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=gestor&periodo=tudo&nivel=conjuntos&campanhas[]=555555")
confere "$(tem '<strong>conjunto free</strong>' "$r")" "conjuntos só das campanhas marcadas"
confere "$(grep -q '<strong>conjunto 5</strong>' <<<"$r"; [ $? -ne 0 ]; echo $?)" "conjunto de campanha não marcada fica de fora"
confere "$(tem 'Mostrando só 1 campanha(s) marcada(s): FREE' "$(sem_tags "$r")")" "tela diz qual seleção está filtrando"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=gestor&periodo=tudo&nivel=anuncios&campanhas[]=120120")
confere "$(tem '<strong>cv 05</strong>' "$r")" "anúncios só das campanhas marcadas"
confere "$(grep -q '<strong>cv free</strong>' <<<"$r"; [ $? -ne 0 ]; echo $?)" "anúncio de campanha não marcada fica de fora"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=gestor&periodo=tudo&nivel=anuncios&conjuntos[]=444444")
confere "$(tem '<strong>cv free</strong>' "$r")" "anúncios só dos conjuntos marcados"
meta "$csrf" "$LEITURA" 587364236934346 >/dev/null

echo "Resumo"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=geral&periodo=tudo")
confere "$(tem 'Faturamento líquidoiR$ 202,00' "$(sem_tags "$r")")" "faturamento líquido do período (com order bump)"
confere "$(tem 'Gasto com anúnciosiR$ 60,00' "$(sem_tags "$r")")" "gasto com anúncios"
confere "$(tem 'Lucro</span><span class="info"[^>]*>i</span></div><b class="positivo">R$ 134,71</b>' "$r")" "lucro com imposto da Meta"
confere "$(tem 'ROI geral</span><span class="info"[^>]*>i</span></div><b class="positivo">3,00</b>' "$r")" "ROI geral: todo o faturamento ÷ (gasto + imposto)"
confere "$(tem 'ROI rastreado' "$(sem_tags "$r")")" "ROI rastreado ao lado, para comparar"
confere "$(tem 'Margem</span><span class="info"[^>]*>i</span></div><b class="positivo">66,7%</b>' "$r")" "margem"
confere "$(tem 'Vendas pendentes</span><span class="info"[^>]*>i</span></div><b class="">R$ 67,00</b>' "$r")" "pendentes no valor cobrado"
confere "$(tem 'class="rosca"' "$r")" "vendas por pagamento em rosca, como na UTMify"
confere "$(tem 'aria-label="Vendas por dia da semana"' "$r")" "vendas por dia da semana"
confere "$(tem 'Qualidade do rastreio' "$(sem_tags "$r")")" "qualidade do rastreio: quanto das vendas o painel explica"
confere "$(tem 'Taxa de aprovação' "$(sem_tags "$r")")" "taxa de aprovação com anéis"
confere "$(tem 'name="produto"' "$r")" "filtro de produto"
confere "$(tem 'name="canal"' "$r")" "filtro de canal"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=geral&periodo=tudo&canal=organico")
confere "$(tem 'Filtro ligado' "$(sem_tags "$r")")" "filtro de canal avisa que o gasto continua o da conta toda"
confere "$(grep -q 'Faturamento líquidoiR$ 202,00' <<<"$(sem_tags "$r")"; [ $? -ne 0 ]; echo $?)" "filtro de canal muda o faturamento"
confere "$(tem 'Funil da Meta' "$(sem_tags "$r")")" "funil da Meta"
confere "$(tem '<span class="nw">Visualizações&nbsp;' "$r")" "funil com visualizações da página"
confere "$(tem 'Funil do site' "$(sem_tags "$r")")" "funil do site"
confere "$(tem '<div class="fluxo" style="--n:5">' "$r")" "funil da Meta em fluxo, com os 5 passos"
confere "$(tem '<b class="dentro">100,0%</b>' "$r")" "primeiro passo do funil com 100% dentro da faixa"
confere "$(tem 'class="grafico"' "$r")" "gráficos por hora em SVG"
confere "$(tem 'data-dica="Tudo o que voltou ÷ tudo o que foi investido' "$r")" "número do Resumo tem o (i) com a conta"

echo "Orgânico"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=organico&periodo=tudo")
confere "$(tem '1Vendas orgânicas' "$(sem_tags "$r")")" "vendas orgânicas do período"
confere "$(tem 'R$ 67,00Faturamento orgânico' "$(sem_tags "$r")")" "faturamento orgânico"
confere "$(tem '<span>WhatsApp</span></span></td><td>0</td><td>0</td><td><span class="selo ok">1</span></td><td>R$ 67,00</td>' "$r")" "venda orgânica pelo WhatsApp no canal certo"
confere "$(tem '<span>Google</span></span></td><td>1</td>' "$r")" "visita orgânica vinda do Google"
confere "$(tem '<span>Direto / sem origem</span>' "$r")" "visita sem origem aparece à parte"
confere "$(tem 'Perfil do Instagram' "$(sem_tags "$r")")" "bloco do perfil do Instagram explica o que falta"
r=$(curl -s -b "$JAR" --data-urlencode "csrf=$csrf" --data-urlencode "acao=remover" "$URL/meta-api.php")
confere "$(tem 'Token removido do painel' "$r")" "remover o token da Meta"
confere "$(grep -q "$LEITURA" "$DADOS/config.php"; [ $? -ne 0 ]; echo $?)" "token removido sai da configuração"

echo "API do Instagram"
destino=$(curl -s -o /dev/null -w '%{redirect_url}' "$URL/instagram-api.php")
confere "$(tem 'entrar.php' "$destino")" "tela da API Instagram exige login"
r=$(curl -s -b "$JAR" "$URL/instagram-api.php")
confere "$(tem 'Conectar o perfil do Instagram' "$r")" "tela da API Instagram abre com o passo a passo"
confere "$(tem 'href="instagram-api.php" class="atual"' "$r")" "aba API Instagram aparece marcada"
ig() { curl -s -b "$JAR" --data-urlencode "csrf=$1" --data-urlencode "acao=salvar" --data-urlencode "token=$2" "$URL/instagram-api.php"; }
ZEROS=$(printf '0%.0s' $(seq 60))
IGTOKEN="IGAATeste$ZEROS"
r=$(ig "" "$IGTOKEN")
confere "$(tem 'Sessão expirada' "$r")" "salvar token do Instagram sem o token do formulário é recusado"
r=$(ig "$csrf" "0123456789abcdef0123456789abcdef")
confere "$(tem 'Isso é a chave secreta do app (32 caracteres), não o token' "$r")" "chave secreta do app no lugar do token é reconhecida e recusada"
r=$(ig "$csrf" "1713000000000000")
confere "$(tem 'Isso é um número de ID (16 dígitos)' "$r")" "ID do app no lugar do token é reconhecido e recusado"
r=$(ig "$csrf" "EAAB$ZEROS")
confere "$(tem 'Esse é um token da Meta (começa com &quot;EAA&quot;)' "$r")" "token da Meta colado na opção do Instagram aponta a opção 1"
r=$(ig "$csrf" "texto qualquer")
confere "$(tem 'O texto colado tem 13 caracteres e começa com &quot;text&quot;' "$r")" "texto que não é token diz o tamanho e o começo"
r=$(ig "$csrf" "IGAAFalso$ZEROS")
confere "$(tem 'O Instagram recusou o token' "$r")" "token que o Instagram recusa não é salvo"
confere "$(grep -q 'IGAA' "$DADOS/config.php"; [ $? -ne 0 ]; echo $?)" "nenhum token do Instagram recusado foi gravado"
r=$(ig "$csrf" "IGAASemInsights$ZEROS")
confere "$(tem 'Conta @engdesk conectada pelo token do Instagram: 1.234 seguidores e 2 posts. Atenção: sem a permissão' "$r")" "token sem insights é aceito, com aviso"
confere "$(tem 'Sem insights</span>' "$r")" "aba API Instagram marca que faltam os insights"
r=$(ig "$csrf" "{\"access_token\": \"$IGTOKEN\", \"user_id\": 17841400000000000}")
confere "$(tem 'Conta @engdesk conectada pelo token do Instagram: 1.234 seguidores e 2 posts. Os números aparecem' "$r")" "token do Instagram colado com o JSON em volta é achado, conferido e salvo"
confere "$(grep -q "'token' => '$IGTOKEN'" "$DADOS/config.php"; echo $?)" "só o token vai para a configuração, sem o JSON"
confere "$(grep -q "$IGTOKEN" <<<"$r"; [ $? -ne 0 ]; echo $?)" "token do Instagram não volta para a tela"
confere "$(tem 'Liberados</span>' "$r")" "insights liberados"
r=$(curl -s -b "$JAR" --data-urlencode "csrf=$csrf" --data-urlencode "acao=testar" "$URL/instagram-api.php")
confere "$(tem 'Conexão OK. @engdesk: 1.234 seguidores. Insights liberados.' "$r")" "testar a conexão com o Instagram"

echo "Orgânico com o Instagram"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=organico&periodo=7d")
confere "$(tem 'id="sync" data-sync="1"' "$r")" "aba Orgânico pede a busca no Instagram em segundo plano"
curl -s -o /dev/null -b "$JAR" --data-urlencode "csrf=$csrf" --data-urlencode "volta=./?aba=organico&periodo=7d" "$URL/sincronizar.php"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=organico&periodo=7d")
t=$(sem_tags "$r")
confere "$(tem 'Instagram em ' "$t")" "barra mostra a hora da busca no Instagram"
confere "$(tem 'Perfil do Instagram · @engdesk' "$t")" "bloco do perfil com o @ da conta"
confere "$(tem '1.234Seguidores' "$t")" "seguidores do perfil"
confere "$(tem '+21Saldo de seguidores' "$t")" "saldo de seguidores do período (5 − 2 por dia, 7 dias)"
confere "$(tem '700Alcance' "$t")" "alcance somado dos 7 dias"
confere "$(tem '49Toques nos botões de contato' "$t")" "toques nos botões de contato (o Instagram não conta o link da bio)"
confere "$(tem '2Posts no período' "$t")" "posts publicados no período"
confere "$(tem 'Do perfil à venda' "$t")" "funil do perfil até a venda"
confere "$([ "$(grep -o 'Toques nos botões de contato' <<<"$t" | wc -l)" -eq 1 ]; echo $?)" "funil vai do alcance direto aos visitantes (os toques nos botões ficam só nos números)"
confere "$(tem 'aria-label="Alcance por dia"' "$r")" "gráfico de alcance por dia"
confere "$(tem 'href="https://www.instagram.com/reel/abc/" target="_blank" rel="noopener noreferrer"' "$r")" "post abre no Instagram em outra aba"
confere "$(tem 'id="feed"' "$r")" "feed com os posts"
confere "$(tem '<img src="midia.php?id=17900000000000001" alt="" loading="lazy">' "$r")" "capa do reel baixada e mostrada pelo painel"
confere "$(tem '<img src="midia.php?id=17900000000000002"' "$r")" "capa do post de feed baixada e mostrada pelo painel"
confere "$(tem 'Qual formato funciona melhor' "$t")" "comparação entre formatos"
confere "$(tem '<td><strong>Reels</strong></td><td>1</td><td>1.000</td><td>2.500</td><td>9,7%</td><td>30</td><td>12</td>' "$r")" "reels: alcance, visualizações, engajamento, salvos e compartilhamentos médios"
code=$(curl -s -o "$DADOS/capa.jpg" -w '%{http_code} %{content_type}' -b "$JAR" "$URL/midia.php?id=17900000000000001")
confere "$([ "$code" = "200 image/jpeg" ]; echo $?)" "capa entregue pelo painel ($code)"
# shellcheck disable=SC2086
largura=$("$PHP" $PHP_FLAGS -r 'echo getimagesize($argv[1])[0] ?? 0;' "$(cygpath -w "$DADOS/capa.jpg" 2>/dev/null || echo "$DADOS/capa.jpg")")
confere "$([ "$largura" = "480" ] || [ "$largura" = "1" ]; echo $?)" "capa reduzida para 480 px de largura ($largura)"
code=$(curl -s -o /dev/null -w '%{http_code}' "$URL/midia.php?id=17900000000000001")
confere "$([ "$code" = "403" ]; echo $?)" "capa não sai sem login ($code)"
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$URL/midia.php?id=../config")
confere "$([ "$code" = "404" ]; echo $?)" "capa só por número de post, sem caminho ($code)"
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$URL/midia.php?id=17999999999999999")
confere "$([ "$code" = "404" ]; echo $?)" "post sem capa baixada dá 404 ($code)"
# shellcheck disable=SC2086
saida=$(cd "$RAIZ" && "$PHP" $PHP_FLAGS -r '
  require "lib/util.php"; require "lib/instagram_sync.php"; putenv("TRACK_IG_MIDIA_HOST");
  foreach (["https://scontent.cdninstagram.com/v/a.jpg", "https://x.fbcdn.net/a.jpg", "http://scontent.cdninstagram.com/a.jpg", "https://cdninstagram.com.evil.test/a.jpg", "https://evil.test/a.jpg", "file:///etc/passwd"] as $u) { echo ig_url_midia_ok($u) ? "s" : "n"; }')
confere "$([ "$saida" = "ssnnnn" ]; echo $?)" "capa só vem do CDN do Instagram e do Facebook, em HTTPS ($saida)"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=organico&periodo=7d&feed_formato=foto")
confere "$(grep -q 'midia.php?id=17900000000000001' <<<"$r"; [ $? -ne 0 ]; echo $?)" "filtro de formato: só fotos, sem o reel"
confere "$(tem 'midia.php?id=17900000000000002' "$r")" "filtro de formato mostra a foto"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=organico&periodo=7d&feed_ver=tabela&feed_ordem=alcance")
t=$(sem_tags "$r")
confere "$(tem 'Vendas em 48 h' "$t")" "feed em tabela, com as vendas em 48 h"
confere "$([ "$(grep -o 'Reel do Drive de Projetos\|Post da planta' <<<"$t" | head -1)" = 'Reel do Drive de Projetos' ]; echo $?)" "ordenar por alcance: do maior para o menor"
r2=$(curl -s -b "$JAR" "$URL/index.php?aba=organico&periodo=7d&feed_ver=tabela&feed_ordem=salvos")
confere "$([ "$(grep -o 'Reel do Drive de Projetos\|Post da planta' <<<"$(sem_tags "$r2")" | head -1)" = 'Reel do Drive de Projetos' ]; echo $?)" "ordenar por salvos"
confere "$(tem '<td>1.000</td><td>2.500</td><td>50</td><td>5</td><td>30</td><td>12</td>' "$r")" "números do reel (alcance, visualizações, curtidas, comentários, salvos, compartilhamentos)"
confere "$(tem '9,7%' "$t")" "engajamento do reel = interações ÷ alcance"
confere "$(tem '8,5 s' "$t")" "tempo médio assistido do reel"
confere "$(tem '<td>15</td><td>4</td><td>8,8%</td>' "$r")" "post de feed com visitas ao perfil, quem seguiu e engajamento"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=organico&periodo=hoje")
confere "$(tem 'Escolha um período de mais de um dia' "$(sem_tags "$r")")" "hoje não desenha o gráfico por dia"
# Token renovado sozinho quando a última renovação tem mais de 7 dias
# shellcheck disable=SC2086
saida=$(cd "$RAIZ" && "$PHP" $PHP_FLAGS -r '
  require "lib/util.php"; require "lib/instagram_sync.php";
  $c = track_config(); $c["instagram_api"]["renovado_em"] = "2026-01-01 00:00:00"; track_salvar_config($c);
  $k = ig_api_renovar(ig_api_chave()); echo substr($k["token"], 0, 12), " ", $k["vence_em"] > gmdate("Y-m-d", time() + 50 * 86400) ? "60d" : "curto";')
confere "$(tem 'IGAARenovado 60d' "$saida")" "token com mais de 7 dias é renovado sozinho, por mais 60 dias ($saida)"
# Métrica que a conta não aceita: descobre as que valem e segue sem ela
r=$(ig "$csrf" "IGAASemViews$ZEROS")
curl -s -o /dev/null -b "$JAR" --data-urlencode "csrf=$csrf" --data-urlencode "volta=./?aba=organico&periodo=7d" "$URL/sincronizar.php"
# shellcheck disable=SC2086
saida=$(cd "$RAIZ" && "$PHP" $PHP_FLAGS -r 'require "lib/util.php"; echo ajuste("ig_metricas");')
confere "$(tem '\["reach","accounts_engaged","total_interactions","profile_links_taps"\]' "$saida")" "métrica recusada fica de fora e as outras seguem ($saida)"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=organico&periodo=7d")
confere "$(tem '700Alcance' "$(sem_tags "$r")")" "alcance continua depois da métrica recusada"
r=$(curl -s -b "$JAR" --data-urlencode "csrf=$csrf" --data-urlencode "acao=remover" "$URL/instagram-api.php")
confere "$(tem 'Instagram desconectado do painel' "$r")" "desconectar o Instagram"
confere "$(grep -q 'IGAA' "$DADOS/config.php"; [ $? -ne 0 ]; echo $?)" "token do Instagram removido sai da configuração"

echo "Instagram pelo token da API Meta"
usar_meta() { curl -s -b "$JAR" --data-urlencode "csrf=$csrf" --data-urlencode "acao=usar_meta" "$URL/instagram-api.php"; }
r=$(usar_meta)
confere "$(tem 'Salve primeiro o token na aba API Meta' "$r")" "sem token na API Meta, o botão diz o que falta"
meta "$csrf" "$LEITURA" 587364236934346 >/dev/null
r=$(usar_meta)
confere "$(tem 'não enxerga nenhuma conta do Instagram. Faltam as permissões: instagram_basic, instagram_manage_insights, pages_show_list.' "$r")" "token da API Meta sem Instagram lista as permissões que faltam"
# Banco do Instagram vazio: os números a seguir só podem vir pelo caminho da Meta
# shellcheck disable=SC2086
(cd "$RAIZ" && "$PHP" $PHP_FLAGS -r 'require "lib/util.php"; track_db()->exec("DELETE FROM ig_dia; DELETE FROM ig_media"); definir_ajuste("ig_perfil", null); definir_ajuste("ig_sync_ok_em", null);')
IGMETA="TokenInstagram000000000000000000000000000000"
r=$(meta "$csrf" "$IGMETA" 587364236934346)
confere "$(tem 'Token conferido e salvo' "$r")" "token da API Meta com as permissões do Instagram é salvo"
r=$(usar_meta)
confere "$(tem 'Conta @engdesk conectada pelo token da API Meta: 1.234 seguidores e 2 posts. Os números aparecem' "$r")" "Instagram conectado pelo token da API Meta"
confere "$(tem 'pela página EngDesk' "$r")" "tela mostra que o acesso é pelo token da API Meta e por qual página"
confere "$([ "$(grep -c "$IGMETA" "$DADOS/config.php")" = "1" ]; echo $?)" "o token da Meta não é copiado para a configuração do Instagram"
curl -s -o /dev/null -b "$JAR" --data-urlencode "csrf=$csrf" --data-urlencode "volta=./?aba=organico&periodo=7d" "$URL/sincronizar.php"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=organico&periodo=7d")
t=$(sem_tags "$r")
confere "$(tem 'Perfil do Instagram · @engdesk' "$t")" "perfil buscado pelo token da API Meta"
confere "$(tem '700Alcance' "$t")" "alcance buscado pelo token da API Meta"
confere "$(tem 'Reel do Drive de Projetos' "$t")" "posts buscados pelo token da API Meta"
confere "$(grep -q 'Última busca no Instagram falhou' <<<"$t"; [ $? -ne 0 ]; echo $?)" "busca pela Meta sem erro"
curl -s -o /dev/null -b "$JAR" --data-urlencode "csrf=$csrf" --data-urlencode "acao=remover" "$URL/meta-api.php"
r=$(curl -s -b "$JAR" "$URL/instagram-api.php")
confere "$(tem 'O token da aba API Meta foi removido' "$r")" "sem o token da API Meta, a aba Instagram avisa"
r=$(curl -s -b "$JAR" --data-urlencode "csrf=$csrf" --data-urlencode "acao=remover" "$URL/instagram-api.php")
confere "$(tem 'Instagram desconectado do painel' "$r")" "desconectar o Instagram ligado pela Meta"

echo "Proteção contra bloqueio da API"
# Limite interno, num banco separado: com limite 3 por minuto, a 4ª chamada nem sai
D2="$DADOS/limite"; mkdir -p "$D2"
# shellcheck disable=SC2086
saida=$(cd "$RAIZ" && TRACK_DADOS="$(cygpath -w "$D2" 2>/dev/null || echo "$D2")" TRACK_KIWIFY_LIMITE_MINUTO=3 "$PHP" $PHP_FLAGS -r '
  require "lib/util.php"; require "lib/kiwify_api.php";
  for ($i = 1; $i <= 4; $i++) { $t = kiwify_api_token("a1b2c3d4-0000-4000-8000-000000000001", "SegredoLeitura0000000000000000");
    echo $i, ":", $t["ok"] ? "ok" : (empty($t["adiada"]) ? "erro" : "adiada"), " "; }')
confere "$(tem '1:ok 2:ok 3:ok 4:adiada' "$saida")" "limite interno segura a chamada acima do limite por minuto ($saida)"
# Kiwify responde 429: o painel para sozinho e nem tenta a chamada seguinte
r=$(salvar "$csrf" "$CID" SegredoLeitura0000000000000000 ContaLimite123)
confere "$(tem 'A Kiwify pediu uma pausa' "$r")" "429 da Kiwify vira pausa, sem salvar nada"
r=$(salvar "$csrf" "$CID" SegredoLeitura0000000000000000 ContaCerta123)
confere "$(tem 'volta a buscar sozinho às' "$r")" "durante a pausa o painel não chama a Kiwify"

echo "Configurações, app e notificações"
destino=$(curl -s -o /dev/null -w '%{redirect_url}' "$URL/configuracoes.php")
confere "$(tem 'entrar.php' "$destino")" "Configurações exige login"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=trafego&periodo=tudo")
confere "$(tem 'class="conta-nome" href="configuracoes.php"' "$r")" "o nome no topo leva a Configurações"
confere "$(tem '<link rel="manifest" href="manifest.php">' "$r")" "painel aponta o manifesto do app"
r=$(curl -s -b "$JAR" "$URL/configuracoes.php")
t=$(sem_tags "$r")
csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' <<<"$r" | head -1 | grep -o '[a-f0-9]\{32\}')
confere "$(tem 'data-instalar hidden>Instalar o app' "$r")" "Configurações tem o botão de instalar o app"
confere "$(tem 'Notificações de venda' "$t")" "Configurações tem as notificações de venda e de relatório"
confere "$(tem 'Venda aprovada! | Drive de Projetos 2.0' "$t")" "prévia da notificação de venda"
confere "$(tem 'A tarefa agendada ainda não está rodando' "$t")" "avisa enquanto a tarefa agendada não roda"
n=$(grep -o 'data-dica' <<<"$r" | wc -l)
confere "$([ "$n" -ge 10 ]; echo $?)" "cada opção tem o (i) ($n)"
r=$(curl -s -b "$JAR" --data-urlencode "csrf=$csrf" --data 'acao=salvar&aprovadas=1&pendentes=1&valor=1&produto=1&canal=1&campanha=1&horas[]=12&horas[]=99&padrao=detalhado' \
    --data-urlencode "app_nome=Painel EngDesk" "$URL/configuracoes.php")
confere "$(tem 'Configurações salvas' "$r")" "preferências de notificação salvas"
confere "$(tem 'value="12" checked' "$r")" "horário das 12h marcado"
confere "$(grep -q 'value="18" checked' <<<"$r"; [ $? -ne 0 ]; echo $?)" "horário desmarcado sai da lista"
confere "$(tem 'value="detalhado" checked' "$r")" "padrão Resumo detalhado escolhido"
h=$(curl -s -D - -o "$DADOS/manifesto.json" "$URL/manifest.php")
m=$(cat "$DADOS/manifesto.json")
confere "$(tem 'application/manifest+json' "$h")" "manifesto do app com o tipo certo"
confere "$(grep -q '"name": "Painel EngDesk"' <<<"$m" && grep -q '"display": "standalone"' <<<"$m"; echo $?)" "manifesto com o nome escolhido, em tela cheia"
h=$(curl -s -D - -o "$DADOS/sw.js" "$URL/sw.php")
confere "$(tem 'application/javascript' "$h")" "service worker servido como JavaScript"
confere "$(tem 'Cache-Control: no-cache' "$h")" "service worker sem cache (atualiza sozinho)"
confere "$(tem 'showNotification' "$(cat "$DADOS/sw.js")")" "service worker mostra a notificação"

notif() { local corpo="${2:-}"; [ -n "$corpo" ] || corpo='{}'; curl -s -b "$JAR" -H "X-CSRF: $csrf" -H 'Content-Type: application/json' --data "$corpo" "$URL/notificacoes.php?acao=$1"; }
code=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$URL/notificacoes.php?acao=chave")
confere "$([ "$code" = "401" ]; echo $?)" "notificações sem login são recusadas ($code)"
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -X POST "$URL/notificacoes.php?acao=chave")
confere "$([ "$code" = "403" ]; echo $?)" "notificações sem o token CSRF são recusadas ($code)"
r=$(notif chave)
CHAVE_PUSH=$(grep -o '"chave":"[A-Za-z0-9_-]*"' <<<"$r" | cut -d'"' -f4)
confere "$([ ${#CHAVE_PUSH} -eq 87 ]; echo $?)" "chave pública do painel (VAPID) com 65 bytes"
confere "$(grep -q 'BEGIN' "$DADOS/config.php"; echo $?)" "chave privada do VAPID guardada fora do site"
# Aparelho de teste: par de chaves e segredo como o navegador criaria
# shellcheck disable=SC2086
ap=$(cd "$RAIZ" && "$PHP" $PHP_FLAGS -r 'require "lib/util.php"; require "lib/push.php"; [$pem, $pub] = push_novo_par();
  echo json_encode(["pem" => $pem, "p256dh" => b64u($pub), "auth" => b64u(random_bytes(16))]);')
echo "$ap" > "$DADOS/aparelho.json"
P256=$(grep -o '"p256dh":"[^"]*"' <<<"$ap" | cut -d'"' -f4)
AUTH=$(grep -o '"auth":"[^"]*"' <<<"$ap" | cut -d'"' -f4)
# Nome do aparelho so em ASCII: no Windows, o curl nao manda acento em UTF-8
insc() { printf '{"endpoint":"%s","keys":{"p256dh":"%s","auth":"%s"},"aparelho":"%s"}' "$1" "$P256" "$AUTH" "$2"; }
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -H "X-CSRF: $csrf" --data "$(insc https://invasor.test/push Teste)" "$URL/notificacoes.php?acao=inscrever")
confere "$([ "$code" = "400" ]; echo $?)" "endereço de push de fora dos navegadores é recusado ($code)"
r=$(notif inscrever "$(insc "http://127.0.0.1:$PORTA_PUSH/push/aparelho-1" 'Android - Chrome')")
confere "$(tem '"ok":true' "$r")" "aparelho inscrito nas notificações"
r=$(notif testar)
confere "$(tem '"enviadas":1' "$r")" "notificação de teste enviada"
L=$(cat "$DADOS/push.log" 2>/dev/null)
confere "$(tem '"caminho":"/push/aparelho-1","vapid":true,"cifra":"aes128gcm"' "$L")" "envio com a assinatura VAPID e a cifra aes128gcm"
confere "$(tem '"titulo":"Notificações ligadas"' "$L")" "o aparelho decifra a notificação de teste"

: > "$DADOS/push.log"
PIX='{"order_id":"pedido-push","order_status":"waiting_payment","webhook_event_type":"pix_created","payment_method":"pix","Product":{"product_name":"Drive de Projetos 2.0"},"Commissions":{"charge_amount":6700},"TrackingParameters":{"utm_source":"MetaAds","utm_medium":"conjunto 5|111111","utm_campaign":"TL 1|120120","utm_term":"Instagram_Reels"}}'
PAGO=${PIX/waiting_payment/paid}
PAGO=${PAGO/pix_created/order_approved}
for corpo in "$PIX" "$PIX" "$PAGO" "$PAGO"; do curl -s -o /dev/null --data "$corpo" "$URL/kiwify.php?chave=$CHAVE"; done
L=$(cat "$DADOS/push.log")
confere "$(tem '"titulo":"Pix gerado | Drive de Projetos 2.0","corpo":"R$ 67,00 · Instagram · anúncio · TL 1"' "$L")" "Pix gerado avisa com valor, canal e campanha"
confere "$(tem '"titulo":"Venda aprovada! | Drive de Projetos 2.0"' "$L")" "venda aprovada avisa na hora do webhook"
confere "$([ "$(wc -l < "$DADOS/push.log")" -eq 2 ]; echo $?)" "reenvio do webhook não repete o aviso ($(wc -l < "$DADOS/push.log") avisos em 4 webhooks)"
# shellcheck disable=SC2086
saida=$(cd "$RAIZ" && "$PHP" $PHP_FLAGS -r 'require "lib/util.php"; require "lib/push.php"; $db = track_db();
  $db->prepare("UPDATE vendas SET notificado = NULL, pedido_pai = ? WHERE pedido = ?")->execute(["pedido-1", "pedido-push"]);
  echo push_avisar_venda("pedido-push"), ";";
  $db->prepare("UPDATE vendas SET notificado = NULL, pedido_pai = NULL, recebida_em = ? WHERE pedido = ?")->execute([gmdate("Y-m-d H:i:s", time() - 86400), "pedido-push"]);
  echo push_avisar_venda("pedido-push"), ";", push_avisar_venda("pedido-push", true);')
confere "$(tem '0;0;1' "$saida")" "order bump não avisa; venda antiga pela API não avisa; pelo webhook avisa ($saida)"

: > "$DADOS/push.log"
# shellcheck disable=SC2086
saida=$(cd "$RAIZ" && "$PHP" $PHP_FLAGS cron.php --hora=12)
confere "$(tem 'relatorio: 1' "$saida")" "tarefa agendada manda o relatório das 12h ($saida)"
# shellcheck disable=SC2086
saida=$(cd "$RAIZ" && "$PHP" $PHP_FLAGS cron.php --hora=12)
confere "$(tem 'relatorio: 0' "$saida")" "relatório das 12h sai uma vez por dia"
# shellcheck disable=SC2086
saida=$(cd "$RAIZ" && "$PHP" $PHP_FLAGS cron.php --hora=18)
confere "$(tem 'relatorio: 0' "$saida")" "horário não escolhido não manda relatório"
confere "$(tem '"titulo":"Parcial das 12h","corpo":"Faturamento R$ ' "$(cat "$DADOS/push.log")")" "relatório detalhado chega decifrado"
code=$(curl -s -o /dev/null -w '%{http_code}' "$URL/cron.php")
confere "$([ "$code" = "404" ]; echo $?)" "tarefa agendada não abre pelo navegador ($code)"
r=$(curl -s -b "$JAR" "$URL/configuracoes.php")
confere "$(grep -q 'A tarefa agendada ainda não está rodando' <<<"$r"; [ $? -ne 0 ]; echo $?)" "com a tarefa rodando, o aviso some"

notif inscrever "$(insc "http://127.0.0.1:$PORTA_PUSH/push/410" 'iPhone - Safari (app)')" >/dev/null
r=$(notif testar)
confere "$(tem '"enviadas":1,"aparelhos":2' "$r")" "teste vai para os 2 aparelhos; um foi cancelado pelo navegador"
r=$(curl -s -b "$JAR" "$URL/configuracoes.php")
confere "$(tem '1 aparelho(s) seu(s)' "$(sem_tags "$r")")" "inscrição cancelada pelo navegador (410) é apagada"
notif cancelar "{\"endpoint\":\"http://127.0.0.1:$PORTA_PUSH/push/aparelho-1\"}" >/dev/null
r=$(notif testar)
confere "$(tem 'Nenhum aparelho seu' "$r")" "desligar apaga a inscrição do aparelho"
# shellcheck disable=SC2086
saida=$(cd "$RAIZ" && "$PHP" $PHP_FLAGS tests/push-cripto.php)
n=$(grep -c '^ok' <<<"$saida")
confere "$(grep -q FALHA <<<"$saida"; [ $? -ne 0 ] && [ "$n" -eq 7 ]; echo $?)" "cifra do push (RFC 8291) e assinatura do VAPID ($n/7)"

echo "Proteções"
for i in 1 2 3 4 5 6; do r=$(curl -s -c "$DADOS/j2" -b "$DADOS/j2" "$URL/entrar.php"); c=$(grep -o '[a-f0-9]\{32\}' <<<"$r" | head -1); r=$(curl -s -c "$DADOS/j2" -b "$DADOS/j2" --data "csrf=$c&senha=errada" "$URL/entrar.php"); done
confere "$(tem 'Muitas tentativas' "$r")" "login bloqueia depois de 5 senhas erradas"

echo "Classificação e webhook"
# shellcheck disable=SC2086
saida=$(cd "$RAIZ" && "$PHP" $PHP_FLAGS -r '
  require "lib/util.php"; require "lib/layout.php";
  echo canal("ig", "social", null, null, "ID MID|120249173963740442")[1], ";", canal("ig", "social")[3], ";", canal("instagram", "social", null, null, "TL 1|120120")[1], ";", canal("organico", "instagram-bio", null, null, "bio")[3];')
confere "$(tem 'Instagram · anúncio;Instagram (bio);Instagram · anúncio;Instagram (bio)' "$saida")" "ig / social com ID de campanha é anúncio; sem ID, bio ($saida)"
r=$(curl -s -b "$JAR" "$URL/kiwify-api.php")
confere "$(tem 'Mostrar a URL do webhook' "$r")" "aba API Kiwify mostra a URL do webhook"
confere "$(tem "kiwify.php?chave=$CHAVE" "$r")" "URL do webhook com a chave certa"
confere "$(grep -q 'O webhook não está chegando' <<<"$r"; [ $? -ne 0 ]; echo $?)" "webhook chegando: sem aviso"
# shellcheck disable=SC2086
(cd "$RAIZ" && "$PHP" $PHP_FLAGS -r 'require "lib/util.php"; track_db()->exec("UPDATE vendas SET fonte = '"'"'api'"'"', recebida_em = datetime('"'"'now'"'"')");')
r=$(curl -s -b "$JAR" "$URL/kiwify-api.php")
confere "$(tem 'O webhook não está chegando' "$r")" "vendas recentes só pela API: aviso de webhook parado"

echo
if [ "$FALHAS" -eq 0 ]; then echo "TUDO OK"; else echo "$FALHAS FALHA(S)"; echo "--- log do servidor:"; tail -20 "$DADOS/servidor.log"; fi
exit "$FALHAS"
