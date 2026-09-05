<?php

declare(strict_types=1);

namespace MyInvoice\Service\Automation;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankPostingRuleRepository;
use MyInvoice\Repository\ExpenseKeywordCatalogRepository;
use MyInvoice\Repository\ExpenseClassificationRuleRepository;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Service\Accounting\Bank\BankMessageNormalizer;
use MyInvoice\Service\Accounting\Expense\ExpenseClassificationService;
use MyInvoice\Service\Accounting\Expense\ExpenseKind;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Bank\AccountNumberNormalizer;
use MyInvoice\Service\Bank\VariableSymbolNormalizer;
use PDO;

final class AutomationRecommendationService
{
    private const TYPES = ['post_invoice', 'post_purchase', 'classify_purchase', 'bank_rule'];
    private const SALDO_ACCOUNTS = ['311', '321', '314', '324', '325'];

    /** @var array<int,array<string,array<string,mixed>>> */
    private array $postingRules = [];

    public function __construct(
        private readonly Connection $db,
        private readonly AutomationFeedService $feed,
        private readonly PostingService $posting,
        private readonly ExpenseClassificationService $classification,
        private readonly PostingRuleRepository $rules,
        private readonly ExpenseKeywordCatalogRepository $catalog,
        private readonly RuleProposalService $ruleProposals,
        private readonly BankPostingRuleRepository $bankRules,
        private readonly ExpenseClassificationRuleRepository $expenseRules,
    ) {}

    /**
     * @param array{suppliers?:list<int>,from?:?string,to?:?string,type?:?string,page?:int,per_page?:int} $filters
     * @return array{items:list<array<string,mixed>>,summary:array{sales:int,purchases:int,bank:int},total:int,page:int,per_page:int}
     */
    public function recommendations(int $userId, bool $isSuperadmin, array $filters = []): array
    {
        $allowed = $this->feed->allowedSupplierIds($userId, $isSuperadmin);
        $requested = array_map('intval', $filters['suppliers'] ?? []);
        $supplierIds = $requested === [] ? $allowed : array_values(array_intersect($allowed, $requested));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(200, (int) ($filters['per_page'] ?? 50)));
        if ($supplierIds === []) {
            return ['items' => [], 'summary' => ['sales' => 0, 'purchases' => 0, 'bank' => 0], 'total' => 0, 'page' => $page, 'per_page' => $perPage];
        }

        $items = $this->collectItems($supplierIds, $filters['from'] ?? null, $filters['to'] ?? null);
        $summary = $this->coverageCounts($supplierIds, $filters['from'] ?? null, $filters['to'] ?? null);
        $type = $filters['type'] ?? null;
        if (is_string($type) && in_array($type, self::TYPES, true)) {
            $items = array_values(array_filter($items, static fn (array $item): bool => $item['type'] === $type));
        }
        usort($items, static fn (array $a, array $b): int => strcmp((string) $b['date'], (string) $a['date']) ?: strcmp((string) $b['id'], (string) $a['id']));

        $total = count($items);
        return [
            'items' => array_slice($items, ($page - 1) * $perPage, $perPage),
            'summary' => $summary,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /** @return array{items:list<array<string,mixed>>,coverage:array<string,array{sales:int,purchases:int,bank:int}>} */
    public function snapshotForSupplier(int $supplierId, ?callable $progress = null): array
    {
        $items = $this->collectItems([$supplierId], null, null, $progress);
        if ($progress !== null) $progress('coverage', 4);
        $coverage = $this->coverageByDate([$supplierId], null, null);
        return ['items' => $items, 'coverage' => $coverage];
    }

    /** @param list<int> $supplierIds @return list<array<string,mixed>> */
    private function collectItems(array $supplierIds, ?string $from, ?string $to, ?callable $progress = null): array
    {
        $this->postingRules = [];
        $names = $this->supplierNames($supplierIds);
        if ($progress !== null) $progress('invoices', 0);
        $items = $this->postingItems($supplierIds, $names, false, $from, $to);
        if ($progress !== null) $progress('purchases', 1);
        $items = array_merge($items, $this->postingItems($supplierIds, $names, true, $from, $to));
        if ($progress !== null) $progress('expense_rules', 2);
        $items = array_merge($items, $this->expenseRuleItems($supplierIds, $names, $from, $to));
        if ($progress !== null) $progress('bank_rules', 3);
        return array_merge($items, $this->bankRuleItems($supplierIds, $names, $from, $to));
    }

    /** @param list<int> $supplierIds @param array<int,string> $names @return list<array<string,mixed>> */
    private function postingItems(array $supplierIds, array $names, bool $purchase, ?string $from, ?string $to): array
    {
        [$in, $params] = $this->inClause($supplierIds);
        $alias = $purchase ? 'pi' : 'i';
        $table = $purchase ? 'purchase_invoices' : 'invoices';
        $sourceType = $purchase ? 'purchase_invoice' : 'invoice';
        $date = "COALESCE({$alias}.tax_date,{$alias}.issue_date)";
        $where = [
            "{$alias}.supplier_id IN ({$in})",
            "{$alias}.booked_at IS NULL",
            "{$alias}.status NOT IN ('draft','cancelled')",
            "NOT EXISTS (SELECT 1 FROM journal_entries je WHERE je.supplier_id={$alias}.supplier_id AND je.source_type='{$sourceType}' AND je.source_id={$alias}.id AND je.posted_at IS NOT NULL AND je.reversed_by IS NULL)",
            "EXISTS (SELECT 1 FROM accounting_periods ap WHERE ap.supplier_id={$alias}.supplier_id AND {$date} BETWEEN ap.starts_on AND ap.ends_on AND ap.status='open')",
            "NOT EXISTS (SELECT 1 FROM accounting_supplier_settings aset WHERE aset.supplier_id={$alias}.supplier_id AND aset.locked_until IS NOT NULL AND {$date} <= aset.locked_until)",
        ];
        $where[] = $purchase
            ? "{$alias}.document_kind NOT IN ('advance','tax_document')"
            : "{$alias}.invoice_type IN ('invoice','credit_note','penalty')";
        if ($from !== null) {
            $where[] = "{$date} >= ?";
            $params[] = $from;
        }
        if ($to !== null) {
            $where[] = "{$date} <= ?";
            $params[] = $to;
        }

        $number = $purchase ? 'pi.vendor_invoice_number' : 'i.varsymbol';
        $clientJoin = $purchase
            ? 'LEFT JOIN clients c ON c.id=pi.vendor_id AND c.supplier_id=pi.supplier_id'
            : 'LEFT JOIN clients c ON c.id=i.client_id AND c.supplier_id=i.supplier_id';
        $stmt = $this->db->pdo()->prepare(
            "SELECT {$alias}.id,{$alias}.supplier_id,{$date} doc_date,{$number} document_no,
                    {$alias}.total_with_vat,cur.code currency,c.company_name counterparty," .
                    ($purchase ? 'pi.vendor_id' : 'NULL') . " vendor_id
               FROM {$table} {$alias}
               JOIN currencies cur ON cur.id={$alias}.currency_id AND cur.supplier_id={$alias}.supplier_id
               {$clientJoin}
              WHERE " . implode(' AND ', $where) . "
              ORDER BY {$date} DESC,{$alias}.id DESC"
        );
        $stmt->execute($params);

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $supplierId = (int) $row['supplier_id'];
            $documentId = (int) $row['id'];
            try {
                $lines = $purchase
                    ? $this->posting->buildFromPurchaseInvoice($supplierId, $documentId)
                    : $this->posting->buildFromInvoice($supplierId, $documentId);
                $lines = self::normalizeLines($lines);
            } catch (\Throwable) {
                continue;
            }
            if ($lines === []) {
                continue;
            }

            $type = $purchase ? 'post_purchase' : 'post_invoice';
            $items[] = [
                'id' => $type . ':' . $supplierId . ':' . $documentId,
                'type' => $type,
                'supplier_id' => $supplierId,
                'supplier_name' => $names[$supplierId] ?? '',
                'vendor_id' => $purchase ? (int) $row['vendor_id'] : null,
                'document_id' => $documentId,
                'item_id' => null,
                'statement_id' => null,
                'transaction_id' => null,
                'date' => (string) $row['doc_date'],
                'document_no' => $row['document_no'] === null ? null : (string) $row['document_no'],
                'description' => $row['document_no'] === null ? null : (string) $row['document_no'],
                'counterparty' => $row['counterparty'] === null ? null : (string) $row['counterparty'],
                'amount' => (float) $row['total_with_vat'],
                'currency' => (string) $row['currency'],
                'booked' => false,
                'period_closed' => false,
                'current_account_code' => null,
                'suggested_account_code' => self::primaryAccount($lines, $purchase ? 'debit' : 'credit'),
                'expense_kind' => null,
                'current_expense_kind' => null,
                'confidence' => 1.0,
                'reason' => 'document_not_posted',
                'source' => 'posting_service',
                'action' => 'post_document',
                'occurrence_count' => 1,
                'samples' => [],
                'rule_payload' => null,
                'lines' => $lines,
                'preview_error' => null,
            ];
        }
        return $items;
    }

    /** @param list<int> $supplierIds @param array<int,string> $names @return list<array<string,mixed>> */
    private function expenseRuleItems(array $supplierIds, array $names, ?string $from, ?string $to): array
    {
        [$in, $params] = $this->inClause($supplierIds);
        $date = 'COALESCE(pi.tax_date,pi.issue_date)';
        $where = [
            "pi.supplier_id IN ({$in})",
            "pi.status NOT IN ('draft','cancelled')",
            "pi.document_kind NOT IN ('advance','tax_document')",
            'pi.is_fixed_asset=0',
            'pii.is_fixed_asset=0',
            'NOT EXISTS (SELECT 1 FROM purchase_invoice_vat_allocations piva WHERE piva.supplier_id=pi.supplier_id AND piva.purchase_invoice_id=pi.id)',
        ];
        if ($from !== null) {
            $where[] = "{$date} >= ?";
            $params[] = $from;
        }
        if ($to !== null) {
            $where[] = "{$date} <= ?";
            $params[] = $to;
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT pii.id item_id,pii.purchase_invoice_id,pii.description,pii.unit_price_without_vat,
                    pii.total_with_vat,pi.supplier_id,pi.vendor_id,pi.exchange_rate,
                    pi.vendor_invoice_number,{$date} doc_date,YEAR({$date}) acq_year,
                    cur.code currency,c.company_name counterparty
               FROM purchase_invoice_items pii
               JOIN purchase_invoices pi ON pi.id=pii.purchase_invoice_id
               JOIN currencies cur ON cur.id=pi.currency_id AND cur.supplier_id=pi.supplier_id
          LEFT JOIN clients c ON c.id=pi.vendor_id AND c.supplier_id=pi.supplier_id
              WHERE " . implode(' AND ', $where) . "
              ORDER BY {$date} DESC,pi.id DESC,pii.order_index,pii.id"
        );
        $stmt->execute($params);

        $catalog = $this->catalog->active();
        $activeRules = [];
        $groups = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $description = trim((string) $row['description']);
            $normalized = BankMessageNormalizer::normalizeKeepDigits($description);
            if (mb_strlen($normalized) < 4) {
                continue;
            }
            $supplierId = (int) $row['supplier_id'];
            $vendorId = $row['vendor_id'] === null ? null : (int) $row['vendor_id'];
            $rate = (float) ($row['exchange_rate'] ?? 0);
            $suggestion = $this->classification->suggestForItem(
                $supplierId,
                $description,
                $row['counterparty'] === null ? null : (string) $row['counterparty'],
                $vendorId,
                abs((float) $row['unit_price_without_vat']) * ($rate > 0 ? $rate : 1.0),
                (int) $row['acq_year'],
            );
            if ($suggestion === null || $suggestion->nonDeductible || $suggestion->kind === ExpenseKind::FixedAsset) {
                continue;
            }

            $existingRule = null;
            if ($suggestion->source === 'rule') {
                $activeRules[$supplierId] ??= $this->expenseRules->activeFor($supplierId);
                foreach ($activeRules[$supplierId] as $rule) {
                    $match = $this->classification->suggestFromRules(
                        $supplierId, $description, $row['counterparty'], $vendorId,
                        abs((float) $row['unit_price_without_vat']) * ($rate > 0 ? $rate : 1.0),
                        (int) $row['acq_year'], [$rule],
                    );
                    if ($match !== null) {
                        $existingRule = $rule;
                        break;
                    }
                }
                if ($existingRule === null || $existingRule['application_mode'] !== 'suggest') continue;
            } elseif (!$suggestion->isAutoApplicable()) {
                continue;
            }

            $account = $suggestion->accountCode ?? $this->accountForKind($supplierId, $suggestion->kind);
            if (!$this->activeAccountsExist($supplierId, $account, $account)) {
                continue;
            }
            $criterion = $existingRule['description_contains'] ?? ($suggestion->source === 'catalog'
                ? $this->catalogCriterion($normalized, $suggestion->kind, $account, $catalog)
                : null);
            $criterion ??= $description;
            $normalizedCriterion = BankMessageNormalizer::normalizeKeepDigits($criterion);
            if ($existingRule === null && (mb_strlen($normalizedCriterion) < 4 || !str_contains($normalized, $normalizedCriterion))) {
                continue;
            }

            $key = $existingRule !== null ? $supplierId . '|review|' . $existingRule['id']
                : implode('|', [$supplierId, $vendorId ?? 0, $normalizedCriterion, $suggestion->kind->value, $account]);
            $groups[$key] ??= [
                'supplier_id' => $supplierId,
                'vendor_id' => $existingRule !== null ? $existingRule['vendor_client_id'] : $vendorId,
                'existing_rule' => $existingRule,
                'counterparty' => $row['counterparty'] === null ? null : (string) $row['counterparty'],
                'criterion' => $criterion,
                'expense_kind' => $suggestion->kind->value,
                'account' => $account,
                'confidence' => $suggestion->confidence,
                'source' => $suggestion->source,
                'documents' => [],
            ];
            $documentId = (int) $row['purchase_invoice_id'];
            $groups[$key]['confidence'] = min((float) $groups[$key]['confidence'], $suggestion->confidence);
            $groups[$key]['documents'][$documentId] ??= [
                'description' => $description,
                'document_id' => $documentId,
                'date' => (string) $row['doc_date'],
                'document_no' => (string) $row['vendor_invoice_number'],
                'amount' => (float) $row['total_with_vat'],
                'currency' => (string) $row['currency'],
            ];
        }

        $items = [];
        foreach ($groups as $key => $group) {
            $documents = array_values($group['documents']);
            $existingRule = $group['existing_rule'];
            if ($existingRule === null && count($documents) < 2) {
                continue;
            }
            usort($documents, static fn (array $a, array $b): int => strcmp($b['date'], $a['date']) ?: ($b['document_id'] <=> $a['document_id']));
            $latest = $documents[0];
            $name = trim((string) ($group['counterparty'] ?? '') . ': ' . $group['criterion'], ': ');
            $items[] = [
                'id' => 'expense_rule:' . substr(hash('sha256', $key), 0, 20),
                'type' => 'classify_purchase',
                'supplier_id' => $group['supplier_id'],
                'supplier_name' => $names[$group['supplier_id']] ?? '',
                'vendor_id' => $group['vendor_id'],
                'rule_id' => $existingRule['id'] ?? null,
                'document_id' => $latest['document_id'],
                'item_id' => null,
                'statement_id' => null,
                'transaction_id' => null,
                'date' => $latest['date'],
                'document_no' => $latest['document_no'],
                'description' => $existingRule['name'] ?? $group['criterion'],
                'counterparty' => $group['counterparty'],
                'amount' => $latest['amount'],
                'currency' => $latest['currency'],
                'booked' => false,
                'period_closed' => false,
                'current_account_code' => null,
                'suggested_account_code' => $group['account'],
                'expense_kind' => $group['expense_kind'],
                'current_expense_kind' => null,
                'confidence' => round((float) $group['confidence'], 2),
                'reason' => $existingRule !== null ? 'review_expense_rule' : 'repeated_expense_pattern',
                'source' => $group['source'],
                'action' => $existingRule !== null ? 'edit_expense_rule' : 'create_expense_rule',
                'occurrence_count' => count($documents),
                'samples' => array_map(static fn (array $sample): array => [
                    'description' => $sample['description'],
                    'document_id' => $sample['document_id'],
                    'date' => $sample['date'],
                ], array_slice($documents, 0, 5)),
                'rule_payload' => $existingRule !== null ? array_intersect_key($existingRule, array_flip([
                    'name', 'vendor_client_id', 'vendor_name_contains', 'description_contains',
                    'expense_kind', 'target_account_code', 'amount_min', 'amount_max', 'priority',
                    'application_mode', 'is_active',
                ])) : [
                    'vendor_client_id' => $group['vendor_id'],
                    'name' => mb_substr($name, 0, 120),
                    'description_contains' => $group['criterion'],
                    'expense_kind' => $group['expense_kind'],
                    'target_account_code' => $group['account'],
                    'amount_min' => null,
                    'amount_max' => null,
                    'priority' => 100,
                    'application_mode' => 'suggest',
                ],
                'lines' => [],
                'preview_error' => null,
            ];
        }
        return $items;
    }

    /** @param list<array<string,mixed>> $catalog */
    private function catalogCriterion(string $normalized, ExpenseKind $kind, string $account, array $catalog): ?string
    {
        $matches = [];
        foreach ($catalog as $entry) {
            if ((int) ($entry['polarity'] ?? 0) <= 0 || (bool) ($entry['requires_review'] ?? false)
                || (string) ($entry['expense_kind'] ?? '') !== $kind->value) {
                continue;
            }
            $entryAccount = trim((string) ($entry['target_account_code'] ?? ''));
            if ($entryAccount !== '' && $entryAccount !== $account) {
                continue;
            }
            $phrase = trim((string) ($entry['phrase'] ?? ''));
            $needle = BankMessageNormalizer::normalizeKeepDigits($phrase);
            if ($needle !== '' && str_contains($normalized, $needle)) {
                $matches[$phrase] = mb_strlen($needle);
            }
        }
        if ($matches === []) {
            return null;
        }
        arsort($matches, SORT_NUMERIC);
        return (string) array_key_first($matches);
    }

    /** @param list<int> $supplierIds @param array<int,string> $names @return list<array<string,mixed>> */
    private function bankRuleItems(array $supplierIds, array $names, ?string $from, ?string $to): array
    {
        $items = [];
        foreach ($supplierIds as $supplierId) {
            $analysis = $this->ruleProposals->analyze($supplierId, 60, true);
            foreach ($analysis['clusters'] ?? [] as $cluster) {
                $proposal = is_array($cluster['proposal'] ?? null) ? $cluster['proposal'] : [];
                $debit = trim((string) ($proposal['debit_account_code'] ?? ''));
                $credit = trim((string) ($proposal['credit_account_code'] ?? ''));
                $occurrences = (int) ($cluster['tx_count'] ?? 0);
                $direction = (string) ($cluster['direction'] ?? '');
                if ($occurrences < 2 || $debit === '' || $credit === '' || (bool) ($proposal['own_transfer'] ?? false)
                    || in_array(substr($debit, 0, 3), self::SALDO_ACCOUNTS, true)
                    || in_array(substr($credit, 0, 3), self::SALDO_ACCOUNTS, true)
                    || ($direction === 'outgoing' && !str_starts_with($credit, '221'))
                    || ($direction === 'incoming' && !str_starts_with($debit, '221'))
                    || !$this->activeAccountsExist($supplierId, $debit, $credit)
                    || $this->ruleProposals->hasEquivalentActive($supplierId, $proposal)
                    || $this->bankProposalCovered($supplierId, $proposal)) {
                    continue;
                }
                $date = substr((string) ($cluster['last_seen'] ?? ''), 0, 10);
                if (($from !== null && $date < $from) || ($to !== null && $date > $to)) {
                    continue;
                }
                unset($proposal['tx_ids'], $proposal['own_transfer']);
                $proposal['mode'] = 'suggest';
                $rawSamples = array_slice((array) ($cluster['sample'] ?? []), 0, 5);
                $statementIds = $this->bankStatementIds(
                    $supplierId,
                    array_map(static fn (array $sample): int => (int) $sample['id'], $rawSamples),
                );
                $samples = array_map(static fn (array $sample): array => [
                    'description' => trim((string) ($sample['description'] ?? '')),
                    'statement_id' => $statementIds[(int) $sample['id']] ?? null,
                    'transaction_id' => (int) $sample['id'],
                    'date' => substr((string) $sample['posted_at'], 0, 10),
                ], $rawSamples);
                $example = $samples === [] ? null : $samples[array_key_last($samples)];
                $stableKey = implode('|', [$supplierId, (string) ($cluster['key'] ?? ''), $debit, $credit]);
                $items[] = [
                    'id' => 'bank_rule:' . substr(hash('sha256', $stableKey), 0, 20),
                    'type' => 'bank_rule',
                    'supplier_id' => $supplierId,
                    'supplier_name' => $names[$supplierId] ?? '',
                    'vendor_id' => null,
                    'document_id' => null,
                    'item_id' => null,
                    'statement_id' => $example['statement_id'] ?? null,
                    'transaction_id' => $example['transaction_id'] ?? null,
                    'date' => $date,
                    'document_no' => null,
                    'description' => (string) ($proposal['name'] ?? ''),
                    'counterparty' => $proposal['description'] ?? null,
                    'amount' => (float) ($cluster['amount_max'] ?? 0),
                    'currency' => 'CZK',
                    'booked' => false,
                    'period_closed' => false,
                    'current_account_code' => null,
                    'suggested_account_code' => ($cluster['direction'] ?? '') === 'outgoing' ? $debit : $credit,
                    'expense_kind' => null,
                    'current_expense_kind' => null,
                    'confidence' => 0.95,
                    'reason' => 'repeated_bank_pattern',
                    'source' => 'posting_history',
                    'action' => 'create_bank_rule',
                    'occurrence_count' => $occurrences,
                    'samples' => $samples,
                    'rule_payload' => $proposal,
                    'lines' => [],
                    'preview_error' => null,
                ];
            }
        }
        return $items;
    }

    private function bankProposalCovered(int $supplierId, array $proposal): bool
    {
        foreach ($this->bankRules->findActive($supplierId, (string) ($proposal['direction'] ?? '')) as $rule) {
            if (($rule['applies_currency'] ?? null) !== null && (string) $rule['applies_currency'] !== 'CZK') {
                continue;
            }
            if ($this->bankRuleCoversProposal($rule, $proposal)) {
                return true;
            }
        }
        return false;
    }

    private function bankRuleCoversProposal(array $rule, array $proposal): bool
    {
        $criterionCovers = static function (mixed $existing, mixed $proposed, callable $normalize): bool {
            $existing = $normalize((string) ($existing ?? ''));
            if ($existing === null || $existing === '') {
                return true;
            }
            $proposed = $normalize((string) ($proposed ?? ''));
            return $proposed !== null && $proposed !== '' && $existing === $proposed;
        };
        if (!$criterionCovers($rule['counterparty_account'] ?? null, $proposal['counterparty_account'] ?? null, AccountNumberNormalizer::canonical(...))
            || !$criterionCovers($rule['counterparty_bank'] ?? null, $proposal['counterparty_bank'] ?? null, AccountNumberNormalizer::canonicalBankCode(...))
            || !$criterionCovers($rule['variable_symbol'] ?? null, $proposal['variable_symbol'] ?? null, static fn (string $value): string => VariableSymbolNormalizer::digits($value))) {
            return false;
        }

        $prefix = trim((string) ($rule['counterparty_prefix'] ?? ''));
        if ($prefix !== '') {
            $account = trim((string) ($proposal['counterparty_account'] ?? ''));
            if ($account === '' || AccountNumberNormalizer::czechAccountPrefix($account) !== ltrim($prefix, '0')) {
                return false;
            }
        }

        $existingMessage = BankMessageNormalizer::normalize((string) ($rule['message_contains'] ?? ''));
        $proposedMessage = BankMessageNormalizer::normalize((string) ($proposal['message_contains'] ?? ''));
        if ($existingMessage !== '' && ($proposedMessage === '' || !str_contains($proposedMessage, $existingMessage))) {
            return false;
        }

        $existingMin = $rule['amount_min'] === null ? null : (float) $rule['amount_min'];
        $existingMax = $rule['amount_max'] === null ? null : (float) $rule['amount_max'];
        $proposedMin = (float) ($proposal['amount_min'] ?? 0);
        $proposedMax = (float) ($proposal['amount_max'] ?? INF);
        return ($existingMin === null || $existingMin <= $proposedMin)
            && ($existingMax === null || $existingMax >= $proposedMax);
    }

    private function activeAccountsExist(int $supplierId, string $debit, string $credit): bool
    {
        $codes = array_values(array_unique([$debit, $credit]));
        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(DISTINCT account_code) FROM chart_of_accounts
              WHERE supplier_id=? AND is_active=1 AND account_code IN ({$placeholders})"
        );
        $stmt->execute([$supplierId, ...$codes]);
        return (int) $stmt->fetchColumn() === count($codes);
    }

    /** @param list<int> $transactionIds @return array<int,int> */
    private function bankStatementIds(int $supplierId, array $transactionIds): array
    {
        if ($transactionIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($transactionIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT bt.id,bt.statement_id
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id=bt.statement_id AND bs.supplier_id=?
              WHERE bt.id IN ({$placeholders})"
        );
        $stmt->execute([$supplierId, ...$transactionIds]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    /** @param list<int> $supplierIds @return array<int,string> */
    private function supplierNames(array $supplierIds): array
    {
        [$in, $params] = $this->inClause($supplierIds);
        $stmt = $this->db->pdo()->prepare("SELECT id,COALESCE(NULLIF(display_name,''),company_name) name FROM supplier WHERE id IN ({$in})");
        $stmt->execute($params);
        $names = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $names[(int) $row['id']] = (string) $row['name'];
        }
        return $names;
    }

    private function accountForKind(int $supplierId, ExpenseKind $kind): string
    {
        $this->postingRules[$supplierId] ??= $this->rules->effectiveMap($supplierId);
        return (string) ($this->postingRules[$supplierId][$kind->ruleKey()]['debit_account_code'] ?? $kind->fallbackAccount());
    }

    /** @param list<int> $supplierIds @return array{sales:int,purchases:int,bank:int} */
    private function coverageCounts(array $supplierIds, ?string $from, ?string $to): array
    {
        $result = ['sales' => 0, 'purchases' => 0, 'bank' => 0];
        foreach ($this->coverageByDate($supplierIds, $from, $to) as $counts) {
            $result['sales'] += $counts['sales'];
            $result['purchases'] += $counts['purchases'];
            $result['bank'] += $counts['bank'];
        }
        return $result;
    }

    /** @param list<int> $supplierIds @return array<string,array{sales:int,purchases:int,bank:int}> */
    private function coverageByDate(array $supplierIds, ?string $from, ?string $to): array
    {
        [$in, $baseParams] = $this->inClause($supplierIds);
        $count = function (string $table, string $alias, string $typeClause) use ($in, $baseParams, $from, $to): array {
            $date = "COALESCE({$alias}.tax_date,{$alias}.issue_date)";
            $where = ["{$alias}.supplier_id IN ({$in})", "{$alias}.status NOT IN ('draft','cancelled')", $typeClause];
            $params = $baseParams;
            if ($from !== null) {
                $where[] = "{$date} >= ?";
                $params[] = $from;
            }
            if ($to !== null) {
                $where[] = "{$date} <= ?";
                $params[] = $to;
            }
            $stmt = $this->db->pdo()->prepare("SELECT {$date} doc_date,COUNT(*) n FROM {$table} {$alias} WHERE " . implode(' AND ', $where) . " GROUP BY {$date}");
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        };
        $counts = [
            'sales' => $count('invoices', 'i', "i.invoice_type IN ('invoice','credit_note','penalty')"),
            'purchases' => $count('purchase_invoices', 'pi', "pi.document_kind NOT IN ('advance','tax_document')"),
        ];
        $result = [];
        foreach ($counts as $type => $dates) {
            foreach ($dates as $date => $number) {
                $result[$date] ??= ['sales' => 0, 'purchases' => 0, 'bank' => 0];
                $result[$date][$type] = (int) $number;
            }
        }
        $params = $baseParams;
        $where = ["bs.supplier_id IN ({$in})", "bt.posted_at >= DATE_SUB(CURDATE(),INTERVAL 60 MONTH)", "COALESCE(bt.currency,bs.currency,'CZK')='CZK'"];
        if ($from !== null) {
            $where[] = 'bt.posted_at >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $where[] = 'bt.posted_at <= ?';
            $params[] = $to;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT DATE(bt.posted_at) doc_date,COUNT(*) n FROM bank_transactions bt
             JOIN bank_statements bs ON bs.id=bt.statement_id WHERE ' . implode(' AND ', $where) . ' GROUP BY DATE(bt.posted_at)'
        );
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $date => $number) {
            $result[$date] ??= ['sales' => 0, 'purchases' => 0, 'bank' => 0];
            $result[$date]['bank'] = (int) $number;
        }
        return $result;
    }

    /** @param list<int> $ids @return array{string,list<int>} */
    private function inClause(array $ids): array
    {
        return [implode(',', array_fill(0, count($ids), '?')), $ids];
    }

    /** @param list<array<string,mixed>> $lines @return list<array<string,mixed>> */
    private static function normalizeLines(array $lines): array
    {
        return array_map(static function (array $line): array {
            $out = ['account_code' => (string) $line['account_code'], 'side' => (string) $line['side'], 'amount' => round((float) $line['amount'], 2)];
            foreach (['cost_center', 'currency_code', 'fx_rate', 'amount_foreign', 'project_id'] as $key) {
                if (array_key_exists($key, $line)) {
                    $out[$key] = $line[$key];
                }
            }
            return $out;
        }, $lines);
    }

    /** @param list<array<string,mixed>> $lines */
    private static function primaryAccount(array $lines, string $side): ?string
    {
        foreach ($lines as $line) {
            $code = (string) ($line['account_code'] ?? '');
            if (($line['side'] ?? '') === $side && (str_starts_with($code, '5') || str_starts_with($code, '6') || str_starts_with($code, '04'))) {
                return $code;
            }
        }
        return null;
    }

}
