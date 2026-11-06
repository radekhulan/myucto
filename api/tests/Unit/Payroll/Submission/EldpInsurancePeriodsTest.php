<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Eldp\EldpAnnualStatementBuilder;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpValidationException;
use PHPUnit\Framework\TestCase;

/**
 * Potvrzení o době důchodového pojištění v kalendářním roce (§ 42 zákona
 * č. 582/1991 Sb.): doby skládá týž sestavovač jako evidenční list, ale
 * potvrzení se vydává za každý rok a plný starobní důchod dobu pojištění
 * nekrátí. Syntetická data (firma 7, osoba 11, vztah 101).
 */
final class EldpInsurancePeriodsTest extends TestCase
{
    use EldpYearFixture;

    private const NO_PENSION = [
        'pension_age_reached_on' => null,
        'early_pension_from' => null,
        'full_pension_paid_from' => null,
        'foreign_insurance' => false,
    ];

    /**
     * Rok 2026: evidenční list sestavuje ČSSZ, ale potvrzení zaměstnavatel
     * vydává; trvající vztah končí posledním zúčtovaným měsícem.
     */
    public function testConfirmationCoversAYearWithoutAStandaloneStatement(): void
    {
        $revisions = $this->year([
            ['id' => 101, 'relation' => 'employment', 'code' => '1', 'start' => '2024-03-01', 'end' => null],
        ], 2026, 7);

        $periods = (new EldpAnnualStatementBuilder())->insurancePeriods(7, 101, 2026, $revisions, self::NO_PENSION);

        self::assertSame([['from' => '2026-01-01', 'to' => '2026-07-31', 'days' => 212, 'months_without_insurance' => []]], $periods['periods']);
        self::assertSame(212, $periods['insurance_days']);
        self::assertSame(11, $periods['employee_id']);
        self::assertCount(7, $periods['source_revisions']);

        try {
            (new EldpAnnualStatementBuilder())->build(7, 101, 2026, $revisions, [
                'excluded_days_confirmed' => true,
                'pension_status' => self::NO_PENSION,
                'requested_by_authority' => false,
            ]);
            self::fail('Samostatný list za rok 2026 bez výzvy nevzniká.');
        } catch (EldpValidationException $exception) {
            self::assertContains($exception->validationCode, ['eldp_standalone_statement_not_applicable', 'eldp_source_incomplete']);
        }
    }

    /** Dohoda: doba od vzniku účasti, měsíce bez účasti vyznačené. */
    public function testAgreementPeriodStartsWhenParticipationArises(): void
    {
        $revisions = $this->year([
            ['id' => 101, 'relation' => 'dpp', 'code' => 'T', 'start' => '2025-01-10', 'end' => '2025-06-30',
                'participates' => [3, 6], 'base' => 50_000, 'participating_base' => 1_200_000],
        ]);

        $periods = (new EldpAnnualStatementBuilder())->insurancePeriods(7, 101, 2025, $revisions, self::NO_PENSION);

        self::assertSame('2025-03-01', $periods['periods'][0]['from']);
        self::assertSame('2025-06-30', $periods['periods'][0]['to']);
        self::assertSame(61, $periods['insurance_days']);
        self::assertSame([4, 5], $periods['periods'][0]['months_without_insurance']);
    }

    /**
     * Plný starobní důchod: evidenční list se nevede (§ 38 odst. 1 věta druhá),
     * ale zaměstnanec je důchodově pojištěn a potvrzení dobu uvede.
     */
    public function testFullOldAgePensionDoesNotShortenTheInsurancePeriod(): void
    {
        $revisions = $this->year([
            ['id' => 101, 'relation' => 'employment', 'code' => '1', 'start' => '2020-01-01', 'end' => null],
        ]);
        // Výplata od prosince 2024: list se vede do prosince 2024 včetně, za rok
        // 2025 už žádný měsíc (Metodická pomůcka ELDP, příklad 8a).
        $pension = ['full_pension_paid_from' => '2024-12'] + self::NO_PENSION;

        $periods = (new EldpAnnualStatementBuilder())->insurancePeriods(7, 101, 2025, $revisions, $pension);

        self::assertSame(365, $periods['insurance_days']);
        $this->expectException(EldpValidationException::class);
        (new EldpAnnualStatementBuilder())->build(7, 101, 2025, $revisions, [
            'excluded_days_confirmed' => true,
            'pension_status' => $pension,
            'requested_by_authority' => false,
        ]);
    }

    /** Dělení řádku kódem D na dobu pojištění nemá vliv: jedna souvislá doba. */
    public function testPensionAgeSplitIsOneContinuousPeriod(): void
    {
        $revisions = $this->year([
            ['id' => 101, 'relation' => 'employment', 'code' => '1', 'start' => '2020-01-01', 'end' => null],
        ]);

        $periods = (new EldpAnnualStatementBuilder())->insurancePeriods(
            7,
            101,
            2025,
            $revisions,
            ['pension_age_reached_on' => '2025-04-01'] + self::NO_PENSION,
        );

        self::assertSame([['from' => '2025-01-01', 'to' => '2025-12-31', 'days' => 365, 'months_without_insurance' => []]], $periods['periods']);
    }
}
