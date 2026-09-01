<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Qr;

use MyInvoice\Action\PurchaseInvoice\PaymentQrAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\PaymentScheduleRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Repository\SupplierPaymentQrSettingsRepository;
use MyInvoice\Repository\WorkReportRepository;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Export\IsdocExporter;
use MyInvoice\Service\Import\IsdocParser;
use MyInvoice\Service\Import\LlmGatewayInterface;
use MyInvoice\Service\Import\PdfIsdocExtractor;
use MyInvoice\Service\Invoice\InvoicePublicLinkService;
use MyInvoice\Service\Invoice\SnapshotBuilder;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Mail\InvoiceEmailVarsBuilder;
use MyInvoice\Service\Payment\BankAccountParser;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Pdf\PdfArchiveService;
use MyInvoice\Service\Pdf\PdfImageExtractor;
use MyInvoice\Service\Qr\QrPaymentGenerator;
use MyInvoice\Service\Signing\Pdf\PdfSigningService;
use MyInvoice\Service\Vat\VatStatusService;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class PaymentQrProductionWiringTest extends TestCase
{
    /** @return iterable<string,array{?array<string,bool>,bool}> */
    public static function issuedSettings(): iterable
    {
        yield 'independent issued flag' => [[
            SupplierPaymentQrSettingsRepository::INVOICE_FIELD => true,
            SupplierPaymentQrSettingsRepository::PURCHASE_INVOICE_FIELD => false,
        ], true];
        yield 'missing settings fail closed' => [null, false];
    }

    /** @return iterable<string,array{?array<string,bool>,bool}> */
    public static function purchaseSettings(): iterable
    {
        yield 'independent purchase flag' => [[
            SupplierPaymentQrSettingsRepository::INVOICE_FIELD => false,
            SupplierPaymentQrSettingsRepository::PURCHASE_INVOICE_FIELD => true,
        ], true];
        yield 'missing settings fail closed' => [null, false];
    }

    /** @param ?array<string,bool> $stored */
    #[DataProvider('issuedSettings')]
    public function testInvoicePdfRendererPassesIssuedFlagAndActualDueDateToGenerator(
        ?array $stored,
        bool $expectedIncludeDueDate,
    ): void {
        $qr = $this->qrExpecting('2026-09-18', $expectedIncludeDueDate);
        $renderer = new InvoicePdfRenderer(
            $this->withoutConstructor(InvoiceRepository::class),
            $this->sqliteConnection(),
            new Config([]),
            $qr,
            $this->withoutConstructor(WorkReportRepository::class),
            $this->withoutConstructor(SnapshotBuilder::class),
            $this->withoutConstructor(PdfArchiveService::class),
            $this->withoutConstructor(IsdocExporter::class),
            $this->withoutConstructor(PdfSigningService::class),
            $this->withoutConstructor(PaymentScheduleRepository::class),
            $this->withoutConstructor(VatStatusService::class),
            $this->settings($stored),
        );

        $html = $renderer->renderHtml($this->issuedInvoice(), includeCss: false, includeWorkReport: false);

        self::assertStringContainsString('data:image/png;base64,dGVzdA==', $html);
    }

    /** @param ?array<string,bool> $stored */
    #[DataProvider('issuedSettings')]
    public function testInvoiceEmailVarsBuilderPassesIssuedFlagAndActualDueDateToGenerator(
        ?array $stored,
        bool $expectedIncludeDueDate,
    ): void {
        $builder = new InvoiceEmailVarsBuilder(
            $this->sqliteConnection(),
            $this->qrExpecting('2026-09-18', $expectedIncludeDueDate),
            $this->withoutConstructor(InvoicePublicLinkService::class),
            $this->settings($stored),
        );

        $uri = $builder->paymentQrDataUri($this->issuedInvoice());

        self::assertSame('data:image/png;base64,dGVzdA==', $uri);
    }

    /** @param ?array<string,bool> $stored */
    #[DataProvider('purchaseSettings')]
    public function testPurchasePaymentQrActionPassesPurchaseFlagAndActualDueDateToGenerator(
        ?array $stored,
        bool $expectedIncludeDueDate,
    ): void {
        $invoice = [
            'id' => 73,
            'supplier_id' => 41,
            'currency' => 'CZK',
            'total_with_vat' => 1210.0,
            'paid_total' => 0.0,
            'rounding' => 0.0,
            'vendor_invoice_number' => 'SYN-2026-73',
            'vendor_company_name' => 'Syntetický dodavatel',
            'due_date' => '2026-09-23',
            'payment_account_number' => '1000000005',
            'payment_bank_code' => '0100',
            'payment_iban' => null,
            'payment_bic' => null,
            'payment_variable_symbol' => '730026',
            'payment_account_source' => 'manual',
        ];
        $repo = $this->createMock(PurchaseInvoiceRepository::class);
        $repo->expects(self::once())->method('find')->with(73, 41)->willReturn($invoice);
        $action = new PaymentQrAction(
            $repo,
            $this->qrExpecting('2026-09-23', $expectedIncludeDueDate),
            new BankAccountParser(),
            new Config([]),
            $this->withoutConstructor(PdfIsdocExtractor::class),
            $this->withoutConstructor(IsdocParser::class),
            $this->createStub(LlmGatewayInterface::class),
            $this->withoutConstructor(PdfImageExtractor::class),
            $this->withoutConstructor(DocumentLockService::class),
            $this->withoutConstructor(ActivityLogger::class),
            $this->withoutConstructor(IpMatcher::class),
            $this->settings($stored),
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/purchase-invoices/73/payment-qr')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 41);

        $response = $action($request, new Response(), ['id' => '73']);
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('data:image/png;base64,dGVzdA==', $body['qr_data_uri'] ?? null);
    }

    /** @return QrPaymentGenerator&MockObject */
    private function qrExpecting(string $dueDate, bool $includeDueDate): QrPaymentGenerator
    {
        $qr = $this->createMock(QrPaymentGenerator::class);
        $qr->expects(self::once())->method('generate')->willReturnCallback(
            static function (
                string $currency,
                float $amount,
                string $variableSymbol,
                array $bank,
                string $supplierName = '',
                ?\DateTimeImmutable $actualDueDate = null,
                ?string $message = null,
                bool $actualIncludeDueDate = false,
            ) use ($dueDate, $includeDueDate): string {
                self::assertSame('CZK', $currency);
                self::assertGreaterThan(0, $amount);
                self::assertNotSame('', $variableSymbol);
                self::assertSame('1000000005', $bank['account_number'] ?? null);
                self::assertNotSame('', $supplierName);
                self::assertSame($dueDate, $actualDueDate?->format('Y-m-d'));
                self::assertSame($includeDueDate, $actualIncludeDueDate);
                return 'data:image/png;base64,dGVzdA==';
            },
        );
        return $qr;
    }

    /** @param ?array<string,bool> $stored */
    private function settings(?array $stored): SupplierPaymentQrSettingsRepository
    {
        $settings = $this->createMock(SupplierPaymentQrSettingsRepository::class);
        $settings->expects(self::once())->method('find')->with(41)->willReturn($stored);
        return $settings;
    }

    private function sqliteConnection(): Connection
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE countries (id INTEGER PRIMARY KEY, iso2 TEXT, name_cs TEXT, name_en TEXT)');
        $pdo->exec('CREATE TABLE supplier (id INTEGER PRIMARY KEY, country_id INTEGER, company_name TEXT, display_name TEXT)');
        $pdo->exec("INSERT INTO countries VALUES (1, 'CZ', 'Česko', 'Czechia')");
        $pdo->exec("INSERT INTO supplier VALUES (41, 1, 'Syntetický dodavatel s.r.o.', 'Syntetický dodavatel')");

        $connection = new Connection($this->createStub(Config::class));
        (new \ReflectionProperty(Connection::class, 'pdo'))->setValue($connection, $pdo);
        return $connection;
    }

    /** @return array<string,mixed> */
    private function issuedInvoice(): array
    {
        return [
            'id' => 81,
            'invoice_type' => 'invoice',
            'status' => 'issued',
            'language' => 'cs',
            'currency' => 'CZK',
            'currency_id' => 0,
            'client_id' => 0,
            'supplier_id' => 41,
            'varsymbol' => '202600001',
            'issue_date' => '2026-09-01',
            'tax_date' => '2026-09-01',
            'due_date' => '2026-09-18',
            'paid_at' => null,
            'amount_to_pay' => 1210.0,
            'paid_total' => 0.0,
            'parent_invoice_id' => null,
            'payment_method' => 'bank_transfer',
            'prices_include_vat' => false,
            'reverse_charge' => false,
            'branding_profile_id' => null,
            'advance_paid_amount' => 0.0,
            'czk_recap' => null,
            'supplier_snapshot' => json_encode([
                'id' => 41,
                'company_name' => 'Syntetický dodavatel s.r.o.',
                'display_name' => 'Syntetický dodavatel',
                'street' => 'Testovací 1',
                'city' => 'Praha',
                'zip' => '11000',
                'is_vat_payer' => true,
            ], JSON_UNESCAPED_UNICODE),
            'client_snapshot' => json_encode([
                'company_name' => 'Syntetický odběratel s.r.o.',
                'street' => 'Pokusná 2',
                'city' => 'Brno',
                'zip' => '60200',
            ], JSON_UNESCAPED_UNICODE),
            'bank_snapshot' => json_encode([
                'account_number' => '1000000005',
                'bank_code' => '0100',
                'bank_name' => 'Testovací banka',
                'iban' => '',
                'bic' => '',
            ], JSON_UNESCAPED_UNICODE),
            'items' => [[
                'description' => 'Syntetické plnění',
                'quantity' => 1.0,
                'unit' => 'ks',
                'unit_price_without_vat' => 1000.0,
                'vat_rate_snapshot' => 21.0,
                'total_without_vat' => 1000.0,
                'total_vat' => 210.0,
                'total_with_vat' => 1210.0,
                'item_kind' => 'standard',
                'oss_applicable' => false,
                'oss_consumer_country' => null,
            ]],
            'totals' => ['without_vat' => 1000.0, 'vat' => 210.0, 'with_vat' => 1210.0],
            'vat_breakdown' => [],
        ];
    }

    /** @template T of object @param class-string<T> $class @return T */
    private function withoutConstructor(string $class): object
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }
}
