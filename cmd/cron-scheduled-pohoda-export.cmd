@echo off
REM ============================================================================
REM  cron-scheduled-pohoda-export.cmd — planovany export vydanych dokladu do
REM  Pohody (XML e-mailem ucetni). Frekvence: 1x denne, doporuceno 06:30
REM
REM  Task Scheduler:
REM    schtasks /create /tn "MyUcto Pohoda Export" /tr "%~f0" /sc daily /st 06:30 /ru SYSTEM
REM ============================================================================
setlocal
set "SCRIPT_DIR=%~dp0"
set "PROJECT_ROOT=%SCRIPT_DIR%.."
if defined MYINVOICE_DATA_DIR (set "LOG_DIR=%MYINVOICE_DATA_DIR%\log\cron") else (set "LOG_DIR=%PROJECT_ROOT%\log\cron")
if defined MYINVOICE_PHP_BIN (set "PHP_BIN=%MYINVOICE_PHP_BIN%") else (set "PHP_BIN=php")
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"
for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd"') do set "TODAY=%%i"
"%PHP_BIN%" "%PROJECT_ROOT%\api\bin\cron-scheduled-pohoda-export.php" %* >> "%LOG_DIR%\scheduled-pohoda-export-%TODAY%.log" 2>&1
exit /b %ERRORLEVEL%
