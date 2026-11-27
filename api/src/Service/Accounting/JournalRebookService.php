<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Http\IsoDate;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingBulkItemRepository;
use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\Bulk\BulkActionException;
use MyInvoice\Service\Accounting\Bulk\BulkCandidateSource;
use MyInvoice\Service\Accounting\Bulk\BulkContext;
use MyInvoice\Service\Accounting\Bulk\BulkJobRunner;
use MyInvoice\Service\Accounting\Bulk\BulkOperation;
use MyInvoice\Service\Accounting\Bulk\BulkSelection;
use MyInvoice\Service\Accounting\Dimension\DimensionException;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use PDO;
use Psr\Http\Message\ServerRequestInterface as Request;

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
 * Je to operace `rebook_account` hromadných úloh ({@see BulkJobRunner}): běh nanečisto,
 * ostrý běh podle něj a vrácení řídí společný běh, tahle třída vybírá zápisy a mění
 * jednotlivé doklady. Na stránce Přeúčtovat vybírá filtr nad řádky deníku, z Hromadných
 * akcí v seznamech týž filtr omezený na vybrané doklady nebo zápisy (`restrict_target`,
 * `restrict_ids`). Ostrý běh zpracuje jen doklady, jejichž zaúčtování se od běhu
 * nanečisto nezměnilo (otisk zápisů), vrácení jen ty, které od přeúčtování nikdo nezměnil.
 *
 * Způsob (`params.mode`): `rewrite` přeúčtuje jen zápisy, které jde opravit na místě
 * (otevřené období, ostatní vynechá s důvodem), `rewrite_or_reverse` ostatní stornuje
 * a zaúčtuje znovu, i s posunem data, který ukáže náhled.
 */
final class JournalRebookService implements BulkOperation, BulkCandidateSource
{
    public const SOURCE = 'journal_rebook';
    public const KEY = 'rebook_account';
    public const LIMIT = BulkJobRunner::LIMIT;
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
        private readonly BulkJobRunner $runner,
        private readonly AccountingModeRepository $modes,
        private readonly DimensionService $dimensions,
    ) {}

    /** @var array<int,array<string,mixed>> */
    private array $filters = [];

    /** @var array<int,array<string,mixed>> */
    private array $applyParams = [];

    public function key(): string
    {
        return self::KEY;
    }

    public function supportsRollback(): bool
    {
        return true;
    }

    public function unavailableReason(Request $request, int $supplierId, string $target): ?string
    {
        if (!RequestAuthorization::canRebookAccounts($request)) {
            return 'Přeúčtovat hromadně smí jen správce firmy.';
        }
        if (!$this->modes->hasDoubleEntry($supplierId)) {
            return 'Firma nevede podvojné účetnictví.';
        }
        return null;
    }

    public function normalizeParams(int $supplierId, string $target, array $raw): array
    {
        try {
            return $this->normalizeFilter($supplierId, ['restrict_target' => $target, 'restrict_ids' => $raw['ids'] ?? []] + $raw);
        } catch (PostingException $e) {
            throw new BulkActionException($e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }

    public function candidates(BulkContext $ctx): array
    {
        return $this->filterCandidates($ctx->supplierId, $this->jobFilter($ctx));
    }

    public function evaluate(BulkContext $ctx, array $row): array
    {
        return $this->evaluateRow($ctx->supplierId, $this->jobFilter($ctx), $row);
    }

    public function apply(BulkContext $ctx, array $dry): array
    {
        return $this->applyOne($ctx->jobId, $ctx->supplierId, $ctx->userId, $this->jobFilter($ctx), $dry);
    }

    public function rollback(BulkContext $ctx, array $applied): array
    {
        $of = $this->applyParams[$ctx->jobId] ??= (array) ($this->jobs->find((int) ($ctx->params['rollback_of_job_id'] ?? 0), $ctx->supplierId)['params'] ?? []);
        return $this->rollbackOne($ctx->jobId, $ctx->supplierId, $ctx->userId, (string) ($of['mode'] ?? self::MODE_REWRITE), $of, $applied);
    }

    /** @return array<string,mixed> filtr úlohy (ostrý běh nese parametry běhu nanečisto) */
    private function jobFilter(BulkContext $ctx): array
    {
        return $this->filters[$ctx->jobId] ??= $this->normalizeFilter($ctx->supplierId, $ctx->params);
    }

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
        // Výběr ze seznamu (Hromadné akce) omezuje zápisy na vybrané doklady nebo zápisy;
        // období a zdroje pak nejsou povinné.
        $restrictTarget = isset($body['restrict_target']) ? (string) $body['restrict_target'] : null;
        $restrictIds = null;
        if ($restrictTarget !== null) {
            if (!in_array($restrictTarget, BulkSelection::TARGETS, true)) {
                throw new PostingException('validation_failed', 'Neznámý druh vybraných záznamů.', 422);
            }
            $restrictIds = array_values(array_unique(array_filter(array_map('intval', (array) ($body['restrict_ids'] ?? [])), static fn (int $id): bool => $id > 0)));
            if ($restrictIds === [] || count($restrictIds) > self::LIMIT) {
                throw new PostingException('validation_failed', 'Neplatný výběr záznamů.', 422);
            }
            $body += ['date_from' => '1900-01-01', 'date_to' => '2999-12-31', 'source_types' => match ($restrictTarget) {
                'invoice' => ['invoice'], 'purchase_invoice' => ['purchase_invoice'], default => self::SOURCES,
            }];
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

        $positive = static function (mixed $v, string $what) use ($supplierId): ?int {
            if ($v === null || $v === '' || $v === 0 || $v === '0') return null;
            if (!is_numeric($v) || (int) $v <= 0 || (string) (int) $v !== (string) $v) {
                throw new PostingException('validation_failed', 'Neplatné ' . $what . '.', 422);
            }
            return (int) $v;
        };
        $partnerId = $positive($body['partner_id'] ?? null, 'id odběratele nebo dodavatele');
        if ($partnerId !== null) {
            $stmt = $this->db->pdo()->prepare('SELECT 1 FROM clients WHERE id = ? AND supplier_id = ?');
            $stmt->execute([$partnerId, $supplierId]);
            if ($stmt->fetchColumn() === false) {
                throw new PostingException('validation_failed', 'Odběratel nebo dodavatel ve firmě není.', 422);
            }
        }
        $dimensionValueId = $positive($body['dimension_value_id'] ?? null, 'id hodnoty dimenze');
        if ($dimensionValueId !== null) {
            try {
                $this->dimensions->requireValue($supplierId, $dimensionValueId);
            } catch (DimensionException $e) {
                throw new PostingException('validation_failed', $e->getMessage(), 422);
            }
        }
        $ruleKey = trim((string) ($body['posting_rule_key'] ?? ''));
        if ($ruleKey !== '' && preg_match('/^[a-z0-9_.\-]{1,64}$/D', $ruleKey) !== 1) {
            throw new PostingException('validation_failed', 'Neplatný klíč předkontace.', 422);
        }
        $docFrom = mb_substr(trim((string) ($body['document_no_from'] ?? '')), 0, 50);
        $docTo = mb_substr(trim((string) ($body['document_no_to'] ?? '')), 0, 50);
        if ($docFrom !== '' && $docTo !== '' && $docFrom > $docTo) {
            throw new PostingException('validation_failed', 'Číslo dokladu „od" musí být menší nebo rovno číslu „do".', 422);
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
            'partner_id'         => $partnerId,
            'dimension_value_id' => $dimensionValueId,
            'posting_rule_key'   => $ruleKey === '' ? null : $ruleKey,
            'document_no_from'   => $docFrom === '' ? null : $docFrom,
            'document_no_to'     => $docTo === '' ? null : $docTo,
        ] + ($restrictTarget === null ? [] : ['restrict_target' => $restrictTarget, 'restrict_ids' => $restrictIds]);
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
        $this->runner->run($jobId, self::SOURCE, $this, false);
    }

    /**
     * Doklady, které filtr zasáhne, v pořadí data. Nad {@see LIMIT} se úloha nespouští
     * (hlídá akce přes {@see count()}), tady je strop jen pojistka.
     *
     * @param array<string,mixed> $filter
     * @return list<array<string,mixed>>
     */
    public function filterCandidates(int $supplierId, array $filter): array
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
        if (($filter['document_no_from'] ?? null) !== null) {
            $where .= ' AND je.document_no >= ?';
            $params[] = $filter['document_no_from'];
        }
        if (($filter['document_no_to'] ?? null) !== null) {
            $where .= ' AND je.document_no <= ?';
            $params[] = $filter['document_no_to'];
        }
        if (($filter['partner_id'] ?? null) !== null) {
            $where .= " AND ((je.source_type = 'invoice' AND EXISTS (SELECT 1 FROM invoices fi
                                WHERE fi.id = je.source_id AND fi.supplier_id = je.supplier_id AND fi.client_id = ?))
                          OR (je.source_type = 'purchase_invoice' AND EXISTS (SELECT 1 FROM purchase_invoices fp
                                WHERE fp.id = je.source_id AND fp.supplier_id = je.supplier_id AND fp.vendor_id = ?)))";
            $params[] = $filter['partner_id'];
            $params[] = $filter['partner_id'];
        }
        if (($filter['posting_rule_key'] ?? null) !== null) {
            $where .= " AND ((je.source_type = 'invoice' AND EXISTS (SELECT 1 FROM invoice_items fii
                                WHERE fii.invoice_id = je.source_id AND fii.posting_rule_key = ?))
                          OR (je.source_type = 'purchase_invoice' AND EXISTS (SELECT 1 FROM purchase_invoice_items fpi
                                WHERE fpi.purchase_invoice_id = je.source_id AND fpi.posting_rule_key = ?)))";
            $params[] = $filter['posting_rule_key'];
            $params[] = $filter['posting_rule_key'];
        }
        if (($filter['dimension_value_id'] ?? null) !== null) {
            [$dimSql, $dimParams] = $this->dimensions->filter($supplierId, (int) $filter['dimension_value_id'])->entrySql('je', $supplierId);
            $where .= ' AND ' . $dimSql;
            array_push($params, ...$dimParams);
        }
        if (($filter['restrict_target'] ?? null) !== null && ($filter['restrict_ids'] ?? []) !== []) {
            $marks = implode(',', array_fill(0, count($filter['restrict_ids']), '?'));
            $where .= $filter['restrict_target'] === 'journal_entry'
                ? " AND je.id IN ({$marks})"
                : " AND je.source_type = ? AND je.source_id IN ({$marks})";
            if ($filter['restrict_target'] !== 'journal_entry') {
                $params[] = $filter['restrict_target'];
            }
            array_push($params, ...$filter['restrict_ids']);
        }
        return [$where, $params];
    }

    /**
     * Co by se s dokladem stalo. Nic nezapisuje.
     *
     * @param array<string,mixed> $filter
     * @param array<string,mixed> $row kandidát z {@see candidates()}
     * @return array<string,mixed> řádek pro accounting_bulk_items
     */
    private function evaluateRow(int $supplierId, array $filter, array $row): array
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
            $blocked = $this->documentAccountBlocker($supplierId, $sourceType, $sourceId, $filter, (string) $plan['entry_date']);
            if ($blocked !== null) {
                return ['status' => 'skipped', 'reason_code' => $blocked[0]] + ($blocked[1] === null ? [] : ['message' => $blocked[1]]) + $item;
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
     * @param array<string,mixed> $filter
     * @param array<string,mixed> $dry položka běhu nanečisto
     * @return array<string,mixed>
     */
    private function applyOne(int $jobId, int $supplierId, int $userId, array $filter, array $dry): array
    {
        $sourceType = (string) $dry['source_type'];
        $sourceId = (int) $dry['source_id'];
        $item = BulkSelection::copyItem($dry);
        if (!in_array($sourceType, self::DOCUMENT_SOURCES, true)) {
            return $this->rebookEntryInPlace($jobId, $supplierId, $userId, (int) $dry['entry_id'], $filter['from_account'], $filter['to_account'], $filter['side'], $dry['fingerprint'], $item);
        }
        try {
            $plan = $this->repost->plan($supplierId, $sourceType, $sourceId);
            [$after] = self::transform(self::normalizeLines($plan['lines']), $filter['from_account'], $filter['to_account'], $filter['side']);
            if ($this->fingerprint($supplierId, $plan) !== $dry['fingerprint'] || self::linesHash($after) !== self::linesHash($dry['after'])) {
                return ['status' => 'skipped', 'reason_code' => 'changed_after_dry_run'] + $item;
            }
            // Úhrada mezi během nanečisto a ostrým otisk zápisu nezmění, saldokontní účet
            // uhrazeného dokladu se ale přeúčtovat nesmí: podmínky se ověří znovu.
            $blocked = $this->documentAccountBlocker($supplierId, $sourceType, $sourceId, $filter, (string) $plan['entry_date']);
            if ($blocked !== null) {
                return ['status' => 'skipped', 'reason_code' => $blocked[0]] + ($blocked[1] === null ? [] : ['message' => $blocked[1]]) + $item;
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
     * @param array<string,mixed> $of parametry ostrého běhu
     * @param array<string,mixed> $applied položka ostrého běhu
     * @return array<string,mixed>
     */
    private function rollbackOne(int $jobId, int $supplierId, int $userId, string $mode, array $of, array $applied): array
    {
        $sourceType = (string) $applied['source_type'];
        $sourceId = (int) $applied['source_id'];
        // V položce vrácení je „před" stav po přeúčtování a „po" původní kontace.
        $item = ['before' => $applied['after'], 'after' => $applied['before']] + BulkSelection::copyItem($applied);
        if (!in_array($sourceType, self::DOCUMENT_SOURCES, true)) {
            $p = $of;
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
     * změnu {@see DocumentRepostService} přenese i na položky. Saldokontní účet (závazek,
     * pohledávka) přenese do protiúčtu řádků ({@see settlement()}). Jinak (DPH, zaokrouhlení)
     * by přeúčtování vydrželo jen do příští úpravy faktury.
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
     * Proč účet dokladu nejde přeúčtovat (null = jde): banka se řídí vlastními invarianty,
     * u faktury musí jít o účet položek, nebo o saldokontní účet neuhrazeného dokladu
     * s platným cílovým účtem.
     *
     * @param array<string,mixed> $filter
     * @return array{0:string, 1:?string}|null
     */
    private function documentAccountBlocker(int $supplierId, string $sourceType, int $sourceId, array $filter, string $entryDate): ?array
    {
        if ($sourceType === 'bank' || $this->isItemAccount($supplierId, $sourceType, $sourceId, $filter['from_account'], $entryDate)) {
            return null;
        }
        $settlement = $this->settlement();
        if (!$settlement->isSettlementAccount($supplierId, $sourceType, $sourceId, $filter['from_account'])) {
            return ['not_item_account', null];
        }
        return $settlement->blocker($supplierId, $sourceType, $sourceId, $filter['to_account']);
    }

    /**
     * Saldokontní účet dokladu (závazek, pohledávka): přeúčtovat jde jen neuhrazený doklad
     * na platný účet; nový účet {@see DocumentRepostService} uloží do protiúčtu řádků.
     */
    private function settlement(): SettlementAccountRebook
    {
        return new SettlementAccountRebook($this->db, $this->posting);
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
}
