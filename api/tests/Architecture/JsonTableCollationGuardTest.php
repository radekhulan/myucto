<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Textový sloupec JSON_TABLE nedědí collation tabulky, ale výchozí collation
 * databáze. Na instalaci, jejíž databáze má jinou výchozí collation než tabulky
 * (utf8mb4_czech_ci, utf8mb4_general_ci), pak porovnání s tabulkovým sloupcem
 * padá na „Illegal mix of collations“ (1267). CI to nechytí — zakládá databázi
 * s utf8mb4_unicode_ci. Každý dotaz s textovým sloupcem JSON_TABLE proto musí
 * collation uvést výslovně.
 */
final class JsonTableCollationGuardTest extends TestCase
{
    public function testTextualJsonTableColumnsDeclareCollationExplicitly(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $offenders = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (stripos($source, 'JSON_TABLE') === false) {
                continue;
            }
            // Jeden dotaz = od JSON_TABLE po konec řetězce předaného do prepare()/query().
            preg_match_all('/JSON_TABLE\s*\((?:(?!"\);|\'\);).)*/is', $source, $statements);
            foreach ($statements[0] as $statement) {
                $textual = preg_match('/\b(?:VARCHAR|CHAR|TEXT)\s*(?:\(\d+\))?\s+PATH\b/i', $statement) === 1;
                if ($textual && stripos($statement, 'COLLATE') === false) {
                    $offenders[] = str_replace($root, 'src', $file->getPathname());
                }
            }
        }

        self::assertSame([], array_values(array_unique($offenders)), 'Textový sloupec JSON_TABLE bez výslovné collation: ' . implode(', ', $offenders));
    }

    /**
     * Totéž v migracích. SaaS instance mají výchozí collation databáze
     * utf8mb4_uca1400_ai_ci, takže migrace 2215 tam bez výslovné collation zastavila
     * aktualizaci na 6.50.0. Příkaz končí středníkem; collation smí stát u sloupce
     * JSON_TABLE nebo v porovnání.
     */
    private const MIGRATION_EXCEPTIONS = [
        // Textové sloupce manifestu se s tabulkou porovnávají jen přes BINARY.
        '1584_payroll_enforcement_xmlzam_guards.sql',
        '1585_payroll_enforcement_xmlzam_guard_collation.sql',
        '1586_payroll_enforcement_xmlzam_manifest_integrity.sql',
        // Role se porovnává jen s literálem, ten se collation sloupce přizpůsobí.
        '2134_property_party_claim_keys.sql',
    ];

    public function testMigrationJsonTableTextColumnsDeclareCollation(): void
    {
        $offenders = [];
        foreach (glob(dirname(__DIR__, 3) . '/db/migrations/*.sql') ?: [] as $path) {
            if (in_array(basename($path), self::MIGRATION_EXCEPTIONS, true)) {
                continue;
            }
            $sql = (string) file_get_contents($path);
            if (stripos($sql, 'JSON_TABLE') === false) {
                continue;
            }
            preg_match_all('/JSON_TABLE\s*\([^;]*/is', $sql, $statements);
            foreach ($statements[0] as $statement) {
                $textual = preg_match('/\b(?:VARCHAR|CHAR|TEXT)\s*(?:\(\d+\))?\s+PATH\b/i', $statement) === 1;
                if ($textual && stripos($statement, 'COLLATE') === false) {
                    $offenders[] = basename($path);
                }
            }
        }
        $offenders = array_values(array_unique($offenders));

        self::assertSame([], $offenders, "Textový sloupec JSON_TABLE v migraci bez výslovné collation:\n" . implode("\n", $offenders));
    }
}
