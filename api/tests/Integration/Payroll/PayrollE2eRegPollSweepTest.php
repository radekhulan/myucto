<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use MyInvoice\Repository\Payroll\PayrollSigningProfileRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionTransportAttemptRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzFrozenPayloadReader;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzDispatchService;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolSignatureVerifierInterface;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzSoftwareIdentification;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzTransportSweepService;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzVrepClient;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationTransportService;
use MyInvoice\Service\Signing\PersonalCertificateVaultService;
use MyInvoice\Tests\Support\PayrollE2eRegistrationTrait;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use MyInvoice\Tests\Unit\Payroll\Submission\CsszFormProtocolSample;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Výsledek odeslané přihlášky A1 dotahuje noční `cron-jmhz-poll`
 * ({@see JmhzTransportSweepService}). VREP je falešný (fronta odpovědí),
 * podpis protokolu ověřuje dvojník, na ČSSZ se nic neposílá. Všechno ostatní
 * je skutečné: ledger pokusů, registrační transport, import protokolu
 * a zápis OIČ a ID PPV.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eRegPollSweepTest extends TestCase
{
    use PayrollFullFlowTrait;
    use PayrollE2eRegistrationTrait;

    private const START_ON = '2026-11-16';

    private PayrollSubmissionTransportAttemptRepository $attempts;

    protected function setUp(): void
    {
        $this->bootRegistrationFlow('2026-11-11');
        $this->attempts = new PayrollSubmissionTransportAttemptRepository($this->db);
        if (!$this->attempts->isAvailable()) {
            self::markTestSkipped('Ledger pokusů o odeslání chybí.');
        }
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    /**
     * Přijatá přihláška: první běh dotáhne protokol, zapíše OIČ a ID PPV
     * jako převzaté z protokolu a uzavře transakci. Druhý běh už nemá co
     * dělat a nic nezdvojí; A1 se pro vztah znovu nenabídne.
     */
    public function testAcceptedProtocolAssignsIdentifiersOnceAndSecondRunDoesNothing(): void
    {
        [$employeeId, $employmentId] = $this->regPerson('Dotažená', 'Přijatá', 61, 'dpp', self::START_ON);
        $this->regSaveA1Profile($employmentId, $this->regA1Profile(self::START_ON, 'T'));
        $prepared = $this->regPrepare($employmentId, ['registration_mode' => 'full']);
        $correlation = 'B0000000000000000000000000000061';
        $attemptId = $this->markSent($prepared, $correlation);
        $birthNumber = $this->birthNumber((int) $prepared['submission_id']);

        $first = $this->sweep([
            $this->protocol($correlation, 'OK', $birthNumber . ';1000000012;200000000000000000061'),
            $this->acknowledgement($correlation),
        ])->run();

        self::assertSame(1, $first['polled'], CanonicalJson::encode($first));
        self::assertSame(1, $first['completed'], CanonicalJson::encode($first));
        self::assertSame(1, $first['closed'], CanonicalJson::encode($first));
        self::assertSame(0, $first['errors'], CanonicalJson::encode($first));
        self::assertSame('accepted', $this->regSubmissionStatus((int) $prepared['submission_id']));
        self::assertSame('2026-11-16', $this->regIdentifier($employmentId, 'id_ppv'));
        self::assertSame(['trusted_receipt'], $this->identifierSources($employeeId));
        $attempt = $this->attempts->find($this->supplierId, 'test', $attemptId);
        self::assertNotNull($attempt);
        self::assertSame('completed', $attempt['status']);
        self::assertNotNull($attempt['closed_at']);

        $counts = $this->identifierCounts($employeeId, (int) $prepared['submission_id']);
        $second = $this->sweep([])->run();

        self::assertSame(0, $second['polled'], CanonicalJson::encode($second));
        self::assertSame(0, $second['errors'], CanonicalJson::encode($second));
        self::assertSame($counts, $this->identifierCounts($employeeId, (int) $prepared['submission_id']));
        self::assertSame([1, 1, 1], $counts);
        $again = $this->regPrepareRaw($employmentId, ['registration_mode' => 'full']);
        self::assertNotSame(201, $again['status'], 'Přihlášený vztah dostal druhou A1.');
        self::assertSame(1, (int) $this->scalar(
            'SELECT COUNT(*) FROM payroll_submissions WHERE supplier_id = ?',
            [$this->supplierId],
        ));
    }

    /**
     * Jeden běh, dvě přihlášky: ČSSZ jednu přijme a druhou odmítne.
     * Identifikátory dostane jen přijatá; odmítnutá je vidět jako odmítnutá
     * a po opravě údajů jde připravit nová přihláška.
     */
    public function testOneRunSettlesAcceptedAndRejectedRegistrationSeparately(): void
    {
        [$acceptedEmployee, $accepted] = $this->regPerson('Dotažená', 'První', 63, 'dpp', self::START_ON);
        $this->regSaveA1Profile($accepted, $this->regA1Profile(self::START_ON, 'T'));
        $acceptedPrepared = $this->regPrepare($accepted, ['registration_mode' => 'full']);
        $this->markSent($acceptedPrepared, 'B0000000000000000000000000000063');
        [$rejectedEmployee, $rejected] = $this->regPerson('Dotažená', 'Druhá', 65, 'dpp', self::START_ON);
        $profile = $this->regA1Profile(self::START_ON, 'T');
        $this->regSaveA1Profile($rejected, $profile);
        $rejectedPrepared = $this->regPrepare($rejected, ['registration_mode' => 'full']);
        $this->markSent($rejectedPrepared, 'B0000000000000000000000000000065');

        $result = $this->sweep([
            $this->protocol(
                'B0000000000000000000000000000063',
                'OK',
                $this->birthNumber((int) $acceptedPrepared['submission_id']) . ';1000000023;200000000000000000063',
            ),
            $this->acknowledgement('B0000000000000000000000000000063'),
            $this->protocol(
                'B0000000000000000000000000000065',
                'ERROR',
                $this->birthNumber((int) $rejectedPrepared['submission_id']),
                'Chyba 604: zaměstnanec je u zaměstnavatele už přihlášen.',
                '604',
            ),
            $this->acknowledgement('B0000000000000000000000000000065'),
        ])->run();

        self::assertSame(2, $result['polled'], CanonicalJson::encode($result));
        self::assertSame(2, $result['completed'], CanonicalJson::encode($result));
        self::assertSame(2, $result['closed'], CanonicalJson::encode($result));
        self::assertSame('accepted', $this->regSubmissionStatus((int) $acceptedPrepared['submission_id']));
        self::assertSame('rejected', $this->regSubmissionStatus((int) $rejectedPrepared['submission_id']));
        self::assertSame('2026-11-16', $this->regIdentifier($accepted, 'id_ppv'));
        self::assertSame(['trusted_receipt'], $this->identifierSources($acceptedEmployee));
        self::assertNull($this->regIdentifier($rejected, 'id_ppv'));
        self::assertSame([], $this->identifierSources($rejectedEmployee));

        $profile['employment']['position_name'] = 'Skladník';
        $this->regSaveA1Profile($rejected, $profile);
        $next = $this->regPrepare($rejected, ['registration_mode' => 'full']);
        self::assertNotSame((int) $rejectedPrepared['submission_id'], (int) $next['submission_id']);
    }

    /**
     * ČSSZ ještě zpracovává: běh nic nezapíše a pokus nechá otevřený, další
     * běh protokol dotáhne.
     */
    public function testStillProcessingKeepsTheAttemptOpenForTheNextRun(): void
    {
        [$employeeId, $employmentId] = $this->regPerson('Dotažená', 'Čekající', 67, 'dpp', self::START_ON);
        $this->regSaveA1Profile($employmentId, $this->regA1Profile(self::START_ON, 'T'));
        $prepared = $this->regPrepare($employmentId, ['registration_mode' => 'full']);
        $correlation = 'B0000000000000000000000000000067';
        $attemptId = $this->markSent($prepared, $correlation);

        $pending = $this->sweep([$this->acknowledgement($correlation)])->run();

        self::assertSame(1, $pending['polled'], CanonicalJson::encode($pending));
        self::assertSame(1, $pending['pending'], CanonicalJson::encode($pending));
        self::assertSame(0, $pending['completed'], CanonicalJson::encode($pending));
        self::assertSame('submitted', $this->regSubmissionStatus((int) $prepared['submission_id']));
        self::assertSame([], $this->identifierSources($employeeId));
        $attempt = $this->attempts->find($this->supplierId, 'test', $attemptId);
        self::assertNotNull($attempt);
        self::assertSame('awaiting_protocol', $attempt['status']);
        // Další dotaz je naplánovaný podle PollInterval; test rozvrh přeskočí.
        $this->db->pdo()->prepare(
            'UPDATE payroll_submission_transport_attempts SET next_retry_at = NULL, row_version = row_version + 1 WHERE id = ?',
        )->execute([$attemptId]);

        $done = $this->sweep([
            $this->protocol(
                $correlation,
                'OK',
                $this->birthNumber((int) $prepared['submission_id']) . ';1000000034;200000000000000000067',
            ),
            $this->acknowledgement($correlation),
        ])->run();

        self::assertSame(1, $done['completed'], CanonicalJson::encode($done));
        self::assertSame('accepted', $this->regSubmissionStatus((int) $prepared['submission_id']));
        self::assertSame(['trusted_receipt'], $this->identifierSources($employeeId));
    }

    // ───────────────────────── příprava ─────────────────────────

    /**
     * Podání ve stavu „odesláno" s otevřeným pokusem v ledgeru, jako po
     * tlačítku Odeslat. Vrací číslo pokusu.
     *
     * @param array<string,mixed> $prepared
     */
    private function markSent(array $prepared, string $correlation): int
    {
        $submissions = $this->container->get(PayrollSubmissionService::class);
        self::assertInstanceOf(PayrollSubmissionService::class, $submissions);
        $submissionId = (int) $prepared['submission_id'];
        $adopted = $submissions->adoptDispatchChannel($this->supplierId, $submissionId, JmhzDispatchService::CHANNEL);
        $attempt = $this->attempts->open(
            $this->supplierId,
            'test',
            $submissionId,
            JmhzDispatchService::CHANNEL,
            1,
            'synthetic-e2e-reg-sweep-' . $submissionId,
            hash('sha256', $this->regArtifactXml($submissionId)),
            $this->actors[0],
        );
        $attempt = $this->attempts->markSent((int) $attempt['id'], $correlation, 200, (int) $attempt['row_version']);
        $submissions->transition(
            $this->supplierId,
            $submissionId,
            (int) $adopted['row_version'],
            'submitted',
            $correlation,
        );

        return (int) $attempt['id'];
    }

    /** @param list<Response> $queue */
    private function sweep(array $queue): JmhzTransportSweepService
    {
        $profiles = $this->container->get(PayrollSigningProfileRepository::class);
        $vault = $this->container->get(PersonalCertificateVaultService::class);
        $secrets = $this->container->get(SecretEncryption::class);
        $submissions = $this->container->get(PayrollSubmissionService::class);
        $frozen = $this->container->get(JmhzFrozenPayloadReader::class);
        $clock = $this->container->get(ClockInterface::class);
        self::assertInstanceOf(PayrollSigningProfileRepository::class, $profiles);
        self::assertInstanceOf(PersonalCertificateVaultService::class, $vault);
        self::assertInstanceOf(SecretEncryption::class, $secrets);
        self::assertInstanceOf(PayrollSubmissionService::class, $submissions);
        self::assertInstanceOf(JmhzFrozenPayloadReader::class, $frozen);
        self::assertInstanceOf(ClockInterface::class, $clock);
        $repository = new PayrollSubmissionRepository($this->db);
        $dispatch = new JmhzDispatchService(
            $this->attempts,
            $profiles,
            $vault,
            $secrets,
            new JmhzSoftwareIdentification('MyUcto', '1.0'),
            new JmhzVrepClient(
                new Client(['handler' => HandlerStack::create(new MockHandler($queue)), 'http_errors' => false]),
                'test',
            ),
            frozen: $frozen,
            submissions: $submissions,
            signatures: new class () implements JmhzProtocolSignatureVerifierInterface {
                public function verifiedProtocolXml(string $bytes, string $environment): string
                {
                    return $bytes;
                }
            },
        );

        return new JmhzTransportSweepService(
            $this->attempts,
            $repository,
            $frozen,
            $dispatch,
            new PayrollRegistrationTransportService($repository, $this->attempts, $frozen, $dispatch, $clock),
        );
    }

    private function protocol(
        string $correlation,
        string $result,
        string $identifier,
        string $message = '',
        string $code = '',
    ): Response {
        $body = CsszFormProtocolSample::protocol(
            'CSSZ_REGZEC',
            $result === 'OK' ? 'response' : 'error',
            $result,
            [
                CsszFormProtocolSample::item('REGZEC25', '', '', 'OK'),
                CsszFormProtocolSample::item('REGZEC25', '1', $identifier, $result, $message, $code),
            ],
            $message,
            $code === '' ? '0' : $code,
        );

        return new Response(
            200,
            ['Content-Type' => 'text/xml'],
            str_replace(CsszFormProtocolSample::CORRELATION, $correlation, $body),
        );
    }

    private function acknowledgement(string $correlation): Response
    {
        return new Response(
            200,
            ['Content-Type' => 'text/xml'],
            '<?xml version="1.0" encoding="utf-8"?>'
            . '<GovTalkMessage xmlns="http://www.govtalk.gov.uk/CM/envelope">'
            . '<EnvelopeVersion>2.0</EnvelopeVersion><Header><MessageDetails>'
            . '<Class>CSSZ_REGZEC</Class><Qualifier>acknowledgement</Qualifier>'
            . '<Function>submit</Function><TransactionID />'
            . '<CorrelationID>' . $correlation . '</CorrelationID>'
            . '<ResponseEndPoint PollInterval="60">https://t-epodani.cssz.cz/VREP/poll</ResponseEndPoint>'
            . '<GatewayTimestamp>2026-11-11T12:00:00.000</GatewayTimestamp>'
            . '</MessageDetails><SenderDetails /></Header>'
            . '<GovTalkDetails><Keys /></GovTalkDetails><Body /></GovTalkMessage>',
        );
    }

    private function birthNumber(int $submissionId): string
    {
        self::assertSame(1, preg_match('/ bno="(\d{9,10})"/', $this->regArtifactXml($submissionId), $match));

        return $match[1];
    }

    /** @return list<string> */
    private function identifierSources(int $employeeId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT source_kind FROM payroll_person_external_ids
              WHERE supplier_id = ? AND employee_id = ? AND environment = "test"
              ORDER BY id',
        );
        $statement->execute([$this->supplierId, $employeeId]);

        return array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return array{0:int,1:int,2:int} OIČ osoby, ID PPV vztahů osoby, protokoly podání */
    private function identifierCounts(int $employeeId, int $submissionId): array
    {
        return [
            (int) $this->scalar(
                'SELECT COUNT(*) FROM payroll_person_external_ids WHERE supplier_id = ? AND employee_id = ?',
                [$this->supplierId, $employeeId],
            ),
            (int) $this->scalar(
                'SELECT COUNT(*) FROM payroll_employment_external_ids WHERE supplier_id = ? AND employee_id = ?',
                [$this->supplierId, $employeeId],
            ),
            (int) $this->scalar(
                'SELECT COUNT(*) FROM payroll_submission_receipts WHERE supplier_id = ? AND submission_id = ?',
                [$this->supplierId, $submissionId],
            ),
        ];
    }
}
