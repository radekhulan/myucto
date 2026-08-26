# 15. Faktura — editor a výkaz víceprací

Editor faktury slouží k tvorbě nového konceptu nebo úpravě existujícího.
Otevře se přes **+ Nová faktura** (z dashboardu), **Faktury → Nová faktura**,
nebo z detailu klienta / zakázky.

## 15.1 Editor — celkový přehled

![Editor faktury](img/09_editor.webp)

Editor je rozdělený na tři bloky:

1. **Hlavička** (vlevo nahoře) — typ, klient, zakázka, data
2. **Položky** (střed) — řádky faktury
3. **Sumář a akce** (vpravo nahoře + dole) — částky, sleva, tlačítka

## 15.2 Hlavička

### 15.2.1 Typ dokladu

| Typ | Popis | Variabilní symbol |
|---|---|---|
| **Faktura** | Standardní daňový doklad | YYMMNNN — `2605001` |
| **Zálohová (proforma)** | Před DUZP, není daňový doklad. Po zaplacení můžeš z ní vytvořit „Daňový doklad" se započtením zálohy. | `9` + YYMMNNN — `92605001` |
| **Dobropis (opravný daňový doklad)** | Záporné částky, stornuje původní fakturu | `7` + YYMMNNN — `72605001` |
| **Storno (interní)** | Pouze interní označení, nevystavuje se klientovi | (bez prefixu) |

### 15.2.2 Klient + Zakázka

- **Klient** (povinný) — vyber z dropdownu, vyhledávání podle jména / IČO.
- **Zakázka** (volitelná) — pokud klient má zakázky, dropdown nabídne jen jeho
  vlastní. Po výběru zakázky se předvyplní hodinová sazba a splatnost.

> ⚠️ Pokud změníš klienta uprostřed editace, zakázka se vyresetuje (původní
> patřila jinému klientovi).

### 15.2.3 Data

| Pole | Význam |
|---|---|
| Vystaveno | Datum vystavení (dnes default) |
| DUZP | Datum uskutečnění zdanitelného plnění (= vystaveno default) |
| Splatnost | Datum splatnosti — automaticky vypočítáno z `vystaveno + splatnost zakázky` (nebo klienta nebo systému) |
| Datum úhrady | Vyplní se automaticky při zaplacení (přes banku nebo manuálně) |

### 15.2.4 Měna a DPH

- **Měna** — předvyplní se z klienta (nebo zakázky), lze přepsat.
- **Reverse charge** — checkbox; pokud zatržené, faktura bude bez DPH s textem
  „Daň přiznává odběratel". Předvyplní se z klienta.

### 15.2.5 Číslo dokladu — ruční override (volitelné)

V hlavičce konceptu je pole **Číslo faktury** (resp. „Číslo zálohové faktury" /
„Číslo dobropisu" podle typu). Pole je **volitelné**:

- **Prázdné** — při Vystavení (status `draft → issued`) systém automaticky
  vygeneruje číslo dle šablony (per-dodavatel v Nastavení nebo globální cfg).
  V placeholderu pole vidíš live náhled, např. `2605002` — to je číslo, které
  fakturu dostane, pokud nepřepíšeš.
- **Vyplněné** — backend použije přesně tvou hodnotu, neinkrementuje counter.
  Při Vystavení se ověří, že číslo není už použité u jiné faktury **stejného
  dodavatele** (jinak vrátí chybu „Číslo už existuje…"). Max 20 znaků.

> 💡 **Použití:** standardně nech prázdné, automatika tě nezklame. Manual
> override použij jen výjimečně — např. když migruješ historickou fakturu
> z jiného systému a potřebuješ zachovat originální číslo.

> ⚠️ **Po Vystavení je číslo immutable** — admin force-edit ho NEodemkne.
> Pokud chceš číslo změnit, musíš vystavit storno/dobropis a fakturu vystavit
> znovu pod jiným číslem.

Šablonu pro automatické generování nastavuješ v **Systém → Dodavatelé →
[tvůj dodavatel] → Číslování faktur** — viz [§ 91.5.3](91_Multi_supplier.md#9153-cislovani-faktur).

### 15.2.6 Ceny „s DPH" vs „bez DPH" (brutto / netto režim)

Přepínač **Ceny zadávám s DPH / bez DPH** (v hlavičce u DPH) určuje, jak se na
faktuře počítá daň:

| Režim | Co je vstupem | Jak se počítá DPH | Typické použití |
|---|---|---|---|
| **bez DPH (netto)** *(výchozí)* | cena bez DPH | „zdola": `DPH = základ × sazba` | běžné B2B faktury |
| **s DPH (brutto)** | cena včetně DPH | „shora" koeficientem (§37 ZDP): `DPH = round(brutto × sazba/(100+sazba))`, `základ = brutto − DPH` | účtenky, paragony, B2C, kde má sedět **celková částka** |

V režimu **s DPH** se zadává cena do sloupce **„Celkem s DPH"** a celková částka
faktury **sedí na haléř** — např. 33 Kč s DPH @ 21 % → základ **27,27** / DPH
**5,73** / celkem **33,00** (ne 32,9967, které by vyšlo přepočtem zdola). U více
řádků stejné sazby se haléřové reziduum dorovná na nejsilnějším řádku, takže
součet daně přesně odpovídá dani z celkového brutto — **detail faktury, PDF i
DPH výkazy (přiznání, kontrolní hlášení, kniha DPH) ukazují stejné číslo.**

- **Zadání „Celkem s DPH":** editor **respektuje aktuální režim** dokladu (nepřepíná
  ho za tebe). V běžném režimu „bez DPH" se z brutto **dopočítá jednotková cena bez
  DPH** (odečtením DPH shora); v režimu „s DPH" se uloží brutto. Režim přepínáš jen
  ručně přepínačem nahoře.
- **Zobrazení jednotkové ceny:** v detailu, PDF i exportech (ISDOC, Pohoda) se „Cena/MJ"
  vždy ukazuje jako **netto** (bez DPH) — i v režimu „s DPH", kde se netto dopočítá z
  řádkového základu.
- **Předvyplnění per dodavatel:** výchozí režim nové faktury nastavíš v
  **Nastavení → Můj dodavatel → Ceny s DPH** (viz [§ 91.3](91_Multi_supplier.md#913-co-je-per-dodavatel-izolovane)).
- **Výchozí režim:** pokud u dodavatele neurčíš jinak, nová faktura se otevře
  v režimu „bez DPH". Přepnutí režimu je vždy vědomá volba v editoru.

> 💡 Režim „s DPH" funguje stejně i u **přijatých faktur** (viz [§ 23.2.3](23_Prijate_faktury.md#2323-polozky))
> a u **šablon pravidelné fakturace** (viz [§ 17.2.2](17_Pravidelne_fakturace.md#1722-sekce-faktura)).

### 15.2.7 Způsob úhrady a platba hotově

Pole **Způsob úhrady** (Bankovní převod / Platební karta / **Hotově** / Jiný) se
tiskne na fakturu. Volba **Hotově** navíc otevře **hotovostní vyrovnání** — třetí
způsob, jak se faktura stane uhrazenou, vedle bankovního výpisu a ručního
označení.

Po zvolení „Hotově" se pod polem objeví výběr **Pokladna**:

- Výchozí volba je **„Nepoužít pokladnu"** — faktura se jen vytiskne s poznámkou
  „hotově" a o úhradu se postaráš jinde. Vyrovnání se nespustí.
- Nabízejí se **jen korunové pokladny** ([§ 30.1](30_Pokladna.md#301-ciselnik-pokladen)).
  Valutová pokladna v seznamu není vůbec.
- Nemá-li firma žádnou korunovou pokladnu, pod polem se zobrazí hláška
  **„Nemáte založenou žádnou korunovou pokladnu — doklad zůstane neuhrazený."**
- Uživatelé **klientského portálu** výběr nevidí.

**Co se stane při vystavení.** V okamžiku vystavení faktury (u konceptu se volba
jen uloží a čeká) systém automaticky:

1. vystaví ve zvolené pokladně **příjmový pokladní doklad (PPD)** s účelem
   „Úhrada faktury", datem = **datum vystavení faktury**, částkou = **zbývá
   k úhradě** a popisem „Úhrada vydané faktury {VS} hotově",
2. doklad **rovnou zaúčtuje** (MD analytika pokladny / D 311) — nevzniká žádný
   koncept ke schválení,
3. zaeviduje **úhradu** k faktuře, takže se faktura překlopí do stavu
   **Uhrazeno**.

Toast potvrdí **„Pokladní doklad {číslo} byl vystaven a zaúčtován."** Doklad
najdeš normálně v [Pokladně](30_Pokladna.md) i v pokladní knize.

**Je to plně vratné.** Když volbu zrušíš — přepneš způsob úhrady jinam, nebo
vybereš „Nepoužít pokladnu" — systém při uložení **smaže pokladní doklad
i jeho zápis v deníku**, zruší evidovanou úhradu a faktura se vrátí do
původního stavu. Změníš-li pokladnu, doklad se **přesune** (starý se zruší,
v nové pokladně vznikne nový s novým číslem) — nezdvojí se. Opakované uložení
beze změny neudělá nic. **Ručně pořízeného pokladního dokladu se vyrovnání
nikdy nedotkne**, ani když je navázaný na tutéž fakturu.

> [!NOTE]
> Analytika účtu 211 se bere z **karty zvolené pokladny**, ne natvrdo z 211.
> Máš-li dvě pokladny na `211.100` a `211.200`, zápis padne na tu správnou.

> [!WARNING]
> **Selhání vyrovnání nikdy neshodí vystavení faktury.** Když pokladní doklad
> z jakéhokoli důvodu vzniknout nemůže (zavřené období, mezitím smazaná
> pokladna…), faktura se normálně vystaví a jen se zobrazí varování
> **„Pokladní doklad se nepodařilo vystavit — doklad je uložený, ale zůstává
> neuhrazený."** Úhradu pak dořeš ručně v Pokladně.
>
> Naopak **storno faktury, která byla hotově inkasovaná, se neprovede**, dokud
> jde pokladní doklad zrušit — když to nejde (třeba kvůli uzavřenému období),
> vrátí systém chybu „Fakturu nelze stornovat — nejdřív vyřešte pokladní
> doklad, kterým byla hotově inkasována (…)".

#### Kde hotovostní vyrovnání nefunguje

| Případ | Chování |
|---|---|
| **Zálohová (proforma) faktura**, storno faktura, platební kalendář | Výběr pokladny se vůbec nezobrazí. Úhrada zálohy totiž zakládá navazující finální doklad nebo daňový doklad k platbě, a ten by pozdější zrušení volby neumělo vzít zpět. Zálohu inkasuj hotově přímo v [Pokladně](30_Pokladna.md#3032-ucel-dokladu). |
| **Pravidelné (opakované) fakturace** | Šablona pole „Pokladna" nemá — vygenerovaná faktura sice zdědí způsob úhrady „Hotově", ale pokladnu ne, takže **žádný doklad nevznikne a faktura zůstane neuhrazená**. Totéž platí pro finální fakturu vystavenou ze zálohy. |
| **Cizoměnová faktura** | Vyrovnání se přeskočí („Cizoměnový doklad z pokladny hradit nelze."). |
| **Valutová pokladna** | Nenabízí se. |
| **Faktura už uhrazená jinou cestou** | Vyrovnání se přeskočí, aby úhradu nezdvojilo. |

Přeskočení není chyba — systém ho oznámí informativní hláškou a fakturu uloží.

## 15.3 Položky

Tabulka řádků faktury. Tlačítko **+ Přidat položku** přidá nový řádek.

Vedle něj je volba **Přidat z ceníku**. Po výběru položky systém použije cenu
pro aktuálního zákazníka a měnu dokladu. Přednost má individuální cena
zákazníka, potom pevná cena ceníku v měně dokladu a nakonec povolený kurzový
přepočet ze základní měny. Ceníková položka musí používat stejný režim cen
s DPH nebo bez DPH jako doklad.

Volba se zobrazuje jen firmám bez aktivního modulu **Sklad**. Při
zapnutém skladu nebo e-shopu se položky vybírají ze skladových karet, aby v
aplikaci nevznikaly dva souběžné zdroje cen a produktových údajů.

Ve výběru lze hledat podle kódu a názvu. U každé nabídky je uvedena výsledná
cena a její zdroj; u kurzového přepočtu také datum použitého kurzovního lístku.

Vložený řádek je samostatný snapshot. Lze jej dále upravit a pozdější změna
ceníku, zákazníka nebo kurzu jej automaticky nepřecení. Správa ceníku je popsána
v [§ 92.1.5](92_Nastaveni.md#9215-cenikove-polozky).

| Sloupec | Význam |
|---|---|
| Popis | Co fakturuješ. Lze multiline. **Tip:** pokud je v popisu měsíc (`Konzultace 3/2026`), klonování faktury automaticky inkrementuje. |
| Množství | Počet jednotek (kusy / hodiny / …) |
| Jednotka | Z číselníku (default `h` / hodina). Číselník spravuješ v **Systém → Číselníky → Jednotky** — viz [§ 92.1.4](92_Nastaveni.md#9214-jednotky). |
| Cena/jed. | Jednotková cena (v režimu „bez DPH" netto, v režimu „s DPH" brutto — viz [§ 15.2.6](#1526-ceny-s-dph-vs-bez-dph-brutto-netto-rezim)) |
| DPH | Sazba — `21 %`, `12 %`, `0 %` (osvobozeno), `RC` (reverse charge) |
| Celkem | Auto-počítáno (množství × cena/jed.) |
| Celkem s DPH | Cena řádku včetně DPH. Zadání respektuje aktuální režim: v režimu „bez DPH" se z brutto zpětně dopočte cena bez DPH, v režimu „s DPH" se uloží brutto — viz [§ 15.2.6](#1526-ceny-s-dph-vs-bez-dph-brutto-netto-rezim). |

### 15.3.1 Drag & drop pořadí

Levým úchytem (☰) přetáhni položky pro změnu pořadí. Pořadí se zachová
v PDF.

### 15.3.2 Smazání položky

Křížek vpravo. Pokud položka je propojená s výkazem víceprací (viz § 15.6),
smazání se zeptá, jestli i smazat výkaz.

### 15.3.3 Prodej majetku

Zaškrtávátko **Prodej majetku** v hlavičce sekce položek (jen v podvojném
účetnictví) přidá ke každému řádku našeptávač **Karty majetku** — hledá v kartách
v užívání, drobný i dlouhodobý majetek pohromadě. Samotné zaškrtávátko je jen
pomůcka pro zobrazení našeptávače; nikam se neukládá a po znovuotevření faktury
se zapne samo, když už některý řádek kartu nese. Odškrtnutí naopak vazby ze všech
řádků zruší.

Vybraná karta předvyplní popis řádku (cenu ne — pořizovací cena z karty není
prodejní cena, v nabídce ji vidíš jen jako informaci) a určí dvě věci:

| Druh karty | Výnos řádku | Co se stane po vystavení faktury |
|---|---|---|
| Drobný majetek | **642** (tržby z prodeje materiálu) | karta přejde na *prodáno* a naváže se na doklad; nic dalšího se neúčtuje, zůstatková cena je nula |
| Dlouhodobý majetek | **641** | karta se vyřadí typem *Prodej* — účetní odpis do měsíce prodeje, daňový půlodpis §26/7, zůstatková cena 541/08x a vyřazení 08x/02x |

Rozpad je **po řádcích**, takže jedna faktura může vedle sebe prodat majetek
i fakturovat službu a každý řádek sedne na svůj účet; nenavázané řádky zůstávají
na 602. Řádek s dlouhodobým majetkem navíc dostane klasifikaci DPH **1m/2m** —
prodej dlouhodobého majetku se podle §76 odst. 4 ZDPH nezapočítává do koeficientu.
Ruční volba klasifikace má přednost.

Pokud se karta uzavřít nepodaří — zavřené účetní období, nebo u dlouhodobého majetku
rok, který nemá potvrzený ani přerušený daňový odpis — **faktura se přesto vystaví
a zaúčtuje** a systém hned po vystavení upozorní, která karta zůstala v užívání
a proč (např. „rok 2025 nemá potvrzený ani přerušený daňový odpis"). Doděláš odpisy
(viz [§ 78.6](78_Majetek.md#786-hromadne-zauctovani-odpisu-roku)) a kartu vyřadíš
z její vlastní stránky.

Jednu kartu lze prodat jen jednou: pokud už ji prodal jiný doklad nebo je
vyřazená, uložení faktury skončí chybou. Storno faktury karty vrátí do užívání
(u dlouhodobého majetku jen dokud je období vyřazení otevřené — jinak zůstane
záznam v auditu a kartu vrátí účetní ručně).

## 15.4 Sumář (vpravo)

Automaticky se přepočítává:

- **Mezisoučet** — součet `množství × cena/jed.` všech položek
- **Sleva** — pokud je vyplněná (% nebo absolutní, pole vlevo)
- **Základ DPH** — po slevě
- **DPH 21 % / 12 % / 0 % (osvob.) / 0 % (RC)** — rozdělené podle sazeb v položkách
- **Celkem k úhradě** — final částka v měně faktury

> 💡 **Sazby DPH ve výběru** — `0 % (osvob.)` znamená osvobozeno od DPH,
> `0 % (RC)` znamená reverse charge (přenesená daňová povinnost). Sazby mají
> stejné procento, ale jiný legislativní význam — vybírej dle situace.

### 15.4.1 Sleva z celé faktury

Pole **Sleva z celé faktury** (v sumáři vpravo) je **procentuální sleva (0–100 %)** na
úrovni celého dokladu — typicky „sleva 10 % z celé faktury".

Jak to funguje:

- Při uložení se sleva **materializuje jako záporná položka „Sleva X %"** přímo mezi
  položkami faktury (vidíš ji v náhledu, detailu i v PDF).
- Pokud má faktura **víc sazeb DPH** (např. 21 % + 12 %), rozpadne se na zápornou
  položku **na každou sazbu zvlášť** — tím zůstává DPH po slevě účetně správně.
  U běžné faktury s jednou sazbou je to jedna položka „Sleva X %".
- Díky tomu, že je sleva reálná položka, se promítne do souhrnu, rozpisu DPH
  i do všech DPH výkazů (Kniha DPH, přiznání DPH, Kontrolní hlášení).
- Slevovou položku needituješ ručně — generuje se z pole „Sleva"; mění se jen změnou
  procenta. Při klonování faktury a při vystavení daňového dokladu k proformě se sleva
  přenáší.

> 💡 **Sleva pevnou částkou** — pevnou slevu (např. −1 500 Kč) zadáš prostě jako běžnou
> položku se zápornou cenou. Procentuální pole je pro slevu z celé faktury.

### 15.4.2 Faktura v cizí měně (EUR / USD / …) — přepočet do CZK

Pokud je faktura v jiné měně než CZK, MyÚčto po **uložení** automaticky
stáhne denní devizový kurz **z ČNB** a uloží ho na fakturu. Kurz se použije
pro přepočet **základů DPH** a **DPH** do CZK (kvůli českému účetnictví).

**Kdy se kurz načítá:**

- Po každém uložení EUR (cizí) faktury — server pošle požadavek na
  `cnb.cz` (kurzovní lístek pro `issue_date`).
- Pokud kurz pro daný den ještě není (víkend, svátek, pozdě večer):
  systém zkusí **až 7 dní zpět** a najde nejbližší dříve dostupný kurz.
- Kurz se cachuje v lokální DB — opakované otevření faktury už neposílá
  nový request.
- Pokud ČNB nedostupné a žádný kurz není v cache: použije se **poslední
  známý kurz** (z dřívější faktury) a zobrazí se ⚠️ upozornění toast po uložení.

**Co se přepočítává:**

- ✅ **Základy DPH per sazba** (21 %, 12 %, 0 %) → CZK
- ✅ **DPH per sazba** → CZK
- ✅ **Celkem bez DPH / DPH celkem / Celkem** → CZK
- ❌ **Jednotlivé řádky položek** se nepřepočítávají (zůstávají v cizí měně)

Zaokrouhlování CZK přepočtu: **HALF_UP, 2 desetinná místa, zvlášť per sazba DPH**.

**Kde je přepočet vidět:**

- **Detail faktury** — sekce „Přepočet do CZK" pod hlavními totály
- **PDF** — samostatná tabulka „Přepočet do CZK" pod sumářem (světle šedé
  podbarvení), plus drobná řádka „Kurz ČNB: X CZK / 1 EUR (datum)"
- **Editor (re-edit)** — informativní řádka pod totály s použitým kurzem

## 15.5 Tlačítka

| Tlačítko | Funkce |
|---|---|
| **Uložit koncept** | Uloží jako `draft` — zůstane v konceptech, neviditelné pro klienta |
| **Vystavit** | Přidělí variabilní symbol, vygeneruje PDF, status → `issued`. **Nelze vrátit zpět** (jen storno / dobropis). |
| **Vystavit a odeslat** | Vystaví + okamžitě pošle e-mailem klientovi |
| **Náhled PDF** | Otevře PDF v novém tabu (jen pro koncepty s vodoznakem „NÁHLED") |
| **Smazat koncept** | Jen pro `draft` — nelze smazat vystavenou |
| **Klonovat** | Vytvoří nový koncept jako kopii (kapitola 8 „Vystavit znovu") |

## 15.6 Výkaz víceprací (work report)

Pokud fakturuješ za **hodiny**, můžeš ke každé hodinové položce přidat detailní
výkaz, který se vytiskne na **2. stranu PDF**.

![Výkaz víceprací](img/09_vykaz_vicepraci.webp)

### 15.6.1 Aktivace

V editoru klikni na šedou ikonu „Přidat výkaz víceprací" v řádku položky.
Zobrazí se modal/sekce:

| Pole | Význam |
|---|---|
| Datum | Den práce |
| Popis | Co bylo děláno |
| Hodiny | Desetinné číslo (1.5, 0.25, …) |
| Sazba | Default ze zakázky, lze přepsat |
| Celkem | Auto: `hodiny × sazba` |

Přidej řádky → tlačítko **Uložit výkaz**. Suma hodin × sazba se přenese do
hlavní položky faktury (pole „Množství" + „Cena/jed.").

### 15.6.2 PDF výstup

Druhá strana PDF má formát:

```
+--------------------------------------------------+
| Výkaz víceprací — faktura 2605001               |
|                                                  |
| Datum     Popis                Hod.  Sazba   Kč |
| 03.05.    Konzultace strategie  2.0  1500   3000 |
| 04.05.    Code review           1.5  1500   2250 |
| ...                                              |
|                                                  |
| Celkem hodin: 12.5                              |
| Celkem k úhradě: 18 750 Kč                      |
+--------------------------------------------------+
```

### 15.6.3 Smazání výkazu

V editoru klik na ikonu odpojit (řetěz). Položka faktury zůstane, ale ztratí
detailní rozpis.

## 15.7 Schvalování výkazu zákazníkem

Pokud má zakázka zapnuté **„Vyžaduje schválení výkazu práce zákazníkem"** (viz
[§ 19.7](19_Zakazky.md)) a faktura obsahuje výkaz víceprací, faktura **nepůjde
vystavit**, dokud zákazník výkaz neschválí přes e-mailový odkaz. Po schválení
se faktura **automaticky vystaví a odešle**.

V detailu faktury se objeví:

- **Badge stavu schválení** v hlavičce vedle status (Neurčeno / Vyžádán /
  Schválen / Zamítnut)
- Tlačítko **„Odeslat ke schválení"** (vedle Vystavit, jen pro draft)
- Tlačítko **„Test schválení"** (v sekci Další akce — pošle test e-mail
  na adresu dodavatele bez vygenerování reálného tokenu)
- Sekce **„Schválení výkazu zákazníkem"** s detaily (datum žádosti, datum
  rozhodnutí, kdo rozhodl, případný důvod zamítnutí)
- Tlačítko **„Změnit stav"** (jen admin) — manuální override pro případy
  schválení mimo systém (telefonem, mailem mimo aplikaci)

### 15.7.1 Workflow

1. Vytvoříš **draft fakturu** s výkazem víceprací na zakázce, která vyžaduje
   schválení.
2. Klikneš **„Odeslat ke schválení"** → systém:
   - vygeneruje jednorázový bezpečný token (uložený v DB)
   - vyrenderuje samostatné PDF jen výkazu (`Vykaz-XYZ.pdf`)
   - pošle e-mail s velkým červeným tlačítkem **„✓ Schválit vícepráce"**
     na fakturační e-maily zakázky (fallback hlavní e-mail klienta)
3. Tlačítko **„Vystavit"** je nyní **zablokované** s nápovědou „Faktura
   nepůjde vystavit, dokud zákazník neschválí výkaz."
4. Zákazník v e-mailu klikne na tlačítko → otevře se **veřejná schvalovací
   stránka** (bez přihlášení), kde vidí výpis víceprací.

   ![Schvalovací stránka pro zákazníka](img/09_schvalit_vykaz_prace.webp)

5. Vybere **Schválit** nebo **Zamítnout** (s povinným důvodem). CAPTCHA
   ochrana proti botům, e-mail rozhodujícího se uloží do auditu.
6. Po schválení:
   - Stav schválení faktury se přepne na **Schválen**
   - Faktura se **automaticky vystaví** (přidělí variabilní symbol, snapshoty)
   - Faktura se **automaticky odešle** standardním procesem (na hlavní e-mail
     klienta + všechny fakturační e-maily zakázky)
7. Po zamítnutí:
   - Stav přepnut na **Zamítnut**, důvod uložen
   - Faktura zůstává jako draft — můžeš výkaz upravit a poslat znovu
     ke schválení (vygeneruje se nový token, předchozí ztrácí platnost)

### 15.7.2 Test schválení

Pro náhled e-mailu před produkčním odesláním klikni **„Test schválení"** v
sekci Další akce — e-mail půjde **na adresu aktuálního dodavatele**, link
v něm vede na placeholder, který nic neudělá. Slouží jen ke kontrole vzhledu.

### 15.7.3 Manuální změna stavu (admin)

Pokud zákazník schválil mimo systém (telefonem, e-mailem), admin může v sekci
„Schválení výkazu zákazníkem" kliknout **„Změnit stav"** a vybrat:

| Stav | Akce |
|---|---|
| **Neurčeno** | Reset — token zruší, vymaže timestamps. Vrátíš fakturu před žádost. |
| **Schválen** | Faktura se okamžitě vystaví a odešle (jako kdyby zákazník schválil přes web). |
| **Zamítnut** | Uloží zápis o zamítnutí s povinným důvodem. Faktura zůstává jako draft. |

> ⚠️ Stav „Vyžádán" v dropdownu chybí — k němu vede jen tlačítko „Odeslat ke
> schválení", které generuje token a posílá e-mail. Ručně se nedá nastavit.

### 15.7.4 Bezpečnost

- **Token je jednorázový** — po schválení/zamítnutí přestane platit. Druhý
  klik na e-mailový odkaz vrátí „Tento odkaz byl již použit nebo není platný".
- **Public stránka chráněna CAPTCHA** (Cloudflare Turnstile) — chrání proti
  botům a anonymnímu spamu.
- **Origin/CSRF check vypnutý** pro public endpointy — zákazník přijde
  z e-mailového klienta s prázdným/cizím Origin headerem. Anti-bot řeší token
  + CAPTCHA.
- **Audit log** — každá akce (`approval_requested`, `approval_approved`,
  `approval_rejected`, `approval_reset`) se zapíše do activity logu faktury
  včetně IP a user-agenta.

## 15.8 Zálohová faktura → daňový doklad

Workflow:

1. Vystavíš **zálohovou (proforma)** — variabilní symbol `9NNNNNN`, status
   `issued`, žádné DUZP.
2. Klient zaplatí — banka spáruje (nebo manuálně označíš jako `paid`).
3. Klikneš **Vystavit daňový doklad** (tlačítko v detailu zálohové).
4. Vytvoří se **daňový doklad** typu „Faktura" s automatickým **odečtem
   zaplacené zálohy** (záporná položka „Odpočet zálohy 92605001").

### 15.8.1 Zpětné propojení už existujících dokladů

Pokud už máš v systému **oba doklady samostatně** (typicky po importu) — zálohovou
i daňovou fakturu — lze je spárovat zpětně, z **kterékoli** strany:

- **V detailu daňového dokladu** (bez vazby): tlačítko **Spárovat se zálohou** → vyber
  zálohovou fakturu téhož odběratele.
- **V detailu zálohové faktury** (bez navázaného dokladu): tlačítko **Spárovat
  s daňovým dokladem** → vyber daňový doklad.

Tlačítko se nabídne jen tehdy, když u daného odběratele existuje vhodný **nespárovaný
protějšek**. Po propojení se na obou dokladech zobrazí křížový odkaz a tlačítko **Zrušit
propojení**; na daňový doklad se doplní **odečet zálohy** (`advance_paid_amount`), pokud
byl nulový — nejvýše však do výše částky dokladu (aby „K úhradě" nešlo do mínusu).
Zaplacení se nemění. Propojená záloha (proforma) zároveň vypadne z pohledávek/po splatnosti.

### 15.8.2 Zaúčtování zálohového cyklu (proforma → DDKP → vyúčtování)

Z pohledu [Účetního deníku](45_Ucetni_denik.md) fungují tři doklady zálohového cyklu
takto:

- **Zaplacení zálohové (proforma) faktury** — zaúčtuje se jako přijetí zálohy (MD 221
  banka nebo 211 pokladna / D 324 Přijaté zálohy), ne jako běžná pohledávka 311 —
  proforma totiž není daňový doklad. Viz [Banka](28_Banka.md) a [Pokladna](30_Pokladna.md).
- **Daňový doklad k přijaté platbě** (typ „Daňový doklad k platbě", viz
  [§ 14.1](14_Faktury.md#141-seznam-faktur)) — zaúčtuje se **jen za DPH** (MD 324 / D
  343), bez fiktivní pohledávky 311 a bez duplicitního výnosu 6xx (ten patří až finální
  faktuře).
- **Vyúčtovací (finální) faktura** navázaná na proformu (viz § 15.8.1) — při jejím
  zaúčtování systém automaticky doplní **zúčtovací řádek zálohy** (MD 324 / D 311) ve
  výši skutečně **přijaté** zálohy, ne nominální částky proformy — takže i částečně
  zaplacená nebo jinak vyrovnaná proforma se zúčtuje správně.

> [!NOTE]
> Mimo automatiku zůstává: proforma, ke které existuje **zároveň** daňový doklad k
> přijaté platbě i vyúčtovací faktura, a proforma navázaná na **víc než jednu**
> vyúčtovací fakturu — v obou případech by částka zúčtování zálohy nebyla jednoznačná,
> systém takový doklad automaticky nezaúčtuje a je potřeba dořešit ručním zápisem.

## 15.9 Storno vs. dobropis

Pokud zjistíš, že vystavená faktura je špatně:

- **Storno (interní)** — pouze interní označení, faktura zmizí ze statistik
  jako „neexistuje". **Klientovi se nic neposílá.** Použij, když jsi fakturu
  ještě neposlal a nechceš ji v evidenci.
- **Dobropis (opravný daňový doklad)** — vystavíš nový doklad se zápornými
  položkami, který klientovi pošleš jako oficiální opravu. Účetně správné, ale
  vyžaduje, abys měl s klientem komunikaci o tom, co a proč.

> [!NOTE]
> **Zaúčtování dobropisu.** Dobropis se do [Účetního deníku](45_Ucetni_denik.md)
> zaúčtuje automaticky stejně jako běžná faktura — systém pozná opravný doklad (typ
> Dobropis, nebo záporná celková částka) a zápis automaticky **otočí strany MD/Dal** a
> použije absolutní částku, takže výsledný zápis je čitelný (kladné částky na správné
> straně), ne matoucí záporná čísla. Funguje stejně v CZK i v cizí měně.

## 15.10 Tipy

- **Vždy uložené jako koncept** — Ctrl+S kdykoli uloží rozpracovanou fakturu.
- **Klonování zachová položky i výkaz víceprací** — datum se aktualizuje na
  dnešní, popis položky inkrementuje měsíc.
- **Sleva v procentech** se počítá z mezisoučtu **před** DPH.
- **Reverse charge** automaticky nastaví všechny položky na sazbu „RC" (0 %)
  a v PDF přidá text „Daň přiznává odběratel".
- **PDF náhled konceptu** má vodoznak „NÁHLED" přes celou stranu — klient si ho
  spletl s vystavenou fakturou by neměl.

## 15.11 Výkaz materiálu

Vedle výkazu víceprací (§ 15.6) lze ke stejné faktuře vést i **výkaz materiálu** —
samostatný rozpis spotřebovaného materiálu, který se do faktury přenese jako
**druhá souhrnná položka** „Materiál" (vedle položky „Práce"). Oba výkazy sdílí
jeden výkaz faktury, tisknou se na **2. stranu PDF** a schvalují se zákazníkem
**zároveň** (§ 15.7).

Zatímco výkaz práce počítá *hodiny × sazba*, výkaz materiálu má místo hodin
**množství + měrnou jednotku** (default „ks") a **cenu za jednotku** — zadává se
tedy ve stylu položek faktury.

### 15.11.1 Aktivace

Editor materiálu je na dvou místech (stejně jako výkaz práce):

- v **editoru faktury** sekce **„Výkaz materiálu"** → tlačítko **„Přidat výkaz
  materiálu"**,
- v **detailu / seznamu faktur** tlačítko **„Výkaz"** → v okně sekce „Výkaz
  materiálu".

Dokud výkaz nemá žádný řádek, je sekce **zabalená** — rozbalíš ji tlačítkem
„Přidat výkaz materiálu".

| Pole | Význam |
|---|---|
| Popis | Co bylo dodáno (např. „Kabel UTP cat6") |
| Množství | Desetinné číslo (počet kusů, metrů, kg, …) |
| MJ | Měrná jednotka z číselníku (default „ks") |
| Cena/MJ | Jednotková cena — **bez DPH, nebo s DPH podle režimu faktury** (viz níže) |
| Celkem | Auto: `množství × cena/MJ` |

### 15.11.2 Sazba DPH výkazu

Každý výkaz nese **jednu sazbu DPH** (sumarizuje se do jedné položky faktury):

- **Výkaz práce** — volitelná sazba, default **21 %**.
- **Výkaz materiálu** — volitelná sazba, default **12 %**.

Sazbu vybereš v záhlaví příslušné sekce. Materiál a práce tak mohou mít **různou
sazbu DPH** na jednom dokladu — DPH se na faktuře rekapituluje správně po sazbách.

### 15.11.3 Ceny s DPH / bez DPH

Cena za jednotku se zadává v **cenové konvenci dokladu** (přepínač „Ceny zadávám
včetně DPH", viz [§ 15.2.6](#1526-ceny-s-dph-vs-bez-dph-brutto-netto-rezim)):

- režim **„bez DPH"** → zadáváš cenu/MJ bez DPH (záhlaví sloupce „Cena/MJ bez DPH"),
- režim **„s DPH"** → zadáváš cenu/MJ včetně DPH (záhlaví „Cena/MJ s DPH"); DPH se
  dopočte koeficientem shora (§ 37 ZDPH).

Sazba DPH (12 %) a konvence ceny (s/bez) jsou **dvě nezávislé věci** — materiál má
12 % bez ohledu na to, jestli cenu píšeš s DPH nebo bez.

### 15.11.4 PDF a schválení

Pokud má výkaz materiálu aspoň jeden řádek, na **2. straně PDF faktury** se pod
tabulkou práce vytiskne i tabulka **Materiál** (popis, množství, MJ, cena/MJ,
celkem). Položka „Materiál" ve faktuře je **proklik** na tuto tabulku (stejně jako
položka práce). Ve schvalovacím e-mailu i na schvalovací stránce zákazník vidí
obě části a schvaluje je **najednou**.
