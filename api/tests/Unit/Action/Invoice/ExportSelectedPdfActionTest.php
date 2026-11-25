<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Invoice;

use MyInvoice\Action\Invoice\ExportSelectedPdfAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Export\MergedInvoicePdfExporter;
use MyInvoice\Service\IpMatcher;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

#[AllowMockObjectsWithoutExpectations]
final class ExportSelectedPdfActionTest extends TestCase
{
    #[TestWith([false])]
    #[TestWith([true])]
    public function testExportsOwnedInvoicesForReadonlyUserAndPreservesOrder(bool $myeetReadOnly): void
    {
        $path = tempnam(sys_get_temp_dir(), 'selected-pdf-test-');
        self::assertNotFalse($path);
        file_put_contents($path, '%PDF-selected');

        $invoices = $this->createStub(InvoiceRepository::class);
        $invoices->method('find')->willReturnCallback(
            static fn (int $id): array => ['id' => $id, 'supplier_id' => 7, 'status' => 'issued'],
        );
        $exporter = $this->createMock(MergedInvoicePdfExporter::class);
        $exporter->expects(self::once())
            ->method('export')
            ->with([12, 11], ['id' => 7], 3, true, !$myeetReadOnly)
            ->willReturn(['path' => $path, 'signed' => true]);

        $request = $this->request(['ids' => '12,11,12', 'sign_pdf' => '1']);
        if ($myeetReadOnly) {
            $request = $request->withAttribute(SupplierScopeMiddleware::ATTR_MYEET_READONLY_SUPPLIER_ID, 7);
        }
        try {
            $response = ($this->action($invoices, $exporter))(
                $request,
                (new ResponseFactory())->createResponse(),
            );

            self::assertSame(200, $response->getStatusCode());
            self::assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
            self::assertStringContainsString('attachment', $response->getHeaderLine('Content-Disposition'));
            self::assertSame('%PDF-selected', (string) $response->getBody());
        } finally {
            @unlink($path);
        }
    }

    public function testRejectsMissingAndMalformedIds(): void
    {
        $invoices = $this->createStub(InvoiceRepository::class);
        $exporter = $this->createMock(MergedInvoicePdfExporter::class);
        $exporter->expects(self::never())->method('export');
        $action = $this->action($invoices, $exporter);

        $missing = $action($this->request([]), (new ResponseFactory())->createResponse());
        $malformed = $action($this->request(['ids' => '12,nope']), (new ResponseFactory())->createResponse());

        self::assertSame(400, $missing->getStatusCode());
        self::assertSame(400, $malformed->getStatusCode());
    }

    public function testRejectsMoreThanOneHundredInvoices(): void
    {
        $invoices = $this->createStub(InvoiceRepository::class);
        $exporter = $this->createMock(MergedInvoicePdfExporter::class);
        $exporter->expects(self::never())->method('export');
        $ids = implode(',', range(1, 101));

        $response = ($this->action($invoices, $exporter))(
            $this->request(['ids' => $ids]),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('too_many', (string) $response->getBody());
    }

    public function testReturns404BeforeExportForForeignInvoice(): void
    {
        $invoices = $this->createStub(InvoiceRepository::class);
        $invoices->method('find')->willReturn(['id' => 12, 'supplier_id' => 99]);
        $exporter = $this->createMock(MergedInvoicePdfExporter::class);
        $exporter->expects(self::never())->method('export');

        $response = ($this->action($invoices, $exporter))(
            $this->request(['ids' => '12']),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    public function testReportsUnavailableSignature(): void
    {
        $invoices = $this->createStub(InvoiceRepository::class);
        $invoices->method('find')->willReturn(['id' => 12, 'supplier_id' => 7, 'status' => 'issued']);
        $exporter = $this->createMock(MergedInvoicePdfExporter::class);
        $exporter->method('export')->willThrowException(new \DomainException('Podpis není dostupný.'));

        $response = ($this->action($invoices, $exporter))(
            $this->request(['ids' => '12', 'sign_pdf' => '1']),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('signature_unavailable', (string) $response->getBody());
    }

    public function testRejectsDraftAndCancelledInvoicesNamingThem(): void
    {
        $invoices = $this->createStub(InvoiceRepository::class);
        $invoices->method('find')->willReturnCallback(
            static fn (int $id): array => match ($id) {
                11 => ['id' => 11, 'supplier_id' => 7, 'status' => 'issued', 'varsymbol' => '2607001'],
                12 => ['id' => 12, 'supplier_id' => 7, 'status' => 'draft', 'varsymbol' => null],
                default => ['id' => $id, 'supplier_id' => 7, 'status' => 'cancelled', 'varsymbol' => '2607009'],
            },
        );
        $exporter = $this->createMock(MergedInvoicePdfExporter::class);
        $exporter->expects(self::never())->method('export');

        $response = ($this->action($invoices, $exporter))(
            $this->request(['ids' => '11,12,13']),
            (new ResponseFactory())->createResponse(),
        );
        $body = (string) $response->getBody();

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('not_exportable', $body);
        // Koncept bez varsymbolu se pojmenuje přes #id, stornovaný přes varsymbol.
        self::assertStringContainsString('#12', $body);
        self::assertStringContainsString('2607009', $body);
        self::assertStringNotContainsString('2607001', $body);
    }

    public function testRemovesTemporaryFileAfterReadingIt(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'selected-pdf-cleanup-');
        self::assertNotFalse($path);
        file_put_contents($path, '%PDF-cleanup');

        $invoices = $this->createStub(InvoiceRepository::class);
        $invoices->method('find')->willReturn(['id' => 12, 'supplier_id' => 7, 'status' => 'paid']);
        $exporter = $this->createMock(MergedInvoicePdfExporter::class);
        $exporter->method('export')->willReturn(['path' => $path, 'signed' => false]);

        $response = ($this->action($invoices, $exporter))(
            $this->request(['ids' => '12']),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('%PDF-cleanup', (string) $response->getBody());
        // Úklid nesmí viset na register_shutdown_function — na Windows by otevřený
        // stream handle unlink() zablokoval a temp soubory by se hromadily.
        self::assertFileDoesNotExist($path);
    }

    private function action(
        InvoiceRepository $invoices,
        MergedInvoicePdfExporter $exporter,
    ): ExportSelectedPdfAction {
        $logger = $this->createStub(ActivityLogger::class);
        $ip = $this->createStub(IpMatcher::class);
        $ip->method('clientIpFromRequest')->willReturn('127.0.0.1');
        return new ExportSelectedPdfAction($invoices, $exporter, $logger, $ip);
    }

    /** @param array<string,string> $query */
    private function request(array $query): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/invoices/export.pdf')
            ->withQueryParams($query)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 7)
            ->withAttribute(AuthMiddleware::ATTR_USER, [
                'id' => 3,
                'role' => 'readonly',
            ]);
    }
}
