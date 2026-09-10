<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Service\Tax\Return\DpfoEpoBusinessValidator;
use MyInvoice\Service\Tax\Return\DpfoReturnCalculator;
use MyInvoice\Service\Tax\Return\DpfoXmlBuilder;
use MyInvoice\Service\Tax\Return\Section10Codebook;
use MyInvoice\Service\Tax\Return\TaxReturnService;
use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Číselník ostatních příjmů § 10 (P-2) a zrušený jednořádkový agregát (P-3),
 * viz private/DANE-PLAN.md.
 *
 * Před opravou aplikace u položky § 10 vedla jen volný popis: `kod_dr_prij10` (A–H)
 * ani `kod10` (P/S/Z/N) se do XML nedostávaly a zkušební EPO na každou položku hlásilo
 * „na ř. N ve sloupci 1 tabulky 2. oddílu není vyplněn kód druhu příjmu podle § 10 ZDP".
 * Legacy agregát `s10_other` se navíc do Přílohy č. 2 nezapisoval vůbec.
 *
 * `sanitizeInputs()` je čistá privátní metoda — volá se reflexí bez konstruktoru,
 * stejný trik jako {@see TaxReturnServiceSanitizeInputsBankAndPuzTest}.
 */
final class DpfoSection10CodebookTest extends TestCase
{
    /** @return array<string,mixed> */
    private function sampleSupplier(): array
    {
        return [
            'id' => 1,
            'company_name' => 'Jan Novák',
            'street' => 'Krátká 12/3',
            'city' => 'Praha',
            'zip' => '110 00',
            'country_iso2' => 'CZ',
            'ic' => '87654321',
            'dic' => 'CZ7801011234',
            'taxpayer_type' => 'fo',
            'financial_office_code' => '451',
            'cz_nace_code' => '62020',
        ];
    }

    /** @param array<string,mixed> $inputs */
    private function build(array $inputs, array $data = []): array
    {
        $calc = (new DpfoReturnCalculator())->compute(
            ['s7_base' => 0, 'expense_mode' => 'pausal', 'expense_rate' => 60] + $data,
            $inputs + ['tax_paid_advances' => 0],
            [],
            TaxConstants::forYear(2025),
        );
        return (new DpfoXmlBuilder())->build($this->sampleSupplier(), 2025, $calc, []);
    }

    /** @return array<string,mixed> */
    private function sanitizeFo(array $inputs): array
    {
        $service = (new ReflectionClass(TaxReturnService::class))->newInstanceWithoutConstructor();
        return (new ReflectionMethod($service, 'sanitizeInputs'))->invoke($service, 'fo', $inputs, 'radne');
    }

    // ── Číselník ────────────────────────────────────────────────────────────

    public function testCodebookHasEightKindsAndFourCodes(): void
    {
        self::assertSame(['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'], array_keys(Section10Codebook::kinds()));
        self::assertSame(['P', 'S', 'Z', 'N'], array_keys(Section10Codebook::codes()));
        self::assertSame('Bezúplatné příjmy', Section10Codebook::kindLabel('G'));
        self::assertNull(Section10Codebook::kindLabel('X'));
    }

    public function testCodebookNormalizationRejectsUnknownValuesInsteadOfGuessing(): void
    {
        self::assertSame('B', Section10Codebook::normalizeKind('b'));
        self::assertSame('', Section10Codebook::normalizeKind('Prodej nemovitosti'));
        self::assertSame('', Section10Codebook::normalizeKind('I'));
        self::assertSame('Z', Section10Codebook::normalizeCode(' z '));
        self::assertSame('', Section10Codebook::normalizeCode('G'));
    }

    // ── P-2: kódy v XML ─────────────────────────────────────────────────────

    public function testKindCodeAndCodeReachVetaJ(): void
    {
        $result = $this->build(['s10_items' => [
            ['kind_code' => 'B', 'code' => 'S', 'text' => 'Prodej rodinného domu', 'income' => 3000000, 'expenses' => 2000000],
        ]]);
        $xml = $result['xml'];

        self::assertStringContainsString('kod_dr_prij10="B"', $xml);
        self::assertStringContainsString('kod10="S"', $xml);
        self::assertStringContainsString('druh_prij10="Prodej rodinného domu"', $xml);
        self::assertSame(
            [],
            array_values(array_filter($result['warnings'], static fn (string $w): bool => str_contains($w, 'kod_dr_prij10'))),
        );
    }

    /**
     * Popis položky je samostatný údaj (sloupec 1 za písmenem). Dokud se `kind_code`
     * používal jako popisek, sanitizovaná položka měla `kind_code = ''`, `??` ho vzalo
     * jako vyplněnou hodnotu a `druh_prij10` se do XML nedostal NIKDY.
     */
    public function testDescriptionComesFromTextNotFromKindCode(): void
    {
        $xml = $this->build(['s10_items' => [
            ['kind_code' => 'C', 'code' => '', 'text' => 'Prodej ojetého vozu', 'income' => 120000, 'expenses' => 80000],
        ]])['xml'];

        self::assertStringContainsString('druh_prij10="Prodej ojetého vozu"', $xml);
        self::assertStringNotContainsString('druh_prij10="C"', $xml);
    }

    /** Zpětná kompatibilita: uložená položka bez číselníku projde, jen se varuje. */
    public function testLegacyItemWithoutKindCodeStillBuildsWithWarning(): void
    {
        $result = $this->build(['s10_items' => [
            ['text' => 'Příležitostná činnost', 'income' => 40000, 'expenses' => 0],
        ]]);

        self::assertStringContainsString('<VetaJ', $result['xml']);
        self::assertStringNotContainsString('kod_dr_prij10=', $result['xml']);
        self::assertNotEmpty(array_filter(
            $result['warnings'],
            static fn (string $w): bool => str_contains($w, 'kód druhu příjmu'),
        ));
    }

    public function testUnknownKindCodeIsNotGuessedIntoXml(): void
    {
        $result = $this->build(['s10_items' => [
            ['kind_code' => 'Prodej nemovitosti', 'text' => 'Prodej pozemku', 'income' => 500000, 'expenses' => 0],
        ]]);

        self::assertStringNotContainsString('kod_dr_prij10=', $result['xml']);
        self::assertStringContainsString('druh_prij10="Prodej pozemku"', $result['xml']);
    }

    public function testCodeNWithoutKindGProducesWarning(): void
    {
        $result = $this->build(['s10_items' => [
            ['kind_code' => 'B', 'code' => 'N', 'text' => 'Prodej pozemku', 'income' => 500000, 'expenses' => 0],
        ]]);

        self::assertStringContainsString('kod10="N"', $result['xml']);
        self::assertNotEmpty(array_filter(
            $result['warnings'],
            static fn (string $w): bool => str_contains($w, 'kód „N"'),
        ));
    }

    // ── P-2: ukládání vstupů ────────────────────────────────────────────────

    public function testSanitizeKeepsCodebookLettersAndDropsUnknownOnes(): void
    {
        $items = $this->sanitizeFo(['s10_items' => [
            ['kind_code' => 'd', 'code' => 'z', 'text' => 'Prodej akcií', 'income' => 90000, 'expenses' => 10000],
            ['kind_code' => 'Q', 'code' => 'Q', 'text' => 'Něco jiného', 'income' => 1000, 'expenses' => 0],
        ]])['s10_items'];

        self::assertSame('D', $items[0]['kind_code']);
        self::assertSame('Z', $items[0]['code']);
        self::assertSame('', $items[1]['kind_code']);
        self::assertSame('', $items[1]['code']);
    }

    /** Volný popis uložený dřív pod `kind_code` se zavedením číselníku nesmí ztratit. */
    public function testSanitizeRescuesLegacyFreeTextFromKindCode(): void
    {
        $items = $this->sanitizeFo(['s10_items' => [
            ['kind_code' => 'Příjem z prodeje chaty', 'income' => 800000, 'expenses' => 600000],
        ]])['s10_items'];

        self::assertSame('', $items[0]['kind_code']);
        self::assertSame('Příjem z prodeje chaty', $items[0]['text']);
    }

    // ── P-3: legacy jednořádkový vstup ──────────────────────────────────────

    public function testLegacyAggregateBecomesSingleItemOnSave(): void
    {
        $clean = $this->sanitizeFo(['s10_other' => ['income' => 60000, 'expenses' => 20000]]);

        self::assertSame(['income' => 0.0, 'expenses' => 0.0], $clean['s10_other']);
        self::assertCount(1, $clean['s10_items']);
        self::assertSame(60000.0, $clean['s10_items'][0]['income']);
        self::assertSame(20000.0, $clean['s10_items'][0]['expenses']);
        self::assertSame(Section10Codebook::LEGACY_ITEM_TEXT, $clean['s10_items'][0]['text']);
        self::assertSame('', $clean['s10_items'][0]['kind_code']);
    }

    public function testLegacyAggregateIsAppendedNextToExistingItems(): void
    {
        $clean = $this->sanitizeFo([
            's10_other' => ['income' => 5000, 'expenses' => 0],
            's10_items' => [['kind_code' => 'A', 'text' => 'Příležitostná činnost', 'income' => 20000, 'expenses' => 0]],
        ]);

        self::assertCount(2, $clean['s10_items']);
        self::assertSame('A', $clean['s10_items'][0]['kind_code']);
        self::assertSame(5000.0, $clean['s10_items'][1]['income']);
    }

    public function testEmptyLegacyAggregateCreatesNoItem(): void
    {
        $clean = $this->sanitizeFo(['s10_other' => ['income' => 0, 'expenses' => 0]]);
        self::assertSame([], $clean['s10_items']);
    }

    /**
     * Dřív se z legacy agregátu Příloha č. 2 vůbec nepostavila (VetaJ chyběla, souhrn
     * by EPO odmítlo třemi křížovými výtkami) — vyplněné číslo skončilo v tichu.
     */
    public function testConvertedLegacyAggregateReachesAppendix2(): void
    {
        $clean = $this->sanitizeFo(['s10_other' => ['income' => 60000, 'expenses' => 20000]]);
        $result = $this->build(['s10_items' => $clean['s10_items']]);

        self::assertStringContainsString('priloha2="1"', $result['xml']);
        self::assertStringContainsString('kc_prij10="60000"', $result['xml']);
        self::assertStringContainsString('kc_vyd10="20000"', $result['xml']);
        self::assertStringContainsString('kc_zd10p="40000"', $result['xml']);
        self::assertSame(1, substr_count($result['xml'], '<VetaJ'));
    }

    // ── Finalizační brána ───────────────────────────────────────────────────

    public function testValidatorBlocksItemWithoutKindCode(): void
    {
        $errors = (new DpfoEpoBusinessValidator())->validate(
            ['s10_items' => [['kind_code' => '', 'text' => 'Prodej auta', 'income' => 100000, 'allowed_expenses' => 0]]],
            [],
        );

        self::assertContains('DPFO: každý příjem §10 musí mít vybraný kód druhu příjmu podle § 10 odst. 1 zákona (A–H).', $errors);
    }

    public function testValidatorAcceptsFullyFilledItem(): void
    {
        $errors = (new DpfoEpoBusinessValidator())->validate(
            ['s10_items' => [['kind_code' => 'C', 'code' => '', 'text' => 'Prodej auta', 'income' => 100000, 'allowed_expenses' => 0]]],
            [],
        );

        self::assertSame([], $errors);
    }

    /** Chybějící `kod10` (P/S/Z/N) je v pořádku — je opravdu volitelný. */
    public function testValidatorDoesNotRequireOptionalCode(): void
    {
        $errors = (new DpfoEpoBusinessValidator())->validate(
            ['s10_items' => [['kind_code' => 'A', 'text' => 'Příležitostný příjem', 'income' => 30000, 'allowed_expenses' => 0]]],
            [],
        );

        self::assertNotContains('DPFO: každý příjem §10 musí mít vybraný kód druhu příjmu podle § 10 odst. 1 zákona (A–H).', $errors);
    }

    public function testValidatorStillRequiresSeparateDescription(): void
    {
        $errors = (new DpfoEpoBusinessValidator())->validate(
            ['s10_items' => [['kind_code' => 'A', 'text' => '', 'income' => 30000, 'allowed_expenses' => 0]]],
            [],
        );

        self::assertContains('DPFO: každý příjem §10 musí mít uveden samostatný druh.', $errors);
    }
}
