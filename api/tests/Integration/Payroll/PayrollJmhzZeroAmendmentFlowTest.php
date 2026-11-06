<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollJmhzCorrectionAction;
use MyInvoice\Action\Payroll\PayrollJmhzPost20Action;
use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzAttributeProjection;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzAttributeScope;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzFrozenPayloadReader;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzZeroReportProfile;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Response;

/**
 * Anulace měsíce nulovým opravným hlášením celou cestou účetní: řádné
 * hlášení za červenec se odešle a ČSSZ ho přijme, pak se ukáže, že vztah
 * skončil už 30. 6. (zapomenutá odhláška). Za červenec se proto pošle
 * opravný formulář podle tabulky Pravidel podání JMHZ 1.4.5 „OPRAVNÉ
 * HLÁŠENÍ s nulovými hodnotami za měsíc, který neměl být již hlášen".
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollJmhzZeroAmendmentFlowTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const PERIOD = '2026-07';
    private const PERIOD_START = '2026-07-01';
    private const PAYDAY = '2026-08-14';

    private int $officeId;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('JMHZ0A', 'Syntetická registrace JMHZ', '1100004322');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $component = $this->createComponent('MZDA_ANULACE', 'base_wage', 'regular');
        $mappings = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        self::assertInstanceOf(PayrollComponentJmhzMappingRepository::class, $mappings);
        $mappings->put($this->supplierId, $component, '10329', null, $this->actors[0]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    /**
     * Zapomenutá odhláška: vztah skončil 30. 6., červenec byl přesto
     * nahlášen nulovým formulářem. Anulační formulář nese hodnoty tabulky
     * atribut po atributu, váže se na GUID řádného podání a původní GUID
     * formuláře, projde XSD a kontrolami nad efektivním setem firmy.
     */
    public function testForgottenTerminationMonthIsAnnulledFromFrozenForm(): void
    {
        [$annulled, $kept, $regularId] = $this->acceptedJulyWithTwoEmployees();
        $this->endEmployment($annulled['employment_id'], '2026-06-30');

        $candidates = $this->candidates($regularId);
        self::assertSame(200, $candidates['status'], CanonicalJson::encode($candidates['body']));
        self::assertSame('amendment_unreportable_month', $candidates['body']['situation']);
        $rows = [];
        foreach ($candidates['body']['forms'] as $row) {
            $rows[$row['employment_id']] = $row;
        }
        self::assertTrue($rows[$annulled['employment_id']]['annullable'], CanonicalJson::encode($rows));
        self::assertSame('2026-06-30', $rows[$annulled['employment_id']]['employment_end']);
        self::assertFalse($rows[$kept['employment_id']]['annullable']);
        self::assertSame(
            'jmhz_zero_amendment_employment_active_in_month',
            $rows[$kept['employment_id']]['blocker']['code'],
        );
        $guid = $rows[$annulled['employment_id']]['form_guid'];

        $frozen = $this->freeze($regularId, [$guid]);
        self::assertSame(201, $frozen['status'], CanonicalJson::encode($frozen['body']));
        self::assertSame('correction', $frozen['body']['submission_kind']);
        self::assertSame($regularId, $frozen['body']['corrects_submission_id']);
        self::assertSame('ready', $frozen['body']['status']);

        $reader = $this->container->get(JmhzFrozenPayloadReader::class);
        self::assertInstanceOf(JmhzFrozenPayloadReader::class, $reader);
        $originalXml = $reader->bytes($this->supplierId, 'test', $regularId);
        $amendmentXml = $reader->bytes($this->supplierId, 'test', (int) $frozen['body']['submission_id']);
        $original = JmhzAttributeProjection::fromXml($originalXml);
        $amendment = JmhzAttributeProjection::fromXml($amendmentXml);

        self::assertSame('O', $amendment->submission()->value('10007'));
        self::assertSame($original->submission()->value('10001'), $amendment->submission()->value('10001'));
        self::assertSame('7', $amendment->submission()->value('10010'));
        self::assertSame('2026', $amendment->submission()->value('10011'));
        self::assertNotNull($amendment->submission()->value('10005'));
        self::assertCount(1, $amendment->forms());
        self::assertStringContainsString("<idFormulare>{$guid}</idFormulare>", $amendmentXml);
        self::assertStringContainsString('<typFormulare>O</typFormulare>', $amendmentXml);
        self::assertStringNotContainsString('<so:souhrn', $amendmentXml);
        self::assertStringNotContainsString('<pvpoj:PVPOJ', $amendmentXml);

        $originalForm = null;
        foreach ($original->forms() as $form) {
            if ($form->value('10228') === $amendment->forms()[0]->value('10228')) {
                $originalForm = $form;
            }
        }
        self::assertNotNull($originalForm);
        $this->assertAnnulmentTable($amendment->forms()[0], $originalForm);

        $replayed = $this->freeze($regularId, [$guid]);
        self::assertSame(200, $replayed['status'], CanonicalJson::encode($replayed['body']));
        self::assertSame($frozen['body']['submission_id'], $replayed['body']['submission_id']);
    }

    /**
     * Bez opravy data skončení v evidenci by anulace zrušila pojistný vztah,
     * který podle evidence trvá. Situace se navíc musí poslat výslovně.
     */
    public function testMonthOfActiveEmploymentAndImplicitSituationAreRefused(): void
    {
        [$annulled, , $regularId] = $this->acceptedJulyWithTwoEmployees();
        $guid = $this->formGuidOf($regularId, $annulled['employment_id']);

        $active = $this->freeze($regularId, [$guid]);
        self::assertSame(422, $active['status']);
        self::assertStringContainsString('v anulovaném měsíci trvá', (string) $active['body']['error']['message']);

        $this->endEmployment($annulled['employment_id'], '2026-06-30');
        $implicit = $this->freeze($regularId, [$guid], 'regular_without_work');
        self::assertSame(422, $implicit['status']);
        self::assertStringContainsString('výslovně potvrdit', (string) $implicit['body']['error']['message']);
    }

    /**
     * Průvodce po 20. dni (Pravidla podání 1.4.5, „Postup po 20. dni", B):
     * zapomenutá odhláška = REGZEC akce 2 a nulová opravná hlášení za měsíce
     * po skončení. Průvodce ukáže, že červenec čeká na anulaci a odhláška
     * chybí; po zmrazení anulace je měsíc vyřízený. U neplatného PPV (A)
     * aplikace měsíce v období vztahu sama neanuluje.
     */
    public function testPost20GuidePairsRegistrationWithMonthAmendments(): void
    {
        [$annulled, , $regularId] = $this->acceptedJulyWithTwoEmployees();
        $this->endEmployment($annulled['employment_id'], '2026-06-30');

        $before = $this->guide($annulled['employment_id'], 'late_termination');
        self::assertSame(200, $before['status'], CanonicalJson::encode($before['body']));
        self::assertSame([2], $before['body']['regzec_actions']);
        self::assertSame('amendments_first', $before['body']['order']);
        self::assertFalse($before['body']['registration']['prepared']);
        self::assertSame('open', $before['body']['status']);
        self::assertCount(1, $before['body']['months']);
        $month = $before['body']['months'][0];
        self::assertSame('2026-07', $month['period']);
        self::assertSame($regularId, $month['submission_id']);
        self::assertSame('outside', $month['scope']);
        self::assertSame('annul', $month['action']);

        $dateChange = $this->guide($annulled['employment_id'], 'date_change');
        self::assertSame([3, 4], $dateChange['body']['regzec_actions']);
        self::assertSame('amendments_first', $dateChange['body']['order']);
        self::assertSame(['annul'], array_column($dateChange['body']['months'], 'action'));

        $frozen = $this->freeze($regularId, [$month['form_guid']]);
        self::assertSame(201, $frozen['status'], CanonicalJson::encode($frozen['body']));

        $after = $this->guide($annulled['employment_id'], 'late_termination');
        self::assertSame('amended', $after['body']['months'][0]['action']);
        self::assertSame($frozen['body']['submission_id'], $after['body']['months'][0]['amendments'][0]['submission_id']);
        self::assertSame('partially_done', $after['body']['status']);

        $invalid = $this->guide($annulled['employment_id'], 'invalid_employment');
        self::assertSame([8], $invalid['body']['regzec_actions']);
        self::assertSame('together', $invalid['body']['order']);

        $unknown = $this->guide($annulled['employment_id'], 'storno');
        self::assertSame(422, $unknown['status']);
    }

    /**
     * Neplatný PPV (A): měsíce v období vztahu podle evidence aplikace
     * nulovým hlášením sama neanuluje (fail-closed), průvodce je vede jako
     * ruční krok.
     */
    public function testPost20GuideLeavesMonthsInsideEmploymentToManualStep(): void
    {
        [$annulled] = $this->acceptedJulyWithTwoEmployees();

        $guide = $this->guide($annulled['employment_id'], 'invalid_employment');

        self::assertSame(200, $guide['status'], CanonicalJson::encode($guide['body']));
        self::assertCount(1, $guide['body']['months']);
        self::assertSame('inside', $guide['body']['months'][0]['scope']);
        self::assertSame('manual', $guide['body']['months'][0]['action']);
        self::assertSame(
            'jmhz_zero_amendment_employment_active_in_month',
            $guide['body']['months'][0]['blocker']['code'],
        );
        self::assertSame([], $this->guide($annulled['employment_id'], 'late_termination')['body']['months']);
    }

    /** Anulovat jde až úplně přijaté řádné hlášení. */
    public function testNotYetAcceptedSubmissionCannotBeAnnulled(): void
    {
        [$annulled, , $regularId] = $this->acceptedJulyWithTwoEmployees(accept: false);
        $this->endEmployment($annulled['employment_id'], '2026-06-30');

        $refused = $this->freeze($regularId, [$this->formGuidOf($regularId, $annulled['employment_id'])]);

        self::assertSame(409, $refused['status'], CanonicalJson::encode($refused['body']));
    }

    /**
     * Tabulka anulace atribut po atributu. „Neopravuje se" = hodnota
     * z opravovaného formuláře.
     */
    private function assertAnnulmentTable(JmhzAttributeScope $form, JmhzAttributeScope $original): void
    {
        $insuredFrom = $original->value('10354');
        self::assertSame(self::PERIOD_START, $insuredFrom);
        $checked = 0;
        foreach (JmhzZeroReportProfile::table(JmhzZeroReportProfile::SITUATION_AMENDMENT_UNREPORTABLE_MONTH) as $attributeId => $rule) {
            $attributeId = (string) $attributeId;
            $label = "Atribut {$attributeId} ({$rule})";
            $value = $form->value($attributeId);
            switch ($rule) {
                case JmhzZeroReportProfile::RULE_ZERO:
                case JmhzZeroReportProfile::RULE_ZERO_OR_ACTUAL_WHEN_CONCURRENT:
                    self::assertNotNull($value, $label . ' chybí.');
                    self::assertSame(0.0, (float) $value, $label . ' není nula.');
                    break;
                case JmhzZeroReportProfile::RULE_FALSE:
                    self::assertFalse($form->boolean($attributeId), $label . ' není NE.');
                    break;
                case JmhzZeroReportProfile::RULE_OMIT:
                    self::assertFalse($form->has($attributeId), $label . ' se nemá uvádět.');
                    break;
                case JmhzZeroReportProfile::RULE_UNCHANGED:
                    self::assertSame($original->value($attributeId), $value, $label . ' se nemá opravovat.');
                    break;
                case JmhzZeroReportProfile::RULE_SINGLE_DAY:
                    self::assertSame($insuredFrom, $value, $label . ' netvoří jediný den.');
                    break;
                default:
                    self::fail("{$label}: pravidlo nemá v testu predikát.");
            }
            ++$checked;
        }
        self::assertSame(37, $checked);
        // Prvky mimo tabulku, které by o neexistujícím vztahu něco tvrdily.
        foreach (['10267', '10275', '10357', '10477', '10476'] as $attributeId) {
            self::assertFalse($form->has($attributeId), "Atribut {$attributeId} v anulaci.");
        }
    }

    /**
     * Dva vztahy na rodičovské dovolené, oba bez příjmu; řádné hlášení za
     * červenec zmrazené a (volitelně) přijaté.
     *
     * @return array{0:array{employee_id:int,employment_id:int},1:array{employee_id:int,employment_id:int},2:int}
     */
    private function acceptedJulyWithTwoEmployees(bool $accept = true): array
    {
        $annulled = $this->hireOnParentalLeave('Alena Zapomenutá', '1990-01-10');
        $kept = $this->hireOnParentalLeave('Běla Trvající', '1991-02-11');
        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, 'zero-amendment');
        self::assertSame([], $run['blockers'], CanonicalJson::encode($run['blockers']));
        self::assertSame([], $run['warnings'], CanonicalJson::encode($run['warnings']));
        self::assertNotNull($run['approved']);
        $preparation = $this->prepareJmhz((int) $run['approved']->revision['id'], 'zero-amendment');
        self::assertSame(201, $preparation['status'], CanonicalJson::encode($preparation['body']));
        $frozen = $this->freezeJmhzSubmission((int) $preparation['body']['id'], $this->officeId);
        self::assertSame(201, $frozen['status'], CanonicalJson::encode($frozen['body']));
        $regularId = (int) $frozen['body']['submission_id'];
        if ($accept) {
            $this->acceptJmhzSubmission($regularId);
        }

        return [$annulled, $kept, $regularId];
    }

    /** @return array{employee_id:int,employment_id:int,name:string} */
    private function hireOnParentalLeave(string $name, string $birthDate): array
    {
        $sequence = ++$this->sequence;
        $person = $this->createEmployment(
            $this->officeId,
            $name,
            $sequence,
            'hpp',
            'employment',
            40,
            10_000,
            true,
            '2026-01-01',
            healthTopUpResponsibility: 'employer_obstacle_verified',
        );
        [$firstName, $lastName] = explode(' ', $name, 2);
        $this->completeJmhzEmployment($person, null, [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birth_date' => $birthDate,
            'sex' => 'female',
            'birth_number' => self::syntheticBirthNumber($birthDate, 'female', $sequence),
        ]);
        $this->assignJmhzIdentity($person, self::syntheticOic($sequence), sprintf('5%020d', $sequence));
        $this->publishShifts($person['employment_id'], self::workdays(self::PERIOD));
        $this->createApprovedAbsence($person['employment_id'], 'parental', '2026-06-01', '2026-12-31');
        $response = $this->approveTimeMonth($person['employment_id'], self::PERIOD, []);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $person;
    }

    /**
     * Datum skončení, jak ho zapíše oprava zapomenuté odhlášky. Mzdový běh
     * za červenec je už uzavřený, takže se mění jen evidence vztahu.
     */
    private function endEmployment(int $employmentId, string $endDate): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET end_date = ? WHERE supplier_id = ? AND id = ?',
        )->execute([$endDate, $this->supplierId, $employmentId]);
    }

    private function formGuidOf(int $regularId, int $employmentId): string
    {
        $candidates = $this->candidates($regularId);
        self::assertSame(200, $candidates['status'], CanonicalJson::encode($candidates['body']));
        foreach ($candidates['body']['forms'] as $row) {
            if ($row['employment_id'] === $employmentId) {
                return (string) $row['form_guid'];
            }
        }
        self::fail('Formulář vztahu v podání chybí.');
    }

    /** @return array{status:int,body:array<string,mixed>} */
    private function candidates(int $regularId): array
    {
        return $this->respond($this->action()->zeroAmendmentCandidates(
            $this->request('GET', "/api/payroll/submissions/{$regularId}/jmhz-zero-amendment")
                ->withQueryParams(['environment' => 'test']),
            new Response(),
            ['submissionId' => (string) $regularId],
        ));
    }

    /**
     * @param list<string> $guids
     * @return array{status:int,body:array<string,mixed>}
     */
    private function freeze(int $regularId, array $guids, string $situation = 'amendment_unreportable_month'): array
    {
        return $this->respond($this->action()->freezeZeroAmendment(
            $this->request('POST', "/api/payroll/submissions/{$regularId}/jmhz-zero-amendment")
                ->withParsedBody([
                    'environment' => 'test',
                    'form_guids' => $guids,
                    'situation' => $situation,
                ]),
            new Response(),
            ['submissionId' => (string) $regularId],
        ));
    }

    /** @return array{status:int,body:array<string,mixed>} */
    private function guide(int $employmentId, string $situation): array
    {
        $action = $this->container->get(PayrollJmhzPost20Action::class);
        self::assertInstanceOf(PayrollJmhzPost20Action::class, $action);

        return $this->respond($action(
            $this->request('GET', "/api/payroll/submissions/jmhz-post20/{$employmentId}")
                ->withQueryParams(['environment' => 'test', 'situation' => $situation]),
            new Response(),
            ['employmentId' => (string) $employmentId],
        ));
    }

    private function action(): PayrollJmhzCorrectionAction
    {
        $action = $this->container->get(PayrollJmhzCorrectionAction::class);
        self::assertInstanceOf(PayrollJmhzCorrectionAction::class, $action);

        return $action;
    }

    /** @return array{status:int,body:array<string,mixed>} */
    private function respond(ResponseInterface $response): array
    {
        return ['status' => $response->getStatusCode(), 'body' => $this->json($response)];
    }
}
