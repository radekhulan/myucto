<?php

declare(strict_types=1);

namespace MyInvoice\Service\Logbook;

/**
 * Bod rozšíření: vozidlo podle platební karty, kterou se tankovalo
 * (karta → držitel → jeho výchozí vozidlo).
 *
 * Kniha jízd karty nezná; implementaci dodá evidence platebních karet. Dokud žádná
 * není zaregistrovaná v kontejneru, {@see VehicleResolver} tenhle krok přeskočí.
 * Implementace musí vracet jen vozidlo téže firmy ($supplierId) — resolver to
 * přesto ověřuje ještě jednou.
 */
interface CardHolderVehicleResolver
{
    /**
     * @param string $cardLast4 poslední čtyři číslice karty (nikdy celé číslo)
     * @param string $date      datum transakce (Y-m-d) — držitel i vozidlo se v čase mění
     * @return int|null cars.id, nebo null když kartu/držitele/vozidlo nezná
     */
    public function vehicleForCard(int $supplierId, string $cardLast4, string $date): ?int;
}
