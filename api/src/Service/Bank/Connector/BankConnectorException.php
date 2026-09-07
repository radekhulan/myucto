<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

final class BankConnectorException extends \RuntimeException
{
    public const INVALID_TOKEN = 'invalid_token';
    public const INVALID_DATE = 'invalid_date';
    public const INVALID_DATE_RANGE = 'invalid_date_range';
    public const INVALID_PAYMENT_ORDER = 'invalid_payment_order';
    public const RATE_LIMITED = 'rate_limited';
    public const HISTORY_LOCKED = 'history_locked';
    public const STATEMENT_TOO_LARGE = 'statement_too_large';
    public const REMOTE_UNAVAILABLE = 'remote_unavailable';
    public const REMOTE_HTTP_ERROR = 'remote_http_error';
    public const RESPONSE_TOO_LARGE = 'response_too_large';
    public const INVALID_RESPONSE = 'invalid_response';
    public const PAYMENT_REJECTED = 'payment_rejected';

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly bool $ambiguousPaymentOutcome = false,
        public readonly ?int $remoteHttpStatus = null,
        public readonly ?int $acceptedCount = null,
        public readonly ?int $rejectedCount = null,
    ) {
        parent::__construct($message);
    }
}
