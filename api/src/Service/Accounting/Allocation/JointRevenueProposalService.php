<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Allocation;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountDefinitionRepository;
use MyInvoice\Repository\AccountingFrameworkRepository;
use MyInvoice\Service\Accounting\AccountingFrameworkService;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;
use MyInvoice\Service\Tax\Return\JournalTaxOrigin;
use PDO;

final class JointRevenueProposalService
{
    public function __construct(private readonly Connection $db) {}

    public function build(int $supplierId, mixed $from, mixed $to): array
    {
        $from = JointAllocationKeyVersion::date($from);
        $to = JointAllocationKeyVersion::date($to);
        if ($from > $to) {
            throw new JointAllocationException('allocation_invalid_interval', 'Konec zdrojového období nesmí předcházet začátku.');
        }
        $framework = new AccountingFrameworkService($this->db, new AccountingFrameworkRepository($this->db));
        foreach ($framework->forInterval($supplierId, $from, $to) as $value) {
            if ($value !== AccountingFrameworkService::CZ_504) {
                throw new JointAllocationException('allocation_framework_required', 'Návrh vyžaduje účetní rámec 504 v celém zdrojovém období.');
            }
        }
        $stmt = $this->db->pdo()->prepare("SELECT system_key, id FROM dimension_types WHERE supplier_id = ? AND is_active = 1 AND system_key IN ('activity','activity_kind','tax_regime')");
        $stmt->execute([$supplierId]);
        $types = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $result = [
            'basis' => 'revenue', 'date_from' => $from, 'date_to' => $to,
            'net_revenue_cents' => '0', 'source_lines' => 0, 'unclassified_lines' => 0,
            'unclassified_amount_cents' => '0', 'cells' => [], 'can_propose' => false,
            'reason_codes' => [], 'verification_scope' => 'posted_revenue_aggregates',
            'requires_manual_confirmation' => true, 'mutations_performed' => false,
        ];
        if (count($types) !== 3) {
            $result['reason_codes'][] = 'system_dimensions_missing';
        } else {
            $stmt = $this->db->pdo()->prepare($this->query($supplierId, $types));
            $stmt->execute([$supplierId, $from, $to, ClosingSourceId::STOCK_SLOT_BASE]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($rows !== []) {
                $first = $rows[0];
                $result['net_revenue_cents'] = bcadd($first['total_cents'], '0', 0);
                $result['source_lines'] = (int) $first['total_lines'];
                $result['unclassified_lines'] = (int) $first['missing_lines'];
                $result['unclassified_amount_cents'] = bcadd($first['missing_cents'], '0', 0);
                if ((int) $first['missing_lines'] > 0) $result['reason_codes'][] = 'unclassified_revenue';
                if ((int) $first['split_lines'] > 0) $result['reason_codes'][] = 'split_dimensions';
                if ((int) $first['negative_cells'] > 0) $result['reason_codes'][] = 'negative_cell';
                if ((int) $first['positive_cells'] > JointAllocationCalculator::MAX_CELLS) $result['reason_codes'][] = 'too_many_cells';
                foreach ($rows as $row) {
                    if ((int) $row['missing'] !== 0 || (int) $row['has_split'] !== 0 || bccomp($row['cents'], '0', 0) <= 0) continue;
                    $result['cells'][] = [
                        'activity' => (int) $row['activity'], 'activity_kind' => (int) $row['activity_kind'],
                        'tax_regime' => (int) $row['tax_regime'], 'weight' => bcdiv($row['cents'], '100', 2),
                    ];
                }
            }
            if (bccomp($result['net_revenue_cents'], '0', 0) <= 0 || $result['cells'] === []) $result['reason_codes'][] = 'no_revenue';
            $result['can_propose'] = $result['reason_codes'] === [];
            $result['cells'] = $result['can_propose'] ? JointAllocationCalculator::cells($result['cells']) : [];
        }
        return $result + ['proposal_hash' => JointAllocationKeyVersion::hash(['supplier_id' => $supplierId] + $result)];
    }

    private function query(int $supplierId, array $types): string
    {
        $select = $joins = [];
        foreach (JointAllocationCalculator::AXES as $axis) {
            $type = (int) $types[$axis];
            $select[] = "v_$axis.id AS $axis";
            $joins[] = "LEFT JOIN journal_entry_line_dimensions d_$axis ON d_$axis.line_id = l.id AND d_$axis.supplier_id = e.supplier_id AND d_$axis.dimension_type_id = $type
                LEFT JOIN dimension_values v_$axis ON v_$axis.id = d_$axis.dimension_value_id AND v_$axis.type_id = $type AND v_$axis.supplier_id = e.supplier_id AND v_$axis.is_active = 1";
        }
        $typeIds = implode(',', array_map('intval', array_values($types)));
        $fields = implode(', ', $select);
        $join = implode("\n", $joins);
        $origin = JournalTaxOrigin::cte($supplierId);
        $originJoin = JournalTaxOrigin::join();
        $included = JournalTaxOrigin::includedSql();
        $accounts = AccountDefinitionRepository::journalJoin($supplierId);
        return "WITH RECURSIVE $origin,
            source_rows AS (
                SELECT $fields,
                    (CASE WHEN l.side = 'credit' THEN l.signed_amount ELSE -l.signed_amount END) * 100 AS cents,
                    EXISTS(SELECT 1 FROM journal_entry_line_dimension_splits sp WHERE sp.line_id = l.id AND sp.supplier_id = e.supplier_id AND sp.dimension_type_id IN ($typeIds)) AS has_split
                FROM journal_entries e FORCE INDEX (idx_je_supplier_entry_date)
                JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                $originJoin
                $accounts
                $join
                WHERE e.supplier_id = ? AND e.entry_date >= ? AND e.entry_date <= ? AND e.posted_at IS NOT NULL
                  AND a.account_type = 'revenue' AND $included
            ), grouped AS (
                SELECT activity, activity_kind, tax_regime, has_split,
                    (activity IS NULL OR activity_kind IS NULL OR tax_regime IS NULL) AS missing,
                    SUM(cents) AS cents, COUNT(*) AS line_count
                FROM source_rows GROUP BY activity, activity_kind, tax_regime, has_split
            )
            SELECT *, SUM(cents) OVER() AS total_cents, SUM(line_count) OVER() AS total_lines,
                SUM(IF(missing, line_count, 0)) OVER() AS missing_lines,
                SUM(IF(missing, cents, 0)) OVER() AS missing_cents,
                SUM(IF(has_split, line_count, 0)) OVER() AS split_lines,
                SUM(cents < 0) OVER() AS negative_cells,
                SUM(cents > 0 AND missing = 0 AND has_split = 0) OVER() AS positive_cells
            FROM grouped ORDER BY (cents > 0 AND missing = 0 AND has_split = 0) DESC, activity, activity_kind, tax_regime, has_split LIMIT 201";
    }
}
