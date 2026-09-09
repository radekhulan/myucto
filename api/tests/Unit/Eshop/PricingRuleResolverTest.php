<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Eshop;

use MyInvoice\Service\Eshop\Pricing\PricingRuleResolver;
use PHPUnit\Framework\TestCase;

final class PricingRuleResolverTest extends TestCase
{
    public function testSpecificityThenPriorityThenLowestIdDetermineWinner(): void
    {
        $profiles = array_map(static fn (int $id): array => [
            'id' => $id,
            'currency_code' => 'EUR',
            'is_active' => true,
        ], range(1, 7));
        $context = [
            'product_id' => 10,
            'manufacturer_id' => 20,
            'category_ids' => [30],
            'vendor_ids' => [40],
        ];
        $rules = [
            $this->rule(70, 7, 'default', null, 999999),
            $this->rule(60, 6, 'vendor', 40, 999999),
            $this->rule(50, 5, 'manufacturer', 20, 5),
            $this->rule(40, 4, 'category', 30, 10),
            $this->rule(30, 3, 'category', 30, 10),
            $this->rule(20, 2, 'product', 10, -999999),
        ];

        self::assertSame(20, PricingRuleResolver::choose($rules, $profiles, $context, 'EUR')['id']);

        $withoutProduct = array_values(array_filter($rules, static fn (array $rule): bool => $rule['match_type'] !== 'product'));
        self::assertSame(30, PricingRuleResolver::choose($withoutProduct, $profiles, $context, 'EUR')['id']);

        $withoutCategories = array_values(array_filter($withoutProduct, static fn (array $rule): bool => $rule['match_type'] !== 'category'));
        self::assertSame(50, PricingRuleResolver::choose($withoutCategories, $profiles, $context, 'EUR')['id']);
    }

    public function testInactiveAndOtherCurrencyRulesAreIgnored(): void
    {
        $profiles = [
            ['id' => 1, 'currency_code' => 'CZK', 'is_active' => true],
            ['id' => 2, 'currency_code' => 'EUR', 'is_active' => false],
        ];
        $context = ['product_id' => 10, 'manufacturer_id' => null, 'category_ids' => [], 'vendor_ids' => []];
        $rules = [$this->rule(1, 1, 'default', null, 0), $this->rule(2, 2, 'product', 10, 0)];

        self::assertNull(PricingRuleResolver::choose($rules, $profiles, $context, 'EUR'));
        self::assertSame(1, PricingRuleResolver::choose($rules, $profiles, $context, 'CZK')['id']);
    }

    private function rule(int $id, int $profileId, string $type, ?int $matchId, int $priority): array
    {
        return [
            'id' => $id,
            'profile_id' => $profileId,
            'match_type' => $type,
            'match_id' => $matchId,
            'priority' => $priority,
            'is_active' => true,
        ];
    }
}
