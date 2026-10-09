<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Migration;

use MyInvoice\Service\Payroll\Import\Jmhz\JmhzReportForm;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverJmhzFormCheck;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverMonth;
use PHPUnit\Framework\TestCase;

/**
 * Převzatá mzda proti přijatému hlášení JMHZ téhož měsíce: hlášení podané před
 * opravou mzdy se ukáže, zaokrouhlení na celé koruny ne.
 */
final class PayrollTakeoverJmhzFormCheckTest extends TestCase
{
    public function testReportFiledBeforeWageCorrectionIsReported(): void
    {
        // Celý měsíc v neschopnosti: hlášení odešlo s plným tarifem, mzda je nula.
        $rows = PayrollTakeoverJmhzFormCheck::compare(
            [self::wage(1, 11, 0, 0, 0, 0, 0)],
            [self::entry(1, 11, self::form(32_164, 32_164, 2_260, 1_448, 2_895))],
        );

        self::assertCount(1, $rows);
        self::assertSame('2026-06', $rows[0]['period']);
        self::assertSame(1, $rows[0]['employee_id']);
        self::assertSame([11], $rows[0]['employment_ids']);
        self::assertSame(501, $rows[0]['submission_id']);
        self::assertSame([
            ['metric' => 'gross', 'takeover_minor' => 0, 'jmhz_minor' => 32_164_00, 'difference_minor' => 32_164_00],
            ['metric' => 'social_base', 'takeover_minor' => 0, 'jmhz_minor' => 32_164_00, 'difference_minor' => 32_164_00],
            ['metric' => 'advance_tax', 'takeover_minor' => 0, 'jmhz_minor' => 2_260_00, 'difference_minor' => 2_260_00],
            ['metric' => 'health_insurance', 'takeover_minor' => 0, 'jmhz_minor' => 4_343_00, 'difference_minor' => 4_343_00],
        ], $rows[0]['differences']);
    }

    public function testRoundingToWholeCrownsIsNotReported(): void
    {
        // Převzatá strana v haléřích, hlášení v celých korunách; zdravotní pojistné
        // zaokrouhlené po podílech o 2 Kč jinak.
        $rows = PayrollTakeoverJmhzFormCheck::compare(
            [self::wage(2, 21, 40_000_49, 40_000_49, 4_879_99, 1_800_00, 3_600_00)],
            [self::entry(2, 21, self::form(40_000, 40_000, 4_880, 1_801, 3_601))],
        );

        self::assertSame([], $rows);
    }

    public function testDifferenceOfOneCrownIsReported(): void
    {
        $rows = PayrollTakeoverJmhzFormCheck::compare(
            [self::wage(2, 21, 40_000_00, 40_000_00, 4_880_00, 1_800_00, 3_600_00)],
            [self::entry(2, 21, self::form(40_000, 40_000, 4_881, 1_800, 3_600))],
        );

        self::assertCount(1, $rows);
        self::assertSame(['advance_tax'], array_column($rows[0]['differences'], 'metric'));
    }

    /**
     * Osvobozené plnění (stravenkový paušál) je v 10286, v hrubé mzdě předchozího
     * programu být nemusí. Rozdíl jen v příjmu proto nález nezakládá.
     */
    public function testIncomeDifferenceAloneIsNotReported(): void
    {
        $rows = PayrollTakeoverJmhzFormCheck::compare(
            [self::wage(2, 21, 40_000_00, 40_000_00, 4_880_00, 1_800_00, 3_600_00)],
            [self::entry(2, 21, self::form(42_300, 40_000, 4_880, 1_800, 3_600))],
        );

        self::assertSame([], $rows);
    }

    public function testNothingIsReportedWithoutForm(): void
    {
        self::assertSame([], PayrollTakeoverJmhzFormCheck::compare([self::wage(1, 11, 0, 0, 0, 0, 0)], []));
    }

    public function testTakeoverFromTheSameReportIsNotComparedWithItself(): void
    {
        $rows = PayrollTakeoverJmhzFormCheck::compare(
            [self::wage(1, 11, 0, 0, 0, 0, 0, 'jmhz')],
            [self::entry(1, 11, self::form(32_164, 32_164, 2_260, 1_448, 2_895))],
        );

        self::assertSame([], $rows);
    }

    public function testDifferentRelationshipScopeIsNotCompared(): void
    {
        // Převzatá mzda nese dva vztahy, hlášení jen jeden: rozsah se liší, údaje ne.
        $rows = PayrollTakeoverJmhzFormCheck::compare(
            [self::wage(1, 11, 30_000_00, 30_000_00, 0, 0, 0), self::wage(1, 12, 5_000_00, 0, 0, 0, 0)],
            [self::entry(1, 11, self::form(30_000, 30_000, 0, 0, 0))],
        );

        self::assertSame([], $rows);
    }

    public function testValueMissingInFormIsNotCompared(): void
    {
        $form = new JmhzReportForm(1, 'A0000000-0000-4000-8000-000000000001', 'R', true, 'bezPriznaku', socialBase: 0);
        $rows = PayrollTakeoverJmhzFormCheck::compare(
            [self::wage(1, 11, 30_000_00, 0, 4_000_00, 0, 0)],
            [self::entry(1, 11, $form)],
        );

        self::assertSame([], $rows);
    }

    /** Formulář bez pojistného ZP zaměstnavatele: chybějící složka není nula. */
    public function testMissingEmployerHealthIsNotCompared(): void
    {
        $rows = PayrollTakeoverJmhzFormCheck::compare(
            [self::wage(2, 21, 40_000_00, 40_000_00, 4_880_00, 1_800_00, 3_600_00)],
            [self::entry(2, 21, self::form(40_000, 40_000, 4_880, 1_800, null))],
        );

        self::assertSame([], $rows);
    }

    public function testEmployerHealthDifferenceIsReported(): void
    {
        $rows = PayrollTakeoverJmhzFormCheck::compare(
            [self::wage(2, 21, 40_000_00, 40_000_00, 4_880_00, 1_800_00, 3_600_00)],
            [self::entry(2, 21, self::form(40_000, 40_000, 4_880, 1_800, 3_900))],
        );

        self::assertCount(1, $rows);
        self::assertSame([
            ['metric' => 'health_insurance', 'takeover_minor' => 5_400_00, 'jmhz_minor' => 5_700_00, 'difference_minor' => 300_00],
        ], $rows[0]['differences']);
    }

    private static function wage(int $employeeId, int $employmentId, int $gross, int $socialBase, int $advance, int $employeeHealth, int $employerHealth, string $source = 'pamica'): PayrollTakeoverMonth
    {
        return PayrollTakeoverMonth::fromRow([
            'period' => '2026-06',
            'source' => $source,
            'external_person_ref' => 'P-' . $employeeId,
            'external_relationship_ref' => 'R-' . $employmentId,
            'employee_id' => $employeeId,
            'employment_id' => $employmentId,
            'gross_minor' => $gross,
            'social_base_minor' => $socialBase,
            'health_base_minor' => $socialBase,
            'advance_tax_minor' => $advance,
            'employee_health_minor' => $employeeHealth,
            'employer_health_minor' => $employerHealth,
        ]);
    }

    /** @return array{submission_id:int,submission_type:?string,submitted_at:?string,period:string,employee_id:?int,employment_id:int,form:JmhzReportForm} */
    private static function entry(int $employeeId, int $employmentId, JmhzReportForm $form): array
    {
        return [
            'submission_id' => 501,
            'submission_type' => 'R',
            'submitted_at' => '2026-07-14 06:49:00',
            'period' => '2026-06',
            'employee_id' => $employeeId,
            'employment_id' => $employmentId,
            'form' => $form,
        ];
    }

    private static function form(int $income, int $socialBase, int $advance, ?int $employeeHealth, ?int $employerHealth): JmhzReportForm
    {
        return new JmhzReportForm(
            1,
            'A0000000-0000-4000-8000-000000000001',
            'R',
            true,
            'bezPriznaku',
            hasSummary: true,
            incomeTotal: $income,
            advance: ['base' => $income, 'computed' => null, 'after_credits' => $advance, 'bonus' => 0],
            socialBase: $socialBase,
            employeeHealth: $employeeHealth,
            employerHealth: $employerHealth,
        );
    }
}
