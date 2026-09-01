<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Qr;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Qr\QrPaymentGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class QrPaymentGeneratorTest extends TestCase
{
    private QrPaymentGenerator $gen;

    protected function setUp(): void
    {
        if (!\function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD není dostupné.');
        }
        $this->gen = new QrPaymentGenerator(new Config([]), new NullLogger());
    }

    public function testCzkFromAccountAndBankCode(): void
    {
        $uri = $this->gen->generate('CZK', 1000.0, '12345', [
            'account_number' => '2000145399',
            'bank_code'      => '0800',
        ]);
        self::assertNotNull($uri);
        self::assertStringStartsWith('data:image/png;base64,', $uri);
    }

    public function testCzkFromIbanOnly(): void
    {
        // Nová cesta: přijatá faktura má jen IBAN dodavatele (žádný account/bank).
        $uri = $this->gen->generate('CZK', 1000.0, '12345', [
            'iban' => 'CZ6508000000192000145399',
        ]);
        self::assertNotNull($uri);
        self::assertStringStartsWith('data:image/png;base64,', $uri);
    }

    public function testEurUsesSepa(): void
    {
        $uri = $this->gen->generate('EUR', 1234.50, '12345', [
            'iban' => 'CZ6508000000192000145399',
            'bic'  => 'GIBACZPX',
        ], 'Dodavatel s.r.o.');
        self::assertNotNull($uri);
        self::assertStringStartsWith('data:image/png;base64,', $uri);
    }

    public function testCustomDueDateAndMessageDoNotBreak(): void
    {
        $uri = $this->gen->generate(
            'CZK',
            500.0,
            '777',
            ['account_number' => '2000145399', 'bank_code' => '0800'],
            'Dodavatel',
            new \DateTimeImmutable('2026-06-30'),
            'Faktura 2026-0042',
            includeDueDate: true,
        );
        self::assertNotNull($uri);
    }

    public function testCzkWithDashedVarsymbolStillGenerates(): void
    {
        // Regrese #58: varsymbol s pomlčkou (číslo dokladu „2026-00001") dřív SPAYD
        // knihovna odmítla (nečíselný VS) → QR se nevykreslil. Po normalizaci projde.
        $uri = $this->gen->generate('CZK', 1000.0, '2026-00001', [
            'account_number' => '2000145399',
            'bank_code'      => '0800',
        ]);
        self::assertNotNull($uri);
        self::assertStringStartsWith('data:image/png;base64,', $uri);
    }

    public function testCzkWithNonNumericVarsymbolDoesNotCrash(): void
    {
        // Zcela nečíselný VS → QR bez VS pole (lepší než žádný QR / fatální chyba).
        $uri = $this->gen->generate('CZK', 1000.0, 'ABC-XYZ', [
            'account_number' => '2000145399',
            'bank_code'      => '0800',
        ]);
        self::assertNotNull($uri);
    }

    public function testReturnsNullForZeroAmount(): void
    {
        self::assertNull($this->gen->generate('CZK', 0.0, '12345', [
            'account_number' => '2000145399', 'bank_code' => '0800',
        ]));
    }

    public function testReturnsNullForMissingBank(): void
    {
        self::assertNull($this->gen->generate('CZK', 1000.0, '12345', []));
    }
}
