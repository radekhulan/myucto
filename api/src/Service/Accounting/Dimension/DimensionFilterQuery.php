<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Dimension;

/**
 * Hodnota parametru filtru na dimenzi z požadavku: `dimension_value_id` (hodnota, nebo
 * víc hodnot oddělených čárkou) a `dimension_descendants` (`0` = jen hodnoty samy, bez
 * podřízených). Jediné místo, kde se parametr čte: sestavy účetnictví (top-level query),
 * seznamy faktur (`filter[dimension_value_id]`) i výkazy DPH tak odmítají a skládají
 * filtr stejně. Hodnoty různých typů musí platit zároveň, hodnoty stejného typu stačí
 * kterákoli ({@see DimensionService::filters()}).
 */
final class DimensionFilterQuery
{
    public const MAX_VALUES = 10;

    /**
     * @throws DimensionException neplatná nebo cizí hodnota
     * @return DimensionFilter|null null = parametr nezadán
     */
    public static function parse(DimensionService $dimensions, int $supplierId, mixed $rawValueIds, mixed $rawDescendants): ?DimensionFilter
    {
        if ($rawValueIds === null) {
            return null;
        }
        if (!is_string($rawValueIds) && !is_int($rawValueIds)) {
            throw new DimensionException('dimension_value_invalid', 'Neplatná hodnota dimenze ve filtru.');
        }
        $raw = trim((string) $rawValueIds);
        if ($raw === '' || $raw === '0') {
            return null;
        }
        $valueIds = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if (preg_match('/^[1-9][0-9]{0,9}$/', $part) !== 1) {
                throw new DimensionException('dimension_value_invalid', 'Neplatná hodnota dimenze ve filtru.');
            }
            $valueIds[] = (int) $part;
        }
        if (count($valueIds) > self::MAX_VALUES) {
            throw new DimensionException('dimension_value_invalid', 'Filtr může obsahovat nejvýše ' . self::MAX_VALUES . ' hodnot dimenzí.');
        }
        $descendants = !is_scalar($rawDescendants) || (string) $rawDescendants !== '0';
        return $dimensions->filters($supplierId, $valueIds, $descendants);
    }
}
