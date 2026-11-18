<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Bank;

use MyInvoice\Service\Bank\EmailNotice\ForeignCurrencyNoticeException;
use MyInvoice\Service\Bank\EmailNotice\NoticeAccountAmount;
use MyInvoice\Service\Bank\EmailNotice\ParsedBankEmailNotice;
use PHPUnit\Framework\TestCase;

/** Částka avíza v měně účtu (#390). */
final class NoticeAccountAmountTest extends TestCase
{
    public function testForeignAmountWithoutAccountAmountIsRefused(): void
    {
        $this->expectException(ForeignCurrencyNoticeException::class);
        NoticeAccountAmount::resolve($this->notice(-13.07, 'USD'), 'CZK');
    }

    public function testAccountCurrencyAmountIsKeptWithOriginal(): void
    {
        $resolved = NoticeAccountAmount::resolve($this->notice(-291.98, 'CZK', 13.07, 'USD'), 'CZK');

        self::assertSame(-291.98, $resolved->amount);
        self::assertSame('CZK', $resolved->currency);
        self::assertSame(-13.07, $resolved->originalAmount);
        self::assertSame('USD', $resolved->originalCurrency);
    }

    public function testSwappedTemplateFieldsAreNormalized(): void
    {
        $resolved = NoticeAccountAmount::resolve($this->notice(-18.00, 'EUR', 455.10, 'CZK'), 'CZK');

        self::assertSame(-455.10, $resolved->amount);
        self::assertSame('CZK', $resolved->currency);
        self::assertSame(-18.00, $resolved->originalAmount);
        self::assertSame('EUR', $resolved->originalCurrency);
    }

    public function testDefaultedCurrencyTakesAccountCurrency(): void
    {
        $notice = new ParsedBankEmailNotice('', 100.0, 'CZK', '2099-01-02', '1000000005/2010', currencyStated: false);

        $resolved = NoticeAccountAmount::resolve($notice, 'EUR');

        self::assertSame(100.0, $resolved->amount);
        self::assertSame('EUR', $resolved->currency);
        self::assertNull($resolved->originalAmount);
    }

    public function testWithoutAccountCurrencyNoticeCurrencyIsUsed(): void
    {
        $resolved = NoticeAccountAmount::resolve($this->notice(-18.00, 'EUR'), null);

        self::assertSame(-18.00, $resolved->amount);
        self::assertSame('EUR', $resolved->currency);
    }

    public function testOriginalInSameCurrencyIsDropped(): void
    {
        $resolved = NoticeAccountAmount::resolve($this->notice(-50.0, 'CZK', 50.0, 'CZK'), 'CZK');

        self::assertNull($resolved->originalAmount);
        self::assertNull($resolved->originalCurrency);
    }

    private function notice(float $amount, string $currency, ?float $originalAmount = null, ?string $originalCurrency = null): ParsedBankEmailNotice
    {
        return new ParsedBankEmailNotice(
            variableSymbol: '',
            amount: $amount,
            currency: $currency,
            postedAt: '2099-01-02',
            recipientAccount: '1000000005/0800',
            originalAmount: $originalAmount,
            originalCurrency: $originalCurrency,
        );
    }
}
