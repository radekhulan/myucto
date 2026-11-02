# 58. Výkaz zisku a ztráty - druhové členění

> Sestavení druhové výsledovky podle účetního rámce platného v daném období: vyhlášky č. 500/2002 Sb. pro podnikatele nebo č. 504/2002 Sb. pro účetní jednotky, jejichž hlavním předmětem činnosti není podnikání. Kapitola je pro účetní v podvojném účetnictví.

## 58.1 Kdy to potřebujete

Pro období vedené podle vyhlášky 504/2002 Sb. má výkaz sloupce **Hlavní činnost**, **Hospodářská činnost** a **Celkem**. Členění vychází z činnosti na zaúčtovaných řádcích. Chybějící klasifikaci je nutné doplnit; aplikace ji sama nepovažuje za hlavní činnost. Stejnou strukturu zachovávají PDF a XLSX. Malá a mikro jednotka bez povinného auditu používají stejnou zkrácenou sadu položek. Účelová výsledovka pro tento rámec není dostupná.

Kapitolu otevřete, když:

- potřebujete výsledovku za období nebo k určitému dni,
- chcete během roku sledovat průběžný výsledek hospodaření,
- potřebujete seznam nákladových a výnosových účtů se zůstatky a skupinami (provozní, finanční, daň),
- se blíží konec roku a chcete odhad výsledku a daně z příjmů právnických osob,
- musíte rozhodnout, které další výnosy se počítají do čistého obratu,
- se výsledek v rozvaze liší od výsledku ve výsledovce.

Cesta: `Účetnictví → Výkaz zisku a ztráty`. Výkaz je dostupný jen pro podvojné účetnictví.

## 58.2 Než začnete

1. **Zaúčtované doklady.** Výkaz čte jen zaúčtované řádky deníku. Před sestavením projděte [Obratovou předvahu](56_Obratova_predvaha.md).
2. **Mapování účtů.** Každý nákladový a výnosový účet s obratem musí být zařazen do řádku výkazu. Nezařazený účet řeší výjimka mapování ([Rozvaha](57_Rozvaha.md#574-krok-za-krokem-zarazeni-uctu-do-jineho-radku)).
3. **Výběr řádků čistého obratu.** Řádky, které se počítají do čistého obratu, se volí v `Firma → Nastavení`, záložka **Daně a účetnictví**, blok **Čistý obrat: výnosy obchodního modelu** (viz [§ 58.5](#585-krok-za-krokem-vyber-radku-pro-cisty-obrat)).

## 58.3 Krok za krokem: sestavení výsledovky

1. Otevřete `Účetnictví → Výkaz zisku a ztráty`.
2. V poli **Období** zvolte fiskální rok.
3. V poli **Sestaveno k** zvolte datum. Prázdná hodnota znamená dřívější z posledního dne období a dneška.
4. V poli **Rozsah** ponechte **Automaticky (dle kategorie ÚJ)**, nebo zvolte plný či zkrácený rozsah.
5. Podle potřeby přepněte **Kč / tis. Kč**. PDF a XLSX zůstávají v korunách.
6. Posíláte-li průběžnou výsledovku klientovi, zaškrtněte **Průběžné časové rozlišení**. Náklady a výnosy dokladů s obdobím od–do se pak započtou jen poměrnou částí do data **Sestaveno k** (viz [§ 58.7.7](#5877-prubezne-casove-rozliseni)).
7. Pod výkazem zkontrolujte **Výsledek hospodaření za účetní období** a **Čistý obrat za účetní období**.
8. Řádek rozbalte kliknutím. Uvidíte přímo namapované účty a jejich příspěvky. Kód účtu otevře opis od začátku období do data sestavení.
9. Klikněte na **Export PDF** nebo **Export XLSX**.

**Jak poznáte, že je hotovo:** Výkaz má vyplněné řádky, výsledek hospodaření odpovídá výsledku z výsledkových účtů a nad tabulkou není upozornění na nezařazený účet.

## 58.4 Krok za krokem: výsledovka po účtech

1. Otevřete `Účetnictví → Výkaz zisku a ztráty` a zvolte záložku **Účet 710 (po účtech)**.
2. Projděte skupiny **Provozní činnost**, **Finanční činnost**, **Daň z příjmů** a **Převod podílu na výsledku hospodaření společníkům**. Náklady jsou vlevo, výnosy vpravo na jednom řádku.
3. Zkontrolujte mezisoučty **Provozní výsledek hospodaření**, **Finanční výsledek hospodaření**, **Výsledek hospodaření před zdaněním** a **po zdanění**.
4. Klikněte na účet. Otevře se inventura účtu, odtud vede jeho opis.
5. Je-li ve skupině **Účty nezařazené ve výkazu** nějaký účet, zařaďte ho výjimkou mapování ([Rozvaha](57_Rozvaha.md#574-krok-za-krokem-zarazeni-uctu-do-jineho-radku)).
6. Klikněte na **Export PDF** nebo **Export XLSX**.

**Jak poznáte, že je hotovo:** Zvýrazněný řádek **Výsledek hospodaření** se shoduje s výkazem a s výsledkem v rozvaze po účtech.

## 58.5 Krok za krokem: výběr řádků pro čistý obrat

Čistý obrat jsou od roku 2024 výnosy z prodeje výrobků, zboží a služeb (§ 1a odst. 2 zákona o účetnictví, § 35 vyhlášky č. 500/2002 Sb.). Které další výnosy patří k obchodnímu modelu firmy, je vaše rozhodnutí.

1. Otevřete `Firma → Nastavení` a zvolte záložku **Daně a účetnictví**.
2. Sjeďte do sekce **Účetní závěrka**, blok **Čistý obrat: výnosy obchodního modelu**. Mapování výkazů na tento blok odkazuje také odkazem **Čistý obrat: nastavení výnosů obchodního modelu**.
3. U druhové výsledovky (**Výsledovka v druhovém členění**) zkontrolujte návrh **Podle zapsané činnosti CZ-NACE** a případně klikněte na **Předvyplnit návrh (N)**. Aplikace zaškrtne řádky, které k takové činnosti typicky patří, i s odůvodněním.
4. Řádky, na kterých firma za období nemá žádný obrat, jsou skryté. Přepínač **Zobrazit i řádky bez obratu** je vrátí.
5. Zkontrolujte výběr, případně ho upravte, a nastavení uložte.
6. Rozhodnutí uveďte v příloze v účetní závěrce.

**Jak poznáte, že je hotovo:** Pod výkazem se zobrazuje **Čistý obrat za účetní období** podle vašeho výběru.

> [!TIP]
> Návrh je podklad, ne rozhodnutí: aplikace nic nezaškrtne sama a výběr ukládáte vy. Zvolený řádek se neskryje nikdy, ani když je na něm nula.

## 58.6 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| **Výkaz nemá pro zvolené parametry žádná data.** | Ve zvoleném období a dni nejsou zaúčtované řádky | Zkontrolujte **Období** a **Sestaveno k**. |
| Skupina **Účty nezařazené ve výkazu** a upozornění nad tabulkou | Výsledkový účet nezná mapa výkazu; výsledek hospodaření ho zahrnuje, výkaz ne | Zařaďte účet výjimkou mapování. |
| Výsledek v rozvaze po účtech se liší od výsledovky | Chyba v počátečních stavech nebo v uzávěrce minulého roku. Při filtru dimenze to může být jen upozornění. | Prověřte [Obratovou předvahu](56_Obratova_predvaha.md) a uzávěrku minulého roku. |
| Čistý obrat minulého období je nulový | Ve výkazu za první rok nového pojetí se čistý obrat minulého období spočtený po staru neuvádí | Nejde o chybu. |
| Blok **Odhad do konce roku (nezaúčtováno)** chybí | Zobrazuje se jen u právnické osoby v neuzavřeném roce bez zaúčtované daně z příjmů (účet 591), bez filtru dimenze a s právem na přiznání k dani z příjmů | Nejde o chybu. |
| Na malé nebo mikro jednotce vidíte jen nejvyšší úroveň | U druhové výsledovky se malý i mikro rozsah omezí na řádky nejvyšší úrovně | Zvolte **Plný rozsah**. |

## 58.7 Podrobnosti a pravidla

### 58.7.1 Zdroj a období

Výkaz čte syntetické zůstatky zaúčtovaných nákladových a výnosových účtů od
začátku zvoleného fiskálního období do data **Sestaveno k** včetně. Prázdné
datum znamená dřívější z posledního dne období a dneška. Vlastní závěrkový
převod účtů se vylučuje.

Sloupec **Minulé období** vznikne stejným výpočtem za předchozí fiskální
období k jeho konci a používá stejnou verzi mapy jako běžné období. Přepínač
**Kč / tis. Kč** mění jen obrazovku; PDF/XLSX zůstává v korunách.

### 58.7.2 Jak se účty mapují

Každý účet se přiřadí podle nejdelšího shodného prefixu ve verzované mapě.
Výnosový příspěvek se počítá jako `Dal - MD`, nákladový jako `MD - Dal`.
Proto běžné výnosy i náklady vstupují do svých řádků kladně; opravný pohyb na
opačné straně je snižuje. Analytické mapy, například 559M/559Z/559P a
561P/561C, mají přednost před obecnou syntetikou.

Základní skupiny mapování jsou:

| Řádky výkazu | Typické účty |
|---|---|
| I., II. tržby | 601, 602, 604 |
| A. výkonová spotřeba | 501–504, 511–513, 518 |
| B., C. změna zásob a aktivace | 581–588 |
| D. osobní náklady | 521–528 |
| E. úpravy hodnot | 551, 557–559 a jejich analytiky |
| III. ostatní provozní výnosy | 641–644, 646–648 |
| F. ostatní provozní náklady | 531, 532, 538, 541–549, 552, 554, 555 |
| finanční výnosy | 661–666, 668 |
| finanční náklady | 561–569, 574, 579 |
| daň a převod výsledku | 591, 592, 595, 596, 599 |

Úplný aktuální rozpad je dán verzí mapování zobrazenou v záhlaví. Kliknutí na
řádek zobrazí přímo namapované účty a jejich příspěvky; kód vede do opisu od
začátku období do data sestavení. Souhrnný blok pod výkazem zobrazuje jen výsledek hospodaření a čistý obrat;
nenamapované nenulové výsledkové účty ukazuje záložka **Účet 710 (po účtech)**.

### 58.7.3 Přesné vzorce výsledku

Mezisoučty A., D., E., III., F. a L. jsou součtem svých podřádků a případných
přímých příspěvků. Z nich se na haléře počítá:

```text
Provozní VH =
    I. + II.
  − A. − B. − C. − D. − E.
  + III.
  − F.

Finanční VH =
    IV. − G.
  + V.  − H.
  + VI. − I. (úpravy hodnot a rezervy ve finanční oblasti)
  − J.
  + VII.
  − K.

VH před zdaněním = Provozní VH + Finanční VH
VH po zdanění    = VH před zdaněním − L.
VH za období     = VH po zdanění − M.

Čistý obrat (období od 1. 1. 2024)       = I. + II. + řádky zvolené firmou
Čistý obrat (období započatá před 2024) = I. + II. + III. + IV. + V. + VI. + VII.
```

Čistý obrat jsou od roku 2024 výnosy z prodeje výrobků, zboží a služeb (§ 1a
odst. 2 zákona o účetnictví, § 35 vyhlášky č. 500/2002 Sb.). Které další výnosy
patří k obchodnímu modelu firmy, je její úsudek: pronajímatel k nim může
počítat tržby z prodeje majetku (III.1.), holding výnosy z podílů (IV.). Řádky se
volí v `Firma → Nastavení`, záložka **Daně a účetnictví**, blok **Čistý obrat: výnosy obchodního
modelu**, zvlášť pro druhovou a účelovou výsledovku, a rozhodnutí se uvádí
v příloze v účetní závěrce.

Výběr řádků má dvě pomůcky. Podle zapsané činnosti **CZ-NACE** aplikace navrhne
řádky, které k takové činnosti typicky patří, i s odůvodněním; tlačítkem se
návrh zaškrtne najednou. Řádky, na kterých firma za období nemá žádný obrat, se
skryjí - přepínačem **Zobrazit i řádky bez obratu** se vrátí. Zvolený řádek se
neskryje nikdy, ani když je na něm nula. Návrh je podklad, ne rozhodnutí:
aplikace nic nezaškrtne sama a výběr uložíte vy. Ve výkazu za první rok nového pojetí se čistý obrat
minulého období spočtený po staru neuvádí, sloupec zůstane nulový.

Obě položky označené `I.` mají interně jedinečné kódy, na výstupu se však
zobrazují podle vyhlášky. Výsledek za období se zároveň nezávisle spočítá ze
všech výsledkových účtů jako `Σ(Dal - MD)`. Oba výsledky se porovnávají na haléře.

### 58.7.4 Rozsah a kategorie

Filtr **Rozsah** nabízí automatický, plný, malý a mikro rozsah. U druhové
výsledovky se malý i mikro rozsah omezí na řádky nejvyšší úrovně. Automatická
volba používá kategorii účetní jednotky, ruční přepis rozsahu a povinný audit
stejně jako [Rozvaha](57_Rozvaha.md).

### 58.7.5 Kontroly a export

Pod výkazem se zobrazí **Výsledek hospodaření za účetní období** a **Čistý obrat
za účetní období**. Aplikace
navíc kontroluje shodu výsledku s výsledkovými účty a úplnost mapování; tyto
vazby používají také automatické testy a navazující procesy. PDF a XLSX
používají stejné období, datum, rozsah, mapu i minulé období jako obrazovka.

Účetní jednotka používající členění nákladů podle funkce sestaví samostatnou
[účelovou výsledovku](59_Vysledovka_ucelova.md); obě varianty mají shodný
celkový výsledek, ale jinou strukturu provozních nákladů.

### 58.7.6 Výsledovka po účtech

Záložka **Účet 710 (po účtech)** ukáže nákladové a výnosové účty se zůstatkem od začátku
období do data sestavení, rozdělené do skupin **Provozní činnost**,
**Finanční činnost**, **Daň z příjmů** a **Převod podílu na výsledku
hospodaření společníkům**. Skupinu určuje stejná mapa jako výkaz (včetně
výjimek firmy), takže účet je vždy ve skupině, do které ho výkaz započte.
Náklady jsou vlevo a výnosy vpravo na jednom řádku. Každá strana má sloupce
**Počáteční stav**, **Obrat** měsíce, **Obrat od** začátku období a **Konečný stav**,
se znaménkem podle typu účtu: náklady `MD − Dal`, výnosy `Dal − MD`. Výnos s převahou
MD a náklad s převahou Dal jsou proto záporné a červené. Každá skupina končí součtem
nákladů a výnosů. Přepínač **Skrýt nulové účty** funguje jako
u [rozvahy po účtech](57_Rozvaha.md#5778-rozvaha-po-uctech).

Pod skupinami následují mezisoučty **Provozní výsledek hospodaření**,
**Finanční výsledek hospodaření**, **Výsledek hospodaření před zdaněním** a
**po zdanění** a zvýrazněný řádek **Výsledek hospodaření** za účetní období.
Hodnoty se shodují s řádky výkazu a s výsledkem v rozvaze po účtech
([Rozvaha po účtech](57_Rozvaha.md#5778-rozvaha-po-uctech)). Uzávěrkový zápis se nezapočítává, po uzavření roku
tak pohled ukazuje obsah konečného účtu 710.

Výsledkový účet, který mapa výkazu nezná, je ve skupině **Účty nezařazené ve
výkazu**. Výsledek hospodaření ho zahrnuje, výkaz ne, proto stránka nad
tabulkou upozorní a účet je třeba zařadit výjimkou mapování
([Mapování účtů](57_Rozvaha.md#5777-mapovani-uctu-pro-konkretni-firmu)).

Kliknutím na účet se otevře záložka **Inventura účtu**. Výsledkové účty se
neinventarizují, stránka to řekne a vede do opisu. Export XLSX i PDF z této záložky má
stejný tvar jako obrazovka v jednotce zvolené přepínačem **Kč / tis. Kč**. PDF se tiskne
na šířku stránky.

#### 58.7.6.1 Odhad do konce roku (nezaúčtováno)

U právnické osoby v roce, který ještě není uzavřený a nemá zaúčtovanou daň
z příjmů (účet 591), je pod tabulkou blok **Odhad do konce roku
(nezaúčtováno)**. Nic z něj není v účetnictví a po zaúčtování daně z příjmů
zmizí. Načítá se samostatně až po tabulce, protože přepočítává náhled
přiznání k DPPO. Aplikace v něm nic nového nepočítá, jen skládá čísla, která
už ukazují jiné stránky:

| Řádek | Odkud je |
|---|---|
| Výsledek hospodaření průběžně (zaúčtováno) | ř. 10 náhledu DPPO; shoduje se s řádkem Výsledek hospodaření před zdaněním výše (k poslednímu dni období) |
| Nezaúčtované operace uzávěrky (časové rozlišení drobného majetku a nákladů příštích období, kurzové rozdíly, rozpuštění rozlišení z minulého roku, konečný stav zásob způsobem B) | projekce uzávěrky v náhledu DPPO, odkaz vede na uzávěrku období ([kap. 72](72_Uzaverka.md)) |
| Odpisy roku podle odpisového plánu (nezaúčtované) | tatáž projekce z karet majetku ([kap. 28](28_Majetek.md)); účetní odpis sníží výsledek, rozdíl proti daňovému odpisu jde do ř. 50 nebo ř. 150 |
| Opravné položky a dohadné položky | tatáž projekce; jde o návrhy, které účetní teprve potvrdí, proto jsou šedě a do odhadu se nesčítají |
| Odhad výsledku hospodaření před zdaněním, připočitatelné a odčitatelné položky (ř. 70 a ř. 170 včetně rozdílu nezaúčtovaných odpisů), základ daně, odhad daně | projekce v náhledu DPPO ([§ 43.3](43_Dan_z_prijmu.md)) včetně ručních úprav základu, ztráty, darů a slev |
| Zaplacené zálohy na daň a odhad doplatku nebo přeplatku | zálohy zadané v přiznání, jinak jistě spárované zálohy z evidence ([§ 43.4](43_Dan_z_prijmu.md)) |
| Odhad výsledku hospodaření po zdanění | odhad výsledku před zdaněním minus odhad daně |

Blok i náhled DPPO ukazují tatáž čísla. Jakmile se krok uzávěrky nebo odpisy
roku zaúčtují, položka z projekce zmizí a částka je v průběžném výsledku,
takže se nic nezapočte dvakrát. Mzdy ani jiné budoucí provozní náklady do
zbytku roku odhad neobsahuje, jde o uzávěrku k dnešnímu stavu účetnictví.

U každého řádku je odkaz na stránku, ze které číslo pochází. Blok se
nezobrazí u fyzické osoby, v uzavřeném roce, při filtru dimenze ani
uživateli bez práva na přiznání k dani z příjmů.

### 58.7.7 Průběžné časové rozlišení

Přijatá faktura s obdobím od–do (pojistné, předplatné, nájem) jde při
zaúčtování celá do nákladu dne dokladu, vydaná faktura s obdobím celá do výnosu.
Část, která patří do dalších měsíců, odloží na účty 381 a 384 až krok uzávěrky
**Časové rozlišení** ([kap. 72](72_Uzaverka.md)). Průběžná výsledovka je proto
v měsíci dokladu zatížená celým nákladem či výnosem.

Volba **Průběžné časové rozlišení** výkaz k datu **Sestaveno k** sestaví tak,
jako by uzávěrka proběhla k tomu dni:

- do nákladů a výnosů se započte jen poměrná část období do data **Sestaveno k**
  včetně, podle počtu dnů, stejně jako v kroku uzávěrky,
- zbytek je v rozvaze jako **náklady příštích období** (381) a **výnosy příštích
  období** (384); období celé po datu jde na 381/384 celé,
- výsledek hospodaření běžného období v rozvaze se upraví o totéž, takže rozvaha
  dál bilancuje.

Upravují se jen doklady zaúčtované od začátku období do data **Sestaveno k**.
Doklad, jehož odklad už je v deníku (zaúčtovaný krok uzávěrky k tomuto dni,
rozpuštění z minulého roku, faktura rozúčtovaná rovnou na 381/384 nebo ruční
interní doklad k faktuře), se znovu neupravuje. Účet nákladu či výnosu je ten,
na který doklad zaúčtoval řádek, včetně analytik účtotvorné dimenze. Stejné
pravidlo platí pro sloupec **Minulé období** k jeho konci.

Do deníku se nic nezapisuje a uzávěrka zůstává stejná. Nad výkazem je poznámka
s částkami na 381 a 384 a počtem upravených dokladů, totéž je v záhlaví PDF a XLSX.
Volba je výchozí vypnutá, aplikace si ji pamatuje pro každého uživatele a platí
společně pro výsledovku v obou členěních i pro rozvahu. Výkaz za hodnotu dimenze
a výkazy podle vyhlášky 504 ji neuplatní. Podání účetní závěrky, přiznání
k dani z příjmů, uzávěrka ani kontroly ji nepoužívají nikdy.

## 58.8 Související kapitoly

- [Výsledovka - účelová](59_Vysledovka_ucelova.md) - stejný celkový výsledek, jiná struktura provozních nákladů
- [Rozvaha](57_Rozvaha.md) - mapování účtů a rozvaha po účtech
- [Obratová předvaha](56_Obratova_predvaha.md)
- [Daň z příjmů](43_Dan_z_prijmu.md) - náhled přiznání, ze kterého vychází odhad do konce roku
- [Uzávěrka](72_Uzaverka.md) a [Majetek](28_Majetek.md)
