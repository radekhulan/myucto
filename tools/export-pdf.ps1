<#
.SYNOPSIS
    MyUcto.cz manuál: Markdown -> PDF (md2pdf engine) — lokální runner.

.DESCRIPTION
    Tenký spouštěč nad sdíleným MD2PDF enginem. Sloučí kapitoly manuálu
    (manual/NNN_*.md dle manual/INDEX.md) do jednoho manual/manual.pdf pomocí
    tools/md2pdf.config.php. Engine se hledá v $env:MD2PDF_HOME, jinak v
    C:\work\MD2PDF. Zdrojové .md jsou READ-ONLY.

.PARAMETER Config
    Cesta k md2pdf.config.php. Default: tools/md2pdf.config.php vedle tohoto skriptu.

.PARAMETER Preview
    Po exportu vyrenderuje PNG náhledy stránek (GhostScript).

.EXAMPLE
    pwsh -File tools\export-pdf.ps1
    pwsh -File tools\export-pdf.ps1 -Preview
#>
[CmdletBinding()]
param(
    [string]$Config,
    [switch]$Preview
)

$ErrorActionPreference = 'Stop'

$ToolsDir = $PSScriptRoot

# --- Engine (sdílený MD2PDF) ----------------------------------------------
$Engine = if ($env:MD2PDF_HOME) { $env:MD2PDF_HOME } else { 'C:\work\MD2PDF' }
$Script = Join-Path $Engine 'md2pdf.php'
if (-not (Test-Path $Script)) {
    throw "MD2PDF engine nenalezen: $Script (nastav `$env:MD2PDF_HOME na adresář enginu)."
}

# --- Config ----------------------------------------------------------------
if (-not $Config) { $Config = Join-Path $ToolsDir 'md2pdf.config.php' }
if (-not (Test-Path $Config)) { throw "Config nenalezen: $Config" }
$Config = (Resolve-Path $Config).Path

# --- php.exe ---------------------------------------------------------------
$Php = $null
foreach ($cand in @('c:\inetpub\php\php.exe', 'C:\Program Files\PHP\php.exe', 'c:\php\php.exe')) {
    if (Test-Path $cand) { $Php = $cand; break }
}
if (-not $Php) { $cmd = Get-Command php -ErrorAction SilentlyContinue; if ($cmd) { $Php = $cmd.Source } }
if (-not $Php) { throw "php.exe nenalezen (zkus c:\inetpub\php\php.exe)." }

# --- Zajisti vendor (mPDF) v enginu ---------------------------------------
if (-not (Test-Path (Join-Path $Engine 'vendor\autoload.php'))) {
    Write-Host "vendor\ enginu chybí - spouštím composer install..." -ForegroundColor Yellow
    $composer = Get-Command composer -ErrorAction SilentlyContinue
    if (-not $composer) { throw "vendor\ chybí a composer není v PATH. Spusť: composer install (v $Engine)." }
    Push-Location $Engine
    try { & $composer.Source install --no-interaction --no-progress --no-dev }
    finally { Pop-Location }
}

# --- Export ----------------------------------------------------------------
Write-Host "PHP:    $Php"
Write-Host "Engine: $Script"
Write-Host "Config: $Config"
Write-Host ""

& $Php $Script "--config=$Config"
$exit = $LASTEXITCODE
if ($exit -ne 0) { throw "md2pdf.php skončil s chybou (exit $exit)." }

# --- Volitelné: PNG náhledy (GhostScript) ----------------------------------
if ($Preview) {
    Write-Host ""
    Write-Host "Renderuji PNG náhledy..." -ForegroundColor Cyan

    $json = & $Php $Script "--config=$Config" '--print-config'
    if ($LASTEXITCODE -ne 0) { throw "Nelze načíst konfiguraci přes --print-config." }
    $cfg     = $json | ConvertFrom-Json
    $OutDir  = $cfg.output_dir
    $PrevDir = Join-Path $OutDir '_preview'

    $gs = $null
    foreach ($cand in @('C:\inetpub\GhostScript\bin\gswin64c.exe',
                        'C:\Program Files\gs\gs10.07.1\bin\gswin64c.exe')) {
        if (Test-Path $cand) { $gs = $cand; break }
    }
    if (-not $gs) { $cmd = Get-Command gswin64c -ErrorAction SilentlyContinue; if ($cmd) { $gs = $cmd.Source } }
    if (-not $gs) {
        Write-Warning "GhostScript (gswin64c.exe) nenalezen - náhledy přeskočeny."
    } else {
        New-Item -ItemType Directory -Force -Path $PrevDir | Out-Null
        Get-ChildItem $PrevDir -Filter '*.png' -ErrorAction SilentlyContinue | Remove-Item -Force
        foreach ($pdf in (Get-ChildItem $OutDir -Filter '*.pdf')) {
            $outPat = Join-Path $PrevDir ("{0}-p%02d.png" -f $pdf.BaseName)
            & $gs -q -dNOPAUSE -dBATCH -sDEVICE=png16m -r110 `
                  -dTextAlphaBits=4 -dGraphicsAlphaBits=4 `
                  -o $outPat $pdf.FullName | Out-Null
            Write-Host ("  náhled: {0}" -f $pdf.BaseName)
        }
        $n = (Get-ChildItem $PrevDir -Filter '*.png').Count
        Write-Host ("Hotovo: {0} PNG v {1}" -f $n, $PrevDir) -ForegroundColor Green
    }
}

Write-Host ""
Write-Host "HOTOVO." -ForegroundColor Green
