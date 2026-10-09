<#
.SYNOPSIS
    Vytáhne mzdy z PAMICA SQL (Microsoft SQL Server) do ZIPu pro převod do MyÚčta.

.DESCRIPTION
    Výstup je stejný jako u datového souboru (Export-Pamica.ps1), jen zdrojem je databáze
    na SQL Serveru: ZIP pamica_export_<datum>.zip se složkou <IČO>_<rok>\91_mzdy.xml za
    každý rok a souhrn vedle něj. Nahrajte ho do průvodce Přechod z PAMICA.

    Skript posílá jen dotazy SELECT přes spojení jen pro čtení (ApplicationIntent=ReadOnly,
    šifrované). Stačí přihlašovací jméno s rolí db_datareader v mzdové databázi.

    Připojení se bere z konfiguračního souboru JSON (parametr -Config, jinak soubor
    pamica-sql.json vedle skriptu, pokud existuje). Vzor je pamica-sql.example.json,
    klíče host, port, database, user, password, trustServerCertificate, driver
    (sqlclient nebo odbc) a odbcDriver popisuje PohodaSql-Common.ps1. Na chybějící klíče
    se skript zeptá, heslo skrytě. Bez zadané databáze nabídne ze seznamu databáze
    na serveru, které mají zaměstnance, pracovní poměry a zpracované mzdy (tabulky ZAM,
    ZAMpomer a MZ); rozhoduje obsah, ne název databáze.

    Požadavky: Windows PowerShell 5.1 (součást Windows 10 a 11) nebo PowerShell 7 a přístup
    na SQL Server. Ovladač SqlClient je součástí Windows; Microsoft ODBC Driver for SQL
    Server je potřeba jen při volbě driver odbc. Skript potřebuje vedle sebe
    Export-Pamica.ps1 a PohodaSql-Common.ps1.

.PARAMETER Config
    Konfigurační soubor JSON s připojením. Bez zadání pamica-sql.json vedle skriptu, nebo dotazy.

.PARAMETER Databaze
    Mzdová databáze. Přebije klíč database z konfigurace.

.PARAMETER Rok
    Roky k vytažení. Bez zadání všechny roky, které jsou ve zpracovaných mzdách.

.PARAMETER Ico
    IČO firmy pro název složky. Bez zadání se bere z databáze.

.PARAMETER Vystup
    Složka, kam se zapíše ZIP a souhrn. Bez zadání složka vedle skriptu.

.EXAMPLE
    .\Export-PamicaSQL.ps1 -Config .\pamica-sql.json

.EXAMPLE
    .\Export-PamicaSQL.ps1 -Databaze StwPh_12345678_2026 -Rok 2026
#>
[CmdletBinding()]
param(
    [string]$Config,
    [string]$Databaze,
    [string[]]$Rok,
    [string]$Ico,
    [string]$Vystup
)

$ErrorActionPreference = 'Stop'

# Z .cmd přes -File přijde "2025,2026" jako jeden řetězec ([int[]] z něj dělal 20252026).
$Rok = @($Rok | ForEach-Object { "$_" -split '[,;\s]+' } | Where-Object { $_ -ne '' } | ForEach-Object { [int]$_ })

if ($env:OS -ne 'Windows_NT') {
    Write-Host 'Skript běží jen na Windows.' -ForegroundColor Red
    exit 1
}

$library = Join-Path $PSScriptRoot 'Export-Pamica.ps1'
# Ve staženém balíčku leží PohodaSql-Common.ps1 vedle skriptu, v repozitáři v pohoda-export.
$sqlCommon = @(
    (Join-Path $PSScriptRoot 'PohodaSql-Common.ps1'),
    (Join-Path $PSScriptRoot '..\pohoda-export\PohodaSql-Common.ps1')
) | Where-Object { Test-Path -LiteralPath $_ -PathType Leaf } | Select-Object -First 1
if (-not (Test-Path -LiteralPath $library -PathType Leaf) -or -not $sqlCommon) {
    Write-Host 'Chybí Export-Pamica.ps1 nebo PohodaSql-Common.ps1. Stáhněte a rozbalte celý ZIP nástroje, aby všechny skripty ležely ve stejné složce.' -ForegroundColor Red
    exit 1
}
. $sqlCommon
# Export-Pamica.ps1 má vlastní parametry; tečkové načtení je naváže v tomto skriptu,
# proto se mu předají hodnoty -Rok, -Ico a -Vystup, jinak by je vynulovalo.
. $library -Rok $Rok -Ico $Ico -Vystup $Vystup

if (-not $Config) {
    $defaultConfig = Join-Path $PSScriptRoot 'pamica-sql.json'
    if (Test-Path -LiteralPath $defaultConfig -PathType Leaf) {
        $Config = $defaultConfig
    }
}

$connection = $null
try {
    if ($Config) {
        Write-Host "Nastavení připojení: $Config"
        $settings = Read-PohodaSqlConfig $Config
    } else {
        Write-Host 'Konfigurační soubor pamica-sql.json není, zadejte připojení k SQL Serveru.'
        $settings = New-PohodaSqlSettings
    }
    $settings = Complete-PohodaSqlSettings $settings
    if ($Databaze) {
        $settings.Database = $Databaze.Trim()
    }

    if (-not $Vystup) { $Vystup = $PSScriptRoot }
    New-Item -ItemType Directory -Force $Vystup | Out-Null
    $Vystup = (Resolve-Path -LiteralPath $Vystup).Path

    $connection = Open-PohodaSqlConnection $settings $settings.Database
    if (-not $settings.Database) {
        $candidates = Get-PohodaSqlPayrollDatabases $connection
        $settings.Database = Select-PohodaSqlDatabase $candidates '' 0 'mzdovou databázi PAMICA (tabulky ZAM, ZAMpomer a MZ se zpracovanými mzdami)'
        $connection.ChangeDatabase($settings.Database)
    }
    Write-Host "Připojeno: $(Get-PohodaSqlConnectionInfo $settings $settings.Database)"

    $null = Invoke-PamicaExport $connection "databázi $($settings.Database)" "Databáze SQL: $($settings.Database)" $Ico $Rok $Vystup
} catch {
    # Uživateli stačí věta, co je špatně; výpis volání by ho jen zmátl.
    Write-Host ''
    Write-Host $_.Exception.Message -ForegroundColor Red
    exit 1
} finally {
    if ($connection) {
        $connection.Close()
        $connection.Dispose()
    }
}
