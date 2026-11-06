<?php

declare(strict_types=1);

namespace MyInvoice\Service\Nonprofit\Membership;

use MyInvoice\Repository\Nonprofit\Membership\MembershipBalanceRepository;

final class MembershipReceivablePaymentAdapter
{
    public function __construct(private readonly MembershipBalanceRepository $balances) {}

    public function target(int $supplier, int $claimId): array
    {
        $claim = $this->balances->claim($supplier, $claimId)
            ?? throw new MembershipException('nonprofit_membership_claim_not_found', 'Pohledávka členského příspěvku nebyla nalezena.', 404);
        if ($claim['reversal_claim_id'] !== null || $claim['reversed_by'] !== null || $claim['posted_at'] === null) {
            throw new MembershipException('nonprofit_membership_claim_not_payable', 'Pohledávka už není platným cílem úhrady.', 409);
        }
        return [
            'schema' => 'nonprofit_membership_payment_target.v1',
            'supplier_id' => $supplier,
            'claim_id' => $claim['claim_id'],
            'case_id' => $claim['case_id'],
            'member_id' => $claim['member_id'],
            'client_id' => $claim['client_id'],
            'currency' => 'CZK',
            'amount_cents' => $claim['amount_cents'],
            'journal_line' => [
                'entry_id' => $claim['journal_entry_id'],
                'line_id' => $claim['receivable_line_id'],
                'line_no' => $claim['line_no'],
                'account_id' => $claim['account_id'],
            ],
            'accepted_payment_source_types' => ['bank', 'cash'],
            'pairing_contract' => 'journal_line_pairings.exact_single_membership_claim.v1',
        ];
    }
}
