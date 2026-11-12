# 69. Inventarizace účtů

> Návod, jak před uzavřením knih odsouhlasit zůstatky rozvahových účtů se skutečností a doložit případné
> rozdíly. Pro účetní firmy v podvojném účetnictví. Doporučený celkový postup popisuje také kapitola
> [Účetní kontroly a inventarizace](46_Ucetni_kontroly_a_inventarizace.md).

## 69.1 Kdy to potřebujete

Kapitolu otevřete, když:

- se blíží uzavření účetního období a kontrola uzávěrky hlásí nedokončenou inventarizaci,
- potřebujete soupis zůstatků rozvahových účtů k rozvahovému dni pro fyzickou nebo dokladovou inventuru,
- potřebujete doložit, jak byl vypořádán rozdíl mezi účetním a skutečným stavem,
- potřebujete vytisknout vyplněný inventarizační protokol s podpisy (§ 29 a 30 zákona o účetnictví),
- potřebujete soupis otevřených položek průběžných účtů 261, 335, 395 a dalších.

Inventarizace účtů převádí konečné zůstatky rozvahových účtů na pracovní protokol, do kterého účetní doplní
skutečný stav a vypořádání rozdílu.

## 69.2 Než začnete

1. **Podvojné účetnictví.** Stránka je dostupná jen firmě v podvojném účetnictví.
2. **Zaúčtované doklady.** Sestava bere jen zaúčtované řádky deníku. Nezaúčtované koncepty se nezapočítají, jejich počet aplikace ukáže ve varování.
3. **Otevřené nebo uzavírané období.** Skutečný stav lze uložit jen v otevřeném nebo uzavíraném období.
4. **Podklady k inventuře.** Připravte si nezávislé podklady ke skutečnému stavu (bankovní výpisy, soupis zásob, odsouhlasení s protistranou). Doporučené podklady viz [§ 69.5.2](#6952-doporucene-podklady).

## 69.3 Krok za krokem: provést inventarizaci

1. Otevřete `Nástroje → Inventarizace účtů`.
2. V poli **Období** zvolte účetní období. Sestava se vždy sestavuje k poslednímu dni celého vybraného období; libovolné Od/Do se nepoužívá.
3. Vyplňte hlavičku **Inventarizačního protokolu**: **Odpovědná osoba**, **Datum inventury**, **Odkaz na protokol** (číslo jednací nebo odkaz na podepsaný protokol) a poznámku.
4. U každého účtu doplňte do sloupce **Skutečný stav** hodnotu z nezávislého podkladu. Účet s analytikami se počítá po analytikách, řádek syntetiky nad nimi je jen součet. Sloupec **Rozdíl** se počítá živě. Sloupec **Způsob doložení** nabízí doporučený podklad. Účty s nulovým zůstatkem doplníte najednou tlačítkem **Potvrdit nulové účty**.
5. U účtu s rozdílem napište do pole **Poznámka k rozdílu**, čím je rozdíl doložen nebo vypořádán. Pak rozdíl potvrďte zaškrtnutím **Vyřešeno**. Samotné zaškrtnutí nic nezaúčtuje; opravu proveďte průkazným zdrojovým nebo ručním zápisem.
6. Klikněte na **Uložit** (protokol zůstane rozpracovaný), nebo na **Uložit a dokončit**, jakmile nezůstal žádný nevyřešený účet.
7. Protokol vytisknete tlačítkem **Export PDF** nebo **Export XLSX**.
8. V bloku **Soupis otevřených položek** zkontrolujte průběžné účty. Kód účtu vede do jeho otevřených položek.

**Jak poznáte, že je hotovo:** Protokol má stav **Dokončeno**, aplikace ohlásila **Inventarizace dokončena** a kontrola uzávěrky už neblokuje uzavření knih.

> [!WARNING]
> **Uložit a dokončit** uspěje, jen když nezůstal žádný nevyřešený účet. Jinak aplikace napíše, kolik
> nevyřešených rozdílů zbývá. Pokud se účetnictví po uložení změní, kontrola znovu porovná uložený skutečný
> stav s aktuálním účetním stavem. Dříve nulový rozdíl tak může být znovu nevyřešený.

## 69.4 Když něco nejde

<!-- cols: 34 30 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Nelze dokončit, zbývají nevyřešené rozdíly | U některých účtů se skutečný stav liší od účetního a nejsou označené jako vyřešené | Rozdíl opravte zápisem, nebo ho doložte poznámkou a zaškrtněte **Vyřešeno**. |
| Skutečný stav nejde uložit | Období je uzavřené nebo schválené | Skutečný stav lze uložit jen v otevřeném nebo uzavíraném období. |
| V období jsou nezaúčtované koncepty | Koncepty se do zůstatků nepočítají | Zaúčtujte je, jinak soupis neodpovídá úplnému stavu knih. |
| Uzavřené období má skutečný stav doplněný sám | Jde o starší uzavřené období bez uloženého protokolu | Viz [§ 69.5.1](#6951-pravidla-sestavy-a-protokolu). Nejde o důkaz fyzické inventury. |
| Kontrola uzávěrky stále blokuje | Inventarizace není dokončená nebo je nevyřešený rozdíl | Dokončete protokol ([§ 69.3](#693-krok-za-krokem-provest-inventarizaci)). |
| Syntetika s analytikami má jediný řádek | Protokol je za ni uložený souhrnně | Skutečný stav vymažte, zrušte **Vyřešeno** a uložte. Účet se rozpadne na analytiky. |
| Soupis otevřených položek hlásí rozdíl | Součet otevřených částek nesedí na zůstatek účtu | Otevřete účet kliknutím na kód a zkontrolujte okruhy. |

## 69.5 Podrobnosti a pravidla

### 69.5.1 Pravidla sestavy a protokolu

**Jaké účty a datum sestava používá.** Sestava se vždy sestavuje k poslednímu dni celého vybraného účetního
období. Z deníku bere jen zaúčtované řádky a vylučuje vlastní závěrkový převod knih.

Zahrnuty jsou účty typu **aktiva**, **pasiva** a **vlastní kapitál**. Rozhoduje typ účtu,
nikoli první číslice: v rámci 504 se zahrnují také příslušné rozvahové účty 9xx.
Nákladové, výnosové, podrozvahové a uzávěrkové účty se vynechají. Každý účet s pohybem má vlastní řádek,
analytiky se řadí pod součtový řádek své syntetiky. Skutečný stav, rozdíl a poznámka se vedou za každou
analytiku zvlášť a nenapočítaná analytika blokuje dokončení stejně jako jiný účet. Součet syntetiky ukáže
skutečný stav a rozdíl, až když jsou napočítané všechny její analytiky.

Protokol uložený ještě za syntetiku zůstává souhrnný, aby dokončená inventarizace nezačala hlásit nevyřešené
řádky. Na analytiky ho převedete podle [§ 69.4](#694-kdyz-neco-nejde).

Pro každý účet platí:

`účetní stav = PS MD − PS Dal + obrat MD − obrat Dal`

Kladná hodnota je konečný zůstatek MD, záporná konečný zůstatek Dal. Na obrazovce se **Účetní stav** a
**Skutečný stav** uvádějí na normální straně účtu, u pasivních účtů (závazky, oprávky, vlastní kapitál) tedy
jako kladné číslo. Účty bez určené strany (±, například 343 nebo 431) se uvádějí znaménkově: kladně na MD,
záporně na Dal. Koncepty se nezapočítají a jejich počet se zobrazí ve varování.

**Protokol a skutečný stav.** Hlavička protokolu obsahuje odpovědnou osobu, datum inventarizace, označení
protokolu a poznámku. U každého účtu lze zadat:

- **Skutečný stav** z nezávislého inventurního podkladu,
- **Rozdíl**, který se živě počítá jako `skutečný stav − účetní stav`,
- potvrzení **Vyřešeno**,
- poznámku k doložení nebo vypořádání rozdílu.

Účet je pro uzávěrku vyřešený, pokud skutečný stav přesně sedí na haléře, nebo účetní rozdíl výslovně označí
jako vyřešený. Samotné zaškrtnutí nic nezaúčtuje; opravu je třeba provést průkazným zdrojovým nebo ručním
zápisem a důvod rozdílu doložit.

Při uložení aplikace znovu načte živé účetní zůstatky, vypočte rozdíly a přepíše položky protokolu, takže
účetní saldo zaslané z obrazovky není zdrojem pravdy.

**Vazba na uzávěrku.** Nedokončená inventarizace nebo nevyřešený rozdíl blokují kontrolu uzávěrky a tím
uzavření knih.

**Starší uzavřená období.** U staršího již uzavřeného či schváleného období bez uloženého protokolu se pro
čtení doplní skutečný stav z účetního zůstatku a položky se označí jako vyřešené. Jde o technický backfill
uzavřeného roku, nikoli důkaz, že byla provedena fyzická nebo dokladová inventura.

### 69.5.2 Doporučené podklady

Aplikace podle účtu nabídne typ podkladu, například:

- 0xx: inventární karta a odpisový plán,
- 1xx: skladová evidence a inventurní soupis,
- 211/213: fyzická inventura hotovosti či cenin,
- 221: bankovní výpis,
- 311/314/321/324: saldokonto a odsouhlasení s protistranou,
- 33x: mzdová rekapitulace,
- 34x: přiznání, rozhodnutí správce daně nebo rekapitulace,
- 38x: smlouva, výpočet časového rozlišení či dohadu,
- 4xx: smlouva, rozhodnutí orgánu společnosti nebo výpočet.

Jde o vodítko. Aplikace neověřuje, zda podklad existuje, je podepsaný nebo odpovídá skutečnosti. Kód účtu
vede do opisu za celé období.

### 69.5.3 Export

**Export PDF** a **Export XLSX** vyexportují vyplněný protokol: hlavičku s odpovědnou osobou, datem inventury
a označením protokolu, u každého účtu konečný zůstatek MD a Dal, stranu, uložený skutečný stav, rozdíl, stav
vyřešení, poznámku a způsob doložení, součty syntetik a místo pro podpisy. Skutečný stav a rozdíl jsou orientované
stejně jako na obrazovce. Nenapočítaný účet má pole prázdná k ručnímu doplnění. Exportuje se uložený stav, před
exportem proto protokol uložte. Protokol staršího uzavřeného období s doplněným stavem nese upozornění, že nejde
o doklad o provedené inventuře.

Stejný protokol je v [uzávěrkovém balíčku](72_Uzaverka.md#729-krok-za-krokem-uzaverkovy-balicek). Nedokončená
inventarizace je tam uvedená v upozorněních.

### 69.5.4 Soupis otevřených položek

Soupis ukazuje k rozvahovému dni otevřené položky vybraných účtů včetně analytik a kontroluje, že součet otevřených
částek odpovídá zůstatku účtu. Bez zadání obsahuje účty 261, 335, 395 a zúčtovací účty z osnovy; zadáním kódů výběr
nahradíte. Účet bez pohybu se vynechá. Na obrazovce je u účtu nejvýš 200 položek, export obsahuje všechny. Spárované
řádky vyrovnaného okruhu otevřené nejsou, viz
[otevřené položky v opisu účtu](55_Hlavni_kniha.md#5585-opis-uctu).

> [!WARNING]
> Aplikace nenahrazuje inventuru. Připraví a hlídá účetní část. Fyzickou inventuru, existenci majetku,
> vymahatelnost pohledávek, úplnost závazků a průkaznost podkladů musí posoudit odpovědná osoba.

## 69.6 Související kapitoly

- [Účetní kontroly a inventarizace](46_Ucetni_kontroly_a_inventarizace.md)
- [Uzávěrka](72_Uzaverka.md)
