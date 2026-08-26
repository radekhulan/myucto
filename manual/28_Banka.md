# 28. Banka — import výpisů a párování plateb

Místo ručního označování faktur jako zaplacených naimportuj **GPC/ABO nebo
podporovaný PDF výpis** z banky. Systém pohyby deduplikuje, nabídne jejich
párování s doklady a v podvojném účetnictví připraví bezpečné zaúčtování.

Návrhy zaúčtování, položky vyžadující zásah a historii automatických rozhodnutí
najdete souhrnně v kapitole [Automat účtování](46_Automat.md).

GPC (ABO) je standardní český formát pro elektronickou výměnu výpisů. Umí ho
exportovat: **KB**, **Fio Bank**, **ČSOB**, **Raiffeisenbank**, **Česká
spořitelna**, **mBank**, a další.

> [!TIP]
> Místo (nebo vedle) výpisů umí systém zpracovávat i **bankovní e-mailová avíza**
> z IMAP schránky — hodí se, když banka posílá oznámení o příchozí platbě rychleji
> než pravidelný výpis. Konfigurace bankovních účtů, IMAP schránek a parserů má
> vlastní kapitolu [Bankovní účty a e-mailová avíza (IMAP)](29_Bankovni_ucty.md)
> — najdeš ji na dalších záložkách téže stránky **Peníze → Bankovní účty**.

## 28.1 Stažení GPC výpisu z banky

Postup je v každé bance trochu jiný:

| Banka | Cesta v internet bankingu |
|---|---|
| **KB** | Účet → Historie pohybů → Export → formát „GPC ABO" |
| **Fio** | Přehled účtu → Stažení dat → formát „GPC" |
| **ČSOB** | Účet → Výpisy → Stáhnout → formát „ABO" |
| **Raiffeisen** | Detail účtu → Pohyby → Export → ABO formát |
| **ČS** | Detail účtu → Výpisy → formát „ABO" |

Stáhni soubor (typicky `.gpc` nebo `.abo`, někdy `.txt`). Velikost ~10–100 KB
na měsíc obvykle.

## 28.2 Upload výpisu do MyÚčto

V hlavním menu **Peníze → Bankovní účty**, záložka **Bankovní výpisy** →
tlačítko **Nahrát GPC/ABO nebo PDF**.

![Upload výpisu](img/11_banka_upload.webp)

> 💡 **PDF výpis místo GPC.** Některé banky GPC/ABO export nenabízejí (Banka
> CREDITAS), případně ho konkrétní účet nemá zapnutý. Pro **Creditas, ČSOB, KB
> a Raiffeisenbank**
> proto stačí nahrát rovnou **PDF výpis** — systém ho deterministicky rozparsuje
> na transakce (bez AI) a ověří, že součet sedí na počáteční a konečný zůstatek
> z hlavičky (na haléř přesně). Dál to funguje úplně stejně jako GPC — párování,
> stavy účtů i originál ke stažení. Jeden soubor může být GPC/ABO nebo PDF,
> rozhoduje přípona; naráz jde vybrat i mix obojího.

Vyber soubor (drag & drop nebo klik). Po nahrání:

1. **Kontrola duplicit** — SHA-256 odmítne opakovaný stejný soubor; stabilní otisk
   jednotlivých pohybů navíc přeskočí transakce, které se opakují v překrývajícím
   se denním a měsíčním výpisu. Několik skutečných pohybů se shodným datem,
   částkou, VS a popisem v jednom souboru se přitom zachová jako samostatné
   transakce — rozhoduje i pořadí jejich výskytu v souboru.
2. **Validace bankovního účtu** — server zkontroluje, že číslo účtu z hlavičky
   výpisu patří některé z měn aktuálního dodavatele.
3. **Parsing transakcí** — přečte všechny řádky.
4. **Auto-matching** — pro příchozí i odchozí pohyby se vyhodnotí VS, zbývající
   částka, číslo dokladu ve zprávě, známý účet protistrany, název a blízkost
   splatnosti. Jednoznačná shoda se potvrdí, slabší se jen nabídne.
5. **Update faktur** — plně uhrazený doklad dostane stav `paid` a datum úhrady
   podle bankovní transakce; nižší platba se zapíše jako částečná a sníží
   zbývající částku.

Výsledek rozlišuje počet pohybů nalezených v souboru, počet skutečně založených
a počet automaticky spárovaných. Jestliže se některé pohyby shodují s již
evidovanými, zobrazí se samostatné varování s počty **nalezeno / založeno /
přeskočeno jako duplicita**. U dávkového nahrání se přeskočené pohyby sečtou do
společného varování. Zkontroluj je, zvlášť pokud nejde o očekávaný překryv výpisů.

Příklad výsledku bez přeskočených duplicit:

```
Importováno: 12 transakcí, spárováno: 8, k manuálnímu párování: 4.
```

## 28.3 Seznam výpisů

Záložka **Bankovní výpisy** ukáže historii.

Nad seznamem lze hledat také uvnitř transakcí všech výpisů:

- podle libovolné části čísla protiúčtu nebo IBANu (mezery, pomlčky a lomítka
  se při porovnání ignorují),
- podle zákazníka nebo dodavatele, a to přes spárovanou vydanou či přijatou
  fakturu nebo evidovaný účet obchodního partnera,
- podle přesné částky bez ohledu na znaménko, takže například `1 500` najde
  příchozí `1 500` i odchozí `-1 500`.

Filtry prohledávají všechny příchozí i odchozí platby a kombinují se; výsledkem
jsou výpisy obsahující alespoň jednu transakci, která splňuje všechna zadaná
kritéria.

V podvojném účetnictví lze navíc zvolit **Výpisy s nezaúčtovanými pohyby**. Seznam
u každého výpisu ukáže jejich počet. Ignorované položky a provizorní e-mailová avíza
se do tohoto počtu nezahrnují.

| Sloupec | Význam |
|---|---|
| Datum | Datum výpisu |
| Číslo | Číslo výpisu z banky |
| Účet | Číslo účtu / IBAN |
| Měna | CZK / EUR / … |
| Příchozí | Suma kreditních transakcí |
| Odchozí | Suma debetních transakcí |
| Spárováno | `12/14` — 12 z 14 transakcí spárováno na faktury |
| Importováno | Datum + uživatel |

### 28.3.1 Všechny pohyby

Záložka **Všechny pohyby** je společný přehled transakcí napříč výpisy, účty a
roky. Na rozdíl od fronty **K zaúčtování** ukazuje i již spárované a zaúčtované
pohyby. U každého řádku vidíš náš zdrojový účet, účet protistrany, výpis,
párování a stav zaúčtování.

Akce jsou stejné jako v detailu konkrétního výpisu: otevření nebo zrušení
párování, rozdělené párování, vytvoření dokladu, přiložení podkladu, vyžádání
dokladu, ignorování i ruční zaúčtování. Po provedené akci se aktualizuje tentýž
řádek; není nutné dohledávat původní výpis. Filtry podle našeho účtu, data,
částky, protistrany, párování a zaúčtování lze kombinovat.

## 28.4 Detail výpisu

Klik na řádek → detail.

Tabulka transakcí:

| Sloupec | Význam |
|---|---|
| Datum | Datum zaúčtování |
| Částka | + (kredit) / − (debet) |
| Měna | |
| Protistrana | Název + číslo účtu (pokud bance zaslala) |
| VS | Variabilní symbol z transakce |
| KS / SS | Konstantní / specifický symbol |
| Popis | Poznámka z banky |
| Stav | `Spárováno` (zelená) / `Bez shody` (šedá) / `Ignorováno` (oranž.) |
| Faktura | Pokud spárováno, číslo faktury (klikatelné) |

### 28.4.1 Částečné platby (více převodů na jednu fakturu)

Příchozí platba se **shodným variabilním symbolem**, ale nižší částkou než
zbývá uhradit, se zaeviduje jako **částečná úhrada** (záznam v boxu Platby
detailu faktury) — faktura zůstává pohledávkou se sníženým zůstatkem a badge
**Částečně uhrazeno**. Další převody se přičítají; jakmile platby pokryjí
částku k úhradě, faktura se označí jako zaplacená (`paid_at` = datum poslední
platby). U **zálohové faktury** se k částečné platbě plátci DPH rovnou
připraví koncept **daňového dokladu k přijaté platbě** (viz § 11.1.2);
doplatek zálohy, ke které už existuje finální doklad, se eviduje na finál.
Stejně fungují platby z **e-mailových avíz** ([29. Bankovní účty](29_Bankovni_ucty.md)).

U cizoměnové faktury placené na **CZK účet** se přepočet kurzem dokladu
použije jen k rozpoznání platby. Pokud se CZK pohyb vejde do devizové tolerance,
zaeviduje se jako úhrada celého zbývajícího obnosu v měně faktury. Skutečná
CZK částka zůstává beze změny na bankovní transakci; nemusí se rovnat
částce faktury násobené kurzem dokladu. Výrazně nižší platba se nadále
eviduje jako částečná úhrada přepočtená kurzem faktury. V podvojném
účetnictví zůstává bankovní noha 221 ve skutečné CZK částce, pohledávka
311 se odúčtuje kurzem předpisu a rozdíl se zachytí na 563/663.

### 28.4.2 Manuální párování

Pro transakce, které se nespárovaly automaticky (typicky chybí VS, nebo
částka nesedí kvůli devizovému kurzu či bankovnímu poplatku):

- MyÚčto nejprve nabídne **skórovaný návrh** přímo pod transakcí. U každého
  kandidáta ukáže slovní jistotu a důvody, například shodný VS, zbývající
  částku, číslo faktury ve zprávě, známý účet protistrany nebo blízké datum
  splatnosti.
- Správného kandidáta lze potvrdit jedním kliknutím; chybný návrh zamítni.
  Přeplatek, podezření na překlep ve VS, rozdílná měna, zálohová faktura a
  částka snížená o poplatek se **nikdy nepotvrdí automaticky**.
- Potvrzené účty zákazníků a dodavatelů se ukládají do jejich evidence. Teprve
  po třech bezchybných shodách může známý účet pomoci s automatickým párováním
  platby bez VS; zrušení chybného párování tuto důvěru okamžitě sníží.

Skóre je dohledatelné složení signálů, nikoli odhad AI. Automatické potvrzení
vyžaduje skóre nejméně **85 %**, náskok alespoň **15 procentních bodů** před
druhým kandidátem a deterministické jádro: přesný VS, známý ověřený účet nebo
jednoznačný součet více dokladů. Návrh od **35 %** se může zobrazit k ručnímu
posouzení. Překlep ve VS, přeplatek, rozdíl odpovídající poplatku, rozdílná měna
nebo proforma automatické potvrzení vždy blokují bez ohledu na skóre.

Když nabídka nesedí, pokračuj klasickým ručním výběrem:

1. Klik **Spárovat** → otevře se modal s vyhledávačem.
2. Najdeš fakturu (číslo / klient / částka).
3. Vyber a potvrď.

Ve stejné měně se zaeviduje platba ve výši transakce. U CZK platby
cizoměnové faktury, která odpovídá celému zbytku v devizové toleranci, se
faktura vyrovná celým zbytkem v její měně; přesný CZK pohyb zůstane na
bankovní transakci. Výrazně nižší částka je částečná úhrada.
Plné pokrytí označí fakturu `paid` (`paid_at` = datum transakce).

Samotná shoda částky a data nebo jen přibližná shoda názvu dodavatele se
automaticky nepotvrdí ani nezaúčtuje. Doklad zůstane nezměněný, dokud kandidáta
neověříš ručním párováním. Stejně se postupuje, když platba míří na přijatou
fakturu, která už je označená jako uhrazená.
Activity log: `bank.matched_manual`.

#### Sloučená úhrada (jedna platba na více faktur)

Když klient zaplatí **víc vystavených faktur jednou platbou** (součet sedí, ale
variabilní symbol odpovídá jen jedné faktuře — nebo žádné), nabídne modal pod
vyhledávačem sekci **Sloučená úhrada**. MyÚčto sám hledá **kombinace faktur
téhož klienta**, jejichž **součet odpovídá částce platby**, ve výchozím okně
**±7 dní** kolem data platby. Klient se jménem podobným protistraně se nabízí
první.

1. Klik **Spárovat** → v sekci **Sloučená úhrada** se zobrazí návrhy kombinací
   (klient, jednotlivé faktury s částkami a datem, celkový součet).
2. U správné kombinace klik **Spárovat (N faktur)**.
3. Každá faktura se uhradí svým **plným zbytkem** a označí jako zaplacená;
   zálohové faktury dostanou koncept finálního dokladu jako u běžné úhrady.

Pomůcky:

- **Hledat v širším okně** — když faktury vystavené dál od sebe (např. ±14 dní),
  rozšiř okno tlačítkem nad návrhy.
- **Vyber fakturu a dohledej zbytek** — pokud víš o jedné faktuře, která do platby
  patří, vyber ji v našeptávači; návrhy se omezí na kombinace, které ji obsahují.

Omezení (záměrná, kvůli správnosti): kombinace jdou jen v rámci **jednoho klienta**
a součet musí **odpovídat částce platby** (sloučená úhrada = uhradit všechny vybrané
faktury celé; není to rozpouštění jedné platby na částečné úhrady). Zrušení
spárování (§ 28.5) smaže **všechny** platby té transakce a vrátí všechny faktury
zpět mezi pohledávky. Activity log: `bank.tx_manual_match_split`.

### 28.4.3 Ignorovat transakci

Pro transakce, které nejsou platby faktur (poplatky, převody mezi vlastními
účty, refundace, …):

1. Klik **Ignorovat**.
2. Status → `Ignorováno`. Pro reporting se nepočítá.

### 28.4.4 Vytvoření přijaté faktury z výpisu (doklad o úhradě)

U **odchozí (záporné) platby**, ke které ještě nemáš v systému přijatou fakturu,
můžeš rovnou založit její koncept přímo z výpisu:

1. Detail výpisu → najdi odchozí transakci → klik **Vytvořit fakturu**.
2. Vyber **existujícího dodavatele** (nebo klik **Nový dodavatel** a založ ho).
   Dodavatel se nezakládá automaticky — musíš ho potvrdit.
3. Potvrď → vznikne **koncept přijaté faktury** v hrubé částce platby
   (1 položka, 0 % DPH) a rovnou se otevře v editoru.
4. V editoru doplň **rozpad DPH**, skutečné **číslo dokladu** a nahraj **PDF**.

Variabilní symbol z platby se předvyplní do pole VS; číslo dokladu dostane
dočasný placeholder `BANK-{id}` (přepiš ho na reálné číslo z faktury). Platba se
zároveň **spáruje** na nově vzniklý koncept (vazba, ne `paid` — to potvrdíš až po
finalizaci faktury).

> 💡 **Tlačítko „Otevřít"** u spárované transakce přeskočí na navázanou fakturu
> (vydanou i přijatou).

## 28.5 Reverse: zrušení spárování

Pokud automatika spárovala chybně:

1. Detail výpisu → najdi transakci → klik **Zrušit párování**.
2. Faktura → status zpět na předchozí (`sent` / `issued`).
3. Activity log: `bank.unmatched`.

## 28.6 Cron — automatický scan

Místo ručního uploadu můžeš nastavit **cron**, který bude pravidelně skenovat
adresář (např. `private/bank-incoming/`) a importovat nové výpisy:

```bash
cmd/cron-bank-scan.sh        # každých 30 minut
```

Setup:

1. Banka pravidelně exportuje výpis e-mailem nebo SFTP do `private/bank-incoming/`
2. Cron každých 30 min spustí `php api/bin/cron-bank-scan.php`
3. Skript projde nové soubory, importuje, přesune do `private/bank-archive/`

## 28.7 Automatické zaúčtování spárovaných plateb (jen podvojné účetnictví)

Firmám vedoucím **podvojné účetnictví** MyÚčto po každém spárování/importu rovnou
nabídne (a u opakovaných plateb i samo vytvoří) zápis do [Účetního deníku](45_Ucetni_denik.md).
Daňová evidence žádný deník nemá — u ní se tato sekce, záložky ani tlačítka
vůbec nezobrazují a bankovní modul funguje jen jako párování plateb popsané
výše.

Automatika žije na stránce **Peníze → Bankovní účty**, kde firmě s podvojným
účetnictvím přibudou vedle **Bankovních výpisů** záložky **Všechny pohyby**,
**K zaúčtování** a **Pravidla účtování**:

- **K zaúčtování** — fronta návrhů čekajících na schválení, s odznáčkem počtu
  čekajících položek přímo na záložce. Výchozí podzáložka **Nezaúčtované pohyby**
  obsahuje všechny skutečné pohyby bez aktivního zápisu, tedy i ty, pro které
  automatika žádný návrh kontace nevytvořila. Historie návrhů zůstává v samostatné
  podzáložce.
- **Pravidla účtování** — naučená pravidla pro opakované platby bez dokladu
  (odvody, poplatky, úroky).

Přímo v detailu výpisu ([§ 28.4](#284-detail-vypisu)) navíc uvidíš u každé
transakce aktuální stav zaúčtování a tlačítko podle situace — **Zaúčtovat…**,
**Schválit / Odmítnout**, nebo **Zrušit zaúčtování**.
Nad transakcemi lze filtrovat **všechny / nezaúčtované / zaúčtované** pohyby;
filtr se kombinuje s filtrem stavu párování.

Automaticky zaúčtovanou transakci od ručního zápisu odliší odznak **Automaticky**.
U návrhů je v přehledu vidět také stručné **Proč** — například název pravidla nebo
informace, že návrh vznikl ze shody platby. Podrobné auditní vysvětlení hotového zápisu
najdete po jeho rozbalení v [Účetním deníku](45_Ucetni_denik.md).

### 28.7.1 Spárované platby faktur — přímý zápis

Jakmile se transakce spáruje s fakturou (automaticky dle VS, ručně nebo jako
sloučená úhrada — [§ 28.4](#284-detail-vypisu)), MyÚčto se ji hned pokusí
zaúčtovat. Konkrétní zápis závisí na typu dokladu:

- **běžná vydaná faktura** → **MD 221 Bankovní účty / D 311 Odběratelé**
  (skutečné účty bere z [předkontace](88_Ucetni_nastroje.md#883-predkontace)
  `payment.receivable.bank`, pokud ji máš upravenou),
- **běžná přijatá faktura** → **MD 321 Dodavatelé / D 221** (`payment.payable.bank`),
- **zálohová (proforma) faktura** → **MD 221 / D 324 Přijaté zálohy** (inkaso zálohy)
  — proforma není daňový doklad a nemá vlastní zaúčtovaný předpis, proto se účtuje
  rovnou jako záloha, ne jako saldokonto 311,
- **zálohová přijatá faktura** → **MD 314 Poskytnuté zálohy / D 221** — symetricky
  k předchozímu bodu,
- **vrácení vydaného dobropisu odběrateli** → **MD 311 / D 221**,
- **refundace přijatého dobropisu od dodavatele** → **MD 221 / D 321**.

> [!NOTE]
> **Každý bankovní účet má svou analytiku.** Účet 221 se nikdy nepoužívá plochý:
> bankovní strana zápisu padá na analytiku toho účtu, ze kterého je výpis —
> **221.100**, **221.200**, **221.300** … (o tečkovaném zápisu viz
> [§ 81.3.1](81_Ucetni_osnova.md#8131-teckovany-zapis-analytik)).
> Číslo se přiděluje automaticky (první volné,
> které v účtovém rozvrhu nekoliduje) a najdeš i změníš ho v
> **Nastavení → Bankovní účty → Kontace účtů**. Díky tomu sedí zůstatek každé
> analytiky přesně na výpis daného účtu, inventarizace k rozvahovému dni se dá
> doložit výpisem a cizoměnové účty se přeceňují automaticky, bez míchání měn.
> Tam, kde text níž mluví o účtu **221**, jde tedy o analytiku konkrétního účtu.
> Historické zápisy, které vznikly dřív, zůstávají na syntetice 221 — přesun na
> analytiky je účetní reklasifikace k datu a dělá se ručně, v otevřeném období.

U **sloučené úhrady** nebo částečných plateb rozdělených na víc faktur vznikne
zápis s tolika řádky na straně 311/321 (resp. 324/314 u záloh), kolik je alokací;
rozdíl do **1 Kč** (zaokrouhlení, bankovní poplatek v alokaci) se dorovná automaticky
na účet **648** (výnos) nebo **548** (náklad) — nad tuto toleranci se transakce
nezaúčtuje sama a čeká na ruční zásah.

Než se zápis vytvoří, MyÚčto ověří:

- transakce je buď v **CZK**, nebo — u spárované platby — ve **stejné cizí měně jako
  faktura** (viz [cizoměnové spárované platby](#28711-cizomenove-sparovane-platby-kurzovy-rozdil) níže),
- **běžná** faktura/přijatá faktura má svůj **vlastní zaúčtovaný předpis** v
  [Účetním deníku](45_Ucetni_denik.md) (a ten není stornovaný) — bez
  zaúčtovaného předpisu se platba jen spáruje, ale nezaúčtuje. **Zálohová (proforma)
  faktura ani zálohová přijatá faktura** tuto podmínku nemají — nejsou daňový doklad,
  takže žádný „svůj" předpis v deníku ani nemají, a účtují se rovnou podle výše,
- **účetní období** transakce je otevřené.

Když některá podmínka nesedí (uzavřené období, faktura označená jako
uhrazená bez evidované platby k ověření apod.), zápis se nevytvoří rovnou,
ale místo něj přibude **návrh** v záložce **K zaúčtování** s vysvětlením
v poznámce (např. „uzavřené období", „faktura už je označena jako zaplacená
— ověřte"). Platby bez zaúčtovaného předpisu **běžné** faktury (ne zálohy), stejně
jako křížová měna nebo CZK doklad placený cizí měnou, se nezaúčtují automaticky.
Nevyřešený skutečný pohyb zůstane dohledatelný ve **Všech pohybech** a v
jednotné frontě **Účetnictví → K doúčtování**, i když pro něj nevznikla kontace.

> [!TIP]
> **Vyúčtovací faktura ze zálohy.** Když později zaúčtuješ finální (vyúčtovací)
> fakturu navázanou na zálohu (viz [§ 23.3.1](23_Prijate_faktury.md#2332-propojeni-zalohy-s-vyuctovaci-fakturou-proti-dvojimu-zapocteni)
> nebo [§ 15.8](15_Faktura_editor.md#158-zalohova-faktura-danovy-doklad)), zápis
> automaticky doplní i **zúčtovací řádek zálohy** (324/311 resp. 321/314) ve výši
> skutečně přijaté/zaplacené zálohy, nejvýše však do celkové částky vyúčtovacího
> dokladu. Případný přeplatek zůstane na 324/314 do vrácení nebo dalšího vyúčtování;
> částka se neodvozuje z nominální hodnoty proformy. Kombinace
> proforma s daňovým dokladem k přijaté platbě **a** vyúčtováním zároveň, nebo
> proforma s víc než jednou vyúčtovací fakturou, je mimo automatiku — takový
> zápis zaúčtuj ručně.

> [!NOTE]
> Zaúčtování spárované platby je **idempotentní** vůči transakci — přepárování
> (změna alokace, sloučená úhrada místo jednoduché) přepíše týž zápis, nevznikne
> duplicita. Zrušení spárování ([§ 28.5](#285-reverse-zruseni-sparovani)) naopak
> zápis nejdřív **stornuje** (opravný zápis v deníku) a teprve pak fakturu vrátí
> mezi pohledávky/závazky — pokud je účetní období zápisu už uzavřené, zrušení
> spárování se **nedokončí** (hláška o uzavřeném období) a musíš nejdřív období
> otevřít nebo počkat na účetní.

#### 28.7.1.1 Cizoměnové spárované platby (kurzový rozdíl)

Faktura v cizí měně (EUR, USD…) placená **stejnou cizí měnou** bankovní transakcí (na
devizový účet) se zaúčtuje automaticky, včetně kurzového rozdílu. Stejně se zaúčtuje
jednoznačně spárovaná **CZK karetní platba za cizoměnovou přijatou fakturu** — u ní
nemusí být variabilní symbol a CZK částka se může lišit podle kurzu karetní asociace:

- saldokonto (**311**/**321**) se odúčtuje v **CZK hodnotě předpisu** — cizí částka
  přepočtená kurzem, který byl zafixovaný při zaúčtování faktury,
- banka (**221**) se zaúčtuje v **CZK hodnotě skutečné úhrady** — cizí částka
  přepočtená pevným měsíčním/ročním kurzem firmy, pokud je zvolený, jinak kurzem
  ČNB ke dni bankovní transakce,
- rozdíl mezi oběma jde na **563** (kurzová ztráta) nebo **663** (kurzový zisk) —
  stejné účty jako u ročního [kurzového přecenění](45_Ucetni_denik.md).

U platby stejnou cizí měnou fungují i částečné a sloučené úhrady (poměrná část na
alokaci). CZK karetní platba za cizoměnový doklad se automaticky účtuje jen při
jednoznačné vazbě 1:1 na celý doklad. Drobný
nealokovaný zbytek do jedné jednotky cizí měny se vede odděleně jako provozní
vyrovnání na **548/648**, nikoli jako kurzový rozdíl. Mimo automatiku
zůstává: **křížová cizí měna** (faktura v EUR placená v USD), **CZK doklad placený cizí
měnou** a **zálohová (proforma) faktura / zálohová přijatá faktura v cizí měně**.
Valutová pokladna umí samostatné hotovostní prodeje, nákupy a ostatní pohyby,
ale úhradu cizoměnové faktury z pokladny záměrně blokuje; viz
[Pokladna](30_Pokladna.md).

Historické spárované transakce zaúčtuje správce příkazem
`php api/bin/backfill-bank-posting.php --supplier=<ID> --apply`. Bez `--apply` se
vypíše pouze dry-run. Back-fill respektuje existující párování i bez VS, bezpečně
naváže jedinou odpovídající `legacy` platbu a plně kryté položky označené
`auto_partial` uzavře jako plné. Nejednoznačné nebo skutečně částečné vazby nechá
k ruční kontrole.

Nespárované transakce (odvody, poplatky, převody mezi vlastními účty) back-fill ve
výchozím stavu vůbec nevyhodnocuje. S `--rules` je vyhodnotí, ale výsledek uloží
vždy jen jako **návrh**. S `--auto` (implikuje `--rules`) se řídí nastavením
**Automatika účtování** dané firmy: co má úroveň `auto`, dávka rovnou **zaúčtuje**,
co má `suggest`, navrhne jako dosud. Firma bez nastavené automatiky má výchozí
`suggest`, takže se pro ni ani s `--auto` nic nemění. Zavřená a schválená období se
přeskočí (`period_closed`) bez ohledu na přepínače.

### 28.7.2 Pravidla účtování opakovaných plateb

Platby bez faktury (odvody na OSSZ/ZP, bankovní poplatky, úroky, leasing…) se
neúčtují samy od prvního výskytu — na záložce **Pravidla účtování** si pro ně
založíš pravidlo:

1. Tlačítko **Nové pravidlo** → formulář: **název**, **směr** (příchozí/odchozí),
   aspoň jedno kritérium shody — **protiúčet** (+ volitelně kód banky),
   **variabilní symbol**, nebo **fragment zprávy** (podřetězec v popisu platby),
   volitelně **rozsah částky** (od–do), a **kontace** (účet MD/D).
   Pole **Priorita** určuje pořadí vyhodnocení (nižší číslo má přednost) a
   **Limit pro automatiku** může nad zadanou částkou vynutit pouze návrh, i když
   je pravidlo povýšené na automatické.
2. Bankovní strana kontace musí být účet **221** (dle směru), druhá strana
   nesmí být saldokontní účet (**311/321/314/324/325**) — na spárované platby
   faktur pravidla nesahají, ty řeší [§ 28.7.1](#2871-sparovane-platby-faktur-primy-zapis).
3. Tlačítko **Otestovat na historii** (dry-run) ukáže, kolika transakcím za
   posledních 12 měsíců by pravidlo sedělo a kolik z nich je už zaúčtovaných —
   pomůže odladit kritéria dřív, než pravidlo uložíš.
4. Při uložení nabídne zaškrtávátko **„Navrhnout zaúčtování N historických
   plateb"** (jen když dry-run něco našel) — vytvoří rovnou návrhy pro dosud
   nezaúčtované historické transakce v otevřených obdobích (max 200 položek).

U již uloženého aktivního pravidla lze stejný bezpečný běh spustit v seznamu
tlačítkem **Použít na historii**. Zpracují se jen dosud nezaúčtované transakce
v otevřených obdobích (max. 200) a vzniknou pouze návrhy ke schválení; opakované
spuštění nevytváří duplicity. Nevyplněná dolní nebo horní mez částky znamená
„bez omezení" na dané straně intervalu, nikoli částku 0 Kč.

Nové pravidlo vždy jen **navrhuje** (režim **Návrh**) — po importu vytvoří
položku v **K zaúčtování**, kterou potvrdíš **Schválit** (případně přes ikonu
ozubeného kolečka přepíšeš kontaci) nebo **Odmítnout**. Po pěti potvrzeních za
sebou beze změny, bez odmítnutí a s vyplněným rozsahem částky se nabídne
**Povýšit na automatiku**. Režim se nikdy nepřepne sám — povýšení vždy potvrdí
člověk. Od dalšího výskytu se zápis vytvoří bez čekání ve frontě (transakce
zůstane vidět v historii se štítkem, kdo/co ji zaúčtovalo).

Když transakci odpovídá víc aktivních pravidel najednou, MyÚčto nikdy neúčtuje
automaticky — vytvoří návrh podle pravidla s vyšší úspěšností a označí to jako
konflikt, ať si kontaci zkontroluješ ručně. Opakované **odmítnutí** téhož
pravidla (3× po sobě u různých transakcí) ho samo **deaktivuje** — na to tě
upozorní hláška při odmítnutí; pravidlo pak najdeš v seznamu vypnuté a můžeš ho
opravit nebo smazat.

Nesedí-li žádné aktivní pravidlo, ale MyÚčto najde v posledním roce **jedinou
stejnou dvouřádkovou kontaci** už dřív zaúčtovanou pro stejný protiúčet (a
sedí-li VS, je-li vyplněný), nabídne rovnou **naučený návrh** — i bez
založeného pravidla. Z libovolného ručního zaúčtování transakce navíc systém
umí nabídnout **„Podobná platba se opakuje…" → Vytvořit pravidlo** s
předvyplněnými kritérii i kontací, ať příště nemusíš zadávat nic ručně. Ruční
opravy mají přednost před starší historií; naučený návrh zobrazí datum a změnu
kontace. Rozporné opravy nový návrh nevytvoří.

> [!TIP]
> Pravidlo v režimu **Automaticky** zaúčtuje stejně, ať výpis nahraješ ručně nebo
> ho naimportuje **cron** ([§ 28.6](#286-cron-automaticky-scan)) — motor
> automatiky je pro obě cesty stejný. Výjimka je **zpětné doplnění historie**
> při zakládání nového pravidla ([§ 28.7.2](#2872-pravidla-uctovani-opakovanych-plateb),
> bod 4) — to i pro pravidlo v režimu Automaticky vždy jen **navrhne**, nikdy
> nezaúčtuje samo, ať máš na staré transakce jistotu, než je potvrdíš.

### 28.7.3 Vlastní převody mezi bankovními účty

Pohyb mezi dvěma bankovními účty téže firmy MyÚčto rozpozná podle účtu výpisu
a protiúčtu. Ve frontě **K zaúčtování** jej označí štítkem **🔁 Vlastní převod**
a nabídne samostatné zaúčtování každé bankovní transakce přes účet **261 — Peníze
na cestě**:

- odchozí pohyb se účtuje **MD 261 / D 221**,
- příchozí pohyb se účtuje **MD 221 / D 261**.

Obě nohy se propojí, jakmile dorazí ve výpisech. V detailu výpisu lze přejít na
druhou nohu; dokud ještě nedorazila, zůstává zůstatek 261 doloženým převodem na
cestě. To je běžné například při odeslání 31. prosince a připsání 2. ledna.

Převod mezi účty v různých měnách systém pouze označí k ručnímu zaúčtování,
protože je potřeba zohlednit kurzový rozdíl. Pokud už existuje podobný ruční
zápis přes 261, automatika upozorní na možné zdvojení a bez kontroly jej
nezaúčtuje. Stejné varování se zobrazí při ručním zadání převodu, ke kterému už
existuje bankovní transakce.

### 28.7.4 Odvody ČSSZ, zdravotním pojišťovnám a finančnímu úřadu

Odchozí korunové platby na účty vedené u ČNB (**kód banky 0710**) MyÚčto
rozpoznává samostatně. Nejprve hledá odpovídající předpis zálohy na daň,
sociální nebo zdravotní pojištění podle variabilního symbolu, data splatnosti
a částky. Pokud předpis nenajde, určí druh odvodu z variabilního symbolu firmy
a předčíslí účtu finančního úřadu. Platby mimo banku 0710 tento detektor nikdy
nepřebírá.

Rozpoznaný odvod se zobrazí ve frontě s kontací **336/341/342/343/345 proti
221**, údajem **Jistota** a lidským vysvětlením. Platba i vratka **DPH** míří na
zúčtovací analytiku **343.900** — tedy přesně na účet, na kterém po měsíčním
zúčtování DPH ([§ 81.3.3](81_Ucetni_osnova.md#8133-mesicni-zuctovani-dph)) leží
skutečný závazek vůči finančnímu úřadu; firma bez analytik účtuje jako dřív na
holou 343. Nejasný odvod zůstane pouze
návrhem. Pokud na zúčtovacím účtu chybí zaúčtovaný předpis nebo jeho kreditní
zůstatek nestačí na platbu, automatika zápis sama neprovede, aby nevytvořila
debetní zůstatek závazku.

Na stránce **Pravidla účtování** lze tlačítkem **Ze šablony** založit připravené
pravidlo pro odvody, bankovní poplatky, úroky, nájem nebo předplatné. Šablona
doplní identifikátory z nastavení firmy a vždy vznikne v režimu **Návrh**.
Superadmin spravuje tento globální katalog v **Systém → Šablony bank. pravidel**.
Změna šablony ovlivní její budoucí použití ve všech firmách;
už dříve vytvořená firemní pravidla se zpětně nemění. Použitou šablonu lze
deaktivovat, ale ne smazat.

Fronta má vedle běžných návrhů také záložku **Potřebuje mě**. Sdružuje položky,
které vyžadují rozhodnutí, i blokované položky z uzavřeného období. Jednotlivou
položku lze po kontrole schválit; blokované a AI návrhy se nikdy neschvalují
hromadně.

## 28.8 Tipy

- **Nahraj výpis **denně/týdně** — čím čerstvější, tím dříve se ti vyfiltrují
  faktury po splatnosti správně.
- **VS je nejsilnější signál**, ale není jediný. Bez něj MyÚčto vyhodnotí částku,
  zprávu, datum, název a dříve ověřený účet protistrany; nejednoznačnou shodu
  nechá vždy k potvrzení. Klienty přesto veď k vyplňování VS.
- **Platby kartou** (bez VS) se zkusí spárovat na přijatou fakturu i podle
  **částky + podobnosti názvu** dodavatele (název protistrany na výpisu nemusí
  přesně sedět s názvem dodavatele). Spáruje se jen při jednoznačné shodě;
  jinak nech na ručním párování / založení dokladu (viz § 28.4.4).
- **Částečné platby** (klient pošle míň, ale VS sedí) se u **vydaných** faktur
  evidují automaticky jako částečná úhrada (viz § 28.4.1). U **přijatých**
  faktur se podplatba jen označí k ruční kontrole. Toleranci přesné shody lze
  ladit v `cfg.php` → `bank.matching.tolerance`; u bankovních e-mailových avíz
  ji nastavíš přímo v mapování účtu.
- **Devizový kurz** — pokud klient pošle EUR a faktura je v CZK, transakce
  nebude spárovaná (jiná měna). Manuálně. Pokud je ale faktura v EUR a klient
  zaplatí přímo eurem na tvůj EUR účet, taková spárovaná platba se dnes zaúčtuje
  automaticky i s kurzovým rozdílem — viz [§ 28.7.1.1](#28711-cizomenove-sparovane-platby-kurzovy-rozdil).
- **Bankovní poplatek** — pokud u korunové dávky banka strhla z 10 000 Kč
  poplatek 200 Kč, na účet dorazí 9 800 Kč. MyÚčto může nabídnout sloučené
  párování celé dávky a po potvrzení připraví návrh vyváženého zápisu s poplatkem
  na účtu 568; bez kontroly jej samo nezaúčtuje.
