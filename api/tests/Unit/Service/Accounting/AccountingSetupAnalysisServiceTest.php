<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Accounting;

use MyInvoice\Service\Accounting\Setup\AccountingSetupAnalysisService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class AccountingSetupAnalysisServiceTest extends TestCase
{
    public function testFlatSyntheticAccountProducesAnalyticProposal(): void
    {
        $proposal = $this->invoke('analyticForGroup', [
            'by_code' => [
                '501' => [
                    'id' => 10,
                    'account_code' => '501',
                    'account_type' => 'expense',
                    'normal_side' => 'debit',
                    'is_active' => true,
                    'is_synthetic' => true,
                ],
            ],
            'children' => [],
        ], 'small_asset', 'small_asset');

        self::assertSame('501.200', $proposal['account_code']);
        self::assertSame('501', $proposal['parent_account_code']);
        self::assertTrue($proposal['create']);
    }

    public function testNonFlatSyntheticAccountStillProducesMissingPreferredAnalytic(): void
    {
        $proposal = $this->invoke('analyticForGroup', [
            'by_code' => [
                '501' => [
                    'id' => 10,
                    'account_code' => '501',
                    'account_type' => 'expense',
                    'normal_side' => 'debit',
                    'is_active' => true,
                    'is_synthetic' => true,
                ],
            ],
            'children' => [10 => 1],
        ], 'small_asset', 'small_asset');

        self::assertSame('501.200', $proposal['account_code']);
        self::assertSame('501', $proposal['parent_account_code']);
        self::assertTrue($proposal['create']);
    }

    public function testExistingPreferredCodeMustBeAnalyticUnderExpectedParent(): void
    {
        $proposal = $this->invoke('analyticForGroup', [
            'by_code' => [
                '501' => [
                    'id' => 10,
                    'account_code' => '501',
                    'account_type' => 'expense',
                    'normal_side' => 'debit',
                    'is_active' => true,
                    'is_synthetic' => true,
                ],
                '501.100' => [
                    'id' => 11,
                    'parent_id' => null,
                    'account_code' => '501.100',
                    'account_type' => 'expense',
                    'normal_side' => 'debit',
                    'is_active' => true,
                    'is_synthetic' => true,
                ],
            ],
            'children' => [],
        ], 'fuel', 'material');

        self::assertNull($proposal);
    }

    public function testAnalyticTemplateCodesAreUniqueAndUseExpectedParents(): void
    {
        $templates = $this->invoke('analyticTemplates');

        self::assertSame([
            'fuel' => ['501.100', '501', 'Pohonné hmoty'],
            'small_asset' => ['501.200', '501', 'Drobný majetek'],
            'material' => ['501.900', '501', 'Ostatní materiál'],
            'energy' => ['502.100', '502', 'Spotřeba energie'],
            'vehicle_repair' => ['511.100', '511', 'Opravy vozidel'],
            'repair' => ['511.900', '511', 'Ostatní opravy a údržba'],
            'insurance' => ['548.100', '548', 'Pojištění'],
            'service' => ['518.100', '518', 'Ostatní služby'],
            'small_intangible' => ['518.200', '518', 'Drobný nehmotný majetek'],
            'fixed_asset' => ['042.100', '042', 'Pořízení DHM'],
        ], $templates);
        self::assertCount(count($templates), array_unique(array_column($templates, 0)));
        foreach ($templates as [$code, $parent]) {
            self::assertStringStartsWith($parent . '.', $code);
        }
    }

    public function testFixedAssetThresholdDoesNotFreezeFutureRuleAsFixedAsset(): void
    {
        $method = new ReflectionMethod(AccountingSetupAnalysisService::class, 'baseRuleKind');

        self::assertSame('small_asset', $method->invoke(null, ['expense_kind' => 'small_asset'], 'fixed_asset'));
    }

    public function testFixedAssetCandidateRequiresPriceStrictlyAboveAnnualLimit(): void
    {
        self::assertFalse($this->invoke('isAboveFixedAssetLimit', 80_000.0, 80_000.0));
        self::assertTrue($this->invoke('isAboveFixedAssetLimit', 80_000.01, 80_000.0));
    }

    public function testSmallAssetCatalogRulesAggregateAcrossVendors(): void
    {
        self::assertNull($this->invoke('groupingVendorId', 'small_asset', 10));
        self::assertNull($this->invoke('groupingVendorId', 'small_intangible', 20));
        self::assertSame(30, $this->invoke('groupingVendorId', 'service', 30));
    }

    public function testEnergyNatureUsesEnergyConsumptionAccount(): void
    {
        self::assertSame('502', $this->invoke('parentForAiNature', 'energy'));
    }

    public function testUtilityTermsCorrectOverlyBroadAiNature(): void
    {
        self::assertSame('energy', $this->invoke('correctAiNature', 'material', 'elektrinu', 'Elektrická energie'));
        self::assertSame('energy', $this->invoke('correctAiNature', 'service', 'vodne stocne', 'Voda a stočné'));
        self::assertSame('fuel', $this->invoke('correctAiNature', 'fuel', 'zemni plyn', 'Pohonné hmoty'));
    }

    public function testNewAiAnalyticIsMarkedForCreation(): void
    {
        $service = (new ReflectionClass(AccountingSetupAnalysisService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AccountingSetupAnalysisService::class, 'nextAiAnalytic');
        $reserved = [];
        $proposal = $method->invokeArgs($service, [[
            'by_code' => [
                '502' => [
                    'id' => 20,
                    'account_code' => '502',
                    'account_type' => 'expense',
                    'normal_side' => 'debit',
                    'is_active' => true,
                    'is_synthetic' => true,
                ],
            ],
            'children' => [],
        ], '502', 'Elektrická energie', &$reserved]);

        self::assertSame('502.100', $proposal['account_code']);
        self::assertTrue($proposal['create']);
    }

    public function testClassificationCoverageIsSafeForEmptyAndPartialHistory(): void
    {
        self::assertSame(0.0, $this->invoke('coveragePct', 0, 0));
        self::assertSame(78.9, $this->invoke('coveragePct', 540, 114));
        self::assertSame(100.0, $this->invoke('coveragePct', 10, 0));
    }

    public function testCatalogPhraseRequiresWholeNormalizedWords(): void
    {
        self::assertTrue($this->invoke('contains', 'cloud hosting', 'cloud'));
        self::assertFalse($this->invoke('contains', 'cloudova sluzba', 'cloud'));
        self::assertFalse($this->invoke('contains', 'supermarket', 'super'));
    }

    private function invoke(string $methodName, mixed ...$args): mixed
    {
        $service = (new ReflectionClass(AccountingSetupAnalysisService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AccountingSetupAnalysisService::class, $methodName);
        return $method->invoke($service, ...$args);
    }
}
