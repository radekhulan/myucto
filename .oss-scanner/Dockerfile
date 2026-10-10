# MyÚčto.cz — prostředí pro Anthropic OSS Scanner (https://github.com/anthropics/oss-scanner)
#
# Scanner image postaví s celým repozitářem jako kontextem, pak odpojí síť a nad /src pustí
# bezpečnostní audit. Všechno, co audit a testy potřebují, se proto stahuje tady:
# PHP 8.5 s rozšířeními jako v produkčním image, MariaDB 11.8, Redis, Node 24, composer
# závislosti včetně dev (PHPUnit), frontend a MCP balíčky.
#
# Image obsahuje připravenou testovací instanci (stejná konfigurace jako CI):
#   .oss-scanner/start-services.sh   spustí MariaDB, Redis a aplikaci na http://localhost:8080
# Kontext buildu filtruje .oss-scanner/Dockerfile.dockerignore (má přednost před kořenovým
# .dockerignore, který z produkčního image vyřazuje testy).
#
# Lokální ověření: docker build -f .oss-scanner/Dockerfile -t myucto-scanner .
FROM php:8.5-cli-trixie

ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_ALLOW_SUPERUSER=1 \
    CI=true

COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY --from=node:24-trixie-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:24-trixie-slim /usr/local/lib/node_modules /usr/local/lib/node_modules

RUN install-php-extensions \
        pdo_mysql gd mbstring intl zip opcache exif bcmath redis sodium soap imagick \
 && apt-get update \
 && apt-get install -y --no-install-recommends \
        ca-certificates git unzip curl mariadb-server mariadb-client redis-server \
        ghostscript poppler-utils librsvg2-bin \
 && rm -rf /var/lib/apt/lists/* \
 && ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
 && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx \
 && ln -s /usr/local/lib/node_modules/corepack/dist/corepack.js /usr/local/bin/corepack \
 && { \
        echo 'memory_limit = 1G'; \
        echo 'upload_max_filesize = 50M'; \
        echo 'post_max_size = 55M'; \
        echo 'date.timezone = Europe/Prague'; \
    } > /usr/local/etc/php/conf.d/myucto-scanner.ini

# scanner contract: the checkout lives inside the image, at /src
COPY . /src
WORKDIR /src

RUN cd api && composer install --prefer-dist --no-interaction --no-progress

# pnpm-workspace.yaml nese supply-chain politiku (minimumReleaseAge), stejně jako produkční Dockerfile.
ENV NODE_OPTIONS=--max-old-space-size=3072
RUN corepack enable \
 && corepack prepare pnpm@latest --activate \
 && cd web \
 && pnpm install --frozen-lockfile \
 && pnpm build:image
RUN cd MCP && npm ci --no-audit --no-fund && npm run build

RUN php tools/generateManualHtml.php

# Testovací instance jako v CI (.github/workflows/ci.yml): cfg.php se syntetickými klíči,
# databáze myinvoice_ci (aplikace) a myinvoice_ci_test (PHPUnit), obě zmigrované a naplněné
# fixturou api/bin/ci-seed.php. Data zůstanou v image, scanner je najde po startu služeb.
RUN cp .oss-scanner/cfg.scanner.php cfg.php \
 && chmod +x .oss-scanner/start-services.sh \
 && .oss-scanner/start-services.sh --no-app \
 && mariadb -e "ALTER USER 'root'@'localhost' IDENTIFIED VIA unix_socket OR mysql_native_password USING PASSWORD('ci'); \
                CREATE USER IF NOT EXISTS 'root'@'127.0.0.1' IDENTIFIED BY 'ci'; \
                GRANT ALL PRIVILEGES ON *.* TO 'root'@'127.0.0.1' WITH GRANT OPTION; \
                CREATE DATABASE IF NOT EXISTS myinvoice_ci CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; \
                CREATE DATABASE IF NOT EXISTS myinvoice_ci_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" \
 && php api/bin/migrate.php \
 && php api/bin/ci-seed.php \
 && MYINVOICE_DB_NAME=myinvoice_ci_test php api/bin/migrate.php \
 && MYINVOICE_DB_NAME=myinvoice_ci_test php api/bin/ci-seed.php \
 && (cd api && vendor/bin/phpunit --no-coverage --testsuite Architecture || echo "WARNING: Architecture suite reported failures") \
 && mariadb-admin shutdown \
 && redis-cli shutdown nosave
