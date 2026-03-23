#!/usr/bin/env bash
set -euo pipefail

# Uso:
# scripts/install-semsearch.sh \
#   --mantis-app mz177-app \
#   --mantis-db mz177-db \
#   --db-name mantisbt \
#   --db-user mantisbt \
#   --db-pass mantisbt \
#   --plugin-prefix mantis_plugin_ \
#   --plugin-dir /var/www/html/plugins \
#   --qdrant-dir ./deploy/qdrant

MANTIS_APP=""
MANTIS_DB=""
DB_NAME="mantisbt"
DB_USER="mantisbt"
DB_PASS="mantisbt"
PLUGIN_PREFIX="mantis_plugin_"
PLUGIN_DIR="/var/www/html/plugins"
QDRANT_DIR="./deploy/qdrant"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --mantis-app) MANTIS_APP="$2"; shift 2;;
    --mantis-db) MANTIS_DB="$2"; shift 2;;
    --db-name) DB_NAME="$2"; shift 2;;
    --db-user) DB_USER="$2"; shift 2;;
    --db-pass) DB_PASS="$2"; shift 2;;
    --plugin-prefix) PLUGIN_PREFIX="$2"; shift 2;;
    --plugin-dir) PLUGIN_DIR="$2"; shift 2;;
    --qdrant-dir) QDRANT_DIR="$2"; shift 2;;
    *) echo "Parámetro desconocido: $1"; exit 1;;
  esac
done

[[ -n "$MANTIS_APP" ]] || { echo "Falta --mantis-app"; exit 1; }
[[ -n "$MANTIS_DB" ]] || { echo "Falta --mantis-db"; exit 1; }

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PLUGIN_SRC="$ROOT_DIR/plugin/SemanticSearch"
SQL_TMPL="$ROOT_DIR/deploy/db/01-semsearch-schema.sql"

echo "[1/5] Copiando plugin a $MANTIS_APP:$PLUGIN_DIR"
docker exec "$MANTIS_APP" sh -lc "mkdir -p '$PLUGIN_DIR'"
docker cp "$PLUGIN_SRC" "$MANTIS_APP:$PLUGIN_DIR/"

echo "[2/5] Aplicando esquema SQL con prefijo '$PLUGIN_PREFIX'"
TMP_SQL="$(mktemp)"
sed "s/__PLUGIN_PREFIX__/${PLUGIN_PREFIX}/g" "$SQL_TMPL" > "$TMP_SQL"
docker cp "$TMP_SQL" "$MANTIS_DB:/tmp/semsearch-schema.sql"
rm -f "$TMP_SQL"
docker exec "$MANTIS_DB" sh -lc "mariadb -u'$DB_USER' -p'$DB_PASS' '$DB_NAME' < /tmp/semsearch-schema.sql"

echo "[3/5] Registrando plugin en mantis_plugin_table"
docker exec "$MANTIS_DB" sh -lc "mariadb -u'$DB_USER' -p'$DB_PASS' '$DB_NAME' -e \"INSERT INTO mantis_plugin_table (basename,enabled,protected,priority) SELECT 'SemanticSearch',1,0,3 WHERE NOT EXISTS (SELECT 1 FROM mantis_plugin_table WHERE basename='SemanticSearch');\""

echo "[4/5] Levantando Qdrant ($QDRANT_DIR)"
( cd "$QDRANT_DIR" && docker compose up -d )

echo "[5/5] Verificando tablas"
docker exec "$MANTIS_DB" sh -lc "mariadb -N -u'$DB_USER' -p'$DB_PASS' '$DB_NAME' -e \"SHOW TABLES LIKE '${PLUGIN_PREFIX}semsearch_%';\""

echo "OK: instalación base completada"
