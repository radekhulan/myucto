<?php

declare(strict_types=1);

namespace MyInvoice\Service\Nonprofit\Membership;

use MyInvoice\Repository\Nonprofit\Membership\MembershipBalanceRepository;

final class MembershipReconciliationService
{
    public function __construct(private readonly MembershipBalanceRepository $balances) {}

    public function memberBalance(int $supplier, int $member, int $afterClaimId = 0, int $limit = 100): array
    {
        $limit = max(1, min(100, $limit));
        $claims = $this->balances->claimsForMember($supplier, $member, $afterClaimId, $limit + 1);
        $more = count($claims) > $limit;
        if ($more) $claims = array_slice($claims, 0, $limit);
        $evidence = $this->balances->pairingEvidence($supplier, $claims);
        $rows = [];
        $activeClaimCents = 0;
        $provenOpenCents = 0;
        $unreconciled = 0;
        foreach ($claims as $claim) {
            $settlement = $this->settlement($claim, $evidence[$claim['claim_id']] ?? null);
            if ($claim['reversal_claim_id'] === null) $activeClaimCents += $claim['amount_cents'];
            if ($settlement['open_cents'] !== null) $provenOpenCents += $settlement['open_cents'];
            if ($settlement['status'] === 'unreconciled' || $settlement['status'] === 'ambiguous') $unreconciled++;
            $rows[] = $claim + ['settlement' => $settlement];
        }
        return [
            'member_id' => $member,
            'page_active_claim_cents' => $activeClaimCents,
            'page_proven_open_cents' => $provenOpenCents,
            'page_unreconciled_count' => $unreconciled,
            'items' => $rows,
            'next_after_claim_id' => $more ? $claims[array_key_last($claims)]['claim_id'] : null,
        ];
    }

    public function claimSettlement(int $supplier, int $claimId): array
    {
        $claim = $this->balances->claim($supplier, $claimId)
            ?? throw new MembershipException('nonprofit_membership_claim_not_found', 'Pohledávka členského příspěvku nebyla nalezena.', 404);
        $evidence = $this->balances->pairingEvidence($supplier, [$claim]);
        return $claim + ['settlement' => $this->settlement($claim, $evidence[$claimId] ?? null)];
    }

    public function periodReconciliation(int $supplier, int $period): array
    {
        $totals = $this->balances->reconciliationTotals($supplier, $period);
        $claims = (int) $totals['claims']['cents'];
        $operations = (int) $totals['operations']['cents'];
        $receivable = (int) $totals['ledger']['receivable_cents'];
        $revenue = (int) $totals['ledger']['revenue_cents'];
        $countsAgree = (int) $totals['claims']['row_count'] === (int) $totals['operations']['row_count']
            && (int) $totals['operations']['row_count'] === (int) $totals['ledger']['operation_count'];
        $complete = $countsAgree && (int) $totals['ledger']['invalid_count'] === 0;
        return $totals + [
            'status' => $complete && $claims === $operations && $operations === $receivable && $receivable === $revenue ? 'balanced' : 'mismatch',
            'difference_cents' => [
                'claims_to_operations' => $claims - $operations,
                'operations_to_receivable' => $operations - $receivable,
                'receivable_to_revenue' => $receivable - $revenue,
            ],
            'proof_complete' => $complete,
        ];
    }

    private function settlement(array $claim, ?array $evidence): array
    {
        if ($claim['reversal_claim_id'] !== null) {
            return ['status' => 'reversed', 'paid_cents' => null, 'open_cents' => 0, 'pairing_id' => null, 'evidence' => 'membership_reversal_claim'];
        }
        if ($claim['posted_at'] === null || $claim['reversed_by'] !== null) {
            return ['status' => 'ambiguous', 'paid_cents' => null, 'open_cents' => null, 'pairing_id' => null, 'evidence' => 'journal_receipt_mismatch'];
        }
        if ($evidence === null) {
            return ['status' => 'unreconciled', 'paid_cents' => null, 'open_cents' => null, 'pairing_id' => null, 'evidence' => 'no_native_pairing'];
        }
        $net = $evidence['net_cents'];
        if ($evidence['invalid_count'] !== 0 || $evidence['membership_claim_count'] !== 1 || $net < 0 || $net > $claim['amount_cents']) {
            return ['status' => 'ambiguous', 'paid_cents' => null, 'open_cents' => null, 'pairing_id' => $evidence['pairing_id'], 'evidence' => 'pairing_not_exact_native_payment'];
        }
        return [
            'status' => $net === 0 ? 'settled' : 'partially_settled',
            'paid_cents' => $claim['amount_cents'] - $net,
            'open_cents' => $net,
            'pairing_id' => $evidence['pairing_id'],
            'evidence' => 'journal_line_pairing_bank_or_cash',
        ];
    }
}
