@echo off
REM ============================================================================
REM  cron-backup-payroll.cmd — denni zaloha mzdoveho uloziste
REM  (storage/payroll-documents/, payroll-period-exports/, payroll-payment-exports/)
REM  do storage/backup/{dbname}-payroll-YYYY-MM-DD.zip
REM
REM  Oddelene od cron-backup-documents.cmd zamerne: mzdove soubory lezi jinde
REM  a do zadne z ostatnich zaloh nespadaly — po obnove by zbyla metadata bez
REM  obsahu, a to u dokumentu se zakonnou archivacni lhutou.
REM  Frekvence: 1x denne, doporuceno 02:40 (PO cron-backup-documents)
REM  Retention: 30 dennich + mesicni (1. v mesici) drzeny 365 dni
REM
REM  Task Scheduler:
REM    schtasks /create /tn "MyUcto BackupPayroll" ^
REM      /tr "%~f0" /sc daily /st 02:40 /ru SYSTEM
REM ============================================================================
setlocal
set "SCRIPT_DIR=%~dp0"
set "PROJECT_ROOT=%SCRIPT_DIR%.."
if defined MYINVOICE_DATA_DIR (set "LOG_DIR=%MYINVOICE_DATA_DIR%\log\cron") else (set "LOG_DIR=%PROJECT_ROOT%\log\cron")
if defined MYINVOICE_PHP_BIN (set "PHP_BIN=%MYINVOICE_PHP_BIN%") else (set "PHP_BIN=php")
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"
for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd"') do set "TODAY=%%i"
"%PHP_BIN%" "%PROJECT_ROOT%\api\bin\cron-backup-payroll.php" %* >> "%LOG_DIR%\backup-payroll-%TODAY%.log" 2>&1
exit /b %ERRORLEVEL%
