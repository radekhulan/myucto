<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\PohodaAssetAccountResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PohodaAssetAccountResolverTest extends TestCase
{
    #[DataProvider('accounts')]
    public function testResolvesAccount(array $opening, float $price, ?string $accumulated, ?string $expected): void
    {
        self::assertSame($expected, PohodaAssetAccountResolver::resolve($opening, $price, $accumulated));
    }

    public static function accounts(): iterable
    {
        yield 'přesná shoda má přednost před zaokrouhlenou' => [['021000' => 500.0, '022000' => 500.95], 500.0, '082000', '021000'];
        yield 'vstupní cena zaokrouhlená na koruny' => [['032000' => 1000.83], 1000.0, null, '032000'];
        yield 'shodu částek rozliší analytika' => [['022001' => 500.0, '022004' => 500.0], 500.0, '082004', '022004'];
        yield 'shoda částky i analytiky zůstává nejednoznačná' => [['021000' => 500.0, '022000' => 500.0], 500.0, '081000', null];
        yield 'účet více budov odvozený z oprávek' => [['021000' => 1500.0, '022000' => 2400.0], 750.0, '081000', '021000'];
        yield 'účet více strojů se stejnou analytikou' => [['022007' => 2500.0, '022000' => 5000.0], 750.0, '082007', '022007'];
        yield 'nehmotný majetek' => [['013003' => 1500.0], 500.0, '073003', '013003'];
        yield 'odvozený účet pokryje cenu s tolerancí 1 Kč' => [['021000' => 749.0], 750.0, '081000', '021000'];
        yield 'odvozený účet nemá dostatečný počáteční stav' => [['021000' => 748.99], 750.0, '081000', null];
        yield 'jiná analytika nestačí' => [['022000' => 2500.0], 750.0, '082007', null];
        yield 'bez účtu oprávek' => [['021000' => 2500.0], 750.0, null, null];
    }
}
