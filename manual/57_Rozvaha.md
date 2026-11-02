# 57. Rozvaha

> Sestavení rozvahy podle účetního rámce vybraného období, vyhlášky č. 500/2002 Sb. nebo č. 504/2002 Sb. Kapitola je pro účetní v podvojném účetnictví.

## 57.1 Kdy to potřebujete

Výkaz respektuje účetní rámec platný ve zvoleném období. Pro vyhlášku 504/2002 Sb. zobrazuje počáteční a konečný stav, oprávky jako záporné položky a výsledek hospodaření pouze v konečném sloupci. Rozvaha obsahuje výsledek ve schvalovacím řízení pouze na počátku období. Pokud není do rozvahového dne vypořádán, použijte průběžný přehled zůstatků a doplňte rozhodnutí o výsledku před sestavením závěrkového formuláře. Při volbě budoucího období 504 a prázdného data výkazu se použije první den tohoto období. Editor mapování 500 se pro tento rámec nepoužívá. Následující popis brutto, korekcí a minulého období se týká vyhlášky 500.

Kapitolu otevřete, když:

- potřebujete rozvahu k určitému dni pro klienta, banku nebo finanční úřad,
- se blíží uzávěrka a chcete ověřit, že aktiva sedí na pasiva,
- vás upozornila kontrola, že řádek aktiv má záporné netto,
- se účet zobrazuje v jiném řádku výkazu, než je správně (splatnost, spřízněné osoby, opravná položka),
- chcete rozvahu srovnat s přílohou už podaného přiznání k dani z příjmů právnických osob,
- potřebujete rychlý seznam rozvahových účtů se zůstatky bez zákonné struktury.

Rozvaha je dostupná jen pro podvojné účetnictví.

## 57.2 Než začnete

1. **Zaúčtované doklady a doúčtované koncepty.** Rozvaha čte zaúčtované řádky deníku. Před sestavením projděte [Obratovou předvahu](56_Obratova_predvaha.md) a odstraňte červené kontroly.
2. **Účtový rozvrh a mapa výkazů.** Každý rozvahový účet musí být zařazen do některého řádku. Nezařazený nenulový účet je v kontrole označen jako nenamapovaný.
3. **Nastavení uzávěrky.** Volby, které ovlivňují rozvahu (souhrnné vykázání daní, převzetí minulého období z uzavřeného výkazu), jsou v **Nastavení uzávěrky a výkazů** na stránce `Uzávěrka`. Viz [§ 57.7.2](#5772-zustatky-znamenka-a-mapovani).
4. **Oprávnění.** Úpravy mapování vyžadují oprávnění k účetnictví.

## 57.3 Krok za krokem: sestavení rozvahy

1. Otevřete `Účetnictví → Rozvaha`.
2. V poli **Období** zvolte fiskální rok.
3. V poli **Rozvahový den** zvolte datum uvnitř období. Prázdná hodnota znamená dřívější z posledního dne období a dneška.
4. V poli **Rozsah** ponechte **Automaticky (dle kategorie ÚJ)**, nebo zvolte **Plný rozsah**, **Zkrácený - malá ÚJ** či **Zkrácený - mikro ÚJ**.
5. Podle potřeby přepněte **Kč / tis. Kč**. Mění jen zobrazení, výpočet i export zůstávají v korunách.
6. Pro průběžnou rozvahu ke klientské výsledovce zaškrtněte **Průběžné časové rozlišení**. Přesah dokladů s obdobím od–do za rozvahovým dnem se ukáže na 381 a 384 a výsledek běžného období se upraví stejně jako ve výsledovce ([§ 58.7.7](58_Vysledovka_druhova.md#5877-prubezne-casove-rozliseni)).
7. Zkontrolujte kontrolu **Aktiva = Pasiva** (porovnává **Aktiva netto** a **Pasiva celkem** na haléře).
8. Podezřelý řádek rozbalte kliknutím. Uvidíte **Účty řádku**, částku a u aktiv cíl Brutto nebo korekce. Kód účtu otevře opis od začátku období do rozvahového dne.
9. Klikněte na **Export PDF** nebo **Export XLSX**.

**Jak poznáte, že je hotovo:** **Aktiva = Pasiva** je zelená, nad tabulkou není varování o záporném netto a výsledek hospodaření v rozvaze odpovídá výsledku z nákladových a výnosových účtů.

> [!WARNING]
> Zelená rovnost stran není kontrola věcného zařazení. Rozvaha je odvozena z účtového rozvrhu a mapy výkazu. Podezřelý řádek rozbalte a jeho účty ověřte v opisu.

## 57.4 Krok za krokem: zařazení účtu do jiného řádku

Použijte, když číslo účtu nestačí k určení řádku: splatnost (například půjčka od společníka splatná za víc než rok), spřízněné osoby, konkrétní analytika, opravná položka k pohledávce.

1. Otevřete `Účetnictví → Mapování účtů do výkazů` a zvolte záložku **Rozvaha** (nebo **Výsledovka**).
2. Najděte účet. Tabulka ukazuje zůstatek ke konci období, aktuální řádek výkazu a zdroj zařazení.
3. Ve sloupci **Řádek výkazu** vyberte cílový řádek. Volba **(podle globální mapy)** výjimku zruší.
4. U saldového účtu můžete ve sloupci **Strana zůstatku** výjimku omezit volbou **Jen debetní** nebo **Jen kreditní**. U řádku aktiv lze účet zařadit volbou **Jako korekce**.
5. U opravné položky zařazené jako korekce zapište do pole **k pohledávce** účet pohledávky, například 351.100.
6. Chcete-li výjimku omezit na roky, vyplňte **Platí v letech**.
7. Do pole **Poznámka** zapište důvod výjimky, například splatnost.
8. Klikněte na **Náhled dopadu**. Ukáže řádky, jejichž hodnota se změní, a upozorní, kdyby rozvaha přestala být vyrovnaná. Nic neukládá.
9. Klikněte na **Uložit** v liště dole. **Zahodit změny** vrátí uložený stav.

**Jak poznáte, že je hotovo:** Účet je v novém řádku rozvahy, kontrola **Aktiva = Pasiva** zůstává zelená a zmizelo varování o záporném netto.

### 57.4.1 Návrh z podaného přiznání

1. Na stránce Mapování účtů do výkazů klikněte na **Navrhnout z podaného přiznání**. Aplikace porovná přílohu účetní závěrky s přílohou podaného přiznání za rok.
2. Přiznání, které v evidenci není, nahrajete tlačítkem **Navrhnout z XML přiznání** v nabídce dalších akcí.
3. Vyberte návrhy, kterým věříte. Návrh označený jako nejistý není předvybraný.
4. Klikněte na **Převzít vybrané** a změny uložte tlačítkem **Uložit**.

Podrobnosti jsou v [§ 57.7.7](#5777-mapovani-uctu-pro-konkretni-firmu).

## 57.5 Krok za krokem: rozvaha po účtech

1. Otevřete `Účetnictví → Rozvaha` a zvolte záložku **Účet 702 (po účtech)**.
2. Nastavte **Rozvahový den**. Rozsah výkazu se tu nepoužívá.
3. Projděte aktiva vlevo a pasiva vpravo. Přepínač **Zobrazit analytiky** analytiky skryje, **Skrýt nulové účty** schová účty bez zůstatku a pohybu.
4. Klikněte na účet. Otevře se inventura účtu, odtud vede opis i otevřené položky.
5. Zkontrolujte zvýrazněný řádek **Výsledek hospodaření** pod součtem rozvahových účtů.
6. Klikněte na **Export PDF** nebo **Export XLSX**.

**Jak poznáte, že je hotovo:** Výsledek hospodaření z rozvahových účtů se neliší od výsledku z výsledkových účtů. Rozdíl stránka ohlásí nad tabulkou.

## 57.6 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| **Aktiva = Pasiva** je červená | Nesoulad v zápisech nebo v mapě; nejde o zaokrouhlení | Prověřte [Obratovou předvahu](56_Obratova_predvaha.md), mapu účtů a závěrkové zápisy. |
| **Řádky aktiv se záporným netto** | Korekce je vyšší než brutto, nejčastěji je opravná položka zařazená jinam než pohledávka | Založte výjimku mapování s vazbou **k pohledávce** ([§ 57.4](#574-krok-za-krokem-zarazeni-uctu-do-jineho-radku)). |
| **Výkaz nemá pro zvolené parametry žádná data.** | Ve zvoleném období a dni nejsou zaúčtované řádky | Zkontrolujte **Období** a **Rozvahový den**. |
| Nenamapovaný nenulový účet | Účet nepokrývá žádná mapa | Zařaďte ho výjimkou mapování, jinak může výkaz zůstat neúplný. |
| Sloupec minulého období nesedí s uzavřeným výkazem | Výjimky firmy se ve sloupci minulého období řídí pravidly běžného roku | V **Nastavení uzávěrky a výkazů** zapněte **Minulé období výkazů převzít z uzavřeného výkazu minulého roku**. |
| Kladný běžný účet a kontokorent se nevzájemně nezkompenzovaly | Saldové účty se počítají po analytikách | Nejde o chybu, viz [§ 57.7.2](#5772-zustatky-znamenka-a-mapovani). |
| Rozvahový den je odmítnut | Leží mimo období | Zvolte datum uvnitř období. |

## 57.7 Podrobnosti a pravidla

### 57.7.1 Období, den a verze

- **Období** určuje fiskální rok.
- **Rozvahový den** musí ležet uvnitř období. Prázdná hodnota znamená dřívější
  z posledního dne období a dneška.
- **Rozsah** volí automatický, plný, zkrácený pro malou nebo zkrácený pro
  mikro účetní jednotku.
- **Kč / tis. Kč** mění pouze obrazovkové zobrazení. Výpočet i export zůstávají
  v korunách.
- **Průběžné časové rozlišení** přičte k zůstatkům deníku odklad dokladů s obdobím
  od–do na 381 a 384 k rozvahovému dni mimo deník; pravidla popisuje
  [§ 58.7.7](58_Vysledovka_druhova.md#5877-prubezne-casove-rozliseni). Výchozí je
  vypnuté a volba platí společně s výsledovkou.

Systém vybere verzi definice výkazu platnou k rozvahovému dni. Její kód se
zobrazuje v záhlaví. Minulé období se sestaví z předchozího fiskálního období
k jeho poslednímu dni, ale se stejnou verzí řádků a mapy jako běžný výkaz,
aby byly sloupce srovnatelné. Výjimky mapování firmy a souhrnné vykázání daní
se ve sloupci minulého období řídí pravidly běžného roku; změnu zařazení pak
vysvětlete v příloze. Kdo chce sloupec minulého období převzít tak, jak byl
v uzavřeném výkazu minulého roku, zapne v **Nastavení uzávěrky a výkazů** na stránce `Uzávěrka`
volbu **Minulé období výkazů převzít z uzavřeného výkazu minulého roku**: sloupec
se pak sestaví s výjimkami a volbami platnými v minulém roce.

### 57.7.2 Zůstatky, znaménka a mapování

Zdrojem jsou syntetické zůstatky všech zaúčtovaných řádků do rozvahového dne.
Analytiky se standardně sčítají pod syntetiku; obsahuje-li mapa delší analytický
prefix, má přednost nejdelší shodný prefix. Podrozvahové účty a vlastní
závěrkový převod knih se vylučují. Výsledkové účty se pro výpočet výsledku
hospodaření omezí na začátek fiskálního období.

Pro každý účet se počítá saldo `MD - Dal`:

- aktivní účet přispívá saldem do **Brutto**,
- korekční účet se obrátí do **Korekce** a odečte se ve vztahu
  `Netto = Brutto - Korekce`,
- pasivní účet se znaménkově obrátí, aby kreditní zůstatek vyšel kladně,
- případný koeficient mapy `sign` umí hodnotu řádku obrátit.

Mapa je verzovaná a při více shodách používá nejdelší prefix. Podmínka
`debit` nebo `credit` mapuje tentýž saldový účet do aktiv či pasiv podle
skutečné strany zůstatku. U takových účtů se saldo nejprve počítá po
analytikách a teprve potom se kladné a záporné zůstatky sečtou odděleně.
Proto se například kladný běžný účet a záporný kontokorent na analytikách 221
vzájemně nezkompenzují. Stejný princip chrání saldové účty 336, 341–346, 395
a 481.

Analytiky **311D** a **461K** mají delší mapu pro dlouhodobé obchodní
pohledávky a krátkodobou část dlouhodobého úvěru. Nezařazený nenulový
rozvahový účet je vrácen v kontrole jako nenamapovaný; účetní jej musí
správně zařadit, jinak může výkaz zůstat neúplný.

#### 57.7.2.1 Souhrnné vykázání daní vůči finančnímu úřadu

Daňové pohledávky a závazky se ve výchozím stavu vykazují zvlášť: přeplatek
jedné daně v aktivech (**Stát - daňové pohledávky**), nedoplatek jiné
v pasivech (**Stát - daňové závazky a dotace**). Vyhláška v § 58 odst. 2 za
vzájemné zúčtování nepovažuje souhrnné vykázání pohledávek a závazků vůči téže
osobě se splatností do jednoho roku. Volba **Daně vůči finančnímu úřadu
vykazovat v rozvaze souhrnně** v **Nastavení uzávěrky a výkazů** na stránce `Uzávěrka` proto
započte přeplatky a nedoplatky na účtech 341 až 345 (daň z příjmů, ostatní
přímé daně, DPH, ostatní daně); dotace (346) a pojistné (336) do započtení
nevstupují. Aktiva i pasiva klesnou o stejnou, menší z obou částek, v detailu
řádku je vidět jako samostatná položka. Pole **Od účetního období** omezí
volbu na roky, kdy ji firma používá. Souhrnné vykázání je třeba uvést
v příloze; příloha u účetních zásad nabídne větu se započtenými částkami.

### 57.7.3 Strom a výpočtové řádky

Řádek typu **detail** obsahuje přímo namapované účty. **Mezisoučet** sčítá
dceřiné řádky a případné vlastní přímé mapování. **Vypočtený řádek** používá
definovaný vzorec a může k němu přičíst vlastní mapované účty.

Aktiva zobrazují Brutto, Korekci, Netto a Netto minulého období. Pasiva
zobrazují běžné a minulé období. Kliknutí na řádek s přímými příspěvky
rozbalí účty, částku a u aktiv cíl Brutto/Korekce; kód účtu vede do opisu od
začátku období do rozvahového dne.

Řádek **Výsledek hospodaření běžného období** se počítá přímo ze všech
výsledkových účtů jako `Σ výnosy (Dal - MD) - Σ náklady (MD - Dal)`. Při
sestavení před koncem období se saldo účtu 431 mapuje do výsledku minulých let;
k poslednímu dni období patří do řádku běžného výsledku spolu s vypočteným
výsledkem.

### 57.7.4 Kontroly

Kontrola **Aktiva = Pasiva** porovnává Aktiva netto a Pasiva celkem na haléře.
Další vazba porovnává výsledek hospodaření v rozvaze s výsledkem vypočteným
přímo ze všech nákladových a výnosových účtů. Nenamapované nenulové účty
hlásí kontrola samostatně; kontrolní blok na stránce zobrazuje
rovnost stran a obě bilanční částky.

Řádek aktiv se **záporným netto** (korekce vyšší než brutto) stránka ukáže nad
tabulkou jako varování, v běžném i minulém období. Nejčastější příčinou je
opravná položka zařazená jinam než pohledávka, ke které patří, třeba celá 391
v obchodních pohledávkách, zatímco pohledávka je výjimkou v dlouhodobých.
Opravte ji výjimkou mapování s vazbou na pohledávku ([§ 57.4](#574-krok-za-krokem-zarazeni-uctu-do-jineho-radku)). Stejné
varování zapíše do protokolu převod dat z jiného programu.

Nesoulad není zaokrouhlovací rozdíl obrazovky; před použitím výkazu je nutné
prověřit obratovou předvahu, mapu účtů a závěrkové zápisy.

### 57.7.5 Kategorie účetní jednotky a rozsah

Automatický rozsah vychází z nejnižší kategorie, u které účetní jednotka
nepřekračuje alespoň dvě ze tří mezí:

| Kategorie | Aktiva netto | Čistý obrat | Průměrný počet zaměstnanců |
|---|---:|---:|---:|
| Mikro | 11 000 000 Kč | 22 000 000 Kč | 10 |
| Malá | 120 000 000 Kč | 240 000 000 Kč | 50 |
| Střední | 600 000 000 Kč | 1 200 000 000 Kč | 250 |
| Velká | nad limity střední kategorie | nad limity střední kategorie | nad limity střední kategorie |

Aktiva netto používají stejnou mapu jako rozvaha. Čistý obrat pro kategorizaci
je obrat účtů 601, 602 a 604; zaměstnance přebírá nastavení účetnictví.
Limity jsou načteny podle roku konce období.

Po uzavření se kritéria a hrubá kategorie období zmrazí. Změna kategorie se
uplatní podle dvou po sobě jdoucích uzavřených období se stejnou hrubou
kategorií. Ruční přepis rozsahu má přednost, povinný audit však vždy vynutí
plný rozsah.

Rozsah řádků:

- **plný** - bez omezení úrovně,
- **malá** - rozvaha do druhé úrovně a navíc povinné řádky C.II.1. a C.II.2.,
- **mikro** - jen nejvyšší úroveň,
- **automaticky** - mikro → mikro, malá → malá, střední/velká → plný.

### 57.7.6 Export

PDF a XLSX používají stejný rozvahový den, rozsah, verzi mapy, srovnávací
období a kontroly jako obrazovka. Přepínač tisíců export neovlivňuje.

### 57.7.7 Mapování účtů pro konkrétní firmu

Globální mapa zařazuje účet podle čísla syntetiky. Předpis ale nechává firmě
volby, které z čísla účtu vyčíst nejde. Pro ně slouží výjimky mapování, které
platí jen pro vybranou firmu:

- **splatnost** - půjčka od společníka na analytice 365.100 splatná za víc
  než rok patří do dlouhodobých závazků (C.I.), ne do krátkodobých (C.II.),
- **spřízněné osoby** - pohledávky, závazky, výnosy a náklady vůči ovládané
  nebo ovládající osobě patří do vlastních řádků a podřádků výkazů,
- **zařazení analytiky** - konkrétní analytika může patřit do jiného řádku
  než její syntetika.

Stránka má záložky **Rozvaha** a **Výsledovka**. Tabulka ukazuje účty firmy,
jejich zůstatek ke konci vybraného období a řádek, kam je výkaz dnes zařadí,
spolu se zdrojem zařazení (globální mapa, mapa funkcí u účelové výsledovky,
výjimka firmy). Ve sloupci **Řádek výkazu** se vybírá cílový řádek ze stromu
řádků výkazu, volba **(podle globální mapy)** výjimku ruší. U saldového účtu
lze výjimku omezit na debetní nebo kreditní zůstatek; opačná strana se pak
řídí mapou bez výjimky. U řádku aktiv lze účet zařadit jako korekci. Poznámka
slouží k zapsání důvodu, například splatnosti.

Výjimka se zadává prefixem účtu stejně jako globální mapa. Při více shodách
vyhrává nejdelší prefix a při stejné délce vyhrává výjimka firmy. Výjimka na
analytiku (365.100) proto přesune jen tu analytiku, ostatní analytiky
syntetiky zůstanou podle globální mapy.

**Opravná položka k pohledávce.** U výjimky zařazené jako korekce lze do pole
**k pohledávce** zapsat účet pohledávky (například 351.100). Korekce pak jde
vždy do řádku, kam výkaz zařadí tuto pohledávku, i když ji později přeřadíte;
vybraný řádek platí jen tehdy, když účet pohledávky v mapě není. Netto řádku
pohledávky tak odpovídá pohledávce snížené o její opravnou položku a opravná
položka nesnižuje obchodní pohledávky.

**Platnost po letech.** Sloupec **Platí v letech** omezí výjimku na účetní
období od roku / do roku (prázdné = bez omezení). Tabulka ukazuje výjimky
platné ve vybraném období; výjimky jiných let jsou v přehledu pod tabulkou.
Pro jeden účet a stranu zůstatku se roky platnosti výjimek nesmí překrývat.
Když účet už výjimku jiných let má, nová výjimka vybraná v tabulce platí od
roku vybraného období.

Změny se nejdřív jen připravují a ukládají se najednou tlačítkem **Uložit**
v liště dole; **Zahodit změny** vrátí uložený stav. **Náhled dopadu** ukáže
řádky výkazu, jejichž hodnota se po uložení změní, a upozorní, kdyby rozvaha
přestala být vyrovnaná. Náhled nic neukládá.

Výjimky používá rozvaha, obě výsledovky, jejich PDF a XLSX exporty, měsíční
report, uzávěrkový balík, kategorie účetní jednotky i příloha účetní závěrky
v přiznání k dani z příjmů právnických osob.

#### 57.7.7.1 Návrh z podaného přiznání

Když je za rok v evidenci podané přiznání k dani z příjmů právnických osob,
tlačítko **Navrhnout z podaného přiznání** porovná přílohu účetní závěrky
(aktiva, pasiva a výsledovku v celých tisících Kč), jak ji vyrobí aplikace,
s přílohou podaného přiznání. Kde se dva řádky liší opačně o částku, která
odpovídá zůstatku jednoho účtu nebo analytiky (s tolerancí 1 tis. Kč na
zaokrouhlení), navrhne tento účet přeřadit do řádku, kde ho má podané
přiznání. Přiznání, které v evidenci není, lze nahrát jako XML přes
**Navrhnout z XML přiznání** v nabídce dalších akcí.

U aktiv se brutto a korekce porovnávají zvlášť. Návrh tak najde přesun
pohledávky i její opravné položky, i když jsou v aplikaci každá v jiném řádku,
a korekci přesunutou do stejného řádku jako pohledávku k ní rovnou naváže.

Podané přiznání nese i sloupec minulého období, jak byl v uzavřeném výkazu
minulého roku. Blok **Minulé období** navrhne výjimky platné do minulého roku,
se kterými bude sloupec minulého období shodný s podaným (při zapnutém převzetí
z uzavřeného výkazu). Má-li účet výjimku bez omezení, převzetí ji omezí na
pozdější roky a návrh přidá pro minulý rok.

Každý návrh uvádí účet, výchozí a cílový řádek a odůvodnění. Návrh, který by
stejně dobře vysvětlil víc účtů, je označený jako nejistý a není předvybraný.
Vybrané návrhy se tlačítkem **Převzít vybrané** přenesou do připravovaných
změn; uloží se až tlačítkem **Uložit**. Pod návrhy je rozbalovací seznam všech
rozdílných řádků přílohy.

> [!WARNING]
> **Zelená rovnost stran není kontrola věcného zařazení.** Rozvaha je
> odvozena z účtového rozvrhu a mapy výkazu; podezřelý řádek rozbalte a jeho
> účty ověřte v opisu.

### 57.7.8 Rozvaha po účtech

Záložka **Účet 702 (po účtech)** nad rozvahou ukáže místo zákonné struktury
rozvahové účty tříd 0 až 4, aktiva vlevo a pasiva vpravo na jednom řádku. Stranu
určuje typ účtu, ne zůstatek: pohledávka v kreditu zůstává v aktivech se záporným
znaménkem. Účty jsou seřazené po třídách, pod syntetikou jsou její analytiky
(přepínač **Zobrazit analytiky** je skryje).

Každá strana má sloupce **Počáteční stav**, **Obrat** měsíce rozvahového dne, **Obrat
od** začátku období a **Konečný stav**. Sloupce mají znaménko podle typu účtu: aktiva
`MD − Dal`, pasiva a vlastní kapitál `Dal − MD`, takže u každého účtu platí
`počáteční stav + obrat od začátku období = konečný stav`. Oprávky jsou v aktivech
záporně, účet 429 v pasivech také. Zůstatek na neobvyklé straně podle účtové osnovy je
červeně. Oprávky ani účty bez určené strany (261, 343, 395, 431) neobvyklé nejsou.

Přepínač **Skrýt nulové účty** schová účty, které mají ve všech čtyřech sloupcích
nulu, takže žádný součet nezmění. Účet s počátečním stavem a konečným stavem 0 se
ukáže, protože v něm je pohyb. Účty bez jediného zápisu sestava neobsahuje.

Pod součty stran je zvýrazněný řádek **Výsledek hospodaření** (zisk zeleně, ztráta
červeně) jako `aktiva celkem − pasiva celkem` a pod ním **Pasiva s výsledkem
hospodaření**, která se rovnají aktivům. Bez uzávěrky je výsledek k dispozici
kdykoli během roku. Zůstatky jsou vždy před uzavřením účetních
knih: uzávěrkový zápis se nezapočítává, takže po uzavření roku pohled k poslednímu
dni období ukazuje přesně to, co uzávěrka převedla na konečný účet rozvažný 702.

Kliknutím na účet se otevře jeho inventura ([Opis účtu](55_Hlavni_kniha.md#5585-opis-uctu)) s rozsahem
od začátku období do rozvahového dne. Rozvahový den a filtr dimenze platí stejně jako u výkazu, rozsah výkazu se
tu nepoužívá. Když se výsledek z rozvahových účtů neliší od výsledku
z výsledkových účtů ([Výsledovka po účtech](58_Vysledovka_druhova.md#5876-vysledovka-po-uctech)), je vše v pořádku;
rozdíl stránka ohlásí nad tabulkou a obvykle ukazuje na chybu v počátečních
stavech nebo v uzávěrce minulého roku. Při filtru dimenze obsahují rozvahové
účty jen řádky s touto hodnotou, takže se jejich výsledek od výsledovky
lišit může; stránka to pak ukáže jen jako upozornění a za výsledek dimenze
platí výsledovka.

Export XLSX z této záložky má stejný tvar jako obrazovka včetně přepínačů, jednotky
**Kč / tis. Kč** a červeně označené neobvyklé strany. Export PDF je soupis po třídách
se zůstatky MD a Dal.

## 57.8 Související kapitoly

- [Obratová předvaha](56_Obratova_predvaha.md) - kontrola úplnosti a podvojnosti před výkazy
- [Výsledovka druhová](58_Vysledovka_druhova.md) a [účelová](59_Vysledovka_ucelova.md)
- [Účtový rozvrh](66_Ucetni_osnova.md) a [Uzávěrka](72_Uzaverka.md)
- [Daň z příjmů](43_Dan_z_prijmu.md) - příloha účetní závěrky v přiznání
