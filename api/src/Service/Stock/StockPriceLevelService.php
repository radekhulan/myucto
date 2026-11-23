<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\CatalogPricingRuleRepository;
use MyInvoice\Repository\StockItemPriceRepository;
use MyInvoice\Repository\StockPriceLevelRepository;
use MyInvoice\Service\Eshop\Pricing\EffectivePriceResolver;
use MyInvoice\Service\Eshop\Pricing\PriceLevelResolver;

/**
 * Cenové hladiny odběratelů (migrace 1833) — sada pravidel hladiny, výjimky
 * produktu na kartě zboží a přiřazení hladiny odběrateli. Výslednou cenu počítá
 * vždy {@see PriceLevelResolver}, stejně jako při nacenění dokladu, aby editor
 * a doklad nemohly ukázat každý jiné číslo.
 */
final class StockPriceLevelService
{
    private const MAX_RULES = 2000;
    private const MAX_ITEM_ROWS = 200;
    private const MATCH_TYPES = ['product', 'category', 'manufacturer'];

    /** Nejvyšší přirážka (rozsah sloupce DECIMAL(6,3)) a nejvyšší sleva hladiny v %. */
    public const MIN_PCT = '-999.999';
    public const MAX_PCT = '100';
    public const ADJUSTMENT_MESSAGE = 'Sleva musí být nejvýše 100 %, přirážka nejvýše 999,999 % (zadává se jako záporná sleva), '
        . 's nejvýše třemi desetinnými místy.';

    /**
     * Sleva (kladná) nebo přirážka (záporná) cenové hladiny v %, normalizovaná na tři
     * desetinná místa; null = neplatná nebo mimo rozsah. Jediné místo s rozsahem pro
     * výchozí hodnotu hladiny i pravidla (CHECK v migraci 2011 drží totéž).
     */
    public static function adjustmentPct(mixed $value): ?string
    {
        $pct = StockItemCustomerPriceService::signedDecimal($value, 3);
        if ($pct === null || bccomp($pct, self::MIN_PCT, 3) < 0 || bccomp($pct, self::MAX_PCT, 3) > 0) {
            return null;
        }
        return $pct;
    }

    public function __construct(
        private readonly Connection $db,
        private readonly StockPriceLevelRepository $levels,
        private readonly StockItemPriceRepository $prices,
        private readonly CatalogPricingRuleRepository $contexts,
        private readonly EffectivePriceResolver $resolver,
    ) {}

    /** @return list<array<string,mixed>> */
    public function rules(int $supplierId, int $levelId): array
    {
        $this->requireLevel($supplierId, $levelId);
        return array_map([self::class, 'publicRule'], $this->levels->rulesForLevel($supplierId, $levelId));
    }

    /**
     * Nahradí celou sadu pravidel hladiny.
     *
     * @param mixed $body seznam {match_type, match_id, rule_type, discount_pct?, fixed_price?, currency_code?, priority?}
     * @return list<array<string,mixed>>
     */
    public function replaceRules(int $supplierId, int $levelId, mixed $body): array
    {
        $this->requireLevel($supplierId, $levelId);
        if (is_array($body) && isset($body['rules']) && is_array($body['rules'])) {
            $body = $body['rules'];
        }
        if (!is_array($body) || !array_is_list($body) || count($body) > self::MAX_RULES) {
            throw new StockException('validation_failed', 'Tělo musí být seznam nejvýše 2000 pravidel.', 422);
        }

        $rows = [];
        $seen = [];
        $byType = [];
        foreach ($body as $index => $raw) {
            if (!is_array($raw)) {
                throw new StockException('validation_failed', 'Neplatné pravidlo.', 422, ['index' => $index]);
            }
            $matchType = (string) ($raw['match_type'] ?? '');
            if (!in_array($matchType, self::MATCH_TYPES, true)) {
                throw new StockException('validation_failed', "match_type musí být 'product', 'category' nebo 'manufacturer'.", 422, ['index' => $index, 'field' => 'match_type']);
            }
            $matchId = (int) ($raw['match_id'] ?? 0);
            if ($matchId <= 0) {
                throw new StockException('validation_failed', 'Cíl pravidla je povinný.', 422, ['index' => $index, 'field' => 'match_id']);
            }
            $row = $this->normalizeRule($raw, $index, $matchType, $matchId);
            $key = $matchType . '|' . $matchId . '|' . ($row['currency_code'] ?? '');
            if (isset($seen[$key])) {
                throw new StockException(
                    'price_level_rule_duplicate',
                    'Pro jeden cíl a měnu smí mít hladina jen jedno pravidlo.',
                    422,
                    ['index' => $index, 'match_type' => $matchType, 'match_id' => $matchId],
                );
            }
            $seen[$key] = true;
            $rows[] = $row;
            $byType[$matchType][$index] = $matchId;
        }
        foreach ($byType as $type => $ids) {
            $known = $this->levels->existingMatchIds($supplierId, $type, array_values($ids));
            foreach ($ids as $index => $id) {
                if (!isset($known[$id])) {
                    throw new StockException('invalid_match', 'Cíl pravidla nepatří této firmě.', 422, ['index' => $index, 'match_type' => $type, 'match_id' => $id]);
                }
            }
        }

        $this->transactional(fn () => $this->levels->replaceRules($supplierId, $levelId, $rows));
        return $this->rules($supplierId, $levelId);
    }

    /**
     * Náhled karty: pro každou aktivní hladinu × měnu karty (CZK vždy, další měny
     * s cenou karty nebo s výjimkou produktu) cena dnes, množství 1, bez akce.
     *
     * @return list<array<string,mixed>>
     */
    public function itemOverview(int $supplierId, int $itemId): array
    {
        $this->requireItem($supplierId, $itemId);
        $levels = $this->levels->listForSupplier($supplierId, true);
        if ($levels === []) {
            return [];
        }
        $currencies = ['CZK' => true];
        foreach ($this->prices->listForItem($supplierId, $itemId) as $row) {
            if (($row['computed_price'] ?? null) !== null) {
                $currencies[strtoupper((string) $row['currency_code'])] = true;
            }
        }
        foreach ($this->levels->productRulesForItem($supplierId, $itemId) as $rule) {
            if ($rule['currency_code'] !== null) {
                $currencies[$rule['currency_code']] = true;
            }
        }
        $standard = [];
        foreach (array_keys($currencies) as $currency) {
            $standard[$currency] = $this->resolver->standardPrices($supplierId, [$itemId], $currency)[$itemId] ?? null;
        }
        $context = $this->contexts->itemContexts($supplierId, [$itemId])[$itemId];

        $out = [];
        foreach ($levels as $level) {
            foreach (array_keys($currencies) as $currency) {
                $rules = $this->levels->applicableRules($supplierId, $level['id'], [$itemId], $currency);
                $own = array_values(array_filter($rules, static fn (array $r): bool => $r['match_type'] === 'product'));
                $inheritedRules = array_values(array_filter($rules, static fn (array $r): bool => $r['match_type'] !== 'product'));
                $applied = PriceLevelResolver::apply($standard[$currency], $level, PriceLevelResolver::chooseRule($rules, $context, $currency));
                $inherited = PriceLevelResolver::apply($standard[$currency], $level, PriceLevelResolver::chooseRule($inheritedRules, $context, $currency));
                $productRule = PriceLevelResolver::chooseRule($own, $context, $currency);
                $out[] = [
                    'price_level_id'       => $level['id'],
                    'code'                 => $level['code'],
                    'name'                 => $level['name'],
                    'is_active'            => $level['is_active'],
                    'default_discount_pct' => $level['default_discount_pct'],
                    'currency_code'        => $currency,
                    'standard_price'       => $standard[$currency],
                    'resulting_price'      => $applied['price'] ?? $standard[$currency],
                    'source'               => $applied['source'] ?? 'none',
                    'rule'                 => ($applied['rule'] ?? null) !== null ? self::publicRule($applied['rule']) : null,
                    'product_rule'         => $productRule !== null ? self::publicRule($productRule) : null,
                    'inherited_price'      => $inherited['price'] ?? $standard[$currency],
                    'inherited_source'     => $inherited['source'] ?? 'none',
                ];
            }
        }
        return $out;
    }

    /**
     * Výjimky produktu na kartě — spravuje JEN pravidla `match_type = 'product'`
     * této karty, pravidla kategorií a výrobců nechá být. Položky se aplikují
     * v pořadí těla:
     *   {price_level_id, rule_type, discount_pct?, fixed_price?, currency_code?, priority?}
     *       … nastaví pravidlo hladiny pro měnu (bez měny = sleva ve všech měnách);
     *   {price_level_id, remove: true, currency_code?}
     *       … odebere pravidlo té měny (null = pravidlo bez měny), bez klíče všechna.
     *
     * @return list<array<string,mixed>> náhled {@see itemOverview()}
     */
    public function saveItem(int $supplierId, int $itemId, mixed $body): array
    {
        $this->requireItem($supplierId, $itemId);
        if (is_array($body) && isset($body['price_levels']) && is_array($body['price_levels'])) {
            $body = $body['price_levels'];
        }
        if (!is_array($body) || !array_is_list($body) || count($body) > self::MAX_ITEM_ROWS) {
            throw new StockException('validation_failed', 'Tělo musí být seznam nejvýše 200 výjimek cenových hladin.', 422);
        }

        $levels = [];
        $ops = [];
        $seen = [];
        foreach ($body as $index => $raw) {
            if (!is_array($raw)) {
                throw new StockException('validation_failed', 'Neplatná výjimka cenové hladiny.', 422, ['index' => $index]);
            }
            $levelId = (int) ($raw['price_level_id'] ?? 0);
            if ($levelId > 0 && !array_key_exists($levelId, $levels)) {
                $levels[$levelId] = $this->levels->find($supplierId, $levelId);
            }
            if ($levelId <= 0 || $levels[$levelId] === null) {
                throw new StockException('invalid_price_level', 'Cenová hladina nepatří této firmě.', 422, ['index' => $index, 'price_level_id' => $raw['price_level_id'] ?? null]);
            }
            if (!empty($raw['remove'])) {
                $all = !array_key_exists('currency_code', $raw);
                $currency = strtoupper(trim((string) ($raw['currency_code'] ?? '')));
                if ($currency !== '' && preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                    throw new StockException('validation_failed', 'Neplatný kód měny (ISO 4217, 3 znaky).', 422, ['index' => $index, 'field' => 'currency_code']);
                }
                $ops[] = ['remove', $levelId, $currency !== '' ? $currency : null, $all];
                continue;
            }
            $row = $this->normalizeRule($raw, $index, 'product', $itemId);
            $key = $levelId . '|' . ($row['currency_code'] ?? '');
            if (isset($seen[$key])) {
                throw new StockException(
                    'price_level_rule_duplicate',
                    'Pro jednu hladinu a měnu smí mít karta jen jednu výjimku.',
                    422,
                    ['index' => $index, 'price_level_id' => $levelId],
                );
            }
            $seen[$key] = true;
            $ops[] = ['upsert', $levelId, $row, array_key_exists('priority', $raw)];
        }

        $existing = [];
        foreach ($this->levels->productRulesForItem($supplierId, $itemId) as $rule) {
            $existing[$rule['price_level_id'] . '|' . ($rule['currency_code'] ?? '')] = $rule;
        }
        $this->transactional(function () use ($ops, $supplierId, $itemId, $existing): void {
            foreach ($ops as $op) {
                if ($op[0] === 'remove') {
                    $this->levels->deleteProductRules($supplierId, $op[1], $itemId, $op[2], $op[3]);
                    continue;
                }
                [, $levelId, $row, $hasPriority] = $op;
                if (!$hasPriority) {
                    // Priorita, kterou jí dal editor hladiny, se úpravou na kartě neztratí.
                    $row['priority'] = $existing[$levelId . '|' . ($row['currency_code'] ?? '')]['priority'] ?? 0;
                }
                $this->levels->deleteProductRules($supplierId, $levelId, $itemId, $row['currency_code']);
                $this->levels->insertRule($supplierId, $levelId, $row);
            }
        });
        return $this->itemOverview($supplierId, $itemId);
    }

    /**
     * Hladina k zápisu na kartu odběratele: null / 0 / '' = bez hladiny („Default").
     * Jinak musí patřit firmě a být aktivní. Neaktivní projde jen tehdy, když ji
     * odběratel už má — uložení jiných údajů karty nesmí shodit deaktivace hladiny.
     */
    public function assignableLevelId(int $supplierId, mixed $raw, ?int $currentLevelId): ?int
    {
        if ($raw === null || $raw === '' || $raw === 0 || $raw === '0' || $raw === false) {
            return null;
        }
        $id = is_int($raw) ? $raw : (is_string($raw) && ctype_digit($raw) ? (int) $raw : 0);
        $level = $id > 0 ? $this->levels->find($supplierId, $id) : null;
        if ($level === null || (!$level['is_active'] && $id !== $currentLevelId)) {
            throw new StockException('invalid_price_level', 'Cenová hladina neexistuje nebo není aktivní.', 422, ['price_level_id' => $raw]);
        }
        return $id;
    }

    /**
     * @param array<string,mixed> $raw
     * @return array{match_type:string, match_id:int, rule_type:string, discount_pct:?string,
     *               fixed_price:?string, currency_code:?string, priority:int}
     */
    private function normalizeRule(array $raw, int $index, string $matchType, int $matchId): array
    {
        $fail = static fn (string $field, string $message): StockException
            => new StockException('validation_failed', $message, 422, ['index' => $index, 'field' => $field]);

        $type = (string) ($raw['rule_type'] ?? '');
        if (!in_array($type, ['discount_pct', 'fixed'], true)) {
            throw $fail('rule_type', "rule_type musí být 'discount_pct' nebo 'fixed'.");
        }
        $currency = strtoupper(trim((string) ($raw['currency_code'] ?? '')));
        if ($currency !== '' && preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw $fail('currency_code', 'Neplatný kód měny (ISO 4217, 3 znaky).');
        }
        $fixed = null;
        $pct = null;
        if ($type === 'fixed') {
            if ($matchType !== 'product') {
                throw $fail('rule_type', 'Pevnou cenu lze zadat jen pro konkrétní produkt.');
            }
            if ($currency === '') {
                throw $fail('currency_code', 'Pevná cena musí mít měnu.');
            }
            $fixed = StockItemCustomerPriceService::unitPrice($raw['fixed_price'] ?? null);
            if ($fixed === null) {
                throw $fail('fixed_price', 'Pevná cena musí být nezáporné číslo s nejvýše šesti desetinnými místy.');
            }
        } else {
            $pct = self::adjustmentPct($raw['discount_pct'] ?? null);
            if ($pct === null) {
                throw $fail('discount_pct', self::ADJUSTMENT_MESSAGE);
            }
        }
        $priority = $raw['priority'] ?? 0;
        if (!is_int($priority) && !(is_string($priority) && preg_match('/^-?[0-9]{1,9}$/D', $priority) === 1)) {
            throw $fail('priority', 'Priorita musí být celé číslo.');
        }
        return [
            'match_type'    => $matchType,
            'match_id'      => $matchId,
            'rule_type'     => $type,
            'discount_pct'  => $pct,
            'fixed_price'   => $fixed,
            'currency_code' => $currency !== '' ? $currency : null,
            'priority'      => (int) $priority,
        ];
    }

    /**
     * @param array<string,mixed> $rule
     * @return array<string,mixed>
     */
    private static function publicRule(array $rule): array
    {
        unset($rule['price_level_id']);
        return $rule;
    }

    private function requireLevel(int $supplierId, int $levelId): void
    {
        if ($this->levels->find($supplierId, $levelId) === null) {
            throw new StockException('not_found', 'Cenová hladina nenalezena.', 404);
        }
    }

    private function requireItem(int $supplierId, int $itemId): void
    {
        if ($this->levels->existingMatchIds($supplierId, 'product', [$itemId]) === []) {
            throw new StockException('not_found', 'Skladová karta nenalezena.', 404);
        }
    }

    private function transactional(callable $fn): void
    {
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $fn();
            if ($own) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
