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

        // Exekuce spotřebuje z první třetiny jen 1 000 Kč — tolik zbývalo
        // dlužníkovi doplatit. Paušál plátce mzdy se z té tisícovky ukrojí,
        // srážku nezvyšuje, takže dohodě zůstane 529 900 − 100 000.
        self::assertSame([self::EMPLOYEE_ID => 429_900], $capacities);
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
        self::assertSame(429_900, $net->deductedMinorUnits);
        self::assertSame(70_100, $net->deductions[0]->unappliedMinorUnits);
        self::assertSame(2_570_100, $net->netPayableMinorUnits);

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
        // Oprávněnému dojde 950 Kč, plátci mzdy 50 Kč — a zaměstnanci se
        // srazí přesně těch 1 000 Kč, které ještě dlužil.
        self::assertSame(
            95_000,
            $person['enforcement']['result']['allocations'][0]['total_minor_units'],
        );
        self::assertSame(
            5_000,
            $person['enforcement']['result']['employer_flat_fee_minor_units'],
        );
        self::assertSame(
            100_000,
            $person['enforcement']['result']['total_withheld_minor_units'],
        );
        self::assertSame(2_470_100, $person['payable_after_enforcement_minor']);
        self::assertSame(
            $net->netPayableMinorUnits - 100_000,
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

    /**
     * Ú-04/Ú-05: osoba celý měsíc na neplaceném volnu s doplatkem zdravotního
     * pojištění do minimálního vyměřovacího základu (§ 3 odst. 10
     * z. č. 592/1992 Sb.) má ZÁPORNOU čistou mzdu. Exekuce z ní nesrazí nic —
     * § 299 OSŘ postihuje příjem, a ten tu žádný není — ale výplata po
     * srážkách musí ten dluh nést dál. Kdyby se z ní stala nula, účetní
     * můstek by ohlásil rozpor mezi předpisem a čistou výplatou.
     */
    public function testNegativeNetCarriesThroughEnforcementAsEmployeeDebt(): void
    {
        $result = $this->processor()->calculate(
            $this->snapshot(),
            $this->overdrawnBaseResult(),
        );
        $person = $result['people'][0];

        self::assertSame(-297_000, $person['payable_after_enforcement_minor']);
        self::assertSame(
            0,
            $person['enforcement']['result']['total_withheld_minor_units'],
        );
        // Nulový postižitelný příjem, ne „k ručnímu posouzení": situace je
        // zákonem předvídaná a jednoznačná, blokovat kvůli ní běh nemá důvod.
        self::assertSame(
            0,
            $person['enforcement']['input']['income']['garnishable_minor_units'],
        );
        self::assertSame(
            -297_000,
            $result['totals']['payable_after_enforcement_minor'],
        );
        self::assertSame(0, $result['totals']['enforcement_withheld_minor']);
    }

    /**
     * NEGATIVNÍ test — záporná výplata je přípustná JEN tam, kde ji vyrobila
     * záporná čistá mzda. Osoba s příjmem, které dobrovolná srážka sní víc,
     * než exekuce nechala, je pořád chyba.
     */
    public function testStillFailsWhenVoluntaryDeductionExceedsPayableWithIncome(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Dobrovolná srážka');

        $this->processor()->calculate(
            $this->snapshot(),
            $this->baseResult(self::NET_BEFORE_DEDUCTIONS),
        );
    }

    /**
     * Zdanitelný NEPENĚŽNÍ příjem (stravování) zvedá základ daně i pojistného,
     * ale nevyplácí se v penězích. Základ srážek tím přeroste peněžní výplatu
     * a bez odečtení naturálií by osoba spadla do ručního posouzení — přestože
     * z obědu se exekuci odvést nedá (§ 299 OSŘ postihuje vyplácený příjem).
     */
    public function testNonCashBenefitDoesNotInflateTheEnforcementBase(): void
    {
        $result = $this->processor()->calculate(
            $this->snapshot(),
            $this->nonCashBaseResult(),
        );
        $person = $result['people'][0];

        // Základ je peněžní výplata, ne peníze plus oběd.
        self::assertSame(
            self::NET_BEFORE_DEDUCTIONS,
            $person['enforcement']['input']['income']['garnishable_minor_units'],
        );
        self::assertNotContains(
            'income:cash_payable_enforcement_base_inconsistent',
            $person['enforcement']['result']['issues'] ?? [],
        );
    }

    /**
     * NEGATIVNÍ test: kontrola rozporu podkladů zůstává. Základ vyšší než
     * peněžní výplata i po odečtení naturálií je pořád vada, ne naturálie.
     */
    public function testInconsistentBaseWithoutNonCashStillBlocks(): void
    {
        $base = $this->nonCashBaseResult();
        // Souhrn bez nepeněžní části: `source_amount` se rovná peněžní výplatě,
        // ale základ srážek je přesto vyšší — to naturáliemi vysvětlit nelze.
        $base['people'][0]['totals']['source_amount_minor'] = 4_000_000;
        $base['people'][0]['totals']['cash_payable_minor'] = 4_000_000;
        $base['people'][0]['totals']['enforcement_base_minor'] = 4_500_000;

        $person = $this->processor()->calculate($this->snapshot(), $base)['people'][0];

        self::assertContains(
            'income:cash_payable_enforcement_base_inconsistent',
            $person['enforcement']['result']['issues'] ?? [],
        );
    }

    /**
     * N8: záporná převzatá náhrada výdajů (vrácení náhrady, § 6 odst. 7 ZDP)
     * snižuje výplatu, ale do základu srážek nepatří. Exekuce se počítá ze mzdy
     * bez ní a vrácení jde jen proti výplatě, místo aby osoba spadla do ručního
     * posouzení s rozporem základu a výplaty.
     */
    public function testNegativeReimbursementOutsideTheBaseReducesOnlyThePayout(): void
    {
        $result = $this->processor()->calculate(
            $this->snapshot(),
            $this->negativeReimbursementBaseResult(-1_500),
        );
        $person = $result['people'][0];

        self::assertNotContains(
            'income:cash_payable_enforcement_base_inconsistent',
            $person['enforcement']['result']['issues'] ?? [],
        );
        self::assertSame('supported', $person['enforcement']['result']['status']);
        // Základ srážek je čistá mzda bez vrácené náhrady, tedy stejný jako
        // u osoby bez ní.
        self::assertSame(
            self::NET_BEFORE_DEDUCTIONS,
            $person['enforcement']['input']['income']['garnishable_minor_units'],
        );
        self::assertSame(529_900, $person['enforcement']['result']['total_withheld_minor_units']);
        self::assertSame(
            self::NET_BEFORE_DEDUCTIONS - 1_500 - 529_900,
            $person['payable_after_enforcement_minor'],
        );
    }

    /**
     * NEGATIVNÍ test: záporná složka mimo základ vysvětlí jen svou vlastní
     * částku. Rozdíl větší než ona je pořád rozpor podkladů.
     */
    public function testNegativeReimbursementDoesNotExplainALargerGap(): void
    {
        $base = $this->negativeReimbursementBaseResult(-1_500);
        $base['people'][0]['totals']['enforcement_base_minor'] = 4_010_000;

        $person = $this->processor()->calculate($this->snapshot(), $base)['people'][0];

        self::assertContains(
            'income:cash_payable_enforcement_base_inconsistent',
            $person['enforcement']['result']['issues'] ?? [],
        );
    }

    /**
     * Mzda 40 000 Kč a převzatá náhrada výdajů se zápornou částkou: výplata
     * i úhrn nesou zápornou náhradu, základ srážek ne. Rozpad po vstupech má
     * tvar, který vyrábí {@see \MyInvoice\Service\Payroll\Run\PayrollRunCalculator}.
     *
     * @return array<string,mixed>
     */
    private function negativeReimbursementBaseResult(int $reimbursement): array
    {
        $gross = 4_000_000;
        $net = self::NET_BEFORE_DEDUCTIONS + $reimbursement;
        $wage = [
            'source_amount_minor' => $gross,
            'cash_payable_minor' => $gross,
            'enforcement_base_minor' => $gross,
        ];
        $refund = [
            'source_amount_minor' => $reimbursement,
            'cash_payable_minor' => $reimbursement,
            'enforcement_base_minor' => 0,
        ];
        $totals = [
            'source_amount_minor' => $gross + $reimbursement,
            'cash_payable_minor' => $gross + $reimbursement,
            'enforcement_base_minor' => $gross,
        ];
        $base = $this->baseResult(null);
        $base['people'][0]['employments'] = [[
            'employment_id' => 101,
            'inputs' => [
                ['input_id' => 1, 'component_code' => 'MZDA', 'totals' => $wage],
                ['input_id' => 2, 'component_code' => 'NAHRADA_VYDAJU_PREVZATA', 'totals' => $refund],
            ],
            'totals' => $totals,
        ]];
        $base['people'][0]['totals'] = $totals;
        $base['statutory'] = ['status' => 'calculated'];
        $base['people'][0]['statutory'] = [
            'person_reference' => 'employee:' . self::EMPLOYEE_ID,
            'status' => 'calculated',
            'net_payable_minor_units' => $net,
            'net_pay' => [
                'net_before_deductions_minor_units' => $net,
                'deducted_minor_units' => 0,
                'net_payable_minor_units' => $net,
                'deductions' => [],
            ],
        ];

        return $base;
    }

    /**
     * Osoba s peněžní mzdou 40 000 Kč a nepeněžním stravováním 600 Kč:
     * `source_amount` nese obojí, `cash_payable` jen peníze, základ srážek
     * obojí — přesně tvar, který vyrábí zdanitelné stravování.
     *
     * @return array<string,mixed>
     */
    private function nonCashBaseResult(): array
    {
        $base = $this->baseResult(null);
        $base['people'][0]['totals'] = [
            'source_amount_minor' => 4_060_000,
            'cash_payable_minor' => 4_000_000,
            'enforcement_base_minor' => 4_060_000,
        ];
        // Zákonný výsledek musí být u osoby i v kořeni, jinak se čistá mzda
        // nedosadí (`requires_net_pay`) a počítalo by se z hrubých čísel —
        // tedy z jiné větve, než na které vada vznikla.
        $base['statutory'] = ['status' => 'calculated'];
        $base['people'][0]['statutory'] = [
            'person_reference' => 'employee:' . self::EMPLOYEE_ID,
            'status' => 'calculated',
            'net_payable_minor_units' => self::NET_BEFORE_DEDUCTIONS,
            'net_pay' => [
                'net_before_deductions_minor_units' => self::NET_BEFORE_DEDUCTIONS,
                'deducted_minor_units' => 0,
                'net_payable_minor_units' => self::NET_BEFORE_DEDUCTIONS,
                'deductions' => [],
            ],
        ];

        return $base;
    }

    /** @return array<string,mixed> */
    private function overdrawnBaseResult(): array
    {
        $person = [
            'employee_id' => self::EMPLOYEE_ID,
            'employments' => [],
            'totals' => [
                'cash_payable_minor' => 0,
                'enforcement_base_minor' => 0,
            ],
            'statutory' => [
                'person_reference' => 'employee:' . self::EMPLOYEE_ID,
                'status' => 'calculated',
                'net_payable_minor_units' => -297_000,
                'net_pay' => [
                    'net_before_deductions_minor_units' => -297_000,
                    'deducted_minor_units' => 0,
                    'net_payable_minor_units' => -297_000,
                    'deductions' => [],
                ],
            ],
        ];

        return [
            'schema_version' => 'payroll-run-result.v1',
            'statutory' => ['status' => 'calculated'],
            'people' => [$person],
            'totals' => [
                'cash_payable_minor' => 0,
                'enforcement_base_minor' => 0,
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
