' FleetView — Laravel scheduler tick.
' Called every minute by the "FleetView Scheduler" Windows Task. Runs
' `php artisan schedule:run` HIDDEN (window style 0) so nothing flashes on
' screen. schedule:run itself only fires the jobs that are actually due
' (notifications:scan every 10 min, the nightly sync at 03:00, etc.).
Set sh = CreateObject("WScript.Shell")
sh.CurrentDirectory = "C:\Users\Rami Almasri\Desktop\fleet-fullstack\backend"
sh.Run """C:\xampp\php\php.exe"" artisan schedule:run", 0, False
