<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Services\Settlement;

final class SettlementPublicView
{
    public static function document(array $read): array
    {
        $facts = $read['document'];
        return ['id' => $read['id'],'revision_no' => $read['revision_no'],'unit' => $read['unit'],'service' => $read['service'],'segment_key' => $facts['segment_key'],'period_from' => $facts['period_from'],'period_to' => $facts['period_to'],'cutoff_at' => $facts['cutoff_at'],'prescribed_cents' => $facts['prescribed_cents'],'paid_at_cutoff_cents' => $facts['paid_cents'],'actual_cents' => $facts['actual_cents'],'amounts' => array_intersect_key($facts['amounts'], array_flip(['advance_applied_cents','new_claim_cents','cancelled_unpaid_cents','new_credit_cents','remaining_old_claim_cents'])),'current_summary' => $read['current_summary'],'scope' => 'recorded_s8_documents','complete_financial_position' => false];
    }
}
