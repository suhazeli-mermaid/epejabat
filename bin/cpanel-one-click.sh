#!/bin/sh
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$ROOT_DIR"

PHP_BIN=${PHP_BIN:-php}
MODE=${DEPLOY_MODE:-${1:-fresh}}
ACTOR=${DEPLOY_ACTOR:-${2:-}}

if [ ! -f .env ]; then
    echo "Ralat: salin .env.production.example kepada .env dan lengkapkan nilainya." >&2
    exit 1
fi

"$PHP_BIN" bin/cpanel-deploy.php preflight

case "$MODE" in
    fresh)
        exec "$PHP_BIN" bin/cpanel-deploy.php deploy --mode=fresh --confirm=pkdkb.epejabat.net
        ;;
    upgrade)
        if [ -n "$ACTOR" ]; then
            exec "$PHP_BIN" bin/cpanel-deploy.php deploy --mode=upgrade --actor="$ACTOR" --confirm=pkdkb.epejabat.net
        fi
        exec "$PHP_BIN" bin/cpanel-deploy.php deploy --mode=upgrade --confirm=pkdkb.epejabat.net
        ;;
    *)
        echo "Penggunaan: sh bin/cpanel-one-click.sh fresh|upgrade [SUPER_ADMIN_ID]" >&2
        exit 1
        ;;
esac
