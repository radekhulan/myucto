<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank;

use MyInvoice\Service\Bank\CurrencyBankAccountFields;
use PHPUnit\Framework\TestCase;

final class CurrencyBankAccountFieldsTest extends TestCase
{
    public function testCzechBankCodeAndForeignAccountWithoutBankCodePass(): void
    {
        self::assertSame([], CurrencyBankAccountFields::validationErrors(['account_number' => '1000000005', 'bank_code' => '0100']));
        self::assertSame([], CurrencyBankAccountFields::validationErrors(['account_number' => '12345678', 'bank_code' => '',
            'iban' => 'GB33BUKB20201555555555', 'bic' => 'BUKBGB22']));
        self::assertSame([], CurrencyBankAccountFields::validationErrors(['bank_code' => null]));
    }

    public function testForeignNationalBankCodesPass(): void
    {
        foreach (['60-84-64', '608464', '60 84 64', '021000021'] as $code) {
            self::assertSame([], CurrencyBankAccountFields::validationErrors(['bank_code' => $code]), $code);
        }
    }

    public function testMalformedAndOverlongFieldsAreRejectedByField(): void
    {
        foreach (['60/84/64', '-6084', '12345678901', '60–84–64'] as $code) {
            self::assertArrayHasKey('bank_code', CurrencyBankAccountFields::validationErrors(['bank_code' => $code]), $code);
        }
        self::assertArrayHasKey('bic', CurrencyBankAccountFields::validationErrors(['bic' => 'BUKBGB22XXXX']));
        self::assertArrayHasKey('iban', CurrencyBankAccountFields::validationErrors(['iban' => str_repeat('1', 35)]));
        self::assertArrayHasKey('account_number', CurrencyBankAccountFields::validationErrors(['account_number' => ['x']]));
    }

    public function testForeignBankCodeForInvoice(): void
    {
        // Český kód se tiskne jako „číslo / kód", ne zvlášť.
        self::assertNull(CurrencyBankAccountFields::foreignBankCode('0800', 'CZ6508000000000005526212'));
        self::assertNull(CurrencyBankAccountFields::foreignBankCode(null, 'CZ6508000000000005526212'));
        self::assertSame(['label' => 'sort_code', 'value' => '60-84-64'], CurrencyBankAccountFields::foreignBankCode('608464', 'GB33BUKB20201555555555'));
        self::assertSame(['label' => 'sort_code', 'value' => '60-84-64'], CurrencyBankAccountFields::foreignBankCode('60-84-64', null));
        // Bez zadaného kódu se sort code vezme z britského IBANu.
        self::assertSame(['label' => 'sort_code', 'value' => '20-20-15'], CurrencyBankAccountFields::foreignBankCode('', 'GB33 BUKB 2020 1555 5555 55'));
        self::assertSame(['label' => 'bank_code', 'value' => '021000021'], CurrencyBankAccountFields::foreignBankCode('021000021', null));
    }

    public function testUkSortCodeComesOnlyFromBritishIban(): void
    {
        self::assertSame('20-20-15', CurrencyBankAccountFields::ukSortCode('GB33 BUKB 2020 1555 5555 55'));
        self::assertSame('12-34-56', CurrencyBankAccountFields::ukSortCode('gb82west12345698765432'));
        self::assertNull(CurrencyBankAccountFields::ukSortCode('CZ6501000000001000000005'));
        self::assertNull(CurrencyBankAccountFields::ukSortCode('GB33BUKB2020155555555'));
        self::assertNull(CurrencyBankAccountFields::ukSortCode(null));
    }
}
