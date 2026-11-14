<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Obligations;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\OtherItemRepository;
use PDO;

final class OtherItemForecastService
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Otevřené části ostatních pohledávek a závazků splatné v rozsahu; položka se splátkovým
     * kalendářem vrací řádek za každou neuhrazenou splátku.
     *
     * @return list<array{side:string,currency:string,due_on:string,remaining:float,status:string,
     *     id:int,document_no:string,title:string,partner_name:string,issued_on:string}>
     */
    public function dueBetween(int $supplierId, string $from, string $to, ?string $currency = null): array
    {
        $sql = 'SELECT oi.id, oi.document_no, oi.title, oi.partner_name, oi.issued_on,
                       oi.side, oi.currency, oi.due_on, oi.status,
                       GREATEST(oi.amount - oi.settled_outside_amount - COALESCE(SUM(a.amount), 0), 0) AS remaining
                  FROM other_items oi
             LEFT JOIN other_item_allocations a ON a.other_item_id = oi.id AND a.supplier_id = oi.supplier_id
                 AND a.reversed_on IS NULL
                 WHERE oi.supplier_id = ? AND oi.deleted_at IS NULL
                   AND oi.status IN (\'draft\',\'confirmed\',\'posted\')
                   AND NOT EXISTS (SELECT 1 FROM other_item_installments i WHERE i.other_item_id = oi.id)
                   AND oi.due_on BETWEEN ? AND ?';
        $params = [$supplierId, $from, $to];
        if ($currency !== null) {
            $sql .= ' AND oi.currency = ?';
            $params[] = $currency;
        }
        $sql .= ' GROUP BY oi.id HAVING remaining > 0 ORDER BY oi.due_on, oi.id';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = array_map(static fn (array $r): array => [
            'side' => (string) $r['side'], 'currency' => (string) $r['currency'],
            'due_on' => (string) $r['due_on'], 'remaining' => (float) $r['remaining'],
            'status' => (string) $r['status'],
        ] + self::identity($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
        // Splátky: zbytek každé splátky podle OtherItemInstallmentCoverage (stejné pravidlo jako
        // detail splátek a přiřazení úhrady). Načtou se jen položky se splátkou v rozsahu.
        $planned = 'SELECT oi.id, oi.document_no, oi.title, oi.partner_name, oi.issued_on,
                           oi.side, oi.currency, oi.status
                      FROM other_items oi
                     WHERE oi.supplier_id = ? AND oi.deleted_at IS NULL
                       AND oi.status IN (\'draft\',\'confirmed\',\'posted\')
                       AND EXISTS (SELECT 1 FROM other_item_installments i
                                    WHERE i.supplier_id = oi.supplier_id AND i.other_item_id = oi.id
                                      AND i.due_on BETWEEN ? AND ?)';
        $planParams = [$supplierId, $from, $to];
        if ($currency !== null) {
            $planned .= ' AND oi.currency = ?';
            $planParams[] = $currency;
        }
        $stmt = $this->db->pdo()->prepare($planned . ' ORDER BY oi.id');
        $stmt->execute($planParams);
        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $items[(int) $row['id']] = $row;
        $coverage = (new OtherItemRepository($this->db))->installmentCoverage($supplierId, array_keys($items));
        foreach ($items as $itemId => $item) {
            foreach ($coverage[$itemId] ?? [] as $installment) {
                if ($installment['due_on'] < $from || $installment['due_on'] > $to
                    || $installment['remaining_amount'] <= 0) continue;
                $rows[] = [
                    'side' => (string) $item['side'], 'currency' => (string) $item['currency'],
                    'due_on' => $installment['due_on'], 'remaining' => (float) $installment['remaining_amount'],
                    'status' => (string) $item['status'],
                ] + self::identity($item);
            }
        }
        usort($rows, static fn (array $a, array $b): int => $a['due_on'] <=> $b['due_on']);
        return $rows;
    }

    /**
     * @param array<string,mixed> $r
     * @return array{id:int,document_no:string,title:string,partner_name:string,issued_on:string}
     */
    private static function identity(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'document_no' => (string) ($r['document_no'] ?? ''),
            'title' => (string) ($r['title'] ?? ''),
            'partner_name' => (string) ($r['partner_name'] ?? ''),
            'issued_on' => (string) ($r['issued_on'] ?? ''),
        ];
    }

    /** @return list<array<string,float|string>> */
    public function resultImpact(int $supplierId, string $from, string $toExclusive): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT oi.currency, oi.side,
                    COALESCE(line.amount, oi.amount) amount,
                    COALESCE(line.amount, oi.amount_czk) amount_czk, a.account_type
               FROM other_items oi
          LEFT JOIN other_item_posting_lines line ON line.supplier_id = oi.supplier_id
                AND line.other_item_id = oi.id
               JOIN chart_of_accounts a ON a.supplier_id = oi.supplier_id
                AND a.account_code = COALESCE(line.account_code, oi.counter_account_code) AND a.is_active = 1
              WHERE oi.supplier_id = ? AND oi.deleted_at IS NULL AND oi.status = \'draft\'
                AND oi.accounting_on >= ? AND oi.accounting_on < ?
                AND a.account_type IN (\'revenue\',\'expense\')'
        );
        $stmt->execute([$supplierId, $from, $toExclusive]);
        $groups = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $code = (string) $item['currency'];
            $groups[$code] ??= ['currency' => $code, 'revenue' => 0.0, 'costs' => 0.0,
                'profit' => 0.0, 'revenue_czk' => 0.0, 'costs_czk' => 0.0, 'profit_czk' => 0.0,
                'posted' => 0.0, 'draft' => 0.0];
            $amount = (float) $item['amount'];
            $amountCzk = (float) $item['amount_czk'];
            $side = (string) $item['side'];
            if ($item['account_type'] === 'revenue') {
                $sign = $side === 'receivable' ? 1 : -1;
                $groups[$code]['revenue'] += $sign * $amount;
                $groups[$code]['revenue_czk'] += $sign * $amountCzk;
            } else {
                $sign = $side === 'payable' ? 1 : -1;
                $groups[$code]['costs'] += $sign * $amount;
                $groups[$code]['costs_czk'] += $sign * $amountCzk;
            }
            $groups[$code]['draft'] += $amount;
        }
        // Výběr začíná zápisy ostatních položek za dané období. Storno se dohledá
        // indexem podle ID zápisu, jinak může optimalizátor pročítat celý deník.
        $posted = $this->db->pdo()->prepare(
            'SELECT STRAIGHT_JOIN oi.currency, oi.amount, oi.amount_czk, a.account_type,
                    line.side, line.amount AS line_amount
               FROM journal_entries entry
          LEFT JOIN journal_entries original FORCE INDEX FOR JOIN (idx_je_supplier_reversed_by)
                 ON original.supplier_id = entry.supplier_id
                AND original.reversed_by = entry.id AND original.source_type = \'other_item\'
               JOIN other_items oi ON oi.supplier_id = entry.supplier_id
                AND oi.id = COALESCE(entry.source_id, original.source_id)
               JOIN journal_entry_lines line ON line.entry_id = entry.id
                AND line.supplier_id = entry.supplier_id
               JOIN chart_of_accounts a ON a.id = line.account_id
                AND a.supplier_id = entry.supplier_id
              WHERE entry.supplier_id = ? AND entry.source_type = \'other_item\'
                AND entry.posted_at IS NOT NULL
                AND entry.entry_date >= ? AND entry.entry_date < ?
                AND a.account_type IN (\'revenue\',\'expense\')'
        );
        $posted->execute([$supplierId, $from, $toExclusive]);
        foreach ($posted->fetchAll(PDO::FETCH_ASSOC) as $line) {
            $code = (string) $line['currency'];
            $groups[$code] ??= ['currency' => $code, 'revenue' => 0.0, 'costs' => 0.0,
                'profit' => 0.0, 'revenue_czk' => 0.0, 'costs_czk' => 0.0, 'profit_czk' => 0.0,
                'posted' => 0.0, 'draft' => 0.0];
            $amountCzk = (float) $line['line_amount'];
            $amount = $amountCzk * (float) $line['amount'] / (float) $line['amount_czk'];
            if ($line['account_type'] === 'revenue') {
                $sign = $line['side'] === 'credit' ? 1 : -1;
                $groups[$code]['revenue'] += $sign * $amount;
                $groups[$code]['revenue_czk'] += $sign * $amountCzk;
            } else {
                $sign = $line['side'] === 'debit' ? 1 : -1;
                $groups[$code]['costs'] += $sign * $amount;
                $groups[$code]['costs_czk'] += $sign * $amountCzk;
            }
            $groups[$code]['posted'] += $sign * $amount;
        }
        foreach ($groups as &$row) {
            $row['revenue'] = round($row['revenue'], 2);
            $row['costs'] = round($row['costs'], 2);
            $row['profit'] = round($row['revenue'] - $row['costs'], 2);
            $row['revenue_czk'] = round($row['revenue_czk'], 2);
            $row['costs_czk'] = round($row['costs_czk'], 2);
            $row['profit_czk'] = round($row['revenue_czk'] - $row['costs_czk'], 2);
            $row['posted'] = round($row['posted'], 2);
            $row['draft'] = round($row['draft'], 2);
        }
        unset($row);
        $groups = array_filter($groups, static fn (array $row): bool =>
            $row['revenue_czk'] !== 0.0 || $row['costs_czk'] !== 0.0 || $row['draft'] !== 0.0);
        ksort($groups);
        return array_values($groups);
    }

    /** @param list<array<string,mixed>> $existing */
    public function mergeCumulative(array $existing, int $supplierId, string $side, string $prefix): array
    {
        $today = new \DateTimeImmutable('today');
        $end = $today->modify('+90 days');
        $byCurrency = [];
        foreach ($existing as $row) $byCurrency[(string) $row['currency']] = $row;
        foreach ($this->dueBetween($supplierId, $today->format('Y-m-d'), $end->format('Y-m-d')) as $item) {
            if ($item['side'] !== $side) continue;
            $code = $item['currency'];
            $byCurrency[$code] ??= [
                'currency' => $code,
                $prefix . '_30' => 0.0, $prefix . '_60' => 0.0, $prefix . '_90' => 0.0,
                'count_30' => 0, 'count_60' => 0, 'count_90' => 0,
            ];
            foreach ([30, 60, 90] as $days) {
                if ($item['due_on'] <= $today->modify('+' . $days . ' days')->format('Y-m-d')) {
                    $byCurrency[$code][$prefix . '_' . $days] = round((float) $byCurrency[$code][$prefix . '_' . $days] + $item['remaining'], 2);
                    $byCurrency[$code]['count_' . $days]++;
                }
            }
        }
        ksort($byCurrency);
        return array_values($byCurrency);
    }

    /** @param list<array<string,mixed>> $existing @param list<array<string,mixed>> $sources */
    public static function mergeSourceCumulative(array $existing, array $sources, string $side, string $prefix): array
    {
        $today = new \DateTimeImmutable('today');
        $byCurrency = [];
        foreach ($existing as $row) $byCurrency[(string) $row['currency']] = $row;
        foreach ($sources as $item) {
            if ($item['side'] !== $side || (float) $item['remaining'] <= 0) continue;
            $code = (string) $item['currency'];
            $byCurrency[$code] ??= [
                'currency' => $code,
                $prefix . '_30' => 0.0, $prefix . '_60' => 0.0, $prefix . '_90' => 0.0,
                'count_30' => 0, 'count_60' => 0, 'count_90' => 0,
            ];
            foreach ([30, 60, 90] as $days) {
                if ($item['due_on'] >= $today->format('Y-m-d')
                    && $item['due_on'] <= $today->modify('+' . $days . ' days')->format('Y-m-d')) {
                    $byCurrency[$code][$prefix . '_' . $days] = round((float) $byCurrency[$code][$prefix . '_' . $days] + (float) $item['remaining'], 2);
                    $byCurrency[$code]['count_' . $days]++;
                }
            }
        }
        ksort($byCurrency);
        return array_values($byCurrency);
    }

    /** @param array<string,mixed> $forecast @param list<array<string,mixed>> $sources */
    public static function mergeSourceWeekly(array $forecast, array $sources): array
    {
        foreach ($forecast['weeks'] as &$week) {
            foreach ($sources as $item) {
                if ((string) $item['currency'] !== (string) $forecast['currency']
                    || (float) $item['remaining'] <= 0
                    || $item['due_on'] < $week['week_start'] || $item['due_on'] > $week['week_end']) continue;
                $key = $item['side'] === 'receivable' ? 'in' : 'out';
                $week[$key] = round((float) $week[$key] + (float) $item['remaining'], 2);
            }
            $week['net'] = round((float) $week['in'] - (float) $week['out'], 2);
        }
        unset($week);
        $running = $totalIn = $totalOut = 0.0;
        foreach ($forecast['weeks'] as &$week) {
            $running += (float) $week['net'];
            $totalIn += (float) $week['in'];
            $totalOut += (float) $week['out'];
            $week['running'] = round($running, 2);
        }
        unset($week);
        $forecast['total_in'] = round($totalIn, 2);
        $forecast['total_out'] = round($totalOut, 2);
        $forecast['total_net'] = round($totalIn - $totalOut, 2);
        return $forecast;
    }
}
