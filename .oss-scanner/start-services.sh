#!/bin/sh
# Spustí služby testovací instance v image pro OSS Scanner (jen uvnitř kontejneru):
# MariaDB a Redis, bez --no-app i aplikaci na http://localhost:8080 nad databází
# myinvoice_ci_test, stejně jako krok „Start test server" v CI.
#
#   .oss-scanner/start-services.sh            služby + aplikace
#   .oss-scanner/start-services.sh --no-app   jen MariaDB a Redis
#
# Přihlášení do aplikace: fixture@example.invalid / Fixture-password-42 (api/bin/ci-seed.php).
# PHPUnit: cd api && MYINVOICE_TEST_URL=http://localhost:8080 vendor/bin/phpunit --testsuite Integration
set -e
cd "$(dirname "$0")/.."

mkdir -p /run/mysqld
chown mysql:mysql /run/mysqld
if ! mariadb-admin ping --silent 2>/dev/null; then
    mariadbd-safe --user=mysql >/tmp/mariadb.log 2>&1 &
    for i in $(seq 1 60); do
        mariadb-admin ping --silent 2>/dev/null && break
        sleep 1
    done
fi
mariadb-admin ping --silent

if ! redis-cli ping >/dev/null 2>&1; then
    redis-server --daemonize yes --save '' --appendonly no
    for i in $(seq 1 30); do
        redis-cli ping >/dev/null 2>&1 && break
        sleep 1
    done
fi

[ "$1" = "--no-app" ] && exit 0

if ! curl -fsS http://localhost:8080/api/health >/dev/null 2>&1; then
    MYINVOICE_DB_NAME=myinvoice_ci_test \
        nohup php -S 127.0.0.1:8080 api/bin/dev-server-router.php >/tmp/php-server.log 2>&1 &
    for i in $(seq 1 30); do
        curl -fsS http://localhost:8080/api/health >/dev/null 2>&1 && exit 0
        sleep 1
    done
    echo "Aplikace se nerozeběhla:"
    cat /tmp/php-server.log
    exit 1
fi
