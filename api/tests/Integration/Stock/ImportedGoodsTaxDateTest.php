<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Service\Import\AiPdfExtractor;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class ImportedGoodsTaxDateTest extends StockTestCase
{
    public function testExplicitDeliveryDateReplacesDifferentExtractedTaxDate(): void
    {
        $sid = $this->createSupplier();
        $vendor = $this->client($sid, 'Fixture EU vendor');
        $this->db->pdo()->prepare("UPDATE clients SET country_id = (SELECT id FROM countries WHERE iso2 = 'DE' LIMIT 1), is_vendor = 1 WHERE id = ? AND supplier_id = ?")->execute([$vendor, $sid]);
        $extractor = $this->container->get(AiPdfExtractor::class);
        $method = new \ReflectionMethod($extractor, 'createDraft');
        $id = $method->invoke($extractor, [
            'document_kind' => 'invoice', 'vendor_invoice_number' => 'FIXTURE-EU-DATE',
            'currency' => 'CZK', 'issue_date' => '2026-08-31', 'delivery_date' => '2026-08-31',
            'tax_date' => '2026-09-01', 'due_date' => '2026-09-15', 'supply_nature' => 'goods',
            'items' => [['description' => 'Fixture goods', 'quantity' => 1, 'unit' => 'ks', 'unit_price_without_vat' => 100, 'vat_rate' => 0]],
            'total_without_vat' => 100, 'total_with_vat' => 100, 'unit_prices_include_vat' => false,
        ], $sid, $this->userId, $vendor, true);
        $query = $this->db->pdo()->prepare('SELECT tax_date, delivery_date, vat_classification_code FROM purchase_invoices WHERE id = ? AND supplier_id = ?');
        $query->execute([$id, $sid]);
        $row = $query->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('23', $row['vat_classification_code']);
        self::assertSame('2026-08-31', $row['tax_date']);
        self::assertSame('2026-08-31', $row['delivery_date']);
    }
}
