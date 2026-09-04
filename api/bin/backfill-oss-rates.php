<?php

declare(strict_types=1);

/**
 * Dotah legislativního číselníku sazeb členských států (OSS).
 *
 * PROČ TO EXISTUJE
 * ------------------------------------------------------------------------------
 * `oss_member_state_rates` je seed migrací 1152/1292/1294. Migrace jsou evidované
 * jako proběhlé, takže když tabulka o svůj obsah přijde, `migrate.php` ji NEOBNOVÍ —
 * a to je přesně to, co všechny hlášky uživateli radí („Spusťte php api/bin/migrate.php").
 * Bez číselníku odmítne import i vystavení každý doklad se sazbou vyšší než 0 %,
 * protože u žádného řádku nejde ověřit, ve které zemi jeho sazba platí.
 *
 * Stalo se to dvakrát a pokaždé jinak: jednou souběhem dvou migrátorů (viz komentář
 * migrace 1319), podruhé `reset.php`, který tabulku mazal jako uživatelská data.
 * Druhou příčinu řeší keep-list v `reset.php`; tenhle skript řeší NÁSLEDEK, ať už
 * ho způsobilo cokoli — a je zapojený do auto-backfillu `migrate.php`, takže rada
 * v hlášce začne platit.
 *
 * Data se NEOPISUJÍ: spouští se přímo migrace 1319, která je psaná jako sebeopravný
 * dotah (`INSERT ... WHERE NOT EXISTS` nad `uq_osmr`). Na zdravé instalaci je no-op
 * a uživatelské řádky (`is_custom = 1`) se jí nemůže dotknout — zdůvodnění je v jejím
 * vlastním komentáři.
 *
 * Použití:
 *   php api/bin/backfill-oss-rates.php            # náhled, nic nezapíše
 *   php api/bin/backfill-oss-rates.php --apply    # dotáhne chybějící řádky
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Tento skript lze spustit pouze z příkazové řádky (CLI).\n");
}

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;

/** Zdroj dat je JEDEN — sebeopravná migrace, ne kopie jejích řádků tady. */
const SELF_HEAL_MIGRATION = 'db/migrations/1319_oss_member_state_rates_self_heal.sql';

$apply = in_array('--apply', $argv, true);
$rootDir = Bootstrap::rootDir();

try {
    $pdo = (new Connection(Config::load($rootDir)))->pdo();
} catch (\Throwable $e) {
    fwrite(STDERR, "[oss-rates] Chyba připojení k DB: " . $e->getMessage() . "\n");
    exit(1);
}

try {
    $before = (int) $pdo->query('SELECT COUNT(*) FROM oss_member_state_rates')->fetchColumn();
} catch (\Throwable $e) {
    // Tabulka neexistuje → chybí migrace 1152; tenhle skript to neřeší.
    fwrite(STDERR, "[oss-rates] Tabulka oss_member_state_rates neexistuje — spusťte php api/bin/migrate.php.\n");
    exit(1);
}

echo "[oss-rates] řádků v číselníku: {$before}\n";

if (!$apply) {
    echo "[oss-rates] náhled — nic se nezapsalo. Pro dotah spusťte s --apply.\n";
    exit(0);
}

$sqlPath = $rootDir . '/' . SELF_HEAL_MIGRATION;
if (!is_file($sqlPath)) {
    fwrite(STDERR, "[oss-rates] Chybí {$sqlPath}.\n");
    exit(1);
}

try {
    $pdo->exec((string) file_get_contents($sqlPath));
} catch (\Throwable $e) {
    fwrite(STDERR, "[oss-rates] Dotah selhal: " . $e->getMessage() . "\n");
    exit(1);
}

$after = (int) $pdo->query('SELECT COUNT(*) FROM oss_member_state_rates')->fetchColumn();
$countries = (int) $pdo->query('SELECT COUNT(DISTINCT country) FROM oss_member_state_rates')->fetchColumn();
printf("[oss-rates] doplněno %d řádků (celkem %d, zemí %d)\n", $after - $before, $after, $countries);

exit(0);
