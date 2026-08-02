#!/bin/sh
# =============================================================================
# FleetView backend container entrypoint.
# Roles (CONTAINER_ROLE env): app (default) | scheduler | queue
#   - Every role: wait for the DB, then (re)build the production caches so the
#     running code matches its config/routes/views.
#   - app role only: run migrations + storage:link (must happen exactly once,
#     not in every replica/worker).
# Then exec the container's command (php-fpm / schedule:work / queue:work).
# =============================================================================
set -e

ROLE="${CONTAINER_ROLE:-app}"
DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-3306}"

echo "[entrypoint] role=${ROLE} — waiting for database ${DB_HOST}:${DB_PORT} ..."
# Pure-PHP TCP probe (no extra packages needed). Give up after ~60s.
i=0
until php -r '$h=getenv("DB_HOST")?:"db"; $p=(int)(getenv("DB_PORT")?:3306); exit(@fsockopen($h,$p,$e,$s,2)?0:1);'; do
    i=$((i+1))
    if [ "$i" -ge 30 ]; then
        echo "[entrypoint] ERROR: database not reachable after 60s — aborting." >&2
        exit 1
    fi
    sleep 2
done
echo "[entrypoint] database is up."

# ---- Run-once tasks: migrations + public storage symlink (app role only) ----
if [ "$ROLE" = "app" ]; then
    echo "[entrypoint] running migrations (--force) ..."
    php artisan migrate --force

    # Public symlink for locally-stored media. Non-fatal: nginx also serves
    # /storage via a direct alias, so a failure here never blocks startup.
    php artisan storage:link 2>/dev/null || true

    # ---- Knowledge graph bootstrap -----------------------------------------
    # The fault ontology arrives in two halves and only one of them is seeded.
    # `db:seed` writes the vocabulary (terms + keyword_profiles); the typed graph
    # in ontology_nodes / ontology_edges is written ONLY by this command, which no
    # seeder calls. Production ran for a day in exactly that state: full knowledge
    # coverage on the admin page, working search, and every match card silently
    # missing its "usually caused by / usually fixed by" lines, because those read
    # the edges and not the profile JSON. Nothing surfaced it — the gap looked like
    # a UI bug for as long as anyone was checking the seeded tables.
    #
    # --if-empty makes it a one-time bootstrap: an already-built graph costs one
    # COUNT, and the full build only runs on an install that has never had one.
    # Non-fatal deliberately — a knowledge-graph failure must not keep the whole
    # site down — but it says so loudly rather than passing in silence.
    echo "[entrypoint] ensuring the ontology graph exists ..."
    php artisan ontology:build-graph --if-empty \
        || echo "[entrypoint] WARNING: ontology:build-graph failed — search works, but match cards will omit causes/repairs. Run it by hand." >&2
fi

# ---- Production optimization caches (all roles) -----------------------------
# Rebuilt on every boot so a new image/config is always reflected. Clear first
# so a stale cache from a previous build can never be served.
php artisan config:clear >/dev/null 2>&1 || true
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "[entrypoint] ready — exec: $*"
exec "$@"
