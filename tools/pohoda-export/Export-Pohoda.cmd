@echo off
rem Export vsech agend z POHODY do XML. Spusteni dvojklikem, nebo s parametry, napr.:
rem   Export-Pohoda.cmd -Uzivatel Admin -Rok 2026
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0Export-Pohoda.ps1" %*
echo.
pause
