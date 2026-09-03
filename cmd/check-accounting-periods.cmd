@echo off
REM ============================================================================
REM  check-accounting-periods.cmd — kontrola uctenich obdobi existujici instalace
REM
REM  Bez --fix je READ-ONLY (lze pustit i proti produkci). S --fix otevre jen
REM  chybejici NAVAZUJICI obdobi (zapomenuty prelom roku); historii nikdy.
REM
REM  Pouziti:
REM    check-accounting-periods.cmd
REM    check-accounting-periods.cmd --supplier=1
REM    check-accounting-periods.cmd --fix
REM
REM  Navratovy kod: 0 = bez nalezu, 1 = zbyva nalez, 2 = chyba argumentu.
REM ============================================================================
setlocal
set "SCRIPT_DIR=%~dp0"
set "PROJECT_ROOT=%SCRIPT_DIR%.."
if defined MYINVOICE_PHP_BIN (set "PHP_BIN=%MYINVOICE_PHP_BIN%") else (set "PHP_BIN=php")
"%PHP_BIN%" "%PROJECT_ROOT%\api\bin\check-accounting-periods.php" %*
exit /b %ERRORLEVEL%
