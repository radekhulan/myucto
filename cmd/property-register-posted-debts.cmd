@echo off
REM ============================================================================
REM  property-register-posted-debts.cmd — doplneni dluhu k zauctovanym predpisum SVJ
REM
REM  S --dry-run je READ-ONLY a jen vypise pocty. Bez nej zaregistruje dluhy
REM  predpisum zauctovanym pred zavedenim hromadne registrace; opakovani je bezpecne.
REM
REM  Pouziti:
REM    property-register-posted-debts.cmd --dry-run
REM    property-register-posted-debts.cmd --supplier=1
REM
REM  Navratovy kod: 0 = hotovo, 1 = zbyvaji predpisy s castecne rucnimi dluhy,
REM  2 = chyba argumentu, 3 = chyba pri registraci.
REM ============================================================================
setlocal
set "SCRIPT_DIR=%~dp0"
set "PROJECT_ROOT=%SCRIPT_DIR%.."
if defined MYINVOICE_PHP_BIN (set "PHP_BIN=%MYINVOICE_PHP_BIN%") else (set "PHP_BIN=php")
"%PHP_BIN%" "%PROJECT_ROOT%\api\bin\property-register-posted-debts.php" %*
exit /b %ERRORLEVEL%
