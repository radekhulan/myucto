<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Service\Tax\Return\UnsupportedCaseDetector;
use PHPUnit\Framework\TestCase;

/**
 * P-1 (private/DANE-PLAN.md) — každý vědomě nepodporovaný případ musí být VIDĚT.
 * Dřív prošly všechny tiše: přiznání se stavělo natvrdo jako typ poplatníka 1,
 * typ přiznání A a účetní vyhláška 500/2002 Sb.
 *
 * Ke každému řádku tabulky P-1 je tu test, který ověřuje, že nález vznikne a s jakou
 * závažností. Všechna data v testu jsou vymyšlená.
 */
final class UnsupportedCaseDetectorTest extends TestCase
{
    /** Běžná s.r.o. — sídlo v ČR, kalendářní rok, žádné příznaky. */
    private function ordinaryPo(array $overrides = []): array
    {
        return $overrides + [
            'taxpayer_type' => 'po',
            'cz_nace_code' => '62020',
            'country_iso2' => 'CZ',
            'epo_taxpayer_code' => null,
            'tax_entity_status' => 'normal',
            'tax_entity_status_date' => null,
            'tax_accounting_decree' => '500',
            'tax_investment_incentive' => 0,
            'tax_atad_cfc' => 0,
            'tax_public_benefit' => 0,
            'tax_cooperating_person' => 0,
            'tax_foreign_income_credit' => 0,
        ];
    }

    private function ordinaryFo(array $overrides = []): array
    {
        return $overrides + $this->ordinaryPo(['taxpayer_type' => 'fo', 'cz_nace_code' => '620200']);
    }

    /** @return array<string,mixed> */
    private function calendarPeriod(int $year = 2025): array
    {
        return ['period' => ['starts_on' => sprintf('%04d-01-01', $year), 'ends_on' => sprintf('%04d-12-31', $year)]];
    }

    /** @param list<array<string,mixed>> $findings */
    private function keys(array $findings): array
    {
        return array_column($findings, 'key');
    }

    /** @param list<array<string,mixed>> $findings */
    private function severity(array $findings, string $key): ?string
    {
        foreach ($findings as $f) {
            if ($f['key'] === $key) {
                return $f['severity'];
            }
        }

        return null;
    }

    /** @param list<array<string,mixed>> $findings */
    private function blockers(array $findings): array
    {
        return array_values(array_filter(
            $findings,
            static fn (array $f): bool => $f['severity'] === UnsupportedCaseDetector::SEVERITY_BLOCKER,
        ));
    }

    // ── Kontrolní vzorek: brána nesmí zastavit běžnou firmu ────────────────────

    /** Bez tohohle testu by šlo bránu „splnit" tím, že blokuje všechno. */
    public function testOrdinaryLimitedCompanyHasNoBlockingFindings(): void
    {
        $findings = UnsupportedCaseDetector::detectForSupplier($this->ordinaryPo(), 'po', $this->calendarPeriod());
        self::assertSame([], $this->blockers($findings), 'Běžná s.r.o. nesmí mít blokující nález.');
    }

    /** Totéž pro OSVČ v daňové evidenci. */
    public function testOrdinarySoleTraderHasNoBlockingFindings(): void
    {
        $findings = UnsupportedCaseDetector::detectForSupplier(
            $this->ordinaryFo(),
            'fo',
            ['accounting_mode' => 'tax_evidence', 'closing' => ['status' => 'final', 'unsupported_cases' => []]],
        );
        self::assertSame([], $this->blockers($findings));
    }

    /** Výslovně potvrzený typ 1 nesmí nic rozsvítit — jinak by účetní neměla jak nález zavřít. */
    public function testExplicitlyConfirmedOrdinaryTaxpayerTypeIsClean(): void
    {
        $findings = UnsupportedCaseDetector::detectForSupplier(
            $this->ordinaryPo(['epo_taxpayer_code' => '1']),
            'po',
            $this->calendarPeriod(),
        );
        self::assertSame([], $findings);
    }

    // ── Typ poplatníka (typ_popldpp) ──────────────────────────────────────────

    /** Každý kód číselníku mimo „1" aplikace neumí — blokuje. */
    public function testEveryNonDefaultTaxpayerTypeBlocks(): void
    {
        foreach (['0', '2', '3', '4', '5', '6', '7', '8', '9'] as $code) {
            $findings = UnsupportedCaseDetector::detectForSupplier(
                $this->ordinaryPo(['epo_taxpayer_code' => $code]),
                'po',
                $this->calendarPeriod(),
            );
            self::assertContains('taxpayer_type_unsupported', $this->keys($findings), "typ poplatníka $code");
            self::assertSame(
                UnsupportedCaseDetector::SEVERITY_BLOCKER,
                $this->severity($findings, 'taxpayer_type_unsupported'),
            );
        }
    }

    /** Hodnota mimo číselník (EPO ji odmítne kritickou kontrolou). */
    public function testTaxpayerTypeOutsideCodebookBlocks(): void
    {
        $findings = UnsupportedCaseDetector::detectForSupplier(
            $this->ordinaryPo(['epo_taxpayer_code' => 'X']),
            'po',
            $this->calendarPeriod(),
        );
        self::assertContains('taxpayer_type_invalid', $this->keys($findings));
        self::assertSame(UnsupportedCaseDetector::SEVERITY_BLOCKER, $this->severity($findings, 'taxpayer_type_invalid'));
    }

    // ── Investiční fond, penzijní společnost, zdravotní pojišťovna, banka ─────

    /** Podezření z dat (NACE finančního sektoru) při nepotvrzeném typu poplatníka blokuje. */
    public function testFinancialSectorNaceWithUndeclaredTaxpayerTypeBlocks(): void
    {
        foreach (['64190', '64300', '65110', '65300', '66300'] as $nace) {
            $findings = UnsupportedCaseDetector::detectForSupplier(
                $this->ordinaryPo(['cz_nace_code' => $nace]),
                'po',
                $this->calendarPeriod(),
            );
            self::assertContains('taxpayer_type_undeclared', $this->keys($findings), "NACE $nace");
            self::assertSame(
                UnsupportedCaseDetector::SEVERITY_BLOCKER,
                $this->severity($findings, 'taxpayer_type_undeclared'),
            );
        }
    }

    /** Po výslovném potvrzení typu 1 zbyde jen varování — nález musí jít zavřít. */
    public function testFinancialSectorNaceWithConfirmedOrdinaryTypeWarnsOnly(): void
    {
        $findings = UnsupportedCaseDetector::detectForSupplier(
            $this->ordinaryPo(['cz_nace_code' => '64190', 'epo_taxpayer_code' => '1']),
            'po',
            $this->calendarPeriod(),
        );
        self::assertSame([], $this->blockers($findings));
        self::assertContains('financial_sector_nace', $this->keys($findings));
    }

    /** Holdingy a leasing účtují podle vyhlášky 500 — nesmí spadnout do falešně pozitivních. */
    public function testOrdinaryFinanceAdjacentNaceIsNotFlagged(): void
    {
        foreach (['64200', '64910', '64920', '66190'] as $nace) {
            $findings = UnsupportedCaseDetector::detectForSupplier(
                $this->ordinaryPo(['cz_nace_code' => $nace]),
                'po',
                $this->calendarPeriod(),
            );
            self::assertSame([], $findings, "NACE $nace nesmí vyvolat nález.");
        }
    }

    /** Účetní vyhláška 501/502/503 — aplikace umí jen 500 a do přiznání píše uv_vyhl=500. */
    public function testForeignAccountingDecreeBlocks(): void
    {
        foreach (['501', '502', '503', '504', '325', '410'] as $decree) {
            $findings = UnsupportedCaseDetector::detectForSupplier(
                $this->ordinaryPo(['tax_accounting_decree' => $decree]),
                'po',
                $this->calendarPeriod(),
            );
            self::assertContains('accounting_decree_unsupported', $this->keys($findings), "vyhláška $decree");
            self::assertSame(
                UnsupportedCaseDetector::SEVERITY_BLOCKER,
                $this->severity($findings, 'accounting_decree_unsupported'),
            );
        }
    }

    // ── Veřejně prospěšný poplatník (§ 17a) ───────────────────────────────────

    public function testPublicBenefitTaxpayerBlocks(): void
    {
        $findings = UnsupportedCaseDetector::detectForSupplier(
            $this->ordinaryPo(['tax_public_benefit' => 1]),
            'po',
            $this->calendarPeriod(),
        );
        self::assertContains('public_benefit', $this->keys($findings));
        self::assertSame(UnsupportedCaseDetector::SEVERITY_BLOCKER, $this->severity($findings, 'public_benefit'));
    }

    /** Vypnutý příznak není tichý předpoklad — NACE organizací sdružujících osoby varuje. */
    public function testPublicBenefitSuspicionFromNaceWarns(): void
    {
        $findings = UnsupportedCaseDetector::detectForSupplier(
            $this->ordinaryPo(['cz_nace_code' => '94991']),
            'po',
            $this->calendarPeriod(),
        );
        self::assertContains('public_benefit_suspected', $this->keys($findings));
        self::assertSame(
            UnsupportedCaseDetector::SEVERITY_WARNING,
            $this->severity($findings, 'public_benefit_suspected'),
        );
    }

    // ── Likvidace, insolvence, fúze (typ_dapdpp) ──────────────────────────────

    public function testEntityStatusBlocksBothReturnTypes(): void
    {
        foreach (['liquidation', 'insolvency', 'transformation'] as $status) {
            foreach (['po', 'fo'] as $type) {
                $supplier = $this->ordinaryPo(['tax_entity_status' => $status, 'tax_entity_status_date' => '2025-04-01']);
                $findings = UnsupportedCaseDetector::detectForSupplier(
                    $supplier,
                    $type,
                    $type === 'po' ? $this->calendarPeriod() : ['accounting_mode' => 'tax_evidence'],
                );
                self::assertContains('entity_status_unsupported', $this->keys($findings), "$status / $type");
                self::assertSame(
                    UnsupportedCaseDetector::SEVERITY_BLOCKER,
                    $this->severity($findings, 'entity_status_unsupported'),
                );
            }
        }
    }

    /** Hláška musí účetní říct, jaký typ přiznání úřad čeká — ne jen „nepodporujeme". */
    public function testLiquidationMessageNamesExpectedReturnTypes(): void
    {
        $findings = UnsupportedCaseDetector::detectForSupplier(
            $this->ordinaryPo(['tax_entity_status' => 'liquidation', 'tax_entity_status_date' => '2025-04-01']),
            'po',
            $this->calendarPeriod(),
        );
        $message = '';
        foreach ($findings as $f) {
            if ($f['key'] === 'entity_status_unsupported') {
                $message = $f['message'];
            }
        }
        self::assertStringContainsString('B (', $message);
        self::assertStringContainsString('C (', $message);
        self::assertStringContainsString('2025-04-01', $message);
    }

    // ── ATAD / CFC a investiční pobídky ───────────────────────────────────────

    public function testAtadCfcBlocks(): void
    {
        $findings = UnsupportedCaseDetector::detectForSupplier(
            $this->ordinaryPo(['tax_atad_cfc' => 1]),
            'po',
            $this->calendarPeriod(),
        );
        self::assertContains('atad_cfc', $this->keys($findings));
        self::assertSame(UnsupportedCaseDetector::SEVERITY_BLOCKER, $this->severity($findings, 'atad_cfc'));
    }

    public function testInvestmentIncentiveBlocks(): void
    {
        $findings = UnsupportedCaseDetector::detectForSupplier(
            $this->ordinaryPo(['tax_investment_incentive' => 1]),
            'po',
            $this->calendarPeriod(),
        );
        self::assertContains('investment_incentive', $this->keys($findings));
        self::assertSame(UnsupportedCaseDetector::SEVERITY_BLOCKER, $this->severity($findings, 'investment_incentive'));
    }

    // ── Sídlo mimo ČR (DPPO i DPFO) ───────────────────────────────────────────

    public function testForeignSeatWarnsForBothReturnTypes(): void
    {
        foreach (['po', 'fo'] as $type) {
            $findings = UnsupportedCaseDetector::detectForSupplier(
                $this->ordinaryPo(['country_iso2' => 'SK']),
                $type,
                $type === 'po' ? $this->calendarPeriod() : ['accounting_mode' => 'tax_evidence'],
            );
            self::assertContains('foreign_seat', $this->keys($findings), "typ $type");
            self::assertSame(UnsupportedCaseDetector::SEVERITY_WARNING, $this->severity($findings, 'foreign_seat'));
        }
    }

    public function testForeignSeatWarningIsSilentForCzechSeat(): void
    {
        self::assertNull(UnsupportedCaseDetector::foreignSeatWarning('CZ'));
        self::assertNull(UnsupportedCaseDetector::foreignSeatWarning(''));
        self::assertNotNull(UnsupportedCaseDetector::foreignSeatWarning('de'));
    }

    // ── Hospodářský rok / zkrácené a atypické období ──────────────────────────

    /** Tichá díra P-1: atypické zkrácené období spadlo na „A" bez jediného slova. */
    public function testAtypicalShortPeriodBlocks(): void
    {
        $findings = UnsupportedCaseDetector::detectForSupplier(
            $this->ordinaryPo(),
            'po',
            ['period' => ['starts_on' => '2025-01-01', 'ends_on' => '2025-06-30']],
        );
        self::assertContains('tax_period_atypical', $this->keys($findings));
        self::assertSame(UnsupportedCaseDetector::SEVERITY_BLOCKER, $this->severity($findings, 'tax_period_atypical'));
    }

    /** Období delší než dvanáct měsíců — typ přiznání A ho popsat nesmí. */
    public function testOverlongPeriodBlocks(): void
    {
        $findings = UnsupportedCaseDetector::detectForSupplier(
            $this->ordinaryPo(),
            'po',
            ['period' => ['starts_on' => '2024-10-01', 'ends_on' => '2025-12-31']],
        );
        self::assertContains('tax_period_atypical', $this->keys($findings));
    }

    public function testFiscalYearWarns(): void
    {
        $findings = UnsupportedCaseDetector::detectForSupplier(
            $this->ordinaryPo(),
            'po',
            ['period' => ['starts_on' => '2025-04-01', 'ends_on' => '2026-03-31']],
        );
        self::assertContains('tax_period_fiscal_year', $this->keys($findings));
        self::assertSame(
            UnsupportedCaseDetector::SEVERITY_WARNING,
            $this->severity($findings, 'tax_period_fiscal_year'),
        );
    }

    public function testMissingPeriodWarns(): void
    {
        $findings = UnsupportedCaseDetector::detectForSupplier($this->ordinaryPo(), 'po', []);
        self::assertContains('tax_period_missing', $this->keys($findings));
    }

    /** První (zkrácený) rok nově vzniklého poplatníka je legitimní „A" — nic nehlásit. */
    public function testShortFirstCalendarYearIsClean(): void
    {
        $findings = UnsupportedCaseDetector::detectForSupplier(
            $this->ordinaryPo(),
            'po',
            ['period' => ['starts_on' => '2025-03-15', 'ends_on' => '2025-12-31', 'is_first' => true]],
        );
        self::assertSame([], $findings);
    }

    /**
     * Tentýž tvar období, ale poplatník už dřív účtoval — jde tedy o přechod
     * z hospodářského roku na kalendářní, který chce jiný typ přiznání (§ 21a, § 38ma).
     * Aplikace ho neumí, takže o tom aspoň musí říct.
     */
    public function testShortCalendarYearAfterEarlierPeriodWarns(): void
    {
        $findings = UnsupportedCaseDetector::detectForSupplier(
            $this->ordinaryPo(),
            'po',
            ['period' => ['starts_on' => '2025-04-01', 'ends_on' => '2025-12-31', 'is_first' => false]],
        );
        self::assertContains('tax_period_short_calendar', $this->keys($findings));
        self::assertSame(['warning'], array_values(array_unique(array_column($findings, 'severity'))));
    }

    // ── Fotovoltaika § 30b / § 24 odst. 2 písm. v) ────────────────────────────

    public function testPhotovoltaicNaceWarns(): void
    {
        $findings = UnsupportedCaseDetector::detectForSupplier(
            $this->ordinaryPo(['cz_nace_code' => '35110']),
            'po',
            $this->calendarPeriod(),
        );
        self::assertContains('photovoltaic_30b', $this->keys($findings));
        self::assertSame(UnsupportedCaseDetector::SEVERITY_WARNING, $this->severity($findings, 'photovoltaic_30b'));
    }

    // ── DPFO ──────────────────────────────────────────────────────────────────

    /** FO v podvojném účetnictví: VetaUA-UE (účetní výkazy) aplikace nesestavuje. */
    public function testFoInDoubleEntryBookkeepingBlocks(): void
    {
        $findings = UnsupportedCaseDetector::detectForSupplier(
            $this->ordinaryFo(),
            'fo',
            ['accounting_mode' => 'double_entry'],
        );
        self::assertContains('fo_double_entry_statements', $this->keys($findings));
        self::assertSame(
            UnsupportedCaseDetector::SEVERITY_BLOCKER,
            $this->severity($findings, 'fo_double_entry_statements'),
        );
    }

    public function testCooperatingPersonBlocks(): void
    {
        $findings = UnsupportedCaseDetector::detectForSupplier(
            $this->ordinaryFo(['tax_cooperating_person' => 1]),
            'fo',
            ['accounting_mode' => 'tax_evidence'],
        );
        self::assertContains('cooperating_person_13', $this->keys($findings));
        self::assertSame(
            UnsupportedCaseDetector::SEVERITY_BLOCKER,
            $this->severity($findings, 'cooperating_person_13'),
        );
    }

    public function testForeignIncomeCreditBlocks(): void
    {
        $findings = UnsupportedCaseDetector::detectForSupplier(
            $this->ordinaryFo(['tax_foreign_income_credit' => 1]),
            'fo',
            ['accounting_mode' => 'tax_evidence'],
        );
        self::assertContains('foreign_income_credit_38f', $this->keys($findings));
        self::assertSame(
            UnsupportedCaseDetector::SEVERITY_BLOCKER,
            $this->severity($findings, 'foreign_income_credit_38f'),
        );
    }

    /** Vypnutý příznak + samostatný základ § 16a = podezření z dat, musí varovat. */
    public function testForeignIncomeSuspicionFromSeparateBaseWarns(): void
    {
        $findings = UnsupportedCaseDetector::detectForSupplier(
            $this->ordinaryFo(),
            'fo',
            ['accounting_mode' => 'tax_evidence'],
            [],
            ['s16a_separate_base' => 40000],
        );
        self::assertContains('foreign_income_suspected', $this->keys($findings));
        self::assertSame(
            UnsupportedCaseDetector::SEVERITY_WARNING,
            $this->severity($findings, 'foreign_income_suspected'),
        );
    }

    /** Ruční `unsupported_cases` z roční uzávěrky se slévá do stejného seznamu. */
    public function testManualUnsupportedCasesFromAnnualClosingBlock(): void
    {
        $findings = UnsupportedCaseDetector::detectForSupplier(
            $this->ordinaryFo(),
            'fo',
            [
                'accounting_mode' => 'tax_evidence',
                'closing' => [
                    'status' => 'final',
                    'unsupported_cases' => [['label' => 'Vymyšlená situace k ručnímu dopočtu']],
                ],
            ],
        );
        self::assertContains('manual_unsupported_cases', $this->keys($findings));
        self::assertSame(
            UnsupportedCaseDetector::SEVERITY_BLOCKER,
            $this->severity($findings, 'manual_unsupported_cases'),
        );
        $message = '';
        foreach ($findings as $f) {
            if ($f['key'] === 'manual_unsupported_cases') {
                $message = $f['message'];
            }
        }
        self::assertStringContainsString('Vymyšlená situace k ručnímu dopočtu', $message);
    }

    /** Nález musí účetní říct, co má udělat — prázdný `action` je k ničemu. */
    public function testEveryFindingCarriesActionableInstruction(): void
    {
        $findings = UnsupportedCaseDetector::detectForSupplier(
            $this->ordinaryPo([
                'epo_taxpayer_code' => '4',
                'tax_entity_status' => 'liquidation',
                'tax_atad_cfc' => 1,
                'tax_investment_incentive' => 1,
                'tax_public_benefit' => 1,
                'country_iso2' => 'AT',
                'tax_accounting_decree' => '501',
            ]),
            'po',
            ['period' => ['starts_on' => '2025-01-01', 'ends_on' => '2025-05-31']],
        );
        self::assertGreaterThanOrEqual(6, count($findings));
        foreach ($findings as $f) {
            self::assertNotSame('', trim($f['message']), $f['key']);
            self::assertNotSame('', trim($f['action']), $f['key']);
            self::assertContains(
                $f['severity'],
                [UnsupportedCaseDetector::SEVERITY_BLOCKER, UnsupportedCaseDetector::SEVERITY_WARNING],
            );
        }
    }
}
