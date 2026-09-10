<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Service\System\Schema\SchemaSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * Referenční otisk struktury `db/schema.snapshot.json` musí odpovídat řadě migrací.
 *
 * Diagnostika instalací porovnává jejich databázi s tímhle otiskem. Kdyby přibyla
 * migrace bez přegenerování otisku, kontrola by na každé aktualizované instalaci
 * přeskočila jako „otisk neodpovídá migracím této verze" — tedy by přestala hlídat
 * právě ve chvíli, kdy je nejvíc potřeba. Že otisk odpovídá i OBSAHEM (ne jen
 * seznamem migrací), ověřuje CI krokem `check-schema.php --strict` nad čistou
 * databází postavenou z migrací.
 */
final class SchemaSnapshotCoverageTest extends TestCase
{
    public function testSnapshotCoversExactlyTheMigrationsInRepository(): void
    {
        $root = dirname(__DIR__, 3);
        $snapshot = SchemaSnapshot::load($root . '/' . SchemaSnapshot::RELATIVE_PATH);
        self::assertNotNull($snapshot, 'Chybí nebo je neplatný ' . SchemaSnapshot::RELATIVE_PATH . '.');

        $files = array_map('basename', glob($root . '/db/migrations/*.sql') ?: []);
        self::assertNotEmpty($files, 'Nenašly se žádné migrace — test by tiše prošel.');

        $missing = array_values(array_diff($files, $snapshot['migrations']));
        $stale = array_values(array_diff($snapshot['migrations'], $files));

        self::assertSame([], array_merge($missing, $stale), sprintf(
            "Otisk struktury neodpovídá db/migrations (v otisku chybí: %s; v otisku navíc: %s).\n"
                . "Přegeneruj ho z čisté databáze postavené z migrací:\n"
                . "  CREATE DATABASE myucto_schema_ref CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n"
                . "  MYINVOICE_DB_NAME=myucto_schema_ref php api/bin/migrate.php --no-backfills\n"
                . "  MYINVOICE_DB_NAME=myucto_schema_ref php api/bin/schema-snapshot.php",
            $missing === [] ? '-' : implode(', ', $missing),
            $stale === [] ? '-' : implode(', ', $stale),
        ));
    }
}
