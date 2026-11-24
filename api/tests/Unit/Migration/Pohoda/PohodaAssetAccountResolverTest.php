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

    #[DataProvider('analytics')]
    public function testResolvesAnalyticAccount(string $synthetic, array $opening, array $journalAccounts, ?string $expected): void
    {
        self::assertSame($expected, PohodaAssetAccountResolver::resolveAnalytic($synthetic, $opening, $journalAccounts));
    }

    public static function analytics(): iterable
    {
        yield 'jednoznačné oprávky z počátečních stavů' => ['082', ['082.007' => 1200.0, '082.008' => 0.0, '081.001' => 100.0], [], '082.007'];
        yield 'několik analytik oprávek se zůstatkem' => ['081', ['081.001' => 100.0, '081.002' => 200.0], [], null];
        yield 'syntetika i analytika mají počáteční zůstatek' => ['081', ['081' => 100.0, '081.001' => 200.0], [], null];
        yield 'oprávky s počátečním zůstatkem na MD' => ['081', ['081.001' => -200.0], [], null];
        yield 'zápisy přiřazené ke kartě mají přednost' => ['081', ['081.001' => 100.0, '081.002' => 200.0], ['081.002', '081.002'], '081.002'];
        yield 'rozpor mezi analytikami v zápisech karty' => ['081', ['081.001' => 100.0], ['081.001', '081.002'], null];
        yield 'zápis odpisů na syntetice brání změně' => ['089', ['089.001' => 100.0], ['089'], null];
        yield 'chybějící účet v nativním zápisu brání změně' => ['082', ['082.001' => 100.0], [''], null];
        yield 'majetek zařazený na analytice' => ['022', [], ['022.007'], '022.007'];
        yield 'zařazení na syntetice brání změně' => ['022', ['022.007' => 3000.0], ['022'], null];
    }
}
