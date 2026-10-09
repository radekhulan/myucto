<#
.SYNOPSIS
    Vytáhne mzdy z datového souboru programu PAMICA do ZIPu pro převod do MyÚčta.

.DESCRIPTION
    PAMICA nemá XML rozhraní jako POHODA, takže se čte přímo z datového souboru
    (Mzdy*.mdb). Skript z něj udělá ZIP ve tvaru, který čeká průvodce „Přechod
    z PAMICA" v MyÚčtu:

      <IČO>_<rok>\91_mzdy.xml      zaměstnanci, pracovní poměry, zpracované mzdy,
                                   srážky a exekuce, podání pro ČSSZ a zdravotní
                                   pojišťovny včetně obsahu odeslaných hlášení,
                                   platby a číselníky mezd

    Pro každý rok, který je v datech, vznikne vlastní složka. Kmenové údaje
    (zaměstnanci, pracovní poměry, pracovní místa) a číselníky jsou v každém roce
    celé, záznamy vázané na rok (mzdy a vše, co z nich visí) jen za ten rok.

    Skript data jen čte, nic v nich nemění (připojení v režimu Read). Tabulky bere
    celé, vynechává jen čistě systémové sloupce (kdo záznam označil a zamkl, výběr,
    ruční pořadí). Obsah podání, který PAMICA drží v binárních sloupcích (měsíční
    hlášení JMHZ, registrace zaměstnanců, hlášení cizinců, roční zúčtování…), rozepíše
    na jednotlivé atributy datového slovníku JMHZ, aby převod převzal i odeslaná
    hlášení. Binární sloupec, který nejde přečíst (obrázek, doručenka), vynechá
    a započítá do souhrnu.

    Před spuštěním PAMICU zavřete.

    Požadavky: Windows a ovladač Microsoft Access Database Engine (instaluje se
    s PAMICOU); skript si podle potřeby sám zvolí 32bitový PowerShell.

.PARAMETER Mdb
    Cesta k datovému souboru PAMICA (Mzdy*.mdb). Bez zadání si ho skript najde sám
    v obvyklých umístěních.

.PARAMETER Rok
    Roky k vytažení. Bez zadání všechny roky, které jsou ve zpracovaných mzdách.

.PARAMETER Ico
    IČO firmy pro název složky. Bez zadání se bere z datového souboru.

.PARAMETER Vystup
    Složka, kam se zapíše ZIP a souhrn. Bez zadání složka vedle skriptu.

.EXAMPLE
    .\Export-Pamica.ps1

.EXAMPLE
    .\Export-Pamica.ps1 -Mdb "C:\ProgramData\STORMWARE\PAMICA\Data\Mzdy.mdb" -Rok 2025,2026
#>
[CmdletBinding()]
param(
    [string]$Mdb,
    [string[]]$Rok,
    [string]$Ico,
    [string]$Vystup
)

$ErrorActionPreference = 'Stop'

# Z .cmd přes -File přijde "2025,2026" jako jeden řetězec ([int[]] z něj dělal 20252026).
$Rok = @($Rok | ForEach-Object { "$_" -split '[,;\s]+' } | Where-Object { $_ -ne '' } | ForEach-Object { [int]$_ })

<#
    Tabulky, které se vytahují, v pořadí zápisu do XML. Chybějící tabulka (jiná verze
    PAMICY) se přeskočí bez chyby. Seznam je shodný s mzdovou skupinou
    Export-PohodaMdb.ps1 - při změně upravte oba.

      Kde       podmínka roku; `{rok}` se nahradí rokem. Bez ní je tabulka v každém
                roce celá (kmen, číselníky).
      Zavisi    tabulka, kterou podmínka potřebuje; když chybí, přeskočí se obojí
      KdeNebo   sloupec a podmínka navíc (spojí se přes OR), použije se jen když
                ten sloupec v tabulce je
      BezBlobu  binární sloupce, které se nevytahují ani nezkoušejí číst (doručenky
                datové schránky jsou ZIP s podepsanou zprávou, ne data podání)
#>
$PamicaTables = [ordered]@{
    # --- kmen a vztahy ---
    ZAM            = @{}
    ZAMpomer       = @{}
    ZAMpDet        = @{}
    ZAMucet        = @{}
    ZAMpoj         = @{}
    ZAMzp          = @{}
    ZAMzivPoj      = @{}
    ZAMpDov        = @{}
    ZAMpSra        = @{}
    ZAMprideleni   = @{}
    ZAMkval        = @{}
    ZAMcleneni     = @{}
    ZAMseznamy     = @{}
    ZamHist        = @{}
    PracMista      = @{}
    SocPojSleva    = @{}
    # --- mzdy ---
    MZ             = @{ Kde = 'Rok = {rok}' }
    MZ2            = @{ Kde = 'RefAg IN (SELECT ID FROM [MZ] WHERE Rok = {rok})'; Zavisi = 'MZ' }
    # Trvalé mzdové složky visí na pracovním poměru, ne na mzdě - patří do každého roku.
    MZslozky       = @{ Kde = 'RefAg IN (SELECT ID FROM [MZ] WHERE Rok = {rok})'; Zavisi = 'MZ'
        KdeNebo = @('Trvale', 'Trvale <> 0') }
    MZneprit       = @{ Kde = 'RefAg IN (SELECT ID FROM [MZ] WHERE Rok = {rok})'; Zavisi = 'MZ' }
    MZsrazky       = @{ Kde = 'RefAg IN (SELECT ID FROM [MZ] WHERE Rok = {rok})'; Zavisi = 'MZ' }
    MZdavky        = @{ Kde = 'Rok = {rok}' }
    MZnahr         = @{ Kde = 'RefAg IN (SELECT ID FROM [MZ] WHERE Rok = {rok})'; Zavisi = 'MZ' }
    MZdoch         = @{ Kde = 'Rok = {rok}' }
    MZzauct        = @{ Kde = 'Rok = {rok}' }
    MZzauctRoz     = @{ Kde = 'Rok = {rok}' }
    MZdanKomp      = @{ Kde = 'Rok = {rok}' }
    Dovolena       = @{ Kde = 'Rok = {rok}' }
    zalZAM         = @{}
    # --- srážky a exekuce (včetně příjemce a rozpadu nezabavitelné částky) ---
    ZAMsrazky      = @{}
    rpZAMprijemSraz = @{}
    # --- podání a hlášení (obsah odeslaných podání je v atributových blobech) ---
    RegZAM         = @{}
    RegZAMitems    = @{}
    RegZAMprilohy  = @{}
    PredRegZAM     = @{}
    PredRegZAMitems = @{}
    ONZ            = @{}
    ONZpol         = @{}
    ONZduchPoj     = @{}
    ONZprilohy     = @{}
    ELDP           = @{ Kde = 'Rok = {rok}' }
    ELDPpol        = @{ Kde = 'Rok = {rok}' }
    MH             = @{ Kde = 'Rok = {rok}' }
    MHitems        = @{ Kde = 'RefAg IN (SELECT ID FROM [MH] WHERE Rok = {rok})'; Zavisi = 'MH' }
    NEMPRI         = @{ Kde = 'ID IN (SELECT RefAg FROM [NEMPRIpol] WHERE RokMZ = {rok})'; Zavisi = 'NEMPRIpol' }
    NEMPRIpol      = @{ Kde = 'RokMZ = {rok}' }
    NEMPRIdeti     = @{ Kde = 'RefPol IN (SELECT ID FROM [NEMPRIpol] WHERE RokMZ = {rok})'; Zavisi = 'NEMPRIpol' }
    NEMPRIpecovalDny = @{ Kde = 'RefPol IN (SELECT ID FROM [NEMPRIpol] WHERE RokMZ = {rok})'; Zavisi = 'NEMPRIpol' }
    NEMPRIpraceVeDnech = @{ Kde = 'RefPol IN (SELECT ID FROM [NEMPRIpol] WHERE RokMZ = {rok})'; Zavisi = 'NEMPRIpol' }
    NEMPRIpracVolno = @{ Kde = 'RefPol IN (SELECT ID FROM [NEMPRIpol] WHERE RokMZ = {rok})'; Zavisi = 'NEMPRIpol' }
    NEMPRIrozvrhSmen = @{ Kde = 'RefPol IN (SELECT ID FROM [NEMPRIpol] WHERE RokMZ = {rok})'; Zavisi = 'NEMPRIpol' }
    NEMPRIprilohy  = @{}
    HZUPN          = @{ Kde = 'ID IN (SELECT RefAg FROM [HZUPNpol] WHERE RokMZ = {rok})'; Zavisi = 'HZUPNpol' }
    HZUPNpol       = @{ Kde = 'RokMZ = {rok}' }
    HZUPNpracoval  = @{ Kde = 'RefPol IN (SELECT ID FROM [HZUPNpol] WHERE RokMZ = {rok})'; Zavisi = 'HZUPNpol' }
    HlaseniCiz     = @{ Kde = 'Rok = {rok}' }
    PDB            = @{ Kde = 'Rok = {rok}' }
    PDBprilohy     = @{}
    VDP            = @{ Kde = 'Rok = {rok}' }
    VDPprilohy     = @{}
    RocniZuctovani = @{ Kde = 'Rok = {rok}' }
    RocniZuctovaniPrilohy = @{}
    EPodani        = @{ Kde = 'Rok = {rok}' }
    DataBoxSent    = @{ BezBlobu = @('Dorucenka') }
    Upominky       = @{}
    # --- platby (příkazy k úhradě mezd, odvodů a srážek) ---
    Doklady        = @{ Kde = 'Rok = {rok}' }
    DokladyPol     = @{ Kde = 'RefAg IN (SELECT ID FROM [Doklady] WHERE Rok = {rok})'; Zavisi = 'Doklady' }
    DokladyPk      = @{ Kde = 'RefAg IN (SELECT ID FROM [Doklady] WHERE Rok = {rok})'; Zavisi = 'Doklady' }
    BP             = @{ Kde = 'YEAR(Datum) = {rok}' }
    BPpol          = @{ Kde = 'RefAg IN (SELECT ID FROM [BP] WHERE YEAR(Datum) = {rok})'; Zavisi = 'BP' }
    # --- číselníky ---
    sMZslozky      = @{}
    sMZneprit      = @{}
    sMZsrazky      = @{}
    sMzPoj         = @{}
    sMzFond        = @{}
    sMzMist        = @{}
    sMzZivPj       = @{}
    sMzDIP         = @{}
    sMzPDP         = @{}
    sSTR           = @{}
    sDrUkonceni    = @{}
    sOdstupne      = @{}
    sKvalifikace   = @{}
    sUdalosti      = @{}
    sTurnus        = @{}
    sUcet          = @{}
    sBanky         = @{}
    sKSym          = @{}
    sCMeny         = @{}
    sCRady         = @{}
    sAnalytika     = @{}
    sMesice        = @{}
    sMJ            = @{}
    sFormUh        = @{}
    sZeme          = @{}
    sRecordLabels  = @{}
    LekarDef       = @{}
    SkoleniDef     = @{}
    sTypSkoleni    = @{}
    pPK            = @{}
    pOS            = @{}
    pOSuSk         = @{}
    # --- metadata ---
    sKonfig        = @{}
    Verze          = @{}
}

# Čistě systémové sloupce, které se nevytahují: kdo záznam označil, výběr v seznamu,
# ruční pořadí a zámky. Datum založení a uložení zůstává - podle něj jde poznat pořadí podání.
# SQL Server má navíc vypočtené sloupce NullCheck_* (unikátnost s NULL), v MDB nejsou.
$PamicaSkipColumns = @('Oznacil', 'Ucetni', 'Creator', 'Sel', 'UsrOrder')

function Test-PamicaSkipColumn([string]$Name) {
    return ($PamicaSkipColumns -contains $Name) -or ($Name -match '^Lock\d*$') -or ($Name -like 'NullCheck_*') -or ($Name -notmatch '^[A-Za-z_][A-Za-z0-9_]*$')
}

# --- atributová data podání -------------------------------------------------
# Totéž čtení je v Export-PohodaMdb.ps1 (ConvertFrom-PohodaAttributeBlob); při změně upravte obě.

$script:PamicaCp1250 = [Text.Encoding]::GetEncoding(1250)

function Test-PamicaAttributeHeader([byte[]]$Bytes, [int]$Pos) {
    if ($Pos + 9 -gt $Bytes.Length) { return $false }
    $section = [BitConverter]::ToInt32($Bytes, $Pos)
    $attribute = [BitConverter]::ToInt32($Bytes, $Pos + 4)
    $len = $Bytes[$Pos + 8]
    if ($section -lt 0 -or $section -gt 64) { return $false }
    if ($attribute -lt 0 -or $attribute -gt 99999) { return $false }
    if ($Pos + 9 + $len + 1 -gt $Bytes.Length) { return $false }
    return $Bytes[$Pos + 9 + $len] -eq 0
}

<#
    Atributová data PAMICA (MH.DataAll, MHitems.Data, RegZAMitems.Data a další):
    int32 verze, int32 počet záznamů, pak záznamy - int32 oddíl, int32 ID atributu
    datového slovníku JMHZ, 1 bajt délka, text v cp1250, nulový bajt a koncovka.
    Koncovka je int32 příznak, int32 pořadí v opakované skupině (děti, sekce ELDP)
    a u měsíčního hlášení ještě int32 druhé pořadí; má tedy 12 nebo 8 bajtů a správná
    délka se pozná podle toho, že za ní začíná platná hlavička dalšího záznamu.
    Blob jiného tvaru (obrázek, ZIP) vrátí $null.
#>
function ConvertFrom-PamicaAttributeBlob([byte[]]$Bytes) {
    if ($null -eq $Bytes -or $Bytes.Length -lt 8) { return $null }
    $version = [BitConverter]::ToInt32($Bytes, 0)
    $count = [BitConverter]::ToInt32($Bytes, 4)
    if ($count -lt 0 -or $count -gt 100000) { return $null }
    $pos = 8
    $out = New-Object System.Collections.Generic.List[object]
    for ($i = 0; $i -lt $count; $i++) {
        if (-not (Test-PamicaAttributeHeader $Bytes $pos)) { return $null }
        $section = [BitConverter]::ToInt32($Bytes, $pos)
        $attribute = [BitConverter]::ToInt32($Bytes, $pos + 4)
        $len = $Bytes[$pos + 8]
        $text = $script:PamicaCp1250.GetString($Bytes, $pos + 9, $len)
        $next = $pos + 10 + $len
        $last = ($i -eq $count - 1)
        $trailer = 0
        foreach ($candidate in 12, 8) {
            $end = $next + $candidate
            if (($last -and $end -eq $Bytes.Length) -or (-not $last -and (Test-PamicaAttributeHeader $Bytes $end))) {
                $trailer = $candidate
                break
            }
        }
        if ($trailer -eq 0) { return $null }
        $order2 = 0
        if ($trailer -eq 12) { $order2 = [BitConverter]::ToInt32($Bytes, $next + 8) }
        $out.Add([pscustomobject]@{
            Id = $attribute
            Section = $section
            Flag = [BitConverter]::ToInt32($Bytes, $next)
            Order = [BitConverter]::ToInt32($Bytes, $next + 4)
            Order2 = $order2
            Value = $text
        })
        $pos = $next + $trailer
    }
    if ($pos -ne $Bytes.Length) { return $null }
    return [pscustomobject]@{ Version = $version; Items = $out.ToArray() }
}

# Znaky, které XML 1.0 nepovoluje (řídicí znaky kromě tabulátoru a konce řádku).
function Get-PamicaXmlText([string]$Value) {
    return [regex]::Replace($Value, '[\x00-\x08\x0B\x0C\x0E-\x1F]', '')
}

<# Zapíše přečtený blob jako `<Sloupec v="1"><a id="10228" t="9" f="1" i="1">hodnota</a>…</Sloupec>`. #>
function Write-PamicaAttributes($Writer, [string]$Name, $Decoded) {
    $Writer.WriteStartElement($Name)
    $Writer.WriteAttributeString('v', [string]$Decoded.Version)
    foreach ($item in $Decoded.Items) {
        $Writer.WriteStartElement('a')
        $Writer.WriteAttributeString('id', [string]$item.Id)
        $Writer.WriteAttributeString('t', [string]$item.Section)
        $Writer.WriteAttributeString('f', [string]$item.Flag)
        if ($item.Order -ne 0) { $Writer.WriteAttributeString('i', [string]$item.Order) }
        if ($item.Order2 -ne 0) { $Writer.WriteAttributeString('j', [string]$item.Order2) }
        $Writer.WriteString((Get-PamicaXmlText $item.Value))
        $Writer.WriteEndElement()
    }
    $Writer.WriteEndElement()
}

# Obvyklá umístění datového souboru PAMICA.
function Get-PamicaDataDirs {
    $dirs = New-Object System.Collections.Generic.List[string]
    foreach ($base in @($env:ProgramData, ${env:ProgramFiles(x86)}, $env:ProgramFiles, 'C:\')) {
        if ($base) { $dirs.Add((Join-Path $base 'STORMWARE\PAMICA\Data')) }
    }
    foreach ($drive in [IO.DriveInfo]::GetDrives()) {
        if (-not $drive.IsReady) { continue }
        if (@('Fixed', 'Network', 'Removable') -notcontains $drive.DriveType.ToString()) { continue }
        $dirs.Add((Join-Path $drive.RootDirectory.FullName 'STORMWARE\PAMICA\Data'))
    }
    return @($dirs | Select-Object -Unique)
}

function Find-PamicaMdb {
    $found = New-Object System.Collections.Generic.List[string]
    foreach ($dir in (Get-PamicaDataDirs)) {
        if (-not (Test-Path -LiteralPath $dir)) { continue }
        foreach ($file in (Get-ChildItem -LiteralPath $dir -Filter 'Mzdy*.mdb' -File -ErrorAction SilentlyContinue)) {
            $found.Add($file.FullName)
        }
    }
    return @($found | Sort-Object -Unique)
}

function Open-PamicaSource([string]$Mdb) {
    if (-not (Test-Path -LiteralPath $Mdb)) { throw "Datový soubor $Mdb neexistuje." }
    foreach ($p in 'Microsoft.ACE.OLEDB.16.0', 'Microsoft.ACE.OLEDB.12.0', 'Microsoft.Jet.OLEDB.4.0') {
        try {
            $c = New-Object System.Data.OleDb.OleDbConnection "Provider=$p;Data Source=$Mdb;Mode=Read;Persist Security Info=False"
            $c.Open()
            return $c
        } catch { }
    }
    return $null
}

<#
    Tabulky zdroje. SQL Server (SqlClient i ODBC) se ptá INFORMATION_SCHEMA: GetSchema
    vrací u SqlClient BASE TABLE a TABLE_SCHEMA, u ODBC TABLE a TABLE_SCHEM.
#>
function Get-PamicaTables($Conn) {
    if ($Conn -is [System.Data.OleDb.OleDbConnection]) {
        return @($Conn.GetSchema('Tables') | Where-Object { $_.TABLE_TYPE -eq 'TABLE' } | ForEach-Object { $_.TABLE_NAME })
    }
    $rows = Get-PamicaRows $Conn "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE' AND TABLE_SCHEMA = 'dbo'"
    return @($rows.Rows | ForEach-Object { [string]$_[0] })
}

function New-PamicaCommand($Conn, [string]$Sql) {
    $cmd = $Conn.CreateCommand()
    $cmd.CommandText = $Sql
    # SQL Server: velké tabulky se čtou déle než výchozích 30 s.
    if ($Conn -isnot [System.Data.OleDb.OleDbConnection]) { $cmd.CommandTimeout = 600 }
    return $cmd
}

function Get-PamicaRows($Conn, [string]$Sql) {
    $cmd = New-PamicaCommand $Conn $Sql
    $table = New-Object System.Data.DataTable
    $r = $cmd.ExecuteReader()
    try { $table.Load($r) } finally { $r.Close() }
    return , $table
}

function Get-PamicaColumns($Conn, [string]$Table) {
    return @((Get-PamicaRows $Conn "SELECT * FROM [$Table] WHERE 1 = 0").Columns | ForEach-Object { $_.ColumnName })
}

function Get-PamicaScalar($Conn, [string]$Sql) {
    $cmd = New-PamicaCommand $Conn $Sql
    return $cmd.ExecuteScalar()
}

<#
    IČO firmy z datového souboru (nastavení účetní jednotky). Prázdný řetězec = nenašlo se
    a uživatel ho musí zadat parametrem -Ico.
#>
function Get-PamicaIco($Conn, $Existing) {
    foreach ($pair in @(@('sKonfig', 'ICO'), @('sKonfig', 'IC'), @('Firma', 'ICO'), @('Firma', 'IC'), @('Verze', 'ICO'), @('Verze', 'IC'))) {
        $table = $pair[0]
        $column = $pair[1]
        if ($Existing -notcontains $table) { continue }
        if ((Get-PamicaColumns $Conn $table) -notcontains $column) { continue }
        $rows = Get-PamicaRows $Conn "SELECT [$column] FROM [$table]"
        foreach ($row in $rows.Rows) {
            $digits = ([string]$row[0]) -replace '\D', ''
            if ($digits.Length -ge 6 -and $digits.Length -le 8) { return $digits }
        }
    }
    return ''
}

<# Verze programu do hlavičky exportu, například `PAMICA 14226.2`. #>
function Get-PamicaProgram($Conn, $Existing) {
    if ($Existing -notcontains 'Verze') { return 'PAMICA' }
    $cols = Get-PamicaColumns $Conn 'Verze'
    $rows = Get-PamicaRows $Conn 'SELECT * FROM [Verze]'
    if ($rows.Rows.Count -eq 0) { return 'PAMICA' }
    $row = $rows.Rows[0]
    $name = if ($cols -contains 'Nazev') { [string]$row['Nazev'] } else { 'PAMICA' }
    $release = if ($cols -contains 'Release') { [string]$row['Release'] } else { '' }
    return (($name, $release) -join ' ').Trim()
}

function Write-PamicaValue($Writer, [string]$Name, $Value) {
    if ($Value -is [DBNull] -or $null -eq $Value) { return }
    if ($Value -is [byte[]]) { return }
    $text = switch ($Value.GetType().Name) {
        # Datum bez času jako dřív; čas se připojí jen tam, kde ho PAMICA vede (okamžik podání).
        'DateTime' { if ($Value.TimeOfDay.Ticks -eq 0) { $Value.ToString('yyyy-MM-dd') } else { $Value.ToString('yyyy-MM-ddTHH:mm:ss') } }
        'Boolean'  { if ($Value) { '1' } else { '0' } }
        # Bez koncových nul (100.0000 i 100 => 100): stejný výstup z MDB i SQL Serveru v každé verzi PowerShellu.
        'Decimal'  { $Value.ToString('0.############################', [Globalization.CultureInfo]::InvariantCulture) }
        'Double'   { $Value.ToString('R', [Globalization.CultureInfo]::InvariantCulture) }
        'Single'   { $Value.ToString('R', [Globalization.CultureInfo]::InvariantCulture) }
        default    { Get-PamicaXmlText ([string]$Value) }
    }
    if ($text -eq '') { return }
    $Writer.WriteElementString($Name, $text)
}

<#
    Mzdy jednoho roku do `91_mzdy.xml`. Vrací počty řádků po tabulkách a počty
    přečtených a nečitelných binárních sloupců (`tabulka.sloupec`).
#>
function Export-PamicaYear($Conn, [string]$Cil, [string]$Ico, [int]$Rok, [string]$Program, $Existing) {
    $tmp = "$Cil.tmp"
    $settings = New-Object System.Xml.XmlWriterSettings
    $settings.Encoding = New-Object System.Text.UTF8Encoding($false)
    $settings.Indent = $true
    $w = [System.Xml.XmlWriter]::Create($tmp, $settings)
    $counts = [ordered]@{}
    $blobs = [ordered]@{}
    try {
        $w.WriteStartDocument()
        $w.WriteStartElement('mdbExport')
        $w.WriteAttributeString('version', '1')
        $w.WriteAttributeString('group', 'mzdy')
        $w.WriteAttributeString('ico', $Ico)
        $w.WriteAttributeString('year', [string]$Rok)
        $w.WriteAttributeString('source', 'PAMICA')
        $w.WriteAttributeString('programVersion', $Program)
        $w.WriteAttributeString('state', 'ok')
        $w.WriteAttributeString('created', (Get-Date -Format 'yyyy-MM-ddTHH:mm:ss'))
        foreach ($name in $PamicaTables.Keys) {
            if ($Existing -notcontains $name) { continue }
            $def = $PamicaTables[$name]
            if ($def.Zavisi -and $Existing -notcontains $def.Zavisi) { continue }
            $present = Get-PamicaColumns $Conn $name
            $sql = "SELECT * FROM [$name]"
            if ($def.Kde) {
                $kde = $def.Kde
                # Stejně jako Export-PohodaMdb.ps1: MZdavky bez sloupce Rok (agenda POHODY) se omezí přes mzdu.
                if ($name -eq 'MZdavky' -and $present -notcontains 'Rok') {
                    if ($present -notcontains 'RefAg' -or (Get-PamicaColumns $Conn 'MZ') -notcontains 'Rok') {
                        throw "Tabulka MZdavky neobsahuje Rok ani použitelnou vazbu RefAg na MZ.ID; nelze ji bezpečně omezit na rok $Rok."
                    }
                    $kde = 'RefAg IN (SELECT ID FROM [MZ] WHERE Rok = {rok})'
                }
                if ($def.KdeNebo -and $present -contains $def.KdeNebo[0]) { $kde = "($kde) OR ($($def.KdeNebo[1]))" }
                $sql += ' WHERE ' + ($kde -replace '\{rok\}', [string]$Rok)
            }
            $table = Get-PamicaRows $Conn $sql
            $take = @($table.Columns | Where-Object { -not (Test-PamicaSkipColumn $_.ColumnName) -and $def.BezBlobu -notcontains $_.ColumnName })
            foreach ($row in $table.Rows) {
                $w.WriteStartElement($name)
                foreach ($col in $take) {
                    $value = $row[$col]
                    if ($value -is [byte[]]) {
                        $key = "$name.$($col.ColumnName)"
                        if (-not $blobs.Contains($key)) { $blobs[$key] = @{ Read = 0; Unreadable = 0 } }
                        $decoded = ConvertFrom-PamicaAttributeBlob $value
                        if ($null -eq $decoded) {
                            $blobs[$key].Unreadable++
                        } else {
                            Write-PamicaAttributes $w $col.ColumnName $decoded
                            $blobs[$key].Read++
                        }
                        continue
                    }
                    Write-PamicaValue $w $col.ColumnName $value
                }
                $w.WriteEndElement()
            }
            $counts[$name] = $table.Rows.Count
        }
        $w.WriteEndElement()
        $w.WriteEndDocument()
    } finally { $w.Close() }
    Move-Item -Force $tmp $Cil
    return [pscustomobject]@{ Pocty = $counts; Bloby = $blobs }
}

<#
    Export mezd z otevřeného spojení (datový soubor MDB nebo SQL Server) do ZIPu
    pamica_export_<datum>.zip ve složce $Vystup, se souhrnem vedle. $Zdroj pojmenuje
    zdroj v hláškách, $ZdrojPopis je první řádek souhrnu o zdroji. Vrací cestu k ZIPu.
#>
function Invoke-PamicaExport($conn, [string]$Zdroj, [string]$ZdrojPopis, [string]$Ico, [int[]]$Rok, [string]$Vystup) {
    $existing = Get-PamicaTables $conn
    if ($existing -notcontains 'MZ' -or $existing -notcontains 'ZAM') {
        throw "V $Zdroj nejsou mzdové tabulky (ZAM, MZ) - nejsou to mzdy PAMICY."
    }

    if (-not $Ico) { $Ico = Get-PamicaIco $conn $existing }
    $Ico = $Ico -replace '\D', ''
    if ($Ico.Length -lt 6 -or $Ico.Length -gt 8) {
        throw 'IČO firmy se v datovém souboru nenašlo. Spusťte skript znovu s parametrem -Ico <IČO firmy>.'
    }

    $program = Get-PamicaProgram $conn $existing
    $dostupneRoky = @((Get-PamicaRows $conn 'SELECT DISTINCT Rok FROM [MZ] WHERE Rok BETWEEN 1990 AND 2100 ORDER BY Rok').Rows | ForEach-Object { [int]$_[0] })
    if ($dostupneRoky.Count -eq 0) { throw 'V datovém souboru nejsou zpracované mzdy (tabulka MZ je prázdná).' }

    $roky = if ($Rok) { @($Rok | Where-Object { $dostupneRoky -contains $_ } | Sort-Object -Unique) } else { $dostupneRoky }
    if ($roky.Count -eq 0) {
        throw ('Zadané roky v datovém souboru nejsou. K dispozici: ' + ($dostupneRoky -join ', ') + '.')
    }

    $stamp = Get-Date -Format 'yyyyMMdd-HHmm'
    $stage = Join-Path $Vystup "pamica_export_$stamp"
    if (Test-Path -LiteralPath $stage) { Remove-Item -Recurse -Force -LiteralPath $stage }
    New-Item -ItemType Directory -Force $stage | Out-Null

    $zam = [int](Get-PamicaScalar $conn 'SELECT COUNT(*) FROM [ZAM]')
    # Pracovní poměry jsou v samostatné tabulce jen v PAMICA; agenda POHODY s mzdami ji nemá.
    $pomery = 0
    $nastup = $null
    $odchod = $null
    if ($existing -contains 'ZAMpomer') {
        $pomery = [int](Get-PamicaScalar $conn 'SELECT COUNT(*) FROM [ZAMpomer]')
        $nastup = Get-PamicaScalar $conn 'SELECT MIN(DatNast) FROM [ZAMpomer] WHERE DatNast IS NOT NULL'
        $odchod = Get-PamicaScalar $conn 'SELECT MAX(DatOdch) FROM [ZAMpomer] WHERE DatOdch IS NOT NULL'
    }

    $souhrn = New-Object System.Collections.Generic.List[string]
    $souhrn.Add("Export mezd z PAMICY")
    $souhrn.Add($ZdrojPopis)
    $souhrn.Add("Program: $program")
    $souhrn.Add("IČO: $Ico")
    $souhrn.Add("Vytvořeno: " + (Get-Date -Format 'yyyy-MM-dd HH:mm'))
    $souhrn.Add('')
    $souhrn.Add("Zaměstnanců: $zam")
    $souhrn.Add("Pracovních poměrů: $pomery")
    if ($nastup -isnot [DBNull] -and $null -ne $nastup) { $souhrn.Add("Nejstarší nástup: " + ([datetime]$nastup).ToString('yyyy-MM-dd')) }
    if ($odchod -isnot [DBNull] -and $null -ne $odchod) { $souhrn.Add("Poslední ukončení: " + ([datetime]$odchod).ToString('yyyy-MM-dd')) }
    $souhrn.Add('')

    Write-Host ''
    Write-Host ("IČO {0}, {1}" -f $Ico, $program)
    Write-Host ("Zaměstnanců: {0}, pracovních poměrů: {1}" -f $zam, $pomery)

    $chybejici = @($PamicaTables.Keys | Where-Object { $existing -notcontains $_ })
    if ($chybejici.Count -gt 0) {
        $souhrn.Add('V datovém souboru nejsou tabulky: ' + ($chybejici -join ', '))
        $souhrn.Add('')
        Write-Host ('V datovém souboru nejsou tabulky: ' + ($chybejici -join ', ')) -ForegroundColor Yellow
    }
    # Pro kontrolu úplnosti: co v datovém souboru je, má data a export to nebere
    # (protokoly změn, odeslané e-maily, nastavení oken a dočasné tabulky programu).
    $mimo = New-Object System.Collections.Generic.List[string]
    foreach ($t in ($existing | Sort-Object)) {
        if ($PamicaTables.Contains($t)) { continue }
        $n = [int](Get-PamicaScalar $conn "SELECT COUNT(*) FROM [$t]")
        if ($n -gt 0) { $mimo.Add("$t ($n)") }
    }
    if ($mimo.Count -gt 0) {
        $souhrn.Add('Tabulky s daty, které export nebere (systémové, protokoly, dočasné): ' + ($mimo -join ', '))
        $souhrn.Add('')
    }

    foreach ($r in $roky) {
        $slozka = Join-Path $stage ("{0}_{1}" -f $Ico, $r)
        New-Item -ItemType Directory -Force $slozka | Out-Null
        $cil = Join-Path $slozka '91_mzdy.xml'
        $result = Export-PamicaYear $conn $cil $Ico $r $program $existing
        $counts = $result.Pocty

        $mesice = @((Get-PamicaRows $conn "SELECT DISTINCT RelMes FROM [MZ] WHERE Rok = $r ORDER BY RelMes").Rows | ForEach-Object { [int]$_[0] })
        $obdobi = if ($mesice.Count -gt 0) { '{0:0000}-{1:00} .. {0:0000}-{2:00}' -f $r, $mesice[0], $mesice[-1] } else { 'bez mezd' }
        $mezd = if ($counts.Contains('MZ')) { $counts['MZ'] } else { 0 }
        $velikost = [math]::Round((Get-Item -LiteralPath $cil).Length / 1KB)

        Write-Host ("  {0}  mezd {1,6}  obdobi {2}  ({3} kB)" -f (Split-Path $slozka -Leaf), $mezd, $obdobi, $velikost) -ForegroundColor Green

        $souhrn.Add("=== rok $r ===")
        $souhrn.Add("Složka: " + (Split-Path $slozka -Leaf) + '\91_mzdy.xml')
        $souhrn.Add("Zpracovaných mezd: $mezd")
        $souhrn.Add("Období: $obdobi")
        $souhrn.Add('Řádky po tabulkách:')
        foreach ($t in $counts.Keys) {
            $souhrn.Add(("  {0,-22} {1,8}" -f $t, $counts[$t]))
        }
        if ($result.Bloby.Count -gt 0) {
            $souhrn.Add('Binární sloupce (přečtené atributy podání / nečitelné):')
            foreach ($b in $result.Bloby.Keys) {
                $souhrn.Add(("  {0,-28} {1,8} {2,8}" -f $b, $result.Bloby[$b].Read, $result.Bloby[$b].Unreadable))
            }
        }
        $souhrn.Add('')
    }

    $zip = Join-Path $Vystup "pamica_export_$stamp.zip"
    if (Test-Path -LiteralPath $zip) { Remove-Item -Force -LiteralPath $zip }
    Compress-Archive -Path (Join-Path $stage '*') -DestinationPath $zip -CompressionLevel Optimal
    Remove-Item -Recurse -Force -LiteralPath $stage

    $souhrnPath = Join-Path $Vystup "pamica_export_$stamp-souhrn.txt"
    Set-Content -LiteralPath $souhrnPath -Value $souhrn -Encoding UTF8

    Write-Host ''
    Write-Host "Souhrn: $souhrnPath"
    Write-Host "Nahrajte do MyÚčta soubor: $zip" -ForegroundColor Cyan
    return $zip
}

# --- spuštění (při načtení do testu se neprovede) ---
if ($MyInvocation.InvocationName -eq '.') { return }

if ($env:OS -ne 'Windows_NT') {
    Write-Host 'Skript běží jen na Windows.' -ForegroundColor Red
    exit 1
}

Write-Host 'Export mezd z PAMICY. Než budete pokračovat, PAMICU zavřete - ze souboru se jen čte, ale otevřený program ho může zamykat.' -ForegroundColor Yellow

if (-not $Mdb) {
    $nalezene = Find-PamicaMdb
    if ($nalezene.Count -eq 0) {
        Write-Host 'Datový soubor PAMICY (Mzdy*.mdb) se nenašel. Zadejte ho parametrem -Mdb, cestu najdete v PAMICE v Soubor - Databáze.' -ForegroundColor Red
        exit 1
    }
    if ($nalezene.Count -gt 1) {
        Write-Host 'Datových souborů PAMICY je víc, vyberte jeden parametrem -Mdb:' -ForegroundColor Yellow
        foreach ($f in $nalezene) { Write-Host "  $f" }
        exit 1
    }
    $Mdb = $nalezene[0]
}
$Mdb = (Resolve-Path -LiteralPath $Mdb).Path
Write-Host "Datový soubor: $Mdb"

if (-not $Vystup) { $Vystup = $PSScriptRoot }
New-Item -ItemType Directory -Force $Vystup | Out-Null
$Vystup = (Resolve-Path -LiteralPath $Vystup).Path

$probe = Open-PamicaSource $Mdb
if ($null -eq $probe -and [Environment]::Is64BitProcess) {
    # Ovladač Accessu bývá jen 32bitový (instaluje ho 32bitová PAMICA) - zkusíme 32bitový PowerShell.
    $ps32 = Join-Path $env:WINDIR 'SysWOW64\WindowsPowerShell\v1.0\powershell.exe'
    if (-not (Test-Path -LiteralPath $ps32)) {
        Write-Host 'Datový soubor nejde otevřít: chybí ovladač Microsoft Access Database Engine.' -ForegroundColor Red
        exit 1
    }
    Write-Host 'Ovladač pro .mdb v 64bitovém PowerShellu chybí, spouštím 32bitový...'
    $q = { param($s) "'" + ($s -replace "'", "''") + "'" }
    $args32 = "-Mdb {0} -Vystup {1}" -f (& $q $Mdb), (& $q $Vystup)
    if ($Rok) { $args32 += ' -Rok ' + (($Rok | ForEach-Object { [string]$_ }) -join ',') }
    if ($Ico) { $args32 += ' -Ico ' + (& $q $Ico) }
    & $ps32 -NoProfile -ExecutionPolicy Bypass -Command ("& {0} {1}" -f (& $q $PSCommandPath), $args32)
    exit $LASTEXITCODE
}
if ($null -eq $probe) {
    Write-Host 'Datový soubor nejde otevřít: chybí ovladač Microsoft Access Database Engine.' -ForegroundColor Red
    exit 1
}
$conn = $probe

try {
    $null = Invoke-PamicaExport $conn $Mdb "Datový soubor: $Mdb" $Ico $Rok $Vystup
} catch {
    # Uživateli stačí věta, co je špatně; výpis volání by ho jen zmátl.
    Write-Host ''
    Write-Host $_.Exception.Message -ForegroundColor Red
    exit 1
} finally {
    $conn.Close()
}
