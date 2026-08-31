<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Service\Tax\Return\TaxReturnService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * `TaxReturnService::sanitizeInputs()` (type='po') — dva nové ruční vstupy z tohoto
 * zadání:
 *   - `bank_account_id` (task #2, DppoReturnDataProvider::pickBankAccount) — volba
 *     účtu pro vrácení přeplatku místo tichého výběru prvního aktivního CZK účtu.
 *   - `puz_to_registry` (task #3, DppoXmlBuilder::buildVetaUZ/pr11_puz) — žádost o
 *     předání Přílohy do sbírky listin, výchozí ANO, přebijitelná na NE.
 *
 * `sanitizeInputs()` je čistá privátní metoda (jen `$this->money()`/`text()`/…, žádný
 * přístup na vlastnosti instance) — volá se reflexí bez konstruktoru, stejný trik jako
 * {@see TaxReturnServiceDppoAppendixWarningsTest}.
 */
final class TaxReturnServiceSanitizeInputsBankAndPuzTest extends TestCase
{
    /** @return array<string,mixed> */
    private function sanitize(array $inputs): array
    {
        $service = (new ReflectionClass(TaxReturnService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($service, 'sanitizeInputs');
        return $method->invoke($service, 'po', $inputs, 'radne');
    }

    public function testBankAccountIdMissingBecomesNull(): void
    {
        self::assertNull($this->sanitize([])['bank_account_id']);
    }

    public function testBankAccountIdNumericStringCoercedToInt(): void
    {
        self::assertSame(5, $this->sanitize(['bank_account_id' => '5'])['bank_account_id']);
    }

    public function testBankAccountIdIntPassesThrough(): void
    {
        self::assertSame(7, $this->sanitize(['bank_account_id' => 7])['bank_account_id']);
    }

    public function testBankAccountIdNonNumericBecomesNull(): void
    {
        // Fat-finger/manipulovaný požadavek — nesmí spadnout, jen se ignoruje jako
        // "žádná volba" (DppoReturnDataProvider::pickBankAccount pak vezme výchozí účet).
        self::assertNull($this->sanitize(['bank_account_id' => 'abc'])['bank_account_id']);
    }

    public function testBankAccountIdEmptyStringBecomesNull(): void
    {
        self::assertNull($this->sanitize(['bank_account_id' => ''])['bank_account_id']);
    }

    public function testPuzToRegistryDefaultsToTrueWhenMissing(): void
    {
        self::assertTrue($this->sanitize([])['puz_to_registry']);
    }

    public function testPuzToRegistryExplicitFalseIsPreserved(): void
    {
        self::assertFalse($this->sanitize(['puz_to_registry' => false])['puz_to_registry']);
    }

    public function testPuzToRegistryExplicitTrueIsPreserved(): void
    {
        self::assertTrue($this->sanitize(['puz_to_registry' => true])['puz_to_registry']);
    }

    public function testPuzToRegistryStringFalseIsCoerced(): void
    {
        self::assertFalse($this->sanitize(['puz_to_registry' => '0'])['puz_to_registry']);
    }
}
