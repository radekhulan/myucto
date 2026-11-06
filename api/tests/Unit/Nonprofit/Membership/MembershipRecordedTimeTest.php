<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Nonprofit\Membership;

use MyInvoice\Repository\Nonprofit\Membership\MembershipChargeRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipRepository;
use MyInvoice\Service\Nonprofit\Membership\MembershipException;
use MyInvoice\Service\Nonprofit\Membership\MembershipRecordedTime;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use PHPUnit\Framework\TestCase;

final class MembershipRecordedTimeTest extends TestCase
{
    public function testExactUtcProjectionDoesNotApplyPragueDstTwice(): void
    {
        self::assertSame(['recorded_at' => '2026-03-29T00:30:00.123456Z', 'recorded_time_status' => 'proven_utc'],
            MembershipRecordedTime::projection('2026-03-29 00:30:00.123456'));
        self::assertSame(['recorded_at' => '2026-10-25T01:30:00.654321Z', 'recorded_time_status' => 'proven_utc'],
            MembershipRecordedTime::projection('2026-10-25 01:30:00.654321'));
    }

    public function testLegacyNullIsExplicitlyUnprovenAndCannotDriveKnownAt(): void
    {
        self::assertSame(['recorded_at' => null, 'recorded_time_status' => 'unproven'], MembershipRecordedTime::projection(null));
        try {
            MembershipRecordedTime::requiredInstant(null);
            self::fail('Legacy local timestamp must not be accepted as UTC.');
        } catch (MembershipException $error) {
            self::assertSame('nonprofit_membership_recorded_time_unproven', $error->errorCode);
            self::assertSame(409, $error->httpStatus);
        }
    }

    public function testRevisionAndClaimRecordsKeepLegacyHashShapeAndBindProvenUtc(): void
    {
        $revision = ['supplier_id' => 1, 'member_id' => 2, 'revision_no' => 1, 'previous_id' => null,
            'effective_from' => '2026-01-01', 'status' => 'active', 'legal_basis' => ['relationship' => 'nonreciprocal_membership'],
            'request' => ['member_id' => 2], 'idempotency_key' => 'synthetic-revision', 'request_hash' => str_repeat('1', 64),
            'parent_hash' => str_repeat('0', 64), 'created_by' => 3, 'recorded_at_utc' => null];
        $legacyRevision = MembershipRepository::revisionRecord($revision);
        self::assertArrayNotHasKey('recorded_at_utc', $legacyRevision);
        $revision['recorded_at_utc'] = '2026-10-25 01:30:00.654321';
        self::assertNotSame(NonprofitTaxRules::hash($legacyRevision), NonprofitTaxRules::hash(MembershipRepository::revisionRecord($revision)));

        $claim = ['supplier_id' => 1, 'case_id' => 2, 'operation_id' => 3, 'original_claim_id' => null,
            'member_id' => 4, 'client_id' => 5, 'kind' => 'issue', 'amount_cents' => 600, 'recorded_at_utc' => null];
        $legacyClaim = MembershipChargeRepository::claimRecord($claim);
        self::assertArrayNotHasKey('recorded_at_utc', $legacyClaim);
        $claim['recorded_at_utc'] = '2026-03-29 00:30:00.123456';
        self::assertNotSame(NonprofitTaxRules::hash($legacyClaim), NonprofitTaxRules::hash(MembershipChargeRepository::claimRecord($claim)));
    }
}
