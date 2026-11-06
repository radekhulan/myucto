<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Ares;

use MyInvoice\Service\Ares\AresClient;
use PHPUnit\Framework\TestCase;

/**
 * Ulice z ARES (issue #252): místní část obce bez ulic nemá `nazevUlice`, adresa se
 * píše názvem části obce a číslem domu. Dřív zůstala ulice prázdná nebo jen číslo.
 */
final class AresNormalizeStreetTest extends TestCase
{
    /** @param array<string,mixed> $sidlo @return array<string,mixed> */
    private function normalize(array $sidlo): array
    {
        $m = new \ReflectionMethod(AresClient::class, 'normalize');
        $obj = (new \ReflectionClass(AresClient::class))->newInstanceWithoutConstructor();
        return $m->invoke($obj, ['ico' => '08701083', 'sidlo' => $sidlo]);
    }

    public function testStreetWithHouseAndOrientationNumber(): void
    {
        $n = $this->normalize(['nazevUlice' => 'Vinohradská', 'cisloDomovni' => 1234, 'cisloOrientacni' => 56, 'nazevObce' => 'Praha']);
        self::assertSame('Vinohradská 1234/56', $n['street']);
        self::assertSame('Praha', $n['city']);
    }

    public function testOrientationLetterIsKept(): void
    {
        $n = $this->normalize(['nazevUlice' => 'Masarykova', 'cisloDomovni' => 10, 'cisloOrientacni' => 3, 'cisloOrientacniPismeno' => 'a']);
        self::assertSame('Masarykova 10/3a', $n['street']);
        self::assertSame('3a', $n['street_number_orient']);
    }

    public function testDistrictWithoutStreetsUsesPartOfMunicipality(): void
    {
        $n = $this->normalize(['nazevCastiObce' => 'Lhotka', 'cisloDomovni' => 42, 'nazevObce' => 'Frýdek-Místek', 'psc' => 73801]);
        self::assertSame('Lhotka 42', $n['street']);
        self::assertSame('Frýdek-Místek', $n['city']);
        self::assertSame('738 01', $n['zip']);
    }

    public function testStreetNameWinsOverPartOfMunicipality(): void
    {
        $n = $this->normalize(['nazevUlice' => 'Hlavní', 'nazevCastiObce' => 'Lhotka', 'cisloDomovni' => 7]);
        self::assertSame('Hlavní 7', $n['street']);
    }
}
