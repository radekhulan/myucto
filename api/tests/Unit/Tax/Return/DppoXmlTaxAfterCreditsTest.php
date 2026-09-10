<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Service\Tax\Return\DppoReturnCalculator;
use MyInvoice\Service\Tax\Return\DppoXmlBuilder;
use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DppoXmlTaxAfterCreditsTest extends TestCase
{
    public static function scenarios(): iterable
    {
        yield 'disabled employee' => [1000000, ['disabled_employees_avg' => 1], '192000'];
        yield 'stopped execution' => [1000000, ['stopped_execution_credit' => 450], '209550'];
        yield 'full credit' => [10000, ['disabled_employees_avg' => 1], '0'];
        yield 'no credit' => [1000000, [], '210000'];
        yield 'loss' => [-100000, [], null];
    }

    #[DataProvider('scenarios')]
    public function testLine330MatchesTaxAfterCredits(float $profit, array $inputs, ?string $expected): void
    {
        $calc = (new DppoReturnCalculator())->compute(
            ['vh' => $profit, 'depreciation' => ['tax' => 0, 'accounting' => 0]],
            $inputs,
            TaxConstants::forYear(2025),
        );
        $result = (new DppoXmlBuilder())->build([
            'company_name' => 'Testovací společnost s.r.o.', 'ic' => '12345678',
            'country_iso2' => 'CZ', 'taxpayer_type' => 'po', 'financial_office_code' => '451',
        ], 2025, $calc);
        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($result['xml']));
        $line = $dom->getElementsByTagName('VetaO')->item(0);
        self::assertInstanceOf(\DOMElement::class, $line);
        if ($expected === null) {
            self::assertFalse($line->hasAttribute('kc_ii320_330'));
        } else {
            self::assertSame($expected, $line->getAttribute('kc_ii320_330'));
            self::assertSame($expected, $line->getAttribute('kc_ii300_310'));
            self::assertSame($expected, $line->getAttribute('kc_ii_340'));
        }
    }
}
