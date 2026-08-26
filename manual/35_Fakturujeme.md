# 35. Fakturujeme — daňový průvodce

> [!WARNING]
> **Správnost faktury je vždy na uživateli.** MyÚčto.cz je účetní nástroj —
> generuje doklady, eviduje je, účtuje a sestavuje z nich výkazy. Není to daňový
> poradce. Sazba DPH, místo plnění, OSS, přenesená daňová povinnost, registrace
> k DPH v cizí zemi — to vše je odpovědnost vystavitele faktury, nikoli aplikace.
> **Nestandardní situace vždy konzultuj s účetní nebo daňovým poradcem.** Cena za
> 30 minut konzultace je řádově nižší než sankce za špatně vystavenou fakturu.

Tahle kapitola je **daňový rozcestník k vystavování dokladů**: co aplikace pozná
a doplní sama, kde tě nechá rozhodnout a kde končí (a tvůj účetní začíná).
Mechaniku obrazovek popisují [14. Faktury](14_Faktury.md) a
[15. Editor faktury](15_Faktura_editor.md), výkazy pak
[36. Výkazy DPH](36_Vykazy_DPH.md).

## 35.1 Daňový status firmy — plátce, neplátce, identifikovaná osoba

Daňový status dodavatele určuje chování celé aplikace: tvar dokladu, dostupné
sazby, povinné poznámky i to, jaké výkazy se vůbec nabídnou. MyÚčto rozlišuje
**tři stavy** — plátce DPH, neplátce a identifikovaná osoba (§ 6g–6l ZDPH).

### 35.1.1 Status se vede v čase, ne jako jeden přepínač

**Cesta: `Nastavení → Daně a účetnictví → Daňové nastavení → Plátcovství DPH`.**

Status **není zaškrtávátko**, které by platilo „ode dneška napořád". Je to
**historie změn**: každý řádek nese datum účinnosti a stav (plátce / neplátce /
identifikovaná osoba). Stav k libovolnému dni je poslední řádek s účinností
menší nebo rovnou tomu dni.

| Vlastnost | Chování |
|---|---|
| **Datum účinnosti** | Může být i **budoucí** — plánovanou změnu zapíšeš dopředu a do systému se propíše až v den účinnosti (noční úloha `cron-vat-status-apply`) |
| **Ukládání** | Řádky historie se ukládají **okamžitě**, nezávisle na tlačítku Uložit ve zbytku formuláře |
| **Úprava data** | Datum existujícího řádku se nemění — řádek smažeš a založíš nový |
| **Kombinace** | Plátce a identifikovaná osoba se **vylučují** — identifikovaná osoba je z definice neplátce |
| **Paušální daň** | Paušalista nesmí být plátce DPH (§ 7a ZDP); aplikace přepnutí na plátce odmítne, dokud je zapnutá paušální daň |

**Proč to není jen dnešní příznak.** Doklad se posuzuje **k rozhodnému datu
dokladu** (datum plnění, u dobropisu datum doručení opravného dokladu), ne podle
toho, jak je firma nastavená dnes:

- **PDF a exporty** vykreslí doklad podle stavu k jeho datu — faktura vystavená
  v době plátcovství zůstane daňovým dokladem, i když firma později registraci
  zrušila.
- **Vystavení dokladu s DPH** aplikace **zablokuje**, pokud firma k rozhodnému
  datu plátcem nebyla (§ 108 odst. 4 ZDPH — uvedená daň by se musela odvést).
  Hláška je adresná: *„Firma není k rozhodnému datu dokladu (…) plátcem DPH."*
  V srpnu tak lze doúčtovat červnové plnění s DPH, ale červencové už ne.
- **Výkazy DPH** posuzují plátcovství **ke konci období výkazu**, ne podle
  dnešního stavu — viz [§ 36](36_Vykazy_DPH.md).

> [!WARNING]
> **Retro zámek.** Změna s účinností v uzamčeném účetním období nebo před/uvnitř
> období už podaného přiznání se neuloží potichu — aplikace vrátí konflikt
> s výčtem kolizí (uzamčené období, zámek k datu, podané přiznání) a uložit lze
> jen s explicitním potvrzením, které se zapíše do auditu. **Podaná přiznání se
> tím nepřepočítají** — případné opravné či dodatečné tvrzení je na tobě.

### 35.1.2 Co se na dokladu mění u neplátce

| Co se mění | Plátce DPH | Neplátce DPH |
|---|---|---|
| Záhlaví dokladu | „Faktura — daňový doklad" | „Faktura" |
| Sloupec „DPH %" v tabulce položek | ano | **skrytý** |
| Sloupec „S DPH" | ano | **skrytý** (jen „Celkem") |
| Volba sazby DPH u položky | ano | **skrytá**, interně se ukládá 0 % |
| Přepínač cen „s DPH / bez DPH" | ano | **skrytý** |
| Reverse charge checkbox | ano (pro EU klienty s VAT ID) | **skrytý** (výjimka: identifikovaná osoba) |
| Sumace DPH (rozpis sazeb, „DPH celkem") | ano | **skrytá** |
| Poznámka „Není plátce DPH" na dokladu | — | ano |

Neplátce dle ZDPH nemá nárok DPH účtovat ani vykazovat. Faktura je proto čistě
jednosloupcová (Cena/j → Celkem) a nese povinnou poznámku.

### 35.1.3 Kdy se plátcem staneš ze zákona (§ 6 a § 94 ZDPH)

Plátcovství **vzniká ze zákona z obratu**, ne přihláškou. Od 1. 1. 2025 se
sleduje **kalendářní rok** (dříve klouzavých 12 měsíců) a existují **dva
limity s různým následkem**:

| Obrat za kalendářní rok | Plátcem se firma stává |
|---|---|
| přes **2 000 000 Kč** | od **1. ledna** následujícího kalendářního roku |
| přes **2 536 500 Kč** | **dnem následujícím** po dni překročení |

Aplikace obrat sleduje a v `Daně a účetnictví` zobrazí **banner**: kolik obrat
činí, který limit překročil, ke kterému dni plátcovství vzniká a do kdy je
potřeba podat přihlášku k registraci (**10 pracovních dnů ode dne překročení
2 000 000 Kč** — § 94 odst. 1; kotvou lhůty je dolní limit, horní limit mění jen
den vzniku plátcovství). Tlačítkem **Zapsat do historie** rovnou založíš
odpovídající řádek.

> [!NOTE]
> U ročního limitu, kde přesný den překročení z dat vyjít nemusí, systém termín
> označí jako **informativní** a řekne to nahlas. Roky do 2024 se neposuzují —
> starý mechanismus klouzavých 12 měsíců aplikace vědomě nemodeluje, protože měl
> jinou lhůtu i jiné datum vzniku.

Banner nic sám nepřepíná: **registraci i zápis do historie provádíš ty.**

### 35.1.4 Identifikovaná osoba (§ 6g–6l ZDPH)

Třetí stav mezi plátcem a neplátcem — typicky freelancer, který fakturuje služby
do EU (a/nebo nakupuje zahraniční služby typu reklamy či SaaS), ale v tuzemsku
plátcem není. V historii plátcovství založ řádek se stavem **Identifikovaná
osoba** k datu, od kterého povinnost vznikla.

Co se tím změní (vše ostatní zůstává jako u neplátce):

| Oblast | Chování identifikované osoby |
|---|---|
| Tuzemské faktury | beze změny — bez DPH, poznámka „Není plátce DPH" |
| Faktura **EU** klientovi s DIČ | po výběru klienta se automaticky zapne **reverse charge** a předvyplní klasifikace **22** (EU služby → souhrnné hlášení); PDF je daňový doklad s DIČ a klauzulí „daň odvede zákazník (čl. 196 směrnice 2006/112/ES)". Sazba DPH se **neuvádí** (samovyměří ji odběratel sazbou své země) — částky jsou základ daně, sloupec se proto jmenuje „Bez DPH". Totéž platí v šabloně pravidelné fakturace. |
| Faktura klientovi mimo EU | bez RC — plnění je mimo předmět české DPH, žádná klauzule, žádné souhrnné hlášení |
| Souhrnné hlášení | podává se za měsíce s EU službami (kód 3) — `Daně → Souhrnné hlášení` |
| Přijaté zahraniční doklady (klasifikace 23/24/25) | samovyměření DPH **bez nároku na odpočet** — daň se reálně platí |
| Přiznání k DPH | typ **identifikovaná osoba** (`typ_platce='I'`), jen řádky samovyměření, **vždy měsíčně** a jen za měsíce, kdy povinnost vznikla |
| Kontrolní hlášení | **nepodává se nikdy** — stránka KH zobrazí upozornění |

> [!WARNING]
> Samovyměřená daň bez nároku na odpočet je u identifikované osoby **skutečný
> výdaj**. Lhůta pro podání přiznání i souhrnného hlášení je do 25. dne
> následujícího měsíce; faktura za EU služby se vystavuje nejpozději do 15 dnů
> od konce měsíce plnění (§ 28).

### 35.1.5 Vznik a zrušení registrace — § 79 a § 79a

Změna statusu má daňový dopad i na **majetek a zásoby, které firma už drží**:

- **při registraci** může vzniknout nárok na odpočet z obchodního majetku
  pořízeného nejvýše 12 měsíců před vznikem plátcovství (**§ 79**),
- **při zrušení registrace** vzniká povinnost uplatněný odpočet snížit
  (**§ 79a**) — u zásob se vrací celý, u dlouhodobého majetku jen podíl za roky
  zbývající z pěti- nebo desetileté lhůty.

Jakmile do historie zapíšeš registraci nebo její zrušení, aplikace na to
**upozorní a nabídne proklik** do agendy `Daně → Opravy DPH (§ 43, § 79)`.
Položky zadává účetní ručně — z dokladu nejde poznat, zda věc k rozhodnému dni
pořád tvoří obchodní majetek. Součet se promítne na **ř. 45 přiznání k DPH**;
evidence sama do deníku neúčtuje. Podrobně
[§ 36 — Opravy DPH](36_Vykazy_DPH.md#367-opravy-dph-43-79-a-79a).

## 35.2 Sazby DPH a klasifikace

### 35.2.1 Číselník sazeb

Standardní seed obsahuje čtyři sazby pro Česko:

| Kód | Sazba | Popis | Kdy použít |
|---|---|---|---|
| `CZ-21` | 21 % | Základní | Default — většina zboží i služeb |
| `CZ-12` | 12 % | Snížená | Potraviny, knihy, ubytování, vodné/stočné, léčivé přípravky… (úplný seznam je v příloze ZDPH) |
| `CZ-0` | 0 % | Osvobozeno | Plnění osvobozená dle § 51 ZDPH (např. finanční služby, vzdělávání), vývoz. Také fallback pro neplátce. |
| `CZ-RC` | 0 % | Reverse charge | Přenesená daňová povinnost — sazba 0 %, daň odvádí příjemce |

Sazby spravuješ v `Nastavení → Číselníky → Sazby DPH`
([§ 92.1.2](92_Nastaveni.md#9212-sazby-dph)). Můžeš přidávat další (typicky sazby
členských států pro OSS — `SK-23`, `PL-23`, `HU-27`), upravovat popisek nebo
zneplatnit zastaralé pomocí **Platí do**. Default sazba se předvyplní u nově
přidané položky faktury.

> [!WARNING]
> **Pole Stát formulář předvyplňuje na `CZ`.** U sazby členského státu ho musíš
> přepsat — sazba `SK-23` se zemí `CZ` je pro systém *česká sazba 23 %*, a takovou
> ČR nezná. Je to nejčastější příčina toho, že import zahraničních dokladů skončí
> chybou. Detail v [§ 40.2.2](40_OSS.md#4032-sazby-dph-pro-cizi-zeme-hlidej-pole-stat).

> [!NOTE]
> Sazby se přiřazují **per položku**, ne per celý doklad. Smíšené sazby v jedné
> faktuře aplikace zvládá — sumace je rozepsaná po sazbách. Vystavené doklady si
> sazbu drží na svých řádcích, takže **změna číselníku minulost nepřepíše**;
> postup při změně sazby s budoucí platností popisuje
> [§ 36](36_Vykazy_DPH.md#369-zmena-sazby-dph-s-budouci-platnosti).

### 35.2.2 Klasifikace DPH — co doklad opravdu zařadí do výkazů

Sazba říká, kolik se počítá. **Klasifikační kód** říká, **kam plnění patří**:
řádek přiznání DPHDP3, oddíl kontrolního hlášení, směr použití (prodej/nákup),
zvláštní režimy (reverse charge, kód režimu KH, oprava nedobytné pohledávky)
a **kód předmětu plnění** pro tuzemský RC (§ 92b–92f) do sekce A.1 / B.1
kontrolního hlášení.

- Klasifikace se **přiřadí sama** podle sazby a situace na dokladu; v editoru ji
  lze přepsat per řádek i za celou hlavičku.
- Vestavěné systémové kódy jsou společné a needitovatelné; vlastní kód si firma
  založit může, ale jen s vědomím dopadu na DPHDP3, KH a Knihu DPH.
- Není to popisek: **backend podle klasifikace zařazuje řádky do daňových
  sestav.**

Detail v [§ 36 — VAT klasifikační kódy](36_Vykazy_DPH.md#3644-jak-funguji-vat-klasifikacni-kody)
a [§ 92.14](92_Nastaveni.md#9214-system-sazby-a-ciselniky).

## 35.3 Datum plnění, zálohy a zjednodušený doklad

### 35.3.1 DUZP je to, co rozhoduje

V hlavičce dokladu jsou dvě různá data a pletou se často:

- **Vystaveno** — kdy doklad vznikl,
- **DUZP (datum uskutečnění zdanitelného plnění)** — **podle něj** se plnění
  zařadí do zdaňovacího období, posoudí plátcovství, ověří platnost sazby
  i platnost registrace do OSS.

Ve výchozím stavu se DUZP rovná datu vystavení; u doúčtování zpětného plnění ho
změň, ne naopak.

### 35.3.2 Zálohová faktura není daňový doklad

Zálohová faktura (proforma) je **výzva k platbě** — do výkazů DPH nevstupuje.
Plátce DPH má povinnost vystavit **daňový doklad k přijaté platbě** (§ 28
odst. 2 ZDPH) s DUZP = den přijetí platby. MyÚčto ho u úhrady zálohové faktury
vystaví jako koncept automaticky (bankovní párování) nebo na klik:

- DPH se počítá **shora koeficientem** (§ 37) a platba se rozdělí mezi sazby
  zálohy poměrně podle jejich vah,
- doklad se čísluje v řadě faktur, do výkazů DPH / KH / Knihy DPH vstupuje
  v měsíci platby a vystavením je rovnou zaplacený,
- finální doklad (vyúčtování) pak ke zdaněným platbám přidá **záporné odpočtové
  řádky podle § 37a** — daní se jen zbytek, nic dvakrát,
- u cizoměnové platby se pro DPH použije **kurz k datu přijetí platby**, ne kurz
  původní proformy.

**Daňový doklad k platbě se nevystavuje** u neplátce, u plnění v přenesené
daňové povinnosti (u RC se záloha nedaní — daň vzniká až k DUZP plnění) a
[v režimu OSS](40_OSS.md#408-uctovani-oss-dane) (daň se přiznává ke dni přijetí
úplaty přímo v OSS přiznání). Podrobně
[§ 16.1.2](16_Faktura_PDF.md#zalohova-faktura-danovy-doklad-k-prijate-platbe) a
[§ 15.8](15_Faktura_editor.md#158-zalohova-faktura-danovy-doklad).

### 35.3.3 Zjednodušený daňový doklad (§ 30 ZDPH)

Do **10 000 Kč včetně daně** lze vystavit zjednodušený daňový doklad — nemusí
obsahovat údaje o odběrateli, základ daně ani výši daně (§ 30a). V editoru je to
zaškrtávátko; aplikace ho **odmítne** ve třech případech, kde to zákon nedovolí:

| Situace | Proč to nejde |
|---|---|
| Doklad je **nad 10 000 Kč** včetně daně | § 30 odst. 1 |
| Doklad je v **přenesené daňové povinnosti** | § 30 odst. 2 — odběratel potřebuje na dokladu své DIČ, jinak plnění vypadne z kontrolního hlášení |
| Jde o **dodání zboží do jiného členského státu** | § 30 odst. 2 — bez identifikace odběratele nelze plnění vykázat v souhrnném hlášení |

### 35.3.4 Storno vs. dobropis

- **Dobropis (opravný daňový doklad, § 42)** je daňový doklad se zápornými
  částkami a vazbou na původní fakturu — patří do období, kdy byl odběrateli
  doručen.
- **Interní storno** je jen interní zrušení dokladu, klientovi se nevystavuje.
- **Oprava chybně určené výše daně (§ 43)** — například špatná sazba — **není
  dobropis**: patří zpětně do období původního plnění a jde do **dodatečného
  přiznání**. Eviduje se v `Daně → Opravy DPH (§ 43, § 79)`.

Rozhodovací pravidlo je v [§ 15.9](15_Faktura_editor.md#159-storno-vs-dobropis).

## 35.4 Reverse charge (přenesená daňová povinnost)

Reverse charge (RC) přesouvá povinnost odvést DPH na **příjemce** faktury.
Vystavitel účtuje 0 % a doplní zákonnou poznámku. V MyÚčtu se RC řeší
checkboxem **Reverse charge** v hlavičce faktury.

### 35.4.1 Kdy RC vystavit

- **Tuzemský RC (§ 92a–§ 92g ZDPH):** stavební a montážní práce mezi plátci
  v ČR, zlato, šrot, mobilní telefony, integrované obvody, plyn/elektřina pro
  obchodníka… (přesný výčet § 92a–g). Oba subjekty musí být plátci DPH v ČR.
  U těchto plnění patří do kontrolního hlášení i **kód předmětu plnění** — nese
  ho klasifikace DPH (§ 35.2.2).
- **EU B2B s reverse charge:** dodavatel je plátce DPH v ČR (nebo
  identifikovaná osoba), klient je osoba povinná k dani v jiném členském státě
  s **platným VAT ID** ověřitelným přes VIES a jde o plnění s místem plnění
  v zemi příjemce dle § 9 odst. 1 ZDPH.

V obou případech aplikace účtuje 0 %, sumace neukáže DPH řádky a do PDF přidá
zákonnou poznámku — pro tuzemského klienta „Daň odvede zákazník (přenesená
daňová povinnost dle § 92a zákona o DPH)", pro zahraničního „…dle čl. 196
směrnice 2006/112/ES". EU plnění se současně vykáže v
[souhrnném hlášení](39_Souhrnne_hlaseni.md).

### 35.4.2 Jak RC zapnout

1. **Profil klienta:** v `Klienti → Editace` zaškrtni **Reverse charge**. Tím se
   RC předvyplní na dokladech pro tohoto klienta.
2. **VIES ověření DIČ:** u zahraničního DIČ (jiný prefix než CZ) ověří tlačítko
   **Detaily plátce DPH** registraci přes evropský VIES. Bez platného VAT ID
   partner na RC nárok nemá.
3. **Editor faktury:** checkbox v hlavičce; přepnutí RC mění jen hlavičkový
   režim dokladu, nominální sazby položek zůstávají.

> [!NOTE]
> RC checkbox je v editoru skrytý, když je dodavatel **neplátce DPH** — neplátce
> RC vystavit nemůže (nemá DPH co přenášet). Výjimkou je **identifikovaná osoba**
> ([§ 35.1.4](#3514-identifikovana-osoba-6g-6l-zdph)), které se RC u EU klienta
> s DIČ zapne automaticky.

RC doklad v cizí měně má vlastní pravidla přepočtu pro výkazy — viz
[§ 36](36_Vykazy_DPH.md#3647-reverse-charge-v-cizi-mene).

## 35.5 Zahraniční fakturace — EU, OSS a třetí země

### 35.5.1 Co aplikace pokrývá

| Scénář | Chování |
|---|---|
| **CZ B2B / B2C** | plně podporováno (21 % / 12 % / RC dle situace) |
| **EU B2B s platným VAT ID** | RC + VIES ověření + souhrnné hlášení |
| **EU B2C — běžné služby** | místo plnění zůstává v ČR dle § 9 odst. 2 ZDPH → fakturuje se **s českou daní**, do OSS to nepatří (i když je odběratel z Polska) |
| **EU B2C — TBE služby a prodej zboží na dálku** | místo plnění je v zemi zákazníka po překročení celounijního prahu **10 000 EUR/rok** → **režim OSS** se sazbou země spotřeby |
| **Mimo EU** (Švýcarsko, USA, UK…) | typicky bez DPH (vývoz zboží / služba mimo předmět české daně); v editoru zvol sazbu `CZ-0` |

### 35.5.2 OSS (One Stop Shop) — co dělá aplikace sama

Režim OSS má **vlastní kapitolu:** [40. Režim OSS (One Stop Shop)](40_OSS.md) —
nastavení, odvození řádku, plnění k ručnímu posouzení, hromadná úprava, doložka
na dokladu, účtování na 345.100, přepočet kurzem ECB, podání, archiv a evidence
§ 110f. Tady stačí vědět tohle:

- **Zařazení řádku do OSS se odvozuje automaticky** ve všech kanálech — v
  editoru, při importu, u pravidelné fakturace, při synchronizaci z iDokladu
  a Fakturoidu, při čtení PDF i přes veřejné API. Ruční označování řádků není
  potřeba.
- **Rozhoduje nezávislý číselník sazeb členských států**, ne uživatelem zadaná
  sazba: sazba, kterou číselník v zemi dodavatele nezná, se **nikdy nevykáže
  jako tuzemské plnění** — cizí daň by jinak tiše skončila na ř. 1 českého
  přiznání.
- **OSS řádky se nezahrnou** do českého přiznání k DPH, kontrolního hlášení ani
  Knihy DPH a účtují se na vlastní účet **345.100**, aby zůstatek 343 pořád
  seděl na přiznání.
- **Práh 10 000 EUR** aplikace sleduje na stránce OSS přiznání — čerpání za
  kalendářní rok, rozpad po zemích a upozornění od 80 % i po překročení.
  Přepočet je orientační a **režim sám nezapne**.
- **Plnění, u kterých si systém není jistý**, označí k ručnímu posouzení; najdeš
  je filtrem **Místo plnění (OSS)** v seznamu faktur a vyřešíš hromadnou akcí
  **Nastavit OSS**.
- **Historické doklady nemusíš zadávat ručně** — import vydaných faktur režim
  OSS odvodí sám, viz [§ 21.4b](21_Importy.md#216-214b-zahranicni-doklady-a-rezim-oss).
- **Doklad s OSS řádkem se neexportuje do Pohoda XML ani Stereo XML** — ty
  formáty nemají kam zapsat zemi spotřeby.

**Postup ve zkratce:** zapni OSS režim v `Nastavení → Daně a účetnictví`, založ
si sazbu členského státu **se správnou zemí** (např. `SK-23` se zemí `SK`) a
vystav fakturu. Podklad a XML `OSSEI1` najdeš v `Daně → OSS přiznání`.

#### Příklad: slovenský spotřebitel, prodej zboží na dálku nad prahem

Slovensko má od **1. 1. 2025** základní sazbu **23 %**. Faktura slovenskému
spotřebiteli nad OSS prahem má mít:

- DIČ vystavitele s prefixem `CZ`,
- DIČ příjemce **prázdné** (B2C),
- sazbu DPH **23 %** ze sazebníku se zemí `SK`,
- měnu typicky EUR,
- OSS doložku na dokladu (aplikace ji doplní sama).

Daň se odvede přes OSS, ne přes tuzemské přiznání.

> [!WARNING]
> MyÚčto **neurčuje samo právní režim plnění, neuzavírá období a XML
> neodesílá**. Sledování prahu i kontrola sazeb jsou **upozornění**, ne závazné
> určení povinnosti. OSS přiznání se navíc nepodává obecnou cestou EPO, ale
> v samostatné aplikaci **MOSS/OSS** Daňového portálu —
> [§ 40.8.5](40_OSS.md#4095-kde-se-oss-priznani-podava). Před podáním vždy ověř
> sazby, zemi spotřeby, přepočet a výsledné XML s účetní nebo daňovým poradcem.

### 35.5.3 Export mimo EU není reverse charge

Pro službu poskytnutou klientovi mimo EU (např. americkému) se v ČR uplatňuje
0 % — plnění je mimo předmět české DPH dle § 9 odst. 1 ZDPH. To **není reverse
charge** v právním slova smyslu: checkbox „Reverse charge" je určený pro EU režim
a § 92a a generuje českou zákonnou poznámku, která pro třetí zemi není přesná.
**Pro export mimo EU použij sazbu `CZ-0`** a do poznámky pod položkami doplň
anglický text typu „Outside the scope of EU VAT — § 9(1) of Czech VAT Act".
Do souhrnného hlášení takové plnění nepatří.

### 35.5.4 Registrace k DPH ve více zemích

OSS pokrývá **B2C plnění do EU** a registraci v cílových státech ve většině
případů nahradí. Nepokryje ale situace, kdy máš v cizí zemi **skutečnou
registraci k DPH** — typicky e-shop s lokálním skladem, kde plnění začíná
i končí v jiném státě.

MyÚčto má jeden dodavatelský profil s jedním DIČ. Workaround: založ druhého
dodavatele (`Nastavení → Dodavatelé → Přidat`) a přepínej mezi nimi přepínačem
v hlavičce — viz [91. Více dodavatelů](91_Multi_supplier.md). **Není to
plnohodnotná multi-jurisdikční podpora**: přiznání k DPH pro každou zemi řeš
s místní účetní.

## 35.6 Co MyÚčto dělá a co nedělá

### 35.6.1 MyÚčto **dělá**

- Vystavení dokladu — faktura, zálohová, daňový doklad k platbě, dobropis,
  storno, zjednodušený doklad
- Evidence faktur, klientů, zakázek, plateb; pravidelnou fakturaci, upomínky
  a hromadné akce nad doklady
- Generování PDF s QR platbou (SPAYD pro CZK, SEPA EPC pro EUR) a odesílání
  e-mailem přes vlastní SMTP
- **ARES**, **VIES** a **registr plátců DPH (CRPDPH)** — doplnění údajů,
  ověření VAT ID, kontrola zveřejněných účtů a nespolehlivého plátce (§ 109)
- Bankovní importy **GPC/ABO i PDF výpisů** (KB, Fio, ČSOB, Raiffeisenbank,
  Česká spořitelna, mBank, Creditas a další), e-mailová avíza z IMAP,
  chytré párování plateb a platební příkazy
- **AI extrakci přijatých dokladů** (Anthropic, Azure OpenAI, OpenAI, Google
  Gemini) — vždy jen jako návrh k potvrzení člověkem
- Export pro účetní v šesti formátech: **PDF ZIP, ISDOC 6.0.2, Pohoda XML,
  Stereo XML, Money S3 XML, CSV**, plus hromadný měsíční ZIP
- **Podvojné účetnictví i daňovou evidenci** — účetní deník, hlavní knihu,
  předvahu, rozvahu, výsledovku, saldokonto, majetek a odpisy, uzávěrku,
  automat účtování
- **Sklad a e-shop** — skladové karty, příjemky/výdejky, inventury, oceňování
  klouzavým průměrem, katalog, cenotvorbu z nákupní ceny
- **Mzdy** — [úplný mzdový modul](58_Uplne_mzdy.md) (osobní karty, pracovní
  vztahy, docházka, absence a dovolená, mzdové složky, mzdový běh, srážky a
  exekuce, výplatní pásky, mzdový list, platby odvodů a účetní můstek)
  i jednodušší [Mzdovou rekapitulaci](57_Mzdy.md); úplné mzdy zatím používejte
  ve zkušebním provozu podle upozornění níže
- XML pro EPO portál MFČR: **přiznání k DPH (DPHDP3), kontrolní hlášení
  (DPHKH1), souhrnné hlášení (DPHSHV), daň z příjmů (DPFO/DPPO — řádné,
  opravné i dodatečné, vč. hospodářského roku)** a **OSS přiznání (OSSEI1)**
- **Podání na EPO** ve dvou režimech: **přímé podání se ZAREP** (uznávaný
  elektronický podpis kvalifikovaným certifikátem, test oficiální podatelny
  a po samostatném potvrzení skutečné odeslání) a **asistované podání**
  (odeslání snapshotu na oficiální endpoint a otevření předvyplněného
  formuláře) — viz [89. EPO podání a archiv](89_Archiv_podani_a_rekonciliace.md)
- Rozšířené opravy DPH — **§ 43, § 46, § 74b, § 79 a § 79a**
- Pojistné OSVČ: přehled sociálního pojištění pro ČSSZ **jako validovanou XML
  datovou větu** a přehled pro zdravotní pojišťovnu jako PDF pomůcku
- Archiv podání s otiskem SHA-256, doručenkami a **rekonciliaci** podaného
  stavu proti dnešním datům
- **REST API v1 (OpenAPI 3.1)**, MCP server a klientský portál

### 35.6.2 MyÚčto **nedělá**

- **Produkční mzdy jako jediný zdroj — zatím.** [Mzdový modul](58_Uplne_mzdy.md)
  běží ve zkušebním provozu: výsledek, odvody, dokumenty i podání je nutné
  ověřit proti jinému důvěryhodnému zdroji a modul nemá být jediným podkladem
  pro výplatu ani pro zákonné podání
- **IOSS ani režim mimo EU** — vede se pouze **režim EU** OSS
- **Podání OSS přes EPO** — `OSSEI1` se podává v samostatné aplikaci
  **MOSS/OSS** Daňového portálu, přímý ani asistovaný kanál ho proto nenabízí
  ([§ 40.8.5](40_OSS.md#4095-kde-se-oss-priznani-podava))
- **Jednotné automatické odeslání všech mzdových podání** — JMHZ lze po
  výslovném potvrzení řízeně odeslat přes VREP nebo ISDS a následně evidovat
  stav a protokol. Ostatní výstupy se podle druhu připraví ke stažení nebo se
  jejich splnění pouze zaeviduje; kanály zdravotních pojišťoven nejsou jednotné
  a část postupu zůstává ruční
- **Podání bez tvého potvrzení** — ani přímý kanál nic neodešle sám; kontrola
  částek a rozhodnutí podat zůstává na člověku
- **Výrobu a kusovníky** (marži lze spočítat, ale výrobní zakázky ne)
- **Insolvenční rejstřík**
- **Daňové poradenství** — všechny výstupy jsou pomůcka k ověření

Standardní tok je: **MyÚčto vystaví doklady → zaúčtuje je → vygeneruje výkazy →
uživatel nebo účetní je zkontroluje → podá se přímo z aplikace a archivuje se
doručenka.** Export do Pohody, Sterea, Money S3 nebo ISDOC je **volitelný** —
hodí se, když část agendy řešíš jinde, ale není nutnou součástí postupu.

## 35.7 Když si nejsi jistý

V pochybnostech platí jednoduchá poučka: **vyber konzervativnější variantu
a zeptej se účetní**.

- **Nevíš, jestli má klient nárok na RC?** → Nepoužij RC, dej 21 %. Klient si
  DPH odpočte, ty odvedeš. V nejhorším řešíš opravným dokladem.
- **Nevíš, jestli plnění patří do tuzemska, nebo do OSS?** → Neháduj sazbu.
  Ověř si, jestli je odběratel osobou povinnou k dani (má DIČ), jestli jde
  o TBE službu nebo zboží na dálku, a jestli jsi přes práh 10 000 EUR. Pokud se
  ukáže, že řádek patřil jinam, opravuje se to **opravným OSS podáním za původní
  čtvrtletí** (§ 40.8.3), respektive dodatečným přiznáním — ne dorovnáním
  v běžném období.
- **Řekne ti klient, že DPH je špatně?** → V editoru opravíš a vystavíš
  **opravný daňový doklad**. Jestli šlo o chybnou **výši daně** (špatná sazba),
  není to dobropis — patří to do evidence **§ 43** a do dodatečného přiznání za
  původní období.
- **Nevíš, jestli už jsi plátce?** → Zkontroluj banner v `Daně a účetnictví`.
  Plátcovství vzniká **ze zákona z obratu**, ne přihláškou; doměrek jde zpětně
  ode dne vzniku.

> [!TIP]
> Jednou ročně (typicky leden) projdi s účetní seznam svých klientů, sazeb a
> typů plnění. Pravidla DPH se mění — sazby, registrační limity, prahy OSS,
> elektronická fakturace. Hodinová konzultace ti ušetří spoustu opravných
> dokladů.

---

→ Pokračuj na [36. Výkazy DPH](36_Vykazy_DPH.md), [40. Režim OSS](40_OSS.md),
nebo se vrať na [INDEX](INDEX.md).
