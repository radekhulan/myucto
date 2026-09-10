<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Card;

use MyInvoice\Service\Bank\Card\PaymentCardInput;
use PHPUnit\Framework\TestCase;

/**
 * Vstup platební karty: celé číslo karty se nikdy neuloží — koncovka se z něj
 * vezme a volná textová pole ho odmítnou. 4111 1111 1111 1111 je veřejné
 * testovací číslo.
 */
final class PaymentCardInputTest extends TestCase
{
    /** @return array<string,mixed> */
    private function valid(array $override = []): array
    {
        return array_merge([
            'label' => 'Firemní karta',
            'last4' => '1234',
            'card_type' => 'debit',
        ], $override);
    }

    public function testFullNumberIsReducedToLast4AndFlagged(): void
    {
        $r = PaymentCardInput::normalize($this->valid(['last4' => '4111 1111 1111 1111']));

        self::assertSame([], $r['errors']);
        self::assertSame('1111', $r['data']['last4']);
        self::assertTrue($r['last4_truncated']);
    }

    public function testPlainLast4IsNotFlagged(): void
    {
        $r = PaymentCardInput::normalize($this->valid(['last4' => '0042']));

        self::assertSame('0042', $r['data']['last4']);
        self::assertFalse($r['last4_truncated']);
    }

    public function testFullNumberInFreeTextIsRejected(): void
    {
        $r = PaymentCardInput::normalize($this->valid([
            'label' => 'Karta 4111 1111 1111 1111',
            'holder_name' => '4111111111111111',
            'note' => 'číslo 4111-1111-1111-1111',
        ]));

        self::assertArrayHasKey('label', $r['errors']);
        self::assertArrayHasKey('holder_name', $r['errors']);
        self::assertArrayHasKey('note', $r['errors']);
    }

    public function testMissingOrShortLast4IsRejected(): void
    {
        self::assertArrayHasKey('last4', PaymentCardInput::normalize($this->valid(['last4' => '']))['errors']);
        self::assertArrayHasKey('last4', PaymentCardInput::normalize($this->valid(['last4' => '12']))['errors']);
    }

    public function testValidityAndEnumsAreChecked(): void
    {
        $r = PaymentCardInput::normalize($this->valid([
            'card_type' => 'gold',
            'card_network' => 'unknown',
            'valid_from' => '2026-06-30',
            'valid_to' => '2026-06-01',
        ]));

        self::assertArrayHasKey('card_type', $r['errors']);
        self::assertArrayHasKey('card_network', $r['errors']);
        self::assertArrayHasKey('valid_to', $r['errors']);
        self::assertArrayHasKey('valid_from', PaymentCardInput::normalize($this->valid(['valid_from' => '30.6.2026']))['errors']);
    }

    public function testForeignKeysAreTypedIds(): void
    {
        $r = PaymentCardInput::normalize($this->valid(['currency_id' => '5', 'employee_id' => 7, 'user_id' => '']));
        self::assertSame(5, $r['data']['currency_id']);
        self::assertSame(7, $r['data']['employee_id']);
        self::assertNull($r['data']['user_id']);

        self::assertArrayHasKey('currency_id', PaymentCardInput::normalize($this->valid(['currency_id' => '1.5']))['errors']);
        self::assertArrayHasKey('employee_id', PaymentCardInput::normalize($this->valid(['employee_id' => '-3']))['errors']);
    }
}
