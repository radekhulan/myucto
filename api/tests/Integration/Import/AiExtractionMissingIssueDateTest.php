<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Import;

use MyInvoice\Service\Import\AiPdfExtractor;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Účtenka, na které AI nenašla datum: koncept vznikne s doplněným datem a varováním. */
#[Group('integration')]
final class AiExtractionMissingIssueDateTest extends StockTestCase
{
    public function testReceiptWithoutDateCreatesDraftWithWarning(): void
    {
        $sid = $this->createSupplier();
        $vendor = $this->client($sid, 'Obchod s obuví bez data');
        $extractor = $this->container->get(AiPdfExtractor::class);
        $data = AiPdfExtractor::withIssueDateFallback([
            'document_kind' => 'receipt', 'vendor_invoice_number' => 'UCT-BEZ-DATA',
            'currency' => 'CZK', 'issue_date' => null, 'tax_date' => null,
            'items' => [['description' => 'Boty', 'quantity' => 1, 'unit_price_without_vat' => 1650.41, 'vat_rate' => 21]],
            'total_without_vat' => 1650.41,
            'total_with_vat' => 1997,
            'vat_recap' => [['rate' => 21, 'base' => 1650.41, 'vat' => 346.59]],
        ], '2026-10-27');

        $id = (new \ReflectionMethod($extractor, 'createDraft'))->invoke($extractor, $data, $sid, $this->userId, $vendor, true);

        $stmt = $this->db->pdo()->prepare('SELECT issue_date, extraction_warning FROM purchase_invoices WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('2026-10-27', $row['issue_date']);
        self::assertStringContainsString('nenašli datum vystavení', (string) $row['extraction_warning']);
        self::assertStringContainsString('den importu', (string) $row['extraction_warning']);
    }
}
