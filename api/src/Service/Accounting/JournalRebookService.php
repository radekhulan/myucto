<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Http\IsoDate;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\ImportJobRepository;
use PDO;

/**
 * Hromadné přeúčtování: změna účtu X → Y na zaúčtovaných zápisech deníku podle filtru
 * (stránka Přeúčtovat doklady).
 *
 * Nevzniká tu žádný vlastní zápis do deníku. Každý doklad jde touž cestou jako tlačítko
 * Přeúčtovat na jednom dokladu ({@see DocumentRepostService}): ta rozhoduje, jestli se
 * zápis opraví na místě, stornuje a zaúčtuje znovu, nebo jestli to období nedovolí,
 * a u faktur přenese nový účet i na položky dokladu. Tahle služba jen vybere zápisy,
 * vymění v nich účet a výsledek po dokladech zapíše do `journal_rebook_items`.
 *
 * Úloha má tři druhy (`params.kind`):
 *   - `dry_run`  … nic nezapíše, u každého dokladu řekne, co by se stalo,
 *   - `apply`    … zpracuje PŘESNĚ doklady, které běh nanečisto označil ke změně, a jen
 *                  když se od té doby jejich zaúčtování nezměnilo (otisk zápisů),
 *   - `rollback` … vrátí doklady, které ostrý běh změnil, a jen když je od té doby nikdo
 *                  nezměnil.
 *
 * Způsob (`params.mode`): `rewrite` přeúčtuje jen zápisy, které jde opravit na místě
 * (otevřené období, ostatní vynechá s důvodem), `rewrite_or_reverse` ostatní stornuje
 * a zaúčtuje znovu, i s posunem data, který ukáže náhled.
 */
final class JournalRebookService
{
    public const SOURCE = 'journal_rebook';
    public const LIMIT = 500;
    public const SOURCES = ['invoice', 'purchase_invoice', 'bank'];
    public const MODE_REWRITE = 'rewrite';
    public const MODE_REWRITE_OR_REVERSE = 'rewrite_or_reverse';
    public const KINDS = ['dry_run', 'apply', 'rollback'];

    public function __construct(
        private readonly Connection $db,
        private readonly ImportJobRepository $jobs,
        private readonly DocumentRepostService $repost,
        private readonly PostingService $posting,
        private readonly ChartOfAccountsRepository $accounts,
    ) {}

    /**
     * Ověří a znormalizuje filtr z požadavku.
     *
     * @param array<string,mixed> $body
     * @return array{from_account:string, to_account:string, side:string, date_from:string, date_to:string,
     *               source_types:list<string>, text:?string, amount_min:?float, amount_max:?float, mode:string}
     *
     * @throws PostingException validation_failed / account_not_found / same_account
     */
    public function normalizeFilter(int $supplierId, array $body): array
    {
        $from = trim((string) ($body['from_account'] ?? ''));
        $to = trim((string) ($body['to_account'] ?? ''));
        if ($from === '' || $to === '') {
            throw new PostingException('validation_failed', 'Zadejte účet, ze kterého se přeúčtuje, i účet, na který.', 422);
        }
        if ($from === $to) {
            throw new PostingException('same_account', 'Účet „z" a „na" musí být různé.', 422);
        }
        $map = $this->accounts->codeToIdMap($supplierId);
        foreach ([$from, $to] as $code) {
            if (!isset($map[$code])) {
                throw new PostingException('account_not_found', 'Účet ' . $code . ' v účtovém rozvrhu firmy není.', 422);
            }
        }

        $side = (string) ($body['side'] ?? 'both');
        if (!in_array($side, ['both', 'debit', 'credit'], true)) {
            throw new PostingException('validation_failed', 'Strana musí být both, debit nebo credit.', 422);
        }
        $dateFrom = (string) ($body['date_from'] ?? '');
        $dateTo = (string) ($body['date_to'] ?? '');
        if (!IsoDate::isValid($dateFrom) || !IsoDate::isValid($dateTo) || $dateFrom > $dateTo) {
            throw new PostingException('validation_failed', 'Zadejte platné období od-do (RRRR-MM-DD).', 422);
        }

        $sources = $body['source_types'] ?? self::SOURCES;
        if (!is_array($sources)) {
            throw new PostingException('validation_failed', 'Neplatný seznam zdrojů.', 422);
        }
        $sources = array_values(array_intersect(self::SOURCES, array_map('strval', $sources)));
        if ($sources === []) {
            throw new PostingException('validation_failed', 'Vyberte aspoň jeden zdroj zápisů.', 422);
        }

        $text = trim((string) ($body['text'] ?? ''));
        $amount = static function (mixed $v): ?float {
            if ($v === null || $v === '') return null;
            if (!is_numeric($v) || (float) $v < 0) {
                throw new PostingException('validation_failed', 'Částka musí být nezáporné číslo.', 422);
            }
            return round((float) $v, 2);
        };
        $mode = (string) ($body['mode'] ?? self::MODE_REWRITE);
        if (!in_array($mode, [self::MODE_REWRITE, self::MODE_REWRITE_OR_REVERSE], true)) {
            throw new PostingException('validation_failed', 'Neplatný způsob přeúčtování.', 422);
        }

        return [
            'from_account' => $from,
            'to_account'   => $to,
            'side'         => $side,
            'date_from'    => $dateFrom,
            'date_to'      => $dateTo,
            'source_types' => $sources,
            'text'         => $text === '' ? null : mb_substr($text, 0, 100),
            'amount_min'   => $amount($body['amount_min'] ?? null),
            'amount_max'   => $amount($body['amount_max'] ?? null),
            'mode'         => $mode,
        ];
    }

    /**
     * Kolik dokladů filtr zasáhne (po zdrojích) a kolik dalších zápisů na účtu leží
     * v agendách, které přeúčtování nemění (pokladna, ruční zápisy, mzdy…).
     *
     * @param array<string,mixed> $filter {@see normalizeFilter()}
     * @return array{matching:int, by_source:array<string,int>, unsupported:int, limit:int}
     */
    public function count(int $supplierId, array $filter): array
    {
        [$where, $params] = $this->lineConditions($supplierId, $filter);
        $sources = implode(', ', array_fill(0, count($filter['source_types']), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT je.source_type, COUNT(DISTINCT je.source_id) AS n
               FROM journal_entry_lines l
               JOIN journal_entries je ON je.id = l.entry_id AND je.supplier_id = l.supplier_id
              WHERE {$where} AND je.active_source_id IS NOT NULL AND je.source_type IN ({$sources})
              GROUP BY je.source_type"
        );
        $stmt->execute([...$params, ...$filter['source_types']]);
        $bySource = array_fill_keys(self::SOURCES, 0);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $bySource[(string) $row['source_type']] = (int) $row['n'];
        }

        $supported = implode(', ', array_fill(0, count(self::SOURCES), '?'));
        $other = $this->db->pdo()->prepare(
            "SELECT COUNT(DISTINCT je.id)
               FROM journal_entry_lines l
               JOIN journal_entries je ON je.id = l.entry_id AND je.supplier_id = l.supplier_id
              WHERE {$where} AND je.reversed_by IS NULL AND je.source_type NOT IN ({$supported})"
        );
        $other->execute([...$params, ...self::SOURCES]);

        return [
            'matching'    => array_sum($bySource),
            'by_source'   => $bySource,
            'unsupported' => (int) $other->fetchColumn(),
            'limit'       => self::LIMIT,
        ];
    }

    /** Vstupní bod workeru ({@see \MyInvoice\Bootstrap} import-worker.php). */
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
            $kind = (string) ($params['kind'] ?? '');
            $cancelled = match ($kind) {
                'dry_run'  => $this->dryRun($jobId, $supplierId, $params, $counts),
                'apply'    => $this->apply($jobId, $supplierId, $userId, $params, $counts),
                'rollback' => $this->rollback($jobId, $supplierId, $userId, $params, $counts),
                default    => throw new \RuntimeException('Neznámý druh úlohy přeúčtování.'),
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
     * Doklady, které filtr zasáhne, v pořadí data. Nad {@see LIMIT} se úloha nespouští
     * (hlídá akce přes {@see count()}), tady je strop jen pojistka.
     *
     * @param array<string,mixed> $filter
     * @return list<array<string,mixed>>
     */
    public function candidates(int $supplierId, array $filter): array
    {
        [$where, $params] = $this->lineConditions($supplierId, $filter);
        $sources = implode(', ', array_fill(0, count($filter['source_types']), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT je.source_type, je.source_id, MIN(je.id) AS entry_id, MIN(je.entry_date) AS entry_date,
                    MAX(je.document_no) AS document_no, MAX(je.description) AS description
               FROM journal_entry_lines l
               JOIN journal_entries je ON je.id = l.entry_id AND je.supplier_id = l.supplier_id
              WHERE {$where} AND je.active_source_id IS NOT NULL AND je.source_type IN ({$sources})
              GROUP BY je.source_type, je.source_id
              ORDER BY MIN(je.entry_date), MIN(je.id)
              LIMIT " . (self::LIMIT + 1)
        );
        $stmt->execute([...$params, ...$filter['source_types']]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Společné podmínky nad řádky deníku: firma, účet, strana, částka, období, text.
     * Jde po indexu `ix_jel_closing_by_account` (supplier_id, account_id, entry_id, side, amount).
     *
     * @param array<string,mixed> $filter
     * @return array{0:string, 1:list<mixed>}
     */
    private function lineConditions(int $supplierId, array $filter): array
    {
        $accountId = (int) ($this->accounts->codeToIdMap($supplierId)[$filter['from_account']]['id'] ?? 0);
        $where = 'l.supplier_id = ? AND l.account_id = ? AND je.entry_date >= ? AND je.entry_date <= ? AND je.posted_at IS NOT NULL';
        $params = [$supplierId, $accountId, $filter['date_from'], $filter['date_to']];
        if ($filter['side'] !== 'both') {
            $where .= ' AND l.side = ?';
            $params[] = $filter['side'];
        }
        if ($filter['amount_min'] !== null) {
            $where .= ' AND l.amount >= ?';
            $params[] = $filter['amount_min'];
        }
        if ($filter['amount_max'] !== null) {
            $where .= ' AND l.amount <= ?';
            $params[] = $filter['amount_max'];
        }
        if ($filter['text'] !== null) {
            $like = '%' . addcslashes((string) $filter['text'], '%_\\') . '%';
            $where .= ' AND (je.document_no LIKE ? OR je.description LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }
        return [$where, $params];
    }

    /**
     * @param array<string,mixed> $params
     * @param array{changed:int, skipped:int, failed:int} $counts
     */
    private function dryRun(int $jobId, int $supplierId, array $params, array &$counts): bool
    {
        $filter = $this->normalizeFilter($supplierId, $params);
        $rows = $this->candidates($supplierId, $filter);
        if (count($rows) > self::LIMIT) {
            throw new \RuntimeException('Filtr zasahuje víc než ' . self::LIMIT . ' dokladů, zužte ho.');
        }
        $this->jobs->updateProgress($jobId, ['total_items' => count($rows), 'current_step' => 'dry_run']);
        foreach ($rows as $i => $row) {
            if ($this->jobs->isCancelRequested($jobId)) return true;
            $item = $this->evaluate($supplierId, $filter, $row);
            $this->insertItem($jobId, $supplierId, $item);
            $counts[match ($item['status']) { 'would_change' => 'changed', 'failed' => 'failed', default => 'skipped' }]++;
            $this->progress($jobId, $i + 1, $counts);
        }
        return false;
    }

    /**
     * Co by se s dokladem stalo. Nic nezapisuje.
     *
     * @param array<string,mixed> $filter
     * @param array<string,mixed> $row kandidát z {@see candidates()}
     * @return array<string,mixed> řádek pro journal_rebook_items
     */
    private function evaluate(int $supplierId, array $filter, array $row): array
    {
        $sourceType = (string) $row['source_type'];
        $sourceId = (int) $row['source_id'];
        $item = self::itemBase($row);
        try {
            $plan = $this->repost->plan($supplierId, $sourceType, $sourceId);
            $before = self::normalizeLines($plan['lines']);
            [$after, $moved] = self::transform($before, $filter['from_account'], $filter['to_account'], $filter['side']);
            $item['entry_id'] = (int) $plan['entry_id'];
            $item['before'] = $before;
            $item['amount'] = $moved;
            if ($moved <= 0) {
                return ['status' => 'unchanged'] + $item;
            }
            $item['after'] = $after;
            if ($sourceType === 'bank' && (str_starts_with($filter['from_account'], '221') || str_starts_with($filter['to_account'], '221'))) {
                return ['status' => 'skipped', 'reason_code' => 'bank_invariant'] + $item;
            }
            if ($sourceType !== 'bank' && !$this->isItemAccount($supplierId, $sourceType, $sourceId, $filter['from_account'], (string) $plan['entry_date'])) {
                return ['status' => 'skipped', 'reason_code' => 'not_item_account'] + $item;
            }
            $preview = $this->repost->previewPlan($supplierId, $sourceType, $sourceId, $after);
            $item['strategy'] = (string) $preview['strategy'];
            $item['target_date'] = $preview['target_date'];
            $item['date_shifted'] = (bool) $preview['date_shifted'];
            $item['reason_code'] = $preview['reason_code'];
            if ($preview['strategy'] === DocumentRepostService::STRATEGY_BLOCKED) {
                return ['status' => 'skipped', 'strategy' => null] + $item;
            }
            if ($preview['strategy'] === DocumentRepostService::STRATEGY_REVERSE && $filter['mode'] === self::MODE_REWRITE) {
                return ['status' => 'skipped', 'strategy' => null, 'reason_code' => $preview['reason_code'] ?? 'needs_reverse'] + $item;
            }
            return ['status' => 'would_change', 'fingerprint' => $this->fingerprint($supplierId, $plan)] + $item;
        } catch (PostingException $e) {
            return ['status' => 'skipped', 'reason_code' => self::reasonFor($e), 'message' => $e->getMessage()] + $item;
        }
    }

    /**
     * @param array<string,mixed> $params
     * @param array{changed:int, skipped:int, failed:int} $counts
     */
    private function apply(int $jobId, int $supplierId, int $userId, array $params, array &$counts): bool
    {
        $filter = $this->normalizeFilter($supplierId, $params);
        $dryJobId = (int) ($params['dry_run_job_id'] ?? 0);
        $excluded = array_flip(array_map('intval', (array) ($params['excluded_entry_ids'] ?? [])));
        $items = $this->itemsByStatus($supplierId, $dryJobId, 'would_change');
        $this->jobs->updateProgress($jobId, ['total_items' => count($items), 'current_step' => 'apply']);
        foreach ($items as $i => $dry) {
            if ($this->jobs->isCancelRequested($jobId)) return true;
            if (isset($excluded[(int) $dry['entry_id']])) {
                $this->insertItem($jobId, $supplierId, ['status' => 'excluded', 'reason_code' => 'excluded_by_user'] + self::copyItem($dry));
                $counts['skipped']++;
            } else {
                $result = $this->applyOne($jobId, $supplierId, $userId, $filter, $dry);
                $this->insertItem($jobId, $supplierId, $result);
                $counts[match ($result['status']) { 'applied' => 'changed', 'failed' => 'failed', default => 'skipped' }]++;
            }
            $this->progress($jobId, $i + 1, $counts);
        }
        return false;
    }

    /**
     * @param array<string,mixed> $filter
     * @param array<string,mixed> $dry položka běhu nanečisto
     * @return array<string,mixed>
     */
    private function applyOne(int $jobId, int $supplierId, int $userId, array $filter, array $dry): array
    {
        $sourceType = (string) $dry['source_type'];
        $sourceId = (int) $dry['source_id'];
        $item = self::copyItem($dry);
        try {
            $plan = $this->repost->plan($supplierId, $sourceType, $sourceId);
            [$after] = self::transform(self::normalizeLines($plan['lines']), $filter['from_account'], $filter['to_account'], $filter['side']);
            if ($this->fingerprint($supplierId, $plan) !== $dry['fingerprint'] || self::linesHash($after) !== self::linesHash($dry['after'])) {
                return ['status' => 'skipped', 'reason_code' => 'changed_after_dry_run'] + $item;
            }
            $result = $this->repost->repost(
                $supplierId,
                $sourceType,
                $sourceId,
                $after,
                $this->repostMeta($jobId, $userId, $filter['mode'], $plan),
                $filter['mode'] === self::MODE_REWRITE_OR_REVERSE,
            );
            $planAfter = $this->repost->plan($supplierId, $sourceType, $sourceId);
            return [
                'status'          => 'applied',
                'strategy'        => (string) $result['strategy'],
                'target_date'     => (string) $result['entry_date'],
                'date_shifted'    => (bool) $result['date_shifted'],
                'reason_code'     => null,
                'after'           => self::normalizeLines($planAfter['lines']),
                'fingerprint'     => $this->fingerprint($supplierId, $planAfter),
                'result_entry_id' => (int) $result['entry_id'],
                'items_synced'    => (int) $result['items_synced'],
            ] + $item;
        } catch (PostingException $e) {
            $reason = self::reasonFor($e);
            return [
                'status'      => in_array($reason, ['repost_failed'], true) ? 'failed' : 'skipped',
                'reason_code' => $reason,
                'message'     => $e->getMessage(),
            ] + $item;
        } catch (\Throwable $e) {
            return ['status' => 'failed', 'reason_code' => 'repost_failed', 'message' => $e->getMessage()] + $item;
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
            throw new \RuntimeException('Vrátit lze jen dokončené přeúčtování.');
        }
        $mode = (string) (($applyJob['params'] ?? [])['mode'] ?? self::MODE_REWRITE);
        $items = $this->itemsByStatus($supplierId, $applyJobId, 'applied');
        $this->jobs->updateProgress($jobId, ['total_items' => count($items), 'current_step' => 'rollback']);
        foreach ($items as $i => $applied) {
            if ($this->jobs->isCancelRequested($jobId)) return true;
            $result = $this->rollbackOne($jobId, $supplierId, $userId, $mode, $applied);
            $this->insertItem($jobId, $supplierId, $result);
            $counts[match ($result['status']) { 'applied' => 'changed', 'failed' => 'failed', default => 'skipped' }]++;
            $this->progress($jobId, $i + 1, $counts);
        }
        return false;
    }

    /**
     * @param array<string,mixed> $applied položka ostrého běhu
     * @return array<string,mixed>
     */
    private function rollbackOne(int $jobId, int $supplierId, int $userId, string $mode, array $applied): array
    {
        $sourceType = (string) $applied['source_type'];
        $sourceId = (int) $applied['source_id'];
        // V položce vrácení je „před" stav po přeúčtování a „po" původní kontace.
        $item = ['before' => $applied['after'], 'after' => $applied['before']] + self::copyItem($applied);
        try {
            $plan = $this->repost->plan($supplierId, $sourceType, $sourceId);
            if ($this->fingerprint($supplierId, $plan) !== $applied['fingerprint']) {
                return ['status' => 'failed', 'reason_code' => 'changed_after_apply'] + $item;
            }
            $result = $this->repost->repost(
                $supplierId,
                $sourceType,
                $sourceId,
                $applied['before'],
                $this->repostMeta($jobId, $userId, $mode, $plan),
                $mode === self::MODE_REWRITE_OR_REVERSE,
            );
            return [
                'status'          => 'applied',
                'strategy'        => (string) $result['strategy'],
                'target_date'     => (string) $result['entry_date'],
                'date_shifted'    => (bool) $result['date_shifted'],
                'reason_code'     => null,
                'result_entry_id' => (int) $result['entry_id'],
                'items_synced'    => (int) $result['items_synced'],
            ] + $item;
        } catch (\Throwable $e) {
            $reason = $e instanceof PostingException ? self::reasonFor($e) : 'repost_failed';
            return ['status' => 'failed', 'reason_code' => $reason, 'message' => $e->getMessage()] + $item;
        }
    }

    /**
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private function repostMeta(int $jobId, int $userId, string $mode, array $plan): array
    {
        $meta = ['user_id' => $userId, 'bulk_rebook_job_id' => $jobId];
        if ($mode === self::MODE_REWRITE) {
            $meta['require_strategy'] = DocumentRepostService::STRATEGY_REPLACE;
        }
        if (($plan['posting_entries'] ?? []) !== []) {
            $meta['expected_posting_entries'] = array_map(static fn (array $m): array => [
                'id' => (int) $m['id'], 'row_version' => (int) $m['row_version'],
            ], $plan['posting_entries']);
        }
        return $meta;
    }

    /**
     * Je účet `$code` účtem, na který podle dokladu míří některá jeho položka? Jen takovou
     * změnu {@see DocumentRepostService} přenese i na položky. Jinak (protiúčet 311/321,
     * DPH, zaokrouhlení) by přeúčtování vydrželo jen do příští úpravy faktury.
     */
    private function isItemAccount(int $supplierId, string $sourceType, int $docId, string $code, string $entryDate): bool
    {
        $accounts = $sourceType === 'invoice'
            ? $this->posting->issuedItemRevenueAccounts($supplierId, $docId, $entryDate)['accounts']
            : $this->posting->purchaseItemExpenseAccounts($supplierId, $docId, $entryDate);
        $code = $this->posting->redirectedAccountCode($supplierId, $code, $entryDate);
        foreach ($accounts as $account) {
            if ($this->posting->redirectedAccountCode($supplierId, (string) $account, $entryDate) === $code) {
                return true;
            }
        }
        return false;
    }

    /**
     * Otisk zaúčtování dokladu: aktivní zápisy s verzí a jejich řádky. Ostrý běh i vrácení
     * podle něj poznají, že doklad mezitím někdo přeúčtoval nebo upravil.
     *
     * @param array<string,mixed> $plan {@see DocumentRepostService::plan()}
     */
    private function fingerprint(int $supplierId, array $plan): string
    {
        $entries = array_map(static fn (array $m): array => [(int) $m['id'], (int) $m['row_version']], $plan['posting_entries'] ?? []);
        if ($entries === []) {
            $stmt = $this->db->pdo()->prepare('SELECT row_version FROM journal_entries WHERE id = ? AND supplier_id = ?');
            $stmt->execute([(int) $plan['entry_id'], $supplierId]);
            $entries = [[(int) $plan['entry_id'], (int) $stmt->fetchColumn()]];
        }
        sort($entries);
        return hash('sha256', json_encode([$entries, self::linesHash(self::normalizeLines($plan['lines']))], JSON_THROW_ON_ERROR));
    }

    /**
     * Vymění účet na řádcích zvolené strany. Ostatní řádky zůstanou beze změny.
     *
     * @param list<array{account_code:?string, side:string, amount:float, is_red_storno:bool}> $lines
     * @return array{0:list<array{account_code:?string, side:string, amount:float, is_red_storno:bool}>, 1:float}
     */
    public static function transform(array $lines, string $from, string $to, string $side): array
    {
        $moved = 0;
        foreach ($lines as $i => $line) {
            if ($line['account_code'] === $from && ($side === 'both' || $line['side'] === $side)) {
                $lines[$i]['account_code'] = $to;
                $moved += JournalLineAmount::cents($line['amount']);
            }
        }
        return [$lines, $moved / 100];
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @return list<array{account_code:?string, side:string, amount:float, is_red_storno:bool}>
     */
    public static function normalizeLines(array $lines): array
    {
        return array_values(array_map(static fn (array $l): array => [
            'account_code'  => isset($l['account_code']) ? (string) $l['account_code'] : null,
            'side'          => (string) $l['side'],
            'amount'        => round((float) $l['amount'], 2),
            'is_red_storno' => (bool) ($l['is_red_storno'] ?? false),
        ], $lines));
    }

    /** @param list<array<string,mixed>> $lines */
    private static function linesHash(array $lines): string
    {
        $keys = array_map(static fn (array $l): string => implode('|', [
            (string) ($l['account_code'] ?? ''), (string) $l['side'],
            JournalLineAmount::cents($l['amount'] ?? 0), !empty($l['is_red_storno']) ? '1' : '0',
        ]), $lines);
        sort($keys);
        return hash('sha256', implode("\n", $keys));
    }

    private static function reasonFor(PostingException $e): string
    {
        return match ($e->errorCode) {
            'repost_strategy_changed' => (string) ($e->context['reason_code'] ?? 'needs_reverse'),
            'version_conflict' => 'changed_after_dry_run',
            'period_not_open', 'date_locked', 'entry_reversed', 'taken_over' => $e->errorCode,
            'repost_date_shift_confirmation_required' => 'needs_reverse',
            'email_notice_provisional', 'transaction_ignored', 'allocation_mismatch' => 'bank_invariant',
            'entry_not_found' => 'entry_not_found',
            default => 'repost_failed',
        };
    }

    /** @param array<string,mixed> $row */
    private static function itemBase(array $row): array
    {
        return [
            'entry_id'    => (int) $row['entry_id'],
            'source_type' => (string) $row['source_type'],
            'source_id'   => (int) $row['source_id'],
            'entry_date'  => (string) $row['entry_date'],
            'document_no' => $row['document_no'] === null ? null : (string) $row['document_no'],
            'description' => $row['description'] === null ? null : (string) $row['description'],
            'amount'      => 0.0,
            'before'      => [],
            'after'       => [],
        ];
    }

    /**
     * Položka předchozího běhu jako základ položky dalšího běhu.
     *
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private static function copyItem(array $item): array
    {
        return array_intersect_key($item, array_flip([
            'entry_id', 'source_type', 'source_id', 'entry_date', 'document_no', 'description',
            'amount', 'strategy', 'target_date', 'date_shifted', 'before', 'after', 'fingerprint',
        ]));
    }

    /** @param array<string,mixed> $item */
    private function insertItem(int $jobId, int $supplierId, array $item): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO journal_rebook_items
                (job_id, supplier_id, entry_id, source_type, source_id, entry_date, document_no, description,
                 amount, status, strategy, target_date, date_shifted, reason_code, message, fingerprint,
                 before_json, after_json, result_entry_id, items_synced)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $jobId,
            $supplierId,
            (int) $item['entry_id'],
            (string) $item['source_type'],
            (int) $item['source_id'],
            (string) $item['entry_date'],
            $item['document_no'] === null ? null : mb_substr((string) $item['document_no'], 0, 50),
            $item['description'] === null ? null : mb_substr((string) $item['description'], 0, 255),
            (float) ($item['amount'] ?? 0),
            (string) $item['status'],
            $item['strategy'] ?? null,
            $item['target_date'] ?? null,
            !empty($item['date_shifted']) ? 1 : 0,
            $item['reason_code'] ?? null,
            isset($item['message']) ? mb_substr((string) $item['message'], 0, 500) : null,
            $item['fingerprint'] ?? null,
            json_encode($item['before'] ?? [], JSON_THROW_ON_ERROR),
            json_encode($item['after'] ?? [], JSON_THROW_ON_ERROR),
            $item['result_entry_id'] ?? null,
            (int) ($item['items_synced'] ?? 0),
        ]);
    }

    /**
     * Položky běhu v daném stavu (pro navazující běh). Běh má nejvýš {@see LIMIT} položek.
     *
     * @return list<array<string,mixed>>
     */
    private function itemsByStatus(int $supplierId, int $jobId, string $status): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM journal_rebook_items WHERE supplier_id = ? AND job_id = ? AND status = ? ORDER BY entry_date, id'
        );
        $stmt->execute([$supplierId, $jobId, $status]);
        return array_map(self::castItem(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Stránka položek běhu pro obrazovku.
     *
     * @return array{items:list<array<string,mixed>>, total:int, counts:array<string,int>}
     */
    public function items(int $supplierId, int $jobId, ?string $status, int $page, int $perPage): array
    {
        $counts = $this->db->pdo()->prepare(
            'SELECT status, COUNT(*) FROM journal_rebook_items WHERE supplier_id = ? AND job_id = ? GROUP BY status'
        );
        $counts->execute([$supplierId, $jobId]);
        $byStatus = array_map('intval', $counts->fetchAll(PDO::FETCH_KEY_PAIR));

        $where = 'supplier_id = ? AND job_id = ?';
        $params = [$supplierId, $jobId];
        if ($status !== null) {
            $where .= ' AND status = ?';
            $params[] = $status;
        }
        $offset = max(0, ($page - 1) * $perPage);
        $stmt = $this->db->pdo()->prepare(
            "SELECT * FROM journal_rebook_items WHERE {$where} ORDER BY entry_date, id LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);

        return [
            'items'  => array_map(static function (array $row): array {
                $item = self::castItem($row);
                unset($item['fingerprint']);
                return $item;
            }, $stmt->fetchAll(PDO::FETCH_ASSOC)),
            'total'  => $status === null ? array_sum($byStatus) : ($byStatus[$status] ?? 0),
            'counts' => $byStatus,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function castItem(array $row): array
    {
        return [
            'id'              => (int) $row['id'],
            'entry_id'        => (int) $row['entry_id'],
            'source_type'     => (string) $row['source_type'],
            'source_id'       => (int) $row['source_id'],
            'document_no'     => $row['document_no'],
            'entry_date'      => (string) $row['entry_date'],
            'description'     => $row['description'],
            'amount'          => (float) $row['amount'],
            'status'          => (string) $row['status'],
            'strategy'        => $row['strategy'],
            'target_date'     => $row['target_date'],
            'date_shifted'    => (bool) $row['date_shifted'],
            'reason_code'     => $row['reason_code'],
            'message'         => $row['message'],
            'fingerprint'     => $row['fingerprint'],
            'before'          => json_decode((string) ($row['before_json'] ?? '[]'), true) ?: [],
            'after'           => json_decode((string) ($row['after_json'] ?? '[]'), true) ?: [],
            'result_entry_id' => $row['result_entry_id'] === null ? null : (int) $row['result_entry_id'],
            'items_synced'    => (int) $row['items_synced'],
        ];
    }

    /** @param array{changed:int, skipped:int, failed:int} $counts */
    private function progress(int $jobId, ?int $processed, array $counts, bool $force = false): void
    {
        if ($force) {
            $processed = $counts['changed'] + $counts['skipped'] + $counts['failed'];
        } elseif ($processed % 10 !== 0) {
            return;
        }
        $this->jobs->updateProgress($jobId, array_filter([
            'processed'     => $processed,
            'created_count' => $counts['changed'],
            'skipped_count' => $counts['skipped'],
            'failed_count'  => $counts['failed'],
        ], static fn (mixed $v): bool => $v !== null));
    }
}
