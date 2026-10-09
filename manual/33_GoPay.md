# 33. GoPay

> Návod, jak do MyÚčta načíst měsíční vyúčtování GoPay (XML) nebo výpis z obchodního
> účtu (XLS/XLSX), zaúčtovat platby, vratky, poplatky i výplatu na bankovní účet
> a ověřit, že se vše spárovalo. Pro účetní a každého, kdo přijímá platby přes GoPay.
> Funkce je dostupná firmám s podvojným účetnictvím i v daňové evidenci (tam bez účetních
> zápisů, viz [§ 33.8.7](#3387-gopay-v-danove-evidenci)).

## 33.1 Kdy to potřebujete

- Zákazník zaplatil fakturu platebním tlačítkem GoPay a vy chcete vidět, že peníze drží GoPay a ještě nedorazily na účet.
- GoPay uzavřel měsíc a poslal vyúčtování (Clearing XML) nebo máte výpis z obchodního účtu.
- Výplata od GoPay přišla na bankovní účet a má se spárovat s vyúčtováním.
- U vyúčtování vidíte stav **Vyžaduje kontrolu** a chcete zjistit proč.
- Doklad nebo bankovní výpis jste do aplikace nahráli až po importu vyúčtování a potřebujete vyúčtování zpracovat znovu.

<!-- cols: 24 40 36 -->
| Kdy | Co udělat | Kde v aplikaci |
|---|---|---|
| jednou při zavádění | Vybrat účty pro GoPay | `Peníze → GoPay`, část **Nastavení účtování** |
| průběžně | Podívat se, které úhrady čekají na vyúčtování | sekce **Čeká na vyúčtování** |
| po uzavření měsíce u GoPay | Načíst vyúčtování | část **Načíst vyúčtování nebo výpis**, postup v [§ 33.3](#333-krok-za-krokem-import-vyuctovani) |
| po importu bankovního výpisu | Zkontrolovat, že je spárovaná výplata | seznam **Importovaná vyúčtování** |

## 33.2 Než začnete

1. **Podvojné účetnictví nebo daňová evidence.** Stránka `Peníze → GoPay` je v menu u firem s podvojným účetnictvím i s daňovou evidencí. K použití potřebujete přístup k bance, k importu potřebujete oprávnění k importu a účtování banky.
2. **Analytické účty (jen podvojné účetnictví).** V účtové osnově musíte mít účty, které vyberete v nastavení (viz [§ 33.2.1](#3321-nastaveni-uctu)). Dva různé účty 221 (peníze u GoPay a cílový bankovní účet), účet pro pohledávky 311, nákladový účet pro poplatky a účet 261.
3. **Zaúčtované doklady (jen podvojné účetnictví).** Faktury a dobropisy, které mají platby párovat, musí být před importem zaúčtované.
4. **Soubor od GoPay.** Clearing XML za uzavřené období z administrace GoPay, nebo výpis z obchodního účtu v XLS/XLSX. K němu můžete přiložit původní PDF.

### 33.2.1 Nastavení účtů

Na stránce `Peníze → GoPay` vyplňte část **Nastavení účtování** a klikněte na **Uložit nastavení**.

<!-- cols: 38 62 -->
| Pole | K čemu slouží |
|---|---|
| **GoPay účet (221)** | Eviduje peníze, které už zákazníci zaplatili, ale GoPay je ještě neposlal na běžný účet. |
| **Cílový bankovní účet (221)** | Analytika skutečného účtu, na který GoPay posílá vyúčtování. |
| **Pohledávky (311)** | Účet použitý na vystavených fakturách a dobropisech. |
| **Nákladový účet poplatků** | Poplatky GoPay, například analytika účtu 568. |
| **Peníze na cestě (261)** | Propojí souhrnný převod z GoPay s jednou příchozí platbou na bankovním výpisu. |
| **Účet odesílatele GoPay**, **Kód banky** | Účet, ze kterého GoPay posílá výplatu. Podle něj se pozná příchozí převod. |
| **Tolerance data bankovní platby ve dnech** | Povolený rozdíl data mezi XML a bankovním pohybem (0 až 14 dní). |

> [!WARNING]
> **GoPay účet (221)** a **Cílový bankovní účet (221)** musí být dvě různé analytiky. Jinak aplikace nastavení neuloží.

V daňové evidenci se účty nevybírají. Část **Nastavení účtování** obsahuje jen **Účet odesílatele GoPay**, **Kód banky**
a toleranci data, podle kterých se výplata spáruje s bankovním pohybem.

## 33.3 Krok za krokem: import vyúčtování

1. V administraci GoPay stáhněte Clearing XML za uzavřené období.
2. Otevřete `Peníze → GoPay`. Máte-li nastavení uložené, zobrazí se část **Načíst vyúčtování nebo výpis**.
3. V poli **GoPay XML nebo XLS/XLSX** vyberte soubor. Chcete-li, přidejte do pole **GoPay PDF (volitelné)** odpovídající PDF.
4. Klikněte na **Načíst a zaúčtovat**.
5. Aplikace ověří strukturu souboru i kontrolní součty, jednotlivé pohyby spáruje s doklady a vytvoří účetní zápisy. Souhrnnou výplatu se pokusí spárovat s už naimportovaným bankovním výpisem.
6. Výsledek zkontrolujte v seznamu **Importovaná vyúčtování**.

**Jak poznáte, že je hotovo:** U vyúčtování je stav **Hotovo**. Znamená, že jsou zaúčtované všechny pohyby a spárovaný je i bankovní převod. Stav **Vyžaduje kontrolu** ukáže v detailu konkrétní důvod u problematického pohybu nebo převodu.

> [!TIP]
> Stejný soubor můžete nahrát opakovaně. Nevzniknou duplicitní zápisy, vyúčtování se jen znovu zpracuje. Hodí se to, když jste bankovní výpis nebo doklad načetli až po importu.

### 33.3.1 Import výpisu z obchodního účtu (XLS/XLSX)

Místo XML můžete nahrát výpis z obchodního účtu za zvolené období. Postup je stejný jako u XML. Aplikace navíc ověří, že součet pohybů odpovídá počátečnímu a konečnému zůstatku výpisu.

Výpis se od XML liší ve třech věcech:

- Neobsahuje GoPay ID platby. Platbu proto převezme úhrada faktury se stejným číslem objednávky, částkou a měnou, jejíž datum se od pohybu liší nejvýše o tři dny. Pokud takovou úhradu nenajde, páruje se platba s fakturou podle čísla objednávky jako u XML.
- Výplata vyúčtování na bankovní účet a poplatek za vyúčtování jsou ve výpisu až v období, kdy je GoPay odeslal, obvykle tedy ve výpisu za další měsíc. Výplata nese ve sloupci **ID objednávky/VS** číslo vyúčtování a páruje se s bankovním převodem podle tohoto variabilního symbolu.
- Výpis obsahující výplaty více vyúčtování nelze načíst. Nahrajte XML jednotlivých vyúčtování nebo výpis za kratší období.

XML a výpisy lze kombinovat. Pohyb, který už eviduje dříve načtené vyúčtování nebo výpis, se znovu nezaloží ani nezaúčtuje. Výplatu a poplatek vyúčtování aplikace pozná podle čísla vyúčtování, platby a vratky podle typu, čísla objednávky, částky a data. Jsou-li všechny pohyby souboru už evidované, import skončí upozorněním a nic nezmění. Stejný výpis stažený znovu se pozná podle obsahu a jen se znovu zpracuje.

## 33.4 Krok za krokem: sledování úhrad, které čekají na vyúčtování

Úhrada faktury platebním tlačítkem GoPay se zaúčtuje hned k datu platby, ne až po vyúčtování.

1. Otevřete `Peníze → GoPay`.
2. V sekci **Čeká na vyúčtování** vidíte úhrady, které ještě nepotvrdilo žádné vyúčtování. Součet **Dorazí v příštím vyúčtování** je částka, kterou GoPay pošle (před odečtením poplatků a vratek).
3. Pokud sekce hlásí úhrady s chybou zaúčtování, opravte příčinu uvedenou u úhrady (například nezaúčtovanou fakturu) a klikněte na **Zaúčtovat úhrady čekající na vyúčtování**.

**Jak poznáte, že je hotovo:** Sekce hlásí, že žádná GoPay úhrada nečeká na vyúčtování, nebo u úhrad nezůstává chyba. Při příštím importu vyúčtování se úhrada převezme bez dalšího zápisu.

## 33.5 Krok za krokem: když vyúčtování vyžaduje kontrolu

1. V seznamu **Importovaná vyúčtování** otevřete vyúčtování se stavem **Vyžaduje kontrolu** (tlačítko **Detail**).
2. V tabulce pohybů najděte řádek se stavem **Nespárováno** nebo **Chyba**. Důvod je uvedený u pohybu.
3. Opravte příčinu:
   - chybějící nebo nezaúčtovaná faktura či dobropis: doklad zaúčtujte,
   - nesouhlasící částka: ověřte částku na dokladu a číslo objednávky dodavatele,
   - nespárovaná výplata: nejdřív importujte bankovní výpis nebo u avíza použijte **Ručně spárovat** (viz [§ 33.8](#338-podrobnosti-a-pravidla)).
4. U vyúčtování klikněte na **Zpracovat znovu**.

**Jak poznáte, že je hotovo:** Zobrazí se hláška, že je vyúčtování kompletně spárované a zaúčtované, a stav je **Hotovo**.

## 33.6 Krok za krokem: PDF a smazání importu

**Přiložení PDF k vyúčtování:**

1. Otevřete `Peníze → GoPay` a v seznamu **Importovaná vyúčtování** klikněte u vyúčtování na **Detail**.
2. V poli **Přiložit PDF** (u vyúčtování, které už PDF má, **Nahradit PDF**) vyberte soubor PDF (nejvýše 10 MiB).
3. Klikněte na **Nahrát PDF**.

**Jak poznáte, že je hotovo:** Zobrazí se hláška „PDF bylo přiloženo k GoPay vyúčtování.“ a v detailu je vidět **Přiložené PDF vyúčtování** s tlačítky **Stáhnout PDF** a **Smazat PDF**. Smazání PDF nemění XML, pohyby ani účetní zápisy. Původní soubor (XML, XLS nebo XLSX) stáhnete tlačítkem u vyúčtování v seznamu.

**Smazání importu** (jen administrátor):

1. V seznamu **Importovaná vyúčtování** klikněte u vyúčtování na **Smazat**.
2. Potvrďte dotaz na smazání vyúčtování, importovaného souboru a jeho účetních zápisů. Akci nelze vrátit.

**Jak poznáte, že je hotovo:** Zobrazí se hláška „GoPay vyúčtování, XML a související účetní zápisy byly smazány.“ Vyúčtování zmizí ze seznamu a jeho zápisy z účetního deníku. Úhrady faktur se vrátí do sekce **Čeká na vyúčtování** a stejný soubor můžete nahrát znovu. Smazat jde jen vyúčtování, jehož zápisy leží v otevřeném a nezamčeném období a nejsou stornované (viz [§ 33.8.6](#3386-smazani-importu)).

## 33.7 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Před importem nastav účty modulu GoPay | Není uložené nastavení | Vyplňte a uložte **Nastavení účtování** ([§ 33.2.1](#3321-nastaveni-uctu)). |
| GoPay účet a cílový bankovní účet musí být různé analytiky | Vybrali jste stejný účet | Založte v osnově samostatnou analytiku a vyberte ji. |
| „Vyber všechny účty pro automatické účtování“ nebo „Zvolený účet neodpovídá požadované účetní skupině“ | Některý účet v nastavení chybí, nebo je z jiné skupiny (například ne 221 u GoPay účtu) | Doplňte v **Nastavení účtování** všechny účty podle popisu polí. |
| „Číslo výplatního účtu GoPay nemá platný český formát“, „Kód banky GoPay musí mít čtyři číslice“ nebo „Tolerance data musí být 0 až 14 dní“ | Chybně vyplněné pole nastavení | Opravte **Účet odesílatele GoPay**, **Kód banky** nebo toleranci a uložte. |
| Pro import potřebujete oprávnění k importu a účtování banky | Chybí oprávnění | Požádejte administrátora o doplnění oprávnění. |
| Soubor není platné GoPay XML, nebo XML nemá očekávaný formát GoPay Clearing | Soubor není Clearing XML z GoPay | Stáhněte znovu Clearing XML z administrace GoPay. |
| Soubor překračuje limit 2 MB | Příliš velký soubor | Použijte vyúčtování za kratší období. |
| „PDF překračuje limit 10 MiB“ | Příliš velké PDF | Přiložte menší PDF (například znovu stažené z administrace GoPay). |
| „Soubor obsahuje nepodporovaný typ pohybu“ nebo „Ze souboru nelze bezpečně určit měnu“ | Soubor obsahuje údaje, které aplikace neumí bezpečně zpracovat | Nic se neuložilo. Zkuste Clearing XML místo výpisu, případně kontaktujte podporu. |
| „Stejné vyúčtování už existuje s jinými údaji“ | Vyúčtování se stejným číslem už je načtené s jiným obsahem | Zkontrolujte, že nahráváte správný soubor. Původní import případně nejdřív smažte. |
| Souhrnné částky nesouhlasí s jednotlivými pohyby, nebo kontrolní součet pohybů v XML nesouhlasí | Soubor je poškozený nebo upravený | Stáhněte soubor znovu. Nic se neuložilo. |
| Soubor není výpis z obchodního účtu GoPay | Nahráli jste jiný XLS/XLSX | Stáhněte výpis z obchodního účtu GoPay. |
| Výpis obsahuje výplaty více vyúčtování | Výpis zasahuje do více vyúčtování | Nahrajte XML jednotlivých vyúčtování nebo výpis za kratší období. |
| Všechny pohyby souboru už jsou evidované | Pohyby už jsou ve dříve načteném vyúčtování nebo výpisu | Nic dalšího není potřeba, import nic nezměnil. |
| Vyúčtování obsahuje zápis v uzavřeném účetním období, nebo v uzamčené části účetnictví | Datum zápisu leží v zavřeném období | Otevřete období, nebo se obraťte na toho, kdo období uzavřel. |
| Vyúčtování obsahuje stornovaný zápis | Některý zápis už má storno | Nejdřív vyřešte storno v účetním deníku. |
| Pohyb je ve stavu **Nespárováno** | Doklad chybí, částka nesouhlasí nebo je výsledek nejednoznačný | Doklad zaúčtujte nebo opravte, pak u vyúčtování klikněte na **Zpracovat znovu**. |
| Úhrada je už potvrzená vyúčtováním GoPay | Chcete smazat úhradu, kterou už převzalo vyúčtování | Nejdřív smažte vyúčtování. |
| Úhrada faktury je už zaúčtovaná jiným GoPay pohybem | Úhrada má jiný zápis | Zkontrolujte pohyby v detailu vyúčtování. |

## 33.8 Podrobnosti a pravidla

Příchozí převod se dohledává ve výpisech GPC, PDF i v pohybech načtených přes bankovní API. Tyto zdroje jsou pro zaúčtování rovnocenné. E-mailové avízo je pouze předběžné a samo zaúčtování převodu nedokládá.

### 33.8.1 Úhrada faktury v den platby

Úhrada faktury platebním tlačítkem GoPay nese u platby referenci `GOPAY:<GoPay ID platby>`. Jakmile se taková úhrada uloží, aplikace ji hned zaúčtuje k datu platby zápisem `221 GoPay / 311 Pohledávky`. Pohledávka tím zaniká ve stejný den, kdy je faktura v modulu i v saldokontu uhrazená, a hlavní kniha se saldokontem souhlasí i na konci měsíce, kdy vyúčtování GoPay ještě nedorazilo. Peníze od té chvíle drží GoPay, takže je vidíte na GoPay účtu.

Takové úhrady ukazuje sekce **Čeká na vyúčtování**. Součet sekce je částka, kterou GoPay pošle v příštím vyúčtování (před odečtením poplatků a vratek). Při importu vyúčtování se platba se stejným GoPay ID, částkou a měnou převezme mezi pohyby vyúčtování bez dalšího účetního zápisu. Nesouhlasí-li částka nebo měna, pohyb vyúčtování zůstane ve stavu **Nespárováno** (při technické chybě **Chyba**).

Pokud úhradu nelze zaúčtovat hned (faktura ještě není zaúčtovaná, datum platby leží v zamčené části účetnictví), úhrada se uloží a v sekci zůstane s důvodem chyby. Tlačítko **Zaúčtovat úhrady čekající na vyúčtování** zaúčtuje tyto úhrady i starší GoPay úhrady bez zápisu. Opakované použití nic nezdvojí. Totéž dělá příkaz `php api/bin/gopay-post-pending.php --apply`.

Smazání úhrady faktury smaže i její zápis ke dni platby, pokud leží v otevřeném a nezamčeném období. Úhradu, kterou už převzalo vyúčtování, smazat nelze. Nejdřív je potřeba smazat vyúčtování.

### 33.8.2 Bankovní avízo a ruční spárování

Pokud příchozí převod nejprve dorazí jen jako bankovní avízo, otevřete u něj **Ručně spárovat**. Aplikace nabídne odpovídající GoPay vyúčtování podle částky, měny, variabilního symbolu, data a účtu odesílatele. Avízo se nezaúčtuje. Po importu skutečného GPC výpisu se vazba automaticky převede na jeho bankovní pohyb a teprve ten vytvoří zápis přijetí převodu `221 Banka / 261 Peníze na cestě`.

### 33.8.3 Párování dokladů

Platba se páruje přednostně podle GoPay ID uloženého u úhrady faktury. Pokud tam ID není, použije se přesné **Číslo objednávky dodavatele** uložené na dokladu spolu s částkou a měnou. U historických dokladů zůstává fallback na řádek `Objednávka: MYU...` v poznámce. Vratka se stejným způsobem páruje s dobropisem. Po úspěšném spárování vratky se dobropis označí jako zaplacený k datu vratky. Volba **Zpracovat znovu** tímto způsobem dorovná i dříve spárovaný dobropis, který ještě zůstal ve stavu nezaplaceno.

Doklad musí být před importem zaúčtovaný. Pokud chybí, částka nesouhlasí nebo je výsledek nejednoznačný, pohyb zůstane ve stavu **Nespárováno** (při technické chybě **Chyba**). Po opravě dokladu zvolte u vyúčtování **Zpracovat znovu**.

V účetním deníku je vazba obousměrná. U zaúčtování faktury nebo dobropisu se v části **Souvisí** zobrazí konkrétní GoPay pohyb a jeho zápis. Z GoPay zápisu se lze stejným způsobem vrátit na zaúčtování dokladu. Vazba se odvozuje z uloženého GoPay pohybu, takže se zobrazuje i u dříve importovaných vyúčtování.

### 33.8.4 Účetní zápisy

Automatické účtování používá následující schéma:

| Událost | Má dáti | Dal |
|---|---|---|
| Platba zákazníka | 221 GoPay | 311 Pohledávky |
| Vratka zákazníkovi | 311 Pohledávky | 221 GoPay |
| Poplatek GoPay | Nákladový účet | 221 GoPay |
| Dobropis poplatku | 221 GoPay | Nákladový účet |
| Odeslání měsíčního vyúčtování | 261 Peníze na cestě | 221 GoPay |
| Přijetí převodu na bankovní účet | 221 Banka | 261 Peníze na cestě |

Příchozí bankovní převod se páruje pouze při shodě částky, měny, clearingového variabilního symbolu, časového okna a nastaveného účtu odesílatele. Samotné číslo účtu GoPay nestačí, protože stejný účet používají i jiná vyúčtování.

### 33.8.5 Stavy vyúčtování

Seznam ukazuje počet všech a zaúčtovaných pohybů. Stav **Hotovo** znamená, že jsou zaúčtované všechny pohyby a je spárovaný i bankovní převod. Stav **Vyžaduje kontrolu** obsahuje v detailu konkrétní důvod u problematického pohybu nebo převodu. Další stavy jsou **Načteno** a **Zpracovává se**. Pohyby mají typ **Platba**, **Vratka**, **Poplatek za vratku**, **Poplatek GoPay**, **Dobropis poplatku** nebo **Převod na účet** a stav **Čeká**, **Zaúčtováno**, **Nespárováno** nebo **Chyba**.

Původní XML zůstává uložené u vyúčtování a lze je kdykoli znovu stáhnout. PDF lze v detailu vyúčtování dodatečně nahrát, nahradit, stáhnout nebo samostatně smazat. Samostatné smazání PDF nemění XML, pohyby ani účetní zápisy.

### 33.8.6 Smazání importu

Administrátor může importované vyúčtování smazat ze seznamu. Smazání odstraní původní XML, přiložené PDF, jednotlivé GoPay pohyby a účetní zápisy, které z nich modul vytvořil. Faktury, dobropisy a jejich úhrady zůstanou zachované. Pokud modul pro příchozí převod použil účetní zápis, který existoval už před zpracováním XML, tento zápis se nesmaže. Úhrady faktur zaúčtované ke dni platby se také nesmažou: vrátí se i se svým zápisem do sekce **Čeká na vyúčtování** a příští import je převezme znovu.

Vyúčtování lze smazat jen tehdy, když všechny jeho účetní zápisy patří do otevřeného a nezamčeného období a nebyly stornované. Po smazání lze stejné XML znovu importovat.

### 33.8.7 GoPay v daňové evidenci

V daňové evidenci se GoPay chová jako v podvojném účetnictví, jen místo účetních zápisů vede pohyby peněžní deník.
Příjem vzniká dnem platby přes GoPay (úhrada faktury s referencí `GOPAY:`), vratka k dobropisu příjem snižuje, poplatky
jsou daňový výdaj ke dni srážky ve vyúčtování a výplata na bankovní účet je převod mezi vlastními prostředky. GoPay má
v deníku vlastní zůstatek, který se sesouhlasí se zůstatkem obchodního účtu GoPay. Podrobnosti a příklad jsou
v [kapitole Daňová evidence](74_Danova_evidence.md#74917-gopay-v-danove-evidenci).

Rozdíly proti podvojnému účetnictví:

- Doklady se před importem nezaúčtovávají, pohyb se páruje rovnou s fakturou nebo dobropisem. Spárovaný pohyb má stav
  **Zaúčtováno** bez čísla zápisu.
- Sekce **Čeká na vyúčtování** se nezobrazuje: platbu, kterou GoPay ještě nevyplatil, ukazuje zůstatek GoPay v peněžním
  deníku.
- Spárovaná výplata zařadí bankovní pohyb v peněžním deníku jako **Převod**. Smazání vyúčtování toto zařazení zruší.
- Vyúčtování v roce s dokončenou roční uzávěrkou daňové evidence nejde načíst, zpracovat znovu ani smazat.

## 33.9 Související kapitoly

- [Banka](29_Banka.md) - import bankovních výpisů a párování plateb, kam se GoPay převod páruje.
- [Bankovní účty](30_Bankovni_ucty.md) - účty, ze kterých GoPay posílá výplatu.
- [Účetní deník](52_Ucetni_denik.md) - zápisy vytvořené z vyúčtování.
- [Účtová osnova](66_Ucetni_osnova.md) - analytické účty pro nastavení.
- [Daňová evidence](74_Danova_evidence.md) - GoPay jako peněžní prostředek v peněžním deníku.
