<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Logbook;

use MyInvoice\Service\Logbook\VehicleVatPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VehicleVatPolicyTest extends TestCase
{
    public function testDefaultsToBusinessWithFullDeduction(): void
    {
        self::assertSame(
            ['usage_mode' => 'business', 'vat_deduction_mode' => 'full', 'vat_deduction_percent' => 100.0],
            VehicleVatPolicy::normalize(null, null, null),
        );
    }

    public function testPrivateVehicleHasNoDeduction(): void
    {
        self::assertSame(
            ['usage_mode' => 'private', 'vat_deduction_mode' => 'none', 'vat_deduction_percent' => 0.0],
            VehicleVatPolicy::normalize('private', null, 55),
        );
    }

    public function testMixedUseTakesProportionalShare(): void
    {
        self::assertSame(
            ['usage_mode' => 'mixed', 'vat_deduction_mode' => 'proportional', 'vat_deduction_percent' => 62.5],
            VehicleVatPolicy::normalize('mixed', null, '62.5'),
        );
    }

    /** @return iterable<string, array{0:?string, 1:?string, 2:mixed}> */
    public static function invalid(): iterable
    {
        yield 'soukromé s plným odpočtem' => ['private', 'full', 100];
        yield 'smíšené s plným odpočtem' => ['mixed', 'full', 100];
        yield 'poměrný bez podílu' => ['mixed', 'proportional', null];
        yield 'poměrný 100 %' => ['business', 'proportional', 100];
        yield 'neznámý režim' => ['leasing', null, null];
        yield 'krácený § 76 u vozidla' => ['business', 'reduced', 80];
    }

    #[DataProvider('invalid')]
    public function testInvalidCombinationsAreRejected(?string $usage, ?string $deduction, mixed $percent): void
    {
        $this->expectException(\InvalidArgumentException::class);
        VehicleVatPolicy::normalize($usage, $deduction, $percent);
    }

    public function testDocumentMismatchDirection(): void
    {
        self::assertSame('over', VehicleVatPolicy::documentMismatch('proportional', 60.0, 'full', 100.0));
        self::assertSame('under', VehicleVatPolicy::documentMismatch('full', 100.0, 'none', 0.0));
        self::assertNull(VehicleVatPolicy::documentMismatch('proportional', 60.0, 'proportional', 60.0));
        self::assertNull(VehicleVatPolicy::documentMismatch('full', 100.0, 'reduced', 70.0));
    }
}
