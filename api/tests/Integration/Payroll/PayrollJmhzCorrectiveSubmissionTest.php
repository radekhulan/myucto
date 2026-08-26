<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionTransportAttemptRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzCorrectiveSubmissionService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzFrozenPayloadReader;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSchemaCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzXmlException;
use MyInvoice\Service\Payroll\Submission\PayrollObligationService;
use MyInvoice\Service\Payroll\Submission\PayrollReceiptVerifierInterface;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionStateMachine;
use MyInvoice\Service\Payroll\Submission\PayrollVerifiedReceipt;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Storno a opravné podání toutéž cestou jako řádné hlášení.
 *
 * XML pro obojí umělo jádro už dřív; chyběla doprava. Testuje se proto celý
 * řetěz PLATFORMY: zmrazení, vazba na původní podání, jeho GUID v datové větě
 * a to, že ledger pokusů takové podání přijme. SÍŤ SE NIKDY NEPOUŽIJE —
 * skutečné odeslání pokrývá JmhzDispatchServiceTest proti falešnému VREP.
 *
 * Všechny hodnoty jsou zjevně syntetické; ostrá data zaměstnavatele do sady
 * nepatří.
 */
#[Group('integration')]
final class PayrollJmhzCorrectiveSubmissionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const CHANNEL = 'vrep_apep';
    private const ENVIRONMENT = 'test';
    private const SUBMISSION_GUID = 'AAAABBBB-1111-7222-8333-CCCCDDDDEEEE';
    private const FORM_GUID = 'AAAABBBB-1111-7222-8333-CCCCDDDDEEEF';
    private const SECOND_FORM_GUID = 'AAAABBBB-1111-7222-8333-CCCCDDDDEEF0';
    private const VARIABLE_SYMBOL = '9990000001';

    private Connection $db;
    private PayrollSubmissionRepository $repository;
    private PayrollObligationService $obligations;
    private PayrollSubmissionService $submissions;
    private JmhzCorrectiveSubmissionService $corrections;
    private PayrollSubmissionTransportAttemptRepository $attempts;
    private MockClock $clock;
    private int $supplierId;
    private int $month;
    private int $year;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $connection = $container->get(Connection::class);
        $encryption = $container->get(SecretEncryption::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(SecretEncryption::class, $encryption);
        $this->db = $connection;
        $pdo = $connection->pdo();
        $source = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);

        // Rozhodné období je BĚŽÍCÍ MĚSÍC: lhůta pro storno končí 20. dne měsíce
        // následujícího, takže test nezestárne spolu s kalendářem.
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Prague'));
        $this->month = (int) $today->format('n');
        $this->year = (int) $today->format('Y');

        $this->repository = new PayrollSubmissionRepository($connection);
        $submissionDay = $today->modify('first day of next month')->modify('+1 day');
        $this->clock = new MockClock(
            $submissionDay->format('Y-m-d') . ' 10:00:00 Europe/Prague',
        );
        $this->obligations = new PayrollObligationService($this->repository, $this->clock);
        $this->submissions = new PayrollSubmissionService(
            $this->repository,
            new PayrollSubmissionStateMachine(),
            $encryption,
            $this->clock,
        );
        $this->corrections = new JmhzCorrectiveSubmissionService(
            $this->repository,
            $this->submissions,
            $this->obligations,
            new JmhzFrozenPayloadReader($this->repository, $this->submissions),
            $this->clock,
        );
        $this->attempts = new PayrollSubmissionTransportAttemptRepository($connection);
        if (!$this->attempts->isAvailable()) {
            $this->markTestSkipped('Migrace 1372 neproběhla.');
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    /**
     * Storno celého podání nese GUID rušeného hlášení a v databázi ukazuje na
     * původní podání. Bez obojího by posloupnost „podal jsem, pak stornoval"
     * nešla dohledat ani u nás, ani u ČSSZ.
     */
    public function testWholeSubmissionCancellationCarriesTheOriginalGuidAndLink(): void
    {
        $original = $this->acceptedRegularSubmission();

        $storno = $this->corrections->cancelSubmission(
            $this->supplierId,
            self::ENVIRONMENT,
            $original['id'],
        );

        self::assertTrue($storno['created']);
        self::assertSame('cancellation', $storno['submission_kind']);
        self::assertSame($original['id'], $storno['corrects_submission_id']);
        self::assertSame('ready', $storno['status']);
        self::assertSame(self::SUBMISSION_GUID, $storno['submission_guid']);

        $stored = $this->repository->findSubmission($this->supplierId, $storno['submission_id']);
        self::assertIsArray($stored);
        self::assertSame($original['id'], $stored['corrects_submission_id']);
        self::assertSame(self::CHANNEL, $stored['channel']);

        $xml = $this->submissions->artifactBytes($this->supplierId, $storno['artifact_id']);
        self::assertSame('S', $this->headerValue($xml, 'typPodani'));
        self::assertSame(self::SUBMISSION_GUID, $this->headerValue($xml, 'idPodani'));
        self::assertSame(self::VARIABLE_SYMBOL, $this->headerValue($xml, 'variabilniSymbol'));
        // Storno je nejmenší podání, jaké JMHZ zná — žádné součásti.
        self::assertStringNotContainsString('formulareOsob', $xml);
    }

    public function testCorrectableComponentsComeFromFrozenRegularPayload(): void
    {
        $original = $this->acceptedRegularSubmission();

        self::assertSame([
            [
                'form_guid' => self::FORM_GUID,
                'person_external_identifier' => '1234567890',
                'employment_external_identifier' => '987654321',
            ],
            [
                'form_guid' => self::SECOND_FORM_GUID,
                'person_external_identifier' => '1234567891',
                'employment_external_identifier' => '987654322',
            ],
        ], $this->corrections->correctableComponents(
            $this->supplierId,
            self::ENVIRONMENT,
            $original['id'],
        ));
    }

    public function testCorrectableComponentsWaitForFinalCsszResult(): void
    {
        $original = $this->regularSubmissionInStatus('submitted');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('úplném přijetí');
        $this->corrections->correctableComponents(
            $this->supplierId,
            self::ENVIRONMENT,
            $original['id'],
        );
    }

    /**
     * Opravné podání stornující jmenované součásti: typ podání O, součást typu
     * S a jen její hlavička — datová část by tvrdila, že se něco vykazuje,
     * přitom se ruší.
     */
    public function testComponentCancellationIsAnAmendmentWithHeaderOnlyForms(): void
    {
        $original = $this->acceptedRegularSubmission();

        $amendment = $this->corrections->cancelComponents(
            $this->supplierId,
            self::ENVIRONMENT,
            $original['id'],
            [self::FORM_GUID],
        );

        self::assertTrue($amendment['created']);
        self::assertSame('correction', $amendment['submission_kind']);
        self::assertSame($original['id'], $amendment['corrects_submission_id']);

        $xml = $this->submissions->artifactBytes($this->supplierId, $amendment['artifact_id']);
        self::assertSame('O', $this->headerValue($xml, 'typPodani'));
        self::assertSame(self::SUBMISSION_GUID, $this->headerValue($xml, 'idPodani'));
        self::assertStringContainsString(self::FORM_GUID, $xml);
        self::assertStringContainsString('<typFormulare>S</typFormulare>', $xml);
    }

    public function testComponentCancellationAfterTheDeadlineIsRefused(): void
    {
        $original = $this->acceptedRegularSubmission();
        $this->clock->modify('+25 days');

        $this->expectException(JmhzXmlException::class);
        $this->expectExceptionMessage('Lhůta pro storno');
        $this->corrections->cancelComponents(
            $this->supplierId,
            self::ENVIRONMENT,
            $original['id'],
            [self::FORM_GUID],
        );
    }

    /**
     * Druhé kliknutí nesmí založit druhé storno. XML se nestaví znovu — jinak
     * by pod týmž podáním vznikl jiný dokument, a duplicitu přijatého podání
     * nelze u ČSSZ vzít zpět.
     */
    public function testRepeatedCancellationReturnsTheSameFrozenSubmission(): void
    {
        $original = $this->acceptedRegularSubmission();

        $first = $this->corrections->cancelSubmission(
            $this->supplierId,
            self::ENVIRONMENT,
            $original['id'],
        );
        $this->clock->modify('+1 second');
        $second = $this->corrections->cancelSubmission(
            $this->supplierId,
            self::ENVIRONMENT,
            $original['id'],
        );

        self::assertTrue($first['created']);
        self::assertFalse($second['created']);
        self::assertSame($first['submission_id'], $second['submission_id']);
        self::assertSame($first['artifact_id'], $second['artifact_id']);
        self::assertSame($first['artifact_sha256'], $second['artifact_sha256']);
    }

    public function testComponentCancellationRejectsUnknownOrAllFrozenForms(): void
    {
        $original = $this->acceptedRegularSubmission();

        try {
            $this->corrections->cancelComponents(
                $this->supplierId,
                self::ENVIRONMENT,
                $original['id'],
                ['AAAABBBB-1111-7222-8333-CCCCDDDDFFFF'],
            );
            self::fail('Cizí GUID formuláře nesmí projít.');
        } catch (JmhzXmlException $exception) {
            self::assertSame('jmhz_cancellation_component_not_frozen', $exception->validationCode);
        }

        $this->expectException(JmhzXmlException::class);
        $this->expectExceptionMessage('storno celého podání');
        $this->corrections->cancelComponents(
            $this->supplierId,
            self::ENVIRONMENT,
            $original['id'],
            [self::FORM_GUID, self::SECOND_FORM_GUID],
        );
    }

    public function testRepeatedComponentCancellationIsStableAcrossTimeAndOrder(): void
    {
        $original = $this->acceptedRegularSubmission();
        $first = $this->corrections->cancelComponents(
            $this->supplierId,
            self::ENVIRONMENT,
            $original['id'],
            [self::FORM_GUID],
        );
        $this->clock->modify('+2 seconds');
        $second = $this->corrections->cancelComponents(
            $this->supplierId,
            self::ENVIRONMENT,
            $original['id'],
            [self::FORM_GUID],
        );

        self::assertFalse($second['created']);
        self::assertSame($first['submission_id'], $second['submission_id']);
        self::assertSame($first['artifact_sha256'], $second['artifact_sha256']);
    }

    /**
     * Ledger pokusů má trigger vyžadující shodu kanálu pokusu s kanálem podání,
     * takže tenhle test dokazuje, že storno je pro transportní vrstvu plnohodnotné
     * podání — pokus se na ně otevře a dohledá se zpátky až k rušenému hlášení.
     */
    public function testCancellationTravelsThroughTheSameTransportLedger(): void
    {
        $original = $this->acceptedRegularSubmission();
        $storno = $this->corrections->cancelSubmission(
            $this->supplierId,
            self::ENVIRONMENT,
            $original['id'],
        );

        $attempt = $this->attempts->open(
            $this->supplierId,
            self::ENVIRONMENT,
            $storno['submission_id'],
            self::CHANNEL,
            1,
            'jmhz-storno-transport-' . $storno['submission_id'],
            hash('sha256', 'storno'),
            null,
        );
        $sent = $this->attempts->markSent(
            (int) $attempt['id'],
            'VREP-STORNO-0001',
            200,
            (int) $attempt['row_version'],
        );

        self::assertSame('awaiting_protocol', $sent['status']);
        self::assertSame($storno['submission_id'], $sent['submission_id']);

        $chain = $this->repository->findSubmission($this->supplierId, $sent['submission_id']);
        self::assertIsArray($chain);
        self::assertSame($original['id'], $chain['corrects_submission_id']);
    }

    /**
     * Podání, které nikdy neopustilo aplikaci, u ČSSZ neexistuje — rušit se
     * u něj nemá co a storno by se vázalo na GUID, o kterém úřad nic neví.
     */
    public function testUnsentSubmissionCannotBeCancelled(): void
    {
        $original = $this->regularSubmission();

        $this->expectException(\DomainException::class);
        $this->corrections->cancelSubmission(
            $this->supplierId,
            self::ENVIRONMENT,
            $original['id'],
        );
    }

    #[DataProvider('ineligibleFinalStateProvider')]
    public function testPendingOrRejectedRegularSubmissionCannotBeCorrected(
        string $status,
    ): void {
        $original = $this->regularSubmissionInStatus($status);

        $this->expectException(\DomainException::class);
        $this->corrections->cancelComponents(
            $this->supplierId,
            self::ENVIRONMENT,
            $original['id'],
            [self::FORM_GUID],
        );
    }

    /** @return iterable<string,array{string}> */
    public static function ineligibleFinalStateProvider(): iterable
    {
        yield 'submitted is not final' => ['submitted'];
        yield 'processing is not final' => ['processing'];
        yield 'rejected has no valid regular root' => ['rejected'];
    }

    public function testPartiallyAcceptedRegularSubmissionWaitsForFormOutcomes(): void
    {
        $original = $this->regularSubmissionInStatus('partially_accepted');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('úplný protokol ČSSZ');
        $this->corrections->cancelComponents(
            $this->supplierId,
            self::ENVIRONMENT,
            $original['id'],
            [self::FORM_GUID],
        );
    }

    public function testRejectedRegularSubmissionIsReplacedByANewUnlinkedRegular(): void
    {
        $rejected = $this->regularSubmissionInStatus('rejected');
        $replacementGuid = 'AAAABBBB-1111-7222-8333-CCCCDDDDEEF0';
        $replacement = $this->regularSubmission($replacementGuid, '-replacement');
        $stored = $this->repository->findSubmission(
            $this->supplierId,
            $replacement['id'],
        );

        self::assertIsArray($stored);
        self::assertNotSame($rejected['id'], $replacement['id']);
        self::assertSame('regular', $stored['submission_kind']);
        self::assertNull($stored['corrects_submission_id']);
    }

    public function testAcceptedCorrectionCannotBeFollowedByCancellingLastRemainingForm(): void
    {
        $original = $this->acceptedRegularSubmission();
        $first = $this->corrections->cancelComponents(
            $this->supplierId,
            self::ENVIRONMENT,
            $original['id'],
            [self::FORM_GUID],
        );
        $this->acceptPreparedSubmission(
            $first['submission_id'],
            'VREP-CORRECTION-0001',
        );

        $storedRoot = $this->repository->findSubmission(
            $this->supplierId,
            $original['id'],
        );
        self::assertIsArray($storedRoot);
        self::assertSame('accepted', $storedRoot['status']);

        $replay = $this->corrections->cancelComponents(
            $this->supplierId,
            self::ENVIRONMENT,
            $original['id'],
            [self::FORM_GUID],
        );
        self::assertFalse($replay['created']);
        self::assertSame($first['submission_id'], $replay['submission_id']);

        $this->expectException(JmhzXmlException::class);
        $this->expectExceptionMessage('storno celého podání');
        $this->corrections->cancelComponents(
            $this->supplierId,
            self::ENVIRONMENT,
            $original['id'],
            [self::SECOND_FORM_GUID],
        );
    }

    public function testAcceptedWholeCancellationSupersedesTheEntireCorrectionChain(): void
    {
        $original = $this->acceptedRegularSubmission();
        $correction = $this->corrections->cancelComponents(
            $this->supplierId,
            self::ENVIRONMENT,
            $original['id'],
            [self::FORM_GUID],
        );
        $this->acceptPreparedSubmission(
            $correction['submission_id'],
            'VREP-CORRECTION-CHAIN-0001',
        );
        $cancellation = $this->corrections->cancelSubmission(
            $this->supplierId,
            self::ENVIRONMENT,
            $original['id'],
        );
        $this->acceptPreparedSubmission(
            $cancellation['submission_id'],
            'VREP-CANCELLATION-CHAIN-0001',
        );

        self::assertSame(
            'superseded',
            $this->repository->findSubmission(
                $this->supplierId,
                $original['id'],
            )['status'] ?? null,
        );
        self::assertSame(
            'superseded',
            $this->repository->findSubmission(
                $this->supplierId,
                $correction['submission_id'],
            )['status'] ?? null,
        );
        self::assertSame(
            'accepted',
            $this->repository->findSubmission(
                $this->supplierId,
                $cancellation['submission_id'],
            )['status'] ?? null,
        );
    }

    public function testCorrectionCanReferenceCanonicalNonV7Guids(): void
    {
        $regularGuid = 'AAAABBBB-1111-4222-8333-CCCCDDDDEEEE';
        $original = $this->regularSubmissionInStatus('accepted', $regularGuid);

        $correction = $this->corrections->cancelComponents(
            $this->supplierId,
            self::ENVIRONMENT,
            $original['id'],
            [self::FORM_GUID],
        );

        $xml = $this->submissions->artifactBytes(
            $this->supplierId,
            $correction['artifact_id'],
        );
        self::assertSame($regularGuid, $this->headerValue($xml, 'idPodani'));
    }

    /** @return array{id:int,row_version:int} */
    private function submittedRegularSubmission(): array
    {
        $submission = $this->regularSubmission();
        $submitted = $this->submissions->transition(
            $this->supplierId,
            $submission['id'],
            $submission['row_version'],
            'submitted',
            'VREP-ORIGINAL-0001',
        );

        return ['id' => $submission['id'], 'row_version' => $submitted['row_version']];
    }

    /** @return array{id:int,row_version:int} */
    private function acceptedRegularSubmission(): array
    {
        return $this->regularSubmissionInStatus('accepted');
    }

    /** @return array{id:int,row_version:int} */
    private function regularSubmissionInStatus(
        string $status,
        string $submissionGuid = self::SUBMISSION_GUID,
    ): array {
        $submission = $this->regularSubmission($submissionGuid);
        $submittedState = $this->submissions->transition(
            $this->supplierId,
            $submission['id'],
            $submission['row_version'],
            'submitted',
            'VREP-ORIGINAL-0001',
        );
        $submitted = [
            'id' => $submission['id'],
            'row_version' => $submittedState['row_version'],
        ];
        if ($status === 'submitted') {
            return $submitted;
        }

        $receipt = $this->submissions->importReceipt(
            $this->supplierId,
            $submitted['id'],
            $submitted['row_version'],
            null,
            '<receipt status="' . $status . '"/>',
            'receipt:regular:' . $status,
            'VREP-ORIGINAL-0001',
            'JMHZ_FINAL',
            $status,
            self::CHANNEL,
            'receipt-regular-' . $status,
            null,
            $this->trustedVerifier($status),
        );

        return [
            'id' => $submitted['id'],
            'row_version' => $receipt['submission_row_version'],
        ];
    }

    private function acceptPreparedSubmission(int $submissionId, string $correlation): void
    {
        $stored = $this->repository->findSubmission($this->supplierId, $submissionId);
        self::assertIsArray($stored);
        $submitted = $this->submissions->transition(
            $this->supplierId,
            $submissionId,
            $stored['row_version'],
            'submitted',
            $correlation,
        );
        $receipt = $this->submissions->importReceipt(
            $this->supplierId,
            $submissionId,
            $submitted['row_version'],
            null,
            '<receipt status="accepted"/>',
            'receipt:correction:' . $submissionId,
            $correlation,
            'JMHZ_FINAL',
            'accepted',
            self::CHANNEL,
            'receipt-correction-' . $submissionId,
            null,
            $this->trustedVerifier('accepted'),
        );
        self::assertSame('accepted', $receipt['submission_status']);
    }

    private function trustedVerifier(string $status): PayrollReceiptVerifierInterface
    {
        return new class ($status) implements PayrollReceiptVerifierInterface {
            public function __construct(private readonly string $status)
            {
            }

            public function verify(
                string $bytes,
                string $channel,
                string $environment,
                ?string $expectedCorrelationReference,
            ): PayrollVerifiedReceipt {
                return new PayrollVerifiedReceipt(
                    $this->status,
                    $expectedCorrelationReference,
                );
            }
        };
    }

    /** @return array{id:int,row_version:int} */
    private function regularSubmission(
        string $submissionGuid = self::SUBMISSION_GUID,
        string $keySuffix = '',
    ): array {
        $periodStart = sprintf('%04d-%02d-01', $this->year, $this->month);
        $periodEnd = (new \DateTimeImmutable($periodStart))
            ->modify('last day of this month')
            ->format('Y-m-d');
        $due = (new \DateTimeImmutable($periodStart))
            ->modify('+1 month')
            ->format('Y-m-20');
        $obligation = $this->obligations->register(
            $this->supplierId,
            'JMHZ25',
            'payroll_run',
            'payroll_run:5',
            $periodStart,
            $periodEnd,
            'regular',
            self::CHANNEL,
            'payroll_run_approved',
            'run:synthetic:' . $this->year . '-' . $this->month . $keySuffix,
            hash('sha256', 'source' . $keySuffix),
            // Podání smí odejít od prvního dne období: test jinak nemá jak se
            // dostat do stavu `submitted`, který je předpokladem storna.
            $periodStart,
            $due,
            'calendar_days',
            'jmhz-corrective-test',
            hash('sha256', 'trigger' . $keySuffix),
            'obligation-jmhz-corrective' . $keySuffix,
            null,
            null,
            null,
            self::ENVIRONMENT,
        );
        $snapshotHash = str_repeat('a', 64);
        $submission = $this->submissions->prepare(
            $this->supplierId,
            $obligation['id'],
            'regular',
            self::CHANNEL,
            $snapshotHash,
            'jmhz-corrective-regular' . $keySuffix,
            null,
            null,
            null,
            self::ENVIRONMENT,
        );
        $part = $this->submissions->addPart(
            $this->supplierId,
            $submission['id'],
            $submission['row_version'],
            'jmhz25:1',
            'JMHZ25',
            'payroll_run:5',
            'jmhz_preparation',
            'jmhz_preparation:1' . $keySuffix,
            $snapshotHash,
        );
        $artifact = $this->submissions->storeArtifact(
            $this->supplierId,
            $submission['id'],
            $part['submission_row_version'],
            $part['id'],
            'outbound_xml',
            'outbound',
            'application/xml',
            $this->frozenPayload($submissionGuid),
            JmhzSchemaCatalog::PACKAGE_KEY,
            'jmhz-controls-test',
            self::CHANNEL,
            'jmhz-corrective-artifact' . $keySuffix,
            null,
        );
        $validated = $this->submissions->transition(
            $this->supplierId,
            $submission['id'],
            $artifact['submission_row_version'],
            'validated',
        );
        $ready = $this->submissions->transition(
            $this->supplierId,
            $submission['id'],
            $validated['row_version'],
            'ready',
        );

        return ['id' => $submission['id'], 'row_version' => $ready['row_version']];
    }

    private function frozenPayload(string $submissionGuid = self::SUBMISSION_GUID): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<jmhz xmlns="' . JmhzSchemaCatalog::NS_PODANI . '"'
            . ' xmlns:form="' . JmhzSchemaCatalog::NS_FORM . '" verze="1.4.3.4">'
            . '<hlavicka>'
            . '<idPodani>' . $submissionGuid . '</idPodani>'
            . '<typPodani>R</typPodani>'
            . '<variabilniSymbol>' . self::VARIABLE_SYMBOL . '</variabilniSymbol>'
            . '<mesic>' . $this->month . '</mesic>'
            . '<rok>' . $this->year . '</rok>'
            . '</hlavicka>'
            . '<formulareOsob><formularOsoby><hlavicka>'
            . '<idFormulare>' . self::FORM_GUID . '</idFormulare>'
            . '<typFormulare>R</typFormulare>'
            . '</hlavicka><form:bezPriznaku><form:identifikace>'
            . '<form:ikMpsv>1234567890</form:ikMpsv>'
            . '<form:idPpv>987654321</form:idPpv>'
            . '</form:identifikace></form:bezPriznaku>'
            . '</formularOsoby><formularOsoby><hlavicka>'
            . '<idFormulare>' . self::SECOND_FORM_GUID . '</idFormulare>'
            . '<typFormulare>R</typFormulare>'
            . '</hlavicka><form:bezPriznaku><form:identifikace>'
            . '<form:ikMpsv>1234567891</form:ikMpsv>'
            . '<form:idPpv>987654322</form:idPpv>'
            . '</form:identifikace></form:bezPriznaku>'
            . '</formularOsoby></formulareOsob></jmhz>';
    }

    private function headerValue(string $xml, string $element): string
    {
        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($xml));
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('p', JmhzSchemaCatalog::NS_PODANI);
        $node = $xpath->query('/p:jmhz/p:hlavicka/p:' . $element)->item(0);
        self::assertNotNull($node);

        return trim($node->textContent);
    }
}
