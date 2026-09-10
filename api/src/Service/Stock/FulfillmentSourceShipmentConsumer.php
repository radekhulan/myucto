<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

interface FulfillmentSourceShipmentConsumer
{
    /** @param list<array{source_line_id:string,quantity:string}> $lineQuantities */
    public function consumeForShipment(
        int $supplierId,
        string $sourceId,
        int $shipmentId,
        array $lineQuantities,
    ): void;
}
