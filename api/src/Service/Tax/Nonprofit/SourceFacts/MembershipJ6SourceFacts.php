<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\SourceFacts;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Nonprofit\Membership\MembershipChargeRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipRepository;
use MyInvoice\Service\Nonprofit\Membership\MembershipClassification;
use MyInvoice\Service\Nonprofit\Membership\MembershipException;
use MyInvoice\Service\Nonprofit\Membership\MembershipJournalProof;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use PDO;

final class MembershipJ6SourceFacts implements NonprofitTaxSourceFactProvider
{
    public const SCHEMA = 'nonprofit-membership-j6-facts.v1';

    public function __construct(private readonly Connection $db) {}

    public function schema(): string
    {
        return self::SCHEMA;
    }

    public function capture(int $supplierId, array $pageRows, bool $currentRead): array
    {
        if ($currentRead && !$this->db->pdo()->inTransaction()) throw new \LogicException('Current membership J6 facts require a transaction.');
        $page = [];
        foreach ($pageRows as $row) {
            if (($row['source_type'] ?? null) !== 'nonprofit_membership') continue;
            $lineId = $row['line_id'] ?? null;
            if (!is_int($lineId) || $lineId <= 0 || isset($page[$lineId])) throw new \InvalidArgumentException('Membership J6 page line is invalid.');
            $page[$lineId] = $row;
        }
        if ($page === []) return [];
        if (count($page) > MembershipJournalProof::MAX_BATCH) throw new \InvalidArgumentException('Membership J6 facts exceed their batch bound.');
        $marks = implode(',', array_fill(0, count($page), '?'));
        $stmt = $this->db->pdo()->prepare("SELECT o.*,c.member_id,c.title_key,cl.id AS claim_id,cl.original_claim_id,cl.client_id,
                cl.kind AS claim_kind,cl.amount_cents AS claim_amount_cents,cl.record_hash AS claim_record_hash,
                cl.recorded_at_utc AS claim_recorded_at_utc,
                mr.record_hash AS member_revision_hash,mr.member_id AS revision_member_id,mr.revision_no,
                mr.previous_id AS revision_previous_id,mr.effective_from AS revision_effective_from,
                mr.status AS revision_status,mr.legal_basis_json,mr.request_json AS revision_request_json,
                mr.idempotency_key AS revision_idempotency_key,mr.request_hash AS revision_request_hash,
                mr.parent_hash AS revision_parent_hash,mr.created_by AS revision_created_by,
                mr.recorded_at_utc AS revision_recorded_at_utc,
                lp.record_hash AS legal_profile_hash
            FROM nonprofit_membership_charge_operations o
            JOIN nonprofit_membership_charge_cases c ON c.supplier_id=o.supplier_id AND c.id=o.case_id
            JOIN nonprofit_membership_charge_claims cl ON cl.supplier_id=o.supplier_id AND cl.operation_id=o.id
            JOIN nonprofit_member_revisions mr ON mr.supplier_id=o.supplier_id AND mr.id=o.member_revision_id
            JOIN nonprofit_legal_profiles lp ON lp.supplier_id=o.supplier_id AND lp.id=o.legal_profile_id
            WHERE o.supplier_id=? AND o.revenue_line_id IN ({$marks}) ORDER BY o.revenue_line_id" . ($currentRead ? ' FOR UPDATE' : ''));
        $stmt->execute([$supplierId, ...array_keys($page)]);
        $operations = [];
        $joined = [];
        $classifications = [];
        $chargeRepository = new MembershipChargeRepository($this->db);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $raw) {
            $operation = $raw;
            foreach (['id', 'supplier_id', 'case_id', 'command_id', 'member_revision_id', 'legal_profile_id', 'accounting_period_id',
                'amount_cents', 'receivable_account_id', 'revenue_account_id', 'journal_entry_id', 'receivable_line_id', 'revenue_line_id', 'created_by'] as $field) {
                $operation[$field] = (int) $operation[$field];
            }
            foreach (['previous_operation_id', 'original_operation_id'] as $field) $operation[$field] = $operation[$field] === null ? null : (int) $operation[$field];
            foreach (['proof', 'receipt'] as $field) $operation[$field] = json_decode($operation[$field . '_json'], true, flags: JSON_THROW_ON_ERROR);
            unset($operation['proof_json'], $operation['receipt_json']);
            if (!hash_equals($operation['record_hash'], NonprofitTaxRules::hash($chargeRepository->operationRecord($operation)))) self::corrupt();
            $lineId = $operation['revenue_line_id'];
            if (!isset($page[$lineId]) || isset($operations[$operation['id']])) self::corrupt();
            $proof = $operation['proof'];
            $legalBasis = json_decode($raw['legal_basis_json'], true, flags: JSON_THROW_ON_ERROR);
            if (($proof['schema'] ?? null) !== 'nonprofit_membership_charge.v1'
                || ($proof['tax_treatment'] ?? null) !== 'unclassified_requires_explicit_j6_decision'
                || (int) ($proof['member']['id'] ?? 0) !== (int) $raw['member_id']
                || (int) ($proof['member_revision']['id'] ?? 0) !== $operation['member_revision_id']
                || !hash_equals((string) ($proof['member_revision']['record_hash'] ?? ''), (string) $raw['member_revision_hash'])
                || ($legalBasis['organisation_form'] ?? null) !== 'association'
                || ($legalBasis['relationship'] ?? null) !== 'nonreciprocal_membership'
                || (int) ($proof['legal_profile']['id'] ?? 0) !== $operation['legal_profile_id']
                || !hash_equals((string) ($proof['legal_profile']['record_hash'] ?? ''), (string) $raw['legal_profile_hash'])
                || ($proof['legal_profile']['profile']['entity_type'] ?? null) !== 'association'
                || ($proof['legal_title']['relationship'] ?? null) !== 'nonreciprocal_membership'
                || (int) ($proof['accounting_period']['id'] ?? 0) !== $operation['accounting_period_id']
                || !is_array($proof['classification'] ?? null)) self::corrupt();
            $claimRecord = ['supplier_id' => $supplierId, 'case_id' => $operation['case_id'], 'operation_id' => $operation['id'],
                'original_claim_id' => $raw['original_claim_id'] === null ? null : (int) $raw['original_claim_id'],
                'member_id' => (int) $raw['member_id'], 'client_id' => (int) $raw['client_id'], 'kind' => $raw['claim_kind'],
                'amount_cents' => (int) $raw['claim_amount_cents'], 'recorded_at_utc' => $raw['claim_recorded_at_utc']];
            if (!hash_equals((string) $raw['claim_record_hash'], NonprofitTaxRules::hash(MembershipChargeRepository::claimRecord($claimRecord)))) self::corrupt();
            $revisionRecord = ['supplier_id' => $supplierId, 'member_id' => (int) $raw['revision_member_id'],
                'revision_no' => (int) $raw['revision_no'],
                'previous_id' => $raw['revision_previous_id'] === null ? null : (int) $raw['revision_previous_id'],
                'effective_from' => $raw['revision_effective_from'], 'status' => $raw['revision_status'],
                'legal_basis' => $legalBasis,
                'request' => json_decode($raw['revision_request_json'], true, flags: JSON_THROW_ON_ERROR),
                'idempotency_key' => $raw['revision_idempotency_key'], 'request_hash' => $raw['revision_request_hash'],
                'parent_hash' => $raw['revision_parent_hash'], 'created_by' => (int) $raw['revision_created_by'],
                'recorded_at_utc' => $raw['revision_recorded_at_utc']];
            if (!hash_equals((string) $raw['member_revision_hash'], NonprofitTaxRules::hash(MembershipRepository::revisionRecord($revisionRecord)))) self::corrupt();
            $row = $page[$lineId];
            $expected = $operation['operation'] === 'issue' ? $operation['amount_cents'] : -$operation['amount_cents'];
            if ((int) $row['entry_id'] !== $operation['journal_entry_id'] || $row['date'] !== $operation['accounting_date']
                || (int) $row['amount_cents'] !== $expected || $row['kind'] !== 'revenue'
                || ($operation['operation'] === 'issue' ? $raw['claim_kind'] !== 'issue' : $raw['claim_kind'] !== 'reversal')) self::corrupt();
            foreach (MembershipClassification::AXES as $axis) {
                $pageAxis = $row['axes'][$axis] ?? null;
                $proofAxis = $proof['classification'][$axis] ?? null;
                if (!is_array($pageAxis) || !is_array($proofAxis) || ($pageAxis['type_id'] ?? null) !== ($proofAxis['type_id'] ?? null)
                    || ($pageAxis['value_id'] ?? null) !== ($proofAxis['value_id'] ?? null)) self::corrupt();
            }
            $operations[$operation['id']] = $operation;
            $joined[$operation['id']] = $raw;
            $classifications[$operation['id']] = $proof['classification'];
        }
        if (count($operations) !== count($page)) self::corrupt();
        (new MembershipJournalProof($this->db))->assertOperations($supplierId, array_values($operations), $currentRead);
        (new MembershipClassification($this->db))->assertStoredMany($supplierId, $classifications, $currentRead);
        $out = [];
        foreach ($operations as $operationId => $operation) {
            $raw = $joined[$operationId];
            $out[$operation['revenue_line_id']] = [
                'operation_id' => $operationId,
                'case_id' => $operation['case_id'],
                'claim_id' => (int) $raw['claim_id'],
                'claim_record_hash' => $raw['claim_record_hash'],
                'operation' => $operation['operation'],
                'reversal_of_operation_id' => $operation['original_operation_id'],
                'member_id' => (int) $raw['member_id'],
                'member_revision_id' => $operation['member_revision_id'],
                'legal_profile_id' => $operation['legal_profile_id'],
                'accounting_period_id' => $operation['accounting_period_id'],
                'accounting_date' => $operation['accounting_date'],
                'amount_cents' => $operation['operation'] === 'issue' ? $operation['amount_cents'] : -$operation['amount_cents'],
                'journal_entry_id' => $operation['journal_entry_id'],
                'journal_line_id' => $operation['revenue_line_id'],
                'member_revision_hash' => $raw['member_revision_hash'],
                'legal_profile_hash' => $raw['legal_profile_hash'],
                'operation_record_hash' => $operation['record_hash'],
                'journal_receipt_hash' => NonprofitTaxRules::hash($operation['receipt']),
                'classification' => $classifications[$operationId],
                'classification_hash' => NonprofitTaxRules::hash($classifications[$operationId]),
                'legal_basis_code' => ['organisation_form' => 'association', 'relationship' => 'nonreciprocal_membership'],
                'tax_treatment' => 'unclassified_requires_explicit_j6_decision',
            ];
        }
        ksort($out, SORT_NUMERIC);
        return $out;
    }

    private static function corrupt(): never
    {
        throw new MembershipException('nonprofit_membership_j6_proof_invalid', 'Členský příspěvek nemá průkazný typovaný podklad J6.', 409);
    }
}
