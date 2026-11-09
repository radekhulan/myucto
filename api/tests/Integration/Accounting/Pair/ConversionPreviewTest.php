<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Pair;

use MyInvoice\Service\Accounting\Pair\JournalPairConversionJob;
use MyInvoice\Service\Accounting\Pair\JournalPairConversionService;
use MyInvoice\Service\Accounting\Pair\JournalPairException;
use MyInvoice\Service\Accounting\Pair\JournalPairer;
use MyInvoice\Service\Accounting\Pair\JournalPairInvariant;
use MyInvoice\Service\ActivityLogger;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Náhled převodu starších zápisů na dvojice: klasifikace auto / composite_mn /
 * ineligible s důvodem, seznam k ruční úpravě, a hlavně že náhled v deníku nic
 * nezmění (řádky, verze zápisů, stavy dvojic).
 */
#[Group('integration')]
final class ConversionPreviewTest extends TestCase
{
    use PairConversionFixture;

    private JournalPairConversionService $service;
    private JournalPairConversionJob $job;
    /** @var list<int> */
    private array $launched = [];

    protected function setUp(): void
    {
        $this->setUpFixture();
        $this->service = new JournalPairConversionService(
            $this->db,
            $this->container->get(ActivityLogger::class),
            function (int $runId): ?string {
                $this->launched[] = $runId;
                return null;
            },
        );
        $this->job = $this->container->get(JournalPairConversionJob::class);
    }

    protected function tearDown(): void
    {
        $this->tearDownFixture();
    }

    public function testPreviewClassifiesEntriesAndWritesNothing(): void
    {
        $pdo = $this->db->pdo();
        $auto = $this->invoiceOneToMany('2098-02-01');
        $simple = $this->entry('2098-02-02', [['518', 'debit', '50.00'], ['321', 'credit', '50.00']], 'purchase_invoice');
        $manyToMany = $this->manyToMany('2098-02-03');
        $sameAccount = $this->entry('2098-02-04', [['311', 'debit', '80.00'], ['311', 'credit', '80.00']]);
        $closed = $this->invoiceOneToMany('2097-06-01');
        $settled = $this->invoiceOneToMany('2098-02-05');
        $settledCounter = $this->invoiceOneToMany('2098-02-06');
        $saldo = $this->invoiceOneToMany('2098-02-07');
        $typed = $this->entry('2098-02-08', [['311', 'debit', '30.00'], ['602', 'credit', '30.00']], 'nonprofit_capital');
        $reversed = $this->invoiceOneToMany('2098-02-09');
        $reversal = $this->entry('2098-02-10', [['311', 'credit', '1210.00'], ['602', 'debit', '1000.00'], ['343', 'debit', '210.00']]);
        $pdo->prepare('UPDATE journal_entries SET reversed_by = ? WHERE id = ?')->execute([$reversal, $reversed]);
        $alreadyPaired = $this->entry('2098-02-11', [['518', 'debit', '10.00', 1], ['221', 'credit', '10.00', 1]]);
        $draft = $this->entry('2098-02-12', [['518', 'debit', '10.00'], ['221', 'credit', '10.00']], posted: false);

        // Rozpad hubu (311, line_no 1) na doklad: dělený řádek → nezpůsobilé.
        $allocation = $pdo->prepare(
            "INSERT INTO journal_settlement_allocations (supplier_id, entry_id, line_no, account_id, side, kind, doc_type, doc_id, amount)
             VALUES (?, ?, ?, ?, ?, 'settlement', 'invoice', 1, ?)"
        );
        $allocation->execute([$this->supplierId, $settled, 1, $this->accounts['311'], 'debit', '1210.00']);
        // Vazba na NEdělený řádek (602, line_no 2) převod neblokuje.
        $allocation->execute([$this->supplierId, $settledCounter, 2, $this->accounts['602'], 'credit', '1000.00']);
        $pdo->prepare("INSERT INTO journal_line_pairing (supplier_id, entry_id, line_no, pairing_symbol) VALUES (?, ?, 1, 'SYM1')")
            ->execute([$this->supplierId, $saldo]);

        $fingerprint = $this->journalFingerprint();

        $runId = $this->service->preview($this->supplierId, null, null, $this->actorId);
        self::assertSame([$runId], $this->launched);
        self::assertSame('queued', $this->service->run($this->supplierId, $runId)['status']);
        $this->job->run($runId);

        $run = $this->service->run($this->supplierId, $runId);
        self::assertSame('done', $run['status']);
        self::assertSame('preview', $run['mode']);
        $counts = $run['counts'];
        self::assertSame(12, $counts['total']);
        self::assertSame(12, $counts['processed']);
        self::assertSame(1, $counts['paired']);
        self::assertSame(3, $counts['auto'], 'auto: 1 : N, dvouřádkový, vazba na nedělený řádek');
        self::assertSame(2, $counts['composite_mn']);
        self::assertSame(6, $counts['ineligible']);
        self::assertSame([JournalPairer::REASON_MANY_TO_MANY => 1, JournalPairInvariant::SAME_ACCOUNT => 1], self::sorted($counts['composite_mn_reasons']));
        self::assertSame(self::sorted([
            JournalPairConversionJob::REASON_CLOSED_PERIOD          => 1,
            JournalPairConversionJob::REASON_SETTLEMENT_ALLOCATION  => 1,
            JournalPairConversionJob::REASON_SALDO_PAIRING          => 1,
            JournalPairConversionJob::REASON_TYPED_OWNER            => 1,
            JournalPairConversionJob::REASON_REVERSED               => 1,
            JournalPairConversionJob::REASON_REVERSAL               => 1,
        ]), self::sorted($counts['ineligible_reasons']));
        self::assertSame(2, $counts['by_source_type']['invoice']['auto']);
        self::assertSame(1, $counts['by_source_type']['purchase_invoice']['auto']);

        $byEntry = array_column($run['sample'], null, 'entry_id');
        self::assertSame('composite_mn', $byEntry[$manyToMany]['status']);
        self::assertSame('composite_mn', $byEntry[$sameAccount]['status']);
        self::assertSame(JournalPairConversionJob::REASON_CLOSED_PERIOD, $byEntry[$closed]['reason']);
        self::assertSame(JournalPairConversionJob::REASON_SETTLEMENT_ALLOCATION, $byEntry[$settled]['reason']);
        self::assertSame(JournalPairConversionJob::REASON_SALDO_PAIRING, $byEntry[$saldo]['reason']);
        self::assertSame(JournalPairConversionJob::REASON_TYPED_OWNER, $byEntry[$typed]['reason']);
        foreach ([$auto, $simple, $settledCounter, $alreadyPaired, $draft] as $notListed) {
            self::assertArrayNotHasKey($notListed, $byEntry);
        }
        // K ruční úpravě (M : N) jdou v seznamu první.
        self::assertSame('composite_mn', $run['sample'][0]['status']);

        self::assertSame($fingerprint, $this->journalFingerprint(), 'Náhled nesmí změnit deník ani stavy dvojic.');
    }

    public function testPreviewRespectsDateWindowAndLock(): void
    {
        $locked = $this->invoiceOneToMany('2098-01-15');
        $this->invoiceOneToMany('2098-02-15');
        $this->invoiceOneToMany('2098-05-15');
        $this->db->pdo()->prepare(
            'INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)'
        )->execute([$this->supplierId, '2098-01-31']);

        $runId = $this->service->preview($this->supplierId, '2098-01-01', '2098-03-31', $this->actorId);
        $this->job->run($runId);
        $counts = $this->service->run($this->supplierId, $runId)['counts'];

        self::assertSame(2, $counts['total']);
        self::assertSame(1, $counts['auto']);
        self::assertSame([JournalPairConversionJob::REASON_DATE_LOCKED => 1], $counts['ineligible_reasons']);
        self::assertContains($locked, array_column($this->service->run($this->supplierId, $runId)['sample'] ?? [], 'entry_id'));
    }

    public function testPreviewWalksManyBatches(): void
    {
        $count = JournalPairConversionJob::BATCH + 3;
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO journal_entries (supplier_id, period_id, entry_date, source_type, posted_at)
             SELECT ?, ?, '2098-04-01', 'manual', '2098-04-01 10:00:00' FROM seq_1_to_{$count}"
        )->execute([$this->supplierId, $this->openPeriodId]);
        $pdo->prepare(
            "INSERT INTO journal_entry_lines (entry_id, supplier_id, account_id, side, amount, line_no)
             SELECT e.id, e.supplier_id, IF(s.seq = 1, ?, ?), IF(s.seq = 1, 'debit', 'credit'), 5.00, s.seq
               FROM journal_entries e JOIN seq_1_to_2 s
              WHERE e.supplier_id = ?"
        )->execute([$this->accounts['518'], $this->accounts['221'], $this->supplierId]);

        $runId = $this->service->preview($this->supplierId, null, null, $this->actorId);
        $this->job->run($runId);
        $counts = $this->service->run($this->supplierId, $runId)['counts'];

        self::assertSame($count, $counts['total']);
        self::assertSame($count, $counts['processed']);
        self::assertSame($count, $counts['auto']);
    }

    public function testCancelStopsQueuedRun(): void
    {
        $this->invoiceOneToMany('2098-02-01');
        $runId = $this->service->preview($this->supplierId, null, null, $this->actorId);

        self::assertTrue($this->service->cancel($this->supplierId, $runId, $this->actorId));
        $this->job->run($runId);
        $run = $this->service->run($this->supplierId, $runId);
        self::assertSame('cancelled', $run['status']);
        self::assertNull($run['counts']);
        self::assertFalse($this->service->cancel($this->supplierId, $runId, $this->actorId), 'Skončený běh už zrušit nejde.');

        $this->expectException(JournalPairException::class);
        $this->service->cancel($this->supplierId, $runId + 1000, $this->actorId);
    }

    public function testRunsAreTenantScopedAndApplyIsExclusive(): void
    {
        $runId = $this->service->apply($this->supplierId, null, null, $this->actorId);
        self::assertNull($this->service->run($this->supplierId + 1000000, $runId));
        self::assertSame([$runId], array_column($this->service->runs($this->supplierId), 'id'));

        try {
            $this->service->apply($this->supplierId, null, null, $this->actorId);
            self::fail('Druhý souběžný převod musí skončit 409.');
        } catch (JournalPairException $e) {
            self::assertSame('conversion_in_progress', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
        }
        // Náhled souběžný převod neblokuje.
        self::assertGreaterThan($runId, $this->service->preview($this->supplierId, null, null, $this->actorId));
    }

    public function testCompositeTaxEvidenceSupplierIsRejected(): void
    {
        $this->db->pdo()->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = ?")->execute([$this->supplierId]);
        try {
            $this->service->preview($this->supplierId, null, null, $this->actorId);
            self::fail('Daňová evidence nemá deník k převodu.');
        } catch (JournalPairException $e) {
            self::assertSame('accounting_not_double_entry', $e->errorCode);
        }
        self::assertSame([], $this->launched);
    }

    public function testFailedWorkerStartMarksRunFailed(): void
    {
        $service = new JournalPairConversionService($this->db, $this->container->get(ActivityLogger::class), static fn (int $id): ?string => 'php cli not found');
        try {
            $service->preview($this->supplierId, null, null, $this->actorId);
            self::fail('Nespuštěný worker musí skončit chybou.');
        } catch (JournalPairException $e) {
            self::assertSame('worker_start_failed', $e->errorCode);
            $run = $service->run($this->supplierId, (int) $e->context['run_id']);
            self::assertSame('failed', $run['status']);
            self::assertStringContainsString('php cli not found', (string) $run['error']);
        }
    }

    /** Otisk deníku firmy: řádky, verze zápisů, stavy dvojic, počet verzí v historii. */
    private function journalFingerprint(): string
    {
        $pdo = $this->db->pdo();
        $parts = [];
        foreach ([
            'SELECT id, entry_id, account_id, side, amount, line_no, pair_no, line_text FROM journal_entry_lines WHERE supplier_id = ? ORDER BY id',
            'SELECT id, row_version, reversed_by FROM journal_entries WHERE supplier_id = ? ORDER BY id',
            'SELECT entry_id, status, reason FROM journal_entry_pair_status WHERE supplier_id = ? ORDER BY entry_id',
            'SELECT COUNT(*) FROM journal_entry_lines FOR SYSTEM_TIME ALL WHERE supplier_id = ?',
        ] as $sql) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$this->supplierId]);
            $parts[] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }

    /** @param array<string,int> $map @return array<string,int> */
    private static function sorted(array $map): array
    {
        ksort($map);
        return $map;
    }
}
