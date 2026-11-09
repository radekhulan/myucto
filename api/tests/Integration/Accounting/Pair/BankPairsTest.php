<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Pair;

use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use MyInvoice\Tests\Support\PairedJournalAssertions;
use PHPUnit\Framework\Attributes\Group;

/**
 * Hromadná platba ze Zadání: jedna odchozí platba dvou přijatých faktur = dvojice
 * 321/221 pro každou úhradu, součet = částka pohybu.
 */
#[Group('integration')]
#[Group('pairs-e2e')]
final class BankPairsTest extends BankPostingTestCase
{
    use PairedJournalAssertions;

    public function testBulkPaymentIsOnePairPerInvoice(): void
    {
        $pdo = $this->db->pdo();
        $this->enableSingleEntryForm($pdo, $this->supplierId, self::YEAR . '-01-01');
        $vendor = $this->client('Dodavatel hromadné platby');
        $first = $this->purchaseInvoice('FP-2099-101', $vendor, 24200.00);
        $second = $this->purchaseInvoice('FP-2099-102', $vendor, 6050.00);
        $this->postPredpis('purchase_invoice', $first, '518', '321', 24200.00);
        $this->postPredpis('purchase_invoice', $second, '518', '321', 6050.00);
        $txId = $this->transaction($this->statement(), -30250.00, ['match_status' => 'manual']);
        $this->paymentMatch($txId, $first, 24200.00);
        $this->paymentMatch($txId, $second, 6050.00);

        $entryId = $this->service->postMatched($this->supplierId, $txId, $this->userId);

        self::assertNotNull($entryId);
        $pairs = $this->assertEntryPaired($pdo, $this->supplierId, (int) $entryId, '30250.00');
        self::assertSame(
            [['321', '221', '24200.00'], ['321', '221', '6050.00']],
            array_map(static fn (array $p): array => [substr($p[0], 0, 3), substr($p[1], 0, 3), $p[2]], $pairs),
        );
        self::assertSame(
            ['Úhrada FP FP-2099-101', 'Úhrada FP FP-2099-101', 'Úhrada FP FP-2099-102', 'Úhrada FP FP-2099-102'],
            $this->lineTexts((int) $entryId),
            'Text dvojice nese číslo hrazené faktury na obou řádcích.',
        );
    }

    public function testCompositeBulkPaymentHasNoPairText(): void
    {
        $vendor = $this->client('Dodavatel složené firmy');
        $first = $this->purchaseInvoice('FP-2099-201', $vendor, 1000.00);
        $second = $this->purchaseInvoice('FP-2099-202', $vendor, 500.00);
        $this->postPredpis('purchase_invoice', $first, '518', '321', 1000.00);
        $this->postPredpis('purchase_invoice', $second, '518', '321', 500.00);
        $txId = $this->transaction($this->statement(), -1500.00, ['match_status' => 'manual']);
        $this->paymentMatch($txId, $first, 1000.00);
        $this->paymentMatch($txId, $second, 500.00);

        $entryId = (int) $this->service->postMatched($this->supplierId, $txId, $this->userId);

        self::assertSame([null, null, null], $this->lineTexts($entryId));
    }

    /** @return list<?string> */
    private function lineTexts(int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT line_text FROM journal_entry_lines WHERE supplier_id = ? AND entry_id = ? ORDER BY line_no, id');
        $stmt->execute([$this->supplierId, $entryId]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }
}
