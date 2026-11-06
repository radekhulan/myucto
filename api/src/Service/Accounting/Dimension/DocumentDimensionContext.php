<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Dimension;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use PDO;

final class DocumentDimensionContext
{
    public function __construct(private readonly Connection $db) {}

    public function lockCurrent(int $supplierId, string $type, int $id): array
    {
        (new SystemDimensionWritePolicy($this->db))->lockContext($supplierId);
        $definition = match ($type) {
            'invoice' => ['invoices', 'COALESCE(tax_date, issue_date)', 'invoice_items', 'invoice_id', 'order_index'],
            'purchase_invoice' => ['purchase_invoices', 'COALESCE(tax_date, issue_date)', 'purchase_invoice_items', 'purchase_invoice_id', 'order_index'],
            'cash_document' => ['cash_documents', 'issue_date'],
            'other_item' => ['other_items', "COALESCE(NULLIF(accounting_on, ''), issued_on)"],
            'asset' => ['assets', 'put_into_use_date'],
            'journal_template' => ['journal_entry_templates', 'NULL', 'journal_entry_template_lines', 'template_id', 'line_no'],
            'recurring_template' => ['recurring_invoice_templates', 'NULL', 'recurring_invoice_template_items', 'template_id', 'order_index'],
            'bank_transaction' => null,
            default => throw new DimensionException('not_found', 'Neznámý typ dokladu.', 404),
        };
        if ($type === 'bank_transaction') {
            $stmt = $this->db->pdo()->prepare('SELECT bt.id, DATE(bt.posted_at) AS accounting_date FROM bank_transactions bt JOIN bank_statements bs ON bs.id = bt.statement_id WHERE bt.id = ? AND ' . BankStatementOwnershipResolver::sql(currentRead: true) . ' FOR UPDATE');
            $stmt->execute([$id, ...BankStatementOwnershipResolver::params($supplierId)]);
        } else {
            [$table, $date] = $definition;
            $stmt = $this->db->pdo()->prepare("SELECT id, {$date} AS accounting_date FROM {$table} WHERE supplier_id = ? AND id = ? FOR UPDATE");
            $stmt->execute([$supplierId, $id]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) throw new DimensionException('not_found', 'Doklad nenalezen.', 404);
        $items = [];
        if (isset($definition[2])) {
            [, , $table, $parent, $order] = $definition;
            $stmt = $this->db->pdo()->prepare("SELECT id FROM {$table} WHERE {$parent} = ? ORDER BY {$order}, id FOR UPDATE");
            $stmt->execute([$id]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $index => $itemId) $items[$index + 1] = (int) $itemId;
        }
        return ['id' => $id, 'date' => $row['accounting_date'] ?: null, 'items' => $items];
    }

    public static function identify(array $lines, array $context, array $header, array $items, array $splits): array
    {
        foreach ($lines as &$line) {
            $number = (int) $line['line_no'];
            $line['id'] = $number === 0 ? -$context['id'] : ($context['items'][$number] ?? null);
            $owners = [];
            foreach (array_keys($header + ($splits[0] ?? [])) as $type) $owners[$type] = 'header';
            if ($number > 0) foreach (array_keys(($items[$number] ?? []) + ($splits[$number] ?? [])) as $type) $owners[$type] = 'item';
            $line['document_assignment_owners'] = $owners;
        }
        unset($line);
        return $lines;
    }
}
