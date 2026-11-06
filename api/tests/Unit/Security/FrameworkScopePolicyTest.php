<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Security;

use MyInvoice\Security\FrameworkScopePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FrameworkScopePolicyTest extends TestCase
{
    #[DataProvider('paths')]
    public function testScopeForPathMirrorsFrontendRouterScopes(string $path, ?string $scope): void
    {
        self::assertSame($scope, FrameworkScopePolicy::scopeForPath($path));
    }

    public static function paths(): iterable
    {
        yield 'SVJ registr' => ['/api/property/summary', FrameworkScopePolicy::SVJ];
        yield 'SVJ platby' => ['/api/property/payments/12/receive', FrameworkScopePolicy::SVJ];
        yield 'převod rámce' => ['/api/accounting/framework-conversions/choices', FrameworkScopePolicy::CONVERSION_504];
        yield 'společné rozvržení' => ['/api/accounting/joint-allocations', FrameworkScopePolicy::NONPROFIT_504];
        yield 'DPPO NNO' => ['/api/accounting/nonprofit-dppo', FrameworkScopePolicy::NONPROFIT_504];
        yield 'cenné papíry 504' => ['/api/accounting/securities/debt/preview', FrameworkScopePolicy::NONPROFIT_504];
        yield 'číselník NNO' => ['/api/accounting/catalog/nonprofit-funding/3', FrameworkScopePolicy::NONPROFIT_504];
        yield 'daň NNO období' => ['/api/accounting/periods/7/nonprofit-tax/draft', FrameworkScopePolicy::NONPROFIT_504];
        // Uzávěrku podle rámce 504 řídí rámec konkrétního období, ne dnešní rámec firmy.
        yield 'krok uzávěrky 504' => ['/api/accounting/periods/7/closing/deferred-tax-504-preview', null];
        yield 'běžný deník' => ['/api/accounting/journal', null];
        yield 'jiný katalog' => ['/api/accounting/catalog/accounts', null];
        yield 'podobný prefix' => ['/api/properties', null];
    }
}
