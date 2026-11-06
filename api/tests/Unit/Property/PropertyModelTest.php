<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Property;

use MyInvoice\Service\Property\PropertyException;
use MyInvoice\Service\Property\PropertyInstant;
use MyInvoice\Service\Property\PropertyOwnership;
use MyInvoice\Service\Property\PropertyRational;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PropertyModelTest extends TestCase
{
    public function testRationalArithmeticPreservesExactLargeValuesAndThirds(): void
    {
        self::assertSame(['num' => '1', 'den' => '2'], PropertyRational::normalize(['num' => '9007199254740993', 'den' => '18014398509481986']));
        self::assertSame(['num' => '1', 'den' => '1'], PropertyRational::sum(array_fill(0, 3, ['num' => '1', 'den' => '3'])));
        self::assertSame(['num' => '0', 'den' => '1'], PropertyRational::normalize(['num' => '0', 'den' => '1000']));
    }

    #[DataProvider('invalidFractions')]
    public function testInvalidFractionsAreRefused(array $value): void
    {
        $this->expectException(PropertyException::class);
        PropertyRational::normalize($value);
    }

    public static function invalidFractions(): iterable
    {
        yield [['num' => '1', 'den' => '0']];
        yield [['num' => '-1', 'den' => '2']];
        yield [['num' => 0.5, 'den' => 1]];
        yield [['num' => '1e3', 'den' => '2']];
        yield [['num' => '1', 'den' => '2', 'approximate' => true]];
    }

    public function testInstantRequiresExplicitOffsetAndKeepsSubdayBoundary(): void
    {
        self::assertSame('2026-05-01T08:15:30.123456Z', PropertyInstant::normalize('2026-05-01T10:15:30.123456+02:00'));
        self::assertSame('2026-05-01T08:15:30.000000Z', PropertyInstant::normalize('2026-05-01T08:15:30Z'));
        self::assertSame(['2026-05-01T08:15:30.000000Z', null], PropertyInstant::interval('2026-05-01T10:15:30+02:00', null));
    }

    #[DataProvider('invalidInstants')]
    public function testUnknownOrImpossibleInstantIsRefused(mixed $instant): void
    {
        $this->expectException(PropertyException::class);
        PropertyInstant::normalize($instant);
    }

    public static function invalidInstants(): iterable
    {
        foreach (['2026-05-01', '2026-05-01T10:00:00', '2026-02-30T10:00:00Z', '2026-01-01T24:00:00Z', '2026-01-01T10:00:00+14:30',
            '1000-01-01T00:00:00+14:00', '9999-12-31T23:59:59-14:00', null] as $value) yield [$value];
    }

    public function testIntervalCannotBeEmptyOrReversed(): void
    {
        $this->expectException(PropertyException::class);
        PropertyInstant::interval('2026-05-01T10:00:00+02:00', '2026-05-01T08:00:00Z');
    }

    public function testSjmIsOneParticipationWithTwoMembersAndNoInventedLiability(): void
    {
        $out = PropertyOwnership::normalize([['group_key' => 'SJM', 'kind' => 'marital_community', 'share' => ['num' => '1', 'den' => '1'], 'members' => [12, 11], 'title_ref' => 'Synthetic REG-2026']]);
        self::assertCount(1, $out);
        self::assertSame([11, 12], $out[0]['members']);
        self::assertSame(['num' => '1', 'den' => '1'], $out[0]['share']);
        self::assertArrayNotHasKey('debtors', $out[0]);
        self::assertArrayNotHasKey('liability_mode', $out[0]);
    }

    public function testCoownershipSumUsesParticipationsAndRejectsIncompleteWhole(): void
    {
        $groups = [];
        foreach (['1/2', '3/10', '1/5'] as $i => $fraction) {
            [$num, $den] = explode('/', $fraction);
            $groups[] = ['group_key' => 'P' . $i, 'kind' => 'coownership_share', 'share' => compact('num', 'den'), 'members' => [$i + 1], 'title_ref' => 'Synthetic REG-2026'];
        }
        self::assertCount(3, PropertyOwnership::normalize($groups));
        array_pop($groups);
        $this->expectException(PropertyException::class);
        PropertyOwnership::normalize($groups);
    }

    public function testDuplicatedMemberCannotPretendToBeSjm(): void
    {
        $this->expectException(PropertyException::class);
        PropertyOwnership::normalize([['group_key' => 'SJM', 'kind' => 'marital_community', 'share' => ['num' => '1', 'den' => '1'], 'members' => [11, 11], 'title_ref' => 'Synthetic REG-2026']]);
    }
}
