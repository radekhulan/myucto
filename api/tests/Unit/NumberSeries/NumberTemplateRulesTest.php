<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\NumberSeries;

use MyInvoice\Service\Invoice\InvoiceNumberFormat;
use MyInvoice\Service\NumberSeries\NumberTemplateRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pravidla tvaru čísla; stejnou sadu vzorků prochází i Vitest (`numberTemplate.spec.ts`),
 * takže frontend a backend se nemohou rozejít.
 */
final class NumberTemplateRulesTest extends TestCase
{
    /** @return iterable<string, array{array<string,mixed>}> */
    public static function cases(): iterable
    {
        $file = dirname(__DIR__, 2) . '/Fixtures/number-template-cases.json';
        foreach (json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) as $i => $case) {
            yield $i . ': ' . $case['template'] => [$case];
        }
    }

    /** @param array<string,mixed> $case */
    #[DataProvider('cases')]
    public function testSharedCases(array $case): void
    {
        $template = NumberTemplateRules::normalize($case['template']);
        if (isset($case['normalized'])) {
            self::assertSame($case['normalized'], $template);
        }
        self::assertSame(
            $case['errors'],
            NumberTemplateRules::check($template, $case['allowPrefix'], $case['allowPp'], $case['period']),
        );
    }

    public function testTemplateOrNullNormalizesBracketsAndRejectsNonsense(): void
    {
        self::assertSame('{YY}{CCCC}', InvoiceNumberFormat::templateOrNull(' (YY)[CCCC] ', 'k'));
        self::assertNull(InvoiceNumberFormat::templateOrNull('  ', 'k'));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('neznámý zástupný symbol');
        InvoiceNumberFormat::templateOrNull('{YYx}{CCCC}', 'k');
    }

    public function testMessagesMatchFrontendCatalog(): void
    {
        $cs = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/web/src/i18n/cs.json'), true, 512, JSON_THROW_ON_ERROR);
        $texts = $cs['number_template']['errors'];
        foreach (['length', 'whitespace', 'counter', 'period'] as $code) {
            self::assertSame($this->unescape($texts[$code]), NumberTemplateRules::message($code, false, false), $code);
        }
        foreach ([[false, false], [true, false], [false, true], [true, true]] as [$prefix, $pp]) {
            $expected = str_replace('{allowed}', NumberTemplateRules::allowedSymbols($prefix, $pp), $this->unescape($texts['placeholder']));
            self::assertSame($expected, NumberTemplateRules::message('placeholder', $prefix, $pp));
        }
    }

    private function unescape(string $s): string
    {
        return str_replace(["{'{'}", "{'}'}"], ['{', '}'], preg_replace("/\\{'(\\{[^']*\\})'\\}/", '$1', $s) ?? $s);
    }
}
