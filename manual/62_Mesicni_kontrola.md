# 62. Měsíční kontrola

> Kontrolní brána nad živými účetními daty: spustí stejnou sadu kontrol jako předběžný krok roční uzávěrky, kdykoli během roku. Nic sama neopravuje a nevytváří ani neukládá uzávěrkový krok, takže ji můžete opakovat po každé opravě. Pro účetní, která chce před DPH a uzávěrkou vědět, co je v účetnictví potřeba dořešit.

## 62.1 Kdy to potřebujete

Kapitolu otevřete, když:

- skončil měsíc nebo čtvrtletí a chcete ověřit, že je účetnictví úplné,
- se chystáte podat přiznání k DPH a chcete mít jistotu, že souhlasí účet 343,
- potřebujete zjistit, proč kontrolní firemní pruh v [Přehledu firem](51_Prehled_firem.md) svítí,
- chcete po podání daní zamknout účtování k datu,
- připravujete [Měsíční přehled](63_Mesicni_report.md) pro klienta.

### 62.1.1 Kdy co udělat

<!-- cols: 26 44 30 -->
| Kdy | Co udělat | Kde v aplikaci |
|---|---|---|
| po skončení měsíce, před DPH | Spustit kontrolu za měsíc a vyřešit nálezy | `Účetnictví → Měsíční kontrola`, [§ 62.3](#623-krok-za-krokem-spusteni-kontroly-a-vyreseni-nalezu) |
| po skončení čtvrtletí | Spustit kontrolu za kvartál | stejná stránka, volba **Kvartál** |
| po doloženém podání daní | Zamknout účtování k datu | tlačítko **Uzamknout k datu**, [§ 62.4](#624-krok-za-krokem-zamek-uctovani-k-datu) |
| před uzávěrkou | Spustit kontrolu za celé období | volba **Vlastní od-do** |

## 62.2 Než začnete

1. **Podvojné účetnictví a právo číst účetnictví.** Stránka je dostupná firmám s podvojným účetnictvím a uživatelům s právem číst účetnictví.
2. **Založené účetní období.** Bez něj stránka vyzve: **Nejprve založte účetní období.** Nabízí období ve stavu otevřené nebo uzavírá se.
3. **Doúčtované doklady.** Nálezy o nezaúčtovaných dokladech se řeší v [K doúčtování](54_Rucni_fronta_doctovani.md).
4. **Zámek vyžaduje administrátora.** Zámek účtování k datu server přijme jen od administrátora.

## 62.3 Krok za krokem: spuštění kontroly a vyřešení nálezů

1. Otevřete `Účetnictví → Měsíční kontrola`.
2. V poli **Účetní období** zvolte období.
3. V poli **Rozsah** zvolte **Měsíc**, **Kvartál** nebo **Vlastní od-do**. Rozsah musí ležet uvnitř vybraného období a datum **Od** nesmí být po datu **Do**.
4. Klikněte na **Spustit kontrolu**.
5. Projděte tabulku. Každý řádek ukazuje stav (**V pořádku** nebo **K řešení**), název kontroly a nález.
6. Kliknutím na počet nálezů otevřete živý detail (náhled je omezený na 50 nálezů). Úplný seznam stáhnete jako CSV. U účtů a dokladů jsou odkazy na opis účtu, deník, fakturu nebo kartu majetku.
7. Porovnejte nález s nezávislým podkladem, nejen s jinou obrazovkou aplikace.
8. Opravte zdrojový doklad, platební vazbu, účetní zápis nebo nastavení podle skutečné příčiny. U kontroly spárovaných plateb může nabídka **Doúčtovat** vytvořit návrh vyrovnaného zápisu. Nevyplývá-li z dat řešení, otevře se prázdný ruční zápis.
9. Kontrolu spusťte znovu.
10. Po vyřešení měsíce připravte [Měsíční přehled](63_Mesicni_report.md).

**Jak poznáte, že je hotovo:** Všechny řádky mají stav **V pořádku**, nebo u zbývajících nálezů víte, proč zůstávají. Zelený stav má být důsledkem opravy zdroje, ne ručního dorovnání bez podkladu.

> [!WARNING]
> Zelená kontrola neprokáže existenci případu, který v systému nemá žádná data. Nenahrazuje inventurní soupis, potvrzení banky, partnerské odsouhlasení ani odborný úsudek účetní.

## 62.4 Krok za krokem: zámek účtování k datu

Zámek použijte po doloženém podání daní.

1. V horní části stránky vidíte aktuální stav (**Období není uzamčeno.** nebo **Uzamčeno k datu**).
2. Klikněte na **Uzamknout k datu**.
3. V okně **Uzamknout účtování k datu** vyplňte **Uzamčeno k** (prázdné datum zámek zruší).
4. Vyplňte **Zdůvodnění** (povinné, nejméně 5 znaků), například: podáno přiznání DPH za červen.
5. Klikněte na **Uložit zámek** a potvrďte dotaz. Aplikace upozorní, že doklady s datem do zámku už nepůjde zaúčtovat.

**Jak poznáte, že je hotovo:** Stránka hlásí **Zámek k datu byl uložen.** a doklady s datem do zámku už nejde zaúčtovat ani přeúčtovat.

> [!TIP]
> Administrátor může zámek nastavit, posunout dopředu, posunout zpět pro řízenou opravu nebo zrušit. Každá změna ukládá do auditní stopy původní hodnotu, novou hodnotu a zdůvodnění. Podrobnosti jsou v [Účetním deníku](52_Ucetni_denik.md#521410-zamek-uctovani-k-datu).

## 62.5 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| **Nejprve založte účetní období.** | Není založené žádné účetní období | Založte ho v `Nástroje → Uzávěrka` ([Uzávěrka](72_Uzaverka.md)). |
| Starší uzavřené období není ve výběru | Stránka uzavřená období nenabízí | Použijte otevřené období; uzavřená období kontroluje [Uzávěrka](72_Uzaverka.md). |
| Měsíční běh upozorní na problém mimo vybraný měsíc | Celoroční invarianty (návaznost předchozího období, deník celého období, inventarizace, roční odpisy) jsou svázané s celým obdobím | Nález řešte, i když netýká zobrazeného měsíce. |
| Předchozí období není uzavřené | Bezprostředně předchozí období musí být uzavřené nebo schválené | Dokončete nebo vědomě znovu otevřete předchozí období. |
| Deník není vyrovnaný | Součet MD a Dal celého období se neshoduje | Dohledejte zápis a neopravujte ho nepodloženým dorovnáním. |
| Odchylka kurzu proti ČNB | Upozornění, ne automatická oprava | Pevný, celní nebo jiný doložený kurz může odchylku vysvětlit. Kurz nepřepisujte jen kvůli zelené kontrole. |
| Zámek nejde uložit | Změnu přijme jen administrátor, zdůvodnění má méně než 5 znaků | Požádejte administrátora, doplňte zdůvodnění. |
| Nenulový zůstatek je zelený | U informačních kontrol může být výsledek v pořádku, i když seznam nebo zůstatek vyžaduje kontrolu | Otevřete opis účtu a doložte, proč účet k rozhodnému dni zůstává otevřený. |

## 62.6 Podrobnosti a pravidla

### 62.6.1 Výběr období a rozsahu

Nejdříve vyberte účetní období. Stránka nabízí období ve stavu
**Otevřené** nebo **Uzavírá se**. Následně zvolte:

- **Měsíc** - kalendářní měsíc oříznutý na hranice fiskálního období,
- **Kvartál** - čtyři po sobě jdoucí tříměsíční bloky od začátku fiskálního
  období,
- **Vlastní od-do** - datum od a do.

Rozsah musí ležet uvnitř vybraného účetního období a datum od nesmí být po
datu do. Stejnou čtecí kontrolu lze provést i nad uzavřeným obdobím,
ale stránka uzavřená období ve výběru nenabízí.

Kontroly mají dva časové režimy:

- položkové kontroly používají vybraný interval nebo stav **k datu do**,
- celoroční invarianty, například návaznost předchozího období, deník celého
  období, inventarizace a roční odpisy, zůstávají svázané s celým fiskálním
  obdobím i při výběru jednoho měsíce.

Proto může měsíční běh upozornit i na problém, který není omezen jen na
zobrazený měsíc.

### 62.6.2 Jak číst výsledek

Každý řádek ukazuje stav, název kontroly a buď hodnotu, nebo počet nálezů.
Kontroly mají závažnost:

- **chyba** - celoroční strukturální problém, který může blokovat uzavření
  knih,
- **varování** - stav vyžadující doložení nebo opravu, ale nemusí být sám o
  sobě účetní chybou,
- **informace** - podklad k odbornému posouzení.

Tabulka používá pro každý řádek stav **V pořádku** nebo **K řešení**; samostatný barevný rozdíl závažnosti v ní není. U informačních
kontrol může být stav v pořádku, i když seznam nebo nenulový zůstatek vyžaduje
kontrolu účetní.

Kliknutím na počet se otevře živý detail. Náhled je omezený na 50 nálezů.
Popup si data při prvním otevření znovu načte, aby neukazoval starý stav po
opravě. Úplný seznam lze stáhnout jako **CSV** bez tohoto stropu. U účtů a
dokladů jsou dostupné prokliky na opis účtu, deník, fakturu nebo kartu majetku.

U kontroly spárovaných plateb může nabídka **Doúčtovat** vytvořit návrh
vyrovnaného zápisu. Pokud z dat neplyne jednoznačné řešení, otevře se prázdný
ruční zápis; nesoulad názvu protistrany se účetním zápisem neopravuje.

### 62.6.3 Kontroly období a deníku

| Kontrola | Co server ověřuje | Doporučený postup |
|---|---|---|
| Předchozí období není uzavřené | Bezprostředně předchozí období musí být uzavřené nebo schválené. | Dokončit nebo vědomě znovu otevřít předchozí období. |
| Nenulové výsledkové zůstatky před začátkem období | Náklady a výnosy před počátkem období nemají zůstat otevřené. | Prověřit uzavření a otevření knih. |
| Koncepty v deníku | V celém období existují zápisy, které nejsou zaúčtované. | Dokončit nebo odstranit koncepty. |
| Deník není vyrovnaný | Součet řádků MD a Dal celého období se neshoduje. | Jde o strukturální problém; dohledat zápis a neopravovat jej nepodloženým dorovnáním. |
| Nezaúčtované vydané/přijaté doklady | V zadaném rozsahu existují zaúčtovatelné doklady bez účetního zápisu; zálohové přijaté výzvy se vylučují. | Otevřít filtrovaný seznam, ověřit doklady a zaúčtovat je. |
| Zápisy bez popisu | Zaúčtovaný zápis v rozsahu nemá obsah účetního případu. | Doplnit popis na zdroji nebo u povoleného ručního zápisu. |
| Stornovaný doklad s aktivním zápisem | Evidence dokladu tvrdí storno, deník stále obsahuje živý předpis. | Opravit vazbu řízeným stornem nebo synchronizací zdroje. |

### 62.6.4 Zůstatkové a inventarizační kontroly

| Oblast | Kontrolované účty nebo stav |
|---|---|
| Peníze na cestě | 261 k datu konce rozsahu; řádně doložený převod přes hranici období může být v pořádku. |
| Vnitřní zúčtování | 395. |
| Pořízení majetku | 041 a 042. |
| Pořízení zásob | 111 a 131. |
| Zálohy | 314 a 324; otevřený zůstatek může být legitimní nevypořádaná záloha. |
| Vlastní průběžné účty | Všechny aktivní účty označené v osnově jako zúčtovací. |
| Neobvyklá strana | Zůstatky účtů na jiné než očekávané straně podle typu účtu. |
| Výsledek hospodaření | Nerozdělený zůstatek účtu 431 k poslednímu dni období. |
| Inventarizace rozvahy | Nezaložená inventarizace je varování; rozpracovaná nebo dokončená s nevyřešenými rozdíly je chyba blokující uzavření knih. |
| Dohady a časové rozlišení | Informativní stavy 388/389 a 381–385; navíc varování na nerozpuštěné dohady přenesené z minulého období. |

Nenulový zůstatek není automaticky chyba. Účetní musí otevřít opis účtu,
identifikovat jednotlivé případy a doložit, proč k rozhodnému dni zůstávají
otevřené.

### 62.6.5 Doklady, platby, zálohy a měny

Kontrola porovnává evidenční stav dokladu, platební vazby a deník:

- zaplacené vydané faktury s otevřeným saldem na 311,
- zaplacené přijaté faktury s otevřeným saldem na 321,
- zaplacené proformy, které nemají inkaso na účtu přijatých záloh ani na 311,
- zaplacené zálohové přijaté faktury bez úhrady na 314,
- doklady stále ve stavu odeslané/přijaté, přestože účetní saldo už je
  vyrovnané,
- nesoulady spárované platby v částce, měně, protistraně nebo použití kurzu;
  u plateb kartou se protistrana neporovnává, banka v ní neuvádí obchodníka,
- realizované kurzové rozdíly, které nebyly zaúčtované na 563/663,
- otevřené cizoměnové položky určené k přecenění,
- řádky devizových účtů s neúplnou stopou měny a
  částky v cizí měně,
- odchylku uloženého kurzu dokladu od denního kurzu ČNB.

Kontrola ČNB je upozornění, ne automatická oprava. Pevný kurz, celní kurz nebo
jiný doložený zákonný postup může odchylku vysvětlit. Historický kurz
nepřepisujte jen proto, aby kontrola zezelenala; kurz faktury, kurz úhrady a
závěrkový kurz mají rozdílnou funkci.

### 62.6.6 Majetek, daně a další zákonné oblasti

Součástí běhu jsou také:

- karta majetku v užívání bez účetního odpisu za fiskální rok,
- majetkové účty bez odpovídajících oprávek,
- nesoulad evidence drobného majetku proti obratu účtu 501.200,
- zůstatek účtu 343 proti přiznání k DPH za zvolený interval,
- oprávněnost čtvrtletního zdaňovacího období podle obratu minulého roku,
- vznik povinné registrace k DPH po překročení zákonného limitu,
- transakce se spojenými osobami a měřitelné odchylky jejich cen od
  srovnatelných cen nespojeným osobám,
- povinnost a vnitřní návaznost přehledu o peněžních tocích a změnách vlastního
  kapitálu u příslušné kategorie účetní jednotky,
- informační připomínka k zaúčtování splatné daně z příjmů přes krok uzávěrky.

Kontrola ceny mezi spojenými osobami tvrdí odchylku jen tam, kde má srovnání
stejné položky vůči nespojeným osobám. Samostatný seznam transakcí se
spojenými osobami je informační podklad a vyžaduje dokumentaci ceny obvyklé.

### 62.6.7 Zámek účtování k datu

Nahoře je zobrazen aktuální stav zámku. Uživatel s právem číst účetnictví
stav vidí. Tlačítko **Uzamknout k datu** se zobrazuje podle práva na uzavírání
období, ale server změnu přijme pouze od administrátora.

Administrátor může zámek:

- nastavit nebo posunout dopředu,
- posunout zpět pro řízenou opravu,
- zrušit prázdným datem.

Každá změna vyžaduje důvod o nejméně pěti znacích a ukládá do auditní stopy
původní hodnotu, novou hodnotu a zdůvodnění. Doklady s datem menším nebo
rovným zámku nelze nově zaúčtovat ani přeúčtovat. Podrobnosti jsou v
[Účetním deníku](52_Ucetni_denik.md#521410-zamek-uctovani-k-datu).

### 62.6.8 Co udělat s nálezem

1. Otevřete detail a zjistěte konkrétní doklady nebo účty.
2. Porovnejte nález s nezávislým podkladem, nikoli jen s jinou obrazovkou
   aplikace.
3. Opravte zdrojový doklad, platební vazbu, účetní zápis nebo nastavení podle
   skutečné příčiny.
4. Kontrolu spusťte znovu. Zelený stav má být důsledkem opravy zdroje, ne
   ručního dorovnání bez podkladu.
5. Po vyřešení měsíce připravte
   [Měsíční přehled](63_Mesicni_report.md) a po doloženém podání daní vědomě
   nastavte zámek.

Měsíční kontrola je široká technická brána, ale neprokáže existenci případu,
který v systému nemá žádná data. Nenahrazuje inventurní soupis, potvrzení
banky, partnerské odsouhlasení ani odborný úsudek účetní.

## 62.7 Související kapitoly

- [Měsíční přehled](63_Mesicni_report.md) - klientský report po vyřešení měsíce
- [Úplnost dokladů](61_Uplnost_dokladu.md) a [K doúčtování](54_Rucni_fronta_doctovani.md)
- [Účetní deník](52_Ucetni_denik.md#521410-zamek-uctovani-k-datu) - zámek účtování k datu
- [Uzávěrka](72_Uzaverka.md) - roční postup
- [Účetní kontroly a inventarizace](46_Ucetni_kontroly_a_inventarizace.md)
