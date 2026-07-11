@echo off
REM ─────────────────────────────────────────────────────────────────────────────
REM  dev-start.cmd — bring the Laravel backend fully to life for local dev.
REM
REM  Starts TWO long-lived processes, each in its own window:
REM    1. php artisan serve        — the HTTP API (http://127.0.0.1:8000)
REM    2. php artisan schedule:work — the scheduler tick (wakes every minute and
REM       runs whatever cron entry from routes/console.php is due).
REM
REM  WHY this matters: on Windows/XAMPP there is no system cron, and `serve` does
REM  NOT run the scheduler. Without schedule:work, notifications:scan (every 10 min),
REM  the nightly om:sync, mileage:scan, etc. NEVER fire — so the in-app bell goes
REM  silent and data goes stale. Keep BOTH windows open while you work.
REM
REM  Closing a window stops that process. Closing the schedule:work window simply
REM  stops the scheduler — reopen by re-running this script.
REM ─────────────────────────────────────────────────────────────────────────────

REM  Resolve the backend root (this file lives in backend\scripts\).
set "BACKEND=%~dp0.."
pushd "%BACKEND%"

echo Starting Laravel dev environment from "%CD%"
echo   - API server      : http://127.0.0.1:8000
echo   - Scheduler (cron) : notifications:scan every 10 min, nightly syncs, etc.
echo.

REM  Run an immediate scan so the bell is fresh the moment you sit down,
REM  instead of waiting up to 10 minutes for the first tick.
echo Priming notifications (one-off scan)...
php artisan notifications:scan
echo.

start "fleet: artisan serve"   cmd /k "cd /d "%BACKEND%" && php artisan serve"
start "fleet: schedule:work"   cmd /k "cd /d "%BACKEND%" && php artisan schedule:work"

echo Both processes launched in separate windows. Leave them open.
popd
