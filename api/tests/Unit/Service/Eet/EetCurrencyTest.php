<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Eet;

use MyInvoice\Service\Eet\EetCurrency;
use PHPUnit\Framework\TestCase;

/** Částka EET v CZK a stopa přepočtu cizoměnové platby. */
final class EetCurrencyTest extends TestCase
{
    /** 180,98 × 24,25 = 4 388,765 → 4 388,77 (round() nad floatem by dal 4 388,76). */
    public function testConversionRoundsHalfUpInCents(): void
    {
        $fx = EetCurrency::converted('eur', '180.98', 24.25, 'cnb:2026-11-02');
        self::assertSame(['4388.77', 'CZK', 'EUR', '180.98', '24.25', 'cnb:2026-11-02'], array_values($fx));
    }

    /** Valutový pokladní doklad: EET bere CZK částku dokladu, jak se zaúčtuje, nepřepočítává ji. */
    public function testDocumentKeepsItsPostedCzkAmount(): void
    {
        $fx = EetCurrency::fromDocument('EUR', '2434.99', '100.00', 24.35, 'cash_document:2026-11-02');
        self::assertSame(['2434.99', 'CZK', 'EUR', '100.00', '24.35', 'cash_document:2026-11-02'], array_values($fx));
        self::assertSame([null, null, null, null], array_slice(array_values(EetCurrency::fromDocument('CZK', '100', null, 1.0, 'x')), 2));
    }
}
