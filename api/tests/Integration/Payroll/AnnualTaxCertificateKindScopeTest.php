<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Document\AnnualTaxCertificateSnapshotBuilder;
use MyInvoice\Service\Payroll\Document\PayrollDocumentKind;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Roční potvrzení se vydává na DRUH příjmu, ne na osobu: kdo měl celý rok jen
 * zálohovou daň, srážkové potvrzení nedostane, a naopak. Sada drží dvě věci,
 * které se dají rozbít nezávisle na sobě:
 *
 *  1. částky na potvrzení = přesný součet zmrazených měsíčních revizí,
 *     a to i u člověka, který v roce nastoupil i skončil;
 *  2. odmítnutí nesprávného druhu má hlášku o CHYBĚJÍCÍM PŘÍJMU. Dřív se
 *     v tomhle případě jako první rozsvítila kontrola daňové rezidence
 *     („Daňové potvrzení nemá doloženou daňovou rezidenci."), protože
 *     rezidence se plní jen z měsíců, které do potvrzení vstoupily — a
 *     u špatného druhu nevstoupil žádný. Účetní pak hledala neexistující
 *     vadu v evidenci osoby místo toho, aby vybrala druhý tiskopis.
 */
#[Group('integration')]
final class AnnualTaxCertificateKindScopeTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const NET_MINOR = 3_193_000;

    public function testPartialYearAdvanceCertificateSumsFrozenRevisions(): void
    {
        [$connection, $builder, $supplierId, $employeeId] = $this->fixture(
            range(3, 8),
        );
        try {
            $prepared = $builder->build(
                $supplierId,
                $employeeId,
                2026,
                PayrollDocumentKind::TaxableIncomeAdvanceCertificate,
                null,
            );
            $document = $prepared['document'];

            self::assertSame([3, 4, 5, 6, 7, 8], $document->months);
            // 6 x 40 000 Kč hrubého, 6 x 3 430 Kč skutečně sražené zálohy.
            self::assertSame(24_000_000, $document->accruedIncomeMinorUnits);
            // Řádky 1 a 2 tiskopisu se shodují právě proto, že brána § 5 odst. 4
            // nepustí měsíc, který nebyl doložen jako uhrazený do 31. ledna.
            self::assertSame(
                $document->accruedIncomeMinorUnits,
                $document->paidIncomeMinorUnits,
            );
            self::assertSame(2_058_000, $document->advanceTaxMinorUnits);
            self::assertSame(0, $document->withholdingTaxMinorUnits);
            self::assertSame(0, $document->taxBonusMinorUnits);
            self::assertSame('2027-01-31', $document->paymentEvidenceCutoff);
            self::assertSame('2026-09-15', $document->lastProvenPaymentDate);
            self::assertNull($document->nonresidentInsuranceMinorUnits);
        } finally {
            $this->rollback($connection);
        }
    }

    public function testWithholdingCertificateNamesTheMissingIncomeNotResidence(): void
    {
        [$connection, $builder, $supplierId, $employeeId] = $this->fixture([1]);
        try {
            $this->expectException(\DomainException::class);
            $this->expectExceptionMessage(
                'neexistuje doložený zdanitelný příjem',
            );

            $builder->build(
                $supplierId,
                $employeeId,
                2026,
                PayrollDocumentKind::TaxableIncomeWithholdingCertificate,
                null,
            );
        } finally {
            $this->rollback($connection);
        }
    }

    /**
     * @param list<int> $months
     * @return array{
     *   Connection,
     *   AnnualTaxCertificateSnapshotBuilder,
     *   int,
     *   int
     * }
     */
    private function fixture(array $months): array
    {
        $container = Bootstrap::buildContainer();
        $connection = $container->get(Connection::class);
        $builder = $container->get(AnnualTaxCertificateSnapshotBuilder::class);
        $sensitive = $container->get(PayrollSensitiveData::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(
            AnnualTaxCertificateSnapshotBuilder::class,
            $builder,
        );
        self::assertInstanceOf(PayrollSensitiveData::class, $sensitive);
        if (!$connection->hasTable('payroll_payment_matches')) {
            $this->markTestSkipped('Migrace platební evidence mezd neproběhla.');
        }
        $pdo = $connection->pdo();
        $sourceSupplierId = (int) $pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1',
        )->fetchColumn();
        self::assertGreaterThan(0, $sourceSupplierId);
        $pdo->beginTransaction();
        try {
            [$supplierId, $employeeId] = $this->createPerson(
                $pdo,
                $sourceSupplierId,
                $sensitive,
                sprintf('2026-%02d-01', $months[0]),
            );
            foreach ($months as $month) {
                $this->createApprovedMonth(
                    $pdo,
                    $supplierId,
                    $employeeId,
                    $month,
                );
            }

            return [$connection, $builder, $supplierId, $employeeId];
        } catch (\Throwable $exception) {
            $this->rollback($connection);
            throw $exception;
        }
    }

    /** @return array{int,int} */
    private function createPerson(
        PDO $pdo,
        int $sourceSupplierId,
        PayrollSensitiveData $sensitive,
        string $effectiveFrom,
    ): array {
        $supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $countryId = (int) $pdo->query(
            'SELECT id FROM countries WHERE iso2 = "CZ" LIMIT 1',
        )->fetchColumn();
        self::assertGreaterThan(0, $countryId);
        $pdo->prepare(
            'UPDATE supplier
                SET company_name = "Syntetická společnost",
                    display_name = "Syntetický zaměstnavatel",
                    ic = "12345678", dic = "CZ12345678",
                    street = "Testovací 12", city = "Testov", zip = "10000",
                    country_id = ?, email = "firma@example.invalid",
                    phone = "+420 222 000 001"
              WHERE id = ?',
        )->execute([$countryId, $supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_offices (supplier_id, code, name, is_active)
             VALUES (?, "KIND", "Syntetická účtárna", 1)',
        )->execute([$supplierId]);
        $officeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employer_settings
                (supplier_id, default_office_id, payroll_contact_name,
                 payroll_contact_email, payroll_contact_phone)
             VALUES (?, ?, "Syntetická mzdová účetní",
                     "mzdy@example.invalid", "+420 777 000 001")',
        )->execute([$supplierId, $officeId]);
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetická osoba", "employee", 1)',
        )->execute([$supplierId]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_person_identity_history
                (supplier_id, employee_id, full_name, first_name, last_name,
                 birth_surname, effective_from)
             VALUES (?, ?, "Syntetická osoba", "Syntetická", "Osoba",
                     "Dřívější Syntetická", ?)',
        )->execute([$supplierId, $employeeId, $effectiveFrom]);
        $pdo->prepare(
            'INSERT INTO payroll_person_addresses
                (supplier_id, employee_id, address_type, street_line, city,
                 postal_code, country_code, effective_from)
             VALUES (?, ?, "residence", "Modelová 2", "Brno", "602 00",
                     "CZ", ?)',
        )->execute([$supplierId, $employeeId, $effectiveFrom]);
        $pdo->prepare(
            'INSERT INTO payroll_person_identifiers
                (supplier_id, employee_id, identifier_type, value_ciphertext,
                 value_hash, value_masked)
             VALUES (?, ?, "birth_number", "enc:v2:synthetic", ?, "••••0009")',
        )->execute([$supplierId, $employeeId, random_bytes(32)]);
        $identifierId = (int) $pdo->lastInsertId();
        $sealed = $sensitive->seal(
            '0001010009',
            PayrollSensitiveField::PERSONAL_IDENTIFIER,
            $supplierId,
            $identifierId,
        );
        $pdo->prepare(
            'UPDATE payroll_person_identifiers
                SET value_ciphertext = ?, value_hash = ?, value_masked = ?
              WHERE supplier_id = ? AND id = ?',
        )->execute([
            $sealed->ciphertext,
            $sealed->lookupHash,
            $sealed->masked,
            $supplierId,
            $identifierId,
        ]);

        return [$supplierId, $employeeId];
    }

    private function createApprovedMonth(
        PDO $pdo,
        int $supplierId,
        int $employeeId,
        int $month,
    ): void {
        $periodStart = sprintf('2026-%02d-01', $month);
        $paymentDate = (new \DateTimeImmutable($periodStart))
            ->modify('+1 month +14 days')
            ->format('Y-m-d');
        $person = [
            'employee_id' => $employeeId,
            'statutory' => [
                'status' => 'calculated',
                'income_tax' => [
                    'status' => 'calculated',
                    'advance_tax' => [
                        'taxable_income_minor_units' => 4_000_000,
                    ],
                    'withholding_base_minor_units' => 0,
                    'withholding_tax_minor_units' => 0,
                ],
                'net_pay' => [
                    'non_cash_income_minor_units' => 0,
                    'advance_tax_minor_units' => 343_000,
                    'withholding_tax_minor_units' => 0,
                    'tax_bonus_minor_units' => 0,
                ],
                'social_insurance' => [
                    'employee_contribution_minor_units' => 284_000,
                ],
                'health_insurance' => [
                    'employee_contribution_minor_units' => 180_000,
                ],
            ],
            'payable_after_enforcement_minor' => self::NET_MINOR,
        ];
        $input = [
            'schema_version' => 'payroll-run-input.v2',
            'people' => [[
                'employee' => ['id' => $employeeId],
                'statutory_evidence' => [
                    'income_tax' => [
                        'declaration' => [
                            'status' => 'signed',
                            'effective_from' => '2026-01-01',
                            'effective_to' => '2026-12-31',
                        ],
                        'residence' => [
                            'residence' => 'czech-resident',
                            'country_code' => 'CZ',
                        ],
                        'credit_claims' => [[
                            'credit_kind' => 'taxpayer',
                            'evidence_status' => 'verified',
                        ]],
                        'child_claims' => [],
                    ],
                ],
                'employments' => [[
                    'inputs' => [[
                        'amount_minor' => 4_000_000,
                        'component' => ['kind' => 'monthly_wage'],
                    ]],
                ]],
            ]],
        ];
        $result = [
            'schema_version' => 'payroll-run-result.v2',
            'people' => [$person],
        ];
        $inputJson = CanonicalJson::encode($input);
        $resultJson = CanonicalJson::encode($result);
        $personJson = CanonicalJson::encode($person);

        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status,
                 current_revision_no)
             VALUES (?, ?, ?, "approved", 1)',
        )->execute([$supplierId, $periodStart, $paymentDate]);
        $runId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json,
                 result_snapshot_hash, idempotency_key_hash, approved_at)
             VALUES (?, ?, 1, "approved", "payroll-run-input.v2",
                     ?, ?, ?, ?, ?, ?, NOW())',
        )->execute([
            $supplierId,
            $runId,
            str_repeat('a', 64),
            $inputJson,
            hash('sha256', $inputJson),
            $resultJson,
            hash('sha256', $resultJson),
            random_bytes(32),
        ]);
        $revisionId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, employee_id, result_json,
                 result_hash, status)
             VALUES (?, ?, ?, ?, ?, "calculated")',
        )->execute([
            $supplierId,
            $revisionId,
            $employeeId,
            $personJson,
            hash('sha256', $personJson),
        ]);
        $this->settleNetWage(
            $pdo,
            $supplierId,
            $employeeId,
            $revisionId,
            $paymentDate,
        );
    }

    private function settleNetWage(
        PDO $pdo,
        int $supplierId,
        int $employeeId,
        int $revisionId,
        string $paymentDate,
    ): void {
        $snapshot = '{"schema":"synthetic-liability.v1"}';
        $reference = 'net-wage.' . bin2hex(random_bytes(6));
        $pdo->prepare(
            'INSERT INTO payroll_payment_liabilities
                (supplier_id, revision_id, employee_id, liability_reference,
                 liability_kind, direction, recipient_reference, due_on,
                 currency_code, amount_minor, source_snapshot_json,
                 source_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, ?, ?, "net_wage", "outgoing", "recipient:synthetic",
                     ?, "CZK", ?, ?, ?, ?)',
        )->execute([
            $supplierId,
            $revisionId,
            $employeeId,
            $reference,
            $paymentDate,
            self::NET_MINOR,
            $snapshot,
            hash('sha256', $snapshot),
            random_bytes(32),
        ]);
        $liabilityId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_payment_batches
                (supplier_id, batch_reference, channel, export_format,
                 direction, planned_payment_date, currency_code,
                 payer_reference, declared_total_minor, declared_item_count,
                 snapshot_ciphertext, snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, "bank", "manual", "outgoing", ?, "CZK",
                     "payer:synthetic", ?, 1, ?, ?, ?)',
        )->execute([
            $supplierId,
            "{$reference}-batch",
            $paymentDate,
            self::NET_MINOR,
            'enc:v2:synthetic-batch',
            hash('sha256', "{$reference}-batch"),
            random_bytes(32),
        ]);
        $batchId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_payment_items
                (supplier_id, batch_id, item_reference, recipient_reference,
                 amount_minor, instruction_ciphertext, instruction_hash,
                 idempotency_key_hash)
             VALUES (?, ?, ?, "recipient:synthetic", ?, ?, ?, ?)',
        )->execute([
            $supplierId,
            $batchId,
            "{$reference}-item",
            self::NET_MINOR,
            'enc:v2:synthetic-instruction',
            hash('sha256', "{$reference}-item"),
            random_bytes(32),
        ]);
        $itemId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_payment_allocations
                (supplier_id, item_id, liability_id, amount_minor,
                 idempotency_key_hash)
             VALUES (?, ?, ?, ?, ?)',
        )->execute([
            $supplierId,
            $itemId,
            $liabilityId,
            self::NET_MINOR,
            random_bytes(32),
        ]);
        $allocationId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO bank_statements
                (supplier_id, file_name, file_hash, account_number,
                 bank_code, currency, statement_date)
             VALUES (?, ?, ?, "1000000005", "0100", "CZK", ?)',
        )->execute([
            $supplierId,
            "{$reference}.gpc",
            hash('sha256', $reference),
            $paymentDate,
        ]);
        $statementId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, description,
                 import_fingerprint)
             VALUES (?, ?, ?, "CZK", "Syntetická mzdová úhrada", ?)',
        )->execute([
            $statementId,
            $paymentDate,
            number_format(-self::NET_MINOR / 100, 2, '.', ''),
            hash('sha256', "{$reference}-tx"),
        ]);
        $transactionId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_payment_matches
                (supplier_id, allocation_id, event_kind, amount_minor,
                 bank_statement_id, bank_transaction_id, idempotency_key_hash)
             VALUES (?, ?, "matched", ?, ?, ?, ?)',
        )->execute([
            $supplierId,
            $allocationId,
            self::NET_MINOR,
            $statementId,
            $transactionId,
            random_bytes(32),
        ]);
    }

    private function rollback(Connection $connection): void
    {
        if ($connection->pdo()->inTransaction()) {
            $connection->pdo()->rollBack();
        }
        $connection->close();
    }
}
