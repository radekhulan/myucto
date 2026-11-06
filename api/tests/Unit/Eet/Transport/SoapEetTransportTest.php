<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Eet\Transport;

use MyInvoice\Service\Eet\Transport\EetEnvironment;
use MyInvoice\Service\Eet\Transport\EetHttpClientInterface;
use MyInvoice\Service\Eet\Transport\EetHttpException;
use MyInvoice\Service\Eet\Transport\EetHttpResponse;
use MyInvoice\Service\Eet\Transport\EetMessageHeader;
use MyInvoice\Service\Eet\Transport\EetOutcome;
use MyInvoice\Service\Eet\Transport\EetResponseParser;
use MyInvoice\Service\Eet\Transport\EetResponseVerifier;
use MyInvoice\Service\Eet\Transport\EetSaleData;
use MyInvoice\Service\Eet\Transport\EetSendResult;
use MyInvoice\Service\Eet\Transport\EetSignatureException;
use MyInvoice\Service\Eet\Transport\EetTimeouts;
use MyInvoice\Service\Eet\Transport\SoapEetTransport;
use PHPUnit\Framework\TestCase;

/**
 * Transport bez sítě: HTTP klient je podvržený. Skutečné odpovědi playgroundu
 * (`Fixtures/eet/pg-response-*.xml`) jsou zaznamenané odpovědi na syntetické
 * tržby testovacího EIČ; podpis potvrzení je skutečný podpis správce daně.
 */
final class SoapEetTransportTest extends TestCase
{
    private const PG_CONFIRMED_UUID = 'd5937e70-df6c-419c-a3cb-71fed7dadd86';
    private const PG_CONFIRMED_AT = '2026-10-24T12:42:06+02:00';
    private const PG_VERIFICATION_UUID = 'feee0847-07b0-4701-a5c6-af3ba1f0b94c';
    private const PG_ERROR_UUID = '8f76e44f-6ee9-4daa-bc46-ae56ddaab2d7';

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/Fixtures/eet/' . $name);
    }

    private static function sale(): EetSaleData
    {
        return new EetSaleData('CZ00000019', 11, 'MYUCTO-DEV-01', 'A/1', new \DateTimeImmutable('2026-10-24T10:00:00Z'), 121000);
    }

    private static function header(string $uuid, bool $verification = false): EetMessageHeader
    {
        return new EetMessageHeader($uuid, new \DateTimeImmutable('2026-10-24T10:42:05Z'), true, $verification);
    }

    /** @param \Closure(string, string, array<string,string>):EetHttpResponse|EetHttpException $reply */
    private static function transport(\Closure|EetHttpResponse|EetHttpException $reply, ?EetResponseVerifier $verifier = null, ?string $clock = self::PG_CONFIRMED_AT): SoapEetTransport
    {
        $http = new class ($reply) implements EetHttpClientInterface {
            /** @var list<array{url:string, body:string, headers:array<string,string>}> */
            public array $requests = [];

            public function __construct(private readonly \Closure|EetHttpResponse|EetHttpException $reply) {}

            public function post(string $url, string $body, array $headers, EetTimeouts $timeouts): EetHttpResponse
            {
                $this->requests[] = ['url' => $url, 'body' => $body, 'headers' => $headers];
                if ($this->reply instanceof EetHttpException) {
                    throw $this->reply;
                }

                return $this->reply instanceof \Closure ? ($this->reply)($url, $body, $headers) : $this->reply;
            }
        };

        return new SoapEetTransport(
            http: $http,
            verifier: $verifier,
            clock: $clock !== null ? static fn (): \DateTimeImmutable => new \DateTimeImmutable($clock) : null,
        );
    }

    private static function send(SoapEetTransport $transport, EetMessageHeader $header, EetEnvironment $environment = EetEnvironment::Playground): EetSendResult
    {
        return $transport->send($environment, self::sale(), $header, EetTestPki::credential(), new EetTimeouts());
    }

    public function testRecordedPlaygroundConfirmationIsVerifiedAgainstPinnedIca(): void
    {
        $result = self::send(self::transport(new EetHttpResponse(200, self::fixture('pg-response-confirmed.xml'), ['x-global-transaction-id' => 'abc'], 71)), self::header(self::PG_CONFIRMED_UUID));

        self::assertSame(EetOutcome::Confirmed, $result->outcome, (string) $result->errorMessage);
        self::assertTrue($result->isConfirmed());
        self::assertFalse($result->isRetryable());
        self::assertTrue($result->signatureVerified);
        self::assertSame(true, $result->testFlag);
        self::assertStringEndsWith('-ff', (string) $result->pok);
        self::assertSame(self::PG_CONFIRMED_UUID, $result->responseUuid);
        self::assertSame(self::PG_CONFIRMED_AT, $result->receivedAt);
        self::assertSame('abc', $result->transactionId);
        self::assertSame(71, $result->durationMs);
        self::assertStringContainsString('<v4:Trzba', $result->requestXml);
    }

    public function testRequestUsesSoapActionFromWsdlAndUtf8(): void
    {
        $seen = [];
        $transport = self::transport(static function (string $url, string $body, array $headers) use (&$seen): EetHttpResponse {
            $seen = ['url' => $url, 'headers' => $headers];

            return new EetHttpResponse(200, self::fixture('pg-response-confirmed.xml'));
        });
        self::send($transport, self::header(self::PG_CONFIRMED_UUID));

        self::assertSame('https://pg.trzbyeet.gov.cz/eet/services/EETServiceSOAP/v4', $seen['url']);
        self::assertSame('"http://fs.gov.cz/eet/OdeslaniTrzby"', $seen['headers']['SOAPAction']);
        self::assertSame('text/xml; charset=utf-8', $seen['headers']['Content-Type']);
    }

    public function testConfirmationForAnotherAttemptIsNotConfirmed(): void
    {
        $result = self::send(self::transport(new EetHttpResponse(200, self::fixture('pg-response-confirmed.xml'))), self::header(EetMessageHeader::uuidV4()));

        self::assertSame(EetOutcome::InvalidResponse, $result->outcome);
        self::assertSame('uuid_mismatch', $result->signatureFailure);
        self::assertTrue($result->isRetryable());
    }

    public function testTamperedConfirmationIsNeverConfirmed(): void
    {
        $tampered = preg_replace('/pok="[0-9a-f]/', 'pok="0', self::fixture('pg-response-confirmed.xml'), 1);
        $result = self::send(self::transport(new EetHttpResponse(200, (string) $tampered)), self::header(self::PG_CONFIRMED_UUID));

        self::assertSame(EetOutcome::InvalidResponse, $result->outcome);
        self::assertSame('digest_mismatch', $result->signatureFailure);
        self::assertFalse($result->signatureVerified);
    }

    public function testPlaygroundConfirmationIsRejectedForProduction(): void
    {
        $result = self::send(
            self::transport(new EetHttpResponse(200, self::fixture('pg-response-confirmed.xml'))),
            self::header(self::PG_CONFIRMED_UUID),
            EetEnvironment::Production,
        );

        self::assertSame(EetOutcome::InvalidResponse, $result->outcome);
        self::assertSame('certificate_environment_mismatch', $result->signatureFailure);
    }

    public function testExpiredSignerCertificateIsRejected(): void
    {
        $result = self::send(
            self::transport(new EetHttpResponse(200, self::fixture('pg-response-confirmed.xml')), clock: '2030-01-01T00:00:00Z'),
            self::header(self::PG_CONFIRMED_UUID),
        );

        self::assertSame('certificate_expired', $result->signatureFailure);
    }

    public function testValidSignatureFromUntrustedIssuerIsRejected(): void
    {
        $uuid = EetMessageHeader::uuidV4();
        $xml = EetTestPki::signedResponse(self::confirmation($uuid));
        $result = self::send(self::transport(new EetHttpResponse(200, $xml), clock: null), self::header($uuid));

        self::assertSame(EetOutcome::InvalidResponse, $result->outcome);
        self::assertSame('certificate_untrusted', $result->signatureFailure);
    }

    public function testSyntheticTrustAcceptsTaxAuthorityAndWarningsAreKept(): void
    {
        $uuid = EetMessageHeader::uuidV4();
        $xml = EetTestPki::signedResponse(self::confirmation($uuid, '<eet:Varovani kod_varov="6">id_jednotky neodpovida formatem</eet:Varovani>'));
        $verifier = new EetResponseVerifier([EetTestPki::caFile()]);
        $result = self::send(self::transport(new EetHttpResponse(200, $xml), $verifier, null), self::header($uuid));

        self::assertSame(EetOutcome::ConfirmedWithWarning, $result->outcome, (string) $result->errorMessage);
        self::assertSame([['code' => 6, 'message' => 'id_jednotky neodpovida formatem']], $result->warnings);
    }

    public function testCertificateOfAnotherOrganizationIsRejected(): void
    {
        $uuid = EetMessageHeader::uuidV4();
        $xml = EetTestPki::signedResponse(self::confirmation($uuid), EetTestPki::taxAuthority('NEOSTRE - playground prostredi EET', 'NTRCZ-12345678'));
        $result = self::send(self::transport(new EetHttpResponse(200, $xml), new EetResponseVerifier([EetTestPki::caFile()]), null), self::header($uuid));

        self::assertSame('certificate_not_tax_authority', $result->signatureFailure);
    }

    public function testUnsignedConfirmationIsInvalid(): void
    {
        $uuid = EetMessageHeader::uuidV4();
        $xml = '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:eet="http://fs.gov.cz/eet/schema/v4"><soapenv:Header/><soapenv:Body>'
            . self::confirmation($uuid) . '</soapenv:Body></soapenv:Envelope>';
        $result = self::send(self::transport(new EetHttpResponse(200, $xml)), self::header($uuid));

        self::assertSame(EetOutcome::InvalidResponse, $result->outcome);
        self::assertSame('signature_missing', $result->signatureFailure);
    }

    public function testVerificationModeCodeZero(): void
    {
        $response = new EetHttpResponse(200, self::fixture('pg-response-verification.xml'));
        $ok = self::send(self::transport($response), self::header(self::PG_VERIFICATION_UUID, true));
        self::assertSame(EetOutcome::VerificationOk, $ok->outcome);
        self::assertSame(0, $ok->errorCode);
        self::assertNull($ok->pok);
        self::assertFalse($ok->isConfirmed());

        $unexpected = self::send(self::transport($response), self::header(self::PG_VERIFICATION_UUID));
        self::assertSame(EetOutcome::InvalidResponse, $unexpected->outcome);
        self::assertSame('unexpected_verification_result', $unexpected->signatureFailure);
    }

    public function testVerificationResultWithoutMessageUuidIsNotVerificationOk(): void
    {
        $xml = '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:eet="http://fs.gov.cz/eet/schema/v4"><soapenv:Header/><soapenv:Body>'
            . '<eet:Odpoved><eet:Hlavicka dat_odmit="2026-10-24T12:42:06+02:00"/><eet:Chyba kod="0" test="true">Datovou zpravu evidovane trzby v overovacim modu se podarilo zpracovat</eet:Chyba></eet:Odpoved>'
            . '</soapenv:Body></soapenv:Envelope>';
        $result = self::send(self::transport(new EetHttpResponse(200, $xml)), self::header(EetMessageHeader::uuidV4(), true));

        self::assertSame(EetOutcome::InvalidResponse, $result->outcome);
        self::assertSame('uuid_mismatch', $result->signatureFailure);
        self::assertSame(0, $result->errorCode);
        self::assertFalse($result->isConfirmed());
    }

    public function testErrorResponseIsNeverConfirmed(): void
    {
        foreach (['pg-response-error-4.xml', 'pg-response-verification.xml'] as $fixture) {
            foreach ([true, false] as $verification) {
                $result = self::send(self::transport(new EetHttpResponse(200, self::fixture($fixture))), self::header(EetMessageHeader::uuidV4(), $verification));
                self::assertFalse($result->isConfirmed(), $fixture);
                self::assertNotSame(EetOutcome::VerificationOk, $result->outcome, $fixture);
            }
        }
    }

    public function testCriticalErrorIsRejectedAndNotRetryable(): void
    {
        $result = self::send(self::transport(new EetHttpResponse(200, self::fixture('pg-response-error-4.xml'))), self::header(self::PG_ERROR_UUID));

        self::assertSame(EetOutcome::Rejected, $result->outcome);
        self::assertSame(4, $result->errorCode);
        self::assertSame('Neplatny podpis SOAP zpravy', $result->errorMessage);
        self::assertFalse($result->isRetryable());
    }

    public function testTemporaryErrorIsRetryable(): void
    {
        $xml = '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:eet="http://fs.gov.cz/eet/schema/v4"><soapenv:Header/><soapenv:Body>'
            . '<eet:Odpoved><eet:Hlavicka dat_odmit="2026-10-24T12:42:06+02:00"/><eet:Chyba kod="-1">Docasna technicka chyba zpracovani</eet:Chyba></eet:Odpoved>'
            . '</soapenv:Body></soapenv:Envelope>';
        $result = self::send(self::transport(new EetHttpResponse(200, $xml)), self::header(EetMessageHeader::uuidV4()));

        self::assertSame(EetOutcome::TemporaryError, $result->outcome);
        self::assertSame(-1, $result->errorCode);
        self::assertTrue($result->isRetryable());
    }

    public function testSoapFaultIsTransportError(): void
    {
        $xml = '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body><soapenv:Fault>'
            . '<faultcode>soapenv:Server</faultcode><faultstring>Internal Error</faultstring></soapenv:Fault></soapenv:Body></soapenv:Envelope>';
        $result = self::send(self::transport(new EetHttpResponse(500, $xml)), self::header(EetMessageHeader::uuidV4()));

        self::assertSame(EetOutcome::TransportError, $result->outcome);
        self::assertStringContainsString('Internal Error', (string) $result->errorMessage);
        self::assertSame(500, $result->httpStatus);
        self::assertTrue($result->isRetryable());
    }

    public function testTimeoutKeepsRequestAndIsRetryable(): void
    {
        $result = self::send(self::transport(new EetHttpException('Vypršel časový limit', true, 3000)), self::header(EetMessageHeader::uuidV4()));

        self::assertSame(EetOutcome::Timeout, $result->outcome);
        self::assertSame(3000, $result->durationMs);
        self::assertStringContainsString('<v4:Trzba', $result->requestXml);
        self::assertNull($result->responseXml);
        self::assertTrue($result->isRetryable());
    }

    public function testConnectionFailureAndGatewayPageAreTransportErrors(): void
    {
        $failed = self::send(self::transport(new EetHttpException('Spojení selhalo')), self::header(EetMessageHeader::uuidV4()));
        self::assertSame(EetOutcome::TransportError, $failed->outcome);

        $gateway = self::send(self::transport(new EetHttpResponse(503, '<html>Service Unavailable</html>')), self::header(EetMessageHeader::uuidV4()));
        self::assertSame(EetOutcome::TransportError, $gateway->outcome);
        self::assertSame(503, $gateway->httpStatus);
    }

    public function testDoctypeInResponseIsRefused(): void
    {
        $xml = '<?xml version="1.0"?><!DOCTYPE soapenv:Envelope [<!ENTITY x SYSTEM "file:///c:/windows/win.ini">]>'
            . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body>&x;</soapenv:Body></soapenv:Envelope>';
        $result = self::send(self::transport(new EetHttpResponse(200, $xml)), self::header(EetMessageHeader::uuidV4()));

        self::assertSame(EetOutcome::InvalidResponse, $result->outcome);
        self::assertSame('xml_unsafe', $result->signatureFailure);
    }

    public function testParserReadsOdpovedOnlyFromSingleBody(): void
    {
        $this->expectException(EetSignatureException::class);
        (new EetResponseParser())->parse(
            '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Header><soapenv:Body/></soapenv:Header><soapenv:Body/></soapenv:Envelope>',
        );
    }

    private static function confirmation(string $uuid, string $extra = ''): string
    {
        return '<eet:Odpoved><eet:Hlavicka uuid_zpravy="' . $uuid . '" dat_prij="2026-10-24T12:42:06+02:00"/>'
            . '<eet:Potvrzeni pok="11111111-2222-4333-8444-555555555555-ff" test="true"/>' . $extra . '</eet:Odpoved>';
    }
}
