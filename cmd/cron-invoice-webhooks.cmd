@echo off
setlocal
set "PROJECT_ROOT=%~dp0.."
if defined MYINVOICE_DATA_DIR (set "LOG_DIR=%MYINVOICE_DATA_DIR%\log\cron") else (set "LOG_DIR=%PROJECT_ROOT%\log\cron")
if defined MYINVOICE_PHP_BIN (set "PHP_BIN=%MYINVOICE_PHP_BIN%") else (set "PHP_BIN=php")
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"
"%PHP_BIN%" "%PROJECT_ROOT%\api\bin\cron-invoice-webhooks.php" %* >> "%LOG_DIR%\invoice-webhooks.log" 2>&1
exit /b %ERRORLEVEL%
