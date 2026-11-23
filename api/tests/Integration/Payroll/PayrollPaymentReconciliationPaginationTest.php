<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentEvidenceReference;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentReconciliationCommand;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentReconciliationQueryService;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentReconciliationService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Historie párování plateb v `GET /payroll/payments/reconciliation` nesmí
 * vracet všechny události období naráz.
 *
 * Historie je append-only: každé částečné plnění i každé storno přidá řádek
 * a nikdy žádný neubude. Za období firmy s pár stovkami závazků a splátkovými
 * platbami to jsou tisíce událostí v jedné odpovědi.
 *
 * Test hlídá i past, kvůli které se to nedalo stránkovat naivně: nabídka
 * „co lze stornovat" se NESMÍ brát ze zobrazené stránky historie, jinak by
 * uživatel mohl stornovat jen to, co má zrovna na obrazovce.
 */
#[Group('integration')]
final class PayrollPaymentReconciliationPaginationTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD = '2099-01';

    private Connection $connection;
    private PDO $pdo;
    private PayrollPaymentReconciliationService $service;
    private PayrollPaymentReconciliationQueryService $queries;
    private int $supplierId;
    private int $allocationId;
    private int $statementId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
            $this->connection = $container->get(Connection::class);
            $this->service = $container->get(PayrollPaymentReconciliationService::class);
            $this->queries = $container->get(PayrollPaymentReconciliationQueryService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $this->pdo = $this->connection->pdo();
        $this->pdo->beginTransaction();

        $sourceSupplierId = (int) ($this->pdo->query('SELECT MIN(id) FROM supplier')
            ->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0) {
            $this->markTestSkipped('Chybí výchozí firma.');
        }
        $this->supplierId = $this->createIsolatedSupplier($this->pdo, $sourceSupplierId);
        [$revisionId, $employeeId] = $this->createApprovedRevision();
        $liabilityId = $this->insertLiability($revisionId, $employeeId, 1_000_000);
        $this->allocationId = $this->insertAllocation($liabilityId, 1_000_000);
        $this->statementId = $this->insertBankStatement();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        if (isset($this->connection)) {
            $this->connection->close();
        }
    }

    /** Strop je tvrdý a `matches_total` počítá všechny události období. */
    public function testCapCannotBeLiftedByAParameter(): void
    {
        $this->seedMatches(6);

        $page = $this->queries->forPeriod($this->supplierId, self::PERIOD, 2, 0);

        self::assertCount(2, $page['matches'], 'Limit musí historii skutečně omezit.');
        self::assertSame(
            6,
            $page['matches_total'],
            'Total je počet všech událostí, ne velikost stránky.',
        );
        self::assertSame(2, $page['matches_limit']);
        self::assertSame(0, $page['matches_offset']);

        $overLimit = $this->queries->forPeriod($this->supplierId, self::PERIOD, 10_000, 0);
        self::assertSame(
            PayrollPaymentReconciliationQueryService::LIST_MAX_LIMIT,
            $overLimit['matches_limit'],
            'Strop nejde obejít vyšším limitem.',
        );
    }

    /** Druhá stránka musí vrátit jiné události a poslední nesmí přetéct. */
    public function testOffsetShiftsThePage(): void
    {
        $this->seedMatches(5);

        $first = $this->queries->forPeriod($this->supplierId, self::PERIOD, 2, 0);
        $second = $this->queries->forPeriod($this->supplierId, self::PERIOD, 2, 2);
        $last = $this->queries->forPeriod($this->supplierId, self::PERIOD, 2, 4);

        self::assertCount(2, $first['matches']);
        self::assertCount(2, $second['matches']);
        self::assertCount(1, $last['matches'], 'Poslední stránka nesmí přetéct.');
        self::assertSame(5, $second['matches_total'], 'Total se posunem stránky nemění.');
        self::assertSame(
            [],
            array_intersect($this->ids($first['matches']), $this->ids($second['matches'])),
            'Stránky se nesmí překrývat.',
        );
        self::assertSame(
            [],
            $this->queries->forPeriod($this->supplierId, self::PERIOD, 2, 5)['matches'],
            'Za koncem seznamu je prázdno, ne zopakovaná poslední stránka.',
        );
    }

    /**
     * Nabídka storna nesmí záviset na tom, kterou stránku historie uživatel čte.
     *
     * Tohle je celý důvod, proč je `reversible_matches` samostatná kolekce:
     * kdyby se odvozovala z `matches`, zmizely by z výběru události ležící
     * na jiné straně a storno by šlo udělat jen náhodou.
     */
    public function testReversibleOfferIsIndependentOfTheHistoryPage(): void
    {
        $seeded = $this->seedMatches(5);

        $firstPage = $this->queries->forPeriod($this->supplierId, self::PERIOD, 2, 0);
        $lastPage = $this->queries->forPeriod($this->supplierId, self::PERIOD, 2, 4);

        self::assertSame(
            $seeded,
            $this->ids($firstPage['reversible_matches']),
            'Nabídka storna obsahuje všechny vratné události, ne jen zobrazenou stránku.',
        );
        self::assertSame(
            $this->ids($firstPage['reversible_matches']),
            $this->ids($lastPage['reversible_matches']),
            'Nabídka storna se listováním historie nemění.',
        );
        foreach ($firstPage['reversible_matches'] as $match) {
            self::assertIsArray($match);
            self::assertSame('matched', $match['event_kind']);
            self::assertGreaterThan(0, $match['reversible_minor']);
        }
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<int>
     */
    private function ids(array $rows): array
    {
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        sort($ids);

        return $ids;
    }

    /**
     * Události se zakládají skutečnou službou, ne přímým INSERTem — tabulka
     * párování má triggery i CHECK omezení, která syntetický řádek neprojde.
     *
     * @return list<int> id založených událostí
     */
    private function seedMatches(int $count): array
    {
        $ids = [];
        for ($i = 0; $i < $count; ++$i) {
            $transactionId = $this->insertBankTransaction(
                sprintf('2099-01-%02d', 10 + $i),
                '-100.00',
                'part-' . $i,
            );
            $result = $this->service->match(
                new PayrollPaymentReconciliationCommand(
                    $this->supplierId,
                    $this->allocationId,
                    10_000,
                    PayrollPaymentEvidenceReference::bank(
                        $this->statementId,
                        $transactionId,
                    ),
                    'bank-part-' . $i,
                    null,
                ),
            );
            $ids[] = $result->id;
        }
        sort($ids);

        return $ids;
    }

    /** @return array{int,int} */
    private function createApprovedRevision(string $periodStart = '2099-01-01'): array
    {
        $this->pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetická platební osoba", "employee", 1)',
        )->execute([$this->supplierId]);
        $employeeId = (int) $this->pdo->lastInsertId();

        $paymentDate = substr($periodStart, 0, 8) . '10';
        $this->pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status)
             VALUES (?, ?, ?, "approved")',
        )->execute([$this->supplierId, $periodStart, $paymentDate]);
        $runId = (int) $this->pdo->lastInsertId();

        $snapshot = '{"schema":"synthetic-payroll-result.v1"}';
        $snapshotHash = hash('sha256', $snapshot);
        $this->pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json,
                 result_snapshot_hash, idempotency_key_hash, approved_at)
             VALUES (?, ?, 1, "approved", "synthetic-payment.v1",
                     ?, ?, ?, ?, ?, ?, NOW())',
        )->execute([
            $this->supplierId,
            $runId,
            str_repeat('a', 64),
            $snapshot,
            $snapshotHash,
            $snapshot,
            $snapshotHash,
            hash('sha256', "synthetic-pagination-revision-{$periodStart}", true),
        ]);
        $revisionId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, employee_id, result_json,
                 result_hash, status)
             VALUES (?, ?, ?, ?, ?, "calculated")',
        )->execute([
            $this->supplierId,
            $revisionId,
            $employeeId,
            $snapshot,
            $snapshotHash,
        ]);

        return [$revisionId, $employeeId];
    }

    private function insertLiability(
        int $revisionId,
        int $employeeId,
        int $amountMinor,
    ): int {
        $snapshot = '{"schema":"synthetic-liability.v1"}';
        $this->pdo->prepare(
            'INSERT INTO payroll_payment_liabilities
                (supplier_id, revision_id, employee_id, liability_reference,
                 liability_kind, direction, recipient_reference, due_on,
                 currency_code, amount_minor, source_snapshot_json,
                 source_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, ?, "net-wage.pagination", "net_wage", "outgoing",
                     "recipient:synthetic", "2099-01-10", "CZK", ?, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $revisionId,
            $employeeId,
            $amountMinor,
            $snapshot,
            hash('sha256', $snapshot),
            hash('sha256', 'liability-pagination', true),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertAllocation(int $liabilityId, int $amountMinor): int
    {
        $reference = "bank-{$liabilityId}";
        $this->pdo->prepare(
            'INSERT INTO payroll_payment_batches
                (supplier_id, batch_reference, channel, export_format,
                 direction, planned_payment_date, currency_code,
                 payer_reference, declared_total_minor, declared_item_count,
                 snapshot_ciphertext, snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, "bank", "manual", "outgoing", "2099-01-10", "CZK",
                     "payer:synthetic", ?, 1, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            "batch-{$reference}",
            $amountMinor,
            'enc:v2:synthetic-batch',
            hash('sha256', "batch-{$reference}"),
            hash('sha256', "batch-{$reference}", true),
        ]);
        $batchId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO payroll_payment_items
                (supplier_id, batch_id, item_reference, recipient_reference,
                 amount_minor, instruction_ciphertext, instruction_hash,
                 idempotency_key_hash)
             VALUES (?, ?, ?, "recipient:synthetic", ?, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $batchId,
            "item-{$reference}",
            $amountMinor,
            'enc:v2:synthetic-instruction',
            hash('sha256', "item-{$reference}"),
            hash('sha256', "item-{$reference}", true),
        ]);
        $itemId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO payroll_payment_allocations
                (supplier_id, item_id, liability_id, amount_minor,
                 idempotency_key_hash)
             VALUES (?, ?, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $itemId,
            $liabilityId,
            $amountMinor,
            hash('sha256', "allocation-{$reference}", true),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertBankStatement(): int
    {
        $this->pdo->prepare(
            'INSERT INTO bank_statements
                (supplier_id, file_name, file_hash, account_number,
                 bank_code, currency, statement_date)
             VALUES (?, "synthetic-payroll-pagination.gpc", ?,
                     "1000000005", "0100", "CZK", "2099-01-31")',
        )->execute([
            $this->supplierId,
            hash('sha256', "synthetic-payroll-pagination-{$this->supplierId}"),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertBankTransaction(
        string $postedAt,
        string $amount,
        string $reference,
    ): int {
        $this->pdo->prepare(
            'INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, description,
                 import_fingerprint)
             VALUES (?, ?, ?, "CZK", ?, ?)',
        )->execute([
            $this->statementId,
            $postedAt,
            $amount,
            "Syntetická mzdová platba {$reference}",
            hash('sha256', "synthetic-bank-{$this->supplierId}-{$reference}"),
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
