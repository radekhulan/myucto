<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

/**
 * Nepřítomnost HPP přes hranici měsíce s ručním přepočtem.
 *
 * Pracovní neschopnost 20. 2. až 5. 3. 2026 (14 kalendářních dnů, 10
 * pracovních dnů: 6 v únoru, 4 v březnu), celá v okně náhrady mzdy
 * (§ 192 odst. 1 ZP). Průměrný výdělek za 1. čtvrtletí 2026 (rozhodné
 * období 4. čtvrtletí 2025): 120 000 Kč / 480 h = 250 Kč/h. Redukce
 * (§ 192 odst. 3 ZP, sada 2026): do první hranice 285,78 Kč 90 % = 225 Kč/h,
 * náhrada 60 % = 135 Kč/h, za směnu 8 h 1 080 Kč.
 * Náhrada mzdy v zákonné výši není vyměřovacím základem pojistného a je
 * osvobozená od daně (§ 6 odst. 9 písm. p) ZDP: „náhrady mzdy … za dobu
 * dočasné pracovní neschopnosti … do výše minimálního nároku“), vyplácí
 * se ale v čisté mzdě.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eHppAbsenceTest extends TestCase
{
    use PayrollFullFlowTrait;

    private int $officeId;
    private int $baseComponentId;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('ABS', 'Syntetická účtárna nepřítomností', '1100001237');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->baseComponentId = $this->createComponent('MZDA_MESICNI_ABS', 'base_wage', 'regular');
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    /**
     * Únor: mzda za 14 odpracovaných dnů z 20 = 28 000 Kč, náhrada
     * 6 × 1 080 = 6 480 Kč. SP 28 000 × 7,1 % = 1 988; ZP 28 000 × 13,5 %
     * = 3 780, zaměstnanec 1 260; záloha z 28 000 × 15 % = 4 200 − 2 570
     * = 1 630; čistá 28 000 + 6 480 − 1 988 − 1 260 − 1 630 = 29 602.
     *
     * Březen: mzda za 18 dnů z 22 = 32 727 Kč, náhrada 4 × 1 080 = 4 320 Kč.
     * SP 32 727 × 7,1 % = 2 323,62 → 2 324; ZP 4 418,15 → 4 419, zaměstnanec
     * 1 473; záloha z 32 727 → 32 800 × 15 % = 4 920 − 2 570 = 2 350;
     * čistá 32 727 + 4 320 − 2 324 − 1 473 − 2 350 = 30 900.
     */
    public function testSicknessAcrossMonthBoundaryPaysWageCompensationForTenWorkdays(): void
    {
        $person = $this->createEmployment($this->officeId, 'Syntetický Nemocný', 1, 'hpp', 'employment', 40, 10_000, true, '2026-02-01');
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO payroll_enforcement_person_month_evidence (supplier_id, employee_id, period_start, claim_register_evidence_complete, dependants_evidence_complete, spouse_evidence_complete, pension_evidence, updated_by) VALUES (?, ?, "2026-03-01", 1, 1, 1, "none", ?)')
            ->execute([$this->supplierId, $person['employee_id'], $this->actors[0]]);
        $this->publishShifts($person['employment_id'], [...self::workdays('2026-02'), ...self::workdays('2026-03')]);
        $averageId = $this->firstQuarterAverage($person['employment_id']);
        $this->createApprovedAbsence(
            $person['employment_id'],
            'dpn',
            '2026-02-20',
            '2026-03-05',
            $averageId,
            decisionExtra: [
                'first_day_fully_worked' => false,
                'insurance_eligibility_confirmed' => true,
                'conflicting_benefit_excluded' => true,
            ],
        );
        $sick = self::dateRange('2026-02-20', '2026-03-05');

        $compensation = $this->compensationInputs($person['employment_id']);
        self::assertSame(['2026-02-01' => 648_000, '2026-03-01' => 432_000], $compensation, 'Náhrada mzdy za 6 + 4 pracovní dny po 1 080 Kč.');

        self::assertSame(200, $this->approveTimeMonth($person['employment_id'], '2026-02', self::workdays('2026-02', $sick))->getStatusCode());
        $this->createApprovedInput($person, $this->baseComponentId, 2_800_000, 'feb-wage', '2026-02-01');
        $february = $this->runPayrollMonth('2026-02-01', '2026-03-13', $this->officeId, 'sick-feb');
        self::assertNotNull($february['approved'], CanonicalJson::encode($february['blockers']) . CanonicalJson::encode($february['warnings']));
        $this->assertMonth($february, 2_800_000, 198_800, 126_000, 163_000, 2_960_200);

        self::assertSame(200, $this->approveTimeMonth($person['employment_id'], '2026-03', self::workdays('2026-03', $sick))->getStatusCode());
        $this->createApprovedInput($person, $this->baseComponentId, 3_272_700, 'mar-wage', '2026-03-01');
        $march = $this->runPayrollMonth('2026-03-01', '2026-04-14', $this->officeId, 'sick-mar');
        self::assertNotNull($march['approved'], CanonicalJson::encode($march['blockers']) . CanonicalJson::encode($march['warnings']));
        $this->assertMonth($march, 3_272_700, 232_400, 147_300, 235_000, 3_090_000);
    }

    /** @param array<string,mixed> $run */
    private function assertMonth(array $run, int $taxable, int $social, int $health, int $advance, int $net): void
    {
        $person = $run['approved']->revision['result_snapshot']['statutory']['people'][0];
        self::assertSame(
            ['taxable' => $taxable, 'social' => $social, 'health' => $health, 'advance' => $advance, 'net' => $net],
            [
                'taxable' => $person['income_tax']['advance_tax']['taxable_income_minor_units'],
                'social' => $person['social_insurance']['employee_contribution_minor_units'],
                'health' => $person['health_insurance']['employee_contribution_minor_units'],
                'advance' => $person['income_tax']['advance_tax']['tax_after_credits_minor_units'],
                'net' => $person['net_payable_minor_units'],
            ],
            'Výpočet nesouhlasí s ručním přepočtem.',
        );
    }

    /** @return array<string,int> období => náhrada mzdy při DPN v haléřích */
    private function compensationInputs(int $employmentId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT input.period_start, SUM(input.amount_minor)
               FROM payroll_inputs input
               JOIN payroll_component_definitions component
                 ON component.supplier_id = input.supplier_id AND component.id = input.component_id
              WHERE input.supplier_id = ? AND input.employment_id = ?
                AND component.code = "NAHRADA_MZDY_DPN"
              GROUP BY input.period_start ORDER BY input.period_start',
        );
        $statement->execute([$this->supplierId, $employmentId]);

        return array_map('intval', $statement->fetchAll(\PDO::FETCH_KEY_PAIR));
    }

    private function firstQuarterAverage(int $employmentId): int
    {
        $created = $this->absences->createAverage(
            $this->request('POST', '/api/payroll/absences/average')->withParsedBody([
                'employment_id' => $employmentId,
                'applicable_year' => 2026,
                'applicable_quarter' => 1,
                'decisive_from' => '2025-10-01',
                'decisive_to' => '2025-12-31',
                'gross_earnings_minor' => 12_000_000,
                'longer_period_allocated_minor' => 0,
                'worked_minutes' => 28_800,
                'worked_days' => 60,
                'probable_hourly_minor' => null,
                'rationale' => null,
            ]),
            new Response(),
        );
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $average = $this->json($created)['snapshot'];
        $approved = $this->absences->approveAverage(
            $this->request('POST', '/api/payroll/absences/average/approve')->withParsedBody(['row_version' => $average['row_version']]),
            new Response(),
            ['id' => (string) $average['id']],
        );
        self::assertSame(200, $approved->getStatusCode(), (string) $approved->getBody());

        return (int) $average['id'];
    }
}
