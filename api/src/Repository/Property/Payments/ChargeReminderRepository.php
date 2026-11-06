<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Property\Payments;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\PropertyInstant;
use MyInvoice\Service\Property\Payments\PaymentException;
use PDO;

/**
 * Doručená upomenutí předpisů zaznamenaná jako důkazní události předpisu.
 * Čte se jedním dotazem pro všechny předpisy platby a ověřuje se otisk každé události
 * stejně jako při čtení historie předpisu.
 */
final class ChargeReminderRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @param list<int> $chargeIds
     * @return array<int,list<array{event_id:int,recipient_id:int,represented_ids:list<int>,delivered_at:string}>>
     */
    public function forCharges(int $supplier, array $chargeIds): array
    {
        if (!$this->db->pdo()->inTransaction()) throw new \LogicException('Reminder evidence is read under the payment owner transaction.');
        $chargeIds = array_values(array_unique($chargeIds));
        $out = [];
        foreach (array_chunk($chargeIds, 500) as $chunk) {
            foreach ($chunk as $id) PropertyInput::id($id, 'charge_id');
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->db->pdo()->prepare(
                "SELECT id, charge_id, kind, effective_at, request_json, request_hash, idempotency_key, created_by
                   FROM unit_charge_evidence_events
                  WHERE supplier_id = ? AND charge_id IN ({$marks}) AND kind = 'reminder'
                  ORDER BY charge_id, id FOR UPDATE"
            );
            $stmt->execute([$supplier, ...$chunk]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $chargeId = (int) $row['charge_id'];
                $request = json_decode($row['request_json'], true, flags: JSON_THROW_ON_ERROR);
                $expected = PropertyInput::hash(['charge_id' => $chargeId, 'request' => $request,
                    'idempotency_key' => $row['idempotency_key'], 'actor' => (int) $row['created_by']]);
                if (!hash_equals($row['request_hash'], $expected) || ($request['kind'] ?? null) !== 'reminder'
                    || $row['effective_at'] !== PropertyInstant::sql($request['effective_at'])) {
                    PaymentException::reject('history_corrupt', ['charge_id' => $chargeId, 'evidence_event_id' => (int) $row['id']]);
                }
                $facts = $request['facts'];
                $out[$chargeId][] = [
                    'event_id' => (int) $row['id'],
                    'recipient_id' => (int) $facts['recipient_id'],
                    'represented_ids' => array_map('intval', $facts['represented_ids']),
                    'delivered_at' => PropertyInstant::normalize($facts['delivered_at']),
                ];
            }
        }
        return $out;
    }
}
