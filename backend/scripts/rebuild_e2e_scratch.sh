#!/usr/bin/env bash
# Rebuild the end-to-end scratch database.
#
# It is a STRUCTURE-ONLY clone of the live `laravel` schema — no rows are ever copied, and the
# migration set is never replayed from empty (it cannot be: 2026_06_11_140001_add_api_to_origin_enums
# issues a raw MySQL-only ALTER that no fresh run survives, which is why cloning is the supported
# route). The only read against live is a no-data mysqldump.
#
# Usage:  bash scripts/rebuild_e2e_scratch.sh
set -euo pipefail

LIVE_DB="${LIVE_DB:-laravel}"
SCRATCH_DB="${SCRATCH_DB:-fleet_e2e_scratch}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_USER="${DB_USER:-root}"

export PATH="/c/xampp/mysql/bin:$PATH"

TMP="$(mktemp -t schema_only.XXXXXX.sql)"
trap 'rm -f "$TMP"' EXIT

echo "dumping structure of ${LIVE_DB} (no rows)..."
mysqldump -h"$DB_HOST" -u"$DB_USER" --no-data --skip-add-locks --skip-comments "$LIVE_DB" > "$TMP"
echo "  tables: $(grep -c 'CREATE TABLE' "$TMP")"

echo "recreating ${SCRATCH_DB}..."
mysql -h"$DB_HOST" -u"$DB_USER" -e \
  "DROP DATABASE IF EXISTS \`${SCRATCH_DB}\`; CREATE DATABASE \`${SCRATCH_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Foreign keys are switched off for the import because mysqldump emits tables alphabetically,
# not in dependency order.
{ echo "SET FOREIGN_KEY_CHECKS=0;"; cat "$TMP"; echo "SET FOREIGN_KEY_CHECKS=1;"; } \
  | mysql -h"$DB_HOST" -u"$DB_USER" "$SCRATCH_DB"

echo "done — $(mysql -h"$DB_HOST" -u"$DB_USER" -N -e \
  "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${SCRATCH_DB}';") tables, 0 rows"
