# 45. Účetní deník

**Účetní deník** je jádrem podvojného účetnictví v MyÚčto — chronologický seznam všech
účetních zápisů firmy, tedy dvojic (nebo vícenásobných skupin) řádků **MD** (má dáti) a
**Dal**, u kterých musí vždy platit **Σ MD = Σ Dal**. Najdeš ho v menu **Účetnictví →
Účetní deník**. Modul je dostupný jen pro firmy vedené v režimu **podvojné účetnictví**
— firmy na [daňové evidenci](90_Danova_evidence.md) vedou místo něj jednodušší
[Peněžní deník](90_Danova_evidence.md) bez podvojných zápisů.

> [!NOTE]
> Deník je jen **evidence toho, co se stalo** — nepředkontovává sám o sobě. Kterým
> účtům (MD/Dal) se má konkrétní doklad zaúčtovat, řeší **předkontace** — viz
> [Účtový rozvrh](81_Ucetni_osnova.md) a
> [Předkontace](88_Ucetni_nastroje.md#883-predkontace). Tato kapitola popisuje
> jen samotný deník: jak se v něm zápisy zobrazují, jak založit ruční zápis a jak
> zápis opravit nebo stornovat.

Zaúčtování jakéhokoli dokladu — ať už automatické (faktura, banka, pokladna, majetek),
nebo ruční — vždy prochází stejnou vnitřní službou, která hlídá podvojnost, otevřenost
účetního období a idempotenci. Díky tomu se v deníku nikdy neobjeví nevyrovnaný zápis
ani duplicitní zaúčtování téhož dokladu, ani kdyby doklad někdo omylem zaúčtoval
dvakrát rychle po sobě (dvojklik, výpadek sítě a opakování požadavku apod.).

## 45.1 Odkud se zápisy berou

Naprostou většinu zápisů do deníku **nezakládáš ručně** — vznikají automaticky jako
vedlejší produkt běžné práce s doklady. Systém k dokladu sestaví vyrovnané řádky MD/Dal
podle nastavených předkontací a zapíše je do deníku; ty už jen v deníku vidíš výsledek
a můžeš se z něj prokliknout zpět na zdrojový doklad. Podle sloupce **Zdroj** rozeznáš:

| Zdroj v deníku | Kdy vzniká |
|---|---|
| **Vydaná faktura** | zaúčtování vydané faktury (311/6xx + DPH na výstupu **343.200** podle [Knihy DPH](37_Kniha_DPH.md)) |
| **Přijatá faktura** | zaúčtování přijaté faktury (321/5xx nebo 04x/02x u majetku + DPH na vstupu **343.100**) |
| **Banka** | spárování položky bankovního výpisu s dokladem — viz [Banka](28_Banka.md) |
| **Pokladna** | zaúčtování pokladního dokladu — viz [Pokladna](30_Pokladna.md) |
| **Zápočet / vypořádání** | vzájemný zápočet nebo jiné vypořádání otevřených položek |
| **Sklad** | zaúčtování příjmu, výdeje nebo inventurního rozdílu zásob |
| **Odpis majetku** | měsíční/roční odpisový běh karty majetku |
| **Zařazení majetku** / **Vyřazení majetku** | uvedení majetku do užívání / jeho vyřazení |
| **Uzavření knih** / **Otevření knih** | roční uzávěrka a otevření nového účetního období |
| **Zúčtování DPH** | měsíční (u čtvrtletního plátce čtvrtletní) interní doklad, který převede vstupní a výstupní daň na **343.900** — viz [§ 81.3.3](81_Ucetni_osnova.md#8133-mesicni-zuctovani-dph) |
| **Kurzové přecenění** | přecenění cizoměnových zůstatků k rozvahovému dni (viz [§ 45.5](#455-multi-menove-radky-zapisu)) |
| **Ruční** | zápis, který jsi založil(a) přímo v deníku (viz [§ 45.4](#454-rucni-zapis)) |

Každý takto vzniklý zápis nese v poli **Zdroj** vazbu na konkrétní doklad
(`source_type` + `source_id`). Kliknutí otevře postranní souhrn zdroje; z něj lze
přejít do plného detailu podporovaného dokladu. Zdrojový panel funguje pro vydané
a přijaté faktury, banku, pokladnu, majetek, odpisy i vypořádání.

> [!TIP]
> Rychlá cesta z dokladu do deníku a zpět: v detailu vydané/přijaté faktury najdeš
> u zaúčtovaného dokladu odkaz **„Zobrazit v deníku"** a badge **Zaúčtováno/Koncept**.
> V deníku pak filtr **Zdroj** + drill-down podle `source_id` zobrazí přesně zápis
> k danému dokladu.

### 45.1.1 Idempotence — proč doklad nejde zaúčtovat dvakrát

Dvojice `(typ zdroje, ID zdrojového dokladu)` je pro zápis **jedinečná** — v databázi
ji hlídá unikátní klíč přímo nad tabulkou zápisů. Opětovné zaúčtování téhož dokladu
(např. po opravě údajů na faktuře, nebo když stejný požadavek odejde omylem dvakrát)
proto **nikdy nevytvoří druhý zápis**:

- systém k dokladu nejdřív zkusí najít existující zápis; pokud ho najde, **smaže jeho
  původní řádky MD/Dal a nahradí je nově spočítanými** — zápis si zachová stejné ID,
  jen se mu zvýší interní číslo verze (`row_version`) a přepíšou se částky, popis
  i datum dokladu podle aktuálního stavu zdrojového dokladu,
- pokud dva požadavky na zaúčtování téhož dokladu odejdou **současně** (typicky
  dvojklik na tlačítko), databáze druhý souběžný pokus o vložení odmítne jako
  duplicitu — aplikace to detekuje a automaticky ho převede na stejný přepis popsaný
  výše, takže výsledek je stejný, ať se doklad zaúčtuje jednou, nebo omylem vícekrát
  „najednou",
- přepis se **neprovede** u zápisu, který je mezitím **stornovaný** (viz
  [§ 45.8](#458-storno-a-oprava-zauctovaneho-zapisu)) — takový zápis se z principu už
  nesmí měnit, doklad je nutné zaúčtovat jako zcela nový zápis,
- přepis se **neprovede** ani tehdy, když se aktuální zápis nachází v mezitím
  **uzavřeném** účetním období — do uzavřeného období nejde zasáhnout, i kdyby šlo jen
  o přepočet stejného dokladu (§35 zákona o účetnictví).

Ruční zápisy (`source_type` = Ruční) žádné `source_id` nemají, takže se na ně
idempotence nevztahuje — každé uložení ručního zápisu je vždy nový samostatný zápis.

## 45.2 Seznam zápisů

Stránka **Účetní deník** zobrazuje stránkovaný seznam zápisů (50 na stránku,
navigace stránek dole; zápisy jsou seřazené od nejnovějších — nejdřív podle data
zápisu, při shodném datu podle pořadí vzniku) se sloupci:

- **Datum** — datum účetního případu (`entry_date`),
- **Doklad** — číslo dokladu/zápisu,
- **Datum dokladu** *(skryto ve výchozím zobrazení)* — datum vyhotovení, pokud se liší od data zápisu,
- **Popis**,
- **Zdroj** — typ a číslo zdrojového dokladu; odznak **Automaticky** se u
  automatického zápisu zobrazuje přímo v tomto sloupci, stejně jako ikona
  řetězu u zápisů, které mají protějšek (doklad ↔ jeho úhrada — viz
  [Souvisí: doklad a jeho úhrada](#4522-souvisi-doklad-a-jeho-uhrada)),
- **Částka** — bez filtru **Účet od / Účet do** celková částka zápisu (Σ MD, u
  vyváženého zápisu shodná se Σ Dal); s aktivním filtrem na účet naopak částka
  PŘIPADAJÍCÍ na filtrovaný rozsah účtů v daném zápisu, se značkou **MD**/**Dal**
  za částkou — u zápisu s víc nohama na různých účtech (např. náklad + zúčtování
  zálohy) by jinak sloupec ukazoval součet celého zápisu, ne částku vybraného účtu,
- **Stav** — badge **Zaúčtováno** (zeleně) nebo **Koncept** (šedě),
- **Zaúčtováno dne** a **Zaúčtoval** *(skryto ve výchozím zobrazení)*.

Přes ikonu ozubeného kola (**ColumnPicker**) si zobrazené sloupce přizpůsobíš, přepínačem
hustoty řádků (**DensityToggle**) zvolíš kompaktnější nebo prostornější tabulku. Nastavené
kombinace filtrů lze uložit a znovu použít přes **Uložené filtry**.

### 45.2.1 Drill-down na zdrojový doklad

Kliknutí na **Zdroj** otevře read-only postranní panel se souhrnem zdroje, aniž by
uživatel ztratil rozevřený deník a filtry. Podle typu nabízí odkaz do plného detailu:

- **vydaná/přijatá faktura** — detail faktury,
- **banka** — detail bankovního výpisu, ke kterému spárovaná transakce patří (viz
  [Banka](28_Banka.md)); zápis totiž vzniká z jednotlivé transakce, proklik tě ale vezme
  rovnou na výpis, který ji obsahuje,
- **pokladna** — seznam **Pokladna** předfiltrovaný na konkrétní pokladnu a číslo
  dokladu (samostatnou stránku detailu pokladní doklad nemá, filtr ale dokladu obratem
  najde — viz [Pokladna](30_Pokladna.md)).

- **majetek a odpis** — karta majetku nebo příslušný odpis,
- **vypořádání** — detail vazeb vypořádaných položek.

U technických zdrojů bez samostatného detailu, například u uzavření knih nebo
kurzového přecenění, zůstane zdroj textový.

### 45.2.2 Souvisí: doklad a jeho úhrada

Deník vede fakturu a její úhradu jako **dva samostatné zápisy** — předpis (311/6xx,
resp. 5xx/321) a úhradu (221/311, resp. 321/221). Účetní je ale řeší jako jeden
případ, proto má každý takový zápis panel **Souvisí**: v rozbaleném řádku deníku
i v postranním náhledu zdrojového dokladu.

Panel u každého protějšku ukazuje typ (banka, pokladna, zápočet, faktura), číslo,
datum, částku a nabízí tři cesty:

- **Náhled** — přepne postranní panel na *zaúčtování protějšku*, aniž bys opustil
  deník; zpět se vrátíš šipkou v hlavičce panelu,
- **Zápis #…** — odskok na protějšek přímo v deníku (deep-link `?entry_id=`),
- **Otevřít doklad** — detail faktury, bankovní výpis s danou transakcí nebo
  předfiltrovaná pokladna.

Vazby se hledají přes evidenci plateb, párování bankovních transakcí (včetně
souhrnných plateb pokrývajících víc dokladů), pokladní doklady navázané na fakturu
a zápočty. Když transakce pokryla jen část dokladu nebo naopak víc dokladů najednou,
panel vedle celkové částky pohybu uvádí i **částku připadající na tento doklad**.

Protějšek, který ještě **není zaúčtovaný**, je označený štítkem *Nezaúčtováno* —
je to typický důvod, proč saldo nesedí s deníkem, takže se záměrně nezamlčuje.

Ikona řetězu ve sloupci **Zdroj** ukazuje, které zápisy protějšek mají, ještě než
řádek rozbalíš.

Kromě takto **odvozených** vazeb panel ukazuje i **ruční vazby na doklad** (viz
[Vazba na doklad](#4564-vazba-na-doklad)) — mají vlastní barvu štítku, protože nevznikly
z evidence plateb, ale zadal je uživatel. Vidíš je z obou stran: u ručního zápisu
jako *Navázaný doklad*, u zaúčtování dokladu jako *Navázaný zápis*.

### 45.2.3 Filtry

Nad tabulkou je filtrační lišta:

- **Číslo dokladu** — hledá částečnou shodu v celém deníku, ne jen na aktuální stránce,
- **Fulltext** — hledá v čísle dokladu, popisu a dostupných údajích zdroje,
- **Období** — výběr účetního období (fiskální rok) ze seznamu založených období,
- **Datum od / Datum do** — rozsah data účetního případu,
- **Zdroj** — omezení na jeden typ zdroje, včetně **Banka** a **Pokladna** (drill-down
  z [Banky](28_Banka.md)/[Pokladny](30_Pokladna.md) i tento filtr vedou ke stejnému
  výsledku, jen jinou cestou — buď z konkrétního dokladu do deníku, nebo z deníku podle
  typu zdroje),
- **Původ** — jen zápisy vytvořené **automaticky**, po **ručním potvrzení**, nebo
  **ručně** bez automatického návrhu,
- **Účet od / Účet do** — omezí deník na rozsah kódů účtů,
- **Částka od / Částka do** — omezí celkovou částku zápisu,
- **Stav** — jen zaúčtované, jen koncepty, nebo vše.

Odkaz **„Zrušit filtry"** vrátí výchozí (prázdný) stav. Když do stránky přijdeš
prokliknutím z jiného místa aplikace (detail dokladu, uzávěrka, sestavy), filtry se
předvyplní automaticky podle parametrů v URL — typicky se rovnou rozbalí konkrétní zápis
a rozsah data se zúží přesně na den daného zápisu, aby ses v dlouhém deníku neztratil(a).

### 45.2.4 Export PDF / XLSX

Tlačítka **Export PDF** a **Export XLSX** nad tabulkou stáhnou deník **přesně s aktuálně
nastavenými filtry** (fulltext, číslo dokladu, období, rozsah dat, zdroj, původ,
stav, rozsah účtů a částek) — deník je totiž jako jediná
zákonná kniha (§13 zákona o účetnictví), kterou je potřeba mít i mimo aplikaci (archivace,
předložení auditorovi nebo finančnímu úřadu). Export obsahuje zápisy **chronologicky**
(od nejstaršího), u každého hlavičkový řádek se sloupcem **Původ** a řádky **MD/Dal**
s kódem a názvem účtu za VŠECHNY účty zápisu. Částka v hlavičkovém řádku se řídí stejným
pravidlem jako sloupec Částka v seznamu: bez filtru na účet je to celková částka zápisu
(Σ MD, u vyváženého zápisu shodná se Σ Dal, proto je v obou sloupcích), s aktivním filtrem
na účet naopak jen částka připadající na filtrovaný rozsah účtů, ve sloupci MD nebo Dal
podle strany. PDF má **číslované strany**. Export je omezený na max. **5 000 zápisů** najednou
— při větším rozsahu zúži filtr (typicky Datum od/do) a export zopakuj po částech.

### 45.2.5 Rozklik na detail zápisu

Klikem na řádek se zápis rozbalí a zobrazí:

- tabulku **řádků zápisu** — účet (kód + název z osnovy), středisko, částka na straně
  **MD** nebo **Dal**; u cizoměnových řádků i částka v původní měně pod částkou v CZK
  (viz [§ 45.5](#455-multi-menove-radky-zapisu)),
- součtový řádek **Celkem**,
- **popis zápisu** s možností úpravy a **přílohy** dokladu (viz
  [§ 45.6](#456-popis-prilohy-a-poznamky)),
- samostatné **poznámky**, které lze připnout, upravit a zachovat s autorem,
- panel **Související dokumenty** pro vazby na dokumentový archiv,
- datum **vytvoření** zápisu,
- panel **Proč takto?** u automatizovaných zápisů — ukáže zdroj rozhodnutí (například
  shodu platby nebo pravidlo), režim automatického zpracování a dostupné auditní údaje,
- tlačítko **Kopírovat jako nový** — otevře formulář **Ruční účetní zápis** s předvyplněnými
  stejnými řádky (účet, strana, částka) a **dnešním datem**; hodí se pro doklady, které se
  opakují bez šablony (jednorázová varianta oproti šablonám, viz [§ 45.4](#454-rucni-zapis)),
- tlačítko **Stornovat** (u aktivního zaúčtovaného zápisu), nebo odkaz na **stornující
  zápis** (u již stornovaného).

Zápisy, které už byly stornovány, mají v seznamu badge **Stornováno** a jsou vizuálně
ztlumené (nižší kontrast řádku).

## 45.3 Koncept vs. zaúčtovaný zápis

Zápis může být ve dvou stavech:

- **Koncept** (`posted_at` prázdné) — zápis existuje, ale nebyl finálně zaúčtován,
- **Zaúčtováno** (`posted_at` vyplněné) — zápis je platný a započítává se do hlavní
  knihy, obratové předvahy i výkazů (tam na koncepty upozorňuje hláška „V rozsahu je
  N nezaúčtovaných konceptů — nejsou zahrnuty").

Rozlišení najdeš v badge sloupce **Stav** i ve filtru **Stav**. Koncept se u
přepisovatelných dokladů (viz idempotence v [§ 45.1](#4511-idempotence-proc-doklad-nejde-zauctovat-dvakrat))
při opravě zdrojového dokladu jednoduše přepíše — storno dává smysl **jen u zaúčtovaného**
zápisu (koncept se opraví/nahradí přímo, ne protizápisem).

## 45.4 Ruční zápis

Tlačítkem **„Ruční zápis"** na hlavní stránce deníku (jen role s právem zápisu — účetní/
administrátor) otevřeš formulář **Ruční účetní zápis** (`/accounting/journal/new`).

### 45.4.1 Hlavička

- **Datum zápisu** — povinné, datum účetního případu; musí spadat do **existujícího a
  otevřeného** účetního období, jinak zaúčtování selže s chybou o chybějícím/uzavřeném období,
- **Číslo dokladu** — volitelné; necháš-li prázdné a firma má na stránce
  [Účetní období](87_Uzaverka.md) zapnutou volbu **„Automatická čísla dokladů ručních
  zápisů (řada ID)"**, systém číslo přidělí automaticky z číselné řady vedené pro ruční
  zápisy (spravuje se v Nástrojích na záložce **Číselné řady**),
- **Popis** — volitelný text zápisu (max. 255 znaků).

### 45.4.2 Řádky zápisu

Tabulka řádků, kde ke každému přidáš:

- **Kód účtu** — textové pole s **našeptávačem** (datalist) nad aktivními účty firemní
  osnovy; při rozpoznaném kódu se pod polem zobrazí název účtu, při nerozpoznaném
  hláška „Účet není v osnově",
- **Stranu** — **MD** nebo **Dal**,
- **Částku** — kladné číslo, na 2 desetinná místa (vždy v účetní měně CZK — ruční zápis
  přes formulář **nepodporuje** zadání cizí měny/kurzu na řádku, na rozdíl od
  automatických zápisů z cizoměnových faktur, viz [§ 45.5](#455-multi-menove-radky-zapisu)),
- **Středisko** — volitelné analytické členění. Pole našeptává aktivní položky z firemního
  číselníku **Nástroje → Střediska**, ale kvůli kompatibilitě historických zápisů lze
  ponechat i vlastní volný text.

Tlačítkem **„+ Přidat řádek"** přidáš další řádek, křížkem u řádku ho odebereš (musí
zůstat aspoň jeden). Do nabídky účtů se dostanou jen **aktivní** účty (syntetika i
analytika), neaktivní se v novém zápisu nenabízí.

### 45.4.3 Kontrola vyrovnanosti

Pod tabulkou řádků systém průběžně počítá součty **MD** a **Dal** a zobrazuje badge:

- **„Vyrovnáno"** (zeleně) — Σ MD = Σ Dal a součet je kladný,
- **„Rozdíl X"** (žlutě) — zápis není vyrovnaný, X je rozdíl MD − Dal.

Tlačítko **„Zaúčtovat"** je aktivní až když je zápis vyrovnaný **a** žádný řádek nemá
prázdný účet nebo nekladnou částku. Stejnou podmínku (**Σ MD = Σ Dal**, kontrolovanou
na zaokrouhlených částkách **v haléřích**, tedy přesně na tom, co se skutečně uloží,
nikoli přes nepřesné porovnání desetinných čísel) vynucuje i backend — ruční obejití
kontroly na frontendu tedy nic nezmůže, server nevyrovnaný zápis vždy odmítne.

Po úspěšném uložení se zápis rovnou zaúčtuje (vznikne `posted_at`) a přesměruje tě zpět
do seznamu deníku.

### 45.4.4 Nejčastější chybové hlášky při ukládání

Kromě nevyrovnanosti může uložení ručního zápisu odmítnout i z dalších důvodů —
všechny hlídá server bez ohledu na to, co propustí formulář:

| Situace | Co uvidíš |
|---|---|
| Chybí datum zápisu | „Vyplňte datum" (frontend) / `entry_date musí být datum` (backend) |
| Žádný řádek nemá vyplněný účet nebo částku | „Vyplňte všechny řádky" |
| Součet MD ≠ součet Dal | „Zápis není vyrovnaný" + konkrétní rozdíl |
| Zadaný kód účtu není v účtové osnově firmy | Účet ✱ není v účtové osnově — zkontroluj kód |
| Pro datum zápisu neexistuje založené účetní období | Pro zadané datum neexistuje účetní období |
| Účetní období pro dané datum je uzavřené / uzavírá se | Do uzavřeného období nelze účtovat (§35 ZoÚ) |

### 45.4.5 Příklad: ruční zápis se dvěma řádky

Účetní potřebuje zaúčtovat zálohu na pracovní cestu vyplacenou zaměstnanci z hotovosti
mimo běžný pokladní doklad. Založí ruční zápis takto:

| Datum zápisu | Číslo dokladu | Popis |
|---|---|---|
| 15. 3. 2026 | (necháno prázdné → přidělí se automaticky) | Záloha na pracovní cestu — Novák |

| Účet | Název | Středisko | MD | Dal |
|---|---|---|---:|---:|
| 335 | Pohledávky za zaměstnanci | — | 5 000,00 | |
| 211 | Pokladna | — | | 5 000,00 |
| | **Celkem** | | **5 000,00** | **5 000,00** |

Badge pod tabulkou ukáže **„Vyrovnáno"** (Σ MD = Σ Dal = 5 000 Kč), tlačítko
**„Zaúčtovat"** se odemkne a po odeslání zápis rovnou vznikne jako **Zaúčtováno**.

### 45.4.6 Převod mezi účty (261 — Peníze na cestě)

Vedle tlačítka Zaúčtovat je i tlačítko **„Převod banka ↔ pokladna"**, které otevře
samostatný dialog **Převod mezi účty (261)**. Použiješ ho pro přesun peněz mezi dvěma
účty firemní osnovy (typicky mezi bankovními účty, nebo banka/pokladna), kdy odeslání
a přijetí spadá do různých dat — systém vytvoří **dvě nohy** přes účet **261 — Peníze
na cestě** (MD 261 / D zdrojový účet při odeslání, MD cílový účet / D 261 při přijetí),
sdílející číslo dokladu z vlastní číselné řady **PP** (přebírá se ze stejné správy
číselných řad jako řada pro ruční zápisy — viz [Účetní období](87_Uzaverka.md)). Zadáš:

- **Z účtu** / **Na účet** — kódy účtů (musí existovat v osnově a být různé),
- **Částka** — kladná,
- **Datum odeslání** / **Datum přijetí**,
- **Popis** *(volitelné)*.

Po odeslání se obě nohy zaúčtují najednou a dialog se zavře s potvrzením čísel dokladů.

### 45.4.7 Uložit a nový

Tlačítko **„Uložit a nový"** vedle **„Zaúčtovat"** uloží rozepsaný zápis stejně jako
běžné odeslání, ale místo přesměrování do deníku **vyčistí řádky** pro další zápis
(datum a popis zůstávají). Hodí se, když účetní zapisuje víc podobných interních
dokladů za sebou (např. zálohy víc zaměstnancům ve stejný den).

### 45.4.8 Šablony ručních zápisů a mzdový můstek

Ruční zápisy, které se opakují (mzdy z externí mzdovky, splátky leasingu), nemusíš
každý měsíc vyklikávat znovu — ulož si je jako **šablonu**.

**Uložit jako šablonu** — tlačítko vedle **„Zaúčtovat"** (aktivní, jakmile má každý
rozepsaný řádek vyplněný kód účtu). V dialogu zadáš:

- **Název šablony** — povinný,
- **Popis** — volitelný,
- **Pojmenování řádků** — ke každému řádku (účet + strana pro orientaci) volitelný
  název pro čitelnost, např. „Hrubé mzdy", „Sociální pojištění zaměstnavatele" —
  usnadní pozdější napárování CSV importu (viz níže),
- **„Uložit i aktuální částky jako výchozí"** — nezaškrtnuto (výchozí): šablona si
  pamatuje jen kostru (účty, strany, pojmenování) a částky zůstanou při použití
  **prázdné k doplnění** — typické pro mzdy, kde se částka mění každý měsíc. Zaškrtneš
  ji u zápisů s pevnou částkou (např. fixní splátka leasingu).

**Nový ze šablony** — tlačítko v hlavičce formuláře ručního zápisu. Vybereš uloženou
šablonu ze seznamu (nebo ji smažeš křížkem, pokud už není potřeba) a tlačítkem
**„Použít šablonu"** se řádky předvyplní do gridu. **Nic není uzamčené** — účet, strana
i částka na každém řádku se dají v gridu běžně přepsat, šablona je jen výchozí bod.
Prázdné částky (typicky mzdy) doplníš ručně, nebo je napárujete z CSV (viz dále).
Datum zápisu se nemění (zůstává dnešek, případně datum, které jsi už vyplnil).

Všechny uložené šablony najdeš také v **Šablony → Šablony zápisů**. Na této záložce
lze šablonu vytvořit, upravit její název, popis i jednotlivé řádky (účet, stranu,
výchozí částku, pojmenování a středisko), smazat ji nebo z ní rovnou založit nový
ruční zápis.

Superadmin má navíc stránku **Systém → Šablony bank. pravidel**. Ta spravuje globální
katalog pravidel nabízených všem firmám přes **Banka → Pravidla účtování → Ze šablony**.
U šablony lze nastavit český a anglický název, směr, kritéria shody, typ operace,
globální předkontaci, prioritu, pořadí a aktivní stav. Úprava se projeví jen při
budoucím vytvoření pravidla; již existující firemní pravidla zůstávají beze změny.
Šablonu použitou některou firmou lze deaktivovat, nikoli smazat.

Firemní číselník středisek se spravuje v **Nástroje → Střediska**. Při založení se kód
automaticky vytvoří z názvu a před uložením jej lze ručně upravit. Uložený kód je pak
neměnný; upravovat lze název a stav aktivní/neaktivní. Aktivní střediska se nabízejí našeptávačem
v ručním zápisu i v editoru šablon. Středisko, které už je použité, se při odstranění
jen deaktivuje, aby zůstaly historické účetní zápisy čitelné.

Firma vedená v podvojném účetnictví má automaticky k dispozici doporučenou šablonu
**„Mzdy"** (badge „doporučená"), naseedovanou při prvním otevření dialogu **Nový ze
šablony**, s řádky:

| Účet | Strana | Pojmenování |
|---|---|---|
| 521 | MD | Hrubé mzdy |
| 524 | MD | Sociální a zdravotní pojištění za zaměstnavatele |
| 331 | Dal | Závazek vůči zaměstnancům (čistá mzda k výplatě) |
| 336 | Dal | Zúčtování se OSSZ a zdravotními pojišťovnami |
| 342 | Dal | Záloha na daň ze závislé činnosti |

Jde o **mzdový můstek pro externí mzdovku** — šablona drží typický předpis pro
rychlé zaúčtování importované rekapitulace. Vedle něj je k dispozici také obrazovka
**Účetnictví → Mzdová rekapitulace**, která umí z měsíčních vstupů vypočítat náhled
a zaúčtovat předpis. Obě cesty jsou alternativní: tentýž měsíc nezaúčtovávej jednou
importem a podruhé mzdovou rekapitulací. Mzdový list a jeho podklady popisuje
[Mzdách](57_Mzdy.md).

#### Import rekapitulace z CSV

Po výběru šablony se v dialogu **Nový ze šablony** zobrazí sekce **„Import rekapitulace
z CSV"**. Nahraješ CSV soubor se **dvěma sloupci** (oddělovač `;` nebo `,`, volitelná
hlavička se přeskočí automaticky):

1. **název položky** (musí odpovídat pojmenování řádku šablony, diakritika a velikost
   písmen se ignorují) **nebo kód účtu** (např. `521`),
2. **částka** (podporuje český i anglický formát desetinné čárky/tečky).

Příklad CSV z externí mzdovky:

```csv
Položka;Částka
Hrubé mzdy;185000
524;62790
Závazek vůči zaměstnancům (čistá mzda k výplatě);134800
336;42200
342;27790
```

Tlačítkem **„Nahrát a napárovat"** systém napáruje řádky CSV na řádky šablony a rovnou
je předvyplní do gridu ručního zápisu (žádný zápis se přitom nezapisuje do databáze —
jde jen o náhled/předvyplnění). Položky, které se nepodařilo napárovat (překlep v názvu,
neznámý účet), se ohlásí toastem s počtem — zkontroluj názvy nebo kódy účtů a doplň je
ručně. Import je jednorázový — soubor se nikam neukládá, slouží jen k rychlému vyplnění
aktuálního zápisu.

## 45.5 Multi-měnové řádky zápisu

Zákon o účetnictví (§4 odst. 12) vyžaduje, aby se pohledávky, závazky, valuty, ceniny
a devizové účty vedly **současně v cizí měně i v korunách**. MyÚčto proto u řádků
deníku (kromě běžné částky v CZK) volitelně ukládá:

- **měnu** řádku (např. EUR, USD) — `NULL`, pokud je řádek v účetní měně (CZK),
- **kurz** k účetní měně, kterým byla částka přepočtena,
- **částku v cizí měně** — tj. původní částku dokladu v jeho měně.

Tyto tři údaje se vyplní **automaticky** jen na saldokontních řádcích (311 — odběratelé,
321 — dodavatelé) vznikajících ze zaúčtování **cizoměnové** vydané nebo přijaté faktury.
Faktura vystavená/přijatá v CZK má vždy kurz 1,0 a tyto sloupce zůstávají prázdné (není
co přeceňovat). **Ruční zápis** cizí měnu na řádku zadat neumožňuje — všechny jeho
částky jsou vždy v CZK.

V rozbaleném detailu zápisu se cizoměnová částka zobrazí jako malý řádek pod částkou
v CZK (viz [§ 45.2](#4525-rozklik-na-detail-zapisu)), takže u faktury v eurech vidíš
zaokrouhlenou korunovou částku i skutečnou částku v EUR, ze které vznikla.

### 45.5.1 Kurzové přecenění a kurzové rozdíly

K rozvahovému dni (v rámci [uzávěrky](87_Uzaverka.md)) systém přecení otevřené
cizoměnové zůstatky aktuálním kurzem a rozdíl proti účetně vedené hodnotě zaúčtuje jako
samostatný zápis se zdrojem **Kurzové přecenění** — kurzový **zisk** na účet **663**,
kurzová **ztráta** na účet **563** (přesný účet určuje předkontace `fx.gain`/`fx.loss`,
s výchozí hodnotou 663/563, pokud si ji ve firemní osnově nepřenastavíš). Totéž pravidlo
(663 zisk / 563 ztráta) platí i pro přecenění cizoměnových zůstatků na bankovních účtech.

> [!NOTE]
> Drobný haléřový rozdíl, který u automaticky zaúčtovaných faktur vznikne jen
> zaokrouhlením (ne kurzem), se nepočítá jako kurzový rozdíl — doúčtuje se na účet
> **648** (ostatní provozní výnos) nebo **548** (ostatní provozní náklad), aby seděla
> podvojnost do posledního haléře.
>
> Toto dorovnání má ale **strop 2 Kč** — když se celková částka dokladu neshoduje se
> základem a DPH spočtenými z položek/DPH evidence o víc než 2 Kč, zaúčtování se
> **odmítne** (s rozpisem obou částek a rozdílu) místo tichého zápisu na 648/548. Nad
> touto hranicí už nejde o zaokrouhlení, ale o skutečný nesoulad dokladu (špatná DPH
> klasifikace položky, ručně přepsaný základ/DPH v rekapitulaci DPH apod.) — je potřeba
> doklad opravit, ne rozdíl zamaskovat jako výnos/náklad. Jde o jinou toleranci než
> 1 Kč dorovnání u spárovaných bankovních plateb (viz [Banka § 28.7.1](28_Banka.md#2871-sparovane-platby-faktur-primy-zapis)) —
> to řeší jen zaokrouhlení mezi součtem alokací a částkou platby, ne shodu hlavičky
> dokladu s DPH evidencí.

> [!NOTE]
> Účty **701, 702** a **710**, na které se během roční uzávěrky účtují zápisy se
> zdrojem Uzavření/Otevření knih, mají v účtové osnově vlastní typ **„Závěrkový"**
> (odlišný od Kapitálu) — viz [Účtový rozvrh](81_Ucetni_osnova.md) a
> [Předkontace](88_Ucetni_nastroje.md#883-predkontace).
> Díky tomu je výkazy (rozvaha/VZZ) do vlastního kapitálu firmy nezapočítávají.

## 45.6 Popis, přílohy a poznámky

Po rozbalení detailu zápisu jsou pod řádky MD/Dal dvě další sekce.

### 45.6.1 Inline editace popisu

U zápisů se zdrojem **Ruční**, **Uzavření knih** nebo **Otevření knih** (jediné typy,
u kterých popis nespravuje jiný doklad) se u popisu zobrazí ikona **tužky** — kliknutím
otevřeš textové pole (max. 255 znaků, Enter uloží, Esc zruší). U ostatních zdrojů
(faktura, banka, pokladna, majetek…) je popis **uzamčen** — text „Popis se edituje na
zdrojovém dokladu" vysvětluje, že popis patří k dokladu samotnému (pokus o editaci na
serveru vždy skončí stejnou chybou, i kdyby se ji frontend nepokusil zabránit).

Úprava popisu funguje i na **už zaúčtovaném** zápisu — nejde o obcházení neměnnosti
účetnictví, protože je **auditovaná**: u zaúčtovaného zápisu se navíc zobrazí varování
„Změna zaúčtovaného zápisu je auditována (§35)" a systém uloží before/after hodnotu do
activity logu v téže databázové transakci jako samotnou změnu — nemůže tedy nastat stav,
kdy by se popis změnil, ale záznam o tom v auditní stopě chyběl. Úprava respektuje
otevřenost období (do uzavřeného období nejde zasáhnout) a je zamítnutá i u zápisu,
který je mezitím **stornovaný**.

**Optimistická konkurence.** Formulář si drží interní číslo verze zápisu (`row_version`),
které pošle spolu s uloženým textem. Pokud zápis mezitím upravil (nebo zaúčtoval znovu)
jiný uživatel, server uložení odmítne a v aplikaci se ukáže hláška **„Zápis mezitím
změnil jiný uživatel — načetl jsem aktuální stav, zkuste to prosím znovu."** Aplikace
si zároveň sama načte aktuální stav zápisu (aktuální popis i nové číslo verze) a
předvyplní jím editační pole, takže rozepsaný text se **neztratí** — porovnáš si ho
s tím, co mezitím uložil kolega, a uložení jednoduše zopakuješ.

### 45.6.2 Přílohy zápisu

Sekce **Přílohy** umožňuje k ruční nebo jinak vzniklé položce deníku připojit skutečný
doklad (sken, PDF, fotku) — nezávisle na tom, jestli má zdrojový doklad (faktura) své
vlastní přílohy v [Dokumentech](31_Dokumenty.md). Přílohy zápisu se ukládají do
vlastního úložiště, odděleného od dokumentového systému faktur. Rozpoznávají se
formáty **PDF, obrázek (JPG/PNG…), XML, ISDOC, ZFO** — ostatní podporované, ale jinak
nekategorizované typy padnou pod obecné „ostatní". Ovládání:

- tlačítko **„Přidat přílohu"** nebo přetažení souborů do vyznačené zóny (drag & drop,
  **více souborů najednou**; při vícesouborovém nahrání se každý soubor vyhodnotí
  samostatně — jedna vadná příloha v dávce nezastaví nahrání ostatních, u chybné se jen
  zobrazí důvod),
- u každé přílohy vidíš **název souboru**, **popisek** (upravitelný inline ikonou tužky,
  max. 255 znaků, „bez popisku" když není vyplněný), **velikost** a **datum nahrání**,
- ikona **stažení** a (s právem zápisu) **koš** pro smazání.

Nahrávání hlídá dva limity: jeden soubor smí mít max. **20 MiB**, součet velikostí všech
příloh jednoho zápisu smí dosáhnout max. **100 MiB** — po jeho překročení další nahrání
odmítne s hláškou o překročení celkového limitu. Typ souboru se nepozná podle přípony
ani podle toho, co pošle prohlížeč, ale **z obsahu souboru** (magic-byte/finfo detekce);
nebezpečné typy (spustitelné soubory a další zakázané přípony/MIME typy) jsou
blokované bez ohledu na to, jak se soubor jmenuje. Stejný soubor (podle otisku obsahu
— sha256, ne podle názvu) nejde k témuž zápisu nahrát dvakrát — druhý pokus skončí
hláškou o duplicitě („Tato příloha už je u zápisu evidována"), ale bajty na disku se
při první shodě jen sdílí, žádná duplicita dat nevzniká. Smazáním přílohy zmizí záznam
v deníku; samotná data na disku se smažou, jen pokud už je nesdílí jiná (stejný obsah
nahraný vícekrát se ukládá jednou, dokud ho drží alespoň jeden záznam).

Stažení přílohy vždy vynutí uložení souboru na disk (nikdy se neotevře přímo v
prohlížeči) — bezpečnostní opatření proti spuštění škodlivého obsahu z prohlížeče.

> [!NOTE]
> Role **jen pro čtení** vidí přílohy i popis zápisu, ale nemůže nic nahrávat, mazat
> ani editovat — tlačítka pro zápis se jí nezobrazí.

### 45.6.3 Poznámky k zápisu

Poznámky jsou oddělené od účetního popisu. Jeden zápis jich může mít více a
každá nese autora a čas vytvoření či poslední úpravy. Text může mít až 5 000
znaků; v detailu se načítá nejvýše 200 živých poznámek. Důležitou poznámku lze
**připnout**, takže zůstane před ostatními. Uživatel s právem zápisu může
poznámku upravit nebo ji odstranit; odstranění je auditovatelné měkké smazání,
nikoli přepis historie účetního zápisu.

> [!NOTE]
> Poznámka slouží pro pracovní vysvětlení a předání případu kolegovi. Nenahrazuje
> účetní doklad ani přílohu, která tvrzení prokazuje. Role jen pro čtení poznámky
> vidí, ale nemůže je měnit.

### 45.6.4 Vazba na doklad

Interní zápis často *souvisí* s konkrétním dokladem, aniž by byl jeho zaúčtováním —
dohadná položka k faktuře, kurzový rozdíl, přeúčtování, oprava. V rozbaleném detailu
zápisu je proto sekce **Vazba na doklad**: tlačítkem **Navázat doklad** otevřeš
našeptávač, který hledá napříč vydanými i přijatými fakturami, pokladními doklady
a bankovními pohyby (podle čísla dokladu, variabilního symbolu nebo názvu partnera).
K vazbě lze připsat **poznámku**, proč spolu doklady souvisejí.

Vazbu jde založit i rovnou při zakládání [ručního zápisu](#454-rucni-zapis) — sekce
Vazba na doklad je i ve formuláři nového zápisu a uloží se jedním krokem se zápisem.
Při akci **Kopírovat jako nový** se vazby zkopírují spolu s řádky.

Co vazba **není**: doklad se jí nezaúčtuje ani neoznačí za vyřízený. Zápis zůstává
ručním zápisem, doklad si dál vede vlastní zaúčtování a nic se nemění na kontrolách
salda ani na tom, které doklady systém považuje za nezaúčtované. Je to čistě
dohledávací informace — zato obousměrná: navázaný doklad se objeví v panelu
**Souvisí** u zápisu i naopak, včetně prokliku na doklad a na jeho zaúčtování.

Jeden zápis může být navázaný na víc dokladů a jeden doklad na víc zápisů. Vazbu
zrušíš tlačítkem **Zrušit vazbu**; zápisu ani dokladu se tím nic nestane. Pokud byl
doklad mezitím smazaný, vazba zůstane vypsaná se štítkem *Doklad neexistuje*, ať ji
máš jak uklidit.

## 45.7 Historie zápisu

Každý přepis existujícího zápisu (idempotentní re-post popsaný v
[§ 45.1](#4511-idempotence-proc-doklad-nejde-zauctovat-dvakrat)) i editace popisu
zanechává v databázi **neměnnou historickou verzi** předchozího stavu hlavičky i řádků
zápisu — jde o auditní mechanismus na úrovni databázového serveru (tzv. systémové
verzování), který běží automaticky na pozadí ke každé změně a nejde ho vypnout ani
obejít z aplikace. Slouží jako důkazní materiál pro §35 zákona o účetnictví (dohledatelnost
oprav).

V rozbaleném detailu zápisu, pod přílohami, je sekce **Historie** — kliknutím na ni se
načte a zobrazí **časová osa všech verzí** zápisu, od nejnovější:

- u každé verze vidíš **číslo verze**, badge **„aktuální"** u té poslední, **datum a čas**
  vzniku verze a (pokud se ho podařilo dohledat v auditním logu) i **kdo** ji vytvořil,
- u verze vzniklé přepisem/editací popisu je pod ní čitelný **rozdíl proti předchozí
  verzi**: která hlavičková pole se změnila (např. popis, datum dokladu) formou
  „původní hodnota → nová hodnota", a jednotlivé řádky MD/Dal označené jako **přidané**
  (zeleně), **odebrané** (červeně) nebo **změněné** (s původní i novou částkou/stranou),
- **nejstarší verze** (vznik zápisu) žádný rozdíl nemá — je to výchozí stav.

Zápis, který od svého vzniku nebyl nikdy přepsán ani nezměnil popis, má v historii jen
jednu verzi a sekce zobrazí „Zápis nebyl od vzniku upraven."

> [!NOTE]
> Spárování verze s konkrétním uživatelem/akcí v auditním logu je **orientační**
> (odvozené z časové blízkosti obou záznamů v téže databázové transakci), ne přesná
> vazba cizím klíčem — u historických verzí vzniklých mimo standardní zaúčtování/editaci
> popisu (např. na starším datovém stavu) se „kdo" nemusí dohledat.

## 45.8 Storno a oprava zaúčtovaného zápisu

Zákon o účetnictví (§35) vyžaduje, aby se do **uzavřeného** účetního období nezasahovalo
a aby oprava zaúčtovaného dokladu byla vždy dohledatelná. MyÚčto to řeší dvěma
mechanismy podle toho, zda je období, kam zápis patří, ještě **otevřené**:

- **Otevřené období — oprava přepisem.** Když opravíš údaje na zdrojovém dokladu
  (u již zaúčtované vydané faktury i administrátorským force-editem), systém **existující zápis
  k dokladu přepíše na místě** (stejné ID zápisu, nové řádky) — historii této změny
  drží auditní stopa na úrovni databáze (viz [§ 45.7](#457-historie-zapisu)),
  takže i po přepisu je dohledatelné, jak zápis vypadal předtím. Přepis odmítne
  zápis, který je **už stornovaný** — ten se opravuje jen novým zápisem.
- **Otevřené období — přímé smazání chybného zápisu.** V detailu podporovaného
  zápisu je vedle storna dostupné tlačítko **„Smazat“**. Bez protizápisu lze odstranit
  ruční zápis, zápis vydané či přijaté faktury, bankovního pohybu a poslední odpis.
  Faktura se atomicky odúčtuje (u přijaté faktury ve stavu **Zaúčtovaná** se pracovní
  stav vrátí na **Přijatá**, platební stav zůstane zachovaný); bankovní pohyb se vrátí
  mezi nezaúčtované položky, takže jej lze zkontovat znovu. Tato možnost není dostupná
  v období, které se uzavírá, je uzavřené či schválené, v části účetnictví uzamčené
  k datu ani u již stornovaného zápisu nebo jeho protizápisu. Smazání se zaznamená
  do auditního logu a databázová systémová historie uchová předchozí podobu zápisu.
- **Zaúčtovaný zápis, který přepisem opravovat nechceš (nebo nejde) — storno.**
  Tlačítko **„Stornovat"** v detailu zápisu vytvoří **zrcadlový protizápis** — stejné
  účty a částky (včetně cizoměnové stopy, pokud na originále byla), ale s
  **prohozenými stranami MD/Dal** — zaúčtovaný k aktuálnímu datu (do otevřeného
  období). Originál dostane odkaz na stornující zápis (badge **„Stornováno"** a proklik
  na stornující zápis), stornující zápis referuje originál. Zápis lze stornovat jen
  **jednou** — opakované storno hlásí „Zápis už byl stornován" (i kdyby dva požadavky
  na storno odešly současně, druhý vždy prohraje a nevznikne druhý protizápis).
  Storno je možné **jen u zaúčtovaného** zápisu (koncept se řeší jinak, viz
  [§ 45.3](#453-koncept-vs-zauctovany-zapis)) a jen do otevřeného období.
- U zápisu se zdrojem vydaná/přijatá faktura storno navíc **odemyká zdrojový doklad**
  (zruší příznak „Zaúčtováno" na faktuře), pokud k dokladu neexistuje jiný aktivní
  zaúčtovaný zápis — doklad tak můžeš opravit a zaúčtovat znovu.

### 45.8.1 Automatické storno při smazání nebo interním stornu dokladu

Storno popsané výše spouštíš ručně tlačítkem v deníku. Když ale **smažeš** nebo
**interně stornuješ** zaúčtovanou vydanou či přijatou fakturu přímo v [Fakturách](14_Faktury.md)
/ [Přijatých fakturách](23_Prijate_faktury.md), stejný mechanismus (protizápis s
prohozenými stranami) se spustí **automaticky**, ve stejné transakci jako
smazání/storno dokladu — deník tak nikdy nezůstane se zápisem k dokladu, který
už neexistuje nebo je zrušený.

Při smazání rodičovské faktury se nejdřív stornují a odpojí také aktivní zápisy
všech navázaných dokladů, které databáze smaže společně s ní (dobropisy a daňové
doklady k přijaté platbě). Selhání storna jediného potomka zastaví celé smazání.

- Protizápis dostane popis **„Storno zápisu při smazání dokladu"** (u smazání)
  nebo **„Storno zápisu při stornu dokladu"** (u interního storna), číslo
  dokladu **„STORNO {původní číslo}"**, a zaúčtuje se **do stejného období jako
  originál** — na rozdíl od ručního storna v deníku (to jde vždy do aktuálního
  otevřeného data).
- Právě proto, že se protizápis snaží zaúčtovat do období originálu: pokud je
  to období **uzavřené**, celá operace (smazání i storno dokladu) skončí chybou
  a **nic se neuloží** — ani doklad, ani zápis se nezmění. Appka to nahlásí
  srozumitelně, např. *„Fakturu nelze smazat — má zaúčtovaný zápis, který nelze
  stornovat (Období storna je „closed" — storno nelze zaúčtovat.). Nejdřív
  vyřešte zaúčtování v deníku."* (analogicky pro storno a pro přijaté faktury).
  Řešení je stejné jako jinde v této kapitole — období napřed znovu otevřít
  přes [Uzávěrku](87_Uzaverka.md), nebo doklad neuzavíráš, ale opravíš
  přeúčtováním.
- Při **smazání** se navíc na zdrojovém dokladu zruší vazba na zápis
  (`source_id`), protože samotný doklad za okamžik zmizí — v deníku po něm
  zůstane jen dvojice originál + stornující zápis jako auditní stopa.

> [!WARNING]
> Storno do **uzavřeného** účetního období nejde provést — systém vrátí chybu, že
> období je uzavřené. Opravu položek z minulého (uzavřeného) roku řeší jiný postup přes
> [Uzávěrku](87_Uzaverka.md) (znovuotevření knih do schválení závěrky), ne přímé storno
> v deníku.

> [!NOTE]
> **Force-edit zaúčtovaného dokladu v uzavřeném období (admin).** Administrátor smí
> výjimečně upravit i fakturu nebo přijatou fakturu, která je zaúčtovaná a spadá do už
> uzavřeného období (parametr `?force=1` v detailu dokladu — viz
> [Faktury](14_Faktury.md) / [Přijaté faktury](23_Prijate_faktury.md)). Taková úprava
> vždy vyžaduje **explicitní volbu**, co se má stát se zápisem v deníku — bez
> zvolení jedné z nich se úprava vůbec neuloží:
>
> - **Přeúčtovat** — původní zápis se stornuje (protizápis k dnešnímu, otevřenému datu)
>   a doklad se rovnou zaúčtuje znovu podle opravených údajů, takže deník zůstane
>   konzistentní s dokladem.
> - **Jen poznámky** — povolí uložit jen neúčetní pole (interní poznámku apod.).
>   Jakoukoli změnu částky, DPH nebo kurzu (u cizoměnového dokladu i směnného kurzu)
>   doklad v tomto režimu odmítne — rozhodila by už zaúčtovaný zápis, aniž by se
>   promítla do deníku.
>
> V otevřeném období force-edit účetních polí automaticky přepíše existující zápis
> podle opraveného dokladu; samostatná volba režimu se nevyžaduje.

## 45.9 Zámek účtování k datu

Kromě uzavření **celého** účetního období (viz [Uzávěrka](87_Uzaverka.md)) existuje i
jemnější, měkký **zámek účtování k datu** platný napříč všemi obdobími firmy —
`locked_until`. Řídí, do kterého data (včetně) už nejde nově zaúčtovat, přeúčtovat ani
stornovat žádný doklad, i když samotné účetní období je pořád formálně **otevřené**.

**K čemu slouží.** Jakmile jednou podáš přiznání k DPH za leden, nechceš, aby šlo o pár
měsíců později omylem zaúčtovat další doklad zpátky do ledna a rozhodit tak už podané
přiznání. Zámek k datu je jemnější než uzavření celého roku — běžný provoz v aktuálních
měsících funguje beze změny, chráněná je jen minulost před zamčeným datem.

**Posun po podání.** Vygenerování ani stažení přiznání k DPH nebo kontrolního hlášení
(viz [Výkazy DPH](36_Vykazy_DPH.md)) samo zámek neposouvá. Backend jej posune až při
explicitním označení validního snapshotu za skončené období jako **odeslaného**, a to
jen **dopředu** (nikdy ho automaticky nezmenší). Běžná obrazovka Archivu podání tuto
akci nenabízí, proto v UI použij po doloženém podání ruční nastavení
zámku administrátorem.

**Co uvidíš.** Pokus o zaúčtování, přeúčtování nebo storno dokladu s datem v zamčeném
rozsahu skončí chybou, že datum je zamčené. Storno zaúčtovaného zápisu, jehož datum už
je zamčené, samo o sobě projde, ale protizápis se automaticky zaúčtuje k dnešnímu
(otevřenému) datu, ne k datu originálu.

**Ruční nastavení a posun.** Zámek může nastavit, posunout dopředu i vrátit zpátky jen
**administrátor** — na stránce [**Účetnictví → Měsíční kontrola**](55_Mesicni_kontrola.md)
tlačítkem „Uzamknout k datu", vždy s
povinným písemným zdůvodněním (min. 5 znaků); změna se zaznamená do auditní stopy
(hodnota před/po + důvod). Stejná akce je dostupná i přímo přes administrátorské API
(`PUT /api/accounting/period-lock`).

## 45.10 Kontrola integrity deníku (noční job)

Kromě zámků a auditní stopy má appka i **pasivní diagnostiku** — jednou denně
(**02:30**) proběhne CLI cron `cron-journal-integrity-check`, který u každé
firmy v režimu **podvojné účetnictví** (daňová evidence se přeskakuje — deník
tam neexistuje) porovná doklady s deníkem a hledá pět typů nesrovnalostí:

| Nález | Co znamená |
|---|---|
| **Sirotčí zápisy** | Aktivní zaúčtovaný zápis, jehož zdrojový doklad (faktura/přijatá faktura) už v systému neexistuje. |
| **Nevyvážené (MD≠D)** | Součet MD a součet D na řádcích zápisu se neshodují (nad toleranci půl haléře). |
| **Zaúčtováno bez zápisu** | Doklad má nastavený příznak „Zaúčtováno", ale žádný aktivní zápis k němu v deníku neexistuje. |
| **Zápis bez zaúčtování** | Opačně — aktivní zápis existuje, ale doklad příznak „Zaúčtováno" nemá. |
| **Doklad ≠ zápis částkou** | Celková částka běžného dokladu (přepočtená na CZK) nesedí na žádný řádek zápisu v rámci tolerance. Daňový doklad k přijaté platbě se tímto pravidlem nekontroluje, protože účtuje jen DPH 324/343. |

Job je **čistě diagnostický — nic sám neopravuje**, jen zapíše počty a ukázku
nálezů (`journal_integrity_findings`, přepis při každém běhu). Výsledek vidíš
na dashboardu ([§ 10.10.2](10_Prehled.md#10102-zkontroluj-integritu-deniku)) —
karta **„Zkontroluj integritu deníku"** se zobrazí jen když job něco našel, se
závažností vždy **vysoká**. Appka nemá samostatnou stránku s výpisem
jednotlivých nálezů — karta i každý štítek rozpadu vedou zpátky na tenhle
seznam zápisů, kde nesrovnalost dohledáš ručně podle typu.

Ruční spuštění (např. hned po podezřelé opravě, bez čekání na noční běh):

```
php api/bin/cron-journal-integrity-check.php                  # spustí a uloží výsledek
php api/bin/cron-journal-integrity-check.php --dry-run         # jen vypíše, nic neuloží
php api/bin/cron-journal-integrity-check.php --supplier=12     # jen jedna firma
```

Při ručním zaúčtování přes API lze zadat jiné `entry_date` než DUZP. Pokud datum
leží v jiném kalendářním roce, zápis se vytvoří, ale odpověď vrátí varování
`entry_date_outside_document_year`; takový přesun je potřeba účetně ověřit.

## 45.11 Omezení a tipy

### 45.11.1 Kdo smí co

| Akce | Jen pro čtení | Účetní / administrátor |
|---|:---:|:---:|
| Prohlížet seznam, filtrovat, rozbalovat detail | ✓ | ✓ |
| Stahovat přílohy zápisu | ✓ | ✓ |
| Založit ruční zápis, převod 261 | — | ✓ |
| Upravit popis zápisu | — | ✓ |
| Nahrát / smazat / popsat přílohu | — | ✓ |
| Stornovat zaúčtovaný zápis | — | ✓ |
| Znovuotevřít uzavřené období (pro opravu) | — | jen administrátor |
| Nastavit/posunout zámek účtování k datu | — | jen administrátor |

- Deník je **jen pro podvojné účetnictví** — firmy na daňové evidenci modul v menu
  vůbec nevidí.
- Ruční zápis (i storno, i editace popisu, i přílohy) vyžaduje **právo zápisu** (role
  účetní/administrátor); role jen pro čtení vidí vše, ale nic needituje.
- **Σ MD = Σ Dal** je tvrdá podmínka na backendu (počítaná v haléřích, ne přes
  desetinná čísla) — frontendová kontrola je jen pomůcka, server nevyrovnaný zápis
  vždy odmítne.
- Zaúčtovat (i stornovat, i přepsat idempotentním re-postem) lze jen do **otevřeného**
  účetního období — nejdřív si ověř v [Uzávěrce](87_Uzaverka.md), že období pro dané
  datum existuje a je otevřené.
- Popis zápisu lze inline upravit jen u **ručních** zápisů a zápisů uzavření/otevření
  knih — u ostatních (faktura, banka, pokladna, majetek) se popis mění na zdrojovém
  dokladu.
- Přílohy zápisu mají per-soubor limit **20 MiB** a per-zápis limit **100 MiB**;
  duplicitní obsah se k témuž zápisu nedá nahrát dvakrát.
- Ruční zápis přes formulář vždy počítá jen v **CZK** — cizí měnu/kurz na řádku má
  jen automaticky vzniklý zápis z cizoměnové faktury.
- Filtr **Zdroj** i postranní detail ze zápisu pokrývají banku, pokladnu, faktury,
  majetek, odpisy a vypořádání. Plný detail se otevírá až odkazem ze zdrojového panelu.
- Export deníku (PDF/XLSX) respektuje aktuálně nastavené filtry a je omezený na
  max. **5 000 zápisů** v jednom exportu.
- Sekce **Historie** v detailu zápisu ukazuje časovou osu verzí s rozdílem oproti
  předchozí verzi — spárování s konkrétním uživatelem je orientační (časová blízkost
  v auditním logu), ne přesná vazba.
- Kromě otevřenosti období hlídá zaúčtování/přeúčtování/storno i **zámek k datu**
  (§ 45.9). Po doloženém podání jej nastav administrátor; backend jej umí posunout také
  při explicitním označení DPH/KH snapshotu jako odeslaného, ale běžné UI tuto akci
  nenabízí.

> [!TIP]
> Hledáš zápis ke konkrétní faktuře? Otevři fakturu, klikni na **„Zobrazit v deníku"**
> — deník se rovnou otevře s filtrem na daný doklad a zápis bude předrozbalený.
