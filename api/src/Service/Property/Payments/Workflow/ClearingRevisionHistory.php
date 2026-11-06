<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Payments\Workflow;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Property\Payments\{PaymentException, PaymentJournalProof};
use MyInvoice\Service\Property\PropertyInput;
use PDO;

final class ClearingRevisionHistory
{
    public function __construct(private readonly Connection $db) {}

    public function current(int $supplier, array $source, array $receipt): array
    {
        if (!$this->db->pdo()->inTransaction()) throw new \LogicException('Source revisions require current owner locks.');
        $cells = $receipt['clearing_cells'] ?? [['key' => 'original', 'amount_cents' => $receipt['amount_cents'], 'line' => $receipt['clearing_line']]];
        $q = $this->db->pdo()->prepare("SELECT * FROM unit_payment_source_revisions WHERE supplier_id=? AND source_id=? AND status='posted' ORDER BY revision_no,id FOR UPDATE");
        $q->execute([$supplier, $source['id']]); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        $previous = null; $number = 0; $date = $receipt['date'];
        foreach ($rows as $row) {
            $request = json_decode($row['request_json'], true, flags: JSON_THROW_ON_ERROR);
            $snapshot = json_decode($row['snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
            if (!hash_equals($row['request_hash'], self::requestHash($request, $row['idempotency_key'], (int) $row['created_by']))
                || !hash_equals($row['snapshot_hash'], PropertyInput::hash($snapshot))
                || (int) $row['revision_no'] !== ++$number || ($row['previous_revision_id'] === null ? null : (int) $row['previous_revision_id']) !== $previous
                || ($snapshot['source_id'] ?? null) !== (int) $source['id'] || ($snapshot['source_receipt_hash'] ?? null) !== PropertyInput::hash($receipt)
                || ($request['expected_revision_id'] ?? null) !== $previous || $request['expected_source_version'] !== (int) $row['expected_source_version']
                || $request['accounting_date'] !== $row['accounting_date'] || $row['accounting_date'] < $date
                || \MyInvoice\Service\Property\PropertyInstant::sql($request['effective_at']) !== $row['effective_at']) self::corrupt();
            $pinnedUsed = []; $seen = [];
            foreach ($snapshot['pinned_operations'] as $pinned) {
                if (isset($seen[$pinned['id']])) self::corrupt(); $seen[$pinned['id']] = true;
                $q = $this->db->pdo()->prepare('SELECT snapshot_json,snapshot_hash,source_id,kind,amount_cents FROM unit_payment_operations WHERE supplier_id=? AND id=? FOR UPDATE');
                $q->execute([$supplier, $pinned['id']]); $operation = $q->fetch(PDO::FETCH_ASSOC);
                if (!$operation || (int) $operation['source_id'] !== (int) $source['id'] || $operation['snapshot_hash'] !== $pinned['snapshot_hash']
                    || !in_array($operation['kind'], ['allocation', 'refund'], true)) self::corrupt();
                $operationSnapshot = json_decode($operation['snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
                if (!hash_equals($operation['snapshot_hash'], PropertyInput::hash($operationSnapshot))
                    || PropertyInput::hash($operationSnapshot['source']['receipt']) !== PropertyInput::hash($receipt)) self::corrupt();
                $selection = $operationSnapshot['clearing_selection'] ?? [['key' => 'original', 'amount_cents' => $operation['amount_cents']]];
                foreach (ClearingCells::consume($cells, $pinnedUsed, $operation['amount_cents'], $selection) as $part) $pinnedUsed[$part['key']] = bcadd($pinnedUsed[$part['key']] ?? '0', $part['amount_cents'], 0);
            }
            if (PropertyInput::hash($pinnedUsed) !== PropertyInput::hash($snapshot['cell_consumed'])) self::corrupt();
            $plan = ClearingRevisionCells::plan($cells, $snapshot['cell_consumed'], $request['moves']);
            if (PropertyInput::hash($plan) !== PropertyInput::hash($snapshot['plan'])) self::corrupt();
            $ledger = (new PaymentJournalProof($this->db))->current($supplier, (int) $row['journal_entry_id']);
            if ($ledger['entry']['source_type'] !== 'unit_payment_revision' || $ledger['entry']['source_id'] !== (int) $row['id']
                || $ledger['entry']['entry_date'] !== $row['accounting_date'] || $ledger['entry']['reversed_by'] !== null
                || $ledger['entry']['period_id'] !== $snapshot['journal']['header']['period_id'] || $request['source_id'] !== (int) $source['id']
                || PropertyInput::hash(PaymentJournalProof::canonicalLines($ledger['lines'])) !== PropertyInput::hash(PaymentJournalProof::canonicalLines($plan['lines']))) self::corrupt();
            $cells = array_column($plan['cells'], null, 'key');
            foreach ($plan['moves'] as $index => $move) $cells[$move['to']['key']]['line'] = $ledger['lines'][2 * $index + 1];
            $cells = array_values($cells); $previous = (int) $row['id']; $date = $row['accounting_date'];
        }
        $q = $this->db->pdo()->prepare('SELECT revision_id,row_version FROM unit_payment_source_heads WHERE supplier_id=? AND source_id=? FOR UPDATE');
        $q->execute([$supplier, $source['id']]); $head = $q->fetch(PDO::FETCH_ASSOC);
        if (($head ? (int) $head['revision_id'] : null) !== $previous || ($head ? (int) $head['row_version'] : 0) !== $number) self::corrupt();
        return ['clearing_cells' => $cells, 'revision_id' => $previous, 'revision_number' => $number, 'revision_date' => $date];
    }

    public static function requestHash(array $request, string $token, int $actor): string
    {
        return PropertyInput::hash(['request' => $request, 'idempotency_key' => $token, 'actor' => $actor]);
    }

    public static function corrupt(): never { PaymentException::reject('source_revision_corrupt'); }
}
