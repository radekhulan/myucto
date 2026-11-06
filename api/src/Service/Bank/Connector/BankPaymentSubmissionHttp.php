<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

/**
 * HTTP stavy odeslání příkazu do banky, společné pro platební příkazy i mzdové dávky.
 * Úspěšné převzetí bankou je 202, jisté odmítnutí 422, nejasný výsledek 502;
 * chyba vstupu nebo stavu nikdy nekončí 500.
 */
final class BankPaymentSubmissionHttp
{
    public static function forStatus(string $status): int
    {
        return match ($status) {
            'accepted_awaiting_authorization', 'import_started', 'awaiting_signature', 'signed', 'completed' => 202,
            'rejected', 'partially_rejected', 'expired' => 422,
            default => 502,
        };
    }

    public static function forError(string $errorCode): int
    {
        return match ($errorCode) {
            'payment_order_not_found', 'connection_not_found' => 404,
            'bank_connection_busy', 'bank_rate_limited', 'payment_signature_unavailable', 'payment_order_changed',
            'payment_signature_expired', 'payment_order_no_longer_payable' => 409,
            'submission_failed', 'payment_signature_failed', 'payment_status_unavailable' => 502,
            default => 422,
        };
    }
}
