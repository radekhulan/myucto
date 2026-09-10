<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Action\Admin\CronJobsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\Cron\CronCatalog;
use MyInvoice\Service\Cron\CronHealth;
use MyInvoice\Service\Cron\CronJobGate;
use MyInvoice\Service\Cron\CronScheduleMode;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Zapojení {@see CronHealth} do přehledu úloh.
 *
 * Scénář, kvůli kterému stav IDLE vznikl: v režimu dispatcheru se
 * `cron-epo-status` (max_age 1 h) nespouští, když nemá práci — heartbeat proto
 * stárne a UI ho hlásilo jako `overdue`, přestože je všechno v pořádku. Test
 * ověřuje, že se ticho promlčí jen tehdy, když je naživu sám dispatcher.
 *
 * Úloha je aktivní jen s podáním EPO, které čeká na stav ({@see CronJobGate::USAGE_EPO_PENDING}),
 * proto si test jedno takové podání založí. Bez něj úloha v přehledu chybí
 * a její důvod jde ven v `inactive`.
 *
 * Původní režim, heartbeaty i založené podání se v tearDown vrací do původního stavu.
 */
#[Group('integration')]
final class CronJobsActionDispatcherHealthTest extends TestCase
{
    private const GATED = 'cron-epo-status';

    private Connection $db;
    private CronJobsAction $action;
    private string $savedMode = CronScheduleMode::INDIVIDUAL;
    /** @var array<string,array<string,mixed>|null> */
    private array $savedHeartbeats = [];
    private ?int $seededSubmissionId = null;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db     = $c->get(Connection::class);
            $this->action = $c->get(CronJobsAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->savedMode = CronScheduleMode::current($pdo);
        foreach ([CronCatalog::DISPATCHER_SCRIPT, self::GATED] as $script) {
            $stmt = $pdo->prepare('SELECT * FROM cron_heartbeat WHERE script = ?');
            $stmt->execute([$script]);
            $this->savedHeartbeats[$script] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        CronScheduleMode::set($pdo, CronScheduleMode::DISPATCHER, null);
        // Gatovaná úloha mlčí půl dne — sama o sobě dávno po limitu.
        $this->writeHeartbeat(self::GATED, 'noop', '-12 hours');
        $this->seedPendingEpoAttempt();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        $this->removeSeededSubmission();
        CronScheduleMode::set($pdo, $this->savedMode, null);
        foreach ($this->savedHeartbeats as $script => $row) {
            $pdo->prepare('DELETE FROM cron_heartbeat WHERE script = ?')->execute([$script]);
            if ($row === null) {
                continue;
            }
            $cols = array_keys($row);
            $pdo->prepare(sprintf(
                'INSERT INTO cron_heartbeat (%s) VALUES (%s)',
                implode(',', $cols),
                implode(',', array_fill(0, count($cols), '?')),
            ))->execute(array_values($row));
        }
    }

    public function testGatedJobIsIdleWhileDispatcherLives(): void
    {
        $this->writeHeartbeat(CronCatalog::DISPATCHER_SCRIPT, 'noop', '-30 seconds');

        $job = $this->fetchJob(self::GATED);
        self::assertSame(CronHealth::IDLE, $job['health']);
        self::assertSame(CronHealth::SOURCE_DISPATCHER, $job['health_source']);
        self::assertNull($this->fetchPayload()['schedule']['scheduler_down'] ?? null);
    }

    public function testGatedJobIsOverdueAgainWhenDispatcherStops(): void
    {
        $this->writeHeartbeat(CronCatalog::DISPATCHER_SCRIPT, 'noop', '-5 hours');

        $job = $this->fetchJob(self::GATED);
        self::assertSame(CronHealth::OVERDUE, $job['health']);
        self::assertSame(CronHealth::SOURCE_SELF, $job['health_source']);
        // Stojí plánovač, ne úloha: stránka to hlásí jednou, stejně jako diagnostika.
        self::assertSame(
            CronHealth::SCHEDULER_DISPATCHER_DOWN,
            $this->fetchPayload()['schedule']['scheduler_down'] ?? null,
        );
    }

    /** Selhávající dispatcher nesmí ticho podřízené úlohy zakrýt. */
    public function testFailingDispatcherDoesNotMaskSilence(): void
    {
        $this->writeHeartbeat(CronCatalog::DISPATCHER_SCRIPT, 'error', '-5 hours', '-30 seconds');

        $job = $this->fetchJob(self::GATED);
        self::assertSame(CronHealth::OVERDUE, $job['health']);
    }

    /** V režimu jednotlivých úloh se nic nepromlčuje — úloha se spouští vždy. */
    public function testIndividualModeKeepsOverdue(): void
    {
        $pdo = $this->db->pdo();
        CronScheduleMode::set($pdo, CronScheduleMode::INDIVIDUAL, null);
        $this->writeHeartbeat(CronCatalog::DISPATCHER_SCRIPT, 'noop', '-30 seconds');

        $job = $this->fetchJob(self::GATED);
        self::assertSame(CronHealth::OVERDUE, $job['health']);
    }

    /**
     * Bez podání čekajícího na stav nemá úloha co obsluhovat: v přehledu chybí
     * a důvod jde ven v `inactive`, stejně jako v kontrole prostředí.
     */
    public function testJobWithoutPendingSubmissionIsListedAsNotInUse(): void
    {
        $this->removeSeededSubmission();
        $this->writeHeartbeat(CronCatalog::DISPATCHER_SCRIPT, 'noop', '-30 seconds');

        $payload = $this->fetchPayload();
        self::assertNotContains(self::GATED, array_column((array) ($payload['jobs'] ?? []), 'script'));
        self::assertSame(CronJobGate::INACTIVE_NOT_IN_USE, $payload['inactive'][self::GATED] ?? null);
    }

    /** @return array<string,mixed> */
    private function fetchPayload(): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/admin/cron-jobs', ['REMOTE_ADDR' => '127.0.0.1'])
            ->withAttribute(AuthMiddleware::ATTR_USER, ['role' => 'admin']);

        $response = $this->action->__invoke($request, (new ResponseFactory())->createResponse());
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $body = $this->json($response);
        return (array) ($body['data'] ?? $body);
    }

    /** @return array<string,mixed> */
    private function fetchJob(string $script): array
    {
        foreach ((array) ($this->fetchPayload()['jobs'] ?? []) as $job) {
            if (($job['script'] ?? null) === $script) {
                return $job;
            }
        }
        self::fail("Úloha {$script} v odpovědi chybí.");
    }

    /** Syntetické přímé podání EPO, které čeká na stav až za hodinu. */
    private function seedPendingEpoAttempt(): void
    {
        $pdo = $this->db->pdo();
        $supplierId = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        if ($supplierId <= 0) {
            $this->markTestSkipped('Testovací databáze nemá žádnou firmu.');
        }

        $xml = '<Pisemnost/>';
        $pdo->prepare(
            'INSERT INTO tax_submissions
                    (supplier_id, form_code, period_year, period_month, xml_content, xml_size_bytes, xml_sha256)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$supplierId, 'dphdp3', 2000, 1, $xml, strlen($xml), hash('sha256', $xml)]);
        $this->seededSubmissionId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO tax_submission_attempts
                    (supplier_id, tax_submission_id, channel, epo_environment, status,
                     idempotency_key, request_sha256, next_poll_at)
             VALUES (?, ?, 'epo_direct', 'test', 'processing', ?, ?, ?)"
        )->execute([
            $supplierId,
            $this->seededSubmissionId,
            bin2hex(random_bytes(16)),
            hash('sha256', 'cron-jobs-action-test'),
            date('Y-m-d H:i:s', time() + 3600),
        ]);
    }

    private function removeSeededSubmission(): void
    {
        if ($this->seededSubmissionId === null) {
            return;
        }
        $this->db->pdo()->prepare('DELETE FROM tax_submissions WHERE id = ?')->execute([$this->seededSubmissionId]);
        $this->seededSubmissionId = null;
    }

    /**
     * @param 'ok'|'noop'|'error' $status
     * @param string $tickAgo relativní čas posledního ticku
     * @param string|null $okAgo relativní čas posledního úspěchu (default = tick)
     */
    private function writeHeartbeat(string $script, string $status, string $tickAgo, ?string $okAgo = null): void
    {
        $tick = date('Y-m-d H:i:s', (int) strtotime($tickAgo));
        $ok   = $status === 'error' && $okAgo === null
            ? null
            : date('Y-m-d H:i:s', (int) strtotime($okAgo ?? $tickAgo));

        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM cron_heartbeat WHERE script = ?')->execute([$script]);
        $pdo->prepare(
            'INSERT INTO cron_heartbeat
                    (script, last_tick_at, last_status, last_started_at, last_finished_at,
                     last_duration_ms, last_exit_code, last_ok_at)
             VALUES (?, ?, ?, ?, ?, 5, ?, ?)'
        )->execute([$script, $tick, $status, $tick, $tick, $status === 'error' ? 1 : 0, $ok]);
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        return (array) json_decode((string) $response->getBody(), true);
    }
}
