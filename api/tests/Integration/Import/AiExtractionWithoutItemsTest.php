<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Import;

use MyInvoice\Service\Document\DocumentStorage;
use MyInvoice\Service\Import\AiPdfExtractor;
use MyInvoice\Service\Import\ClientResolver;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceSubmissionProcessingService;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceSubmissionUploadService;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use MyInvoice\Tests\Support\FakeLlmGateway;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Jednorázová volba „Načíst bez položek" projde od vytěžení až do uloženého konceptu:
 * jeden řádek na sazbu DPH, odpočet zdaněné zálohy zvlášť, součty dokladu beze změny.
 * Celý test běží v transakci, kterou úklid StockTestCase vrátí.
 */
#[Group('integration')]
final class AiExtractionWithoutItemsTest extends StockTestCase
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

    #[DataProvider('modes')]
    public function testDirectUploadCreatesOneLinePerRate(bool $withoutItems, int $expectedLines): void
    {
        [$extractor] = $this->prepare();

        $res = $extractor->extractAndCreate($this->sid, $this->userId, self::pdf(), null, 'faktura.pdf', withoutItems: $withoutItems);
        self::assertTrue($res['ok'], (string) ($res['error'] ?? ''));

        $this->assertDraft((int) $res['purchase_invoice_id'], $expectedLines);
    }

    /** @return iterable<string, array{bool, int}> */
    public static function modes(): iterable
    {
        yield 's položkami' => [false, 7];
        yield 'bez položek' => [true, 3];
    }

    public function testSubmissionExtractionPassesWithoutItems(): void
    {
        [$extractor] = $this->prepare();
        $created = $this->container->get(PurchaseInvoiceSubmissionUploadService::class)
            ->submitBytes(self::pdf(), 'predany-doklad.pdf', $this->sid, $this->userId, 'staff');
        $processing = $this->container->make(PurchaseInvoiceSubmissionProcessingService::class, ['ai' => $extractor]);

        $result = $processing->extract((int) $created['submission']['id'], $this->sid, $this->userId, true, true);

        $this->assertDraft($result['purchase_invoice_id'], 3);
    }

    /** @return array{AiPdfExtractor} */
    private function prepare(): array
    {
        $this->db->pdo()->beginTransaction();
        $this->sid = $this->createSupplier();
        $this->db->pdo()->prepare('UPDATE supplier SET ic = ? WHERE id = ?')->execute([self::OWN_IC, $this->sid]);
        $vendorId = $this->client($this->sid, 'Syntetický dodavatel bez položek');

        $item = static fn (string $d, float $q, float $p, int $rate): array =>
            ['description' => $d, 'quantity' => $q, 'unit' => 'ks', 'unit_price_without_vat' => $p, 'vat_rate' => $rate];
        $llm = new FakeLlmGateway(static fn (string $bytes): array => [
            'vendor' => ['company_name' => 'Syntetický dodavatel bez položek', 'ic' => '11220044', 'dic' => null, 'is_vat_payer' => true],
            'customer' => ['company_name' => 'Syntetický odběratel', 'ic' => self::OWN_IC, 'dic' => null],
            'vendor_invoice_number' => 'BEZPOL-' . bin2hex(random_bytes(3)),
            'document_kind' => 'invoice',
            'issue_date' => '2091-06-15',
            'tax_date' => '2091-06-15',
            'due_date' => '2091-06-29',
            'currency' => 'CZK',
            'unit_prices_include_vat' => false,
            'total_without_vat' => 1601.76,
            'total_with_vat' => 1919.97,
            'vat_recap' => [
                ['rate' => 21, 'base' => 1400.0, 'vat' => 294.0],
                ['rate' => 12, 'base' => 201.76, 'vat' => 24.21],
            ],
            'advance_reference' => 'ZF-SYN-1',
            'items' => [
                $item('Syntetická služba A', 2, 1000.0, 21),
                $item('Syntetická služba B', 1, 500.0, 21),
                $item('Sleva 10 %', 1, -100.0, 21),
                $item('Syntetická kniha', 1, 200.0, 12),
                $item('Záložka do knihy', 7, 0.125, 12),
                $item('Záložka dárková', 7, 0.125, 12),
                $item('Odpočet zálohy č. ZF-SYN-1', 1, -1000.0, 21),
            ],
        ]);
        $resolver = $this->createStub(ClientResolver::class);
        $resolver->method('resolveVendor')->willReturn(['id' => $vendorId, 'created' => false, 'role_added' => false, 'is_vat_payer' => true]);

        return [$this->container->make(AiPdfExtractor::class, ['anthropic' => $llm, 'clientResolver' => $resolver])];
    }

    private function assertDraft(int $invoiceId, int $expectedLines): void
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT pii.description, pii.quantity, pii.unit_price_without_vat, vr.rate_percent
               FROM purchase_invoice_items pii JOIN vat_rates vr ON vr.id = pii.vat_rate_id
              WHERE pii.purchase_invoice_id = ? ORDER BY pii.order_index'
        );
        $stmt->execute([$invoiceId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount($expectedLines, $rows);

        if ($expectedLines === 3) {
            self::assertSame([2400.0, 201.76, -1000.0], array_map('floatval', array_column($rows, 'unit_price_without_vat')));
            self::assertSame([21.0, 12.0, 21.0], array_map('floatval', array_column($rows, 'rate_percent')));
            self::assertSame('Odpočet zálohy č. ZF-SYN-1', $rows[2]['description']);
        }

        $stmt = $pdo->prepare('SELECT total_without_vat, total_vat, total_with_vat FROM purchase_invoices WHERE id = ?');
        $stmt->execute([$invoiceId]);
        self::assertSame([1601.76, 318.21, 1919.97], array_map('floatval', array_values($stmt->fetch(\PDO::FETCH_ASSOC))));
    }

    private static function pdf(): string
    {
        return "%PDF-1.4\n% BEZPOL-" . bin2hex(random_bytes(6)) . "\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
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
