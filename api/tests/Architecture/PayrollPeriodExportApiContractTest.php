<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class PayrollPeriodExportApiContractTest extends TestCase
{
    public function testSessionOnlyPeriodExportRoutesAndSafetyGuardsRemainWired(): void
    {
        $root = dirname(__DIR__, 3);
        $routes = file_get_contents($root . '/api/src/Routes.php');
        $action = file_get_contents(
            $root . '/api/src/Action/Payroll/PayrollPeriodExportAction.php',
        );
        $service = file_get_contents(
            $root . '/api/src/Service/Payroll/Export/PayrollPeriodExportService.php',
        );
        self::assertIsString($routes);
        self::assertIsString($action);
        self::assertIsString($service);

        foreach ([
            '/exports/monthly/{period:[0-9]{4}-[0-9]{2}}',
            '/exports/annual/{year:[0-9]{4}}',
            '/exports/{exportId:[0-9]+}/download-grants',
            '/exports/download',
        ] as $route) {
            self::assertStringContainsString($route, $routes);
        }
        self::assertStringContainsString(
            "AuthMiddleware::ATTR_METHOD) === 'bearer'",
            $action,
        );
        self::assertStringContainsString(
            "'payroll.documents'",
            $action,
        );
        self::assertStringContainsString(
            "'Cache-Control', 'private, no-store'",
            $action,
        );
        self::assertStringContainsString(
            'readVerified(',
            $service,
        );
        self::assertStringContainsString(
            'consumeGrant(',
            $service,
        );
    }
}
