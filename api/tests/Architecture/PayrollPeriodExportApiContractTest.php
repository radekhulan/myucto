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
            '/exports/jobs/{jobId:[0-9]+}',
            '/exports/jobs/{jobId:[0-9]+}/run',
            '/exports/jobs/{jobId:[0-9]+}/download-grants',
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
            'PayrollPeriodExportQueueService',
            $action,
        );
        self::assertStringContainsString('), 202)', $action);
        // Archiv se skládá hned po zařazení; cron je jen pojistka.
        self::assertStringContainsString(
            'BackgroundProcess::spawnPhp(',
            $action,
        );
        self::assertStringContainsString(
            'payroll-period-export-worker.php',
            $action,
        );
        self::assertStringContainsString(
            "'progress' => \$this->queue->progress(",
            $action,
        );
        $worker = file_get_contents(
            $root . '/api/bin/payroll-period-export-worker.php',
        );
        self::assertIsString($worker);
        // Doběhnutí jobu musí mít strop na iterace i na čas, jinak se worker zacyklí.
        self::assertStringContainsString('--job-id=', $worker);
        self::assertStringContainsString('--max-seconds=', $worker);
        self::assertStringContainsString('LOCK_EX | LOCK_NB', $worker);
        self::assertStringNotContainsString(
            '$this->exports->createMonthly(',
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
