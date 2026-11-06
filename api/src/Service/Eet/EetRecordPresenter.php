<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet;

/**
 * Jednotný tvar EET záznamu a platební události v API odpovědích (peníze jako
 * řetězce, časy ISO 8601 s offsetem). Bez XML a bez tajemství.
 */
final class EetRecordPresenter
{
    /** @param array<string,mixed> $row */
    public static function summary(array $row): array
    {
        return [
            'record_id' => (int) $row['id'],
            'public_id' => (string) $row['public_id'],
            'status' => (string) $row['status'],
            'action_code' => $row['action_code'] ?? null,
            'pok' => $row['pok'] ?? null,
            'environment' => (string) $row['environment'],
            'warnings' => self::warnings($row['warnings_json'] ?? null),
            'overdue' => (int) ($row['overdue'] ?? 0) === 1,
            'attempt_count' => (int) ($row['attempt_count'] ?? 0),
            'next_attempt_at' => EetValues::iso($row['next_attempt_at'] ?? null),
            'last_error' => isset($row['last_error_code']) || isset($row['last_error_message'])
                ? ['code' => $row['last_error_code'] !== null ? (int) $row['last_error_code'] : null, 'message' => $row['last_error_message']]
                : null,
        ];
    }

    /** @param array<string,mixed> $row celý řádek eet_records */
    public static function record(array $row): array
    {
        return self::summary($row) + [
            'kind' => (string) $row['kind'],
            'payment_event_id' => $row['payment_event_id'] !== null ? (int) $row['payment_event_id'] : null,
            'corrects_record_id' => $row['corrects_record_id'] !== null ? (int) $row['corrects_record_id'] : null,
            'correction_reason' => $row['correction_reason'],
            'eic' => (string) $row['eic'],
            // Pověření (eic_poverujiciho, povereni_vice_popl), jak odešlo ve zprávě.
            'delegating_eic' => isset($row['delegating_eic']) ? (string) $row['delegating_eic'] : null,
            'multiple_delegators' => (int) ($row['multiple_delegators'] ?? 0) === 1,
            // Tržba evidovaná externím systémem nemusí mít jednotku ani zařízení v MyÚčtu.
            'unit_id' => $row['unit_id'] !== null ? (int) $row['unit_id'] : null,
            'unit_number' => $row['unit_number'] !== null ? (int) $row['unit_number'] : null,
            'device_id' => $row['device_id'] !== null ? (int) $row['device_id'] : null,
            'device_code' => $row['device_code'] !== null ? (string) $row['device_code'] : null,
            'sequence_number' => $row['sequence_number'] !== null ? (string) $row['sequence_number'] : null,
            'external' => (string) $row['status'] === 'external',
            'sale_at' => (new \DateTimeImmutable((string) $row['sale_at'] . (string) $row['sale_at_offset']))->format(DATE_ATOM),
            'total_amount' => (string) $row['total_amount'],
            'credit_topup_amount' => isset($row['credit_topup_amount']) ? (string) $row['credit_topup_amount'] : null,
            'credit_draw_amount' => isset($row['credit_draw_amount']) ? (string) $row['credit_draw_amount'] : null,
            'currency' => 'CZK',
            'policy' => [
                'decision' => $row['policy_decision'],
                'reason_code' => $row['policy_reason'],
                'version' => $row['policy_version'],
            ],
            'deadline_at' => EetValues::iso((string) $row['deadline_at']),
            'confirmed_at' => EetValues::iso($row['confirmed_at'] ?? null),
            'created_at' => EetValues::iso($row['created_at'] ?? null),
        ];
    }

    /**
     * @param array<string,mixed> $event
     * @param array<string,mixed>|null $record
     * @param list<array<string,mixed>> $allocations
     */
    public static function event(array $event, ?array $record, array $allocations = []): array
    {
        return [
            'payment_event_id' => (string) $event['public_id'],
            'kind' => (string) $event['kind'],
            'origin' => (string) $event['origin'],
            // Částka a měna, jak platba proběhla; `amount_czk` jde do EET (u CZK totéž).
            'amount' => (string) ($event['original_amount'] ?? $event['amount']),
            'currency' => (string) ($event['original_currency'] ?? $event['currency']),
            'amount_czk' => (string) $event['amount'],
            'exchange_rate' => isset($event['exchange_rate']) ? EetCurrency::rate((float) $event['exchange_rate']) : null,
            'exchange_rate_source' => $event['exchange_rate_source'] ?? null,
            'payment_method' => $event['payment_method'],
            'channel' => $event['channel'],
            'contact_context' => (string) $event['contact_context'],
            'business_purpose' => (string) $event['business_purpose'],
            'invoice_id' => $event['invoice_id'] !== null ? (int) $event['invoice_id'] : null,
            'ordered_at' => EetValues::iso($event['ordered_at']),
            'received_at' => EetValues::iso($event['received_at']),
            'recorded_at' => EetValues::iso($event['recorded_at']),
            'paid_on' => $event['paid_on'],
            'payment_order_status' => (string) $event['payment_order_status'],
            'bank_verification_status' => (string) $event['bank_verification_status'],
            'accounting_settlement_status' => (string) $event['accounting_settlement_status'],
            'eet_decision' => [
                'decision' => (string) $event['eet_decision'],
                'reason_code' => $event['eet_reason_code'],
                'policy_version' => $event['policy_version'],
            ],
            'allocations' => array_map(static fn (array $a): array => [
                'invoice_id' => $a['invoice_id'] !== null ? (int) $a['invoice_id'] : null,
                'invoice_payment_id' => $a['invoice_payment_id'] !== null ? (int) $a['invoice_payment_id'] : null,
                'cash_document_id' => $a['cash_document_id'] !== null ? (int) $a['cash_document_id'] : null,
                'bank_transaction_id' => $a['bank_transaction_id'] !== null ? (int) $a['bank_transaction_id'] : null,
                'amount' => (string) $a['amount'],
            ], $allocations),
            'eet' => $record !== null ? self::summary($record) : null,
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function warnings(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }
}
