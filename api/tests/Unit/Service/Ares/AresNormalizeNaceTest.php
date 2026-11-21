<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Ares;

use MyInvoice\Service\Ares\AresClient;
use PHPUnit\Framework\TestCase;

/**
 * Regrese: jediná NACE jako numerický string („620") se v PHP poli stával int
 * klíčem a primaryNace vracela int → TypeError → pád celého normalize a selhání
 * ARES lookupu (časté u OSVČ). cz_nace_code musí být vždy string.
 */
final class AresNormalizeNaceTest extends TestCase
{
    /** @param array<string,mixed> $raw @return array<string,mixed> */
    private function normalize(array $raw): array
    {
        $m = new \ReflectionMethod(AresClient::class, 'normalize');
        $obj = (new \ReflectionClass(AresClient::class))->newInstanceWithoutConstructor();
        return $m->invoke($obj, $raw);
    }

    public function testSingleNumericNaceReturnsString(): void
    {
        $n = $this->normalize(['czNace' => ['620'], 'pravniForma' => '101']);
        self::assertIsString($n['cz_nace_code']);
        self::assertSame('620', $n['cz_nace_code']);
        self::assertSame('fo', $n['taxpayer_type']);
    }

    public function testPlaceholderSkippedLeavesSingle(): void
    {
        $n = $this->normalize(['czNace' => ['00', '620']]);
        self::assertSame('620', $n['cz_nace_code']);
    }

    public function testMultipleNaceIsAmbiguousEmpty(): void
    {
        $n = $this->normalize(['czNace' => ['62', '73110', '00', '46']]);
        self::assertSame('', $n['cz_nace_code']);
    }

    public function testMissingNaceEmpty(): void
    {
        $n = $this->normalize(['pravniForma' => '112']);
        self::assertSame('', $n['cz_nace_code']);
        self::assertSame('po', $n['taxpayer_type']);
    }

    /**
     * Zahraniční fyzická osoba (424) a její odštěpný závod (425) jsou fyzické osoby:
     * dřív je ARES zařadil jako právnickou osobu a firma vznikla v podvojném účetnictví.
     */
    public function testForeignNaturalPersonIsFo(): void
    {
        self::assertSame('fo', $this->normalize(['pravniForma' => '424'])['taxpayer_type']);
        self::assertSame('fo', $this->normalize(['pravniForma' => '425'])['taxpayer_type']);
        self::assertSame('po', $this->normalize(['pravniForma' => '421'])['taxpayer_type']);
    }
}
