<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop\Pricing;

use MyInvoice\Repository\CatalogPricingExchangeRateRepository;
use MyInvoice\Repository\CatalogPricingProfileRepository;
use MyInvoice\Repository\CatalogPricingRuleRepository;

final class PricingRuleResolver
{
    private const RANK = [
        'product' => 400,
        'category' => 300,
        'manufacturer' => 300,
        'vendor' => 200,
        'default' => 100,
    ];

    public function __construct(
        private readonly CatalogPricingProfileRepository $profiles,
        private readonly CatalogPricingRuleRepository $rules,
        private readonly CatalogPricingExchangeRateRepository $rates,
    ) {}

    public function resolve(
        int $supplierId,
        int $stockItemId,
        string $currencyCode,
        ?PricingSnapshot $snapshot = null,
        ?array $context = null,
    ): ?array {
        $context ??= $this->itemContext($supplierId, $stockItemId);
        if ($snapshot !== null) {
            return self::choose(
                $snapshot->pricingPolicy['rules'] ?? [],
                $snapshot->pricingPolicy['profiles'] ?? [],
                $context,
                $currencyCode,
            );
        }
        return self::choose(
            $this->rules->listForSupplier($supplierId, true),
            $this->profiles->listForSupplier($supplierId, true),
            $context,
            $currencyCode,
        );
    }

    public function itemContext(int $supplierId, int $stockItemId): array
    {
        return $this->rules->itemContext($supplierId, $stockItemId);
    }

    public function snapshot(int $supplierId, string $onDate): array
    {
        $profiles = $this->profiles->listForSupplier($supplierId, true);
        $rules = $this->rules->listForSupplier($supplierId, true);
        $currencies = $this->rates->currenciesUsedForPricing($supplierId);
        $pairs = [];
        foreach ($profiles as $profile) {
            foreach ($currencies as $currency) {
                $pairs[] = ['source' => $profile['fx_source'], 'currency_code' => $currency];
            }
        }
        return [
            'profiles' => $profiles,
            'rules' => $rules,
            'rates' => $this->rates->snapshot($supplierId, $pairs, $onDate),
        ];
    }

    public static function choose(array $rules, array $profiles, array $context, string $currencyCode): ?array
    {
        $currencyCode = strtoupper($currencyCode);
        $profilesById = [];
        foreach ($profiles as $profile) {
            if (!($profile['is_active'] ?? false) || strtoupper((string) $profile['currency_code']) !== $currencyCode) {
                continue;
            }
            $profilesById[(int) $profile['id']] = $profile;
        }

        $candidates = [];
        foreach ($rules as $rule) {
            $profileId = (int) ($rule['profile_id'] ?? 0);
            if (!($rule['is_active'] ?? false) || !isset($profilesById[$profileId])) {
                continue;
            }
            if (!self::matches($rule, $context)) {
                continue;
            }
            $rule['precedence'] = self::RANK[(string) $rule['match_type']];
            $rule['profile'] = $profilesById[$profileId];
            $candidates[] = $rule;
        }
        usort($candidates, static fn (array $left, array $right): int =>
            [$right['precedence'], (int) $right['priority'], -(int) $right['id']]
            <=> [$left['precedence'], (int) $left['priority'], -(int) $left['id']]
        );
        return $candidates[0] ?? null;
    }

    private static function matches(array $rule, array $context): bool
    {
        $id = $rule['match_id'] === null ? null : (int) $rule['match_id'];
        return match ((string) $rule['match_type']) {
            'product' => $id === (int) $context['product_id'],
            'category' => in_array($id, $context['category_ids'], true),
            'manufacturer' => $id === $context['manufacturer_id'],
            'vendor' => in_array($id, $context['vendor_ids'], true),
            'default' => $id === null,
            default => false,
        };
    }
}
