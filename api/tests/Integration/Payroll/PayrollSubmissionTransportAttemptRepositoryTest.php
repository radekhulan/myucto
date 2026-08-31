<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollSubmissionTransportAttemptRepository;
use MyInvoice\Repository\Submission\SubmissionOutboxRepository;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionBridgeService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollSubmissionTransportAttemptRepositoryTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const CHANNEL = 'vrep_apep';
    private const ENVIRONMENT = 'test';

    private Connection $db;
    private PayrollSubmissionTransportAttemptRepository $repository;
    private int $supplierId;
    private int $submissionId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $db = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $this->db = $db;
        $this->repository = new PayrollSubmissionTransportAttemptRepository($db);
        if (!$this->repository->isAvailable()) {
            $this->markTestSkipped('Migrace 1372 neproběhla.');
        }
        $pdo = $db->pdo();
        $pdo->beginTransaction();
        $sourceSupplierId = (int) $pdo->query('SELECT MIN(id) FROM supplier')
            ->fetchColumn();
        $this->supplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );
        $this->submissionId = $this->createSubmission($pdo);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testAttemptIsOpenedSentAndCompletedInOneOrderedLedger(): void
    {
        self::assertSame(1, $this->nextAttemptNo());
        $opened = $this->open('transport-happy-path');

        self::assertSame('prepared', $opened['status']);
        self::assertSame(1, $opened['attempt_no']);
        self::assertSame(1, $opened['row_version']);
        self::assertNull($opened['sent_at']);
        self::assertNull($opened['correlation_reference']);

        $sent = $this->repository->markSent(
            (int) $opened['id'],
            'VREP-2026-07-0001',
            202,
            (int) $opened['row_version'],
        );
        self::assertSame('awaiting_protocol', $sent['status']);
        self::assertSame(2, $sent['row_version']);
        self::assertSame(202, $sent['response_http_status']);
        self::assertNotNull($sent['sent_at']);

        $correlated = $this->repository->findByCorrelation(
            $this->supplierId,
            self::ENVIRONMENT,
            self::CHANNEL,
            'VREP-2026-07-0001',
        );
        self::assertIsArray($correlated);
        self::assertSame($opened['id'], $correlated['id']);

        $completed = $this->repository->markCompleted(
            (int) $opened['id'],
            (int) $sent['row_version'],
        );
        self::assertSame('completed', $completed['status']);
        self::assertSame(3, $completed['row_version']);
        self::assertNotNull($completed['completed_at']);

        $ledger = $this->repository->listForSubmission(
            $this->supplierId,
            self::ENVIRONMENT,
            $this->submissionId,
        );
        self::assertCount(1, $ledger);
        self::assertSame($opened['id'], $ledger[0]['id']);
        self::assertSame(2, $this->nextAttemptNo());
    }

    public function testRepeatedOpenWithSameKeyReturnsExistingAttempt(): void
    {
        $first = $this->open('transport-idempotent');
        $second = $this->open('transport-idempotent');

        self::assertSame($first['id'], $second['id']);
        self::assertSame($first['row_version'], $second['row_version']);
        self::assertCount(
            1,
            $this->repository->listForSubmission(
                $this->supplierId,
                self::ENVIRONMENT,
                $this->submissionId,
            ),
        );

        $byKey = $this->repository->findByIdempotencyKey('transport-idempotent');
        self::assertIsArray($byKey);
        self::assertSame($first['id'], $byKey['id']);
        // Klíč se nikam neukládá v čitelné podobě a hash se ven nevrací.
        self::assertArrayNotHasKey('idempotency_key_hash', $byKey);
        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_submission_transport_attempts
              WHERE id = ? AND idempotency_key_hash = ?',
        );
        $statement->execute([
            $first['id'],
            hash('sha256', 'transport-idempotent', true),
        ]);
        self::assertSame(1, (int) $statement->fetchColumn());
    }

    public function testSameKeyWithDifferentRequestContentIsRejected(): void
    {
        $this->open('transport-content-drift');

        $this->expectException(\DomainException::class);
        $this->repository->open(
            $this->supplierId,
            self::ENVIRONMENT,
            $this->submissionId,
            self::CHANNEL,
            1,
            'transport-content-drift',
            str_repeat('b', 64),
            null,
        );
    }

    public function testStaleRowVersionCannotMutateTheAttempt(): void
    {
        $opened = $this->open('transport-stale-version');
        $sent = $this->repository->markSent(
            (int) $opened['id'],
            'VREP-2026-07-0002',
            200,
            (int) $opened['row_version'],
        );
        self::assertSame(2, $sent['row_version']);

        $this->expectException(\DomainException::class);
        $this->repository->markCompleted(
            (int) $opened['id'],
            (int) $opened['row_version'],
        );
    }

    public function testFailedAttemptNeedsMachineReadableCodeAndText(): void
    {
        $opened = $this->open('transport-failure');
        $attemptId = (int) $opened['id'];

        try {
            $this->repository->markFailed(
                $attemptId,
                'HTTP_500',
                'Server odpověděl chybou.',
                500,
                null,
                (int) $opened['row_version'],
            );
            self::fail('Kód chyby mimo ^[a-z][a-z0-9_]{0,63}$ nesmí projít.');
        } catch (\DomainException) {
            $this->addToAssertionCount(1);
        }

        try {
            $this->repository->markFailed(
                $attemptId,
                'transport_rejected',
                '   ',
                500,
                null,
                (int) $opened['row_version'],
            );
            self::fail('Neúspěch bez textu chyby nesmí projít.');
        } catch (\DomainException) {
            $this->addToAssertionCount(1);
        }

        $failed = $this->repository->markFailed(
            $attemptId,
            'transport_rejected',
            str_repeat('ě', 900),
            503,
            '2026-08-15 06:30:00',
            (int) $opened['row_version'],
        );

        self::assertSame('failed', $failed['status']);
        self::assertSame('transport_rejected', $failed['error_code']);
        self::assertSame(503, $failed['response_http_status']);
        self::assertSame('2026-08-15 06:30:00', $failed['next_retry_at']);
        self::assertSame(2, $failed['row_version']);
        self::assertSame(
            PayrollSubmissionTransportAttemptRepository::ERROR_MESSAGE_MAX_LENGTH,
            mb_strlen((string) $failed['error_message']),
        );
    }

    /**
     * Dotaz na stav se zapisuje jako DŮKAZ, ne jako stavová proměnná: počitadlo
     * roste, termín dalšího dotazu se posouvá a důvod neúspěchu je vidět. Bez
     * toho by strop pokusů nešlo vynutit a automatika by se ptala donekonečna.
     */
    public function testPollBookkeepingGrowsAndSchedulesTheNextAsk(): void
    {
        $sent = $this->repository->markSent(
            (int) $this->open('transport-poll-bookkeeping')['id'],
            'VREP-2026-07-0010',
            200,
            1,
            '2026-08-15 06:00:00',
        );
        self::assertSame('2026-08-15 06:00:00', $sent['next_retry_at']);
        self::assertSame(0, $sent['poll_count']);

        $first = $this->repository->recordPoll(
            (int) $sent['id'],
            '2026-08-15 07:00:00',
            'VREP neodpovědělo.',
            (int) $sent['row_version'],
        );
        self::assertSame(1, $first['poll_count']);
        self::assertSame('2026-08-15 07:00:00', $first['next_retry_at']);
        self::assertSame('VREP neodpovědělo.', $first['last_poll_error']);
        self::assertNotNull($first['last_polled_at']);

        // Úspěšný dotaz důvod smaže — jinak by u pokusu navždy visela chyba,
        // která už neplatí.
        $second = $this->repository->recordPoll(
            (int) $sent['id'],
            '2026-08-15 08:00:00',
            null,
            (int) $first['row_version'],
        );
        self::assertSame(2, $second['poll_count']);
        self::assertNull($second['last_poll_error']);
    }

    /**
     * Fronta na pozadí bere jen to, čemu dozrál termín. Prázdný termín znamená
     * „zeptej se hned" — pokusy z doby před migrací 1379 ho nemají a vynechat
     * je by znamenalo, že na ně automatika nikdy nesáhne.
     */
    public function testDueQueuesTakeOnlyRipeAttempts(): void
    {
        $ripe = $this->repository->markSent(
            (int) $this->open('transport-due-ripe')['id'],
            'VREP-2026-07-0011',
            200,
            1,
            '2020-01-01 00:00:00',
        );
        $later = $this->repository->markSent(
            (int) $this->open('transport-due-later')['id'],
            'VREP-2026-07-0012',
            200,
            1,
            '2099-01-01 00:00:00',
        );

        $due = array_column($this->repository->listDuePolls(50), 'id');
        self::assertContains($ripe['id'], $due);
        self::assertNotContains($later['id'], $due);

        // Dotažený pokus už se nedotazuje, ale čeká na uzavření transakce.
        $completed = $this->repository->markCompleted(
            (int) $ripe['id'],
            (int) $ripe['row_version'],
        );
        self::assertNotContains(
            $completed['id'],
            array_column($this->repository->listDuePolls(50), 'id'),
        );
        self::assertContains(
            $completed['id'],
            array_column($this->repository->listDueCloses(50, 8), 'id'),
        );
    }

    /**
     * Automatický sweep je JMHZ-specifický a nesmí registrační podání omylem
     * dotazovat třídou CSSZ_JMHZ. PREZEC/REGZEC se po ručně spuštěném odeslání
     * sledují jen přes svou explicitní transportní akci.
     */
    public function testJmhzDueQueuesExcludeRegistrationAttempts(): void
    {
        $pdo = $this->db->pdo();
        $registrationSubmissionId = $this->createSubmission($pdo, 'registration');
        $pdo->prepare(
            'UPDATE payroll_obligations obligation
               JOIN payroll_submissions submission
                 ON submission.obligation_id = obligation.id
                  SET obligation.agenda_code = "PREZEC26"
                WHERE submission.id = ?',
        )->execute([$registrationSubmissionId]);
        $opened = $this->repository->open(
            $this->supplierId,
            self::ENVIRONMENT,
            $registrationSubmissionId,
            self::CHANNEL,
            1,
            'registration-not-for-jmhz-sweep',
            str_repeat('b', 64),
            null,
        );
        $sent = $this->repository->markSent(
            (int) $opened['id'],
            'VREP-2026-08-REGISTRATION',
            200,
            (int) $opened['row_version'],
            '2020-01-01 00:00:00',
        );

        self::assertNotContains(
            $sent['id'],
            array_column($this->repository->listDuePolls(50), 'id'),
        );
        $completed = $this->repository->markCompleted(
            (int) $sent['id'],
            (int) $sent['row_version'],
        );
        self::assertNotContains(
            $completed['id'],
            array_column($this->repository->listDueCloses(50, 8), 'id'),
        );
    }

    /**
     * Transakce se uzavírá právě jednou. `closed_at` je jednorázové přiřazení,
     * takže druhý pokus není tichý no-op, ale hlasitá chyba — volající si musí
     * ověřit stav dřív, než začne posílat.
     */
    public function testTransactionIsClosedExactlyOnce(): void
    {
        $sent = $this->repository->markSent(
            (int) $this->open('transport-close-once')['id'],
            'VREP-2026-07-0013',
            200,
            1,
        );
        $completed = $this->repository->markCompleted(
            (int) $sent['id'],
            (int) $sent['row_version'],
        );

        $closed = $this->repository->markClosed(
            (int) $completed['id'],
            (int) $completed['row_version'],
        );
        self::assertNotNull($closed['closed_at']);
        self::assertSame(1, $closed['close_attempts']);
        self::assertNull($closed['next_retry_at']);
        // Uzavřený pokus z fronty na uzavření mizí, takže druhý běh na pozadí
        // nemá co uzavírat.
        self::assertNotContains(
            $closed['id'],
            array_column($this->repository->listDueCloses(50, 8), 'id'),
        );

        $this->expectException(\DomainException::class);
        $this->repository->markClosed(
            (int) $closed['id'],
            (int) $closed['row_version'],
        );
    }

    /**
     * Dotažený pokus přijme JEDINOU změnu: doklad o uzavření transakce. Kdyby
     * šlo přepsat cokoli dalšího, přestal by být důkazem o tom, jak podání
     * dopadlo.
     */
    public function testCompletedAttemptAcceptsOnlyTheClosingRecord(): void
    {
        $sent = $this->repository->markSent(
            (int) $this->open('transport-terminal-guard')['id'],
            'VREP-2026-07-0014',
            200,
            1,
        );
        $completed = $this->repository->markCompleted(
            (int) $sent['id'],
            (int) $sent['row_version'],
        );

        try {
            $this->db->pdo()->prepare(
                'UPDATE payroll_submission_transport_attempts
                    SET error_code = "rewritten", row_version = row_version + 1
                  WHERE id = ?',
            )->execute([$completed['id']]);
            self::fail('Dotažený pokus nesmí přijmout přepis výsledku.');
        } catch (PDOException $exception) {
            self::assertStringContainsString(
                'accepts only the closing record',
                $exception->getMessage(),
            );
        }
    }

    /**
     * Vzdaný pokus je terminální a nese důvod, podle kterého se dá jednat.
     * Otevřít ho zpátky nelze — jinak by se automatika mohla vrátit k podání,
     * které už převzal člověk.
     */
    public function testGivenUpAttemptIsTerminalAndCarriesTheReason(): void
    {
        $sent = $this->repository->markSent(
            (int) $this->open('transport-expired')['id'],
            'VREP-2026-07-0015',
            200,
            1,
        );

        $expired = $this->repository->markExpired(
            (int) $sent['id'],
            'jmhz_protocol_not_delivered',
            'ČSSZ protokol nevydala; zkontrolujte podání na ePortálu ČSSZ.',
            (int) $sent['row_version'],
        );
        self::assertSame('expired', $expired['status']);
        self::assertNull($expired['next_retry_at']);
        self::assertStringContainsString('ePortálu', (string) $expired['error_message']);

        $this->expectException(\DomainException::class);
        $this->repository->markCompleted(
            (int) $expired['id'],
            (int) $expired['row_version'],
        );
    }

    public function testAttemptRowsCannotBeDeleted(): void
    {
        $opened = $this->open('transport-append-only');

        try {
            $this->db->pdo()->prepare(
                'DELETE FROM payroll_submission_transport_attempts WHERE id = ?',
            )->execute([$opened['id']]);
            self::fail('Ledger pokusů o odeslání nesmí jít mazat.');
        } catch (PDOException $exception) {
            self::assertStringContainsString(
                'append-only',
                $exception->getMessage(),
            );
        }

        self::assertIsArray($this->repository->find(
            $this->supplierId,
            self::ENVIRONMENT,
            (int) $opened['id'],
        ));
    }

    public function testAttemptStaysInvisibleOutsideItsEnvironment(): void
    {
        $opened = $this->open('transport-environment-scope');

        self::assertNull($this->repository->find(
            $this->supplierId,
            'production',
            (int) $opened['id'],
        ));
        self::assertSame([], $this->repository->listForSubmission(
            $this->supplierId,
            'production',
            $this->submissionId,
        ));
    }

    /**
     * Přehled nese období rovnou s pokusem. Kdyby ho ledger nenesl, musela by
     * se na něj obrazovka doptat u každého podání zvlášť — jeden HTTP požadavek
     * navíc na řádek jen kvůli tomu, aby se dalo poznat „červenec".
     *
     * Pokus, jehož podání v evidenci není, přesto z ledgeru nesmí zmizet:
     * ledger je přírůstkový důkaz o odeslání a chybějící řádek by byl horší
     * než chybějící období.
     */
    public function testRecentLedgerCarriesThePeriodAndKeepsOrphanedAttempts(): void
    {
        $ours = $this->open('transport-period-join');
        $orphan = $this->openOrphanedAttempt();

        $recent = $this->repository->listRecent(
            $this->supplierId,
            self::ENVIRONMENT,
        );
        $byId = array_column($recent, null, 'id');

        self::assertArrayHasKey($ours['id'], $byId);
        self::assertSame('2026-07-01', $byId[$ours['id']]['period_start']);
        self::assertSame('2026-07-31', $byId[$ours['id']]['period_end']);
        self::assertSame('regular', $byId[$ours['id']]['submission_kind']);
        self::assertSame('prepared', $byId[$ours['id']]['submission_status']);
        self::assertNull($byId[$ours['id']]['corrects_submission_id']);

        self::assertArrayHasKey($orphan, $byId);
        self::assertNull($byId[$orphan]['period_start']);
        self::assertNull($byId[$orphan]['period_end']);
        self::assertNull($byId[$orphan]['submission_kind']);
        self::assertNull($byId[$orphan]['submission_status']);
        self::assertNull($byId[$orphan]['corrects_submission_id']);

        // Období smí přibýt jen do přehledu; detail pokusu i fronty na pozadí
        // zůstávají beze změny, aby se jim nezměnil tvar řádku.
        $detail = $this->repository->find(
            $this->supplierId,
            self::ENVIRONMENT,
            (int) $ours['id'],
        );
        self::assertIsArray($detail);
        self::assertArrayNotHasKey('period_start', $detail);
    }

    /**
     * Připravené opravné a stornovací podání ještě nemá pokus v ledgeru, ale
     * právě proto musí být v UI dohledatelné a odeslatelné podle vlastního ID.
     */
    public function testReadyCorrectiveSubmissionWithoutAttemptIsListed(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'UPDATE payroll_submissions
                SET status = "accepted", submitted_at = UTC_TIMESTAMP(),
                    decided_at = UTC_TIMESTAMP()
              WHERE id = ?',
        )->execute([$this->submissionId]);
        $obligationId = (int) $pdo->query(
            'SELECT obligation_id FROM payroll_submissions WHERE id = '
            . $this->submissionId,
        )->fetchColumn();
        $pdo->prepare('UPDATE payroll_obligations SET agenda_code = ? WHERE id = ?')
            ->execute([JmhzSubmissionBridgeService::AGENDA_CODE, $obligationId]);
        $pdo->prepare(
            'INSERT INTO payroll_submissions
                (supplier_id, environment, obligation_id,
                 corrects_submission_id, submission_kind, channel, status,
                 source_snapshot_hash, request_fingerprint,
                 idempotency_key_hash)
             VALUES (?, ?, ?, ?, "cancellation", ?, "ready", ?, ?, ?)',
        )->execute([
            $this->supplierId,
            self::ENVIRONMENT,
            $obligationId,
            $this->submissionId,
            self::CHANNEL,
            hash('sha256', "corrective-snapshot:{$this->supplierId}"),
            hash('sha256', "corrective-request:{$this->supplierId}"),
            hash('sha256', "corrective-key:{$this->supplierId}", true),
        ]);
        $correctiveId = (int) $pdo->lastInsertId();

        $ready = $this->repository->listReadySubmissions(
            $this->supplierId,
            self::ENVIRONMENT,
            [JmhzSubmissionBridgeService::AGENDA_CODE],
        );

        self::assertCount(1, $ready);
        self::assertSame($correctiveId, $ready[0]['submission_id']);
        self::assertSame('cancellation', $ready[0]['submission_kind']);
        self::assertSame($this->submissionId, $ready[0]['corrects_submission_id']);
        self::assertSame('2026-07-01', $ready[0]['period_start']);
        self::assertNull($ready[0]['outbox_id']);

        $pdo->prepare(
            'INSERT INTO payroll_submission_artifacts
                (supplier_id, environment, submission_id, artifact_kind,
                 direction, mime_type, content_ciphertext, byte_size,
                 artifact_sha256, channel, idempotency_key_hash)
             VALUES (?, ?, ?, "outbound_xml", "outbound", "application/xml",
                     "enc:v2:test", 1, ?, "isds", ?)',
        )->execute([
            $this->supplierId,
            self::ENVIRONMENT,
            $correctiveId,
            str_repeat('d', 64),
            hash('sha256', "corrective-artifact:{$this->supplierId}", true),
        ]);
        $artifactId = (int) $pdo->lastInsertId();
        $queued = (new SubmissionOutboxRepository($this->db))->enqueue([
            'supplier_id' => $this->supplierId,
            'environment' => self::ENVIRONMENT,
            'channel' => 'isds',
            'agenda_code' => JmhzSubmissionBridgeService::AGENDA_CODE,
            'recipient_id' => null,
            'recipient_box_id' => '9tsaf6s',
            'subject' => 'Syntetické storno JMHZ',
            'artifact_kind' => 'payroll_submission',
            'artifact_id' => $artifactId,
            'artifact_filename' => 'synthetic-jmhz.xml',
            'artifact_sha256' => str_repeat('d', 64),
            'correlation_reference' => 'TEST-JMHZ-READY-OUTBOX-' . $correctiveId,
            'created_by' => null,
        ], 'ready-corrective-outbox-' . $correctiveId);

        $queuedReady = $this->repository->listReadySubmissions(
            $this->supplierId,
            self::ENVIRONMENT,
            [JmhzSubmissionBridgeService::AGENDA_CODE],
        );
        self::assertCount(1, $queuedReady);
        self::assertSame((int) $queued['row']['id'], $queuedReady[0]['outbox_id']);
        self::assertSame('ready', $queuedReady[0]['outbox_dispatch_state']);

        $this->repository->open(
            $this->supplierId,
            self::ENVIRONMENT,
            $correctiveId,
            self::CHANNEL,
            1,
            'corrective-attempt',
            str_repeat('c', 64),
            null,
        );
        self::assertSame(
            [],
            $this->repository->listReadySubmissions(
                $this->supplierId,
                self::ENVIRONMENT,
                [JmhzSubmissionBridgeService::AGENDA_CODE],
            ),
        );
    }

    /**
     * Pokus, jehož podání v evidenci není.
     *
     * Cizí klíč i vstupní trigger (migrace 1372) to za normálního provozu
     * nedovolí, takže se sem dá dostat jen obnovou po částech nebo zásahem do
     * dat. Vyrábí se proto poctivě — pokus vznikne nad existujícím podáním a to
     * se pak s vypnutou kontrolou smaže. Kdyby přehled joinoval natvrdo, takový
     * pokus by z ledgeru zmizel a s ním i důkaz, že podání odešlo.
     */
    private function openOrphanedAttempt(): int
    {
        $pdo = $this->db->pdo();
        $submissionId = $this->createSubmission($pdo, 'orphan');
        $attemptId = (int) $this->repository->open(
            $this->supplierId,
            self::ENVIRONMENT,
            $submissionId,
            self::CHANNEL,
            1,
            'transport-period-orphan',
            str_repeat('a', 64),
            null,
        )['id'];

        $pdo->exec('SET foreign_key_checks = 0');
        try {
            $pdo->prepare('DELETE FROM payroll_submissions WHERE id = ?')
                ->execute([$submissionId]);
        } finally {
            $pdo->exec('SET foreign_key_checks = 1');
        }

        return $attemptId;
    }

    /** @return array<string,mixed> */
    private function open(string $idempotencyKey): array
    {
        return $this->repository->open(
            $this->supplierId,
            self::ENVIRONMENT,
            $this->submissionId,
            self::CHANNEL,
            $this->nextAttemptNo(),
            $idempotencyKey,
            str_repeat('a', 64),
            null,
        );
    }

    private function nextAttemptNo(): int
    {
        return $this->repository->nextAttemptNo(
            $this->supplierId,
            self::ENVIRONMENT,
            $this->submissionId,
        );
    }

    private function createSubmission(PDO $pdo, string $tag = 'transport'): int
    {
        $pdo->prepare(
            'INSERT INTO payroll_obligations
                (supplier_id, environment, agenda_code, subject_type,
                 subject_reference, period_start, period_end, obligation_kind,
                 preferred_channel, source_event_type, source_event_reference,
                 source_event_hash, request_fingerprint, idempotency_key_hash)
             VALUES (?, ?, "JMHZ", "office", ?, "2026-07-01",
                     "2026-07-31", "regular", ?, "payroll_run_approved",
                     ?, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            self::ENVIRONMENT,
            'office:' . $tag,
            self::CHANNEL,
            "run:{$tag}:2026-07",
            hash('sha256', "obligation-event:{$tag}:{$this->supplierId}"),
            hash('sha256', "obligation-request:{$tag}:{$this->supplierId}"),
            hash('sha256', "transport-obligation:{$tag}:{$this->supplierId}", true),
        ]);
        $obligationId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO payroll_submissions
                (supplier_id, environment, obligation_id, submission_kind,
                 channel, status, source_snapshot_hash, request_fingerprint,
                 idempotency_key_hash)
             VALUES (?, ?, ?, "regular", ?, "prepared", ?, ?, ?)',
        )->execute([
            $this->supplierId,
            self::ENVIRONMENT,
            $obligationId,
            self::CHANNEL,
            hash('sha256', "submission-snapshot:{$tag}:{$this->supplierId}"),
            hash('sha256', "submission-request:{$tag}:{$this->supplierId}"),
            hash('sha256', "transport-submission:{$tag}:{$this->supplierId}", true),
        ]);

        return (int) $pdo->lastInsertId();
    }
}
