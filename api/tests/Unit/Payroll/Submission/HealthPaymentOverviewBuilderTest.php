<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceOverviewException;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthPaymentOverviewBuilder;
use PHPUnit\Framework\TestCase;

final class HealthPaymentOverviewBuilderTest extends TestCase
{
    private HealthPaymentOverviewBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new HealthPaymentOverviewBuilder();
    }

    public function testBuildsDeterministicOverviewPerInsurer(): void
    {
        $source = $this->source();
        $overviews = $this->builder->build(41, $source);

        self::assertCount(2, $overviews);
        self::assertSame('regular', $overviews[0]->revisionKind);
        self::assertSame(['111', '201'], array_map(
            static fn ($overview): string => $overview->insurerCode,
            $overviews,
        ));
        self::assertSame(
            ['employee:7', 'employee:12'],
            array_column($overviews[0]->people, 'employee_reference'),
        );
        self::assertSame(1_700_000, $overviews[0]->totals[
            'assessment_base_minor_units'
        ]);
        self::assertSame(229_500, $overviews[0]->totals[
            'total_contribution_minor_units'
        ]);
        self::assertSame(
            'zp-prehled-2026-06-111-revize-53.json',
            $overviews[0]->filename(),
        );
        self::assertSame(
            hash('sha256', $overviews[0]->downloadBytes()),
            $overviews[0]->sha256(),
        );
        self::assertFalse(
            $overviews[0]->toArray()['official_submission']['supported'],
        );

        $reordered = $source;
        $reordered['statutory_result']['people'] = array_reverse(
            $reordered['statutory_result']['people'],
        );
        $rebuilt = $this->builder->build(41, $reordered);

        self::assertSame(
            array_map(
                static fn ($overview): string => $overview->downloadBytes(),
                $overviews,
            ),
            array_map(
                static fn ($overview): string => $overview->downloadBytes(),
                $rebuilt,
            ),
        );
    }

    public function testRejectsRevisionThatIsNotCurrentAndApproved(): void
    {
        $source = $this->source();
        $source['revision']['current_revision_no'] = 3;

        $this->expectException(HealthInsuranceOverviewException::class);
        $this->expectExceptionMessage('aktuální schválené');
        $this->builder->build(41, $source);
    }

    public function testRejectsTamperedImmutablePersonSnapshot(): void
    {
        $source = $this->source();
        $source['statutory_result']['people'][0]['result_snapshot'][
            'assessment_base_minor_units'
        ]++;

        try {
            $this->builder->build(41, $source);
            self::fail('Pozměněný snapshot musí být odmítnut.');
        } catch (HealthInsuranceOverviewException $exception) {
            self::assertSame(
                'health_insurance_snapshot_hash_mismatch',
                $exception->validationCode,
            );
        }
    }

    public function testRejectsDifferenceBetweenPeopleAndInsurerLiability(): void
    {
        $source = $this->source();
        $source['statutory_result']['result_snapshot'][
            'insurer_liabilities'
        ][0]['total_contribution_minor_units']++;
        $source['statutory_result']['result_snapshot'][
            'total_contribution_minor_units'
        ]++;
        $source['statutory_result']['result_snapshot_hash'] = hash(
            'sha256',
            CanonicalJson::encode(
                $source['statutory_result']['result_snapshot'],
            ),
        );

        try {
            $this->builder->build(41, $source);
            self::fail('Rozdílné kontrolní součty musí být odmítnuty.');
        } catch (HealthInsuranceOverviewException $exception) {
            self::assertSame(
                'health_insurance_totals_mismatch',
                $exception->validationCode,
            );
        }
    }

    public function testRejectsManualReviewPersonEvenWhenRootClaimsCalculated(): void
    {
        $source = $this->source();
        $source['statutory_result']['people'][0]['result_status'] =
            'manual_review';

        try {
            $this->builder->build(41, $source);
            self::fail('Ruční kontrola osoby musí přehled zablokovat.');
        } catch (HealthInsuranceOverviewException $exception) {
            self::assertSame(
                'health_insurance_person_not_calculated',
                $exception->validationCode,
            );
        }
    }

    public function testRejectsInsurerOutsideTheCodebook(): void
    {
        $source = $this->source();
        $root = $source['statutory_result']['result_snapshot'];
        $root['insurer_liabilities'][0]['insurer_code'] = '999';
        $source['statutory_result']['result_snapshot'] = $root;
        $source['statutory_result']['result_snapshot_hash'] = hash(
            'sha256',
            CanonicalJson::encode($root),
        );

        try {
            $this->builder->build(41, $source);
            self::fail('Neexistující pojišťovna musí přehled zablokovat.');
        } catch (HealthInsuranceOverviewException $exception) {
            self::assertSame(
                'health_insurance_insurer_invalid',
                $exception->validationCode,
            );
            self::assertStringContainsString('999', $exception->getMessage());
            self::assertStringContainsString('111 VZP', $exception->getMessage());
        }
    }

    /**
     * @return array{
     *   revision:array<string,mixed>,
     *   statutory_result:array<string,mixed>
     * }
     */
    private function source(): array
    {
        $people = [
            $this->person(12, 'Syntetická osoba B', '111', 700_000, 31_500, 63_000),
            $this->person(7, 'Syntetická osoba A', '111', 1_000_000, 45_000, 90_000),
            $this->person(28, 'Syntetická osoba C', '201', 500_000, 22_500, 45_000),
        ];
        $root = [
            'calculation_date' => '2026-06-30',
            'status' => 'calculated',
            'assessment_base_minor_units' => 2_200_000,
            'employee_contribution_minor_units' => 99_000,
            'employer_contribution_minor_units' => 198_000,
            'total_contribution_minor_units' => 297_000,
            'insurer_liabilities' => [
                [
                    'insurer_code' => '201',
                    'person_count' => 1,
                    'assessment_base_minor_units' => 500_000,
                    'employee_contribution_minor_units' => 22_500,
                    'employer_contribution_minor_units' => 45_000,
                    'total_contribution_minor_units' => 67_500,
                ],
                [
                    'insurer_code' => '111',
                    'person_count' => 2,
                    'assessment_base_minor_units' => 1_700_000,
                    'employee_contribution_minor_units' => 76_500,
                    'employer_contribution_minor_units' => 153_000,
                    'total_contribution_minor_units' => 229_500,
                ],
            ],
            'issues' => [],
            'ruleset_id' => 'cz-health-2026',
            'ruleset_hash' => str_repeat('b', 64),
        ];

        return [
            'revision' => [
                'id' => 53,
                'run_id' => 19,
                'revision_no' => 2,
                'revision_kind' => 'regular',
                'revision_status' => 'approved',
                'period_start' => '2026-06-01',
                'current_revision_no' => 2,
            ],
            'statutory_result' => [
                'id' => 71,
                'supplier_id' => 41,
                'revision_id' => 53,
                'schema_version' => 'payroll-health-result.v1',
                'result_status' => 'calculated',
                'ruleset_id' => 'cz-health-2026',
                'ruleset_hash' => str_repeat('b', 64),
                'result_snapshot' => $root,
                'result_snapshot_hash' => hash(
                    'sha256',
                    CanonicalJson::encode($root),
                ),
                'people' => $people,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function person(
        int $employeeId,
        string $name,
        string $insurerCode,
        int $base,
        int $employee,
        int $employer,
    ): array {
        $input = [
            'employee' => [
                'id' => $employeeId,
                'full_name' => $name,
            ],
        ];
        $result = [
            'person_id' => "employee:{$employeeId}",
            'status' => 'calculated',
            'insurer_status' => 'verified',
            'insurer_code' => $insurerCode,
            'ppz_counted' => true,
            'assessment_base_minor_units' => $base,
            'employee_contribution_minor_units' => $employee,
            'employer_contribution_minor_units' => $employer,
            'total_contribution_minor_units' => $employee + $employer,
        ];

        return [
            'employee_id' => $employeeId,
            'result_status' => 'calculated',
            'input_snapshot' => $input,
            'input_snapshot_hash' => hash(
                'sha256',
                CanonicalJson::encode($input),
            ),
            'result_snapshot' => $result,
            'result_snapshot_hash' => hash(
                'sha256',
                CanonicalJson::encode($result),
            ),
            'relationships' => [],
        ];
    }
}
