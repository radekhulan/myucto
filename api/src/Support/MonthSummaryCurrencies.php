<?php

declare(strict_types=1);

namespace MyInvoice\Support;

use PDO;

/**
 * Kódy měn pro skupiny souhrnu měsíce seznamu dokladů (#392). Agregace seskupuje podle
 * `currency_id` bez JOINu na každý doklad a kódy dohledá jedním dotazem pro výsledné
 * skupiny. Pořadí skupin: měsíc, pak kód měny, stejně jako dřív ORDER BY v SQL.
 */
final class MonthSummaryCurrencies
{
    /**
     * @param list<array<string,mixed>> $rows řádky s `month_bucket` a `currency_id`
     * @return list<array<string,mixed>> tytéž řádky s klíčem `currency` (kód měny)
     */
    public static function attach(PDO $pdo, array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = array_values(array_unique(array_map(static fn (array $r): int => (int) $r['currency_id'], $rows)));
        $stmt = $pdo->prepare('SELECT id, code FROM currencies WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $stmt->execute($ids);
        $codes = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $codes[(int) $c['id']] = (string) $c['code'];
        }
        foreach ($rows as &$r) {
            $r['currency'] = $codes[(int) $r['currency_id']] ?? '';
        }
        unset($r);
        usort($rows, static fn (array $a, array $b): int
            => [(string) $a['month_bucket'], $a['currency']] <=> [(string) $b['month_bucket'], $b['currency']]);
        return $rows;
    }
}
