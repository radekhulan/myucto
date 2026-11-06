<?php

declare(strict_types=1);
namespace MyInvoice\Service\Property\Payments\Workflow;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Payments\PaymentRepository;
use MyInvoice\Repository\Property\Payments\PaymentWorkflowRepository;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Property\Payments\{PaymentException,PaymentLocks,PaymentSourceProof,PaymentJournalProof};
use MyInvoice\Service\Property\PropertyInput;
use PDO;

final class PaymentWorkflowFacade
{
    public function __construct(private readonly Connection $db, private readonly PaymentWorkflowPlanner $planner,
        private readonly PaymentRepository $payments, private readonly BankPostingService $bank,
        private readonly \MyInvoice\Service\Accounting\Cash\CashDocumentService $cash) {}

    public function preview(int $supplier, array $request): array
    {
        return (new DimensionPeriodGuard($this->db))->transaction(fn () => $this->planner->current($supplier, $request));
    }

    public function confirmAuthorized(int $supplier, array $request, string $previewHash, string $token, int $actor, bool $canPostBank, bool $canPostCash): array
    {
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplier, $request, $previewHash, $token, $actor, $canPostBank, $canPostCash): array {
            (new PaymentLocks($this->db))->acquire($supplier);
            $old = (new PaymentWorkflowRepository($this->db))->retry($supplier, $request, $previewHash, $token, $actor);
            if ($old !== null) return $this->result($old);
            $plan = $this->planner->current($supplier, $request);
            if ($plan['snapshot']['native'] !== null && !($plan['source']['kind'] === 'bank' ? $canPostBank : $canPostCash)) {
                throw new PaymentException('forbidden', 'Zaúčtování zdrojové platby vyžaduje také oprávnění banky nebo pokladny.', 403);
            }
            return $this->confirm($supplier, $request, $previewHash, $token, $actor);
        });
    }

    public function confirm(int $supplier, array $request, string $previewHash, string $token, int $actor): array
    {
        PropertyInput::code($token, 'idempotency_key');
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplier, $request, $previewHash, $token, $actor): array {
            (new PaymentLocks($this->db))->acquire($supplier);
            $repository = new PaymentWorkflowRepository($this->db);
            $old = $repository->retry($supplier, $request, $previewHash, $token, $actor);
            if ($old !== null) return $this->result($old);
            $plan = $this->planner->current($supplier, $request);
            if (!hash_equals($previewHash, $plan['preview_hash'])) PaymentException::reject('preview_stale');
            $id = $repository->prepare($supplier, $request, $plan, $token, $actor);
            $kind = $plan['snapshot']['source']['kind'];
            $existing = $plan['snapshot']['source']['existing'];
            if ($existing !== null) {
                $nativeId = $plan['snapshot']['source']['id'];
                $current = (new PaymentSourceProof($this->db))->current($supplier, $kind, $nativeId);
                if (PropertyInput::hash($current) !== PropertyInput::hash($existing)) PaymentException::reject('preview_stale');
                $entry = $current['receipt']['ledger']['entry']['id'];
                $repository->bindJournal($supplier, $id, 1, $entry);
            } elseif ($kind === 'bank') {
                $intent = PaymentClearingIntent::fromPending($this->db, $this->planner, $supplier, $id, 1);
                $entry = $this->bank->postPropertyClearing($intent);
                $nativeId = $plan['snapshot']['source']['id'];
            } else {
                $intent = PaymentClearingIntent::fromPending($this->db, $this->planner, $supplier, $id, 1);
                $native = $this->cash->postPropertyClearing($intent);
                $entry = $native['journal_entry_id']; $nativeId = $native['id'];
            }
            foreach ($plan['snapshot']['parts'] as $index => $part) {
                $source = (new PaymentSourceProof($this->db))->current($supplier, $kind, $nativeId);
                $partRequest = $part['request'];
                $expectedPart = $part['plan']['snapshot'];
                if ($kind === 'cash' && $plan['snapshot']['source']['id'] === null) {
                    $expectedPart = PaymentWorkflowRealization::cash($expectedPart, $nativeId);
                    if (isset($partRequest['cash_receipt']) && $partRequest['cash_receipt']['cash_document_id'] === null) $partRequest['cash_receipt']['cash_document_id'] = $nativeId;
                }
                $partRequest['source'] = ['kind' => $kind, 'id' => $nativeId, 'expected_version' => $source['version']];
                $current = $this->payments->preview($supplier, $partRequest);
                if (isset($part['source_projection_hash'])) {
                    $expectedPart = PaymentWorkflowRealization::projectedSource($expectedPart, $current['snapshot']);
                }
                foreach (['allocations', 'credit', 'reservation'] as $field) {
                    if (PropertyInput::hash($current['snapshot'][$field] ?? []) !== PropertyInput::hash($expectedPart[$field] ?? [])) PaymentException::reject('workflow_plan_changed');
                }
                if (PropertyInput::hash(PaymentJournalProof::canonicalLines($current['snapshot']['lines'])) !== PropertyInput::hash(PaymentJournalProof::canonicalLines($expectedPart['lines']))) PaymentException::reject('workflow_plan_changed');
                $operation = $this->payments->apply($supplier, $partRequest, $current['preview_hash'], 'workflow.' . $id . '.' . $index, $actor);
                $q = $this->db->pdo()->prepare('INSERT INTO unit_payment_workflow_operations (supplier_id,workflow_id,operation_id,part_key,part_no) VALUES (?,?,?,?,?)');
                $q->execute([$supplier, $id, $operation['id'], $part['key'], $index + 1]);
            }
            $source = (new PaymentSourceProof($this->db))->current($supplier, $kind, $nativeId);
            if ($source['available_cents'] !== $plan['snapshot']['available_cents']) PaymentException::reject('workflow_plan_changed');
            $q = $this->db->pdo()->prepare("UPDATE unit_payment_workflows SET source_id=?,status='confirmed',row_version=row_version+1 WHERE supplier_id=? AND id=? AND status='pending' AND journal_entry_id=? AND source_id IS NULL");
            $q->execute([$source['id'], $supplier, $id, $entry]);
            if ($q->rowCount() !== 1) PaymentException::reject('workflow_version_conflict');
            return $this->result($repository->find($supplier, $id));
        });
    }

    private function result(array $workflow): array
    {
        $q = $this->db->pdo()->prepare('SELECT part_key,operation_id FROM unit_payment_workflow_operations WHERE supplier_id=? AND workflow_id=? ORDER BY part_no FOR UPDATE');
        $q->execute([$workflow['supplier_id'], $workflow['id']]);
        return ['id' => (int) $workflow['id'], 'status' => $workflow['status'], 'source' => ['kind' => $workflow['source_kind'],
            'id' => (int) ($workflow['bank_transaction_id'] ?? $workflow['cash_document_id'])], 'journal_entry_id' => (int) $workflow['journal_entry_id'],
            'settled_cents' => $workflow['plan']['settled_cents'], 'available_cents' => $workflow['plan']['available_cents'], 'parts' => $q->fetchAll(PDO::FETCH_ASSOC)];
    }
}
