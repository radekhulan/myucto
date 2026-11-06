<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

/**
 * Klient dostane vždy jen obecné „Ověření passkey selhalo." — `reason` a `detail`
 * jsou pro auditní log, aby šlo zpětně zjistit, proč konkrétní správce klíčů
 * neprošel (nesedí origin, chybí ověření uživatele, credential není evidovaná…).
 */
class PasskeyVerificationException extends \RuntimeException
{
    public const REASON_UNKNOWN = 'unknown';
    public const REASON_MALFORMED = 'malformed';
    public const REASON_CROSS_ORIGIN = 'cross_origin';
    public const REASON_UNKNOWN_CREDENTIAL = 'unknown_credential';
    public const REASON_CREDENTIAL_MISMATCH = 'credential_mismatch';
    public const REASON_USER_HANDLE_MISMATCH = 'user_handle_mismatch';
    public const REASON_ASSERTION_INVALID = 'assertion_invalid';

    public function __construct(
        string $message = 'Ověření passkey selhalo.',
        public readonly string $reason = self::REASON_UNKNOWN,
        public readonly ?string $detail = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @return array{reason:string,detail?:string}
     */
    public function logPayload(): array
    {
        $payload = ['reason' => $this->reason];
        if ($this->detail !== null && $this->detail !== '') {
            $payload['detail'] = mb_substr($this->detail, 0, 200);
        }
        return $payload;
    }
}
