<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Property\Catalog;

use MyInvoice\Service\Property\Catalog\PropertyLegalRoleCursor;
use MyInvoice\Service\Property\PropertyException;
use PHPUnit\Framework\TestCase;

final class PropertyLegalRoleCursorTest extends TestCase
{
    public function testCursorBindsTenantAndBothTemporalAxes(): void
    {
        $filters = ['as_of' => '2026-06-01T10:00:00.000000Z', 'known_at' => '2026-10-19T10:00:00.000000Z'];
        $token = PropertyLegalRoleCursor::encode(7, $filters, 41);
        self::assertSame(41, PropertyLegalRoleCursor::decode($token, 7, $filters));
        foreach ([[8, $filters], [7, $filters + ['unit_id' => 2]]] as [$supplier, $changed]) {
            try {
                PropertyLegalRoleCursor::decode($token, $supplier, $changed);
                self::fail('Cursor accepted a different tenant or filter set.');
            } catch (PropertyException $error) {
                self::assertSame('property_legal_role_cursor_invalid', $error->errorCode);
            }
        }
    }
}
