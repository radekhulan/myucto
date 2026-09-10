<?php

declare(strict_types=1);

namespace MyInvoice\Service\Http;

/**
 * Odchozí HTTP požadavek byl guardem odmítnut, nebo selhal na síti.
 * Zpráva je bezpečná pro zobrazení uživateli (neobsahuje credentials).
 */
final class OutboundRequestException extends \RuntimeException
{
    public const DNS_UNAVAILABLE = 'dns_unavailable';
    public const TARGET_BLOCKED = 'target_blocked';
    public const SIZE_LIMIT = 'size_limit';

    public function __construct(
        string $message,
        public readonly string $reason = 'request_failed',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
