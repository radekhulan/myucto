<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Dimension;

use PDO;

/**
 * Sdílené typy a hodnoty dimenzí skupiny firem (supplier_id IS NULL), na které odkazují
 * data jedné firmy.
 *
 * Export firmy je jako řádky nenese (patří skupině, ne firmě), ale přiřazení firmy na ně
 * míří. Archiv účetnictví ({@see \MyInvoice\Service\Accounting\Archive\ArchiveService})
 * i Kompletní export dat ({@see \MyInvoice\Service\Export\Instance\InstanceExportService})
 * je proto uvádějí zvlášť a obnova je v cíli dohledá, nikdy je nezakládá znovu.
 * Seznam odkazujících tabulek je tady jednou pro oba exporty.
 */
final class SharedDimensionReferences
{
    /** Tabulky firmy (sloupec supplier_id) a jejich sloupec s ID hodnoty dimenze. */
    public const VALUE_REFERENCES = [
        'journal_entry_line_dimensions' => 'dimension_value_id',
        'journal_entry_line_dimension_splits' => 'dimension_value_id',
        'document_dimensions' => 'dimension_value_id',
        'document_dimension_splits' => 'dimension_value_id',
        'dimension_defaults' => 'dimension_value_id',
        'dimension_account_map' => 'dimension_value_id',
        'dimension_account_rules' => 'default_value_id',
        'bank_posting_rule_dimensions' => 'dimension_value_id',
        'dimension_values' => 'parent_id',
    ];

    /**
     * @param array<string,mixed>|null $tables existující tabulky schématu (klíče); null = všechny
     * @return array{type_ids: list<int>, value_ids: list<int>} vzestupně
     */
    public static function collect(PDO $pdo, int $supplierId, ?array $tables = null): array
    {
        if ($tables !== null && (!isset($tables['dimension_values']) || !isset($tables['dimension_types']))) {
            return ['type_ids' => [], 'value_ids' => []];
        }
        $exists = [];
        $params = [];
        foreach (self::VALUE_REFERENCES as $table => $column) {
            if ($tables !== null && !isset($tables[$table])) {
                continue;
            }
            $exists[] = "EXISTS (SELECT 1 FROM {$table} x WHERE x.supplier_id = ? AND x.{$column} = v.id)";
            $params[] = $supplierId;
        }
        $values = [];
        if ($exists !== []) {
            $stmt = $pdo->prepare(
                'SELECT v.id, v.type_id FROM dimension_values v
                  WHERE v.supplier_id IS NULL AND (' . implode(' OR ', $exists) . ')'
            );
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $values[(int) $row['id']] = (int) $row['type_id'];
            }
        }
        // Řádek interního dokladu nese dimenze jako JSON {typ: hodnota}.
        if ($tables === null || isset($tables['internal_document_lines'])) {
            $stmt = $pdo->prepare('SELECT dimensions_json FROM internal_document_lines WHERE supplier_id = ? AND dimensions_json IS NOT NULL');
            $stmt->execute([$supplierId]);
            $ids = [];
            while (($json = $stmt->fetchColumn()) !== false) {
                $map = json_decode((string) $json, true);
                foreach (is_array($map) ? $map : [] as $value) {
                    if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                        $ids[(int) $value] = true;
                    }
                }
            }
            foreach (array_chunk(array_keys(array_diff_key($ids, $values)), 500) as $chunk) {
                $q = $pdo->prepare('SELECT id, type_id FROM dimension_values WHERE supplier_id IS NULL AND id IN ('
                    . implode(', ', array_fill(0, count($chunk), '?')) . ')');
                $q->execute($chunk);
                foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $values[(int) $row['id']] = (int) $row['type_id'];
                }
            }
        }
        $types = array_fill_keys(array_values($values), true);
        // Typ skupiny bez sdílené hodnoty: pravidlo firmy nebo vlastní hodnota firmy pod typem skupiny.
        $typeRefs = ['EXISTS (SELECT 1 FROM dimension_values v WHERE v.supplier_id = ? AND v.type_id = t.id)'];
        if ($tables === null || isset($tables['dimension_account_rules'])) {
            $typeRefs[] = 'EXISTS (SELECT 1 FROM dimension_account_rules r WHERE r.supplier_id = ? AND r.dimension_type_id = t.id)';
        }
        $stmt = $pdo->prepare('SELECT t.id FROM dimension_types t WHERE t.supplier_id IS NULL AND (' . implode(' OR ', $typeRefs) . ')');
        $stmt->execute(array_fill(0, count($typeRefs), $supplierId));
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $types[(int) $id] = true;
        }
        if ($types !== []) {
            // Hodnota skupiny může viset na typu firmy jen výjimečně; typ firmy export nese jako řádek.
            $ids = array_keys($types);
            $stmt = $pdo->prepare('SELECT id FROM dimension_types WHERE supplier_id IS NULL AND id IN ('
                . implode(', ', array_fill(0, count($ids), '?')) . ')');
            $stmt->execute($ids);
            $types = array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
        }
        $typeIds = array_keys($types);
        sort($typeIds);
        $valueIds = array_keys($values);
        sort($valueIds);
        return ['type_ids' => $typeIds, 'value_ids' => $valueIds];
    }
}
