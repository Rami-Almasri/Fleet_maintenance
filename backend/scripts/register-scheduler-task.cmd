@echo off
REM ─────────────────────────────────────────────────────────────────────────────
REM  register-scheduler-task.cmd — install "FleetView Scheduler" as a permanent
REM  Windows Scheduled Task, so the Laravel scheduler (routes/console.php) ticks
REM  every minute FOREVER: on boot, on reboot, whether or not anyone is logged
REM  in, and independent of any dev-start.cmd window or open browser tab.
REM
REM  This is what actually makes inspections:generate-tasks (the Proactive
REM  Diagnostic Monitor — the canonical source of system-generated inspection
REM  requests, dailyAt 07:30) and every other Schedule:: entry in
REM  routes/console.php run unattended. Without this task registered, the
REM  scheduler only fires while a developer manually keeps a schedule:work
REM  window open (dev-start.cmd) — see docs/FleetView-Production-Readiness-Audit.md
REM  §3.6, previously unchecked.
REM
REM  MUST be run from an elevated (Administrator) command prompt — /RU SYSTEM
REM  requires it. Safe to re-run (uses /F to overwrite an existing task).
REM
REM  Verify   : schtasks /query /tn "FleetView Scheduler" /v /fo LIST
REM  Run now  : schtasks /run   /tn "FleetView Scheduler"
REM  Remove   : schtasks /delete /tn "FleetView Scheduler" /f
REM ─────────────────────────────────────────────────────────────────────────────

set "BACKEND=%~dp0.."
for %%I in ("%BACKEND%") do set "BACKEND=%%~fI"

schtasks /create ^
  /tn "FleetView Scheduler" ^
  /tr "wscript.exe \"%BACKEND%\run-scheduler.vbs\"" ^
  /sc MINUTE /mo 1 ^
  /ru SYSTEM ^
  /rl HIGHEST ^
  /f

if %ERRORLEVEL% EQU 0 (
  echo.
  echo "FleetView Scheduler" registered — ticks every minute, survives reboot,
  echo runs whether or not a user is logged in.
) else (
  echo.
  echo FAILED — re-run this script from an elevated ^(Administrator^) prompt.
)
