[CmdletBinding()]
param(
    [switch] $DryRun
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
$logFile = Join-Path $logRoot ('jmhz-source-monitor-{0}.log' -f (Get-Date -Format 'yyyy-MM-dd'))
$arguments = @()
if ($DryRun) {
    $arguments += '--dry-run'
}

& $phpBin (Join-Path $projectRoot 'api\bin\cron-jmhz-source-monitor.php') @arguments *>> $logFile
exit $LASTEXITCODE
