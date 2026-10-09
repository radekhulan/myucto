# 91. Mzdové složky a vstupy

> Návod, jak zadávat mzdové vstupy zaměstnancům (odměny, prémie, benefity,
> srážky z podkladů), nastavit pravidelné předpisy, importovat vstupy ze souboru
> a spravovat katalog mzdových složek. Pro mzdové účetní.

## 91.1 Kdy to potřebujete

<!-- cols: 30 40 30 -->
| Kdy | Co udělat | Kde v aplikaci |
|---|---|---|
| každý měsíc před výpočtem | Zadat jednorázové vstupy (odměna, prémie, benefit) a schválit je | záložka **Měsíční vstupy**, [§ 91.3](#913-krok-za-krokem-jednorazovy-mesicni-vstup) |
| každý měsíc | Schválit koncepty z importu docházky a předpisů | [§ 91.5](#915-krok-za-krokem-hromadne-schvaleni-vstupu) |
| při změně smlouvy | Nastavit pravidelnou složku (příplatek, paušál) | záložka **Pravidelné předpisy**, [§ 91.4](#914-krok-za-krokem-pravidelny-predpis) |
| když máte podklady v tabulce | Importovat vstupy | záložka **CSV / XLSX import**, [§ 91.6](#916-krok-za-krokem-import-vstupu-z-csv-nebo-xlsx) |
| při zavedení nového plnění | Založit vlastní složku a zařadit ji do JMHZ | záložka **Katalog složek**, [§ 91.7](#917-krok-za-krokem-nova-mzdova-slozka) |
| u prací 3. kategorie | Zadat podklady povinného spoření | záložka **Rizikové spoření**, [§ 91.9](#919-krok-za-krokem-povinne-sporeni-u-rizikove-prace) |

## 91.2 Než začnete

1. **Pracovní vztah** musí být aktivní v daném období (viz
   [Zaměstnanci](86_Zamestnanci.md)).
2. **Podklad** pro částku, množství a období máte připravený (smlouva,
   rozhodnutí o odměně, doklad o benefitu).
3. **Oprávnění**: k zápisu a schválení potřebujete oprávnění k mzdovým vstupům;
   katalog složek upravuje správce mezd.

Hromadně zadáte měsíc rychleji v [Rychlém měsíčním vstupu](79_Rychly_mesicni_vstup.md).
Absence, cestovní náhrady, benefity a srážky se zadávají ve vlastních agendách.

### 91.2.1 Jak je stránka uspořádaná

`Mzdy → Mzdové složky a vstupy` má nahoře **Mzdové období** a záložky:

<!-- cols: 30 70 -->
| Záložka | K čemu slouží |
|---|---|
| **Katalog složek** | Mzdové složky a jejich dopad do daně, pojištění, JMHZ a účetnictví |
| **Pravidelné předpisy** | Složky, které se opakují každý měsíc po dobu účinnosti |
| **Měsíční vstupy** | Jednorázové vstupy za období, koncepty a jejich schválení |
| **Rizikové spoření** | Podklady povinného spoření u rizikové práce |
| **CSV / XLSX import** | Import měsíčních vstupů s povinným náhledem |

## 91.3 Krok za krokem: jednorázový měsíční vstup

1. Otevřete `Mzdy → Mzdové složky a vstupy`, zvolte **Mzdové období**
   a záložku **Měsíční vstupy**.
2. Klikněte na **Nový vstup**.
3. Vyberte **Zaměstnanec**, **Pracovní vztah** a **Mzdová složka**. Vyplňte
   **Částka (Kč)** nebo **Množství** a případně poznámku.
4. Klikněte na **Zkontrolovat dopad**. U benefitu náhled ukáže čerpání koše
   a zbytek limitu.
5. Uložte. Vstup vznikne jako **Koncept**, ještě ho lze upravit nebo zrušit.
6. Schvalte ho (jednotlivě, nebo hromadně podle [§ 91.5](#915-krok-za-krokem-hromadne-schvaleni-vstupu)).

**Jak poznáte, že je hotovo:** Vstup má stav **Schválený**. Po zahájení
mzdového běhu se změní na **Uzamčený během**.

## 91.4 Krok za krokem: pravidelný předpis

1. Otevřete záložku **Pravidelné předpisy** a klikněte na **Nový předpis**.
2. Vyberte zaměstnance, vztah a složku.
3. Zvolte zadání pevnou částkou, nebo procentem (**Sazba (%)** jako běžné
   procento).
4. Vyplňte **Platnost od**, případně **Platnost do**, a uložte.
5. Do zvoleného měsíce ho převedete tlačítkem **Převést do měsíce**. Aplikace
   ohlásí, kolik vstupů vytvořila, kolik už existovalo a kolik čeká na ruční
   kontrolu.

**Jak poznáte, že je hotovo:** Předpis je v seznamu se stavem *Platný* (nebo
*Naplánovaný*, začíná-li v budoucnu) a v měsíci vznikne vstup ze zdroje
předpisu.

## 91.5 Krok za krokem: hromadné schválení vstupů

1. Na záložce **Měsíční vstupy** zvolte období.
2. Zužte seznam filtry (**Hledat**, **Složky**, **Stavy**, **Zdroj**,
   **Importní dávka**), třeba na importní dávku docházky.
3. Zkontrolujte souhrnný pruh (počet vstupů, součet, počet konceptů).
4. Klikněte na **Schválit … odpovídajících filtru**. Jen vybrané řádky schválíte
   zaškrtnutím a **Schválit vybrané**.
5. Co schválit nešlo, zůstane pod pruhem seskupené podle důvodu. Vyřešte to
   a schvalte znovu.

**Jak poznáte, že je hotovo:** Souhrnný pruh ukazuje nula konceptů a mzdový běh
nehlásí neschválené vstupy.

> [!TIP]
> Mzdový běh u blokace neschválených vstupů odkazuje rovnou na koncepty měsíce
> a nabízí jejich hromadné schválení.

## 91.6 Krok za krokem: import vstupů z CSV nebo XLSX

1. Otevřete záložku **CSV / XLSX import**.
2. Klikněte na **Vybrat soubor**, nebo soubor přetáhněte do zvýrazněné plochy
   (CSV nebo XLSX, nejvýš 5 MB).
3. Klikněte na **Zkontrolovat soubor**. Náhled ukáže **Platných**,
   **Chybných** a **Duplicit** s popisem u každého řádku.
4. Opravte chybné řádky v souboru, nebo klikněte na **Importovat platné
   řádky**.
5. Importované vstupy schvalte na záložce **Měsíční vstupy**.

**Jak poznáte, že je hotovo:** Aplikace hlásí „Importní protokol byl uložen"
s počty přijatých, odmítnutých a duplicitních řádků.

## 91.7 Krok za krokem: nová mzdová složka

1. Otevřete záložku **Katalog složek** a klikněte na **Nová složka**.
2. Vyplňte **Název**. **Kód** se z něj vytvoří sám bez diakritiky.
3. Nastavte **Druh složky**, **Povaha plnění**, **Způsob výpočtu**, **Četnost**
   a dopad do daně a pojištění.
4. U benefitu vyberte **Zákonný koš osvobození** a případně **Podklad
   osvobození** a **Roční limit (Kč)** (viz [Koše benefitů](89_Kose_benefitu.md)).
5. Vyberte **Účet Má dáti** a **Účet Dal** (našeptávač podle čísla nebo názvu).
6. Vyplňte **Platnost od** a uložte.
7. Zařaďte složku do JMHZ ([§ 91.8](#918-krok-za-krokem-zarazeni-slozky-do-jmhz)).

**Jak poznáte, že je hotovo:** Aplikace hlásí „Mzdová složka byla uložena."
a složka je v katalogu jako **Aktivní**.

## 91.8 Krok za krokem: zařazení složky do JMHZ

1. V **Katalogu složek** klikněte u složky na **Nastavit JMHZ**.
2. Vyberte **Cílový atribut měsíčního hlášení**.
3. Uložte.

**Jak poznáte, že je hotovo:** Sloupec **JMHZ** ukazuje **Namapováno** (nebo
**Jen do úhrnu příjmu (bez rozpadu mzdy)**) místo **Chybí mapování**.

## 91.9 Krok za krokem: povinné spoření u rizikové práce

1. V `Mzdy → Nastavení mezd`, záložce **Účty institucí**, založte penzijní
   společnost jako jiného příjemce a ověřte její účet.
2. Otevřete záložku **Rizikové spoření**, zvolte měsíc a klikněte na **Nový
   záznam**.
3. Vyberte zaměstnance, vztah a **Zákonný rizikový faktor (3. kategorie)**.
4. Zadejte **Celé osmihodinové rizikové směny** a **Započaté hodiny u směn jiné
   délky**.
5. Vyplňte **Právo uplatněno dne**, **Zaměstnanec informován dne**,
   **Penzijní společnost**, **Ověřený účet penzijní společnosti**,
   **Identifikace produktu / smlouvy** a symboly podle pokynů penzijní
   společnosti.
6. Klikněte na **Uložit koncept**, zkontrolujte a pak **Schválit podklady**.
7. Po schválení mzdy zařaďte závazek **Povinné spoření u rizikové práce** do
   platební dávky v `Mzdy → Mzdové příkazy a úhrady`.

**Jak poznáte, že je hotovo:** Aplikace hlásí „Podklady byly schváleny
a zahrnou se do zmrazeného mzdového běhu." a v řádku je **Vypočtený
příspěvek** se splatností.

## 91.10 Krok za krokem: export vstupů do Excelu nebo PDF

1. Na záložce **Měsíční vstupy** nastavte filtr.
2. V souhrnném pruhu klikněte na **Excel** nebo **PDF**.

**Jak poznáte, že je hotovo:** Stáhne se soubor se všemi vstupy odpovídajícími
filtru, ne jen se zobrazenou stránkou.

## 91.11 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Složku náhrady za dovolenou nebo nemoc nejde zadat ručně | Vzniká ze schválené absence | Schvalte absenci v [Absence a dovolená](76_Absence_a_dovolena.md). |
| Převzatý příspěvek na stravování nejde zadat ručně | Osvobozenou část spočítal předchozí program ze svých směn | Zadejte příspěvek na složku `PRISPEVEK_STRAVOVANI`. |
| Příplatek za přesčas, svátek, noc nebo víkend nejde zadat | Vzniká ze schválené docházky | Schvalte měsíc docházky, nebo přesčas zadejte hodinami v rychlém vstupu. |
| Vstup z předpisu, docházky, absence nebo cesty nejde upravit | Opravuje se u svého zdroje | Upravte zdroj a vstup se přepočte. |
| Schválený benefitní vstup nejde upravit | Čerpání je zapsané v koši | Proveďte storno (opačný zápis) a zadejte správnou částku. |
| Hromadné schválení se v rozsahu Rok nebo Vše nenabízí | Schvaluje se vždy nad jedním obdobím | Přepněte **Rozsah** na *Měsíc*. |
| Schválení stravného neprojde | Docházkový podklad není úplný | Uzavřete docházku za měsíc. |
| Příprava JMHZ se zastaví na složce | Složka nemá zařazení do JMHZ, nebo je deaktivované | Klikněte na **Nastavit JMHZ** ([§ 91.8](#918-krok-za-krokem-zarazeni-slozky-do-jmhz)). |
| Mapování JMHZ pochází ze staršího balíku | Cíl už v aktuální specifikaci není | **Zrušit mapování** a zvolte cíl z aktuálního balíku. |
| Složku nebo předpis nejde smazat | Už vstoupily do mzdového vstupu nebo výpočtu | Ukončete platnost nebo složku deaktivujte. |
| Export hlásí zúžení filtru | PDF pojme nejvýš 5 000 vstupů, Excel 20 000 | Zužte filtr. |
| Záporná náhrada za dovolenou neprojde | Úhrn vztahu v měsíci by byl záporný, nebo jde o jiný měsíc | Použijte opravu původního běhu. |

## 91.12 Podrobnosti a pravidla

### 91.12.1 Kódy výchozích složek

Výchozí složky mají české kódy bez diakritiky, bezpečné pro CSV a strojové
zpracování, např. `MZDA_MESICNI`, `MZDA_HODINOVA`, `ODMENA`, `NAHRADA_MZDY`,
`NEPENEZNI_PRIJEM`, `PRISPEVEK_STRAVOVANI` a `CESTOVNI_NAHRADA`. Stejné kódy
patří do sloupce `component_code` importovaného souboru.

U nové vlastní složky se kód vytvoří z názvu bez diakritiky a sleduje změny
názvu, dokud ho ručně neupravíte. Po uložení už kód ani začátek platnosti změnit
nejde; další účinnost se zakládá jako nová verze.

### 91.12.2 Složky, které se nezadávají ručně

- **Náhrady vázané na schválenou absenci**: `NAHRADA_MZDY_DOVOLENA` (§ 222
  zákoníku práce) a `NAHRADA_MZDY_DPN` (§ 192) vznikají při schválení
  [absence](76_Absence_a_dovolena.md) z jejích hodin a zmrazeného průměrného
  výdělku; ruční částka by se rozešla s evidencí nároku. U nemoci jde i o daň:
  osvobozena je podle § 6 odst. 9 písm. p) zákona o daních z příjmů jen náhrada
  do výše minimálního zákonného nároku, takže sjednanou vyšší náhradu podle § 192
  odst. 3 zadejte jako běžnou zdanitelnou složku.
- **Zákonné příplatky** podle § 114 až § 118 (`PRIPLATEK_PRESCAS`,
  `PRIPLATEK_SVATEK`, `PRIPLATEK_NOCNI`, `PRIPLATEK_VIKEND`,
  `PRIPLATEK_ZTIZENE_PROSTREDI`) vznikají při schválení měsíce
  [docházky](77_Dochazka_a_smeny.md#7795-zakonne-priplatky-ke-mzde-114-az-118)
  z evidovaných hodin a příznaků, aby šel nárok doložit z mzdového listu (§ 142
  odst. 5 zákoníku práce). Výjimkou je přesčas zadaný hodinami v
  [rychlém měsíčním vstupu](79_Rychly_mesicni_vstup.md), který příplatkovou
  složku založí také. Sazbu berou z legislativní sady nebo sjednané zásady
  vztahu, ručně se nepřepisuje.

**Proplacená nebo vrácená náhrada za dovolenou** zadaná částkou má složku
`NAHRADA_MZDY_DOVOLENA_VYROVNANI` (Proplacená / vrácená náhrada za dovolenou).
Vyčerpal-li zaměstnanec dovolenou, na kterou mu právo nevzniklo, srazí se mu
náhrada (§ 147 odst. 1 písm. e) zákoníku práce): zadejte ji **zápornou částkou**
v měsíci srážky. Sníží hrubou mzdu, základ daně i pojistného a v hlášení náhrady
za dovolenou (10338). Běh ji přijme, zůstane-li úhrn vztahu v měsíci nezáporný;
jiná záporná částka nebo částka za jiný měsíc patří do opravy původního běhu.
Tuto složku zakládá i převod z PAMICA (složky J07 a J10) a sekce skončení vztahu
na kartě zaměstnance.

**Stravenkový paušál převzatý z jiného programu** (PAMICA Z21) má dvě složky.
Část do limitu za směnu (§ 6 odst. 9 písm. b) zákona o daních z příjmů) jde na
`PRISPEVEK_STRAVOVANI_PREVZATY`: nedaní se, není vyměřovacím základem pojistného
a v hlášení je v úhrnu příjmů (10286) i mezi osvobozenými příjmy (10289). Limit
spočítal předchozí program podle směn, které evidoval, proto složku zakládá jen
převod a ručně ji zadat nejde. Část nad limit a paušál bez osvobození (Z21a) jde
na `PRISPEVEK_STRAVOVANI_ZDANITELNY`, běžný zdanitelný příjem. Vlastní příspěvek
pro další měsíce zadávejte na `PRISPEVEK_STRAVOVANI`, u kterého aplikace
osvobozenou část spočítá ze směn v docházce.

**Nezdaněná náhrada převzatá z jiného programu** (PAMICA „Náhrada nezdaněná",
v číselníku bez daně a bez pojistného) jde na `NAHRADA_VYDAJU_PREVZATA`. Je to
náhrada výdajů, která není předmětem daně (§ 6 odst. 7 zákona o daních
z příjmů): vyplácí se nad čistou mzdu, není v hrubé mzdě, ve vyměřovacích
základech ani v úhrnech hlášení (10286, 10289). Jaký výdaj nahrazuje, export
nevede, proto složku zakládá jen převod a ručně ji zadat nejde. Vlastní
cestovní náhrady zadávejte vyúčtováním pracovní cesty.

### 91.12.3 Koncept, schválení a filtry

Jednorázový vstup vzniká jako **Koncept**, jen tak jde upravit i zrušit.
Schválení vstup zmrazí. Upravit jde i koncept z importu (např. docházky):
zaměstnanec, vztah, složka a externí identifikátor zůstávají podle zdroje,
měnit lze částku a množství. Vstupy z předpisu, docházky, absence nebo cesty se
opravují u svého zdroje. Stavy vstupu: **Koncept**, **Schválený**, **Uzamčený
během**, **Zrušený**. Po uzavření období vstup bez řízené opravy nepřepisujte.

Filtry (jméno nebo osobní číslo, zaměstnanec, složka, stav, zdroj, importní
dávka) se drží v adrese stránky, takže přežijí obnovení a jdou poslat odkazem.
**Zobrazení** seskupí vstupy **Podle zaměstnance** nebo **Podle složky** se
součty; **Otevřít v seznamu** udělá ze skupiny filtr.

Zúžíte-li seznam na jeden vztah (odkaz z karty zaměstnance), objeví se vedle
**Mzdové období** přepínač **Rozsah**: *Měsíc*, *Rok* a *Vše*. Drží se v adrese
a zrušení zúžení ho vrátí na *Měsíc*. Přibude zobrazení **Po obdobích** (řádek
na měsíc od nejnovějšího, **Otevřít měsíc** skočí do editovatelného období)
a sloupec **Období**. Export stáhne celý rozsah a hlavička i název souboru nesou
skutečné rozpětí. Hromadné schválení a zrušení se nad rozsahem nenabízí,
protože pracují nad jedním obdobím.

Souhrnný pruh ukazuje počet vstupů, součet částek a počet konceptů za **celý
filtr**. **Schválit … odpovídajících filtru** schválí všechny koncepty ve filtru
a schválené přeskočí; každý vstup prochází stejnými kontrolami jako jednotlivě
(roční limit benefitu, podklad docházky u stravného, složka k ručnímu
posouzení). Stejně funguje **Zrušit … konceptů**, vždy s potvrzením.
[Rychlý měsíční vstup](79_Rychly_mesicni_vstup.md) uloží řádky rovnou jako
schválené, má-li uživatel oprávnění.

**Export.** Sešit Excel má na listu **Vstupy** řádek na vstup (osobní číslo,
jméno, vztah, složka, množství, sazba, částka, stav, zdroj, importní dávka)
a součet; list **Info** nese firmu, období, filtr a čas exportu. Sazba je
dopočtená jako částka lomená množstvím. Poslední dva sloupce jsou skryté
a identifikují vstup, proto je list zamčený bez hesla: filtrovat jde, pro řazení
ho odemkněte (Revize, Odemknout list). PDF je tisková sestava na šířku podle
zaměstnanců s mezisoučty a rekapitulací podle složek. PDF pojme nejvýš 5 000
vstupů, Excel 20 000.

**Pravidelné předpisy** ukazují vždy celou historii bez ohledu na období;
přepínač rozsahu tam není. Stav *Platný*, *Naplánovaný*, *Ukončený*
a *Vypnutý* se určuje podle účinnosti, ne jen podle příznaku.

### 91.12.4 Klasifikace složky a snapshot

Každá složka samostatně určuje dopad do daně, sociálního a zdravotního
pojištění, průměrného výdělku, exekučního základu, JMHZ, statistiky
a účetnictví. Schválený vstup si uloží neměnný snapshot klasifikace, takže
pozdější změna katalogu nepřepíše zpracované období.

Osvobození od daně nenahrazuje zařazení do JMHZ. Náhrada mzdy při nemoci
a příspěvky na penzijní produkty nebo pojištění dlouhodobé péče potřebují
zařazení do odpovídajícího údaje hlášení, i když jsou osvobozené. Chybějící nebo
deaktivované zařazení použitých složek zastaví přípravu hlášení. Osvobozené
příspěvky na stravování, ubytování, vzdělávání, rekreaci a zdravotní benefity
se mohou vykázat jen v úhrnu příjmů bez zařazení do rozpadu mzdy.

### 91.12.5 Zařazení do JMHZ, předpisy a import

U složky zahrnuté do JMHZ nastavte konkrétní cílový atribut. Stav **Chybí
mapování** výpočtu mzdy nebrání, ale složku zatím nelze bezpečně převést do
hlášení. Prémie a odměny se v hlášení dělí na pravidelně měsíčně zúčtované
(10330, včetně pohyblivých složek mzdy) a nepravidelné (10331). Odměna
z pravidelného předpisu se zařadí do pravidelných sama. U odměny, která se
zadává za měsíc (ručně, importem docházky nebo převodem z jiného programu),
aplikace pravidelnost nezná, a když takovou složku nově založíte, zařazení
nechá na vás. Výchozí složka *Odměna* je zařazená mezi nepravidelné. Odměna,
kterou aplikace dřív sama zařadila mezi nepravidelné, zařazení drží dál, jen
u ní katalog i kontrola před mzdovým během ukážou upozornění **Ověřte
pravidelnost odměny**. Upozornění nic neblokuje. Zmizí, když v **Nastavit
JMHZ** zvolíte správné pole a uložíte, i když zůstane stejné. Celkové cíle používejte jen pro částky, které nejde zařadit do
detailního rozpadu; aplikace je viditelně odlišuje. Mapování lze auditovatelně
deaktivovat (**Zrušit mapování**) a teprve potom složku z JMHZ vyloučit
(**Vyloučeno**) nebo převést do ručního posouzení (**Ruční posouzení**). Samotné
mapování nevytváří XML ani nic neodesílá.

Pravidelný předpis má vlastní interval platnosti a zadává se pevnou částkou nebo
procentem. Účty MD a D se vybírají našeptávačem z aktivního účtového rozvrhu.
Procento zadávejte jako běžné procento a množství v přirozené jednotce; převod na
interní jednotky provede aplikace. Jednorázový vstup se nejdřív zkontroluje
a pak samostatně schválí.

Import odmítá nebezpečné sešity, vzorce a duplicitní řádky a před zápisem vždy
ukáže náhled. Přijímá CSV a XLSX do 5 MB a chybu ukáže u souboru. Stejný
ovládací prvek pro výběr souboru používá i import docházky
([Nastavení mezd](90_Nastaveni_mezd.md#901411-import-dochazky)). Opakované
nahrání stejného obsahu vrátí původní výsledek.

### 91.12.6 Mazání složek a předpisů

Omylem založenou vlastní složku nebo předpis smažete tlačítkem **Smazat**,
dokud nevstoupily do žádného mzdového vstupu, výpočtu ani jiné evidence. Smazání
se vždy potvrzuje. Po použití ho aplikace odmítne a vysvětlí, zda ukončit
platnost, nebo záznam deaktivovat; zpracovaná historie se nemaže.

### 91.12.7 Povinné spoření u rizikové práce

Panel slouží jen pro práce 3. kategorie, u nichž je rozhodným faktorem vibrace,
chlad, teplo nebo dynamická fyzická zátěž velkými svalovými skupinami (zákon
č. 324/2025 Sb.). Obecné označení vztahu jako rizikového nestačí: u každého
vztahu vyberte zákonný faktor a za měsíc zadejte počet rozhodných osmin směny.
Celá osmihodinová směna má osm osmin, u směny jiné délky se každá započatá
hodina počítá jako jedna osmina. Minimální rozsah směn, sazbu, účinnost
i splatnost určuje účinná legislativní sada; ručně se nepřepisují.

Účet penzijní společnosti je v katalogu šifrovaný. Do schváleného podkladu se
připne identifikátor, verze, otisk a maskovaná podoba účtu, takže pozdější změna
katalogu zmrazený běh nezmění. Identifikaci smlouvy, symboly a zprávu pro
příjemce zadejte podle pokynů penzijní společnosti; odkaz na podklad je
nepovinný.

**Právo uplatněno dne** určuje první měsíc nároku. Oznámí-li zaměstnanec údaje
během aktuálního měsíce, aplikace uloží stav bez příspěvku a nárok začne
následující měsíc. **Zaměstnanec informován dne** eviduje informační povinnost;
chybějící datum výpočet příspěvku (4 %) nezmění, běh ale upozorní.

Schválený záznam se nepřepisuje, oprava založí novou revizi a původní zůstane
v historii. Běh zmrazí použitou legislativní sadu s výsledkem, takže pozdější
změna nepřepíše sazbu, minimum směn, účinnost ani splatnost. Příspěvek se počítá
z nezastropovaného vyměřovacího základu a zaokrouhluje nahoru na koruny.
Chybí-li u historického běhu podklad nebo je poškozený, přepočet se zablokuje
k ručnímu posouzení; dnešní parametry se nikdy nedosadí.

V podvojném účetnictví se příspěvek účtuje jako zákonný sociální náklad proti
závazku vůči penzijní společnosti (výchozí 527 / 379). Zaměstnanci se nevyplácí
a penzijní společnost není institucí sociálního ani zdravotního pojištění,
proto nejde na 331 ani 336. Předkontaci změníte v
[Nastavení mezd](90_Nastaveni_mezd.md#90146-predkontace-pro-zvlastni-mzdove-situace).
Po schválení mzdy vznikne samostatný závazek v mzdových příkazech, který
zařadíte do ABO nebo SEPA dávky. Uhrazený je až po spárování bankovní transakce
nebo pokladního dokladu; v panelu se stav ručně nepřepíná.

### 91.12.8 Bezpečnost a časté chyby

Budoucí pravidelná složka čeká na účinnost, aktivní se použije pro rozhodné
období a ukončená zůstává v historii. Kontrolujte znaménko, jednotku, období,
vztah a klasifikaci plnění. Obecnou složku nepoužívejte k obcházení
nepodporovaného právního režimu. Odkaz na podklad je volitelný důkaz; hodnota,
období a druh plnění jsou skutečné vstupy.

Časté chyby:

- jednorázová složka zadaná jako pravidelná,
- vstup přiřazený jinému souběžnému vztahu,
- duplicitní import a ruční zadání stejné částky,
- změna vstupu bez nového výpočtu otevřeného běhu.

## 91.13 Související kapitoly

- [Rychlý měsíční vstup](79_Rychly_mesicni_vstup.md): hromadné zadání
- [Koše benefitů](89_Kose_benefitu.md): limity osvobození
- [Mzdové běhy](80_Mzdove_behy.md): výpočet
- [Absence a dovolená](76_Absence_a_dovolena.md) a [Docházka a směny](77_Dochazka_a_smeny.md)
- [Nastavení mezd](90_Nastaveni_mezd.md): předkontace a import docházky
