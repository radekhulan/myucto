<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Service\Payroll\PayrollChildReference;
use PHPUnit\Framework\TestCase;

final class PayrollChildReferenceTest extends TestCase
{
    public function testSortsByNumericDependantIdAcrossDigitBoundary(): void
    {
        $sorted = PayrollChildReference::sortByDependantId(
            ['dependant-100' => 'b', 'child-a' => 'x', 'dependant-99' => 'a', 'dependant-1000' => 'c'],
        );

        // Odkaz jiného tvaru (převzatá data, testovací odkazy) jde až za děti s id.
        self::assertSame(['dependant-99', 'dependant-100', 'dependant-1000', 'child-a'], array_keys($sorted));
    }

    public function testReferencesWithoutIdFallBackToText(): void
    {
        self::assertSame(['child-a', 'child-b'], array_keys(PayrollChildReference::sortByDependantId(['child-b' => 2, 'child-a' => 1])));
    }

    /** Issue #420: pořadí nároku rozhoduje před id, id při shodném pořadí číselně. */
    public function testSortsByClaimOrderThenNumericId(): void
    {
        $sorted = PayrollChildReference::sortByClaimOrder(
            ['dependant-7' => 2, 'dependant-100' => 1, 'dependant-99' => 1, 'child-a' => 0, 'dependant-8' => 3],
            static fn (int $order): int => $order,
        );

        // Neznámé pořadí (0) jde až za známá.
        self::assertSame(['dependant-99', 'dependant-100', 'dependant-7', 'dependant-8', 'child-a'], array_keys($sorted));
        self::assertLessThan(0, PayrollChildReference::compareByClaimOrder(1, 'dependant-9', 2, 'dependant-1'));
    }

    public function testReadsIdOnlyFromValidReference(): void
    {
        self::assertSame(42, PayrollChildReference::dependantId('dependant-42'));
        self::assertNull(PayrollChildReference::dependantId('dependant-0'));
        self::assertNull(PayrollChildReference::dependantId('dependant-42x'));
        self::assertNull(PayrollChildReference::dependantId('child-a'));
    }
}
