<?php

declare(strict_types=1);

namespace MyInvoice\Service\Sample;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\CostCenterRepository;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionValueHistoryGuard;
use PDO;

final class SampleDimensionPurger
{
    public function __construct(
        private readonly Connection $db,
        private readonly DimensionRepository $repo,
        private readonly CostCenterRepository $costCenters,
    ) {}

    public function purge(int $supplierId, array $journalIds = []): array
    {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) throw new \LogicException('Odebrání ukázkových dimenzí vyžaduje otevřenou transakci.');
        (new DimensionValueHistoryGuard($this->db))->lockPeriods();
        $supplier = $this->rows('SELECT id, supplier_group_id FROM supplier WHERE id=? FOR UPDATE', [$supplierId])[0] ?? null;
        if ($supplier === null) throw new \DomainException('Firma pro odebrání ukázkových dimenzí neexistuje.');
        $owned = [];
        foreach ($this->rows('SELECT entity_type, entity_id FROM sample_data_entries WHERE supplier_id=?', [$supplierId]) as $row) {
            $owned[$row['entity_type']][(int) $row['entity_id']] = true;
        }
        if ($journalIds !== []) {
            foreach ($this->rows('SELECT id FROM journal_entry_lines WHERE supplier_id=? AND entry_id IN (' . self::marks($journalIds) . ')', [$supplierId, ...$journalIds]) as $row) $owned['dim_line'][(int) $row['id']] = true;
        }
        $documents = [];
        foreach (['invoice', 'purchase_invoice', 'cash_document', 'asset', 'other_item', 'recurring_template', 'journal_template'] as $docType) {
            $documents[$docType] = $owned[$docType] ?? [];
        }
        $documents['invoice'] += $owned['credit_note'] ?? [];
        $statementIds = array_keys($owned['bank_statement'] ?? []);
        if ($statementIds !== []) {
            foreach ($this->rows('SELECT bt.id FROM bank_transactions bt JOIN bank_statements bs ON bs.id=bt.statement_id WHERE bs.supplier_id=? AND bs.id IN (' . self::marks($statementIds) . ')', [$supplierId, ...$statementIds]) as $row) $documents['bank_transaction'][(int) $row['id']] = true;
        }
        $counts = ['dimension_types' => 0, 'dimension_values' => 0, 'dimension_documents' => 0, 'dimension_journal_lines' => 0, 'dimension_defaults' => 0, 'dimension_cost_centers' => 0];
        $roots = [];
        foreach (['dimension_values' => 'dimension_value', 'dimension_types' => 'dimension_type', 'cost_centers' => 'dimension_cost_center', 'dimension_defaults' => 'dim_default'] as $table => $type) {
            $ids = array_keys($owned[$type] ?? []);
            $roots[$table] = [];
            if ($ids === []) continue;
            foreach ($this->rows('SELECT * FROM `' . $table . '` WHERE id IN (' . self::marks($ids) . ') FOR UPDATE', $ids) as $row) {
                $local = (int) ($row['supplier_id'] ?? 0) === $supplierId;
                $global = $row['supplier_id'] === null && ($row['supplier_group_id'] ?? null) !== null && (int) $row['supplier_group_id'] === (int) ($supplier['supplier_group_id'] ?? 0);
                if (!$local && !$global) self::blocked($table);
                $roots[$table][(int) $row['id']] = $row;
            }
        }
        foreach ($roots['dimension_defaults'] as $row) {
            if (!isset($roots['dimension_values'][(int) $row['dimension_value_id']])) self::blocked('dimension_defaults');
            if (!isset($owned['project'][(int) ($row['project_id'] ?? 0)])) self::blocked('dimension_defaults.project_id');
        }
        $references = $this->rows('SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_COLUMN_NAME="id" AND REFERENCED_TABLE_NAME IN ("dimension_values", "dimension_types", "cost_centers")', []);
        foreach ($references as $ref) {
            $parent = $ref['REFERENCED_TABLE_NAME'];
            $ids = array_keys($roots[$parent]);
            if ($ids === []) continue;
            $table = $ref['TABLE_NAME'];
            $column = $ref['COLUMN_NAME'];
            foreach ($this->rows('SELECT * FROM `' . $table . '` WHERE `' . $column . '` IN (' . self::marks($ids) . ') FOR UPDATE', $ids) as $row) {
                $allowed = match ($table) {
                    'dimension_values' => isset($roots['dimension_values'][(int) $row['id']]),
                    'dimension_defaults' => isset($roots['dimension_defaults'][(int) $row['id']]),
                    'journal_entry_line_dimensions', 'journal_entry_line_dimension_splits' => (int) $row['supplier_id'] === $supplierId && isset($owned['dim_line'][(int) $row['line_id']]),
                    'document_dimensions', 'document_dimension_splits' => (int) $row['supplier_id'] === $supplierId && isset($documents[$row['doc_type']][(int) $row['doc_id']]),
                    default => false,
                };
                if (!$allowed) self::blocked($table);
            }
        }
        foreach ($roots['cost_centers'] as $center) {
            foreach ($this->rows('SELECT id FROM journal_entry_lines WHERE supplier_id=? AND cost_center=?', [$supplierId, $center['code']]) as $line) {
                if (!isset($owned['dim_line'][(int) $line['id']])) self::blocked('journal_entry_lines');
            }
            if ($this->rows('SELECT l.id FROM journal_entry_template_lines l JOIN journal_entry_templates t ON t.id=l.template_id WHERE t.supplier_id=? AND l.cost_center=? LIMIT 1', [$supplierId, $center['code']]) !== []) self::blocked('journal_entry_template_lines');
        }
        $restore = array_keys($owned['dim_restore_enabled'] ?? []);
        if (count($restore) > 1 || ($restore !== [] && !in_array($restore[0], [1, 2], true))) self::blocked('dimensions_enabled');

        $valueIds = array_keys($roots['dimension_values']);
        if ($valueIds !== []) {
            foreach ($documents as $docType => $ids) {
                $docIds = array_keys($ids);
                if ($docIds === []) continue;
                foreach (['document_dimensions', 'document_dimension_splits'] as $table) {
                    $stmt = $pdo->prepare('DELETE FROM `' . $table . '` WHERE supplier_id=? AND doc_type=? AND doc_id IN (' . self::marks($docIds) . ') AND dimension_value_id IN (' . self::marks($valueIds) . ')');
                    $stmt->execute([$supplierId, $docType, ...$docIds, ...$valueIds]);
                    $counts['dimension_documents'] += $stmt->rowCount();
                }
            }
            $lineIds = array_keys($owned['dim_line'] ?? []);
            if ($lineIds !== []) {
                foreach (['journal_entry_line_dimensions', 'journal_entry_line_dimension_splits'] as $table) {
                    $stmt = $pdo->prepare('DELETE FROM `' . $table . '` WHERE supplier_id=? AND line_id IN (' . self::marks($lineIds) . ') AND dimension_value_id IN (' . self::marks($valueIds) . ')');
                    $stmt->execute([$supplierId, ...$lineIds, ...$valueIds]);
                    $counts['dimension_journal_lines'] += $stmt->rowCount();
                }
            }
        }
        foreach (array_keys($roots['dimension_defaults']) as $id) {
            $stmt = $pdo->prepare('DELETE FROM dimension_defaults WHERE supplier_id=? AND id=?');
            $stmt->execute([$supplierId, $id]);
            $counts['dimension_defaults'] += $stmt->rowCount();
        }
        $remaining = $roots['dimension_values'];
        while ($remaining !== []) {
            $progress = false;
            foreach ($remaining as $id => $row) {
                if (array_filter($remaining, static fn (array $child): bool => (int) ($child['parent_id'] ?? 0) === $id) !== []) continue;
                if (!$this->repo->deleteValue($supplierId, $id)) self::blocked('dimension_values');
                unset($remaining[$id]);
                $counts['dimension_values']++;
                $progress = true;
            }
            if (!$progress) self::blocked('dimension_values.parent_id');
        }
        foreach (array_keys($roots['dimension_types']) as $id) {
            if (!$this->repo->deleteType($supplierId, $id)) self::blocked('dimension_types');
            $counts['dimension_types']++;
        }
        foreach (array_keys($roots['cost_centers']) as $id) {
            if (!$this->costCenters->delete($supplierId, $id)) self::blocked('cost_centers');
            $counts['dimension_cost_centers']++;
        }
        if ($restore !== []) $this->repo->setEnabled($supplierId, $restore[0] === 2);
        return $counts;
    }

    private static function marks(array $ids): string
    {
        return implode(',', array_fill(0, count($ids), '?'));
    }

    private static function blocked(string $table): never
    {
        throw new \DomainException('Ukázkové dimenze jsou propojené s neevidovanými daty (' . $table . '); odebrání bylo zastaveno.');
    }

    private function rows(string $sql, array $params): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
