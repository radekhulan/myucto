<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class OpenApiCoverageCheckerTest extends TestCase
{
    public function testCurrentPublicApiHasNoMissingOrStaleContracts(): void
    {
        $root = dirname(__DIR__, 3);
        [$exit, $output] = $this->check(
            (string) file_get_contents($root . '/api/src/Routes.php'),
            (string) file_get_contents($root . '/api/openapi.yaml'),
        );
        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('No drift', $output);
    }

    public function testBearerPolicySkipsInternalRoutesAndForbiddenAccountingWrites(): void
    {
        [$exit, $output] = $this->check(<<<'PHP'
<?php
$app->get('/api/payment-cards', PaymentCardAction::class);
$app->post('/api/accounting/dimensions/types', DimensionAction::class);
$app->get('/api/accounting/payroll/employees', PayrollEmployeeAction::class);
$app->get('/api/payroll/people', PayrollPeopleAction::class);
$app->get('/api/accounting/dimensions', DimensionAction::class);
PHP, <<<'YAML'
paths:
  /api/v1/payroll/people:
    get:
  /api/v1/accounting/dimensions:
    get:
YAML);
        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('No drift', $output);
    }

    public function testEnumAlternativesAreCoveredAndInvalidAlternativeRemainsStale(): void
    {
        $routes = <<<'PHP'
<?php
$app->group('/api/eshop', function ($g) {
    $g->post('/products/{id:[0-9]+}/{operation:retry|cancel}', ProductAction::class);
});
PHP;
        $spec = <<<'YAML'
paths:
  /api/v1/eshop/products/{id}/retry:
    post:
  /api/v1/eshop/products/{id}/cancel:
    post:
YAML;
        [$exit, $output] = $this->check($routes, $spec);
        self::assertSame(0, $exit, $output);
        [$exit, $output] = $this->check($routes, $spec . "\n  /api/v1/eshop/products/{id}/unknown:\n    post:\n");
        self::assertSame(1, $exit, $output);
        self::assertStringContainsString('(no methods) /api/eshop/products/{id}/unknown', $output);
    }

    public function testStaticRouteCannotHideBehindNumericPlaceholder(): void
    {
        [$exit, $output] = $this->check(<<<'PHP'
<?php
$app->get('/api/accounting/journal/{id:[0-9]+}', JournalAction::class);
$app->get('/api/accounting/journal/link-candidates', LinkAction::class);
PHP, <<<'YAML'
paths:
  /api/v1/accounting/journal/{id}:
    get:
YAML);
        self::assertSame(1, $exit, $output);
        self::assertStringContainsString('GET /api/accounting/journal/link-candidates', $output);
    }

    public function testPublicPayrollReadCannotBeSkippedByModulePrefix(): void
    {
        [$exit, $output] = $this->check("<?php\n\$app->get('/api/payroll/people', PayrollPeopleAction::class);\n", "paths:\n");
        self::assertSame(1, $exit, $output);
        self::assertStringContainsString('GET /api/payroll/people', $output);
    }

    public function testActionSessionExceptionsApplyToSymbols(): void
    {
        [$exit, $output] = $this->check(<<<'PHP'
<?php
use MyInvoice\Action\Stock\ProductAssemblyAction;
$app->get('/api/stock/assemblies', [ProductAssemblyAction::class, 'list']);
$app->get('/api/stock/assemblies/new-public-read', [ProductAssemblyAction::class, 'publicRead']);
PHP, "paths:\n");
        self::assertSame(1, $exit, $output);
        self::assertStringContainsString('GET /api/stock/assemblies/new-public-read', $output);
        self::assertStringNotContainsString("  - GET /api/stock/assemblies\n", $output);
    }

    public function testPropertyRouteRegistrarsAreReadFromRoutesFile(): void
    {
        $routes = <<<'PHP'
<?php
\MyInvoice\Routes\FundingRoutes::register($app);
\MyInvoice\Routes\ServiceLifecycleRoutes::register($app);
PHP;
        $spec = <<<'YAML'
paths:
  /api/v1/property/funds/commands:
    post:
  /api/v1/property/service-lifecycles/commands/{operation}:
    post:
YAML;
        [$exit, $output] = $this->check($routes, $spec);
        self::assertSame(0, $exit, $output);
        [$exit, $output] = $this->check($routes, $spec . "\n  /api/v1/property/funds/unknown:\n    post:\n");
        self::assertSame(1, $exit, $output);
        self::assertStringContainsString('(no methods) /api/property/funds/unknown', $output);
    }

    private function check(string $routes, string $spec): array
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'openapi-coverage-' . bin2hex(random_bytes(8));
        mkdir($dir);
        $routesFile = $dir . DIRECTORY_SEPARATOR . 'routes.php';
        $specFile = $dir . DIRECTORY_SEPARATOR . 'openapi.yaml';
        file_put_contents($routesFile, $routes);
        file_put_contents($specFile, $spec);
        try {
            $process = proc_open([PHP_BINARY, dirname(__DIR__, 3) . '/cmd/check-openapi-coverage.php',
                '--routes', $routesFile, '--spec', $specFile], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            return [proc_close($process), $output];
        } finally {
            unlink($routesFile);
            unlink($specFile);
            rmdir($dir);
        }
    }
}
