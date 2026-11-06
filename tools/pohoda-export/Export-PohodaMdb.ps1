<#
.SYNOPSIS
    Vytáhne z datového souboru POHODY majetek, mzdy a sklad, které XML export POHODY neobsahuje.

.DESCRIPTION
    XML rozhraní POHODY nevrací dlouhodobý majetek (karty, odpisové plány), mzdy
    (zaměstnanci, pracovní poměry, zpracované mzdy) ani ocenění skladu. Tento skript je čte
    přímo z datového souboru a uloží je jako XML vedle souborů exportu, aby je MyÚčto při
    převodu načetlo:

      90_majetek.xml   karty majetku, daňové odpisy po letech, účetní odpisy po měsících
      91_mzdy.xml      zaměstnanci, pracovní poměry, zpracované mzdy, srážky a exekuce,
                       podání pro ČSSZ a zdravotní pojišťovny včetně obsahu odeslaných
                       hlášení, platby a číselníky mezd
      92_sklad.xml     sklady, členění skladu, karty zásob se stavem, ceníky a ocenění
                       stavu každé karty po posledním pohybu (historie pohybů ne)

    Skupina mzdy je omezená na rok agendy (podle složky <IČO>_<rok> nebo názvu datového
    souboru) - kmenové údaje a číselníky jsou celé, záznamy vázané na rok jen za ten rok.

    Skript data jen čte, nic v nich nemění. Tabulky bere celé, vynechává jen čistě
    systémové sloupce (kdo záznam označil a zamkl, výběr, ruční pořadí). Obsah podání
    v binárních sloupcích (měsíční hlášení JMHZ, registrace zaměstnanců a další) rozepíše
    na atributy datového slovníku JMHZ; binární sloupec, který nejde přečíst, vynechá.

    Na konci vypíše a zapíše vedle každého XML souboru (`<soubor>-souhrn.txt`) přehled:
    které tabulky ze seznamu v datovém souboru nejsou, které jsou prázdné, kolik řádků
    má každá vytažená tabulka a kolik binárních sloupců se přečetlo.

    Zdrojem může být:
      - datový soubor POHODY nebo PAMICA (.mdb) - parametr -Mdb,
      - databáze POHODA SQL - parametry -SqlServer a -Databaze.

    Export-Pohoda.ps1 tento skript volá sám pro každou exportovanou agendu. Samostatně ho
    spusťte, když máte jen datový soubor, nebo mzdy vede jiný program (PAMICA).

    Požadavky: Windows. Pro .mdb ovladač Microsoft Access Database Engine (instaluje se
    s POHODOU); skript si podle potřeby sám zvolí 32bitový PowerShell.

.PARAMETER Mdb
    Cesta k datovému souboru POHODY nebo PAMICA (.mdb).

.PARAMETER SqlServer
    Instance SQL serveru POHODA SQL, například .\POHODA.

.PARAMETER Databaze
    Název databáze agendy v POHODA SQL (StwPh_<IČO>_<rok>).

.PARAMETER Vystup
    Složka agendy exportu (<IČO>_<rok>), kam se soubory zapíšou. Bez zadání složka vedle skriptu.

.PARAMETER Skupiny
    Co vytáhnout: majetek, mzdy, sklad (výchozí vše).

.PARAMETER Heslo
    Heslo databáze, je-li datový soubor jím chráněný. Bez zadání se na něj skript
    zeptá, když ho datový soubor vyžaduje.

.EXAMPLE
    .\Export-PohodaMdb.ps1 -Mdb "C:\ProgramData\STORMWARE\POHODA\Data\StwPh_12345678_2026.mdb" -Vystup .\pohoda_export\12345678_2026

.EXAMPLE
    .\Export-PohodaMdb.ps1 -Mdb "D:\PAMICA\Data\Mzdy.mdb" -Skupiny mzdy -Vystup .\mzdy
#>
[CmdletBinding()]
param(
    [string]$Mdb,
    [string]$SqlServer,
    [string]$Databaze,
    [string]$Vystup,
    [ValidateSet('majetek', 'mzdy', 'sklad')][string[]]$Skupiny = @('majetek', 'mzdy', 'sklad'),
    [string]$Heslo
)

$ErrorActionPreference = 'Stop'

<#
    Tabulky datového souboru po skupinách, v pořadí zápisu do XML. Chybějící tabulka (jiná
    verze nebo program) se přeskočí bez chyby. Mzdová skupina je shodná se seznamem
    v Export-Pamica.ps1 - při změně upravte oba.

      Kde       podmínka roku (jen skupina mzdy); `{rok}` se nahradí rokem agendy. Bez ní je
                tabulka v každém roce celá (kmen, číselníky). Bez známého roku se nepoužije -
                tabulka se vytáhne celá jako dřív.
      Zavisi    tabulka, kterou podmínka potřebuje; když v souboru chybí, přeskočí se obojí
      KdeNebo   sloupec a podmínka navíc (spojí se přes OR), použije se jen když ten sloupec
                v tabulce je - trvalé mzdové složky visí na pracovním poměru, ne na mzdě
      BezBlobu  sloupce, které se nevytahují: binární (doručenky datové schránky jsou ZIP
                s podepsanou zprávou, ne data podání) a dlouhé texty, které převod nečte
                (formátované popisy karet zásob)

    Skupina sklad má navíc odvozenou tabulku SKzStav (Write-PohodaStockValuation): ocenění
    stavu karty po posledním pohybu. Pohyby samotné (SKzPoh) se nevytahují.
#>
$PohodaMdbGroups = [ordered]@{
    majetek = @{
        Soubor  = '90_majetek.xml'
        Klic    = @('IM', 'DM')
        Tabulky = [ordered]@{
            IM = @{}; IModpis = @{}; IModpisM = @{}; IMuodpis = @{}; IMpohyb = @{}; IMpredm = @{}
            IMclen = @{}; IMmist = @{}; sIMO = @{}; sIMOpol = @{}; DM = @{}; DMpohyb = @{}
        }
    }
    sklad = @{
        Soubor  = '92_sklad.xml'
        Klic    = @('SKz')
        Tabulky = [ordered]@{
            sSklad = @{}; SkSt = @{}; SkCeny = @{}; sCMeny = @{}
            SKz    = @{ BezBlobu = @('Popis', 'Popis2', 'FmtPopis', 'FmtPopis2', 'ZpravaV', 'ZpravaP', 'TText') }
            SKzCn  = @{}; SKzPol = @{}
        }
    }
    mzdy = @{
        Soubor  = '91_mzdy.xml'
        Klic    = @('ZAM', 'MZ')
        Tabulky = [ordered]@{
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
    }
}

# Zdrojový doklad karty drobného majetku: DM.RelAgID je kód agendy, DM.RefPol záznam v ní
# (položka dokladu, případně doklad). Kódy agend odpovídají pUD.RelUdAg (deník).
$PohodaDmSources = @{
    2  = @('FA', 'FApol', 'FA')
    27 = @('HO', 'HOpol', 'HO')
    29 = @('pINT', 'pINTpol', 'INT')
}

# Čistě systémové sloupce, které se nevytahují: kdo záznam označil, výběr v seznamu,
# ruční pořadí a zámky. Datum založení a uložení zůstává - podle něj jde poznat pořadí podání.
# POHODA SQL má navíc vypočtené sloupce NullCheck_* (unikátnost s NULL), v MDB nejsou.
$PohodaMdbSkipColumns = @('Oznacil', 'Ucetni', 'Creator', 'Sel', 'UsrOrder')

function Test-PohodaSkipColumn([string]$Name) {
    return ($PohodaMdbSkipColumns -contains $Name) -or ($Name -match '^Lock\d*$') -or ($Name -like 'NullCheck_*') -or ($Name -notmatch '^[A-Za-z_][A-Za-z0-9_]*$')
}

# --- atributová data podání -------------------------------------------------
# Totéž čtení je v Export-Pamica.ps1 (ConvertFrom-PamicaAttributeBlob); při změně upravte obě.

$script:PohodaCp1250 = [Text.Encoding]::GetEncoding(1250)

function Test-PohodaAttributeHeader([byte[]]$Bytes, [int]$Pos) {
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
function ConvertFrom-PohodaAttributeBlob([byte[]]$Bytes) {
    if ($null -eq $Bytes -or $Bytes.Length -lt 8) { return $null }
    $version = [BitConverter]::ToInt32($Bytes, 0)
    $count = [BitConverter]::ToInt32($Bytes, 4)
    if ($count -lt 0 -or $count -gt 100000) { return $null }
    $pos = 8
    $out = New-Object System.Collections.Generic.List[object]
    for ($i = 0; $i -lt $count; $i++) {
        if (-not (Test-PohodaAttributeHeader $Bytes $pos)) { return $null }
        $section = [BitConverter]::ToInt32($Bytes, $pos)
        $attribute = [BitConverter]::ToInt32($Bytes, $pos + 4)
        $len = $Bytes[$pos + 8]
        $text = $script:PohodaCp1250.GetString($Bytes, $pos + 9, $len)
        $next = $pos + 10 + $len
        $last = ($i -eq $count - 1)
        $trailer = 0
        foreach ($candidate in 12, 8) {
            $end = $next + $candidate
            if (($last -and $end -eq $Bytes.Length) -or (-not $last -and (Test-PohodaAttributeHeader $Bytes $end))) {
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
function Get-PohodaXmlText([string]$Value) {
    return [regex]::Replace($Value, '[\x00-\x08\x0B\x0C\x0E-\x1F]', '')
}

<# Zapíše přečtený blob jako `<Sloupec v="1"><a id="10228" t="9" f="1" i="1">hodnota</a>…</Sloupec>`. #>
function Write-PohodaAttributes($Writer, [string]$Name, $Decoded) {
    $Writer.WriteStartElement($Name)
    $Writer.WriteAttributeString('v', [string]$Decoded.Version)
    foreach ($item in $Decoded.Items) {
        $Writer.WriteStartElement('a')
        $Writer.WriteAttributeString('id', [string]$item.Id)
        $Writer.WriteAttributeString('t', [string]$item.Section)
        $Writer.WriteAttributeString('f', [string]$item.Flag)
        if ($item.Order -ne 0) { $Writer.WriteAttributeString('i', [string]$item.Order) }
        if ($item.Order2 -ne 0) { $Writer.WriteAttributeString('j', [string]$item.Order2) }
        $Writer.WriteString((Get-PohodaXmlText $item.Value))
        $Writer.WriteEndElement()
    }
    $Writer.WriteEndElement()
}

<#
    Otevře datový soubor Accessu (.mdb) jen pro čtení, ovladače zkouší od nejnovějšího.
    Vrací Connection, nebo při neúspěchu Failure s důvodem a Detail s hláškami ovladačů:
      Driver    žádný ovladač Accessu v této bitovosti PowerShellu není registrovaný
      Password  soubor je chráněný heslem databáze (heslo chybí, nebo nesouhlasí)
      Locked    soubor drží výhradně jiný program, nebo chybí právo ke čtení
      Format    soubor není databáze Accessu, nebo ji ovladač nezná
      Other     jiná chyba
    Shodné s Export-Pamica.ps1 a Export-PohodaMdbAccounting.ps1 - při změně upravte všechny tři.
#>
function Open-MdbConnection([string]$Path, [string]$Heslo) {
    $details = New-Object System.Collections.Generic.List[string]
    $failure = $null
    foreach ($provider in 'Microsoft.ACE.OLEDB.16.0', 'Microsoft.ACE.OLEDB.12.0', 'Microsoft.Jet.OLEDB.4.0') {
        $connection = $null
        try {
            $builder = New-Object System.Data.OleDb.OleDbConnectionStringBuilder
            $builder.Provider = $provider
            $builder['Data Source'] = $Path
            $builder['Mode'] = 'Read'
            $builder['Persist Security Info'] = $false
            if ($Heslo) { $builder['Jet OLEDB:Database Password'] = $Heslo }
            $connection = New-Object System.Data.OleDb.OleDbConnection ([string]$builder.ConnectionString)
            $connection.Open()
            return [pscustomobject]@{ Connection = $connection; Failure = $null; Detail = '' }
        } catch {
            if ($connection) { $connection.Dispose() }
            $e = $_.Exception
            while ($e.InnerException) { $e = $e.InnerException }
            $message = $e.Message.Trim()
            $details.Add("${provider}: $message")
            # Neregistrovaný ovladač o souboru nic neříká; důvod dá první ovladač, který soubor otevřít zkusil.
            $kind = Get-MdbFailureKind $message.Replace($Path, '')
            if ($kind -ne 'Driver' -and $null -eq $failure) { $failure = $kind }
        }
    }
    if ($null -eq $failure) { $failure = 'Driver' }
    return [pscustomobject]@{ Connection = $null; Failure = $failure; Detail = ($details -join [Environment]::NewLine) }
}

function Get-MdbFailureKind([string]$Message) {
    if ($Message -match 'not registered|není registrov|nicht registriert') { return 'Driver' }
    if ($Message -match 'password|heslo|Kennwort') { return 'Password' }
    if ($Message -match 'exclusive|already in use|already opened|locked|could not use|výhradn|uzamčen|používá jiný') { return 'Locked' }
    if ($Message -match 'Unrecognized database format|Neznámý formát|nerozpozn|formát databáze') { return 'Format' }
    return 'Other'
}

<# Věta pro uživatele, proč se datový soubor neotevřel, včetně hlášek ovladačů. #>
function Get-MdbFailureMessage($Result, [string]$Program, [bool]$HesloZadano) {
    $text = switch ($Result.Failure) {
        'Driver' { 'Datový soubor nejde otevřít: chybí ovladač Microsoft Access Database Engine.' }
        'Password' {
            if ($HesloZadano) { 'Datový soubor nejde otevřít: heslo databáze nesouhlasí.' }
            else { 'Datový soubor je chráněný heslem databáze. Zadejte ho parametrem -Heslo.' }
        }
        'Locked' { "Datový soubor nejde otevřít, drží ho jiný program. Zavřete $Program na všech počítačích, které s datovým souborem pracují, a export spusťte znovu." }
        'Format' { 'Soubor není datový soubor Accessu (.mdb), nebo ho nainstalovaný ovladač nezná.' }
        default { 'Datový soubor nejde otevřít.' }
    }
    return $text + [Environment]::NewLine + 'Hlášení ovladačů:' + [Environment]::NewLine + $Result.Detail
}

<# Heslo databáze z konzole; v neinteraktivním běhu (bez konzole) vrátí $null. #>
function Read-MdbPassword {
    try {
        $secure = Read-Host 'Datový soubor je chráněný heslem databáze. Zadejte heslo' -AsSecureString
    } catch { return $null }
    if ($null -eq $secure -or $secure.Length -eq 0) { return $null }
    $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
    try { return [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr) }
    finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr) }
}

function Open-PohodaSource([string]$Mdb, [string]$SqlServer, [string]$Databaze, [string]$Heslo) {
    if ($Mdb) {
        if (-not (Test-Path $Mdb)) { throw "Datový soubor $Mdb neexistuje." }
        $result = Open-MdbConnection $Mdb $Heslo
        $script:PohodaOpenFailure = $result
        return $result.Connection
    }
    if ($SqlServer -and $Databaze) {
        $c = New-Object System.Data.SqlClient.SqlConnection "Server=$SqlServer;Database=$Databaze;Integrated Security=True;TrustServerCertificate=True;ApplicationIntent=ReadOnly"
        $c.Open()
        return $c
    }
    throw 'Zadejte datový soubor (-Mdb) nebo databázi POHODA SQL (-SqlServer a -Databaze).'
}

<#
    Tabulky zdroje. SQL Server (SqlClient i ODBC) se ptá INFORMATION_SCHEMA: GetSchema
    vrací u SqlClient BASE TABLE a TABLE_SCHEMA, u ODBC TABLE a TABLE_SCHEM.
#>
function Get-PohodaTables($Conn) {
    if ($Conn -is [System.Data.OleDb.OleDbConnection]) {
        return @($Conn.GetSchema('Tables') | Where-Object { $_.TABLE_TYPE -eq 'TABLE' } | ForEach-Object { $_.TABLE_NAME })
    }
    $rows = Get-PohodaRows $Conn "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE' AND TABLE_SCHEMA = 'dbo'"
    return @($rows.Rows | ForEach-Object { [string]$_[0] })
}

function New-PohodaMdbCommand($Conn, [string]$Sql) {
    $cmd = $Conn.CreateCommand()
    $cmd.CommandText = $Sql
    # SQL Server: velké tabulky se čtou déle než výchozích 30 s.
    if ($Conn -isnot [System.Data.OleDb.OleDbConnection]) { $cmd.CommandTimeout = 600 }
    return $cmd
}

function Get-PohodaRows($Conn, [string]$Sql) {
    $cmd = New-PohodaMdbCommand $Conn $Sql
    $table = New-Object System.Data.DataTable
    $r = $cmd.ExecuteReader()
    try { $table.Load($r) } finally { $r.Close() }
    return , $table
}

<#
    Zdroj karty drobného majetku: číslo, datum, text a částka položky dokladu (nejdřív
    položka podle RefPol, jinak přímo doklad). $null = zdroj neznámý.
#>
function Resolve-PohodaDmSource($Conn, $Agenda, $Ref) {
    $agendaId = 0; $refId = 0
    if (-not [int]::TryParse([string]$Agenda, [ref]$agendaId) -or -not [int]::TryParse([string]$Ref, [ref]$refId)) { return $null }
    if ($refId -le 0 -or -not $PohodaDmSources.ContainsKey($agendaId)) { return $null }
    $doc, $pol, $code = $PohodaDmSources[$agendaId]
    $existing = Get-PohodaTables $Conn
    if ($existing -notcontains $doc) { return $null }
    if ($existing -contains $pol) {
        $t = Get-PohodaRows $Conn "SELECT d.Cislo, d.Datum, p.SText, p.Kc FROM [$pol] p INNER JOIN [$doc] d ON d.ID = p.RefAg WHERE p.ID = $refId"
        if ($t.Rows.Count -gt 0) {
            return @{ Agenda = $code; Cislo = $t.Rows[0][0]; Datum = $t.Rows[0][1]; Text = $t.Rows[0][2]; Kc = $t.Rows[0][3] }
        }
    }
    $t = Get-PohodaRows $Conn "SELECT Cislo, Datum FROM [$doc] WHERE ID = $refId"
    if ($t.Rows.Count -gt 0) {
        return @{ Agenda = $code; Cislo = $t.Rows[0][0]; Datum = $t.Rows[0][1]; Text = $null; Kc = $null }
    }
    return $null
}

function Write-PohodaValue($Writer, [string]$Name, $Value) {
    if ($Value -is [DBNull] -or $null -eq $Value) { return }
    if ($Value -is [byte[]]) { return }
    $text = switch ($Value.GetType().Name) {
        # Datum bez času jako dřív; čas se připojí jen tam, kde ho zdroj vede (okamžik podání).
        'DateTime' { if ($Value.TimeOfDay.Ticks -eq 0) { $Value.ToString('yyyy-MM-dd') } else { $Value.ToString('yyyy-MM-ddTHH:mm:ss') } }
        'Boolean'  { if ($Value) { '1' } else { '0' } }
        # Bez koncových nul (100.0000 i 100 => 100): stejný výstup z MDB i SQL Serveru v každé verzi PowerShellu.
        'Decimal'  { $Value.ToString('0.############################', [Globalization.CultureInfo]::InvariantCulture) }
        'Double'   { $Value.ToString('R', [Globalization.CultureInfo]::InvariantCulture) }
        'Single'   { $Value.ToString('R', [Globalization.CultureInfo]::InvariantCulture) }
        default    { Get-PohodaXmlText ([string]$Value) }
    }
    if ($text -eq '') { return }
    $Writer.WriteElementString($Name, $text)
}

<#
    Ocenění stavu karty zásoby po posledním pohybu jako tabulka `SKzStav` (RefSKz, Datum,
    KcOceneni, Pohybu). POHODA vede hodnotu stavu v ocenění každého pohybu; průměrná cena
    na kartě je zaokrouhlená a stav × cena by se od hodnoty skladu lišil. Pohyby se čtou
    proudem seřazené podle karty a data, do XML jde jen poslední pohyb karty a počet pohybů.
    Vrací počet zapsaných karet.
#>
function Write-PohodaStockValuation($Conn, $Writer) {
    $reader = $null
    foreach ($order in 'RefSKz, Datum, OrderFld, ID', 'RefSKz, Datum, ID') {
        try {
            $reader = (New-PohodaMdbCommand $Conn "SELECT RefSKz, Datum, KcOceneni FROM [SKzPoh] ORDER BY $order").ExecuteReader()
            break
        } catch {
            if ($order -eq 'RefSKz, Datum, ID') { throw }
        }
    }
    $written = 0
    $card = $null; $date = $null; $value = $null; $moves = 0
    try {
        while ($reader.Read()) {
            $ref = $reader.GetValue(0)
            if ($ref -is [DBNull]) { continue }
            if ($null -ne $card -and $ref -ne $card) {
                Write-PohodaStockValuationRow $Writer $card $date $value $moves
                $written++
                $moves = 0
            }
            $card = $ref; $date = $reader.GetValue(1); $value = $reader.GetValue(2); $moves++
        }
        if ($null -ne $card) {
            Write-PohodaStockValuationRow $Writer $card $date $value $moves
            $written++
        }
    } finally { $reader.Close() }
    return $written
}

function Write-PohodaStockValuationRow($Writer, $Card, $Date, $Value, [int]$Moves) {
    $Writer.WriteStartElement('SKzStav')
    Write-PohodaValue $Writer 'RefSKz' $Card
    Write-PohodaValue $Writer 'Datum' $Date
    Write-PohodaValue $Writer 'KcOceneni' $Value
    Write-PohodaValue $Writer 'Pohybu' $Moves
    $Writer.WriteEndElement()
}

<#
    Vytáhne skupinu tabulek do XML souboru `$Cil`. Vrátí objekt s počtem řádků celkem
    (`Zaznamu`; 0 = soubor nevznikl, skupina v datovém souboru nemá data), počty řádků
    po jednotlivých vytažených tabulkách (`Pocty`, uspořádaný slovník), seznamem tabulek,
    které v datovém souboru chybí (`Chybejici`), a počty přečtených a nečitelných binárních
    sloupců (`Bloby`, klíč `tabulka.sloupec`).
#>
function Export-PohodaMdbGroup($Conn, [string]$Group, [string]$Cil, [string]$Ico, [string]$Rok) {
    $def = $PohodaMdbGroups[$Group]
    $existing = Get-PohodaTables $Conn
    $chybejici = @($def.Tabulky.Keys | Where-Object { $existing -notcontains $_ })
    # Skupina bez karet (majetek) nebo zaměstnanců a mezd se nevytahuje - samotné číselníky nic nepřevedou.
    $keyRows = 0
    foreach ($t in $def.Klic) {
        if ($existing -contains $t) {
            $cmd = New-PohodaMdbCommand $Conn "SELECT COUNT(*) FROM [$t]"
            $keyRows += [int]$cmd.ExecuteScalar()
        }
    }
    if ($keyRows -eq 0) {
        if (Test-Path $Cil) { Remove-Item -Force $Cil }
        return [pscustomobject]@{ Zaznamu = 0; Pocty = [ordered]@{}; Chybejici = $chybejici; Bloby = [ordered]@{} }
    }
    $tmp = "$Cil.tmp"
    $settings = New-Object System.Xml.XmlWriterSettings
    $settings.Encoding = New-Object System.Text.UTF8Encoding($false)
    $settings.Indent = $true
    $w = [System.Xml.XmlWriter]::Create($tmp, $settings)
    $rows = 0
    $counts = [ordered]@{}
    $blobs = [ordered]@{}
    try {
        $w.WriteStartDocument()
        $w.WriteStartElement('mdbExport')
        $w.WriteAttributeString('version', '1')
        $w.WriteAttributeString('group', $Group)
        if ($Ico) { $w.WriteAttributeString('ico', $Ico) }
        if ($Rok) { $w.WriteAttributeString('year', $Rok) }
        $w.WriteAttributeString('source', 'POHODA')
        $w.WriteAttributeString('state', 'ok')
        $w.WriteAttributeString('created', (Get-Date -Format 'yyyy-MM-ddTHH:mm:ss'))
        foreach ($t in $def.Tabulky.Keys) {
            if ($existing -notcontains $t) { continue }
            $tdef = $def.Tabulky[$t]
            if ($tdef.Zavisi -and $existing -notcontains $tdef.Zavisi) { continue }
            # Celá tabulka do paměti: u drobného majetku se během zápisu dotazuje zdrojový doklad
            # a otevřený reader by druhý dotaz na tomtéž spojení nepustil.
            $present = @((Get-PohodaRows $Conn "SELECT * FROM [$t] WHERE 1 = 0").Columns | ForEach-Object { $_.ColumnName })
            $sql = "SELECT * FROM [$t]"
            if ($tdef.Kde -and $Rok) {
                $kde = $tdef.Kde
                if ($t -eq 'MZdavky' -and $present -notcontains 'Rok') {
                    $mzColumns = @()
                    if ($existing -contains 'MZ') {
                        $mzColumns = @((Get-PohodaRows $Conn 'SELECT * FROM [MZ] WHERE 1 = 0').Columns | ForEach-Object { $_.ColumnName })
                    }
                    if ($present -notcontains 'RefAg' -or $mzColumns -notcontains 'ID' -or $mzColumns -notcontains 'Rok') {
                        throw "Tabulka MZdavky neobsahuje Rok ani použitelnou vazbu RefAg na MZ.ID; nelze ji bezpečně omezit na rok $Rok."
                    }
                    $kde = 'RefAg IN (SELECT ID FROM [MZ] WHERE Rok = {rok})'
                }
                if ($tdef.KdeNebo -and $present -contains $tdef.KdeNebo[0]) { $kde = "($kde) OR ($($tdef.KdeNebo[1]))" }
                $sql += ' WHERE ' + ($kde -replace '\{rok\}', [string]$Rok)
            }
            $table = Get-PohodaRows $Conn $sql
            foreach ($row in $table.Rows) {
                $w.WriteStartElement($t)
                foreach ($col in $table.Columns) {
                    $name = $col.ColumnName
                    if ((Test-PohodaSkipColumn $name) -or $tdef.BezBlobu -contains $name) { continue }
                    $value = $row[$col]
                    if ($value -is [byte[]]) {
                        $key = "$t.$name"
                        if (-not $blobs.Contains($key)) { $blobs[$key] = @{ Read = 0; Unreadable = 0 } }
                        $decoded = ConvertFrom-PohodaAttributeBlob $value
                        if ($null -eq $decoded) {
                            $blobs[$key].Unreadable++
                        } else {
                            Write-PohodaAttributes $w $name $decoded
                            $blobs[$key].Read++
                        }
                        continue
                    }
                    Write-PohodaValue $w $name $value
                }
                if ($t -eq 'DM' -and $table.Columns.Contains('RelAgID') -and $table.Columns.Contains('RefPol')) {
                    $src = Resolve-PohodaDmSource $Conn $row['RelAgID'] $row['RefPol']
                    if ($src) {
                        Write-PohodaValue $w 'SrcAgenda' $src.Agenda
                        Write-PohodaValue $w 'SrcCislo' $src.Cislo
                        Write-PohodaValue $w 'SrcDatum' $src.Datum
                        Write-PohodaValue $w 'SrcText' $src.Text
                        Write-PohodaValue $w 'SrcKc' $src.Kc
                    }
                }
                $w.WriteEndElement()
                $rows++
            }
            $counts[$t] = $table.Rows.Count
        }
        if ($Group -eq 'sklad' -and $existing -contains 'SKzPoh') {
            $counts['SKzStav'] = Write-PohodaStockValuation $Conn $w
            $rows += $counts['SKzStav']
        }
        $w.WriteEndElement()
        $w.WriteEndDocument()
    } finally { $w.Close() }
    if ($rows -gt 0) {
        Move-Item -Force $tmp $Cil
    } else {
        Remove-Item -Force $tmp
    }
    return [pscustomobject]@{ Zaznamu = $rows; Pocty = $counts; Chybejici = $chybejici; Bloby = $blobs }
}

<#
    Souhrn skupiny (počty řádků po tabulkách, chybějící a prázdné tabulky) do textového
    souboru vedle XML, ve stejném tvaru jako u Export-Pamica.ps1.
#>
function Write-PohodaMdbSummary([string]$Path, [string]$Group, $Result) {
    $lines = New-Object System.Collections.Generic.List[string]
    $lines.Add("Export skupiny $Group z datového souboru POHODY")
    $lines.Add('Vytvořeno: ' + (Get-Date -Format 'yyyy-MM-dd HH:mm'))
    $lines.Add('')
    if ($Result.Chybejici.Count -gt 0) {
        $lines.Add('V datovém souboru nejsou tabulky: ' + ($Result.Chybejici -join ', '))
        $lines.Add('')
    }
    $prazdne = @($Result.Pocty.Keys | Where-Object { $Result.Pocty[$_] -eq 0 })
    if ($prazdne.Count -gt 0) {
        $lines.Add('Prázdné tabulky (v datovém souboru jsou, ale nic v nich není): ' + ($prazdne -join ', '))
        $lines.Add('')
    }
    $lines.Add('Řádky po tabulkách:')
    foreach ($t in $Result.Pocty.Keys) {
        $lines.Add(("  {0,-22} {1,8}" -f $t, $Result.Pocty[$t]))
    }
    if ($Result.Bloby -and $Result.Bloby.Count -gt 0) {
        $lines.Add('Binární sloupce (přečtené atributy podání / nečitelné):')
        foreach ($b in $Result.Bloby.Keys) {
            $lines.Add(("  {0,-28} {1,8} {2,8}" -f $b, $Result.Bloby[$b].Read, $Result.Bloby[$b].Unreadable))
        }
    }
    Set-Content -LiteralPath $Path -Value $lines -Encoding UTF8
}

<#
    Pro Export-Pohoda.ps1: vytáhne všechny skupiny do složky agendy, včetně souhrnu vedle
    každého XML souboru (`<soubor>-souhrn.txt`). Vrací řádky souhrnu.
#>
function Export-PohodaMdbData([string]$Mdb, [string]$SqlServer, [string]$Databaze, [string]$Cil, [string]$Ico, [string]$Rok, [string[]]$Skupiny, [string]$Heslo) {
    $conn = Open-PohodaSource $Mdb $SqlServer $Databaze $Heslo
    if ($null -eq $conn) {
        throw (Get-MdbFailureMessage $PohodaOpenFailure 'POHODU' ([bool]$Heslo))
    }
    try {
        Export-PohodaMdbGroups $conn $Cil $Ico $Rok $Skupiny
    } finally { $conn.Close() }
}

<#
    Skupiny z už otevřeného spojení (datový soubor nebo POHODA SQL) do složky agendy
    včetně souhrnů. Vrací řádek za skupinu pro Write-PohodaMdbGroupReport.
#>
function Export-PohodaMdbGroups($Conn, [string]$Cil, [string]$Ico, [string]$Rok, [string[]]$Skupiny) {
    foreach ($g in $Skupiny) {
        $soubor = $PohodaMdbGroups[$g].Soubor
        $file = Join-Path $Cil $soubor
        $result = Export-PohodaMdbGroup $Conn $g $file $Ico $Rok
        $souhrnPath = Join-Path $Cil ($soubor -replace '\.xml$', '-souhrn.txt')
        Write-PohodaMdbSummary $souhrnPath $g $result
        [pscustomobject]@{ Skupina = $g; Soubor = $soubor; Zaznamu = $result.Zaznamu; Pocty = $result.Pocty; Chybejici = $result.Chybejici; Bloby = $result.Bloby }
    }
}

<# Výpis výsledku skupiny do konzole. #>
function Write-PohodaMdbGroupReport($Row) {
    if ($Row.Zaznamu -gt 0) {
        Write-Host ("  {0,-8} {1,-16} {2,8} záznamů" -f $Row.Skupina, $Row.Soubor, $Row.Zaznamu) -ForegroundColor Green
        foreach ($t in $Row.Pocty.Keys) {
            Write-Host ("      {0,-22} {1,8}" -f $t, $Row.Pocty[$t])
        }
        foreach ($b in $Row.Bloby.Keys) {
            Write-Host ("      {0,-28} přečteno {1,6}, nečitelné {2,6}" -f $b, $Row.Bloby[$b].Read, $Row.Bloby[$b].Unreadable)
        }
    } else {
        Write-Host ("  {0,-8} ve zdroji nic není" -f $Row.Skupina) -ForegroundColor Yellow
    }
    if ($Row.Chybejici.Count -gt 0) {
        Write-Host ("      chybí tabulky: " + ($Row.Chybejici -join ', ')) -ForegroundColor Yellow
    }
}

# --- samostatné spuštění (při načtení z Export-Pohoda.ps1 se neprovede) ---
if ($MyInvocation.InvocationName -eq '.') { return }

if ($env:OS -ne 'Windows_NT') {
    Write-Host 'Skript běží jen na Windows.' -ForegroundColor Red
    exit 1
}
if (-not $Vystup) { $Vystup = Join-Path $PSScriptRoot ('pohoda_mdb_' + (Get-Date -Format 'yyyyMMdd_HHmmss')) }
New-Item -ItemType Directory -Force $Vystup | Out-Null
$Vystup = (Resolve-Path $Vystup).Path

$ico = ''
$rok = ''
if ((Split-Path $Vystup -Leaf) -match '^(\d{6,8})_(\d{4})$') { $ico = $matches[1]; $rok = $matches[2] }
elseif ($Mdb -and ([IO.Path]::GetFileNameWithoutExtension($Mdb) -match '(\d{6,8})_(\d{4})$')) { $ico = $matches[1]; $rok = $matches[2] }

if ($Mdb) {
    if (-not $Heslo -and $env:MYUCTO_MDB_HESLO) { $Heslo = $env:MYUCTO_MDB_HESLO }
    $probe = Open-PohodaSource $Mdb $null $null $Heslo
    if ($null -eq $probe -and $PohodaOpenFailure.Failure -eq 'Password' -and -not $Heslo) {
        $Heslo = Read-MdbPassword
        if ($Heslo) { $probe = Open-PohodaSource $Mdb $null $null $Heslo }
    }
    if ($null -eq $probe -and $PohodaOpenFailure.Failure -eq 'Driver' -and [Environment]::Is64BitProcess) {
        # Ovladač Access bývá jen 32bitový (instaluje ho 32bitová POHODA) - zkusíme 32bitový PowerShell.
        # Jen když ovladač opravdu chybí; heslo nebo zamčený soubor 32 bitů nevyřeší.
        $ps32 = Join-Path $env:WINDIR 'SysWOW64\WindowsPowerShell\v1.0\powershell.exe'
        if (Test-Path -LiteralPath $ps32) {
            Write-Host 'Ovladač pro .mdb v 64bitovém PowerShellu chybí, spouštím 32bitový...'
            $q = { param($s) "'" + ($s -replace "'", "''") + "'" }
            # Heslo jde do 32bitového procesu proměnnou prostředí, ne na příkazové řádce.
            if ($Heslo) { $env:MYUCTO_MDB_HESLO = $Heslo }
            & $ps32 -NoProfile -ExecutionPolicy Bypass -Command ("& {0} -Mdb {1} -Vystup {2} -Skupiny {3}" -f (& $q $PSCommandPath), (& $q $Mdb), (& $q $Vystup), ($Skupiny -join ','))
            exit $LASTEXITCODE
        }
    }
    if ($null -eq $probe) {
        Write-Host (Get-MdbFailureMessage $PohodaOpenFailure 'POHODU' ([bool]$Heslo)) -ForegroundColor Red
        exit 1
    }
    $probe.Close()
}

if (-not $rok -and $Skupiny -contains 'mzdy') {
    Write-Host 'Rok agendy se nepodařilo určit ze složky ani z názvu datového souboru - mzdy se vytáhnou bez omezení na rok.' -ForegroundColor Yellow
}

Write-Host "Výstup: $Vystup"
foreach ($row in (Export-PohodaMdbData $Mdb $SqlServer $Databaze $Vystup $ico $rok $Skupiny $Heslo)) {
    Write-PohodaMdbGroupReport $row
}
Write-Host 'Hotovo. Soubory přidejte do ZIP exportu ke složce agendy (IČO_rok), nebo spusťte znovu Export-Pohoda.ps1.' -ForegroundColor Cyan
