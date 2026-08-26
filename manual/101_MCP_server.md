# 101. MCP server (napojení AI asistenta)

MCP server propojí **AI asistenta** — Claude, ChatGPT přes Codex, Gemini,
Copilota — s daty tvé firmy. Po zprovoznění se ptáš běžnou češtinou
(„kolik zaplatíme na DPH“, „kdo nám dluží“, „jaký byl loni zisk“) a asistent
si sám vybere správný nástroj a zavolá ho přes [REST API](99_API.md).

Nastavení najdeš v aplikaci: **Firma → MCP server**. Ta stránka ukazuje adresu
API konkrétně tvojí instance a hotovou konfiguraci pro vybraného asistenta.

## 101.1 Co je MCP

**Model Context Protocol** je otevřený standard pro připojení nástrojů k AI
modelům. Server je malý program, který běží u tebe na počítači, mluví s aplikací
přes REST API a asistentovi nabízí sadu pojmenovaných **nástrojů**
(`list_unpaid_invoices`, `vat_return_preview`, `trial_balance`, …).

Podstatné vlastnosti:

- **MCP server nevytváří vlastní kopii dat.** Běží lokálně a volá přímo tvoji
  instanci. Výsledek nástroje ale dostane připojený AI asistent a může jej podle
  svého provozního modelu odeslat poskytovateli AI. Citlivost dotazu proto
  posuzuj stejně jako při ručním vložení údajů do daného asistenta.
- **Asistent má jen to, co má token.** Rozsah, vazba na firmu, omezení podle IP
  i oprávnění role uživatele platí beze změny.
- **Všechno je vidět v logu.** Každé volání se zapíše včetně názvu nástroje.

## 101.2 Rozsah — co asistent umí

| Oblast | Rozsah |
|---|---|
| Fakturace | čtení, vystavování, odesílání, evidence úhrad, upomínky |
| Odběratelé | vyhledání, založení a úprava karty, dotažení údajů z ARES |
| Výkazy práce a materiálu | přidání a odebrání řádků u konceptu faktury, automatická hodinová sazba |
| Zakázky | **čtení i zápis** — založení, úprava, archivace, rozpočty a ziskovost |
| Dokumenty | metadata, fulltext a omezené čtení vytěženého textu; úprava tagů a vazeb |
| Kniha jízd | **čtení i zápis** — vozidla, jízdy a tankování; daňový souhrn jen ke čtení |
| Pohledávky a závazky | zaplacené / nezaplacené / po splatnosti, stáří pohledávek |
| Daně | odhad DPH za měsíc i kvartál, kontrolní a souhrnné hlášení, daň z příjmů, daňový kalendář — **jen čtení** |
| Účetnictví | obratovka, rozvaha, výsledovka, hlavní kniha, saldo, deník — **jen čtení** |
| Statistika | tržby, zisk, trendy, top odběratelé a dodavatelé, cash flow, platební morálka, koncentrace, riziko odchodu |
| E-shop a sklad | **kompletní správa včetně zápisu** — zboží, obsah karet, ceny, dodavatelé, média, kategorie, číselníky, sklady, příjemky a výdejky, inventury (viz [§ 101.9](#1019-e-shop-a-sklad)) |
| Objednávky u dodavatele | **čtení i zápis** — založení, odeslání, potvrzení, uzavření, storno, příjemka z objednávky a hromadné objednání podle návrhu doplnění zásob ([§ 101.9](#1019-e-shop-a-sklad)) |
| Hledání | globální vyhledávání napříč odběrateli a doklady |

Nástrojů je aktuálně **181**; v režimu jen pro čtení (`MYUCTO_READ_ONLY=1`,
[§ 101.4](#1014-nastaveni)) se jich asistentovi nabídne **105** — zbylých 76 mění
data a server je vůbec nezveřejní. Přesný počet vypíše server při startu do
`stderr` ([§ 101.3](#1013-zprovozneni), krok 4).

> [!IMPORTANT]
> **Do účetnictví a daní asistent nezapisuje.** Zaúčtovat doklad, uzavřít období,
> zaevidovat opravu podle § 46 / § 74b ani odeslat podání na EPO nemůže. Je to
> agenda s daňovou odpovědností, kde chyba znamená opravné podání — dělá ji člověk
> v aplikaci. Zákaz vynucuje server, ne jen MCP: i token s právem zápisu dostane
> na takovou operaci `403 token_write_forbidden` (viz [kapitola 78.6](99_API.md#996-scopes)).

## 101.3 Zprovoznění

### Krok 1 — API token

V **Firma → API tokeny** vytvoř nový token. Zobrazí se **jen jednou**, hned si
ho zkopíruj.

- Pro zkoušení volič rozsah **čtení**. Rozsah **čtení a zápis** dávej až tehdy,
  když má asistent opravdu vystavovat doklady nebo měnit ceny.
- Token rovnou **omez na svou IP adresu** (sloupec *IP omezení* u tokenu).

### Krok 2 — příprava serveru

Server vyžaduje **Node 20 nebo novější**. Ve vydané distribuci je už připravený
hotový build; nic nemusíš sestavovat ani instalovat. Máš dvě možnosti.

Pokud Node nemáš: Windows — `winget install --id OpenJS.NodeJS.LTS --exact`;
macOS — `brew install node`.

**A) Hotový build z distribuce (doporučeno).** Použij přiložený soubor:

```text
MCP/dist/myucto-mcp.mjs
```

Jde o jediný soubor bez externích balíčků. Můžeš ho nechat v instalaci nebo
zkopírovat kamkoliv, třeba na jiný počítač. V konfiguraci asistenta pak jen
nastavíš jeho úplnou cestu. V artefaktech vydání je navíc ke stažení také jako
samostatný soubor MCP serveru.

**B) Vývoj ze zdrojáků.** Hodí se, když si chceš nástroje upravovat:

```bash
cd MCP
npm install
```

Server pak běží z `MCP/src/index.mjs` a potřebuje vedle sebe `node_modules`.

> [!NOTE]
> **Sestavení neodstraňuje potřebu Node.** Výsledek je pořád JavaScript, jen bez
> externích závislostí — Node musí být nainstalovaný v obou případech. Odpadá
> jen `npm install` a adresář `node_modules`.

### Krok 3 — registrace u asistenta

Na stránce **Firma → MCP server** vyber v kroku 3 svého asistenta; zobrazí se
hotová konfigurace i s adresou tvojí instance, kterou stačí zkopírovat.

| Asistent | Kam konfigurace patří |
|---|---|
| **Claude Code** (CLI i desktop) | příkaz `claude mcp add` |
| **Claude Desktop** | `claude_desktop_config.json` (Settings → Developer → Edit Config) |
| **ChatGPT přes Codex CLI** | `~/.codex/config.toml` |
| **Gemini CLI** | `~/.gemini/settings.json` |
| **VS Code (Copilot)** | `.vscode/mcp.json` |
| **Cursor** | `.cursor/mcp.json` |

Například pro Claude Code:

```bash
claude mcp add myucto \
  --env MYUCTO_API_URL=https://tvoje-instance.cz/api/v1 \
  --env MYUCTO_API_TOKEN=mi_pat_tvuj_token \
  -- node /cesta/k/myucto.cz/MCP/dist/myucto-mcp.mjs
```

Na stránce v aplikaci se dá přepnout, jestli má konfigurace ukazovat na hotový
`MCP/dist/myucto-mcp.mjs`, nebo na vývojový `MCP/src/index.mjs` — cesta se změní
ve všech ukázkách naráz.

> [!NOTE]
> **Webový ani desktopový ChatGPT tenhle server připojit neumí** — pracuje jen
> se vzdálenými MCP servery přes HTTP, zatímco tenhle běží lokálně. Pro práci
> s daty MyÚčta v prostředí OpenAI použij **Codex CLI**.

### Krok 4 — ověření

Napiš asistentovi „ověř připojení k MyÚčtu“. Zavolá nástroj `whoami` a vrátí
uživatele, roli a firmu. Volání se hned objeví v logu na stránce MCP serveru.

## 101.4 Nastavení

Server se konfiguruje proměnnými prostředí:

| Proměnná | Výchozí | Význam |
|---|---|---|
| `MYUCTO_API_URL` | — | **Povinné.** Adresa API, musí končit `/api/v1`. |
| `MYUCTO_API_TOKEN` | — | **Povinné.** Token `mi_pat_…`. |
| `MYUCTO_SUPPLIER_ID` | — | Firma, se kterou pracovat. Jen u tokenů nevázaných na jednu firmu. |
| `MYUCTO_READ_ONLY` | `0` | `1` = zápisové nástroje se asistentovi vůbec nenabídnou. |
| `MYUCTO_MAX_RPS` | `8` | Nejvýš tolik požadavků za sekundu. |
| `MYUCTO_MAX_CONCURRENT` | `3` | Nejvýš tolik souběžných volání. |
| `MYUCTO_TIMEOUT_MS` | `30000` | Timeout jednoho požadavku. |
| `MYUCTO_SYSTEM_CA` | `1` | Načíst certifikační autority z operačního systému. `0` = nenačítat. |
| `MYUCTO_INSECURE_TLS` | `0` | `1` = vůbec neověřovat HTTPS certifikát. **Jen pro vývojovou instanci.** |

`MYUCTO_READ_ONLY=1` je užitečná pojistka i u tokenu, který právo zápisu má —
zápisové nástroje se v takovém režimu asistentovi ani nezobrazí, takže si
nenaplánuje postup, který by stejně nedokončil.

Stropy `MAX_RPS` a `MAX_CONCURRENT` nejsou kosmetika: API sdílí PHP procesy
s běžícím webem, takže asistent bez omezení zpomalí i běžné uživatele.
Přebytečná volání čekají ve frontě. Nezávisle na nich platí serverový
[rate limit](99_API.md) tokenu.

## 101.5 Příklady dotazů

**Fakturace a pohledávky**

- „Které faktury jsou po splatnosti a kdo nám dluží nejvíc?“
- „Najdi fakturu pro ACME z června a ukaž, jestli je zaplacená.“
- „Vystav fakturu firmě ACME na 10 hodin konzultací po 1 500 Kč.“ *(token čtení a zápis)*

**Odběratelé**

- „Založ klienta podle IČO 45274649.“
- „Najdi v ARES firmu s IČO 12345678 a ukaž mi její adresu.“
- „Uprav Prazdroji telefon na +420 123 456 789.“
- „Přenačti údaje ACME z ARES, přestěhovali se.“

**Výkazy práce a materiálu**

- „Přidej mi do výkazu práce pro AVYX 3 hodiny práce na MCP serveru.“
- „Kolik hodin je zatím ve výkazu na téhle faktuře?“
- „Přidej do výkazu 5 metrů kabeláže po 120 Kč.“
- „Smaž poslední řádek z výkazu, zadal jsem ho omylem.“

Podrobnosti v [§ 101.7](#1017-vykazy-prace-a-materialu).

**Zakázky, dokumenty a kniha jízd**

- „Založ zakázku pro ACME s rozpočtem 200 000 Kč a splatností 14 dní.“ *(token čtení a zápis)*
- „Jaká je ziskovost zakázek od začátku roku a které mají nezaúčtované doklady?“
- „Najdi ve smlouvách zmínku o výpovědní lhůtě a ukaž text dokumentu.“
- „Připoj tu smlouvu k zakázce Web 2026.“ *(token čtení a zápis)*
- „Přidej dnešní služební jízdu Praha–Kolín, 120 km.“ *(asistent si nechá vybrat kategorii; token čtení a zápis)*
- „Zapiš tankování 40 litrů za 1 520 Kč.“ *(token čtení a zápis)*

Podrobnosti v [§ 101.8](#1018-zakazky-dokumenty-a-kniha-jizd).

**Daně**

- „Kolik letos v červenci zaplatíme na DPH?“
- „Jak vychází DPH za tenhle kvartál a co se ještě změní z konceptů?“
- „Z jakých dokladů se skládá DPH za červen?“
- „Kolik letos odvedeme na dani z příjmů a jak jsme na tom se zálohami?“

**Účetnictví**

- „Ukaž obratovou předvahu za letošní období.“
- „Jak se zaúčtovala faktura číslo 2026001?“
- „Co visí v saldu — komu jsme nespárovali platby?“

**Statistika**

- „Ukaž trend obratu a zisku po měsících za poslední rok.“
- „Kde nám utíkají peníze — rozpad nákladů podle kategorií.“
- „Jak moc jsme závislí na největších zákaznících?“
- „Co bych měl dneska řešit?“

**E-shop a sklad**

- „Které zboží je pod minimální zásobou a mělo by se doobjednat?“
- „Kolik máme uloženo ve skladu k dnešnímu dni?“
- „Založ zboží Kabel HDMI 2 m, sazba 21 %, minimální zásoba 10.“ *(token čtení a zápis)*
- „Zdraž všechno zboží značky Acme o pět procent.“ *(token čtení a zápis)*
- „Nasklaď 20 kusů kabelu na hlavní sklad za 89 Kč a příjemku zaúčtuj.“ *(token čtení a zápis)*

Celá kapitola: [§ 101.9](#1019-e-shop-a-sklad).

## 101.6 Odběratelé a ARES

Nového odběratele stačí zadat IČEM:

> „Založ klienta podle IČO 45274649.“

Asistent si vytáhne z **ARES** název, adresu, DIČ i registraci k DPH a kartu
založí. Cokoli řekneš navíc („…a e-mail fakturace@firma.cz“) má přednost před
tím, co vrátí rejstřík — může jít o změnu, která se do ARES ještě nepropsala.

Bez IČO je potřeba název, ulice, město a PSČ; asistent si o ně řekne.

### Ochrana proti duplicitám

Před založením se kontroluje, jestli odběratel se stejným **IČO nebo DIČ** už
neexistuje. Pokud ano, **nic se nezaloží** a asistent ukáže stávající kartu.
Druhou kartu téže firmy lze vytvořit jen vědomě, na výslovné potvrzení.

### Úprava

Stačí říct, co se má změnit — zbytek karty zůstane. Asistent si ji načte,
změnu do ní vloží a uloží celou zpět, takže se nic nevynuluje.

Když se firma přestěhuje nebo přejmenuje, jde údaje přenačíst z rejstříku:

> „Přenačti údaje ACME z ARES.“

Když je ARES nedostupný, u úpravy se **nic nemění** (raději nic než půlka
starých a půlka nových údajů). U zakládání se použijí údaje ze zadání, pokud
stačí — asistent do odpovědi napíše, odkud data vzal.

## 101.7 Výkazy práce a materiálu

Výkaz je navázaný na **koncept faktury** — přesně jako v aplikaci. Stačí tedy říct:

> „Přidej mi do výkazu práce pro AVYX 3 hodiny práce na MCP serveru.“

Asistent zakázku dohledá, najde její koncept faktury a řádek přidá. Existující
řádky zůstanou beze změny.

### Jak se určí hodinová sazba

Sazbu zadávat nemusíš. Doplní se v tomhle pořadí a první nenulová vyhraje:

1. **poslední řádek výkazu** — když už se výkaz jednou vyplnil, nová hodina má
   sedět s ním, ne s ceníkem;
2. **hodinová sazba zakázky**;
3. **hodinová sazba odběratele**;
4. **výchozí hodinová sazba firmy** (Nastavení firmy).

Když sazbu nemá nikdo, asistent to řekne a požádá o ni — netipuje. Vlastní sazbu
lze samozřejmě určit („…3 hodiny po 1 800 Kč“).

### Který doklad se použije

- Pokud řekneš číslo faktury, použije se ta.
- Pokud jmenuješ jen zakázku nebo odběratele, hledá se jeho **koncept** faktury.
- **Je-li konceptů víc, asistent nehádá** — vypíše je a nechá tě vybrat. Zapsat
  hodiny na cizí doklad by bylo horší než se doptat.
- Vystavená faktura je uzamčená; do jejího výkazu se zapsat nedá.

### Materiál

Řádky materiálu fungují stejně (množství, jednotka, cena za jednotku). Jediný
rozdíl: **sazbu DPH materiálu si asistent nevymýšlí.** Převezme ji z už
existujícího výkazu, jinak si o ni řekne — špatná sazba by se propsala do
přiznání k DPH.

## 101.8 Zakázky, dokumenty a kniha jízd

### Zakázky

Asistent umí zakázku založit, upravit, archivovat i bezpečně smazat, pokud ještě
nemá doklady. Při úpravě nejdřív načte současný stav a zachová všechna nezadaná
pole. Změna výchozí kategorie tržby může doplnit tuto kategorii i do dosavadních
faktur zakázky; proto ji zadávej výslovně.

Přehled ziskovosti je **jen ke čtení**. V podvojném účetnictví vychází z deníku,
v daňové evidenci z dokladů, a upozorní i na nezaúčtované doklady. Asistent přes
něj nic nezaúčtuje ani neopraví.

### Dokumenty

MCP umí dokumenty vypsat, hledat v názvu, popisu i vytěženém textu, přečíst
omezený úsek textu, upravit název, popis a tagy a připojit dokument k odběrateli,
dokladu nebo zakázce. Dlouhý text se vrací po částech nejvýše 50 000 znaků.
Platí stejná firemní a osobní oprávnění jako v aplikaci.

Přes tento MCP server se **nenahrávají ani nestahují binární soubory**. PDF,
obrázek nebo ZIP nahraj v aplikaci; asistent pak pracuje s jeho metadaty a
vytěženým textem. Odpojení vazby vyžaduje potvrzení, dokument samotný ale nemaže.

### Kniha jízd

Asistent umí spravovat vozidla, přidávat a upravovat jízdy a tankování a číst
roční souhrn kilometrů a spotřeby. U nové jízdy vyžaduje vozidlo, datum,
vzdálenost nebo oba stavy tachometru a hlavně **výslovně vybranou kategorii**.
Soukromou či služební povahu cesty nikdy neodhaduje — pokud kategorii neřekneš,
nejdřív nabídne číselník a doptá se.

Smazání vozidla, jízdy nebo tankování vyžaduje potvrzení. Používané vozidlo
nelze smazat; lze ho pouze archivovat. Roční daňový souhrn je dostupný jen ke
čtení a žádný účetní zápis z MCP nevytváří.

## 101.9 E-shop a sklad

Na rozdíl od účetnictví je e-shopová a skladová agenda **obousměrná** — asistent
umí katalog nejen číst, ale i zakládat, upravovat a mazat. Důvod je prostý:
skladový pohyb je dohledatelný ve skladové knize a zaúčtovaný doklad jde
stornovat protidokladem, takže se chyba dá v aplikaci napravit. Účetní dopad
vzniká až v účetní vrstvě, která zůstává jen ke čtení.

> [!NOTE]
> Celá tahle agenda je **volitelný modul**. Když ho firma nemá zapnutý,
> nástroje vracejí `403 stock_disabled` — zapíná se v nastavení firmy.

### Co asistent umí

| Oblast | Čtení | Zápis |
|---|---|---|
| **Zboží — skladová karta** | seznam, našeptávač, detail, skladová kniha (pohyby) | založit, upravit (SKU, název, MJ, sazba DPH, minimální zásoba, aktivita), smazat |
| **Zboží — obsah pro e-shop** | karta i s kategoriemi, štítky a parametry; jazykové verze | výrobce, záruka, dodací lhůta, hmotnost, publikace, překlady, kategorie, štítky, parametry, poplatky |
| **Ceny** | ceny po měnách, marže | uložit cenotvorbu (přirážka / pevná cena / zaokrouhlení), vynutit přepočet |
| **Dodavatelé zboží** | seznam s nákupní cenou a dodací lhůtou | nahradit seznam dodavatelů zboží |
| **Nabídky dodavatelů („u dodavatele")** | přehled dvojic zboží × dodavatel napříč katalogem — nákupní cena a měna, kód u dodavatele, dodací lhůta, minimální odběr, balení a množství hlášené dodavatelem | založit a upravit nabídku (upsert podle dvojice zboží × dodavatel), odebrat nabídku |
| **Média** | seznam obrázků a příloh | popisky, pořadí, hlavní obrázek, smazání |
| **Kategorie** | strom, detail, překlady | založit, upravit, přesunout v stromu, uložit překlady, smazat |
| **Číselníky** | výrobci, štítky, typy poplatků, parametry i jejich hodnoty | u všech čtyř založit / upravit / smazat |
| **Sklady** | seznam, detail, hodnota zásob | založit, upravit, smazat |
| **Zásoby** | stav, dostupnost s rezervacemi, sestava stavu, ocenění k datu | — |
| **Množstevní pohledy** | všechny čtyři veličiny najednou (skladem, rezervováno, prodejné, na cestě), rozpad „na cestě" na konkrétní objednávky a rozpad rezervací na konkrétní faktury | — |
| **Doplnění zásob** | návrh, co a kolik doobjednat (zboží pod minimem) | hromadně z návrhu založit objednávky seskupené po dodavatelích |
| **Objednávky u dodavatele** | seznam se stavem a plněním (objednáno / přijato / zbývá), detail s řádky | založit koncept, upravit, odeslat, potvrdit, uzavřít zbytek, stornovat, znovu otevřít, smazat koncept, vytvořit příjemku |
| **Příjemky, výdejky, převodky** | seznam, detail s řádky | založit koncept, upravit, zaúčtovat, stornovat, smazat koncept |
| **Inventury** | seznam, detail s rozdíly | založit, spustit, zapsat napočítané množství, uzavřít |

### Potvrzování nevratných kroků

Mazání, storno dokladu a uzavření inventury vyžadují **výslovné potvrzení**.
První volání takového nástroje záměrně **nic neprovede** — jen vrátí, čeho by se
změna týkala:

> **NEPROVEDENO — chybí potvrzení.** Smazat se má výrobce: `ACME — Acme s.r.o.`
> Operace je nevratná. Ukaž to uživateli a teprve po jeho souhlasu zavolej
> nástroj znovu s `confirm: true`.

Funguje to tedy jako **suchý běh**: uvidíš konkrétní záznam včetně kódu a názvu,
ne jen to, co si asistent myslí, že maže. Teprve druhé volání s potvrzením
operaci provede. U médií a hodnot parametrů se navíc kontroluje, že záznam
opravdu patří ke zboží (resp. parametru), které jsi uvedl — překlep v čísle tak
nesmaže fotku cizímu zboží.

Praktický dopad: **asistent se tě před smazáním vždycky zeptá.** Řetězec „ukliď
nepoužívané štítky“ neproběhne jedním vrzem, ale jako výpis a dotaz.

### Kolekce se nahrazují celé

Ceny, dodavatelé, jazykové verze, kategorie, štítky, parametry a řádky
skladového dokladu se ukládají **jako celek** — co v uloženém seznamu není, to se
smaže. Není to nedostatek nástroje, ale způsob, jakým to ukládá i aplikace.

Nástroje na to asistenta upozorňují a jeho správný postup je: nejdřív si stav
načíst, do něj vložit změnu a poslat zpátky **kompletní** seznam. Když si nejsi
jistý, řekni si o vypsání současného stavu předem:

> „Ukaž ceny toho zboží, pak k nim přidej eurovou cenu s marží 25 %.“

### Skladové doklady mají dvě fáze

Příjemka, výdejka i převodka vznikají jako **koncept**, který se stavem skladu
nedělá nic — teprve zaúčtování pohyb provede, přidělí dokladu číslo a doklad
uzamkne. Nástroje ty dvě fáze schválně nespojují: asistent má doklad připravit
a nechat si ho zkontrolovat, než se zásoby pohnou.

> „Nasklaď 20 kusů kabelu na hlavní sklad za 89 Kč za kus.“
> → asistent založí koncept příjemky a ukáže ti ho.
> „Souhlasím, zaúčtuj.“
> → teprve teď se zásoba zvýší.

Zaúčtovaný doklad už upravit ani smazat nejde, jen **stornovat** — vznikne k němu
opačný protidoklad v původních cenách a oba zůstanou ve skladové knize.

Server sám odmítne (`409`) výdej do minusu, jakýkoli pohyb na skladu
s rozběhnutou inventurou a doklad do uzavřeného účetního období.

### Objednávky u dodavatele

Asistent umí celý životní cyklus objednávky
([§ 33.11](33_Sklad.md#3311-objednavky-u-dodavatele)) — a drží se v něm stejných
pravidel jako aplikace:

- **Nová objednávka vzniká jako koncept.** Nedostane číslo a do „na cestě" se
  nezapočítá. Teprve *odeslat* jí přidělí číslo řady OBJ a zboží se začne počítat
  jako objednané.
- **Úprava objednávky nahrazuje i řádky celé** — platí tu totéž pravidlo jako
  u ostatních kolekcí (viz výše). Upravovat jde jen koncept.
- **Zavřít zbytek, stornovat a smazat vyžadují potvrzení** (jsou nevratné).
  Storno projde jen do doby, než se z objednávky cokoli přijme; potom server
  odmítne s doporučením použít *zavřít zbytek*.
- **Příjem z objednávky založí příjemku jako koncept** — skladem pohne teprve
  její zaúčtování, stejně jako u ručně pořízeného dokladu.
- **Doplnění zásob umí objednat hromadně**: z plochého seznamu zboží a množství
  vznikne **jedna objednávka na dodavatele**, vždy jako koncept. Položky, které
  nešly zařadit (chybí dodavatel, neplatné množství, neznámé zboží), asistent
  dostane zpátky vypsané i s důvodem — nikdy se nezahodí tiše.

Množstevní pohledy jsou jen ke čtení a odpovídají § 33.9: `stock_quantities`
vrací u každé karty **skladem, rezervováno, prodejné a na cestě**,
`stock_in_transit` rozpad na konkrétní objednávky a `stock_reservations` rozpad
na konkrétní faktury.

> „Kolik máme kabelů volných k prodeji a co z toho je jen rezervované?"
> „Co je potřeba doobjednat a od koho?"
> → asistent přečte množstevní pohledy a návrh doplnění, objednávky ale založí
> jako koncepty, které si odsouhlasíš.

### Inventura

Postup kopíruje aplikaci: založit → spustit (udělá se snímek očekávaných stavů
a **sklad se zablokuje** pro zaúčtování dokladů) → zapsat napočítané množství →
uzavřít. Uzavření vygeneruje rozdílovou příjemku na přebytky a výdejku na manka,
rovnou zaúčtované — proto vyžaduje potvrzení a proto asistent před ním hlásí,
kolik řádků zůstalo nespočítaných (ty se přeskočí).

### Co přes MCP nejde

- **Nahrát fotku ke zboží.** Přenos souborů běží mimo formát, se kterým tenhle
  server pracuje. Fotky nahraješ v aplikaci, asistent s nimi pak umí pracovat
  (popisky, pořadí, hlavní obrázek, smazání).
- **Hromadný import zboží z XLSX/CSV** ani **import ceníku dodavatele**. Ze
  stejného důvodu; oba importy mají v aplikaci vlastní průvodce s náhledem.
  Jednotlivé nabídky dodavatelů ale asistent zakládat i upravovat umí.
- **Stáhnout PDF nebo XLSX** skladového dokladu, inventurního soupisu či sestavy.
  Data sestav asistent přečte, hotový soubor si stáhneš v aplikaci.

## 101.10 Log volání

Stránka **Firma → MCP server** má dole **Log volání** — každé volání tvých API
tokenů včetně zamítnutých. U volání z MCP serveru je vidět i **název nástroje**,
takže poznáš, co asistent dělal, ne jen jaké URL zavolal.

Filtruje se podle tokenu, metody, cesty, zdroje a na samotné chyby. Podrobnosti
jsou v [§ 99.8](99_API.md#998-log-volani-api).

## 101.11 Bezpečnost

- Token se ukládá jen jako **SHA-256 hash**; plaintext se zobrazí jednou.
- **Omez token na IP** — uniklý token je pak mimo tvou síť k ničemu.
- **Rozsah `čtení`** stačí na drtivou většinu dotazů; zápis dávej vědomě.
- Bearer token má přístup **jen k veřejnému API**. Správa uživatelů, rolí,
  citlivá nastavení a podpisové profily jsou pro něj nedostupné bez ohledu
  na roli uživatele, který token vydal.
- Token **nedávej do souborů, které commituješ** do gitu (týká se hlavně
  `.vscode/mcp.json` a `.cursor/mcp.json` v projektu).
- Nepoužívaný token **zruš**. Historie volání v logu zůstane.
- **Mazání a storna se neprovedou napoprvé.** Nástroje, které nejdou vzít zpět,
  vyžadují potvrzení a při prvním zavolání jen vypíšou, čeho by se změna týkala
  (viz [§ 101.9](#1019-e-shop-a-sklad)). Je to pojistka proti tomu, aby asistent
  smazal něco, co si domyslel — ne náhrada za `MYUCTO_READ_ONLY=1`, který je
  u nedozorovaného provozu pořád ta správná volba.

## 101.12 Řešení problémů

| Projev | Příčina a náprava |
|---|---|
| Server nenaběhne, hlásí chybnou konfiguraci | `MYUCTO_API_URL` musí končit `/api/v1` a token začínat `mi_pat_`. |
| Asistent hlásí, že **server neodpovídá** | Častou příčinou je nedůvěryhodný HTTPS certifikát — viz [§ 101.13](#10113-vlastni-https-certifikat); současně ověř dostupnost API. |
| `401 invalid_token` | Token je zrušený nebo expirovaný — vygeneruj nový. |
| `403 token_ip_forbidden` | Token má omezení podle IP a tahle adresa mezi nimi není. |
| `403 insufficient_scope` | Token má jen rozsah čtení, operace vyžaduje zápis. |
| `403 token_write_forbidden` | Zápis do účetnictví nebo daní — přes API nikdy, viz [§ 99.6](99_API.md#996-scopes). |
| `403 stock_disabled` | Skladový a e-shopový modul není pro firmu zapnutý. |
| `409` u mazání zboží, výrobce, kategorie, skladu… | Záznam je někde použitý — server ho nepustí. Archivuj ho (`archived`), případně zboží či sklad jen deaktivuj. |
| „NEPROVEDENO — chybí potvrzení“ | Není chyba: takhle vypadá náhled nevratné operace. Zkontroluj výpis a řekni asistentovi, ať to potvrdí. |
| `429` | Překročen limit — sniž `MYUCTO_MAX_RPS`. |
| Asistent nástroje nevidí | Restartuj aplikaci asistenta; u Gemini CLI ověř příkazem `/mcp`. |
| V logu nejsou žádná volání | Server se nespustil — zkontroluj cestu k `index.mjs` a že proběhlo `npm install`. |

## 101.13 Vlastní HTTPS certifikát

Instance s certifikátem od firemní nebo vlastní autority (typicky testovací
prostředí) je zvláštní případ: **Node má vlastní seznam kořenových autorit
a úložiště operačního systému ve výchozím stavu nečte.** Adresa, která
v prohlížeči funguje bez varování, tedy asistentovi spadne — a protože `fetch`
takovou chybu hlásí jako obyčejné selhání spojení, vypadá to, jako by server
neběžel. Přesně tohle je za hláškou *„server momentálně neodpovídá“*.

Server proto **při startu autority ze systému načte sám**. Nainstalovaný root
certifikát tak stačí a nic dalšího nastavovat nemusíš. Co načetl, vypíše na svůj
chybový výstup:

```
MyÚčto MCP připojen — nástroje načteny, API https://…/api/v1; TLS: systémové certifikáty načteny
```

Když spojení i tak selže na certifikát, dostaneš konkrétní hlášku s postupem.
Nejčastější zbylé příčiny:

- **Neúplný řetěz certifikátů.** Server neposílá mezilehlý certifikát —
  projeví se jako `unable to verify the first certificate`. Náprava je na straně
  webserveru, ne klienta.
- **Node starší než 22.15**, který runtime načtení autorit neumí. Přidej do
  konfigurace asistenta `NODE_OPTIONS=--use-system-ca`, případně
  `NODE_EXTRA_CA_CERTS=/cesta/k/ca.pem`.
- **Certifikát není vydaný nainstalovanou autoritou** (jiný self-signed).

Jako poslední možnost — a **výhradně proti vývojové instanci** — jde ověřování
vypnout přes `MYUCTO_INSECURE_TLS=1`. Server na to při startu hlasitě upozorní.
Na produkci to nepoužívej: bez ověření certifikátu jde spojení odposlechnout
i podvrhnout, a token v hlavičce je to první, co útočník získá.
