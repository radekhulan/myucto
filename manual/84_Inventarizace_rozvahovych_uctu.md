# 84. Inventarizace účtů

**Cesta: `Nástroje → Inventarizace účtů`**

Inventarizace účtů převádí konečné zůstatky rozvahových účtů na pracovní
protokol, do kterého účetní doplní skutečný stav a vypořádání rozdílu.
Je součástí kontroly před uzavřením knih. Doporučený celkový postup popisuje
také kapitola [Účetní kontroly a inventarizace](79_Ucetni_kontroly_a_inventarizace.md).

## 84.1 Jaké účty a datum sestava používá

Sestava se vždy sestavuje k poslednímu dni celého vybraného účetního období.
Libovolné `Od / Do` se zde nepoužívá. Z deníku bere jen zaúčtované řádky a
vylučuje vlastní závěrkový převod knih.

Zahrnuty jsou účty typu **aktiva**, **pasiva** a **vlastní kapitál**, tedy
rozvahové účty tříd 0–4. Nákladové, výnosové, podrozvahové a uzávěrkové účty
se vynechají. Analytiky se seskupují pod syntetiku.

Pro každý účet:

`účetní stav = PS MD − PS Dal + obrat MD − obrat Dal`

Kladná hodnota je KS MD, záporná KS Dal. Na obrazovce se zobrazuje jedno
znaménkové **Účetní saldo**. Koncepty se nezapočítají a jejich počet se
zobrazí ve varování.

## 84.2 Protokol a skutečný stav

Hlavička protokolu obsahuje odpovědnou osobu, datum inventarizace, označení
protokolu a poznámku. U každého účtu lze zadat:

- **Skutečný stav** z nezávislého inventurního podkladu,
- **Rozdíl**, který se živě počítá jako
  `skutečný stav − účetní stav`,
- potvrzení **Vyřešeno**,
- poznámku k doložení nebo vypořádání rozdílu.

Účet je pro uzávěrku vyřešený, pokud skutečný stav přesně sedí na haléře,
nebo účetní rozdíl výslovně označí jako vyřešený. Samotné zaškrtnutí nic
nezaúčtuje; opravu je třeba provést průkazným zdrojovým nebo ručním zápisem
a důvod rozdílu doložit.

Údaje lze ukládat jen v otevřeném nebo uzavíraném období. Při uložení server
znovu načte živé účetní zůstatky, vypočte rozdíly a přepíše položky protokolu,
takže klientem zaslané účetní saldo není zdrojem pravdy.

## 84.3 Dokončení a vazba na uzávěrku

**Uložit** ponechá protokol rozpracovaný. **Uložit a dokončit** uspěje pouze
tehdy, když nezůstal žádný nevyřešený účet. Nedokončená inventarizace nebo
nevyřešený rozdíl blokují kontrolu uzávěrky `inventory_unresolved` a tím
uzavření knih.

Pokud se účetnictví po uložení změní, kontrola znovu porovná uložený skutečný
stav s aktuálním účetním stavem. Dříve nulový rozdíl tak může být znovu
nevyřešený.

U staršího již uzavřeného či schváleného období bez uloženého protokolu se
pro čtení doplní skutečný stav z účetního zůstatku a položky se označí jako
vyřešené. Jde o technický backfill uzavřeného roku, nikoli důkaz, že byla
provedena fyzická nebo dokladová inventura.

## 84.4 Doporučené podklady

Systém podle účtu nabídne typ podkladu, například:

- 0xx — inventární karta a odpisový plán,
- 1xx — skladová evidence a inventurní soupis,
- 211/213 — fyzická inventura hotovosti či cenin,
- 221 — bankovní výpis,
- 311/314/321/324 — saldokonto a odsouhlasení s protistranou,
- 33x — mzdová rekapitulace,
- 34x — přiznání, rozhodnutí správce daně nebo rekapitulace,
- 38x — smlouva, výpočet časového rozlišení či dohadu,
- 4xx — smlouva, rozhodnutí orgánu společnosti nebo výpočet.

Jde o vodítko. Systém neověřuje, zda podklad existuje, je podepsaný nebo
odpovídá skutečnosti. Kód účtu vede do opisu za celé období.

## 84.5 Export

PDF/XLSX z položky menu exportují účetní soupis KS MD/KS Dal, doporučený
podklad a prázdná pole pro ruční doplnění skutečného stavu a rozdílu.
Aktuálně nejde o tisk uložených editovatelných hodnot protokolu z obrazovky;
ty zůstávají uložené v uzávěrkové evidenci aplikace.

> ⚠️ **Aplikace nenahrazuje inventuru.** Připraví a hlídá účetní část.
> Fyzickou inventuru, existenci majetku, vymahatelnost pohledávek, úplnost
> závazků a průkaznost podkladů musí posoudit odpovědná osoba.
