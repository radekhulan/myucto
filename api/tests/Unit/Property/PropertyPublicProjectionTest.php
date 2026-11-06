<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Property;

use MyInvoice\Service\Property\PropertyPublicProjection;
use PHPUnit\Framework\TestCase;

final class PropertyPublicProjectionTest extends TestCase
{
    public function testUnitRetainsExactAccountingAttributesWithoutLegalPartiesOrPaymentInstructions(): void
    {
        $input = ['id' => 7, 'kind' => 'unit', 'code' => 'UNIT', 'parent_id' => 3, 'dimension_value_id' => 17,
            'history' => [['reason' => 'Private history']], 'parties' => [['company_name' => 'Private person']],
            'revision' => ['id' => 11, 'revision' => 2, 'status' => 'confirmed', 'effective_from' => '2026-06-01T00:00:00.000001Z',
                'effective_to' => null, 'recorded_at' => '2026-06-02 12:00:00.000002', 'reason' => 'Private legal reason',
                'state' => ['unit_no' => 'U1', 'kind' => 'apartment', 'active' => true,
                    'attributes' => ['share' => ['num' => '9007199254740993', 'den' => '9007199254740994']], 'services' => [19],
                    'ownerships' => [['members' => [42]]], 'roles' => [['title_ref' => 'Private mandate']],
                    'identifiers' => [['value' => 'Private payment instruction']]]]];
        $original = $input;
        $projected = PropertyPublicProjection::object($input);
        self::assertSame($original, $input);
        self::assertSame($input['revision']['state']['attributes'], $projected['revision']['state']['attributes']);
        self::assertSame('2026-06-01T00:00:00.000001Z', $projected['revision']['effective_from']);
        self::assertSame(2, $projected['revision']['revision']);
        self::assertStringNotContainsString('Private', json_encode($projected, JSON_THROW_ON_ERROR));
        self::assertSame(['unit_no', 'kind', 'active', 'attributes', 'services'], array_keys($projected['revision']['state']));
    }

    public function testProfileAndUnconfirmedObjectDoNotExposeRequestOrHistory(): void
    {
        self::assertNull(PropertyPublicProjection::profile(null));
        self::assertSame(['revision_no' => 2, 'enabled' => false], PropertyPublicProjection::profile([
            'revision_no' => 2, 'enabled' => false, 'reason' => 'Private', 'request_hash' => 'Private', 'created_by' => 42,
        ]));
        self::assertSame(['id' => 7, 'kind' => 'service', 'revision' => null, 'pending_revisions' => 0, 'review_required' => true], PropertyPublicProjection::object([
            'id' => 7, 'kind' => 'service', 'revision' => null, 'history' => [['reason' => 'Private']],
        ]));
    }
}
