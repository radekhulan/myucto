<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\TenantTransfer;

use MyInvoice\Service\TenantTransfer\Registry\TenantSecretColumnDetector;
use PHPUnit\Framework\TestCase;

final class TenantSecretColumnDetectorTest extends TestCase
{
    public function testPseudonymizationSaltIsTreatedAsSensitive(): void
    {
        self::assertTrue(
            TenantSecretColumnDetector::matches('ai_pseudo_salt'),
        );
    }

    public function testOrdinaryBusinessColumnsStayOutsideHeuristic(): void
    {
        self::assertFalse(TenantSecretColumnDetector::matches('tax_rate'));
        self::assertFalse(TenantSecretColumnDetector::matches('supplier_id'));
    }
}
