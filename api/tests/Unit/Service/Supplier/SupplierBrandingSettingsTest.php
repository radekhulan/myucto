<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Supplier;

use MyInvoice\Service\Supplier\SupplierBrandingSettings;
use MyInvoice\Service\WorkReport\WorkReportDisplayOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SupplierBrandingSettingsTest extends TestCase
{
    public function testDisplayOptionsUseEffectiveValuesForRevokedPermissionComparison(): void
    {
        $stored = '{"amount":false,"compact":true}';
        $same = SupplierBrandingSettings::validate([
            'work_report_display_options' => ['date' => true, 'compact' => true, 'amount' => false],
        ]);
        self::assertTrue(SupplierBrandingSettings::sameValue('work_report_display_options', $same['work_report_display_options'], $stored));
        $changed = SupplierBrandingSettings::validate([
            'work_report_display_options' => ['compact' => true, 'amount' => true],
        ]);
        self::assertFalse(SupplierBrandingSettings::sameValue('work_report_display_options', $changed['work_report_display_options'], $stored));
        self::assertFalse(SupplierBrandingSettings::sameValue('work_report_display_options', null, $stored));
        self::assertTrue(SupplierBrandingSettings::sameValue('work_report_display_options', null, '{"amount":true}'));
    }

    public function testDefaultDisplayOptionsAreStoredAsNullAndReturnedAsResolvedOptions(): void
    {
        foreach ([null, [], WorkReportDisplayOptions::DEFAULTS, ['compact' => false, 'amount' => true]] as $value) {
            $body = SupplierBrandingSettings::validate(['work_report_display_options' => $value]);
            self::assertNull($body['work_report_display_options']);
            self::assertSame(WorkReportDisplayOptions::DEFAULTS, SupplierBrandingSettings::normalizeResponse($body)['work_report_display_options']);
        }
        $body = SupplierBrandingSettings::validate(['work_report_display_options' => ['rate' => false]]);
        self::assertSame('{"rate":false}', $body['work_report_display_options']);
        self::assertSame(array_replace(WorkReportDisplayOptions::DEFAULTS, ['rate' => false]), SupplierBrandingSettings::normalizeResponse($body)['work_report_display_options']);
    }

    public function testResponsePreservesBooleanDefaultsAndUnrelatedFields(): void
    {
        $response = SupplierBrandingSettings::normalizeResponse(['id' => 7, 'invoice_pdf_compact_items' => '1']);
        self::assertSame(7, $response['id']);
        self::assertTrue($response['invoice_pdf_compact_items']);
        self::assertTrue($response['invoice_pdf_show_base_qty']);
        self::assertFalse($response['invoice_pdf_show_issued_by']);
        self::assertFalse($response['invoice_pdf_show_line_discount']);
        self::assertFalse($response['invoice_pdf_bank_in_footer']);
        self::assertSame('#3B2D83', $response['email_accent_color']);
        self::assertSame('#3B2D83', SupplierBrandingSettings::normalizeResponse(['email_accent_color' => ''])['email_accent_color']);
    }

    public function testBooleanValuesAndColorAreNormalizedBeforeStorage(): void
    {
        self::assertSame([
            'invoice_pdf_compact_items' => 1, 'invoice_pdf_show_base_qty' => 0, 'email_accent_color' => '#AABBCC',
        ], SupplierBrandingSettings::validate([
            'invoice_pdf_compact_items' => true, 'invoice_pdf_show_base_qty' => 0, 'email_accent_color' => ' #aabbcc ',
        ]));
        self::assertSame(['email_accent_color' => '#3B2D83'], SupplierBrandingSettings::validate(['email_accent_color' => ''], blankColorUsesDefault: true));
    }

    public static function invalidValues(): array
    {
        return [
            'JSON text místo objektu' => ['work_report_display_options', '{"rate":false}'],
            'pole místo objektu' => ['work_report_display_options', [false]],
            'neznámá volba' => ['work_report_display_options', ['other' => true]],
            'text místo logické volby' => ['work_report_display_options', ['rate' => 'false']],
            'číslo místo logické volby' => ['work_report_display_options', ['amount' => 0]],
            'text místo přepínače' => ['invoice_pdf_compact_items', 'false'],
            'objekt místo přepínače' => ['invoice_pdf_show_base_qty', ['enabled' => true]],
            'null místo přepínače' => ['invoice_pdf_show_issued_by', null],
            'neplatná barva' => ['email_accent_color', 'red'],
            'objekt místo barvy' => ['email_accent_color', ['red']],
        ];
    }

    #[DataProvider('invalidValues')]
    public function testInvalidInputIsRejected(string $field, mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SupplierBrandingSettings::validate([$field => $value]);
    }
}
