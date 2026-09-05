<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Automation;

use MyInvoice\Action\Automation\AutomationRecommendationJobAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Service\Automation\AutomationFeedService;
use MyInvoice\Service\Automation\AutomationRecommendationCache;
use MyInvoice\Service\Automation\AutomationRecommendationJobService;
use MyInvoice\Service\Automation\AutomationRecommendationService;
use MyInvoice\Service\Automation\AutomationRecommendationWorkerLauncher;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class AutomationRecommendationJobTest extends BankPostingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->db->pdo()->prepare('DELETE FROM import_jobs WHERE supplier_id=? AND source=?')->execute([$this->supplierId, AutomationRecommendationJobService::SOURCE]);
    }

    public function testStartIsDetachedAndIdempotentThenWorkerPublishesWithProgress(): void
    {
        $generator = $this->createMock(AutomationRecommendationService::class);
        $generator->expects(self::once())->method('snapshotForSupplier')->willReturnCallback(function (int $supplierId, callable $progress): array {
            self::assertSame($this->supplierId, $supplierId);
            $progress('expense_rules', 2);
            $row = $this->db->pdo()->query("SELECT current_step,processed,status FROM import_jobs WHERE source='automation_recommendations' ORDER BY id DESC LIMIT 1")->fetch();
            self::assertSame('expense_rules', $row['current_step']);
            self::assertSame(2, (int) $row['processed']);
            self::assertSame('running', $row['status']);
            return ['items' => [], 'coverage' => []];
        });
        $launcher = $this->createMock(AutomationRecommendationWorkerLauncher::class);
        $launcher->expects(self::once())->method('spawn')->willReturn(true);
        $service = $this->serviceFor($generator, $launcher);
        $first = $service->start($this->supplierId, $this->userId);
        self::assertSame('queued', $first['status']);
        self::assertSame($first['id'], $service->start($this->supplierId, $this->userId)['id']);
        $service->run($first['id']);
        $done = $service->latest($this->supplierId);
        self::assertSame('completed', $done['status']);
        self::assertSame(6, $done['processed']);
        self::assertSame(0, $done['created_count']);
        $service->run($first['id']);
        self::assertNull($service->latest(999999));
    }

    public function testSpawnFailureIsVisibleAndRetryable(): void
    {
        $generator = $this->createMock(AutomationRecommendationService::class);
        $generator->expects(self::never())->method('snapshotForSupplier');
        $launcher = $this->createMock(AutomationRecommendationWorkerLauncher::class);
        $launcher->expects(self::exactly(2))->method('spawn')->willReturnOnConsecutiveCalls(false, true);
        $service = $this->serviceFor($generator, $launcher);
        $failed = $service->start($this->supplierId, $this->userId);
        self::assertSame('failed', $failed['status']);
        self::assertSame('worker_spawn_failed', $failed['last_error']);
        $retry = $service->start($this->supplierId, $this->userId);
        self::assertNotSame($failed['id'], $retry['id']);
        self::assertSame('queued', $retry['status']);
    }

    public function testWorkerFailureKeepsPublishedSnapshot(): void
    {
        $generator = $this->createMock(AutomationRecommendationService::class);
        $generator->expects(self::once())->method('snapshotForSupplier')->willThrowException(new \RuntimeException('synthetic failure'));
        $launcher = $this->createMock(AutomationRecommendationWorkerLauncher::class);
        $launcher->expects(self::once())->method('spawn')->willReturn(true);
        $service = $this->serviceFor($generator, $launcher);
        $job = $service->start($this->supplierId, $this->userId);
        $this->db->pdo()->prepare("UPDATE automation_recommendation_snapshots SET generated_at='2099-01-01 00:00:00' WHERE supplier_id=?")->execute([$this->supplierId]);
        $service->run($job['id']);
        self::assertSame('failed', $service->latest($this->supplierId)['status']);
        $stmt = $this->db->pdo()->prepare('SELECT generated_at FROM automation_recommendation_snapshots WHERE supplier_id=?');
        $stmt->execute([$this->supplierId]);
        self::assertSame('2099-01-01 00:00:00', $stmt->fetchColumn());
    }

    public function testJobEndpointsRequireCurrentCompanyAndPermissionAndGetNeverStartsWork(): void
    {
        $generator = $this->createMock(AutomationRecommendationService::class);
        $generator->expects(self::never())->method('snapshotForSupplier');
        $launcher = $this->createMock(AutomationRecommendationWorkerLauncher::class);
        $launcher->expects(self::never())->method('spawn');
        $service = $this->serviceFor($generator, $launcher);
        $action = new AutomationRecommendationJobAction($service, $this->container->get(AutomationFeedService::class), $this->container->get(AccountingModeRepository::class));
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/automation/recommendations/job')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withQueryParams(['suppliers' => (string) $this->supplierId]);
        self::assertSame(200, $action->status($request, new Response())->getStatusCode());
        self::assertSame(422, $action->start($request->withQueryParams(['suppliers' => '999999']), new Response())->getStatusCode());
        self::assertSame(422, $action->start($request->withQueryParams([]), new Response())->getStatusCode());
        self::assertSame(403, $action->start($request->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'client']), new Response())->getStatusCode());
    }

    private function serviceFor(AutomationRecommendationService $generator, AutomationRecommendationWorkerLauncher $launcher): AutomationRecommendationJobService
    {
        return new AutomationRecommendationJobService(
            $this->db,
            $this->container->get(ImportJobRepository::class),
            new AutomationRecommendationCache($this->db, $this->container->get(AutomationFeedService::class), $generator),
            $launcher,
        );
    }
}
