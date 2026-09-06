<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Garnishment;

use MyInvoice\Service\Payroll\Absence\LeaveCompensationCalculator;
use MyInvoice\Service\Payroll\Absence\LeaveEntitlementCalculator;
use MyInvoice\Service\Payroll\Absence\SicknessCompensationCalculator;
use MyInvoice\Service\Payroll\Garnishment\ClaimCategory;
use MyInvoice\Service\Payroll\Garnishment\DeductionClaim;
use MyInvoice\Service\Payroll\Garnishment\DeductionLegalBasis;
use MyInvoice\Service\Payroll\Garnishment\GarnishableIncomeItem;
use MyInvoice\Service\Payroll\Garnishment\GarnishableIncomeKind;
use MyInvoice\Service\Payroll\Garnishment\GarnishableIncomeResolver;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentCalculator;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentInput;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentStatus;
use MyInvoice\Service\Payroll\Garnishment\InsolvencyInstruction;
use MyInvoice\Service\Payroll\Garnishment\InsolvencyMode;
use MyInvoice\Service\Payroll\Garnishment\PensionEvidence;
use MyInvoice\Service\Payroll\Garnishment\SpousePensionEvidence;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use PHPUnit\Framework\TestCase;

/**
 * AUDIT MZDOVÉHO MODULU (private/MZDY-AUDIT.md) — exekuce, insolvence,
 * náhrada mzdy při nemoci a dovolená proti ručnímu výpočtu z parametrů
 * připnuté sady 2026 (nezabavitelná částka na povinného 14 101,50 Kč,
 * čtvrtina 3 525,375 Kč, hranice plně zabavitelného zbytku 31 521 Kč).
 *
 * POZOR: hodnoty sady 2026 (85 % místo 2/3, faktor 1,9 místo 1,5, normativ
 * 9 430 + energie 2 300) audit neověřil proti Sbírce zákonů — test bere
 * parametry sady jako dané a ověřuje jen aritmetiku nad nimi.
 */
final class GarnishmentAuditManualCasesTest extends TestCase
{
    /**
     * Čistá mzda 30 000, 1 dítě, nepřednostní pohledávka.
     * Nezabavitelné: 14 101,50 + 3 525,375 = 17 626,875 → 17 627.
     * Zbytek 12 373 → základ dělitelný třemi 12 372 → třetina 4 124.
     * Sráží se 1 třetina = 4 124; k výplatě 25 876.
     */
    public function testNonPriorityClaimWithOneDependant(): void
    {
        $result = $this->calculate(3_000_000, [$this->claim('c1', ClaimCategory::NonPriority)], dependants: 1);

        self::assertSame(GarnishmentStatus::Supported, $result->status, implode(',', $result->issues));
        self::assertSame(1_762_700, $result->protectedAmountMinorUnits);
        self::assertSame(412_400, $result->thirdMinorUnits);
        self::assertSame(0, $result->fullyAttachableExcessMinorUnits);
        self::assertSame(412_400, $result->totalWithheldMinorUnits);
        self::assertSame(2_587_600, $result->employeePaymentMinorUnits);
    }

    /** Přednostní (výživné): dvě třetiny = 8 248. */
    public function testPriorityMaintenanceClaimTakesTwoThirds(): void
    {
        $result = $this->calculate(
            3_000_000,
            [$this->claim('c1', ClaimCategory::CurrentMaintenance, maintenanceWeightMinorUnits: 500_000)],
            dependants: 1,
        );

        self::assertSame(GarnishmentStatus::Supported, $result->status, implode(',', $result->issues));
        self::assertSame(824_800, $result->totalWithheldMinorUnits);
    }

    /**
     * Čistá mzda 60 000, bez vyživovaných, nepřednostní.
     * Zbytek 60 000 − 14 102 = 45 898; nad hranicí 31 521 je 14 377 plně zabavitelných;
     * třetiny z 31 521 → 10 507 každá. Nepřednostní: 10 507 + 14 377 = 24 884.
     */
    public function testHighIncomeFullyAttachableExcessGoesToNonPriorityClaimToo(): void
    {
        $result = $this->calculate(6_000_000, [$this->claim('c1', ClaimCategory::NonPriority)]);

        self::assertSame(GarnishmentStatus::Supported, $result->status, implode(',', $result->issues));
        self::assertSame(1_410_200, $result->protectedAmountMinorUnits);
        self::assertSame(1_050_700, $result->thirdMinorUnits);
        self::assertSame(1_437_700, $result->fullyAttachableExcessMinorUnits);
        self::assertSame(2_488_400, $result->totalWithheldMinorUnits);
    }

    /**
     * Schválené oddlužení splátkovým kalendářem: sráží se jako pro přednostní
     * pohledávku (2 třetiny + plně zabavitelný zbytek) na účet správce.
     * 30 000, 1 dítě → 8 248.
     */
    public function testApprovedInsolvencyWithholdsTwoThirdsToTheAdministrator(): void
    {
        $result = $this->calculate(
            3_000_000,
            [],
            dependants: 1,
            insolvency: new InsolvencyInstruction(
                InsolvencyMode::ApprovedStandard,
                decisionVerified: true,
                recipientVerified: true,
                paymentInstructionId: 101,
                paymentInstructionHash: str_repeat('a', 64),
                employmentId: 202,
            ),
        );

        self::assertSame(GarnishmentStatus::Supported, $result->status, implode(',', $result->issues));
        self::assertTrue($result->insolvencyApplied);
        self::assertSame(824_800, $result->totalWithheldMinorUnits);
        self::assertSame(824_800, $result->allocationFor('insolvency-administrator')?->totalMinorUnits);
    }

    /** Souběh exekuce a oddlužení je fail-closed (ruční posouzení), nic se nesrazí. */
    public function testEnforcementConcurrentWithInsolvencyIsManualReview(): void
    {
        $result = $this->calculate(
            3_000_000,
            [$this->claim('c1', ClaimCategory::NonPriority)],
            insolvency: new InsolvencyInstruction(
                InsolvencyMode::ApprovedStandard,
                decisionVerified: true,
                recipientVerified: true,
                paymentInstructionId: 101,
                paymentInstructionHash: str_repeat('a', 64),
                employmentId: 202,
            ),
        );

        self::assertSame(GarnishmentStatus::ManualReview, $result->status);
        self::assertContains('concurrent_enforcement_with_insolvency_requires_manual_review', $result->issues);
        self::assertSame(0, $result->totalWithheldMinorUnits);
    }

    /**
     * Náhrada mzdy při DPN (§ 192 ZP), průměr 300 Kč/h, 5 směn × 8 h v prvních 14 dnech.
     * Redukce: 285,78 × 0,9 = 257,202 + (300 − 285,78) × 0,6 = 8,532 → 265,734 Kč/h.
     * 60 % = 159,4404 Kč/h × 40 h = 6 377,616 → nahoru 6 378 Kč.
     */
    public function testSicknessCompensationMatchesManualReductionAndRounding(): void
    {
        $segments = [];
        for ($day = 6; $day <= 10; $day++) {
            $segments[] = [
                'shift_id' => $day,
                'local_date' => sprintf('2026-07-%02d', $day),
                'planned_minutes' => 480,
                'eligible_minutes' => 480,
            ];
        }
        $result = (new SicknessCompensationCalculator(CzechPayrollRulesets2026::provider()))
            ->calculate('2026-07-06', 30_000, $segments);

        self::assertSame(637_800, $result->compensationMinor);
    }

    /**
     * Dovolená: průměr 300 Kč/h × 16 h = 4 800 Kč (bez redukce).
     * Nárok DPP s fikcí 20 h/týden za celý rok při 4 týdnech: 80 h.
     * Nárok HPP 40 h/týden, 4 týdny, 26 odpracovaných týdnů: 40 × 4 × 26 / 52 = 80 h.
     */
    public function testLeaveCompensationAndEntitlementMatchManualCalculation(): void
    {
        $compensation = LeaveCompensationCalculator::calculate(30_000, [
            ['shift_id' => 1, 'local_date' => '2026-07-13', 'planned_minutes' => 480, 'eligible_minutes' => 480],
            ['shift_id' => 2, 'local_date' => '2026-07-14', 'planned_minutes' => 480, 'eligible_minutes' => 480],
        ]);
        self::assertSame(['2026-07-01' => 480_000], $compensation->amountsByPeriod);

        $calculator = new LeaveEntitlementCalculator(CzechPayrollRulesets2026::provider());
        $dpp = $calculator->calculate('2026-01-01', 'dpp', 2_400, 4, 365, 62_400, 'Audit: DPP fikce 20 h.');
        self::assertSame(4_800, $dpp->entitlementMinutes);
        $halfYear = $calculator->calculate('2026-01-01', 'employment', 2_400, 4, 182, 62_400, 'Audit: půl roku.');
        self::assertSame(4_800, $halfYear->entitlementMinutes);
    }

    /** @param list<DeductionClaim> $claims */
    private function calculate(
        int $netMinorUnits,
        array $claims,
        int $dependants = 0,
        ?InsolvencyInstruction $insolvency = null,
    ): \MyInvoice\Service\Payroll\Garnishment\GarnishmentResult {
        $income = (new GarnishableIncomeResolver())->resolve([
            new GarnishableIncomeItem('net-wage', GarnishableIncomeKind::Wage, $netMinorUnits, 'payer-main'),
        ], evidenceComplete: true);

        return (new GarnishmentCalculator(CzechPayrollRulesets2026::provider()))->calculate(new GarnishmentInput(
            '2026-06',
            '2026-07-15',
            $income,
            $claims,
            $dependants,
            true,
            false,
            true,
            PensionEvidence::None,
            false,
            null,
            $insolvency ?? InsolvencyInstruction::none(),
            false,
            true,
            SpousePensionEvidence::NotDocumented,
        ));
    }

    private function claim(
        string $id,
        ClaimCategory $category,
        ?int $maintenanceWeightMinorUnits = null,
    ): DeductionClaim {
        return new DeductionClaim(
            $id,
            DeductionLegalBasis::Statutory,
            $category,
            10_000_000,
            '2026-01-01',
            legalTitleVerified: true,
            orderOrNoticeDelivered: true,
            orderIssuedOn: '2022-01-01',
            priorityClassificationVerified: true,
            maintenanceWeightMinorUnits: $maintenanceWeightMinorUnits,
            dueMonetaryClaimVerified: true,
            enforcementOrderId: $id,
        );
    }
}
