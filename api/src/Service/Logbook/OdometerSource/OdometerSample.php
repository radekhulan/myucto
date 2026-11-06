<?php

declare(strict_types=1);

namespace MyInvoice\Service\Logbook\OdometerSource;

/**
 * Stav tachometru, jak ho vrátila služba: hodnota v jednotce služby a okamžik (UTC),
 * kdy ho odeslalo auto. Převod na km a místní datum dělá jádro.
 */
final class OdometerSample
{
    public const UNIT_KM = 'km';
    public const UNIT_MI = 'mi';
    private const KM_PER_MILE = 1.609344;

    public readonly \DateTimeImmutable $at;

    public function __construct(
        public readonly int|float $value,
        public readonly string $unit,
        \DateTimeImmutable $at,
    ) {
        if (!in_array($unit, [self::UNIT_KM, self::UNIT_MI], true)) {
            throw new OdometerSourceException(OdometerSourceException::INVALID_RESPONSE, 'Neznámá jednotka stavu tachometru.');
        }
        if (!is_finite((float) $value) || $value < 0) {
            throw new OdometerSourceException(OdometerSourceException::INVALID_RESPONSE, 'Neplatný stav tachometru.');
        }
        $this->at = $at->setTimezone(new \DateTimeZone('UTC'));
    }

    /** Celé km; míle se převádějí zaokrouhlením. */
    public function km(): int
    {
        return (int) round($this->unit === self::UNIT_MI ? $this->value * self::KM_PER_MILE : $this->value);
    }
}
