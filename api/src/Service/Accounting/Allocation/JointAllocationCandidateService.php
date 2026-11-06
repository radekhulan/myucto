<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Allocation;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingFrameworkRepository;
use MyInvoice\Repository\DimensionAssignmentRepository;
use PDO;

final class JointAllocationCandidateService
{
    public function __construct(private readonly Connection $db) {}

    public function list(int $supplierId, array $filter): array
    {
        $date = JointAllocationKeyVersion::date($filter['target_date'] ?? null);
        $from = JointAllocationKeyVersion::date($filter['from'] ?? '1900-01-01');
        $to = JointAllocationKeyVersion::date($filter['to'] ?? $date);
        if ($from > $to) throw new JointAllocationException('allocation_invalid_interval', 'Začátek filtru nesmí následovat po konci.');
        $before = $filter['before_id'] ?? PHP_INT_MAX;
        if ((!is_int($before) && (!is_string($before) || !ctype_digit($before))) || (int) $before <= 0
            || !is_string($filter['search'] ?? '') || mb_strlen($filter['search'] ?? '') > 200) {
            throw new JointAllocationException('allocation_invalid_sources', 'Zadejte platný filtr zdrojových řádků.');
        }
        $before = (int) $before;
        $search = trim($filter['search'] ?? '');
        $stmt = $this->db->pdo()->prepare("SELECT l.*, e.entry_date AS source_date, e.source_type, e.source_id, e.description AS entry_description,
                e.document_no, e.posted_at, e.reversed_by, a.account_code, a.name AS account_name, a.account_type,
                c.batch_id AS claim_batch_id, b.id AS allocation_batch_id, x.id AS cancellation_id
            FROM journal_entry_lines l
            JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
            JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
            LEFT JOIN joint_allocation_source_claims c ON c.line_id = l.id AND c.supplier_id = l.supplier_id
            LEFT JOIN joint_allocation_batches b ON b.journal_entry_id = e.id AND b.supplier_id = l.supplier_id
            LEFT JOIN joint_allocation_cancellations x ON x.reversal_entry_id = e.id AND x.supplier_id = l.supplier_id
            WHERE l.supplier_id = ? AND l.id < ? AND e.entry_date BETWEEN ? AND ?
                AND (? = '' OR LOCATE(?, CONCAT_WS(' ', e.document_no, e.description, a.account_code, a.name)) > 0)
            ORDER BY l.id DESC LIMIT 500");
        $stmt->execute([$supplierId, $before, $from, $to, $search, $search]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $history = (new AccountingFrameworkRepository($this->db))->listForTenant($supplierId);
        $out = [];
        foreach ($rows as $line) {
            $framework = 'cz_500';
            foreach ($history as $version) {
                if ($version['effective_from'] > $line['source_date']) break;
                $framework = $version['accounting_framework'];
            }
            try {
                JointAllocationSourceEligibility::assert($line, $date, $framework);
                $line['id'] = (int) $line['id'];
                unset($line['claim_batch_id'], $line['allocation_batch_id'], $line['cancellation_id'], $line['posted_at'], $line['reversed_by']);
                $out[] = $line;
            } catch (JointAllocationException) {
                continue;
            }
        }
        $ids = array_column($out, 'id');
        $assignments = new DimensionAssignmentRepository($this->db);
        $dimensions = $assignments->lineDimensions($supplierId, $ids);
        $splits = $assignments->lineSplits($supplierId, $ids);
        foreach ($out as &$line) {
            $line['dimensions'] = $dimensions[$line['id']] ?? [];
            $line['dimension_splits'] = $splits[$line['id']] ?? [];
        }
        unset($line);
        return ['items' => $out, 'next_before_id' => count($rows) === 500 ? (int) $rows[array_key_last($rows)]['id'] : null];
    }
}
