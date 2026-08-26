# 98. Aktualizace aplikace

MyÚčto.cz kontroluje GitHub Releases API a v **Systém → Aktualizace**
(jen superadmin) zobrazí běžící sestavení, dostupnou aktualizaci a její poznámky.
Aplikaci lze aktualizovat z uživatelského rozhraní nebo provozním skriptem;
konkrétní postup závisí na typu instalace.

## 98.1 Co všechno se aktualizuje

Aktualizace zahrnuje všechny tři vrstvy aplikace:

- **Backend (PHP)** — `api/vendor/` se přebuilduje (nebo přijde
  představěné v production bundlu), schéma DB se případně migruje
  (`php api/bin/migrate.php`).
- **Frontend (Vue)** — `web/dist/` (Vite produkční build).
- **Manuál** — `manual/generated/*.html` + `manual/manual.pdf`.

Zachovají se `cfg.php`, `cfg.local.php`, `private/`, `storage/` a `log/`, tedy
konfigurace a uživatelská data mimo distribuční balíček. Databázové migrace
mohou schéma i uložená data řízeně převádět, proto před aktualizací vždy
zálohuj databázi i datový adresář.

> 🛈 Aktualizace aplikace se nedotýká licence — ta je vázaná na instalaci a
> zůstává aktivní přes upgrady. Správu licence a předplatného popisuje
> [100. Licence a aktivace](100_Licence_a_aktivace.md).

## 98.2 Pravidelná kontrola — jak funguje

Cron skript `api/bin/cron-version-check.php` se spouští 1× denně, volá
GitHub API a cachuje výsledek do tabulky `app_meta` (klíče
`latest_version`, `latest_release_notes`, `latest_release_url`,
`latest_published_at`, `last_check_at`). UI / footer čte z cache, žádný
blocking síťový call při každém načtení stránky.

### 98.2.1 Plánování cronu

| Prostředí | Příklad |
|-----------|---------|
| Linux/cron | `0 6 * * * cd /opt/myucto && php api/bin/cron-version-check.php` |
| Docker (host cron) | `0 6 * * * docker compose -f /opt/myucto/docker-compose.production.yml exec -T app php api/bin/cron-version-check.php` |
| Windows Scheduler | Daily, akce: `php.exe C:\inetpub\myucto\api\bin\cron-version-check.php` |

Pokud cron nenastavíš, kontrola se nikdy nespustí — admin musí kliknout
**„Zkontrolovat teď"** v UI.

## 98.3 Patička aplikace a upozornění na aktualizaci

V patičce každé stránky vidíš označení právě běžícího sestavení. Pokud je
k dispozici aktualizace a jsi přihlášený jako superadmin, zobrazí se vedle něj
klikací upozornění vedoucí do **Systém → Aktualizace**.

Ostatní uživatelé vidí jen označení běžícího sestavení; upozornění ani ovládání
aktualizace nemají k dispozici.

## 98.4 Aktualizace v UI — Docker

V **Systém → Aktualizace** klikni na tlačítko **Aktualizovat**.
Aplikace zapíše flag soubor `upgrade-requested.json` **uvnitř kontejneru**
do `${MYINVOICE_DATA_DIR}/storage/` (ve standardní Docker instalaci
`/data/storage/`) a UI začne sledovat stav.
**Vlastní
upgrade ale provádí host-side watcher** — proces běžící mimo container,
který má přístup k `docker compose` na hostu a přes `docker compose exec`
čte/píše do storage volume.

### 98.4.1 Test režim (jednorázově, ve foregroundu)

Než nainstaluješ watcher jako daemon, otestuj ho ručně v PowerShell /
bash okně:

```bash
# Linux / macOS
cd /opt/myucto
bash cmd/docker-update-watcher.sh
```

```powershell
# Windows — spusť tím PowerShellem, který máš (uprav cd na SVOU instalační cestu)
cd C:\inetpub\myucto
pwsh -NoProfile -ExecutionPolicy Bypass -File cmd\docker-update-watcher.ps1
# nemáš-li PowerShell 7, použij místo `pwsh` příkaz `powershell` (Windows PS 5.1)
```

> 🛈 Watcher si vlastní update spouští **tímtéž** PowerShell hostem, pod kterým
> běží (`pwsh` i `powershell`), a cesty řeší z umístění skriptu — funguje tedy
> i z jiného adresáře než `C:\inetpub\myucto` a na strojích, kde je jen
> PowerShell 7 (`pwsh`).

Vidíš `[watcher] start, polling storage/upgrade-requested.json inside
container every 30s` — watcher poslouchá. Klikni v UI **„Aktualizovat"**
a do 30 s zachytí flag, spustí `docker-update.{sh,ps1}`, výsledek napíše
do kontejneru. Watcher zastav `Ctrl+C`.

### 98.4.2 Instalace watcheru jako daemon (na produkci)

#### Linux — systemd unit

```bash
sudo tee /etc/systemd/system/myucto-update-watcher.service <<'EOF'
[Unit]
Description=MyUcto update watcher
After=docker.service

[Service]
Type=simple
WorkingDirectory=/opt/myucto
ExecStart=/opt/myucto/cmd/docker-update-watcher.sh
Restart=always
User=root

[Install]
WantedBy=multi-user.target
EOF

sudo systemctl daemon-reload
sudo systemctl enable --now myucto-update-watcher
```

Logy: `journalctl -u myucto-update-watcher -f`.

#### Windows — Scheduled Task

```powershell
# Uprav cestu k SVÉ instalaci. Máš-li jen Windows PowerShell 5.1, nahraď
# `pwsh.exe` za `powershell.exe`.
schtasks /create /tn "MyUcto Update Watcher" `
  /tr "pwsh.exe -NoProfile -ExecutionPolicy Bypass -File C:\inetpub\myucto\cmd\docker-update-watcher.ps1" `
  /sc onstart /ru SYSTEM /rl HIGHEST

# Spusť hned (ne až po restartu)
schtasks /run /tn "MyUcto Update Watcher"
```

> 🛈 `pwsh.exe` musí být v PATH (PowerShell 7 instalátor ji tam dává). Pokud
> Scheduled Task hlásí, že příkaz nenašel, zadej plnou cestu
> `C:\Program Files\PowerShell\7\pwsh.exe`, nebo použij `powershell.exe` (PS 5.1).

Stav úlohy: `schtasks /query /tn "MyUcto Update Watcher" /v /fo list`.
Stop: `schtasks /end /tn "MyUcto Update Watcher"`.

### 98.4.3 Co watcher dělá

1. Každých 30 s: `docker compose exec -T app test -f storage/upgrade-requested.json`.
2. Když ho najde → přečte `target_version` přes `cat`, přejmenuje na
   `upgrade-inflight.json` přes `mv` uvnitř kontejneru (zámek proti
   double-triggeru).
3. Spustí na hostu `cmd/docker-update.{sh,ps1}` — ten dělá:
   - `docker compose pull` (registry mode) nebo `git pull && build` (source mode)
   - `docker compose up -d` (restart stacku)
   - `php api/bin/migrate.php` (pending migrace)
4. Po restartu kontejneru počká až 60 s, než bude zase responzivní
   (`docker compose exec true`), pak zapíše výsledek (success / fail)
   přes `cat > storage/upgrade-result.json` zpět do kontejneru.
5. Plný log běhu na host: `/tmp/myucto-upgrade-YYYYMMDDTHHMMSSZ.log`
   (Linux) nebo `%TEMP%\myucto-upgrade-...log` (Windows).
6. UI v **Systém → Aktualizace** každých 5 s pollne `/api/admin/update/
   status`, který načte `upgrade-result.json` z kontejneru a zobrazí
   „Upgrade úspěšně dokončen" nebo „Upgrade selhal" s message.

### 98.4.4 Pokud watcher neběží

UI sice flag soubor zapíše, ale nikdo ho nezpracuje (UI zůstane věčně
ve stavu „Upgrade probíhá…"). Spusť na hostu ručně:

```bash
# Linux / macOS
cd /opt/myucto
bash cmd/docker-update.sh
docker compose -f docker-compose.production.yml exec app rm -f storage/upgrade-requested.json
```

```powershell
# Windows
cd C:\inetpub\myucto
.\cmd\docker-update.ps1
docker compose -f docker-compose.production.yml exec app rm -f storage/upgrade-requested.json
```

(Pokud nepoužíváš production compose, vynechej `-f docker-compose.production.yml`.)

## 98.5 Persistentní data v Dockeru

Standardní Compose konfigurace ukládá stav aplikace do jediného volume
`app-data:/data` a nastavuje `MYINVOICE_DATA_DIR=/data`. V něm musí zůstat
`cfg.local.php`, `log/`, `storage/` a `private/`; databáze používá samostatný
volume. Aktualizace image ani znovuvytvoření aplikačního kontejneru proto
nesmí tyto soubory odstranit.

Před aktualizací ověř:

1. `docker compose config` ukazuje volume `app-data` připojený do `/data`;
2. datový volume i databáze jsou zahrnuté v záloze;
3. `cfg.local.php` neleží pouze v zapisovatelné vrstvě běžícího kontejneru;
4. na hostu je dost místa pro nový image i zálohu.

Aktualizační skript umí rozpoznat instalaci se samostatnými volumes pro log,
storage a private data a před pokračováním spustí převod přes
`cmd/docker-migrate-volumes.ps1` nebo `cmd/docker-migrate-volumes.sh`. Zdrojové
volumes po převodu nemaže. Odstraň je až po ověření přihlášení, konfigurace,
faktur, nahraných souborů a běhu naplánovaných úloh v aktuálním kontejneru.

## 98.6 Aktualizace v UI — nativní instalace

Nativní deployment (sdílený hosting / VPS bez Dockeru) se aktualizuje
z UI stejně jako Docker — jedním tlačítkem. V **Systém → Aktualizace**
klikni na **Aktualizovat**.

Aplikace stáhne **production bundle** z GitHub release
(`myucto-X.Y.Z.tar.gz`), ověří jeho SHA-256, nasadí ho přes instalaci
a spustí migrace. **Composer, Node ani pnpm na hostu potřeba nejsou** —
bundle má `api/vendor/`, `web/dist/`, `manual/generated/` i
`manual/manual.pdf` už představěné.

### 98.6.1 Co se děje na pozadí

Vlastní práci dělá detached CLI worker `api/bin/native-update.php`
(z UI se spouští automaticky, ručně jde zavolat taky):

```bash
php api/bin/native-update.php --target=X.Y.Z
php api/bin/native-update.php --target=X.Y.Z --preflight   # jen kontrola
```

| Krok | Co dělá |
|------|---------|
| `preflight` | Práva na zápis, volné místo (min. 512 MB), PHP CLI, `phar` + `zlib`, možnost přepsat existující soubor |
| `download` | Stažení assetu z release (jen HTTPS, jen hosty GitHubu) |
| `verify` | SHA-256 proti assetu `.sha256`; při neshodě se bundle smaže a končí se |
| `extract` | Rozbalení do `storage/updates/<verze>/stage/` + validace všech cest v archivu |
| `backup` | Kopie souborů, které se budou přepisovat, do `storage/updates/<verze>/backup/` |
| `swap` | Nakopírování bundlu přes instalaci (nic se nemaže) |
| `migrate` | `php api/bin/migrate.php` už novým kódem |
| `finish` | Až teď se přepíše `VERSION`, uklidí se staging a starší zálohy |

Průběh se zapisuje do `storage/upgrade-requested.json` (krok +
heartbeat), výsledek do `storage/upgrade-result.json` a plný log do
`storage/upgrade-<timestamp>.log`. UI všechny tři čte a ukazuje krok
za krokem.

### 98.6.2 Co zůstane nedotčené

`cfg.php`, `cfg.local.php`, `cfg.docker.php`, `.env`, `storage/`,
`private/`, `log/`, `tmp/` a `.git/` se nikdy nepřepisují — bundle je
ani neobsahuje a swap je navíc přeskakuje. Soubory, které v novém
bundlu nejsou, se **nemažou** (stejné chování jako ruční `tar -xzf`).

`VERSION` se úmyslně přepisuje jako poslední krok: dokud migrace
neproběhnou, instalace se hlásí starou verzí, takže přerušená
aktualizace nevypadá jako dokončená.

> ⚠️ Aktualizace přepisuje soubory běžící aplikace — requesty
> odbavované přesně v ten moment mohou selhat. Na produkci ji spouštěj
> v klidném okně a **měj aktuální zálohu databáze**; migrace samotné
> se vracet nedají.

> 🛈 Pokud máš `opcache.validate_timestamps=0`, restartuj po aktualizaci
> php-fpm / IIS application pool — jinak poběží stará bytecode cache.
> Preflight na to upozorní sám.

### 98.6.3 Bezpečnostní model

Bundle se stahuje po HTTPS jen z hostů GitHubu a kontroluje se jeho
SHA-256. Checksum ale leží ve stejném releasu jako tarball, takže
chrání proti **poškozenému přenosu, ne proti kompromitovanému
repozitáři** — trust root je GitHub účet projektu. Aktualizaci smí
spustit jen superadmin.

### 98.6.4 Když automatická cesta nejde

Sdílený hosting často zakazuje spouštění procesů nebo nemá práva na
zápis do rootu. Preflight to pozná dopředu, vypíše konkrétní důvody
a UI nabídne ruční postup se stejným bundlem:

```bash
curl -LO https://github.com/radekhulan/myucto/releases/download/vX.Y.Z/myucto-X.Y.Z.tar.gz
curl -LO https://github.com/radekhulan/myucto/releases/download/vX.Y.Z/myucto-X.Y.Z.tar.gz.sha256
sha256sum -c myucto-X.Y.Z.tar.gz.sha256
tar -xzf myucto-X.Y.Z.tar.gz --strip-components=1 \
  --exclude='cfg.php' --exclude='cfg.local.php' --exclude='cfg.docker.php' \
  --exclude='storage' --exclude='private' --exclude='log'
php api/bin/migrate.php
```

Ve vývoji (instalace je git checkout) preferuj `git checkout vX.Y.Z` —
bundle by ti jinak zašpinil pracovní kopii. Preflight na to upozorní.

## 98.7 Co když upgrade selže

### 98.7.1 Docker watcher

Watcher zapíše `storage/upgrade-result.json` se `status: "failed"` a
plným logem do `storage/upgrade-YYYYMMDDTHHMMSSZ.log`. UI ho zobrazí.
Typické příčiny:

- **Image pull selhal** — síť, GHCR rate limit, neplatný tag → spusť
  `docker compose pull` ručně, viz log.
- **Migrace selhala** — schéma kolize, missing column → vraťto na
  předchozí tag (`docker compose pull image:OLD-VERSION && up -d`),
  pak řeš migrace.
- **Stack se nezastavuje** — running queries blokují. Restartuj přes
  `docker compose restart app`.

Container s aplikací se restartoval, ale data v DB volume zůstávají
nedotčena.

#### Hodnoty s mezerou v `.env`

Do verze 5.12.0 se `.env` v update skriptech **spouštěl jako shell kód**, takže
hodnota s mezerou bez uvozovek aktualizaci utnula hned na začátku (v logu
zůstala jen hláška typu `.env: line 12: Novak: command not found`):

```bash
# tohle dřív rozbilo autoupdater
MYINVOICE_SMTP_FROM_NAME=Jan Novak
```

Nově se `.env` už jen **parsuje** (`cmd/lib/env-load.{sh,ps1}`), takže
oba zápisy fungují stejně a nic z `.env` se nespouští:

```bash
MYINVOICE_SMTP_FROM_NAME=Jan Novak
MYINVOICE_SMTP_FROM_NAME="Jan Novak"
```

Pravidla parseru — hodnota je celý zbytek řádku za prvním `=`; uvozovky
(jednoduché i dvojité) se sundají; `#` **na začátku řádku** je komentář,
uvnitř hodnoty jen po mezeře (`FOO=bar   # poznámka` → `bar`, `FOO=a#b` →
`a#b`); `$PROMENNA` se **nerozvine**, bere se doslova; CRLF konce řádků
nevadí. Když chceš mít v hodnotě mezeru na kraji nebo znak `#` bez mezery
kolem, dej hodnotu do uvozovek.

> ⚠️ Běžíš-li na starší verzi, kde autoupdater na hodnotě s mezerou padá,
> stačí příslušný řádek `.env` uzavřít do uvozovek a spustit aktualizaci
> znovu.

Selhání kroku aktualizace už také nikdy nekončí tiše — skript vypíše
`ERROR: … AKTUALIZACE NEBYLA DOKONČENA` s číslem řádku a skončí nenulovým
kódem; watcher tenhle důvod propíše i do hlášky v UI.

### 98.7.2 Nativní

Worker zapíše `storage/upgrade-result.json` se `status: "failed"`,
důvodem a cestou k logu `storage/upgrade-<timestamp>.log`. UI to
zobrazí včetně cesty k záloze. Podle kroku, na kterém to spadlo:

- **`download` / `verify` / `extract`** — instalace se vůbec nezměnila,
  nic řešit netřeba. Neshoda SHA-256 znamená poškozené stažení; zkus to
  znovu, případně stáhni bundle ručně.
- **`swap`** — worker sám spustí **rollback** ze zálohy
  `storage/updates/<verze>/backup/` a do logu napíše, kolik souborů
  vrátil. Když rollback část souborů nevrátil (zamčené soubory), obnov
  je odtud ručně:
  ```bash
  cp -r storage/updates/X.Y.Z/backup/. .
  ```
- **`migrate`** — kód je už nasazený, schéma ne. Rollback se
  **záměrně nespouští** (vracet kód pod rozjeté schéma škodí víc).
  Projdi log a dokonči `php api/bin/migrate.php` ručně.

Pokud `migrate.php` selhal, vrátit migraci samotnou nejde — musíš
debugovat konkrétní migraci. Záloha DB je tvoje odpovědnost (kapitola
**§ 16 Exporty**).

Když se worker přestane hlásit (spadl proces), UI po 15 minutách bez
heartbeatu příznak „probíhá" samo zruší a napíše, kde hledat log.

## 98.8 Dohled na nové verze bez UI

Pokud nemáš administrátorský přístup do UI, ale chceš vědět, kdy je
nová verze, můžeš pollovat veřejný endpoint:

```bash
curl -s https://myucto.tvuj-server.cz/api/version | jq
```

Vrátí aktuální označení, poslední dostupné označení, příznak `has_update`
a odkaz `release_url`. Jde o veřejný endpoint bez autentizace; stejné základní
údaje může vidět kdokoli s přístupem k aplikaci v patičce.
