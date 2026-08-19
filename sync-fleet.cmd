@echo off
REM ===========================================================================
REM  Fleet sync runner (Windows) - run ANY phase alone, or everything.
REM
REM  Double-click  -> shows a menu, pick what to sync.
REM  Or pass a target (for Task Scheduler / quick runs):
REM      sync-fleet.cmd all            (full sync incl. maintenance, backs up first)
REM      sync-fleet.cmd fleet          (WHICH CARS EXIST - the "Faster" sheet register)
REM      sync-fleet.cmd carinfo        (same thing; kept as the old name for scheduled tasks)
REM      sync-fleet.cmd cars           (refresh those cars from the API - never adds a car)
REM      sync-fleet.cmd fleet-audit    (read-only: cars we hold that the register does not list)
REM      sync-fleet.cmd registrations  (RTA fines, from sheet)
REM      sync-fleet.cmd insurance      (insurance + Mulkiya)
REM      sync-fleet.cmd contracts      (contracts - last 6 months, fast)
REM      sync-fleet.cmd contracts-all  (contracts - ALL history, slow)
REM      sync-fleet.cmd invoices       (invoices)
REM      sync-fleet.cmd customers      (customer names)
REM      sync-fleet.cmd maintenance    (maintenance log, from sheet)
REM      sync-fleet.cmd customer-cases (customer-charge maintenance cases, from sheet)
REM      sync-fleet.cmd garages        (garages + parts shops -> vendors, from sheet)
REM
REM  Every run logs to backend\storage\logs\sync\ and retries on failure.
REM ===========================================================================
setlocal enabledelayedexpansion

REM --- CONFIG (edit if your paths differ) -----------------------------------
set "PHP=C:\xampp\php\php.exe"
set "ARTISAN_DIR=C:\Users\Rami Almasri\Desktop\fleet-fullstack\backend"
set "MAX_ATTEMPTS=3"
set "RETRY_WAIT=120"
set "MEM=1024M"
set "PRUNE_DAYS=14"
REM -------------------------------------------------------------------------

set "INTERACTIVE="
set "TARGET=%~1"
if not "%TARGET%"=="" goto resolve

REM --- no argument: show the menu --------------------------------------------
set "INTERACTIVE=1"
:menu
echo(
echo   ================= FLEET SYNC =================
echo     1.  Full sync  (everything incl. maintenance - backs up first)
echo     2.  Fleet register  (sheet: WHICH CARS EXIST - run this first)
echo     3.  Cars            (API: VIN/plate/year/status/odometer - adds no car)
echo     4.  Registrations   (sheet: RTA fines)
echo     5.  Insurance + Mulkiya
echo     6.  Contracts - last 6 months   (fast: all open + recent)
echo     7.  Contracts - ALL history     (slow: every contract)
echo     8.  Invoices
echo     9.  Customer names
echo    10.  Maintenance log  (sheet: N-Maintenance ^& Repair)
echo    11.  Garages          (sheet: garages ^& parts shops -^> vendors)
echo    12.  Customer cases   (sheet: customer-charge maintenance log)
echo    13.  Fleet audit      (read-only: cars we hold that the register omits)
echo     0.  Quit
echo   =============================================
echo(
set "TARGET="
set /p "CHOICE=Pick a number then press Enter: "
if "%CHOICE%"=="0" goto :eof
if "%CHOICE%"=="1" set "TARGET=all"
if "%CHOICE%"=="2" set "TARGET=fleet"
if "%CHOICE%"=="3" set "TARGET=cars"
if "%CHOICE%"=="4" set "TARGET=registrations"
if "%CHOICE%"=="5" set "TARGET=insurance"
if "%CHOICE%"=="6" set "TARGET=contracts"
if "%CHOICE%"=="7" set "TARGET=contracts-all"
if "%CHOICE%"=="8" set "TARGET=invoices"
if "%CHOICE%"=="9" set "TARGET=customers"
if "%CHOICE%"=="10" set "TARGET=maintenance"
if "%CHOICE%"=="11" set "TARGET=garages"
if "%CHOICE%"=="12" set "TARGET=customer-cases"
if "%CHOICE%"=="13" set "TARGET=fleet-audit"
if not defined TARGET ( echo   Invalid choice - try again. & goto menu )

:resolve
set "CMD="
if /i "%TARGET%"=="all"           ( set "CMD=fleet:refresh"                          & set "NAME=Full sync" )
if /i "%TARGET%"=="cars"          ( set "CMD=om:sync --vehicles --skip-backup"       & set "NAME=Cars (API)" )
if /i "%TARGET%"=="fleet"         ( set "CMD=sync:vehicles"                          & set "NAME=Fleet register (sheet)" )
if /i "%TARGET%"=="carinfo"       ( set "CMD=sync:vehicles"                          & set "NAME=Fleet register (sheet)" )
if /i "%TARGET%"=="fleet-audit"   ( set "CMD=fleet:register-audit"                   & set "NAME=Fleet register audit (read-only)" )
if /i "%TARGET%"=="registrations" ( set "CMD=sync:registrations"                     & set "NAME=Registrations (sheet)" )
if /i "%TARGET%"=="insurance"     ( set "CMD=sync:insurance"                         & set "NAME=Insurance + Mulkiya" )
if /i "%TARGET%"=="contracts"     ( set "CMD=om:sync --contracts --months=6 --skip-backup" & set "NAME=Contracts (last 6 months)" )
if /i "%TARGET%"=="contracts-all" ( set "CMD=om:sync --contracts --months=0 --skip-backup" & set "NAME=Contracts (ALL history)" )
if /i "%TARGET%"=="invoices"      ( set "CMD=om:sync --invoices --skip-backup"       & set "NAME=Invoices (API)" )
if /i "%TARGET%"=="customers"     ( set "CMD=om:sync --customers-bulk --skip-backup" & set "NAME=Customer names (API)" )
if /i "%TARGET%"=="maintenance"   ( set "CMD=import:maintenance-sheet"               & set "NAME=Maintenance log (sheet)" )
if /i "%TARGET%"=="customer-cases" ( set "CMD=import:customer-cases"                 & set "NAME=Customer cases (sheet)" )
if /i "%TARGET%"=="garages"       ( set "CMD=garages:sync"                           & set "NAME=Garages to vendors (sheet)" )
if not defined CMD (
  echo Unknown target "%TARGET%". Valid: all fleet cars carinfo fleet-audit registrations insurance contracts invoices customers maintenance customer-cases garages
  if defined INTERACTIVE pause
  goto :eof
)

set "LOG_DIR=%ARTISAN_DIR%\storage\logs\sync"
for /f %%I in ('powershell -NoProfile -Command "Get-Date -Format yyyyMMdd-HHmmss"') do set "STAMP=%%I"
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"
set "LOG=%LOG_DIR%\sync-%TARGET%-%STAMP%.log"

REM --- one-at-a-time guard: refuse only if a sync is GENUINELY running (checks live php
REM     processes, not a lock file that can go stale; ignores `php artisan serve`). ---
set "PSCHK=%TEMP%\fleetsync_check_%RANDOM%.ps1"
>"%PSCHK%" echo @(Get-CimInstance Win32_Process ^| Where-Object { $_.Name -eq 'php.exe' -and $_.CommandLine -match 'om:sync^|fleet:refresh^|sync:^|import:^|garages:' }).Count
set "RUNNING=0"
for /f %%R in ('powershell -NoProfile -ExecutionPolicy Bypass -File "%PSCHK%"') do set "RUNNING=%%R"
del "%PSCHK%" 2>nul
if not "!RUNNING!"=="0" (
  echo A sync is already running ^(!RUNNING! process^). Please wait for it to finish.
  if defined INTERACTIVE pause
  goto :eof
)

cd /d "%ARTISAN_DIR%"
echo Running "%NAME%" ... (log: %LOG%)

set /a attempt=1
:run
echo [%date% %time%] ===== Attempt !attempt!/%MAX_ATTEMPTS% : %NAME% (%CMD%) =====>> "%LOG%"
"%PHP%" -d memory_limit=%MEM% artisan %CMD% --no-interaction >> "%LOG%" 2>&1
set "CODE=!errorlevel!"
echo [%date% %time%] exited with code !CODE!>> "%LOG%"

if "!CODE!"=="0" ( echo [%date% %time%] SUCCESS>> "%LOG%" & goto cleanup )
if !attempt! geq %MAX_ATTEMPTS% ( echo [%date% %time%] FAILED after %MAX_ATTEMPTS% attempt(s)>> "%LOG%" & goto cleanup )
echo [%date% %time%] retry in %RETRY_WAIT%s (idempotent - it resumes)...>> "%LOG%"
timeout /t %RETRY_WAIT% /nobreak >nul
set /a attempt+=1
goto run

:cleanup
if exist "%ARTISAN_DIR%\storage\app\backups" forfiles /p "%ARTISAN_DIR%\storage\app\backups" /m *.sql /d -%PRUNE_DAYS% /c "cmd /c del @path" 2>nul
forfiles /p "%LOG_DIR%" /m sync-*.log /d -%PRUNE_DAYS% /c "cmd /c del @path" 2>nul
echo Done (exit code !CODE!). Log: %LOG%
if defined INTERACTIVE pause
endlocal

REM ---------------------------------------------------------------------------
REM  Schedule examples (run in an ADMIN cmd):
REM    Nightly full sync at 02:30:
REM      schtasks /create /tn "FleetSync-All" /tr "\"%~f0\" all" /sc DAILY /st 02:30 /rl HIGHEST
REM    Contracts every 3 hours:
REM      schtasks /create /tn "FleetSync-Contracts" /tr "\"%~f0\" contracts" /sc HOURLY /mo 3 /rl HIGHEST
REM ---------------------------------------------------------------------------
