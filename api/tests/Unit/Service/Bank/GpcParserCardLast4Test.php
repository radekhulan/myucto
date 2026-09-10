<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank;

use MyInvoice\Service\Bank\GpcParser;
use PHPUnit\Framework\TestCase;

/**
 * GPC karetní pohyb: maskované číslo karty je v navazujícím 078/079 avízu.
 * Parser z něj vytáhne koncovku do `card_last4` a jméno obchodníka bez masky.
 * Syntetická data (maskované číslo, fiktivní obchodník).
 */
final class GpcParserCardLast4Test extends TestCase
{
    private function header(): string
    {
        return '074' . str_pad('1', 16) . str_pad('', 20) . '010626'
            . str_pad('0', 14, '0') . '+' . str_pad('0', 14, '0') . '+'
            . str_pad('0', 14, '0') . '+' . str_pad('0', 14, '0') . '+'
            . '001' . '010626';
    }

    private function cardTx(string $doc = 'D'): string
    {
        return '075'
            . str_pad('1', 16) . str_pad('', 16, '0') . str_pad($doc, 13)
            . str_pad('25000', 12, '0', STR_PAD_LEFT) . '1'
            . str_pad('0', 10, '0', STR_PAD_LEFT)
            . '00' . '0000' . '0000'
            . str_pad('0', 10, '0', STR_PAD_LEFT)
            . '120626'
            . str_pad('', 20)
            . '00203' . '120626';
    }

    public function testMaskedCardFrom078BecomesCardLast4(): void
    {
        $r = (new GpcParser())->parse(
            $this->header() . "\n" . $this->cardTx() . "\n" . '078SHELL TEST PRAHA CZ PK: 000000******1111'
        );

        $t = $r['transactions'][0];
        self::assertSame('1111', $t['card_last4'] ?? null);
        self::assertSame('SHELL TEST PRAHA CZ', $t['counterparty_name']);
        self::assertSame(-250.0, $t['amount']);
    }

    public function testMaskedCardFrom079AdditionalLine(): void
    {
        $r = (new GpcParser())->parse(
            $this->header() . "\n" . $this->cardTx() . "\n"
            . '078TESTOVACI OBCHOD BRNO' . "\n"
            . '079PK: 516872XXXXXX2222'
        );

        $t = $r['transactions'][0];
        self::assertSame('2222', $t['card_last4'] ?? null);
        self::assertSame('TESTOVACI OBCHOD BRNO', $t['counterparty_name']);
    }

    public function testNonCardMovementHasNoCardLast4(): void
    {
        $r = (new GpcParser())->parse(
            $this->header() . "\n" . $this->cardTx() . "\n" . '078Poznamka k platbe'
        );

        self::assertArrayNotHasKey('card_last4', $r['transactions'][0]);
    }

    public function testEachMovementKeepsItsOwnCard(): void
    {
        $r = (new GpcParser())->parse(
            $this->header() . "\n"
            . $this->cardTx('D1') . "\n" . '078OBCHOD A PK: 000000******1111' . "\n"
            . $this->cardTx('D2') . "\n" . '078OBCHOD B PK: 000000******2222'
        );

        self::assertSame('1111', $r['transactions'][0]['card_last4'] ?? null);
        self::assertSame('2222', $r['transactions'][1]['card_last4'] ?? null);
    }
}
