<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Property\Payments;

use MyInvoice\Service\Property\Payments\UnitPaymentResolver;
use PHPUnit\Framework\TestCase;

/** Signály identifikátorů registru vůči bankovnímu pohybu. */
final class UnitPaymentResolverTest extends TestCase
{
    public function testVariableSymbolMatchesWithoutLeadingZeros(): void
    {
        self::assertSame('vs', self::signal(['kind' => 'vs', 'value' => '0105'], vs: '105'));
        self::assertNull(self::signal(['kind' => 'vs', 'value' => '105'], vs: '1050'));
    }

    public function testVariableSymbolInMessageIsOnlyWeakTextSignalAndNeedsThreeDigits(): void
    {
        self::assertSame('text', self::signal(['kind' => 'vs', 'value' => '105'], message: 'zaloha byt 105 unor'));
        self::assertNull(self::signal(['kind' => 'vs', 'value' => '5'], message: 'zaloha byt 5'));
    }

    public function testSpecificSymbolAndSipoLink(): void
    {
        self::assertSame('ss', self::signal(['kind' => 'ss', 'value' => '777'], ss: '777'));
        self::assertNull(self::signal(['kind' => 'ss', 'value' => '777'], vs: '777'));
        self::assertSame('sipo_link', self::signal(['kind' => 'sipo_link', 'value' => '1234567890'], message: 'sipo 1234567890'));
        self::assertSame('sipo_link', self::signal(['kind' => 'sipo_link', 'value' => '1234567890'], vs: '1234567890'));
    }

    public function testUnitAccountComparesCanonicalCzechAccounts(): void
    {
        $account = UnitPaymentResolver::accountKey('0000191000000005', '0100');
        self::assertSame('19-1000000005/0100', $account);
        self::assertSame('account', self::signal(['kind' => 'account', 'value' => '19-1000000005/0100'], account: $account));
        self::assertNull(self::signal(['kind' => 'account', 'value' => '19-1000000005/0300'], account: $account));
        self::assertNull(UnitPaymentResolver::accountKey('1000000005', ''));
    }

    public function testTextNeedsThreeCharactersAndIgnoresCase(): void
    {
        self::assertSame('text', self::signal(['kind' => 'text', 'value' => 'Byt A2'], message: 'platba byt a2 leden'));
        self::assertNull(self::signal(['kind' => 'text', 'value' => 'A2'], message: 'platba a2'));
    }

    public function testPaymentInstantIsPragueNoon(): void
    {
        self::assertSame('2026-02-01T11:00:00.000000Z', UnitPaymentResolver::paymentInstant('2026-02-01'));
        self::assertSame('2026-07-01T10:00:00.000000Z', UnitPaymentResolver::paymentInstant('2026-07-01 00:00:00'));
    }

    private static function signal(array $identifier, string $vs = '', string $ss = '', string $message = '', ?string $account = null): ?string
    {
        return UnitPaymentResolver::identifierSignal($identifier, $vs, $ss, UnitPaymentResolver::digitTokens($message), $message, $account);
    }
}
