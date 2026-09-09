# 99. REST API (automatizace a integrace)

MyÚčto.cz nabízí veřejné REST API pro integraci s e-shopy, CRM, Make/Zapier
a vlastními skripty. API používá **Personal Access Tokens** (PAT) v hlavičce
`Authorization`.

## 99.1 Dokumentační rozhraní

K dispozici jsou **tři varianty** stejné dokumentace nad jedním OpenAPI specem
(navzájem se prolinkují v horní liště):

| URL | Nástroj | Použití |
|---|---|---|
| **[/api/docs](/api/docs)** | Swagger UI | „Try it out" — vlož API token (Authorize) a volej endpointy přímo z prohlížeče |
| **[/api/reference](/api/reference)** | Redoc | Pretty static reference, 3-sloupcový layout, lepší typografie pro čtení |
| **[/api/scalar](/api/scalar)** | Scalar | Moderní reference s vestavěným API klientem a fulltext vyhledáváním |
| **[/api/openapi.yaml](/api/openapi.yaml)** | Raw OpenAPI 3.1 | Import do Postmana, Insomnie, Zapier Custom App, Make HTTP modulu |

> [!NOTE]
> `openapi.yaml` pokrývá fakturaci a klienty i další dostupné agendy —
> účetnictví (tagy okolo podvojného účetnictví, účtové osnovy, období a deníku),
> sklad (skladové karty, pohyby, doklady, inventury), e-shop (katalog zboží,
> kategorie, číselníky) a klientský portál (agregovaný přehled hospodaření).
> Detailní chování jednotlivých endpointů popisují kapitoly k dané agendě —
> tady jde jen o to, že přes REST API se dá automatizovat i tahle část systému.
> Samotná přítomnost operace ve specifikaci však neznamená, že ji lze zavolat
> PAT tokenem: taková operace odpoví `403 session_required` a v popisu to má
> uvedené. Popis konkrétní operace může uvádět session-only přístup,
> superadmina nebo zákaz zápisu přes bearer token.

---

## 99.2 Vytvoření tokenu

1. Otevři položku **API tokeny** v hlavním menu. Každý uživatel spravuje své
   vlastní tokeny; dostupné firmy a oprávnění se odvozují z jeho účtu.
2. Klikni **Nový token**, vyplň:
   - **Název** — pojmenuj integraci (např. „Make zapier reporting“).
   - **Dodavatel** — když má účet víc firem, vyber, do které firmy token patří.
     Doporučeno; token bound na konkrétního dodavatele nemůže přistupovat
     k datům jiných firem.
   - **Rozsah** — `read` (GET a výslovně čtecí POSTy katalogu) nebo `read & write` (plné API).
   - **Evidence mzdových podání v Dokumentech** — volitelné, výchozí vypnuto.
     Dokumenty navázané na mzdová podání (doručenky datové schránky, protokoly
     ČSSZ, odpovědi zdravotních pojišťoven) jsou pro tokeny normálně neviditelné;
     výpis je přeskočí a detail vrátí `404`. Zaškrtni jen tehdy, když je
     integrace opravdu potřebuje — třeba archivační skript, který je zakládá
     a pak s nimi pracuje. **Zdravotní údaje, exekuce, insolvenci ani výplatní
     pásky tím neodemkneš**, ty zůstávají jen pro přihlášení v prohlížeči.
     Schopnost jde nastavit jen při vytváření tokenu; existujícímu ji doplnit
     nelze, vyrob nový. Jestli ji token opravdu má, poznáš podle štítku
     v přehledu tokenů a podle `allow_payroll_submission_docs` v `api-me`.
   - **Expirace** — volitelná. Bez expirace token platí, dokud ho ručně nezrušíš.
   - **Čerstvé ověření** — použij passkey nebo TOTP. Passkey otevře systémový
     dialog zařízení; TOTP vyžaduje aktuální šestimístný kód. Ověření je
     jednorázové a vázané přímo na vytvoření tokenu.
3. Po vytvoření zobrazíme **plain-text token** (`mi_pat_…`) — **jen jednou**.
   Ulož ho do password manageru, zpětně už ho nezobrazíme.

Samotné přihlášení pomocí MFA nestačí: vytvoření PAT vždy vyžaduje nový
účelový step-up, pokud má účet passkey nebo TOTP. Účet bez jakéhokoli silného
faktoru se místo toho prokáže aktuálním heslem. Proof pro jinou operaci ani
odemčení zamčené PWA token nevytvoří. PAT je bearer credential a serverový
zámek browserové session se na něj nevztahuje; chraň jej vlastní expirací,
minimálním scopem a včasnou revokací.

## 99.3 Použití tokenu

```bash
curl -H "Authorization: Bearer mi_pat_XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX" \
     https://myucto.cz/api/v1/auth/api-me
```

Response:

```json
{
  "user":     { "id": 1, "email": "you@example.com", "name": "Petr", "role": "admin" },
  "supplier": { "id": 1, "company_name": "Acme s.r.o.", "display_name": "Acme" },
  "auth_method": "bearer",
  "token":    {
    "id": 42, "name": "Make integrace", "prefix": "mi_pat_abcd",
    "scope": "read_write", "allow_payroll_submission_docs": false, "expires_at": null
  }
}
```

`allow_payroll_submission_docs` říká, jestli token dostal volitelnou schopnost
z kroku 2. Když integrace nevidí doručenky ani protokoly podání, podívej se
sem dřív, než začneš hledat chybu v datech — `false` znamená zavřený kanál,
ne prázdný výsledek. Přepnout to u hotového tokenu nejde, vyrob nový.

### 99.3.1 Příklady

**Seznam faktur za leden 2026:**
```bash
curl -H "Authorization: Bearer mi_pat_…" \
     "https://myucto.cz/api/v1/invoices?from=2026-01-01&to=2026-01-31"
```

**Vytvoření klienta:**
```bash
curl -X POST https://myucto.cz/api/v1/clients \
     -H "Authorization: Bearer mi_pat_…" \
     -H "Content-Type: application/json" \
     -d '{
       "company_name": "Nový klient s.r.o.",
       "ic": "12345678",
       "street": "Hlavní 1",
       "city": "Praha",
       "zip": "11000",
       "country_id": 1
     }'
```

**Označení faktury jako zaplacené:**
```bash
curl -X POST https://myucto.cz/api/v1/invoices/123/mark-paid \
     -H "Authorization: Bearer mi_pat_…" \
     -H "Content-Type: application/json" \
     -d '{"paid_at": "2026-05-10"}'
```

## 99.4 Verzování

- Stabilní cesta: `/api/v1/...`
- Každá response vrací hlavičku `X-API-Version: 1`.
- Pokud přidáme nekompatibilní změnu, půjde do `/api/v2/...`; v1 zůstane funkční.

## 99.5 Rate limity

- **600 requestů / minutu / token** (defaultně, konfigurovatelně přes
  `cfg.rate_limits.api_per_min_per_token`).
- Při překročení vrátíme `429 Too Many Requests` + `Retry-After: <s>`.

Každá bearer-authed response vrací tyto headers, ať si můžeš self-throttle
před tím, než narazíš na 429:

```
X-RateLimit-Limit:     600         (limit v aktuálním okně)
X-RateLimit-Remaining: 587         (kolik volání ti ještě zbývá)
X-RateLimit-Reset:     42          (sekundy do reset countru)
```

Doporučujeme klienta s retry-with-backoff (`axios-retry`, Retry-After-aware) +
sledovat `X-RateLimit-Remaining` a brzdit, když klesá pod ~10 %.

## 99.6 Multi-supplier

Pokud má účet **víc firem (dodavatelů)**, máš dvě možnosti:

| Token bound na supplier_id (doporučeno) | Token globální |
|---|---|
| Token operuje vždy v kontextu této firmy. | Klient pošle hlavičku `X-Supplier-Id: <id>` u každého requestu. |
| Hlavička `X-Supplier-Id` se ignoruje. | Bez hlavičky = výchozí firma. |
| Token nemůže „skočit“ do jiné firmy = bezpečnější. | Flexibilnější pro power-user skripty. |

## 99.7 Scopes

| Scope | Povolené metody |
|---|---|
| `read` | `GET`, `HEAD` a čtecí dávkové POSTy katalogu |
| `read_write` | všechny (POST, PUT, PATCH, DELETE) |

Volání s nedostatečným scopem vrátí `403 insufficient_scope`. Čtecí POSTy
katalogu jsou výjimka: pouze nesou strukturovaný výběr nebo projekci, data
nemění, proto fungují i s rozsahem `read`.

### 99.7.1 Účetnictví a daně jen ke čtení

Nad rámec scopů platí tvrdé pravidlo: **účetní a daňová vrstva je přes API token
jednosměrná**. Čtení funguje normálně, zápis odmítne i token se scope
`read_write` — chybou `403 token_write_forbidden`.

| Cesta | `GET` | zápis |
|---|---|---|
| `/api/v1/accounting/**` mimo mzdové podcesty | ano | **ne** |
| `/api/v1/accounting/payroll/**`, `/api/v1/accounting/reports/payroll-sheet` | **ne** | **ne** |
| `/api/v1/reports/**` | ano | **ne** |
| `/api/v1/tax/**`, `/api/v1/tax-evidence/**` | ano | **ne** |

Zaúčtování dokladu, storno zápisu, uzavření období, zaevidování opravy podle
§ 46 / § 74b i odeslání podání na EPO jsou úkony s daňovou odpovědností, kde
chyba znamená opravné podání. Dělají se proto výhradně z webového rozhraní,
kde je vidět kontext a krok se potvrzuje. Integraci ani AI asistentovi to
nebrání v tom podstatném — obratovku, rozvahu, výsledovku, saldo i odhad DPH
si přes API přečtou.

Mzdové endpointy jsou kvůli rodným číslům, adresám, mzdovým částkám a dalším
personálním údajům dostupné pouze přihlášenému uživateli ve webové aplikaci.
Bearer token na ně vrátí `403 token_endpoint_forbidden`; veřejný mzdový API
kontrakt zatím neexistuje.

## 99.8 Omezení tokenu podle IP adresy

U každého tokenu lze nastavit **seznam povolených zdrojových adres**. Ve výpisu
tokenů k tomu slouží sloupec **IP omezení**.

- **Prázdný seznam = bez omezení.** Token funguje odkudkoliv. Tak se chovají
  všechny existující tokeny, dokud jim první pravidlo nepřidáš.
- Jakmile přidáš první pravidlo, projdou jen volání z uvedených adres.
  Ostatní dostanou `403 token_ip_forbidden` — a zamítnutí se zapíše do logu
  volání (viz níže), takže je poznat, že někdo zkouší token odjinud.
- Podporované zápisy: **IPv4 i IPv6**, samostatná adresa i CIDR rozsah.

| Zápis | Význam |
|---|---|
| `203.0.113.7` | jediná IPv4 adresa |
| `192.168.1.0/24` | celý rozsah IPv4 |
| `2001:db8::1` | jediná IPv6 adresa |
| `2001:db8::/32` | prefix IPv6 |

Neplatný zápis se neuloží. Kontroluje se i smysluplnost prefixu vůči rodině
adresy, takže `192.168.1.0/64` skončí chybou — jinak by vzniklo pravidlo,
které nikdy nic nepovolí, a token by tiše přestal fungovat.

> [!TIP]
> Když jede integrace z jednoho serveru, omez token na jeho adresu. Uniklý
> token je pak k ničemu komukoli mimo tvou síť.

## 99.9 Log volání API

Každé volání bearer tokenem se zaznamenává — včetně zamítnutých. Výpis najdeš
v **Nastavení firmy → MCP server → Log volání**; vidíš vždy jen volání svých
vlastních tokenů.

U každého záznamu je čas, token, HTTP metoda, cesta, návratový kód, doba
zpracování a zdrojová IP. U volání z MCP serveru navíc **název nástroje**,
který volání vyvolal, takže je poznat záměr, ne jen holá cesta.

Filtrovat jde podle tokenu, metody, cesty, zdroje (jen MCP) a na samotné chyby.
Záznamy se drží **90 dní**, pak je uklidí údržbový cron. Nejde o auditní stopu
podle § 33a — ta žije dál v Aktivitě uživatelů a nemaže se.

## 99.10 Chybové odpovědi

Všechny chyby v unifikovaném formátu:

```json
{ "error": { "code": "validation_failed", "message": "Pole 'name' je povinné." } }
```

| Kód | Význam |
|---|---|
| `unauthenticated` / `invalid_token` | Chybí nebo neplatný token |
| `insufficient_scope` | Token nemá `read_write` |
| `token_endpoint_forbidden` | Endpoint není přes token dostupný (jen z webu) |
| `token_write_forbidden` | Zápis do účetní / daňové vrstvy — přes token nikdy |
| `session_required` | Operaci dělá člověk v aplikaci; token na ni nestačí ani se `read_write` |
| `token_ip_forbidden` | Token není povolen z této IP adresy |
| `validation_failed` | Tělo neprošlo validací |
| `not_found` | Zdroj neexistuje (nebo nepatří aktuálnímu supplier-ovi) |
| `rate_limited` | Překročen limit (viz `Retry-After`) |

## 99.11 Nastavení dodavatele a číslování dokladů přes API

Veřejný subset nastavení dodavatele jde měnit tokenem se scope `read_write`
(uživatel tokenu musí být admin):

- **`PUT /api/v1/settings/supplier`** — částečný update: fakturační údaje,
  defaulty, **číslování dokladů** (`invoice_number_format`,
  `proforma_number_format`, `credit_note_number_format`,
  `purchase_invoice_number_format`, `invoice_number_period`) a **branding**
  (`email_branding_enabled`, `email_accent_color`, `pdf_logo_show_name`,
  `display_name`, `tagline`). Tato pole představují původní nastavení dodavatele,
  které se používá při vypnutých brandingových profilech. Logo se přes tento endpoint nastavit nedá.

- **`PUT /api/v1/settings/supplier/invoice-counter`** — nastaví counter číselné
  řady tak, aby příští vystavený doklad dostal zadané číslo. Hodí se při
  migraci z jiného fakturačního software (navázání na existující řadu):

```bash
curl -X PUT https://mojefirma.example/api/v1/settings/supplier/invoice-counter \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{ "type": "invoice", "next_number": 42 }'
# → { "type": "invoice", "next_number": 42, "counter": 41,
#     "period": "202607", "preview": "2607042" }
```

Counter jde i **snížit**; pokud by nové číslo kolidovalo s už vystaveným
dokladem, vystavení se samoopravně posune na první volné číslo — duplicitní
číslo nikdy nevznikne. Volitelné `date` (YYYY-MM-DD) určuje období řady
(při `invoice_number_period` = `year`/`month`), default je dnešek.

- **`POST /api/v1/settings/supplier/logo`** — multipart upload loga (pole
  `file`; PNG / JPG / SVG / WebP, max 1 MiB). Logo se v e-mailech a PDF
  ukládá do původního nastavení dodavatele a zobrazuje při zapnutém brandingu.
  `DELETE` na stejné cestě
  logo odebere:

```bash
curl -X POST https://mojefirma.example/api/v1/settings/supplier/logo \
  -H "Authorization: Bearer $TOKEN" \
  -F "file=@logo.png"
# → { "logo_path": "storage/supplier-logos/sup-1.png", "width": 480, "height": 160 }
```

## 99.12 Brandingový profil faktury

Po zapnutí modulu brandingových profilů vrací aktivní profily aktuálního
dodavatele read-only endpoint:

```bash
curl -H "Authorization: Bearer $TOKEN" \
  https://mojefirma.example/api/v1/branding-profiles
```

Hodnotu `id` lze poslat při vytvoření konceptu faktury:

```bash
curl -X POST https://mojefirma.example/api/v1/invoices \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "client_id": 123,
    "branding_profile_id": 5,
    "issue_date": "2026-07-20",
    "due_date": "2026-08-03",
    "items": [{
      "description": "Konzultační služby",
      "quantity": 1,
      "unit": "h",
      "unit_price_without_vat": 2500,
      "vat_rate_id": 1
    }]
  }'
```

Profil musí být aktivní a patřit stejnému dodavateli jako klient. Jinak API
vrátí HTTP 400 s kódem `integrity_violation`. Když `branding_profile_id` v těle
chybí nebo je `null`, nový koncept převezme výchozí profil klienta a následně
výchozí profil dodavatele. Není-li žádný nastaven, použije základní identitu.

Při vystavení se výsledná identita včetně cesty k verzi loga uloží do snapshotu
faktury. Pozdější úprava profilu tedy již vystavený doklad nezmění.

## 99.13 Export faktur přes API

- **`GET /api/v1/invoices/export?format=pdf-zip|isdoc|pohoda|stereo|money_s3|csv&month=YYYY-MM`**
  — hromadný export vystavených dokladů za měsíc (nebo
  `period=quarterly&year=YYYY&quarter=1..4`). PDF ZIP, ISDOC, Pohoda, Stereo,
  Money S3 XML nebo CSV; `date_by=tax` zařazuje dle DUZP (shodně s výkazy DPH).
  Jde o stejnou logiku jako na obrazovce **Export / Import → Export vystavených**.
- **`GET /api/v1/invoices/{id}/isdoc`** — ISDOC XML jedné vystavené faktury
  (koncept nelze, 400). PDF je dostupné přes
  `GET /api/v1/invoices/{id}/pdf`.

```bash
curl -H "Authorization: Bearer $TOKEN" -OJ \
  "https://mojefirma.example/api/v1/invoices/export?format=isdoc&month=2026-06"
```

## 99.14 Bezpečnost tokenů — best practices

- **Ukládej token jako secret** (password manager, Make encrypted variable, GitHub Secrets…).
  Nepushuj do gitu.
- **Vyhraď token jedné integraci** — pokud aplikaci přestaneš používat, zruš jen
  tenhle token, ostatní zůstanou funkční.
- **Read-only kde to jde** — reporting do BI nepotřebuje `read_write`.
- **Bound na supplier_id** — minimalizuje radius pádu při kompromitaci.
- **Sleduj `last_used_at`** v UI — token, který se 3 měsíce nepoužil, asi nepotřebuješ.
- **Při ztrátě/podezření** — okamžitě **Zrušit** v UI. Revokace je instantní (žádný cache).
- **Zrušit, nebo Smazat?** Zrušení je bezpečná volba: přístup skončí okamžitě,
  ale token zůstane v přehledu a v logu volání si drží celou historii — je pak
  vidět, co s ním kdo dělal. Mazání je navíc úklid: token z přehledu zmizí
  a jeho volání v logu ztratí přiřazení (zůstanou jako volání bez jména).
  Při podezření na zneužití proto **nejdřív zruš a maž až po prošetření** —
  jinak zahodíš právě ty stopy, které bys potřeboval. Kdo co smazal, zůstane
  v Aktivitě (`api_token.deleted` včetně jména a prefixu).

## 99.15 Co API nepokrývá

- **Session-only a administrační endpointy** mohou být v `openapi.yaml`
  zdokumentované kvůli úplnému kontraktu SPA, ale bearer token je volat nesmí.
  Poznáš je podle popisu operace a případné chyby `token_endpoint_forbidden`.
  Externí integraci stav jen na operacích výslovně dostupných pro PAT; interní
  správu uživatelů, rolí, tokenů a podpisových profilů neautomatizuj.
- **Webhooks** nejsou podporované — pokud potřebuješ notifikaci o platbě, použij polling
  `/api/v1/invoices?status=paid&from=<last_check>`.
- **OAuth2** nepodporujeme — PAT je vědomé zjednodušení pro tenhle typ produktu.
- **Idempotency-Key** není podporován; pokud Make po retry vytváří
  duplicitní záznam, otevři issue.

## 99.16 Dávkové čtení katalogu

### Navigace ve filtrovaném výběru

`POST /api/v1/stock/items/{id}/neighbors` přijímá `filters` stejného tvaru jako
seznam karet a volitelná pole `ids` a `excluded_ids`, každé nejvýše 30 000 ID.
Vrátí `previous_id`, `next_id`, `position` a `total` pro celý filtrovaný výběr.
Prázdné `ids` znamená prázdný výběr, vynechané `ids` všechny vyhovující karty.
Nedostupná karta má nulové sousedy a `position: null`, bez prozrazení cizí firmy.
Endpoint je čtecí a povoluje token se scope `read`.


Pro synchronizaci e-shopu použij čtecí POSTy `POST /api/v1/catalog/products/batch`
a `POST /api/v1/catalog/prices/batch`. Jsou dostupné i tokenu se scope `read`:
POST je zde jen způsob, jak předat větší strukturovaný dotaz, data nemění.

Oba endpointy přijmou nejvýš 500 **unikátních** ID. Výsledek obsahuje položku
pro každé vstupní ID ve stejném pořadí. Karta, která neexistuje nebo nepatří
aktuální firmě, vrátí shodně `{ "status": "unavailable", "data": null }`;
API tak neprozrazuje existenci cizích karet.

### Produkty

`POST /api/v1/catalog/products/batch` očekává alespoň `ids`. Bez `fields`
vrací `sku`, `name`, `ean` a `is_active`; každá dostupná karta má vždy také
`id` a `row_version`. Pole `fields` slouží pro selektivní načtení dalších
sekcí, například `i18n`, `prices` nebo `availability`. Překlady lze omezit
polem `locales` (nejvýš 20 jazyků), ceny polem `currencies` (nejvýš 10 měn) a
dostupnost polem `warehouse_ids` (nejvýš 50 skladů). Vynechané `warehouse_ids`
zahrnou všechny sklady firmy.

Projekce `costs` obsahuje nákladové ocenění a vyžaduje oprávnění
`stock.items.write`; token pouze pro čtení ji nedostane, i když samotný dávkový
endpoint je čtecí.

```json
{
  "ids": [41, 42],
  "fields": ["sku", "name", "i18n", "availability"],
  "locales": ["cs", "en"],
  "warehouse_ids": [3]
}
```

### Efektivní ceny

`POST /api/v1/catalog/prices/batch` přijímá seznam `items` s `id` a volitelným
`qty`. Množství je kladný desetinný **řetězec** s nejvýš 11 číslicemi před a
třemi za desetinnou tečkou, aby integrace neztratila přesnost převodem na
JavaScriptové číslo. Když `qty` vynecháš, API použije `"1"`; `currency` je
výchozí `CZK` a `on_date` dnešní datum. Cena se vyhodnocuje zvlášť pro množství
každé položky. Pokud pro kartu a měnu platná cena není, je cena `null`, nikdy
náhradní nula.

```json
{
  "currency": "EUR",
  "on_date": "2026-09-09",
  "items": [
    { "id": 41, "qty": "2.500" },
    { "id": 42 }
  ]
}
```

## 99.17 Asynchronní export katalogu

`POST /api/v1/catalog/exports` založí JSONL export a vrátí katalogovou úlohu
se stavem `queued` nebo `running`. Jde o třetí čtecí POST katalogu, takže je
dostupný i tokenu se scope `read`. Stav úlohy průběžně načítej přes
`GET /api/v1/eshop/jobs/{id}` a hotový soubor stáhni z
`GET /api/v1/catalog/exports/{id}/download`.

Tělo má `selection` a `projection`. Výběr je buď konkrétní seznam `ids`, nebo
`all_matching: true` s filtry stejného tvaru jako seznam skladových karet a
volitelným `excluded_ids`. Výběr konkrétních ID i vyloučení má strop 30 000
položek. Projekce přijímá stejná pole jako dávkové čtení produktů, kromě
`costs`; náklady export vždy odmítne `403 forbidden_projection`, bez ohledu na
oprávnění tokenu. Výchozí pole jsou `sku`, `name`, `ean`, `is_active`, jazyky
`cs` a měny `CZK`. `locales`, `currencies` a `warehouse_ids` mají stejné limity
20, 10 a 50 jako dávkové čtení.

```json
{
  "selection": {
    "all_matching": true,
    "filters": { "active": true, "availability": "in_stock" },
    "excluded_ids": [42]
  },
  "projection": {
    "fields": ["sku", "name", "i18n", "prices", "availability"],
    "locales": ["cs", "en"],
    "currencies": ["CZK", "EUR"],
    "warehouse_ids": [3]
  }
}
```

Při založení exportu se zmrazí ID a `row_version` každé vybrané karty. Worker
čte obsah po dávkách v konzistentním snapshotu a ke každé hotové kartě zapíše
`captured_at`. Změní-li se karta mezi zařazením a jejím zachycením, výsledný
řádek má `status: "conflict"` a `error_code: "version_conflict"`; export tak
nemíchá starou identitu a nová data. Karta neexistující, cizí nebo nedostupná
při čtení zůstává v souboru se `status: "failed"`, `error_code: "unavailable"`
a `data: null`.

Hotový soubor je `application/x-ndjson` a začíná jedním manifestem:

```json
{"type":"manifest","format_version":1,"job_id":81,"total":2,"consistency":"selection_versions_with_per_batch_snapshot"}
```

Následuje jeden řádek pro každou požadovanou kartu ve zmrazeném pořadí. Každý
má `type: "product"`, `ordinal`, `id`, `status`, `expected_version`,
`error_code`, `captured_at` a `data`. Dokud úloha není `completed`, download
vrací `409 export_not_ready`. ID cizí firmy, neexistující export a jiný typ
úlohy vracejí shodně `404`.
