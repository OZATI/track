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
# shellcheck disable=SC2086
"$PHP" $PHP_FLAGS -S "127.0.0.1:$PORTA" -t "$RAIZ" >"$DADOS/servidor.log" 2>&1 &
SERVIDOR=$!
trap 'kill $SERVIDOR $API_FALSA $META_FALSA 2>/dev/null; rm -rf "$DADOS"' EXIT
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
r=$(curl -s -c "$JAR" -b "$JAR" --data-urlencode "csrf=$csrf" --data-urlencode "usuario=kenio" --data-urlencode "senha=errada-123456" "$URL/entrar.php")
confere "$(tem 'Usuário ou senha incorretos' "$r")" "senha errada não entra"
csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' <<<"$r" | head -1 | grep -o '[a-f0-9]\{32\}')
r=$(curl -s -c "$JAR" -b "$JAR" --data-urlencode "csrf=$csrf" --data-urlencode "usuario=ninguem" --data-urlencode "senha=senha-de-teste-123" "$URL/entrar.php")
confere "$(tem 'Usuário ou senha incorretos' "$r")" "usuário inexistente recebe a mesma mensagem"
csrf=$(grep -o 'name="csrf" value="[a-f0-9]*"' <<<"$r" | head -1 | grep -o '[a-f0-9]\{32\}')
destino=$(curl -s -o /dev/null -w '%{redirect_url}' -c "$JAR" -b "$JAR" --data-urlencode "csrf=$csrf" --data-urlencode "usuario=kenio" --data-urlencode "senha=senha-de-teste-123" --data-urlencode "volta=//invasor.test/" "$URL/entrar.php")
confere "$([ "${destino%/}" = "$URL" ]; echo $?)" "login certo; volta para outro site é ignorada (vai para $destino)"
r=$(curl -s -b "$JAR" "$URL/index.php?periodo=tudo")
confere "$(tem 'Tráfego por origem' "$r")" "tela inicial é a de tráfego"
confere "$(tem 'MetaAds / conjunto 5|111 / TL 1|120</span></span></td><td>1</td>' "$r")" "tabela de tráfego mostra a origem do anúncio com 1 visitante"
confere "$(tem 'Tráfego por canal' "$r")" "tráfego agrupado por canal"
confere "$(tem 'canal-instagram' "$r")" "anúncio com posicionamento Instagram_Reels vira canal Instagram"
confere "$(tem '<span>Orgânico</span>' "$r")" "visita vinda do Google sem etiqueta vira canal Orgânico (folha)"
confere "$(tem '<span>Direto / sem origem</span>' "$r")" "visita sem etiqueta nem site de origem vira Direto"
confere "$(tem 'id="form-sair"' "$r")" "Sair fica no topo, com a conta"
confere "$(grep -q 'data-sair' <<<"$r"; [ $? -ne 0 ]; echo $?)" "Sair não é mais uma aba"
confere "$(tem 'orgânico · www.google.com' "$r")" "visita sem etiqueta vinda do Google aparece como orgânico · www.google.com"
confere "$(tem 'direto (sem origem)' "$r")" "visita sem etiqueta e sem site de origem aparece como direto"
confere "$(grep -q 'q=drive' <<<"$r"; [ $? -ne 0 ]; echo $?)" "parâmetros do site de origem não são guardados (LGPD)"
confere "$(tem 'Tráfego por página' "$r")" "tabela por página aparece"
confere "$(tem 'Clicaram no WhatsApp' "$r")" "coluna WhatsApp aparece quando o site já teve clique no WhatsApp"
r2=$(curl -s -b "$JAR" "$URL/index.php?periodo=tudo&dominio=site.test&pagina=/drivedeprojetos/")
confere "$(grep -q 'WhatsApp' <<<"$r2"; [ $? -ne 0 ]; echo $?)" "página que só vende pelo checkout não mostra coluna de WhatsApp"
confere "$(tem 'class="lateral"' "$r")" "barra lateral CMS | UTM aparece quando há link do CMS"
confere "$(tem 'href="../" title="CMS"' "$r")" "ícone CMS aponta para o link configurado"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=resumo&periodo=tudo")
confere "$(tem 'Vendas em que os dados batem' "$r")" "tela de conferência abre"
confere "$(tem '>1 (50%)<' "$r")" "uma venda bate e a outra fica sem visitante (50%)"
confere "$(tem 'Sem visitante' "$r")" "venda sem identificador aparece como 'Sem visitante'"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=vendas&periodo=tudo")
confere "$(tem 'R$ 59,49' "$r")" "valor em centavos exibido em reais"
confere "$(tem 'R$ 67,00' "$r")" "valor com ponto decimal exibido em reais"
confere "$(tem '<span>Meta · anúncio</span>' "$r")" "venda de anúncio sem posicionamento chega por Meta"
confere "$(tem '<span>Orgânico</span><span class="suave">· WhatsApp</span>' "$r")" "venda orgânica mostra o meio (WhatsApp)"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=eventos&periodo=tudo")
confere "$(tem '&lt;script&gt;alert(1)&lt;/script&gt;' "$r")" "texto vindo da página é escapado (sem XSS)"
confere "$(grep -q '<script>alert(1)' <<<"$r"; [ $? -ne 0 ]; echo $?)" "nenhum script injetado na tela"
confere "$(tem '177.10.20.0' "$r")" "IP guardado parcial (último número zerado)"
confere "$(tem 'iPhone · Instagram (app)' "$r")" "aparelho e navegador reconhecidos"
confere "$(tem 'TL 1 · cv 05 · Instagram Reels' "$r")" "evento mostra campanha, anúncio e posicionamento sem o ID"
confere "$(tem 'Clique no checkout <b>1</b>' "$r")" "resumo conta os eventos por tipo"
confere "$(tem '<span class="selo ok">Comprou</span>' "$r")" "visitante com venda aprovada aparece como Comprou"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=eventos&periodo=tudo&evento=CliqueCheckout")
confere "$(grep -q 'Visualização</strong>' <<<"$r"; [ $? -ne 0 ]; echo $?)" "filtro por tipo de evento mostra só os cliques no checkout"
confere "$(tem 'Clique no checkout</strong>' "$r")" "filtro por tipo de evento mantém o evento escolhido"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=eventos&periodo=tudo&dominio=site.test")
confere "$(tem '<option value="/drivedeprojetos/"' "$r")" "caixa de páginas lista as páginas do domínio escolhido"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=eventos&periodo=tudo&dominio=site.test&pagina=/bio/")
confere "$(tem 'WhatsApp' "$r")" "filtro por página mostra os eventos dela"
confere "$(grep -q 'Clique no checkout</strong>' <<<"$r"; [ $? -ne 0 ]; echo $?)" "filtro por página esconde os eventos das outras"
r=$(curl -s -b "$JAR" "$URL/index.php?aba=visitantes&periodo=tudo&v=$VID")
confere "$(tem 'Linha do tempo (2 eventos)' "$r")" "detalhe do visitante com a linha do tempo"
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
RASTREIO="\"sck\":\"trk_$VID\",\"utm_source\":\"MetaAds\",\"utm_medium\":\"conjunto 5|111\",\"utm_campaign\":\"TL 1|120\""
COMPRADOR='"customer":{"name":"Nome Da API","email":"cliente-api@exemplo.com","cpf":"99999999999","mobile":"+5511999999999"}'
cat >"$DADOS/kiwify-falsa-vendas.json" <<EOF
[
 {"id":"pedido-1","reference":"RefUm01","type":"product","parent_order_id":null,"status":"paid","payment_method":"pix","created_at":"$AGORA","updated_at":"$AGORA","approved_date":"$AGORA",
  "product":{"name":"Drive de Projetos 2.0"},"payment":{"charge_amount":5949},"tracking":{$RASTREIO},$COMPRADOR},
 {"id":"api-2","reference":"RefDois2","type":"product","parent_order_id":null,"status":"paid","payment_method":"credit_card","created_at":"$AGORA","updated_at":"$AGORA","approved_date":"$AGORA",
  "product":{"name":"Drive de Projetos 2.0"},"payment":{"charge_amount":6700},"tracking":{$RASTREIO},$COMPRADOR},
 {"id":"api-3","reference":"RefBump3","type":"bump","parent_order_id":"api-2","status":"paid","payment_method":"credit_card","created_at":"$AGORA","updated_at":"$AGORA","approved_date":"$AGORA",
  "product":{"name":"Memorial Descritivo"},"payment":{"charge_amount":2990},"tracking":{$RASTREIO},$COMPRADOR},
 {"id":"api-4","reference":"RefPix04","type":"product","parent_order_id":null,"status":"waiting_payment","payment_method":"pix","created_at":"$AGORA","updated_at":"$AGORA","approved_date":null,
  "product":{"name":"Drive de Projetos"},"payment":{"charge_amount":6700},"tracking":{"utm_source":"FB","utm_medium":"conjunto 2|222"},$COMPRADOR}
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
confere "$(tem 'Vendas (5 pedidos)' "$r")" "vendas da API somam com as do webhook, sem duplicar"
confere "$(tem 'RefDois2' "$r")" "pedido aparece pela referência curta da Kiwify"
confere "$(tem 'Só pela API' "$r")" "venda que só a API trouxe fica marcada"
confere "$(tem 'Webhook + API' "$r")" "venda que chegou pelos dois caminhos fica marcada"
confere "$(tem 'order bump</span>' "$r")" "order bump aparece marcado"
confere "$(tem 'última busca na API' "$r")" "barra mostra a última busca"
confere "$(grep -q 'data-sync="1"' <<<"$r"; [ $? -ne 0 ]; echo $?)" "sem busca pendente, a tela não pede outra"
r=$(curl -s -b "$JAR" "$URL/index.php?periodo=tudo")
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
confere "$(tem 'Vendas (5 pedidos)' "$r")" "busca completa de novo não duplica"
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
r=$(meta "$csrf" "TokenGerencia00000000000000000000000000000000" 587364236934346)
confere "$(tem 'também pode editar a conta: gerenciar anúncios' "$r")" "token que pode editar anúncios é recusado"
r=$(meta "$csrf" "$LEITURA" 111222333444)
confere "$(tem 'não enxerga a conta de anúncios 111222333444' "$r")" "conta de anúncios sem acesso é recusada"
confere "$(grep -q 'TokenLeitura\|TokenGerencia' "$DADOS/config.php"; [ $? -ne 0 ]; echo $?)" "nenhum token recusado foi gravado"
r=$(meta "$csrf" "$LEITURA" act_587364236934346)
confere "$(tem 'Token conferido e salvo. Conta DRIVE DE PROJETOS: R$ 1.539,22 investidos nos últimos 7 dias' "$r")" "token só de leitura é conferido e salvo, com o gasto de 7 dias"
confere "$(grep -q "$LEITURA" <<<"$r"; [ $? -ne 0 ]; echo $?)" "token da Meta não volta para a tela"
confere "$(tem 'consulta(s) no último minuto' "$r")" "aba API Meta mostra o uso da API"
r=$(curl -s -b "$JAR" --data-urlencode "csrf=$csrf" --data-urlencode "acao=remover" "$URL/meta-api.php")
confere "$(tem 'Token removido do painel' "$r")" "remover o token da Meta"
confere "$(grep -q "$LEITURA" "$DADOS/config.php"; [ $? -ne 0 ]; echo $?)" "token removido sai da configuração"

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

echo "Proteções"
for i in 1 2 3 4 5 6; do r=$(curl -s -c "$DADOS/j2" -b "$DADOS/j2" "$URL/entrar.php"); c=$(grep -o '[a-f0-9]\{32\}' <<<"$r" | head -1); r=$(curl -s -c "$DADOS/j2" -b "$DADOS/j2" --data "csrf=$c&senha=errada" "$URL/entrar.php"); done
confere "$(tem 'Muitas tentativas' "$r")" "login bloqueia depois de 5 senhas erradas"

echo
if [ "$FALHAS" -eq 0 ]; then echo "TUDO OK"; else echo "$FALHAS FALHA(S)"; echo "--- log do servidor:"; tail -20 "$DADOS/servidor.log"; fi
exit "$FALHAS"
