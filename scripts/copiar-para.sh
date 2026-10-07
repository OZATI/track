#!/usr/bin/env bash
# Copia o painel para dentro do repositorio de um site (ex.: engdesk/admin/utm).
# Este repositorio e a base; cada site publica a copia pelo proprio deploy, sem
# integracao nova na hospedagem. Mudanca no painel: faz aqui, commita e copia de novo.
#
# Uso: bash scripts/copiar-para.sh ../engdesk/admin/utm
set -euo pipefail

ORIGEM="$(cd "$(dirname "$0")/.." && pwd)"
DESTINO="${1:?Uso: bash scripts/copiar-para.sh <pasta de destino>}"
# Todas as paginas .php e os scripts .js da raiz (tela ou script novo entra sozinho na copia) e
# os demais arquivos
mapfile -t PAGINAS < <(cd "$ORIGEM" && ls -1 *.php *.js)
ARQUIVOS=("${PAGINAS[@]}" app-192.png app-512.png .htaccess robots.txt LICENSE)

# O VERSAO diz qual commit esta na copia: com alteracao sem commit ele mentiria
if [ -n "$(git -C "$ORIGEM" status --porcelain -- "${ARQUIVOS[@]}" lib)" ]; then
  echo "Ha alteracoes sem commit no painel. Commite no OZATI/track antes de copiar." >&2
  exit 1
fi

if [ -e "$DESTINO" ] && [ -n "$(ls -A "$DESTINO")" ]; then
  # So limpa pasta que ja e uma copia do painel: um destino errado nao perde nada
  if ! head -1 "$DESTINO/VERSAO" 2>/dev/null | grep -q '^OZATI/track '; then
    echo "$DESTINO nao esta vazia e nao e uma copia do painel (sem VERSAO). Nada foi copiado." >&2
    exit 1
  fi
  rm -rf "$DESTINO/lib"
  rm -f "$DESTINO"/*.php "$DESTINO"/*.js "$DESTINO"/app-*.png "$DESTINO/.htaccess" "$DESTINO/robots.txt" "$DESTINO/LICENSE"
fi

mkdir -p "$DESTINO"
for f in "${ARQUIVOS[@]}"; do cp "$ORIGEM/$f" "$DESTINO/"; done
cp -r "$ORIGEM/lib" "$DESTINO/"

COMMIT="$(git -C "$ORIGEM" rev-parse HEAD)"
cat > "$DESTINO/VERSAO" <<EOF
OZATI/track ${COMMIT:0:7}
https://github.com/OZATI/track/commit/$COMMIT
Copia do painel. Nao edite aqui: mude no OZATI/track e rode scripts/copiar-para.sh de novo.
EOF

echo "Painel copiado para $DESTINO (OZATI/track ${COMMIT:0:7})."
