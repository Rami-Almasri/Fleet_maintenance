@echo off
REM ============================================================================
REM  INTELLIGENCE FRESHNESS WATCHDOG — the half that lives OUTSIDE Laravel.
REM
REM  Registers a Windows Scheduled Task that runs the freshness check daily at
REM  06:05, independently of Laravel's own scheduler.
REM
REM  WHY OUTSIDE. A watchdog registered inside the scheduler it watches is only
REM  half a watchdog: if that scheduler stops, the alert stops with it and the
REM  silence is indistinguishable from health. routes/console.php has an entry
REM  too — it catches the likelier failure (a rebuild that runs and fails) — but
REM  only THIS one survives the scheduler itself dying.
REM
REM  WHAT IT GUARDS. Every recurrence figure on the platform now reads two
REM  nightly-rebuilt tables: the fleet comeback rate, every garage score, the
REM  Garage x Fault matrix, Repair Intelligence. If the rebuild stops, nothing
REM  breaks visibly — the pages keep rendering month-old numbers and looking
REM  exactly as authoritative as they did yesterday.
REM
REM  Run this ONCE, from an elevated prompt, on the server.
REM
REM  Usage:   install-health-watchdog.cmd
REM           install-health-watchdog.cmd /remove
REM ============================================================================
setlocal

set TASKNAME=FleetIntelligenceFreshness
set RUNAT=06:05

if /I "%~1"=="/remove" goto :remove

REM Resolve PHP and this project's path so the task does not depend on PATH or
REM on whatever directory the scheduler happens to start in.
for /f "delims=" %%P in ('where php 2^>nul') do set PHPBIN=%%P& goto :gotphp
:gotphp
if "%PHPBIN%"=="" (
    echo ERROR: php was not found on PATH. Install PHP or edit PHPBIN below.
    exit /b 1
)

set PROJECT=%~dp0
if "%PROJECT:~-1%"=="\" set PROJECT=%PROJECT:~0,-1%

echo Registering "%TASKNAME%"
echo   php      : %PHPBIN%
echo   project  : %PROJECT%
echo   runs at  : %RUNAT% daily
echo.

schtasks /Create ^
  /TN "%TASKNAME%" ^
  /TR "cmd /c cd /d \"%PROJECT%\" && \"%PHPBIN%\" artisan intelligence:rebuild-health --alert" ^
  /SC DAILY ^
  /ST %RUNAT% ^
  /RL HIGHEST ^
  /F

if errorlevel 1 (
    echo.
    echo FAILED. Run this from an ELEVATED command prompt ^(Run as administrator^).
    exit /b 1
)

echo.
echo Registered. Verifying by running it once now...
echo.
cd /d "%PROJECT%"
"%PHPBIN%" artisan intelligence:rebuild-health

echo.
echo ----------------------------------------------------------------------
echo  Done. The task alerts the maintenance managers in-app AND exits
echo  non-zero, so it works whether a person or a monitor is watching.
echo.
echo  Check it:    schtasks /Query /TN "%TASKNAME%" /V /FO LIST
echo  Run it now:  schtasks /Run   /TN "%TASKNAME%"
echo  Remove it:   install-health-watchdog.cmd /remove
echo ----------------------------------------------------------------------
goto :eof

:remove
schtasks /Delete /TN "%TASKNAME%" /F
echo Removed "%TASKNAME%".
goto :eof
