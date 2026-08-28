<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use PHPUnit\Framework\TestCase;

final class PayrollSurchargeMigrationTest extends TestCase
{
    public function testPolicyIsVersionedPerEmploymentAndScopedToSupplier(): void
    {
        $sql = $this->migration('1624_payroll_employment_surcharge_policies.sql');

        self::assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS payroll_employment_surcharge_policies',
            $sql,
        );
        // Verzování: jedna verze na den účinnosti, ne jeden řádek na vztah.
        self::assertStringContainsString(
            'UNIQUE KEY uq_payroll_surcharge_policy_version
    (supplier_id, employment_id, valid_from)',
            $sql,
        );
        self::assertStringContainsString(
            'FOREIGN KEY (supplier_id, employment_id)',
            $sql,
        );
        self::assertStringContainsString('ON DELETE CASCADE', $sql);
    }

    /**
     * § 114 odst. 3 se týká jen přesčasu. Kdyby `holiday_mode` nabízel
     * `included_in_wage`, dal by se příplatek za svátek vypnout zaklikáním
     * něčeho, co zákon nezná.
     */
    public function testHolidayModeDoesNotOfferWageInclusion(): void
    {
        $sql = $this->migration('1624_payroll_employment_surcharge_policies.sql');

        self::assertStringContainsString(
            "overtime_mode                 ENUM('surcharge','compensatory_time_off','included_in_wage')",
            $sql,
        );
        self::assertStringContainsString(
            "holiday_mode                  ENUM('compensatory_time_off','surcharge')",
            $sql,
        );
    }

    /**
     * Výchozí hodnoty musí odpovídat zákonu, ne pohodlí: § 114 odst. 1 příplatek,
     * § 115 odst. 1 náhradní volno.
     */
    public function testDefaultModesFollowTheLabourCodeDefaults(): void
    {
        $sql = $this->migration('1624_payroll_employment_surcharge_policies.sql');

        self::assertStringContainsString("NOT NULL DEFAULT 'surcharge'", $sql);
        self::assertStringContainsString("NOT NULL DEFAULT 'compensatory_time_off'", $sql);
    }

    /**
     * Sazba nad 100 % musí projít: zákonné minimum § 115 je rovných 100 %,
     * takže cokoli sjednaného nad rámec zákona je vyšší.
     */
    public function testAgreedRateCeilingAllowsMoreThanOneHundredPercent(): void
    {
        $sql = $this->migration('1624_payroll_employment_surcharge_policies.sql');

        self::assertStringContainsString('holiday_rate_bp IS NULL OR holiday_rate_bp BETWEEN 1 AND 50000', $sql);
        self::assertStringNotContainsString('BETWEEN 1 AND 10000', $sql);
    }

    /** CHECK se musí nejdřív zahodit — MariaDB u něj `IF NOT EXISTS` nemá. */
    public function testConstraintsAreIdempotent(): void
    {
        foreach ([
            '1624_payroll_employment_surcharge_policies.sql',
            '1625_payroll_time_entry_difficulty_factors.sql',
        ] as $file) {
            $sql = $this->migration($file);
            $added = substr_count($sql, 'ADD CONSTRAINT');
            self::assertSame(
                $added,
                substr_count($sql, 'DROP CONSTRAINT IF EXISTS'),
                "Migrace {$file} přidává CHECK, který předtím nezahazuje.",
            );
        }
    }

    public function testDifficultyFactorColumnIsNullableAndTiedToItsCategory(): void
    {
        $sql = $this->migration('1625_payroll_time_entry_difficulty_factors.sql');

        self::assertStringContainsString(
            'ADD COLUMN IF NOT EXISTS difficulty_factor_count TINYINT UNSIGNED NULL',
            $sql,
        );
        // Počet vlivů dává smysl jen u ztíženého prostředí a nikdy nesmí být nula:
        // „ztížené prostředí bez ztěžujícího vlivu" je protimluv.
        self::assertStringContainsString(
            "category = 'difficult_environment' AND difficulty_factor_count >= 1",
            $sql,
        );
    }

    /** Existující zápisy docházky se nesmí dotknout — NULL znamená „platí obvyklý stav". */
    public function testDifficultyFactorMigrationDoesNotBackfill(): void
    {
        $sql = $this->migration('1625_payroll_time_entry_difficulty_factors.sql');

        self::assertStringNotContainsString('UPDATE payroll_time_entries', $sql);
        self::assertStringNotContainsString('NOT NULL DEFAULT 1', $sql);
    }

    private function migration(string $file): string
    {
        $path = dirname(__DIR__, 4) . '/db/migrations/' . $file;
        $sql = file_get_contents($path);
        self::assertIsString($sql, "Migrace {$file} neexistuje.");

        return $sql;
    }
}
