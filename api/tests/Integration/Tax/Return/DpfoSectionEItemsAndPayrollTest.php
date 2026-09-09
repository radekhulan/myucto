<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax\Return;

use MyInvoice\Service\Tax\Return\DpfoReturnDataProvider;
use MyInvoice\Tests\Integration\TaxEvidence\CashJournalTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * Podklady DPFO: položkový rozpis oddílu E Přílohy č. 1 (P-5) a úhrn zúčtovaných
 * mezd pro `kc_dpfmz18` (P-8) — viz private/DANE-PLAN.md.
 *
 * P-5 regrese: `DpfoReturnDataProvider::closing()` četlo úpravy § 23 jako
 * `SELECT direction, SUM(amount) … GROUP BY direction`, takže popisy z
 * `tax_evidence_non_cash_adjustments.description` se nikam nepředávaly a stavěč XML
 * jel v produkci VŽDY fallback větví „jeden souhrnný řádek + varování", i když
 * jednotlivé položky v databázi byly.
 *
 * P-8 regrese: úhrn hrubých mezd se do podkladů nedostával vůbec.
 */
#[Group('integration')]
final class DpfoSectionEItemsAndPayrollTest extends CashJournalTestCase
{
    private DpfoReturnDataProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = $this->container->get(DpfoReturnDataProvider::class);
        $this->setVatPayer($this->supplierId, false);
    }

    private function createClosing(string $status = 'final'): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO tax_evidence_closings
                 (supplier_id, year, status, checklist, opening_balances, closing_balances, unsupported_cases)
             VALUES (?, ?, ?, '{}', '{}', '{}', '[]')"
        )->execute([$this->supplierId, self::YEAR, $status]);

        return (int) $pdo->lastInsertId();
    }

    private function addAdjustment(int $closingId, string $direction, float $amount, string $description): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO tax_evidence_non_cash_adjustments
                 (supplier_id, closing_id, adjustment_on, kind, direction, amount, description)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $this->supplierId,
            $closingId,
            self::YEAR . '-12-31',
            'section23_other',
            $direction,
            $amount,
            $description,
        ]);
    }

    private function createEmployee(string $name): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO payroll_employees (supplier_id, full_name) VALUES (?, ?)')
            ->execute([$this->supplierId, $name]);

        return (int) $pdo->lastInsertId();
    }

    private function addMonthlyRecord(int $employeeId, int $month, int $gross, bool $retired = false): void
    {
        // `chk_payroll_monthly_record_retired`: odložení je trojice atributů, ne jen datum.
        $retirement = $retired ? 'NOW(), ?, ?' : 'NULL, NULL, NULL';
        $params = [
            $this->supplierId,
            $employeeId,
            self::YEAR,
            $month,
            $gross,
            json_encode(['gross' => $gross], JSON_THROW_ON_ERROR),
            $gross,
        ];
        if ($retired) {
            $params[] = $this->userId;
            $params[] = 'Období převzal modul Mzdy (test).';
        }
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_monthly_records
                 (supplier_id, employee_id, year, month, gross, breakdown, advance_tax_final, net_final,
                  retired_at, retired_by, retired_reason)
             VALUES (?, ?, ?, ?, ?, ?, 0, ?, ' . $retirement . ')'
        )->execute($params);
    }

    // ── P-5 ─────────────────────────────────────────────────────────────────

    public function testAdjustmentItemsAreHandedOverWithDescriptions(): void
    {
        $closingId = $this->createClosing();
        $this->addAdjustment($closingId, 'increase', 20000.0, 'Nepeněžní příjem ze zápočtu');
        $this->addAdjustment($closingId, 'increase', 10000.0, 'Osobní spotřeba zásob');
        $this->addAdjustment($closingId, 'decrease', 15000.0, 'Zaplacené pojistné z minulého období');
        $this->addAdjustment($closingId, 'neutral', 7000.0, 'Neutrální přeúčtování');

        $result = $this->provider->gather($this->supplierId, self::YEAR);

        self::assertCount(2, $result['s7_increase_items']);
        self::assertSame(
            ['Nepeněžní příjem ze zápočtu', 'Osobní spotřeba zásob'],
            array_column($result['s7_increase_items'], 'description'),
        );
        self::assertCount(1, $result['s7_decrease_items']);
        self::assertSame('Zaplacené pojistné z minulého období', $result['s7_decrease_items'][0]['description']);
    }

    /** Souhrn ř. 105/106 MUSÍ zůstat součtem předaných položek — jeden zdroj, jedny řádky. */
    public function testItemsSumEqualsAggregateTotals(): void
    {
        $closingId = $this->createClosing();
        $this->addAdjustment($closingId, 'increase', 20000.0, 'Nepeněžní příjem ze zápočtu');
        $this->addAdjustment($closingId, 'increase', 10000.0, 'Osobní spotřeba zásob');
        $this->addAdjustment($closingId, 'decrease', 9000.0, 'Zaplacené pojistné z minulého období');
        $this->addAdjustment($closingId, 'decrease', 6000.0, 'Oprava duplicitního výnosu');

        $result = $this->provider->gather($this->supplierId, self::YEAR);

        self::assertEqualsWithDelta(30000.0, $result['s7_increase'], 0.001);
        self::assertEqualsWithDelta(15000.0, $result['s7_decrease'], 0.001);
        self::assertEqualsWithDelta(
            $result['s7_increase'],
            array_sum(array_column($result['s7_increase_items'], 'amount')),
            0.001,
            'Součet položek oddílu E se nesmí rozejít s úhrnem na ř. 105.',
        );
        self::assertEqualsWithDelta(
            $result['s7_decrease'],
            array_sum(array_column($result['s7_decrease_items'], 'amount')),
            0.001,
            'Součet položek oddílu E se nesmí rozejít s úhrnem na ř. 106.',
        );
    }

    public function testClosingWithoutAdjustmentsHandsOverNoItems(): void
    {
        $this->createClosing();

        $result = $this->provider->gather($this->supplierId, self::YEAR);

        self::assertSame([], $result['s7_increase_items']);
        self::assertSame([], $result['s7_decrease_items']);
        self::assertEqualsWithDelta(0.0, $result['s7_increase'], 0.001);
    }

    // ── P-8 ─────────────────────────────────────────────────────────────────

    public function testPayrollGrossSumsMonthlyRecords(): void
    {
        $this->createClosing();
        $employee = $this->createEmployee('Testovací zaměstnanec');
        $this->addMonthlyRecord($employee, 1, 30000);
        $this->addMonthlyRecord($employee, 2, 25000);

        $result = $this->provider->gather($this->supplierId, self::YEAR);

        self::assertEqualsWithDelta(55000.0, $result['payroll_gross'], 0.001);
    }

    /**
     * Měsíc, který od ruční rekapitulace převzal modul Mzdy, je odložený (`retired_at`)
     * a jeho hrubé mzdy nese roční sestava modulu — do úhrnu nesmí vstoupit dvakrát.
     */
    public function testRetiredMonthlyRecordIsNotCounted(): void
    {
        $this->createClosing();
        $employee = $this->createEmployee('Testovací zaměstnanec');
        $this->addMonthlyRecord($employee, 1, 30000);
        $this->addMonthlyRecord($employee, 2, 25000, true);

        $result = $this->provider->gather($this->supplierId, self::YEAR);

        self::assertEqualsWithDelta(30000.0, $result['payroll_gross'], 0.001);
    }

    public function testNoPayrollDataGivesNullWithoutWarning(): void
    {
        $this->createClosing();

        $result = $this->provider->gather($this->supplierId, self::YEAR);

        self::assertNull($result['payroll_gross']);
        self::assertSame([], array_values(array_filter(
            $result['warnings'],
            static fn (string $w): bool => str_contains($w, 'hrubých mezd'),
        )));
    }
}
