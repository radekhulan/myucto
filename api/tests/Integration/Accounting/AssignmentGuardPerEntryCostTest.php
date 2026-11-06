<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Allocation\JointAllocationUseGuard;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Property\Services\Settlement\SettlementUseGuard;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Strážci vložení dimenzí na řádky zápisu vyhodnocují odkazy agend SVJ, NNO a alokací
 * jednou za zápis, ne za každý jeho řádek. Uzávěrkový zápis má stovky řádků a každý
 * řádek dřív spustil přes dvacet závislých poddotazů se zámky (kolem 0,3 s na zápis).
 */
#[Group('integration')]
final class AssignmentGuardPerEntryCostTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PostingService $posting;
    private int $supplier;
    private int $actor;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
        $pdo->beginTransaction();
        $this->supplier = $this->createIsolatedSupplier($pdo, (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn());
        $this->actor = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        (new AccountingPeriodRepository($this->db))->create($this->supplier, 2026, '2026-01-01', '2026-12-31');
        (new ChartOfAccountsSeeder($this->db))->seedForSupplier($this->supplier, '2026-01-01');
        $this->posting = $container->get(PostingService::class);
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
    }

    public function testGuardReadsDoNotGrowWithEveryLineOfOneEntry(): void
    {
        $small = $this->entryLines(2);
        $large = $this->entryLines(200);
        $joint = new JointAllocationUseGuard($this->db);
        $settlement = new SettlementUseGuard($this->db);

        $smallJoint = $this->indexReads(fn () => $joint->assertAssignmentInsertsMutable($this->supplier, $small));
        $largeJoint = $this->indexReads(fn () => $joint->assertAssignmentInsertsMutable($this->supplier, $large));
        // Řádek sám stojí několik čtení indexu (řádek, hlavička, nároky alokací); odkazy
        // zápisu za řádek by přidaly desítky dalších.
        self::assertLessThan(198 * 12, $largeJoint - $smallJoint, 'Odkazy zápisu se nesmějí vyhodnocovat za každý řádek.');

        $smallSettlement = $this->indexReads(fn () => $settlement->mutableAssignmentEntries($this->supplier, $small));
        $largeSettlement = $this->indexReads(fn () => $settlement->mutableAssignmentEntries($this->supplier, $large));
        self::assertLessThan(198 * 6, $largeSettlement - $smallSettlement, 'Odkaz vyúčtování se nesmí vyhodnocovat za každý řádek.');
        self::assertCount(1, $settlement->mutableAssignmentEntries($this->supplier, $large));
    }

    /** Odkaz na zápis zachytí strážce u kteréhokoli jeho řádku, i když jsou v dotazu řádky dvou zápisů. */
    public function testReferencedEntryIsStillRejectedForAnyOfItsLines(): void
    {
        $free = $this->entryLines(2);
        $lines = $this->entryLines(4);
        $entry = (int) $this->db->pdo()->query('SELECT entry_id FROM journal_entry_lines WHERE id=' . $lines[3])->fetchColumn();
        $pdo = $this->db->pdo();
        // Syntetický odkaz bez celé platební agendy; cizí klíče se po vložení hned vracejí.
        $pdo->exec('SET SESSION foreign_key_checks = 0');
        try {
            $pdo->prepare("INSERT INTO unit_payment_source_revisions (supplier_id, source_id, revision_no, expected_source_version, journal_entry_id, status, accounting_date, effective_at, request_json, snapshot_json, request_hash, snapshot_hash, idempotency_key, created_by)
                VALUES (?, 1, 1, 1, ?, 'posted', '2026-03-01', '2026-03-01 00:00:00', '{}', '{}', REPEAT('a', 64), REPEAT('b', 64), 'synthetic-guard-reference', ?)")
                ->execute([$this->supplier, $entry, $this->actor]);
        } finally {
            $pdo->exec('SET SESSION foreign_key_checks = 1');
        }
        (new JointAllocationUseGuard($this->db))->assertAssignmentInsertsMutable($this->supplier, $free);
        try {
            (new JointAllocationUseGuard($this->db))->assertAssignmentInsertsMutable($this->supplier, [...$free, $lines[3]]);
            self::fail('Řádek zápisu použitého platbou SVJ prošel strážcem.');
        } catch (\MyInvoice\Service\Accounting\PostingException $e) {
            self::assertStringContainsString('source_immutable', $e->errorCode);
        }
    }

    /** @return list<int> řádky jednoho ručního zápisu */
    private function entryLines(int $count): array
    {
        $lines = [];
        for ($i = 0; $i < $count / 2; $i++) {
            $lines[] = ['account_code' => '221', 'side' => 'debit', 'amount' => '10.00'];
            $lines[] = ['account_code' => '321', 'side' => 'credit', 'amount' => '10.00'];
        }
        $entry = $this->posting->postDocument($this->supplier, 'manual', null, $lines, ['entry_date' => '2026-03-01', 'posted_by' => $this->actor]);
        $stmt = $this->db->pdo()->prepare('SELECT id FROM journal_entry_lines WHERE supplier_id = ? AND entry_id = ? ORDER BY id');
        $stmt->execute([$this->supplier, $entry]);
        $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        self::assertCount($count, $ids);
        return $ids;
    }

    private function indexReads(callable $work): int
    {
        $pdo = $this->db->pdo();
        $reads = static function () use ($pdo): int {
            $sum = 0;
            foreach ($pdo->query("SHOW SESSION STATUS WHERE Variable_name IN ('Handler_read_key','Handler_read_next')")->fetchAll(\PDO::FETCH_NUM) as $row) $sum += (int) $row[1];
            return $sum;
        };
        $before = $reads();
        $work();
        return $reads() - $before;
    }
}
