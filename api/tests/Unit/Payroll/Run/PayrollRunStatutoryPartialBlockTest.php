<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Run;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollRunRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryResultRepository;
use MyInvoice\Service\Payroll\RiskySavings\PayrollRiskySavingsRules;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Run\PayrollRunStatutoryCalculationService;
use MyInvoice\Service\Payroll\Run\PayrollRunStatutoryInputAssembler;
use MyInvoice\Service\Payroll\Run\PayrollRunStatutoryResultPersister;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Osoba bez zákonné evidence vypadne z výpočtu sama; ostatní se spočítají.
 *
 * Běh 6/2026 s 225 lidmi, z nichž nikdo evidenci neměl, ukázal účetní
 * 900 zákonných a 225 exekučních blokátorů a nikomu nic nespočítal: jediný
 * osobní problém shodil celou doménu a s ní celý zákonný výpočet. Firemní
 * souhrny a schválení běhu přitom úplnost vyžadovat dál musí — hlídá to
 * kořenový stav `manual_review` a to, že se nic neukládá.
 */
final class PayrollRunStatutoryPartialBlockTest extends TestCase
{
    private const PERSIST_FORBIDDEN = 'Výsledek s vyřazenou osobou se nesmí ukládat.';

    /** Vlastní důvody osoby 43 — přesně jeden na každou chybějící evidenci. */
    private const MISSING_EVIDENCE_43 = [
        'health_insurance:health_coverage_evidence_missing:employee:43',
        'income_tax:tax_declaration_evidence_missing:employee:43',
        'income_tax:tax_residence_evidence_missing:employee:43',
        'social_insurance:social_jurisdiction_evidence_missing:employee:43',
    ];

    public function testAssemblerSetsAsideOnlyThePersonWithoutEvidence(): void
    {
        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble(
            $this->snapshot([
                $this->person(42, 84),
                $this->personWithoutEvidence(43, 85),
            ]),
        );

        self::assertSame([], $bundle->globalIssues());
        self::assertSame(
            ['employee:42'],
            array_map(
                static fn ($person): string => $person->personId,
                $bundle->socialInsurance?->people ?? [],
            ),
        );
        self::assertSame(
            ['employee:42'],
            array_map(
                static fn ($person): string => $person->personId,
                $bundle->healthInsurance?->people ?? [],
            ),
        );
        self::assertSame(
            ['employee:42'],
            array_map(
                static fn ($input): string => $input->employeeReference,
                $bundle->incomeTax,
            ),
        );
        self::assertSame([43], array_keys($bundle->blockedPeople));
        self::assertSame(
            self::MISSING_EVIDENCE_43,
            array_map(
                static fn ($issue): string => $issue->toIssueString(),
                $bundle->blockedPeople[43],
            ),
        );
    }

    public function testOthersAreCalculatedWhilePersonWithoutEvidenceCarriesOnlyOwnIssues(): void
    {
        $result = $this->calculate([
            $this->person(42, 84),
            $this->personWithoutEvidence(43, 85),
        ]);

        // Firemní souhrn ani uložené sady — běh bez úplné množiny osob
        // nesmí vypadat spočítaně a nejde schválit.
        self::assertSame('manual_review', $result['status']);
        self::assertSame([], $result['issues']);
        self::assertSame([], $result['result_set_ids']);
        self::assertNull($result['employer_social_minor_units']);
        self::assertNull($result['employer_social_before_discount_minor_units']);
        self::assertNull($result['employer_social_part_time_discount_minor_units']);
        self::assertSame([], $result['employer_social_categories']);

        self::assertCount(2, $result['people']);
        [$calculated, $blocked] = $result['people'];
        self::assertSame('employee:42', $calculated['person_reference']);
        self::assertSame('calculated', $calculated['status']);
        self::assertIsInt($calculated['net_payable_minor_units']);
        self::assertGreaterThan(0, $calculated['net_payable_minor_units']);
        self::assertSame('calculated', $calculated['social_insurance']['status']);
        self::assertSame('calculated', $calculated['health_insurance']['status']);
        self::assertSame('calculated', $calculated['income_tax']['status']);

        self::assertSame('employee:43', $blocked['person_reference']);
        self::assertSame('manual_review', $blocked['status']);
        self::assertNull($blocked['net_payable_minor_units']);
        self::assertNull($blocked['social_insurance']);
        self::assertNull($blocked['health_insurance']);
        self::assertNull($blocked['income_tax']);
        self::assertSame(self::MISSING_EVIDENCE_43, $blocked['net_pay']['issues']);
        // Obrazovka běhu čte důvody z osoby, ne z čisté mzdy.
        self::assertSame(self::MISSING_EVIDENCE_43, $blocked['issues']);
        self::assertArrayNotHasKey('issues', $calculated);
    }

    /**
     * Výsledek spočítané osoby nesmí záviset na tom, kdo další z výpočtu
     * vypadl a proč. Bajtové porovnání „bez souseda" vyžaduje uložení
     * výsledku, a to jde jen proti databázi — to hlídá integrační
     * PayrollRunPartialStatutoryBlockTest.
     */
    public function testCalculatedPersonIsIdenticalWhateverHappensToBlockedNeighbours(): void
    {
        $withOne = $this->personResult($this->calculate([
            $this->person(42, 84),
            $this->personWithoutEvidence(43, 85),
        ]), 'employee:42');
        $withTwo = $this->personResult($this->calculate([
            $this->person(42, 84),
            $this->personWithoutEvidence(43, 85),
            $this->personWithoutTerm(44, 86),
        ]), 'employee:42');

        self::assertSame(
            json_encode($withOne, JSON_THROW_ON_ERROR),
            json_encode($withTwo, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Vyřazená osoba nemá vyměřovací základ, takže příspěvek na rizikové
     * spoření by u ní hlásil jen odvozené „chybí základ" a zakrýval skutečnou
     * příčinu.
     */
    public function testBlockedPersonGetsNoDerivedRiskySavingsEntry(): void
    {
        $blocked = $this->personWithoutEvidence(43, 85);
        $blocked['employments'][0]['risky_savings_evidence'] = ['synthetic' => true];

        $result = $this->calculate([$this->person(42, 84), $blocked]);

        self::assertSame([], $result['risky_savings']);
    }

    /** Duplicitní osoba je problém snímku, ne osoby — nevíme, komu co patří. */
    public function testGlobalIssueStillBlocksEveryone(): void
    {
        $person = $this->person(42, 84);

        $result = $this->calculate([$person, $person, $this->person(43, 85)]);

        self::assertSame('manual_review', $result['status']);
        self::assertSame([], $result['people']);
        self::assertSame([], $result['result_set_ids']);
        self::assertContains(
            'snapshot:duplicate_employee_reference:employee:42',
            $result['issues'],
        );
    }

    /**
     * Blokátor na každý SKUTEČNÝ problém vyřazené osoby — spočítaná osoba
     * nemá žádný a problém vztahu hlášený třemi doménami je jeden řádek.
     */
    public function testValidationRowsAreOnePerRealProblemOfBlockedPeople(): void
    {
        $result = $this->calculate([
            $this->person(42, 84),
            $this->personWithoutEvidence(43, 85),
            $this->personWithoutTerm(44, 86),
        ]);
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE payroll_run_validations (
            supplier_id INTEGER, revision_id INTEGER, severity TEXT, code TEXT,
            entity_type TEXT, entity_id INTEGER, message TEXT, remediation_path TEXT,
            requires_override INTEGER
        )');
        // Období běhu pro hlášku o pojišťovně zapsané až později; tady žádné není.
        $pdo->exec('CREATE TABLE payroll_runs (supplier_id INTEGER, id INTEGER, period_start TEXT)');
        $pdo->exec('CREATE TABLE payroll_run_revisions (supplier_id INTEGER, id INTEGER, run_id INTEGER)');
        $connection = $this->createStub(Connection::class);
        $connection->method('pdo')->willReturn($pdo);

        (new PayrollRunRepository($connection))->replaceStatutoryValidations(
            7,
            1,
            ['statutory' => $result],
        );

        $rows = $pdo->query('SELECT * FROM payroll_run_validations')
            ->fetchAll(PDO::FETCH_ASSOC);
        $perEmployee = array_count_values(array_map(
            static fn (array $row): string => $row['entity_type'] . ':' . $row['entity_id'],
            $rows,
        ));
        ksort($perEmployee);
        self::assertSame(['employee:43' => 4, 'employee:44' => 1], $perEmployee);
    }

    /**
     * Slevu pracujícího důchodce uplatňuje zaměstnanec sám. Bez záznamu se
     * osoba spočítá stejně, jako by měla „neuplatňuje se", a nekončí
     * v ručním posouzení.
     */
    public function testMissingWorkingPensionerDiscountIsTreatedAsNotClaimed(): void
    {
        $withoutRecord = $this->person(42, 84);
        $withoutRecord['statutory_evidence']['social']['working_pensioner_discount'] = null;

        $assembler = new PayrollRunStatutoryInputAssembler();
        $missing = $assembler->assemble($this->snapshot([$withoutRecord]));
        $notClaimed = $assembler->assemble($this->snapshot([$this->person(42, 84)]));

        self::assertSame([], $missing->blockedPeople);
        self::assertSame([], $missing->globalIssues());
        self::assertEquals(
            $notClaimed->socialInsurance?->people,
            $missing->socialInsurance?->people,
        );
        self::assertCount(1, $missing->socialInsurance?->people ?? []);
    }

    /**
     * @param list<array<string,mixed>> $people
     * @return array<string,mixed>
     */
    private function calculate(array $people): array
    {
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willThrowException(
            new \LogicException(self::PERSIST_FORBIDDEN),
        );
        $service = new PayrollRunStatutoryCalculationService(
            CzechPayrollRulesets2026::provider(),
            new PayrollRunStatutoryInputAssembler(),
            new PayrollRunStatutoryResultPersister(
                new PayrollStatutoryResultRepository($db),
                $db,
            ),
        );

        return $service->calculateAndPersist(
            7,
            1,
            null,
            $this->snapshot($people),
            $this->baseResult($people),
        );
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function personResult(array $result, string $reference): array
    {
        foreach ($result['people'] as $person) {
            if ($person['person_reference'] === $reference) {
                return $person;
            }
        }
        self::fail("Výsledek nemá osobu {$reference}.");
    }

    /**
     * @param list<array<string,mixed>> $people
     * @return array<string,mixed>
     */
    private function snapshot(array $people): array
    {
        return [
            'schema_version' => 'payroll-run-input.v2',
            'supplier_id' => 7,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'payment_date' => '2026-07-15',
            'statutory_period' => [
                'period_start' => '2026-06-01',
                'period_end' => '2026-06-30',
                'payment_date' => '2026-07-15',
                'tax_calculation_date' => '2026-06-30',
                'social_calculation_date' => '2026-06-30',
                'health_calculation_date' => '2026-06-30',
            ],
            'risky_savings_ruleset' => PayrollRiskySavingsRules::fromProvider(
                CzechPayrollRulesets2026::provider(),
                '2026-06-01',
            )->toSnapshot(),
            'people' => $people,
        ];
    }

    /**
     * @param list<array<string,mixed>> $people
     * @return array<string,mixed>
     */
    private function baseResult(array $people): array
    {
        $result = [];
        foreach ($people as $person) {
            $result[] = [
                'employee_id' => $person['employee']['id'],
                'employments' => array_map(
                    static fn (array $employment): array => [
                        'employment_id' => $employment['employment']['id'],
                        'totals' => [
                            'cash_payable_minor' => 4_500_000,
                            'source_amount_minor' => 4_500_000,
                        ],
                    ],
                    $person['employments'],
                ),
            ];
        }

        return ['people' => $result];
    }

    /** @return array<string,mixed> */
    private function personWithoutEvidence(int $employeeId, int $employmentId): array
    {
        $person = $this->person($employeeId, $employmentId);
        $person['statutory_evidence'] = [
            'schema_version' => 'payroll-person-statutory-evidence.v1',
            'employee_id' => $employeeId,
            'effective_on' => '2026-06-30',
            'health' => [
                'coverage' => null,
                'minimum_reductions' => [],
                'month_evidence' => null,
                'other_employer_bases' => [],
            ],
            'income_tax' => [
                'declaration' => null,
                'residence' => null,
                'credit_claims' => [],
                'child_claims' => [],
            ],
            'social' => [
                'jurisdiction' => null,
                'working_pensioner_discount' => null,
            ],
        ];

        return $person;
    }

    /** Vztah bez účinných podmínek — problém, který hlásí všechny tři domény. */
    private function personWithoutTerm(int $employeeId, int $employmentId): array
    {
        $person = $this->person($employeeId, $employmentId);
        $person['employments'][0]['term'] = null;

        return $person;
    }

    /** @return array<string,mixed> */
    private function person(int $employeeId, int $employmentId): array
    {
        $state = static fn (string $kind, array $totals): array => [
            'status' => 'verified',
            'issue_code' => null,
            'state' => [
                'schema_version' => 'payroll-statutory-accumulator-state.v1',
                'supplier_id' => 7,
                'employee_id' => $employeeId,
                'calculation_kind' => $kind,
                'year' => 2026,
                'before_period_start' => '2026-06-01',
                'totals' => $totals,
            ],
        ];

        return [
            'employee' => [
                'id' => $employeeId,
                'full_name' => "Syntetický Zaměstnanec {$employeeId}",
            ],
            'deduction_agreements' => [],
            'statutory_accumulators' => [
                'schema_version' => 'payroll-person-statutory-accumulators.v1',
                'social_insurance' => $state('social_insurance', [
                    'assessment_base_minor_units' => 12_300_000,
                ]),
                'income_tax' => $state('income_tax', [
                    'completed_months' => 5,
                    'advance_base_minor_units' => 12_300_000,
                    'withholding_base_minor_units' => 0,
                    'advance_tax_minor_units' => 1_845_000,
                    'withholding_tax_minor_units' => 0,
                    'applied_non_refundable_credits_minor_units' => 154_200,
                    'applied_child_credit_minor_units' => 0,
                    'tax_bonus_minor_units' => 0,
                    'bonus_qualifying_income_minor_units' => 12_300_000,
                ]),
            ],
            'statutory_evidence' => $this->completeEvidence($employeeId),
            'employments' => [[
                'employment' => [
                    'id' => $employmentId,
                    'employee_id' => $employeeId,
                    'relation_type' => 'employment',
                    'start_date' => '2025-01-01',
                    'actual_start_date' => '2025-01-02',
                    'end_date' => null,
                    'monthly_gross_minor' => 4_500_000,
                ],
                'term' => [
                    'id' => $employmentId + 1000,
                    'effective_from' => '2025-01-01',
                    'effective_to' => null,
                    'social_insurance_participation' => 'automatic',
                    'health_insurance_participation' => 'automatic',
                    'tax_regime' => 'advance',
                    'tax_declaration_signed' => true,
                ],
                'inputs' => [[
                    'id' => $employmentId * 10,
                    'amount_minor' => 4_500_000,
                    'source_period_start' => null,
                    'component' => [
                        'code' => 'MZDA_MESICNI',
                        'tax_treatment' => 'included',
                        'social_participation_treatment' => 'included',
                        'social_treatment' => 'included',
                        'health_participation_treatment' => 'included',
                        'health_treatment' => 'included',
                    ],
                ]],
            ]],
        ];
    }

    /** @return array<string,mixed> */
    private function completeEvidence(int $employeeId): array
    {
        return [
            'schema_version' => 'payroll-person-statutory-evidence.v1',
            'employee_id' => $employeeId,
            'effective_on' => '2026-06-30',
            'health' => [
                'coverage' => [
                    'id' => 1,
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                    'row_version' => 1,
                    'jurisdiction' => 'czech_regime_verified',
                    'foreign_country_code' => null,
                    'jurisdiction_evidence_reference' => null,
                    'insurer_status' => 'verified',
                    'insurer_code' => '111',
                    'insurer_evidence_reference' => 'document:health-insurer',
                ],
                'minimum_reductions' => [],
                'month_evidence' => null,
                'other_employer_bases' => [],
            ],
            'income_tax' => [
                'declaration' => [
                    'id' => 3,
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                    'row_version' => 1,
                    'status' => 'signed',
                    'evidence_reference' => 'document:tax-declaration',
                ],
                'residence' => [
                    'id' => 4,
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                    'row_version' => 1,
                    'residence' => 'czech-resident',
                    'country_code' => 'CZ',
                    'evidence_reference' => 'document:tax-residence',
                ],
                'credit_claims' => [[
                    'id' => 5,
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                    'row_version' => 1,
                    'credit_kind' => 'taxpayer',
                    'evidence_status' => 'verified',
                    'evidence_reference' => 'document:taxpayer-credit',
                ]],
                'child_claims' => [],
            ],
            'social' => [
                'jurisdiction' => [
                    'id' => 6,
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                    'row_version' => 1,
                    'jurisdiction' => 'czech_regime_verified',
                    'foreign_country_code' => null,
                    'jurisdiction_evidence_reference' => null,
                    'a1_status' => 'not_applicable',
                    'a1_certificate_reference' => null,
                    'a1_valid_until' => null,
                ],
                'working_pensioner_discount' => [
                    'id' => 7,
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                    'row_version' => 1,
                    'status' => 'not_claimed',
                    'evidence_reference' => null,
                ],
            ],
        ];
    }
}
