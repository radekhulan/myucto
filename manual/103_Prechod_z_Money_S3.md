# 103. Přechod z Money S3

> Návod, jak převést účetní agendu z Money S3 do firmy v MyÚčtu: od zálohy
> agendy v Money přes zkoušku nanečisto a ostrý převod až po kontrolu převzatých
> dat. Pro účetní a správce, kteří přecházejí z Money S3.

**Cesta: `Systém → Přechod z jiných účetních systémů → Money S3`**

## 103.1 Kdy to potřebujete

- Firma dosud účtovala v Money S3 a chce pokračovat v MyÚčtu s kompletním
  deníkem, doklady, bankou a pokladnou.
- Převádíte víc firem najednou (účetní kancelář, skupina firem).
- Převod skončil chybou nebo rozdílem v protokolu a potřebujete vědět, co dál.
- Chcete po převodu ověřit, že MyÚčto sedí na Money na haléř.

<!-- cols: 24 40 36 -->
| Fáze | Co udělat | Kde |
|---|---|---|
| 1 | Zálohovat agendu v Money S3 | Money S3, funkce *Zálohovat agendu* |
| 2 | Nahrát zálohu a zkontrolovat náhled | `Systém → Přechod z jiných účetních systémů → Money S3`, krok **Záloha agendy** |
| 3 | Zkouška nanečisto | krok **Zkouška nanečisto** |
| 4 | Ostrý převod | krok **Převod** |
| 5 | Kontrola převzetí | protokol, `Účetnictví`, obratová předvaha |
| 6 | Navázání číselné řady, skeny dokladů, zaměstnanci | `Nastavení`, `Dokumenty`, `Mzdy` |

## 103.2 Než začnete

1. **Podvojné účetnictví, nebo daňová evidence v Money.** Agendu podvojného
   účetnictví převod převede s účetním deníkem. Agendu, kterou Money vede jako
   daňovou evidenci (peněžní deník), převede do daňové evidence firmy, viz
   [§ 103.10.10](#1031010-danova-evidence).
2. **Cílová firma.** Vyberte (nebo založte) firmu v MyÚčtu, do které se bude
   převádět. Agenda podvojného účetnictví přepne firmu, která v MyÚčtu dosud
   vede daňovou evidenci, na podvojné účetnictví. Agendu daňové evidence převod
   převede jen do firmy v daňové evidenci a její režim nemění. Má-li se skupina
   firem sdílet jako projekty, založte skupinu před převodem.
3. **Účetní rok.** Vede-li firma v Money hospodářský rok, nastavte před
   převodem v `Firma → Nastavení` na záložce **Daně a účetnictví** pole
   **Účetní rok začíná** na měsíc, kterým rok v Money začíná. Každý účetní rok Money se převede jako jedno
   účetní období firmy (například srpen 2024 až červenec 2025 jako období 2024).
4. **Oprávnění.** Zkoušku nanečisto spustí uživatel s oprávněním k zápisu
   importů. Ostrý převod zapisuje účetní deník, mění nastavení firmy a
   uzavírá roky, proto vyžaduje navíc zápis do účetního deníku, do nastavení
   firmy a uzavírání období. Chybějící oprávnění průvodce ukáže a převod
   nespustí. Položka je v menu Systém, které vidí administrátor; jiný
   uživatel otevře průvodce přímým odkazem `/imports/money-s3`.
5. **Klid ve firmě.** Zkouška nanečisto drží zámky převáděných období,
   spouštějte ji mimo běžnou práci.
6. **Volné místo na disku** pro zálohu (až 4 GB).

> [!TIP]
> Převod je určen pro menší množství dat. Převody z jiných systémů jejich
> výrobci nepodporují a export se mezi verzemi liší. Průvodce nabízí
> kontakt na podporu, která převod provede nebo upraví na míru. Převzatá data
> si ověřte vždy.

## 103.3 Krok za krokem: záloha agendy v Money S3

1. V Money S3 otevřete agendu firmy, kterou chcete převést.
2. Zvolte funkci *Zálohovat agendu* a uložte soubor. Money vytvoří jeden
   soubor s příponou `.lz`, jehož jméno obsahuje IČO firmy a číslo agendy,
   například `12345678ag001.lz`. Jedna záloha obsahuje všechny roky agendy.
3. Soubor nerozbalujte. Přijímá se i stejný soubor s příponou `.zip`.
4. Chcete-li při kontrole porovnat výsledek se sestavou z Money, vyexportujte
   z Money ke každému převáděnému roku obratovou předvahu do CSV (účet v prvním
   sloupci, dál PS MD, PS D, obrat MD, obrat D, KS MD, KS D).

**Jak poznáte, že je hotovo:** Máte soubor `.lz` (případně CSV s předvahami).
Instalace Money se zálohou nijak nemění.

## 103.4 Krok za krokem: nahrání zálohy a zkouška nanečisto

1. Otevřete `Systém → Přechod z jiných účetních systémů → Money S3`.
2. V kroku **Záloha agendy** klikněte na **Soubor zálohy agendy (.lz)**,
   vyberte soubor a klikněte na **Nahrát a načíst**. Stránku nechte během
   nahrávání otevřenou. Průvodce ukazuje procenta a při výpadku spojení naváže.
3. V kroku **Náhled a volby** zkontrolujte agendu (**IČO**, **DIČ**, **Sídlo**,
   **Verze Money**, **Záloha pořízena**) a tabulku **Účetní roky v záloze**.
   Roky jsou účetní roky firmy; u hospodářského roku rok, ve kterém účetní rok
   začíná, s hranicemi období (například 2024 (1. 8. 2024 – 31. 7. 2025)).
   Část **Kontrola před převodem** musí ukázat, že je záloha v pořádku.
4. Nastavte **Volby převodu**:
   - **Převést od roku** (výchozí je rok, od kterého navazují stavy; **Všechny
     roky zálohy** převede celou zálohu; rokem je účetní rok firmy jako
     v tabulce let),
   - **Uzavřít historické roky průvodcem uzávěrkou** (výchozí zapnuto),
   - **Začátek prvního účetního období** jen u firmy založené během prvního
     převáděného roku,
   - volitelně sestavy z Money v části **Sestavy z Money ke kontrole**
     (**Nahrát předvahu {rok}**; předvaha patří k účetnímu roku firmy, se
     kterým ji porovná rekonciliace).
5. Klikněte na **Pokračovat**.
6. V kroku **Zkouška nanečisto** klikněte na **Spustit zkoušku nanečisto**.
   Proběhne celý převod včetně uzávěrky a rekonciliace, na konci se ale
   všechno vrátí. V MyÚčtu nezůstane nic.
7. Přečtěte protokol zkoušky. Chyby opravte a zkoušku zopakujte (**Zkoušku
   zopakovat**).

**Jak poznáte, že je hotovo:** Zkouška skončí stavem **V pořádku** nebo
**S upozorněními** a rekonciliace nehlásí chybu. Selže-li zkouška jen na
**Rozdílech k přijetí**, rozhodnete se podle [§ 103.10.5.1](#1031051-chyby-upozorneni-a-rozdily-k-prijeti).

Kontrola před převodem zastaví převod, když se agenda v Money z podvojného
účetnictví vrátila do daňové evidence, záloha patří firmě s jiným IČO, účetní období v MyÚčtu už obsahuje zápisy, které nevznikly převodem, období je
uzavřené, nebo by se účetní rok Money rozdělil do dvou účetních období firmy
(agenda vede jiný účetní rok, než má firma nastavený; hlášení navrhne měsíc
pro nastavení **Účetní rok začíná**). Chybí-li
IČO v záloze nebo ve firmě, zkouška jen upozorní a ostrý převod se spustí až po
výslovném potvrzení. U agendy daňové evidence kontrola projde peněžní deník
a zastaví převod z důvodů v [§ 103.10.10](#1031010-danova-evidence).

## 103.5 Krok za krokem: ostrý převod

1. V kroku **Převod** zaškrtněte potvrzení, že rozumíte dopadu převodu
   (u neověřeného IČO i potvrzení, že záloha patří této firmě).
2. Po zkoušce, která selhala jen na rozdílech k přijetí, zaškrtněte navíc
   **Převést i přes rozdíly** (viz [§ 103.10.5.1](#1031051-chyby-upozorneni-a-rozdily-k-prijeti)).
3. Klikněte na **Spustit převod**. Převod běží na pozadí, stránku můžete
   zavřít. Průběh ukazuje počty zápisů, už převedených a chyb. Převod můžete
   zastavit tlačítkem **Zastavit převod**.
4. Po dokončení klikněte na **Otevřít účetní deník** nebo **Obratová předvaha**.

**Jak poznáte, že je hotovo:** Převod skončí stavem **V pořádku** nebo **S
upozorněními**. Protokol je v přehledu **Protokoly převodů** pod průvodcem. Převod
jedné firmy běží vždy jen jeden, druhý se do jeho konce nespustí.

## 103.6 Krok za krokem: kontrola převzetí

1. Otevřete protokol ostrého převodu. U každého roku zkontrolujte rekonciliaci:
   obratová předvaha MyÚčta proti předvaze z deníku Money (na haléř), případně
   proti nahrané sestavě z Money; obraty MD = D, předvaha = deník, vyrovnané
   počáteční stavy; doklady proti deníku (přijaté faktury proti 321, vydané
   proti 311, pokladna proti 211, banka proti 221).
2. Projděte upozornění a **Rozdíly k přijetí**. Každý nepřevedený doklad
   doplňte ručně, nebo opravte příčinu a převod zopakujte (doplní jen to,
   co chybí).
3. V `Účetnictví` otevřete obratovou předvahu a porovnejte syntetické účty
   s výstupy Money.
4. Doklady označené v protokolu jako **doklad bez zápisu** zaúčtujte v
   `Účetnictví → Doúčtovat doklady`. Koncepty k ruční kontrole (zálohové
   faktury, dobropisy, doklady s nejistým členěním DPH) opravte a potvrďte.
5. Zkontrolujte kroky **Dlouhodobý majetek** a **Drobný majetek** (porovnání
   karet se zůstatky účtů) a krok **Mzdy** (kontrolní úhrny po měsících,
   návrh kontací).
6. Zkontrolujte, zda se historické roky uzavřely. Rok, jehož konečné stavy
   nesedí na počáteční stavy dalšího roku, zůstává otevřený (viz
   [§ 103.10.6](#103106-uzaverka-historickych-let)).
7. Doplňte, co se nepřevádí: skeny dokladů v `Dokumenty → Skeny k dokladům`,
   zaměstnance importem podání JMHZ v `Mzdy → Importy`.

**Jak poznáte, že je hotovo:** Všechny kontroly K1 až K4 (viz
[§ 103.10.9](#103109-davkovy-prevod-vice-firem)) projdou, nezbývají doklady bez
zápisu ani nepřijaté rozdíly a obraty v MyÚčtu odpovídají Money.

## 103.7 Krok za krokem: navázání číselné řady

Převod přenáší doklady s čísly z Money S3, ale počítadlo nové řady nenastaví.

1. Otevřete `Nastavení → Doklady → Číslování faktur` a vyberte šablonu řady.
2. Do pole **Příští číslo** zadejte číslo, kterým má řada pokračovat.
3. Klikněte na **Nastavit počítadlo**. Ukládá se samostatně, mimo tlačítko
   **Uložit**, a po potvrzení se ukáže náhled výsledného čísla.

**Jak poznáte, že je hotovo:** Náhled ukazuje očekávané příští číslo.

> [!WARNING]
> Zkontrolujte, že perioda resetu sedí se šablonou: u masky bez `{MM}` a
> měsíčního resetu by počítadlo prvního dne dalšího měsíce spadlo zpátky na
> začátek a čísla by kolidovala. Viz
> [§ 95.6](95_Multi_supplier.md#956-krok-za-krokem-cislovani-faktur).

## 103.8 Krok za krokem: dávkový převod více firem

1. V průvodci otevřete záložku **Dávka více firem** a vyberte zálohy `.lz`
   všech firem najednou.
2. Zkontrolujte u každé firmy IČO, název, roky, doporučený rok „od" a cílovou
   firmu. Zvolte **Firma už v MyÚčtu je** (převést, nebo přeskočit).
3. Volitelně přiložte EPO XML podaných přiznání k DPPO, zvolte skupinu firem
   a **Firmy dávky jsou spřízněné osoby** (jen pro skupinu, ne pro nezávislé
   klienty).
4. Spusťte zkoušku nanečisto, pak ostrý převod. Dávka běží jako jeden úkol na
   pozadí.
5. V protokolu dávky zkontrolujte u každé firmy stav a kontroly K1 až K4.

**Jak poznáte, že je hotovo:** U každé firmy je stav převedena nebo
přeskočena a kontroly K1 až K4 jsou v pořádku. Podrobný protokol firmy je v
záložce **Jedna firma**.

Pravidla dávky včetně příkazové řádky jsou v
[§ 103.10.9](#103109-davkovy-prevod-vice-firem).

## 103.9 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Kontrola před převodem hlásí chybu | Jiné IČO, zápisy v období, uzavřené období, nebo agenda vede jiný účetní rok než firma | Opravte příčinu a nahrajte zálohu znovu; u účetního roku nastavte ve firmě měsíc z hlášení (**Účetní rok začíná**) a převod spusťte znovu |
| Kontrola před převodem hlásí, že peněžní deník nesedí s bankou nebo pokladnou | Řádky peněžního deníku Money nesedí s bankovními nebo pokladními doklady, nebo odkazují na doklad, který v záloze není | Opravte doklad v Money, zálohujte agendu znovu a nahrajte ji |
| Kontrola před převodem hlásí, že firma vede podvojné účetnictví | Agenda vede v Money daňovou evidenci, firma v MyÚčtu převáděné roky jako podvojné účetnictví | Převeďte agendu do firmy v daňové evidenci; převod režim firmy nemění |
| Kontrola před převodem hlásí, že Money vede od roku znovu daňovou evidenci | Firma v Money přešla z podvojného účetnictví zpět na daňovou evidenci | Převod by firmu přepnul do podvojného účetnictví i pro roky daňové evidence, proto ji nepřevede |
| Import výpisu z banky hlásí, že pohyby do dne přeskočil | Pohyby účtu do toho dne převod převzal z Money | Pohyb z toho dne, který v Money nebyl, doplňte ručně; další výpisy importujte od následujícího dne |
| Nahrávání se přerušilo | Výpadek spojení | Vyberte soubor a nahrajte ho znovu |
| Zkouška selhala jen na rozdílech k přijetí | Doklad se nepřevedl nebo výsledek nesedí na Money | Zaškrtněte **Převést i přes rozdíly**, nebo rozdíl opravte a zkoušku zopakujte |
| Rok se neuzavřel | Konečné stavy nesedí na počáteční stavy dalšího roku | Rok zůstává otevřený; důvod je v protokolu, rozdíl opravte a převod zopakujte |
| Doklad je koncept | Zálohová faktura, dobropis, stornovaný doklad nebo členění DPH mimo tuzemské řádky | Opravte druh dokladu nebo klasifikaci DPH a potvrďte ho |
| Číslo dokladu má příponu roku (např. `FP001/2025`) | Číslo už ve firmě existuje; Money čísluje řady každý rok od začátku | Není chyba |
| Chybí zaměstnanci | Převod osoby a mzdy zaměstnanců nepřebírá | Importujte podání JMHZ v `Mzdy → Importy` |
| Chybí přílohy a archiv dokumentů | Money je ukládá šifrovaně | Připojte skeny v `Dokumenty → Skeny k dokladům` |
| Kontrola K1 hlásí rozdíl pod 1,00 Kč | Zaokrouhlení dokladů ve zdroji | Je to upozornění, kontrola platí |
| Je potřeba převést znovu od začátku | Firma byla smazána | Před smazáním stáhněte profil firmy a po převodu ho nahrajte zpět ([§ 103.10.8](#103108-opakovany-prevod)) |

## 103.10 Podrobnosti a pravidla

### 103.10.1 Soubor zálohy agendy a oprávnění

Průvodce převede účetní agendu z Money S3 do firmy v MyÚčtu. Vstupem je záloha
agendy, kterou si firma nebo účetní vytvoří v Money funkcí *Zálohovat agendu*
(soubor `.lz`). Záloha se jen čte, instalace Money se převodem nijak nemění.

Položka je v menu Systém, které vidí administrátor. Jiný uživatel s potřebnými
oprávněními otevře průvodce přímým odkazem `/imports/money-s3`.

#### 103.10.1.1 Co je soubor zálohy agendy

- Záloha agendy je jeden soubor s příponou `.lz`, který Money S3 vytvoří
  funkcí *Zálohovat agendu*. Jméno obsahuje IČO firmy a číslo agendy, například
  `12345678ag001.lz`.
- Uvnitř je obyčejný ZIP s datovými soubory agendy (`*.DAT`), rozdělenými po
  účetních rocích, a se základními údaji o agendě (název, IČO, verze Money).
  Jedna záloha obsahuje všechny roky agendy.
- Soubor nahrajte tak, jak ho Money vytvořilo, nerozbalený. Přijímá se i stejný
  soubor s příponou `.zip`.
- Záloha může mít až 4 GB. Průvodce ji posílá po částech a ukazuje průběh
  v procentech; při výpadku spojení část zopakuje a naváže tam, kde server
  data má. Stránku nechte během nahrávání otevřenou.
- Elektronický archiv dokumentů z Money záloha nepřenese (Money ho ukládá
  šifrovaně). Skeny dokladů připojíte zvlášť v `Dokumenty → Skeny k dokladům`.
- Záloha obsahuje celé účetnictví firmy. Nahraný soubor aplikace po 24 hodinách
  bez práce s převodem sama smaže.

Průvodce vidí a zkoušku nanečisto spouští uživatel s oprávněním
`utilities.import` pro zápis. Ostrý převod zapisuje účetní deník, mění nastavení
firmy a uzavírá roky, proto navíc vyžaduje zápis do účetního deníku
(`accounting.journal.write`) a do nastavení firmy (`settings.company.write`),
s uzávěrkou historických let i uzavírání období (`accounting.periods.close`).
Chybějící oprávnění průvodce ukáže a převod nespustí.

Průvodce je dostupný i firmě, která v MyÚčtu zatím vede daňovou evidenci: agenda
podvojného účetnictví ji převodem přepne do podvojného účetnictví. Agendu, kterou
Money vede jako daňovou evidenci, převod převede do daňové evidence firmy a režim
firmy nemění, viz [§ 103.10.10](#1031010-danova-evidence).

### 103.10.2 Co převod přenese

| Z Money | Do MyÚčta |
|---|---|
| účtový rozvrh (jen účty, na které se účtovalo) | analytiky pod syntetiky osnovy, `042000` → `042.000` |
| účetní roky | účetní období |
| účetní deník včetně počátečních stavů | účetní zápisy, počáteční stavy jako otevírací zápis k 1. dni období |
| středisko na řádku deníku | dimenze Středisko a středisko na řádku zápisu, viz níže |
| zakázka na řádku deníku | dimenze Projekt nebo Vozidlo na řádku zápisu a v hlavičce dokladu, viz níže |
| adresář a bankovní spojení partnerů | klienti a jejich bankovní účty |
| předkontace | pravidla zaúčtování se zkratkou z Money |
| přijaté a vydané faktury | doklady se stavem zaúčtováno nebo uhrazeno, položka na každou sazbu DPH; doklad nejisté daňové povahy jako koncept k ruční kontrole |
| pokladny a pokladní doklady | pokladny a zaúčtované pokladní doklady |
| bankovní účty a bankovní doklady | výpis na účet a rok se zdrojem „import", bankovní pohyby |
| úhrady faktur | spárování faktury s bankovním pohybem nebo pokladním dokladem |
| karty dlouhodobého majetku a jejich pohyby | karty majetku s počátečními stavy, technickými zhodnoceními a odpisy převedených let, viz níže |
| karty drobného majetku | evidence drobného majetku včetně vyřazených karet |

#### 103.10.2.1 Majetek

Převod přebírá evidenci majetku Money (karty a jejich pohyby). Zařazení, odpisy
i vyřazení jsou už v převedeném deníku, karta proto vzniká bez zápisu v deníku.

- **Karta dlouhodobého majetku** přebírá název, inventární číslo, datum zařazení,
  majetkový a oprávkový účet a daňovou skupinu a způsob odpisu (Z zrychlený,
  N rovnoměrný). Vstupní cena je cena na začátku prvního převáděného roku,
  pozdější zvýšení ceny a technická zhodnocení jsou technická zhodnocení karty.
- **Účetní odpisy** před prvním převáděným rokem jsou počáteční stav karty,
  odpisy převedených let se zapíší přesně podle Money jako zaúčtované převzatým
  deníkem. Hromadné účtování odpisů je znovu neúčtuje a plán naváže dalším měsícem.
- **Daňové odpisy** hmotného majetku Money na kartách neukládá, počítá je
  z parametrů karty. Převod je stejně spočte podle zákona o daních z příjmů
  a uzavřené roky zapíše jako potvrzené. Otevřený rok dostane daňový odpis až
  uzávěrkou. Nehmotný majetek se daňově odpisuje podle účetních odpisů.
- **Neodpisovaný majetek** (pozemky, skupina N) se převede bez odpisů.
- **Vyřazená karta** z převedených let se převede jako vyřazená. Karta vyřazená
  před prvním převáděným rokem se nepřevádí.
- **Pomocná karta** bez majetkového účtu (Money na ní počítá například daňové
  odpisy k majetku vedenému jinde) se nepřevádí, protokol ji vypíše. Karta
  „jen ÚČETNÍ odpis" se převede jen s účetními odpisy.
- **Snížení ceny** (dotace, dobropis) z převedených let se zapíše jako záporné
  technické zhodnocení a karta zůstane konceptem ke kontrole daňové vstupní ceny.
- **Drobný majetek** přebírá název, inventární číslo, datum pořízení, cenu,
  dodavatele a umístění, vyřazené karty s datem vyřazení.

Na konci kroku převod porovná karty se zůstatky majetkových a oprávkových účtů
po syntetikách. Rozdíl, který je už v evidenci Money (majetek účtovaný bez karty),
protokol označí zvlášť.

#### 103.10.2.2 Mzdy

Zápisy mezd jsou v převedeném deníku a znovu nevznikají. Z mzdových dokladů
(závazky a interní doklady mzdového modulu Money) převod v kroku **Mzdy** udělá
tři věci:

- **Návrh kontací mezd.** Z mzdových zápisů posledního převáděného roku odvodí,
  které účty firma používá pro hrubé mzdy, pojistné, daň a srážky, a uloží je jako
  návrh v Mzdy → Importy → **Kontace mezd** (viz
  [§ 108.9.11](108_Prechod_z_PAMICA.md#108911-kontace-mezd-z-puvodniho-programu)).
  Nastavení mezd se nemění, dokud návrh nepotvrdíte. Význam zápisu se bere
  z druhu mzdového dokladu, který Money u novějších dokladů vede (sociální,
  zdravotní pojištění, daň, srážky…); starší doklady bez druhu se zařadí podle
  páru účtů. Analytiku účtu 336 pro sociální a zdravotní pojištění pozná převod
  podle dokladů s druhem, jinak podle názvu účtu. Co zařadit nejde, je v návrhu
  jen v přehledu.
- **Kontrolní úhrny po měsících.** Protokol ukáže za každý měsíc mezd součty
  celé firmy: hrubé mzdy, pojistné zaměstnanců a zaměstnavatele, zálohovou
  a srážkovou daň, srážky a čistou mzdu k výplatě. Zálohovou daň porovná
  s měsíčním vyúčtováním daně z příjmů ze závislé činnosti, které Money vede
  zvlášť (sražené zálohy po přeplatcích z ročního zúčtování); měsíc, kde nesedí,
  vyznačí. Úhrny jsou kontrola, ne převzaté mzdy jednotlivých zaměstnanců.
- **Zapnutí modulu Mzdy.** Firmě, která mzdy vede, převod zapne modul Mzdy
  a nastaví začátek vedení mezd v MyÚčtu na měsíc po posledním mzdovém dokladu.
  Chybí-li nastavení zaměstnavatele, založí ho s mzdovou účtárnou `MZDY`
  a výchozími předkontacemi. Variabilní symbol ČSSZ, kód OSSZ a číslo plátce
  zdravotního pojištění, které firma vede v Nastavení firmy, převezme do Mezd
  a k variabilnímu symbolu založí registraci účtárny s účinností od začátku
  vedení mezd (viz [§ 90.14.1](90_Nastaveni_mezd.md#90141-mzdove-uctarny-a-registrace-u-cssz)).
  Co v Nastavení firmy není, ani účty institucí převod nevymýšlí; protokol
  vypíše k doplnění v Mzdy → Nastavení jen to, co opravdu chybí. Zapnutý
  modul, jeho začátek ani existující nastavení převod nemění. Firmě, jejíž mzdy
  skončily víc než rok před koncem převáděných dat, se modul nezapíná.

**Zaměstnanci se nepřevádějí.** Novější verze Money vedou osoby a mzdy
jednotlivých zaměstnanců v šifrované databázi agendy, kterou převod přečíst
nemůže. Starší čitelné tabulky mzdového modulu v záloze (u agend vedených dlouho
končí typicky rokem 2020) převod také nepřebírá: historie osob se nepřevádí.
Zaměstnance i historii jejich mezd převezměte importem přijatých podání JMHZ a registrací
v Mzdy → Importy, viz [§ 90.14.10](90_Nastaveni_mezd.md#901410-import-jmhz-registrace-a-mesicni-hlaseni).

**Zaúčtování se nepřepočítává.** Deník je přesná kopie toho, co bylo v Money,
a doklady se k němu jen připojí. Z dokladu je proto vidět jeho zápis a naopak,
detail faktury ukazuje úhradu jako zaúčtovanou a automatika už doklad znovu
nezaúčtuje.

**Střediska a zakázky jako dimenze.** Převod u firmy zapne
[Dimenze](114_Dimenze.md) a středisko i zakázku z deníku Money převede na
hodnoty dimenzí. Doklad dostane hodnotu do hlavičky, když všechny jeho řádky
s daným typem nesou tutéž; hlavičku, kterou už někdo vyplnil, převod nemění.

- Středisko z Money je hodnota firemní dimenze **Středisko**. Založí se i
  v `Nástroje → Střediska` a kód střediska zůstane na řádcích zápisů.
- Zakázka ve tvaru registrační značky (`1AB 2345`, `3CD4567`, `EL123AB`) je
  hodnota firemní dimenze **Vozidlo**. Je-li vůz v knize jízd, hodnota se na
  něj naváže a nese jeho název. Různé zápisy téže značky (`5E6 7890`, `5E67890`)
  se sloučí do jedné hodnoty.
- Ostatní zakázky jsou hodnoty dimenze **Projekt**. Patří-li firma do skupiny
  firem, je Projekt globální: stejný kód zakázky v mateřské firmě i v SPV je
  jeden projekt a výsledovka po dimenzi ho sečte přes všechny firmy skupiny.
  Skupinu je proto dobré založit před převodem.
- Hodnota, kterou poslední převáděný rok nepoužil, se založí jako uzavřená.
  Hodnotu, kterou poslední rok použil, převod znovu otevře.
- Fakturační zakázky (`Zakázky`) ani klienty převod ze zakázek Money nezakládá.
- Činnost z Money se nepřevádí.

Dimenze se doplní před uzavřením převáděného roku. V již uzavřeném roce opakovaný
převod zachová uložené středisko, projekt, vozidlo i rozpady. Pokud nová záloha
obsahuje jiné hodnoty, protokol na zachované dimenze upozorní. Shodný opakovaný
převod toto upozornění nevytváří. V otevřeném období platí Money: opakovaný převod
přepíše ruční změnu spravovaných dimenzí.

**Popisy zápisů se dogenerují.** Money veze v řádku deníku jen pole `Popis`, které
je u celé řady dokladů shodné. Po navázání dokladů proto převod popisy přeskládá do
tvaru **doklad - protistrana - obsah**, aby se zápisy v deníku daly rozlišit; v protokolu
to uvidíte jako *„U N převedených zápisů se popis doplnil o číslo dokladu a protistranu."*
Částek, účtů ani dat se to nedotýká a jde to kdykoli zopakovat - viz
[§ 52.14.13.1](52_Ucetni_denik.md#5214131-dogenerovani-popisu-u-prevzatych-zapisu).

**Doklady k ruční kontrole.** Fakturu, jejíž daňovou povahu záloha Money
spolehlivě neurčuje, převod převezme jako koncept:

- zálohovou fakturu, proformu a daňový doklad k platbě (jiný druh než běžná faktura),
- dobropis, stornovaný doklad a doklad, který je v Money označený „neúčtovat",
- doklad s členěním DPH mimo tuzemské řádky přiznání: přenesená daňová
  povinnost, plnění z EU a do EU, zvláštní režimy, nebo doklad s daní bez členění.

Koncept nevstoupí do přiznání k DPH, kontrolního hlášení ani do účtování.
Protokol ho vypíše i s důvodem. Po opravě druhu dokladu nebo klasifikace
DPH ho potvrďte.

**Doklad v cizí měně** koncept není. Převezme se v měně a kurzu dokladu,
když základ a daň po sazbách v měně přepočtené kurzem dávají na haléř koruny,
které Money vykázalo v přiznání; DPH, kontrolní hlášení i deník tak zůstávají
v Kč přesně stejné. Jinak (a u samovyměření nebo měny, kterou firma nemá
v číselníku měn) se převezme v Kč a poznámka dokladu i protokol uvedou důvod. Přijatá faktura s členěním „do přiznání nezahrnovat" se
převezme bez nároku na odpočet.

Číslo dokladu, které už ve firmě je, dostane příponu roku, například
`FP001/2025`. Money čísluje řady každý rok od začátku, takže stejné číslo
v dalším roce je běžné. Stejně se rozliší doklady se stejným číslem na dvou
bankovních účtech nebo ve dvou pokladnách.

### 103.10.3 Co převod nepřenese

- **Přílohy a elektronický archiv.** Money je drží v šifrovaných souborech,
  které ze zálohy číst nejde. Skeny dokladů se připojují zvlášť.
- **Zaměstnanci, mzdy osob a sklad.** Zápisy mezd a zásob jsou v převedeném
  deníku; zaměstnance převezmete z podání JMHZ (viz [§ 103.10.2.2](#1031022-mzdy)), zásoby se zakládají
  v MyÚčtu.
- **Interní doklady, kniha pohledávek a závazků.** V deníku jsou jako ruční
  zápisy s původním číslem dokladu, samostatný doklad z nich nevzniká. U daňové
  evidence vzniknou ostatní závazky a pohledávky jen z neuhrazených, viz
  [§ 103.10.10](#1031010-danova-evidence).
- **Číselné řady a řádky DPH pokladních dokladů.** Podaná přiznání k DPH za
  převáděné roky zůstávají v Money; u daňové evidence je převod zapíše jako
  podaná mimo MyÚčto (bez XML).

### 103.10.4 Postup

1. **Záloha agendy.** Nahrajte soubor `.lz`. Průvodce ho rozbalí (jen datové
   soubory agendy) a načte. Rozbalení a načtení běží na serveru na pozadí,
   u velké zálohy i několik minut; obnovení stránky mezitím průvodce nepřeruší.
2. **Náhled a volby.** Zkontrolujte firmu, IČO, verzi Money a účetní roky.
   Kontrola před převodem zastaví převod, když:
   - agenda se v Money z podvojného účetnictví vrátila do daňové evidence
     (rok daňové evidence leží po roce podvojného účetnictví),
   - záloha patří firmě s jiným IČO,
   - účetní období v MyÚčtu už obsahuje zápisy, které nevznikly převodem,
   - období je v MyÚčtu uzavřené,
   - účetní rok Money by se rozdělil do dvou účetních období firmy, protože
     agenda vede jiný účetní rok, než má firma nastavený (`Firma → Nastavení`,
     záložka **Daně a účetnictví**, pole **Účetní rok začíná**). Hlášení
     navrhne měsíc, kterým rok v Money začíná; po jeho nastavení převod
     spusťte znovu.

   Chybí-li IČO v záloze nebo ve firmě, nejde ověřit, že agenda patří této
   firmě. Zkouška nanečisto jen upozorní, ostrý převod se spustí až po
   výslovném potvrzení.

   Volby:
   - *Uzavřít historické roky* - viz [§ 103.10.6](#103106-uzaverka-historickych-let), ve výchozím stavu zapnuto.
   - *Začátek prvního účetního období* - jen u firmy založené během prvního
     převáděného roku. Bez něj začíná první období prvním dnem účetního roku
     firmy (1. 1., u hospodářského roku prvním dnem jeho prvního měsíce), u roku
     bez počátečních stavů prvním zápisem deníku. Datum platí, jen když padne
     do prvního převáděného účetního roku.
   - *Sestavy z Money* - ke každému roku můžete nahrát obratovou předvahu
     vyexportovanou z Money do CSV (účet v prvním sloupci, dál PS MD, PS D,
     obrat MD, obrat D, KS MD, KS D). Rekonciliace ji porovná s předvahou MyÚčta.
3. **Zkouška nanečisto.** Proběhne celý převod včetně uzávěrky a rekonciliace,
   na konci se ale všechno vrátí. Výsledkem je protokol; v MyÚčtu nic nezůstane
   a nastavení automatiky se nezmění. Zkouška běží v jedné databázové transakci
   a po celou dobu drží zámky převáděných účetních období: spouštějte ji mimo
   běžnou práci ve firmě, zápisy do těchto období do jejího konce počkají.
4. **Ostrý převod.** Po potvrzení běží na pozadí, stránku můžete zavřít. Po
   dokončení průvodce nabídne účetní deník a obratovou předvahu. Převod jedné
   firmy běží vždy jen jeden, druhý se do jeho konce nespustí.

#### 103.10.4.1 Navázání na existující číselnou řadu

Převod přenáší doklady s čísly, která měly v Money S3, ale počítadlo nové řady
tím sám nenastaví. Číslo, kterým má řada v MyÚčtu pokračovat, zadejte
v Nastavení → Doklady → **Číslování faktur** do pole **Příští číslo** u příslušné
šablony a potvrďte tlačítkem *Nastavit počítadlo*. Ukládá se samostatně, mimo
tlačítko *Uložit*, a po potvrzení ukáže náhled výsledného čísla.

Vlastní řadu může mít i jednotlivý zákazník nebo kategorie tržby; pole *Příští
číslo* je pak u jejich šablony. U zděděné šablony se pole nenabízí, protože se
čísluje řadou dodavatele a počítadlo je společné.

> [!WARNING]
> Zkontrolujte, že **perioda resetu sedí se šablonou**: u masky bez `{MM}`
> a měsíčního resetu by počítadlo prvního dne dalšího měsíce spadlo zpátky na
> začátek a čísla by kolidovala. Podrobně viz
> [§ 95.6](95_Multi_supplier.md#956-krok-za-krokem-cislovani-faktur).

> [!TIP]
> Sestava *Úplnost číselné řady* začne řadu počítat až od nastaveného čísla,
> takže začátek řady na vyšším čísle nehlásí jako chybějící doklady.

### 103.10.5 Rekonciliace a protokol

Každý běh (zkouška i převod) končí protokolem. Najdete v něm kroky převodu
s počty, upozornění a chyby a pro každý rok rekonciliaci:

- obratová předvaha MyÚčta proti předvaze spočtené přímo z deníku Money
  v záloze, po syntetických účtech, počáteční stav, obrat a konečný stav na haléř,
- obratová předvaha proti sestavě z Money, pokud jste ji nahráli,
- obraty MD = D, předvaha = deník, vyrovnané počáteční stavy, žádné rozpracované zápisy,
- doklady proti deníku: přijaté faktury proti 321, vydané proti 311, pokladna
  proti 211 a banka proti 221.

Doklad, ke kterému v deníku Money není zápis se stejným číslem, protokol vypíše
jako doklad bez zápisu. Takový doklad není zaúčtovaný. Zaúčtujte ho ručně nebo
hromadně v Účetnictví → Doúčtovat doklady. Koncepty k ruční kontrole se mezi
doklady bez zápisu nepočítají.

Úhradu faktury páruje převod podle čísla dokladu úhrady, které Money
u faktury drží. Číslo se v Money každý rok opakuje, takže mezi pohyby se
stejným číslem rozhoduje shoda částky, pak datum úhrady a nakonec rok. Dva
stejně dobré kandidáty převod nespáruje a protokol je vypíše k ručnímu
spárování. Spárovaná faktura dostane stav uhrazeno.

Protokoly všech běhů zůstávají v přehledu pod průvodcem. Protokol zkoušky
nanečisto z přehledu smažete, protokol ostrého převodu zůstává.

#### 103.10.5.1 Chyby, upozornění a rozdíly k přijetí

Zprávy protokolu mají tři váhy. Platí stejně pro převod z Money S3, POHODY,
PAMICA i PREMIER.

| Váha | Co znamená | Příklady |
|---|---|---|
| **Chyba** | Převod nesmí pokračovat, účetnictví by bylo rozbité nebo neúplné v základu. Ostrý převod se nespustí. | kontrola před převodem (jiné IČO, zápisy v období, uzavřené období, soubor), účet mimo osnovu, deník, ve kterém MD ≠ D, obraty nebo počáteční stavy předvahy nesedí (K2), koncepty v deníku, neočekávaná chyba |
| **Upozornění** | Převod doběhne, protokol jen oznamuje, co je potřeba dořešit. | číslo dokladu je ve firmě obsazené jiným dokladem, doklad koliduje s existujícím, konečné stavy roku nesedí na počáteční stavy dalšího roku (rok zůstane otevřený), zaokrouhlovací rozdíl předvahy |
| **Rozdíl k přijetí** | Část dat se nepřevedla nebo převod nesedí na zdrojový program, zbytek je v pořádku. Bez přijetí se chová jako chyba. | doklad se sazbou DPH, kterou číselník nezná, zápis bez data, měsíc mezd, který se nepřevedl, předvaha proti zdroji nad korunu (K1), rozvaha (K3), doklady proti deníku (K4) |

**Zaokrouhlení předvahy.** Liší-li se obratová předvaha MyÚčta od zdroje
na každém účtu nejvýše o 1,00 Kč, jde o zaokrouhlení dokladů ve zdrojovém
programu. Protokol ho vypíše jako upozornění se seznamem účtů a částek
a kontrola K1 platí. Větší rozdíl na kterémkoli účtu je rozdíl k přijetí.

**Přijetí rozdílů.** Když zkouška nanečisto selže jen na rozdílech k přijetí,
průvodce je pod protokolem vypíše a nabídne zaškrtávátko *Převést i přes
rozdíly*. Teprve po jeho zaškrtnutí jde přejít k ostrému převodu. Ostrý převod
pak rozdíly zapíše jako upozornění, skončí stavem *dokončeno s upozorněními*
a protokol je vypíše v samostatné sekci přijatých rozdílů (kód, účty nebo čísla
dokladů a částky). Doklady, které se nepřevedly, doplňte ručně, nebo příčinu
opravte a spusťte převod znovu; opakovaný převod doplní jen to, co chybí.

Při přijatých rozdílech se automatika účtování po převodu obnoví stejně jako
po převodu bez chyb. Převod více roků pokračuje dalšími roky. Převod z PREMIER
rok s přijatými rozdíly neuzavírá ([§ 109.8.6.1](109_Prechod_z_PREMIER.md#109861-uzaverka-uzavrenych-roku)).

### 103.10.6 Uzávěrka historických let

Money převáděné roky uzavřelo, převod je ale naveze otevřené. Průvodce je pak
uzavře průvodcem uzávěrkou MyÚčta od nejstaršího roku, a jen tehdy, když konečné
stavy roku sedí účet po účtu na počáteční stavy dalšího roku z Money včetně
výsledku hospodaření na 431. Při rozdílu rok zůstane otevřený a pozdější roky
také.

Kroky, které proběhly v Money (odpisy, dohadné položky, časové rozlišení,
rezervy, daň z příjmů), se přeskočí s poznámkou, protože jejich zápisy jsou
v deníku. Kurzové přecenění a zásoby se nepřeskakují: má-li rok co přeceňovat,
uzavřete ho ručně v Uzávěrce (kapitola [Uzávěrka](72_Uzaverka.md)).

Otevření dalšího roku převzaté počáteční stavy z Money ponechá a nic nového
nezaúčtuje, takže se počáteční stavy nezdvojí. Poslední převedený rok zůstává
otevřený. Rok, jehož knihy se uzavřely, ale další rok se otevřít nepodařilo,
opakovaný převod dotáhne.

### 103.10.7 Režim účetnictví a automatika

Převod zapíše podvojné účetnictví od začátku prvního převáděného období do
nastavení firmy i do historie režimů. Automatika účtování je během převodu
vypnutá: deník přichází hotový a každý automatický zápis nad týmiž doklady by
byl duplicita.

Po úspěšném převodu se automatika vrátí do stavu před převodem. Firma, která
podvojné účetnictví zapíná právě převodem, dostane výchozí nastavení účetní
jednotky jako po aktivaci. Skončí-li převod chybou, automatika zůstane vypnutá,
dokud převod nedoběhne bez chyb. Stav před převodem se ukládá ještě před
vypnutím automatiky, takže ani převod přerušený pádem ji nenechá vypnutou
natrvalo: další úspěšný běh ji vrátí na stav před prvním převodem.

Záznam daňové evidence, který v historii režimů firmy leží uvnitř převáděných
let, převod odstraní, protože v Money byly tyto roky podvojné.

Agenda daňové evidence režim firmy ani automatiku účtování nemění
([§ 103.10.10](#1031010-danova-evidence)).

### 103.10.8 Opakovaný převod

Převod si pamatuje, co z které agendy už vzniklo. Opakovaný převod téže nebo
novější zálohy založí jen to, co ještě chybí, a nic nezdvojí. Převod přerušený
chybou tak stačí po opravě spustit znovu. Do uzavřeného roku už převod nic
nepřidá.

Už převedené doklady ani zápisy deníku opakovaný převod nepřepisuje, protože
mohly být mezitím zaúčtované, spárované nebo upravené v MyÚčtu. Změnila-li se
v Money celková částka faktury, protokol ji vypíše jako změněnou v Money
a ponechanou v MyÚčtu; upravte ji ručně.

Převádíte-li firmu znovu od začátku (firmu smažete a převedete znovu), ztratí
se s ní i nastavení, které převod nezakládá: výjimky mapování výkazů, volby
výkazů a uzávěrky, daňový profil, dimenze, předkontace a pravidla banky. Před
smazáním proto v průvodci (nebo v Nastavení) stáhněte **profil firmy** a po
ostrém převodu ho nahrajte zpět. Výkazy pak vyjdou stejně jako před smazáním.
Popis profilu je v [§ 96.13](96_Nastaveni.md#9613-krok-za-krokem-profil-firmy).

### 103.10.9 Dávkový převod více firem

Účetní kancelář nebo skupina firem převede víc agend najednou v záložce
**Dávka více firem** nahoře v průvodci. Dávka dělá u každé firmy totéž co
průvodce jedné firmy (stejný převod, stejný protokol u firmy) a navíc firmu
najde nebo založí.

- **Zálohy.** Vyberte zálohy `.lz` všech firem najednou. Nahrávají se po jedné,
  server každou po nahrání přečte a ukáže IČO, název, roky, doporučený rok „od"
  a firmu v MyÚčtu, do které se převede. Z více záloh téže firmy platí nejnovější
  podle data zálohy. Nahrané zálohy zůstávají týden; zálohu úspěšně převedené
  firmy dávka smaže.
- **Firma podle IČO.** Firma se stejným IČO, ke které máte přístup, se převede
  (nebo přeskočí, podle volby *Firma už v MyÚčtu je*). Chybějící firmu dávka
  založí stejně jako zakládání další firmy v aplikaci: název, sídlo a DIČ ze
  zálohy, co v ní chybí, z posledního podaného přiznání k DPPO a z ARES.
  Plátcovství DPH ověří registr plátců; bez něj rozhodnou obraty na účtu 343
  a protokol vyzve k ověření. Zakládat firmy smí jen uživatel s oprávněním
  zakládat firmy. Firmu, ke které přístup nemáte, dávka nepřevede a vypíše ji
  jako chybu. E-mail založené firmy doplňte v nastavení firmy.
- **Rok „od" automaticky.** U každé firmy začne převod prvním rokem, od kterého
  v Money navazují konečné a počáteční stavy (viz [§ 103.10.6](#103106-uzaverka-historickych-let)). Starší roky zůstanou
  v archivu Money. Volbou *Všechny roky* převedete celou zálohu.
- **Podaná přiznání k DPPO (volitelné).** Přiložte EPO XML podaných přiznání
  (DPPDP9). Přiřadí se podle IČO, za každý rok platí poslední podání (dodatečné
  před opravným před řádným). Zakládaná firma z nich dostane NACE, kategorii
  účetní jednotky, audit a začátek prvního účetního období u firmy vzniklé
  během roku. Po ostrém převodu dávka přiznání převezme do Daní a do evidence
  daňových ztrát stejným převzetím jako `api/bin/tax-return-import.php`; existující
  rozpracované přiznání nepřepíše a finální nikdy nemění.
- **Skupina firem.** Firmy lze zařadit do skupiny aktuální firmy nebo do nové
  skupiny. Zařazení proběhne před převodem, takže zakázky Money se převedou jako
  globální projekty skupiny (viz [§ 103.10.2](#103102-co-prevod-prenese)). Volba *Firmy dávky jsou spřízněné osoby*
  označí partnery s IČO jiné firmy dávky nebo skupiny jako spřízněné osoby, a to
  nově založené i ty, které už v adresáři firmy byly. Karta, která už spřízněnou
  osobou je, si ponechá svůj typ vztahu i doložení. Pro nezávislé klienty kanceláře
  volbu nezapínejte.

Zkouška nanečisto založí firmy i celý převod v transakci, která se na konci
vrátí: nezůstane ani firma, ani protokol u firmy, výsledek je jen v protokolu
dávky i s podrobným protokolem každé firmy.

Dávka běží jako jeden úkol na pozadí se společným průběhem. Firmy se převádějí
po jedné a pád jedné firmy ostatní nezastaví. Zrušení platí od další firmy,
u ostrého převodu i uvnitř právě převáděné firmy.

**Protokol dávky** ukazuje u každé firmy, zda se založila, převedla do
existující, nebo přeskočila, rok „od", stav, převzatá přiznání, upozornění
a kontroly:

| Kontrola | Co ověřuje |
|---|---|
| K1 | obratová předvaha proti deníku Money (a proti sestavě z Money) na haléř |
| K2 | obraty MD = D, předvaha = deník, vyrovnané počáteční stavy, žádné koncepty |
| K3 | rozvaha vychází a žádný účet ve výkazech nechybí |
| K4 | doklady po knihách proti zápisům na 321, 311, 211 a 221 |

Podrobný protokol ostrého převodu je u firmy v záložce *Jedna firma* (po
přepnutí do firmy). Předchozí dávky zůstávají v přehledu pod průvodcem.

**Opakování.** Dávku jde spustit znovu se stejnými nebo novějšími zálohami.
S volbou *Převést znovu* se do existujících firem doplní jen to, co chybí
(viz [§ 103.10.8](#103108-opakovany-prevod)), nic se nezdvojí; firma, která minule selhala, se převede znovu.
Profil nastavení existující firmy si dávka před převodem odloží a po úspěšném
převodu ho obnoví, takže ho při opakování není potřeba stahovat ručně.

Správce instalace může dávku spustit i z příkazové řádky nad adresářem
záloh:

```
php api/bin/money-s3-batch.php --dir=<adresář se zálohami> --list
php api/bin/money-s3-batch.php --dir=<adresář se zálohami> --all --dry-run
php api/bin/money-s3-batch.php --dir=<adresář se zálohami> --all --dppo-dir=<adresář s XML> --report=souhrn.json
```

Volby odpovídají průvodci (`--from-year=auto|RRRR|all`, `--existing=skip|update`,
`--group="Název"`, `--related-parties`, `--no-close`, `--no-registry`), na konci
je souhrn po firmách s K1 až K4.

Účtuje-li firma po převodu ještě nějaký čas i v Money, porovnávejte každý měsíc
MyÚčto s výstupy Money nebo s novou zálohou agendy na stránce
`Účetnictví → Souběh se starým systémem` (kapitola
[Souběh se starým systémem](111_Soubeh_se_starym_systemem.md)). Záloha se tam
čte bez zápisu do MyÚčta.

### 103.10.10 Daňová evidence

Agendu, kterou Money vede jako daňovou evidenci (místo účetního deníku peněžní
deník), převod převede do firmy v MyÚčtu, která vede daňovou evidenci. Firma
v daňové evidenci zůstane: převod režim firmy nemění a účetní deník, účtovou
osnovu ani účetní období nezakládá. Rok a režim každého roku čte převod
z nastavení roku v Money. Dávka agendu daňové evidence převede do nové firmy
v daňové evidenci.

Kontrola před převodem projde peněžní deník proti bankovním, pokladním
a interním dokladům, vazby řádků na uhrazené doklady a navázání počátečních
stavů účtů a pokladen a vypíše souhrn každého roku. Převod zastaví, když:

- peněžní deník nesedí s bankovním nebo pokladním dokladem, má řádek bez
  dokladu nebo odkazuje na uhrazený doklad, který v záloze není,
- peněžní deník používá typ pohybu nebo sloupec, který převod nezná, nebo
  obsahuje pohyb v cizí měně,
- agenda má v Money majetek nebo mzdy,
- firma v MyÚčtu vede převáděné roky jako podvojné účetnictví,
- převáděné roky už v MyÚčtu obsahují doklady, bankovní pohyby nebo pokladní
  doklady, které nevznikly převodem,
- doklady firmy jsou uzamčené do převáděného období (při prvním převodu),
- firma má nastavený hospodářský rok; daňová evidence se vede po kalendářních
  letech.

| Z Money | Do MyÚčta |
|---|---|
| adresář a bankovní spojení partnerů | klienti a jejich bankovní účty |
| vydané a přijaté faktury, ostatní pohledávky v přiznání DPH | doklady s DPH; uhrazené jen podle úhrad z peněžního deníku |
| zálohová faktura, daňový doklad k záloze, vyúčtování zálohy | záloha, daňový doklad, faktura s odečtenou zálohou |
| předkontace daňové evidence přijaté faktury | daňová uznatelnost dokladu |
| pokladny a pokladní doklady | pokladní doklady: úhrada faktury, prodej nebo nákup s DPH bez faktury, ostatní (vklad, výběr, nedaňové) |
| bankovní účty a bankovní doklady | měsíční výpisy se zdrojem „import" a počátečním stavem účtu z Money |
| vazby řádků peněžního deníku na uhrazené doklady | úhrady faktur bankou nebo pokladnou |
| zápočet faktury a dobropisu téhož dokladu | zápočet dobropisu |
| sloupec peněžního deníku u pohybu bez dokladu | zařazení pohybu v peněžním deníku (vklad a výběr, nedaňový příjem a výdaj, pojistné, daň, DPH, dar) |
| neuhrazené ostatní závazky a pohledávky | ostatní závazky a pohledávky na zbývající částku |
| plátcovství DPH (nastavení roku, první podané přiznání) | historie plátcovství DPH a zdaňovací období |
| podaná přiznání DPH a kontrolní hlášení | podání mimo MyÚčto, viz níže |

Peněžní deník Money se nepřebírá jako další platby: MyÚčto peněžní deník
počítá z převzatých dokladů, banky a pokladny. Rekonciliace ho za každý rok
porovná s peněžním deníkem Money, a to za každý uhrazený doklad (pohyb bez
dokladu sám za sebe), a protokol ukáže daňové příjmy a výdaje obou. Rozdíly
k přijetí ([§ 103.10.5.1](#1031051-chyby-upozorneni-a-rozdily-k-prijeti))
vznikají typicky tam, kde Money:

- rozdělil úhradu na základ a DPH jinak, než ji MyÚčto rozpočítá poměrem
  (například úhrada jen ve výši DPH),
- při vyúčtování zálohy přeúčtoval DPH zálohy nulovým dokladem; MyÚčto nulové
  doklady nepřebírá a zaplacenou zálohu uplatní jako výdaj při její úhradě,
- vedl zaokrouhlení úhrady jako samostatný řádek bez dokladu; v MyÚčtu zůstane
  nezařazené,
- vedl nepeněžní pohyb (zápočet) s daňovým příjmem nebo výdajem.

Hláška rozdílu vypíše každý doklad zvlášť: uhrazený doklad se základem a DPH,
jeho vyrovnání bez peněz (zápočet s dobropisem, odečtenou zálohu), číslo
a datum pohybu a rozdělení úhrady na daňovou část a DPH v Money (s kódem
pohybu peněžního deníku, podle něj pohyb v Money dohledáte) a v MyÚčtu. Dál
uvede o kolik je daňový příjem nebo výdaj v MyÚčtu vyšší nebo nižší a, kde to
nevyplývá z čísel, pravděpodobnou příčinu. U běžné faktury nebo dobropisu
hláška navíc spočítá, jak úhrada připadá na daňovou část a DPH podle dokladu
(po odečtení započtených dobropisů), a řekne, které rozdělení dokladu
odpovídá. Když dokladu odpovídá rozdělení v MyÚčtu, oprava není potřeba:
součet roku v MyÚčtu se jen bude lišit od Money.

Zápočet ostatní pohledávky proti ostatnímu závazku daňová evidence MyÚčta
nemá: doklady zůstanou neuhrazené a převod to ohlásí.

**Podaná přiznání DPH.** Se zapnutou volbou **Zapsat podaná přiznání DPH
a uzamknout převzaté období** převod zapíše přiznání DPH a kontrolní hlášení,
která Money eviduje jako podaná, jako podaná mimo MyÚčto. Za období se zapíše
jedno podání. Money v záloze nemá jejich XML ani datum podání: záznam je bez
XML a s časem převodu a dodatečné přiznání k takovému období MyÚčto nesestaví.
Podání uzamkne doklady firmy do konce posledního podaného období.

**Pokračování v MyÚčtu.** Neuhrazené faktury a ostatní závazky z Money se
doplácejí v MyÚčtu. Výpis z banky za období, které převod převzal, převzaté
pohyby nezdvojí: import výpisu přeskočí pohyby účtu do dne posledního pohybu
převzatého z Money a ohlásí to. Pohyb z toho dne, který v Money nebyl,
doplňte ručně; další výpisy importujte od následujícího dne.

### 103.10.11 Omezení

- Daňovou evidenci převod převede jen u agendy vedené jako daňová evidence ve
  všech letech, v korunách a bez majetku a mezd. Rok, který Money vede jako
  daňovou evidenci v agendě, která vede i podvojné účetnictví, převod vynechá
  i s jeho doklady a náhled ho vypíše v upozornění.
- Převod nikdy nezapíše podvojné účetnictví pro rok, který Money vede jako
  daňovou evidenci. Agendu, která se v Money z podvojného účetnictví vrátila
  do daňové evidence, kontrola před převodem zastaví a dávka ji odmítne.
  Přechod z daňové evidence na podvojné účetnictví převod převede od prvního
  roku podvojného účetnictví.
- Formát dat Money není veřejně dokumentovaný. Čtení je ověřené na verzi
  Money S3 26.600; u jiné verze průvodce upozorní a výsledek je o to důležitější
  porovnat se sestavami z Money.
- Tvar účetního roku (kalendářní, nebo hospodářský) určuje nastavení firmy
  **Účetní rok začíná**, ne záloha. Nastavuje se před převodem; agendu s jiným
  účetním rokem kontrola před převodem zastaví s návrhem měsíce. Účetní období,
  daňové odpisy majetku i uzávěrka historických let jdou po účetních letech
  firmy.
- Převod čte jen zálohu agendy, nikdy živou instalaci Money. Z archivu rozbalí
  jen datové soubory agendy s omezeným počtem i velikostí.
- Nahraná záloha zůstává na serveru pro další běh. Po úspěšném ostrém převodu
  se smaže, jinak ji denní úklid smaže po 24 hodinách bez práce s ní.

## 103.11 Související kapitoly

- [Souběh se starým systémem](111_Soubeh_se_starym_systemem.md)
- [Účetní deník](52_Ucetni_denik.md)
- [Uzávěrka](72_Uzaverka.md)
- [Přechod z PAMICA](108_Prechod_z_PAMICA.md)
- [Řešení problémů](999_Reseni_problemu.md)
