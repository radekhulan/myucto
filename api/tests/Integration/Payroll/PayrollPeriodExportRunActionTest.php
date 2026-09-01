<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollPeriodExportAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Payroll\Export\PayrollPeriodExportQueueService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * `POST /payroll/exports/jobs/{jobId}/run` dotlačí uvízlý archiv bez čekání na cron.
 *
 * Endpoint spouští proces na pozadí, takže se musí bránit dvakrát: cizí job se
 * nesmí dát spustit ANI omylem (jinak by jedna firma vytěžovala worker cizími
 * daty a z odpovědi se dozvěděla, co má druhá ve frontě) a neexistující job se
 * musí odmítnout DŘÍV, než se cokoli odpálí.
 */
#[Group('integration')]
final class PayrollPeriodExportRunActionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollPeriodExportAction $action;
    private PayrollPeriodExportQueueService $queue;
    private int $supplierId;
    private int $otherSupplierId;
    private int $userId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->action = $container->get(PayrollPeriodExportAction::class);
            $this->queue = $container->get(PayrollPeriodExportQueueService::class);
        } catch (\Throwable $exception) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $exception->getMessage());
        }
        if (!$this->db->hasTable('payroll_period_export_jobs')) {
            $this->markTestSkipped('Chybí tabulka payroll_period_export_jobs.');
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')
            ->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')
            ->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)')
            ->execute([$this->supplierId, $this->otherSupplierId]);
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

    public function testForeignAndUnknownJobsCannotBeRun(): void
    {
        $job = $this->queue->enqueueMonthly($this->supplierId, '2097-09', $this->userId);
        $jobId = (int) $job['id'];

        $foreign = $this->action->run(
            $this->request($this->otherSupplierId),
            new Response(),
            ['jobId' => (string) $jobId],
        );
        self::assertSame(404, $foreign->getStatusCode());

        $unknown = $this->action->run(
            $this->request($this->supplierId),
            new Response(),
            ['jobId' => (string) ($jobId + 1_000_000)],
        );
        self::assertSame(404, $unknown->getStatusCode());

        $owned = $this->queue->detail($this->supplierId, $jobId);
        self::assertIsArray($owned);
        self::assertSame(
            'queued',
            (string) $owned['status'],
            'Odmítnutý požadavek nesmí sáhnout na stav cizího jobu.',
        );
    }

    private function request(int $supplierId): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/payroll/exports/jobs/1/run')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }
}
