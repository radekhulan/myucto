<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Nonprofit\Membership;

use MyInvoice\Service\Nonprofit\Membership\MembershipCatalogCursor;
use MyInvoice\Service\Nonprofit\Membership\MembershipException;
use PHPUnit\Framework\TestCase;

final class MembershipCatalogCursorTest extends TestCase
{
    public function testCursorBindsTenantScopeAndTemporalFilters(): void
    {
        $filters = ['as_of' => '2026-06-30', 'known_at' => '2026-10-19T10:00:00.123456Z'];
        $token = MembershipCatalogCursor::encode('roles', 41, $filters, 90);

        self::assertSame(90, MembershipCatalogCursor::decode($token, 'roles', 41, $filters));
        foreach ([['receivables', 41, $filters], ['roles', 42, $filters], ['roles', 41, $filters + ['member_id' => 7]]]
            as [$scope, $supplier, $changed]) {
            try {
                MembershipCatalogCursor::decode($token, $scope, $supplier, $changed);
                self::fail('Cursor accepted a different tenant, scope or filter set.');
            } catch (MembershipException $error) {
                self::assertSame('nonprofit_membership_catalog_cursor_invalid', $error->errorCode);
                self::assertSame(422, $error->httpStatus);
            }
        }
    }

    public function testMalformedAndNoncanonicalCursorsFailClosed(): void
    {
        $filters = ['member_id' => 7];
        foreach (['', '***', rtrim(strtr(base64_encode('{"v":1}'), '+/', '-_'), '=')] as $token) {
            try {
                MembershipCatalogCursor::decode($token, 'receivables', 1, $filters);
                self::fail('Malformed cursor was accepted.');
            } catch (MembershipException $error) {
                self::assertSame('nonprofit_membership_catalog_cursor_invalid', $error->errorCode);
            }
        }
    }
}
