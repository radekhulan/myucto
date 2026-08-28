<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class PayrollNetMigrationTest extends TestCase
{
    public function testNetPayPersistenceIsTenantScopedAndAppendOnlyReady(): void
    {
        $path = dirname(__DIR__, 3)
            . '/db/migrations/1250_payroll_net_pay_and_deductions.sql';
        self::assertFileExists($path);
        $sql = (string) file_get_contents($path);
        $repairPath = dirname(__DIR__, 3)
            . '/db/migrations/1251_payroll_deduction_agreement_owner.sql';
        self::assertFileExists($repairPath);
        $repairSql = (string) file_get_contents($repairPath);

        foreach ([
            'payroll_deduction_agreements',
            'payroll_deduction_ledger',
            'payroll_net_results',
            'payroll_payout_rules',
            'payroll_payout_allocations',
        ] as $table) {
            self::assertStringContainsString(
                "CREATE TABLE IF NOT EXISTS {$table}",
                $sql,
                $table,
            );
        }
        self::assertStringContainsString(
            'UNIQUE KEY uq_payroll_net_result_revision_employee',
            $sql,
        );
        self::assertStringContainsString(
            'UNIQUE KEY uq_payroll_deduction_ledger_event',
            $sql,
        );
        self::assertStringContainsString(
            'FOREIGN KEY (supplier_id, revision_id)',
            $sql,
        );
        self::assertStringContainsString(
            'FOREIGN KEY (supplier_id, employee_id)',
            $sql,
        );
        self::assertStringContainsString(
            'CHECK (amount_minor <> 0)',
            $sql,
        );
        foreach ([$sql, $repairSql] as $migrationSql) {
            self::assertStringContainsString(
                'FOREIGN KEY (supplier_id, agreement_id, employee_id)',
                $migrationSql,
            );
            self::assertStringContainsString(
                '(supplier_id, id, employee_id)',
                $migrationSql,
            );
        }
    }

    /**
     * `payroll_net_results` a `payroll_payout_allocations` jsou mrtvé tabulky.
     *
     * Zapisovatel do nich nikdy neměl produkčního volajícího a model ho mezitím
     * přerostl: alokace by se počítaly z čisté mzdy PŘED exekučními srážkami,
     * kdežto skutečné platby se rozdělují z `payable_after_enforcement_minor`
     * (`PayrollNetWageLiabilityMaterializer`). Kdyby někdo zápis obnovil, vznikl
     * by druhý — a s tím prvním rozporný — rozpis těch samých peněz, a to
     * NEMĚNNĚ (migrace 1631). Zdroj pravdy je zmrazená revize plus
     * `payroll_payment_liabilities` s `liability_kind = 'net_wage'`.
     *
     * Test je proto brána, ne popis: hlídá, že do těch tabulek `src/` nezapisuje.
     */
    public function testDeadNetResultTablesHaveNoProductionWriter(): void
    {
        $source = dirname(__DIR__, 2) . '/src';
        self::assertDirectoryExists($source);

        $offenders = [];
        $files = new \RegexIterator(
            new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            ),
            '/\.php$/D',
        );
        foreach ($files as $file) {
            $code = (string) file_get_contents((string) $file);
            foreach (['payroll_net_results', 'payroll_payout_allocations'] as $table) {
                if (preg_match(
                    '/(INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO|UPDATE)\s+' . $table . '\b/i',
                    $code,
                ) === 1) {
                    $offenders[] = basename((string) $file) . " => {$table}";
                }
            }
        }

        self::assertSame([], $offenders);
    }
}
