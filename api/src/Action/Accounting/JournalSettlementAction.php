<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\GuardsDocumentLock;
use MyInvoice\Http\IsoDate;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Accounting\AdvanceDeductionService;
use MyInvoice\Service\Accounting\DocumentLock;
use MyInvoice\Service\Accounting\JournalSettlementException;
use MyInvoice\Service\Accounting\JournalSettlementService;
use MyInvoice\Service\Accounting\Reports\ReportException;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Vyrovnání položky saldokonta zápisem v deníku ({@see JournalSettlementService}).
 *
 *   GET    /api/accounting/saldo-settlements/candidates?doc_type=&doc_id=&account=&as_of=
 *   GET    /api/accounting/saldo-settlements/residual-lines?account=&as_of=&q=
 *   GET    /api/accounting/saldo-settlements/suggestions?period_id=&as_of=&account=
 *   POST   /api/accounting/saldo-settlements            : {allocations:[…]} nebo {groups:[{allocations:[…]}]}
 *   DELETE /api/accounting/saldo-settlements/{id}
 *   POST   /api/accounting/saldo-settlements/other-item : ostatní položka ze zůstatku bez dokladu
 *   GET    /api/accounting/saldo-settlements/advance-candidates?doc_type=&doc_id=&account=&as_of=
 *   POST   /api/accounting/saldo-settlements/advance-deductions : {deductions:[…]} nebo {groups:[{deductions:[…]}]}
 *   DELETE /api/accounting/saldo-settlements/advance-deductions/{doc_type}/{id}
 *
 * Zálohu na 314/324 vyrovná čerpání konečnou fakturou ({@see AdvanceDeductionService}),
 * ne zápis v deníku. Čerpání platí od zaúčtování konečné faktury, proto zápis faktury
 * v uzavřeném období hlídá stejné přebití správcem jako rozpad.
 *
 * Práva řeší RoutePermissionMap: čtení 'accounting' READ, zápis 'accounting.journal.write'
 * (rozpad je rozpis zápisu v deníku, stejně jako jeho vazba na doklad). Založení ostatní
 * položky navíc 'other_items' WRITE.
 *
 * Rozpad deník nemění, ale mění saldokonto k datu zápisu. Zápis v uzavřeném období proto
 * projde jen správci s `?force=1` a zapíše se do auditu ({@see GuardsDocumentLock}),
 * stejně jako úprava dokladu v uzavřeném období.
 */
final class JournalSettlementAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;
    use GuardsDocumentLock;

    public function __construct(
        private readonly JournalSettlementService $settlements,
        private readonly AccountingPeriodRepository $periods,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly LoggerInterface $log,
        private readonly Connection $db,
        private readonly AdvanceDeductionService $advances,
    ) {}

    public function candidates(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $q = $request->getQueryParams();
        $asOf = $this->asOf($q, $response, $err);
        if ($asOf === null) return $err;
        try {
            return Json::ok($response, $this->settlements->candidates(
                $supplierId,
                (string) ($q['doc_type'] ?? ''),
                (int) ($q['doc_id'] ?? 0),
                (string) ($q['account'] ?? ''),
                $asOf,
            ));
        } catch (\Throwable $e) {
            return $this->mapError($response, $e);
        }
    }

    public function residualLines(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $q = $request->getQueryParams();
        $asOf = $this->asOf($q, $response, $err);
        if ($asOf === null) return $err;
        try {
            return Json::ok($response, [
                'items' => $this->settlements->residualLines($supplierId, (string) ($q['account'] ?? ''), $asOf, (string) ($q['q'] ?? '')),
            ]);
        } catch (\Throwable $e) {
            return $this->mapError($response, $e);
        }
    }

    public function suggestions(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $q = $request->getQueryParams();
        $periodId = (int) ($q['period_id'] ?? 0);
        if ($periodId <= 0 || $this->periods->findById($supplierId, $periodId) === null) {
            return Json::error($response, 'not_found', 'Účetní období nenalezeno.', 404);
        }
        $rawAsOf = trim((string) ($q['as_of'] ?? ''));
        if ($rawAsOf !== '' && !IsoDate::isValid($rawAsOf)) {
            return Json::error($response, 'validation_failed', 'as_of musí být datum (YYYY-MM-DD).', 422);
        }
        $account = trim((string) ($q['account'] ?? 'all'));
        try {
            return Json::ok($response, $this->settlements->suggestions($supplierId, $periodId, $rawAsOf === '' ? null : $rawAsOf, $account === '' ? 'all' : $account));
        } catch (\Throwable $e) {
            return $this->mapError($response, $e);
        }
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->requirePermission($request, $response, 'accounting.journal.write', AccessLevel::WRITE, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $body = (array) ($request->getParsedBody() ?? []);
        $batch = array_key_exists('groups', $body);
        $groups = $batch ? $body['groups'] : [['allocations' => $body['allocations'] ?? null]];
        if (!is_array($groups) || $groups === [] || !array_is_list($groups) || count($groups) > JournalSettlementService::MAX_GROUPS) {
            return Json::error($response, 'invalid_allocations', 'Zadejte 1 až ' . JournalSettlementService::MAX_GROUPS . ' skupin vyrovnání.', 422);
        }
        $lineIds = [];
        foreach ($groups as $group) {
            foreach ((array) (is_array($group) ? ($group['allocations'] ?? []) : []) as $row) {
                if (is_array($row)) $lineIds[] = (int) ($row['line_id'] ?? 0);
            }
        }
        if ($deny = $this->denyClosedPeriod($request, $response, $supplierId, $lineIds)) return $deny;

        $userId = $this->userId($request);
        $created = [];
        $failed = [];
        foreach ($groups as $index => $group) {
            try {
                $rows = is_array($group) ? ($group['allocations'] ?? null) : null;
                $created = [...$created, ...$this->settlements->allocate($supplierId, is_array($rows) ? $rows : [], $userId)];
            } catch (JournalSettlementException $e) {
                if (!$batch) return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
                $failed[] = ['index' => $index, 'code' => $e->errorCode, 'message' => $e->getMessage()];
            } catch (\Throwable $e) {
                return $this->mapError($response, $e);
            }
        }
        if ($created !== []) {
            $this->audit($request, 'accounting.saldo_allocation_created', null, [
                'allocations' => array_map(static fn (array $a): array => [
                    'id' => $a['id'], 'entry_id' => $a['entry_id'], 'doc_type' => $a['doc_type'],
                    'doc_id' => $a['doc_id'], 'amount' => $a['amount'],
                ], $created),
            ], $supplierId);
        }
        return Json::ok($response, ['created' => $created, 'failed' => $failed], $created === [] ? 200 : 201);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'accounting.journal.write', AccessLevel::WRITE, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);
        $status = $this->settlements->closedPeriodOfAllocation($supplierId, $id);
        if ($status !== null) {
            $lock = new DocumentLock(false, null, false, null, true, false, $status);
            if ($deny = $this->denyIfLocked($request, $response, $lock, 'journal_settlement_allocation', $id)) return $deny;
        }
        try {
            $deleted = $this->settlements->delete($supplierId, $id);
        } catch (\Throwable $e) {
            return $this->mapError($response, $e);
        }
        $this->audit($request, 'accounting.saldo_allocation_deleted', $deleted['entry_id'], [
            'allocation_id' => $deleted['id'], 'doc_type' => $deleted['doc_type'],
            'doc_id' => $deleted['doc_id'], 'amount' => $deleted['amount'],
        ], $supplierId);
        return Json::ok($response, ['deleted' => $deleted['id']]);
    }

    public function createOtherItem(Request $request, Response $response): Response
    {
        if (!$this->requirePermission($request, $response, 'accounting.journal.write', AccessLevel::WRITE, $err)) return $err;
        if (!$this->requirePermission($request, $response, 'other_items', AccessLevel::WRITE, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $body = (array) ($request->getParsedBody() ?? []);
        $lineId = (int) ($body['line_id'] ?? 0);
        $dueOn = trim((string) ($body['due_on'] ?? ''));
        if ($dueOn !== '' && !IsoDate::isValid($dueOn)) {
            return Json::error($response, 'validation_failed', 'due_on musí být datum (YYYY-MM-DD).', 422);
        }
        if ($deny = $this->denyClosedPeriod($request, $response, $supplierId, [$lineId])) return $deny;
        try {
            $result = $this->settlements->createOtherItem($supplierId, [
                'line_id'    => $lineId,
                'amount'     => $body['amount'] ?? null,
                'partner_id' => isset($body['partner_id']) && (int) $body['partner_id'] > 0 ? (int) $body['partner_id'] : null,
                'title'      => is_string($body['title'] ?? null) ? $body['title'] : null,
                'due_on'     => $dueOn,
            ], $this->userId($request));
        } catch (\Throwable $e) {
            return $this->mapError($response, $e);
        }
        $this->audit($request, 'accounting.saldo_other_item_created', $result['line']['entry_id'], [
            'other_item_id' => (int) $result['item']['id'],
            'allocation_id' => $result['allocation_id'],
            'amount'        => (float) $result['item']['amount'],
        ], $supplierId);
        return Json::ok($response, ['item' => $result['item'], 'allocation_id' => $result['allocation_id']], 201);
    }

    public function advanceCandidates(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $q = $request->getQueryParams();
        $asOf = $this->asOf($q, $response, $err);
        if ($asOf === null) return $err;
        try {
            return Json::ok($response, $this->advances->candidates(
                $supplierId,
                (string) ($q['doc_type'] ?? ''),
                (int) ($q['doc_id'] ?? 0),
                (string) ($q['account'] ?? ''),
                $asOf,
            ));
        } catch (\Throwable $e) {
            return $this->mapError($response, $e);
        }
    }

    /**
     * Čerpání záloh konečnými fakturami: {deductions:[{doc_type, advance_id, final_id, amount}]}
     * nebo hromadně {groups:[{deductions:[…]}]} (návrh párování, každá skupina zvlášť).
     */
    public function createAdvanceDeductions(Request $request, Response $response): Response
    {
        if (!$this->requirePermission($request, $response, 'accounting.journal.write', AccessLevel::WRITE, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $body = (array) ($request->getParsedBody() ?? []);
        $batch = array_key_exists('groups', $body);
        $groups = $batch ? $body['groups'] : [['deductions' => $body['deductions'] ?? null]];
        if (!is_array($groups) || $groups === [] || !array_is_list($groups) || count($groups) > JournalSettlementService::MAX_GROUPS) {
            return Json::error($response, 'invalid_deductions', 'Zadejte 1 až ' . JournalSettlementService::MAX_GROUPS . ' skupin čerpání.', 422);
        }
        $finals = [];
        foreach ($groups as $group) {
            foreach ((array) (is_array($group) ? ($group['deductions'] ?? []) : []) as $row) {
                if (is_array($row) && isset(array_flip(AdvanceDeductionService::ACCOUNTS)[(string) ($row['doc_type'] ?? '')])) {
                    $finals[(string) $row['doc_type']][] = (int) ($row['final_id'] ?? 0);
                }
            }
        }
        foreach ($finals as $docType => $ids) {
            $closed = $this->advances->closedPeriodEntries($supplierId, $docType, $ids);
            if ($closed !== []) {
                $lock = new DocumentLock(false, null, false, null, true, false, $closed[0]['period_status']);
                if ($deny = $this->denyIfLocked($request, $response, $lock, 'journal_entry', $closed[0]['entry_id'])) return $deny;
            }
        }

        $userId = $this->userId($request);
        $created = [];
        $failed = [];
        foreach ($groups as $index => $group) {
            try {
                $rows = is_array($group) ? ($group['deductions'] ?? null) : null;
                $done = $this->advances->deduct($supplierId, is_array($rows) ? $rows : [], $userId);
            } catch (JournalSettlementException $e) {
                if (!$batch) return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
                $failed[] = ['index' => $index, 'code' => $e->errorCode, 'message' => $e->getMessage()];
                continue;
            } catch (\Throwable $e) {
                return $this->mapError($response, $e);
            }
            // Každá skupina je samostatná potvrzená transakce: audit hned po ní, aby potvrzené
            // čerpání bez záznamu nezůstalo ani při chybě některé další skupiny.
            $this->audit($request, 'accounting.saldo_advance_deduction_created', null, [
                'deductions' => array_map(static fn (array $d): array => [
                    'id' => $d['id'], 'doc_type' => $d['doc_type'], 'advance_id' => $d['advance_id'],
                    'final_id' => $d['final_id'], 'amount' => $d['amount'],
                ], $done),
            ], $supplierId);
            $created = [...$created, ...$done];
        }
        return Json::ok($response, ['created' => $created, 'failed' => $failed], $created === [] ? 200 : 201);
    }

    public function deleteAdvanceDeduction(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'accounting.journal.write', AccessLevel::WRITE, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $docType = (string) ($args['doc_type'] ?? '');
        $id = (int) ($args['id'] ?? 0);
        try {
            $closed = $this->advances->closedPeriodOfDeduction($supplierId, $docType, $id);
            if ($closed !== null) {
                $lock = new DocumentLock(false, null, false, null, true, false, $closed['period_status']);
                if ($deny = $this->denyIfLocked($request, $response, $lock, 'journal_entry', $closed['entry_id'])) return $deny;
            }
            $deleted = $this->advances->delete($supplierId, $docType, $id);
        } catch (\Throwable $e) {
            return $this->mapError($response, $e);
        }
        $this->audit($request, 'accounting.saldo_advance_deduction_deleted', null, [
            'deduction_id' => $deleted['id'], 'doc_type' => $deleted['doc_type'], 'advance_id' => $deleted['advance_id'],
            'final_id' => $deleted['final_id'], 'amount' => $deleted['amount'],
        ], $supplierId);
        return Json::ok($response, ['deleted' => $deleted['id']]);
    }

    /** @param list<int> $lineIds */
    private function denyClosedPeriod(Request $request, Response $response, int $supplierId, array $lineIds): ?Response
    {
        $closed = $this->settlements->closedPeriodEntries($supplierId, $lineIds);
        if ($closed === []) {
            return null;
        }
        $lock = new DocumentLock(false, null, false, null, true, false, $closed[0]['period_status']);
        return $this->denyIfLocked($request, $response, $lock, 'journal_entry', $closed[0]['entry_id']);
    }

    /** @param array<string,mixed> $q */
    private function asOf(array $q, Response $response, ?Response &$err): ?string
    {
        $asOf = trim((string) ($q['as_of'] ?? ''));
        if ($asOf === '') {
            $err = null;
            return date('Y-m-d');
        }
        if (!IsoDate::isValid($asOf)) {
            $err = Json::error($response, 'validation_failed', 'as_of musí být datum (YYYY-MM-DD).', 422);
            return null;
        }
        $err = null;
        return $asOf;
    }

    private function mapError(Response $response, \Throwable $e): Response
    {
        if ($e instanceof JournalSettlementException) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        if ($e instanceof ReportException) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        $this->log->error('Vyrovnání saldokonta selhalo: ' . $e->getMessage(), ['exception' => $e]);
        return Json::error($response, 'operation_failed', 'Vyrovnání se nepodařilo provést.', 500);
    }

    /** @param array<string,mixed> $payload */
    private function audit(Request $request, string $action, ?int $entryId, array $payload, int $supplierId): void
    {
        $this->logger->log(
            $action,
            $this->userId($request),
            'journal_entry',
            $entryId,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );
    }
}
