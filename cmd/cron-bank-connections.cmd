@echo off
setlocal
set "SCRIPT_DIR=%~dp0"
set "PROJECT_ROOT=%SCRIPT_DIR%.."
if defined MYINVOICE_PHP_BIN (set "PHP_EXE=%MYINVOICE_PHP_BIN%") else (set "PHP_EXE=php")
if defined MYINVOICE_DATA_DIR (set "LOG_DIR=%MYINVOICE_DATA_DIR%\log\cron") else (set "LOG_DIR=%PROJECT_ROOT%\log\cron")
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"
for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd"') do set "TODAY=%%i"
"%PHP_EXE%" "%PROJECT_ROOT%\api\bin\cron-bank-connections.php" %* >> "%LOG_DIR%\bank-connections-%TODAY%.log" 2>&1
exit /b %ERRORLEVEL%
