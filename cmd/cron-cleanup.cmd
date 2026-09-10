@echo off
REM ============================================================================
REM  cron-cleanup.cmd — denní úklid DB a souborů
REM  Frekvence: 1x denne, doporuceno 03:00
REM
REM  Smaze: login_attempts >24h, expirovane sessions, pouzite password_resets,
REM         ARES/VIES cache >30 dni, PDF cache >90 dni, log files nad max_files,
REM         nahrane soubory davek skenu (opustene >48 h, skoncene >7 dni).
REM
REM  Task Scheduler:
REM    schtasks /create /tn "MyUcto Cleanup" ^
REM      /tr "%~f0" /sc daily /st 03:00 /ru SYSTEM
REM ============================================================================
setlocal
set "SCRIPT_DIR=%~dp0"
set "PROJECT_ROOT=%SCRIPT_DIR%.."
if defined MYINVOICE_DATA_DIR (set "LOG_DIR=%MYINVOICE_DATA_DIR%\log\cron") else (set "LOG_DIR=%PROJECT_ROOT%\log\cron")
if defined MYINVOICE_PHP_BIN (set "PHP_BIN=%MYINVOICE_PHP_BIN%") else (set "PHP_BIN=php")
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"
for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd"') do set "TODAY=%%i"
"%PHP_BIN%" "%PROJECT_ROOT%\api\bin\cron-cleanup.php" %* >> "%LOG_DIR%\cleanup-%TODAY%.log" 2>&1
exit /b %ERRORLEVEL%
