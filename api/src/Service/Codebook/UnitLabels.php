<?php

declare(strict_types=1);

namespace MyInvoice\Service\Codebook;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Popisky měrných jednotek z číselníku `units` (CS / EN) pro tisk dokladů.
 *
 * Řádek dokladu ukládá kód jednotky, popisek se dosazuje až při zobrazení.
 * Kódy se párují stejně jako v {@see UnitPrecision::key()}. Jednotka mimo
 * číselník a jednotka s prázdným popiskem se tiskne kódem.
 */
final class UnitLabels
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Popisky jednotek v jazyce dokladu, klíčem je {@see UnitPrecision::key()}.
     *
     * @return array<string,string>
     */
    public function byUnit(string $locale): array
    {
        $column = $locale === 'en' ? 'label_en' : 'label_cs';
        $rows = $this->db->pdo()->query(
            "SELECT code, {$column} AS label FROM units"
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $map = [];
        foreach ($rows as $r) {
            $label = trim((string) $r['label']);
            if ($label !== '') {
                $map[UnitPrecision::key((string) $r['code'])] = $label;
            }
        }
        return $map;
    }

    /** @param array<string,string> $map výstup {@see byUnit()} */
    public static function label(array $map, string $unit): string
    {
        return $map[UnitPrecision::key($unit)] ?? $unit;
    }
}
