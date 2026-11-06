<?php

declare(strict_types=1);

namespace MyInvoice\Service\Nonprofit\Membership;

use MyInvoice\Http\IsoDate;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Nonprofit\Membership\MembershipBalanceRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipChargeRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipRepository;
use MyInvoice\Service\Property\PropertyInstant;

final class MembershipPublicCatalog
{
    private readonly MembershipReconciliationService $reconciliation;

    public function __construct(
        private readonly Connection $db,
        private readonly MembershipRepository $members,
        private readonly MembershipChargeRepository $charges,
        MembershipBalanceRepository $balances,
    ) { $this->reconciliation = new MembershipReconciliationService($balances); }

    public function roles(int $supplier, string $asOf, ?string $knownAt, ?string $cursor, int $limit = 25, ?int $memberId = null): array
    {
        if (!IsoDate::isValid($asOf) || $supplier <= 0 || $limit < 1 || $limit > 100 || ($memberId !== null && $memberId <= 0)) self::invalidQuery();
        $knownAt = $knownAt === null ? null : PropertyInstant::normalize($knownAt);
        $after = 0;
        if ($cursor !== null) {
            if ($memberId !== null) self::invalidQuery();
            $decoded = MembershipCatalogCursor::decodeRole($cursor, $supplier, $asOf, $knownAt);
            $after = $decoded['after_id'];
            $knownAt = PropertyInstant::normalize($decoded['known_at']);
        }
        $knownAt ??= $this->now();
        $knownSql = PropertyInstant::sql($knownAt);
        $memberRows = $memberId === null
            ? $this->members->publicMembersPage($supplier, $asOf, $knownSql, $after, $limit + 1)
            : (($row = $this->members->publicMember($supplier, $memberId, $knownSql)) === null ? [] : [$row]);
        $more = $memberId === null && count($memberRows) > $limit;
        if ($more) $memberRows = array_slice($memberRows, 0, $limit);
        if ($memberRows === []) {
            if ($memberId !== null) self::notFound();
            return $this->roleResponse([], $asOf, $knownAt, null);
        }
        $effective = $this->members->effectiveMany($supplier, array_column($memberRows, 'id'), $asOf, $knownSql);
        $items = [];
        foreach ($memberRows as $member) {
            $revision = $effective[$member['id']] ?? null;
            if ($revision === null) continue;
            $basis = $revision['legal_basis'] ?? [];
            if (($basis['organisation_form'] ?? null) !== 'association' || ($basis['relationship'] ?? null) !== 'nonreciprocal_membership') {
                throw new MembershipException('nonprofit_membership_member_proof_changed', 'Zachycený členský podklad není průkazný.', 409);
            }
            $items[] = ['member_id' => $member['id'], 'client_id' => $member['client_id'], 'revision_id' => $revision['id'],
                'revision_no' => $revision['revision_no'], 'previous_revision_id' => $revision['previous_id'], 'role' => 'association_member',
                'status' => $revision['status'], 'effective_from' => $revision['effective_from'], 'effective_to' => $revision['effective_to'],
                'recorded_at' => MembershipRecordedTime::requiredInstant($revision['recorded_at_utc'] ?? null),
                'recorded_time_status' => 'proven_utc', 'proof_status' => 'verified'];
        }
        if ($memberId !== null && $items === []) self::notFound();
        $next = $more ? MembershipCatalogCursor::encode('roles', $supplier, ['as_of' => $asOf, 'known_at' => $knownAt], $memberRows[array_key_last($memberRows)]['id']) : null;
        return $this->roleResponse($items, $asOf, $knownAt, $next);
    }

    public function receivables(int $supplier, ?int $member, ?string $cursor, int $limit = 25, ?int $claimId = null): array
    {
        if ($supplier <= 0 || ($claimId === null && ($member === null || $member <= 0)) || ($member !== null && $member <= 0)
            || $limit < 1 || $limit > 100 || ($claimId !== null && $claimId <= 0)) self::invalidQuery();
        $detail = $claimId === null ? null : $this->reconciliation->claimSettlement($supplier, $claimId);
        if ($detail !== null) $member = $detail['member_id'];
        $filters = ['member_id' => $member];
        $after = $cursor === null ? 0 : MembershipCatalogCursor::decode($cursor, 'receivables', $supplier, $filters);
        if ($claimId !== null && $cursor !== null) self::invalidQuery();
        $balance = $claimId === null ? $this->reconciliation->memberBalance($supplier, $member, $after, $limit) : null;
        $rows = $claimId === null ? $balance['items'] : [$detail];
        if ($rows === []) return $this->receivableResponse([], null);
        $claims = $this->charges->claimsMany($supplier, array_column($rows, 'claim_id'));
        $operations = $this->charges->operationsMany($supplier, array_column($rows, 'operation_id'));
        if (count($claims) !== count($rows) || count($operations) !== count($rows)) self::proofChanged();
        (new MembershipStoredProof($this->db, $this->members))->assertMany($supplier, array_values($operations));
        $items = [];
        foreach ($rows as $row) {
            $claim = $claims[$row['claim_id']] ?? null;
            $operation = $operations[$row['operation_id']] ?? null;
            if ($claim === null || $operation === null || $claim['operation_id'] !== $operation['id'] || $claim['member_id'] !== $member) self::proofChanged();
            $settlement = $row['settlement'];
            $recorded = MembershipRecordedTime::projection($claim['recorded_at_utc'] ?? null);
            $items[] = ['claim_id' => $claim['id'], 'case_id' => $claim['case_id'], 'operation_id' => $operation['id'],
                'member_id' => $claim['member_id'], 'client_id' => $claim['client_id'], 'member_revision_id' => $operation['member_revision_id'],
                'legal_profile_id' => $operation['legal_profile_id'], 'accounting_period_id' => $operation['accounting_period_id'],
                'accounting_date' => $operation['accounting_date'], 'due_date' => $operation['due_date'],
                'period_from' => $row['period_from'], 'period_to' => $row['period_to'], 'amount_cents' => (string) $claim['amount_cents'],
                'journal_entry_id' => $operation['journal_entry_id'], 'receivable_line_id' => $operation['receivable_line_id'],
                'revenue_line_id' => $operation['revenue_line_id'], 'reversal_claim_id' => $row['reversal_claim_id'],
                'settlement' => ['status' => $settlement['status'], 'paid_cents' => $settlement['paid_cents'] === null ? null : (string) $settlement['paid_cents'],
                    'open_cents' => $settlement['open_cents'] === null ? null : (string) $settlement['open_cents'], 'pairing_id' => $settlement['pairing_id']],
                'recorded_at' => $recorded['recorded_at'], 'recorded_time_status' => $recorded['recorded_time_status'],
                'proof_status' => 'verified', 'knowledge' => 'current_native_pairings'];
        }
        $next = $claimId === null && $balance['next_after_claim_id'] !== null
            ? MembershipCatalogCursor::encode('receivables', $supplier, $filters, (int) $balance['next_after_claim_id']) : null;
        return $this->receivableResponse($items, $next);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    }

    private function roleResponse(array $items, string $asOf, string $knownAt, ?string $cursor): array
    {
        return ['items' => $items, 'next_cursor' => $cursor, 'as_of' => $asOf, 'known_at' => $knownAt,
            'temporal_basis' => 'effective_as_of_and_recorded_by_known_at', 'proof_status' => 'verified', 'financial_authorization' => false];
    }

    private function receivableResponse(array $items, ?string $cursor): array
    {
        return ['items' => $items, 'next_cursor' => $cursor, 'knowledge' => 'current_ledger_and_native_pairings',
            'settlement_coverage' => 'native_bank_cash_pairings_only', 'proof_status' => 'verified', 'financial_authorization' => false];
    }

    private static function invalidQuery(): never { throw new MembershipException('nonprofit_membership_catalog_query_invalid', 'Neplatný filtr nebo stránkování.', 422); }
    private static function notFound(): never { throw new MembershipException('nonprofit_membership_catalog_not_found', 'Záznam členského katalogu nebyl nalezen.', 404); }
    private static function proofChanged(): never { throw new MembershipException('nonprofit_membership_catalog_proof_invalid', 'Členský subledger není průkazný.', 409); }
}
