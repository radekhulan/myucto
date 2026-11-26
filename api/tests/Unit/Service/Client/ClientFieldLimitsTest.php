<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Client;

use MyInvoice\Service\Accounting\AdvancePaidAccount;
use MyInvoice\Service\Client\ClientFieldLimits;
use MyInvoice\Service\Validation;
use PHPUnit\Framework\TestCase;

/**
 * Délky textových sloupců `clients`: ruční zadání dostane chybu validace,
 * cizí data (AI vytěžení, importy) se do sloupců upraví. Obojí z jedné mapy,
 * která musí odpovídat otisku struktury DB.
 */
final class ClientFieldLimitsTest extends TestCase
{
    public function testLimitsMatchSchemaSnapshot(): void
    {
        $snapshot = json_decode(
            (string) file_get_contents(dirname(__DIR__, 5) . '/db/schema.snapshot.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $columns = $snapshot['tables']['clients']['columns'];

        foreach ($columns as $column => $definition) {
            if (preg_match('/^varchar\((\d+)\)/', (string) $definition, $m) !== 1) {
                continue;
            }
            if (in_array($column, ['invoice_number_format', 'proforma_number_format', 'credit_note_number_format',
                'email_subject_format', 'email_attachment_name_format'], true)) {
                // Formáty mají vlastní validaci délky (InvoiceNumberFormat, ClientEmailFormat).
                continue;
            }
            if (in_array($column, AdvancePaidAccount::FIELDS, true)) {
                // Kód účtu záloh ověřuje AdvancePaidAccount proti osnově firmy (stejná šířka
                // jako chart_of_accounts.account_code), zkracovat se nesmí.
                continue;
            }
            self::assertArrayHasKey($column, ClientFieldLimits::MAX_LENGTHS, "clients.{$column} chybí v ClientFieldLimits");
            self::assertSame((int) $m[1], ClientFieldLimits::MAX_LENGTHS[$column], "clients.{$column}");
        }
        foreach (array_keys(ClientFieldLimits::MAX_LENGTHS) as $column) {
            self::assertArrayHasKey($column, $columns, "clients.{$column} v DB neexistuje");
        }
    }

    public function testFitLeavesValuesThatFitUntouched(): void
    {
        $data = [
            'company_name' => '  Syntetická s.r.o.  ',
            'dic' => 'CZ 12345678',
            'zip' => '110 00',
            'note' => str_repeat('x', 5000),
            'web' => 'https://example.invalid',
        ];

        self::assertSame($data, ClientFieldLimits::fit($data));
    }

    public function testFitKeepsOnlyThePostalCodeFromTooLongZip(): void
    {
        self::assertSame('11000', ClientFieldLimits::fit(['zip' => '110 00 Praha 1'])['zip']);
        self::assertSame('AT-1230', ClientFieldLimits::fit(['zip' => "AT-1230\u{00A0}Wien"])['zip']);
        self::assertNull(ClientFieldLimits::fit(['zip' => 'neuvedeno na dokladu'])['zip']);
    }

    public function testFitNormalizesOrDropsDic(): void
    {
        self::assertSame('CZ12345678', ClientFieldLimits::fit(['dic' => 'CZ  1  2  3  4  5  6  7  8'])['dic']);
        self::assertSame('NL123456789B01', ClientFieldLimits::fit(['dic' => 'nl-1-2-3-4-5-6-7-8-9-b01'])['dic']);
        // Popisek by po odstranění nealfanumerických znaků slepil cizí „DIČ" — zahodit.
        self::assertNull(ClientFieldLimits::fit(['dic' => 'DIČ: CZ 12345678 (plátce DPH)'])['dic']);
        self::assertNull(ClientFieldLimits::fit(['dic' => 'CZ12345678, SK2020123456'])['dic']);
    }

    public function testFitDropsGarbledIc(): void
    {
        self::assertNull(ClientFieldLimits::fit(['ic' => 'IČO: 12345678, DIČ: CZ12345678'])['ic']);
    }

    public function testFitTruncatesTexts(): void
    {
        $fitted = ClientFieldLimits::fit([
            'company_name' => str_repeat('á', 300),
            'city' => str_repeat('Město ', 40),
            'phone' => str_repeat('+420 000 000 000, ', 5),
        ]);

        self::assertSame(190, mb_strlen($fitted['company_name']));
        self::assertSame(120, mb_strlen($fitted['city']));
        self::assertSame(40, mb_strlen($fitted['phone']));
    }

    public function testManualInputTooLongIsValidationError(): void
    {
        $errors = Validation::client([
            'company_name' => 'Syntetická s.r.o.',
            'street' => 'Dlouhá 1',
            'city' => 'Praha',
            'zip' => '11000',
            'dic' => str_repeat('CZ1234567890', 2),
            'tax_number' => str_repeat('1', 31),
            'first_name' => str_repeat('J', 61),
            'main_email' => str_repeat('a', 190) . '@example.invalid',
        ]);

        foreach (['dic', 'tax_number', 'first_name', 'main_email'] as $field) {
            self::assertArrayHasKey($field, $errors, $field);
        }
        self::assertArrayNotHasKey('zip', $errors);
    }
}
