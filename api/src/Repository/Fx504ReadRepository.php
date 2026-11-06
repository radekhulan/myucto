<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Closing\ClosingException;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;
use MyInvoice\Service\Accounting\Closing\Fx504Builder;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use PDO;

final class Fx504ReadRepository
{
    public function __construct(private readonly Connection $db) {}

    public function snapshot(int $supplierId, int $periodId, bool $currentRead = false): array
    {
        if ($currentRead) (new \MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy($this->db))->lockContext($supplierId);
        $lock = $currentRead ? ' FOR UPDATE' : '';
        $stmt = $this->db->pdo()->prepare('SELECT * FROM accounting_periods WHERE supplier_id = ? ORDER BY starts_on, id' . $lock);
        $stmt->execute([$supplierId]);
        $periods = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'id');
        $period = $periods[$periodId] ?? null;
        if ($period === null) throw new ClosingException('not_found', 'Účetní období neexistuje.', 404);
        $securitiesRead = new SecuritiesValuation504ReadRepository($this->db);
        $securitiesProfile = $securitiesRead->profileSnapshot($supplierId, $period, $currentRead);
        $stmt = $this->db->pdo()->prepare('SELECT id, period_id, entry_date, source_type, source_id, reversed_by, row_version
            FROM journal_entries WHERE supplier_id = ? AND posted_at IS NOT NULL AND entry_date <= ? ORDER BY id' . $lock);
        $stmt->execute([$supplierId, $period['ends_on']]);
        $headers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $excluded = self::excludedEntries($headers, $periodId);
        $excludedIds = array_keys($excluded);
        $excludedIds = $excludedIds === [] ? [0] : $excludedIds;
        $excludedMarks = implode(',', array_fill(0, count($excludedIds), '?'));
        $anchor = null;
        foreach ($headers as $header) {
            if ($header['source_type'] === 'opening' && $header['reversed_by'] === null) {
                $start = $periods[$header['period_id']]['starts_on'] ?? null;
                if ($start !== null && ($anchor === null || $start > $anchor)) $anchor = $start;
            }
        }
        $stmt = $this->db->pdo()->prepare("SELECT l.*, e.entry_date, e.source_type, e.source_id, e.row_version AS entry_version,
                a.account_code, a.account_type, a.name AS account_name
            FROM journal_entries e JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
            JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
            WHERE e.supplier_id = ? AND e.posted_at IS NOT NULL AND e.entry_date <= ?
                AND a.account_type IN ('asset', 'liability', 'equity')
                AND NOT (e.source_type = 'closing' AND COALESCE(e.source_id, 0) = ?)
                AND e.id NOT IN ({$excludedMarks})
            ORDER BY e.id, l.line_no, l.id" . $lock);
        $stmt->execute([$supplierId, $period['ends_on'], $periodId, ...$excludedIds]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $ids = array_map('intval', array_column($rows, 'id'));
        $assignments = new DimensionAssignmentRepository($this->db);
        $dims = $assignments->lineDimensions($supplierId, $ids, $currentRead);
        $splits = $assignments->lineSplits($supplierId, $ids, $currentRead);
        $origins = $assignments->lineOrigins($supplierId, $ids, $currentRead);
        $splitOrigins = $assignments->lineSplitOrigins($supplierId, $ids, $currentRead);
        foreach ($rows as &$row) {
            $row['dimensions'] = $dims[$row['id']] ?? [];
            $row['dimension_splits'] = $splits[$row['id']] ?? [];
            $row['dimension_origins'] = $origins[$row['id']] ?? [];
            $row['dimension_split_origins'] = $splitOrigins[$row['id']] ?? [];
        }
        unset($row);
        $stmt = $this->db->pdo()->prepare('SELECT id, code, name, system_key, is_active FROM dimension_types WHERE supplier_id = ? ORDER BY id' . $lock);
        $stmt->execute([$supplierId]);
        $types = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $requiredTypes = [];
        foreach ($types as $type) if (in_array($type['system_key'], ['activity', 'activity_kind', 'tax_regime'], true)) $requiredTypes[] = (int) $type['id'];
        $stmt = $this->db->pdo()->prepare('SELECT id, type_id, code, name, semantic_key, is_active FROM dimension_values WHERE supplier_id = ? ORDER BY id' . $lock);
        $stmt->execute([$supplierId]);
        $values = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $nominal = (new Fx504NativeNominalRepository($this->db))->normalize($supplierId, $period['ends_on'], $rows, $headers, $currentRead);
        $rows = $nominal['rows'];
        $ids = array_map('intval', array_column($rows, 'id'));
        $caseSnapshot = (new Fx504CaseReadRepository($this->db))->snapshot($supplierId, $period, $rows, $headers, $anchor, $currentRead);
        $caseSnapshot['findings'] = [...$caseSnapshot['findings'], ...$nominal['findings']];
        $caseSnapshot['hash'] = hash('sha256', $caseSnapshot['hash'] . $nominal['hash']);
        $positions = Fx504Builder::positions(array_values(array_filter($rows, static fn (array $row): bool => ($anchor === null || $row['entry_date'] >= $anchor) && $row['source_type'] !== 'closing')));
        $rates = [];
        $rateInfo = [];
        $from = (new \DateTimeImmutable($period['ends_on']))->modify('-7 days')->format('Y-m-d');
        $stmt = $this->db->pdo()->prepare('SELECT currency_code, rate_date, rate FROM exchange_rates
            WHERE currency_code = ? AND rate_date >= ? AND rate_date <= ? ORDER BY rate_date DESC LIMIT 1' . $lock);
        foreach (array_unique(array_column($caseSnapshot['rows'], 'currency_code')) as $currency) {
            $stmt->execute([$currency, $from, $period['ends_on']]);
            $rate = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($rate === false) continue;
            $rates[$currency] = (string) $rate['rate'];
            $rateInfo[] = ['currency' => $currency, 'rate' => (string) $rate['rate'], 'rate_date' => $rate['rate_date'], 'fallback_used' => $rate['rate_date'] !== $period['ends_on']];
        }
        $securities = $securitiesRead->snapshot($supplierId, $period, $currentRead, $securitiesProfile);
        return ['period' => $period, 'positions' => $positions, 'rates' => $rates, 'rate_info' => $rateInfo,
            'securities_snapshot' => $securities,
            'required_types' => $requiredTypes, 'dimension_types' => $types, 'dimension_values' => $values,
            'source_line_ids' => $ids, 'source_rows' => $rows, 'case_snapshot' => $caseSnapshot,
            'snapshot_hash' => hash('sha256', json_encode([$periodId, $period['starts_on'], $period['ends_on'], $anchor, $rows, $types, $values, $rateInfo, $caseSnapshot['hash'], $securities['hash']], JSON_THROW_ON_ERROR))];
    }
    public static function excludedEntries(array $headers, int $periodId): array
    {
        $excluded = [];
        $ownSlots = [ClosingSourceId::fxSaldo($periodId), ClosingSourceId::fxBank($periodId)];
        foreach ($headers as $header) {
            if ($header['source_type'] === 'fx_revaluation' && in_array((int) $header['source_id'], $ownSlots, true)) $excluded[(int) $header['id']] = true;
        }
        do {
            $size = count($excluded);
            foreach ($headers as $header) if (isset($excluded[(int) $header['id']]) && $header['reversed_by'] !== null) $excluded[(int) $header['reversed_by']] = true;
        } while (count($excluded) !== $size);
        return $excluded;
    }

}
