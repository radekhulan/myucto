<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Myucto;

use MyInvoice\Repository\MovementClassificationRepository;
use RuntimeException;

/** Validation of stored classifications and rules, without accounting side effects. */
final class MyuctoAccountingGraph
{
    public static function movementTable(mixed $type): string
    {
        return match ($type) {
            'bank' => 'bank_transactions',
            'cash' => 'cash_documents',
            default => throw new RuntimeException('Neplatný typ pohybu v klasifikaci nebo její historii.'),
        };
    }

    public static function validate(array $tables): void
    {
        $accounts = array_column(array_values($tables['chart_of_accounts'] ?? []), null, 'account_code');
        foreach ($tables['posting_rules'] ?? [] as $row) {
            foreach (['rule_key', 'description', 'debit_account_code', 'credit_account_code', 'priority', 'is_active', 'created_at'] as $column) {
                if (!array_key_exists($column, $row)) {
                    throw new RuntimeException('Neúplná předkontace v exportu: ' . $column . '.');
                }
            }
            foreach (['debit_account_code', 'credit_account_code'] as $column) {
                if ($row[$column] !== null && !isset($accounts[$row[$column]])) {
                    throw new RuntimeException('Předkontace odkazuje na chybějící účet: ' . $column . '.');
                }
            }
        }
        foreach (['de_movement_classification', 'de_movement_classification_history'] as $table) {
            foreach ($tables[$table] ?? [] as $row) {
                $columns = $table === 'de_movement_classification'
                    ? ['classified_by', 'classified_at', 'updated_at', 'note']
                    : ['changed_by', 'changed_at'];
                foreach ($columns as $column) {
                    if (!array_key_exists($column, $row)) {
                        throw new RuntimeException('Neúplná klasifikace nebo její historie: ' . $table . '.' . $column . '.');
                    }
                }
                $parent = self::movementTable($row['source_type'] ?? null);
                if ($table === 'de_movement_classification') {
                    $column = $parent === 'bank_transactions' ? 'bank_transaction_id' : 'cash_document_id';
                    $other = $parent === 'bank_transactions' ? 'cash_document_id' : 'bank_transaction_id';
                    if (!array_key_exists($other, $row) || $row[$other] !== null) {
                        throw new RuntimeException('Klasifikace musí odkazovat právě na jeden odpovídající pohyb.');
                    }
                    $buckets = ['tax_bucket'];
                } else {
                    $column = 'source_id';
                    $buckets = ['previous_tax_bucket', 'new_tax_bucket'];
                }
                $id = MyuctoExportReader::id($row[$column] ?? null);
                if (!isset($tables[$parent][$id])) {
                    throw new RuntimeException('Chybí zdrojový pohyb klasifikace nebo její historie.');
                }
                foreach ($buckets as $bucket) {
                    if (!array_key_exists($bucket, $row)
                        || ($row[$bucket] === null && $table === 'de_movement_classification')
                        || ($row[$bucket] !== null && !in_array($row[$bucket], MovementClassificationRepository::TAX_BUCKETS, true))) {
                        throw new RuntimeException('Neplatná daňová klasifikace: ' . $table . '.' . $bucket . '.');
                    }
                }
            }
        }
    }
}
