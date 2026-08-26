# 91. Více dodavatelů z jedné instalace

MyÚčto umožňuje fakturovat za **libovolný počet dodavatelů (firem / IČO)**
z jedné instalace. Typické scénáře:

- **OSVČ + s.r.o.** — Jan Novák, OSVČ + Novák s.r.o. = 2 dodavatelé
- **Holding** — mateřská firma + 3 dceřiné = 4 dodavatelé
- **Účetní kancelář** — fakturuje za sebe + spravuje fakturaci pro 20 klientů
- **Sdílený workspace pro tým** — každý kolega má vlastní firmu, ale všichni
  vidí svého

Data jsou **plně izolovaná** — klienti jednoho dodavatele nejsou viditelní
pro druhého, faktury mají vlastní řadu varsymbolů, číselné cykly,
e-mailové šablony atd.

## 91.1 Jak to vidět v UI

Pokud máš přístup k více firmám, uprostřed spodní lišty se zobrazí **přepínač
dodavatele**:

![Přepínač dodavatele](img/14_supplier_switcher.webp)

- Pokud je dodavatel jediný, přepínač se nezobrazuje.
- Pokud je jich víc, ukazuje se dropdown s aktuálním + ostatními ke přepnutí.

Při přepnutí:

- Aplikace se reloadne (router-link na `/`)
- Pokud jsi byl na detailu / editoru entity, přesměruje na seznam (entita
  patří jinému dodavateli, neviděl bys ji)

## 91.2 Přidání nového dodavatele

V hlavním menu **Systém → Dodavatelé**.

![Seznam dodavatelů](img/14_dodavatele_list.webp)

Tabulka:

| Sloupec | Význam |
|---|---|
| Název | Název firmy / OSVČ |
| IČO | České IČO |
| DIČ | Daňové ID |
| Měn | Počet aktivních měn (= počet bankovních účtů) |
| Klientů | Počet klientů pod tímto dodavatelem |
| Faktur | Počet vystavených faktur |
| Vytvořen | Datum |

Tlačítko **+ Nový dodavatel** vpravo nahoře.

### 91.2.1 Modal nového dodavatele

![Nový dodavatel — ARES](img/14_dodavatel_novy.webp)

| Pole | Význam |
|---|---|
| IČO | Zadej a klikni **Načíst z ARES** — předvyplní zbytek |
| Firma | Název |
| DIČ | (volitelné, OSVČ neplátce nech prázdné) |
| Adresa | Ulice / Město / PSČ / Stát |
| E-mail / telefon | Kontakt |
| První bankovní účet | CZK účet (číslo + bank kód) — automaticky se založí v měně CZK |

Po **Vytvořit** je dodavatel okamžitě v dropdownu, můžeš na něj přepnout.

## 91.3 Co je per-dodavatel (izolované)

Každý dodavatel má vlastní:

- **Klienty** + jejich zakázky + faktury
- **Měny** + bankovní účty (CZK + EUR + …)
- **Číselnou řadu varsymbolů** (každý dodavatel má samostatné `2605001`,
  `2605002`, …)
- **Šablonu čísla faktury** — vlastní formát per typ dokladu (`{YY}{MM}{CCC}`,
  `JD{YYYY}-{CC}`, …) + reset cyklu (rok / měsíc / nikdy) — viz § 91.5.3
- **Výchozí nastavení** — splatnost, hodinová sazba, DPH, **výchozí režim cen
  s DPH / bez DPH** (*Ceny s DPH* — předvyplní přepínač u nové
  faktury, viz [§ 15.2.6](15_Faktura_editor.md#1526-ceny-s-dph-vs-bez-dph-brutto-netto-rezim))
- **E-mailové šablony** (faktura nová / upomínka / reset hesla)
- **Pohoda kódy** pro export
- **From: jméno + Reply-To** v odchozích e-mailech
- **Statistiky** (dashboard ukazuje data jen aktuálního dodavatele)

## 91.4 Co je sdílené (cross-supplier)

- **Uživatelé + role** — uživatel vidí všechny dodavatele
- **Číselníky** (DPH sazby, země) — společné systémové
- **Activity log** — všechny mutace logované, ale filtrovatelné per dodavatel
- **IP allowlist + bezpečnostní nastavení** — globální
- **SMTP konfigurace** — globální (`From:` jméno se ale řídí per-dodavatel)
- **Cron skripty** — projedou všechny dodavatele

## 91.5 Editace dodavatele

Nastavení aktuálně zvolené firmy je v **Firma → Nastavení** rozdělené do
záložek **Údaje firmy**, **Fakturace**, **Daně a účetnictví** a **Pokročilé**.
Změny ze všech záložek se ukládají společným tlačítkem dole pod obsahem.

**Systém → Dodavatelé → klik na řádek → Editovat**.

Záložky:

### 91.5.1 Základní údaje

Stejné jako při založení (IČO, název, adresa, kontakt). Změna se projeví na
NOVÝCH fakturách. Vystavené mají vlastní snapshot.

### 91.5.2 E-mail branding

**From / Reply-To** se odvozuje automaticky:

| Pole | Význam |
|---|---|
| From: jméno | `display_name` dodavatele (fallback `company_name`) — místo „myucto@server" |
| Reply-To | `email` dodavatele — odpovědi klientů jdou rovnou na firemní mail |

**Vlastní branding emailů + PDF** — **Firma → Branding**. Položka je v menu
hned za **AI nastavením**. Nahraď default „M" logo
MyÚčto vlastním logem firmy a navol akcent barvu. Když je branding
**zapnutý**, použije se logo i akcent barva jak v **e-mailech**, tak v
**PDF faktur** (logo v hlavičce místo textového jména firmy, akcent barva
na akcentech celého dokladu). Když je **vypnutý**, e-mail i PDF se vrátí
k default MyÚčto „M" brandingu a fialové barvě — toggle gatuje obojí
konzistentně.

![Branding emailů — toggle, logo, akcent barva, live preview](img/14_branding.webp)

| Pole | Co dělá |
|---|---|
| **Použít vlastní branding** | Toggle vpravo nahoře (default vypnuto = MyÚčto branding). Pokud zapnuté, hlavička emailů i PDF se sestaví z polí níže. |
| **Logo** | Upload PNG / JPG / SVG (max 1 MiB, ideálně do 200 KiB). Pro raster ideální výška 240 px (zobrazí se v emailu jako 48 px na 5× retině). SVG: originál se uloží pro PDF (vektor = crisp v libovolném zoomu), pro email se serverstrana převede na transparentní PNG (Outlook a Gmail SVG strippují) — primárně přes PHP `Imagick` extension (cross-platform — Windows i Linux), fallback na `rsvg-convert` CLI (`librsvg2-bin`). Logo se v emailu připojí jako CID inline image, takže se zobrazí bez „Display images" promptu v Gmailu/Outlooku. Tlačítka **Nahradit logo** / **Odebrat**. |
| **Akcent barva** | Hex `#RRGGBB` — akcentová barva **celého e-mailu** (částky, tlačítka, odkazy, náhradní „M" box) **i PDF faktury** (linka pod hlavičkou, hlavička tabulky položek, řádky „Celkem" / „K úhradě", labely, popisky QR/banky, nadpis a odkaz výkazu víceprací). Aplikuje se **jen při zapnutém brandingu**; jinak default `#3B2D83` (fialová MyÚčto). Sémantické barvy (dobropis červená, zelené „Schválit"/„Uhrazeno", oranžová „po splatnosti") zůstávají. Color picker + textový input + odkaz **↺ default** pro reset. |

> 🛈 **Auto-save** — toggle a barva se ukládají **automaticky** (color picker
> má 0,5 s debounce, ať se neukládá při každém pixelu pohybu). Logo se ukládá
> okamžitě při uploadu. Tlačítko **Uložit branding** je explicitní fallback
> pro jistotu — typicky ho nepotřebuješ.

V hlavičce se pak vykreslí:

- **Logo** vlevo (místo fialového „M" boxu) — `<img>` s `max-height: 48px`
- **Brand name** = `display_name` dodavatele (fallback `company_name`)
- **Subtitle** = `tagline` dodavatele (pokud vyplněno)

**Live preview** — pod nastavením iframe se zkušebním emailem (faktura
`2026005` s boxem „K úhradě" a tlačítkem „Zobrazit fakturu" — obojí
obarvené akcent barvou, ať vidíš branding i v těle, ne jen v hlavičce/patičce).
Tlačítka **CS / EN** přepínají jazyk preview. Po každé změně toggle / barvy /
loga se preview obnoví automaticky; tlačítko **↻** vpravo nahoře v hlavičce
preview je manuální refresh, kdyby si cache hrála.

**Patička emailu** vždy obsahuje malý šedý text „Používá účetní systém
[MyÚčto.cz](https://myucto.cz/)" jako attribution — nezakrývá tvoji
firemní identitu, jen drobně označuje použitou platformu.

> 🛈 **Snapshot vs live branding** — fakturační údaje (název firmy, adresa,
> kontakt) se v emailu berou ze **snapshotu** zachyceného při vystavení faktury
> (immutable, kvůli auditu). Naopak **branding** (logo, barva, toggle) se vždy
> bere **live** z aktuálního stavu dodavatele — pokud změníš logo, projeví se
> okamžitě i v emailech ke starým fakturám.

> ⚠️ **SVG na hostu bez Imagick i `rsvg-convert`** — SVG upload selže
> s hláškou „SVG konverze není dostupná". Buď nainstaluj jedno z toho:
> - **PHP `imagick` extension** (cross-platform — Windows: `pecl install imagick`,
>   Linux: `apt install php-imagick`, macOS: `pecl install imagick`) — preferované
> - **`librsvg2-bin`** (Linux: `apt install librsvg2-bin`, macOS: `brew install librsvg`)
>
> Docker image `ghcr.io/radekhulan/myucto` má `librsvg2-bin` zabalené, takže
> SVG funguje out-of-the-box. PNG / JPG funguje vždy přes GD (built-in).

### 91.5.3 Číslování faktur

V detailu dodavatele najdeš sekci **Číslování faktur** se šablonami pro každý
typ dokladu a volbou cyklu, kdy se pořadové číslo resetuje.

**Šablony (per typ dokladu):**

| Pole | Co zadat |
|---|---|
| Šablona pro fakturu | např. `{YY}{MM}{CCC}` → `2605001` (default) nebo `JD{YYYY}-{CCC}` → `JD2026-001` |
| Šablona pro zálohovou | např. `9{YY}{MM}{CCC}` → `92605001` (prefix 9 = záloha) |
| Šablona pro dobropis | např. `7{YY}{MM}{CCC}` → `72605001` (prefix 7 = dobropis) |

Placeholdery:

| Token | Význam | Příklad pro 2026-04, counter=42 |
|---|---|---|
| `{YYYY}` | 4-ciferný rok | `2026` |
| `{YY}` | 2-ciferný rok | `26` |
| `{MM}` | číslo měsíce (01..12) | `04` |
| `{C}`, `{CC}`, `{CCC}`… | counter, padding podle počtu C | `42`, `42`, `042` |

U roku i měsíce lze zapsat **posun** ve tvaru `±N`: `{YY+30}` → `56`,
`{YYYY+1}` → `2027`, `{MM-1}` → `03`. Rok se posouvá po letech, měsíc po
měsících včetně přetečení roku (`{MM+8}` v květnu → `01`).

> ⚠️ **Posun mění jen vypsané číslo, ne kdy se řada resetuje.** Období čítače
> řídí výhradně volba *Reset číslování*. Řada `{YY+30}{CCC}` tedy v roce 2026
> vypisuje `56001`, `56002`… a přeskočí zpátky na `001` k 1. lednu 2027 —
> podle skutečného roku, ne podle toho posunutého.

> 🛈 Pole nech **prázdné** a systém použije fallback z `cfg.varsymbol.templates`
> (default `{YY}{MM}{CCC}` pro fakturu, `9{YY}{MM}{CCC}` pro proformu,
> `7{YY}{MM}{CCC}` pro dobropis). Vyplň, jen když chceš vlastní řadu.

Pod každým polem **live preview** ukazuje, jak by vypadalo příští číslo
(např. „Náhled: `JD2026-001`"). Když chybí counter (`{C+}`), pole je červené
s chybou „Chybí counter".

**Reset číselné řady:**

| Hodnota | Kdy se counter vrací na 1 |
|---|---|
| **Roční** (`year`) | 1. ledna |
| **Měsíční** (`month`) | 1. dne v měsíci (default — backwards compat s legacy chováním) |
| **Bez resetu** (`none`) | Nikdy — souvislá číselná řada napříč roky |

> ⚠️ **Změna cyklu uprostřed roku** může vyrobit duplicitní čísla. Pokud
> přepneš z `month` na `year` a šablona obsahuje `{MM}`, dostaneš v dalším
> měsíci stejné `{YY}{MM}001` jako už máš v evidenci. Backend chytne přes
> 409 chybu při Vystavení, ale doporučujeme spolu s změnou cyklu **upravit
> i šablonu** (pro `year` vyhoď `{MM}`, pro `none` vyhoď `{YY}` i `{MM}`).

**Vlastní řada mimo dodavatele:**

Šablonu lze přebít i na nižší úrovni. Uplatní se první vyplněná v tomto pořadí:

| Priorita | Kde se nastavuje | Kdy to použít |
|---|---|---|
| 1. Zákazník | Detail zákazníka → **Vlastní číselná řada** | Odběratel, se kterým je sjednaná samostatná řada (typicky převod z jiného systému) |
| 2. Kategorie tržby | Číselníky → **Kategorie tržeb** → *Vlastní číselná řada* | Oddělené řady podle druhu tržby (např. hosting × konzultace) napříč zákazníky |
| 3. Dodavatel | Sekce výše | Standardní řada firmy |
| 4. `cfg.varsymbol.templates` | Konfigurace instalace | Fallback, když není vyplněné nic |

Každá vyhrávající úroveň má **vlastní počítadlo** — dvě kategorie tržeb s vlastní
šablonou se navzájem nepřečíslovávají a supplier-wide řada jimi neproběhne.
Nevyplněná pole se dědí, takže kategorie může mít vlastní řadu jen pro faktury
a proformy nechat na dodavateli.

> ⚠️ Šablony různých řad se musí lišit **číslicí**, ne jen písmenem nebo pomlčkou —
> bankovní párování variabilní symbol normalizuje na číslice. Kolizi hlásí kontrola
> v nastavení dodavatele (pokrývá řady dodavatele, zákazníků i kategorií tržeb).

**Kde se to projeví:**

- V editoru konceptu vidíš **placeholder** s předpokládaným číslem (preview).
- Při Vystavení (Issue) se atomicky vezme další counter z DB a uloží jako
  immutable `varsymbol`.
- V editoru konceptu můžeš číslo přepsat ručně — viz [§ 15.2.5](15_Faktura_editor.md#1525-cislo-dokladu-rucni-override-volitelne).

### 91.5.4 Kopie odchozích e-mailů dodavateli

Sekce **Kopie odchozích e-mailů na e-mail dodavatele** v nastavení dodavatele.
Zprávy klientům se mohou posílat v kopii i na e-mail dodavatele — audit vlastní
odchozí pošty. Tři typy zpráv, každý s vlastní volbou:

| Typ zprávy | Pokrývá |
|---|---|
| **Odeslání dokladu** | Ruční odeslání faktury/proformy/dobropisu + automatické odeslání po schválení výkazu |
| **Upomínky** | Ruční i automatické upomínky po splatnosti (vč. proforma upomínek) |
| **Schvalování výkazů** | Žádost o schválení výkazu **i** schvalovací upomínky |

Volby per typ:

| Volba | Co dělá |
|---|---|
| **Dle konfigurace** (default) | Přebírá globální nastavení ze `cfg.php` (`cc_supplier_on_send`, `cc_supplier_on_reminder`, `cc_supplier_on_approval[_reminder]`) — efektivní hodnota je vidět přímo ve volbě |
| **Neposílat** | Kopie se neposílá, i kdyby ji cfg zapínala |
| **Kopie (CC)** | Dodavatel viditelně v kopii |
| **Skrytá kopie (BCC)** | Klient kopii nevidí (default chování cfg u schvalování) |

> 🛈 Kopie prochází jednotným resolverem příjemců (#86) — v modalu odeslání ji
> uvidíš jako chip **„kopie dodavateli“** a můžeš ji pro konkrétní e-mail ručně
> smazat. Pokud je e-mail dodavatele už mezi příjemci (TO), podruhé se nepřidá.

> 🛈 Děkovný e-mail za úhradu kopii dodavateli záměrně neposílá — o úhradě
> dodavatel ví (sám ji označil, nebo přišla z banky).

### 91.5.5 Poděkování za úhradu

Sekce **Poděkování za úhradu** v nastavení dodavatele zapíná krátký děkovný
e-mail, který se zákazníkovi pošle po zaplacení faktury. Funkce je **ve
výchozím stavu vypnutá**.

| Volba | Co dělá |
|---|---|
| **Posílat poděkování za úhradu** | Hlavní vypínač funkce. Bez něj se zbylé volby neuplatní. |
| **Automaticky při spárování platby z banky** | Jakmile se platba spáruje z bankovního výpisu nebo e-mailového avíza a faktura se označí jako zaplacená, pošle se poděkování samo. |
| **Předzaškrtnout při ručním označení jako zaplacené** | V modalu ručního označení faktury jako zaplacené bude checkbox „Odeslat zákazníkovi poděkování" předem zaškrtnutý (jinak prázdný). |
| **Přiložit PDF faktury (se stavem Uhrazeno)** | K e-mailu se připojí PDF faktury orazítkované jako uhrazené. |

Text e-mailu upravíš v šabloně `invoice_payment_thanks` (**Systém →
E-mail šablony**) — má samostatnou variantu pro běžnou fakturu i pro
zaplacenou zálohu (proformu). Poděkování jde poslat i **ručně** v detailu
nebo hromadně v seznamu faktur při označování plateb.

> 🛈 Poděkování je **idempotentní** — odešle se k jedné faktuře jen jednou.
> Neposílá se u storna ani u faktury bez e-mailu příjemce; selhání e-mailu
> nikdy nezablokuje samotné označení platby. Vše se zapisuje do activity logu.

### 91.5.6 Pohoda kódy

| Pole | Sloupec | Příklad |
|---|---|---|
| Účet (kód) | `pohoda_account_code` | `KB` |
| Středisko | `pohoda_centre_code` | `01` |
| Činnost | `pohoda_activity_code` | `100` |
| Zakázka | `pohoda_contract_code` | `ZAK1` |
| Předkontace | `pohoda_accounting_code` | `300` |

Viz [20. Exporty](20_Exporty.md).

## 91.6 Režim účtování per firma

Kromě izolovaných dat (§ 91.3) si každý dodavatel v multi-supplier instalaci **nezávisle
na ostatních** volí i svůj vlastní **režim účetnictví**. Přepnutí dodavatele ve spodní liště
(§ 91.1) tak nemění jen viditelná data, ale i to, jaké sekce menu a moduly máš k dispozici —
holding s mateřskou firmou v podvojném účetnictví a dceřinou firmou vedenou v daňové evidenci
je běžný a plně podporovaný stav.

### 91.6.1 Kde se nastavuje

V **detailu dodavatele** (§ 91.5), sekce **„Daňové nastavení"** (stejný box jako EPO údaje
a Pohoda kódy, viz [§ 91.5.6](#9156-pohoda-kody)), je pole **Režim účetnictví**:

| Volba | Hodnota v DB (`accounting_mode`) | Význam |
|---|---|---|
| **Daňová evidence** | `tax_evidence` | Jednoduchá evidence příjmů a výdajů — výchozí pro nově založeného dodavatele |
| **Podvojné účetnictví** | `double_entry` | Plnohodnotné podvojné účetnictví — účetní deník, hlavní kniha, výkazy, majetek |

> [!NOTE]
> Pole smí měnit jen **admin**, stejně jako ostatní údaje v detailu dodavatele (§ 91.5).
> Výjimkou je zapnutí skladu (§ 91.6.3), které smí přepnout i role **účetní**.

> [!WARNING]
> Firma s historií se na podvojné účetnictví přepíná výhradně přes **průvodce
> aktivací**. Běžné uložení nastavení tě do něj automaticky přesměruje; režim se
> zapne až po úspěšné kontrole a doúčtování, takže účetní sestavy mezitím nevypadají
> jako úplné. Průvodce založí účtový rozvrh, nechá zkontrolovat otevírací rozvahu,
> provede kontrolu nanečisto a teprve potom zpracuje faktury, pokladnu a banku.
> Podrobný postup je v [§ 90.10.3](90_Danova_evidence.md#90103-pruvodce-aktivaci-podvojneho-ucetnictvi).

U nové firmy bez historie se režim přepne přímo a výchozí účtový rozvrh se založí
automaticky. Pokud byla podvojná evidence zapnuta dříve a historie není kompletní,
zobrazí Deník, Hlavní kniha, Předvaha, Rozvaha a Výsledovka viditelné upozornění
**„Historie není doúčtována — sestavy jsou neúplné"** s odkazem na dokončení aktivace.
Stejný úkol se zobrazí i na Přehledu.

> [!NOTE]
> Založení osnovy a doúčtování historie (výše) řeší **účetní** stránku přechodu.
> Zákon u OSVČ navíc vyžaduje jednorázovou **úpravu základu daně** o neuhrazené
> pohledávky/závazky a zásoby k datu přechodu (příloha č. 3 ZDP) — to je daňová
> záležitost mimo účetní zápisy, systém k ní jen připraví podklady. Podrobně viz
> [Daňová evidence § 51.10](90_Danova_evidence.md).

> [!WARNING]
> Fyzická osoba může vedení účetnictví ukončit až po **5 po sobě jdoucích účetních
> obdobích** (§ 4 odst. 7 zákona o účetnictví). MyÚčto dřívější přepnutí zpět na
> daňovou evidenci odmítne. I při povoleném přechodu je nutné upravit základ daně
> podle přílohy č. 2 ZDP; přechodová sestava umí připravit podklady pro oba směry.

### 91.6.2 Co která volba zpřístupní v menu

Volba se v menu projeví **okamžitě** po přihlášení nebo po přepnutí dodavatele (§ 91.1):

| Režim | Sekce v menu | Obsahuje |
|---|---|---|
| **Podvojné účetnictví** | **Účetnictví** | Účetní deník, Hlavní kniha, Obratová předvaha, Rozvaha, Výsledovka, Majetek, Účtový rozvrh, Účetní období, Předkontace, Archiv účetnictví (jen admin), Export/Import |
| **Daňová evidence** | **Daňová evidence** | Peněžní deník, Pohledávky a závazky |

Podrobný popis obou modulů najdeš v samostatných kapitolách —
[Účetní deník](45_Ucetni_denik.md) pro podvojné účetnictví,
[Daňová evidence](90_Danova_evidence.md) pro daňovou evidenci.

Nezávisle na zvoleném režimu zůstávají v menu i:

- **Pokladna** (pokladní doklady) — v sekci **Peníze** hned za Bankovními účty, dostupná
  pro oba režimy stejně.
- Sekce **Daně** (přiznání DPH, kontrolní hlášení, souhrnné hlášení, daň z příjmu…) —
  netýká se volby účetního režimu, platí stejně pro plátce i neplátce DPH.
- Položka **Export/Import** (`/exchange`) — u dodavatele v daňové evidenci je (jako
  fallback) v sekci Daně; u dodavatele v podvojném účetnictví je součástí sekce Účetnictví.

### 91.6.3 Sklad je nezávislý na režimu účetnictví

Zapnutí skladové evidence (pole `stock_enabled`) je **samostatný přepínač** v detailu
dodavatele, nezávislý na volbě daňová evidence / podvojné účetnictví — funguje shodně
v obou režimech. Zapíná/vypíná sekci menu **Sklad** (skladové karty, příjemky/výdejky,
e-shop číselníky, inventury, sestavy) — podrobně viz [Sklad](33_Sklad.md).

| Pole | Co dělá |
|---|---|
| **Vést skladovou evidenci** | Hlavní vypínač — zpřístupní skladové karty, doklady, inventury a sekci Sklad v menu. |
| **Automatická výdejka při vystavení faktury** | Zobrazí se, jen když je sklad zapnutý. Při vystavení faktury s položkami napojenými na skladové karty se automaticky vytvoří a zaúčtuje výdejka, bez ručního zásahu. |

> [!TIP]
> Obě pole skladu smí přepnout i role **účetní**, ne jen admin — je to jediná výjimka
> z jinak admin-only nastavení dodavatele (§ 91.5).

## 91.7 Smazání dodavatele

Na stránce **Systém → Dodavatelé** (`/admin/suppliers`, jen admin) má každý
řádek tlačítko **Smazat**.

> [!WARNING]
> **Firmu s účetními daty appka nedovolí smazat.** Před smazáním appka
> zkontroluje čtrnáct tabulek (klienti, vydané i přijaté faktury, účetní
> deník, pokladní doklady a pokladny, majetek, sklad — karty i doklady i
> sklady, dokumenty, přiznání k dani z příjmů, podané daňové výkazy, příkazy
> k úhradě). Pokud má firma v **kterékoli** z nich data, smazání skončí
> chybou s výčtem, co konkrétně brání: *„Firmu nelze smazat — obsahuje účetní
> data: vydané faktury, účetní deník. Data nejdřív odstraňte, nebo firmu
> archivujte."* Bankovní výpisy a transakce se nekontrolují (nemají přímou
> vazbu na firmu), ale prakticky u firmy s bankovními daty už existují i
> zaúčtované doklady, takže na ně blok narazí stejně.
>
> **Žádné tlačítko „archivovat" ve skutečnosti neexistuje** — text v hlášce je
> jen doporučení „nech ji neaktivní". Jediná reálná cesta ke smazání firmy s
> účetní historií je napřed fyzicky odstranit její data ve všech
> kontrolovaných tabulkách (typicky přes IT/podporu), ne přes UI.
>
> Potvrzovací dialog při kliku na **Smazat** říká *„Lze jen pokud nemá
> klienty ani faktury"* — to je zjednodušené a **neúplné**: tlačítko samo je
> neaktivní jen když má firma klienty nebo vydané faktury, ale i firma bez
> nich může mít pokladnu, majetek nebo sklad a smazání pak stejně skončí
> stejnou chybou 409 až po potvrzení.

Firmu, která žádná data nemá (čerstvě založená, nikdy nepoužitá), appka smaže
rovnou. Poslední zbývajícího dodavatele instalace smazat nejde vůbec
(„Posledního supplier nelze smazat" — vždy musí zůstat aspoň jeden).

## 91.8 X-Supplier-Id v API

Aktuální dodavatel se posílá v každém API requestu jako header
`X-Supplier-Id: N`. UI ho posílá z localStorage (`myinvoice.current_supplier_id`).

Pokud header chybí, server fallbackuje na `MIN(supplier.id)` — typicky první
dodavatel = ten z setup wizardu.

## 91.9 Přehled firem (pro účetní kancelář)

Přepínání dodavatele (§ 91.1) stačí, dokud spravuješ pár firem. Účetní kancelář
se 8+ klienty ale potřebuje vidět termíny a resty **napříč všemi firmami
najednou**, ne proklikávat každou zvlášť. K tomu slouží stránka **Účetnictví →
Přehled firem** (`/portfolio`).

> [!NOTE]
> Položka v menu se zobrazí jen uživatelům s přístupem k **více než jedné**
> firmě — pro jednofiremní instalaci nedává smysl a menu nezahltí. Role
> **klient** k ní nemá přístup vůbec (stejně jako k analytickým grafům a účetnictví).

Tabulka firem je seřazená **dle urgence** — firma s nejbližším daňovým
termínem nahoře, firmy bez termínu (neplátci DPH) dole. Per firma vidíš:

| Sloupec | Význam |
|---|---|
| **Nejbližší termín** | Nejbližší DPH / KH / SH termín (dny do splatnosti, po termínu červeně) |
| **Nezaúčtováno** | Počet nezaúčtovaných dokladů (jen podvojné účetnictví) |
| **Nespárováno (banka)** | Nespárované příchozí platby z bankovních výpisů |
| **Koncepty PF** | Přijaté faktury čekající na revizi |
| **Účetní období** | Stav aktuálního fiskálního roku (otevřené / uzavírá se / uzavřené) |
| **Poslední import banky** | Kdy byl naposledy naimportován bankovní výpis |

Klik na **název firmy** nebo na konkrétní číslo/termín **přepne aktivní
firmu** (stejný mechanismus jako přepínač v horní liště, § 91.1) a rovnou tě
přesměruje na odpovídající agendu (např. klik na nezaúčtované doklady tě
přepne na firmu a otevře filtrovaný seznam faktur).

> [!NOTE]
> Přehled respektuje **přístup k firmám** — pokud ti admin v **Systém →
> Uživatelé → editace účtu → Přístup k firmám** zaškrtl jen některé firmy,
> vidíš v přehledu jen ty. Globální admin a účty bez omezení (nic
> nezaškrtnuto) vidí všechny firmy v instalaci.

## 91.10 Tipy

- **Při založení dodavatele použij ARES** — ušetří 5 minut opisování.
- **Nevynechej Pohoda kódy** pokud plánuješ používat Pohoda XML export.
- **Per-dodavatel `From:` jméno** je důležitý pro deliverabilitu — klient
  vidí v inboxu „Faktury Vzorové firmy" místo „myucto@server-3.hosting.cz".
- **Ukázková data se generují jen pro první firmu v instalaci.** Příkaz
  `php api/bin/sample.php` neumí vybrat jinou firmu; pro další firmy založ
  syntetická testovací data ručně.
