# Stereo NX — převod daňové evidence a účetnictví

Zdroj čte `blondak/nx1-reader` připnutý v Composer lockfile. Firemní zálohy ani dekódované obchodní řádky nepatří do repozitáře.
Výchozí heslo formátu je na výslovný požadavek provozovatele součástí
serverového adaptéru `StereoNxBackupPassword` v XOR podobě; obfuskace jej
nechrání před čtenářem zdrojového kódu. Do prohlížeče se neposílá. Čtení archivu je
přímé, bez extrakce na disk. Testovací ZIPy obsahují pouze syntetická data.

## Implementováno

- `StereoNxManifest`: seznam firem z `ObsahBck.txt`, UTF-8 / Windows-1250,
  explicitní vazba číselného indexu na `Firma_<index>/`. Poslední pole záznamu
  se uchovává jako neprůhledný identifikátor, není považováno za IČO.
- `StereoNxBackup`: kontrola limitů ZIPu, cest, duplicit bez rozlišení velikosti
  písmen a výběru firmy. Inventura čte každý řádek každé firemní tabulky a
  porovnává počet s deklarovaným počtem. Chyba není prázdná tabulka.
- `StereoNxPaymentReconciliation`: domácí úhrady přes explicitní dvojici
  `DoklSRada` + `DoklSCislo`; kontrola osiřelých a neúplných vazeb, směru a
  částek. Cizoměnové vazby se označují jako neověřené. Nepoužívá heuristiku
  podle variabilního symbolu ani data splatnosti.
- `StereoNxCompanyMetadata`: strukturální čtení ověřeného formátu `firma.bin`
  s hlavičkou TPF0 a verzí 251. Vrací pouze IČO, DIČ, název a plátcovství;
  žádné hledání identifikátorů v osobních údajích nebo volném textu.
- `StereoNxVat`: převádí řádky přiznání z `Lsdph` přes čistý veřejný
  klasifikátor `PremierVat`; názvy a zkratky členění nejsou daňovou autoritou.
- `StereoNxPurchaseRecap`: plán náhradních přijatých položek po sazbách,
  s původním textem, režimem cen, dodavatelskou daní, samovyměřením a explicitním
  zaokrouhlením. Neurčené příznaky mají `requires_draft: true`; samovyměření
  nemění závazek vůči dodavateli. Výchozí sazby se nedosazují.
- `api/bin/stereo-nx-inspect.php`: strojově čitelný přehled schématu, počtů
  a kontroly úhrad. Volba `--purchases` přidá souhrn plánování přijatých
  rekapitulací bez řádkových firemních hodnot. Neotevírá spojení s aplikační
  databází.

- `StereoNxSourcePlan` a `StereoNxIssuedDocuments`: úplný plán podporovaných
  agend, historické údaje protistran, kontrola saldokonta a peněžního deníku,
  roční složené klíče a důvody konceptů. Naplněné neověřené agendy převod blokují.
- `StereoNxImporter`: transakční zápis do existujícího plátce DPH v režimu
  `tax_evidence`, shoda IČO, kontrola uzávěrek a naplněných období. Zkouška
  nanečisto provede stejný zápis a rollback. Trvalá mapa hlídá opakování a změny
  zdroje. Platby mají fyzickou bankovní/pokladní vazbu; koncepty zůstávají mimo DPH.
- `StereoNxMigrationAction`, `StereoNxUploads` a průvodce v `web/src/pages/imports`:
  upload po částech, výběr firmy, kontrolní běh a převod. Úspěšná zkouška je
  vázaná na archiv, firmu a výklad prázdné země. Nahrané archivy jsou omezené
  na tenanta i uživatele a mají časově omezenou retenci.

CLI inspektor je stále pouze kontrola zdroje: `ready_for_import: false` a
`mode: source_inspection`. Databázovou zkoušku provádí průvodce v aplikaci.

Volba `--accounting` přidává kontrolu deníku vůči účtové osnově a kontrolu
vazeb zaměstnanců na mzdové měsíce. Zdrojový rok a datum účetního případu
se zachovávají samostatně. Rozdíl roku je upozornění; kontace se časově řadí
podle `KdyUcPripad`, což bylo ověřeno proti oběma ročním předvahám Stereo
po jednotlivých účtech a stranách MD/Dal. Identita řádku
deníku zahrnuje `Agenda`, `DoklRada`, `DoklCislo`, `Klic` a `Poradi`, protože
jeden řádek dokladu může mít více kontací. `UID` není autoritou identity.
Export deníku ze Stereo potvrzuje, že `UID` označuje **ID uživatele**,
`Klic` je **Číslo záznamu**, `Poradi` je **Pořadí** a `KdyUcPripad` je
**Okamžik uskutečnění**. Sloupce `Rok` a `Mesic` exportuje samostatně;
samotná shoda se zdrojovým CSV nestačí k ověření zařazení do výkazů.
Nulové pomocné identifikátory export zobrazuje prázdné.
Počáteční zápisy označuje `LFirma.DruhPS`; ověřená předvaha je vykazuje
zvlášť od obratů a nesmějí být převzaty zároveň z deníku i ze stavů osnovy.
Výstup CLI obsahuje pouze souhrny a počty problémů, nikoli jednotlivé
kontace nebo osobní údaje. Samotná diagnostika neprovádí databázový
import a neověřuje mzdové výpočty.

## Zdrojové tabulky a ověřované vazby

| Oblast | Tabulky | Vazba / poznámka |
|---|---|---|
| Firmy | `ObsahBck.txt`, `LFirma`, `LFirmaUc` | `LFirma` obsahuje i přístupové údaje; nikdy nedumpovat celé řádky do reportů |
| Adresář | `LAdresy`, `LAdruct` | kandidát identity `Firma`; snapshoty dokladů ponechat historické |
| Vydané doklady | `Svfh`, `Svfp` | hlavička a položky: `DoklSRada`, `DoklSCislo` |
| Přijaté doklady | `SPFH`, `Spfp` | tabulka položek může být prázdná i při existujících hlavičkách |
| Pohledávky/závazky | `Cpz`, `CPZZ` | `Cpz.Uhrazeno`, nikoli samotné `UhrazenoVse`, pro kontrolu úhrad |
| Banka | `CBanka`, `CBankap` | výpis/položky `DoklRada`, `DoklCislo`; vazba platby na doklad přes `DoklSRada`, `DoklSCislo` |
| Pokladna | `CPokl`, `CPoklSD` | rozlišit samotný peněžní pohyb a rozpis složeného dokladu |
| Peněžní deník | `Cdenik` | obsahuje projekci banky/pokladny; nepřidávat jako další fyzické platby |
| Druhy a sloupce | `Ldruhy`, `LSloupce` | `Sloupec`, `Typ`; význam kategorií potvrdit před překladem do MyÚčta |
| Členění DPH | `Lsdph`, `ZAZPVDPH` | číselník a zdrojová kontrolní evidence; cílové DPH přes `VatLedgerService` |
| Majetek/mzdy/sklad | `J*`, `M*`, `S*` | prázdná tabulka nepotvrzuje správnost budoucího mapování naplněné tabulky |

Kontrola úhrad porovnává součet `CBankap.Castka` a `CPokl.Castka` navázaných
na doklad s `Cpz.Uhrazeno`. Směr musí odpovídat, měna dokladu musí být CZK
(`Kč` nebo `CZK`) s jednotkovým kurzem. Převody cizích měn ani úhrady z jiných
agend tato kontrola nedopočítává. Neshoda se nesmí obejít dosazením nuly.

## Hranice převodu

Daňová evidence podporuje domácí doklady CZK plátce. Neověřené agendy,
zálohy, cizí měny a vlastní řádky `Spfp` tento režim nadále blokují.

Podvojné účetnictví obsluhuje `StereoNxAccountingImporter` s jednou transakcí
pro všechny moduly. `StereoNxAccountingWriter` zapisuje účtový rozvrh a deník,
záporné kontace přes `is_red_storno` při zachování původních stran.
`StereoNxJournalLinks` váže deník na faktury přes sdílený `JournalEntryLinker`.
`StereoNxAccountingDocuments` přebírá i ověřené cizoměnové faktury a dobropisy;
nejisté daňové údaje a souhrnné položky znamenají koncept k ruční kontrole.
Měna faktury musí existovat v cílové firmě. `Cdenik` neobsahuje cizoměnové
částky; jejich rozpad do kontací se neodhaduje. Před kurzovým přeceněním
je nutná kontrola cizoměnových zůstatků.
`StereoNxAccountingPayments` zapisuje ověřenou domácí banku, pokladnu a úhrady
bez dalšího automatického zaúčtování. Datum každého dokladu i pohybu se
kontroluje přes `StereoNxTargetDates`, i když leží mimo data účetního deníku.

`StereoNxAssets`, `StereoNxEmployees` a `StereoNxInventory` přebírají ověřené
karty majetku s historickými odpisy, zaměstnance s pracovními vztahy, sklady,
skladové karty a vozidla. Mzdy bez úplných složek a ověřeného období,
nejasné technické zhodnocení, skladové stavy, leasing a další nepodporované
agendy se výslovně vykazují jako nepřevedené. Částečný převod nemaže zálohu.
Neověřené hodnoty se nedoplňují odhadem.

### Historické mzdy

`StereoNxPayrollMonths` převádí doložený standardní HPP ze `MMzdy` na měsíční
reference; `StereoNxPayrollWriter` je ukládá přes společný
`PayrollMigrationReferenceTotalsWriter` pod samostatným zdrojem `stereo_nx`.
Hranici převzatých měsíců určuje `PayrollHistoricalPeriodService`; import
ji neposouvá a nevytváří mzdové běhy, platby ani další kontace.

Mapování hrubé a čisté mzdy, srážek a dobírky bylo ověřeno na podrobných
páskách a měsíční rekapitulaci. `StravPO` je osvobozený stravenkový paušál;
`Dobirka` je částka k výplatě, nikoli součet `NaUcet` a `VHotovosti`.
`TypDan=Z` odpovídá záloze na daň; ostatní kódy nejsou odhadovány.
Zaměstnavatelské pojistné se v podporovaném jednoduchém případě rekonstruuje
z globální `DataPrg/GDATA/Gparrok`, podle platnosti k mzdovému měsíci.
Sociální částka na historické pásce je zaokrouhlena nahoru za každou osobu;
zdravotní částka je zaokrouhlené celkové pojistné minus zdrojová zaměstnanecká
část. Nejde o nový výpočet dnešního odvodu zaměstnavatele. Souběhy, doplatky,
slevy a jiné neověřené varianty se odmítají, nikoli doplňují nulou.

Reference zachovávají oddělenou čistou mzdu, srážky a dobírku. Historická
srážka není karta exekuce; počáteční kumulace se touto cestou nezakládají.
Zdrojové klíče a otisky brání změně již převzaté mzdy i dvojímu převodu;
cílová osoba a pracovní vztah musejí patřit vybrané firmě.

Návrh mzdových předkontací vzniká pouze z řádků `Cdenik` v účetní řadě
`MPARZPR.DoklRadaU`, jejichž text i dvojice účtů souhlasí s pojmenovanou
zaměstnaneckou kontací `MPARUCT` (`TypPar=1`). Rozlišují se hrubá mzda,
pojistné obou stran, zálohová a srážková daň a doložené exekuce a ostatní
srážky. Neznámé texty a rozporné kontace nevytvářejí doporučení. Návrh musí
potvrdit účetní; opakovaný převod již potvrzený návrh Stereo nepřepíše.

Výklad prázdné země jako ČR je explicitní volbou průvodce. Označení EU bez
konkrétního státu zůstává neurčené. Kvůli povinnému cílovému `country_id`
mají takové protistrany technický zástupný stát CZ, poznámku a všechny
jejich doklady stav koncept; stát musí uživatel před potvrzením opravit.
Neurčené příznaky DPH/cen, chybějící údaje a neshody zdrojových evidencí se
zachovávají jako důvody ruční kontroly, nikoli jako ověřené daňové údaje.

Dokladové DPH zpracovává stávající `VatLedgerService`; import nevytváří
vlastní vedlejší evidenci. V daňové evidenci `Cdenik` kontroluje banku a pokladnu, nepřidává
virtuální úhrady. Nulový počáteční záznam pokladny se eviduje v mapě převodu
bez vytvoření nepovoleného nulového pokladního dokladu.

## Ověření

Syntetická fixture `SyntheticNx1Archive` skládá skutečný NX!2/DICT/NXDH
formát a šifrovaný ZIP. Integrační test `StereoNxImportTest` prochází čtením,
HTTP akcemi a zápisem do izolované DB, zkouškou nanečisto, opakováním,
kontrolou DPH a peněžního deníku. Reálná záloha do testů nepatří.

### Režim a firemní profil

`StereoNxBackup::companyIdentity()` určuje `accounting_mode` jen z homogenního
neprázdného `Cdenik` s ověřenými poli `UcetMD`/`UcetD`: všechny řádky s oběma
účty znamenají podvojné účetnictví, všechny bez účtů daňovou evidenci.
Prázdné, smíšené či neúplné údaje vracejí `null`; typ organizace ani přítomnost
účtového rozvrhu nejsou důkaz režimu. Společný `StereoNxCompanyCompatibility`
blokuje známý nesoulad v HTTP akci i obou převodnících před tvorbou plánu.

Profil z `firma.bin` se nabízí po jednotlivých rozdílech vedle aktuálních
hodnot. `StereoNxCompanyProfile` omezuje pole na běžné firemní a kontaktní
údaje; vybrané hodnoty se vždy znovu načtou ze zálohy. Samostatná akce ověří
IČO, hash ZIPu, vlastnictví nahrávky, očekávané původní hodnoty pod zámkem
firmy a použije `SettingsAction` pro shodná oprávnění a validaci jako běžné
nastavení. Změna režimu účetnictví a DPH zůstává v existujícím nastavení firmy.

### Omezení ručních kontrol

Typ `Z` je zálohová faktura, která sama nevstupuje do účtování ani DPH
([Kastner FAQ 0209](https://www.kastnersw.cz/stereo/faq/?faqNumber=0209)).
Při explicitním `ZpracovatDPH=false` se mapuje na vydanou proformu či
přijatou zálohu. Rozpis se zachová, ale evidence DPH jej nezahrne. Bez rozpisu
se smí použít pouze doložený popis a kladné celkem bez zdrojové DPH.
Vazby čerpání záloh se nehádají; původní kladné a záporné položky se zachovají
v konceptu do ověření vazby. Neznámý typ `M` se neoznačuje jako storno.

Samostatná CZK pokladna bez DPH se zbaví kontroly jen při explicitních
prázdných vazbách, vypnutém DPH, nulovém daňovém rozpisu a úplné shodě
s kontacemi na pokladní účet 211 podle částky, směru a data.
`StereoNxMovementJournalLinks` sdílí identifikaci i kontrolu s plánem
převodu a ověří vazbu znovu před potvrzením transakce. Opakovaný převod
nemění ručně upravený stav cílového dokladu.

Cizoměnové koncepty rozlišují neshodu korunového celku s kurzem a neshodu
korunového daňového základu s přepočteným celkem. Diagnostika nemění
kurz ani daňové částky. Zemi označenou pouze `I` bez dalšího doložení
nepovažuje automaticky za Itálii.

## Společná infrastruktura a vědomé rozdíly

Stereo adaptér dekóduje a ověřuje zdroj, zapisovače cílových evidencí sdílí
s ostatními převody. Doklady používají `MigratedDocumentWriter` a kanonické
DTO, `MigrationVatRateLookup`, `VatReturnLineClassifier`,
`PartnerIdentityMatcher` a `ForeignCurrencyTakeover`. Banka a pokladna
využívají `BankAccountRegistrar`, `BankStatementImportWriter`, `BankSymbols`,
`MigratedCashNumber` a `MigratedPaymentWriter`; vazby spravuje
`JournalEntryLinker`. Neověřená měnová vazba úhrady se neodhaduje.

Osnova a období používají `ChartAccountCreator`, `MigrationPeriods` a
`MigrationHomeCurrency`. Výslovný typ účtu a strana ze Stereo jsou volbami
společného zapisovače, nikoli druhou implementací pravidel. Předvahu proti
zdrojovému deníku porovnávají `TrialBalanceReconciliation` a
`ReconciliationCriteria`. Karty majetku používají společné služby majetku,
`SmallAssetCard` a `MigratedDepreciation`; neověřené vyřazení zůstává
konceptem, nepředává se jako doložené `MigratedDisposal`.

Mzdy používají `PayrollTakeoverRecord`, personální DTO, společné personální
a pracovněprávní zapisovače a `PayrollMigrationReferenceTotalsWriter`.
`PayrollMigrationModuleSetup` plánuje a doplňuje chybějící nastavení modulu;
zkouška vrací i jeho změny. `StereoNxPayrollPostingMap` implementuje
`PayrollLegacyPostingSource` jen pro kontace doložené současně MPARUCT,
MPARZPR a Cdenik. `PayrollPostingMapProposalService` ukládá návrh s volbou
zachovat potvrzený výsledek; výchozí chování ostatních převodů se nemění.

Karty skladů, zásob a vozidel zapisuje obecný `MigratedInventoryWriter` přes
repozitáře příslušných evidencí, bez tvorby skladových pohybů nebo jízd.
Firemní kontakty využívají `MigrationCompanyIdentity` a výběrové převzetí
v `CompanyProfileCarryOver`. Stereo převádí do existující firmy: nezakládá
novou firmu podle ARES a nepřepisuje plátcovství z příznaku v záloze.

Job dědí `AbstractImportJobService`; `ChunkedUploadStore` zachovává formát
již nahraných záloh přes explicitní konfiguraci. Zdrojová mapa nadále nese
IČO, index firmy, klíč a otisk kvůli více firmám v jedné záloze. Její zápis
je pouze evidence původu, nikoli samostatný zapisovač účetních objektů.
