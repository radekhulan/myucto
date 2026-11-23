<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Import;

use MyInvoice\Service\Document\DocumentStorage;
use MyInvoice\Service\Import\AiExtractedDataClipper;
use MyInvoice\Service\Import\AiPdfExtractor;
use MyInvoice\Service\Import\ClientResolver;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use MyInvoice\Tests\Support\FakeLlmGateway;
use PHPUnit\Framework\Attributes\Group;

/**
 * Text z AI delší než sloupec doklad nesmí shodit. Účtenka nesla jako VS číslo
 * účtenky o 24 znacích a INSERT do `purchase_invoices` padal na chybě 1406.
 * Celý test běží v transakci, kterou úklid StockTestCase vrátí.
 */
#[Group('integration')]
final class AiExtractionColumnWidthTest extends StockTestCase
{
    private const OWN_IC = '11223344';

    private int $sid = 0;

    protected function tearDown(): void
    {
        if ($this->sid > 0) {
            self::removeTree(DocumentStorage::baseDir($this->sid));
            self::removeTree(RuntimePaths::storage('purchase-invoices') . '/supplier-' . $this->sid);
        }
        parent::tearDown();
    }

    public function testOverlongTextsAreClippedInsteadOfDbError(): void
    {
        $this->db->pdo()->beginTransaction();
        $this->sid = $this->createSupplier();
        $this->db->pdo()->prepare('UPDATE supplier SET ic = ? WHERE id = ?')->execute([self::OWN_IC, $this->sid]);
        $vendorId = $this->client($this->sid, 'Syntetický obchod');

        $llm = new FakeLlmGateway(static fn (string $bytes): array => [
            'vendor' => [
                'company_name' => 'Syntetický obchod ' . str_repeat('s dlouhým názvem ', 20),
                'ic' => '11220044', 'dic' => 'CZ11220044', 'zip' => '158 00 Praha 5 - Smíchov',
                'is_vat_payer' => true,
            ],
            'customer' => ['company_name' => 'Syntetický odběratel', 'ic' => self::OWN_IC, 'dic' => null],
            'payment' => ['bank_account' => null, 'iban' => null, 'variable_symbol' => 'B10810126290190858241362'],
            'vendor_invoice_number' => 'UCT-' . bin2hex(random_bytes(3)) . str_repeat('-0123456789', 6),
            'document_kind' => 'receipt',
            'issue_date' => '2091-06-15',
            'tax_date' => '2091-06-15',
            'due_date' => '2091-06-15',
            'currency' => 'CZK',
            'unit_prices_include_vat' => false,
            'total_without_vat' => 100.0,
            'total_with_vat' => 121.0,
            'vat_recap' => [['rate' => 21, 'base' => 100.0, 'vat' => 21.0]],
            'items' => [[
                'description' => 'Syntetické zboží', 'quantity' => 1, 'unit' => 'balení po dvanácti kusech',
                'seller_item_code' => str_repeat('KOD', 40), 'unit_price_without_vat' => 100.0, 'vat_rate' => 21,
            ]],
        ]);
        $seenVendor = [];
        $resolver = $this->createStub(ClientResolver::class);
        $resolver->method('resolveVendor')->willReturnCallback(
            static function (array $vendor) use (&$seenVendor, $vendorId): array {
                $seenVendor = $vendor;
                return ['id' => $vendorId, 'created' => false, 'role_added' => false, 'is_vat_payer' => true];
            },
        );
        $extractor = $this->container->make(AiPdfExtractor::class, ['anthropic' => $llm, 'clientResolver' => $resolver]);

        $res = $extractor->extractAndCreate($this->sid, $this->userId, self::pdf(), null, 'uctenka.pdf');
        self::assertTrue($res['ok'], (string) ($res['error'] ?? ''));

        self::assertLessThanOrEqual(190, mb_strlen((string) $seenVendor['company_name']));
        self::assertStringStartsWith('Syntetický obchod s dlouhým názvem', (string) $seenVendor['company_name']);
        self::assertLessThanOrEqual(10, mb_strlen((string) $seenVendor['zip']));

        $stmt = $this->db->pdo()->prepare(
            'SELECT pi.payment_variable_symbol, pi.vendor_invoice_number, pii.unit, pii.seller_item_code
               FROM purchase_invoices pi JOIN purchase_invoice_items pii ON pii.purchase_invoice_id = pi.id
              WHERE pi.id = ?'
        );
        $stmt->execute([(int) $res['purchase_invoice_id']]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertNull($row['payment_variable_symbol'], 'číslo účtenky není variabilní symbol');
        self::assertSame(50, mb_strlen((string) $row['vendor_invoice_number']));
        self::assertSame('balení po dvanácti k', $row['unit']);
        self::assertSame(80, mb_strlen((string) $row['seller_item_code']));
    }

    public function testVariableSymbolKeepsDigitsInsteadOfCuttingThem(): void
    {
        $data = AiExtractedDataClipper::clip(
            ['payment' => ['variable_symbol' => 'VS: 2026 0001 23 (uveďte prosím)']],
            ['payment.variable_symbol' => 20],
        );
        self::assertSame('2026000123', $data['payment']['variable_symbol']);

        $data = AiExtractedDataClipper::clip(['payment' => ['variable_symbol' => '2026-000123']], ['payment.variable_symbol' => 20]);
        self::assertSame('2026-000123', $data['payment']['variable_symbol']);
    }

    private static function pdf(): string
    {
        return "%PDF-1.4\n% SIRKA-" . bin2hex(random_bytes(6)) . "\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
