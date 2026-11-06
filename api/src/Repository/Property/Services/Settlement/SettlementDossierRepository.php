<?php

declare(strict_types=1);
namespace MyInvoice\Repository\Property\Services\Settlement;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Property\Payments\PaymentLocks;
use MyInvoice\Service\Property\{PropertyInput,PropertyException};
use MyInvoice\Service\Property\Services\Settlement\{SettlementDossier,SettlementEvidence,SettlementSql};
use MyInvoice\Repository\Property\PropertyReadRepository;

final class SettlementDossierRepository
{
    use SettlementSql;
    public const TABLES = ['property_service_settlement_dossier_events'];
    public function __construct(private readonly Connection $db, private readonly SettlementDocumentRepository $documents) {}

    public function read(int $supplier, int $document): array
    {
        return $this->transaction($supplier, fn () => $this->readLocked($supplier, $document));
    }
    public function readLocked(int $supplier, int $document): array
    {
        if (!$this->db->pdo()->inTransaction()) throw new \LogicException('Dossier read requires a stable transaction.');
        $stored = $this->document($supplier, $document);
        $facts = $stored['document'];
        $dossier = $facts['external_dossier'] ?? null;
        $revision = 0; $previous = null; $hash = $stored['document_hash']; $events = []; $versions = [];
        foreach ($dossier['deliveries'] ?? [] as $delivery) $versions[SettlementDossier::deliveryKey($delivery)] = null;
        foreach ($this->rows('SELECT * FROM property_service_settlement_dossier_events WHERE supplier_id=? AND document_id=? ORDER BY revision_no FOR UPDATE', [$supplier,$document]) as $row) {
            $envelope = self::decode($row['request_json']); $snapshot = self::decode($row['snapshot_json']);
            $event = $this->normalizeEvent($envelope['event'], $facts, $dossier);
            $expected = ['document_id' => $document,'document_hash' => $stored['document_hash'],'revision_no' => $revision + 1,'previous_id' => $previous,'previous_hash' => $hash,'request_hash' => $row['request_hash'],'event' => $event];
            if ($row['request_hash'] !== PropertyInput::hash($envelope) || $row['snapshot_hash'] !== PropertyInput::hash($snapshot) || PropertyInput::hash($expected) !== $row['snapshot_hash'] || (int)$row['revision_no'] !== $revision + 1 || ($row['previous_id'] === null ? null : (int)$row['previous_id']) !== $previous
                || $envelope['document_id'] !== $document || $envelope['document_hash'] !== $stored['document_hash'] || $envelope['expected_revision'] !== $revision || $envelope['actor'] !== (int)$row['created_by'] || $envelope['token'] !== $row['idempotency_key']) SettlementEvidence::reject('dossier_history_corrupt');
            $this->transition($dossier, $versions, $event, (int)$row['id']);
            $revision++; $previous = (int)$row['id']; $hash = $row['snapshot_hash'];
            $events[] = ['id' => $previous,'revision_no' => $revision,'event' => $event,'reason' => $envelope['reason'],'created_by' => (int)$row['created_by'],'recorded_at' => $row['recorded_at']];
        }
        $historyParties = SettlementDossier::historyPartyIds($facts['external_dossier'] ?? null, array_column($events, 'event'));
        $parties = (new PropertyReadRepository($this->db))->parties($supplier, $historyParties === [] ? $facts['recipient_ids'] : $historyParties, true);
        $parties = array_map(fn ($party) => ['id' => $party['id'],'name' => $party['company_name']], $parties);
        return ['document_id' => $document,'document_hash' => $stored['document_hash'],'revision_no' => $revision,'latest_event_id' => $previous,'latest_hash' => $hash,'dossier' => $dossier,'delivery_versions' => $versions,'parties' => $parties,'events' => $events,'scope' => 'external_evidence_only','financial_history_changed' => false];
    }
    public function append(int $supplier, int $document, array $event, int $expected, string $reason, string $token, int $actor): array
    {
        PropertyInput::id($supplier, 'supplier_id'); PropertyInput::id($actor, 'actor'); PropertyInput::code($token, 'idempotency_key');
        $reason = PropertyInput::text($reason, 'reason', 4000); if ($expected < 0) PropertyInput::invalid('expected_revision');
        return $this->transaction($supplier, function () use ($supplier,$document,$event,$expected,$reason,$token,$actor): array {
            $stored = $this->document($supplier, $document); $current = $this->readLocked($supplier, $document);
            $event = $this->normalizeEvent($event, $stored['document'], $current['dossier']);
            $envelope = ['document_id' => $document,'document_hash' => $stored['document_hash'],'expected_revision' => $expected,'event' => $event,'reason' => $reason,'token' => $token,'actor' => $actor];
            $requestHash = PropertyInput::hash($envelope);
            $old = $this->one('SELECT id,revision_no,request_hash,snapshot_hash FROM property_service_settlement_dossier_events WHERE supplier_id=? AND idempotency_key=? FOR UPDATE', [$supplier,$token]);
            if ($old) {
                if ($old['request_hash'] !== $requestHash) SettlementEvidence::reject('dossier_idempotency_conflict');
                return ['id' => (int)$old['id'],'revision_no' => (int)$old['revision_no'],'snapshot_hash' => $old['snapshot_hash'],'financial_history_changed' => false];
            }
            if ($expected !== $current['revision_no']) SettlementEvidence::reject('dossier_revision_conflict');
            $dossier = $current['dossier']; $versions = $current['delivery_versions'];
            $this->transition($dossier, $versions, $event, -1);
            $parties = SettlementDossier::partyIds($dossier);
            if (count((new PropertyReadRepository($this->db))->parties($supplier, $parties, true)) !== count($parties)) PropertyInput::invalid('dossier_parties');
            $snapshot = ['document_id' => $document,'document_hash' => $stored['document_hash'],'revision_no' => $expected + 1,'previous_id' => $current['latest_event_id'],'previous_hash' => $current['latest_hash'],'request_hash' => $requestHash,'event' => $event];
            $snapshotHash = PropertyInput::hash($snapshot);
            $this->execute('INSERT INTO property_service_settlement_dossier_events (supplier_id,document_id,revision_no,previous_id,request_json,request_hash,snapshot_json,snapshot_hash,idempotency_key,created_by,recorded_at) VALUES (?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))', [$supplier,$document,$expected + 1,$current['latest_event_id'],self::json($envelope),$requestHash,self::json($snapshot),$snapshotHash,$token,$actor]);
            $id = (int)$this->db->pdo()->lastInsertId();
            $this->readLocked($supplier, $document);
            return ['id' => $id,'revision_no' => $expected + 1,'snapshot_hash' => $snapshotHash,'financial_history_changed' => false];
        });
    }
    private function document(int $supplier, int $id): array
    {
        PropertyInput::id($id, 'document_id');
        if (!$this->one('SELECT id FROM property_service_settlements WHERE supplier_id=? AND id=? FOR UPDATE', [$supplier,$id])) throw new PropertyException('property_not_found', 'Vyúčtování nebylo nalezeno.', 404);
        return $this->documents->find($supplier, $id, true);
    }
    private function normalizeEvent(array $event, array $facts, ?array $dossier): array
    {
        $event = SettlementEvidence::detached($event);
        if (($event['type'] ?? null) === 'acceptance') {
            SettlementEvidence::fields($event, ['type','dossier']); if (!is_array($event['dossier'])) PropertyInput::invalid('dossier');
            $event['dossier'] = SettlementDossier::normalize($event['dossier'], $facts['period_from'], $facts['period_to']);
            if (SettlementDossier::recipientIds($event['dossier']) !== $facts['recipient_ids']) SettlementEvidence::reject('dossier_recipient_scope');
        } elseif (($event['type'] ?? null) === 'delivery') {
            SettlementEvidence::fields($event, ['type','delivery','supersedes_event_id']); if ($dossier === null) SettlementEvidence::reject('dossier_acceptance_required');
            if (!is_array($event['delivery'])) PropertyInput::invalid('delivery');
            $event['delivery'] = SettlementDossier::delivery($event['delivery'], $dossier['segments']);
            if ($event['supersedes_event_id'] !== null) PropertyInput::id($event['supersedes_event_id'], 'supersedes_event_id');
        } elseif (($event['type'] ?? null) === 'legal_metadata') {
            SettlementEvidence::fields($event, ['type','person_change']); if ($dossier === null) SettlementEvidence::reject('dossier_acceptance_required');
            if (!is_array($event['person_change'])) PropertyInput::invalid('person_change'); $event['person_change'] = SettlementDossier::personChange($event['person_change']);
        } else PropertyInput::invalid('event.type');
        return $event;
    }
    private function transition(?array &$dossier, array &$versions, array $event, int $id): void
    {
        if ($event['type'] === 'acceptance') {
            if ($dossier !== null) SettlementEvidence::reject('dossier_already_accepted');
            $dossier = $event['dossier']; foreach ($dossier['deliveries'] as $delivery) $versions[SettlementDossier::deliveryKey($delivery)] = $id;
        } elseif ($event['type'] === 'delivery') {
            $key = SettlementDossier::deliveryKey($event['delivery']);
            if (!array_key_exists($key, $versions) || $versions[$key] !== $event['supersedes_event_id']) SettlementEvidence::reject('dossier_receipt_conflict');
            foreach ($dossier['deliveries'] as &$delivery) if (SettlementDossier::deliveryKey($delivery) === $key) { $delivery = $event['delivery']; break; } unset($delivery);
            $versions[$key] = $id;
        } else $dossier['person_changes'][] = $event['person_change'];
    }
    private function transaction(int $supplier, callable $work): mixed
    {
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplier,$work) { (new PaymentLocks($this->db))->acquire($supplier); return $work(); });
    }
}
