# cmd/ — provozní a deploy skripty

Adresář obsahuje **wrappery a operační skripty** pro plánované úlohy (cron),
Docker provoz a build frontendu. Pro každý skript jsou typicky **dvě varianty**:

- `*.sh` — Linux / macOS (bash)
- `*.cmd` nebo `*.ps1` — Windows (cmd nebo PowerShell)

Skripty samy detekují cestu k projektu (`PROJECT_ROOT`) podle umístění
samotného skriptu, takže jsou přenositelné mezi `C:\inetpub\wwwroot\…`,
`C:\work\…` (junction), `/var/www/…` na Linuxu apod.

### Volba PHP interpretu — `MYINVOICE_PHP_BIN`

Všechny skripty, které volají PHP (cron wrappery, `test.{sh,ps1}`,
`audit-gate.{sh,ps1}`, `release-bundle.sh`, `license-*`, `download-*`,
`install-zp-xsd.*`), defaultně volají `php` z `PATH`. Na hostingu s **víc
nainstalovanými verzemi PHP** nemusí být ta z `PATH` ta správná (nebo tam
žádná není) — nastav proměnnou prostředí, ať skript použije konkrétní
binárku, aniž by se dotýkal `PATH`:

```bash
# Linux / macOS
export MYINVOICE_PHP_BIN=/opt/php8.5/bin/php
cmd/cron-dispatch.sh
```

```powershell
# Windows PowerShell
$env:MYINVOICE_PHP_BIN = 'C:\inetpub\php85\php.exe'
.\cmd\cron-dispatch.cmd
```

```cmd
REM Windows Task Scheduler (proměnnou nastav v definici úlohy, ne globálně)
set MYINVOICE_PHP_BIN=C:\inetpub\php85\php.exe
```

Kdo proměnnou nenastaví, dostane přesně dnešní chování (`php` z `PATH`) —
default se nemění. `download-jmhz-xsd.*`, `download-jmhz-codebooks.*` a
`install-zp-xsd.*` navíc mají fallback na `C:\inetpub\php\php.exe`, pokud
`php` není v `PATH` ani `MYINVOICE_PHP_BIN` není nastavená; `MYINVOICE_PHP_BIN`
má vždy přednost před oběma.

## Přehled všech skriptů

### Cron — plánované úlohy

| Skript | Co dělá |
|---|---|
| `cron-cleanup.{cmd,sh}` | Čištění expirovaných session, starých logů, PDF cache, login_attempts |
| `cron-backup.{cmd,sh}` | mariadb-dump celé DB do `storage/backup/YYYY-MM-DD.zip`, retention 30 dní |
| `cron-backup-pdf.{cmd,sh}` | ZIP všech PDF (`storage/invoices/` + `storage/work-reports/`) do `storage/backup/{dbname}-pdf-YYYY-MM-DD.zip`, stejná retention jako `cron-backup` |
| `cron-backup-documents.{cmd,sh}` | ZIP celé sekce Dokumenty (`storage/documents/`, všechny typy; vynechává `_thumbs`/`_jobs`) do `storage/backup/{dbname}-documents-YYYY-MM-DD.zip`, stejná retention; oddělené od `cron-backup-pdf` (ten Dokumenty nezahrnuje) |
| `cron-bank-scan.{cmd,sh}` | Auto-import nových GPC výpisů z `private/bank-incoming/` + matching plateb na faktury |
| `cron-bank-email-notices.{cmd,sh}` | IMAP polling bankovních e-mailových avíz, parsování plateb a matching na faktury (konfigurace v **Admin → Bankovní účty**) |
| `cron-scan-purchase-inbox.{cmd,sh}` | Import nových přijatých dokladů z nastaveného inbox adresáře |
| `cron-send-reminders.{cmd,sh}` | Odeslání upomínkových e-mailů na faktury po splatnosti (`--days=N`, `--cooldown=N`, `--dry-run`) |
| `cron-send-approval-reminders.{cmd,sh}` | Upomínky zákazníkům, kteří neschválili výkaz víceprací (`--days=N`, `--dry-run`) |
| `cron-document-request-reminders.{cmd,sh}` | Upomínky na nevyřízené požadavky na dodání dokladů |
| `cron-epo-status.{cmd,sh}` | Bezpečné vyzvedávání dodejek a stavů přímých EPO podání s řízeným odstupem; původní podání nikdy neopakuje |
| `cron-jmhz-poll.{cmd,sh}` | Dotažení protokolu ČSSZ k měsíčnímu hlášení a uzavření transakce u VREP; neúspěšný dotaz nikdy neuzavře podání (`--limit=N`) |
| `cron-jmhz-source-monitor.{cmd,ps1,sh}` | Denní read-only sledování veřejných indexů dokumentace JMHZ MPSV/ČSSZ. Do **Systém → Plánované úlohy** ukládá konkrétní nový/změněný dokument, starou a novou verzi, URL a hash; nikdy samo neaktualizuje číselník (`--dry-run`) |
| `cron-generate-recurring-invoices.{cmd,sh}` | Generování faktur ze šablon pravidelné fakturace; volitelné rovnou vystavení a odeslání klientovi (`--dry-run`) |
| `cron-automation-digest.{cmd,sh}` | Ranní souhrn kokpitu Automat podle nastavené hodiny (`--dry-run`, `--hour=N`) |
| `cron-ai-worker.{cmd,sh}` | Zpracování fronty AI návrhů účtování (`--supplier=N`, `--limit=N`, `--dry-run`) |
| `cron-payroll-document-worker.{cmd,ps1,sh}` | Asynchronní generování mzdových PDF po osobách, retry a dokončení měsíčního ZIPu; táhne i frontu ročních dokumentů (mzdový list, potvrzení o zdanitelných příjmech) — měsíční pásky mají přednost (`--limit=N`; PowerShell: `-Limit N`) |
| `cron-payroll-period-export-worker.{cmd,ps1,sh}` | Asynchronní sestavení mzdového exportu období/roku; durable lease a retry (`--limit=N`; PowerShell: `-Limit N`) |
| `cron-ai-rule-miner.{cmd,sh}` | Noční vytěžení návrhových pravidel z potvrzených korekcí (`--supplier=N`, `--days=N`, `--dry-run`) |
| `cron-payroll-post.{cmd,sh}` | **1× měsíčně** — zaúčtuje mzdovou rekapitulaci za předchozí měsíc zaměstnancům, kteří mají na kartě „Účtovat automaticky" a vyplněnou pravidelnou hrubou mzdu (`--dry-run`, `--supplier=ID`, `--period=RRRR-MM`). Jen podvojné účetnictví; datum zápisu je poslední den účtovaného měsíce |
| `cron-vat-clearing.{cmd,sh}` | **1× měsíčně** — interní doklad zúčtování DPH: převede daň zdaňovacího období z analytik 343.100 (vstup) a 343.200 (výstup) na zúčtovací 343.900 (`--dry-run`, `--supplier=ID`, `--period=RRRR-MM`, `--force`). Jen plátci v podvojném účetnictví; datum zápisu je poslední den období, zaúčtování idempotentní |
| `cron-vat-status-apply.{cmd,sh}` | Denní propsání historie plátcovství DPH do živé cache firem (`supplier.is_vat_payer`, `is_identified`) — aplikuje změny plánované s budoucí účinností v den, kdy nastanou (`--dry-run`). Jediný set-based UPDATE, idempotentní |
| `cron-journal-integrity-check.{cmd,sh}` | Noční integrity job nad účetním deníkem (jen podvojné účetnictví) — sirotčí zápisy, Σ MD ≠ Σ D, booked_at bez zápisu a naopak, doklad ≠ zápis částkou. Čistě čtecí, výsledek do dashboardu (`--dry-run`, `--supplier=ID`) |
| `cron-license-renew.{cmd,sh}` | Hodinový tick obnovy licenčního tokenu (E4). Server se běžně volá nejvýše 1× denně, ale od 2 hodin před další platbou do 24 hodin po ní a během `past_due` nejvýše 1× za hodinu |
| `license-activate.{cmd,sh}` | **Headless aktivace licenčního klíče** (E4) bez přihlášení do UI — `license-activate.cmd MYU-XXXX-XXXX-XXXX-XXXX [--takeover]`. Zavolá licenční server, uloží klíč+token, vypíše tarif / počty / platnost (u doživotní licence „Neomezeně"). Hodí se pro demo instance, servery a automatizaci. `--takeover` vynutí přenos vazby z jiné instalace (po chybě `already_bound`) |
| `license-status.{cmd,sh}` | Výpis aktuálního stavu licence (E4) — stav, tarif, počty, platnost, maskovaný klíč |
| `license-deactivate.{cmd,sh}` | Deaktivace licence (E4) — uvolní vazbu na serveru a smaže klíč/token lokálně |
| `cron-cnb-rates.{cmd,sh}` | Denní stažení kurzovního lístku ČNB do `exchange_rates` (`--days=N`, `--dry-run`). Bez ní se tabulka plní jen jako ad-hoc cache prvního dotazu, historie zůstává děravá a cizoměnová úhrada ke dni bez kurzu nemá čím ocenit pohyb. Dohání i mezery za posledních 30 dnů; dny, které kurz už mají, přeskočí bez HTTP volání. Jednorázové doplnění celé historie: `api/bin/backfill-cnb-rates.php` |
| `cron-version-check.{cmd,sh}` | Denní kontrola GitHub Releases API; cachuje poslední dostupnou verzi + release notes pro **Systém → Aktualizace** |
| `cron-storage-usage.{cmd,sh}` | Hodinové měření spotřeby místa instance (velikost databáze z `information_schema` + datový prostor **bez adresáře záloh**) do `instance_storage_usage`. Jediné místo, kde se prochází strom souborů — web i `/api/health` pak čtou hotové číslo. Podklad pro upozornění na 90 % a režim jen pro čtení při vyčerpané kvótě (`--force`, `--json`) |
| `cron-dispatch.{cmd,sh}` | **Plánovač** pro režim „jeden dispatcher" (Systém → Plánované úlohy). Jediná položka běžící každou minutu, která spustí právě ty úlohy, které jsou na řadě a mají co dělat. V default režimu „jednotlivé úlohy" se neplánuje (`--dry-run`, `--at="RRRR-MM-DD HH:MM"`) |

Všechny tři backup ZIPy (DB, PDF, Dokumenty) lze volitelně šifrovat heslem
`cron.backup.password` v `cfg.php` (AES-256). Rozbalení pak vyžaduje 7-Zip /
WinRAR / `unzip -P` — vestavěný Průzkumník Windows AES-256 archivy neotevře.
Pokud je heslo nastavené a PHP ext-zip AES nepodporuje (libzip < 1.2), záloha
se záměrně nevytvoří a úloha skončí chybou (vidět v **Systém → Plánované úlohy**).

### Docker — vývoj v kontejnerech

| Skript | Co dělá |
|---|---|
| `docker-build.{sh,ps1}` | `docker compose build app` — postaví image (default **alpine/nginx** z `Dockerfile.alpine`; volitelné `--no-cache`, `--pull`) |
| `docker-install.{sh,ps1}` | First-run setup: vygeneruje `.env` + `cfg.docker.php`, **preferuje GHCR pull** (lokální build jen s `--build` / `MYINVOICE_INSTALL_MODE=source`), `up -d`, počká na DB healthcheck, spustí migrace, vypíše URL setup wizardu |
| `docker-ghcr.{sh,ps1}` | One-click install **z pre-built image na GHCR** (`ghcr.io/radekhulan/myucto:latest` = alpine/nginx) — žádný local build. Stejně jako install vygeneruje `.env` + `cfg.docker.php`, místo `build` udělá `pull`, pak `up -d` + migrace |
| `docker-update.{sh,ps1}` | Update běžící instance — **detekuje režim z image běžícího kontejneru**: registry (`ghcr.io/...`) → `pull`, lokální build → `git pull` + rebuild; pak `up -d` + migrace + úklid dangling vrstev. Přebití `MYINVOICE_UPDATE_MODE=registry\|source`. (Existující Debian instalace se přechodem `:latest` na alpine zmigrují samy při příštím updatu — drop-in.) |
| `docker-prune-images.{sh,ps1}` | Detekuje a maže **obsolete** myucto image (nepoužívané kontejnerem ani compose) + dangling vrstvy. `--dry-run` / `-DryRun` jen vypíše. Chrání běžící i compose-referencované image |
| `lib/env-load.{sh,ps1}` | Sdílený **parser `.env`** pro skripty výše. `.env` se nikdy nesourcuje ani neevaluuje — hodnota je celý zbytek řádku za prvním `=`, uvozovky se sundají, CRLF nevadí, `$…` se nerozvíjí. Řeší issue #14 (hodnota s mezerou bez uvozovek utnula autoupdater) i spuštění cizího kódu z `.env`. Přímé spuštění `--print` / `-Print <soubor>` vypíše, co skript z `.env` opravdu vyčte |
| `docker-update-watcher.{sh,ps1}` | Host-side daemon (systemd unit / Scheduled Task) — sleduje flag soubor `storage/upgrade-requested.json` (zapisuje UI v **Systém → Aktualizace**) a spustí `docker-update`, výsledek do `upgrade-result.json` |

### Build / deploy / kvalita

| Skript | Co dělá |
|---|---|
| `publish.{sh,ps1}` | `cd web && pnpm install && pnpm build` — produkční build frontendu do `web/dist/` (před commitem nebo nasazením na produkční IIS / Apache) |
| `test.{sh,ps1}`    | `cd api && vendor/bin/phpunit` — spustí testovou sadu (94 testů, ~1 s). Lze passnout filter / testsuite (`cmd/test.sh --filter=GpcParser`) |
| `verify-instance-hardening.{sh,ps1}` | **H-19** — akceptační test hardeningu NASAZENÉ instance (ne repa). Přes HTTP zkouší sadu citlivých URL (`cfg.php`, `api/src/`, `db/`, `storage/`, `private/`, VCS metadata, …) a ověřuje, že každá vrátí 403 nebo 404; k tomu testuje tenantový host gate (neznámý `Host` → `421`, i na přímý `/web/dist/index.html`) a že reverzní proxy nepřepisuje hlavičku `Host`. Jen GET/HEAD, nic nezapisuje. `cmd/verify-instance-hardening.sh --host=www.myucto.cz [--ip=IP] [--json]` / `pwsh -File cmd/verify-instance-hardening.ps1 -InstanceHost www.myucto.cz [-Ip IP] [-Json]`. Detaily a seznam pravidel, na která skript testuje, viz níže |

### `verify-instance-hardening.{sh,ps1}` — co sada ověřuje

Skript testuje účinek, ne znění direktivy: pro každou citlivou URL se dívá, jestli
přijde 403 nebo 404. Pravidla, která na to potřebuje, jsou doplněná ve všech třech
konfiguracích — `.htaccess`, `web.config` i `docker/nginx.conf`:

- jména `cfg.sample.php`, `cfg.docker.php`, `VERSION`, `web.config`
  a `portainer-template.json` (H-19),
- přípony `.cmd`/`.ps1`/`.sh` — pomocné skripty leží s docrootem v kořeni bundlu
  ve veřejném prostoru (`demo.cmd`, `production.cmd`, `docker-entrypoint.sh`)
  a prozrazují cesty i postup nasazení.

⚠️ **Tři konfigurace, jedna smlouva.** H-19 doplnil pravidla jen do `.htaccess`
a `web.config` — na `docker/nginx.conf` se zapomnělo a Docker instance vydávala
`/VERSION` i `/production.cmd` s 200 tam, kde hosting vracel 403. Skript to
neuvidí, pokud ho pustíš jen proti Apache instanci. Statickou paritu všech tří
konfigurací proto hlídá `SensitivePathBlockParityTest` (testsuite Architecture);
při přidání nové citlivé cesty rozšiř seznam v něm i tady.

## Cron — doporučené frekvence

| Skript | Frekvence | Příklad času |
|---|---|---|
| `cron-cleanup` | 1× denně | 03:00 |
| `cron-backup` | 4× denně | `0 2,8,14,20 * * *` (ranní běh před cleanupem) |
| `cron-backup-pdf` | 1× denně | 02:30 (po DB backupu) |
| `cron-backup-documents` | 1× denně | 02:35 (po PDF backupu) |
| `cron-bank-scan` | každých 15–30 minut | `*/30 * * * *` |
| `cron-bank-email-notices` | každých 30 minut | `*/30 * * * *` |
| `cron-scan-purchase-inbox` | každých 10 minut | `*/10 * * * *` |
| `cron-send-reminders` | 1× denně (pracovní dny) | 09:00, Po–Pá |
| `cron-send-approval-reminders` | 1× denně (pracovní dny) | 09:15, Po–Pá |
| `cron-document-request-reminders` | 1× denně (pracovní dny) | 09:30, Po–Pá |
| `cron-epo-status` | každou minutu; jednotlivé pokusy mají vlastní backoff | `* * * * *` |
| `cron-jmhz-poll` | každých 10 minut; odstup dotazů si řídí sám ledger pokusů | `*/10 * * * *` |
| `cron-jmhz-source-monitor` | 1× denně; jen kontroluje veřejné podklady a vypíše diff, nic neaktualizuje | 07:00 |
| `cron-generate-recurring-invoices` | 1× denně | 06:30 |
| `cron-automation-digest` | každou hodinu v ranním okně | 06:00–08:00 |
| `cron-ai-worker` | každých 10 minut | `*/10 * * * *` |
| `cron-payroll-document-worker` | každou minutu | `* * * * *` |
| `cron-payroll-period-export-worker` | každou minutu | `* * * * *` |
| `cron-ai-rule-miner` | 1× denně v noci | 04:00 |
| `cron-vat-status-apply` | 1× denně (po půlnoci) | 00:30 |
| `cron-journal-integrity-check` | 1× denně (v noci) | 02:30 |
| `cron-payroll-post` | 1× měsíčně, 1. den | 04:00 (`0 4 1 * *`) |
| `cron-vat-clearing` | 1× měsíčně, 1. den | 04:30 (`30 4 1 * *`) |
| `cron-license-renew` | 1× za hodinu; síť běžně jen 1× denně | 15. minuta (`15 * * * *`) |
| `cron-cnb-rates` | 1× denně (po vyhlášení kurzu ČNB ~14:30) | 15:00 |
| `cron-version-check` | 1× denně | 06:00 |
| `cron-storage-usage` | 1× za hodinu | 15. minuta (`15 * * * *`) |
| `cron-dispatch` | každou minutu — **jen v režimu dispatcher**, kde nahrazuje všechny položky výše | `* * * * *` |

Logy se ukládají do `log/cron/<nazev>-YYYY-MM-DD.log`. Stav úloh sleduj
v admin/activity-log (každý cron sám zapíše záznam `cron.<nazev>`).

### Vypnutí jednotlivé úlohy — `cron.disabled_jobs`

Na spravovaných instalacích (cizí hosting) dává smysl vypnout úlohy, které
duplikují něco, co si hosting dělá sám — typicky `cron-backup-pdf` a
`cron-backup-documents`, pokud hosting zálohuje celý filesystém a naše
záloha by jen zbytečně žrala kvótu. V `cfg.php` nastav:

```php
'cron' => [
    'disabled_jobs' => ['cron-backup-pdf', 'cron-backup-documents'],
],
```

Jména musí přesně sedět na `script` z katalogu (`CronCatalog::all()`) —
neznámé jméno je považováno za překlep a zaloguje se jako varování, aby se
nedalo přehlédnout. Vypnutá úloha se v přehledu **Systém → Plánované úlohy**
ukáže jako „vypnuto konfigurací" (ne jako zmeškaná) a health-check ji
nepočítá do `stale`/`inactive` poplachů. Vypnutí přebije všechny ostatní
důvody (i chybějící `requires_config` adresář nebo nezapnutou funkci) a
nesahá kvůli tomu do databáze.

⚠️ `cron.disabled_jobs` je (zatím) jediný zdroj pravdy pro **UI přehled a
health check** (`CronJobGate::inactiveReason()`), NE pro skutečné spouštění.
Samotný běh úlohy nastavení zatím nekontroluje:
- **Individuální crontab/Task Scheduler** (podle tabulek výše) spustí skript,
  dokud má vlastní řádek/úlohu zaregistrovanou — vypnutou úlohu z crontabu
  ručně odeber.
- **Docker vestavěný cron** generuje crontab z `CronJobGate::schedulableJobs()`
  (`isSchedulable()`), která `cron.disabled_jobs` zatím nečte — vypnutá úloha
  se tedy v kontejneru pořád naplánuje.
- **Dispatcher** (`cron-dispatch`) taky zatím `cron.disabled_jobs` nekontroluje.

Než se tohle dotáhne (viz TODO u `CronDispatcher`/`DockerCrontabGenerator`),
vypnutí přes `cron.disabled_jobs` je *viditelný signál v UI*, ne tvrdý kill
switch — pro skutečné zastavení běhu vypnutou úlohu ještě vyřaď z
crontabu/Task Scheduleru (nebo z Docker image).

### Windows — Task Scheduler

```cmd
schtasks /create /tn "MyUcto Cleanup"   /tr "C:\inetpub\wwwroot\myucto.cz\cmd\cron-cleanup.cmd"        /sc daily /st 03:00 /ru SYSTEM
schtasks /create /tn "MyUcto Backup"    /tr "C:\inetpub\wwwroot\myucto.cz\cmd\cron-backup.cmd"         /sc daily /st 02:00 /ri 360 /du 24:00 /ru SYSTEM
schtasks /create /tn "MyUcto BackupPDF" /tr "C:\inetpub\wwwroot\myucto.cz\cmd\cron-backup-pdf.cmd"     /sc daily /st 02:30 /ru SYSTEM
schtasks /create /tn "MyUcto BackupDocs" /tr "C:\inetpub\wwwroot\myucto.cz\cmd\cron-backup-documents.cmd" /sc daily /st 02:35 /ru SYSTEM
schtasks /create /tn "MyUcto BankScan"  /tr "C:\inetpub\wwwroot\myucto.cz\cmd\cron-bank-scan.cmd"      /sc minute /mo 30 /ru SYSTEM
schtasks /create /tn "MyUcto BankEmailNotices" /tr "C:\inetpub\wwwroot\myucto.cz\cmd\cron-bank-email-notices.cmd" /sc minute /mo 30 /ru SYSTEM
schtasks /create /tn "MyUcto PurchaseInbox" /tr "C:\inetpub\wwwroot\myucto.cz\cmd\cron-scan-purchase-inbox.cmd" /sc minute /mo 10 /ru SYSTEM
schtasks /create /tn "MyUcto Reminders" /tr "C:\inetpub\wwwroot\myucto.cz\cmd\cron-send-reminders.cmd" /sc weekly /d MON,TUE,WED,THU,FRI /st 09:00 /ru SYSTEM
schtasks /create /tn "MyUcto ApprovalReminders" /tr "C:\inetpub\wwwroot\myucto.cz\cmd\cron-send-approval-reminders.cmd" /sc weekly /d MON,TUE,WED,THU,FRI /st 09:15 /ru SYSTEM
schtasks /create /tn "MyUcto DocumentRequestReminders" /tr "C:\inetpub\wwwroot\myucto.cz\cmd\cron-document-request-reminders.cmd" /sc weekly /d MON,TUE,WED,THU,FRI /st 09:30 /ru SYSTEM
schtasks /create /tn "MyUcto EpoStatus" /tr "C:\inetpub\wwwroot\myucto.cz\cmd\cron-epo-status.cmd" /sc minute /mo 1 /ru SYSTEM
schtasks /create /tn "MyUcto JmhzPoll"  /tr "C:\inetpub\wwwroot\myucto.cz\cmd\cron-jmhz-poll.cmd" /sc minute /mo 10 /ru SYSTEM
schtasks /create /tn "MyUcto JmhzSourceMonitor" /tr "C:\inetpub\wwwroot\myucto.cz\cmd\cron-jmhz-source-monitor.cmd" /sc daily /st 07:00 /ru SYSTEM
schtasks /create /tn "MyUcto Recurring"         /tr "C:\inetpub\wwwroot\myucto.cz\cmd\cron-generate-recurring-invoices.cmd" /sc daily /st 06:30 /ru SYSTEM
schtasks /create /tn "MyUcto AutomationDigest"  /tr "C:\inetpub\wwwroot\myucto.cz\cmd\cron-automation-digest.cmd" /sc hourly /mo 1 /st 06:00 /et 08:59 /ru SYSTEM
schtasks /create /tn "MyUcto AI Worker"         /tr "C:\inetpub\wwwroot\myucto.cz\cmd\cron-ai-worker.cmd" /sc minute /mo 10 /ru SYSTEM
schtasks /create /tn "MyUcto Payroll Documents" /tr "powershell.exe -NoProfile -ExecutionPolicy Bypass -File C:\inetpub\wwwroot\myucto.cz\cmd\cron-payroll-document-worker.ps1" /sc minute /mo 1 /ru SYSTEM
schtasks /create /tn "MyUcto Payroll Period Export" /tr "powershell.exe -NoProfile -ExecutionPolicy Bypass -File C:\inetpub\wwwroot\myucto.cz\cmd\cron-payroll-period-export-worker.ps1" /sc minute /mo 1 /ru SYSTEM
schtasks /create /tn "MyUcto AI Rule Miner"     /tr "C:\inetpub\wwwroot\myucto.cz\cmd\cron-ai-rule-miner.cmd" /sc daily /st 04:00 /ru SYSTEM
schtasks /create /tn "MyUcto VatStatusApply"    /tr "C:\inetpub\wwwroot\myucto.cz\cmd\cron-vat-status-apply.cmd"          /sc daily /st 00:30 /ru SYSTEM
schtasks /create /tn "MyUcto JournalIntegrity"  /tr "C:\inetpub\wwwroot\myucto.cz\cmd\cron-journal-integrity-check.cmd"     /sc daily /st 02:30 /ru SYSTEM
schtasks /create /tn "MyUcto PayrollPost"       /tr "C:\inetpub\wwwroot\myucto.cz\cmd\cron-payroll-post.cmd"             /sc monthly /d 1 /st 04:00 /ru SYSTEM
schtasks /create /tn "MyUcto VatClearing"       /tr "C:\inetpub\wwwroot\myucto.cz\cmd\cron-vat-clearing.cmd"            /sc monthly /d 1 /st 04:30 /ru SYSTEM
schtasks /create /tn "MyUcto LicenseRenew"      /tr "C:\inetpub\wwwroot\myucto.cz\cmd\cron-license-renew.cmd"          /sc hourly /mo 1 /st 00:15 /ru SYSTEM
schtasks /create /tn "MyUcto CnbRates"         /tr "C:\inetpub\wwwroot\myucto.cz\cmd\cron-cnb-rates.cmd"             /sc daily /st 15:00 /ru SYSTEM
schtasks /create /tn "MyUcto VersionCheck"      /tr "C:\inetpub\wwwroot\myucto.cz\cmd\cron-version-check.cmd"           /sc daily /st 06:00 /ru SYSTEM
schtasks /create /tn "MyUcto StorageUsage"      /tr "C:\inetpub\wwwroot\myucto.cz\cmd\cron-storage-usage.cmd"           /sc hourly /mo 1 /ru SYSTEM

REM Režim "jeden dispatcher" — MÍSTO všech úloh výše zaregistruj jen tuhle jednu.
REM Nikdy obojí najednou: úlohy by běžely dvakrát.
REM schtasks /create /tn "MyUcto Dispatch" /tr "C:\inetpub\wwwroot\myucto.cz\cmd\cron-dispatch.cmd" /sc minute /mo 1 /ru SYSTEM
```

> 💡 **Update watcher** (samostatný proces, ne plánovaná úloha) — kontroluje
> flag soubor `storage/upgrade-requested.json` a aplikuje upgrade z UI. Spouští
> se **At startup**, ne na cron:
>
> ```cmd
> schtasks /create /tn "MyUcto UpdateWatcher" ^
>   /tr "powershell.exe -NoProfile -ExecutionPolicy Bypass -File C:\inetpub\wwwroot\myucto.cz\cmd\docker-update-watcher.ps1" ^
>   /sc onstart /ru SYSTEM /rl HIGHEST
> ```

> ⚠️ PHP musí být v `PATH` účtu, pod kterým úloha běží (typicky `SYSTEM`
> nemá uživatelský PATH — ověř `where php` v cmd spuštěném jako SYSTEM přes
> `PsExec -s -i cmd`). Případně nastav proměnnou prostředí `MYINVOICE_PHP_BIN`
> na absolutní cestu k `php.exe` (v definici `schtasks` úlohy, ne globálně) —
> viz [Volba PHP interpretu](#volba-php-interpretu--myinvoice_php_bin) výš.
> Skripty samotné se díky tomu nemusí upravovat.
>
> ⚠️ `cron-backup` potřebuje `mariadb-dump` (nebo `mysqldump`). Skript zkouší
> `PATH` a běžné Windows lokace (`C:\Program Files\MariaDB*\bin`,
> `C:\inetpub\MariaDB\bin`, XAMPP, Laragon). Pokud máš binárku jinde, nastav
> v `cfg.php` (resp. `cfg.docker.php`) absolutní cestu:
> `'db' => ['dump_tool' => 'D:\\mariadb\\bin\\mariadb-dump.exe', ...]`.

### Linux — crontab

Edituj `crontab -e` (nebo `/etc/cron.d/myucto`):

```cron
# m  h  dom mon dow  command
  0  3  *   *   *    /var/www/myucto.cz/cmd/cron-cleanup.sh
  0  2  *   *   *    /var/www/myucto.cz/cmd/cron-backup.sh
 30  2  *   *   *    /var/www/myucto.cz/cmd/cron-backup-pdf.sh
 35  2  *   *   *    /var/www/myucto.cz/cmd/cron-backup-documents.sh
*/30 *  *   *   *    /var/www/myucto.cz/cmd/cron-bank-scan.sh
*/30 *  *   *   *    /var/www/myucto.cz/cmd/cron-bank-email-notices.sh
*/10 *  *   *   *    /var/www/myucto.cz/cmd/cron-scan-purchase-inbox.sh
 0  9  *   *   1-5  /var/www/myucto.cz/cmd/cron-send-reminders.sh
 15  9  *   *   1-5  /var/www/myucto.cz/cmd/cron-send-approval-reminders.sh
 30  9  *   *   1-5  /var/www/myucto.cz/cmd/cron-document-request-reminders.sh
  *  *  *   *   *    /var/www/myucto.cz/cmd/cron-epo-status.sh
*/10 *  *   *   *    /var/www/myucto.cz/cmd/cron-jmhz-poll.sh
  0  7  *   *   *    /var/www/myucto.cz/cmd/cron-jmhz-source-monitor.sh
 30  6  *   *   *    /var/www/myucto.cz/cmd/cron-generate-recurring-invoices.sh
  0  6-8 *   *   *    /var/www/myucto.cz/cmd/cron-automation-digest.sh
*/10 *  *   *   *    /var/www/myucto.cz/cmd/cron-ai-worker.sh
  *  *  *   *   *    /var/www/myucto.cz/cmd/cron-payroll-document-worker.sh
  *  *  *   *   *    /var/www/myucto.cz/cmd/cron-payroll-period-export-worker.sh
  0  4  *   *   *    /var/www/myucto.cz/cmd/cron-ai-rule-miner.sh
 30  0  *   *   *    /var/www/myucto.cz/cmd/cron-vat-status-apply.sh
 30  2  *   *   *    /var/www/myucto.cz/cmd/cron-journal-integrity-check.sh
  0  4  1   *   *    /var/www/myucto.cz/cmd/cron-payroll-post.sh
 30  4  1   *   *    /var/www/myucto.cz/cmd/cron-vat-clearing.sh
 15  *  *   *   *    /var/www/myucto.cz/cmd/cron-license-renew.sh
  0 15  *   *   *    /var/www/myucto.cz/cmd/cron-cnb-rates.sh
  0  6  *   *   *    /var/www/myucto.cz/cmd/cron-version-check.sh
 15  *  *   *   *    /var/www/myucto.cz/cmd/cron-storage-usage.sh
```

`*.sh` skripty musí být spustitelné: `chmod +x cmd/*.sh`.

#### Alternativa: režim „jeden dispatcher"

Přepneš-li v **Systém → Plánované úlohy** režim plánování na „jeden dispatcher",
nahradí se **všechny řádky výše** jediným:

```cron
# m  h  dom mon dow  command
  *  *  *   *   *    /var/www/myucto.cz/cmd/cron-dispatch.sh
```

Dispatcher si každou minutu sám spočítá, které úlohy jsou na řadě, přeskočí ty,
které u téhle instalace nemají co dělat, a zbytek spustí jako samostatné procesy
— izolace mezi úlohami tím zůstává stejná jako u jednotlivých položek.

> ⚠️ **Nikdy obojí najednou.** Běží-li dispatcher vedle jednotlivých položek,
> spustí se každá úloha dvakrát. U `cron-generate-recurring-invoices` nebo
> `cron-payroll-post` to znamená doklady navíc. Dispatcher se proti tomu brání
> dvěma pojistkami (kontroluje nastavený režim a nárokuje si minutu v
> `cron_dispatch_claims`), ale spoléhat se na ně jako na jedinou obranu nemá smysl.

> 💡 **Update watcher** (samostatný proces, ne crontab job) — sleduje flag
> soubor `storage/upgrade-requested.json` a aplikuje upgrade spuštěný
> z UI. Nejjednodušší instalace přes systemd:
>
> ```bash
> sudo tee /etc/systemd/system/myucto-update-watcher.service <<'EOF'
> [Unit]
> Description=MyUcto update watcher
> After=docker.service
>
> [Service]
> Type=simple
> WorkingDirectory=/var/www/myucto.cz
> ExecStart=/var/www/myucto.cz/cmd/docker-update-watcher.sh
> Restart=always
>
> [Install]
> WantedBy=multi-user.target
> EOF
>
> sudo systemctl daemon-reload
> sudo systemctl enable --now myucto-update-watcher
> ```

### Manuální spuštění (debug)

Skripty jsou bezpečné spustit ručně. Pro `cron-send-reminders` je
k dispozici `--dry-run`:

```cmd
cmd\cron-send-reminders.cmd --dry-run
cmd\cron-send-reminders.cmd --days=5 --cooldown=14
pwsh -File cmd\cron-payroll-document-worker.ps1 -Limit 100
pwsh -File cmd\cron-payroll-period-export-worker.ps1 -Limit 1
```

## Docker

V rootu projektu je `Dockerfile` (multi-stage: node → composer → php:8.5-apache)
a `docker-compose.yml` se službami **app** + **db** (MariaDB 11.8) + volitelně
**redis** (profile).

### První spuštění

```bash
# Linux / macOS
cmd/docker-install.sh

# Windows PowerShell
.\cmd\docker-install.ps1
```

Skript je **idempotentní** — bezpečně se dá pustit znovu (existující `.env`
a `cfg.docker.php` přeskočí). Po dokončení běží aplikace na
**http://localhost:8080** a v prohlížeči naskočí setup wizard.

### Rebuild image

```bash
cmd/docker-build.sh --no-cache    # po změnách v Dockerfile / composer.json / pnpm-lock.yaml
cmd/docker-build.sh --pull        # pull nových verzí base images (php:8.5-apache, mariadb:11.8)
```

### One-click instalace z GHCR (bez local buildu)

Pokud nechceš stavět image lokálně (a `pnpm`/`composer` v hostu řešit
vůbec), použij `docker-ghcr` — stáhne pre-built multi-arch image z
[ghcr.io/radekhulan/myucto](https://github.com/radekhulan/myucto/pkgs/container/myucto)
a zbytek (random hesla, `cfg.docker.php`, `up -d`, migrace) je shodný
s `docker-install`:

```bash
# Linux / macOS
cmd/docker-ghcr.sh

# Windows PowerShell
.\cmd\docker-ghcr.ps1
```

Skript používá **`docker-compose.production.yml`** (image-only, žádný
`build:` block), takže další compose příkazy vyžadují flag `-f`:

```bash
docker compose -f docker-compose.production.yml logs -f app
docker compose -f docker-compose.production.yml pull          # update na novější tag
docker compose -f docker-compose.production.yml up -d
docker compose -f docker-compose.production.yml down          # stop (data persist)
```

> 💡 V produkci pinuj konkrétní verzi (`:1.7.0` místo `:latest`)
> editací `docker-compose.production.yml` před prvním `pull`.

Pro **update** běžícího GHCR deploye stačí `cmd/docker-update.sh`
(auto-detekuje registry mode = `pull` + `up -d` + migrace) — viz výše.

### Konfigurace přes `.env`

Vzniká při prvním spuštění install skriptu:

| Proměnná           | Default     | Význam                                                |
|--------------------|-------------|-------------------------------------------------------|
| `APP_PORT`         | `8080`      | Host port pro Apache                                  |
| `DB_PORT`          | `3307`      | Host port pro MariaDB (vázán jen na `127.0.0.1`)      |
| `DB_NAME`          | `myucto`    | Název DB                                              |
| `DB_USER`          | `myucto`    | App user                                              |
| `DB_PASSWORD`      | random      | Heslo app usera (28 znaků base64)                     |
| `DB_ROOT_PASSWORD` | random      | Heslo MariaDB roota                                   |

### Volitelný Redis

```bash
docker compose --profile redis up -d
```

a v `cfg.docker.php` nastavit `redis.enabled => true` (host už je `redis`).

### Daily ops

```bash
docker compose up -d                                       # start
docker compose down                                        # stop (data v named volumes přežijí)
docker compose down -v                                     # stop + WIPE volumes (zničí DB)
docker compose logs -f app                                 # live logs
docker compose exec app bash                               # shell do kontejneru
docker compose exec app php api/bin/migrate.php --status   # cli z hostu
```

### Cron uvnitř kontejneru

Oba image (Debian/Apache i alpine/nginx) mají **vestavěný cron** — crontab se
build-time generuje z `CronCatalog` (`tools/generateDockerCrontab.php`), takže
obsahuje všechny úlohy + frekvence z UI **Systém → Plánované úlohy**. Spouští ho
entrypoint při startu (default `MYINVOICE_ENABLE_CRON=1`; logy v
`${MYINVOICE_DATA_DIR}/log/cron`). Při více replikách app nastav v jedné běžící
`MYINVOICE_ENABLE_CRON=0`, jinak by úlohy běžely vícenásobně.

Vypnutí vestavěného cronu a spouštění z hosta (alternativa):

```cron
0 9 * * 1-5  docker compose -f /opt/myucto/docker-compose.yml exec -T app php api/bin/cron-send-reminders.php
```

## Build / deploy

### `publish.{sh,ps1}` — produkční build frontendu

Spusť před deploy na IIS / Apache (frontend assety v `web/dist/`):

```bash
# Linux / macOS
cmd/publish.sh

# Windows PowerShell
.\cmd\publish.ps1
```

Co dělá (3 kroky):

1. `cd web/`
2. `pnpm install` — synchronizuje `web/node_modules/` s `pnpm-lock.yaml`
3. `pnpm build` — Vite build do `web/dist/` (s production optimalizacemi,
   tree-shaking, minifikací)

> 💡 `web/dist/` je v `.gitignore` — produkce si build dělá sama (tj. po
> `git pull` na produkci spusť `cmd/publish.sh`). Alternativně lze build
> commitnout (vyžaduje úpravu `.gitignore`).

> ⚠️ Vyžaduje `pnpm` v PATH. Instalace: `npm install -g pnpm`.

### `test.{sh,ps1}` — PHPUnit testy

```bash
# Linux / macOS
cmd/test.sh                            # všechny testy
cmd/test.sh --testsuite=Unit           # jen unit
cmd/test.sh --filter=GpcParser         # jen testy s názvem obsahujícím "GpcParser"

# Windows PowerShell
.\cmd\test.ps1
.\cmd\test.ps1 --filter=InvoiceMath
```

Pokrývá: GpcParser, InvoiceMath, AccountNumberNormalizer, SupplierGuard,
TurnstileVerifier, SecretEncryption, TotpService, IpMatcher, varsymbol +
month-increment helpers, error catalog. Integration test: unauthenticated
access (smoke check že middleware blokuje bez session).

### Údržba číselníků — spouští se ručně

| Skript | Co dělá |
|---|---|
| `download-okec.{cmd,sh}` | Aktualizuje snapshot číselníku ČINNOSTI (CZ-NACE / `c_okec`) v `api/resources/ciselniky/okec.txt` z rozhraní číselníků Daňového portálu. Proti němu jede kanonizace CZ-NACE v přiznání k DPH. |

**Není to cron úloha** — číselník se mění zřídka (poslední velká změna: přechod
na NACE rev. 2.1 k 1. 1. 2026). Pouštěj ručně, `--dry-run` napřed jen porovná
a vypíše rozdíl; výsledek zkontroluj přes `git diff` a commitni. Zastaralý
snapshot nic neblokuje — kód mimo něj se uloží i odešle, jen s upozorněním.

## Konvence

- **Návratový kód 0** = OK, **non-zero** = chyba (vhodné pro shell pipes
  i Task Scheduler trigger).
- **`set -euo pipefail`** ve všech `.sh` (strict mode — fail fast).
- **`$ErrorActionPreference = 'Stop'`** ve všech `.ps1`.
- **PROJECT_ROOT** vždy resolvuju z `dirname` skriptu — žádné absolutní cesty
  v kódu.
- **Žádný `cd $HOME`** — pracuje se relativně k umístění skriptu, ne CWD volajícího.
