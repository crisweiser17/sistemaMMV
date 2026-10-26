#!/usr/bin/env bash
#
# Deploy do Sistema MMV para producao (Cloudways, app "MMV Equipamentos").
#
# O servidor NAO e repositorio git: o codigo vai por rsync sobre SSH.
# Nunca toca em .env, banco SQLite, storage/ nem vendor/.
#
#   scripts/deploy.sh          mostra o que vai mudar e pede confirmacao
#   scripts/deploy.sh --sim    so mostra o que mudaria (nao envia nada)
#   scripts/deploy.sh -y       envia sem perguntar
#
# Requer o alias `mmv-prod` em ~/.ssh/config.

set -euo pipefail

HOST="mmv-prod"
APP="/home/1610684.cloudwaysapps.com/pajzuqqpna/public_html"
URL="https://phplaravel-1610684-6492136.cloudwaysapps.com"

SIMULAR=0
SEM_PERGUNTA=0
for arg in "$@"; do
    case "$arg" in
        --sim) SIMULAR=1 ;;
        -y) SEM_PERGUNTA=1 ;;
        *) echo "Opcao desconhecida: $arg" >&2; exit 1 ;;
    esac
done

cd "$(dirname "$0")/.."

EXCLUDES=(
    --exclude=.git/
    --exclude=.env
    --exclude='.env.*'
    --exclude=/vendor/
    --exclude=/node_modules/
    --exclude=/storage/
    --exclude='/database/*.sqlite*'
    --exclude='/bootstrap/cache/*.php'
    --exclude=/public/storage
    --exclude=/public/hot
    --exclude=/scripts/
    --exclude=.DS_Store
    --exclude=.phpunit.result.cache
    --exclude=/.claude/
    --exclude=/.codex/
    --exclude=/.cursor/
    --exclude=/.idea/
    --exclude=/.vscode/
    --exclude='* [0-9].php'
    --exclude='* [0-9].js'
    --exclude='* copy.php'
)

# -c compara por conteudo: o mtime local muda a toa (iCloud, checkout) e geraria envio desnecessario.
# Sem --delete: arquivo apagado localmente continua no servidor (remover a mao, de proposito).
RSYNC=(rsync -rlptzc --itemize-changes "${EXCLUDES[@]}" ./ "$HOST:$APP/")

echo "==> Verificando conexao com $HOST"
ssh -o ConnectTimeout=15 -o BatchMode=yes "$HOST" "test -f '$APP/artisan'" \
    || { echo "Sem acesso ao app em $APP" >&2; exit 1; }

if [ -n "$(git status --porcelain)" ]; then
    echo
    echo "ATENCAO: ha alteracoes sem commit — elas TAMBEM vao para producao:"
    git status --short
fi

echo
echo "==> Arquivos que mudariam no servidor"
MUDANCAS="$("${RSYNC[@]}" --dry-run | grep -E '^[<c]' || true)"
if [ -z "$MUDANCAS" ]; then
    echo "Nenhuma diferenca. Producao ja esta igual ao local."
    exit 0
fi
echo "$MUDANCAS" | awk '{ $1=""; print " " $0 }'

LOCK_MUDOU=0
if [ "$(md5 -q composer.lock)" != "$(ssh "$HOST" "md5sum '$APP/composer.lock' | cut -d' ' -f1")" ]; then
    LOCK_MUDOU=1
    echo
    echo "composer.lock mudou: vai rodar composer install no servidor."
fi

[ "$SIMULAR" = 1 ] && { echo; echo "(simulacao — nada foi enviado)"; exit 0; }

if [ "$SEM_PERGUNTA" = 0 ]; then
    echo
    read -r -p "Enviar para PRODUCAO? [s/N] " resp
    [[ "$resp" =~ ^[sS]$ ]] || { echo "Cancelado."; exit 1; }
fi

echo
echo "==> Backup do banco"
ssh "$HOST" bash -s -- "$APP" <<'REMOTO'
set -euo pipefail
cd "$1"
dir=storage/app/backups
mkdir -p "$dir"
destino="$dir/database-$(date +%Y%m%d-%H%M%S).sqlite"
# API de backup do SQLite: copia consistente mesmo com o site gravando no banco.
php -r '$o = new SQLite3($argv[1]); $d = new SQLite3($argv[2]); $o->backup($d);' \
    database/database.sqlite "$destino"
echo "   $destino ($(du -h "$destino" | cut -f1))"
# Mantem os 20 mais recentes.
ls -1t "$dir"/database-*.sqlite | tail -n +21 | xargs -r rm -f
REMOTO

echo
echo "==> Enviando arquivos"
"${RSYNC[@]}" | grep -E '^[<c]' | awk '{ $1=""; print " " $0 }' || true

echo
echo "==> Pos-deploy no servidor"
ssh "$HOST" bash -s -- "$APP" "$LOCK_MUDOU" <<'REMOTO'
set -euo pipefail
cd "$1"
if [ "$2" = 1 ]; then
    composer install --no-dev --optimize-autoloader --no-interaction
fi
php artisan migrate --force
php artisan config:clear
php artisan route:clear
php artisan view:clear
REMOTO

echo
echo "==> Teste do site"
codigo="$(curl -s -o /dev/null -w '%{http_code}' "$URL/login")"
if [ "$codigo" = 200 ]; then
    echo "   /login respondeu 200 — deploy concluido."
else
    echo "   /login respondeu $codigo — VERIFICAR! Veja $URL/diagnostico.php" >&2
    exit 1
fi
