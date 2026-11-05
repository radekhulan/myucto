<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Learning;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankPostingRuleRepository;
use MyInvoice\Service\Accounting\Bank\BankMessageNormalizer;
use MyInvoice\Service\Accounting\Bank\BankRuleMatcher;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Bank\AccountNumberNormalizer;
use MyInvoice\Service\Bank\VariableSymbolNormalizer;
use PDO;

final class RuleMiner
{
    private const SALDO_BLACKLIST = ['311', '321', '314', '324', '325'];

    public function __construct(
        private readonly Connection $db,
        private readonly BankPostingRuleRepository $rules,
        private readonly BankRuleMatcher $matcher,
        private readonly CorrectionRecorder $corrections,
        private readonly ActivityLogger $activity,
    ) {}

    /** @return array{clusters:int,proposed:int,created:int,skipped:int,skip_reasons:array<string,int>,proposals:list<array<string,mixed>>} */
    public function run(?int $supplierId = null, int $days = 180, bool $apply = false): array
    {
        $days = max(1, min(3650, $days));
        $where = $supplierId === null ? '' : ' AND c.supplier_id = ?';
        $stmt = $this->db->pdo()->prepare(
            "SELECT c.supplier_id, c.entity_id AS tx_id, c.final_debit, c.final_credit,
                    bt.amount, bt.counterparty_account, bt.counterparty_bank, bt.counterparty_name,
                    bt.variable_symbol, bt.description, bs.account_number AS recipient_account, bs.bank_code AS recipient_bank
               FROM accounting_corrections c
               JOIN bank_transactions bt ON bt.id=c.entity_id
               JOIN bank_statements bs ON bs.id=bt.statement_id
               JOIN supplier s ON s.id=c.supplier_id AND s.accounting_mode='double_entry'
              WHERE c.entity_type='bank_transaction'
                AND c.event_type IN ('approve_override','manual_post')
                AND c.final_debit IS NOT NULL AND c.final_credit IS NOT NULL
                AND c.created_at >= (NOW() - INTERVAL {$days} DAY){$where}
              ORDER BY c.supplier_id, c.entity_id, c.id"
        );
        $stmt->execute($supplierId === null ? [] : [$supplierId]);

        $clusters = [];
        $ownAccounts = new \MyInvoice\Service\Accounting\Bank\StatementOwnAccount($this->db);
        $noAccount = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $account = AccountNumberNormalizer::normalize((string) ($row['counterparty_account'] ?? ''));
            if ($account === '') {
                $noAccount++;
                continue;
            }
            $bank = AccountNumberNormalizer::canonicalBankCode((string) ($row['counterparty_bank'] ?? '')) ?? '';
            $direction = (float) $row['amount'] > 0 ? 'incoming' : 'outgoing';
            $key = sprintf('%010d|%s|%s|%s', (int) $row['supplier_id'], $direction, $account, $bank);
            $row['normalized_account'] = $account;
            $row['normalized_bank'] = $bank;
            $row['direction'] = $direction;
            $clusters[$key][] = $row;
        }
        ksort($clusters, SORT_STRING);

        $report = [
            'clusters' => count($clusters), 'proposed' => 0, 'created' => 0,
            'skipped' => 0, 'skip_reasons' => [], 'proposals' => [],
        ];
        if ($noAccount > 0) $report['skip_reasons']['no_account'] = $noAccount;

        foreach ($clusters as $rows) {
            $txs = [];
            $kontace = [];
            foreach ($rows as $row) {
                $txs[(int) $row['tx_id']] = $row;
                $kontace[(string) $row['final_debit'] . '/' . (string) $row['final_credit']] = true;
            }
            $rows = array_values($txs);
            if (count($rows) < 3) {
                $this->skip($report, 'too_few');
                continue;
            }
            if (count($kontace) !== 1) {
                $this->skip($report, 'contradiction');
                continue;
            }
            $representative = $rows[0];
            $debit = (string) $representative['final_debit'];
            $credit = (string) $representative['final_credit'];
            if (!$this->safeAccounts((string) $representative['direction'], $debit, $credit)) {
                $this->skip($report, 'blacklist');
                continue;
            }
            $tenant = (int) $representative['supplier_id'];
            // Shluk může mít pohyby z více vlastních účtů. Pravidlo omezené na účet ho pokrývá,
            // jen když leží všechny na jeho účtu; posuzuje se proto každý účet shluku zvlášť.
            $ownIds = [];
            foreach ($rows as $row) {
                $ownIds[(int) ($ownAccounts->idFor($tenant, $row) ?? 0)] = true;
            }
            $ownIds = array_keys($ownIds);
            $txs = array_map(
                fn (int $own): array => $this->matchTx($representative) + [BankRuleMatcher::OWN_ACCOUNT => $own > 0 ? $own : null],
                $ownIds,
            );
            $existing = $this->rules->listForTenant($tenant);
            if ($this->exactRuleExists($existing, $representative, $debit, $credit, $ownIds)) {
                $this->skip($report, 'rule_exists');
                continue;
            }
            $covered = false;
            foreach ($existing as $rule) {
                if ((bool) $rule['is_active']
                    && array_filter($txs, fn (array $tx): bool => !$this->matcher->matching($rule, $tx)) === []) {
                    $covered = true;
                    break;
                }
            }
            if ($covered) {
                $this->skip($report, 'covered_by_rule');
                continue;
            }

            $amounts = array_map(static fn (array $row): float => abs((float) $row['amount']), $rows);
            $symbols = array_values(array_unique(array_map(
                static fn (array $row): string => VariableSymbolNormalizer::digits((string) ($row['variable_symbol'] ?? '')),
                $rows,
            )));
            $messages = array_map(static fn (array $row): string => BankMessageNormalizer::normalize((string) ($row['description'] ?? '')), $rows);
            $data = [
                'name' => trim((string) ($representative['counterparty_name'] ?? '')) ?: 'Naučeno z korekcí',
                'direction' => (string) $representative['direction'],
                'counterparty_account' => (string) $representative['normalized_account'],
                'counterparty_bank' => (string) $representative['normalized_bank'] ?: null,
                'variable_symbol' => count($symbols) === 1 && $symbols[0] !== '' ? $symbols[0] : null,
                'message_contains' => $this->commonPrefix($messages),
                'amount_min' => floor(min($amounts) * 0.9),
                'amount_max' => ceil(max($amounts) * 1.1),
                'debit_account_code' => $debit,
                'credit_account_code' => $credit,
                'description' => 'mined_from_corrections',
                'mode' => 'suggest',
            ];
            $report['proposed']++;
            $proposal = ['supplier_id' => $tenant, 'transactions' => count($rows)] + $data;
            if ($apply) {
                $ruleId = $this->insert($tenant, $data);
                $proposal['rule_id'] = $ruleId;
                $report['created']++;
            }
            $report['proposals'][] = $proposal;
        }
        ksort($report['skip_reasons'], SORT_STRING);
        return $report;
    }

    /** @param array<string,mixed> $data */
    private function insert(int $supplierId, array $data): int
    {
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();
        try {
            $id = $this->rules->insert($supplierId, $data, null);
            $this->corrections->ruleEvent($supplierId, 'rule_mined', $id, null, 'mined_from_corrections');
            $this->activity->log('bank_rule.mined', null, 'bank_posting_rule', $id, ['source' => 'corrections'], supplierId: $supplierId);
            if ($own) $pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @param list<array<string,mixed>> $rules
     * @param array<string,mixed> $row
     * @param list<int> $ownIds vlastní účty pohybů shluku (0 = výpis bez vlastního účtu)
     */
    private function exactRuleExists(array $rules, array $row, string $debit, string $credit, array $ownIds): bool
    {
        foreach ($rules as $rule) {
            $ruleAccount = (int) ($rule['bank_account_id'] ?? 0);
            if (($ruleAccount === 0 || $ownIds === [$ruleAccount])
                && (string) $rule['direction'] === (string) $row['direction']
                && AccountNumberNormalizer::equals((string) ($rule['counterparty_account'] ?? ''), (string) $row['normalized_account'])
                && (AccountNumberNormalizer::canonicalBankCode((string) ($rule['counterparty_bank'] ?? '')) ?? '') === (string) $row['normalized_bank']
                && (string) $rule['debit_account_code'] === $debit
                && (string) $rule['credit_account_code'] === $credit) return true;
        }
        return false;
    }

    private function safeAccounts(string $direction, string $debit, string $credit): bool
    {
        foreach (self::SALDO_BLACKLIST as $prefix) {
            if (str_starts_with($debit, $prefix) || str_starts_with($credit, $prefix)) return false;
        }
        return str_starts_with($direction === 'incoming' ? $debit : $credit, '221');
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function matchTx(array $row): array
    {
        return [
            'amount' => (float) $row['amount'], 'variable_symbol' => $row['variable_symbol'],
            'counterparty_account' => $row['counterparty_account'], 'counterparty_bank' => $row['counterparty_bank'],
            'description' => $row['description'],
        ];
    }

    /** @param list<string> $messages */
    private function commonPrefix(array $messages): ?string
    {
        $messages = array_values(array_filter($messages, static fn (string $message): bool => $message !== ''));
        if ($messages === []) return null;
        $prefix = array_shift($messages);
        foreach ($messages as $message) {
            $length = min(strlen($prefix), strlen($message));
            $i = 0;
            while ($i < $length && $prefix[$i] === $message[$i]) $i++;
            $prefix = substr($prefix, 0, $i);
            if ($prefix === '') break;
        }
        $prefix = trim($prefix);
        return strlen($prefix) >= 4 ? $prefix : null;
    }

    /** @param array<string,mixed> $report */
    private function skip(array &$report, string $reason): void
    {
        $report['skipped']++;
        $report['skip_reasons'][$reason] = ($report['skip_reasons'][$reason] ?? 0) + 1;
    }
}
