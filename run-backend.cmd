@echo off
REM Supervisor for the Laravel dev server on port 8001.
REM Restarts `php artisan serve` if it ever exits. Close this window to stop it.
cd /d "%~dp0backend"
:loop
echo [%date% %time%] starting php artisan serve on 127.0.0.1:8001
php artisan serve --host=127.0.0.1 --port=8001
echo [%date% %time%] server exited, restarting in 3s
timeout /t 3 /nobreak >nul
goto loop
