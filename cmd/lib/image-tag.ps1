# MyUcto.cz - cilova verze aktualizace u pripnute znacky image.
#
# Windows protejsek `cmd/lib/image-tag.sh` - MUSI se chovat stejne (AGENTS.md,
# sekce Multiplatformnost). Duvod v hlavicce `.sh` varianty; strucne: pripnutou
# verzi X.Y.Z u myucto image v compose prepise na vyssi cilovou verzi z tlacitka
# Aktualizovat. Plovouci znacky (`latest`, `6`, `6.70`) ani nizsi verzi nemeni.
#
# Pouziti (dot-source):
#   . "$PSScriptRoot\lib\image-tag.ps1"
#   $bump = Get-ImageTagBump -Compose 'docker-compose.production.yml' -Target '6.71.0'
#   if ($bump) { Set-ImageTag -Compose ... -Old $bump.Old -New $bump.New }
#
# Prime spusteni (test suite):
#   pwsh -NoProfile -File cmd/lib/image-tag.ps1 -Bump <compose> <verze>
#   pwsh -NoProfile -File cmd/lib/image-tag.ps1 -Write <compose> <stara> <nova>

[CmdletBinding()]
param([switch]$Bump, [switch]$Write, [string]$Compose, [string]$From, [string]$To)

function Get-ImageTagBump {
    param([string]$Compose, [string]$Target)
    $Target = "$Target" -replace '^v', ''
    if (-not (Test-Path -LiteralPath $Compose) -or $Target -cnotmatch '\A[0-9]{1,6}\.[0-9]{1,6}\.[0-9]{1,6}\z') { return $null }
    $current = $null
    foreach ($line in [System.IO.File]::ReadAllLines((Resolve-Path -LiteralPath $Compose).Path)) {
        if ($line -match '^\s*image:\s*["'']?([^\s"'']*myucto:[0-9]{1,6}\.[0-9]{1,6}\.[0-9]{1,6})["'']?(\s.*)?$') {
            $current = $Matches[1]
            break
        }
    }
    if (-not $current) { return $null }
    $version = $current.Substring($current.LastIndexOf(':') + 1)
    if ([version]$Target -le [version]$version) { return $null }
    return [pscustomobject]@{
        Old = $current
        New = $current.Substring(0, $current.LastIndexOf(':') + 1) + $Target
    }
}

function Set-ImageTag {
    param([string]$Compose, [string]$Old, [string]$New)
    $path = (Resolve-Path -LiteralPath $Compose).Path
    $text = [System.IO.File]::ReadAllText($path)
    $lines = $text -split "`n"
    for ($i = 0; $i -lt $lines.Count; $i++) {
        if ($lines[$i] -match '^\s*image:' -and $lines[$i].Contains($Old)) {
            $at = $lines[$i].IndexOf($Old)
            $lines[$i] = $lines[$i].Substring(0, $at) + $New + $lines[$i].Substring($at + $Old.Length)
            break
        }
    }
    # UTF-8 bez BOM, konce radku beze zmeny.
    [System.IO.File]::WriteAllText($path, ($lines -join "`n"), (New-Object System.Text.UTF8Encoding($false)))
}

if ($Bump) {
    $b = Get-ImageTagBump -Compose $Compose -Target $From
    if ($b) { Write-Output "$($b.Old) $($b.New)" }
} elseif ($Write) {
    Set-ImageTag -Compose $Compose -Old $From -New $To
}
