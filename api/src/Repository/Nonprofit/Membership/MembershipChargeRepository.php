<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Nonprofit\Membership;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Nonprofit\Membership\MembershipException;
use MyInvoice\Service\Nonprofit\Membership\MembershipRecordedTime;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use PDO;

final class MembershipChargeRepository
{
    public function __construct(private readonly Connection $db) {}

    public function command(int $supplier, string $key, bool $lock = false): ?array
    {
        $this->current($lock);
        $q = $this->db->pdo()->prepare('SELECT * FROM nonprofit_membership_charge_commands WHERE supplier_id=? AND idempotency_key=?' . ($lock ? ' FOR UPDATE' : ''));
        $q->execute([$supplier, $key]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;
        foreach (['id', 'supplier_id', 'created_by'] as $field) $row[$field] = (int) $row[$field];
        $row['request'] = json_decode($row['request_json'], true, flags: JSON_THROW_ON_ERROR);
        unset($row['request_json']);
        return $row;
    }

    public function insertCommand(int $supplier, string $key, array $request, string $hash, int $actor): array
    {
        $q = $this->db->pdo()->prepare('INSERT INTO nonprofit_membership_charge_commands(supplier_id,idempotency_key,request_json,request_hash,created_by,created_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP(6))');
        $q->execute([$supplier, $key, NonprofitTaxRules::canonicalJson($request), $hash, $actor]);
        return $this->command($supplier, $key, true) ?? throw new \LogicException('Inserted membership command disappeared.');
    }

    public function caseById(int $supplier, int $case, bool $lock = false): ?array
    {
        $this->current($lock);
        $q = $this->db->pdo()->prepare('SELECT * FROM nonprofit_membership_charge_cases WHERE supplier_id=? AND id=?' . ($lock ? ' FOR UPDATE' : ''));
        $q->execute([$supplier, $case]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->caseRow($row);
    }

    public function casesForTitle(int $supplier, int $member, string $title, bool $lock = false): array
    {
        $this->current($lock);
        $q = $this->db->pdo()->prepare('SELECT * FROM nonprofit_membership_charge_cases WHERE supplier_id=? AND member_id=? AND title_key=? ORDER BY period_from,period_to,id' . ($lock ? ' FOR UPDATE' : ''));
        $q->execute([$supplier, $member, $title]);
        return array_map($this->caseRow(...), $q->fetchAll(PDO::FETCH_ASSOC));
    }

    public function activeOverlaps(int $supplier, int $member, string $title, string $from, string $to, bool $lock = false): array
    {
        $this->current($lock);
        $q = $this->db->pdo()->prepare("SELECT c.* FROM nonprofit_membership_charge_cases c JOIN nonprofit_membership_charge_operations o ON o.supplier_id=c.supplier_id AND o.case_id=c.id AND o.id=c.head_operation_id WHERE c.supplier_id=? AND c.member_id=? AND c.title_key=? AND c.period_from<=? AND c.period_to>=? AND o.operation='issue' ORDER BY c.period_from,c.period_to,c.id" . ($lock ? ' FOR UPDATE' : ''));
        $q->execute([$supplier, $member, $title, $to, $from]);
        return array_map($this->caseRow(...), $q->fetchAll(PDO::FETCH_ASSOC));
    }

    public function insertCase(int $supplier, int $member, string $title, string $from, string $to, int $actor): array
    {
        $q = $this->db->pdo()->prepare('INSERT INTO nonprofit_membership_charge_cases(supplier_id,member_id,title_key,period_from,period_to,created_by,created_at) VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(6))');
        $q->execute([$supplier, $member, $title, $from, $to, $actor]);
        return $this->caseById($supplier, (int) $this->db->pdo()->lastInsertId(), true) ?? throw new \LogicException('Inserted charge case disappeared.');
    }

    public function operation(int $supplier, int $operation, bool $lock = false): ?array
    {
        $this->current($lock);
        $q = $this->db->pdo()->prepare('SELECT * FROM nonprofit_membership_charge_operations WHERE supplier_id=? AND id=?' . ($lock ? ' FOR UPDATE' : ''));
        $q->execute([$supplier, $operation]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->operationRow($row);
    }

    public function operationsMany(int $supplier, array $operationIds): array
    {
        $operationIds = array_values(array_unique(array_map('intval', $operationIds)));
        if ($supplier <= 0 || $operationIds === [] || count($operationIds) > 500 || min($operationIds) <= 0) throw new \InvalidArgumentException('Membership operation batch must contain one tenant and 1-500 operations.');
        $marks = implode(',', array_fill(0, count($operationIds), '?'));
        $q = $this->db->pdo()->prepare("SELECT * FROM nonprofit_membership_charge_operations FORCE INDEX(uq_nmop_tenant) WHERE supplier_id=? AND id IN ({$marks}) ORDER BY supplier_id,id");
        $q->execute([$supplier, ...$operationIds]);
        $rows = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $rows[(int) $row['id']] = $this->operationRow($row);
        return $rows;
    }

    public function operationForCommand(int $supplier, int $command, bool $lock = false): ?array
    {
        $this->current($lock);
        $q = $this->db->pdo()->prepare('SELECT id FROM nonprofit_membership_charge_operations WHERE supplier_id=? AND command_id=?' . ($lock ? ' FOR UPDATE' : ''));
        $q->execute([$supplier, $command]);
        $id = $q->fetchColumn();
        return $id === false ? null : $this->operation($supplier, (int) $id, $lock);
    }

    public function head(int $supplier, array $case, bool $lock = false): ?array
    {
        return $case['head_operation_id'] === null ? null : $this->operation($supplier, $case['head_operation_id'], $lock);
    }

    public function insertOperation(array $row): array
    {
        $record = $this->operationRecord($row);
        $row['record_hash'] = NonprofitTaxRules::hash($record);
        $q = $this->db->pdo()->prepare('INSERT INTO nonprofit_membership_charge_operations(supplier_id,case_id,command_id,operation,previous_operation_id,original_operation_id,member_revision_id,legal_profile_id,accounting_period_id,accounting_date,due_date,amount_cents,receivable_account_id,revenue_account_id,journal_entry_id,receivable_line_id,revenue_line_id,preview_hash,proof_json,receipt_json,parent_hash,record_hash,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))');
        $q->execute([$row['supplier_id'], $row['case_id'], $row['command_id'], $row['operation'], $row['previous_operation_id'], $row['original_operation_id'],
            $row['member_revision_id'], $row['legal_profile_id'], $row['accounting_period_id'], $row['accounting_date'], $row['due_date'], $row['amount_cents'],
            $row['receivable_account_id'], $row['revenue_account_id'], $row['journal_entry_id'], $row['receivable_line_id'], $row['revenue_line_id'],
            $row['preview_hash'], NonprofitTaxRules::canonicalJson($row['proof']), NonprofitTaxRules::canonicalJson($row['receipt']), $row['parent_hash'], $row['record_hash'], $row['created_by']]);
        return $this->operation((int) $row['supplier_id'], (int) $this->db->pdo()->lastInsertId(), true) ?? throw new \LogicException('Inserted membership operation disappeared.');
    }

    public function advanceHead(int $supplier, int $case, ?int $expectedHead, int $expectedVersion, int $operation): array
    {
        $q = $this->db->pdo()->prepare('UPDATE nonprofit_membership_charge_cases SET head_operation_id=?,row_version=row_version+1 WHERE supplier_id=? AND id=? AND row_version=? AND head_operation_id<=>?');
        $q->execute([$operation, $supplier, $case, $expectedVersion, $expectedHead]);
        if ($q->rowCount() !== 1) throw new MembershipException('nonprofit_membership_case_changed', 'Účetní případ se mezitím změnil.', 409);
        return $this->caseById($supplier, $case, true) ?? throw new \LogicException('Updated charge case disappeared.');
    }

    public function claimForCase(int $supplier, int $case, bool $lock = false): ?array
    {
        $this->current($lock);
        $q = $this->db->pdo()->prepare("SELECT c.* FROM nonprofit_membership_charge_claims c WHERE c.supplier_id=? AND c.case_id=? AND c.kind='issue' ORDER BY c.id" . ($lock ? ' FOR UPDATE' : ''));
        $q->execute([$supplier, $case]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) return null;
        $claim = $this->claimRow($rows[array_key_last($rows)]);
        return $claim;
    }

    public function claimForOperation(int $supplier, int $operation, bool $lock = false): ?array
    {
        $this->current($lock);
        $q = $this->db->pdo()->prepare('SELECT * FROM nonprofit_membership_charge_claims WHERE supplier_id=? AND operation_id=?' . ($lock ? ' FOR UPDATE' : ''));
        $q->execute([$supplier, $operation]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->claimRow($row);
    }

    public function claimsMany(int $supplier, array $claimIds): array
    {
        $claimIds = array_values(array_unique(array_map('intval', $claimIds)));
        if ($supplier <= 0 || $claimIds === [] || count($claimIds) > 500 || min($claimIds) <= 0) throw new \InvalidArgumentException('Membership claim batch must contain one tenant and 1-500 claims.');
        $marks = implode(',', array_fill(0, count($claimIds), '?'));
        $q = $this->db->pdo()->prepare("SELECT * FROM nonprofit_membership_charge_claims FORCE INDEX(uq_nmclaim_tenant) WHERE supplier_id=? AND id IN ({$marks}) ORDER BY supplier_id,id");
        $q->execute([$supplier, ...$claimIds]);
        $rows = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $rows[(int) $row['id']] = $this->claimRow($row);
        return $rows;
    }

    public function appendClaim(array $row): array
    {
        $row['recorded_at_utc'] = MembershipRecordedTime::nowSql();
        $hash = NonprofitTaxRules::hash(self::claimRecord($row));
        $q = $this->db->pdo()->prepare('INSERT INTO nonprofit_membership_charge_claims(supplier_id,case_id,operation_id,original_claim_id,member_id,client_id,kind,amount_cents,record_hash,created_at,recorded_at_utc) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
        $q->execute([$row['supplier_id'], $row['case_id'], $row['operation_id'], $row['original_claim_id'], $row['member_id'], $row['client_id'], $row['kind'], $row['amount_cents'], $hash, $row['recorded_at_utc'], $row['recorded_at_utc']]);
        return $this->claimForOperation((int) $row['supplier_id'], (int) $row['operation_id'], true) ?? throw new \LogicException('Inserted membership claim disappeared.');
    }

    public static function claimRecord(array $row): array
    {
        $record = array_intersect_key($row, array_flip(['supplier_id', 'case_id', 'operation_id', 'original_claim_id', 'member_id', 'client_id', 'kind', 'amount_cents']));
        if (($row['recorded_at_utc'] ?? null) !== null) $record['recorded_at_utc'] = $row['recorded_at_utc'];
        return $record;
    }

    public function operationRecord(array $row): array
    {
        return array_intersect_key($row, array_flip(['supplier_id', 'case_id', 'command_id', 'operation', 'previous_operation_id', 'original_operation_id',
            'member_revision_id', 'legal_profile_id', 'accounting_period_id', 'accounting_date', 'due_date', 'amount_cents', 'receivable_account_id',
            'revenue_account_id', 'journal_entry_id', 'receivable_line_id', 'revenue_line_id', 'preview_hash', 'proof', 'receipt', 'parent_hash', 'created_by']));
    }

    private function operationRow(array $row): array
    {
        foreach (['id', 'supplier_id', 'case_id', 'command_id', 'member_revision_id', 'legal_profile_id', 'accounting_period_id', 'amount_cents',
            'receivable_account_id', 'revenue_account_id', 'journal_entry_id', 'receivable_line_id', 'revenue_line_id', 'created_by'] as $field) $row[$field] = (int) $row[$field];
        foreach (['previous_operation_id', 'original_operation_id'] as $field) $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        foreach (['proof', 'receipt'] as $field) {
            $row[$field] = json_decode($row[$field . '_json'], true, flags: JSON_THROW_ON_ERROR);
            unset($row[$field . '_json']);
        }
        if (!hash_equals($row['record_hash'], NonprofitTaxRules::hash($this->operationRecord($row)))) {
            throw new MembershipException('nonprofit_membership_operation_corrupt', 'Operace členského příspěvku není průkazná.', 409);
        }
        return $row;
    }

    private function caseRow(array $row): array
    {
        foreach (['id', 'supplier_id', 'member_id', 'row_version', 'created_by'] as $field) $row[$field] = (int) $row[$field];
        $row['head_operation_id'] = $row['head_operation_id'] === null ? null : (int) $row['head_operation_id'];
        return $row;
    }

    private function claimRow(array $row): array
    {
        foreach (['id', 'supplier_id', 'case_id', 'operation_id', 'member_id', 'client_id', 'amount_cents'] as $field) $row[$field] = (int) $row[$field];
        $row['original_claim_id'] = $row['original_claim_id'] === null ? null : (int) $row['original_claim_id'];
        if (!hash_equals($row['record_hash'], NonprofitTaxRules::hash(self::claimRecord($row)))) throw new MembershipException('nonprofit_membership_claim_corrupt', 'Pohledávka členského příspěvku není průkazná.', 409);
        return $row;
    }

    private function current(bool $lock): void
    {
        if ($lock && !$this->db->pdo()->inTransaction()) throw new \LogicException('Membership charge current reads require a transaction.');
    }
}
