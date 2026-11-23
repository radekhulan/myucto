<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollEmploymentAction;
use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

/**
 * Hlavní vztah (JMHZ 10495) u brigádníků agentury: navazující DPP na každou
 * akci, překryv o jeden den, dvě dohody od téhož dne a HPP s dohodou.
 *
 * Kontroly 255 a 260 katalogu chtějí v podání právě jeden primární vztah
 * osoby. Každý scénář jde cestou účetní: založení dohody na kartě osoby,
 * docházka, odměna, běh, příprava hlášení a dry-run s XSD a katalogem.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eDppPrimaryEmploymentTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const PERIOD = '2026-04';
    private const PERIOD_START = '2026-04-01';
    private const PAYDAY = '2026-05-14';

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
     * První DPP skončí 15. 4., navazující začne 16. 4. bez překryvu. Nová
     * dohoda převezme hlavní roli, ale za duben vykazuje osoba obě. V dubnovém
     * hlášení smí být hlavní jen jedna (kontrola 255), v květnu je hlavní ta
     * nová jako jediný vztah osoby.
     */
    public function testFollowUpAgreementWithoutOverlapLeavesOnePrimaryInTransitionMonth(): void
    {
        $person = $this->hire('Petra Navazující', 'female', '1998-05-05');
        $this->endAgreement($person['employment_id'], '2026-04-15');
        $second = $this->addAgreement($person, '2026-04-16', null, true);

        self::assertSame(0, (int) $this->scalar(
            'SELECT is_primary FROM payroll_employments WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $person['employment_id']],
        ), 'Skončená dohoda hlavní roli nepředala.');
        self::assertSame(1, (int) $this->scalar(
            'SELECT is_primary FROM payroll_employments WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $second['employment_id']],
        ));

        $this->work($person, ['2026-04-07'], 300_000);
        $this->work($second, ['2026-04-21'], 400_000);
        $xml = $this->submission('follow-up');
        self::assertSame(2, substr_count($xml, '</formularOsoby>'));
        self::assertSame(1, substr_count($xml, '<primarniPpv>true</primarniPpv>'), 'V dubnu musí být hlavní právě jedna dohoda.');
    }

    /**
     * Dohody se překrývají o jeden den (první do 16. 4., druhá od 16. 4.).
     * Nová dohoda vznikne jako vedlejší, v dubnu je hlavní ta první.
     */
    public function testOneDayOverlapKeepsTheFirstAgreementPrimary(): void
    {
        $person = $this->hire('Olga Překryvová', 'female', '1997-06-06');
        $this->endAgreement($person['employment_id'], '2026-04-16');
        $rejected = $this->createAgreementResponse($person, '2026-04-16', null, true);
        self::assertNotSame(201, $rejected->getStatusCode(), (string) $rejected->getBody());
        self::assertStringContainsString('primární', mb_strtolower((string) $rejected->getBody()));
        $second = $this->addAgreement($person, '2026-04-16', null, false);

        $this->work($person, ['2026-04-07'], 300_000);
        $this->work($second, ['2026-04-21'], 400_000);
        $xml = $this->submission('one-day-overlap');
        self::assertSame(1, substr_count($xml, '<primarniPpv>true</primarniPpv>'));
        self::assertSame(1, substr_count($xml, '<primarniPpv>false</primarniPpv>'));
    }

    /** Dvě dohody od téhož dne: hlavní je ta, kterou evidence vede jako hlavní. */
    public function testTwoAgreementsStartingTheSameDay(): void
    {
        $person = $this->hire('Hana Souběžná', 'female', '1996-07-07');
        $this->moveStart($person['employment_id'], '2026-04-01');
        $second = $this->addAgreement($person, '2026-04-01', '2026-04-30', false);

        $this->work($person, ['2026-04-07'], 500_000);
        $this->work($second, ['2026-04-08'], 500_000);
        $xml = $this->submission('same-day');
        self::assertSame(2, substr_count($xml, '</formularOsoby>'));
        self::assertSame(1, substr_count($xml, '<primarniPpv>true</primarniPpv>'));
    }

    /**
     * Hlavní vztah pro hlášení za květen, kdy osoba vykazuje už jen
     * navazující dohodu: jediný vztah v podání je hlavní.
     */
    public function testOnlyFollowUpAgreementInNextMonthIsPrimary(): void
    {
        $person = $this->hire('Rita Květnová', 'female', '1995-08-08');
        $this->endAgreement($person['employment_id'], '2026-04-15');
        // Navazující dohoda vznikla jako vedlejší (např. starším importem)
        // a skončená dohoda podmínky už opravit nedovolí.
        $second = $this->addAgreement($person, '2026-04-16', null, false);
        $this->work($person, ['2026-04-07'], 300_000);
        $this->work($second, ['2026-04-21'], 400_000);
        $this->submission('may-before');

        $this->publishShifts($second['employment_id'], self::workdays('2026-05'));
        $this->approveTimeMonth($second['employment_id'], '2026-05', ['2026-05-12'], dailyMinutes: 240);
        $this->createApprovedInput($second, $this->baseComponentId, 400_000, 'may-' . $second['employment_id'], '2026-05-01');
        $xml = $this->submission('may', '2026-05-01', '2026-06-12');
        self::assertSame(1, substr_count($xml, '</formularOsoby>'));
        self::assertSame(1, substr_count($xml, '<primarniPpv>true</primarniPpv>'));
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
        $this->probableAverage($person['employment_id'], 2026, 2);
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
        $this->probableAverage($employmentId, 2026, 2);
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
