<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollSubmissionConflictException;
use MyInvoice\Repository\Payroll\PayrollSubmissionManualAcceptanceRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Submission\PayrollDeadlineAssessmentService;
use MyInvoice\Service\Payroll\Submission\PayrollObligationService;
use MyInvoice\Service\Payroll\Submission\PayrollReceiptVerifierInterface;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionManualAcceptancePolicy;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionManualAcceptanceReader;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionManualAcceptanceService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionStateMachine;
use MyInvoice\Service\Payroll\Submission\PayrollVerifiedReceipt;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Ruční potvrzení přijetí podání podle aplikace ČSSZ.
 *
 * Hlídá tři věci: ruční přijetí má TYTÉŽ následky jako ověřené (povinnost,
 * termín, nahrazení předchůdce), nevydává se za protokol (žádný řádek
 * v `payroll_submission_receipts`) a pozdější ověřený protokol má přednost
 * — rozpor se zapíše jako nález, ne aby se tiše přepsal nebo zahodil.
 */
#[Group('integration')]
final class PayrollSubmissionManualAcceptanceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PNG = "\x89PNG\r\n\x1a\n" . 'synthetic-screenshot';

    private Connection $db;
    private PayrollObligationService $obligations;
    private PayrollSubmissionService $submissions;
    private PayrollSubmissionManualAcceptanceService $manual;
    private PayrollSubmissionManualAcceptanceReader $reader;
    private int $supplierId;
    private int $userId;
    private MockClock $clock;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $connection = $container->get(Connection::class);
        $encryption = $container->get(SecretEncryption::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(SecretEncryption::class, $encryption);
        $this->db = $connection;
        $pdo = $connection->pdo();
        $source = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        self::assertGreaterThan(0, $source);
        self::assertGreaterThan(0, $this->userId);
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);

        $repository = new PayrollSubmissionRepository($connection);
        $acceptances = new PayrollSubmissionManualAcceptanceRepository($connection);
        $clock = new MockClock('2026-08-04 10:11:12 Europe/Prague');
        $this->clock = $clock;
        $this->obligations = new PayrollObligationService($repository, $clock);
        $this->submissions = new PayrollSubmissionService(
            $repository,
            new PayrollSubmissionStateMachine(),
            $encryption,
            $clock,
            null,
            null,
            $acceptances,
        );
        $this->manual = new PayrollSubmissionManualAcceptanceService(
            $acceptances,
            $repository,
            $this->submissions,
            new PayrollSubmissionManualAcceptancePolicy(),
            $this->reader = new PayrollSubmissionManualAcceptanceReader($acceptances),
            $clock,
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    public function testManualAcceptanceHasSameConsequencesAsVerifiedAcceptance(): void
    {
        $verified = $this->submitted('verified', 'office:verified');
        $this->importTrusted($verified, 'accepted', 'verified-accepted');

        $manual = $this->submitted('manual', 'office:manual');
        $result = $this->accept($manual, 'unchanged', 'V aplikaci ČSSZ stav Přijato, zpráva z 5. 8.', '2026-08-03');

        self::assertTrue($result['created']);
        self::assertSame('accepted', $result['submission']['status']);
        self::assertSame($this->submissionRow($verified['id'])['status'], $this->submissionRow($manual['id'])['status']);
        self::assertSame('fulfilled', $this->obligationStatus($verified['id']));
        self::assertSame('fulfilled', $this->obligationStatus($manual['id']));
        self::assertNotNull($this->submissionRow($manual['id'])['decided_at']);

        $deadlines = new PayrollDeadlineAssessmentService($this->clock);
        self::assertSame(
            $deadlines->assess('2026-08-01', '2026-08-20', 'fulfilled', 'accepted')->phase,
            $deadlines->assess('2026-08-01', '2026-08-20', $this->obligationStatus($manual['id']), $this->submissionRow($manual['id'])['status'])->phase,
        );

        // Výrok člověka se NEVYDÁVÁ za protokol úřadu.
        self::assertSame(0, $this->receiptCount($manual['id']));
        $acceptance = $result['acceptance'];
        self::assertSame('unchanged', $acceptance['variant']);
        self::assertSame('submitted', $acceptance['status_before']);
        self::assertSame($this->userId, $acceptance['recorded_by']);
        self::assertSame('2026-08-04 08:11:12', $acceptance['recorded_at'], 'Čas zápisu je v UTC.');
        self::assertSame('2026-08-03', $acceptance['authority_accepted_on']);
        self::assertNull($acceptance['contradiction']);
    }

    public function testRejectedSubmissionChangedByAuthorityKeepsAttachment(): void
    {
        $submitted = $this->submitted('rejected', 'office:rejected');
        $rejected = $this->importTrusted($submitted, 'rejected', 'rejected');
        self::assertSame('rejected', $rejected['submission_status']);

        $result = $this->accept(
            ['id' => $submitted['id'], 'row_version' => $rejected['submission_row_version']],
            'changed_by_authority',
            'Chybu ve formuláři opravila OSSZ v aplikaci ČSSZ, stav Přijato.',
            null,
            self::PNG,
        );

        self::assertSame('accepted', $result['submission']['status']);
        self::assertSame('rejected', $result['acceptance']['status_before']);
        self::assertSame('changed_by_authority', $result['acceptance']['variant']);
        $artifactId = $result['acceptance']['attachment_artifact_id'];
        self::assertIsInt($artifactId);
        self::assertSame(self::PNG, $this->submissions->artifactBytes($this->supplierId, $artifactId));
        $kind = $this->db->pdo()->prepare(
            'SELECT artifact_kind, mime_type FROM payroll_submission_artifacts WHERE supplier_id = ? AND id = ?',
        );
        $kind->execute([$this->supplierId, $artifactId]);
        self::assertSame(
            ['artifact_kind' => 'manual_attachment', 'mime_type' => 'image/png'],
            $kind->fetch(\PDO::FETCH_ASSOC),
        );
        self::assertSame('fulfilled', $this->obligationStatus($submitted['id']));
    }

    public function testManualAcceptanceIsRefusedOutsideAllowedStates(): void
    {
        $ready = $this->ready('ready', 'office:ready');
        $this->assertDomainRefusal($ready, 'neodeslala');

        $accepted = $this->submitted('accepted', 'office:accepted');
        $done = $this->importTrusted($accepted, 'accepted', 'already-accepted');
        $this->assertDomainRefusal(
            ['id' => $accepted['id'], 'row_version' => $done['submission_row_version']],
            'už je přijaté',
        );

        $other = $this->submitted('other-agenda', 'office:other', 'NEMPRI');
        $this->assertDomainRefusal($other, 'JMHZ');
    }

    public function testInputValidationAndOptimisticLock(): void
    {
        $submitted = $this->submitted('validation', 'office:validation');
        foreach ([
            ['unchanged', 'krátké', null, null],
            ['guessed', 'Dostatečně dlouhá poznámka.', null, null],
            ['unchanged', 'Dostatečně dlouhá poznámka.', '2026-08-05', null],
            ['unchanged', 'Dostatečně dlouhá poznámka.', '05.08.2026', null],
            ['unchanged', 'Dostatečně dlouhá poznámka.', null, '<html>not an image</html>'],
        ] as [$variant, $note, $acceptedOn, $attachment]) {
            try {
                $this->accept($submitted, $variant, $note, $acceptedOn, $attachment);
                self::fail('Neplatný vstup musí být odmítnut: ' . $variant . ' / ' . $note);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(PayrollSubmissionConflictException::class);
        $this->accept(
            ['id' => $submitted['id'], 'row_version' => $submitted['row_version'] + 7],
            'unchanged',
            'Dostatečně dlouhá poznámka.',
        );
    }

    public function testIdempotentReplayReturnsSameRecordAndKeyIsFrozen(): void
    {
        $submitted = $this->submitted('idem', 'office:idem');
        $first = $this->accept($submitted, 'unchanged', 'Přijato podle aplikace ČSSZ.', null, null, 'manual-idem-key-1');
        $replay = $this->accept($submitted, 'unchanged', 'Přijato podle aplikace ČSSZ.', null, null, 'manual-idem-key-1');

        self::assertTrue($first['created']);
        self::assertFalse($replay['created']);
        self::assertSame($first['acceptance']['id'], $replay['acceptance']['id']);
        self::assertCount(1, $this->manual->overview($this->supplierId, 'test', $submitted['id'])['history']);

        $this->expectException(\DomainException::class);
        $this->accept($submitted, 'unchanged', 'Jiná poznámka ke stejnému klíči.', null, null, 'manual-idem-key-1');
    }

    /**
     * Ověřený protokol, který přijde po ručním přijetí a říká „zamítnuto",
     * vyhraje: podání se vrátí na zamítnuté, povinnost ke kontrole a u podání
     * zůstane nález, které ruční potvrzení který protokol vyvrátil. Nové ruční
     * potvrzení nález vědomě uzavře.
     */
    public function testLaterVerifiedRejectionWinsAndIsRecordedAsContradiction(): void
    {
        $submitted = $this->submitted('contradiction', 'office:contradiction');
        $accepted = $this->accept($submitted, 'unchanged', 'V aplikaci ČSSZ to vypadalo jako přijaté.');

        $receipt = $this->importTrusted(
            ['id' => $submitted['id'], 'row_version' => $accepted['submission']['row_version']],
            'rejected',
            'late-rejected',
        );

        self::assertSame('rejected', $receipt['submission_status']);
        self::assertSame('manual_review', $this->obligationStatus($submitted['id']));
        self::assertSame(1, $this->receiptCount($submitted['id']));
        $issues = $this->contradictionIssues($submitted['id']);
        self::assertCount(1, $issues);
        self::assertSame('error', $issues[0]['severity']);
        self::assertSame(0, (int) $issues[0]['is_resolved']);
        self::assertSame($accepted['acceptance']['id'] . ':' . $receipt['id'], $issues[0]['entity_reference']);

        $overview = $this->manual->overview($this->supplierId, 'test', $submitted['id']);
        self::assertIsArray($overview);
        self::assertSame('rejected', $overview['history'][0]['contradiction']['remote_status']);
        self::assertTrue($overview['can_accept']);

        $again = $this->accept(
            ['id' => $submitted['id'], 'row_version' => $receipt['submission_row_version']],
            'changed_by_authority',
            'OSSZ zamítnutí po telefonu opravila, v aplikaci ČSSZ je Přijato.',
            null,
            null,
            'manual-after-contradiction',
        );
        self::assertSame('accepted', $again['submission']['status']);
        self::assertSame('fulfilled', $this->obligationStatus($submitted['id']));
        self::assertSame(1, (int) $this->contradictionIssues($submitted['id'])[0]['is_resolved']);
        $summary = $this->reader->summaries($this->supplierId, 'test', [$submitted['id']])[$submitted['id']];
        self::assertSame($again['acceptance']['id'], $summary['id']);
        self::assertNull($summary['contradiction']);
    }

    public function testLaterVerifiedAcceptanceConfirmsWithoutFinding(): void
    {
        $submitted = $this->submitted('confirm', 'office:confirm');
        $accepted = $this->accept($submitted, 'unchanged', 'V aplikaci ČSSZ stav Přijato.');

        $receipt = $this->importTrusted(
            ['id' => $submitted['id'], 'row_version' => $accepted['submission']['row_version']],
            'accepted',
            'late-accepted',
        );

        self::assertSame('accepted', $receipt['submission_status']);
        self::assertSame('fulfilled', $this->obligationStatus($submitted['id']));
        self::assertSame([], $this->contradictionIssues($submitted['id']));
    }

    /**
     * Ruční přijetí opravy nahradí předchůdce stejně jako ověřené. Pozdější
     * zamítnutí pak stav vrátit nesmí (předchůdce už je nahrazený), ale nález
     * a povinnost ke kontrole vzniknout musí.
     */
    public function testManualAcceptanceOfCorrectionSupersedesPredecessorAndContradictionStaysVisible(): void
    {
        $original = $this->submitted('root', 'office:correction');
        $this->importTrusted($original, 'accepted', 'root-accepted');
        $correctionObligation = $this->obligations->register(
            $this->supplierId,
            'JMHZ',
            'office',
            'office:correction',
            '2026-07-01',
            '2026-07-31',
            'correction',
            'manual_upload',
            'correction_requested',
            'correction:synthetic:manual',
            str_repeat('e', 64),
            '2026-08-01',
            '2026-08-28',
            'calendar_days',
            'jmhz-correction-test',
            str_repeat('f', 64),
            'obligation-manual-correction',
            environment: 'test',
        );
        $prepared = $this->submissions->prepare(
            $this->supplierId,
            $correctionObligation['id'],
            'correction',
            'manual_upload',
            str_repeat('a', 64),
            'correction-manual',
            null,
            $original['id'],
            environment: 'test',
        );
        $correction = $this->toSubmitted($prepared, 'correction-manual');

        $accepted = $this->accept($correction, 'unchanged', 'Opravné hlášení v aplikaci ČSSZ přijato.');
        self::assertSame('superseded', $this->submissionRow($original['id'])['status']);

        $receipt = $this->importTrusted(
            ['id' => $correction['id'], 'row_version' => $accepted['submission']['row_version']],
            'rejected',
            'correction-late-rejected',
        );
        self::assertSame('accepted', $receipt['submission_status']);
        self::assertSame('manual_review', $this->obligationStatus($correction['id']));
        self::assertCount(1, $this->contradictionIssues($correction['id']));
    }

    public function testManualAcceptanceRecordsAreImmutable(): void
    {
        $submitted = $this->submitted('immutable', 'office:immutable');
        $result = $this->accept($submitted, 'unchanged', 'V aplikaci ČSSZ stav Přijato.');

        $this->expectException(\PDOException::class);
        $this->db->pdo()->prepare(
            'UPDATE payroll_submission_manual_acceptances SET note = ? WHERE supplier_id = ? AND id = ?',
        )->execute(['Přepsaná poznámka po faktu.', $this->supplierId, $result['acceptance']['id']]);
    }

    /** @param array{id:int,row_version:int} $submission @return array<string,mixed> */
    private function accept(
        array $submission,
        string $variant,
        string $note,
        ?string $acceptedOn = null,
        ?string $attachment = null,
        ?string $key = null,
    ): array {
        return $this->manual->accept(
            $this->supplierId,
            'test',
            $submission['id'],
            $submission['row_version'],
            $variant,
            $note,
            $acceptedOn,
            $attachment,
            $key ?? 'manual-acceptance-' . $submission['id'] . '-' . bin2hex(random_bytes(4)),
            $this->userId,
        );
    }

    /** @param array{id:int,row_version:int} $submission */
    private function assertDomainRefusal(array $submission, string $message): void
    {
        try {
            $this->accept($submission, 'unchanged', 'Dostatečně dlouhá poznámka.');
            self::fail('Ruční přijetí mělo být odmítnuto: ' . $message);
        } catch (\DomainException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }

    /** @param array{id:int,row_version:int} $submission @return array<string,mixed> */
    private function importTrusted(array $submission, string $status, string $key): array
    {
        return $this->submissions->importReceipt(
            $this->supplierId,
            $submission['id'],
            $submission['row_version'],
            null,
            '<receipt status="' . $status . '" key="' . $key . '"/>',
            'receipt:' . $key,
            null,
            'CSSZ_JMHZ',
            $status,
            'manual_upload',
            'receipt-' . $key,
            null,
            new class ($status) implements PayrollReceiptVerifierInterface {
                public function __construct(private readonly string $status) {}

                public function verify(
                    string $bytes,
                    string $channel,
                    string $environment,
                    ?string $expectedCorrelationReference,
                ): PayrollVerifiedReceipt {
                    return new PayrollVerifiedReceipt($this->status, $expectedCorrelationReference);
                }
            },
        );
    }

    /** @return array{id:int,row_version:int} */
    private function ready(string $key, string $subject, string $agenda = 'JMHZ'): array
    {
        $obligation = $this->obligations->register(
            $this->supplierId,
            $agenda,
            'office',
            $subject,
            '2026-07-01',
            '2026-07-31',
            'regular',
            'manual_upload',
            'payroll_run_approved',
            'run:synthetic:' . $key,
            str_repeat('c', 64),
            '2026-08-01',
            '2026-08-20',
            'calendar_days',
            'jmhz-deadline-test',
            str_repeat('d', 64),
            'obligation-manual-' . $key,
            environment: 'test',
        );
        $prepared = $this->submissions->prepare(
            $this->supplierId,
            $obligation['id'],
            'regular',
            'manual_upload',
            str_repeat('a', 64),
            'regular-manual-' . $key,
            environment: 'test',
        );
        $validated = $this->submissions->transition($this->supplierId, $prepared['id'], $prepared['row_version'], 'validated');

        return $this->submissions->transition($this->supplierId, $prepared['id'], $validated['row_version'], 'ready');
    }

    /** @return array{id:int,row_version:int} */
    private function submitted(string $key, string $subject, string $agenda = 'JMHZ'): array
    {
        $ready = $this->ready($key, $subject, $agenda);

        return $this->submissions->transition(
            $this->supplierId,
            $ready['id'],
            $ready['row_version'],
            'submitted',
            'synthetic-manual-' . $key,
        );
    }

    /** @param array{id:int,row_version:int} $prepared @return array{id:int,row_version:int} */
    private function toSubmitted(array $prepared, string $key): array
    {
        $validated = $this->submissions->transition($this->supplierId, $prepared['id'], $prepared['row_version'], 'validated');
        $ready = $this->submissions->transition($this->supplierId, $prepared['id'], $validated['row_version'], 'ready');

        return $this->submissions->transition(
            $this->supplierId,
            $prepared['id'],
            $ready['row_version'],
            'submitted',
            'synthetic-manual-' . $key,
        );
    }

    /** @return array{status:string,decided_at:?string} */
    private function submissionRow(int $submissionId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT status, decided_at FROM payroll_submissions WHERE supplier_id = ? AND id = ?',
        );
        $statement->execute([$this->supplierId, $submissionId]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }

    private function obligationStatus(int $submissionId): string
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT obligation.status
               FROM payroll_submissions submission
               JOIN payroll_obligations obligation
                 ON obligation.supplier_id = submission.supplier_id
                AND obligation.id = submission.obligation_id
              WHERE submission.supplier_id = ? AND submission.id = ?',
        );
        $statement->execute([$this->supplierId, $submissionId]);

        return (string) $statement->fetchColumn();
    }

    private function receiptCount(int $submissionId): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_submission_receipts WHERE supplier_id = ? AND submission_id = ?',
        );
        $statement->execute([$this->supplierId, $submissionId]);

        return (int) $statement->fetchColumn();
    }

    /** @return list<array<string,mixed>> */
    private function contradictionIssues(int $submissionId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT severity, is_resolved, entity_reference
               FROM payroll_submission_issues
              WHERE supplier_id = ? AND submission_id = ? AND issue_code = ?
              ORDER BY id',
        );
        $statement->execute([
            $this->supplierId,
            $submissionId,
            PayrollSubmissionManualAcceptanceRepository::CONTRADICTION_ISSUE_CODE,
        ]);

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }
}
