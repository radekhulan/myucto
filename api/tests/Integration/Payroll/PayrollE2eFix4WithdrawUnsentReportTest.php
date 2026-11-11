<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollUnsentSubmissionWithdrawAction;
use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzFrozenPayloadReader;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

/**
 * Zmrazené, ale neodeslané řádné měsíční hlášení jde po opravě mzdy zrušit
 * a připravit znovu z opravené revize (dříve otevřený nález O2 scénářů HPP).
 * Odeslané hlášení zrušit nejde, tam vede cesta přes opravné hlášení
 * ({@see PayrollE2eHpp2CorrectionTest}).
 *
 * HPP 45 000 Kč, duben 2026; po opravě přibude odměna 2 000 Kč. Ruční
 * přepočet: původně SP 3 195, ZP zaměstnanec 2 025, záloha 6 750 − 2 570
 * = 4 180, čistá 35 600 Kč; po opravě SP 3 337, ZP zaměstnanec 2 115, záloha
 * 7 050 − 2 570 = 4 480, čistá 37 068 Kč.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eFix4WithdrawUnsentReportTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const PERIOD = '2026-04';
    private const PERIOD_START = '2026-04-01';
    private const PAYDAY = '2026-05-14';

    private int $officeId;
    private int $baseComponentId;
    private int $bonusComponentId;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('ZRU', 'Syntetická účtárna zrušení', '1100001237');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->baseComponentId = $this->createComponent('MZDA_MESICNI_ZRU', 'base_wage', 'regular');
        $this->bonusComponentId = $this->createComponent('ODMENA_ZRU', 'bonus', 'one_off');
        $mappings = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        self::assertInstanceOf(PayrollComponentJmhzMappingRepository::class, $mappings);
        $mappings->put($this->supplierId, $this->baseComponentId, '10329', null, $this->actors[0]);
        $mappings->put($this->supplierId, $this->bonusComponentId, '10331', null, $this->actors[0]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testFrozenUnsentReportIsWithdrawnAndPreparedAgainFromTheCorrectedRun(): void
    {
        [$regularId, $correctedPreparationId] = $this->frozenReportAndCorrectedPreparation();

        // Druhé řádné hlášení založit nejde a hláška říká obě cesty dál.
        $refused = $this->freezeJmhzSubmission($correctedPreparationId, $this->officeId);
        self::assertSame(409, $refused['status'], CanonicalJson::encode($refused['body']));
        $message = (string) $refused['body']['error']['message'];
        self::assertStringContainsString('ještě nebylo odesláno', $message);
        self::assertStringContainsString('„Zrušit přípravu“', $message);
        self::assertStringContainsString('opravným hlášením', $message);

        // Zrušení vyžaduje výslovné potvrzení i důvod.
        $rowVersion = (int) $this->scalar('SELECT row_version FROM payroll_submissions WHERE supplier_id = ? AND id = ?', [$this->supplierId, $regularId]);
        $unconfirmed = $this->withdraw($regularId, ['row_version' => $rowVersion, 'reason' => 'Oprava mzdy po zmrazení.']);
        self::assertSame(422, $unconfirmed->getStatusCode(), (string) $unconfirmed->getBody());
        self::assertStringContainsString('nebylo odesláno ani nahráno na ePortál', $this->json($unconfirmed)['error']['message']);
        $withoutReason = $this->withdraw($regularId, ['row_version' => $rowVersion, 'confirm_not_sent' => true, 'reason' => ' ']);
        self::assertSame(422, $withoutReason->getStatusCode(), (string) $withoutReason->getBody());
        self::assertSame('ready', $this->submissionStatus($regularId), 'Odmítnuté zrušení nesmí nic změnit.');

        $withdrawn = $this->withdraw($regularId, [
            'row_version' => $rowVersion,
            'confirm_not_sent' => true,
            'reason' => 'Oprava mzdy po zmrazení: zapomenutá odměna za duben.',
        ]);
        self::assertSame(200, $withdrawn->getStatusCode(), (string) $withdrawn->getBody());
        self::assertSame('cancelled_in_time', $this->json($withdrawn)['submission']['status']);
        self::assertSame('cancelled_in_time', $this->submissionStatus($regularId));
        self::assertSame('cancelled', $this->scalar(
            'SELECT obligation.status FROM payroll_obligations obligation
               JOIN payroll_submissions submission
                 ON submission.supplier_id = obligation.supplier_id AND submission.obligation_id = obligation.id
              WHERE submission.supplier_id = ? AND submission.id = ?',
            [$this->supplierId, $regularId],
        ));
        // Auditní stopa: kdo, co a proč.
        $audit = $this->db->pdo()->prepare(
            'SELECT user_id, payload FROM activity_log
              WHERE supplier_id = ? AND action = "payroll.submission.unsent_withdrawn"
                AND entity_type = "payroll_submission" AND entity_id = ?',
        );
        $audit->execute([$this->supplierId, $regularId]);
        $logged = $audit->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(1, $logged);
        self::assertSame($this->actors[0], (int) $logged[0]['user_id']);
        $payload = json_decode((string) $logged[0]['payload'], true);
        self::assertSame('Oprava mzdy po zmrazení: zapomenutá odměna za duben.', $payload['reason']);
        self::assertSame(self::PERIOD_START, $payload['period_start']);

        // Nové řádné hlášení z opravené revize: nové částky, nový GUID podání.
        $refrozen = $this->freezeJmhzSubmission($correctedPreparationId, $this->officeId);
        self::assertSame(201, $refrozen['status'], CanonicalJson::encode($refrozen['body']));
        self::assertSame('ready', $refrozen['body']['status']);
        $newId = (int) $refrozen['body']['submission_id'];
        self::assertNotSame($regularId, $newId);
        $reader = $this->container->get(JmhzFrozenPayloadReader::class);
        self::assertInstanceOf(JmhzFrozenPayloadReader::class, $reader);
        $old = (string) preg_replace('/>\s+</', '><', $reader->bytes($this->supplierId, 'test', $regularId));
        $new = (string) preg_replace('/>\s+</', '><', $reader->bytes($this->supplierId, 'test', $newId));
        foreach ([
            '<typPodani>R</typPodani>',
            '<form:zuctovanoCelkem>47000</form:zuctovanoCelkem>',
            '<form:tarif>45000</form:tarif>',
            '<form:odmenyNepravidelne>2000</form:odmenyNepravidelne>',
            '<form:danZalohaPoSleve>4480</form:danZalohaPoSleve>',
            '<form:mzdaCista>37068</form:mzdaCista>',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $new);
        }
        self::assertStringContainsString('<form:zuctovanoCelkem>45000</form:zuctovanoCelkem>', $old, 'Zrušené hlášení zůstává v historii beze změny.');
        self::assertNotSame(self::submissionGuid($old), self::submissionGuid($new), 'Nové hlášení nese vlastní GUID podání.');

        // Zrušené hlášení se podruhé zrušit nedá a nové je k odeslání jediné.
        $again = $this->withdraw($regularId, ['row_version' => (int) $this->json($withdrawn)['submission']['row_version'], 'confirm_not_sent' => true, 'reason' => 'Znovu.']);
        self::assertSame(409, $again->getStatusCode(), (string) $again->getBody());
        self::assertSame(1, (int) $this->scalar(
            'SELECT COUNT(*) FROM payroll_submissions WHERE supplier_id = ? AND status = "ready"',
            [$this->supplierId],
        ));
    }

    public function testSentReportCannotBeWithdrawn(): void
    {
        [$regularId] = $this->frozenReportAndCorrectedPreparation();
        $this->acceptJmhzSubmission($regularId);
        $rowVersion = (int) $this->scalar('SELECT row_version FROM payroll_submissions WHERE supplier_id = ? AND id = ?', [$this->supplierId, $regularId]);

        $response = $this->withdraw($regularId, ['row_version' => $rowVersion, 'confirm_not_sent' => true, 'reason' => 'Oprava mzdy.']);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        self::assertStringContainsString('už bylo odesláno', $this->json($response)['error']['message']);
        self::assertStringContainsString('opravným hlášením', $this->json($response)['error']['message']);
        self::assertSame('accepted', $this->submissionStatus($regularId));
    }

    /**
     * Schválený duben se zmrazeným řádným hlášením, pak oprava běhu
     * (zapomenutá odměna 2 000 Kč) a nová příprava z opravené revize.
     *
     * @return array{int,int} id zmrazeného řádného podání a id nové přípravy
     */
    private function frozenReportAndCorrectedPreparation(): array
    {
        $person = $this->hire();
        $this->createApprovedInput($person, $this->baseComponentId, 4_500_000, 'base', self::PERIOD_START);
        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, 'zru');
        self::assertSame([], $run['blockers'], CanonicalJson::encode($run['blockers']));
        self::assertNotNull($run['approved']);
        self::assertSame(3_560_000, $run['approved']->revision['result_snapshot']['statutory']['people'][0]['net_payable_minor_units']);
        $preparation = $this->prepareJmhz((int) $run['approved']->revision['id'], 'zru');
        self::assertSame('source_ready', $preparation['body']['readiness_status'], CanonicalJson::encode($preparation['body']));
        $frozen = $this->freezeJmhzSubmission((int) $preparation['body']['id'], $this->officeId);
        self::assertSame(201, $frozen['status'], CanonicalJson::encode($frozen['body']));
        self::assertSame('ready', $frozen['body']['status']);

        $runId = (int) $run['approved']->run['id'];
        $requested = $this->runs->requestCorrection($this->supplierId, $runId, (int) $run['approved']->run['row_version'], 'zru-oprava-request', $this->actors[0], 'Zapomenutá odměna za duben.');
        $reopened = $this->runs->reopen($this->supplierId, $runId, (int) $requested->run['row_version'], 'zru-oprava-reopen', $this->actors[0], 'Zapomenutá odměna za duben.');
        $this->createApprovedInput($person, $this->bonusComponentId, 200_000, 'bonus', self::PERIOD_START);
        $refreshed = $this->runs->refreshInputs($this->supplierId, $runId, (int) $reopened->run['row_version'], 'zru-oprava-refresh', $this->actors[0]);
        $calculated = $this->runs->calculate($this->supplierId, $runId, (int) $refreshed->run['row_version'], 'zru-oprava-calculate', $this->actors[0]);
        self::assertSame([], $this->blockingValidations((int) $calculated->revision['id']), 'Zaseknutí: přepočet opravy.');
        $corrected = $this->runs->approve($this->supplierId, $runId, (int) $calculated->run['row_version'], 'zru-oprava-approve', $this->actors[0]);
        self::assertSame(3_706_800, $corrected->revision['result_snapshot']['statutory']['people'][0]['net_payable_minor_units']);
        $repared = $this->prepareJmhz((int) $corrected->revision['id'], 'zru-fix');
        self::assertSame('source_ready', $repared['body']['readiness_status'], CanonicalJson::encode($repared['body']));

        return [(int) $frozen['body']['submission_id'], (int) $repared['body']['id']];
    }

    /** @param array<string,mixed> $body */
    private function withdraw(int $submissionId, array $body): \Psr\Http\Message\ResponseInterface
    {
        $action = $this->container->get(PayrollUnsentSubmissionWithdrawAction::class);
        self::assertInstanceOf(PayrollUnsentSubmissionWithdrawAction::class, $action);

        return $action(
            $this->request('POST', "/api/payroll/submissions/queue/{$submissionId}/withdraw")
                ->withParsedBody($body + ['environment' => 'test']),
            new Response(),
            ['submissionId' => (string) $submissionId],
        );
    }

    private function submissionStatus(int $submissionId): string
    {
        return (string) $this->scalar(
            'SELECT status FROM payroll_submissions WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $submissionId],
        );
    }

    private static function submissionGuid(string $xml): string
    {
        self::assertSame(1, preg_match('#<idPodani>([^<]+)</idPodani>#', $xml, $match), 'Hlášení nemá GUID podání.');

        return $match[1];
    }

    /** @return array{employee_id:int,employment_id:int,name:string} */
    private function hire(): array
    {
        $person = $this->createEmployment($this->officeId, 'Syntetická Zrušená', 1, 'hpp', 'employment', 40, 10_000, true, self::PERIOD_START);
        $this->completeJmhzEmployment($person, identity: [
            'first_name' => 'Syntetická',
            'last_name' => 'Zrušená',
            'birth_date' => '1986-06-06',
            'sex' => 'female',
            'birth_number' => self::syntheticBirthNumber('1986-06-06', 'female', 1),
        ]);
        $this->assignJmhzIdentity($person, self::syntheticOic(1), sprintf('2%020d', 1));
        $workdays = self::workdays(self::PERIOD);
        $this->publishShifts($person['employment_id'], $workdays);
        $this->createApprovedAverage($person['employment_id'], 2);
        self::assertSame(200, $this->approveTimeMonth($person['employment_id'], self::PERIOD, $workdays)->getStatusCode());

        return $person;
    }
}
