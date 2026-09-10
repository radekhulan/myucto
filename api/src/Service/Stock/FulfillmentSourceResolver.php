<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

final class FulfillmentSourceResolver
{
    public function __construct(
        private readonly StockIssueDraftFulfillmentSource $stockIssueDraft,
        private readonly SalesOrderFulfillmentSourceProvider $salesOrders,
    ) {}

    public function resolve(string $type): FulfillmentSourceProvider
    {
        if ($type === $this->stockIssueDraft->type()) {
            return $this->stockIssueDraft;
        }
        if ($type === 'sales_order') {
            return $this->salesOrders;
        }
        throw new StockException('fulfillment_source_invalid', 'Neznámý typ zdroje vychystání.', 422);
    }

    /** @param list<array{source_line_id:string,quantity:string}> $lineQuantities */
    public function consumeShipment(
        int $supplierId,
        string $type,
        string $sourceId,
        int $shipmentId,
        array $lineQuantities,
    ): void {
        $provider = $this->resolve($type);
        if ($provider instanceof FulfillmentSourceShipmentConsumer) {
            $provider->consumeForShipment($supplierId, $sourceId, $shipmentId, $lineQuantities);
        }
    }
}
