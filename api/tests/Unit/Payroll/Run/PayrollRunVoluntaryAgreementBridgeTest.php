<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Run;

use MyInvoice\Service\Payroll\Garnishment\ClaimCategory;
use MyInvoice\Service\Payroll\Garnishment\DeductionClaim;
use MyInvoice\Service\Payroll\Garnishment\DeductionLegalBasis;
use MyInvoice\Service\Payroll\Garnishment\EnforcementPersonMonthEvidence;
use MyInvoice\Service\Payroll\Garnishment\EnforcementPersonMonthRequest;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentCalculator;
use MyInvoice\Service\Payroll\Garnishment\InsolvencyInstruction;
use MyInvoice\Service\Payroll\Garnishment\PayrollGarnishmentCalculation;
use MyInvoice\Service\Payroll\Garnishment\PayrollGarnishmentPort;
use MyInvoice\Service\Payroll\Garnishment\PayrollGarnishmentRunIntegration;
use MyInvoice\Service\Payroll\Garnishment\PayrollGarnishmentSnapshotWriter;
use MyInvoice\Service\Payroll\Garnishment\PensionEvidence;
use MyInvoice\Service\Payroll\Net\NetRelationshipIncome;
use MyInvoice\Service\Payroll\Net\PayrollDeductionRequest;
use MyInvoice\Service\Payroll\Net\PayrollNetCalculator;
use MyInvoice\Service\Payroll\Net\PayrollNetInput;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Run\PayrollRunGarnishmentProcessor;
use PHPUnit\Framework\TestCase;

/**
 * Nález E-03: dohoda o srážkách ze mzdy doručená plátci DŘÍV než exekuční
 * příkaz má podle § 148 odst. 2 zákoníku práce ve spojení s § 280 odst. 5
 * o. s. ř. lepší pořadí a musí se uspokojit před ní.
 *
 * Sestavovač mzdového běhu ji proto přemosťuje do rozvrhu exekučního jádra
 * (jen kvůli pořadí — srazí ji až čistá mzda). Doplňuje
 * {@see PayrollRunGarnishmentOrderTest}, který drží opačný, dosavadní případ:
 * dohoda bez pořadí dostane až zbytek po exekucích.
 */
final class PayrollRunVoluntaryAgreementBridgeTest extends TestCase
{
    private const EMPLOYEE_ID = 11;
    private const NET_BEFORE_DEDUCTIONS = 3_000_000;

    /**
     * Kontrolní bod bez dohody: exekuce spolkne celou obecnou část 529 900
     * (z toho 5 000 paušál plátce mzdy) a na dohody nezbude nic.
     */
    public function testBaselineLeavesNothingForAgreements(): void
    {
        self::assertSame(
            [self::EMPLOYEE_ID => 0],
            $this->capacities($this->snapshot()),
        );
    }

    /**
     * Dohoda doručená 1. 1. 2026, exekuční příkaz až 1. 2. 2026 — dohoda si
     * z obecné části vezme svoje a exekuci zbude až zbytek.
     */
    public function testEarlierAgreementTakesPrecedenceOverEnforcement(): void
    {
        $snapshot = $this->snapshot([
            $this->agreement(2_000_00, '2026-01-01'),
        ]);

        self::assertSame(
            [self::EMPLOYEE_ID => 200_000],
            $this->capacities($snapshot),
        );
    }

    /**
     * Dohoda doručená až po exekučním příkazu se chová jako dosud — nezbude
     * na ni nic.
     */
    public function testLaterAgreementStillWaitsForTheRemainder(): void
    {
        $snapshot = $this->snapshot([
            $this->agreement(2_000_00, '2026-03-01'),
        ]);

        self::assertSame(
            [self::EMPLOYEE_ID => 0],
            $this->capacities($snapshot),
        );
    }

    /**
     * Zpětná kompatibilita: dohoda zaevidovaná dřív, než se den doručení
     * ukládal, se nepřemosťuje a zůstává jí dosavadní chování.
     */
    public function testAgreementWithoutDeliveryDateKeepsTheOldBehaviour(): void
    {
        $snapshot = $this->snapshot([$this->agreement(2_000_00, null)]);

        self::assertSame(
            [self::EMPLOYEE_ID => 0],
            $this->capacities($snapshot),
        );
    }

    /**
     * Vyčerpaný limit dohody se nepřemosťuje: dohoda, která už má sraženo
     * všechno, nemá o co soutěžit a exekuci nesmí ubrat ani korunu.
     */
    public function testExhaustedAgreementDoesNotReserveAnything(): void
    {
        $snapshot = $this->snapshot([
            $this->agreement(2_000_00, '2026-01-01', totalLimit: 500_000, withheld: 500_000),
        ]);

        self::assertSame(
            [self::EMPLOYEE_ID => 0],
            $this->capacities($snapshot),
        );
    }

    /**
     * Zbývající limit strop nároku snižuje: dohoda si vezme jen to, co jí do
     * limitu chybí, zbytek obecné části připadne exekuci.
     */
    public function testRemainingLimitCapsTheReservation(): void
    {
        $snapshot = $this->snapshot([
            $this->agreement(2_000_00, '2026-01-01', totalLimit: 500_000, withheld: 425_000),
        ]);

        self::assertSame(
            [self::EMPLOYEE_ID => 75_000],
            $this->capacities($snapshot),
        );
    }

    /**
     * Celé pořadí v jednom průchodu: dohoda si vezme 2 000 Kč, exekuce
     * 3 299 Kč, a zaměstnanci se každá částka srazí PRÁVĚ JEDNOU.
     */
    public function testWholeOrderDeductsTheAgreementExactlyOnce(): void
    {
        $processor = $this->processor();
        $snapshot = $this->snapshot([$this->agreement(2_000_00, '2026-01-01')]);
        $base = $this->baseResult();

        $capacities = $processor->voluntaryDeductionCapacities(
            $snapshot,
            $base,
            [self::EMPLOYEE_ID => self::NET_BEFORE_DEDUCTIONS],
        );
        self::assertSame(200_000, $capacities[self::EMPLOYEE_ID]);

        $net = (new PayrollNetCalculator())->calculate(new PayrollNetInput(
            personReference: 'employee:' . self::EMPLOYEE_ID,
            relationships: [new NetRelationshipIncome('employment:101', 4_000_000, 0)],
            employeeSocialMinorUnits: 700_000,
            employeeHealthMinorUnits: 300_000,
            advanceTaxMinorUnits: 0,
            withholdingTaxMinorUnits: 0,
            taxBonusMinorUnits: 0,
            correctionMinorUnits: 0,
            voluntaryDeductionCapacityMinorUnits: $capacities[self::EMPLOYEE_ID],
            deductions: [new PayrollDeductionRequest(
                'agreement:7',
                10,
                200_000,
                null,
                true,
                '2026-01-01',
            )],
        ));

        // Dohoda se uspokojila celá — přesně to, co jí rozvrh pořadí vyhradil.
        self::assertSame(200_000, $net->deductedMinorUnits);
        self::assertSame(0, $net->deductions[0]->unappliedMinorUnits);

        $base['statutory'] = ['status' => 'calculated'];
        $base['people'][0]['statutory'] = [
            'person_reference' => 'employee:' . self::EMPLOYEE_ID,
            'status' => 'calculated',
            'net_payable_minor_units' => $net->netPayableMinorUnits,
            'net_pay' => $net->jsonSerialize(),
        ];
        $person = $processor->calculate($snapshot, $base)['people'][0];
        $enforcement = $person['enforcement']['result'];

        // Exekuci zbylo 529 900 − 200 000; přemostěná dohoda mezi exekučními
        // srážkami NENÍ, jinak by ji zaměstnanec zaplatil dvakrát.
        self::assertSame(329_900, $enforcement['total_withheld_minor_units']);
        self::assertSame(
            ['claim-synthetic-1'],
            array_map(
                static fn (array $allocation): string => $allocation['claim_id'],
                $enforcement['allocations'],
            ),
        );
        // Zaměstnanci ubylo 200 000 (dohoda) + 329 900 (exekuce), nic víc.
        self::assertSame(
            self::NET_BEFORE_DEDUCTIONS - 200_000 - 329_900,
            $person['payable_after_enforcement_minor'],
        );
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array<int,int>
     */
    private function capacities(array $snapshot): array
    {
        return $this->processor()->voluntaryDeductionCapacities(
            $snapshot,
            $this->baseResult(),
            [self::EMPLOYEE_ID => self::NET_BEFORE_DEDUCTIONS],
        );
    }

    /** @return array<string,mixed> */
    private function agreement(
        int $requestedMinor,
        ?string $deliveredOn,
        ?int $totalLimit = null,
        int $withheld = 0,
    ): array {
        return [
            'id' => 7,
            'agreement_reference' => 'SRZ-7',
            'title' => 'Stravenky',
            'deduction_kind' => 'meal',
            'priority_no' => 10,
            'requested_minor' => $requestedMinor,
            'total_limit_minor' => $totalLimit,
            'withheld_total_minor' => $withheld,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'delivered_on' => $deliveredOn,
            'row_version' => 1,
        ];
    }

    /**
     * @param list<array<string,mixed>> $agreements
     * @return array<string,mixed>
     */
    private function snapshot(array $agreements = []): array
    {
        $evidence = new EnforcementPersonMonthEvidence(
            claims: [new DeductionClaim(
                id: 'claim-synthetic-1',
                legalBasis: DeductionLegalBasis::Statutory,
                category: ClaimCategory::NonPriority,
                outstandingMinorUnits: 1_000_000,
                priorityDate: '2026-02-01',
                legalTitleVerified: true,
                orderOrNoticeDelivered: true,
                orderIssuedOn: '2026-01-20',
                priorityClassificationVerified: true,
                dueMonetaryClaimVerified: true,
                enforcementOrderId: 'order-synthetic-1',
            )],
            eligibleDependants: 0,
            dependantsEvidenceComplete: true,
            eligibleSpouse: false,
            spouseEvidenceComplete: true,
            pensionEvidence: PensionEvidence::None,
            hasMultiplePayers: false,
            protectedAmountOverrideMinorUnits: null,
            protectedAmountOverrideVerified: false,
            claimRegisterEvidenceComplete: true,
            insolvency: InsolvencyInstruction::none(),
        );

        return [
            'schema_version' => 'payroll-run-input.v2',
            'supplier_id' => 1,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'payment_date' => '2026-07-15',
            'people' => [[
                'employee' => ['id' => self::EMPLOYEE_ID],
                'enforcement_evidence' => $evidence->toCanonicalArray(),
                'deduction_agreements' => $agreements,
            ]],
        ];
    }

    /** @return array<string,mixed> */
    private function baseResult(): array
    {
        $gross = 4_000_000;

        return [
            'schema_version' => 'payroll-run-result.v1',
            'people' => [[
                'employee_id' => self::EMPLOYEE_ID,
                'employments' => [],
                'totals' => [
                    'cash_payable_minor' => $gross,
                    'enforcement_base_minor' => $gross,
                ],
            ]],
            'totals' => [
                'cash_payable_minor' => $gross,
                'enforcement_base_minor' => $gross,
            ],
        ];
    }

    private function processor(): PayrollRunGarnishmentProcessor
    {
        $port = new class implements PayrollGarnishmentPort {
            public function calculate(
                EnforcementPersonMonthRequest $request,
            ): PayrollGarnishmentCalculation {
                throw new \LogicException('Persistence port is not used during calculation.');
            }
        };
        $writer = new class implements PayrollGarnishmentSnapshotWriter {
            public function store(
                EnforcementPersonMonthRequest $request,
                PayrollGarnishmentCalculation $calculation,
                ?int $revisionId,
                string $idempotencyKey,
            ): int {
                throw new \LogicException('Snapshot writer is not used during calculation.');
            }
        };

        return new PayrollRunGarnishmentProcessor(
            new GarnishmentCalculator(CzechPayrollRulesets2026::provider()),
            new PayrollGarnishmentRunIntegration($port, $writer),
        );
    }
}
