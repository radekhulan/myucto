# 65. Šablony a pravidla

> Návod, jak si nechat předvyplňovat opakované účetní zápisy, klasifikaci nákladů
> a účtování bankovních plateb. Pro účetní firmy v podvojném účetnictví.

## 65.1 Kdy to potřebujete

Kapitolu otevřete, když:

- často zadáváte stejný vícerádkový ruční zápis (například mzdy),
- aplikace u přijatých faktur špatně hádá druh nákladu nebo účet,
- se opakují bankovní platby bez dokladu (poplatky, odvody, úroky, splátky úvěru) a nechcete je zaúčtovávat ručně,
- převzali jste firmu s historickým účetnictvím a chcete z něj odvodit pravidla,
- chcete doplnit předkontace o analytické účty.

Stránka `Nástroje → Šablony účtování` má pět záložek. Nejde o jeden univerzální
automat: každá záložka zasahuje do jiné části zpracování.

<!-- cols: 26 44 30 -->
| Záložka | Co ovlivňuje | Postup |
|---|---|---|
| **Šablony zápisů** | Předvyplnění řádků ručního účetního zápisu | [§ 65.3](#653-krok-za-krokem-sablona-ucetniho-zapisu) |
| **Pravidla nákladů** | Návrh druhu nákladu a účtu na řádku přijaté faktury | [§ 65.4](#654-krok-za-krokem-pravidlo-nakladu) |
| **Pravidla výnosů** | Účet položky vydané faktury podle kódu, textu nebo sazby DPH | [§ 65.10.9](#65109-pravidla-vynosu) |
| **Pravidla účtování** | Návrh nebo automatizace opakovaných bankovních plateb | [§ 65.5](#655-krok-za-krokem-pravidlo-uctovani-banky) |
| **Šablony pravidel** | Katalog předloh, ze kterých tlačítko **Ze šablony** založí pravidlo účtování | [§ 65.6](#656-krok-za-krokem-sablona-bankovniho-pravidla) |

Záložka **Šablony pravidel** se zobrazí jen s oprávněním ke správě bankovních pravidel.
Kdo toto oprávnění má, ale Šablony účtování nevidí, najde katalog v nabídce `Nástroje`
jako samostatnou stránku **Šablony bank. pravidel**. Vedle toho je v nabídce `Nástroje`
**Asistent nastavení účtování** ([§ 65.8](#658-krok-za-krokem-asistent-nastaveni-uctovani)).

## 65.2 Než začnete

1. **Podvojné účetnictví.** Všechny záložky se zobrazují jen firmě v podvojném
   účetnictví. U firmy v daňové evidenci stránka nabídne odkaz **Otevřít Nastavení → Daně a účetnictví**.
2. **Správná firma.** Šablony a pravidla jsou firemní a používají firmu zvolenou
   v hlavní liště aplikace. Pravidla druhé firmy se nepřimíchávají (u **Pravidel
   účtování** je jméno firmy uvedeno i nad seznamem).
3. **Oprávnění.** Šablony zápisů si čtete s oprávněním k šablonám účtování a měníte
   se zápisovou variantou téhož oprávnění. Pravidla nákladů a výnosů používají účetní
   oprávnění. Bankovní pravidla a katalog jejich šablon řídí oprávnění ke správě
   bankovních pravidel: čtení zobrazí, zápis dovolí správu (viz [§ 65.10.6](#65106-opravneni-audit-a-chyby)).
4. **Účtový rozvrh.** Účty použité v šabloně a pravidlech musí v osnově firmy
   existovat a být aktivní (viz [Účtový rozvrh](66_Ucetni_osnova.md)).

> [!TIP]
> Změna pravidla se použije na nové návrhy. Již zaúčtované zápisy sama zpětně nepřepočítává.

## 65.3 Krok za krokem: šablona účetního zápisu

Šablona ukládá název, popis a libovolný počet řádků. Řádek má aktivní účet z účtového
rozvrhu, stranu **MD** nebo **Dal**, volitelnou výchozí částku, popis řádku a volitelné
nákladové středisko.

**Založení šablony:**

1. Otevřete `Nástroje → Šablony účtování` a záložku **Šablony zápisů**.
2. Klikněte na **Nová šablona**.
3. Vyplňte název a přidejte řádky. U každého řádku zvolte účet, stranu a podle potřeby částku, popis a středisko. Každý řádek musí mít účet.
4. Klikněte na **Uložit**.

Šablonu můžete založit i z rozepsaného ručního zápisu tlačítkem **Uložit jako šablonu**.
Zaškrtnutím volby **Uložit i aktuální částky jako výchozí** se uloží i částky. Bez ní zůstanou částky prázdné k doplnění, což se hodí u mezd a jiných proměnných položek.

**Použití šablony:**

1. V seznamu šablon klikněte u šablony na **Nový zápis**. Otevře se ruční zápis s předvyplněnými řádky.
2. Doplňte datum, číslo dokladu, popis a chybějící částky.
3. Zápis uložte. Musí být vyrovnaný (součet MD = součet Dal).

Stejný výběr šablon nabízí i ruční zápis (**Použít šablonu**) a náhled kontace dokladu.
Použití šablony změní jen návrh řádků, nikoli zdrojový doklad.

Částky z externí rekapitulace (například mzdové) můžete napárovat na řádky vybrané šablony
volbou **Import rekapitulace z CSV**: soubor má dva sloupce, název položky nebo kód účtu a částku.
Samotné nahrání jen napáruje řádky, nic se nezaúčtuje.

**Jak poznáte, že je hotovo:** Šablona je v seznamu s počtem řádků. Zápis z ní vzniklý je
v `Účetnictví → Účetní deník`.

> [!WARNING]
> Šablona není účetním dokladem a její výchozí částky nejsou důkazem správnosti.
> Před zaúčtováním je zkontrolujte.

## 65.4 Krok za krokem: pravidlo nákladů

Pravidlo předvyplní na řádku přijaté faktury druh nákladu (a případně účet).

1. Otevřete `Nástroje → Šablony účtování` a záložku **Pravidla nákladů**.
2. Klikněte na **Nové pravidlo**.
3. Vyplňte **Název pravidla**.
4. V části **Kritéria** vyplňte aspoň jedno z polí **Dodavatel**, **Název dodavatele obsahuje** nebo **Popis položky obsahuje**. Volitelně zúžíte shodu polem **Rozpětí částky** (od, do).
5. Zvolte **Druh nákladu**. Podle něj se předvyplní účet: služba 518, materiál 501, drobný majetek 501 (s kartou v evidenci majetku), dlouhodobý majetek 042.
6. Chcete-li jiný účet, než odpovídá druhu, vyplňte **Cílový účet (nepovinné)**. Například pojistné je služba, ale patří na 548.
7. Nastavte **Prioritu** (0 až 999, výchozí 100, nižší jde první).
8. V **Režim použití** zvolte **Jen navrhovat**, nebo **Použít automaticky**.
9. Klikněte na **Uložit**.

Seznam pravidel lze filtrovat podle druhu nákladu a stavu. Ve sloupci **Použití** vidíte, kolikrát se pravidlo uplatnilo.

**Jak poznáte, že je hotovo:** Při příštím importu nebo ručním zadání přijaté faktury s odpovídajícím
dodavatelem či textem se na řádku nabídne zvolený druh nákladu a účet.

Pravidlo v režimu **Použít automaticky** zapíše druh nákladu i účet na řádky rovnou při AI vytěžení, takže je
kontrola vytěžení už nenabízí jako návrh AI. Pravidlo v režimu **Jen navrhovat** zůstane návrhem, který převezmete
tlačítkem **Použít**. Pokud u řádku sami zvolíte jiný druh nákladu nebo účet, pravidlo ho už nepřepíše.

> [!TIP]
> Automatizaci nasazujte postupně: nejdřív pravidlo otestujte, několik návrhů ručně
> schvalte a teprve podle skutečných zásahů zvažte automatický režim. Pravidla vytvořená asistentem
> začínají v režimu návrhu (kromě pravidel silně doložených zaúčtovanou historií, viz [§ 65.10.7](#65107-jak-asistent-analyzuje-historii));
> na automatické použití je přepněte až po ověření na nových dokladech.

## 65.5 Krok za krokem: pravidlo účtování banky

Záložka spravuje opakované bankovní pohyby bez spolehlivě párovatelného dokladu, například
bankovní poplatky, odvody, úroky nebo splátky úvěru. Pracovní postup fronty **K zaúčtování**
je v kapitole [Bankovní účty](30_Bankovni_ucty.md).

**Z konkrétní platby** (nejjednodušší):

1. Otevřete frontu **K zaúčtování** a u platby klikněte na **Zaúčtovat…**.
2. Zaškrtněte **Vytvořit z této platby pravidlo**, doplňte **Název pravidla** a kritéria.
3. Volitelně zaškrtněte **Použít i na další odpovídající nezaúčtované pohyby**.
4. Potvrďte zaúčtování.

**Ručně:**

1. Otevřete `Nástroje → Šablony účtování` a záložku **Pravidla účtování**.
2. Vytvořte nové pravidlo a zvolte **Směr** (příchozí, odchozí).
3. Vyplňte aspoň jeden rozpoznávací znak: **Protiúčet**, **Variabilní symbol** nebo **Fragment zprávy**. Podle potřeby přidejte **Rozsah částky (Kč)**.
4. Zadejte kontaci MD/D. Bankovní strana musí být účet 221 (u příchozí platby na MD, u odchozí na D).
5. Pomocí **Otestovat na historii** zjistíte, kolika transakcím za 12 měsíců vzor odpovídá. Nic se při tom nezapisuje.
6. Uložte pravidlo. Nové pravidlo začíná v režimu **Návrh**.

Pravidla lze v seznamu upravit, vypnout, povýšit na automatické nebo smazat. Zaúčtované zápisy v deníku
smazáním pravidla zůstanou.

**Jak poznáte, že je hotovo:** Platby odpovídající vzoru se objeví v **K zaúčtování** jako návrh
(nebo se rovnou zaúčtují, je-li pravidlo automatické).

> [!WARNING]
> Saldokontní účty 311, 321, 314, 324 a 325 patří párování dokladů, ne obecnému pravidlu.
> Platby faktur se párují, neúčtují se pravidlem.

## 65.6 Krok za krokem: šablona bankovního pravidla

Každá firma má vlastní katalog typických bankovních pravidel.

1. Otevřete `Nástroje → Šablony účtování`, záložka **Šablony pravidel**.
2. Chcete-li přidat vlastní šablonu, klikněte na **Nová šablona** a vyplňte název a klíč, směr a kritéria, předkontaci, pořadí a stav **Aktivní**.
3. Na stránce bankovních pravidel klikněte u šablony na **Použít šablonu**. Vznikne pravidlo pro aktuální firmu.
4. Chybí-li údaj, který šablona potřebuje (například VS ČSSZ, číslo zdravotního pojištění nebo DIČ), aplikace nabídne **Otevřít nastavení**.

**Jak poznáte, že je hotovo:** U šablony vidíte **Šablona už je použita** a tlačítko **Otevřít pravidlo**.

Použitou šablonu nelze smazat, jen deaktivovat.

## 65.7 Krok za krokem: doplnit předkontace podle osnovy

Systémová mapa předkontací mluví syntetickými účty, například 518 nebo 321. Jakmile firma zavede
analytiky, je potřeba kontace posunout na ně.

1. Otevřete `Nástroje → Účetní nastavení` a záložku **Předkontace** (viz [Nástroje](73_Ucetni_nastroje.md)).
2. Klikněte na **Doplnit podle osnovy**. Otevře se náhled, který každou stranu pravidla porovná s osnovou firmy.
3. Volbou **Zobrazit jen řádky k řešení** skryjete položky v pořádku.
4. U řádků se stavem **K rozhodnutí** vyberte analytiku, nebo zvolte **Ponechat beze změny**.
5. Klikněte na **Zapsat vybrané změny**.

<!-- cols: 26 74 -->
| Stav | Význam |
|---|---|
| V pořádku | účet je analytika, nebo syntetika bez daňových analytik |
| Doplní engine | syntetika má jedinou daňovou analytiku, účtování na ni přejde samo |
| K rozhodnutí | syntetika má dvě a více daňových analytik, vybrat musí účetní |
| Určuje doklad | analytiku volí doklad sám (banka, pokladna, DPH) |
| Chybí účet | účet z pravidla není v osnově firmy a pravidlo nelze zaúčtovat |

**Jak poznáte, že je hotovo:** Aplikace ohlásí **Doplněno pravidel** s počtem a u řádků zůstane stav V pořádku.

Náhled nic nemění. Zapíší se jen zaškrtnuté řádky se stavem K rozhodnutí, a to jako firemní předkontace.
Nabízené účty jsou pouze daňové analytiky původního účtu. Nedaňová analytika (například 518.990) mezi nimi není,
protože ji vybírá daňový příznak dokladu, nikoli předkontace. U firmy, která už účtuje, je předvybraná ta
analytika, kterou má nejčastěji v deníku.

## 65.8 Krok za krokem: asistent nastavení účtování

Asistent je určený hlavně pro firmu s naimportovanou nebo historickou účetní databází, která dosud nemá
pravidla. Je širší než provozní Automat. Prochází položky přijatých faktur, jejich dosavadní kontace
a opakované bankovní pohyby. Výsledkem jsou návrhy analytických účtů, pravidel nákladů, předkontací,
bankovních pravidel, kandidátů na dlouhodobý majetek a upozornění na neúplná data.

Postup má tři oddělené kroky.

**Krok 1: Analýza historie.**

1. Otevřete `Nástroje → Asistent nastavení účtování`.
2. V části **Analýza historie** zvolte **Doklady od** a **Doklady do** (prázdné meze znamenají celou historii).
3. Máte-li pro firmu nakonfigurovanou a potvrzenou AI bránu, můžete zaškrtnout **Doplnit nerozpoznané položky pomocí AI** a zvolit **Rozsah AI analýzy** (nejvýše 50, 100 nebo 200 vzorků).
4. Klikněte na **Spustit analýzu**. Běží jako úloha na pozadí a nic nemění.

**Krok 2: Kontrola návrhů a vytvoření pravidel.**

1. Projděte návrhy v záložkách podle typu (pravidla nákladů, analytiky, předkontace, bankovní pravidla, majetek, kvalita dat). Filtr **Zdroj návrhu** odliší místní slovník, AI a vzory z historie.
2. Podporovaný návrh můžete před schválením upravit tlačítkem **Upravit návrh** (český název, klíčová fráze, účty MD/D) a uložit **Uložit návrh**.
3. Vyberte návrhy a klikněte na **Vytvořit vybrané**.
4. Chcete-li vytvořit balíček jen ze stávajících pravidel (opakovaná analýza nenašla nic nového), klikněte na **Použít aktivní pravidla**.

Z vybraných položek vznikne neměnný balíček a pravidla se uloží v režimu jen navrhovat, kromě pravidel silně
doložených zaúčtovanou historií. Tímto krokem se historické doklady ani deník nemění.

**Krok 3: Přeúčtování historie (volitelné).**

1. V části **Kontrola a přeúčtování historie** zvolte **Přeúčtovat doklady od** a **Přeúčtovat doklady do**.
2. Zvolte **Rozsah dokladů**: **Jen doklady odpovídající pravidlům** (bezpečná výchozí volba), nebo **Přeúčtovat všechny doklady v období**.
3. Klikněte na **Spustit dry-run**. Vypíše každý dotčený doklad, stav a účty před změnou i po ní. Číslo dokladu otevře stejný náhled zdroje jako účetní deník.
4. Souhlasí-li výsledek, klikněte na **Přeúčtovat historii** a potvrďte.
5. Potřebujete-li se vrátit, klikněte na **Obnovit původní účtování**. Když zálohu už nepotřebujete, smažte ji tlačítkem **Smazat zálohu obnovy**.

**Jak poznáte, že je hotovo:** Úloha je označena jako hotová a v dry-runu vidíte, kolik procent dokladů se
přeúčtuje. Po ostrém běhu mají doklady nové kontace v deníku.

> [!WARNING]
> Volba **Přeúčtovat všechny doklady v období** může nahradit ručně zadané účtování (doklady převzaté z jiného
> programu se ale nepřepisují nikdy). Ostrý běh
> vyžaduje dokončený dry-run stejného balíčku se zcela shodným rozsahem dat i režimem výběru.
> Smazání zálohy je nevratné: deník se nezmění, ale zmizí možnost obnovy daného běhu.

## 65.9 Když něco nejde

<!-- cols: 34 30 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Záložky ani šablony nejsou vidět | Firma vede daňovou evidenci | Přejděte na podvojné účetnictví v `Firma → Nastavení`, záložka **Daně a účetnictví** (viz [Aktivace účetnictví](68_Aktivace_ucetnictvi.md)). |
| Každý řádek šablony musí mít vyplněný účet | Řádek nemá účet | Doplňte aktivní účet z osnovy. |
| Vyplňte aspoň jedno kritérium | Pravidlo nákladů nemá dodavatele ani text | Vyplňte dodavatele, název dodavatele nebo popis položky. Samotné rozpětí částky pravidlo netvoří. |
| Neplatné rozpětí částky | Minimum je vyšší než maximum | Prohoďte hranice. |
| Priorita musí být v rozsahu 0 až 999 | Priorita mimo rozsah | Zadejte hodnotu 0 až 999. |
| Neplatný cílový účet | Cílem je saldokontní, DPH nebo bankovní účet, případně neaktivní účet | Zvolte aktivní nákladový účet. |
| Automatický režim nejde zapnout | Bankovní pravidlo nemá rozsah částky nebo ještě nemá úspěšné použití | Doplňte rozsah částky a nejdřív několik návrhů schvalte ručně. |
| Saldokontní účet nepatří do pravidla | Pravidlo míří na 311, 321 a podobně | Platbu faktury spárujte s dokladem. |
| Zápis nejde uložit | Období je uzavřené, datum zamčené nebo zápis není vyrovnaný | Zkontrolujte účetní období a rovnost MD/Dal. |
| Dodavatel nebo šablona patří jiné firmě | Vybrána položka z jiné firmy | Přepněte na správnou firmu v hlavní liště. |
| Šablonu banky nelze smazat | Už je použita v pravidle | Deaktivujte ji. |
| Demo režim blokuje změnu, přestože je tlačítko vidět | Demo režim může zápisy blokovat | Použijte ostrou instalaci. |

Při řešení chyby postupujte podle vrstvy:

1. špatně rozpoznaný druh nákladu: opravte pravidlo nákladů nebo řádek dokladu,
2. správný druh, ale chybný základní účet: opravte předkontaci,
3. nestandardní vícerádkový zápis: použijte nebo upravte šablonu,
4. opakovaná platba bez dokladu: opravte bankovní pravidlo.

## 65.10 Podrobnosti a pravidla

### 65.10.1 Šablony zápisů

Šablona zápisu není účetním dokladem. Při uložení zápisu server znovu ověří aktivitu účtů,
otevřené období, datumový zámek a rovnost MD/Dal. Účet šablony patří stejné firmě a musí být aktivní,
kód střediska se ověřuje proti firemnímu číselníku. Hlavička i řádky šablony se ukládají společně.

Při prvním načtení seznamu aplikace idempotentně doplní doporučenou mzdovou šablonu a předuzávěrkové
šablony. Opakované načtení nevytvoří kopie. Firemní šablony lze zakládat, upravovat a mazat.

### 65.10.2 Pravidla klasifikace nákladů

Pravidlo předvyplní jeden ze čtyř druhů: **služba** (výchozí účet 518), **materiál** (501),
**drobný majetek** (501) a **dlouhodobý majetek** (042). Místo výchozího účtu může určit konkrétní aktivní
nákladový účet. Saldokonto, DPH, banku a pokladnu nelze nastavit jako cílový nákladový účet.

Všechna vyplněná kritéria (dodavatel, fragment názvu dodavatele, fragment popisu položky, hranice částky)
se vyhodnocují současně (AND). Samotné cenové pásmo nestačí, pravidlo musí mít dodavatele nebo textový
fragment, jinak by nebezpečně zachytávalo nesouvisející nákupy.

Aktivní pravidla se zkoušejí podle nejnižšího čísla priority. Při stejné prioritě má přednost pravidlo
s více úspěšnými použitími a poté starší pravidlo. Vyhraje první shoda. Po potvrzeném použití se zvýší
počet zásahů a uloží čas posledního použití.

Volba **opakovaný předplacený náklad** může označit pravidelně hrazenou službu pro návrh časového rozlišení.
Nejde o automatické zaúčtování: uzávěrková logika musí stále ověřit období, významnost a podklad.

#### Od návrhu k deníku

Klasifikátor může spojit firemní pravidlo, text položky a výsledek importu nebo AI vytěžení. Do uložení
řádku jde jen o návrh. Potvrzený druh nákladu pak určí předkontaci při zaúčtování dokladu. DPH jde odděleně
přes evidenci DPH.

U drobného majetku lze z potvrzených řádků vytvořit evidenční karty. Karta nevytváří druhý nákladový zápis;
podrobnosti jsou v kapitole [Drobný majetek](27_Drobny_majetek.md).

> [!WARNING]
> Cenový práh ani text „notebook“ sám nerozhoduje, zda jde o drobný či dlouhodobý majetek, technické
> zhodnocení nebo soubor věcí. Konečné posouzení patří účetnímu a vnitřní směrnici firmy.

### 65.10.3 Pravidla účtování banky

Pravidlo obsahuje směr platby, účet MD/Dal a aspoň jeden rozpoznávací znak (protiúčet, variabilní symbol
nebo fragment zprávy). Lze přidat rozsah částky. Bankovní strana musí odpovídat účtu 221.

Nové pravidlo začíná v režimu navrhovat. Na automatický režim se povýší až po úspěšném použití a s bezpečným
rozsahem částky. Dry-run na historii nic nezapisuje. Volitelný backfill vytvoří návrhy i pro starší
nezaúčtované pohyby a nikdy nepřeúčtuje již zaúčtovanou transakci. Historický backfill vždy degraduje
automatický režim na návrh, aby stará data neúčtoval bez kontroly.

Schválení návrhu vytvoří idempotentní zápis, odmítnutí uloží důvod a auditní stopu. Opakovaně odmítané
pravidlo se může deaktivovat.

### 65.10.4 Šablony bankovních pravidel

Šablona má stabilní klíč, popis, výchozí kontaci, kritéria, režim a aktivní stav. Z šablony vznikne bankovní
pravidlo pro aktuální firmu; pozdější změna šablony již vytvořené pravidlo potichu nepřepíše. Katalogy jiných
firem se nezobrazují ani nemění. Aplikace ověřuje existenci účtů v osnově konkrétní firmy a neplatnou nebo
neaktivní šablonu nepoužije. Správa katalogu je oddělená od vytvořených bankovních pravidel.

### 65.10.5 Předkontace nejsou šablony

Předkontace je systémová mapa operace na základní dvojici účtů (například vydaná faktura za služby nebo
vzájemný zápočet). Šablona naproti tomu předvyplňuje celý ruční zápis. Pravidlo nákladů vybírá druh řádku
dokladu a bankovní pravidlo rozpoznává transakci. Technické klíče předkontací se uživateli nezobrazují jako názvy.

### 65.10.6 Oprávnění, audit a chyby

Aplikace všechny objekty omezuje na aktuální firmu a zaznamenává vytvoření, změnu, smazání, použití či
instalaci do auditní stopy. Typické chyby jsou neaktivní nebo neexistující účet, chybějící kritérium
pravidla, obrácené cenové pásmo, priorita mimo rozsah 0 až 999, dodavatel nebo šablona jiné firmy a uzavřené
či uzamčené účetní období (viz tabulka v [§ 65.9](#659-kdyz-neco-nejde)).

### 65.10.7 Jak asistent analyzuje historii

**Zaúčtovaná historie má přednost.** Asistent nejdřív projde, jak se přijaté faktury skutečně zaúčtovaly.
U převzatých dat to bývá i deset let historie a to, co v ní fungovalo, je spolehlivější než odhad podle
textu položky. Pravidlo nákladů dodavatel → účet vznikne, když má dodavatel aspoň tři zaúčtované doklady
a aspoň 90 % z nich šlo na stejný nákladový účet. Doklad se počítá jen tehdy, když jeden účet nese aspoň
95 % jeho nákladové částky. Haléřové zaokrouhlení ho tedy nerozbije, ale faktura za zboží i dopravu zůstává
smíšená a počítá se jako nesouhlas. Pokud se osnova v čase měnila, posoudí asistent ještě poslední dva roky
dodavatele. Dodavatele, který účtoval na více účtů, zkusí rozdělit klíčovým slovem z popisu položek
(například nafta proti servisu). Když ani to nedá čistou shodu, pravidlo nenavrhne a dodavatele uvede
v kvalitě dat mezi nejednoznačnými. Z historie se neučí pořízení majetku (účty 0xx).

Pravidla ze zaúčtované historie mají přednost před slovníkem i AI. Pokud stávající pravidlo posílá doklady
dodavatele jinam, než kam se roky účtovaly, dostane návrh prioritu těsně nad ním a u návrhu je vidět, které
pravidlo přebije. Když stávající pravidla už vedou na stejný účet, návrh nevznikne. Pravidlo s aspoň pěti
doklady a shodou aspoň 95 % se po schválení uloží v automatickém režimu; ostatní jen navrhují. Ruční změna
účtu nebo druhu nákladu v editoru návrhu vrátí pravidlo do režimu navrhování. Položky pokryté historií se
už nenabízejí slovníku ani AI.

**Analytické účty.** Pokud je nákladový účet v osnově plochý, může asistent navrhnout analytiky pro opakovaně
rozpoznané skupiny, například pohonné hmoty, energie, drobný majetek, opravy, pojištění nebo služby. Pravidlo
nákladů míří na existující nebo současně navrženou analytiku, nikoli přímo na syntetický účet. Účet se při
analýze nezaloží. Vznikne až po výslovném schválení společně se závislým pravidlem nákladů a případnou
firemní předkontací. Existující analytiky ani vlastní předkontace asistent nepřepisuje bez schválení.

V editoru analytiky lze místo založení nového účtu zvolit kterýkoli aktivní existující analytický účet.
Asistent pak atomicky přesměruje všechna dosud neschválená závislá pravidla nákladů a předkontace. Pouhé
odškrtnutí návrhu analytiky naopak odškrtne také návrhy, které by bez ní neměly platný cílový účet.

**Opakované spouštění.** Analýzu lze bezpečně spouštět opakovaně. Každý běh zůstane uložený jako samostatná
auditní stopa, ale obrazovka pracuje vždy s výsledkem posledního běhu. Aktivní ekvivalentní pravidlo nákladů,
předkontace nebo bankovní pravidlo se znovu nenavrhne. Kdyby se stav změnil mezi analýzou a schválením,
druhá kontrola při schválení duplicitní pravidlo stejně nevytvoří.

**Slovník.** Interní katalog rozpoznává obecné výrazy v češtině, slovenštině, němčině a angličtině. Slova pro
pohonné hmoty, materiál, služby, opravy, pojištění a majetek mají také záporné pojistky, aby například
doprava nebo pronájem nespadly mezi pořízení majetku. Česká a slovenská diakritika se při porovnání
normalizuje, například Š a š na s. Katalog neobsahuje data konkrétních firem. Jednoznačné výrazy pro drobný
majetek se sčítají napříč dodavateli, takže například dva notebooky od různých prodejců vytvoří jeden obecný
návrh pravidla místo dvou slabých, osamocených vzorů.

**AI doplnění.** Je-li pro firmu nakonfigurovaná a potvrzená AI brána, lze při spuštění výslovně zapnout
doplnění nerozpoznaných položek. Po lokálním průchodu se odešle uživatelem zvolených nejvýše 50, 100 nebo 200
opakujících se typických textů. Výchozí a nejlevnější rozsah je 50. Vyšší rozsahy se rozdělí na samostatné
dávky po nejvýše 50 vzorcích, aby odpověď nepřekročila limit poskytovatele. Model se kvůli velikosti vstupu
automaticky nemění. Texty jsou zkrácené, bez diakritiky a zbavené názvů protistran, čísel dokladů,
identifikátorů, dat a částek. AI pouze určí povahu nákladu, společné klíčové slovo a obecný název analytiky.
Volbu účtu a roční hranici majetku vždy provede a znovu ověří lokální účetní vrstva. Chyba nebo nedostupnost
AI nezastaví základní analýzu; úspěšné předchozí dávky se při částečném výsledku zachovají.

**Banka.** Asistent hledá opakované pohyby a učí se také z jejich konzistentní historické kontace. Již
existující ekvivalentní bankovní pravidla odfiltruje. Schválený návrh založí bankovní pravidlo v režimu
navrhovat pro frontu **Bankovní účty → K zaúčtování**. Saldokontní účty se tímto způsobem nenavrhují
a bankovní historie se hromadně nepřepisuje.

**Majetek.** Nepoužívá se pevná částka. Asistent načte daňový limit platný pro rok pořízení z ročních
daňových konstant. Cena se posuzuje za kus bez DPH a po přepočtu do korun. Pouze hmotná věc s částkou vyšší
než dobový limit vytvoří kandidáta na účet 042 a založení karty odpisovaného majetku; služba kandidátem není.
Kandidáti jsou uvedeni jmenovitě a každý se potvrzuje samostatně. Samotný návrh kartu dlouhodobého majetku
nezaloží.

**Analýza** je pouze pro čtení. Po dokončení ukazuje procentní odhad položek pokrytých klasifikací. Přesný
podíl dokladů, jejichž kontace se skutečně změní, ukáže až dry-run. Balíček pro následný dry-run zmrazí také
všechna již aktivní pravidla nákladů firmy, takže opakovaná analýza nezapomene pravidla vytvořená předchozím
během.

### 65.10.8 Přeúčtování historie

Rozsah se řídí datem zdanitelného plnění, a pokud chybí, datem vystavení dokladu; prázdná mez znamená celou
dostupnou historii. Bezpečný výchozí režim zahrne pouze doklady, jejichž položka odpovídá konkrétnímu
pravidlu. Volba **Přeúčtovat všechny doklady v období** zahrne také faktury bez konkrétní shody a použije
na ně výchozí předkontaci, například zbytkovou analytiku 518.100. Je vhodná pro úplný převod ze syntetik na
analytiky.

Ostrý běh čistě nahradí jen nákladové a majetkové řádky 5xx/04x existujícího účetního zápisu. Závazek 321,
DPH 343 a ostatní nohy převezme beze změny. Nevytváří storno ani další kopii zápisu. Pokud se položka změní
na drobný hmotný nebo nehmotný majetek, ve stejné transakci se synchronizuje také karta v
`Nákup → Drobný majetek`. Dlouhodobý majetek zůstává kandidátem k ručnímu založení karty a odpisového plánu.
Tento job mění pouze přijaté faktury; již zaúčtovanou bankovní historii nemění.

Doklad převzatý z jiného účetního programu (například převodem z POHODY nebo jiného systému v části
`Systém → Přechod z jiných účetních systémů`) se přeskočí vždy, i při volbě **Přeúčtovat všechny doklady
v období**: jeho kontace se nepřepisuje a v dry-runu je uveden jako přeskočený. Přeskočí se také doklad s
autoritativním rozdělením DPH a doklad bez aktivního účetního zápisu.

Uzavřené, uzavírané a měkce uzamčené období se vždy přeskočí. Stav období se ověří při dry-runu a znovu
atomicky bezprostředně před přepisem. Pokud se zápis nebo vypočtený výsledek od dry-runu změnil, asistent jej
také přeskočí a vyžádá novou kontrolu. Po ostrém běhu lze původní účtování obnovit ze snapshotu; obnova opět
smí změnit jen otevřené a nezamčené období a odmítne doklad mezitím ručně změněný. Jakmile už záloha není
potřeba, lze snapshot samostatně a nevratně smazat. Smazání snapshotu účetní deník ani klasifikaci položek
nemění, pouze odstraní možnost obnovy daného běhu.

### 65.10.9 Pravidla výnosů

Pravidlo výnosů určí účet položky vydané faktury. Hodí se hlavně u importovaných faktur,
jejichž položky nejsou navázané na skladové karty, takže by celý výnos šel na předkontaci
dokladu (602).

1. Otevřete `Nástroje → Šablony účtování` a záložku **Pravidla výnosů**.
2. Klikněte na **Nové pravidlo** a vyplňte **Název pravidla**.
3. V části **Kritéria** vyplňte aspoň jedno z polí **Kód položky**, **Text položky obsahuje**
   nebo **Sazba DPH (%)**. Vyplněná kritéria musí sedět všechna.
4. Zvolte **Účet položky**: výnosový účet (601, 602, 604…), u přefakturace i rozvahový.
   Saldokontní, daňové a peněžní účty povolené nejsou (stejně jako u účtu položky,
   viz [§ 114.11.9](114_Dimenze.md#114119-produkt-a-kategorie)).
5. Nastavte **Prioritu** (0 až 999, nižší jde první) a uložte.

**Kód položky** je kód prodávajícího z ISDOC (`SellersItemIdentification`). Import vydaných
faktur ho ukládá k položce a pravidlo s ním porovnává přesnou shodu bez ohledu na velikost
písmen. **Text položky obsahuje** se porovnává bez ohledu na velikost písmen a diakritiku.

Kdy se pravidlo uplatní:

- **Import vydaných faktur** zapíše účet z pravidla na položku. Účetní ho vidí v editoru
  faktury v poli **Účet položky** a může ho přepsat. Ve sloupci **Použití** se pravidlu
  přičte každá takto označená položka.
- **Zaúčtování** faktury i `Účetnictví → Doúčtovat doklady` doplní účet z pravidla
  položkám, které nemají vlastní účet ani účet produktu nebo kategorie. Pořadí je tedy
  prodej majetku > účet položky > produkt > kategorie > pravidlo výnosů > předkontace
  dokladu. Slevový a textový řádek pravidlo nebere.

Změna pravidla už uložený účet položky ani zaúčtované zápisy nemění. Pravidla výnosů se
nastavují ručně, z historie se neučí. Evidenci DPH a kontrolní hlášení účet položky
neovlivňuje.

## 65.11 Související kapitoly

- [Účtový rozvrh](66_Ucetni_osnova.md)
- [Účetní nástroje a předkontace](73_Ucetni_nastroje.md)
- [Bankovní účty](30_Bankovni_ucty.md)
- [Drobný majetek](27_Drobny_majetek.md)
- [Aktivace účetnictví](68_Aktivace_ucetnictvi.md)
