<?php

declare(strict_types=1);

namespace MyInvoice\Service\Ai;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class AnomalyDetector
{
    public function __construct(private readonly Connection $db) {}

    /** @return list<array{code:string,detail:array<string,mixed>}> */
    public function checkBankTx(int $supplierId, array $tx): array
    {
        if ((int) ($tx['id'] ?? 0) <= 0 || !$this->belongsToSupplier($supplierId, $tx)) {
            return [];
        }
        $out = [];
        $account = preg_replace('/\D/', '', (string) ($tx['counterparty_account'] ?? '')) ?? '';
        $account = ltrim($account, '0');
        if ($account !== '') {
            $amounts = $this->historicalAmounts($supplierId, $tx, $account);
            if (count($amounts) >= 6) {
                $mean = array_sum($amounts) / count($amounts);
                $variance = array_sum(array_map(static fn (float $v): float => ($v - $mean) ** 2, $amounts)) / count($amounts);
                $std = max(1.0, sqrt($variance));
                $z = abs(abs((float) $tx['amount']) - $mean) / $std;
                if ($z > 3.0) {
                    $out[] = ['code' => 'amount_zscore', 'detail' => [
                        'n' => count($amounts), 'mean' => round($mean, 2), 'std' => round($std, 2), 'z' => round($z, 2),
                    ]];
                }
            }
        }
        $vs = preg_replace('/\D/', '', (string) ($tx['variable_symbol'] ?? '')) ?? '';
        if ($vs !== '' && $this->hasDuplicate($supplierId, $tx, $vs)) {
            $out[] = ['code' => 'duplicate_payment', 'detail' => ['window_days' => 10]];
        }
        return $out;
    }

    public function recordDegraded(int $supplierId): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO ai_metrics (supplier_id,source,metric_date,suggested_count)
             VALUES (?,'anomaly',CURDATE(),1)
             ON DUPLICATE KEY UPDATE suggested_count=suggested_count+1"
        )->execute([$supplierId]);
    }

    /** @return list<float> */
    private function historicalAmounts(int $supplierId, array $tx, string $account): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT ABS(bt.amount) amount
               FROM bank_transactions bt JOIN bank_statements bs ON bs.id=bt.statement_id
              WHERE bs.supplier_id=? AND bt.id<>? AND bt.posted_at<? AND bt.posted_at>=DATE_SUB(?,INTERVAL 24 MONTH)
                AND bs.account_number=? AND COALESCE(bs.bank_code,'')=COALESCE(?, '')
                AND SIGN(bt.amount)=SIGN(?)
                AND TRIM(LEADING '0' FROM REGEXP_REPLACE(SUBSTRING_INDEX(COALESCE(bt.counterparty_account,''),'/',1),'[^0-9]',''))=?"
        );
        $stmt->execute([
            $supplierId, (int) $tx['id'], (string) $tx['posted_at'], (string) $tx['posted_at'],
            (string) ($tx['recipient_account'] ?? ''), $tx['recipient_bank'] ?? null,
            (float) $tx['amount'], $account,
        ]);
        return array_map('floatval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function hasDuplicate(int $supplierId, array $tx, string $vs): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM bank_transactions bt JOIN bank_statements bs ON bs.id=bt.statement_id
              WHERE bs.supplier_id=? AND bt.id<>? AND bs.account_number=? AND COALESCE(bs.bank_code,'')=COALESCE(?, '')
                AND bs.source <> 'email_notice'
                AND REGEXP_REPLACE(COALESCE(bt.variable_symbol,''),'[^0-9]','')=?
                AND SIGN(bt.amount)=SIGN(?) AND ABS(ABS(bt.amount)-ABS(?))<=0.01
                AND ABS(DATEDIFF(bt.posted_at,?))<=10 LIMIT 1"
        );
        $stmt->execute([
            $supplierId, (int) $tx['id'], (string) ($tx['recipient_account'] ?? ''), $tx['recipient_bank'] ?? null,
            $vs, (float) $tx['amount'], (float) $tx['amount'], (string) $tx['posted_at'],
        ]);
        return $stmt->fetchColumn() !== false;
    }

    private function belongsToSupplier(int $supplierId, array $tx): bool
    {
        return (int) ($tx['statement_supplier_id'] ?? 0) === $supplierId;
    }
}
