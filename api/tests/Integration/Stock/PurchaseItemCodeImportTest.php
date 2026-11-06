<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Import\AiPdfExtractor;
use MyInvoice\Service\Import\ClientResolver;
use MyInvoice\Service\Import\IsdocParser;
use MyInvoice\Service\Import\IsdocToPurchaseInvoiceMapper;
use MyInvoice\Tests\Support\FakeLlmGateway;
use PHPUnit\Framework\Attributes\Group;

/** Kód zboží z řádku se uloží na řádek přijaté faktury a přežije přepis řádků z editoru. */
#[Group('integration')]
final class PurchaseItemCodeImportTest extends StockTestCase
{
    public function testReplaceItemsStoresAndNormalizesCodes(): void
    {
        $supplierId = $this->createSupplier();
        $piId = $this->purchaseInvoice($supplierId, $this->client($supplierId, 'Dodavatel kódů'));
        $base = ['quantity' => 1, 'unit' => 'ks', 'unit_price_without_vat' => 100, 'vat_rate_id' => $this->vatRateId];

        $repo = $this->container->get(PurchaseInvoiceRepository::class);
        $repo->replaceItems($piId, [
            $base + ['description' => 'Díl', 'seller_item_code' => ' 000079673 ', 'item_ean' => '8590000000011'],
            $base + ['description' => 'Dlouhý', 'seller_item_code' => str_repeat('X', 81), 'item_ean' => '859000000001'],
            $base + ['description' => 'Doprava', 'seller_item_code' => '  ', 'item_ean' => null],
            $base + ['description' => 'Hraniční', 'seller_item_code' => str_repeat('Y', 80), 'item_ean' => '12345678'],
        ]);
        $badEans = ['1234567', '123456789', '85900000000112345', '8590 000000011', 'EAN8590000000011', '859000000001A'];
        $repo->replaceItems($badPi = $this->purchaseInvoice($supplierId, $this->client($supplierId, 'Dodavatel EAN')), array_map(
            static fn (string $ean): array => $base + ['description' => $ean, 'item_ean' => $ean],
            $badEans,
        ));
        self::assertSame(array_fill(0, count($badEans), null), array_column($repo->itemsFor($badPi), 'item_ean'));

        $rows = $repo->itemsFor($piId);
        self::assertSame('000079673', $rows[0]['seller_item_code']);
        self::assertSame('8590000000011', $rows[0]['item_ean']);
        self::assertNull($rows[1]['seller_item_code'], 'Delší kód se nezkracuje, zkrácený by mohl patřit jiné kartě.');
        self::assertSame('859000000001', $rows[1]['item_ean']);
        self::assertSame(str_repeat('Y', 80), $rows[3]['seller_item_code']);
        self::assertSame('12345678', $rows[3]['item_ean']);
        self::assertNull($rows[2]['seller_item_code']);
        self::assertNull($rows[2]['item_ean']);
    }

    public function testAiExtractionStoresCodesOnLines(): void
    {
        $supplierId = $this->createSupplier();
        $this->db->pdo()->prepare("UPDATE supplier SET ic = '11223344' WHERE id = ?")->execute([$supplierId]);
        $vendorId = $this->client($supplierId, 'Dodavatel AI');
        $item = ['quantity' => 2, 'unit' => 'ks', 'unit_price_without_vat' => 50.0, 'line_total_without_vat' => 100.0, 'vat_rate' => 21];
        $llm = new FakeLlmGateway(static fn (string $bytes): array => [
            'vendor' => ['company_name' => 'Dodavatel AI', 'ic' => '11220044', 'dic' => null, 'is_vat_payer' => true],
            'customer' => ['company_name' => 'Odběratel', 'ic' => '11223344', 'dic' => null],
            'vendor_invoice_number' => 'KOD-' . bin2hex(random_bytes(3)),
            'document_kind' => 'invoice',
            'issue_date' => '2091-06-15',
            'tax_date' => '2091-06-15',
            'due_date' => '2091-06-29',
            'currency' => 'CZK',
            'total_without_vat' => 200.0,
            'total_with_vat' => 242.0,
            'items' => [
                $item + ['description' => 'Díl', 'seller_item_code' => '000079673', 'item_ean' => '8590000000011'],
                $item + ['description' => 'Doprava', 'seller_item_code' => null, 'item_ean' => null],
            ],
        ]);
        $resolver = $this->createStub(ClientResolver::class);
        $resolver->method('resolveVendor')->willReturn(['id' => $vendorId, 'created' => false, 'role_added' => false, 'is_vat_payer' => true]);

        $pdf = "%PDF-1.4\n% KOD-" . bin2hex(random_bytes(6)) . "\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
        $res = $this->container->make(AiPdfExtractor::class, ['anthropic' => $llm, 'clientResolver' => $resolver])
            ->extractAndCreate($supplierId, $this->userId, $pdf, null, 'faktura.pdf');
        self::assertTrue($res['ok'], (string) ($res['error'] ?? ''));

        $rows = $this->container->get(PurchaseInvoiceRepository::class)->itemsFor((int) $res['purchase_invoice_id']);
        self::assertSame(['000079673', '8590000000011'], [$rows[0]['seller_item_code'], $rows[0]['item_ean']]);
        self::assertNull($rows[1]['seller_item_code']);
    }

    public function testIsdocImportStoresCodesOnLines(): void
    {
        $supplierId = $this->createSupplier();
        $vendorId = $this->client($supplierId, 'Dodavatel ISDOC');
        $xml = str_replace(
            '<Item><Description>Tričko – M</Description></Item>',
            '<Item><Description>Tričko – M</Description><CatalogueItemIdentification><ID>8590000000011</ID></CatalogueItemIdentification>'
            . '<SellersItemIdentification><ID>TRI-M</ID></SellersItemIdentification></Item>',
            (string) file_get_contents(__DIR__ . '/../../Fixtures/Shoptet/isdoc-faktura.isdoc'),
        );
        $parsed = (new IsdocParser())->parse($xml)['invoices'][0];
        $parsed['varsymbol'] = 'KOD-' . bin2hex(random_bytes(3));

        $resolver = $this->createStub(ClientResolver::class);
        $resolver->method('resolveVendor')->willReturn(['id' => $vendorId, 'created' => false, 'role_added' => false, 'is_vat_payer' => true]);
        $mapped = $this->container->make(IsdocToPurchaseInvoiceMapper::class, ['clientResolver' => $resolver])->map($parsed, $supplierId, $this->userId);

        $rows = $this->container->get(PurchaseInvoiceRepository::class)->itemsFor((int) $mapped['purchase_invoice_id']);
        self::assertSame(['TRI-M', '8590000000011'], [$rows[0]['seller_item_code'], $rows[0]['item_ean']]);
        self::assertNull($rows[1]['seller_item_code']);
    }
}
