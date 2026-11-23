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

function Find-AppImageLine {
    param([string[]]$Lines)
    $inServices = $false
    $inApp = $false
    $serviceIndent = 0
    $propertyIndent = 0
    for ($i = 0; $i -lt $Lines.Count; $i++) {
        $line = $Lines[$i].TrimEnd("`r")
        if ($line -cmatch '^ *(#|$)') { continue }
        $key = $line.TrimStart(' ')
        $indent = $line.Length - $key.Length
        if ($indent -eq 0) {
            $inServices = $key -cmatch '^(services|"services"|''services''): *(#.*)?$'
            $serviceIndent = 0
            $inApp = $false
            continue
        }
        if (-not $inServices) { continue }
        if (-not $serviceIndent) { $serviceIndent = $indent }
        if ($indent -eq $serviceIndent) {
            $inApp = $key -cmatch '^(app|"app"|''app''): *(#.*)?$'
            $propertyIndent = 0
            continue
        }
        if (-not $inApp) { continue }
        if (-not $propertyIndent) { $propertyIndent = $indent }
        if ($indent -eq $propertyIndent -and $key -cmatch '^image: +') {
            if ($key -cmatch '^image: +(?:"([^"\s]+)"|''([^''\s]+)''|([^"''\s]+)) *(?:#.*)?$') {
                $image = $Matches[1] + $Matches[2] + $Matches[3]
                return [pscustomobject]@{ Index = $i; Image = $image }
            }
            return $null
        }
    }
    return $null
}

function Get-ImageTagBump {
    param([string]$Compose, [string]$Target, [string[]]$Lines)
    $Target = "$Target" -creplace '^v', ''
    if ($Target -cnotmatch '\A[0-9]{1,6}\.[0-9]{1,6}\.[0-9]{1,6}\z') { return $null }
    if ($Compose) {
        if (-not (Test-Path -LiteralPath $Compose)) { return $null }
        $Lines = [System.IO.File]::ReadAllLines((Resolve-Path -LiteralPath $Compose).Path)
    }
    $current = (Find-AppImageLine -Lines $Lines).Image
    if ($current -cnotmatch '(^|/)myucto:[0-9]{1,6}\.[0-9]{1,6}\.[0-9]{1,6}$') { return $null }
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
    $app = Find-AppImageLine -Lines $lines
    if (-not $app -or $app.Image -cne $Old) {
        throw "V $Compose neodpovida image sluzby app ocekavane hodnote $Old."
    }
    $line = $lines[$app.Index]
    $at = $line.IndexOf($Old, [System.StringComparison]::Ordinal)
    $lines[$app.Index] = $line.Substring(0, $at) + $New + $line.Substring($at + $Old.Length)
    [System.IO.File]::WriteAllText($path, ($lines -join "`n"), (New-Object System.Text.UTF8Encoding($false)))
}

if ($Bump) {
    $b = Get-ImageTagBump -Compose $Compose -Target $From
    if ($b) { Write-Output "$($b.Old) $($b.New)" }
} elseif ($Write) {
    Set-ImageTag -Compose $Compose -Old $From -New $To
}
