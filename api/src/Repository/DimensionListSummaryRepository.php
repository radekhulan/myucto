<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Dimension\DimensionValueLabel;
use PDO;

final class DimensionListSummaryRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @param list<int> $documentIds @return array<int,list<string>> */
    public function forDocuments(int $supplierId, string $docType, array $documentIds): array
    {
        if ($documentIds === [] || !(new DimensionRepository($this->db))->enabled($supplierId)) return [];
        if (!in_array($docType, ['invoice', 'purchase_invoice'], true)) return [];
        $marks = implode(',', array_fill(0, count($documentIds), '?'));
        $sql = "SELECT d.doc_id AS owner_id, t.name AS type_name, v.code AS value_code, v.name AS value_name, v.semantic_key AS value_semantic_key
                  FROM document_dimensions d
                  JOIN dimension_types t ON t.id = d.dimension_type_id
                  JOIN dimension_values v ON v.id = d.dimension_value_id AND v.type_id = d.dimension_type_id
                 WHERE d.supplier_id = ? AND d.doc_type = ? AND d.doc_id IN ({$marks})
                UNION ALL
                SELECT s.doc_id AS owner_id, t.name AS type_name, v.code AS value_code, v.name AS value_name, v.semantic_key AS value_semantic_key
                  FROM document_dimension_splits s
                  JOIN dimension_types t ON t.id = s.dimension_type_id
                  JOIN dimension_values v ON v.id = s.dimension_value_id AND v.type_id = s.dimension_type_id
                 WHERE s.supplier_id = ? AND s.doc_type = ? AND s.doc_id IN ({$marks})";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, $docType, ...$documentIds, $supplierId, $docType, ...$documentIds]);
        return $this->summarize($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param list<int> $entryIds @return array<int,list<string>> */
    public function forJournalEntries(int $supplierId, array $entryIds): array
    {
        if ($entryIds === [] || !(new DimensionRepository($this->db))->enabled($supplierId)) return [];
        $marks = implode(',', array_fill(0, count($entryIds), '?'));
        $sql = "SELECT l.entry_id AS owner_id, t.name AS type_name, v.code AS value_code, v.name AS value_name, v.semantic_key AS value_semantic_key
                  FROM journal_entry_lines l
                  JOIN journal_entry_line_dimensions d ON d.line_id = l.id AND d.supplier_id = l.supplier_id
                  JOIN dimension_types t ON t.id = d.dimension_type_id
                  JOIN dimension_values v ON v.id = d.dimension_value_id AND v.type_id = d.dimension_type_id
                 WHERE l.supplier_id = ? AND l.entry_id IN ({$marks})
                UNION ALL
                SELECT l.entry_id AS owner_id, t.name AS type_name, v.code AS value_code, v.name AS value_name, v.semantic_key AS value_semantic_key
                  FROM journal_entry_lines l
                  JOIN journal_entry_line_dimension_splits s ON s.line_id = l.id AND s.supplier_id = l.supplier_id
                  JOIN dimension_types t ON t.id = s.dimension_type_id
                  JOIN dimension_values v ON v.id = s.dimension_value_id AND v.type_id = s.dimension_type_id
                 WHERE l.supplier_id = ? AND l.entry_id IN ({$marks})";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, ...$entryIds, $supplierId, ...$entryIds]);
        return $this->summarize($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param list<array<string,mixed>> $rows @return array<int,list<string>> */
    private function summarize(array $rows): array
    {
        $unique = [];
        foreach ($rows as $row) {
            $id = (int) $row['owner_id'];
            $label = (string) $row['type_name'] . ': '
                . DimensionValueLabel::short(['code' => $row['value_code'], 'name' => $row['value_name'], 'semantic_key' => $row['value_semantic_key']]);
            $unique[$id][$label] = true;
        }
        $result = [];
        foreach ($unique as $id => $labels) {
            $values = array_keys($labels);
            sort($values, SORT_NATURAL);
            $result[(int) $id] = $values;
        }
        return $result;
    }
}
