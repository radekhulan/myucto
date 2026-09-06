# 43. Průvodce účetního

Tahle kapitola není popis jedné obrazovky — je to **mapa a doporučený postup**
napříč celou aplikací z pohledu účetní/účetního, který v MyÚčto.cz vede
podvojné účetnictví jedné nebo víc firem. Ostatní kapitoly popisují jednotlivé
stránky do detailu; tahle kapitola říká, **jak systém účtuje**, **kdy na kterou
stránku sáhnout** a v jakém pořadí věci na sebe navazují — od jednoho dokladu až
po roční závěrku.

Pokud vedeš **daňovou evidenci** místo podvojného účetnictví, sekce menu
**Účetnictví** se ti nezobrazí — místo ní máš **Daňová evidence**
([§ 51](90_Danova_evidence.md): peněžní deník, pohledávky a závazky). Tahle
kapitola je pro režim **podvojné účetnictví** (`double_entry`).

## 43.1 Než začneš — co je „zaúčtováno" a proč na tom všechno stojí

Vydaná i přijatá faktura, bankovní/pokladní pohyb i majetkový doklad mohou
existovat v aplikaci **bez zápisu v účetním deníku** — vystavení dokladu a
jeho zaúčtování jsou dva oddělené kroky. Dokud doklad není zaúčtovaný,
nepromítne se do hlavní knihy, výsledovky, rozvahy ani do předvahy pro DPH
kontrolu.

- Na detailu vydané faktury ([§ 14](14_Faktury.md)) i přijaté faktury
  ([§ 23](23_Prijate_faktury.md)) je tlačítko **Zaúčtovat** — vytvoří zápis
  podle [předkontace](88_Ucetni_nastroje.md#883-predkontace)
  a doklad dostane účetní ikonu **Zaúčtováno** s tooltipem a odkazem na zápis v deníku.
- Badge **Nezaúčtováno** vidíš přímo v seznamech faktur (filtr **Zaúčtování**
  ve FilterBar) i na dlaždici **Akce k řešení** na [Přehledu](10_Prehled.md) —
  to je tvůj denní vstupní bod, kolik dokladů ještě čeká na zaúčtování.
- Když zaúčtování selže, aplikace vrátí srozumitelnou chybu místo tichého
  selhání — přehled chybových hlášek a jak je opravit viz
  [§ 16.1.3 Zaúčtování do deníku](16_Faktura_PDF.md#1613-zauctovani-do-deniku)
  a [ochrany účtu při zaúčtování](81_Ucetni_osnova.md#816-ochrany-pri-uctovani).
- U banky ([§ 28](29_Bankovni_ucty.md#298-automaticke-zauctovani-bankovnich-transakci-jen-podvojne-ucetnictvi))
  a párovaných plateb ([§ 28.7](28_Banka.md#287-automaticke-zauctovani-sparovanych-plateb-jen-podvojne-ucetnictvi))
  lze zaúčtování z velké části zautomatizovat pravidly — ušetří to ruční
  zaúčtování běžných plateb.

## 43.2 Jak systém účtuje

MyÚčto nemá jeden univerzální automat, který by „uměl účtovat". To, **jaký
zápis vznikne**, skládají čtyři nezávislé vrstvy nastavení. Teprve nad nimi
stojí **automatika**, která rozhoduje o něčem úplně jiném: jestli hotový
výsledek rovnou zapíše do deníku, nebo ti ho předloží ke schválení.

Ten rozdíl je praktický. Je-li zápis **věcně špatně**, oprav vrstvu, která o něm
rozhodla. Je-li zápis správně, ale **nemá vznikat sám**, sáhni na automatiku.
Nastavování automatiky nikdy neopraví špatný účet a naopak.

Jedno platí vždycky: ať zápis vznikne jakkoli, prochází stejnou vnitřní službou,
která hlídá podvojnost (Σ MD = Σ Dal), otevřenost účetního období, zámek k datu
a idempotenci. V deníku proto nikdy nevznikne nevyrovnaný zápis ani dvojí
zaúčtování téhož dokladu — viz [Účetní deník](45_Ucetni_denik.md).

### 43.2.1 Čtyři vrstvy, které určují kontaci

| # | Vrstva | Co určuje | Na co se použije | Kde ji najdeš |
|---|---|---|---|---|
| 1 | **Předkontace** | výchozí dvojici účtů **MD/Dal** pro systémový typ operace (stabilní klíč, například `invoice.services.issued` nebo `payment.receivable.bank`) | každý doklad, který systém účtuje sám | **Nástroje → Účetní nastavení**, záložka **Předkontace** |
| 2 | **Pravidla nákladů** | druh řádku přijaté faktury a konkrétní nákladový účet | řádky přijatých faktur | **Nástroje → Šablony účtování**, záložka **Pravidla nákladů** |
| 3 | **Pravidla účtování** | kontaci opakovaného bankovního pohybu, ke kterému neexistuje doklad | bankovní pohyby bez faktury (poplatky, odvody, úroky, nájem, splátky) | **Nástroje → Šablony účtování**, záložka **Pravidla účtování**, nebo **Peníze → Bankovní účty → Pravidla účtování** |
| 4 | **Šablony zápisů** | předvyplněné řádky ručního zápisu | ruční zápis v deníku a náhled kontace dokladu | **Nástroje → Šablony účtování**, záložka **Šablony zápisů** |

Vrstvy se **nepřekrývají** a nedají se nahradit jedna druhou:

- **Předkontace není šablona.** Je to mapa „typ operace → dvojice účtů". Systém
  má zhruba padesát globálních klíčů (fakturace, úhrady, pokladna, zálohy, mzdy,
  DPH, kurzové rozdíly, opravné položky, rezervy, časové rozlišení, dohadné
  položky, odpisy, vyřazení majetku, inventarizační rozdíly, odvody). Firma
  u nich může přepsat jen účty MD/Dal — **nový druh operace nezaložíš**, a
  firemní override vždy přebije globální hodnotu. Prázdná strana může být záměr:
  protiúčet doplní podle dokladu služba, třeba u kurzového rozdílu. DPH na 343
  v mapě není vůbec; dopočítává ji daňová služba z položek přes knihu DPH.
- **Pravidlo nákladů nevybírá kontaci**, vybírá **druh řádku**. Kontaci pak
  určí předkontace odpovídající tomuto druhu.
- **Bankovní pravidlo není šablona zápisu s pevnou částkou.** Rozpoznává
  transakci; částku bere z pohybu.
- **Šablona zápisu neúčtuje.** Jen předvyplní řádky, které účetní doplní
  a potvrdí.

Když se výsledek nelíbí, postupuj po vrstvách odshora:

1. **špatně rozpoznaný druh nákladu** → oprav pravidlo nákladů nebo přímo řádek
   dokladu,
2. **správný druh, ale chybný základní účet** → oprav předkontaci,
3. **nestandardní vícerádkový zápis** → použij nebo uprav šablonu zápisů,
4. **opakovaná platba bez dokladu účtovaná špatně** → oprav bankovní pravidlo.

Podrobně všechny čtyři popisuje kapitola [Šablony a pravidla](80_Sablony.md),
předkontace samotné [§ 88.3](88_Ucetni_nastroje.md#883-predkontace).

> [!TIP]
> Firmě s naimportovanou historií, která ještě žádná pravidla nemá, sestaví
> první sadu **Nástroje → Asistent nastavení účtování**
> ([§ 80.7](80_Sablony.md#807-asistent-nastaveni-uctovani)). Projde existující
> doklady a navrhne analytické účty, pravidla nákladů, předkontace i bankovní
> pravidla. Nic nezaloží bez tvého výslovného schválení.

### 43.2.2 Co který doklad spustí

| Událost | Co systém udělá | Podle čeho |
|---|---|---|
| **Vystavení vydané faktury** | zápis 311 / 6xx + DPH na výstupu 343.200 | předkontace podle klíče výnosu na hlavičce (výchozí `invoice.services.issued`, 602), kniha DPH |
| **Přijetí přijaté faktury** | zápis 5xx nebo 04x / 321 + DPH na vstupu 343.100 | druh řádku z pravidel nákladů → předkontace, kniha DPH |
| **Spárování bankovní platby s dokladem** | přímý zápis 221/311, 321/221, 221/324 (inkaso zálohy), 314/221 (poskytnutá záloha) | předkontace `payment.*` |
| **Bankovní pohyb bez dokladu** | zápis nebo návrh podle vestavěného rozpoznání, pravidla nebo naučené kontace | pravidla účtování, registr vlastních účtů, mapa odvodů, historie oprav |
| **Pokladní doklad** | zápis pokladny | předkontace |
| **Zařazení, vyřazení a odpis majetku** | zápis odpisu nebo pohybu karty | [§ 49 Majetek](78_Majetek.md) |
| **Skladový pohyb, zápočet, měsíční zúčtování DPH, uzávěrkové operace** | vlastní zápisy s odpovídajícím zdrojem | příslušné kapitoly |
| **Schválení mzdového běhu** | rozdílový mzdový deník | předkontace **zmrazené při uzamknutí vstupů** — pozdější změna nastavení už zkontrolovanou revizi nepřepíše |
| **Ruční zápis v deníku** | to, co zadáš | případně šablona zápisů |

Mzdové platby se z banky **neúčtují**: pohyb si nárokuje mzdový modul, aby se
závazek neodúčtoval dvakrát. Vypořádání se řeší v
[Mzdových příkazech a úhradách](65_Platby_a_uhrady.md) a kontroluje na
[Shodě účtování mezd](64_Shoda_uctovani_mezd.md).

> [!IMPORTANT]
> **Automatika účtování je háček na VZNIK dokladu, ne zametač existujících.**
> Spustí se při vystavení faktury, přijetí přijaté faktury nebo opakované
> fakturaci. Doklad, který už v systému leží — typicky **naimportovaný z jiného
> systému** — jí neprojde nikdy, ať je nastavená jakkoli. Takové doklady
> zaúčtuje **Účetnictví → Doúčtovat doklady**
> ([§ 45.12](45_Ucetni_denik.md#4512-douctovani-nezauctovanych-dokladu)):
> ukáže, kolik dokladů čeká zvlášť po typech, umí **Zkusit nanečisto** i běh
> **Zastavit**, každý doklad účtuje samostatně (jeden vadný dávku nezastaví) a
> nemá strop 500 dokladů na dávku jako hromadné zaúčtování ze seznamu faktur.
> Účtuje vydané a přijaté faktury; pokladna, banka a zápočty mají vlastní cesty.

### 43.2.3 Kde se automatika zapíná a co přesně smí

Všechno se nastavuje na jednom místě: **Firma → Nastavení → Daně a účetnictví**.
Tentýž box je i v **Účetnictví → Automat → Pravidla**, takže kvůli změně režimu
nemusíš odcházet z fronty.

**Dvě samostatná zaškrtávátka** řídí háček na vznik dokladu:

- **Automaticky účtovat vydané faktury** — po vystavení faktury ji rovnou
  zaúčtovat. Chyba zaúčtování vystavení nezablokuje; faktura zůstane vystavená
  a zaúčtuješ ji ručně.
- **Automaticky účtovat přijaté faktury** — totéž pro přijaté doklady.

**Box Automatika účtování** řídí zbytek — hlavně banku:

| Volba | Význam |
|---|---|
| **Celkový režim: vypnuto** | Automat nevytváří ani neúčtuje návrhy. |
| **Celkový režim: jen návrhy** | Všechny rozpoznané operace čekají na potvrzení. |
| **Celkový režim: asistovaná** | Samy se účtují jen spárované platby, převody mezi vlastními účty, sociální a zdravotní pojištění OSVČ a obě vestavěná rozpoznávání. Zbytek zůstává jako návrh. |
| **Celkový režim: plná automatika** | Samy se účtují všechny deterministické operace **kromě** AI návrhů, naučených kontací, paušální daně a ostatních odvodů — ty zůstávají návrhem. |
| **Denní limit automatiky (Kč)** | Celofiremní strop na objem zaúčtovaný za den. Prázdná hodnota znamená bez limitu; po jeho vyčerpání se další položky degradují na návrh. |
| **Ranní přehled e-mailem** | Souhrn fronty; neprovádí žádnou účetní operaci. |
| **Nastavit jednotlivé typy operací** | Rozpad celkového režimu na konkrétní typy — každý zvlášť na úrovni **vypnuto / jen návrhy / plná automatika**. |

Podrobné nastavení má čtyři skupiny:

- **Platby a převody** — Vystavené faktury, Přijaté faktury, Spárované bankovní
  platby, Převody mezi vlastními účty.
- **Odvody** — Sociální a Zdravotní pojištění OSVČ, Sociální a Zdravotní
  pojištění zaměstnavatele, Platby DPH, Odvod daně v režimu OSS, Daň z příjmů,
  Srážková daň, Daň ze závislé činnosti, Daň z nemovitých věcí, Silniční daň,
  Paušální daň, Ostatní odvody.
- **Banka** — Bankovní úroky, Bankovní poplatky, Vlastní bankovní pravidla,
  Naučené kontace, Rozpoznávání odvodů, Rozpoznávání vlastních převodů.
- **AI** — AI návrhy bankovních plateb, AI návrhy dokladů.

Rozpoznávání je nadřazené: vypneš-li **Rozpoznávání odvodů** nebo
**Rozpoznávání vlastních převodů**, klesnou s ním i všechny operace, které se
o něj opírají — vyšší úroveň u jednotlivého odvodu se pak neuplatní.

> [!IMPORTANT]
> **Výchozí stav po zapnutí podvojného účetnictví je plná automatika** a obě
> zaškrtávátka automatického účtování faktur zapnutá. Firmě, která teprve
> zavádí účetnictví nebo importovala cizí historii, doporučujeme hned na začátku
> přepnout celkový režim na **jen návrhy**, několik dní kontrolovat výsledky ve
> frontě a teprve ověřeným typům operací úroveň zvyšovat. Cesta zpět je vždy
> otevřená.

Úroveň je vždy jen **horní hranice**. Bez ohledu na nastavení se **nikdy
nezaúčtuje samo**:

- **AI návrh** — technicky vyloučený, nejde povolit ani omylem,
- **nejednoznačná shoda** — dvě stejně silná pravidla skončí jako návrh
  s důvodem **konflikt pravidel**,
- **cizoměnový nespárovaný pohyb**,
- **pohyb v uzavřeném nebo zamčeném období**,
- **položka nad stropem pravidla nebo nad denním limitem**,
- **operace na saldokontním účtu** (311, 321, 314, 324, 325) mimo řádné
  spárování platby s dokladem,
- **úhrada závazku** (336, 342, 343, 345) z 221 **bez existujícího zaúčtovaného
  předpisu** v dostatečné výši — jinak by vznikl nepodložený zůstatek,
- **podezření na duplicitu** a **nízká jistota** — ty jdou rovnou do fronty
  **Vyžaduje zásah**.

Vlastní převod se účtuje sám jen mezi evidovanými vlastními účty ve stejné měně.
Uživatelské bankovní pravidlo smí účtovat samo teprve tehdy, když je povýšené na
automatický režim, má vyplněný rozsah částky a už nejméně třikrát uspělo.

### 43.2.4 Návrhy: co znamená „Proč" a jistota

Každý návrh i automatický zápis nese štítek **Proč**, který říká, která vrstva
rozhodla:

| Štítek | Rozhodla | Účtuje samo? |
|---|---|---|
| **Faktura** | platba byla spárována s konkrétním dokladem | ano, podle politiky |
| **Pravidlo** | pojmenované bankovní pravidlo | ano, je-li povýšené a v limitu |
| **Systémové rozpoznání** | vestavěná detekce (vlastní převod, odvod) | ano, podle politiky |
| **Naučeno** | shoda s dříve potvrzenými zápisy téže firmy | jen jako návrh |
| **Předpis zálohy** | evidovaný předpis | ano, podle politiky |
| **AI návrh** | jazykový model nebo podobnost | **nikdy** — ani hromadně |

Jistota (slovně i procentem) vyjadřuje sílu shody podle dostupných dat, ne
účetní správnost. Prakticky platí, že položka pod velmi vysokou jistotou
se zaúčtovat sama nemůže a při opravdu nízké jistotě rovnou padá do
**Vyžaduje zásah**. U návrhu s vysokou jistotou, který přesto čeká, Automat
vypíše konkrétní pojistku — překročený strop, denní limit, chybějící předpis,
anomálii nebo uzavřené období.

### 43.2.5 Kam výsledek doputuje — tři fronty, tři různé otázky

| Stránka | Odpovídá na otázku | Provádí akce? |
|---|---|---|
| [**Účetnictví → Automat**](46_Automat.md) | Co systém zaúčtoval, co navrhl a co v návrhu potřebuje rozhodnutí? | Ano — schválit, zamítnout, upravit kontaci, odložit, stornovat. |
| [**Účetnictví → K doúčtování**](47_Rucni_fronta_doctovani.md) | Který známý případ nemá hotový zápis a **nevznikl pro něj vůbec žádný návrh**? | Ne — jen odkazuje na zdroj. |
| [**Účetnictví → Úplnost dokladů**](54_Uplnost_dokladu.md) | Které bankovní pohyby nemají doklad a které otevřené doklady jsou po splatnosti? | Ne — kontrolní sestava s agingem. |

Bankovní pohyb, pro který **existuje návrh v jakémkoli stavu**, je vždy jen
v Automatu; do fronty K doúčtování se dostanou jen dva důvody — **nenalezeno
pravidlo** a **cizoměnová operace není podporovaná automatikou**. Fronty se tak
záměrně nepřekrývají. Nezaúčtovaný **doklad** naopak může být vidět v obou — je
to týž případ ve dvou pracovních pohledech.

Automat má sedm záložek: **Doporučení** (výchozí — hledá příležitosti
k automatizaci nad existující historií), **Zaúčtováno dnes**, **Ke schválení**,
**Vyžaduje zásah**, **Pravidla**, **Checklist** a **Historie**.

### 43.2.6 Náklady: od PDF přijaté faktury k účtu

1. Doklad se do systému dostane e-mailem, uploadem, přes ISDOC nebo přes
   [AI extrakci](25_AI_extrakce.md) (**Nákup → AI import**), která vyplní
   hlavičku a řádky.
2. Na každém řádku se určí **druh nákladu**. Rozhodují tři vrstvy v tomto
   pořadí: **firemní pravidlo nákladů** → **katalog frází** → **vestavěná
   klíčová slova**. Neuspěje-li nic, řádek zůstane na výchozí hodnotě a
   rozhodne účetní.

   | Druh nákladu | Výchozí účet |
   |---|---|
   | služba | 518 |
   | materiál | 501 |
   | drobný majetek | 501 |
   | drobný nehmotný majetek | 518 |
   | dlouhodobý majetek | 042 |

   Místo výchozího účtu může pravidlo určit konkrétní aktivní nákladový účet.
   Saldokonto, DPH, banku ani pokladnu jako cíl nastavit nelze.
3. Kritéria pravidla — konkrétní dodavatel, fragment názvu dodavatele, fragment
   popisu položky — se vyhodnocují **současně (AND)** a aspoň jedno z nich musí
   být vyplněné. **Rozpětí částky** je jen zúžení podle ceny za kus bez DPH,
   ne samostatné kritérium; pravidlo postavené jen na ceně by zachytávalo
   nesouvisející nákupy. Priorita **0–999, výchozí 100, nižší jde první**;
   vyhraje první shoda.
4. Každé pravidlo má **Režim použití**: **Jen navrhovat** nebo **Použít
   automaticky**. Pravidla z asistenta i z Automatu vznikají vždy v režimu
   návrhu. I automatické použití je jen předvyplnění — ruční volba účtu na
   řádku je vždy silnější a nepřepíše se.
5. Systém sám hlídá dvě věci, které se pletou nejčastěji:
   - **práh § 26 odst. 2 ZDP** — řádek označený jako drobný majetek, jehož cena
     za kus překročí zákonný limit (výchozích 80 000 Kč), se překlopí na
     **dlouhodobý majetek** a účet 042 s odpisy;
   - **osobní a nedaňové výdaje podle § 25 ZDP** (typicky optika nebo chytré
     hodinky) dostanou jen nízkou jistotu, zůstanou jako služba a **účet
     nedostanou vůbec** — volba mezi 528 a 513 je rozhodnutí účetní jednotky.
6. **Účetní potvrdí řádek.** Do uložení jde pořád jen o návrh.
7. Zaúčtování použije **předkontaci odpovídající potvrzenému druhu**. Faktura se
   rozpadá **po položkách**, takže jeden doklad může mít víc nákladových noh
   s různými účty. DPH jde odděleně přes knihu DPH; neodpočitatelná část daně se
   přičte k nákladu.
8. **Daňovou uznatelnost nese účet**, ne pravidlo. Nedaňový náklad se účtuje na
   účet označený v účtovém rozvrhu jako daňově neuznatelný (528, 513) a odtud si
   ho sečte řádek 40 přiznání k dani z příjmů právnických osob.
9. U drobného majetku lze z potvrzených řádků založit evidenční karty — karta
   nevytváří druhý nákladový zápis ([§ 27](27_Drobny_majetek.md)).

> [!IMPORTANT]
> Cenový práh ani slovo v popisu nerozhoduje, jestli jde o drobný či dlouhodobý
> majetek, technické zhodnocení nebo soubor věcí. Návrh je pomůcka; zařazení,
> daňovou uznatelnost a období nákladu potvrzuje účetní podle vnitřní směrnice.
> AI návrh druhu nákladu má z principu tak nízkou jistotu, že se sám nikdy
> nepoužije.

### 43.2.7 Bankovní výpisy: od výpisu k zápisu

1. **Import.** GPC výpis nebo PDF podporované banky nahraješ v
   **Peníze → Bankovní výpisy**, nebo pohyby dorazí z e-mailových avíz
   ([§ 29](29_Bankovni_ucty.md)). Avízo je provizorní, **nikdy se neúčtuje** a
   po příchodu oficiálního výpisu mu párování předá.
2. **Párování.** Platba se spáruje podle variabilního symbolu, ručně, nebo jako
   sloučená úhrada ([§ 28.4](28_Banka.md#284-detail-vypisu)).
3. **Spárovaná platba se účtuje přímo** podle předkontací `payment.*` — běžná
   vydaná faktura 221/311, běžná přijatá 321/221, proforma 221/324, přijatá
   zálohová 314/221. U cizoměnové úhrady se dopočítá kurzový rozdíl (563/663).
   Na spárované platby faktur bankovní pravidla nesahají.
4. **Nespárovaný pohyb** projde v tomto pořadí:
   1. **vestavěné rozpoznání** — odvody státním institucím podle mapy předčíslí
      a variabilních symbolů (DPH, DPPO, DPFO, zálohová a srážková daň ze mzdy,
      daň z nemovitých věcí, silniční daň, paušální daň, SP a ZP OSVČ) a převody
      mezi vlastními účty; rozpoznání ustoupí uživatelskému pravidlu s prioritou
      nižší než 50,
   2. **bankovní pravidlo**,
   3. **naučená kontace** z dříve potvrzených obdobných pohybů,
   4. **AI návrh**, je-li AI asistence zapnutá,
   5. **nic** — pohyb skončí v **K doúčtování** s důvodem
      **Nenalezeno pravidlo**.
5. **Cizoměnový nespárovaný pohyb** automatika nepodporuje. Dostane důvod
   **Cizoměnová operace není podporovaná automatikou** a účtuje se ručně na
   detailu výpisu.

Bankovní pravidlo si založíš na záložce **Pravidla účtování** tlačítkem
**Nové pravidlo**, rovnou z rozbalovacího menu pohybu volbou
**Vytvořit účtovací pravidlo** (formulář se předvyplní protistranou, zprávou,
směrem a měnou), nebo z hotové **šablony** v katalogu
**Nástroje → Šablony bank. pravidel** (odvody, bankovní poplatky, přijaté úroky,
nájem, předplatné). Pravidlo obsahuje:

- **směr** (příchozí/odchozí),
- **alespoň jedno kritérium shody** — protiúčet (volitelně s kódem banky a
  předčíslím), variabilní symbol, nebo fragment zprávy; fragment se hledá
  v popisu platby **i ve jménu protistrany**,
- volitelný **rozsah částky** (prázdná mez neomezuje danou stranu intervalu),
- **kontaci MD/Dal** — bankovní strana musí být účet **221**, druhá strana nesmí
  být saldokontní účet (311/321/314/324/325),
- **prioritu** — nižší číslo se vyhodnocuje dřív; hodnota pod 50 přebije
  i vestavěné rozpoznání,
- **limit pro automatiku** — nad zadanou částkou vynutí pouhý návrh, i když je
  pravidlo povýšené.

Uložení pravidla samo nic nezaúčtuje. **Otestovat na historii** je dry-run;
**Použít na historii** vytvoří **jen návrhy** pro dosud nezaúčtované pohyby
v otevřených obdobích a nikdy nepřeúčtuje už zaúčtovanou transakci.

Nové pravidlo vždy začíná v režimu **navrhovat**. Po **pěti** potvrzeních beze
změny za sebou, bez jediného odmítnutí a s vyplněným rozsahem částky nabídne
Automat tlačítko **Povýšit na automatiku** — k povýšení nikdy nedojde samo.
Naopak **tři odmítnutí na různých transakcích** pravidlo deaktivují a storno
automatického zápisu ho vrátí zpět do režimu návrhů.

### 43.2.8 Když je zápis špatně

- **Návrh, který ještě není zaúčtovaný** — v Automatu **Upravit kontaci**
  a schválit opravenou variantu (rozdíl se uloží jako učicí signál), nebo
  **Zamítnout** s uvedením důvodu. Opakovaná zamítnutí pravidlo vypnou.
- **Hotový automatický zápis** — **Stornovat** na kartě **Zaúčtováno dnes**,
  případně **Vrátit zpět** z oznámení hned po schválení. Vznikne storno **ve
  stejném datu** jako původní zápis, obojí zůstane kvůli auditu a kontace se
  vrátí do fronty. Do dnešního období systém storno potichu nepřesune — je-li
  původní období už uzavřené, operaci odmítne beze změny dat.
- **Zápis u dokladu** — oprav zdrojový doklad a zaúčtuj znovu, nebo použij
  storno. Účetní historie se nepřepisuje.
- **Systémová příčina** — po storně vždy oprav i vrstvu z
  [§ 43.2.1](#4321-ctyri-vrstvy-ktere-urcuji-kontaci), jinak stejná chyba vznikne
  příště znovu.

## 43.3 Denní cyklus

1. **[Přehled](10_Prehled.md)** → dlaždice **Akce k řešení** ukáže nejdůležitější
   termíny a nehotové doklady.
2. **K doúčtování** ([samostatná kapitola](47_Rucni_fronta_doctovani.md))
   je pracovní fronta napříč bankou, vydanými a přijatými fakturami a vyžádanými
   doklady. Začni nejstaršími položkami a důvody označenými jako blokované nebo
   bez pravidla.
3. **Přijaté faktury** ([§ 23](23_Prijate_faktury.md)) — zkontroluj výsledek AI
   extrakce a navržený druh výdaje. Návrh je pomůcka; účet, daňovou uznatelnost,
   DPH a případné zařazení do majetku potvrzuje účetní.
4. **Banka** ([§ 27](28_Banka.md)) — potvrď jednoznačná párování, vyřeš
   rozúčtované a cizoměnové pohyby a položky, pro které nevznikl návrh. Automatika
   zaúčtuje jen operace povolené firemní politikou; ostatní ponechá ke schválení.
5. **[Automat](46_Automat.md)** — v kokpitu rozlišuj **Zaúčtováno dnes**,
   **Ke schválení** a **Vyžaduje zásah**. U každého návrhu je dostupné
   vysvětlení zdroje pravidla a náhled kontace. Hromadně potvrzuj jen stejnorodé
   položky, jejichž dopad jsi zkontroloval(a).
6. **Účetní deník** ([§ 45.2](45_Ucetni_denik.md#452-seznam-zapisu)) — filtr
   **Koncept** ukáže rozpracované ruční zápisy. Oprava zaúčtovaného zápisu se
   provádí auditovanou změnou povolených údajů, stornem nebo opravou zdrojového
   dokladu, ne přepisem účetní historie.

> [!IMPORTANT]
> Prázdná fronta neznamená, že je účetnictví věcně správné. Systém umí poznat
> chybějící zaúčtování a řadu technických nesouladů, ale neumí bez podkladu
> rozhodnout například o daňové uznatelnosti, období nákladu, existenci závazku,
> tvorbě opravné položky nebo správnosti odhadu dohadné položky.

## 43.4 Měsíční cyklus

1. Dokonči [**K doúčtování**](47_Rucni_fronta_doctovani.md) a projdi
   [**Úplnost dokladů**](54_Uplnost_dokladu.md). Pohyb bez
   dokladu není automaticky náklad; vyžádej podklad, nebo účetně dolož, proč je
   zaúčtován bez něj.
2. **Mzdy.** Vede-li firma zjednodušenou
   [Mzdovou rekapitulaci](57_Mzdy.md), zkontroluj zaměstnance, měsíční vstupy a
   náhled předpisu. Vede-li **úplné mzdy**, jdi podle
   [§ 58.3](58_Uplne_mzdy.md#583-doporuceny-mesicni-postup) a výsledek ověř na
   [Shodě účtování mezd](64_Shoda_uctovani_mezd.md). V obou případech odpovídá
   za správnost vstupů, zvláštní režimy a shodu s podklady mzdové agendy účetní.
3. [**Měsíční kontrolu**](55_Mesicni_kontrola.md) spusť
   před DPH a po dokončení měsíce. Výsledek je kontrolní seznam, nikoli automatická
   oprava.
4. **[Saldokonto](53_Saldokonto.md)** — otevřené
   pohledávky a závazky per partner; použij k inventarizaci účtů 311/321 a
   k rozhodnutí, co poslat na [upomínku](22_Upominky.md) nebo na
   [zápočet](82_Zapocty.md).
5. **Hlavní kniha a předvaha** — prověř neobvyklé zůstatky, průběžné účty,
   pokladnu a vazbu banky na účetní analytiky.
6. **DPH výkazy** ([§ 35](36_Vykazy_DPH.md)) — přiznání a kontrolní hlášení;
   před podáním zkontroluj, že se čísla shodují s knihou DPH
   ([§ 36](37_Kniha_DPH.md)) a s účtem 343 v deníku.
7. Po skutečném podání nahraj XML a potvrzení do **EPO podání a archívu**.
   Samotné vytvoření ani stažení XML zámek neposouvá. Po kontrole doručenky označ
   validní DPH/KH snapshot jako **odeslaný** ručně. Tím se příslušný zámek posune.
   ([§ 45.9 Zámek účtování k datu](45_Ucetni_denik.md#459-zamek-uctovani-k-datu)).
8. **[Měsíční přehled](56_Mesicni_report.md) klientovi**
   — pokud firmu vede externí účetní pro klienta, jedno tlačítko sestaví PDF
   report za měsíc.

## 43.5 Roční cyklus — účetní uzávěrka

Celý postup vede **uzávěrkový průvodce** v [§ 50](87_Uzaverka.md), krok za
krokem:

1. [Předběžné kontroly](87_Uzaverka.md#8721-krok-1-predbezne-kontroly) —
   totéž jako měsíční kontrola, ale za celý rok.
2. [Odpisy majetku](87_Uzaverka.md#8722-krok-2-odpisy-majetku) — hromadné
   zaúčtování ročních odpisů, viz i [§ 49](78_Majetek.md#786-hromadne-zauctovani-odpisu-roku).
3. [Kurzové rozdíly](87_Uzaverka.md#8723-krok-3-kurzove-rozdily) — přecenění
   cizoměnových zůstatků k rozvahovému dni.
4. [Dohadné položky a časové rozlišení](87_Uzaverka.md#8724-kroky-4-5-dohadne-polozky-a-casove-rozliseni),
   včetně návrhů předplacených nákladů a zvolené politiky drobného majetku.
   Návrhy vycházejí z pravidel nákladů označených jako **opakovaný předplacený
   náklad**; jde o read-only doporučení, které uzávěrka teprve zaúčtuje po
   potvrzení.
5. [Opravné položky k pohledávkám](87_Uzaverka.md#8725-krok-opravne-polozky-k-pohledavkam) —
   navazuje na saldokonto ze [§ 43.4](#434-mesicni-cyklus).
6. [Daň z příjmů](87_Uzaverka.md#8726-krok-dan-z-prijmu) — mezikrok na
   [Daň z příjmů](38_Dan_z_prijmu.md).
7. **Sklad** — je-li aktivní a firma používá způsob B, zkontroluj inventuru a
   ocenění. Backend umí zaúčtovat konečný stav, manka a přebytky, ale současný
   webový průvodce skladový krok nezobrazuje; firma se skladem proto standardní UI
   cestou uzávěrku nedokončí. Bez skladu se krok přeskočí.
8. [Uzavření knih a otevření nového roku](87_Uzaverka.md#873-uzavreni-knih-a-otevreni-noveho-roku).
9. **Uzávěrkový balíček** — před schválením stáhni doložitelný ZIP sestav,
   inventarizací a daňových podkladů.
10. [Schválení závěrky](87_Uzaverka.md#874-interni-kontrola-schvaleni-zaverky-a-znovuotevreni) —
   rozdělení výsledku hospodaření (431 → 428/429/364).

Po celý rok si drž [**Rozvahu**](50_Rozvaha.md) a
[**Výsledovku**](51_Vysledovka_druhova.md)
po ruce jako průběžnou kontrolu, jestli VH ve výsledovce sedí s A.V. rozvahy —
u firem s bankovním úvěrem zkontroluj i řádek nákladových úroků (562).

## 43.6 Víc firem — účetní kancelář

Vedeš-li víc firem najednou, sekce Účetnictví začíná položkou
**[Přehled firem](44_Prehled_firem.md)** — cross-firemní pohled na termíny
DPH/KH, nezaúčtované doklady a stav uzávěrky, s proklikem a přepnutím firmy
bez návratu na dashboard.

Předkontace, pravidla, šablony i fronty jsou vždy **firemní**. Automat ani
Šablony účtování nemají vlastní výběr firmy — pracují s firmou zvolenou v hlavní
liště aplikace, takže data dvou účetních jednotek se nikdy nesmíchají. Naučené
kontace se rovněž nikdy neporovnávají přes firmy.

## 43.7 Mapa kapitol Účetnictví

| Co řešíš | Kapitola |
|---|---|
| Termíny a stav práce napříč firmami | [Přehled firem](44_Prehled_firem.md) |
| Automatické návrhy, schvalování, pravidla a historie | [Automat](46_Automat.md) |
| Zápisy, ruční zápis, storno, zámek k datu | [§ 44 Účetní deník](45_Ucetni_denik.md) |
| Doklady a pohyby, které čekají na ruční rozhodnutí | [K doúčtování](47_Rucni_fronta_doctovani.md) |
| Bankovní pohyby bez podkladu a doklady po splatnosti | [Úplnost dokladů](54_Uplnost_dokladu.md) |
| Průběžná kontrolní brána za měsíc, kvartál či vlastní rozsah | [Měsíční kontrola](55_Mesicni_kontrola.md) |
| Sestavení, PDF a odeslání klientského reportu | [Měsíční přehled](56_Mesicni_report.md) |
| Účtový rozvrh a kontrola účtu | [Účtový rozvrh](81_Ucetni_osnova.md) |
| Předkontace, šablony zápisů, pravidla nákladů a bankovní pravidla | [Šablony a pravidla](80_Sablony.md), [Nástroje](88_Ucetni_nastroje.md) |
| Hlavní kniha | [Hlavní kniha](48_Hlavni_kniha.md) |
| Předvaha | [Obratová předvaha](49_Obratova_predvaha.md) |
| Rozvaha | [Rozvaha](50_Rozvaha.md) |
| Výsledovka | [Druhová](51_Vysledovka_druhova.md) a [účelová](52_Vysledovka_ucelova.md) |
| Otevřené pohledávky a závazky | [Saldokonto](53_Saldokonto.md) |
| Měsíční kontroly, úplnost dokladů, K1–K10 a inventarizace | [Účetní kontroly a inventarizace](79_Ucetni_kontroly_a_inventarizace.md) |
| Mzdová rekapitulace, kontace a mzdový list | [Mzdy](57_Mzdy.md) |
| Úplný mzdový modul a měsíční mzdový běh | [Úplné mzdy](58_Uplne_mzdy.md) |
| Dlouhodobý i drobný majetek, odpisy, inventární karty | [§ 49 Majetek a odpisy](78_Majetek.md) |
| Uzávěrkový průvodce, kontroly K1–K10, balíček, archiv | [§ 50 Účetní období a uzávěrka](87_Uzaverka.md) |
| DPH přiznání, kontrolní hlášení, kniha DPH | [§ 35](36_Vykazy_DPH.md), [§ 36](37_Kniha_DPH.md) |
| Bankovní a pokladní zaúčtování | [§ 27–29 Peníze](28_Banka.md) |
