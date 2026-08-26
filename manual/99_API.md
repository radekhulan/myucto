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
> PAT tokenem: popis konkrétní operace může uvádět session-only přístup,
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
   - **Rozsah** — `read` (jen GET) nebo `read & write` (plné API).
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
  "token":    { "id": 42, "name": "Make integrace", "prefix": "mi_pat_abcd", "scope": "read_write", "expires_at": null }
}
```

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
| `read` | `GET`, `HEAD` |
| `read_write` | všechny (POST, PUT, PATCH, DELETE) |

Volání s nedostatečným scopem vrátí `403 insufficient_scope`.

### 99.7.1 Účetnictví a daně jen ke čtení

Nad rámec scopů platí tvrdé pravidlo: **účetní a daňová vrstva je přes API token
jednosměrná**. Čtení funguje normálně, zápis odmítne i token se scope
`read_write` — chybou `403 token_write_forbidden`.

| Cesta | `GET` | zápis |
|---|---|---|
| `/api/v1/accounting/**` | ano | **ne** |
| `/api/v1/reports/**` | ano | **ne** |
| `/api/v1/tax/**`, `/api/v1/tax-evidence/**` | ano | **ne** |

Zaúčtování dokladu, storno zápisu, uzavření období, zaevidování opravy podle
§ 46 / § 74b i odeslání podání na EPO jsou úkony s daňovou odpovědností, kde
chyba znamená opravné podání. Dělají se proto výhradně z webového rozhraní,
kde je vidět kontext a krok se potvrzuje. Integraci ani AI asistentovi to
nebrání v tom podstatném — obratovku, rozvahu, výsledovku, saldo i odhad DPH
si přes API přečtou.

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
