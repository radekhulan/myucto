<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollEmploymentAction;
use MyInvoice\Action\Payroll\PayrollInputsAction;
use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Payroll\PayrollEmploymentBulkClosure;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Run\PayrollRunReadinessService;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

/**
 * Případ z podpory agentury: brigádník měl v lednu DPP, která skončila, a na
 * únor má novou navazující dohodu. Schválený únorový vstup ale zůstal na
 * lednové, už skončené dohodě. Kontrola únorového běhu radí vstup zrušit
 * a vede na výpis vstupů; tam ho uživatel s právem schvalovat mzdy zrušit
 * musí umět a skončená dohoda pak z únorové kontroly zmizí.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eDppInputCancellationTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const PERIOD = '2026-02';
    private const PERIOD_START = '2026-02-01';
    private const PAYDAY = '2026-03-13';

    private int $officeId;
    private int $baseComponentId;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('DPP', 'Syntetická agentura', '1100001237');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->baseComponentId = $this->createComponent('ODMENA_DOHODA', 'base_wage', 'regular');
        $mappings = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        \assert($mappings instanceof PayrollComponentJmhzMappingRepository);
        $mappings->put($this->supplierId, $this->baseComponentId, '10329', null, $this->actors[0]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    /**
     * Schválený vstup za únor na lednové dohodě ještě nebyl v běhu. Účetní
     * bez práva schvalovat dostane srozumitelné odmítnutí, schvalovatel ho
     * zruší a únorový běh pak počítá jen navazující dohodu.
     */
    public function testApprovedFebruaryInputOnEndedJanuaryAgreementIsCancelledFromTheList(): void
    {
        $january = $this->hire('Nela Navazující', 'female', '1998-03-09');
        $this->endAgreement($january['employment_id'], '2026-01-31');
        $closure = $this->container->get(PayrollEmploymentBulkClosure::class);
        \assert($closure instanceof PayrollEmploymentBulkClosure);
        self::assertSame([$january['employment_id']], $closure->endExpiredAgreements($this->supplierId, '2026-02-03')['ended']);
        $february = $this->addAgreement($january, '2026-02-01', null, true);
        $this->work($february, ['2026-02-10'], 400_000);
        // Únorový vstup, který skončil na lednové dohodě (import před založením nové).
        $this->createApprovedInput($january, $this->baseComponentId, 350_000, 'unor-na-lednove', self::PERIOD_START);

        $readiness = $this->container->get(PayrollRunReadinessService::class);
        \assert($readiness instanceof PayrollRunReadinessService);
        $before = $readiness->inspect($this->supplierId, self::PERIOD_START, self::PAYDAY, $this->officeId)['findings'];
        $stray = array_values(array_filter($before, static fn (array $f): bool => $f['code'] === 'input_after_employment_end'));
        self::assertCount(1, $stray, CanonicalJson::encode(array_column($before, 'code')));
        self::assertSame('blocker', $stray[0]['severity']);
        self::assertStringContainsString('vstup za 02/2026 je na vztahu ukončeném 31. 1. 2026', $stray[0]['message']);
        self::assertStringContainsString('vstup zrušte', $stray[0]['message']);
        // Odkaz vede na výpis vstupů února zúžený na skončenou dohodu.
        self::assertSame(
            "/payroll/components?tab=inputs&period=2026-02&employment={$january['employment_id']}",
            $stray[0]['remediation_path'],
        );
        self::assertContains($january['employment_id'], $this->employmentsNamedBy($before));

        // Výpis, na který odkaz vede, vstup ukáže jako schválený a mimo běh.
        $listed = $this->listInputs($january['employment_id']);
        self::assertCount(1, $listed);
        self::assertSame('approved', $listed[0]['status']);
        self::assertSame(350_000, (int) $listed[0]['amount_minor']);
        self::assertNull($listed[0]['benefit_basket'] ?? null);
        $inputId = (int) $listed[0]['id'];
        $version = (int) $listed[0]['row_version'];

        // Kdo smí vstupy jen zapisovat, schválený vstup nezruší a dozví se proč.
        $clerk = $this->cancelInput($inputId, $version, ['payroll.inputs.write' => AccessLevel::WRITE->value]);
        self::assertSame(409, $clerk->getStatusCode(), (string) $clerk->getBody());
        self::assertSame(
            'Schválený mzdový vstup může zrušit jen uživatel s právem schvalovat mzdy.',
            $this->json($clerk)['error']['message'],
        );

        // Uživatel s právem schvalovat mzdy ho z výpisu zruší.
        $approver = $this->cancelInput($inputId, $version, [
            'payroll.inputs.write' => AccessLevel::WRITE->value,
            'payroll.approve' => AccessLevel::WRITE->value,
        ]);
        self::assertSame(200, $approver->getStatusCode(), 'API odmítlo zrušení schváleného vstupu mimo běh: ' . (string) $approver->getBody());
        self::assertSame('cancelled', $this->json($approver)['input']['status']);
        self::assertSame([], $this->listInputs($january['employment_id']));

        // Skončená lednová dohoda v únorové kontrole běhu už není.
        $after = $readiness->inspect($this->supplierId, self::PERIOD_START, self::PAYDAY, $this->officeId)['findings'];
        self::assertNotContains('input_after_employment_end', array_column($after, 'code'));
        self::assertNotContains($january['employment_id'], $this->employmentsNamedBy($after), CanonicalJson::encode($after));

        // A únorový běh počítá jen navazující dohodu.
        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, 'cancel-february');
        self::assertSame([], $run['blockers'], CanonicalJson::encode($run['blockers']));
        self::assertNotNull($run['approved']);
        $members = $this->db->pdo()->prepare(
            'SELECT employment_id FROM payroll_run_employments WHERE supplier_id = ? AND revision_id = ?',
        );
        $members->execute([$this->supplierId, (int) $run['approved']->revision['id']]);
        self::assertSame([$february['employment_id']], array_map('intval', $members->fetchAll(\PDO::FETCH_COLUMN)));
    }

    /**
     * Vztahy, které kontrola běhu jmenuje (u nálezů vázaných na vztah).
     *
     * @param list<array<string,mixed>> $findings
     * @return list<int>
     */
    private function employmentsNamedBy(array $findings): array
    {
        $ids = [];
        foreach ($findings as $finding) {
            foreach ($finding['entities'] ?? [] as $entity) {
                if (($entity['entity_type'] ?? null) === 'employment' && $entity['entity_id'] !== null) {
                    $ids[] = (int) $entity['entity_id'];
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /** @return list<array<string,mixed>> */
    private function listInputs(int $employmentId): array
    {
        $action = $this->container->get(PayrollInputsAction::class);
        \assert($action instanceof PayrollInputsAction);
        $response = $action->list(
            $this->request('GET', '/api/payroll/inputs')->withQueryParams(['period' => self::PERIOD, 'employment_id' => (string) $employmentId]),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response)['inputs'];
    }

    /** @param array<string,string> $permissions */
    private function cancelInput(int $inputId, int $rowVersion, array $permissions): \Psr\Http\Message\ResponseInterface
    {
        $action = $this->container->get(PayrollInputsAction::class);
        \assert($action instanceof PayrollInputsAction);

        return $action->cancel(
            $this->request('POST', "/api/payroll/inputs/{$inputId}/cancel")
                ->withAttribute('auth.effective_role', new EffectiveRole(42, 'Mzdová účetní', 'staff', true, $permissions))
                ->withParsedBody(['row_version' => $rowVersion]),
            new Response(),
            ['id' => (string) $inputId],
        );
    }

    /**
     * @return array{employee_id:int,employment_id:int,name:string}
     */
    private function hire(string $name, string $sex, string $birthDate): array
    {
        $sequence = ++$this->sequence;
        $person = $this->createEmployment(
            $this->officeId,
            $name,
            $sequence,
            'dpp',
            'dpp',
            10,
            2_500,
            true,
            self::PERIOD_START,
        );
        [$firstName, $lastName] = explode(' ', $name, 2);
        $this->completeJmhzEmployment($person, identity: [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birth_date' => $birthDate,
            'sex' => $sex,
            'birth_number' => self::syntheticBirthNumber($birthDate, $sex, $sequence),
        ]);
        $this->assignJmhzIdentity($person, self::syntheticOic($sequence), sprintf('2%020d', $sequence));
        $this->probableAverage($person['employment_id'], 2026, 1);
        $this->publishShifts($person['employment_id'], self::workdays(self::PERIOD));

        return $person;
    }

    /** Sjednaný konec dohody (podmínky i vztah), jako když ho účetní zapíše na kartě. */
    private function endAgreement(int $employmentId, string $endOn): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE payroll_employments SET end_date = ? WHERE supplier_id = ? AND id = ?')
            ->execute([$endOn, $this->supplierId, $employmentId]);
        $pdo->prepare('UPDATE payroll_employment_terms SET fixed_term_end_on = ? WHERE supplier_id = ? AND employment_id = ?')
            ->execute([$endOn, $this->supplierId, $employmentId]);
    }

    private function moveStart(int $employmentId, string $startOn): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE payroll_employments SET start_date = ?, actual_start_date = ? WHERE supplier_id = ? AND id = ?')
            ->execute([$startOn, $startOn, $this->supplierId, $employmentId]);
        $pdo->prepare(
            'UPDATE payroll_employment_terms SET effective_from = ?, planned_start_on = ?, actual_start_on = ?
              WHERE supplier_id = ? AND employment_id = ?',
        )->execute([$startOn, $startOn, $startOn, $this->supplierId, $employmentId]);
    }

    /**
     * Další dohoda téže osoby přes kartu osoby (POST /people/{id}/employments).
     *
     * @param array{employee_id:int,employment_id:int,name:string} $person
     * @return array{employee_id:int,employment_id:int,name:string}
     */
    private function addAgreement(array $person, string $startOn, ?string $endOn, bool $primary): array
    {
        $response = $this->createAgreementResponse($person, $startOn, $endOn, $primary);
        self::assertSame(201, $response->getStatusCode(), 'Zaseknutí: založení dohody. ' . (string) $response->getBody());
        $employmentId = (int) $this->json($response)['employment']['id'];
        $agreement = ['employee_id' => $person['employee_id'], 'employment_id' => $employmentId, 'name' => $person['name']];
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET status = "active", actual_start_date = start_date WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $employmentId]);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms SET actual_start_on = planned_start_on WHERE supplier_id = ? AND employment_id = ?',
        )->execute([$this->supplierId, $employmentId]);
        $this->completeJmhzEmployment($agreement, withIdentity: false);
        $sequence = ++$this->sequence;
        $identities = $this->container->get(\MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService::class);
        $identities->assignManualJmhzIdentity(
            $this->supplierId,
            $employmentId,
            'test',
            null,
            sprintf('2%020d', $sequence),
            $startOn,
            null,
            true,
            $this->actors[0],
        );
        $this->probableAverage($employmentId, 2026, 1);
        $this->publishShifts($employmentId, self::workdays(self::PERIOD));

        return $agreement;
    }

    /** @param array{employee_id:int,employment_id:int,name:string} $person */
    private function createAgreementResponse(array $person, string $startOn, ?string $endOn, bool $primary): \Psr\Http\Message\ResponseInterface
    {
        $action = $this->container->get(PayrollEmploymentAction::class);
        \assert($action instanceof PayrollEmploymentAction);

        return $action->create(
            $this->request('POST', "/api/payroll/people/{$person['employee_id']}/employments")->withParsedBody([
                'code' => '',
                'relation_type' => 'dpp',
                'confirm_new_agreement' => true,
                'monthly_gross_minor' => null,
                'terms' => [
                    'office_id' => $this->officeId,
                    'effective_from' => $startOn,
                    'contract_signed_on' => $startOn,
                    'planned_start_on' => $startOn,
                    'actual_start_on' => null,
                    'fixed_term_end_on' => $endOn,
                    'weekly_hours' => '10',
                    'workload_basis_points' => 2500,
                    'work_place' => 'Hlavní město Praha',
                    'regular_workplace' => 'Hlavní město Praha',
                    'jmhz_workplace_municipality_code' => '554782',
                    'jmhz_workplace_country_code' => 'CZ',
                    'jmhz_apz_contribution_status' => 'no',
                    'jmhz_apz_instrument_code' => null,
                    'jmhz_functional_benefits_status' => 'no',
                    'jmhz_temporary_assignment_status' => 'no',
                    'cz_isco_code' => '43111',
                    'activity_code' => null,
                    'jmhz_relationship_detail_code' => null,
                    'social_insurance_participation' => 'automatic',
                    'health_insurance_participation' => 'automatic',
                    'tax_regime' => 'advance',
                    'foreign_legislation_country_code' => null,
                    'a1_certificate_until' => null,
                    'risky_work' => false,
                    'tax_declaration_signed' => true,
                    'is_primary' => $primary,
                    'change_reason' => 'Navazující dohoda agentury',
                ],
            ]),
            new Response(),
            ['id' => (string) $person['employee_id']],
        );
    }

    /**
     * @param array{employee_id:int,employment_id:int,name:string} $person
     * @param list<string> $days
     */
    private function work(array $person, array $days, int $amountMinor): void
    {
        $response = $this->approveTimeMonth($person['employment_id'], self::PERIOD, $days, dailyMinutes: 240);
        self::assertSame(200, $response->getStatusCode(), 'Zaseknutí: docházka. ' . (string) $response->getBody());
        $this->createApprovedInput($person, $this->baseComponentId, $amountMinor, 'odmena-' . $person['employment_id'], self::PERIOD_START);
    }

    private function probableAverage(int $employmentId, int $year, int $quarter): void
    {
        $first = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, ($quarter - 1) * 3 + 1));
        $from = $first->modify('-3 months');
        $response = $this->absences->createAverage(
            $this->request('POST', '/api/payroll/absences/average')->withParsedBody([
                'employment_id' => $employmentId,
                'applicable_year' => $year,
                'applicable_quarter' => $quarter,
                'decisive_from' => $from->format('Y-m-d'),
                'decisive_to' => $first->modify('-1 day')->format('Y-m-d'),
                'gross_earnings_minor' => 0,
                'longer_period_allocated_minor' => 0,
                'worked_minutes' => 0,
                'worked_days' => 0,
                'probable_hourly_minor' => 20_000,
                'rationale' => 'Pravděpodobný výdělek ze sjednané hodinové odměny dohody.',
            ]),
            new Response(),
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $average = $this->json($response)['snapshot'];
        $approved = $this->absences->approveAverage(
            $this->request('POST', '/api/payroll/absences/average/approve')->withParsedBody(['row_version' => $average['row_version']]),
            new Response(),
            ['id' => (string) $average['id']],
        );
        self::assertSame(200, $approved->getStatusCode(), (string) $approved->getBody());
    }

    private function submission(string $key, string $periodStart = self::PERIOD_START, string $payday = self::PAYDAY): string
    {
        $run = $this->runPayrollMonth($periodStart, $payday, $this->officeId, "primary-{$key}");
        self::assertSame([], $run['blockers'], 'Zaseknutí: výpočet. ' . CanonicalJson::encode($run['blockers']));
        self::assertSame([], $run['warnings'], 'Zaseknutí: varování. ' . CanonicalJson::encode($run['warnings']));
        $preparation = $this->prepareJmhz((int) $run['approved']->revision['id'], "primary-{$key}");
        self::assertSame(201, $preparation['status'], CanonicalJson::encode($preparation['body']));
        self::assertSame(
            'source_ready',
            $preparation['body']['readiness_status'],
            'Zaseknutí: příprava hlášení. ' . CanonicalJson::encode($preparation['body']['issues'] ?? []),
        );
        $tested = $this->dryRunJmhz((int) $preparation['body']['id'], $this->officeId);
        self::assertSame(200, $tested['status'], CanonicalJson::encode($tested['body']));
        self::assertSame(
            'dry_run_valid',
            $tested['body']['status'],
            'Zaseknutí: XSD nebo kontroly. ' . CanonicalJson::encode(array_diff_key($tested['body'], ['xml' => true])),
        );
        self::assertTrue($tested['body']['controls']['submittable']);

        return (string) preg_replace('/>\s+</', '><', (string) $tested['body']['xml']);
    }
}
