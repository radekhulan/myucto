# 53. Automat účtování

> Pracovní přehled pro účetní: na jednom místě ukazuje, co systém zaúčtoval sám, co připravil ke schválení a kde potřebuje vaše rozhodnutí. Kapitola je návod pro každodenní práci s frontou a pro nastavení míry automatiky.

## 53.1 Kdy to potřebujete

Kapitolu otevřete, když:

- začínáte pracovní den a chcete projít, co systém za noc zaúčtoval,
- máte ve frontě návrhy bankovních zápisů ke schválení,
- Automat zastavil položku a ukazuje, že vyžaduje zásah,
- automatický zápis je špatně a potřebujete ho vrátit,
- zavádíte automatiku u nové firmy nebo po importu historie,
- chcete opakované platby účtovat sami podle pravidel,
- se blíží uzávěrka měsíce.

### 53.1.1 Kdy co udělat

<!-- cols: 26 46 28 -->
| Kdy | Co udělat | Kde v aplikaci |
|---|---|---|
| každé ráno | Projít zaúčtované, ke schválení a zásahy | `Účetnictví → Automat`, [§ 53.3](#533-krok-za-krokem-ranni-rutina) |
| když přijde návrh | Schválit, opravit kontaci, nebo zamítnout | záložka **Ke schválení**, [§ 53.4](#534-krok-za-krokem-schvaleni-uprava-a-zamitnuti-navrhu) |
| když je položka zastavená | Vyřešit důvod zásahu | záložka **Vyžaduje zásah**, [§ 53.5](#535-krok-za-krokem-vyreseni-polozky-ktera-vyzaduje-zasah) |
| když je zápis špatně | Stornovat nebo vrátit zpět | záložka **Zaúčtováno dnes**, [§ 53.6](#536-krok-za-krokem-vraceni-a-storno-zapisu) |
| při zavedení | Spustit průvodce a zvolit míru automatiky | tlačítko **Průvodce automatikou**, [§ 53.7](#537-krok-za-krokem-prvni-nastaveni) |
| když pravidlo opakovaně uspělo | Povýšit ho na automatiku | záložka **Pravidla**, [§ 53.8](#538-krok-za-krokem-povyseni-pravidla-na-automatiku) |
| před uzávěrkou měsíce | Dořešit frontu a projít checklist | záložka **Checklist**, [§ 53.9](#539-krok-za-krokem-mesicni-rutina-pred-uzaverkou) |

## 53.2 Než začnete

1. **Podvojné účetnictví.** Automat je dostupný jen firmám s podvojným účetnictvím. Menu `Účetnictví → Automat` se u jiných firem nezobrazí.
2. **Oprávnění k účetnictví.** Každou akci server ověří pro konkrétní firmu zvlášť.
3. **Správná firma v hlavní liště.** Všechny záložky vždy zobrazují jen firmu zvolenou v hlavní liště aplikace. Automat nemá vlastní výběr firmy ani společný přehled více firem. Pracujete-li s více firmami, firmu přepněte v liště.
4. **Zvolená míra automatiky.** Co smí systém účtovat sám, se nastavuje v boxu **Automatika účtování** na záložce **Pravidla** (viz [§ 53.7](#537-krok-za-krokem-prvni-nastaveni)). Po zapnutí podvojného účetnictví je výchozí plná automatika, proto firmě s importovanou historií doporučujeme začít režimem **jen návrhy**.
5. **AI návrhy jsou volitelné.** Zapíná je správce ve `Firma → AI nastavení → AI asistence účtování`. Bez nich Automat funguje na pravidlech a vestavěném rozpoznání ([§ 53.11.9](#53119-ai-navrhy-uctovani)).

## 53.3 Krok za krokem: ranní rutina

Vyhraďte si několik minut.

1. Otevřete `Účetnictví → Automat` a v hlavní liště zkontrolujte firmu.
2. Otevřete záložku **Zaúčtováno dnes**. Zkontrolujte neobvyklé částky, nové protistrany a první použití nového pravidla. Je-li výsledek špatně, postupujte podle [§ 53.6](#536-krok-za-krokem-vraceni-a-storno-zapisu).
3. Přejděte na **Vyžaduje zásah**. Vyřešte nejprve uzavřená období, duplicity a chybějící doklady, protože mohou blokovat další práci ([§ 53.5](#535-krok-za-krokem-vyreseni-polozky-ktera-vyzaduje-zasah)).
4. Na záložce **Ke schválení** rozbalte návrhy, ověřte MD/D a schvalte je ([§ 53.4](#534-krok-za-krokem-schvaleni-uprava-a-zamitnuti-navrhu)).
5. Hromadně potvrďte až opakované deterministické položky, které jste už jednotlivě ověřili.
6. Nakonec otevřete **Checklist**, pohled **Denní**, a zkontrolujte, zda nezůstala důležitá nevyřízená položka.

**Jak poznáte, že je hotovo:** Záložky **Ke schválení** a **Vyžaduje zásah** jsou prázdné, nebo obsahují jen položky, které vědomě odkládáte. Denní checklist nemá nesplněný řádek.

> [!TIP]
> Zkratky urychlí opakovanou práci: **J** a **K** přesouvají řádek, **Enter** rozbalí detail, **A** schválí a **X** zamítne (celý seznam v [§ 53.11.10](#531110-klavesove-zkratky)).

## 53.4 Krok za krokem: schválení, úprava a zamítnutí návrhu

Před schválením ověřte:

- zda popis a protistrana odpovídají očekávané operaci,
- zda částka a měna souhlasí s podkladem,
- zda datum patří do správného období,
- zda navržené účty MD/D odpovídají povaze operace,
- zda už podobný zápis v deníku neexistuje.

**Jeden návrh:**

1. Na záložce **Ke schválení** rozbalte řádek a podívejte se na náhled zápisu, štítek **Proč** a jistotu (viz [§ 53.11.5](#53115-jak-cist-proc-a-jistotu)).
2. Je-li návrh správný, klikněte na **Schválit**. Vznikne účetní zápis.
3. Má-li návrh chybné účty, klikněte na **Upravit kontaci**, doplňte správné účty MD/D a klikněte na **Schválit upravenou kontaci**. Rozdíl mezi návrhem a výsledkem se uloží jako učicí signál.
4. Nemá-li být návrh použit, klikněte na **Odmítnout** a vyberte stručný důvod (**Navržený účet je chybný**, **Není to náš doklad nebo transakce**, **Duplicita**, **Jiný důvod**). Zamítnutí není chyba: je to informace, že daný návrh nemá být použit.

**Hromadně:**

1. Označte stejnorodé deterministické návrhy (nebo klikněte na **Vybrat způsobilé na této stránce**).
2. Klikněte na **Schválit vybrané** a zkontrolujte náhled dopadu: počet položek a firem a souhrn obratů MD/D po účtech a měnách.
3. Potvrďte tlačítkem **Zaúčtovat N položek**.
4. Vybrané návrhy lze stejně hromadně **Odmítnout vybrané**. Zvolený důvod se uloží pro zlepšování pravidel.

**Jak poznáte, že je hotovo:** Návrh zmizí ze záložky **Ke schválení**. Schválený zápis je v deníku (**Zobrazit zápis**) a v Historii.

> [!WARNING]
> AI návrhy, položky v uzavřeném nebo zamčeném období, položky bez práva zápisu a jiné řádky než bankovní návrhy se do hromadného výběru nezahrnou. Před hromadným schválením rozbalte alespoň první položky každého pravidla.

## 53.5 Krok za krokem: vyřešení položky, která vyžaduje zásah

1. Otevřete záložku **Vyžaduje zásah**. Nad frontou je souhrn nejčastějších důvodů za posledních 30 dní. Anomálie jsou zvýrazněné a řazené před běžné položky.
2. U položky přečtěte důvod. Co který znamená a jak ho řešit, ukazuje tabulka v [§ 53.11.4.3](#531143-vyzaduje-zasah).
3. Klikněte na **Zdroj**. Otevře se postranní detail transakce, výpisu nebo dokladu, aniž byste ztratili rozpracovanou frontu.
4. Vyřešte příčinu: doplňte doklad, zaúčtujte předpis, upravte pravidlo nebo ověřte datum.
5. Konflikt pravidel: Automat ukáže všechny odpovídající varianty včetně kontace. Vyberte správné pravidlo tlačítkem **Použít vybrané pravidlo** a potvrďte, nebo návrh zamítněte.
6. Podezření na duplicitu: porovnejte vedle sebe navržený a existující zápis. Potom zvolte **Přesto zaúčtovat**, nebo **Už pokryto existujícím zápisem**.
7. Nemůžete-li položku vyřešit hned, klikněte na **Odložit**. Položka zůstane ve frontě, ale přesune se za aktivní položky do následujícího dne. Tlačítkem **Vrátit do fronty** ji vrátíte.

**Jak poznáte, že je hotovo:** Položka zmizí z fronty **Vyžaduje zásah** a objeví se v Historii.

> [!TIP]
> Samostatná stránka [**K doúčtování**](54_Rucni_fronta_doctovani.md) ukazuje doklady bez předpisu, otevřené žádosti o podklad a skutečné bankovní pohyby, pro které nevznikl žádný návrh. Bankovní návrhy v jakémkoli stavu zůstávají pouze v Automatu, takže se obě fronty záměrně nepřekrývají.

## 53.6 Krok za krokem: vrácení a storno zápisu

1. Hned po schválení se na několik sekund zobrazí oznámení. Klikněte na **Vrátit zpět**.
2. Později otevřete záložku **Zaúčtováno dnes** a u automatického zápisu klikněte na **Stornovat**. Potvrďte.
3. Po hromadném schválení nabídne oznámení **Vrátit celou dávku**.
4. Vrácený návrh najdete znovu na záložce **Ke schválení**. Opravte ho nebo zamítněte.
5. Opravte i příčinu (pravidlo nebo předkontaci), jinak stejná chyba vznikne příště.

**Jak poznáte, že je hotovo:** V deníku je původní zápis i storno ve stejném datu a kontace čeká ve frontě **Ke schválení**.

Vrácení probíhá účetně bezpečně:

1. systém vyhledá původní zápis a jeho datum,
2. ověří, že původní období je stále otevřené a není zamčené,
3. vytvoří storno ve stejném datu jako původní zápis,
4. zachová původní zápis i storno pro audit,
5. vrátí kontaci do fronty **Ke schválení**, kde ji lze opravit nebo zamítnout.

> [!WARNING]
> Systém nikdy neposune storno potichu do dnešního období. Pokud bylo období mezitím uzavřeno, zobrazí upozornění a nic nezmění. Další postup určí osoba odpovědná za uzávěrku. Dávka jedné firmy se vrací atomicky: buď vzniknou storna všech dosud aktivních zápisů, nebo žádné. Při více firmách se dávky zpracují postupně a úspěšné vrácení předchozí firmy se kvůli chybě následující firmy neodvolá.

## 53.7 Krok za krokem: první nastavení

Průvodce je dostupný pro jednu vybranou firmu.

1. Otevřete `Účetnictví → Automat` a klikněte na **Průvodce automatikou**. Průvodce má čtyři kroky (vpravo nahoře vidíte 1/4 až 4/4).
2. **Analýza historie (1/4).** Průvodce jen přečte opakované nezaúčtované korunové bankovní pohyby a ukáže počet nalezených skupin a jejich pokrytí. Nic nemění. Klikněte na **Pokračovat**.
3. **Návrhy pravidel (2/4).** Průvodce seskupí podobné platby podle protistrany, variabilního symbolu nebo textu. Vyberte skupiny, které chcete použít, zkontrolujte účty MD/D (u nejasné skupiny můžete použít **Navrhnout kontaci (AI)**) a klikněte na **Pokračovat**.
4. **Doplnění historie (3/4).** Zkontrolujte, na kolik historických transakcí se pravidla použijí a které transakce v uzavřených obdobích se přeskočí. Klikněte na **Vytvořit pravidla a návrhy**.
5. **Úroveň automatiky (4/4).** V poli **Úroveň automatiky** zvolte režim. Chcete-li ranní souhrn, zaškrtněte **Posílat ranní e-mailový přehled** a zadejte hodinu odeslání. Klikněte na **Uložit**.
6. Několik dní kontrolujte výsledky ve frontě a teprve ověřeným typům operací povolte vyšší úroveň.

**Jak poznáte, že je hotovo:** Pravidla jsou založená v režimu **jen návrhy**, nad frontou vidíte jejich výsledky a v boxu **Automatika účtování** je zvolená úroveň.

Všechna pravidla vytvořená průvodcem začínají v režimu **jen návrhy**, i kdyby byla v odeslaných datech uvedena plná automatika.

> [!TIP]
> Úrovně a jejich význam jsou v [§ 53.11.6](#53116-urovne-automatiky). Pro celofiremní nastavení pravidel z již zaúčtované historie slouží také [Asistent nastavení účtování](65_Sablony.md#658-krok-za-krokem-asistent-nastaveni-uctovani).

## 53.8 Krok za krokem: povýšení pravidla na automatiku

1. Otevřete záložku **Pravidla**. U pravidla v režimu **jen návrhy** vidíte počet potvrzení beze změny.
2. Po pěti potvrzeních za sebou, bez odmítnutí a s vyplněným rozsahem částky se tlačítko **Povýšit na automatiku** zvýrazní.
3. Klikněte na **Povýšit na automatiku**. K povýšení nikdy nedojde samo.
4. Chcete-li pravidlo povýšit dřív, klikněte na totéž tlačítko u aktivního návrhového pravidla (nebo změňte režim v úpravě pravidla) a výslovně povýšení potvrďte. V historii pravidla je označené jako ruční.
5. Tlačítkem **Historie** zobrazíte časovou osu pravidla, změny kontace, autora a úspěšnost.

**Jak poznáte, že je hotovo:** Pravidlo je v automatickém režimu a jeho shody se účtují samy až do stropu automatiky a denního limitu.

> [!WARNING]
> Ručně povýšené pravidlo účtuje samo i bez předchozích použití a bez rozsahu částky. Strop automatiky, uzavřené období, anomálie a denní limit platí dál. Stornujete-li automatický zápis, pravidlo se vrátí do režimu **jen návrhy**.

## 53.9 Krok za krokem: měsíční rutina před uzávěrkou

1. V Automatu nastavte filtr **Od/Do** na uzavíraný měsíc.
2. Vyřešte všechny položky **Vyžaduje zásah**.
3. Schvalte nebo zamítněte všechny návrhy **Ke schválení**.
4. Projděte automatické zápisy s vyššími nebo neobvyklými částkami.
5. Otevřete [**Účetnictví → K doúčtování**](54_Rucni_fronta_doctovani.md) a dořešte bankovní pohyby bez návrhu, nezaúčtované doklady a žádosti o podklady.
6. Projděte [**Účetnictví → Úplnost dokladů**](61_Uplnost_dokladu.md) v obou směrech.
7. V Automatu otevřete **Checklist**, pohled **Měsíční závěrka**, a ověřte, že je fronta prázdná.
8. Teprve potom pokračujte v kontrolách účetního období a v uzávěrce.

**Jak poznáte, že je hotovo:** Checklist **Měsíční závěrka** nemá nesplněný řádek.

Automat je pomocník pro třídění a opakované účtování. Nenahrazuje kontrolu úplnosti dokladů, bankovních zůstatků, saldokonta, DPH ani závěrkové operace.

## 53.10 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Záložky jsou prázdné, ale víte o nezaúčtovaném dokladu | Prázdná karta **Ke schválení** znamená jen, že nečekají uložené bankovní návrhy | Podívejte se do **Doporučení**, **Vyžaduje zásah** a do [K doúčtování](54_Rucni_fronta_doctovani.md). |
| Položku vidíte, ale nejde schválit | Období je zamčené, nebo máte pro firmu jen právo čtení | Požádejte správce rolí nebo osobu odpovědnou za účetní období. |
| Vrácení zpět se odmítne hláškou, že původní období je uzavřené nebo zamčené | Storno se nikdy nepřesouvá do dnešního období | Další postup určí osoba odpovědná za uzávěrku ([§ 53.6](#536-krok-za-krokem-vraceni-a-storno-zapisu)). |
| Pravidlo se objevilo ve **Vyžaduje zásah** jako vypnuté | Bylo opakovaně zamítnuto | Zkontrolujte podmínky a účty; pravidlo opravte, nebo ponechte vypnuté. |
| Návrh má vysokou jistotu, a přesto čeká | Zasáhla pojistka (strop, denní limit, chybějící předpis, anomálie, uzavřené období) | Pojistku Automat u návrhu vypíše; odstraňte ji, nebo návrh schvalte ručně. |
| Nenacházíte firmu v Automatu | Automat ukazuje jen firmu z hlavní lišty | Přepněte firmu v hlavní liště aplikace. |
| Tlačítko **Prověřit znovu** nic nezměnilo | Analýza běží na pozadí, nebo skončila chybou | Počkejte na dokončení, průběh se obnovuje i po návratu na stránku. Při chybě spuštění zopakujte, dosavadní výsledky zůstanou. |

## 53.11 Podrobnosti a pravidla

### 53.11.1 Co Automat je

**Automat** je pracovní přehled, který účetní ukazuje na jednom místě vše, co
systém zaúčtoval sám, co připravil ke schválení a kde potřebuje lidské
rozhodnutí. Nejde o „černou skříňku“, která bez kontroly účtuje všechno. Každá
položka ukazuje zdroj rozhodnutí, navrženou kontaci, míru jistoty a důvod, proč
skončila právě v dané skupině.

Stránku otevřete přes **Účetnictví → Automat**. Je dostupná firmám s podvojným
účetnictvím a respektuje oprávnění uživatele pro každou firmu zvlášť.
Všechny záložky vždy zobrazují pouze firmu zvolenou v hlavní liště aplikace.
Automat nemá vlastní výběr firmy ani společný přehled více firem.

Kontrola duplicitních bankovních úhrad nepovažuje e-mailové avízo za další
platbu. Pohyby načtené přes bankovní API se kontrolují stejně jako řádné
výpisy ABO/GPC nebo PDF; skutečná shoda dvou pohybů ve výpisech nadále
vyžaduje prověření.

Pro prvotní nastavení celé firmy a návrh pravidel z již zaúčtované historie
slouží samostatný [Asistent nastavení účtování](65_Sablony.md#658-krok-za-krokem-asistent-nastaveni-uctovani).
Automat spojuje každodenní frontu s doporučeními nad existujícími doklady.

### 53.11.2 Záložka Doporučení

Výchozí karta **Doporučení** hledá konkrétní příležitosti pro automatizaci.
Zobrazuje se pouze pro aktuálně zvolenou firmu v aplikaci a nemá vlastní
přepínač firmy. Lze ji omezit obdobím a typem doporučení.
U skupin se období vztahuje k poslednímu výskytu, zatímco počet a ukázky
vycházejí z celé prověřené historie.

- **Nové nákladové pravidlo** seskupuje opakované nákupy, které ještě nepokrývá
  aktivní pravidlo. Ukazuje hledaný text, doporučený druh nákladu a účet,
  počet výskytů a příklady dokladů. Akce **Vytvořit nákladové pravidlo** otevře
  předvyplněný původní formulář z pravidel nákladů. Text je povinný; vazbu na
  dodavatele lze změnit nebo vypnout a vytvořit obecné pravidlo podle textu.
- **Existující pravidlo: zvážit automatiku** ukazuje nákladové pravidlo v režimu
  návrhu a konkrétní případy, které rozpoznává. Námětem je přepnutí na automatické
  použití, nikoli založení dalšího pravidla. Počet výskytů neznamená počet
  potvrzení správnosti. Akce **Zkontrolovat a nastavit automatiku**
  načte jeho aktuální nastavení do původního formuláře přímo nad přehledem.
  Po kontrole můžete upravit kritéria nebo zvolit **Použít automaticky**.
  Nevytváří se další kopie stejného pravidla.
- **Pravidlo účtování** vychází z opakovaných bankovních pohybů. Akce
  **Vytvořit pravidlo účtování** otevře původní formulář s kritérii, kontací
  MD/D, rozsahem částky, měnou, prioritou a testem na historii. Nejde o
  šablonu účetního zápisu s pevnou částkou.
- **Předpis vydané nebo přijaté faktury** se nabízí jen pro nezaúčtovaný
  doklad v otevřeném období s platným náhledem. Akce otevře aktuální náhled
  konkrétního dokladu; zaúčtování potvrdíte až v něm.

Uložení formuláře skutečně vytvoří nebo upraví pravidlo a vyžádá obnovu doporučení.
Nové návrhy pravidel jsou předvyplněné v režimu návrhu. Historické doklady
slouží jako podklady pro budoucí automatizaci, nikoli jako pokyn měnit
uzavřená období. Případy pokryté automatickými pravidly ani samotný historický
nesoulad s existujícím pravidlem se nenabízejí jako další úkol. Pravidlo
ponechané v režimu návrhu se po přepočtu může znovu nabídnout k ověření.

**Prověřit znovu** okamžitě spustí samostatnou úlohu na pozadí pro aktuální
firmu, stejně jako analýza v Asistentovi nastavení účtování. Na cron nečeká.
Stránka ukazuje postup po fázích: vydané faktury, přijaté faktury, nákladová
pravidla, bankovní pravidla, souhrn a uložení výsledků. Procenta vyjadřují
dokončené fáze, nikoli odhad zbývajícího času. Průběh se obnovuje i po návratu
na stránku. Opakovaný klik nepustí druhý souběžný job pro stejnou firmu.
Při chybě lze spuštění zopakovat; dosavadní výsledky zůstávají zachované.
Po dokončení se přehled aktualizuje automaticky. Uložení pravidla rovněž
spustí obnovu. Otevření stránky, filtrování ani stránkování analýzu nespouští.

Pravidelná automatická obnova je součástí denní úlohy `cron-ai-rule-miner`
(výchozí čas 04:00). Samostatný cron pro doporučení není potřeba. Ruční
obnova z UI funguje i bez spuštěného cron dispatcheru.

Tlačítkem **Otevřít podklad** přejdete na příslušnou fakturu nebo bankovní
pohyb. Jde o doplňkovou kontrolu vedle konkrétní akce doporučení. Pravidlo
lze vytvořit také v rozbalovacím menu akcí detailu. Otevření doporučení ani
**Prověřit znovu** nic neúčtuje, nemění položky a neodesílá data externí AI.

Úloha `cron-ai-rule-miner` vytváří návrhová bankovní pravidla
z opakovaných potvrzených oprav a následně obnovuje doporučení.
`cron-automation-digest` obnovuje starší
bankovní návrhy a odesílá nastavený e-mailový souhrn. Přehled doporučení
využívá existující analýzu bankovní historie a nákladovou klasifikaci;
nezakládá pravidla při pouhém načtení stránky.

Prázdná karta **Ke schválení** znamená, že nečekají uložené bankovní návrhy.
Sama o sobě neznamená, že byly prověřeny všechny faktury. K tomu slouží
**Doporučení** a karta **Vyžaduje zásah**.

> [!WARNING]
> Automat nemění pravidla účetních období. Do uzavřeného nebo zamčeného období
> nic nezaúčtuje ani nestornuje. Nejasnou platbu raději odloží ke kontrole. AI
> návrh nikdy nezaúčtuje automaticky a nelze ho schválit hromadně.

### 53.11.3 Proč je práce s Automatem bezpečná

Při každém rozhodnutí se uplatní několik pojistek:

1. **Oprávnění k firmě** - uživatel vidí jen firmy, ke kterým je přiřazený.
   Fronta a její akce patří vždy aktuální firmě. Pro jinou firmu změňte
   výběr v hlavní liště aplikace.
2. **Otevřené účetní období** - datum dokladu nebo bankovního pohybu musí patřit
   do otevřeného a nezamčeného období.
3. **Jednoznačná kontace** - automatické zaúčtování se použije jen tam, kde
   pravidlo nebo vestavěné rozpoznání dává jednoznačný výsledek.
4. **Omezení částky a denního objemu** - nastavený strop pravidla nebo denní
   limit převede položku do fronty ke schválení místo automatického zápisu.
5. **Ochrana saldokonta a duplicit** - nebezpečná nebo neúplná kontace se
   nezpracuje potichu. Automat upozorní na chybějící předpis, možnou duplicitu
   nebo konflikt pravidel.
6. **Dohledatelný původ** - u zápisu je vidět, zda rozhodla faktura, pravidlo,
   vestavěné rozpoznání, dříve naučený vzor nebo AI návrh.
7. **Storno místo mazání** - vrácení již provedeného zápisu zachová auditní
   stopu. Původní zápis se stornuje a kontace se vrátí do fronty ke kontrole.

Bez ohledu na zvolený preset se automaticky nikdy nezaúčtuje AI návrh,
nejednoznačná shoda, pohyb v uzavřeném období, položka nad platným limitem ani
operace, která by vytvořila nepodložený zůstatek saldokonta. Vlastní převod se
účtuje automaticky jen mezi evidovanými vlastními účty ve stejné měně. Odvod
státu nebo pojišťovně potřebuje existující zaúčtovaný předpis a dostatečný
kreditní zůstatek příslušného zúčtovacího účtu.

Výchozí bezpečný postup je jednoduchý: nejprve používejte režim **jen návrhy**,
několik dní kontrolujte výsledky a teprve ověřeným typům operací povolte vyšší
úroveň automatiky.

### 53.11.4 Orientace na stránce

V horní části jsou filtry zdroje rozhodnutí, typu operace, jistoty,
částky a období. Výsledky lze řadit podle data, jistoty, částky, typu operace
nebo zdroje. Firma zvolená v hlavní liště platí pro frontu, pravidla, checklist,
historii i průvodce, takže editovaná pravidla vždy patří právě zobrazené firmě. Následují
pracovní záložky:

#### 53.11.4.1 Zaúčtováno dnes

Zobrazuje zápisy, které automat provedl bez ručního potvrzení. U každého řádku
vidíte firmu, datum, částku, důvod, jistotu a kontaci **MD/D**. Rozbalením řádku
se zobrazí náhled zápisu. Tlačítko **Zobrazit zápis** otevře účetní deník.

Tuto záložku stačí ráno rychle projít. Zaměřte se hlavně na neobvyklé částky,
nové protistrany a první použití nového pravidla. Pokud výsledek není správně,
použijte **Stornovat**; podrobnosti jsou v [§ 53.6](#536-krok-za-krokem-vraceni-a-storno-zapisu).

Bez vlastního filtru data se zobrazují dnešní rozhodnutí a jsou seskupená podle
dne. Pokud nastavíte rozsah **Od/Do**, lze stejným způsobem projít i starší
historii. Delší seznamy jsou rozdělené na stránky; volba všech položek se vždy
týká jen právě zobrazené stránky.

#### 53.11.4.2 Ke schválení

Obsahuje návrhy, u kterých systém zná pravděpodobnou kontaci, ale podle
nastavení nebo bezpečnostní pojistky čeká na účetní. Tlačítko **Schválit**
vytvoří účetní zápis, **Odmítnout** návrh zamítne.

Pokud je návrh věcně správný, ale má chybné účty, použijte **Upravit kontaci**,
doplňte správné účty MD/D a schvalte opravenou variantu. Automat si rozdíl mezi
návrhem a výsledkem uloží jako učicí signál. Účty vždy zkontrolujte v kontextu
firmy uvedené na řádku.

Deterministické návrhy lze označit a schválit hromadně. Před potvrzením Automat
zobrazí počet položek a firem i souhrn obratů MD/D po účtech a měnách. Po
zaúčtování lze celou dávku jednou akcí vrátit. V rámci jedné firmy je vrácení
atomické: pokud by jediný zápis narazil na uzavřené nebo zamčené období,
nevrátí se z dávky této firmy nic. Do hromadného výběru se
nezahrnou:

- AI návrhy,
- položky v uzavřeném nebo zamčeném období,
- položky, ke kterým uživatel nemá právo zápisu,
- jiné typy řádků než bankovní návrhy.

Před hromadným schválením zkontrolujte zobrazený dopad a rozbalte alespoň první
položky každého pravidla. Hromadná akce je rozdělena po firmách, takže se data
různých účetních jednotek nesmísí. Vybrané návrhy lze také hromadně odmítnout;
zvolený důvod se uloží pro další zlepšování pravidel.

#### 53.11.4.3 Vyžaduje zásah

Sem patří položky, které bez lidského rozhodnutí nelze bezpečně dokončit.
Červené nebo varovné označení neznamená poškozená data - znamená, že systém
zastavil automatiku dříve, než by mohl vzniknout chybný zápis.

Nad frontou je souhrn nejčastějších důvodů zásahu za posledních 30 dní.
Anomálie jsou zvýrazněné a řazené před běžné položky. Nejasnou položku lze
**Odložit** do následujícího dne; zůstane ve frontě, ale přesune se za aktivní
položky. Akce **Zdroj** otevře postranní detail bankovní transakce, výpisu nebo
dokladu bez ztráty rozepracované fronty.

| Důvod | Co znamená | Doporučený postup |
|---|---|---|
| Doklad není zaúčtovaný | Faktura nebo přijatý doklad nemá předpis v deníku | Otevřete doklad, zkontrolujte jej a použijte **Zaúčtovat doklad** |
| Období je uzavřené | Datum spadá mimo otevřené období nebo pod měkký zámek | Ověřte správnost data; období otevírejte jen podle interního postupu |
| Chybí povinná dimenze | Pravidlo v režimu Automaticky by vytvořilo zápis bez dimenze povinné podle pravidla dimenzí | Doplňte dimenzi u pohybu (akce **Dimenze**) nebo v pravidle a návrh schvalte, viz [§ 114.11.5](114_Dimenze.md#114115-vychozi-dimenze) |
| Konflikt pravidel | Stejné platbě odpovídá více stejně silných pravidel | Porovnejte pravidla, jedno zpřesněte nebo snižte jeho prioritu |
| Podezření na duplicitu | Podobný zápis už v deníku existuje | Otevřete porovnání a ověřte doklad, částku, datum a protistranu |
| Chybí doklad | K bankovní platbě není účetní podklad | Vyžádejte doklad od klienta a zaúčtování dokončete až po jeho kontrole |
| Nejasné vyúčtování zálohy | Nelze bezpečně určit návaznost zálohy nebo DPH | Otevřete související doklady a posuďte vyúčtování ručně |
| Neobvyklá částka | Částka se odchyluje od známého chování protistrany | Porovnejte ji s dokladem a předchozími platbami |
| Překročený limit | Platba je nad stropem pravidla nebo denním limitem | Zkontrolujte ji a schvalte jednotlivě, případně upravte limit |
| Chybějící předpis závazku | Platba by vytvořila nesprávný zůstatek na zúčtovacím účtu | Nejdříve zaúčtujte předpis a poté platbu |
| Vypnuté pravidlo | Pravidlo bylo opakovaně zamítnuto | Zkontrolujte podmínky a účty; pravidlo opravte nebo ponechte vypnuté |

Přijatá zálohová výzva se mezi nezaúčtovanými předpisy nezobrazuje. Sama není
nákladem ani závazkem na účtu 321; účtuje se až její skutečná úhrada z banky
nebo pokladny proti účtu 314. Náklad a DPH vzniknou až z navazujícího finálního
daňového dokladu.

U konfliktu pravidel Automat zobrazí všechny odpovídající varianty včetně
kontace. Vyberte správné pravidlo a potvrďte je, nebo návrh zamítněte. U
podezření na duplicitu se vedle sebe zobrazí navržený a existující zápis s
odkazem do deníku; teprve po porovnání zvolte **Přesto zaúčtovat** nebo
**Už pokryto existujícím zápisem**.

> [!TIP]
> Samostatná stránka [**K doúčtování**](54_Rucni_fronta_doctovani.md) ukazuje
> doklady bez předpisu, otevřené žádosti o podklad a skutečné bankovní pohyby,
> pro které **nevznikl žádný návrh**. Bankovní návrhy v jakémkoli stavu zůstávají
> pouze v Automatu, takže se obě fronty záměrně nepřekrývají.

#### 53.11.4.4 Pravidla

Zobrazuje celkové nastavení automatiky a pravidla bankovních pohybů. Pravidlo
typicky určuje směr platby, protistranu nebo text, případný rozsah částky a
výslednou kontaci. Pravidla udržujte co nejkonkrétnější. Obecné pravidlo podle
krátkého textu má větší riziko falešné shody než pravidlo podle bankovního účtu
protistrany.

Prázdná dolní nebo horní mez částky pravidlo neomezuje na dané straně intervalu.
Nižší číselná **priorita** se vyhodnocuje dříve. Rozsah částky určuje, na jaké
pohyby pravidlo platí; samostatný **limit pro automatiku** pouze rozhoduje, zda
shoda ještě smí rovnou účtovat, nebo musí zůstat návrhem. Nad tím vším stojí
celofiremní denní limit z nastavení automatiky.
U aktivního pravidla lze tlačítkem **Použít na historii** vytvořit návrhy také pro
dosud nezaúčtované bankovní transakce v otevřených obdobích. Běh nic nezaúčtuje
sám a při opakování nevytvoří duplicitní návrhy.

Převody mezi vlastními účty jsou vestavěné rozpoznání, nikoli běžné pravidlo.
Jejich režim nastavte v horním boxu **Automatika účtování** presetem
**Asistovaná** nebo **Plná automatika**. V podrobném nastavení (**Nastavit
jednotlivé typy operací**) musí být na úrovni **plná automatika** jak
**Převody mezi vlastními účty**, tak **Rozpoznávání vlastních převodů**. Starší pravidlo vytvořené pro konkrétní
vlastní účet lze smazat; rozhodující je registr vlastních bankovních účtů.

#### 53.11.4.5 Checklist

Checklist nabízí tři pohledy:

- **Denní** - co automat provedl, co čeká na potvrzení a co vyžaduje zásah.
- **Měsíční závěrka** - upozorní, zda před uzavřením období nezůstala
  nevyřízená fronta.
- **DPH** - pomáhá projít položky důležité před přípravou daňového výstupu.

Kliknutím na řádek checklistu přejdete přímo na odpovídající frontu. Zelená
fajfka znamená splněnou kontrolu, nikoli automatické potvrzení účetní správnosti
všech podkladů.

#### 53.11.4.6 Historie

Historie ukazuje automatická zaúčtování, schválení, zamítnutí a nahrazené
návrhy. Slouží k dohledání, co se s položkou stalo a kdo rozhodnutí provedl.
U každé události je vidět částka a měna, kontace, popis, protistrana, datum
bankovní transakce, variabilní symbol a číslo účetního dokladu. Odkazy vedou
přímo na zápis v deníku a na zdrojovou bankovní transakci. Samotné účetní zápisy
a storna zůstávají dohledatelné také v účetním deníku.

### 53.11.5 Jak číst „Proč“ a jistotu

Štítek **Proč** vysvětluje zdroj návrhu:

- **Faktura** - platba byla spárována s konkrétním dokladem.
- **Pravidlo** - kontaci určilo pojmenované bankovní pravidlo.
- **Systémové rozpoznání** - systém rozpoznal bezpečný typ operace, například
  vlastní převod nebo odvod.
- **Naučeno** - návrh odpovídá dříve potvrzeným obdobným transakcím.
- **Předpis zálohy** - platba odpovídá evidovanému předpisu.
- **AI návrh** - jde pouze o pomůcku pro ruční kontrolu; nikdy se sám ani
  hromadně nezaúčtuje.

Jistota je zobrazena slovně i procentem. Vysoká jistota neznamená, že lze
vynechat účetní úsudek - vyjadřuje pouze sílu shody podle dostupných dat.
Nízká jistota je záměrný signál, že položku máte otevřít a ověřit podrobněji.
U návrhu s vysokou jistotou, který přesto čeká na potvrzení, Automat vypíše také
konkrétní pojistku, například překročený strop, denní limit, chybějící předpis,
anomálii nebo uzavřené období.

### 53.11.6 Úrovně automatiky

- **Vypnuto** - systém nové operace automaticky nezaúčtuje.
- **Jen návrhy** - rozpoznané operace čekají na ruční schválení; doporučeno pro
  začátek a pro nové firmy.
- **Asistovaná** - jednoznačné bezpečné typy mohou proběhnout automaticky,
  ostatní zůstávají ke schválení.
- **Plná automatika** - deterministické operace mohou být zaúčtovány podle
  politiky a limitů. Nejasné a AI návrhy stále vyžadují člověka.

Úroveň je jen horní hranice. Konkrétní bezpečnostní pojistka, limit, zavřené
období nebo nejednoznačnost vždy může operaci přesunout do ruční fronty.

### 53.11.7 Ranní e-mailový přehled

V průvodci lze zapnout ranní souhrn a vybrat hodinu odeslání. Přehled se
agreguje pro uživatele napříč všemi jeho povolenými firmami a obsahuje odkazy
přímo na návrhy ke schválení a položky vyžadující zásah.

Pokud není co řešit ani co oznámit, e-mail se neposílá. Přehled neprovádí žádnou
účetní operaci - pouze připomíná stav fronty.

Server kontroluje naplánované souhrny každou hodinu mezi 6:00 a 8:00 a odešle
je v hodině nastavené u konkrétní firmy. Jeden příjemce dostane souhrn za všechny
firmy, k nimž má přidělený přístup.

### 53.11.8 Jak se Automat učí z oprav

Automat si uchovává auditní stopu každé účetní opravy: změnu navržených účtů
MD/D, odmítnutí návrhu, ruční zaúčtování i storno. U naučeného návrhu proto
uvidíte srozumitelnou větu, kdy a z jaké kontace jste přešli na novou. Poslední
jednoznačná oprava má přednost před starší historií deníku; rozporné opravy
nevytvoří další návrh bez vaší kontroly.

Pravidlo v režimu **jen návrhy** ukazuje počet potvrzení beze změny. Po pěti
takových potvrzeních za sebou, bez odmítnutí a s vyplněným rozsahem částky, je
připravené k povýšení a tlačítko **Povýšit na automatiku** se zvýrazní. Povýšit
můžete i pravidlo bez této historie: tlačítko je u každého aktivního návrhového
pravidla a režim lze změnit také v úpravě pravidla. Takové vynucené povýšení
musíte výslovně potvrdit a v historii pravidla je označené jako ruční. Ručně
povýšené pravidlo pak účtuje samo i bez předchozích použití a bez rozsahu
částky; strop automatiky, uzavřené období, anomálie a denní limit platí dál.
K povýšení nikdy nedojde samo, rozhodnutí vždy potvrdí člověk. Tlačítko **Historie** zobrazí časovou osu
pravidla, zaznamenané změny kontace, autora a úspěšnost.

Pokud automatický zápis stornujete, pravidlo se bezpečně vrátí do režimu **jen
návrhy** a začne znovu sbírat potvrzení. Storno se nepočítá jako odmítnutí;
samostatná ochrana tří odmítnutí různých transakcí zůstává zachovaná.

Správce může pravidelně spouštět miner korekcí. Ten hledá nejméně tři
konzistentní ruční opravy stejného bankovního protějšku a vytvoří z nich pouze
nové návrhové pravidlo. Nikdy tím nezapne plnou automatiku a ignoruje
saldokontní účty i rozporné vzory. Standardní plánovač jej spouští denně ve
4:00; AI worker pro povolené firmy zpracovává čekající úlohy každých deset
minut.

### 53.11.9 AI návrhy účtování

AI asistence je ve výchozím stavu vypnutá. Správce ji může zapnout v části
**Firma → AI nastavení → AI asistence účtování** zvlášť pro bankovní
transakce a přijaté faktury. Před zapnutím je nutné potvrdit zpracovatelskou
smlouvu (DPA) s právě zvoleným poskytovatelem. Po změně poskytovatele je
vyžadováno nové potvrzení; bez něj systém žádná data neodešle. Rozbalovací AI
dotaz se v dialogu **Zaúčtovat** zobrazí až tehdy, když je tato volba zapnutá,
rozsah obsahuje bankovní transakce, poskytovatel má vyplněné přihlašovací údaje,
vyhovuje rezidenční politice a DPA je potvrzená.

Před odesláním se údaje omezí a pseudonymizují. Poskytovatel dostane částku,
měnu, měsíc, směr pohybu, kód banky a nevratné otisky identifikátorů. Variabilní
symbol se odešle jen jako obecný tvar a volný text se redukuje na povolené
účetní pojmy. Jména, e-mailové adresy, telefonní čísla, čísla účtů, IBAN a
samotné variabilní symboly se neposílají. Pseudonymizační klíč zůstává pouze v
databázi dané firmy.

AI používá dva zdroje návrhů:

- podobnost s dříve potvrzenými zápisy dané firmy; tento režim se aktivuje po
  nejméně 20 naučených rozhodnutích a nikdy neporovnává data jiné firmy,
- jazykový model pro případy, kde známý vzor nestačí.

V dialogu **Zaúčtovat** lze rozbalit položku **Zeptat se AI na kontaci** a
doplnit účetní kontext, například „jde o výběr kartou do pokladny“. Dotaz se
před odesláním rovněž očistí od osobních a identifikačních údajů. Výsledek pouze
předvyplní účty MD/D; zápis vznikne až po jejich kontrole a potvrzení tlačítkem
pro zaúčtování. Dotaz lze upravit a odeslat znovu.

Každé použití **Zeptat se AI** se uloží do auditní stopy návrhu, včetně použitého
poskytovatele/modelu a výsledku. Pokud úsporný model vrátí prázdnou, neplatnou
nebo příliš nejistou odpověď pro bankovní kontaci, systém smí provést právě
jeden ohraničený pokus silnějším modelem. I tento krok je zaznamenaný. Když ani
druhý výsledek není použitelný nebo poskytovatel selže, nic se nepředvyplní a
položka zůstane k ručnímu zpracování; nevzniká žádná opakovací smyčka ani
náhradní automatické zaúčtování.

U rozpracované přijaté faktury může AI navrhnout nákladový nebo majetkový účet
a kategorii. Návrh lze jednotlivě použít nebo odmítnout. Doklady s ručním
rozdělením DPH, dlouhodobým majetkem nebo již uzamčené doklady zůstávají pod
výhradně ruční kontrolou.

Každý AI návrh má nízký strop jistoty, nikdy se nezaúčtuje automaticky a není
součástí hromadného schválení. Pokud účetní schválí méně než polovinu posledních
návrhů, systém daný AI zdroj pozastaví. Správce jej může po kontrole v nastavení
znovu povolit. Denní limit chrání firmu před nečekanou spotřebou placeného
poskytovatele.

### 53.11.10 Klávesové zkratky

Na kartách fronty lze urychlit opakovanou práci:

| Klávesa | Akce |
|---|---|
| **J** / **K** | další / předchozí řádek |
| **Enter** | rozbalit nebo zavřít detail |
| **A** | schválit vybraný návrh |
| **X** | zamítnout vybraný návrh |
| **Shift+A** | hromadně schválit označené způsobilé návrhy |
| **1**, **2**, **3** | přepnout hlavní pracovní záložku |

Zkratky se nespouštějí při psaní do filtrů nebo jiných vstupních polí.

### 53.11.11 Časté otázky

**Může automat zaúčtovat něco do uzavřeného období?**

Ne. Uzavřené období i měkký zámek mají před automatikou přednost.

**Co když pravidlo navrhne špatný účet?**

Návrh zamítněte a pravidlo opravte. Při opakovaných zamítnutích se pravidlo
vypne a objeví se ve frontě k zásahu.

**Je vysoká jistota zárukou správnosti?**

Ne. Je to informace o kvalitě shody, nikoli náhrada účetního posouzení.

**Může AI návrh projít bez mé kontroly?**

Ne. AI návrhy jsou vždy jednotlivé, ručně kontrolované a vyloučené z hromadného
schválení i automatického zaúčtování.

**Co se stane při kliknutí na Vrátit zpět?**

Vznikne auditovatelné storno v původním otevřeném období a návrh se vrátí do
fronty. Pokud je období už uzavřené, systém operaci odmítne beze změny dat.

**Proč položku vidím, ale nemohu ji schválit?**

Nejčastěji je období zamčené nebo máte pro danou firmu pouze právo čtení. Stav
ověřte u správce rolí nebo osoby odpovědné za účetní období.

**Musím přepínat firmu?**

Ano. Automat ukazuje jen firmu zvolenou v hlavní liště aplikace a server při každé akci znovu ověří vaše oprávnění k této firmě.

Další informace o importu pohybů a bankovních pravidlech jsou v kapitole
[Banka - výpisy a párování](29_Banka.md). Účetní období a uzávěrku popisuje
kapitola [Účetní období a uzávěrka](72_Uzaverka.md).

## 53.12 Související kapitoly

- [Průvodce účetního](50_Pruvodce_ucetniho.md) - jak Automat zapadá do denního, měsíčního a ročního postupu
- [K doúčtování](54_Rucni_fronta_doctovani.md) - případy, pro které nevznikl žádný návrh
- [Úplnost dokladů](61_Uplnost_dokladu.md) - kontrola bankovních pohybů bez dokladu a dokladů po splatnosti
- [Asistent nastavení účtování](65_Sablony.md#658-krok-za-krokem-asistent-nastaveni-uctovani) - první sada pravidel z historie
- [Banka - výpisy a párování](29_Banka.md) - import pohybů a bankovní pravidla
- [Účetní období a uzávěrka](72_Uzaverka.md)
