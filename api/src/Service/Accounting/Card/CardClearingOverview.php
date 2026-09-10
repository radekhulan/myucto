<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Card;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use MyInvoice\Repository\CardClearingSettingsRepository;
use PDO;

/**
 * Stav mezičlenu plateb kartou pro kontroly (měsíční i předuzávěrkové) a přehledy.
 *
 * Zůstatek analytiky karty má vždy vysvětlovat konkrétní nevypořádané pohyby: platbu
 * kartou, k níž k danému dni není vypořádání s dokladem ani uzavření bez dokladu.
 * Rozdíl mezi zůstatkem a součtem takových pohybů je nevysvětlený — typicky ruční
 * zápis na analytiku karty — a kontrola ho hlásí zvlášť.
 */
final class CardClearingOverview
{
    public const MAX_ITEMS = 200;

    public function __construct(
        private readonly Connection $db,
        private readonly CardClearingAccounts $accounts,
        private readonly CardClearingSettingsRepository $settings,
    ) {}

    /**
     * Analytiky mezičlenu karet, které firma v osnově má (pod kteroukoli syntetikou).
     *
     * @return array<string, array{account_id:int, name:string}>
     */
    public function clearingAccounts(int $supplierId): array
    {
        $codes = $this->accounts->allClearingCodes($supplierId);
        if ($codes === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($codes), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, account_code, name FROM chart_of_accounts WHERE supplier_id = ? AND account_code IN ($ph)"
        );
        $stmt->execute([$supplierId, ...$codes]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(string) $r['account_code']] = ['account_id' => (int) $r['id'], 'name' => (string) $r['name']];
        }
        ksort($out);
        return $out;
    }

    /** Součet zůstatků analytik karet pod syntetikou (kvůli vyloučení z kontrol 261/395). */
    public function clearingBalanceUnder(int $supplierId, string $synthetic, string $asOf): float
    {
        $total = 0.0;
        foreach (array_keys($this->clearingAccounts($supplierId)) as $code) {
            if (str_starts_with((string) $code, $synthetic . '.')) {
                $total += $this->accounts->balance($supplierId, (string) $code, $asOf);
            }
        }
        return round($total, 2);
    }

    /**
     * Pohyby kartou zaúčtované přes mezičlen, které k datu nemají vypořádání ani uzavření.
     *
     * @return list<array{tx_id:int, entry_id:int, posted_at:string, account_code:string, amount:float, counterparty_name:?string, card_last4:?string}>
     */
    public function openItems(int $supplierId, string $asOf): array
    {
        $codes = array_keys($this->clearingAccounts($supplierId));
        if ($codes === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($codes), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT bt.id AS tx_id, je.id AS entry_id, bt.posted_at, c.account_code,
                    CASE WHEN jel.side = 'debit' THEN jel.amount ELSE -jel.amount END AS amount,
                    bt.counterparty_name, bt.card_last4
               FROM journal_entries je
               JOIN journal_entry_lines jel ON jel.entry_id = je.id AND jel.supplier_id = je.supplier_id
               JOIN chart_of_accounts c ON c.id = jel.account_id AND c.supplier_id = je.supplier_id
               JOIN bank_transactions bt ON bt.id = je.source_id
              WHERE je.supplier_id = ? AND je.source_type = 'bank' AND je.reversed_by IS NULL
                AND je.posted_at IS NOT NULL AND je.entry_date <= ?
                AND c.account_code IN ($ph)
                AND NOT EXISTS (
                    SELECT 1 FROM journal_entries s
                     WHERE s.supplier_id = je.supplier_id AND s.source_id = bt.id
                       AND s.source_type IN ('card_settlement', 'card_writeoff')
                       AND s.reversed_by IS NULL AND s.posted_at IS NOT NULL AND s.entry_date <= ?
                )
              ORDER BY bt.posted_at, bt.id"
        );
        $stmt->execute([$supplierId, $asOf, ...$codes, $asOf]);
        return array_map(static fn (array $r): array => [
            'tx_id'             => (int) $r['tx_id'],
            'entry_id'          => (int) $r['entry_id'],
            'posted_at'         => (string) $r['posted_at'],
            'account_code'      => (string) $r['account_code'],
            'amount'            => round((float) $r['amount'], 2),
            'counterparty_name' => $r['counterparty_name'] !== null ? (string) $r['counterparty_name'] : null,
            'card_last4'        => $r['card_last4'] !== null ? (string) $r['card_last4'] : null,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Předuzávěrková kontrola `card_clearing_open`: zůstatek každé analytiky karty proti
     * nevypořádaným pohybům.
     *
     * @return array{ok:bool, accounts:list<array<string,mixed>>, unexplained:float}
     */
    public function closingCheck(int $supplierId, string $asOf): array
    {
        $accounts = $this->clearingAccounts($supplierId);
        $byCode = [];
        foreach ($this->openItems($supplierId, $asOf) as $item) {
            $byCode[$item['account_code']][] = $item;
        }
        $rows = [];
        $unexplainedTotal = 0.0;
        foreach ($accounts as $code => $meta) {
            $balance = $this->accounts->balance($supplierId, (string) $code, $asOf);
            $items = $byCode[$code] ?? [];
            if (abs($balance) < 0.005 && $items === []) {
                continue;
            }
            $explained = round(array_sum(array_column($items, 'amount')), 2);
            $unexplained = round($balance - $explained, 2);
            $unexplainedTotal += $unexplained;
            $rows[] = [
                'account_code' => (string) $code,
                'account_id'   => $meta['account_id'],
                'name'         => $meta['name'],
                'balance'      => $balance,
                'explained'    => $explained,
                'unexplained'  => $unexplained,
                'open_count'   => count($items),
                'items'        => array_slice($items, 0, self::MAX_ITEMS),
            ];
        }
        return ['ok' => $rows === [], 'accounts' => $rows, 'unexplained' => round($unexplainedTotal, 2)];
    }

    /**
     * Měsíční kontrola `card_payments_unmatched`: platby kartou bez dokladu starší než
     * N dní z nastavení (jen od data účinnosti režimu).
     *
     * @return array{enabled:bool, days:int, count:int, total:float, items:list<array<string,mixed>>}
     */
    public function unmatchedOlderThan(int $supplierId, string $asOf): array
    {
        $s = $this->settings->find($supplierId);
        $days = (int) $s['unmatched_alert_days'];
        if (empty($s['enabled']) || $s['effective_from'] === null) {
            return ['enabled' => false, 'days' => $days, 'count' => 0, 'total' => 0.0, 'items' => []];
        }
        $cutoff = date('Y-m-d', strtotime($asOf . ' -' . $days . ' days'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT bt.id, bt.posted_at, bt.amount, bt.card_last4, bt.counterparty_name
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bt.card_last4 IS NOT NULL AND bt.amount < 0 AND bt.source = 'statement'
                AND bt.match_status = 'unmatched'
                AND bt.posted_at >= ? AND bt.posted_at <= ?
                AND NOT EXISTS (SELECT 1 FROM payment_matches pm WHERE pm.bank_transaction_id = bt.id)
                AND NOT EXISTS (SELECT 1 FROM journal_entries w
                                 WHERE w.supplier_id = ? AND w.source_type = 'card_writeoff'
                                   AND w.source_id = bt.id AND w.reversed_by IS NULL)
                AND " . BankStatementOwnershipResolver::sql('bs') . '
              ORDER BY bt.posted_at, bt.id'
        );
        $stmt->execute(array_merge(
            [(string) $s['effective_from'], $cutoff, $supplierId],
            BankStatementOwnershipResolver::params($supplierId),
        ));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $items = array_map(static fn (array $r): array => [
            'tx_id'             => (int) $r['id'],
            'posted_at'         => (string) $r['posted_at'],
            'amount'            => round((float) $r['amount'], 2),
            'card_last4'        => (string) $r['card_last4'],
            'counterparty_name' => $r['counterparty_name'] !== null ? (string) $r['counterparty_name'] : null,
        ], $rows);
        return [
            'enabled' => true,
            'days'    => $days,
            'count'   => count($items),
            'total'   => round(array_sum(array_map(static fn (array $i): float => abs($i['amount']), $items)), 2),
            'items'   => array_slice($items, 0, self::MAX_ITEMS),
        ];
    }
}
