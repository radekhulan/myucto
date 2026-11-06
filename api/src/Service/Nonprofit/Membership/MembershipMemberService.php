<?php

declare(strict_types=1);

namespace MyInvoice\Service\Nonprofit\Membership;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\Nonprofit\Membership\MembershipRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use PDO;

final class MembershipMemberService
{
    public function __construct(
        private readonly Connection $db,
        private readonly MembershipRepository $members,
    ) {}

    public function create(int $supplier, int $client, string $memberNumber, int $actor): array
    {
        $memberNumber = trim($memberNumber);
        if ($supplier <= 0 || $client <= 0 || $actor <= 0 || $memberNumber === '' || mb_strlen($memberNumber) > 80) {
            throw new MembershipException('nonprofit_membership_invalid', 'Člen vyžaduje klienta, členské číslo a autora.');
        }
        return TransactionFailure::retrying($this->db->pdo(), fn (): array => (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplier, $client, $memberNumber, $actor): array {
            $q = $this->db->pdo()->prepare('SELECT id FROM supplier WHERE id=? FOR UPDATE');
            $q->execute([$supplier]);
            if ($q->fetchColumn() === false) throw new MembershipException('nonprofit_membership_tenant_not_found', 'Organizace nebyla nalezena.', 404);
            $q = $this->db->pdo()->prepare('SELECT id FROM clients WHERE supplier_id=? AND id=? FOR UPDATE');
            $q->execute([$supplier, $client]);
            if ($q->fetchColumn() === false) throw new MembershipException('nonprofit_membership_client_not_found', 'Klient nebyl nalezen v této organizaci.', 404);
            return $this->members->byIdentity($supplier, $client, $memberNumber, true)
                ?? $this->members->insertMember($supplier, $client, $memberNumber, $actor);
        }));
    }

    public function appendRevision(int $supplier, int $member, array $input, int $actor): array
    {
        $allowed = ['status', 'effective_from', 'legal_basis', 'expected_revision', 'idempotency_key'];
        if (array_diff(array_keys($input), $allowed) !== [] || $actor <= 0 || !is_int($input['expected_revision'] ?? null) || $input['expected_revision'] < 0
            || !in_array($input['status'] ?? null, ['active', 'suspended', 'ended'], true) || !is_array($input['legal_basis'] ?? null)) {
            throw new MembershipException('nonprofit_membership_invalid', 'Revize členství nemá platný stav, očekávanou revizi nebo autora.');
        }
        $effective = MembershipRevisionTimeline::date((string) ($input['effective_from'] ?? ''));
        $key = MembershipChargeRequest::key($input['idempotency_key'] ?? null);
        $basis = $this->legalBasis($input['legal_basis']);
        $request = ['member_id' => $member, 'status' => $input['status'], 'effective_from' => $effective, 'legal_basis' => $basis,
            'expected_revision' => $input['expected_revision'], 'actor' => $actor];
        $requestHash = NonprofitTaxRules::hash($request);
        return TransactionFailure::retrying($this->db->pdo(), fn (): array => (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplier, $member, $input, $actor, $effective, $key, $basis, $request, $requestHash): array {
            (new DimensionPeriodGuard($this->db))->lockPeriods($supplier);
            $existing = $this->members->revisionByKey($supplier, $key, true);
            if ($existing !== null) {
                if (!hash_equals($existing['request_hash'], $requestHash)) throw new MembershipException('nonprofit_membership_idempotency_conflict', 'Klíč požadavku už patří jiné revizi členství.', 409);
                return $existing;
            }
            if ($this->members->member($supplier, $member, true) === null) throw new MembershipException('nonprofit_membership_member_not_found', 'Člen nebyl nalezen v této organizaci.', 404);
            $history = $this->members->history($supplier, $member, true);
            $previous = $history === [] ? null : $history[array_key_last($history)];
            if (($previous['revision_no'] ?? 0) !== $input['expected_revision']) throw new MembershipException('nonprofit_membership_revision_changed', 'Historie členství se mezitím změnila.', 409);
            if ($previous === null && $input['status'] !== 'active') throw new MembershipException('nonprofit_membership_initial_status_invalid', 'První revize členství musí členství aktivovat.');
            if ($previous !== null && $effective <= $previous['effective_from']) throw new MembershipException('nonprofit_membership_effective_conflict', 'Nová revize musí začít po posledním datu účinnosti.', 409);
            return $this->members->appendRevision([
                'supplier_id' => $supplier, 'member_id' => $member, 'revision_no' => ($previous['revision_no'] ?? 0) + 1,
                'previous_id' => $previous['id'] ?? null, 'effective_from' => $effective, 'status' => $input['status'],
                'legal_basis' => $basis, 'request' => $request, 'idempotency_key' => $key, 'request_hash' => $requestHash,
                'parent_hash' => $previous['record_hash'] ?? str_repeat('0', 64), 'created_by' => $actor,
            ]);
        }));
    }

    private function legalBasis(array $input): array
    {
        if (array_diff(array_keys($input), ['organisation_form', 'relationship', 'reference', 'evidence']) !== []
            || ($input['organisation_form'] ?? null) !== 'association' || ($input['relationship'] ?? null) !== 'nonreciprocal_membership') {
            throw new MembershipException('nonprofit_membership_legal_basis_invalid', 'Členství musí být doložené členstvím ve spolku bez konkrétní protislužby.');
        }
        return [
            'organisation_form' => 'association',
            'relationship' => 'nonreciprocal_membership',
            'reference' => MembershipChargeRequest::text($input['reference'] ?? null, 'reference', 1000),
            'evidence' => MembershipChargeRequest::text($input['evidence'] ?? null, 'evidence', 2000),
        ];
    }
}
