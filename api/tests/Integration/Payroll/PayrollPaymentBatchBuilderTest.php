<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollPaymentBatchRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payment\CzechBankAccountValidator;
use MyInvoice\Service\Payment\IbanValidator;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentBatchBuilder;
use MyInvoice\Service\Payroll\PayrollProductionGate;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[Group('integration')]
final class PayrollPaymentBatchBuilderTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollPaymentBatchBuilder $builder;
    private SecretEncryption $encryption;
    private PayrollSensitiveData $sensitiveData;
    private int $supplierId;
    private int $actorId;
    private int $employeeId;
    private int $accountId;
    private int $accountRowVersion;
    private string $accountHash;
    private int $payerCurrencyId;
    private int $revisionId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $connection = $container->get(Connection::class);
        $encryption = $container->get(SecretEncryption::class);
        $sensitiveData = $container->get(PayrollSensitiveData::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(SecretEncryption::class, $encryption);
        self::assertInstanceOf(PayrollSensitiveData::class, $sensitiveData);
        $this->db = $connection;
        $this->encryption = $encryption;
        $this->sensitiveData = $sensitiveData;
        $pdo = $connection->pdo();
        $sourceSupplier = $pdo->query('SELECT MIN(id) FROM supplier');
        self::assertInstanceOf(\PDOStatement::class, $sourceSupplier);
        $sourceSupplierId = (int) $sourceSupplier->fetchColumn();
        self::assertGreaterThan(0, $sourceSupplierId);

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );
        $pdo->prepare(
            'INSERT INTO payroll_module_state
                (supplier_id, status, start_period, activated_by, activated_at)
             VALUES (?, "active", "2026-01-01", NULL, NOW())',
        )->execute([$this->supplierId]);
        $pdo->prepare(
            'UPDATE supplier
                SET company_name = "Syntetická mzdová firma",
                    display_name = NULL
              WHERE id = ?',
        )->execute([$this->supplierId]);
        $this->actorId = $this->createActor($pdo);
        $this->employeeId = $this->createEmployee($pdo);
        [
            $this->accountId,
            $this->accountRowVersion,
            $this->accountHash,
        ] = $this->createVerifiedAccount($pdo);
        $this->payerCurrencyId = $this->createPayerCurrency($pdo);
        $this->revisionId = $this->createApprovedRevision($pdo);
        $this->builder = new PayrollPaymentBatchBuilder(
            new PayrollPaymentBatchRepository($connection),
            $sensitiveData,
            $encryption,
            new IbanValidator(),
            new CzechBankAccountValidator(),
            new MockClock('2026-08-04 10:11:12 Europe/Prague'),
            $container->get(PayrollProductionGate::class),
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    public function testCreatesEncryptedPartialAboBatchIdempotently(): void
    {
        $liabilityId = $this->insertBankLiability(100_000);
        $request = [[
            'liability_id' => $liabilityId,
            'amount_minor' => 60_000,
        ]];

        $first = $this->builder->build(
            $this->supplierId,
            'abo',
            "currency:{$this->payerCurrencyId}",
            $request,
            $this->actorId,
        );
        $replayed = $this->builder->build(
            $this->supplierId,
            'abo',
            "currency:{$this->payerCurrencyId}",
            $request,
            $this->actorId,
        );

        self::assertTrue($first['created']);
        self::assertFalse($first['replayed']);
        self::assertFalse($replayed['created']);
        self::assertTrue($replayed['replayed']);
        self::assertSame(
            array_diff_key($first, ['created' => true, 'replayed' => true]),
            array_diff_key($replayed, ['created' => true, 'replayed' => true]),
        );
        self::assertSame(60_000, $first['declared_total_minor']);
        self::assertSame(1, $first['declared_item_count']);
        self::assertSame('2099-01-10', $first['planned_payment_date']);
        self::assertSame('CZK', $first['currency_code']);
        self::assertArrayNotHasKey('account_number', $first);
        self::assertArrayNotHasKey('instruction', $first);

        $stored = $this->batch($first['batch_id']);
        self::assertStringStartsWith(
            'enc:v2:',
            $this->stringValue($stored, 'snapshot_ciphertext'),
        );
        self::assertStringNotContainsString(
            '1000000005',
            $this->stringValue($stored, 'snapshot_ciphertext'),
        );
        self::assertSame(
            60_000,
            $this->integerValue($stored, 'declared_total_minor'),
        );
        self::assertSame(
            1,
            $this->integerValue($stored, 'declared_item_count'),
        );
        $snapshotJson = $this->encryption->decryptFor(
            $this->stringValue($stored, 'snapshot_ciphertext'),
            "payroll-payment-batch:{$this->supplierId}:"
                . $this->stringValue($stored, 'batch_reference'),
        );
        $snapshot = json_decode(
            $snapshotJson,
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($snapshot);
        self::assertSame(
            'Syntetická mzdová firma',
            $snapshot['payer_instruction']['account_holder_name']
                ?? null,
        );
        self::assertSame(
            '2026-08-04T08:11:12+00:00',
            $snapshot['creation_datetime'] ?? null,
        );

        $items = $this->items($first['batch_id']);
        self::assertCount(1, $items);
        self::assertSame(
            60_000,
            $this->integerValue($items[0], 'amount_minor'),
        );
        self::assertStringStartsWith(
            'enc:v2:',
            $this->stringValue($items[0], 'instruction_ciphertext'),
        );
        self::assertStringNotContainsString(
            '1000000005',
            $this->stringValue($items[0], 'instruction_ciphertext'),
        );
        $instruction = $this->encryption->decryptFor(
            $this->stringValue($items[0], 'instruction_ciphertext'),
            "payroll-payment-item:{$this->supplierId}:"
                . $this->stringValue($items[0], 'item_reference'),
        );
        self::assertStringContainsString('1000000005', $instruction);
        $instructionPayload = json_decode(
            $instruction,
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($instructionPayload);
        self::assertSame(
            'Syntetická platební osoba',
            $instructionPayload['recipient_name'] ?? null,
        );
        self::assertSame(
            $this->stringValue($items[0], 'instruction_hash'),
            hash('sha256', $instruction),
        );

        self::assertSame(
            60_000,
            $this->allocatedMinor($liabilityId),
        );
        self::assertSame(
            1,
            $this->countRows(
                'payroll_payment_batches',
                'supplier_id = ?',
                [$this->supplierId],
            ),
        );
    }

    public function testGroupsSameRecipientWithExactTotalsAndAllocations(): void
    {
        $firstLiabilityId = $this->insertBankLiability(60_000);
        $secondLiabilityId = $this->insertBankLiability(40_000);

        $result = $this->builder->build(
            $this->supplierId,
            'abo',
            "currency:{$this->payerCurrencyId}",
            [
                [
                    'liability_id' => $secondLiabilityId,
                    'amount_minor' => 40_000,
                ],
                [
                    'liability_id' => $firstLiabilityId,
                    'amount_minor' => 60_000,
                ],
            ],
            $this->actorId,
        );

        self::assertSame(100_000, $result['declared_total_minor']);
        self::assertSame(1, $result['declared_item_count']);
        $items = $this->items($result['batch_id']);
        self::assertCount(1, $items);
        self::assertSame(
            100_000,
            $this->integerValue($items[0], 'amount_minor'),
        );
        self::assertSame(
            60_000,
            $this->allocatedMinor($firstLiabilityId),
        );
        self::assertSame(
            40_000,
            $this->allocatedMinor($secondLiabilityId),
        );
    }

    public function testCompletesRemainingPartialAllocationAndRejectsOverflow(): void
    {
        $liabilityId = $this->insertBankLiability(100_000);
        $this->builder->build(
            $this->supplierId,
            'abo',
            "currency:{$this->payerCurrencyId}",
            [[
                'liability_id' => $liabilityId,
                'amount_minor' => 60_000,
            ]],
            $this->actorId,
        );
        $second = $this->builder->build(
            $this->supplierId,
            'abo',
            "currency:{$this->payerCurrencyId}",
            [[
                'liability_id' => $liabilityId,
                'amount_minor' => 40_000,
            ]],
            $this->actorId,
        );

        self::assertSame(40_000, $second['declared_total_minor']);
        self::assertSame(100_000, $this->allocatedMinor($liabilityId));

        try {
            $this->builder->build(
                $this->supplierId,
                'abo',
                "currency:{$this->payerCurrencyId}",
                [[
                    'liability_id' => $liabilityId,
                    'amount_minor' => 1,
                ]],
                $this->actorId,
            );
            self::fail('Plně alokovaný závazek nesmí přijmout další částku.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString(
                'otevřenou částku',
                $exception->getMessage(),
            );
        }
        self::assertSame(
            2,
            $this->countRows(
                'payroll_payment_batches',
                'supplier_id = ?',
                [$this->supplierId],
            ),
        );
    }

    public function testRejectsIncompleteOrChangedRecipientInstructionAtomically(): void
    {
        $liabilityId = $this->insertBankLiability(100_000);
        $this->db->pdo()->prepare(
            'UPDATE payroll_person_accounts
                SET effective_to = "2099-01-09",
                    row_version = row_version + 1
              WHERE supplier_id = ? AND employee_id = ? AND id = ?',
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $this->accountId,
        ]);

        try {
            $this->builder->build(
                $this->supplierId,
                'abo',
                "currency:{$this->payerCurrencyId}",
                [[
                    'liability_id' => $liabilityId,
                    'amount_minor' => 100_000,
                ]],
                $this->actorId,
            );
            self::fail('Změněná instrukce příjemce musí být odmítnuta.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString(
                'zmrazenému cíli',
                $exception->getMessage(),
            );
        }

        self::assertSame(
            0,
            $this->countRows(
                'payroll_payment_batches',
                'supplier_id = ?',
                [$this->supplierId],
            ),
        );
        self::assertSame(0, $this->allocatedMinor($liabilityId));
    }

    public function testRejectsRecipientWhoseVerificationWasCleared(): void
    {
        $liabilityId = $this->insertBankLiability(100_000);
        $this->db->pdo()->prepare(
            'UPDATE payroll_person_accounts
                SET verification_source = NULL, verified_on = NULL,
                    verified_by = NULL
              WHERE supplier_id = ? AND employee_id = ? AND id = ?',
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $this->accountId,
        ]);

        try {
            $this->builder->build(
                $this->supplierId,
                'abo',
                "currency:{$this->payerCurrencyId}",
                [[
                    'liability_id' => $liabilityId,
                    'amount_minor' => 100_000,
                ]],
                $this->actorId,
            );
            self::fail('Neověřený účet příjemce musí být odmítnut.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString(
                'úplné ověření',
                $exception->getMessage(),
            );
        }

        self::assertSame(
            0,
            $this->countRows(
                'payroll_payment_batches',
                'supplier_id = ?',
                [$this->supplierId],
            ),
        );
        self::assertSame(0, $this->allocatedMinor($liabilityId));
    }

    public function testRejectsIncompletePayerInstructionAtomically(): void
    {
        $liabilityId = $this->insertBankLiability(100_000);
        $this->db->pdo()->prepare(
            'UPDATE currencies
                SET account_number = NULL
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $this->payerCurrencyId]);

        try {
            $this->builder->build(
                $this->supplierId,
                'abo',
                "currency:{$this->payerCurrencyId}",
                [[
                    'liability_id' => $liabilityId,
                    'amount_minor' => 100_000,
                ]],
                $this->actorId,
            );
            self::fail('Neúplný účet plátce musí být odmítnut.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString(
                'úplný korunový účet plátce',
                $exception->getMessage(),
            );
        }

        self::assertSame(
            0,
            $this->countRows(
                'payroll_payment_batches',
                'supplier_id = ?',
                [$this->supplierId],
            ),
        );
        self::assertSame(0, $this->allocatedMinor($liabilityId));
    }

    public function testRejectsPayerAccountThatFailsModuloElevenAtomically(): void
    {
        $liabilityId = $this->insertBankLiability(100_000);
        $this->db->pdo()->prepare(
            'UPDATE currencies
                SET account_number = "1000000006"
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $this->payerCurrencyId]);

        try {
            $this->builder->build(
                $this->supplierId,
                'abo',
                "currency:{$this->payerCurrencyId}",
                [[
                    'liability_id' => $liabilityId,
                    'amount_minor' => 100_000,
                ]],
                $this->actorId,
            );
            self::fail('Účet plátce s chybným modulo 11 nesmí projít.');
        } catch (\DomainException $exception) {
            self::assertSame(
                'účet plátce není platný český bankovní účet.',
                $exception->getMessage(),
            );
        }

        self::assertSame(
            0,
            $this->countRows(
                'payroll_payment_batches',
                'supplier_id = ?',
                [$this->supplierId],
            ),
        );
        self::assertSame(0, $this->allocatedMinor($liabilityId));
    }

    public function testRejectsRecipientAccountThatFailsModuloElevenAtomically(): void
    {
        $this->replaceVerifiedEmployeeAccount('1000000006/0100');
        $liabilityId = $this->insertBankLiability(100_000);

        try {
            $this->builder->build(
                $this->supplierId,
                'abo',
                "currency:{$this->payerCurrencyId}",
                [[
                    'liability_id' => $liabilityId,
                    'amount_minor' => 100_000,
                ]],
                $this->actorId,
            );
            self::fail('Účet příjemce s chybným modulo 11 nesmí projít.');
        } catch (\DomainException $exception) {
            self::assertSame(
                'účet příjemce není platný český bankovní účet.',
                $exception->getMessage(),
            );
        }

        self::assertSame(
            0,
            $this->countRows(
                'payroll_payment_batches',
                'supplier_id = ?',
                [$this->supplierId],
            ),
        );
        self::assertSame(0, $this->allocatedMinor($liabilityId));
    }

    public function testCreatesEncryptedSepaInstructionFromVerifiedIban(): void
    {
        $iban = 'CZ1801000000001000000005';
        $this->replaceVerifiedEmployeeAccount($iban);
        $payerCurrencyId = $this->createSepaPayerCurrency(
            $this->db->pdo(),
            $iban,
        );
        $liabilityId = $this->insertBankLiability(12_345, 'EUR');

        $result = $this->builder->build(
            $this->supplierId,
            'sepa',
            "currency:{$payerCurrencyId}",
            [[
                'liability_id' => $liabilityId,
                'amount_minor' => 12_345,
            ]],
            $this->actorId,
        );

        self::assertSame('EUR', $result['currency_code']);
        self::assertSame('sepa', $result['export_format']);
        $item = $this->items($result['batch_id'])[0];
        $ciphertext = $this->stringValue(
            $item,
            'instruction_ciphertext',
        );
        self::assertStringNotContainsString($iban, $ciphertext);
        $instruction = $this->encryption->decryptFor(
            $ciphertext,
            "payroll-payment-item:{$this->supplierId}:"
                . $this->stringValue($item, 'item_reference'),
        );
        self::assertStringContainsString($iban, $instruction);
    }

    public function testCreatesManualCashBatchWithoutAccountReference(): void
    {
        $liabilityId = $this->insertCashLiability(25_000);
        $result = $this->builder->build(
            $this->supplierId,
            'manual',
            'cash',
            [[
                'liability_id' => $liabilityId,
                'amount_minor' => 25_000,
            ]],
            $this->actorId,
        );

        self::assertSame(25_000, $result['declared_total_minor']);
        $batch = $this->batch($result['batch_id']);
        self::assertSame('cash', $batch['channel']);
        self::assertSame('manual', $batch['export_format']);
        $item = $this->items($result['batch_id'])[0];
        $instruction = $this->encryption->decryptFor(
            $this->stringValue($item, 'instruction_ciphertext'),
            "payroll-payment-item:{$this->supplierId}:"
                . $this->stringValue($item, 'item_reference'),
        );
        self::assertStringNotContainsString('account', $instruction);
        self::assertStringContainsString(
            "employee-cash:{$this->employeeId}",
            $instruction,
        );
    }

    private function insertBankLiability(
        int $amountMinor,
        string $currencyCode = 'CZK',
    ): int
    {
        $verificationHash = hash(
            'sha256',
            CanonicalJson::encode([
                'schema_reference' =>
                    'payroll-payment-target-verification.v1',
                'person_id' => $this->employeeId,
                'payment_target_id' => $this->accountId,
                'payment_target_hash' => $this->accountHash,
                'row_version' => $this->accountRowVersion,
                'verification_source' => 'user_verified',
                'verified_on' => '2099-01-05',
                'verified_by' => $this->actorId,
            ]),
        );
        $source = [
            'schema_reference' => 'payroll-payment-net-wage-source.v1',
            'run_id' => 1,
            'revision_id' => $this->revisionId,
            'revision_no' => 1,
            'person_id' => $this->employeeId,
            'person_result_hash' => str_repeat('a', 64),
            'input_snapshot_hash' => str_repeat('b', 64),
            'result_snapshot_hash' => str_repeat('c', 64),
            'logical_reference' => 'synthetic-bank-liability',
            'recipient_reference' => "employee-account:{$this->accountId}",
            'allocation_reference_hash' => str_repeat('d', 64),
            'payment_target_id' => $this->accountId,
            'payment_target_hash' => $this->accountHash,
            'payment_target_row_version' => $this->accountRowVersion,
            'payment_target_verification_hash' => $verificationHash,
            'target_amount_minor' => $amountMinor,
            'prior_signed_minor' => 0,
            'delta_signed_minor' => $amountMinor,
        ];

        return $this->insertLiability(
            'employee-account:' . $this->accountId,
            $amountMinor,
            $source,
            $currencyCode,
        );
    }

    private function insertCashLiability(int $amountMinor): int
    {
        return $this->insertLiability(
            "employee-cash:{$this->employeeId}",
            $amountMinor,
            [
                'schema_reference' =>
                    'payroll-payment-net-wage-source.v1',
                'run_id' => 1,
                'revision_id' => $this->revisionId,
                'revision_no' => 1,
                'person_id' => $this->employeeId,
                'person_result_hash' => str_repeat('a', 64),
                'input_snapshot_hash' => str_repeat('b', 64),
                'result_snapshot_hash' => str_repeat('c', 64),
                'logical_reference' => 'synthetic-cash-liability',
                'recipient_reference' =>
                    "employee-cash:{$this->employeeId}",
                'allocation_reference_hash' => str_repeat('e', 64),
                'payment_target_id' => $this->employeeId,
                'payment_target_hash' => null,
                'payment_target_row_version' => null,
                'payment_target_verification_hash' => null,
                'target_amount_minor' => $amountMinor,
                'prior_signed_minor' => 0,
                'delta_signed_minor' => $amountMinor,
            ],
        );
    }

    /** @param array<string,mixed> $source */
    private function insertLiability(
        string $recipientReference,
        int $amountMinor,
        array $source,
        string $currencyCode = 'CZK',
    ): int {
        $sourceJson = CanonicalJson::encode($source);
        $scope = hash(
            'sha256',
            $recipientReference . ':' . $amountMinor . ':' . $currencyCode,
        );
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_payment_liabilities
                (supplier_id, revision_id, employee_id,
                 liability_reference, liability_kind, direction,
                 recipient_reference, due_on, currency_code, amount_minor,
                 source_snapshot_json, source_snapshot_hash,
                 idempotency_key_hash, created_by)
             VALUES (?, ?, ?, ?, "net_wage", "outgoing", ?,
                     "2099-01-10", ?, ?, ?, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $this->revisionId,
            $this->employeeId,
            'synthetic:' . substr($scope, 0, 48),
            $recipientReference,
            $currencyCode,
            $amountMinor,
            $sourceJson,
            hash('sha256', $sourceJson),
            hash('sha256', "synthetic-liability:{$scope}", true),
            $this->actorId,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function createApprovedRevision(PDO $pdo): int
    {
        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status,
                 current_revision_no)
             VALUES (?, "2099-01-01", "2099-01-10", "approved", 1)',
        )->execute([$this->supplierId]);
        $runId = (int) $pdo->lastInsertId();
        $input = '{"schema_version":"payroll-run-input.v2"}';
        $result = '{"schema_version":"payroll-run-result.v2"}';
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json,
                 result_snapshot_hash, idempotency_key_hash, approved_at)
             VALUES (?, ?, 1, "approved", "payroll-run-input.v2",
                     ?, ?, ?, ?, ?, ?, NOW())',
        )->execute([
            $this->supplierId,
            $runId,
            str_repeat('a', 64),
            $input,
            hash('sha256', $input),
            $result,
            hash('sha256', $result),
            hash('sha256', "synthetic-batch-revision:{$runId}", true),
        ]);
        $revisionId = (int) $pdo->lastInsertId();
        $personJson = '{"employee_id":' . $this->employeeId . '}';
        $pdo->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, employee_id, result_json,
                 result_hash, status)
             VALUES (?, ?, ?, ?, ?, "calculated")',
        )->execute([
            $this->supplierId,
            $revisionId,
            $this->employeeId,
            $personJson,
            hash('sha256', $personJson),
        ]);

        return $revisionId;
    }

    /** @return array{int,int,string} */
    private function createVerifiedAccount(PDO $pdo): array
    {
        $pdo->prepare(
            'INSERT INTO payroll_person_accounts
                (supplier_id, employee_id, label, bank_account_ciphertext,
                 bank_account_hash, bank_account_masked, effective_from,
                 is_active, row_version)
             VALUES (?, ?, "Syntetický účet", "placeholder",
                     ?, "••••0005/0100", "2099-01-01", 1, 1)',
        )->execute([
            $this->supplierId,
            $this->employeeId,
            str_repeat("\0", 32),
        ]);
        $accountId = (int) $pdo->lastInsertId();
        $sealed = $this->sensitiveData->seal(
            '1000000005/0100',
            PayrollSensitiveField::BANK_ACCOUNT,
            $this->supplierId,
            $accountId,
        );
        $pdo->prepare(
            'UPDATE payroll_person_accounts
                SET bank_account_ciphertext = ?, bank_account_hash = ?,
                    bank_account_masked = ?
              WHERE supplier_id = ? AND employee_id = ? AND id = ?',
        )->execute([
            $sealed->ciphertext,
            $sealed->lookupHash,
            $sealed->masked,
            $this->supplierId,
            $this->employeeId,
            $accountId,
        ]);
        $pdo->prepare(
            'UPDATE payroll_person_accounts
                SET verification_source = "user_verified",
                    verified_on = "2099-01-05", verified_by = ?,
                    row_version = row_version + 1
              WHERE supplier_id = ? AND employee_id = ? AND id = ?',
        )->execute([
            $this->actorId,
            $this->supplierId,
            $this->employeeId,
            $accountId,
        ]);
        $row = $pdo->prepare(
            'SELECT row_version, LOWER(HEX(bank_account_hash))
               FROM payroll_person_accounts
              WHERE supplier_id = ? AND employee_id = ? AND id = ?',
        );
        $row->execute([
            $this->supplierId,
            $this->employeeId,
            $accountId,
        ]);
        $values = $row->fetch(PDO::FETCH_NUM);
        self::assertIsArray($values);

        $rowVersion = $values[0] ?? null;
        $hash = $values[1] ?? null;
        if ((!is_int($rowVersion) && !is_string($rowVersion))
            || !is_string($hash)
        ) {
            throw new \UnexpectedValueException(
                'Testovací účet nemá očekávaný otisk a verzi.',
            );
        }
        $normalizedVersion = filter_var(
            $rowVersion,
            FILTER_VALIDATE_INT,
        );
        if ($normalizedVersion === false) {
            throw new \UnexpectedValueException(
                'Testovací účet nemá číselnou verzi.',
            );
        }

        return [$accountId, $normalizedVersion, $hash];
    }

    private function createPayerCurrency(PDO $pdo): int
    {
        $pdo->prepare(
            'INSERT INTO currencies
                (supplier_id, code, label, symbol, name_cs, name_en,
                 decimals, is_active, is_default, account_number, bank_code)
             VALUES (?, "CZK", "Syntetický CZK účet", "Kč",
                     "Česká koruna", "Czech koruna", 2, 1, 1,
                     "1000000005", "0100")',
        )->execute([$this->supplierId]);

        return (int) $pdo->lastInsertId();
    }

    private function createSepaPayerCurrency(
        PDO $pdo,
        string $iban,
    ): int {
        $pdo->prepare(
            'INSERT INTO currencies
                (supplier_id, code, label, symbol, name_cs, name_en,
                 decimals, is_active, is_default, iban, bic)
             VALUES (?, "EUR", "Syntetický EUR účet", "€",
                     "Euro", "Euro", 2, 1, 0, ?, "KOMBCZPP")',
        )->execute([$this->supplierId, $iban]);

        return (int) $pdo->lastInsertId();
    }

    private function replaceVerifiedEmployeeAccount(string $account): void
    {
        $sealed = $this->sensitiveData->seal(
            $account,
            PayrollSensitiveField::BANK_ACCOUNT,
            $this->supplierId,
            $this->accountId,
        );
        $this->db->pdo()->prepare(
            'UPDATE payroll_person_accounts
                SET bank_account_ciphertext = ?, bank_account_hash = ?,
                    bank_account_masked = ?, row_version = row_version + 1
              WHERE supplier_id = ? AND employee_id = ? AND id = ?',
        )->execute([
            $sealed->ciphertext,
            $sealed->lookupHash,
            $sealed->masked,
            $this->supplierId,
            $this->employeeId,
            $this->accountId,
        ]);
        $this->db->pdo()->prepare(
            'UPDATE payroll_person_accounts
                SET verification_source = "user_verified",
                    verified_on = "2099-01-05", verified_by = ?,
                    row_version = row_version + 1
              WHERE supplier_id = ? AND employee_id = ? AND id = ?',
        )->execute([
            $this->actorId,
            $this->supplierId,
            $this->employeeId,
            $this->accountId,
        ]);
        $state = $this->row(
            'SELECT row_version,
                    LOWER(HEX(bank_account_hash)) AS bank_account_hash
               FROM payroll_person_accounts
              WHERE supplier_id = ? AND employee_id = ? AND id = ?',
            [$this->supplierId, $this->employeeId, $this->accountId],
        );
        $this->accountRowVersion = $this->integerValue(
            $state,
            'row_version',
        );
        $this->accountHash = $this->stringValue(
            $state,
            'bank_account_hash',
        );
    }

    private function createActor(PDO $pdo): int
    {
        $pdo->prepare(
            'INSERT INTO users
                (email, password_hash, name, role, locale, is_active)
             VALUES (?, ?, "Syntetický dávkový uživatel",
                     "accountant", "cs", 1)',
        )->execute([
            'payroll-batch-' . bin2hex(random_bytes(6)) . '@example.invalid',
            '$2y$10$uses.only.synthetic.placeholder.hash00000000000000000',
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function createEmployee(PDO $pdo): int
    {
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetická platební osoba", "employee", 1)',
        )->execute([$this->supplierId]);

        return (int) $pdo->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function batch(int $batchId): array
    {
        return $this->row(
            'SELECT * FROM payroll_payment_batches
              WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $batchId],
        );
    }

    /** @return list<array<string,mixed>> */
    private function items(int $batchId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_payment_items
              WHERE supplier_id = ? AND batch_id = ? ORDER BY id',
        );
        $statement->execute([$this->supplierId, $batchId]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = $this->associativeRow($row);
        }

        return $result;
    }

    private function allocatedMinor(int $liabilityId): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT COALESCE(SUM(amount_minor), 0)
               FROM payroll_payment_allocations
              WHERE supplier_id = ? AND liability_id = ?',
        );
        $statement->execute([$this->supplierId, $liabilityId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @param list<mixed> $params
     */
    private function countRows(string $table, string $where, array $params): int
    {
        if ($table !== 'payroll_payment_batches') {
            throw new \InvalidArgumentException('Nepovolená testovací tabulka.');
        }
        $statement = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE {$where}",
        );
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    /**
     * @param list<mixed> $params
     * @return array<string,mixed>
     */
    private function row(string $sql, array $params): array
    {
        $statement = $this->db->pdo()->prepare($sql);
        $statement->execute($params);
        return $this->associativeRow(
            $statement->fetch(PDO::FETCH_ASSOC),
        );
    }

    /** @param array<string,mixed> $row */
    private function stringValue(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value)) {
            throw new \UnexpectedValueException(
                "Testovací hodnota {$field} není text.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private function integerValue(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) && !is_string($value)) {
            throw new \UnexpectedValueException(
                "Testovací hodnota {$field} není číslo.",
            );
        }
        $normalized = filter_var($value, FILTER_VALIDATE_INT);
        if ($normalized === false) {
            throw new \UnexpectedValueException(
                "Testovací hodnota {$field} není celé číslo.",
            );
        }

        return $normalized;
    }

    /** @return array<string,mixed> */
    private function associativeRow(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \UnexpectedValueException(
                'Databáze nevrátila testovací řádek.',
            );
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    'Testovací řádek nemá textové klíče.',
                );
            }
            $result[$key] = $item;
        }

        return $result;
    }
}
