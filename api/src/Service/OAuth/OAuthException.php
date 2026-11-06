<?php

declare(strict_types=1);

namespace MyInvoice\Service\OAuth;

/**
 * Chyba OAuth klienta se stabilním kódem. Kódy chyb autorizačního serveru odpovídají
 * RFC 6749 a RFC 8628; poskytovatel si je převede na kódy své domény.
 */
final class OAuthException extends \RuntimeException
{
    /** Neznámý, vypršelý, cizí nebo už použitý state. */
    public const INVALID_STATE         = 'oauth_state_invalid';
    public const AUTHORIZATION_PENDING = 'authorization_pending';
    public const SLOW_DOWN             = 'slow_down';
    public const ACCESS_DENIED         = 'access_denied';
    public const EXPIRED_TOKEN         = 'expired_token';
    public const INVALID_GRANT         = 'invalid_grant';
    public const INVALID_CLIENT        = 'invalid_client';
    public const INVALID_REQUEST       = 'invalid_request';
    public const RATE_LIMITED          = 'rate_limited';
    public const REMOTE_ERROR          = 'remote_error';
    public const REMOTE_UNAVAILABLE    = 'remote_unavailable';
    public const INVALID_RESPONSE      = 'invalid_response';
    public const ROTATION_FAILED       = 'credential_rotation_failed';
    public const CONNECTION_BUSY       = 'connection_busy';
    public const ENCRYPTION_KEY        = 'encryption_key_unavailable';
    public const APP_URL_INVALID       = 'app_url_invalid';

    /** Chybové kódy autorizačního serveru, které se předávají beze změny. */
    public const SERVER_ERRORS = [
        self::AUTHORIZATION_PENDING, self::SLOW_DOWN, self::ACCESS_DENIED, self::EXPIRED_TOKEN,
        self::INVALID_GRANT, self::INVALID_CLIENT, self::INVALID_REQUEST,
    ];

    public function __construct(
        public readonly string $errorCode,
        string $message = '',
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct($message !== '' ? $message : $errorCode);
    }
}
