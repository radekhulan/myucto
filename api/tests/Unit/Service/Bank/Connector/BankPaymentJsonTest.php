<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Connector;

use MyInvoice\Service\Bank\Connector\BankPaymentAmount;
use MyInvoice\Service\Bank\Connector\BankPaymentJson;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Částka platby v JSON pro bankovní API (#393). Zápis vychází z haléřů, takže nezávisí na
 * `serialize_precision`: při hodnotě 17 by float 1234.56 skončil jako 1234.5599999999999
 * a Česká spořitelna platbu odmítla (WRONG_DECIMAL_PLACES).
 */
final class BankPaymentJsonTest extends TestCase
{
    private string|false $precision = false;

    protected function setUp(): void
    {
        $this->precision = ini_get('serialize_precision');
        ini_set('serialize_precision', '17');
    }

    protected function tearDown(): void
    {
        ini_set('serialize_precision', (string) $this->precision);
    }

    #[DataProvider('amounts')]
    public function testAmountIsWrittenAsNumberWithAtMostTwoDecimals(int $minor, string $plain, string $zeroFraction): void
    {
        $body = ['amount' => ['instructedAmount' => ['value' => BankPaymentAmount::fromMinor($minor), 'currency' => 'CZK']]];

        self::assertSame('{"amount":{"instructedAmount":{"value":' . $plain . ',"currency":"CZK"}}}', BankPaymentJson::encode($body));
        self::assertSame('{"amount":{"instructedAmount":{"value":' . $zeroFraction . ',"currency":"CZK"}}}',
            BankPaymentJson::encode($body, JSON_PRESERVE_ZERO_FRACTION));
    }

    public static function amounts(): iterable
    {
        yield 'haléře' => [123456, '1234.56', '1234.56'];
        yield 'z hlášení #393' => [235, '2.35', '2.35'];
        yield 'desetiny' => [250, '2.5', '2.5'];
        yield 'jeden haléř' => [1, '0.01', '0.01'];
        yield 'celé koruny' => [100000, '1000', '1000.0'];
        yield 'velká částka' => [999999999999, '9999999999.99', '9999999999.99'];
        yield 'záporná' => [-1005, '-10.05', '-10.05'];
    }

    public function testFloatInPaymentBodyIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        BankPaymentJson::encode(['amount' => ['instructedAmount' => ['value' => 1234.56]]]);
    }

    public function testAmountCannotLeakThroughPlainJsonEncode(): void
    {
        $this->expectException(\LogicException::class);
        json_encode(['value' => BankPaymentAmount::fromMinor(100)]);
    }

    public function testTextResemblingPlaceholderStaysText(): void
    {
        $body = ['message' => 'mu-amount-0000000000000000-0', 'list' => [BankPaymentAmount::fromMinor(1999), BankPaymentAmount::fromMinor(5)]];
        self::assertSame('{"message":"mu-amount-0000000000000000-0","list":[19.99,0.05]}', BankPaymentJson::encode($body));
    }
}
