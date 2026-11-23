<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Export;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\Export\IsdocExporter;
use MyInvoice\Service\Export\PohodaXmlExporter;
use MyInvoice\Service\Export\PurchaseInvoiceExportService;
use PHPUnit\Framework\TestCase;

/**
 * Issue #391: přijatá faktura musí do Pohody poslat svou formu úhrady
 * (`purchase_invoices.payment_method`), ne výchozí převod.
 */
final class PurchaseInvoicePohodaPaymentTypeTest extends TestCase
{
    public function testReceivedInvoiceShapeCarriesPaymentMethodIntoPohoda(): void
    {
        self::assertSame('cash', $this->exportedPaymentType('cash'));
        self::assertSame('encashment', $this->exportedPaymentType('direct_debit'));
        self::assertSame('draft', $this->exportedPaymentType('bank_transfer'));
    }

    private function exportedPaymentType(string $method): ?string
    {
        $repo = $this->createStub(PurchaseInvoiceRepository::class);
        $repo->method('find')->willReturn([
            'id'              => 42,
            'vendor_id'       => 8,
            'document_kind'   => 'invoice',
            'varsymbol'       => 'PF2607001',
            'issue_date'      => '2026-07-01',
            'tax_date'        => '2026-07-01',
            'due_date'        => '2026-07-15',
            'currency'        => 'CZK',
            'payment_method'  => $method,
            'vendor_snapshot' => [
                'ic' => '11122233', 'dic' => 'CZ11122233', 'company_name' => 'Dodavatel s.r.o.',
                'street' => 'Dlouhá 5', 'city' => 'Brno', 'zip' => '60200', 'country_iso2' => 'CZ',
            ],
            'items' => [[
                'description' => 'Vývoj systému', 'quantity' => 1.0, 'unit' => 'ks',
                'unit_price_without_vat' => 1000.0, 'vat_rate_snapshot' => 21.0,
                'total_without_vat' => 1000.0, 'total_vat' => 210.0, 'total_with_vat' => 1210.0,
            ]],
            'vat_breakdown' => [['vat_rate' => 21.0, 'without_vat' => 1000.0, 'vat' => 210.0, 'with_vat' => 1210.0]],
            'totals' => ['without_vat' => 1000.0, 'vat' => 210.0, 'with_vat' => 1210.0, 'rounding' => 0.0],
        ]);

        $statement = $this->createStub(\PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetch')->willReturn(['ic' => '01698401', 'company_name' => 'Naše firma s.r.o.']);
        $pdo = $this->createStub(\PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);

        $tax = $this->createStub(TaxConstantsRepository::class);
        $tax->method('vatBucketThreshold')->willReturn(20.5);

        $service = new PurchaseInvoiceExportService(
            $db,
            $repo,
            new IsdocExporter($this->createStub(InvoiceRepository::class), $db),
            new PohodaXmlExporter($this->createStub(InvoiceRepository::class), $db, $tax),
        );

        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($service->toPohodaXml(42, 7)));
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('inv', PohodaXmlExporter::NS_INV);
        $xpath->registerNamespace('typ', PohodaXmlExporter::NS_TYP);

        return $xpath->query('//inv:invoiceHeader/inv:paymentType/typ:paymentType')->item(0)?->textContent;
    }
}
