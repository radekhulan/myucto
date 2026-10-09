<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax\Return;

use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Repository\TaxProfileRepository;
use MyInvoice\Service\Tax\Return\DpfoReturnCalculator;
use MyInvoice\Service\Tax\Return\DpfoReturnDataProvider;
use MyInvoice\Service\Tax\Return\InsuranceSummaryService;
use MyInvoice\Service\Tax\TaxConstants;
use MyInvoice\Tests\Integration\TaxEvidence\CashJournalTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Úpravy dílčího základu § 7 (§ 5 a § 23 ZDP, ř. 105/106 Přílohy č. 1) se v přiznání
 * i v přehledech OSVČ započítají PRÁVĚ JEDNOU.
 *
 * Regrese: podklady dávaly `s7_base` už včetně zvýšení a snížení z roční uzávěrky daňové
 * evidence a kalkulátor přiznání k němu přičetl `s7_increase − s7_decrease` znovu. Ř. 104
 * (kc_hosp_rozd) tak nesl rozdíl příjmů a výdajů i s úpravami a ř. 113 (kc_zd7p) je měl
 * dvakrát. Přehledy ČSSZ a ZP braly `s7_base` bez druhého přičtení, takže přiznání
 * a přehledy vykázaly jiný dílčí základ § 7.
 *
 * Přehledy mají úpravy obsahovat: daňový základ přehledu ČSSZ je dílčí základ daně § 7
 * (pokyny ČSSZ k přehledu OSVČ, ř. 20), VZP bere ř. 37 DAP, případně ř. 113 Přílohy č. 1.
 *
 * Syntetický rok 2099: konstanty přehledů dostane z přesného roku 2025 přes DB override,
 * protože přehled bez ověřených konstant roku odmítne počítat.
 */
#[Group('integration')]
final class DpfoSection7AdjustmentsOnceTest extends CashJournalTestCase
{
    private const INCOME = 900000.0;
    private const EXPENSES = 350000.0;
    private const INCREASE = 52000.0;   // 40 000 + 12 000
    private const DECREASE = 15000.0;

    private DpfoReturnDataProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = $this->container->get(DpfoReturnDataProvider::class);
        $this->setVatPayer($this->supplierId, false);

        $this->db->pdo()->prepare(
            'INSERT INTO tax_constants (year, data) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE data = VALUES(data)'
        )->execute([self::YEAR, json_encode(TaxConstants::forYear(2025), JSON_THROW_ON_ERROR)]);

        // OSVČ, neplátce DPH, daňová evidence, skutečné výdaje.
        $this->cashDoc('in', 'sale', 500000.0, [], null, self::YEAR . '-03-10');
        $this->cashDoc('in', 'sale', 400000.0, [], null, self::YEAR . '-09-10');
        $this->cashDoc('out', 'purchase', 200000.0, [], null, self::YEAR . '-04-10');
        $this->cashDoc('out', 'purchase', 150000.0, [], null, self::YEAR . '-10-10');

        $closingId = $this->createClosing();
        $this->addAdjustment($closingId, 'setoff', 'increase', 40000.0, 'Nepeněžní příjem ze zápočtu pohledávky');
        $this->addAdjustment($closingId, 'private_use', 'increase', 12000.0, 'Osobní spotřeba zásob');
        $this->addAdjustment($closingId, 'section23_other', 'decrease', 15000.0, 'Příjem zdaněný v minulém období');
    }

    /** Bez činností na profilu: příjmy a výdaje z peněžního deníku. */
    public function testAdjustmentsCountOnceInReturnAndInsuranceSummaries(): void
    {
        $this->profile([]);

        // Ruční výpočet Přílohy č. 1:
        //   ř. 101 příjmy          900 000
        //   ř. 102 výdaje          350 000
        //   ř. 104 rozdíl          550 000
        //   ř. 105 zvýšení          52 000
        //   ř. 106 snížení          15 000
        //   ř. 113 dílčí základ    587 000 = 550 000 + 52 000 − 15 000
        $expectedBefore = self::INCOME - self::EXPENSES;
        $expectedBase = $expectedBefore + self::INCREASE - self::DECREASE;
        self::assertSame(587000.0, $expectedBase);

        $data = $this->provider->gather($this->supplierId, self::YEAR);
        self::assertEqualsWithDelta(self::INCOME, $data['s7_income'], 0.001, 'Předpoklad: příjmy z deníku.');
        self::assertEqualsWithDelta(self::EXPENSES, $data['s7_expenses'], 0.001, 'Předpoklad: výdaje z deníku.');
        self::assertEqualsWithDelta(self::INCREASE, $data['s7_increase'], 0.001);
        self::assertEqualsWithDelta(self::DECREASE, $data['s7_decrease'], 0.001);

        $s7 = $this->computeReturn($data)['s7'];
        self::assertEqualsWithDelta($expectedBefore, $s7['before_adjustments'], 0.001, 'ř. 104 nesmí obsahovat úpravy.');
        self::assertEqualsWithDelta($expectedBase, $s7['base'], 0.001, 'ř. 113 = ř. 104 + ř. 105 − ř. 106, úpravy jednou.');

        $this->assertInsuranceSummaryUses($expectedBase);
        self::assertEqualsWithDelta($expectedBase, $data['s7_base'], 0.001, 'Podklady ukazují stejný dílčí základ jako ř. 113.');
    }

    /**
     * S činnostmi na profilu počítá přiznání § 7 z činností. Přehledy musí vzít tentýž
     * dílčí základ, ne rozdíl z peněžního deníku.
     */
    public function testActivitiesGiveSameSection7BaseInReturnAndInsuranceSummaries(): void
    {
        $this->profile([
            ['name' => 'Programování', 'nace_code' => '620', 'expense_mode' => 'pausal', 'expense_rate' => 60,
             'income' => self::INCOME, 'expenses' => 0, 'active_months' => 12],
        ]);

        // 900 000 − 60 % paušál 540 000 = 360 000; + 52 000 − 15 000 = 397 000.
        $expectedBase = 397000.0;

        $data = $this->provider->gather($this->supplierId, self::YEAR);
        $s7 = $this->computeReturn($data)['s7'];
        self::assertEqualsWithDelta(360000.0, $s7['before_adjustments'], 0.001);
        self::assertEqualsWithDelta($expectedBase, $s7['base'], 0.001);

        $this->assertInsuranceSummaryUses($expectedBase);
        self::assertEqualsWithDelta($expectedBase, $data['s7_base'], 0.001);
    }

    /** @param list<array<string,mixed>> $activities */
    private function profile(array $activities): void
    {
        $this->container->get(TaxProfileRepository::class)->upsert($this->supplierId, self::YEAR, [
            'activity_rate' => 60,
            'flat_tax_band' => 'none',
            'use_actual_expenses' => true,
            'is_secondary' => false,
            'activities' => $activities,
        ]);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function computeReturn(array $data): array
    {
        $c = $this->container->get(TaxConstantsRepository::class)->forExactYear(self::YEAR);

        return (new DpfoReturnCalculator())->compute($data, [], (array) $data['profile'], $c);
    }

    private function assertInsuranceSummaryUses(float $expectedBase): void
    {
        $summary = $this->container->get(InsuranceSummaryService::class)->build($this->supplierId, self::YEAR);
        self::assertEqualsWithDelta($expectedBase, $summary['tax_base_7'], 0.001, 'Přehledy berou dílčí základ § 7 z ř. 113.');

        $c = TaxConstants::forYear(2025);
        // Hlavní činnost celý rok: VZ ČSSZ 55 %, VZ ZP 50 % dílčího základu, nejméně minimum roku 2025.
        self::assertEqualsWithDelta(
            // 397 000 × 0,55 = 218 350 přesně; holé ceil() float součinu by dalo 218 351.
            max(ceil(round($expectedBase * (float) $c['social_assessment_pct'], 6)), ceil((float) $c['social_min_base_main'])),
            $summary['social']['assessment_base'],
            0.001,
        );
        self::assertEqualsWithDelta(
            max(ceil(round($expectedBase * (float) $c['health_assessment_pct'], 6)), ceil((float) $c['health_min_base'])),
            $summary['health']['assessment_base'],
            0.001,
        );
    }

    private function createClosing(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO tax_evidence_closings
                 (supplier_id, year, status, checklist, opening_balances, closing_balances, unsupported_cases)
             VALUES (?, ?, 'final', '{}', '{}', '{}', '[]')"
        )->execute([$this->supplierId, self::YEAR]);

        return (int) $pdo->lastInsertId();
    }

    private function addAdjustment(int $closingId, string $kind, string $direction, float $amount, string $description): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO tax_evidence_non_cash_adjustments
                 (supplier_id, closing_id, adjustment_on, kind, direction, amount, description)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, $closingId, self::YEAR . '-12-31', $kind, $direction, $amount, $description]);
    }
}
