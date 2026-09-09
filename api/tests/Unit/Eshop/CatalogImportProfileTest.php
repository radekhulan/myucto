<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Eshop;

use MyInvoice\Service\Eshop\Import\CatalogImportProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CatalogImportProfileTest extends TestCase
{
    public function testMappedValuesPreserveIdentifiersAndNormalizeDecimals(): void
    {
        $profile = CatalogImportProfile::normalize(['mapping' => ['sku' => 'Kód', 'min_qty' => 'Minimum', 'is_active' => 'Aktivní']]);
        self::assertSame(['is_active' => true, 'min_qty' => '100.000', 'sku' => '000123'],
            CatalogImportProfile::map($profile, ['Kód', 'Minimum', 'Aktivní'], ['000123', '100,00', 'ano']));
        self::assertSame(
            CatalogImportProfile::map($profile, ['Kód', 'Minimum', 'Aktivní'], ['000123', '100.00', '1']),
            CatalogImportProfile::map($profile, ['Kód', 'Minimum', 'Aktivní'], ['000123', '100', 'true']),
        );
    }

    public function testMissingAndBlankFieldsRemainUntouchedUnlessExplicitlyCleared(): void
    {
        $profile = CatalogImportProfile::normalize(['mapping' => ['sku' => 'sku', 'ean' => 'ean', 'note' => 'note'], 'operations' => ['note' => 'preserve', 'categories' => 'clear']]);
        self::assertSame(['sku' => 'A', 'categories' => []], CatalogImportProfile::map($profile, ['sku', 'ean', 'note'], ['A', '', 'ignored']));
        $profile['blank'] = 'clear';
        self::assertSame(['ean' => null, 'sku' => 'A', 'categories' => []], CatalogImportProfile::map($profile, ['sku', 'ean', 'note'], ['A', '', 'ignored']));
    }

    #[DataProvider('invalidValues')]
    public function testInvalidDomainValuesAreRejected(string $field, string $value): void
    {
        $profile = CatalogImportProfile::normalize(['mapping' => ['sku' => 'sku', $field => 'value']]);
        $this->expectException(\InvalidArgumentException::class);
        CatalogImportProfile::map($profile, ['sku', 'value'], ['A', $value]);
    }

    public static function invalidValues(): iterable
    {
        yield ['min_qty', '100000000000'];
        yield ['min_qty', '-1'];
        yield ['min_qty', '1.0001'];
        yield ['is_active', 'maybe'];
        yield ['manufacturer_id', '1.5'];
        yield ['vat_rate_id', '0'];
        yield ['prices', '{}'];
        yield ['categories', '[invalid]'];
        yield ['categories', '[{"id":1}]'];
        yield ['categories', '[{"category_id":0}]'];
        yield ['tag_ids', '[0]'];
        yield ['fees', '[{"amount":"10"}]'];
        yield ['i18n', '[{"locale":"cs","name":""}]'];
        yield ['prices', '[{"currency_code":"CZK","is_manual_override":"false"}]'];
        yield ['item_type', 'service'];
        yield ['ean', str_repeat('1', 21)];
    }

    public function testDuplicateHeaderDoesNotSilentlyChooseOneColumn(): void
    {
        $profile = CatalogImportProfile::normalize(['mapping' => ['sku' => 'sku']]);
        $this->expectException(\InvalidArgumentException::class);
        CatalogImportProfile::map($profile, ['sku', 'sku'], ['A', 'B']);
    }

    public function testExternalIdentityRequiresNamedSource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CatalogImportProfile::normalize(['identity' => 'external_id', 'mapping' => ['external_id' => 'id']]);
    }
}
