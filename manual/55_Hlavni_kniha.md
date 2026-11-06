# 55. Hlavní kniha

> Přehled zaúčtovaných pohybů seskupený podle účtů: počáteční stavy, obraty a konečné zůstatky. Kapitola je pro účetní, která potřebuje zjistit, z čeho se skládá zůstatek účtu, nebo ověřit zaúčtování před výkazy.

## 55.1 Kdy to potřebujete

Kapitolu otevřete, když:

- chcete zjistit zůstatek nebo obrat konkrétního účtu za období,
- potřebujete dohledat, z jakých dokladů se zůstatek účtu skládá,
- ověřujete neobvyklý zůstatek před sestavením rozvahy nebo výsledovky,
- čistíte průběžný účet (261 Peníze na cestě, 395 Vnitřní zúčtování, zálohy, půjčky) a párujete vyrovnávající se řádky,
- potřebujete podklad pro auditora nebo klienta v PDF či XLSX.

Hlavní kniha zahrnuje rozvahové, výsledkové, uzávěrkové i podrozvahové účty včetně účtové třídy 7. Je dostupná jen firmám s podvojným účetnictvím a navazuje na [Účtový rozvrh](66_Ucetni_osnova.md).

## 55.2 Než začnete

1. **Zaúčtované doklady.** Sestava čte jen zaúčtované řádky deníku. Koncepty se nezapočítají. Počet konceptů v rozsahu uvidíte ve varování nad tabulkou. Nezaúčtované doklady dořešte v [K doúčtování](54_Rucni_fronta_doctovani.md).
2. **Účetní období.** Nejnovější otevřené období se předvyplní samo. Starší období zvolíte v poli **Období**.
3. **Oprávnění k účetnictví.** Párování otevřených položek vyžaduje oprávnění k zápisu.

## 55.3 Krok za krokem: zobrazení zůstatků účtů

1. Otevřete `Účetnictví → Hlavní kniha`.
2. V poli **Období** zvolte fiskální rok. Hodnota **Vše** spojí všechna období firmy.
3. Chcete-li zúžit rozsah, vyplňte **Od** a **Do**.
4. Chcete-li vidět jednotlivé analytické účty, zapněte **Rozpad po analytikách**.
5. Hledáte-li konkrétní doklady, použijte filtry **Dodavatel**, **Odběratel** nebo **Položka faktury**. Chcete-li vidět jen část účtů, vyplňte **Účet od** a **Účet do**. Filtry zrušíte tlačítkem **Zrušit filtry**.
6. Prohlédněte sloupce **PS MD / PS Dal**, **Obrat MD / Obrat Dal** a **KS MD / KS Dal**. Seřadíte je kliknutím na záhlaví.
7. Chcete-li kombinaci uložit, uložte aktuální filtr jako pohled (nabídka **Uložené pohledy**) a případně ho nastavte jako výchozí.

**Jak poznáte, že je hotovo:** Řádek **Součty** má shodné obraty na straně MD a Dal a nad tabulkou není žluté upozornění na nezaúčtované koncepty.

> [!TIP]
> Do uložené kombinace se nepřenáší volba **Stav po uzavření knih**. Ovládání **Sloupce** a **Hustota** mění jen zobrazení.

## 55.4 Krok za krokem: dohledání, z čeho se skládá zůstatek

1. U účtu klikněte na šipku na začátku řádku. Rozbalí se obrat MD a Dal po měsících.
2. Klikněte na měsíc s pohybem. Zobrazí se jednotlivé řádky deníku: datum, doklad, analytika, popis a částka.
3. Klikněte na doklad. Otevře se prvotní doklad.
4. Přesahuje-li měsíc 200 řádků, klikněte na odkaz v patičce a pokračujte v opisu účtu za stejný měsíc.
5. Chcete-li vidět celý účet, klikněte na **kód účtu**. Otevře se karta účtu se zachovaným rozsahem **Od / Do**. Odtud otevřete **Opis účtu**.
6. V opisu čtěte sloupec **Zůstatek**, což je běžící saldo. Kladný zůstatek znamená MD, záporný Dal.

**Jak poznáte, že je hotovo:** Součet řádků opisu odpovídá zůstatku účtu v knize.

## 55.5 Krok za krokem: párování otevřených položek

Použijte na účtech, kde se řádky vyrovnávají dodatečně, například 261 Peníze na cestě, 395 Vnitřní zúčtování, zálohy nebo půjčky.

1. Otevřete opis účtu a přepněte na **Otevřené položky - párování**.
2. Chcete-li vidět stav k určitému dni, vyplňte **K datu**. Volba **Jen otevřené** skryje vyrovnané řádky.
3. Zaškrtněte řádky, které se navzájem vyrovnávají. Lišta nad tabulkou průběžně ukazuje rozdíl MD a Dal vybraných řádků.
4. Klikněte na **Spárovat**.
5. Automatické návrhy získáte tlačítkem **Návrhy párování**. Nastavte **Okno (dní)** a klikněte na **Spárovat návrhy**. Návrhy najdou storno s původním zápisem a dvojice stejné částky na opačných stranách téhož účtu.
6. Chybné párování opravíte kliknutím na číslo okruhu. Dole se otevřou jeho položky. Řádek odeberete tlačítkem **Odebrat z okruhu**, další zaškrtnuté řádky přidáte tlačítkem **Přidat do okruhu**, celý okruh zrušíte tlačítkem **Zrušit párování**.

**Jak poznáte, že je hotovo:** Karta **Zůstatek účtu** nahoře hlásí **Otevřené položky sedí na zůstatek účtu.** a vyrovnané okruhy z pohledu **Jen otevřené** zmizely.

## 55.6 Krok za krokem: export

1. Nastavte období, rozsah, rozpad analytik, účty a filtry tak, jak je chcete mít na výstupu.
2. Klikněte na **Exportovat…**. V dialogu vyberte obsah a formát PDF/XLSX.
3. Zkontrolujte převzaté období a filtry a klikněte na **Exportovat**.

**Jak poznáte, že je hotovo:** Soubor se stáhne a v názvu obsahuje fiskální rok.

### 55.6.1 Export více účtů včetně dokladů

V dialogu **Export hlavní knihy** vyberte **Souhrn účtů** nebo **Rozpis včetně
dokladů** a formát. Souhrn jde do PDF i XLSX,
rozpis včetně dokladů zatím jen do PDF. Exportuje se kniha,
jak je zobrazená: období, **Rozpad po analytikách**, rozsah účtů a filtry se převezmou z naposledy
načtené knihy a dialog je vypíše. Dokud se kniha načítá, export nejde spustit.
Jiné období nebo filtry nastavte na obrazovce. Během přípravy souboru tlačítko ukazuje
**Připravuji export…**; velký rozpis může trvat až minutu. Podrobná sestava obsahuje všechny zaúčtované
řádky ve zvoleném období, po účtech a chronologicky: datum, číslo dokladu, popis,
protiúčet, MD, D a průběžný zůstatek. Rozpis navíc obsahuje účty, které obrazovka
nezobrazí, protože mají nulový počáteční stav i obrat, ale nesou pohyby (typicky zápis
a jeho červené storno). U každého účtu uvádí počáteční stav, obraty,
saldo obratů (MD − D) a konečný zůstatek. Kladný zůstatek znamená MD, záporný D.
PDF rozpisu je na výšku a účty v něm jdou plynule za sebou; pokračuje-li účet na
další straně, nese hlavička strany jeho název a záhlaví sloupců. Hlavička sestavy uvádí název firmy a IČ.
Jde o rozpis účetních zápisů, nikoli o přiložené skeny nebo PDF prvotních dokladů.

Které účty export obsahuje, určuje filtr **Účet od** a **Účet do** na obrazovce
(viz [§ 55.8.2](#5582-obdobi-rozsah-a-filtry)). Ostatní filtry hlavní knihy platí
i pro export, včetně dimenzí a pohledu před/po uzavření knih. Koncepty se neexportují.
Volbou **Souhrn účtů** se stejným rozsahem získáte souhrnnou hlavní knihu.

Rozpis včetně dokladů má nejvýše 10 000 řádků. Do limitu se kromě řádků dokladů
počítá za každý účet 6 řádků na jeho záhlaví, počáteční stav a součty. Větší rozpis
rozdělte podle období nebo rozsahu účtů.

## 55.7 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Žluté upozornění **V rozsahu je N nezaúčtovaných konceptů - nejsou zahrnuty.** | Koncepty do částek nevstupují | Koncepty zaúčtujte nebo smažte. Po zaúčtování se kniha změní. |
| **Žádné účty s pohybem nebo počátečním stavem ve zvoleném rozsahu.** | V rozsahu není žádný zaúčtovaný pohyb | Zkontrolujte **Období**, **Od** a **Do**. |
| Server odmítl datum | Datum leží mimo období, nebo je **Od** později než **Do** | Opravte rozsah. |
| Zůstatek k poslednímu dni uzavřeného roku je nulový | Zapnuté **Stav po uzavření knih** zahrnuje závěrkový převod | Vypněte volbu. Výchozí pohled závěrkový převod vynechává. |
| **Rozpis má N řádků v M účtech, pro PDF je to příliš mnoho …** | Rozpis by byl pro PDF příliš velký | Zužte období nebo rozsah účtů a exportujte po částech. |
| Chybějící účet, který čekáte | Účet bez PS a bez obratu se nezobrazuje | Zkontrolujte rozsah, nebo účet v [Účtovém rozvrhu](66_Ucetni_osnova.md). |
| Párování nejde založit | Řádky jsou z různých účtů (u syntetiky z různých analytik), nebo už jsou v jiném okruhu | Okruh drží řádky jednoho účtu a každý řádek smí být jen v jednom okruhu. |
| Rozdíl otevřených položek proti zůstatku účtu | Karta **Zůstatek účtu** zvýrazní rozdíl | Zkontrolujte okruhy a **K datu**. Součet otevřených položek se má rovnat zůstatku účtu. |

## 55.8 Podrobnosti a pravidla

### 55.8.1 Co je zdrojem sestavy

Sestava čte řádky účetního deníku, které mají vyplněný okamžik zaúčtování.
Koncepty do částek nevstupují; jejich počet v nastaveném rozsahu se zobrazí
ve varování nad tabulkou. Storna se neodstraňují přepsáním historie, ale
vyruší původní částku opačným zaúčtovaným zápisem.

Účetní zápisy mohou vzniknout z vydaných a přijatých dokladů, banky, pokladny,
majetku, mezd, uzávěrky nebo ručního zápisu. Hlavní kniha mezi těmito zdroji
částek nerozlišuje: rozhodující je účet, strana MD/Dal, částka a datum řádku.

### 55.8.2 Období, rozsah a filtry

- **Období** určuje fiskální rok. Předvyplní se nejnovější otevřené období,
  jinak první dostupné. Při změně období se **Od / Do** nastaví na jeho hranice;
  ostatní filtry i rozbalený účet zůstanou zachované. Volba **Vše** spojí všechna
  účetní období firmy do jednoho víceletého rozsahu. Ve výchozím pohledu přitom
  vynechá technické uzavírací a navazující otevírací zápisy, aby nepřifukovaly
  obraty mezi roky.
- **Od / Do** omezí sestavu uvnitř zvoleného období. Prázdné hodnoty znamenají
  první a poslední den období. Datum mimo období nebo `Od > Do` server odmítne.
- **Rozpad po analytikách** vypíše jednotlivé analytické účty. Bez něj se
  analytiky sečtou pod syntetický účet.
- **Stav po uzavření knih** započte i vlastní závěrkový převod období. Výchozí
  pohled jej vynechává, aby zůstatky k poslednímu dni uzavřeného roku nebyly
  závěrkovým zápisem vynulované. Otevírací zápis a skladové uzávěrkové pohyby
  zůstávají součástí účetních hodnot.
- **Dodavatel**, **Odběratel** a **Položka faktury** filtrují zápisy podle
  protistrany nebo položky zdrojové přijaté či vydané faktury. Filtr se
  uplatní na počáteční stav, obraty i měsíční rozpad vybraného výřezu.
- **Účet od** a **Účet do** omezí knihu na rozsah účtů včetně krajů. Pole
  našeptávají z účtového rozvrhu a pod polem ukážou název účtu, u začátku kódu
  počet účtů, které na něj začínají (včetně neaktivních). Zadat jde
  i začátek kódu: **5 až 5** vybere všechny nákladové účty, **501 až 518** rozsah
  včetně analytik a **501.100 až 501.100** jednu analytiku. Rozsah se použije
  po potvrzení pole Enterem nebo po jeho opuštění a řádek **Součty** sečte jen
  vybrané účty. Stejně funguje filtr **Účet od / Účet do** v [účetním deníku](52_Ucetni_denik.md). Pro výběr jednotlivých analytik zapněte **Rozpad po analytikách**;
  bez něj se rozsah uplatňuje na syntetické účty a mez s analytikou vybere celou
  syntetiku. Stránka na to upozorní.

Aktuální kombinaci období, rozsahu, rozpadu analytik, rozsahu účtů a vyhledávacích filtrů
lze uložit jako vlastní sestavu a případně ji nastavit jako výchozí. Volba
**Stav po uzavření knih** se do uložené kombinace nepřenáší. Ovládání **Sloupce**
skrývá či zobrazuje sloupce tabulky a **Hustota** mění řádkování; obě volby
mění jen zobrazení.

Kliknutím na záhlaví sloupce lze řadit účty podle kódu, názvu i částek.
Při rozpadu po analytikách zůstávají analytické účty pod svou syntetikou
a řadí se uvnitř její skupiny.

### 55.8.3 Výpočet PS, obratů a KS

Každý řádek účtu obsahuje:

| Hodnota | Výpočet |
|---|---|
| **PS MD / PS Dal** | Netto zůstatek před datem `Od`, zobrazený jen na straně výsledného salda |
| **Obrat MD / Obrat Dal** | Hrubé součty stran za dny `Od` až `Do` včetně |
| **KS MD / KS Dal** | `PS MD - PS Dal + obrat MD - obrat Dal`, opět jen na straně výsledného salda |

Otevírací zápis datovaný prvním dnem období patří do PS, nikoli do obratu.
U rozvahových účtů se počáteční stav nese z historie; existuje-li otevírací
kotva po uzávěrce, počítá se od ní, aby se historie a otevření nezapočetly
dvakrát. U nákladových a výnosových účtů začíná okno PS nejdříve prvním dnem
daného fiskálního období.

Peníze se pro součty převádějí na haléře. Účet bez PS i bez obratu se
nezobrazuje. Řádek **Součty** sčítá všechny zobrazené účty.

### 55.8.4 Měsíční rozpad a řádky měsíce

Šipka na začátku řádku rozbalí obrat MD a Dal po kalendářních měsících
zasahujících do nastaveného rozsahu. Jde o rozpad obratu, nikoli zůstatku:
měsíc bez pohybu proto ukazuje nuly. Měsíční částky se seskupují stejně jako
hlavní řádek - podle syntetiky, nebo po analytikách. Součet měsíců se rovná
obratu za celý rozsah; otevírací zápis prvního dne období je i tady součástí
počátečního stavu, ne lednového obratu.

Kliknutí na měsíc s pohybem rozbalí **jednotlivé řádky deníku** za ten měsíc:
datum, doklad, analytiku, popis a částku na správné straně. Doklad vede na
prvotní doklad (viz [§ 55.8.5](#5585-opis-uctu)). Zobrazí se prvních 200 řádků;
u delších měsíců odkaz v patičce pokračuje v opisu účtu za stejný měsíc.

Kliknutí na **kód účtu** otevře [kartu účtu](66_Ucetni_osnova.md#666-krok-za-krokem-karta-uctu)
se zachovaným rozsahem `Od / Do`. Z karty vede odkaz zpět do hlavní knihy;
kniha pak dotčený účet sama rozbalí a odroluje k němu.

### 55.8.5 Opis účtu

Opis účtu otevřete z karty účtu nebo z rozbaleného měsíce; rozsah `Od / Do`
se přenáší. Opis syntetického účtu zahrnuje i jeho analytiky a nahoře ukazuje
PS, obrat MD, obrat Dal a KS. Je-li opis složený z víc analytik, přibude
sloupec **Analytika** s odkazem na kartu příslušného účtu.

Pohyby jsou řazené podle data, ID zápisu a čísla řádku. Sloupec **Zůstatek**
je běžící saldo `PS + MD − Dal` počítané nad celým rozsahem, takže navazuje
správně i na dalších stránkách. Výchozí stránka má 50 řádků.

Zůstatek v opisu je se znaménkem podle typu účtu: aktiva a náklady `MD − Dal`,
pasiva, vlastní kapitál a výnosy `Dal − MD`. Běžný zůstatek je tak kladný. Stranu
zůstatku ukazuje popisek karty. Neobvyklá strana podle účtové osnovy je červeně,
například pohledávka v kreditu. Oprávky mají typ aktiva, proto jsou záporné, ale
neobvyklé nejsou. Účty bez určené strany (261, 343, 395, 431) neobvyklou stranu
nemají.

**Filtr a součty podle protiúčtu.** Blok **Součty podle protiúčtu** rozděluje obrat
MD a Dal na jednotlivé protiúčty a jeho součet se rovná obratu účtu. Řádky se shodnou
částkou tvoří dvojici, když jich je na obou stranách zápisu stejně: pokladní doklad
501 MD 300 / 211 D 300 a 513 MD 700 / 211 D 700 má u řádku 300 protiúčet 501
a u řádku 700 protiúčet 513. Ostatní řádky se dělí poměrem jen mezi ostatní řádky
protistrany, takže 311 MD 1 210 má protiúčty 602 za 1 000 a 343 za 210 i tehdy, když je
ve stejném zápisu ještě dvojice. Je-li na opačné straně zápisu i sám účet (převod v rámci
účtu), je mezi protiúčty i on. Řádek, jehož protistrana je celá spárovaná s jinými řádky,
protiúčet nemá a v součtech je bez protiúčtu. Výběrem v poli **Protiúčet** nebo kliknutím na řádek
bloku opis vyfiltrujete.
Řádek složeného zápisu se ukáže celý, sloupec **Zůstatek** dál ukazuje zůstatek
celého účtu a filtr platí i pro export. Výběr syntetiky zahrne její analytiky.

Záložka **Inventura účtu** ukazuje účetní a skutečný stav účtu a jeho analytik
z [inventarizačního protokolu](69_Inventarizace_rozvahovych_uctu.md) k rozvahovému
dni. Vedou na ni odkazy z rozvahy a výsledovky po účtech.

Doklad v opisu vede podle zdroje na vydanou fakturu, přijatou fakturu, bankovní
výpis, pokladní doklad, kartu majetku nebo na vyrovnaný doklad zápočtu; bez
rozpoznaného zdroje na konkrétní zápis účetního deníku. PDF/XLSX opisu obsahuje
celý zvolený rozsah, nejen aktuální stránku.

Každý řádek dál ukazuje:

- **Partner** a **VS** ze zdrojového dokladu (faktura, bankovní pohyb, pokladní
  doklad, u zápočtu hrazený doklad),
- **Protiúčet**, tedy účty opačné strany téhož zápisu (u řádku z dvojice se shodnou
  částkou jen jeho dvojice),
- **Okruh**, pokud je řádek spárovaný (viz níže),
- **Měnu**; u cizoměnového řádku i částku v cizí měně.

XLSX opisu nese partnera, VS, protiúčet a okruh také.

#### 55.8.5.1 Otevřené položky a párování

Záložka **Otevřené položky - párování** ukazuje, z čeho se skládá zůstatek
účtu k datu. Funguje na kterémkoli účtu, typicky na 261 Peníze na cestě
(převody mezi účty), 395 Vnitřní zúčtování, zálohách nebo půjčkách.

Řádky, které se navzájem vyrovnávají, spojíte do **okruhu**: zaškrtněte je
a klikněte na **Spárovat**. Lišta nad tabulkou průběžně ukazuje rozdíl MD a Dal
vybraných řádků. Vyrovnaný okruh je uzavřený a jeho řádky z pohledu
**Jen otevřené** zmizí. Nevyrovnaný okruh je povolený: rozdíl zůstane otevřený
na straně, která převažuje, a to na nejnovějším řádku.

Sloupce **Otevřeno MD**, **Otevřeno Dal** a **Otevřený zůstatek** počítají jen
otevřené části řádků. Součet otevřených položek se vždy rovná zůstatku účtu;
karta **Zůstatek účtu** nahoře to kontroluje a případný rozdíl zvýrazní.

- Okruh drží řádky jednoho účtu, u syntetiky vždy jedné analytiky. Řádek
  smí být jen v jednom okruhu.
- Počáteční stav z otevření knih je v seznamu jako běžný řádek, takže se
  dá spárovat s pozdějším vyrovnáním.
- Datum **K datu** počítá jen řádky do toho dne. Okruh, jehož protějšek přišel
  později, je k tomu dni otevřený.
- Klik na číslo okruhu otevře dole jeho položky. Odtud jde řádek z okruhu
  odebrat, přidat do okruhu další zaškrtnuté řádky nebo celý okruh zrušit.
  Odebráním předposledního řádku okruh zanikne.
- **Návrhy párování** najdou storno s původním zápisem a dvojice stejné
  částky na opačných stranách téhož účtu v zadaném okně dní. **Spárovat
  návrhy** z nich založí okruhy jedním klikem.

Párování nemění účetní deník, obraty ani zůstatky. Proto jde měnit i v
uzavřeném nebo zamčeném období. Když se doklad přeúčtuje a jeho řádek zůstane
na stejném účtu, okruh zůstane beze změny. Když řádek přejde na jiný účet nebo
se zápis stornuje nebo smaže, řádek z okruhu vypadne a zbytek okruhu je znovu
otevřený. Okruh, ve kterém zůstane jediný řádek, zanikne a řádek jde spárovat
znovu. Tyto změny se zapisují do historie aktivit.

### 55.8.6 Export a návaznosti

Dialog **Export hlavní knihy** používá stejné období, rozsah dat, rozpad
analytik, rozsah účtů, pohled před/po uzavření a filtry jako obrazovka. Název souboru
obsahuje fiskální rok. Stejná logika PS/obrat/KS se používá také v
[Obratové předvaze](56_Obratova_predvaha.md); výkazové mapování a zákonné
řádky popisuje [Rozvaha](57_Rozvaha.md).

> [!WARNING]
> **Koncepty nejsou součástí částek.** Žluté upozornění znamená, že se po
> jejich zaúčtování hlavní kniha ještě změní.

## 55.9 Související kapitoly

- [Obratová předvaha](56_Obratova_predvaha.md) - stejná logika PS, obratů a KS pro všechny účty s kontrolami úplnosti
- [Rozvaha](57_Rozvaha.md) a [Výsledovka](58_Vysledovka_druhova.md) - výkazy z týchž zápisů
- [Účtový rozvrh](66_Ucetni_osnova.md) - karta účtu
- [Účetní deník](52_Ucetni_denik.md) - zdrojové zápisy
