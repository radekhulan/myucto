<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/** MZ-14-W01/W02 — immutable legal facts kept outside the calculation aggregate. */
final class PayrollEnforcementFactsRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string,mixed>> */
    public function parties(int $supplierId, int $caseId): array
    {
        $this->caseExists($supplierId, $caseId);
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, party_role, revision_no, effective_from, party_name,
                    party_reference, source_document_id, created_at
               FROM payroll_enforcement_case_parties
              WHERE supplier_id = ? AND case_id = ?
              ORDER BY party_role, effective_from, revision_no',
        );
        $stmt->execute([$supplierId, $caseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function breakdowns(int $supplierId, int $caseId, int $claimId): array
    {
        $this->claim($supplierId, $caseId, $claimId, false);
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, revision_no, principal_minor_units, interest_minor_units,
                    costs_minor_units, maintenance_minor_units, total_minor_units,
                    source_document_id, change_reason, created_at
               FROM payroll_enforcement_claim_breakdowns
              WHERE supplier_id = ? AND case_id = ? AND claim_id = ?
              ORDER BY revision_no',
        );
        $stmt->execute([$supplierId, $caseId, $claimId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function recipientInstructions(int $supplierId, int $caseId): array
    {
        $this->caseExists($supplierId, $caseId);
        $stmt = $this->db->pdo()->prepare(
            'SELECT instruction.id, instruction.revision_no, instruction.effective_from,
                    instruction.recipient_party_id, party.party_role,
                    party.party_name, instruction.payment_account_id,
                    instruction.source_document_id, instruction.change_reason,
                    instruction.created_at
               FROM payroll_enforcement_recipient_instructions instruction
               JOIN payroll_enforcement_case_parties party
                 ON party.supplier_id = instruction.supplier_id
                AND party.id = instruction.recipient_party_id
              WHERE instruction.supplier_id = ? AND instruction.case_id = ?
              ORDER BY instruction.effective_from, instruction.revision_no',
        );
        $stmt->execute([$supplierId, $caseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed> */
    public function appendParty(
        int $supplierId,
        int $caseId,
        string $role,
        string $effectiveFrom,
        string $name,
        ?string $reference,
        int $documentId,
        string $documentSha256,
        int $actorUserId,
    ): array {
        $this->caseExists($supplierId, $caseId, true);
        $existing = $this->findParty(
            $supplierId, $caseId, $role, $effectiveFrom, $name, $reference,
            $documentId, $documentSha256,
        );
        if ($existing !== null) {
            return $existing;
        }
        $revision = $this->nextRevision(
            'payroll_enforcement_case_parties',
            $supplierId,
            $caseId,
            $role,
        );
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_enforcement_case_parties
                (supplier_id, case_id, party_role, revision_no, effective_from,
                 party_name, party_reference, source_document_id,
                 source_document_sha256, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $stmt->execute([
            $supplierId, $caseId, $role, $revision, $effectiveFrom, $name,
            $reference, $documentId, $documentSha256, $actorUserId,
        ]);
        return $this->partyById($supplierId, (int) $this->db->pdo()->lastInsertId());
    }

    /** @return array<string,mixed> */
    public function appendBreakdown(
        int $supplierId,
        int $caseId,
        int $claimId,
        int $principal,
        int $interest,
        int $costs,
        int $maintenance,
        int $documentId,
        string $documentSha256,
        ?string $reason,
        int $actorUserId,
    ): array {
        $claim = $this->claim($supplierId, $caseId, $claimId, true);
        if (($claim['status'] ?? null) !== 'received') {
            throw new \DomainException(
                'Použitou pohledávku nelze tiše překlasifikovat; založte opravný právní postup.',
            );
        }
        $total = $principal + $interest + $costs + $maintenance;
        if ($total !== (int) $claim['outstanding_minor_units']) {
            throw new \InvalidArgumentException(
                'Rozpad pohledávky musí přesně odpovídat její původní částce.',
            );
        }
        $existing = $this->findBreakdown(
            $supplierId, $caseId, $claimId, $principal, $interest, $costs,
            $maintenance, $documentId, $documentSha256, $reason,
        );
        if ($existing !== null) {
            return $existing;
        }
        $this->assertClaimUnused($supplierId, $caseId, $claimId, (string) $claim['claim_key']);
        $revision = $this->nextBreakdownRevision($supplierId, $claimId);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_enforcement_claim_breakdowns
                (supplier_id, case_id, claim_id, revision_no,
                 principal_minor_units, interest_minor_units, costs_minor_units,
                 maintenance_minor_units, source_document_id,
                 source_document_sha256, change_reason, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $stmt->execute([
            $supplierId, $caseId, $claimId, $revision, $principal, $interest,
            $costs, $maintenance, $documentId, $documentSha256, $reason,
            $actorUserId,
        ]);
        return $this->breakdownById($supplierId, (int) $this->db->pdo()->lastInsertId());
    }

    /** @return array<string,mixed> */
    public function appendRecipientInstruction(
        int $supplierId,
        int $caseId,
        string $effectiveFrom,
        int $recipientPartyId,
        int $paymentAccountId,
        int $documentId,
        string $documentSha256,
        ?string $reason,
        int $actorUserId,
    ): array {
        $this->caseExists($supplierId, $caseId, true);
        $this->assertRecipientParty($supplierId, $caseId, $recipientPartyId, $effectiveFrom);
        $existing = $this->findRecipientInstruction(
            $supplierId, $caseId, $effectiveFrom, $recipientPartyId,
            $paymentAccountId, $documentId, $documentSha256, $reason,
        );
        if ($existing !== null) {
            return $existing;
        }
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_enforcement_recipient_instructions
                (supplier_id, case_id, revision_no, effective_from,
                 recipient_party_id, payment_account_id, source_document_id,
                 source_document_sha256, change_reason, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $stmt->execute([
            $supplierId, $caseId,
            $this->nextRecipientInstructionRevision($supplierId, $caseId),
            $effectiveFrom, $recipientPartyId, $paymentAccountId, $documentId,
            $documentSha256, $reason, $actorUserId,
        ]);
        return $this->recipientInstructionById(
            $supplierId,
            (int) $this->db->pdo()->lastInsertId(),
        );
    }

    /**
     * New activation is intentionally fail-closed. Legacy cases can stay as
     * historical evidence, but nobody may attest their recipient by setting a
     * boolean: a current authority, beneficiary and documented instruction are
     * all required.
     */
    public function assertLegalRecipientReadyForActivation(
        int $supplierId,
        int $caseId,
        string $effectiveOn,
    ): void {
        $this->caseExists($supplierId, $caseId, true);
        $roles = $this->currentPartyRoles($supplierId, $caseId, $effectiveOn);
        if (!isset($roles['beneficiary'])
            || (!isset($roles['court']) && !isset($roles['executor']))) {
            throw new \InvalidArgumentException(
                'Aktivace vyžaduje aktuální doložený soud nebo exekutora a oprávněného.',
            );
        }
        $instruction = $this->currentRecipientInstruction(
            $supplierId,
            $caseId,
            $effectiveOn,
        );
        if ($instruction === null) {
            throw new \InvalidArgumentException(
                'Aktivace vyžaduje doloženou instrukci příjemce k ověřenému účtu.',
            );
        }
        $partyId = (int) $instruction['recipient_party_id'];
        $role = (string) $instruction['party_role'];
        if (!isset($roles[$role]) || (int) $roles[$role] !== $partyId) {
            throw new \InvalidArgumentException(
                'Instrukce příjemce neodkazuje na aktuální právní stranu případu.',
            );
        }
        $account = $this->db->pdo()->prepare(
            'SELECT 1
               FROM payroll_institution_accounts account
               JOIN payroll_institutions institution
                 ON institution.supplier_id = account.supplier_id
                AND institution.id = account.institution_id
              WHERE account.supplier_id = ? AND account.id = ?
                AND institution.institution_type = "other_recipient"
                AND account.valid_from <= ?
                AND (account.valid_to IS NULL OR account.valid_to >= ?)
                AND account.verified_on IS NOT NULL',
        );
        $account->execute([
            $supplierId,
            (int) $instruction['payment_account_id'],
            $effectiveOn,
            $effectiveOn,
        ]);
        if ($account->fetchColumn() === false) {
            throw new \InvalidArgumentException(
                'Instrukce příjemce neodkazuje k datu účinnosti na ověřený účet.',
            );
        }
    }

    private function caseExists(int $supplierId, int $caseId, bool $lock = false): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_enforcement_cases
              WHERE supplier_id = ? AND id = ?' . ($lock ? ' FOR UPDATE' : ''),
        );
        $stmt->execute([$supplierId, $caseId]);
        if ($stmt->fetchColumn() === false) {
            throw new \OutOfBoundsException('Exekuční případ nebyl nalezen.');
        }
    }

    /** @return array<string,mixed> */
    private function claim(int $supplierId, int $caseId, int $claimId, bool $lock): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT claim.id, claim.claim_key, claim.outstanding_minor_units,
                    enforcement_case.status
               FROM payroll_enforcement_claims claim
               JOIN payroll_enforcement_cases enforcement_case
                 ON enforcement_case.supplier_id = claim.supplier_id
                AND enforcement_case.id = claim.case_id
              WHERE claim.supplier_id = ? AND claim.case_id = ? AND claim.id = ?'
                . ($lock ? ' FOR UPDATE' : ''),
        );
        $stmt->execute([$supplierId, $caseId, $claimId]);
        $claim = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($claim)) {
            throw new \OutOfBoundsException('Pohledávka exekučního případu nebyla nalezena.');
        }
        return $claim;
    }

    /** @return array<string,mixed>|null */
    private function findParty(
        int $supplierId, int $caseId, string $role, string $effectiveFrom,
        string $name, ?string $reference, int $documentId, string $sha,
    ): ?array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, party_role, revision_no, effective_from, party_name,
                    party_reference, source_document_id, created_at
               FROM payroll_enforcement_case_parties
              WHERE supplier_id = ? AND case_id = ? AND party_role = ?
                AND effective_from = ? AND party_name = ?
                AND party_reference <=> ? AND source_document_id = ?
                AND source_document_sha256 = ? LIMIT 1',
        );
        $stmt->execute([$supplierId, $caseId, $role, $effectiveFrom, $name, $reference, $documentId, $sha]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function findBreakdown(
        int $supplierId, int $caseId, int $claimId, int $principal, int $interest,
        int $costs, int $maintenance, int $documentId, string $sha, ?string $reason,
    ): ?array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, revision_no, principal_minor_units, interest_minor_units,
                    costs_minor_units, maintenance_minor_units, total_minor_units,
                    source_document_id, change_reason, created_at
               FROM payroll_enforcement_claim_breakdowns
              WHERE supplier_id = ? AND case_id = ? AND claim_id = ?
                AND principal_minor_units = ? AND interest_minor_units = ?
                AND costs_minor_units = ? AND maintenance_minor_units = ?
                AND source_document_id = ? AND source_document_sha256 = ?
                AND change_reason <=> ? LIMIT 1',
        );
        $stmt->execute([$supplierId, $caseId, $claimId, $principal, $interest, $costs, $maintenance, $documentId, $sha, $reason]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function findRecipientInstruction(
        int $supplierId, int $caseId, string $effectiveFrom,
        int $recipientPartyId, int $paymentAccountId, int $documentId, string $sha,
        ?string $reason,
    ): ?array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, revision_no, effective_from, recipient_party_id,
                    payment_account_id, source_document_id, change_reason, created_at
               FROM payroll_enforcement_recipient_instructions
              WHERE supplier_id = ? AND case_id = ? AND effective_from = ?
                AND recipient_party_id = ? AND payment_account_id = ?
                AND source_document_id = ? AND source_document_sha256 = ?
                AND change_reason <=> ?
              LIMIT 1',
        );
        $stmt->execute([
            $supplierId, $caseId, $effectiveFrom, $recipientPartyId,
            $paymentAccountId, $documentId, $sha, $reason,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function assertRecipientParty(
        int $supplierId,
        int $caseId,
        int $partyId,
        string $effectiveOn,
    ): void
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1
               FROM payroll_enforcement_case_parties party
              WHERE party.supplier_id = ? AND party.case_id = ? AND party.id = ?
                AND party.party_role IN ('executor', 'beneficiary')
                AND party.effective_from <= ?
                AND party.revision_no = (
                    SELECT MAX(current_party.revision_no)
                      FROM payroll_enforcement_case_parties current_party
                     WHERE current_party.supplier_id = party.supplier_id
                       AND current_party.case_id = party.case_id
                       AND current_party.party_role = party.party_role
                       AND current_party.effective_from <= ?
                )",
        );
        $stmt->execute([$supplierId, $caseId, $partyId, $effectiveOn, $effectiveOn]);
        if ($stmt->fetchColumn() === false) {
            throw new \InvalidArgumentException(
                'Příjemcem může být jen aktuální exekutor nebo oprávněný stejného případu.',
            );
        }
    }

    /** @return array<string,int> */
    private function currentPartyRoles(int $supplierId, int $caseId, string $effectiveOn): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT party_role, id
               FROM payroll_enforcement_case_parties party
              WHERE party.supplier_id = ? AND party.case_id = ?
                AND party.effective_from <= ?
                AND party.revision_no = (
                    SELECT MAX(newer.revision_no)
                      FROM payroll_enforcement_case_parties newer
                     WHERE newer.supplier_id = party.supplier_id
                       AND newer.case_id = party.case_id
                       AND newer.party_role = party.party_role
                       AND newer.effective_from <= ?
                )',
        );
        $stmt->execute([$supplierId, $caseId, $effectiveOn, $effectiveOn]);
        $roles = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $party) {
            $roles[(string) $party['party_role']] = (int) $party['id'];
        }
        return $roles;
    }

    /** @return array<string,mixed>|null */
    private function currentRecipientInstruction(
        int $supplierId,
        int $caseId,
        string $effectiveOn,
    ): ?array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT instruction.recipient_party_id, instruction.payment_account_id,
                    party.party_role
               FROM payroll_enforcement_recipient_instructions instruction
               JOIN payroll_enforcement_case_parties party
                 ON party.supplier_id = instruction.supplier_id
                AND party.id = instruction.recipient_party_id
              WHERE instruction.supplier_id = ? AND instruction.case_id = ?
                AND instruction.effective_from <= ?
              ORDER BY instruction.effective_from DESC, instruction.revision_no DESC
              LIMIT 1',
        );
        $stmt->execute([$supplierId, $caseId, $effectiveOn]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function nextRevision(string $table, int $supplierId, int $caseId, string $role): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(MAX(revision_no), 0) + 1 FROM {$table}
              WHERE supplier_id = ? AND case_id = ? AND party_role = ?",
        );
        $stmt->execute([$supplierId, $caseId, $role]);
        return (int) $stmt->fetchColumn();
    }

    private function nextBreakdownRevision(int $supplierId, int $claimId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(MAX(revision_no), 0) + 1
               FROM payroll_enforcement_claim_breakdowns
              WHERE supplier_id = ? AND claim_id = ?',
        );
        $stmt->execute([$supplierId, $claimId]);
        return (int) $stmt->fetchColumn();
    }

    private function nextRecipientInstructionRevision(int $supplierId, int $caseId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(MAX(revision_no), 0) + 1
               FROM payroll_enforcement_recipient_instructions
              WHERE supplier_id = ? AND case_id = ?',
        );
        $stmt->execute([$supplierId, $caseId]);
        return (int) $stmt->fetchColumn();
    }

    private function assertClaimUnused(int $supplierId, int $caseId, int $claimId, string $claimKey): void
    {
        $pdo = $this->db->pdo();
        $checks = [
            ['SELECT 1 FROM payroll_enforcement_allocations WHERE supplier_id = ? AND claim_id = ? LIMIT 1', [$supplierId, $claimId]],
            ['SELECT 1 FROM payroll_enforcement_ledger WHERE supplier_id = ? AND claim_id = ? LIMIT 1', [$supplierId, $claimId]],
            ['SELECT 1 FROM payroll_payment_liabilities WHERE supplier_id = ? AND liability_kind = "enforcement" AND liability_reference = ? LIMIT 1', [$supplierId, "enforcement:c{$caseId}:cl{$claimId}"]],
            ["SELECT 1 FROM payroll_enforcement_month_results WHERE supplier_id = ? AND JSON_SEARCH(input_snapshot_json, 'one', ?, NULL, '$.claims[*].id') IS NOT NULL LIMIT 1", [$supplierId, $claimKey]],
        ];
        foreach ($checks as [$sql, $params]) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            if ($stmt->fetchColumn() !== false) {
                throw new \DomainException('Použitou pohledávku nelze tiše překlasifikovat; založte opravný právní postup.');
            }
        }
    }

    /** @return array<string,mixed> */
    private function partyById(int $supplierId, int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, party_role, revision_no, effective_from, party_name, party_reference, source_document_id, created_at FROM payroll_enforcement_case_parties WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $id]);
        return $this->requiredRow($stmt);
    }

    /** @return array<string,mixed> */
    private function breakdownById(int $supplierId, int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, revision_no, principal_minor_units, interest_minor_units, costs_minor_units, maintenance_minor_units, total_minor_units, source_document_id, change_reason, created_at FROM payroll_enforcement_claim_breakdowns WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $id]);
        return $this->requiredRow($stmt);
    }

    /** @return array<string,mixed> */
    private function recipientInstructionById(int $supplierId, int $id): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, revision_no, effective_from, recipient_party_id,
                    payment_account_id, source_document_id, change_reason, created_at
               FROM payroll_enforcement_recipient_instructions
              WHERE supplier_id = ? AND id = ?',
        );
        $stmt->execute([$supplierId, $id]);
        return $this->requiredRow($stmt);
    }

    /** @return array<string,mixed> */
    private function requiredRow(\PDOStatement $stmt): array
    {
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \RuntimeException('Uložený exekuční právní údaj nelze načíst.');
        }
        return $row;
    }
}
