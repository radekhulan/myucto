<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollSigningProfileRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionTransportAttemptRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzAcknowledgementParser;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzDispatchService;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolParser;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolSignatureVerifier;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzSoftwareIdentification;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzTransportException;
use MyInvoice\Service\Payroll\Submission\PayrollObligationService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionStateMachine;
use MyInvoice\Service\Signing\PersonalCertificateVaultService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Support\JmhzSignedProtocolFactory;
use MyInvoice\Tests\Unit\Payroll\Submission\JmhzTransportSample;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Znovu ověření protokolu, který se při dotažení uložil jako neověřený.
 *
 * Výchozí stav je přesně ten, ve kterém zůstala podání dotažená před opravou
 * ověření podpisu: protokol leží v evidenci jako `unverified`, podání visí na
 * `submitted` a pokus je uzavřený, takže se protokol sám znovu neověří.
 *
 * Testuje se obojí: platně podepsaný protokol podání dotáhne stejně jako
 * čerstvě dotažený, a protokol, který ověřením neprojde, nezmění nic.
 */
#[Group('integration')]
final class PayrollJmhzProtocolReverifyTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const CHANNEL = 'vrep_apep';

    private Connection $db;
    private PayrollObligationService $obligations;
    private PayrollSubmissionService $submissions;
    private PayrollSubmissionTransportAttemptRepository $attempts;
    private JmhzDispatchService $dispatch;
    private int $supplierId;
    private int $sourceSupplierId;
    private ?JmhzSignedProtocolFactory $factory = null;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $connection = $container->get(Connection::class);
        $encryption = $container->get(SecretEncryption::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(SecretEncryption::class, $encryption);
        $this->db = $connection;
        $pdo = $connection->pdo();
        $sourceStatement = $pdo->query('SELECT MIN(id) FROM supplier');
        self::assertInstanceOf(\PDOStatement::class, $sourceStatement);
        $this->sourceSupplierId = (int) $sourceStatement->fetchColumn();
        self::assertGreaterThan(0, $this->sourceSupplierId);
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $this->sourceSupplierId);

        $repository = new PayrollSubmissionRepository($connection);
        $clock = new MockClock('2026-08-04 10:11:12 Europe/Prague');
        $this->obligations = new PayrollObligationService($repository, $clock);
        $this->submissions = new PayrollSubmissionService(
            $repository,
            new PayrollSubmissionStateMachine(),
            $encryption,
            $clock,
        );
        $this->attempts = new PayrollSubmissionTransportAttemptRepository($connection);
        $this->dispatch = new JmhzDispatchService(
            $this->attempts,
            new PayrollSigningProfileRepository($connection),
            $this->createStub(PersonalCertificateVaultService::class),
            $encryption,
            new JmhzSoftwareIdentification('MyUcto', '1.0'),
            null,
            new JmhzAcknowledgementParser(),
            new JmhzProtocolParser(),
            null,
            $this->submissions,
            new JmhzProtocolSignatureVerifier(
                trustAnchorPem: $this->protocols()->anchorPem(),
            ),
        );
    }

    protected function tearDown(): void
    {
        $this->factory?->cleanUp();
        $this->factory = null;
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    public function testSignedStoredProtocolIsVerifiedAndMovesTheSubmission(): void
    {
        $submission = $this->submitted('accepted');
        $receiptId = $this->storeUnverified(
            $submission,
            $this->protocols()->sign($this->protocol('OK')),
        );
        self::assertSame(
            'manual_review',
            $this->obligationStatus($submission['id']),
        );

        $result = $this->dispatch->reverifyStoredProtocol(
            $this->supplierId,
            'production',
            $submission['id'],
            $receiptId,
        );

        self::assertSame('verified', $result['outcome']);
        self::assertTrue($result['verified']);
        self::assertSame('accepted', $result['remote_status']);
        self::assertSame('accepted', $result['submission_status']);
        self::assertNull($result['code']);
        self::assertSame(
            'accepted',
            $this->submissions->get($this->supplierId, $submission['id'])['status'],
        );
        self::assertSame('fulfilled', $this->obligationStatus($submission['id']));

        $verifiedId = $result['verified_receipt_id'];
        self::assertIsInt($verifiedId);
        self::assertNotSame($receiptId, $verifiedId);
        $verified = $this->storedReceipt($submission['id'], $verifiedId);
        self::assertSame('trusted', $verified['verification_status']);
        self::assertSame('accepted', $verified['remote_status']);
        // Neověřený protokol je neměnná historie; vyřízený je tím, že vedle
        // něj stojí ověřený se stejným otiskem.
        $original = $this->storedReceipt($submission['id'], $receiptId);
        self::assertSame('unverified', $original['verification_status']);
        self::assertNull($original['remote_status']);
        self::assertSame($verifiedId, $original['trusted_receipt_id']);
        self::assertSame($original['summary_hash'], $verified['summary_hash']);

        $outcomes = $this->submissions->jmhzProtocolFormOutcomes(
            $this->supplierId,
            'production',
            $verifiedId,
        );
        self::assertCount(1, $outcomes);
        self::assertSame(JmhzTransportSample::FORM_GUID, $outcomes[0]['form_guid']);
        self::assertSame('accepted', $outcomes[0]['remote_status']);
    }

    public function testSignedRejectionIsAppliedExactlyAsFreshProtocol(): void
    {
        $submission = $this->submitted('rejected');
        $receiptId = $this->storeUnverified(
            $submission,
            $this->protocols()->sign($this->protocol('ERROR')),
        );

        $result = $this->dispatch->reverifyStoredProtocol(
            $this->supplierId,
            'production',
            $submission['id'],
            $receiptId,
        );

        self::assertSame('verified', $result['outcome']);
        self::assertSame('rejected', $result['remote_status']);
        self::assertSame(
            'rejected',
            $this->submissions->get($this->supplierId, $submission['id'])['status'],
        );
        $outcomes = $this->submissions->jmhzProtocolFormOutcomes(
            $this->supplierId,
            'production',
            (int) $result['verified_receipt_id'],
        );
        self::assertCount(1, $outcomes);
        self::assertSame(20118, $outcomes[0]['errors'][0]['code']);
    }

    public function testUnsignedProtocolStaysUnverified(): void
    {
        $submission = $this->submitted('unsigned');
        $receiptId = $this->storeUnverified($submission, $this->protocol('OK'));

        $this->assertRejectedWithoutChange($submission, $receiptId);
    }

    public function testProtocolSignedByAnyoneElseStaysUnverified(): void
    {
        $submission = $this->submitted('foreign');
        $receiptId = $this->storeUnverified(
            $submission,
            $this->protocols()->sign($this->protocol('OK'), 'Kdokoli jiny'),
        );

        $this->assertRejectedWithoutChange($submission, $receiptId);
    }

    public function testTamperedProtocolStaysUnverified(): void
    {
        $submission = $this->submitted('tampered');
        $signed = $this->protocols()->sign($this->protocol('OK'));
        $tampered = str_replace('countWar="0"', 'countWar="1"', $signed);
        self::assertNotSame($signed, $tampered);
        $receiptId = $this->storeUnverified($submission, $tampered);

        $this->assertRejectedWithoutChange($submission, $receiptId);
    }

    /** Platně podepsaný protokol cizího podání nesmí tomuhle podání dát stav. */
    public function testSignedProtocolOfAnotherSubmissionStaysUnverified(): void
    {
        $submission = $this->submitted('correlation');
        $this->currentCorrelation = $this->correlation('someone-else');
        $receiptId = $this->storeUnverified(
            $submission,
            $this->protocols()->sign($this->protocol('OK')),
        );

        $this->assertRejectedWithoutChange($submission, $receiptId);
    }

    public function testForeignSupplierAndEnvironmentAreNotFound(): void
    {
        $submission = $this->submitted('tenant');
        $receiptId = $this->storeUnverified(
            $submission,
            $this->protocols()->sign($this->protocol('OK')),
        );
        $foreignSupplier = $this->createIsolatedSupplier(
            $this->db->pdo(),
            $this->sourceSupplierId,
        );

        foreach ([[$foreignSupplier, 'production'], [$this->supplierId, 'test']] as [$supplierId, $environment]) {
            try {
                $this->dispatch->reverifyStoredProtocol(
                    $supplierId,
                    $environment,
                    $submission['id'],
                    $receiptId,
                );
                self::fail('Protokol cizí firmy ani jiného prostředí se nesmí najít.');
            } catch (JmhzTransportException $exception) {
                self::assertSame('jmhz_protocol_reverify_not_found', $exception->errorCode);
                self::assertSame(404, $exception->remoteHttpStatus);
            }
        }
        self::assertSame(
            'submitted',
            $this->submissions->get($this->supplierId, $submission['id'])['status'],
        );
    }

    public function testRepeatedReverificationChangesNothing(): void
    {
        $submission = $this->submitted('repeat');
        $receiptId = $this->storeUnverified(
            $submission,
            $this->protocols()->sign($this->protocol('OK')),
        );
        $first = $this->dispatch->reverifyStoredProtocol(
            $this->supplierId,
            'production',
            $submission['id'],
            $receiptId,
        );
        $afterFirst = $this->submissions->get($this->supplierId, $submission['id']);
        $receipts = $this->receiptCount();

        $second = $this->dispatch->reverifyStoredProtocol(
            $this->supplierId,
            'production',
            $submission['id'],
            $receiptId,
        );

        self::assertSame('verified', $first['outcome']);
        self::assertSame('already_verified', $second['outcome']);
        self::assertTrue($second['verified']);
        self::assertSame($first['verified_receipt_id'], $second['verified_receipt_id']);
        self::assertSame('accepted', $second['remote_status']);
        self::assertSame($receipts, $this->receiptCount());
        self::assertSame(
            $afterFirst['row_version'],
            $this->submissions->get($this->supplierId, $submission['id'])['row_version'],
        );

        // Ověřený protokol samotný je taky „už ověřený", ne nová práce.
        $third = $this->dispatch->reverifyStoredProtocol(
            $this->supplierId,
            'production',
            $submission['id'],
            (int) $first['verified_receipt_id'],
        );
        self::assertSame('already_verified', $third['outcome']);
        self::assertSame($receipts, $this->receiptCount());
    }

    /** Přehled „Stav odeslání" nabídne tlačítko právě do chvíle ověření. */
    public function testTransportHistoryOffersReverificationUntilVerified(): void
    {
        $submission = $this->submitted('history');
        $attempt = $this->attempts->open(
            $this->supplierId,
            'production',
            $submission['id'],
            self::CHANNEL,
            1,
            'jmhz-send-history-' . $this->supplierId,
            str_repeat('e', 64),
            null,
        );
        $this->attempts->markSent(
            (int) $attempt['id'],
            $submission['correlation'],
            200,
            (int) $attempt['row_version'],
        );
        $receiptId = $this->storeUnverified(
            $submission,
            $this->protocols()->sign($this->protocol('OK')),
        );

        self::assertSame($receiptId, $this->historyItem((int) $attempt['id'])['unverified_receipt_id']);

        $this->dispatch->reverifyStoredProtocol(
            $this->supplierId,
            'production',
            $submission['id'],
            $receiptId,
        );

        self::assertNull($this->historyItem((int) $attempt['id'])['unverified_receipt_id']);
    }

    /**
     * @param array{id:int,status:string,row_version:int,correlation:string} $submission
     */
    private function assertRejectedWithoutChange(array $submission, int $receiptId): void
    {
        $before = $this->submissions->get($this->supplierId, $submission['id']);
        $receipts = $this->receiptCount();

        $result = $this->dispatch->reverifyStoredProtocol(
            $this->supplierId,
            'production',
            $submission['id'],
            $receiptId,
        );

        self::assertSame('failed', $result['outcome']);
        self::assertFalse($result['verified']);
        self::assertNull($result['verified_receipt_id']);
        self::assertNull($result['remote_status']);
        self::assertIsString($result['code']);
        self::assertStringStartsWith('jmhz_protocol_', $result['code']);
        self::assertNotSame('', (string) $result['message']);
        self::assertSame('submitted', $result['submission_status']);

        $after = $this->submissions->get($this->supplierId, $submission['id']);
        self::assertSame('submitted', $after['status']);
        self::assertSame($before['row_version'], $after['row_version']);
        self::assertSame($receipts, $this->receiptCount());
        $original = $this->storedReceipt($submission['id'], $receiptId);
        self::assertSame('unverified', $original['verification_status']);
        self::assertNull($original['trusted_receipt_id']);
        self::assertSame('manual_review', $this->obligationStatus($submission['id']));
    }

    /**
     * Stav, ve kterém protokol zůstal před opravou ověření: import bez
     * verifieru, tedy uložená příloha bez `remote_status`.
     *
     * @param array{id:int,status:string,row_version:int,correlation:string} $submission
     */
    private function storeUnverified(array $submission, string $protocol): int
    {
        $current = $this->submissions->get($this->supplierId, $submission['id']);
        $receipt = $this->submissions->importReceipt(
            $this->supplierId,
            $submission['id'],
            $current['row_version'],
            null,
            $protocol,
            $submission['correlation'],
            $submission['correlation'],
            'CSSZ_JMHZ',
            'accepted',
            self::CHANNEL,
            'jmhz-protocol:1:' . hash('sha256', $protocol),
        );
        self::assertFalse($receipt['trusted']);
        self::assertSame('submitted', $receipt['submission_status']);

        return (int) $receipt['id'];
    }

    /** @return array<string,mixed> */
    private function storedReceipt(int $submissionId, int $receiptId): array
    {
        $receipt = $this->submissions->storedReceipt(
            $this->supplierId,
            'production',
            $submissionId,
            $receiptId,
        );
        self::assertIsArray($receipt);

        return $receipt;
    }

    /** @return array<string,mixed> */
    private function historyItem(int $attemptId): array
    {
        foreach ($this->attempts->listRecentPage($this->supplierId, 'production')['items'] as $item) {
            if ((int) $item['id'] === $attemptId) {
                return $item;
            }
        }
        self::fail('Pokus v přehledu chybí.');
    }

    private function receiptCount(): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_submission_receipts WHERE supplier_id = ?',
        );
        $statement->execute([$this->supplierId]);

        return (int) $statement->fetchColumn();
    }

    private function obligationStatus(int $submissionId): string
    {
        $obligation = $this->submissions->obligationOf(
            $this->supplierId,
            'production',
            $submissionId,
        );
        self::assertIsArray($obligation);

        return (string) $obligation['status'];
    }

    private function protocol(string $result): string
    {
        return JmhzTransportSample::partialProtocol(
            $result,
            [[
                'guid' => JmhzTransportSample::FORM_GUID,
                'result' => $result,
                'errMsg' => $result === 'OK'
                    ? ''
                    : 'JMHZ25_LT: 20118 - Chybná hodnota',
                'errNum' => $result === 'OK' ? '' : '20118',
            ]],
            errMsg: $result === 'OK' ? '' : 'JMHZ25_LT: 20118 - Chybná hodnota',
            errNumber: $result === 'OK' ? '0' : '20118',
            generalResult: $result,
            correlationId: $this->currentCorrelation,
        );
    }

    private string $currentCorrelation = '';

    private function correlation(string $key): string
    {
        return 'CID' . strtoupper(substr(hash('crc32b', $key), 0, 8));
    }

    /** @return array{id:int,status:string,row_version:int,correlation:string} */
    private function submitted(string $key): array
    {
        $this->currentCorrelation = $this->correlation($key);
        $obligation = $this->obligations->register(
            $this->supplierId,
            'JMHZ',
            'office',
            'office:synthetic',
            '2026-07-01',
            '2026-07-31',
            'regular',
            self::CHANNEL,
            'payroll_run_approved',
            'run:synthetic:2026-07:' . $key,
            str_repeat('c', 64),
            '2026-08-01',
            '2026-08-20',
            'calendar_days',
            'jmhz-deadline-test',
            str_repeat('d', 64),
            'obligation-jmhz-2026-07-' . $key,
        );
        $submission = $this->submissions->prepare(
            $this->supplierId,
            $obligation['id'],
            'regular',
            self::CHANNEL,
            str_repeat('a', 64),
            'regular-2026-07-' . $key,
        );
        $validated = $this->submissions->transition(
            $this->supplierId,
            $submission['id'],
            $submission['row_version'],
            'validated',
        );
        $ready = $this->submissions->transition(
            $this->supplierId,
            $submission['id'],
            $validated['row_version'],
            'ready',
        );
        $submitted = $this->submissions->transition(
            $this->supplierId,
            $submission['id'],
            $ready['row_version'],
            'submitted',
            $this->currentCorrelation,
        );

        return $submitted + ['correlation' => $this->currentCorrelation];
    }

    private function protocols(): JmhzSignedProtocolFactory
    {
        return $this->factory ??= new JmhzSignedProtocolFactory();
    }
}
