<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Repository\Payroll\PayrollDeductionAgreementRepository;
use MyInvoice\Service\Payroll\Net\DeductionAgreementStatus;
use MyInvoice\Service\Payroll\Net\DeductionAgreementTerms;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

/**
 * Srážky ze mzdy za duben 2026 (první měsíc JMHZ) celým tokem: docházka,
 * nepřítomnost, běh, schválení, příprava hlášení a jeho test proti XSD
 * a katalogu kontrol. Očekávané hodnoty jsou přepočtené ručně.
 *
 * Duben 2026 má 20 pracovních dnů a 2 svátky v pracovní dny (Velký pátek
 * 3. 4., Velikonoční pondělí 6. 4.), fond měsíční mzdy 22 × 8 = 176 hodin.
 * Nezabavitelná částka bez vyživovaných osob 14 101,50 → 14 102 Kč
 * (nař. vlády č. 595/2006 Sb. ve znění č. 548/2025 Sb.).
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eHpp2DeductionFlowTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const PERIOD = '2026-04';
    private const PERIOD_START = '2026-04-01';
    private const PAYDAY = '2026-05-14';

    private int $officeId;
    private int $baseComponentId;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('SRZ', 'Syntetická účtárna srážek', '1100001237');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->baseComponentId = $this->createComponent('MZDA_MESICNI_SRZ', 'base_wage', 'regular');
        $mappings = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        self::assertInstanceOf(PayrollComponentJmhzMappingRepository::class, $mappings);
        $mappings->put($this->supplierId, $this->baseComponentId, '10329', null, $this->actors[0]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    /**
     * Exekuce při nemoci: srážky se počítají z čisté mzdy včetně náhrady
     * mzdy při dočasné pracovní neschopnosti (§ 299 odst. 1 písm. a) o. s. ř.,
     * § 145 odst. 2 písm. b) ZP).
     *
     * HPP 44 000 Kč, podepsané prohlášení, neschopnost 13. až 26. 4. 2026
     * (14 kalendářních dnů, 10 směn, 80 hodin). Mzda 44 000 × 96/176
     * = 24 000 Kč. Náhrada mzdy: průměr 250 Kč/h je pod první redukční
     * hranicí 285,78 Kč, započte se 90 % = 225 Kč, náhrada 60 % = 135 Kč/h,
     * 80 h × 135 = 10 800 Kč (§ 192 ZP); je osvobozená od daně a mimo
     * vyměřovací základy.
     *
     * SP 24 000 × 7,1 % = 1 704; ZP 24 000 × 13,5 % = 3 240, zaměstnanec
     * 1 080; záloha 24 000 × 15 % = 3 600 − 2 570 = 1 030. Čistá mzda
     * 24 000 − 1 704 − 1 080 − 1 030 + 10 800 = 30 986 Kč.
     *
     * Srážky: 30 986 − 14 102 = 16 884 Kč, třetina 5 628 Kč. Nepřednostní
     * pohledávka: sraženo 5 628 Kč, z toho paušál plátce 50 Kč a oprávněnému
     * 5 578 Kč. K výplatě 25 358 Kč. (Bez náhrady mzdy by třetina byla jen
     * (20 186 − 14 102 = 6 084) / 3 = 2 028 Kč.)
     */
    public function testEnforcementDuringSicknessTakesWageCompensationIntoAccount(): void
    {
        $person = $this->hire('Syntetický Povinný', 'male', '1984-03-12', 1);
        $averageId = $this->average($person['employment_id'], 4_000_000);
        $this->createApprovedAbsence(
            $person['employment_id'],
            'dpn',
            '2026-04-13',
            '2026-04-26',
            $averageId,
            decisionExtra: [
                'first_day_fully_worked' => false,
                'insurance_eligibility_confirmed' => true,
                'conflicting_benefit_excluded' => true,
            ],
        );
        $sick = self::dateRange('2026-04-13', '2026-04-26');
        self::assertSame(200, $this->approveTimeMonth($person['employment_id'], self::PERIOD, self::workdays(self::PERIOD, $sick))->getStatusCode());
        $this->createApprovedInput($person, $this->baseComponentId, 2_400_000, 'wage-april', self::PERIOD_START);
        self::assertSame(1_080_000, $this->inputAmount($person['employment_id'], 'NAHRADA_MZDY_DPN'), 'Náhrada mzdy za 80 hodin po 135 Kč.');
        $claim = $this->enforcementClaim($person['employee_id'], 'non_priority', '2026-02-16', 10_000_000);

        $result = $this->submission('sickness-enforcement');

        $statutory = $result['statutory'];
        self::assertSame(
            ['social' => 170_400, 'health' => 108_000, 'advance' => 103_000, 'net' => 3_098_600],
            [
                'social' => $statutory['social_insurance']['employee_contribution_minor_units'],
                'health' => $statutory['health_insurance']['employee_contribution_minor_units'],
                'advance' => $statutory['income_tax']['advance_tax']['tax_after_credits_minor_units'],
                'net' => $statutory['net_payable_minor_units'],
            ],
            'Čistá mzda nesouhlasí s ručním přepočtem.',
        );
        $enforcement = $result['person']['enforcement']['result'];
        self::assertSame('supported', $enforcement['status'], CanonicalJson::encode($enforcement['issues']));
        self::assertSame(
            ['income' => 3_098_600, 'protected' => 1_410_200, 'third' => 562_800, 'withheld' => 562_800, 'fee' => 5_000, 'payment' => 2_535_800],
            [
                'income' => $enforcement['garnishable_income_minor_units'],
                'protected' => $enforcement['protected_amount_minor_units'],
                'third' => $enforcement['third_minor_units'],
                'withheld' => $enforcement['total_withheld_minor_units'],
                'fee' => $enforcement['employer_flat_fee_minor_units'],
                'payment' => $enforcement['employee_payment_minor_units'],
            ],
            'Srážky nesouhlasí s ručním přepočtem.',
        );
        self::assertSame($claim, $enforcement['allocations'][0]['claim_id']);
        self::assertSame(557_800, $enforcement['allocations'][0]['total_minor_units']);
        self::assertSame(2_535_800, $result['person']['payable_after_enforcement_minor']);
        // Hlášení: čistý příjem před srážkami (10344) a příznak evidovaných
        // srážek na základě výkonu rozhodnutí (10116).
        self::assertStringContainsString('<form:mzdaCista>30986</form:mzdaCista>', $result['xml']);
        self::assertStringContainsString('<form:srazkyZeMzdyEvidovany>true</form:srazkyZeMzdyEvidovany>', $result['xml']);
    }

    /**
     * Dohoda o srážkách ze mzdy (splátka zápůjčky zaměstnavatele 3 000 Kč,
     * uzavřená 5. 1. 2026) bez exekuce, HPP 30 000 Kč, celý měsíc odpracován.
     *
     * SP 2 130; ZP 4 050 / 3 = 1 350; záloha 4 500 − 2 570 = 1 930; čistá
     * 24 590 Kč. Třetina (24 590 − 14 102 = 10 488) / 3 = 3 496 Kč, splátka
     * 3 000 Kč se do ní vejde celá (§ 148 odst. 2 ZP). K výplatě 21 590 Kč.
     * V hlášení zůstává čistý příjem (10344) 24 590 Kč a příznak srážek je
     * ANO (10116: dohoda o srážkách z příjmu podle občanského zákoníku).
     */
    public function testDeductionAgreementReducesPayoutAndIsFlaggedInMonthlyReport(): void
    {
        $person = $this->hire('Syntetická Splácející', 'female', '1990-06-21', 2);
        $this->average($person['employment_id'], 4_000_000);
        self::assertSame(200, $this->approveTimeMonth($person['employment_id'], self::PERIOD, self::workdays(self::PERIOD))->getStatusCode());
        $this->createApprovedInput($person, $this->baseComponentId, 3_000_000, 'wage-april', self::PERIOD_START);
        $agreements = $this->container->get(PayrollDeductionAgreementRepository::class);
        self::assertInstanceOf(PayrollDeductionAgreementRepository::class, $agreements);
        $agreements->create(
            $this->supplierId,
            $person['employee_id'],
            DeductionAgreementTerms::fromRequest([
                'title' => 'Splátka zápůjčky',
                'deduction_kind' => 'advance',
                'priority_no' => 100,
                'requested_minor' => 300_000,
                'valid_from' => '2026-01-01',
                'delivered_on' => '2026-01-05',
            ]),
            DeductionAgreementStatus::Active,
            $this->actors[0],
        );

        $result = $this->submission('agreement');

        $netPay = $result['statutory']['net_pay'];
        self::assertSame(
            ['before' => 2_459_000, 'deducted' => 300_000, 'net' => 2_159_000],
            [
                'before' => $netPay['net_before_deductions_minor_units'],
                'deducted' => $netPay['deducted_minor_units'],
                'net' => $netPay['net_payable_minor_units'],
            ],
            'Čistá mzda a srážka nesouhlasí s ručním přepočtem.',
        );
        self::assertSame(0, (int) $result['person']['enforcement']['result']['total_withheld_minor_units']);
        self::assertSame(2_159_000, $result['person']['payable_after_enforcement_minor']);
        self::assertStringContainsString('<form:mzdaCista>24590</form:mzdaCista>', $result['xml']);
        self::assertStringContainsString('<form:srazkyZeMzdyEvidovany>true</form:srazkyZeMzdyEvidovany>', $result['xml']);
    }

    /**
     * @return array{employee_id:int,employment_id:int,name:string}
     */
    private function hire(string $name, string $sex, string $birthDate, int $sequence): array
    {
        $person = $this->createEmployment($this->officeId, $name, $sequence, 'hpp', 'employment', 40, 10_000, true, self::PERIOD_START);
        [$firstName, $lastName] = explode(' ', $name, 2);
        $this->completeJmhzEmployment($person, identity: [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birth_date' => $birthDate,
            'sex' => $sex,
            'birth_number' => self::syntheticBirthNumber($birthDate, $sex, $sequence),
        ]);
        $this->assignJmhzIdentity($person, self::syntheticOic($sequence), sprintf('2%020d', $sequence));
        $this->publishShifts($person['employment_id'], self::workdays(self::PERIOD));

        return $person;
    }

    /** Schválený průměrný výdělek pro 2. čtvrtletí 2026 z 160 hodin; vrací ID. */
    private function average(int $employmentId, int $grossMinor): int
    {
        $created = $this->absences->createAverage(
            $this->request('POST', '/api/payroll/absences/average')->withParsedBody([
                'employment_id' => $employmentId,
                'applicable_year' => 2026,
                'applicable_quarter' => 2,
                'decisive_from' => '2026-01-01',
                'decisive_to' => '2026-03-31',
                'gross_earnings_minor' => $grossMinor,
                'longer_period_allocated_minor' => 0,
                'worked_minutes' => 9_600,
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

    private function inputAmount(int $employmentId, string $componentCode): int
    {
        return (int) $this->scalar(
            'SELECT COALESCE(SUM(input.amount_minor), 0)
               FROM payroll_inputs input
               JOIN payroll_component_definitions component
                 ON component.supplier_id = input.supplier_id AND component.id = input.component_id
              WHERE input.supplier_id = ? AND input.employment_id = ? AND input.period_start = ?
                AND component.code = ?',
            [$this->supplierId, $employmentId, self::PERIOD_START, $componentCode],
        );
    }

    /**
     * Exekuční případ ve stavu „srážet a odesílat“ s jednou ověřenou
     * pohledávkou a oprávněným; vrací klíč pohledávky (claim_id výsledku).
     */
    private function enforcementClaim(int $employeeId, string $category, string $deliveredOn, int $outstandingMinor): string
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_enforcement_cases
                (supplier_id, employee_id, case_key, case_kind, status,
                 effective_from, evidence_complete, recipient_verified)
             VALUES (?, ?, ?, "enforcement", "remit", "2026-01-01", 1, 1)',
        )->execute([$this->supplierId, $employeeId, 'case-' . bin2hex(random_bytes(6))]);
        $caseId = (int) $pdo->lastInsertId();
        $hash = hash('sha256', "enforcement-decision:{$this->supplierId}:{$caseId}");
        $pdo->prepare(
            'INSERT INTO documents
                (supplier_id, title, original_name, filename, sha256, mime_type,
                 size_bytes, doc_type, source, uploaded_by, scope, owner_user_id)
             VALUES (?, "Syntetický exekuční příkaz", "decision.pdf", ?, ?, "application/pdf", 1, "pdf",
                     "manual", ?, "company", NULL)',
        )->execute([$this->supplierId, "{$hash}.pdf", $hash, $this->actors[0]]);
        $pdo->prepare(
            'INSERT INTO payroll_enforcement_case_parties
                (supplier_id, case_id, party_role, revision_no, effective_from,
                 party_name, party_reference, source_document_id,
                 source_document_sha256, created_by)
             VALUES (?, ?, "beneficiary", 1, "2026-01-01", "Syntetický oprávněný", NULL, ?, ?, ?)',
        )->execute([$this->supplierId, $caseId, (int) $pdo->lastInsertId(), $hash, $this->actors[0]]);
        $claimKey = 'claim-' . bin2hex(random_bytes(6));
        $pdo->prepare(
            'INSERT INTO payroll_enforcement_claims
                (supplier_id, case_id, claim_key, enforcement_order_key,
                 legal_basis, category, outstanding_minor_units,
                 priority_date, first_payer_delivered_on, order_issued_on,
                 legal_title_verified, order_or_notice_delivered,
                 priority_classification_verified, due_monetary_claim_verified, is_active)
             VALUES (?, ?, ?, ?, "statutory", ?, ?, ?, ?, "2026-02-02", 1, 1, 1, 1, 1)',
        )->execute([
            $this->supplierId,
            $caseId,
            $claimKey,
            'order-' . bin2hex(random_bytes(6)),
            $category,
            $outstandingMinor,
            $deliveredOn,
            $deliveredOn,
        ]);

        return $claimKey;
    }

    /**
     * Běh, schválení, příprava JMHZ a test hlášení (XSD, katalog kontrol).
     *
     * @return array{person:array<string,mixed>,statutory:array<string,mixed>,xml:string}
     */
    private function submission(string $scenario): array
    {
        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, "hpp2-srazky-{$scenario}");
        self::assertSame([], $run['blockers'], 'Zaseknutí: výpočet. ' . CanonicalJson::encode($run['blockers']));
        self::assertSame([], $run['warnings'], 'Zaseknutí: varování. ' . CanonicalJson::encode($run['warnings']));
        self::assertNotNull($run['approved']);
        $revisionId = (int) $run['approved']->revision['id'];
        $snapshot = $run['approved']->revision['result_snapshot'];

        $preparation = $this->prepareJmhz($revisionId, "hpp2-srazky-{$scenario}");
        self::assertSame(201, $preparation['status'], CanonicalJson::encode($preparation['body']));
        self::assertSame('source_ready', $preparation['body']['readiness_status'], CanonicalJson::encode($preparation['body']['issues'] ?? []));
        $tested = $this->dryRunJmhz((int) $preparation['body']['id'], $this->officeId);
        self::assertSame(200, $tested['status'], CanonicalJson::encode($tested['body']));
        self::assertSame('dry_run_valid', $tested['body']['status'], CanonicalJson::encode($tested['body']['controls'] ?? $tested['body']));
        self::assertTrue($tested['body']['controls']['submittable']);

        return [
            'person' => $snapshot['people'][0],
            'statutory' => $snapshot['statutory']['people'][0],
            'xml' => (string) preg_replace('/>\s+</', '><', (string) $tested['body']['xml']),
        ];
    }
}
