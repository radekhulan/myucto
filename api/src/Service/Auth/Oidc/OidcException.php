<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth\Oidc;

/**
 * Selhání přihlášení u poskytovatele identity. `code` je strojový kód z pevného
 * seznamu, jen ten jde do URL a do UI; text poskytovatele se nikam nepropisuje.
 */
final class OidcException extends \RuntimeException
{
    public const PROVIDER_DISABLED = 'sso_disabled';
    public const FLOW_INVALID = 'sso_flow_invalid';
    public const PROVIDER_ERROR = 'sso_provider_error';
    public const PROVIDER_UNAVAILABLE = 'sso_provider_unavailable';
    public const TOKEN_INVALID = 'sso_token_invalid';
    public const NOT_LINKED = 'sso_not_linked';
    public const ALREADY_LINKED = 'sso_already_linked';
    public const INVITE_INVALID = 'sso_invite_invalid';
    public const USER_INACTIVE = 'sso_user_inactive';
    public const IDENTITY_MISMATCH = 'sso_identity_mismatch';
    public const CANCELLED = 'sso_cancelled';
    /** Účet Google není z domény Google Workspace povolené v `allowed_hosted_domains`. */
    public const DOMAIN_NOT_ALLOWED = 'sso_domain_not_allowed';

    public const CODES = [
        self::PROVIDER_DISABLED, self::FLOW_INVALID, self::PROVIDER_ERROR, self::PROVIDER_UNAVAILABLE,
        self::TOKEN_INVALID, self::NOT_LINKED, self::ALREADY_LINKED, self::INVITE_INVALID,
        self::USER_INACTIVE, self::IDENTITY_MISMATCH, self::CANCELLED, self::DOMAIN_NOT_ALLOWED,
    ];

    /** Účel a návratová adresa spotřebovaného přihlášení, je-li už známé. */
    public ?string $purpose = null;
    public ?string $returnTo = null;

    public function __construct(public readonly string $errorCode, string $message = '')
    {
        if (!in_array($errorCode, self::CODES, true)) {
            throw new \InvalidArgumentException('Neznámý kód chyby přihlášení.');
        }
        parent::__construct($message !== '' ? $message : $errorCode);
    }
}
