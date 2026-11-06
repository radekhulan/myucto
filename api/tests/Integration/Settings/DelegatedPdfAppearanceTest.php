<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Settings;

use MyInvoice\Action\Settings\ClientBrandingSettingsAction;
use MyInvoice\Action\Settings\SettingsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class DelegatedPdfAppearanceTest extends TestCase
{
    use IsolatedSupplierTrait;

    public static function pdfFields(): iterable
    {
        foreach ([false, true] as $delegated) {
            foreach ([
                'invoice_pdf_compact_items' => true,
                'invoice_pdf_show_issued_by' => true,
                'invoice_pdf_show_line_discount' => true,
                'invoice_pdf_show_base_qty' => false,
                'invoice_pdf_bank_in_footer' => true,
                'work_report_display_options' => ['amount' => false],
            ] as $field => $value) {
                yield ($delegated ? 'delegované ' : 'běžné ') . $field => [$delegated, $field, $value];
            }
        }
    }

    #[DataProvider('pdfFields')]
    public function testEachAppearanceFieldInvalidatesSupplierDraftsAndUsesSameResponse(bool $delegated, string $field, mixed $value): void
    {
        $container = Bootstrap::buildContainer();
        $pdo = $container->get(Connection::class)->pdo();
        $pdo->beginTransaction();
        try {
            $source = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
            self::assertGreaterThan(0, $source, 'Test vyžaduje syntetickou firmu.');
            $supplier = $this->createIsolatedSupplier($pdo, $source);
            $renderer = $this->createMock(InvoicePdfRenderer::class);
            $renderer->expects(self::once())->method('invalidateDraftsBySupplier')->with($supplier)->willReturn(1);
            $container->set(InvoicePdfRenderer::class, $renderer);
            $settings = $container->get(SettingsAction::class);
            $branding = $container->get(ClientBrandingSettingsAction::class);
            $request = (new ServerRequestFactory())->createServerRequest('PUT', '/api/settings/supplier')
                ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplier)
                ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
                ->withAttribute(AuthMiddleware::ATTR_USER, [
                    'id' => (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn(), 'role' => 'admin',
                ])
                ->withParsedBody([$field => $value]);
            $result = $delegated ? $branding->update($request, new Response()) : $settings->updateSupplier($request, new Response());
            self::assertSame(200, $result->getStatusCode(), (string) $result->getBody());
            $read = static fn ($response): array => json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
            $ordinary = $read($settings->getSupplier($request, new Response()));
            $narrow = $read($branding->get($request, new Response()));
            self::assertSame($ordinary[$field], $narrow[$field]);
            if (is_bool($value)) self::assertSame($value, $narrow[$field]);
            else self::assertFalse($narrow[$field]['amount']);
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
    }
}
