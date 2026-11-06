<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\ControlTotals\PayrollControlTotalsService;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollControlTotalsServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    public function testLoadsOnlyTenantApprovedCanonicalImmutableResult(): void
    {
        $connection = \MyInvoice\Tests\Support\PayrollTestContainer::build()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $pdo = $connection->pdo();
        $supplierQuery = $pdo->query(
            'SELECT MIN(id) FROM supplier',
        );
        self::assertInstanceOf(\PDOStatement::class, $supplierQuery);
        $sourceSupplierId = (int) $supplierQuery->fetchColumn();
        self::assertGreaterThan(0, $sourceSupplierId);

        $pdo->beginTransaction();
        try {
            $supplierId = $this->createIsolatedSupplier(
                $pdo,
                $sourceSupplierId,
            );
            $otherSupplierId = $this->createIsolatedSupplier(
                $pdo,
                $sourceSupplierId,
            );
            $revisionId = $this->insertRevision(
                $pdo,
                $supplierId,
                'approved',
                false,
            );
            $service = new PayrollControlTotalsService($connection);

            $totals = $service->forApprovedRevision(
                $supplierId,
                $revisionId,
            );
            self::assertSame(
                12_345,
                $totals->company['source_amount_minor'],
            );
            self::assertSame(
                9_345,
                array_column(
                    $totals->liabilities,
                    'amount_minor',
                    'liability_kind',
                )['net_wage'],
            );

            try {
                $service->forApprovedRevision(
                    $otherSupplierId,
                    $revisionId,
                );
                self::fail('Cizí firma nesmí načíst kontrolní součty revize.');
            } catch (\DomainException $exception) {
                self::assertStringContainsString(
                    'neexistuje',
                    $exception->getMessage(),
                );
            }

            $badHashRevision = $this->insertRevision(
                $pdo,
                $supplierId,
                'approved',
                true,
            );
            try {
                $service->forApprovedRevision(
                    $supplierId,
                    $badHashRevision,
                );
                self::fail('Nesprávný hash musí výpočet uzavřít chybou.');
            } catch (\DomainException $exception) {
                self::assertStringContainsString(
                    'Otisk',
                    $exception->getMessage(),
                );
            }

            $reviewedRevision = $this->insertRevision(
                $pdo,
                $supplierId,
                'reviewed',
                false,
            );
            $this->expectException(\DomainException::class);
            $this->expectExceptionMessage('schválena');
            $service->forApprovedRevision($supplierId, $reviewedRevision);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $connection->close();
        }
    }

    private function insertRevision(
        PDO $pdo,
        int $supplierId,
        string $status,
        bool $badHash,
    ): int {
        $pdo->prepare(
            'INSERT INTO payroll_offices
                (supplier_id, code, name, is_active)
             VALUES (?, ?, "Syntetická kontrolní účtárna", 1)',
        )->execute([
            $supplierId,
            'CT' . bin2hex(random_bytes(3)),
        ]);
        $officeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, office_id, period_start, payment_date, status)
             VALUES (?, ?, ?, ?, ?)',
        )->execute([
            $supplierId,
            $officeId,
            '2098-01-01',
            '2098-02-15',
            $status === 'approved' ? 'approved' : 'reviewed',
        ]);
        $runId = (int) $pdo->lastInsertId();
        $employeeId = 501;
        $employmentId = 601;
        $input = [
            'schema_version' => 'payroll-run-input.v2',
            'people' => [[
                'employee' => ['id' => $employeeId],
                'employments' => [[
                    'employment' => [
                        'id' => $employmentId,
                        'office_id' => $officeId,
                    ],
                    'inputs' => [],
                ]],
            ]],
        ];
        $metrics = [
            'source_amount_minor' => 12_345,
            'cash_payable_minor' => 12_345,
            'tax_base_minor' => 12_345,
            'social_base_minor' => 12_345,
            'health_base_minor' => 12_345,
            'average_earning_base_minor' => 12_345,
            'enforcement_base_minor' => 12_345,
            'jmhz_amount_minor' => 12_345,
        ];
        $result = [
            'schema_version' => 'payroll-run-result.v2',
            'source_snapshot_hash' => hash(
                'sha256',
                CanonicalJson::encode($input),
            ),
            'people' => [[
                'employee_id' => $employeeId,
                'employments' => [[
                    'employment_id' => $employmentId,
                    'inputs' => [[
                        'input_id' => 1,
                        'accounting' => [
                            'debit_code' => '521',
                            'credit_code' => '331',
                            'amount_minor' => 12_345,
                        ],
                    ]],
                    'totals' => $metrics,
                ]],
                'totals' => $metrics,
                'statutory' => [
                    'person_reference' => "employee:{$employeeId}",
                    'status' => 'calculated',
                    'social_insurance' => [
                        'employee_contribution_minor_units' => 1_000,
                    ],
                    'health_insurance' => [
                        'employee_contribution_minor_units' => 500,
                        'employer_contribution_minor_units' => 1_000,
                        'total_contribution_minor_units' => 1_500,
                    ],
                    'income_tax' => [
                        'advance_tax' => [
                            'tax_after_credits_minor_units' => 1_000,
                        ],
                        'withholding_tax_minor_units' => 0,
                    ],
                    'net_pay' => [
                        'person_reference' => "employee:{$employeeId}",
                        'relationships' => [[
                            'relationship_reference'
                                => "employment:{$employmentId}",
                            'cash_income_minor_units' => 12_345,
                            'non_cash_income_minor_units' => 0,
                        ]],
                        'cash_income_minor_units' => 12_345,
                        'non_cash_income_minor_units' => 0,
                        'employee_social_minor_units' => 1_000,
                        'employee_health_minor_units' => 500,
                        'advance_tax_minor_units' => 1_000,
                        'withholding_tax_minor_units' => 0,
                        'tax_bonus_minor_units' => 0,
                        'correction_minor_units' => 0,
                        'net_before_deductions_minor_units' => 9_845,
                        'deducted_minor_units' => 500,
                        'net_payable_minor_units' => 9_345,
                        'deductions' => [[
                            'applied_minor_units' => 500,
                        ]],
                    ],
                    'net_payable_minor_units' => 9_345,
                ],
            ]],
            'totals' => $metrics,
            'accounting_totals' => [[
                'debit_code' => '521',
                'credit_code' => '331',
                'amount_minor' => 12_345,
            ]],
            'statutory' => [
                'status' => 'calculated',
                'employer_social_minor_units' => 2_000,
            ],
        ];
        $inputJson = CanonicalJson::encode($input);
        $resultJson = CanonicalJson::encode($result);
        $resultHash = $badHash
            ? str_repeat('f', 64)
            : hash('sha256', $resultJson);
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json,
                 result_snapshot_hash, idempotency_key_hash, approved_at)
             VALUES (?, ?, 1, ?, "payroll-run-input.v2", ?, ?, ?, ?, ?, ?, ?)',
        )->execute([
            $supplierId,
            $runId,
            $status,
            str_repeat('a', 64),
            $inputJson,
            hash('sha256', $inputJson),
            $resultJson,
            $resultHash,
            hash(
                'sha256',
                "{$supplierId}:{$runId}:{$status}:{$badHash}",
                true,
            ),
            $status === 'approved' ? '2098-02-01 10:00:00' : null,
        ]);
        return (int) $pdo->lastInsertId();
    }
}
