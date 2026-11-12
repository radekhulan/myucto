# 43. Daň z příjmů (DPFO / DPPO)

> Návod, jak z účetních dat sestavit roční přiznání k dani z příjmů, zkontrolovat
> ho a stáhnout XML pro EPO. Pro OSVČ (DPFO, formulář DPFDP7) i pro s.r.o. a a.s.
> (DPPO, formulář DPPDP9) a pro účetní, která za ně přiznání připravuje.

Životní cyklus finálního XML, potvrzení podání a porovnání proti podanému souboru
shrnuje samostatná kapitola [Archiv podání a daňová
rekonciliace](49_Archiv_podani_a_rekonciliace.md).

## 43.1 Kdy to potřebujete

- Končí rok nebo zdaňovací období a potřebujete roční přiznání k dani z příjmů.
- Jste OSVČ a potřebujete spolu s přiznáním i přehledy pojistného pro ČSSZ a zdravotní pojišťovnu.
- Chcete znát odhad daně před uzávěrkou nebo plánované zálohy na další období.
- Zjistili jste chybu v podaném přiznání a potřebujete opravné nebo dodatečné.
- Máte podané přiznání z jiného programu a chcete ho porovnat s účetnictvím nebo převzít jeho vstupy.
- Platíte nerezidentovi licenční poplatek, dividendu nebo úrok a musíte podat oznámení do zahraničí.
- Chcete vyloučit z daně z příjmů osvobozený příjem nebo přefakturaci.

<!-- cols: 26 40 34 -->
| Kdy | Co udělat | Kde v aplikaci |
|---|---|---|
| po uzavření roku | Sestavit přiznání, projít kontroly, uzamknout, stáhnout XML a podat | `Daně → Daň z příjmů` |
| 1. 4. následujícího roku | Podat přiznání FO (bez poradce) a PO (řádně, podle účetního období) | viz [§ 43.11.17](#431117-terminy-podani) |
| 1. 5. (v roce 2026 4. 5.) | Podat přiznání FO elektronicky | `Daně → EPO podání a archív` |
| 1. 7. | Podat přiznání se zastoupením daňovým poradcem nebo advokátem | viz [§ 43.11.17](#431117-terminy-podani) |
| měsíc po lhůtě k přiznání | Podat přehledy pojistného OSVČ | karta **Export**, část **Pojistné OSVČ (Přehledy)** |
| po finalizaci řádného přiznání | Vygenerovat zálohy na příští rok a párovat platby | karta **Zálohy na daň a pojistné** |
| při každé platbě nerezidentovi | Podat oznámení podle § 38da, případně hlášení podle § 38e | `Daně → Příjmy do zahraničí` |

## 43.2 Než začnete

- Mějte zaúčtované doklady a uzavřené nebo schválené účetní období roku (u DPPO). U DPFO z daňové evidence dokončete roční uzávěrku (viz [Daňová evidence](74_Danova_evidence.md#7499-rocni-uzaverka-danove-evidence)).
- U DPPO dokončete účetní závěrku (viz [Účetní závěrka](72_Uzaverka.md)).
- U účtů nákladů mějte na účtovém rozvrhu správně nastavenou daňovou uznatelnost (viz [Účtová osnova](66_Ucetni_osnova.md)).
- V `Nastavení → Daně a účetnictví` zkontrolujte část **Přiznání k dani z příjmů - povaha poplatníka**. Výchozí hodnoty odpovídají běžné firmě a OSVČ, takže je ponechte, pokud se vás netýkají (viz [§ 43.11.10](#431110-situace-ktere-aplikace-v-priznani-neumi)).
- U OSVČ můžete v `Nastavení → Daně a účetnictví`, části **Osobní údaje podnikatele**, doplnit titul, jméno a příjmení. Titul je nepovinný; použije se také v XML přiznání DPFO a v XML přehledu pro ČSSZ.
- Chcete-li prodloužení lhůty, vyplňte zastoupení daňovým poradcem v `Nastavení → Daně a účetnictví`, část **Zastoupení a podepisující osoba**.
- Pro párování záloh potřebujete naimportované bankovní výpisy.
- Pro stažení XML a PDF potřebujete oprávnění k exportu výkazů.

> [!WARNING]
> Výpočty jsou pomůckou pro poplatníka a účetní. Sazby, minima a limity se každý rok mění. Před podáním hodnoty ověřte a případně konzultujte s daňovým poradcem. Vygenerované XML se ověřuje proti oficiálnímu XSD schématu finanční správy a archivuje.

DPPO pro účetní rámec 504 vychází z potvrzeného právního profilu a daňového
posouzení konkrétního účetního období. Aplikace samostatně ukazuje dostupnost
výpočtu, finalizace, XML a podání. Období začínající v roce 2026 lze v rozsahu
ověřených pravidel vypočítat, dostupný formulář DPPDP9 05.01.01 však jeho
finalizaci a podání neumožňuje. Historická období s rámcem 500 zůstávají
dostupná i po změně současného rámce firmy. Rozhoduje rámec příslušného období.

## 43.3 Krok za krokem: sestavení a podání přiznání

1. Otevřete `Daně → Daň z příjmů`.
2. Nahoře zvolte **DPFO** nebo **DPPO** (nabízí se jen typ odpovídající vaší firmě), druh **Řádné** a rok. Vedle roku vidíte zdaňovací období a stav **Rozpracováno** nebo **Finální**.
3. Prohlédněte si upozornění nad kartami: **Situace, které aplikace v přiznání neumí** a **Předfinalizační kontrola**. Nález si přečtěte a vyřešte nebo ověřte (viz [§ 43.10](#4310-kdyz-neco-nejde)).
4. Na kartě **Podklady** zkontrolujte načtená data. U DPPO je to výsledek hospodaření, nedaňové náklady, rozdíl odpisů a vyřazený majetek. U DPFO příjmy a výdaje § 7, případně činnosti.
5. Na kartě **Úpravy a odpočty** doplňte ruční položky, které systém nezná: ztrátu, dary, odečty, slevy, zálohy, příjmy podle § 6, § 8 až § 10 (u DPFO). Klikněte na **Uložit**.
6. Na kartě **Náhled přiznání** projděte řádky formuláře (číslo řádku, popis, hodnota, zdroj), **celkovou daň** a **doplatek nebo přeplatek**. U DPPO uvidíte i předpis záloh na další období.
7. Klikněte na **Uzamknout (finální)**. Tím se zmrazí snímek vypočtených řádků.
8. Na kartě **Export** klikněte na **Stáhnout XML**. XML se ověří proti XSD a uloží do archivu.
9. Soubor nahrajte na [mojedane.gov.cz](https://mojedane.gov.cz) přes „Načtení souboru" nebo ho předejte do předvyplněného formuláře EPO z archivu podání a podání odešlete.
10. Přetáhněte do archivu odeslané XML a potvrzení a záznam označte jako podaný (viz [Archiv podání](49_Archiv_podani_a_rekonciliace.md)).

**Jak poznáte, že je hotovo:** Přiznání má stav **Finální**, v `Daně → EPO podání a archív` je záznam s XML a máte uložené potvrzení z portálu.

> [!TIP]
> Chcete-li přiznání zkontrolovat s účetní, klikněte na **PDF sestava**. Je to pracovní sestava, ne tiskopis pro finanční úřad. Nic se tím neuzamyká ani neukládá do archivu.

K dispozici je i **Pracovní XML** pro rozpracované přiznání. Nearchivuje se a není určeno k podání. Podrobnosti o kartách a exportu jsou v [§ 43.11.1](#43111-karty-a-stavy-priznani) a [§ 43.11.5](#43115-export).

Rozpracované přiznání se ukládá. Uzamčené přiznání lze znovu **Odemknout**. Souběžnou editaci chrání verzování: při konfliktu se stránka znovu načte.

## 43.4 Krok za krokem: opravné nebo dodatečné přiznání

Použijte, když potřebujete změnit už podané přiznání (§ 141 daňového řádu).

1. Otevřete `Daně → Daň z příjmů` a zvolte typ a rok.
2. V přepínači nahoře zvolte **Opravné** (před uplynutím lhůty k podání, jen jedno) nebo **Dodatečné** (po lhůtě). Každý druh je samostatný záznam za totéž období.
3. U dodatečného se pod přepínačem zobrazí výběr pořadí **Dodatečné č. N**. Další dodatečné založíte tlačítkem **Nové dodatečné**. Časová osa ukazuje dosavadní podání za období se stavem a datem.
4. Zkontrolujte předvyplněnou **Poslední známou daň** a podle potřeby ji přepište. Vyplňte **Datum zjištění** a **Důvody podání**.
5. Dokončete podklady, úpravy a náhled jako u řádného přiznání, uzamkněte a stáhněte XML (viz [§ 43.3](#433-krok-za-krokem-sestaveni-a-podani-priznani)).

**Jak poznáte, že je hotovo:** V náhledu je přehled **Dodatečné přiznání - přehled** s nově zjištěnou daní, poslední známou daní a rozdílem a záznam v archivu je označený jako podaný.

Pravidla výpočtu poslední známé daně jsou v [§ 43.11.13](#431113-opravne-a-dodatecne-priznani-141-dr).

## 43.5 Krok za krokem: zálohy na daň a pojistné

1. Finalizujte řádné přiznání za rok (viz [§ 43.3](#433-krok-za-krokem-sestaveni-a-podani-priznani)).
2. Otevřete kartu **Zálohy na daň a pojistné**. Předpisy záloh na příští rok se založí automaticky, případně je vyvoláte tlačítkem **Vygenerovat předpisy**. Zálohy pro rok bez finalizace minulého přiznání vytvoříte tlačítkem v části **Zálohy pro tento rok**.
3. Změnil-li výši záloh finanční úřad rozhodnutím, zadejte ho na kartě **Rozhodnutí FÚ o zálohách** (viz [§ 43.11.8](#43118-zalohy-na-dan-a-pojistne)).
4. Po úhradě záloh klikněte na **Spárovat platby**. Aplikace v bankovních výpisech najde odchozí úhrady, označí předpisy jako zaplacené a předvyplní zaplacené zálohy do rozpracovaného přiznání nebo přehledu daného roku.
5. Platbu, kterou párování nenašlo, potvrďte ručně tlačítkem **Potvrdit úhradu** u předpisu. Všechny najednou potvrdíte tlačítkem **Vše zaplaceno**.

**Jak poznáte, že je hotovo:** Předpisy mají stav **Zaplaceno** nebo **Uhrazeno**. Nejbližší splatnosti vidíte také na widgetu **Nadcházející zálohy na daň a pojistné** na Přehledu.

> [!TIP]
> Zálohy se nepárují na doklady, jen na bankovní pohyby. Bez naimportovaných výpisů je nespárujete.

## 43.6 Krok za krokem: pojistné OSVČ

1. V `Daně → Daň z příjmů` zvolte **DPFO** a rok.
2. Otevřete kartu **Export** a v části **Pojistné OSVČ (Přehledy)** klikněte na **Spočítat** (po změně podkladů na **Přepočítat**).
3. Zkontrolujte u sociálního (ČSSZ) a zdravotního pojištění vyměřovací základ, pojistné, doplatek po zálohách, **novou měsíční zálohu** a případně nemocenské.
4. Stáhněte výstup: **Stáhnout PDF přehledů** (souhrnná pomůcka), **Stáhnout XML ČSSZ** (validovaná datová věta) nebo **Přehled pro ZP** (PDF ve struktuře oficiálního formuláře). Pojišťovnu podle kódu a číslo pojištěnce aplikace bere z **Nastavení firmy**.
5. XML nahrajte na ePortál ČSSZ nebo do datové schránky. Přehled pro zdravotní pojišťovnu přepište do formuláře pojišťovny.

**Jak poznáte, že je hotovo:** Máte odeslaný přehled pro ČSSZ i pro zdravotní pojišťovnu a v bance nastavenou novou měsíční zálohu.

> [!WARNING]
> XML ČSSZ umí jen jeden režim hlavní nebo vedlejší činnosti na celý rok. Při změně režimu, přerušení činnosti, zaměstnání nebo nové OSVČ údaje ručně dokončete (viz [§ 43.11.16](#431116-pojistne-osvc-dulezita-omezeni)).

## 43.7 Krok za krokem: kontrola proti podanému DPPO a převzetí vstupů

Použijte u firmy převedené z jiného programu nebo od účetní kanceláře, která má podaná přiznání za minulé roky.

1. Otevřete `Daně → Daň z příjmů`, zvolte **DPPO** a rok, který chcete porovnat.
2. Na kartě **Export** v části **Rekonciliace proti podanému přiznání** vyberte soubor s XML skutečně podaného přiznání DPPDP9.
3. Klikněte na **Porovnat**. Uvidíte shody, rozdíly a hodnoty přítomné jen v podaném souboru.
4. Chcete-li převzít vstupy, klikněte na **Náhled převzetí do vstupů** a zkontrolujte navržené vstupy.
5. Klikněte na **Převzít do vstupů přiznání** a potvrďte případné přepsání ručních položek.
6. Roky převádějte od nejstaršího.

**Jak poznáte, že je hotovo:** Aplikace potvrdí, že vstupy přiznání byly převzaty. Řádky ze vstupů pak sedí na podání a případné rozdíly zbývají jen na řádcích spočtených z účetnictví.

Pravidla převzetí a hromadný převod více let jsou v [§ 43.11.14](#431114-prevzeti-podaneho-dppo-do-vstupu-priznani).

## 43.8 Krok za krokem: osvobozený příjem a přefakturace

Použijte u vydaných dokladů, které nejsou základem daně z příjmů (osvobozený prodej movité věci, přefakturace).

1. Otevřete vydanou fakturu v editoru.
2. Zaškrtněte **Osvobozeno od daně z příjmů** a volitelně doplňte důvod.
3. Fakturu uložte.

**Jak poznáte, že je hotovo:** Částka je ve výkazech a v optimalizátoru vedena odděleně jako osvobozená. V přiznání DPH a v obratu zůstává beze změny.

Pravidla jsou v [§ 43.11.12](#431112-prijem-mimo-zaklad-dane-z-prijmu-osvobozeny-prefakturace).

## 43.9 Krok za krokem: oznámení o příjmech do zahraničí

1. Otevřete `Daně → Příjmy do zahraničí`. Tiskopisy najdete také v archivu podání ve složce **Příjmy nerezidentů**.
2. Zvolte tiskopis: oznámení podle § 38da, nebo hlášení podle § 38e.
3. Vyplňte poplatníka (typ, jméno nebo název, daňovou identifikaci ve státě rezidence, adresu, stát rezidence) a údaje o příjmu. Údaje se zadávají ručně.
4. Klikněte na **Vygenerovat a stáhnout XML**. Podání se uloží do archivu a stáhne.
5. Pokračujte jako u ostatních písemností EPO (viz [Archiv podání](49_Archiv_podani_a_rekonciliace.md)).

**Jak poznáte, že je hotovo:** Podání je v archivu ve složce **Příjmy nerezidentů** a po odeslání ho označíte jako podané.

> [!WARNING]
> Ani jedna z těchto písemností není mzdové podání. Týkají se plateb do zahraničí, typicky licenčních poplatků, dividend, úroků nebo odměn za služby. Podrobnosti a omezení jsou v [§ 43.11.18](#431118-oznameni-o-prijmech-plynoucich-do-zahranici-a-zajisteni-dane).

## 43.10 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Panel **Situace, které aplikace v přiznání neumí** s varováním | Povaha poplatníka nebo data zasahují do případu, který aplikace nepodporuje | Přečtěte si, **co s tím**, a daný údaj dokončete v EPO ručně. Finalizace i export zůstávají povolené. |
| Panel **Předfinalizační kontrola** s upozorněním | Kontrola našla nesoulad (odpisy, dary, výsledek hospodaření, období, DPH) | Otevřete rozpad u problému a opravte podklady, případně nález ověřte (viz [§ 43.11.9](#43119-predfinalizacni-kontrola)) |
| Přiznání se nevydá | Typ poplatníka nebo situace je mezi vědomě nepodporovanými | Viz [§ 43.11.19](#431119-rozsah-a-co-aplikace-vedome-nepodporuje) a podání dokončete v EPO |
| Varování, že chybí finanční úřad | V nastavení firmy není vyplněn kód FÚ | Doplňte ho v nastavení firmy |
| Varování, že dary pod 2 000 Kč se neodečtou | Dar pod zákonnou hranicí systém vyloučí | Ověřte položky darů |
| Varování o daňové ztrátě k převodu | Ztráta se uplatní až v dalších obdobích | Zkontrolujte přehled **Daňové ztráty** na kartě **Úpravy a odpočty** |
| Pracovní XML bylo staženo s obsahovými nesrovnalostmi | Podklady jsou neúplné nebo nesouhlasí | Zkontrolujte podklady a uvedená upozornění před podáním |
| XML bylo staženo, ale neprošlo kontrolou schématu | Údaje jsou chybné | Opravte chyby uvedené v archivu podání |
| Není vybrán druh příjmu § 10 | Druh příjmu je povinný, finanční úřad podání vytkne | Vyberte druh příjmu v kartě **Úpravy a odpočty** |
| Zálohy se nespárovaly | Chybí výpis, nesedí variabilní symbol, částka nebo datum | Naimportujte výpis nebo potvrďte úhradu ručně |
| Přiznání je uzamčené, nelze upravit | Je ve stavu **Finální** | Klikněte na **Odemknout** |
| Tlačítko pro stažení XML nebo PDF chybí | Chybí oprávnění k exportu výkazů | Požádejte správce firmy o oprávnění |

## 43.11 Podrobnosti a pravidla

### 43.11.1 Karty a stavy přiznání

Stránka vede od podkladů k exportu v kartách **Podklady**, **Úpravy a odpočty**, **Náhled přiznání** a **Export**. K nim přistupují karty **Zálohy na daň a pojistné** a **Rozhodnutí FÚ o zálohách**. Rozpracované přiznání se ukládá (stav **Rozpracováno**), po dokončení jde **Uzamknout (finální)**, čímž se zmrazí snímek vypočtených řádků. Uzamčené přiznání lze **Odemknout**. Souběžnou editaci chrání verzování, při konfliktu se stránka znovu načte.

### 43.11.2 Podklady

Automaticky načtená data ze systému:

- **DPPO:** výsledek hospodaření (Σ výnosy − Σ náklady mimo daň z příjmů, **bez uzávěrkových
   zápisů**), daňově **neuznatelné náklady dle § 25** (účty označené v osnově), **rozdíl
   daňových a účetních odpisů** a **daňová zůstatková cena vyřazeného majetku** (§ 24/§ 25).
- **DPFO:** dílčí základ **§ 7** - příjmy a výdaje z **kasové báze** (daňová evidence) nebo
  **výdajovým paušálem**. Každá činnost má vlastní název, CZ-NACE, sazbu a příjmy;
  příjmy činností musí přesně navazovat na příjem deníku. Zákonný strop se u činností
  se stejnou paušální sazbou uplatní jednou za celou skupinu a výdaj se mezi ně
  poměrně rozdělí. **Fyzická osoba s podvojným
  účetnictvím** má § 7 odvozený z **výsledku hospodaření** deníku (výnosy − náklady, § 23/2);
  mimoúčetní úpravy základu (nedaňové náklady, rozdíl odpisů) je nutné případně doplnit ručně.
  Osobní odpočty (§ 15), měsíční nároky na děti a manžela/manželku, invalidita,
  ZTP/P a měsíční režim OSVČ se spravují přímo v daňovém profilu. U dítěte se
  eviduje identita, pořadí, oprávněné měsíce a ZTP/P; neúplný nárok vyvolá varování.

### 43.11.3 Úpravy a odpočty

Ruční položky, které systém nezná a které **přežijí mezi sezeními**:

- **DPPO:** odečet ztráty minulých let (§ 34), dary (§ 20/8) - buď souhrnně, nebo **položkově**
  (dary v hodnotě pod **2 000 Kč** se dle § 20/8 neodečtou a systém je vyloučí), **odečet na
  podporu výzkumu a vývoje** (§ 34/4, ř. 242) a **na podporu odborného vzdělávání** (§ 34/4,
  ř. 243), přepočtený počet zaměstnanců se zdravotním postižením (sleva § 35), **sleva za
  zastavené exekuce (§ 35 odst. 4)**, zaplacené zálohy na daň a volné položky § 23
  zvyšující/snižující základ.
- **DPFO:** příjmy a sražené zálohy ze **závislé činnosti (§ 6)**, dílčí základy **§ 8/§ 9**
  a položkové druhy příjmů **§ 10** (výdaj se u každého druhu omezí jeho příjmem),
  (kapitál, nájem, ostatní), **samostatný základ daně (§ 16a)**, **odečet daňové ztráty minulých
  let (§ 34)** - uplatní se max do výše úhrnu § 7-§ 10 (ř. 41), zaplacené zálohy na daň i na
  **pojistné** (sociální/zdravotní).

#### Odečty § 34 odst. 4 (DPPO)

Výši odečtu na **výzkum a vývoj** ani na **odborné vzdělávání** systém z účetnictví spočítat
nemůže - plyne z projektu výzkumu a vývoje, resp. z evidence odborného vzdělávání. Zadává ji
poplatník a systém hlídá **pořadí a strop**: odborné vzdělávání se odečítá až od základu
sníženého o ztrátu a o odečet na výzkum a vývoj, limit darů § 20/8 se počítá až ze základu
sníženého podle § 34. Nevyužitý zbytek odečtu na výzkum a vývoj lze podle § 34 odst. 5 uplatnit
v následujících **3 obdobích** - tenhle přenos systém neeviduje a upozorní na něj.

#### Sleva za zastavené exekuce (§ 35 odst. 4, DPPO)

Odpovídá výši náhrady, kterou přiznal exekutor v usnesení o zastavení exekuce. Z účetnictví
ji odvodit nelze - nárok zakládá usnesení, ne doklad - proto se zadává ručně. Vstupuje do
ř. 3 tabulky H přílohy č. 1 II. oddílu a spolu se slevami za zaměstnance se zdravotním
postižením do úhrnu na ř. 4 a dál na **ř. 300** přiznání, nejvýše však do výše daně na ř. 290.

#### Opravné položky a rezervy - tabulka C přílohy č. 1 II. oddílu (DPPO)

Účtuje-li firma o zákonných opravných položkách k pohledávkám nebo o zákonné rezervě na
opravy hmotného majetku, přiznání dostane **tabulku C přílohy č. 1 II. oddílu**. Systém ji
staví z účetnictví a z uzávěrkového kroku **Opravné položky**:

- **stav** opravných položek a rezerv ke konci období z účtů **391** a **451**,
- **tvorbu** za období z účtů **558** (zákonné OP) a **552** (zákonná rezerva § 7),
- **odpis pohledávky** podle § 24 odst. 2 písm. y) z účtu **546** (ř. 12 tabulky),
- **rozpad podle paragrafu** (§ 8 insolvence, § 8a nepromlčené pohledávky, § 8b ručení
  za celní dluh, § 8c drobné pohledávky) z volby účetní v kroku Opravné položky.

Paragraf z hlavní knihy odvodit nejde - kontace 558/391 je pro všechny stejná. Proto u každé
zákonné opravné položky vyberte v uzávěrce sloupec **Paragraf ZoR**. Chybí-li volba, nebo
zůstatek účtu 391 neodpovídá evidenci kroku, systém rozpad **nevyplní** a přiznání na to
upozorní varováním - tabulku C pak doplňte ručně v EPO.

Systém eviduje jedinou zákonnou rezervu, a to na opravy hmotného majetku podle § 7 zákona
o rezervách (kontace 552/451, ř. 25 a 26 tabulky). Rezervu podle § 9, § 10 nebo § 11a-11c
přesuňte na ř. 27-31 ručně. U zdaňovacího období kratšího než 12 měsíců se ř. 25 a 26 podle
pokynů nevyplňují a systém na to upozorní. Účetní (daňově neuznatelné) opravné položky na
účtu 559 a ostatní rezervy na 554/459 do tabulky C nepatří - vykazují se na ř. 40.

#### Samostatný základ daně (§ 16a, DPFO)

Zahraniční podíly na zisku a obdobné příjmy podle § 8 odst. 1 lze **volitelně** zdanit
samostatně sazbou **15 %**. Příjmy zahrnuté sem se v § 8 (ř. 38) neuvádějí a slevy § 35ba
ani § 35c se na tuto daň neuplatňují - přičítá se až k výsledné dani. Do XML se údaj
**nezapisuje** (atributy nejsou v úředním schématu popsané), příslušné řádky vyplňte ručně v EPO;
systém na to u nenulové částky upozorní.

#### Paušální výdaj na dopravu (§ 24/2/zt, DPPO)

U volných položek § 23 lze zaškrtnout **Paušál na dopravu**. Označená položka (paušální výdaj
i odpovídající add-back PHM) se vykáže na **ř. 40**, resp. **112/170** místo obecného ř. 62/162;
základ daně se tím nemění, jde jen o zařazení na správný řádek. Bez zaškrtnutí se systém pokusí
paušál rozpoznat z textu položky, a když si není jistý, upozorní na to.

#### Řádek přiznání u ručních položek (DPPO)

Každé volné položce § 23 lze vybrat **řádek přiznání**, na kterém se vykáže: zvyšující ř. 20, 30,
40 nebo 61, snižující ř. 100, 101, 109 až 112, 120, 130, 140, 160 nebo 161. Bez volby jde položka
na obecný ř. 62, resp. 162. Základ daně se volbou nemění, mění se jen řádek v přiznání a v XML.
U ř. 20, 30, 109 až 112 a 140 se text položky přenese i do zvláštní přílohy k řádku.

#### Daňové ztráty (§ 34)

Na kartě **Úpravy a odpočty** je přehled **Daňové ztráty (§ 34)**: každá ztráta z minulých let
(rok vzniku, evidovaná výše, kolik už bylo uplatněno, zbývající zůstatek a **rok expirace** =
rok vzniku + 5). Ztráta vzniká **automaticky** při finalizaci přiznání se záporným základem
(FO i PO) a v následujících **5 obdobích** ji lze uplatnit. Systém nabídne **návrh uplatnění
(FIFO** - od nejstarší ztráty) tlačítkem **Uplatnit návrh**; uplatnění se eviduje k roku
uplatnění (při vrácení přiznání do rozpracovaného stavu se automaticky uvolní). Poplatník
uplatňující ztrátu přikládá k přiznání **samostatnou přílohu podle § 34 odst. 1**.

Finalizace ověřuje, že lze celý vypočtený odpočet přiřadit k dostupným evidovaným
ztrátám. Pokud evidence nestačí nebo nelze ověřit vlastníka dosavadního uplatnění,
přiznání zůstane rozpracované a archiv ani evidence ztrát se nezmění. Opravte výši
odpočtu nebo doplňte průkazné podklady. Uvolňuje se jen uplatnění nahrazovaného
účinného přiznání; práce s nižší variantou nemění uplatnění vyšší finální varianty.

Přesná alokace u DPPO podporuje doložený řetězec kalendářních zdaňovacích období.
Pokud podklady obsahují hospodářské, zkrácené nebo neúplně doložené období, nelze
přiznání s odpočtem ztráty finalizovat. Přiznání bez odpočtu ztráty tato kontrola
neblokuje. Počáteční ztrátu přebíranou pouze rokem zadávejte touto cestou jen pro
kalendářní zdaňovací období. U DPFO není pro kalendářní období vyžadováno založení
podvojného účetnictví.

### 43.11.4 Náhled přiznání

Tabulka řádků formuláře (číslo řádku, popis, hodnota, zdroj) + **celková daň**,
**doplatek/přeplatek** a u DPPO **předpis záloh na další období** (§ 38a, prahy 30/150 tis. Kč).
Nad tabulkou se zobrazují **upozornění** (chybějící FÚ, nadlimitní dary, daňová ztráta k převodu...).

### 43.11.5 Export

- **Pracovní XML** - pracovní XML rozpracovaného přiznání; nearchivuje se a není určeno
  k podání.
- **Stáhnout XML** - ostré DPPDP9 / DPFDP7 ověřené proti XSD a obsahovým kontrolám.
  Finalizované **DPFO i DPPO** uchovávají neměnný výpočet a XML včetně příloh.
  Opakované stažení vrátí stejné uložené XML. Pokud starší finální DPPO uložené XML
  nemá, export jej sestaví z uloženého výpočtu a aktuálních identifikačních údajů
  a příloh. Chybí-li i výpočet, použije aktuální podklady. Obrazovka na tuto
  rekonstrukci upozorní. Nová finalizace vytvoří další
  revizi; původní uložené XML zůstává zachované. Soubor nahrajete na
  [mojedane.gov.cz](https://mojedane.gov.cz) přes „Načtení souboru". Ostrý export se
  **archivuje**. Pokud jsou nalezeny nesrovnalosti, stránka po stažení zůstane
  otevřená s varováním; jinak aplikace otevře **Daně → EPO podání a archív**.
  Selhání kontroly XSD ani obsahové kontroly stažení nezakáže. V archivu lze snapshot předat do
  předvyplněného formuláře EPO a po odeslání k němu přetáhnout XML a potvrzení.
  Dokumenty DPFO a DPPO se ukládají pod samostatně konfigurovatelný kořen
  **Daň z příjmů** a dále podle roku a typu formuláře.
- **PDF sestava** (tlačítko **Stáhnout PDF sestavu**) - přehledná pracovní sestava přiznání (DPPO i DPFO) pro kontrolu
  s účetní a do archivu. Není podáním pro finanční úřad a netváří se jako tiskopis; každá
  strana to uvádí. Obsahuje identifikaci poplatníka, zdaňovací období, druh a stav
  přiznání, souhrn (základ daně, sazba, daň, slevy, zálohy, doplatek nebo přeplatek,
  zálohy podle § 38a), řádky přiznání a upozornění k podání. U DPPO přidává rozvahu
  a výkaz zisku a ztráty v celých tisících Kč v rozsahu přílohy přiznání, u DPFO
  Přílohu č. 1 (příjmy a výdaje, činnosti, úpravy podle § 23, majetek a dluhy), Přílohu
  č. 2, děti a manžela nebo manželku. Na konci jsou daňové ztráty podle § 34 a vstupy
  zadané v přiznání. Všechny částky přiznání se čtou z téhož XML, které se stahuje pro
  EPO (u rozpracovaného DPFO z pracovního XML), takže se od něj nemohou lišit; otisk
  XML je v hlavičce sestavy. Stažení sestavy nic nearchivuje a nevyžaduje finalizaci.
  Přehledy pojistného OSVČ se tisknou zvlášť, viz níže.
- **DPFO - Pojistné OSVČ (Přehledy):** část **sociálního** a **zdravotního** pojištění OSVČ - vyměřovací
  základ, pojistné, doplatek po zálohách, **nová měsíční záloha**, případně **nemocenské**
  (dobrovolné). Tlačítka: **Stáhnout PDF přehledů** (souhrnná pomůcka), **Stáhnout XML ČSSZ** (validovaná datová
  věta) a **Přehled pro ZP** - PDF „Přehled OSVČ pro zdravotní pojišťovnu" ve struktuře
  oficiálního formuláře (výběr pojišťovny dle kódu a číslo pojištěnce z **Nastavení firmy**).
  Oba přehledy vycházejí ze stejného základu § 7, ale používají vlastní sazby, minima
  a pravidla. Shodný zdroj proto neznamená shodný vyměřovací základ ani částku.

### 43.11.6 Jak se počítá DPFO

Výpočet odděluje dílčí základy § 6, § 7, § 8, § 9 a položkové § 10. Výdaj u každého
druhu § 10 je nejvýše jeho příjem. Záporný úhrn § 7 až § 10 může vytvořit daňovou
ztrátu, ale nesnižuje dílčí základ § 6. Ztrátu minulých let lze odečíst jen od kladného
úhrnu § 7 až § 10.

Každý druh ostatního příjmu podle § 10 se zadává jako samostatná položka: **druh příjmu**
z číselníku A až H podle § 10 odst. 1 zákona (příležitostná činnost, prodej nemovitostí,
movitých věcí, cenných papírů, převod podle písm. c), jiné ostatní příjmy, bezúplatné
příjmy, loterie a tomboly), volitelný **kód** P/S/Z/N (zemědělská výroba s výdaji procentem
z příjmů, majetek ve společném jmění manželů, zdroj v zahraničí, bezúplatný příjem, který
je nemovitostí) a slovní popis. Druh příjmu je povinný - bez něj finanční úřad podání
vytkne; aplikace zobrazí varování a finalizaci umožní.

V Příloze č. 1 se u daňové evidence vykazuje také údaj **Mzdy**, tedy celkový objem
zúčtovaných mezd za období. Předvyplní se ze mzdové agendy (mzdové běhy modulu Mzdy
i ruční mzdová rekapitulace); ručním vstupem jde přebít, když se mzdy zpracovávaly
mimo aplikaci.

Od základu se následně odečtou položky § 15. Úroky z bytové potřeby používají roční
limit podle data obstarání a počtu měsíců; penzijní produkty, soukromé životní pojištění,
DIP a pojištění dlouhodobé péče sdílejí zákonný roční limit. Do pole penzijního příspěvku
se nezadává hrubá roční platba, ale rovnou odčitatelná částka z ročního potvrzení penzijní
společnosti - tedy příspěvek snížený o částky připadající na měsíce, kdy nepřevýšil hranici
pro maximální státní příspěvek; systém dál pracuje jen s touto (již sníženou) hodnotou.
Dary musí splnit spodní hranici a souhrnný procentní strop. Základ po odpočtech se zaokrouhlí
dolů na celé stokoruny, daň v pásmech 15/23 % se zaokrouhlí nahoru na celé Kč.
Řádky přiznání i příloh jsou v celých korunách a součtové řádky (úhrn dílčích základů,
základ daně, úhrn odpočtů, dílčí základ v příloze č. 1) se sčítají z už zaokrouhlených
řádků, jak je kontroluje EPO.

Sleva na poplatníka je roční. Manžel/manželka, invalidita, ZTP/P a děti se posuzují
podle zadaných měsíců a podmínek; ZTP/P zdvojnásobuje příslušný nárok. U dětí záleží
také na pořadí a daňový bonus má vlastní příjmový test. Systém nekontroluje pravost
doložených potvrzení; na chybějící podklady upozorní při finalizaci.

U daňové evidence vstupují do § 7 příjmy a skutečné výdaje peněžního deníku,
potvrzené daňové odpisy a nepeněžní zvýšení či snížení z roční uzávěrky. Pokud deník
selže, náhled může zobrazit nouzový fakturační součet. Na neúplné podklady upozorní
varování, které finalizaci ani export nezakáže.
U podvojného účetnictví FO vychází § 7 z účtovaných výnosů a nákladů; rozdíl účetních
a daňových odpisů a neobvyklé mimoúčetní úpravy je nutné prověřit ručně.

Nepeněžní úpravy se v oddílu E přílohy č. 1 rozepisují jednotlivě. Pokud je v jednom
směru více než 99 úprav, export zachová prvních 98 samostatně a ostatní sloučí do
posledního označeného souhrnného řádku. Na sloučení upozorní varování; úplný rozpis
zůstává v roční uzávěrce daňové evidence. Částky se rozdělují na celé koruny tak,
aby jejich součet odpovídal zaokrouhlenému součtu podkladů. Skutečný nesoulad
podkladů s úhrnem na řádku 105 nebo 106 se hlásí samostatně.

### 43.11.7 Jak se počítá DPPO

Výchozí řádek 10 je výsledek hospodaření z účtů 6xx minus 5xx bez daně z příjmů a bez
technických uzávěrkových zápisů. Základ upravují nedaňové náklady, ruční položky § 23,
rozdíl daňových a účetních odpisů a rozdíl zůstatkových cen vyřazeného majetku.
Následují ztráty, dary a slevy.

Rozdíl zůstatkových cen prodaného nebo zlikvidovaného majetku jde podle pokynů
k přiznání na dva řádky: účetní ZC vyšší než daňová zvyšuje základ na **ř. 40**,
daňová ZC vyšší než účetní ho snižuje na **ř. 160** (se zvláštní přílohou podle účtové
skupiny nákladů). Účetní ZC se bere ze zápisu vyřazení v modulu majetku. Majetek
vyřazený mimo modul, třeba převzatý z jiného účetního programu, kde vyřazení
zaúčtoval převzatý deník, má účetní ZC z karty (vstupní cena po zhodnocení minus
oprávky) a aplikace ji porovná s deníkem (MD 54x proti oprávkám karty ke dni
vyřazení). Když nesedí, podklady ukážou obě čísla. Daňová ZC se bere z daňových
odpisů karty. Karta bez nich má daňovou ZC rovnou účetní (nehmotný majetek „daňový
= účetní"), vstupní ceně (neodpisovaný majetek, třeba pozemek), nebo vstupní ceně
minus počáteční daňový stav. U odpisovaného majetku bez jakékoli daňové historie je
daňová ZC **neznámá**: přiznání rozdíl nedopočítá, podklady na to upozorní a rozdíl
zadáte ruční položkou. Základ se před sazbou zaokrouhluje dolů na celé tisíce
Kč; jednotlivé zálohy na další období se zaokrouhlují nahoru na celé stokoruny.

Každý řádek přiznání se vyplňuje v celých korunách. Částka z účetnictví se zaokrouhlí
matematicky a součtové řádky (70, 170, 200 a navazující) jsou součtem už
zaokrouhlených řádků, protože přesně tak je kontroluje EPO. Strop odečtu darů se
zaokrouhluje dolů, aby odečet nepřekročil zákonné procento.

Výsledkové zápisy skladové uzávěrky se do výpočtu zahrnují. Technický zápis
uzavření knih se vylučuje, aby převod na uzávěrkové účty nevynuloval výsledek.

Panel **Projekce závěrkových operací** ukáže odhad výsledku a daně po zaúčtování
uzávěrkových kroků, které v neuzavřeném roce ještě zaúčtované nejsou: časové rozlišení
drobného majetku a nákladů příštích období, kurzové rozdíly, rozpuštění rozlišení
z minulého roku, konečný stav zásob (způsob B) a odpisy roku podle odpisového plánu.
Účetní odpis sníží výsledek, rozdíl proti daňovému odpisu jde do ř. 50 nebo ř. 150
stejně jako u zaúčtovaných odpisů. Opravné a dohadné položky jsou jen návrhy
k potvrzení, zobrazí se šedě a do projekce se nesčítají. Každá položka odkazuje na
uzávěrku období, odpisy na Majetek. Zaúčtovaný krok z projekce zmizí, takže se nic
nezapočte dvakrát. Samotné přiznání (řádky a XML) počítá jen se zaúčtovanými položkami
a schválenými ručními úpravami. Účetní závěrku dokončete podle
[kapitoly Účetní závěrka](72_Uzaverka.md).

### 43.11.8 Zálohy na daň a pojistné

Na kartě **Zálohy na daň a pojistné** se z **finalizovaného
řádného** přiznání automaticky (nebo tlačítkem **Vygenerovat předpisy**) založí předpisy
záloh na **příští rok**:

- **DPPO - daň § 38a:** dle poslední známé daňové povinnosti (ř. 340) - **žádné** zálohy do
  30 000 Kč, **pololetní** (40 %) do 150 000 Kč, **čtvrtletní** (25 %) nad 150 000 Kč. Splatnost
  15. den příslušného měsíce. U kalendářního roku jsou pololetní zálohy splatné 15. 6. a
  15. 12.; čtvrtletní 15. 6., 15. 9., 15. 12. a 15. 3. následujícího roku. Březnová záloha
  před podáním přiznání ještě patří do předchozího zálohového období. Při prodloužené lhůtě
  do července začíná nový harmonogram až zářijovou, resp. prosincovou zálohou.
- **OSVČ (DPFO) - sociální a zdravotní:** nové **měsíční** zálohy dle přehledů pojistného.
  Nová výše se použije až od měsíce podání přehledu; do té doby platí dosavadní výše,
  nejméně aktuální zákonné minimum. Sociální je splatná do konce kalendářního měsíce,
  zdravotní do **8. dne** následujícího měsíce.
- **OSVČ (DPFO) - daň § 38a:** stejné prahy 30/150 tis. Kč jako u DPPO, ale výše
  se krátí podle podílu příjmů ze závislé činnosti (§ 6). Při podílu alespoň 50 %
  zálohy nevzniknou; při podílu 15-50 % se počítají v poloviční výši.

Tlačítko **Spárovat platby** najde v **bankovních výpisech** odchozí úhrady podle **variabilního
symbolu**, částky, data a vlastnictví účtu (daň = kmenová část DIČ, sociální = VS ČSSZ,
zdravotní = číslo pojištěnce), označí
odpovídající předpisy jako **zaplacené** a **předvyplní** zaplacené zálohy do rozpracovaného
přiznání / přehledu daného roku. Nejbližší splatnosti ukazuje i **widget na Přehledu** (dashboard).
Zálohy se **nepárují na doklady**, jen na bankovní pohyby - proto je nutné mít naimportované výpisy.

Karta **Rozhodnutí FÚ o zálohách** (§ 174 DŘ) umožní zadat rozhodnutí finančního úřadu o změně výše záloh na daň napříč roky. Každé rozhodnutí platí pro zvolené období (**Účinnost OD** až **Účinnost DO**, případně otevřený konec): uvnitř období se zálohy počítají podle rozhodnutí (výše a periodicita), mimo něj podle predikce z přiznání (§ 38a). Rozsahy se nesmí překrývat. Karta ukazuje i předpis placení záloh napříč roky se stavem úhrady, který se určuje automaticky spárováním s bankovními platbami.

> [!TIP]
> QR platba záloh není součástí. Předpis slouží jako plánovací a párovací pomůcka.

### 43.11.9 Předfinalizační kontrola

Než přiznání **finalizujete**, systém nad kartami zobrazí panel **Předfinalizační kontrola** - sadu
kontrol, které dělá zkušená účetní ručně, aby se do XML nedostala tichá chyba. Každá kontrola má
stav **OK / upozornění** (nebo **nerelevantní** tam, kde se netýká daného typu poplatníka),
u problémů rovnou ukáže **částky a prokliknutelný rozpad**:

- **Účetní období uzavřeno** - období roku má být `uzavřené`/`schválené`, ne otevřené.
- **Obrat účtu 551 = účetní odpisy** - zaúčtované účetní odpisy (551) musí odpovídat odpisům
  evidovaným v modulu majetku; rozdíl = odpisy nezaúčtované nebo zaúčtované ručně jinou částkou
  (řádek 50/150 by pak zkreslil základ).
- **Obrat účtu 543 = zadané dary (§ 20/8)** - dary na účtu 543 musí sedět s dary zadanými do přiznání.
- **VH přiznání = VH výsledovky** - výsledek hospodaření z přiznání se porovná s výsledovkou
  (nezávislá cesta výpočtu přes výkaz zisku a ztráty); rozdíl signalizuje chybu v mapování osnovy.
- **Nedaňové účty s obratem (ř. 40)** - informativní výčet nedaňových účtů s nenulovým obratem
  a částkami (drill-down do deníku), ať máte jistotu o řádku 40.
- **DPH přiznání za rok podána** (archivní pokrytí DPH za rok) - u plátce kontrola, že za všechna měsíční nebo
  čtvrtletní období existuje archivní záznam DPH. Současná kontrola nerozlišuje stažené
  a skutečně odeslané XML, proto je jen upozorněním a nenahrazuje doručenky z EPO.

U DPFO se jako **varování** zobrazují také nedokončená roční uzávěrka daňové evidence,
nezařazený příjem, bankovní
úhrada mimo vlastněný výpis, chyba peněžního deníku, nevyřešený přechod § 23 odst. 8,
neúplné osoby, činnosti nebo měsíce OSVČ. Výsledek kontrol se ukládá do neměnného
snapshotu. Nálezy finalizaci ani export nezakazují; před podáním je ověřte.

### 43.11.10 Situace, které aplikace v přiznání neumí

Nad předfinalizační kontrolou se u obou přiznání zobrazuje panel **Situace, které
aplikace v přiznání neumí**. Vzniká z povahy poplatníka a z účetních dat.
Všechny nálezy jsou žlutá **varování**. Finalizace i export jsou povolené;
údaje si před podáním ověřte.

Ke každému nálezu je napsané, **co s tím** - typicky „podejte přiznání za toto období
v portálu EPO ručně" nebo „opravte údaj v nastavení firmy".

Část povahy poplatníka aplikace z účetnictví poznat nemůže, proto se zadává
v `Nastavení → Daně a účetnictví`, část **Přiznání k dani z příjmů - povaha poplatníka**:

<!-- cols: 36 64 -->
| Pole | K čemu je |
|---|---|
| Typ poplatníka (§ 17 ZDP) | Kód z tiskopisu DPPO. Aplikace umí sestavit přiznání jen pro typ **1 (ostatní)**. Dokud typ nepotvrdíte, staví se přiznání jako pro typ 1. |
| Účetní vyhláška závěrky | V podvojném účetnictví se odvozuje z účinného účetního rámce, v daňové evidenci se zadává ručně. DPPO aplikace zpracuje jen pro vyhlášku **500/2002 Sb.**. |
| Stav poplatníka + rozhodný den | Běžný / v likvidaci / v insolvenci / po fúzi nebo přeměně. |
| Veřejně prospěšný poplatník (§ 17a) | Spolek, nadace, ústav, církev, veřejná vysoká škola. |
| Investiční pobídka (§ 35a/§ 35b) | Nositel příslibu investiční pobídky. |
| ATAD / CFC (§ 23e-23h, § 38fa) | Omezení nadměrných výpůjčních výdajů, příjmy ovládané zahraniční společnosti. |
| Spolupracující osoba (§ 13) - OSVČ | Rozdělení příjmů a výdajů na spolupracující osobu. |
| Zahraniční příjmy se zápočtem (§ 38f) - OSVČ | Zápočet daně zaplacené v zahraničí, Příloha č. 3. |

Výchozí hodnoty odpovídají běžné firmě a OSVČ, takže je nechte být, pokud se vás netýkají.
Je-li rozhodný den změny stavu až po konci období přiznání, tento stav starší
přiznání neovlivňuje. Při chybějícím nebo neplatném datu se zobrazí varování.
Vypnutý příznak ale **není tichý předpoklad**: kde jde podezření poznat z dat, aplikace se
ozve sama - u firmy s převažující činností z **finančního sektoru** (banky, investiční
fondy, pojišťovny, penzijní společnosti) upozorní na nepotvrzený typ poplatníka,
u **organizací sdružujících osoby** varuje na veřejně prospěšného poplatníka
a u **výroby elektřiny** upozorní na odpisy fotovoltaiky podle § 30b.

Ruční seznam nepodporovaných situací z [roční uzávěrky daňové evidence](74_Danova_evidence.md)
se do stejného panelu slévá - nálezy jsou na jednom místě, ne ve dvou seznamech.

### 43.11.11 Daňová (ne)uznatelnost nákladů (§ 25) - DPPO

Nedaňové náklady se u DPPO poznají podle příznaku **Daňová uznatelnost** na účtu v
[účtovém rozvrhu](66_Ucetni_osnova.md). Šablona rovnou označí jako nedaňové syntetiky
**513** (reprezentace), **528** (ostatní sociální), **543** (dary), **545** (pokuty a
penále), **549** (manka nad náhrady), **554** (účetní rezervy), **559** (účetní opravné
položky). Analytiky **dědí** příznak ze syntetiky; ručně jej lze změnit. Odpisy (551) a
daň z příjmů (59x) se neflagují - řeší se vlastní mechanikou (rozdíl odpisů, resp. vyloučení
z výsledku hospodaření).

### 43.11.12 Příjem mimo základ daně z příjmů (osvobozený, přefakturace)

Některé **vydané** doklady nejsou základem daně z příjmů - typicky:

- **Prodej movité věci osvobozený dle § 4 odst. 1 písm. c) ZDP** - např. vozidlo prodané po
  více než 1 roce od nabytí; u OSVČ na paušálu, kde věc nebyla v obchodním majetku, neběží
  ani 5letý test po vyřazení.
- **Přefakturace / průběžné položky** (§ 23 odst. 4 ZDP) - částka, která není ani příjmem,
  ani výdajem.

U takové faktury zaškrtněte v editoru **Osvobozeno od daně z příjmů** (volitelně doplňte důvod).
Příznak **nezahrne částku do základu daně z příjmů** (výkaz i optimalizátor; osvobozená část
se ukáže odděleně) a **nedotkne se DPH** - doklad zůstává v přiznání DPH i v obratu beze změny.

Osvobozený příjem nevstupuje ani do **rozhodných příjmů pro pásmo paušálního režimu** -
rozhodnými příjmy jsou podle § 2a odst. 5 ZDP příjmy ze samostatné činnosti a § 7a odst. 1
písm. b) bod 1 ZDP uvádí příjmy od daně osvobozené jako kategorii, kterou poplatník smí mít
*vedle* rozhodných příjmů. Na teploměru limitu 2 mil. Kč v Optimalizátoru se proto neobjeví.
U plátce DPH se počítá **částka bez DPH**, stejně jako u zdanitelného příjmu.

#### Souvislost se sociálním a zdravotním pojištěním (OSVČ)

U OSVČ se **vyměřovací základ** pojistného odvozuje z **daňového základu § 7**. Když částka
nevstoupí do základu daně z příjmů, zmizí i z vyměřovacího základu SP a ZP - jeden příznak
sedí na daň i na pojistné.

<!-- cols: 24 40 36 -->
| Veličina | Co znamená | OSVČ |
|---|---|---|
| **Vyměřovací základ** | z čeho se pojistné počítá | 55 % (SP) / 50 % (ZP) daňového základu § 7, nejméně roční **minimum**; u SP nejvýše 48× průměrná mzda |
| **Sazba odvodu** | kolik se odvádí | SP 29,2 %, ZP 13,5 %, nemocenské 2,7 % (drží roční daňové konstanty) |

> [!TIP]
> Snížení pojistného se projeví **jen nad rámec minimálního vyměřovacího základu**. OSVČ
> na zákonném minimu osvobozením příjmu na pojistném neušetří. U **s.r.o.** se SP/ZP z obratu
> netýká; příznak tam ovlivní jen základ DPPO. Vedlejší činnost pod **rozhodnou částkou**
> neplatí sociální pojistné vůbec.

### 43.11.13 Opravné a dodatečné přiznání (§ 141 DŘ)

Přepínačem **Řádné / Opravné / Dodatečné** nahoře zvolíte druh přiznání; každý druh je samostatný
záznam za totéž období.

- **Opravné** - plná náhrada řádného přiznání **před uplynutím lhůty** k podání (jen jedno).
- **Dodatečné** - po lhůtě, počítá se **rozdílově** proti **poslední známé dani**. Za jedno období
  jich lze podat **víc** (dodatečné č. 1, č. 2, ...). Pod přepínačem se u dodatečného zobrazí výběr
  **pořadí (č. N)**, tlačítko **Nové dodatečné** a **časová osa** dosavadních podání za období
  (stav, kdy bylo změněno/podáno).

**Poslední známá daň** se odvozuje z **naposledy pravomocně stanovené daně** (§ 141 odst. 1 DŘ) -
tedy z posledního finalizovaného přiznání v řetězu **řádné → opravné → dodatečné č. 1 → č. 2 → ...**
Systém ji u nového dodatečného předvyplní (lze ručně přepsat); rozdíl proti nově zjištěné dani se
promítne do V. oddílu formuláře (u DPPO řádky iv1/iv2/iv3). Dodatečné přiznání č. N tedy vždy
navazuje na to předchozí, ne na řádné.

### 43.11.14 Převzetí podaného DPPO do vstupů přiznání

U DPPO lze v kartě **Export** v části **Rekonciliace proti podanému přiznání** nahrát XML DPPDP9, které bylo skutečně podáno účetní nebo
upraveno na EPO. Aplikace nejprve zkontroluje typ formuláře a rok a potom porovná
formulářové řádky s aktuálním výpočtem. Zobrazí shody, rozdíly a hodnoty přítomné jen
v podaném souboru. Jde o read-only kontrolu: nahrání nic nezaúčtuje, nepřepíše přiznání
a soubor samo neoznačí jako přijatý finanční správou. Importní rekonciliace
skutečně podaného DPFO, DPHDP3 a KH není k dispozici.

Firma převedená z jiného programu nebo od účetní kanceláře má podaná přiznání za minulé roky,
ale v MyÚčtu jen účetnictví. Tlačítko **Náhled převzetí do vstupů** ve stejném bloku převezme
z podaného XML údaje, které z účetnictví neplynou:

- úpravy základu na jejich řádcích (ř. 20, 30, 61, 62, 100 až 162 kromě ř. 150), texty ze
  zvláštní přílohy podání, když ji podání má;
- z ř. 40 a ř. 160 jen část nad to, co MyÚčto spočte samo (nedaňové účty, rozdíl zůstatkových
  cen vyřazeného majetku);
- odečet ztráty (ř. 230), odečty § 34 odst. 4 (ř. 242, 243), dary (ř. 260), slevy § 35 z tabulky H
  a zaplacené zálohy.

Výsledek hospodaření, odpisy a nedaňové účty spočte MyÚčto z účetnictví jako u každého
přiznání. Náhled nic neukládá: ukáže navržené vstupy a porovnání přiznání s nimi proti podání.
U každého řádku je vidět, zda je spočtený z účetnictví, ze vstupů, nebo jde o mezisoučet.
Po převzetí mají řádky ze vstupů sedět; rozdíl na řádcích z účetnictví je skutečný rozdíl mezi
účetnictvím a podáním a převzetí ho nezakrývá.

Tlačítko **Převzít do vstupů přiznání** vstupy uloží. U existujícího rozpracovaného přiznání se
po potvrzení přepíšou jen ruční položky, ztráta, odečty, dary a slevy; lhůta, účet pro přeplatek
a poznámky zůstanou. Finální přiznání převzetí nezmění. Převzít lze jen přiznání stejné firmy
(IČO) a roku, který je na obrazovce vybraný.

Převzetí zároveň zapíše do evidence **daňových ztrát** ztrátu vzniklou v roce podání a ztrátu
uplatněnou na ř. 230, takže ztráty navazují mezi převzatými roky. Přebírejte proto roky od
nejstaršího. Když podání uplatňuje ztrátu, jejíž rok vzniku v evidenci chybí, aplikace na to
upozorní.

Pro převod více let najednou slouží příkaz

```
php api/bin/tax-return-import.php --ico=<IČO> --filed=<soubor nebo adresář s XML> [--dry-run]
```

který z adresáře vybere za každý rok poslední podání firmy (dodatečné má přednost před
opravným a řádným) a roky zpracuje vzestupně. Volba `--replace-draft` přepíše existující
rozpracovaná přiznání, `--loss=RRRR:ČÁSTKA` doplní ztrátu roku, za který podání není,
a `--dry-run` jen vypíše náhled.

### 43.11.15 Roční uzávěrka daňové evidence a snapshot DPFO

DPFO ze skutečných výdajů upozorní na nedokončenou roční uzávěrku podle § 7b.
Přiznání lze finalizovat i exportovat s tímto varováním.
Kontrolní seznam pokrývá deník, nepeněžní operace, majetek, zásoby, pohledávky,
závazky, vysoké nákupy, změny režimu a cizí měny. Zadávají se počáteční a konečné
stavy majetku, hotovosti, banky, zásob, pohledávek, ostatních aktiv, dluhů a rezerv.

Nepeněžní úpravy mohou být například zápočet, barter, naturální příjem, prominutý dluh,
soukromá spotřeba, manko, škoda nebo jiná úprava § 23. Směr **Zvýšení základu** nebo **Snížení základu** přímo
ovlivní § 7; volba **Pouze stavová evidence** pouze uloží auditní stopu. Aplikace právní směr neurčuje.
Finalizace uzávěrky vyžaduje všechny kontroly, vypořádaný roční koeficient § 76, vyřešené
blokery deníku a posouzené nadlimitní nákupy. Checklist potvrzuje provedení inventury,
nenahrazuje její fyzické podklady.

Finální DPFO uloží výpočet, podklady, kontroly, seznam zdrojových pohybů, XML a jejich
kontrolní otisky. Oprava podkladů vyžaduje znovu otevřít uzávěrku i přiznání a vytvořit
nový snapshot. Podrobnosti jsou v [Daňové evidenci](74_Danova_evidence.md#7499-rocni-uzaverka-danove-evidence).

### 43.11.16 Pojistné OSVČ - důležitá omezení

Měsíční profil eviduje aktivní, hlavní, vedlejší a přerušenou činnost a příznak, zda
se v daném měsíci uplatní minimum zdravotního pojištění. Tato data se používají při
orientačním výpočtu minim. Sociální vyměřovací základ vychází z 55 % základu § 7,
zdravotní z 50 %, oba s příslušnými minimy a maximem; vyměřovací základ i pojistné se
zaokrouhlují nahoru na celé Kč.

Současné XML ČSSZ však umí pouze jeden režim hlavní/vedlejší činnosti pro celý rok.
Neodesílejte je bez ručního dokončení, pokud činnost začala, skončila či byla přerušena,
střídala hlavní a vedlejší režim, existovalo zaměstnání ovlivňující maximální základ,
nebo jde o novou OSVČ či jiný zvláštní režim. Údaje **Státní pojištěnec**, **Zaměstnání**,
**Nová OSVČ** a individuální vyměřovací základ nejsou do konečného pojistného zapojeny
ve všech zákonných kombinacích. Zdravotní přehled je pouze PDF pomůcka bez jednotného
podávacího XML. V těchto případech přepište údaje do formuláře instituce a ověřte je.

### 43.11.17 Termíny podání

- **Daň z příjmů FO (bez poradce):** **1. 4.** následujícího roku · **elektronicky:** **1. 5.**, připadne-li na víkend nebo svátek, nejbližší následující pracovní den (v roce 2026 tedy 4. 5.), **s poradcem:** **1. 7.**
- **Daň z příjmů PO:** dle účetního období (řádně 1. 4., s auditem/poradcem 1. 7.)
- **Přehledy pojistného OSVČ:** do **1 měsíce** po lhůtě pro daňové přiznání.

Lhůtu 1. 7. prodlužuje jen zastoupení daňovým poradcem nebo advokátem (kód
podepisující osoby 4b nebo 4c v `Nastavení → Daně a účetnictví`, část **Zastoupení a
podepisující osoba**). Obecný zmocněnec, například účetní kancelář bez osvědčení daňového
poradce, lhůtu neprodlužuje (viz [Zastoupení a podepisující osoba](41_Vykazy_DPH.md#zastoupeni-a-podepisujici-osoba)).

### 43.11.18 Oznámení o příjmech plynoucích do zahraničí a zajištění daně

Platí-li firma daňovému nerezidentovi příjem ze zdrojů v České republice, vzniká
vedle vlastní srážky ještě samostatná oznamovací povinnost. MyÚčto pro ni
připraví dvě písemnosti:

- **Oznámení o příjmech plynoucích do zahraničí** podle § 38da (tiskopis
  25 5478). Podává se za každý jednotlivý příjem a každý druh příjmu zvlášť,
  nikoli souhrnně za rok. U licenčních poplatků, dividend a úroků se oznamuje
  i tehdy, když je příjem od daně osvobozený nebo když smlouva o zamezení
  dvojímu zdanění přiznává zdanění druhému státu.
- **Hlášení plátce daně o provedení srážky zajištění daně** podle § 38e
  (tiskopis 25 5544). Podává se ke každému zajištění daně sraženému poplatníkovi,
  který není daňovým rezidentem státu EU ani EHP, a to z příjmu, který srážkové
  dani nepodléhá.

> [!WARNING]
> Ani jedna z těchto písemností není mzdové podání. Týkají se plateb
> do zahraničí, typicky licenčních poplatků, dividend, úroků nebo odměn za služby,
> a s výplatní listinou nemají nic společného. Mzdový modul zdaňuje srážkovou daní
> jedině příjmy podle § 6 odst. 4, a ty jsou z oznamovací povinnosti výslovně
> vyloučené (§ 38da odst. 5 písm. b)). Zajištění daně se ze záloh na příjem ze
> závislé činnosti nesráží vůbec (§ 38e odst. 1 poslední věta). Číselník druhů
> příjmu proto pro závislou činnost žádný kód nemá. Obrazovka je z téhož důvodu
> dostupná i firmě, která mzdy vůbec nevede: kdo platí licenční poplatek do třetí
> země, má povinnost stejně.

**Kde to najdete.** Obrazovka je v menu `Daně → Příjmy do zahraničí` (komerční modul). Oba tiskopisy
se v archivu podání řadí do složky **Příjmy nerezidentů**. Ke čtení stačí
oprávnění pro sestavy, ke stažení XML navíc právo exportovat.

**Údaje zadáváte ručně, a je to záměr.** Aplikace tyto platby nikde neeviduje:
z mezd nevznikají a přijaté doklady srážkovou daň ani zajištění nenesou. Cokoli
by se tu odvozovalo, by znamenalo podat nepravdivé oznámení, proto je formulář
prázdný. Sám doplní jen to, co skutečně ví:

- větu o plátci z údajů firmy,
- cílový finanční úřad podle nastaveného kódu FÚ; není-li vyplněný, dosadí se
  FÚ pro Prahu 1 a podání dostane varování, ať kód ověříte,
- skupinu druhu příjmu podle vybraného kódu z číselníku,
- kontroly proti tiskopisu a validaci proti XSD.

Vyplňuje se poplatník, tedy typ (fyzická osoba, obchodní společnost, sdružení,
jiná právnická osoba, státní nebo mezinárodní organizace, ostatní), jméno nebo
název, daňová identifikace ve státě rezidence a adresa. **Stát daňové rezidence
je povinný a nesmí být Česká republika** - jde z definice o nerezidenta.
U fyzické osoby musí být uvedeno buď datum narození, nebo daňová identifikace.

U oznámení podle § 38da se dále zadává druh příjmu ze zveřejněného číselníku,
sazba daně, způsob úhrady, datum úhrady **nebo** rok úhrady (právě jedno z toho),
částky, kurz a jeden odvod sražené daně. Nulovou sazbu, tedy osvobozený příjem,
formulář přijme jen u licenčních poplatků, dividend a úroků; u osvobozeného
příjmu se naopak odvody nevyplňují. U hlášení podle § 38e se zadává druh
zdanitelného příjmu volným textem, sazba zajištění (1 %, 10 %, odkaz na § 16
nebo § 21, případně nula jen v následném hlášení), příjem před srážkou,
zajištěná částka a rozhodná data. Zajištění se zaokrouhluje na celé koruny
nahoru.

**Co aplikace nehlídá.** Osvobozené úroky se oznamují až od okamžiku, kdy jejich
úhrn za kalendářní měsíc přesáhne 300 000 Kč (§ 38da odst. 5 písm. a)). Aplikace
takový úhrn nevede, protože platby do zahraničí neeviduje, takže limit posoudíte
sami a pod ním oznámení prostě nezakládáte. Nehlídá ani lhůty: oznámení se podává
ve lhůtě pro odvod sražené daně, u osvobozeného příjmu do 31. ledna
následujícího roku. Tyto tiskopisy proto nemají řádek v daňovém kalendáři.
**Vyúčtování zajištění daně se nepodává vůbec** (§ 38e odst. 12).

**Výstup a odeslání.** Tlačítko **Vygenerovat a stáhnout XML** vytvoří jedno
podání k jedné platbě, uloží je do archivu podání i s otiskem a stáhne. Odtud
pokračujete stejně jako u ostatních písemností EPO, tedy asistovaným nebo přímým
podáním; postup je v kapitole
[EPO podání, archív a daňová rekonciliace](49_Archiv_podani_a_rekonciliace.md).
Obrazovka sama nevede seznam ani koncepty: každé odeslání formuláře je jedno
hotové podání, historii hledejte v archivu. Na uzávěrku DPH se tato podání
nenavazují, protože se týkají jednotlivé platby.

Nesrovnalosti, které podání nezablokují, se uloží jako varování, například
následné hlášení bez data zjištění důvodů nebo bez poznámky, ke které původní
písemnosti patří, chybějící odvod u neosvobozeného příjmu, nebo úhrn odvodů
odlišný od sražené daně. Přečtěte si je před odesláním.

Formulář zatím neumí víc odvodů sražené daně v jednom oznámení, byť je tiskopis
připouští, a nepracuje s řádky 27a a 27b (příjem navýšený o povinné pojistné).
Nevytváří textovou přílohu; takový případ dokončete v EPO.

**Zástupce.** Je-li ke dni vytvoření XML v evidenci zastoupení platný zástupce
(`Nastavení → Daně a účetnictví`, část **Zastoupení a podepisující osoba**), vyplní se
jako podepisující osoba do obou písemností, stejně jako u přiznání DPH a daně z příjmů.
Oznámení ani hlášení nemají pole "podává daňový poradce", uvede se jen zástupce
a jeho kód. Lhůtu prodlužují jen kódy 4b a 4c; u ostatních kódů, například obecného
zmocněnce 4a, aplikace uloží k podání varování.

### 43.11.19 Rozsah a co aplikace vědomě nepodporuje

Aplikace pokrývá **řádné, opravné i dodatečné** přiznání DPPO (s.r.o./a.s., kalendářní rok i
**hospodářský rok**, česká rezidence) a DPFO (OSVČ § 7 automaticky; § 6 z potvrzení
zaměstnavatele, § 8/§ 9/§ 10 jako ruční vstupy), pojistné OSVČ vč. nemocenského, XML
validované proti XSD a **e-podání pro ČSSZ** (Přehled OSVČ jako validovaná XML datová věta
k nahrání na ePortál ČSSZ / do datové schránky).

Pojistné (sociální i zdravotní) se v přehledech zaokrouhluje **na celé koruny nahoru**.
Shodu s datovou větou ČSSZ lze očekávat jen u podporovaného celoročního režimu.

Přehled pro **zdravotní pojišťovny** je pouze PDF pomůcka, protože pojišťovny
nemají jednotné veřejné schéma. Přímé odeslání na EPO ani ePortál ČSSZ tato
obrazovka neprovádí: XML stáhněte a nahrajte na mojedane.gov.cz, resp. ePortál ČSSZ.

Pokud náhled nebo XSD projde, znamená to strukturální a implementovanou obsahovou
kontrolu, nikoli potvrzení věcné správnosti každého daňového případu. Chybějící případ
dokončete v EPO a archivujte právě finální odeslanou verzi.

#### Co aplikace vědomě nepodporuje

Následující případy nejsou „zatím", ale rozhodnutí. Žádný z nich neprojde tiše - aplikace
je pozná a nález ukáže v panelu [Situace, které aplikace v přiznání neumí](#431110-situace-ktere-aplikace-v-priznani-neumi)
ještě před podáním.

<!-- cols: 34 66 -->
| Případ | Jak se projeví |
|---|---|
| Investiční fond, investiční nebo penzijní společnost, banka, pojišťovna | Přiznání se nevydá. Zvláštní režimy a vyhlášky 501/502/503 nejsou podporované. |
| Daňový nerezident, stálá provozovna | Přiznání se nevydá při typu poplatníka 2. Sídlo mimo ČR samo o sobě nerezidenta nedělá (rozhoduje místo vedení podle § 17 odst. 3 ZDP), proto je samotné zahraniční sídlo jen upozorněním. |
| Likvidace, insolvence, fúze nebo přeměna | Přiznání se nevydá. Mění typ přiznání i zdaňovací období; hláška vám řekne, který typ přiznání úřad čeká. |
| Investiční pobídky (§ 35a/§ 35b) | Přiznání se nevydá. Podmínky se sledují roky zpětně mimo účetnictví. |
| ATAD, CFC (§ 23e-23h, § 38fa) | Přiznání se nevydá. Úpravy základu daně se nepočítají, základ by byl podhodnocený. |
| Atypické zdaňovací období | Přiznání se nevydá, pokud období neodpovídá kalendářnímu ani hospodářskému roku, nebo je delší než dvanáct měsíců. **Hospodářský rok podporovaný je** - jen na něj upozorní, protože lhůty, zálohy a roční sazby se odvozují od kalendářního roku. |
| Fyzická osoba v podvojném účetnictví | Přiznání se nevydá. Účetní výkazy fyzické osoby (věty přílohy DPFDP7) aplikace nesestavuje, podání by odešlo bez povinné přílohy. |
| Spolupracující osoba (§ 13) - OSVČ | Přiznání se nevydá. Vazba mezi poplatníky se nevede, dílčí základ § 7 by vyšel vyšší, než jaký vám náleží. |
| Zahraniční příjmy se zápočtem (§ 38f, Příloha č. 3) - OSVČ | Přiznání se nevydá. Evidence příjmů a daní po státech se nevede. Samostatný základ daně podle § 16a bez zapnutého příznaku vyvolá aspoň upozornění. |
| Fotovoltaika (§ 30b) a účetní odpisy majetku mimo zákonnou definici (§ 24 odst. 2 písm. v) | Upozornění u výroby elektřiny. Evidence majetku ty režimy nerozlišuje, příslušné řádky tabulky B zůstanou prázdné - doplňte je v EPO. |
| Zvláštní příloha k ř. 62, Příloha A k ř. 40 | Slovní popis, ne dopočet. Aplikace na ně upozorňuje, text napíšete v EPO. |

### 43.11.20 Právní profil organizace v režimu 504

V nabídce Účetnictví otevřete **DPPO neziskové organizace** a vyberte účetní období.
Právní profil obsahuje výslovně zvolené postavení poplatníka, druh organizace a doložené
rozhodné skutečnosti. Oprávněný uživatel jej může potvrdit; předchozí potvrzení zůstávají
v historii. Změna podkladů vyžaduje nové potvrzení profilu a příslušného daňového posouzení.

Výpočet vyžaduje podvojné účetnictví a rámec 504 v celém skutečném období,
potvrzený právní profil a aktuální potvrzené daňové posouzení. Podporovaný
interval musí celý ležet uvnitř pravidel pro roky 2024 až 2026. Snížení
základu a související úsporu upravujte podle doloženého právního titulu;
uložení změny vyžaduje oprávnění k zápisu výkazů.

Obrazovka rozlišuje **Výpočet**, **Finalizaci**, **Export XML** a **Podání**.
U nedostupné operace ukáže konkrétní důvod a připnutou verzi formuláře.
Nedostupnost formuláře nebrání samostatně podporovanému výpočtu. Převzetí
výpočtu neautorizuje podání. Finalizace vytváří neměnný důkaz podkladů,
daňových ztrát, výkazů a XML. Export a odeslání znovu kontrolují příslušné
podmínky; před odesláním musí odpovídat také aktuální finální podklady.

Načtení obrazovky podklady DPPO nesestavuje. Připravenost výpočtu ověříte
tlačítkem **Ověřit připravenost výpočtu**; teprve potom obrazovka ukáže zjištění
a nabídne převzetí do přiznání.

Finalizace, export XML a podání DPPO 504 jsou dostupné až po **akceptaci výpočtu**.
Akceptaci zapíná pouze správce firmy v sekci **Akceptace výpočtu DPPO 504**:
výslovně potvrdí, že výpočet ověřil, a uvede odkaz na provedené ověření (například
protokol porovnání s nezávislým výpočtem). Výchozí stav je vypnutý. Akceptace platí
pro konkrétní verzi pravidel a formuláře; po jejich změně je potřeba ji potvrdit znovu.
Správce ji může kdykoli odvolat, záznamy akceptací zůstávají v historii.

Příloha daňových ztrát používá doložená kalendářní období, přesné částky
v celých Kč a stejný plán uplatnění jako evidence ztrát. Respektuje také
podporované zpětné uplatnění a jeho limity. Historický záznam ztráty
bez průkazného období nelze automaticky použít v této příloze. Hospodářský
rok nebo zkrácené období s přílohou ztrát vyžaduje samostatné zpracování.

Přehled daňových úspor zobrazuje doložené uložené záznamy podle oprávnění uživatele.
Právní profily a interní podklady úspor vyžadují přihlášenou aplikaci
nebo API token s **Plným zápisem** a odpovídajícími právy
([§ 103.9.7.3](103_API.md#103973-plny-zapis-tokenem-systemwrite)).
Běžný token ani katalog MCP tyto interní podklady nezpřístupňují.

#### Použití daňové úspory v následujícím období

Daňová úspora vznikne finalizací přiznání, které uplatní snížení základu daně podle
§ 20 odst. 7. Úsporu použijete v bezprostředně následujícím účetním období v sekci
**Použití daňové úspory podle § 20** na stejné obrazovce:

1. Zvolte zdrojovou úsporu. Obrazovka ukáže její výši a zbývající částku.
2. Vyberte zaúčtovaný náklad následujícího období. Nabídka obsahuje jen náklady, které
   aktuální potvrzené daňové posouzení tohoto období vede jako způsobilé. Posouzení
   může být v obou formátech, nabídku i dostupnou částku ověřuje server.
3. Zadejte použitou částku a doložený účel a stiskněte **Zkontrolovat použití úspory**.
4. V kontrole před potvrzením zaškrtněte, že jste uvedené skutečnosti ověřili, a použití
   potvrďte.

Použití lze později zrušit stejným postupem přes **Zkontrolovat zrušení použití**
s uvedením důvodu; původní záznam zůstává v historii. Dokud použití trvá, náklad, na
který navazuje, nelze změnit ani stornovat.

DPPO následujícího období převezme evidenci použití automaticky. Je-li úspora použita
celá, k základu daně se nic nepřičítá. Nepoužitou část aplikace přičte k základu daně
v poměru nepoužité úspory k celé úspoře: například při snížení 100 000 Kč, úspoře
21 000 Kč a použití 10 500 Kč přičte 50 000 Kč.

Pracovní plocha pro posouzení neziskové organizace načítá podklady po stránkách. Nejprve
zobrazí pouze souhrn zdrojů a potvrzenou historii. Koncept otevřete výslovně po volbě
právního profilu a jeho důkazu; teprve potom procházíte účetní řádky, rozhodnutí,
činnosti a vazby použitých prostředků. Další stránka se načte jen po stisknutí tlačítka,
takže ani dlouhé účetní období nestahuje celý rok do prohlížeče.

Každé uložení posune revizi konceptu. Staré stránkovací odkazy a starý náhled tím
pozbudou platnost. Náhled slouží ke kontrole rozhodnutí, výsledku, pokrytí a doloženého
použití prostředků. Potvrzení výsledek náhledu slepě nepřebírá: znovu načte aktuální
účetní podklady, ověří poslední potvrzené posouzení a až poté vytvoří neměnný záznam.
Pokud po odeslání není kvůli přerušení spojení zřejmý výsledek, obrazovka nabídne
opakování přesně stejného požadavku bez vzniku dvojí revize.

Rozpracovaný koncept se při dalším otevření obrazovky načte automaticky. Každý
uživatel má pro účetní období nejvýše jeden vlastní koncept.

Potvrzenou historii lze opět procházet po stránkách. Pokud mezitím vznikne nová
revize, obrazovka historii načte znovu od začátku. U každé revize otevřete její
neměnné zdroje a výsledkové části, stáhnete souhrn v PDF nebo XLSX, případně úplný
proudový NDJSON export. Souhrn obsahuje přechod od účetního výsledku k výsledku
posouzení; úplné podklady jsou jen v NDJSON exportu.

Aktuální potvrzené posouzení může oprávněný uživatel **odvolat**. Uvede důvod
a potvrdí zaškrtnutím u tlačítka. Odvolání vytvoří novou revizi a původní potvrzení
zůstane v historii. Odvolat nelze posouzení, na jehož zdroje financování navazuje
potvrzené čerpání v pozdějším období.

Revize uložená ve starším formátu je označena **Starší formát, vyžaduje převod**.
Oprávněný uživatel ji převede tlačítkem **Převést do nového formátu**. Převod
zachová číslo revize i původní uložená data, výsledek znovu přepočítá a uloží jen
tehdy, když se s původním výsledkem shoduje. Samotné zobrazení žádný převod ani jiný
zápis nespouští.

DPPO 504 přebírá aktuální potvrzenou revizi v obou formátech. Pokud se po potvrzení
změnily účetní podklady období, výpočet DPPO posouzení odmítne a je potřeba je potvrdit znovu.
Výpočet, finalizace i XML DPPO navazujícího období a kontrola posouzení v roční uzávěrce
fungují i tehdy, když jsou předchozí období posouzená v novém formátu. Starším
formátem ale navazující období posoudit nelze: aplikace takové posouzení odmítne
a vyzve k potvrzení v novém formátu.

U SVJ jsou doložené příspěvky vlastníků na správu domu a pozemku a úhrady služeb
vedeny odděleně jako příjmy mimo předmět daně. Toto zařazení nelze změnit ani ručním
posouzením historického období. Původ příspěvků musí navazovat na skutečný předpis
nebo na úplné převzetí záloh s doloženým účelem, vlastníky a účetní návazností.
Samotný zůstatek účtu dlouhodobých záloh není daňovým podkladem.

Celý doložený náklad správy je daňově neuznatelný, i když byla čerpána pouze část záloh
nebo bylo čerpání stornováno. Náklad související také s vlastním zdanitelným příjmem SVJ
musí být předem průkazně rozdělen na samostatné účelové řádky. Nejasné rozdělení aplikace
odmítne; zbývající částku nelze určit podle výše nevyčerpaných záloh. Úroky z bankovního
účtu, přijaté sankce a příjmy vlastníků ze společných částí se posuzují samostatně.

## 43.12 Související kapitoly

- [Archiv podání a daňová rekonciliace](49_Archiv_podani_a_rekonciliace.md)
- [Výkazy DPH](41_Vykazy_DPH.md)
- [Daňová evidence](74_Danova_evidence.md)
- [Účetní závěrka](72_Uzaverka.md)
- [Účtová osnova](66_Ucetni_osnova.md)
