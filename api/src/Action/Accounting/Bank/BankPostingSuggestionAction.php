<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Bank;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankPostingRuleRepository;
use MyInvoice\Repository\BankPostingSuggestionRepository;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use MyInvoice\Service\Accounting\Learning\RulePromotionService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Bank\BankMovementListService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Fronta návrhů zaúčtování + protokol automatiky — REST API (mini-epic
 * AUTOMATIZACE, §5). List/count = čtení, approve/reject/bulk = write.
 */
final class BankPostingSuggestionAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    private const MAX_PER_PAGE = 100;
    private const VALID_STATUS = ['pending', 'needs_input', 'blocked', 'approved', 'rejected', 'auto_posted', 'superseded'];
    private const REJECT_REASONS = ['wrong_account', 'not_ours', 'duplicate', 'other'];
    private const SNOOZE_REASONS = ['later', 'waiting_document', 'needs_review'];

    public function __construct(
        private readonly BankPostingService $service,
        private readonly BankPostingSuggestionRepository $suggestions,
        private readonly BankPostingRuleRepository $rules,
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly BankMovementListService $movements,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $q = $request->getQueryParams();
        $status = in_array($q['status'] ?? null, self::VALID_STATUS, true) ? (string) $q['status'] : 'pending';
        $account = isset($q['account']) && $q['account'] !== '' ? (string) $q['account'] : null;
        $page = max(1, (int) ($q['page'] ?? 1));
        $perPage = max(1, min(self::MAX_PER_PAGE, (int) ($q['per_page'] ?? 50)));

        $res = $this->suggestions->paginate($supplierId, $status, $account, $perPage, ($page - 1) * $perPage);
        return Json::ok($response, [
            'items'    => $res['items'],
            'total'    => $res['total'],
            'page'     => $page,
            'per_page' => $perPage,
        ]);
    }

    public function count(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        return Json::ok($response, $this->suggestions->queueCounts($supplierId));
    }

    public function unposted(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $q = $request->getQueryParams();
        // Fronta k zaúčtování. scope=all zůstává kvůli zpětné kompatibilitě; záložka
        // „Všechny pohyby" čte GET /api/bank-transactions, který není vázaný na režim účetnictví.
        $scope = ($q['scope'] ?? '') === 'all' ? 'all' : 'unposted';
        return Json::ok($response, $this->movements->list($supplierId, $q, $scope));
    }

    public function unpostedCount(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        return Json::ok($response, ['unposted' => $this->suggestions->unpostedCount($supplierId)]);
    }

    public function approve(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) $args['id'];
        $body = (array) ($request->getParsedBody() ?? []);
        $overrides = [];
        foreach (['debit_account_code', 'credit_account_code'] as $key) {
            if (isset($body[$key]) && trim((string) $body[$key]) !== '') {
                $overrides[$key] = trim((string) $body[$key]);
            }
        }
        $selectedRuleId = isset($body['selected_rule_id']) && (int) $body['selected_rule_id'] > 0
            ? (int) $body['selected_rule_id'] : null;
        $before = $this->suggestions->find($supplierId, $id);
        try {
            $entryId = $this->service->approveSuggestion($supplierId, $id, $this->auditMeta($request), $overrides, $selectedRuleId);
        } catch (\Throwable $e) {
            return $this->mapPostingError($response, $e);
        }
        $this->log($request, 'bank_suggestion.approved', $id, ['entry_id' => $entryId]);
        $ruleId = $selectedRuleId ?? ($before['rule_id'] ?? null);
        $rule = $ruleId !== null ? $this->rules->find($supplierId, (int) $ruleId) : null;
        return Json::ok($response, [
            'journal_entry_id' => $entryId,
            'document_no' => $this->documentNo($supplierId, $entryId),
            'rule_progress' => $rule === null ? null : [
                'rule_id' => (int) $rule['id'],
                'rule_name' => (string) $rule['name'],
                'approved_streak' => (int) $rule['approved_streak'],
                'promotion_candidate' => RulePromotionService::isCandidate($rule),
            ],
        ]);
    }

    public function reject(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) $args['id'];
        $body = (array) ($request->getParsedBody() ?? []);
        $note = isset($body['note']) && trim((string) $body['note']) !== '' ? trim((string) $body['note']) : null;
        if ($note !== null && !in_array($note, self::REJECT_REASONS, true)) {
            return Json::error($response, 'invalid_reason', 'Vyberte platný důvod odmítnutí.', 422);
        }

        $before = $this->suggestions->find($supplierId, $id);
        try {
            $this->service->rejectSuggestion($supplierId, $id, $this->auditMeta($request), $note);
        } catch (\Throwable $e) {
            return $this->mapPostingError($response, $e);
        }
        $this->log($request, 'bank_suggestion.rejected', $id, []);

        $result = ['rejected' => true];
        if ($before !== null && $before['rule_id'] !== null) {
            $rule = $this->rules->find($supplierId, (int) $before['rule_id']);
            if ($rule !== null && $rule['is_active'] === false) {
                $result['rule_disabled'] = true;
            }
        }
        return Json::ok($response, $result);
    }

    public function bulkApprove(Request $request, Response $response): Response
    {
        if (!$this->requireWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $body = (array) ($request->getParsedBody() ?? []);
        $ids = $this->ids($body);
        if (count($ids) > 200) {
            return Json::error($response, 'too_many_ids', 'Najednou lze schválit nejvýše 200 návrhů.', 422);
        }

        $meta = $this->auditMeta($request);
        $batchId = bin2hex(random_bytes(16));
        $approved = 0;
        $approvedIds = [];
        $failed = [];
        $pdo = $this->db->pdo();
        foreach ($ids as $id) {
            $savepoint = $pdo->inTransaction();
            $itemTxStarted = false;
            try {
                $suggestion = $this->suggestions->find($supplierId, $id);
                if ($suggestion === null) {
                    $failed[] = ['id' => $id, 'code' => 'not_found'];
                    continue;
                }
                if ((string) $suggestion['status'] !== 'pending') {
                    $failed[] = ['id' => $id, 'code' => 'suggestion_not_pending'];
                    continue;
                }
                if (in_array((string) $suggestion['source'], BankPostingSuggestionRepository::AI_SOURCES, true)) {
                    $failed[] = ['id' => $id, 'code' => 'ai_bulk_forbidden'];
                    continue;
                }
                if ($savepoint) {
                    $pdo->exec('SAVEPOINT bulk_approve_item');
                } else {
                    $pdo->beginTransaction();
                }
                $itemTxStarted = true;
                $this->service->approveSuggestion($supplierId, $id, $meta);
                if (!$this->suggestions->assignBatch($supplierId, $id, $batchId)) {
                    throw new \RuntimeException('Schválený návrh se nepodařilo přiřadit do dávky.');
                }
                if ($savepoint) {
                    $pdo->exec('RELEASE SAVEPOINT bulk_approve_item');
                } else {
                    $pdo->commit();
                }
                $approved++;
                $approvedIds[] = $id;
            } catch (\MyInvoice\Service\Accounting\PostingException $e) {
                if ($itemTxStarted) $this->rollbackItem($pdo, $savepoint);
                $failed[] = ['id' => $id, 'code' => $e->errorCode];
            } catch (\Throwable $e) {
                if ($itemTxStarted) $this->rollbackItem($pdo, $savepoint);
                $failed[] = ['id' => $id, 'code' => 'error'];
            }
        }
        $this->log($request, 'bank_suggestion.bulk_approved', 0, ['approved' => $approved, 'failed' => count($failed)]);
        return Json::ok($response, [
            'approved' => $approved,
            'approved_ids' => $approvedIds,
            'failed' => $failed,
            'batch_id' => $approved > 0 ? $batchId : null,
        ]);
    }

    public function bulkPreview(Request $request, Response $response): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $ids = $this->ids((array) ($request->getParsedBody() ?? []));
        if (count($ids) > 200) return Json::error($response, 'too_many_ids', 'Najednou lze zkontrolovat nejvýše 200 návrhů.', 422);

        $items = [];
        $failed = [];
        $accounts = [];
        foreach ($ids as $id) {
            try {
                $preview = $this->service->previewSuggestion($supplierId, $id);
                $items[] = $preview;
                foreach ($preview['lines'] as $line) {
                    $key = $preview['currency'] . '|' . $line['account_code'];
                    $accounts[$key] ??= [
                        'currency' => $preview['currency'],
                        'account_code' => (string) $line['account_code'],
                        'debit' => 0.0,
                        'credit' => 0.0,
                    ];
                    $side = (string) $line['side'];
                    $accounts[$key][$side] = round((float) $accounts[$key][$side] + (float) $line['amount'], 2);
                }
            } catch (\MyInvoice\Service\Accounting\PostingException $e) {
                $failed[] = ['id' => $id, 'code' => $e->errorCode];
            } catch (\Throwable) {
                $failed[] = ['id' => $id, 'code' => 'error'];
            }
        }
        return Json::ok($response, [
            'count' => count($items),
            'items' => $items,
            'accounts' => array_values($accounts),
            'failed' => $failed,
        ]);
    }

    public function bulkReject(Request $request, Response $response): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $body = (array) ($request->getParsedBody() ?? []);
        $ids = $this->ids($body);
        $reason = (string) ($body['reason'] ?? '');
        if (!in_array($reason, self::REJECT_REASONS, true)) {
            return Json::error($response, 'invalid_reason', 'Vyberte platný důvod odmítnutí.', 422);
        }
        if (count($ids) > 200) return Json::error($response, 'too_many_ids', 'Najednou lze odmítnout nejvýše 200 návrhů.', 422);

        $meta = $this->auditMeta($request);
        $rejected = 0;
        $failed = [];
        foreach ($ids as $id) {
            try {
                $suggestion = $this->suggestions->find($supplierId, $id);
                if ($suggestion === null) {
                    $failed[] = ['id' => $id, 'code' => 'not_found'];
                    continue;
                }
                if ((string) $suggestion['status'] !== 'pending') {
                    $failed[] = ['id' => $id, 'code' => 'suggestion_not_pending'];
                    continue;
                }
                if (in_array((string) $suggestion['source'], BankPostingSuggestionRepository::AI_SOURCES, true)) {
                    $failed[] = ['id' => $id, 'code' => 'ai_bulk_forbidden'];
                    continue;
                }
                $this->service->rejectSuggestion($supplierId, $id, $meta, $reason);
                $rejected++;
            } catch (\MyInvoice\Service\Accounting\PostingException $e) {
                $failed[] = ['id' => $id, 'code' => $e->errorCode];
            } catch (\Throwable) {
                $failed[] = ['id' => $id, 'code' => 'error'];
            }
        }
        $this->log($request, 'bank_suggestion.bulk_rejected', 0, [
            'reason' => $reason, 'rejected' => $rejected, 'failed' => count($failed),
        ]);
        return Json::ok($response, ['rejected' => $rejected, 'failed' => $failed]);
    }

    public function snooze(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $body = (array) ($request->getParsedBody() ?? []);
        $until = isset($body['until']) && trim((string) $body['until']) !== '' ? trim((string) $body['until']) : null;
        $reason = isset($body['reason']) && trim((string) $body['reason']) !== '' ? trim((string) $body['reason']) : null;
        if ($reason !== null && !in_array($reason, self::SNOOZE_REASONS, true)) {
            return Json::error($response, 'invalid_reason', 'Neplatný důvod odložení.', 422);
        }
        if ($until !== null) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $until);
            if ($date === false || $date->format('Y-m-d') !== $until || $date < new \DateTimeImmutable('today') || $date > new \DateTimeImmutable('+1 year')) {
                return Json::error($response, 'invalid_until', 'Datum odložení musí být platné budoucí datum.', 422);
            }
            $until = $date->format('Y-m-d 23:59:59');
        }
        if (!$this->suggestions->snooze($supplierId, (int) $args['id'], $until, $reason, $this->userId($request))) {
            return Json::error($response, 'not_found', 'Nevyřízený návrh nebyl nalezen.', 404);
        }
        $this->log($request, $until === null ? 'bank_suggestion.unsnoozed' : 'bank_suggestion.snoozed', (int) $args['id'], [
            'until' => $until, 'reason' => $reason,
        ]);
        return Json::ok($response, ['snoozed_until' => $until, 'snooze_reason' => $reason]);
    }

    public function undoBatch(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $batchId = strtolower((string) ($args['batchId'] ?? ''));
        if (preg_match('/^[a-f0-9]{32}$/', $batchId) !== 1) {
            return Json::error($response, 'invalid_batch', 'Neplatný identifikátor dávky.', 422);
        }

        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT bulk_undo_batch');
        }
        try {
            $rows = $this->suggestions->findBatch($supplierId, $batchId, true);
            if ($rows === []) throw new \MyInvoice\Service\Accounting\PostingException('not_found', 'Dávka nebyla nalezena.', 404);
            $reversalIds = [];
            $alreadyReversed = 0;
            foreach ($rows as $row) {
                if ($row['journal_entry_id'] === null || $row['reversed_by'] !== null) {
                    $alreadyReversed++;
                    continue;
                }
                $meta = $this->auditMeta($request);
                $meta['reason'] = 'bulk_undo:' . $batchId;
                $meta['description'] = 'Storno dávky automatiky ' . $batchId;
                $reversalIds[] = $this->service->unpost($supplierId, (int) $row['bank_transaction_id'], $meta);
            }
            if ($ownTx) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT bulk_undo_batch');
            }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            } elseif (!$ownTx && $pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT bulk_undo_batch');
                $pdo->exec('RELEASE SAVEPOINT bulk_undo_batch');
            }
            return $this->mapPostingError($response, $e);
        }
        $this->log($request, 'bank_suggestion.batch_unposted', 0, [
            'batch_id' => $batchId, 'reversed' => count($reversalIds), 'already_reversed' => $alreadyReversed,
        ]);
        return Json::ok($response, [
            'batch_id' => $batchId,
            'reversed' => count($reversalIds),
            'already_reversed' => $alreadyReversed,
            'reversal_entry_ids' => $reversalIds,
        ]);
    }

    /** @return list<int> */
    private function ids(array $body): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', (array) ($body['ids'] ?? [])),
            static fn (int $value): bool => $value > 0,
        )));
    }

    private function rollbackItem(\PDO $pdo, bool $savepoint): void
    {
        if (!$pdo->inTransaction()) return;
        if ($savepoint) {
            $pdo->exec('ROLLBACK TO SAVEPOINT bulk_approve_item');
            $pdo->exec('RELEASE SAVEPOINT bulk_approve_item');
        } else {
            $pdo->rollBack();
        }
    }

    private function documentNo(int $supplierId, int $entryId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT document_no FROM journal_entries WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$entryId, $supplierId]);
        $v = $stmt->fetchColumn();
        return $v === false || $v === null ? null : (string) $v;
    }

    private function log(Request $request, string $action, int $id, array $payload): void
    {
        $this->logger->log($action, $this->userId($request), 'bank_posting_suggestion', $id ?: null, $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'), $this->currentSupplierId($request));
    }
}
