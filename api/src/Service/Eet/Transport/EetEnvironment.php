<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet\Transport;

/**
 * Cílové prostředí společného technického zařízení správce daně (EET 2.0, rozhraní v4).
 *
 * Adresy jsou z dokumentů „Přístupové a provozní informace“ pro playground a produkci.
 * Hlavní verze rozhraní je součástí URL; vedlejší verze (4.1, 4.2 …) URL nemění.
 */
enum EetEnvironment: string
{
    case Playground = 'playground';
    case Production = 'production';

    public function endpoint(): string
    {
        return match ($this) {
            self::Playground => 'https://pg.trzbyeet.gov.cz/eet/services/EETServiceSOAP/v4',
            self::Production => 'https://trzbyeet.gov.cz/eet/services/EETServiceSOAP/v4',
        };
    }

    /** Neprodukční prostředí vrací POK s koncovkou „-ff“ a příznak test="true". */
    public function isTest(): bool
    {
        return $this === self::Playground;
    }
}
