<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Eet;

use MyInvoice\Action\Eet\EetRecordAction;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\EffectiveRole;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * EET souhrn faktury vypovídá o faktuře: route vyžaduje `eet`, akce navíc čtení faktur.
 */
final class EetRecordActionInvoicePermissionTest extends TestCase
{
    public function testInvoiceSummaryWithoutInvoiceReadIsForbidden(): void
    {
        $action = (new \ReflectionClass(EetRecordAction::class))->newInstanceWithoutConstructor();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/invoices/1/eet')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 1)
            ->withAttribute('auth.effective_role', new EffectiveRole(1, 'Pokladní', 'staff', true, ['eet' => 1]));

        $response = $action->invoice($request, new Response(), ['id' => '1']);

        self::assertSame(403, $response->getStatusCode());
    }
}
