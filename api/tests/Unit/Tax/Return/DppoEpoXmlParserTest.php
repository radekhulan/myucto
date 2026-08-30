<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Service\Tax\Return\DppoEpoXmlParser;
use MyInvoice\Service\Tax\Return\DppoReturnCalculator;
use MyInvoice\Service\Tax\Return\DppoXmlBuilder;
use MyInvoice\Service\Tax\Return\TaxReturnException;
use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\TestCase;

/**
 * Featura A (`private/REAL_data_followup_UX.md` §A) — parser podaného EPO XML DPPDP9.
 * Pokrývá: round-trip s naším vlastním builderem, robustnost napříč verzemi formuláře
 * (04.01 reálný vzor vs. 09.01 náš export — jiná verzePis, stejné atributy kc_ii*),
 * ignorování neznámých atributů (podrobnější rozpis, co náš kalkulátor nepočítá),
 * dodatečné přiznání (V. oddíl iv1/iv2/iv3) a chybové stavy (jiný formulář, битý XML).
 */
final class DppoEpoXmlParserTest extends TestCase
{
    private function sampleSupplier(): array
    {
        return [
            'company_name' => 'Ukázková firma s.r.o.', 'street' => 'Zkušební 123/4',
            'city' => 'Vzorov', 'zip' => '100 00', 'country_iso2' => 'CZ',
            'ic' => '12345678', 'dic' => 'CZ12345678', 'taxpayer_type' => 'po',
            'financial_office_code' => '451', 'cz_nace_code' => '62020',
        ];
    }

    public function testRoundTripWithOwnBuilder(): void
    {
        $calc = (new DppoReturnCalculator())->compute(
            [
                'vh' => 500000,
                'non_deductible_costs' => 20000,
                'disposal_nondeductible_residual' => 10000,
                'depreciation' => ['tax' => 40000, 'accounting' => 100000],
            ],
            ['manual_increase_items' => [['text' => 'Pokuta', 'amount' => 5000]], 'loss_carryforward' => 100000, 'donations' => 50000, 'tax_paid_advances' => 20000],
            TaxConstants::forYear(2025)
        );
        $xml = (new DppoXmlBuilder())->build($this->sampleSupplier(), 2025, $calc)['xml'];

        $parsed = (new DppoEpoXmlParser())->parse($xml);

        self::assertSame('dppdp9', $parsed['form_code']);
        self::assertSame('B', $parsed['dapdpp_forma']);
        self::assertSame('12345678', $parsed['supplier']['ic']);
        self::assertSame('12345678', $parsed['supplier']['dic']); // CZ prefix se v XML nevypisuje
        self::assertSame('2025-01-01', $parsed['zdobd_od']);
        self::assertSame('2025-12-31', $parsed['zdobd_do']);
        self::assertSame(21.0, $parsed['rate_pct']);

        foreach ($calc['lines'] as $l) {
            if ((float) $l['value'] === 0.0 && !in_array((int) $l['line'], [10, 200, 250, 270, 290, 310, 340, 360], true)) {
                continue; // nulové nepovinné řádky builder do XML nevypisuje
            }
            self::assertArrayHasKey((int) $l['line'], $parsed['lines'], 'chybí řádek ' . $l['line']);
            self::assertEqualsWithDelta((float) $l['value'], $parsed['lines'][(int) $l['line']], 0.01, 'řádek ' . $l['line']);
        }
    }

    /** Starší podaný vzor má verzePis="04.01" a atributy mimo náš LINE_ATTR (rozpis). */
    public function testParsesOlderFormVersionAndIgnoresUnknownAttributes(): void
    {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Pisemnost nazevSW="EPO MF ČR" verzeSW="45.9.2">
<DPPDP9 verzePis="04.01">
<VetaD c_nace="620900" typ_dapdpp="M" typ_zo="A" typ_popldpp="1" c_ufo_cil="451" zdobd_od="01.01.2024" dokument="DP9" kc_v_4="-628110" dapdpp_forma="B" k_uladis="DPP" zdobd_do="31.12.2024" />
<VetaP psc="11000" zkrobchjm="Ukázková firma s.r.o." dic="12345678" naz_obce="Praha 1" rod_c="12345678" />
<VetaO kc_ii_360="628110" kc_ii_340="628110" kc_ii230_250="2991500" kc_ii270_280="21" kc_ii260_270="2991000" kc_ii300_310="628110" kc_ii50_40="26000" kc_ii190_170="45000" kc_ii_112="45000" kc_ii200_200="2991500" kc_ii320_330="628110" kc_ii280_290="628110" kc_ii10_10="3010500" kc_ii80_70="26000" kc_ii_220="2991500" d_hospvysl="31.12.2024" />
</DPPDP9>
</Pisemnost>
XML;
        $parsed = (new DppoEpoXmlParser())->parse($xml);

        self::assertSame('04.01', $parsed['verze_pis']);
        self::assertSame('12345678', $parsed['supplier']['ic']);
        // Naše rozpoznané řádky (LINE_ATTR):
        self::assertSame(3010500.0, $parsed['lines'][10]);
        self::assertSame(26000.0, $parsed['lines'][40]);
        self::assertSame(2991500.0, $parsed['lines'][200]);
        self::assertSame(2991000.0, $parsed['lines'][270]);
        self::assertSame(628110.0, $parsed['lines'][290]);
        self::assertSame(628110.0, $parsed['lines'][340]);
        self::assertSame(628110.0, $parsed['lines'][360]);
        // Úkol #41 — ř.112 (paušál na dopravu, doplňková info §23/3c) a ř.170 (souhrn
        // snižujících položek) jsou od úkolu #41 SOUČÁSTÍ LINE_ATTR (řádkový rozpis 1:1
        // s podaným přiznáním), ne jen informativní „extra" bez diffu.
        self::assertArrayHasKey(170, $parsed['lines']);
        self::assertSame(45000.0, $parsed['lines'][170]);
        self::assertArrayHasKey(112, $parsed['lines']);
        self::assertSame(45000.0, $parsed['lines'][112]);
        // kc_ii80_70 (souhrn zvyšujících, ř.70) do LINE_ATTR přibyl 30. 8. 2026: bez něj
        // zkušební EPO vytklo „Řádek 70 II. oddílu není naplněn" i nesouhlas ř.200.
        self::assertArrayHasKey(70, $parsed['lines']);
        self::assertArrayHasKey('kc_ii_220', $parsed['extra']); // má vlastní popisek, ne surové jméno
        self::assertSame('Základ daně před odečtem ztráty a darů (ř. 220)', $parsed['extra']['kc_ii_220']['label']);
    }

    public function testParsesAmendmentBlock(): void
    {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Pisemnost nazevSW="EPO MF ČR" verzeSW="1.0">
<DPPDP9 verzePis="09.01">
<VetaD dokument="DP9" k_uladis="DPP" dapdpp_forma="D" typ_zo="A" c_ufo_cil="451" zdobd_od="01.01.2025" zdobd_do="31.12.2025" d_zjist="15.03.2026" kc_dppiv1="126000" kc_dppiv2="100000" kc_dppiv3="26000" />
<VetaP zkrobchjm="Firma s.r.o." rod_c="12345678" dic="12345678" />
<VetaO kc_ii_340="126000" kc_ii280_290="126000" kc_ii200_200="600000" />
</DPPDP9>
</Pisemnost>
XML;
        $parsed = (new DppoEpoXmlParser())->parse($xml);

        self::assertSame('D', $parsed['dapdpp_forma']);
        self::assertSame(126000.0, $parsed['amendment']['kc_dppiv1']);
        self::assertSame(100000.0, $parsed['amendment']['kc_dppiv2']);
        self::assertSame(26000.0, $parsed['amendment']['kc_dppiv3']);
        self::assertSame('2026-03-15', $parsed['amendment']['d_zjist']);
    }

    public function testRejectsWrongFormType(): void
    {
        $xml = '<?xml version="1.0"?><Pisemnost><DPHDP3 verzePis="01.01"><VetaD dokument="DP3" /></DPHDP3></Pisemnost>';
        $this->expectException(TaxReturnException::class);
        (new DppoEpoXmlParser())->parse($xml);
    }

    public function testRejectsMalformedXml(): void
    {
        $this->expectException(TaxReturnException::class);
        (new DppoEpoXmlParser())->parse('toto neni <xml');
    }

    public function testRejectsEmptyInput(): void
    {
        $this->expectException(TaxReturnException::class);
        (new DppoEpoXmlParser())->parse('   ');
    }

    public function testMissingVetaOThrows(): void
    {
        $xml = '<?xml version="1.0"?><Pisemnost><DPPDP9 verzePis="09.01"><VetaD dokument="DP9" k_uladis="DPP" /></DPPDP9></Pisemnost>';
        try {
            (new DppoEpoXmlParser())->parse($xml);
            self::fail('Očekávána výjimka.');
        } catch (TaxReturnException $e) {
            self::assertSame('wrong_form', $e->errorCode);
        }
    }

    /**
     * Chybí-li ve $calc dopočtená sazba (volající obešel DppoReturnCalculator), builder
     * dosadí sazbu § 21 ZDP z roční sady pro ZADANÝ rok podání, ne natvrdo dnešní default.
     */
    public function testFallbackRateComesFromTaxConstantsForGivenYear(): void
    {
        $calc = ['lines' => [['line' => '200', 'value' => 100000]], 'summary' => []];
        $xml = (new DppoXmlBuilder())->build($this->sampleSupplier(), 2025, $calc)['xml'];

        $parsed = (new DppoEpoXmlParser())->parse($xml);
        self::assertSame((float) TaxConstants::forYear(2025)['corporate_tax_rate'] * 100, $parsed['rate_pct']);
    }
}
