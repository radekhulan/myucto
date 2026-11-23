<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Report;

use MyInvoice\Action\Report\VatDimensionReportAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\EffectiveRole;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * DPH podle dimenze má práva Knihy DPH: náhled `reports`, export navíc `reports.export`.
 * Kontrola předchází sestavení sestavy (služby akce tu záměrně nejsou inicializované).
 */
final class VatDimensionReportActionAccessTest extends TestCase
{
    public function testPreviewRequiresReports(): void
    {
        $response = $this->action()->preview($this->request(['invoices' => 2]), new Response());
        self::assertSame(403, $response->getStatusCode());
    }

    public function testDownloadRequiresReportsExport(): void
    {
        $response = $this->action()->download($this->request(['reports' => 1]), new Response());
        self::assertSame(403, $response->getStatusCode());
    }

    public function testReportsRightPassesToValidation(): void
    {
        // Bez typu dimenze je to validační chyba, ne zákaz: právo `reports` stačí.
        $response = $this->action()->preview($this->request(['reports' => 1], ['year' => '2026', 'month' => '10']), new Response());
        self::assertSame(400, $response->getStatusCode());
        $response = $this->action()->download($this->request(['reports' => 1, 'reports.export' => 1], ['year' => '2026', 'month' => '10', 'type_id' => '11', 'format' => 'docx']), new Response());
        self::assertSame(400, $response->getStatusCode());
        $response = $this->action()->preview($this->request(['reports' => 1], ['year' => '2026', 'month' => '10', 'period' => 'yearly', 'type_id' => '11']), new Response());
        self::assertSame(400, $response->getStatusCode());
    }

    private function action(): VatDimensionReportAction
    {
        return (new \ReflectionClass(VatDimensionReportAction::class))->newInstanceWithoutConstructor();
    }

    /**
     * @param array<string,int> $permissions
     * @param array<string,string> $query
     */
    private function request(array $permissions, array $query = []): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', '/api/reports/vat-by-dimension/preview')
            ->withQueryParams($query)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 4])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 9)
            ->withAttribute('auth.effective_role', new EffectiveRole(8, 'Účetní', 'staff', true, $permissions));
    }
}
