# MyÚčto MCP server

MCP server (Model Context Protocol) nad veřejným REST API MyÚčta. Zpřístupní
AI klientovi — Claude Code, Claude Desktop, IDE rozšíření — **fakturaci,
mzdové údaje a vstupy, zakázky, dokumenty, knihu jízd, e-shop se skladem a statistiku**.

Uživatelský návod včetně příkladů dotazů je přímo v aplikaci:
**Nastavení firmy → MCP server**. Tenhle soubor je technická poznámka k repozitáři.

## Co server umí a co záměrně ne

| Oblast | Rozsah |
|---|---|
| Fakturace | čtení, vystavování, odesílání, evidence úhrad, upomínky |
| Výkazy práce a materiálu | přidání a odebrání řádků u konceptu faktury, automatická hodinová sazba |
| Zakázky | čtení i zápis — založení, úprava, archivace, rozpočty a ziskovost |
| Dokumenty | metadata, fulltext a omezené čtení vytěženého textu; úprava tagů a vazeb; nahrání a stažení originálu |
| Kniha jízd | čtení i zápis — vozidla, jízdy, tankování; daňový souhrn jen ke čtení |
| Pohledávky a závazky | zaplacené / nezaplacené / po splatnosti, stáří pohledávek |
| Daně | **jen čtení** — odhad DPH (měsíc i kvartál), KH, SH, daň z příjmů, kalendář |
| Účetnictví | **jen čtení** — obratovka, rozvaha, výsledovka, hlavní kniha, saldo, deník |
| Ostatní pohledávky a závazky | čtení; zápis **jen konceptů**: založení, úprava, smazání, splátkový kalendář a opakování bez automatického účtování (`src/other-item-tools.mjs`) |
| SVJ | **jen čtení**: registr a předpisy, karta jednotky s hrubými pohledávkami a oddělenými kredity, doložené role dlužníků a ručitelů, historie úhrad a rekonciliace |
| Dimenze | **jen čtení**: typy a hodnoty, zisk a roční statistika, nepřímé cash flow, filtry výkazů a deníku, přiřazení dokladů a kontrola pravidel |
| Statistika | tržby, zisk, trendy, top odběratelé i dodavatelé, cash flow, platební morálka |
| Všechny firmy | **jen čtení**: manažerské součty za přesné období, vývoj, roční predikce, prognóza toků, zůstatky a rizika přístupných firem přes `group_dashboard`; původní měny i samostatný přepočet CZK |
| E-shop a sklad | **čtení i zápis** — zboží a obsah karet, ceny, dodavatelé, média, kategorie, číselníky, sklady, příjemky/výdejky/převodky, inventury; **jen čtení** — balení, individuální ceny zákazníků, cenové hladiny, nacenění pro odběratele, šarže a sériová čísla, lokace, prodejní objednávky, cyklické inventury |
| Ceník služeb | **jen čtení** — položky, ceny po měnách, individuální ceny zákazníků a výsledná cena (jen firmy bez skladového modulu) |
| Mzdy | čtení osob a výsledků; změna sjednané mzdy od data se zachováním historie, mzdové vstupy, přesčasy a nové absence; schvalování absencí a řízení mzdového běhu, platby, podání a dokumenty jsou zakázané |
| Hledání | globální vyhledávání napříč odběrateli a doklady |

E-shopová a skladová vrstva je naopak **obousměrná** — pohyb je dohledatelný ve
skladové knize a zaúčtovaný doklad jde stornovat protidokladem, takže je chyba
napravitelná v aplikaci. Mazání, storno dokladu a uzavření inventury přesto
vyžadují `confirm: true`: první volání nic neprovede a jen vrátí, čeho by se
změna týkala (`destructive: true` u nástroje, `destructiveHint` v anotaci).
Import zboží z XLSX/CSV v katalogu není, má v aplikaci průvodce s náhledem.

Soubory (`src/file-tools.mjs`): stažení PDF a ISDOC vydané faktury, příloh, PDF
přijaté faktury a originálu dokumentu; nahrání přílohy faktury, PDF přijaté
faktury, dokumentu a obrázku ke zboží a založení přijaté faktury z ISDOC. Klient
skládá multipart z base64 v paměti a stažený soubor vrací jako obrázek, vložený
prostředek nebo base64. Strop je `MYUCTO_MAX_FILE_MB` (výchozí 10 MB), serverový
MCP 5 MB (`McpFileLimits` v PHP).

Účetní a daňová vrstva je **jednosměrná**. Zaúčtování, storno zápisu, uzavření
období, zaevidování opravy podle § 46 / § 74b a odeslání podání na EPO v katalogu
nejsou a nesmí být: jsou to úkony s daňovou odpovědností, kde chyba znamená
opravné podání, a model nemá jak doložit jejich správnost. Vynucuje to i server
(`ApiScopeMiddleware::BEARER_READ_ONLY` → `403 token_write_forbidden`), takže
i kdyby sem někdo takový nástroj přidal, dostane odmítnutí. Zdůvodnění je
v hlavičce `src/tools.mjs`, při rozšiřování katalogu ho neobcházej. Výjimkou
jsou koncepty ostatních pohledávek a závazků (včetně jejich dimenzí a připojené
smlouvy) a analytické účty účtové osnovy
(`create_analytic_account`, `update_analytic_account`), které server povoluje
výčtem metoda a cesta (`BEARER_WRITE_EXCEPTIONS`); do deníku nic nezapisují.
Syntetické účty a mazání účtů zůstávají ve webovém rozhraní.

Karta SVJ (`property_receivable_card`) vyžaduje `unit_id`, `as_of` a `known_at` včetně
času a offsetu. Vrací doložený historický stav podle tehdejší znalosti. Zachovejte
`null`, `precision`, `review_required` a `coverage`: neúplný podklad neznamená nulový
dluh. Popisky osob a jednotek se načítají z aktuálních číselníků. Pohledávky a kredity nesčítejte do jediného čistého salda. `source_snapshot_hash`
je otisk původní doménové projekce před kurátorováním, nikoli odpovědi MCP.

Operace (`list_property_payment_operations`) jsou vzestupné a `next_cursor` patří do
`after_id`; potvrzená workflow (`property_payment_history`) jsou sestupná a kurzor
patří do `before_id`. Obě stránky mají `limit` 1 až 100, výchozí 25. Částky workflow
odpovídají okamžiku potvrzení, nikoli dnešní dostupnosti kreditu. Rekonciliace
(`property_receivable_reconciliation`) používá účetní datum a současnou znalost
účetnictví (`current_ledger`), nepřebírá historickou znalost z karty. Karta a
rekonciliace interně načítají historii celé firmy i při omezení na jednu jednotku.
Karta není stránkovaná a limit operací neomezuje počet jejich alokací. Tyto výstupy
nejsou potvrzením dluhu ani oprávněním k zápisu; ABO soubory a platební dispozice
přes tyto nástroje nejsou dostupné.

## Instalace

Dvě varianty; obě vyžadují **Node ≥ 20** (server běží na globálním `fetch`).
Pokud Node není nainstalovaný: Windows —
`winget install --id OpenJS.NodeJS.LTS --exact`; macOS — `brew install node`.

### A) Hotový build z distribuce — doporučeno pro uživatele

Ve vydané distribuci už je `MCP/dist/myucto-mcp.mjs` přiložený. Nic se
nesestavuje ani neinstaluje; server se spustí přes
`node MCP/dist/myucto-mcp.mjs`. Soubor lze také zkopírovat kamkoliv.

Buildovací příkazy jsou potřeba jen při práci přímo se zdrojovým repozitářem
nebo po úpravě MCP serveru:

```bash
pwsh -File cmd/build-mcp.ps1      # Windows
./cmd/build-mcp.sh                # Linux / macOS
```

Výsledkem je stejný jednosouborový server bez externích závislostí. Odpadá
`npm install` i `node_modules`, a s nimi typická chyba „nainstaloval jsem to
jinam, než ukazuje konfigurace asistenta".

> **Build NEODSTRAŇUJE potřebu Node.** Je to pořád JavaScript, jen bez externích
> závislostí. Samostatný spustitelný soubor bez Node by znamenal přibalit celý
> runtime (desítky MB), což pro tenhle nástroj nedává smysl.

Ve vydaných balíčcích je build přiložený (`MCP/dist/`) a k release je navíc
připnutý jako samostatný asset `myucto-mcp-<verze>.mjs` — viz
`.github/workflows/docker-publish.yml`.

### B) Přímo ze zdrojáků — pro vývoj

```bash
cd MCP
npm install
node src/index.mjs
```

## Registrace v Claude Code

```bash
claude mcp add myucto \
  --env MYUCTO_API_URL=https://ucto.firma.cz/api/v1 \
  --env MYUCTO_API_TOKEN=mi_pat_vas_token \
  -- node /cesta/k/myucto.cz/MCP/dist/myucto-mcp.mjs
```

Token se generuje v aplikaci: **Nastavení firmy → API tokeny → Nový token**.

## Proměnné prostředí

| Proměnná | Povinná | Výchozí | Význam |
|---|---|---|---|
| `MYUCTO_API_URL` | ano | — | Základ API, musí končit `/api/v1` |
| `MYUCTO_API_TOKEN` | ano | — | Token `mi_pat_…` |
| `MYUCTO_SUPPLIER_ID` | ne | — | Firma pro token nevázaný na jednu firmu |
| `MYUCTO_READ_ONLY` | ne | `0` | `1` = zápisové nástroje se vůbec nenabídnou |
| `MYUCTO_MAX_RPS` | ne | `8` | Strop požadavků za sekundu |
| `MYUCTO_MAX_CONCURRENT` | ne | `3` | Souběžná volání |
| `MYUCTO_TIMEOUT_MS` | ne | `30000` | Timeout jednoho požadavku |
| `MYUCTO_MAX_FILE_MB` | ne | `10` | Strop staženého i nahraného souboru v MB (nejvýš 50) |
| `MYUCTO_SYSTEM_CA` | ne | `1` | Načíst certifikační autority z OS; `0` = nenačítat |
| `MYUCTO_INSECURE_TLS` | ne | `0` | `1` = neověřovat HTTPS certifikát (JEN vývoj) |

Stropy `MAX_RPS` / `MAX_CONCURRENT` nejsou kosmetika: API sdílí PHP procesy
s běžícím webem, takže agent bez omezení zpomalí i normální uživatele.
Nezávisle na nich platí serverový rate limit tokenu.

## Vlastní / firemní HTTPS certifikát

Node má vlastní seznam kořenových autorit a systémové úložiště ve výchozím stavu
**nečte** — instance s firemním certifikátem tedy v prohlížeči funguje, ale
`fetch` na ni spadne na `unable to verify the first certificate` a chyba přijde
jako obyčejné selhání spojení („server neodpovídá"). Server proto při startu
systémové autority načte sám (`tls.setDefaultCACertificates`, Node 22.15+)
a výsledek vypíše na stderr:

```
MyÚčto MCP v1.0.0 připojen — 195 nástrojů, API https://…/api/v1; TLS: +134 systémových certifikátů
```

Zbylé příčiny selhání: neúplný řetěz (chybí mezilehlý certifikát — oprava patří
na webserver), starší Node (`NODE_OPTIONS=--use-system-ca` / `NODE_EXTRA_CA_CERTS`),
nebo certifikát vydaný jinou než nainstalovanou autoritou. `MYUCTO_INSECURE_TLS=1`
je až poslední možnost a jen pro vývoj.

## Výkazy práce — poznámka k návrhu

`PUT /invoices/{id}/work-report` **nahrazuje celý výkaz**, ne jen přidává řádek.
Nástroje `add_work_report_entry` / `add_work_report_material` proto výkaz nejdřív
načtou, přidají řádek a pošlou zpět kompletní seznam — jinak by přidání jedné
hodiny smazalo všechno ostatní.

Hodinová sazba se odvozuje v pořadí *poslední řádek výkazu → zakázka → odběratel
→ výchozí sazba firmy* (`resolveHourlyRate`). Nulová hodnota se bere jako
nevyplněno a jde se o úroveň výš. Sazbu DPH materiálu naopak **neodvozujeme** —
buď je ve výkazu, nebo si ji nástroj vyžádá; špatná sazba se propíše do přiznání.

`resolveDraftInvoice` dohledá doklad podle názvu zakázky nebo odběratele.
Při víc než jednom kandidátovi **záměrně selže s výpisem možností** místo výběru
prvního — zápis hodin na cizí doklad je horší než doptání se.

## Struktura

```
src/index.mjs   vstupní bod — konfigurace, MCP handshake
src/local-call.mjs  volání nástroje v lokálním serveru, mapování chyb
src/tool-args.mjs   ověření argumentů proti inputSchema (obě cesty, i serverový MCP)
src/client.mjs  HTTP klient — throttling, retry, hlavičky, serializace query
src/tools.mjs   katalog nástrojů (jediné místo, kam se přidává nový nástroj)
test/           automatické testy katalogu a bezpečných zápisových vzorů
```

Argumenty se před `run()` ověří proti `inputSchema` (`additionalProperties: false`
je povinné), takže `run()` dostane jen deklarované parametry. Hodnotu z argumentů
skládanou do cesty API vždy obal `seg()` z `tool-shared.mjs`.

Přidání nástroje = jeden záznam v `TOOLS`. `write: true` u čehokoli, co mění data —
podle toho se nástroj skryje v režimu jen pro čtení a označí varováním v popisu.
`destructive: true` navíc u mazání a nevratných kroků; k tomu patří `confirm: CONFIRM`
ve schématu a `confirmed()` / `requireConfirm()` v `run()`, které bez potvrzení
místo zápisu vrátí náhled dotčeného záznamu.

Každý nástroj má přímo u definice deklaraci `api`, například
`api: [['GET', '/api/invoices/{id}'], ['PUT', '/api/invoices/{id}/work-report']]`.
Uvádějte všechny operace včetně podmíněného dohledávání v pomocných funkcích;
každá operace mimo GET vyžaduje `write: true`. Nástroje, které nejsou převedené
do klientského katalogu nebo API této instalace nevolají, mají výslovné
`api: false` a `apiReason`. Na klientské doméně se nikdy nenabídnou.

`npm run generate:api` v `MCP/` vytvoří `src/tool-api.generated.json`; tento
manifest se commituje a neupravuje ručně. PHP jej načítá bez Node a podle
stávající mapy API oprávnění, pravidel klientské domény a role uživatele posoudí
každou operaci. Chybějící nebo poškozený manifest znamená prázdný katalog.
Rozsah tokenu dále omezí nabídku v serverovém MCP. Při běhu se všechna práva
a vazba na firmu ověřují znovu v API.

`npm test` kontroluje shodu manifestu s deklaracemi i skutečná volání nástrojů
nad zaznamenávajícím klientem. Pro každou novou větev doplňte syntetický scénář
v `test/api-contracts.test.mjs`. Scénáře musí dojít i k podmíněným operacím a
zápisům za potvrzovacími guardy. V klientském katalogu jsou deklarované dosavadní
klientské nástroje, výkazy práce, sazby DPH, měny, nacenění ceníku, úpravy
přijatých konceptů a nahrávání příloh faktur, PDF přijatých faktur a jejich
strukturovaný import. Ke čtení jsou také zůstatky bankovních účtů, návrhy
párování výpisů, historie faktur ze šablony, hledání zboží, jeho dostupnost
a seznam/detail skladů. Ostatní nástroje zůstávají výslovně vyloučené, dokud
nebudou převedené se stejným ověřením závislostí.

Ploché číselníky (výrobci, štítky, poplatky, parametry) generuje `codebookTools()` —
pětice seznam/detail/založit/upravit/smazat z jedné konfigurace. Další číselník
stejného tvaru je tedy jeden objekt, ne pět nástrojů psaných ručně.

Pro čtení většího výběru katalogu slouží `get_products_batch` (nejvýš 500
karet) a `get_product_prices_batch` (nejvýš 500 kombinací karty a množství).
Oba používají čtecí POST, aby se vešel strukturovaný vstup, ale klient je při
přechodném výpadku opakuje stejně bezpečně jako GET. Stejně jede
`quote_product_prices` (`POST /stock/items/quote`) — cena řádků pro odběratele,
měnu, datum a balení tak, jak ji nacení faktura. Čtecí POST je povolený jen pro
cesty v `READ_POST_PATHS` v `src/client.mjs`; server je pouští i tokenu jen pro
čtení. Běžné POST zápisy se neopakují.

Balení, individuální ceny zákazníků, cenové hladiny, prodejní objednávky,
cyklické inventury a ceník služeb jsou v katalogu jen ke čtení. Jejich zápis
nahrazuje celé sady (ceny karty, pravidla hladiny) nebo hýbe rezervacemi
a doklady; zůstává proto v aplikaci.

`list_products` podporuje výrobce, dodavatele, kategorii, štítky a chybějící
údaje. `get_catalog_facets` vrací počty hodnot filtrů nad celou množinou;
`get_catalog_job` načte průběh a souhrnný výsledek dostupné katalogové úlohy.

## Serverový MCP a Node v kontejneru

Serverový MCP (`/mcp`, OAuth) pouští Node z PHP. Existují dvě cesty:

```
src/hosted-core.mjs    společná logika (katalog, vazba na firmu, volání nástroje)
src/hosted-bridge.mjs  vlastní instalace: Node si interní API volá sám přes PHP CLI
src/hosted-relay.mjs   spravovaná instalace: volání API odbavuje PHP, které Node spustilo
```

Reléová cesta se použije jen při `app.managed = true` a nastaveném
`MYINVOICE_MCP_NODE_BINARY`. Je určená pro hosting, kde Node nesmí běžet vedle PHP
a provozovatel ho zavírá do kontejneru. Proměnná pak ukazuje na spouštěč, který:

- dostane jediný argument, absolutní cestu k `MCP/src/hosted-relay.mjs` dané
  instalace, a v kontejneru spustí `node` s tímto skriptem,
- beze změny propustí standardní vstup, výstup, chybový výstup a návratový kód,
- vidí složku `MCP` instalace pro čtení; nic dalšího z instalace, síť ani PHP
  nepotřebuje (verzi posílá PHP v zadání),
- jde spustit pod uživatelem webu.

PHP navíc potřebuje povolené `proc_open` a PHP CLI; když ho `PhpCliLocator`
nenajde sám, nastaví se `MYINVOICE_MCP_PHP_BINARY`.

Node je v této cestě nedůvěryhodná strana. Token ani hlavičku firmy nedostane,
doplňuje je `ManagedNodeRelay` až k požadavku na interní API podle schváleného
připojení. Protokol je po řádcích JSON a je popsaný v hlavičce `hosted-relay.mjs`.

Lokální zkouška s Dockerem, spouštěč pro Linux:

```sh
#!/bin/sh
exec docker run --rm -i --network none --read-only \
  -v "$(dirname "$(dirname "$1")")":/mcp:ro node:24-slim node /mcp/src/hosted-relay.mjs
```

## Logování

Každý požadavek nese `X-MyUcto-Client: mcp`, `X-MyUcto-Client-Version` a
`X-MyUcto-Tool` s názvem volaného nástroje. Server je zapisuje do
`api_request_log` a aplikace je zobrazuje v **Nastavení firmy → MCP server →
Log volání** — je tedy vidět, který nástroj co volal, včetně zamítnutých pokusů.

## Verze a aktualizace

Verze MCP serveru odpovídá verzi instalace v kořenovém souboru `VERSION` a
zobrazí se při jeho spuštění. Jednosouborový build ji má vloženou přímo v sobě,
takže po zkopírování mimo instalaci žádný další soubor nepotřebuje.

Nástroj `check_update` porovná běžící verzi s posledním stabilním vydáním na
GitHubu. Pokud je při startu dostupná novější verze, server vypíše na stderr
odkaz na asset `myucto-mcp-<verze>.mjs`; síťová chyba spuštění neblokuje. Ruční
volání nástroje případnou nedostupnost GitHubu oznámí jako chybu.

## Bezpečnost

- Token se ukládá jen jako SHA-256 hash; plaintext se zobrazí jednou při vydání.
- Token lze omezit na IP adresy a rozsahy (IPv4 i IPv6) — v aplikaci u tokenu.
- Rozsah `čtení` odmítne jakýkoli zápis už na serveru; `MYUCTO_READ_ONLY`
  je druhá pojistka na straně klienta.
- Bearer token má přístup jen k veřejnému subsetu API — interní správa,
  uživatelé a nastavení jsou pro něj nedostupné bez ohledu na roli.
