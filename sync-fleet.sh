#!/usr/bin/env bash
# ============================================================================
#  Fleet full sync - fire-and-forget CLI runner (Linux / macOS)
#  Runs `php artisan fleet:refresh`, logs to a timestamped file, retries on
#  failure, and uses flock so two runs never overlap. Schedule from cron.
# ============================================================================
set -uo pipefail

# --- CONFIG ---------------------------------------------------------------
PHP="${PHP:-php}"
ARTISAN_DIR="${ARTISAN_DIR:-/var/www/fleet-fullstack/backend}"
MAX_ATTEMPTS="${MAX_ATTEMPTS:-3}"
RETRY_WAIT="${RETRY_WAIT:-120}"
MEM="${MEM:-1024M}"
PRUNE_DAYS="${PRUNE_DAYS:-14}"
# -------------------------------------------------------------------------

# Target: full sync by default, or a single phase. Usage: ./sync-fleet.sh [target]
TARGET="${1:-all}"
case "$TARGET" in
  all)           ARTISAN_CMD="fleet:refresh" ;;
  cars)          ARTISAN_CMD="om:sync --vehicles --skip-backup" ;;
  carinfo)       ARTISAN_CMD="sync:vehicles" ;;
  registrations) ARTISAN_CMD="sync:registrations" ;;
  insurance)     ARTISAN_CMD="sync:insurance" ;;
  contracts)     ARTISAN_CMD="om:sync --contracts --months=6 --skip-backup" ;;
  contracts-all) ARTISAN_CMD="om:sync --contracts --months=0 --skip-backup" ;;
  invoices)      ARTISAN_CMD="om:sync --invoices --skip-backup" ;;
  customers)     ARTISAN_CMD="om:sync --customers-bulk --skip-backup" ;;
  maintenance)   ARTISAN_CMD="import:maintenance-sheet" ;;
  garages)       ARTISAN_CMD="garages:sync" ;;
  *) echo "Unknown target '$TARGET'. Valid: all cars carinfo registrations insurance contracts contracts-all invoices customers maintenance garages"; exit 2 ;;
esac

LOG_DIR="$ARTISAN_DIR/storage/logs/sync"
mkdir -p "$LOG_DIR"
STAMP="$(date +%Y%m%d-%H%M%S)"
LOG="$LOG_DIR/sync-$TARGET-$STAMP.log"
LOCK="$LOG_DIR/sync.lock"

cd "$ARTISAN_DIR" || { echo "Cannot cd to $ARTISAN_DIR"; exit 1; }

# Single-instance lock: exit immediately if another run holds it.
exec 9>"$LOCK"
if ! flock -n 9; then
  echo "[$(date)] Another sync is already running. Exiting." | tee -a "$LOG"
  exit 0
fi

log() { echo "[$(date '+%F %T')] $*" | tee -a "$LOG"; }

attempt=1
while :; do
  log "===== Attempt $attempt/$MAX_ATTEMPTS : $TARGET ($ARTISAN_CMD) ====="
  "$PHP" -d memory_limit="$MEM" artisan $ARTISAN_CMD --no-interaction >>"$LOG" 2>&1
  code=$?
  log "fleet:refresh exited with code $code"

  if [ "$code" -eq 0 ]; then log "SUCCESS"; break; fi
  if [ "$attempt" -ge "$MAX_ATTEMPTS" ]; then log "FAILED after $MAX_ATTEMPTS attempt(s)"; break; fi
  log "Retrying in ${RETRY_WAIT}s (idempotent - it resumes)..."
  sleep "$RETRY_WAIT"
  attempt=$((attempt + 1))
done

# Prune old backups + logs.
find "$ARTISAN_DIR/storage/app/backups" -name '*.sql' -mtime +"$PRUNE_DAYS" -delete 2>/dev/null || true
find "$LOG_DIR" -name 'sync-*.log' -mtime +"$PRUNE_DAYS" -delete 2>/dev/null || true

log "Done. Log: $LOG"
# Cron example (nightly 02:30):  30 2 * * *  /var/www/fleet-fullstack/sync-fleet.sh
exit "$code"
