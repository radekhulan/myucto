<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolParser;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolSignatureVerifier;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzReceiptVerifier;
use MyInvoice\Service\Payroll\Submission\PayrollObligationService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionStateMachine;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Support\JmhzSignedProtocolFactory;
use MyInvoice\Tests\Unit\Payroll\Submission\JmhzTransportSample;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Co se stane s odeslaným hlášením, když dorazí protokol ČSSZ.
 *
 * Tohle je konec cesty, který dosud chyběl: podání se nedostalo dál než na
 * `submitted`, protože protokol nešlo ověřit a bez ověřeného protokolu se
 * `remote_status` do platformy nedostane. Testuje se obojí — že podepsaný
 * protokol podání DOTÁHNE do koncového stavu, a že neověřitelný ho tam
 * NEDOSTANE ani omylem.
 */
#[Group('integration')]
final class PayrollJmhzProtocolReceiptTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const CHANNEL = 'vrep_apep';

    private Connection $db;
    private PayrollObligationService $obligations;
    private PayrollSubmissionService $submissions;
    private int $supplierId;
    private ?JmhzSignedProtocolFactory $factory = null;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $connection = $container->get(Connection::class);
        $encryption = $container->get(SecretEncryption::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(SecretEncryption::class, $encryption);
        $this->db = $connection;
        $pdo = $connection->pdo();
        $sourceStatement = $pdo->query('SELECT MIN(id) FROM supplier');
        self::assertInstanceOf(\PDOStatement::class, $sourceStatement);
        $source = (int) $sourceStatement->fetchColumn();
        self::assertGreaterThan(0, $source);
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);

        $repository = new PayrollSubmissionRepository($connection);
        $clock = new MockClock('2026-08-04 10:11:12 Europe/Prague');
        $this->obligations = new PayrollObligationService($repository, $clock);
        $this->submissions = new PayrollSubmissionService(
            $repository,
            new PayrollSubmissionStateMachine(),
            $encryption,
            $clock,
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

    public function testSignedRejectionProtocolMovesSubmissionOutOfSubmitted(): void
    {
        $submission = $this->submitted('rejected');
        $protocol = $this->protocols()->sign($this->protocol('ERROR'));

        $receipt = $this->import($submission, $protocol, 'rejected');

        self::assertTrue($receipt['trusted']);
        self::assertSame('rejected', $receipt['submission_status']);
        self::assertSame(
            'rejected',
            $this->submissions->get($this->supplierId, $submission['id'])['status'],
        );
        $outcomes = $this->submissions->jmhzProtocolFormOutcomes(
            $this->supplierId,
            'production',
            (int) $receipt['id'],
        );
        self::assertCount(1, $outcomes);
        self::assertSame(JmhzTransportSample::FORM_GUID, $outcomes[0]['form_guid']);
        self::assertSame(3, $outcomes[0]['protocol_status_code']);
        self::assertSame('Rejected', $outcomes[0]['protocol_status_name']);
        self::assertSame('rejected', $outcomes[0]['remote_status']);
        self::assertSame(1, $outcomes[0]['error_count']);
        self::assertStringStartsWith('enc:v2:', (string) $this->db->pdo()->query(
            'SELECT errors_ciphertext FROM payroll_jmhz_protocol_form_outcomes'
                . ' WHERE id = ' . (int) $outcomes[0]['id'],
        )->fetchColumn());
        self::assertSame(20118, $outcomes[0]['errors'][0]['code']);
        self::assertSame('dis', $outcomes[0]['errors'][0]['origin']);
        self::assertSame(118, $outcomes[0]['errors'][0]['control_id']);

        $replayed = $this->import($submission, $protocol, 'rejected');
        self::assertFalse($replayed['created']);
        self::assertCount(
            1,
            $this->submissions->jmhzProtocolFormOutcomes(
                $this->supplierId,
                'production',
                (int) $receipt['id'],
            ),
        );
    }

    public function testSignedAcceptanceProtocolClosesTheSubmission(): void
    {
        $submission = $this->submitted('accepted');
        $protocol = $this->protocols()->sign($this->protocol('OK'));

        $receipt = $this->import($submission, $protocol, 'accepted');

        self::assertTrue($receipt['trusted']);
        self::assertSame('accepted', $receipt['submission_status']);
    }

    /**
     * Protokol podepsaný někým jiným než ČSSZ nesmí podání pohnout, ať tvrdí
     * cokoli. Deklarovaný stav přichází od volajícího a je bezcenný.
     */
    public function testProtocolSignedByAnyoneElseLeavesTheSubmissionSubmitted(): void
    {
        $submission = $this->submitted('foreign');
        $protocol = $this->protocols()->sign($this->protocol('OK'), 'Kdokoli jiny');

        try {
            $this->import($submission, $protocol, 'accepted');
            self::fail('Cizí podpis nesmí protokol prohlásit za důvěryhodný.');
        } catch (\Throwable) {
            // Import se zahodil celý; podání zůstává tam, kde bylo.
        }

        self::assertSame(
            'submitted',
            $this->submissions->get($this->supplierId, $submission['id'])['status'],
        );
    }

    /**
     * Bez verifieru je protokol jen příloha: uloží se, ale stav podání
     * nezmění a povinnost si vyžádá ruční kontrolu.
     */
    public function testUnverifiedProtocolIsStoredButNeverAccepted(): void
    {
        $submission = $this->submitted('unverified');
        $protocol = $this->protocols()->sign($this->protocol('OK'));

        $receipt = $this->submissions->importReceipt(
            $this->supplierId,
            $submission['id'],
            $submission['row_version'],
            null,
            $protocol,
            $this->correlation('unverified'),
            $this->correlation('unverified'),
            'CSSZ_JMHZ',
            'accepted',
            self::CHANNEL,
            'jmhz-protocol-unverified',
        );

        self::assertFalse($receipt['trusted']);
        self::assertSame('submitted', $receipt['submission_status']);
        self::assertSame(
            [],
            $this->submissions->jmhzProtocolFormOutcomes(
                $this->supplierId,
                'production',
                (int) $receipt['id'],
            ),
        );
    }

    /**
     * @param array{id:int,status:string,row_version:int} $submission
     * @return array<string,mixed>
     */
    private function import(array $submission, string $protocol, string $declared): array
    {
        return $this->submissions->importReceipt(
            $this->supplierId,
            $submission['id'],
            $submission['row_version'],
            null,
            $protocol,
            (string) $submission['correlation'],
            (string) $submission['correlation'],
            'CSSZ_JMHZ',
            $declared,
            self::CHANNEL,
            'jmhz-protocol-' . $submission['id'],
            null,
            new JmhzReceiptVerifier(
                new JmhzProtocolSignatureVerifier(
                    trustAnchorPem: $this->protocols()->anchorPem(),
                ),
                new JmhzProtocolParser(),
            ),
        );
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
