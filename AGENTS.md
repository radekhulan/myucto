# AGENTS.md — pravidla pro AI agenty a přispěvatele

Pokyny pro práci s tímto repozitářem (Claude Code, Codex, Cursor, Copilot a další).
Platí pro celý repozitář. Obecný popis projektu je v [README.md](README.md).

## O projektu

MyUcto.cz — český self-hosted fakturační a účetní systém (vystavené + přijaté
faktury, multi-supplier, DPH/KH/SH výkazy, EPO XML, CRM, REST API).
Backend PHP 8.5 + Slim 4, frontend Vue 3 + TypeScript + Vite + Tailwind,
databáze MariaDB 11.8+.

## Layout repozitáře

- `api/` — PHP backend (Slim, autowired actions, services, repositories); `api/bin/` = CLI skripty, `api/tests/` = PHPUnit
- `web/` — Vue 3 + TS frontend; zdrojáky ve `web/src/`, lokalizace ve `web/src/i18n/`
- `web/dist/` — produkční build frontendu; **gitignorovaný**, staví se lokálně (`pnpm build`) a v CI do release bundlu
- `db/migrations/` — SQL migrace (číslované, idempotentní)
- `manual/` — uživatelský manuál (Markdown, česky); `manual/generated/` = vyrenderované HTML, taky gitignorované
- `tools/` — pomocné skripty (generování manuálu, převody obrázků)
- `cmd/` — cron/deploy wrappery (`.sh` + `.cmd`/`.ps1`)

## Fork MyÚčto.cz — pravidla portování

- Upstream: `git@github.com:radekhulan/myinvoice.git` (remote `upstream`, VEŘEJNÉ) —
  merguj `upstream/master` pravidelně.
- Repozitáře:
  - **`radekhulan/myucto`** — veřejné, **tady běží vývoj i PR přispěvatelů**.
    Jeho historie začíná veřejnou historií MyInvoice po `7dd51638` (4.51.0) a nad
    ní je jeden commit s celým stromem MyÚčta. Díky tomu **sdílí merge base
    s upstreamem**, takže `git merge upstream/master` jde spustit přímo tady.
  - **`radekhulan/myucto-dev`** — privátní ARCHIV původní historie (1600+ commitů,
    reálná data zákazníků). Vývoj proti němu neběží, nepushuje se do něj.
- Proč to takhle: MyInvoice je velmi živý (~1000 commitů/90 dnů), takže ztráta
  merge base by znamenala ruční portování stovek commitů. Squash vlastní historie
  do jednoho commitu skryje interní data, ale předka s upstreamem zachová.
- Kód piš **aditivně**: nové moduly do nových souborů; sdílené soubory jen doplňuj
  malými lokalizovanými změnami, ať zůstane merge z upstreamu levný.
- MyÚčto-specifické migrace čísluj od `1000_` — range `0125`–`0999` je rezervovaný
  pro upstream.
- Namespace `MyInvoice\` a interní identifikátory (env vary, cookie/localStorage/Redis
  klíče) se **NEpřejmenovávají** — mění se jen user-visible branding (UI texty,
  e-maily, dokumentace, loga).
- DB baseline a minimální podporovaná verze je **MariaDB 11.8 LTS**.

## Příkazy

```bash
# Frontend — build (NUTNÉ po každé změně web/src; web/dist/ se necommituje, ale bez buildu neuvidíš změnu v aplikaci)
cd web && pnpm build            # = vue-tsc --noEmit && vite build (npm run build funguje též)
cd web && pnpm type-check       # jen typová kontrola

# PHP testy (PHPUnit 13)
cd api && php vendor/bin/phpunit                  # vše
cd api && php vendor/bin/phpunit --filter Xyz     # podmnožina

# Migrace — VŽDY přes migrate.php, NIKDY mysql klientem přímo
php api/bin/migrate.php
php api/bin/migrate.php --status

# Manuál — po změně manual/*.md regenerovat pouze HTML
php tools/generateManualHtml.php
```

## Tvrdá pravidla

### Migrace
- Nová migrace = nový číslovaný soubor v `db/migrations/`, spouští se **výhradně** přes `php api/bin/migrate.php`.
- Každá migrace musí být **idempotentní** (opakovatelně spustitelná): používej nativní MariaDB `IF [NOT] EXISTS` (`ADD COLUMN IF NOT EXISTS`, `CREATE TABLE IF NOT EXISTS`, …), ne PREPARE/EXECUTE triky.
- Cílová DB je MariaDB 11.8+: v SQL preferuj **window functions a CTE** před vnořenými subselecty; nepoužívej `SQL_CALC_FOUND_ROWS`.
- **Číslo je vždy nejbližší volné, nikdy „s rezervou".** Nové číslo = nejvyšší existující + 1. Nepřeskakuj na kulaté číslo, nenech si mezeru „pro jistotu" a nevolej po vlastním rozsahu — souvislá řada je jediný způsob, jak je poznat pořadí. Sáhne-li po číslech víc agentů naráz, kolizi řeší ten, kdo integruje: přečísluje na nejbližší volné, přejmenuje řádek v tabulce `migrations` v lokálních DB a opraví odkazy na název souboru v testech.
- Migrace se eviduje **podle názvu souboru** (`migrations.filename`), takže přejmenování ji spustí znovu. Právě proto musí být idempotentní. Přečíslovat jde jen migraci, která ještě není nasazená u zákazníků; u nasazené se číslo nemění nikdy.

### i18n
- Veškeré nové UI texty přes `t()` z vue-i18n — **nikdy** natvrdo česky/anglicky v šablonách. Vždy doplň **obě** locale (`web/src/i18n/cs.json` i `en.json`).
- Pole/seznamy překladů přes `tm()` + `rt()` — `t()` pole stringifikuje.
- Literální `{` `}` v textu zprávy escapuj jako `{'{token}'}` — jinak to vue-i18n bere jako interpolaci a render tiše spadne.

### OpenAPI sync
- Při **jakékoli** změně veřejného API (nová route, změna serializace, nový/změněný sloupec promítnutý do JSON, nové query/body pole) ihned aktualizuj `api/openapi.yaml` — jak `paths` (`/api/v1/*`), tak `components/schemas`.
- Po editaci ověř: YAML se parsuje, žádné duplicitní klíče (PyYAML je tiše přepíše — použij striktní loader), žádné dangling `$ref`.
- Veřejné API je kurátorovaný read-only subset; mutace číselníků a interní plumbing se nedokumentují.

### DPH a daňová správnost
- Veškerá evidence DPH jde přes `VatLedgerService` — nikdy neobcházet vlastním SQL.
- Výkazy a rekapitulace sumují **řádky** (`invoice_items` / per-řádkové totály), ne hlavičku dokladu.
- Při zásahu do daní/DPH proaktivně ověř daňovou správnost (zařazení do správného období), ne jen „napojení na existující kód". Kontroluj **symetrii** filtrů: obě strany evidence proti všem typům dokladů (`invoice_type` vs `document_kind`); proforma = záloha na vstupu.
- Každá nová cesta, která tvoří doklad z jiného dokladu (proforma → faktura, dobropis, kopie, recurring), musí přenést `prices_include_vat` — jinak se brutto cena chybně přepočítá jako netto.
- Agregace nákladů z `purchase_invoices` musí vyřadit spárované/zaplacené zálohové doklady (jinak se náklad počítá 2×).
- Dotazy na pohledávky (unpaid/overdue/aging/cashflow) musí mít guard `(invoice_type NOT IN ('invoice','proforma') OR amount_to_pay > 0)` — finální doklad ze zaplacené proformy má `amount_to_pay = 0`.

### Multiplatformnost (Windows / Linux / Docker)
- Veškerý kód musí být spustitelný a testovatelný na **Windows (IIS), Linuxu i v Dockeru** — žádná platformně specifická zkratka, která jinde rozbije běh.
- Proto jsou pomocné skripty záměrně **„zdvojené"**: ke každému `.sh` existuje ekvivalentní `.ps1`/`.cmd` (typicky v `cmd/`). Při změně jednoho udržuj v synchronizaci i druhý; nový skript přidávej rovnou v obou variantách.
- Webserver konfigurace existuje paralelně pro **Apache (`.htaccess`) i IIS (`web.config`)** — změny rewrite pravidel, hlaviček apod. promítej do obou.
- V PHP nepředpokládej konkrétní oddělovač cest ani casing souborového systému (viz guardy níže); rozdíly platforem řeš v kódu, ne podmínkou „jen na Windows".

### Runtime cesty a bezpečnost
- Cesty do `storage/` a `log/` vždy přes `RuntimePaths` (respektuje `MYINVOICE_DATA_DIR`), nikdy `Bootstrap::rootDir()`. Statické assety zůstávají na root dir.
- Path-traversal guardy musí být case-insensitive (Windows `realpath()` vrací nekonzistentní casing — porovnávej `strtolower` obě strany).
- Citlivé údaje (hesla, API klíče, connection stringy) nikdy do kódu, testů ani dokumentace.

### Frontend
- Po každé změně ve `web/src` spusť `pnpm build` — aplikace běží z `web/dist/`, takže bez buildu změnu neuvidíš ani neotestuješ; samotný `vue-tsc` nestačí. Do commitu `web/dist/` nepatří (je gitignorovaný), staví ho CI.
- Drž se existujícího design language (sjednocené boxy, status badges, mobile cards) — před vymýšlením nového vzoru se podívej, jak to dělají sousední stránky.
- **Akční tlačítka — jednotný koncept** (vzor detail faktury): každá akce má **ikonu + sémantickou barvu** dle smyslu (`primary` = hlavní krok, `success` = potvrzení/úhrada, `warning` = upomínka/admin zásah, `danger` = destrukce, `neutral` = utility). Na detailních stránkách přes sdílený `ActionBar`/`ActionItem` (`web/src/components/ui/ActionBar.vue`: 1 plná primární akce dle stavu, sekundární outline, utility/destrukce v „…"). Samostatná tlačítka mimo ActionBar přebírají stejné FILLED/OUTLINE styly a ikony — žádná ad-hoc tlačítka bez ikony a sémantické barvy. Platí i pro sekci Účetnictví a všechny nové stránky.
- Toolbary a skupiny tlačítek vždy s `flex-wrap` (+ `whitespace-nowrap` na tlačítkách) — obsah se při zúžení nesmí mačkat ani přetékat.

## Testy

- PHPUnit 13, testy v `api/tests/{Unit,Integration,Architecture,Invariants}`. Nové chování pokrývej testem; PR nesmí rozbít existující testy.
- **Pouze syntetická testovací data** — repo je veřejné. Žádné reálné doklady, výpisy, IBANy, čísla dokladů ani identifikátory skutečných protistran.
- České bankovní účty v testech musí projít mod-11 validací; ověřený placeholder: `1000000005 / 0100`.
- ISDOC export se validuje proti oficiálnímu XSD (`api/xsd/isdoc-invoice-6.0.2.xsd`).

### Účetní a daňová vrstva — dvě tvrdá pravidla

Obojí si vynutil audit účetního jádra; obojí bylo v repu předtím jako prozaický text
a **stejně se porušilo**. Proto ke každému existuje spustitelná brána.

1. **Nález se neuzavírá bez testu, u kterého jsi OVĚŘIL, že bez opravy padá.**
   Vzniklo tu opakovaně tvrzení, které svítilo zeleně a nekontrolovalo nic (guard
   hledající řetězec, který se vyskytoval i v komentáři; test skipující kvůli prázdné
   testovací DB; křížová kontrola vracející tiše nulu, když nenašla řádek výkazu).
   Zelená bez tohohle kroku není důkaz.
2. **U každého fixu v daňové/účetní vrstvě si polož otázku „kde jinde žije stejný
   koncept?"** → grep → buď sjednotit na SSOT, nebo odlišnost zdůvodnit v komentáři
   u kódu. Pravidlo implementované na jedné větvi a nepropagované na druhou je
   nejčastější třída chyb tohoto jádra.

Praktické důsledky:

- **Výjimku v guardu uděluj SYMBOLU (metodě), nikdy souboru.** Allowlist na úrovni
  souboru vypne kontrolu i pro kód, který s výjimkou nesouvisí.
- **SSOT musí jít ZAVOLAT.** Pravidlo schované jako `private` helper uvnitř jedné akce
  se okopíruje rychleji, než kdyby neexistovalo — vytváří totiž dojem, že existuje.
- **Před commitem do účetní/daňové vrstvy pusť auditní bránu:**
  `pwsh -File cmd/audit-gate.ps1` (Linux: `cmd/audit-gate.sh`). Kroky nad daty jsou
  read-only, takže je lze pustit i proti produkci; `-SkipData` je vynechá.
- **Ne každá odlišnost je drift.** `formatAmount` v EPO výkazech a § 46 vs § 74b jsou
  záměr — guard je má chránit před „sjednocením", ne je slučovat.

## Manuál

- Zdroj v `manual/*.md` (česky). Při změně funkcionality viditelné uživatelem aktualizuj příslušnou kapitolu.
- Piš **jen aktuální stav** — žádné „od verze X.Y.Z", žádné odkazy na historii vývoje.
- Po změně Markdownu regeneruj pouze HTML přes `php tools/generateManualHtml.php`.
  PDF generuj jen na výslovnou žádost uživatele.
- Vzhled manuálu (`manual/manual.css`) zrcadlí design tokeny aplikace (`web/src/styles/main.css`) včetně dark mode — při změně tokenů udržuj synchronizaci.

## Konvence

- Drž se stylu okolního kódu (pojmenování, idiomy, hustota komentářů). Nepřidávej komentáře, které kód jen opakují.
- Commit messages česky, conventional-commits styl: `feat(scope): …`, `fix(scope): …`, `release: X.Y.Z — …` (viz `git log`).
- `VERSION` a poznámky k vydání (`.github/release-notes/vX.Y.Z.md`) mění maintainer při release — v běžném PR na ně nesahej.
- Necommituj vygenerované artefakty. `web/dist/`, `manual/generated/` a `manual/manual.pdf` jsou gitignorované; do release bundlu je staví CI (`.github/workflows/docker-publish.yml`).
