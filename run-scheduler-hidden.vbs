' ---------------------------------------------------------------------------
' Runs run-scheduler.cmd with no console window.
'
' The "FleetView Scheduler" task fires every minute. Pointed straight at the
' .cmd it flashed a terminal on the desktop once a minute; the proper fix (the
' task's S4U/background logon) needs admin rights, so the task points here
' instead and this launches the .cmd with the window hidden (0) and does not
' wait (False).
' ---------------------------------------------------------------------------

Dim shell, here
Set shell = CreateObject("WScript.Shell")
here = Left(WScript.ScriptFullName, InStrRev(WScript.ScriptFullName, "\"))
shell.Run """" & here & "run-scheduler.cmd""", 0, False
