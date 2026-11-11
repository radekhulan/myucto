<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use MyInvoice\Repository\Payroll\PayrollSigningProfileRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionTransportAttemptRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzFrozenPayloadReader;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzDispatchService;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolSignatureVerifierInterface;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzSoftwareIdentification;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzTransportSweepService;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzVrepClient;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationTransportService;
use MyInvoice\Service\Signing\PersonalCertificateVaultService;
use MyInvoice\Tests\Unit\Payroll\Submission\CsszFormProtocolSample;
use Psr\Clock\ClockInterface;

/**
 * Dotažení výsledku odeslané registrace nočním `cron-jmhz-poll` nad
 * {@see PayrollE2eRegistrationTrait}: podání ve stavu „odesláno" s pokusem
 * v ledgeru, falešný VREP (fronta odpovědí) a syntetický protokol ČSSZ.
 * Podpis protokolu ověřuje dvojník, na ČSSZ se nic neposílá.
 */
trait PayrollE2eRegSweepTrait
{
    private PayrollSubmissionTransportAttemptRepository $attempts;

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
}
