<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Payments\Workflow;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceReadRepository;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceJournalProof;
use MyInvoice\Service\Property\Payments\PaymentException;
use MyInvoice\Service\Property\PropertyInput;
use PDO;

final class PaymentReliefReversalProof
{
    public static function assertPending(Connection $db, int $supplier, array $pending, array $original, array $request): array
    {
        $q = $db->pdo()->prepare("SELECT * FROM unit_payment_audit WHERE supplier_id=? AND id=? AND object_kind='correction' AND action='reversal_requested' FOR UPDATE");
        $q->execute([$supplier, $request['correction_audit_id'] ?? 0]);
        $audit = $q->fetch(PDO::FETCH_ASSOC);
        if (!$audit) PaymentException::reject('reversal_intent');
        $payload = json_decode($audit['payload_json'], true, flags: JSON_THROW_ON_ERROR);
        if (!hash_equals($audit['payload_hash'], PropertyInput::hash($payload))
            || (int) $audit['object_id'] !== ($request['correction_id'] ?? null)
            || (int) $audit['created_by'] !== (int) $pending['created_by'] || $payload['actor'] !== (int) $pending['created_by']
            || $payload['request']['accounting_date'] !== $request['accounting_date'] || $payload['request']['reason'] !== $request['reason']) PaymentException::reject('reversal_intent');
        $q = $db->pdo()->prepare('SELECT * FROM unit_payment_corrections WHERE supplier_id=? AND id=? FOR UPDATE');
        $q->execute([$supplier, $audit['object_id']]); $correction = $q->fetch(PDO::FETCH_ASSOC);
        if (!$correction || $correction['status'] !== 'posted' || (int) $correction['operation_id'] !== (int) $original['id']
            || (int) $correction['row_version'] !== $payload['request']['expected_version'] || $correction['snapshot_hash'] !== $payload['snapshot_hash']) PaymentException::reject('reversal_intent');
        $snapshot = json_decode($correction['snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        $originalRequest = json_decode($correction['request_json'], true, flags: JSON_THROW_ON_ERROR);
        if (!hash_equals($correction['snapshot_hash'], PropertyInput::hash($snapshot))
            || !hash_equals($correction['request_hash'], PropertyInput::hash(['request' => $originalRequest, 'token' => $correction['idempotency_key'], 'actor' => (int) $correction['created_by']]))
            || PropertyInput::hash($originalRequest) !== PropertyInput::hash($snapshot['request'])) PaymentException::reject('reversal_intent');
        $charge = (new IssuanceReadRepository($db))->find($supplier, (int) $correction['adjustment_charge_id'], true);
        if ($charge['id'] !== $payload['charge']['id'] || $charge['status'] !== 'reversed'
            || $charge['row_version'] !== $payload['charge']['row_version'] + 1 || $charge['snapshot_hash'] !== $payload['charge']['snapshot_hash']
            || $charge['snapshot_hash'] !== $snapshot['adjustment']['snapshot_hash']) PaymentException::reject('reversal_intent');
        $proof = new IssuanceJournalProof($db);
        $proof->assertEntry($supplier, $charge['journal_entry_id'], $charge);
        $proof->assertEntry($supplier, $charge['reversal_entry_id'], $charge, true);
        $q = $db->pdo()->prepare('SELECT entry_date FROM journal_entries WHERE supplier_id=? AND id=? FOR UPDATE');
        $q->execute([$supplier, $charge['reversal_entry_id']]);
        if ($q->fetchColumn() !== $request['accounting_date']) PaymentException::reject('reversal_intent');
        return ['correction' => $correction, 'audit' => $payload];
    }
}
