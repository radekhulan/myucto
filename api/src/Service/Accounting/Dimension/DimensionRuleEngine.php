<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Dimension;

/**
 * Čisté jádro pravidel dimenzí podle účtu (jednotkově testovatelné, bez DB).
 *
 * Pro každý řádek a typ dimenze vezme pravidla, jejichž maska účtu řádku odpovídá
 * a jejichž platnost pokrývá datum účetního případu:
 *
 *   • Řádek hodnotu typu má (jedinou nebo rozpad) → pravidlo je splněné, nic se nemění.
 *     Hodnota z dokladu, zakázky, klienta nebo ruční volby má vždy přednost.
 *   • Jinak se doplní výchozí hodnota pravidla: nejdřív vozidlo podle platební karty
 *     (`default_from_card`), pak pevná hodnota. Při souběhu více pravidel téhož typu
 *     rozhoduje specifičtější maska (delší předpona), při shodě starší pravidlo.
 *   • Zůstane-li řádek bez hodnoty, vznikne porušení s nejpřísnějším vynucením
 *     ze všech pravidel typu (error > warning; `none` porušení nehlásí).
 */
final class DimensionRuleEngine
{
    private const SEVERITY = ['none' => 0, 'warning' => 1, 'error' => 2];

    /**
     * @param list<array{id:int, dimension_type_id:int, mask:DimensionAccountMask, enforcement:string,
     *                    default_value_id:?int, default_from_card:bool, valid_from:?string, valid_to:?string}> $rules
     * @param list<array<string,mixed>> $lines řádky s klíčem `account_code` (+ `dimensions`, `dimension_splits`)
     * @param (callable(int):?int)|null $cardValue typ => hodnota vozidla podle platební karty dokladu
     * @return array{lines:list<array<string,mixed>>, defaults:int,
     *               violations:list<array{line:int, account_code:string, type_id:int, enforcement:string, rule_id:int}>}
     */
    public static function apply(array $rules, array $lines, string $date, ?callable $cardValue, bool $fillDefaults = true): array
    {
        $rules = array_values(array_filter($rules, static fn (array $r): bool => self::validOn($r, $date)));
        $defaults = 0;
        $violations = [];
        if ($rules === []) {
            return ['lines' => array_values($lines), 'defaults' => 0, 'violations' => []];
        }
        $cardCache = [];
        $out = [];
        foreach (array_values($lines) as $i => $line) {
            $code = (string) ($line['account_code'] ?? '');
            /** @var array<int,list<array{rule:array<string,mixed>, specificity:int}>> $byType */
            $byType = [];
            foreach ($rules as $rule) {
                $specificity = $rule['mask']->specificity($code);
                if ($specificity > 0) {
                    $byType[$rule['dimension_type_id']][] = ['rule' => $rule, 'specificity' => $specificity];
                }
            }
            foreach ($byType as $typeId => $matches) {
                if (self::hasType($line, $typeId)) {
                    continue;
                }
                if ($fillDefaults) {
                    usort($matches, static fn (array $a, array $b): int
                        => [$b['specificity'], $a['rule']['id']] <=> [$a['specificity'], $b['rule']['id']]);
                    $valueId = null;
                    $origin = null;
                    foreach ($matches as $m) {
                        if ($m['rule']['default_from_card'] && $cardValue !== null) {
                            $valueId = $cardCache[$typeId] ??= ($cardValue($typeId) ?? 0);
                            $valueId = $valueId > 0 ? $valueId : null;
                            if ($valueId !== null) $origin = ['origin' => 'card_default', 'ref' => 'rule:' . $m['rule']['id']];
                        }
                        if ($valueId === null && $m['rule']['default_value_id'] !== null) {
                            $valueId = $m['rule']['default_value_id'];
                            $origin = ['origin' => 'rule_default', 'ref' => 'rule:' . $m['rule']['id']];
                        }
                        if ($valueId !== null) {
                            break;
                        }
                    }
                    if ($valueId !== null) {
                        $dims = (array) ($line['dimensions'] ?? []);
                        $dims[$typeId] = $valueId;
                        ksort($dims);
                        $line['dimensions'] = $dims;
                        $line['dimension_origins'][$typeId] = DimensionAssignmentOrigin::normalize($origin ?? []);
                        $defaults++;
                        continue;
                    }
                }
                $strictest = null;
                foreach ($matches as $m) {
                    if ($strictest === null
                        || self::SEVERITY[$m['rule']['enforcement']] > self::SEVERITY[$strictest['enforcement']]) {
                        $strictest = $m['rule'];
                    }
                }
                if ($strictest !== null && $strictest['enforcement'] !== 'none') {
                    $violations[] = [
                        'line' => $i,
                        'account_code' => $code,
                        'type_id' => $typeId,
                        'enforcement' => $strictest['enforcement'],
                        'rule_id' => $strictest['id'],
                    ];
                }
            }
            $out[] = $line;
        }
        return ['lines' => $out, 'defaults' => $defaults, 'violations' => $violations];
    }

    /** @param array<string,mixed> $rule */
    public static function validOn(array $rule, string $date): bool
    {
        return ($rule['valid_from'] === null || $rule['valid_from'] <= $date)
            && ($rule['valid_to'] === null || $date <= $rule['valid_to']);
    }

    /** @param array<string,mixed> $line */
    public static function hasType(array $line, int $typeId): bool
    {
        return isset(((array) ($line['dimensions'] ?? []))[$typeId])
            || !empty(((array) ($line['dimension_splits'] ?? []))[$typeId] ?? null);
    }
}
