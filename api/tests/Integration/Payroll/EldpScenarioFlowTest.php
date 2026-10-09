<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollEldpAction;
use MyInvoice\Action\Payroll\PayrollPensionRequestAction;
use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Repository\Payroll\PayrollPensionRequestRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpStatementCopyService;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpStatementService;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpValidationException;
use MyInvoice\Service\Payroll\Submission\Eldp\PensionInsuranceCertificateService;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

/**
 * Evidenční list cestou účetní: založení osoby s hlavním pracovním poměrem
 * a dohodou o pracovní činnosti, docházka, mzdový běh, schválení a příprava
 * evidenčního listu na výzvu ČSSZ. Dřív list pro DPČ vůbec nevznikl
 * („evidenční list zatím podporuje jen pracovní poměr").
 *
 * Všechna data jsou syntetická; transakci vrací tearDown.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class EldpScenarioFlowTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const PERIOD = '2026-07';
    private const PERIOD_START = '2026-07-01';
    private const PAYDAY = '2026-08-14';

    private int $officeId;
    private int $baseComponentId;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('ELDP', 'Syntetická registrace ELDP', '9990004321');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->baseComponentId = $this->createComponent('MZDA_MESICNI_ELDP', 'base_wage', 'regular');
        $mappings = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        self::assertInstanceOf(PayrollComponentJmhzMappingRepository::class, $mappings);
        $mappings->put($this->supplierId, $this->baseComponentId, '10329', null, $this->actors[0]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testAgreementToCompleteAJobReachesAStatementOnAuthorityRequest(): void
    {
        [, $agreement] = $this->approvedJuly();

        $service = $this->container->get(EldpStatementService::class);
        self::assertInstanceOf(EldpStatementService::class, $service);
        $prepared = $service->prepare(
            $this->supplierId,
            $agreement['employment_id'],
            2026,
            'test',
            [
                'excluded_days_confirmed' => true,
                'deducted_days_none' => true,
                'pension_status' => ['pension_age_reached_on' => null, 'early_pension_from' => null, 'full_pension_paid_from' => null, 'foreign_insurance' => false],
                'requested_by_authority' => true,
                'authority_request_received_on' => '2026-08-20',
                'note' => 'Syntetická výzva ČSSZ.',
            ],
            'eldp-flow-dpc',
            $this->actors[0],
        );

        self::assertTrue($prepared['created']);
        self::assertSame('01', $prepared['eldp_type']);
        self::assertSame(31, $prepared['insurance_days']);

        $statement = $service->statement($this->supplierId, 'test', $agreement['employment_id'], 2026);
        self::assertIsArray($statement);
        $section = $statement['payload']['eldp_sections'][0];
        self::assertSame('A++', $section['code']);
        self::assertSame('2026-07-01', $section['valid_from']);
        self::assertSame('2026-07-31', $section['valid_to']);
        self::assertSame(10_000, $section['assessment_base_czk']);
        self::assertSame('2026-07-01', $statement['payload']['form']['employed_from']);
        self::assertSame('2026-07-31', $statement['payload']['form']['prepared_on']);
    }

    /**
     * ELDP na výzvu z evidence výzev: zapsaná výzva hlídá lhůtu v přehledu
     * termínů, list se připraví s údaji výzvy (týž termín), výzva se na list
     * naváže a termín pak nese povinnost listu. Stejnopis předaný zaměstnanci
     * se zapíše k výzvě. Potvrzení podle § 42 za týž rok skládá týž sestavovač.
     */
    public function testAuthorityRequestFromTheRegisterIsTrackedAndPreparedTheSameWay(): void
    {
        [$person, $agreement] = $this->approvedJuly();
        $actions = $this->container->get(PayrollPensionRequestAction::class);
        self::assertInstanceOf(PayrollPensionRequestAction::class, $actions);
        $employeeId = (int) $agreement['employee_id'];
        $uri = "/api/payroll/people/{$employeeId}/pension-requests";

        $created = $this->json($actions->save(
            $this->request('POST', $uri)->withParsedBody([
                'request_kind' => 'eldp',
                'requester' => 'ossz',
                'requester_reference' => 'SYN-OSSZ-1/2026',
                'received_on' => '2026-08-20',
                'employment_id' => $agreement['employment_id'],
                'period_year' => 2026,
            ]),
            new Response(),
            ['id' => (string) $employeeId],
        ));
        $request = $created['requests'][0];
        self::assertSame('2026-08-28', $request['due_on']);
        self::assertSame('open', $request['status']);
        $repository = new PayrollPensionRequestRepository($this->db);
        self::assertSame(
            [$request['id']],
            array_column($repository->openDeadlines($this->supplierId, '2026-08-01', '2026-09-30'), 'request_id'),
        );

        $eldp = $this->container->get(PayrollEldpAction::class);
        self::assertInstanceOf(PayrollEldpAction::class, $eldp);
        $prepared = $this->json($eldp->prepare(
            $this->request('POST', '/api/payroll/submissions/eldp')->withParsedBody([
                'employment_id' => $agreement['employment_id'],
                'year' => 2026,
                'environment' => 'test',
                'excluded_days_confirmed' => true,
                'death_on' => null,
                // Údaje výzvy převezme server ze zapsané výzvy.
                'requested_by_authority' => false,
                'authority_request_received_on' => null,
                'pension_status' => ['pension_age_reached_on' => null, 'early_pension_from' => null, 'full_pension_paid_from' => null, 'foreign_insurance' => false],
                'note' => '',
                'idempotency_key' => 'eldp-request-flow',
                'pension_request_id' => $request['id'],
            ]),
            new Response(),
        ));
        self::assertSame('2026-08-28', $prepared['statement']['due_on']);

        $listed = $repository->list($this->supplierId, $employeeId)[0];
        self::assertSame('statement_prepared', $listed['status']);
        self::assertSame($prepared['statement']['statement_id'], $listed['eldp_statement_id']);
        self::assertSame([], $repository->openDeadlines($this->supplierId, '2026-08-01', '2026-09-30'));

        $copied = $this->json($actions->save(
            $this->request('POST', $uri)->withParsedBody([
                'id' => $request['id'],
                'copy_delivered' => true,
                'copy_delivered_on' => '2026-08-27',
            ]),
            new Response(),
            ['id' => (string) $employeeId],
        ));
        self::assertSame('2026-08-27', $copied['requests'][0]['copy_delivered_on']);
        $this->completeCopyIdentity($employeeId);
        $copy = $eldp->copy(
            $this->request('GET', '/api/payroll/submissions/eldp/copy')->withQueryParams([
                'employment_id' => (string) $agreement['employment_id'],
                'year' => '2026',
                'environment' => 'test',
            ]),
            new Response(),
        );
        self::assertSame(200, $copy->getStatusCode(), (string) $copy->getBody());
        $copy->getBody()->rewind();
        self::assertStringStartsWith('%PDF-', (string) $copy->getBody());

        $confirmation = $this->json($actions->save(
            $this->request('POST', $uri)->withParsedBody([
                'request_kind' => 'insurance_period_confirmation',
                'requester' => 'employee',
                'received_on' => '2026-09-01',
                'employment_id' => $person['employment_id'],
                'period_year' => 2026,
            ]),
            new Response(),
            ['id' => (string) $employeeId],
        ))['requests'][0];
        self::assertSame('2026-09-09', $confirmation['due_on']);
        $certificates = $this->container->get(PensionInsuranceCertificateService::class);
        self::assertInstanceOf(PensionInsuranceCertificateService::class, $certificates);
        $data = $certificates->data($this->supplierId, $employeeId, $confirmation['id']);
        self::assertSame([['from' => '2026-07-01', 'to' => '2026-07-31', 'days' => 31, 'months_without_insurance' => []]], $data['periods']);
        $pdf = $certificates->render($this->supplierId, $employeeId, $confirmation['id']);
        self::assertStringStartsWith('%PDF-', $pdf['pdf']);
    }

    /**
     * § 38 odst. 4 a 5 zákona č. 582/1991 Sb. ve znění do 31. 12. 2025:
     * stejnopis nese údaje listu podle odst. 4, tedy identifikaci občana
     * (jméno, příjmení, rodné příjmení, rodné číslo, datum a místo narození,
     * trvalý pobyt) a zaměstnavatele (název, IČ, sídlo, variabilní symbol).
     * Chybějící údaj stejnopis zastaví, nedoplňuje se odhadem.
     */
    public function testCopyCarriesIdentificationOfCitizenAndEmployer(): void
    {
        [$person, $agreement] = $this->approvedJuly();
        $this->prepareOnAuthorityRequest($agreement['employment_id'], 'eldp-copy-identity');
        $copies = $this->container->get(EldpStatementCopyService::class);
        self::assertInstanceOf(EldpStatementCopyService::class, $copies);

        try {
            $copies->template($this->supplierId, 'test', $agreement['employment_id'], 2026);
            self::fail('Bez rodného příjmení a trvalého pobytu se stejnopis vydat nesmí.');
        } catch (EldpValidationException $exception) {
            self::assertSame('eldp_copy_identity_incomplete', $exception->validationCode);
            self::assertContains('rodné příjmení občana', $exception->blockers[0]['detail']['missing']);
            self::assertContains('adresa trvalého pobytu občana', $exception->blockers[0]['detail']['missing']);
        }

        $this->completeCopyIdentity((int) $person['employee_id']);
        $template = $copies->template($this->supplierId, 'test', $agreement['employment_id'], 2026);
        self::assertSame(self::syntheticBirthNumber('1985-03-14', 'female', 1), $template['employee']['birth_number']);
        self::assertSame('Syntetický zaměstnavatel', $template['employer']['name']);
        self::assertNotSame('', $template['employer']['variable_symbol']);
        $html = $copies->html($template);
        foreach ([
            'Dohodová Olga',
            'Rodné příjmení: Nováková',
            'Rodné číslo: ' . self::syntheticBirthNumber('1985-03-14', 'female', 1),
            'Datum narození: 14.03.1985',
            'Místo narození: Testov',
            'Trvalý pobyt: Zkušební 7, 110 00 Praha 1',
            'IČ: 00000019',
            'Variabilní symbol: ' . $template['employer']['variable_symbol'],
            '<td>N</td>',
        ] as $expected) {
            self::assertStringContainsString($expected, $html);
        }
    }

    private function prepareOnAuthorityRequest(int $employmentId, string $idempotencyKey): void
    {
        $service = $this->container->get(EldpStatementService::class);
        self::assertInstanceOf(EldpStatementService::class, $service);
        $service->prepare(
            $this->supplierId,
            $employmentId,
            2026,
            'test',
            [
                'excluded_days_confirmed' => true,
                'deducted_days_none' => true,
                'pension_status' => ['pension_age_reached_on' => null, 'early_pension_from' => null, 'full_pension_paid_from' => null, 'foreign_insurance' => false],
                'requested_by_authority' => true,
                'authority_request_received_on' => '2026-08-20',
                'note' => 'Syntetická výzva ČSSZ.',
            ],
            $idempotencyKey,
            $this->actors[0],
        );
    }

    /** Rodné příjmení a trvalý pobyt, které sdílený tok nezakládá. */
    private function completeCopyIdentity(int $employeeId): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'UPDATE payroll_person_identity_history SET birth_surname = "Nováková"
              WHERE supplier_id = ? AND employee_id = ?',
        )->execute([$this->supplierId, $employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_person_addresses
                (supplier_id, employee_id, address_type, street_line, city, postal_code, country_code, effective_from)
             VALUES (?, ?, "residence", "Zkušební 7", "Praha 1", "110 00", "CZ", "2026-01-01")',
        )->execute([$this->supplierId, $employeeId]);
    }

    /**
     * Osoba s pracovním poměrem a DPČ od 1. 7. 2026, schválená mzda za červenec.
     *
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function approvedJuly(): array
    {
        $person = $this->createEmployment(
            $this->officeId,
            'Olga Dohodová',
            1,
            'hpp',
            'employment',
            40,
            10_000,
            true,
            self::PERIOD_START,
        );
        $this->completeJmhzEmployment($person, identity: [
            'first_name' => 'Olga',
            'last_name' => 'Dohodová',
            'birth_date' => '1985-03-14',
            'sex' => 'female',
            'birth_number' => self::syntheticBirthNumber('1985-03-14', 'female', 1),
        ]);
        $this->assignJmhzIdentity($person, self::syntheticOic(1), sprintf('2%020d', 1));
        $this->publishShifts($person['employment_id'], self::workdays(self::PERIOD));
        $this->createApprovedAverage($person['employment_id'], 3);

        $agreement = $this->createEmployment(
            $this->officeId,
            $person['name'],
            2,
            'dpc',
            'dpc',
            10,
            2_500,
            true,
            self::PERIOD_START,
            existingEmployeeId: $person['employee_id'],
        );
        $this->completeJmhzEmployment($agreement, withIdentity: false);
        $this->assignJmhzIdentity($agreement, null, sprintf('2%020d', 2));
        $this->createApprovedAverage($agreement['employment_id'], 3);
        // Sdílený tok zakládá vztahy od 1. 1.; evidenční list na výzvu pokrývá
        // jen měsíce se schválenou mzdou, takže vztahy začínají až červencem.
        $this->startOn($person['employment_id']);
        $this->startOn($agreement['employment_id']);

        foreach ([
            [$person, self::workdays(self::PERIOD), 480, 4_000_000],
            [$agreement, ['2026-07-04', '2026-07-11', '2026-07-18'], 240, 1_000_000],
        ] as [$relation, $days, $minutes, $amount]) {
            $response = $this->approveTimeMonth($relation['employment_id'], self::PERIOD, $days, dailyMinutes: $minutes);
            self::assertSame(200, $response->getStatusCode(), 'Zaseknutí: schválení docházky. ' . (string) $response->getBody());
            $this->createApprovedInput($relation, $this->baseComponentId, $amount, 'base-' . $relation['employment_id'], self::PERIOD_START);
        }

        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, 'eldp-flow');
        self::assertSame([], $run['blockers'], 'Zaseknutí: výpočet mzdy. ' . CanonicalJson::encode($run['blockers']));
        self::assertSame([], $run['warnings'], 'Zaseknutí: varování. ' . CanonicalJson::encode($run['warnings']));
        self::assertNotNull($run['approved']);

        return [$person, $agreement];
    }

    /** Vztah i jeho podmínky začínají až vykazovaným měsícem. */
    private function startOn(int $employmentId): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'UPDATE payroll_employments SET start_date = ?, actual_start_date = ?
              WHERE supplier_id = ? AND id = ?',
        )->execute([self::PERIOD_START, self::PERIOD_START, $this->supplierId, $employmentId]);
        $pdo->prepare(
            'UPDATE payroll_employment_terms
                SET effective_from = ?, planned_start_on = ?, actual_start_on = ?
              WHERE supplier_id = ? AND employment_id = ?',
        )->execute([
            self::PERIOD_START,
            self::PERIOD_START,
            self::PERIOD_START,
            $this->supplierId,
            $employmentId,
        ]);
    }
}
