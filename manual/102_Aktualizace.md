# 102. Aktualizace aplikace

> Návod pro správce instalace: jak zjistit, že je dostupná nová verze, jak
> aplikaci aktualizovat (Docker i nativní instalace), co dělat, když
> aktualizace selže, a jak sledovat nové verze bez přihlášení.

MyÚčto.cz kontroluje GitHub Releases API a v `Systém → Aktualizace`
(jen superadmin) zobrazí běžící sestavení, dostupnou aktualizaci a její poznámky.
Aplikaci lze aktualizovat z uživatelského rozhraní nebo provozním skriptem;
konkrétní postup závisí na typu instalace.

## 102.1 Kdy to potřebujete

- V patičce aplikace se objevilo upozornění na novou verzi.
- Chcete zkontrolovat, jakou verzi právě provozujete.
- Aktualizace skončila chybou nebo se v aplikaci zobrazuje věčně jako
  probíhající.
- Chcete sledovat nové verze, aniž byste se přihlašovali.

<!-- cols: 26 40 34 -->
| Kdy | Co udělat | Kde |
|---|---|---|
| před každou aktualizací | Zálohovat databázi i datový adresář | viz [§ 102.2](#1022-nez-zacnete) |
| při upozornění v patičce | Aktualizovat jedním tlačítkem | `Systém → Aktualizace`, [§ 102.3](#1023-krok-za-krokem-aktualizace-dockeru) nebo [§ 102.4](#1024-krok-za-krokem-aktualizace-nativni-instalace) |
| jednou při zřízení | Naplánovat denní kontrolu verze | cron, viz [§ 102.8.2.1](#102821-planovani-cronu) |
| po chybě | Zjistit důvod a případně vrátit stav | [§ 102.6](#1026-krok-za-krokem-kdyz-aktualizace-selze) |

## 102.2 Než začnete

1. **Oprávnění.** Stránku `Systém → Aktualizace` vidí a aktualizaci spouští
   jen superadmin. U spravované instalace aktualizaci nasazuje provozovatel
   a tlačítko se nezobrazuje.
2. **Záloha.** Databázové migrace mohou schéma i uložená data převádět a
   vrátit se nedají. Před aktualizací vždy zálohujte databázi i datový adresář.
   Zálohu databáze si zajišťujete sami.
3. **Docker:** ověřte, že `docker compose config` ukazuje volume `app-data`
   připojený do `/data`, že datový volume i databáze jsou v záloze, že
   `cfg.local.php` neleží jen v zapisovatelné vrstvě kontejneru a že je na
   hostu dost místa pro nový image i zálohu (viz [§ 102.8.5](#10285-persistentni-data-v-dockeru)).
4. **Docker:** na hostu musí běžet update watcher (viz
   [§ 102.8.4.2](#102842-instalace-watcheru-jako-daemon-na-produkci)), jinak se aktualizace po
   kliknutí nezpracuje.
5. **Nativní instalace:** potřebujete práva na zápis do kořene aplikace,
   alespoň 512 MB volného místa, PHP CLI a rozšíření `phar` a `zlib`.
   Composer, Node ani pnpm na hostu potřeba nejsou.

> [!TIP]
> Aktualizace aplikace se nedotýká licence. Je vázaná na instalaci a zůstává
> aktivní přes upgrady. Viz [104. Licence a aktivace](104_Licence_a_aktivace.md).

## 102.3 Krok za krokem: aktualizace Dockeru

1. Otevřete `Systém → Aktualizace`. Zkontrolujte **Aktuální verzi** a
   **Poslední vydanou verzi**. Dostupnost ověříte tlačítkem **Zkontrolovat teď**.
2. Přečtěte si **Poznámky k vydání**.
3. Klikněte na **Aktualizovat na {verze}**. Aplikace zapíše požadavek a ukáže
   **Upgrade zařazen do fronty**.
4. Do 30 sekund požadavek převezme update watcher na hostu, aktualizuje image,
   restartuje stack a spustí migrace. Stav se obnovuje každých 5 sekund.

**Jak poznáte, že je hotovo:** Stránka ukáže **Upgrade úspěšně dokončen**
s časem **Dokončeno** a **Aktuální verze** odpovídá nové verzi.

Neběží-li watcher, stav zůstane navždy na **Upgrade probíhá…**. Spusťte
aktualizaci ručně podle [§ 102.8.4.4](#102844-pokud-watcher-nebezi) a stav
uvolněte tlačítkem **Zrušit / odblokovat**.

## 102.4 Krok za krokem: aktualizace nativní instalace

1. Otevřete `Systém → Aktualizace` a ověřte, že u prostředí je **Nativní
   instalace**. Zpráva **Tato instalace umí aktualizaci provést sama z release
   bundlu.** potvrzuje, že kontrola prostředí prošla.
2. Klikněte na **Aktualizovat na {verze}** a potvrďte dotaz.
3. Sledujte kroky (**krok {n} z {počet}**): kontrola prostředí, stažení,
   ověření SHA-256, rozbalení, záloha, nasazení, migrace a dokončení. Stránku
   nezavírejte, průběh se obnovuje sám.
4. Používáte-li `opcache.validate_timestamps=0`, po dokončení restartujte
   php-fpm / IIS application pool.

**Jak poznáte, že je hotovo:** Stránka ukáže **Upgrade úspěšně dokončen**, u
výsledku je **Log** a **Záloha přepsaných souborů** a verze v patičce je nová.

Zpráva **Automatickou aktualizaci teď nelze spustit** vypíše důvody. Pak
použijte ruční postup ([§ 102.8.6.4](#102864-kdyz-automaticka-cesta-nejde)).

> [!WARNING]
> Aktualizace přepisuje soubory běžící aplikace. Požadavky odbavované přesně
> v ten okamžik mohou selhat. Na produkci ji spouštějte v klidném okně a mějte
> aktuální zálohu databáze.

## 102.5 Krok za krokem: sledování nových verzí bez přihlášení

1. Ze svého serveru nebo monitoringu volejte veřejný endpoint
   `https://<vaše-adresa>/api/version`.
2. Sledujte pole `has_update` a `release_url`.

**Jak poznáte, že je hotovo:** Odpověď vrací aktuální označení, poslední
dostupné označení, `has_update` a `release_url`. Endpoint nevyžaduje
přihlášení.

## 102.6 Krok za krokem: když aktualizace selže

1. Na `Systém → Aktualizace` přečtěte důvod u **Upgrade selhal**.
2. Otevřete log: `storage/upgrade-<časová značka>.log`.
3. Postupujte podle kroku, kde proces spadl ([§ 102.8.7](#10287-co-kdyz-upgrade-selze)).
4. Visí-li stav jako probíhající a proces už neběží, klikněte na **Zrušit /
   odblokovat**. Po 15 minutách bez signálu to UI udělá samo.

**Jak poznáte, že je hotovo:** Aplikace běží, ve stavu není příznak
probíhající aktualizace a `php api/bin/migrate.php --status` nehlásí čekající
migrace.

## 102.7 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| **Upgrade probíhá…** věčně | Neběží update watcher | Spusťte aktualizaci ručně a stav uvolněte (**Zrušit / odblokovat**) |
| **Automatickou aktualizaci teď nelze spustit** | Preflight našel chybějící práva, místo nebo PHP CLI | Odstraňte uvedené důvody, nebo použijte ruční postup |
| **Upgrade selhal**, krok `verify` | Neshoda SHA-256, poškozené stažení | Zkuste znovu, případně stáhněte bundle ručně |
| **Upgrade selhal**, krok `swap` | Zamčené soubory | Rollback proběhne sám; chybějící soubory vraťte ze zálohy |
| **Upgrade selhal**, krok `migrate` | Konkrétní migrace selhala | Rollback se nespouští; opravte příčinu a dokončete `php api/bin/migrate.php` |
| `.env: line N: ... command not found` | Starší verze spouštěla `.env` jako shell | Hodnotu s mezerou uzavřete do uvozovek a aktualizaci spusťte znovu |
| Stav **Upgrade nepotvrzen** | Výsledek se nepodařilo načíst | Zkontrolujte log a verzi v patičce |
| Tlačítko **Aktualizovat** není vidět | Spravovaná instalace, nebo nejste superadmin | Spravovanou instalaci aktualizuje provozovatel |

## 102.8 Podrobnosti a pravidla

### 102.8.1 Co všechno se aktualizuje

Aktualizace zahrnuje všechny tři vrstvy aplikace:

- **Backend (PHP)** - `api/vendor/` se přebuilduje (nebo přijde
  představěné v production bundlu), schéma DB se případně migruje
  (`php api/bin/migrate.php`).
- **Frontend (Vue)** - `web/dist/` (Vite produkční build).
- **Manuál** - `manual/generated/*.html`. PDF verze je ke stažení na [myucto.cz](https://myucto.cz/manual/Manual_MyUcto.pdf).

Zachovají se `cfg.php`, `cfg.local.php`, `private/`, `storage/` a `log/`, tedy
konfigurace a uživatelská data mimo distribuční balíček. Databázové migrace
mohou schéma i uložená data řízeně převádět, proto před aktualizací vždy
zálohujte databázi i datový adresář.

> [!TIP]
> Aktualizace aplikace se nedotýká licence. Je vázaná na instalaci a
> zůstává aktivní přes upgrady. Správu licence a předplatného popisuje
> [104. Licence a aktivace](104_Licence_a_aktivace.md).

### 102.8.2 Pravidelná kontrola - jak funguje

Cron skript `api/bin/cron-version-check.php` se spouští 1× denně, volá
GitHub API a cachuje výsledek do tabulky `app_meta` (klíče
`latest_version`, `latest_release_notes`, `latest_release_url`,
`latest_published_at`, `last_check_at`). UI / footer čte z cache, žádný
blocking síťový call při každém načtení stránky.

#### 102.8.2.1 Plánování cronu

| Prostředí | Příklad |
|-----------|---------|
| Linux/cron | `0 6 * * * cd /opt/myucto && php api/bin/cron-version-check.php` |
| Docker (host cron) | `0 6 * * * docker compose -f /opt/myucto/docker-compose.production.yml exec --user www-data -T app php api/bin/cron-version-check.php` |
| Windows Scheduler | Daily, akce: `php.exe C:\inetpub\myucto\api\bin\cron-version-check.php` |

Pokud cron nenastavíte, kontrola se nikdy nespustí - admin musí kliknout
**„Zkontrolovat teď"** v UI.

### 102.8.3 Patička aplikace a upozornění na aktualizaci

V patičce každé stránky vidíte označení právě běžícího sestavení. Pokud je
k dispozici aktualizace a jste přihlášeni jako superadmin, zobrazí se vedle něj
klikací upozornění vedoucí do **Systém → Aktualizace**.

Ostatní uživatelé vidí jen označení běžícího sestavení; upozornění ani ovládání
aktualizace nemají k dispozici.

### 102.8.4 Aktualizace v UI - Docker

V `Systém → Aktualizace` klikněte na tlačítko **Aktualizovat**.
Aplikace zapíše flag soubor `upgrade-requested.json` **uvnitř kontejneru**
do `${MYINVOICE_DATA_DIR}/storage/` (ve standardní Docker instalaci
`/data/storage/`) a UI začne sledovat stav.
**Vlastní
upgrade ale provádí host-side watcher** - proces běžící mimo container,
který má přístup k `docker compose` na hostu a přes `docker compose exec`
čte/píše do storage volume.

#### 102.8.4.1 Test režim (jednorázově, ve foregroundu)

Než nainstalujete watcher jako daemon, otestujte ho ručně v PowerShell /
bash okně:

```bash
# Linux / macOS
cd /opt/myucto
bash cmd/docker-update-watcher.sh
```

```powershell
# Windows - spusťte tím PowerShellem, který máte (upravte cd na SVOU instalační cestu)
cd C:\inetpub\myucto
pwsh -NoProfile -ExecutionPolicy Bypass -File cmd\docker-update-watcher.ps1
# nemáte-li PowerShell 7, použijte místo `pwsh` příkaz `powershell` (Windows PS 5.1)
```

> [!TIP]
> Watcher si vlastní update spouští **tímtéž** PowerShell hostem, pod kterým
> běží (`pwsh` i `powershell`), a cesty řeší z umístění skriptu - funguje tedy
> i z jiného adresáře než `C:\inetpub\myucto` a na strojích, kde je jen
> PowerShell 7 (`pwsh`).

Uvidíte `[watcher] start, polling storage/upgrade-requested.json inside
container every 30s` - watcher poslouchá. Klikněte v UI na **Aktualizovat**
a do 30 s zachytí flag, spustí `docker-update.{sh,ps1}`, výsledek napíše
do kontejneru. Watcher zastavíte `Ctrl+C`.

#### 102.8.4.2 Instalace watcheru jako daemon (na produkci)

##### Linux - systemd unit

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

##### Windows - Scheduled Task

```powershell
# Upravte cestu k SVÉ instalaci. Máte-li jen Windows PowerShell 5.1, nahraďte
# `pwsh.exe` za `powershell.exe`.
schtasks /create /tn "MyUcto Update Watcher" `
  /tr "pwsh.exe -NoProfile -ExecutionPolicy Bypass -File C:\inetpub\myucto\cmd\docker-update-watcher.ps1" `
  /sc onstart /ru SYSTEM /rl HIGHEST

# Spusťte hned (ne až po restartu)
schtasks /run /tn "MyUcto Update Watcher"
```

> [!TIP]
> `pwsh.exe` musí být v PATH (PowerShell 7 instalátor ji tam dává). Pokud
> Scheduled Task hlásí, že příkaz nenašel, zadejte plnou cestu
> `C:\Program Files\PowerShell\7\pwsh.exe`, nebo použijte `powershell.exe` (PS 5.1).

Stav úlohy: `schtasks /query /tn "MyUcto Update Watcher" /v /fo list`.
Stop: `schtasks /end /tn "MyUcto Update Watcher"`.

#### 102.8.4.3 Co watcher dělá

1. Každých 30 s: `docker compose exec -T app test -f storage/upgrade-requested.json`.
2. Když ho najde → přečte `target_version` přes `cat`, přejmenuje na
   `upgrade-inflight.json` přes `mv` uvnitř kontejneru (zámek proti
   double-triggeru).
3. Spustí na hostu `cmd/docker-update.{sh,ps1}` - ten dělá:
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

#### 102.8.4.4 Pokud watcher neběží

UI sice flag soubor zapíše, ale nikdo ho nezpracuje (UI zůstane věčně
ve stavu „Upgrade probíhá…"). Spusťte na hostu ručně:

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

(Pokud nepoužíváte production compose, vynechejte `-f docker-compose.production.yml`.)

### 102.8.5 Persistentní data v Dockeru

Standardní Compose konfigurace ukládá stav aplikace do jediného volume
`app-data:/data` a nastavuje `MYINVOICE_DATA_DIR=/data`. V něm musí zůstat
`cfg.local.php`, `log/`, `storage/` a `private/`; databáze používá samostatný
volume. Aktualizace image ani znovuvytvoření aplikačního kontejneru proto
nesmí tyto soubory odstranit.

Před aktualizací ověřte:

1. `docker compose config` ukazuje volume `app-data` připojený do `/data`;
2. datový volume i databáze jsou zahrnuté v záloze;
3. `cfg.local.php` neleží pouze v zapisovatelné vrstvě běžícího kontejneru;
4. na hostu je dost místa pro nový image i zálohu.

Aktualizační skript umí rozpoznat instalaci se samostatnými volumes pro log,
storage a private data a před pokračováním spustí převod přes
`cmd/docker-migrate-volumes.ps1` nebo `cmd/docker-migrate-volumes.sh`. Zdrojové
volumes po převodu nemaže. Odstraňte je až po ověření přihlášení, konfigurace,
faktur, nahraných souborů a běhu naplánovaných úloh v aktuálním kontejneru.

### 102.8.6 Aktualizace v UI - nativní instalace

Nativní deployment (sdílený hosting / VPS bez Dockeru) se aktualizuje
z UI stejně jako Docker - jedním tlačítkem. V `Systém → Aktualizace`
klikněte na **Aktualizovat**.

Aplikace stáhne **production bundle** z GitHub release
(`myucto-X.Y.Z.tar.gz`), ověří jeho SHA-256, nasadí ho přes instalaci
a spustí migrace. **Composer, Node ani pnpm na hostu potřeba nejsou** -
bundle má `api/vendor/`, `web/dist/` i `manual/generated/` už
představěné.

#### 102.8.6.1 Co se děje na pozadí

Vlastní práci dělá detached CLI worker `api/bin/native-update.php`
(z UI se spouští automaticky, ručně jde zavolat také):

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

#### 102.8.6.2 Co zůstane nedotčené

`cfg.php`, `cfg.local.php`, `cfg.docker.php`, `.env`, `storage/`,
`private/`, `log/`, `tmp/` a `.git/` se nikdy nepřepisují - bundle je
ani neobsahuje a swap je navíc přeskakuje. Soubory, které v novém
bundlu nejsou, se **nemažou** (stejné chování jako ruční `tar -xzf`).
Výjimkou jsou soubory frontendu ve `web/dist/assets/` z předchozích vydání:
po migracích a v denním úklidu se smažou ty, které nepatří k aktuálnímu ani
předchozímu vydání a jsou starší než den.

`VERSION` se úmyslně přepisuje jako poslední krok: dokud migrace
neproběhnou, instalace se hlásí starou verzí, takže přerušená
aktualizace nevypadá jako dokončená.

> [!WARNING]
> Aktualizace přepisuje soubory běžící aplikace - requesty
> odbavované přesně v ten moment mohou selhat. Na produkci ji spouštějte
> v klidném okně a **mějte aktuální zálohu databáze**; migrace samotné
> se vracet nedají.

> [!TIP]
> Pokud máte `opcache.validate_timestamps=0`, restartujte po aktualizaci
> php-fpm / IIS application pool - jinak poběží stará bytecode cache.
> Preflight na to upozorní sám.

#### 102.8.6.3 Bezpečnostní model

Bundle se stahuje po HTTPS jen z hostů GitHubu a kontroluje se jeho
SHA-256. Checksum ale leží ve stejném releasu jako tarball, takže
chrání proti **poškozenému přenosu, ne proti kompromitovanému
repozitáři** - trust root je GitHub účet projektu. Aktualizaci smí
spustit jen superadmin.

#### 102.8.6.4 Když automatická cesta nejde

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

Ve vývoji (instalace je git checkout) preferujte `git checkout vX.Y.Z` -
bundle by vám jinak zašpinil pracovní kopii. Preflight na to upozorní.

### 102.8.7 Co když upgrade selže

#### 102.8.7.1 Docker watcher

Watcher zapíše `storage/upgrade-result.json` se `status: "failed"` a
plným logem do `storage/upgrade-YYYYMMDDTHHMMSSZ.log`. UI ho zobrazí.
Typické příčiny:

- **Image pull selhal** - síť, GHCR rate limit, neplatný tag → spusťte
  `docker compose pull` ručně, viz log.
- **Migrace selhala** - schéma kolize, missing column → vraťte se na
  předchozí tag (`docker compose pull image:OLD-VERSION && up -d`),
  pak řešte migrace.
- **Stack se nezastavuje** - running queries blokují. Restartujte přes
  `docker compose restart app`.

Container s aplikací se restartoval, ale data v DB volume zůstávají
nedotčena.

##### Hodnoty s mezerou v `.env`

Starší verze v update skriptech **spouštěly `.env` jako shell kód**, takže
hodnota s mezerou bez uvozovek aktualizaci utnula hned na začátku (v logu
zůstala jen hláška typu `.env: line 12: Novak: command not found`):

```bash
# tohle rozbíjelo starší autoupdater
MYINVOICE_SMTP_FROM_NAME=Jan Novak
```

Soubor `.env` se pouze **parsuje** (`cmd/lib/env-load.{sh,ps1}`), takže
oba zápisy fungují stejně a nic z `.env` se nespouští:

```bash
MYINVOICE_SMTP_FROM_NAME=Jan Novak
MYINVOICE_SMTP_FROM_NAME="Jan Novak"
```

Pravidla parseru - hodnota je celý zbytek řádku za prvním `=`; uvozovky
(jednoduché i dvojité) se sundají; `#` **na začátku řádku** je komentář,
uvnitř hodnoty jen po mezeře (`FOO=bar   # poznámka` → `bar`, `FOO=a#b` →
`a#b`); `$PROMENNA` se **nerozvine**, bere se doslova; CRLF konce řádků
nevadí. Chcete-li mít v hodnotě mezeru na kraji nebo znak `#` bez mezery
kolem, dejte hodnotu do uvozovek.

> [!WARNING]
> Běžíte-li na starší verzi, kde autoupdater na hodnotě s mezerou padá,
> stačí příslušný řádek `.env` uzavřít do uvozovek a spustit aktualizaci
> znovu.

Selhání kroku aktualizace nikdy nekončí tiše - skript vypíše
`ERROR: … AKTUALIZACE NEBYLA DOKONČENA` s číslem řádku a skončí nenulovým
kódem; watcher tenhle důvod propíše i do hlášky v UI.

#### 102.8.7.2 Nativní

Worker zapíše `storage/upgrade-result.json` se `status: "failed"`,
důvodem a cestou k logu `storage/upgrade-<timestamp>.log`. UI to
zobrazí včetně cesty k záloze. Podle kroku, na kterém to spadlo:

- **`download` / `verify` / `extract`** - instalace se vůbec nezměnila,
  nic řešit netřeba. Neshoda SHA-256 znamená poškozené stažení; zkuste to
  znovu, případně stáhněte bundle ručně.
- **`swap`** - worker sám spustí **rollback** ze zálohy
  `storage/updates/<verze>/backup/` a do logu napíše, kolik souborů
  vrátil. Když rollback část souborů nevrátil (zamčené soubory), obnovte
  je odtud ručně:
  ```bash
  cp -r storage/updates/X.Y.Z/backup/. .
  ```
- **`migrate`** - kód je už nasazený, schéma ne. Rollback se
  **záměrně nespouští** (vracet kód pod rozjeté schéma škodí víc).
  Projděte log a dokončete `php api/bin/migrate.php` ručně.

Pokud `migrate.php` selhal, vrátit migraci samotnou nejde - musíte
ladit konkrétní migraci. Záloha DB je vaše odpovědnost (viz [Exporty](20_Exporty.md)).

Když se worker přestane hlásit (spadl proces), UI po 15 minutách bez
heartbeatu příznak „probíhá" samo zruší a napíše, kde hledat log.

### 102.8.8 Dohled na nové verze bez UI

Pokud nemáte administrátorský přístup do UI, ale chcete vědět, kdy je
nová verze, můžete pravidelně volat veřejný endpoint:

```bash
curl -s https://myucto.vas-server.cz/api/version | jq
```

Vrátí aktuální označení, poslední dostupné označení, příznak `has_update`
a odkaz `release_url`. Jde o veřejný endpoint bez autentizace; stejné základní
údaje může vidět kdokoli s přístupem k aplikaci v patičce.

## 102.9 Související kapitoly

- [Licence a aktivace](104_Licence_a_aktivace.md)
- [Instalace Docker](03_Instalace_Docker.md)
- [Nativní instalace](04_Instalace_Nativni.md)
- [Řešení problémů](999_Reseni_problemu.md)
