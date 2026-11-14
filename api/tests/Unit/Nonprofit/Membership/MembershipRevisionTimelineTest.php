<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Nonprofit\Membership;

use MyInvoice\Service\Nonprofit\Membership\MembershipException;
use MyInvoice\Service\Nonprofit\Membership\MembershipRevisionTimeline;
use PHPUnit\Framework\TestCase;

/** Zrušení revize je článek řetězu; platný stav se počítá jen z platných revizí (krok 7 #321). */
final class MembershipRevisionTimelineTest extends TestCase
{
    public function testVoidedRevisionAndVoidingLinkAreIgnoredAndDateCanBeReused(): void
    {
        $history = [
            self::row(1, 1, '2026-01-01', 'active'),
            self::row(2, 2, '2026-07-01', 'suspended'),
            self::row(3, 3, '2026-07-01', 'suspended', voids: 2),
            self::row(4, 4, '2026-07-01', 'ended'),
        ];

        self::assertSame([1, 4], array_column(MembershipRevisionTimeline::effective($history), 'id'));
        self::assertSame('ended', MembershipRevisionTimeline::at($history, '2026-08-01')['status']);
        self::assertSame([2 => 3], MembershipRevisionTimeline::voided($history));
        self::assertSame('2026-07-01', MembershipRevisionTimeline::intervals($history)[0]['effective_to']);
    }

    public function testRepeatedVoidsPopValidRevisionsInOrder(): void
    {
        $history = [
            self::row(1, 1, '2026-01-01', 'active'),
            self::row(2, 2, '2026-07-01', 'suspended'),
            self::row(3, 3, '2026-07-01', 'suspended', voids: 2),
            self::row(4, 4, '2026-01-01', 'active', voids: 1),
        ];

        self::assertSame([], MembershipRevisionTimeline::effective($history));
        self::assertNull(MembershipRevisionTimeline::at($history, '2026-08-01'));
    }

    /** @return iterable<string, array{array<int,array<string,mixed>>}> */
    public static function corruptVoids(): iterable
    {
        yield 'zruší ne-poslední' => [[self::row(1, 1, '2026-01-01', 'active'), self::row(2, 2, '2026-07-01', 'suspended'), self::row(3, 3, '2026-01-01', 'active', voids: 1)]];
        yield 'zruší rušící článek' => [[self::row(1, 1, '2026-01-01', 'active'), self::row(2, 2, '2026-01-01', 'active', voids: 1), self::row(3, 3, '2026-01-01', 'active', voids: 2)]];
        yield 'jiné datum' => [[self::row(1, 1, '2026-01-01', 'active'), self::row(2, 2, '2026-02-01', 'active', voids: 1)]];
        yield 'jiný stav' => [[self::row(1, 1, '2026-01-01', 'active'), self::row(2, 2, '2026-01-01', 'ended', voids: 1)]];
        yield 'zrušení bez revize' => [[self::row(1, 1, '2026-01-01', 'active', voids: 9)]];
        yield 'odkaz mimo vrchol se shodným datem a stavem' => [[self::row(1, 1, '2026-01-01', 'active'), self::row(2, 2, '2026-01-01', 'active', voids: 9)]];
    }

    /** @param array<int,array<string,mixed>> $history */
    #[\PHPUnit\Framework\Attributes\DataProvider('corruptVoids')]
    public function testInconsistentVoidingLinkMakesHistoryUnproven(array $history): void
    {
        try {
            MembershipRevisionTimeline::effective($history);
            self::fail('Nekonzistentní rušící článek musí historii zneplatnit.');
        } catch (MembershipException $e) {
            self::assertSame('nonprofit_membership_revision_corrupt', $e->errorCode);
        }
    }

    public function testTwoValidRevisionsOnTheSameDayRemainAConflict(): void
    {
        try {
            MembershipRevisionTimeline::intervals([self::row(1, 1, '2026-01-01', 'active'), self::row(2, 2, '2026-01-01', 'ended')]);
            self::fail('Dvě platné revize ke stejnému dni jsou konflikt.');
        } catch (MembershipException $e) {
            self::assertSame('nonprofit_membership_effective_conflict', $e->errorCode);
        }
    }

    public function testVoidingLinkIsSealedInRecordHashWhileLegacyRevisionHashIsUnchanged(): void
    {
        $base = ['supplier_id' => 1, 'member_id' => 2, 'revision_no' => 2, 'previous_id' => 1, 'effective_from' => '2026-01-01', 'status' => 'active',
            'legal_basis' => [], 'request' => [], 'idempotency_key' => 'k', 'request_hash' => 'a', 'parent_hash' => 'b', 'created_by' => 3];
        $legacy = \MyInvoice\Repository\Nonprofit\Membership\MembershipRepository::revisionRecord($base);
        self::assertArrayNotHasKey('voids_revision_id', $legacy);
        self::assertSame($legacy, \MyInvoice\Repository\Nonprofit\Membership\MembershipRepository::revisionRecord($base + ['voids_revision_id' => null]));
        self::assertSame(1, \MyInvoice\Repository\Nonprofit\Membership\MembershipRepository::revisionRecord($base + ['voids_revision_id' => 1])['voids_revision_id']);
    }

    private static function row(int $id, int $no, string $from, string $status, ?int $voids = null): array
    {
        return ['id' => $id, 'revision_no' => $no, 'effective_from' => $from, 'status' => $status, 'voids_revision_id' => $voids];
    }
}
