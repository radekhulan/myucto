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
use MyInvoice\Service\Payroll\Garnishment\InsolvencyMode;
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
 * MZ-13-W07: exekuční srážka se počítá z čisté mzdy PŘED dobrovolnou dohodou
 * o srážkách (§ 148 ZP, § 276 a násl. OSŘ). Dohoda si nesmí ukousnout dřív,
 * než exekuce uvidí základ.
 */
final class PayrollRunGarnishmentOrderTest extends TestCase
{
    private const EMPLOYEE_ID = 11;
    private const NET_BEFORE_DEDUCTIONS = 3_000_000;
    private const VOLUNTARY_REQUESTED = 500_000;

    public function testEnforcementBaseIgnoresVoluntaryDeductionAgreements(): void
    {
        $result = $this->processor()->calculate(
            $this->snapshot(),
            $this->baseResult(self::VOLUNTARY_REQUESTED),
        );
        $person = $result['people'][0];
        $enforcement = $person['enforcement'];

        self::assertSame(
            self::NET_BEFORE_DEDUCTIONS,
            $enforcement['input']['income']['garnishable_minor_units'],
        );
        self::assertSame(
            529_900,
            $enforcement['result']['total_withheld_minor_units'],
        );
        self::assertSame(
            524_900,
            $enforcement['result']['allocations'][0]['total_minor_units'],
        );
        self::assertSame(
            5_000,
            $enforcement['result']['employer_flat_fee_minor_units'],
        );
        self::assertSame(
            self::NET_BEFORE_DEDUCTIONS - self::VOLUNTARY_REQUESTED - 529_900,
            $person['payable_after_enforcement_minor'],
        );
        self::assertSame(
            529_900,
            $result['totals']['enforcement_withheld_minor'],
        );
    }

    public function testVoluntaryCapacityIsWhatEnforcementLeftInTheGeneralPool(): void
    {
        $capacities = $this->processor()->voluntaryDeductionCapacities(
            $this->snapshot(),
            $this->baseResult(null),
            [self::EMPLOYEE_ID => self::NET_BEFORE_DEDUCTIONS],
        );

        self::assertSame([self::EMPLOYEE_ID => 0], $capacities);
    }

    public function testVoluntaryCapacityKeepsGeneralPoolLeftoverForTheAgreement(): void
    {
        $capacities = $this->processor()->voluntaryDeductionCapacities(
            $this->snapshot(100_000),
            $this->baseResult(null),
            [self::EMPLOYEE_ID => self::NET_BEFORE_DEDUCTIONS],
        );

        self::assertSame([self::EMPLOYEE_ID => 424_900], $capacities);
    }

    public function testInsolvencyLeavesNoCapacityForVoluntaryAgreements(): void
    {
        $capacities = $this->processor()->voluntaryDeductionCapacities(
            $this->snapshot(null, true),
            $this->baseResult(null),
            [self::EMPLOYEE_ID => self::NET_BEFORE_DEDUCTIONS],
        );

        self::assertSame([self::EMPLOYEE_ID => 0], $capacities);
    }

    /**
     * Celé pořadí v jednom testu: čistá mzda 30 000 Kč → exekuce z ní → dohoda
     * o srážce dostane jen to, co exekuce nechala v obecné kapacitě.
     */
    public function testWholeOrderGivesEnforcementPrecedenceOverTheAgreement(): void
    {
        $processor = $this->processor();
        $snapshot = $this->snapshot(100_000);
        $base = $this->baseResult(null);

        $capacities = $processor->voluntaryDeductionCapacities(
            $snapshot,
            $base,
            [self::EMPLOYEE_ID => self::NET_BEFORE_DEDUCTIONS],
        );
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
                self::VOLUNTARY_REQUESTED,
                null,
                true,
            )],
        ));

        self::assertSame(self::NET_BEFORE_DEDUCTIONS, $net->netBeforeDeductionsMinorUnits);
        self::assertSame(424_900, $net->deductedMinorUnits);
        self::assertSame(75_100, $net->deductions[0]->unappliedMinorUnits);
        self::assertSame(2_575_100, $net->netPayableMinorUnits);

        $base['statutory'] = ['status' => 'calculated'];
        $base['people'][0]['statutory'] = [
            'person_reference' => 'employee:' . self::EMPLOYEE_ID,
            'status' => 'calculated',
            'net_payable_minor_units' => $net->netPayableMinorUnits,
            'net_pay' => $net->jsonSerialize(),
        ];
        $person = $processor->calculate($snapshot, $base)['people'][0];

        self::assertSame(
            self::NET_BEFORE_DEDUCTIONS,
            $person['enforcement']['input']['income']['garnishable_minor_units'],
        );
        self::assertSame(
            100_000,
            $person['enforcement']['result']['allocations'][0]['total_minor_units'],
        );
        self::assertSame(
            105_000,
            $person['enforcement']['result']['total_withheld_minor_units'],
        );
        self::assertSame(2_470_100, $person['payable_after_enforcement_minor']);
        self::assertSame(
            $net->netPayableMinorUnits - 105_000,
            $person['payable_after_enforcement_minor'],
        );
    }

    public function testBlockedStatutoryPersonGetsNoVoluntaryCapacity(): void
    {
        $capacities = $this->processor()->voluntaryDeductionCapacities(
            $this->snapshot(),
            $this->baseResult(null),
            [],
        );

        self::assertSame([], $capacities);
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

    /** @return array<string,mixed> */
    private function snapshot(
        ?int $claimOutstanding = 1_000_000,
        bool $insolvency = false,
    ): array {
        $claims = $claimOutstanding === null ? [] : [new DeductionClaim(
            id: 'claim-synthetic-1',
            legalBasis: DeductionLegalBasis::Statutory,
            category: ClaimCategory::NonPriority,
            outstandingMinorUnits: $claimOutstanding,
            priorityDate: '2026-02-01',
            legalTitleVerified: true,
            orderOrNoticeDelivered: true,
            orderIssuedOn: '2026-01-20',
            priorityClassificationVerified: true,
            dueMonetaryClaimVerified: true,
            enforcementOrderId: 'order-synthetic-1',
        )];
        $evidence = new EnforcementPersonMonthEvidence(
            claims: $insolvency ? [] : $claims,
            eligibleDependants: 0,
            dependantsEvidenceComplete: true,
            eligibleSpouse: false,
            spouseEvidenceComplete: true,
            pensionEvidence: PensionEvidence::None,
            hasMultiplePayers: false,
            protectedAmountOverrideMinorUnits: null,
            protectedAmountOverrideVerified: false,
            claimRegisterEvidenceComplete: true,
            insolvency: $insolvency
                ? new InsolvencyInstruction(
                    InsolvencyMode::ApprovedStandard,
                    true,
                    true,
                    paymentInstructionId: 101,
                    paymentInstructionHash: str_repeat('a', 64),
                    employmentId: 202,
                )
                : InsolvencyInstruction::none(),
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
            ]],
        ];
    }

    /**
     * Základ mzdového běhu se zákonným výsledkem: čistá mzda před dohodou je
     * 30 000 Kč, dohoda o srážce žádá 5 000 Kč.
     *
     * @return array<string,mixed>
     */
    private function baseResult(?int $voluntaryApplied): array
    {
        $gross = 4_000_000;
        $person = [
            'employee_id' => self::EMPLOYEE_ID,
            'employments' => [],
            'totals' => [
                'cash_payable_minor' => $gross,
                'enforcement_base_minor' => $gross,
            ],
        ];
        $result = [
            'schema_version' => 'payroll-run-result.v1',
            'people' => [$person],
            'totals' => [
                'cash_payable_minor' => $gross,
                'enforcement_base_minor' => $gross,
            ],
        ];
        if ($voluntaryApplied === null) {
            return $result;
        }

        $result['statutory'] = ['status' => 'calculated'];
        $result['people'][0]['statutory'] = [
            'person_reference' => 'employee:' . self::EMPLOYEE_ID,
            'status' => 'calculated',
            'net_payable_minor_units' =>
                self::NET_BEFORE_DEDUCTIONS - $voluntaryApplied,
            'net_pay' => [
                'net_before_deductions_minor_units' => self::NET_BEFORE_DEDUCTIONS,
                'deducted_minor_units' => $voluntaryApplied,
                'net_payable_minor_units' =>
                    self::NET_BEFORE_DEDUCTIONS - $voluntaryApplied,
                'deductions' => [[
                    'deduction_reference' => 'agreement:7',
                    'priority' => 10,
                    'requested_minor_units' => self::VOLUNTARY_REQUESTED,
                    'applied_minor_units' => $voluntaryApplied,
                    'unapplied_minor_units' =>
                        self::VOLUNTARY_REQUESTED - $voluntaryApplied,
                    'active' => true,
                ]],
            ],
        ];

        return $result;
    }
}
