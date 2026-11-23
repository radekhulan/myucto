<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bank;

use MyInvoice\Http\IsoDate;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingBulkItemRepository;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Bank\BankMatchSearch;
use MyInvoice\Service\Bank\BankTransactionPostingScope;
use PDO;

/**
 * Nespárované a nezaúčtované bankovní pohyby podle filtru, hromadně (stránka Doúčtovat
 * doklady, box Bankovní pohyby podle filtru).
 *
 * Typicky výplaty mezd: mzdy jsou zaúčtované interním dokladem 521/331 a ve výpisu leží
 * desítky jednotlivých výplat bez společného VS nebo textu. Pravidlo účtování se na ně
 * nehodí, párovat nemají s čím, takže zůstávají Nespárované. Tady se zaúčtují jedním
 * krokem na zvolený protiúčet (výdej MD protiúčet / Dal banka, příjem obráceně).
 *
 * Každý pohyb jde touž cestou jako ruční zaúčtování z fronty
 * ({@see BankPostingService::postManual()}: bankovní noha na analytiku účtu výpisu,
 * cizí měna kurzem dne, avízo se neúčtuje), vrácení touž cestou jako zrušení zaúčtování
 * ({@see BankPostingService::unpost()}). Úloha má stejné tři druhy jako hromadné
 * přeúčtování: nanečisto, ostře podle běhu nanečisto a vrácení ostrého běhu.
 */
final class BankBulkService
{
    public const SOURCE = 'bank_bulk';
    public const LIMIT = 500;
    public const ACTIONS = ['post', 'match'];
    /** Párování běží v požadavku uživatele (cesta jednoho párování je jen v HTTP akci), proto menší strop. */
    public const MATCH_LIMIT = 100;

    public function __construct(
        private readonly Connection $db,
        private readonly ImportJobRepository $jobs,
        private readonly AccountingBulkItemRepository $items,
        private readonly BankPostingService $posting,
        private readonly ChartOfAccountsRepository $accounts,
        private readonly JournalEntryRepository $journal,
        private readonly BankMatchSearch $matchSearch,
    ) {}

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     *
     * @throws PostingException validation_failed / account_not_found
     */
    public function normalizeFilter(int $supplierId, array $body): array
    {
        $dateFrom = (string) ($body['date_from'] ?? '');
        $dateTo = (string) ($body['date_to'] ?? '');
        if (!IsoDate::isValid($dateFrom) || !IsoDate::isValid($dateTo) || $dateFrom > $dateTo) {
            throw new PostingException('validation_failed', 'Zadejte platné období od-do (RRRR-MM-DD).', 422);
        }
        $direction = (string) ($body['direction'] ?? 'out');
        if (!in_array($direction, ['out', 'in', 'both'], true)) {
            throw new PostingException('validation_failed', 'Směr musí být out, in nebo both.', 422);
        }
        $text = static fn (mixed $v, int $max): ?string => ($t = trim((string) ($v ?? ''))) === '' ? null : mb_substr($t, 0, $max);
        $amount = static function (mixed $v): ?float {
            if ($v === null || $v === '') return null;
            if (!is_numeric($v) || (float) $v < 0) {
                throw new PostingException('validation_failed', 'Částka musí být nezáporné číslo.', 422);
            }
            return round((float) $v, 2);
        };
        $action = (string) ($body['action'] ?? 'post');
        if (!in_array($action, self::ACTIONS, true)) {
            throw new PostingException('validation_failed', 'Neznámá akce.', 422);
        }

        $filter = [
            'action'               => $action,
            'date_from'            => $dateFrom,
            'date_to'              => $dateTo,
            'direction'            => $direction,
            'bank_account'         => $text($body['bank_account'] ?? null, 34),
            'text'                 => $text($body['text'] ?? null, 100),
            'counterparty_account' => $text($body['counterparty_account'] ?? null, 34),
            'variable_symbol'      => $text($body['variable_symbol'] ?? null, 20),
            'amount_min'           => $amount($body['amount_min'] ?? null),
            'amount_max'           => $amount($body['amount_max'] ?? null),
        ];

        if ($action === 'post') {
            $counter = trim((string) ($body['counter_account'] ?? ''));
            if ($counter === '') {
                throw new PostingException('validation_failed', 'Zadejte protiúčet, na který se pohyby zaúčtují.', 422);
            }
            if (!isset($this->accounts->codeToIdMap($supplierId)[$counter])) {
                throw new PostingException('account_not_found', 'Účet ' . $counter . ' v účtovém rozvrhu firmy není.', 422);
            }
            if (str_starts_with($counter, '221')) {
                throw new PostingException('validation_failed', 'Protiúčet nemůže být bankovní účet 221.', 422);
            }
            $filter['counter_account'] = $counter;
            $filter['description'] = $text($body['description'] ?? null, 255);
        }
        if ($action === 'match') {
            $type = (string) ($body['document_type'] ?? '');
            $docId = (int) ($body['document_id'] ?? 0);
            if (!in_array($type, ['invoice', 'purchase_invoice'], true) || $docId <= 0) {
                throw new PostingException('validation_failed', 'Vyberte vydanou nebo přijatou fakturu, se kterou se pohyby spárují.', 422);
            }
            $document = $this->matchSearch->document($supplierId, $type, $docId);
            if ($document === null || (float) $document['amount_to_pay'] <= 0) {
                throw new PostingException('document_not_found', 'Doklad ke spárování nebyl nalezen, nebo jde o dobropis.', 422);
            }
            // Úhrada vydané faktury je příjem, přijaté výdej. Opačný směr by spárování
            // jednoho pohybu stejně odmítlo, tady se rovnou nevybere.
            $filter['direction'] = $type === 'invoice' ? 'in' : 'out';
            $filter['document_type'] = $type;
            $filter['document_id'] = $docId;
            $filter['document_no'] = (string) ($document['varsymbol'] ?? $document['vendor_invoice_number'] ?? $docId);
        }

        return $filter;
    }

    /**
     * @param array<string,mixed> $filter
     * @return array{matching:int, income:int, expense:int, total_amount:float, limit:int}
     */
    public function count(int $supplierId, array $filter): array
    {
        $rows = $this->candidates($supplierId, $filter, self::LIMIT * 4);
        $income = count(array_filter($rows, static fn (array $r): bool => (float) $r['amount'] > 0));
        return [
            'matching'     => count($rows),
            'income'       => $income,
            'expense'      => count($rows) - $income,
            'total_amount' => round(array_sum(array_map(static fn (array $r): float => (float) $r['amount'], $rows)), 2),
            'limit'        => self::LIMIT,
        ];
    }

    /**
     * Nespárované, nezaúčtované a neignorované pohyby z výpisů firmy podle filtru.
     *
     * Vlastnictví výpisu rozhoduje {@see BankStatementOwnershipResolver}. Výraz nad
     * `bs.supplier_id` ale index nepoužije, proto jdou dvě větve: výpisy s vyplněnou
     * firmou po indexu a starší výpisy bez firmy celým výrazem.
     *
     * @param array<string,mixed> $filter
     * @return list<array<string,mixed>>
     */
    public function candidates(int $supplierId, array $filter, int $limit = self::LIMIT + 1): array
    {
        $where = "bt.source = 'statement' AND bt.match_status = 'unmatched'
              AND bt.posted_at >= ? AND bt.posted_at < DATE_ADD(?, INTERVAL 1 DAY)
              AND NOT " . BankTransactionPostingScope::existsSql($supplierId, 'bt.id');
        $params = [$filter['date_from'], $filter['date_to']];
        $min = $filter['amount_min'];
        $max = $filter['amount_max'];
        $range = static function (string $sign) use ($min, $max, &$params): string {
            $parts = [$sign === '+' ? 'bt.amount > 0' : 'bt.amount < 0'];
            if ($min !== null) {
                $parts[] = $sign === '+' ? 'bt.amount >= ?' : 'bt.amount <= ?';
                $params[] = $sign === '+' ? $min : -$min;
            }
            if ($max !== null) {
                $parts[] = $sign === '+' ? 'bt.amount <= ?' : 'bt.amount >= ?';
                $params[] = $sign === '+' ? $max : -$max;
            }
            return '(' . implode(' AND ', $parts) . ')';
        };
        $where .= match ($filter['direction']) {
            'out'  => ' AND ' . $range('-'),
            'in'   => ' AND ' . $range('+'),
            default => ' AND (' . $range('+') . ' OR ' . $range('-') . ')',
        };
        if ($filter['bank_account'] !== null) {
            $where .= ' AND bs.account_number = ?';
            $params[] = $filter['bank_account'];
        }
        if ($filter['counterparty_account'] !== null) {
            $where .= ' AND bt.counterparty_account = ?';
            $params[] = $filter['counterparty_account'];
        }
        if ($filter['variable_symbol'] !== null) {
            $where .= ' AND bt.variable_symbol = ?';
            $params[] = $filter['variable_symbol'];
        }
        if ($filter['text'] !== null) {
            $like = '%' . addcslashes((string) $filter['text'], '%_\\') . '%';
            $where .= ' AND (bt.description LIKE ? OR bt.counterparty_name LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }

        $select = 'SELECT bt.id, bt.posted_at, bt.amount, bt.currency, bt.variable_symbol, bt.counterparty_name,
                          bt.counterparty_account, bt.description, bs.account_number
                     FROM bank_statements bs
                     JOIN bank_transactions bt ON bt.statement_id = bs.id';
        $sql = "{$select} WHERE bs.supplier_id = ? AND {$where}
                UNION ALL
                {$select} WHERE bs.supplier_id IS NULL AND " . BankStatementOwnershipResolver::sql() . " AND {$where}
                ORDER BY posted_at, id
                LIMIT " . max(1, $limit);
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, ...$params, ...BankStatementOwnershipResolver::params($supplierId), ...$params]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Vstupní bod workeru (import-worker.php). */
    public function run(int $jobId): void
    {
        $job = $this->jobs->findById($jobId);
        if ($job === null || $job['source'] !== self::SOURCE || !$this->jobs->markRunning($jobId)) {
            return;
        }
        $supplierId = (int) $job['supplier_id'];
        $params = (array) ($job['params'] ?? []);
        $userId = (int) $job['created_by'];
        $counts = ['changed' => 0, 'skipped' => 0, 'failed' => 0];
        try {
            $cancelled = match ((string) ($params['kind'] ?? '')) {
                'dry_run'  => $this->dryRun($jobId, $supplierId, $params, $counts),
                'apply'    => $this->apply($jobId, $supplierId, $userId, $params, $counts),
                'rollback' => $this->rollback($jobId, $supplierId, $userId, $params, $counts),
                default    => throw new \RuntimeException('Neznámý druh úlohy.'),
            };
            $this->progress($jobId, null, $counts, true);
            if ($cancelled) {
                $this->jobs->markCancelled($jobId);
            } elseif ($counts['failed'] > 0) {
                $this->jobs->markCompletedWithWarnings($jobId);
            } else {
                $this->jobs->markCompleted($jobId);
            }
        } catch (\Throwable $e) {
            $this->jobs->appendLog($jobId, $e->getMessage());
            $this->jobs->markFailed($jobId, mb_substr($e->getMessage(), 0, 500));
        }
    }

    /**
     * @param array<string,mixed> $params
     * @param array{changed:int, skipped:int, failed:int} $counts
     */
    private function dryRun(int $jobId, int $supplierId, array $params, array &$counts): bool
    {
        $filter = $this->normalizeFilter($supplierId, $params);
        $limit = $filter['action'] === 'match' ? self::MATCH_LIMIT : self::LIMIT;
        $rows = $this->candidates($supplierId, $filter);
        if (count($rows) > $limit) {
            throw new \RuntimeException('Filtr zasahuje víc než ' . $limit . ' pohybů, zužte ho.');
        }
        $this->jobs->updateProgress($jobId, ['total_items' => count($rows), 'current_step' => 'dry_run']);
        $document = $filter['action'] === 'match'
            ? $this->matchSearch->document($supplierId, $filter['document_type'], $filter['document_id'])
            : null;
        if ($filter['action'] === 'match' && $document === null) {
            throw new \RuntimeException('Doklad ke spárování nebyl nalezen.');
        }
        $remainingCents = $document === null ? 0 : (int) round(abs((float) $document['remaining']) * 100);
        foreach ($rows as $i => $row) {
            if ($this->jobs->isCancelRequested($jobId)) return true;
            $item = $document === null
                ? $this->evaluate($supplierId, $filter, $row)
                : $this->evaluateMatch($supplierId, $filter, $document, $row, $remainingCents);
            $this->items->insert($jobId, $supplierId, $item);
            $counts[match ($item['status']) { 'would_change' => 'changed', 'failed' => 'failed', default => 'skipped' }]++;
            $this->progress($jobId, $i + 1, $counts);
        }
        return false;
    }

    /**
     * @param array<string,mixed> $filter
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function evaluate(int $supplierId, array $filter, array $row): array
    {
        $item = self::itemBase($row);
        try {
            $this->posting->prepareRepostLines($supplierId, (int) $row['id'], self::postLines($filter, $row));
            $item['after'] = self::postLines($filter, $row);
            return ['status' => 'would_change', 'fingerprint' => self::txFingerprint($row)] + $item;
        } catch (PostingException $e) {
            return ['status' => 'skipped', 'reason_code' => self::reasonFor($e), 'message' => $e->getMessage()] + $item;
        }
    }

    /**
     * Spárování pohybu s dokladem nanečisto: pohyb musí být volnou úhradou dokladu
     * (tentýž dotaz jako výběr úhrady z detailu dokladu) ve stejné měně a vejít se do
     * zbytku k úhradě. Pohyby se berou podle data, takže přeplatek padne na poslední.
     *
     * @param array<string,mixed> $filter
     * @param array<string,mixed> $document {@see BankMatchSearch::document()}
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function evaluateMatch(int $supplierId, array $filter, array $document, array $row, int &$remainingCents): array
    {
        $item = self::itemBase($row);
        $item['after'] = [[
            'document_type' => $filter['document_type'],
            'document_id'   => (int) $document['id'],
            'document_no'   => (string) ($document['varsymbol'] ?? $document['vendor_invoice_number'] ?? $document['id']),
        ]];
        $txCurrency = strtoupper((string) ($row['currency'] ?: 'CZK'));
        if ($txCurrency !== strtoupper((string) $document['currency'])) {
            return ['status' => 'skipped', 'reason_code' => 'currency'] + $item;
        }
        if ($this->matchSearch->payments($supplierId, $filter['document_type'], $document, '', 1, (int) $row['id'])['items'] === []) {
            return ['status' => 'skipped', 'reason_code' => 'not_candidate'] + $item;
        }
        $cents = (int) round(abs((float) $row['amount']) * 100);
        if ($cents > $remainingCents) {
            return ['status' => 'skipped', 'reason_code' => 'over_remaining'] + $item;
        }
        $remainingCents -= $cents;
        return ['status' => 'would_change', 'fingerprint' => self::txFingerprint($row)] + $item;
    }

    /**
     * Pohyby běhu nanečisto, které ostrý běh zpracuje: označené ke změně, bez ručně
     * vyřazených. Pohyb, který od běhu nanečisto přestal odpovídat filtru (spároval
     * nebo zaúčtoval ho někdo jiný), se vrací s `current = null`.
     *
     * @param array<string,mixed> $filter
     * @param list<int> $excludedIds
     * @return list<array{dry:array<string,mixed>, current:?array<string,mixed>, excluded:bool}>
     */
    public function applyPlan(int $supplierId, int $dryJobId, array $filter, array $excludedIds): array
    {
        $excluded = array_flip($excludedIds);
        $current = [];
        foreach ($this->candidates($supplierId, $filter, self::LIMIT * 4) as $row) {
            $current[(int) $row['id']] = $row;
        }
        $plan = [];
        foreach ($this->items->byStatus($supplierId, $dryJobId, 'would_change') as $dry) {
            $txId = (int) $dry['source_id'];
            $row = $current[$txId] ?? null;
            $plan[] = [
                'dry'      => $dry,
                'current'  => $row !== null && self::txFingerprint($row) === $dry['fingerprint'] ? $row : null,
                'excluded' => isset($excluded[$txId]),
            ];
        }
        return $plan;
    }

    /**
     * @param array<string,mixed> $params
     * @param array{changed:int, skipped:int, failed:int} $counts
     */
    private function apply(int $jobId, int $supplierId, int $userId, array $params, array &$counts): bool
    {
        $filter = $this->normalizeFilter($supplierId, $params);
        if ($filter['action'] !== 'post') {
            throw new \RuntimeException('Na pozadí se hromadně jen účtuje, párování běží v požadavku.');
        }
        $excluded = array_flip(array_map('intval', (array) ($params['excluded_ids'] ?? [])));
        $items = $this->items->byStatus($supplierId, (int) ($params['dry_run_job_id'] ?? 0), 'would_change');
        $current = [];
        foreach ($this->candidates($supplierId, $filter, self::LIMIT * 4) as $row) {
            $current[(int) $row['id']] = $row;
        }
        $this->jobs->updateProgress($jobId, ['total_items' => count($items), 'current_step' => 'apply']);
        foreach ($items as $i => $dry) {
            if ($this->jobs->isCancelRequested($jobId)) return true;
            $txId = (int) $dry['source_id'];
            $item = self::copyItem($dry);
            if (isset($excluded[$txId])) {
                $result = ['status' => 'excluded', 'reason_code' => 'excluded_by_user'] + $item;
            } elseif (!isset($current[$txId]) || self::txFingerprint($current[$txId]) !== $dry['fingerprint']) {
                $result = ['status' => 'skipped', 'reason_code' => 'changed_after_dry_run'] + $item;
            } else {
                $result = $this->postOne($supplierId, $userId, $filter, $current[$txId], $item);
            }
            $this->items->insert($jobId, $supplierId, $result);
            $counts[match ($result['status']) { 'applied' => 'changed', 'failed' => 'failed', default => 'skipped' }]++;
            $this->progress($jobId, $i + 1, $counts);
        }
        return false;
    }

    /**
     * @param array<string,mixed> $filter
     * @param array<string,mixed> $row
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private function postOne(int $supplierId, int $userId, array $filter, array $row, array $item): array
    {
        $txId = (int) $row['id'];
        try {
            $lines = self::postLines($filter, $row);
            $result = $this->posting->postManual($supplierId, $txId, array_filter([
                'debit_account_code'  => $lines[0]['account_code'],
                'credit_account_code' => $lines[1]['account_code'],
                'description'         => $filter['description'] ?? null,
            ], static fn (mixed $v): bool => $v !== null), ['user_id' => $userId, 'posted_by' => $userId]);
            $entryId = (int) $result['entry_id'];
            return [
                'status'          => 'applied',
                'entry_id'        => $entryId,
                'result_entry_id' => $entryId,
                'fingerprint'     => $this->entryFingerprint($supplierId, $entryId),
            ] + $item;
        } catch (PostingException $e) {
            $reason = self::reasonFor($e);
            return ['status' => $reason === 'posting_failed' ? 'failed' : 'skipped', 'reason_code' => $reason, 'message' => $e->getMessage()] + $item;
        } catch (\Throwable $e) {
            return ['status' => 'failed', 'reason_code' => 'posting_failed', 'message' => $e->getMessage()] + $item;
        }
    }

    /**
     * @param array<string,mixed> $params
     * @param array{changed:int, skipped:int, failed:int} $counts
     */
    private function rollback(int $jobId, int $supplierId, int $userId, array $params, array &$counts): bool
    {
        $applyJobId = (int) ($params['rollback_of_job_id'] ?? 0);
        $applyJob = $this->jobs->find($applyJobId, $supplierId);
        if ($applyJob === null || $applyJob['source'] !== self::SOURCE || (($applyJob['params'] ?? [])['kind'] ?? '') !== 'apply') {
            throw new \RuntimeException('Vrátit lze jen dokončený ostrý běh.');
        }
        if ((($applyJob['params'] ?? [])['action'] ?? 'post') !== 'post') {
            throw new \RuntimeException('Na pozadí se vrací jen hromadné zaúčtování, párování se vrací v požadavku.');
        }
        $items = $this->items->byStatus($supplierId, $applyJobId, 'applied');
        $this->jobs->updateProgress($jobId, ['total_items' => count($items), 'current_step' => 'rollback']);
        foreach ($items as $i => $applied) {
            if ($this->jobs->isCancelRequested($jobId)) return true;
            $txId = (int) $applied['source_id'];
            $item = ['before' => $applied['after'], 'after' => []] + self::copyItem($applied);
            try {
                $entry = $this->journal->findBySource($supplierId, 'bank', $txId);
                if ($entry === null || (int) $entry['id'] !== (int) $applied['result_entry_id']
                    || $this->entryFingerprint($supplierId, (int) $entry['id']) !== $applied['fingerprint']) {
                    $result = ['status' => 'failed', 'reason_code' => 'changed_after_apply'] + $item;
                } else {
                    $reversalId = $this->posting->unpost($supplierId, $txId, [
                        'user_id' => $userId, 'posted_by' => $userId,
                        'description' => 'Vrácení hromadného zaúčtování (úloha #' . $applyJobId . ')',
                    ]);
                    $result = ['status' => 'applied', 'result_entry_id' => $reversalId] + $item;
                }
            } catch (\Throwable $e) {
                $result = ['status' => 'failed', 'reason_code' => $e instanceof PostingException ? self::reasonFor($e) : 'posting_failed', 'message' => $e->getMessage()] + $item;
            }
            $this->items->insert($jobId, $supplierId, $result);
            $counts[match ($result['status']) { 'applied' => 'changed', 'failed' => 'failed', default => 'skipped' }]++;
            $this->progress($jobId, $i + 1, $counts);
        }
        return false;
    }

    /**
     * Řádky zaúčtování pohybu: výdej MD protiúčet / Dal banka, příjem MD banka / Dal
     * protiúčet. Bankovní noha se na analytiku účtu výpisu přesměruje v postManual.
     *
     * @param array<string,mixed> $filter
     * @param array<string,mixed> $row
     * @return list<array{account_code:string, side:string, amount:float}>
     */
    private static function postLines(array $filter, array $row): array
    {
        $amount = round(abs((float) $row['amount']), 2);
        $counter = (string) $filter['counter_account'];
        return (float) $row['amount'] < 0
            ? [['account_code' => $counter, 'side' => 'debit', 'amount' => $amount], ['account_code' => '221', 'side' => 'credit', 'amount' => $amount]]
            : [['account_code' => '221', 'side' => 'debit', 'amount' => $amount], ['account_code' => $counter, 'side' => 'credit', 'amount' => $amount]];
    }

    /** @param array<string,mixed> $row */
    private static function txFingerprint(array $row): string
    {
        return hash('sha256', implode('|', [(int) $row['id'], (string) $row['posted_at'], (string) $row['amount'], (string) ($row['currency'] ?? '')]));
    }

    private function entryFingerprint(int $supplierId, int $entryId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT row_version FROM journal_entries WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$entryId, $supplierId]);
        $lines = array_map(static fn (array $l): string => $l['account_id'] . '|' . $l['side'] . '|' . $l['amount'],
            $this->journal->linesForEntry($entryId, $supplierId));
        sort($lines);
        return hash('sha256', $entryId . '#' . (int) $stmt->fetchColumn() . '#' . implode(';', $lines));
    }

    private static function reasonFor(PostingException $e): string
    {
        return match ($e->errorCode) {
            'already_posted' => 'already_posted',
            'period_closed', 'period_not_open' => 'period_not_open',
            'date_locked' => 'date_locked',
            'email_notice_provisional', 'transaction_ignored' => 'bank_invariant',
            'fx_not_supported', 'cross_currency', 'cross_currency_manual_only' => 'currency',
            'not_found' => 'not_found',
            default => 'posting_failed',
        };
    }

    /** @param array<string,mixed> $row */
    private static function itemBase(array $row): array
    {
        $who = trim((string) ($row['counterparty_name'] ?? ''));
        $what = trim((string) ($row['description'] ?? ''));
        return [
            'entry_id'    => null,
            'source_type' => 'bank',
            'source_id'   => (int) $row['id'],
            'entry_date'  => substr((string) $row['posted_at'], 0, 10),
            'document_no' => $row['variable_symbol'] === null || $row['variable_symbol'] === '' ? null : (string) $row['variable_symbol'],
            'description' => implode(' · ', array_filter([$who, $what], static fn (string $s): bool => $s !== '')) ?: null,
            'amount'      => round((float) $row['amount'], 2),
            'before'      => [],
            'after'       => [],
        ];
    }

    /**
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private static function copyItem(array $item): array
    {
        return array_intersect_key($item, array_flip([
            'entry_id', 'source_type', 'source_id', 'entry_date', 'document_no', 'description',
            'amount', 'before', 'after', 'fingerprint', 'result_entry_id',
        ]));
    }

    /** @param array{changed:int, skipped:int, failed:int} $counts */
    private function progress(int $jobId, ?int $processed, array $counts, bool $force = false): void
    {
        if ($force) {
            $processed = $counts['changed'] + $counts['skipped'] + $counts['failed'];
        } elseif ($processed % 10 !== 0) {
            return;
        }
        $this->jobs->updateProgress($jobId, [
            'processed'     => $processed,
            'created_count' => $counts['changed'],
            'skipped_count' => $counts['skipped'],
            'failed_count'  => $counts['failed'],
        ]);
    }
}
