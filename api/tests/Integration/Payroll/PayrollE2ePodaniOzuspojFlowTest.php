<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollDiscountIntentAction;
use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojIntentService;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

/**
 * Sleva na pojistném zaměstnavatele za zaměstnance na kratší úvazek
 * (§ 7a zákona č. 589/1992 Sb.) celou cestou: důvod slevy v podmínkách
 * vztahu, záměr, oznámení OZUSPOJ (náhled ověřený proti XSD
 * `api/xsd/cssz/ozuspoj-1.2`, zmrazení do fronty, nic se neodesílá),
 * zápis přijetí a mzdový běh, ve kterém se sleva uplatní.
 *
 * Meze 2026 (ruleset sociálního pojištění, průměrná mzda 48 967 Kč):
 * - sleva 5 % z vyměřovacího základu zaměstnance (§ 7a odst. 1),
 * - sjednaná kratší doba 8 až 30 hodin týdně (§ 7a odst. 2),
 * - nenáleží nad 1,5násobek průměrné mzdy = 73 450,50 Kč, nad 1,15 %
 *   průměrné mzdy na hodinu = 563,12 Kč a nad 138 odpracovaných hodin
 *   (§ 7a odst. 3).
 *
 * Syntetická data, transakci vrací tearDown.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2ePodaniOzuspojFlowTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const PERIOD = '2026-04';
    private const PERIOD_START = '2026-04-01';

    private int $officeId;
    private int $baseComponentId;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('E2EO', 'Syntetická účtárna OZUSPOJ', '1100002345');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->configureIncomeTaxOutput();
        // Kód OSSZ zaměstnavatele z číselníku; nese ho věta OZUSPOJ.
        $this->db->pdo()->prepare('UPDATE payroll_employer_settings SET social_security_office_code = "112" WHERE supplier_id = ?')
            ->execute([$this->supplierId]);
        $this->baseComponentId = $this->createComponent('MZDA_E2E_OZ', 'base_wage', 'regular');
        $mappings = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        self::assertInstanceOf(PayrollComponentJmhzMappingRepository::class, $mappings);
        $mappings->put($this->supplierId, $this->baseComponentId, '10329', null, $this->actors[0]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    /**
     * Zaměstnankyně 61 let (důvod 55+), 20 hodin týdně, mzda 20 000 Kč.
     *
     * Lhůta oznámení záměru od 1. 4. 2026: nejdříve 1 měsíc předem (1. 3.),
     * nejpozději splatnost pojistného za duben = 20. 5. 2026 (středa),
     * § 7a odst. 5 a § 9 odst. 1.
     *
     * Ručně za duben: odpracováno 20 pracovních dnů × 4 h = 80 h (≤ 138),
     * základ na hodinu 20 000 / 80 = 250 Kč (≤ 563,12), základ 20 000 Kč
     * (≤ 73 450,50) → sleva náleží: 5 % × 20 000 = 1 000 Kč. Pojistné
     * zaměstnavatele 24,8 % × 20 000 = 4 960 Kč, po slevě 3 960 Kč.
     */
    public function testAcceptedIntentAppliesFivePercentDiscountInTheRun(): void
    {
        $person = $this->employ('Syntetická Seniorní', '1965-03-01', 'female', 20, 5_000);
        $intent = $this->createIntent($person['employment_id'], '2026-03-25');
        self::assertSame('draft', $intent['status']);
        self::assertSame('2026-03-01', $intent['window']['earliest_notification_on']);
        self::assertSame('2026-05-20', $intent['window']['due_on']);

        $intentId = (int) $intent['id'];
        $preview = $this->call('preview', $intentId);
        self::assertSame(200, $preview['status'], CanonicalJson::encode($preview['body']));
        $xml = (string) $preview['body']['xml'];
        self::assertStringContainsString('2026-04-01', $xml);
        self::assertStringContainsString('112', $xml);
        self::assertFalse($preview['body']['official_submission']['supported']);

        $prepared = $this->call('prepare', $intentId);
        self::assertSame(201, $prepared['status'], CanonicalJson::encode($prepared['body']));
        self::assertSame('ready', $prepared['body']['status']);
        self::assertSame(
            hash('sha256', $xml),
            $prepared['body']['artifact_sha256'],
            'Zmrazené XML musí být bajtově totéž, co ukázal náhled.',
        );
        $this->service()->recordReceipt($this->supplierId, 'production', $intentId, 'accepted', '2026-04-15', null);

        $social = $this->month([[$person, 20_000]]);
        self::assertSame(100_000, $social['part_time_discount_minor_units'], CanonicalJson::encode($social));
        self::assertSame(2_000_000, $social['part_time_discount_assessment_base_minor_units']);
        self::assertSame(496_000, $social['employer_contribution_before_discount_minor_units']);
        self::assertSame(396_000, $social['employer_contribution_minor_units']);
        $relationship = $this->relationship($person['employment_id']);
        self::assertSame('applied', $relationship['part_time_employer_discount_outcome'], CanonicalJson::encode($relationship));
    }

    /**
     * 30 hodin týdně, mzda 74 000 Kč: odpracováno 20 × 6 = 120 h (≤ 138),
     * na hodinu 74 000 / 120 = 616,67 Kč — nad 563,12 Kč, takže i když základ
     * 74 000 Kč převyšuje 73 450,50 Kč, rozhodne už první překročená mez.
     * Sleva nenáleží, pojistné zaměstnavatele 24,8 % × 74 000 = 18 352 Kč.
     * Výsledek musí říct, která mez slevu vyloučila (ne tichá nula).
     */
    public function testDiscountIsNotAppliedAboveTheStatutoryLimits(): void
    {
        $person = $this->employ('Syntetický Vysokopříjmový', '1966-05-05', 'male', 30, 7_500);
        $intentId = (int) $this->createIntent($person['employment_id'], '2026-03-20')['id'];
        $prepared = $this->call('prepare', $intentId);
        self::assertSame(201, $prepared['status'], CanonicalJson::encode($prepared['body']));
        $this->service()->recordReceipt($this->supplierId, 'production', $intentId, 'accepted', '2026-04-10', null);

        $social = $this->month([[$person, 74_000]]);
        self::assertSame(0, $social['part_time_discount_minor_units'], CanonicalJson::encode($social));
        self::assertSame(1_835_200, $social['employer_contribution_minor_units']);
        $relationship = $this->relationship($person['employment_id']);
        self::assertContains(
            $relationship['part_time_employer_discount_outcome'],
            ['hourly_assessment_base_above_limit', 'assessment_base_above_limit'],
            CanonicalJson::encode($relationship),
        );
    }

    /**
     * Bez důvodu slevy v podmínkách vztahu záměr nevznikne: 422 s hláškou,
     * co doplnit a kde (karta vztahu), žádná 500.
     */
    public function testIntentWithoutDiscountReasonNamesWhereToFixIt(): void
    {
        $person = $this->employ('Syntetický Bezdůvodný', '1964-01-01', 'male', 20, 5_000, reason: 'none');
        $action = $this->action();
        $response = $action->create(
            $this->request('POST', '/api/payroll/submissions/discount-intents?environment=production')
                ->withQueryParams(['environment' => 'production'])
                ->withParsedBody(['employment_id' => $person['employment_id'], 'intent_from' => self::PERIOD_START]),
            new Response(),
        );
        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->json($response);
        self::assertSame('ozuspoj_discount_reason_missing', $body['error']['code']);
        self::assertStringContainsString('kartě vztahu', $body['error']['message']);
    }

    /** @return array<string,mixed> */
    private function createIntent(int $employmentId, string $informedOn): array
    {
        $response = $this->action()->create(
            $this->request('POST', '/api/payroll/submissions/discount-intents')
                ->withQueryParams(['environment' => 'production'])
                ->withParsedBody([
                    'employment_id' => $employmentId,
                    'intent_from' => self::PERIOD_START,
                    'employee_informed_on' => $informedOn,
                ]),
            new Response(),
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response);
    }

    /** @return array{status:int,body:array<string,mixed>} */
    private function call(string $method, int $intentId): array
    {
        $response = $this->action()->{$method}(
            $this->request('POST', "/api/payroll/submissions/discount-intents/{$intentId}/{$method}")
                ->withQueryParams(['environment' => 'production', 'submission_kind' => 'start'])
                ->withParsedBody(['environment' => 'production', 'submission_kind' => 'start']),
            new Response(),
            ['intentId' => (string) $intentId],
        );
        self::assertNotSame(500, $response->getStatusCode(), (string) $response->getBody());

        return ['status' => $response->getStatusCode(), 'body' => $this->json($response)];
    }

    private function action(): PayrollDiscountIntentAction
    {
        $action = $this->container->get(PayrollDiscountIntentAction::class);
        self::assertInstanceOf(PayrollDiscountIntentAction::class, $action);

        return $action;
    }

    private function service(): OzuspojIntentService
    {
        $service = $this->container->get(OzuspojIntentService::class);
        self::assertInstanceOf(OzuspojIntentService::class, $service);

        return $service;
    }

    /** @return array{employee_id:int,employment_id:int,name:string,daily:int} */
    private function employ(
        string $name,
        string $birthDate,
        string $sex,
        int $weekly,
        int $workload,
        string $reason = 'age_55_plus',
    ): array {
        $sequence = ++$this->sequence;
        $person = $this->createEmployment(
            $this->officeId,
            $name,
            $sequence,
            'hpp',
            'employment',
            $weekly,
            $workload,
            true,
            self::PERIOD_START,
            healthTopUpResponsibility: 'employee',
        );
        [$first, $last] = explode(' ', $name, 2);
        $this->completeJmhzEmployment($person, null, [
            'first_name' => $first,
            'last_name' => $last,
            'birth_date' => $birthDate,
            'sex' => $sex,
            'birth_number' => self::syntheticBirthNumber($birthDate, $sex, $sequence),
        ]);
        $this->assignJmhzIdentity($person, self::syntheticOic($sequence), sprintf('7%020d', $sequence));
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE payroll_employees SET birth_date = ? WHERE supplier_id = ? AND id = ?')
            ->execute([$birthDate, $this->supplierId, $person['employee_id']]);
        $pdo->prepare('UPDATE payroll_employments SET start_date = ?, actual_start_date = ? WHERE supplier_id = ? AND id = ?')
            ->execute(['2026-03-02', '2026-03-02', $this->supplierId, $person['employment_id']]);
        $pdo->prepare(
            'UPDATE payroll_employment_terms
                SET effective_from = "2026-03-02", planned_start_on = "2026-03-02", actual_start_on = "2026-03-02",
                    social_part_time_discount_reason = ?
              WHERE supplier_id = ? AND employment_id = ?',
        )->execute([$reason, $this->supplierId, $person['employment_id']]);
        $daily = intdiv($weekly * 60, 5);
        $this->ensureRegularCalendar($person['employment_id'], $daily);
        $person['daily'] = $daily;

        return $person;
    }

    /**
     * @param list<array{0:array<string,mixed>,1:int}> $pay
     * @return array<string,mixed> výsledek sociálního pojištění firmy za měsíc
     */
    private function month(array $pay): array
    {
        foreach ($pay as [$person, $gross]) {
            $this->publishShifts($person['employment_id'], self::workdays(self::PERIOD));
            $this->db->pdo()->prepare(
                'INSERT IGNORE INTO payroll_enforcement_person_month_evidence
                    (supplier_id, employee_id, period_start, claim_register_evidence_complete,
                     dependants_evidence_complete, spouse_evidence_complete, pension_evidence, updated_by)
                 VALUES (?, ?, ?, 1, 1, 1, "none", ?)',
            )->execute([$this->supplierId, $person['employee_id'], self::PERIOD_START, $this->actors[0]]);
            $time = $this->approveTimeMonth($person['employment_id'], self::PERIOD, self::workdays(self::PERIOD), dailyMinutes: $person['daily']);
            self::assertSame(200, $time->getStatusCode(), 'Zaseknutí: docházka ' . (string) $time->getBody());
            $this->createApprovedInput($person, $this->baseComponentId, $gross * 100, "e2e-oz-{$person['employment_id']}", self::PERIOD_START);
        }
        $run = $this->runPayrollMonth(self::PERIOD_START, '2026-05-10', $this->officeId, 'e2e-oz-' . self::PERIOD);
        self::assertSame([], $run['blockers'], 'Zaseknutí: výpočet ' . CanonicalJson::encode($run['blockers']));
        self::assertSame([], $run['warnings'], 'Zaseknutí: varování ' . CanonicalJson::encode($run['warnings']));
        self::assertNotNull($run['approved'], 'Zaseknutí: běh neschválen');
        $json = $this->scalar(
            'SELECT result_snapshot_json FROM payroll_statutory_results WHERE supplier_id = ? AND revision_id = ? AND calculation_kind = "social_insurance"',
            [$this->supplierId, (int) $run['approved']->revision['id']],
        );
        $social = json_decode((string) $json, true);
        self::assertIsArray($social);

        return $social;
    }

    /** @return array<string,mixed> výsledek sociálního pojištění vztahu ve schválené revizi */
    private function relationship(int $employmentId): array
    {
        $json = $this->scalar(
            'SELECT relationship.result_snapshot_json
               FROM payroll_statutory_relationship_results relationship
               JOIN payroll_run_revisions revision ON revision.supplier_id = relationship.supplier_id AND revision.id = relationship.revision_id
              WHERE relationship.supplier_id = ? AND relationship.employment_id = ?
                AND relationship.calculation_kind = "social_insurance" AND revision.status = "approved"
              ORDER BY relationship.id DESC LIMIT 1',
            [$this->supplierId, $employmentId],
        );
        $relationship = json_decode((string) $json, true);
        self::assertIsArray($relationship, "Vztah {$employmentId} ve výsledku chybí.");

        return $relationship;
    }
}
