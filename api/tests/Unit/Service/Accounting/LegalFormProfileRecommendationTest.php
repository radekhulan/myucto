<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Accounting;

use MyInvoice\Service\Accounting\LegalFormProfileRecommendation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LegalFormProfileRecommendationTest extends TestCase
{
    /** @return iterable<string,array{0:string,1:string}> */
    public static function nonprofitLegalForms(): iterable
    {
        yield 'nadace' => ['117', 'nonprofit'];
        yield 'nadační fond' => ['118', 'nonprofit'];
        yield 'obecně prospěšná společnost' => ['141', 'nonprofit'];
        yield 'ústav' => ['161', 'nonprofit'];
        yield 'spolek' => ['706', 'nonprofit'];
        yield 'pobočný spolek' => ['736', 'nonprofit'];
    }

    #[DataProvider('nonprofitLegalForms')]
    public function testNonprofitLegalFormRecommendsCz504ForDoubleEntry(string $legalForm, string $profile): void
    {
        self::assertSame([
            'entity_profile' => $profile,
            'accounting_framework' => 'cz_504',
            'public_benefit_taxpayer' => null,
        ], LegalFormProfileRecommendation::forLegalForm($legalForm, 'double_entry'));
    }

    public function testSvjIsDistinctAndIsNotPublicBenefitTaxpayer(): void
    {
        self::assertSame([
            'entity_profile' => 'svj',
            'accounting_framework' => 'cz_504',
            'public_benefit_taxpayer' => false,
        ], LegalFormProfileRecommendation::forLegalForm('145', 'double_entry'));
    }

    public function testNonprofitOutsideDoubleEntryDoesNotRecommendFramework(): void
    {
        self::assertSame([
            'entity_profile' => 'nonprofit',
            'accounting_framework' => null,
            'public_benefit_taxpayer' => null,
        ], LegalFormProfileRecommendation::forLegalForm('706', 'tax_evidence'));
    }

    /** @return iterable<string,array{0:?string}> */
    public static function unknownLegalForms(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'commercial company' => ['112'];
        yield 'another 7xx code' => ['711'];
        yield 'unknown 7xx code' => ['799'];
    }

    #[DataProvider('unknownLegalForms')]
    public function testUnknownLegalFormHasNoRecommendation(?string $legalForm): void
    {
        self::assertSame([
            'entity_profile' => 'unknown',
            'accounting_framework' => null,
            'public_benefit_taxpayer' => null,
        ], LegalFormProfileRecommendation::forLegalForm($legalForm, 'double_entry'));
    }
}
