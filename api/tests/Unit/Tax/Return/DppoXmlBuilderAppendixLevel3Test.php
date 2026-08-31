<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Service\Tax\Return\DppoReturnCalculator;
use MyInvoice\Service\Tax\Return\DppoXmlBuilder;
use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\TestCase;

/**
 * Dodatek 2026-08-31 (pokračování, viz DANE-PLAN.md dodatek 11) — úroveň 3
 * (a místy 4) zbytku rozvahy: `DppoXmlBuilderAppendixDetailTest` doplnila úroveň 2
 * (B./C./D. aktiv, A./C./D. pasiv), ale jakmile ta úroveň začala nést hodnoty, zkušební
 * EPO u `large` vytklo JEJICH vlastní součty o úroveň hlouběji (B.I./B.II./B.III./C.I./
 * C.III./C.IV. aktiv; A.I./A.II./A.III./A.IV./B./C.I./C.II. pasiv). `buildAktivaDetailElements`
 * je čistě rekurzivní podle AKTIVA_DETAIL_C_RADKU — pokrytí je jen datová (mapová) změna.
 * `buildPasivaDetailElements` byla proto stejným způsobem zrekurzivněna (PASIVA_DETAIL_C_RADKU).
 * Hodnoty ověřeny ručně proti reálnému zkušebnímu EPO (dodatek 11).
 */
final class DppoXmlBuilderAppendixLevel3Test extends TestCase
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

    private function sampleCalc(): array
    {
        return (new DppoReturnCalculator())->compute(
            ['vh' => 500000, 'depreciation' => ['tax' => 0, 'accounting' => 0]],
            ['tax_paid_advances' => 0],
            TaxConstants::forYear(2025)
        );
    }

    private function build(array $appendix): array
    {
        return (new DppoXmlBuilder())->build($this->sampleSupplier(), 2025, $this->sampleCalc(), [], $appendix);
    }

    private static function asset(string $rowCode, float $net): array
    {
        return ['row_code' => $rowCode, 'gross' => $net, 'correction' => 0.0, 'net' => $net, 'prev_net' => 0.0];
    }

    private static function liability(string $rowCode, float $amount): array
    {
        return ['row_code' => $rowCode, 'amount' => $amount, 'prev_amount' => 0.0];
    }

    /**
     * B.I. (c_radku 4) — úroveň 3 (B.I.1.–B.I.5., 5/6/9/10/11) i vnořená úroveň 4 pod
     * B.I.2. (subtotal, 7/8) a B.I.5. (subtotal, 12/13) — jedním mechanismem
     * (buildAktivaDetailElements je rekurzivní podle klíčů, žádná změna kódu, jen mapy).
     * Hodnoty schválně beze zbytku dělitelné tisícem, ať test ověří čistě mapování/rekurzi
     * bez interakce se zaokrouhlovací absorpcí (ta má vlastní pokrytí v Appendix*DetailTest).
     */
    public function testAktivaBIGroupLevel3AndNestedLevel4Mapped(): void
    {
        $appendix = [
            'balance_sheet' => ['assets' => [
                self::asset('B.', 700000.0),
                self::asset('B.I.', 700000.0),
                self::asset('B.I.1.', 100000.0),
                self::asset('B.I.2.', 200000.0),
                self::asset('B.I.2.1.', 120000.0),
                self::asset('B.I.2.2.', 80000.0),
                self::asset('B.I.3.', 100000.0),
                self::asset('B.I.4.', 100000.0),
                self::asset('B.I.5.', 200000.0),
                self::asset('B.I.5.1.', 150000.0),
                self::asset('B.I.5.2.', 50000.0),
            ]],
            'income_statement' => ['rows' => [['row_code' => 'I.', 'amount' => 1000.0, 'prev_amount' => 0.0]]],
        ];
        $xml = $this->build($appendix)['xml'];

        self::assertStringContainsString('<VetaUA c_radku="4" kc_brutto="700" kc_korekce="0" kc_netto="700" kc_netto_min="0"/>', $xml);
        self::assertStringContainsString('<VetaUA c_radku="5" kc_brutto="100" kc_korekce="0" kc_netto="100" kc_netto_min="0"/>', $xml);
        self::assertStringContainsString('<VetaUA c_radku="6" kc_brutto="200" kc_korekce="0" kc_netto="200" kc_netto_min="0"/>', $xml);
        self::assertStringContainsString('<VetaUA c_radku="7" kc_brutto="120" kc_korekce="0" kc_netto="120" kc_netto_min="0"/>', $xml);
        self::assertStringContainsString('<VetaUA c_radku="8" kc_brutto="80" kc_korekce="0" kc_netto="80" kc_netto_min="0"/>', $xml);
        self::assertStringContainsString('<VetaUA c_radku="9" kc_brutto="100" kc_korekce="0" kc_netto="100" kc_netto_min="0"/>', $xml);
        self::assertStringContainsString('<VetaUA c_radku="10" kc_brutto="100" kc_korekce="0" kc_netto="100" kc_netto_min="0"/>', $xml);
        self::assertStringContainsString('<VetaUA c_radku="11" kc_brutto="200" kc_korekce="0" kc_netto="200" kc_netto_min="0"/>', $xml);
        self::assertStringContainsString('<VetaUA c_radku="12" kc_brutto="150" kc_korekce="0" kc_netto="150" kc_netto_min="0"/>', $xml);
        self::assertStringContainsString('<VetaUA c_radku="13" kc_brutto="50" kc_korekce="0" kc_netto="50" kc_netto_min="0"/>', $xml);

        // Součty sedí přesně (žádná absorpce potřeba): B.I.1.+…+B.I.5.=B.I.; B.I.2.1.+B.I.2.2.=B.I.2.
        self::assertSame(700, 100 + 200 + 100 + 100 + 200);
        self::assertSame(200, 120 + 80);
    }

    /** B.II./B.III. (14/27) — úroveň 3, čistá mapa bez vnořené úrovně 4 v testu. */
    public function testAktivaBIIAndBIIIGroupsLevel3Mapped(): void
    {
        $appendix = [
            'balance_sheet' => ['assets' => [
                self::asset('B.', 1200000.0),
                self::asset('B.II.', 500000.0),
                self::asset('B.II.1.', 100000.0),
                self::asset('B.II.2.', 100000.0),
                self::asset('B.II.3.', 100000.0),
                self::asset('B.II.4.', 100000.0),
                self::asset('B.II.5.', 100000.0),
                self::asset('B.III.', 700000.0),
                self::asset('B.III.1.', 100000.0),
                self::asset('B.III.2.', 100000.0),
                self::asset('B.III.3.', 100000.0),
                self::asset('B.III.4.', 100000.0),
                self::asset('B.III.5.', 100000.0),
                self::asset('B.III.6.', 100000.0),
                self::asset('B.III.7.', 100000.0),
            ]],
            'income_statement' => ['rows' => [['row_code' => 'I.', 'amount' => 1000.0, 'prev_amount' => 0.0]]],
        ];
        $xml = $this->build($appendix)['xml'];

        self::assertStringContainsString('c_radku="15" kc_brutto="100"', $xml); // B.II.1.
        self::assertStringContainsString('c_radku="18" kc_brutto="100"', $xml); // B.II.2.
        self::assertStringContainsString('c_radku="19" kc_brutto="100"', $xml); // B.II.3.
        self::assertStringContainsString('c_radku="20" kc_brutto="100"', $xml); // B.II.4.
        self::assertStringContainsString('c_radku="24" kc_brutto="100"', $xml); // B.II.5.
        self::assertStringContainsString('c_radku="28" kc_brutto="100"', $xml); // B.III.1.
        self::assertStringContainsString('c_radku="29" kc_brutto="100"', $xml); // B.III.2.
        self::assertStringContainsString('c_radku="30" kc_brutto="100"', $xml); // B.III.3.
        self::assertStringContainsString('c_radku="31" kc_brutto="100"', $xml); // B.III.4.
        self::assertStringContainsString('c_radku="32" kc_brutto="100"', $xml); // B.III.5.
        self::assertStringContainsString('c_radku="33" kc_brutto="100"', $xml); // B.III.6.
        self::assertStringContainsString('c_radku="34" kc_brutto="100"', $xml); // B.III.7.
    }

    /** C.I./C.III./C.IV. (38/68/71) — úroveň 3, včetně vnořené úrovně 4 pod C.I.3. (41). */
    public function testAktivaCIAndCIIIAndCIVGroupsLevel3AndNestedLevel4Mapped(): void
    {
        $appendix = [
            'balance_sheet' => ['assets' => [
                self::asset('C.', 700000.0),
                self::asset('C.I.', 500000.0),
                self::asset('C.I.1.', 100000.0),
                self::asset('C.I.2.', 100000.0),
                self::asset('C.I.3.', 200000.0),
                self::asset('C.I.3.1.', 130000.0),
                self::asset('C.I.3.2.', 70000.0),
                self::asset('C.I.4.', 50000.0),
                self::asset('C.I.5.', 50000.0),
                self::asset('C.III.', 100000.0),
                self::asset('C.III.1.', 60000.0),
                self::asset('C.III.2.', 40000.0),
                self::asset('C.IV.', 100000.0),
                self::asset('C.IV.1.', 30000.0),
                self::asset('C.IV.2.', 70000.0),
            ]],
            'income_statement' => ['rows' => [['row_code' => 'I.', 'amount' => 1000.0, 'prev_amount' => 0.0]]],
        ];
        $xml = $this->build($appendix)['xml'];

        self::assertStringContainsString('c_radku="39" kc_brutto="100"', $xml); // C.I.1.
        self::assertStringContainsString('c_radku="40" kc_brutto="100"', $xml); // C.I.2.
        self::assertStringContainsString('c_radku="41" kc_brutto="200"', $xml); // C.I.3.
        self::assertStringContainsString('c_radku="42" kc_brutto="130"', $xml); // C.I.3.1.
        self::assertStringContainsString('c_radku="43" kc_brutto="70"', $xml);  // C.I.3.2.
        self::assertStringContainsString('c_radku="44" kc_brutto="50"', $xml);  // C.I.4.
        self::assertStringContainsString('c_radku="45" kc_brutto="50"', $xml);  // C.I.5.
        self::assertStringContainsString('c_radku="69" kc_brutto="60"', $xml);  // C.III.1.
        self::assertStringContainsString('c_radku="70" kc_brutto="40"', $xml);  // C.III.2.
        self::assertStringContainsString('c_radku="72" kc_brutto="30"', $xml);  // C.IV.1.
        self::assertStringContainsString('c_radku="73" kc_brutto="70"', $xml);  // C.IV.2.
    }

    /**
     * Zpětná kompatibilita: appendix nese jen B./B.I. (úroveň 1/2, jako dřív) bez úrovně 3
     * — žádné nové VetaUA řádky se nevygenerují, žádné nuly naslepo.
     */
    public function testAktivaLevel3OmittedWhenOnlyLevel2DataPresent(): void
    {
        $appendix = [
            'balance_sheet' => ['assets' => [
                self::asset('B.', 1000000.0),
                self::asset('B.I.', 1000000.0),
            ]],
            'income_statement' => ['rows' => [['row_code' => 'I.', 'amount' => 1000.0, 'prev_amount' => 0.0]]],
        ];
        $xml = $this->build($appendix)['xml'];

        self::assertStringContainsString('c_radku="4"', $xml);
        self::assertStringNotContainsString('c_radku="5"', $xml);
        self::assertStringNotContainsString('c_radku="6"', $xml);
        self::assertStringNotContainsString('c_radku="9"', $xml);
    }

    /**
     * A.I./A.III. (pasiva, 3/15) — úroveň 3, čistá mapa. A.IV. (18) nese v datech jen
     * A.IV.1. (19) — A.IV.2. (21, „Jiný výsledek hospodaření minulých let") v
     * `statement_rows` chybí (skupina (b), viz DANE-PLAN.md dodatek 11), takže se
     * neposílá vůbec — chybějící přispívá součtu nulou, ne chybou.
     */
    public function testPasivaAIAndAIIIAndAIVGroupsLevel3Mapped(): void
    {
        $appendix = [
            'balance_sheet' => ['liabilities' => [
                self::liability('P.A.', 700000.0),
                self::liability('P.A.I.', 300000.0),
                self::liability('P.A.I.1.', 200000.0),
                self::liability('P.A.I.2.', 50000.0),
                self::liability('P.A.I.3.', 50000.0),
                self::liability('P.A.III.', 200000.0),
                self::liability('P.A.III.1.', 120000.0),
                self::liability('P.A.III.2.', 80000.0),
                self::liability('P.A.IV.', 200000.0),
                self::liability('P.A.IV.1.', 200000.0),
            ]],
            'income_statement' => ['rows' => [['row_code' => 'I.', 'amount' => 1000.0, 'prev_amount' => 0.0]]],
        ];
        $xml = $this->build($appendix)['xml'];

        self::assertStringContainsString('<VetaUD kc_sled="200" c_radku="4" kc_min="0"/>', $xml);  // P.A.I.1.
        self::assertStringContainsString('<VetaUD kc_sled="50" c_radku="5" kc_min="0"/>', $xml);   // P.A.I.2.
        self::assertStringContainsString('<VetaUD kc_sled="50" c_radku="6" kc_min="0"/>', $xml);   // P.A.I.3.
        self::assertStringContainsString('<VetaUD kc_sled="120" c_radku="16" kc_min="0"/>', $xml); // P.A.III.1.
        self::assertStringContainsString('<VetaUD kc_sled="80" c_radku="17" kc_min="0"/>', $xml);  // P.A.III.2.
        self::assertStringContainsString('<VetaUD kc_sled="200" c_radku="19" kc_min="0"/>', $xml); // P.A.IV.1.
        self::assertStringNotContainsString('c_radku="21"', $xml); // A.IV.2. — chybí v datech, neposílá se
    }

    /** A.II. (7) — úroveň 3 (A.II.1./A.II.2., 8/9) i vnořená úroveň 4 pod A.II.2. (10/11). */
    public function testPasivaAIIGroupNestedLevel4Mapped(): void
    {
        $appendix = [
            'balance_sheet' => ['liabilities' => [
                self::liability('P.A.', 300000.0),
                self::liability('P.A.II.', 300000.0),
                self::liability('P.A.II.1.', 100000.0),
                self::liability('P.A.II.2.', 200000.0),
                self::liability('P.A.II.2.1.', 120000.0),
                self::liability('P.A.II.2.2.', 80000.0),
            ]],
            'income_statement' => ['rows' => [['row_code' => 'I.', 'amount' => 1000.0, 'prev_amount' => 0.0]]],
        ];
        $xml = $this->build($appendix)['xml'];

        self::assertStringContainsString('<VetaUD kc_sled="100" c_radku="8" kc_min="0"/>', $xml);   // P.A.II.1.
        self::assertStringContainsString('<VetaUD kc_sled="200" c_radku="9" kc_min="0"/>', $xml);   // P.A.II.2.
        self::assertStringContainsString('<VetaUD kc_sled="120" c_radku="10" kc_min="0"/>', $xml);  // P.A.II.2.1.
        self::assertStringContainsString('<VetaUD kc_sled="80" c_radku="11" kc_min="0"/>', $xml);   // P.A.II.2.2.
    }

    /**
     * B. (Rezervy, 25) — úroveň 3 pod P.B. (27/28/29). B.1. (26, „Rezerva na důchody a
     * podobné závazky") v `statement_rows` chybí (skupina (b)) — jen 3 ze 4 oficiálních
     * podřádků se posílají, chybějící přispívá nulou.
     */
    public function testPasivaBGroupRezervyMapped(): void
    {
        $appendix = [
            'balance_sheet' => ['liabilities' => [
                self::liability('P.B.', 90000.0),
                self::liability('P.B.2.', 30000.0),
                self::liability('P.B.3.', 30000.0),
                self::liability('P.B.4.', 30000.0),
            ]],
            'income_statement' => ['rows' => [['row_code' => 'I.', 'amount' => 1000.0, 'prev_amount' => 0.0]]],
        ];
        $xml = $this->build($appendix)['xml'];

        self::assertStringContainsString('<VetaUD kc_sled="90" c_radku="25" kc_min="0"/>', $xml);  // P.B. samotné
        self::assertStringContainsString('<VetaUD kc_sled="30" c_radku="27" kc_min="0"/>', $xml);  // P.B.2.
        self::assertStringContainsString('<VetaUD kc_sled="30" c_radku="28" kc_min="0"/>', $xml);  // P.B.3.
        self::assertStringContainsString('<VetaUD kc_sled="30" c_radku="29" kc_min="0"/>', $xml);  // P.B.4.
        self::assertStringNotContainsString('c_radku="26"', $xml); // B.1. — chybí v datech
    }

    /**
     * C.I. (31) — úroveň 3, jen 6 z 9 oficiálních podřádků (C.I.4./C.I.5./C.I.7. v datech
     * chybí, skupina (b)). C.II. (46) — úroveň 3 (7 z 8 podřádků, C.II.7. chybí) i vnořená
     * úroveň 4 pod C.II.8. (57–63, všech 7 v datech je).
     */
    public function testPasivaCIAndCIIGroupsWithNestedLevel4Mapped(): void
    {
        $appendix = [
            'balance_sheet' => ['liabilities' => [
                self::liability('P.C.', 1100000.0),
                self::liability('P.C.I.', 600000.0),
                self::liability('P.C.I.1.', 100000.0),
                self::liability('P.C.I.2.', 100000.0),
                self::liability('P.C.I.3.', 100000.0),
                self::liability('P.C.I.6.', 100000.0),
                self::liability('P.C.I.8.', 100000.0),
                self::liability('P.C.I.9.', 100000.0),
                self::liability('P.C.II.', 500000.0),
                self::liability('P.C.II.1.', 50000.0),
                self::liability('P.C.II.2.', 50000.0),
                self::liability('P.C.II.3.', 50000.0),
                self::liability('P.C.II.4.', 50000.0),
                self::liability('P.C.II.5.', 50000.0),
                self::liability('P.C.II.6.', 50000.0),
                self::liability('P.C.II.8.', 200000.0),
                self::liability('P.C.II.8.1.', 30000.0),
                self::liability('P.C.II.8.2.', 30000.0),
                self::liability('P.C.II.8.3.', 30000.0),
                self::liability('P.C.II.8.4.', 30000.0),
                self::liability('P.C.II.8.5.', 30000.0),
                self::liability('P.C.II.8.6.', 30000.0),
                self::liability('P.C.II.8.7.', 20000.0),
            ]],
            'income_statement' => ['rows' => [['row_code' => 'I.', 'amount' => 1000.0, 'prev_amount' => 0.0]]],
        ];
        $xml = $this->build($appendix)['xml'];

        self::assertStringContainsString('kc_sled="100" c_radku="32" kc_min="0"', $xml); // P.C.I.1.
        self::assertStringContainsString('kc_sled="100" c_radku="35" kc_min="0"', $xml); // P.C.I.2.
        self::assertStringContainsString('kc_sled="100" c_radku="36" kc_min="0"', $xml); // P.C.I.3.
        self::assertStringContainsString('kc_sled="100" c_radku="39" kc_min="0"', $xml); // P.C.I.6.
        self::assertStringContainsString('kc_sled="100" c_radku="41" kc_min="0"', $xml); // P.C.I.8.
        self::assertStringContainsString('kc_sled="100" c_radku="42" kc_min="0"', $xml); // P.C.I.9.
        self::assertStringNotContainsString('c_radku="37"', $xml); // C.I.4. — chybí v datech
        self::assertStringNotContainsString('c_radku="38"', $xml); // C.I.5. — chybí v datech
        self::assertStringNotContainsString('c_radku="40"', $xml); // C.I.7. — chybí v datech

        self::assertStringContainsString('kc_sled="50" c_radku="47" kc_min="0"', $xml);  // P.C.II.1.
        self::assertStringContainsString('kc_sled="50" c_radku="50" kc_min="0"', $xml);  // P.C.II.2.
        self::assertStringContainsString('kc_sled="200" c_radku="56" kc_min="0"', $xml); // P.C.II.8.
        self::assertStringContainsString('kc_sled="30" c_radku="57" kc_min="0"', $xml);  // P.C.II.8.1.
        self::assertStringContainsString('kc_sled="20" c_radku="63" kc_min="0"', $xml);  // P.C.II.8.7.
        self::assertStringNotContainsString('c_radku="55"', $xml); // C.II.7. — chybí v datech
    }
}
