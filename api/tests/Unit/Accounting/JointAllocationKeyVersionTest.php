<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting;

use MyInvoice\Service\Accounting\Allocation\JointAllocationException;
use MyInvoice\Service\Accounting\Allocation\JointAllocationKeyVersion;
use PHPUnit\Framework\TestCase;

final class JointAllocationKeyVersionTest extends TestCase
{
    public function testEffectiveIntervalIsHalfOpen(): void
    {
        $version = JointAllocationKeyVersion::normalize(self::input());
        JointAllocationKeyVersion::assertDate($version, '2026-01-01');
        JointAllocationKeyVersion::assertDate($version, '2026-12-31');
        $this->expectException(JointAllocationException::class);
        JointAllocationKeyVersion::assertDate($version, '2027-01-01');
    }

    public function testInvalidIntervalIsRejected(): void
    {
        $input = self::input();
        $input['valid_to'] = $input['valid_from'];
        $this->expectException(JointAllocationException::class);
        JointAllocationKeyVersion::normalize($input);
    }

    public function testOverlapIsRejectedWhileAdjacentVersionIsAllowed(): void
    {
        $version = JointAllocationKeyVersion::normalize(self::input());
        $next = $version;
        $next['valid_from'] = '2027-01-01';
        $next['valid_to'] = '2028-01-01';
        JointAllocationKeyVersion::assertNoOverlap($next, [$version]);
        $next['valid_from'] = '2026-12-31';
        $this->expectException(JointAllocationException::class);
        JointAllocationKeyVersion::assertNoOverlap($next, [$version]);
    }

    public function testCanonicalHashAndMeaningfulChanges(): void
    {
        $input = self::input();
        $first = JointAllocationKeyVersion::normalize($input);
        $input['cells'] = array_reverse($input['cells']);
        self::assertSame($first, JointAllocationKeyVersion::normalize($input));
        $input['explanation'] = 'Jiný doložený podklad.';
        self::assertNotSame($first['content_hash'], JointAllocationKeyVersion::normalize($input)['content_hash']);
    }

    public function testInvalidCalendarDateIsRejected(): void
    {
        $this->expectException(JointAllocationException::class);
        JointAllocationKeyVersion::date('2026-02-29');
    }

    private static function input(): array
    {
        return [
            'valid_from' => '2026-01-01', 'valid_to' => '2027-01-01', 'basis' => 'hours',
            'explanation' => 'Syntetický výkaz hodin za kalendářní rok.',
            'cells' => [
                ['activity' => 1, 'activity_kind' => 11, 'tax_regime' => 21, 'weight' => '60'],
                ['activity' => 2, 'activity_kind' => 12, 'tax_regime' => 21, 'weight' => '40'],
            ],
        ];
    }
}
