# 3. Instalace — Docker

Docker je nejrychlejší cesta (cca 3 minuty) a doporučená pro nové instalace.
Předpoklady: **Docker Desktop** (Windows / macOS) nebo **Docker Engine
+ compose-plugin** (Linux).

Klon repa je společný krok pro většinu variant:

```bash
git clone https://github.com/radekhulan/myucto.git myucto
cd myucto
```

Pak si vyber variantu podle toho, jestli chceš stavět image lokálně, nebo
si stáhnout pre-built z GHCR.

## 3.1 Varianta A — pre-built image z GHCR (rychlejší, bez local buildu)

Stáhne hotový multi-arch image (`ghcr.io/radekhulan/myucto:latest`,
`linux/amd64` + `linux/arm64`). Nepotřebuješ na hostu `pnpm`/`composer`
ani několikaminutový build.

```bash
# Linux / macOS
cmd/docker-ghcr.sh

# Windows — v PowerShellu 7 (pwsh); Windows PowerShell 5.1 skript odmítne
pwsh -File .\cmd\docker-ghcr.ps1
```

Skript `docker-ghcr` postupně:

1. Vygeneruje `.env` s náhodnými DB hesly (28 znaků base64)
2. Vygeneruje `cfg.docker.php` z `cfg.sample.php` (host=db / redis,
   randomized `app.pepper` + `secret_encryption_key`, dev-friendly cookies)
3. `docker compose pull` — stáhne image z GHCR
4. `up -d` + počká na DB healthy + spustí migrace

Používá `docker-compose.production.yml` (image-only, žádný `build:` block),
takže další compose příkazy vyžadují flag `-f docker-compose.production.yml`
(viz [§ 3.6 Daily ops](#36-daily-ops)).

> 💡 V produkci pinuj konkrétní neměnný release tag — uprav `docker-compose.production.yml`
> a změň `:latest` na konkrétní tag (např. `:X.Y.Z`). Aktualizaci pak proveď přes
> `cmd/docker-update.{sh,ps1}` (auto-detekuje registry mode = `pull` + `up -d`
> + migrace).

**Aktualizace na novou verzi:**

```bash
# Linux / macOS
cmd/docker-update.sh

# Windows — v PowerShellu 7 (pwsh); Windows PowerShell 5.1 skript odmítne
pwsh -File .\cmd\docker-update.ps1
```

Skript v registry módu sám zavolá `docker compose pull app` (stáhne nový
image z GHCR), restartuje stack a doběhne pending migrace. Volumes (DB data)
zůstávají zachovány. Režim **detekuje z image běžícího kontejneru**:
`ghcr.io/...` → registry (`pull`), lokální build → source (`git pull` + rebuild).
Když stack zrovna neběží, ale máš lokálně stažený GHCR image, bere to taky jako
registry. Přebít lze `MYINVOICE_UPDATE_MODE=registry|source`.

Nový image se publikuje automaticky při každém release tagu `v*.*.*`,
takže aktualizace je otázkou jednoho příkazu.

> 🔔 **Upgrade přímo z UI:** admin vidí v **Systém →
> Aktualizace** stav verze + tlačítko *Aktualizovat*, které pull image
> + restart spustí přes host-side watcher. Detaily včetně instalace
> watcheru jako systemd unit / Scheduled Task → [§ 3.9 Update watcher](#39-update-watcher-jednoclick-upgrade-z-ui-volitelne)
> nebo kapitola [Aktualizace](98_Aktualizace.md).
> Pro denní kontrolu nové verze nezapomeň naplánovat
> `php api/bin/cron-version-check.php` (1× denně, viz [Aktualizace](98_Aktualizace.md)).

> **WSL2 / Linux po klonu:** pokud `./cmd/docker-ghcr.sh` hlásí
> `Permission denied` nebo `/usr/bin/env: 'bash\r': No such file…`,
> má tvůj git zapnutý `core.autocrlf=true`, který na checkoutu konvertuje
> LF → CRLF. Oprav jednorázově existující soubory a vypni autocrlf
> globálně (na Linuxu nikdy nemá být zapnutý):
>
> ```bash
> sed -i 's/\r$//' cmd/*.sh
> chmod +x cmd/*.sh
> git config --global core.autocrlf input
> ```
>
> Repo má `.gitattributes` s `*.sh text eol=lf`, takže příští `git clone`
> bude LF i bez tohoto kroku.

## 3.2 Varianta B — build z source

Postaví image lokálně z repa — vhodné pro vývoj a vlastní úpravy.

```bash
# Linux / macOS
cmd/docker-install.sh

# Windows — v PowerShellu 7 (pwsh); Windows PowerShell 5.1 skript odmítne
pwsh -File .\cmd\docker-install.ps1
```

Skript `docker-install` postupně:

1. Vygeneruje `.env` s náhodnými DB hesly (28 znaků base64)
2. Vygeneruje `cfg.docker.php` z `cfg.sample.php` (host=db / redis,
   randomized `app.pepper` + `secret_encryption_key`, dev-friendly cookies pro
   HTTP loopback)
3. Postaví image `myucto:latest` (multi-stage: Vue build → composer →
   PHP 8.5 + nginx + php-fpm z `Dockerfile.alpine`)
4. Spustí stack: **app** (nginx:80 → host:8080) + **db** (MariaDB 11.8)
5. Počká, až bude DB healthy, a spustí migrace

## 3.3 Varianta C — bez klonování repa (jen Docker)

Pokud nechceš mít na hostiteli klon repa (typicky produkční Linux server,
jen Docker daemon), GHCR image obsahuje veškerý PHP/JS kód i migrace —
z repa potřebuješ jen **3 malé soubory**.

#### Varianta C1 — one-click přes `docker-ghcr.sh` (doporučeno)

Stáhne si i instalační skript a chová se stejně jako Varianta A
(random hesla, vygenerovaný `cfg.docker.php`, pull image, migrace):

```bash
mkdir myucto && cd myucto
curl -O https://raw.githubusercontent.com/radekhulan/myucto/master/docker-compose.production.yml
curl -O https://raw.githubusercontent.com/radekhulan/myucto/master/cfg.sample.php
curl -O https://raw.githubusercontent.com/radekhulan/myucto/master/cmd/docker-ghcr.sh
curl -O https://raw.githubusercontent.com/radekhulan/myucto/master/cmd/lib/env-load.sh
chmod +x docker-ghcr.sh
./docker-ghcr.sh
```

> 🛈 `env-load.sh` je parser `.env` (skript ho jinak dotáhne sám). Díky němu
> smí být v `.env` i hodnota s mezerou bez uvozovek — viz **§ 77.7**.

Skript najde `docker-compose.production.yml` v aktuálním adresáři, takže
nemusíš nic přejmenovávat. Update na novou verzi:

```bash
docker compose -f docker-compose.production.yml pull
docker compose -f docker-compose.production.yml up -d
```

#### Varianta C2 — manuálně, bez skriptu

Když chceš plnou kontrolu nad `cfg.docker.php` a `.env`:

```bash
mkdir myucto && cd myucto
curl -O https://raw.githubusercontent.com/radekhulan/myucto/master/docker-compose.production.yml
curl -O https://raw.githubusercontent.com/radekhulan/myucto/master/cfg.sample.php
mv docker-compose.production.yml docker-compose.yml
cp cfg.sample.php cfg.docker.php
# uprav cfg.docker.php — minimálně:
#   db.host => 'db', db.user => 'myucto', db.pass => '<heslo z .env níže>'
#   app.pepper a secret_encryption_key (oboje: openssl rand -base64 32)

cat > .env <<EOF
DB_PASSWORD=$(openssl rand -base64 28)
DB_ROOT_PASSWORD=$(openssl rand -base64 28)
EOF

docker compose up -d
docker compose exec app php api/bin/migrate.php
```

> 🛈 V Dockeru se migrace spouštějí automaticky při startu kontejneru
> (`docker-entrypoint.sh`). Ruční `php api/bin/migrate.php` zůstává bezpečný
> idempotentní fallback.

> ⚠️ Varianta C2 NEgeneruje hesla a secrets automaticky — musíš je do
> `cfg.docker.php` doplnit ručně. Pro one-click bez klonu repa použij **C1**.

> 📖 **Manuál na `/manual`:** GHCR image má vygenerovaný HTML
> manuál i PDF (`tools/generateManualHtml.php` +
> `tools/exportManualToPdf.php` se volají build-time v `Dockerfile`),
> takže `http://localhost:8080/manual` funguje bez dalších kroků a v sidebaru
> je button **„Stáhnout PDF"**. Update na nový obsah = `cmd/docker-update.{sh,ps1}`
> (pull novějšího image z GHCR stáhne i nové vygenerované kapitoly).
>
> Kdyby `/manual` vrátil 503 *„Manuál není zatím vygenerovaný“*, regeneruješ
> manuál ručně bez rebuildu:
>
> ```bash
> docker compose -f docker-compose.production.yml exec app \
>   php tools/generateManualHtml.php
> docker compose -f docker-compose.production.yml exec app \
>   php tools/exportManualToPdf.php
> ```

## 3.4 Po dokončení (všechny varianty)

**Otevři: 👉 http://localhost:8080**

V prohlížeči naskočí setup wizard — viz [7. První spuštění](07_Setup_wizard.md).

> ⚠️ **Použij `http://`, ne `https://`, a explicitní port `:8080`.** Docker
> stack běží na plain HTTP — pokud zadáš `https://...` nebo defaultní port,
> dostaneš `SSL_ERROR_RX_RECORD_TOO_LONG` / `ERR_SSL_PROTOCOL_ERROR`. Pro
> HTTPS na LAN/produkčním serveru viz [§ 3.8 HTTPS / TLS terminace](#38-https-tls-terminace).

> 🌐 **Přístup z jiného stroje (LAN IP, hostname)?** Setup wizard funguje
> z libovolného hostu (např. `http://10.0.0.8:8080`) a `app.url` se automaticky
> uloží podle URL, kterou v wizardu použiješ. Pokud potřebuješ URL znát už
> před setupem (např. produkční doména + reverzní proxy), spusť kontejner
> s `-e MYINVOICE_APP_URL=https://invoice.example.com`.
>
> 🛈 **Přístup z LAN přes IP** (např. `http://192.168.1.50:8080`)
> automaticky funguje. RFC1918 privátní IP (`10.*`, `172.16-31.*`, `192.168.*`),
> `127.*`, `localhost` a `*.local` jsou vyjmuty z HTTPS redirectu v `.htaccess`
> a `web.config`. Také požadavek s hlavičkou `X-Forwarded-Proto: https`
> (reverse proxy s TLS terminací) redirect přeskočí.
>
> ⚠️ Přístup přes LAN IP a plain HTTP je vhodný pro prvotní instalaci, ale
> **passkeys na něm nejsou podporované**. Pro passkeys použij stabilní hostname,
> důvěryhodné HTTPS a nastav `app.url` na přesný veřejný origin. Výjimkou pro
> lokální vývoj je `http://localhost`.

## 3.5 Změna portu

Edituj `.env` (vznikl po prvním spuštění):

```
APP_PORT=9000          # místo 8080
DB_PORT=3308           # místo 3307 (vázán jen na 127.0.0.1)
```

a `docker compose up -d`. URL pak `http://localhost:9000`.

### 3.5.1 Runtime env pro auto-migrace (Docker)

Vstupní skript image podporuje tyto proměnné:

```bash
MYINVOICE_SKIP_MIGRATIONS=1     # vypne auto-migraci při startu
MYINVOICE_MIGRATE_ATTEMPTS=20   # počet retry pokusů migrace
MYINVOICE_MIGRATE_DELAY=3       # pauza mezi pokusy (sekundy)
MYINVOICE_DATA_DIR=/data        # default v compose souborech; sjednocuje
                                # log/, storage/, private/ a cfg.local.php pod /data
MYINVOICE_AUTH_REQUIRE_MFA=true  # vyžadovat passkey nebo TOTP
MYINVOICE_AUTH_MFA_METHODS=passkey,totp
MYINVOICE_AUTH_PASSWORDLESS_LOGIN=true # povolit passkey login bez e-mailu a hesla; default false
MYINVOICE_SESSION_LOCK_AFTER_MINUTES=15 # výchozí interval a maximum osobní volby; 0 nic nevynucuje
```

Default je `20` pokusů s pauzou `3` sekundy. Pokud proměnné nenastavíš, použije
se výchozí chování.

**`MYINVOICE_DATA_DIR`** je **default** v `docker-compose.yml` i
`docker-compose.production.yml` (single-volume layout `app-data:/data`). Drží
log/, storage/, private/dkim/ **i `cfg.local.php`** — per-instance konfigurace
z setup wizardu tak přežije image update. Viz **[§ 3.5.3 Single-volume úložiště](#353-single-volume-uloziste)** níže.
Pokud používáš původní třísvazkové úložiště, `cmd/docker-update.{sh,ps1}` ho
detekuje a před `up -d` automaticky spustí `cmd/docker-migrate-volumes.{sh,ps1}`.
Podrobný popis aktuálního ukládání dat je v
[§ 98.5](98_Aktualizace.md#985-persistentni-data-v-dockeru).

**Mount `cfg.docker.php` je volitelný** — image obsahuje stub `cfg.php`
(`<?php return [];`) a vše lze předat přes ENV (12-factor). Pro full-ENV deploy
(Railway, Heroku, Fly.io) bind-mount `./cfg.docker.php:/var/www/html/cfg.php:ro`
v `docker-compose.yml` zakomentuj nebo odstraň.

### 3.5.2 Railway / PaaS specifika

Některé PaaS (typicky Railway) injectují nevyřešené placeholdery jako
`${VAR}`, pokud proměnná není definovaná. MyÚčto je v env
overridech ignoruje, takže nepřepíší validní hodnoty z `cfg.php`/`cfg.docker.php`.
Pokud chybí `secret_encryption_key`, aplikace fallbackuje na HKDF z `app.pepper`.

### 3.5.3 Single-volume úložiště

> 🛈 **TL;DR:** Výchozí je single-volume layout. Všechen stavový obsah
> (log/, storage/, private/dkim/ **+ `cfg.local.php`**) leží v jediném
> persistent volumu `app-data:/data`. Image updaty jsou tak bezpečné —
> per-instance konfigurace přežije.

**Výchozí layout:**

| Vlastnost     | Single-volume                                |
|---------------|----------------------------------------------|
| Volume        | `app-data` (+ `db-data` pro MariaDB)         |
| Mount point   | `/data`                                      |
| Env           | `MYINVOICE_DATA_DIR=/data`                   |
| Compose       | `docker compose up -d` (default)             |
| Backup        | jeden `tar czf` nad `app-data` + dump DB     |
| Image update  | bezpečný — `cfg.local.php` v `/data` přežije |

**Co je pod `/data`.** Aplikace přes `Config::applyDataDirOverrides()` přepíše:

- `log/` → `/data/log`
- `storage/invoices/`, `storage/uploads/`, `storage/backup/`, `storage/sessions/`, `storage/cache/` → `/data/storage/…`
- `private/dkim/` → `/data/private/dkim`
- `cfg.local.php` zápisy ze setup wizardu / `bin/setup.php` / `bin/reset.php` → `/data/cfg.local.php`

Žádné jiné cesty se nemění (kód, vendor, web/dist zůstávají uvnitř `/var/www/html`, čistě read-only).

#### Pro novou instalaci

`cmd/docker-install.{sh,ps1}` použije default `docker-compose.yml` se single-volume
layoutem — nemusíš nic nastavovat navíc.

Ověření, že běží single-volume layout:

```bash
docker compose exec app sh -c 'echo $MYINVOICE_DATA_DIR'   # → /data
docker compose exec app ls /data                            # → log  storage  private  cfg.local.php (po setupu)
docker volume ls | grep myucto                           # vidíš pouze app-data + db-data
```

#### Pro instalaci s původním třísvazkovým úložištěm

**Nikdy nepřepínej layout bez migrace** — aplikace by nahlížela do prázdného
`/data` a tvářila se, že data zmizela. `cmd/docker-update.{sh,ps1}` to dělá
automaticky před `up -d`. Aktuální rozložení dat popisuje
[§ 98.5 Persistentní data v Dockeru](98_Aktualizace.md#985-persistentni-data-v-dockeru).

Shrnutí: `cmd/docker-migrate-volumes.{sh,ps1}` snapshotne `cfg.local.php`
z běžícího kontejneru, zkopíruje data ze starých volumes do nového `app-data`
přes dočasný `alpine` sidecar a obnoví `cfg.local.php`. Staré volumes nesmaže
(musíš ručně po ověření). Skript je idempotentní.

#### Backup single-volume layoutu

```bash
docker run --rm \
  -v myinvoice_app-data:/data:ro \
  -v "$PWD":/backup \
  alpine tar czf /backup/myucto-data-$(date +%F).tar.gz -C /data .
```

Plus dump MariaDB (viz [§ 98.7 Záloha a obnova](98_Aktualizace.md)) — to jsou dohromady **dvě entity** k zálohování (db + app-data).

## 3.6 Daily ops

```bash
docker compose up -d                                 # start
docker compose down                                  # stop (data v named volumes přežijí)
docker compose down -v                               # stop + WIPE volumes (ZNIČÍ DB!)
docker compose logs -f app                           # live logs
docker compose exec app bash                         # shell do kontejneru
docker compose exec app php api/bin/migrate.php      # CLI uvnitř kontejneru
cmd/docker-build.sh --no-cache                       # rebuild image (po PHP/JS změnách, jen Varianta B)
```

> 💡 Pokud jsi instaloval přes **Variantu A (docker-ghcr)**, všechny
> `docker compose` příkazy potřebují flag `-f docker-compose.production.yml`,
> např. `docker compose -f docker-compose.production.yml logs -f app`.

## 3.7 Volitelný Redis

```bash
docker compose --profile redis up -d
```

a v `cfg.docker.php` nastav `redis.enabled => true`. Restart appky.

## 3.8 HTTPS / TLS terminace

Docker stack sám TLS nedělá — web server (nginx) uvnitř kontejneru poslouchá na
portu 80 (HTTP) a mapuje se na host port `8080`. Pokud potřebuješ HTTPS (LAN
server, produkce, doménové jméno), postav před stack reverse proxy s TLS terminací.

**Symptom špatné konfigurace:** prohlížeč hodí `SSL_ERROR_RX_RECORD_TOO_LONG`
(Firefox) nebo `ERR_SSL_PROTOCOL_ERROR` (Chrome) — znamená to, že browser mluví
TLS, ale server odpovídá plain HTTP.

**Tři rozumné cesty:**

1. **Caddy (nejjednodušší)** — automatický Let's Encrypt pro doménu nebo
   self-signed pro IP, jeden Caddyfile řádek:
   ```
   vase-domena.cz {
       reverse_proxy localhost:8080
   }
   ```

2. **Nginx + self-signed cert** (`mkcert` nebo `openssl`) — pro intranet
   bez veřejného doménového jména.

3. **Cloudflare Tunnel / Tailscale Funnel** — pokud chceš veřejný přístup
   bez otevírání portů na firewallu.

**Konkrétní recept — Caddy jako další container vedle stacku:**

V kořeni repa (vedle `docker-compose.production.yml`) vytvoř `Caddyfile`:

```
faktury.tvojefirma.cz {
    reverse_proxy localhost:8080
}
```

Pak Caddy spusť na host síti, aby viděl port `8080`:

```bash
docker run -d --name caddy --restart unless-stopped \
  --network host \
  -v "$PWD/Caddyfile:/etc/caddy/Caddyfile:ro" \
  -v caddy_data:/data \
  -v caddy_config:/config \
  caddy:2
```

Caddy si vyžádá Let's Encrypt cert sám (potřebuje veřejně dostupné porty
80/443 a A/AAAA záznam pro doménu). Auto-renewuje. `X-Forwarded-Proto: https`
posílá automaticky — to je důležité, protože `.htaccess` v repu bez tohoto
hlavičky vynucuje HTTP→HTTPS redirect a vzniká redirect loop.

**A v `cfg.docker.php` přepni production nastavení:**

```php
'app' => [
    'url' => 'https://faktury.tvojefirma.cz',  // doslova to, co user vidí v adresáku
    ...
],
'session' => [
    'cookie_secure'   => true,
    'cookie_name'     => '__Host-myinvoice_session',
    'cookie_samesite' => 'Lax',
],
```

`app.url` se používá v emailových odkazech (faktury, reset hesla, upomínky) —
musí přesně odpovídat veřejné URL, jinak budou linky vést na špatnou doménu
nebo `localhost:8080`. `__Host-` cookie prefix vyžaduje HTTPS — pokud jsi po
této změně zkusil load přes `http://`, login se rozbije (cookie se neuloží).

Stejná hodnota je bezpečnostní autoritou pro WebAuthn: určuje přesný origin a
hostname (RP ID), pro který lze passkey vytvořit a použít. Neodvozuje se
z `Host` ani proxy hlaviček. Změna `app.url` na jiný hostname proto zneplatní
použitelnost dříve registrovaných passkeys; před změnou ověř TOTP nebo jinou
recovery cestu.

### 3.8.1 Vlastní domény klientských portálů

Vlastní doména firmy **nenahrazuje** `app.url`. Canonical `app.url` zůstává
stabilní adresou interního rozhraní, resetů hesla a WebAuthn RP ID. Další
hostname pouze nasměruj na stejnou instanci a následně jej založ v
**Nastavení → Firma → Vlastní domény**.

Pro každý vlastní hostname musí provozní vrstva zajistit:

1. veřejný A/AAAA nebo CNAME záznam směrovaný na reverse proxy;
2. důvěryhodný TLS certifikát platný pro přesný hostname;
3. předání původní hlavičky `Host` a `X-Forwarded-Proto: https` do aplikace;
4. DNS TXT challenge zobrazenou v administraci;
5. přidání hostname do seznamu povolených domén Cloudflare Turnstile, pokud je
   CAPTCHA zapnutá.

Aplikace certifikáty sama nevydává. Doménu aktivuje až po kontrole TXT a po
HTTPS požadavku na přesný challenge endpoint bez redirectu. Reverse proxy tedy
musí routing a certifikát připravit **před** stisknutím Ověřit. Caddy může více
hostname obsloužit jedním blokem a pro každý automaticky získat certifikát:

```caddyfile
faktury.tvojefirma.cz, portal.klient-a.cz, portal.klient-b.cz {
    reverse_proxy localhost:8080
}
```

U nginx, Traefiku nebo Cloudflare Tunnel platí stejný princip: všechny hostname
vedou do stejné aplikace, ale `Host` se nesmí přepsat na interní název služby.
Neznámý nebo neaktivní Host aplikace odmítne; nestačí tedy pouze přidat DNS a
certifikát. Konfigurace Apache, IIS i nginx v repozitáři posílá SPA fallback
přes stejnou serverovou kontrolu hostname.

Aktivní vlastní doména používá oddělenou host-only session cookie. Přihlášení
včetně passkey proběhne na canonical `app.url` a prohlížeč se vrátí jednorázovým
PKCE tokem; není potřeba a ani správné registrovat novou WebAuthn RP doménu pro
každého klienta.

Restart stacku: `docker compose -f docker-compose.production.yml restart app`
(nebo bez `-f` flagu pro Variantu B).

## 3.9 Update watcher — jednoclick upgrade z UI (volitelné)

Admin vidí v **Systém → Aktualizace** stav verze + tlačítko
*Aktualizovat*, které zařadí upgrade do fronty. Aby ho někdo aplikoval,
musí na hostu běžet **watcher** — proces, který přes `docker compose
exec` poslouchá flag soubor uvnitř kontejneru a spouští
`cmd/docker-update.(sh/ps1)`. Bez watcheru tlačítko *Aktualizovat*
nikam nedojede (UI zůstane věčně ve stavu „Upgrade probíhá…") a musíš
upgrade aplikovat ručně přes shell.

#### Test režim (foreground)

Než ho udělej daemon, otestuj ho v běžícím okně:

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
> běží (`pwsh` i `powershell`), a cesty řeší z umístění skriptu — funguje proto
> i z jiného adresáře a na strojích jen s PowerShell 7 (`pwsh`).

Vidíš `[watcher] start, polling storage/upgrade-requested.json inside
container every 30s` — od té chvíle hlídá flag. Klikni v UI
**„Aktualizovat"** a do 30 s zachytí flag, spustí
`cmd/docker-update.(sh/ps1)`, výsledek napíše zpátky. Watcher zastav
`Ctrl+C`.

#### Linux — systemd unit (produkce)

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

[Install]
WantedBy=multi-user.target
EOF

sudo systemctl daemon-reload
sudo systemctl enable --now myucto-update-watcher
```

Logy: `journalctl -u myucto-update-watcher -f`.

#### Windows — Scheduled Task (produkce)

```powershell
# Uprav cestu k SVÉ instalaci. Máš-li jen Windows PowerShell 5.1, nahraď
# `pwsh.exe` za `powershell.exe`.
schtasks /create /tn "MyUcto Update Watcher" `
  /tr "pwsh.exe -NoProfile -ExecutionPolicy Bypass -File C:\inetpub\myucto\cmd\docker-update-watcher.ps1" `
  /sc onstart /ru SYSTEM /rl HIGHEST
schtasks /run /tn "MyUcto Update Watcher"
```

> 🛈 `pwsh.exe` musí být v PATH (dává ji tam instalátor PowerShell 7). Pokud ji
> Scheduled Task nenajde, zadej plnou cestu `C:\Program Files\PowerShell\7\pwsh.exe`,
> nebo použij `powershell.exe` (PS 5.1).

Stav úlohy: `schtasks /query /tn "MyUcto Update Watcher" /v /fo list`.

#### Denní kontrola dostupné aktualizace

Watcher jen reaguje na *kliknutí*. Aby admin **viděl**, že je dostupná
aktualizace (badge v patičce + stav na `/admin/update`), musí běžet
denní cron `cmd/cron-version-check.(sh/cmd)` — viz [Aktualizace](98_Aktualizace.md).

#### Plné detaily

Recovery při zaseknutém upgradu, test workflow z `master`, externí
monitoring přes `/api/version` → kapitola [Aktualizace](98_Aktualizace.md).

## 3.10 Image: alpine/nginx (default) + Debian fallback

Pro hosting s omezeným diskem a pamětí je **default image alpine/nginx** —
běží na `php:8.5-fpm-alpine` + **nginx** + **php-fpm** místo Debian/Apache.
Funkčně je identický (stejné API, `.htaccess` přeložený 1:1 do nginx configu),
jen výrazně štíhlejší:

| Metrika                         | Debian/Apache (fallback) | **Alpine/nginx (default)** |
|---------------------------------|---------------|--------------|
| Velikost image (`docker image inspect .Size`) | ~328 MB | **~132 MB** (−60 %) |
| RAM aplikace (idle)             | desítky MB (Apache prefork) | **~26 MB** (php-fpm ondemand) |
| Web server                      | Apache + `.htaccess` | nginx |

Obě varianty obsahují PHP rozšíření **imagick** (+~35–40 MB), bez kterého by
nefungovaly náhledy PDF dokumentů a import fotek ve formátu HEIC/HEIF z iPhonu.

GHCR `:latest` (i `:X.Y.Z`, `:X.Y`) používá **Alpine Linux**. Lokální build
(`docker compose build`) staví taky alpine z `Dockerfile.alpine`.

### 3.10.1 Migrace existující instalace = nic navíc

`/data` i DB volume jsou **plně kompatibilní** mezi variantami (www-data má
v obou uid 33). Existující Debian instalace se proto zmigruje **sama při
příštím updatu**:

```bash
cmd/docker-update.sh        # registry: pull :latest (= alpine) + recreate; data zůstanou
```

### 3.10.2 Lokální Debian varianta

Pokud pro své prostředí potřebuješ Debian/Apache, sestav image lokálně ze
souboru `Dockerfile`; CI tuto variantu do GHCR nepublikuje:

```bash
# source: postav Debian image lokálně z Dockerfile
docker build -f Dockerfile -t myucto:latest .
docker compose up -d
```

### 3.10.3 RAM tuning

Pro stroje s ~512 MB–1 GB RAM lze sáhnout na tyto proměnné (alpine entrypoint
je čte při startu):

```bash
PHP_FPM_MAX_CHILDREN=4    # méně php-fpm workerů (každý ~30–60 MB); default 8
OPCACHE_MEMORY=64         # menší opcache shared paměť v MB; default 128
```

MariaDB tuning je už v obou compose souborech: `performance-schema=OFF`
(~100–200 MB méně RAM), buffer pool 128 MB (RAM) a **redo log 48 MB** místo
defaultních 96 MB (~50 MB méně na disku — fresh MariaDB data dir tak spadne
z ~173 MB na ~120 MB; vlastní data faktur jsou jen jednotky MB). Pro nejmenší
stroje přidej do `.env`:

```bash
DB_INNODB_BUFFER_POOL=64M   # RAM (buffer pool)
DB_INNODB_LOG_SIZE=32M      # disk (redo log) — ušetří dalších ~16 MB
```

> 🛈 MariaDB redo log se při startu s jinou velikostí bezpečně přesází (po čistém
> shutdownu), data zůstávají. Změna se projeví při příštím recreatnutí db kontejneru.

### 3.10.4 Úklid starých image (uvolnění disku)

Po updatech zůstávají osiřelé image. `docker-update` sám uklidí dangling
vrstvy; staré **tagované** verze smaž explicitně:

```bash
cmd/docker-prune-images.sh --dry-run   # napřed vypiš, co by smazal
cmd/docker-prune-images.sh             # smaže obsolete (běžící + compose image chrání)
```

## 3.11 Instalace přes Portainer / Dockge (GUI, bez příkazové řádky)

Protože je image veřejný na GHCR, jde MyÚčto nasadit i čistě přes webové
GUI správce kontejnerů — **bez klonování repa, bez SSH, bez `cfg.docker.php`**.
Veškerá konfigurace se předává proměnnými prostředí (12-factor).

K tomu slouží samostatný compose **`docker-compose.portainer.yml`** (nemá
`build:` ani bind-mount cfg souboru — jen `image:` z GHCR a `environment:`).

> 🔑 **Povinné proměnné** (vygeneruj a poznač si):
>
> ```bash
> openssl rand -base64 28   # → DB_PASSWORD
> openssl rand -base64 28   # → DB_ROOT_PASSWORD
> openssl rand -base64 32   # → MYINVOICE_PEPPER
> openssl rand -base64 32   # → MYINVOICE_SECRET_KEY (doporučené, jinak fallback z pepperu)
> ```

### 3.11.1 Portainer — App Template (one-click)

Nejjednodušší cesta. Přidej katalog šablon a nasaď z formuláře:

1. **Settings → App Templates → URL** vlož:
   ```
   https://raw.githubusercontent.com/radekhulan/myucto/master/portainer-template.json
   ```
   a ulož.
2. **App Templates** → najdi dlaždici **MyÚčto.cz** → klikni.
3. Vyplň proměnné (povinné DB hesla + pepper; ostatní mají rozumný default) →
   **Deploy the stack**.
4. Otevři **http://&lt;host&gt;:8080** → doběhne [setup wizard](07_Setup_wizard.md).

Portainer si compose stáhne z repa sám (`repository.stackfile =
docker-compose.portainer.yml`), pulne image z GHCR a spustí stack. DB migrace
se spustí automaticky při startu kontejneru.

### 3.11.2 Portainer — ruční Stack (web editor)

Když nechceš přidávat katalog šablon:

1. **Stacks → Add stack → Web editor**.
2. Vlož obsah `docker-compose.portainer.yml` (zkopíruj z
   [repa](https://github.com/radekhulan/myucto/blob/master/docker-compose.portainer.yml)).
3. Dole v **Environment variables** přidej proměnné (`DB_PASSWORD`,
   `DB_ROOT_PASSWORD`, `MYINVOICE_PEPPER`, případně `MYINVOICE_SECRET_KEY`,
   `APP_PORT`) → **Deploy the stack**.

Alternativně **Add stack → Repository**: URL `https://github.com/radekhulan/myucto`,
Compose path `docker-compose.portainer.yml`.

### 3.11.3 Dockge

Dockge drží stacky jako reálné soubory na disku, takže pasuje na compose 1:1:

1. **+ Compose** → název stacku (např. `myucto`).
2. Do editoru vlož `docker-compose.portainer.yml`.
3. Do `.env` panelu doplň proměnné:
   ```env
   DB_PASSWORD=...
   DB_ROOT_PASSWORD=...
   MYINVOICE_PEPPER=...
   MYINVOICE_SECRET_KEY=...
   APP_PORT=8080
   ```
4. **Save → Start**. Logy a interaktivní terminál máš přímo v Dockge.

### 3.11.4 HTTPS / produkce

Default compose jede HTTP-friendly cookies (`MYINVOICE_SESSION_COOKIE_SECURE=false`,
`MYINVOICE_SESSION_COOKIE_NAME=myinvoice_session`) — login funguje hned přes
`http://host:8080`. Jakmile dáš před stack **HTTPS reverse proxy**
(viz [§ 3.8](#38-https-tls-terminace)), přepni v proměnných:

```env
MYINVOICE_APP_URL=https://faktury.firma.cz
MYINVOICE_SESSION_COOKIE_SECURE=true
MYINVOICE_SESSION_COOKIE_NAME=__Host-myinvoice_session
```

a stack překresli (Portainer: *Update the stack* / Dockge: *Restart*). Proxy musí
posílat `X-Forwarded-Proto: https`, jinak vznikne redirect loop.

### 3.11.5 Aktualizace v GUI

Aktualizace stáhne aktuální image a znovu vytvoří kontejner; migrace doběhnou
při startu:

- **Portainer:** Stacks → MyÚčto → **Update the stack** se zapnutým
  *Re-pull image and redeploy* (u App Template / git stacku *Pull and redeploy*).
- **Dockge:** tlačítko **Update** u stacku.

> 💡 V produkci radši **pinni konkrétní neměnný release tag** (v compose změň
> `:latest` například na `:X.Y.Z`) a aktualizuj vědomě — u účetní aplikace
> nedoporučujeme
> slepý auto-update přes Watchtower na `:latest`. In-app upgrade z UI (Systém →
> Aktualizace) i host watcher z [§ 3.9](#39-update-watcher-jednoclick-upgrade-z-ui-volitelne)
> jsou pro Portainer/Dockge zbytečné — update je tu otázkou jednoho tlačítka.

> 🛈 **Redis (volitelné):** stack má `redis` službu pod profilem `redis`.
> Profil se aktivuje proměnnou `COMPOSE_PROFILES=redis`, kterou **formulář App
> Template nenabízí** — přes one-click šablonu (§ 3.11.1) tedy Redis nasadit
> nejde. Použij ruční stack (§ 3.11.2) nebo Dockge (§ 3.11.3) a doplň proměnné:
>
> ```env
> COMPOSE_PROFILES=redis
> MYINVOICE_REDIS_ENABLED=true
> MYINVOICE_REDIS_HOST=redis
> ```
>
> Bez Redisu appka jede na databázovém fallbacku (sessiony v `sessions`,
> rate limit v `rate_limit_counters`), takže je to čistě volitelné.
> Pozor: samotné `MYINVOICE_REDIS_ENABLED=true` bez aktivního profilu znamená,
> že se aplikace pokouší spojit s neexistujícím hostem.
