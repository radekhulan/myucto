<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Import;

use MyInvoice\Service\Import\AiPdfExtractor;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Doklad s jediným datem dostane DUZP z data vystavení; výjimky zůstávají prázdné. */
#[Group('integration')]
final class AiExtractionTaxDateFromIssueDateTest extends StockTestCase
{
    public function testForeignServiceReceiptWithSingleDateGetsTaxDate(): void
    {
        $row = $this->draft('IE', 'receipt', 'services');

        self::assertSame('24e', $row['vat_classification_code']);
        self::assertSame('2026-11-01', $row['tax_date']);
    }

    public function testDomesticInvoiceWithSingleDateGetsTaxDate(): void
    {
        self::assertSame('2026-11-01', $this->draft('CZ', 'invoice', 'services')['tax_date']);
    }

    public function testEuGoodsWithoutDeliveryDateStayEmptyWithWarning(): void
    {
        $row = $this->draft('DE', 'invoice', 'goods');

        self::assertSame('23', $row['vat_classification_code']);
        self::assertNull($row['tax_date']);
        self::assertStringContainsString('Datum dodání se z dokladu nepodařilo vytěžit', (string) $row['extraction_warning']);
    }

    public function testAdvanceStaysEmpty(): void
    {
        self::assertNull($this->draft('CZ', 'advance', 'services')['tax_date']);
    }

    public function testCreditNoteStaysEmpty(): void
    {
        self::assertNull($this->draft('CZ', 'credit_note', 'services')['tax_date']);
    }

    public function testExtractedTaxDateIsKept(): void
    {
        self::assertSame('2026-10-31', $this->draft('CZ', 'invoice', 'services', ['tax_date' => '2026-10-31'])['tax_date']);
    }

    public function testFallbackIssueDateIsNotCopied(): void
    {
        $data = AiPdfExtractor::withIssueDateFallback(['issue_date' => null, 'tax_date' => null], '2026-10-27');

        self::assertNull($this->draft('CZ', 'receipt', 'services', $data)['tax_date']);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array{tax_date:?string, vat_classification_code:?string, extraction_warning:?string}
     */
    private function draft(string $vendorCountry, string $kind, string $nature, array $overrides = []): array
    {
        $sid = $this->createSupplier();
        $vendor = $this->client($sid, 'Fixture dodavatel');
        $this->db->pdo()
            ->prepare('UPDATE clients SET country_id = (SELECT id FROM countries WHERE iso2 = ? LIMIT 1), is_vendor = 1 WHERE id = ? AND supplier_id = ?')
            ->execute([$vendorCountry, $vendor, $sid]);
        $extractor = $this->container->get(AiPdfExtractor::class);
        $id = (new \ReflectionMethod($extractor, 'createDraft'))->invoke($extractor, array_merge([
            'document_kind' => $kind, 'vendor_invoice_number' => 'FIXTURE-JEDNO-DATUM',
            'currency' => 'CZK', 'issue_date' => '2026-11-01', 'tax_date' => null, 'delivery_date' => null,
            'due_date' => '2026-11-01', 'supply_nature' => $nature,
            'items' => [['description' => 'Fixture položka', 'quantity' => 1, 'unit' => 'ks', 'unit_price_without_vat' => 126, 'vat_rate' => 0]],
            'total_without_vat' => 126, 'total_with_vat' => 126, 'unit_prices_include_vat' => false,
        ], $overrides), $sid, $this->userId, $vendor, true);
        $query = $this->db->pdo()->prepare('SELECT tax_date, vat_classification_code, extraction_warning FROM purchase_invoices WHERE id = ? AND supplier_id = ?');
        $query->execute([$id, $sid]);

        return $query->fetch(\PDO::FETCH_ASSOC);
    }
}
