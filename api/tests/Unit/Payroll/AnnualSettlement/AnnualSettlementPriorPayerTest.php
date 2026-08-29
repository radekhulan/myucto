<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\AnnualSettlement;

use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementBlocker;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementCreditMonths;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementInput;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementResult;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualTaxRates;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualTaxSettlementCalculator;
use MyInvoice\Service\Payroll\IncomeTax\ExternalEmployerTaxCertificate;
use MyInvoice\Service\Payroll\IncomeTax\TaxCreditKind;
use MyInvoice\Service\Payroll\IncomeTax\TaxEvidenceStatus;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use PHPUnit\Framework\TestCase;

/**
 * W28 — potvrzení od předchozích plátců: lhůta, souběh a rozhodný příjem.
 *
 * V-23  § 35c odst. 4 — rozhodný příjem pro roční bonus se měřil na dvou
 *       stranách různě (vlastní zálohový základ vs. ř. 1 potvrzení).
 * V-24  § 38ch odst. 3 věta druhá — `received_on` se neporovnalo s 15. 2.
 * V-25  § 38ch odst. 1 / § 38g odst. 2 — souběh plátců nešlo detekovat,
 *       protože potvrzení nenesla období od–do.
 */
final class AnnualSettlementPriorPayerTest extends TestCase
{
    private const YEAR = 2026;

    /**
     * V-24. Doklad převzatý 16. 2. je opožděný — § 38ch odst. 3 věta druhá:
     * „Plátce daně roční zúčtování záloh a daňového zvýhodnění neprovede,
     * pokud poplatník tyto doklady nepředloží plátci daně do 15. února po
     * uplynutí zdaňovacího období."
     */
    public function testCertificateReceivedAfterFifteenthOfFebruaryBlocksTheSettlement(): void
    {
        $result = $this->calculate([$this->certificate(receivedOn: '2027-02-16')]);

        self::assertFalse($result->performed);
        self::assertContains(
            AnnualSettlementBlocker::PriorEmployerDocumentsLate->value,
            $this->blockerCodes($result),
        );
    }

    /** Poslední den lhůty je ještě včas — nerovnost je neostrá. */
    public function testCertificateReceivedOnTheDeadlineIsStillInTime(): void
    {
        $result = $this->calculate([$this->certificate(receivedOn: '2027-02-15')]);

        self::assertNotContains(
            AnnualSettlementBlocker::PriorEmployerDocumentsLate->value,
            $this->blockerCodes($result),
        );
    }

    /**
     * V-25. Dva plátci se překrývajícím se obdobím = SOUBĚH, ne „postupně“.
     * § 38g odst. 2 pak výjimku z povinnosti podat přiznání nedává a plátce
     * podle § 38ch odst. 1 věty druhé zúčtování provést nesmí.
     */
    public function testOverlappingEmploymentPeriodsForceTheTaxReturn(): void
    {
        $result = $this->calculate([
            $this->certificate(
                reference: 'prvni-platce',
                employmentFrom: '2026-01-01',
                employmentTo: '2026-07-31',
            ),
            $this->certificate(
                reference: 'druhy-platce',
                employmentFrom: '2026-06-01',
                employmentTo: '2026-12-31',
            ),
        ]);

        self::assertFalse($result->performed);
        self::assertContains(
            AnnualSettlementBlocker::MustFileTaxReturn->value,
            $this->blockerCodes($result),
        );
    }

    /** Navazující období jsou „postupně“ — přesně to, co § 38ch odst. 1 dovoluje. */
    public function testConsecutiveEmploymentPeriodsAreFine(): void
    {
        $result = $this->calculate([
            $this->certificate(
                reference: 'prvni-platce',
                employmentFrom: '2026-01-01',
                employmentTo: '2026-05-31',
            ),
            $this->certificate(
                reference: 'druhy-platce',
                employmentFrom: '2026-06-01',
                employmentTo: '2026-09-30',
            ),
        ]);

        self::assertNotContains(
            AnnualSettlementBlocker::MustFileTaxReturn->value,
            $this->blockerCodes($result),
        );
    }

    /**
     * Chybějící období znamená „nevíme“, ne „souběh nebyl“ — blokátor se
     * nezvedá, aby historicky uložená potvrzení bez období neshodila zúčtování
     * všem. Doloženo přímo na value objectu, protože je to ta hranice, o kterou
     * jde.
     */
    public function testUnknownPeriodIsNotTreatedAsProofOfSequence(): void
    {
        $withPeriod = $this->certificate(
            reference: 'a',
            employmentFrom: '2026-01-01',
            employmentTo: '2026-12-31',
        );
        $withoutPeriod = $this->certificate(reference: 'b');

        self::assertNull($withPeriod->overlapsPeriodOf($withoutPeriod, self::YEAR));
        self::assertNull($withoutPeriod->overlapsPeriodOf($withPeriod, self::YEAR));
        self::assertTrue($withPeriod->overlapsPeriodOf($withPeriod, self::YEAR));
    }

    /** Otevřený konec se čte jako „do konce zdaňovacího období“. */
    public function testOpenEndedPeriodRunsToTheEndOfTheTaxYear(): void
    {
        $running = $this->certificate(
            reference: 'a',
            employmentFrom: '2026-03-01',
            employmentTo: null,
        );
        $later = $this->certificate(
            reference: 'b',
            employmentFrom: '2026-11-01',
            employmentTo: '2026-12-31',
        );

        self::assertTrue($running->overlapsPeriodOf($later, self::YEAR));
    }

    /**
     * V-23. Rozhodný příjem pro roční bonus se na obou stranách musí měřit
     * stejně. § 35c odst. 4 vylučuje z testu příjmy zdaněné srážkou — ty jsou
     * v ř. 1 potvrzení (hrubý příjem) obsažené, v zálohovém základu (ř. 5) ne.
     *
     * Potvrzení tu nese hrubý příjem 200 000 Kč, ale zálohový základ jen
     * 100 000 Kč; rozdíl je srážkově zdaněná dohoda. S vlastním základem
     * 500 000 Kč vyjde rozhodný příjem 600 000 Kč, ne 700 000 Kč.
     */
    public function testBonusQualifyingIncomeUsesTheAdvanceBaseOnBothSides(): void
    {
        $result = $this->calculate(
            [$this->certificate(
                grossIncomeMinorUnits: 20_000_000,
                advanceBaseMinorUnits: 10_000_000,
                advanceTaxMinorUnits: 0,
            )],
            advanceBase: 50_000_000,
        );

        self::assertTrue($result->performed, implode(',', $this->blockerCodes($result)));
        self::assertSame(
            60_000_000,
            $result->trace['total_bonus_qualifying_income_minor_units'] ?? null,
        );
    }

    /**
     * @param list<ExternalEmployerTaxCertificate> $certificates
     */
    private function calculate(
        array $certificates,
        int $advanceBase = 50_000_000,
    ): AnnualSettlementResult {
        $rates = AnnualTaxRates::forRuleset(
            CzechPayrollRulesets2026::provider()->forDate(
                PayrollRulesetDomain::IncomeTax,
                sprintf('%04d-12-01', self::YEAR),
            ),
        );

        return (new AnnualTaxSettlementCalculator())->calculate(
            new AnnualSettlementInput(
                self::YEAR,
                12,
                $advanceBase,
                0,
                0,
                0,
                0,
                $advanceBase,
                0,
                0,
                [new AnnualSettlementCreditMonths(TaxCreditKind::Taxpayer, 12)],
                [],
                $certificates,
            ),
            $rates,
        );
    }

    private function certificate(
        string $reference = 'synthetic-certificate',
        ?string $receivedOn = '2027-02-01',
        ?string $employmentFrom = null,
        ?string $employmentTo = null,
        int $grossIncomeMinorUnits = 10_000_000,
        int $advanceBaseMinorUnits = 10_000_000,
        int $advanceTaxMinorUnits = 0,
    ): ExternalEmployerTaxCertificate {
        return new ExternalEmployerTaxCertificate(
            $reference,
            $advanceBaseMinorUnits,
            $advanceTaxMinorUnits,
            TaxEvidenceStatus::Verified,
            'synthetic-evidence',
            $grossIncomeMinorUnits,
            0,
            0,
            0,
            'Předchozí plátce',
            'CZ00000019',
            $receivedOn,
            $employmentFrom,
            $employmentTo,
        );
    }

    /** @return list<string> */
    private function blockerCodes(AnnualSettlementResult $result): array
    {
        return array_map(
            static fn (AnnualSettlementBlocker $blocker): string => $blocker->value,
            $result->blockers,
        );
    }
}
