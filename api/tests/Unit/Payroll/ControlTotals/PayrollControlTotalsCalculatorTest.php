<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\ControlTotals;

use MyInvoice\Service\Payroll\ControlTotals\PayrollControlTotalsCalculator;
use MyInvoice\Service\Payroll\ControlTotals\PayrollControlTotals;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PHPUnit\Framework\TestCase;

final class PayrollControlTotalsCalculatorTest extends TestCase
{
    public function testBuildsExactHierarchyLiabilityAndAccountingTotals(): void
    {
        $totals = $this->calculate(
            $this->resultSnapshot(),
        );

        self::assertSame(9, $totals->supplierId);
        self::assertSame(17, $totals->revisionId);
        self::assertCount(3, $totals->relationships);
        self::assertCount(2, $totals->people);
        self::assertSame([
            [
                'office_id' => 71,
                'totals' => $this->metrics(10_000),
            ],
            [
                'office_id' => 72,
                'totals' => $this->metrics(20_000),
            ],
        ], $totals->offices);
        self::assertSame($this->metrics(30_000), $totals->company);
        self::assertSame([
            [
                'liability_kind' => 'advance_tax',
                'direction' => 'outgoing',
                'amount_minor' => 3_000,
            ],
            [
                'liability_kind' => 'employee_receivable',
                'direction' => 'incoming',
                'amount_minor' => 0,
            ],
            [
                'liability_kind' => 'health_insurance',
                'direction' => 'outgoing',
                'amount_minor' => 4_050,
            ],
            [
                'liability_kind' => 'net_wage',
                'direction' => 'outgoing',
                'amount_minor' => 23_100,
            ],
            [
                'liability_kind' => 'social_insurance',
                'direction' => 'outgoing',
                'amount_minor' => 6_100,
            ],
            [
                'liability_kind' => 'standard_deduction',
                'direction' => 'outgoing',
                'amount_minor' => 450,
            ],
            [
                'liability_kind' => 'withholding_tax',
                'direction' => 'outgoing',
                'amount_minor' => 0,
            ],
        ], $totals->liabilities);
        self::assertSame([
            [
                'debit_code' => '521',
                'credit_code' => '331',
                'amount_minor' => 30_000,
            ],
        ], $totals->accountingDimensions);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/D',
            $totals->controlHash,
        );
    }

    public function testFailsClosedWhenPersonDoesNotEqualRelationships(): void
    {
        $result = $this->resultSnapshot(firstPersonSourceAmount: 10_001);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('osoby');

        $this->calculate($result);
    }

    public function testFailsClosedWhenAccountingDimensionDoesNotEqualInputs(): void
    {
        $result = $this->resultSnapshot(accountingAmount: 29_999);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('účetní');

        $this->calculate($result);
    }

    public function testFailsClosedForUnapprovedStatutoryCalculation(): void
    {
        $result = $this->resultSnapshot(statutoryStatus: 'manual_review');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('zákonn');

        $this->calculate($result);
    }

    public function testRejectsDecimalOrNumericStringInsteadOfExactMinorUnits(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('celých haléřích');

        $this->calculate(
            $this->resultSnapshot(stringPersonSourceAmount: true),
        );
    }

    /**
     * Ú-04/Ú-05: osoba celý měsíc na neplaceném volnu. Nemá žádný peněžní
     * příjem, ale zaměstnavatel za ni odvádí doplatek zdravotního pojištění
     * do minimálního vyměřovacího základu (§ 3 odst. 10 z. č. 592/1992 Sb.),
     * který podle odst. 12 téhož paragrafu hradí zaměstnanec. Čistá mzda tím
     * vyjde ZÁPORNÁ a je z ní pohledávka za zaměstnancem, ne chyba podkladů.
     */
    public function testNegativeNetPayableBecomesEmployeeReceivableNotFailure(): void
    {
        $totals = $this->calculate(
            $this->resultSnapshot(overdrawnPerson: true),
            $this->inputSnapshot(overdrawnPerson: true),
        );

        $liabilities = $this->liabilityMap($totals);
        // Závazek čisté mzdy zůstává tím, co se skutečně vyplácí — přeplatek
        // ho NESNIŽUJE, jinak by nesouhlasil s platebními závazky MZ-17.
        self::assertSame(23_100, $liabilities['net_wage']);
        self::assertSame(500, $liabilities['employee_receivable']);
        // Doplatek ZP je pořád odvod zdravotní pojišťovně, i když jde
        // z kapsy zaměstnance, kterému se nic nevyplácí.
        self::assertSame(4_550, $liabilities['health_insurance']);
        self::assertSame('incoming', $this->liabilityDirection($totals, 'employee_receivable'));
        self::assertSame('outgoing', $this->liabilityDirection($totals, 'net_wage'));
        self::assertCount(3, $totals->people);
    }

    public function testWholeRunInNegativeStillProducesExactTotals(): void
    {
        $totals = $this->calculate(
            $this->resultSnapshot(overdrawnPerson: true, overdrawnOnly: true),
            $this->inputSnapshot(overdrawnPerson: true, overdrawnOnly: true),
        );

        $liabilities = $this->liabilityMap($totals);
        self::assertSame(0, $liabilities['net_wage']);
        self::assertSame(500, $liabilities['employee_receivable']);
        self::assertSame(500, $liabilities['health_insurance']);
        self::assertSame(0, $liabilities['standard_deduction']);
        self::assertSame($this->metrics(0), $totals->company);
    }

    /**
     * Opravná revize, která pohledávku odúčtuje: člověk se do měsíce vrátil,
     * mzda byla dopočítána a přeplatek zmizel. Kontrolní součty nesmí držet
     * ani korunu z předchozí verze.
     */
    public function testCorrectionRevisionClearsTheEmployeeReceivable(): void
    {
        $totals = $this->calculate(
            $this->resultSnapshot(overdrawnPerson: true, overdrawnSettled: true),
            $this->inputSnapshot(overdrawnPerson: true),
        );

        $liabilities = $this->liabilityMap($totals);
        self::assertSame(0, $liabilities['employee_receivable']);
        self::assertSame(23_600, $liabilities['net_wage']);
    }

    /**
     * NEGATIVNÍ test — povolení záporné čisté mzdy nesmí být plošné. Záporný
     * odvod pojistného za osobu (a tedy i za účtárnu a firmu) žádný účetní
     * význam nemá a musí dál padat.
     */
    public function testStillFailsClosedForNegativeInsuranceContribution(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('nesmí být záporné');

        $this->calculate(
            $this->resultSnapshot(negativeEmployeeSocial: true),
        );
    }

    /**
     * NEGATIVNÍ test — záporná čistá mzda se nekontroluje znaménkem, ale
     * rovností s vlastním rozpadem. Rozbitá soustava padá dál.
     */
    public function testStillFailsClosedWhenNegativeNetDoesNotMatchItsBreakdown(): void
    {
        $result = $this->resultSnapshot(overdrawnPerson: true);
        $result['people'][2]['statutory']['net_pay']['net_payable_minor_units'] = -400;
        $result['people'][2]['statutory']['net_payable_minor_units'] = -400;

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('kontrolní součet');

        $this->calculate($result, $this->inputSnapshot(overdrawnPerson: true));
    }

    /** @return array<string,int> */
    private function liabilityMap(PayrollControlTotals $totals): array
    {
        $result = [];
        foreach ($totals->liabilities as $liability) {
            $result[$liability['liability_kind']] = $liability['amount_minor'];
        }

        return $result;
    }

    private function liabilityDirection(
        PayrollControlTotals $totals,
        string $kind,
    ): string {
        foreach ($totals->liabilities as $liability) {
            if ($liability['liability_kind'] === $kind) {
                return $liability['direction'];
            }
        }

        self::fail("Kontrolní součty neobsahují závazek {$kind}.");
    }

    /** @return array<string,mixed> */
    private function inputSnapshot(
        bool $overdrawnPerson = false,
        bool $overdrawnOnly = false,
    ): array {
        $overdrawn = [
            'employee' => ['id' => 503],
            'employments' => [[
                'employment' => ['id' => 604, 'office_id' => 73],
                'inputs' => [],
            ]],
        ];
        if ($overdrawnOnly) {
            return [
                'schema_version' => 'payroll-run-input.v2',
                'people' => [$overdrawn],
            ];
        }
        $snapshot = [
            'schema_version' => 'payroll-run-input.v2',
            'people' => [
                [
                    'employee' => ['id' => 501],
                    'employments' => [[
                        'employment' => ['id' => 601, 'office_id' => 71],
                        'inputs' => [],
                    ]],
                ],
                [
                    'employee' => ['id' => 502],
                    'employments' => [
                        [
                            'employment' => ['id' => 602, 'office_id' => 72],
                            'inputs' => [],
                        ],
                        [
                            'employment' => ['id' => 603, 'office_id' => 72],
                            'inputs' => [],
                        ],
                    ],
                ],
            ],
        ];
        if ($overdrawnPerson) {
            $snapshot['people'][] = $overdrawn;
        }

        return $snapshot;
    }

    /** @return array<string,mixed> */
    private function resultSnapshot(
        int $firstPersonSourceAmount = 10_000,
        int $accountingAmount = 30_000,
        string $statutoryStatus = 'calculated',
        bool $stringPersonSourceAmount = false,
        bool $overdrawnPerson = false,
        bool $overdrawnOnly = false,
        bool $overdrawnSettled = false,
        bool $negativeEmployeeSocial = false,
    ): array {
        $firstPerson = $this->person(
            501,
            [
                $this->relationship(601, 10_000, 1),
            ],
            700,
            450,
            900,
            1_000,
            0,
            100,
            7_750,
        );
        if ($firstPersonSourceAmount !== 10_000
            || $stringPersonSourceAmount
        ) {
            $firstPerson['totals'] = [
                ...$this->metrics(10_000),
                'source_amount_minor' => $stringPersonSourceAmount
                    ? (string) $firstPersonSourceAmount
                    : $firstPersonSourceAmount,
            ];
        }
        if ($negativeEmployeeSocial) {
            $firstPerson['statutory']['social_insurance']
                ['employee_contribution_minor_units'] = -700;
        }
        // Neplacené volno celý měsíc: nulový příjem, jediná položka je
        // doplatek ZP do minimálního vyměřovacího základu. Čistá mzda −500.
        // V opravné revizi (`overdrawnSettled`) je člověk zpátky v práci
        // a přeplatek je vyrovnaný.
        $overdrawn = $overdrawnSettled
            ? $this->person(
                503,
                [$this->relationship(604, 500, 4)],
                0,
                0,
                0,
                0,
                0,
                0,
                500,
            )
            : $this->person(
                503,
                [$this->relationship(604, 0, 4)],
                0,
                500,
                0,
                0,
                0,
                0,
                -500,
            );
        $people = $overdrawnOnly
            ? [$overdrawn]
            : [
                $firstPerson,
                $this->person(
                    502,
                    [
                        $this->relationship(602, 12_000, 2),
                        $this->relationship(603, 8_000, 3),
                    ],
                    1_400,
                    900,
                    1_800,
                    2_000,
                    0,
                    350,
                    15_350,
                ),
                ...($overdrawnPerson ? [$overdrawn] : []),
            ];

        return [
            'schema_version' => 'payroll-run-result.v2',
            'source_snapshot_hash' => hash(
                'sha256',
                CanonicalJson::encode(
                    $this->inputSnapshot($overdrawnPerson, $overdrawnOnly),
                ),
            ),
            'people' => $people,
            'totals' => $this->metrics(
                $overdrawnOnly ? 0 : ($overdrawnSettled ? 30_500 : 30_000),
            ),
            'accounting_totals' => [[
                'debit_code' => '521',
                'credit_code' => '331',
                'amount_minor' => $overdrawnOnly
                    ? 0
                    : ($overdrawnSettled ? 30_500 : $accountingAmount),
            ]],
            'statutory' => [
                'status' => $statutoryStatus,
                'employer_social_minor_units' => $overdrawnOnly ? 0 : 4_000,
            ],
        ];
    }

    /**
     * @param list<array{
     *   employment_id:int,
     *   inputs:list<array{
     *     input_id:int,
     *     accounting:array{
     *       debit_code:string,
     *       credit_code:string,
     *       amount_minor:int
     *     }
     *   }>,
     *   totals:array<string,int>
     * }> $relationships
     * @return array<string,mixed>
     */
    private function person(
        int $employeeId,
        array $relationships,
        int $social,
        int $healthEmployee,
        int $healthEmployer,
        int $advanceTax,
        int $withholdingTax,
        int $deducted,
        int $netPayable,
    ): array {
        $source = array_sum(array_map(
            static fn(array $row): int
                => $row['totals']['source_amount_minor'],
            $relationships,
        ));
        return [
            'employee_id' => $employeeId,
            'employments' => $relationships,
            'totals' => $this->metrics($source),
            'statutory' => [
                'person_reference' => "employee:{$employeeId}",
                'status' => 'calculated',
                'social_insurance' => [
                    'employee_contribution_minor_units' => $social,
                ],
                'health_insurance' => [
                    'employee_contribution_minor_units' => $healthEmployee,
                    'employer_contribution_minor_units' => $healthEmployer,
                    'total_contribution_minor_units'
                        => $healthEmployee + $healthEmployer,
                ],
                'income_tax' => [
                    'advance_tax' => [
                        'tax_after_credits_minor_units' => $advanceTax,
                        'tax_bonus_minor_units' => 0,
                    ],
                    'withholding_tax_minor_units' => $withholdingTax,
                ],
                'net_pay' => [
                    'person_reference' => "employee:{$employeeId}",
                    'relationships' => array_map(
                        static fn(array $row): array => [
                            'relationship_reference'
                                => 'employment:' . $row['employment_id'],
                            'cash_income_minor_units'
                                => $row['totals']['cash_payable_minor'],
                            'non_cash_income_minor_units' => 0,
                        ],
                        $relationships,
                    ),
                    'cash_income_minor_units' => $source,
                    'non_cash_income_minor_units' => 0,
                    'employee_social_minor_units' => $social,
                    'employee_health_minor_units' => $healthEmployee,
                    'advance_tax_minor_units' => $advanceTax,
                    'withholding_tax_minor_units' => $withholdingTax,
                    'tax_bonus_minor_units' => 0,
                    'correction_minor_units' => 0,
                    'net_before_deductions_minor_units'
                        => $netPayable + $deducted,
                    'deducted_minor_units' => $deducted,
                    'net_payable_minor_units' => $netPayable,
                    'deductions' => $deducted === 0 ? [] : [[
                        'applied_minor_units' => $deducted,
                    ]],
                ],
                'net_payable_minor_units' => $netPayable,
            ],
        ];
    }

    /**
     * @return array{
     *   employment_id:int,
     *   inputs:list<array{
     *     input_id:int,
     *     accounting:array{
     *       debit_code:string,
     *       credit_code:string,
     *       amount_minor:int
     *     }
     *   }>,
     *   totals:array<string,int>
     * }
     */
    private function relationship(
        int $employmentId,
        int $amount,
        int $inputId,
    ): array {
        return [
            'employment_id' => $employmentId,
            'inputs' => [[
                'input_id' => $inputId,
                'accounting' => [
                    'debit_code' => '521',
                    'credit_code' => '331',
                    'amount_minor' => $amount,
                ],
            ]],
            'totals' => $this->metrics($amount),
        ];
    }

    /** @return array<string,int> */
    private function metrics(int $amount): array
    {
        return [
            'source_amount_minor' => $amount,
            'cash_payable_minor' => $amount,
            'tax_base_minor' => $amount,
            'social_base_minor' => $amount,
            'health_base_minor' => $amount,
            'average_earning_base_minor' => $amount,
            'enforcement_base_minor' => $amount,
            'jmhz_amount_minor' => $amount,
        ];
    }

    /**
     * @param array<string,mixed> $result
     * @param ?array<string,mixed> $input
     */
    private function calculate(
        array $result,
        ?array $input = null,
    ): PayrollControlTotals {
        return new PayrollControlTotalsCalculator()->calculate(
            9,
            17,
            $input ?? $this->inputSnapshot(),
            $result,
            hash('sha256', CanonicalJson::encode($result)),
        );
    }
}
