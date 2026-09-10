<?php

declare(strict_types=1);

/**
 * Vygeneruje referenční otisk struktury databáze `db/schema.snapshot.json`.
 *
 * Otisk MUSÍ vzniknout z čisté databáze postavené jen z migrací — jinak by do
 * reference propadly odchylky konkrétní instalace a kontrola by je pak hlásila
 * jako správný stav. Postup:
 *
 *   CREATE DATABASE myucto_schema_ref CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
 *   MYINVOICE_DB_NAME=myucto_schema_ref php api/bin/migrate.php --no-backfills
 *   MYINVOICE_DB_NAME=myucto_schema_ref php api/bin/schema-snapshot.php
 *
 * Po každé nové migraci je potřeba otisk přegenerovat — hlídá to
 * `SchemaSnapshotCoverageTest` a v CI `check-schema.php --strict`.
 *
 * Použití:
 *   php api/bin/schema-snapshot.php [--output=cesta]
 */

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\System\Schema\SchemaIntegrityService;
use MyInvoice\Service\System\Schema\SchemaSnapshot;

$rootDir = Bootstrap::rootDir();
$output = $rootDir . '/' . SchemaSnapshot::RELATIVE_PATH;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--output=')) {
        $output = substr($arg, strlen('--output='));
        continue;
    }
    fwrite(STDERR, "Neznámý argument: {$arg}\n");
    exit(2);
}

$pdo = (new Connection(Config::load($rootDir)))->pdo();
$service = new SchemaIntegrityService($pdo, $rootDir);

$files = $service->migrationFiles();
$applied = $service->appliedMigrations();
if ($applied === null) {
    fwrite(STDERR, "Databáze nemá tabulku migrations — nejdřív spusť php api/bin/migrate.php.\n");
    exit(1);
}
if ($applied !== $files) {
    fwrite(STDERR, sprintf(
        "Aplikované migrace neodpovídají db/migrations (nespuštěné: %d, neznámé: %d).\n"
            . "Otisk se generuje jen z databáze postavené přesně z aktuální řady migrací.\n",
        count(array_diff($files, $applied)),
        count(array_diff($applied, $files)),
    ));
    exit(1);
}

$snapshot = SchemaSnapshot::capture($pdo, $files);
if (file_put_contents($output, SchemaSnapshot::encode($snapshot)) === false) {
    fwrite(STDERR, "Otisk se nepodařilo zapsat do {$output}.\n");
    exit(1);
}

printf(
    "Otisk zapsán: %s\n  databáze %s, migrací %d, tabulek %d, triggerů %d, rutin %d\n",
    $output,
    (string) $pdo->query('SELECT DATABASE()')->fetchColumn(),
    count($files),
    count($snapshot['tables']),
    count($snapshot['triggers']),
    count($snapshot['routines']),
);
