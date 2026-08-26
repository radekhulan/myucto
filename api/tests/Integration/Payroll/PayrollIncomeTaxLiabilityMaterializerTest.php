<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollInstitutionAccountDeletionRepository;
use MyInvoice\Repository\Payroll\PayrollInstitutionAccountRepository;
use MyInvoice\Repository\Payroll\PayrollPaymentLiabilityRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryResultRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Deadline\PayrollLevyDeadlinePolicy;
use MyInvoice\Service\Payroll\Payment\PayrollIncomeTaxLiabilityMaterializer;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentBatchBuilder;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentQueryService;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollIncomeTaxLiabilityMaterializerTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollIncomeTaxLiabilityMaterializer $materializer;
    private PayrollPaymentBatchBuilder $batches;
    private SecretEncryption $encryption;
    private int $supplierId;
    private int $otherSupplierId;
    private int $actorId;
    private int $employeeId;
    private int $runId;
    private int $payerCurrencyId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $connection = $container->get(Connection::class);
        $sensitive = $container->get(PayrollSensitiveData::class);
        $batches = $container->get(PayrollPaymentBatchBuilder::class);
        $encryption = $container->get(SecretEncryption::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(PayrollSensitiveData::class, $sensitive);
        self::assertInstanceOf(PayrollPaymentBatchBuilder::class, $batches);
        self::assertInstanceOf(SecretEncryption::class, $encryption);
        $this->db = $connection;
        $this->batches = $batches;
        $this->encryption = $encryption;
        $pdo = $connection->pdo();
        $source = $pdo->query('SELECT MIN(id) FROM supplier');
        self::assertInstanceOf(\PDOStatement::class, $source);
        $sourceSupplierId = (int) $source->fetchColumn();
        self::assertGreaterThan(0, $sourceSupplierId);
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );
        $this->otherSupplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );
        $this->actorId = $this->createActor($pdo);
        $pdo->prepare(
            'INSERT INTO payroll_module_state
                (supplier_id, status, start_period, activated_by, activated_at)
             VALUES (?, "active", "2026-01-01", ?, NOW())',
        )->execute([$this->supplierId, $this->actorId]);
        $this->employeeId = $this->createEmployee($pdo);
        $this->payerCurrencyId = $this->createPayerCurrency($pdo);
        $pdo->prepare(
            'UPDATE supplier
                SET company_name = "Syntetická daňová firma",
                    display_name = NULL
              WHERE id = ?',
        )->execute([$this->supplierId]);
        $institutions = new PayrollInstitutionAccountRepository(
            $connection,
            $sensitive,
            new PayrollInstitutionAccountDeletionRepository(
                $connection,
                new ActivityLogger($connection),
            ),
        );
        $this->createTaxTarget(
            $institutions,
            'advance_tax',
            '1234567890',
            '1001',
            '1148',
        );
        $this->createTaxTarget(
            $institutions,
            'withholding_tax',
            '1234567890',
            '1002',
            '1148',
        );
        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status,
                 current_revision_no)
             VALUES (?, "2026-06-01", "2026-07-10", "approved", 1)'
        )->execute([$this->supplierId]);
        $this->runId = (int) $pdo->lastInsertId();
        $this->materializer = new PayrollIncomeTaxLiabilityMaterializer(
            new PayrollPaymentLiabilityRepository($connection),
            new PayrollStatutoryResultRepository($connection),
            $institutions,
            $sensitive,
            new PayrollLevyDeadlinePolicy(),
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

    public function testMaterializesSeparateFrozenTaxLiabilitiesIdempotently(): void
    {
        $revisionId = $this->createRevision(
            1,
            'regular',
            null,
            12_500,
            3_000,
        );

        $first = $this->materializer->materialize(
            $this->supplierId,
            $revisionId,
            $this->actorId,
        );
        $replay = $this->materializer->materialize(
            $this->supplierId,
            $revisionId,
            $this->actorId,
        );

        self::assertSame(2, $first['created_count']);
        self::assertSame(0, $replay['created_count']);
        self::assertSame($first['liability_ids'], $replay['liability_ids']);
        $rows = array_map($this->liability(...), $first['liability_ids']);
        self::assertSame(
            ['advance_tax', 'withholding_tax'],
            array_column($rows, 'liability_kind'),
        );
        self::assertSame(
            ['payroll-tax:advance', 'payroll-tax:withholding'],
            array_column($rows, 'liability_reference'),
        );
        self::assertSame(
            ['2026-07-20', '2026-07-31'],
            array_column($rows, 'due_on'),
        );
        self::assertSame([12_500, 3_000], array_map(
            fn (array $row): int => $this->integer($row, 'amount_minor'),
            $rows,
        ));
        $listed = (new PayrollPaymentQueryService($this->db))
            ->listForPeriod($this->supplierId, '2026-06')['items'];
        self::assertSame(
            ['ready', 'ready'],
            array_column($listed, 'batch_eligibility'),
        );
        foreach ($rows as $index => $row) {
            self::assertNull($row['employee_id']);
            self::assertSame('outgoing', $row['direction']);
            $source = $this->string($row, 'source_snapshot_json');
            self::assertStringNotContainsString('1000000005', $source);
            self::assertStringNotContainsString(
                'Syntetický finanční úřad',
                $source,
            );
            $decoded = $this->object(json_decode(
                $source,
                true,
                flags: JSON_THROW_ON_ERROR,
            ));
            self::assertSame('tax_office', $decoded['institution_type']);
            self::assertSame(
                $index === 0 ? '1001' : '1002',
                $decoded['specific_symbol'],
            );
            self::assertSame('1234567890', $decoded['variable_symbol']);
            self::assertSame('1148', $decoded['constant_symbol']);
        }
        $expectedMessages = [
            'Zaloha na dan z prijmu',
            'Srazkova dan z prijmu',
        ];
        foreach ($first['liability_ids'] as $index => $liabilityId) {
            $batch = $this->batches->build(
                $this->supplierId,
                'abo',
                "currency:{$this->payerCurrencyId}",
                [[
                    'liability_id' => $liabilityId,
                    'amount_minor' => $index === 0 ? 12_500 : 3_000,
                ]],
                $this->actorId,
            );
            $instruction = $this->batchInstruction($batch['batch_id']);
            self::assertSame(
                $expectedMessages[$index],
                $instruction['payment_message'],
            );
            self::assertSame(
                $index === 0 ? '1001' : '1002',
                $instruction['specific_symbol'],
            );
            self::assertSame('1234567890', $instruction['variable_symbol']);
            self::assertSame('1148', $instruction['constant_symbol']);
        }
    }

    /**
     * § 35d odst. 5: „O vyplacený měsíční daňový bonus plátce daně sníží odvod
     * záloh na daň za příslušný kalendářní měsíc." § 38ch odst. 5 a § 35d
     * odst. 9 říkají totéž o doplatku z ročního zúčtování. Bez toho by
     * zaměstnavatel vyplatil bonus i doplatek zaměstnanci a TÝŽ peníze poslal
     * ještě jednou finančnímu úřadu.
     */
    public function testAdvanceLevyIsReducedByBonusAndAnnualSettlement(): void
    {
        $revisionId = $this->createRevision(
            1,
            'regular',
            null,
            12_500,
            3_000,
            taxBonus: 2_000,
            annualSettlement: 1_500,
        );

        $result = $this->materializer->materialize(
            $this->supplierId,
            $revisionId,
            $this->actorId,
        );

        $rows = array_map($this->liability(...), $result['liability_ids']);
        self::assertSame(
            ['advance_tax', 'withholding_tax'],
            array_column($rows, 'liability_kind'),
        );
        self::assertSame([9_000, 3_000], array_map(
            fn (array $row): int => $this->integer($row, 'amount_minor'),
            $rows,
        ));
        $source = $this->object(json_decode(
            $this->string($rows[0], 'source_snapshot_json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        ));
        self::assertSame(3_500, $source['advance_tax_offset_minor']);
        self::assertSame(0, $source['advance_tax_offset_unapplied_minor']);
        self::assertArrayNotHasKey(
            'advance_tax_offset_minor',
            $this->object(json_decode(
                $this->string($rows[1], 'source_snapshot_json'),
                true,
                flags: JSON_THROW_ON_ERROR,
            )),
        );
    }

    /**
     * Odvod nemůže být záporný. Nevyčerpaný zbytek se podle § 35d odst. 5 a 9
     * buď odečte z odvodů v dalších měsících, nebo si o něj plátce požádá
     * správce daně — obojí je jeho úkon, takže se jen pojmenuje.
     */
    public function testAdvanceLevyStopsAtZeroAndNamesTheUnappliedRemainder(): void
    {
        $revisionId = $this->createRevision(
            1,
            'regular',
            null,
            1_000,
            3_000,
            taxBonus: 2_500,
            annualSettlement: 900,
        );

        $result = $this->materializer->materialize(
            $this->supplierId,
            $revisionId,
            $this->actorId,
        );

        $rows = array_map($this->liability(...), $result['liability_ids']);
        self::assertSame(
            ['withholding_tax'],
            array_column($rows, 'liability_kind'),
        );
        self::assertSame([3_000], array_map(
            fn (array $row): int => $this->integer($row, 'amount_minor'),
            $rows,
        ));
    }

    public function testCorrectionCreatesIndependentSignedDeltas(): void
    {
        $regularRevision = $this->createRevision(
            1,
            'regular',
            null,
            12_500,
            3_000,
        );
        $regular = $this->materializer->materialize(
            $this->supplierId,
            $regularRevision,
            $this->actorId,
        );
        $correctionRevision = $this->createRevision(
            2,
            'correction',
            $regularRevision,
            10_000,
            4_500,
        );

        $correction = $this->materializer->materialize(
            $this->supplierId,
            $correctionRevision,
            $this->actorId,
        );
        self::assertSame(2, $correction['created_count']);
        $rows = array_map($this->liability(...), $correction['liability_ids']);
        self::assertSame(
            ['advance_tax', 'withholding_tax'],
            array_column($rows, 'liability_kind'),
        );
        self::assertSame(
            ['incoming', 'outgoing'],
            array_column($rows, 'direction'),
        );
        self::assertSame([2_500, 1_500], array_map(
            fn (array $row): int => $this->integer($row, 'amount_minor'),
            $rows,
        ));
        self::assertSame(
            $regular['liability_ids'],
            array_map(
                fn (array $row): int =>
                    $this->integer($row, 'previous_liability_id'),
                $rows,
            ),
        );
    }

    public function testRootTotalsAndTenantIsolationFailClosed(): void
    {
        $revisionId = $this->createRevision(
            1,
            'regular',
            null,
            12_500,
            3_000,
            rootAdvance: 12_501,
        );
        try {
            $this->materializer->materialize(
                $this->supplierId,
                $revisionId,
                $this->actorId,
            );
            self::fail('Rozporný kořenový součet nesmí vytvořit závazek.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString(
                'kořenovému výsledku',
                $exception->getMessage(),
            );
        }
        self::assertSame(0, $this->liabilityCount());

        try {
            $this->materializer->materialize(
                $this->otherSupplierId,
                $revisionId,
                $this->actorId,
            );
            self::fail('Cizí tenant nesmí materializovat revizi.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }
        self::assertSame(0, $this->liabilityCount());
    }

    public function testTamperedImmutableResultHashFailsClosed(): void
    {
        $revisionId = $this->createRevision(
            1,
            'regular',
            null,
            12_500,
            3_000,
            storeStatutory: false,
        );
        $input = '{"schema_version":"payroll-run-input.v2"}';
        $root = '{"advance_tax_minor_units":1,"people":[],"status":"calculated","tax_bonus_minor_units":0,"withholding_tax_minor_units":0}';
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_statutory_results
                (supplier_id, revision_id, calculation_kind,
                 schema_version, result_status, ruleset_id, ruleset_hash,
                 input_snapshot_json, input_snapshot_hash,
                 result_snapshot_json, result_snapshot_hash,
                 result_set_hash, created_by)
             VALUES (?, ?, "income_tax", "payroll-income-tax-result.v1",
                     "calculated", "cz-income-tax-2026", ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $this->supplierId,
            $revisionId,
            str_repeat('b', 64),
            $input,
            hash('sha256', $input),
            $root,
            str_repeat('f', 64),
            hash('sha256', 'synthetic-invalid-result-set'),
            $this->actorId,
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Otisk výsledku daně nesouhlasí');
        try {
            $this->materializer->materialize(
                $this->supplierId,
                $revisionId,
                $this->actorId,
            );
        } finally {
            self::assertSame(0, $this->liabilityCount());
        }
    }

    public function testStatutoryResultFromDifferentFrozenInputFailsClosed(): void
    {
        $revisionId = $this->createRevision(
            1,
            'regular',
            null,
            12_500,
            3_000,
            statutoryInput: [
                'schema_version' => 'payroll-run-input.v2',
                'synthetic' => 'foreign',
            ],
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('zmrazeného vstupu');
        try {
            $this->materializer->materialize(
                $this->supplierId,
                $revisionId,
                $this->actorId,
            );
        } finally {
            self::assertSame(0, $this->liabilityCount());
        }
    }

    /** @param array<string,mixed>|null $statutoryInput */
    private function createRevision(
        int $revisionNo,
        string $revisionKind,
        ?int $previousRevisionId,
        int $advance,
        int $withholding,
        ?int $rootAdvance = null,
        bool $storeStatutory = true,
        ?array $statutoryInput = null,
        int $taxBonus = 0,
        int $annualSettlement = 0,
    ): int {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'UPDATE payroll_runs
                SET current_revision_no = ?, status = "approved"
              WHERE supplier_id = ? AND id = ?'
        )->execute([$revisionNo, $this->supplierId, $this->runId]);
        $input = '{"schema_version":"payroll-run-input.v2"}';
        $result = '{"schema_version":"payroll-run-result.v2"}';
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, previous_revision_id,
                 revision_kind, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json,
                 result_snapshot_hash, idempotency_key_hash, approved_at)
             VALUES (?, ?, ?, ?, ?, "approved", "payroll-run-input.v2",
                     ?, ?, ?, ?, ?, ?, NOW())'
        )->execute([
            $this->supplierId,
            $this->runId,
            $revisionNo,
            $previousRevisionId,
            $revisionKind,
            str_repeat('a', 64),
            $input,
            hash('sha256', $input),
            $result,
            hash('sha256', $result),
            hash(
                'sha256',
                "synthetic-income-tax-revision:{$this->runId}:{$revisionNo}",
                true,
            ),
        ]);
        $revisionId = (int) $pdo->lastInsertId();
        $runPersonJson = json_encode(
            ['employee_id' => $this->employeeId],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        $pdo->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, employee_id, result_json,
                 result_hash, status)
             VALUES (?, ?, ?, ?, ?, "calculated")'
        )->execute([
            $this->supplierId,
            $revisionId,
            $this->employeeId,
            $runPersonJson,
            hash('sha256', $runPersonJson),
        ]);
        $personResult = [
            'status' => 'calculated',
            'calculation_date' => '2026-06-30',
            'employee_reference' => "employee:{$this->employeeId}",
            'payer_reference' => "supplier:{$this->supplierId}",
            'advance_tax' => [
                'tax_after_credits_minor_units' => $advance,
                'tax_bonus_minor_units' => $taxBonus,
            ],
            'withholding_tax_minor_units' => $withholding,
        ];
        $netResult = [
            'person_reference' => "employee:{$this->employeeId}",
            'annual_settlement_minor_units' => $annualSettlement,
        ];
        (new PayrollStatutoryResultRepository($this->db))->store(
            $this->supplierId,
            $revisionId,
            'net_pay',
            'payroll-net-result.v1',
            'calculated',
            'cz-net-pay-2026',
            str_repeat('b', 64),
            $statutoryInput ?? ['schema_version' => 'payroll-run-input.v2'],
            [
                'net_payable_minor_units' => 0,
                'people' => ["employee:{$this->employeeId}"],
                'policy_hash' => str_repeat('c', 64),
                'policy_id' => 'cz-net-pay-policy-2026',
                'status' => 'calculated',
            ],
            [[
                'employee_id' => $this->employeeId,
                'input_snapshot' => ['synthetic' => true],
                'relationships' => [],
                'result_snapshot' => $netResult,
                'result_status' => 'calculated',
            ]],
            $this->actorId,
        );
        if ($storeStatutory) {
            (new PayrollStatutoryResultRepository($this->db))->store(
                $this->supplierId,
                $revisionId,
                'income_tax',
                'payroll-income-tax-result.v1',
                'calculated',
                'cz-income-tax-2026',
                str_repeat('b', 64),
                $statutoryInput
                    ?? ['schema_version' => 'payroll-run-input.v2'],
                [
                    'advance_tax_minor_units' => $rootAdvance ?? $advance,
                    'people' => ["employee:{$this->employeeId}"],
                    'policy_hash' => str_repeat('c', 64),
                    'policy_id' => 'cz-income-tax-policy-2026',
                    'ruleset_hash' => str_repeat('b', 64),
                    'ruleset_id' => 'cz-income-tax-2026',
                    'status' => 'calculated',
                    'tax_bonus_minor_units' => $taxBonus,
                    'withholding_tax_minor_units' => $withholding,
                ],
                [[
                    'employee_id' => $this->employeeId,
                    'input_snapshot' => ['synthetic' => true],
                    'relationships' => [],
                    'result_snapshot' => $personResult,
                    'result_status' => 'calculated',
                ]],
                $this->actorId,
            );
        }

        return $revisionId;
    }

    private function createTaxTarget(
        PayrollInstitutionAccountRepository $institutions,
        string $code,
        string $variableSymbol,
        string $specificSymbol,
        string $constantSymbol,
    ): void {
        $institutions->create($this->supplierId, [
            'institution_type' => 'tax_office',
            'institution_code' => $code,
            'institution_name' => "Syntetický finanční úřad {$code}",
            'bank_account' => '1000000005/0100',
            'currency_code' => 'CZK',
            'variable_symbol' => $variableSymbol,
            'specific_symbol' => $specificSymbol,
            'constant_symbol' => $constantSymbol,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'source_kind' => 'official_document',
            'source_reference' => "synthetic:tax-office:{$code}",
            'verified_on' => '2026-06-15',
        ], $this->actorId);
    }

    private function createEmployee(PDO $pdo): int
    {
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, "Syntetická daňová osoba", "employee", "hpp",
                     1, 1, 0, 10000, 0, 1)'
        )->execute([$this->supplierId]);

        return (int) $pdo->lastInsertId();
    }

    private function createActor(PDO $pdo): int
    {
        $pdo->prepare(
            'INSERT INTO users
                (email, password_hash, name, role, locale, is_active)
             VALUES (?, ?, "Syntetický daňový uživatel",
                     "accountant", "cs", 1)'
        )->execute([
            'payroll-income-tax-' . bin2hex(random_bytes(6))
                . '@example.invalid',
            '$2y$10$uses.only.synthetic.placeholder.hash00000000000000000',
        ]);

        return (int) $pdo->lastInsertId();
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

    /** @return array<string,mixed> */
    private function batchInstruction(int $batchId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT item_reference, instruction_ciphertext
               FROM payroll_payment_items
              WHERE supplier_id = ? AND batch_id = ?',
        );
        $statement->execute([$this->supplierId, $batchId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        $normalized = $this->object($row);
        $json = $this->encryption->decryptFor(
            $this->string($normalized, 'instruction_ciphertext'),
            "payroll-payment-item:{$this->supplierId}:"
                . $this->string($normalized, 'item_reference'),
        );
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return $this->object($decoded);
    }

    /** @return array<string,mixed> */
    private function liability(int $id): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_payment_liabilities
              WHERE supplier_id = ? AND id = ?'
        );
        $statement->execute([$this->supplierId, $id]);

        return $this->object($statement->fetch(PDO::FETCH_ASSOC));
    }

    private function liabilityCount(): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_payment_liabilities
              WHERE supplier_id = ?'
        );
        $statement->execute([$this->supplierId]);

        return (int) $statement->fetchColumn();
    }

    /** @return array<string,mixed> */
    private function object(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \UnexpectedValueException(
                'Testovací hodnota není objekt.',
            );
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    'Testovací objekt nemá textové klíče.',
                );
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /** @param array<string,mixed> $row */
    private function integer(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) && !is_string($value)) {
            throw new \UnexpectedValueException(
                "Testovací pole {$field} není číslo.",
            );
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false) {
            throw new \UnexpectedValueException(
                "Testovací pole {$field} není platné číslo.",
            );
        }

        return $integer;
    }

    /** @param array<string,mixed> $row */
    private function string(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value)) {
            throw new \UnexpectedValueException(
                "Testovací pole {$field} není text.",
            );
        }

        return $value;
    }
}
