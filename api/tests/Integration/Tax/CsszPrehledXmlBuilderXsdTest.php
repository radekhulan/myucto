<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Service\Tax\Return\CsszPrehledXmlBuilder;
use MyInvoice\Service\Validation\XmlSchemaValidator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Epic DP v2 (issue #19), Fáze 3 — vygenerovaná datová věta ČSSZ „Přehled OSVČ"
 * musí projít validací proti schématu ČSSZ (api/xsd/osvc25.xsd + import baseTypes2.xsd).
 * Soft-skip, když schema není přítomné.
 */
#[Group('integration')]
final class CsszPrehledXmlBuilderXsdTest extends TestCase
{
    private function sampleSupplier(): array
    {
        return [
            'company_name' => 'Jan Novák',
            'street' => 'Krátká 12/3',
            'street_number_pop' => '',
            'street_number_orient' => '',
            'city' => 'Praha',
            'zip' => '110 00',
            'country_iso2' => 'CZ',
            'dic' => 'CZ7801011234', // RČ 780101/1234 → 1978-01-01
            'data_box_id' => 'abcdefg',
            'email' => 'jan@example.com',
            'phone' => '+420 601 002 003',
            'cssz_vsdp' => '1234567890',
            'cssz_ossz_code' => '222',
        ];
    }

    /** Přehled pojistného ve tvaru InsuranceSummaryService::build() (hlavní činnost). */
    private function sampleSummary(bool $secondary = false): array
    {
        // Konzistentní data: VZ = 55 % daňového základu (nad minimem), pojistné 29,2 % VZ.
        return [
            'year' => 2025,
            'tax_base_7' => 480000.0,
            'is_secondary' => $secondary,
            'social' => [
                'assessment_base' => 264000.0, // 0.55 × 480000
                'min_base' => 160000.0,
                'insurance' => 77088.0,        // 0.292 × 264000
                'advances_paid' => 60000.0,
                'balance_due' => 17088.0,
                'monthly_advance' => 6424.0,
                'participates' => true,
            ],
            'rates' => ['social_assessment_pct' => 0.55, 'social_rate' => 0.292],
        ];
    }

    private function buildXml(bool $secondary = false, array $meta = []): array
    {
        return (new CsszPrehledXmlBuilder())->build(
            $this->sampleSupplier(),
            2025,
            $this->sampleSummary($secondary),
            ['productVersion' => '1.0', 'fill_date' => '2026-04-15'] + $meta,
        );
    }

    public function testStructure(): void
    {
        $xml = $this->buildXml()['xml'];
        self::assertStringContainsString('xmlns="http://schemas.cssz.cz/OSVC2025"', $xml);
        self::assertStringContainsString('for="prehledosvc"', $xml);
        self::assertStringContainsString('dep="222"', $xml);
        self::assertStringContainsString('vsdp="1234567890"', $xml);
        self::assertStringContainsString('typ="N"', $xml);
        self::assertStringContainsString('bno="7801011234"', $xml);
        self::assertStringContainsString('den="1978-01-01"', $xml);
        self::assertStringContainsString('<druc>H</druc>', $xml);
        self::assertStringContainsString('pri="480000"', $xml); // daňový základ (atribut pvv)
        self::assertStringContainsString('<poj>77088</poj>', $xml);
        self::assertStringContainsString('<ned>17088</ned>', $xml);
        self::assertStringContainsString('<uvz>264000</uvz>', $xml);
    }

    public function testAcademicTitleUsesDedicatedCsszAttribute(): void
    {
        $supplier = $this->sampleSupplier();
        $supplier['company_name'] = 'MUDr. Josef Novák';
        $xml = (new CsszPrehledXmlBuilder())->build($supplier, 2025, $this->sampleSummary(), [])['xml'];
        self::assertStringContainsString('sur="Novák"', $xml);
        self::assertStringContainsString('fir="Josef"', $xml);
        self::assertStringContainsString('tit="MUDr."', $xml);
    }

    public function testMaximumAssessmentBaseIsReflectedInCsszXml(): void
    {
        $summary = $this->sampleSummary();
        $summary['tax_base_7'] = 10000000.0;
        $summary['social']['max_base'] = 2234736.0;
        $xml = (new CsszPrehledXmlBuilder())->build($this->sampleSupplier(), 2025, $summary, [])['xml'];
        self::assertStringContainsString('<uvz>2234736</uvz>', $xml);
        self::assertStringContainsString('<poj>652543</poj>', $xml);
    }

    /** 400 000 × 0,55 je ve float 220000.00000000003; VZ ani pojistné nesmí vyskočit o korunu. */
    public function testAssessmentBaseRoundingIgnoresFloatNoise(): void
    {
        $summary = $this->sampleSummary();
        $summary['tax_base_7'] = 400000.0;
        $xml = (new CsszPrehledXmlBuilder())->build($this->sampleSupplier(), 2025, $summary, [])['xml'];
        self::assertStringContainsString('<uvz>220000</uvz>', $xml);
        self::assertStringContainsString('<poj>64240</poj>', $xml);
    }

    public function testSecondaryActivityUsesVColumn(): void
    {
        $xml = $this->buildXml(true)['xml'];
        self::assertStringContainsString('<druc>V</druc>', $xml);
        // Vyměřovací základ ve sloupci vedlejší (v), hlavní (h) prázdný.
        self::assertMatchesRegularExpression('/<vvz h="" v="264000"\s*\/>/', $xml);
    }

    public function testPassesXsd(): void
    {
        $validator = new XmlSchemaValidator();
        if (!$validator->hasSchema('osvc25')) {
            self::markTestSkipped('XSD osvc25.xsd není k dispozici.');
        }
        $validation = $validator->validate($this->buildXml()['xml'], 'osvc25');
        self::assertSame('passed', $validation['status'], 'XSD chyby: ' . implode(' | ', $validation['errors']));
        self::assertEmpty($validation['errors']);
    }

    public function testSecondaryPassesXsd(): void
    {
        $validator = new XmlSchemaValidator();
        if (!$validator->hasSchema('osvc25')) {
            self::markTestSkipped('XSD osvc25.xsd není k dispozici.');
        }
        $validation = $validator->validate($this->buildXml(true)['xml'], 'osvc25');
        self::assertSame('passed', $validation['status'], 'XSD chyby: ' . implode(' | ', $validation['errors']));
    }

    /** Vedlejší činnost bez účasti (pod rozhodnou částkou) → VZ, minimum i pojistné 0. */
    public function testNonParticipatingZeroesAll(): void
    {
        $summary = [
            'year' => 2025,
            'tax_base_7' => 40000.0,
            'is_secondary' => true,
            'social' => [
                'assessment_base' => 0.0,
                'min_base' => 133434.0, // konstanta minima (nezaokrouhluje se na 0)
                'insurance' => 0.0,
                'advances_paid' => 0.0,
                'balance_due' => 0.0,
                'participates' => false,
            ],
            'rates' => ['social_assessment_pct' => 0.55, 'social_rate' => 0.292],
        ];
        $built = (new CsszPrehledXmlBuilder())->build($this->sampleSupplier(), 2025, $summary, ['fill_date' => '2026-04-15']);
        // mvz musí být 0 (jinak uvz=0 < mvz poruší cross-kontrolu uvz=max(vvz,mvz)).
        self::assertStringContainsString('<mvz>0</mvz>', $built['xml']);
        self::assertStringContainsString('<uvz>0</uvz>', $built['xml']);
        self::assertStringContainsString('<poj>0</poj>', $built['xml']);
        $validator = new XmlSchemaValidator();
        if ($validator->hasSchema('osvc25')) {
            self::assertSame('passed', $validator->validate($built['xml'], 'osvc25')['status']);
        }
    }

    /**
     * OSVČ zahájená v půli roku (hlavní činnost od července) → mesc/mesv = 6, měsíce
     * leden–červen v hlavc bloku prázdné (neaktivní), červenec–prosinec 'A'.
     */
    public function testMidYearStartUsesRealMonthCounts(): void
    {
        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $active = $m >= 7;
            $months[] = [
                'month' => $m,
                'activity_status' => $active ? 'main' : 'inactive',
                'social_participates' => $active,
                'health_minimum_applies' => $active,
            ];
        }
        $summary = $this->sampleSummary();
        $summary['months'] = $months;

        $built = (new CsszPrehledXmlBuilder())->build(
            $this->sampleSupplier(),
            2025,
            $summary,
            ['productVersion' => '1.0', 'fill_date' => '2026-04-15'],
        );
        $xml = $built['xml'];

        // Hlavní činnost → druc H, mesc/mesv v hlavním sloupci = 6, vedlejší prázdný.
        self::assertStringContainsString('<druc>H</druc>', $xml);
        self::assertMatchesRegularExpression('/<mesc h="6" v=""\s*\/>/', $xml);
        self::assertMatchesRegularExpression('/<mesv h="6" v=""\s*\/>/', $xml);

        // hlavc: leden–červen prázdné, červenec–prosinec 'A'.
        $hlavc = $this->extractBlock($xml, 'hlavc');
        self::assertMatchesRegularExpression('/<m1\s*\/>/', $hlavc);
        self::assertMatchesRegularExpression('/<m6\s*\/>/', $hlavc);
        self::assertStringContainsString('<m7>A</m7>', $hlavc);
        self::assertStringContainsString('<m12>A</m12>', $hlavc);

        // vedc blok existuje, ale všechny měsíce prázdné (žádná vedlejší činnost).
        $vedc = $this->extractBlock($xml, 'vedc');
        self::assertMatchesRegularExpression('/<m7\s*\/>/', $vedc);
        self::assertStringNotContainsString('<m7>A</m7>', $vedc);

        // Bez per-month dat = původní celoroční chování (12 měsíců).
        $wholeYear = $this->buildXml()['xml'];
        self::assertMatchesRegularExpression('/<mesc h="12" v=""\s*\/>/', $wholeYear);

        if (($validator = new XmlSchemaValidator())->hasSchema('osvc25')) {
            self::assertSame('passed', $validator->validate($xml, 'osvc25')['status']);
        }
    }

    private function extractBlock(string $xml, string $tag): string
    {
        self::assertSame(1, preg_match('#<' . $tag . '>(.*?)</' . $tag . '>#s', $xml, $m), "blok $tag nenalezen");
        return $m[1];
    }

    public function testMissingIdentifiersWarn(): void
    {
        $supplier = $this->sampleSupplier();
        unset($supplier['cssz_vsdp'], $supplier['cssz_ossz_code']);
        $built = (new CsszPrehledXmlBuilder())->build($supplier, 2025, $this->sampleSummary(), []);
        self::assertNotEmpty(array_filter($built['warnings'], fn ($w) => str_contains($w, 'OSSZ')));
        self::assertNotEmpty(array_filter($built['warnings'], fn ($w) => str_contains($w, 'variabilní symbol')));
    }

    public function testOpravnyPrehled(): void
    {
        $xml = $this->buildXml(false, ['typ' => 'O', 'opr_date' => '2026-05-01', 'opr_reason' => 'Oprava VZ'])['xml'];
        self::assertStringContainsString('typ="O"', $xml);
        self::assertStringContainsString('datopr="2026-05-01"', $xml);
        self::assertStringContainsString('duvod="Oprava VZ"', $xml);
    }
}
