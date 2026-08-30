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
use MyInvoice\Service\Payroll\Garnishment\GarnishmentStatus;
use MyInvoice\Service\Payroll\Garnishment\InsolvencyInstruction;
use MyInvoice\Service\Payroll\Garnishment\InsolvencyMode;
use MyInvoice\Service\Payroll\Garnishment\PensionEvidence;
use MyInvoice\Service\Payroll\Garnishment\SpousePensionEvidence;
use MyInvoice\Service\Payroll\Net\DeductionPriorityResolver;
use MyInvoice\Service\Payroll\Net\PayrollDeductionRequest;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use PHPUnit\Framework\TestCase;

/**
 * Pořadí dohody o srážkách ze mzdy vůči exekuci se řídí dnem doručení plátci
 * mzdy (§ 2045 odst. 2 obč. zák., § 148 odst. 2 zák. práce, § 280 odst. 5
 * o. s. ř.) — nález E-03.
 *
 * Do 8/2026 dostala dohoda VŽDY až zbytek po exekucích, protože kapacitu
 * dobrovolných srážek počítalo exekuční jádro, které o dohodách nevědělo.
 * Dohoda s vyplněným dnem doručení teď soutěží o obecnou nepřednostní část
 * společně s exekucemi; sráží ji ale pořád jen čistá mzda, takže se nikde
 * nezapočte dvakrát.
 */
final class VoluntaryAgreementPriorityTest extends TestCase
{
    /** Čistá mzda 40 000 Kč, bez vyživovaných osob. */
    private const NET_MINOR_UNITS = 4_000_000;

    /**
     * Kontrolní bod: bez dohody spolkne nepřednostní exekuce celou obecnou část
     * a na dohody nezbude nic. Všechna další čísla se poměřují proti tomuhle.
     */
    public function testBaselineWithoutAgreementLeavesNoVoluntaryCapacity(): void
    {
        $calculator = $this->calculator();
        $result = $calculator->calculate($this->input([
            $this->enforcement('exekuce', 10_000_00, '2026-03-01'),
        ]));

        self::assertSame(GarnishmentStatus::Supported, $result->status);
        // Nezabavitelná částka nechá zbytek 2 589 600; první třetina, tedy celá
        // obecná nepřednostní část, je 863 200 a spolkne ji exekuce.
        self::assertSame(863_200, $result->totalWithheldMinorUnits);
        self::assertSame(0, $calculator->voluntaryDeductionCapacity($result));
    }

    /**
     * Dohoda doručená DŘÍV než exekuční příkaz má lepší pořadí: uspokojí se
     * celá a exekuci zbude až zbytek obecné části.
     */
    public function testAgreementDeliveredBeforeEnforcementOutranksIt(): void
    {
        $calculator = $this->calculator();
        $result = $calculator->calculate($this->input(
            [$this->enforcement('exekuce', 10_000_00, '2026-03-01')],
            [$this->agreement('agreement:1', 3_000_00, '2026-01-15')],
        ));

        self::assertSame(GarnishmentStatus::Supported, $result->status);
        // Exekuci ubylo přesně to, co si vzala dohoda: 863 200 − 300 000.
        self::assertSame(563_200, $result->totalWithheldMinorUnits);
        self::assertSame(300_000, $calculator->voluntaryDeductionCapacity($result));
        // Přemostěná dohoda NENÍ exekuční srážkou a v rozvrhu se neobjeví.
        self::assertNull($result->allocationFor('agreement:1'));
        self::assertSame(
            ['exekuce'],
            array_map(
                static fn ($allocation): string => $allocation->claimId,
                $result->allocations,
            ),
        );
    }

    /**
     * Dohoda doručená AŽ PO exekučním příkazu se chová jako dosud — dostane jen
     * to, co exekuce nechala.
     */
    public function testAgreementDeliveredAfterEnforcementWaitsForTheRemainder(): void
    {
        $calculator = $this->calculator();
        $result = $calculator->calculate($this->input(
            [$this->enforcement('exekuce', 6_000_00, '2026-03-01')],
            [$this->agreement('agreement:1', 3_000_00, '2026-05-20')],
        ));

        self::assertSame(600_000, $result->totalWithheldMinorUnits);
        // Obecná část je 863 200; exekuce si vzala 600 000 a dohodě zbylo
        // 263 200 — přesně tolik, kolik by dostala i bez přemostění.
        self::assertSame(263_200, $calculator->voluntaryDeductionCapacity($result));

        $withoutBridge = $calculator->calculate($this->input(
            [$this->enforcement('exekuce', 6_000_00, '2026-03-01')],
        ));
        self::assertSame(
            $calculator->voluntaryDeductionCapacity($withoutBridge),
            $calculator->voluntaryDeductionCapacity($result),
        );
    }

    /**
     * Týž den doručení = totéž pořadí, a nestačí-li částka, uspokojí se poměrně
     * (§ 280 odst. 5 věta druhá o. s. ř.).
     */
    public function testSameDeliveryDaySharesProportionally(): void
    {
        $calculator = $this->calculator();
        $result = $calculator->calculate($this->input(
            [$this->enforcement('exekuce', 15_000_00, '2026-03-01')],
            [$this->agreement('agreement:1', 5_000_00, '2026-03-01')],
        ));

        // Obecná část 863 200 se dělí v poměru 1 500 000 : 500 000, tedy 3 : 1
        // — 647 400 exekuci a 215 800 dohodě.
        self::assertSame(647_400, $result->totalWithheldMinorUnits);
        self::assertSame(215_800, $calculator->voluntaryDeductionCapacity($result));
    }

    /**
     * Zpětná kompatibilita: dohoda bez zaznamenaného dne doručení nemá pořadí
     * čím doložit, takže se nepřemosťuje a chová se přesně jako do 8/2026.
     */
    public function testAgreementWithoutDeliveryDateBehavesLikeBefore(): void
    {
        $calculator = $this->calculator();
        $result = $calculator->calculate($this->input(
            [$this->enforcement('exekuce', 10_000_00, '2026-03-01')],
            [$this->agreement('agreement:1', 3_000_00, null)],
        ));

        self::assertSame(863_200, $result->totalWithheldMinorUnits);
        self::assertSame(0, $calculator->voluntaryDeductionCapacity($result));
    }

    /**
     * Dvojí započtení: dohoda vedená ZÁROVEŇ jako pohledávka rejstříku se sráží
     * a vyplácí exekučním jádrem. Přemostit ji podruhé nelze — ubrala by
     * kapacitu sama sobě a zaměstnanci by se srazila dvakrát.
     */
    public function testAgreementAlreadyInClaimRegisterIsNotBridgedTwice(): void
    {
        $calculator = $this->calculator();
        $registered = new DeductionClaim(
            'agreement:1',
            DeductionLegalBasis::VoluntaryAgreement,
            ClaimCategory::NonPriority,
            300_000,
            '2026-01-15',
            legalTitleVerified: false,
            orderOrNoticeDelivered: true,
            orderIssuedOn: null,
            priorityClassificationVerified: true,
            agreementVerified: true,
        );
        $result = $calculator->calculate($this->input(
            [$this->enforcement('exekuce', 10_000_00, '2026-03-01'), $registered],
            [$this->agreement('agreement:1', 3_000_00, '2026-01-15')],
        ));

        // Dohoda z rejstříku se srazila (300 000) a exekuci zbylo 563 200;
        // kapacita pro dobrovolné srážky je nula, protože obecná část je plná.
        self::assertSame(863_200, $result->totalWithheldMinorUnits);
        self::assertSame(300_000, $result->allocationFor('agreement:1')?->totalMinorUnits);
        self::assertSame(0, $calculator->voluntaryDeductionCapacity($result));
    }

    /**
     * Peníze se nesmějí ztratit ani vyrobit: součet exekuční srážky a kapacity
     * dobrovolných srážek je na pořadí nezávislý.
     */
    public function testTotalWithheldPlusCapacityIsIndependentOfOrder(): void
    {
        $calculator = $this->calculator();
        $totals = [];
        foreach (['2026-01-15', '2026-03-01', '2026-05-20', null] as $deliveredOn) {
            $result = $calculator->calculate($this->input(
                [$this->enforcement('exekuce', 10_000_00, '2026-03-01')],
                $deliveredOn === null
                    ? [$this->agreement('agreement:1', 3_000_00, null)]
                    : [$this->agreement('agreement:1', 3_000_00, $deliveredOn)],
            ));
            $totals[] = $result->totalWithheldMinorUnits
                + $calculator->voluntaryDeductionCapacity($result);
        }

        self::assertSame([863_200, 863_200, 863_200, 863_200], $totals);
    }

    /**
     * V oddlužení se dohoda neprovádí vůbec (§ 148 odst. 2 zák. práce ve
     * spojení s § 409 insolvenčního zákona) — přemostění na tom nesmí nic
     * změnit.
     */
    public function testApprovedInsolvencyIgnoresBridgedAgreements(): void
    {
        $calculator = $this->calculator();
        $result = $calculator->calculate($this->input(
            [],
            [$this->agreement('agreement:1', 3_000_00, '2026-01-15')],
            new InsolvencyInstruction(
                InsolvencyMode::ApprovedStandard,
                decisionVerified: true,
                recipientVerified: true,
                paymentInstructionId: 101,
                paymentInstructionHash: str_repeat('a', 64),
                employmentId: 202,
            ),
        ));

        self::assertTrue($result->insolvencyApplied);
        self::assertSame(0, $calculator->voluntaryDeductionCapacity($result));
    }

    /**
     * Kanonický tvar vstupu se hashuje a bajtově porovnává. Bez přemostěné
     * dohody proto klíč vůbec nesmí přibýt, s ní musí přežít round-trip.
     */
    public function testCanonicalSnapshotKeepsBridgedAgreements(): void
    {
        $withoutAgreements = $this->input([
            $this->enforcement('exekuce', 10_000_00, '2026-03-01'),
        ])->toCanonicalArray();
        self::assertArrayNotHasKey('voluntary_agreements', $withoutAgreements);

        $input = $this->input(
            [$this->enforcement('exekuce', 10_000_00, '2026-03-01')],
            [$this->agreement('agreement:1', 3_000_00, '2026-01-15')],
        );
        $restored = GarnishmentInput::fromCanonicalArray($input->toCanonicalArray());

        self::assertCount(1, $restored->voluntaryAgreements);
        self::assertSame('agreement:1', $restored->voluntaryAgreements[0]->id);
        self::assertSame('2026-01-15', $restored->voluntaryAgreements[0]->priorityDate);
        self::assertSame(
            $input->toCanonicalArray(),
            $restored->toCanonicalArray(),
        );
    }

    /** Přednostní pohledávkou dohoda o srážkách být nemůže. */
    public function testBridgedAgreementCannotBePriority(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->input([], [new DeductionClaim(
            'agreement:1',
            DeductionLegalBasis::VoluntaryAgreement,
            ClaimCategory::CurrentMaintenance,
            300_000,
            '2026-01-15',
            legalTitleVerified: false,
            orderOrNoticeDelivered: true,
            orderIssuedOn: null,
            priorityClassificationVerified: true,
            agreementVerified: true,
            maintenanceWeightMinorUnits: 300_000,
        )]);
    }

    /**
     * Rozvrh MEZI dohodami se řídí týmž pravidlem: dřív doručená dohoda
     * vyčerpá kapacitu dřív než ta s lepším ručně nastaveným `priority_no`.
     */
    public function testNetPayResolverPrefersEarlierDelivery(): void
    {
        $results = (new DeductionPriorityResolver())->resolve([
            new PayrollDeductionRequest('agreement:2', 10, 200_000, null, true, '2026-05-20'),
            new PayrollDeductionRequest('agreement:1', 99, 200_000, null, true, '2026-01-15'),
        ], 200_000);

        $applied = [];
        foreach ($results as $result) {
            $applied[$result->deductionReference] = $result->appliedMinorUnits;
        }

        self::assertSame(['agreement:1' => 200_000, 'agreement:2' => 0], $applied);
    }

    private function calculator(): GarnishmentCalculator
    {
        return new GarnishmentCalculator(CzechPayrollRulesets2026::provider());
    }

    /**
     * @param list<DeductionClaim> $claims
     * @param list<DeductionClaim> $agreements
     */
    private function input(
        array $claims,
        array $agreements = [],
        ?InsolvencyInstruction $insolvency = null,
    ): GarnishmentInput {
        $income = (new GarnishableIncomeResolver())->resolve([
            new GarnishableIncomeItem(
                'net-wage',
                GarnishableIncomeKind::Wage,
                self::NET_MINOR_UNITS,
                'payer-main',
            ),
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
            $insolvency ?? InsolvencyInstruction::none(),
            false,
            true,
            SpousePensionEvidence::NotDocumented,
            $agreements,
        );
    }

    private function enforcement(
        string $id,
        int $outstandingMinorUnits,
        string $priorityDate,
    ): DeductionClaim {
        return new DeductionClaim(
            $id,
            DeductionLegalBasis::Statutory,
            ClaimCategory::NonPriority,
            $outstandingMinorUnits,
            $priorityDate,
            legalTitleVerified: true,
            orderOrNoticeDelivered: true,
            orderIssuedOn: '2021-01-01',
            priorityClassificationVerified: true,
            dueMonetaryClaimVerified: true,
            enforcementOrderId: $id,
        );
    }

    private function agreement(
        string $id,
        int $outstandingMinorUnits,
        ?string $deliveredOn,
    ): DeductionClaim {
        return new DeductionClaim(
            $id,
            DeductionLegalBasis::VoluntaryAgreement,
            ClaimCategory::NonPriority,
            $outstandingMinorUnits,
            $deliveredOn,
            legalTitleVerified: false,
            orderOrNoticeDelivered: true,
            orderIssuedOn: null,
            priorityClassificationVerified: true,
            agreementVerified: true,
        );
    }
}
