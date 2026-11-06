# 3. Instalace - Docker

> Návod, jak nainstalovat MyÚčto v Dockeru za několik minut. Pro správce, který
> má server nebo počítač s Dockerem a chce mít aplikaci rychle v provozu.

## 3.1 Kdy to potřebujete

Kapitolu otevřete, když:

- instalujete MyÚčto poprvé a chcete nejrychlejší, doporučenou cestu (cca 3 minuty),
- chcete aplikaci nasadit na server jen s Dockerem, bez klonu repozitáře,
- spravujete kontejnery přes Portainer nebo Dockge a nechcete používat příkazovou řádku,
- potřebujete před aplikaci dát HTTPS,
- chcete změnit port, zapnout Redis nebo ušetřit paměť na malém stroji,
- chcete aplikaci aktualizovat nebo zapnout tlačítko **Aktualizovat** v aplikaci.

<!-- cols: 40 60 -->
| Situace | Kam jít |
|---|---|
| Chci nejrychlejší instalaci z hotového image | [§ 3.3](#33-krok-za-krokem-instalace-z-hotoveho-image-ghcr) |
| Chci image sestavit lokálně z repozitáře | [§ 3.4](#34-krok-za-krokem-instalace-sestavenim-ze-zdrojovych-kodu) |
| Na serveru nechci klon repozitáře | [§ 3.5](#35-krok-za-krokem-instalace-bez-klonu-repozitare) |
| Používám Portainer nebo Dockge | [§ 3.9](#39-krok-za-krokem-instalace-pres-portainer-nebo-dockge) |
| Potřebuji HTTPS | [§ 3.8](#38-krok-za-krokem-https-pres-reverse-proxy) |
| Chci aktualizovat | [§ 3.7](#37-krok-za-krokem-aktualizace) |

## 3.2 Než začnete

Potřebujete:

1. **Docker Desktop** (Windows, macOS) nebo **Docker Engine s compose pluginem** (Linux).
2. U variant s klonem repozitáře příkaz `git`.
3. Na Windows **PowerShell 7** (`pwsh`). Windows PowerShell 5.1 instalační skripty odmítne.

Klon repozitáře je společný krok většiny variant:

```bash
git clone https://github.com/radekhulan/myucto.git myucto
cd myucto
```

Pak zvolte variantu podle toho, jestli chcete stáhnout hotový image z GHCR (rychlejší, bez lokálního buildu), nebo ho sestavit lokálně (vhodné pro vývoj a vlastní úpravy).

> [!WARNING]
> Na WSL2 nebo Linuxu může po klonu skript `./cmd/docker-ghcr.sh` hlásit `Permission denied` nebo `/usr/bin/env: 'bash\r': No such file…`. Příčinou je `core.autocrlf=true`, který na checkoutu převádí LF na CRLF. Opravte to jednorázově (viz [§ 3.11](#311-kdyz-neco-nejde)). Na Linuxu nesmí být autocrlf nikdy zapnutý.

## 3.3 Krok za krokem: instalace z hotového image (GHCR)

Stáhne hotový multi-arch image `ghcr.io/radekhulan/myucto:latest` (`linux/amd64` a `linux/arm64`). Na hostu nepotřebujete `pnpm`, `composer` ani několikaminutový build.

1. Naklonujte repozitář a přejděte do něj (viz [§ 3.2](#32-nez-zacnete)).
2. Spusťte instalační skript.

   ```bash
   # Linux / macOS
   cmd/docker-ghcr.sh

   # Windows - v PowerShellu 7 (pwsh)
   pwsh -File .\cmd\docker-ghcr.ps1
   ```

3. Počkejte, až skript doběhne. Postupně:
   1. vygeneruje `.env` s náhodnými hesly databáze (28 znaků base64),
   2. vygeneruje `cfg.docker.php` z `cfg.sample.php` (host `db` / `redis`, náhodné `app.pepper`, `secret_encryption_key` a `payroll_hash_key`, produkční `app.env`; cookies se řídí schématem `app.url`: pro výchozí `http://localhost:8080` bez příznaku Secure, pro HTTPS adresu s ním),
   3. provede `docker compose pull` (stáhne image z GHCR),
   4. spustí stack, počká na zdravou databázi a spustí migrace.
4. Otevřete aplikaci (viz [§ 3.6](#36-krok-za-krokem-prvni-otevreni-aplikace)).

**Jak poznáte, že je hotovo:** skript skončí bez chyby a na `http://localhost:8080` se zobrazí průvodce prvním spuštěním.

Volby skriptu (platí jen při prvním vygenerování `cfg.docker.php`): `--url=https://ucto.firma.cz` (PowerShell `-AppUrl`) nastaví veřejnou adresu a s HTTPS zapne Secure cookie, `--dev` (`-Dev`) zapne vývojový režim `app.env = development` jen pro čistě lokální instalaci. Pokud instalaci později zveřejníte přes HTTPS, nastavte `session.cookie_secure = true`; jinak na to upozorní stránka Aktualizace.

Tato varianta používá `docker-compose.production.yml` (jen image, žádný blok `build:`). Další příkazy `docker compose` proto potřebují volbu `-f docker-compose.production.yml` (viz [§ 3.12.5](#3125-denni-provoz)).

> [!TIP]
> V produkci připněte konkrétní neměnný release tag. V `docker-compose.production.yml` změňte `:latest` na konkrétní tag (například `:X.Y.Z`). Aktualizaci pak provádějte přes `cmd/docker-update.{sh,ps1}`.

## 3.4 Krok za krokem: instalace sestavením ze zdrojových kódů

Postaví image lokálně z repozitáře. Vhodné pro vývoj a vlastní úpravy.

1. Naklonujte repozitář a přejděte do něj (viz [§ 3.2](#32-nez-zacnete)).
2. Spusťte instalační skript.

   ```bash
   # Linux / macOS
   cmd/docker-install.sh

   # Windows - v PowerShellu 7 (pwsh)
   pwsh -File .\cmd\docker-install.ps1
   ```

3. Počkejte, až skript doběhne. Postupně:
   1. vygeneruje `.env` s náhodnými hesly databáze (28 znaků base64),
   2. vygeneruje `cfg.docker.php` z `cfg.sample.php` (host `db` / `redis`, náhodné `app.pepper`, `secret_encryption_key` a `payroll_hash_key`, produkční `app.env`; cookies se řídí schématem `app.url`: pro výchozí `http://localhost:8080` bez příznaku Secure, pro HTTPS adresu s ním),
   3. postaví image `myucto:latest` (multi-stage: build Vue, composer, PHP 8.5 + nginx + php-fpm z `Dockerfile.alpine`),
   4. spustí stack: **app** (nginx na portu 80, na hostu `8080`) a **db** (MariaDB 11.8),
   5. počká na zdravou databázi a spustí migrace.
4. Otevřete aplikaci (viz [§ 3.6](#36-krok-za-krokem-prvni-otevreni-aplikace)).

**Jak poznáte, že je hotovo:** skript skončí bez chyby a na `http://localhost:8080` se zobrazí průvodce prvním spuštěním.

Volby skriptu (platí jen při prvním vygenerování `cfg.docker.php`): `--url=https://ucto.firma.cz` (PowerShell `-AppUrl`) nastaví veřejnou adresu a s HTTPS zapne Secure cookie, `--dev` (`-Dev`) zapne vývojový režim `app.env = development` jen pro čistě lokální instalaci. Pokud instalaci později zveřejníte přes HTTPS, nastavte `session.cookie_secure = true`; jinak na to upozorní stránka Aktualizace.

## 3.5 Krok za krokem: instalace bez klonu repozitáře

Hodí se pro produkční Linux server, kde je jen Docker. GHCR image obsahuje veškerý kód i migrace, z repozitáře potřebujete jen tři malé soubory.

### 3.5.1 Varianta C1: jedním skriptem (doporučeno)

Skript se chová stejně jako [§ 3.3](#33-krok-za-krokem-instalace-z-hotoveho-image-ghcr): náhodná hesla, vygenerovaný `cfg.docker.php`, stažení image, migrace.

1. Vytvořte adresář a stáhněte soubory.

   ```bash
   mkdir myucto && cd myucto
   curl -O https://raw.githubusercontent.com/radekhulan/myucto/master/docker-compose.production.yml
   curl -O https://raw.githubusercontent.com/radekhulan/myucto/master/cfg.sample.php
   curl -O https://raw.githubusercontent.com/radekhulan/myucto/master/cmd/docker-ghcr.sh
   curl -O https://raw.githubusercontent.com/radekhulan/myucto/master/cmd/lib/env-load.sh
   chmod +x docker-ghcr.sh
   ```

2. Spusťte `./docker-ghcr.sh`. Skript najde `docker-compose.production.yml` v aktuálním adresáři, nic nepřejmenovávejte.

**Jak poznáte, že je hotovo:** skript skončí bez chyby a na `http://localhost:8080` se zobrazí průvodce prvním spuštěním.

Volby skriptu (platí jen při prvním vygenerování `cfg.docker.php`): `--url=https://ucto.firma.cz` (PowerShell `-AppUrl`) nastaví veřejnou adresu a s HTTPS zapne Secure cookie, `--dev` (`-Dev`) zapne vývojový režim `app.env = development` jen pro čistě lokální instalaci. Pokud instalaci později zveřejníte přes HTTPS, nastavte `session.cookie_secure = true`; jinak na to upozorní stránka Aktualizace.

`env-load.sh` je parser souboru `.env` (skript ho jinak dotáhne sám). Díky němu smí být v `.env` i hodnota s mezerou bez uvozovek.

### 3.5.2 Varianta C2: ručně, bez skriptu

Zvolte ji, když chcete plnou kontrolu nad `cfg.docker.php` a `.env`. Hesla a klíče se negenerují automaticky, musíte je doplnit sami.

1. Stáhněte soubory a připravte konfiguraci.

   ```bash
   mkdir myucto && cd myucto
   curl -O https://raw.githubusercontent.com/radekhulan/myucto/master/docker-compose.production.yml
   curl -O https://raw.githubusercontent.com/radekhulan/myucto/master/cfg.sample.php
   mv docker-compose.production.yml docker-compose.yml
   cp cfg.sample.php cfg.docker.php
   ```

2. V `cfg.docker.php` nastavte minimálně:
   - `db.host` na `db`, `db.user` na `myucto`, `db.pass` na heslo z `.env`,
   - `app.pepper` a `secret_encryption_key` (obojí `openssl rand -base64 32`).
3. Vytvořte `.env` s hesly.

   ```bash
   cat > .env <<EOF
   DB_PASSWORD=$(openssl rand -base64 28)
   DB_ROOT_PASSWORD=$(openssl rand -base64 28)
   EOF
   ```

4. Spusťte stack a migrace.

   ```bash
   docker compose up -d
   docker compose exec --user www-data app php api/bin/migrate.php
   ```

**Jak poznáte, že je hotovo:** `docker compose ps` ukazuje běžící služby **app** a **db** a na `http://localhost:8080` se zobrazí průvodce prvním spuštěním.

> [!TIP]
> V Dockeru se migrace spouštějí automaticky při startu kontejneru. Ruční `php api/bin/migrate.php` zůstává bezpečným záložním postupem, protože migrace jsou idempotentní.

## 3.6 Krok za krokem: první otevření aplikace

1. V prohlížeči otevřete **`http://localhost:8080`**. Použijte `http://` a explicitní port `:8080`.
2. Projděte průvodce prvním spuštěním (viz [7. První spuštění](07_Setup_wizard.md)).

**Jak poznáte, že je hotovo:** zobrazí se průvodce prvním spuštěním a po jeho dokončení přihlašovací stránka.

Přístup z jiného stroje (LAN IP, hostname) funguje také, například `http://10.0.0.8:8080`. Adresa `app.url` se uloží podle URL, kterou v průvodci použijete. Potřebujete-li ji znát předem (produkční doména a reverse proxy), spusťte kontejner s `-e MYINVOICE_APP_URL=https://invoice.example.com`.

> [!WARNING]
> Docker stack běží na prostém HTTP. Při zadání `https://` nebo výchozího portu prohlížeč hlásí `SSL_ERROR_RX_RECORD_TOO_LONG` nebo `ERR_SSL_PROTOCOL_ERROR`. HTTPS zajistíte podle [§ 3.8](#38-krok-za-krokem-https-pres-reverse-proxy).

Přístup z LAN přes IP (`10.*`, `172.16-31.*`, `192.168.*`), `127.*`, `localhost` a `*.local` je vyjmut z přesměrování na HTTPS v `.htaccess` a `web.config`. Přesměrování se přeskočí i u požadavku s hlavičkou `X-Forwarded-Proto: https` (reverse proxy s TLS terminací).

> [!WARNING]
> Přístup přes LAN IP a prosté HTTP je vhodný pro prvotní instalaci, ale passkeys na něm nejsou podporované. Pro passkeys použijte stabilní hostname, důvěryhodné HTTPS a nastavte `app.url` na přesný veřejný origin. Výjimkou pro lokální vývoj je `http://localhost`.

## 3.7 Krok za krokem: aktualizace

Aktualizace stáhne nový image, restartuje stack a doběhne čekající migrace. Data v databázi zůstávají zachována.

1. Přejděte do adresáře instalace.
2. Spusťte aktualizační skript.

   ```bash
   # Linux / macOS
   cmd/docker-update.sh

   # Windows - v PowerShellu 7 (pwsh)
   pwsh -File .\cmd\docker-update.ps1
   ```

3. Počkejte na dokončení skriptu.

**Jak poznáte, že je hotovo:** skript skončí bez chyby a v `Systém → Aktualizace` ukazuje **Aktuální verze** novou verzi.

Skript režim detekuje z image běžícího kontejneru: `ghcr.io/...` znamená registry (`pull`), lokální build znamená source (`git pull` a rebuild). Když stack neběží, ale máte lokálně stažený GHCR image, bere to také jako registry. Režim lze přebít proměnnou `MYINVOICE_UPDATE_MODE=registry|source`. Nový image se publikuje automaticky při každém release tagu `v*.*.*`.

Instalace bez klonu repozitáře (varianta C) se aktualizuje takto:

```bash
docker compose -f docker-compose.production.yml pull
docker compose -f docker-compose.production.yml up -d
```

> [!TIP]
> Administrátor může aktualizovat i tlačítkem **Aktualizovat na {verze}** v `Systém → Aktualizace`. Aby tlačítko fungovalo, musí na hostu běžet watcher (viz [§ 3.10](#310-krok-za-krokem-tlacitko-aktualizovat-v-aplikaci-update-watcher)). Aby aplikace sama ukazovala, že je dostupná nová verze, naplánujte `php api/bin/cron-version-check.php` jednou denně (viz [Aktualizace](102_Aktualizace.md)).

## 3.8 Krok za krokem: HTTPS přes reverse proxy

Docker stack sám TLS nedělá. Nginx v kontejneru poslouchá na portu 80 a mapuje se na host port `8080`. Pro HTTPS postavte před stack reverse proxy s TLS terminací. Tři rozumné cesty:

1. **Caddy** (nejjednodušší): automatický Let's Encrypt pro doménu nebo self-signed certifikát pro IP.
2. **Nginx s vlastním certifikátem** (`mkcert` nebo `openssl`): pro intranet bez veřejné domény.
3. **Cloudflare Tunnel nebo Tailscale Funnel**: veřejný přístup bez otevírání portů na firewallu.

Postup s Caddy jako dalším kontejnerem vedle stacku:

1. V kořeni repozitáře (vedle `docker-compose.production.yml`) vytvořte soubor `Caddyfile`.

   ```
   faktury.tvojefirma.cz {
       reverse_proxy localhost:8080
   }
   ```

2. Spusťte Caddy na síti hostu, aby viděl port `8080`.

   ```bash
   docker run -d --name caddy --restart unless-stopped \
     --network host \
     -v "$PWD/Caddyfile:/etc/caddy/Caddyfile:ro" \
     -v caddy_data:/data \
     -v caddy_config:/config \
     caddy:2
   ```

3. V `cfg.docker.php` přepněte produkční nastavení.

   ```php
   'app' => [
       'url' => 'https://faktury.tvojefirma.cz',  // doslova to, co uživatel vidí v adresním řádku
       ...
   ],
   'session' => [
       'cookie_secure'   => true,
       'cookie_name'     => '__Host-myinvoice_session',
       'cookie_samesite' => 'Lax',
   ],
   ```

4. Restartujte aplikaci: `docker compose -f docker-compose.production.yml restart app` (u varianty B bez volby `-f`).

**Jak poznáte, že je hotovo:** `https://faktury.tvojefirma.cz` se otevře s platným certifikátem a přihlášení funguje.

Caddy si certifikát vyžádá sám (potřebuje veřejně dostupné porty 80 a 443 a A/AAAA záznam domény) a sám ho obnovuje. Hlavičku `X-Forwarded-Proto: https` posílá automaticky. To je důležité: `.htaccess` v repozitáři bez ní vynucuje přesměrování HTTP na HTTPS a vzniká nekonečná smyčka přesměrování.

> [!WARNING]
> Cookie s prefixem `__Host-` vyžaduje HTTPS. Pokud po této změně otevřete aplikaci přes `http://`, přihlášení se rozbije, protože se cookie neuloží.

`app.url` se používá v odkazech v e-mailech (faktury, reset hesla, upomínky). Musí přesně odpovídat veřejné URL, jinak odkazy povedou na špatnou doménu nebo na `localhost:8080`. Pokud instance z internetu dostupná není (jen LAN nebo VPN), vypněte odkaz na web fakturu v e-mailech klientům (viz [§ 16.5.1](16_Faktura_PDF.md#161012-vypnuti-web-faktury-na-instalaci)).

Stejná hodnota je bezpečnostní autoritou pro WebAuthn. Určuje přesný origin a hostname (RP ID), pro který lze passkey vytvořit a použít. Neodvozuje se z hlavičky `Host` ani z hlaviček proxy. Změna `app.url` na jiný hostname proto zneplatní dříve registrované passkeys. Před změnou ověřte TOTP nebo jinou cestu obnovy přístupu.

## 3.9 Krok za krokem: instalace přes Portainer nebo Dockge

Protože je image veřejný na GHCR, jde MyÚčto nasadit přes webové rozhraní správce kontejnerů, bez klonování repozitáře, bez SSH a bez `cfg.docker.php`. Konfigurace se předává proměnnými prostředí. K tomu slouží samostatný soubor `docker-compose.portainer.yml` (jen `image:` z GHCR a `environment:`, bez `build:` a bez bind-mountu cfg souboru).

Nejdřív vygenerujte a poznamenejte si povinné hodnoty:

```bash
openssl rand -base64 28   # DB_PASSWORD
openssl rand -base64 28   # DB_ROOT_PASSWORD
openssl rand -base64 32   # MYINVOICE_PEPPER
openssl rand -base64 32   # MYINVOICE_SECRET_KEY (doporučené, jinak fallback z pepperu)
```

### 3.9.1 Portainer: šablona aplikace (one-click)

1. V Portaineru otevřete **Settings → App Templates** a do pole **URL** vložte `https://raw.githubusercontent.com/radekhulan/myucto/master/portainer-template.json`. Uložte.
2. Otevřete **App Templates**, najděte dlaždici **MyÚčto.cz** a klikněte na ni.
3. Vyplňte proměnné (povinná hesla DB a pepper, ostatní mají rozumnou výchozí hodnotu) a klikněte na **Deploy the stack**.
4. Otevřete `http://<host>:8080`. Spustí se průvodce prvním spuštěním (viz [7. První spuštění](07_Setup_wizard.md)).

**Jak poznáte, že je hotovo:** stack je ve stavu běžící a na portu 8080 se zobrazí průvodce.

Portainer si compose stáhne z repozitáře sám (`repository.stackfile = docker-compose.portainer.yml`), stáhne image z GHCR a stack spustí. Migrace databáze proběhnou automaticky při startu kontejneru.

### 3.9.2 Portainer: ruční stack (webový editor)

1. Otevřete **Stacks → Add stack → Web editor**.
2. Vložte obsah `docker-compose.portainer.yml` (zkopírujte z [repozitáře](https://github.com/radekhulan/myucto/blob/master/docker-compose.portainer.yml)).
3. V části **Environment variables** přidejte `DB_PASSWORD`, `DB_ROOT_PASSWORD`, `MYINVOICE_PEPPER`, případně `MYINVOICE_SECRET_KEY` a `APP_PORT`.
4. Klikněte na **Deploy the stack**.

Alternativně použijte **Add stack → Repository** s URL `https://github.com/radekhulan/myucto` a cestou compose `docker-compose.portainer.yml`.

### 3.9.3 Dockge

Dockge drží stacky jako soubory na disku, takže pasuje na compose 1:1.

1. Klikněte na **+ Compose** a zadejte název stacku (například `myucto`).
2. Do editoru vložte `docker-compose.portainer.yml`.
3. Do panelu `.env` doplňte proměnné.

   ```env
   DB_PASSWORD=...
   DB_ROOT_PASSWORD=...
   MYINVOICE_PEPPER=...
   MYINVOICE_SECRET_KEY=...
   APP_PORT=8080
   ```

4. Klikněte na **Save** a potom na **Start**. Logy a interaktivní terminál máte přímo v Dockge.

### 3.9.4 HTTPS a produkce v Portaineru a Dockge

Výchozí compose používá cookies vhodné pro HTTP (`MYINVOICE_SESSION_COOKIE_SECURE=false`, `MYINVOICE_SESSION_COOKIE_NAME=myinvoice_session`), takže přihlášení funguje hned přes `http://host:8080`. Jakmile před stack dáte HTTPS reverse proxy (viz [§ 3.8](#38-krok-za-krokem-https-pres-reverse-proxy)), přepněte proměnné:

```env
MYINVOICE_APP_URL=https://faktury.firma.cz
MYINVOICE_SESSION_COOKIE_SECURE=true
MYINVOICE_SESSION_COOKIE_NAME=__Host-myinvoice_session
```

Pak stack znovu vytvořte (Portainer: **Update the stack**, Dockge: **Restart**). Proxy musí posílat `X-Forwarded-Proto: https`, jinak vznikne smyčka přesměrování.

### 3.9.5 Aktualizace v Portaineru a Dockge

Aktualizace stáhne aktuální image a znovu vytvoří kontejner. Migrace doběhnou při startu.

- **Portainer:** otevřete **Stacks**, vyberte stack MyÚčto a klikněte na **Update the stack** se zapnutou volbou *Re-pull image and redeploy* (u šablony nebo git stacku *Pull and redeploy*).
- **Dockge:** klikněte na tlačítko **Update** u stacku.

> [!TIP]
> V produkci připněte konkrétní neměnný release tag (v compose změňte `:latest` například na `:X.Y.Z`) a aktualizujte vědomě. U účetní aplikace nedoporučujeme slepý automatický update přes Watchtower na `:latest`. Aktualizace z aplikace i watcher z [§ 3.10](#310-krok-za-krokem-tlacitko-aktualizovat-v-aplikaci-update-watcher) jsou pro Portainer a Dockge zbytečné.

### 3.9.6 Redis v Portaineru a Dockge

Služba `redis` je ve stacku pod profilem `redis`. Profil se zapíná proměnnou `COMPOSE_PROFILES=redis`, kterou formulář šablony aplikace nenabízí. Přes one-click šablonu ([§ 3.9.1](#391-portainer-sablona-aplikace-one-click)) tedy Redis nasadit nejde. Použijte ruční stack ([§ 3.9.2](#392-portainer-rucni-stack-webovy-editor)) nebo Dockge ([§ 3.9.3](#393-dockge)) a doplňte proměnné:

```env
COMPOSE_PROFILES=redis
MYINVOICE_REDIS_ENABLED=true
MYINVOICE_REDIS_HOST=redis
```

Bez Redisu aplikace běží na databázovém fallbacku (sessions v tabulce `sessions`, rate limit v `rate_limit_counters`), takže je Redis čistě volitelný. Samotné `MYINVOICE_REDIS_ENABLED=true` bez aktivního profilu znamená, že se aplikace pokouší spojit s neexistujícím hostem.

## 3.10 Krok za krokem: tlačítko Aktualizovat v aplikaci (update watcher)

Administrátor vidí v `Systém → Aktualizace` stav verze a tlačítko **Aktualizovat na {verze}**, které zařadí upgrade do fronty. Aby ho někdo provedl, musí na hostu běžet **watcher**. Je to proces, který přes `docker compose exec` sleduje příznakový soubor uvnitř kontejneru a spouští `cmd/docker-update.(sh/ps1)`. Bez watcheru tlačítko nikam nedojede (aplikace zůstane ve stavu „Upgrade probíhá…") a upgrade musíte provést ručně přes shell (viz [§ 3.7](#37-krok-za-krokem-aktualizace)).

### 3.10.1 Test v popředí

Než z watcheru uděláte službu, vyzkoušejte ho v běžícím okně.

1. Spusťte watcher.

   ```bash
   # Linux / macOS
   cd /opt/myucto
   bash cmd/docker-update-watcher.sh
   ```

   ```powershell
   # Windows - PowerShellem, který máte (upravte cd na SVOU instalační cestu)
   cd C:\inetpub\myucto
   pwsh -NoProfile -ExecutionPolicy Bypass -File cmd\docker-update-watcher.ps1
   # nemáte-li PowerShell 7, použijte místo `pwsh` příkaz `powershell` (Windows PS 5.1)
   ```

2. Ověřte, že vidíte `[watcher] start, polling storage/upgrade-requested.json inside container every 30s`.
3. V aplikaci klikněte na **Aktualizovat na {verze}**. Do 30 sekund watcher příznak zachytí, spustí `cmd/docker-update.(sh/ps1)` a výsledek zapíše zpět.
4. Watcher zastavíte klávesou `Ctrl+C`.

Watcher spouští aktualizaci stejným PowerShell hostem, pod kterým sám běží (`pwsh` i `powershell`), a cesty řeší z umístění skriptu. Funguje proto i z jiného adresáře a na strojích jen s PowerShell 7.

### 3.10.2 Linux: služba systemd (produkce)

1. Vytvořte jednotku.

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
   ```

2. Zapněte ji.

   ```bash
   sudo systemctl daemon-reload
   sudo systemctl enable --now myucto-update-watcher
   ```

Logy: `journalctl -u myucto-update-watcher -f`.

### 3.10.3 Windows: naplánovaná úloha (produkce)

```powershell
# Upravte cestu k SVÉ instalaci. Máte-li jen Windows PowerShell 5.1, nahraďte
# `pwsh.exe` za `powershell.exe`.
schtasks /create /tn "MyUcto Update Watcher" `
  /tr "pwsh.exe -NoProfile -ExecutionPolicy Bypass -File C:\inetpub\myucto\cmd\docker-update-watcher.ps1" `
  /sc onstart /ru SYSTEM /rl HIGHEST
schtasks /run /tn "MyUcto Update Watcher"
```

Stav úlohy: `schtasks /query /tn "MyUcto Update Watcher" /v /fo list`.

`pwsh.exe` musí být v PATH (dává ji tam instalátor PowerShell 7). Pokud ji naplánovaná úloha nenajde, zadejte plnou cestu `C:\Program Files\PowerShell\7\pwsh.exe`, nebo použijte `powershell.exe` (PS 5.1).

**Jak poznáte, že je hotovo:** po kliknutí na **Aktualizovat na {verze}** se stav v aplikaci změní na „Upgrade probíhá…" a po dokončení na „Upgrade úspěšně dokončen".

### 3.10.4 Denní kontrola dostupné aktualizace

Watcher reaguje jen na kliknutí. Aby administrátor viděl, že je dostupná aktualizace (štítek v patičce a stav v `Systém → Aktualizace`), musí běžet denní úloha `cmd/cron-version-check.(sh/cmd)` (viz [Aktualizace](102_Aktualizace.md)). Obnovu zaseknutého upgradu, test z `master` a externí monitoring přes `/api/version` popisuje také kapitola [Aktualizace](102_Aktualizace.md).

## 3.11 Když něco nejde

<!-- cols: 34 30 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Prohlížeč hlásí `SSL_ERROR_RX_RECORD_TOO_LONG` nebo `ERR_SSL_PROTOCOL_ERROR` | Prohlížeč mluví TLS, ale server odpovídá prostým HTTP | Použijte `http://` a port `:8080`, nebo nastavte HTTPS podle [§ 3.8](#38-krok-za-krokem-https-pres-reverse-proxy) |
| `Permission denied` nebo `/usr/bin/env: 'bash\r': No such file…` při spuštění `.sh` skriptu | Git s `core.autocrlf=true` převedl LF na CRLF | Spusťte `sed -i 's/\r$//' cmd/*.sh`, potom `chmod +x cmd/*.sh` a `git config --global core.autocrlf input`. Repozitář má `.gitattributes` s `*.sh text eol=lf`, takže další `git clone` bude v pořádku i bez toho |
| Smyčka přesměrování po nasazení HTTPS | Proxy neposílá `X-Forwarded-Proto: https` | Nastavte hlavičku na proxy (Caddy ji posílá sám) |
| Po přepnutí na `__Host-` cookie nejde přihlášení | Aplikace je otevřená přes `http://` | Otevřete ji přes `https://` |
| Odkazy v e-mailech vedou na `localhost:8080` | `app.url` neodpovídá veřejné URL | Nastavte `app.url` na přesnou veřejnou adresu |
| Passkey po změně hostname nefunguje | WebAuthn je svázaný s hostname z `app.url` | Přihlaste se přes TOTP nebo jinou cestou obnovy a passkey zaregistrujte znovu |
| `/manual` vrací 503 „Manuál není zatím vygenerovaný" | HTML manuál nebyl vygenerován | Spusťte `docker compose -f docker-compose.production.yml exec app php tools/generateManualHtml.php` a potom stejně `php tools/exportManualToPdf.php` |
| Kontrola prostředí hlásí „Bez práva zápisu: cache" | Cache vytvořil root | Postup v [§ 3.12.6](#3126-opravneni-cache-pri-rucnim-spusteni-php) |
| Tlačítko **Aktualizovat na {verze}** nic neudělá, stav „Upgrade probíhá…" trvá | Na hostu neběží watcher | Spusťte watcher ([§ 3.10](#310-krok-za-krokem-tlacitko-aktualizovat-v-aplikaci-update-watcher)), nebo klikněte na **Zrušit / odblokovat** a aktualizujte ručně ([§ 3.7](#37-krok-za-krokem-aktualizace)) |
| Aplikace po přepnutí layoutu úložiště ukazuje prázdná data | Aplikace se dívá do prázdného `/data` | Nikdy nepřepínejte layout bez migrace, viz [§ 3.12.4](#3124-single-volume-uloziste) |
| Aplikace se nemůže spojit s Redisem | `MYINVOICE_REDIS_ENABLED=true` bez aktivního profilu `redis` | Zapněte profil (`COMPOSE_PROFILES=redis`), nebo Redis vypněte |
| Vlastní doména se nepodaří ověřit | Chybí routing, certifikát nebo TXT záznam | Postup v [§ 3.12.8](#3128-vlastni-domeny-klientskych-portalu) |

## 3.12 Podrobnosti a pravidla

### 3.12.1 Změna portu

Upravte `.env` (vznikl po prvním spuštění):

```
APP_PORT=9000          # místo 8080
DB_PORT=3308           # místo 3307 (vázán jen na 127.0.0.1)
```

Pak spusťte `docker compose up -d`. URL bude `http://localhost:9000`.

### 3.12.2 Proměnné prostředí kontejneru

Vstupní skript image podporuje:

```bash
MYINVOICE_SKIP_MIGRATIONS=1     # vypne auto-migraci při startu
MYINVOICE_MIGRATE_ATTEMPTS=20   # počet opakování migrace
MYINVOICE_MIGRATE_DELAY=3       # pauza mezi pokusy (sekundy)
MYINVOICE_DATA_DIR=/data        # výchozí v compose souborech; sjednocuje
                                # log/, storage/, private/ a cfg.local.php pod /data
MYINVOICE_AUTH_REQUIRE_MFA=true  # vyžadovat passkey nebo TOTP
MYINVOICE_AUTH_MFA_METHODS=passkey,totp
MYINVOICE_AUTH_PASSWORDLESS_LOGIN=true # povolit passkey login bez e-mailu a hesla; výchozí false
MYINVOICE_SESSION_LOCK_AFTER_MINUTES=15 # výchozí interval a maximum osobní volby; 0 nic nevynucuje
```

Výchozí je `20` pokusů s pauzou `3` sekundy. Pokud proměnné nenastavíte, použije se výchozí chování.

`MYINVOICE_DATA_DIR` je výchozí v `docker-compose.yml` i `docker-compose.production.yml` (single-volume layout `app-data:/data`). Drží `log/`, `storage/`, `private/dkim/` i `cfg.local.php`, takže konfigurace instance z průvodce přežije aktualizaci image (viz [§ 3.12.4](#3124-single-volume-uloziste)). Používáte-li původní třísvazkové úložiště, `cmd/docker-update.{sh,ps1}` ho detekuje a před `up -d` automaticky spustí `cmd/docker-migrate-volumes.{sh,ps1}`. Aktuální ukládání dat popisuje [§ 102.5](102_Aktualizace.md#10285-persistentni-data-v-dockeru).

Mount `cfg.docker.php` je volitelný. Image obsahuje stub `cfg.php` (`<?php return [];`) a vše lze předat přes proměnné prostředí (12-factor). Pro nasazení čistě přes proměnné (Railway, Heroku, Fly.io) bind-mount `./cfg.docker.php:/var/www/html/cfg.php:ro` v `docker-compose.yml` zakomentujte nebo odstraňte.

### 3.12.3 Railway a jiné PaaS

Některé PaaS (typicky Railway) vkládají nevyřešené zástupné hodnoty jako `${VAR}`, pokud proměnná není definovaná. MyÚčto je v přepisech z prostředí ignoruje, takže nepřepíší platné hodnoty z `cfg.php` nebo `cfg.docker.php`. Pokud chybí `secret_encryption_key`, aplikace použije HKDF odvozený z `app.pepper`.

### 3.12.4 Single-volume úložiště

Výchozí je single-volume layout. Všechen stavový obsah (`log/`, `storage/`, `private/dkim/` a `cfg.local.php`) leží v jediném trvalém volumu `app-data:/data`. Aktualizace image jsou tak bezpečné, konfigurace instance přežije.

| Vlastnost | Single-volume |
|---|---|
| Volume | `app-data` (a `db-data` pro MariaDB) |
| Mount point | `/data` |
| Proměnná | `MYINVOICE_DATA_DIR=/data` |
| Compose | `docker compose up -d` (výchozí) |
| Záloha | jeden `tar czf` nad `app-data` a dump DB |
| Aktualizace image | bezpečná, `cfg.local.php` v `/data` přežije |

Aplikace přes `Config::applyDataDirOverrides()` přesměruje:

- `log/` na `/data/log`,
- `storage/invoices/`, `storage/uploads/`, `storage/backup/`, `storage/sessions/`, `storage/cache/` na `/data/storage/…`,
- `private/dkim/` na `/data/private/dkim`,
- zápisy `cfg.local.php` z průvodce, `bin/setup.php` a `bin/reset.php` na `/data/cfg.local.php`.

Žádné jiné cesty se nemění (kód, vendor, `web/dist` zůstávají v `/var/www/html` pouze pro čtení).

Nová instalace přes `cmd/docker-install.{sh,ps1}` používá výchozí `docker-compose.yml` se single-volume layoutem, nic dalšího nenastavujete. Layout ověříte takto:

```bash
docker compose exec app sh -c 'echo $MYINVOICE_DATA_DIR'   # /data
docker compose exec app ls /data                            # log  storage  private  cfg.local.php (po setupu)
docker volume ls | grep myucto                              # pouze app-data + db-data
```

> [!WARNING]
> U instalace s původním třísvazkovým úložištěm nikdy nepřepínejte layout bez migrace. Aplikace by se dívala do prázdného `/data` a tvářila se, že data zmizela. `cmd/docker-update.{sh,ps1}` migraci provede automaticky před `up -d`.

`cmd/docker-migrate-volumes.{sh,ps1}` zálohuje `cfg.local.php` z běžícího kontejneru, zkopíruje data ze starých volumes do nového `app-data` přes dočasný sidecar `alpine` a obnoví `cfg.local.php`. Staré volumes nemaže (smažete je ručně po ověření). Skript je idempotentní. Rozložení dat popisuje [§ 102.5 Persistentní data v Dockeru](102_Aktualizace.md#10285-persistentni-data-v-dockeru).

#### Záloha single-volume layoutu

```bash
docker run --rm \
  -v myinvoice_app-data:/data:ro \
  -v "$PWD":/backup \
  alpine tar czf /backup/myucto-data-$(date +%F).tar.gz -C /data .
```

Spolu s dumpem MariaDB (viz [Aktualizace](102_Aktualizace.md)) jsou to dvě entity k zálohování: databáze a `app-data`.

### 3.12.5 Denní provoz

```bash
docker compose up -d                                 # start
docker compose down                                  # stop (data v pojmenovaných volumes přežijí)
docker compose down -v                               # stop a SMAZÁNÍ volumes (ZNIČÍ DB!)
docker compose logs -f app                           # živé logy
docker compose exec app bash                         # shell v kontejneru
docker compose exec --user www-data app php api/bin/migrate.php # CLI uvnitř kontejneru
cmd/docker-build.sh --no-cache                       # rebuild image (po změnách PHP/JS, jen varianta B)
```

> [!TIP]
> Po instalaci variantou z GHCR ([§ 3.3](#33-krok-za-krokem-instalace-z-hotoveho-image-ghcr)) potřebují všechny příkazy `docker compose` volbu `-f docker-compose.production.yml`, například `docker compose -f docker-compose.production.yml logs -f app`.

### 3.12.6 Oprávnění cache při ručním spuštění PHP

Aplikační PHP příkazy spouštějte v kontejneru vždy s `--user www-data`, stejně jako web a vestavěný cron. Spuštění pod rootem může vytvořit cache a její soubory zámků bez práva zápisu pro web.

Pokud kontrola prostředí hlásí „Bez práva zápisu: cache" a cache vlastní root, opravte ve výchozím layoutu vlastníka adresáře včetně jeho obsahu:

```powershell
docker compose exec --user root app chown -R www-data:www-data /data/storage/cache
```

Příkaz nic nemaže ani nemění databázi. Potom obnovte stránku kontroly prostředí. Při vlastním `MYINVOICE_DATA_DIR` nahraďte `/data` jeho skutečnou hodnotou. Ve starším layoutu bez této proměnné je cesta `/var/www/html/storage/cache`. Používáte-li `docker-compose.production.yml`, přidejte za `docker compose` volbu `-f docker-compose.production.yml`. Oprava platí pro běžící kontejner, ruční PHP příkazy nadále spouštějte jako `www-data`.

### 3.12.7 Volitelný Redis

```bash
docker compose --profile redis up -d
```

V `cfg.docker.php` nastavte `redis.enabled => true` a aplikaci restartujte. Pro Portainer a Dockge viz [§ 3.9.6](#396-redis-v-portaineru-a-dockge).

### 3.12.8 Vlastní domény klientských portálů

Vlastní doména firmy nenahrazuje `app.url`. Kanonická `app.url` zůstává stabilní adresou interního rozhraní, resetů hesla a WebAuthn RP ID. Další hostname pouze nasměrujte na stejnou instanci a potom ho založte v `Firma → Nastavení` na záložce **Údaje firmy** v sekci **Klientské domény** tlačítkem **Přidat doménu** (viz [9. Klientský portál](09_Klientsky_portal.md)).

Pro každý vlastní hostname musí provozní vrstva zajistit:

1. veřejný A/AAAA nebo CNAME záznam směrovaný na reverse proxy,
2. důvěryhodný TLS certifikát platný pro přesný hostname,
3. předání původní hlavičky `Host` a `X-Forwarded-Proto: https` do aplikace,
4. DNS TXT záznam podle výzvy zobrazené v administraci,
5. přidání hostname do seznamu povolených domén Cloudflare Turnstile, pokud je CAPTCHA zapnutá.

Aplikace certifikáty sama nevydává. Doménu aktivuje až po kontrole TXT záznamu a po HTTPS požadavku na přesný endpoint výzvy bez přesměrování. Reverse proxy tedy musí routing a certifikát připravit před kliknutím na **Ověřit DNS a HTTPS**. Caddy může více hostname obsloužit jedním blokem a pro každý automaticky získá certifikát:

```caddyfile
faktury.tvojefirma.cz, portal.klient-a.cz, portal.klient-b.cz {
    reverse_proxy localhost:8080
}
```

U nginx, Traefiku nebo Cloudflare Tunnel platí stejný princip: všechny hostname vedou do stejné aplikace, ale `Host` se nesmí přepsat na interní název služby. Neznámý nebo neaktivní Host aplikace odmítne, nestačí tedy přidat DNS a certifikát. Konfigurace Apache, IIS i nginx v repozitáři posílá SPA fallback přes stejnou serverovou kontrolu hostname.

Aktivní vlastní doména používá oddělenou host-only session cookie. Přihlášení včetně passkey proběhne na kanonické `app.url` a prohlížeč se vrátí jednorázovým PKCE tokenem. Pro každého klienta proto nemusíte (a nesmíte) registrovat novou WebAuthn RP doménu.

Každé nové přihlášení z klientské domény vyžaduje nové ověření. Existující relace na hlavní adrese se automaticky nepřebírá; přihlášení klienta zachová účet správce otevřený v hlavním okně.

### 3.12.9 Image: Alpine/nginx (výchozí) a Debian

Pro hosting s omezeným diskem a pamětí je výchozí image **Alpine/nginx**: `php:8.5-fpm-alpine` + nginx + php-fpm místo Debian/Apache. Funkčně je identický (stejné API, `.htaccess` přeložený 1:1 do konfigurace nginx), jen štíhlejší.

| Metrika | Debian/Apache (fallback) | **Alpine/nginx (výchozí)** |
|---|---|---|
| Velikost image (`docker image inspect .Size`) | ~328 MB | **~132 MB** (-60 %) |
| RAM aplikace (idle) | desítky MB (Apache prefork) | **~26 MB** (php-fpm ondemand) |
| Web server | Apache + `.htaccess` | nginx |

Obě varianty obsahují PHP rozšíření **imagick** (+35-40 MB), bez kterého by nefungovaly náhledy PDF dokumentů a import fotek ve formátu HEIC/HEIF z iPhonu. GHCR `:latest` (i `:X.Y.Z`, `:X.Y`) používá Alpine. Lokální build (`docker compose build`) staví také Alpine z `Dockerfile.alpine`.

#### Migrace stávající instalace

`/data` i DB volume jsou plně kompatibilní mezi variantami (`www-data` má v obou uid 33). Existující Debian instalace se proto přesune sama při příští aktualizaci:

```bash
cmd/docker-update.sh        # registry: pull :latest (= alpine) + recreate; data zůstanou
```

#### Lokální Debian varianta

Potřebujete-li Debian/Apache, sestavte image lokálně ze souboru `Dockerfile`. CI tuto variantu do GHCR nepublikuje.

```bash
docker build -f Dockerfile -t myucto:latest .
docker compose up -d
```

#### Ladění paměti

Pro stroje s ~512 MB-1 GB RAM lze nastavit proměnné, které Alpine entrypoint čte při startu:

```bash
PHP_FPM_MAX_CHILDREN=4    # méně php-fpm workerů (každý ~30-60 MB); výchozí 8
OPCACHE_MEMORY=64         # menší opcache v MB; výchozí 128
```

Ladění MariaDB je už v obou compose souborech: `performance-schema=OFF` (~100-200 MB méně RAM), buffer pool 128 MB a redo log 48 MB místo výchozích 96 MB (~50 MB méně na disku, čerstvý datový adresář MariaDB tak klesne z ~173 MB na ~120 MB; vlastní data faktur jsou jen jednotky MB). Pro nejmenší stroje přidejte do `.env`:

```bash
DB_INNODB_BUFFER_POOL=64M   # RAM (buffer pool)
DB_INNODB_LOG_SIZE=32M      # disk (redo log) - ušetří dalších ~16 MB
```

Redo log MariaDB se při startu s jinou velikostí bezpečně přesází (po čistém vypnutí), data zůstávají. Změna se projeví při příštím znovuvytvoření kontejneru `db`.

### 3.12.10 Úklid starých image

Po aktualizacích zůstávají osiřelé image. `docker-update` sám uklidí visící vrstvy, staré tagované verze smažte explicitně:

```bash
cmd/docker-prune-images.sh --dry-run   # nejdřív vypíše, co by smazal
cmd/docker-prune-images.sh             # smaže zastaralé (běžící a compose image chrání)
```

### 3.12.11 Manuál na /manual

GHCR image má vygenerovaný HTML manuál i PDF (`tools/generateManualHtml.php` a `tools/exportManualToPdf.php` se volají při buildu v `Dockerfile`). Adresa `http://localhost:8080/manual` proto funguje bez dalších kroků a v postranním panelu je tlačítko **Stáhnout PDF**. Nový obsah získáte aktualizací (`cmd/docker-update.{sh,ps1}` stáhne novější image i s novými kapitolami). Při chybě 503 viz [§ 3.11](#311-kdyz-neco-nejde).

## 3.13 Související kapitoly

- [1. Úvod](01_Uvod.md)
- [2. Instalace - Quickstart](02_Instalace_Quickstart.md)
- [4. Instalace - Nativní](04_Instalace_Nativni.md)
- [5. Po instalaci](05_Po_instalaci.md)
- [7. První spuštění](07_Setup_wizard.md)
- [102. Aktualizace](102_Aktualizace.md)
