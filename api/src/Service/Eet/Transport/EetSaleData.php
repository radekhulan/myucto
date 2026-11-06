<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet\Transport;

/**
 * Údaje e-tržby (element `Data` datové zprávy v4). Částky jsou v haléřích, aby se
 * do zprávy nikdy nedostal zaokrouhlený float. Formální kontroly dělá
 * {@see EetMessageBuilder}; tahle třída je jen nosič.
 *
 * Při opakovaném zaslání téže tržby se posílají PŘESNĚ tytéž údaje (stejné
 * pořadové číslo i čas tržby) — správce daně podle nich pozná, že jde o tutéž
 * tržbu (kap. 4 popisu rozhraní).
 */
final readonly class EetSaleData
{
    public function __construct(
        public string $eic,
        public int $unitId,
        public string $deviceCode,
        public string $sequenceNumber,
        public \DateTimeImmutable $saleAt,
        public int $totalCents,
        public ?int $creditTopUpCents = null,
        public ?int $creditDrawCents = null,
        public ?string $delegatingEic = null,
        public ?bool $multipleDelegators = null,
    ) {}
}
