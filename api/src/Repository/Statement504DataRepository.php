<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;
use MyInvoice\Service\Accounting\Dimension\DimensionFilter;
use MyInvoice\Service\Accounting\Dimension\DimensionSplitAllocation;
use MyInvoice\Service\Accounting\Reports\ReportException;
use MyInvoice\Service\Tax\Return\JournalTaxOrigin;
use PDO;

final class Statement504DataRepository
{
    public function __construct(private readonly Connection $db, private readonly LedgerReportRepository $ledger) {}

    public function balances(int $supplierId, string $startsOn, string $asOf, ?DimensionFilter $dimension = null): array
    {
        $accountDefinitions=AccountDefinitionRepository::relation($asOf,$supplierId);
        $anchor = $this->ledger->openingAnchor($supplierId, $startsOn);
        [$source, $params] = $dimension?->lineSource($supplierId, 'l', $anchor, $asOf) ?? ['journal_entry_lines l', []];
        $origin = JournalTaxOrigin::sourceTypeSql();
        $stmt = $this->db->pdo()->prepare('WITH RECURSIVE ' . JournalTaxOrigin::cte($supplierId) . "
            SELECT a.id, a.account_code AS code, a.name, a.account_type,
                CAST(SUM(CASE WHEN e.entry_date < ? OR (e.entry_date = ? AND {$origin} = 'opening')
                    THEN CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END ELSE 0 END) * 100 AS SIGNED) AS opening_cents,
                CAST(SUM(CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END) * 100 AS SIGNED) AS closing_cents
            FROM {$source} JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
            " . JournalTaxOrigin::join() . "
            JOIN {$accountDefinitions} a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
            WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL AND e.entry_date <= ?
                AND (? IS NULL OR e.entry_date >= ?) AND a.account_type IN ('asset','liability','equity')
                AND " . JournalTaxOrigin::includedSql() . '
            GROUP BY a.id ORDER BY a.account_code');
        $stmt->execute([$startsOn, $startsOn, ...$params, $supplierId, $asOf, $anchor, $anchor, ClosingSourceId::STOCK_SLOT_BASE]);
        return array_map(static function (array $r): array {
            $r['opening_cents'] = (int) $r['opening_cents'];
            $r['closing_cents'] = (int) $r['closing_cents'];
            return $r;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function profitLines(int $supplierId, string $from, string $to, ?DimensionFilter $dimension = null): array
    {
        $accountDefinitions=AccountDefinitionRepository::relation($to,$supplierId);
        $stmt = $this->db->pdo()->prepare("SELECT id FROM dimension_types WHERE supplier_id = ? AND system_key = 'activity'");
        $stmt->execute([$supplierId]);
        $typeId = (int) $stmt->fetchColumn();
        $stmt = $this->db->pdo()->prepare('WITH RECURSIVE ' . JournalTaxOrigin::cte($supplierId) . "
            SELECT l.id, a.account_code AS code, a.account_type, l.side, l.is_red_storno,
                CAST(l.amount * 100 AS SIGNED) AS amount_cents,
                CAST((CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END) * 100 AS SIGNED) AS balance_cents,
                v.id AS value_id, v.semantic_key AS activity,
                COALESCE(sp.split_count, 0) AS split_count, sp.split_share
            FROM journal_entry_lines l JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
            " . JournalTaxOrigin::join() . "
            JOIN {$accountDefinitions} a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
            LEFT JOIN journal_entry_line_dimensions d ON d.line_id = l.id AND d.dimension_type_id = ? AND d.supplier_id = l.supplier_id
            LEFT JOIN dimension_values v ON v.id = d.dimension_value_id AND v.type_id = d.dimension_type_id AND v.supplier_id = l.supplier_id
            LEFT JOIN (
                SELECT s.line_id, COUNT(*) AS split_count, SUM(s.share) AS split_share
                  FROM journal_entries se
                  JOIN journal_entry_lines sl ON sl.supplier_id = se.supplier_id AND sl.entry_id = se.id
                  JOIN journal_entry_line_dimension_splits s ON s.line_id = sl.id AND s.dimension_type_id = ?
                 WHERE se.supplier_id = ? AND se.posted_at IS NOT NULL AND se.entry_date >= ? AND se.entry_date <= ?
                 GROUP BY s.line_id
            ) sp ON sp.line_id = l.id
            WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL AND e.entry_date >= ? AND e.entry_date <= ?
                AND (a.account_type IN ('expense','revenue') OR a.account_code REGEXP '^[56]') AND " . JournalTaxOrigin::includedSql() . ' ORDER BY l.id');
        $stmt->execute([
            $typeId,
            $typeId, $supplierId, $from, $to,
            $supplierId, $from, $to,
            ClosingSourceId::STOCK_SLOT_BASE,
        ]);
        $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $parts = [];
        if (array_filter($lines, static fn (array $l): bool => (int) $l['split_count'] > 0) !== []) {
            [$sql, $params] = DimensionSplitAllocation::partsSql($typeId, null, $supplierId, [$from, $to]);
            $stmt = $this->db->pdo()->prepare("SELECT p.line_id,p.value_id,CAST(p.amount * 100 AS SIGNED) AS cents,v.semantic_key
                FROM ({$sql}) p LEFT JOIN dimension_values v ON v.id=p.value_id AND v.type_id=? AND v.supplier_id=?");
            $stmt->execute([...$params, $typeId, $supplierId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $part) $parts[(int) $part['line_id']][] = $part;
        }
        // Podmínku na činnost rozhoduje činnost řádku (i v rozpadu), ostatní typy jdou přes filtr řádků.
        $activityFilter = $dimension?->criterion($typeId);
        $otherFilter = $dimension?->without($typeId);
        $filtered = null;
        $foreignSplits = [];
        if ($otherFilter !== null) {
            $otherTypes = array_map(static fn (DimensionFilter $c): int => $c->typeId, $otherFilter->criteria());
            $typeMarks = implode(',', array_fill(0, count($otherTypes), '?'));
            [$sql, $params] = $otherFilter->lineSource($supplierId, 'f', $from, $to);
            $stmt = $this->db->pdo()->prepare("SELECT f.id,CAST(SUM(f.signed_amount) * 100 AS SIGNED) AS cents,
                EXISTS(SELECT 1 FROM journal_entry_line_dimension_splits s WHERE s.line_id=f.id AND s.dimension_type_id IN ({$typeMarks})) AS has_split
                FROM {$sql}
                JOIN journal_entries fe ON fe.id = f.entry_id AND fe.supplier_id = f.supplier_id
                WHERE fe.supplier_id = ? AND fe.entry_date >= ? AND fe.entry_date <= ?
                GROUP BY f.id");
            $stmt->execute([...$otherTypes, ...$params, $supplierId, $from, $to]);
            $filtered = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $filtered[(int) $row['id']] = (int) $row['cents'];
                $foreignSplits[(int) $row['id']] = (bool) $row['has_split'];
            }
        }
        $out = [];
        foreach ($lines as $line) {
            $id = (int) $line['id'];
            if (($line['code'][0] === '5' && $line['account_type'] !== 'expense')
                || ($line['code'][0] === '6' && $line['account_type'] !== 'revenue')
                || !in_array($line['code'][0], ['5', '6'], true)) {
                throw new ReportException('statement_account_type_invalid', 'Typ zaúčtovaného účtu neodpovídá účtové třídě vyhlášky 504.');
            }
            if ((int) $line['split_count'] === 0) {
                $this->assertActivity($line['activity']);
                if ($activityFilter !== null && !in_array((int) $line['value_id'], $activityFilter->valueIds, true)) continue;
                if ($filtered !== null && !array_key_exists($id, $filtered)) continue;
                $amount = $filtered === null ? (int) $line['balance_cents'] : (int) $filtered[$id] * ($line['side'] === 'debit' ? 1 : -1);
                $out[] = ['code' => $line['code'], 'activity' => $line['activity'], 'balance_cents' => $amount];
                continue;
            }
            if ($line['value_id'] !== null || (float) $line['split_share'] !== 1.0) {
                throw new ReportException('statement_activity_invalid', 'Rozpad zaúčtované činnosti je nejednoznačný nebo neúplný.');
            }
            $allocated = $parts[$id] ?? [];
            if (array_sum(array_column($allocated, 'cents')) !== (int) $line['amount_cents']) {
                throw new ReportException('statement_activity_invalid', 'Rozpad zaúčtované činnosti nesouhlasí s částkou řádku.');
            }
            foreach ($allocated as $part) $this->assertActivity($part['semantic_key']);
            if ($filtered !== null && !array_key_exists($id, $filtered)) continue;
            if ($filtered !== null && ($foreignSplits[$id] || abs((int) $filtered[$id]) !== (int) $line['amount_cents'])) {
                throw new ReportException('statement_joint_allocation_required', 'Vybraná dimenze a činnost vyžadují společnou alokaci.');
            }
            foreach ($allocated as $part) {
                if ($activityFilter !== null && !in_array((int) $part['value_id'], $activityFilter->valueIds, true)) continue;
                $out[] = ['code' => $line['code'], 'activity' => $part['semantic_key'], 'balance_cents' => (int) $part['cents']
                    * ($line['side'] === 'debit' ? 1 : -1) * ((int) $line['is_red_storno'] === 1 ? -1 : 1)];
            }
        }
        return $out;
    }

    private function assertActivity(?string $activity): void
    {
        if (!in_array($activity, ['main', 'economic'], true)) {
            throw new ReportException('statement_activity_invalid', 'Zařazení činnosti na zaúčtovaném řádku chybí nebo není platné.');
        }
    }
}
