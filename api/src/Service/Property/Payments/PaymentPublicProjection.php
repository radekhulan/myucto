<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Payments;

final class PaymentPublicProjection
{
    public static function operation(array $operation): array
    {
        $result = self::only($operation, ['id', 'kind', 'status', 'row_version', 'amount_cents', 'accounting_date', 'reason', 'source_label', 'unit_labels', 'credit_id']);
        $result['allocations'] = array_map(static fn (array $allocation): array => self::only($allocation,
            ['id', 'debt_id', 'charge_line_id', 'accessory_id', 'opening_component_id', 'amount_cents']), $operation['allocations']);
        return $result;
    }

    public static function card(array $card): array
    {
        $result = self::only($card, ['as_of', 'known_at', 'scope', 'totals', 'precision', 'coverage']);
        $result['source_snapshot_hash'] = $card['snapshot_hash'];
        $result['claims'] = array_map(static function (array $claim): array {
            $result = self::only($claim, ['claim_key', 'debt_id', 'unit_id', 'unit_label', 'due_date', 'title_ref', 'amount_cents', 'person_liability_cents', 'precision', 'complete', 'source_hash']);
            $result['groups'] = array_map(static fn (array $group): array => self::only($group,
                ['group_key', 'role', 'mode', 'basis', 'members', 'extent', 'valid_from', 'valid_to', 'remaining_cap_cents']), $claim['groups']);
            $result['liability'] = self::only($claim['liability'], ['event_id', 'event_hash', 'head_event_id', 'complete', 'review_required', 'precision', 'remaining_cents']);
            return $result;
        }, $card['claims']);
        $result['credits'] = array_map(static fn (array $credit): array => self::only($credit,
            ['id', 'unit_id', 'unit_label', 'parties', 'due_date', 'source_hash', 'complete', 'precision', 'uncertain_event_ids', 'gross_credit_cents', 'reserved_credit_cents', 'available_credit_cents']), $card['credits']);
        return $result;
    }

    private static function only(array $source, array $keys): array
    {
        return array_intersect_key($source, array_fill_keys($keys, true));
    }
}
