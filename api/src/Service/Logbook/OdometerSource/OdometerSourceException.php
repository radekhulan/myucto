<?php

declare(strict_types=1);

namespace MyInvoice\Service\Logbook\OdometerSource;

/**
 * Chyba zdroje stavu tachometru. `errorCode` je stabilní kód pro UI a stav napojení
 * a je společný všem zdrojům; kódy konkrétní služby (např. BMW `CU-105`) zdroj
 * převede na některý z těchto. Zpráva nikdy nenese tokeny ani odpověď vzdáleného API.
 */
final class OdometerSourceException extends \RuntimeException
{
    public const REMOTE_UNAVAILABLE    = 'remote_unavailable';
    public const INVALID_RESPONSE      = 'invalid_response';
    public const REMOTE_ERROR          = 'remote_error';
    public const RATE_LIMITED          = 'rate_limited';
    /** Hodnota z formuláře připojení (client ID, klíč, …) je neplatná. */
    public const INVALID_INPUT         = 'invalid_input';
    /** Uživatel přihlášení u služby odmítl. */
    public const ACCESS_DENIED         = 'access_denied';
    /** Rozpracované přihlášení vypršelo. */
    public const EXPIRED               = 'expired_token';
    /** Přístup propadl nebo byl zneplatněn — nutné nové přihlášení. */
    public const REAUTH_REQUIRED       = 'reauth_required';
    public const TOKEN_INVALID         = 'token_invalid';
    /** Auto k přístupu nepatří nebo k němu služba nedala oprávnění. */
    public const VEHICLE_NOT_PERMITTED = 'vehicle_not_permitted';
    /** Auto zatím stav tachometru neodeslalo. */
    public const NO_DATA               = 'no_odometer_data';
    /** Stav z auta nenavazuje na zapsané stavy a jízdy; přeskočí se. */
    public const REGRESSION            = 'odometer_regression';
    public const UNKNOWN_PROVIDER      = 'unknown_provider';

    /** Chyby jednoho auta; ostatní chyby se týkají celého účtu u zdroje a další auta účtu se nezkoušejí. */
    private const VEHICLE_SCOPED = [self::VEHICLE_NOT_PERMITTED, self::NO_DATA, self::REGRESSION];

    /**
     * Přechodné stavy auta, které se samy srovnají (auto ještě neodeslalo data, stav nenavazuje
     * na ručně zapsané jízdy): varování u auta, denní běh kvůli nim neskončí chybou. Odebrané
     * oprávnění k autu je trvalé a bez zásahu uživatele se nespraví, proto zůstává chybou auta.
     */
    private const VEHICLE_WARNINGS = [self::NO_DATA, self::REGRESSION];

    public function __construct(
        public readonly string $errorCode,
        string $message = 'Služba stav tachometru odmítla.',
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct($message);
    }

    public function concernsVehicleOnly(): bool
    {
        return in_array($this->errorCode, self::VEHICLE_SCOPED, true);
    }

    public function isVehicleWarning(): bool
    {
        return in_array($this->errorCode, self::VEHICLE_WARNINGS, true);
    }
}
