[CmdletBinding()]
param(
    [ValidateRange(1, 500)]
    [int] $Limit = 25
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$phpBin = if ($env:MYINVOICE_PHP_BIN) { $env:MYINVOICE_PHP_BIN } else { 'php' }
$logRoot = if ($env:MYINVOICE_DATA_DIR) {
    Join-Path $env:MYINVOICE_DATA_DIR 'log\cron'
} else {
    Join-Path $projectRoot 'log\cron'
}
$null = New-Item -ItemType Directory -Path $logRoot -Force
$logFile = Join-Path $logRoot (
    'payroll-document-worker-{0}.log' -f (Get-Date -Format 'yyyy-MM-dd')
)

& $phpBin (Join-Path $projectRoot 'api\bin\cron-payroll-document-worker.php') `
    "--limit=$Limit" *>> $logFile
exit $LASTEXITCODE
