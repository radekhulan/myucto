<?php

declare(strict_types=1);

/**
 * Kontrola struktury databáze proti migracím (read-only).
 *
 * Porovná tabulky, sloupce, indexy, cizí klíče, CHECK omezení, triggery, rutiny
 * a auditní historii s referenčním otiskem `db/schema.snapshot.json`. Totéž
 * ukazuje Systém → Diagnostika jako kontrolu „Struktura databáze".
 *
 * Použití:
 *   php api/bin/check-schema.php            # exit 1 jen při nálezu typu fail
 *   php api/bin/check-schema.php --strict   # exit 1 i při varování nebo přeskočení (CI)
 *   php api/bin/check-schema.php --json
 *
 * Návratový kód: 0 v pořádku, 1 nález, 2 kontrola neproběhla (chybí otisk,
 * nespuštěné migrace, otisk neodpovídá řadě migrací).
 */

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\System\Schema\SchemaIntegrityService;

$strict = in_array('--strict', $argv, true);
$json = in_array('--json', $argv, true);

$rootDir = Bootstrap::rootDir();
$pdo = (new Connection(Config::load($rootDir)))->pdo();
$report = (new SchemaIntegrityService($pdo, $rootDir))->report();

if ($json) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
} else {
    $reasons = [
        'snapshot_missing'         => 'chybí referenční otisk db/schema.snapshot.json',
        'snapshot_outdated'        => 'otisk neodpovídá řadě migrací v db/migrations — přegeneruj ho (api/bin/schema-snapshot.php)',
        'migrations_pending'       => 'čekají nespuštěné migrace — nejdřív php api/bin/migrate.php',
        'migrations_table_missing' => 'databáze nemá tabulku migrations',
    ];
    printf("Struktura databáze — %s\n\n", (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
    if ($report['status'] === SchemaIntegrityService::STATUS_SKIP) {
        printf("PŘESKOČENO: %s\n", $reasons[$report['reason']] ?? $report['reason']);
    } else {
        foreach ($report['findings'] as $f) {
            printf("[%s] %-22s %s\n", strtoupper($f['severity']), $f['code'], $f['object']);
            if ($f['expected'] !== '') {
                printf("         očekáváno: %s\n", $f['expected']);
            }
            if ($f['actual'] !== '') {
                printf($f['severity'] === SchemaIntegrityService::SEVERITY_INFO
                    ? "         odstranění: %s\n"
                    : "         v databázi: %s\n", $f['actual']);
            }
        }
        printf("%sNálezů: %d chyb, %d varování, %d pozůstatků starší verze → %s\n",
            $report['findings'] === [] ? '' : "\n",
            $report['counts']['fail'], $report['counts']['warn'], $report['counts']['info'],
            match ($report['status']) {
                SchemaIntegrityService::STATUS_OK => 'SHODA S MIGRACEMI',
                SchemaIntegrityService::STATUS_WARN => 'SHODA S VÝHRADAMI',
                default => 'NESHODA',
            });
    }
}

exit(match ($report['status']) {
    SchemaIntegrityService::STATUS_OK   => 0,
    SchemaIntegrityService::STATUS_WARN => $strict ? 1 : 0,
    SchemaIntegrityService::STATUS_SKIP => 2,
    default                             => 1,
});
