<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RoutePermissionMap;
use PHPUnit\Framework\TestCase;

final class PayrollPaymentsApiContractTest extends TestCase
{
    public function testRoutesExposeSafeLiabilityAndAccountVerificationCommands(): void
    {
        $routes = $this->read('api/src/Routes.php');

        self::assertStringContainsString(
            "\$g->get('/payments/liabilities', [PayrollPaymentAction::class, 'listLiabilities']);",
            $routes,
        );
        foreach ([
            "\$g->get('/payments/payer-options', [PayrollPaymentAction::class, 'listPayerOptions']);",
            "\$g->get('/payments/batches', [PayrollPaymentAction::class, 'listBatches']);",
            "\$g->post('/payments/batches', [PayrollPaymentAction::class, 'createBatch']);",
            "\$g->get('/payments/reconciliation', [PayrollPaymentAction::class, 'listReconciliation']);",
            "\$g->post('/payments/reconciliation/matches', [PayrollPaymentAction::class, 'matchPayment']);",
            "\$g->post('/payments/reconciliation/reversals', [PayrollPaymentAction::class, 'reversePayment']);",
            "'/payments/batches/{batchId:[0-9]+}/exports'",
            "'/payments/exports/{exportId:[0-9]+}/download-grants'",
            "\$g->post('/payments/exports/download', [PayrollPaymentAction::class, 'downloadExport']);",
        ] as $route) {
            self::assertStringContainsString($route, $routes);
        }
        self::assertStringContainsString(
            "'/revisions/{revisionId:[0-9]+}/payments/liabilities'",
            $routes,
        );
        self::assertStringContainsString(
            "'/revisions/{revisionId:[0-9]+}/payments/net-wage-liabilities'",
            $routes,
        );
        self::assertStringContainsString(
            "'/people/{employeeId:[0-9]+}/accounts/{accountId:[0-9]+}/verify'",
            $routes,
        );
    }

    public function testRoutesUseSpecificPaymentAndPersonWritePermissions(): void
    {
        $map = new RoutePermissionMap();

        $list = $map->match('GET', '/api/payroll/payments/liabilities');
        self::assertNotNull($list);
        self::assertSame('payroll.payments', $list->key);
        self::assertSame(AccessLevel::READ, $list->minimum);

        $materialize = $map->match(
            'POST',
            '/api/payroll/revisions/7/payments/liabilities',
        );
        self::assertNotNull($materialize);
        self::assertSame('payroll.payments', $materialize->key);
        self::assertSame(AccessLevel::WRITE, $materialize->minimum);

        foreach ([
            ['GET', '/api/payroll/payments/payer-options', AccessLevel::READ],
            ['GET', '/api/payroll/payments/batches', AccessLevel::READ],
            ['POST', '/api/payroll/payments/batches', AccessLevel::WRITE],
            ['GET', '/api/payroll/payments/reconciliation', AccessLevel::READ],
            ['POST', '/api/payroll/payments/reconciliation/matches', AccessLevel::WRITE],
            ['POST', '/api/payroll/payments/reconciliation/reversals', AccessLevel::WRITE],
            ['POST', '/api/payroll/payments/batches/7/exports', AccessLevel::WRITE],
            ['POST', '/api/payroll/payments/exports/8/download-grants', AccessLevel::WRITE],
            ['POST', '/api/payroll/payments/exports/download', AccessLevel::WRITE],
        ] as [$method, $path, $access]) {
            $matched = $map->match($method, $path);
            self::assertNotNull($matched, "{$method} {$path}");
            self::assertSame('payroll.payments', $matched->key);
            self::assertSame($access, $matched->minimum);
        }

        $verify = $map->match(
            'POST',
            '/api/payroll/people/8/accounts/9/verify',
        );
        self::assertNotNull($verify);
        self::assertSame('payroll.person.write', $verify->key);
        self::assertSame(AccessLevel::WRITE, $verify->minimum);
    }

    public function testSessionOnlyPaymentEndpointsStayOutsideBearerOpenApi(): void
    {
        $openApi = $this->read('api/openapi.yaml');
        self::assertStringNotContainsString('/api/v1/payroll/', $openApi);
        self::assertStringNotContainsString('/api/v1/accounting/payroll/', $openApi);
        self::assertStringNotContainsString('/api/v1/accounting/reports/payroll-sheet', $openApi);
    }

    private function read(string $relativePath): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . '/' . $relativePath);
        self::assertIsString($contents);
        return $contents;
    }
}
