# 15. Faktura - editor a výkaz víceprací

> Návod, jak vytvořit a upravit fakturu v editoru: vyplnit hlavičku a položky, zvolit
> způsob úhrady, přidat výkaz víceprací, nechat ho schválit zákazníkem, vystavit zálohovou
> fakturu a opravit chybný doklad. Pro každého, kdo fakturuje.

## 15.1 Kdy to potřebujete

Kapitolu otevřete, když:

- vystavujete novou fakturu, zálohovou fakturu nebo dobropis,
- fakturujete za hodiny a zákazník chce vidět rozpis práce nebo materiálu,
- zákazník musí výkaz víceprací před vystavením schválit,
- chcete fakturu zaplacenou hotově rovnou vyrovnat v pokladně,
- fakturujete v cizí měně nebo s cenami včetně DPH,
- prodáváte majetek nebo fakturujete plnění na delší období (předplatné, nájem),
- potřebujete opravit už vystavenou fakturu.

Editor otevřete přes **Nová faktura** (na dashboardu, v seznamu `Prodej → Vydané faktury`
nebo z detailu klienta či zakázky).

## 15.2 Než začnete

- **Klient.** Fakturu vystavujete na klienta, který musí být založený (`Prodej → Klienti`,
  viz [18. Klienti](18_Klienti.md)). Klienta lze vybrat i při psaní přímo v editoru.
- **Zakázka** (volitelná). Předvyplní hodinovou sazbu a splatnost, viz [19. Zakázky](19_Zakazky.md).
- **Číslování faktur.** Šablonu čísla nastavíte v `Nastavení`, záložce **Fakturace**, sekci
  **Číslování faktur** (viz [§ 95.5.3](95_Multi_supplier.md#956-krok-za-krokem-cislovani-faktur)).
- **Výchozí režim cen.** Chcete-li, aby nové faktury měly ceny včetně DPH, zapněte v
  `Nastavení`, záložce **Fakturace**, volbu **Nové faktury s cenami včetně DPH**
  (viz [§ 95.3](95_Multi_supplier.md#95131-co-je-per-dodavatel-izolovane)).
- **Pokladna.** Pro hotovostní úhradu musíte mít založenou aspoň jednu korunovou pokladnu
  ([§ 32.1](32_Pokladna.md#32111-ciselnik-pokladen)).

## 15.3 Krok za krokem: vystavit fakturu

![Editor faktury](img/09_editor.webp)

1. Klikněte na **Nová faktura**.
2. V poli **Typ dokladu** zvolte typ (**Faktura - daňový doklad**, **Zálohová faktura
   (proforma)**, **Opravný daňový doklad (dobropis)** nebo **Platební / splátkový kalendář**).
3. Vyberte **Klienta**. Případně vyberte **Zakázku** klienta.
4. Zkontrolujte data **Vystaveno**, **DUZP** a **Splatnost** a **Měnu**.
5. V části **Položky** klikněte na **+ Přidat položku** a vyplňte popis, množství, jednotku,
   cenu a sazbu DPH. Položku lze také vložit tlačítkem **Vložit z ceníku**.
6. Zvolte **Způsob úhrady**.
7. Zkontrolujte sumář vpravo (mezisoučet, sleva, DPH, **K úhradě**).
8. Klikněte na **Vytvořit** (u úpravy konceptu na **Uložit**). Faktura se uloží jako koncept.
   Uložit lze kdykoli i zkratkou Ctrl+S (macOS Cmd+S).
9. V detailu faktury klikněte na **Vystavit**. Faktura dostane číslo a vygeneruje se PDF.
10. Klikněte na **Odeslat klientovi**. Odeslání e-mailem je samostatný krok po vystavení.

**Jak poznáte, že je hotovo:** v seznamu `Prodej → Vydané faktury` má faktura stav
**Vystaveno**, po odeslání **Odesláno**.

> [!WARNING]
> Vystavení nelze vrátit zpět. Opravit lze jen stornem nebo dobropisem (viz
> [§ 15.7](#157-krok-za-krokem-opravit-chybnou-fakturu-storno-nebo-dobropis)). Upravujte proto
> jen koncepty.

> [!TIP]
> PDF konceptu (**Zobrazit PDF** v nabídce dalších akcí) má přes celou stranu vodoznak
> „NÁHLED“, takže si ho klient nespletete s vystavenou fakturou.

### 15.3.1 Fakturovat s cenami včetně DPH

1. V hlavičce zapněte volbu **Ceny zadávám včetně DPH**.
2. U položek zadávejte cenu do sloupce **Cena/j s DPH**.

**Jak poznáte, že je hotovo:** celková částka sedí na haléř. Výklad výpočtu je v
[§ 15.9.2](#1592-hlavicka).

### 15.3.2 Zaplacená hotově: vyrovnat v pokladně

1. V poli **Způsob úhrady** zvolte **Hotově**.
2. V poli **Pokladna** vyberte korunovou pokladnu (výchozí **Nepoužít pokladnu** nechá
   fakturu jen vytisknout s poznámkou).
3. Fakturu vystavte.

**Jak poznáte, že je hotovo:** aplikace ohlásí „Pokladní doklad {číslo} byl vystaven a
zaúčtován.“ a faktura je uhrazená. Pravidla jsou v [§ 15.9.2](#1592-hlavicka).

## 15.4 Krok za krokem: zálohová faktura a daňový doklad k ní

1. Vytvořte fakturu s typem **Zálohová faktura (proforma)** a vystavte ji. Dostane číslo s
   předponou `9` a nemá DUZP.
2. Až klient zaplatí (banka spáruje platbu, nebo fakturu ručně označíte jako zaplacenou),
   otevřete detail zálohové faktury.
3. Klikněte na **Vystavit fakturu k záloze** a potvrďte.
4. Vznikne daňový doklad typu faktura s automatickým odečtem zaplacené zálohy (záporná
   položka „Odpočet zálohy“ s číslem zálohové faktury).

**Jak poznáte, že je hotovo:** na obou dokladech je křížový odkaz, na daňovém dokladu je
odečet zálohy v sumáři.

### 15.4.1 Spárovat už existující zálohu a daňový doklad

Pokud už máte v systému oba doklady samostatně (typicky po importu), spárujete je z
kterékoli strany:

1. V detailu daňového dokladu bez vazby klikněte na **Spárovat se zálohou** a vyberte zálohovou
   fakturu téhož odběratele. V detailu zálohové faktury klikněte na **Spárovat s daňovým
   dokladem** a vyberte daňový doklad.
2. Propojení zrušíte tlačítkem **Zrušit propojení**.

**Jak poznáte, že je hotovo:** zobrazí se zpráva „Záloha propojena.“ Tlačítko se nabídne jen
tehdy, když u daného odběratele existuje vhodný nespárovaný protějšek.

## 15.5 Krok za krokem: výkaz víceprací a materiálu

Fakturujete-li za hodiny, můžete ke každé hodinové položce přidat výkaz, který se vytiskne
na 2. stranu PDF.

![Výkaz víceprací](img/09_vykaz_vicepraci.webp)

1. V editoru klikněte u položky na ikonu **Přidat výkaz práce**.
2. Pojmenujte výkaz a klikněte na **Přidat řádek**. Vyplňte **Datum**, **Popis**, **Hodiny**
   (zápis `1:20` nebo `1,5`) a **Sazbu**.
3. Zvolte sazbu DPH výkazu.
4. Klikněte na **Přenést do faktury**. Součet práce se přenese jako jedna položka
   `1 ks × celková cena práce`.
5. Potřebujete-li i materiál, rozbalte sekci **Výkaz materiálu** tlačítkem **Přidat výkaz
   materiálu**, klikněte na **Přidat materiál** a vyplňte popis, množství, jednotku (MJ) a cenu.
6. Fakturu uložte.

**Jak poznáte, že je hotovo:** faktura má položku „Práce“ (případně i „Materiál“) a na 2.
straně PDF je rozpis. Zapomenete-li výkaz přenést, aplikace vás před uložením upozorní.

Výkaz smažete ikonou odpojení u položky nebo tlačítkem **Smazat výkaz**. Položka faktury
zůstane, ale ztratí detailní rozpis. Podrobnosti a pravidla zadávání viz
[§ 15.9.6](#1596-vykaz-vicepraci) a [§ 15.9.10](#15910-vykaz-materialu).

## 15.6 Krok za krokem: nechat výkaz schválit zákazníkem

Funguje, má-li zakázka zapnutou volbu **Vyžaduje schválení výkazu práce zákazníkem**
(viz [§ 19.5](19_Zakazky.md#195-krok-za-krokem-schvalovani-vykazu-zakaznikem)).

1. Vytvořte koncept faktury s výkazem víceprací na takové zakázce.
2. V detailu faktury klikněte na **Odeslat ke schválení** a potvrďte. Zákazníkovi přijde
   e-mail s tlačítkem **Schválit vícepráce** a PDF výkazu.
3. Počkejte na rozhodnutí. Do té doby je tlačítko **Vystavit** zablokované s nápovědou
   „Faktura nepůjde vystavit, dokud zákazník neschválí výkaz.“
4. Po schválení se faktura **automaticky vystaví a odešle**.
5. Po zamítnutí zůstane koncept. Výkaz upravte a pošlete ke schválení znovu.

**Jak poznáte, že je hotovo:** v detailu faktury je u stavu schválení hodnota **Schválen**
a faktura je vystavená a odeslaná.

> [!TIP]
> Vzhled e-mailu si vyzkoušíte tlačítkem **Test schválení** (v nabídce dalších akcí). E-mail
> přijde na adresu aktuálního dodavatele, odkaz v něm nic nedělá.

Schválení mimo systém (telefonem) zapíše správce, viz [§ 15.9.7](#1597-schvalovani-vykazu-zakaznikem).

## 15.7 Krok za krokem: opravit chybnou fakturu (storno nebo dobropis)

1. Je-li to **koncept**, otevřete ho a upravte, nebo ho smažte tlačítkem **Smazat**.
2. Je-li fakturu potřeba stornovat, otevřete její detail a klikněte na **Storno / dobropis**.
3. Zvolte, zda jde o interní storno (klientovi se nic neposílá), nebo o dobropis (opravný
   daňový doklad, který klientovi pošlete).
4. Zrušit omylem provedené storno lze tlačítkem **Zrušit storno**.

**Jak poznáte, že je hotovo:** původní faktura má stav **Storno**, případně vznikl dobropis
se zápornými položkami.

Rozdíl mezi stornem a dobropisem a zaúčtování dobropisu viz [§ 15.9.9](#1599-storno-vs-dobropis).

## 15.8 Když něco nejde

<!-- cols: 32 34 34 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| „Faktura musí mít aspoň jednu položku.“ | Vystavit nelze doklad bez položek | Přidejte položku tlačítkem **+ Přidat položku** |
| „Výsledná částka k úhradě musí být větší než 0. Pro čistě záporný nebo nulový doklad použij dobropis.“ | Faktura nesmí skončit zápornou částkou | Vystavte dobropis, nebo zapněte vyúčtování s částkou k vyplacení ([§ 15.9.4](#1594-sumar-vpravo)) |
| Chyba „Číslo už existuje…“ | Ručně zadané číslo už je u jiné faktury téhož dodavatele | Číslo změňte, nebo pole nechte prázdné |
| Tlačítko **Vystavit** je zablokované | Zakázka vyžaduje schválení výkazu zákazníkem | Pošlete výkaz ke schválení ([§ 15.6](#156-krok-za-krokem-nechat-vykaz-schvalit-zakaznikem)) |
| „Nemáte založenou žádnou korunovou pokladnu - doklad zůstane neuhrazený.“ | Pod volbou **Hotově** není žádná korunová pokladna | Založte korunovou pokladnu, nebo úhradu vyřešte jinde |
| „Pokladní doklad se nepodařilo vystavit - doklad je uložený, ale zůstává neuhrazený.“ | Pokladní doklad nemohl vzniknout (zavřené období, smazaná pokladna) | Úhradu dořešte ručně v Pokladně |
| „Fakturu nelze stornovat - nejdřív vyřešte pokladní doklad, kterým byla hotově inkasována (…)“ | Pokladní doklad nejde zrušit (třeba kvůli uzavřenému období) | Vyřešte pokladní doklad v Pokladně a storno zopakujte |
| „Tento odkaz byl již použit nebo není platný“ | Schvalovací odkaz je jednorázový | Pošlete výkaz ke schválení znovu |
| Uložení faktury s prodejem majetku skončí chybou | Karta majetku už byla prodána jiným dokladem, nebo je vyřazená | Vyberte jinou kartu, viz [§ 15.9.3](#1593-polozky) |
| Doklad zálohy nelze automaticky zaúčtovat | K proformě existuje zároveň daňový doklad k platbě i vyúčtovací faktura, nebo víc vyúčtovacích faktur | Dořešte ručním zápisem, viz [§ 15.9.8](#1598-zalohova-faktura-a-danovy-doklad) |
| Pole data se neuloží | Neplatné datum (např. `31. 2.`) | Opravte datum |

## 15.9 Podrobnosti a pravidla

### 15.9.1 Editor - celkový přehled

Editor je rozdělený na tři bloky:

1. **Hlavička** (vlevo nahoře) - typ, klient, zakázka, data.
2. **Položky** (střed) - řádky faktury.
3. **Sumář a akce** (vpravo nahoře a dole) - částky, sleva, tlačítka.

### 15.9.2 Hlavička

#### Typ dokladu

<!-- cols: 30 46 24 -->
| Typ | Popis | Variabilní symbol |
|---|---|---|
| **Faktura** | Standardní daňový doklad | YYMMNNN - `2605001` |
| **Zálohová (proforma)** | Před DUZP, není daňový doklad. Po zaplacení z ní můžete vytvořit daňový doklad se započtením zálohy | `9` + YYMMNNN - `92605001` |
| **Dobropis (opravný daňový doklad)** | Záporné částky, opravuje původní fakturu | `7` + YYMMNNN - `72605001` |
| **Storno (interní)** | Pouze interní označení, nevystavuje se klientovi | bez předpony |

Změna typu rozpracovaného dokladu ho přečísluje: stávající číslo se uvolní z původní řady
a uloží se nové číslo z řady cílového typu.

Typ **Platební / splátkový kalendář** je daňovým dokladem jen s rozpisem plateb na předem
stanovené období (§ 31 a § 31a ZDPH). Splátky zadáte v části **Rozpis plateb** tlačítkem
**Přidat splátku** nebo **Rozpustit na 12 měsíců**. Bez rozpisu, nebo když součet rozpisu
nesedí na celkovou částku dokladu, kalendář vystavit nelze. Ke splátkám se pak už jednotlivé
doklady nevystavují.

Dobropis vztahující se k jiné faktuře nezakládejte ručně přes **Typ dokladu**, ale akcí
**Storno / dobropis** v detailu té faktury.

#### Klient a zakázka

- **Klient** (povinný) se vybírá z nabídky, vyhledávat lze podle jména nebo IČO. Při psaní
  se nabízí také firmy vedené pouze jako dodavatelé. Po vystavení faktury se jim doplní role
  odběratele. Prázdný seznam zůstává jen pro odběratele.
- **Zakázka** (volitelná): má-li klient zakázky, nabídne se jen jeho vlastní. Po výběru
  zakázky se předvyplní hodinová sazba a splatnost.

> [!WARNING]
> Pokud změníte klienta uprostřed editace, zakázka se vyresetuje (původní patřila jinému
> klientovi).

#### Data

<!-- cols: 30 70 -->
| Pole | Význam |
|---|---|
| Vystaveno | Datum vystavení (výchozí dnes) |
| DUZP | Datum uskutečnění zdanitelného plnění (výchozí shodné s vystavením) |
| Splatnost | Datum splatnosti, automaticky vypočítané z data vystavení a splatnosti zakázky (nebo klienta, nebo systému) |
| Číslo objednávky dodavatele | Volitelná obchodní reference, například `MYU000023`. Je uložená přímo na faktuře i bez zakázky, tiskne se v hlavičce PDF jako „Objednávka“ a lze podle ní párovat platby |
| Datum úhrady | Vyplní se automaticky při zaplacení (přes banku nebo ručně) |

Datumová pole se zadávají ve formátu jazyka aplikace: v češtině `d. m. rrrr` (např. `1. 9. 2026`
nebo zkráceně `1.9.26`), v angličtině `mm/dd/yyyy`. Formát se neřídí jazykem prohlížeče ani
systému. Ikona vpravo v poli otevře kalendář. Neplatné datum (např. `31. 2.`) pole označí
a faktura se neuloží, dokud ho neopravíte. Platí to stejně ve všech agendách, viz [§ 1.1](01_Uvod.md).

Zálohová faktura nemá DUZP, daňový doklad k záloze se vystaví po platbě.

#### Měna a DPH

- **Měna** se předvyplní z klienta (nebo zakázky), lze ji přepsat.
- **Reverse charge**: je-li zatržené, faktura bude bez DPH s textem „Daň přiznává odběratel“.
  Předvyplní se z klienta.

#### Zaokrouhlení úhrady

U pole **Zaokrouhlení** lze zvolit **Automaticky pro hotovost v Kč**, **Nezaokrouhlovat** nebo
**Na celé Kč**. Nová faktura má automatický režim. Pro dobírku nebo převod lze celé koruny
zapnout ručně. U dobírky tuto volbu používejte pouze při hotovostní úhradě. Platba kartou se
nezaokrouhluje.

Zaokrouhlení platí pro faktury a dobropisy v CZK, nikoli pro zálohové faktury a daňové
doklady k přijaté platbě. Při každém uložení se částka po odečtení zálohy matematicky
zaokrouhlí na nejbližší korunu: 916,44 Kč na 916 Kč, 9,70 Kč na 10 Kč. Rozdíl je uveden
samostatně v sumáři i PDF a je zahrnut v celkové částce. Základy položek, DPH a výkazy DPH
se nemění.

Režim je uložený na faktuře a přenáší se při kopírování i tvorbě dobropisu. Existující
doklady mají zaokrouhlení vypnuté, dokud ho výslovně nezměníte.

#### Číslo dokladu - ruční zadání (volitelné)

V hlavičce konceptu je pole **Číslo faktury** (podle typu **Číslo zálohové faktury** nebo
**Číslo dobropisu**). Pole je volitelné:

- **Prázdné** - při vystavení systém automaticky vygeneruje číslo podle šablony (per
  dodavatel v Nastavení nebo globální konfigurace). V poli vidíte živý náhled, např. `2605002`,
  což je číslo, které faktura dostane, pokud ho nepřepíšete.
- **Vyplněné** - použije se přesně vaše hodnota, počítadlo se neposune. Při vystavení se
  ověří, že číslo není už použité u jiné faktury **stejného dodavatele** (jinak vrátí chybu
  „Číslo už existuje…“). Maximálně 20 znaků.

> [!TIP]
> Standardně pole nechte prázdné. Ruční číslo použijte jen výjimečně, např. když migrujete
> historickou fakturu z jiného systému a potřebujete zachovat originální číslo. Ruční číslo
> obchází automatickou řadu, za jeho jedinečnost a návaznost ručíte sám.

> [!WARNING]
> Po vystavení je číslo neměnné, ani úprava správcem ho neodemkne. Chcete-li číslo změnit,
> musíte vystavit storno nebo dobropis a fakturu vystavit znovu pod jiným číslem.

Šablonu pro automatické generování nastavíte v `Nastavení`, záložce **Fakturace**, sekci
**Číslování faktur** (viz [§ 95.5.3](95_Multi_supplier.md#956-krok-za-krokem-cislovani-faktur)).

#### Platební variabilní symbol

Číslo faktury a variabilní symbol, pod kterým klient platí, můžou být dvě různé hodnoty. Pole
**Platební variabilní symbol** v hlavičce je volitelné:

- **Prázdné** - platební VS se odvodí z čísla faktury (jen číslice, nejvýše 10 znaků, pomlčky
  a písmena se vynechají). Pole ukazuje, jaký VS z čísla vznikne.
- **Vyplněné** - na PDF, v QR platbě, v e-mailu, v upomínce, na webové faktuře i v exportech
  (ISDOC, Pohoda, Money S3, Stereo) se použije tato hodnota. Číslo faktury se nemění.
  Povolené jsou jen číslice, nejvýše 10.

Platební VS **nemusí být unikátní**. Hodí se třeba u pravidelné fakturace, kdy zákazník platí
trvalým příkazem pod stále stejným VS:

<!-- cols: 50 50 -->
| Číslo faktury | Platební VS |
|---|---|
| `20260001` | `12345` |
| `20260002` | `12345` |

Platební VS lze změnit i u vystavené faktury přes úpravu správcem. Na účetnictví ani DPH nemá
vliv. Při kopii faktury a při vystavení daňového dokladu ze zálohy se převezme. Jak se podle
něj párují platby, popisuje [§ 29](29_Banka.md).

#### Ceny s DPH a bez DPH (brutto a netto)

Volba **Ceny zadávám včetně DPH** (v hlavičce u DPH) určuje, jak se na faktuře počítá daň:

<!-- cols: 20 24 30 26 -->
| Režim | Co je vstupem | Jak se počítá DPH | Typické použití |
|---|---|---|---|
| **bez DPH (netto)** (výchozí) | cena bez DPH | „zdola“: `DPH = základ × sazba` | běžné B2B faktury |
| **s DPH (brutto)** | cena včetně DPH | „shora“ koeficientem (§ 37 ZDPH): `DPH = round(brutto × sazba/(100+sazba))`, `základ = brutto − DPH` | účtenky, paragony, B2C, kde má sedět **celková částka** |

V režimu **s DPH** se zadává cena do sloupce **Cena/j s DPH** (u řádku také **Celkem s DPH**)
a celková částka faktury **sedí na haléř**. Například 33 Kč s DPH při 21 % dá základ **27,27**,
DPH **5,73** a celkem **33,00** (ne 32,9967, které by vyšlo přepočtem zdola). U více řádků
stejné sazby se haléřové reziduum dorovná na nejsilnějším řádku, takže součet daně přesně
odpovídá dani z celkového brutto. Detail faktury, PDF i DPH výkazy (přiznání, kontrolní
hlášení, kniha DPH) ukazují stejné číslo.

- **Zadání celkem s DPH:** editor **respektuje aktuální režim** dokladu (nepřepíná ho za vás).
  V běžném režimu bez DPH se z brutto dopočítá jednotková cena bez DPH (odečtením DPH shora),
  v režimu s DPH se uloží brutto. Režim přepínáte jen ručně.
- **Zobrazení jednotkové ceny:** v detailu, PDF i exportech (ISDOC, Pohoda) se „Cena/MJ“ vždy
  ukazuje jako **netto** (bez DPH), i v režimu s DPH, kde se netto dopočítá z řádkového základu.
- **Předvyplnění per dodavatel:** výchozí režim nové faktury nastavíte v `Nastavení`, záložce
  **Fakturace**, volbou **Nové faktury s cenami včetně DPH** (viz
  [§ 95.3](95_Multi_supplier.md#95131-co-je-per-dodavatel-izolovane)). Pokud u dodavatele
  neurčíte jinak, nová faktura se otevře v režimu bez DPH. Přepnutí je vždy vědomá volba v editoru.

> [!TIP]
> Režim s DPH funguje stejně i u **přijatých faktur** (viz
> [§ 23.2.3](23_Prijate_faktury.md#23116-polozky-a-druh-vydaje)) a u **šablon pravidelné fakturace** (viz
> [§ 17.8.2](17_Pravidelne_fakturace.md#1782-sekce-faktura)).

#### Způsob úhrady a platba hotově

Pole **Způsob úhrady** (**Bankovní převod**, **Inkaso**, **Platební karta**, **Hotově**,
**Dobírka**, **Zápočet**, **Jiný způsob**) se tiskne na fakturu. QR platba se tiskne jen u
**Bankovního převodu**. U platby kartou nebo v hotovosti se v PDF a e-mailu nezobrazí ani
bankovní spojení. Volba **Hotově** navíc
otevře **hotovostní vyrovnání**, třetí způsob, jak se faktura stane uhrazenou, vedle bankovního
výpisu a ručního označení.

Po zvolení **Hotově** se pod polem objeví výběr **Pokladna**:

- Výchozí volba je **Nepoužít pokladnu**: faktura se jen vytiskne s poznámkou „hotově“ a o
  úhradu se postaráte jinde. Vyrovnání se nespustí.
- Nabízejí se **jen korunové pokladny** ([§ 32.1](32_Pokladna.md#32111-ciselnik-pokladen)).
  Valutová pokladna v seznamu není vůbec.
- Nemá-li firma žádnou korunovou pokladnu, pod polem se zobrazí hláška „Nemáte založenou
  žádnou korunovou pokladnu - doklad zůstane neuhrazený.“
- Uživatelé **klientského portálu** výběr nevidí.

**Co se stane při vystavení.** V okamžiku vystavení faktury (u konceptu se volba jen uloží a
čeká) systém automaticky:

1. vystaví ve zvolené pokladně **příjmový pokladní doklad (PPD)** s účelem „Úhrada faktury“,
   datem rovným **datu vystavení faktury**, částkou **zbývá k úhradě** a popisem „Úhrada
   vydané faktury {VS} hotově“,
2. doklad **rovnou zaúčtuje** (MD analytika pokladny / D 311), nevzniká žádný koncept ke
   schválení,
3. zaeviduje **úhradu** k faktuře, takže se faktura překlopí do stavu **Zaplaceno**.

Zpráva potvrdí „Pokladní doklad {číslo} byl vystaven a zaúčtován.“ Doklad najdete normálně
v [Pokladně](32_Pokladna.md) i v pokladní knize.

**Doklad k vyplacení.** Faktura se zápornou výslednou částkou nebo dobropis (se zapnutou
volbou z [§ 15.9.4](#1594-sumar-vpravo)) dostane místo PPD **výdajový pokladní doklad (VPD)**
na částku k vrácení, zaúčtovaný MD 311 / D analytika pokladny. Doklad se označí jako vyplacený.
Zrušení volby, storno i smazání VPD ho vrátí mezi otevřené. Bez volby se u záporné částky
nic nevystaví.

**Je to plně vratné.** Když volbu zrušíte (přepnete způsob úhrady jinam, nebo vyberete
**Nepoužít pokladnu**), systém při uložení **smaže pokladní doklad i jeho zápis v deníku**,
zruší evidovanou úhradu a faktura se vrátí do původního stavu. Změníte-li pokladnu, doklad se
**přesune** (starý se zruší, v nové pokladně vznikne nový s novým číslem), nezdvojí se.
Opakované uložení beze změny neudělá nic. **Ručně pořízeného pokladního dokladu se vyrovnání
nikdy nedotkne**, ani když je navázaný na tutéž fakturu.

> [!TIP]
> Analytika účtu 211 se bere z **karty zvolené pokladny**, ne natvrdo z 211. Máte-li dvě
> pokladny na `211.100` a `211.200`, zápis padne na tu správnou.

> [!WARNING]
> **Selhání vyrovnání nikdy neshodí vystavení faktury.** Když pokladní doklad z jakéhokoli
> důvodu vzniknout nemůže (zavřené období, mezitím smazaná pokladna), faktura se normálně
> vystaví a jen se zobrazí varování „Pokladní doklad se nepodařilo vystavit - doklad je
> uložený, ale zůstává neuhrazený.“ Úhradu pak dořešíte ručně v Pokladně.
>
> Naopak **storno faktury, která byla hotově inkasovaná, se neprovede**, dokud jde pokladní
> doklad zrušit. Když to nejde (třeba kvůli uzavřenému období), vrátí systém chybu „Fakturu
> nelze stornovat - nejdřív vyřešte pokladní doklad, kterým byla hotově inkasována (…)“.

Kde hotovostní vyrovnání nefunguje:

<!-- cols: 30 70 -->
| Případ | Chování |
|---|---|
| **Zálohová (proforma) faktura**, storno faktura, platební kalendář | Výběr pokladny se vůbec nezobrazí. Úhrada zálohy totiž zakládá navazující finální doklad nebo daňový doklad k platbě, a ten by pozdější zrušení volby neumělo vzít zpět. Zálohu inkasujte hotově přímo v [Pokladně](32_Pokladna.md#32113-hlavicka-dokladu-a-ucel) |
| **Pravidelné (opakované) fakturace** | Šablona pole „Pokladna“ nemá. Vygenerovaná faktura sice zdědí způsob úhrady „Hotově“, ale pokladnu ne, takže **žádný doklad nevznikne a faktura zůstane neuhrazená**. Totéž platí pro finální fakturu vystavenou ze zálohy |
| **Cizoměnová faktura** | Vyrovnání se přeskočí („Cizoměnový doklad z pokladny hradit nelze.“) |
| **Valutová pokladna** | Nenabízí se |
| **Faktura už uhrazená jinou cestou** | Vyrovnání se přeskočí, aby úhradu nezdvojilo |

Přeskočení není chyba, systém ho oznámí informativní hláškou a fakturu uloží.

### 15.9.3 Položky

Tabulka řádků faktury. Tlačítko **+ Přidat položku** přidá nový řádek.

Vedle něj je volba **Vložit z ceníku**. Po výběru položky systém použije cenu pro aktuálního
zákazníka a měnu dokladu. Přednost má individuální cena zákazníka, potom pevná cena ceníku
v měně dokladu a nakonec povolený kurzový přepočet ze základní měny. Ceníková položka musí
používat stejný režim cen s DPH nebo bez DPH jako doklad. Před vložením z ceníku je potřeba
vybrat zákazníka a měnu dokladu.

Volba se zobrazuje jen firmám bez aktivního modulu **Sklad**. Při zapnutém skladu nebo e-shopu
se položky vybírají ze skladových karet, aby v aplikaci nevznikaly dva souběžné zdroje cen
a produktových údajů.

Ve výběru lze hledat podle kódu a názvu. U každé nabídky je uvedena výsledná cena a její zdroj,
u kurzového přepočtu také datum použitého kurzovního lístku. Vložený řádek je samostatný
snapshot. Lze jej dále upravit a pozdější změna ceníku, zákazníka nebo kurzu jej automaticky
nepřecení. Správa ceníku je popsána v [§ 96.1.5](96_Nastaveni.md#96161-ciselniky-podrobnosti).

<!-- cols: 24 76 -->
| Sloupec | Význam |
|---|---|
| Popis | Co fakturujete. Lze víceřádkově. Je-li v popisu měsíc (`Konzultace 3/2026`), klonování faktury ho automaticky posune |
| Množ. | Počet jednotek (kusy, hodiny, ...) |
| Jed. | Jednotka z číselníku (výchozí `h` / hodina). Číselník spravuje správce v `Systém → Sazby a číselníky`, záložce **Jednotky**, viz [§ 96.1.4](96_Nastaveni.md#96161-ciselniky-podrobnosti) |
| Cena/j | Jednotková cena (v režimu bez DPH netto, v režimu s DPH brutto, viz [§ 15.9.2](#1592-hlavicka)) |
| DPH | Sazba: `21 %`, `12 %`, `0 %` (osvobozeno), `RC` (reverse charge) |
| Celkem | Automaticky: množství × cena/j |
| Celkem s DPH | Cena řádku včetně DPH. Zadání respektuje aktuální režim: v režimu bez DPH se z brutto zpětně dopočte cena bez DPH, v režimu s DPH se uloží brutto |

**Pořadí.** Levým úchytem (☰) přetáhněte položky pro změnu pořadí. Pořadí se zachová v PDF.

**Smazání položky.** Křížkem vpravo. Pokud je položka propojená s výkazem víceprací (viz
[§ 15.9.6](#1596-vykaz-vicepraci)), smazání se zeptá, zda smazat i výkaz.

#### Prodej majetku

Zaškrtávátko **Prodej majetku** v hlavičce sekce položek (jen v podvojném účetnictví) přidá
ke každému řádku našeptávač **Karta majetku**. Hledá v kartách v užívání, drobný i dlouhodobý
majetek pohromadě. Samotné zaškrtávátko je jen pomůcka pro zobrazení našeptávače, nikam se
neukládá a po znovuotevření faktury se zapne samo, když už některý řádek kartu nese.
Odškrtnutí naopak vazby ze všech řádků zruší.

Vybraná karta předvyplní popis řádku (cenu ne: pořizovací cena z karty není prodejní cena,
v nabídce ji vidíte jen jako informaci) a určí dvě věci:

<!-- cols: 22 22 56 -->
| Druh karty | Výnos řádku | Co se stane po vystavení faktury |
|---|---|---|
| Drobný majetek | **642** (tržby z prodeje materiálu) | karta přejde na *prodáno* a naváže se na doklad, nic dalšího se neúčtuje, zůstatková cena je nula |
| Dlouhodobý majetek | **641** | karta se vyřadí typem *Prodej*: účetní odpis do měsíce prodeje, daňový půlodpis § 26/7, zůstatková cena 541/08x a vyřazení 08x/02x |

Rozpad je **po řádcích**, takže jedna faktura může vedle sebe prodat majetek i fakturovat
službu a každý řádek sedne na svůj účet. Nenavázané řádky zůstávají na 602. Řádek s
dlouhodobým majetkem navíc dostane klasifikaci DPH **1m/2m**, prodej dlouhodobého majetku se
podle § 76 odst. 4 ZDPH nezapočítává do koeficientu. Ruční volba klasifikace má přednost.

Pokud se kartu uzavřít nepodaří (zavřené účetní období, nebo u dlouhodobého majetku rok, který
nemá potvrzený ani přerušený daňový odpis), **faktura se přesto vystaví a zaúčtuje** a systém
hned po vystavení upozorní, která karta zůstala v užívání a proč (např. „rok 2025 nemá
potvrzený ani přerušený daňový odpis“). Doděláte odpisy (viz
[§ 28.6](28_Majetek.md#286-krok-za-krokem-zauctovat-odpisy-roku)) a kartu vyřadíte z její vlastní
stránky.

Jednu kartu lze prodat jen jednou: pokud už ji prodal jiný doklad nebo je vyřazená, uložení
faktury skončí chybou. Storno faktury karty vrátí do užívání (u dlouhodobého majetku jen
dokud je období vyřazení otevřené, jinak zůstane záznam v auditu a kartu vrátí účetní ručně).

#### Časové rozlišení výnosu

Faktura za plnění na delší období (roční předplatné, nájem, servisní smlouva) se zaúčtuje
celá do výnosu ke dni zdanitelného plnění. Část, která patří do dalšího účetního období,
odloží až uzávěrka na účet **384 Výnosy příštích období** (viz
[§ 72.2.4](72_Uzaverka.md#7244-kroky-4-a-5-dohadne-polozky-a-casove-rozliseni)). K tomu stačí u
položky vyplnit období, do kterého výnos patří:

1. Nad tabulkou položek klikněte na odkaz **Časové rozlišení**.
2. U položky zvolte **+ období rozlišení** a vyplňte data **Od** a **Do**. Rozlišení zrušíte
   volbou **× zrušit rozlišení**.

Obsahuje-li text položky období (například `období 28. 9. 2026 – 28. 9. 2027`,
`10/2026 – 09/2027` nebo `předplatné na rok 2027`), aplikace ho pod položkou nabídne k použití
(**Použít jako časové rozlišení** / **Nepoužívat**). Návrh se nikdy nepoužije sám.

U vystavené a zaúčtované faktury se období nastaví v jejím **detailu**: stejný odkaz **Časové
rozlišení** nad položkami otevře pole **Od** a **Do** u všech řádků a tlačítko **Uložit časové
rozlišení** je uloží. Zápis faktury v deníku se tím nemění. Fakturu z uzavřeného účetního
období takto upravit nelze.

Období se přenáší ze zálohové faktury na vyúčtovací fakturu. Přes API se nastaví poli
`accrual_from` a `accrual_to` u položky, u vystavené faktury endpointem
`PUT /api/v1/invoices/{id}/accrual`.

### 15.9.4 Sumář (vpravo)

Automaticky se přepočítává:

- **Mezisoučet** - součet množství × cena/j všech položek,
- **Sleva** - pokud je vyplněná (procenta),
- **Základ DPH** - po slevě,
- **DPH 21 % / 12 % / 0 % (osvob.) / 0 % (RC)** - rozdělené podle sazeb v položkách,
- **K úhradě** - výsledná částka v měně faktury.

> [!TIP]
> Sazby DPH ve výběru: `0 % (osvob.)` znamená osvobozeno od DPH, `0 % (RC)` znamená reverse
> charge (přenesená daňová povinnost). Sazby mají stejné procento, ale jiný legislativní
> význam, vybírejte podle situace.

#### Sleva z celé faktury

Pole **Sleva z celé faktury** (v sumáři vpravo) je **procentuální sleva (0-100 %)** na úrovni
celého dokladu, typicky „sleva 10 % z celé faktury“.

- Při uložení se sleva **materializuje jako záporná položka „Sleva X %“** přímo mezi položkami
  faktury (vidíte ji v náhledu, detailu i v PDF).
- Pokud má faktura **víc sazeb DPH** (např. 21 % + 12 %), rozpadne se na zápornou položku **na
  každou sazbu zvlášť**, tím zůstává DPH po slevě účetně správně. U běžné faktury s jednou
  sazbou je to jedna položka „Sleva X %“.
- Díky tomu, že je sleva reálná položka, se promítne do souhrnu, rozpisu DPH i do všech DPH
  výkazů (Kniha DPH, přiznání DPH, Kontrolní hlášení).
- Slevovou položku needitujete ručně, generuje se z pole sleva. Mění se jen změnou procenta.
  Při klonování faktury a při vystavení daňového dokladu k proformě se sleva přenáší.
- Sleva v procentech se počítá z mezisoučtu **před** DPH.

> [!TIP]
> Pevnou slevu (např. -1 500 Kč) zadáte jako běžnou položku se zápornou cenou. Procentuální
> pole je pro slevu z celé faktury.

#### Faktura v cizí měně (EUR, USD, ...) a přepočet do CZK

Pokud je faktura v jiné měně než CZK, MyÚčto po **uložení** automaticky stáhne denní devizový
kurz **z ČNB** a uloží ho na fakturu. Kurz se použije pro přepočet **základů DPH** a **DPH** do
CZK (kvůli českému účetnictví). Kurz můžete ručně upravit, uložená hodnota se použije v PDF i
exportech. Liší-li se kurz na dokladu od denního kurzu ČNB, aplikace na to upozorní.

**Kdy se kurz načítá:**

- Po každém uložení faktury v cizí měně server požádá `cnb.cz` o kurzovní lístek pro datum
  vystavení.
- Pokud kurz pro daný den ještě není (víkend, svátek, pozdě večer), systém zkusí **až 7 dní
  zpět** a najde nejbližší dříve dostupný kurz.
- Kurz se cachuje v lokální databázi, opakované otevření faktury už neposílá nový požadavek.
- Pokud je ČNB nedostupná a žádný kurz není v cache, použije se **poslední známý kurz** (z
  dřívější faktury) a po uložení se zobrazí varování.

**Co se přepočítává:**

- základy DPH per sazba (21 %, 12 %, 0 %) do CZK,
- DPH per sazba do CZK,
- celkem bez DPH, DPH celkem a celkem do CZK,
- jednotlivé řádky položek se nepřepočítávají (zůstávají v cizí měně).

Zaokrouhlování CZK přepočtu: **HALF_UP, 2 desetinná místa, zvlášť per sazba DPH**.

**Kde je přepočet vidět:**

- **Detail faktury** - sekce „Přepočet do CZK“ pod hlavními součty.
- **PDF pro českého odběratele** - samostatná tabulka „Přepočet do CZK“ pod sumářem a drobná
  řádka s kurzem ČNB.
- **PDF pro zahraničního odběratele** - informativní přepočet ani kurz netiskne. Pokud doklad
  obsahuje českou DPH, uvede pouze zákonem požadovanou výši DPH v CZK.
- **Editor (opětovná úprava)** - informativní řádka pod součty s použitým kurzem.

#### Poznámky nad a pod položkami

Pod sumářem jsou dvě volná textová pole, která se tisknou na PDF:

<!-- cols: 36 64 -->
| Pole | Kde se vytiskne |
|---|---|
| **Poznámka nad položkami** | Mezi hlavičkou dokladu a tabulkou položek |
| **Poznámka pod položkami** | Pod sumářem, typicky obchodní podmínky, výhrada vlastnictví, penále z prodlení |

**Výchozí text poznámky pod položkami.** Text, který patří na **každou** fakturu, nemusíte psát
ručně pokaždé znovu. V `Nastavení`, záložce **Fakturace**, části **Výchozí poznámka pod
položkami** zapněte **Předvyplňovat poznámku pod položkami** a vyplňte text.

- Text se drží **podle jazyka dokladu**, ne podle měny. Jsou tam dvě pole, **Text pro doklady
  v češtině** a **Text pro doklady v angličtině**. Česká firma běžně fakturuje v eurech česky,
  proto rozhoduje jazyk.
- Předvyplní se jen na **nově zakládaném** dokladu. V dokladu ho můžete libovolně přepsat i
  smazat, je to výchozí hodnota, ne zámek.
- **Už vystavené a rozpracované doklady se nemění.** Uložení ani přepočet staré faktury text
  nedoplní ani nepřepíše.
- Když v rozpracovaném dokladu **přepnete jazyk** (ručně nebo výběrem klienta s nastavenou
  angličtinou), text se přepne na variantu nového jazyka, ale **jen pokud jste do pole
  nesáhli**. Ručně upravený text zůstane. Pokud pro nový jazyk text vyplněný není, pole se
  vyprázdní, ať v anglickém dokladu nezůstane česká věta.
- Dokud je volba **vypnutá**, nepředvyplňuje se nic. Výchozí nastavení je prázdné.

> [!TIP]
> Typický obsah: výhrada vlastnictví a sazba úroku z prodlení, třeba „Zboží zůstává až do
> úplného uhrazení majetkem dodavatele. Při zpožděné úhradě Vám budeme účtovat penále ve výši
> 0,05 % za každý započatý den prodlení.“

#### Vyúčtování s částkou k vyplacení

Někdy odpočty na faktuře převáží plnění: zákazníkovi dodáte zboží za 892 Kč, ale zároveň od
něj převezmete vrácený sud za 1 500 Kč. Výsledek je, že zákazníkovi **dlužíte 608 Kč**. Takový
doklad vystavíte jako jednu běžnou fakturu se zápornou výslednou částkou, bez dobropisu a
zápočtu.

Funkci zapnete v `Nastavení`, záložce **Fakturace**, volbou **Povolit vyúčtování s částkou k
vyplacení** (viz [§ 95.5.6](95_Multi_supplier.md#958-krok-za-krokem-kopie-e-mailu-podekovani-za-uhradu-a-vyuctovani-k-vyplaceni)). Bez
ní editor dál vyžaduje kladnou částku k úhradě.

Se zapnutou volbou:

- Faktura (ne zálohová, ne finální doklad ze zálohy) smí skončit zápornou částkou, pokud má
  **aspoň jeden kladný řádek**. Místo chyby se v sumáři ukáže informační box „Zákazníkovi
  vrátíte 608 Kč“ a řádek **K vrácení**.
- Doklad **jen se zápornými řádky** není vyúčtování. Editor ho dál odmítne a odkáže na dobropis.
- DPH se nemění: výkazy sčítají řádky, znaménko součtu je nezajímá.
- Zaokrouhlení na celé koruny (viz Zaokrouhlení úhrady v [§ 15.9.2](#1592-hlavicka)) platí i
  pro vyplácenou částku: -607,60 Kč hotově je -608 Kč.

**PDF a e-mail.** Místo „K úhradě“ se tiskne **K vrácení** s kladnou částkou a bez QR platby i
bez našeho bankovního spojení. U převodu věta „Částku vám vrátíme převodem pod variabilním
symbolem {VS}.“, u hotovosti „Částku vám vyplatíme v hotovosti.“, po vyplacení „Vyplaceno v
hotovosti.“. Dobropisy si ponechávají své texty.

**Jak peníze vrátit.** Podle způsobu úhrady na faktuře:

<!-- cols: 26 74 -->
| Způsob úhrady | Co se stane |
|---|---|
| **Hotově** se zvolenou pokladnou | Při vystavení vznikne **výdajový pokladní doklad (VPD)** na částku k vrácení, zaúčtuje se MD 311 / D analytika pokladny a faktura je rovnou **vyplacená** (viz [§ 15.9.2](#1592-hlavicka)) |
| **Převodem** | Faktura zůstane otevřená jako závazek vůči zákazníkovi. Vrácení zadáte z detailu faktury akcí **Vrátit peníze** nebo v [Platebních příkazech](26_Platebni_prikazy.md). Odchozí platba s VS faktury se z výpisu spáruje sama |

Stejně se se zapnutou volbou vyplácí i **dobropis** placený hotově. Peníze vrácené mimo
aplikaci označíte akcí **Označit jako vyplaceno**.

### 15.9.5 Tlačítka

Editor má dole jedno tlačítko: **Vytvořit** u nové faktury, **Uložit** u úpravy konceptu.
Faktura se uloží jako koncept, zůstane neviditelná pro klienta. Další akce najdete v **detailu
faktury**:

<!-- cols: 30 70 -->
| Tlačítko (detail) | Funkce |
|---|---|
| **Vystavit** | Přidělí variabilní symbol, vygeneruje PDF a nastaví stav **Vystaveno**. **Nelze vrátit zpět** (jen storno nebo dobropis) |
| **Odeslat klientovi** | Pošle vystavenou fakturu e-mailem klientovi (samostatný krok po vystavení) |
| **Zobrazit PDF** / **Stáhnout PDF** | Otevře, resp. stáhne PDF (nabídka dalších akcí). U konceptu jsou dostupné, jakmile má faktura aspoň jednu položku |
| **Smazat** | Jen pro koncept, vystavenou fakturu smazat nelze |
| **Klonovat** | Vytvoří nový koncept jako kopii faktury (po potvrzení lze posunout měsíce v popiscích o +1). Klonování zachová položky i výkaz víceprací |

### 15.9.6 Výkaz víceprací

Pokud fakturujete za **hodiny**, můžete ke každé hodinové položce přidat detailní výkaz, který
se vytiskne na **2. stranu PDF**.

V editoru klikněte na ikonu **Přidat výkaz práce** v řádku položky. Zobrazí se tabulka:

<!-- cols: 20 80 -->
| Pole | Význam |
|---|---|
| Datum | Den práce |
| Popis | Co bylo děláno |
| Hodiny | Hodiny a minuty (`1:20`) nebo desetinné hodiny (`1,5`) |
| Sazba | Hodinová sazba až na 6 desetinných míst, předvyplní se ze zakázky, lze přepsat |
| Celkem | Z přesných minut: `minuty × hodinová sazba / 60`, částka zaokrouhlená na haléře |

Přidejte řádky a výkaz uložte. Součet práce se přenáší jako jedna položka `1 ks × celková
cena práce`. Podrobný čas a sazby zůstávají ve výkazu.

Čas lze zadat také přímo do množství fakturační položky s hodinovou jednotkou (`h`, `hod`,
`hod.`). Například `0:01` při sazbě 1 000 Kč/h znamená 16,67 Kč. Sazba `333,33333 Kč/h` při
60 hodinách dává 20 000 Kč. Stejný způsob zadávání podporují přijaté faktury a šablony
pravidelné fakturace.

Šestimístnou sazbu lze zadat u ručních hodinových položek a ve výkazu práce. Položky navázané
na ceník používají cenu z ceníku na dvě desetinná místa.

Uložené desetinné hodiny se při otevření ani uložení bez změny času nepřevádějí na celé
minuty. Například `0,33 h` zůstává `0,33 h`, nikoli 20 minut. Desetinné zadání, které
neodpovídá celým minutám, zachovává původní přesnost: dvě desetinná místa ve výkazu a tři u
množství fakturační položky. Pro přesné minutové účtování použijte zápis `H:MM`. Skladové
řádky zachovávají běžné množství a cenu na dvě desetinná místa.

**Druhá strana PDF** má formát:

```
+--------------------------------------------------+
| Výkaz víceprací - faktura 2605001                |
|                                                  |
| Datum     Popis                Hod.  Sazba   Kč  |
| 03.05.    Konzultace strategie  2.0  1500   3000 |
| 04.05.    Code review           1.5  1500   2250 |
| ...                                              |
|                                                  |
| Celkem hodin: 12.5                               |
| Celkem k úhradě: 18 750 Kč                       |
+--------------------------------------------------+
```

**Smazání výkazu.** V editoru klikněte na ikonu odpojit (řetěz). Položka faktury zůstane, ale
ztratí detailní rozpis.

### 15.9.7 Schvalování výkazu zákazníkem

Pokud má zakázka zapnuté **Vyžaduje schválení výkazu práce zákazníkem** (viz
[§ 19.5](19_Zakazky.md#195-krok-za-krokem-schvalovani-vykazu-zakaznikem)) a faktura obsahuje výkaz víceprací, faktura **nepůjde vystavit**,
dokud zákazník výkaz neschválí přes e-mailový odkaz. Po schválení se faktura **automaticky
vystaví a odešle**.

V detailu faktury se objeví:

- **Štítek stavu schválení** v hlavičce vedle stavu (Neurčeno / Vyžádán / Schválen / Zamítnut /
  Vypršel),
- tlačítko **Odeslat ke schválení** (vedle **Vystavit**, jen u konceptu),
- tlačítko **Test schválení** (v nabídce dalších akcí) - pošle zkušební e-mail na adresu
  dodavatele bez vygenerování reálného tokenu,
- sekce **Schválení výkazu zákazníkem** s detaily (datum žádosti, datum rozhodnutí, kdo
  rozhodl, případný důvod zamítnutí),
- tlačítko **Změnit stav** (jen správce) - ruční zásah pro případy schválení mimo systém
  (telefonem, e-mailem mimo aplikaci).

#### Průběh schválení

1. Vytvoříte **koncept faktury** s výkazem víceprací na zakázce, která vyžaduje schválení.
2. Kliknete na **Odeslat ke schválení**. Systém vygeneruje jednorázový bezpečný odkaz,
   vyrenderuje samostatné PDF jen výkazu a pošle e-mail s velkým červeným tlačítkem
   **Schválit vícepráce** na fakturační e-maily zakázky (jinak hlavní e-mail klienta).
3. Tlačítko **Vystavit** je nyní zablokované s nápovědou „Faktura nepůjde vystavit, dokud
   zákazník neschválí výkaz.“
4. Zákazník v e-mailu klikne na tlačítko a otevře se **veřejná schvalovací stránka** (bez
   přihlášení), kde vidí výpis víceprací.

   ![Schvalovací stránka pro zákazníka](img/09_schvalit_vykaz_prace.webp)

5. Vybere **Schválit** nebo **Zamítnout** (s povinným důvodem). Chrání ji CAPTCHA proti botům a
   e-mail rozhodujícího se uloží do auditu.
6. Po schválení se stav faktury přepne na **Schválen**, faktura se **automaticky vystaví**
   (přidělí se variabilní symbol, snapshoty) a **automaticky odešle** standardním procesem (na
   hlavní e-mail klienta a všechny fakturační e-maily zakázky).
7. Po zamítnutí se stav přepne na **Zamítnut**, důvod se uloží a faktura zůstává konceptem.
   Výkaz můžete upravit a poslat znovu ke schválení (vygeneruje se nový odkaz, předchozí
   ztrácí platnost).

#### Test schválení

Pro náhled e-mailu před ostrým odesláním klikněte na **Test schválení** v nabídce dalších
akcí. E-mail půjde **na adresu aktuálního dodavatele**, odkaz v něm vede na zástupnou stránku,
která nic neudělá. Slouží jen ke kontrole vzhledu.

#### Ruční změna stavu (správce)

Pokud zákazník schválil mimo systém (telefonem, e-mailem), správce může v sekci **Schválení
výkazu zákazníkem** kliknout na **Změnit stav** a vybrat:

<!-- cols: 24 76 -->
| Stav | Akce |
|---|---|
| **Neurčeno** | Reset: odkaz zruší, vymaže časové údaje. Vrátíte fakturu před žádost |
| **Schválen** | Faktura se okamžitě vystaví a odešle (jako kdyby zákazník schválil přes web) |
| **Zamítnut** | Uloží zápis o zamítnutí s povinným důvodem. Faktura zůstává konceptem |

> [!WARNING]
> Stav **Vyžádán** v nabídce chybí. Vede k němu jen tlačítko **Odeslat ke schválení**, které
> generuje odkaz a posílá e-mail. Ručně se nastavit nedá.

#### Bezpečnost

- **Odkaz je jednorázový** - po schválení nebo zamítnutí přestane platit. Druhé kliknutí na
  e-mailový odkaz vrátí „Tento odkaz byl již použit nebo není platný“.
- **Veřejná stránka je chráněná CAPTCHA** (Cloudflare Turnstile) proti botům a anonymnímu spamu.
- **Kontrola původu požadavku (Origin/CSRF) je pro veřejné koncové body vypnutá** - zákazník
  přijde z e-mailového klienta s prázdnou nebo cizí hlavičkou Origin. Proti botům chrání odkaz
  a CAPTCHA.
- **Auditní log** - každá akce (žádost, schválení, zamítnutí, reset) se zapíše do aktivity
  faktury včetně IP adresy a user-agenta.

### 15.9.8 Zálohová faktura a daňový doklad

Postup je v [§ 15.4](#154-krok-za-krokem-zalohova-faktura-a-danovy-doklad-k-ni). Po propojení
se na obou dokladech zobrazí křížový odkaz a tlačítko **Zrušit propojení**. Na daňový doklad se
doplní **odečet zálohy**, pokud byl nulový, nejvýše však do výše částky dokladu (aby „K úhradě“
nešlo do mínusu). Zaplacení se nemění. Propojená záloha (proforma) zároveň vypadne z
pohledávek a z faktur po splatnosti.

#### Zaúčtování zálohového cyklu (proforma, DDKP, vyúčtování)

Z pohledu [Účetního deníku](52_Ucetni_denik.md) fungují tři doklady zálohového cyklu takto:

- **Zaplacení zálohové (proforma) faktury** se zaúčtuje jako přijetí zálohy (MD 221 banka nebo
  211 pokladna / D 324 Přijaté zálohy), ne jako běžná pohledávka 311, protože proforma není
  daňový doklad. Viz [Banka](29_Banka.md) a [Pokladna](32_Pokladna.md).
- **Daňový doklad k přijaté platbě** (typ „Daňový doklad k platbě“, viz
  [§ 14.11.1](14_Faktury.md#14111-sloupce-seznamu)) se zaúčtuje **jen za DPH** (MD 324 / D 343),
  bez fiktivní pohledávky 311 a bez duplicitního výnosu 6xx (ten patří až finální faktuře).
- **Vyúčtovací (finální) faktura** navázaná na proformu (viz [§ 15.4.1](#1541-sparovat-uz-existujici-zalohu-a-danovy-doklad))
  při zaúčtování automaticky doplní **zúčtovací řádek zálohy** (MD 324 / D 311) ve výši
  skutečně **přijaté** zálohy, ne nominální částky proformy, takže i částečně zaplacená nebo
  jinak vyrovnaná proforma se zúčtuje správně.

> [!WARNING]
> Mimo automatiku zůstává proforma, ke které existuje **zároveň** daňový doklad k přijaté
> platbě i vyúčtovací faktura, a proforma navázaná na **víc než jednu** vyúčtovací fakturu.
> V obou případech by částka zúčtování zálohy nebyla jednoznačná, systém takový doklad
> automaticky nezaúčtuje a je potřeba dořešit ho ručním zápisem.

### 15.9.9 Storno vs. dobropis

Pokud zjistíte, že vystavená faktura je špatně:

- **Storno (interní)** - pouze interní označení, faktura zmizí ze statistik jako „neexistuje“.
  **Klientovi se nic neposílá.** Použijte, když jste fakturu ještě neposlali a nechcete ji v
  evidenci.
- **Dobropis (opravný daňový doklad)** - vystavíte nový doklad se zápornými položkami, který
  klientovi pošlete jako oficiální opravu. Účetně správné, ale vyžaduje, abyste s klientem
  komunikovali o tom, co a proč. Dobropis vztahující se k jiné faktuře vystavte přes **Storno /
  dobropis** v detailu té faktury, ne ručně.

> [!TIP]
> **Zaúčtování dobropisu.** Dobropis se do [Účetního deníku](52_Ucetni_denik.md) zaúčtuje
> automaticky stejně jako běžná faktura. Systém pozná opravný doklad (typ Dobropis, nebo
> záporná celková částka) a zápis automaticky **otočí strany MD/Dal** a použije absolutní
> částku, takže výsledný zápis je čitelný (kladné částky na správné straně), ne matoucí záporná
> čísla. Funguje stejně v CZK i v cizí měně.

**Zápočet dobropisu s fakturou.** V daňové evidenci se vystavený dobropis k faktuře, která ještě
není zaplacená, s ní automaticky započte: faktuře klesne **Zbývá uhradit** o částku dobropisu a
dobropis je vyrovnaný. Odběratel pak platí jen rozdíl a jeho platbu aplikace spáruje s bankou
podle zbytku. V platbách faktury se zápočet ukáže se zdrojem **Zápočet dobropisu**. V podvojném
účetnictví se dobropis automaticky nezapočítává, dobropis i faktura zůstávají na saldokontu
každý sám za sebe. Započíst je jde tlačítkem **Započíst s fakturou**.

- Zápočet vzniká jen v plné výši dobropisu a jen tehdy, když ho zbytek faktury pokryje. Dobropis k
  už zaplacené faktuře zůstává k vrácení peněz jako dosud.
- V detailu dobropisu je odkaz na fakturu a tlačítko **Zrušit zápočet** pro případ, že odběratel
  přesto zaplatil celou fakturu. Dobropis vystavený dřív se dá započíst tlačítkem **Započíst s
  fakturou**.
- Zápočet není platba: v [peněžním deníku](74_Danova_evidence.md) se neobjeví, příjmem je až
  skutečně přijatý zbytek. DPH se nemění, dobropis je v evidenci DPH sám za sebe.

### 15.9.10 Výkaz materiálu

Vedle výkazu víceprací lze ke stejné faktuře vést i **výkaz materiálu**, samostatný rozpis
spotřebovaného materiálu, který se do faktury přenese jako **druhá souhrnná položka**
„Materiál“ (vedle položky „Práce“). Oba výkazy sdílí jeden výkaz faktury, tisknou se na **2.
stranu PDF** a schvalují se zákazníkem **zároveň** (viz [§ 15.9.7](#1597-schvalovani-vykazu-zakaznikem)).

Zatímco výkaz práce počítá *hodiny × sazba*, výkaz materiálu má místo hodin **množství a měrnou
jednotku** (výchozí „ks“) a **cenu za jednotku**, zadává se tedy ve stylu položek faktury.

Editor materiálu je na dvou místech (stejně jako výkaz práce):

- v **editoru faktury** v sekci **Výkaz materiálu** tlačítkem **Přidat výkaz materiálu**,
- v **detailu a seznamu faktur** tlačítkem **Výkaz**, v okně sekce Výkaz materiálu.

Dokud výkaz nemá žádný řádek, je sekce **zabalená** a rozbalíte ji tlačítkem **Přidat výkaz
materiálu**.

<!-- cols: 20 80 -->
| Pole | Význam |
|---|---|
| Popis | Co bylo dodáno (např. „Kabel UTP cat6“) |
| Množství | Desetinné číslo (počet kusů, metrů, kg, ...) |
| MJ | Měrná jednotka z číselníku (výchozí „ks“) |
| Cena/MJ | Jednotková cena - **bez DPH, nebo s DPH podle režimu faktury** (viz níže) |
| Celkem | Automaticky: množství × cena/MJ |

**Sazba DPH výkazu.** Každý výkaz nese **jednu sazbu DPH** (sumarizuje se do jedné položky
faktury). U výkazu práce je volitelná, výchozí **21 %**. U výkazu materiálu je volitelná,
výchozí **12 %**. Sazbu vyberete v záhlaví příslušné sekce. Materiál a práce tak mohou mít
**různou sazbu DPH** na jednom dokladu, DPH se na faktuře rekapituluje správně po sazbách.

**Ceny s DPH a bez DPH.** Cena za jednotku se zadává v **cenové konvenci dokladu** (volba **Ceny
zadávám včetně DPH**, viz [§ 15.9.2](#1592-hlavicka)):

- režim **bez DPH** - zadáváte cenu/MJ bez DPH,
- režim **s DPH** - zadáváte cenu/MJ včetně DPH, DPH se dopočte koeficientem shora (§ 37 ZDPH).

Sazba DPH (12 %) a konvence ceny (s/bez) jsou **dvě nezávislé věci**: materiál má 12 % bez
ohledu na to, jestli cenu píšete s DPH nebo bez.

**PDF a schválení.** Pokud má výkaz materiálu aspoň jeden řádek, na **2. straně PDF faktury**
se pod tabulkou práce vytiskne i tabulka **Materiál** (popis, množství, MJ, cena/MJ, celkem).
Položka „Materiál“ ve faktuře je **proklik** na tuto tabulku (stejně jako položka práce). Ve
schvalovacím e-mailu i na schvalovací stránce zákazník vidí obě části a schvaluje je **najednou**.

### 15.9.11 Tipy

- Ctrl+S (macOS Cmd+S) kdykoli uloží rozpracovanou fakturu jako koncept.
- Klonování zachová položky i výkaz víceprací, datum se aktualizuje na dnešní, popis položky
  posune měsíc.
- **Reverse charge** automaticky nastaví všechny položky na sazbu „RC“ (0 %) a v PDF přidá text
  „Daň přiznává odběratel“.

## 15.10 Související kapitoly

- [14. Faktury](14_Faktury.md) - seznam faktur a hromadné akce.
- [16. Faktura PDF](16_Faktura_PDF.md) - detail, PDF, odeslání a úhrady.
- [17. Pravidelná fakturace](17_Pravidelne_fakturace.md) - šablony opakovaných faktur.
- [19. Zakázky](19_Zakazky.md) - zakázky a schvalování výkazu.
- [32. Pokladna](32_Pokladna.md) - pokladní doklady k hotovostním fakturám.
- [52. Účetní deník](52_Ucetni_denik.md) - zaúčtování faktur.
