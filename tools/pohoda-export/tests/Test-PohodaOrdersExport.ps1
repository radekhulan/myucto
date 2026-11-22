[CmdletBinding()]
param()

# Objednávky v 89_ucetnictvi_mdb.xml: tabulky OBJ a OBJpol jen s povolenými sloupci
# (bez interních a binárních), sloupec, který starší POHODA nemá, se vynechá, a datový
# soubor bez objednávek zapíše tabulku s atributem missing="true".

$ErrorActionPreference = 'Stop'
$toolDir = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
. (Join-Path $toolDir 'Pohoda-Common.ps1')

$testRoot = Join-Path $env:TEMP ('pohoda-orders-export-' + [Guid]::NewGuid().ToString('N'))
$mdbPath = Join-Path $testRoot 'synthetic.mdb'
$connection = $null

function Assert-True([bool]$Condition, [string]$Message) {
    if (-not $Condition) { throw $Message }
}

New-Item -ItemType Directory -Path $testRoot | Out-Null
try {
    $catalog = New-Object -ComObject ADOX.Catalog
    $catalog.Create("Provider=Microsoft.ACE.OLEDB.16.0;Data Source=$mdbPath;Jet OLEDB:Engine Type=5") | Out-Null
    $catalogConnection = $catalog.ActiveConnection
    $catalogConnection.Close()
    [Runtime.InteropServices.Marshal]::FinalReleaseComObject($catalogConnection) | Out-Null
    [Runtime.InteropServices.Marshal]::FinalReleaseComObject($catalog) | Out-Null
    $catalogConnection = $null
    $catalog = $null

    $connection = New-Object System.Data.OleDb.OleDbConnection "Provider=Microsoft.ACE.OLEDB.16.0;Data Source=$mdbPath"
    $connection.Open()
    # OBJ bez sloupce DatStorn (starší verze), s interními sloupci Labels a Oznacil, které se nevytahují.
    foreach ($sql in @(
        'CREATE TABLE [OBJ] ([ID] INTEGER, [Cislo] TEXT(30), [RelTpObj] INTEGER, [Datum] DATETIME, [DatDod] DATETIME, [Vyrizeno] BIT, [RefAD] INTEGER, [Firma] TEXT(100), [RefCM] INTEGER, [CmKurs] DOUBLE, [CmMnoz] INTEGER, [KcCelkem] CURRENCY, [Labels] TEXT(50), [Oznacil] TEXT(50))',
        'CREATE TABLE [OBJpol] ([ID] INTEGER, [RefAg] INTEGER, [RefSKz] INTEGER, [SText] TEXT(100), [Mnozstvi] DOUBLE, [Dodano] DOUBLE, [MJ] TEXT(10), [KcJedn] CURRENCY, [RelSzDPH] INTEGER, [Kc] CURRENCY, [KcDPH] CURRENCY, [OrderFld] INTEGER, [VCislo] TEXT(30))',
        "INSERT INTO [OBJ] ([ID], [Cislo], [RelTpObj], [Datum], [DatDod], [Vyrizeno], [RefAD], [Firma], [RefCM], [CmKurs], [CmMnoz], [KcCelkem], [Labels], [Oznacil]) VALUES (1, 'OV0001', 2, #2026-03-02#, #2026-03-20#, 0, 5, 'Vzorový dodavatel s.r.o.', 0, 0, 0, 1210.5, 'interni', 'uzivatel')",
        "INSERT INTO [OBJpol] ([ID], [RefAg], [RefSKz], [SText], [Mnozstvi], [Dodano], [MJ], [KcJedn], [RelSzDPH], [Kc], [KcDPH], [OrderFld], [VCislo]) VALUES (10, 1, 7, 'Ložisko', 10, 4, 'ks', 100.05, 2, 1000.5, 210.11, 1, 'VC-1')"
    )) {
        $command = $connection.CreateCommand()
        try {
            $command.CommandText = $sql
            $command.ExecuteNonQuery() | Out-Null
        } finally {
            $command.Dispose()
        }
    }

    $tables = Get-PohodaTableMap $connection
    $sw = New-Object IO.StringWriter
    $xw = [Xml.XmlWriter]::Create($sw, (New-Object Xml.XmlWriterSettings -Property @{ OmitXmlDeclaration = $true }))
    $xw.WriteStartElement('pohodaMdbAccounting')
    $counts = @{}
    foreach ($table in 'OBJ', 'OBJpol') {
        Assert-True ($PohodaAccountingTables.Contains($table)) "Tabulka $table chybí v seznamu exportovaných tabulek."
        Assert-True (-not ($PohodaRequiredTables -contains $table)) "Tabulka $table nesmí být povinná, starší agenda ji mít nemusí."
        $counts[$table] = Write-PohodaTable $xw $connection $table $PohodaAccountingTables[$table] $tables
    }
    $xw.WriteEndElement()
    $xw.Flush()
    [xml]$xml = $sw.ToString()

    Assert-True ($counts['OBJ'] -eq 1 -and $counts['OBJpol'] -eq 1) 'Export nevrátil po jednom řádku objednávky a položky.'
    $order = $xml.SelectSingleNode("/pohodaMdbAccounting/table[@name='OBJ']/row")
    $item = $xml.SelectSingleNode("/pohodaMdbAccounting/table[@name='OBJpol']/row")
    $orderColumns = @($order.ChildNodes | ForEach-Object { $_.LocalName })
    $itemColumns = @($item.ChildNodes | ForEach-Object { $_.LocalName })
    foreach ($expected in 'ID', 'Cislo', 'RelTpObj', 'Datum', 'DatDod', 'Vyrizeno', 'RefAD', 'RefCM', 'CmKurs', 'CmMnoz', 'KcCelkem') {
        Assert-True ($orderColumns -contains $expected) "OBJ neobsahuje sloupec $expected."
    }
    foreach ($expected in 'ID', 'RefAg', 'RefSKz', 'Mnozstvi', 'Dodano', 'MJ', 'KcJedn', 'RelSzDPH', 'Kc', 'KcDPH', 'OrderFld') {
        Assert-True ($itemColumns -contains $expected) "OBJpol neobsahuje sloupec $expected."
    }
    Assert-True (-not ($orderColumns -contains 'Labels') -and -not ($orderColumns -contains 'Oznacil')) 'OBJ vytáhl sloupec mimo povolený seznam.'
    Assert-True (-not ($itemColumns -contains 'VCislo')) 'OBJpol vytáhl sloupec mimo povolený seznam.'
    Assert-True (-not ($orderColumns -contains 'DatStorn')) 'Sloupec, který zdroj nemá, se nemá zapsat.'
    Assert-True ($order.RelTpObj -eq '2' -and $order.Vyrizeno -eq 'false' -and $order.Datum -eq '2026-03-02T00:00:00') "Hodnoty hlavičky objednávky nesedí: $($order.OuterXml)"
    Assert-True ($item.Mnozstvi -eq '10' -and $item.Dodano -eq '4' -and $item.Kc -eq '1000.5') "Hodnoty položky objednávky nesedí: $($item.OuterXml)"

    # Datový soubor bez objednávek: tabulka s atributem missing, export pokračuje.
    $drop = $connection.CreateCommand()
    $drop.CommandText = 'DROP TABLE [OBJpol]'
    $drop.ExecuteNonQuery() | Out-Null
    $drop.Dispose()
    $sw = New-Object IO.StringWriter
    $xw = [Xml.XmlWriter]::Create($sw, (New-Object Xml.XmlWriterSettings -Property @{ OmitXmlDeclaration = $true }))
    $missingCount = Write-PohodaTable $xw $connection 'OBJpol' $PohodaAccountingTables['OBJpol'] (Get-PohodaTableMap $connection)
    $xw.Flush()
    Assert-True ($missingCount -eq 0 -and $sw.ToString() -eq '<table name="OBJpol" missing="true" />') "Chybějící tabulka se nezapsala jako missing: $($sw.ToString())"

    Write-Output 'POHODA_ORDERS_EXPORT_OK'
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
