# 86. Zaměstnanci

> Návod, jak v MyÚčtu založit zaměstnance, vést jeho osobní kartu, zákonnou
> evidenci a pracovní vztahy a jak vztah ukončit. Pro mzdové účetní
> a personalisty.

## 86.1 Kdy to potřebujete

Kapitolu otevřete, když:

- nastupuje nový zaměstnanec, nebo stávající uzavírá další dohodu či smlouvu,
- zaměstnanec podepsal (nebo odvolal) prohlášení poplatníka, uplatňuje děti
  nebo slevu na dani,
- se mění mzda, úvazek, místo výkonu práce nebo jiná podmínka vztahu,
- přišly identifikátory od ČSSZ (OIČ a ID PPV) nebo výzva ČSSZ,
- zaměstnanec žádá o potvrzení o zdanitelných příjmech nebo o době pojištění,
- zaměstnanec odchází, nenastoupil nebo zemřel,
- chcete uložit smlouvu nebo jiný dokument do personálního spisu.

<!-- cols: 30 40 30 -->
| Situace | Co udělat | Kde v aplikaci |
|---|---|---|
| Nástup | Založit zaměstnance a vztah, doplnit zákonnou evidenci, přihlásit na ČSSZ a ZP | **Přidat zaměstnance**, postup v [§ 86.3](#863-krok-za-krokem-novy-zamestnanec) |
| Prohlášení, rezidence, pojišťovna, důchod | Zapsat do zákonné evidence osoby | sekce **Zákonná evidence osoby**, [§ 86.4](#864-krok-za-krokem-zakonna-evidence-osoby) |
| Dítě, daňové zvýhodnění | Zapsat vyživovanou osobu a uplatnění | **Vyživované osoby a daňové zvýhodnění**, [§ 86.5](#865-krok-za-krokem-deti-a-danove-zvyhodneni) |
| Změna mzdy, úvazku, podmínek | Uložit novou verzi podmínek vztahu | karta vztahu, [§ 86.6](#866-krok-za-krokem-zmena-mzdy-uvazku-nebo-podminek-vztahu) |
| Protokol ČSSZ s OIČ a ID PPV | Opsat identifikátory | **Identifikátory přidělené ČSSZ pro JMHZ**, [§ 86.7](#867-krok-za-krokem-identifikatory-od-cssz-oic-a-id-ppv) |
| Výzva ČSSZ nebo žádost zaměstnance | Zapsat ji, aby termín hlídal přehled termínů | [§ 86.8](#868-krok-za-krokem-vyzvy-a-zadosti) |
| Odchod | Ukončit vztah, vyrovnat dovolenou, odstupné, odhlásit | **Skončení vztahu**, [§ 86.9](#869-krok-za-krokem-skonceni-vztahu) |

## 86.2 Než začnete

1. **Oprávnění.** K seznamu zaměstnanců stačí oprávnění ke mzdám. Zakládat
   a měnit osoby potřebujete oprávnění **Spravovat zaměstnance**
   (`payroll.person.write`), pracovní vztahy oprávnění k zápisu pracovních
   vztahů (`payroll.employment.write`). Celé rodné číslo a další citlivé
   hodnoty zobrazí jen oprávnění k citlivým údajům.
2. **Nastavení mezd.** Mějte založenou aspoň jednu mzdovou účtárnu a výchozí
   zdravotní pojišťovnu (`Mzdy → Nastavení mezd`, záložka **Zaměstnavatel
   a účtárny**, viz [Nastavení mezd](90_Nastaveni_mezd.md)).
3. **Podklady.** Připravte jen údaje, které potřebujete pro mzdu, daň,
   pojištění, výplatu, dokumenty a podání, a ověřte je z oprávněného podkladu
   (smlouva, prohlášení, průkaz pojištěnce, protokol ČSSZ).

### 86.2.1 Jak je agenda uspořádaná

Agenda odděluje **osobu** (kartu zaměstnance) od **pracovního vztahu**. Jedna
osoba může mít víc vztahů, například pracovní poměr a souběžnou DPP. Duplicitní
kartu pro tutéž osobu nezakládejte, přidejte jí další vztah.

`Mzdy → Zaměstnanci` ukazuje seznam. Po otevření osoby je karta seřazená shora
dolů:

<!-- cols: 34 66 -->
| Část karty | Co v ní je |
|---|---|
| Hlavička | Jméno, stav, počet vztahů, štítek chybějících údajů, **Zpět na seznam** a akce (**Přidat pracovní vztah**, **Smazat zaměstnance**) |
| Souhrn chybějících údajů | Co chybí a jestli to brání podání; **Doplnit →** skočí na místo opravy |
| **Běžné údaje zaměstnance** | Jméno, rodné číslo, bydliště, kontakt, osobní číslo, pracovní doba, mzda |
| **Zákonná evidence osoby** | Prohlášení k dani, rezidence, slevy, sociální a zdravotní pojištění, důchod, výjimky z minima |
| **Úplná osobní evidence a historie** (sbalená) | Historie jmen, adres, kontaktů a identifikátorů, výplatní účty, **Vyživované osoby a daňové zvýhodnění**, **Pobytová a pracovní oprávnění**, **Žádosti o potvrzení o zdanitelných příjmech**, **Výzvy a žádosti v důchodovém pojištění** |
| **Pracovní vztahy** | Karta každého vztahu: podmínky, registrace na ČSSZ, identifikátory, skončení, dokumenty, navazující agendy |

## 86.3 Krok za krokem: nový zaměstnanec

1. Otevřete `Mzdy → Zaměstnanci` a klikněte na **Přidat zaměstnance**
   (rychle také přes nabídku **Nový zaměstnanec**).
2. Vyplňte jméno a příjmení, druh vztahu a plánovaný nástup. Jen tato pole
   mají hvězdičku. **Osobní číslo** je nepovinné, bez něj ho aplikace přidělí
   sama.
3. Rozbalte **Další údaje** a vyplňte, co máte: rodné číslo, datum narození,
   základní mzdu, týdenní pracovní dobu, mzdovou účtárnu a zdravotní
   pojišťovnu.
4. Odpovězte na tři otázky pro ČSSZ (příspěvek od úřadu práce, funkční
   požitky, dočasné přidělení). Předvybrané **Ne** platí pro většinu firem.
5. Klikněte na **Uložit**. Vznikne karta osoby i první pracovní vztah a karta se
   otevře k doplnění.
6. Projděte souhrn chybějících údajů nahoře a u každé položky klikněte na
   **Doplnit →**. Typicky jde o adresu, daňovou rezidenci, prohlášení
   poplatníka, ověřenou pojišťovnu a výplatní účet.
7. V **Zákonné evidenci osoby** doplňte zbytek (postup v
   [§ 86.4](#864-krok-za-krokem-zakonna-evidence-osoby)). U běžného českého
   zaměstnance stačí tlačítko **Doplnit běžné údaje (rezident ČR)**.
8. Na kartě vztahu zkontrolujte **Druh činnosti**, případně **Bližší určení
   pracovněprávního vztahu**, a místo výkonu práce v **Další údaje**.
9. Rozbalte **Úplná osobní evidence a historie** a založte výplatní účet.
   Zvolte **Na účet** a zaškrtněte **Účet mám ověřený - ověření zapsat při
   uložení karty**.
10. Dole na kartě klikněte na **Uložit vše**.
11. Přihlaste zaměstnance na ČSSZ a u zdravotní pojišťovny podle
    [§ 85.5](85_Podani_a_hlaseni.md#855-krok-za-krokem-nastup-zamestnance).
12. V den nástupu klikněte na kartě vztahu na **Potvrdit nástup**.

**Jak poznáte, že je hotovo:** V seznamu je u osoby akce **Otevřít kartu**
(karta je připravená) a souhrn chybějících údajů hlásí, že nic nechybí. Vztah
má stav **Aktivní** a v checklistu nástupu jsou registrace splněné.

> [!TIP]
> Víc lidí najednou doplníte tlačítky nad seznamem: **Doplnit výchozí zákonné
> údaje**, **Zdravotní pojišťovny hromadně** a **Doplnit místo výkonu práce**.

## 86.4 Krok za krokem: zákonná evidence osoby

Zákonná evidence nese právní skutečnosti, ze kterých vychází výpočet daně
a pojistného. Vede se po celých měsících.

1. Na kartě osoby najděte sekci **Zákonná evidence osoby**. Pole **Ke kterému
   dni** určuje měsíc, který se kontroluje; hlavička ukáže počet chybějících
   údajů.
2. Chybí-li všechno, klikněte na **Doplnit běžné údaje (rezident ČR)**. Náhled
   ukáže, co se doplní a od kterého měsíce. Potvrďte ho.
3. U sekce, kterou měníte, klikněte na **Upravit** a pak na **Přidat záznam**.
   Nový záznam je předvyplněný běžným českým případem.
4. Vyplňte hodnotu a **Platí od** (první den měsíce). Odkaz na podklad je
   nepovinný.
5. Sekci uložte tlačítkem **Uložit** u sekce, nebo vše najednou lištou
   **Uložit vše** dole na kartě.
6. Prohlášení poplatníka zapište v sekci **Prohlášení poplatníka k dani** jako
   **Podepsáno** nebo **Nepodepsáno**. Doplnit běžné údaje ho nezapíše.

Podle situace doplňte i další sekce:

<!-- cols: 36 64 -->
| Situace zaměstnance | Sekce |
|---|---|
| Uplatňuje slevu na invaliditu nebo ZTP/P | **Slevy na dani (§ 35ba)** |
| Pracuje v cizím režimu sociálního pojištění (formulář A1) | **Příslušnost k sociálnímu pojištění** |
| Pobírá starobní důchod a uplatňuje slevu na pojistném | **Sleva pracujícího důchodce** |
| Dosáhl důchodového věku nebo pobírá důchod | **Důchodový věk** a **Pobíraný důchod** |
| Minimum zdravotního pojištění se na něj nevztahuje (státní pojištěnec, ZTP, péče o dítě do 7 let…) | **Výjimky z minima zdravotního pojištění** |
| Má souběžné zaměstnání jinde | **Vyměřovací základ u jiného zaměstnavatele** a **Měsíční evidence zdravotního minima** |

**Jak poznáte, že je hotovo:** Sekce hlásí „Evidence je k tomuto měsíci
úplná" a štítek v hlavičce ukazuje **vše doplněno**.

> [!WARNING]
> Bez prohlášení, rezidence, sociálního a zdravotního pojištění mzdový běh
> zákonný výpočet osoby nespočítá a pošle ji do ručního posouzení. Podrobnosti
> v [§ 86.12.3](#86123-zakonna-evidence-osoby).

## 86.5 Krok za krokem: děti a daňové zvýhodnění

1. Na kartě osoby rozbalte **Úplná osobní evidence a historie** a najděte sekci
   **Vyživované osoby a daňové zvýhodnění**.
2. Klikněte na **Přidat vyživovanou osobu**. Vyplňte vztah, jméno a příjmení
   zvlášť, datum narození, **Vyživovaná od** (den narození, osvojení, převzetí
   do péče nebo zahájení studia) a případně **Vyživovaná do**. U dítěte
   s průkazem vyplňte i **Průkaz ZTP/P přiznán od**.
3. Založte uplatnění: kdo zvýhodnění uplatňuje, pořadí dítěte v domácnosti,
   důvod a stav ověření, **Nárok od (první den měsíce)**. Formulář datum
   předvyplní podle pravidel § 35c.
4. Odpovězte na otázku, zda děti v domácnosti vyživuje i jiná osoba. Při
   odpovědi „ano" doplňte její jméno, příjmení a datum narození.
5. Dítě, které uplatňuje druhý rodič, zapište také a zvolte **Jiná osoba
   v domácnosti (pořadí N)**. Drží pořadí ostatních dětí.
6. Uložte lištou **Uložit vše**.

**Jak poznáte, že je hotovo:** U uplatnění svítí „Nárok je doložený a lze jej
uplatnit." Prohlášení poplatníka musí být v daném měsíci **Podepsáno**.

## 86.6 Krok za krokem: změna mzdy, úvazku nebo podmínek vztahu

**Mzda nebo pracovní doba** (rychle, u hlavního vztahu):

1. V **Běžných údajích zaměstnance** klikněte na **Upravit**.
2. Změňte **Pravidelná hrubá mzda** nebo **Týdenní pracovní doba** a vyplňte
   **Platí od**.
3. Klikněte na **Uložit vše**. Předchozí hodnota zůstane v historii.

**Ostatní podmínky vztahu** (úvazek, účtárna, druh činnosti, režim pojištění,
místo výkonu práce):

1. Otevřete kartu pracovního vztahu a upravte pole v části **Základní údaje**,
   případně ve sbalené části **Další údaje**.
2. Vyplňte **Účinnost podmínek od**. Nová verze může začít nejdřív den po
   začátku poslední verze. Pole **Důvod změny** je nepovinné.
3. Klikněte na **Uložit vše**.

**Jak poznáte, že je hotovo:** V časové ose vztahu přibude nová verze
podmínek a předchozí je uzavřená dnem před novou účinností.

> [!TIP]
> Změna mzdy ani úvazku se ČSSZ nehlásí, projeví se v nejbližším měsíčním
> hlášení. Změnu adresy, jména nebo pojišťovny ale ohlásíte do 8 dnů, viz
> [§ 85.8](85_Podani_a_hlaseni.md#858-krok-za-krokem-zmena-udaju-zamestnance-a3).

## 86.7 Krok za krokem: identifikátory od ČSSZ (OIČ a ID PPV)

1. Otevřete kartu pracovního vztahu a rozbalte sekci **Identifikátory
   přidělené ČSSZ pro JMHZ**.
2. Zvolte **Prostředí** (produkce, nebo testovací prostředí).
3. Vyplňte **OIČ / IK MPSV osoby** (přesně 10 číslic) a **ID PPV pracovního
   vztahu** (nejvýš 22 číslic) a **Platí od**.
4. Zaškrtněte potvrzení, že jste hodnoty ověřili v podkladu ČSSZ.
5. Klikněte na **Uložit**.

**Jak poznáte, že je hotovo:** Sekce ukáže uložené hodnoty jen jako masku
a v souhrnu chybějících údajů zmizí „Doplnit OIČ" a „Doplnit ID PPV".

Identifikátory z protokolu ČSSZ zapisuje aplikace sama, jakmile přijde
protokol k registraci odeslané z MyÚčta. Pro víc lidí najednou použijte import
**OIČ z POHODY** nebo import registrací (viz [Nastavení mezd](90_Nastaveni_mezd.md#9010-krok-za-krokem-import-zamestnancu-z-jmhz-a-registraci)).

## 86.8 Krok za krokem: výzvy a žádosti

**Žádost o potvrzení o zdanitelných příjmech** (vystavit do 10 dnů):

1. Na kartě osoby rozbalte **Úplná osobní evidence a historie**, sekci
   **Žádosti o potvrzení o zdanitelných příjmech**.
2. Vyplňte **Den žádosti**, **Rok příjmů** a případně poznámku a klikněte na
   **Zapsat žádost**.
3. Potvrzení vystavte v `Mzdy → Dokumenty a výstupy` (tlačítko **Vystavit
   potvrzení** vás tam přenese). Předáte-li ho jinak, klikněte na
   **Vyřízeno mimo aplikaci**.

**Výzva ČSSZ nebo žádost v důchodovém pojištění:**

1. Ve stejné části otevřete sekci **Výzvy a žádosti v důchodovém pojištění**.
2. Zvolte **Druh** (evidenční list, oprava měsíčního hlášení, potvrzení
   o době pojištění, potvrzení o náhradách, potvrzení podle znění do
   31. 12. 2025) a **Kdo vyzval nebo požádal**.
3. Vyplňte **Den doručení** a podle druhu **Pracovní vztah**, **Rok** nebo
   **Měsíc hlášení**. U výzvy k evidenčnímu listu za rok od 2027 vyplňte
   **Lhůta uvedená ve výzvě**, po úmrtí **Datum úmrtí**.
4. Klikněte na **Zapsat výzvu nebo žádost**. Termín se objeví v přehledu
   termínů.
5. Vyřiďte ji: u evidenčního listu klikněte na **Sestavit evidenční list**
   (postup v [§ 85.10](85_Podani_a_hlaseni.md#8510-krok-za-krokem-evidencni-list-duchodoveho-pojisteni)),
   u potvrzení o době pojištění na **Stáhnout potvrzení (PDF)**.
6. Předání stejnopisu zapište tlačítkem **Stejnopis předán zaměstnanci**
   (u potvrzení o hornictví **Stejnopis předložen ČSSZ**) a výzvu uzavřete
   tlačítkem **Vyřízeno**.

**Jak poznáte, že je hotovo:** Žádost má stav **Vyřízeno** s datem a termín
zmizí z přehledu termínů. U evidenčního listu postupuje stav přes **Evidenční
list připraven** a **Evidenční list podán** až po **Evidenční list přijat**.

## 86.9 Krok za krokem: skončení vztahu

1. Uzavřete poslední mzdové období. Odložený příjem potvrďte až po ukončení
   (krok 7).
2. Na kartě vztahu klikněte na **Ukončit**, zvolte datum skončení a potvrďte.
3. V sekci **Skončení vztahu** vyberte **Způsob skončení**, případně **Zákonný
   důvod**, a klikněte na **Uložit skončení**.
4. Vyrovnejte dovolenou: **Proplatit nevyčerpanou dovolenou**, nebo **Srazit
   přečerpanou dovolenou**.
5. Při výpovědi nebo dohodě z organizačních důvodů vyplňte **Počet násobků
   průměru pro srážky** a klikněte na **Založit odstupné do posledního běhu**.
6. V části **Dokumenty při skončení vztahu** vytvořte zápočtový list
   a potvrzení pro Úřad práce.
7. Vyplácíte-li odměnu v dalším měsíci, potvrďte ji v části **Odložený příjem
   po skončení vztahu**.
8. Odhlaste zaměstnance (A2 a HOZ) podle
   [§ 85.7](85_Podani_a_hlaseni.md#857-krok-za-krokem-odchod-zamestnance).
   Odhláška A2 se předvyplní tlačítkem **Předvyplnit ze skončení vztahu**.
9. Běží-li exekuce, oznamte skončení soudu nebo exekutorovi do týdne (viz
   [Srážky a exekuce](88_Srazky_a_exekuce.md#887-krok-za-krokem-skonceni-pracovniho-pomeru-povinneho)).

**Jak poznáte, že je hotovo:** Vztah má stav **Skončený**, položky výstupního
checklistu jsou splněné a odhláška A2 je přijatá.

> [!WARNING]
> Vztah nejdřív **ukončete** a teprve potom případně archivujte. Archivací
> neukončeného vztahu by z přehledu zmizela odhláška ze zdravotního pojištění,
> kterou je stále nutné podat.

Nenastoupil-li zaměstnanec vůbec, klikněte na **Označit nenástup** a podejte
storno A8 nebo ukončení předregistrace P2.

## 86.10 Krok za krokem: personální spis

1. Na kartě vztahu klikněte v **Navazujících agendách** na **Personální spis**.
2. Klikněte na **Vybrat soubory**, nebo soubory přetáhněte do vyznačené plochy.
3. U dokumentu vyplňte **Druh dokumentu**, **Název**, **Datum dokumentu**,
   případně **Platnost do** a poznámku. Klikněte na **Nahrát**.
4. Poznámku k zaměstnanci napište do pole **Nová poznámka k zaměstnanci…**
   a klikněte na **Přidat poznámku**. Důležitou poznámku připněte
   (**Připnout nahoru**).

**Jak poznáte, že je hotovo:** Dokument je v seznamu **Dokumenty** se jménem
toho, kdo ho nahrál, a s datem.

## 86.11 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Mzdový běh pošle osobu do ručního posouzení | Chybí prohlášení, rezidence, sociální nebo zdravotní pojištění k danému měsíci | V **Zákonné evidenci osoby** doplňte údaje, které hlavička vypisuje ([§ 86.4](#864-krok-za-krokem-zakonna-evidence-osoby)). |
| Uložení evidence odmítne díru v řadě | Záznamy jedné řady musí navazovat den po dni | Upravte **Platí od** nebo **Platí do** sousedních záznamů. |
| Řádek evidence má štítek **Uzavřeno schválenou mzdou** | Záznam začal před koncem posledního schváleného období | Klikněte na **Změnit od …**, nebo když se změna musí projevit zpětně, na **Otevřít mzdu k opravě**. |
| Založení zaměstnance odmítne pojišťovnu | Neznámý kód zdravotní pojišťovny | Vyberte pojišťovnu ze seznamu. |
| Na kartě vztahu: „Vztah nemá mzdovou účtárnu." | Převzatý vztah bez účtárny | Vyberte **Mzdová účtárna** v **Základních údajích** a uložte. |
| **Ověřit účet** je nedostupné | Na kartě je neuložená změna účtu | Nejdřív kartu uložte, pak účet ověřte. |
| Měsíční hlášení se zastaví na dočasném přidělení | Chybí uživatel, ke kterému je zaměstnanec přidělen | Na kartě vztahu doplňte IČO, nebo zahraniční osobu ([§ 86.12.10](#861210-evidence-pro-cssz-druh-cinnosti-a-vykonavana-pozice)). |
| Hlášení se zastaví na kategorizaci rizika | U záchranáře nebo hasiče podniku chybí volba | Vyplňte **Kategorizace rizika pro JMHZ** ve **Výjimečných situacích**. |
| Mzdový běh odmítne příjem po skončení | Odložený příjem není potvrzený, nebo jde o dohodu či zaměstnání malého rozsahu | Potvrďte ho v **Odložený příjem po skončení vztahu**; u dohod podejte hlášení ručně přes ePortál ([§ 86.12.17](#861217-skonceni-vztahu)). |
| Zaměstnance nejde smazat | Má neprázdný personální spis | Nejdřív spis vyprázdněte. |
| Formulář se neuloží kvůli souběžné změně | Vztah mezitím změnil jiný uživatel | Načtěte aktuální verzi a změnu zopakujte. |
| Sekce Skončení vztahu hlásí, že chybí průměr nebo nárok dovolené („Absence a průměry") | Za čtvrtletí skončení není schválený průměr, nebo kniha dovolené nemá nárok | Otevřete `Mzdy → Absence a dovolená`, záložku **Průměrný výdělek** nebo **Dovolená**. |

## 86.12 Podrobnosti a pravidla

### 86.12.1 Seznam a karta zaměstnance

V `Mzdy → Zaměstnanci` jsou tytéž karty jako ve spodní části Mzdové
rekapitulace. Změna jména nebo aktivního stavu v původní agendě se týká téže
osoby; slučovat duplicitní karty není potřeba. **Přidat zaměstnance** zakládá
právě tuto společnou kartu, ne druhou osobu jen pro úplné mzdy. Formulář se
otevře místo seznamu.

**Osobní číslo** patří k pracovnímu vztahu, takže osoba se dvěma vztahy má dvě.
Nevyplněné přidělí aplikace sama (`ZAM-…`). Smí obsahovat písmena, číslice
a znaky `.` `_` `/` `-`, nejvýš 64 znaků. V seznamech se zobrazuje u jména jako
„os. č. …“. Smazat ho nejde, jen změnit; změna samotného osobního čísla
nezakládá novou verzi podmínek.

Hvězdička u popisku znamená, že bez pole uložení neprojde. Při zakládání jsou
takové jen jméno, druh vztahu a plánovaný nástup. Rodné číslo je potřeba až
u přihlášky na ČSSZ (stačí i EČP) a u oznámení zdravotní pojišťovně (nahradí ho
jen číslo pojištěnce ZP, EČP ne). Zaměstnance bez českého rodného čísla lze
založit bez náhradní hodnoty.

Týdenní pracovní dobu zadejte skutečnou hned při založení, aby první interval
podmínek odpovídal realitě. U dohod (DPP, DPČ), společníka a člena
statutárního orgánu se stanovená týdenní doba do měsíčního hlášení neuvádí:
podle pokynů MPSV tam patří hodnota 99 a potvrzení pracovní doby ji předvyplní
i vyžaduje. Automatický nárok dovolené sjednanou dobu převezme.

Mzdová účtárna se při zakládání nabízí jen firmě s víc aktivními účtárnami,
ostatním ji aplikace dosadí z výchozí účtárny. Zdravotní pojišťovna se
předvyplní výchozí pojišťovnou z nastavení a zapíše se do zákonné evidence
osoby k datu nástupu týmž uložením, takže nemůže vzniknout karta bez ní. Neznámý
kód pojišťovny celé založení odmítne.

Toolbar nad seznamem hledá podle jména nebo osobního čísla (stačí jeho část)
a přepíná zobrazení **Aktivní**, **Všichni**, **Mám doplnit údaje** a **Brání
podání**. Hledání i stránkování běží na serveru po 25 osobách, takže se stejně
pracuje s deseti i pěti sty zaměstnanci. Na telefonu se tabulka mění na karty.

Seznam ukazuje stav osoby, další krok k dokončení karty, počet a druh vztahů
a původní vztah převzatý z Mzdové rekapitulace. Akce v řádku odpovídá
dalšímu kroku (například **Doplnit adresu**, **Doplnit OIČ**, **Vybrat druh
činnosti**); u hotové karty je **Otevřít kartu**.

**Jedno Uložit pro celou kartu.** Sekce karty (běžné údaje, zákonná evidence,
úplná osobní evidence, vyživované osoby, pobytová oprávnění, podmínky vztahů
a profil REGZEC A1) nemají vlastní uložení celé karty. Po změně se dole objeví
lišta s neuloženými změnami, která vyjmenuje rozepsané sekce, a **Uložit vše**
je uloží postupně. Když uložení některé sekce neprojde, lišta se u ní zastaví
a ukáže důvod; zbylé sekce zůstanou neuložené a nic se neztratí. **Zahodit
změny** vrátí všechny sekce do uloženého stavu. Zavření karty, přepnutí na jinou
osobu, sbalení úplné evidence i odchod ze stránky se na neuložené změny zeptají.
Technická verze záznamu chrání před přepsáním souběžné změny.

### 86.12.2 Běžné údaje, identita a výplatní účet

V **Běžných údajích zaměstnance** se nejdřív zobrazí čtecí souhrn, editor se
otevře tlačítkem **Upravit**. Upravíte jméno a příjmení (zadávají se zvlášť,
systém je z celého jména neodhaduje), rodné číslo, bydliště, e-mail, telefon,
osobní číslo, týdenní pracovní dobu a pravidelnou hrubou mzdu. Stát bydliště se
vybírá ze společného číselníku zemí; při jeho výpadku lze zadat dvoupísmenný
ISO kód.

Změna jména, bydliště, kontaktu, pracovní doby nebo mzdy historii nemaže:
starší záznam uzavře a založí novou účinnou verzi. Záznam založený tentýž den
lze opravit na místě. Uzavřená historická adresa se nemění. Je-li uložené rodné
příjmení, nová verze jména ho převezme na serveru bez odkrytí. Osobní profil
a primární vztah se ukládají jednou transakcí.

**Citlivé údaje.** Rodné číslo se v seznamu nezobrazuje. Jinde jsou z masky
vidět jen **poslední dvě číslice** (se čtyřmi šlo rodné číslo dopočítat
z data narození a pohlaví). Celou hodnotu odkryje jen samostatná oprávněná akce
**Zobrazit údaje**, která se zaznamenává; bez oprávnění je tlačítko neaktivní
a vysvětlí proč. U citlivých údajů zadávejte novou hodnotu, jen když ji měníte;
po uložení ji aplikace z formuláře odstraní.

**Další identifikátory.** EČP, VČP, zahraniční identifikátor a **číslo
pojištěnce ZP** se vedou samostatně a úplná evidence drží jejich historii.
Číslo pojištěnce ZP vyplňte, jen když ho pojišťovna přidělila odlišně od
rodného čísla nebo zaměstnanec rodné číslo nemá (typicky cizinec po prvním
přihlášení); opište ho z průkazu pojištěnce nebo z oznámení pojišťovny. EČP je
evidenční číslo ČSSZ, číslem pojištěnce zdravotní pojišťovny není.

**Údaje pro registraci zaměstnance.** Každá verze jména má sbalenou část
s titulem před a za jménem, datem a místem narození, státem narození, státním
občanstvím a pohlavím pro registrační formulář ČSSZ. Údaje platí od data
**Platí od** nad nimi, takže registrace použije verzi účinnou k datu nástupu.
Dřívější příjmení aplikace skládá z dřívějších verzí jména (bez aktuálního
a rodného); při změně příjmení proto přidejte novou verzi jména, starou
nepřepisujte. U osoby narozené mimo ČR vyplňte stát narození, PREZEC ho píše za
název obce.

**Výplatní účet** má název, období účinnosti a rozdělení výplaty; podíly účtů
a hotovosti jsou v procentech a dávají dohromady 100 %. Přepnutí na **Na účet**
vynuluje hotovost a přepne pravidlo „zbytek čisté mzdy" na účet. Nový účet jde
v pravidle vybrat ještě před uložením. Zaškrtnutím **Účet mám ověřený -
ověření zapsat při uložení karty** se při uložení zapíše ověření s druhem
podkladu a datem. Uložený účet ověříte tlačítkem **Ověřit účet**. Při neuložené
změně účtu je ověření zablokované, aby se neověřila předchozí hodnota pod nově
zobrazenými údaji. Každá pozdější změna čísla účtu, účinnosti nebo aktivního
stavu ověření zneplatní.

### 86.12.3 Zákonná evidence osoby

Sekce **Zákonná evidence osoby** vede:

- **Prohlášení poplatníka k dani** (viz [§ 86.12.4](#86124-prohlaseni-k-dani-ma-jedine-misto)),
- **Daňová rezidence**: rezident, nerezident se zemí, nebo neověřeno,
- **Slevy na dani (§ 35ba)**: na poplatníka, na invaliditu I. a II. stupně, na
  invaliditu III. stupně a na držitele ZTP/P. Uplatní se jen s podepsaným
  prohlášením; bez záznamu se sleva neodečte. Každý druh je vlastní řada,
  takže mohou platit souběžně. Sleva na poplatníka se odvodí z podepsaného
  prohlášení,
- **Příslušnost k sociálnímu pojištění** včetně formuláře A1,
- **Sleva pracujícího důchodce** (sleva na pojistném),
- **Příslušnost ke zdravotnímu pojištění** a zdravotní pojišťovna,
- **Měsíční evidence zdravotního minima**,
- **Výjimky z minima zdravotního pojištění**,
- **Vyměřovací základ u jiného zaměstnavatele**,
- **Důchodový věk** a **Pobíraný důchod**.

Chybí-li prohlášení, rezidence, sociální nebo zdravotní příslušnost nebo
zdravotní pojišťovna, mzdový běh zákonný výpočet osoby nespočítá a skončí
v ručním posouzení. Sekce proto ukazuje počet chybějících údajů a vyjmenuje je
k měsíci zvolenému v **Ke kterému dni**. Čtení stačí obecné oprávnění ke mzdám,
zápis vyžaduje **Spravovat zaměstnance**; evidence je vedená na osobě, ne na
vztahu.

**Výchozí hodnoty.** **Přidat záznam** předvyplní běžný český případ: daňový
rezident ČR, český sociální i zdravotní režim, A1 se netýká, sleva pracujícího
důchodce se neuplatňuje, pojišťovna je ta, u které je osoba vedená (jinak
výchozí z nastavení mezd). **Doplnit běžné údaje (rezident ČR)** otevře hromadné
doplnění zúžené na osobu: doplní záznamy od měsíce nástupu (nejdřív po období
uzavřeném schválenou mzdou) a osobu s cizím prvkem nebo bez pojišťovny vyloučí
i s důvodem. Prohlášení se tím jako podepsané nezapíše. Sleva pracujícího
důchodce je nepovinná; záznam potřebuje jen zaměstnanec, který ji uplatnil.

Co evidence odvodí, na to se neptá: u českého daňového rezidenta je stát vždy
ČR, u českého sociálního režimu je A1 „netýká se". Tato pole se objeví až po
přepnutí na cizí režim, kdy evidence vyžádá stát ze seznamu. Stát i pojišťovna
se vždy vybírají ze seznamu.

**Odkaz na podklad je všude volitelný.** Lze vybrat typický podklad, nebo přes
**Jiné** zapsat číslo dokladu (písmena, číslice a `.`, `:`, `/`, `_`, `-`).
Prázdné pole uložení, výpočet ani podání neblokuje a aplikace žádný odkaz
nevymýšlí. Ověřené hodnoty jsou rozhodnutím uživatele, který za správnost
odpovídá. Varianta **Neověřeno** se ukládá jako důvod ručního posouzení.

**Příslušnost k sociálnímu pojištění a A1.** Podle ní se počítá pojistné. A1
musí platit po celou dobu zahraniční příslušnosti v měsíci; končí-li dřív, mzda
osoby se zastaví, dokud nedoplníte nové A1 nebo nezapíšete od dalšího dne
českou příslušnost. Účast „zahraniční", stát cizích předpisů a platnost A1
v podmínkách vztahu musí odpovídat, jinak výpočet ohlásí rozpor.

#### Měsíce, navazování a uzavřená historie

Evidence se zadává **po celých měsících** (kromě výjimek z minima, které mají
přesný den) a záznamy jedné řady musí navazovat den po dni. Čte se k prvnímu
dni měsíce, takže změna uprostřed měsíce by se ztratila nebo by vznikly dvě
platné verze. Díra v řadě se odmítne už při uložení.

Záznam, který začal před koncem posledního schváleného mzdového období, je
uzavřený: nejde posunout jeho začátek ani ho smazat. Věcná změna se zapíše jako
nový záznam od dalšího měsíce a původní se ukončí posledním uzavřeným dnem.
Doplnit chybějící záznam do uzavřeného období jde. Uzamčený řádek nabízí dvě
akce:

- **Změnit od …** s prvním dnem dalšího měsíce ukončí platný záznam na hranici
  zmrazení a založí jeho novou verzi.
- **Otevřít mzdu k opravě** spustí korekční tok a otevře **všechny** běhy, které
  hranici drží (určuje ji nejpozdější z nich). Nabídne se jen tam, kde ho server
  přijme a kde na to máte oprávnění; do historie běhu se zapíše důvod „Oprava
  zákonné evidence osoby".

Panel nad historií vždy ukazuje, do kterého dne je historie uzavřená.

#### Sdělení zdravotní pojišťovny zaměstnancem

Zaměstnanec sděluje zaměstnavateli pojišťovnu při nástupu a změnu do osmi dnů;
zaměstnavatel přijetí písemně potvrdí (§ 12 písm. b) zákona č. 48/1997 Sb.).
U každé zapsané pojišťovny je blok **Sdělení zdravotní pojišťovny
zaměstnancem**: zapište den sdělení a den potvrzení a tlačítkem **Potvrzení
(PDF)** vytiskněte potvrzení k podpisu. Potvrzení jde vytvořit až po zápisu dne
sdělení; nese den potvrzení, jinak dnešní datum. Obě data se ukládají
samostatně tlačítkem **Uložit sdělení** a obsah hromadného oznámení nemění.

#### Měsíční evidence zdravotního minima

Evidence je **nepovinná**. Bez záznamu platí zákonný stav podle § 3 odst. 10
zákona č. 592/1992 Sb.: doplatek do minimálního vyměřovacího základu hradí
zaměstnanec. Výjimkou je měsíc se schválenou překážkou na straně zaměstnavatele
se sníženou náhradou (prostoj, počasí, částečná nezaměstnanost); tehdy hradí
doplatek zaměstnavatel a běh to odvodí sám. Záznam zakládejte jen tehdy, když
je skutečnost jiná: doplatek jde k tíži zaměstnavatele z důvodu, který evidence
nepřítomností nezná; v měsíci je vedle překážky zaměstnavatele i neplacená
nepřítomnost (běh se zastaví a zeptá); nebo si zaměstnanec při souběhu zvolil
pro doplatek jiného zaměstnavatele. Rozklad pojistného u schválené mzdy ukáže,
jestli hodnota vznikla zápisem, nebo ze zákona. **Neověřeno** znamená ruční
posouzení.

Minimum se krátí samo podle schválených nepřítomností. O dny nemoci,
karantény, ošetřování člena rodiny a dlouhodobého ošetřovného se poměrně
snižuje (§ 3 odst. 9 písm. b) zákona č. 592/1992 Sb.). Za dny peněžité pomoci
v mateřství a rodičovské dovolené platí pojistné stát (§ 7 odst. 1 písm. d)
zákona č. 48/1997 Sb.), takže se za ně minimum nepoužije; trvá-li to celý měsíc,
doplatek nevzniká. Neplacené volno ani neomluvená absence minimum nesnižují
a doplatek za ně hradí zaměstnanec. Otcovská poporodní péče minimum také
nesnižuje: zákon ji v § 3 odst. 8 a 9 nejmenuje a za jejího příjemce stát
pojistné neplatí.

#### Výjimky z minima zdravotního pojištění

Na některé osoby se minimální vyměřovací základ nevztahuje (§ 3 odst. 8 zákona
č. 592/1992 Sb.). Doplatek za ně nevzniká, proto výjimku zaevidujte:

<!-- cols: 34 66 -->
| Důvod výjimky | Kdy platí |
|---|---|
| **Státní pojištěnec** | za osobu platí pojistné i stát, např. poživatel důchodu, student, příjemce rodičovského příspěvku, uchazeč o zaměstnání, osoba pečující o závislou osobu |
| **Držitel průkazu ZTP nebo ZTP/P** | osoba s těžkým tělesným, smyslovým nebo mentálním postižením s průkazem |
| **Důchodový věk bez nároku na důchod** | dosáhla důchodového věku, ale nesplňuje další podmínky pro starobní důchod |
| **OSVČ platí zálohy alespoň z minima** | vedle zaměstnání odvádí zálohy aspoň z minima pro OSVČ; jen za celý měsíc |
| **Jen odměna pěstouna** | osoba je pouze příjemcem odměny pěstouna; jen za celý měsíc |
| **Péče o dítě do 7 let (potvrzená pojišťovnou)** | zaměstnanec osobně a řádně pečuje aspoň o jedno dítě do 7 let (§ 7 odst. 1 písm. k) zákona č. 48/1997 Sb., od 1. 1. 2026); platí ode dne uvedeného v oznámení pojišťovně, nejdřív den po jeho doručení, dokladem je potvrzení pojišťovny |
| **Nemoc, karanténa nebo ošetřování** | jen když nepřítomnost není vedená v aplikaci |

U výjimky zadáte důvod, **Platí od**, případně **Platí do** a nepovinně doklad
(rozhodnutí o důchodu, průkaz, potvrzení pojišťovny) s poznámkou. Výjimka se
zadává **s přesným dnem**: začne-li nebo skončí během měsíce, minimum se sníží
poměrně podle kalendářních dnů (§ 3 odst. 9 písm. c)). Výjimky OSVČ a pěstouna
musí trvat celý měsíc, jinak výpočet ohlásí nález k posouzení. Každý důvod je
samostatná řada, takže například ZTP/P a státní pojištěnec mohou platit
současně; překryv téhož důvodu se odmítne. **Neověřeno** jde uložit jako
rozpracovaný stav, zůstane ale chybějícím údajem a výpočet osobu pošle do
ručního posouzení.

Výpočet sám odvodí: doloženou slevu pracujícího důchodce (§ 7d zákona
č. 589/1992 Sb. náleží jen poživateli starobního důchodu, za kterého platí
pojistné i stát), doloženou slevu na dani pro držitele ZTP/P (dokládá průkaz)
a nemoc, karanténu, ošetřování, mateřskou a rodičovskou ze schválených
nepřítomností. Sleva na invaliditu výjimkou není, protože se přiznává i tomu,
komu invalidní důchod nevznikl; poživatele invalidního důchodu zadejte jako
státního pojištěnce ručně.

#### Vyměřovací základ u jiného zaměstnavatele

Při souběžném zaměstnání se minimum posuzuje z **úhrnu** vyměřovacích základů
(§ 3 odst. 10 zákona č. 592/1992 Sb.). V sekci zadáte za měsíc označení
zaměstnavatele (písmena bez diakritiky, číslice a `.`, `:`, `/`, `_`, `-`, např.
`zamestnavatel:firma-b`), jeho vyměřovací základ v korunách a od kdy (případně
do kdy) tam zaměstnání trvá. Kdo doplatek odvádí, určíte volbou **Zvolený
zaměstnavatel** v měsíční evidenci zdravotního minima; nabídka obsahuje
zaměstnavatele zapsané za týž měsíc i ty přidané ve stejné úpravě.

#### Doplatek u jednatele s nízkou odměnou

Minimum platí pro každého zaměstnance, tedy i pro jednatele nebo člena orgánu
s odměnou pod minimální mzdou a bez podepsaného prohlášení. Některé programy
doplatek u orgánů nepočítají; MyÚčto ho počítá, protože ho zákon ukládá.
Rozklad pojistného u běhu vysvětlí, proč doplatek vznikl, a tlačítkem **Zadat
výjimku z minima** otevře kartu osoby přímo v této sekci. Vztahuje-li se na
jednatele výjimka (například pobírá důchod), zaevidujte ji a běh přepočítejte.

#### Důchodový věk a pobíraný důchod

Z obou sekcí čte kód `D` a odečítané doby evidenční list i měsíční hlášení JMHZ
(podrobně v [§ 85.14.22](85_Podani_a_hlaseni.md#851422-evidencni-list-duchodoveho-pojisteni)).
Obě jsou nepovinné a výpočet mzdy je nečte, takže je nezamyká ani schválená
mzda: den jde opravit i zpětně. Hlášení a listy, které už vznikly, se tím
nemění.

- **Důchodový věk** je jediný záznam: den, kdy zaměstnanec dosáhl nebo dosáhne
  důchodového věku, a podle čeho (tabulka zákona, sdělení ČSSZ, prohlášení
  zaměstnance). Vychází-li den z data narození a pohlaví jednoznačně podle § 32
  a přílohy č. 1 zákona č. 155/1995 Sb., sekce ho nabídne a předvyplní. U žen
  narozených do roku 1972 závisí na počtu vychovaných dětí, které evidence
  nevede, a snížený důchodový věk (např. u horníků) výpočet nezná; tam den
  zapište sami. Bez záznamu kód `D` nevznikne.
- **Pobíraný důchod** se vede od-do po dnech s druhem podle číselníku ČSSZ
  (**Starobní**, **Invalidní 3. stupně**, **Invalidní 1. nebo 2. stupně**, cizí
  důchody charakteru starobního nebo invalidního), stejně jako v přihlášce
  REGZEC. U starobního se označí, zda je **předčasný**, u každého případný
  **snížený důchodový věk**. Každý druh je vlastní řada, takže cizí a český
  důchod mohou běžet souběžně; překryv téhož druhu se odmítne.

Předčasný starobní důchod dává kód `D` od dne přiznání. Starobní důchod ukončuje
odečítané doby od prvního celého měsíce výplaty (přiznaný uprostřed měsíce od
dalšího měsíce) a od roku 2025 i vedení ročního evidenčního listu. Invalidní
a cizí důchody kód `D` nezakládají. Import přihlášek REGZEC doplní pobíraný
důchod do prázdné evidence; vyplněnou nemění a rozdíl jen ohlásí.

### 86.12.4 Prohlášení k dani má jediné místo

Prohlášení poplatníka k dani se nastavuje **výhradně v zákonné evidenci
osoby**, v sekci **Prohlášení poplatníka k dani**. Karta pracovního vztahu
ukazuje v pruhu **Ze zákonné evidence osoby** jen stav a odkaz **Nastavit
v zákonné evidenci**, který panel otevře. Hodnota na kartě vztahu se odvozuje
z evidence a mzdový snímek i měsíční hlášení berou hodnotu ze stejného zdroje,
takže se nemohou rozejít. Prohlášení se podepisuje i odvolává kdykoli během
vztahu, proto patří k osobě, ne do verze smlouvy.

<!-- cols: 24 76 -->
| Stav | Co znamená |
|---|---|
| **Podepsáno** | prohlášení platí, měsíční slevy a zvýhodnění se uplatní |
| **Nepodepsáno** | vědomě zapsané „nepodepsal", daň se sráží bez slev |
| **Neověřeno** | zapsané, ale nedoložené; osoba jde do ručního posouzení |
| Nezadáno | k danému měsíci není žádný záznam |

**Nezadáno není totéž co Nepodepsáno**, i když se počítá stejně opatrně: bez
prohlášení se měsíční sleva uplatnit nesmí (§ 38k odst. 4 zákona o daních
z příjmů) a za nesraženou zálohu ručí plátce (§ 38s). Rozlišení ukazuje, jestli
to někdo rozhodl, nebo jen zapomněl.

Evidence se vede po celých měsících s platností od a do, řady musí navazovat
a otevřený smí být jen jeden záznam. Bez podepsaného prohlášení se v měsíci
neuplatní žádná měsíční sleva ani zvýhodnění na dítě. Dva současně účinné
záznamy panel odmítne jako konflikt. Jde ale jen o kontrolu vašich záznamů:
do cizí firmy aplikace nevidí, souběh prohlášení u víc plátců si ohlídejte
sami.

### 86.12.5 Vyživované osoby a daňové zvýhodnění na dítě

Sekce **Vyživované osoby a daňové zvýhodnění** eviduje děti pro měsíční
zvýhodnění podle § 35c zákona o daních z příjmů a manžela nebo partnera, u
kterých lze slevu uplatnit až v ročním zúčtování.

U osoby zadáte vztah k poplatníkovi, jméno a příjmení zvlášť (měsíční hlášení
je vykazuje odděleně), datum narození, volitelně rodné číslo, průkaz ZTP/P,
soustavné studium a období vyživování. Rodné číslo dítěte se ukládá šifrovaně,
zobrazuje se maskované a odkrýt ho lze jen auditovaným odhalením. Rodné číslo
ani datum narození dítěte se do měsíčního hlášení neodesílají.

Evidence osoby nárok nezakládá. Ten vzniká až **uplatněním** s vlastním
obdobím, kde uvedete:

- **kdo zvýhodnění uplatňuje**: zaměstnanec, nebo jiná osoba ve společné
  domácnosti (pořadí **N**),
- **pořadí dítěte** ve společně hospodařící domácnosti; určuje výši a počítají
  se do něj i děti, které uplatňuje druhý rodič; dvě děti nesmí mít v měsíci
  stejné pořadí,
- **ZTP/P**: zvýhodnění je dvojnásobné a zaškrtnout jde jen u osoby s vedeným
  průkazem,
- **důvod a stav ověření**: podepsané prohlášení musí platit k počátku nároku;
  odkaz do dokumentace je volitelný,
- **potvrzení společné domácnosti a druhého poplatníka**; chybí-li, výpočet
  skončí v ruční kontrole,
- **jiná osoba vyživující tytéž děti v téže domácnosti**: ptá se na ni měsíční
  hlášení. Dokud je „zatím nerozhodnuto", hlášení se nesestaví; při „ano"
  doplňte jméno, příjmení a datum narození druhé osoby, jinak podání odmítne
  kontrola ČSSZ. Odpověď musí být u všech dětí domácnosti stejná.

**Pořadí N.** Pořadí se určuje za domácnost a v měsíci smí dítě uplatnit jen
jeden rodič. Uplatňuje-li zaměstnanec jen druhé dítě, zapište i první a zvolte
**Jiná osoba v domácnosti (pořadí N)** s pořadím 1. Takové dítě nezakládá částku,
ale drží pořadí, takže druhé dítě dostane sazbu druhého dítěte a mzda se na
mezeře v pořadí nezastaví. U dítěte N musí být potvrzená společná domácnost
a uvedená osoba, která zvýhodnění uplatňuje (jméno, příjmení, datum narození);
tvrzení „druhý poplatník neuplatňuje" se nevyplňuje. Do měsíčního hlášení jde
s kódem N a odpovědí ano na jinou vyživující osobu, v ročním zúčtování s kódem N
ve všech měsících. Uvádí-li zaměstnanec jen děti N, zvýhodnění neuplatňuje.

**Měsíce nároku.** Nárok se zadává po celých měsících. Náleží za měsíc, na
jehož počátku byly splněny podmínky, a podle § 35c odst. 10 už v měsíci
narození, osvojení, převzetí do péče nahrazující péči rodičů nebo zahájení
studia. Do **Vyživovaná od** zadejte den události, do **Vyživovaná do** den,
kdy vyživování skončilo (úmrtí, ukončení studia, 26. narozeniny):

- dítě narozené v průběhu měsíce má nárok od prvního dne měsíce narození,
- u osvojení, převzetí do péče a zahájení studia zvolte stejnou událost jako
  **důvod uplatnění** a nárok začne prvním dnem toho měsíce,
- začne-li vyživování v průběhu měsíce z jiného důvodu (např. se přistěhuje
  dítě manžela), nárok začíná prvním dnem dalšího měsíce,
- měsíc, ve kterém vyživování skončí, patří do nároku celý.

Formulář data předvyplní a upozorní, když zadané období z pravidel vybočí.

**Dvojnásobek za ZTP/P** náleží od měsíce, na jehož počátku průkaz platil.
Vyplňte proto **Průkaz ZTP/P přiznán od**. Uplatníte-li dvojnásobek od
dřívějšího měsíce, aplikace nárok při uložení rozdělí: do konce měsíce přiznání
základní výše, od prvního celého měsíce dvojnásobek. Mzda, roční zúčtování,
potvrzení o příjmech ani hlášení tak dvojnásobek nedostanou dřív. Den přiznání
pozdější než už evidovaný dvojnásobek aplikace neuloží.

Stejná pravidla platí pro import hlášení JMHZ a převod z předchozího systému.
U dítěte vedeného jako **osvojené** nebo **převzaté do péče** import zapíše
nárok i za měsíc začátku vyživování s odpovídajícím důvodem. Hlášení
s dvojnásobkem ZTP/P dřív, než náleží, import nepřevezme a upozorní.

Aplikace nedovolí dvě překrývající se uplatnění na totéž dítě u jednoho
poplatníka ani uplatnění mimo období vyživování. Uplatňuje-li totéž dítě (podle
rodného čísla) ve stejném měsíci jiný zaměstnanec firmy, uložení se odmítne.
Výjimkou jsou oba rodiče u téže firmy, kdy jeden uplatňuje a druhý uvádí pořadí
N; odmítne se, když oba uvádějí N.

Sazby se berou z legislativních pravidel. Bez účinné sazby pro období aplikace
částku neodhaduje a označí nárok k ruční kontrole. Nárok zasahující do měsíce
uzavřeného schválenou mzdou se nepřepisuje: původní záznam se ukončí posledním
zmrazeným měsícem a vznikne nová verze od dalšího měsíce. Mimo zmrazené období
nárok ukončíte úpravou **Nárok do (poslední den měsíce)**.

### 86.12.6 Pobytová a pracovní oprávnění cizinců

Sekce **Pobytová a pracovní oprávnění** eviduje druh, označení, stát vydání,
počátek účinnosti, konec platnosti a podklad ve firemních Dokumentech. Osobní
dokument, dokument jiné firmy ani dokument v koši aplikace nepřijme.

Historie se nepřepisuje. Prodloužení založte akcí **Navázat obnovení**
u předchozího oprávnění; vznikne nový neměnný záznam a původní podklad zůstane.
Jedno oprávnění může mít jen jedno přímé pokračování a překrývající se záznam
bez předchůdce se odmítne. Sekce upozorní na oprávnění, která skončila nebo
skončí do 30 dnů. Bez oprávnění k Dokumentům uvidíte historii a upozornění, ne
odkaz na podklad. Zápis vyžaduje **Spravovat zaměstnance** i čtení firemních
Dokumentů.

### 86.12.7 Druh vztahu a předkontace

Jedna osoba může mít víc samostatných právních vztahů. Druh vztahu rozhoduje
o výpočtu, podání i zaúčtování:

<!-- cols: 60 20 20 -->
| Druh vztahu | Hrubý náklad | Závazek |
|---|---:|---:|
| pracovní poměr mimo výkon funkce, zaměstnání malého rozsahu, DPP, DPČ | 521 | 331 |
| příjem společníka ze závislé činnosti | 522 | 366 |
| odměna za výkon funkce člena orgánu | 523 | 366 |
| pojistné hrazené zaměstnavatelem | 524 | 336 |

Odměna jednatele za výkon funkce není totéž co pracovní poměr jednatele mimo
výkon funkce ani jiný příjem společníka. Souběh se vede jako víc vztahů jedné
osoby. Převzatý vztah z Mzdové rekapitulace zachovává dosavadní kontaci; před
ostrým použitím zkontrolujte právní titul, protože starší karta
„jednatel-společník" nerozliší smlouvu o výkonu funkce od ostatní závislé
činnosti. Výchozí účty nastavíte v [Nastavení mezd](90_Nastaveni_mezd.md).

### 86.12.8 Životní cyklus vztahu

Nový vztah začíná jako **Plánovaný**. Stav mění jen akce (**Předregistrovat**,
**Zahájit / obnovit**, **Přerušit**, **Ukončit**, **Archivovat**, **Označit
nenástup**, **Vrátit z archivu**) s datem účinnosti:

`Plánovaný → Předregistrovaný → Aktivní → Přerušený → Skončený → Archivovaný`

Z přerušeného vztahu se lze vrátit do aktivního nebo ho ukončit. Plánovaný či
předregistrovaný vztah lze označit jako **Nenastoupil** a pak archivovat.
Přeskočení povinného kroku nebo návrat ze skončeného vztahu aplikace odmítne.

**Potvrdit nástup** u zaměstnance, který u firmy dřív žádnou mzdu neměl a nemá
počáteční stav, zároveň zapíše nulový počáteční stav za rok nástupu; bez něj by
osoba vypadla ze zákonného výpočtu. Zaměstnanec převzatý z jiného programu má
skutečné úhrny a doplňuje je v tabulce **Počáteční stavy**.

Skončení vztah nemaže; zůstává pro doplatek, opravu, podání a dohledání údajů.
Archivace ho jen odklidí z aktivní práce. Oznamovací povinnosti vůči zdravotní
pojišťovně se odvozují od **skutečného** nástupu, jinak od plánovaného. Vztah
**Nenastoupil** ani archivovaný vztah oznamovací povinnost nevytváří.

### 86.12.9 Sjednané podmínky, souběhy a srážková daň

Každé uložení změny podmínek založí další účinný interval a předchozí uzavře
dnem před novou účinností; starší mzdové období tak pozdější změna nepřepíše.
Historie drží uzavření smlouvy, plánovaný a skutečný nástup a dobu určitou,
úvazek, týdenní hodiny, místo práce, pravidelné pracoviště, CZ-ISCO a druh
činnosti, mzdovou účtárnu, pojistnou účast, A1 a cizí předpisy, rizikovou práci,
daňový režim, příznak primárního vztahu a důvod změny.

Kód **CZ-ISCO** může mít čtyři číslice (podskupina) nebo pět (kategorie).
Měsíční hlášení JMHZ přijme obojí, registrace zaměstnance na ČSSZ (přihláška
A1 i změna A3) jen pětimístnou kategorii. Se čtyřmístným kódem se přihláška
zastaví a vyzve k výběru kategorie; aplikace ji sama nedoplní, protože
podskupina má obvykle několik kategorií.

Karta vztahu má v hlavním sloupci **Základní údaje** (pravidelná hrubá mzda,
týdenní pracovní doba, **Úvazek (%)**, **Výjimka z výměry dovolené (týdny)**,
pravděpodobný výdělek, **Mzdová účtárna**, **Druh činnosti**, **Bližší určení
pracovněprávního vztahu**, **Režim nároku na stravování**, primární vztah)
a **Termíny**. Ve sbalené části **Další údaje** jsou skupiny **Místo výkonu
práce**, **JMHZ – vykonávaná pozice**, **Pojištění a daň** a **Výjimečné
situace**; otevře se sama jen u vztahu, kde je něco z ní vyplněné. Postranní
pruh ukazuje navazující agendy a údaje **Ze zákonné evidence osoby**.

**Dovolená.** Firemní výměra dovolené platí všem vztahům. **Výjimku z výměry
dovolené** vyplňte jen tam, kde má vztah jiný nárok (nejméně 4 týdny); prázdné
pole znamená firemní politiku. Změna se ukládá jako nová verze podmínek.

**Pravděpodobný výdělek** (§ 355 zákoníku práce) se zadává tlačítkem **Zadat
pravděpodobný výdělek (§ 355 ZP)** jen tam, kde skutečný průměr vzniknout
nemůže (dohoda v prvním měsíci, odměna za úkol bez hodin).

**Mzdová účtárna** se mění jen na kartě vztahu. Z účtárny vychází variabilní
symbol pro odvod sociálního pojistného a běh se dá na účtárnu zúžit. Novému
vztahu ji aplikace dosadí z výchozí účtárny. Vztah bez účtárny (typicky
převzatý) na to upozorní; uložení to neblokuje, blokátorem se to stane až při
uzamčení vstupů mzdového běhu. Nabídka obsahuje aktivní účtárny; deaktivovaná
účtárna, kterou vztah drží, v ní zůstává.

**Srážková daň.** Jestli se příjem zaměstnance bez podepsaného prohlášení daní
zálohou, nebo srážkou, určuje aplikace sama podle § 6 odst. 4 zákona o daních
z příjmů; na kartě se nic nevyplňuje:

- **DPP**: srážková daň 15 %, když úhrn odměn z dohod u vás za měsíc nedosáhne
  rozhodné částky pro DPP (pro rok 2026 je 12 000 Kč), bez ohledu na další
  příjmy od vás.
- **Každý jiný vztah** (pracovní poměr, zaměstnání malého rozsahu, DPČ, člen
  statutárního orgánu, společník): srážková daň 15 %, když úhrn těchto příjmů od
  vás za měsíc nedosáhne rozhodné částky pro účast na nemocenském pojištění (pro
  rok 2026 je 4 500 Kč). Rozhoduje skutečně zúčtovaný příjem, ne sjednaná mzda
  ani účast na pojištění. Souběžné vztahy se sčítají; příjem z DPP se přičte jen
  tehdy, když sám srážkou nešel.
- Příjem **přesně na rozhodné částce** se už daní zálohou.
- S **podepsaným prohlášením** se daní vždy zálohou.

Základ srážkové daně i daň se zaokrouhlují na celé koruny dolů. Sražená daň je
konečná a do ročního zúčtování nevstupuje.

**Souběhy.** Jedna osoba může mít souběžně např. HPP a DPP nebo pracovní poměr
a odměnu za výkon funkce. V aktivní práci je právě jeden vztah primární. Každý
souběh má vlastní kód, stav, historii a registrační identitu. Je-li v měsíci
účastných na sociálním pojištění víc vztahů téže osoby, pojistné zaměstnance
i sleva pracujícího důchodce se počítají a zaokrouhlují po vztazích. Tak je
vykazuje i měsíční hlášení a pojistné v přehledu je jejich součet. Rozklad na
kartě osoby ukáže výpočet každého vztahu.

### 86.12.10 Evidence pro ČSSZ: druh činnosti a vykonávaná pozice

**Druh činnosti** a **Bližší určení pracovněprávního vztahu** se vybírají
z připnutých číselníků JMHZ. Při založení aplikace navrhne druh podle pořadí
souběžného vztahu (první pracovní poměr 1, druhý 2, DPČ A, B…, DPP T, U…,
společník a člen orgánu S); návrh lze přepsat. U druhů 1 až 9 je bližší určení
povinným podkladem pro výběr scénáře hlášení; chybějící hodnota se nikdy
nevykládá jako „Žádné".

- **Výkon trestu.** Bližší určení „výkon trestu odnětí svobody nebo
  zabezpečovací detence" u pracovního poměru nebo zaměstnání malého rozsahu
  (druh 1 až 9) hlásí vztah formulářem vězně: bez zdravotního pojištění,
  pozice a rozpadu mzdy, s ELDP a odpracovanými hodinami. Odsouzený zařazený do
  práce je pro pojistné zaměstnancem (§ 5 odst. 1 písm. a) bod 11 zákona
  č. 589/1992 Sb.), počítá se proto jako pracovní poměr.
- **Druhy činnosti 11 až 14** jsou příjmy ze závislé činnosti, které u vás
  nezakládají účast na pojištění: náhrada od pojišťovny za škodu při plnění
  pracovních úkolů (11), mezinárodní pronájem pracovní síly (12), jiný příjem
  vyplácený plátcem, u kterého se činnost nevykonává (13), a neuvolněný člen
  zastupitelstva (14). Sociální ani zdravotní pojištění se nepočítá a měsíční
  hlášení jde formulářem jiného příjmu (11, 13, 14) nebo mezinárodního pronájmu
  pracovní síly (12). Vybírají se u pracovního poměru s bližším určením 1.

Skupina **JMHZ – vykonávaná pozice** (v **Další údaje**) eviduje příspěvek od
úřadu práce a jeho nástroj, funkční požitky podle § 6 odst. 10 zákona o daních
z příjmů a dočasné přidělení k jinému zaměstnavateli. Na tyto tři otázky se
aplikace ptá už při zakládání s předvybraným **Ne**. Na kartě mají stavy
**Nevyplněno (bere se jako ne)**, **Ne** a **Ano**. Nevyplněno hlášení
nezastaví, ale v evidenci zůstane, aby bylo poznat, že ji nikdo výslovně
nepotvrdil; zmrazený snímek si poznamená, že hodnota vznikla výkladem
výchozího stavu.

**Místo výkonu práce.** Obec se vybírá našeptávačem, stát z připnuté nabídky;
obec, kód obce a stát se ukládají jen jako úplná trojice a aplikace kontroluje
shodu názvu s kódem i platnost státu podle číselníků CISOB a CZEM. Podmínky před
začátkem účinnosti připnutých číselníků nelze označit jako ověřené. Budoucí
změnu lze naplánovat, ale přesahuje-li vykazované období ověřené pokrytí
číselníku, mzdový snímek ji označí jako neověřenou a bez novějšího snímku
číselníku se neodešle.

**Dočasné přidělení (agentura práce).** Při **Ano** se karta zeptá na uživatele,
ke kterému je zaměstnanec přidělen: **česká firma nebo podnikatel** s IČO (osm
číslic s platnou kontrolní číslicí), nebo **zahraniční osoba** se státem,
osmimístným registračním číslem a názvem. Hlášení uživatele vykazuje v každém
měsíci přidělení; bez něj ho příprava zastaví s odkazem na kartu. Přidělení
k nepodnikající fyzické osobě (rodné číslo) aplikace nevykazuje; takové hlášení
podejte přes ePortál ČSSZ.

**Člen družstva nebo SVJ.** U pracovního poměru a dalších vztahů mimo DPP a DPČ
je ve skupině **Pojištění a daň** volba **Člen družstva nebo SVJ, který pro ně
pracuje za odměnu**. Takový člen je zaměstnancem pro zdravotní pojištění jen
v měsíci, kdy dosáhne započitatelného příjmu (§ 5 písm. a) zákona
č. 48/1997 Sb.). V ostatních měsících se nepočítá do přehledu o platbě ani do
minima; rozklad pojistného u běhu uvede, který případ nastal.

**Riziková práce.** Sazbová kategorie § 5a odst. 1 ve skupině **Výjimečné
situace** se promítá do hlášení. U **rizikového zaměstnání** hlášení vykáže
kategorizaci rizika 1 (práce kategorie 4) a hodiny rizikové práce rovné
odpracovaným hodinám. U kategorie **zdravotnický záchranář nebo hasič podniku**
vyberte **Kategorizace rizika pro JMHZ** (záchranář, nebo člen jednotky HZS
podniku); bez volby se příprava hlášení zastaví.

**Sleva na pojistném za kratší úvazek.** Pole **Sleva zaměstnavatele za kratší
úvazek (§ 7a)** v **Další údaje** určuje důvod slevy podle § 7a odst. 1 zákona
č. 589/1992 Sb. (věk alespoň 55 let, péče o dítě mladší 10 let, péče o závislou
osobu blízkou, příprava na povolání do 26 let, rekvalifikace uchazeče
o zaměstnání, osoba se zdravotním postižením, věk do 21 let). Sleva se uplatní,
jen když ČSSZ přijala oznámení záměru slevu uplatňovat (§ 7a odst. 5); to se
podává v `Mzdy → Podání a hlášení` přes **Mimořádná podání ▾ → Záměr slevy**
(OZUSPOJ). Pole **Odkaz na podklad k nároku** je nepovinná dohledávka (například
číslo rozhodnutí o invaliditě); za ověření nároku odpovídá uživatel. Za měsíc,
ve kterém je zaměstnanec uveden v přehledu pro příspěvek v době částečné práce,
sleva nenáleží (§ 7a odst. 3 písm. e)).

### 86.12.11 Registrace vztahu a profil REGZEC A1

Na kartě vztahu je sekce **Registrace vztahu na ČSSZ**. Její část
**Autoritativní profil REGZEC A1** je podklad pro první plnou registraci.
Otevřete ji tlačítkem **Doplnit profil**. Hodnoty jsou předvyplněné z karty
osoby a vztahu i s uvedením zdroje; údaje, které aplikace nevede (číslo
popisné, vzdělání, pracovní režim, doklad totožnosti…), doplníte z personálního
podkladu. Sekce formuláře: **Trvalý pobyt**, **Adresa pobytu v ČR**,
**Kontaktní adresa**, **Daňová rezidence**, **Pracovní vztah**, **Zdravotní
pojištění a skutečnosti**, **Důchod**, **Zahraniční legislativa**, **Cizozemský
nositel pojištění**, **Doklad totožnosti**, **Přístup na trh práce**,
**Přílohy**.

**Kontrola** ukáže, co brání podání; rozpracovaný profil jde uložit i tak.
Uložení mění jen profil, ne kartu osoby; rozdíl proti kmenovým datům přenesete
tlačítkem **Zapsat do kmenových dat**. Profil se ukládá šifrovaně a po odeslání
registrace zůstává jako doklad toho, co bylo podáno. Postup přihlášky je
v [§ 85.5](85_Podani_a_hlaseni.md#855-krok-za-krokem-nastup-zamestnance),
podrobnosti v [§ 85.14.17](85_Podani_a_hlaseni.md#851417-profil-regzec-a1).

### 86.12.12 OIČ / IK MPSV a ID PPV pro JMHZ

Sekce **Identifikátory přidělené ČSSZ pro JMHZ** se načte až po otevření, takže
ani firma se stovkami zaměstnanců neposílá zbytečné dotazy.

- **OIČ / IK MPSV** identifikuje osobu a použije se u jejích vztahů.
- **ID PPV** identifikuje právě jeden vztah. Souběžný HPP a DPP téže osoby mají
  stejné OIČ, ale každý vlastní ID PPV.

Identifikátory pro testovací prostředí a produkci jsou oddělené. Přepnutí
prostředí rozepsané hodnoty zahodí, aby se testovací identifikátor neuložil do
produkce. **Poznámka k podkladu** je nepovinná; povinné je jen výslovné
potvrzení ověření. Po uložení se zobrazují jen masky. Platný identifikátor nelze
tiše přepsat; opravila-li ho ČSSZ, ověřte datum účinnosti a navazující protokol
a nezakládejte kvůli tomu druhou kartu osoby. Zobrazení stačí oprávnění číst
mzdy, uložení potřebuje současně oprávnění spravovat zaměstnance i pracovní
vztahy (nejde o pravidlo čtyř očí).

### 86.12.13 Výzvy a žádosti v důchodovém pojištění

Část povinností vzniká až výzvou nebo žádostí a lhůta běží od doručení.
Zapsaná výzva má termín spočítaný serverem a objeví se v přehledu termínů.

<!-- cols: 34 26 40 -->
| Druh | Kdo vyzývá | Lhůta a vyřízení |
|---|---|---|
| **Evidenční list důchodového pojištění** | ČSSZ, územní správa, pozůstalí | do 8 dnů od doručení výzvy (za roky od 2027 ve lhůtě z výzvy), po úmrtí do 3 měsíců; sestaví se na obrazovce ELDP tlačítkem u výzvy a od té chvíle termín nese podání listu |
| **Sdělení nebo oprava údajů měsíčním hlášením (§ 38a odst. 1)** | ČSSZ, územní správa | do 8 dnů od doručení; po uplynutí lhůty pro hlášení na tiskopise ČSSZ, případně evidenčním listem na výzvu |
| **Potvrzení o době důchodového pojištění (§ 42)** | zaměstnanec, bývalý zaměstnanec, územní správa | do 8 dnů od žádosti; aplikace ho sestaví ze schválených mezd (**Stáhnout potvrzení (PDF)**) |
| **Potvrzení o náhradách za ztrátu na výdělku (§ 37 odst. 2)** | zaměstnanec, bývalý zaměstnanec | do 30 dnů; náhrady modul nepočítá, vystavte z vlastní evidence a žádost označte jako vyřízenou |
| **Potvrzení podle znění do 31. 12. 2025** | zaměstnanec, bývalý zaměstnanec | do 30 dnů na předepsaných tiskopisech (čl. V zákona č. 360/2025 Sb.): vyloučené doby se zúčtovaným příjmem, hlubinné hornictví, směny v rizikovém zaměstnání, směny záchranáře nebo hasiče podniku; údaje o směnách modul nevede |

Stejnopis listu a potvrzení dostává zaměstnanec, u potvrzení o hornictví
a směnách ČSSZ; předání zapíšete tlačítkem u výzvy. Volitelně lze uvést číslo
jednací a doklad o vyřízení. Omylem zapsanou výzvu lze smazat.

### 86.12.14 Checklist a časová osa

Detail vztahu ukazuje povinnosti nástupu, změny a skončení: smlouva nebo dohoda,
registrace a změny pro zdravotní pojišťovnu a ČSSZ/JMHZ, daňové prohlášení,
výstupní doklady, kontrola exekucí či insolvence a kontrola pozdějšího
doplatku. Každá položka má termín a stav **Nesplněno**, **Splněno** nebo
**Netýká se**.

Ve výstupní části jsou navíc **Evidenční list důchodového pojištění (ELDP)**
a **Potvrzení o zdanitelných příjmech**. Položka, která na vztah nedopadá, se
nezaloží: ELDP se u vztahů skončených **od 1. 4. 2026** nezakládá, protože ho
sestavuje ČSSZ z měsíčního hlášení. Potvrzení o zdanitelných příjmech se vydává
na žádost do 10 dnů (§ 38j odst. 3 zákona o daních z příjmů). U nesplněné
položky vyplňte **Den žádosti zaměstnance** a klikněte na **Zapsat žádost**;
položka dostane termín (den žádosti + 10 dnů) a hlídá ji panel **Zákonné
termíny**. Povinnost se uzavře sama vytvořením potvrzení v aplikaci; potvrzení
vydané před zapsanou žádostí ji neuzavře. Žádost během trvajícího vztahu
zapíšete na kartě osoby (viz [§ 86.8](#868-krok-za-krokem-vyzvy-a-zadosti)).

Přihlášky a odhlášky se odškrtnou samy podle **stavu podání**: splněné jsou,
když podání odešlo (nebo bylo přijato) v ostrém prostředí. Připravené, ale
neodeslané hlášení ani test položku nesplní. Odhláška z ČSSZ se odškrtne
odesláním A2 (u nenastoupení A8), u zdravotní pojišťovny odesláním oznámení
o ukončení. Nesplněná registrace, změna nebo odhláška u pojišťovny má tlačítko
**Připravit oznámení ZP**: otevře oznámení zdravotním pojišťovnám s obdobím
události a pojišťovnou zaměstnance a hromadné oznámení rovnou sestaví. Nic se
tím neodesílá.

Termíny se neodvozují ode dne události, ale z pravidel, která aplikace používá
jinde. U několika lhůt aplikace přiznává, že je nemá z ověřeného zdroje:
prohlášení poplatníka (§ 38k odst. 4 ZDP), pracovní smlouva ke dni nástupu
(§ 34 odst. 2 zákoníku práce) a zápočtový list ke dni skončení (§ 313 odst. 1
zákoníku práce, jehož rozsah novela pro rok 2025 zúžila). Tyto termíny si
ověřte podle platného znění. Vyřídil-li položky za víc lidí jiný systém, označte
je hromadně v panelu **Zákonné termíny** na `Mzdy → Přehled mezd`, s povinnou
poznámkou (viz [§ 85.14.3](85_Podani_a_hlaseni.md#85143-zakonne-terminy-na-prehledu-mezd)).

**Upozornění na chybějící přihlášku.** Není-li zaměstnanec přihlášen na ČSSZ
nebo u pojišťovny, mzdový běh to ohlásí jako **varování**, ne blokaci: mzda za
práci náleží bez ohledu na přihlášku. Varování je nutné vzít na vědomí. Ozve se,
jen když platí všechno naráz: položka nástupního checklistu je nesplněná,
k vztahu není evidovaná odpovídající povinnost podání, vztah není
**Nenastoupil** ani archivovaný, nástup už nastal a vztah zakládá účast na
pojištění. Dohoda s automatickým posouzením účasti, vztah bez účasti i cizinec
s A1 tedy mlčí. Přihlášku podanou mimo aplikaci odškrtněte v checklistu, u víc
lidí hromadně v panelu **Zákonné termíny**; nástup před 1. 7. 2026 je v sekci
**Bez termínu**.

Časová osa zachovává stavové přechody, změny checklistu i rozdíl každé verze
podmínek. Změnil-li vztah mezitím jiný uživatel, starší formulář se neuloží
a je nutné načíst aktuální verzi.

### 86.12.15 Navazující agendy

Sekce **Navazující agendy** na kartě vztahu vede jedním kliknutím do agend:
docházka a směny, nepřítomnosti, mzdové vstupy, pracovní cesty, opakované
složky, průměrný výdělek, dohody o srážkách, exekuce, insolvence, zákonná
evidence, vyživované osoby, personální spis, dokumenty a roční zúčtování.
Cílová obrazovka se otevře zúžená na zaměstnance; zúžení je vidět v horní liště
a jedním tlačítkem se ruší. Zužuje server, takže se člověk najde i tehdy, když
by ležel na několikáté straně, a počty mluví o zúženém seznamu. Nedá-li zúžení
žádný záznam, lišta to řekne větou.

Souhrn pod tlačítky ukazuje u agend se záznamy jejich počet, datum posledního
a případně částku; prázdné agendy jmenuje jedna věta. Agenda bez oprávnění se
nenabízí ani nezapočítává.

### 86.12.16 Personální spis

**Personální spis** je místo pro dokumenty a poznámky, které se netýkají výpočtu
mzdy: smlouvy a dohody, dodatky, popis pozice, doklady o ukončení, osvědčení,
lékařské prohlídky a školení. Otevírá se z karty vztahu v Navazujících
agendách; stránka spisu má i výběr zaměstnance.

Nahrát jde víc souborů najednou; název se pak převezme z názvu souboru.
Povolené jsou PDF, dokumenty Wordu a OpenOffice, tabulky, skeny (JPG, PNG, TIFF,
HEIC), podepsané dokumenty (P7S, P7M, ASiC-E, ZFO) a e-maily, nejvýš 25 MB na
soubor. Stejný soubor nejde k jednomu zaměstnanci nahrát dvakrát. Dokument
s prošlou platností je označený **Platnost skončila**; **Platnost do** nemůže být
dřív než datum dokumentu.

U dokumentu jsou akce **Zobrazit** (náhled PDF a obrázků), **Stáhnout**
(originál), **Upravit** (druh, název, data a poznámka; soubor se nemění)
a **Smazat**. Smazání nejde vrátit, soubor zůstane jen v dřívějších zálohách.
Poznámky lze připnout, upravit a smazat; u každé je vidět, kdo a kdy ji zapsal.

Spis je soukromý a má vlastní oprávnění **Personální spis**. Výchozí ho dostávají
role, které smějí spravovat zaměstnance; role jen pro čtení ho nemá. Spis
neleží v sekci Dokumenty: soubory se ukládají zašifrované do samostatné složky
a zálohují se vlastní úlohou `cron-backup-personnel` (viz
[Po instalaci](05_Po_instalaci.md)). Poznámky jsou v databázi také
zašifrované. Při výmazu osobních údajů se dokumenty i poznámky stanou
nečitelnými. Zaměstnance s neprázdným spisem nejde smazat.

### 86.12.17 Skončení vztahu

Po akci **Ukončit** se na kartě vztahu objeví sekce **Skončení vztahu**. Je to
jediné místo, kde se zadává, jak a proč vztah skončil. Odsud se předvyplní
odhláška **REGZEC A2** (důvod ukončení pro Úřad práce, čistý průměrný výdělek,
odstupné) tlačítkem **Předvyplnit ze skončení vztahu**, převezme se způsob
skončení do **potvrzení zaměstnavatele pro Úřad práce** (§ 313 odst. 2
zákoníku práce; ve formuláři potvrzení je volba zamčená) a založí se
proplacení dovolené a odstupné jako vstupy posledního běhu. Schválení A2
i vydání potvrzení odmítne údaj, který záznamu odporuje. Bez záznamu se obě
místa vyplňují ručně.

#### Způsob a důvod skončení

**Způsob skončení** (výpověď zaměstnavatele, dohoda, výpověď zaměstnance,
okamžité zrušení, zkušební doba, uplynutí doby určité, úmrtí…) a u výpovědi
zaměstnavatele, dohody a okamžitého zrušení i **Zákonný důvod**, jehož nabídka
odpovídá způsobu. Dohoda z organizačních nebo zdravotních důvodů zakládá
odstupné jako výpověď a v A2 jde jako důvod 4 nebo 5, protože jen u nich ČSSZ
přijme údaj o odstupném. Dohoda bez důvodu jde jako důvod 2. Po uložení sekce
ukáže kód důvodu pro A2 a způsob skončení pro potvrzení Úřadu práce.

#### Nevyčerpaná a přečerpaná dovolená

Sekce ukazuje zůstatek knihy dovolené za rok skončení a náhradu ve výši
průměrného výdělku (§ 222 odst. 2 a 3 zákoníku práce); průměr je schválený
průměr za čtvrtletí dne skončení.

- **Proplatit nevyčerpanou dovolenou** založí schválený vstup složky **Náhrada
  mzdy za dovolenou** za měsíc skončení a do knihy zapíše proplacení, takže
  zůstatek klesne na nulu. V JMHZ se náhrada objeví v náhradách za dovolenou.
- **Srazit přečerpanou dovolenou** založí záporný vstup téže složky (§ 147
  odst. 1 písm. e) zákoníku práce). Zkontrolujte, že výplata neklesne pod
  nezabavitelnou částku; zbytek je nutné vymáhat jinak. Po úmrtí se přečerpaná
  dovolená nesráží (§ 328 odst. 2).
- **Vzít vyrovnání dovolené zpět** (v nabídce „…") založí opravný vstup ve
  stejném měsíci a proplacení v knize stornuje.

Proplácí se jen zůstatek roku skončení. Nevyčerpanou dovolenou z předchozího
roku, která nebyla převedena, sekce ohlásí; převeďte ji v knize dovolené.
Chybí-li průměr nebo nárok, sekce řekne co a pošle vás do `Mzdy → Absence
a dovolená` (záložky **Průměrný výdělek** a **Dovolená**).

#### Odstupné

U výpovědi nebo dohody z organizačních důvodů sekce navrhne odstupné podle § 67
odst. 1 zákoníku práce: jednonásobek průměrného měsíčního výdělku při trvání
poměru kratším než rok, dvojnásobek od roku do dvou let, trojnásobek od dvou
let. Do trvání se započte předchozí poměr u téhož zaměstnavatele, skončil-li
nejvýš šest měsíců před vznikem nového (§ 67 odst. 2). Konto pracovní doby
podle § 86 odst. 4 zvýší odstupné o trojnásobek. Při dosažení nejvyšší
přípustné expozice (§ 52 písm. e)) je odstupné dvanáctinásobek. Vyšší násobek
podle kolektivní smlouvy nebo vnitřního předpisu zadejte do **Násobek
odstupného podle kolektivní smlouvy** i s tím, o co se opírá; nižší než zákonný
aplikace nepřijme.

**Založit odstupné do posledního běhu** založí schválený vstup složky
**Odstupné** za měsíc skončení. V JMHZ je odstupné jen v úhrnu zúčtovaného
příjmu a v základu daně, ne ve mzdě za práci ani v náhradách (pokyny MPSV);
v `Mzdy → Mzdové složky a vstupy` má ve sloupci **JMHZ** stav **Jen do úhrnu
příjmu (bez rozpadu mzdy)**.

Před založením vyplňte **Počet násobků průměru pro srážky**. Exekuční
a insolvenční srážky se z odstupného počítají zvlášť z každého násobku jako ze
mzdy za jeden měsíc doby poskytování odstupného, s vlastní nezabavitelnou
částkou (§ 299 odst. 4 o. s. ř.). Pole je předvyplněné z návrhu a sekce ukáže,
do kdy doba poskytování trvá. Nastoupil-li zaměstnanec v té době jinam nebo mu
vznikl jiný příjem, vyplňte **Jiný příjem povinného od** a případně potvrďte,
že nezabavitelnou částku započítává nový plátce. Výpočet srážek popisuje
[§ 88 Srážky z odstupného](88_Srazky_a_exekuce.md#88128-srazky-z-odstupneho).

U výpovědi nebo dohody pro dlouhodobou zdravotní nezpůsobilost z pracovního
úrazu nebo nemoci z povolání náleží jednorázová náhrada dvanáctinásobku
průměrného měsíčního výdělku (§ 271ca zákoníku práce). Sekce ji spočítá
a nabídne **Založit náhradu**. Vyberte **Kdo náhradu vyplácí** a **Den
výplaty**:

- **zaměstnavatel**: vznikne schválený vstup složky **Jednorázová náhrada při
  skončení (§ 271ca ZP)** za měsíc výplaty; podléhá dani a srážkám, pojistné
  z ní neplyne. V den skončení ji lze vyplatit jen podle písemné dohody
  (§ 271ca odst. 2); výplata v dalším měsíci se zúčtuje jako odložený příjem,
  který aplikace pro hlášení rovnou potvrdí,
- **pojišťovna přímo zaměstnanci**: mzdový vstup nevzniká, aby se výplata
  nezdvojila; náhrada zůstane zapsaná pro A2.

V A2 se náhrada předvyplní jako jednorázová náhrada.

#### Úmrtí zaměstnance

Při způsobu skončení **Úmrtí zaměstnance** sekce vede osoby blízké podle § 328
zákoníku práce. Mzdová práva do trojnásobku průměrného měsíčního výdělku
přecházejí postupně na manžela nebo partnera, děti a rodiče, žili-li se
zaměstnancem ve společné domácnosti. Zapište jméno, vztah, společnou domácnost
a účet (**Přidat osobu**); sekce označí, kdo nárok nabývá (první skupina
v pořadí, uvnitř rovným dílem) a jeho podíl na limitu. Zbytek nároků, a všechny,
není-li oprávněná osoba, je předmětem dědictví.

Zdanění výplaty pozůstalým aplikace neurčuje, zákon o daních z příjmů ho
u nároků přešlých podle § 328 zákoníku práce výslovně neupravuje. Před výplatou
zapište do **Daňové posouzení výplaty pozůstalým**, jak ji zdaníte a podle
čeho, a potvrďte ho. Běží-li exekuce, insolvence nebo dohody o srážkách, sekce
je ukáže s proklikem; ukončete je v jejich agendě, aplikace je sama nezastaví.
A2 se předvyplní s příznakem skončení úmrtím a bez podkladů pro Úřad práce.

#### Zápočtový list a pokračující srážky

Zápočtový list (potvrzení o zaměstnání, § 313 odst. 1 zákoníku práce) se
vytváří v části **Dokumenty při skončení vztahu** (viz
[Dokumenty a výstupy](83_Dokumenty_a_vystupy.md)). Blok **Pokračující srážky ze
mzdy** nabídne každou srážku, ve které má pokračovat další plátce:

- **Exekuce** s nesplacenou pohledávkou, i doručená a dosud neověřená,
- **Dohoda o srážkách** aktivní nebo pozastavená podle § 146 písm. b) zákoníku
  práce s nevyčerpaným limitem; srážky ze zákona podle § 147 odst. 1 (vrácení
  zálohy, nevyúčtovaná záloha, náhrada mzdy bez nároku) jsou pohledávky tohoto
  zaměstnavatele, které další plátce srážet nesmí, a nenabízejí se,
- **Insolvence**: zahájené řízení nebo oddlužení zapsané v měsíční evidenci
  srážek za měsíc skončení.

U každé doplňte oprávněného, orgán, který srážku nařídil, a spisovou značku;
částky se přebírají z evidence srážek. Zápočtový list se nevydá, dokud údaje
neodpovídají všem srážkám, které evidence zná. Soudu nebo exekutorovi oznamte
skončení zvlášť do jednoho týdne (§ 295 odst. 2 o. s. ř., viz
[Srážky a exekuce](88_Srazky_a_exekuce.md#887-krok-za-krokem-skonceni-pracovniho-pomeru-povinneho)).

#### Odložený příjem po skončení

Doplatek zúčtovaný v měsíci po skončení pracovního poměru (typicky odměnu)
potvrďte v části **Odložený příjem po skončení vztahu**: měsíc zúčtování a druh
*Příjem po skončení zaměstnání (1)*. Běh pak příjem přijme, pojistné vypočte za
měsíc zúčtování a JMHZ ho vykáže samostatným formulářem Odložený příjem s ELDP
za tento měsíc (0 dnů, kód s „P" na druhé pozici). Záloha na daň zůstává
zálohou a hlášení uvádí podepsané prohlášení, ale měsíční slevu na poplatníka
ani zvýhodnění na děti aplikace za měsíc, kdy už u vás nepracuje, neodečte: za
měsíc je smí poskytnout jen jeden plátce a poplatník je mohl uplatnit u nového
zaměstnavatele. Nárok si uplatní v ročním zúčtování nebo v přiznání. Bez
potvrzení běh příjem po skončení odmítne a pošle vás sem.

Ostatní druhy odloženého příjmu (např. doplatek za dřívější měsíce trvajícího
vztahu) a odložený příjem z dohod podejte opravným hlášením na ePortálu ČSSZ.
U vztahu, jehož účast stojí na výši příjmu (dohoda, zaměstnání malého rozsahu,
člen orgánu), běh odložený příjem nepřijme ani po potvrzení: s příjmem
posledního měsíce může zpětně založit účast a opravu hlášení kvůli
nemocenskému pojištění je třeba zaslat vždy. Kontrola běhu proto řekne, že
hlášení i opravu podáte ručně přes ePortál ČSSZ.

### 86.12.18 Bezpečnost a časté chyby

Osoba může existovat bez aktivního vztahu. Neúplná data lze evidovat, výpočet,
dokument nebo podání si ale doplnění vyžádá. Ověřujte identifikátory,
pojišťovnu, účet, data vztahu a souběhy. Osobní a zdravotní údaje zpřístupněte
jen podle role. Odkaz na dokument je volitelná stopa k bezpečně uloženému
podkladu; nenahrazuje zákonný identifikátor a nesmí obsahovat tajné údaje.
Podání, které aplikace nepodporuje, dokončete ručně.

Časté chyby:

- duplicitní karta osoby místo dalšího vztahu,
- přepsání historické podmínky bez data účinnosti,
- vstup přiřazený k jinému ze souběžných vztahů,
- skončení vztahu bez poslední mzdy, dokumentů nebo ruční oznamovací
  povinnosti.

## 86.13 Související kapitoly

- [Úplné mzdy](75_Uplne_mzdy.md): jak začít a měsíční postup
- [Nastavení mezd](90_Nastaveni_mezd.md): účtárny, předkontace, importy zaměstnanců
- [Mzdové složky a vstupy](91_Mzdove_slozky_a_vstupy.md): pravidelné odměňování
- [Mzdové běhy](80_Mzdove_behy.md): výpočet a schválení mezd
- [Podání a hlášení](85_Podani_a_hlaseni.md): přihlášky, změny, odhlášky a JMHZ
- [Dokumenty a výstupy](83_Dokumenty_a_vystupy.md): zápočtový list a potvrzení
- [Srážky a exekuce](88_Srazky_a_exekuce.md) a [Dohody o srážkách](87_Dohody_o_srazkach.md)
- [Výmaz osobních údajů](94_Vymaz_osobnich_udaju.md)
