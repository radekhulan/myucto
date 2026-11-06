<?php

declare(strict_types=1);
namespace MyInvoice\Service\Property\Services\Settlement;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Property\PropertyInput;

final class SettlementDossierReferences
{
    use SettlementSql;
    public function __construct(private readonly Connection $db) {}
    public function clientHasHistory(int $supplier, int $client, bool $current): bool
    {
        PropertyInput::id($client, 'client_id');
        return in_array($client, $this->allPartyIds($supplier, $current), true);
    }
    public function allPartyIds(int $supplier, bool $current): array
    {
        PropertyInput::id($supplier, 'supplier_id');
        if ($current && !$this->db->pdo()->inTransaction()) throw new \LogicException('Current dossier references require owner locks.');
        $suffix = $current ? ' FOR UPDATE' : ''; $ids = []; $parents = []; $heads = [];
        foreach ($this->rows("SELECT d.id,d.document_json,d.document_hash FROM property_service_settlements d WHERE d.supplier_id=? AND (JSON_CONTAINS_PATH(d.document_json,'one','$.external_dossier')=1 OR EXISTS(SELECT 1 FROM property_service_settlement_dossier_events e WHERE e.supplier_id=d.supplier_id AND e.document_id=d.id)) ORDER BY d.id".$suffix, [$supplier]) as $row) {
            $facts = self::decode($row['document_json']); if (PropertyInput::hash($facts) !== $row['document_hash']) SettlementEvidence::reject('dossier_history_corrupt');
            if(isset($facts['external_dossier'])){$dossier = SettlementDossier::normalize($facts['external_dossier'], $facts['period_from'], $facts['period_to']);array_push($ids, ...SettlementDossier::partyIds($dossier));}
            $parents[(int)$row['id']] = $row['document_hash'];
        }
        foreach ($this->rows('SELECT * FROM property_service_settlement_dossier_events WHERE supplier_id=? ORDER BY document_id,revision_no'.$suffix, [$supplier]) as $row) {
            $document = (int)$row['document_id'];
            if (!isset($parents[$document])) SettlementEvidence::reject('dossier_history_corrupt');
            $request = self::decode($row['request_json']); $snapshot = self::decode($row['snapshot_json']); $head = $heads[$document] ?? ['id' => null,'revision' => 0,'hash' => $parents[$document]];
            if (PropertyInput::hash($request) !== $row['request_hash'] || PropertyInput::hash($snapshot) !== $row['snapshot_hash'] || $request['actor'] !== (int)$row['created_by'] || $request['token'] !== $row['idempotency_key'] || $request['document_id'] !== $document || $request['document_hash'] !== $parents[$document] || $request['expected_revision'] !== $head['revision']
                || $snapshot['document_id'] !== $document || $snapshot['document_hash'] !== $parents[$document] || $snapshot['request_hash'] !== $row['request_hash'] || $snapshot['revision_no'] !== $head['revision'] + 1 || (int)$row['revision_no'] !== $head['revision'] + 1 || $snapshot['previous_id'] !== $head['id'] || ($row['previous_id'] === null ? null : (int)$row['previous_id']) !== $head['id'] || $snapshot['previous_hash'] !== $head['hash'] || PropertyInput::hash($request['event']) !== PropertyInput::hash($snapshot['event'])) SettlementEvidence::reject('dossier_history_corrupt');
            array_push($ids, ...SettlementDossier::eventPartyIds($snapshot['event']));
            $heads[$document] = ['id' => (int)$row['id'],'revision' => (int)$row['revision_no'],'hash' => $row['snapshot_hash']];
        }
        $ids = array_values(array_unique($ids)); sort($ids, SORT_NUMERIC); return $ids;
    }
}
