<?php

declare(strict_types=1);

namespace MyInvoice\Service\Automation;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\BankPostingRuleRepository;
use MyInvoice\Repository\SupplierBankAccountRepository;
use MyInvoice\Service\Accounting\Bank\BankMessageNormalizer;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use MyInvoice\Service\Accounting\OperationType;
use MyInvoice\Service\Accounting\Setup\AccountingRuleEquivalence;
use MyInvoice\Service\Bank\AccountNumberNormalizer;
use MyInvoice\Service\Bank\VariableSymbolNormalizer;
use PDO;

final class RuleProposalService
{
    private const SALDO_ACCOUNTS = ['311', '321', '314', '324', '325'];

    public function __construct(
        private readonly Connection $db,
        private readonly BankPostingRuleRepository $rules,
        private readonly SupplierBankAccountRepository $bankAccounts,
        private readonly AccountingPeriodRepository $periods,
        private readonly BankPostingService $posting,
    ) {}

    /** @return array<string,mixed> */
    public function analyze(int $supplierId, int $monthsBack = 27, bool $includePostedHistory = false): array
    {
        $monthsBack = max(1, min(60, $monthsBack));
        $historyFilter = $includePostedHistory ? '' : "
                AND bt.match_status='unmatched' AND hp.source_id IS NULL
                AND NOT EXISTS(SELECT 1 FROM bank_posting_suggestions pending_bps
                                WHERE pending_bps.supplier_id=? AND pending_bps.bank_transaction_id=bt.id
                                  AND pending_bps.status IN ('pending','needs_input','blocked','approved','auto_posted'))";
        $stmt = $this->db->pdo()->prepare(
            "SELECT bt.*,bs.account_number recipient_account,bs.bank_code recipient_bank,
                    EXISTS(SELECT 1 FROM accounting_periods ap WHERE ap.supplier_id=?
                           AND bt.posted_at BETWEEN ap.starts_on AND ap.ends_on AND ap.status <> 'open') period_locked,
                    EXISTS(SELECT 1 FROM bank_posting_suggestions bps WHERE bps.supplier_id=? AND bps.bank_transaction_id=bt.id
                           AND bps.status IN ('pending','needs_input','blocked','approved','auto_posted')) suggestion_exists,
                    EXISTS(SELECT 1 FROM journal_entries historical_je
                            WHERE historical_je.supplier_id=? AND historical_je.source_type='bank'
                              AND historical_je.source_id=bt.id) posting_history_exists,
                    hp.debit_code historical_debit_code,hp.credit_code historical_credit_code
              FROM bank_transactions bt JOIN bank_statements bs ON bs.id=bt.statement_id
              LEFT JOIN (
                    SELECT je.source_id,
                           MAX(CASE WHEN jel.side='debit' THEN coa.account_code END) debit_code,
                           MAX(CASE WHEN jel.side='credit' THEN coa.account_code END) credit_code
                      FROM journal_entries je
                      JOIN journal_entry_lines jel ON jel.entry_id=je.id AND jel.supplier_id=je.supplier_id
                      JOIN chart_of_accounts coa ON coa.id=jel.account_id AND coa.supplier_id=je.supplier_id
                     WHERE je.supplier_id=? AND je.source_type='bank'
                       AND je.posted_at IS NOT NULL AND je.reversed_by IS NULL
                     GROUP BY je.source_id
                    HAVING COUNT(DISTINCT jel.id)=2
              ) hp ON hp.source_id=bt.id
              WHERE bs.supplier_id=? AND bt.posted_at >= DATE_SUB(CURDATE(),INTERVAL ? MONTH)
                AND COALESCE(bt.currency,bs.currency,'CZK')='CZK'" . $historyFilter
        );
        $params = [$supplierId, $supplierId, $supplierId, $supplierId, $supplierId, $monthsBack];
        if (!$includePostedHistory) {
            $params[] = $supplierId;
        }
        $stmt->execute($params);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $clusters = [];
        $lockedPeriods = [];
        foreach ($transactions as $tx) {
            $key = $this->clusterKey($tx);
            if ($key === null) continue;
            $clusters[$key][] = $tx;
            if ($tx['historical_debit_code'] === null && !(bool) $tx['suggestion_exists'] && (bool) $tx['period_locked']) {
                $lockedPeriods[substr((string) $tx['posted_at'], 0, 7)] = true;
            }
        }

        $result = [];
        $covered = 0;
        foreach ($clusters as $key => $rows) {
            if (count($rows) < 2) continue;
            $covered += count($rows);
            usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['posted_at'], (string) $b['posted_at']) ?: ((int) $a['id'] <=> (int) $b['id']));
            $first = $rows[0];
            $posting = $includePostedHistory
                ? self::consistentHistoricalPosting($rows)
                : $this->historicalPostingByCounterparty($supplierId, $first);
            $recipient = $this->accountEndpoint($supplierId, (string) ($first['recipient_account'] ?? ''), isset($first['recipient_bank']) ? (string) $first['recipient_bank'] : null);
            $counterpartyEndpoint = $this->accountEndpoint(
                $supplierId,
                (string) ($first['counterparty_account'] ?? ''),
                isset($first['counterparty_bank']) ? (string) $first['counterparty_bank'] : null,
                isset($first['counterparty_name']) ? (string) $first['counterparty_name'] : null,
            );
            $incoming = (float) $first['amount'] >= 0;
            $flow = [
                'from' => $incoming ? $counterpartyEndpoint : $recipient,
                'to' => $incoming ? $recipient : $counterpartyEndpoint,
                'own_transfer' => $recipient['own'] && $counterpartyEndpoint['own'],
            ];
            if ($posting === null && $flow['own_transfer']) {
                $posting = $incoming ? ['debit' => '221', 'credit' => '261'] : ['debit' => '261', 'credit' => '221'];
            }
            $amounts = array_map(static fn (array $r): float => abs((float) $r['amount']), $rows);
            $prefix = mb_substr(BankMessageNormalizer::normalize((string) ($first['description'] ?? '')), 0, 40);
            $counterparty = AccountNumberNormalizer::canonical((string) ($first['counterparty_account'] ?? ''));
            $vs = VariableSymbolNormalizer::digits((string) ($first['variable_symbol'] ?? ''));
            $proposal = [
                'name' => $flow['own_transfer']
                    ? trim((string) (($flow['from']['label'] ?: $flow['from']['account_number']) . ' → ' . ($flow['to']['label'] ?: $flow['to']['account_number'])))
                    : trim((string) ($first['counterparty_name'] ?: $prefix ?: $key)),
                'direction' => (float) $first['amount'] >= 0 ? 'incoming' : 'outgoing',
                'counterparty_account' => $counterparty,
                'counterparty_bank' => AccountNumberNormalizer::canonicalBankCode((string) ($first['counterparty_bank'] ?? '')),
                'variable_symbol' => $counterparty === null && $vs !== '' ? $vs : null,
                'message_contains' => $counterparty === null && $vs === '' ? ($prefix !== '' ? $prefix : null) : null,
                'amount_min' => min($amounts), 'amount_max' => max($amounts),
                'debit_account_code' => $posting['debit'] ?? null,
                'credit_account_code' => $posting['credit'] ?? null,
                'description' => $first['description'] ?: null,
                'mode' => 'suggest',
                'operation_type' => $flow['own_transfer'] ? OperationType::BANK_TRANSFER_OWN : OperationType::BANK_RULE_CUSTOM,
                'own_transfer' => $flow['own_transfer'],
                'tx_ids' => array_map(
                    static fn (array $r): int => (int) $r['id'],
                    array_values(array_filter($rows, static fn (array $r): bool =>
                        !(bool) $r['posting_history_exists'] && !(bool) $r['suggestion_exists'])),
                ),
            ];
            if (!$flow['own_transfer']
                && $proposal['debit_account_code'] !== null
                && $proposal['credit_account_code'] !== null
                && $this->hasEquivalentActive($supplierId, $proposal)
            ) {
                continue;
            }
            $result[] = [
                'key' => $key, 'direction' => $proposal['direction'],
                'counterparty_account' => $counterparty, 'counterparty_bank' => $proposal['counterparty_bank'],
                'variable_symbol' => $proposal['variable_symbol'], 'message_prefix' => $proposal['message_contains'],
                'tx_count' => count($rows), 'first_seen' => (string) $first['posted_at'], 'last_seen' => (string) $rows[array_key_last($rows)]['posted_at'],
                'amount_min' => min($amounts), 'amount_max' => max($amounts),
                'template_key' => ($proposal['counterparty_bank'] === '0710' ? 'remit.social.own' : null),
                'flow' => $flow,
                'proposal' => $proposal,
                'sample' => array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'posted_at' => (string) $r['posted_at'], 'amount' => (float) $r['amount'], 'description' => $r['description']], array_slice($rows, 0, 5)),
            ];
        }
        usort($result, static fn (array $a, array $b): int => $b['tx_count'] <=> $a['tx_count'] ?: strcmp($a['key'], $b['key']));
        return [
            'analyzed_tx' => count($transactions),
            'coverage_pct' => $transactions === [] ? 0.0 : round($covered / count($transactions) * 100, 1),
            'clusters' => $result,
            'locked' => ['tx_count' => count(array_filter($transactions, static fn (array $r): bool =>
                $r['historical_debit_code'] === null && !(bool) $r['suggestion_exists'] && (bool) $r['period_locked'])), 'periods' => array_keys($lockedPeriods)],
        ];
    }

    /** @param list<array<string,mixed>> $payloads @return array<string,mixed> */
    public function apply(int $supplierId, int $userId, array $payloads, bool $backfill, bool $throwOnError = false): array
    {
        $created = 0; $existing = 0; $backfilled = 0; $locked = 0; $skipped = [];
        foreach ($payloads as $payload) {
            try {
                $data = $this->validate($supplierId, $payload);
                $ownTransfer = (bool) ($payload['own_transfer'] ?? false);
                $txIds = array_values(array_unique(array_filter(array_map('intval', (array) ($payload['tx_ids'] ?? [])), static fn (int $id): bool => $id > 0)));
                if (!$ownTransfer) {
                    if ($this->hasEquivalentActive($supplierId, $data)) {
                        $existing++;
                    } else {
                        $this->rules->insert($supplierId, $data, $userId);
                        $created++;
                    }
                }
                if ($backfill) {
                    foreach (array_slice($txIds, 0, 200) as $txId) {
                        $tx = $this->transaction($supplierId, $txId);
                        if ($tx === null) continue;
                        $period = $this->periods->findForDate($supplierId, (string) $tx['posted_at']);
                        if ($period !== null && $period['status'] !== 'open') { $locked++; continue; }
                        $result = $ownTransfer
                            ? $this->posting->handleTransaction($txId, $userId, true, $supplierId)
                            : $this->posting->applyRules($supplierId, $txId, $userId, true);
                        if (($result['action'] ?? null) === 'suggested' && ($result['created'] ?? true)) $backfilled++;
                    }
                }
            } catch (\Throwable $e) {
                if ($throwOnError) {
                    throw $e;
                }
                $skipped[] = ['name' => (string) ($payload['name'] ?? ''), 'code' => $e->getMessage()];
            }
        }
        return ['created' => $created, 'existing' => $existing, 'skipped' => $skipped, 'backfilled' => $backfilled, 'locked_skipped' => $locked];
    }

    /** @param array<string,mixed> $proposal */
    public function hasEquivalentActive(int $supplierId, array $proposal): bool
    {
        $direction = trim((string) ($proposal['direction'] ?? ''));
        if (!in_array($direction, ['incoming', 'outgoing'], true)) {
            return false;
        }
        foreach ($this->rules->findActive($supplierId, $direction) as $existing) {
            if (AccountingRuleEquivalence::bank($existing, $proposal)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $tx */
    private function clusterKey(array $tx): ?string
    {
        $direction = (float) $tx['amount'] >= 0 ? 'in' : 'out';
        $account = AccountNumberNormalizer::canonical((string) ($tx['counterparty_account'] ?? ''));
        $bank = AccountNumberNormalizer::canonicalBankCode((string) ($tx['counterparty_bank'] ?? ''));
        if ($account !== null) return $direction . ':account:' . $account . ':' . ($bank ?? '');
        $vs = VariableSymbolNormalizer::digits((string) ($tx['variable_symbol'] ?? ''));
        if ($vs !== '') return $direction . ':vs:' . $vs;
        $message = mb_substr(BankMessageNormalizer::normalize((string) ($tx['description'] ?? '')), 0, 40);
        return mb_strlen($message) >= 4 ? $direction . ':message:' . $message : null;
    }

    /** @param list<array<string,mixed>> $rows @return array{debit:string,credit:string}|null */
    private static function consistentHistoricalPosting(array $rows): ?array
    {
        $pairs = [];
        foreach ($rows as $row) {
            $debit = trim((string) ($row['historical_debit_code'] ?? ''));
            $credit = trim((string) ($row['historical_credit_code'] ?? ''));
            if ($debit !== '' && $credit !== '') {
                $pairs[$debit . '/' . $credit] = true;
            }
        }
        if (count($pairs) !== 1) return null;
        [$debit, $credit] = explode('/', array_key_first($pairs), 2);
        return ['debit' => $debit, 'credit' => $credit];
    }

    /** @param array<string,mixed> $tx @return array{debit:string,credit:string}|null */
    private function historicalPostingByCounterparty(int $supplierId, array $tx): ?array
    {
        $account = AccountNumberNormalizer::canonical((string) ($tx['counterparty_account'] ?? ''));
        if ($account === null) return null;
        $stmt = $this->db->pdo()->prepare(
            "SELECT MAX(CASE WHEN jel.side='debit' THEN coa.account_code END) debit_code,
                    MAX(CASE WHEN jel.side='credit' THEN coa.account_code END) credit_code,
                    COUNT(DISTINCT jel.id) line_count
               FROM bank_transactions bt JOIN journal_entries je ON je.supplier_id=? AND je.source_type='bank' AND je.source_id=bt.id
               JOIN journal_entry_lines jel ON jel.entry_id=je.id JOIN chart_of_accounts coa ON coa.id=jel.account_id
              WHERE REGEXP_REPLACE(bt.counterparty_account,'[^0-9]','') LIKE ? GROUP BY je.id HAVING line_count=2"
        );
        $stmt->execute([$supplierId, '%' . $account]);
        $pairs = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pairs[(string) $row['debit_code'] . '/' . (string) $row['credit_code']] = true;
        }
        if (count($pairs) !== 1) return null;
        [$debit, $credit] = explode('/', array_key_first($pairs), 2);
        return ['debit' => $debit, 'credit' => $credit];
    }

    /** @return array{label:?string,account_number:?string,bank_code:?string,own:bool} */
    private function accountEndpoint(int $supplierId, string $accountNumber, ?string $bankCode, ?string $fallbackLabel = null): array
    {
        $accountNumber = trim($accountNumber);
        $bankCode = AccountNumberNormalizer::canonicalBankCode($bankCode, $accountNumber);
        $own = $accountNumber !== '' ? $this->bankAccounts->matchCounterparty($supplierId, $accountNumber, $bankCode) : null;
        if ($own !== null) {
            return [
                'label' => trim((string) ($own['label'] ?? '')) ?: null,
                'account_number' => trim((string) ($own['account_number'] ?? $accountNumber)) ?: null,
                'bank_code' => trim((string) ($own['bank_code'] ?? $bankCode)) ?: null,
                'own' => true,
            ];
        }

        $currency = null;
        if ($accountNumber !== '') {
            $stmt = $this->db->pdo()->prepare(
                'SELECT label,account_number,bank_code,iban FROM currencies
                  WHERE supplier_id=? AND (account_number IS NOT NULL OR iban IS NOT NULL)'
            );
            $stmt->execute([$supplierId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
                if (AccountNumberNormalizer::matchesAny($accountNumber, $candidate['account_number'], $candidate['iban'])
                    && ($bankCode === null || $candidate['bank_code'] === null
                        || $bankCode === AccountNumberNormalizer::canonicalBankCode((string) $candidate['bank_code']))) {
                    $currency = $candidate;
                    break;
                }
            }
        }
        return [
            'label' => $currency !== null ? (trim((string) $currency['label']) ?: null) : (trim((string) $fallbackLabel) ?: null),
            'account_number' => $currency !== null
                ? (trim((string) ($currency['account_number'] ?: $currency['iban'])) ?: null)
                : ($accountNumber !== '' ? $accountNumber : null),
            'bank_code' => $currency !== null
                ? (trim((string) ($currency['bank_code'] ?? $bankCode)) ?: null)
                : $bankCode,
            'own' => $currency !== null,
        ];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function validate(int $supplierId, array $payload): array
    {
        $name = trim((string) ($payload['name'] ?? ''));
        $direction = (string) ($payload['direction'] ?? '');
        $debit = trim((string) ($payload['debit_account_code'] ?? ''));
        $credit = trim((string) ($payload['credit_account_code'] ?? ''));
        if ($name === '' || !in_array($direction, ['incoming', 'outgoing'], true)) throw new \InvalidArgumentException('rule_criteria_missing');
        if ($debit === '' || $credit === '' || in_array($debit, self::SALDO_ACCOUNTS, true) || in_array($credit, self::SALDO_ACCOUNTS, true)) throw new \InvalidArgumentException('saldo_forbidden');
        $criteria = ['counterparty_account', 'counterparty_bank', 'variable_symbol', 'message_contains'];
        if (!array_filter($criteria, static fn (string $key): bool => trim((string) ($payload[$key] ?? '')) !== '')) throw new \InvalidArgumentException('rule_criteria_missing');
        $check = $this->db->pdo()->prepare('SELECT COUNT(*) FROM chart_of_accounts WHERE supplier_id=? AND account_code IN (?,?) AND is_active=1');
        $check->execute([$supplierId, $debit, $credit]);
        if ((int) $check->fetchColumn() !== 2) throw new \InvalidArgumentException('account_not_found');
        return [
            'name' => mb_substr($name, 0, 120), 'direction' => $direction,
            'counterparty_account' => trim((string) ($payload['counterparty_account'] ?? '')) ?: null,
            'counterparty_bank' => trim((string) ($payload['counterparty_bank'] ?? '')) ?: null,
            'variable_symbol' => trim((string) ($payload['variable_symbol'] ?? '')) ?: null,
            'message_contains' => ($m = BankMessageNormalizer::normalize((string) ($payload['message_contains'] ?? ''))) !== '' ? $m : null,
            'amount_min' => isset($payload['amount_min']) ? abs((float) $payload['amount_min']) : null,
            'amount_max' => isset($payload['amount_max']) ? abs((float) $payload['amount_max']) : null,
            'debit_account_code' => $debit, 'credit_account_code' => $credit,
            'description' => trim((string) ($payload['description'] ?? '')) ?: null,
            'mode' => 'suggest', 'is_active' => true, 'priority' => 100,
            'operation_type' => (bool) ($payload['own_transfer'] ?? false)
                ? OperationType::BANK_TRANSFER_OWN : OperationType::BANK_RULE_CUSTOM,
            'applies_currency' => 'CZK',
        ];
    }

    /** @return array<string,mixed>|null */
    private function transaction(int $supplierId, int $txId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT bt.*,bs.account_number recipient_account,bs.bank_code recipient_bank
              FROM bank_transactions bt JOIN bank_statements bs ON bs.id=bt.statement_id
              WHERE bt.id=? AND bs.supplier_id=?
                AND NOT EXISTS(SELECT 1 FROM journal_entries je WHERE je.supplier_id=? AND je.source_type='bank' AND je.source_id=bt.id)"
        );
        $stmt->execute([$txId, $supplierId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row && $this->statementBelongsToSupplier($supplierId, $row) ? $row : null;
    }
}
