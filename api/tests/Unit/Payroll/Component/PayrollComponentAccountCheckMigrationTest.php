<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Component;

use MyInvoice\Service\Payroll\Accounting\PayrollAccountCode;
use PHPUnit\Framework\TestCase;

/**
 * W3/Ú-02 — CHECK předkontací složek musí sedět na TABULCE, KTERÁ EXISTUJE.
 *
 * Migrace 1262 mířila na `payroll_components`, jenže číselník se jmenuje
 * `payroll_component_definitions`. `ALTER TABLE IF EXISTS` chybu spolkl,
 * migrace prošla zeleně a dál platil původní CHECK bez tečky, takže
 * analytická předkontace 521.100 v databázi neprošla, přestože ji PHP
 * schválilo.
 */
final class PayrollComponentAccountCheckMigrationTest extends TestCase
{
    private const REGEX = '^[0-9]{3}[.A-Z0-9]{0,13}$';

    public function testCheckIsRebuiltOnTheRealComponentTable(): void
    {
        $sql = $this->migration();

        self::assertStringContainsString(
            "ALTER TABLE payroll_component_definitions\n"
            . '  DROP CONSTRAINT IF EXISTS chk_payroll_component_accounts;',
            $sql,
            'Bez DROP CONSTRAINT IF EXISTS není migrace idempotentní — '
            . 'MariaDB neumí u CHECKu IF NOT EXISTS.',
        );
        self::assertStringContainsString(
            'ADD CONSTRAINT chk_payroll_component_accounts CHECK (',
            $sql,
        );
        $statements = $this->statements($sql);
        self::assertStringNotContainsString(
            'ALTER TABLE IF EXISTS',
            $statements,
            'IF EXISTS by znovu spolklo překlep v názvu tabulky.',
        );
        self::assertStringNotContainsString(
            'payroll_components ',
            $statements,
            'Tabulka payroll_components neexistuje.',
        );
    }

    public function testDatabaseRegexMatchesTheApplicationFormat(): void
    {
        $sql = $this->migration();

        self::assertSame(
            2,
            substr_count($sql, "REGEXP '" . self::REGEX . "'"),
            'Obě strany předkontace musí povolovat týž formát jako PHP.',
        );
        foreach (['521', '521.100', '336.OSSZ'] as $accepted) {
            self::assertTrue(PayrollAccountCode::isValid($accepted));
            self::assertSame(1, preg_match('/' . self::REGEX . '/D', $accepted));
        }
        foreach (['52', '521.', '521.100.200.300.400', 'abc'] as $rejected) {
            self::assertSame(
                PayrollAccountCode::isValid($rejected),
                preg_match('/' . self::REGEX . '/D', $rejected) === 1,
                "Formát {$rejected} musí PHP i databáze posoudit stejně.",
            );
        }
    }

    /** Migrace bez komentářů — komentáře smí historii popisovat i doslova. */
    private function statements(string $sql): string
    {
        return implode("\n", array_filter(
            explode("\n", $sql),
            static fn (string $line): bool => !str_starts_with(trim($line), '--'),
        ));
    }

    private function migration(): string
    {
        $path = dirname(__DIR__, 5)
            . '/db/migrations/1613_payroll_component_account_check_fix.sql';
        $sql = file_get_contents($path);
        self::assertIsString($sql);

        return $sql;
    }
}
