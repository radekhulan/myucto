<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Garnishment;

use MyInvoice\Service\Payroll\Garnishment\ClaimCategory;
use MyInvoice\Service\Payroll\Garnishment\DeductionClaim;
use MyInvoice\Service\Payroll\Garnishment\DeductionLegalBasis;
use MyInvoice\Service\Payroll\Garnishment\GarnishableIncomeItem;
use MyInvoice\Service\Payroll\Garnishment\GarnishableIncomeKind;
use MyInvoice\Service\Payroll\Garnishment\GarnishableIncomeResolver;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentCalculator;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentInput;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentResult;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentStatus;
use MyInvoice\Service\Payroll\Garnishment\InsolvencyInstruction;
use MyInvoice\Service\Payroll\Garnishment\InsolvencyMode;
use MyInvoice\Service\Payroll\Garnishment\PensionEvidence;
use MyInvoice\Service\Payroll\Garnishment\SpousePensionEvidence;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use PHPUnit\Framework\TestCase;

/**
 * Zahájené insolvenční řízení (režim `alert_only`): srážky se provádějí
 * v rozsahu dosavadních exekucí a deponují se (§ 109 odst. 1 písm. c) IZ,
 * R 4/2020). Dřív režim zablokoval celý běh ruční kontrolou a nesrazilo se nic.
 *
 * Čísla vycházejí z nezabavitelných částek 2026 (nař. vlády č. 595/2006 Sb.
 * ve znění pro rok 2026): nezabavitelná částka 14 102 Kč, hranice plně
 * zabavitelné části 28 203 Kč.
 */
final class InsolvencyCommencedDepositTest extends TestCase
{
    private const NET = 4_000_000;

    public function testCommencedInsolvencyWithholdsLikeTheEnforcementButFlagsTheDeposit(): void
    {
        $claims = [
            $this->claim('claim-a', ClaimCategory::NonPriority, 10_000_000, '2026-01-10'),
        ];
        $plain = $this->calculate($claims, InsolvencyInstruction::none());
        $commenced = $this->calculate($claims, $this->commenced());

        self::assertSame(GarnishmentStatus::Supported, $commenced->status, implode(', ', $commenced->issues));
        // 40 000 − 14 102 = 25 898 → třetiny z 25 896, třetina 8 632 Kč.
        self::assertSame(863_200, $commenced->thirdMinorUnits);
        self::assertSame($plain->totalWithheldMinorUnits, $commenced->totalWithheldMinorUnits);
        self::assertSame(863_200, $commenced->totalWithheldMinorUnits);
        self::assertSame($plain->employerFlatFeeMinorUnits, $commenced->employerFlatFeeMinorUnits);
        self::assertSame(
            $plain->allocationFor('claim-a')?->totalMinorUnits,
            $commenced->allocationFor('claim-a')?->totalMinorUnits,
        );
        self::assertNull($commenced->allocationFor('insolvency-administrator'));
        self::assertTrue($commenced->insolvencyApplied);
        self::assertContains(
            'insolvency_commenced_deposit',
            array_column($commenced->roundingTrace, 'step'),
        );
    }

    public function testPriorityAndNonPriorityClaimsKeepTheirPoolsWithoutManualReview(): void
    {
        $claims = [
            $this->claim('claim-a', ClaimCategory::NonPriority, 10_000_000, '2026-01-10'),
            $this->claim('claim-b', ClaimCategory::OtherPriority, 10_000_000, '2026-02-10'),
        ];
        $plain = $this->calculate($claims, InsolvencyInstruction::none());
        $commenced = $this->calculate($claims, $this->commenced());

        self::assertSame(GarnishmentStatus::Supported, $commenced->status, implode(', ', $commenced->issues));
        self::assertSame(1_726_400, $commenced->totalWithheldMinorUnits);
        foreach (['claim-a', 'claim-b'] as $claimId) {
            self::assertSame(
                $plain->allocationFor($claimId)?->firstPoolMinorUnits,
                $commenced->allocationFor($claimId)?->firstPoolMinorUnits,
            );
            self::assertSame(
                $plain->allocationFor($claimId)?->secondPoolMinorUnits,
                $commenced->allocationFor($claimId)?->secondPoolMinorUnits,
            );
        }
    }

    public function testVoluntaryAgreementIsNotExercisedAfterCommencement(): void
    {
        $claims = [
            $this->claim('claim-a', ClaimCategory::NonPriority, 800_000, '2026-02-10'),
        ];
        $agreement = new DeductionClaim(
            'agreement:1',
            DeductionLegalBasis::VoluntaryAgreement,
            ClaimCategory::NonPriority,
            500_000,
            '2026-01-05',
            legalTitleVerified: false,
            orderOrNoticeDelivered: true,
            orderIssuedOn: null,
            priorityClassificationVerified: true,
            agreementVerified: true,
        );
        $calculator = new GarnishmentCalculator(CzechPayrollRulesets2026::provider());

        $result = $calculator->calculate($this->input($claims, $this->commenced(), [$agreement]));

        self::assertSame(GarnishmentStatus::Supported, $result->status);
        // Dohoda doručená dřív by jinak ubrala exekuci první místo a srazilo
        // by se jen 3 632 Kč; celou kapacitu 8 632 Kč pohledávka nepotřebuje.
        // K pohledávce 8 000 Kč se sráží náhrada plátce mzdy 50 Kč, kterou
        // hradí povinný (§ 270 odst. 2 o. s. ř.).
        self::assertSame(805_000, $result->totalWithheldMinorUnits);
        self::assertSame(800_000, $result->allocationFor('claim-a')?->totalMinorUnits);
        self::assertSame(0, $calculator->voluntaryDeductionCapacity($result));
    }

    public function testUnverifiedCommencementStillStopsTheRun(): void
    {
        $result = $this->calculate(
            [$this->claim('claim-a', ClaimCategory::NonPriority, 10_000_000, '2026-01-10')],
            new InsolvencyInstruction(InsolvencyMode::AlertOnly, false, false),
        );

        self::assertSame(GarnishmentStatus::ManualReview, $result->status);
        self::assertContains('insolvency_decision_not_verified', $result->issues);
        self::assertNotContains('insolvency_recipient_not_verified', $result->issues);
        self::assertSame(0, $result->totalWithheldMinorUnits);
    }

    private function commenced(): InsolvencyInstruction
    {
        return new InsolvencyInstruction(
            InsolvencyMode::AlertOnly,
            decisionVerified: true,
            recipientVerified: false,
        );
    }

    /** @param list<DeductionClaim> $claims */
    private function calculate(array $claims, InsolvencyInstruction $insolvency): GarnishmentResult
    {
        return (new GarnishmentCalculator(CzechPayrollRulesets2026::provider()))
            ->calculate($this->input($claims, $insolvency));
    }

    /**
     * @param list<DeductionClaim> $claims
     * @param list<DeductionClaim> $agreements
     */
    private function input(
        array $claims,
        InsolvencyInstruction $insolvency,
        array $agreements = [],
    ): GarnishmentInput {
        $income = (new GarnishableIncomeResolver())->resolve([
            new GarnishableIncomeItem('net-wage', GarnishableIncomeKind::Wage, self::NET, 'payer-main'),
        ], evidenceComplete: true);

        return new GarnishmentInput(
            '2026-06',
            '2026-07-15',
            $income,
            $claims,
            0,
            true,
            false,
            true,
            PensionEvidence::None,
            false,
            null,
            $insolvency,
            false,
            true,
            SpousePensionEvidence::NotDocumented,
            $agreements,
        );
    }

    private function claim(
        string $id,
        ClaimCategory $category,
        int $outstanding,
        string $deliveredOn,
    ): DeductionClaim {
        return new DeductionClaim(
            $id,
            DeductionLegalBasis::Statutory,
            $category,
            $outstanding,
            $deliveredOn,
            legalTitleVerified: true,
            orderOrNoticeDelivered: true,
            orderIssuedOn: '2025-12-01',
            priorityClassificationVerified: true,
            dueMonetaryClaimVerified: true,
            enforcementOrderId: $id,
        );
    }
}
