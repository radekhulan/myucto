<#
.SYNOPSIS
    Vyexportuje z programu POHODA všechny agendy účetní jednotky do XML (jedna agenda = jeden soubor).

.DESCRIPTION
    Skript používá oficiální XML rozhraní POHODY (automatické zpracování z příkazového řádku,
    Pohoda.exe /XML). Pro každou agendu připraví exportní požadavek, nechá ho POHODU zpracovat
    a odpověď uloží do samostatného souboru. Nakonec vše zabalí do jednoho ZIP archivu.

    Nic neimportuje ani nemění, data v POHODĚ zůstanou beze změny.

    Postup:
      1. Zjistí seznam účetních jednotek (rok, IČO, datový soubor).
      2. Pro vybrané IČO a roky vyexportuje každou agendu zvlášť.
      3. Zapíše přehled (souhrn.csv, souhrn.txt) a vytvoří ZIP.

    Požadavky:
      - POHODA nainstalovaná na tomto počítači, uživatel s právem na XML import/export
        (nejjednodušší je účet Admin).
      - Knihovna MSXML6 (je součástí Windows).

.PARAMETER Uzivatel
    Přihlašovací jméno do POHODY (např. Admin).

.PARAMETER Heslo
    Heslo do POHODY. Když ho nezadáte, skript se zeptá.

.PARAMETER Ico
    IČO účetní jednotky. Povinné jen tehdy, když je v POHODĚ více firem.

.PARAMETER Rok
    Rok (nebo více roků oddělených čárkou), který se má exportovat. Bez zadání se vyexportují
    všechny roky dané firmy.

.PARAMETER PohodaExe
    Cesta k Pohoda.exe. Bez zadání ji skript najde sám.

.PARAMETER Vystup
    Složka pro výsledek. Bez zadání vznikne vedle skriptu složka pohoda_export_<datum>.

.PARAMETER TimeoutMinut
    Jak dlouho nejvýš čekat na export jedné agendy (výchozí 60 minut).

.EXAMPLE
    .\Export-Pohoda.ps1 -Uzivatel Admin -Rok 2026

.EXAMPLE
    .\Export-Pohoda.ps1 -Uzivatel Admin -Ico 12345678
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string]$Uzivatel,
    [string]$Heslo,
    [string]$Ico,
    [int[]]$Rok,
    [string]$PohodaExe,
    [string]$Vystup,
    [int]$TimeoutMinut = 60
)

$ErrorActionPreference = 'Stop'

if ($env:OS -ne 'Windows_NT') {
    Write-Host 'Skript běží jen na Windows, na stejném počítači, kde je nainstalovaná POHODA.' -ForegroundColor Red
    exit 1
}
if ($PSVersionTable.PSVersion -lt [version]'5.1') {
    Write-Host "Skript potřebuje Windows PowerShell 5.1 nebo novější, tento počítač má verzi $($PSVersionTable.PSVersion)." -ForegroundColor Red
    Write-Host 'Windows 10 a 11 ho mají v základu. Na Windows 7 / 8.1 nainstalujte Windows Management Framework 5.1:' -ForegroundColor Red
    Write-Host 'https://www.microsoft.com/download/details.aspx?id=54616' -ForegroundColor Red
    exit 1
}

try { [System.Text.Encoding]::RegisterProvider([System.Text.CodePagesEncodingProvider]::Instance) } catch { }
$Win1250 = [System.Text.Encoding]::GetEncoding(1250)

$NsUri = @{
    dat  = 'http://www.stormware.cz/schema/version_2/data.xsd'
    lst  = 'http://www.stormware.cz/schema/version_2/list.xsd'
    ftr  = 'http://www.stormware.cz/schema/version_2/filter.xsd'
    typ  = 'http://www.stormware.cz/schema/version_2/type.xsd'
    lAdb = 'http://www.stormware.cz/schema/version_2/list_addBook.xsd'
    lStk = 'http://www.stormware.cz/schema/version_2/list_stock.xsd'
    lCon = 'http://www.stormware.cz/schema/version_2/list_contract.xsd'
    lCen = 'http://www.stormware.cz/schema/version_2/list_centre.xsd'
    lAcv = 'http://www.stormware.cz/schema/version_2/list_activity.xsd'
    acu  = 'http://www.stormware.cz/schema/version_2/accountingunit.xsd'
}

# Doklady včetně záložek Doklady, Dokumenty, Volitelné parametry a Likvidace (úhrady).
$Restrict = '<lst:restrictionData><lst:documents>true</lst:documents><lst:attachments>true</lst:attachments><lst:parameters>true</lst:parameters><lst:liquidations>true</lst:liquidations></lst:restrictionData>'

function New-Doc([string]$Soubor, [string]$Popis, [string]$Element, [string]$Attrs, [string]$Request) {
    [pscustomobject]@{ Soubor = $Soubor; Popis = $Popis; Xml = "<lst:$Element version=`"2.0`" $Attrs><lst:$Request/>$Restrict</lst:$Element>" }
}
function New-List([string]$Soubor, [string]$Popis, [string[]]$Xml) {
    [pscustomobject]@{ Soubor = $Soubor; Popis = $Popis; Xml = $Xml }
}
function New-Simple([string]$Soubor, [string]$Popis, [string]$Element, [string]$VersionAttr) {
    $request = 'request' + ($Element -replace '^list', '' -replace 'Request$', '')
    New-List $Soubor $Popis "<lst:$Element version=`"2.0`" $VersionAttr=`"2.0`"><lst:$request/></lst:$Element>"
}

$InvoiceTypes = [ordered]@{
    issuedInvoice           = 'Vydané faktury'
    issuedCreditNotice      = 'Vydané dobropisy'
    issuedDebitNote         = 'Vydané vrubopisy'
    issuedAdvanceInvoice    = 'Vydané zálohové faktury'
    issuedProformaInvoice   = 'Vydané proforma faktury'
    issuedCorrectiveTax     = 'Vydané opravné daňové doklady'
    receivable              = 'Ostatní pohledávky'
    penalty                 = 'Penále'
    receivedInvoice         = 'Přijaté faktury'
    receivedCreditNotice    = 'Přijaté dobropisy'
    receivedDebitNote       = 'Přijaté vrubopisy'
    receivedAdvanceInvoice  = 'Přijaté zálohové faktury'
    receivedProformaInvoice = 'Přijaté proforma faktury'
    receivedCorrectiveTax   = 'Přijaté opravné daňové doklady'
    commitment              = 'Ostatní závazky'
}

$Agendy = New-Object System.Collections.Generic.List[object]

# Účetnictví
$Agendy.Add((New-Doc 'ucetni_denik' 'Účetní deník (podvojné účetnictví)' 'listAccountancyRequest' 'accountancyVersion="2.0"' 'requestAccountancy'))
$Agendy.Add((New-List 'uctova_osnova' 'Účtová osnova' '<lst:listAccountRequest version="1.1"/>'))
$Agendy.Add((New-List 'predkontace_pu' 'Předkontace (podvojné účetnictví)' '<lst:listAccountingDoubleEntryRequest version="1.1"/>'))
$Agendy.Add((New-List 'predkontace_de' 'Předkontace (daňová evidence)' '<lst:listAccountingSingleEntryRequest version="1.1"/>'))
$Agendy.Add((New-List 'saldo' 'Saldo (nespárované i spárované záznamy)' '<lst:listBalanceRequest version="1.0" balanceVersion="1.0"><lst:requestBalance><lst:groupByDoc>false</lst:groupByDoc><lst:removeBalancedRec>false</lst:removeBalancedRec><lst:pairing>PairingSymbol</lst:pairing></lst:requestBalance></lst:listBalanceRequest>'))
$Agendy.Add((New-Simple 'cleneni_dph' 'Členění DPH' 'listClassificationVATRequest' 'classificationVATVersion'))
$Agendy.Add((New-Simple 'ciselne_rady' 'Číselné řady' 'listNumericalSeriesRequest' 'numericalSeriesVersion'))
$Agendy.Add((New-Simple 'formy_uhrady' 'Formy úhrady' 'listPaymentRequest' 'paymentVersion'))
$Agendy.Add((New-Simple 'ucetni_formy_uhrady' 'Účetní formy úhrady' 'listAccountingFormOfPaymentRequest' 'accountingFormOfPaymentVersion'))
$Agendy.Add((New-Simple 'pravidla_parovani' 'Pravidla párování plateb' 'listRulesPairingRequest' 'rulesPairingVersion'))
$Agendy.Add((New-Simple 'globalni_nastaveni' 'Globální nastavení' 'listGlobalSettingsRequest' 'globalSettingsVersion'))

# Doklady
foreach ($t in $InvoiceTypes.Keys) {
    $Agendy.Add((New-Doc ('faktury_' + $t) $InvoiceTypes[$t] 'listInvoiceRequest' "invoiceType=`"$t`" invoiceVersion=`"2.0`"" 'requestInvoice'))
}
$Agendy.Add((New-Doc 'interni_doklady' 'Interní doklady' 'listIntDocRequest' 'intDocVersion="2.0"' 'requestIntDoc'))
$Agendy.Add((New-Doc 'pokladna' 'Pokladní doklady' 'listVoucherRequest' 'voucherVersion="2.0"' 'requestVoucher'))
$Agendy.Add((New-Doc 'banka' 'Bankovní doklady' 'listBankRequest' 'bankVersion="2.0"' 'requestBank'))

# Adresář a číselníky
$Agendy.Add((New-List 'adresar' 'Adresář' '<lAdb:listAddressBookRequest version="2.0" addressBookVersion="2.0"><lAdb:requestAddressBook/></lAdb:listAddressBookRequest>'))
$Agendy.Add((New-Simple 'bankovni_ucty' 'Bankovní účty' 'listBankAccountRequest' 'bankAccountVersion'))
$Agendy.Add((New-Simple 'pokladny' 'Hotovostní pokladny' 'listCashRegisterRequest' 'cashRegisterVersion'))
$Agendy.Add((New-List 'strediska' 'Střediska' '<lCen:listCentreRequest version="2.0" centreVersion="2.0"><lCen:requestCentre/></lCen:listCentreRequest>'))
$Agendy.Add((New-List 'cinnosti' 'Činnosti' '<lAcv:listActivityRequest version="2.0" activityVersion="2.0"><lAcv:requestActivity/></lAcv:listActivityRequest>'))
$Agendy.Add((New-List 'zakazky' 'Zakázky' '<lCon:listContractRequest version="2.0" contractVersion="2.0"><lCon:requestContract/></lCon:listContractRequest>'))
$Agendy.Add((New-Simple 'provozovny' 'Provozovny' 'listEstablishmentRequest' 'establishmentVersion'))
$ParamAgendy = 'adresar', 'faktury', 'pokladna', 'banka', 'interni', 'ucetniDenik', 'penezniDenik', 'osnova', 'majetek', 'drobnyMajetek', 'casoveRozliseni', 'zakazky', 'strediska', 'cinnosti', 'objednavky', 'nabidky', 'zasoby'
$Agendy.Add((New-List 'volitelne_parametry' 'Volitelné parametry' ($ParamAgendy | ForEach-Object { "<lst:listParameterRequest version=`"2.0`" parameterVersion=`"2.0`"><lst:requestParameter idsAgenda=`"$_`"/></lst:listParameterRequest>" })))
$Agendy.Add((New-List 'uzivatelske_seznamy' 'Uživatelské seznamy' '<lst:listUserCodeRequest version="1.1" listVersion="1.1"/>'))
$Agendy.Add((New-Simple 'gdpr' 'GDPR' 'listGDPRRequest' 'GDPRVersion'))

# Obchod
$Agendy.Add((New-Doc 'objednavky_vydane' 'Vydané objednávky' 'listOrderRequest' 'orderType="issuedOrder" orderVersion="2.0"' 'requestOrder'))
$Agendy.Add((New-Doc 'objednavky_prijate' 'Přijaté objednávky' 'listOrderRequest' 'orderType="receivedOrder" orderVersion="2.0"' 'requestOrder'))
$Agendy.Add((New-Doc 'nabidky_vydane' 'Vydané nabídky' 'listOfferRequest' 'offerType="issuedOffer" offerVersion="2.0"' 'requestOffer'))
$Agendy.Add((New-Doc 'nabidky_prijate' 'Přijaté nabídky' 'listOfferRequest' 'offerType="receivedOffer" offerVersion="2.0"' 'requestOffer'))
$Agendy.Add((New-Doc 'poptavky_vydane' 'Vydané poptávky' 'listEnquiryRequest' 'enquiryType="issuedEnquiry" enquiryVersion="2.0"' 'requestEnquiry'))
$Agendy.Add((New-Doc 'poptavky_prijate' 'Přijaté poptávky' 'listEnquiryRequest' 'enquiryType="receivedEnquiry" enquiryVersion="2.0"' 'requestEnquiry'))

# Sklad
$Agendy.Add((New-List 'zasoby' 'Zásoby' '<lStk:listStockRequest version="2.0" stockVersion="2.0"><lStk:requestStock/></lStk:listStockRequest>'))
$Agendy.Add((New-Simple 'sklady' 'Sklady' 'listStoreRequest' 'storeVersion'))
$Agendy.Add((New-List 'cleneni_skladu' 'Členění skladů' '<lst:listStorageRequest version="1.0"/>'))
$Agendy.Add((New-Simple 'skupiny_zasob' 'Skupiny zásob' 'listGroupStocksRequest' 'groupStocksVersion'))
$Agendy.Add((New-Simple 'dodavatele_zasob' 'Dodavatelé zásob' 'listSupplierRequest' 'supplierVersion'))
$Agendy.Add((New-List 'prodejni_ceny' 'Prodejní ceny' '<lst:listSellingPriceRequest version="1.0"/>'))
$Agendy.Add((New-Simple 'individualni_ceny' 'Individuální ceny' 'listIndividualPriceRequest' 'individualPriceVersion'))
$Agendy.Add((New-Simple 'akcni_ceny' 'Akční ceny' 'listActionPriceRequest' 'actionPricesVersion'))
$Agendy.Add((New-Simple 'merne_jednotky' 'Měrné jednotky' 'listMeasureUnitRequest' 'measureUnitVersion'))
$Agendy.Add((New-Simple 'recyklacni_poplatky' 'Recyklační poplatky' 'listRecyclingContribRequest' 'recyclingContribVersion'))
$Agendy.Add((New-Doc 'prijemky' 'Příjemky' 'listPrijemkaRequest' 'prijemkaVersion="2.0"' 'requestPrijemka'))
$Agendy.Add((New-Doc 'vydejky' 'Výdejky' 'listVydejkaRequest' 'vydejkaVersion="2.0"' 'requestVydejka'))
$Agendy.Add((New-Doc 'prodejky' 'Prodejky' 'listProdejkaRequest' 'prodejkaVersion="2.0"' 'requestProdejka'))
$Agendy.Add((New-Doc 'prevodky' 'Převodky' 'listPrevodkaRequest' 'prevodkaVersion="2.0"' 'requestPrevodka'))
$Agendy.Add((New-Doc 'vyroba' 'Výroba' 'listVyrobaRequest' 'vyrobaVersion="2.0"' 'requestVyroba'))
$Agendy.Add((New-Doc 'vyrobni_pozadavky' 'Výrobní požadavky' 'listProductRequirementRequest' 'productRequirementVersion="2.0"' 'requestProductRequirement'))
$Agendy.Add((New-Doc 'vyrobni_cisla' 'Výrobní čísla' 'listRegistrationNumberRequest' 'registrationNumberVersion="2.0"' 'requestRegistrationNumber'))
$Agendy.Add((New-Simple 'pohyby' 'Pohyby zásob' 'listMovementRequest' 'movementVersion'))
$Agendy.Add((New-Simple 'inventurni_seznamy' 'Inventurní seznamy' 'listInventoryListsRequest' 'inventoryListsVersion'))
$Agendy.Add((New-List 'kategorie_eshop' 'Kategorie internetového obchodu' '<lst:listCategoryRequest version="2.0" categoryVersion="2.0"><lst:requestCategory/></lst:listCategoryRequest>'))
$Agendy.Add((New-List 'parametry_eshop' 'Parametry internetového obchodu' '<lst:listIntParamRequest version="2.0"><lst:requestIntParam/></lst:listIntParamRequest>'))

# Servis
$Agendy.Add((New-Doc 'servis' 'Servis' 'listServiceRequest' 'serviceVersion="2.0"' 'requestService'))
$Agendy.Add((New-Doc 'reklamace' 'Reklamace' 'listClaimRequest' 'claimVersion="2.0"' 'requestClaim'))

function Find-PohodaExe {
    $candidates = New-Object System.Collections.Generic.List[string]
    foreach ($root in @(${env:ProgramFiles(x86)}, $env:ProgramFiles, 'C:\')) {
        if (-not $root) { continue }
        foreach ($sub in @('STORMWARE\POHODA', 'STORMWARE\POHODA SQL', 'STORMWARE\POHODA E1', 'POHODA')) {
            $candidates.Add((Join-Path (Join-Path $root $sub) 'Pohoda.exe'))
        }
        $stw = Join-Path $root 'STORMWARE'
        if (Test-Path $stw) {
            Get-ChildItem $stw -Filter 'Pohoda.exe' -Recurse -Depth 2 -ErrorAction SilentlyContinue | ForEach-Object { $candidates.Add($_.FullName) }
        }
    }
    foreach ($c in $candidates) { if (Test-Path $c) { return $c } }
    return $null
}

function Write-Win1250([string]$Path, [string]$Text) {
    [System.IO.File]::WriteAllText($Path, $Text, $Win1250)
}

function New-DataPack([string]$Id, [string]$DataPackIco, [string]$Note, [string[]]$ItemXml) {
    $xmlns = ($NsUri.GetEnumerator() | Sort-Object Name | ForEach-Object { "xmlns:$($_.Name)=`"$($_.Value)`"" }) -join ' '
    $k = 0
    $items = foreach ($x in $ItemXml) {
        $k++
        "<dat:dataPackItem version=`"2.0`" id=`"$Id-$k`">`r`n$x`r`n</dat:dataPackItem>"
    }
    @"
<?xml version="1.0" encoding="Windows-1250"?>
<dat:dataPack version="2.0" id="$Id" ico="$DataPackIco" application="MyUcto export" note="$Note" $xmlns>
$($items -join "`r`n")
</dat:dataPack>
"@
}

function Invoke-PohodaXml([string]$RequestPath, [string]$ResponsePath, [string]$Database, [string]$WorkDir) {
    $ini = Join-Path $WorkDir 'pohoda_xml.ini'
    $lines = @('[XML]', "input_xml=$RequestPath", "response_xml=$ResponsePath")
    if ($Database) { $lines += "database=$Database" }
    $lines += 'check_duplicity=0'
    $lines += 'format_output=1'
    Write-Win1250 $ini (($lines -join "`r`n") + "`r`n")
    if (Test-Path $ResponsePath) { Remove-Item $ResponsePath -Force }

    $argLine = '/XML "{0}" "{1}" "{2}"' -f $Uzivatel, $script:HesloPlain, $ini
    $proc = Start-Process -FilePath $script:PohodaPath -ArgumentList $argLine -PassThru -WindowStyle Minimized
    if (-not $proc.WaitForExit($TimeoutMinut * 60 * 1000)) {
        try { $proc.Kill() } catch { }
        throw "POHODA neodpověděla do $TimeoutMinut minut (možná čeká na potvrzení dialogu v okně programu)."
    }
    if (-not (Test-Path $ResponsePath)) {
        throw "POHODA skončila (kód $($proc.ExitCode)) a nevytvořila odpověď. Zkontrolujte jméno, heslo a práva na XML."
    }
}

function Read-Response([string]$Path) {
    $doc = New-Object System.Xml.XmlDocument
    $doc.Load($Path)
    $pack = $doc.DocumentElement
    $states = New-Object System.Collections.Generic.List[string]
    $notes = New-Object System.Collections.Generic.List[string]
    $count = 0
    $nodes = @($pack)
    foreach ($item in $pack.ChildNodes) {
        if ($item.NodeType -ne 'Element') { continue }
        $nodes += $item
        foreach ($list in $item.ChildNodes) {
            if ($list.NodeType -ne 'Element') { continue }
            $nodes += $list
            $count += @($list.ChildNodes | Where-Object { $_.NodeType -eq 'Element' }).Count
        }
    }
    foreach ($node in $nodes) {
        if ($node.GetAttribute('state')) { $states.Add($node.GetAttribute('state')) }
        if ($node.GetAttribute('note') -and -not $notes.Contains($node.GetAttribute('note'))) { $notes.Add($node.GetAttribute('note')) }
    }
    $stav = 'ok'
    foreach ($s in $states) { if ($s -ne 'ok') { $stav = $s } }
    [pscustomobject]@{ Stav = $stav; Zaznamu = $count; Poznamka = ($notes -join ' | ') }
}

# --- start ---

if ($PohodaExe) { $script:PohodaPath = $PohodaExe } else { $script:PohodaPath = Find-PohodaExe }
if (-not $script:PohodaPath -or -not (Test-Path $script:PohodaPath)) {
    throw 'Nenašel jsem Pohoda.exe. Zadejte cestu parametrem -PohodaExe "C:\...\Pohoda.exe".'
}

if ($PSBoundParameters.ContainsKey('Heslo')) {
    $script:HesloPlain = $Heslo
} else {
    $secure = Read-Host "Heslo do POHODY pro uživatele $Uzivatel (prázdné = bez hesla)" -AsSecureString
    $script:HesloPlain = [System.Runtime.InteropServices.Marshal]::PtrToStringAuto([System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure))
}

if (-not $Vystup) { $Vystup = Join-Path $PSScriptRoot ('pohoda_export_' + (Get-Date -Format 'yyyyMMdd_HHmmss')) }
New-Item -ItemType Directory -Force $Vystup | Out-Null
$Vystup = (Resolve-Path $Vystup).Path
$work = Join-Path $Vystup '_pozadavky'
New-Item -ItemType Directory -Force $work | Out-Null
$logPath = Join-Path $Vystup 'souhrn.txt'
"Export z POHODY $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')" | Set-Content $logPath -Encoding UTF8
"Program: $script:PohodaPath" | Add-Content $logPath -Encoding UTF8

Write-Host "POHODA: $script:PohodaPath"
Write-Host "Výstup: $Vystup"
Write-Host ''
Write-Host 'Zjišťuji seznam účetních jednotek...'

$unitsReq = Join-Path $work '00_ucetni_jednotky.xml'
$unitsResp = Join-Path $Vystup '00_ucetni_jednotky.xml'
Write-Win1250 $unitsReq (New-DataPack 'units' '' 'Seznam ucetnich jednotek' '<acu:listAccountingUnitRequest version="1.6"/>')
Invoke-PohodaXml $unitsReq $unitsResp $null $work

$unitsDoc = New-Object System.Xml.XmlDocument
$unitsDoc.Load($unitsResp)
$nsm = New-Object System.Xml.XmlNamespaceManager $unitsDoc.NameTable
$nsm.AddNamespace('acu', $NsUri.acu)
$nsm.AddNamespace('typ', $NsUri.typ)
$units = foreach ($u in $unitsDoc.SelectNodes('//acu:itemAccountingUnit', $nsm)) {
    [pscustomobject]@{
        Rok      = [int]$u.SelectSingleNode('acu:year', $nsm).InnerText
        Ico      = ([string]$u.SelectSingleNode('.//typ:ico', $nsm).InnerText).Trim()
        Firma    = ([string]$u.SelectSingleNode('.//typ:company', $nsm).InnerText).Trim()
        Typ      = [string]$u.SelectSingleNode('acu:unitType', $nsm).InnerText
        Soubor   = [string]$u.SelectSingleNode('acu:dataFile', $nsm).InnerText
    }
}
if (-not $units) { throw "POHODA nevrátila žádnou účetní jednotku. Odpověď je v $unitsResp." }

$units | Sort-Object Ico, Rok | Format-Table -AutoSize | Out-String | Tee-Object -Variable unitsTable | Write-Host
$unitsTable | Add-Content $logPath -Encoding UTF8

$selected = @($units)
if ($Ico) { $selected = @($selected | Where-Object { $_.Ico -eq $Ico }) }
if ($Rok) { $selected = @($selected | Where-Object { $Rok -contains $_.Rok }) }
$icos = @($selected | Select-Object -ExpandProperty Ico -Unique)
if ($icos.Count -gt 1) {
    throw 'V POHODĚ je více firem. Spusťte skript znovu s parametrem -Ico <IČO> podle tabulky výše.'
}
if ($selected.Count -eq 0) { throw 'Zadanému IČO / roku neodpovídá žádná účetní jednotka (viz tabulka výše).' }

$summary = New-Object System.Collections.Generic.List[object]
$total = $selected.Count * $Agendy.Count
$n = 0
foreach ($unit in ($selected | Sort-Object Rok)) {
    $unitDir = Join-Path $Vystup ("{0}_{1}" -f $unit.Ico, $unit.Rok)
    New-Item -ItemType Directory -Force $unitDir | Out-Null
    Write-Host ''
    Write-Host ("=== {0} {1}, rok {2} ({3}) ===" -f $unit.Firma, $unit.Ico, $unit.Rok, $unit.Soubor)
    $i = 0
    foreach ($a in $Agendy) {
        $i++
        $n++
        $stem = '{0:D2}_{1}' -f $i, $a.Soubor
        Write-Progress -Activity "Export POHODA $($unit.Ico) / $($unit.Rok)" -Status $a.Popis -PercentComplete ([int](100 * $n / $total))
        $req = Join-Path $work ("{0}_{1}_{2}.xml" -f $unit.Ico, $unit.Rok, $stem)
        $resp = Join-Path $unitDir ($stem + '.xml')
        Write-Win1250 $req (New-DataPack ('{0:D3}' -f $i) $unit.Ico $a.Soubor $a.Xml)
        $row = [ordered]@{ Ico = $unit.Ico; Rok = $unit.Rok; Soubor = $stem + '.xml'; Agenda = $a.Popis; Stav = ''; Zaznamu = 0; Velikost_kB = 0; Poznamka = '' }
        $sw = [System.Diagnostics.Stopwatch]::StartNew()
        try {
            Invoke-PohodaXml $req $resp $unit.Soubor $work
            $r = Read-Response $resp
            $row.Stav = $r.Stav
            $row.Zaznamu = $r.Zaznamu
            $row.Poznamka = $r.Poznamka
            $row.Velikost_kB = [math]::Round((Get-Item $resp).Length / 1KB)
        } catch {
            $row.Stav = 'chyba'
            $row.Poznamka = $_.Exception.Message
        }
        $color = 'Green'
        if ($row.Stav -ne 'ok') { $color = 'Yellow' }
        Write-Host ("  {0,-38} {1,-8} {2,8} záznamů  {3,5}s  {4}" -f $a.Popis, $row.Stav, $row.Zaznamu, [int]$sw.Elapsed.TotalSeconds, $row.Poznamka) -ForegroundColor $color
        $summary.Add([pscustomobject]$row)
    }
}
Write-Progress -Activity 'Export POHODA' -Completed

$summary | Export-Csv (Join-Path $Vystup 'souhrn.csv') -NoTypeInformation -Encoding UTF8 -Delimiter ';'
$summary | Format-Table Ico, Rok, Soubor, Stav, Zaznamu, Velikost_kB, Poznamka -AutoSize | Out-String -Width 300 | Add-Content $logPath -Encoding UTF8

$zip = $Vystup.TrimEnd('\') + '.zip'
if (Test-Path $zip) { Remove-Item $zip -Force }
Compress-Archive -Path (Join-Path $Vystup '*') -DestinationPath $zip -CompressionLevel Optimal

$ok = @($summary | Where-Object { $_.Stav -eq 'ok' }).Count
Write-Host ''
Write-Host "Hotovo: $ok z $($summary.Count) exportů proběhlo bez chyby."
Write-Host "Pošlete nám prosím soubor: $zip" -ForegroundColor Cyan
