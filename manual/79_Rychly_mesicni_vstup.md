# 79. Rychlý měsíční vstup

> Jak na jedné stránce zadat měsíční mzdy, přesčasy, odměny a příplatky všem
> zaměstnancům najednou, bez otevírání každého vztahu zvlášť. Pro mzdové
> účetní před spuštěním mzdového běhu.

## 79.1 Kdy to potřebujete

- Začíná zpracování mezd za měsíc a potřebujete zadat základní mzdy, odměny
  a bonusy.
- Zaměstnanec pracoval přesčas a chcete ho zadat hodinami nebo částkou.
- Firma nevede docházku a příplatky za noc, víkend, svátek nebo ztížené
  prostředí zadáváte ručně.
- Import z docházkového systému přinesl hodnotu, kterou potřebujete ručně
  přepsat.
- Mzdový běh hlásí, že pravidelná složka nemá za měsíc vstup.

Do měsíčního postupu patří po absencích, docházce a cestovních náhradách
a před mzdovým během (viz [§ 75.4](75_Uplne_mzdy.md#754-krok-za-krokem-zpracovani-mzdoveho-mesice)).

## 79.2 Než začnete

1. **Pracovní vztahy** účinné ve zpracovávaném měsíci (`Mzdy → Zaměstnanci`).
   Bez nich stránka ukáže **V tomto měsíci nejsou účinné pracovní vztahy**.
2. **Mzdové složky** firmy (`Mzdy → Mzdové složky a vstupy`).
3. **Absence a docházka** za měsíc zapsané a rozhodnuté. Nabídnutá mzda se
   o ně krátí.
4. **Pracovní kalendář** přiřazený vztahu, chcete-li zadávat přesčas hodinami,
   a **schválený průměrný hodinový výdělek** za čtvrtletí.
5. **Oprávnění** zapisovat mzdové vstupy. S právem mzdové vstupy schvalovat
   se řádky ukládají rovnou jako schválené.
6. Podklad pro každý zadaný údaj.

## 79.3 Krok za krokem: měsíční mzdy a odměny

1. Otevřete `Mzdy → Rychlý měsíční vstup` (nadpis stránky **Rychlý měsíční
   vstup mezd**). Ověřte firmu a nahoře v poli **Mzdové období** měsíc.
2. Zkontrolujte pruhy nad tabulkou. Pruh **Navrženo, neuloženo** upozorňuje,
   že základní mzda v přerušovaně orámovaných polích je jen návrh ze
   sjednaných podmínek; mzdový běh ji nevidí, dokud ji neuložíte.
3. Hledáte-li konkrétního člověka, napište jméno nebo osobní číslo do pole
   hledání nad tabulkou.
4. U každého zaměstnance, u kterého se něco mění, zkontrolujte nebo přepište
   **Základní mzda** (u dohody **Základní odměna z dohody**, u společníka
   **Závislý příjem společníka**, u statutára **Odměna za výkon funkce**)
   a doplňte **Bonus / odměna**.
5. Pokud složka v měsíci není, zadejte **0**. Prázdné pole neznamená nulu.
   Trvající vztah bez příjmu zadejte s 0 Kč u základní mzdy; podá se za něj
   nulové hlášení JMHZ.
6. Porovnejte **Součet za období** pod tabulkou se zdrojovým podkladem.
7. Klikněte na **Uložit měsíční podklady** (nebo v pruhu nahoře na **Uložit
   a schválit vše (N)**, bez práva schvalovat **Uložit vše (N)**). Uloží se
   celá rozepsaná sada ze všech stránek.
8. Klikněte na **Přejít na mzdový běh** a mzdy spočítejte nebo přepočítejte
   (viz [Mzdové běhy](80_Mzdove_behy.md)).

**Jak poznáte, že je hotovo:** Objeví se hláška **Měsíční podklady byly
bezpečně uloženy.**, pruh **Navrženo, neuloženo** zmizí a pole mají ikonu
stavu **Schváleno** (bez práva schvalovat **Koncept**, který musí ještě někdo
schválit).

> [!TIP]
> Má-li vztah pravidelnou složku (typicky převzatou měsíční mzdu) bez vstupu
> za tento měsíc, ukáže se pruh **Pravidelná mzdová složka bez vstupu za
> tento měsíc**. Klikněte na **Vytvořit a schválit vstupy z pravidelných
> složek (N)** (bez práva schvalovat **Vytvořit vstupy z pravidelných
> složek (N)**). Mzda se při tom krátí o schválené nepřítomnosti.

## 79.4 Krok za krokem: přesčas

1. V řádku zaměstnance přepněte malým přepínačem **h / Kč** způsob zadání.
   Volba platí jen pro ten řádek.
2. V režimu **h** zadejte počet přesčasových hodin. Aplikace pod polem ukáže
   rozdělení: „Z toho dosažená mzda … a příplatek za přesčas …“.
3. V režimu **Kč** zadejte celkovou částku přesčasu.
4. Uložte jako v [§ 79.3](#793-krok-za-krokem-mesicni-mzdy-a-odmeny).

**Jak poznáte, že je hotovo:** Pole přesčasu má ikonu stavu **Schváleno**
nebo **Koncept** a v náhledu hrubého příjmu je přesčas započtený.

> [!WARNING]
> Přesčas zadejte buď tady, nebo v docházce, nikdy obojím. Jeden přesčas
> nelze vykázat dvakrát; schválení docházky ani uložení vstupu ho podruhé
> nepřijme.

## 79.5 Krok za krokem: zákonné příplatky a další složky

1. Klikněte na **Zadat i příplatky**. Odkryjí se sloupce pro noční práci
   (§ 116), sobotu a neděli (§ 118), práci ve svátek (§ 115) a ztížené
   prostředí (§ 117) a otevře se nabídka sloupců.
2. Zadejte počet hodin za měsíc, u ztíženého prostředí i počet ztěžujících
   vlivů. Částku aplikace dopočte a ukáže u pole.
3. Chcete-li zadávat i další mzdové složky, zaškrtněte je v nabídce
   **Složky**. Předvolba **Kompaktní** vrátí výchozí stav, **Kompletní
   přehled** přidá každou složku, která má v měsíci hodnotu.
4. Uložte jako v [§ 79.3](#793-krok-za-krokem-mesicni-mzdy-a-odmeny).

**Jak poznáte, že je hotovo:** U pole příplatku je dopočtená částka a po
uložení ikona stavu. Pole, do kterého zadat nejde, ukazuje **Nedostupné**
a důvod najdete v popisku po najetí myší nebo v pruhu **Proč některé
příplatky nejde zadat**.

## 79.6 Krok za krokem: úprava hodnoty z importu

1. Najděte buňku se šipkou dolů (hodnota z importu, například z docházky).
2. Přepište částku. Buňka dostane ikonu tužky a popisek „Z importu X,
   přepsáno na Y“.
3. Chcete-li přepis zrušit, klikněte na **Vrátit na hodnotu z importu**.
   Rozmyslíte-li si to, klikněte na **Ponechat ruční přepis**.
4. Uložte.

**Jak poznáte, že je hotovo:** Po uložení platí v buňce ruční hodnota
s ikonou tužky, nebo po vrácení znovu hodnota z importu se šipkou dolů.

## 79.7 Když něco nejde

<!-- cols: 32 34 34 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Řádek je jen ke čtení a u jména je štítek **Mzda uzavřena** | Mzdový běh za měsíc je schválený, zaúčtovaný, připravený k úhradě, vyplacený nebo uzavřený | U běhu klikněte na **Vyžádat opravu**; běh čekající na opravu podklady přijímá (odkaz **Otevřít mzdové běhy**) |
| **Uložit měsíční podklady** je neaktivní | Všechny řádky jsou uzavřené, nebo není co uložit | Viz řádek výše |
| Základní mzda není nabídnutá, pole čeká na ruční zadání | Chybí pracovní kalendář, o absenci se ještě nerozhodlo, nemoc nemá zmrazený výpočet náhrady, nebo si evidence odporuje | Doplňte kalendář a rozhodněte o absenci, nebo zadejte mzdu ručně |
| „Pracovní vztah trvá jen část měsíce. Zadejte potvrzenou základní mzdu ručně.“ | Nástup, ukončení nebo přerušení v průběhu měsíce | Zadejte skutečnou částku za zpracovávané období |
| „Hodiny lze použít až po schválení průměrného hodinového výdělku.“ | Chybí schválený průměr | Zadejte přesčas celkovou částkou, nebo nechte průměr schválit |
| Hodinový přesčas se nenabízí | Dohoda, společník, statutár, mzda sjednaná s přihlédnutím k přesčasu, nebo chybí kalendář | Zadejte celkovou částku |
| Pole s ikonou řetězu je jen ke čtení | Hodnotu spravuje jiný vstup (pravidelná složka, docházka) | Změňte ji v `Mzdy → Mzdové složky a vstupy` (odkaz **Otevřít mzdové vstupy**) |
| Příplatek je **Nedostupné** s důvodem „už vznikl ze schválené docházky“ | Vyplatil by se dvakrát | Upravte docházku, ne rychlý vstup |
| Příplatek za svátek je **Nedostupné** | U vztahu není sjednaná zásada pro svátek | Doplňte ji na kartě pracovního vztahu v části **Zásady zákonných příplatků (§ 114–118)** |
| Pole je červeně označené | Chybí hodnota, špatný formát, záporné číslo nebo překročený limit | Opravte pole podle zprávy u něj; ostatní řádky se uloží |
| Uložení selhalo, protože vstup mezitím změnil jiný uživatel | Souběžná změna | Klikněte na **Obnovit** (nebo **Obnovit aktuální podklady**), změny zkontrolujte a uložte znovu |
| „Mzdové vstupy za toto období se nenačetly.“ | Načtení selhalo | Nezadávejte vstupy znovu, nejdřív zopakujte načtení, ať nevzniknou duplicity |

## 79.8 Podrobnosti a pravidla

### 79.8.1 Co stránka ukazuje

U každého zaměstnance se zobrazí jméno, maskované rodné číslo, typ vztahu,
základní mzda nebo odměna ze vztahu, přesčas a bonus či další odměna.
Pracovní poměr, DPP, DPČ, závislý příjem společníka a odměna za výkon funkce
zůstávají v samostatných řádcích a nesloučí se. Štítek druhu vztahu a vlastní
popisek pole základu mají jen vztahy, které nejsou běžným pracovním poměrem
(DPP, DPČ, zaměstnání malého rozsahu, odměna za výkon funkce, příjem
společníka).

Hledání podle jména nebo osobního čísla prohledá celý měsíc, ne jen
zobrazenou stránku, a nezáleží na velikosti písmen ani na diakritice.
Stránkování i počet řádků se pak týkají jen výsledku. Hledaný text zůstává
v adrese stránky, takže ho lze sdílet odkazem. Rozepsané změny hledání
nezruší a uloží se i tehdy, když řádek aktuální hledání nevrací.

**Náhled hrubého příjmu** se přepočítává okamžitě. Další existující mzdové
vstupy jsou v něm zobrazené samostatně. Do náhledu vstupují všechny složky
zařazené jako zdanitelný příjem včetně nepeněžních. Osvobozené náhrady
a jiné složky mimo hrubý příjem se zobrazí zvlášť a do součtu se nepřičtou.
Složka s neuzavřeným daňovým zařazením vytvoří ruční kontrolu. Jde jen
o náhled hrubých složek; pojištění, daň, srážky a čistou mzdu spočítá až
mzdový běh.

**Součet za období** pod tabulkou sčítá všechny vztahy měsíce (při hledání
všechny nalezené), ne jen zobrazenou stránku, a započítá i rozepsané změny.
Hlavička, sloupec se jménem a součtový řádek zůstávají při posouvání na očích.

Upozornění, která by se opakovala u mnoha řádků, stojí jednou v pruhu nad
tabulkou: kolik řádků má hodnotu spravovanou jiným vstupem (s odkazem
**Otevřít mzdové vstupy**), u kterých lidí chybí rodné číslo (jméno otevře
kartu osoby) a u kolika vztahů nejde přesčas zadat v hodinách. Na počítači
stojí poslední věta v hlavičce sloupce **Přesčas**, v buňce je přepínač hodin
jen neaktivní. V řádku zůstává jen ikona stavu u pole (**Koncept**,
**Schváleno**, **Uzamčeno**, **Jiný vstup**) s vysvětlením v popisku.

Docházka není povinná. Formulář ukládá měsíční podklady samostatně pro každý
pracovní vztah a schválené ani uzamčené vstupy nikdy nepřepisuje.

### 79.8.2 Krácení měsíční mzdy o absence

Nabídnutá měsíční mzda je už zkrácená o evidované absence. Mzda přísluší za
vykonanou práci (§ 109 odst. 1 zákoníku práce), takže neodpracovaná doba do
ní nepatří. Poměr se počítá z naplánovaných hodin individuálního rozvrhu, ne
z počtu pracovních dnů: při nerovnoměrném rozvržení by deset zameškaných dnů
vyšlo stejně jako deset jiných dnů téhož měsíce, i když je za nimi jiný počet
hodin. Pod polem je vidět, kolik hodin bylo odečteno a z jakého měsíčního
fondu. Zkrácený úvazek se počítá ze svého vlastního fondu, ne z obecného
čtyřicetihodinového týdne.

Odečtené hodiny nezůstanou nezaplacené. Nahradí je vlastní složka podle
titulu: [náhrada za dovolenou](76_Absence_a_dovolena.md) podle § 222, náhrada
při dočasné pracovní neschopnosti podle § 192, nebo náhrada za jinou placenou
překážku. Dobu krytou dávkou nemocenského pojištění a neplacené volno
zaměstnavatel neplatí. Každá naplánovaná hodina je tak vyplacena právě jednou.

Svátek, který připadl na obvyklý pracovní den, měsíční mzdu nekrátí (§ 115
odst. 3 zákoníku práce): měsíční mzda ho pokrývá, a proto je součástí fondu,
ze kterého se krátí. Například v červenci 2026 (svátek 6. 7.) se odpracovává
176 hodin, ale mzda se krátí poměrem k fondu 184 hodin. Stejný fond platí pro
dosaženou mzdu za přesčas a hlásí se v měsíčním hlášení. Výjimkou je svátek
v době nemoci: za ten náleží náhrada podle § 192 odst. 1, takže se ze
základní mzdy odečte, aby nebyl zaplacen dvakrát. Svátek uvnitř celodenní
nepřítomnosti, za kterou mzda ani náhrada od zaměstnavatele nepřísluší
(mateřská, rodičovská, otcovská, ošetřovné, nemoc od 15. dne, neplacené
volno, neomluvená absence), se ze základní mzdy odečte také: zaměstnanec ten
den nepracoval kvůli nepřítomnosti, ne kvůli svátku. Ve svátek v době dovolené
nebo placené překážky se mzda nekrátí.

Když si aplikace jistá není, žádnou částku nenabídne a vyžádá ruční zadání:
chybí pracovní kalendář, o absenci v měsíci se ještě nerozhodlo, nemoc nemá
zmrazený výpočet náhrady, nebo si evidence odporuje. Nabídnout v takové
chvíli celou sjednanou mzdu by vypadalo hotově a nikdo by to už
nezkontroloval.

Při nástupu, ukončení nebo pozastavení v průběhu měsíce formulář
nepředvyplní plnou měsíční mzdu a vyžádá skutečnou částku za zpracovávané
období. Plný měsíční pravidelný předpis v takovém měsíci nepřevezme
automaticky; zůstane v ruční kontrole, dokud není doložené správné časové
rozpočítání. Historický měsíc zachová vztah, který byl tehdy účinný a později
archivován.

### 79.8.3 Přesčas: dosažená mzda a příplatek

Přesčas jde zadat celkovou částkou. Zadání v hodinách je dostupné jen tehdy,
když má vztah pro dané čtvrtletí schválený průměrný hodinový výdělek a pro
měsíc přiřazený pracovní kalendář. Bez nich aplikace hodinovou sazbu
neodhaduje a vyžádá celkovou částku. U závislého příjmu společníka, odměny za
výkon funkce, DPP a DPČ se hodinový přesčas nenabízí; použije se doložená
celková částka nebo odměna.

Přesčas zadaný hodinami se rozdělí na dva různé nároky:

- **dosažená mzda** za odpracované přesčasové hodiny (složka
  `MZDA_HODINOVA`). Počítá se z měsíčního základu děleného fondem hodin daného měsíce, ne
  paušální sazbou;
- **příplatek za práci přesčas** podle § 114 (složka `PRIPLATEK_PRESCAS`).
  Počítá se z doloženého
  průměrného výdělku sazbou z legislativní sady, takže se propíše i sazba
  sjednaná výš než zákonné minimum.

Rozdělení není kosmetika: z mzdového listu musí být vidět, čím byl nárok na
příplatek uspokojen (§ 142 odst. 5 zákoníku práce).

Je-li mzda sjednána s přihlédnutím k práci přesčas (§ 114 odst. 3), přesčas
hodinami zadat nelze, protože příplatek ani náhradní volno nepřísluší. Je-li
u vztahu sjednáno náhradní volno místo příplatku, uloží se jen dosažená mzda
a příplatková část je nulová.

Máte-li přesčas v rychlém vstupu, schválení měsíce docházky s přesčasovými
hodinami se zastaví, a naopak.

### 79.8.4 Zákonné příplatky a další složky

Částku příplatku za noční práci (§ 116), sobotu a neděli (§ 118), práci ve
svátek (§ 115) a ztížené prostředí (§ 117) dopočte aplikace ze schváleného
průměrného výdělku (u § 117 ze základní sazby minimální mzdy) a ze sjednané
sazby. Příplatek, který za měsíc už vznikl ze schválené
[docházky](77_Dochazka_a_smeny.md#7795-zakonne-priplatky-ke-mzde-114-az-118),
tady ručně zadat nejde, protože by se vyplatil dvakrát. Za práci ve svátek
náleží podle § 115 odst. 1 náhradní volno; příplatek jen tehdy, byl-li
sjednán. Stav přepínače **Zadat i příplatky** si aplikace pamatuje; drží-li
některý řádek v měsíci příplatek, sloupce se ukážou samy. Platí-li stejný
důvod nedostupnosti pro víc řádků, vypíše se jednou v pruhu nad tabulkou.

**Další mzdové složky.** Ve výchozím stavu jsou v nabídce **Složky**
zaškrtnuté jen zákonné příplatky. Zaškrtnutím přidáte sloupec pro kteroukoli
další mzdovou složku firmy, třeba `PRIPLATEK_BOZP` z importu docházky. Složky jsou
v nabídce seskupené podle druhu (příplatky a prémie, odměny, hodinová mzda…)
a u každé je vidět, u kolika lidí má v měsíci hodnotu. Volba se pamatuje pro
přihlášeného uživatele.

Zadat lze jednorázové peněžní složky s uzavřeným zdaněním: příplatky, prémie,
odměny, provize, hodinovou a úkolovou mzdu a ostatní. Pravidelné složky,
benefity, náhrady a cestovné nabídka označí **jen přehled**; sloupec je ukáže,
ale mění se v Mzdových vstupech. Základ, přesčas, bonus a zákonné příplatky
mají svá pevná pole, vlastní sloupec nemají.

Buňka ukazuje částku a ikonu zdroje:

- **šipka dolů**: hodnota z importu, například z docházky;
- **tužka**: ruční přepis importované hodnoty;
- **řetěz**: hodnotu spravuje jiný vstup (pravidelná složka, docházka nebo
  více vstupů) a buňka je jen ke čtení. Ikona vede do mzdových složek
  daného vztahu.

### 79.8.5 Importované hodnoty a ruční přepis

Úprava importované hodnoty je ruční přepis. Importní vstup se nemaže ani
nemění. Zůstane jako doklad dávky ve stavu zrušeno a vedle něj vznikne ruční
vstup téže složky a téhož období. Do mzdového běhu i do náhledu jde vždy jen
jeden z nich, takže se částka nikdy nezapočte dvakrát. **Vrátit na hodnotu
z importu** přepis při uložení zruší a platnou se znovu stane hodnota
z importu. Přepsat na nulu jde (zadejte 0); prázdné pole importovanou hodnotu
nesmaže. Uzamčenou hodnotu, kterou už zpracoval mzdový běh, lze změnit jen
opravnou revizí. Schválenou hodnotu může přepsat nebo vrátit jen uživatel
s právem schvalovat mzdové vstupy.

Opakovaný import hodnoty nepřepisuje naslepo. Stejná hodnota je duplicita.
Změněná hodnota rozpracovaného vstupu se aktualizuje na místě a druhý vstup
nevznikne. Schválený vstup import nezmění a rozdíl ohlásí. Ručně přepsanou
hodnotu import ponechá a ve výsledku napíše, kolik hodnot je ručně
přepsaných. Novou hodnotu z importu si přitom zapamatuje, takže **Vrátit na
hodnotu z importu** vrátí tu z poslední dávky.

### 79.8.6 Ukládání, schvalování a stavy

Hromadné uložení vytváří běžné vstupy složek `MZDA_MESICNI`,
`PREMIE_PRIPLATKY`, `ODMENA` a zvolených dalších složek, takže nevzniká souběžná
evidence mezd. Opakované uložení stejného měsíce nevytvoří duplicity.

Uložení a schválení je jeden krok. Má-li přihlášený uživatel právo mzdové
vstupy schvalovat, uloží se rozepsané řádky rovnou jako schválené a mzdový
běh je bez dalšího zásahu přebere. Uživatel bez tohoto práva ukládá koncepty,
které někdo se schvalovacím oprávněním potvrdí později, buď po jednom, nebo
hromadně v mzdových vstupech tlačítkem **Schválit N odpovídajících filtru**.
Ani u pěti set zaměstnanců tedy nemusíte schvalovat řádek po řádku.

Ukládá se celá rozepsaná sada, ne jen zobrazená stránka. Rozepsané změny
přežijí přechod na další stránku a uložení se posílá po dávkách, takže
funguje i pro stovky zaměstnanců. Selhání jednoho řádku neshodí uložení
ostatních. Chybný řádek se vrátí s červeným označením konkrétního pole
a důvodem u něj; ostatní řádky se uloží.

Rozpracované vstupy se mění s kontrolou jejich verze; už zpracovaný nebo
uzamčený vstup formulář nikdy nepřepíše. Kontroluje se i verze pracovního
vztahu, takže po souběžné změně smlouvy formulář vyžádá obnovení.

V otevřeném běhu se uložený vstup projeví po přepočtu. Po uzavření nelze
změnou vstupu přepsat archivovaný výsledek; je nutný opravný postup.

### 79.8.7 Uzavřená mzda

Je-li vztah v mzdovém běhu za měsíc, který je schválený, zaúčtovaný,
připravený k úhradě, vyplacený nebo uzavřený, je jeho řádek jen ke čtení
a u jména nese štítek **Mzda uzavřena**. Nad tabulkou se zobrazí upozornění
s odkazem na mzdové běhy; takový řádek se neukládá a aplikace by ho stejně
odmítla. Jsou-li uzavřené všechny řádky, **Uložit měsíční podklady** je
neaktivní. Změnu zadáte až po **Vyžádat opravu** u mzdového běhu. Totéž
platí pro ruční zadání v Mzdových vstupech, vytvoření vstupů z pravidelných
složek a první promítnutí vyúčtování pracovní cesty.

### 79.8.8 Formát a kontroly

Částky zadávejte v Kč s nejvýše dvěma desetinnými místy, hodiny s nejvýše
třemi. Prázdná hodnota neznamená nulu; pokud složka v měsíci není, zadejte
**0**. Formulář označí konkrétní chybné pole a rozliší chybějící hodnotu,
neplatný formát, záporné číslo a překročení podporovaného rozsahu (částka do
10 miliard Kč, přesčas do 1 000 hodin, příplatek do 744 hodin, ztěžujících
vlivů 1 až 255). Pokud se uložení nepodaří například proto, že stejný vstup
mezitím změnil jiný uživatel, přesný důvod zůstane viditelný nad formulářem.

Před uložením kontrolujte období, souběžný vztah, znaménko, jednotku
a duplicity. Hromadné zadání zvyšuje riziko záměny osoby, proto porovnejte
součet se zdrojovým podkladem. Časté chyby: vstup v jiném měsíci nebo
u jiného vztahu, duplicitní ruční zadání už importované částky, jednorázová
částka vložená do pravidelné složky a oprava vstupu bez nového výpočtu.
Zdrojovou přílohu nesdílejte mimo oprávněný okruh.

## 79.9 Související kapitoly

- [Úplné mzdy](75_Uplne_mzdy.md): pořadí kroků mzdového měsíce
- [Mzdové složky a vstupy](91_Mzdove_slozky_a_vstupy.md): význam složek, pravidelné vstupy, hromadné schválení
- [Docházka a směny](77_Dochazka_a_smeny.md): příplatky ze schválené docházky
- [Absence a dovolená](76_Absence_a_dovolena.md): absence, které krátí mzdu
- [Mzdové běhy](80_Mzdove_behy.md): výpočet ze zadaných vstupů
- [Mzdové příkazy a úhrady](82_Platby_a_uhrady.md): výplata
