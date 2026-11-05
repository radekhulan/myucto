@echo off
REM ============================================================================
REM  cron-maintenance-notice.cmd — stazeni ohlaseni planovane udrzby
REM  Frekvence: kazdych 15 minut.
REM
REM  Stahne hlasky provozovatele (od-do, text) do tabulky `app_meta`.
REM  Aplikace je pak ukazuje v pruhu nahore a na prihlaseni.
REM
REM  Task Scheduler:
REM    schtasks /create /tn "MyUcto Maintenance Notice" ^
REM      /tr "%~f0" /sc minute /mo 15 /ru SYSTEM
REM ============================================================================
setlocal
set "SCRIPT_DIR=%~dp0"
set "PROJECT_ROOT=%SCRIPT_DIR%.."
if defined MYINVOICE_DATA_DIR (set "LOG_DIR=%MYINVOICE_DATA_DIR%\log\cron") else (set "LOG_DIR=%PROJECT_ROOT%\log\cron")
if defined MYINVOICE_PHP_BIN (set "PHP_BIN=%MYINVOICE_PHP_BIN%") else (set "PHP_BIN=php")
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"
for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd"') do set "TODAY=%%i"
"%PHP_BIN%" "%PROJECT_ROOT%\api\bin\cron-maintenance-notice.php" %* >> "%LOG_DIR%\maintenance-notice-%TODAY%.log" 2>&1
exit /b %ERRORLEVEL%
