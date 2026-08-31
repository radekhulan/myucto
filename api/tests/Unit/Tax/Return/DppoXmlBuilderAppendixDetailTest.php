<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Service\Tax\Return\DppoReturnCalculator;
use MyInvoice\Service\Tax\Return\DppoXmlBuilder;
use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\TestCase;

/**
 * Doplněk 2026-08-31: úroveň 2 přílohy účetní závěrky (VetaUA B.I.–D.3., VetaUD
 * A.I.–D.2., chybějící řádky VZZ II./B./C./III./IV./G./V./H./I.n/J.) a zaokrouhlovací
 * absorpce součtů (jádro úkolu — viz absorbRoundingDiff v DppoXmlBuilder). Zkušební EPO
 * 31. 8. 2026 vytklo tyhle mezisoučty jako „Hodnota řádku X se nerovná součtu"; lokální
 * XSD to nezachytí (netestuje hodnoty), proto jsou hodnoty ověřeny ručně proti reálnému
 * zkušebnímu EPO — viz private/AUDIT-DPPO-XML.md dodatek 10.
 */
final class DppoXmlBuilderAppendixDetailTest extends TestCase
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

    /**
     * B.I./B.II./B.III. (c_radku 4/14/27) — hodnoty 333 500 Kč × 3 se každá zvlášť
     * zaokrouhlí na 334 tis. (333 500 → 333,5 → 334), ale rodič B. (1 000 500 Kč) na
     * 1001 tis. — součet dětí (1002) by bez absorpce neseděl na rodiče. Rozdíl (−1) jde
     * do první (dle pořadí číselníku) složky s největší abs. hodnotou = B.I., která navíc
     * dostane odpovídající −1 na brutto, aby uvnitř řádku dál platilo netto=brutto−korekce.
     */
    public function testAktivaBGroupMappedAndAbsorbsRoundingDrift(): void
    {
        $appendix = [
            'balance_sheet' => ['assets' => [
                ['row_code' => 'B.', 'gross' => 1000500.0, 'correction' => 0.0, 'net' => 1000500.0, 'prev_net' => 0.0],
                ['row_code' => 'B.I.', 'gross' => 333500.0, 'correction' => 0.0, 'net' => 333500.0, 'prev_net' => 0.0],
                ['row_code' => 'B.II.', 'gross' => 333500.0, 'correction' => 0.0, 'net' => 333500.0, 'prev_net' => 0.0],
                ['row_code' => 'B.III.', 'gross' => 333500.0, 'correction' => 0.0, 'net' => 333500.0, 'prev_net' => 0.0],
            ]],
            'income_statement' => ['rows' => [['row_code' => 'I.', 'amount' => 1000.0, 'prev_amount' => 0.0]]],
        ];
        $xml = $this->build($appendix)['xml'];

        self::assertStringContainsString('<VetaUA c_radku="3" kc_brutto="1001" kc_korekce="0" kc_netto="1001" kc_netto_min="0"/>', $xml);
        self::assertStringContainsString('<VetaUA c_radku="4" kc_brutto="333" kc_korekce="0" kc_netto="333" kc_netto_min="0"/>', $xml);
        self::assertStringContainsString('<VetaUA c_radku="14" kc_brutto="334" kc_korekce="0" kc_netto="334" kc_netto_min="0"/>', $xml);
        self::assertStringContainsString('<VetaUA c_radku="27" kc_brutto="334" kc_korekce="0" kc_netto="334" kc_netto_min="0"/>', $xml);

        // Součet dětí přesně sedí na rodiče (chyba EPO „B.I.+B.II.+B.III." by jinak přetrvala).
        self::assertSame(1001, 333 + 334 + 334);
    }

    /** C.I.–C.IV. (38/46/68/71) a D.1.–D.3. (75/76/77) — čistá mapa beze zaokrouhlovací drift. */
    public function testAktivaCAndDGroupsMappedCorrectly(): void
    {
        $appendix = [
            'balance_sheet' => ['assets' => [
                ['row_code' => 'C.', 'gross' => 2000000.0, 'correction' => 0.0, 'net' => 2000000.0, 'prev_net' => 0.0],
                ['row_code' => 'C.I.', 'gross' => 500000.0, 'correction' => 0.0, 'net' => 500000.0, 'prev_net' => 0.0],
                ['row_code' => 'C.II.', 'gross' => 500000.0, 'correction' => 0.0, 'net' => 500000.0, 'prev_net' => 0.0],
                ['row_code' => 'C.III.', 'gross' => 500000.0, 'correction' => 0.0, 'net' => 500000.0, 'prev_net' => 0.0],
                ['row_code' => 'C.IV.', 'gross' => 500000.0, 'correction' => 0.0, 'net' => 500000.0, 'prev_net' => 0.0],
                ['row_code' => 'D.', 'gross' => 300000.0, 'correction' => 0.0, 'net' => 300000.0, 'prev_net' => 0.0],
                ['row_code' => 'D.1.', 'gross' => 100000.0, 'correction' => 0.0, 'net' => 100000.0, 'prev_net' => 0.0],
                ['row_code' => 'D.2.', 'gross' => 100000.0, 'correction' => 0.0, 'net' => 100000.0, 'prev_net' => 0.0],
                ['row_code' => 'D.3.', 'gross' => 100000.0, 'correction' => 0.0, 'net' => 100000.0, 'prev_net' => 0.0],
            ]],
            'income_statement' => ['rows' => [['row_code' => 'I.', 'amount' => 1000.0, 'prev_amount' => 0.0]]],
        ];
        $xml = $this->build($appendix)['xml'];

        self::assertStringContainsString('<VetaUA c_radku="38" kc_brutto="500" kc_korekce="0" kc_netto="500" kc_netto_min="0"/>', $xml);
        self::assertStringContainsString('<VetaUA c_radku="46" kc_brutto="500" kc_korekce="0" kc_netto="500" kc_netto_min="0"/>', $xml);
        self::assertStringContainsString('<VetaUA c_radku="68" kc_brutto="500" kc_korekce="0" kc_netto="500" kc_netto_min="0"/>', $xml);
        self::assertStringContainsString('<VetaUA c_radku="71" kc_brutto="500" kc_korekce="0" kc_netto="500" kc_netto_min="0"/>', $xml);
        self::assertStringContainsString('<VetaUA c_radku="75" kc_brutto="100" kc_korekce="0" kc_netto="100" kc_netto_min="0"/>', $xml);
        self::assertStringContainsString('<VetaUA c_radku="76" kc_brutto="100" kc_korekce="0" kc_netto="100" kc_netto_min="0"/>', $xml);
        self::assertStringContainsString('<VetaUA c_radku="77" kc_brutto="100" kc_korekce="0" kc_netto="100" kc_netto_min="0"/>', $xml);
    }

    /**
     * Bez úrovně 2 v datech (appendix nese jen souhrnné řádky, jako dřív) se žádné nové
     * VetaUA řádky nevygenerují — zpětná kompatibilita, žádné nuly naslepo.
     */
    public function testAktivaDetailOmittedWhenNoLevel2Data(): void
    {
        $appendix = [
            'balance_sheet' => ['assets' => [
                ['row_code' => 'B.', 'gross' => 1000000.0, 'correction' => 0.0, 'net' => 1000000.0, 'prev_net' => 0.0],
            ]],
            'income_statement' => ['rows' => [['row_code' => 'I.', 'amount' => 1000.0, 'prev_amount' => 0.0]]],
        ];
        $xml = $this->build($appendix)['xml'];

        self::assertStringContainsString('<VetaUA c_radku="3"', $xml);
        self::assertStringNotContainsString('c_radku="4"', $xml);
        self::assertStringNotContainsString('c_radku="14"', $xml);
        self::assertStringNotContainsString('c_radku="27"', $xml);
    }

    /**
     * A.I.–A.VI. (pasiva, c_radku 3/7/15/18/22/23) — stejná past jako u aktiv (dva
     * −0,5→+1 zaokrouhlené řádky, rodič A. na celý tisíc), ale 'P.A.V.' (Výsledek
     * hospodaření běžného účetního období) je z absorpce VYLOUČEN a musí zůstat přesně
     * na nezávisle zaokrouhlené hodnotě — jinak by se rozjel křížový test proti VH z VZZ.
     */
    public function testPasivaAGroupAbsorbsDriftWithoutTouchingPAV(): void
    {
        $appendix = [
            'balance_sheet' => ['liabilities' => [
                ['row_code' => 'P.A.', 'amount' => 401000.0, 'prev_amount' => 0.0],
                ['row_code' => 'P.A.I.', 'amount' => 100500.0, 'prev_amount' => 0.0],
                ['row_code' => 'P.A.IV.', 'amount' => 100500.0, 'prev_amount' => 0.0],
                ['row_code' => 'P.A.V.', 'amount' => 200000.0, 'prev_amount' => 0.0],
            ]],
            'income_statement' => ['rows' => [
                ['row_code' => 'VH', 'amount' => 200000.0, 'prev_amount' => 0.0],
            ]],
        ];
        $xml = $this->build($appendix)['xml'];

        // Rodič A. beze změny (401), P.A.V. beze změny (200 = stejná hodnota jako VH).
        self::assertStringContainsString('<VetaUD kc_sled="401" c_radku="2" kc_min="0"/>', $xml);
        self::assertStringContainsString('<VetaUD kc_sled="200" c_radku="22" kc_min="0"/>', $xml);
        self::assertStringContainsString('<VetaUB c_radku="55" kc_min="0" kc_sled="200"/>', $xml); // VH

        // P.A.I. (101→100) absorbovalo rozdíl, P.A.IV. beze změny (101) — 100+101+200=401.
        self::assertStringContainsString('<VetaUD kc_sled="100" c_radku="3" kc_min="0"/>', $xml);
        self::assertStringContainsString('<VetaUD kc_sled="101" c_radku="18" kc_min="0"/>', $xml);
    }

    /** C.I.+C.II. (31/46) sedí přímo na existující řádek C. (30); D.1.+D.2. (65/66) na D. (64). */
    public function testPasivaCAndDGroupsMappedCorrectly(): void
    {
        $appendix = [
            'balance_sheet' => ['liabilities' => [
                ['row_code' => 'P.C.', 'amount' => 900000.0, 'prev_amount' => 0.0],
                ['row_code' => 'P.C.I.', 'amount' => 400000.0, 'prev_amount' => 0.0],
                ['row_code' => 'P.C.II.', 'amount' => 500000.0, 'prev_amount' => 0.0],
                ['row_code' => 'P.D.', 'amount' => 50000.0, 'prev_amount' => 0.0],
                ['row_code' => 'P.D.1.', 'amount' => 20000.0, 'prev_amount' => 0.0],
                ['row_code' => 'P.D.2.', 'amount' => 30000.0, 'prev_amount' => 0.0],
            ]],
            'income_statement' => ['rows' => [['row_code' => 'I.', 'amount' => 1000.0, 'prev_amount' => 0.0]]],
        ];
        $xml = $this->build($appendix)['xml'];

        self::assertStringContainsString('<VetaUD kc_sled="900" c_radku="30" kc_min="0"/>', $xml);
        self::assertStringContainsString('<VetaUD kc_sled="400" c_radku="31" kc_min="0"/>', $xml);
        self::assertStringContainsString('<VetaUD kc_sled="500" c_radku="46" kc_min="0"/>', $xml);
        self::assertStringContainsString('<VetaUD kc_sled="50" c_radku="64" kc_min="0"/>', $xml);
        self::assertStringContainsString('<VetaUD kc_sled="20" c_radku="65" kc_min="0"/>', $xml);
        self::assertStringContainsString('<VetaUD kc_sled="30" c_radku="66" kc_min="0"/>', $xml);
    }

    /** Nově doplněné řádky VZZ (II./B./C./III./IV./G./V./H./I.n/J.) — čistá mapa, žádná drift. */
    public function testVzzNewRowsMappedToCorrectCRadku(): void
    {
        $appendix = [
            'balance_sheet' => ['assets' => [
                ['row_code' => 'B.', 'gross' => 1000.0, 'correction' => 0.0, 'net' => 1000.0, 'prev_net' => 0.0],
            ]],
            'income_statement' => ['rows' => [
                ['row_code' => 'II.', 'amount' => 111000.0, 'prev_amount' => 0.0],
                ['row_code' => 'B.', 'amount' => -22000.0, 'prev_amount' => 0.0],
                ['row_code' => 'C.', 'amount' => -33000.0, 'prev_amount' => 0.0],
                ['row_code' => 'III.', 'amount' => 44000.0, 'prev_amount' => 0.0],
                ['row_code' => 'IV.', 'amount' => 55000.0, 'prev_amount' => 0.0],
                ['row_code' => 'G.', 'amount' => 66000.0, 'prev_amount' => 0.0],
                ['row_code' => 'V.', 'amount' => 77000.0, 'prev_amount' => 0.0],
                ['row_code' => 'H.', 'amount' => 88000.0, 'prev_amount' => 0.0],
                ['row_code' => 'I.n', 'amount' => 99000.0, 'prev_amount' => 0.0],
                ['row_code' => 'J.', 'amount' => 12000.0, 'prev_amount' => 0.0],
            ]],
        ];
        $xml = $this->build($appendix)['xml'];

        self::assertStringContainsString('<VetaUB c_radku="2" kc_min="0" kc_sled="111"/>', $xml);
        self::assertStringContainsString('<VetaUB c_radku="7" kc_min="0" kc_sled="-22"/>', $xml);
        self::assertStringContainsString('<VetaUB c_radku="8" kc_min="0" kc_sled="-33"/>', $xml);
        self::assertStringContainsString('<VetaUB c_radku="20" kc_min="0" kc_sled="44"/>', $xml);
        self::assertStringContainsString('<VetaUB c_radku="31" kc_min="0" kc_sled="55"/>', $xml);
        self::assertStringContainsString('<VetaUB c_radku="34" kc_min="0" kc_sled="66"/>', $xml);
        self::assertStringContainsString('<VetaUB c_radku="35" kc_min="0" kc_sled="77"/>', $xml);
        self::assertStringContainsString('<VetaUB c_radku="38" kc_min="0" kc_sled="88"/>', $xml);
        self::assertStringContainsString('<VetaUB c_radku="42" kc_min="0" kc_sled="99"/>', $xml);
        self::assertStringContainsString('<VetaUB c_radku="43" kc_min="0" kc_sled="12"/>', $xml);
    }

    /**
     * Provozní výsledek hospodaření (PVH, c_radku 30): I.+II. (100 500 + 100 500 Kč) se
     * každé zaokrouhlí zvlášť na 101 tis. (100 500 → 100,5 → 101), ale skutečný součet
     * (201 000 Kč) je celý tisíc → 201. Bez absorpce by EPO vytklo „Provozní výsledek
     * hospodaření neodpovídá výpočtu". PVH samo zůstává nezávisle zaokrouhlené (201) —
     * rozdíl (−1) jde do první složky vzorce s největší abs. hodnotou, tj. do 'I.'.
     */
    public function testPvhAbsorbsRoundingDriftWithoutChangingPvhItself(): void
    {
        $appendix = [
            'balance_sheet' => ['assets' => [
                ['row_code' => 'B.', 'gross' => 1000.0, 'correction' => 0.0, 'net' => 1000.0, 'prev_net' => 0.0],
            ]],
            'income_statement' => ['rows' => [
                ['row_code' => 'I.', 'amount' => 100500.0, 'prev_amount' => 0.0],
                ['row_code' => 'II.', 'amount' => 100500.0, 'prev_amount' => 0.0],
                ['row_code' => 'PVH', 'amount' => 201000.0, 'prev_amount' => 0.0],
            ]],
        ];
        $xml = $this->build($appendix)['xml'];

        self::assertStringContainsString('<VetaUB c_radku="30" kc_min="0" kc_sled="201"/>', $xml); // PVH beze změny
        self::assertStringContainsString('<VetaUB c_radku="1" kc_min="0" kc_sled="100"/>', $xml);  // I. absorbovalo −1
        self::assertStringContainsString('<VetaUB c_radku="2" kc_min="0" kc_sled="101"/>', $xml);  // II. beze změny
        self::assertSame(201, 100 + 101);
    }

    /** Stejná past pro Finanční výsledek hospodaření (FVH, c_radku 48) na IV./VI. */
    public function testFvhAbsorbsRoundingDriftWithoutChangingFvhItself(): void
    {
        $appendix = [
            'balance_sheet' => ['assets' => [
                ['row_code' => 'B.', 'gross' => 1000.0, 'correction' => 0.0, 'net' => 1000.0, 'prev_net' => 0.0],
            ]],
            'income_statement' => ['rows' => [
                ['row_code' => 'IV.', 'amount' => 100500.0, 'prev_amount' => 0.0],
                ['row_code' => 'VI.', 'amount' => 100500.0, 'prev_amount' => 0.0],
                ['row_code' => 'FVH', 'amount' => 201000.0, 'prev_amount' => 0.0],
            ]],
        ];
        $xml = $this->build($appendix)['xml'];

        self::assertStringContainsString('<VetaUB c_radku="48" kc_min="0" kc_sled="201"/>', $xml); // FVH beze změny
        self::assertStringContainsString('<VetaUB c_radku="31" kc_min="0" kc_sled="100"/>', $xml); // IV. absorbovalo −1
        // VI. se tiskne 2× (39 celkem, 41 „VI.2. Ostatní") se stejnou (beze změny) hodnotou 101.
        self::assertStringContainsString('<VetaUB c_radku="39" kc_min="0" kc_sled="101"/>', $xml);
        self::assertStringContainsString('<VetaUB c_radku="41" kc_min="0" kc_sled="101"/>', $xml);
    }

    /**
     * VHPZ (c_radku 49) = PVH + FVH musí sedět PŘESNĚ (chyba EPO „Výsledek hospodaření
     * před zdaněním neodpovídá výpočtu") — hodnota se proto DOPOČÍTÁ z už zaokrouhlených
     * PVH/FVH, ne nezávisle ze skutečné hodnoty VHPZ. Fixtura dává PVH/FVH shodně
     * 200 500 Kč (každé zvlášť zaokrouhleno nahoru na 201 tis.), takže součet je 402, ale
     * nezávislé zaokrouhlení skutečného součtu (401 000 Kč) by dalo 401 — a fixtura navíc
     * nese vyloženě jinou (nesprávnou) 'VHPZ' hodnotu 999 999 Kč, aby bylo vidět, že se
     * skutečně DOPOČÍTÁ, ne přebírá ze zdroje.
     */
    public function testVhpzDerivedFromPvhPlusFvhNotIndependentlyRounded(): void
    {
        $appendix = [
            'balance_sheet' => ['assets' => [
                ['row_code' => 'B.', 'gross' => 1000.0, 'correction' => 0.0, 'net' => 1000.0, 'prev_net' => 0.0],
            ]],
            'income_statement' => ['rows' => [
                ['row_code' => 'PVH', 'amount' => 200500.0, 'prev_amount' => 200500.0],
                ['row_code' => 'FVH', 'amount' => 200500.0, 'prev_amount' => 200500.0],
                ['row_code' => 'VHPZ', 'amount' => 999999.0, 'prev_amount' => 999999.0],
            ]],
        ];
        $xml = $this->build($appendix)['xml'];

        self::assertStringContainsString('<VetaUB c_radku="30" kc_min="201" kc_sled="201"/>', $xml); // PVH
        self::assertStringContainsString('<VetaUB c_radku="48" kc_min="201" kc_sled="201"/>', $xml); // FVH
        self::assertStringContainsString('<VetaUB c_radku="49" kc_min="402" kc_sled="402"/>', $xml); // VHPZ = 201+201, NE round(401000)=401 ani round(999999)=1000
    }

    /**
     * Navazující past zjištěná až proti zkušebnímu EPO 31. 8. 2026: jakmile se VHPZ
     * dopočítává (viz test výše), nezávisle zaokrouhlené VHPO (c_radku 53) i VH (c_radku
     * 55) se od něj uměly rozejít o tisícikorunu — chyby EPO „Výsledek hospodaření po
     * zdanění/za účetní období neodpovídá výpočtu". Celý řetězec PVH+FVH→VHPZ→VHPO→VH se
     * proto dopočítává (M. chybí → přispívá nulou). Křížová kontrola vůči rozvaze
     * (P.A.V. = VH) tím NENÍ ohrožena — P.A.V. dopočítanou hodnotu VH prostě PŘEVEZME
     * (viz buildVetaUD/$overrides), místo aby ji počítala nezávisle.
     */
    public function testVhpoAndVhDerivedThroughFullChainAndPavMirrorsVh(): void
    {
        $appendix = [
            'balance_sheet' => ['liabilities' => [
                ['row_code' => 'P.A.', 'amount' => 301000.0, 'prev_amount' => 0.0],
                ['row_code' => 'P.A.V.', 'amount' => 300000.0, 'prev_amount' => 0.0], // vyloženě jiná, ať je vidět, že se přebírá VH
            ]],
            'income_statement' => ['rows' => [
                ['row_code' => 'PVH', 'amount' => 200500.0, 'prev_amount' => 0.0],
                ['row_code' => 'FVH', 'amount' => 200500.0, 'prev_amount' => 0.0],
                ['row_code' => 'L.', 'amount' => 101000.0, 'prev_amount' => 0.0],
                ['row_code' => 'VHPO', 'amount' => 999999.0, 'prev_amount' => 0.0], // vyloženě jiná, ať je vidět dopočet
                ['row_code' => 'VH', 'amount' => 999999.0, 'prev_amount' => 0.0],   // totéž
            ]],
        ];
        $xml = $this->build($appendix)['xml'];

        self::assertStringContainsString('<VetaUB c_radku="49" kc_min="0" kc_sled="402"/>', $xml);  // VHPZ = 201+201
        self::assertStringContainsString('<VetaUB c_radku="53" kc_min="0" kc_sled="301"/>', $xml);  // VHPO = 402−101
        self::assertStringContainsString('<VetaUB c_radku="55" kc_min="0" kc_sled="301"/>', $xml);  // VH = VHPO−0(M. chybí)
        self::assertStringContainsString('<VetaUD kc_sled="301" c_radku="22" kc_min="0"/>', $xml);  // P.A.V. = VH (ne vlastní 300)
    }
}
