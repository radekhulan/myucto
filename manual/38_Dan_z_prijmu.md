# 38. Daň z příjmů (DPFO / DPPO)

Životní cyklus finálního XML, potvrzení podání a porovnání proti podanému souboru
shrnuje samostatná kapitola [Archiv podání a daňová
rekonciliace](89_Archiv_podani_a_rekonciliace.md).

V menu **Daně → Daň z příjmů** sestavíš **roční přiznání k dani z příjmů** z účetních dat
a stáhneš **validované XML pro EPO** (portál Moje daně):

- **DPFO** (fyzické osoby, formulář **DPFDP7**) — pro OSVČ; včetně **Přílohy 1 (§ 7)** a
  **Přehledů pojistného** (sociální ČSSZ + zdravotní pojišťovna).
- **DPPO** (právnické osoby, formulář **DPPDP9**) — pro s.r.o. / a.s.

> [!NOTE]
> Výpočty jsou **pomůckou pro poplatníka a účetní**. Sazby, minima a limity se každý rok
> mění — před podáním hodnoty ověřte a případně konzultujte s daňovým poradcem. Vygenerované
> XML se ověřuje proti oficiálnímu **XSD schématu** finanční správy a archivuje.

## 38.1 Workflow — 4 karty

Stránka vede od podkladů k exportu ve čtyřech kartách. Rozpracované přiznání se ukládá
(stav **Rozpracováno**), po dokončení jde **Uzamknout (finální)** — tím se zmrazí snímek
vypočtených řádků. Uzamčené přiznání lze zase **Odemknout**. Souběžnou editaci chrání
verzování (při konfliktu se stránka znovu načte).

### 38.1.1 1. Podklady

Automaticky načtená data ze systému:

- **DPPO:** výsledek hospodaření (Σ výnosy − Σ náklady mimo daň z příjmů, **bez uzávěrkových
   zápisů**), daňově **neuznatelné náklady dle § 25** (účty označené v osnově), **rozdíl
   daňových a účetních odpisů** a **daňová zůstatková cena vyřazeného majetku** (§ 24/§ 25).
- **DPFO:** dílčí základ **§ 7** — příjmy a výdaje z **kasové báze** (daňová evidence) nebo
  **výdajovým paušálem**. Každá činnost má vlastní název, CZ-NACE, sazbu a příjmy;
  příjmy činností musí přesně navazovat na příjem deníku. Zákonný strop se u činností
  se stejnou paušální sazbou uplatní jednou za celou skupinu a výdaj se mezi ně
  poměrně rozdělí. **Fyzická osoba s podvojným
  účetnictvím** má § 7 odvozený z **výsledku hospodaření** deníku (výnosy − náklady, § 23/2);
  mimoúčetní úpravy základu (nedaňové náklady, rozdíl odpisů) je nutné případně doplnit ručně.
  Osobní odpočty (§ 15), měsíční nároky na děti a manžela/manželku, invalidita,
  ZTP/P a měsíční režim OSVČ se spravují přímo v daňovém profilu. U dítěte se
  eviduje identita, pořadí, oprávněné měsíce a ZTP/P; neúplný nárok finalizaci zablokuje.

### 38.1.2 2. Úpravy a odpočty

Ruční položky, které systém nezná a které **přežijí mezi sezeními**:

- **DPPO:** odečet ztráty minulých let (§ 34), dary (§ 20/8) — buď souhrnně, nebo **položkově**
  (dary v hodnotě pod **2 000 Kč** se dle § 20/8 neodečtou a systém je vyloučí), přepočtený
  počet zaměstnanců se zdravotním postižením (sleva § 35), zaplacené zálohy na daň a volné
  položky § 23 zvyšující/snižující základ.
- **DPFO:** příjmy a sražené zálohy ze **závislé činnosti (§ 6)**, dílčí základy **§ 8/§ 9**
  a položkové druhy příjmů **§ 10** (výdaj se u každého druhu omezí jeho příjmem),
  (kapitál, nájem, ostatní), **odečet daňové ztráty minulých let (§ 34)** — uplatní se max do
  výše úhrnu § 7–§ 10 (ř. 41), zaplacené zálohy na daň i na **pojistné** (sociální/zdravotní).

#### Daňové ztráty (§ 34)

Na kartě **Úpravy a odpočty** je přehled **Daňové ztráty**: každá ztráta z minulých let
(rok vzniku, stanovená výše, kolik už bylo uplatněno, zbývající zůstatek a **rok expirace** =
rok vzniku + 5). Ztráta vzniká **automaticky** při finalizaci přiznání se záporným základem
(FO i PO) a v následujících **5 obdobích** ji lze uplatnit. Systém nabídne **návrh uplatnění
(FIFO** — od nejstarší ztráty) tlačítkem **Uplatnit návrh**; uplatnění se eviduje k roku
uplatnění (při vrácení přiznání do rozpracovaného stavu se automaticky uvolní). Poplatník
uplatňující ztrátu přikládá k přiznání **samostatnou přílohu podle § 34 odst. 1**.

### 38.1.3 3. Náhled přiznání

Tabulka řádků formuláře (číslo řádku, popis, hodnota, zdroj) + **celková daň**,
**doplatek/přeplatek** a u DPPO **předpis záloh na další období** (§ 38a, prahy 30/150 tis. Kč).
Nad tabulkou se zobrazují **upozornění** (chybějící FÚ, nadlimitní dary, daňová ztráta k převodu…).

### 38.1.4 4. Export

- **Náhled XML** — pracovní XML rozpracovaného přiznání; nearchivuje se a není určeno
  k podání.
- **Stáhnout XML** — ostré DPPDP9 / DPFDP7 ověřené proti XSD a obsahovým kontrolám.
  U **DPFO** je ostrý export dostupný jen z finalizovaného neměnného snapshotu a
  opakované stažení vrátí stejné uložené XML. U **DPPO** se XML sestavuje z aktuálních
  účetních dat i po finalizaci; po uzavření účetnictví proto data neměň a podávanou
  kopii vždy archivuj. Soubor nahraješ na
  [mojedane.gov.cz](https://mojedane.gov.cz) přes „Načtení souboru". Ostrý export se
  **archivuje** a po dokončení stažení aplikace otevře **Nástroje → EPO podání
  a archív**. Tam lze snapshot předat do
  předvyplněného formuláře EPO a po odeslání k němu přetáhnout XML a potvrzení.
  Dokumenty DPFO a DPPO se ukládají pod samostatně konfigurovatelný kořen
  **Daň z příjmů** a dále podle roku a typu formuláře.
- **DPFO — Pojistné:** karta **sociálního** a **zdravotního** pojištění OSVČ — vyměřovací
  základ, pojistné, doplatek po zálohách, **nová měsíční záloha**, případně **nemocenské**
  (dobrovolné). Tlačítka: **PDF přehledů** (souhrnná pomůcka), **XML ČSSZ** (validovaná datová
  věta) a **Přehled pro ZP** — PDF „Přehled OSVČ pro zdravotní pojišťovnu" ve struktuře
  oficiálního formuláře (výběr pojišťovny dle kódu a číslo pojištěnce z **Nastavení firmy**).
  Oba přehledy vycházejí ze stejného základu § 7, ale používají vlastní sazby, minima
  a pravidla. Shodný zdroj proto neznamená shodný vyměřovací základ ani částku.

## 38.2 Jak se počítá DPFO

Výpočet odděluje dílčí základy § 6, § 7, § 8, § 9 a položkové § 10. Výdaj u každého
druhu § 10 je nejvýše jeho příjem. Záporný úhrn § 7 až § 10 může vytvořit daňovou
ztrátu, ale nesnižuje dílčí základ § 6. Ztrátu minulých let lze odečíst jen od kladného
úhrnu § 7 až § 10.

Od základu se následně odečtou položky § 15. Úroky z bytové potřeby používají roční
limit podle data obstarání a počtu měsíců; penzijní produkty, soukromé životní pojištění,
DIP a pojištění dlouhodobé péče sdílejí zákonný roční limit. Do pole penzijního příspěvku
se nezadává hrubá roční platba, ale rovnou odčitatelná částka z ročního potvrzení penzijní
společnosti — tedy příspěvek snížený o částky připadající na měsíce, kdy nepřevýšil hranici
pro maximální státní příspěvek; systém dál pracuje jen s touto (již sníženou) hodnotou.
Dary musí splnit spodní hranici a souhrnný procentní strop. Základ po odpočtech se zaokrouhlí
dolů na celé stokoruny, daň v pásmech 15/23 % se zaokrouhlí nahoru na celé Kč.

Sleva na poplatníka je roční. Manžel/manželka, invalidita, ZTP/P a děti se posuzují
podle zadaných měsíců a podmínek; ZTP/P zdvojnásobuje příslušný nárok. U dětí záleží
také na pořadí a daňový bonus má vlastní příjmový test. Systém nekontroluje pravost
doložených potvrzení — jejich existenci pouze vyžaduje jako podklad finalizace.

U daňové evidence vstupují do § 7 příjmy a skutečné výdaje peněžního deníku,
potvrzené daňové odpisy a nepeněžní zvýšení či snížení z roční uzávěrky. Pokud deník
selže, náhled může zobrazit nouzový fakturační součet, ale finalizace je zablokovaná.
U podvojného účetnictví FO vychází § 7 z účtovaných výnosů a nákladů; rozdíl účetních
a daňových odpisů a neobvyklé mimoúčetní úpravy je nutné prověřit ručně.

## 38.3 Jak se počítá DPPO

Výchozí řádek 10 je výsledek hospodaření z účtů 6xx minus 5xx bez daně z příjmů a bez
technických uzávěrkových zápisů. Základ upravují nedaňové náklady, ruční položky § 23,
rozdíl daňových a účetních odpisů a rozdíl zůstatkových cen vyřazeného majetku.
Následují ztráty, dary a slevy. Základ se před sazbou zaokrouhluje dolů na celé tisíce
Kč; jednotlivé zálohy na další období se zaokrouhlují nahoru na celé stokoruny.

> [!WARNING]
> Současný filtr nerozlišuje vlastní technické uzavření knih od ostatních zápisů se
> zdrojem `closing`: vyloučí i výsledkové zápisy skladové uzávěrky. Firma se zapnutým
> skladem proto musí před finalizací DPPO i DPFO v podvojném účetnictví porovnat řádek
> výsledku hospodaření s výsledovkou a daňový dopad skladu doplnit nebo vysvětlit
> ručně. Úspěšná XSD validace tuto obsahovou mezeru neodhalí.

Panel uzávěrkových návrhů může upozornit na neodpisovaný drobný majetek, časové rozlišení,
kurzové rozdíly, rezervy nebo dohadné položky. Je pouze projekcí: do DPPO vstoupí až
řádně zaúčtované položky a schválené ruční daňové úpravy. Účetní závěrku dokonči podle
[kapitoly Účetní závěrka](87_Uzaverka.md).

## 38.4 Zálohy na daň a pojistné

V kartě **Export** je pod přehledy panel **Zálohy na daň a pojistné**. Z **finalizovaného
řádného** přiznání se automaticky (nebo tlačítkem **Vygenerovat předpisy**) založí předpisy
záloh na **příští rok**:

- **DPPO — daň § 38a:** dle poslední známé daňové povinnosti (ř. 340) — **žádné** zálohy do
  30 000 Kč, **pololetní** (40 %) do 150 000 Kč, **čtvrtletní** (25 %) nad 150 000 Kč. Splatnost
  15. den příslušného měsíce. U kalendářního roku jsou pololetní zálohy splatné 15. 6. a
  15. 12.; čtvrtletní 15. 6., 15. 9., 15. 12. a 15. 3. následujícího roku. Březnová záloha
  před podáním přiznání ještě patří do předchozího zálohového období. Při prodloužené lhůtě
  do července začíná nový harmonogram až zářijovou, resp. prosincovou zálohou.
- **OSVČ (DPFO) — sociální a zdravotní:** nové **měsíční** zálohy dle přehledů pojistného.
  Nová výše se použije až od měsíce podání přehledu; do té doby platí dosavadní výše,
  nejméně aktuální zákonné minimum. Sociální je splatná do konce kalendářního měsíce,
  zdravotní do **8. dne** následujícího měsíce.
- **OSVČ (DPFO) — daň § 38a:** stejné prahy 30/150 tis. Kč jako u DPPO, ale výše
  se krátí podle podílu příjmů ze závislé činnosti (§ 6). Při podílu alespoň 50 %
  zálohy nevzniknou; při podílu 15–50 % se počítají v poloviční výši.

Tlačítko **Spárovat platby** najde v **bankovních výpisech** odchozí úhrady podle **variabilního
symbolu**, částky, data a vlastnictví účtu (daň = kmenová část DIČ, sociální = VS ČSSZ,
zdravotní = číslo pojištěnce), označí
odpovídající předpisy jako **zaplacené** a **předvyplní** zaplacené zálohy do rozpracovaného
přiznání / přehledu daného roku. Nejbližší splatnosti ukazuje i **widget na Přehledu** (dashboard).
Zálohy se **nepárují na doklady**, jen na bankovní pohyby — proto je nutné mít naimportované výpisy.

> 🛈 QR platba záloh není součástí — předpis slouží jako plánovací a párovací pomůcka.

## 38.5 Předfinalizační kontrola („závěrková kontrola účetní")

Než přiznání **finalizuješ**, systém nad kartami zobrazí panel **Předfinalizační kontrola** — sadu
kontrol, které dělá zkušená účetní ručně, aby se do XML nedostala tichá chyba. Každá kontrola má
stav **OK / upozornění / bloker** (nebo **nerelevantní** tam, kde se netýká daného typu poplatníka),
u problémů rovnou ukáže **částky a prokliknutelný rozpad**:

- **Účetní období uzavřeno** — období roku má být `uzavřené`/`schválené`, ne otevřené.
- **Obrat účtu 551 = účetní odpisy** — zaúčtované účetní odpisy (551) musí odpovídat odpisům
  evidovaným v modulu majetku; rozdíl = odpisy nezaúčtované nebo zaúčtované ručně jinou částkou
  (řádek 50/150 by pak zkreslil základ).
- **Obrat účtu 543 = zadané dary (§ 20/8)** — dary na účtu 543 musí sedět s dary zadanými do přiznání.
- **VH přiznání = VH výsledovky** — výsledek hospodaření z přiznání se porovná s výsledovkou
  (nezávislá cesta výpočtu přes výkaz zisku a ztráty); rozdíl signalizuje chybu v mapování osnovy.
- **Nedaňové účty s obratem (ř. 40)** — informativní výčet nedaňových účtů s nenulovým obratem
  a částkami (drill-down do deníku), ať máš jistotu o řádku 40.
- **Archivní pokrytí DPH za rok** — u plátce kontrola, že za všechna měsíční nebo
  čtvrtletní období existuje archivní záznam DPH. Současná kontrola nerozlišuje stažené
  a skutečně odeslané XML, proto je jen upozorněním a nenahrazuje doručenky z EPO.

U DPFO jsou chyby, které by vedly k neúplnému nebo nesprávnému podání, skutečnými
**blokery**: nedokončená roční uzávěrka daňové evidence, nezařazený příjem, bankovní
úhrada mimo vlastněný výpis, chyba peněžního deníku, nevyřešený přechod § 23 odst. 8,
neúplné osoby, činnosti nebo měsíce OSVČ. Výsledek kontrol se ukládá do neměnného
snapshotu. Informativní kontroly a upozornění, která neovlivňují zákonnou úplnost,
zůstávají poradní.

## 38.6 Daňová (ne)uznatelnost nákladů (§ 25) — DPPO

Nedaňové náklady se u DPPO poznají podle příznaku **Daňová uznatelnost** na účtu v
[účtovém rozvrhu](81_Ucetni_osnova.md). Šablona rovnou označí jako nedaňové syntetiky
**513** (reprezentace), **528** (ostatní sociální), **543** (dary), **545** (pokuty a
penále), **549** (manka nad náhrady), **554** (účetní rezervy), **559** (účetní opravné
položky). Analytiky **dědí** příznak ze syntetiky; ručně jej lze změnit. Odpisy (551) a
daň z příjmů (59x) se neflagují — řeší se vlastní mechanikou (rozdíl odpisů, resp. vyloučení
z výsledku hospodaření).

## 38.7 Příjem mimo základ daně z příjmů (osvobozený / přefakturace)

Některé **vydané** doklady nejsou základem daně z příjmů — typicky:

- **Prodej movité věci osvobozený dle § 4 odst. 1 písm. c) ZDP** — např. vozidlo prodané po
  více než 1 roce od nabytí; u OSVČ na paušálu, kde věc nebyla v obchodním majetku, neběží
  ani 5letý test po vyřazení.
- **Přefakturace / průběžné položky** (§ 23 odst. 4 ZDP) — částka, která není ani příjmem,
  ani výdajem.

U takové faktury zaškrtni v editoru **„Osvobozeno od daně z příjmů"** (volitelně doplň důvod).
Příznak **nezahrne částku do základu daně z příjmů** (výkaz i optimalizátor; osvobozená část
se ukáže odděleně) a **nedotkne se DPH** — doklad zůstává v přiznání DPH i v obratu beze změny.

### 38.7.1 Souvislost se sociálním a zdravotním pojištěním (OSVČ)

U OSVČ se **vyměřovací základ** pojistného odvozuje z **daňového základu § 7**. Když částka
nevstoupí do základu daně z příjmů, zmizí i z vyměřovacího základu SP a ZP — jeden příznak
sedí na daň i na pojistné.

| Veličina | Co znamená | OSVČ |
|---|---|---|
| **Vyměřovací základ** | z čeho se pojistné počítá | 55 % (SP) / 50 % (ZP) daňového základu § 7, nejméně roční **minimum**; u SP nejvýše 48× průměrná mzda |
| **Sazba odvodu** | kolik se odvádí | SP 29,2 %, ZP 13,5 %, nemocenské 2,7 % (drží roční daňové konstanty) |

> 🛈 Snížení pojistného se projeví **jen nad rámec minimálního vyměřovacího základu** — OSVČ
> na zákonném minimu osvobozením příjmu na pojistném neušetří. U **s.r.o.** se SP/ZP z obratu
> netýká; příznak tam ovlivní jen základ DPPO. Vedlejší činnost pod **rozhodnou částkou**
> neplatí sociální pojistné vůbec.

## 38.8 Opravné a dodatečné přiznání (§ 141 DŘ)

Přepínačem **Řádné / Opravné / Dodatečné** nahoře zvolíš druh přiznání; každý druh je samostatný
záznam za totéž období.

- **Opravné** — plná náhrada řádného přiznání **před uplynutím lhůty** k podání (jen jedno).
- **Dodatečné** — po lhůtě, počítá se **rozdílově** proti **poslední známé dani**. Za jedno období
  jich lze podat **víc** (dodatečné č. 1, č. 2, …). Pod přepínačem se u dodatečného zobrazí výběr
  **pořadí (č. N)**, tlačítko **Nové dodatečné** a **časová osa** dosavadních podání za období
  (stav, kdy bylo změněno/podáno).

**Poslední známá daň** se odvozuje z **naposledy pravomocně stanovené daně** (§ 141 odst. 1 DŘ) —
tedy z posledního finalizovaného přiznání v řetězu **řádné → opravné → dodatečné č. 1 → č. 2 → …**
Systém ji u nového dodatečného předvyplní (lze ručně přepsat); rozdíl proti nově zjištěné dani se
promítne do V. oddílu formuláře (u DPPO řádky iv1/iv2/iv3). Dodatečné přiznání č. N tedy vždy
navazuje na to předchozí, ne na řádné.

### 38.8.1 Kontrola proti skutečně podanému DPPO

U DPPO lze v kartě Export nahrát XML DPPDP9, které bylo skutečně podáno účetní nebo
upraveno na EPO. Aplikace nejprve zkontroluje typ formuláře a rok a potom porovná
formulářové řádky s aktuálním výpočtem. Zobrazí shody, rozdíly a hodnoty přítomné jen
v podaném souboru. Jde o read-only kontrolu: nahrání nic nezaúčtuje, nepřepíše přiznání
a soubor samo neoznačí jako přijatý finanční správou. Importní rekonciliace
skutečně podaného DPFO, DPHDP3 a KH není k dispozici.

## 38.9 Roční uzávěrka daňové evidence a snapshot DPFO

DPFO ze skutečných výdajů nelze finalizovat bez dokončené roční uzávěrky podle § 7b.
Kontrolní seznam pokrývá deník, nepeněžní operace, majetek, zásoby, pohledávky,
závazky, vysoké nákupy, změny režimu a cizí měny. Zadávají se počáteční a konečné
stavy majetku, hotovosti, banky, zásob, pohledávek, ostatních aktiv, dluhů a rezerv.

Nepeněžní úpravy mohou být například zápočet, barter, naturální příjem, prominutý dluh,
soukromá spotřeba, manko, škoda nebo jiná úprava § 23. Směr **zvýšení/snížení** přímo
ovlivní § 7; volba **neutrální** pouze uloží auditní stopu. Aplikace právní směr neurčuje.
Finalizace uzávěrky vyžaduje všechny kontroly, vypořádaný roční koeficient § 76, vyřešené
blokery deníku a posouzené nadlimitní nákupy. Checklist potvrzuje provedení inventury,
nenahrazuje její fyzické podklady.

Finální DPFO uloží výpočet, podklady, kontroly, seznam zdrojových pohybů, XML a jejich
kontrolní otisky. Oprava podkladů vyžaduje znovu otevřít uzávěrku i přiznání a vytvořit
nový snapshot. Podrobnosti jsou v [Daňové evidenci](90_Danova_evidence.md#9083-rocni-uzaverka-danove-evidence).

## 38.10 Pojistné OSVČ — důležitá omezení

Měsíční profil eviduje aktivní, hlavní, vedlejší a přerušenou činnost a příznak, zda
se v daném měsíci uplatní minimum zdravotního pojištění. Tato data se používají při
orientačním výpočtu minim. Sociální vyměřovací základ vychází z 55 % základu § 7,
zdravotní z 50 %, oba s příslušnými minimy a maximem; vyměřovací základ i pojistné se
zaokrouhlují nahoru na celé Kč.

Současné XML ČSSZ však umí pouze jeden režim hlavní/vedlejší činnosti pro celý rok.
Neodesílej je bez ručního dokončení, pokud činnost začala, skončila či byla přerušena,
střídala hlavní a vedlejší režim, existovalo zaměstnání ovlivňující maximální základ,
nebo jde o novou OSVČ či jiný zvláštní režim. Pole `state_insured`, `employed`,
`new_osvc` a individuální vyměřovací základ nejsou do konečného pojistného zapojeny
ve všech zákonných kombinacích. Zdravotní přehled je pouze PDF pomůcka bez jednotného
podávacího XML. V těchto případech přepiš údaje do formuláře instituce a ověř je.

## 38.11 Termíny podání

- **Daň z příjmů FO (bez poradce):** **1. 4.** následujícího roku · **elektronicky / s poradcem:** **2. 5.**, resp. **1. 7.**
- **Daň z příjmů PO:** dle účetního období (řádně 1. 4., s auditem/poradcem 1. 7.)
- **Přehledy pojistného OSVČ:** do **1 měsíce** po lhůtě pro daňové přiznání.

## 38.12 Rozsah a co ještě není

Pokrývá **řádné, opravné i dodatečné** přiznání DPPO (s.r.o./a.s., kalendářní rok i
**hospodářský rok**, česká rezidence) a DPFO (OSVČ § 7 automaticky; § 6 z potvrzení
zaměstnavatele, § 8/§ 9/§ 10 jako ruční vstupy), pojistné OSVČ vč. nemocenského, XML
validované proti XSD a **e-podání pro ČSSZ** (Přehled OSVČ jako validovaná XML datová věta
k nahrání na ePortál ČSSZ / do datové schránky).

Pojistné (sociální i zdravotní) se v přehledech zaokrouhluje **na celé koruny nahoru**.
Shodu s datovou větou ČSSZ lze očekávat jen u podporovaného celoročního režimu.

Přehled pro **zdravotní pojišťovny** je pouze PDF pomůcka, protože pojišťovny
nemají jednotné veřejné schéma. Přímé odeslání na EPO ani ePortál ČSSZ tato
obrazovka neprovádí: XML stáhni a nahraj na mojedane.gov.cz, resp. ePortál ČSSZ.

Generátor nepokrývá všechny zahraniční příjmy a zápočty zahraniční daně, spolupracující
osoby, všechny zvláštní přílohy ani neobvyklé režimy. Pokud náhled nebo XSD projde,
znamená to strukturální a implementovanou obsahovou kontrolu, nikoli potvrzení věcné
správnosti každého daňového případu. Chybějící případ dokonči v EPO a archivuj právě
finální odeslanou verzi.
