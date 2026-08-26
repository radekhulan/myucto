<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use MyInvoice\Repository\Payroll\PayrollSigningProfileRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionTransportAttemptRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzDispatchOutcome;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzDispatchService;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzGovTalkEnvelope;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzReceiptVerifier;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzSoftwareIdentification;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzSubmissionStatus;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzTransportException;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzVrepClient;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Service\Signing\PersonalCertificateVaultService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

// Oba repozitáře ledgeru jsou `final`. Mockují se týmž mechanismem jako zbytek
// sady (viz tests/bootstrap.php), jen se cesty přidávají tady — allowPaths()
// seznam doplňuje, takže kvůli jedné testovací třídě nemusí růst globální
// seznam v bootstrapu.
\DG\BypassFinals::allowPaths([
    '*/api/src/Repository/Payroll/PayrollSubmissionTransportAttemptRepository.php',
    '*/api/src/Repository/Payroll/PayrollSigningProfileRepository.php',
    '*/api/src/Service/Payroll/Submission/PayrollSubmissionService.php',
]);

/**
 * Odesílací cesta JMHZ na VREP. Testuje se proti falešnému VREP (Guzzle
 * MockHandler) a efemérnímu certifikátu vyrobenému v testu — skutečný
 * podpisový klíč se do sady nikdy nedostane.
 *
 * Vzorky odpovědí jsou doslovné: potvrzení převzetí je zkrácená odpověď
 * testovacího VREP na reálně odeslané podání, protokol je vzorek z
 * `JmhzTransportSample`.
 */
final class JmhzDispatchServiceTest extends TestCase
{
    private const SUPPLIER = 11;
    private const SUBMISSION = 42;
    private const ATTEMPT = 7;
    private const CORRELATION = 'CCCC9999DDDD0000EEEE1111FFFF2222';

    /** @var list<array<string,mixed>> */
    private array $history = [];

    /** @var list<string> */
    private array $log = [];

    /** Argumenty, se kterými ledger dostal zápis neúspěchu. */
    private ?array $failure = null;

    protected function setUp(): void
    {
        if (!function_exists('openssl_cms_sign') || !function_exists('openssl_cms_encrypt')) {
            self::markTestSkipped('Server nepodporuje CMS.');
        }
        $this->history = [];
        $this->log = [];
        $this->failure = null;
    }

    /**
     * Ledger se musí založit DŘÍV, než obálka opustí proces: pád mezi odesláním
     * a zápisem by u ČSSZ nechal podání, o kterém aplikace neví, a druhý pokus
     * by narazil na duplicitu bez vysvětlení. Potvrzení převzetí přitom není
     * přijetí — `isSettled()` proto zůstává false.
     */
    public function testSendOpensTheLedgerBeforeTheEnvelopeLeavesAndRecordsTheCorrelation(): void
    {
        $attempts = $this->attempts();
        $attempts->method('nextAttemptNo')->willReturn(1);
        $attempts->expects(self::once())->method('open')->with(
            self::SUPPLIER,
            'test',
            self::SUBMISSION,
            JmhzDispatchService::CHANNEL,
            1,
            'jmhz-2026-04-11',
            hash('sha256', JmhzTransportSample::payload()),
            3,
        )->willReturnCallback(
            function (): array {
                $this->log[] = 'open';

                return self::attemptRow();
            },
        );
        $attempts->expects(self::once())->method('markSent')
            ->with(self::ATTEMPT, self::CORRELATION, 200, 0)
            ->willReturnCallback(function (): array {
                $this->log[] = 'sent';

                return self::attemptRow([
                    'status' => 'awaiting_protocol',
                    'correlation_reference' => self::CORRELATION,
                    'row_version' => 1,
                ]);
            });
        $attempts->expects(self::never())->method('markFailed');

        $outcome = $this->send($this->service($attempts, [
            new Response(200, ['Content-Type' => 'text/xml'], self::acknowledgement()),
        ]));

        self::assertSame(['open', 'http', 'sent'], $this->log);
        self::assertSame(
            'https://t-epodani.cssz.cz/VREP/submission',
            (string) $this->history[0]['request']->getUri(),
        );
        self::assertStringContainsString(
            '<Class>CSSZ_JMHZ</Class>',
            (string) $this->history[0]['request']->getBody(),
        );
        self::assertNotNull($outcome->acknowledgement);
        self::assertSame(self::CORRELATION, $outcome->acknowledgement->correlationId);
        self::assertSame('awaiting_protocol', $outcome->attempt['status']);
        self::assertFalse($outcome->isSettled());
    }

    /**
     * Druhé kliknutí na „Odeslat" nesmí založit druhé podání za totéž období —
     * ČSSZ ho odmítne jako duplicitu (chyba 20022). Když ledger vrátí už
     * otevřený pokus, na VREP se nesahá vůbec.
     */
    public function testSendWithAKeyThatAlreadyWentThroughDoesNotSendAgain(): void
    {
        $attempts = $this->attempts();
        $attempts->method('nextAttemptNo')->willReturn(1);
        $attempts->expects(self::once())->method('open')->willReturn(self::attemptRow([
            'status' => 'awaiting_protocol',
            'correlation_reference' => self::CORRELATION,
            'row_version' => 1,
        ]));
        $attempts->expects(self::never())->method('markSent');
        $attempts->expects(self::never())->method('markFailed');

        $outcome = $this->send($this->service($attempts, []));

        self::assertSame([], $this->history);
        self::assertNotContains('http', $this->log);
        self::assertSame(self::CORRELATION, $outcome->attempt['correlation_reference']);
        self::assertNull($outcome->acknowledgement);
    }

    /**
     * Odpověď, která není potvrzením převzetí, znamená pokus bez correlation
     * reference — ten se už nikdy nedohledá ani neuzavře. Musí tedy skončit
     * v ledgeru jako neúspěch s kódem, jinak nelze rozhodnout, jestli se smí
     * opakovat.
     */
    public function testSendRecordsAFailureWhenVrepAnswersWithSomethingElse(): void
    {
        $attempts = $this->openedAttempts();
        $attempts->expects(self::never())->method('markSent');
        $this->captureFailure($attempts);

        $service = $this->service($attempts, [
            new Response(200, ['Content-Type' => 'text/xml'], JmhzTransportSample::partialProtocol(
                result: 'ERROR',
                qualifier: 'error',
            )),
        ]);

        self::assertSame(
            'jmhz_dispatch_rejected',
            $this->failedSend($service, JmhzTransportException::class)->errorCode,
        );
        $this->assertFailureRecorded('jmhz_dispatch_rejected', 200);
    }

    /**
     * Selhání přenosu se do ledgeru zapisuje i s HTTP statusem protistrany a
     * původní výjimka jde dál — přebalit ji na doménovou chybu by zamlčelo,
     * že podání se vůbec neodeslalo a lhůta pořád běží.
     */
    public function testSendRecordsAFailureAndRethrowsWhenTheTransportBreaks(): void
    {
        $attempts = $this->openedAttempts();
        $attempts->expects(self::never())->method('markSent');
        $this->captureFailure($attempts);

        $service = $this->service($attempts, [
            new Response(500, ['Content-Type' => 'text/html'], 'chyba brány'),
        ]);

        $exception = $this->failedSend($service, JmhzTransportException::class);

        self::assertSame('jmhz_vrep_http_error', $exception->errorCode);
        self::assertSame(500, $exception->remoteHttpStatus);
        $this->assertFailureRecorded('jmhz_vrep_http_error', 500);
    }

    /**
     * REGRESE: HTTP 200 s tělem, které není XML (chybová stránka brány).
     *
     * Obálka je v tu chvíli U ČSSZ, jen se nedá přečíst odpověď. Dokud zápis
     * neúspěchu pokrýval jen samotné volání VREP, zůstal řádek ve stavu
     * `prepared` — obsluha viděla neodeslaný pokus, odeslala znovu a ČSSZ
     * druhé podání odmítla jako duplicitu.
     */
    public function testSendRecordsAFailureWhenTheAnswerIsNotXmlAtAll(): void
    {
        $attempts = $this->openedAttempts();
        $attempts->expects(self::never())->method('markSent');
        $this->captureFailure($attempts);

        $service = $this->service($attempts, [
            new Response(200, ['Content-Type' => 'text/html'], '<html><br>502 Bad Gateway<p>brána nedostupná</html>'),
        ]);

        self::assertSame(
            'jmhz_acknowledgement_unreadable',
            $this->failedSend($service, JmhzTransportException::class)->errorCode,
        );
        $this->assertFailureRecorded('jmhz_acknowledgement_unreadable', 200);
    }

    /**
     * REGRESE: potvrzení převzetí bez CorrelationID.
     *
     * Nejzákeřnější varianta téhož: odpověď je tvarem v pořádku, jen pod ní
     * není identifikátor, pod kterým by se podání dalo dohledat a uzavřít.
     * Bez zápisu do ledgeru je takový pokus ztracený úplně.
     */
    public function testSendRecordsAFailureWhenTheAcknowledgementCarriesNoCorrelation(): void
    {
        $attempts = $this->openedAttempts();
        $attempts->expects(self::never())->method('markSent');
        $this->captureFailure($attempts);

        $service = $this->service($attempts, [
            new Response(200, ['Content-Type' => 'text/xml'], str_replace(
                '<CorrelationID>' . self::CORRELATION . '</CorrelationID>',
                '<CorrelationID></CorrelationID>',
                self::acknowledgement(),
            )),
        ]);

        self::assertSame(
            'jmhz_acknowledgement_correlation_missing',
            $this->failedSend($service, JmhzTransportException::class)->errorCode,
        );
        $this->assertFailureRecorded('jmhz_acknowledgement_correlation_missing', 200);
    }

    /**
     * REGRESE: ztracený optimistický zámek při zápisu odeslání.
     *
     * Podání prošlo, potvrzení dorazilo — a `markSent` neuspěje, protože řádek
     * mezitím posunul jiný běh. Ani tohle není „nezahájený" pokus: ledger musí
     * dostat neúspěch s kódem `jmhz_dispatch_send_unresolved` a volajícímu se
     * musí vrátit původní chyba, ne její náhrada.
     */
    public function testSendRecordsAFailureWhenTheLedgerLosesTheRaceAfterTheEnvelopeWentOut(): void
    {
        $lock = new \DomainException('Pokus o odeslání #7 byl mezitím změněn.');
        $attempts = $this->openedAttempts();
        $attempts->expects(self::once())->method('markSent')->willThrowException($lock);
        $this->captureFailure($attempts);

        $service = $this->service($attempts, [
            new Response(200, ['Content-Type' => 'text/xml'], self::acknowledgement()),
        ]);

        self::assertSame($lock, $this->failedSend($service, \DomainException::class));
        $this->assertFailureRecorded('jmhz_dispatch_send_unresolved', 200);
    }

    /**
     * REGRESE: zápis neúspěchu nesmí přebít původní chybu.
     *
     * Když je ledger nedostupný přesně v okamžik, kdy se do něj zapisuje pád,
     * volající by se místo skutečné příčiny dozvěděl o problému s databází —
     * a hledal by ji na nesprávném místě.
     */
    public function testFailureBookkeepingNeverReplacesTheOriginalError(): void
    {
        $lock = new \DomainException('Pokus o odeslání #7 byl mezitím změněn.');
        $attempts = $this->openedAttempts();
        $attempts->expects(self::once())->method('markSent')->willThrowException($lock);
        $this->captureFailure($attempts, new \RuntimeException('Ledger je nedostupný.'));

        $service = $this->service($attempts, [
            new Response(200, ['Content-Type' => 'text/xml'], self::acknowledgement()),
        ]);

        self::assertSame($lock, $this->failedSend($service, \DomainException::class));
        $this->assertFailureRecorded('jmhz_dispatch_send_unresolved', 200);
    }

    /**
     * Dokud VREP odpovídá potvrzením, zpracování běží dál. Uzavřít pokus v ten
     * okamžik znamená vydat za výsledek něco, co ještě neexistuje.
     */
    public function testPollLeavesTheAttemptOpenWhileProcessingStillRuns(): void
    {
        $attempts = $this->attempts();
        $attempts->method('find')->willReturn(self::sentRow());
        $attempts->expects(self::never())->method('markCompleted');

        $outcome = $this->service($attempts, [
            new Response(200, ['Content-Type' => 'text/xml'], self::acknowledgement()),
        ])->poll(
            self::SUPPLIER,
            'test',
            self::ATTEMPT,
            JmhzTransportSample::VARIABLE_SYMBOL,
        );

        self::assertSame(
            'https://t-epodani.cssz.cz/VREP/poll',
            (string) $this->history[0]['request']->getUri(),
        );
        self::assertNotNull($outcome->acknowledgement);
        self::assertNull($outcome->report);
        self::assertFalse($outcome->isSettled());
        self::assertSame('awaiting_protocol', $outcome->attempt['status']);
    }

    /** Až protokol o zpracování je výsledek — teprve tehdy se pokus uzavírá. */
    public function testPollCompletesTheAttemptOnceTheProtocolArrives(): void
    {
        $attempts = $this->attempts();
        $attempts->method('find')->willReturn(self::sentRow());
        $attempts->expects(self::once())->method('markCompleted')
            ->with(self::ATTEMPT, 1)
            ->willReturn(self::sentRow(['status' => 'completed', 'row_version' => 2]));

        $outcome = $this->service($attempts, [
            new Response(200, ['Content-Type' => 'text/xml'], JmhzTransportSample::partialProtocol()),
        ])->poll(
            self::SUPPLIER,
            'test',
            self::ATTEMPT,
            JmhzTransportSample::VARIABLE_SYMBOL,
        );

        self::assertNull($outcome->acknowledgement);
        self::assertNotNull($outcome->report);
        self::assertSame(
            JmhzSubmissionStatus::ProcessedAndComplete,
            $outcome->report->status,
        );
        self::assertTrue($outcome->isSettled());
        self::assertSame('completed', $outcome->attempt['status']);
    }

    public function testPollRejectsAProtocolForAnotherSubmissionClass(): void
    {
        $attempts = $this->attempts();
        $attempts->method('find')->willReturn(self::sentRow());
        $attempts->expects(self::never())->method('markCompleted');

        try {
            $this->service($attempts, [
                new Response(
                    200,
                    ['Content-Type' => 'text/xml'],
                    JmhzTransportSample::partialProtocol(),
                ),
            ])->poll(
                self::SUPPLIER,
                'test',
                self::ATTEMPT,
                JmhzTransportSample::VARIABLE_SYMBOL,
                1,
                'CSSZ_PREZEC',
            );
            self::fail('Protokol JMHZ nesmí uzavřít pokus PREZEC.');
        } catch (JmhzTransportException $exception) {
            self::assertSame(
                'jmhz_protocol_class_mismatch',
                $exception->errorCode,
            );
        }
    }

    /**
     * Dotažený protokol musí podání posunout, jinak zůstane navždy „odesláno".
     * Kontroluje se, že se import volá S ověřovatelem podpisu — bez něj by se
     * `remote_status` do platformy nedostal a celý dotaz by byl zbytečný.
     */
    public function testSettledPollHandsTheProtocolToTheSubmissionPlatform(): void
    {
        $attempts = $this->attempts();
        $attempts->method('find')->willReturn(self::sentRow());
        $attempts->expects(self::once())->method('markCompleted')->willReturn(
            self::sentRow(['status' => 'completed', 'row_version' => 2]),
        );

        $submissions = $this->createMock(PayrollSubmissionService::class);
        $submissions->method('get')->willReturn([
            'id' => self::SUBMISSION,
            'status' => 'submitted',
            'row_version' => 7,
        ]);
        $submissions->expects(self::once())->method('importReceipt')->with(
            self::SUPPLIER,
            self::SUBMISSION,
            7,
            null,
            self::anything(),
            self::CORRELATION,
            self::CORRELATION,
            'CSSZ_JMHZ',
            'accepted',
            JmhzDispatchService::CHANNEL,
            self::anything(),
            null,
            self::isInstanceOf(JmhzReceiptVerifier::class),
        )->willReturn([
            'submission_status' => 'accepted',
            'submission_row_version' => 8,
            'trusted' => true,
        ]);

        $this->service($attempts, [
            new Response(200, ['Content-Type' => 'text/xml'], JmhzTransportSample::partialProtocol()),
        ], null, $submissions)->poll(
            self::SUPPLIER,
            'test',
            self::ATTEMPT,
            JmhzTransportSample::VARIABLE_SYMBOL,
        );
    }

    /**
     * Když ověření podpisu neprojde, protokol se NESMÍ zahodit ani prohlásit
     * za důvěryhodný — uloží se znovu bez verifieru, tedy jako příloha bez
     * důkazní síly, a k obecnému `receipt_unverified` se přidá pojmenovaný
     * důvod.
     */
    public function testUnverifiableProtocolIsStoredWithoutMovingTheSubmission(): void
    {
        $attempts = $this->attempts();
        $attempts->method('find')->willReturn(self::sentRow());
        $attempts->expects(self::once())->method('markCompleted')->willReturn(
            self::sentRow(['status' => 'completed', 'row_version' => 2]),
        );

        $verifiers = [];
        $submissions = $this->createMock(PayrollSubmissionService::class);
        $submissions->method('get')->willReturn([
            'id' => self::SUBMISSION,
            'status' => 'submitted',
            'row_version' => 7,
        ]);
        $submissions->expects(self::exactly(2))->method('importReceipt')
            ->willReturnCallback(
                function (...$arguments) use (&$verifiers): array {
                    $verifiers[] = $arguments[12] ?? null;
                    if ($arguments[12] !== null) {
                        // Vzorek protokolu žádný podpis nenese.
                        throw new JmhzTransportException(
                            'jmhz_protocol_signature_missing',
                            'Protokol ČSSZ neobsahuje podepsanou časovou značku.',
                        );
                    }

                    return [
                        'submission_status' => 'submitted',
                        'submission_row_version' => 8,
                        'trusted' => false,
                    ];
                },
            );
        $submissions->expects(self::once())->method('recordIssue')->with(
            self::SUPPLIER,
            self::SUBMISSION,
            7,
            null,
            'error',
            'remote',
            'jmhz_protocol_signature_missing',
        )->willReturn(['id' => 1, 'submission_row_version' => 9]);

        $outcome = $this->service($attempts, [
            new Response(200, ['Content-Type' => 'text/xml'], JmhzTransportSample::partialProtocol()),
        ], null, $submissions)->poll(
            self::SUPPLIER,
            'test',
            self::ATTEMPT,
            JmhzTransportSample::VARIABLE_SYMBOL,
        );

        // Výsledek dotazu se kvůli neověřenému podpisu neztrácí: ledger má
        // pokus za vyřízený a report je pořád k dispozici.
        self::assertTrue($outcome->isSettled());
        self::assertInstanceOf(JmhzReceiptVerifier::class, $verifiers[0]);
        self::assertNull($verifiers[1]);
    }

    /**
     * Transakce se uzavírá FUNKCÍ `delete`, kvalifikátor zůstává `poll`.
     * Zjištěno pokusem proti testovacímu VREP: `Qualifier=delete` vrátí
     * „Invalid qualifier" a transakce zůstane viset — což podací protokol
     * výslovně zakazuje. Proto se kontroluje odeslané tělo, ne záměr.
     */
    public function testCloseSendsDeleteAsFunctionAndKeepsPollAsQualifier(): void
    {
        $attempts = $this->attempts();
        $attempts->method('find')->willReturn(self::completedRow());
        $attempts->expects(self::never())->method('markCompleted');
        // Uzavření se musí zapsat do ledgeru: neuzavřená transakce je porušení
        // pravidel provozu a bez záznamu by se nedalo poznat, které ještě visí.
        $attempts->expects(self::once())->method('markClosed')
            ->with(self::ATTEMPT, 2)
            ->willReturn(self::completedRow([
                'closed_at' => '2026-04-11 08:31:00',
                'close_attempts' => 1,
                'row_version' => 3,
            ]));

        $this->service($attempts, [
            new Response(200, ['Content-Type' => 'text/xml'], '<GovTalkMessage/>'),
        ])->close(
            self::SUPPLIER,
            'test',
            self::ATTEMPT,
            JmhzTransportSample::VARIABLE_SYMBOL,
        );

        self::assertCount(1, $this->history);
        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML((string) $this->history[0]['request']->getBody()));
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('g', JmhzGovTalkEnvelope::NS_GOVTALK);

        self::assertSame('poll', $this->text($xpath, '//g:MessageDetails/g:Qualifier'));
        self::assertSame('delete', $this->text($xpath, '//g:MessageDetails/g:Function'));
        self::assertSame(self::CORRELATION, $this->text($xpath, '//g:MessageDetails/g:CorrelationID'));
    }

    /**
     * Bez zvoleného certifikátu se nemá co odeslat — a hlavně nesmí vzniknout
     * řádek v ledgeru: pokus, který nikdy neopustil proces, by v historii
     * vypadal jako neúspěšné podání a spotřeboval by pořadové číslo.
     */
    public function testMissingSigningProfileRefusesWithoutOpeningTheLedger(): void
    {
        $attempts = $this->attempts();
        $attempts->expects(self::never())->method('nextAttemptNo');
        $attempts->expects(self::never())->method('open');
        $attempts->expects(self::never())->method('markFailed');

        $profiles = $this->createStub(PayrollSigningProfileRepository::class);
        $profiles->method('find')->willReturn(null);

        $exception = $this->failedSend(
            $this->service($attempts, [], $profiles),
            JmhzTransportException::class,
        );

        self::assertSame('jmhz_signing_profile_missing', $exception->errorCode);
        self::assertSame([], $this->history);
    }

    /**
     * Odeslání, u kterého se čeká pád. Vrací chycenou výjimku, ať se dá dál
     * zkoumat; když nespadne nic, je to samo o sobě selhání testu.
     *
     * @template T of \Throwable
     * @param class-string<T> $expected
     * @return T
     */
    private function failedSend(JmhzDispatchService $service, string $expected): \Throwable
    {
        try {
            $this->send($service);
        } catch (\Throwable $exception) {
            self::assertInstanceOf($expected, $exception);

            return $exception;
        }

        self::fail('Odeslání mělo skončit výjimkou ' . $expected . '.');
    }

    private function send(JmhzDispatchService $service): JmhzDispatchOutcome
    {
        return $service->send(
            self::SUPPLIER,
            'test',
            self::SUBMISSION,
            JmhzTransportSample::payload(),
            JmhzTransportSample::VARIABLE_SYMBOL,
            'jmhz-2026-04-11',
            3,
        );
    }

    /**
     * Zápis neúspěchu do ledgeru si zapamatuje argumenty. `$throws` simuluje
     * ledger, který v ten okamžik sám selže.
     *
     * @param MockObject&PayrollSubmissionTransportAttemptRepository $attempts
     */
    private function captureFailure(MockObject $attempts, ?\Throwable $throws = null): void
    {
        $attempts->expects(self::once())->method('markFailed')->willReturnCallback(
            function (
                int $attemptId,
                string $errorCode,
                string $errorMessage,
                ?int $httpStatus,
                ?string $nextRetryAt,
                int $expectedVersion,
            ) use ($throws): array {
                $this->failure = [
                    $attemptId,
                    $errorCode,
                    $errorMessage,
                    $httpStatus,
                    $nextRetryAt,
                    $expectedVersion,
                ];
                if ($throws !== null) {
                    throw $throws;
                }

                return self::attemptRow(['status' => 'failed', 'row_version' => 1]);
            },
        );
    }

    private function assertFailureRecorded(string $errorCode, ?int $httpStatus): void
    {
        self::assertIsArray($this->failure, 'Ledger nedostal zápis neúspěchu.');
        self::assertSame(self::ATTEMPT, $this->failure[0]);
        self::assertSame($errorCode, $this->failure[1]);
        // Repozitář kód chyby validuje proti témuž tvaru; kód mimo něj by
        // zápis neúspěchu shodil na DomainException místo uložení.
        self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]{0,63}$/D', $this->failure[1]);
        self::assertNotSame('', trim($this->failure[2]));
        self::assertSame($httpStatus, $this->failure[3]);
        self::assertSame(0, $this->failure[5]);
    }

    /**
     * @param MockObject&PayrollSubmissionTransportAttemptRepository $attempts
     * @param list<mixed> $queue
     */
    private function service(
        PayrollSubmissionTransportAttemptRepository $attempts,
        array $queue,
        ?PayrollSigningProfileRepository $profiles = null,
        ?PayrollSubmissionService $submissions = null,
    ): JmhzDispatchService {
        $material = self::certificate();

        if ($profiles === null) {
            $profiles = $this->createStub(PayrollSigningProfileRepository::class);
            $profiles->method('find')->willReturn([
                'supplier_id' => self::SUPPLIER,
                'environment' => 'test',
                'credential_id' => 5,
                'owner_user_id' => 3,
                'cssz_registered_serial' => null,
                'row_version' => 1,
            ]);
        }

        $vault = $this->createStub(PersonalCertificateVaultService::class);
        $vault->method('resolve')->willReturn([
            'pfx' => $material['pfx'],
            'password_enc' => 'enc:jmhz',
            'certificate_valid_from' => null,
            'certificate_valid_to' => null,
            'credential' => ['id' => 5, 'serial_hex' => '01'],
        ]);

        $secrets = $this->createStub(SecretEncryption::class);
        $secrets->method('decrypt')->willReturn($material['password']);

        return new JmhzDispatchService(
            $attempts,
            $profiles,
            $vault,
            $secrets,
            new JmhzSoftwareIdentification('MyUcto', '1.0'),
            $this->vrep($queue),
            submissions: $submissions,
        );
    }

    /** @param list<mixed> $queue */
    private function vrep(array $queue): JmhzVrepClient
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));
        $stack->push(Middleware::tap(function (): void {
            $this->log[] = 'http';
        }));

        return new JmhzVrepClient(
            new Client(['handler' => $stack, 'http_errors' => false]),
            'test',
        );
    }

    /** @return MockObject&PayrollSubmissionTransportAttemptRepository */
    private function attempts(): MockObject
    {
        return $this->createMock(PayrollSubmissionTransportAttemptRepository::class);
    }

    /**
     * Ledger, který pokus otevře ve stavu `prepared` — výchozí stav pro
     * všechny scénáře, kde obálka opravdu odchází.
     *
     * @return MockObject&PayrollSubmissionTransportAttemptRepository
     */
    private function openedAttempts(): MockObject
    {
        $attempts = $this->attempts();
        $attempts->method('nextAttemptNo')->willReturn(1);
        $attempts->expects(self::once())->method('open')->willReturn(self::attemptRow());

        return $attempts;
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private static function attemptRow(array $overrides = []): array
    {
        return array_merge([
            'id' => self::ATTEMPT,
            'supplier_id' => self::SUPPLIER,
            'environment' => 'test',
            'submission_id' => self::SUBMISSION,
            'channel' => JmhzDispatchService::CHANNEL,
            'attempt_no' => 1,
            'status' => 'prepared',
            'correlation_reference' => null,
            'request_sha256' => str_repeat('a', 64),
            'response_http_status' => null,
            'error_code' => null,
            'error_message' => null,
            'next_retry_at' => null,
            'poll_count' => 0,
            'last_polled_at' => null,
            'last_poll_error' => null,
            'sent_at' => null,
            'completed_at' => null,
            'closed_at' => null,
            'close_attempts' => 0,
            'close_error' => null,
            'row_version' => 0,
            'created_by' => 3,
        ], $overrides);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private static function sentRow(array $overrides = []): array
    {
        return self::attemptRow(array_merge([
            'status' => 'awaiting_protocol',
            'correlation_reference' => self::CORRELATION,
            'response_http_status' => 200,
            'sent_at' => '2026-04-11 08:00:00',
            'row_version' => 1,
        ], $overrides));
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private static function completedRow(array $overrides = []): array
    {
        return self::sentRow(array_merge([
            'status' => 'completed',
            'completed_at' => '2026-04-11 08:30:00',
            'poll_count' => 1,
            'last_polled_at' => '2026-04-11 08:30:00',
            'row_version' => 2,
        ], $overrides));
    }

    private function text(\DOMXPath $xpath, string $expression): string
    {
        $node = $xpath->query($expression)->item(0);

        return $node === null ? '' : trim($node->textContent);
    }

    /** Zkrácená, ale doslovná odpověď testovacího VREP na odeslané podání. */
    private static function acknowledgement(): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<GovTalkMessage xmlns="http://www.govtalk.gov.uk/CM/envelope">'
            . '<EnvelopeVersion>2.0</EnvelopeVersion>'
            . '<Header><MessageDetails>'
            . '<Class>CSSZ_JMHZ</Class>'
            . '<Qualifier>acknowledgement</Qualifier>'
            . '<Function>submit</Function>'
            . '<TransactionID />'
            . '<CorrelationID>' . self::CORRELATION . '</CorrelationID>'
            . '<ResponseEndPoint PollInterval="60">https://t-epodani.cssz.cz/VREP/poll</ResponseEndPoint>'
            . '<GatewayTimestamp>2026-08-15T02:24:15.182</GatewayTimestamp>'
            . '</MessageDetails><SenderDetails /></Header>'
            . '<GovTalkDetails><Keys /></GovTalkDetails>'
            . '<Body />'
            . '</GovTalkMessage>';
    }

    /** @return array{cert:string,pfx:string,password:string} */
    private static function certificate(): array
    {
        static $material = null;
        if (is_array($material)) {
            return $material;
        }
        // OpenSSL na Windows nemusí mít openssl.cnf na očekávaném místě a bez
        // něj klíč nevyrobí. Test si proto nese vlastní minimální konfiguraci.
        $options = ['config' => self::opensslConfig()];
        $key = openssl_pkey_new($options + [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($key, self::opensslErrors());
        $csr = openssl_csr_new(
            ['commonName' => 'JMHZ Dispatch Test', 'countryName' => 'CZ'],
            $key,
            $options + ['digest_alg' => 'sha256'],
        );
        self::assertNotFalse($csr, self::opensslErrors());
        $certificate = openssl_csr_sign($csr, null, $key, 1, $options + ['digest_alg' => 'sha256']);
        self::assertNotFalse($certificate, self::opensslErrors());
        openssl_x509_export($certificate, $pem);
        $password = 'jmhz-test';
        self::assertTrue(
            openssl_pkcs12_export($certificate, $pfx, $key, $password),
            self::opensslErrors(),
        );

        return $material = [
            'cert' => (string) $pem,
            'pfx' => (string) $pfx,
            'password' => $password,
        ];
    }

    private static function opensslConfig(): string
    {
        static $path = null;
        if (is_string($path)) {
            return $path;
        }
        $file = tempnam(sys_get_temp_dir(), 'jmhz-openssl-');
        self::assertIsString($file);
        file_put_contents($file, "[req]\ndistinguished_name = dn\n[dn]\n[v3_ca]\n");
        register_shutdown_function(static function () use ($file): void {
            if (is_file($file)) {
                unlink($file);
            }
        });

        return $path = $file;
    }

    private static function opensslErrors(): string
    {
        $errors = [];
        while (($error = openssl_error_string()) !== false) {
            $errors[] = $error;
        }

        return $errors === [] ? 'OpenSSL nehlásí chybu.' : implode('; ', $errors);
    }
}
