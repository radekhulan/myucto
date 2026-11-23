@echo off
REM ============================================================================
REM  cron-payroll-end-agreements.cmd — ukonceni dohod po uplynuti sjednaneho
REM  konce
REM  Frekvence: 1x denne 00:30
REM
REM  DPP a DPC se sjednanym koncem v minulosti ukonci ke dni sjednaneho konce
REM  stejnou cestou jako rucni akce "Ukoncit skoncene dohody" (kontrolni seznam
REM  odchodu, auditni stopa s akterem system). Dohodu s mzdovym vstupem,
REM  dochazkou nebo zuctovanym behem po konci necha byt k posouzeni clovekem.
REM
REM  Bezi jen tam, kde jsou zapnute mzdy (supplier.payroll_enabled). Opakovane
REM  spusteni je bezpecne - ukoncena dohoda uz kandidatem neni.
REM
REM  Volitelne argumenty:
REM    --supplier=ID         jen jedna firma (lze opakovat)
REM    --today=YYYY-MM-DD    rozhodny den (default dnesek)
REM
REM  Task Scheduler (denne 00:30):
REM    schtasks /create /tn "MyUcto PayrollEndAgreements" ^
REM      /tr "%~f0" /sc daily /st 00:30 /ru SYSTEM
REM ============================================================================
setlocal
set "SCRIPT_DIR=%~dp0"
set "PROJECT_ROOT=%SCRIPT_DIR%.."
if defined MYINVOICE_DATA_DIR (set "LOG_DIR=%MYINVOICE_DATA_DIR%\log\cron") else (set "LOG_DIR=%PROJECT_ROOT%\log\cron")
if defined MYINVOICE_PHP_BIN (set "PHP_BIN=%MYINVOICE_PHP_BIN%") else (set "PHP_BIN=php")
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"
for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd"') do set "TODAY=%%i"
"%PHP_BIN%" "%PROJECT_ROOT%\api\bin\cron-payroll-end-agreements.php" %* >> "%LOG_DIR%\payroll-end-agreements-%TODAY%.log" 2>&1
exit /b %ERRORLEVEL%
