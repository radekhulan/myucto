<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Action\PurchaseInvoice\CreatePurchaseInvoiceAction;
use MyInvoice\Action\PurchaseInvoice\UpdatePurchaseInvoiceAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Import\AiPdfExtractor;
use MyInvoice\Service\Import\ClientResolver;
use MyInvoice\Service\Import\IsdocParser;
use MyInvoice\Service\Import\IsdocToPurchaseInvoiceMapper;
use MyInvoice\Service\PurchaseInvoice\VendorNumberDuplicates;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use MyInvoice\Tests\Support\FakeLlmGateway;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Pravděpodobná duplicita přijatého dokladu s JINÝM datem vystavení: tentýž doklad
 * dodavatele převzatý z jiného systému (23. 9.) a znovu vytěžený z PDF (21. 9.).
 * Unikátní index (číslo + datum) ji nechytí, proto se jen varuje — automatické cesty
 * zakládají koncept s hlášením, ruční uložení vrací varování s odkazem na doklad.
 */
#[Group('integration')]
final class PurchaseInvoiceLikelyDuplicateTest extends StockTestCase
{
    private int $supplierId = 0;
    private int $vendorId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->supplierId = $this->createSupplier();
        $this->vendorId = $this->client($this->supplierId, 'Dodavatel duplicit');
    }

    public function testLikelyMatchesSameNumberWithOtherIssueDateOnly(): void
    {
        $existing = $this->existing('FV-2098-0042', '2098-09-23');
        $pdo = $this->db->pdo();
        $likely = fn (string $number, string $date, ?int $vendorId = null): array => VendorNumberDuplicates::likely(
            $pdo, $this->supplierId, $vendorId ?? $this->vendorId, $number, $date,
        );

        $matches = $likely('FV 2098/0042', '2098-09-21');
        self::assertSame([$existing], array_column($matches, 'id'), 'stejné číslo (jiný zápis) a jiné datum = možná duplicita');
        self::assertSame('2098-09-23', $matches[0]['issue_date']);
        self::assertSame(1210.0, $matches[0]['total_with_vat']);

        self::assertSame([], $likely('ZF-2098-0042', '2098-09-21'), 'jiná písmena (zálohová vs. faktura) nejsou duplicita');
        self::assertSame([], $likely('FV-2098-0042', '2098-09-23'), 'stejné datum hlídá unikátní index, ne varování');
        self::assertSame([], $likely('FV-2098-0042', '2099-09-24'), 'mimo okno ±1 rok');
        self::assertSame([], $likely('FV-2098-0042', '2098-09-21', $this->client($this->supplierId, 'Jiný dodavatel')));

        $pdo->prepare("UPDATE purchase_invoices SET status = 'cancelled' WHERE id = ?")->execute([$existing]);
        self::assertSame([], $likely('FV-2098-0042', '2098-09-21'), 'stornovaný doklad se nehlásí');
    }

    public function testManualCreateAndUpdateReturnWarningWithExistingDocument(): void
    {
        $existing = $this->existing('FV-2098-0042', '2098-09-23');
        $body = [
            'vendor_id'             => $this->vendorId,
            'vendor_invoice_number' => 'FV-2098-0042',
            'document_kind'         => 'invoice',
            'issue_date'            => '2098-09-21',
            'tax_date'              => '2098-09-21',
            'due_date'              => '2098-10-05',
            'currency_id'           => $this->currencyIdFor($this->supplierId),
            'items'                 => [],
        ];

        $created = $this->json($this->create($body), 201);
        self::assertContains('vendor_invoice_likely_duplicate', $created['_warnings'] ?? []);
        self::assertSame([$existing], array_column($created['_warning_meta']['vendor_invoice_likely_duplicate']['matches'] ?? [], 'id'));

        $other = $this->json($this->create(['vendor_invoice_number' => 'FV-2098-0099'] + $body), 201);
        self::assertNotContains('vendor_invoice_likely_duplicate', $other['_warnings'] ?? []);

        $renumbered = $this->json($this->update((int) $other['id'], ['vendor_invoice_number' => 'FV-2098-0042', 'issue_date' => '2098-09-25'] + $body), 200);
        self::assertContains('vendor_invoice_likely_duplicate', $renumbered['_warnings'] ?? [], 'změna čísla na existující doklad varuje');

        $resaved = $this->json($this->update((int) $other['id'], ['vendor_invoice_number' => 'FV-2098-0042', 'issue_date' => '2098-09-25'] + $body), 200);
        self::assertNotContains('vendor_invoice_likely_duplicate', $resaved['_warnings'] ?? [], 'opakované uložení beze změny čísla a data už nevaruje');
    }

    public function testStructuredImportStaysDraftWithWarning(): void
    {
        $parsed = (new IsdocParser())->parse((string) file_get_contents(__DIR__ . '/../../Fixtures/Shoptet/isdoc-faktura.isdoc'))['invoices'][0];
        $parsed['varsymbol'] = 'DUP-' . bin2hex(random_bytes(3));
        $existingDate = (new \DateTimeImmutable((string) $parsed['issue_date']))->modify('+2 days')->format('Y-m-d');
        $existing = $this->existing((string) $parsed['varsymbol'], $existingDate);

        $resolver = $this->createStub(ClientResolver::class);
        $resolver->method('resolveVendor')->willReturn(['id' => $this->vendorId, 'created' => false, 'role_added' => false, 'is_vat_payer' => true]);
        $mapped = $this->container->make(IsdocToPurchaseInvoiceMapper::class, ['clientResolver' => $resolver])
            ->map($parsed, $this->supplierId, $this->userId, 'received');

        self::assertTrue($mapped['likely_duplicate'] ?? false);
        $row = $this->row((int) $mapped['purchase_invoice_id']);
        self::assertSame('draft', $row['status'], 'možná duplicita nevznikne jako přijatý doklad');
        self::assertStringContainsString('Možná duplicita: doklad č. ' . $parsed['varsymbol'] . ' od tohoto dodavatele už existuje', (string) $row['extraction_warning']);
        self::assertStringContainsString('#' . $existing, (string) $row['extraction_warning']);
    }

    public function testAiExtractionAddsWarningToDraft(): void
    {
        $this->db->pdo()->prepare("UPDATE supplier SET ic = '11223344' WHERE id = ?")->execute([$this->supplierId]);
        $number = 'FV-' . bin2hex(random_bytes(3));
        $this->existing($number, '2098-09-23');
        $item = ['quantity' => 1, 'unit' => 'ks', 'unit_price_without_vat' => 1000.0, 'line_total_without_vat' => 1000.0, 'vat_rate' => 21];
        $llm = new FakeLlmGateway(static fn (string $bytes): array => [
            'vendor' => ['company_name' => 'Dodavatel duplicit', 'ic' => '11220044', 'dic' => null, 'is_vat_payer' => true],
            'customer' => ['company_name' => 'Odběratel', 'ic' => '11223344', 'dic' => null],
            'vendor_invoice_number' => $number,
            'document_kind' => 'invoice',
            'issue_date' => '2098-09-21',
            'tax_date' => '2098-09-21',
            'due_date' => '2098-10-05',
            'currency' => 'CZK',
            'total_without_vat' => 1000.0,
            'total_with_vat' => 1210.0,
            'items' => [$item + ['description' => 'Služba']],
        ]);
        $resolver = $this->createStub(ClientResolver::class);
        $resolver->method('resolveVendor')->willReturn(['id' => $this->vendorId, 'created' => false, 'role_added' => false, 'is_vat_payer' => true]);

        $pdf = "%PDF-1.4\n% DUP-" . bin2hex(random_bytes(6)) . "\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
        $res = $this->container->make(AiPdfExtractor::class, ['anthropic' => $llm, 'clientResolver' => $resolver])
            ->extractAndCreate($this->supplierId, $this->userId, $pdf, null, 'faktura.pdf');
        self::assertTrue($res['ok'], (string) ($res['error'] ?? ''));

        $row = $this->row((int) $res['purchase_invoice_id']);
        self::assertSame('draft', $row['status']);
        self::assertStringContainsString(
            'Možná duplicita: doklad č. ' . $number . ' od tohoto dodavatele už existuje (datum vystavení 23. 9. 2098, částka 1 210,00 CZK',
            (string) $row['extraction_warning'],
        );
    }

    private function existing(string $number, string $issueDate): int
    {
        $id = $this->purchaseInvoice($this->supplierId, $this->vendorId, [
            'vendor_invoice_number' => $number,
            'issue_date'            => $issueDate,
        ]);
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET total_with_vat = 1210 WHERE id = ?')->execute([$id]);

        return $id;
    }

    /** @return array<string,mixed> */
    private function row(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT status, extraction_warning FROM purchase_invoices WHERE id = ?');
        $stmt->execute([$id]);

        return (array) $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    /** @param array<string,mixed> $body */
    private function create(array $body): ResponseInterface
    {
        return ($this->container->get(CreatePurchaseInvoiceAction::class))(
            $this->request('POST', '/api/purchase-invoices')->withParsedBody($body),
            new Psr7Response(),
        );
    }

    /** @param array<string,mixed> $body */
    private function update(int $id, array $body): ResponseInterface
    {
        return ($this->container->get(UpdatePurchaseInvoiceAction::class))(
            $this->request('PUT', '/api/purchase-invoices/' . $id)->withParsedBody($body),
            new Psr7Response(),
            ['id' => (string) $id],
        );
    }

    private function request(string $method, string $uri): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, $uri)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response, int $status): array
    {
        $response->getBody()->rewind();
        $raw = (string) $response->getBody();
        self::assertSame($status, $response->getStatusCode(), $raw);

        return (array) json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }
}
