<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Currency;

use DateTimeImmutable;
use MyInvoice\Service\Currency\CnbExchangeRateClient;
use MyInvoice\Service\Currency\CurrencyConversionService;
use PHPUnit\Framework\TestCase;

final class CurrencyConversionServiceTest extends TestCase
{
    public function testConvertsCzkToEur(): void
    {
        $service = $this->serviceWithRates(['EUR' => 25.0]);

        $result = $service->convert(2500.0, 'CZK', 'EUR', new DateTimeImmutable('2026-06-15'));

        self::assertNotNull($result);
        self::assertSame(100.0, $result['amount']);
        self::assertSame(0.04, $result['cross_rate']);
        self::assertSame('2026-06-13', $result['rate_date']);
        self::assertTrue($result['fallback_used']);
    }

    public function testConvertsEurToCzk(): void
    {
        $service = $this->serviceWithRates(['EUR' => 25.0]);

        $result = $service->convert(100.0, 'EUR', 'CZK', new DateTimeImmutable('2026-06-15'));

        self::assertNotNull($result);
        self::assertSame(2500.0, $result['amount']);
        self::assertSame(25.0, $result['cross_rate']);
    }

    public function testConvertsCrossCurrencyThroughCzk(): void
    {
        $service = $this->serviceWithRates(['EUR' => 25.0, 'USD' => 20.0]);

        $result = $service->convert(100.0, 'EUR', 'USD', new DateTimeImmutable('2026-06-15'));

        self::assertNotNull($result);
        self::assertSame(125.0, $result['amount']);
        self::assertSame(1.25, $result['cross_rate']);
    }

    public function testUnitPriceKeepsSixDecimalsWhileAmountsStayOnCents(): void
    {
        $service = $this->serviceWithRates(['EUR' => 25.0]);
        $date = new DateTimeImmutable('2026-06-15');

        self::assertSame(0.1, $service->convertUnitPrice(0.004, 'EUR', 'CZK', $date)['amount']);
        self::assertSame(0.00016, $service->convertUnitPrice(0.004, 'CZK', 'EUR', $date)['amount']);
        self::assertSame(0.004, $service->convertUnitPrice(0.004, 'EUR', 'EUR', $date)['amount']);
        self::assertSame(0.0, $service->convert(0.004, 'CZK', 'EUR', $date)['amount'], 'Částky a součty se dál převádějí na haléře.');
    }

    public function testReturnsNullWhenCommonRateIsUnavailable(): void
    {
        $cnb = $this->createStub(CnbExchangeRateClient::class);
        $cnb->method('getRatesForCommonDate')->willReturn(null);

        self::assertNull((new CurrencyConversionService($cnb))->convert(
            100.0,
            'EUR',
            'USD',
            new DateTimeImmutable('2026-06-15'),
        ));
    }

    /** @param array<string,float> $rates */
    private function serviceWithRates(array $rates): CurrencyConversionService
    {
        $cnb = $this->createStub(CnbExchangeRateClient::class);
        $cnb->method('getRatesForCommonDate')->willReturn([
            'rates' => $rates,
            'rate_date' => '2026-06-13',
            'fallback_used' => true,
            'source' => 'cache',
        ]);
        return new CurrencyConversionService($cnb);
    }
}
