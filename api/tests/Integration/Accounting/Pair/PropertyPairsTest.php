<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Pair;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceRepository;
use MyInvoice\Service\Property\Charges\Issuance\IssuancePreviewService;
use MyInvoice\Tests\Support\ChargeIssuanceFixture;
use MyInvoice\Tests\Support\PairedJournalAssertions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * SVJ: předpis záloh jednotky po dvojicích pohledávka 315 / fond nebo služba 324.x,
 * součet dvojic = předpis, a důkaz zápisu předpisu (IssuanceJournalProof) zápis
 * jednoduché formy přijme.
 */
#[Group('integration')]
#[Group('pairs-e2e')]
final class PropertyPairsTest extends TestCase
{
    use ChargeIssuanceFixture;
    use PairedJournalAssertions;

    private Connection $db;

    protected function setUp(): void
    {
        $this->db = Bootstrap::buildContainer()->get(Connection::class);
        self::assertStringEndsWith('_test', (string) $this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->db->pdo()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testChargeIssuanceIsPairedPerComponent(): void
    {
        $f = $this->issuanceFixture($this->db);
        $this->enableSingleEntryForm($this->db->pdo(), $f['supplier'], '2026-01-01');
        $request = $f['issue_request'];
        $preview = (new IssuancePreviewService($this->db))->preview($f['supplier'], $request);
        $repository = Bootstrap::buildContainer()->get(IssuanceRepository::class);
        $batch = $repository->issue($f['supplier'], ['request' => $request, 'preview_hash' => $preview['preview_hash'], 'idempotency_key' => 'pair-batch'], $f['actor']);

        $posted = $repository->post($f['supplier'], $batch['id'], array_map(static fn (array $c): array => ['id' => $c['id'], 'row_version' => 1], $batch['charges']), $f['actor']);

        foreach ($posted['charges'] as $charge) {
            $pairs = $this->assertEntryPaired($this->db->pdo(), $f['supplier'], (int) $charge['journal_entry_id']);
            foreach ($pairs as [$debit, $credit]) {
                self::assertSame('315', substr($debit, 0, 3));
                self::assertNotSame('315', substr($credit, 0, 3));
            }
        }
    }
}
