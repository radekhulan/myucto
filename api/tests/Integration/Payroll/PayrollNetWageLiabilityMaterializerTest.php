<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollPaymentLiabilityRepository;
use MyInvoice\Repository\Payroll\PayrollRunRepository;
use MyInvoice\Service\Payroll\Net\PayoutAllocationService;
use MyInvoice\Service\Payroll\Payment\PayrollNetWageLiabilityMaterializer;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentQueryService;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollNetWageLiabilityMaterializerTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollNetWageLiabilityMaterializer $service;
    private int $supplierId;
    private int $actorId;
    private int $employeeId;
    private int $accountId;
    private string $accountHash;

    protected function setUp(): void
    {
        $connection = \MyInvoice\Tests\Support\PayrollTestContainer::build()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->db = $connection;
        $pdo = $connection->pdo();
        $sourceSupplier = $pdo->query(
            'SELECT MIN(id) FROM supplier',
        );
        self::assertInstanceOf(\PDOStatement::class, $sourceSupplier);
        $sourceSupplierId = (int) $sourceSupplier->fetchColumn();
        self::assertGreaterThan(0, $sourceSupplierId);

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );
        $this->actorId = $this->createActor($pdo);
        $this->employeeId = $this->createEmployee($pdo);
        [$this->accountId, $this->accountHash] = $this->createVerifiedAccount(
            $pdo,
        );
        $this->service = new PayrollNetWageLiabilityMaterializer(
            new PayrollPaymentLiabilityRepository($connection),
            new PayoutAllocationService(),
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

    public function testMaterializesVerifiedBankAndCashTargetsIdempotently(): void
    {
        [, $revisionId] = $this->createRevision(
            revisionNo: 1,
            revisionKind: 'regular',
            payableMinor: 100_000,
            bankMinor: 30_000,
        );

        $first = $this->service->materialize(
            $this->supplierId,
            $revisionId,
            $this->actorId,
        );
        $replayed = $this->service->materialize(
            $this->supplierId,
            $revisionId,
            $this->actorId,
        );

        self::assertSame(2, $first['created_count']);
        self::assertSame(0, $replayed['created_count']);
        self::assertSame($first['liability_ids'], $replayed['liability_ids']);

        $rows = $this->liabilities($revisionId);
        self::assertCount(2, $rows);
        self::assertSame(
            [30_000, 70_000],
            array_column($rows, 'amount_minor'),
        );
        self::assertSame(
            ['outgoing', 'outgoing'],
            array_column($rows, 'direction'),
        );
        self::assertSame(
            [
                "employee-account:{$this->accountId}",
                "employee-cash:{$this->employeeId}",
            ],
            array_column($rows, 'recipient_reference'),
        );
        self::assertSame(
            ['2099-01-10', '2099-01-10'],
            array_column($rows, 'due_on'),
        );

        foreach ($rows as $row) {
            $sourceJson = (string) $row['source_snapshot_json'];
            self::assertSame(
                hash('sha256', $sourceJson),
                $row['source_snapshot_hash'],
            );
            self::assertStringNotContainsString(
                'Syntetická platební osoba',
                $sourceJson,
            );
            self::assertStringNotContainsString(
                'bank_account',
                $sourceJson,
            );
            self::assertStringNotContainsString(
                'birth',
                $sourceJson,
            );
            self::assertStringNotContainsString(
                'enc:v2:',
                $sourceJson,
            );
        }
    }

    public function testPeriodQueryReturnsOnlySafeDerivedPaymentState(): void
    {
        [, $revisionId] = $this->createRevision(
            revisionNo: 1,
            revisionKind: 'regular',
            payableMinor: 100_000,
            bankMinor: 30_000,
        );
        $this->service->materialize(
            $this->supplierId,
            $revisionId,
            $this->actorId,
        );

        $items = (new PayrollPaymentQueryService($this->db))->listForPeriod(
            $this->supplierId,
            '2099-01',
        )['items'];

        self::assertCount(2, $items);
        $amounts = array_column($items, 'amount_minor');
        sort($amounts, SORT_NUMERIC);
        self::assertSame([30_000, 70_000], $amounts);
        $recipientKinds = array_column($items, 'recipient_kind');
        sort($recipientKinds, SORT_STRING);
        self::assertSame(['bank', 'cash'], $recipientKinds);
        foreach ($items as $item) {
            self::assertSame('open', $item['state']);
            self::assertSame('ready', $item['payment_target_status']);
            self::assertSame('ready', $item['batch_eligibility']);
            self::assertNull($item['batch_block_reason']);
            self::assertSame('regular', $item['revision_kind']);
            self::assertSame(
                'Syntetická platební osoba',
                $item['recipient_name'],
            );
            self::assertSame(0, $item['allocated_minor']);
            self::assertSame(0, $item['settled_minor']);
            self::assertArrayNotHasKey('recipient_reference', $item);
            self::assertArrayNotHasKey('source_snapshot_json', $item);
            self::assertArrayNotHasKey('source_snapshot_hash', $item);
            self::assertArrayNotHasKey('bank_account_hash', $item);
        }
    }

    public function testCorrectionCreatesOnlySignedDeltasAndLinksPreviousRows(): void
    {
        [$runId, $firstRevisionId] = $this->createRevision(
            revisionNo: 1,
            revisionKind: 'regular',
            payableMinor: 100_000,
            bankMinor: 30_000,
        );
        $first = $this->service->materialize(
            $this->supplierId,
            $firstRevisionId,
            $this->actorId,
        );
        self::assertSame(2, $first['created_count']);

        [, $correctionRevisionId] = $this->createRevision(
            revisionNo: 2,
            revisionKind: 'correction',
            payableMinor: 80_000,
            bankMinor: 50_000,
            runId: $runId,
            previousRevisionId: $firstRevisionId,
        );
        $correction = $this->service->materialize(
            $this->supplierId,
            $correctionRevisionId,
            $this->actorId,
        );
        $replayed = $this->service->materialize(
            $this->supplierId,
            $correctionRevisionId,
            $this->actorId,
        );

        self::assertSame(2, $correction['created_count']);
        self::assertSame(0, $replayed['created_count']);
        $rows = $this->liabilities($correctionRevisionId);
        self::assertCount(2, $rows);
        self::assertSame(
            [20_000, 40_000],
            array_column($rows, 'amount_minor'),
        );
        self::assertSame(
            ['outgoing', 'incoming'],
            array_column($rows, 'direction'),
        );
        self::assertNotNull($rows[0]['previous_liability_id']);
        self::assertNotNull($rows[1]['previous_liability_id']);

        $net = $this->db->pdo()->prepare(
            'SELECT SUM(
                    CASE direction
                      WHEN "outgoing" THEN amount_minor
                      ELSE -amount_minor
                    END
                )
               FROM payroll_payment_liabilities liability
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = liability.supplier_id
                AND revision.id = liability.revision_id
              WHERE liability.supplier_id = ?
                AND revision.run_id = ?
                AND liability.liability_kind = "net_wage"',
        );
        $net->execute([$this->supplierId, $runId]);
        self::assertSame(80_000, (int) $net->fetchColumn());
    }

    public function testRejectsUnverifiedFrozenBankTargetWithoutWritingAnything(): void
    {
        [, $revisionId] = $this->createRevision(
            revisionNo: 1,
            revisionKind: 'regular',
            payableMinor: 100_000,
            bankMinor: 100_000,
            accountOverrides: ['verified_by' => null],
        );

        try {
            $this->service->materialize(
                $this->supplierId,
                $revisionId,
                $this->actorId,
            );
            self::fail('Neověřený bankovní cíl musí být odmítnut.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString(
                'úplné ověření',
                $exception->getMessage(),
            );
        }

        self::assertSame([], $this->liabilities($revisionId));
    }

    public function testRejectsTamperedCanonicalPersonResultHash(): void
    {
        [, $revisionId] = $this->createRevision(
            revisionNo: 1,
            revisionKind: 'regular',
            payableMinor: 100_000,
            bankMinor: 30_000,
        );
        $this->db->pdo()->prepare(
            'UPDATE payroll_run_persons
                SET result_hash = ?
              WHERE supplier_id = ? AND revision_id = ? AND employee_id = ?',
        )->execute([
            str_repeat('f', 64),
            $this->supplierId,
            $revisionId,
            $this->employeeId,
        ]);

        try {
            $this->service->materialize(
                $this->supplierId,
                $revisionId,
                $this->actorId,
            );
            self::fail('Poškozený hash osoby musí být odmítnut.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString(
                'Otisk výsledku osoby',
                $exception->getMessage(),
            );
        }

        self::assertSame([], $this->liabilities($revisionId));
    }

    public function testZeroPayableCreatesNoEconomicLiability(): void
    {
        [, $revisionId] = $this->createRevision(
            revisionNo: 1,
            revisionKind: 'regular',
            payableMinor: 0,
            bankMinor: 0,
        );

        $result = $this->service->materialize(
            $this->supplierId,
            $revisionId,
            $this->actorId,
        );

        self::assertSame([], $result['liability_ids']);
        self::assertSame(0, $result['created_count']);
        self::assertSame([], $this->liabilities($revisionId));
    }

    public function testPartnerSettlementCreatesNoPayableLiabilityForSettledPart(): void
    {
        [, $revisionId] = $this->createRevision(
            revisionNo: 1,
            revisionKind: 'regular',
            payableMinor: 100_000,
            bankMinor: 0,
            settlementMinor: 40_000,
        );

        $result = $this->service->materialize(
            $this->supplierId,
            $revisionId,
            $this->actorId,
        );

        // Započtených 40 000 se nesmí objevit nikde v platbách — je to účetní
        // překlasifikace (331/366 MD / 365.100 D), ne výplata. Vyplácí se jen
        // zbytek, jinak by firma poslala peníze, které už jsou vypořádané.
        self::assertSame(1, $result['created_count']);
        $rows = $this->liabilities($revisionId);
        self::assertCount(1, $rows);
        self::assertSame([60_000], array_column($rows, 'amount_minor'));
        self::assertSame(
            ["employee-cash:{$this->employeeId}"],
            array_column($rows, 'recipient_reference'),
        );
    }

    public function testPartnerSettlementRefusesOrdinaryEmployeeWithoutWritingAnything(): void
    {
        [, $revisionId] = $this->createRevision(
            revisionNo: 1,
            revisionKind: 'regular',
            payableMinor: 100_000,
            bankMinor: 0,
            settlementMinor: 40_000,
            relationType: 'employment',
        );

        try {
            $this->service->materialize(
                $this->supplierId,
                $revisionId,
                $this->actorId,
            );
            self::fail('Zápočet u běžného zaměstnance musí být odmítnut.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString(
                'Zápočtem na účet společníka',
                $exception->getMessage(),
            );
        }

        self::assertSame([], $this->liabilities($revisionId));
    }

    public function testRejectsBankDestinationWithoutFrozenAccountIdReference(): void
    {
        [, $revisionId] = $this->createRevision(
            revisionNo: 1,
            revisionKind: 'regular',
            payableMinor: 100_000,
            bankMinor: 100_000,
            bankDestinationReference: 'synthetic-bank-destination',
        );

        try {
            $this->service->materialize(
                $this->supplierId,
                $revisionId,
                $this->actorId,
            );
            self::fail('Bankovní cíl bez account:<id> musí být odmítnut.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString(
                'account:<id>',
                $exception->getMessage(),
            );
        }

        self::assertSame([], $this->liabilities($revisionId));
    }

    public function testLegacyRevisionWithoutFrozenPayoutAccountsIsExcludedAndRejectedClearly(): void
    {
        [, $revisionId] = $this->createRevision(
            revisionNo: 1,
            revisionKind: 'regular',
            payableMinor: 100_000,
            bankMinor: 0,
            includePayoutAccounts: false,
        );

        // Příznak počítá SQL nad snapshotem, ne PHP nad dekódovaným polem —
        // seznam běhů kvůli paměti LONGTEXT sloupce vůbec nečte.
        $page = (new PayrollRunRepository($this->db))->list(
            $this->supplierId,
            '2099-01-01',
        );
        self::assertCount(1, $page['items']);
        self::assertSame(1, $page['total']);
        self::assertFalse($page['items'][0]['payment_materialization_supported']);

        try {
            $this->service->materialize(
                $this->supplierId,
                $revisionId,
                $this->actorId,
            );
            self::fail('Legacy revize bez snapshotu účtů musí být odmítnuta.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString(
                'zmrazené výplatní účty',
                $exception->getMessage(),
            );
        }

        self::assertSame([], $this->liabilities($revisionId));
    }

    /**
     * @param array<string,mixed> $accountOverrides
     * @return array{int,int}
     */
    private function createRevision(
        int $revisionNo,
        string $revisionKind,
        int $payableMinor,
        int $bankMinor,
        ?int $runId = null,
        ?int $previousRevisionId = null,
        array $accountOverrides = [],
        ?string $bankDestinationReference = null,
        bool $includePayoutAccounts = true,
        int $settlementMinor = 0,
        string $settlementAccountCode = '365.100',
        string $relationType = 'partner_dependent',
    ): array {
        $pdo = $this->db->pdo();
        if ($runId === null) {
            $pdo->prepare(
                'INSERT INTO payroll_runs
                    (supplier_id, period_start, payment_date, status,
                     current_revision_no)
                 VALUES (?, "2099-01-01", "2099-01-10", "approved", ?)',
            )->execute([$this->supplierId, $revisionNo]);
            $runId = (int) $pdo->lastInsertId();
        } else {
            $pdo->prepare(
                'UPDATE payroll_runs
                    SET current_revision_no = ?, status = "approved"
                  WHERE supplier_id = ? AND id = ?',
            )->execute([$revisionNo, $this->supplierId, $runId]);
        }

        $account = array_replace([
            'id' => $this->accountId,
            'bank_account_hash' => $this->accountHash,
            'effective_from' => '2099-01-01',
            'effective_to' => null,
            'row_version' => 1,
            'verification_source' => 'user_verified',
            'verified_on' => '2099-01-05',
            'verified_by' => $this->actorId,
        ], $accountOverrides);
        $rules = [];
        if ($bankMinor > 0) {
            $rules[] = [
                'id' => 11,
                'allocation_reference' => 'synthetic-bank',
                'destination_kind' => 'bank',
                'destination_reference' => $bankDestinationReference
                    ?? "account:{$this->accountId}",
                'allocation_kind' => 'fixed',
                'amount_minor' => $bankMinor,
                'basis_points' => null,
                'priority_no' => 10,
                'row_version' => 1,
            ];
        }
        if ($settlementMinor > 0) {
            $rules[] = [
                'id' => 13,
                'allocation_reference' => 'synthetic-partner-settlement',
                'destination_kind' => 'partner_settlement',
                'destination_reference' => $settlementAccountCode,
                'allocation_kind' => 'fixed',
                'amount_minor' => $settlementMinor,
                'basis_points' => null,
                'priority_no' => 5,
                'row_version' => 1,
            ];
        }
        $rules[] = [
            'id' => 12,
            'allocation_reference' => 'synthetic-cash',
            'destination_kind' => 'cash',
            'destination_reference' => null,
            'allocation_kind' => 'remainder',
            'amount_minor' => null,
            'basis_points' => null,
            'priority_no' => 20,
            'row_version' => 1,
        ];
        $personInput = [
            'employee' => ['id' => $this->employeeId],
            'payout_rules' => $rules,
            'employments' => $settlementMinor > 0
                ? [[
                    'employment' => [
                        'id' => 901,
                        'employee_id' => $this->employeeId,
                        'relation_type' => $relationType,
                    ],
                    'inputs' => [],
                ]]
                : [],
        ];
        if ($includePayoutAccounts) {
            $personInput['payout_accounts'] = [$account];
        }
        $input = [
            'schema_version' => 'payroll-run-input.v2',
            'supplier_id' => $this->supplierId,
            'period_start' => '2099-01-01',
            'period_end' => '2099-01-31',
            'payment_date' => '2099-01-10',
            'people' => [$personInput],
        ];
        $inputJson = CanonicalJson::encode($input);
        $inputHash = hash('sha256', $inputJson);
        $personResult = [
            'employee_id' => $this->employeeId,
            'payable_after_enforcement_minor' => $payableMinor,
            'employments' => [],
        ];
        $result = [
            'schema_version' => 'payroll-run-result.v2',
            'source_snapshot_hash' => $inputHash,
            'people' => [$personResult],
        ];
        $resultJson = CanonicalJson::encode($result);
        $resultHash = hash('sha256', $resultJson);
        $personJson = CanonicalJson::encode($personResult);

        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, previous_revision_id,
                 revision_kind, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json,
                 result_snapshot_hash, idempotency_key_hash, approved_at)
             VALUES (?, ?, ?, ?, ?, "approved", "payroll-run-input.v2",
                     ?, ?, ?, ?, ?, ?, NOW())',
        )->execute([
            $this->supplierId,
            $runId,
            $revisionNo,
            $previousRevisionId,
            $revisionKind,
            str_repeat('a', 64),
            $inputJson,
            $inputHash,
            $resultJson,
            $resultHash,
            hash(
                'sha256',
                "synthetic-payment-revision:{$runId}:{$revisionNo}",
                true,
            ),
        ]);
        $revisionId = (int) $pdo->lastInsertId();
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

        return [$runId, $revisionId];
    }

    private function createActor(PDO $pdo): int
    {
        $pdo->prepare(
            'INSERT INTO users
                (email, password_hash, name, role, locale, is_active)
             VALUES (?, ?, "Syntetický ověřovatel", "accountant", "cs", 1)',
        )->execute([
            'payroll-payment-' . bin2hex(random_bytes(6)) . '@example.invalid',
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

    /** @return array{int,string} */
    private function createVerifiedAccount(PDO $pdo): array
    {
        $hash = hash(
            'sha256',
            "synthetic-tenant-safe:{$this->supplierId}:employee-account",
        );
        $pdo->prepare(
            'INSERT INTO payroll_person_accounts
                (supplier_id, employee_id, label, bank_account_ciphertext,
                 bank_account_hash, bank_account_masked,
                 allocation_basis_points, effective_from, is_active,
                 row_version, verification_source, verified_on, verified_by)
             VALUES (?, ?, "Syntetický účet", "enc:v2:synthetic",
                     UNHEX(?), "••••0005", 10000, "2099-01-01", 1, 1,
                     "user_verified", "2099-01-05", ?)',
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $hash,
            $this->actorId,
        ]);

        return [(int) $pdo->lastInsertId(), $hash];
    }

    /** @return list<array<string,mixed>> */
    private function liabilities(int $revisionId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, liability_reference, direction, recipient_reference,
                    due_on, amount_minor, previous_liability_id,
                    source_snapshot_json, source_snapshot_hash
               FROM payroll_payment_liabilities
              WHERE supplier_id = ? AND revision_id = ?
              ORDER BY recipient_reference',
        );
        $statement->execute([$this->supplierId, $revisionId]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row) || array_is_list($row)) {
                self::fail('Databáze vrátila neplatný syntetický závazek.');
            }
            $result[] = [
                'id' => (int) ($row['id'] ?? 0),
                'liability_reference' =>
                    (string) ($row['liability_reference'] ?? ''),
                'direction' => (string) ($row['direction'] ?? ''),
                'recipient_reference' =>
                    (string) ($row['recipient_reference'] ?? ''),
                'due_on' => (string) ($row['due_on'] ?? ''),
                'amount_minor' => (int) ($row['amount_minor'] ?? 0),
                'previous_liability_id' =>
                    ($row['previous_liability_id'] ?? null) === null
                    ? null
                    : (int) $row['previous_liability_id'],
                'source_snapshot_json' =>
                    (string) ($row['source_snapshot_json'] ?? ''),
                'source_snapshot_hash' =>
                    (string) ($row['source_snapshot_hash'] ?? ''),
            ];
        }

        return $result;
    }
}
