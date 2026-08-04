@echo off
REM ============================================================================
REM  Fleet Intelligence GOLDEN NUMBER suite runner (Windows / XAMPP).
REM
REM  Clones the live `laravel` schema into `laravel_golden`, applies any pending
REM  migrations to the CLONE, then asserts the numbers the platform was designed
REM  against. The live database is only ever READ (mysqldump), never written.
REM
REM  The clone is required because these tests need the real corpus: 26,942
REM  tickets and 49,487 fault labels. The CRUD suite's RefreshDatabase would
REM  wipe exactly the data under test.
REM
REM  Usage:   run-golden-tests.cmd              (clone + migrate + run)
REM           run-golden-tests.cmd --no-clone   (reuse the existing clone)
REM ============================================================================
setlocal
set MYSQL="C:\xampp\mysql\bin\mysql.exe"
set MYSQLDUMP="C:\xampp\mysql\bin\mysqldump.exe"
set DUMP=%TEMP%\laravel_golden_clone.sql

cd /d "%~dp0"

if "%~1"=="--no-clone" goto :migrate

echo Cloning live `laravel` into `laravel_golden` (live DB is read-only here)...
%MYSQL% -u root -e "DROP DATABASE IF EXISTS laravel_golden; CREATE DATABASE laravel_golden CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
if errorlevel 1 goto :fail

%MYSQLDUMP% -u root --single-transaction --routines --no-tablespaces laravel > "%DUMP%"
if errorlevel 1 goto :fail

%MYSQL% -u root laravel_golden < "%DUMP%"
if errorlevel 1 goto :fail

del "%DUMP%" 2>nul

:migrate
echo Applying pending migrations to the clone...
set DB_DATABASE=laravel_golden
php artisan migrate --force
if errorlevel 1 goto :fail

echo Running the golden number suite...
vendor\bin\phpunit -c phpunit.golden.xml
goto :eof

:fail
echo.
echo FAILED to prepare the golden clone. Is XAMPP MySQL running?
exit /b 1
