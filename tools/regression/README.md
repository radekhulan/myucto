# Lokální numerická regresní brána

Samostatný CLI harness načítá aplikaci z explicitního `--code-root`. Stejný harness lze použít nad dvěma checkouty, včetně baseline `499eb4dd1`. Neprovádí export databáze, klonování, migrace, seedování ani odesílání podání. Finanční služby nemění.

Manifest pokrývá níže uvedené čtecí výpočty pro účetní rámec 500, daňovou evidenci a roční daňové přechody. Discovery uvádí `manifest_complete=true`, ale `complete=false`. Teprve capture bez jediné chyby, s neprázdným manifestem a nenulovým numerickým pokrytím nastaví `coverage.complete=true`. To nepotvrzuje věcnou správnost aplikace ani shodu s druhou revizí; tu vyhodnocuje následné porovnání. Denní hranice účetních režimů jsou výslovně mimo tento roční parity rozsah; baseline metodu `forDate` nemá a harness ji nenahrazuje. Rámec 504 není součástí kompatibilní uzávěrkové projekce a skončí explicitní chybou s důkazem rámce.

## Vstupy a bezpečnost

Capture přijímá pouze již připravenou izolovanou databázi se jménem odpovídajícím `^[a-z][a-z0-9_]*_[a-f0-9]{8,32}_test$`. Odmítne i takto pojmenovanou databázi, pokud ji aktuálně používá konfigurace checkoutu. Hesla načítá původní `Config`, nepíší se do argumentů ani manifestu. Výchozí host musí být `localhost`, `127.0.0.1` nebo `::1`. Docker vyžaduje zároveň `--docker-test-host HOST` a `MYINVOICE_REGRESSION_ALLOW_DOCKER_TEST=1`; jde výhradně o testovací host.

Výstupní soubor ani pracovní adresář nesmí být uvnitř žádného Git repozitáře nebo code rootu. Kontrola používá `realpath`, tedy i junction/symlink ekvivalenty, a porovnává cesty bez ohledu na velikost písmen. Rodič výstupu musí již existovat. Existující soubor se nepřepisuje. Výstupní adresář zabezpečte oprávněními operačního systému: JSON obsahuje skutečné návratové hodnoty služeb. Nikdy jej nedávejte do fixtures, commitu, veřejného CI artefaktu nebo zprávy.

`ConfigSnapshot` načte původní konfiguraci instance včetně `MYINVOICE_DATA_DIR/cfg.local.php` před přesměrováním runtime. Celá konfigurace zůstává pouze v paměti; mění se cílový testovací DB host a název, vypnutí Redis a izolované runtime cesty. Původní Bootstrap a jeho DI registrace jsou zachované instrumentací v paměti. Zdroje Bootstrapu i Config jsou připnuté SHA-256 a neznámá změna skončí chybou. Test této cesty běží v čistém fixture podprocesu, aby změny tříd prováděné BypassFinals v PHPUnit neovlivnily kontrolu zdrojů.

Každý případ i preflight mají vlastní PHP proces, `MYINVOICE_DATA_DIR`, cache a tmp. Původní `Connection::pdo`, `LoggingPdo` a `LoggingPdoStatement` jsou instrumentovány pouze v paměti. Přijímají se jen ověřené SHA-256 jejich zdrojů; neznámá změna skončí chybou. Těla původních metod, nastavení PDO, SQL mode, timezone i sdílení handlů zůstávají zachována. Před každým vrácením připojení se ověří skutečné `SELECT DATABASE()`, `@@session.tx_read_only` a aktivní transakce. Nový handle dostane výchozí READ ONLY session a transakci. Výstup se dokončí až po opětovné kontrole a rollbacku.

SQL tripwire zastaví zápis ještě před native prepare. Pokus i PDOException z chybějící tabulky nebo jiného selhání zůstávají chybou, i když je služba spolkne. Totéž platí pro cURL, stream URL a blokované síťové/procesové funkce, včetně volání pod `@`. Očekávaný potlačený lokální cache miss (`SchemaCache` čte neexistující soubor přes `@file_get_contents`) zachovává původní PHP chování. Worker nesmí vytvářet další procesy. Redis je vypnutý. Nejde o OS sandbox pro nedůvěryhodný PHP kód: manifest volá pouze pevně vyjmenované aplikační služby. Přidání další služby vyžaduje review jejích I/O cest. Globální firewall ani původní zdrojové soubory se nemění.

## Spuštění

CLI vstup je `api/bin/regression-compare.php`. Argumenty jsou dvojice `--jméno hodnota`, bez shellových skriptů.

Capture vyžaduje:

- `--code-root`: absolutní cesta checkoutu s vlastním správným Composer autoloadem a konfigurací.
- `--database`: explicitní unikátní testovací kopie.
- `--as-of`: datum `YYYY-MM-DD`; určuje konec účetních filtrů a PSR hodiny.
- `--execution-date`: skutečný kalendářní den v časové zóně aplikace. Oba capture musí běžet ve stejný den; změna dne za běhu je chyba. Nativní `date()` a SQL hodiny se nepřepisují.
- `--dataset-id`: SHA-256 společného vstupního dumpu. Operátor ručí za totožnost kopií; harness nečte zdrojovou databázi ani sám totožnost dumpu nedokazuje.
- `--output`: nový absolutní soubor mimo Git, v privátním adresáři.

Režim `compare` vyžaduje `--baseline`, `--candidate` a `--output`. Oba capture musí mít stejný dataset, datum, execution date, manifest a rozsah pokrytí. Code rooty mohou být jiné. Výstup obsahuje úplný seznam strukturálních a numerických rozdílů po cestách; hodnoty zůstávají v privátních capture souborech.

Stdout obsahuje pouze agregáty a anonymní case IDs. Výjimky se nezapisují syrovými zprávami, SQL, parametry ani trace. Sanitizovaný typ výjimky, doménový kód, zdrojový soubor/řádek warningu a sticky porušení jsou v lokálním JSON. Chybějící kurz, seed, lazy write, síťový pokus, nepotlačený warning, timeout nebo fatální chyba nejsou skip. Shodná výjimka v obou revizích není úspěch. Chyba, rozdíl, prázdný capture nebo nulové numerické pokrytí vrací exit code 2.

## Manifest

| Oblast | Skutečná volání a filtry |
| --- | --- |
| Rozvaha a VZZ | `FinancialStatementService::balanceSheet`, `incomeStatement`, `incomeStatementByFunction`; každé účetní období, explicitní asOf, full/small/micro |
| Budoucí zápisy | Neukončené i budoucí období navíc samostatně do `ends_on`; průběžné výkazy do asOf se nesměšují s celoročním výsledkem |
| Předvaha a hlavní kniha | `TrialBalanceService::build`, `GeneralLedgerService::build`; syntetika i analytika, před i po uzavření |
| Dimenze | Každá viditelná hodnota včetně neaktivních, samostatně i s potomky; rozvaha, obě VZZ a analytická předvaha. `DimensionService::filter` zachovává produkční split/legacy sémantiku |
| Společné dimenze | `DimensionProfitService::build` za firmu i všechny členy skupiny s účty a firemním rozpadem; `DimensionCashFlowService::build` s filtrem stejné větve pro členy skupiny. Výsledovka respektuje kasovou bázi daňové evidence, dimenzovaný cashflow je účetní sestava nad deníkem |
| Uzávěrka a otevření 500 | `ClosingService::state`, `incomeTaxPreview`, `ClosingRepository::plBalances`, `bsBalances`, `openingBalancesInPeriod` a čisté `ClosingEntryBuilder::closingLines/openingLines`. Rozvahové řádky zachovávají společné dimenze a splity; výsledkové účty používají nativní agregovanou sémantiku rámce 500. Žádné skutečné uzavření či otevření roku |
| Další uzávěrkové náhledy | `inventoryPreview`, `stockValuationProjection` a `StockClosingValuation::totals`, `fxPreview` včetně nativních bankovních návrhů, `smallAssetAccrualPreview`, `prepaidExpenseAccrualPreview`, `deferredRevenueAccrualPreview`, všechny stránky `provisionsPreview`, `deferredTaxPreview` |
| DPPO | `TaxReturnService::previewReadOnly`, `readOnlyInputs`, `balanceDueReadOnly`, `DppoReturnDataProvider::gather` se skutečnými inputs a `ExistingObligationSourceService::dppoForecastForYear`; také průběžný rok a uložené budoucí roky |
| DPFO a daňová evidence | Stejné čtecí přiznání pro FO, `DpfoReturnDataProvider::gather`, `CashJournalService::build` a `TaxEstimateService::estimate`; také firmy bez účetního období |
| DPH/KH/SH | `VatLedgerService::rows/returnRows`, `DphBookBuilder`, `DphPriznaniBuilder`, `KontrolniHlaseniBuilder`, `SouhrnneHlaseniBuilder`; celé měsíce a čtvrtletí do asOf, bez draftů. Pro období s archivovaným podaným předchůdcem také DPH opravné/dodatečné, KH opravné/následné/následné opravné a SH následné |
| XML | Původní XML zůstává v capture. Navíc `xml_numeric` obsahuje desetinné řetězce finančních atributů DPPO/DPFO/VAT/KH/SH s úplnou DOM cestou, včetně nul a opakovaných vět. DPPO/DPFO XML vzniká přes `TaxReturnService::reportSource`. Žádné float přetypování, externí entity ani DTD |
| Historické režimy | `AccountingModeRepository::forYear` pro každý daňový rok a roky před/po změně režimu. Historická daňová evidence zůstává ve výběru i při současném podvojném režimu. Uložené odlišné typy přiznání se volají explicitně, bez přepsání současného typu firmy |

Začátek kontrolních měsíců se odvozuje z nejstaršího účetního období, faktury, přijaté faktury, pokladního nebo bankovního pohybu. Firma bez těchto dat dostane aktuální rok. Uložené daňové přiznání přidává svůj rok. PO mají podle kontraktu `KontrolniHlaseniBuilder::build` pouze měsíční KH; nepoužitelné kvartální KH jsou spočtené v `not_applicable.po_quarterly_control_statement`, kvartální DPH a SH se tím nevyřazují. Výběr VAT je technický kontrolní řez, nikoli potvrzení zákonné periodicity podání konkrétní firmy. Nepodporovaný rámec, nedostupná služba nebo chybějící daňové konstanty jsou chyba, ne tichý skip.

Roční DPPO/DPFO a daňové odhady používají skutečnou roční aplikační sémantiku, která může zahrnovat již zapsané budoucí pohyby. Forecast přijímá podle kontraktu služby jen roky 2020 až 2100; ostatní roky mají nadále přiznání a samostatné `not_applicable.dppo_forecast_year_outside_2020_2100`. Forecast respektuje uloženou budoucí lhůtu a skutečný `execution-date`; nativní hodiny se nemění. Syntetický test výslovně odlišuje VZZ k 30. 6. 2026 (10 000), celoroční VZZ/DPPO (19 000) a forecast doplatku (3 890).

Čtecí cesty byly prověřené včetně závislostí. Peněžní deník a jeho daňové varianty mohou při chybějících kurzech zkusit síť a doplnění cache; takový pokus zůstává chybou pod společným SQL/network tripwire. Harness nic nedoseeduje a nevypíná ochranu pro náhledy. Syntetická fixture obsahuje CZK i EUR s předem uloženým kurzem; náhled FX musí vrátit zisk 1 000 Kč. Uzávěrkový stav čte kroky a případné porovnání opening; incomeTaxPreview čte podklady a aktuální zůstatky, nic neúčtuje.

Uzávěrková projekce používá společná nativní API dostupná také v baseline `499eb4dd1`. Nevytváří náhradní finanční vzorce ani adaptační implementaci 504. Výstup obsahuje důkaz rámce z nativní rozvahy včetně version_code. Také starý kód vracející verzi vyhl504 bez samostatného framework pole je odmítnutý. Rámec 504 nebo neznámý tvar rozvahy vyvolá `regression_closing_framework_unsupported`; chyba se nepovažuje za pokrytý případ. Chybějící či nevyvážené otevření roku zůstává nativní chybou.

Chybějící podaný předchůdce VAT je vyčíslený jako `vat_amendments_without_filed_predecessor`; budoucí archivované období mimo asOf má samostatný počet. Existující archiv se ověřuje podle hashe XML, formuláře a zdaňovacího období. Vadné XML, nesprávný formulář nebo neúplný archiv znamená chybu, nikoli nulový základ. Finanční rozdíly počítají výhradně původní generátory.

Historický typ poplatníka se nepředpokládá podle současné firmy. Existuje-li uložené přiznání jiného typu, manifest zavolá tento typ samostatně. Nativní `taxpayer_type_mismatch` zůstává chybou a brání úplnému capture. Bez uloženého dokladu se změna typu nedovozuje. Roční historie účetního režimu se čte přes původní `forYear` k poslednímu dni roku; široký kontrolní výběr daňové evidence zachová i roky po přechodu a nechá filtraci na nativní službě.

## Porovnání

Desetinná čísla se kanonizují řetězcově, bez konverze DECIMAL na float, bez epsilon tolerance a bez zaokrouhlování výsledku. `1.2300` a `1.23` jsou numericky shodné, nejmenší nenulový rozdíl zůstává rozdílem. PHP float se porovnává podle přesné JSON reprezentace vrácené PHP; harness nepředstírá vyšší přesnost, než vrací služba. Nula a null jsou různé, neztrácejí se nulové řádky ani duplicity a pořadí seznamu zůstává významné. Asociativní klíče se porovnávají podle jména.

IDs, kódy a enum `accounting_decree` se porovnávají beze změny typu, ale nezvyšují počet numerických hodnot. Přidané pole, například `podklady.accounting_decree=500`, se objeví jako `added_field` a vyžaduje review. Neexistuje automatický seznam tolerovaných rozdílů. Jediná normalizace výstupu je prezentační `entity.prepared_at` na explicitní asOf; ostatní data a časové údaje zůstávají viditelné.

## Ověření harnessu

Unit testy jsou `RegressionManifestTest`, `RegressionCaseProjectionTest`, `RegressionComparatorTest` a `RegressionInstrumentationTest`. Ověřují také ztrátu finančních atributů DPFO, chybějící nové náhledy, odmítnutí vadného archivu, explicitní historické typy, všechny podporované varianty oprav a stránkování opravných položek. Opt-in `MYINVOICE_REGRESSION_SYNTHETIC_DB=1` povolí `RegressionMariaDbInstrumentationTest`: vytvoří vlastní náhodnou lokální testovací DB pouze se syntetickou DECIMAL/NULL tabulkou. Ověřuje shodu původního/instrumentovaného PDO, skutečný READ ONLY stav, sdílení i nové připojení a zachycený UPDATE.

Stejný opt-in povolí `RegressionCaptureTest`. Ten vytváří jinou vlastní náhodnou databázi, spustí plné migrace přes `migrate.php --no-backfills --no-analyze` a `ci-seed.php`, vloží syntetické doklady a přes skutečný `PostingService` zaúčtuje nenulové částky. Následný capture volá každý implementovaný kind skutečným kontejnerem v samostatných READ ONLY procesech. Kontroluje úspěch všech případů, přesný hospodářský výsledek, nenulové finanční hodnoty včetně XML a předání ručních DPPO inputs do provideru. Nepoužívá ručně zjednodušené aplikační tabulky. Oba integrační testy databázi odstraní pouze po úspěšném CREATE a nastavení owned flagu. Testy se spouštějí standardním PHPUnit s explicitním absolutním `-c api/phpunit.xml` a testovací DB.

Syntetická parity fixture po plných migracích a před zmrazením vstupu odstraní pouze referenční definice výkazů 504 z vlastní testovací DB. Obě revize tak dostanou totožný nativní referenční seed 500; baseline nezná framework filtr a s dopřednými definicemi 504 by vybrala jiný výkaz. Tato příprava neprobíhá v harnessu ani během capture a nijak nepřepisuje finanční výsledky. Snapshot zahrnuje referenční definice i účetní a daňová data. Skutečné srovnání začíná dvěma kopiemi stejného frozen dumpu a každá používá pouze migrace svého checkoutu; do baseline se dopředné seedy nevkládají.

Volitelný `MYINVOICE_REGRESSION_BASELINE_ROOT` přidá do syntetického integračního testu celý capture druhého checkoutu nad totožným zmrazeným vstupem. Obě sady musí proběhnout bez chyby a mít totožný manifest; vstupní data zůstanou beze změny. Test tím nedeklaruje numerickou shodu revizí. Před finálním lokálním srovnáním je nutné samostatně reviewovat přípravu dvou izolovaných kopií a migrační strategii. Tento nástroj přípravu kopií neautomatizuje.
