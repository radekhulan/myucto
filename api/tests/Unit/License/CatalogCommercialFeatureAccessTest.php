<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\License;

use MyInvoice\Service\License\CommercialFeatureAccess;
use PHPUnit\Framework\TestCase;

final class CatalogCommercialFeatureAccessTest extends TestCase
{
    public function testCatalogSharesTheStockLicenseBoundary(): void
    {
        foreach (['/api/catalog/products/batch', '/api/catalog/prices/batch', '/api/catalog/facets', '/api/catalog/exports', '/api/catalog/exports/1/download'] as $path) {
            self::assertTrue(CommercialFeatureAccess::restrictsApiPath($path));
        }
        self::assertFalse(CommercialFeatureAccess::restrictsApiPath('/api/catalogue'));
    }
}
