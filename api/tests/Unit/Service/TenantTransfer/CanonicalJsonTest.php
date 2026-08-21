<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\TenantTransfer;

use MyInvoice\Service\TenantTransfer\Fingerprint\CanonicalJson;
use PHPUnit\Framework\TestCase;

final class CanonicalJsonTest extends TestCase
{
    public function testObjectKeysAreSortedRecursivelyAndListOrderIsPreserved(): void
    {
        $encoded = CanonicalJson::encode([
            'z' => ['b' => 2, 'a' => 1],
            'a' => [['z' => true, 'a' => false], 'second'],
        ]);

        self::assertSame('{"a":[{"a":false,"z":true},"second"],"z":{"a":1,"b":2}}', $encoded);
    }

    public function testFloatingPointMetadataIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CanonicalJson::sha256(['limit' => 1.5]);
    }
}
