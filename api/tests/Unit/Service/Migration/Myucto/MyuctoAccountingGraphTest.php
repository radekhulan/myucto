<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\Myucto;

use MyInvoice\Service\Migration\Myucto\MyuctoAccountingGraph;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MyuctoAccountingGraphTest extends TestCase
{
    public function testDeletionHistoryNeedsMovementButNotCurrentClassification(): void
    {
        MyuctoAccountingGraph::validate($this->tables());
        self::assertSame('cash_documents', MyuctoAccountingGraph::movementTable('cash'));
    }

    #[DataProvider('invalidRows')]
    public function testInvalidSourceGraphIsRejected(string $table, string $column, mixed $value): void
    {
        $tables = $this->tables();
        $tables[$table][1][$column] = $value;
        $this->expectException(RuntimeException::class);
        MyuctoAccountingGraph::validate($tables);
    }

    public static function invalidRows(): iterable
    {
        yield 'missing account' => ['posting_rules', 'debit_account_code', '999999'];
        yield 'both movement ids' => ['de_movement_classification', 'cash_document_id', 2];
        yield 'wrong movement type' => ['de_movement_classification', 'source_type', 'cash'];
        yield 'missing movement' => ['de_movement_classification', 'bank_transaction_id', 999];
        yield 'unknown bucket' => ['de_movement_classification', 'tax_bucket', 'unknown'];
        yield 'null current bucket' => ['de_movement_classification', 'tax_bucket', null];
        yield 'unknown history type' => ['de_movement_classification_history', 'source_type', 'invoice'];
        yield 'missing history movement' => ['de_movement_classification_history', 'source_id', 999];
        yield 'invalid previous bucket' => ['de_movement_classification_history', 'previous_tax_bucket', 'unknown'];
        yield 'invalid new bucket' => ['de_movement_classification_history', 'new_tax_bucket', 'unknown'];
    }

    private function tables(): array
    {
        return [
            'chart_of_accounts' => [1 => ['account_code' => '311990', 'is_active' => 0]],
            'posting_rules' => [1 => ['rule_key' => 'synthetic', 'description' => 'Synthetic rule',
                'debit_account_code' => '311990', 'credit_account_code' => null,
                'priority' => 17, 'is_active' => 0, 'created_at' => '2090-01-01 10:00:00']],
            'bank_transactions' => [1 => ['id' => 1]],
            'cash_documents' => [2 => ['id' => 2]],
            'de_movement_classification' => [1 => ['source_type' => 'bank',
                'bank_transaction_id' => 1, 'cash_document_id' => null, 'tax_bucket' => 'private',
                'classified_by' => null, 'classified_at' => '2090-01-01 10:00:00',
                'updated_at' => '2090-01-01 10:00:00', 'note' => null]],
            'de_movement_classification_history' => [1 => ['source_type' => 'cash',
                'source_id' => 2, 'previous_tax_bucket' => 'private', 'new_tax_bucket' => null,
                'changed_by' => null, 'changed_at' => '2090-01-01 10:00:00']],
        ];
    }
}
