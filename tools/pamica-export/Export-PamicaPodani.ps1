<#
.SYNOPSIS
    Vytáhne z datového souboru PAMICA všechna uložená podání (JMHZ, registrace, NEMPRI,
    HZUPN, ELDP, ONZ) a log datové schránky do JSON korpusu.

.DESCRIPTION
    Čte jen z kopie nebo ze souboru v režimu Read (nic nezapisuje). Výstup je vstupem pro
    Build-PamicaPodani.php, který z něj složí XML v oficiálním formátu ČSSZ.

      <Vystup>\raw\<tabulka>.json     úplný výpis tabulky; binární atributové bloby
                                      (MH.DataAll, MHitems.Data, RegZAMitems.Data, ...)
                                      jsou rozepsané na položky "ID atributu -> hodnota"
                                      (items = bezeztrátově včetně oddílu, příznaku a pořadí
                                      v opakované skupině, attrs = mapa ID -> hodnota
                                      nebo pole hodnot u opakovaných atributů)
      <Vystup>\raw\dorucenky\<ID>.zip doručenky z DataBoxSent.Dorucenka (ZIP se ZFO)
      <Vystup>\raw\_meta.json         zdrojový soubor, SHA256, čas výpisu, počty řádků

    Blob se vždy dekóduje stejnou funkcí jako v Export-Pamica.ps1 (dot-source), takže
    formát (int32 verze/počet, záznam oddíl/ID atributu/délka/text cp1250/koncovka)
    se udržuje na jednom místě. Blob, který nejde přečíst, se uloží jako base64
    (pole blobRaw), nic se nezahazuje.

    Tabulky se zaměstnanci (ZAM, ZAMpomer) se berou celé kvůli vazbě podání na osoby
    (osobní číslo, rodné číslo, OIČ, IDPPV). Výstup proto obsahuje osobní údaje a patří
    jen do soukromého úložiště, nikdy do repozitáře.

.PARAMETER Mdb
    Cesta k datovému souboru PAMICA (Mzdy*.mdb). Doporučeno kopie.

.PARAMETER Vystup
    Cílová složka korpusu (vznikne podsložka raw).

.PARAMETER Xml
    Po výpisu rovnou spustí Build-PamicaPodani.php (složí XML, zvaliduje je, zapíše index.csv
    a xml\VALIDATION.md). Vyžaduje PHP s ext-dom; cestu k němu lze zadat parametrem -Php.

.PARAMETER Php
    Spustitelný soubor PHP pro parametr -Xml (výchozí `php` z PATH).

.EXAMPLE
    .\Export-PamicaPodani.ps1 -Mdb C:\temp\MzdyXX.mdb -Vystup C:\doc\PrivateData\Korpus -Xml

.EXAMPLE
    .\Export-PamicaPodani.ps1 -Mdb C:\temp\MzdyXX.mdb -Vystup C:\doc\PrivateData\Korpus
    php .\Build-PamicaPodani.php --corpus C:\doc\PrivateData\Korpus
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$Mdb,
    [Parameter(Mandatory)][string]$Vystup,
    [switch]$Xml,
    [string]$Php = 'php',
    [string]$Heslo
)

$ErrorActionPreference = 'Stop'

# Dot-source přepíše parametry Mdb, Vystup a Heslo svými výchozími hodnotami, proto je předem uložíme.
$mdbPath = $Mdb
$outPath = $Vystup
$buildXml = $Xml.IsPresent
$phpPath = $Php
$mdbPassword = $Heslo
. (Join-Path $PSScriptRoot 'Export-Pamica.ps1')
$Mdb = $mdbPath
$Vystup = $outPath
$Heslo = $mdbPassword
if (-not $Heslo -and $env:MYUCTO_MDB_HESLO) { $Heslo = $env:MYUCTO_MDB_HESLO }

# Tabulky podání a jejich vazby; sloupec typu byte[] se rozepíše jako atributový blob.
$PodaniTables = @(
    'MH', 'MHitems',
    'RegZAM', 'RegZAMitems', 'PredRegZAM', 'PredRegZAMitems',
    'NEMPRI', 'NEMPRIpol', 'NEMPRIdeti', 'NEMPRIpecovalDny', 'NEMPRIpraceVeDnech', 'NEMPRIpracVolno', 'NEMPRIrozvrhSmen',
    'HZUPN', 'HZUPNpol', 'HZUPNpracoval',
    'ELDP', 'ELDPpol',
    'ONZ', 'ONZpol', 'ONZduchPoj',
    'DataBoxSent',
    'ZAM', 'ZAMpomer'
)

function ConvertTo-PodaniValue($Value) {
    if ($Value -is [DBNull] -or $null -eq $Value) { return $null }
    switch ($Value.GetType().Name) {
        'DateTime' { if ($Value.TimeOfDay.Ticks -eq 0) { return $Value.ToString('yyyy-MM-dd') } else { return $Value.ToString('yyyy-MM-ddTHH:mm:ss') } }
        'Boolean' { return [bool]$Value }
        'Decimal' { return [decimal]$Value }
        'Double' { return [double]$Value }
        'Single' { return [double]::Parse($Value.ToString('R', [Globalization.CultureInfo]::InvariantCulture), [Globalization.CultureInfo]::InvariantCulture) }
        'String' { return Get-PamicaXmlText ([string]$Value) }
        default { return $Value }
    }
}

function ConvertTo-PodaniBlob($Decoded) {
    $items = New-Object System.Collections.Generic.List[object]
    $attrs = [ordered]@{}
    foreach ($it in $Decoded.Items) {
        $value = Get-PamicaXmlText $it.Value
        $items.Add([ordered]@{ id = $it.Id; section = $it.Section; flag = $it.Flag; order = $it.Order; order2 = $it.Order2; value = $value })
        $key = [string]$it.Id
        if ($attrs.Contains($key)) {
            if ($attrs[$key] -is [System.Collections.IList]) { $attrs[$key].Add($value) }
            else {
                $list = New-Object System.Collections.Generic.List[object]
                $list.Add($attrs[$key])
                $list.Add($value)
                $attrs[$key] = $list
            }
        } else {
            $attrs[$key] = $value
        }
    }
    return [ordered]@{ version = $Decoded.Version; items = $items; attrs = $attrs }
}

$Mdb = (Resolve-Path -LiteralPath $Mdb).Path
New-Item -ItemType Directory -Force (Join-Path $Vystup 'raw\dorucenky') | Out-Null
$raw = Join-Path (Resolve-Path -LiteralPath $Vystup).Path 'raw'

$conn = Open-PamicaSource $Mdb $Heslo
if ($null -eq $conn -and $PamicaOpenFailure.Failure -eq 'Password' -and -not $Heslo) {
    $Heslo = Read-MdbPassword
    if ($Heslo) { $conn = Open-PamicaSource $Mdb $Heslo }
}
if ($null -eq $conn -and $PamicaOpenFailure.Failure -eq 'Driver' -and [Environment]::Is64BitProcess) {
    # Ovladač Accessu bývá jen 32bitový (instaluje ho 32bitová PAMICA) - zkusíme 32bitový PowerShell.
    $ps32 = Join-Path $env:WINDIR 'SysWOW64\WindowsPowerShell\v1.0\powershell.exe'
    if (Test-Path -LiteralPath $ps32) {
        Write-Host 'Ovladač pro .mdb v 64bitovém PowerShellu chybí, spouštím 32bitový...'
        $q = { param($s) "'" + ($s -replace "'", "''") + "'" }
        $args32 = "-Mdb {0} -Vystup {1} -Php {2}" -f (& $q $Mdb), (& $q $Vystup), (& $q $phpPath)
        if ($buildXml) { $args32 += ' -Xml' }
        if ($Heslo) { $env:MYUCTO_MDB_HESLO = $Heslo }
        & $ps32 -NoProfile -ExecutionPolicy Bypass -Command ("& {0} {1}" -f (& $q $PSCommandPath), $args32)
        exit $LASTEXITCODE
    }
}
if ($null -eq $conn) { throw (Get-MdbFailureMessage $PamicaOpenFailure 'PAMICU' ([bool]$Heslo)) }

$meta = [ordered]@{
    zdroj = Split-Path -Leaf $Mdb
    sha256 = (Get-FileHash -Algorithm SHA256 -LiteralPath $Mdb).Hash
    velikost = (Get-Item -LiteralPath $Mdb).Length
    zmeneno = (Get-Item -LiteralPath $Mdb).LastWriteTime.ToString('yyyy-MM-ddTHH:mm:ss')
    vypsano = (Get-Date).ToString('yyyy-MM-ddTHH:mm:ss')
    program = ''
    tabulky = [ordered]@{}
    bloby = [ordered]@{}
    dorucenky = 0
}

try {
    $existing = Get-PamicaTables $conn
    $meta.program = Get-PamicaProgram $conn $existing
    foreach ($name in $PodaniTables) {
        if ($existing -notcontains $name) { continue }
        $table = Get-PamicaRows $conn "SELECT * FROM [$name]"
        $rows = New-Object System.Collections.Generic.List[object]
        foreach ($row in $table.Rows) {
            $o = [ordered]@{}
            foreach ($col in $table.Columns) {
                $value = $row[$col]
                if ($value -is [byte[]]) {
                    if ($col.ColumnName -eq 'Dorucenka') {
                        $o['dorucenkaSoubor'] = $null
                        if ($value.Length -gt 0) {
                            $rel = "dorucenky\$([string]$row['ID']).zip"
                            [IO.File]::WriteAllBytes((Join-Path $raw $rel), $value)
                            $o['dorucenkaSoubor'] = "dorucenky/$([string]$row['ID']).zip"
                            $meta.dorucenky++
                        }
                        continue
                    }
                    $key = "$name.$($col.ColumnName)"
                    if (-not $meta.bloby.Contains($key)) { $meta.bloby[$key] = [ordered]@{ prectene = 0; neprectene = 0; prazdne = 0 } }
                    if ($value.Length -eq 0) { $meta.bloby[$key].prazdne++; $o[$col.ColumnName] = $null; continue }
                    $decoded = ConvertFrom-PamicaAttributeBlob $value
                    if ($null -eq $decoded) {
                        $meta.bloby[$key].neprectene++
                        $o[$col.ColumnName] = [ordered]@{ blobRaw = [Convert]::ToBase64String($value) }
                    } else {
                        $meta.bloby[$key].prectene++
                        $o[$col.ColumnName] = ConvertTo-PodaniBlob $decoded
                    }
                    continue
                }
                $o[$col.ColumnName] = ConvertTo-PodaniValue $value
            }
            $rows.Add($o)
        }
        $meta.tabulky[$name] = $rows.Count
        $json = if ($rows.Count -eq 0) { '[]' } else { ConvertTo-Json -InputObject $rows.ToArray() -Depth 12 }
        [IO.File]::WriteAllText((Join-Path $raw "$name.json"), $json, (New-Object Text.UTF8Encoding($false)))
        Write-Host ("{0,-20} {1,6}" -f $name, $rows.Count)
    }
} finally {
    $conn.Close()
}

[IO.File]::WriteAllText((Join-Path $raw '_meta.json'), ($meta | ConvertTo-Json -Depth 6), (New-Object Text.UTF8Encoding($false)))
Write-Host "Hotovo: $raw"

if ($buildXml) {
    & $phpPath (Join-Path $PSScriptRoot 'Build-PamicaPodani.php') --corpus (Resolve-Path -LiteralPath $Vystup).Path
    if ($LASTEXITCODE -ne 0) { throw "Build-PamicaPodani.php skončil s kódem $LASTEXITCODE." }
}
