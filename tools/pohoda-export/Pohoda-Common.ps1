<#
    Společný zápis účetnictví POHODY do 89_ucetnictvi_mdb.xml a ZIPu pro průvodce „Přechod
    z POHODA". Používají ho Export-PohodaMdbAccounting.ps1 (datový soubor MDB)
    a Export-PohodaSQL.ps1 (databáze POHODA SQL); zdroj se liší jen spojením. Soubor se
    načítá tečkou, sám nic nespouští.

    Spojení může být OleDb (MDB), SqlClient nebo ODBC (SQL Server). Čte se jen přes SELECT.
#>

$PohodaDocumentColumns = @(
    'ID', 'Cislo', 'Datum', 'DatZdPln', 'DatUcP', 'DatSplat', 'DatKHDPH',
    'SText', 'VarSym', 'KonstSym', 'SpecSym', 'PDoklad', 'CisloKHDPH',
    'RelPk', 'RelTpDPH', 'RefAD', 'Firma', 'Jmeno', 'Utvar', 'Ulice', 'Obec',
    'PSC', 'ICO', 'DIC', 'RefZeme', 'Ucet', 'KodBanky', 'RelForUh',
    'KcLikv', 'DatLikv', 'Pozn', 'RefCM', 'CmKurs', 'CmMnoz', 'CmCelkem',
    'Kc0', 'KcZaokr', 'Kc1', 'KcDPH1', 'Kc2', 'KcDPH2', 'Kc3', 'KcDPH3',
    'KcCelkem', 'RelStorn', 'TpStorn', 'TpUD', 'RelDruhUD', 'HistSzDPH'
)

$PohodaItemColumns = @(
    'ID', 'RefAg', 'RelAgID', 'RefPol', 'SText', 'Mnozstvi', 'MJ', 'RelSzDPH',
    'ProcentoDPH', 'KcJedn', 'Kc', 'KcDPH', 'RelTpDPH', 'OrderFld'
)

# Režim OSS vede jen agenda faktur (stát spotřeby, doklady prokazující stát, typ plnění
# a částky položky v cizí měně). Ostatní tabulky dokladů tyto sloupce nemají; sloupec,
# který konkrétní verze POHODY nezná, Write-PohodaTable vynechá.
$PohodaAccountingTables = [ordered]@{
    FA       = $PohodaDocumentColumns + @('RelTpFak', 'MOSS', 'MOSSDukaz', 'DatZdPlnMOSS')
    FApol    = $PohodaItemColumns + @('MOSSDruh', 'CmJedn', 'Cm', 'CmDPH')
    pUD      = @('ID', 'RelUdAg', 'Cislo', 'Datum', 'DatZdPln', 'SText', 'Kc', 'UMD', 'UD', 'RelAgID', 'ParSym', 'ParSym2', 'RefStr', 'RefAD', 'Firma', 'Jmeno', 'ParICO')
    sSTR     = @('ID', 'IDS', 'SText')
    pOS      = @('Ucet', 'Nazev')
    pPK      = @('ID', 'IDS', 'SText', 'UMD', 'UD', 'RelPkAg')
    sDPH     = @('ID', 'IDS', 'SText', 'RefTpDph', 'RelVlivKHDPH')
    sDPHTp   = @('ID', 'Radky')
    AD       = @('ID', 'Firma', 'Jmeno', 'Utvar', 'Ulice', 'Obec', 'PSC', 'ICO', 'DIC', 'RefZeme', 'Email', 'GSM', 'Tel')
    sUcet    = @('ID', 'RelJeUcet', 'IDS', 'SText', 'KodBanky', 'Banka', 'IBAN', 'AUcet')
    sZeme    = @('ID', 'IDS')
    sCMeny   = @('ID', 'Kod')
    sFormUh  = @('ID', 'RelTyp')
    BV       = $PohodaDocumentColumns + @('RelTpBV', 'RefUcet', 'DatPlat', 'Vypis', 'ParSym')
    BVpol    = $PohodaItemColumns + @('RelIDUhrady', 'ParSym', 'RelPk')
    HO       = $PohodaDocumentColumns + @('RelTpHO', 'RefUcet', 'DatPlat')
    HOpol    = $PohodaItemColumns
    pINT     = $PohodaDocumentColumns
    pINTpol  = $PohodaItemColumns
    Uhrady   = @('ID', 'RelIDH', 'RelAgH', 'RelAgU', 'DatumU', 'RelIDU', 'CisloU', 'KcU', 'CisloH', 'VarSymH')
    sKonfig  = @('ICO', 'Rok', 'RelRokTp', 'DatRokOd', 'DatRokDo', 'RelUTyp')
    Verze    = @('ID', 'Verze')
}

$PohodaRequiredTables = @(
    'pUD', 'pOS', 'sDPH', 'sDPHTp', 'FA', 'FApol', 'AD', 'pPK', 'sUcet',
    'sZeme', 'sCMeny', 'sFormUh', 'BV', 'BVpol', 'HO', 'HOpol', 'pINT',
    'pINTpol', 'Uhrady', 'sKonfig'
)

<# Dotaz nad spojením; SQL Server dostane delší časový limit, velké tabulky se čtou minuty. #>
function New-PohodaCommand($Connection, [string]$Sql) {
    $command = $Connection.CreateCommand()
    $command.CommandText = $Sql
    if ($Connection -isnot [System.Data.OleDb.OleDbConnection]) {
        $command.CommandTimeout = 600
    }
    return $command
}

<#
    Tabulky zdroje (malými písmeny => skutečný název). MDB je hlásí přes GetSchema jako
    TABLE; SQL Server se ptá INFORMATION_SCHEMA, protože GetSchema vrací SqlClient
    (BASE TABLE, TABLE_SCHEMA) a ODBC (TABLE, TABLE_SCHEM) každý jinak.
#>
function Get-PohodaTableMap($Connection) {
    $tables = @{}
    if ($Connection -is [System.Data.OleDb.OleDbConnection]) {
        foreach ($row in $Connection.GetSchema('Tables').Rows) {
            if ([string]$row['TABLE_TYPE'] -ne 'TABLE') {
                continue
            }
            $name = [string]$row['TABLE_NAME']
            if ($name -ne '') {
                $tables[$name.ToLowerInvariant()] = $name
            }
        }
        return $tables
    }
    $command = New-PohodaCommand $Connection "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE' AND TABLE_SCHEMA = 'dbo'"
    $reader = $null
    try {
        $reader = $command.ExecuteReader()
        while ($reader.Read()) {
            $name = [string]$reader.GetValue(0)
            if ($name -ne '') {
                $tables[$name.ToLowerInvariant()] = $name
            }
        }
    } finally {
        if ($reader) {
            $reader.Close()
            $reader.Dispose()
        }
        $command.Dispose()
    }
    return $tables
}

<#
    Text hodnoty do XML. Desetinné číslo bez koncových nul: money ze SQL Serveru i Currency
    z MDB ve Windows PowerShellu 5.1 přichází se čtyřmi místy (100.0000), v PowerShellu 7
    u MDB zkrácené (100). Výstup je tak stejný ze všech zdrojů i verzí PowerShellu.
#>
function ConvertTo-PohodaXmlText($Value) {
    if ($Value -is [DateTime]) {
        return $Value.ToString('yyyy-MM-ddTHH:mm:ss', [Globalization.CultureInfo]::InvariantCulture)
    }
    if ($Value -is [bool]) {
        return $(if ($Value) { 'true' } else { 'false' })
    }
    if ($Value -is [decimal]) {
        return $Value.ToString('0.############################', [Globalization.CultureInfo]::InvariantCulture)
    }
    if ($Value -is [double]) {
        return $Value.ToString('R', [Globalization.CultureInfo]::InvariantCulture)
    }
    if ($Value -is [single]) {
        return $Value.ToString('R', [Globalization.CultureInfo]::InvariantCulture)
    }
    if ($Value -is [IFormattable]) {
        return $Value.ToString($null, [Globalization.CultureInfo]::InvariantCulture)
    }
    return [string]$Value
}

function Get-PohodaProgramVersion($Connection, [hashtable]$Tables) {
    if (-not $Tables.ContainsKey('verze')) {
        return ''
    }
    $table = ([string]$Tables['verze']).Replace(']', ']]')
    $presentColumns = Get-PohodaPresentColumns $Connection $table
    if (-not $presentColumns.ContainsKey('verze')) {
        return ''
    }
    $versionColumn = ([string]$presentColumns['verze']).Replace(']', ']]')
    $command = New-PohodaCommand $Connection "SELECT TOP 1 [$versionColumn] FROM [$table]"
    $reader = $null
    try {
        $reader = $command.ExecuteReader()
        if (-not $reader.Read()) {
            return ''
        }
        if (-not $reader.IsDBNull(0)) {
            return [string]$reader.GetValue(0)
        }
        return ''
    } finally {
        if ($reader) {
            $reader.Close()
            $reader.Dispose()
        }
        $command.Dispose()
    }
}

<# Sloupce tabulky (malými písmeny => skutečný název); vypočtené NullCheck_* z POHODA SQL se neberou. #>
function Get-PohodaPresentColumns($Connection, [string]$QuotedTableName) {
    $command = New-PohodaCommand $Connection "SELECT * FROM [$QuotedTableName] WHERE 1 = 0"
    $reader = $null
    try {
        $reader = $command.ExecuteReader([System.Data.CommandBehavior]::SchemaOnly)
        $columns = @{}
        for ($i = 0; $i -lt $reader.FieldCount; $i++) {
            $name = $reader.GetName($i)
            if ($name -like 'NullCheck_*') {
                continue
            }
            $columns[$name.ToLowerInvariant()] = $name
        }
        return $columns
    } finally {
        if ($reader) {
            $reader.Close()
            $reader.Dispose()
        }
        $command.Dispose()
    }
}

function Get-PohodaAgendaMetadata($Connection, [hashtable]$Tables) {
    if (-not $Tables.ContainsKey('skonfig')) {
        throw 'Zdroj neobsahuje tabulku sKonfig s identifikací účetní agendy.'
    }
    $table = ([string]$Tables['skonfig']).Replace(']', ']]')
    $presentColumns = Get-PohodaPresentColumns $Connection $table
    foreach ($required in 'ICO', 'Rok', 'RelRokTp', 'DatRokOd', 'DatRokDo') {
        if (-not $presentColumns.ContainsKey($required.ToLowerInvariant())) {
            throw "Tabulka sKonfig neobsahuje sloupec $required potřebný k ověření účetní agendy."
        }
    }

    $selected = @('ICO', 'Rok', 'RelRokTp', 'DatRokOd', 'DatRokDo') | ForEach-Object {
        '[' + ([string]$presentColumns[$_.ToLowerInvariant()]).Replace(']', ']]') + ']'
    }
    $command = New-PohodaCommand $Connection ('SELECT TOP 1 ' + ($selected -join ', ') + " FROM [$table]")
    $reader = $null
    try {
        $reader = $command.ExecuteReader()
        if (-not $reader.Read() -or $reader.IsDBNull(0) -or $reader.IsDBNull(1)) {
            throw 'Tabulka sKonfig neobsahuje IČO a rok účetní agendy.'
        }
        $actualIco = (ConvertTo-PohodaXmlText $reader.GetValue(0)).Trim()
        $actualYear = 0
        if ($actualIco -notmatch '^\d{6,8}$' -or
            -not [int]::TryParse((ConvertTo-PohodaXmlText $reader.GetValue(1)), [ref]$actualYear)) {
            throw 'V tabulce sKonfig není platné IČO nebo rok účetní agendy.'
        }
        $actualIco = $actualIco.PadLeft(8, '0')
        return [pscustomobject]@{
            Ico = $actualIco
            Year = $actualYear
        }
    } finally {
        if ($reader) {
            $reader.Close()
            $reader.Dispose()
        }
        $command.Dispose()
    }
}

function Write-PohodaTable($Writer, $Connection, [string]$RequestedName, [string[]]$AllowedColumns, [hashtable]$Tables) {
    $lookup = $RequestedName.ToLowerInvariant()
    if (-not $Tables.ContainsKey($lookup)) {
        $Writer.WriteStartElement('table')
        $Writer.WriteAttributeString('name', $RequestedName)
        $Writer.WriteAttributeString('missing', 'true')
        $Writer.WriteEndElement()
        return 0
    }

    $actualName = [string]$Tables[$lookup]
    $quotedName = $actualName.Replace(']', ']]')
    $presentColumns = Get-PohodaPresentColumns $Connection $quotedName
    $columns = @()
    foreach ($allowed in $AllowedColumns) {
        $key = $allowed.ToLowerInvariant()
        if ($presentColumns.ContainsKey($key)) {
            $actualColumn = [string]$presentColumns[$key]
            $columns += [pscustomobject]@{
                Source = $actualColumn
                Name = [Xml.XmlConvert]::EncodeLocalName($actualColumn)
            }
        }
    }
    if ($columns.Count -eq 0) {
        throw "Tabulka $RequestedName neobsahuje žádný sloupec potřebný pro převod."
    }

    $select = ($columns | ForEach-Object { '[' + $_.Source.Replace(']', ']]') + ']' }) -join ', '
    $command = New-PohodaCommand $Connection "SELECT $select FROM [$quotedName]"
    $reader = $null
    $rows = 0
    try {
        $reader = $command.ExecuteReader([System.Data.CommandBehavior]::SequentialAccess)
        $Writer.WriteStartElement('table')
        $Writer.WriteAttributeString('name', $RequestedName)
        while ($reader.Read()) {
            $Writer.WriteStartElement('row')
            for ($index = 0; $index -lt $columns.Count; $index++) {
                $column = $columns[$index]
                if ($reader.IsDBNull($index)) {
                    $Writer.WriteStartElement([string]$column.Name)
                    $Writer.WriteAttributeString('nil', 'true')
                    $Writer.WriteEndElement()
                    continue
                }
                $value = $reader.GetValue($index)
                if ($value -is [byte[]]) {
                    continue
                }
                $Writer.WriteElementString([string]$column.Name, (ConvertTo-PohodaXmlText $value))
            }
            $Writer.WriteEndElement()
            $rows++
        }
        $Writer.WriteEndElement()
        return $rows
    } finally {
        if ($reader) {
            $reader.Close()
            $reader.Dispose()
        }
        $command.Dispose()
    }
}

function New-PohodaZip([array]$Files, [string]$Destination) {
    Add-Type -AssemblyName System.IO.Compression
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $stream = [IO.File]::Open($Destination, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::None)
    $archive = $null
    try {
        $archive = New-Object -TypeName IO.Compression.ZipArchive -ArgumentList @(
            $stream,
            [IO.Compression.ZipArchiveMode]::Create,
            $false
        )
        foreach ($file in $Files) {
            [IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                $archive,
                [string]$file.Source,
                [string]$file.Entry,
                [IO.Compression.CompressionLevel]::Optimal
            ) | Out-Null
        }
    } finally {
        if ($archive) {
            $archive.Dispose()
        }
        $stream.Dispose()
    }
}

<# IČO z parametru: prázdné zůstane prázdné, jinak 6 až 8 číslic doplněných na osm. #>
function ConvertTo-PohodaAgendaIco([string]$Ico) {
    if ([string]::IsNullOrWhiteSpace($Ico)) {
        return ''
    }
    if ($Ico -notmatch '^\d{6,8}$') {
        throw 'IČO musí obsahovat 6 až 8 číslic.'
    }
    return $Ico.PadLeft(8, '0')
}

function Assert-PohodaAgendaYear([int]$Rok) {
    $maxYear = (Get-Date).Year + 1
    if ($Rok -lt 1990 -or $Rok -gt $maxYear) {
        throw "Rok musí být celé číslo od 1990 do $maxYear."
    }
}

<#
    Export účetní agendy z otevřeného spojení: ověří povinné tabulky, IČO a rok ze sKonfig
    (zadané -Ico/-Rok musí souhlasit), zapíše <Vystup>\<IČO>_<rok>\89_ucetnictvi_mdb.xml,
    zavolá $Doplnky (majetek a mzdy, parametry složka agendy, IČO, rok) a zabalí ZIP pro
    průvodce. Spojení nezavírá - o to se stará volající.

    $Zdroj a $VeZdroji pojmenují zdroj v hláškách („Datový soubor", „v datovém souboru").
#>
function Invoke-PohodaAccountingExport {
    param(
        [Parameter(Mandatory = $true)]$Connection,
        [Parameter(Mandatory = $true)][string]$Vystup,
        [string]$Ico = '',
        [int]$Rok = 0,
        [bool]$Potvrdit = $false,
        [bool]$BezZip = $false,
        [Parameter(Mandatory = $true)][scriptblock]$Doplnky,
        [string]$Zdroj = 'Datový soubor',
        [string]$VeZdroji = 'v datovém souboru'
    )

    $maxYear = (Get-Date).Year + 1
    $tables = Get-PohodaTableMap $Connection
    $missingRequired = @($PohodaRequiredTables | Where-Object { -not $tables.ContainsKey($_.ToLowerInvariant()) })
    if ($missingRequired.Count -gt 0) {
        throw "$Zdroj neobsahuje povinné účetní tabulky: " + ($missingRequired -join ', ')
    }
    $metadata = Get-PohodaAgendaMetadata $Connection $tables
    if ($metadata.Year -lt 1990 -or $metadata.Year -gt $maxYear) {
        throw "$Zdroj uvádí nepodporovaný účetní rok $($metadata.Year)."
    }
    if ($Ico -ne '' -and $Ico -ne $metadata.Ico) {
        throw "Zadané IČO $Ico neodpovídá IČO $($metadata.Ico) uloženému $VeZdroji."
    }
    if ($Rok -ne 0 -and $Rok -ne $metadata.Year) {
        throw "Zadaný rok $Rok neodpovídá roku $($metadata.Year) uloženému $VeZdroji."
    }
    $Ico = $metadata.Ico
    $Rok = $metadata.Year
    if ($Potvrdit) {
        Write-Host "$Zdroj obsahuje IČO $Ico a účetní rok $Rok."
        $answer = ([string](Read-Host 'Pokračovat s těmito údaji? [A/n]')).Trim()
        if ($answer -notin @('', 'a', 'A', 'ano', 'Ano', 'ANO')) {
            throw 'Export byl zrušen uživatelem.'
        }
    }
    $programVersion = Get-PohodaProgramVersion $Connection $tables

    $outputRoot = [IO.Path]::GetFullPath($Vystup)
    if (-not (Test-Path -LiteralPath $outputRoot)) {
        New-Item -ItemType Directory -Path $outputRoot | Out-Null
    }
    $agendaDir = Join-Path $outputRoot ("{0}_{1}" -f $Ico, $Rok)
    $target = Join-Path $agendaDir '89_ucetnictvi_mdb.xml'
    $assetTarget = Join-Path $agendaDir '90_majetek.xml'
    $payrollTarget = Join-Path $agendaDir '91_mzdy.xml'
    $stockTarget = Join-Path $agendaDir '92_sklad.xml'
    foreach ($existingTarget in @($target, $assetTarget, $payrollTarget, $stockTarget)) {
        if (Test-Path -LiteralPath $existingTarget) {
            throw "Výstup $existingTarget už existuje. Smažte ho nebo zvolte jinou výstupní složku."
        }
    }
    $zipTarget = $agendaDir + '.zip'
    if (-not $BezZip -and (Test-Path -LiteralPath $zipTarget)) {
        throw "ZIP $zipTarget už existuje. Smažte ho nebo zvolte jinou výstupní složku."
    }
    if (-not (Test-Path -LiteralPath $agendaDir)) {
        New-Item -ItemType Directory -Path $agendaDir | Out-Null
    }

    $temp = Join-Path $agendaDir ('89_ucetnictvi_mdb.xml.' + [Guid]::NewGuid().ToString('N') + '.tmp')
    $writer = $null
    try {
        $settings = New-Object System.Xml.XmlWriterSettings
        $settings.Encoding = New-Object System.Text.UTF8Encoding($false)
        $settings.Indent = $true
        $settings.CheckCharacters = $true
        $writer = [Xml.XmlWriter]::Create($temp, $settings)
        $writer.WriteStartDocument()
        $writer.WriteStartElement('pohodaMdbAccounting')
        $writer.WriteAttributeString('formatVersion', '1')
        $writer.WriteAttributeString('ico', $Ico)
        $writer.WriteAttributeString('year', [string]$Rok)
        $writer.WriteAttributeString('programVersion', $programVersion)

        $totalRows = 0
        foreach ($table in $PohodaAccountingTables.Keys) {
            $count = Write-PohodaTable $writer $Connection $table $PohodaAccountingTables[$table] $tables
            $totalRows += $count
            Write-Host ("  {0,-12} {1,8} řádků" -f $table, $count)
        }

        $writer.WriteEndElement()
        $writer.WriteEndDocument()
        $writer.Flush()
        $writer.Close()
        $writer = $null

        Move-Item -LiteralPath $temp -Destination $target
        Write-Host "Hotovo: $target ($totalRows řádků)" -ForegroundColor Green

        Write-Host 'Exportuji majetek, mzdy a sklad...'
        try {
            $null = & $Doplnky $agendaDir $Ico $Rok
        } catch {
            foreach ($createdTarget in @($target, $assetTarget, $payrollTarget, $stockTarget)) {
                if (Test-Path -LiteralPath $createdTarget) {
                    Remove-Item -LiteralPath $createdTarget -Force
                }
            }
            throw
        }

        $zip = $null
        if (-not $BezZip) {
            $zipTemp = $agendaDir + '.' + [Guid]::NewGuid().ToString('N') + '.tmp.zip'
            try {
                $agendaName = Split-Path $agendaDir -Leaf
                $zipFiles = @(
                    [pscustomobject]@{ Source = $target; Entry = $agendaName + '/89_ucetnictvi_mdb.xml' }
                )
                foreach ($optionalTarget in @($assetTarget, $payrollTarget, $stockTarget)) {
                    if (Test-Path -LiteralPath $optionalTarget -PathType Leaf) {
                        $zipFiles += [pscustomobject]@{
                            Source = $optionalTarget
                            Entry = $agendaName + '/' + (Split-Path $optionalTarget -Leaf)
                        }
                    }
                }
                New-PohodaZip -Files $zipFiles -Destination $zipTemp
                Move-Item -LiteralPath $zipTemp -Destination $zipTarget
                $zip = $zipTarget
                Write-Host "ZIP: $zipTarget" -ForegroundColor Green
            } finally {
                if (Test-Path -LiteralPath $zipTemp) {
                    Remove-Item -LiteralPath $zipTemp -Force
                }
            }
        }
        return [pscustomobject]@{ Ico = $Ico; Rok = $Rok; AgendaDir = $agendaDir; Target = $target; Zip = $zip }
    } finally {
        if ($writer) {
            $writer.Close()
        }
        if (Test-Path -LiteralPath $temp) {
            Remove-Item -LiteralPath $temp -Force
        }
    }
}
