<?php

declare(strict_types=1);
namespace MyInvoice\Service\Property\Payments\Workflow;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Payments\PaymentWorkflowRepository;
use MyInvoice\Service\Property\Payments\PaymentException;
use MyInvoice\Service\Property\Payments\PaymentJournalProof;
use MyInvoice\Service\Property\PropertyInput;
use PDO;

final class PaymentWorkflowSourceProof
{
    public function __construct(private readonly Connection $db) {}

    public function current(int $supplier, string $kind, int $id, array $row, array $ledger, string $date, string $direction, string $cents): ?array
    {
        $column = $kind === 'bank' ? 'bank_transaction_id' : 'cash_document_id';
        $q = $this->db->pdo()->prepare('SELECT id FROM unit_payment_workflows WHERE supplier_id=? AND ' . $column . '=? AND journal_entry_id=? ORDER BY id FOR UPDATE');
        $q->execute([$supplier, $id, $ledger['entry']['id']]);
        $ids = $q->fetchAll(PDO::FETCH_COLUMN);
        $owners = [];
        foreach ($ids as $workflowId) {
            $candidate = (new PaymentWorkflowRepository($this->db))->find($supplier, (int) $workflowId);
            if ($candidate['plan']['native'] !== null) $owners[] = $candidate;
        }
        if ($owners === []) return null;
        if (count($owners) !== 1) PaymentException::reject('workflow_source_ambiguous');
        $workflow = $owners[0];
        $plan = $workflow['plan'];
        $facts = $plan['source'];
        if ($workflow['source_kind'] !== $kind || $facts['amount_cents'] !== $cents || $facts['direction'] !== $direction || $facts['date'] !== $date
            || $ledger['entry']['entry_date'] !== $date || $ledger['entry']['source_type'] !== $kind || $ledger['entry']['source_id'] !== $id || $ledger['entry']['reversed_by'] !== null
            || PropertyInput::hash(PaymentJournalProof::canonicalLines($ledger['lines'])) !== PropertyInput::hash(PaymentJournalProof::canonicalLines($plan['native']['lines']))) PaymentException::reject('source_clearing_mismatch');
        $cells = [];
        foreach ($plan['cells'] as $index => $cell) $cells[] = ['key' => $cell['key'], 'amount_cents' => $cell['amount_cents'], 'line' => $ledger['lines'][$index + 1]];
        $receipt = ['version' => 2, 'workflow_id' => (int) $workflow['id'], 'kind' => $kind, 'id' => $id, 'direction' => $direction,
            'amount_cents' => $cents, 'date' => $date, 'currency' => 'CZK', 'ledger' => $ledger, 'clearing_cells' => $cells,
            'counterparty_account' => $kind === 'bank' ? ($row['counterparty_account'] ?? null) : null,
            'counterparty_bank' => $kind === 'bank' ? ($row['counterparty_bank'] ?? null) : null, 'partner_name' => $kind === 'cash' ? $row['partner_name'] : null];
        $q = $this->db->pdo()->prepare('SELECT * FROM unit_payment_sources WHERE supplier_id=? AND ' . $column . '=? FOR UPDATE');
        $q->execute([$supplier, $id]); $stored = $q->fetch(PDO::FETCH_ASSOC);
        $used = '0'; $cellUsed = []; $revision = [];
        if ($stored) {
            $raw = json_decode($stored['receipt_json'], true, flags: JSON_THROW_ON_ERROR);
            if (!hash_equals($stored['receipt_hash'], PropertyInput::hash($raw)) || !hash_equals($stored['receipt_hash'], PropertyInput::hash($receipt))) PaymentException::reject('source_changed');
            $revision = (new ClearingRevisionHistory($this->db))->current($supplier, $stored, $receipt);
            $cells = $revision['clearing_cells'];
            if ($revision['revision_id'] === null) $revision = [];
            $q = $this->db->pdo()->prepare("SELECT source_amount_cents,source_native_minor,snapshot_json,snapshot_hash FROM unit_payment_operations WHERE supplier_id=? AND source_id=? AND status='posted' AND kind IN ('allocation','refund') ORDER BY id FOR UPDATE");
            $q->execute([$supplier, $stored['id']]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $operation) {
                $snapshot = json_decode($operation['snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
                if (!hash_equals($operation['snapshot_hash'], PropertyInput::hash($snapshot)) || !isset($snapshot['clearing_selection'])
                    || $operation['source_amount_cents'] === null || $operation['source_native_minor'] !== null) PaymentException::reject('source_capacity_corrupt');
                foreach (ClearingCells::consume($cells, $cellUsed, $operation['source_amount_cents'], $snapshot['clearing_selection']) as $part) $cellUsed[$part['key']] = bcadd($cellUsed[$part['key']] ?? '0', $part['amount_cents'], 0);
                $used = bcadd($used, $operation['source_amount_cents'], 0);
            }
            if ($used !== $stored['consumed_cents'] || $stored['amount_cents'] !== $cents || $stored['direction'] !== $direction || (int) $stored['journal_entry_id'] !== $ledger['entry']['id']
                || $stored['currency_code'] !== 'CZK' || $stored['native_amount_minor'] !== null || $stored['consumed_native_minor'] !== null || $stored['fx_rate'] !== null) PaymentException::reject('source_capacity_corrupt');
        }
        return ['receipt' => $receipt, 'id' => $stored ? (int) $stored['id'] : null, 'version' => $stored ? (int) $stored['row_version'] : 0,
            'consumed_cents' => $used, 'available_cents' => bcsub($cents, $used, 0), 'consumed_native_minor' => null,
            'available_native_minor' => null, 'cell_consumed' => $cellUsed, 'cell_native_consumed' => []] + $revision;
    }
}
