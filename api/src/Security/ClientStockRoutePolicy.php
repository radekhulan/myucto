<?php

declare(strict_types=1);

namespace MyInvoice\Security;

/** Provozní sklad pro klienta: sdílené stock READ nikdy neotevírá účetní evidenci. */
final class ClientStockRoutePolicy
{
    public static function allows(string $method, string $path, string $permission): bool
    {
        if ($permission !== 'stock' && !str_starts_with($permission, 'stock.')) return true;
        $method = strtoupper($method);
        if ($method === 'HEAD') $method = 'GET';
        $patterns = match ($method) {
            'GET' => [
                '#^/api/stock/items(?:/search|/[0-9]+(?:/(?:packaging|customer-prices|tracking|tracking-choices|locations))?)?$#D',
                '#^/api/stock/(?:warehouses(?:/[0-9]+)?|locations(?:/[0-9]+/contents)?|availability|quantities)$#D',
                '#^/api/stock/fulfillment/tasks(?:/[0-9]+)?$#D',
            ],
            'POST' => [
                '#^/api/stock/items(?:/quote)?$#D',
                '#^/api/stock/fulfillment/tasks(?:/[0-9]+/(?:scan|shipments))?$#D',
                '#^/api/stock/fulfillment/shipments/[0-9]+/(?:dispatch|returns)$#D',
            ],
            'PUT' => ['#^/api/stock/items/[0-9]+(?:/(?:packaging|customer-prices))?$#D'],
            'DELETE' => ['#^/api/stock/items/[0-9]+$#D'],
            default => [],
        };
        return array_any($patterns, static fn (string $pattern): bool => preg_match($pattern, $path) === 1);
    }
}
