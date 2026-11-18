<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Http\IsoDate;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingBulkItemRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use PDO;

/**
 * Hromadné přeúčtování: změna účtu X → Y na zaúčtovaných zápisech deníku podle filtru
 * (stránka Přeúčtovat doklady).
 *
 * Nevzniká tu žádný vlastní zápis do deníku. Každý doklad jde touž cestou jako tlačítko
 * Přeúčtovat na jednom dokladu ({@see DocumentRepostService}): ta rozhoduje, jestli se
 * zápis opraví na místě, stornuje a zaúčtuje znovu, nebo jestli to období nedovolí,
 * a u faktur přenese nový účet i na položky dokladu. Tahle služba jen vybere zápisy,
 * vymění v nich účet a výsledek po dokladech zapíše do `accounting_bulk_items`.
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
    public const SOURCES = ['invoice', 'purchase_invoice', 'bank', 'manual', 'cash'];
    /** Zdroje, které přeúčtuje {@see DocumentRepostService}; ruční zápisy a pokladnu mění {@see rebookEntryInPlace()}. */
    public const DOCUMENT_SOURCES = ['invoice', 'purchase_invoice', 'bank'];
    /**
     * Živý zápis kandidáta: doklad má aktivní zápis se zdrojem, ruční zápis (bez zdroje)
     * nesmí být stornovaný ani být sám stornem jiného zápisu.
     */
    private const LIVE_SQL = "(je.active_source_id IS NOT NULL OR (je.source_type = 'manual' AND je.reversed_by IS NULL
        AND NOT EXISTS (SELECT 1 FROM journal_entries rev WHERE rev.supplier_id = je.supplier_id AND rev.reversed_by = je.id)))";
    /** Klíč dokladu: ruční zápis nemá zdroj, je jím sám zápis. */
    private const KEY_SQL = "IF(je.source_type = 'manual', je.id, je.source_id)";
    public const MODE_REWRITE = 'rewrite';
    public const MODE_REWRITE_OR_REVERSE = 'rewrite_or_reverse';
    public const KINDS = ['dry_run', 'apply', 'rollback'];

    public function __construct(
        private readonly Connection $db,
        private readonly ImportJobRepository $jobs,
        private readonly DocumentRepostService $repost,
        private readonly PostingService $posting,
        private readonly ChartOfAccountsRepository $accounts,
        private readonly AccountingBulkItemRepository $items,
        private readonly JournalEntryRepository $journal,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * Stránka položek běhu pro obrazovku.
     *
     * @return array{items:list<array<string,mixed>>, total:int, counts:array<string,int>}
     */
    public function items(int $supplierId, int $jobId, ?string $status, int $page, int $perPage): array
    {
        return $this->items->page($supplierId, $jobId, $status, $page, $perPage);
    }

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
            "SELECT je.source_type, COUNT(DISTINCT " . self::KEY_SQL . ") AS n
               FROM journal_entry_lines l
               JOIN journal_entries je ON je.id = l.entry_id AND je.supplier_id = l.supplier_id
              WHERE {$where} AND " . self::LIVE_SQL . " AND je.source_type IN ({$sources})
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
            "SELECT je.source_type, " . self::KEY_SQL . " AS source_id, MIN(je.id) AS entry_id, MIN(je.entry_date) AS entry_date,
                    MAX(je.document_no) AS document_no, MAX(je.description) AS description
               FROM journal_entry_lines l
               JOIN journal_entries je ON je.id = l.entry_id AND je.supplier_id = l.supplier_id
              WHERE {$where} AND " . self::LIVE_SQL . " AND je.source_type IN ({$sources})
              GROUP BY je.source_type, " . self::KEY_SQL . "
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
            $this->items->insert($jobId, $supplierId, $item);
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
     * @return array<string,mixed> řádek pro accounting_bulk_items
     */
    private function evaluate(int $supplierId, array $filter, array $row): array
    {
        $sourceType = (string) $row['source_type'];
        $sourceId = (int) $row['source_id'];
        $item = self::itemBase($row);
        if (!in_array($sourceType, self::DOCUMENT_SOURCES, true)) {
            return $this->evaluateEntry($supplierId, $filter, $row, $item);
        }
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
        $items = $this->items->byStatus($supplierId, $dryJobId, 'would_change');
        $this->jobs->updateProgress($jobId, ['total_items' => count($items), 'current_step' => 'apply']);
        foreach ($items as $i => $dry) {
            if ($this->jobs->isCancelRequested($jobId)) return true;
            if (isset($excluded[(int) $dry['entry_id']])) {
                $this->items->insert($jobId, $supplierId, ['status' => 'excluded', 'reason_code' => 'excluded_by_user'] + self::copyItem($dry));
                $counts['skipped']++;
            } else {
                $result = $this->applyOne($jobId, $supplierId, $userId, $filter, $dry);
                $this->items->insert($jobId, $supplierId, $result);
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
        if (!in_array($sourceType, self::DOCUMENT_SOURCES, true)) {
            return $this->rebookEntryInPlace($jobId, $supplierId, $userId, (int) $dry['entry_id'], $filter['from_account'], $filter['to_account'], $filter['side'], $dry['fingerprint'], $item);
        }
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
        $items = $this->items->byStatus($supplierId, $applyJobId, 'applied');
        $this->jobs->updateProgress($jobId, ['total_items' => count($items), 'current_step' => 'rollback']);
        foreach ($items as $i => $applied) {
            if ($this->jobs->isCancelRequested($jobId)) return true;
            $result = $this->rollbackOne($jobId, $supplierId, $userId, $mode, $applied);
            $this->items->insert($jobId, $supplierId, $result);
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
        if (!in_array($sourceType, self::DOCUMENT_SOURCES, true)) {
            $applyJob = $this->jobs->findById($jobId);
            $of = $this->jobs->find((int) (($applyJob['params'] ?? [])['rollback_of_job_id'] ?? 0), $supplierId);
            $p = (array) ($of['params'] ?? []);
            $lineIds = array_values(array_filter(array_map(static fn (array $l): int => (int) ($l['line_id'] ?? 0), $applied['after'])));
            $result = $this->rebookEntryInPlace($jobId, $supplierId, $userId, (int) $applied['entry_id'], (string) $p['to_account'], (string) $p['from_account'], 'both', $applied['fingerprint'], $item, $lineIds);
            return $result['status'] === 'applied' ? $result : ['status' => 'failed'] + $result;
        }
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
     * Ruční zápis, interní doklad nebo pokladní doklad: co by se stalo. Tyto zápisy
     * nestaví žádný doklad znovu, takže se přeúčtují jen na místě (otevřené období,
     * nezamčené datum, nebo zamčené s daňově neutrálním přesunem) a stornem nikdy.
     *
     * @param array<string,mixed> $filter
     * @param array<string,mixed> $row
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private function evaluateEntry(int $supplierId, array $filter, array $row, array $item): array
    {
        try {
            $state = $this->entryState($supplierId, (int) $row['entry_id'], false);
            [$after, $moved] = self::transform($state['lines'], $filter['from_account'], $filter['to_account'], $filter['side']);
            $item['entry_id'] = $state['id'];
            $item['before'] = $state['lines'];
            $item['amount'] = $moved;
            if ($moved <= 0) {
                return ['status' => 'unchanged'] + $item;
            }
            $item['after'] = self::markChanged($state['lines'], $after);
            $reason = $this->entryBlocker($supplierId, $state, $filter['from_account'], $filter['to_account'], $after);
            if ($reason !== null) {
                return ['status' => 'skipped', 'reason_code' => $reason] + $item;
            }
            return [
                'status' => 'would_change', 'strategy' => DocumentRepostService::STRATEGY_REPLACE,
                'target_date' => $state['entry_date'], 'fingerprint' => self::entryFingerprint($state),
                'reason_code' => $state['date_locked'] ? 'tax_neutral_rewrite' : null,
            ] + $item;
        } catch (PostingException $e) {
            return ['status' => 'skipped', 'reason_code' => self::reasonFor($e), 'message' => $e->getMessage()] + $item;
        }
    }

    /**
     * Přeúčtuje řádky zápisu na místě: zámek období a zápisu, znovu všechny podmínky
     * z náhledu, shodný otisk, výměna účtu na řádcích
     * ({@see JournalEntryRepository::reassignLineAccounts()}) a zápis téhož účtu do
     * zdrojového dokladu (řádky interního dokladu, protiúčet pokladního dokladu).
     *
     * @param array<string,mixed> $item
     * @param list<int>|null $onlyLineIds vrácení: přesně řádky, které ostrý běh změnil
     * @return array<string,mixed>
     */
    private function rebookEntryInPlace(int $jobId, int $supplierId, int $userId, int $entryId, string $from, string $to, string $side, ?string $fingerprint, array $item, ?array $onlyLineIds = null): array
    {
        try {
            return TransactionFailure::retrying($this->db->pdo(), fn (): array => (new DimensionPeriodGuard($this->db))->transaction(
                function () use ($jobId, $supplierId, $userId, $entryId, $from, $to, $side, $fingerprint, $item, $onlyLineIds): array {
                    (new DimensionPeriodGuard($this->db))->lockPeriods($supplierId);
                    $state = $this->entryState($supplierId, $entryId, true);
                    if ($fingerprint !== null && self::entryFingerprint($state) !== $fingerprint) {
                        return ['status' => 'skipped', 'reason_code' => $onlyLineIds === null ? 'changed_after_dry_run' : 'changed_after_apply'] + $item;
                    }
                    if ($onlyLineIds !== null) {
                        $only = array_flip($onlyLineIds);
                        $after = array_map(static fn (array $l): array => isset($only[(int) $l['line_id']]) && $l['account_code'] === $from
                            ? ['account_code' => $to] + $l : $l, $state['lines']);
                    } else {
                        [$after] = self::transform($state['lines'], $from, $to, $side);
                    }
                    $lineIds = [];
                    foreach ($state['lines'] as $i => $line) {
                        if ($line['account_code'] !== $after[$i]['account_code']) $lineIds[] = (int) $line['line_id'];
                    }
                    if ($lineIds === []) {
                        return ['status' => 'skipped', 'reason_code' => 'changed_after_dry_run'] + $item;
                    }
                    $reason = $this->entryBlocker($supplierId, $state, $from, $to, $after);
                    if ($reason !== null) {
                        return ['status' => 'skipped', 'reason_code' => $reason] + $item;
                    }
                    $accountId = (int) ($this->accounts->codeToIdMap($supplierId, $state['entry_date'], true)[$to]['id'] ?? 0);
                    if ($accountId === 0) {
                        throw new PostingException('account_not_found', 'Účet ' . $to . ' v účtovém rozvrhu firmy není.', 422);
                    }
                    $this->journal->reassignLineAccounts($entryId, $supplierId, $state['row_version'], $lineIds, $accountId);
                    $synced = $this->syncEntrySource($supplierId, $state, $from, $to, $side, $lineIds);
                    $now = $this->entryState($supplierId, $entryId, true);
                    $this->activity->log('accounting.reposted', $userId ?: null, 'journal_entry', $entryId, [
                        'source_type' => $state['source_type'], 'source_id' => $state['source_id'], 'strategy' => 'replace',
                        'reason_code' => $state['date_locked'] ? 'tax_neutral_rewrite' : null, 'original_entry_id' => $entryId,
                        'before' => $state['lines'], 'after' => $now['lines'], 'items_synced' => $synced, 'bulk_rebook_job_id' => $jobId,
                    ], null, null, $supplierId);
                    return [
                        'status' => 'applied', 'strategy' => DocumentRepostService::STRATEGY_REPLACE, 'target_date' => $state['entry_date'],
                        'date_shifted' => false, 'reason_code' => null, 'after' => self::markChanged($state['lines'], $now['lines']),
                        'fingerprint' => self::entryFingerprint($now), 'result_entry_id' => $entryId, 'items_synced' => $synced,
                    ] + $item;
                },
            ));
        } catch (PostingException $e) {
            $reason = self::reasonFor($e);
            return ['status' => $reason === 'repost_failed' ? 'failed' : 'skipped', 'reason_code' => $reason, 'message' => $e->getMessage()] + $item;
        } catch (\Throwable $e) {
            return ['status' => 'failed', 'reason_code' => 'repost_failed', 'message' => $e->getMessage()] + $item;
        }
    }

    /**
     * Zápis s hlavičkou, obdobím, zámkem a řádky (kód účtu, `line_id`).
     *
     * @return array{id:int, entry_date:string, row_version:int, source_type:string, source_id:?int,
     *               period_status:?string, date_locked:bool, reversed:bool,
     *               lines:list<array{line_id:int, account_id:int, account_code:?string, side:string, amount:float, is_red_storno:bool}>}
     */
    private function entryState(int $supplierId, int $entryId, bool $lock): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT je.id, je.entry_date, je.row_version, je.source_type, je.source_id, je.reversed_by, je.posted_at, p.status AS period_status
               FROM journal_entries je
          LEFT JOIN accounting_periods p ON p.id = je.period_id AND p.supplier_id = je.supplier_id
              WHERE je.id = ? AND je.supplier_id = ?' . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$entryId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || $row['posted_at'] === null) {
            throw new PostingException('entry_not_found', 'Zápis #' . $entryId . ' není zaúčtovaný.', 404);
        }
        $lockStmt = $this->db->pdo()->prepare('SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $lockStmt->execute([$supplierId]);
        $lockedUntil = $lockStmt->fetchColumn();
        $map = $this->accounts->idToAccountMap($supplierId);
        $lines = [];
        foreach ($this->journal->linesForEntry($entryId, $supplierId, $lock) as $line) {
            $lines[] = [
                'line_id'       => (int) $line['id'],
                'account_id'    => (int) $line['account_id'],
                'account_code'  => isset($map[(int) $line['account_id']]['code']) ? (string) $map[(int) $line['account_id']]['code'] : null,
                'side'          => (string) $line['side'],
                'amount'        => round((float) $line['amount'], 2),
                'is_red_storno' => (bool) ($line['is_red_storno'] ?? false),
            ];
        }
        return [
            'id'            => (int) $row['id'],
            'entry_date'    => (string) $row['entry_date'],
            'row_version'   => (int) $row['row_version'],
            'source_type'   => (string) $row['source_type'],
            'source_id'     => $row['source_id'] === null ? null : (int) $row['source_id'],
            'period_status' => $row['period_status'] === null ? null : (string) $row['period_status'],
            'date_locked'   => $lockedUntil !== false && $lockedUntil !== null && (string) $row['entry_date'] <= (string) $lockedUntil,
            'reversed'      => $row['reversed_by'] !== null,
            'lines'         => $lines,
        ];
    }

    /**
     * Proč zápis nejde přeúčtovat na místě (NULL = jde).
     *
     * @param array<string,mixed> $state {@see entryState()}
     * @param list<array<string,mixed>> $after
     */
    private function entryBlocker(int $supplierId, array $state, string $from, string $to, array $after): ?string
    {
        if ($state['reversed']) return 'entry_reversed';
        if ($state['period_status'] !== 'open') return 'period_not_open';
        if ((new DocumentPostingGroup($this->db))->owner($supplierId, $state['id']) !== null) return 'posting_group';
        if ($state['source_type'] === 'manual') {
            $doc = $this->internalDocument($supplierId, $state['id']);
            if ($doc !== null && $doc['status'] !== 'posted') return 'entry_reversed';
        }
        if ($state['source_type'] === 'cash') {
            if (str_starts_with($from, '211') || str_starts_with($to, '211')) return 'cash_account';
            $cash = $this->cashDocument($supplierId, (int) $state['source_id']);
            // Zápis pokladního dokladu se staví z dokladu: protiúčet nese hlavička, ostatní
            // účty předkontace. Přeúčtovat jde jen protiúčet dokladu, který neuhrazuje fakturu.
            if ($cash === null || $cash['status'] !== 'posted' || $cash['invoice_id'] !== null || $cash['purchase_invoice_id'] !== null
                || (string) $cash['counter_account_code'] !== $from) {
                return 'not_item_account';
            }
            if (mb_strlen($to) > 10) return 'not_item_account';
        }
        if ($state['date_locked']) {
            $violation = TaxNeutralReclassification::violation(
                $state['lines'],
                array_map(fn (array $l): array => ['account_id' => (int) ($this->accounts->codeToIdMap($supplierId)[(string) $l['account_code']]['id'] ?? 0)] + $l, $after),
                $this->accounts->idToAccountMap($supplierId),
                $this->posting->incomeTaxFiledForYear($supplierId, (int) substr($state['entry_date'], 0, 4)),
            );
            if ($violation !== null) return 'date_locked';
        }
        return null;
    }

    /**
     * Zapíše přeúčtovaný účet i do zdrojového dokladu, aby se doklad s deníkem nerozešel:
     * řádky interního dokladu na téže straně a s týmž účtem, protiúčet pokladního dokladu.
     *
     * @param array<string,mixed> $state
     * @param list<int> $lineIds
     */
    private function syncEntrySource(int $supplierId, array $state, string $from, string $to, string $side, array $lineIds): int
    {
        if ($state['source_type'] === 'cash') {
            $stmt = $this->db->pdo()->prepare('UPDATE cash_documents SET counter_account_code = ? WHERE id = ? AND supplier_id = ? AND counter_account_code = ?');
            $stmt->execute([$to, (int) $state['source_id'], $supplierId, $from]);
            return $stmt->rowCount();
        }
        $doc = $this->internalDocument($supplierId, $state['id']);
        if ($doc === null) {
            return 0;
        }
        $sides = array_values(array_unique(array_map(
            static fn (array $l): string => $l['side'],
            array_filter($state['lines'], static fn (array $l): bool => in_array((int) $l['line_id'], $lineIds, true)),
        )));
        $in = implode(',', array_fill(0, count($sides), '?'));
        $stmt = $this->db->pdo()->prepare(
            "UPDATE internal_document_lines SET account_code = ?
              WHERE internal_document_id = ? AND supplier_id = ? AND account_code = ? AND side IN ({$in})"
        );
        $stmt->execute([$to, (int) $doc['id'], $supplierId, $from, ...$sides]);
        $this->db->pdo()->prepare('UPDATE internal_documents SET row_version = row_version + 1 WHERE id = ? AND supplier_id = ?')
            ->execute([(int) $doc['id'], $supplierId]);
        return $stmt->rowCount();
    }

    /** @return ?array{id:int, status:string} */
    private function internalDocument(int $supplierId, int $entryId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, status FROM internal_documents WHERE supplier_id = ? AND journal_entry_id = ?');
        $stmt->execute([$supplierId, $entryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : ['id' => (int) $row['id'], 'status' => (string) $row['status']];
    }

    /** @return ?array<string,mixed> */
    private function cashDocument(int $supplierId, int $docId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, status, counter_account_code, invoice_id, purchase_invoice_id FROM cash_documents WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$docId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $state */
    private static function entryFingerprint(array $state): string
    {
        return hash('sha256', $state['id'] . '#' . $state['row_version'] . '#' . self::linesHash($state['lines']));
    }

    /**
     * Řádky po změně s `line_id` jen u přeúčtovaných: vrácení podle nich ví, které řádky
     * vrátit, a obrazovka páruje řádky podle pořadí.
     *
     * @param list<array<string,mixed>> $before
     * @param list<array<string,mixed>> $after
     * @return list<array<string,mixed>>
     */
    private static function markChanged(array $before, array $after): array
    {
        $out = [];
        foreach ($after as $i => $line) {
            $changed = ($before[$i]['account_code'] ?? null) !== $line['account_code'];
            $out[] = [
                'account_code' => $line['account_code'], 'side' => $line['side'], 'amount' => $line['amount'],
                'is_red_storno' => (bool) ($line['is_red_storno'] ?? false),
            ] + ($changed ? ['line_id' => (int) ($line['line_id'] ?? $before[$i]['line_id'] ?? 0)] : []);
        }
        return $out;
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
