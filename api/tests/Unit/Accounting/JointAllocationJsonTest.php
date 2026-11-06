<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting;

use MyInvoice\Service\Accounting\Allocation\JointAllocationKeyVersion;
use PHPUnit\Framework\TestCase;

final class JointAllocationJsonTest extends TestCase
{
    public function testStoredPayloadRetainsNumericTypesAndCanonicalHash(): void
    {
        $payload = ['source_lines' => [['id' => 12, 'amount' => '20123.45', 'dimension_splits' => [7 => [8 => 1.0]],
            'zero' => 0.0, 'fraction' => 0.25]], 'explanation' => 'Syntetický podklad'];
        $decoded = json_decode(JointAllocationKeyVersion::encodeJson($payload), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($payload, $decoded);
        self::assertSame(JointAllocationKeyVersion::hash($payload), JointAllocationKeyVersion::hash($decoded));
        self::assertSame(JointAllocationKeyVersion::hash($payload), JointAllocationKeyVersion::hash(array_reverse($decoded, true)));
        $decoded['source_lines'][0]['dimension_splits'][7][8] = 1;
        self::assertNotSame(JointAllocationKeyVersion::hash($payload), JointAllocationKeyVersion::hash($decoded));
    }
}
