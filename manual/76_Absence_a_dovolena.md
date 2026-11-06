# 76. Absence a dovolená

> Návod, jak zapsat a schválit dovolenou, nemoc, překážky v práci, neplacené
> volno a další nepřítomnosti, aby se správně promítly do mzdy, náhrad,
> nároku na dovolenou a do hlášení. Pro mzdové účetní a každého, kdo
> nepřítomnosti eviduje.

## 76.1 Kdy to potřebujete

Kapitolu otevřete, když:

- zaměstnanec čerpá dovolenou nebo o ni žádá,
- zaměstnanec onemocněl, je v karanténě nebo ošetřuje člena rodiny,
- zaměstnanec byl u lékaře, měl svatbu, pohřeb nebo jinou osobní překážku,
- firma nemá pro zaměstnance práci (prostoj, počasí, částečná nezaměstnanost,
  částečná práce s příspěvkem státu),
- zaměstnankyně nastupuje na mateřskou, zaměstnanec na otcovskou nebo
  rodičovskou,
- zaměstnanec má neplacené volno, náhradní volno za přesčas nebo neomluvenou
  absenci,
- soud rozhodl, že vztah trval po neplatném skončení,
- je začátek čtvrtletí a potřebujete nový průměrný výdělek pro náhrady,
- je začátek roku a potřebujete určit nárok na dovolenou.

<!-- cols: 30 40 30 -->
| Situace | Druh absence | Kde to dál pokračuje |
|---|---|---|
| Dovolená | **Dovolená** | čerpání v knize dovolené, náhrada mzdy ve mzdě |
| Nemoc, karanténa | **Dočasná pracovní neschopnost**, **Karanténa** | náhrada za prvních 14 dnů ve mzdě, delší nemoc jako případ dávky ([§ 85.6](85_Podani_a_hlaseni.md#856-krok-za-krokem-nemoc-osetrovani-materska-a-otcovska)) |
| Ošetřování člena rodiny | **Ošetřovné**, **Dlouhodobé ošetřovné** | případ dávky (NEMPRI) |
| Lékař, svatba, úmrtí v rodině a další osobní překážky | **Překážka na straně zaměstnance s náhradou mzdy** | náhrada mzdy ve mzdě |
| Osobní překážka, za kterou náhrada nepřísluší | **Překážka na straně zaměstnance bez náhrady mzdy** | jen neodpracované hodiny |
| Prostoj, počasí, částečná nezaměstnanost, částečná práce | **Překážka na straně zaměstnavatele** | náhrada mzdy ve mzdě |
| Mateřská, otcovská, rodičovská | **Peněžitá pomoc v mateřství**, **Otcovská**, **Rodičovská** | případ dávky a hlášení zdravotní pojišťovně |
| Neplacené volno, volno za přesčas, neomluvená absence | **Neplacené volno**, **Náhradní volno za přesčas**, **Neomluvená absence** | krácení mzdy, hlášení |
| Veřejná funkce bez náhrady | **Výkon veřejné funkce (volno bez náhrady)** | jen neodpracované hodiny |
| Vztah trval po neplatném skončení bez přiznané náhrady | **Trvání vztahu po neplatném skončení (bez náhrady mzdy)** | vyloučená doba v JMHZ a ELDP |
| Nic z uvedeného | **Jiná absence** | ruční posouzení |

## 76.2 Než začnete

1. **Zaměstnanec a pracovní vztah.** Založené v `Mzdy → Zaměstnanci`
   (viz [Zaměstnanci](86_Zamestnanci.md)). Nepřítomnost se vždy váže
   k pracovnímu vztahu.
2. **Rozvržené směny.** Náhrady, čerpání dovolené i krácení mzdy se počítají
   z publikovaných směn v `Mzdy → Docházka a směny` (viz
   [Docházka a směny](77_Dochazka_a_smeny.md)). Bez směn náhrada nevznikne.
3. **Schválený průměrný výdělek** pro každou nepřítomnost s náhradou mzdy
   (dovolená, nemoc v prvních 14 dnech, placené překážky). Postup
   v [§ 76.4](#764-krok-za-krokem-prumerny-vydelek-pro-nahrady).
4. **Firemní výměra dovolené** v `Mzdy → Nastavení mezd`, záložce
   **Politiky a připravenost** (pro výpočet nároku).
5. **Oprávnění** k mzdové agendě (`payroll`).

> [!TIP]
> Stránka se v aplikaci jmenuje **Absence, dovolená a DPN** a má tři záložky:
> **Absence**, **Průměrný výdělek** a **Dovolená**. Nahoře vyberete
> **Zaměstnanec**, **Pracovní vztah** a období **Od** a **Do**. Průměr a knihu
> dovolené uvidíte jen pro jednoho vybraného zaměstnance.

## 76.3 Krok za krokem: zapsat a schválit nepřítomnost

1. Otevřete `Mzdy → Absence a dovolená`, záložku **Absence**.
2. Nahoře vyberte **Zaměstnanec** a **Pracovní vztah**. Bez výběru osoby
   novou nepřítomnost založit nejde.
3. V části **Nová absence** zvolte **Druh absence** a vyplňte **Od** a **Do**.
   Pod výběrem druhu se u některých druhů zobrazí vysvětlení, co se
   s nepřítomností stane.
4. U druhu s náhradou mzdy vyberte **Schválený průměr za hodinu**. Chybí-li,
   klikněte na **Přejít na Průměrný výdělek** a průměr nejdřív založte
   ([§ 76.4](#764-krok-za-krokem-prumerny-vydelek-pro-nahrady)).
5. Začíná nebo končí nepřítomnost částí směny, vyplňte **Čerpání první den
   (hodiny)** nebo **Čerpání poslední den (hodiny)**.
6. Doplňte údaje, které formulář u daného druhu chce (druh překážky,
   očekávaný den porodu, potvrzení u nemoci) a případně **Poznámka**.
7. Klikněte na **Založit absenci**. Nepřítomnost má stav **Ke schválení**.
8. V seznamu **Evidované absence** ji zkontrolujte a klikněte na **Schválit**.
   Více nepřítomností schválíte najednou: zaškrtněte je a klikněte na
   **Hromadně schválit**.

**Jak poznáte, že je hotovo:** Nepřítomnost má stav **Schváleno**. U dovolené
přibylo čerpání v **Historii dovolené**, u nepřítomnosti s náhradou vznikl
mzdový vstup náhrady. U nemoci, ošetřovného nebo mateřské se nad seznamem
objeví zpráva o založeném případu dávky a odkaz **Otevřít případy dávek**.

> [!WARNING]
> Nepřítomnost nezadávejte zároveň v docházce i tady. Schválenou nepřítomnost
> po uzavření mzdy neměňte přímo: zrušte ji tlačítkem **Zrušit** a zapište
> znovu, aplikace pak označí navazující mzdový běh ke kontrole opravy.

## 76.4 Krok za krokem: průměrný výdělek pro náhrady

Průměrný výdělek se zakládá jednou za čtvrtletí pro každý pracovní vztah.

**Pro celou firmu najednou:**

1. Otevřete záložku **Průměrný výdělek** bez vybraného zaměstnance.
2. V části **Hromadné vypočtení průměrů za čtvrtletí** zvolte **Rok použití**
   a **Čtvrtletí**.
3. Projděte návrhy. U každého vztahu vidíte skutečný průměr nebo
   pravděpodobný výdělek se zdrojem, nebo důvod, proč návrh nevznikl.
4. Klikněte na **Vybrat připravené na stránce** a pak na **Založit a schválit
   vybrané**.

**Pro jednoho zaměstnance:**

1. Nahoře vyberte zaměstnance a pracovní vztah, otevřete záložku
   **Průměrný výdělek**.
2. V části **Nový snapshot průměrného výdělku** zkontrolujte předvyplněná
   čísla z uzavřených mzdových běhů. Poměrnou část odměn za období delší než
   čtvrtletí doplňte ručně do **Poměrná část delších odměn (Kč)**.
3. Klikněte na **Vypočítat snapshot**.
4. Zkontrolujte výsledek a klikněte na **Schválit**.

**Nový zaměstnanec** (méně než 21 odpracovaných dnů): na kartě pracovního
vztahu v `Mzdy → Zaměstnanci` klikněte na **Zadat pravděpodobný výdělek
(§ 355 ZP)**, vyplňte částku a odůvodnění a uložte. Formulář průměru ho pak
nabídne sám. Když ho nezadáte, aplikace ho navrhne podle pravidel
v [§ 76.11.1](#76111-prumerny-a-pravdepodobny-vydelek).

**Jak poznáte, že je hotovo:** Průměr má stav **Schválený snapshot** a ve
formuláři nové absence se nabízí v poli **Schválený průměr za hodinu**.

**Kdy průměr založit:** před zahájením mzdového běhu za měsíc, ve kterém se
podává měsíční hlášení. Schválený průměr se zmrazí s ostatními vstupy běhu
(pole 10345 hlášení); schválený až po výpočtu revize hlášení nevidí a oprava
stojí opravnou revizi. Kontrola před během proto upozorní **Chybí průměr pro
JMHZ** se jmény zaměstnanců a odkazem na hromadné vypočtení průměrů. Upozornění
běh nezastavuje.

Průměr jde založit jen na čtvrtletí, ve kterém pracovní vztah trval. Vztah,
který začíná po konci čtvrtletí nebo skončil před jeho začátkem, aplikace
odmítne a hromadné založení ho přeskočí. Průměr tak nevznikne omylem na jiném
vztahu téže osoby.

## 76.5 Krok za krokem: dovolená

**Na začátku roku určete nárok:**

1. Zkontrolujte firemní výměru v `Mzdy → Nastavení mezd`, záložce
   **Politiky a připravenost** (nejméně 4 týdny, obvykle 5).
2. Otevřete `Mzdy → Absence a dovolená`, záložku **Dovolená**, a zvolte
   **Rok**.
3. V části **Hromadný výpočet z ověřených podkladů** projděte vztahy. Štítek
   **Připraveno** znamená, že výpočet může proběhnout.
4. Má-li vztah štítek **Doplnit**, klikněte na něj. U jiných absencí vás
   převede do části **Posouzení jiných absencí**, kde u každého druhu
   zvolíte **Započítat** nebo **Nezapočítat**. U ostatních chybějících údajů
   otevře kartu pracovního vztahu.
5. Klikněte na **Vybrat připravené na stránce** a pak na **Spočítat vybrané**.

**Čerpání dovolené:**

1. Na záložce **Absence** zapište druh **Dovolená** a vyberte schválený průměr.
2. Klikněte na **Založit absenci** a pak na **Schválit**.
3. Nestačí-li zůstatek, aplikace se zeptá *„Poskytnout dovolenou nad rámec
   nároku?“* a ukáže nárok a zůstatek. Potvrďte tlačítkem **Poskytnout nad
   rámec nároku**, jen když to tak opravdu chcete.

**Jak poznáte, že je hotovo:** V **Historii dovolené** je položka **Nárok**
na rok a u každého schváleného čerpání položka **Čerpání**. **Zůstatek
dovolené (h:mm)** odpovídá skutečnosti.

> [!TIP]
> Výjimky (pracovní úraz, změna úvazku během roku, převod z předchozího
> programu) řešte v části **Ruční výpočet a opravy**: **Spočítat a zapsat
> nárok** nebo **Ruční položka ledgeru** a **Přidat položku**.

## 76.6 Krok za krokem: nemoc a karanténa

1. Na záložce **Absence** zapište **Dočasná pracovní neschopnost** nebo
   **Karanténa** s obdobím z potvrzení lékaře. Diagnózu nezapisujte.
2. Vyberte schválený průměr.
3. Zaškrtněte **Potvrzena účast na nemocenském pojištění** a **Vyloučen
   souběh s dávkou, která náhradu nepřipouští**.
4. Odpracoval-li zaměstnanec první plánovanou směnu celou, zaškrtněte
   **První plánovaná směna byla celá odpracována**.
5. Nemá-li zaměstnanec nárok (není účasten nemocenského pojištění),
   zaškrtněte **Zaměstnanec nemá nárok na náhradu (DPN bez nároku)**.
6. Je-li důvod náhradu snížit, zvolte v **Snížení náhrady** odpovídající
   možnost a vyplňte **Důvod snížení**.
7. Klikněte na **Založit absenci** a pak na **Schválit**. Nemoc a karanténa
   se schvalují jednotlivě, hromadně ne.
8. Prodloužení nemoci zapište jako další část, která začíná den po konci
   předchozí. Schvalujte části popořadě.

**Jak poznáte, že je hotovo:** Nemoc má stav **Schváleno**, ve mzdě je
náhrada za dny v prvních 14 kalendářních dnech. Trvá-li nemoc déle než
14 dnů, vznikl případ dávky; postup podání NEMPRI a HZUPN je v
[§ 85.6](85_Podani_a_hlaseni.md#856-krok-za-krokem-nemoc-osetrovani-materska-a-otcovska).

## 76.7 Krok za krokem: překážky v práci

1. Na záložce **Absence** zvolte **Překážka na straně zaměstnance s náhradou
   mzdy**, nebo **Překážka na straně zaměstnavatele**.
2. V **Druh překážky** vyberte konkrétní důvod. Pod ním se zobrazí, za jakých
   podmínek a v jaké výši náhrada přísluší.
3. Zkontrolujte **Náhrada mzdy (% průměrného výdělku)**. U překážek
   zaměstnavatele ji můžete zvýšit až na průměr, pak vyplňte **Důvod sazby /
   podklad** (vnitřní předpis, dohoda).
4. U **Částečná nezaměstnanost** a **Jiná placená překážka (vnitřní předpis)**
   je **Důvod sazby / podklad** povinný.
5. Vyberte schválený průměr, klikněte na **Založit absenci** a pak na
   **Schválit**.

**Jak poznáte, že je hotovo:** Ve mzdě je mzdový vstup **Náhrada mzdy při
překážkách na straně zaměstnance**, resp. **na straně zaměstnavatele**,
s hodinami z rozvržených směn. Nejsou-li na dny překážky směny, aplikace
po schválení upozorní, že náhrada nevznikla.

> [!TIP]
> Za částečnou práci, na kterou firma žádá příspěvek státu, zvolte druh
> **Částečná práce s příspěvkem státu**. Aplikace pak za zaměstnance v tom
> měsíci neuplatní slevu na pojistném.

## 76.8 Krok za krokem: mateřská, otcovská a ošetřování

1. U **Peněžitá pomoc v mateřství** vyplňte povinný **Očekávaný den porodu**.
   Začíná-li nepřítomnost až porodem nebo po něm, vyplňte rovnou **Den
   porodu**.
2. Po porodu u nepřítomnosti klikněte na **Doplnit den porodu**, zadejte datum
   a klikněte na **Uložit den porodu**. Jde to jen jednou.
3. U **Ošetřovné** zaškrtněte **Osamělý zaměstnanec pečující o dítě do
   16 let**, pokud o dítě pečuje sám.
4. Schvalte nepřítomnost. Případ dávky se založí sám; otevřete ho odkazem
   **Otevřít případy dávek** a pokračujte podle
   [§ 85.6](85_Podani_a_hlaseni.md#856-krok-za-krokem-nemoc-osetrovani-materska-a-otcovska).

**Jak poznáte, že je hotovo:** Nepřítomnost je **Schváleno**, u mateřské je
doplněný den porodu a případ dávky existuje.

> [!TIP]
> Byla-li zaměstnankyně v dřívějším měsíci převedena na jinou práci kvůli
> těhotenství, mateřství nebo kojení, nese případ dávky dvě oznámení:
> NEMPRI a **NEMPRI ke dni převedení**. Obě podáváte současně se stejnou
> lhůtou (viz [§ 85.14.21](85_Podani_a_hlaseni.md#851421-nemocenske-a-dalsi-zakonne-povinnosti)).

## 76.9 Krok za krokem: trvání vztahu po neplatném skončení

Použijte, když pravomocné rozhodnutí soudu (nebo mimosoudní dohoda po podání
žaloby) určilo, že vztah trval po neplatném skončení, a **náhrada mzdy za tu
dobu přiznána nebyla**.

1. Na záložce **Absence** zvolte **Trvání vztahu po neplatném skončení (bez
   náhrady mzdy)**.
2. Vyplňte **Od** a **Do** podle rozhodnutí. Průměr se nevybírá.
3. Klikněte na **Založit absenci** a pak na **Schválit**.

**Jak poznáte, že je hotovo:** Nepřítomnost je **Schváleno** a mzdový běh za
měsíc proběhne i bez mzdového vstupu. V měsíčním hlášení a v evidenčním
listu se doba vykáže jako vyloučená doba.

> [!WARNING]
> Byla-li náhrada mzdy přiznána, tento druh nepoužívejte. Náhradu zadejte
> jako mzdový vstup.

## 76.10 Když něco nejde

<!-- cols: 32 32 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| *„Novou nepřítomnost lze založit až po výběru konkrétního zaměstnance“* | Nahoře je vybráno **Všichni zaměstnanci** | Vyberte **Zaměstnanec** a **Pracovní vztah**. |
| *„Pro tento vztah není spočítaný žádný průměrný výdělek.“* | Chybí průměr za čtvrtletí | Klikněte na **Přejít na Průměrný výdělek** a založte ho ([§ 76.4](#764-krok-za-krokem-prumerny-vydelek-pro-nahrady)). |
| *„Průměrný výdělek … čeká na schválení.“* | Průměr je založený, ale neschválený | Na záložce **Průměrný výdělek** klikněte na **Schválit**. |
| Návrh průměru: *„Skutečný průměr z uzavřených běhů odvodit nelze a u pracovního vztahu není zadaný pravděpodobný výdělek“* | Nový vztah bez podkladů | Na kartě vztahu klikněte na **Zadat pravděpodobný výdělek (§ 355 ZP)**. |
| Návrh průměru nevznikl, chybí běh za měsíc | V rozhodném období je měsíc bez mzdového běhu, i když vztah trval | Doplňte evidenci (mzdový běh za ten měsíc). |
| *„Překážka je schválená, ale náhrada mzdy nevznikla“* | Na dny překážky nejsou rozvržené směny | Klikněte na **Rozvrhnout směny v docházce**, nebo zadejte náhradu jako mzdový vstup. |
| *„U překážky chybí druh … schválit ji nelze.“* | Překážka zapsaná bez druhu | Zrušte ji a zapište znovu s vybraným **Druh překážky**. |
| Uložení překážky odmítne chybějící podklad | U částečné nezaměstnanosti, jiné placené překážky nebo zvýšené sazby chybí **Důvod sazby / podklad** | Doplňte dohodu s odbory nebo vnitřní předpis. |
| Nemoc nejde schválit hromadně | Nemoc a karanténa potřebují potvrzení účasti na pojištění | Schvalte je jednotlivě se zaškrtnutými potvrzeními. |
| Navazující část nemoci nejde schválit | Předchozí část ještě není schválená | Schvalujte části popořadě. |
| Dovolená se zastaví dotazem na přečerpání | Zůstatek nestačí | Potvrďte **Poskytnout nad rámec nároku**, nebo zkraťte čerpání. |
| U čerpání je upozornění, že nárok dosud nebyl určen | Pro rok chybí položka **Nárok** | Spočítejte nárok ([§ 76.5](#765-krok-za-krokem-dovolena)). |
| Ruční krácení dovolené aplikace odmítne | Chybí schválená **Neomluvená absence** v témže roce, nebo krácení překračuje její hodiny | Zapište a schvalte neomluvenou absenci. |
| Výpočet nároku má štítek **Doplnit** | Chybí podmínky, docházka, nebo je potřeba posoudit jiné absence | Klikněte na štítek a doplňte, co řádek vyjmenuje. |
| Hromadné založení průměrů: *„Podklady se od načtení seznamu změnily.“* | Mezitím se změnila data | Zkontrolujte výběr a odešlete znovu. |
| Měsíc s mateřskou nejde sestavit do hlášení | Měsíc sahá na očekávaný den porodu, ale den porodu není doplněný | Klikněte na **Doplnit den porodu**. |
| Den porodu je zapsaný chybně | Den porodu jde doplnit jen jednou | Zrušte nepřítomnost a zapište ji znovu. |

## 76.11 Podrobnosti a pravidla

### 76.11.1 Průměrný a pravděpodobný výdělek

Záložka **Průměrný výdělek** vede čtvrtletní snapshot průměrného nebo
pravděpodobného hodinového výdělku. Skutečný průměr používá započitatelnou
mzdu a odpracovaný čas v rozhodném období. Formuláře zadávají částky v Kč
a čas v hodinách či dnech, na haléře a minuty je aplikace převádí až při
uložení. Stejně se v hodinách zadává částečný první nebo poslední den
absence i ruční změna dovolené. Přesný důvod chyby zůstane viditelný přímo
u formuláře.

Při méně než 21 odpracovaných dnech je povinný pravděpodobný hodinový
výdělek a jeho odůvodnění. Zadává se jednou na kartě pracovního vztahu
a uloží se do revize podmínek, takže platí, dokud ho nová revize nezmění.
Formulář průměru ho nabídne a označí, že průměr stojí na pravděpodobném
výdělku. U běžného vztahu se pole nenabízí, průměr se spočítá z uzavřených
mzdových běhů.

Není-li pravděpodobný výdělek zadaný u **nového** vztahu (všechny měsíce
rozhodného období bez mzdového běhu leží celé před nástupem), aplikace ho
navrhne podle § 355 odst. 2 zákoníku práce v tomto pořadí:

1. z hrubé mzdy dosažené od počátku rozhodného období: započitatelná mzda
   a odpracované hodiny z uzavřených mzdových běhů od nástupu, typicky
   z prvního uzavřeného měsíce;
2. ze sjednané měsíční mzdy přepočtené na hodinu koeficientem 4,348
   (týdenní pracovní doba × 4,348, § 356 odst. 2);
3. nemá-li vztah ani sjednanou mzdu, z minimální mzdy účinné v rozhodném
   období přepočtené na hodinu při 40hodinové týdenní době.

Odůvodnění návrhu uvádí zdroj i čísla. Chybí-li běh v měsíci, kdy vztah už
trval, návrh nevznikne a je potřeba doplnit evidenci. Není-li z čeho
vycházet, návrh i měsíční hlášení ČSSZ řeknou, že chybí pravděpodobný
výdělek, a odkážou na kartu vztahu. Snapshot musí projít ruční kontrolou
a schválením, teprve potom jde připojit k absenci s náhradou.

**Hromadně za firmu.** Bez vybrané osoby ukazuje záložka návrhy za zvolené
čtvrtletí pro všechny vztahy: připravené návrhy, už založené průměry
a důvody, proč u zbytku návrh nevznikl. Vybrané průměry se založí a rovnou
schválí. Změní-li se mezitím podklady, neuloží se nic a přehled se načte
znovu. Hromadně založený skutečný průměr nezohledňuje poměrnou část mzdy za
období delší než čtvrtletí (§ 358 zákoníku práce). U zaměstnanců s roční
nebo pololetní odměnou založte průměr ručně u konkrétní osoby. Průměr
z převzatých mezd předchozího programu se hromadně neschvaluje; založte ho
jednotlivě a převzatou hrubou mzdu upravte na započitatelnou (náhrady za
dovolenou nebo svátek do průměru nepatří).

**Spodní hranice.** Je-li vypočtený průměr nižší než minimální mzda, použije
se podle § 357 odst. 1 zákoníku práce minimální mzda. Hranice platí pro
skutečný i pravděpodobný výdělek a její výše se bere z legislativní sady
účinné pro rozhodné období. Ve stopě výpočtu je vidět, že se uplatnila
a z jaké hodnoty.

> [!WARNING]
> Aplikace nerozlišuje **stanovenou** kratší týdenní pracovní dobu (§ 79
> odst. 2 a 3 zákoníku práce), která hodinové minimum zvyšuje, od
> **sjednané** kratší doby podle § 80, která ho nezvyšuje. U provozu s kratší
> stanovenou týdenní dobou hodinovou hranici ověřte a případný rozdíl
> vypořádejte mimo automatický výpočet.

### 76.11.2 Dovolená: nárok, čerpání a náhrada

Nárok se vede v minutách. U DPP a DPČ výpočet používá zákonnou fiktivní
týdenní pracovní dobu 20 hodin. Započitatelné a náhradní doby, změny úvazku,
krácení a další právní okolnosti před uložením ověřte. Kniha dovolené je
historie: oprava vytváří novou položku a nic nemaže. Schválení čerpání
zapíše zápornou položku podle publikovaných směn. Zrušení schváleného
čerpání ji nemaže, vytvoří kladnou reverzi a označí absenci ke kontrole
případné opravy mzdy.

**Náhrada mzdy za dovolenou.** Za dobu čerpání přísluší podle § 222 odst. 1
zákoníku práce náhrada ve výši průměrného výdělku. Aplikace ji při schválení
založí jako mzdový vstup složky **Náhrada mzdy za dovolenou** v měsíci, do
kterého čerpání spadá; hodiny bere ze stejných směn jako kniha dovolené
a sazbu ze zmrazeného průměru. Na výplatní pásce má vlastní řádek s hodinami
i sazbou (§ 142 odst. 5 zákoníku práce) a do měsíčního hlášení jde vlastním
údajem. Ručně ji zadat nejde. Zrušení dovolené vstup nemaže, vytvoří zápornou
korekci ve **stejném** měsíci, protože oprava nároku patří období, ve kterém
nárok vznikl. Zaplacené peníze se vypořádají srážkou podle § 147 odst. 1
písm. e) zákoníku práce.

Náhrada se zdaňuje a vstupuje do vyměřovacích základů obou pojištění, ale ne
do dalšího průměrného výdělku: § 353 zjišťuje průměr z hrubé mzdy za
odpracovanou dobu. Součet zkrácené základní mzdy a náhrady se proto běžné
měsíční mzdě rovnat nemusí, náhrada vychází z výdělku minulého čtvrtletí.

**Svátek v době dovolené se nečerpá.** Připadne-li svátek na den
s rozvrženou směnou a zároveň schválenou dovolenou, směna se z čerpání
vypustí. Týden dovolené kolem Vánoc tedy nespotřebuje pět směn, jen ty, které
svátkem nejsou. Hodiny svátku se do odpracované doby dostanou právě jednou.
Zrušení je symetrické: reverze neguje přesně to, co bylo zapsáno.

Do nároku se počítá odpracovaná doba bez přesčasu. Svátek na plánovaný
pracovní den se do ní započítá, jen v rozsahu, který za týž den nebyl
započten jiným titulem.

**Přečerpání** aplikace hlídá ve třech stavech:

1. **nárok je určený a zůstatek stačí:** čerpání projde bez dotazu;
2. **nárok je určený a zůstatek nestačí:** uložení se zastaví a přečerpání
   musíte výslovně potvrdit u té jedné karty, se skutečnými čísly nároku
   a zůstatku. Nevyčerpaný přeplatek se jinak pozná až při vypořádání
   a strhne se zaměstnanci ze mzdy;
3. **nárok není určený:** aplikace se neptá a čerpání pustí s upozorněním.
   Zůstatek není nula, je neznámý.

Za určený nárok se počítá jen položka typu **Nárok**. **Převod** z minulého
roku ani ruční **Oprava** samy nestačí.

**Krácení** se řídí § 223 zákoníku práce. Aplikace vynutí zákonné minimum
podle odst. 2 a odmítne krácení dřív, než je nárok určen, i nad rámec
nároku. Podmínku dvoutýdenního zbytku hlídá jen u vztahu, který trval celý
kalendářní rok. Krátit lze jen o neomluveně zameškané hodiny (§ 223 odst. 1):
zapište je druhem **Neomluvená absence** a schvalte. Ruční položka
**Krácení** projde jen do součtu schválených neomluvených hodin v témže
roce. O tom, že jde o neomluvené zameškání, rozhoduje zaměstnavatel (§ 348
odst. 3); aplikace ho z jiných druhů nepřítomnosti neodvozuje.

**Čerpání před zahájením vedení mezd** (měsíce převzaté z předchozího
programu) zapíšete ruční položkou se zápornými hodinami a doložením původu.
Od prvního mzdového období v MyÚčtu vzniká čerpání jen schválením
nepřítomnosti.

### 76.11.3 Hromadný výpočet nároku

Výměra musí mít nejméně 4 týdny, běžně 5. Odlišný pracovní vztah má výjimku
na své kartě; bez ní platí firemní výměra. Odkaz na zdroj je volitelná
auditní poznámka.

Vztahy se na záložce **Dovolená** načítají po stránkách, takže postup
funguje pro deset i stovky zaměstnanců. Aplikace pro každý vztah spojí
účinné smluvní podmínky, sjednanou týdenní dobu, firemní politiku
a schválené měsíce docházky. Bez vašeho výběru se nic nezapisuje. Jedna
mzdová účetní může výběr i zápis dokončit sama.

Výpočet se zastaví jen tehdy, když chybí skutečný právní údaj, během roku
se změnila výměra nebo pracovní doba, jiná absence vyžaduje posouzení
započitatelnosti, nebo chybí schválený měsíc docházky. Existující nárok
automat nepřepíše. Při uložení znovu ověří otisk podkladů; změněná data
vyžadují obnovení přehledu. Uložená revize uchová politiku, smluvní
podmínky, schválenou docházku i výpočetní stopu.

**Posouzení jiných absencí.** Rozhodnutí **Započítat** / **Nezapočítat**
(§ 216 odst. 2 a § 348 odst. 1 zákoníku práce) se dělá jednou za druh
absence a zapíše se do zdůvodnění nároku. Pracovní neschopnost a karanténa
se při započtení omezí na 20násobek týdenní pracovní doby za rok. Pracovní
úraz nebo nemoc z povolání se započítávají celé, spočítejte je ručně v části
**Ruční výpočet a opravy**.

**Firma převedená z jiného programu.** Absence z měsíců před prvním mzdovým
obdobím v MyÚčtu se znovu neposuzují. Vztah, kterému převod zapsal zůstatek
dovolené roku, má štítek **Převzato**: zůstatek už obsahuje nárok po krácení
a čerpání, takže se nárok toho roku znovu nepočítá. Od dalšího roku počítá
nárok MyÚčto.

### 76.11.4 Placené překážky v práci a náhrada mzdy

Podle **Druh překážky** aplikace určí sazbu náhrady z průměrného výdělku:

<!-- cols: 46 26 28 -->
| Druh | Předpis | Náhrada |
|---|---|---|
| Lékař, svatba, úmrtí, doprovod, převoz k porodu, pohřeb spoluzaměstnance, stěhování v zájmu zaměstnavatele, hledání zaměstnání po výpovědi z organizačních důvodů, znemožnění cesty těžce postiženého | § 199 ZP, NV č. 590/2006 Sb. | 100 % |
| Darování krve, činnost v odborech a radě zaměstnanců, školení potřebné pro práci | § 203 odst. 2, § 205 ZP | 100 % |
| Jiná placená překážka podle vnitřního předpisu | vnitřní předpis | 100 %, podklad povinný |
| Prostoj | § 207 písm. a) ZP | nejméně 80 % |
| Přerušení práce kvůli počasí nebo živelní události | § 207 písm. b) ZP | nejméně 60 % |
| Jiná překážka na straně zaměstnavatele | § 208 ZP | 100 % |
| Částečná nezaměstnanost | § 209 ZP | nejméně 60 %, podklad povinný |
| Částečná práce s příspěvkem státu | § 120a a násl. zákona o zaměstnanosti | nejméně 80 % |

U překážek zaměstnance je sazba pevná. U překážek zaměstnavatele ji lze
zvýšit až na průměr, jen s důvodem. U částečné nezaměstnanosti se vždy uvádí
dohoda s odborovou organizací nebo vnitřní předpis (§ 209 odst. 2), bez něj
jde o jinou překážku se 100 % průměru. Body nařízení vlády bez náhrady
(účast u porodu, svatba rodiče, dalších pět dnů po úmrtí, stěhování bez
zájmu zaměstnavatele…) zapisujte jako **Překážka na straně zaměstnance bez
náhrady mzdy**.

**Částečná práce s příspěvkem státu.** Jde o částečnou práci, na kterou
zaměstnavatel žádá příspěvek (§ 120a a násl. zákona o zaměstnanosti).
Zaměstnanec je tím v měsíčním přehledu nákladů na náhrady mezd, takže za
něj v měsíci s touto překážkou nenáleží sleva na pojistném (§ 7a odst. 3
písm. e) zákona č. 589/1992 Sb.).

Schválením překážky vznikne mzdový vstup na složce **Náhrada mzdy při
překážkách na straně zaměstnance**, resp. **na straně zaměstnavatele**.
Hodiny se berou ze směn, o které se krátí základní mzda; svátek se
nenahrazuje (mzda se za něj nekrátí). Na výplatní pásce má náhrada vlastní
řádek. V měsíčním hlášení jde do náhrad za překážky na straně zaměstnance
(10341), resp. zaměstnavatele (10340), hodiny do 10471, resp. 10472, a mezi
neodpracované hodiny s náhradou (10276). Zrušení překážky náhradu nemaže,
vytvoří zápornou korekci ve stejném měsíci.

Překážku zapsanou bez druhu schválit nejde. Nepřítomnosti převzaté
z předchozího programu druh nemají a náhradu nezakládají, tu už obsahuje
převzatá mzda.

**Doplatek do minima zdravotního pojištění.** Sníží-li překážka na straně
zaměstnavatele se sníženou náhradou (prostoj, počasí, částečná
nezaměstnanost, částečná práce) vyměřovací základ pod minimum, doplatek
hradí zaměstnavatel (§ 3 odst. 10 zákona č. 592/1992 Sb.). Mzdový běh to
pozná ze schválené překážky sám, není-li v měsíční evidenci zdravotního
minima zaměstnance zapsáno jinak. Je-li v témže měsíci i neplacené volno,
neomluvená absence nebo jiná neplacená nepřítomnost a doplatek vznikne, běh
se zastaví s kontrolou; plátce doplatku za měsíc určete v zákonné evidenci
na kartě zaměstnance. Tam se evidují i výjimky z minima, například péče
o dítě do 7 let potvrzená zdravotní pojišťovnou (viz
[Zaměstnanci, § 86.12.3](86_Zamestnanci.md#86123-zakonna-evidence-osoby)).

### 76.11.5 Pracovní volno bez náhrady mzdy

Bez náhrady mzdy eviduje agenda tři druhy:

- **Výkon veřejné funkce (volno bez náhrady)** podle § 200 až 202 zákoníku
  práce,
- **Překážka na straně zaměstnance bez náhrady mzdy**,
- **Trvání vztahu po neplatném skončení (bez náhrady mzdy)**.

Zapisují se bez průměrného výdělku a po schválení nevznikne mzdový vstup.
Z odpracované doby se vyjmou jako neplacené volno. V měsíčním hlášení jdou
jejich hodiny jen do celkového počtu neodpracovaných hodin (10275) v bloku
neplaceného volna, ne mezi překážky s náhradou (10471).

Veřejná funkce a překážka bez náhrady se v evidenčním listu chovají jako
neplacené volno: vyloučenou dobou nejsou, celé dny se vykážou jako vyloučené
dny pro nemocenské dávky a měsíc bez započitatelného příjmu se za dobu
pojištění nepovažuje. Přiznává-li zákon za konkrétní veřejnou funkci náhradu
mzdy, zadejte ji jako mzdový vstup; výpočet takové náhrady aplikace nedělá.

**Trvání vztahu po neplatném skončení** je doba, po kterou podle
pravomocného rozhodnutí soudu (nebo mimosoudní dohody po podání žaloby)
vztah trval po neplatném skončení a náhrada mzdy za ni přiznána nebyla. Mzda
za ni nenáleží. V měsíčním hlášení (10536) i v evidenčním listu je
**vyloučenou dobou** podle § 16 odst. 4 písm. j) zákona č. 155/1995 Sb.,
měsíc přitom zůstává dobou pojištění. Neodpracované hodiny jsou u ní
nepovinné (například člen orgánu bez pracovní doby je nemá); jsou-li, jdou
do bloku neplaceného volna. Mzdový běh za měsíc s touto nepřítomností
proběhne i bez mzdového vstupu. Po dosažení důchodového věku (sekce
s kódem D) příprava hlášení takový měsíc zastaví, protože odečítané doby pro
tuto vyloučenou dobu údaj nemají. Byla-li náhrada mzdy přiznána, tento druh nepoužívejte a náhradu
zadejte jako mzdový vstup.

### 76.11.6 Náhrada mzdy při nemoci a karanténě

Náhrada se počítá z publikovaných směn v prvních 14 kalendářních dnech.
**Svátek uvnitř okna se proplácí i bez publikované směny:** aplikace pro něj
dopočítá směnu podle rozvrhu. Je-li směna na svátek publikovaná, nechá ji
být, takže k dvojímu proplacení nedojde. Odpracoval-li zaměstnanec první
plánovanou směnu celou, okno začíná následujícím dnem. Výsledek uchovává
průměr, redukční hranice, pravidla, zaokrouhlení a rozpad po směnách.
Diagnóza se v agendě neeviduje; zdravotní údaje zpřístupněte jen
oprávněným osobám.

**Neschopnost zapsaná po částech** (po měsících nebo prodloužením) je jedna
neschopnost s jedním čtrnáctidenním oknem. Část, která začíná den po konci
předchozí části téhož druhu, dostane při schválení jen zbytek okna; dny
vyčerpané předchozími částmi se zapíšou do **Dnů okna náhrady vyčerpaných
před touto částí**. Za dny za oknem náhrada nevzniká, zaměstnanec dostává
nemocenské. Navazující část počítá z průměru a pravidel první části, i když
začíná v dalším čtvrtletí, a odpracovaný první den se u ní nepotvrzuje.
Navazující část nejde schválit dřív než předchozí a předchozí nejde zrušit,
dokud na ni navazuje schválená část. Ručně (**Upravit dny okna náhrady**)
zapisujte vyčerpané dny jen u předchozího zaměstnavatele nebo
v předchozím mzdovém programu.

**DPN bez nároku.** Náhrada patří jen zaměstnanci účastnému nemocenského
pojištění (§ 192 odst. 1 ZP). U DPP a zaměstnání malého rozsahu rozhoduje
účast v měsíci, kdy neschopnost vznikla (§ 15a zákona č. 187/2006 Sb.).
Neschopnost bez nároku se schválí bez průměru a bez náhrady: mzda se za její
dny krátí jako za neplacenou dobu, mzdový vstup ani případ nemocenské
nevznikne a evidenční list ji vykáže jako neschopnost bez nároku.
Nezaškrtnuté potvrzení účasti schválení nepustí.

**Snížení náhrady.** Vznikla-li neschopnost v případech § 31 zákona
č. 187/2006 Sb. (rvačka, opilost, návykové látky, úmyslný trestný čin nebo
přestupek), zvolte **Na polovinu (§ 192 odst. 4 ZP)**. Při porušení režimu
v prvních 14 dnech můžete zvolit **Porušení režimu (§ 192 odst. 5 ZP)**
a náhradu snížit o procento (100 % znamená neposkytnout) nebo o částku v Kč.
Částkou jde snižovat jen náhradu, která leží v jednom kalendářním měsíci.
Důvod je povinný. Snížení se počítá z přesné náhrady před zaokrouhlením
a výpočet uchová i původní výši.

Náhrada se počítá nejvýš do dne skončení vztahu a nejdřív ode dne nástupu.
Trvá-li neschopnost déle než vztah, schválení na to upozorní.

### 76.11.7 Potvrzení pracovní doby pro JMHZ

Při schválení měsíce v `Mzdy → Docházka a směny` se samostatně potvrzuje
pracovní jádro JMHZ: stanovený a sjednaný měsíční fond, stanovená týdenní
doba, evidenční dny a skutečně odpracované hodiny. Nabídnuté hodnoty jsou
dohledatelný podklad; potvrďte je jako přesná desetinná čísla. Poznámku
k ověření můžete nechat prázdnou. Aplikace nezaokrouhluje minuty ani
nedopočítá chybějící profesní fond. Potvrzený souhrn je neměnný a navázaný
na revizi schváleného měsíce; po znovuotevření je nutné nové potvrzení.

Evidenční dny aplikace u pracovního poměru sníží o dny mateřské, rodičovské
a otcovské, protože zaměstnanec po tu dobu není v evidenčním stavu (výklad
MPSV i ČSÚ). Měsíční fondy zůstávají plné; nemoc ani neplacené volno
evidenční dny nesnižují. U jednatele, společníka a člena orgánu (druh
činnosti K a N až S) chtějí pokyny MPSV stanovený i sjednaný fond nulový;
aplikace oba navrhne jako 0. Hodnotu ze smlouvy o výkonu funkce, která
pracovní dobu sjednává, můžete přepsat.

Součástí potvrzení jsou dvě povinná rozhodnutí **Ano/Ne**: zda v měsíci
nastaly neodpracované hodiny (IN07) a zda nastaly překážky v práci (IN08).
Žádná odpověď se nepředvyplní jako **Ne**. Při IN07 se uvádí celkový rozsah
a případně placené hodiny, DPN s náhradou nebo bez ní, dovolená a péče.
Placené hodiny zahrnují všechny neodpracované hodiny s náhradou mzdy,
i DPN v prvních 14 dnech; z nich se počítá i sleva na pojistném podle § 7a.
V hlášení se ale hodiny DPN s náhradou mezi hodinami s náhradou (10276)
neuvádějí a vykážou se jako překážka na straně zaměstnance (10471), jak
chtějí pokyny MPSV. Převod udělá aplikace sama; při potvrzení hodiny
nepřesouvejte a u měsíce jen s nemocí odpovězte na otázku o překážkách
**Ne**. Při IN08 musí být uvedena aspoň jedna hodnota překážek na straně
zaměstnance nebo zaměstnavatele. Kategorie se mohou překrývat, jejich součet
se proto nemusí rovnat celkovým neodpracovaným hodinám. Evidence absencí je
podklad k ruční kontrole, ne automatická právní klasifikace. Nevyřízená
absence nebo čekající oprava schválení měsíce blokuje. Schválená placená
dovolená projde běžným profilem JMHZ jen tehdy, když souhlasí
s publikovanými směnami a potvrzený souhrn ji vykazuje celou jako placené
neodpracované hodiny.

**Svátky** patří do fondu i do neodpracovaných hodin. Aplikace navrhuje fond
včetně svátků na pracovní dny a při potvrzení sama přičte hodiny svátků,
ve kterých zaměstnanec nepracoval ani nečerpal jinou nepřítomnost,
k celkovým i placeným neodpracovaným hodinám (u DPP, DPČ a statutárů ne).
Dialog schválení ukazuje jejich počet, do polí je sami nepřidávejte. Na slevu
podle § 7a to nemá vliv.

Běžným profilem JMHZ projdou i **otcovská, rodičovská, neplacené volno,
neomluvená absence a překážky v práci**. Otcovská je vyloučenou dobou v celé
podpůrčí době; ostatní čtyři vyloučenou dobu netvoří a dobu pojištění
nekrátí, takže evidenční list za ně vykáže plný měsíc bez vyloučených dnů.
Potvrzení pracovní doby se u nich ptá navíc na hodiny, ale jen u druhu,
který v měsíci opravdu je. Do pole neplaceného volna patří i hodiny veřejné
funkce, překážky bez náhrady a trvání vztahu po neplatném skončení.

**Náhradní volno za přesčas.** Mzda za dobu čerpání nepřísluší (§ 114
odst. 1 zákoníku práce), hodiny jdou jen do celkového počtu neodpracovaných
hodin. Podle pokynů MPSV se v měsíci čerpání hodiny volna odečtou od
vykázaného přesčasu (nejvýše do nuly) i od odpracovaných hodin; v aplikaci
zůstávají odpracované hodiny beze změny, převod udělá až hlášení. Vyloučenou
dobou evidenčního listu není, celé dny se vykazují jako vyloučené dny pro
nemocenské dávky. Platí to pro měsíce, jejichž pracovní doba se potvrdí po
zavedení této podpory; dřív potvrzený měsíc znovu otevřete a potvrďte.

### 76.11.8 Měsíc bez započitatelného příjmu

Měsíc, ve kterém rodičovská, neplacené volno, pracovní volno bez náhrady
mzdy nebo neomluvená absence nenechaly žádný započitatelný příjem, se podle
§ 11 odst. 2 zákona č. 155/1995 Sb. za dobu pojištění nepovažuje. Hlášení ho
vykáže s kódem činnosti, nulou dnů pojištění a nulovým vyměřovacím základem.
Dny neplaceného volna i rodičovské uvede jako vyloučené dny pro nemocenské
dávky; u rodičovské i v měsíci, kdy začala nebo skončila. Roční evidenční
list takový měsíc započítá stejně, s nulou dnů pojištění.

Celý měsíc nemoci nebo ošetřovného je naopak omluvný důvod, takže zůstává
dobou pojištění s plným počtem dnů a nulovým základem. Stačí jediný omluvný
důvod: přibude-li k neplacenému volnu nemoc, ošetřovné nebo doba před
porodem, je celý měsíc dobou pojištění a vyloučenou dobou jsou jen dny
omluvného důvodu (Metodická pomůcka ČSSZ k vyplňování ELDP, příklad 5).
Neplacené volno se dál uvede jako vyloučené dny pro nemocenské dávky.

Do mzdových vstupů se za takový měsíc nic nezadává, ani nulová mzda. Běh
vztah spočítá i bez mzdové složky, pokud v měsíci leží schválená
nepřítomnost bez náhrady od zaměstnavatele (neplacené volno, pracovní volno
bez náhrady mzdy, trvání vztahu po neplatném skončení, rodičovská,
neomluvená absence, peněžitá pomoc v mateřství, otcovská, nemoc nebo
ošetřovné, náhradní volno). Varování *„pracovní vztah nemá v období žádnou
schválenou mzdovou složku“* se pak jen zobrazí a nevyžaduje potvrzení
výjimky. Bez takové nepřítomnosti je potvrzení výjimky povinné, protože jde
nejspíš o zapomenutou mzdu.

Zablokovaný zůstává měsíc bez příjmu, ve kterém se **omluvná nepřítomnost
potkala s nepřítomností bez příjmu**: nemoc nebo měsíc porodu s neplaceným
volnem či rodičovskou. Zákon rozhoduje jen o celém měsíci, takový měsíc
vyřiďte ručně mimo aplikaci.

### 76.11.9 Mateřská, otcovská a ošetřovné

Očekávaný den porodu je u peněžité pomoci v mateřství povinný. Den porodu se
doplňuje jen jednou, i u schválené nepřítomnosti, protože vstupuje do
podaných hlášení.

Vyloučenou dobou evidenčního listu je jen část od začátku osmého týdne před
očekávaným dnem porodu do dne před porodem. Den porodu a doba po něm
vyloučenou dobou nejsou. Měsíc porodu bez započitatelného příjmu se vykáže
jako měsíc pojištění s plným počtem dnů a nulovým základem, protože jeho
část před porodem je omluvná. Každý další měsíc po porodu bez
započitatelného příjmu se podle § 11 odst. 2 zákona č. 155/1995 Sb. vykáže
s nulou dnů pojištění. Dny peněžité pomoci i otcovské jdou do hlášení jako
vyloučené dny s vyplacenou dávkou. Měsíc, který sahá na očekávaný den porodu
nebo za něj, se bez doplněného dne porodu nesestaví.

Před porodem nelze na peněžitou pomoc nastoupit dřív než od začátku osmého
týdne před očekávaným dnem porodu. Začíná-li nepřítomnost porodem nebo po
něm (předčasný porod, převzetí dítěte do péče, druhá část podpůrčí doby
zapsaná zvlášť), vyplňte den porodu rovnou při zápisu.

**Převedení na jinou práci.** Byla-li zaměstnankyně převedena kvůli
těhotenství, mateřství nebo kojení, může se rozhodné období určit ke dni
převedení, je-li to pro ni výhodnější (§ 19 odst. 6 zákona č. 187/2006 Sb.).
Leží-li den převedení v dřívějším měsíci, než vznikla sociální událost, nese
případ dávky dvě oznámení: NEMPRI s rozhodným obdobím ke dni vzniku události
a **NEMPRI ke dni převedení**. Obě se podávají současně a se stejnou lhůtou,
výhodnější období vybere ČSSZ. Ve stejném měsíci by obě nesla totéž období,
takže druhé oznámení nevzniká. Postup je v
[§ 85.14.21](85_Podani_a_hlaseni.md#851421-nemocenske-a-dalsi-zakonne-povinnosti).

**Ošetřovné.** Osamělému zaměstnanci pečujícímu o dítě do 16 let náleží až
16 kalendářních dnů místo 9 (§ 40 odst. 1 zákona č. 187/2006 Sb.) a podle
toho měsíční hlášení rozdělí dny ošetřování na dny s dávkou (10475) a dny
omluvené nepřítomnosti bez náhrady (10473); vyloučenou dobu ošetřování
vykáže v 10360. U dlouhodobého ošetřovného je podpůrčí doba 90 dnů.

### 76.11.10 Stavy, kontroly a omezení

Nepřítomnost má stav **Ke schválení**, **Schváleno**, **Zamítnuto** nebo
**Zrušeno**. Do mzdy se zařadí podle dat a kalendáře, ne podle dne vložení
záznamu. Aplikace hlídá překryvy nepřítomností, směny, svátky, čerpání
nároku a návaznost částí nemoci. Odkaz na podklad je volitelná auditní
stopa; skutečné vstupy jsou druh a interval.

Časté chyby: záměna kalendářních dnů za pracovní dny nebo hodiny, dvojí
zadání téže nepřítomnosti v docházce i v absencích, změna události po
uzavření mzdy bez řízené opravy.

Agenda je označena **Vyžaduje ruční kontrolu**. Bez schváleného průměru,
publikovaného rozvrhu nebo potvrzených zákonných podmínek výpočet bezpečně
selže; chybějící údaj systém neodhaduje. Výpočty náhrad a dovolené jsou
dostupné pro legislativní sady roku **2025 a 2026**; pro rok 2027 sada
zatím neexistuje (viz
[Legislativní pravidla mezd](92_Legislativni_pravidla_mezd.md#9281-ktere-roky-jsou-pokryte)).

> [!WARNING]
> Zacházení se svátkem v dovolené a v okně náhrady při nemoci a spodní
> hranice průměrného výdělku se zpětně nepřepočítávají. Dovolená čerpaná
> přes svátek a náhrada při nemoci se svátkem v 14denním okně zpracované
> před zavedením těchto pravidel mohou být nadspotřebované nebo
> podplacené. Dotčené případy projděte a vypořádejte ručně po jednotlivých
> zaměstnancích.

## 76.12 Související kapitoly

- [Docházka a směny](77_Dochazka_a_smeny.md): rozvrh směn a schválení měsíce.
- [Mzdové běhy](80_Mzdove_behy.md): kam se náhrady promítnou.
- [Dokumenty a výstupy](83_Dokumenty_a_vystupy.md): výplatní pásky a evidenční listy.
- [Podání a hlášení](85_Podani_a_hlaseni.md): NEMPRI, HZUPN a měsíční hlášení.
- [Zaměstnanci](86_Zamestnanci.md): pravděpodobný výdělek, výjimky z minima ZP.
- [Nastavení mezd](90_Nastaveni_mezd.md): výměra dovolené a politiky.
- [Legislativní pravidla mezd](92_Legislativni_pravidla_mezd.md): pokryté roky.
