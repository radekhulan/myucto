<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property;

use MyInvoice\Infrastructure\Database\Connection;

final class PropertyHistoryUseGuard
{
    public function __construct(private readonly Connection $db) {}

    public function supplierHasHistory(int $supplierId, bool $currentRead = false): bool
    {
        foreach (['property_objects', 'property_module_profiles'] as $table) if ($this->exists($table, 'supplier_id = ?', [$supplierId], $currentRead)) return true;
        return false;
    }

    public function clientHasHistory(int $supplierId, int $clientId, bool $currentRead = false): bool
    {
        foreach (['property_revision_members', 'property_revision_roles', 'unit_charge_parties', 'unit_credit_parties', 'property_claim_party_members'] as $table) if ($this->exists($table, 'supplier_id = ? AND client_id = ?', [$supplierId, $clientId], $currentRead)) return true;
        if ($this->exists('property_funding_operations', "supplier_id = ? AND JSON_VALUE(request_json, '$.recipient_id') = ?", [$supplierId, $clientId], $currentRead)) return true;
        if ($this->exists('property_debt_certificates', "supplier_id = ? AND JSON_VALUE(request_json, '$.requester_id') = ?", [$supplierId, $clientId], $currentRead)) return true;
        if ($this->exists('unit_charge_notices', "supplier_id = ? AND EXISTS (
            SELECT 1 FROM JSON_TABLE(notice_json, '$.deliveries[*]' COLUMNS (recipient_id BIGINT PATH '$.recipient_id')) AS delivery
            WHERE delivery.recipient_id = ?)", [$supplierId, $clientId], $currentRead)) return true;
        if ($this->exists('unit_charge_evidence_events', "supplier_id = ? AND kind = 'reminder'
            AND JSON_VALUE(request_json, '$.facts.recipient_id') = ?", [$supplierId, $clientId], $currentRead)) return true;
        return (new \MyInvoice\Service\Property\Services\Settlement\SettlementDossierReferences($this->db))->clientHasHistory($supplierId, $clientId, $currentRead);
    }

    private function exists(string $table, string $where, array $parameters, bool $currentRead): bool
    {
        if ($currentRead && !$this->db->pdo()->inTransaction()) throw new \LogicException('Current property dependency checks require period-first owner locks.');
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM ' . $table . ' WHERE ' . $where . ' LIMIT 1' . ($currentRead ? ' FOR UPDATE' : ''));
        $stmt->execute($parameters);
        return $stmt->fetchColumn() !== false;
    }
}
