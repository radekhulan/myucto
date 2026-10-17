<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Match;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClientBankAccountRepository;
use MyInvoice\Service\Bank\AccountNumberNormalizer;
use MyInvoice\Service\Bank\BankNoticeMutationGuard;
use PDO;

final class CounterpartyMapService
{
    public function __construct(
        private readonly Connection $db,
        private readonly ClientBankAccountRepository $accounts,
    ) {}

    public function record(int $supplierId, string $account, string $bank, string $side, int $clientId, bool $manual, ?float $feePct = null, ?int $transactionId = null): void
    {
        (new BankNoticeMutationGuard($this->db))->mutate($supplierId, $transactionId, fn () => $this->recordMutable($supplierId, $account, $bank, $side, $clientId, $manual, $feePct, $transactionId));
    }

    private function recordMutable(int $supplierId, string $account, string $bank, string $side, int $clientId, bool $manual, ?float $feePct = null, ?int $transactionId = null): void
    {
        $key = AccountNumberNormalizer::canonical($account);
        if ($supplierId <= 0 || $clientId <= 0 || $key === null || !in_array($side, ['incoming', 'outgoing'], true)) return;
        $accountId = $this->accounts->captureFromBank($clientId, $supplierId, $account, $bank, $transactionId);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO bank_counterparty_map
                (supplier_id, client_bank_account_id, side, match_count, manual_count)
             VALUES (?, ?, ?, 0, 0)
             ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id)'
        );
        $stmt->execute([$supplierId, $accountId, $side]);
        $mapId = (int) $this->db->pdo()->lastInsertId();
        if ($transactionId !== null && $transactionId > 0) {
            $observation = $this->db->pdo()->prepare(
                'INSERT IGNORE INTO bank_counterparty_observations
                    (map_id, bank_transaction_id, manual, fee_pct)
                 VALUES (?, ?, ?, ?)'
            );
            $observation->execute([$mapId, $transactionId, $manual ? 1 : 0, $feePct]);
            if ($observation->rowCount() === 0) return;
        }
        $update = $this->db->pdo()->prepare(
            'UPDATE bank_counterparty_map
                SET promoted_at = IF(promoted_at IS NULL AND match_count + 1 >= 3 AND contradiction_count = 0, NOW(), promoted_at),
                    match_count = match_count + 1,
                    manual_count = manual_count + ?,
                    fee_pct_last = IF(? IS NULL, fee_pct_last, ?),
                    fee_pct_samples = fee_pct_samples + ?,
                    last_match_at = NOW()
              WHERE id = ? AND supplier_id = ?'
        );
        $update->execute([$manual ? 1 : 0, $feePct, $feePct, $feePct === null ? 0 : 1, $mapId, $supplierId]);
    }

    public function recordContradiction(int $supplierId, string $account, string $bank, string $side, int $clientId): void
    {
        $key = AccountNumberNormalizer::canonical($account);
        if ($key === null) return;
        $stmt = $this->db->pdo()->prepare(
            'UPDATE bank_counterparty_map bcm
              JOIN client_bank_accounts cba ON cba.id = bcm.client_bank_account_id AND cba.supplier_id = bcm.supplier_id
                SET contradiction_count = contradiction_count + 1, demoted_at = NOW()
              WHERE bcm.supplier_id = ? AND cba.account_key = ?
                AND (cba.bank_key = ? OR cba.bank_key = \'\' OR ? = \'\')
                AND bcm.side = ? AND cba.client_id = ?'
        );
        $bankKey = $this->bankKey($account, $bank);
        $stmt->execute([$supplierId, $key, $bankKey, $bankKey, $side, $clientId]);
    }

    /** @return array{client_id:int,promoted:bool,match_count:int,fee_pct_last:?float,fee_pct_samples:int}|null */
    public function lookup(int $supplierId, string $account, string $bank, string $side): ?array
    {
        $key = AccountNumberNormalizer::canonical($account);
        if ($key === null) return null;
        $stmt = $this->db->pdo()->prepare(
            'SELECT cba.client_id, bcm.match_count, bcm.contradiction_count,
                    bcm.fee_pct_last, bcm.fee_pct_samples
               FROM bank_counterparty_map bcm
               JOIN client_bank_accounts cba ON cba.id = bcm.client_bank_account_id
              WHERE bcm.supplier_id = ? AND cba.supplier_id = bcm.supplier_id
                AND cba.is_active = 1 AND cba.account_key = ?
                AND (cba.bank_key = ? OR cba.bank_key = \'\' OR ? = \'\')
                AND bcm.side = ?'
        );
        $bankKey = $this->bankKey($account, $bank);
        $stmt->execute([$supplierId, $key, $bankKey, $bankKey, $side]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $clientIds = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['client_id'], $rows)));
        if (count($clientIds) !== 1) return null;
        usort($rows, static fn (array $a, array $b): int => (int) $b['match_count'] <=> (int) $a['match_count']);
        $row = $rows[0];
        return [
            'client_id' => (int) $row['client_id'],
            'promoted' => (int) $row['match_count'] >= 3 && (int) $row['contradiction_count'] === 0,
            'match_count' => (int) $row['match_count'],
            'fee_pct_last' => $row['fee_pct_last'] === null ? null : (float) $row['fee_pct_last'],
            'fee_pct_samples' => (int) $row['fee_pct_samples'],
        ];
    }

    private function bankKey(string $account, string $bank): string
    {
        $key = AccountNumberNormalizer::canonicalBankCode($bank, $account);
        if ($key !== null) return $key;
        if (preg_match('#/(\d{1,4})\s*$#', $account, $match) === 1) {
            return str_pad($match[1], 4, '0', STR_PAD_LEFT);
        }
        return '';
    }
}
