<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollComponentRepository;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Migrace 2284: příspěvek zaměstnavatele na penzijní a životní produkty a na
 * pojištění dlouhodobé péče se u už založených výchozích složek mění na
 * nepeněžní plnění (§ 6 odst. 9 písm. m) ZDP, platí se instituci).
 *
 * Mění se jen složka v neposouzeném stavu nebo zařazená jako osvobozená;
 * složka, kterou firma vede jako zdanitelnou peněžní, zůstává. Opakované
 * spuštění nic nezmění.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eFix4ContributionMigrationTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const MIGRATION = '2284_payroll_component_employer_contribution_non_monetary.sql';

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $components = $this->container->get(PayrollComponentRepository::class);
        self::assertInstanceOf(PayrollComponentRepository::class, $components);
        $components->ensureDefaults($this->supplierId);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testDefaultsAreNonMonetary(): void
    {
        self::assertSame('non_monetary', $this->component('PRISPEVEK_PENZE_ZIVOTNI')['value_kind']);
        self::assertSame('non_monetary', $this->component('PRISPEVEK_DLOUHODOBA_PECE')['value_kind']);
    }

    public function testMigrationTurnsUnreviewedAndExemptContributionIntoNonMonetary(): void
    {
        // Stav firmy před opravou: peněžní složka, jedna neposouzená, druhá
        // zařazená jako osvobozená.
        $this->legacy('PRISPEVEK_PENZE_ZIVOTNI', 'exempt');
        $this->legacy('PRISPEVEK_DLOUHODOBA_PECE', 'manual_review');
        $before = $this->component('PRISPEVEK_PENZE_ZIVOTNI');

        $this->runMigration();

        $after = $this->component('PRISPEVEK_PENZE_ZIVOTNI');
        self::assertSame('non_monetary', $after['value_kind']);
        self::assertSame('exempt', $after['tax_treatment'], 'Zařazení firmy se nemění.');
        self::assertSame((int) $before['row_version'] + 1, (int) $after['row_version']);
        self::assertSame('non_monetary', $this->component('PRISPEVEK_DLOUHODOBA_PECE')['value_kind']);

        // Idempotence: druhé spuštění nezmění ani verzi řádku.
        $this->runMigration();
        self::assertSame((int) $after['row_version'], (int) $this->component('PRISPEVEK_PENZE_ZIVOTNI')['row_version']);
    }

    public function testMigrationKeepsContributionTheCompanyPaysOutAsTaxableWage(): void
    {
        $this->legacy('PRISPEVEK_PENZE_ZIVOTNI', 'included');
        $before = $this->component('PRISPEVEK_PENZE_ZIVOTNI');

        $this->runMigration();

        self::assertSame($before, $this->component('PRISPEVEK_PENZE_ZIVOTNI'));
    }

    public function testMigrationDoesNotTouchOtherMonetaryBenefits(): void
    {
        $before = $this->component('PRISPEVEK_STRAVOVANI');
        self::assertSame('monetary', $before['value_kind']);

        $this->runMigration();

        self::assertSame($before, $this->component('PRISPEVEK_STRAVOVANI'));
    }

    private function legacy(string $code, string $taxTreatment): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_component_definitions
                SET value_kind = "monetary", tax_treatment = ?
              WHERE supplier_id = ? AND code = ?',
        )->execute([$taxTreatment, $this->supplierId, $code]);
    }

    /** @return array<string,mixed> */
    private function component(string $code): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT value_kind, tax_treatment, row_version
               FROM payroll_component_definitions
              WHERE supplier_id = ? AND code = ?',
        );
        $statement->execute([$this->supplierId, $code]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row, "Složka {$code} ve výchozím číselníku chybí.");

        return $row;
    }

    private function runMigration(): void
    {
        $sql = file_get_contents(dirname(__DIR__, 4) . '/db/migrations/' . self::MIGRATION);
        self::assertIsString($sql);
        $start = strpos($sql, 'UPDATE payroll_component_definitions');
        self::assertIsInt($start);
        $this->db->pdo()->exec(substr($sql, $start));
    }
}
