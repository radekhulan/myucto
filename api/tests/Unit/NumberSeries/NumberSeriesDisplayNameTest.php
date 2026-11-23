<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\NumberSeries;

use MyInvoice\Service\NumberSeries\NumberSeriesKinds;
use PHPUnit\Framework\TestCase;

/**
 * Zobrazovaný název řady v hláškách serveru musí odpovídat popisku, který skládá
 * seznam řad ve frontendu z `number_series.kinds` a `number_series.scopes`.
 */
final class NumberSeriesDisplayNameTest extends TestCase
{
    public function testUnnamedSeriesIsNamedByKindAndScope(): void
    {
        self::assertSame('Faktury', NumberSeriesKinds::displayName('invoice', '', 'company', ''));
        self::assertSame('Příjmové pokladní doklady - pokladna Hlavní', NumberSeriesKinds::displayName('cash_in', '', 'cash_register', 'Hlavní'));
        self::assertSame('E-shop', NumberSeriesKinds::displayName('invoice', ' E-shop ', 'company', ''));
    }

    public function testNamesMirrorFrontendTranslations(): void
    {
        $file = dirname(__DIR__, 4) . '/web/src/i18n/cs.json';
        if (!is_file($file)) {
            self::markTestSkipped('Chybí web/src/i18n/cs.json.');
        }
        $cs = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR)['number_series'];
        foreach (array_keys(NumberSeriesKinds::KINDS) as $kind) {
            self::assertSame($cs['kinds'][$kind], NumberSeriesKinds::displayName($kind, '', 'company', ''), $kind);
        }
        foreach ($cs['scopes'] as $scope => $word) {
            $expected = 'Faktury - ' . ($word !== '' ? $word . ' ' : '') . 'X';
            self::assertSame($expected, NumberSeriesKinds::displayName('invoice', '', $scope, 'X'), $scope);
        }
    }
}
