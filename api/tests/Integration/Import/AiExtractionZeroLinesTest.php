<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Import;

use MyInvoice\Service\Import\AiPdfExtractor;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Předplatné SaaS rozepisuje v ceně zahrnuté kvóty jako řádky za $0.00. Do dokladu
 * nepatří: základ ani DPH nemění a každý z nich přidával odrážku do hlášení
 * o druhu nákladu. Sleva (záporný řádek) zůstává.
 */
#[Group('integration')]
final class AiExtractionZeroLinesTest extends StockTestCase
{
    public function testZeroAmountLinesAreNotImported(): void
    {
        $id = $this->createDraft([
            ['description' => 'Subscription to Team', 'quantity' => 1, 'unit_price_without_vat' => 29.00, 'vat_rate' => 21],
            ['description' => '50,000 reserved errors', 'quantity' => 1, 'unit_price_without_vat' => 0, 'vat_rate' => 21],
            ['description' => '5 GB reserved logs', 'quantity' => 1, 'unit_price_without_vat' => 0.0, 'line_total_without_vat' => 0, 'vat_rate' => 21],
            ['description' => '149 pay-as-you-go replays', 'quantity' => 149, 'unit_price_without_vat' => 0.0037584, 'line_total_without_vat' => 0.56, 'vat_rate' => 21],
            ['description' => 'Promotional Discount', 'quantity' => 1, 'unit_price_without_vat' => -14.78, 'vat_rate' => 21],
        ], 14.78);

        $rows = $this->items($id);
        self::assertSame(
            ['Subscription to Team', '149 pay-as-you-go replays', 'Promotional Discount'],
            array_column($rows, 'description'),
        );
        self::assertSame([0, 1, 2], array_map('intval', array_column($rows, 'order_index')));

        $warning = (string) $this->db->pdo()->query('SELECT extraction_warning FROM purchase_invoices WHERE id = ' . $id)->fetchColumn();
        self::assertStringNotContainsString('reserved errors', $warning);
        self::assertStringNotContainsString('reserved logs', $warning);
    }

    /** Kontrolní okno zvýrazní přesně ty řádky, které jmenuje hlášení. */
    public function testExpenseKindProposalsAreStoredForReview(): void
    {
        $id = $this->createDraft([
            ['description' => 'Konzultace k projektu', 'quantity' => 1, 'unit_price_without_vat' => 1000, 'vat_rate' => 21,
             'expense_kind' => 'service', 'expense_kind_confidence' => 0.9, 'expense_kind_reasoning' => 'Služba podle dokladu'],
            ['description' => 'Zero line', 'quantity' => 1, 'unit_price_without_vat' => 0, 'vat_rate' => 21],
            ['description' => 'Kancelářská židle', 'quantity' => 1, 'unit_price_without_vat' => 3000, 'vat_rate' => 21,
             'expense_kind' => 'small_asset', 'expense_kind_confidence' => 0.9, 'expense_kind_reasoning' => 'Vybavení kanceláře'],
        ], 4000.0);

        $row = $this->db->pdo()->query('SELECT extraction_warning, extraction_review FROM purchase_invoices WHERE id = ' . $id)
            ->fetch(\PDO::FETCH_ASSOC);
        $review = json_decode((string) $row['extraction_review'], true);
        self::assertIsArray($review, 'Návrhy druhu nákladu se musí uložit strukturovaně.');

        preg_match_all('/řádek (\d+)/u', (string) $row['extraction_warning'], $m);
        $fromWarning = array_map(static fn (string $n): int => (int) $n - 1, $m[1]);
        self::assertNotSame([], $fromWarning);
        self::assertSame($fromWarning, array_column($review['expense_kinds'], 'order_index'));
        foreach ($review['expense_kinds'] as $p) {
            self::assertContains($p['kind'], ['service', 'material', 'small_asset', 'small_intangible', 'fixed_asset']);
        }
    }

    /**
     * Trello MCUAD: „zaplaceno" na dokladu přepnulo import rovnou na Uhrazená a editor
     * pak doklad zamkl. Vytěžený doklad zůstává konceptem, úhradu nabídne kontrola.
     */
    public function testPaidPerDocumentStaysDraftWithHint(): void
    {
        $id = $this->createDraft([
            ['description' => 'Team (Monthly)', 'quantity' => 1, 'unit_price_without_vat' => 1000, 'vat_rate' => 21],
        ], 1000.0, ['already_paid' => true, 'payment' => ['method' => 'transfer', 'method_confidence' => 0.9]]);

        $row = $this->db->pdo()->query('SELECT status, paid_at, extraction_warning, extraction_review FROM purchase_invoices WHERE id = ' . $id)
            ->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('draft', $row['status']);
        self::assertNull($row['paid_at']);
        self::assertStringContainsString(AiPdfExtractor::PAID_PER_DOCUMENT_WARNING, (string) $row['extraction_warning']);
        self::assertTrue(json_decode((string) $row['extraction_review'], true)['paid_per_document'] ?? false);
    }

    public function testDocumentWithOnlyZeroLinesKeepsThem(): void
    {
        $id = $this->createDraft([
            ['description' => 'Free plan', 'quantity' => 1, 'unit_price_without_vat' => 0, 'vat_rate' => 0],
            ['description' => 'Free support', 'quantity' => 1, 'unit_price_without_vat' => 0, 'vat_rate' => 0],
        ], 0.0);

        self::assertCount(2, $this->items($id));
    }

    public function testZeroAmountLineDetection(): void
    {
        self::assertTrue(AiPdfExtractor::isZeroAmountLine(['quantity' => 1, 'unit_price_without_vat' => 0]));
        self::assertTrue(AiPdfExtractor::isZeroAmountLine(['quantity' => 0, 'unit_price_without_vat' => 120]));
        self::assertTrue(AiPdfExtractor::isZeroAmountLine(['quantity' => 1, 'unit_price_without_vat' => 0.001]));
        self::assertFalse(AiPdfExtractor::isZeroAmountLine(['quantity' => 1, 'unit_price_without_vat' => -5]));
        self::assertFalse(AiPdfExtractor::isZeroAmountLine(['quantity' => 1, 'unit_price_without_vat' => 0, 'line_total_without_vat' => 250]));
    }

    /**
     * Řádek mimo daňovou rekapitulaci (spropitné) chybí v položkách: rozdíl „K úhradě“
     * proti řádkům nad korunu se nesmí tiše zahodit, má se ohlásit (PR #237).
     */
    public function testTotalGapAboveRoundingIsReported(): void
    {
        $id = $this->createDraft(
            [['description' => 'Potraviny', 'quantity' => 1, 'unit_price_without_vat' => 1000.00, 'vat_rate' => 21]],
            1000.00,
            // Rekapitulace sedí na řádky (1 000 + 210), „K úhradě" je o 20 Kč víc (spropitné).
            ['total_with_vat_rounded' => 1230.00],
        );

        $warning = (string) $this->db->pdo()->query('SELECT extraction_warning FROM purchase_invoices WHERE id = ' . $id)->fetchColumn();
        self::assertStringContainsString('rozdíl 20,00 CZK', $warning);
    }

    /**
     * @param list<array<string,mixed>> $items
     * @param array<string,mixed>       $extra
     */
    private function createDraft(array $items, float $total, array $extra = []): int
    {
        $sid = $this->createSupplier();
        $vendor = $this->client($sid, 'Fixture SaaS vendor');
        $extractor = $this->container->get(AiPdfExtractor::class);
        return (new \ReflectionMethod($extractor, 'createDraft'))->invoke($extractor, [
            'document_kind' => 'invoice', 'vendor_invoice_number' => 'FIXTURE-ZERO-' . count($items),
            'currency' => 'CZK', 'issue_date' => '2026-08-31', 'tax_date' => '2026-08-31', 'due_date' => '2026-09-15',
            'items' => $items,
            'total_without_vat' => $total, 'total_with_vat' => round($total * 1.21, 2), 'unit_prices_include_vat' => false,
        ] + $extra, $sid, $this->userId, $vendor, true);
    }

    /** @return list<array<string,mixed>> */
    private function items(int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT description, order_index FROM purchase_invoice_items WHERE purchase_invoice_id = ? ORDER BY order_index');
        $stmt->execute([$invoiceId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
