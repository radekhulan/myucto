<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop\Pricing;

use MyInvoice\Repository\CatalogPricingRuleRepository;
use MyInvoice\Repository\StockPriceLevelRepository;

/**
 * Cenové hladiny odběratelů (migrace 1833) — základ ceny karty podle hladiny
 * přiřazené odběrateli. Volá ho {@see EffectivePriceResolver} až po individuální
 * ceně zákazníka a náhled na kartě zboží, aby doklad a editor ukázaly totéž číslo.
 *
 * Výběr pravidla (první, co platí, vyhrává):
 *   1. pravidla hladiny shodná s kartou (produkt / kategorie karty / výrobce karty)
 *      v měně dokladu nebo bez měny — pořadí přesně jako u cenových profilů
 *      ({@see PricingRuleResolver}): typ shody (produkt > kategorie = výrobce),
 *      pak `priority` sestupně, pak pravidlo pro konkrétní měnu před pravidlem
 *      bez měny, pak nižší id;
 *   2. výchozí hodnota hladiny, když je nenulová.
 * Kladné `discount_pct` je sleva, záporné přirážka (−45 = 145 % standardní ceny).
 * Pevná cena v jiné měně se nepoužije. Sleva i přirážka se počítá ze standardní ceny
 * na šest desetinných míst stejně jako u zákaznické ceny; bez standardní ceny hladina nic neurčí.
 */
final class PriceLevelResolver
{
    public function __construct(
        private readonly StockPriceLevelRepository $levels,
        private readonly CatalogPricingRuleRepository $contexts,
    ) {}

    /** @return array{id:int, code:string, name:string, default_discount_pct:string}|null */
    public function levelForClient(int $supplierId, int $clientId): ?array
    {
        return $clientId > 0 ? $this->levels->activeLevelForClient($supplierId, $clientId) : null;
    }

    /**
     * Hladina zvolená na dokladu (migrace 1880), přepisuje hladinu odběratele.
     *
     * @return array{id:int, code:string, name:string, default_discount_pct:string}|null
     */
    public function activeLevel(int $supplierId, int $levelId): ?array
    {
        return $levelId > 0 ? $this->levels->activeLevel($supplierId, $levelId) : null;
    }

    /**
     * Základ ceny podle hladiny — jen pro karty, u kterých hladina něco určuje.
     *
     * @param array{id:int, code:string, name:string, default_discount_pct:string} $level
     * @param list<int> $stockItemIds
     * @param array<int,string> $standard stock_item_id => standardní cena
     * @return array<int, array{price:string, rule_type:string, discount_pct:?string, source:string, rule:?array<string,mixed>}>
     */
    public function baselines(int $supplierId, array $level, array $stockItemIds, string $currency, array $standard): array
    {
        if ($stockItemIds === []) {
            return [];
        }
        $rules = $this->levels->applicableRules($supplierId, $level['id'], $stockItemIds, $currency);
        $contexts = $rules === [] ? [] : $this->contexts->itemContexts($supplierId, $stockItemIds);
        $out = [];
        foreach ($stockItemIds as $itemId) {
            $rule = $rules === [] ? null : self::chooseRule($rules, $contexts[$itemId], $currency);
            $applied = self::apply($standard[$itemId] ?? null, $level, $rule);
            if ($applied !== null) {
                $out[$itemId] = $applied;
            }
        }
        return $out;
    }

    /**
     * Vítězné pravidlo hladiny pro kartu a měnu; null = žádné neplatí.
     *
     * @param list<array<string,mixed>> $rules
     * @param array<string,mixed> $context viz {@see CatalogPricingRuleRepository::itemContexts()}
     * @return array<string,mixed>|null
     */
    public static function chooseRule(array $rules, array $context, string $currency): ?array
    {
        $currency = strtoupper($currency);
        $candidates = [];
        foreach ($rules as $rule) {
            if ($rule['currency_code'] !== null && strtoupper((string) $rule['currency_code']) !== $currency) {
                continue;
            }
            if (!PricingRuleResolver::matches($rule, $context)) {
                continue;
            }
            $candidates[] = $rule;
        }
        usort($candidates, static fn (array $left, array $right): int =>
            [PricingRuleResolver::rank((string) $right['match_type']), (int) $right['priority'], $right['currency_code'] !== null ? 1 : 0, -(int) $right['id']]
            <=> [PricingRuleResolver::rank((string) $left['match_type']), (int) $left['priority'], $left['currency_code'] !== null ? 1 : 0, -(int) $left['id']]
        );
        return $candidates[0] ?? null;
    }

    /**
     * Cena za základní jednotku podle pravidla, nebo podle výchozí slevy hladiny.
     *
     * @param array{default_discount_pct:string} $level
     * @param array<string,mixed>|null $rule
     * @return array{price:string, rule_type:string, discount_pct:?string, source:string, rule:?array<string,mixed>}|null
     */
    public static function apply(?string $standardPrice, array $level, ?array $rule): ?array
    {
        if ($rule !== null) {
            $fixed = (string) $rule['rule_type'] === 'fixed';
            $price = EffectivePriceResolver::customerBaseline($standardPrice, [
                'price_type'   => $fixed ? 'fixed' : 'discount_pct',
                'fixed_price'  => $rule['fixed_price'],
                'discount_pct' => $rule['discount_pct'],
            ]);
            if ($price === null) {
                return null;
            }
            return [
                'price'        => $price,
                'rule_type'    => $fixed ? 'fixed' : 'discount_pct',
                'discount_pct' => $fixed ? null : (string) $rule['discount_pct'],
                'source'       => (string) $rule['match_type'],
                'rule'         => $rule,
            ];
        }
        // Výchozí hodnota hladiny: kladná = sleva, záporná = přirážka, nula = hladina mimo
        // pravidla cenu nemění.
        $default = (string) $level['default_discount_pct'];
        if (bccomp($default, '0', 3) === 0) {
            return null;
        }
        $price = EffectivePriceResolver::customerBaseline($standardPrice, [
            'price_type' => 'discount_pct', 'fixed_price' => null, 'discount_pct' => $default,
        ]);
        if ($price === null) {
            return null;
        }
        return ['price' => $price, 'rule_type' => 'discount_pct', 'discount_pct' => $default, 'source' => 'default', 'rule' => null];
    }
}
