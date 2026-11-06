<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Nonprofit\Membership;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use PDO;

final class MembershipBalanceRepository
{
    public function __construct(private readonly Connection $db) {}

    public function claimsForMember(int $supplier, int $member, int $afterId = 0, int $limit = 100): array
    {
        $limit = max(1, min(101, $limit));
        $q = $this->db->pdo()->prepare(
            "SELECT c.id claim_id,c.case_id,c.operation_id,c.member_id,c.client_id,c.amount_cents,
                    r.id reversal_claim_id,o.accounting_date,o.due_date,o.journal_entry_id,o.receivable_line_id,
                    l.line_no,l.account_id,e.posted_at,e.reversed_by,cc.title_key,cc.period_from,cc.period_to
               FROM nonprofit_membership_charge_claims c FORCE INDEX(ix_nmclaim_member)
               JOIN nonprofit_membership_charge_operations o
                 ON o.supplier_id=c.supplier_id AND o.id=c.operation_id AND o.operation='issue'
               JOIN nonprofit_membership_charge_cases cc
                 ON cc.supplier_id=c.supplier_id AND cc.id=c.case_id
               JOIN journal_entry_lines l
                 ON l.supplier_id=o.supplier_id AND l.id=o.receivable_line_id AND l.entry_id=o.journal_entry_id
               JOIN journal_entries e
                 ON e.supplier_id=o.supplier_id AND e.id=o.journal_entry_id
          LEFT JOIN nonprofit_membership_charge_claims r
                 ON r.supplier_id=c.supplier_id AND r.original_claim_id=c.id AND r.kind='reversal'
              WHERE c.supplier_id=? AND c.member_id=? AND c.kind='issue' AND c.id>?
              ORDER BY c.id
              LIMIT {$limit}"
        );
        $q->execute([$supplier, $member, $afterId]);
        return array_map($this->claimRow(...), $q->fetchAll(PDO::FETCH_ASSOC));
    }

    public function claim(int $supplier, int $claim): ?array
    {
        $q = $this->db->pdo()->prepare(
            "SELECT c.id claim_id,c.case_id,c.operation_id,c.member_id,c.client_id,c.amount_cents,
                    r.id reversal_claim_id,o.accounting_date,o.due_date,o.journal_entry_id,o.receivable_line_id,
                    l.line_no,l.account_id,e.posted_at,e.reversed_by,cc.title_key,cc.period_from,cc.period_to
               FROM nonprofit_membership_charge_claims c
               JOIN nonprofit_membership_charge_operations o
                 ON o.supplier_id=c.supplier_id AND o.id=c.operation_id AND o.operation='issue'
               JOIN nonprofit_membership_charge_cases cc
                 ON cc.supplier_id=c.supplier_id AND cc.id=c.case_id
               JOIN journal_entry_lines l
                 ON l.supplier_id=o.supplier_id AND l.id=o.receivable_line_id AND l.entry_id=o.journal_entry_id
               JOIN journal_entries e
                 ON e.supplier_id=o.supplier_id AND e.id=o.journal_entry_id
          LEFT JOIN nonprofit_membership_charge_claims r
                 ON r.supplier_id=c.supplier_id AND r.original_claim_id=c.id AND r.kind='reversal'
              WHERE c.supplier_id=? AND c.id=? AND c.kind='issue'"
        );
        $q->execute([$supplier, $claim]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->claimRow($row);
    }

    public function pairingEvidence(int $supplier, array $claims): array
    {
        if ($claims === []) return [];
        $entryIds = array_values(array_unique(array_column($claims, 'journal_entry_id')));
        $in = implode(',', array_fill(0, count($entryIds), '?'));
        $q = $this->db->pdo()->prepare(
            "SELECT i.entry_id,i.line_no,i.account_id,i.pairing_id
               FROM journal_line_pairing_items i
              WHERE i.supplier_id=? AND i.entry_id IN ({$in})
                AND EXISTS(SELECT 1 FROM journal_line_pairing_items other
                            WHERE other.supplier_id=i.supplier_id AND other.pairing_id=i.pairing_id
                              AND (other.entry_id<>i.entry_id OR other.line_no<>i.line_no))"
        );
        $q->execute([$supplier, ...$entryIds]);
        $claimByRef = [];
        foreach ($claims as $claim) $claimByRef[$claim['journal_entry_id'] . ':' . $claim['line_no'] . ':' . $claim['account_id']] = $claim['claim_id'];
        $pairByClaim = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = $row['entry_id'] . ':' . $row['line_no'] . ':' . $row['account_id'];
            if (isset($claimByRef[$key])) $pairByClaim[$claimByRef[$key]] = (int) $row['pairing_id'];
        }
        if ($pairByClaim === []) return [];
        $claimsById = [];
        foreach ($claims as $claim) $claimsById[$claim['claim_id']] = $claim;
        $requested = [];
        $params = [];
        foreach ($pairByClaim as $claim => $pair) {
            $requested[] = 'SELECT ? claim_id,? pairing_id,? account_id';
            array_push($params, $claim, $pair, $claimsById[$claim]['account_id']);
        }
        $q = $this->db->pdo()->prepare(
            'WITH requested AS (' . implode(' UNION ALL ', $requested) . ")
             SELECT r.claim_id,r.pairing_id,COUNT(*) item_count,
                    SUM(l.id IS NULL OR e.posted_at IS NULL OR e.reversed_by IS NOT NULL OR l.is_red_storno<>0
                        OR l.currency_code IS NOT NULL OR l.amount_foreign IS NOT NULL OR l.fx_rate IS NOT NULL
                        OR l.account_id<>r.account_id
                        OR (mo.id IS NULL AND NOT ((e.source_type='bank' AND bs.id IS NOT NULL)
                                                   OR (e.source_type='cash' AND cd.id IS NOT NULL)))) invalid_count,
                    SUM(mo.id IS NOT NULL) membership_claim_count,
                    COALESCE(SUM(CASE WHEN l.id IS NULL THEN 0 WHEN l.side='debit' THEN ROUND(l.signed_amount*100) ELSE -ROUND(l.signed_amount*100) END),0) net_cents
               FROM requested r
               JOIN journal_line_pairing_items i ON i.supplier_id=? AND i.pairing_id=r.pairing_id
               JOIN journal_entries e ON e.supplier_id=i.supplier_id AND e.id=i.entry_id
          LEFT JOIN journal_entry_lines l ON l.supplier_id=i.supplier_id AND l.entry_id=i.entry_id
                    AND l.line_no=i.line_no AND l.account_id=i.account_id
          LEFT JOIN nonprofit_membership_charge_operations mo
                 ON mo.supplier_id=l.supplier_id AND mo.receivable_line_id=l.id AND mo.operation='issue'
          LEFT JOIN bank_transactions bt ON e.source_type='bank' AND bt.id=e.source_id
          LEFT JOIN bank_statements bs ON bs.id=bt.statement_id
                    AND " . BankStatementOwnershipResolver::sqlForColumn('i.supplier_id') . "
          LEFT JOIN cash_documents cd ON e.source_type='cash' AND cd.id=e.source_id AND cd.supplier_id=i.supplier_id
              GROUP BY r.claim_id,r.pairing_id
              ORDER BY r.claim_id"
        );
        $q->execute([...$params, $supplier]);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['claim_id']] = ['pairing_id' => (int) $row['pairing_id'], 'item_count' => (int) $row['item_count'],
                'invalid_count' => (int) $row['invalid_count'], 'membership_claim_count' => (int) $row['membership_claim_count'],
                'net_cents' => (int) $row['net_cents']];
        }
        return $out;
    }

    public function reconciliationTotals(int $supplier, int $period): array
    {
        $claim = $this->db->pdo()->prepare(
            "SELECT COUNT(*) row_count,
                    COALESCE(SUM(CASE WHEN c.kind='issue' THEN c.amount_cents ELSE -c.amount_cents END),0) cents
               FROM nonprofit_membership_charge_claims c
               JOIN nonprofit_membership_charge_operations o
                 ON o.supplier_id=c.supplier_id AND o.id=c.operation_id
              WHERE c.supplier_id=? AND o.accounting_period_id=?"
        );
        $claim->execute([$supplier, $period]);
        $operation = $this->db->pdo()->prepare(
            "SELECT COUNT(*) row_count,
                    COALESCE(SUM(CASE WHEN operation='issue' THEN amount_cents ELSE -amount_cents END),0) cents
               FROM nonprofit_membership_charge_operations
              WHERE supplier_id=? AND accounting_period_id=?"
        );
        $operation->execute([$supplier, $period]);
        $ledger = $this->db->pdo()->prepare(
            "SELECT COUNT(*) operation_count,
                    COALESCE(SUM(CASE WHEN o.operation='issue' AND rl.side='debit' THEN ROUND(rl.signed_amount*100)
                                      WHEN o.operation='reverse' AND rl.side='credit' THEN -ROUND(rl.signed_amount*100) END),0) receivable_cents,
                    COALESCE(SUM(CASE WHEN o.operation='issue' AND vl.side='credit' THEN ROUND(vl.signed_amount*100)
                                      WHEN o.operation='reverse' AND vl.side='debit' THEN -ROUND(vl.signed_amount*100) END),0) revenue_cents,
                    SUM(e.posted_at IS NULL OR rl.id IS NULL OR vl.id IS NULL
                        OR rl.currency_code IS NOT NULL OR vl.currency_code IS NOT NULL
                        OR rl.amount_foreign IS NOT NULL OR vl.amount_foreign IS NOT NULL
                        OR rl.fx_rate IS NOT NULL OR vl.fx_rate IS NOT NULL
                        OR rl.is_red_storno<>0 OR vl.is_red_storno<>0
                        OR rl.amount<>vl.amount
                        OR (o.operation='issue' AND (rl.side<>'debit' OR vl.side<>'credit'))
                        OR (o.operation='reverse' AND (rl.side<>'credit' OR vl.side<>'debit'))) invalid_count
               FROM nonprofit_membership_charge_operations o
               JOIN journal_entries e ON e.supplier_id=o.supplier_id AND e.id=o.journal_entry_id
          LEFT JOIN journal_entry_lines rl ON rl.supplier_id=o.supplier_id AND rl.id=o.receivable_line_id AND rl.entry_id=o.journal_entry_id
          LEFT JOIN journal_entry_lines vl ON vl.supplier_id=o.supplier_id AND vl.id=o.revenue_line_id AND vl.entry_id=o.journal_entry_id
              WHERE o.supplier_id=? AND o.accounting_period_id=?"
        );
        $ledger->execute([$supplier, $period]);
        return ['claims' => $claim->fetch(PDO::FETCH_ASSOC), 'operations' => $operation->fetch(PDO::FETCH_ASSOC), 'ledger' => $ledger->fetch(PDO::FETCH_ASSOC)];
    }

    private function claimRow(array $row): array
    {
        foreach (['claim_id', 'case_id', 'operation_id', 'member_id', 'client_id', 'amount_cents', 'journal_entry_id', 'receivable_line_id', 'line_no', 'account_id'] as $field) $row[$field] = (int) $row[$field];
        $row['reversal_claim_id'] = $row['reversal_claim_id'] === null ? null : (int) $row['reversal_claim_id'];
        $row['reversed_by'] = $row['reversed_by'] === null ? null : (int) $row['reversed_by'];
        return $row;
    }
}
