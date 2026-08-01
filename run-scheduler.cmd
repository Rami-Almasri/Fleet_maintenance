@echo off
REM ---------------------------------------------------------------------------
REM Drives Laravel's scheduler on Windows.
REM
REM Laravel's schedule (backend/routes/console.php) does NOT run itself — one
REM process must call `artisan schedule:run` every minute, and that call is what
REM decides which of the 21 jobs are due. Without it NOTHING is scheduled: the OM
REM sync, the Status-sheet overlay, the notification scans and the nightly
REM intelligence rebuilds all simply never fire.
REM
REM Registered as the Windows Scheduled Task "FleetView Scheduler" (every 1 min).
REM   view:    schtasks /Query /TN "FleetView Scheduler"
REM   stop:    schtasks /Change /TN "FleetView Scheduler" /DISABLE
REM   remove:  schtasks /Delete /TN "FleetView Scheduler" /F
REM
REM Output is appended to backend\storage\logs\schedule.log — check there first
REM when a scheduled job seems not to have run.
REM ---------------------------------------------------------------------------

cd /d "%~dp0backend"
"C:\xampp\php\php.exe" artisan schedule:run >> "storage\logs\schedule.log" 2>&1
