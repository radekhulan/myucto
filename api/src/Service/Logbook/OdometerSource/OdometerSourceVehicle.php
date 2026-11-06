<?php

declare(strict_types=1);

namespace MyInvoice\Service\Logbook\OdometerSource;

/**
 * Auto dostupné pod přístupem ke zdroji. `ref` je identifikátor auta u služby
 * (u automobilek obvykle VIN); `vin` slouží ke spárování s kartou auta.
 */
final class OdometerSourceVehicle
{
    /** Šířka sloupce odometer_source_cars.vehicle_ref. */
    public const MAX_REF_LENGTH = 64;

    public readonly ?string $label;

    public function __construct(
        public readonly string $ref,
        public readonly ?string $vin = null,
        ?string $label = null,
        public readonly bool $primary = false,
    ) {
        if ($ref === '' || strlen($ref) > self::MAX_REF_LENGTH || preg_match('/[\x00-\x1f\x7f]/', $ref)) {
            throw new OdometerSourceException(OdometerSourceException::INVALID_RESPONSE, 'Služba vrátila neplatný identifikátor auta.');
        }
        $label = $label !== null ? trim($label) : null;
        $this->label = $label === null || $label === '' ? null : mb_substr($label, 0, 100);
    }

    /** @return array{ref:string, vin:?string, label:?string, primary:bool} */
    public function toArray(): array
    {
        return ['ref' => $this->ref, 'vin' => $this->vin, 'label' => $this->label, 'primary' => $this->primary];
    }
}
