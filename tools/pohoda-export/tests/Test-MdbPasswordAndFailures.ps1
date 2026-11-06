[CmdletBinding()]
param()

# Datový soubor chráněný heslem databáze (issue #244): nástroj dřív hlásil chybějící
# ovladač. Ověřuje rozpoznání příčiny a export mezd z PAMICA i POHODY s parametrem -Heslo.

$ErrorActionPreference = 'Stop'
$testRoot = Join-Path $env:TEMP ('mdb-password-' + [Guid]::NewGuid().ToString('N'))
[string]$mdbPath = Join-Path $testRoot 'Mzdy_12345678.mdb'
# ADOX neumí heslo v uvozovkách, proto bez středníku.
$password = 'TajneHeslo1'
$connection = $null

New-Item -ItemType Directory -Path $testRoot | Out-Null
try {
    $builder = New-Object System.Data.OleDb.OleDbConnectionStringBuilder
    $builder.Provider = 'Microsoft.ACE.OLEDB.16.0'
    $builder['Data Source'] = $mdbPath
    $builder['Jet OLEDB:Engine Type'] = 5
    $builder['Jet OLEDB:Database Password'] = $password
    $catalog = New-Object -ComObject ADOX.Catalog
    $catalog.Create([string]$builder.ConnectionString) | Out-Null
    $catalogConnection = $catalog.ActiveConnection
    $catalogConnection.Close()
    [Runtime.InteropServices.Marshal]::FinalReleaseComObject($catalogConnection) | Out-Null
    [Runtime.InteropServices.Marshal]::FinalReleaseComObject($catalog) | Out-Null
    $catalogConnection = $null
    $catalog = $null

    $builder.Remove('Jet OLEDB:Engine Type') | Out-Null
    $connection = New-Object System.Data.OleDb.OleDbConnection ([string]$builder.ConnectionString)
    $connection.Open()
    foreach ($sql in @(
        'CREATE TABLE [ZAM] ([ID] INTEGER)',
        'CREATE TABLE [MZ] ([ID] INTEGER, [Rok] INTEGER)',
        'INSERT INTO [MZ] ([ID], [Rok]) VALUES (1, 2026)'
    )) {
        $command = $connection.CreateCommand()
        try {
            $command.CommandText = $sql
            $command.ExecuteNonQuery() | Out-Null
        } finally {
            $command.Dispose()
        }
    }
    $connection.Close()
    $connection.Dispose()
    $connection = $null

    $exporter = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\Export-PohodaMdb.ps1'))
    . $exporter

    $missing = Open-MdbConnection $mdbPath ''
    if ($null -ne $missing.Connection -or $missing.Failure -ne 'Password') {
        throw "Bez hesla čekám Password, je $($missing.Failure)."
    }
    $wrong = Open-MdbConnection $mdbPath 'spatne'
    if ($wrong.Failure -ne 'Password') { throw "Se špatným heslem čekám Password, je $($wrong.Failure)." }
    if ((Get-MdbFailureMessage $missing 'POHODU' $false) -notmatch 'parametrem -Heslo') { throw 'Hláška bez hesla neradí -Heslo.' }
    if ((Get-MdbFailureMessage $wrong 'POHODU' $true) -notmatch 'nesouhlasí') { throw 'Hláška se špatným heslem neříká, že nesouhlasí.' }

    $ok = Open-MdbConnection $mdbPath $password
    if ($null -eq $ok.Connection) { throw "Se správným heslem se soubor neotevřel: $($ok.Detail)" }
    $ok.Connection.Close()
    $ok.Connection.Dispose()

    $notMdb = Join-Path $testRoot 'neni.mdb'
    Set-Content -LiteralPath $notMdb -Value 'tohle neni databaze' -Encoding ASCII
    $format = Open-MdbConnection $notMdb ''
    if ($format.Failure -ne 'Format') { throw "Soubor, který není databáze, hlásí $($format.Failure)." }

    if ((Get-MdbFailureKind "The 'Microsoft.ACE.OLEDB.16.0' provider is not registered on the local machine.") -ne 'Driver') {
        throw 'Neregistrovaný ovladač se nerozpoznal.'
    }

    $pohodaOut = Join-Path $testRoot '12345678_2026'
    & $exporter -Mdb $mdbPath -Vystup $pohodaOut -Skupiny mzdy -Heslo $password | Out-Null
    if (-not (Test-Path -LiteralPath (Join-Path $pohodaOut '91_mzdy.xml'))) { throw 'Export-PohodaMdb s heslem nevytvořil 91_mzdy.xml.' }

    # PAMICA: jen fáze otevření (syntetický soubor nemá celé schéma mezd). Skript končí
    # přes exit, proto běží ve vlastním procesu.
    $pamica = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..\pamica-export\Export-Pamica.ps1'))
    $shell = (Get-Process -Id $PID).Path
    $pamicaOut = Join-Path $testRoot 'pamica'
    $wrongRun = (& $shell -NoProfile -ExecutionPolicy Bypass -File $pamica -Mdb $mdbPath -Rok 2026 -Ico 12345678 -Vystup $pamicaOut -Heslo 'spatne' 2>&1) -join "`n"
    if ($wrongRun -notmatch 'heslo databáze nesouhlasí' -or $wrongRun -match 'chybí ovladač|spouštím 32bitový') {
        throw "Export-Pamica se špatným heslem nehlásí heslo: $wrongRun"
    }
    $goodRun = (& $shell -NoProfile -ExecutionPolicy Bypass -File $pamica -Mdb $mdbPath -Rok 2026 -Ico 12345678 -Vystup $pamicaOut -Heslo $password 2>&1) -join "`n"
    if ($goodRun -match 'heslem|nesouhlasí|chybí ovladač|spouštím 32bitový') {
        throw "Export-Pamica se správným heslem soubor neotevřel: $goodRun"
    }

    Write-Output 'MDB_PASSWORD_AND_FAILURES_OK'
} finally {
    if ($connection) {
        $connection.Close()
        $connection.Dispose()
    }
    [GC]::Collect()
    [GC]::WaitForPendingFinalizers()
    if (Test-Path -LiteralPath $testRoot) {
        $resolvedTemp = [IO.Path]::GetFullPath($env:TEMP).TrimEnd('\') + '\'
        $resolvedTestRoot = [IO.Path]::GetFullPath($testRoot)
        if (-not $resolvedTestRoot.StartsWith($resolvedTemp, [StringComparison]::OrdinalIgnoreCase)) {
            throw "Refusing to remove unexpected test path $resolvedTestRoot."
        }
        Remove-Item -LiteralPath $testRoot -Recurse -Force -ErrorAction SilentlyContinue
    }
}
