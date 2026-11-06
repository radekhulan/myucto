<#
.SYNOPSIS
    Vyexportuje účetní data z datového souboru POHODY do proudového XML pro převod do MyÚčta.

.DESCRIPTION
    Skript čte pouze pevně povolené tabulky z databáze POHODY přes Microsoft ACE/Jet OLE DB.
    Zdrojový soubor nijak nemění. Výsledek zapisuje proudově do dočasného souboru a pod
    konečným názvem ho zveřejní až po úspěšném dokončení všech tabulek.

    Výstup:
      <Vystup>\<Ico>_<Rok>\89_ucetnictvi_mdb.xml

    Pro každou povolenou tabulku vznikne element <table>. Tabulka, která v konkrétní verzi
    POHODY neexistuje, se zapíše s atributem missing="true". Binární sloupce se neexportují.

    Zápis je společný s Export-PohodaSQL.ps1 (Pohoda-Common.ps1), výstup z MDB i POHODA SQL
    je stejný.

.PARAMETER Mdb
    Datový soubor účetní agendy POHODY (.mdb). Bez zadání se otevře výběr souboru.

.PARAMETER Vystup
    Kořenová výstupní složka. Skript v ní vytvoří složku <Ico>_<Rok>. Bez zadání
    se použije složka exportního nástroje.

.PARAMETER Ico
    Volitelná kontrola IČO účetní jednotky, 6 až 8 číslic. Skutečné IČO se vždy
    načte z tabulky sKonfig a odlišná hodnota export zastaví.

.PARAMETER Rok
    Volitelná kontrola účetního roku. Skutečný rok se vždy načte z tabulky sKonfig
    a odlišná hodnota export zastaví.

.PARAMETER BezZip
    Nevytvářet ZIP archiv. Výchozí chování je vytvořit ZIP vedle složky agendy.

.PARAMETER Heslo
    Heslo databáze, je-li datový soubor jím chráněný. Bez zadání se na něj skript
    zeptá, když ho datový soubor vyžaduje.

.EXAMPLE
    .\Export-PohodaMdbAccounting.ps1 -Mdb "C:\POHODA\Data\StwPh_12345678_2026.mdb" -Vystup .\export -Ico 12345678 -Rok 2026
#>
[CmdletBinding()]
param(
    [string]$Mdb,
    [string]$Vystup,
    [string]$Ico,
    [int]$Rok,
    [switch]$BezZip,
    [switch]$PotvrditMetadata,
    [string]$Heslo
)

$ErrorActionPreference = 'Stop'
$icoWasSpecified = $PSBoundParameters.ContainsKey('Ico') -and -not [string]::IsNullOrWhiteSpace($Ico)
$yearWasSpecified = $PSBoundParameters.ContainsKey('Rok')

<#
    Otevře datový soubor Accessu (.mdb) jen pro čtení, ovladače zkouší od nejnovějšího.
    Vrací Connection, nebo při neúspěchu Failure s důvodem a Detail s hláškami ovladačů:
      Driver    žádný ovladač Accessu v této bitovosti PowerShellu není registrovaný
      Password  soubor je chráněný heslem databáze (heslo chybí, nebo nesouhlasí)
      Locked    soubor drží výhradně jiný program, nebo chybí právo ke čtení
      Format    soubor není databáze Accessu, nebo ji ovladač nezná
      Other     jiná chyba
    Shodné s Export-PohodaMdb.ps1 a Export-Pamica.ps1 - při změně upravte všechny tři.
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


function Select-PohodaMdb {
    Add-Type -AssemblyName System.Windows.Forms
    $dialog = New-Object System.Windows.Forms.OpenFileDialog
    $dialog.Title = 'Vyberte datový soubor účetní agendy POHODY'
    $dialog.Filter = 'Datový soubor POHODY (*.mdb)|*.mdb|Všechny soubory (*.*)|*.*'
    $dialog.Multiselect = $false
    try {
        if ($dialog.ShowDialog() -ne [System.Windows.Forms.DialogResult]::OK) {
            throw 'Nebyl vybrán žádný datový soubor.'
        }
        return $dialog.FileName
    } finally {
        $dialog.Dispose()
    }
}

if ($env:OS -ne 'Windows_NT') {
    throw 'Skript běží jen na Windows.'
}

foreach ($required in 'Pohoda-Common.ps1', 'Export-PohodaMdb.ps1') {
    if (-not (Test-Path -LiteralPath (Join-Path $PSScriptRoot $required) -PathType Leaf)) {
        throw "Chybí $required. Stáhněte a rozbalte celý ZIP převodníku, aby všechny skripty ležely ve stejné složce."
    }
}
. (Join-Path $PSScriptRoot 'Pohoda-Common.ps1')
$companion = Join-Path $PSScriptRoot 'Export-PohodaMdb.ps1'

$interactive = [string]::IsNullOrWhiteSpace($Mdb)
if ($interactive) {
    $Mdb = Select-PohodaMdb
}
$mdbFile = Get-Item -LiteralPath $Mdb -ErrorAction Stop
if ($mdbFile.PSIsContainer) {
    throw "Cesta $Mdb není datový soubor."
}
[string]$mdbPath = [string]$mdbFile.FullName

$Ico = ConvertTo-PohodaAgendaIco $(if ($icoWasSpecified) { $Ico } else { '' })
if ($yearWasSpecified) {
    Assert-PohodaAgendaYear $Rok
}
if ([string]::IsNullOrWhiteSpace($Vystup)) {
    $Vystup = $PSScriptRoot
}

if (-not $Heslo -and $env:MYUCTO_MDB_HESLO) { $Heslo = $env:MYUCTO_MDB_HESLO }
$opened = Open-MdbConnection $mdbPath $Heslo
if ($null -eq $opened.Connection -and $opened.Failure -eq 'Password' -and -not $Heslo) {
    $Heslo = Read-MdbPassword
    if ($Heslo) { $opened = Open-MdbConnection $mdbPath $Heslo }
}
$connection = $opened.Connection
# 32bitový PowerShell jen když ovladač opravdu chybí; heslo nebo zamčený soubor 32 bitů nevyřeší.
if ($null -eq $connection -and $opened.Failure -eq 'Driver' -and [Environment]::Is64BitProcess) {
    $powershell32 = Join-Path $env:WINDIR 'SysWOW64\WindowsPowerShell\v1.0\powershell.exe'
    if (-not (Test-Path -LiteralPath $powershell32)) {
        throw 'Datový soubor nejde otevřít a 32bitový Windows PowerShell není dostupný.'
    }
    Write-Host 'Ovladač pro MDB v 64bitovém PowerShellu není dostupný, spouštím 32bitový PowerShell.'
    # Heslo jde do 32bitového procesu proměnnou prostředí, ne na příkazové řádce.
    if ($Heslo) { $env:MYUCTO_MDB_HESLO = $Heslo }
    $arguments = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $PSCommandPath, '-Mdb', $mdbPath, '-Vystup', $Vystup)
    if ($icoWasSpecified) {
        $arguments += @('-Ico', $Ico)
    }
    if ($yearWasSpecified) {
        $arguments += @('-Rok', [string]$Rok)
    }
    if ($interactive -or $PotvrditMetadata) {
        $arguments += '-PotvrditMetadata'
    }
    if ($BezZip) {
        $arguments += '-BezZip'
    }
    & $powershell32 @arguments
    exit $LASTEXITCODE
}
if ($null -eq $connection) {
    $message = Get-MdbFailureMessage $opened 'POHODU' ([bool]$Heslo)
    if ($opened.Failure -eq 'Driver') {
        $message = 'Nainstalujte Microsoft 365 Access Runtime s ACE OLE DB/ODBC ve stejné architektuře jako Microsoft Office: https://support.microsoft.com/en-us/access/download-and-install-microsoft-365-access-runtime' + [Environment]::NewLine + $message
    }
    throw $message
}

try {
    # Majetek, mzdy a sklad čte Export-PohodaMdb.ps1 vlastním spojením, proto se účetní spojení
    # před jeho spuštěním zavře.
    $null = Invoke-PohodaAccountingExport -Connection $connection -Vystup $Vystup -Ico $Ico `
        -Rok $(if ($yearWasSpecified) { $Rok } else { 0 }) -Potvrdit ($interactive -or $PotvrditMetadata) -BezZip $BezZip `
        -Doplnky {
            param($agendaDir)
            $connection.Close()
            & $companion -Mdb $mdbPath -Vystup $agendaDir -Skupiny @('majetek', 'mzdy', 'sklad') -Heslo $Heslo
        }
} finally {
    $connection.Close()
    $connection.Dispose()
}
