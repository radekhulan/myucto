@echo off
REM ============================================================================
REM  cron-payroll-registration-changes.cmd — detekce hlasitelnych zmen v
REM  registru pojistencu (CSSZ)
REM  Frekvence: 1x denne 05:00
REM
REM  Detekce zaklada navrh povinnosti a tim rozjizdi osmidennou lhutu
REM  (par. 19 odst. 5 zakona c. 323/2025 Sb.). Bez cronu se spousti jen pri
REM  otevreni karty zamestnance nebo na tlacitko ve fronte podani - u stovky
REM  zamestnancu tak lhuty tise utikaji.
REM
REM  Skript NIC NEODESILA. Vznikne pouze navrh; podani z nej vyrobi a odesle
REM  clovek z fronty Mzdy -> Podani a hlaseni.
REM
REM  Bezi jen tam, kde jsou zapnute mzdy (supplier.payroll_enabled). Opakovane
REM  spusteni je bezpecne - porovnavaji se jen vztahy, u kterych se pohnul
REM  vodoznak zdroje (payroll_registration_change_scans.source_watermark).
REM
REM  Volitelne argumenty:
REM    --environment=test    detekce v testovacim prostredi (default production)
REM    --supplier=ID         jen jedna firma (lze opakovat)
REM    --batch=N             velikost porce vztahu (default 200)
REM    --max-batches=N       strop porci na firmu a beh (default 25)
REM
REM  Task Scheduler (denne 05:00):
REM    schtasks /create /tn "MyUcto PayrollRegistrationChanges" ^
REM      /tr "%~f0" /sc daily /st 05:00 /ru SYSTEM
REM ============================================================================
setlocal
set "SCRIPT_DIR=%~dp0"
set "PROJECT_ROOT=%SCRIPT_DIR%.."
if defined MYINVOICE_DATA_DIR (set "LOG_DIR=%MYINVOICE_DATA_DIR%\log\cron") else (set "LOG_DIR=%PROJECT_ROOT%\log\cron")
if defined MYINVOICE_PHP_BIN (set "PHP_BIN=%MYINVOICE_PHP_BIN%") else (set "PHP_BIN=php")
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"
for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd"') do set "TODAY=%%i"
"%PHP_BIN%" "%PROJECT_ROOT%\api\bin\cron-payroll-registration-changes.php" %* >> "%LOG_DIR%\payroll-registration-changes-%TODAY%.log" 2>&1
exit /b %ERRORLEVEL%
