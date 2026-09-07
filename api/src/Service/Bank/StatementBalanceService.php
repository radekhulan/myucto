<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use PDO;

final class StatementBalanceService
{
    public function __construct(private readonly Connection $db) {}

    public function snapshot(int $supplierId, int $statementId): array
    {
        $pdo = $this->db->pdo();
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) $pdo->beginTransaction();
        try {
            $result = $this->readSnapshot($supplierId, $statementId);
            if ($ownTransaction) $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private function readSnapshot(int $supplierId, int $statementId): array
    {
        $query = $this->db->pdo()->prepare('SELECT bs.id, bs.source, bs.account_number, bs.bank_code, bs.currency,
            bs.statement_date, bs.prev_balance, bs.credit_total, bs.debit_total, bs.curr_balance,
            (bs.pdf_content IS NOT NULL) AS has_pdf, (bs.file_content IS NOT NULL) AS has_file FROM bank_statements bs WHERE ' . BankStatementOwnershipResolver::sql());
        $query->execute(BankStatementOwnershipResolver::params($supplierId));
        $statements = $query->fetchAll(PDO::FETCH_ASSOC);
        $selected = null;
        foreach ($statements as $row) if ((int) $row['id'] === $statementId) $selected = $row;
        if ($selected === null) throw new \InvalidArgumentException('statement_not_found');
        $key = AuthoritativeTransactionReconciler::account((string) $selected['account_number'], (string) $selected['bank_code']);
        if ($key === null || !BankStatementSource::isStatement((string) $selected['source'])) {
            throw new \InvalidArgumentException('gpc_account_unsupported');
        }
        $statements = array_values(array_filter($statements, static fn (array $row): bool =>
            $row['currency'] === $selected['currency'] && BankStatementSource::isStatement((string) $row['source'])
            && AuthoritativeTransactionReconciler::account((string) $row['account_number'], (string) $row['bank_code']) === $key));
        $to = substr((string) $selected['statement_date'], 0, 10);
        $from = substr($to, 0, 7) . '-01';
        $anchorDate = null;
        $anchor = null;
        $confirmed = null;
        $conflict = false;
        $checkpoints = [];
        $unverifiedPdf = false;
        usort($statements, static fn (array $a, array $b): int => strcmp($a['statement_date'], $b['statement_date']));
        foreach ($statements as $row) {
            $date = substr($row['statement_date'], 0, 10);
            if ($date > $to) continue;
            if ($row['source'] === 'bank_api' && $row['has_pdf'] && $date >= $from) $unverifiedPdf = true;
            if (!in_array($row['source'], ['gpc', 'pdf'], true) || $row['curr_balance'] === null) continue;
            $balance = self::cents($row['curr_balance']);
            if ($row['prev_balance'] !== null && $row['credit_total'] !== null && $row['debit_total'] !== null
                && self::cents($row['prev_balance']) + self::cents($row['credit_total']) - self::cents($row['debit_total']) !== $balance) {
                throw new \InvalidArgumentException('balance_conflict');
            }
            if ($date >= $from) {
                if (isset($checkpoints[$date]) && $checkpoints[$date] !== $balance) throw new \InvalidArgumentException('balance_conflict');
                $checkpoints[$date] = $balance;
            }
            if ($date < $from) {
                if ($date === $anchorDate && $anchor !== $balance) $conflict = true;
                if ($date !== $anchorDate) $conflict = false;
                $anchorDate = $date;
                $anchor = $balance;
            }
            if ($date === $to) {
                if ($confirmed !== null && $confirmed !== $balance) throw new \InvalidArgumentException('balance_conflict');
                $confirmed = $balance;
            }
        }
        if ($conflict) throw new \InvalidArgumentException('balance_conflict');
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $statements);
        $tx = $this->db->pdo()->prepare("SELECT bt.id, bt.posted_at, bt.amount, bt.currency, bt.bank_ref,
            bt.variable_symbol, bt.constant_symbol, bt.specific_symbol, bt.counterparty_account,
            bt.counterparty_bank, bt.counterparty_name, bt.description
            FROM bank_transactions bt WHERE bt.statement_id IN (" . implode(',', $ids) . ")
            AND bt.source = 'statement' AND bt.posted_at > ? AND bt.posted_at <= ? ORDER BY bt.posted_at, bt.id");
        $tx->execute([$anchorDate ?? date('Y-m-d', strtotime($from . ' -1 day')), $to]);
        $opening = $anchor;
        $credit = 0;
        $debit = 0;
        $transactions = [];
        foreach ($tx->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['currency'] !== null && $row['currency'] !== '' && $row['currency'] !== $selected['currency']) {
                throw new \InvalidArgumentException('gpc_currency_mismatch');
            }
            $amount = self::cents($row['amount']);
            if ($row['posted_at'] < $from) {
                if ($opening !== null) $opening += $amount;
                continue;
            }
            if ($amount >= 0) $credit += $amount; else $debit -= $amount;
            $transactions[] = $row;
        }
        $closing = $opening === null ? null : $opening + $credit - $debit;
        $difference = $closing === null || $confirmed === null ? null : $confirmed - $closing;
        $checkpointMismatch = false;
        if ($opening !== null) {
            foreach ($checkpoints as $date => $balance) {
                $calculated = $opening;
                foreach ($transactions as $row) {
                    if ($row['posted_at'] <= $date) $calculated += self::cents($row['amount']);
                }
                if ($calculated !== $balance) {
                    $checkpointMismatch = true;
                    $difference = $balance - $calculated;
                    break;
                }
            }
        }
        $bankStatementId = null;
        if ($transactions !== []) {
            $transactionIds = array_map(static fn (array $row): int => (int) $row['id'], $transactions);
            foreach ($statements as $row) {
                if ($row['source'] !== 'gpc' || !$row['has_file'] || $row['curr_balance'] === null
                    || $row['statement_date'] < $to || substr($row['statement_date'], 0, 7) !== substr($to, 0, 7)) continue;
                $covered = $this->db->pdo()->query('SELECT COUNT(*) FROM bank_transactions bt WHERE bt.id IN ('
                    . implode(',', $transactionIds) . ') AND ' . StatementTransactionScope::sql((int) $row['id']))->fetchColumn();
                if ((int) $covered === count($transactionIds)) $bankStatementId = (int) $row['id'];
            }
        }
        if ($unverifiedPdf && $bankStatementId === null) throw new \InvalidArgumentException('balance_pdf_unverified');
        return ['account_number' => substr($key, 5), 'bank_code' => substr($key, 0, 4), 'currency' => $selected['currency'],
            'from' => $from, 'to' => $to, 'anchor_date' => $anchorDate,
            'opening' => $opening === null ? null : $opening / 100, 'closing' => $closing === null ? null : $closing / 100,
            'credit' => $credit / 100, 'debit' => $debit / 100,
            'confirmed_closing' => $confirmed === null ? null : $confirmed / 100,
            'bank_statement_id' => $bankStatementId,
            'difference' => $difference === null ? null : $difference / 100,
            'status' => $closing === null ? 'missing_anchor' : ($checkpointMismatch ? 'mismatch' : ($confirmed === null ? 'calculated' : 'confirmed')),
            'transactions' => $transactions];
    }

    public static function cents(mixed $value): int
    {
        if (!is_numeric($value) || !preg_match('/^(-?)(\d{1,12})(?:\.(\d{1,2}))?$/D', (string) $value, $m)) {
            throw new \InvalidArgumentException('gpc_amount_invalid');
        }
        return ($m[1] === '-' ? -1 : 1) * ((int) $m[2] * 100 + (int) str_pad($m[3] ?? '', 2, '0'));
    }
}
