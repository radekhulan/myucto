<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Document\AnnualTaxCertificateDocumentData;
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
 * Jednatel-společník, jehož čistá odměna se nevyplácí, ale započítává na účet
 * ke společníkům (331/366 → 365). Závazek čisté mzdy pro započtenou část
 * záměrně nevzniká, takže potvrzení o zdanitelných příjmech nesmí chtít
 * spárovanou platbu — důkazem je zaúčtovaný mzdový předpis revize a datem
 * vypořádání datum jeho deníkového zápisu.
 *
 * Mezní datum § 5 odst. 4 ZDP (31. ledna následujícího roku) platí pro zápočet
 * stejně jako pro úhradu a nezaúčtovaný zápočet není doložený.
 */
#[Group('integration')]
final class AnnualTaxCertificatePartnerSettlementTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const NET_MINOR = 3_193_000;
    private const BANK_PART_MINOR = 2_000_000;
    private const SETTLEMENT_ACCOUNT = '365.100';

    public function testFullOffsetToPartnerAccountIssuesCertificate(): void
    {
        $fixture = $this->fixture();
        try {
            foreach ([1, 2, 3] as $month) {
                $revision = $this->approvedMonth($fixture, $month, false);
                $this->postSettlement(
                    $fixture,
                    $revision,
                    self::NET_MINOR,
                    self::monthEnd($month),
                );
            }

            $document = $this->buildAdvanceCertificate($fixture);

            self::assertSame([1, 2, 3], $document->months);
            self::assertSame(12_000_000, $document->accruedIncomeMinorUnits);
            self::assertSame(
                $document->accruedIncomeMinorUnits,
                $document->paidIncomeMinorUnits,
            );
            self::assertSame(1_029_000, $document->advanceTaxMinorUnits);
            self::assertSame('2026-03-31', $document->lastProvenPaymentDate);
        } finally {
            $this->rollback($fixture['connection']);
        }
    }

    public function testBankPartAndOffsetTogetherCoverTheNetPay(): void
    {
        $fixture = $this->fixture();
        try {
            $revision = $this->approvedMonth($fixture, 1, true);
            $this->settleBankPart($fixture, $revision, '2026-02-12');
            $this->postSettlement(
                $fixture,
                $revision,
                self::NET_MINOR - self::BANK_PART_MINOR,
                '2026-01-31',
            );

            $document = $this->buildAdvanceCertificate($fixture);

            self::assertSame([1], $document->months);
            self::assertSame(4_000_000, $document->paidIncomeMinorUnits);
            self::assertSame('2026-02-12', $document->lastProvenPaymentDate);
        } finally {
            $this->rollback($fixture['connection']);
        }
    }

    /**
     * Opravná revize se stejným cílovým účetním stavem dostane dávku
     * `no_change` bez deníkového zápisu. Zápočet propsal do účetnictví
     * předchozí zaúčtovaný předpis, takže datem je datum JEHO zápisu.
     */
    public function testNoChangeCorrectionUsesThePreviousPostedEntryDate(): void
    {
        $fixture = $this->fixture();
        try {
            $first = $this->approvedMonth($fixture, 1, false);
            $this->postSettlement(
                $fixture,
                $first,
                self::NET_MINOR,
                '2026-01-31',
            );
            $this->noChangeCorrection($fixture, $first);

            $document = $this->buildAdvanceCertificate($fixture);

            self::assertSame([1], $document->months);
            self::assertSame('2026-01-31', $document->lastProvenPaymentDate);
        } finally {
            $this->rollback($fixture['connection']);
        }
    }

    public function testBankPartAloneDoesNotProveTheMixedMonth(): void
    {
        $fixture = $this->fixture();
        try {
            $revision = $this->approvedMonth($fixture, 1, true);
            $this->settleBankPart($fixture, $revision, '2026-02-12');

            $this->expectException(\DomainException::class);
            $this->expectExceptionMessage('není zaúčtovaný');

            $this->buildAdvanceCertificate($fixture);
        } finally {
            $this->rollback($fixture['connection']);
        }
    }

    public function testUnpostedOffsetFailsClosed(): void
    {
        $fixture = $this->fixture();
        try {
            $this->approvedMonth($fixture, 1, false);

            $this->expectException(\DomainException::class);
            $this->expectExceptionMessage('není zaúčtovaný');

            $this->buildAdvanceCertificate($fixture);
        } finally {
            $this->rollback($fixture['connection']);
        }
    }

    public function testPostedOffsetWithDifferentAmountFailsClosed(): void
    {
        $fixture = $this->fixture();
        try {
            $revision = $this->approvedMonth($fixture, 1, false);
            $this->postSettlement(
                $fixture,
                $revision,
                self::NET_MINOR - 100,
                '2026-01-31',
            );

            $this->expectException(\DomainException::class);
            $this->expectExceptionMessage('jinou částku nebo účet');

            $this->buildAdvanceCertificate($fixture);
        } finally {
            $this->rollback($fixture['connection']);
        }
    }

    public function testOffsetPostedAfterJanuaryCutoffFailsClosed(): void
    {
        $fixture = $this->fixture();
        try {
            $revision = $this->approvedMonth($fixture, 12, false);
            $this->postSettlement(
                $fixture,
                $revision,
                self::NET_MINOR,
                '2027-02-01',
            );

            $this->expectException(\DomainException::class);
            $this->expectExceptionMessage('po 31. 1. 2027');

            $this->buildAdvanceCertificate($fixture);
        } finally {
            $this->rollback($fixture['connection']);
        }
    }

    /**
     * @param array{
     *   connection:Connection,
     *   builder:AnnualTaxCertificateSnapshotBuilder,
     *   supplier_id:int,
     *   employee_id:int
     * } $fixture
     */
    private function buildAdvanceCertificate(
        array $fixture,
    ): AnnualTaxCertificateDocumentData {
        $prepared = $fixture['builder']->build(
            $fixture['supplier_id'],
            $fixture['employee_id'],
            2026,
            PayrollDocumentKind::TaxableIncomeAdvanceCertificate,
            null,
        );

        return $prepared['document'];
    }

    /**
     * @return array{
     *   connection:Connection,
     *   builder:AnnualTaxCertificateSnapshotBuilder,
     *   supplier_id:int,
     *   employee_id:int
     * }
     */
    private function fixture(): array
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $connection = $container->get(Connection::class);
        $builder = $container->get(AnnualTaxCertificateSnapshotBuilder::class);
        $sensitive = $container->get(PayrollSensitiveData::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(
            AnnualTaxCertificateSnapshotBuilder::class,
            $builder,
        );
        self::assertInstanceOf(PayrollSensitiveData::class, $sensitive);
        foreach (['payroll_payment_matches', 'payroll_posting_batches'] as $table) {
            if (!$connection->hasTable($table)) {
                $this->markTestSkipped("Chybí tabulka {$table}.");
            }
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
            );

            return [
                'connection' => $connection,
                'builder' => $builder,
                'supplier_id' => $supplierId,
                'employee_id' => $employeeId,
            ];
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
             VALUES (?, "SPOL", "Syntetická účtárna", 1)',
        )->execute([$supplierId]);
        $officeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employer_settings
                (supplier_id, default_office_id, payroll_contact_name,
                 payroll_contact_email, payroll_contact_phone)
             VALUES (?, ?, "Syntetická mzdová účetní",
                     "mzdy@example.invalid", "+420 777 000 001")',
        )->execute([$supplierId, $officeId]);
        foreach ([2026, 2027] as $year) {
            $pdo->prepare(
                'INSERT INTO accounting_periods
                    (supplier_id, fiscal_year, starts_on, ends_on, status)
                 VALUES (?, ?, ?, ?, "open")',
            )->execute([
                $supplierId,
                $year,
                "{$year}-01-01",
                "{$year}-12-31",
            ]);
        }
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetický Společník", "employee", 1)',
        )->execute([$supplierId]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_person_identity_history
                (supplier_id, employee_id, full_name, first_name, last_name,
                 birth_surname, effective_from)
             VALUES (?, ?, "Syntetický Společník", "Syntetický", "Společník",
                     "Syntetický", "2026-01-01")',
        )->execute([$supplierId, $employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_person_addresses
                (supplier_id, employee_id, address_type, street_line, city,
                 postal_code, country_code, effective_from)
             VALUES (?, ?, "residence", "Modelová 2", "Brno", "602 00",
                     "CZ", "2026-01-01")',
        )->execute([$supplierId, $employeeId]);
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

    /**
     * Zmrazená schválená revize měsíce. Výplatní pravidla jsou ve vstupu
     * revize stejně jako u skutečného běhu: buď celá čistá odměna zápočtem,
     * nebo pevná část na bankovní účet a zbytek zápočtem.
     *
     * @param array{connection:Connection,supplier_id:int,employee_id:int} $fixture
     * @return array{run_id:int,revision_id:int,period_start:string}
     */
    private function approvedMonth(
        array $fixture,
        int $month,
        bool $withBankPart,
    ): array {
        $pdo = $fixture['connection']->pdo();
        $supplierId = $fixture['supplier_id'];
        $employeeId = $fixture['employee_id'];
        $periodStart = sprintf('2026-%02d-01', $month);
        $paymentDate = (new \DateTimeImmutable($periodStart))
            ->modify('+1 month +11 days')
            ->format('Y-m-d');
        $payoutRules = [];
        if ($withBankPart) {
            $payoutRules[] = [
                'allocation_reference' => 'BANK-PART',
                'destination_kind' => 'bank',
                'destination_reference' => 'payout-account:synthetic',
                'allocation_kind' => 'fixed',
                'amount_minor' => self::BANK_PART_MINOR,
                'priority_no' => 10,
            ];
        }
        $payoutRules[] = [
            'allocation_reference' => 'PARTNER-SETTLEMENT',
            'destination_kind' => 'partner_settlement',
            'destination_reference' => self::SETTLEMENT_ACCOUNT,
            'allocation_kind' => 'remainder',
            'priority_no' => 100,
        ];
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
                'payout_rules' => $payoutRules,
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
                    'employment' => ['relation_type' => 'partner_dependent'],
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

        return [
            'run_id' => $runId,
            'revision_id' => $revisionId,
            'period_start' => $periodStart,
        ];
    }

    /**
     * Zaúčtovaný mzdový předpis revize: dávka s cílovou alokací zápočtu
     * (klíč a znaménko jako v PayrollPostingLineBuilder) a deníkový zápis.
     *
     * @param array{connection:Connection,supplier_id:int,employee_id:int} $fixture
     * @param array{run_id:int,revision_id:int,period_start:string} $revision
     */
    private function postSettlement(
        array $fixture,
        array $revision,
        int $amountMinor,
        string $entryDate,
    ): void {
        $pdo = $fixture['connection']->pdo();
        $supplierId = $fixture['supplier_id'];
        $periodId = (int) $pdo->query(
            'SELECT id FROM accounting_periods
              WHERE supplier_id = ' . $supplierId . '
                AND fiscal_year = ' . (int) substr($entryDate, 0, 4),
        )->fetchColumn();
        self::assertGreaterThan(0, $periodId);
        $pdo->prepare(
            'INSERT INTO payroll_posting_batches
                (supplier_id, run_id, revision_id, entry_date, status,
                 target_hash, delta_hash)
             VALUES (?, ?, ?, ?, "prepared", ?, ?)',
        )->execute([
            $supplierId,
            $revision['run_id'],
            $revision['revision_id'],
            $entryDate,
            hash('sha256', "target-{$revision['revision_id']}"),
            hash('sha256', "delta-{$revision['revision_id']}"),
        ]);
        $batchId = (int) $pdo->lastInsertId();
        $baseKey = sprintf(
            'employee:%d:partner-settlement:%s',
            $fixture['employee_id'],
            hash('sha256', 'PARTNER-SETTLEMENT'),
        );
        $insert = $pdo->prepare(
            'INSERT INTO payroll_posting_allocations
                (supplier_id, batch_id, allocation_key, account_code,
                 signed_minor, description)
             VALUES (?, ?, ?, ?, ?, "Zápočet čisté mzdy na účet společníka")',
        );
        $insert->execute([
            $supplierId,
            $batchId,
            "{$baseKey}:settlement:employment",
            '366',
            $amountMinor,
        ]);
        $insert->execute([
            $supplierId,
            $batchId,
            "{$baseKey}:liability",
            self::SETTLEMENT_ACCOUNT,
            -$amountMinor,
        ]);
        $pdo->prepare(
            'INSERT INTO journal_entries
                (supplier_id, period_id, entry_date, document_no, description,
                 source_type, source_id, posted_at)
             VALUES (?, ?, ?, ?, "Syntetický mzdový předpis", "payroll", ?,
                     NOW())',
        )->execute([
            $supplierId,
            $periodId,
            $entryDate,
            'MZ-SYN-' . $revision['revision_id'],
            $revision['revision_id'],
        ]);
        $entryId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'UPDATE payroll_posting_batches
                SET status = "posted", journal_entry_id = ?, posted_at = NOW()
              WHERE supplier_id = ? AND id = ?',
        )->execute([$entryId, $supplierId, $batchId]);
    }

    /**
     * Druhá schválená revize téhož běhu se shodnými snapshoty; její účetní
     * dávka nic nemění (`no_change`) a odkazuje na zaúčtovanou dávku první.
     *
     * @param array{connection:Connection,supplier_id:int,employee_id:int} $fixture
     * @param array{run_id:int,revision_id:int,period_start:string} $first
     */
    private function noChangeCorrection(array $fixture, array $first): void
    {
        $pdo = $fixture['connection']->pdo();
        $supplierId = $fixture['supplier_id'];
        $pdo->prepare(
            'UPDATE payroll_run_revisions
                SET status = "superseded", superseded_at = NOW()
              WHERE supplier_id = ? AND id = ?',
        )->execute([$supplierId, $first['revision_id']]);
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json,
                 result_snapshot_hash, idempotency_key_hash, approved_at)
             SELECT supplier_id, run_id, 2, "approved", schema_version,
                    ruleset_manifest_hash, input_snapshot_json,
                    input_snapshot_hash, result_snapshot_json,
                    result_snapshot_hash, ?, NOW()
               FROM payroll_run_revisions
              WHERE supplier_id = ? AND id = ?',
        )->execute([random_bytes(32), $supplierId, $first['revision_id']]);
        $secondId = (int) $pdo->lastInsertId();
        $person = $pdo->prepare(
            'SELECT employee_id, result_json, result_hash
               FROM payroll_run_persons
              WHERE supplier_id = ? AND revision_id = ?',
        );
        $person->execute([$supplierId, $first['revision_id']]);
        $personRow = $person->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($personRow);
        $pdo->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, employee_id, result_json,
                 result_hash, status)
             VALUES (?, ?, ?, ?, ?, "calculated")',
        )->execute([
            $supplierId,
            $secondId,
            (int) $personRow['employee_id'],
            $personRow['result_json'],
            $personRow['result_hash'],
        ]);
        $pdo->prepare(
            'UPDATE payroll_runs SET current_revision_no = 2
              WHERE supplier_id = ? AND id = ?',
        )->execute([$supplierId, $first['run_id']]);
        $previousBatchId = (int) $pdo->query(
            'SELECT id FROM payroll_posting_batches
              WHERE supplier_id = ' . $supplierId . '
                AND revision_id = ' . $first['revision_id'],
        )->fetchColumn();
        $pdo->prepare(
            'INSERT INTO payroll_posting_batches
                (supplier_id, run_id, revision_id, previous_batch_id,
                 entry_date, status, target_hash, delta_hash)
             VALUES (?, ?, ?, ?, "2026-03-31", "prepared", ?, ?)',
        )->execute([
            $supplierId,
            $first['run_id'],
            $secondId,
            $previousBatchId,
            hash('sha256', "target-{$first['revision_id']}"),
            hash('sha256', "delta-{$secondId}"),
        ]);
        $batchId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_posting_allocations
                (supplier_id, batch_id, allocation_key, account_code,
                 signed_minor, description)
             SELECT supplier_id, ?, allocation_key, account_code,
                    signed_minor, description
               FROM payroll_posting_allocations
              WHERE supplier_id = ? AND batch_id = ?',
        )->execute([$batchId, $supplierId, $previousBatchId]);
        $pdo->prepare(
            'UPDATE payroll_posting_batches
                SET status = "no_change", posted_at = NOW()
              WHERE supplier_id = ? AND id = ?',
        )->execute([$supplierId, $batchId]);
    }

    /**
     * Bankovní část čisté mzdy: závazek `net_wage` se spárovanou úhradou.
     *
     * @param array{connection:Connection,supplier_id:int,employee_id:int} $fixture
     * @param array{run_id:int,revision_id:int,period_start:string} $revision
     */
    private function settleBankPart(
        array $fixture,
        array $revision,
        string $paymentDate,
    ): void {
        $pdo = $fixture['connection']->pdo();
        $supplierId = $fixture['supplier_id'];
        $amount = self::BANK_PART_MINOR;
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
            $revision['revision_id'],
            $fixture['employee_id'],
            $reference,
            $paymentDate,
            $amount,
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
            $amount,
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
            $amount,
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
            $amount,
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
            number_format(-$amount / 100, 2, '.', ''),
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
            $amount,
            $statementId,
            $transactionId,
            random_bytes(32),
        ]);
    }

    private static function monthEnd(int $month): string
    {
        return (new \DateTimeImmutable(sprintf('2026-%02d-01', $month)))
            ->format('Y-m-t');
    }

    private function rollback(Connection $connection): void
    {
        if ($connection->pdo()->inTransaction()) {
            $connection->pdo()->rollBack();
        }
        $connection->close();
    }
}
