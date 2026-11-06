<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Action\Admin\Import\AiExtractPdfAction;
use MyInvoice\Routes;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;
use Slim\Routing\RoutingResults;

/**
 * AI import přijatých faktur přes API token (#233). Catch-all `/api/{path:.*}`
 * odpoví na každou cestu, takže „routa existuje" se musí ověřit tím, KAM se
 * požadavek doručí, ne jen tím, že router něco najde.
 */
final class PurchaseInvoiceAiImportRouteTest extends TestCase
{
    public function testTokenRouteDispatchesToAiExtractAction(): void
    {
        $app = AppFactory::create();
        Routes::register($app);

        $results = $app->getRouteResolver()->computeRoutingResults('/api/purchase-invoices/import-ai', 'POST');

        self::assertSame(RoutingResults::FOUND, $results->getRouteStatus());
        $route = $app->getRouteResolver()->resolveRoute((string) $results->getRouteIdentifier());
        self::assertSame('/api/purchase-invoices/import-ai', $route->getPattern());
        self::assertSame(AiExtractPdfAction::class, $route->getCallable());
    }
}
