# 88. Nástroje

**Cesta: `Nástroje → Účetní nastavení`**. Sekce **Nástroje** následuje v
hlavním menu bezprostředně po **Účetnictví** v horním i levém rozložení.

Nástroje jsou jedna položka menu s několika záložkami. Viditelnost závisí na
účetním režimu a roli:

| Záložka | Dostupnost |
|---|---|
| Střediska | Podvojné účetnictví |
| Předkontace | Podvojné účetnictví |
| Kurzový režim | Podvojné účetnictví |
| Repo sazba ČNB | Podvojné účetnictví |
| Archiv účetnictví | Podvojné účetnictví, jen administrátor |

Účetní období a průvodce uzávěrkou jsou samostatný bod menu
[Uzávěrka](87_Uzaverka.md), nikoli záložka Nástrojů.

## 88.1 Hromadný export

**Cesta: `Daně → Hromadný export`**. Hromadný export je samostatná stránka,
nikoli záložka Nástrojů.

Export vytvoří ZIP podkladů za vybraný měsíc nebo kvartál. Náhled nejprve
spočítá dostupné soubory a dovolí vybrat:

- vydané faktury v PDF a ISDOC,
- přijaté faktury v PDF a ISDOC,
- bankovní výpisy v PDF a GPC,
- knihu DPH.

### 88.1.1 Zařazení do období

Export používá stejné rozhodné datum jako daňová vrstva:

- vydané faktury podle **DUZP** (`effective_tax_date`),
- přijaté faktury podle společného výrazu data nároku na odpočet z
  `VatLedgerService`, včetně data doručení a reverse-charge větví,
- bankovní výpisy podle data výpisu a výhradně podle vlastnictví účtu aktuální
  firmy,
- knihu DPH po jednotlivých měsících zvoleného období.

Tím se podklady přijatých faktur zařadí do stejného měsíce jako kniha DPH.
Změna data doručení nebo daňové klasifikace proto může změnit výsledek náhledu.

### 88.1.2 Background job

Po spuštění vznikne úloha `queued → running → completed`, případně `failed`
nebo `cancelled`. Worker vytváří soubory postupně, ukládá aktuální krok a počet
hotových položek. Aktivní může být jen jeden export firmy.

Hotový ZIP zůstává v historii ke stažení. Smazání historie odstraní i výsledný
soubor. Zrušení je kooperativní: worker požadavek kontroluje mezi položkami.
Chybějící zdrojové PDF nebo chyba rendereru se objeví v chybě úlohy; export
nepovažuj za úplný jen podle toho, že ZIP existuje.

Čtení a náhled vyžadují `reports.export`; spuštění, zrušení a smazání jeho
zápisovou variantu.

## 88.2 Střediska

Středisko rozlišuje odpovědnost nebo část firmy na řádcích účetního zápisu.
Číselník obsahuje neměnný unikátní **kód**, název a aktivní stav.

Kód se po založení nemění, aby se historické řádky a šablony nerozešly.
Nepoužité středisko lze smazat. Pokud je použité řádkem deníku nebo šablonou,
server je místo fyzického smazání deaktivuje. Neaktivní středisko zůstane v
historii, ale není nabízeno pro nový zápis.

Středisko samo nic nezaúčtuje a nemění účetní výkazy podle účtů. Je analytickým
rozměrem pro filtrování, export a manažerské vyhodnocení. Čtení vyžaduje
`accounting`, změny zápisové účetní oprávnění.

## 88.3 Předkontace

Předkontace jsou efektivní mapa systémových účetních operací na výchozí účty.
Server spojí globální pravidla s firemním override stejného klíče.

| Sloupec | Význam |
|---|---|
| Klíč | Stabilní typ operace, například `invoice.services.issued` |
| Popis | Lidský význam operace |
| MD účet | Výchozí účet strany Má dáti |
| Dal účet | Výchozí účet strany Dal |
| Původ | Globální nebo firemní |

Prázdná strana může být záměrná: konkrétní protiúčet doplní služba podle
dokladu, například u kurzového rozdílu. DPH na 343 také není součástí základní
mapy; dopočítává ji daňová služba z položek.

### 88.3.1 Firemní override

Akce **Upravit** mění jen MD/Dal účet. Alespoň jedna strana musí být vyplněná
a každý kód musí existovat a být aktivní v osnově firmy. Uložením vznikne
firemní override; globální seed se nemění.

Zaúčtování si účet ověří znovu. Deaktivuje-li se později, operace skončí
chybou, nepoužije jiný účet potichu. Stejně se hlídají závěrkové účty,
podrozvaha, otevřené období, zámek a vyrovnanost.

### 88.3.2 Import a export předkontací

Export obsahuje efektivní pohled se sloupci `klic`, `popis`, `md_ucet`,
`d_ucet`, `aktivni`, `priorita` a `zdroj`. Import přijímá XLSX/CSV do 2 MB a
má dry-run před potvrzením.

- Klíč musí existovat v globální šabloně; nový druh operace import nevytvoří.
- Účty musí být aktivní.
- `priorita` a `zdroj` jsou při importu informativní.
- Řádek shodný s efektivní hodnotou se přeskočí, aby nevznikal zbytečný override.
- Import nemaže a reportuje každý založený, změněný, přeskočený a chybný řádek.

Čtení a export používají účetní oprávnění. Web zobrazí editaci a import jen s
`accounting.templates:write`; server při zápisu navíc vyžaduje zápisové
oprávnění k účetnictví.

## 88.4 Kurzový režim

Firma může zvolit:

- **denní kurz** — kurz ČNB podle rozhodného dne,
- **pevný měsíční kurz**,
- **pevný roční kurz**.

U pevného režimu se zadává měna, rok, u měsíčního i měsíc, a kurz. Tlačítko
předvyplnění načte kurz ČNB k prvnímu dni období; uživatel jej před uložením
může změnit. Roční řádek má měsíc `0`.

`FixedExchangeRateService` při vzniku dokladu vyhledá přesný firemní kurz pro
měnu a období. Chybějící pevný kurz je chyba — server nesmí potichu přejít na
denní kurz. Použitý kurz se uloží do hlavičky dokladu jako historický snapshot.
Pozdější změna režimu nebo číselníku již uložený doklad nepřepočítá.

V denním režimu může kontrola upozornit na odchylku od ČNB. V pevném režimu je
tato odchylková kontrola záměrně vypnutá, protože odlišný kurz je zvolená
účetní metoda, ne chyba.

Pevné kurzy jsou tenantové, měna se normalizuje na třípísmenný kód a kurz musí
být kladný. Změna režimu i sazby se auditují.

## 88.5 Repo sazba ČNB

Číselník uchovává 2T repo sazbu, datum platnosti a poznámku. Řádek se stejným
datem se aktualizuje, nikoli duplikuje.

Sazbu používá výpočet zákonného úroku z prodlení:

`jistina × (repo sazba k počátku prodlení + 8) / 100 × dny / 365 nebo 366`

Rozhodná je sazba platná k prvnímu dni kalendářního pololetí, ve kterém
prodlení začalo; po dobu jednoho prodlení se změnou sazby uprostřed období
nepřepíná. Chybí-li potřebná historická sazba, kalkulátor vrátí chybu a úrok
nevymyslí. Více viz [Upomínky](22_Upominky.md).

Editaci sazeb svěř administrátorovi nebo účetnímu, který doloží zdroj ČNB.
Smazání používané historické sazby může znemožnit reprodukovat starší výpočet.

## 88.6 Obnovitelný archiv v kompletním exportu

Kompletní export v **Administrace → Kompletní export dat** může být přímo
obnovitelný. Není pro něj samostatná obrazovka ani druhý ZIP: je jednou z
volitelných částí jediného exportního balíčku.

Export obsahuje JSON Lines data firmy, mimo jiné:

- účetní období, osnovu, deník, předkontace a střediska,
- dlouhodobý majetek a odpisy,
- vydané a přijaté faktury, položky a částečné úhrady,
- banku a pokladnu,
- přílohy deníku včetně binárních souborů,
- kompletní firemní mzdovou evidenci včetně zaměstnanců, pracovních vztahů,
  docházky, vstupů, běhů, výsledků, srážek, plateb, dokumentů a podání,
- daň z příjmů a u skladové firmy skladovou evidenci.

Obnovitelný archiv přidává také připnuté legislativní katalogy JMHZ a výpočetní
obsah správcovských odchylek mzdových pravidel, aby obnovené snapshoty neztratily
své podklady. Globální audit, identity správců a jimi zapsané důvody se do exportu
jedné firmy nepřenášejí.

Manifest uvádí verzi schématu, počty řádků a SHA-256 každé datové části i
přílohy. Hesla, API klíče, soukromé certifikáty, volba podpisového certifikátu,
uložené osobní přístupy k ISDS a jiné provozní tajné hodnoty se neexportují;
po obnově se nastaví znovu. PDF faktur i mzdové dokumenty jsou součástí exportu;
zahrnuty jsou také zašifrované bankovní exporty mzdových plateb.

### 88.6.1 Obnova ze serveru

Obnova není dostupná ve webu. Administrátor serveru použije:

```text
php api/bin/archive-restore.php --file=<export.zip> --database=<prazdna_migrovana_db> --dry-run
php api/bin/archive-restore.php --file=<export.zip> --database=<prazdna_migrovana_db> --restore --storage=<prazdny_datovy_adresar> --documents
```

Databáze musí být předem migrovaná cílovou, stejnou nebo novější verzí MyÚčto a
spolu s datovým adresářem prázdná. Dry-run ověří hash a počet každé části i
přílohy bez zápisu. Ostrá obnova zachová interní ID, obnoví vše v jedné
databázové transakci a nepřepíše proto žádná existující data.

Po importu se znovu ověří všechny cizí klíče. Přílohy se uloží do zadaného
datového adresáře. Volitelný parametr `--documents` navíc uloží originály přijatých
faktur, importované originály vydaných faktur i aktuální PDF vydaných faktur do
jejich aplikačních úložišť; PDF vydané faktury přitom znovu propojí přes
`invoices.pdf_path`. Bez tohoto parametru se obnoví databáze, výpisy a přílohy,
ale PDF dokladů zůstávají jen v exportním ZIPu. Přihlašovací tajemství, tokeny a klíče se neobnovují;
uživatelé jsou zablokovaní a správce jim pošle pozvánku nebo reset hesla.
Mzdové osobní údaje a bankovní exporty zůstávají v archivu kontextově
zašifrované. Cílová instalace proto musí bezpečně převzít původní
`app.secret_encryption_key`, případně jej po rotaci dočasně ponechat mezi
`app.secret_encryption_previous_keys`. Samotný klíč v exportu nikdy není.
Automatický round-trip test hlídá počty, vazby a hashe souborů, ale archiv stále
nenahrazuje celoinstanční zálohu databáze.

### 88.6.2 Obsah kompletního exportu

Správce instalace najde v nabídce **Administrace → Kompletní export dat** jeden
nadřazený ZIP. U jednotlivých částí si zvolí, co do něj patří:

- **Úplný obnovitelný archiv** — hlavní ZIP s databází, binárními výpisy a
  přílohami; volba automaticky zapne nutné části, včetně podkladů pro volitelnou
  obnovu PDF dokladů;
- **Data, doklady a přílohy** — přenositelný JSON Lines export, PDF/ISDOC doklady,
  bankovní výpisy, mzdové PDF, zašifrované mzdové platební exporty a nahrané
  soubory;
- u plátce DPH **podklady po měsících** (Kniha DPH v PDF a kontrolní hlášení v XML),
  při čtvrtletní periodě také ZIP za každé čtvrtletí;
- v podvojném účetnictví **uzávěrkové balíčky** za vybraná účetní období.

Zadaný rozsah omezuje doklady a výkazy; obnovitelný archiv a JSONL data jsou vždy
kompletní pro jednu firmu. Obnovu provádějte přímo z tohoto ZIPu příkazem
uvedeným v předchozí kapitole — jeho formát je kompatibilní se stejnou i novější
verzí MyÚčto. Obecné soubory `data/*.jsonl` jsou kontrolní a přenosový export,
nikoli vstup pro import.

V podvojném účetnictví export slouží také jako praktický podklad pro zákonnou
retenci účetních a daňových záznamů. Přehled lhůt a zadržení skartace najdete v
sekci **Účetnictví → Retence**.

## 88.7 Retence a právní zadržení na backendu

Aktuální web Nástrojů nemá samostatnou záložku **Retence**, backend však
retenční pravidla vynucuje při mazání účetních a daňových dokladů a poskytuje
API `/api/accounting/retention`.

| Kategorie | Lhůta od konce období |
|---|---:|
| Účetní závěrka a výroční zpráva | 10 let |
| Účetní doklady, knihy, odpisové plány a inventury | 5 let |
| Daňové doklady | 10 let |

U dokladu s DPH vyhrává delší desetiletá lhůta. Poslední den lhůty je stále
chráněný; systém nic nemaže automaticky ani po jejím uplynutí.

Administrátor může při výslovně potvrzeném mazání retenční ochranu přehlasovat.
Takový zásah se zapíše do auditu s vypočteným datem uchování. Běžný uživatel
ochranu obejít nemůže.

Probíhající daňová kontrola nebo spor se eviduje jako **legal hold** podle § 32.
Zadržení může platit pro období nebo celou firmu a uchovává důvod a spisovou
značku. Aktivní hold blokuje smazání i po uplynutí běžné lhůty. Uvolnění je
ruční a auditované; historický záznam nezmizí.

## 88.8 Oprávnění a řešení problémů

- Záložky podvojného účetnictví vyžadují `accounting`; jejich změny zápisové
  oprávnění příslušného modulu.
- Měsíční export používá `reports.export`.
- Předkontace používají `accounting.templates`.
- Archiv a právní zadržení jsou administrátorské.
- Všechny soubory a záznamy jsou omezené aktuální firmou.

Při chybě background úlohy nejprve otevři její poslední krok a hlášení. Úlohu
nespouštěj opakovaně naslepo, pokud chyba vznikla chybějícím zdrojovým souborem,
neplatnou předkontací nebo nedostatkem místa. U kurzů a repo sazeb oprav
chybějící období v číselníku; již uložené doklady se samy zpětně nezmění.

> [!IMPORTANT]
> Archiv účetnictví je přenosný účetní export, ne jediná záloha. Pravidelně
> ověřuj také obnovitelnost celé databáze a datového adresáře.
