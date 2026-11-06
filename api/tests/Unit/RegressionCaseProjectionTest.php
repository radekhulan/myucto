<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit;

use MyUctoRegression\CaseProjection;
use MyUctoRegression\ProjectionUnavailable;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/tools/regression/bootstrap.php';

final class RegressionCaseProjectionTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('unsupportedFrameworks')]
    public function testUnsupportedFrameworkCannotUseTheDefault500Builder(array $statement, array $proof): void
    {
        $container = new class ($statement) {
            public function __construct(private readonly array $statement) {}
            public function get(string $class): object
            {
                if (str_ends_with($class, 'ClosingEntryBuilder')) throw new \LogicException('504 must not reach the default 500 builder');
                return new class ($this->statement) {
                    public function __construct(private readonly array $statement) {}
                    public function balanceSheet(...$args): array { return $this->statement; }
                };
            }
        };
        try {
            CaseProjection::closing($container, ['kind' => 'closing_entry_projection', 'args' => [1, 2, '2025-01-01', '2025-12-31']]);
            self::fail('Unsupported framework was accepted.');
        } catch (ProjectionUnavailable $error) {
            self::assertSame('regression_closing_framework_unsupported', $error->errorCode);
            self::assertSame($proof, $error->frameworkProof);
        }
    }

    public static function unsupportedFrameworks(): iterable
    {
        $proof = ['accounting_framework' => 'cz_504', 'version_code' => 'cz504-full', 'statement_type' => 'balance_sheet'];
        yield 'native 504 framework' => [$proof, $proof];
        $proof = ['accounting_framework' => null, 'version_code' => 'vyhl504-2002/2016', 'statement_type' => 'balance_sheet'];
        yield 'old code sees a forward-incompatible definition' => [$proof + ['checks' => ['assets_net' => 0.0, 'liabilities_total' => 0.0]], $proof];
    }

    public function testProvisionProjectionKeepsEveryNativePage(): void
    {
        $service = new class {
            public array $calls = [];
            public function provisionsPreview(int $supplier, int $period, int $page, int $perPage): array
            {
                $this->calls[] = [$supplier, $period, $page, $perPage];
                return ['pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => 201], 'items' => [['invoice_id' => $page, 'remaining' => $page === 1 ? '10.00' : '987.65']]];
            }
        };
        $container = new class ($service) {
            public function __construct(private readonly object $service) {}
            public function get(string $class): object { return $this->service; }
        };
        $result = CaseProjection::closing($container, ['kind' => 'closing_provisions', 'args' => [1, 2, '2025-01-01', '2025-12-31']]);
        self::assertSame([[1, 2, 1, 200], [1, 2, 2, 200]], $service->calls);
        self::assertSame('987.65', $result['pages'][1]['items'][0]['remaining']);
    }
}
