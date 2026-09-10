<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Card;

use MyInvoice\Service\Bank\Card\CardPaymentVehicleHints;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Rozpoznání platby kartou na čerpací stanici podle obchodníka. */
final class CardPaymentVehicleHintsTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function merchants(): iterable
    {
        yield 'síť stanic' => ['SHELL 1234 PRAHA', true];
        yield 'síť s maskovanou kartou' => ['PK: 000000******4242 ORLEN STANICE 55', true];
        yield 'palivo v popisu' => ['Nafta motorová', true];
        yield 'obecný obchod' => ['TESTOVACI PAPIRNICTVI', false];
        yield 'supermarket není super benzín' => ['SUPERMARKET TESTOVACI', false];
        yield 'mytí u stanice' => ['OMV mytí vozu', false];
        yield 'zkratka uvnitř slova' => ['MOLITANOVE MATRACE', false];
    }

    #[DataProvider('merchants')]
    public function testFuelStationMerchant(string $text, bool $expected): void
    {
        self::assertSame($expected, CardPaymentVehicleHints::looksLikeFuelStation($text));
    }
}
