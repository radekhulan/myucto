@echo off
setlocal
set "DEPLOY_REMOTE=%~1"
set "DEPLOY_LABEL=%~2"
if not defined DEPLOY_REMOTE set "DEPLOY_REMOTE=production"
if not defined DEPLOY_LABEL set "DEPLOY_LABEL=%DEPLOY_REMOTE%"
"%SystemRoot%\System32\WindowsPowerShell\v1.0\powershell.exe" -NoProfile -File "%~dp0tools\Invoke-MyUctoDeployment.ps1" -Remote "%DEPLOY_REMOTE%" -Label "%DEPLOY_LABEL%"
exit /b %errorlevel%
