<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Migrace smí rušit jen cizí klíč, jehož jméno nějaká migrace explicitně deklarovala.
 *
 * Automatické jméno `<tabulka>_ibfk_N` určuje pořadí FK v CREATE TABLE. Stačí přidat
 * nebo přeskládat jiný FK a `DROP FOREIGN KEY IF EXISTS …_ibfk_7` tiše zruší jiný klíč,
 * nebo nezruší nic. Migrace 2062 takhle mazala FK na nákladový řádek čerpání úspory.
 */
final class MigrationForeignKeyNameGuardTest extends TestCase
{
    public function testDroppedForeignKeysUseExplicitlyDeclaredNames(): void
    {
        $files = glob(\dirname(__DIR__, 3) . '/db/migrations/*.sql') ?: [];
        self::assertNotEmpty($files);

        $sources = [];
        $declared = [];
        foreach ($files as $file) {
            $sql = self::withoutComments((string) file_get_contents($file));
            $sources[basename($file)] = $sql;
            preg_match_all('/CONSTRAINT\s+`?(\w+)`?\s+FOREIGN\s+KEY/i', $sql, $m);
            foreach ($m[1] as $name) {
                $declared[strtolower($name)] = true;
            }
        }
        self::assertNotEmpty($declared, 'Žádný pojmenovaný FK — guard by neměřil nic.');

        $offenders = [];
        foreach ($sources as $file => $sql) {
            foreach (self::droppedForeignKeys($sql) as $name) {
                if (preg_match('/_ibfk_\d+$/i', $name) === 1 || !isset($declared[strtolower($name)])) {
                    $offenders[] = $file . ' → ' . $name;
                }
            }
        }

        self::assertSame([], $offenders, "Cizí klíč rušený migrací musí mít jméno deklarované přes CONSTRAINT <jméno> FOREIGN KEY:\n  "
            . implode("\n  ", $offenders));
    }

    public function testScannerRejectsAutomaticName(): void
    {
        self::assertSame(['nonprofit_tax_saving_events_ibfk_7'], self::droppedForeignKeys(
            'ALTER TABLE nonprofit_tax_saving_events DROP FOREIGN KEY IF EXISTS nonprofit_tax_saving_events_ibfk_7;'
        ));
        self::assertSame(['fk_named'], self::droppedForeignKeys("ALTER TABLE t\n  DROP FOREIGN KEY `fk_named`;"));
        self::assertSame([], self::droppedForeignKeys(self::withoutComments('-- DROP FOREIGN KEY IF EXISTS t_ibfk_1')));
    }

    /** @return list<string> */
    private static function droppedForeignKeys(string $sql): array
    {
        preg_match_all('/DROP\s+FOREIGN\s+KEY\s+(?:IF\s+EXISTS\s+)?`?(\w+)`?/i', $sql, $m);
        return $m[1];
    }

    private static function withoutComments(string $sql): string
    {
        return (string) preg_replace('/--[^\n]*/', '', $sql);
    }
}
