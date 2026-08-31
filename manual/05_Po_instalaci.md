# 5. Po instalaci a CLI nástroje

Ať jsi instaloval přes [Docker](03_Instalace_Docker.md) nebo
[nativně](04_Instalace_Nativni.md), poslední krok je stejný: otevři aplikaci
v prohlížeči a projdi úvodním průvodcem. Tato kapitola navíc shrnuje CLI
nástroje a plánované úlohy (cron) pro běžnou údržbu.

Pokud převádíš existující instalaci MyInvoice, neprocházej nejdřív setup
wizardem. Použij postup [Převod dat z MyInvoice do MyÚčto](06_Prevod_z_MyInvoice.md),
který zachová správné pořadí importu a MyÚčto migrací.

## 5.1 První spuštění

Otevři aplikaci v prohlížeči (u Dockeru **http://localhost:8080**, u nativní
instalace URL podle web serveru) — naskočí **setup wizard**. Provede tě
založením prvního dodavatele, administrátorského účtu a základní konfigurace.
Detailní popis: [První spuštění (setup wizard)](07_Setup_wizard.md).

## 5.2 Co nastavit hned po prvním přihlášení

- **Dodavatel** — IČO/DIČ, adresa, logo, číslování faktur, bankovní účty
  (Nastavení → Můj dodavatel; detail viz [Nastavení](92_Nastaveni.md)).
- **Odchozí pošta (SMTP)** — aby fungovalo odesílání faktur a upomínek.
- **Daňové nastavení** — typ poplatníka, perioda DPH, kód FÚ (pokud jsi plátce;
  viz [Výkazy DPH](36_Vykazy_DPH.md)).
- **Zabezpečení** — 2FA, IP allowlist, role uživatelů (viz [Bezpečnost](97_Bezpecnost.md)).
- **Plánované úlohy (cron)** — zálohy, párování plateb, upomínky
  (viz [§ 5.5 Cron skripty](#55-cron-skripty)).

## 5.3 Produkční doporučení

- Nasazuj za **HTTPS** (u Dockeru reverse proxy — viz
  [§ 3.8 HTTPS / TLS terminace](03_Instalace_Docker.md#38-https-tls-terminace)).
- Zapni **zálohy** a ověř, že běží (Systém → Plánované úlohy).
- Pinuj konkrétní neměnný release tag image a sleduj [Aktualizace](98_Aktualizace.md).

## 5.4 CLI nástroje

```bash
php api/bin/migrate.php              # spustí pending migrace
php api/bin/migrate.php --status     # vypíše stav migrací
php api/bin/setup.php                # interaktivní úvodní zřízení
php api/bin/sample.php               # vygeneruje testovací data (po setupu)
php api/bin/sample.php --list        # vypíše firmy a jestli už data mají
php api/bin/sample.php --supplier=7  # testovací data do konkrétní prázdné firmy
php api/bin/reset.php                # smaže všechna user-data (vyžaduje "ANO")
php api/bin/recompute-stats.php      # přepočítá agregované statistiky
```

## 5.5 Cron skripty

V `cmd/` jsou připravené `.cmd` (Windows Task Scheduler) i `.sh` (Linux cron)
wrappery. Zvol **právě jeden** způsob plánování:

- **Jeden dispatcher** — naplánuj pouze `cron-dispatch` každou minutu. Sám
  spouští úlohy v jejich časech a levnou kontrolou přeskočí ty, které nemají práci.
- **Jednotlivé úlohy** — naplánuj každý potřebný wrapper samostatně podle tabulky.

Oba režimy nekombinuj, jinak by se některé úlohy spouštěly dvakrát.

| Skript | Doporučená frekvence |
|---|---|
| `cron-cleanup` | 1× denně 03:00 |
| `cron-backup` | 1× denně 02:00 |
| `cron-backup-pdf` | 1× denně 02:30 |
| `cron-backup-documents` | 1× denně 02:35 |
| `cron-bank-scan` | každých 30 min |
| `cron-bank-email-notices` | každých 30 min |
| `cron-scan-purchase-inbox` | každých 10 min |
| `cron-send-reminders` | 1× denně 09:00, Po–Pá |
| `cron-send-approval-reminders` | 1× denně 09:15, Po–Pá |
| `cron-document-request-reminders` | 1× denně 09:30, Po–Pá |
| `cron-epo-status` | každou minutu; jednotlivé pokusy mají vlastní odstup |
| `cron-generate-recurring-invoices` | 1× denně 06:30 |
| `cron-automation-digest` | každou hodinu v ranním okně 06:00–08:00 |
| `cron-ai-worker` | každých 10 min; zpracuje frontu po zapnutí AI asistence |
| `cron-ai-rule-miner` | 1× denně 04:00; vytváří návrhová pravidla z korekcí |
| `cron-payroll-post` | 1× měsíčně 1. dne 04:00; zaúčtuje mzdy za předchozí měsíc |
| `cron-vat-clearing` | 1× měsíčně 1. dne 04:30; interní doklad zúčtování DPH za skončené období ([§ 81.3.3](81_Ucetni_osnova.md#8133-mesicni-zuctovani-dph)) |
| `cron-vat-status-apply` | 1× denně 00:30; aplikuje plánované změny plátcovství DPH v den účinnosti |
| `cron-journal-integrity-check` | 1× denně 02:30; čtecí kontrola integrity deníku |
| `cron-cnb-rates` | 1× denně 15:00; stahuje kurzovní lístek ČNB do kurzové historie a dohání mezery za posledních 30 dnů. Bez ní se kurzy plní jen náhodně při prvním dotazu a cizoměnová úhrada ke dni bez kurzu se nemá čím ocenit |
| `cron-license-renew` | každou hodinu v 15. minutě; server se běžně kontroluje 1× denně, kolem platby a při prodlení 1× za hodinu |
| `cron-version-check` | 1× denně 06:00; kontrola dostupné aktualizace |
| `cron-dispatch` | každou minutu, pouze v režimu jednoho dispatcheru |

Detaily v `cmd/README.md`.

**Šifrování záloh:** volitelné heslo `cron.backup.password` v `cfg.php`
zašifruje všechny tři typy ZIP záloh (DB dump, PDF dokladů, sekce Dokumenty)
algoritmem AES-256. Pro rozbalení použijte 7-Zip, WinRAR nebo `unzip -P` —
vestavěný Průzkumník Windows šifrované AES-256 archivy neumí otevřít. Šifruje
se obsah souborů, názvy souborů uvnitř archivu zůstávají čitelné. Pokud je
heslo nastavené a PHP šifrování nepodporuje (libzip < 1.2), záloha se záměrně
nevytvoří a úloha skončí chybou — nešifrovaná záloha by vznikla jen omylem.

> 💡 **Relativní cesty v `cfg.php`.** Cestové klíče (`cron.backup.output_dir`,
> `storage.*`, `logging.path`, archivy přijatých/importovaných dokladů, DKIM)
> zadané relativně (např. `storage/backup`) se ukotvují k **rootu aplikace**,
> ne k pracovnímu adresáři procesu. Záloha tak skončí na očekávaném místě
> i když cron běží pod Task Schedulerem nebo systémovým cronem s jiným
> aktuálním adresářem. Absolutní cesty (vč. `C:\…` a UNC `\\server`) i
> `MYINVOICE_DATA_DIR` zůstávají beze změny.

**Kontrola, že úlohy běží:** otevři v aplikaci **Systém → Plánované úlohy**.
Každý cron skript si zapisuje vlastní heartbeat do tabulky `cron_runs`
(start, konec, exit code, JSON report). Stránka ukazuje pro každou
doporučenou úlohu kdy naposled úspěšně proběhla, a pokud poslední běh
chybí nebo je starší než `max_age_hours` (typicky 36 h), je tu varování
**Stáří** / **Selhává** / **Neběželo**. Tím se odhalí "cron vůbec není
nastavený" i "cron běží, ale failuje" — bez ohledu na OS (crontab vs.
Task Scheduler vs. Docker host).

**Stav „Nemá práci" (jen režim jednoho dispatcheru).** Dispatcher úlohy, které
mají levnou kontrolu práce (`cron-epo-status`, `cron-ai-worker`), vůbec
nespouští, dokud pro ně není co dělat — jejich poslední běh proto legitimně
stárne. Že plánování funguje, dokládá heartbeat samotného `cron-dispatch`,
a takové úloze se místo varování ukáže neutrální **Nemá práci**. Jakmile
dispatcher sám zestárne nebo začne selhávat, vrátí se u nich normální varování.
