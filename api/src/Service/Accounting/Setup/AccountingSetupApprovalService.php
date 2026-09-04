<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Setup;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingSetupRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\ExpenseClassificationRuleRepository;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Service\Accounting\Expense\ExpenseKind;
use MyInvoice\Service\Automation\RuleProposalService;

final class AccountingSetupApprovalService
{
    public function __construct(
        private readonly Connection $db,
        private readonly AccountingSetupRepository $setup,
        private readonly ChartOfAccountsRepository $chart,
        private readonly ExpenseClassificationRuleRepository $expenseRules,
        private readonly PostingRuleRepository $postingRules,
        private readonly RuleProposalService $bankRules,
    ) {}

    public function approve(int $supplierId, int $runId, array $proposalIds, int $userId): array
    {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $bundle = $this->setup->approve(
                $supplierId,
                $runId,
                $proposalIds,
                $userId,
                $this->expenseRules->activeFor($supplierId),
            );
            $chartCreated = 0;
            $chartPayloads = [];
            foreach ($bundle['payload'] as $item) {
                if (($item['type'] ?? '') !== 'chart_account') {
                    continue;
                }
                $proposal = (array) ($item['proposal'] ?? []);
                if (($proposal['create'] ?? true) === false) {
                    continue;
                }
                $code = trim((string) ($proposal['account_code'] ?? ''));
                $parentCode = trim((string) ($proposal['parent_account_code'] ?? ''));
                $name = trim((string) ($proposal['name'] ?? ''));
                if ($code === '' || $parentCode === '' || $name === '') {
                    throw new \RuntimeException('invalid_chart_account_proposal');
                }
                $existing = $this->chart->findByCode($supplierId, $code);
                if ($existing !== null) {
                    $parent = $this->chart->findByCode($supplierId, $parentCode);
                    if (empty($existing['is_active']) || !empty($existing['is_synthetic'])
                        || (string) ($existing['name'] ?? '') !== $name || $parent === null
                        || (int) ($existing['parent_id'] ?? 0) !== (int) $parent['id']) {
                        throw new \RuntimeException('chart_account_changed');
                    }
                    continue;
                }
                $parent = $this->chart->findByCode($supplierId, $parentCode);
                if ($parent === null || empty($parent['is_active']) || empty($parent['is_synthetic'])) {
                    throw new \RuntimeException('chart_is_no_longer_flat');
                }
                $chartPayloads[] = [$proposal, $parent, $code, $name];
            }
            foreach ($chartPayloads as [$proposal, $parent, $code, $name]) {
                $this->chart->insert($supplierId, [
                    'account_code' => $code,
                    'name' => mb_substr($name, 0, 160),
                    'account_type' => (string) $parent['account_type'],
                    'normal_side' => $parent['normal_side'],
                    'is_synthetic' => false,
                    'parent_id' => (int) $parent['id'],
                    'is_active' => true,
                ]);
                $chartCreated++;
            }

            $expenseCreated = 0;
            $postingCreated = 0;
            $bankPayloads = [];
            foreach ($bundle['payload'] as $item) {
                $proposal = (array) ($item['proposal'] ?? []);
                if (($item['type'] ?? '') === 'expense_rule') {
                    if (($item['source'] ?? null) === 'existing') {
                        continue;
                    }
                    $this->requireActiveAnalyticAccount($supplierId, $proposal['target_account_code'] ?? null);
                    $proposal['name'] = mb_substr((string) ($proposal['name'] ?? 'Pravidlo'), 0, 120);
                    $proposal['application_mode'] = 'suggest';
                    $proposal['is_active'] = true;
                    if (!$this->hasEquivalentExpenseRule($supplierId, $proposal)) {
                        $this->expenseRules->insert($supplierId, $proposal, $userId);
                        $expenseCreated++;
                    }
                } elseif (($item['type'] ?? '') === 'posting_rule') {
                    $debit = trim((string) ($proposal['debit_account_code'] ?? ''));
                    $credit = trim((string) ($proposal['credit_account_code'] ?? ''));
                    $ruleKey = trim((string) ($proposal['rule_key'] ?? ''));
                    if ($ruleKey === '') {
                        throw new \RuntimeException('invalid_posting_rule_proposal');
                    }
                    if (in_array($ruleKey, array_map(
                        static fn (ExpenseKind $kind): string => $kind->ruleKey(),
                        ExpenseKind::cases(),
                    ), true)) {
                        $this->requireActiveAnalyticAccount($supplierId, $debit);
                    } else {
                        $this->requireActiveAccount($supplierId, $debit);
                    }
                    $this->requireActiveAccount($supplierId, $credit);
                    $this->postingRules->upsertOverride(
                        $supplierId,
                        $ruleKey,
                        $debit,
                        $credit,
                        mb_substr((string) ($proposal['description'] ?? $ruleKey), 0, 255),
                    );
                    $postingCreated++;
                } elseif (($item['type'] ?? '') === 'bank_rule') {
                    $proposal['mode'] = 'suggest';
                    $bankPayloads[] = $proposal;
                }
            }
            $bank = $bankPayloads === []
                ? ['created' => 0, 'skipped' => [], 'backfilled' => 0, 'locked_skipped' => 0]
                : $this->bankRules->apply($supplierId, $userId, $bankPayloads, false, true);
            if ($ownTx) {
                $pdo->commit();
            }
            return $bundle + [
                'expense_rules_created' => $expenseCreated,
                'chart_accounts_created' => $chartCreated,
                'posting_rules_created' => $postingCreated,
                'bank_rules_created' => (int) ($bank['created'] ?? 0),
                'bank_rules_skipped' => (array) ($bank['skipped'] ?? []),
            ];
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function requireActiveAccount(int $supplierId, mixed $accountCode): void
    {
        $code = trim((string) $accountCode);
        if ($code === '') {
            throw new \RuntimeException('target_account_not_postable');
        }
        $account = $this->chart->findByCode($supplierId, $code);
        if ($account === null || empty($account['is_active'])) {
            throw new \RuntimeException('target_account_not_postable');
        }
    }

    private function requireActiveAnalyticAccount(int $supplierId, mixed $accountCode): void
    {
        $code = trim((string) $accountCode);
        $account = $code === '' ? null : $this->chart->findByCode($supplierId, $code);
        if ($account === null || empty($account['is_active']) || !empty($account['is_synthetic'])) {
            throw new \RuntimeException('target_account_not_analytic');
        }
    }

    /** @param array<string,mixed> $proposal */
    private function hasEquivalentExpenseRule(int $supplierId, array $proposal): bool
    {
        foreach ($this->expenseRules->activeFor($supplierId) as $existing) {
            if (AccountingRuleEquivalence::expense($existing, $proposal)) {
                return true;
            }
        }
        return false;
    }
}
