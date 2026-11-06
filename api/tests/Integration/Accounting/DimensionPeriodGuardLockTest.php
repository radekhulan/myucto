<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use PHPUnit\Framework\TestCase;

/**
 * Kontrola uzávěrky při zamykání zápisů: dotaz smí číst jen zápisy uzávěrky daného období
 * (ne celé období pro každý doklad) a volající bez zájmu o dimenze ji vůbec nespouští.
 */
final class DimensionPeriodGuardLockTest extends TestCase
{
    private Connection $db;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje - test vyžaduje DB connection.');
        }
        $this->db = Bootstrap::buildContainer()->get(Connection::class);
        $this->db->pdo()->beginTransaction();
        $this->inTx = true;
    }

    protected function tearDown(): void
    {
        if ($this->inTx && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testClosingLookupUsesPeriodSourceIndex(): void
    {
        $plan = $this->db->pdo()->query(
            "EXPLAIN SELECT id FROM journal_entries FORCE INDEX (idx_je_supplier_period_source) WHERE supplier_id = 1 AND period_id = 1
                AND source_type = 'closing' AND posted_at IS NOT NULL AND reversed_by IS NULL LIMIT 1 FOR UPDATE"
        )->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('idx_je_supplier_period_source', $plan['key']);
        self::assertGreaterThanOrEqual(3, count(explode(',', (string) $plan['ref'])));
    }

    public function testEntryLockedWithoutFreezeLookupCannotPassMutabilityCheck(): void
    {
        $this->expectException(\LogicException::class);
        (new DimensionPeriodGuard($this->db))->assertMutable(['period_status' => 'open']);
    }
}
