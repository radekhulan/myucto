# 4. Instalace - Nativní (PHP + MariaDB + web server)

> Návod na instalaci MyÚčta na tradiční hosting bez Dockeru (cca 5 minut).
> Pro správce, který má PHP, MariaDB a web server IIS nebo Apache.

## 4.1 Kdy to potřebujete

Kapitolu otevřete, když:

- nemůžete nebo nechcete použít Docker (sdílený hosting, firemní Windows server s IIS),
- chcete instalaci ze zdrojových kódů a vlastní build,
- potřebujete hotový balíček bez Composeru a Node.js (sdílený hosting),
- aktualizujete existující nativní instalaci.

<!-- cols: 40 60 -->
| Situace | Kam jít |
|---|---|
| Mám PHP CLI, Composer, Node a pnpm a chci build ze zdrojů | [§ 4.3](#43-krok-za-krokem-instalace-ze-zdrojovych-kodu) |
| Nechci buildit, stačí mi PHP, MariaDB a web server | [§ 4.4](#44-krok-za-krokem-instalace-z-hotoveho-balicku) |
| Aktualizuji existující instalaci | [§ 4.5](#45-krok-za-krokem-aktualizace) |

> [!TIP]
> Nechcete buildit? Stáhněte hotový **production bundle** z [GitHub Releases](https://github.com/radekhulan/myucto/releases). Má už hotové `api/vendor/`, `web/dist/` i `manual/generated/`, takže odpadá Composer, Node, pnpm i build. Postup je v [§ 4.4](#44-krok-za-krokem-instalace-z-hotoveho-balicku).

## 4.2 Než začnete

Pro build ze zdrojových kódů potřebujete:

- **PHP 8.5+** s rozšířeními `pdo`, `pdo_mysql`, `mbstring`, `openssl`, `json`, `iconv`, `gd`,
- **MariaDB 11.8+** (minimum 11.8 LTS, podporovaná i 12.3),
- **Composer 2.x**, **Node.js 22+** (doporučeno 24) a **pnpm 10+**,
- **Redis** (volitelné, jinak se použije záložní řešení v MariaDB MEMORY),
- web server **IIS** nebo **Apache** (oba jsou podporované, repozitář má `web.config` i `.htaccess`).

Pro instalaci z hotového balíčku stačí PHP, MariaDB a web server.

Před konfigurací si připravte:

- přístup k MariaDB (uživatel s právem vytvořit databázi),
- přesnou veřejnou adresu aplikace (budoucí `app.url`),
- údaje k odchozí poště (SMTP),
- klíče Cloudflare Turnstile z dash.cloudflare.com (pro CAPTCHA).

## 4.3 Krok za krokem: instalace ze zdrojových kódů

### 4.3.1 Klon a konfigurace

1. Naklonujte repozitář a zkopírujte vzorovou konfiguraci.

   ```bash
   git clone https://github.com/radekhulan/myucto.git myucto
   cd myucto
   cp cfg.sample.php cfg.php
   ```

2. Otevřete `cfg.php` a vyplňte:
   - `db.user` a `db.pass` - připojení k MariaDB,
   - `app.url` - přesný stabilní veřejný origin aplikace,
   - `app.pepper` - vygenerujte příkazem `openssl rand -base64 32`,
   - `smtp.host`, `user`, `pass` - odchozí pošta,
   - `captcha.site_key` a `secret_key` - z dash.cloudflare.com, sekce Turnstile,
   - `ip_allowlist.allow` - volitelné, v produkci doporučené.

> [!WARNING]
> Pro passkeys musí `app.url` používat stabilní hostname a důvěryhodné HTTPS, například `https://faktury.example.cz`. Výjimkou pro lokální vývoj je `http://localhost`. Prosté HTTP přes LAN IP ani střídání aliasů hostname není podporované. Klíče WebAuthn jsou s hostname kryptograficky svázané, takže změna domény vyžaduje TOTP nebo administrátorskou obnovu přístupu.

### 4.3.2 Vytvoření databáze

```bash
mysql -u root -p -e "CREATE DATABASE myucto CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### 4.3.3 Backend a migrace

1. Nainstalujte závislosti PHP.

   ```bash
   cd api && composer install && cd ..
   ```

2. Spusťte migrace.

   ```bash
   php api/bin/migrate.php
   ```

3. Vyrenderujte manuál (zpřístupní adresu `/manual`).

   ```bash
   php tools/generateManualHtml.php
   ```

`generateManualHtml.php` je samostatný skript (nepotřebuje Composer ani `vendor`) a generuje HTML kapitoly a vyhledávací index. `exportManualToPdf.php` vyžaduje `api/vendor/` (mPDF). Obojí spouštějte znovu po každém stažení repozitáře, aby `/manual` ukazoval aktuální obsah. V Docker variantě se to volá při buildu uvnitř `Dockerfile` (viz [Instalace - Docker](03_Instalace_Docker.md)).

### 4.3.4 Build frontendu

```bash
cd web
pnpm install
pnpm build       # produkční build do web/dist/
```

### 4.3.5 Web server

- **IIS:** `web.config` v kořeni repozitáře nastaví přepisování adres a statické soubory.
- **Apache:** `.htaccess` v kořeni repozitáře, vyžaduje `mod_rewrite` a `mod_headers`.

Nasměrujte web server na kořen repozitáře a ověřte, že se aplikace otevře na adrese z `app.url`.

**Jak poznáte, že je hotovo:** na adrese z `app.url` se zobrazí průvodce prvním spuštěním (viz [7. První spuštění](07_Setup_wizard.md)). Pokračujte kapitolou [5. Po instalaci](05_Po_instalaci.md).

## 4.4 Krok za krokem: instalace z hotového balíčku

Pro sdílený hosting bez Composeru a Node. Production bundle z [release stránky](https://github.com/radekhulan/myucto/releases) se publikuje automaticky ke každému release tagu. Obsahuje hotové `api/vendor/`, `web/dist/` i `manual/generated/`, takže žádný build není potřeba (přeskočíte [§ 4.3.3](#433-backend-a-migrace) kromě migrací a [§ 4.3.4](#434-build-frontendu)).

1. Stáhněte balíček, ověřte jeho kontrolní součet a rozbalte ho.

   ```bash
   TAG=X.Y.Z
   curl -LO https://github.com/radekhulan/myucto/releases/download/v$TAG/myucto-$TAG.tar.gz
   sha256sum -c myucto-$TAG.tar.gz.sha256   # ověření integrity
   tar -xzf myucto-$TAG.tar.gz --strip-components=1 \
     --exclude='cfg.php' --exclude='cfg.local.php' \
     --exclude='storage' --exclude='private' --exclude='log'
   ```

2. Vyplňte `cfg.php` podle [§ 4.3.1](#431-klon-a-konfigurace).
3. Vytvořte databázi podle [§ 4.3.2](#432-vytvoreni-databaze).
4. Spusťte migrace.

   ```bash
   php api/bin/migrate.php
   ```

5. Nastavte web server podle [§ 4.3.5](#435-web-server).

**Jak poznáte, že je hotovo:** `php api/bin/migrate.php` doběhne bez chyby a na adrese z `app.url` se zobrazí průvodce prvním spuštěním.

> [!TIP]
> Upgrade jde spustit i z aplikace: v `Systém → Aktualizace` je tlačítko **Aktualizovat na {verze}**, které příkazy pro stažení bundlu zobrazí jako box ke zkopírování. Patička aplikace ukazuje aktuální verzi a štítek, pokud je dostupná novější (denně ji obnovuje `cron-version-check.php`). Podrobnosti v kapitole [Aktualizace](102_Aktualizace.md).

## 4.5 Krok za krokem: aktualizace

Nativní instalaci aktualizujete jedním ze dvou postupů podle toho, co máte na hostu k dispozici. Konfiguraci (`cfg.php`) ani data (`storage`, `private`, `log`) upgrade nemaže.

### 4.5.1 Build ze zdrojů (host má PHP CLI, Composer, Node a pnpm)

1. Přepněte se na novou verzi.

   ```bash
   git fetch --tags
   git checkout vX.Y.Z
   ```

2. Přeinstalujte závislosti a sestavte frontend.

   ```bash
   cd api && composer install --no-dev && cd ..
   cd web && pnpm install && pnpm build && cd ..
   ```

3. Přegenerujte manuál a spusťte migrace.

   ```bash
   php tools/generateManualHtml.php
   php tools/exportManualToPdf.php
   php api/bin/migrate.php
   ```

### 4.5.2 Bez Composeru a Node (sdílený hosting)

Rozbalte hotový production bundle (viz [§ 4.4](#44-krok-za-krokem-instalace-z-hotoveho-balicku)) a spusťte migraci.

```bash
TAG=X.Y.Z
curl -LO https://github.com/radekhulan/myucto/releases/download/v$TAG/myucto-$TAG.tar.gz
sha256sum -c myucto-$TAG.tar.gz.sha256
tar -xzf myucto-$TAG.tar.gz --strip-components=1 \
  --exclude='cfg.php' --exclude='cfg.local.php' \
  --exclude='storage' --exclude='private' --exclude='log'
php api/bin/migrate.php
```

**Jak poznáte, že je hotovo:** `migrate.php` doběhne bez chyby a v `Systém → Aktualizace` ukazuje **Aktuální verze** novou verzi.

Migrace jsou idempotentní, takže `migrate.php` spouštějte po každém upgradu vždy.

## 4.6 Když něco nejde

<!-- cols: 34 30 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| `composer` nebo `pnpm` na hostu není | Sdílený hosting bez nástrojů pro build | Použijte hotový balíček ([§ 4.4](#44-krok-za-krokem-instalace-z-hotoveho-balicku)) |
| Adresa `/manual` nezobrazí aktuální obsah | Manuál nebyl po stažení repozitáře znovu vygenerován | Spusťte `php tools/generateManualHtml.php` |
| Adresy aplikace vrací 404 | Web server nemá zapnuté přepisování adres | Apache: zapněte `mod_rewrite` a `mod_headers`. IIS: ověřte, že `web.config` z kořene repozitáře je načtený |
| Po změně domény nefungují passkeys | Klíče WebAuthn jsou svázané s hostname z `app.url` | Přihlaste se přes TOTP nebo administrátorskou obnovou a passkey zaregistrujte znovu |
| Upgrade selhal | Různé příčiny | Postup obnovy a návrat na předchozí verzi v [102.7 Co když upgrade selže](102_Aktualizace.md#10287-co-kdyz-upgrade-selze) |

## 4.7 Podrobnosti a pravidla

- **Odchozí pošta.** Hodnoty `smtp.host`, `user`, `pass` v `cfg.php` určují, odkud aplikace odesílá faktury, upomínky a resety hesel.
- **CAPTCHA.** Klíče `captcha.site_key` a `secret_key` pocházejí z Cloudflare Turnstile.
- **Omezení přístupu podle IP.** `ip_allowlist.allow` je volitelné, v produkci doporučené.
- **Redis.** Volitelný. Bez něj aplikace používá záložní úložiště v MariaDB (MEMORY).
- **Manuál a PDF.** HTML manuál generuje samostatný skript bez závislostí, export PDF potřebuje `api/vendor/` (mPDF).
- **Plný postup aktualizace**, zachování dat, návrat na předchozí verzi a řešení selhání upgradu najdete v kapitole [Aktualizace](102_Aktualizace.md), zejména [§ 102.6 Aktualizace v UI - nativní instalace](102_Aktualizace.md#10286-aktualizace-v-ui-nativni-instalace) a [§ 102.7 Co když upgrade selže](102_Aktualizace.md#10287-co-kdyz-upgrade-selze).

## 4.8 Související kapitoly

- [2. Instalace - Quickstart](02_Instalace_Quickstart.md)
- [3. Instalace - Docker](03_Instalace_Docker.md)
- [5. Po instalaci](05_Po_instalaci.md)
- [7. První spuštění](07_Setup_wizard.md)
- [102. Aktualizace](102_Aktualizace.md)
