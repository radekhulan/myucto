<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Service\Tax\Return\DppoReturnCalculator;
use MyInvoice\Service\Tax\Return\DppoXmlBuilder;
use MyInvoice\Service\Tax\Return\LegalProvisionLedgerService;
use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\TestCase;

/**
 * VetaG — příloha č. 1 II. oddílu, tabulka C (zákonné opravné položky k pohledávkám
 * a zákonné rezervy podle z. 593/1992 Sb.). Věta v builderu úplně chyběla, takže firma
 * účtující o opravných položkách neměla k ř. 62/162 přílohu a systém na to ani
 * neupozornil. Mapování atributů na řádky tiskopisu viz docblock buildVetaG.
 */
final class DppoXmlBuilderVetaGTest extends TestCase
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

    /** @param array<string,mixed> $over podklad LegalProvisionLedgerService::forPeriod */
    private function provisions(array $over): array
    {
        return $over + LegalProvisionLedgerService::empty();
    }

    /** @param array<string,mixed> $legalProvisions */
    private function build(array $legalProvisions, array $meta = []): array
    {
        $calc = (new DppoReturnCalculator())->compute(
            [
                'vh' => 500000,
                'depreciation' => ['tax' => 0, 'accounting' => 0],
                'legal_provisions' => $legalProvisions,
            ],
            ['tax_paid_advances' => 0],
            TaxConstants::forYear(2025)
        );

        return (new DppoXmlBuilder())->build($this->sampleSupplier(), 2025, $calc, $meta);
    }

    /** Plný rok — bez guardu §3/1 ZoR na ř. 25/26. */
    private function fullYearMeta(): array
    {
        return ['zdobd_od' => '01.01.2025', 'zdobd_do' => '31.12.2025'];
    }

    // ── Zákonné OP k pohledávkám (tabulka a) ─────────────────────────────────

    public function testVetaGMapsLegalAllowanceSectionsToTableCRows(): void
    {
        $result = $this->build($this->provisions([
            'allowance_balance' => 130000.0,
            'allowance_declared_total' => 130000.0,
            'allowance_declared_legal' => 130000.0,
            'allowance_by_section' => ['8' => 10000.0, '8a' => 50000.0, '8b' => 20000.0, '8c' => 50000.0],
            'allowance_split_reliable' => true,
            'allowance_created_split_reliable' => true,
            'legal_allowance_created' => 130000.0,
            'has_activity' => true,
        ]), $this->fullYearMeta());

        $xml = $result['xml'];
        self::assertStringContainsString('<VetaG', $xml);
        // Tvorba za období — ř. 3 (§8), 6 (§8a), 8 (§8b), 10 (§8c).
        self::assertStringContainsString('kc_dpp_c3="10000"', $xml);
        self::assertStringContainsString('kc_dpp_c6="50000"', $xml);
        self::assertStringContainsString('kc_op8b="20000"', $xml);
        self::assertStringContainsString('kc_op8c="50000"', $xml);
        // Stav ke konci období — ř. 4, 7, 9, 11.
        self::assertStringContainsString('kc_dpp_c4="10000"', $xml);
        self::assertStringContainsString('kc_dpp_c7="50000"', $xml);
        self::assertStringContainsString('kc_sop8b="20000"', $xml);
        self::assertStringContainsString('kc_sop8c="50000"', $xml);
    }

    public function testVetaGCountsIntoAppendixCounter(): void
    {
        $xml = $this->build($this->provisions([
            'allowance_balance' => 50000.0,
            'allowance_declared_total' => 50000.0,
            'allowance_declared_legal' => 50000.0,
            'allowance_by_section' => ['8' => 0.0, '8a' => 50000.0, '8b' => 0.0, '8c' => 0.0],
            'allowance_split_reliable' => true,
            'allowance_created_split_reliable' => true,
            'legal_allowance_created' => 50000.0,
            'has_activity' => true,
        ]), $this->fullYearMeta())['xml'];

        self::assertStringContainsString('p_pr_2od="1"', $xml, 'VetaG patří do počítadla příloh II. oddílu');
    }

    public function testVetaGFollowsVetaOAndPrecedesVetaS(): void
    {
        $xml = $this->build($this->provisions([
            'allowance_balance' => 50000.0,
            'allowance_declared_total' => 50000.0,
            'allowance_declared_legal' => 50000.0,
            'allowance_by_section' => ['8' => 0.0, '8a' => 50000.0, '8b' => 0.0, '8c' => 0.0],
            'allowance_split_reliable' => true,
            'allowance_created_split_reliable' => true,
            'legal_allowance_created' => 50000.0,
            'has_activity' => true,
        ]), $this->fullYearMeta())['xml'];

        $vetaO = strpos($xml, '<VetaO');
        $vetaG = strpos($xml, '<VetaG');
        $vetaS = strpos($xml, '<VetaS');
        self::assertNotFalse($vetaG);
        self::assertGreaterThan($vetaO, $vetaG);
        self::assertLessThan($vetaS, $vetaG);
    }

    public function testVetaGOmittedWithoutAnyProvisionActivity(): void
    {
        $result = $this->build(LegalProvisionLedgerService::empty(), $this->fullYearMeta());
        self::assertStringNotContainsString('<VetaG', $result['xml']);
        self::assertStringContainsString('p_pr_2od="0"', $result['xml']);
    }

    // ── Varování místo tichého průchodu ──────────────────────────────────────

    public function testUnassignedLegalSectionWarnsAndSuppressesSplit(): void
    {
        $result = $this->build($this->provisions([
            'allowance_balance' => 80000.0,
            'allowance_declared_total' => 80000.0,
            'allowance_declared_legal' => 80000.0,
            'allowance_by_section' => ['8' => 0.0, '8a' => 30000.0, '8b' => 0.0, '8c' => 0.0],
            'allowance_unassigned' => 50000.0,
            'allowance_split_reliable' => false,
            'legal_allowance_created' => 80000.0,
            'has_activity' => true,
        ]), $this->fullYearMeta());

        self::assertStringNotContainsString('kc_dpp_c6=', $result['xml'], 'nezařazená OP se nesmí odhadnout do §8a');
        self::assertStringNotContainsString('kc_dpp_c7=', $result['xml']);
        $matches = array_filter($result['warnings'], static fn (string $w): bool => str_contains($w, 'určený paragraf'));
        self::assertNotEmpty($matches, 'chybí varování o opravných položkách bez paragrafu');
    }

    public function testAllowanceBalanceNotExplainedByClosingStepWarns(): void
    {
        $result = $this->build($this->provisions([
            // 391 nese 200 000 Kč, ale uzávěrkový krok vysvětluje jen 120 000 Kč
            // (např. OP z minulých let, které nikdo znovu nedeklaroval).
            'allowance_balance' => 200000.0,
            'allowance_declared_total' => 120000.0,
            'allowance_declared_legal' => 120000.0,
            'allowance_by_section' => ['8' => 0.0, '8a' => 120000.0, '8b' => 0.0, '8c' => 0.0],
            'allowance_split_reliable' => false,
            'legal_allowance_created' => 120000.0,
            'has_activity' => true,
        ]), $this->fullYearMeta());

        self::assertStringNotContainsString('kc_dpp_c7=', $result['xml']);
        $matches = array_filter($result['warnings'], static fn (string $w): bool => str_contains($w, 'účet 391'));
        self::assertNotEmpty($matches, 'chybí varování o nevysvětleném zůstatku 391');
    }

    public function testCreatedRowsOmittedWhenPeriodCreationDoesNotMatchDeclaration(): void
    {
        $result = $this->build($this->provisions([
            'allowance_balance' => 100000.0,
            'allowance_declared_total' => 100000.0,
            'allowance_declared_legal' => 100000.0,
            'allowance_by_section' => ['8' => 0.0, '8a' => 100000.0, '8b' => 0.0, '8c' => 0.0],
            'allowance_split_reliable' => true,
            'allowance_created_split_reliable' => false,
            'legal_allowance_created' => 40000.0,
            'has_activity' => true,
        ]), $this->fullYearMeta());

        // Stav (ř. 7) se vyplní, tvorba (ř. 6) ne — a musí o tom být varování.
        self::assertStringContainsString('kc_dpp_c7="100000"', $result['xml']);
        self::assertStringNotContainsString('kc_dpp_c6=', $result['xml']);
        $matches = array_filter($result['warnings'], static fn (string $w): bool => str_contains($w, 'řádky TVORBY'));
        self::assertNotEmpty($matches, 'chybí varování o nesouladu tvorby a evidence');
    }

    // ── Odpis pohledávek (ř. 12) a zákonná rezerva §7 (ř. 25/26) ─────────────

    public function testReceivableWriteOffFillsRow12(): void
    {
        $xml = $this->build($this->provisions([
            'receivable_writeoff_deductible' => 33000.0,
            'has_activity' => true,
        ]), $this->fullYearMeta())['xml'];

        self::assertStringContainsString('kc_dpp_c8="33000"', $xml);
    }

    public function testLegalRepairReserveFillsRows25And26AndWarnsAboutOtherSections(): void
    {
        $result = $this->build($this->provisions([
            'legal_reserve_balance' => 250000.0,
            'legal_reserve_created' => 100000.0,
            'has_activity' => true,
        ]), $this->fullYearMeta());

        self::assertStringContainsString('kc_dpp_c18="100000"', $result['xml']);
        self::assertStringContainsString('kc_dpp_c19="250000"', $result['xml']);
        $matches = array_filter($result['warnings'], static fn (string $w): bool => str_contains($w, '§ 7 zákona o rezervách'));
        self::assertNotEmpty($matches, 'chybí upozornění, že se 451 vykazuje jako rezerva §7');
    }

    /**
     * § 3 odst. 1 zákona o rezervách — ř. 25/26 se vyplňují jen za zdaňovací období
     * dlouhé nejméně 12 měsíců. Zkrácené období je nesmí nést, ale ani je tiše zahodit.
     */
    public function testShortPeriodOmitsRepairReserveRowsAndWarns(): void
    {
        $result = $this->build($this->provisions([
            'legal_reserve_balance' => 250000.0,
            'legal_reserve_created' => 100000.0,
            'has_activity' => true,
        ]), ['zdobd_od' => '01.01.2025', 'zdobd_do' => '30.06.2025', 'typ_zo' => 'A']);

        self::assertStringNotContainsString('kc_dpp_c18=', $result['xml']);
        self::assertStringNotContainsString('kc_dpp_c19=', $result['xml']);
        $matches = array_filter($result['warnings'], static fn (string $w): bool => str_contains($w, 'kratší než 12 měsíců'));
        self::assertNotEmpty($matches, 'chybí varování o zkráceném období u rezervy §7');
    }

    public function testCalendarYearCountsAsFullZorPeriod(): void
    {
        $result = $this->build($this->provisions([
            'legal_reserve_balance' => 10000.0,
            'legal_reserve_created' => 10000.0,
            'has_activity' => true,
        ]), $this->fullYearMeta());

        self::assertStringContainsString('kc_dpp_c19="10000"', $result['xml']);
        $matches = array_filter($result['warnings'], static fn (string $w): bool => str_contains($w, 'kratší než 12 měsíců'));
        self::assertEmpty($matches, 'kalendářní rok je 12 měsíců, guard §3/1 ZoR se nesmí spustit');
    }

    /**
     * Účetní (daňově neuznatelné) opravné položky na 559 do tabulky C NEPATŘÍ — vykazují
     * se na ř. 40 II. oddílu. Sama jejich existence nesmí VetaG vyrobit.
     */
    public function testAccountingAllowanceAloneDoesNotProduceVetaG(): void
    {
        $result = $this->build($this->provisions([
            'allowance_balance' => 40000.0,
            'allowance_declared_total' => 40000.0,
            'allowance_declared_acct' => 40000.0,
            'allowance_split_reliable' => true,
            'allowance_created_split_reliable' => true,
            'acct_allowance_created' => 40000.0,
            'has_activity' => true,
        ]), $this->fullYearMeta());

        self::assertStringNotContainsString('<VetaG', $result['xml']);
    }
}
