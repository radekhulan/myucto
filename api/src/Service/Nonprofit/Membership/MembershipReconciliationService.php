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
        $openCents = 0;
        $needsReview = 0;
        foreach ($claims as $claim) {
            $settlement = $this->settlement($claim, $evidence[$claim['claim_id']] ?? null);
            if ($claim['reversal_claim_id'] === null) $activeClaimCents += $claim['amount_cents'];
            // Nespárovaný předpis je neuhrazený celou částkou; neurčený zůstatek mají jen předpisy k prověření.
            if ($settlement['open_cents'] !== null) {
                $openCents += $settlement['open_cents'];
            }
            if ($settlement['status'] === 'ambiguous') {
                $needsReview++;
            }
            $rows[] = $claim + ['settlement' => $settlement];
        }
        return [
            'member_id' => $member,
            'page_active_claim_cents' => $activeClaimCents,
            // Stejně jako seznam: s předpisem k prověření je zůstatek neurčený, ne součet jen známých částí.
            'page_open_cents' => $needsReview > 0 ? null : $openCents,
            'page_needs_review_count' => $needsReview,
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
        if ($claim['posted_at'] === null || $claim['reversed_by'] !== null || !$claim['account_id'] || empty($claim['receipt_valid'])) {
            return ['status' => 'ambiguous', 'paid_cents' => null, 'open_cents' => null, 'pairing_id' => null, 'evidence' => 'journal_receipt_mismatch'];
        }
        if ($evidence === null) {
            return ['status' => 'unreconciled', 'paid_cents' => 0, 'open_cents' => $claim['amount_cents'], 'pairing_id' => null, 'evidence' => 'no_native_pairing'];
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

    /**
     * SQL podoba pravidel settlement(): filtr musí proběhnout před stránkováním, PHP metodu z SQL volat nelze.
     * Agregace párování je společná; shodu stavů SQL přehledu s detailem ověřuje integrační test.
     */
    public static function settlementStatusSql(): string
    {
        return "CASE WHEN c.posted_at IS NULL OR c.reversed_by IS NOT NULL OR c.receipt_valid=0 THEN 'ambiguous'
          WHEN p.claim_id IS NULL THEN 'unreconciled'
          WHEN p.invalid_count<>0 OR p.membership_claim_count<>1 OR p.net_cents<0 OR p.net_cents>c.amount_cents THEN 'ambiguous'
          WHEN p.net_cents=0 THEN 'settled' ELSE 'partially_settled' END";
    }
}
