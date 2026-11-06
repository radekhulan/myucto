<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Security;

use MyInvoice\Service\Anonymization\AnonymizationPolicy;
use MyInvoice\Service\Payroll\Security\PayrollKeyRotationService;
use PHPUnit\Framework\TestCase;

/**
 * Výčet mzdových šifrovaných sloupců pro rotaci klíče nesmí zaostat za
 * skutečností. Sloupec, na který rotace zapomene, drží starý klíč navždy
 * a ten pak nejde z konfigurace odebrat.
 *
 * Inventář citlivých sloupců vede {@see AnonymizationPolicy} (nový sloupec
 * bez strategie tam shodí vlastní test), takže je to spolehlivý protějšek.
 */
final class PayrollKeyRotationCatalogTest extends TestCase
{
    public function testEveryPayrollCiphertextColumnIsRotated(): void
    {
        $expected = [];
        foreach (AnonymizationPolicy::COLUMNS as $table => $columns) {
            foreach ($columns as $column => $strategy) {
                // Mzdové tabulky celé; mimo mzdy každá hodnota zapečetěná mzdovou
                // primitivou (`sealed:`), třeba rodná čísla daňového profilu (H28).
                $payroll = str_starts_with($table, 'payroll_');
                if (str_ends_with($column, '_ciphertext')
                    && ($payroll || AnonymizationPolicy::isSealed($strategy))
                ) {
                    $expected[] = $table . '.' . $column;
                }
            }
        }
        $covered = array_map(
            static fn (array $t): string => $t['table'] . '.' . $t['column'],
            PayrollKeyRotationService::targets(),
        );

        sort($expected);
        sort($covered);
        self::assertNotEmpty($expected);
        self::assertSame([], array_values(array_diff($expected, $covered)), 'Sloupce bez rotace.');
        self::assertSame([], array_values(array_diff($covered, $expected)), 'Rotace mimo inventář citlivých sloupců.');
    }

    /**
     * Append-only tabulka pustí přebalení jen přes výjimku z migrace 1915.
     * Proměnná v katalogu a v triggeru se musí shodovat, jinak UPDATE
     * narazí na trigger.
     */
    public function testGuardVariablesMatchMigration(): void
    {
        $migration = (string) file_get_contents(
            dirname(__DIR__, 5) . '/db/migrations/1915_payroll_key_rewrap_trigger_guard.sql',
        );
        foreach (PayrollKeyRotationService::targets() as $target) {
            if ($target['guard'] === null) {
                self::assertStringNotContainsString('ON ' . $target['table'] . "\n", $migration);
                continue;
            }
            self::assertSame(
                'payroll_key_rewrap_' . preg_replace('/^payroll_/', '', $target['table']),
                $target['guard'],
            );
            self::assertStringContainsString('@' . $target['guard'] . ' <=> OLD.id', $migration);
            self::assertStringContainsString('BEFORE UPDATE ON ' . $target['table'] . "\n", $migration);
        }
    }
}
