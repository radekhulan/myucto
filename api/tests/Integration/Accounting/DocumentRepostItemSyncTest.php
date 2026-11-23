<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Service\Accounting\DocumentJournalSync;
use MyInvoice\Service\Accounting\DocumentRepostService;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Přeúčtování faktury se propíše i na její položky, takže ho nevrátí další úprava
 * dokladu (force-edit staví zápis znovu z položek přes buildFromInvoice /
 * buildFromPurchaseInvoice).
 */
#[Group('integration')]
final class DocumentRepostItemSyncTest extends BankPostingTestCase
{
    private DocumentRepostService $repost;
    private DocumentJournalSync $sync;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repost = $this->container->get(DocumentRepostService::class);
        $this->sync = $this->container->get(DocumentJournalSync::class);
    }

    private function vatRateId(): int
    {
        return (int) $this->db->pdo()->query('SELECT MIN(id) FROM vat_rates')->fetchColumn();
    }

    private function postedSaleInvoice(): int
    {
        $docId = $this->saleInvoice('SYN-RB-' . random_int(1000, 9999), $this->client('Syntetický odběratel'), 1000.0);
        $this->db->pdo()->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, "Služba", 1, "ks", 1000, ?, 0, 1000, 0, 1000, 0)'
        )->execute([$docId, $this->vatRateId()]);
        $this->posting->postDocument($this->supplierId, 'invoice', $docId,
            $this->posting->buildFromInvoice($this->supplierId, $docId), ['entry_date' => self::YEAR . '-06-10', 'user_id' => $this->userId, 'posted' => true]);
        return $docId;
    }

    private function postedPurchaseInvoice(): int
    {
        $docId = $this->purchaseInvoice('SYN-RB-P-' . random_int(1000, 9999), $this->client('Syntetický dodavatel'), 1000.0);
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, "Materiál", 1, "ks", 1000, ?, 0, 1000, 0, 1000, 0)'
        )->execute([$docId, $this->vatRateId()]);
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $docId,
            $this->posting->buildFromPurchaseInvoice($this->supplierId, $docId), ['entry_date' => self::YEAR . '-06-10', 'user_id' => $this->userId, 'posted' => true]);
        return $docId;
    }

    /** @return list<array{account_code:string, side:string, amount:float, is_red_storno:bool}> */
    private function replaced(array $lines, string $prefix, string $to): array
    {
        $found = false;
        $out = array_map(static function (array $l) use ($prefix, $to, &$found): array {
            if (str_starts_with((string) $l['account_code'], $prefix)) {
                $l['account_code'] = $to;
                $found = true;
            }
            return ['account_code' => (string) $l['account_code'], 'side' => $l['side'], 'amount' => (float) $l['amount'], 'is_red_storno' => (bool) ($l['is_red_storno'] ?? false)];
        }, $lines);
        self::assertTrue($found, 'Zápis nemá řádek na účtu ' . $prefix);
        return $out;
    }

    private function activeCodes(string $sourceType, int $docId): array
    {
        $entry = $this->journal->findBySource($this->supplierId, $sourceType, $docId);
        self::assertNotNull($entry);
        return array_map('strval', array_keys($this->linesByAccountCode((int) $entry['id'])));
    }

    public function testIssuedInvoiceRepostSurvivesForceEditRebuild(): void
    {
        $docId = $this->postedSaleInvoice();
        $plan = $this->repost->plan($this->supplierId, 'invoice', $docId);
        $result = $this->repost->repost($this->supplierId, 'invoice', $docId,
            $this->replaced($plan['lines'], '60', '604'), ['user_id' => $this->userId]);
        self::assertSame(1, $result['items_synced']);

        $this->sync->repostForceEdit($this->supplierId, 'invoice', $docId, ['user_id' => $this->userId]);

        $codes = $this->activeCodes('invoice', $docId);
        self::assertContains('604', $codes);
        self::assertNotContains('602', $codes);
    }

    public function testPurchaseInvoiceRepostOfRuleAccountSurvivesForceEditRebuild(): void
    {
        $docId = $this->postedPurchaseInvoice();
        $plan = $this->repost->plan($this->supplierId, 'purchase_invoice', $docId);
        $from = null;
        foreach ($plan['lines'] as $line) {
            if (str_starts_with((string) $line['account_code'], '5')) $from = (string) $line['account_code'];
        }
        self::assertNotNull($from);
        $to = $from === '501' ? '518' : '501';
        $result = $this->repost->repost($this->supplierId, 'purchase_invoice', $docId,
            $this->replaced($plan['lines'], $from, $to), ['user_id' => $this->userId]);
        self::assertSame(1, $result['items_synced']);

        $this->sync->repostForceEdit($this->supplierId, 'purchase_invoice', $docId, ['user_id' => $this->userId]);

        $codes = $this->activeCodes('purchase_invoice', $docId);
        self::assertContains($to, $codes);
        self::assertNotContains($from, $codes);
    }

    public function testNonItemAccountIsNotWrittenToItems(): void
    {
        $docId = $this->postedSaleInvoice();
        $plan = $this->repost->plan($this->supplierId, 'invoice', $docId);
        $result = $this->repost->repost($this->supplierId, 'invoice', $docId,
            $this->replaced($plan['lines'], '311', '315'), ['user_id' => $this->userId]);
        self::assertSame(0, $result['items_synced']);
        $stmt = $this->db->pdo()->prepare('SELECT revenue_account_code FROM invoice_items WHERE invoice_id = ?');
        $stmt->execute([$docId]);
        self::assertNull($stmt->fetchColumn());
    }
}
