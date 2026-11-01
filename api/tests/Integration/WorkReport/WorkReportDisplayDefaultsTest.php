<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\WorkReport;

use MyInvoice\Action\Settings\SettingsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\WorkReport\WorkReportDisplayOptions;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class WorkReportDisplayDefaultsTest extends TestCase
{
    use IsolatedSupplierTrait;

    public function testDefaultsAreValidatedAndIsolatedPerCompany(): void
    {
        $container = Bootstrap::buildContainer();
        $pdo = $container->get(Connection::class)->pdo();
        $settings = $container->get(SettingsAction::class);
        $pdo->beginTransaction();
        try {
            $supplier = $this->createIsolatedSupplier($pdo, 1);
            $other = $this->createIsolatedSupplier($pdo, 1);
            $request = (new ServerRequestFactory())->createServerRequest('PUT', '/api/settings/supplier')
                ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplier)
                ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
                ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1, 'role' => 'admin']);
            $response = $settings->updateSupplier($request->withParsedBody([
                'work_report_display_options' => ['compact' => true, 'rate' => false, 'amount' => false],
            ]), new Response());
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $read = static fn ($response): array => json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
            $actual = $read($settings->getSupplier($request, new Response()))['work_report_display_options'];
            self::assertTrue($actual['compact']);
            self::assertFalse($actual['rate']);
            self::assertFalse($actual['amount']);
            self::assertSame(WorkReportDisplayOptions::DEFAULTS,
                $read($settings->getSupplier($request->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $other), new Response()))['work_report_display_options']);

            $invalid = $settings->updateSupplier($request->withParsedBody(['work_report_display_options' => ['rate' => 'false']]), new Response());
            self::assertSame(400, $invalid->getStatusCode());
            self::assertSame($actual, $read($settings->getSupplier($request, new Response()))['work_report_display_options']);

            $response = $settings->updateSupplier($request->withParsedBody(['work_report_display_options' => null]), new Response());
            self::assertSame(200, $response->getStatusCode());
            self::assertSame(WorkReportDisplayOptions::DEFAULTS,
                $read($settings->getSupplier($request, new Response()))['work_report_display_options']);
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
    }
}
