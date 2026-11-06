<?php

declare(strict_types=1);
namespace MyInvoice\Service\Property\Payments\Workflow;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Payments\PaymentWorkflowRepository;
use MyInvoice\Service\Property\Payments\PaymentException;
use MyInvoice\Service\Property\PropertyInput;
use PDO;

final class PaymentClearingIntent
{
    private function __construct(private readonly Connection $db, private readonly PaymentWorkflowPlanner $planner,
        public readonly int $supplier, public readonly int $workflowId, public readonly int $version) {}

    public static function fromPending(Connection $db, PaymentWorkflowPlanner $planner, int $supplier, int $workflowId, int $version): self
    {
        $intent = new self($db, $planner, $supplier, $workflowId, $version);
        $intent->current();
        return $intent;
    }

    public function __serialize(): array { throw new \LogicException('A live clearing intent cannot be serialized.'); }
    public function __unserialize(array $data): void { throw new \LogicException('A live clearing intent cannot be restored.'); }
    private function __clone() {}

    public function current(?string $kind = null): array
    {
        if (!$this->db->pdo()->inTransaction()) throw new \LogicException('Clearing intent requires the owner transaction.');
        $row = (new PaymentWorkflowRepository($this->db))->find($this->supplier, $this->workflowId);
        if ($row['status'] !== 'pending' || (int) $row['row_version'] !== $this->version || $row['journal_entry_id'] !== null
            || ($kind !== null && $row['source_kind'] !== $kind)) PaymentException::reject('workflow_version_conflict');
        $plan = $this->planner->current($this->supplier, $row['request']);
        if (!hash_equals($row['preview_hash'], $plan['preview_hash'])) PaymentException::reject('preview_stale');
        if ($plan['snapshot']['native'] === null) PaymentException::reject('source_owned_elsewhere');
        if ($row['source_kind'] === 'cash' && $row['cash_document_id'] !== null && isset($row['request']['source']['draft'])) {
            $q = $this->db->pdo()->prepare('SELECT * FROM cash_documents WHERE supplier_id=? AND id=? FOR UPDATE');
            $q->execute([$this->supplier, $row['cash_document_id']]);
            $cash = $q->fetch(PDO::FETCH_ASSOC);
            if (!$cash || $cash['journal_entry_id'] !== null || $cash['status'] !== 'draft' || $cash['doc_number'] !== null) PaymentException::reject('source_changed');
            foreach ($plan['snapshot']['source']['native_draft'] as $field => $value) {
                if ($field === 'vat_lines') continue;
                if (!array_key_exists($field, $cash) || (string) $cash[$field] !== (string) $value) PaymentException::reject('source_changed', ['field' => $field]);
            }
        }
        return ['workflow' => $row, 'snapshot' => $plan['snapshot']];
    }

    public function bindCash(int $id): self
    {
        $proof = $this->current('cash');
        if ($proof['workflow']['cash_document_id'] !== null) PaymentException::reject('workflow_version_conflict');
        (new PaymentWorkflowRepository($this->db))->bindCash($this->supplier, $this->workflowId, $this->version, $id);
        return self::fromPending($this->db, $this->planner, $this->supplier, $this->workflowId, $this->version + 1);
    }

    public function assertConnection(Connection $db): void
    {
        if ($db->pdo() !== $this->db->pdo()) throw new \LogicException('Clearing intent belongs to another transaction connection.');
    }

    public function journal(): array
    {
        $proof = $this->current();
        $row = $proof['workflow'];
        $native = $proof['snapshot']['native'];
        $id = $row['source_kind'] === 'bank' ? $row['bank_transaction_id'] : $row['cash_document_id'];
        if ($id === null) PaymentException::reject('workflow_native_missing');
        $native['header'] += ['source_id' => (int) $id, 'posted_at' => gmdate('Y-m-d H:i:s'), 'posted_by' => (int) $row['created_by']];
        return $native;
    }

    public function bindJournal(int $entry): void
    {
        $repository = new PaymentWorkflowRepository($this->db);
        $row = $repository->find($this->supplier, $this->workflowId);
        if ($row['status'] !== 'pending' || (int) $row['row_version'] !== $this->version || $row['journal_entry_id'] !== null) PaymentException::reject('workflow_version_conflict');
        $actual = (new \MyInvoice\Service\Property\Payments\PaymentJournalProof($this->db))->current($this->supplier, $entry);
        $expected = $row['plan']['native'];
        $sourceId = $row['source_kind'] === 'bank' ? $row['bank_transaction_id'] : $row['cash_document_id'];
        if ($actual['entry']['source_type'] !== $row['source_kind'] || $actual['entry']['source_id'] !== (int) $sourceId
            || $actual['entry']['entry_date'] !== $expected['header']['entry_date'] || $actual['entry']['reversed_by'] !== null
            || PropertyInput::hash(\MyInvoice\Service\Property\Payments\PaymentJournalProof::canonicalLines($actual['lines']))
                !== PropertyInput::hash(\MyInvoice\Service\Property\Payments\PaymentJournalProof::canonicalLines($expected['lines']))) PaymentException::reject('posted_ledger_mismatch');
        $repository->bindJournal($this->supplier, $this->workflowId, $this->version, $entry);
    }
}
