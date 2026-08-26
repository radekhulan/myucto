# 21. Importy (Pohoda XML, ISDOC/ISDOCX, PDF/A-3, iDoklad API, Fakturoid API)

Pokud máš historické vydané nebo přijaté faktury v jiném systému (Pohoda,
iDoklad, Fakturoid, Superfaktura nebo jiný software podporující ISDOC), můžeš
je do MyÚčto **naimportovat** — nemusíš je opisovat ručně.

Existují dvě cesty:

1. **Soubor upload** (Pohoda XML, ISDOC, ISDOCX, PDF/A-3 s embedded ISDOC) —
   vydané v § 21.1–21.7, přijaté v § 21.13.
2. **Přímý API import z iDoklad / Fakturoid** (OAuth2 credentials + background
   job) — § 21.8–21.12.

> Směr určuje položka menu: **Prodej → Import** očekává aktuální firmu jako
> dodavatele, **Nákup → Import** jako odběratele. Kontrola IČO brání tomu, aby se
> do firmy omylem importoval cizí doklad.

## 21.1 Obrazovka importů

V hlavním menu jsou dvě samostatné stránky: **Prodej → Import** pro vydané
faktury a **Nákup → Import** pro přijaté. Obě položky vidí jen administrátor.

Formulář:

- **Soubory** — přetáhni nebo klikni pro výběr. Akceptuje:
  - `.xml` (Pohoda dataPack)
  - `.isdoc` (ISDOC 5.x nebo 6.x)
  - `.isdocx` (ISDOC Package — ZIP balíček se strukturovaným ISDOC + PDF; viz § 21.6)
  - `.pdf` (PDF/A-3 s embedded ISDOC nebo ISDOCX přílohou — viz § 21.6)
  - `.zip` s libovolným počtem těchto souborů uvnitř
- **Importovat** — odešle a vrátí report (kolik vytvořeno / přeskočeno / chyba).

## 21.2 Co se založí

Pro každou fakturu v souboru:

| Entita | Logika |
|---|---|
| **Klient** | Lookup po IČO. Pokud neexistuje, načteme adresu z **ARES** (preferenčně), fallback na adresu z XML. Vznikne nový klient. |
| **Zakázka** | Když má faktura `číslo zakázky` (ISDOC `OrderReference/ID`, Pohoda `numberOrder`), přiřadí se k zakázce s tím číslem (vytvoří se, pokud chybí). Pokud nemá číslo zakázky, ale klient má v importovaném balíku **více různých e-mailů**, vytvoří se per-email zakázka s názvem `{Firma} – {email}`. Jinak `bez zakázky`. |
| **Faktura** | Přepíše se do `invoices` se zachovaným původním varsymbolem. Položky, sazby DPH, kurz, měna se převezmou. Snapshoty (klient/dodavatel/banka) se zafixují z aktuálních dat. |

## 21.3 Stav (paid vs issued) — pravidlo 30 dní

Aby ses nemusel po importu zabývat starými fakturami:

- **Datum splatnosti starší než 30 dní** → faktura se uloží jako **Zaplacená**
  (`paid_at` = DUZP nebo datum vystavení). Předpoklad: starý doklad už dávno
  zaplacený.
- **Datum splatnosti v posledních 30 dnech (nebo v budoucnu)** → faktura se
  uloží jako **Vystavená**. Můžeš platbu spárovat standardním flow přes
  bankovní výpis nebo ručně označit jako zaplacenou.

## 21.4 Co se přeskočí

- **Cizí dodavatel** — celý soubor se přeskočí, pokud IČO dodavatele v souboru
  neodpovídá aktuálnímu dodavateli v aplikaci. (Hláška v reportu.)
- **Duplicita** — pokud doklad **stejného druhu** s daným varsymbolem u tohoto
  dodavatele už existuje, přeskočí se. V reportu se zobrazí důvod a id
  existující faktury.
- **Doklad bez položek** — doklad, který v souboru nemá jedinou položku, se
  **nezaloží** a v reportu skončí jako chyba. Vznikl by doklad na nulu, který
  v seznamu vypadá jako naimportovaný, ale do žádného výkazu nepřispěje.
  Doplň položky ve zdrojovém systému a doklad naimportuj znovu.

## 21.5 21.4a Dobropis se stejným variabilním symbolem jako faktura

Většina systémů vystavuje opravný daňový doklad (dobropis) s **týmž variabilním
symbolem**, jaký má opravovaná faktura, aby vratka odešla na stejný symbol.
Dva doklady pod jedním symbolem ale u jednoho dodavatele vést nejde — variabilní
symbol je jediné, podle čeho se páruje platba z banky.

Import to řeší takto:

- Dobropis se naimportuje pod **variabilním symbolem odvozeným z čísla dokladu**
  (např. `D262200015`). V reportu je o tom poznámka — pod symbolem ze souboru
  doklad v aplikaci **nedohledáš** a platba se na něj sama nenapáruje.
- Vazba na opravovaný doklad se přesto zachová: dobropis se naváže na původní
  fakturu (v detailu dokladu je vidět odkaz), pokud je faktura u téhož odběratele
  v systému. Když tam není, řekne to poznámka v reportu a vazbu doplníš ručně.
- Když se symbol z čísla dokladu odvodit nedá (soubor číslo neuvádí) nebo je
  i ten obsazený, doklad se přeskočí s hláškou, ať mu zadáš jiný variabilní
  symbol a naimportuješ ho znovu.

## 21.6 21.4b Zahraniční doklady a režim OSS

Import vydaných faktur umí sám poznat plnění v [režimu OSS](40_OSS.md) a vyplnit
na položce příznak OSS, zemi spotřeby, typ sazby i typ plnění. Nemusíš je
proklikávat ručně.

### 21.6.1 Než spustíš import

1. **Spusť databázové migrace** (`php api/bin/migrate.php`). Bez číselníku
   [sazeb států OSS](92_Nastaveni.md#9214-9212b-sazby-statu-oss) se import zahraničních
   dokladů **vůbec nerozběhne** — raději neudělá nic, než aby doklady zařadil naslepo.
2. **Zkontroluj zemi u zahraničních sazeb** v `Nastavení → Číselníky → DPH sazby`.
   Formulář zemi předvyplňuje na `CZ`, takže sazba `PL-23` bývá založená se zemí `CZ`.
   Import ji v takovém stavu nepřijme.
3. **Zapni OSS** na kartě `Nastavení → Daně a účetnictví → Režim OSS (One Stop Shop)`
   a vyplň platnost registrace. Doklady
   s datem plnění před začátkem registrace zůstanou tuzemské — to je správně.

### 21.6.2 Jak se import rozhoduje

Rozhodovací pravidlo je společné všem vstupním kanálům a popisuje ho
[§ 40.3](40_OSS.md#404-jak-vznika-oss-radek). Ve zkratce: **autoritou pro místo
plnění je číselník sazeb států OSS, ne tvoje tabulka DPH sazeb** — a do tuzemského
přiznání smí jen řádek, u kterého číselník potvrdí, že sazba v zemi dodavatele
k datu plnění opravdu platí. Každá jiná odpověď znamená buď zařazení do OSS, nebo
odmítnutí dokladu s hláškou, co doplnit.

Specifika importu ze souboru:

- **Procento sazby se nikdy nedosazuje odhadem.** Bere se v tomto pořadí: hodnota
  `percentVAT` z Pohoda XML nebo `Percent` z ISDOC, číselná hodnota v atributu sazby,
  dopočet z rekapitulace v témže souboru (daň ÷ základ), a teprve u tuzemského odběratele
  převod slovního označení sazby („základní", „snížená") na sazbu platnou k datu plnění.
  Když ani to nevyjde, je sazba neznámá a rozhodne pravidlo výše.
- **Sazba se páruje na zemi a platnost k datu**, ne na nejbližší procento. Když se
  nenajde, odmítne se **celý doklad** — doklad s vynechaným řádkem má špatné součty.
  Hláška řekne, u které sazby a na jaký stát opravit zemi.
- **Země spotřeby se bere z odběratele na importovaném dokladu**, ne z uložené karty
  klienta a ne z měny. Doklad v eurech pro slovenského odběratele jde do SK.
- **Odmítnutý řádek znamená, že se doklad nevytvoří.** Zdroj pravdy je v souboru,
  takže po opravě stačí import zopakovat — už naimportované doklady se přeskočí
  jako duplicity.
- **Nejednoznačnou sazbu import zařadí do OSS** a označí k ručnímu posouzení, ne
  naopak. Proč právě tímto směrem, vysvětluje
  [§ 40.4.1](40_OSS.md#4051-dva-stavy-ktere-vypadaji-podobne).
- **Doklad, který se rozpadne** mezi OSS podání a tuzemské přiznání, se neodmítá
  (smíšená faktura umí vzniknout legitimně), ale hlásí se zvlášť a jeho řádky se
  označí k posouzení.
- **Původní období u dobropisů import nedoplňuje** — v souboru není z čeho ho
  poznat — a na každý takový doklad upozorní. Dokud období nedoplníš (`RRRRQn`
  v editoru položky), vykáže se oprava do běžného čtvrtletí.

### 21.6.3 Co po importu zkontrolovat

1. **Typ plnění zboží / služba** u položek, kde soubor jednotku neuvedl — dosadí se
   výchozí „služba" a v podání to vyjde jako `S`, kdežto u zboží tam patří `G`.
2. **Řádky k ručnímu posouzení.**
3. **OSS řádky bez typu sazby** — bez typu sazby se řádek do podání nedostane.
4. **Náhled OSS podání** před stažením XML — poslední místo, kde se chyba dá chytit.

Kolik čeho vzniklo, říká souhrn importu ([§ 21.5](#217-report)); souhrn ale po
zavření stránky zmizí, kdežto filtr **Místo plnění (OSS)** v seznamu faktur ne.
Všechny tři první body má [hromadná úprava OSS](40_OSS.md#406-hromadna-editace-oss)
jako samostatný výběr položek — nemusíš je hledat po jednom.

> [!TIP]
> Doklady s prázdným příznakem OSS vyžadují kontrolu, protože jejich zahraniční
> daň může být vykázaná v českém přiznání.
> Než podáš přiznání za období, do kterého import spadl, projdi si zahraniční
> doklady v tom období a ověř, že v přiznání k DPH nefigurují.

## 21.7 Report

Po importu vidíš tabulku:

| Sloupec | Význam |
|---|---|
| Soubor | Cesta v balíku (název ZIPu / interní cesta) |
| Stav | `vytvořeno` / `přeskočeno` / `chyba` |
| Var. symbol | Z faktury |
| Detail | Link na vytvořenou fakturu, badge `paid`/`issued`, štítky `+ klient` / `+ zakázka` (pokud něco vzniklo). U přeskočených/chybných: důvod. |

Doklad může projít a přesto mít poznámku — typicky když se **nahradil variabilní symbol**
(§ 21.4a) nebo když se **odvodilo něco, co v souboru nebylo**. Poznámka se u dokladu
objeví jen jednou, i když se týká víc položek, aby dvacetipoložková faktura nevyrobila
dvacet stejných vět.

Nad tabulkou je souhrn za celý běh. U zahraničních dokladů v něm najdeš:

| Údaj | Význam |
|---|---|
| **položek v režimu OSS** | Kolik řádků se zařadilo do OSS |
| **položek bez typu sazby OSS** | Řádky, které se do podání nedostanou, dokud typ sazby nedoplníš |
| **položek k ručnímu posouzení** | Řádky s nejistým místem plnění (viz § 21.4b) |
| **dobropisů bez období opravy** | Opravné doklady, kterým chybí původní OSS čtvrtletí |
| **dokladů s nahrazeným variabilním symbolem** | Kolik dokladů dostalo symbol odvozený z čísla dokladu |
| **dokladů s varováním** | Kolik dokladů prošlo, ale nese poznámku ke kontrole |

Jednotlivé doklady mají v seznamu odpovídající štítky (`OSS: n`, `neurčený typ sazby`,
`k ručnímu posouzení`, `dobropis bez období opravy`, `VS nahrazen`), takže se dá
z tisícovky řádků rychle vyfiltrovat to, co potřebuje pozornost.

Souhrn existuje právě proto, aby se při tisícovce dokladů dalo přečíst jedno číslo místo
tisícovky hlášek.

## 21.8 PDF/A-3 a ISDOCX import (embedded i samostatný ISDOC)

Většina českých fakturačních systémů (**iDoklad**, **Fakturoid**, **Superfaktura**,
**Pohoda**, **MyÚčto**) dnes vkládá ISDOC XML přímo do PDF dokumentu jako
přílohu — viz standard **PDF/A-3** + ISDOC spec. Pokud máš v ruce jen PDF
faktury (typicky to, co ti přišlo emailem od dodavatele), můžeš ho importovat
přímo — MyÚčto z něj vytáhne embedded `*.isdoc` přílohu a importuje stejně,
jako kdybys nahrál samostatný `.isdoc` soubor.

**ISDOCX balíček (ISDOC Package).** Některé systémy fakturu nevkládají do PDF,
ale balí strukturovaný ISDOC i čitelné PDF do jednoho **ZIP archivu s příponou
`.isdocx`** (uvnitř `manifest.xml`, vlastní `*.isdoc` a `*.pdf`). MyÚčto takový
balíček **rozbalí**, vytáhne z něj ISDOC a naimportuje ho stejně jako samostatný
`.isdoc` — **deterministicky, zdarma a bez AI** — a čitelné PDF z balíčku navíc
archivuje pro náhled v detailu faktury. Funguje to jak při nahrání samotného
`.isdocx`, tak když je `.isdocx` přílohou uvnitř PDF/A-3. Hlavní ISDOC se v
balíčku určí podle `manifest.xml` (`<maindocument>`), s fallbackem na `.isdoc`
v kořeni archivu (balíčky bez manifestu).

**Jak to poznáš, jestli PDF má embedded ISDOC?**

- Otevři PDF v jakémkoli prohlížeči, klikni na ikonu **přílohy / sponky**.
  Pokud uvidíš soubor typu `*.isdoc` (často `invoice.isdoc`, ale třeba iDoklad
  ho pojmenuje `Vydaná faktura - 20230005-invoice.isdoc`), je to ono.
- V `Adobe Reader` najdeš přílohu v levém panelu pod ikonou kancelářské sponky.
- Můžeš to taky zjistit příkazem `pdfdetach -list <soubor>.pdf` (z balíku
  `poppler-utils`), nebo jakýmkoli PDF prohlížečem podporujícím přílohy.

**Co když PDF přílohu nemá?**

Pak ho **nelze automaticky importovat** — pure PDF nemá strukturovaná data
faktury, jen vizuální layout. Import vyhodí čitelnou chybu „PDF neobsahuje
ISDOC přílohu". V tom případě:

- Buď v původním systému (iDoklad, Pohoda …) **stáhni XML/ISDOC samostatně**
  a importuj ten soubor.
- Nebo fakturu zadej ručně.

**Co se podporuje:**

- ✅ PDF/A-3 s `/Type /EmbeddedFile` + filename končící `.isdoc` (oficiální
  ISDOC PDF spec).
- ✅ PDF s embedded ISDOC pod jiným jménem (content sniff podle ISDOC
  namespace `http://isdoc.cz/namespace/2013`).
- ✅ **ISDOCX balíček** (`.isdocx` ZIP s `manifest.xml` + `.isdoc` + PDF) —
  jako samostatný soubor i jako příloha PDF/A-3. Hlavní ISDOC se určí z
  manifestu, s fallbackem na `.isdoc` v kořeni archivu.
- ✅ PDF s *compressed object streams* (`/Type /ObjStm`, PDF 1.5+).
  Spec sice ObjStm zavedlo, ale **stream objekty (a tím i `EmbeddedFile`)
  v ObjStm být nesmí** — vždy zůstávají na top-level, takže náš scanner
  je najde i v takových PDF.

**Limity:**

- ❌ **Šifrované PDF** (heslem nebo certifikátem). Stream byty jsou
  zašifrované, extractor je neumí dekódovat. Otevři PDF v Adobe Readeru,
  zadej heslo, ulož znovu bez šifrování, a pak nahraj.
- ❌ **Non-FlateDecode stream filtr** (LZW, RunLengthDecode, ASCII85
  bez následného Flate). Extractor zvládá jen FlateDecode (drtivá
  většina běžných PDF). U producentů používajících jiné filtry můžeš narazit.
- ❌ **Vícestupňový filter chain** (`/Filter [/ASCII85Decode /FlateDecode]`).
  Vzácné, ale existuje. Workaround: stáhni si ISDOC samostatně v původním
  systému.

## 21.9 Tipy

- **Před importem nahraj klienty z ARES** — ne nutné, ale pokud máš čas, můžeš
  je založit ručně se správnou výchozí měnou a paušálem; import pak jen použije
  existující ID a nebude tahat ARES.
- **Pohoda → MyÚčto** — exportuj v Pohodě data balíček (XML), nahraj sem.
  Pohoda neukládá `číslo zakázky` per fakturu, takže se importují bez zakázky
  (pokud klient nemá více emailů — viz § 21.2).
- **Multi-supplier** — přepni v aplikaci na cílového dodavatele předtím, než
  spustíš import. IČO z XML se ověří proti tomuto kontextu.
- **Co dělat, když import vyhodí chybu** — soubor zkontroluj v textovém
  editoru, jestli má validní XML a očekávaný root element (`<dat:dataPack>`
  pro Pohodu, `<Invoice>` v ISDOC namespace pro ISDOC). Pro PDF zkontroluj,
  jestli má `.isdoc` přílohu (viz § 21.6).

## 21.10 API import z iDoklad

Alternativa k file uploadu: přímé volání iDoklad API v3 (OAuth2 Client Credentials).
Vhodné pro většinu dat — táhne **kontakty + vystavené faktury + dobropisy + přijaté
faktury + přijaté účtenky/paragony + bankovní pohyby** najednou, po sekcích a rocích, s dry-run
preview a background jobem.

### 21.10.1 Získání API credentials

1. Přihlas se do [iDokladu](https://app.idoklad.cz/).
2. **Nastavení → API přístup** (nebo **Uživatelský účet → API**).
3. **Vytvořit nový API klíč** → typ **Client Credentials**.
4. Zkopíruj:
   - **Client ID** — identifikátor aplikace
   - **Client Secret** — tajný klíč (zobrazí se **jen jednou**; uschovej si ho)

### 21.10.2 Nastavení v MyÚčto

`Systém → Externí integrace → iDoklad` (admin only):

| Pole | Popis |
|---|---|
| **Client ID** | Vložit z iDokladu |
| **Client Secret** | Vložit z iDokladu (uloží se šifrovaně AES-256-GCM per supplier) |

Klikni **Uložit** → MyÚčto si **otestuje connection** (token endpoint + ping
na první sekci). Pokud OAuth2 selže (401), zkontroluj copy-paste (typicky se
přidá whitespace).

### 21.10.3 Spuštění importu

Na téže stránce, sekce **Spustit import**:

| Pole | Popis |
|---|---|
| **Roky** | Range (např. `2020-2025`); můžeš zvolit i jen aktuální + minulý rok |
| **Sekce** | Zaškrtnout: `contacts` / `invoices` / `credit-notes` / `purchases` / `receipts` (přijaté účtenky/paragony) / bankovní účty a pohyby |
| **Dry-run (jen náhled)** | Default ON pro první běh — nepíše nic do DB, jen vypíše co BY udělal |

Klikni **Spustit import**.

Volba **Bankovní účty** synchronizuje číselník účtů z iDokladu a mapuje jej
jen na aktivní účty stejné měny v MyÚčtu. Přesná a jednoznačná shoda se
propojí; neznámý nebo nejednoznačný účet zůstane ke kontrole. Synchronizace
automaticky nezakládá ani nepřepisuje místní bankovní účet.

Volitelná sekce **Bankovní pohyby** se načítá až po synchronizaci dokladů.
Importuje se pouze účet s jednoznačným mapováním. Stabilní ID pohybu z iDokladu
brání duplicitám a vazba na konkrétní doklad z iDokladu má přednost před
obecným párováním podle variabilního symbolu. Volba **Pouze změny od posledního
importu** používá ID posledního uloženého pohybu; dry-run ukáže také přímé
shody, ale platby nezapisuje.

GPC nebo PDF výpis z banky je autoritativnější zdroj. Když stejná platba
přijde i z iDokladu nebo e-mailového avíza, sekundární záznam se při
jednoznačné shodě označí jako ignorovaný, aby nevznikla dvojí úhrada.

### 21.10.4 Co se importuje

| Sekce | Co se vytvoří |
|---|---|
| **contacts** | `clients` rows (IČ, name, address, DIČ, email, phone). ARES NEvolá — důvěřuje datům z iDokladu. |
| **invoices** | `invoices` + `invoice_items` + VAT classification. Status: viz § 21.8.5. |
| **credit-notes** | `invoices` se `invoice_type='credit_note'` + parent link na původní fakturu (přes `parent_invoice_id`). |
| **purchases** | `purchase_invoices` + `purchase_invoice_items`. Klient → `clients` s `is_vendor=true`. |
| **receipts** | Přijaté **účtenky/paragony** (iDoklad `ReceivedReceipts`) → `purchase_invoices` s `document_kind='receipt'`. Účtenka nemá splatnost ani DUZP → `datum vystavení` = DUZP = splatnost. Hrazená na místě → importuje se rovnou jako **Zaplacená**. Hotovostní účtenka **bez dodavatele** viz poznámka níže. |
| **bank transactions** | Bankovní pohyby (`BankStatements`) → výpisy a transakce se zachováním firmy, mapováním účtů a deduplikací proti GPC/PDF/e-mailovým avízům. |

U vydané faktury se bankovní účet přebírá z historických údajů `MyAddress`
konkrétního dokladu. Výchozí účet měny se použije jen tehdy, když doklad účet
neobsahuje nebo jej nelze jednoznačně spojit s aktivním účtem v MyÚčtu.

### 21.10.5 Platební stav

API import přebírá **skutečný platební stav ze zdrojového systému** — na rozdíl
od file uploadu (§ 21.3), kde se stáří jen odhaduje pravidlem 30 dní:

- Doklad v iDokladu **Uhrazeno / Přeplaceno** → importuje se jako **Zaplacená**
  (`paid_at` = datum úhrady z iDokladu; nepošle se na ni upomínka).
- Vše ostatní (neuhrazeno, částečně uhrazeno) → **Koncept**. Doklady si
  zkontroluješ a vystavíš sám — záměrně se automaticky nevystavují, aby na
  reálně nezaplacené historické faktury nezačaly klientům odcházet upomínky.
- Totéž platí pro **přijaté faktury** (uhrazeno → Zaplacená, jinak Koncept).
- **Přijaté účtenky/paragony** jsou hrazené na místě → importují se rovnou jako
  **Zaplacená** (datum úhrady = datum vystavení), pokud iDoklad nevrátí jiný stav.

**Hotovostní účtenka bez dodavatele.** Účtenka bez navázaného kontaktu (typicky
hotovostní nákup) se **nezahazuje** — náklad se navěsí na sběrného systémového
dodavatele **„Hotovostní nákup (účtenka)"** (jeden na firmu, založí se automaticky
jako neplátce). Protože dodavatele ani jeho plátcovství DPH nelze u anonymní
účtenky ověřit, importuje se **bez nároku na odpočet DPH** a doklad dostane
upozornění *„Účtenka bez identifikace dodavatele…"*. Pokud si chceš odpočet
uplatnit, otevři doklad, **doplň skutečného dodavatele a přepni odpočet** na plný.
Pro neplátce DPH je tohle bez dopadu — účtenka je jen daňový náklad.

**Sleva** — sleva z iDokladu se přenáší: sleva na úrovni dokladu
(`DiscountType=OnDocument`) se u vydaných faktur uloží jako procentuální sleva
(viz § 10.4.1), u přijatých jako záporná položka „Sleva X %" po sazbách DPH;
položková sleva se zapečetí do jednotkové ceny. Importovaná částka tak odpovídá
iDokladu.

**Idempotence:** každý záznam má v DB sloupec `idoklad_id`, který se uloží při
prvním importu. Druhý import téhož období záznamy **přeskočí** (žádné duplicity,
žádný update existujících — import je čistě additivní).

## 21.11 API import z Fakturoid

Stejný flow jako iDoklad, jen jiný provider. **Podporujeme dvě auth
metody** — email + API token i OAuth2 Client Credentials.

### 21.11.1 Získání API credentials

**OAuth2 Client Credentials (doporučeno):**

1. Přihlas se do [Fakturoidu](https://app.fakturoid.cz/).
2. **Nastavení → API v3 přístupové údaje**.
3. **Přidat aplikaci** → zkopíruj **Client ID** + **Client Secret**.
4. Zjisti **slug účtu** — část URL: `https://app.fakturoid.cz/{slug}/...`,
   např. `jannovak`.

**E-mail a osobní API token (kompatibilní alternativa):**

1. **Nastavení → API přístup → Osobní API token**.
2. Zkopíruj **email** + **API token**.
3. Zjisti **slug** (stejný postup).

### 21.11.2 Nastavení v MyÚčto

`Systém → Externí integrace → Fakturoid`:

Přepínač **Typ autentizace**:

| Typ | Pole |
|---|---|
| **OAuth2 (Client Credentials)** — doporučená metoda | Slug + Client ID + Client Secret |
| **E-mail + API token** — kompatibilní alternativa | Slug + E-mail + API token |

Oba způsoby koexistují per-supplier. Pokud má supplier vyplněné oba bloky,
**OAuth2 má prioritu** (Bearer token).

OAuth2 token MyÚčto cachuje šifrovaně (AES-256-GCM v
`supplier.fakturoid_access_token_enc`) s TTL ~2h. Při HTTP 401 se token vyhodí
a obnoví automaticky — uživatel to nemusí řešit.

### 21.11.3 Spuštění importu

Identické s iDoklad (viz § 21.8.3) — vyber roky, sekce, dry-run.

### 21.11.4 Co se importuje

| Sekce | Co se vytvoří |
|---|---|
| **contacts** (Fakturoid `subjects`) | `clients` |
| **invoices** | `invoices` + `invoice_items` + DPH klasifikace |
| **credit-notes** | `invoices` s `invoice_type='credit_note'` |
| **purchases** (Fakturoid `expenses`) | `purchase_invoices` |

**Platební stav** — stejně jako u iDokladu (§ 21.8.5) se přebírá
skutečný stav z Fakturoidu: doklad **Zaplaceno** → importuje se jako Zaplacená
(`paid_at` = datum úhrady `paid_on`), **Stornováno** → Stornovaná; vše ostatní
(vč. částečných úhrad) zůstává Koncept k ručnímu vystavení.

Fakturoid stránkuje po 40 záznamech — MyÚčto automaticky tahá všechny stránky
za vybrané roky.

**Idempotence přes `fakturoid_id`** stejně jako u iDokladu.

## 21.12 Dry-run mód

Společný pro iDoklad i Fakturoid. Po zaškrtnutí **Jen náhled (dry-run)** se import
provede **synchronně** (vrátí výsledek najednou) a **nezapisuje do DB**. Slouží
k validaci credentials + náhledu dat.

**Příklad výstupu:**

```
[contacts]    Nalezeno 45 kontaktů — 40 by se vytvořilo, 5 přeskočeno (duplicita)
[invoices]    Nalezeno 120 faktur — 115 nových, 5 přeskočeno (varsymbol existuje)
[purchases]   Nalezeno 30 přijatých faktur — 30 nových
```

Pokud výstup vypadá rozumně, odzaškrtni dry-run a spusť ostrý import.

## 21.13 Background job (ostrý import)

Ostrý import (bez dry-run) běží jako **background worker** přes PHP CLI proces
(`api/bin/import-worker.php`). Aplikace vrátí `job_id` okamžitě a UI sleduje
průběh:

1. **Progress bar** se aktualizuje pollingem `GET /api/admin/import-jobs/{id}`
   (každé 2 sekundy, viz `import_jobs` migrace 0029).
2. **Detailní log** každého záznamu (sekce, akce, ID v DB / důvod přeskočení).
3. **Tlačítko Zrušit import** — worker bezpečně dokončí aktuální batch a zastaví
   se. Status v DB se nastaví na `cancelled`.

**Prevence duplicitních jobů:** stejné parametry (provider + sekce + roky)
nelze spustit znovu, dokud běží — UI vrátí 409 Conflict s odkazem na běžící job.

## 21.14 Časté problémy API importu

**„Neplatné credentials" / 401 Unauthorized**
→ Whitespace v copy-pastu Client Secret / API tokenu. Vygeneruj credentials znovu
a vlož pečlivě (bez okolních mezer / newlines). Test connection v Nastavení by
měl projít zelený.

**„Slug Fakturoid — kde ho najdu?"**
→ Z URL po přihlášení: `https://app.fakturoid.cz/jannovak/invoices` → slug je
`jannovak`. Slug je tvoje subdoména, **ne** company name.

**Import se zasekl / „neodpovídá"**
→ V UI klikni **Zrušit import**. Pokud nepomůže, restartuj backend kontejner
(`docker compose restart app`) a spusť znovu. Workers nejsou supervised — po
restartu spadnou tichá.

**Faktury se importují, ale chybí DPH klasifikace**
→ V iDoklad/Fakturoid musí mít položky vyplněné členění DPH. Pokud chybí,
MyÚčto použije auto-default podle sazby (`VatClassificationDefaulter`):
21 % → `1` (sales) / `40` (purchase), 12 % → `2`/`41`. Vystavený řádek
s 0 % vyžaduje výslovnou klasifikaci (např. osvobození, vývoz nebo plnění mimo
předmět daně); systém jej automaticky nezařadí na ř. 50. U přijatého řádku bez
nároku zůstává výchozí kód `42`.

**Kontakty z iDoklad/Fakturoid nemají emaily**
→ Originální systém je nemá vyplněné. Doplň ručně v `Klienti` po importu —
jinak nebudou fungovat upomínky.

## 21.15 Import přijatých faktur

**Cesta: `Nákup → Import`** (jen administrátor).

Import přijatých je oddělený od importu vydaných. Přijímá Pohoda XML, ISDOC,
ISDOCX, PDF/A-3 s vloženým ISDOC a ZIP balíky; lze vybrat více souborů najednou.
Server u této stránky vždy použije směr **přijatá faktura** a ověří, že IČO
odběratele ve vstupním dokladu odpovídá aktuálně zvolené firmě. Doklad s cizím
odběratelem odmítne, i kdyby byl jinak syntakticky platný.

Pro vložení **jedné** přijaté faktury není administrátorská stránka potřeba.
Uživatel včetně klientské role s oprávněním vytvářet přijaté faktury může na
`Nákup → Přijaté faktury → Nová přijatá faktura` přetáhnout `.isdoc`, `.isdocx`
nebo PDF/A-3 s vloženým ISDOC. Použije se stejné mapování, vznikne předvyplněný
koncept a otevře se ke kontrole. Běžné PDF bez vloženého ISDOC zůstane přílohou
pro ruční vyplnění; tato jednosouborová cesta nikdy nevolá AI.

Pro každý platný doklad systém:

1. vyhledá nebo založí dodavatele,
2. vytvoří **koncept přijaté faktury** a její položky,
3. u ISDOCX nebo PDF/A-3 uloží čitelný PDF originál k faktuře,
4. odděleně zaarchivuje původní strojový artefakt ISDOC, ISDOCX nebo Pohoda XML,
5. vrátí report **Vytvořeno / Přeskočeno / Chyba** s odkazem na nový koncept.

Strukturovaný import nepoužívá AI. PDF bez vloženého ISDOC proto patří do
**Nákup → AI import**, případně je lze zpracovat přes scan inbox. Importovaný
koncept před zaúčtováním vždy otevři a zkontroluj dodavatele, období, DUZP,
částky, DPH klasifikaci a nárok na odpočet.

Na stejné stránce je také ruční spuštění **scan inboxu**. Ten projde
nakonfigurovaný adresář, použije ISDOC přednostně a u nestrukturovaného PDF může
přejít na nastavenou AI bránu. Volba **Nanečisto** vrátí report bez vytvoření
dokladů. Nezpracované a chybné soubory zůstávají v samostatném seznamu s důvodem,
aby se neztratily v souhrnných počtech.

Upload je omezen na 50 souborů, nejvýše 20 MiB na soubor a 50 MiB celkem. Zápis
vyžaduje oprávnění k importu; všechny výsledky jsou omezené na aktuální firmu.
