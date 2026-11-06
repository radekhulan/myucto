# MyUcto.cz — multi-stage Docker build
#
# Stage 1: build Vue frontend (web/dist)
# Stage 2: install PHP dependencies (api/vendor)
# Stage 3: runtime image (PHP 8.5 + Apache)
#
# Build:    docker compose build       (or cmd/docker-build.{sh,ps1})
# First run: cmd/docker-install.{sh,ps1}
# Daily:    docker compose up -d / down

# ---------- Stage 1: frontend ----------
FROM node:24-alpine AS web-build
WORKDIR /app
# pnpm-workspace.yaml nese supply-chain politiku (minimumReleaseAgeExclude pro
# záměrně povýšené balíky jako vite, onlyBuiltDependencies). Musí být v kontextu
# PŘED `pnpm install`, jinak novější pnpm@latest odmítne „příliš čerstvé" závislosti
# (ERR_PNPM_MINIMUM_RELEASE_AGE_VIOLATION), přestože jsou ve whitelistu repa.
COPY web/package.json web/pnpm-lock.yaml web/pnpm-workspace.yaml ./
RUN corepack enable && corepack prepare pnpm@latest --activate \
 && pnpm install --frozen-lockfile
COPY web/ ./
# Image staví jen artefakt (kontroly + vite build). Typová kontrola vue-tsc potřebuje přes 3 GB
# haldy a v Docker Desktop VM se 4 GB paměti padala na „heap out of memory"; kód typově
# ověřuje CI (`pnpm type-check` ve frontend jobu, včetně testů).
ENV NODE_OPTIONS=--max-old-space-size=3072
RUN pnpm build:image

# ---------- Stage 2: composer ----------
# The composer image has only a minimal PHP without pdo_mysql/gd/intl extensions,
# but the runtime stage installs them — so skip platform checks here.
FROM composer:2 AS php-deps
WORKDIR /app
COPY api/composer.json api/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction \
        --ignore-platform-reqs
COPY api/ ./
# dump-autoload + cleanup mPDF fontů. Cleanup voláme explicitně, protože composer
# install výše běží s --no-scripts (post-install-cmd z composer.json se nespustí).
RUN composer dump-autoload --optimize --classmap-authoritative \
 && php bin/cleanup-mpdf-fonts.php

FROM node:24-bookworm-slim AS mcp-node

# ---------- Stage 3: runtime ----------
FROM php:8.5-apache AS runtime

# librsvg2-bin (~30–50 MB i s cairo/pango/gdk-pixbuf deps) je FALLBACK pro konverzi
# SVG loga dodavatele, když Imagick nemá SVG delegate. Pro menší image lze vypnout
# `--build-arg INSTALL_RSVG=0`. Default 1 = beze změny chování.
ARG INSTALL_RSVG=1

# Use mlocati/docker-php-extension-installer — the de-facto installer for PHP-Docker
# extensions. Handles apt deps, parallel builds, PECL packages and the
# install-modules race condition that bites raw `docker-php-ext-install` on PHP 8.5.
COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/

# Imagick (+~34 MiB i s ImageMagick delegáty, měřeno) není kosmetika: je to jediná
# cesta k náhledům PDF dokumentů (ThumbnailGenerator — GD umí jen rastr) a jediná
# cesta k importu HEIC/HEIF, což je výchozí formát fotek z iPhonu
# (ImageToPdfConverter). Bez něj obě cesty tiše odpadnou a kontrola prostředí
# hlásí varování. Ověřené delegáty v tomto image: PDF, HEIC, SVG.
# poppler-utils = `pdftoppm`, záložní cesta k obrázkům stránek PDF pro vision modely
# v Ollamě (PdfPageRasterizer) — parita s Dockerfile.alpine.
RUN install-php-extensions \
        pdo_mysql gd mbstring intl zip opcache exif bcmath redis sodium soap imagick \
 && apt-get update \
 && apt-get install -y --no-install-recommends tini cron mariadb-client libstdc++6 poppler-utils \
 && if [ "$INSTALL_RSVG" = "1" ]; then \
        apt-get install -y --no-install-recommends librsvg2-bin; \
    fi \
 && a2enmod rewrite headers deflate expires \
 && rm -f /etc/apache2/mods-enabled/mpm_* \
 && a2enmod mpm_prefork \
 && apt-get clean \
 && rm -rf /var/lib/apt/lists/*
# mariadb-client = mariadb-dump (~20 MB) — vyžaduje ho cron-backup.php pro
# denní DB dump (kritická úloha). Per issue #34 bez něj fail out-of-the-box
# na fresh deployi a uživatel nemá jak ho doinstalovat persistentně.

# Restriktivní ImageMagick policy (docker/imagemagick-policy.xml): zakáže MVG, MSL,
# URL/HTTP(S), TEXT, EPHEMERAL, PostScript apod., PDF/SVG/HEIC nechá. Adresář
# konfigurace se liší podle verze ImageMagicku (6/7), proto se policy zapíše do
# každého /etc/ImageMagick-*. Build selže, pokud se policy neuplatní.
COPY docker/imagemagick-policy.xml /tmp/imagemagick-policy.xml
RUN set -e; \
    major="$(php -r 'preg_match("/ImageMagick (\d+)/", Imagick::getVersion()["versionString"], $m); echo $m[1] ?? "";')"; \
    [ -n "$major" ]; \
    mkdir -p "/etc/ImageMagick-$major"; \
    for d in /etc/ImageMagick-*; do install -m 0644 /tmp/imagemagick-policy.xml "$d/policy.xml"; done; \
    rm -f /tmp/imagemagick-policy.xml; \
    printf 'viewbox 0 0 1 1\n' > /tmp/policy-probe.mvg; \
    php -r 'try { (new Imagick())->readImage("mvg:/tmp/policy-probe.mvg"); fwrite(STDERR, "ImageMagick policy se neuplatnila\n"); exit(1); } catch (ImagickException $e) { if (!preg_match("/security policy|not authorized/i", $e->getMessage())) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); } echo "ImageMagick policy OK\n"; }'; \
    rm -f /tmp/policy-probe.mvg

# PHP runtime config
RUN { \
        echo 'memory_limit = 512M'; \
        echo 'upload_max_filesize = 50M'; \
        echo 'post_max_size = 55M'; \
        echo 'max_execution_time = 60'; \
        echo 'date.timezone = Europe/Prague'; \
        echo 'expose_php = Off'; \
        echo 'session.upload_progress.enabled = 0'; \
        echo 'opcache.enable = 1'; \
        echo 'opcache.memory_consumption = 128'; \
        echo 'opcache.interned_strings_buffer = 16'; \
        echo 'opcache.max_accelerated_files = 20000'; \
        echo 'opcache.validate_timestamps = 0'; \
        echo 'opcache.enable_cli = 1'; \
        echo 'opcache.file_cache = /var/cache/opcache'; \
    } > /usr/local/etc/php/conf.d/myucto.ini

# Opcache file cache — sdílený zkompilovaný bytecode mezi CLI procesy. Bez něj
# každý běh api/bin/cron-*.php znovu parsuje a kompiluje ~200 souborů (CLI má
# vlastní SHM, která zaniká s procesem), a cron jich při vyšší frekvenci pouští
# desítky za hodinu. `validate_timestamps=0` výše dělá cache bezpečnou: kód se
# v image nemění, takže se nikdy nemusí revalidovat.
#
# Vlastníka NASTAVUJEME AŽ NA KONCI buildu (viz níže) — build-time `php tools/*`
# běží jako root a naplnil by cache root-owned soubory, do kterých by www-data
# za běhu nemohl zapisovat. Zároveň je to vítaný side effect: cache je v image
# předehřátá o soubory, které si build sáhl.
RUN mkdir -p /var/cache/opcache

# Apache: doc root → /var/www/html, allow .htaccess, dynamický port přes ${PORT}
# (Apache 2.4 expanduje ${PORT} z env při parsingu konfigurace — funguje
# pro Railway/Heroku/Fly.io, kde je port přidělen dynamicky. Default 80.)
ENV PORT=80
RUN sed -ri \
        -e 's!/var/www/html!/var/www/html!g' \
        -e 's!AllowOverride None!AllowOverride All!g' \
        /etc/apache2/apache2.conf /etc/apache2/sites-available/000-default.conf \
 && sed -ri 's!^Listen 80$!Listen ${PORT}!' /etc/apache2/ports.conf \
 && sed -ri 's!\*:80>!*:${PORT}>!' /etc/apache2/sites-available/000-default.conf

# Copy application code
WORKDIR /var/www/html
COPY --from=mcp-node /usr/local/bin/node /usr/local/bin/node
COPY --chown=www-data:www-data . .
RUN chmod +x /var/www/html/docker-entrypoint.sh
COPY --from=web-build --chown=www-data:www-data /app/dist ./web/dist
COPY --from=php-deps  --chown=www-data:www-data /app/vendor ./api/vendor

# Generate HTML manual from manual/*.md (servíruje /manual route).
# PDF manuálu v obrazu není, „Stáhnout PDF" odkazuje na myucto.cz.
RUN php tools/generateManualHtml.php \
 && chown -R www-data:www-data manual/generated

# Vestavěný cron (volitelný, MYINVOICE_ENABLE_CRON=1 default). Wrapper + crontab
# generovaný z CronCatalog (jediný zdroj pravdy — viz tools/generateDockerCrontab.php),
# takže obsahuje všechny úlohy + frekvence z UI „Plánované úlohy". Daemon pouští entrypoint.
RUN cp docker/cron-run.sh /usr/local/bin/myucto-cron-run \
 && chmod 0755 /usr/local/bin/myucto-cron-run \
 && ln -s /usr/local/bin/myucto-cron-run /usr/local/bin/myinvoice-cron-run \
 && php tools/generateDockerCrontab.php > /etc/cron.d/myucto \
 && chmod 0644 /etc/cron.d/myucto

# Poslední build-time `php` proběhl výše → teď smí opcache file cache přebrat
# www-data (viz komentář u opcache.file_cache).
RUN chown -R www-data:www-data /var/cache/opcache

# Stub cfg.php — image je samostatný a `/var/www/html` může běžet jako read-only.
# Veškerou konfiguraci lze předat přes ENV (viz api/src/Infrastructure/Config/Config.php).
# cfg.php je v .dockerignore, takže ve fázi COPY tady reálně neexistuje. Pokud
# uživatel přesto chce vlastní cfg.php, namountuje ho přes volume přes tento stub.
RUN echo '<?php return [];' > cfg.php && chown www-data:www-data cfg.php

# Single persistent data dir (default od 3.6.0). Compose mountuje sem
# `app-data:/data` a nastavuje `MYINVOICE_DATA_DIR=/data`. Drží log/, storage/,
# private/dkim/ a cfg.local.php — per-instance konfigurace přežije image update.
# Zpětně kompatibilní: pokud uživatel `MYINVOICE_DATA_DIR` neset (custom compose
# bez env), aplikace fallbackuje na log/, storage/, private/ v rootu repa.
RUN mkdir -p /data/log /data/storage /data/private \
 && chown -R www-data:www-data /data
VOLUME ["/data"]

# Default stateful adresáře uvnitř image (fallback pro custom compose bez DATA_DIR).
RUN mkdir -p log storage private && chown -R www-data:www-data log storage private

EXPOSE 80
ENTRYPOINT ["/usr/bin/tini", "--"]
CMD ["/var/www/html/docker-entrypoint.sh"]
