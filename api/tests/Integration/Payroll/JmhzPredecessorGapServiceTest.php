<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollJmhzExternalSubmissionAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Payroll\Deadline\PayrollDeadlineOverviewService;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzExternalSubmissionStore;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPredecessorGapService;
use MyInvoice\Service\Payroll\Submission\PayrollMonthlyChecklistService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Q15-17: převzatý měsíc, za který předchozí program hlášení JMHZ nepodal.
 * Měsíční přehled dřív tvrdil „žádná otevřená položka" a hlídač termínů
 * mlčel, přestože lhůta uplynula.
 */
#[Group('integration')]
final class JmhzPredecessorGapServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD = '2026-08';

    private ContainerInterface $container;
    private Connection $db;
    private int $supplierId;

    protected function setUp(): void
    {
        $this->container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $db = $this->container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $this->db = $db;
        if (!$db->hasTable('payroll_external_jmhz_submissions')) {
            self::markTestSkipped('Migrace historie podání předchozím programem neproběhla.');
        }
        $pdo = $db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT MIN(id) FROM supplier')?->fetchColumn() ?: 0);
        $userId = (int) ($pdo->query('SELECT MIN(id) FROM users')?->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $userId === 0) {
            self::markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_module_state (supplier_id, status, start_period, activated_by, activated_at)
             VALUES (?, "active", "2026-09-01", ?, NOW())',
        )->execute([$this->supplierId, $userId]);
        $pdo->prepare(
            'INSERT INTO payroll_runs (supplier_id, period_start, payment_date, run_kind, status)
             VALUES (?, ?, "2026-09-10", "takeover", "closed")',
        )->execute([$this->supplierId, self::PERIOD . '-01']);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testTakeoverMonthWithoutSubmissionIsReportedEverywhere(): void
    {
        $gaps = $this->gaps()->missing($this->supplierId, 'production');
        self::assertSame([self::PERIOD], array_column($gaps, 'period'));
        self::assertSame([], $this->gaps()->missing($this->supplierId, 'test'), 'Test prostředí předchozí program nevede.');

        $checklist = $this->container->get(PayrollMonthlyChecklistService::class);
        self::assertInstanceOf(PayrollMonthlyChecklistService::class, $checklist);
        $items = $checklist->checklist($this->supplierId, 'production', self::PERIOD)['items'];
        $rows = array_values(array_filter($items, static fn (array $item): bool => $item['source'] === 'predecessor_jmhz'));
        self::assertCount(1, $rows, 'Měsíční přehled musí nepodané hlášení převzatého měsíce ukázat.');
        self::assertFalse($rows[0]['done']);
        self::assertStringContainsString('8/2026', (string) $rows[0]['action']['reason']);

        $deadlines = $this->container->get(PayrollDeadlineOverviewService::class);
        self::assertInstanceOf(PayrollDeadlineOverviewService::class, $deadlines);
        $references = array_column(
            $deadlines->itemsForWindow($this->supplierId, 'production', '2026-01-01', '2027-12-31'),
            'reference',
        );
        self::assertContains('predecessor_jmhz:' . self::PERIOD, $references, 'Hlídač termínů musí mluvit taky.');
    }

    /**
     * JMHZ se hlásí za období od ledna 2026 (leden až březen zpětně do
     * 30. 6. 2026, sada cz-jmhz-deadlines-2026.transition.v1). Převzatý měsíc
     * roku 2025 hlášení JMHZ nemá, takže ho hlídač nesmí hlásit jako nepodaný.
     */
    public function testTakeoverMonthBeforeJmhzStartIsNotReported(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_runs (supplier_id, period_start, payment_date, run_kind, status)
             VALUES (?, "2025-12-01", "2026-01-10", "takeover", "closed"),
                    (?, "2026-01-01", "2026-02-10", "takeover", "closed")',
        )->execute([$this->supplierId, $this->supplierId]);

        self::assertSame(
            ['2026-01', self::PERIOD],
            array_column($this->gaps()->missing($this->supplierId, 'production'), 'period'),
        );
    }

    public function testSentPredecessorSubmissionClosesTheGap(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_external_jmhz_submissions
                (supplier_id, environment, source, source_key, document_kind, period, submission_type, status,
                 payload_ciphertext, payload_hash, payload_sha256)
             VALUES (?, "production", "jmhz_xml", "syn-2026-08", "monthly", ?, "R", "sent", "x", ?, ?)',
        )->execute([$this->supplierId, self::PERIOD, str_repeat("\0", 32), str_repeat('a', 64)]);

        self::assertSame([], $this->gaps()->missing($this->supplierId, 'production'));
    }

    /** PRE-05: hlášení zrušené stornem téhož GUID měsíc nepodalo, hlídač ho musí hlásit dál. */
    public function testRegularSubmissionCancelledByStornoDoesNotCloseTheGap(): void
    {
        $guid = '44444444-4444-4444-8444-444444444444';
        $insert = $this->db->pdo()->prepare(
            'INSERT INTO payroll_external_jmhz_submissions
                (supplier_id, environment, source, source_key, document_kind, period, submission_type, submission_guid, status,
                 payload_ciphertext, payload_hash, payload_sha256)
             VALUES (?, "production", "jmhz_xml", ?, "monthly", ?, ?, ?, "sent", "x", ?, ?)',
        );
        $insert->execute([$this->supplierId, 'syn-r', self::PERIOD, 'R', $guid, str_repeat("\0", 32), str_repeat('a', 64)]);
        self::assertSame([], $this->gaps()->missing($this->supplierId, 'production'));

        $insert->execute([$this->supplierId, 'syn-s', self::PERIOD, 'S', $guid, str_repeat("\0", 32), str_repeat('c', 64)]);
        self::assertSame([self::PERIOD], array_column($this->gaps()->missing($this->supplierId, 'production'), 'period'));
    }

    /**
     * Účetní potvrdí, že hlášení podal předchozí program mimo MyÚčto: měsíc
     * přestane být mezerou všude (hlídač, Měsíční přehled) a platí jako odeslané
     * řádné hlášení, takže druhé řádné hlášení MyÚčto nepřipraví. Potvrzení jde
     * vzít zpět; převzatý doklad tudy smazat nejde.
     */
    public function testManualAttestationClosesTheGapAndCanBeRevoked(): void
    {
        $store = $this->container->get(JmhzExternalSubmissionStore::class);
        self::assertInstanceOf(JmhzExternalSubmissionStore::class, $store);

        $result = $store->attestMonthly($this->supplierId, 'production', self::PERIOD, '2026-09-15', ' podáno portálem ', null);

        self::assertSame([], $this->gaps()->missing($this->supplierId, 'production'));
        $sent = $store->sentMonthly($this->supplierId, 'production', self::PERIOD);
        self::assertSame(JmhzExternalSubmissionStore::SOURCE_MANUAL_ATTESTATION, $sent['source'] ?? null);
        self::assertSame('R', $sent['submission_type'] ?? null);
        $checklist = $this->container->get(PayrollMonthlyChecklistService::class);
        self::assertInstanceOf(PayrollMonthlyChecklistService::class, $checklist);
        $items = $checklist->checklist($this->supplierId, 'production', self::PERIOD)['items'];
        self::assertSame([], array_values(array_filter(
            $items,
            static fn (array $item): bool => $item['source'] === 'predecessor_jmhz' && !$item['done'],
        )));
        $row = array_values(array_filter(
            $store->overview($this->supplierId, 'production'),
            static fn (array $item): bool => $item['id'] === $result['id'],
        ));
        self::assertSame('podáno portálem', $row[0]['note'] ?? null);

        $this->db->pdo()->prepare(
            'INSERT INTO payroll_external_jmhz_submissions
                (supplier_id, environment, source, source_key, document_kind, period, submission_type, status,
                 payload_ciphertext, payload_hash, payload_sha256)
             VALUES (?, "production", "jmhz_xml", "syn-2026-07", "monthly", "2026-07", "R", "sent", "x", ?, ?)',
        )->execute([$this->supplierId, str_repeat("\0", 32), str_repeat('a', 64)]);
        $imported = (int) $this->db->pdo()->lastInsertId();
        self::assertNull($store->revokeAttestation($this->supplierId, 'production', $imported));

        $revoked = $store->revokeAttestation($this->supplierId, 'production', $result['id']);
        self::assertSame(self::PERIOD, $revoked['period'] ?? null);
        self::assertSame([self::PERIOD], array_column($this->gaps()->missing($this->supplierId, 'production'), 'period'));
    }

    /**
     * Potvrdit jde jen měsíc, který hlídač právě hlásí jako nepodaný; jiný by
     * potvrzením tiše zmizel z povinností. Potvrzení se zapisuje do auditu.
     */
    public function testAttestActionAcceptsOnlyMissingPeriods(): void
    {
        $action = $this->container->get(PayrollJmhzExternalSubmissionAction::class);
        self::assertInstanceOf(PayrollJmhzExternalSubmissionAction::class, $action);
        $userId = (int) ($this->db->pdo()->query('SELECT MIN(id) FROM users')?->fetchColumn() ?: 0);

        $refused = $action->attest($this->attestRequest($userId, ['2026-07'], '2026-09-15'), new Response());
        self::assertSame(422, $refused->getStatusCode(), (string) $refused->getBody());
        $future = $action->attest($this->attestRequest($userId, [self::PERIOD], '2999-01-01'), new Response());
        self::assertSame(422, $future->getStatusCode());

        $ok = $action->attest($this->attestRequest($userId, [self::PERIOD], '2026-09-15'), new Response());
        self::assertSame(200, $ok->getStatusCode(), (string) $ok->getBody());
        self::assertSame([], $this->gaps()->missing($this->supplierId, 'production'));
        $audit = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM activity_log WHERE action = "payroll.jmhz_external_attested" AND supplier_id = ?',
        );
        $audit->execute([$this->supplierId]);
        self::assertSame(1, (int) $audit->fetchColumn());
    }

    /** @param list<string> $periods */
    private function attestRequest(int $userId, array $periods, string $submittedOn): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/payroll/submissions/jmhz-external/attestations')
            ->withQueryParams(['environment' => 'production'])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withParsedBody(['periods' => $periods, 'submitted_on' => $submittedOn, 'note' => 'portál']);
    }

    private function gaps(): JmhzPredecessorGapService
    {
        $service = $this->container->get(JmhzPredecessorGapService::class);
        self::assertInstanceOf(JmhzPredecessorGapService::class, $service);

        return $service;
    }
}
