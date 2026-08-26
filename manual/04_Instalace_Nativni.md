# 4. Instalace — Nativní (PHP + MariaDB + web server)

Tradiční hosting bez Dockeru (cca 5 minut).

> 💡 **Nechce se ti buildit?** Stáhni si hotový **production bundle** z
> [GitHub Releases](https://github.com/radekhulan/myucto/releases) — má už
> hotové `api/vendor/`, `web/dist/`, `manual/generated/` i `manual.pdf`, takže
> odpadá Composer, Node/pnpm i build kroky (4.3 frontend/manuál a 4.4). Postup
> najdeš níže v [4.6 Alternativa: hotový balíček](#46-alternativa-hotovy-balicek-bez-buildu).
> Pak ti stačí PHP + MariaDB + web server.

Předpoklady (pro build ze zdrojáků):

- **PHP 8.5+** s extensions: `pdo`, `pdo_mysql`, `mbstring`, `openssl`, `json`,
  `iconv`, `gd`
- **MariaDB 11.8+**
- **Composer 2.x**, **Node.js 22+** (24 doporučeno), **pnpm 10+**
- **Redis** (volitelné — fallback na MariaDB MEMORY)
- Web server: **IIS** nebo **Apache** (oba podporované, repo má `web.config`
  i `.htaccess`)

## 4.1 Klon a konfigurace

```bash
git clone https://github.com/radekhulan/myucto.git myucto
cd myucto
cp cfg.sample.php cfg.php
```

Otevři `cfg.php` a vyplň:

- `db.user` / `db.pass` — připojení k MariaDB
- `app.url` — přesný stabilní veřejný origin aplikace
- `app.pepper` — vygeneruj `openssl rand -base64 32`
- `smtp.host` / `user` / `pass` — odchozí pošta
- `captcha.site_key` / `secret_key` — z dash.cloudflare.com → Turnstile
- `ip_allowlist.allow` — volitelné, doporučeno v produkci

Pro passkeys musí `app.url` používat stabilní hostname a důvěryhodné HTTPS,
například `https://faktury.example.cz`. Výjimkou pro lokální vývoj je
`http://localhost`. Plain HTTP přes LAN IP ani střídání hostname aliasů není
podporované. WebAuthn klíče jsou s hostname kryptograficky svázané, takže změna
domény vyžaduje TOTP nebo administrátorskou recovery cestu.

## 4.2 Vytvoř databázi

```bash
mysql -u root -p -e "CREATE DATABASE myucto CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

## 4.3 Backend + migrace

```bash
cd api && composer install && cd ..
php api/bin/migrate.php
php tools/generateManualHtml.php   # vyrenderuje manual/generated/ → /manual route
php tools/exportManualToPdf.php    # vygeneruje manual/manual.pdf (Stáhnout PDF v sidebaru)
```

`generateManualHtml.php` je self-contained (nepotřebuje composer/vendor),
generuje HTML kapitoly + search index. `exportManualToPdf.php` vyžaduje
`api/vendor/` (mPDF). Spouštět obojí znovu po každém pull repa, aby `/manual`
ukazoval aktuální obsah. (V Docker variantě se volá build-time uvnitř
`Dockerfile` — viz [Instalace — Docker](03_Instalace_Docker.md).)

## 4.4 Frontend build

```bash
cd web
pnpm install
pnpm build       # produkční build do web/dist/
```

## 4.5 Web server

- **IIS** — `web.config` v rootu repa nakonfiguruje rewrite + statiku.
- **Apache** — `.htaccess` v rootu repa, vyžaduje `mod_rewrite`, `mod_headers`.

Po nasazení web serveru pokračuj kapitolou [Po instalaci](05_Po_instalaci.md).

## 4.6 Alternativa: hotový balíček (bez buildu)

Pro sdílený hosting bez Composeru / Node — stáhni **production bundle** z
[release page](https://github.com/radekhulan/myucto/releases). Publikuje se
automaticky ke každému release tagu a obsahuje hotové `api/vendor/`,
`web/dist/`, `manual/generated/` i `manual.pdf`, takže **žádný build krok není
potřeba** (přeskočíš sekce 4.3 frontend/manuál i 4.4).

```bash
TAG=X.Y.Z
curl -LO https://github.com/radekhulan/myucto/releases/download/v$TAG/myucto-$TAG.tar.gz
sha256sum -c myucto-$TAG.tar.gz.sha256   # ověř integritu
tar -xzf myucto-$TAG.tar.gz --strip-components=1 \
  --exclude='cfg.php' --exclude='cfg.local.php' \
  --exclude='storage' --exclude='private' --exclude='log'
```

Zbytek je stejný jako u instalace ze zdrojáků: vyplň `cfg.php` (viz [4.1](#41-klon-a-konfigurace)),
vytvoř databázi ([4.2](#42-vytvor-databazi)), spusť migrace a nakonfiguruj web
server ([4.5](#45-web-server)):

```bash
php api/bin/migrate.php
```

> 🔔 **Upgrade z UI:** v **Systém → Aktualizace** je tlačítko *Aktualizovat*,
> které příkazy pro stažení bundlu zobrazí jako copy-paste box. Patička
> aplikace ukazuje aktuální verzi + badge, pokud je dostupná novější (denně
> refreshuje `cron-version-check.php`). Detail v kapitole
> [Aktualizace](98_Aktualizace.md).

## 4.7 Aktualizace

Nativní instalace se aktualizuje dvěma způsoby — vyber podle toho, co máš na
hostu k dispozici:

**Build ze zdrojáků** (host má PHP CLI + Composer + Node + pnpm):

```bash
git fetch --tags
git checkout vX.Y.Z
cd api && composer install --no-dev && cd ..
cd web && pnpm install && pnpm build && cd ..
php tools/generateManualHtml.php
php tools/exportManualToPdf.php
php api/bin/migrate.php
```

**Bez Composeru / Node** (sdílený hosting) — rozbal hotový **production bundle**
(viz [4.6](#46-alternativa-hotovy-balicek-bez-buildu)) a spusť migraci:

```bash
TAG=X.Y.Z
curl -LO https://github.com/radekhulan/myucto/releases/download/v$TAG/myucto-$TAG.tar.gz
sha256sum -c myucto-$TAG.tar.gz.sha256
tar -xzf myucto-$TAG.tar.gz --strip-components=1 \
  --exclude='cfg.php' --exclude='cfg.local.php' \
  --exclude='storage' --exclude='private' --exclude='log'
php api/bin/migrate.php
```

Migrace jsou idempotentní, takže `migrate.php` se po každém upgradu spustí vždy.
Konfiguraci (`cfg.php`) ani data (`storage`, `private`, `log`) upgrade nemaže.

> 🛈 Plný postup, zachování dat, rollback a řešení selhání upgradu najdeš
> v kapitole [Aktualizace — § 56.6 Nativní instalace](98_Aktualizace.md#986-aktualizace-v-ui-nativni-instalace)
> a [§ 98.7 Co když upgrade selže](98_Aktualizace.md#987-co-kdyz-upgrade-selze).
