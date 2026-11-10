<?php

declare(strict_types=1);

namespace MyInvoice\Service\Nonprofit\Membership;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\Nonprofit\Membership\MembershipBatchRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipRateRepository;
use MyInvoice\Service\ActivityLogger;
use PDOException;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\JournalLineAmount;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

final class MembershipBatchService
{
    private readonly MembershipBatchRepository $batches;
    private readonly ActivityLogger $activity;
    private readonly ?int $maximumOverride;
    private ?int $memberLimit = null;
    private readonly LoggerInterface $logger;

    public function __construct(private readonly Connection $db, private readonly MembershipChargeService $charges, ?ActivityLogger $activity = null, ?int $maximumMembers = null, ?LoggerInterface $logger = null)
    {
        $this->batches = new MembershipBatchRepository($db);
        $this->activity = $activity ?? new ActivityLogger($db);
        $this->maximumOverride = $maximumMembers === null ? null : self::maximumMembers($maximumMembers);
        // Action předává aplikační logger. Přímé CLI volání bez DI nesmí chybu zahodit;
        // PHP error log respektuje provozní umístění nastavené Bootstrapem.
        $this->logger = $logger ?? new Logger('myinvoice', [new ErrorLogHandler()]);
    }

    /** Explicitní override pro testy má přednost před provozní ENV. */
    public static function maximumMembers(?int $override = null): int
    {
        if ($override !== null) {
            if ($override < 1 || $override > 5000) throw new \InvalidArgumentException('Limit dávky musí být 1–5000.');
            return $override;
        }
        $raw = getenv('MYINVOICE_MEMBERSHIP_BATCH_MAX_MEMBERS');
        if ($raw === false || trim($raw) === '') return 5000;
        $raw = trim($raw);
        if (preg_match('/^[1-9][0-9]{0,3}$/D', $raw) !== 1 || (int) $raw > 5000) {
            throw new \InvalidArgumentException('MYINVOICE_MEMBERSHIP_BATCH_MAX_MEMBERS vyžaduje celé číslo 1–5000.');
        }
        return (int) $raw;
    }

    /** Neplatná provozní ENV nesmí shodit čtecí endpointy; dávka použije výchozí limit. */
    private function memberLimit(): int
    {
        if ($this->memberLimit !== null) return $this->memberLimit;
        try {
            return $this->memberLimit = self::maximumMembers($this->maximumOverride);
        } catch (\InvalidArgumentException $e) {
            $this->logger->warning('Členská dávka: neplatná konfigurace limitu, použije se výchozí 5000.', ['exception' => $e]);
            return $this->memberLimit = 5000;
        }
    }

    public function create(int $supplier, array $body, int $actor): array
    {
        $fields = ['template', 'selection_mode', 'member_ids', 'idempotency_key'];
        if (array_diff(array_keys($body), $fields) !== [] || array_diff($fields, array_keys($body)) !== []
            || !is_array($body['template']) || !is_array($body['member_ids']) || !array_is_list($body['member_ids'])
            || !in_array($body['selection_mode'], ['active', 'selected'], true)) self::invalid();
        $ids = $body['member_ids'];
        if (count($ids) > 5000 || ($body['selection_mode'] === 'active' ? $ids !== [] : $ids === [])) self::invalid();
        foreach ($ids as $id) if (!is_int($id) || $id <= 0) self::invalid();
        $ids = array_values(array_unique($ids)); sort($ids, SORT_NUMERIC);
        if (array_key_exists('member_id', $body['template'])) self::invalid();
        $key = MembershipChargeRequest::key($body['idempotency_key']);
        // Hash vstupu je nezávislý na měnitelné sazbě. Odvozená šablona má vlastní
        // uložený snapshot; replay nesmí znovu odvozovat ani kontrolovat dnešní sazbu.
        $hash = NonprofitTaxRules::hash(['template' => $body['template'], 'selection_mode' => $body['selection_mode'], 'member_ids' => $ids, 'actor' => $actor]);
        $result = TransactionFailure::retrying($this->db->pdo(), fn (): array => (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplier, $ids, $key, $hash, $actor, $body): array {
            // Krátký zámek serializuje založení se stejným klíčem, nikoli účtování celé dávky.
            $q = $this->db->pdo()->prepare('SELECT id FROM supplier WHERE id=? FOR UPDATE');
            $q->execute([$supplier]);
            if (($existing = $this->batches->byKey($supplier, $key)) !== null) {
                if (!hash_equals($existing['request_hash'], $hash)) throw new MembershipException('nonprofit_membership_idempotency_conflict', 'Klíč dávky už patří jinému požadavku.', 409);
                return ['id' => $existing['id'], 'created' => false];
            }
            if (count($ids) > $this->memberLimit()) self::invalid();
            if ($body['selection_mode'] === 'selected') $this->batches->assertSelection($supplier, $ids);
            $normalized = $this->rateRequest($supplier, $body['template'], true);
            $template = $normalized;
            unset($template['member_id'], $template['amount_cents'], $template['legal_title']);
            $template += ['title_reference' => $normalized['legal_title']['reference'], 'evidence' => $normalized['legal_title']['evidence'], 'nonreciprocal' => true];
            $id = $this->batches->insert($supplier, $key, $hash, $template, $body['selection_mode'], $actor, $ids);
            return ['id' => $id, 'created' => true];
        }));
        if ($result['created']) $this->seedPage($supplier, $result['id']);
        return $this->detail($supplier, $result['id'], write: true);
    }

    public function detail(int $supplier, int $id, int $after = 0, bool $write = false): array
    {
        $current=$write && $this->db->pdo()->inTransaction();
        $batch = $current ? $this->lockedBatch($supplier,$id) : $this->batch($supplier, $id);
        $summary = $this->batches->summary($supplier, $id, $current);
        $items = $this->batches->page($supplier, $id, $after, $current);
        $public = array_intersect_key($batch, array_flip(['id', 'supplier_id', 'created_by', 'confirmed_by', 'confirmed_at', 'phase', 'approval_expires_at', 'member_count', 'created_at', 'template', 'selection_mode']));
        if ($write) $public['approval_token'] = $batch['approval_token'];
        return ['batch' => $public, 'summary' => $summary, 'items' => $items,
            'next_after_member_id' => count($items) === 50 ? $items[array_key_last($items)]['member_id'] : null,
            'accounts' => [
                ['account' => $batch['template']['receivable_account'], 'side' => 'debit', 'amount_cents' => $summary['planned_cents']],
                ['account' => $batch['template']['revenue_account'], 'side' => 'credit', 'amount_cents' => $summary['planned_cents']],
            ]];
    }

    public function prepare(int $supplier, int $id): array
    {
        $batch = $this->batch($supplier, $id);
        if ($batch['phase'] === 'seeding') {
            $this->seedPage($supplier, $id);
            return $this->detail($supplier, $id, write: true);
        }
        if ($batch['phase'] !== 'preparing') return $this->detail($supplier, $id, write: true);
        $work = $this->batches->workPage($supplier, $id, 'pending', 50);
        $ids = array_map('intval', array_column($work, 'member_id'));
        $amounts = array_column($work, 'amount_cents', 'member_id');
        if ($ids !== []) {
            $results = TransactionFailure::retrying($this->db->pdo(), fn (): array => $this->charges->previewIssuePage($supplier, $batch['template'], $ids), 3);
            $rows = [];
            foreach ($results as $member => $result) {
                if (isset($result['failure'])) {
                    $this->logger->error('Členská dávka: technická chyba při přípravě položky.', [
                        'supplier_id' => $supplier, 'batch_id' => $id, 'member_id' => $member, 'exception' => $result['failure'],
                    ]);
                }
                $preview = $result['preview'] ?? null;
                $existing = $result['existing'] ?? null;
                $error = $result['error'] ?? null;
                $rows[] = ['member_id' => $member, 'status' => $preview ? 'ready' : ($existing ? 'existing' : 'rejected'),
                    'amount_cents' => $existing ? (int) $existing['head_amount_cents'] : ($preview['request']['amount_cents'] ?? (int) $amounts[$member]),
                    'preview_hash' => $preview['preview_hash'] ?? null, 'expected_case_version' => $preview['expected_case_version'] ?? null,
                    'operation_id' => $existing['head_operation_id'] ?? null,
                    'deferred_cents' => isset($preview['deferral']) ? JournalLineAmount::cents($preview['deferral']['deferred_amount']) : 0,
                    'deferral_date' => $preview['deferral']['balance_sheet_date'] ?? null,
                    'error_code' => $error?->errorCode, 'error_message' => $error === null ? null : mb_substr($error->getMessage(), 0, 2000)];
            }
            $this->batches->prepared($supplier, $id, $rows);
        }
        $this->batches->finishPreparation($supplier, $id);
        return $this->detail($supplier, $id, write: true);
    }

    public function confirm(int $supplier, int $id, string $token, int $actor): array
    {
        $outer = $this->db->pdo()->inTransaction();
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) self::invalid();
        TransactionFailure::retrying($this->db->pdo(), fn () => (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplier, $id, $token, $actor): void {
            $this->lockedBatch($supplier, $id);
            $this->assertApproval($supplier, $id, $token);
            $this->batches->beginPosting($supplier, $id, $token, $actor);
            $this->activity->log('nonprofit_membership_batch_confirm', $actor, 'nonprofit_membership_batch', $id,
                ['member_count' => count($this->batches->workPage($supplier, $id, 'ready', 10)), 'work_limit' => 10,
                    'summary' => $this->batches->summary($supplier, $id, true)], supplierId: $supplier);
        }), 3);
        // Zámek dávky serializuje cancel/renew i souběžné potvrzení. Finanční operace
        // a outcome jsou atomické; starší commit/outcome gap dál obsluhuje finanční replay.
        foreach ($this->batches->workPage($supplier, $id, 'ready', 10) as $item) {
            $member = (int) $item['member_id'];
            $attempted = $item;
            try {
                (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplier, $id, $token, $member, &$attempted): void {
                    $batch = $this->lockedBatch($supplier, $id);
                    if ($batch['phase'] !== 'posting' || !$this->batches->approvalValid($supplier, $id, $token)) return;
                    $work = $this->batches->workPage($supplier, $id, 'ready', 50);
                    $found = array_column($work, null, 'member_id');
                    if (!isset($found[$member])) return;
                    $current = $found[$member];
                    $attempted = $current;
                    $this->batches->attempts($supplier, $id, $member);
                    $result = $this->charges->confirmIssue($supplier, ['member_id' => $member] + $batch['template'],
                        (int) $current['expected_case_version'], $current['preview_hash'], self::memberKey($batch, $member), $batch['confirmed_by']);
                    $this->batches->posted($supplier, $id, $member, 'created', $result['operation']['id']);
                });
            } catch (\Throwable $e) {
                $domain = ($e instanceof MembershipException || $e instanceof PostingException) && $e->httpStatus < 500;
                $transient = $e instanceof PDOException && in_array((int) ($e->errorInfo[1] ?? 0), [1213, 1205, 1020], true);
                if (!$domain) $this->logOperationalFailure($supplier, $id, $member, $actor, $e, $transient);
                // Vlastník transakce již provedl rollback. Cizí odvolanou transakci
                // nelze převést na výsledek položky ani v ní pokračovat.
                if (($outer && $e instanceof PDOException) || (!$outer && $this->db->pdo()->inTransaction())) throw $e;
                (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplier, $id, $token, $member, $attempted, $e, $transient, $actor): void {
                    $batch = $this->lockedBatch($supplier, $id);
                    if ($batch['phase'] !== 'posting' || $batch['approval_token'] !== $token
                        || !$this->batches->approvalValid($supplier, $id, $token)) return;
                    $current = $this->batches->workItem($supplier, $id, $member);
                    if ($current === null || $current['preview_hash'] !== $attempted['preview_hash']
                        || (int) $current['expected_case_version'] !== (int) $attempted['expected_case_version']) return;
                    $attempts = $this->batches->attempts($supplier, $id, $member);
                    $domain = ($e instanceof MembershipException || $e instanceof PostingException) && $e->httpStatus < 500;
                    if (!$transient || $attempts >= 3) {
                        $this->batches->posted($supplier, $id, $member, 'rejected', null,
                            $domain ? $e->errorCode : 'nonprofit_membership_batch_technical',
                            $domain ? $e->getMessage() : 'Položku nelze dokončit kvůli technické chybě.');
                    }
                    $this->activity->log('nonprofit_membership_batch_item_failure', $actor, 'nonprofit_membership_batch', $id,
                        ['member_id' => $member, 'attempts' => $attempts, 'technical' => !$domain, 'retryable' => $transient && $attempts < 3,
                            'exception_class' => $e::class, 'database_code' => $e instanceof PDOException ? (int) ($e->errorInfo[1] ?? 0) : null], supplierId: $supplier);
                });
            }
        }
        $this->batches->finishPosting($supplier, $id);
        return $this->detail($supplier, $id, write: true);
    }

    public function cancel(int $supplier, int $id, int $actor): array
    {
        (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplier, $id, $actor): void {
            $this->lockedBatch($supplier, $id);
            $this->batches->cancel($supplier, $id);
            $this->activity->log('nonprofit_membership_batch_cancel', $actor, 'nonprofit_membership_batch', $id, supplierId: $supplier);
        });
        return $this->detail($supplier, $id, write: true);
    }

    public function renewPreview(int $supplier, int $id, int $actor): array
    {
        (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplier, $id, $actor): void {
            $this->lockedBatch($supplier, $id);
            $this->batches->renewPreview($supplier, $id);
            $this->activity->log('nonprofit_membership_batch_renew_preview', $actor, 'nonprofit_membership_batch', $id, supplierId: $supplier);
        });
        return $this->detail($supplier, $id, write: true);
    }

    public static function memberKey(array $batch, int $member): string
    {
        return 'batch-' . $batch['batch_uid'] . '-member-' . $member;
    }

    private function logOperationalFailure(int $supplier, int $batch, int $member, int $actor, \Throwable $failure, bool $transient): void
    {
        $this->logger->log($transient ? 'warning' : 'error', 'Členská dávka: provozní chyba při potvrzení položky.', [
            'supplier_id' => $supplier, 'batch_id' => $batch, 'member_id' => $member, 'actor_id' => $actor,
            'transient' => $transient, 'database_code' => $failure instanceof PDOException ? (int) ($failure->errorInfo[1] ?? 0) : null,
            'exception' => $failure,
        ]);
    }

    private function assertApproval(int $supplier, int $id, string $token): void
    {
        if (!$this->batches->approvalValid($supplier, $id, $token)) {
            throw new MembershipException('nonprofit_membership_batch_not_ready', 'Náhled není připravený nebo souhlas vypršel. Obnovte náhled.', 409);
        }
    }

    private function seedPage(int $supplier, int $id): void
    {
        try {
            TransactionFailure::retrying($this->db->pdo(), fn () => $this->batches->seed($supplier, $id, $this->memberLimit()), 3);
        } catch (MembershipException $e) {
            if ($e->errorCode !== 'nonprofit_membership_batch_limit') throw $e;
            // Částečný seed nikdy nesmí dospět k souhlasu s neúplným výběrem.
            (new DimensionPeriodGuard($this->db))->transaction(fn () => $this->batches->cancel($supplier, $id));
            throw $e;
        }
    }

    private function lockedBatch(int $supplier, int $id): array
    {
        return $this->batches->find($supplier, $id, true)
            ?? throw new MembershipException('nonprofit_membership_batch_not_found', 'Dávka nebyla nalezena v této organizaci.', 404);
    }

    private function rateRequest(int $supplier, array $input, bool $lock = false): array
    {
        if (!is_int($input['rate_id'] ?? null) || $input['rate_id'] <= 0) self::invalid();
        $rate = (new MembershipRateRepository($this->db))->find($supplier, $input['rate_id'], $lock);
        if ($rate === null || !$rate['is_active']) throw new MembershipException('nonprofit_membership_rate_unavailable', 'Vybraná sazba není dostupná v této organizaci.', 422);
        if (!in_array($rate['period_months'], [1, 3, 6, 12], true)) self::invalid();
        foreach (['amount', 'receivable_account', 'revenue_account'] as $field) {
            if (array_key_exists($field, $input)) {
                if ($field === 'amount' && !is_string($input[$field]) && !is_int($input[$field]) && !is_float($input[$field])) self::invalid();
                try { $value = $field === 'amount' ? JournalLineAmount::decimal($input[$field]) : $input[$field]; }
                catch (\InvalidArgumentException) { self::invalid(); }
                if ($value !== $rate[$field]) self::invalid();
            }
            $input[$field] = $rate[$field];
        }
        if ($rate['period_months'] > 0) {
            $from = MembershipRevisionTimeline::date((string) ($input['period_from'] ?? ''));
            if (substr($from, 8, 2) !== '01') self::invalid();
            $to = (new \DateTimeImmutable($from))->modify('+' . $rate['period_months'] . ' months')->modify('-1 day')->format('Y-m-d');
            if (isset($input['period_to']) && $input['period_to'] !== $to) self::invalid();
            $input['period_to'] = $to;
        }
        return MembershipChargeRequest::issue(['member_id' => 1] + $input);
    }

    private function batch(int $supplier, int $id): array
    {
        return $this->batches->find($supplier, $id)
            ?? throw new MembershipException('nonprofit_membership_batch_not_found', 'Dávka nebyla nalezena v této organizaci.', 404);
    }

    private static function invalid(): never
    {
        throw new MembershipException('nonprofit_membership_batch_invalid', 'Dávka vyžaduje platnou sazbu, společné údaje a výběr členů.', 422);
    }
}
