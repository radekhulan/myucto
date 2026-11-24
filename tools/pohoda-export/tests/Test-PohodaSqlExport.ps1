[CmdletBinding()]
param()

# Nástroje pro POHODA SQL a PAMICA SQL bez serveru: načtení konfigurace JSON, sestavení
# spojení (heslo nikdy v textu spojení ani ve výpisu), dotazy interaktivního režimu,
# výběr databáze, desetinná čísla bez koncových nul a vynechání sloupců NullCheck_*.

$ErrorActionPreference = 'Stop'
$toolDir = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
. (Join-Path $toolDir 'Pohoda-Common.ps1')
. (Join-Path $toolDir 'PohodaSql-Common.ps1')
. (Join-Path $toolDir 'Export-PohodaMdb.ps1')
. (Join-Path $toolDir '..\pamica-export\Export-Pamica.ps1')

$testRoot = Join-Path $env:TEMP ('pohoda-sql-export-' + [Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $testRoot | Out-Null
$secret = 'Fiktivni-Heslo-' + [Guid]::NewGuid().ToString('N').Substring(0, 8)

function Assert-True([bool]$Condition, [string]$Message) {
    if (-not $Condition) { throw $Message }
}

function Get-PlainText([Security.SecureString]$Value) {
    $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($Value)
    try { return [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr) } finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr) }
}

# Odpovědi na Read-Host v pořadí dotazů; zapamatuje si texty dotazů a skryté zadání.
$script:Answers = New-Object System.Collections.Generic.Queue[object]
$script:Prompts = New-Object System.Collections.Generic.List[string]
function Read-Host {
    param([Parameter(Position = 0)][string]$Prompt, [switch]$AsSecureString)
    $script:Prompts.Add($(if ($AsSecureString) { "[skryte] $Prompt" } else { $Prompt }))
    if ($script:Answers.Count -eq 0) { throw "Neočekávaný dotaz: $Prompt" }
    $answer = [string]$script:Answers.Dequeue()
    if ($AsSecureString) { return (ConvertTo-SecureString -String $answer -AsPlainText -Force) }
    return $answer
}
function Set-Answers([object[]]$Values) {
    $script:Answers.Clear()
    $script:Prompts.Clear()
    foreach ($v in $Values) { $script:Answers.Enqueue($v) }
}

try {
    Assert-True ($PohodaAccountingTables['Uhrady'] -contains 'RefDD') 'Export úhrad musí přenášet odkaz na DDKP.'

    # --- konfigurace JSON ----------------------------------------------------
    $config = Join-Path $testRoot 'pohoda-sql.json'
    $json = [ordered]@{
        _napoveda = 'ignoruje se'
        host = 'SERVER\POHODA'
        port = 1433
        database = 'StwPh_12345678_2026'
        user = 'ctenar'
        password = $secret
        trustServerCertificate = $false
        driver = 'sqlclient'
    }
    [IO.File]::WriteAllText($config, ($json | ConvertTo-Json), (New-Object Text.UTF8Encoding($false)))
    $settings = Read-PohodaSqlConfig $config
    Assert-True ($settings.Host -eq 'SERVER\POHODA' -and $settings.Port -eq 1433 -and $settings.Database -eq 'StwPh_12345678_2026') 'Konfigurace nenačetla server, port nebo databázi.'
    Assert-True ($settings.User -eq 'ctenar' -and $settings.Password -is [Security.SecureString]) 'Heslo se nedrží jako SecureString.'
    Assert-True ((Get-PlainText $settings.Password) -eq $secret) 'Heslo z konfigurace se změnilo.'
    Assert-True (-not $settings.TrustServerCertificate -and $settings.Driver -eq 'sqlclient') 'trustServerCertificate nebo driver se nenačetl.'
    Assert-True ($settings.OdbcDriver -eq 'ODBC Driver 18 for SQL Server') 'Chybí výchozí ODBC ovladač.'

    # Vzor z balíčku jde načíst a nemá přihlašovací údaje.
    $sample = Read-PohodaSqlConfig (Join-Path $toolDir 'pohoda-sql.example.json')
    Assert-True ($sample.User -eq '' -and $null -eq $sample.Password -and $sample.Port -eq 0) 'Vzor pohoda-sql.example.json nesmí mít uživatele, heslo ani port.'
    $pamicaSample = Read-PohodaSqlConfig (Join-Path $toolDir '..\pamica-export\pamica-sql.example.json')
    Assert-True ($pamicaSample.Database -eq '' -and $pamicaSample.Driver -eq 'sqlclient') 'Vzor pamica-sql.example.json nejde načíst.'

    # Neplatný JSON: hláška nesmí obsahovat obsah souboru (heslo).
    $broken = Join-Path $testRoot 'broken.json'
    [IO.File]::WriteAllText($broken, "{ `"password`": `"$secret`", ")
    try { Read-PohodaSqlConfig $broken | Out-Null; throw 'Neplatný JSON prošel.' } catch {
        Assert-True ($_.Exception.Message -like '*není platný JSON*') "Neplatný JSON hlásí něco jiného: $($_.Exception.Message)"
        Assert-True ($_.Exception.Message -notlike "*$secret*") 'Hláška o neplatném JSON obsahuje heslo.'
    }
    $unknown = Join-Path $testRoot 'unknown.json'
    [IO.File]::WriteAllText($unknown, '{ "server": "x" }')
    try { Read-PohodaSqlConfig $unknown | Out-Null; throw 'Neznámý klíč prošel.' } catch {
        Assert-True ($_.Exception.Message -like '*neznámý klíč server*') 'Neznámý klíč se nehlásí.'
    }

    # --- spojení: SqlClient ----------------------------------------------------
    $connection = New-PohodaSqlConnection $settings 'StwPh_12345678_2026'
    try {
        $cs = [string]$connection.ConnectionString
        Assert-True ($connection -is [System.Data.SqlClient.SqlConnection]) 'Výchozí ovladač není SqlClient.'
        Assert-True ($cs -notlike "*$secret*") 'Heslo je v textu spojení SqlClient.'
        Assert-True ($null -ne $connection.Credential -and $connection.Credential.UserId -eq 'ctenar') 'SqlClient nedostal přihlášení přes SqlCredential.'
        foreach ($expected in 'Data Source=SERVER\POHODA,1433', 'Initial Catalog=StwPh_12345678_2026', 'Encrypt=True', 'TrustServerCertificate=False', 'ApplicationIntent=ReadOnly', 'Persist Security Info=False') {
            Assert-True ($cs -like "*$expected*") "V textu spojení chybí $expected."
        }
        Assert-True ($cs -notlike '*Integrated Security=True*') 'SQL přihlášení nesmí zapnout Windows autentizaci.'
    } finally { $connection.Dispose() }

    $windows = New-PohodaSqlSettings
    $windows.Host = 'SERVER'
    $connection = New-PohodaSqlConnection $windows ''
    try {
        $cs = [string]$connection.ConnectionString
        Assert-True ($cs -like '*Integrated Security=True*' -and $cs -like '*Data Source=SERVER;*' -and $null -eq $connection.Credential) 'Prázdný uživatel nepoužil Windows autentizaci bez portu.'
    } finally { $connection.Dispose() }

    # --- spojení: ODBC ---------------------------------------------------------
    $settings.Driver = 'odbc'
    $connection = New-PohodaSqlConnection $settings 'StwPh_12345678_2026'
    try {
        $cs = [string]$connection.ConnectionString
        Assert-True ($connection -is [System.Data.Odbc.OdbcConnection]) 'Driver odbc nevytvořil ODBC spojení.'
        foreach ($expected in 'Driver={ODBC Driver 18 for SQL Server}', 'Encrypt=Yes', 'TrustServerCertificate=No', 'ApplicationIntent=ReadOnly', 'UID=ctenar') {
            Assert-True ($cs -like "*$expected*") "V textu ODBC spojení chybí $expected."
        }
    } finally { $connection.Dispose() }
    $info = Get-PohodaSqlConnectionInfo $settings 'StwPh_12345678_2026'
    Assert-True ($info -notlike "*$secret*" -and $info -like '*ODBC (ODBC Driver 18 for SQL Server)*' -and $info -like '*uživatel SQL ctenar*') 'Popis spojení pro výpis je špatně nebo obsahuje heslo.'

    # --- interaktivní režim ----------------------------------------------------
    Set-Answers @('SERVER\POHODA', '', 'ctenar', $secret, '')
    $asked = Complete-PohodaSqlSettings (New-PohodaSqlSettings)
    Assert-True ($script:Prompts.Count -eq 5) "Bez konfigurace se má skript zeptat pětkrát, zeptal se $($script:Prompts.Count)krát."
    Assert-True ($script:Prompts[0] -like 'SQL server*' -and $script:Prompts[1] -like 'Port*' -and $script:Prompts[2] -like 'Uživatel SQL*') 'Dotazy na server, port a uživatele chybí.'
    Assert-True ($script:Prompts[3] -like '`[skryte`] Heslo*') 'Heslo se nezadává skrytě.'
    Assert-True ($asked.Host -eq 'SERVER\POHODA' -and $asked.Port -eq 0 -and $asked.User -eq 'ctenar' -and $asked.TrustServerCertificate) 'Odpovědi se nepromítly do nastavení (prázdný port a výchozí důvěra certifikátu).'
    Assert-True ((Get-PlainText $asked.Password) -eq $secret) 'Skryté heslo se ztratilo.'

    # Konfigurace s Windows přihlášením: nic se neptá.
    Set-Answers @()
    $noUser = Join-Path $testRoot 'windows.json'
    [IO.File]::WriteAllText($noUser, '{ "host": "SERVER", "port": "", "database": "", "user": "", "trustServerCertificate": true }')
    $quiet = Complete-PohodaSqlSettings (Read-PohodaSqlConfig $noUser)
    Assert-True ($script:Prompts.Count -eq 0 -and $quiet.User -eq '' -and $null -eq $quiet.Password) 'Úplná konfigurace s Windows přihlášením se nemá na nic ptát.'

    # --- výběr databáze ----------------------------------------------------------
    $agendas = @(
        [pscustomobject]@{ Name = 'StwPh_12345678_2026'; Ico = '12345678'; Year = 2026; Company = 'Vzorová s.r.o.' },
        [pscustomobject]@{ Name = 'StwPh_12345678_2025'; Ico = '12345678'; Year = 2025; Company = 'Vzorová s.r.o.' },
        [pscustomobject]@{ Name = 'StwPh_87654321_2026'; Ico = '87654321'; Year = 2026; Company = '' }
    )
    Set-Answers @()
    Assert-True ((Select-PohodaSqlDatabase $agendas '12345678' 2025) -eq 'StwPh_12345678_2025' -and $script:Prompts.Count -eq 0) '-Ico a -Rok mají vybrat agendu bez dotazu.'
    Set-Answers @('2')
    $picked = Select-PohodaSqlDatabase $agendas '' 2026 6>$null
    Assert-True ($picked -eq 'StwPh_87654321_2026' -and $script:Prompts.Count -eq 1) 'Výběr číslem ze seznamu nefunguje.'
    try { Select-PohodaSqlDatabase $agendas '11111111' 0 | Out-Null; throw 'Neexistující IČO prošlo.' } catch {
        Assert-True ($_.Exception.Message -like '*IČO 11111111*') 'Chybí hláška o nenalezené agendě.'
    }

    # --- hodnoty: desetinná čísla a vypočtené sloupce ---------------------------
    Assert-True ((ConvertTo-PohodaXmlText ([decimal]'100.0000')) -eq '100') 'money 100.0000 se nezkrátilo na 100.'
    Assert-True ((ConvertTo-PohodaXmlText ([decimal]'-12.3400')) -eq '-12.34') 'Desetinné číslo ztratilo platné místo.'
    Assert-True ((ConvertTo-PohodaXmlText ([decimal]'0.0000')) -eq '0') 'Nula se nezkrátila.'
    $sw = New-Object IO.StringWriter
    $xw = [Xml.XmlWriter]::Create($sw, (New-Object Xml.XmlWriterSettings -Property @{ OmitXmlDeclaration = $true; ConformanceLevel = 'Fragment' }))
    Write-PohodaValue $xw 'Kc' ([decimal]'1250.5000')
    Write-PamicaValue $xw 'KcP' ([decimal]'80.0000')
    $xw.Flush()
    Assert-True ($sw.ToString() -eq '<Kc>1250.5</Kc><KcP>80</KcP>') "Skupiny majetek/mzdy zapisují desetinná čísla jinak: $($sw.ToString())"
    foreach ($skip in 'Test-PohodaSkipColumn', 'Test-PamicaSkipColumn') {
        Assert-True (& $skip 'NullCheck_Cislo') "$skip nevynechává vypočtený sloupec NullCheck_*."
        Assert-True (-not (& $skip 'Cislo')) "$skip vynechává běžný sloupec."
    }

    Write-Output 'POHODA_SQL_EXPORT_OK'
} finally {
    if (Test-Path -LiteralPath $testRoot) {
        $resolvedTemp = [IO.Path]::GetFullPath($env:TEMP).TrimEnd('\') + '\'
        $resolvedTestRoot = [IO.Path]::GetFullPath($testRoot)
        if (-not $resolvedTestRoot.StartsWith($resolvedTemp, [StringComparison]::OrdinalIgnoreCase)) {
            throw "Refusing to remove unexpected test path $resolvedTestRoot."
        }
        Remove-Item -LiteralPath $testRoot -Recurse -Force -ErrorAction SilentlyContinue
    }
}
