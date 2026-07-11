@echo off
REM ============================================================================
REM  Fleet CRUD + Notification smoke suite runner (Windows / XAMPP).
REM
REM  Boots the REAL app against an isolated MySQL schema (laravel_test) — same
REM  engine as production (MariaDB via XAMPP) — so it exercises exactly what the
REM  boss will see, while NEVER touching the live `laravel` data.
REM
REM  Usage:   run-crud-tests.cmd            (whole suite)
REM           run-crud-tests.cmd NotificationTest   (one test class)
REM ============================================================================
setlocal
set MYSQL="C:\xampp\mysql\bin\mysql.exe"

echo Ensuring isolated test schema (laravel_test) exists...
%MYSQL% -u root -e "CREATE DATABASE IF NOT EXISTS laravel_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

cd /d "%~dp0"

if "%~1"=="" (
    vendor\bin\phpunit -c phpunit.crud.xml
) else (
    vendor\bin\phpunit -c phpunit.crud.xml --filter %1
)

endlocal
