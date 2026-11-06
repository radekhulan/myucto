<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Property;

use MyInvoice\Service\Property\PropertyException;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\PropertyRational;
use MyInvoice\Service\Property\PropertyTimeline;
use MyInvoice\Service\Property\PropertyUnitState;
use PHPUnit\Framework\TestCase;

final class PropertyTimelineTest extends TestCase
{
    public function testTwelveUnitsPreserveExactSharesAndSeparateRoles(): void
    {
        $units = array_map(PropertyUnitState::normalize(...), require dirname(__DIR__, 2) . '/Fixtures/property-house-at-limes.php');
        self::assertCount(12, $units);
        self::assertSame(['num' => '1', 'den' => '1'], PropertyRational::sum(array_map(static fn (array $state): array => $state['attributes']['share'], $units)));
        self::assertCount(1, $units['U01']['ownerships']);
        self::assertSame([1, 2], $units['U01']['ownerships'][0]['members']);
        self::assertSame($units['U03']['ownerships'][0]['members'], $units['U04']['ownerships'][0]['members']);
        self::assertSame([12], $units['U07']['ownerships'][0]['members']);
        self::assertSame(13, $units['U07']['roles'][0]['client_id']);
        self::assertSame([], $units['G12']['services']);
        self::assertSame([1], $units['U11']['services']);
    }

    public function testEffectiveAndKnownTimesDifferAndTwoTransfersKeepPastSnapshots(): void
    {
        $units = require dirname(__DIR__, 2) . '/Fixtures/property-house-at-limes.php';
        $a = PropertyUnitState::normalize($units['U05']);
        $b = $a;
        $b['ownerships'][0]['members'] = [8];
        $c = $a;
        $c['ownerships'][0]['members'] = [9];
        $history = [$this->revision(1, 'confirmed', '2026-01-01T00:00:00+01:00', '2026-01-01T09:00:00+01:00', $a),
            $this->revision(2, 'pending', '2026-05-01T10:15:30+02:00', '2026-05-02T09:00:00+02:00', $b),
            $this->revision(3, 'confirmed', '2026-05-01T10:15:30+02:00', '2026-05-20T09:00:00+02:00', $b) + ['resolves_revision_id' => 2],
            $this->revision(4, 'confirmed', '2026-09-01T00:00:00+02:00', '2026-09-20T09:00:00+02:00', $c)];
        $old = PropertyTimeline::at($history, '2026-05-10T12:00:00+02:00', '2026-05-10T12:00:00+02:00');
        self::assertSame([7], $old['revision']['state']['ownerships'][0]['members']);
        self::assertSame([2], $old['pending_revision_ids']);
        self::assertTrue($old['ownership_review_required']);
        $hash = PropertyInput::hash($old);
        $current = PropertyTimeline::at($history, '2026-05-10T12:00:00+02:00');
        self::assertSame([8], $current['revision']['state']['ownerships'][0]['members']);
        self::assertFalse($current['ownership_review_required']);
        self::assertSame([7], PropertyTimeline::at($history, '2026-05-01T10:15:29.999999+02:00')['revision']['state']['ownerships'][0]['members']);
        self::assertSame([8], PropertyTimeline::at($history, '2026-05-01T10:15:30+02:00')['revision']['state']['ownerships'][0]['members']);
        self::assertSame([9], PropertyTimeline::at($history, '2026-09-01T00:00:00+02:00')['revision']['state']['ownerships'][0]['members']);
        self::assertSame($hash, PropertyInput::hash(PropertyTimeline::at($history, '2026-05-10T12:00:00+02:00', '2026-05-10T12:00:00+02:00')));
        self::assertSame([7], $a['ownerships'][0]['members']);
    }

    public function testRepresentativeCannotInjectDebtorOrRefundRecipientFlag(): void
    {
        $units = require dirname(__DIR__, 2) . '/Fixtures/property-house-at-limes.php';
        $state = $units['U01'];
        $state['roles'][] = ['client_id' => 20, 'role' => 'representative', 'scopes' => ['communication'], 'title_ref' => 'Synthetic mandate', 'is_debtor' => true];
        $this->expectException(PropertyException::class);
        PropertyUnitState::normalize($state);
    }

    public function testCorrectionOfLimitedIntervalDoesNotChangeLaterTransfer(): void
    {
        $units = require dirname(__DIR__, 2) . '/Fixtures/property-house-at-limes.php';
        $a = PropertyUnitState::normalize($units['U05']);
        $b = $a;
        $b['attributes']['persons'] = ['num' => '2', 'den' => '1'];
        $history = [$this->revision(1, 'confirmed', '2026-01-01T00:00:00Z', '2026-01-01T09:00:00Z', $a),
            $this->revision(2, 'confirmed', '2026-08-01T00:00:00Z', '2026-10-01T09:00:00Z', $b)];
        $history[1]['effective_to'] = '2026-09-01T00:00:00Z';
        self::assertSame($b, PropertyTimeline::at($history, '2026-08-15T00:00:00Z')['revision']['state']);
        self::assertSame($a, PropertyTimeline::at($history, '2026-09-01T00:00:00Z')['revision']['state']);
    }

    private function revision(int $id, string $status, string $effective, string $recorded, array $state): array
    {
        return ['id' => $id, 'revision' => $id, 'status' => $status, 'effective_from' => $effective, 'effective_to' => null, 'recorded_at' => $recorded, 'state' => $state];
    }
}
