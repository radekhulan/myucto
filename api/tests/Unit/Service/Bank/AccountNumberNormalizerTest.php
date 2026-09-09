<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank;

use MyInvoice\Service\Bank\AccountNumberNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AccountNumberNormalizerTest extends TestCase
{
    public function testIbanComparisonUsesDomesticIdentityAndBank(): void
    {
        self::assertTrue(AccountNumberNormalizer::equals('CZ7508000000001000000005', '1000000005/0800'));
        self::assertTrue(AccountNumberNormalizer::equals('1000000005', 'CZ7508000000001000000005'));
        self::assertTrue(AccountNumberNormalizer::equals('SK0383300000001000000005', '0000001000000005'));
        self::assertFalse(AccountNumberNormalizer::equals('CZ7508000000001000000005', '1000000005/0100'));
        self::assertFalse(AccountNumberNormalizer::equals('CZ7508000000001000000005', 'SK0383300000001000000005'));
    }

    public function testSlovakIbanMatchesDomesticGpcAccount(): void
    {
        $iban = 'SK0383300000001000000005';
        self::assertSame('0000001000000005', AccountNumberNormalizer::czechSlovakIbanAccountPart($iban));
        self::assertTrue(AccountNumberNormalizer::matchesAny('0000001000000005', null, $iban));
        self::assertTrue(AccountNumberNormalizer::matchesAny('0000001000000005', $iban));
        self::assertFalse(AccountNumberNormalizer::matchesAny('0000002000000018', null, $iban));
        self::assertNull(AccountNumberNormalizer::czechIbanAccountPart($iban));
    }

    public function testPrefixedShortAccountMatchesPaddedGpcWithoutLosingPrefix(): void
    {
        self::assertTrue(AccountNumberNormalizer::matchesAny('0000190000000019', '19-19'));
        self::assertTrue(AccountNumberNormalizer::equalsCzech('19-19', '0000190000000019'));
        self::assertFalse(AccountNumberNormalizer::equalsCzech('19-19', '19'));
        self::assertFalse(AccountNumberNormalizer::equalsCzech('19-19', '1900000019'));
        self::assertFalse(AccountNumberNormalizer::equalsCzech('19-1*', '19-19'));
    }

    #[DataProvider('normalizeCases')]
    public function testNormalize(string $input, string $expected): void
    {
        self::assertSame($expected, AccountNumberNormalizer::normalize($input));
    }

    /** @return iterable<string, array{string, string}> */
    public static function normalizeCases(): iterable
    {
        yield 'zero-padded GPC'         => ['0000000123456789', '123456789'];
        yield 'plain digits'            => ['123456789',        '123456789'];
        yield 'CZ prefix dash'          => ['19-2000145399',    '192000145399'];
        yield 'spaces'                  => ['1 000 000 005',    '1000000005'];
        yield 'prefix + zero padding'   => ['0000019-2000145399', '192000145399'];
        yield 'leading zeros only'      => ['0000000000',       ''];
        yield 'empty'                   => ['',                  ''];
        yield 'IBAN style stripped'     => ['CZ6508000000192000145399', '6508000000192000145399'];
    }

    public function testEqualsZeroPaddedVsPlain(): void
    {
        self::assertTrue(AccountNumberNormalizer::equals('0000000123456789', '123456789'));
        self::assertTrue(AccountNumberNormalizer::equals('123456789', '0000000123456789'));
    }

    public function testEqualsDifferentAccounts(): void
    {
        self::assertFalse(AccountNumberNormalizer::equals('1000000005', '1000000006'));
    }

    public function testMaskRequiresVisibleDigitsAndNeverMatchesAnotherMask(): void
    {
        self::assertFalse(AccountNumberNormalizer::equals('**********', '1000000005'));
        self::assertFalse(AccountNumberNormalizer::equals('1000000005', '**********'));
        self::assertFalse(AccountNumberNormalizer::equals('**********', '**********'));
        self::assertFalse(AccountNumberNormalizer::equals('100***0005', '100***0005'));
        self::assertFalse(AccountNumberNormalizer::equals('100***0005', '1000005'));
    }

    public function testEqualsPrefixVsBase(): void
    {
        // Note: prefixed account `19-1000000005` normalizes to `191000000005`,
        // a different value than `1000000005`. So they are NOT considered same.
        self::assertFalse(AccountNumberNormalizer::equals('19-1000000005', '1000000005'));
    }

    public function testEqualsEmptyEmpty(): void
    {
        self::assertTrue(AccountNumberNormalizer::equals('', ''));
        self::assertTrue(AccountNumberNormalizer::equals('0000', ''));
    }

    public function testEqualsMonetaMaskedAccount(): void
    {
        // Moneta Info Servis: „Účet: 238***891" vs. plné číslo stejné délky.
        self::assertTrue(AccountNumberNormalizer::equals('238***891', '238456891'));
        self::assertTrue(AccountNumberNormalizer::equals('238456891', '238***891'));
        self::assertTrue(AccountNumberNormalizer::equals('238***891/0600', '238456891'));
        self::assertTrue(AccountNumberNormalizer::equals('238***891', '238456891/0600'));
        self::assertFalse(AccountNumberNormalizer::equals('238***891', '239456891'));
        self::assertFalse(AccountNumberNormalizer::equals('238***891', '2384567891')); // jiná délka
        self::assertFalse(AccountNumberNormalizer::equals('238***891', '238***892')); // dvě masky
    }

    public function testMatchesAnyViaMonetaMaskedAccount(): void
    {
        self::assertTrue(AccountNumberNormalizer::matchesAny('238***891', '238456891', null));
        self::assertFalse(AccountNumberNormalizer::matchesAny('238***891', '239456891', null));
    }

    // ── IBAN podpora (#109 — EUR účty evidované jen IBANem vs GPC výpis) ──

    #[DataProvider('ibanAccountPartCases')]
    public function testCzechIbanAccountPart(string $iban, ?string $expected): void
    {
        self::assertSame($expected, AccountNumberNormalizer::czechIbanAccountPart($iban));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function ibanAccountPartCases(): iterable
    {
        yield 'CZ IBAN compact'   => ['CZ6508000000192000145399', '0000192000145399'];
        yield 'CZ IBAN spaces'    => ['CZ65 0800 0000 1920 0014 5399', '0000192000145399'];
        yield 'CZ IBAN lowercase' => ['cz6508000000192000145399', '0000192000145399'];
        yield 'non-CZ IBAN'       => ['DE89370400440532013000', null];
        yield 'plain account'     => ['192000145399', null];
        yield 'too short'         => ['CZ650800019200014539', null];
        yield 'empty'             => ['', null];
    }

    public function testCzechIbanBankCode(): void
    {
        self::assertSame('0800', AccountNumberNormalizer::czechIbanBankCode('CZ6508000000192000145399'));
        self::assertSame('0800', AccountNumberNormalizer::czechIbanBankCode('CZ65 0800 0000 1920 0014 5399'));
        self::assertNull(AccountNumberNormalizer::czechIbanBankCode('DE89370400440532013000'));
        self::assertNull(AccountNumberNormalizer::czechIbanBankCode(''));
    }

    public function testMatchesAnyViaAccountNumber(): void
    {
        self::assertTrue(AccountNumberNormalizer::matchesAny('0000192000145399', '19-2000145399', null));
    }

    public function testMatchesAnyViaIbanOnly(): void
    {
        // EUR účet evidovaný jen IBANem — GPC výpis nese domácí číslo (#109).
        self::assertTrue(AccountNumberNormalizer::matchesAny('0000192000145399', null, 'CZ6508000000192000145399'));
        self::assertTrue(AccountNumberNormalizer::matchesAny('192000145399', '', 'CZ65 0800 0000 1920 0014 5399'));
    }

    public function testMatchesAnyIbanPastedIntoAccountNumberField(): void
    {
        self::assertTrue(AccountNumberNormalizer::matchesAny('0000192000145399', 'CZ6508000000192000145399', null));
    }

    public function testMatchesAnyRejectsDifferentAccount(): void
    {
        self::assertFalse(AccountNumberNormalizer::matchesAny('1000000005', '1000000006', 'CZ6508000000192000145399'));
        self::assertFalse(AccountNumberNormalizer::matchesAny('1000000005', null, null));
    }

    #[DataProvider('canonicalCases')]
    public function testCanonical(?string $accountNumber, ?string $iban, ?string $expected): void
    {
        self::assertSame($expected, AccountNumberNormalizer::canonical($accountNumber, $iban));
    }

    /** @return iterable<string, array{?string, ?string, ?string}> */
    public static function canonicalCases(): iterable
    {
        yield 'national with prefix and bank' => ['19-2000145399/0800', null, '192000145399'];
        yield 'matching Czech IBAN' => ['CZ65 0800 0000 1920 0014 5399', null, '192000145399'];
        yield 'zero-padded GPC' => ['0000192000145399', null, '192000145399'];
        yield 'IBAN fallback' => [null, 'CZ65 0800 0000 1920 0014 5399', '192000145399'];
        yield 'non-CZ IBAN' => ['DE89370400440532013000', null, null];
        yield 'empty' => [null, null, null];
    }

    /**
     * REGRESE (nezaúčtované převody mezi vlastními účty): týž účet přijde ve výpisech
     * jednou jako `1700000006` a jindy jako nulami vycpaný `00000001700000006`. Obě
     * podoby MUSÍ dát tentýž kanonický klíč, jinak
     * {@see \MyInvoice\Repository\SupplierBankAccountRepository::matchCounterparty()}
     * protistranu nenajde, {@see \MyInvoice\Service\Accounting\Bank\OwnTransferDetector::detect()}
     * vrátí null a převod se nikdy nespáruje — bez jediné chybové hlášky.
     *
     * Účet BEZ předčíslí je zákeřný v tom, že padding je jen vodicí nula navíc:
     * kdyby se `canonical()` kdy vrátil k `substr($digits, 6)` (což dělá
     * {@see AccountNumberNormalizer::czechAccountBase()} u 16místného zápisu),
     * rozešly by se.
     */
    public function testZeroPaddedStatementFormCanonicalisesLikeThePlainOne(): void
    {
        $plain = AccountNumberNormalizer::canonical('1700000006');
        $padded = AccountNumberNormalizer::canonical('00000001700000006');

        self::assertSame('1700000006', $plain);
        self::assertSame($plain, $padded, 'Vycpaná GPC podoba je týž účet jako holé číslo.');
        self::assertTrue(AccountNumberNormalizer::equals('1700000006', '00000001700000006'));

        // A totéž s kódem banky za lomítkem, jak číslo chodí z UI nastavení.
        self::assertSame($plain, AccountNumberNormalizer::canonical('1700000006/0300'));
    }

    /**
     * `normalize()` a `canonical()` se ZÁMĚRNĚ rozcházejí a sjednotit je NELZE.
     *
     * `normalize()` je čistý strip cifer — jeho výstup se persistuje
     * (`bank_transactions.import_fingerprint`, `bank_posting_rules.counterparty_account`)
     * a zrcadlí ho SQL v {@see \MyInvoice\Repository\BankStatementOwnershipResolver}.
     * `canonical()` navíc odřízne kód banky za lomítkem a rozumí CZ IBANu — je to klíč
     * vlastního účtu (`supplier_bank_accounts.account_canonical`).
     *
     * Kdo staví tenant guard, musí použít TUTÉŽ funkci jako strana, kterou hlídá —
     * míchání obou je přesně díra R4. Tenhle test je pojistka, aby „zjednodušení" na
     * jednu funkci nebylo tiché: rozbije se tady, ne až v produkčních datech.
     */
    public function testNormalizeAndCanonicalDivergeOnBankCodeSuffixByDesign(): void
    {
        self::assertSame('1234567890800', AccountNumberNormalizer::normalize('123456789/0800'));
        self::assertSame('123456789', AccountNumberNormalizer::canonical('123456789/0800'));
        self::assertSame('123456789', AccountNumberNormalizer::canonical('123456789'));
        self::assertSame('123456789', AccountNumberNormalizer::normalize('123456789'));

        // canonical() je u ne-CZ IBANu přísnější (null), normalize() vrátí cifry.
        self::assertNull(AccountNumberNormalizer::canonical('DE89370400440532013000'));
        self::assertSame('89370400440532013000', AccountNumberNormalizer::normalize('DE89370400440532013000'));
    }

    public function testCanonicalBankCodePrefersExplicitCodeAndFallsBackToIban(): void
    {
        self::assertSame('0100', AccountNumberNormalizer::canonicalBankCode('100'));
        self::assertSame('0800', AccountNumberNormalizer::canonicalBankCode(null, 'CZ65 0800 0000 1920 0014 5399'));
        self::assertNull(AccountNumberNormalizer::canonicalBankCode(null, 'DE89370400440532013000'));
    }

    #[DataProvider('accountPrefixCases')]
    public function testCzechAccountPrefix(string $raw, ?string $expected): void
    {
        self::assertSame($expected, AccountNumberNormalizer::czechAccountPrefix($raw));
    }

    /** @return iterable<string,array{string,?string}> */
    public static function accountPrefixCases(): iterable
    {
        yield 'national' => ['705-77628031', '705'];
        yield 'GPC' => ['0007057762803100', '705'];
        yield 'IBAN' => ['CZ6507100007057762803100', '705'];
        yield 'without prefix' => ['77628031/0710', null];
    }

    #[DataProvider('accountBaseCases')]
    public function testCzechAccountBase(string $raw, ?string $expected): void
    {
        self::assertSame($expected, AccountNumberNormalizer::czechAccountBase($raw));
    }

    /**
     * Základ = číslo BEZ předčíslí. Musí vyjít stejně z národního i nulami vycpaného
     * GPC zápisu — na tom stojí rozpoznání zdravotní pojišťovny v remittance_map,
     * kde `normalize()` selhává (u účtu s předčíslím ho slepí s číslem).
     *
     * @return iterable<string,array{string,?string}>
     */
    public static function accountBaseCases(): iterable
    {
        yield 'ČSSZ národní' => ['21012-7928311', '7928311'];
        yield 'ČSSZ GPC' => ['0210120007928311', '7928311'];
        yield 'VZP bez předčíslí' => ['1111006311', '1111006311'];
        yield 'VZP GPC' => ['0000001111006311', '1111006311'];
        yield 's kódem banky' => ['1111006311/0710', '1111006311'];
        yield 'IBAN' => ['CZ6507100007057762803100', '7762803100'];
        yield 'prázdné' => ['', null];
    }
}
