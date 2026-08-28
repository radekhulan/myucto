<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Payment;

use MyInvoice\Service\Payment\AboPaymentOrderWriter;
use PHPUnit\Framework\TestCase;

/**
 * Zlatý test ABO/KPC generátoru — struktura ověřená proti reálnému
 * `private/KPC/abo-payment-96.kpc` (data jsou zde SYNTETICKÁ, ne z výpisu).
 */
final class AboPaymentOrderWriterTest extends TestCase
{
    private AboPaymentOrderWriter $writer;

    protected function setUp(): void
    {
        $this->writer = new AboPaymentOrderWriter();
    }

    public function testBuildsFullAboPaymentOrder(): void
    {
        $order = [
            'client_name'          => 'TESTCLIENT',
            'client_number'        => null,                 // → odvodit z čísla účtu plátce
            'file_number'          => '123',
            'payer_account_number' => '19-2000145399',
            'payer_bank_code'      => '0800',
            'payment_date'         => '2026-06-12',
            'items'                => [
                [
                    'account_number'  => '2000145399',
                    'bank_code'       => '0800',
                    'amount'          => 4555.40,
                    'variable_symbol' => '2601603',
                    'constant_symbol' => '0308',
                    'specific_symbol' => null,
                    'message'         => 'FV-160/2026',     // „-" a „/" se z platebního styku vyhodí
                ],
                [
                    'account_number'  => '35-6233260257',
                    'bank_code'       => '0100',
                    'amount'          => 15000.00,
                    'variable_symbol' => '260100001',
                    'constant_symbol' => null,              // KS = jen banka + 0000
                    'specific_symbol' => '7',
                    'message'         => null,              // → fallback na VS
                ],
            ],
        ];

        $expected = implode("\r\n", [
            'UHL1120626TESTCLIENT          2000145399000999000000000000',
            '1 1501 000123 0800',
            '2 000019-2000145399 00000001955540 120626',
            '000000-2000145399 000000455540 2601603 08000308 0000000000 AV:FV1602026',
            '000035-6233260257 000001500000 260100001 01000000 0000000007 AV:260100001',
            '3 +',
            '5 +',
        ]) . "\r\n";

        self::assertSame($expected, $this->writer->build($order));
    }

    /** KS pole = směrový kód banky příjemce (4) + konstantní symbol (4). */
    public function testConstantSymbolFieldEncodesRecipientBankCode(): void
    {
        $out = $this->writer->build($this->orderWith([
            'account_number' => '123456789', 'bank_code' => '2700',
            'amount' => 100.00, 'variable_symbol' => '1', 'constant_symbol' => '308',
        ]));
        // banka 2700 + KS 0308 → '27000308'
        self::assertStringContainsString(' 27000308 ', $out);
    }

    /** Částka se uvádí v haléřích, zleva nulami, 12 míst u položky. */
    public function testAmountInHalerWithCorrectRounding(): void
    {
        $out = $this->writer->build($this->orderWith([
            'account_number' => '123456789', 'bank_code' => '0800',
            'amount' => 1234.565, 'variable_symbol' => '1', // 123456.5 haléře → round 123457
        ]));
        self::assertStringContainsString(' 000000123457 ', $out);
    }

    public function testExactMinorUnitsBypassFloatingPointConversion(): void
    {
        $out = $this->writer->build($this->orderWith([
            'account_number' => '123456789',
            'bank_code' => '0800',
            'amount_minor' => 12_345,
            'variable_symbol' => '1',
        ]));

        self::assertStringContainsString(' 000000012345 ', $out);
        self::assertStringContainsString(
            '2 000000-2000145399 00000000012345 ',
            $out,
        );
    }

    public function testThrowsOnEmptyItems(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->writer->build([
            'payer_account_number' => '2000145399', 'payer_bank_code' => '0800',
            'payment_date' => '2026-06-12', 'items' => [],
        ]);
    }

    public function testThrowsOnMissingOrNonArrayItems(): void
    {
        $rejected = 0;
        foreach ([null, 'not-an-array'] as $items) {
            $order = [
                'payer_account_number' => '2000145399',
                'payer_bank_code' => '0800',
                'payment_date' => '2026-06-12',
            ];
            if ($items !== null) {
                $order['items'] = $items;
            }
            try {
                $this->writer->build($order);
                self::fail('Neplatné položky musí být odmítnuty.');
            } catch (\InvalidArgumentException) {
                ++$rejected;
            }
        }
        self::assertSame(2, $rejected);
    }

    public function testExactMinorUnitsRespectAboFieldLimits(): void
    {
        try {
            $this->writer->build($this->orderWith([
                'account_number' => '123456789',
                'bank_code' => '0800',
                'amount_minor' => 1_000_000_000_000,
            ]));
            self::fail('Položka nad 12 míst nesmí vytvořit neplatné ABO.');
        } catch (\InvalidArgumentException) {
        }

        $item = [
            'account_number' => '123456789',
            'bank_code' => '0800',
            'amount_minor' => 999_999_999_999,
        ];
        $order = $this->orderWith($item);
        $order['items'] = array_fill(0, 101, $item);
        $this->expectException(\InvalidArgumentException::class);
        $this->writer->build($order);
    }

    public function testThrowsWhenPayeeHasNoCzechAccount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->writer->build($this->orderWith([
            'account_number' => null, 'bank_code' => null, // jen IBAN → do ABO nepatří
            'amount' => 100.00, 'variable_symbol' => '1',
        ]));
    }

    public function testThrowsOnNonPositiveAmount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->writer->build($this->orderWith([
            'account_number' => '123456789', 'bank_code' => '0800',
            'amount' => 0, 'variable_symbol' => '1',
        ]));
    }

    public function testAcceptsExactMaximumFixedFieldLengths(): void
    {
        $order = [
            'client_name' => 'X',
            'client_number' => '1234567890',
            'file_number' => '123456',
            'payer_account_number' => '123456-1234567890',
            'payer_bank_code' => '1234',
            'payment_date' => '2026-06-12',
            'items' => [[
                'account_number' => '654321-0987654321',
                'bank_code' => '4321',
                'amount_minor' => 1,
                'variable_symbol' => '1234567890',
                'constant_symbol' => '1234',
                'specific_symbol' => '0987654321',
            ]],
        ];

        $result = $this->writer->build($order);

        self::assertStringContainsString(
            '123456-1234567890',
            $result,
        );
        self::assertStringContainsString(
            '654321-0987654321 000000000001 1234567890'
                . ' 43211234 0987654321',
            $result,
        );
    }

    public function testRejectsEveryOversizedFixedNumericField(): void
    {
        $base = [
            'client_name' => 'X',
            'client_number' => '1234567890',
            'file_number' => '123456',
            'payer_account_number' => '123456-1234567890',
            'payer_bank_code' => '1234',
            'payment_date' => '2026-06-12',
            'items' => [[
                'account_number' => '654321-0987654321',
                'bank_code' => '4321',
                'amount_minor' => 1,
                'variable_symbol' => '1234567890',
                'constant_symbol' => '1234',
                'specific_symbol' => '0987654321',
            ]],
        ];
        $cases = [];
        foreach ([
            'client_number' => '12345678901',
            'file_number' => '1234567',
            'payer_account_number' => '1234567-1234567890',
            'payer_account_number_number' => '123456-12345678901',
            'payer_bank_code' => '12345',
        ] as $field => $value) {
            $order = $base;
            $order[$field === 'payer_account_number_number'
                ? 'payer_account_number'
                : $field] = $value;
            $cases[] = $order;
        }
        foreach ([
            'account_number' => '1234567-1234567890',
            'account_number_number' => '123456-12345678901',
            'bank_code' => '12345',
            'variable_symbol' => '12345678901',
            'constant_symbol' => '12345',
            'specific_symbol' => '12345678901',
        ] as $field => $value) {
            $order = $base;
            $order['items'][0][
                $field === 'account_number_number'
                    ? 'account_number'
                    : $field
            ] = $value;
            $cases[] = $order;
        }

        $rejected = 0;
        foreach ($cases as $order) {
            try {
                $this->writer->build($order);
                self::fail('Přetečené pevné ABO pole musí být odmítnuto.');
            } catch (\InvalidArgumentException) {
                ++$rejected;
            }
        }
        self::assertSame(11, $rejected);
    }

    /**
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    /**
     * P-07 — chybějící variabilní symbol se dřív tiše nahradil nulou. Odvod
     * bez VS zdravotní pojišťovna nespáruje s IČ a firmu vede jako dlužníka,
     * exekutor platbu nepřiřadí ke spisu. Teď je to tvrdá chyba.
     */
    public function testThrowsWhenVariableSymbolIsMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nemá variabilní symbol');
        $this->writer->build($this->orderWith([
            'account_number' => '2000145399', 'bank_code' => '0800',
            'amount' => 100.00, 'variable_symbol' => null,
        ]));
    }

    public function testThrowsWhenVariableSymbolHasNoDigits(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nemá variabilní symbol');
        $this->writer->build($this->orderWith([
            'account_number' => '2000145399', 'bank_code' => '0800',
            'amount' => 100.00, 'variable_symbol' => '---',
        ]));
    }

    /**
     * Čistá mzda na účet zaměstnance VS legitimně nemá — volající to ale musí
     * potvrdit vědomě, ne mlčením.
     */
    public function testWritesZeroVariableSymbolOnlyWhenExplicitlyAllowed(): void
    {
        $result = $this->writer->build($this->orderWith([
            'account_number' => '2000145399', 'bank_code' => '0800',
            'amount' => 100.00, 'variable_symbol' => null,
            'allow_missing_variable_symbol' => true,
        ]));

        self::assertStringContainsString(
            '000000-2000145399 000000010000 0 08000000 0000000000',
            $result,
        );
    }

    public function testRejectsNonBooleanMissingSymbolFlag(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->writer->build($this->orderWith([
            'account_number' => '2000145399', 'bank_code' => '0800',
            'amount' => 100.00, 'variable_symbol' => null,
            'allow_missing_variable_symbol' => 'ano',
        ]));
    }

    private function orderWith(array $item): array
    {
        return [
            'client_name'          => 'X',
            'payer_account_number' => '2000145399',
            'payer_bank_code'      => '0800',
            'payment_date'         => '2026-06-12',
            'items'                => [$item],
        ];
    }
}
