<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClosingRepository;
use MyInvoice\Repository\OtherItemRepository;
use PDO;

/**
 * Kontroly ostatních pohledávek a závazků pro měsíční kontrolu a uzávěrku.
 * Čtou jen: pokrytí splátek dává {@see OtherItemInstallmentCoverage}, zůstatky účtů
 * stejná rutina jako ostatní zůstatkové kontroly ({@see ClosingRepository::accountBalances}).
 */
final class OtherItemMonthlyCheck
{
    /** Rozdíl v Kč, pod kterým se zůstatek účtu a otevřené položky berou jako shodné (haléřové zaokrouhlení, kurz). */
    public const BALANCE_TOLERANCE = 1.00;

    public function __construct(
        private readonly Connection $db,
        private readonly OtherItemRepository $items,
        private readonly ClosingRepository $closing,
    ) {}

    /**
     * Splátky s datem splatnosti do konce kontrolovaného rozsahu, které mají zbytek k úhradě.
     * Jeden nález na položku, ne na splátku.
     *
     * @return array{key:string,severity:string,ok:bool,value:array<string,mixed>}
     */
    public function overdueInstallments(int $supplierId, string $rangeTo): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT oi.id, oi.document_no, oi.title, oi.partner_name, oi.side, oi.currency
               FROM other_items oi
              WHERE oi.supplier_id = ? AND oi.deleted_at IS NULL AND oi.status IN ('confirmed','posted')
                AND EXISTS (SELECT 1 FROM other_item_installments i
                             WHERE i.supplier_id = oi.supplier_id AND i.other_item_id = oi.id AND i.due_on <= ?)
              ORDER BY oi.id"
        );
        $stmt->execute([$supplierId, $rangeTo]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[(int) $row['id']] = $row;
        }
        $coverage = $this->items->installmentCoverage($supplierId, array_keys($rows), $rangeTo);
        $findings = [];
        foreach ($rows as $itemId => $item) {
            $due = array_values(array_filter(
                $coverage[$itemId] ?? [],
                static fn (array $c): bool => $c['due_on'] <= $rangeTo && $c['remaining_amount'] > 0.005,
            ));
            if ($due === []) continue;
            $findings[] = [
                'doc_type' => 'other_item',
                'id' => $itemId,
                'doc_no' => (string) ($item['document_no'] ?? ''),
                'partner_name' => (string) ($item['partner_name'] ?? ''),
                'due_date' => $due[0]['due_on'],
                'amount' => round(array_sum(array_column($due, 'remaining_amount')), 2),
                'currency' => (string) $item['currency'],
                'note' => (string) $item['title'] . ' (' . count($due) . 'x)',
            ];
        }

        return [
            'key' => 'other_item_installments_overdue',
            'severity' => 'warning',
            'ok' => $findings === [],
            'value' => ['count' => count($findings), 'items' => $findings],
        ];
    }

    /**
     * Zůstatek účtů, na kterých leží ostatní položky, proti součtu jejich otevřených zbytků
     * k poslednímu dni rozsahu. Jen podvojné účetnictví (daňová evidence nemá deník), jinak null.
     *
     * @return array{key:string,severity:string,ok:bool,value:array<string,mixed>}|null
     */
    public function accountBalanceDifference(int $supplierId, string $rangeTo): ?array
    {
        $pdo = $this->db->pdo();
        $mode = $pdo->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
        $mode->execute([$supplierId]);
        if ($mode->fetchColumn() === 'tax_evidence') {
            return null;
        }

        $stmt = $pdo->prepare(
            "SELECT oi.id, oi.side, oi.amount, oi.amount_czk, oi.settled_outside_amount,
                    COALESCE(NULLIF(oi.account_code, ''), IF(oi.side = 'receivable', '315', '325')) AS account,
                    COALESCE((SELECT SUM(a.amount) FROM other_item_allocations a
                               WHERE a.supplier_id = oi.supplier_id AND a.other_item_id = oi.id
                                 AND a.payment_on <= ? AND (a.reversed_on IS NULL OR a.reversed_on > ?)), 0) AS paid
               FROM other_items oi
              WHERE oi.supplier_id = ? AND oi.deleted_at IS NULL AND oi.status = 'posted'
                AND COALESCE(oi.accounting_on, oi.issued_on) <= ?"
        );
        $stmt->execute([$rangeTo, $rangeTo, $supplierId, $rangeTo]);
        $byAccount = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $amount = (float) $row['amount'];
            $open = max($amount - (float) $row['settled_outside_amount'] - (float) $row['paid'], 0.0);
            $czk = $row['amount_czk'] !== null ? (float) $row['amount_czk'] : $amount;
            $remainingCzk = $amount > 0 ? $czk * $open / $amount : 0.0;
            $account = (string) $row['account'];
            $byAccount[$account] = ($byAccount[$account] ?? 0.0) + ($row['side'] === 'receivable' ? $remainingCzk : -$remainingCzk);
        }
        if ($byAccount === []) {
            return $this->balanceResult([]);
        }

        // Analytika a její syntetika by se započetly dvakrát (zůstatek účtu zahrnuje analytiky).
        $codes = array_keys($byAccount);
        sort($codes);
        $roots = [];
        foreach ($codes as $code) {
            foreach (array_keys($roots) as $root) {
                if (str_starts_with($code, $root)) {
                    $roots[$root] += $byAccount[$code];
                    continue 2;
                }
            }
            $roots[$code] = $byAccount[$code];
        }
        $balances = $this->closing->accountBalances($supplierId, array_keys($roots), $rangeTo);

        $accounts = [];
        foreach ($roots as $code => $openItems) {
            $code = (string) $code;
            $ledger = round((float) ($balances[$code] ?? 0.0), 2);
            $open = round($openItems, 2);
            $diff = round($ledger - $open, 2);
            if (abs($diff) <= self::BALANCE_TOLERANCE) continue;
            $account = $pdo->prepare('SELECT id, name FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ? LIMIT 1');
            $account->execute([$supplierId, $code]);
            $meta = $account->fetch(PDO::FETCH_ASSOC) ?: [];
            $accounts[] = [
                'account_id' => isset($meta['id']) ? (int) $meta['id'] : null,
                'account_code' => $code,
                'name' => (string) ($meta['name'] ?? ''),
                'amount' => $diff,
                'ledger_balance' => $ledger,
                'open_items' => $open,
                'note' => sprintf('%.2f / %.2f', $ledger, $open),
            ];
        }

        return $this->balanceResult($accounts);
    }

    /**
     * @param list<array<string,mixed>> $accounts
     * @return array{key:string,severity:string,ok:bool,value:array<string,mixed>}
     */
    private function balanceResult(array $accounts): array
    {
        return [
            'key' => 'other_item_account_balance',
            'severity' => 'warning',
            'ok' => $accounts === [],
            'value' => ['count' => count($accounts), 'accounts' => $accounts],
        ];
    }
}
