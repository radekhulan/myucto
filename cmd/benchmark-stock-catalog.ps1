[CmdletBinding()]
param(
    [ValidateRange(1, 30000)] [int] $Size = 100,
    [ValidateRange(3, 100)] [int] $Iterations = 15,
    [ValidateRange(1, 100)] [int] $MaxBatches = 100
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$phpBin = if ($env:MYINVOICE_PHP_BIN) { $env:MYINVOICE_PHP_BIN } else { 'php' }
& $phpBin (Join-Path $projectRoot 'api\bin\benchmark-stock-catalog.php') "--size=$Size" "--iterations=$Iterations" "--max-batches=$MaxBatches"
exit $LASTEXITCODE
