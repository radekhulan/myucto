<?php

declare(strict_types=1);

namespace MyInvoice\Service\Automation;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankPostingRuleRepository;
use MyInvoice\Repository\UserSupplierRepository;
use MyInvoice\Service\Accounting\Bank\BankPostingPreview;
use MyInvoice\Service\Accounting\Bank\BankRuleMatcher;
use MyInvoice\Service\Accounting\Learning\LearningStatsProvider;
use PDO;

final class AutomationFeedService
{
    /** @var array<string,bool> */
    private array $columnCache = [];

    public function __construct(
        private readonly Connection $db,
        private readonly UserSupplierRepository $memberships,
        private readonly BankRuleMatcher $ruleMatcher,
        private readonly BankPostingRuleRepository $rules,
        private readonly LearningStatsProvider $learningStats,
        private readonly BankPostingPreview $previews,
    ) {}

    /**
     * Firmy, jejichž automatizaci smí uživatel vidět. Vázaný požadavek (token pro
     * jednu firmu, vlastní doména) dostane nejvýš tuhle jednu firmu, a to i
     * u globálního admina; jinak by token vydaný pro firmu A četl i ostatní firmy
     * svého vlastníka.
     *
     * @return list<int>
     */
    public function allowedSupplierIds(int $userId, bool $isSuperadmin, ?int $boundSupplierId = null): array
    {
        $ids = $this->memberAllowedSupplierIds($userId, $isSuperadmin);
        if ($boundSupplierId === null) return $ids;
        return in_array($boundSupplierId, $ids, true) ? [$boundSupplierId] : [];
    }

    /** @return list<int> */
    private function memberAllowedSupplierIds(int $userId, bool $isSuperadmin): array
    {
        if ($isSuperadmin) {
            return array_map('intval', $this->db->pdo()->query(
                "SELECT id FROM supplier WHERE accounting_mode = 'double_entry' ORDER BY id"
            )->fetchAll(PDO::FETCH_COLUMN));
        }
        $ids = $this->memberships->allowedSupplierIds($userId);
        if ($ids === []) return [];
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT s.id FROM supplier s
               JOIN user_suppliers us ON us.supplier_id=s.id AND us.user_id=?
               JOIN users u ON u.id=us.user_id
               JOIN roles base_role ON base_role.id=u.role_id
               JOIN roles effective_role ON effective_role.id=COALESCE(us.role_id,u.role_id)
               JOIN role_permissions rp ON rp.role_id=effective_role.id AND rp.permission_key='accounting' AND rp.access_level>=1
              WHERE s.accounting_mode='double_entry' AND s.id IN ($marks)
                AND effective_role.is_active=1 AND effective_role.role_type='staff'
                AND (us.role_id IS NULL OR effective_role.role_type=base_role.role_type)
              ORDER BY s.id"
        );
        $stmt->execute(array_merge([$userId], $ids));
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int} */
    public function feed(int $userId, bool $isSuperadmin, FeedQuery $query, ?int $boundSupplierId = null): array
    {
        $allowed = $this->allowedSupplierIds($userId, $isSuperadmin, $boundSupplierId);
        $suppliers = $query->suppliers === []
            ? $allowed
            : array_values(array_intersect($allowed, array_map('intval', $query->suppliers)));
        if ($suppliers === []) {
            return ['items' => [], 'total' => 0, 'page' => $query->page, 'per_page' => $query->perPage];
        }

        $canWrite = $this->writeAccessBySupplier($userId, $isSuperadmin, $suppliers);
        $items = $this->bankItems($suppliers, $query, $canWrite);
        if ($query->tab === 'needs_input' && $query->operationType === null) {
            if ($query->source === null || $query->source === 'document') {
                $items = array_merge(
                    $items,
                    $this->unbookedInvoices($suppliers, $query, $canWrite),
                    $this->unbookedPurchases($suppliers, $query, $canWrite),
                );
            }
            if ($query->source === null || $query->source === 'rule') {
                $items = array_merge($items, $this->disabledRules($suppliers, $query, $canWrite));
            }
        }

        $items = array_values(array_filter($items, static function (array $item) use ($query): bool {
            $confidence = $item['confidence'] === null ? null : (float) $item['confidence'];
            $amount = abs((float) $item['amount']);
            if ($query->minConfidence !== null && ($confidence === null || $confidence < $query->minConfidence)) return false;
            if ($query->maxConfidence !== null && ($confidence === null || $confidence > $query->maxConfidence)) return false;
            if ($query->minAmount !== null && $amount < $query->minAmount) return false;
            if ($query->maxAmount !== null && $amount > $query->maxAmount) return false;
            return true;
        }));

        usort($items, function (array $a, array $b) use ($query): int {
            $aSnoozed = isset($a['snoozed_until']) && $a['snoozed_until'] !== null && (string) $a['snoozed_until'] >= date('Y-m-d H:i:s');
            $bSnoozed = isset($b['snoozed_until']) && $b['snoozed_until'] !== null && (string) $b['snoozed_until'] >= date('Y-m-d H:i:s');
            if ($aSnoozed !== $bSnoozed) return $aSnoozed ? 1 : -1;
            $aAnomaly = str_starts_with((string) ($a['note'] ?? ''), 'anomaly');
            $bAnomaly = str_starts_with((string) ($b['note'] ?? ''), 'anomaly');
            if ($query->tab !== 'auto' && $aAnomaly !== $bAnomaly) return $aAnomaly ? -1 : 1;
            if ($query->sort !== 'default') {
                $field = $query->sort;
                $av = match ($field) {
                    'amount' => abs((float) $a['amount']),
                    'confidence' => $a['confidence'] === null ? null : (float) $a['confidence'],
                    default => $a[$field] ?? null,
                };
                $bv = match ($field) {
                    'amount' => abs((float) $b['amount']),
                    'confidence' => $b['confidence'] === null ? null : (float) $b['confidence'],
                    default => $b[$field] ?? null,
                };
                if ($av === null || $bv === null) {
                    if ($av !== $bv) return $av === null ? 1 : -1;
                } else {
                    $cmp = is_float($av) || is_int($av) ? $av <=> $bv : strcmp((string) $av, (string) $bv);
                    if ($cmp !== 0) return $query->direction === 'desc' ? -$cmp : $cmp;
                }
                return strcmp((string) $a['id'], (string) $b['id']);
            }
            if ($query->tab === 'auto') {
                return strcmp((string) $b['date'], (string) $a['date']) ?: strcmp((string) $b['id'], (string) $a['id']);
            }
            $supplier = strcmp((string) $a['supplier_name'], (string) $b['supplier_name']);
            if ($supplier !== 0) return $supplier;
            if ($query->tab === 'pending') {
                $ac = $a['confidence'] === null ? -1.0 : (float) $a['confidence'];
                $bc = $b['confidence'] === null ? -1.0 : (float) $b['confidence'];
                if ($ac !== $bc) return $bc <=> $ac;
            }
            return strcmp((string) $a['date'], (string) $b['date']) ?: strcmp((string) $a['id'], (string) $b['id']);
        });

        $total = count($items);
        $offset = ($query->page - 1) * $query->perPage;
        return [
            'items' => array_slice($items, $offset, $query->perPage),
            'total' => $total,
            'page' => $query->page,
            'per_page' => $query->perPage,
        ];
    }

    /** @param list<int> $supplierFilter */
    public function counts(int $userId, bool $isSuperadmin, ?string $from, ?string $to, array $supplierFilter, ?int $boundSupplierId = null): array
    {
        $allowed = $this->allowedSupplierIds($userId, $isSuperadmin, $boundSupplierId);
        $ids = $supplierFilter === [] ? $allowed : array_values(array_intersect($allowed, array_map('intval', $supplierFilter)));
        if ($ids === []) return ['auto_today' => 0, 'pending' => 0, 'needs_input' => 0, 'per_supplier' => []];

        $from ??= (new \DateTimeImmutable('today'))->format('Y-m-d');
        $to ??= $from;
        $rows = [];
        foreach ($ids as $id) {
            $supplier = $this->supplierName($id);
            $auto = $this->suggestionCount($id, ['auto_posted'], $from, $to);
            $pending = $this->suggestionCount($id, ['pending'], $from, $to, false);
            $needs = $this->suggestionCount($id, ['needs_input', 'blocked'], $from, $to, false)
                + $this->unbookedDocumentCount($id)
                + $this->disabledRuleCount($id);
            $rows[] = [
                'supplier_id' => $id,
                'supplier_name' => $supplier,
                'auto_today' => $auto,
                'pending' => $pending,
                'needs_input' => $needs,
            ];
        }
        return [
            'auto_today' => array_sum(array_column($rows, 'auto_today')),
            'pending' => array_sum(array_column($rows, 'pending')),
            'needs_input' => array_sum(array_column($rows, 'needs_input')),
            'per_supplier' => $rows,
        ];
    }

    /** @return array<string,mixed> */
    public function stats(int $supplierId, string $from, string $to): array
    {
        $pdo = $this->db->pdo();
        $status = $pdo->prepare(
            "SELECT status, COUNT(*) n FROM bank_posting_suggestions
              WHERE supplier_id = ? AND DATE(created_at) BETWEEN ? AND ?
                AND status IN ('auto_posted','approved','rejected') GROUP BY status"
        );
        $status->execute([$supplierId, $from, $to]);
        $counts = ['auto_posted' => 0, 'approved' => 0, 'rejected' => 0];
        foreach ($status->fetchAll(PDO::FETCH_ASSOC) as $row) $counts[(string) $row['status']] = (int) $row['n'];

        $manualStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM journal_entries je
              WHERE je.supplier_id = ? AND je.source_type = 'bank' AND je.entry_date BETWEEN ? AND ?
                AND je.posted_at IS NOT NULL AND NOT EXISTS (
                  SELECT 1 FROM bank_posting_suggestions bps WHERE bps.journal_entry_id = je.id
                )"
        );
        $manualStmt->execute([$supplierId, $from, $to]);
        $manual = (int) $manualStmt->fetchColumn();
        $denominator = array_sum($counts) + $manual;
        $accepted = $counts['auto_posted'] + $counts['approved'];

        $trendStmt = $pdo->prepare(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') month,
                    SUM(status IN ('auto_posted','approved')) accepted,
                    COUNT(*) total
               FROM bank_posting_suggestions
              WHERE supplier_id = ? AND DATE(created_at) BETWEEN ? AND ?
                AND status IN ('auto_posted','approved','rejected')
              GROUP BY DATE_FORMAT(created_at, '%Y-%m') ORDER BY month"
        );
        $trendStmt->execute([$supplierId, $from, $to]);
        $trend = array_map(static fn (array $r): array => [
            'month' => (string) $r['month'],
            'rate' => (int) $r['total'] === 0 ? 0.0 : round((int) $r['accepted'] / (int) $r['total'], 4),
        ], $trendStmt->fetchAll(PDO::FETCH_ASSOC));

        $reasonStmt = $pdo->prepare(
            "SELECT CASE WHEN LOCATE(':', note) > 0 THEN SUBSTRING_INDEX(note, ':', 1) ELSE note END code, COUNT(*) n
               FROM bank_posting_suggestions
              WHERE supplier_id = ? AND DATE(created_at) BETWEEN ? AND ?
                AND status IN ('needs_input','blocked') AND note IS NOT NULL
              GROUP BY code ORDER BY n DESC LIMIT 10"
        );
        $reasonStmt->execute([$supplierId, $from, $to]);
        $topReasons = array_map(static fn (array $r): array => ['code' => (string) $r['code'], 'count' => (int) $r['n']], $reasonStmt->fetchAll(PDO::FETCH_ASSOC));

        $ruleStmt = $pdo->prepare(
            'SELECT id, name, hit_count, rejected_streak FROM bank_posting_rules WHERE supplier_id = ? ORDER BY rejected_streak DESC, hit_count DESC LIMIT 10'
        );
        $ruleStmt->execute([$supplierId]);
        $rules = array_map(static function (array $r): array {
            $uses = (int) $r['hit_count'] + (int) $r['rejected_streak'];
            return [
                'rule_id' => (int) $r['id'], 'name' => (string) $r['name'],
                'hit_count' => (int) $r['hit_count'], 'rejected_streak' => (int) $r['rejected_streak'],
                'reject_rate' => $uses === 0 ? 0.0 : round((int) $r['rejected_streak'] / $uses, 4),
            ];
        }, $ruleStmt->fetchAll(PDO::FETCH_ASSOC));

        return array_merge([
            'period' => ['from' => $from, 'to' => $to],
            'automation_rate' => $denominator === 0 ? 0.0 : round($accepted / $denominator, 4),
            'trend' => $trend,
            'auto_count' => $counts['auto_posted'],
            'approved_count' => $counts['approved'],
            'rejected_count' => $counts['rejected'],
            'manual_bank_count' => $manual,
            'top_reasons' => $topReasons,
            'rules_by_reject' => $rules,
            'saved_seconds' => $accepted * 45,
            'gl_share_pct' => $this->generalLedgerShare($supplierId, $from, $to),
        ], $this->learningStats->stats($supplierId, $from, $to));
    }

    /** @return array<string,mixed> */
    public function overview(int $supplierId): array
    {
        $pdo = $this->db->pdo();
        $policyStmt = $pdo->prepare('SELECT operation_type, level FROM auto_posting_policy WHERE supplier_id = ? ORDER BY operation_type');
        $policyStmt->execute([$supplierId]);
        $settingsStmt = $pdo->prepare('SELECT automation_level, automation_daily_limit_czk, automation_digest_enabled, automation_digest_hour FROM accounting_supplier_settings WHERE supplier_id = ?');
        $settingsStmt->execute([$supplierId]);
        $settings = $settingsStmt->fetch(PDO::FETCH_ASSOC) ?: [
            'automation_level' => 'suggest', 'automation_daily_limit_czk' => null,
            'automation_digest_enabled' => 0, 'automation_digest_hour' => 7,
        ];
        return [
            'detections' => array_map(static fn (array $r): array => [
                'key' => (string) $r['operation_type'], 'policy_level' => (string) $r['level'],
            ], $policyStmt->fetchAll(PDO::FETCH_ASSOC)),
            'rules' => $this->rules->listForTenant($supplierId),
            'learned' => [],
            'counterparties' => [],
            'ai' => ['enabled' => false, 'scope' => null, 'muted_sources' => []],
            'settings' => [
                'automation_level' => (string) $settings['automation_level'],
                'automation_daily_limit_czk' => $settings['automation_daily_limit_czk'] === null ? null : (float) $settings['automation_daily_limit_czk'],
                'automation_digest_enabled' => (bool) $settings['automation_digest_enabled'],
                'automation_digest_hour' => (int) $settings['automation_digest_hour'],
            ],
        ];
    }

    /** @return array{scope:string,items:list<array<string,mixed>>} */
    public function checklist(int $userId, bool $isSuperadmin, int $supplierId, string $scope, ?string $from, ?string $to, ?int $boundSupplierId = null): array
    {
        $counts = $this->counts($userId, $isSuperadmin, $from, $to, [$supplierId], $boundSupplierId);
        $items = [
            ['key' => 'auto_posted_since', 'ok' => true, 'count' => $counts['auto_today'], 'link' => ['route' => '/automation', 'query' => ['tab' => 'auto']]],
            ['key' => 'pending_count', 'ok' => $counts['pending'] === 0, 'count' => $counts['pending'], 'link' => ['route' => '/automation', 'query' => ['tab' => 'pending']]],
            ['key' => 'needs_input_count', 'ok' => $counts['needs_input'] === 0, 'count' => $counts['needs_input'], 'link' => ['route' => '/automation', 'query' => ['tab' => 'needs_input']]],
        ];
        if ($scope === 'month_end') {
            $items[] = ['key' => 'queue_empty_for_month', 'ok' => $counts['pending'] + $counts['needs_input'] === 0, 'count' => $counts['pending'] + $counts['needs_input'], 'link' => ['route' => '/automation', 'query' => ['tab' => 'pending']]];
        }
        return ['scope' => $scope, 'items' => $items];
    }

    /** @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int} */
    public function history(int $userId, bool $isSuperadmin, FeedQuery $query, ?int $boundSupplierId = null): array
    {
        $allowed = $this->allowedSupplierIds($userId, $isSuperadmin, $boundSupplierId);
        $ids = $query->suppliers === [] ? $allowed : array_values(array_intersect($allowed, $query->suppliers));
        if ($ids === []) return ['items' => [], 'total' => 0, 'page' => $query->page, 'per_page' => $query->perPage];
        [$in, $params] = $this->inClause($ids);
        $where = ["bps.supplier_id IN ($in)", "bps.status IN ('auto_posted','approved','rejected','superseded')"];
        if ($query->from !== null) { $where[] = 'DATE(COALESCE(bps.reviewed_at,bps.created_at)) >= ?'; $params[] = $query->from; }
        if ($query->to !== null) { $where[] = 'DATE(COALESCE(bps.reviewed_at,bps.created_at)) <= ?'; $params[] = $query->to; }
        $this->addSourceFilter($where, $params, $query->source);
        $count = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM bank_posting_suggestions bps WHERE ' . implode(' AND ', $where)
        );
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $stmt = $this->db->pdo()->prepare(
            'SELECT bps.id, bps.supplier_id, COALESCE(NULLIF(s.display_name,\'\'),s.company_name) supplier_name,
                    bps.status event, bps.source, bps.amount, bps.debit_account_code, bps.credit_account_code,
                    bps.journal_entry_id, bps.bank_transaction_id, bps.note,
                    bt.statement_id, bt.posted_at transaction_date,
                    COALESCE(NULLIF(bt.currency,\'\'),NULLIF(bs.currency,\'\'),\'CZK\') currency,
                    COALESCE(NULLIF(bps.description,\'\'),NULLIF(je.description,\'\'),NULLIF(bt.description,\'\'),\'\') description,
                    bt.counterparty_name, bt.variable_symbol, je.document_no,
                    COALESCE(bps.reviewed_at,bps.created_at) occurred_at, u.name decided_by
               FROM bank_posting_suggestions bps
               JOIN supplier s ON s.id=bps.supplier_id
               JOIN bank_transactions bt ON bt.id=bps.bank_transaction_id
               JOIN bank_statements bs ON bs.id=bt.statement_id
          LEFT JOIN journal_entries je ON je.id=bps.journal_entry_id AND je.supplier_id=bps.supplier_id
          LEFT JOIN users u ON u.id=bps.reviewed_by WHERE ' . implode(' AND ', $where) . '
           ORDER BY occurred_at DESC, bps.id DESC LIMIT ? OFFSET ?'
        );
        foreach ($params as $index => $value) $stmt->bindValue($index + 1, $value);
        $stmt->bindValue(count($params) + 1, $query->perPage, PDO::PARAM_INT);
        $stmt->bindValue(count($params) + 2, ($query->page - 1) * $query->perPage, PDO::PARAM_INT);
        $stmt->execute();
        $items = array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'supplier_id' => (int) $r['supplier_id'], 'supplier_name' => (string) $r['supplier_name'],
            'event' => (string) $r['event'], 'source' => (string) $r['source'], 'amount' => (float) $r['amount'],
            'debit_account_code' => (string) $r['debit_account_code'], 'credit_account_code' => (string) $r['credit_account_code'],
            'journal_entry_id' => $r['journal_entry_id'] === null ? null : (int) $r['journal_entry_id'],
            'bank_transaction_id' => (int) $r['bank_transaction_id'], 'statement_id' => (int) $r['statement_id'],
            'transaction_date' => (string) $r['transaction_date'], 'currency' => (string) $r['currency'],
            'description' => (string) $r['description'], 'counterparty' => $r['counterparty_name'],
            'variable_symbol' => $r['variable_symbol'], 'document_no' => $r['document_no'], 'note' => $r['note'],
            'occurred_at' => (string) $r['occurred_at'], 'decided_by' => $r['decided_by'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
        return ['items' => $items, 'total' => $total, 'page' => $query->page, 'per_page' => $query->perPage];
    }

    /** @param list<int> $supplierIds @param array<int,bool> $canWrite @return list<array<string,mixed>> */
    private function bankItems(array $supplierIds, FeedQuery $query, array $canWrite): array
    {
        $statuses = match ($query->tab) {
            'auto' => ['auto_posted'], 'pending' => ['pending'], default => ['needs_input', 'blocked'],
        };
        if (!$this->hasColumn('bank_posting_suggestions', 'confidence') && $query->tab === 'needs_input') return [];
        [$supplierIn, $params] = $this->inClause($supplierIds);
        [$statusIn, $statusParams] = $this->inClause($statuses);
        $params = array_merge($params, $statusParams);
        $where = ["bps.supplier_id IN ($supplierIn)", "bps.status IN ($statusIn)", "s.accounting_mode='double_entry'"];
        $this->addSourceFilter($where, $params, $query->source);
        if ($query->operationType !== null) { $where[] = 'bps.operation_type = ?'; $params[] = $query->operationType; }
        $dateColumn = $query->tab === 'auto' ? 'DATE(bps.created_at)' : 'bt.posted_at';
        if ($query->from !== null) { $where[] = $dateColumn . ' >= ?'; $params[] = $query->from; }
        if ($query->to !== null) { $where[] = $dateColumn . ' <= ?'; $params[] = $query->to; }
        $confidence = $this->hasColumn('bank_posting_suggestions', 'confidence') ? 'bps.confidence' : 'NULL';
        $detector = $this->hasColumn('bank_posting_suggestions', 'detector') ? 'bps.detector' : 'NULL';
        $operation = $this->hasColumn('bank_posting_suggestions', 'operation_type') ? 'bps.operation_type' : 'NULL';
        $stmt = $this->db->pdo()->prepare(
            "SELECT bps.*, bt.posted_at tx_date, DATE(bps.created_at) decision_date,
                    bt.currency tx_currency, bt.description tx_description,
                    bt.statement_id, bt.amount signed_amount, bt.counterparty_name, bt.counterparty_account, bt.counterparty_bank, bt.variable_symbol,
                    bs.account_number recipient_account, bs.bank_code recipient_bank,
                    COALESCE(NULLIF(s.display_name,''),s.company_name) supplier_name,
                    r.name rule_name, r.hit_count rule_hit_count, r.approved_streak rule_approved_streak, je.document_no,
                    $confidence confidence_value, $detector detector_value, $operation operation_value,
                    (NOT EXISTS(SELECT 1 FROM accounting_periods ap WHERE ap.supplier_id=bps.supplier_id
                            AND bt.posted_at BETWEEN ap.starts_on AND ap.ends_on AND ap.status = 'open')
                     OR EXISTS(SELECT 1 FROM accounting_supplier_settings aset WHERE aset.supplier_id=bps.supplier_id
                               AND aset.locked_until IS NOT NULL AND bt.posted_at <= aset.locked_until)) period_closed
               FROM bank_posting_suggestions bps
               JOIN bank_transactions bt ON bt.id=bps.bank_transaction_id
               JOIN bank_statements bs ON bs.id=bt.statement_id
               JOIN supplier s ON s.id=bps.supplier_id
          LEFT JOIN bank_posting_rules r ON r.id=bps.rule_id
          LEFT JOIN journal_entries je ON je.id=bps.journal_entry_id
              WHERE " . implode(' AND ', $where)
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $duplicates = $this->duplicateEntries($rows, $supplierIds);
        $out = [];
        $ownAccounts = new \MyInvoice\Service\Accounting\Bank\StatementOwnAccount($this->db);
        foreach ($rows as $r) {
            $sid = (int) $r['supplier_id'];
            $note = $r['note'] === null ? null : (string) $r['note'];
            $conflicts = null;
            if ($note === 'rule_conflict') {
                $direction = (float) $r['signed_amount'] >= 0 ? 'incoming' : 'outgoing';
                $conflicts = [];
                foreach ($this->rules->findActive($sid, $direction) as $rule) {
                    if ($this->ruleMatcher->matching($rule, $ownAccounts->withOwnAccount($sid, $r, $r))) {
                        $conflicts[] = [
                            'id' => $rule['id'], 'name' => $rule['name'],
                            'debit_account_code' => $rule['debit_account_code'],
                            'credit_account_code' => $rule['credit_account_code'], 'hit_count' => $rule['hit_count'],
                        ];
                    }
                }
            }
            // Kontace se ukazuje tak, jak ji uvidí deník (analytika banky + přesměr jediné
            // analytiky) — návrh držící syrové `261/221` by sliboval jiný zápis, než jaký
            // schválením vznikne.
            $codes = $this->previews->codes(
                $sid,
                $r,
                (string) $r['debit_account_code'],
                (string) $r['credit_account_code'],
            );
            $out[] = [
                'id' => 'bps:' . $r['id'], 'kind' => 'bank_suggestion', 'tab' => $query->tab,
                'supplier_id' => $sid, 'supplier_name' => (string) $r['supplier_name'],
                'date' => (string) ($query->tab === 'auto' ? $r['decision_date'] : $r['tx_date']),
                'amount' => (float) $r['amount'], 'currency' => (string) ($r['tx_currency'] ?: 'CZK'),
                'description' => (string) ($r['description'] ?: $r['tx_description'] ?: ''),
                'counterparty' => $r['counterparty_name'], 'debit_account_code' => $codes['debit'],
                'credit_account_code' => $codes['credit'],
                'accounts_resolved' => $codes['resolved'], 'source' => (string) $r['source'],
                'confidence' => $r['confidence_value'] === null ? null : (float) $r['confidence_value'],
                'detector' => $r['detector_value'], 'operation_type' => $r['operation_value'],
                'rule_id' => $r['rule_id'] === null ? null : (int) $r['rule_id'], 'rule_name' => $r['rule_name'],
                'rule_hit_count' => $r['rule_hit_count'] === null ? null : (int) $r['rule_hit_count'],
                'rule_approved_streak' => $r['rule_approved_streak'] === null ? null : (int) $r['rule_approved_streak'],
                'note' => $note,
                'snoozed_until' => $r['snoozed_until'] ?? null,
                'snooze_reason' => $r['snooze_reason'] ?? null,
                'journal_entry_id' => $r['journal_entry_id'] === null ? null : (int) $r['journal_entry_id'],
                'document_no' => $r['document_no'], 'period_closed' => (bool) $r['period_closed'],
                'can_write' => $canWrite[$sid] ?? false,
                'refs' => ['suggestion_id' => (int) $r['id'], 'bank_transaction_id' => (int) $r['bank_transaction_id'],
                    'statement_id' => (int) $r['statement_id'], 'invoice_id' => null, 'purchase_invoice_id' => null],
                'source_details' => [
                    'variable_symbol' => $r['variable_symbol'],
                    'counterparty_account' => $r['counterparty_account'],
                    'counterparty_bank' => $r['counterparty_bank'],
                    'signed_amount' => (float) $r['signed_amount'],
                ],
                'conflict_rules' => $conflicts, 'duplicate_entry' => $duplicates[(int) $r['id']] ?? null,
            ];
        }
        return $out;
    }

    /** @param list<int> $ids @param array<int,bool> $canWrite */
    private function unbookedInvoices(array $ids, FeedQuery $query, array $canWrite): array
    {
        [$in, $params] = $this->inClause($ids);
        $where = ["i.supplier_id IN ($in)", "i.booked_at IS NULL", "i.status NOT IN ('draft','cancelled')", "i.invoice_type IN ('invoice','credit_note','tax_document','penalty')"];
        if ($query->from !== null) { $where[] = 'i.issue_date >= ?'; $params[] = $query->from; }
        if ($query->to !== null) { $where[] = 'i.issue_date <= ?'; $params[] = $query->to; }
        $stmt = $this->db->pdo()->prepare(
            "SELECT i.id,i.supplier_id,i.issue_date,i.total_with_vat,cur.code currency,i.varsymbol,
                    c.company_name counterparty,COALESCE(NULLIF(s.display_name,''),s.company_name) supplier_name,
                    (NOT EXISTS(SELECT 1 FROM accounting_periods ap WHERE ap.supplier_id=i.supplier_id
                                AND i.issue_date BETWEEN ap.starts_on AND ap.ends_on AND ap.status='open')
                     OR EXISTS(SELECT 1 FROM accounting_supplier_settings aset WHERE aset.supplier_id=i.supplier_id
                               AND aset.locked_until IS NOT NULL AND i.issue_date<=aset.locked_until)) period_closed
               FROM invoices i JOIN supplier s ON s.id=i.supplier_id JOIN currencies cur ON cur.id=i.currency_id
               JOIN clients c ON c.id=i.client_id WHERE " . implode(' AND ', $where)
        );
        $stmt->execute($params);
        return array_map(static fn (array $r): array => [
            'id' => 'inv:' . $r['id'], 'kind' => 'unbooked_invoice', 'tab' => 'needs_input',
            'supplier_id' => (int) $r['supplier_id'], 'supplier_name' => (string) $r['supplier_name'], 'date' => (string) $r['issue_date'],
            'amount' => (float) $r['total_with_vat'], 'currency' => (string) $r['currency'], 'description' => (string) ($r['varsymbol'] ?: ('#' . $r['id'])),
            'counterparty' => $r['counterparty'], 'debit_account_code' => null, 'credit_account_code' => null,
            'source' => 'document', 'confidence' => null, 'detector' => null, 'operation_type' => null,
            'rule_id' => null, 'rule_name' => null, 'note' => 'document_not_posted', 'snoozed_until' => null, 'snooze_reason' => null, 'journal_entry_id' => null,
            'document_no' => $r['varsymbol'], 'period_closed' => (bool) $r['period_closed'], 'can_write' => $canWrite[(int) $r['supplier_id']] ?? false,
            'refs' => ['suggestion_id' => null, 'bank_transaction_id' => null, 'statement_id' => null, 'invoice_id' => (int) $r['id'], 'purchase_invoice_id' => null],
            'source_details' => null,
            'conflict_rules' => null, 'duplicate_entry' => null,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param list<int> $ids @param array<int,bool> $canWrite */
    private function unbookedPurchases(array $ids, FeedQuery $query, array $canWrite): array
    {
        [$in, $params] = $this->inClause($ids);
        $where = ["pi.supplier_id IN ($in)", "pi.booked_at IS NULL", "pi.status NOT IN ('draft','cancelled')", "pi.document_kind <> 'advance'"];
        if ($query->from !== null) { $where[] = 'pi.issue_date >= ?'; $params[] = $query->from; }
        if ($query->to !== null) { $where[] = 'pi.issue_date <= ?'; $params[] = $query->to; }
        $stmt = $this->db->pdo()->prepare(
            "SELECT pi.id,pi.supplier_id,pi.issue_date,pi.total_with_vat,cur.code currency,pi.vendor_invoice_number,
                    c.company_name counterparty,COALESCE(NULLIF(s.display_name,''),s.company_name) supplier_name,
                    (NOT EXISTS(SELECT 1 FROM accounting_periods ap WHERE ap.supplier_id=pi.supplier_id
                                AND pi.issue_date BETWEEN ap.starts_on AND ap.ends_on AND ap.status='open')
                     OR EXISTS(SELECT 1 FROM accounting_supplier_settings aset WHERE aset.supplier_id=pi.supplier_id
                               AND aset.locked_until IS NOT NULL AND pi.issue_date<=aset.locked_until)) period_closed
               FROM purchase_invoices pi JOIN supplier s ON s.id=pi.supplier_id JOIN currencies cur ON cur.id=pi.currency_id
               JOIN clients c ON c.id=pi.vendor_id WHERE " . implode(' AND ', $where)
        );
        $stmt->execute($params);
        return array_map(static fn (array $r): array => [
            'id' => 'pi:' . $r['id'], 'kind' => 'unbooked_purchase', 'tab' => 'needs_input',
            'supplier_id' => (int) $r['supplier_id'], 'supplier_name' => (string) $r['supplier_name'], 'date' => (string) $r['issue_date'],
            'amount' => -(float) $r['total_with_vat'], 'currency' => (string) $r['currency'], 'description' => (string) $r['vendor_invoice_number'],
            'counterparty' => $r['counterparty'], 'debit_account_code' => null, 'credit_account_code' => null,
            'source' => 'document', 'confidence' => null, 'detector' => null, 'operation_type' => null,
            'rule_id' => null, 'rule_name' => null, 'note' => 'document_not_posted', 'snoozed_until' => null, 'snooze_reason' => null, 'journal_entry_id' => null,
            'document_no' => $r['vendor_invoice_number'], 'period_closed' => (bool) $r['period_closed'], 'can_write' => $canWrite[(int) $r['supplier_id']] ?? false,
            'refs' => ['suggestion_id' => null, 'bank_transaction_id' => null, 'statement_id' => null, 'invoice_id' => null, 'purchase_invoice_id' => (int) $r['id']],
            'source_details' => null,
            'conflict_rules' => null, 'duplicate_entry' => null,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param list<int> $ids @param array<int,bool> $canWrite */
    private function disabledRules(array $ids, FeedQuery $query, array $canWrite): array
    {
        [$in, $params] = $this->inClause($ids);
        $where = ["r.supplier_id IN ($in)", 'r.is_active=0', 'r.rejected_streak>=3', 'r.updated_at >= DATE_SUB(NOW(),INTERVAL 30 DAY)'];
        if ($query->from !== null) { $where[] = 'DATE(r.updated_at) >= ?'; $params[] = $query->from; }
        if ($query->to !== null) { $where[] = 'DATE(r.updated_at) <= ?'; $params[] = $query->to; }
        $stmt = $this->db->pdo()->prepare(
            "SELECT r.*,COALESCE(NULLIF(s.display_name,''),s.company_name) supplier_name FROM bank_posting_rules r JOIN supplier s ON s.id=r.supplier_id WHERE " . implode(' AND ', $where)
        );
        $stmt->execute($params);
        return array_map(static fn (array $r): array => [
            'id' => 'rule:' . $r['id'], 'kind' => 'rule_disabled', 'tab' => 'needs_input',
            'supplier_id' => (int) $r['supplier_id'], 'supplier_name' => (string) $r['supplier_name'], 'date' => substr((string) $r['updated_at'], 0, 10),
            'amount' => 0.0, 'currency' => 'CZK', 'description' => (string) $r['name'], 'counterparty' => null,
            'debit_account_code' => (string) $r['debit_account_code'], 'credit_account_code' => (string) $r['credit_account_code'],
            'source' => 'rule', 'confidence' => null, 'detector' => null, 'operation_type' => $r['operation_type'],
            'rule_id' => (int) $r['id'], 'rule_name' => (string) $r['name'], 'note' => 'rule_disabled', 'snoozed_until' => null, 'snooze_reason' => null,
            'journal_entry_id' => null, 'document_no' => null, 'period_closed' => false,
            'can_write' => $canWrite[(int) $r['supplier_id']] ?? false,
            'refs' => ['suggestion_id' => null, 'bank_transaction_id' => null, 'statement_id' => null, 'invoice_id' => null, 'purchase_invoice_id' => null],
            'source_details' => null,
            'conflict_rules' => null, 'duplicate_entry' => null,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param list<int> $ids @return array<int,bool> */
    private function writeAccessBySupplier(int $userId, bool $isSuperadmin, array $ids): array
    {
        if ($isSuperadmin) return array_fill_keys($ids, true);
        [$in, $params] = $this->inClause($ids);
        array_unshift($params, $userId);
        $stmt = $this->db->pdo()->prepare(
            "SELECT us.supplier_id,
                    (effective_role.is_active=1 AND effective_role.role_type='staff'
                     AND (us.role_id IS NULL OR effective_role.role_type=base_role.role_type)
                     AND EXISTS(SELECT 1 FROM role_permissions rp WHERE rp.role_id=effective_role.id AND rp.permission_key='bank.rules' AND rp.access_level>=2)
                     AND EXISTS(SELECT 1 FROM role_permissions rp WHERE rp.role_id=effective_role.id AND rp.permission_key='accounting' AND rp.access_level>=2)) can_write
               FROM users u JOIN roles base_role ON base_role.id=u.role_id
               JOIN user_suppliers us ON us.user_id=u.id
               JOIN roles effective_role ON effective_role.id=COALESCE(us.role_id,u.role_id)
              WHERE u.id=? AND us.supplier_id IN ($in)"
        );
        $stmt->execute($params);
        $out = array_fill_keys($ids, false);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $out[(int) $row['supplier_id']] = (bool) $row['can_write'];
        return $out;
    }

    private function suggestionCount(int $supplierId, array $statuses, string $from, string $to, bool $dateFilter = true): int
    {
        [$in, $params] = $this->inClause($statuses);
        array_unshift($params, $supplierId);
        $sql = "SELECT COUNT(*) FROM bank_posting_suggestions WHERE supplier_id=? AND status IN ($in)";
        if ($dateFilter) { $sql .= ' AND DATE(created_at) BETWEEN ? AND ?'; $params[] = $from; $params[] = $to; }
        $stmt = $this->db->pdo()->prepare($sql); $stmt->execute($params); return (int) $stmt->fetchColumn();
    }

    private function unbookedDocumentCount(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT (SELECT COUNT(*) FROM invoices WHERE supplier_id=? AND booked_at IS NULL AND status NOT IN ('draft','cancelled') AND invoice_type IN ('invoice','credit_note','tax_document','penalty'))
                  +(SELECT COUNT(*) FROM purchase_invoices WHERE supplier_id=? AND booked_at IS NULL AND status NOT IN ('draft','cancelled') AND document_kind <> 'advance')"
        );
        $stmt->execute([$supplierId, $supplierId]); return (int) $stmt->fetchColumn();
    }

    private function disabledRuleCount(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM bank_posting_rules WHERE supplier_id=? AND is_active=0 AND rejected_streak>=3 AND updated_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)');
        $stmt->execute([$supplierId]); return (int) $stmt->fetchColumn();
    }

    private function supplierName(int $supplierId): string
    {
        $stmt = $this->db->pdo()->prepare("SELECT COALESCE(NULLIF(display_name,''),company_name) FROM supplier WHERE id=?");
        $stmt->execute([$supplierId]); return (string) $stmt->fetchColumn();
    }

    private function generalLedgerShare(int $supplierId, string $from, string $to): float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN bps.id IS NOT NULL THEN ABS(jel.signed_amount) ELSE 0 END),0) automated,
                    COALESCE(SUM(ABS(jel.signed_amount)),0) total
               FROM journal_entries je JOIN journal_entry_lines jel ON jel.entry_id=je.id AND jel.side='debit'
          LEFT JOIN bank_posting_suggestions bps ON bps.journal_entry_id=je.id AND bps.status IN ('auto_posted','approved')
              WHERE je.supplier_id=? AND je.entry_date BETWEEN ? AND ? AND je.posted_at IS NOT NULL"
        );
        $stmt->execute([$supplierId, $from, $to]); $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['automated' => 0, 'total' => 0];
        return (float) $r['total'] === 0.0 ? 0.0 : round((float) $r['automated'] / (float) $r['total'] * 100, 1);
    }

    /** @param list<array<string,mixed>> $rows @param list<int> $supplierIds @return array<int,array<string,mixed>> */
    private function duplicateEntries(array $rows, array $supplierIds): array
    {
        $mapping = [];
        $entryIds = [];
        foreach ($rows as $row) {
            if (preg_match('/^duplicate_suspect:#?(\d+)$/', (string) ($row['note'] ?? ''), $m)) {
                $mapping[(int) $row['id']] = (int) $m[1]; $entryIds[] = (int) $m[1];
            }
        }
        if ($entryIds === []) return [];
        [$entryIn, $params] = $this->inClause(array_values(array_unique($entryIds)));
        [$supplierIn, $supplierParams] = $this->inClause($supplierIds); $params = array_merge($params, $supplierParams);
        $stmt = $this->db->pdo()->prepare(
            "SELECT je.id,je.document_no,je.entry_date,
                    MAX(CASE WHEN jel.side='debit' THEN coa.account_code END) debit_account_code,
                    MAX(CASE WHEN jel.side='credit' THEN coa.account_code END) credit_account_code,
                    MAX(jel.signed_amount) amount
               FROM journal_entries je JOIN journal_entry_lines jel ON jel.entry_id=je.id JOIN chart_of_accounts coa ON coa.id=jel.account_id
              WHERE je.id IN ($entryIn) AND je.supplier_id IN ($supplierIn) GROUP BY je.id,je.document_no,je.entry_date"
        );
        $stmt->execute($params); $byId = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $byId[(int) $r['id']] = [
            'journal_entry_id' => (int) $r['id'], 'document_no' => $r['document_no'], 'entry_date' => (string) $r['entry_date'],
            'amount' => (float) $r['amount'], 'debit_account_code' => $r['debit_account_code'], 'credit_account_code' => $r['credit_account_code'],
        ];
        $out = []; foreach ($mapping as $suggestionId => $entryId) if (isset($byId[$entryId])) $out[$suggestionId] = $byId[$entryId];
        return $out;
    }

    private function hasColumn(string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (array_key_exists($key, $this->columnCache)) return $this->columnCache[$key];
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $stmt->execute([$table, $column]); return $this->columnCache[$key] = (int) $stmt->fetchColumn() > 0;
    }

    /** @param list<string> $where @param list<int|string> $params */
    private function addSourceFilter(array &$where, array &$params, ?string $source): void
    {
        if ($source === null) return;
        if ($source === 'ai') {
            $where[] = 'bps.source IN (?,?)';
            array_push($params, 'knn', 'llm');
            return;
        }
        $where[] = 'bps.source = ?';
        $params[] = $source;
    }

    /** @param list<int|string> $values @return array{0:string,1:list<int|string>} */
    private function inClause(array $values): array
    {
        return [implode(',', array_fill(0, count($values), '?')), array_values($values)];
    }
}
