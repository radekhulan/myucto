<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Card;

use MyInvoice\Service\Bank\Card\CardNumberMask;
use MyInvoice\Service\Bank\CreditasTransactionParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Koncovka karty z maskovaného čísla a sanitizace vstupu. Všechna čísla jsou
 * syntetická (maskovaná nebo veřejné testovací číslo 4111 1111 1111 1111).
 */
final class CardNumberMaskTest extends TestCase
{
    /** @return iterable<string, array{0:string, 1:?string}> */
    public static function maskedTexts(): iterable
    {
        yield 'GPC 078 avízo' => ['ACME OBCHOD PRAHA CZ PK: 000000******1234', '1234'];
        yield 'BIN + X maska' => ['PK: 516872XXXXXX7989', '7989'];
        yield 'hvězdičky po skupinách' => ['Karta **** **** **** 4321', '4321'];
        yield 'X s pomlčkami' => ['XXXX-XXXX-XXXX-5678 platba', '5678'];
        yield 'Creditas buňka' => ['123456******7890 Testshop a.s.', '7890'];
        yield 'bez masky' => ['Platba VS 12341234', null];
        yield 'X uvnitř slova' => ['TAXXXX1234', null];
        yield 'málo maskovacích znaků' => ['karta **1234', null];
    }

    #[DataProvider('maskedTexts')]
    public function testLast4FromText(string $text, ?string $expected): void
    {
        self::assertSame($expected, CardNumberMask::last4FromText($text));
    }

    public function testTwoDifferentCardsInOneMovementAreAmbiguous(): void
    {
        self::assertNull(CardNumberMask::last4FromText('PK: 000000******1111', 'PK: 000000******2222'));
        self::assertSame('1111', CardNumberMask::last4FromText('PK: 000000******1111', 'znovu 000000******1111'));
    }

    public function testStripMaskedLeavesMerchantName(): void
    {
        self::assertSame('SHELL TEST PRAHA', CardNumberMask::stripMasked('SHELL TEST PRAHA PK: 000000******1234'));
        self::assertSame('Testshop a.s.', CardNumberMask::stripMasked('123456******7890 Testshop a.s.'));
    }

    public function testNormalizeLast4KeepsOnlyTheEnding(): void
    {
        // Celé číslo vložené do formuláře → uloží se jen koncovka.
        self::assertSame('1111', CardNumberMask::normalizeLast4('4111 1111 1111 1111'));
        self::assertSame('9876', CardNumberMask::normalizeLast4('**** 9876'));
        self::assertSame('0042', CardNumberMask::normalizeLast4('0042'));
        self::assertNull(CardNumberMask::normalizeLast4('12'));
        self::assertNull(CardNumberMask::normalizeLast4(null));
        self::assertNull(CardNumberMask::normalizeLast4(['1234']));
    }

    public function testContainsFullPanDetectsLuhnValidNumbers(): void
    {
        self::assertTrue(CardNumberMask::containsFullPan('karta 4111 1111 1111 1111'));
        self::assertTrue(CardNumberMask::containsFullPan('4111-1111-1111-1111'));
        self::assertFalse(CardNumberMask::containsFullPan('4111111111111112'), 'Luhn nesedí');
        self::assertFalse(CardNumberMask::containsFullPan('Faktura 202600001'));
        self::assertFalse(CardNumberMask::containsFullPan('Firemní karta 1234'));
    }

    public function testParsedTransactionPrefersExplicitColumnThenTextThenApiMetadata(): void
    {
        self::assertSame('3333', CardNumberMask::forParsedTransaction([
            'card_last4' => '3333',
            'description' => 'PK: 000000******4444',
        ]));
        self::assertSame('4444', CardNumberMask::forParsedTransaction([
            'counterparty_name' => null,
            'description' => 'Nákup PK: 000000******4444',
        ]));
        self::assertNull(CardNumberMask::forParsedTransaction([
            'counterparty_name' => 'Dodavatel s.r.o.',
            'description' => 'Úhrada faktury',
        ]));
    }

    /**
     * Bankovní API (zde CREDITAS) vrací kartu v surových datech transakce. Parser
     * je předá jako `metadata` a koncovka se najde i tam, kde ji popis nenese.
     */
    public function testApiMetadataCarriesMaskedCard(): void
    {
        $parsed = (new CreditasTransactionParser())->parse([[
            'transactionId' => 'TX-SYNTH-1',
            'type' => 'DEBIT',
            'amount' => ['value' => '250.00', 'currency' => 'CZK'],
            'effectiveDate' => '2026-06-12',
            'remittanceInfo' => 'Nákup kartou',
            'cardInfo' => ['maskedPan' => '123456******4242', 'merchant' => 'TESTOVACI OBCHOD'],
        ]], '1000000005', '2026-06-30');

        self::assertSame('4242', CardNumberMask::forParsedTransaction($parsed['transactions'][0]));
    }
}
