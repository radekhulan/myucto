@echo off
setlocal
powershell.exe -NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -File "%~dp0cron-jmhz-source-monitor.ps1" %*
exit /b %ERRORLEVEL%
