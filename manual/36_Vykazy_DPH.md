# 36. Výkazy DPH (DPHDP3 + KH)

MyÚčto.cz generuje XML pro EPO portál MFČR:
- **DPH přiznání (DPHDP3)** — měsíční nebo kvartální
- **Kontrolní hlášení (DPHKH1)** — měsíčně nebo kvartálně (pro OSVČ/FO kvartální plátce DPH)
- **OSS přiznání (OSSEI1)** — kvartální podklad pro EU režim One Stop Shop

Související výkazy a exporty mají v manuálu vlastní kapitoly: [Kniha DPH](37_Kniha_DPH.md)
(interní žurnál), [Souhrnné hlášení](39_Souhrnne_hlaseni.md) (EU dodání B2B) a
[Hromadný export](42_Hromadny_export.md) (ZIP balíček pro účetní). Rozdíl mezi
staženým a skutečně podaným XML vysvětluje [Archiv podání a daňová
rekonciliace](89_Archiv_podani_a_rekonciliace.md). Výkazy najdeš v menu
**Daně**, archiv podání jako poslední bod menu **Nástroje**.

OSS má samostatnou stránku **Daně → OSS přiznání**, která se objeví až po
zapnutí režimu v nastavení firmy. Zdroj dat, kontroly, sledování prahu a XML
export popisuje oddíl [OSS přiznání](#363-oss-priznani-ossei1).

## 36.1 Předpoklady před prvním podáním

V **Nastavení → Daňové nastavení** vyplň:

1. **Typ poplatníka** — FO (OSVČ) nebo PO (s.r.o., a.s.)
2. **Perioda DPH přiznání** — Měsíční nebo Kvartální
3. **Kód finančního úřadu** (např. 451 = Praha 1)
4. **Kód územního pracoviště (ÚzP)** — pokud existuje
5. **DIČ** v Identifikaci firmy (povinné)
6. Volitelně: CZ-NACE, datová schránka, sestavitel přiznání
7. Pro OSS: zapnout OSS režim, zemi identifikace, měnu podání a platnost registrace

Detailní mapping všech polí v UI na XML atributy najdeš v sekci [Pole EPO / VetaP](#362-pole-epo-vetap) níže.

> [!NOTE]
> **Právnické osoby (PO/s.r.o./a.s.) podávají Kontrolní hlášení VŽDY měsíčně** (§ 101e odst. 1 ZDPH).
> OSVČ (FO) mohou podávat KH ve stejné lhůtě jako přiznání k DPH — tj. **kvartálně**, pokud jsou kvartálním plátcem (§ 101e odst. 2).
> Přepínač Měsíčně / Kvartálně se v `Daně → Kontrolní hlášení` zobrazí jen FO.

## 36.2 Pole EPO / VetaP

Tato sekce mapuje pole z **Nastavení → Daňové nastavení** (admin only) na konkrétní
atributy v EPO XML (DPHDP3 + DPHKH1). Vyplň je všechny — bez nich EPO portál podání
odmítne nebo bude generovat formálně neúplný výkaz.

### 36.2.1 Identifikace finančního úřadu

| Pole v UI | XML atribut | Popis | Jak zjistit |
|---|---|---|---|
| **Kód finančního úřadu** | `c_ufo` | Číselný kód územního finančního orgánu | např. `451` Praha 1, `463` Jihomoravský kraj. Najdeš na posledním podaném přiznání nebo v EPO. |
| **Kód územního pracoviště** | `c_pracufo` | Konkrétní pracoviště v rámci FÚ | např. `3203` pracoviště Brno III. Volitelné, ale EPO ho někdy vyžaduje. |
| **CZ-NACE kód (`cz_nace_code`)** | `c_okec` | Hlavní podnikatelská činnost (NACE) | např. `631000` (IT poradenství). Najdeš na živnostenském listě / ARES. Fallback `631000` pokud necháš prázdné. |

### 36.2.2 Typ plátce a perioda

| Pole v UI | XML atribut | Hodnoty | Kdy použít |
|---|---|---|---|
| **Typ poplatníka** | `typ_ds` ve VetaP | `F` (FO/OSVČ) / `P` (PO/s.r.o.) | Podle právní formy. |
| **Typ plátce DPH** | `typ_platce` ve VetaD | `P` (plátce) / `I` (identifikovaná osoba) | `I` se nastaví automaticky, když je firma k rozhodnému datu vedená v historii plátcovství jako **Identifikovaná osoba** (viz [§ 35.1.4](35_Fakturujeme.md#3514-identifikovana-osoba-6g-6l-zdph)). Perioda (měsíc/kvartál) jde zvlášť atributy `mesic`/`ctvrt` dle `vat_period`. |

> 🛈 **Identifikovaná osoba**: přiznání obsahuje jen řádky samovyměření
> z přeshraničních přijatých plnění (ř. 3–6, 12–13) **bez zrcadlového odpočtu
> ř. 43** (IO nemá nárok na odpočet — daň se reálně platí, ř. 64). Podává se
> **vždy měsíčně** a jen za měsíce, kdy povinnost vznikla; tuzemské řádky
> a oddíl C se automaticky vynechají (s upozorněním v náhledu). Kontrolní
> hlášení IO nepodává; služby do EU vykazuje v souhrnném hlášení.

### 36.2.3 Sídlo / adresa

EPO rozděluje uliční adresu na tři samostatné atributy (`ulice` + `c_pop` + `c_orient`).
Naše DB tyto sloupce drží separátně (`supplier.street`, `street_number_pop`,
`street_number_orient`):

| Pole v UI | XML atribut | Popis |
|---|---|---|
| **Ulice** (`street`) | `ulice` | Název ulice bez čísla, např. `Vodičkova` |
| **Číslo popisné** (`street_number_pop`) | `c_pop` | Popisné číslo budovy, např. `1104` |
| **Číslo orientační** (`street_number_orient`) | `c_orient` | Orientační číslo, např. `36` |
| **Město** (`city`) | `naz_obce` | EPO vyžaduje VELKÝMI PÍSMENY, builder převede automaticky |
| **PSČ** (`zip`) | `psc` | Bez mezer, builder odstraní |
| **Země** (`country_id` → ISO) | `stat` | Defaultně `CZE` (Česká republika) |

> [!IMPORTANT]
> **Pro OSVČ:** EPO vyžaduje **adresu sídla podnikání**, nikoli trvalého bydliště,
> pokud jsou různé. Najdeš v živnostenském rejstříku / ARES jako *„Místo podnikání"*.

### 36.2.4 Osobní údaje (jen pro FO/OSVČ)

| Pole v UI | XML atribut | Popis |
|---|---|---|
| **Titul** | `titul` | Před jménem (Bc., Ing., Mgr., …) — nepovinné |
| **Jméno** | `jmeno` | Křestní jméno plátce |
| **Příjmení** | `prijmeni` | Příjmení plátce |

PO (právnické osoby) tyto pole nevyplňují — místo nich se použije `zkrobchjm` z firmy.

### 36.2.5 Oprávněná osoba k podpisu — POVINNÉ pro PO

Pole `opr_*` identifikují fyzickou osobu, která je u právnické osoby oprávněná
přiznání podepsat (typicky jednatel, předseda představenstva).

| Pole v UI | XML atribut | Popis |
|---|---|---|
| **Jméno oprávněné osoby** (`opr_jmeno`) | `opr_jmeno` | Křestní jméno jednatele / podepisujícího |
| **Příjmení oprávněné osoby** (`opr_prijmeni`) | `opr_prijmeni` | Příjmení |
| **Postavení** (`opr_postaveni`) | `opr_postaveni` | Funkce, typicky `jednatel`, `majitel`, `předseda představenstva` |

U FO (OSVČ) zůstávají prázdná — fallback je `jmeno` + `prijmeni`.

### 36.2.6 Sestavitel přiznání (sest_*)

Pole sestavitele jsou relevantní jen pokud **přiznání za tebe podává jiná osoba**
(účetní, daňový poradce). Pokud podáváš sám, nech prázdná — builder použije tvoje
údaje (fallback na `jmeno` + `prijmeni` + `phone`).

| Pole v UI | XML atribut | Popis |
|---|---|---|
| **Jméno sestavitele** (`sest_jmeno`) | `sest_jmeno` | Křestní jméno sestavitele |
| **Příjmení sestavitele** (`sest_prijmeni`) | `sest_prijmeni` | Příjmení |
| **Telefon sestavitele** (`sest_telefon`) | `sest_telef` | Ve formátu `+420XXXXXXXXX` |
| **E-mail sestavitele** (`sest_email`) | (interní log) | Pro audit — EPO XML ho neukládá |
| **Funkce / role** (`sest_funkce`) | (interní log) | Volně psané, např. `účetní`, `daňový poradce` |

> Pokud necháš **Příjmení sestavitele** prázdné a do Jména napíšeš celé jméno
> („Jan Novák"), builder ho do XML rozdělí podle první mezery (zpětná
> kompatibilita). Pro spolehlivost ale vyplň obě pole zvlášť.

### 36.2.7 Kontaktní údaje pro podání

| Pole v UI | XML atribut | Popis |
|---|---|---|
| **E-mail** (`email`) | `email` | Kontakt pro FÚ |
| **Telefon** (`phone`) | `c_telef` | Ve formátu `+420XXXXXXXXX` |

### 36.2.8 Postup podání na EPO portál

1. **Vygeneruj XML** v aplikaci: `Daně → DPH přiznání` (resp. KH/SH), vyber období
   a klikni **Stáhnout XML**.
2. V **Nástroje → EPO podání a archív** zkontroluj lokální validaci a otevři detail
   příslušného snapshotu.
3. Volitelně **zkontroluj v textovém editoru**:
   - **VetaD** — ověř `rok`, `mesic`/`ctvrt`, `typ_platce`, `c_okec`, `d_poddp`
     (datum podání = dnes)
   - **VetaP** — ověř `dic`, `c_ufo`, `c_pracufo`, identifikační údaje, adresu
   - **Veta1/Veta4** — ověř součty `obrat23`/`dan23` (sales), `pln23`/`odp_tuz23_nar`
     (purchase) proti seznamu faktur za období
   - **Veta6** — `dano_da` (daň k odvodu) nebo `dano_no` (nadměrný odpočet)
4. Klikni **Otevřít a podat v EPO**. Aplikace předá přesný archivovaný XML snapshot
   do předvyplněného formuláře EPO; nic se zatím samo neodešle.
5. V EPO spusť obsahové kontroly, ověř částky a potvrď **Odeslat**.
6. **Stáhni odeslané XML a potvrzení**. Přetáhni je zpět do detailu podání;
   aplikace je uloží do Dokumentů ve složce daného období a dostupné potvrzení ověří.

Stažení XML vytvoří v **Nástroje → EPO podání a archív** záznam se stavem staženo. Tento stav
neznamená, že soubor odešel správci daně. Backend rozlišuje rozpracované, vygenerované,
stažené a odeslané podání; teprve explicitní označení jako **odeslané** může sloužit
jako základ pro dodatečné přiznání a uzamknout skončené období DPH/KH. Po nahrání
podepsaného potvrzení aplikace zobrazí dostupné technické kontroly; stav podání
uživatel nastaví ručně po kontrole doručenky. Přijetí či odmítnutí je nadále nutné
sledovat podle portálu. Rozhodujícím důkazem zůstává potvrzení z EPO.

> [!TIP]
> XML soubor lze ručně doupravit v textovém editoru — struktura musí zůstat
> zachovaná, ale hodnoty atributů můžeš editovat. Užitečné pro hotfix bez
> přepočtu celé databáze.

### 36.2.9 Časté problémy

**EPO odmítne soubor s chybou „neúplná adresa"**
→ Vyplň `street_number_pop` + `street_number_orient` v Daňovém nastavení.
Pole `street` se ukládá samostatně, EPO chce všechny tři atributy.

**„Chybí kód finančního úřadu"** warning v náhledu
→ Vyplň `financial_office_code` v Daňovém nastavení. Bez něj XML neprojde XSD
validací (`c_ufo` je `use="required"`).

**„Tenant nebyl k poslednímu dni období evidovaný jako plátce DPH"**
→ Plátcovství se posuzuje **ke konci období výkazu** (historie plátcovství), ne
podle dnešního stavu. Zkontroluj v Daňovém nastavení historii plátcovství DPH —
pokud firma v daném období plátcem byla, doplň/oprav řádek historie. Vyplň DIČ.
Pokud jsi **identifikovaná osoba**, nech plátce vypnutého a zaškrtni
`Identifikovaná osoba` — přiznání se pak generuje s `typ_platce='I'`.

**Čísla v Veta1/Veta4 nesedí**
→ Zkontroluj **VAT klasifikační kódy** na položkách faktur za období. Každý řádek
musí mít `vat_classification_code` (1/2 pro sales 21/12 %, 40/41 pro purchase,
23 pro EU pořízení zboží, 5 pro tuzemský RC, atd.). Auto-defaulter to dělá při
vytvoření faktury — pro starší / importovaná data můžeš spustit backfill v
`Daně → DPH přiznání → topbar tlačítko **Přemapovat klasifikace**`.

**„Aplikace generuje `typ_platce='P'`, ale jsem čtvrtletní plátce"**
→ V Daňovém nastavení změň `vat_period` na `quarterly`. Pak v UI DPH přiznání
toggluj na **Kvartálně** a vyber kvartál.

**„Nevím, jaký je můj kód FÚ a pracoviště"**
→ Podívej se na poslední DPH přiznání, které jsi nahrál na EPO — kódy jsou v
sekci VetaD/VetaP. Alternativně zavolej na svůj FÚ nebo se podívej na
[seznam FÚ](https://www.financnisprava.cz/cs/financni-sprava/organy-financni-spravy/uzemni-pracoviste).

**„OKÉČ kód mi vyjde fallback `631000`, ale moje činnost je jiná"**
→ Vyplň `cz_nace_code` v Daňovém nastavení. Číslo najdeš na živnostenském listě
nebo v ARES. Builder ho normalizuje (odstraní `CZ-NACE ` prefix, padne na 6
číslic).

## 36.3 OSS přiznání (OSSEI1)

**Cesta: `Daně → OSS přiznání`**. Stránka připravuje podklad a XML formuláře
`OSSEI1` za zvolený kalendářní kvartál. Objeví se až po zapnutí OSS v daňovém
nastavení firmy.

Hotové XML se **podává v aplikaci MOSS/OSS na Daňovém portálu**, do které se
musíš přihlásit — obecnou cestou EPO to nejde, viz
[§ 40.8.5](40_OSS.md#4095-kde-se-oss-priznani-podava).

Do přiznání vstupují jednotlivé OSS řádky vydaných faktur, jejichž datum
zdanitelného plnění patří do vybraného kvartálu. Aplikace je seskupí podle státu
spotřeby, typu plnění, typu sazby a sazby DPH a oddělí běžná plnění od oprav
vztahujících se k dřívějším obdobím. Výpočet vychází z řádkových základů a daně
v daňovém ledgeru, nikoli jen z celkové částky hlavičky faktury.

**OSS řádky jsou vyřazené z českého přiznání k DPH, z kontrolního hlášení
i z Knihy DPH.** Zařazení do OSS se odvozuje automaticky ve všech vstupních
kanálech — ruční označování řádků není potřeba.

> **Celý režim OSS popisuje samostatná kapitola [40. Režim OSS (One Stop
> Shop)](40_OSS.md)**: nastavení a registrace, odvození řádku, plnění k ručnímu
> posouzení, hromadná úprava, doložka na dokladu, účtování na 345.100, sledování
> prahu 10 000 EUR, přepočet kurzem ECB, opravy minulých období, XML `OSSEI1`,
> archiv podání, rekonciliace a evidence § 110f.

### 36.3.1 Co se z OSS promítne do přiznání k DPH

Přiznání k DPH hlásí varování se seznamem dokladů u řádků, které zůstaly **mimo
OSS s příznakem „k ručnímu posouzení"** — vstupují na **ř. 1 a 2**, aniž to kdo
potvrdil. Zakládají je kanály běžící bez lidského zásahu (pravidelná fakturace,
synchronizace z iDokladu a Fakturoidu, čtení PDF, vlastní integrace přes API).
Projdi je dřív, než přiznání podáš — najdeš je filtrem **Místo plnění (OSS)**
v seznamu faktur, volbou **Nejisté — v tuzemsku**
([§ 14.1.1](14_Faktury.md#nejiste-misto-plneni-oss)). Druhou skupinu, tedy řádky
zařazené do OSS s týmž otazníkem, hlásí náhled OSS podání; rozdíl mezi nimi
vysvětluje [§ 40.4](40_OSS.md#405-plneni-k-rucnimu-posouzeni).

Účtování OSS daně na vlastní účet **345.100** je důvod, proč **zůstatek 343 jde
s přiznáním k DPH srovnat** — podrobně
[§ 40.7](40_OSS.md#408-uctovani-oss-dane).

## 36.4 DPH přiznání (DPHDP3)

### 36.4.1 Cesta: `Daně → DPH přiznání`

#### Topbar

- **Toggle Měsíčně / Kvartálně** — override podle `supplier.vat_period`
- **Month / Year picker** — pro měsíční; **Q1/Q2/Q3/Q4 picker** pro kvartální
- **Typ podání** — Řádné / Opravné / Dodatečné (viz [níže](#typ-podani-radne-opravne-dodatecne))
- **Stáhnout XML** — vytvoří XML formuláře DPHDP3 pro EPO portál a po dokončení
  stažení otevře **Nástroje → EPO podání a archív**. Stejně se chová export
  kontrolního a souhrnného hlášení.

#### Typ podání — řádné, opravné, dodatečné

Vedle výběru období nabízí stránka selector **Typ podání**:

| Typ | Kdy použít | Jak se počítá |
|---|---|---|
| **Řádné** (výchozí) | Standardní podání v řádné lhůtě | Jako dosud — plný přepočet za období |
| **Opravné** (§ 138 daňového řádu) | Nahrazuje už podané řádné přiznání, dokud za dané období ještě neuplynula lhůta pro podání | Počítá se **znovu celé** (ne rozdíl) — stejná logika jako u řádného, jen jiný typ podání v XML |
| **Dodatečné** (§ 141 daňového řádu) | Podání **po lhůtě** — zjistil(a) jsi, že se u už podané daně za dané období musí něco opravit | Vykazuje **jen ROZDÍL** oproti poslední známé dani — ne absolutní částky |

Po výběru **Dodatečné** se zobrazí povinné pole **Datum zjištění** (kdy jsi zjistil(a)
důvod pro opravu) — bez jeho vyplnění se náhled nespočítá.

> [!WARNING]
> Dodatečné přiznání nevykazuje absolutní částky za období, ale jen **rozdíl proti
> poslednímu archivnímu XML, které bylo v systému explicitně označeno jako odeslané**
> (řádné, případně opravné). Pouhé stažení XML základnu nevytvoří.
> Není to volba aplikace, ale zákonný požadavek (§ 141 daňového řádu) — proto se
> **datum zjištění** vyžaduje a bez něj se dodatečné přiznání nedá spočítat. Pokud jsi
> za dané období ještě nepodal(a) žádné řádné ani opravné přiznání, dodatečné
> přiznání **nejde spočítat vůbec** — systém to odmítne srozumitelnou chybou, protože
> rozdíl nemá vůči čemu počítat (chybí základna).

> [!NOTE]
> Pokud jsi za dané období už podal(a) jedno dodatečné přiznání a zjistíš, že je
> potřeba opravit ještě jednou, **druhé (a každé další) dodatečné přiznání počítá
> rozdíl kumulativně** — tedy proti stavu **po předchozím dodatečném přiznání**, ne
> proti úplně původnímu řádnému. Nehrozí tak, že by se stejná už jednou opravená
> částka vykázala podruhé.

> [!TIP]
> U dodatečného přiznání se nad tabulkami zobrazí panel s **poslední známou daní**
> (stav před opravou) a **rozdílem** (řádek 66 přiznání) — přesně tak, jak to bude
> vypadat v podaném XML.

Volba **Dodatečné/opravné** (oprava už podaného dodatečného přiznání) se v selectoru
**vůbec nenabízí**. Jde o právně složitější případ — takové podání předchozí
dodatečné přiznání **nahrazuje**, nesčítá se s ním, a poslední známou daň by u něj
nebylo možné bezpečně dopočítat bez rizika, že se stejná částka vykáže dvakrát. Pro
tento případ je potřeba přiznání sestavit ručně ve spolupráci s daňovým poradcem.

#### Fronta „doklady změněné po podání"

Jakmile bylo přiznání za dané období aspoň jednou v archivu označeno jako odeslané,
může se na stránce objevit
žlutá sekce **Doklady změněné po podání** — obsahuje doklady (vydané i přijaté
faktury, daňové pokladní doklady), které svým DPH-rozhodným datem (viz [Které doklady
se zahrnou](#ktere-doklady-se-zahrnou)) spadají do naposledy podaného období, ale byly
**vytvořené nebo upravené AŽ PO tom**, co bylo přiznání za dané období naposledy
podáno. Snapshot podání zachytí také doklad, který byl po podání stornován nebo mu bylo
DUZP přesunuto mimo období. U každého dokladu vidíš jeho číslo, částku a datum poslední
změny. U starších podání bez snapshotu aplikace zobrazí upozornění, že je potřeba porovnat
podání s knihou DPH ručně.

Křížová kontrola současně neblokujícím upozorněním vyjmenuje koncepty DDKP a finálních
dokladů z proformy i přijaté platby proformy, ke kterým daňový doklad ještě nevznikl.
Před podáním je dokonči nebo účetně ověř — daňová povinnost vzniká přijetím úplaty.

> [!TIP]
> Tahle fronta je jen **podklad pro rozhodnutí** — nic sama o sobě nevynucuje. Pokud
> se v ní doklad objeví, zvaž, jestli je rozdíl významný natolik, že je potřeba podat
> **dodatečné přiznání** (viz výše), nebo jestli stačí ho promítnout až do dalšího
> řádného období.

> [!NOTE]
> Sekce se zobrazí jen tehdy, když za dané období už bylo přiznání v archivu
> **označeno jako odeslané** (řádné, opravné nebo dodatečné) — u období, které
> ještě vůbec podané nebylo, fronta
> nedává smysl a nezobrazí se.

#### Křížová kontrola s kontrolním hlášením, souhrnným hlášením a účtem 343

Při každém načtení náhledu aplikace automaticky porovná chystané přiznání se třemi
zdroji, které si finanční úřad páruje strojově — jakýkoli nesoulad mezi nimi typicky
znamená výzvu nebo kontrolu:

| Kontrola | Co se porovnává |
|---|---|
| DPHDP3 ř. 1+2 ↔ KH | Tuzemská zdanitelná plnění na výstupu vs. sekce **A.4 + A.5** kontrolního hlášení |
| DPHDP3 ř. 10+11 ↔ KH | Tuzemský přijatý reverse charge vs. sekce **B.1** kontrolního hlášení |
| DPHDP3 ř. 20+21 ↔ SH | Dodání zboží/služeb do JČS vs. **souhrnné hlášení** |
| Obrat účtu 343 ↔ vlastní daň | Zaúčtovaný obrat účtu **343** (podvojné účetnictví) vs. vlastní daň / nadměrný odpočet z přiznání |

Pokud vše sedí, na stránce se nic nezobrazí. Pokud kontrola najde rozdíl, nad
rekapitulačními kartami se objeví červená sekce s:
- popisem, čeho se rozdíl týká (např. „DPHDP3 ř.1+2 ↔ KH A.4 + A.5"),
- konkrétní částkou z obou stran a rozdílem v Kč,
- pokud lze rozdíl přiřadit ke konkrétním dokladům, seznamem dokladů (číslo dokladu
  a částka na obou stranách) — u rozdílu proti souhrnnému hlášení i s vysvětlením
  pravděpodobné příčiny (chybějící DIČ odběratele, plnění zařazené jako EU, ale na
  tuzemské/ne-EU zemi, apod.).

U kontroly účtu 343 rozpis navíc u každého rozdílového dokladu vysvětlí, zda jde
o časový posun odpočtu podle § 73 ZDPH, odlišnou částku, chybějící řádek 343 nebo
zápis bez protějšku v přiznání. Typický časový posun vznikne, když je předpis
zaúčtovaný k DUZP na konci měsíce, ale přijatý doklad dorazí až v následujícím
měsíci. Pokud celý rozdíl vysvětlují pouze tyto časové posuny, zobrazí se neutrální
informace s čísly dokladů a přiznání můžete stáhnout bez potvrzování nesouladu.
Nevysvětlený zbytek nad toleranci zůstává červený a blokující.

> [!WARNING]
> Tlačítko **Stáhnout XML** se při nalezeném rozdílu úplně nezablokuje, ale
> vyžádá **potvrzení** — zobrazí se dialog s upozorněním, že se přiznání rozchází
> s kontrolním/souhrnným hlášením nebo obratem účtu 343 a že finanční úřad páruje
> podání strojově. Teprve po potvrzení se XML skutečně stáhne. Tahle vědomá volba
> (že jsi o rozdílu věděl/a a přesto jsi stáhl/a) se spolu s celým rozpisem rozdílu
> zaloguje do auditní stopy.

### 36.4.2 Převod DPH na zúčtovací účet

Po skončení zdaňovacího období vzniká interní doklad **„převod DPH"**, který přesune
výstupní daň z `343.200` a vstupní daň z `343.100` na zúčtovací účet `343.900`. Po něm
drží `343.900` přesně tu částku, kterou finančnímu úřadu dlužíte nebo kterou od něj
čekáte — a tu pak uzavře platba z banky.

**Doklad se řídí přiznáním, ne kalendářem.** Založí a přepočítá se ve chvíli, kdy
přiznání **podáte**, takže hlavní kniha ukazuje přesně to, co odešlo na úřad. Dodatečné
i opravné přiznání ho přepočítají znovu. Sestavení návrhu přiznání už existující doklad
osvěží, ale nový nezaloží — návrh není podání.

To řeší situaci, kvůli které dřívější řešení na kalendáři selhávalo: když doklad za dané
období dorazí až po termínu, změní přiznání — a s ním i převod.

> [!NOTE]
> Když se v už vyrovnaném období něco změní, doklad přestane odpovídat a v přehledu DPH
> se objeví štítek **Neaktuální** spolu s uzávěrkovou kontrolou. Přepočítat ho můžete
> ručně tlačítkem v agendě DPH — před zápisem uvidíte náhled s výstupní daní, vstupní
> daní a výsledným zůstatkem. Rozdíly do 1 Kč se ignorují: roky zaúčtované ručně
> v celých korunách by jinak hlásily nález trvale.
>
> Do **uzavřeného nebo zamčeného** období se doklad nikdy nepřepíše ani nesmaže — jen
> se ohlásí nález. Aktualizace jinak probíhá přepisem původního dokladu, nikoli stornem.

> [!NOTE]
> Kontrola obratu účtu 343 se automaticky **přeskočí** (zobrazí se jen informativní
> šedá poznámka, ne červené varování a nic neblokuje), pokud v období existují ještě
> nezaúčtované DPH doklady — v tom případě rozdíl neznamená chybu v přiznání, jen že
> se zatím nezaúčtovalo vše. Jakmile doklady zaúčtujete, kontrola při dalším načtení
> náhledu proběhne znovu.

> [!TIP]
> Kontrola se počítá nad stejnými reálnými výkazy, jaké se skutečně podávají (tentýž
> účetní deník, tytéž buildery jako KH a SH), takže nikdy neukáže jiný rozdíl, než
> jaký by nastal při skutečném podání. Pokud se sekce objeví, oprav podklad (doplň
> DIČ, oprav klasifikaci, zaúčtuj chybějící doklad) a znovu načti náhled — po opravě
> sekce zmizí.

#### 4 KPI karty

- **DPH na výstupu** — z vydaných faktur (řádky 1-29)
- **DPH na vstupu** — z přijatých faktur (řádky 40+)
- **Daň k odvodu** NEBO **Nadměrný odpočet** (color coded)
- **Termín podání** — 25. den následujícího měsíce (po kvartálu) s **countdown** (kolik dní zbývá, červené pokud po termínu)

#### Trend graf

12 měsíců DPH na výstupu / vstupu / net due (rozdíl). Pro rychlou orientaci, jak se podání vyvíjí.

#### Tabulky DPH na výstupu (řádky 1-29) a vstupu (40+)

Per řádek: kód, popis, základ, DPH. Hodnoty se počítají agregací `invoice_items` / `purchase_invoice_items` per `vat_classification_code`.

### 36.4.3 Jak se DPHDP3 generuje a co zahrnuje

Tato sekce přesně popisuje, podle jakých pravidel se přiznání sestavuje — užitečné
pro kontrolu proti seznamu faktur i pro účetní.

#### Zdroje dat a granularita

- **DPH na výstupu (ř. 1-26)** se počítá z položek vystavených faktur a řádků DPH
  zaúčtovaných příjmových pokladních daňových dokladů.
- **DPH na vstupu / nárok na odpočet (ř. 40-47)** z položek přijatých faktur,
  jejich řádkových alokací a řádků DPH zaúčtovaných výdajových pokladních dokladů.
- **Samovyměřená daň** u reverse charge a pořízení z EU se objevuje na **obou
  stranách** (výstup ř. 3-13 + odpočet ř. 43).
- Agreguje se **per řádek faktury** (`*_items`), ne per faktura — kvůli kurzu cizí
  měny a možnosti per-řádek klasifikace.

Tato řádková evidence je společná pro daňovou evidenci i podvojné účetnictví.
Změna účetního režimu proto sama nemění výsledek DPHDP3, KH ani SH. Datum úhrady
ovlivňuje daň z příjmů v daňové evidenci, nikoli období DPH.

#### Které doklady se zahrnou

| Filtr | Pravidlo |
|---|---|
| **Období** | **Vystavené** se řadí podle **DUZP** (`COALESCE(tax_date, issue_date)`) — daň na výstupu vzniká k datu plnění. **Přijaté tuzemské** se řadí podle **nejpozdějšího ze tří dat**: DUZP, datum vystavení, a **datum přijetí** (`received_at`) tehdy, když ho **zadal(a) uživatel** — nárok na odpočet nelze uplatnit dříve, než plátce doklad fyzicky drží (§ 73 odst. 1 písm. a ZDPH). Typicky se to projeví u dokladu se zpětným DUZP, který dorazil až později — faktura pak spadá do měsíce, kdy jsi ji fyzicky/e-mailem dostal(a), ne do měsíce DUZP/vystavení. U **importovaných** dokladů (AI extrakce, ISDOC, iDoklad/Fakturoid, bankovní avízo, scan inboxu) se datum přijetí do řazení nepočítá — import ho plní datem zpracování, ne skutečným přijetím, takže by zařazení jen zkreslilo; použije se pozdější z DUZP a data vystavení. Rozhoduje **skutečná změna pole**, ne to, že doklad někdo otevřel a uložil: přeuložení vytěženého dokladu beze změny data přijetí ho ponechá importním, takže upravený i neupravený doklad se stejnými daty skončí ve stejném období. **Přijaté zahraniční reverse charge** (příznak RC + dodavatel mimo CZ — pořízení zboží z JČS, služby z EU/3. země, dovoz) se řadí **podle DUZP** — povinnost přiznat daň (ř. 3–13) vzniká k DUZP bez ohledu na to, kdy doklad dorazil (§ 25 odst. 1, § 24), a pozdní doklad neblokuje ani zrcadlový odpočet ř. 43 (§ 73 odst. 1 písm. b — nárok lze prokázat jiným způsobem). Tuzemský RC (kód 5) zůstává konzervativně na pozdějším z dat. (Zobrazené *Datum plnění* dál nese skutečné DUZP, mění se jen příslušnost k období.) Doklad bez vyplněného DUZP nevypadne. |
| **Stav** | Vylučují se `draft` a `cancelled`. U vystavených navíc `proforma` (zálohová faktura není daňový doklad). |
| **Klasifikace** | Řádek se zařadí podle `vat_classification_code` (item-level override → header → auto-default podle sazby + RC + směru). Řádek bez výsledného kódu se do přiznání nedostane. |

#### Přepočet měny

Základ i daň se vždy převedou na **CZK** kurzem faktury (`exchange_rate`); u CZK
faktur je kurz 1. Chybějící kurz u cizoměnového daňového plnění je chyba podkladu.
Evidence drží haléře, ale jednotlivé atributy a řádky DPHDP3 se v XML zaokrouhlují
na celé Kč běžným matematickým zaokrouhlením. Dodatečné přiznání počítá rozdíl až
mezi takto zaokrouhlenými hodnotami nové a poslední odeslané verze; prostý rozdíl
haléřových součtů proto nemusí být totožný.

#### Mapování na řádky přiznání

| Řádek | Co obsahuje | Typický kód |
|---|---|---|
| **1 / 2** | Tuzemská zdanitelná plnění na výstupu 21 % / 12 % | 1 / 2 |
| **3 / 4** | Pořízení zboží z JČS (samovyměření) 21 % / 12 % | 23 |
| **5 / 6** | Přijetí služby z EU | 24 |
| **7 / 8** | Dovoz zboží ze 3. země | 25 |
| **10 / 11** | Tuzemský reverse charge (příjemce) | 5 |
| **12 / 13** | Přijetí služby ze 3. země | (custom) |
| **20-26** (oddíl C) | Dodání zboží do EU, vývoz, služby do JČS — **osvobozená plnění s nárokem na odpočet, jen základ bez daně** | 20 / 22 / 26 |
| **40 / 41** | Nárok na odpočet — tuzemsko 21 % / 12 % (doklad s **Krácený §76** míří na tentýž řádek, jen do sloupce „Krácený odpočet" — viz [níže](#kraceny-odpocet-76-koeficient)) | 40 / 41 |
| **43** | Nárok na odpočet u samovyměřené daně (zrcadlo ř. 3-13) | (secondary) |
| **47** | Hodnota pořízeného dlouhodobého majetku — **doplňující údaj** k ř. 40-45 | flag majetek |
| **52 / 53** | Krácení odpočtu koeficientem (§76) — zálohové (52, každé období) a roční vypořádací dorovnání (53, jen poslední období roku) | koef_p20_nov / koef_p20_vypor |

> [!NOTE]
> **Oddíl C (ř. 20-26)** — dodání do EU (`dod_zb`), vývoz (`pln_vyvoz`), služby do
> JČS (`pln_sluzby`) a další — se generuje do elementu `Veta2`. Jde o osvobozená
> plnění, na DPHDP3 se uvádí **jen základ** (žádná daň), ale ovlivňují vypořádací
> koeficient (ř. 51-53).

> [!NOTE]
> **Identifikovaná osoba** vyplňuje z celé tabulky jen **ř. 3-6 a 12-13**.
> Zrcadlový odpočet **ř. 43** a navázaný **ř. 47** se vyřadí potichu — to je
> pointa režimu, IO nárok na odpočet nemá a samovyměřená daň jí zůstává jako
> skutečný výdaj. Ř. 7/8 (dovoz — daň vybírá celní úřad) a ř. 10/11 (tuzemský
> RC § 92a — jen mezi plátci) IO věcně nemá. Cokoli dalšího, co z klasifikací
> vyjde (tuzemské ř. 1/2, oddíl C, odpočty ř. 40+), se vynechá **s upozorněním
> v náhledu**, ať je vidět, co a proč vypadlo. Kvartální volba se ignoruje —
> IO podává vždy měsíčně. Podrobnosti [§ 35.1.4](35_Fakturujeme.md#3514-identifikovana-osoba-6g-6l-zdph).

#### Krácený odpočet § 76 (koeficient)

Přijaté faktury s **Nárok na odpočet DPH = Krácený (§76)** (viz
[Přijaté faktury § 23.2.4](23_Prijate_faktury.md#2324-danova-uznatelnost-a-narok-na-odpocet))
jsou doklady se **společnými vstupy** — používanými zároveň pro plnění s nárokem na
odpočet i pro plnění osvobozená bez nároku podle § 51 (typicky nájem, energie, účetní
služby u firem, které mají vedle zdanitelných příjmů i osvobozené — pronájem, finanční
nebo zdravotní služby). Na rozdíl od poměrného odpočtu §75 se procento nezadává na
jednotlivém dokladu — kráti se **jedním koeficientem za celou firmu a rok**.

**Jak se to projeví v přiznání:**

- Doklad se zařadí do sloupce **„Krácený odpočet"** na řádcích **40/41/42** (místo
  sloupce „V plné výši") — daň na dokladu je **plná**, per doklad se nic nekrátí.
- **Řádek 46** (Odpočet daně celkem) se rozpadá na dvě čísla — „V plné výši" (řádky
  40-45 mimo krácený sloupec) a „Krácený odpočet" (součet kráceného sloupce řádků
  40-42).
- **Řádek 52** — krácený odpočet ř. 46 vynásobený **zálohovým koeficientem** platným
  pro daný rok. Vykazuje se **v každém zdaňovacím období** roku (měsíc i kvartál).
- **Řádek 53** — jen v **posledním zdaňovacím období roku** (prosinec u měsíčních
  plátců, Q4 u kvartálních): systém dopočítá **vypořádací koeficient** ze skutečných
  dat **celého roku** a doplní rozdíl mezi ročním nárokem (roční krácený odpočet ×
  vypořádací koeficient) a součtem, který už byl v jednotlivých obdobích uplatněn na
  ř. 52. Rozdíl může vyjít kladně i záporně.
- **Řádek 63** (Odpočet daně celkem) = ř. 46 „V plné výši" + ř. 52 + ř. 53.

> [!NOTE]
> **Zálohový vs. vypořádací koeficient.** Zálohový koeficient (§ 76 odst. 6) se
> používá **v průběhu roku** — na začátku roku ho buď zadáš ručně (kvalifikovaný
> odhad), nebo se automaticky **převezme** z vypořádacího koeficientu předchozího,
> už vypořádaného roku. Vypořádací koeficient (§ 76 odst. 7) se počítá až **ze
> skutečných dat celého roku**, takže je zpravidla přesnější než odhad použitý
> během roku — proto poslední období roku obsahuje dorovnání na ř. 53. Oba
> koeficienty se **zaokrouhlují nahoru na celé procento** (§ 76 odst. 5); vyjde-li
> hodnota **95 % a víc**, zaokrouhlí se rovnou na **100 %** (plný nárok).

**Nastavení koeficientu.** Bez nastaveného zálohového koeficientu pro daný rok
nejde doklad s kráceným nárokem §76 **ani zaúčtovat, ani zahrnout do přiznání** —
systém vrátí srozumitelnou chybu s výzvou koeficient nejdřív nastavit. Nastavení
zálohového koeficientu i roční vypořádání je **dostupné jen přes
administrátorské API** — obdobně jako [zámek účtování k datu](45_Ucetni_denik.md#459-zamek-uctovani-k-datu),
samostatná obrazovka v administraci pro tuto akci není:

| Endpoint | Kdo smí | Co dělá |
|---|---|---|
| `GET /api/reports/vat-coefficient?year=2026` | admin, účetní, jen pro čtení | Vrátí nastavený zálohový koeficient pro daný rok (případně automaticky převzatý z vypořádání předchozího roku) a vypořádací koeficient, pokud je rok už vypořádaný. |
| `PUT /api/reports/vat-coefficient` | admin, účetní | Nastaví/změní zálohový koeficient (celé %, 0–100) pro daný rok. |
| `POST /api/reports/vat-coefficient/settle` | jen admin | Spočítá a **uloží** vypořádací koeficient za celý (uzavřený) rok ze skutečných ročních dat. Náhled ani stažení přiznání koeficient nikdy automaticky neuloží — vypořádání je vždy samostatný, vědomý krok. |

**Plnění vyloučená z koeficientu.** Pro transakce podle **§ 76 odst. 4** zvol
v klasifikaci DPH odpovídající kód: `1m`/`2m` pro zdaněný prodej dlouhodobého majetku,
nebo `3m` pro příležitostné osvobozené finanční či nemovitostní plnění. Doklad zůstane
na běžném řádku 1/2 nebo 50 a současně se vykáže na řádku 51 ve správném sloupci;
vypořádací koeficient ho odečte z čitatele nebo jmenovatele.

**Omezení, o kterých je dobré vědět:**
- Samotné **účetní zaúčtování ročního vypořádání** (na účty 548/343, u firem
  s analytikami DPH proti **343.100**) systém **nedělá automaticky** — jen spočte
  a zobrazí částku na ř. 53 v přiznání; do
  [Účetního deníku](45_Ucetni_denik.md) ji zapiš ručním zápisem. Nezaměňuj to
  s [měsíčním zúčtováním DPH](81_Ucetni_osnova.md#8133-mesicni-zuctovani-dph),
  které automatické je — to jen převádí obrat období na 343.900, roční
  vypořádání koeficientu neřeší.
- Kombinace **reverse charge** (samovyměření) a **Krácený (§76)** na jednom dokladu
  **není podporovaná** (řádek 43, kam se zrcadlí odpočet u samovyměření, nemá krácený
  protějšek) — takový doklad systém odmítne zaúčtovat i zahrnout do přiznání
  srozumitelnou chybou; zaúčtuj ho a vykaž ručně.

#### Samovyměření daně u reverse charge

U reverse charge (faktura s `reverse_charge=1` **nebo** klasifikační kód s příznakem
`is_reverse_charge` — kódy 5 a 23) vendor fakturuje **bez DPH**. Aplikace daň
**dopočítá** ze základu: `daň_CZK = základ_CZK × sazba / 100`. Tatáž částka se uvede
dvakrát:
- na **výstupu** (ř. 3 u zboží z EU, ř. 10 u tuzemského RC, ř. 5/12 u služeb),
- na **vstupu** jako odpočet na **ř. 43** (přes `dphdp3_line_secondary`).

Net dopad na vlastní daň je tedy nulový (daň = odpočet), pokud máš plný nárok.

#### Vlastní daň vs. nadměrný odpočet

`vlastní daň = DPH na výstupu − nárok na odpočet`. Kladná hodnota = daň k úhradě FÚ;
záporná = nadměrný odpočet. Atribut `trans` ve `VetaD` se nastaví `A` (vznikla
povinnost) / `N` podle znaménka.

### 36.4.4 Jak fungují VAT klasifikační kódy

Každá faktura (nebo její řádek) má `vat_classification_code` (např. "1", "40", "5", "20"). Tento kód určuje na který **řádek DPH přiznání** položka patří.

**Vystavené doklady (sale):**

| Kód | Význam | Řádek DPHDP3 | KH / SH |
|---|---|---|---|
| **1** / **2** | Tuzemské plnění 21 % / 12 % | 1 / 2 | KH A.4 nebo A.5 |
| **1m** / **2m** | Prodej dlouhodobého majetku 21 % / 12 % — vyloučeno z koeficientu § 76 | 1 / 2 | KH A.4 / A.5 |
| **1c** / **2c** | Cestovní služba § 89 — **přirážka**, 21 % / 12 % | 1 / 2 | KH A.4 s `kod_rezim_pl=1` |
| **1p** / **2p** | Použité zboží § 90 — **přirážka**, 21 % / 12 % | 1 / 2 | KH A.4 s `kod_rezim_pl=2` |
| **3** | Osvobozené plnění bez nároku na odpočet (§ 51) | 50 | — |
| **3m** | Příležitostné osvobozené plnění vyloučené z koeficientu § 76 odst. 4 | 50 | — |
| **20** | Dodání zboží do jiného členského státu | 20 | SH kód plnění 0 |
| **22** | Poskytnutí služby do JČS (§ 9 odst. 1) | 21 | SH kód plnění 3 |
| **31** | Dodání zboží prostřední osobou při třístranném obchodu (§ 17) | 31 | SH kód plnění 2 |
| **23n** | Dodání nového dopravního prostředku neregistrované osobě (§ 19) | 23 | — |
| **24z** | Zasílání zboží do JČS (§ 8) — mimo režim OSS | 24 | — |
| **25s** | Tuzemský přenos — **stavební a montážní práce § 92e** (dodavatel) | 25 | KH A.1, `kod_pred_pl=4` |
| **25s5** | Tuzemský přenos — **odpad a šrot § 92c** (dodavatel) | 25 | KH A.1, `kod_pred_pl=5` |
| **25s3** | Tuzemský přenos — **dodání nemovité věci § 92d** (dodavatel) | 25 | KH A.1, `kod_pred_pl=3` |
| **26** | Vývoz **zboží** do 3. země (§ 66) | 22 | — |
| **26s** | **Služba** s místem plnění mimo tuzemsko — 3. země (§ 9 odst. 1) | 26 | — |

**Přijaté doklady (purchase):**

| Kód | Význam | Řádek DPHDP3 | KH |
|---|---|---|---|
| **40** / **41** | Tuzemské plnění 21 % / 12 % s nárokem na odpočet | 40 / 41 | KH B.2 nebo B.3 |
| **42** | Tuzemské plnění **bez nároku** na odpočet | — (mimo přiznání) | — |
| **5** | Tuzemský přenos — **stavební a montážní práce § 92e** (příjemce) | 10 + odpočet 43 | KH B.1, `kod_pred_pl=4` |
| **5c** | Tuzemský přenos — **odpad a šrot § 92c** (příjemce) | 10 + 43 | KH B.1, `kod_pred_pl=5` |
| **5d** | Tuzemský přenos — **dodání nemovité věci § 92d** (příjemce) | 10 + 43 | KH B.1, `kod_pred_pl=3` |
| **23** | Pořízení zboží z JČS (§ 25) | 3 + 43 | KH A.2 |
| **24e** | Přijetí služby z JČS (§ 9 odst. 1) | 5 + 43 | KH A.2 |
| **24** | Přijetí služby ze 3. země / od osoby neusazené v tuzemsku | 12 + 43 | KH A.2 |
| **25** | Dovoz zboží ze 3. země | 7 | — |
| **30** | Pořízení zboží prostřední osobou při třístranném obchodu (§ 17) | 30 | — |

> Kódy s příponou (`1m`, `25s5`, `26s`, …) se od základní varianty liší **jediným atributem** —
> vyloučením z koeficientu, kódem předmětu plnění, zvláštním režimem, nebo řádkem přiznání.
> Číselník je editovatelný: v `Nastavení → Číselníky → Klasifikace DPH` si můžeš přidat vlastní
> kód, včetně kódu předmětu plnění s písmenným sufixem (`1a`, `3a`) z číselníku MFČR.

### 36.4.5 Auto-default klasifikace

Pokud na fakturu ani řádek kód nevybereš, doplní ho systém sám. Rozhoduje:

- **sazba** na řádku (`vat_rate_snapshot`),
- **země protistrany** (tuzemsko / EU / 3. země),
- **reverse charge** na dokladu,
- u přijatých dokladů navíc **plátcovství tvé firmy** k datu dokladu a **povaha plnění**
  (poplatek orgánu veřejné moci — viz níž),
- u nulové sazby do zahraničí **měrná jednotka** položky: časová (h, den, měsíc) znamená službu,
  fyzikální míra nebo balení (kg, l, m², paleta) zboží; `ks` je neutrální a rozhodne statistický
  default (služba).

U **přijatých** dokladů přiřazuje kód jediné místo — ukládání řádků. Hlavička dokladu ho jen
**přebírá z dominantního řádku**, sama nikdy nerozhoduje; ručně zvolený kód na hlavičce zůstává.
Výkazy čtou kód z řádku a teprve pak z hlavičky.

Hodnoty se berou z číselníku `vat_classifications` k **DUZP dokladu**, takže po změně sazby
dostanou starší doklady starou klasifikaci a nové novou.

#### Kdy se kód ZÁMĚRNĚ nepřiřadí

Prázdná klasifikace je někdy správný výsledek, ne opomenutí:

- **Nulová sazba v tuzemsku.** Nula sama nerozlišuje osvobození bez nároku (§ 51), plnění mimo
  předmět daně, přeúčtování nákladů, náhradu škody ani smluvní pokutu. Přiznání na neklasifikovaný
  nulový řádek upozorní a zahrne ho, až mu vybereš kód. (Dřív se všechno tohle bralo jako
  osvobození § 51, což nafukovalo ř. 50 a snižovalo krácený odpočet podle koeficientu § 76.)
- **Poplatek orgánu veřejné moci** — soudní a správní poplatky, kolky, evropský platební rozkaz,
  `Gerichtskosten`, `court fee`. Orgán při výkonu veřejné správy není osobou povinnou k dani
  (§ 5 odst. 4 ZDPH), takže plnění není předmětem daně **ani u zahraničního soudu či úřadu**:
  nesamovyměřuje se podle § 9 odst. 1 a doklad nepatří do přiznání ani do KH. Aplikace ho pozná
  z popisu položky, nechá bez klasifikace a řekne to upozorněním v detailu dokladu. Účetně jde
  o běžný náklad (typicky 538 – Ostatní daně a poplatky).
- **Sazba, kterou český číselník nezná** (např. německých 19 %). Cizí daň nelze uplatnit jako
  odpočet, takže se nepřiřadí ani `40`, ani `41`. Doklad dostane upozornění, že bez zásahu
  nevstoupí do přiznání ani do KH — zkontroluj sazby a rozhodni, jestli jde o náklad včetně cizí
  daně, nebo o špatně vytěžený doklad.

> **Automatika je pomůcka, ne rozhodnutí.** Daňové zařazení dokladu je odpovědnost uživatele nebo
> jeho účetní; návrh je vždy přepsatelný a u nejednoznačných případů raději nenavrhneme nic, než
> abychom potichu vyrobili daňovou povinnost nebo odpočet.

#### Co automatika u přijatých dokladů udělá

| Dodavatel | Sazba | Reverse charge | Výsledek |
|---|---|---|---|
| tuzemský | 21 % / 12 % | ne | `40` / `41` |
| tuzemský | 0 % | ne | bez kódu |
| tuzemský, firma je **plátce** | libovolná | ano | `5` (§ 92a, ř. 10 + KH B.1) |
| tuzemský, firma je **neplátce / identifikovaná osoba** | libovolná | ano | tuzemský přenos se **nepřiřadí** — § 92a funguje jen mezi plátci |
| z EU | 0 % | jedno | `24e` (přijetí služby, ř. 5) |
| z EU | 21 % | ano | `23` (pořízení zboží z JČS, ř. 3) |
| ze 3. země | 0 %, nebo 21 % + RC | jedno | `24` (služba od neusazené osoby, ř. 12) |
| jakýkoli | 0 % | poplatek úřadu | bez kódu (mimo předmět daně) |

Zahraniční samovyměření podle § 108 se týká **i identifikované osoby** — kvůli němu ten režim
existuje. Nárok na odpočet ale nemá, takže se jí zrcadlový odpočet na ř. 43 nepřizná.

#### Řádek přiznání se řídí sazbou, ne jen kódem

Když si vybereš kód pro základní sazbu, ale řádek nese sníženou (nebo obráceně), použije se
**dvojče kódu odpovídající skutečné sazbě** (1↔2, 40↔41, u samovyměření 3↔4, 5↔6, 7↔8, 10↔11,
12↔13). Bez toho by přiznání vykázalo základ v 21% sloupci, zatímco kontrolní hlášení bucketuje
podle skutečné sazby do 12% sloupce, a oba výkazy by se rozešly. Vlastní přemapování kódu
v číselníku (per firma) tím dotčené není — přepíná se jen při skutečném rozporu sazeb.

### 36.4.6 Override per řádek nebo header

V editoru faktury (vystavené i přijaté) je sekce **Klasifikace** s VAT picker dropdown. Můžeš:
- Nechat prázdné → auto-default
- Vybrat konkrétní kód → manual override (např. specifický kód pro export)

### 36.4.7 Reverse charge v cizí měně

Pro RC plnění (typicky `reverse_charge=true` na fakturě, kódy 5 / 23 / 24)
v cizí měně:

1. **Kurz** se aplikuje na základ DPH (`pii.total_without_vat × invoice.exchange_rate`).
2. **Samovyměřená daň** se dopočte ze sazby (`základ_CZK × vat_rate / 100`),
   protože vendor vystavil bez DPH.
3. **Odpočet** se uvede na ř. 43 jako mirror primary řádku (3 / 10 / 12 — viz
   `dphdp3_line_secondary` v `vat_classifications`).

Příklad: faktura z DE, 1 000 € @ kurz 25, vat_classification_code='23' →
ř. 3 (`p_zb23=25000`, `dan_pzb23=5250`) + ř. 43 (`odp_rezim=25000`,
`odp_rez_nar=5250`) + KH sekce A.2.

### 36.4.8 Pořízení dlouhodobého majetku

Checkbox **„Pořízení dlouhodobého majetku"** v editoru přijaté faktury označí
doklad za majetek vymezený v § 4 odst. 4 písm. c) (vozidlo, stroj). Pro
mixed doklady lze flag nastavit i per řádek.

Hodnota se na DPHDP3 uvede:
- **ř. 40** (nebo 41/42/43 podle klasifikace) — běžný odpočet
- **ř. 47** (atribut `nar_maj`) — doplňující údaj o hodnotě majetku

Daň se v součtech ř. 46 neduplikuje (ř. 47 je informativní). V [Knize DPH](37_Kniha_DPH.md)
je samostatná sekce **47.047** se sumací.

## 36.5 Kontrolní hlášení (DPHKH1)

### 36.5.1 Cesta: `Daně → Kontrolní hlášení`

Právnická osoba podává KH měsíčně; fyzická osoba podle svého zdaňovacího období
měsíčně nebo čtvrtletně. Identifikovaná osoba KH nepodává. KH obsahuje sekce:

- **A.1** — Plnění v režimu přenesené daňové povinnosti (dodavatel). Doklad, který nese víc
  režimů § 92 najednou (např. stavební práce a odpad na jedné faktuře), dá **větu za každý kód
  předmětu plnění** — tak to vyžaduje XSD i párování s protistranou. Kód předmětu plnění nese
  klasifikace řádku (`25s` = 4 stavební práce, `25s5` = 5 odpad a šrot, `25s3` = 3 nemovitá věc).
- **A.2** — Pořízení zboží z jiného členského státu a přijaté služby od osoby
  neusazené v tuzemsku podle § 24, včetně třetích zemí. Typicky používá kód `23`,
  `24` nebo jeho zahraniční variantu. Atributy: `k_stat`, `vatid_dod`, `c_evid_dd`, `dppd`,
  `zakl_dane1/dan1`, `zakl_dane2/dan2`. Daň je samovyměřená — Kniha DPH ji
  i u řádků RC počítá z `základ × sazba/100`.
- **A.4** — Tuzemská plnění s DPH **nad 10 000 Kč** (individuálně)
- **A.5** — Tuzemská plnění s DPH **do 10 000 Kč** (sumace)
- **B.1** — Přenesená daňová povinnost (odběratel). Rozpad na věty per kód předmětu plnění
  platí stejně jako u A.1; kódy nesou klasifikace `5`, `5c`, `5d`.
- **B.2** — Přijatá tuzemská plnění nad 10 000 Kč
- **B.3** — Přijatá tuzemská plnění do 10 000 Kč (sumace)

UI ukazuje **count řádků per sekce** + deadline countdown.

### 36.5.2 Typ podání — řádné, opravné, následné

Analogicky k DPH přiznání nabízí stránka **Kontrolní hlášení** selector **Typ podání**:

| Typ | Kdy použít |
|---|---|
| **Řádné** (výchozí) | Standardní měsíční (resp. kvartální u FO) podání |
| **Řádné/opravné** (§ 101f odst. 1) | Nahrazuje už podané řádné kontrolní hlášení, dokud za dané období ještě neuplynula lhůta pro podání |
| **Následné** (§ 101f odst. 2) | Podání **po lhůtě** — oprava už podaného kontrolního hlášení |
| **Následné/opravné** | Oprava už podaného následného kontrolního hlášení |

Po výběru **Následné** nebo **Následné/opravné** se zobrazí dvě volitelná pole:

- **Datum zjištění** — kdy jsi zjistil(a), že je potřeba podat opravu.
- **Č. j. výzvy** — číslo jednací výzvy finančního úřadu, pokud kontrolní hlášení
  reaguje na doručenou výzvu správce daně. Na doručenou výzvu má účetní jen **5
  pracovních dnů** na reakci — pole vyplň, pokud podání na výzvu navazuje.

> [!WARNING]
> Na rozdíl od dodatečného DPH přiznání se **následné kontrolní hlášení vždy počítá
> jako úplné** — obsahuje všechny údaje za dané období znovu (sekce A.1-A.5, B.1-B.3),
> ne jen rozdíl oproti dřívějšímu podání. To vyžaduje přímo zákon — u kontrolního
> hlášení se rozdílový způsob (na rozdíl od dodatečného DPH přiznání) nepoužívá.

### 36.5.3 Pravidla zařazení do sekcí

Aby v reálně podaném KH seděly sekce, řídí se zařazení dokladů těmito pravidly
(odpovídají metodice GFŘ a opravám z reportu #35):

| Pravidlo | Detail |
|---|---|
| **Období** | `COALESCE(tax_date, issue_date)` v daném měsíci — DUZP, fallback datum vystavení. Doklad **bez DUZP** se zařadí podle data vystavení (nevypadne). |
| **Stav** | Bez `draft` a `cancelled` (storno je součást auditní stopy, do KH nepatří). |
| **Práh 10 000 Kč** | Porovnává se **`abs()` celkové částky vč. DPH** — záporný dobropis nad limit (např. −25 000 Kč) jde tedy správně do A.4/B.2 jednotlivě, ne do sumace. |
| **DIČ protistrany** | Do A.4/B.2 patří jen plnění **nad limit a s DIČ** plátce. Plnění **bez DIČ** (B2C, doklad od neplátce) jde do sumace **A.5/B.3 bez ohledu na částku**. |
| **Jen zdanitelná plnění** | Do A.4/A.5/B.2/B.3 patří jen plnění se **zdanitelným základem 21/12 %**. Osvobozená, EU dodání, vývoz a reverse charge (kde je uložená sazba 0) se sem **nezařazují** (netvoří nulové řádky). |

#### Kam který doklad patří

- **A.1** (vystavené RC) — faktury v režimu přenesené daňové povinnosti (dodavatel).
  Detekce: klasifikační kód s `is_reverse_charge` **nebo** příznak `reverse_charge`
  na faktuře. Vyžaduje DIČ odběratele.
- **A.2** (zahraniční samovyměření) — přijaté faktury s klasifikací
  `kh_section = 'A.2'` (typicky pořízení zboží kód 23, služby z EU a služby od
  osoby neusazené ve třetí zemi). Daň je **samovyměřená** (počítá se ze základu × sazba).
  **Nezařadí se zároveň do B.2** ani do B.1.
- **A.4 / A.5** (vystavená tuzemská) — viz pravidla v tabulce výše.
- **B.1** (přijaté RC) — **tuzemský** reverse charge (kód 5 / `is_reverse_charge`).
  Pořízení z JČS (A.2) sem **nepatří**, i když je také samovyměřené.
- **B.2 / B.3** (přijatá tuzemská) — analogicky k A.4/A.5. Vylučují se doklady,
  které patří do A.2 / B.1 / reverse charge (aby se neduplikovaly).

> [!NOTE]
> **Rekapitulace (VetaC)** sčítá obraty napříč sekcemi. `pln_rez_pren` odpovídá
> A.1, `rez_pren23` a `rez_pren5` odpovídají B.1 v základní a snížené sazbě.

#### Atributy A.2 (zahraniční samovyměření)

`k_stat` (země dodavatele), `vatid_dod` (DIČ bez prefixu země), `c_evid_dd` (číslo
dokladu dodavatele), `dppd` (datum povinnosti přiznat daň), `zakl_dane1/dan1` (21 %),
`zakl_dane2/dan2` (12 %). Daň se dopočítá ze základu × sazba/100, protože vendor
fakturuje bez DPH.

Oddíl A.2 zahrnuje také přijaté služby od osoby neusazené v tuzemsku ze třetí
země (kód `24`, ř. 12/13 přiznání). U takového dodavatele může zůstat VAT ID
i kód členského státu prázdný.

### 36.5.4 Zvláštní režimy a opravy nedobytných pohledávek

V `Systém → Číselníky → Klasifikace DPH` lze u vlastního kódu nastavit:

- režim KH `0` (běžný), `1` (cestovní služba § 89) nebo `2` (použité zboží § 90),
- příznak `P` pro opravu nedobytné pohledávky dle § 46 / § 74b.

Hodnoty se přenesou do `VetaA4.kod_rezim_pl` a `VetaA4/VetaB2.zdph_44`.
U vystaveného dobropisu, který snižuje daň, přiznání zároveň připomene ověření
data doručení opravného daňového dokladu podle § 42 ZDPH.

Příznak `zdph_44` na klasifikačním kódu označuje zvláštní režim v KH. Samotnou
korekci odpočtu dlužníka podle § 74b připravuje a eviduje samostatná stránka
**Daně → Oprava odpočtu §74b**, popsaná níže.

## 36.6 Oprava odpočtu §74b

**Cesta: `Daně → Oprava odpočtu §74b`**.

Stránka pro zvolený měsíc nejprve vytvoří **náhled nanečisto**. Vybírá tuzemské
přijaté zdanitelné doklady s uplatněným odpočtem, vylučuje reverse charge
a stornované doklady a porovnává je s evidovanými úhradami. Do úhrady vstupuje
záloha, bankovní párování i pokladna; stav **Zaplaceno** je autoritativní signál
plné úhrady i u starších dokladů bez detailní historie plateb.

Korekce vzniká po uplynutí šesti kalendářních měsíců následujících po měsíci
splatnosti. Backend počítá:

`cílová korekce = původně uplatněný odpočet × neuhrazená část / částka s DPH`

Původně uplatněný odpočet respektuje plný, poměrný, krácený i nulový nárok.
Od cíle se odečte čistá korekce zaevidovaná v dřívějších obdobích. Výsledná
delta je buď nové **snížení odpočtu**, nebo **obnovení odpočtu** po další úhradě.
Nulový rozdíl se znovu nezapisuje.

Příklad: z odpočtu 2 100 Kč zůstává 40 % závazku neuhrazeno; cílová korekce je
840 Kč. Po úhradě na 10 % neuhrazeného zbytku klesne cíl na 210 Kč a rozdíl
630 Kč se zobrazí jako obnovení odpočtu.

Tlačítko **Zaevidovat období** je vědomý zápis do daňového ledgeru a vyžaduje
oprávnění finalizovat výkazy. Teprve zaevidované nenulové pohyby se promítnou do:

- DPHDP3 na řádky 40/41 a do související hodnoty řádku 34,
- kontrolního hlášení B.2 s příznakem `zdph_44 = P`,
- Knihy DPH.

Náhled nic nezapisuje ani neúčtuje do deníku. Před zaevidováním ověř splatnost,
skutečné úhrady, původní nárok na odpočet a aktuální právní podmínky § 74b.

## 36.7 Opravy DPH (§43, §79 a §79a)

**Cesta: `Daně → Opravy DPH (§43, §79)`**. Stránka vede dvě samostatné evidence;
zápis vyžaduje oprávnění finalizovat výkazy, čtení běžné oprávnění k reportům.

### 36.7.1 §43 — oprava výše daně

§43 se používá při chybně určené **výši daně**, například při nesprávné sazbě
nebo výpočtu. Není to dobropis podle §42: oprava patří zpětně do období
původního plnění a vstupuje do **dodatečného přiznání**.

U záznamu vybereš vydanou nebo přijatou fakturu, období původního plnění,
sazbovou skupinu, změnu základu a daně, datum doručení opravného dokladu, jeho
číslo a povinný důvod. Změna DPH nesmí být nulová. Backend hlídá také lhůtu pro
stanovení daně: standardně tři roky od 25. dne po konci původního zdaňovacího
období; u čtvrtletního plátce se konec posuzuje za celé čtvrtletí.

Evidované částky se podle sazby přičtou k řádkům 1 nebo 2 DPHDP3 za období
původního plnění. Evidence sama nevytváří účetní zápis a nepřepočítává zdrojovou
fakturu.

### 36.7.2 §79 a §79a — registrace a zrušení registrace

Tato záložka eviduje odpočet při registraci a jeho snížení při zrušení
registrace. Položky zadává účetní ručně, protože systém z dokladu nepozná, zda
zásoba nebo majetek k rozhodnému dni stále tvoří obchodní majetek.

Zadává se druh operace, popis, datum pořízení, rozhodný den, druh majetku
(zásoba nebo dlouhodobý majetek), DPH na vstupu a u dlouhodobého majetku
pětiletá nebo desetiletá lhůta. Rozhodný den určuje období vykázání.

- při registraci vstupuje nárok **kladně**, pokud bylo plnění pořízeno nejvýše
  12 měsíců před vznikem plátcovství,
- při zrušení registrace se u zásob vrací celý odpočet **záporně**,
- u dlouhodobého majetku se vrací jen podíl za roky zbývající z pěti- nebo
  desetileté lhůty; po jejím uplynutí je částka nulová.

Součet platných položek se promítá na řádek 45 DPHDP3, zaokrouhlený na celé Kč.
Ani tato evidence sama neúčtuje do účetního deníku.

## 36.8 Co kontrola podání neumí

Křížové kontroly porovnávají sestavy vypočtené z aktuálních dat aplikace. Neumějí
načíst skutečně odeslané DPHDP3 nebo DPHKH1 z portálu a porovnat je řádek po řádku.
Pokud účetní XML na portálu ručně upraví, ulož jeho finální kopii a potvrzení mimo
aplikaci a při další opravě ji porovnej ručně. Archivní snapshot je věrným obrazem
souboru vytvořeného aplikací, nikoli automatickým potvrzením, že právě tento soubor
byl přijat finanční správou.

## 36.9 Změna sazby DPH s budoucí platností

Pokud se sazba změní, postupuj:

1. **Číselníky → Sazby DPH:**
   - u dosavadní sazby nastav **Platí do** na den před účinností změny,
   - založ novou sazbu s novým procentem a datem **Platí od**.
2. **Číselníky → Klasifikace DPH:**
   - u odpovídající klasifikace uprav sazbu, nebo ponech samostatné klasifikace
     pro dosavadní a novou sazbu.
3. **Vystavené doklady** si ponechají sazbu uloženou na svých řádcích.
4. **Doklady s DUZP v nové účinnosti** použijí platnou sazbu a odpovídající výchozí
   klasifikaci.

## 36.10 Časté chyby

### 36.10.1 "Chybí kód finančního úřadu"
→ Doplň v Nastavení → Daňové nastavení.

### 36.10.2 "Faktura nemá VAT klasifikační kód"
→ Auto-default by ho měl přiřadit. Pokud ne, znamená to, že VAT sazba na řádku nemá v `vat_classifications` defaultní kód. Buď přidej kód v Codebooks, nebo vyber manual v editoru.

### 36.10.3 "DIČ klienta není ve formátu CZxxxxxxxx"
→ Pro KH XML potřebuje DIČ být čisté číslo (bez prefixu CZ). Systém to ořezává automaticky. Pokud klient **nemá DIČ**, doklad se zařadí do **sumační sekce A.5 (resp. B.3)** bez ohledu na částku — do A.4/B.2, kde je DIČ povinné, se nedostane. Pokud doklad do A.4/B.2 patřit má (protistrana je plátce), doplň jí DIČ.

### 36.10.4 "Dodatečné přiznání vyžaduje datum zjištění důvodů"
→ U typu podání **Dodatečné** vyplň pole **Datum zjištění** — bez něj systém rozdíl
proti poslední známé dani nedokáže spočítat (§ 141 daňového řádu vyžaduje toto datum
jako součást přiznání).

### 36.10.5 "Pro dané období neexistuje dřívější řádné/opravné přiznání"
→ Dodatečné přiznání se vždy počítá jako **rozdíl** proti poslední známé dani — pokud
za dané období ještě nebylo podáno žádné řádné ani opravné přiznání, nemá se vůči
čemu rozdíl počítat. Nejdřív podej za dané období **řádné** (nebo opravné) přiznání,
teprve pak lze dodatečně opravovat.

### 36.10.6 "Opravné dodatečné přiznání (druh E) zatím není podporováno"
→ Volba **Dodatečné/opravné** se v selectoru schválně vůbec nenabízí (viz [Typ podání
— DPH přiznání](#typ-podani-radne-opravne-dodatecne)) — jde o právně složitější
případ (nahrazuje předchozí dodatečné přiznání, ne že by k němu jen přičítal rozdíl).
Sestav ho ručně ve spolupráci s daňovým poradcem.

## 36.11 Podpora pro daňového poradce

Pokud XML zpracovává externí účetní:
1. Vyplň v Nastavení **Sestavitel přiznání** (jméno, funkce, telefon, email)
2. Doporučujeme: u poradce ověřit XML před prvním podáním
3. Před odesláním použij kontroly v otevřeném formuláři EPO. Serverový parametr
   `test=1` se týká podepsaného ZAREP podání a není součástí asistovaného předání.
