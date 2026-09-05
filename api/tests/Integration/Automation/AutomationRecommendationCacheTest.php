<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Automation;

use MyInvoice\Action\Automation\AutomationRecommendationAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\Automation\AutomationFeedService;
use MyInvoice\Service\Automation\AutomationRecommendationCache;
use MyInvoice\Service\Automation\AutomationRecommendationService;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class AutomationRecommendationCacheTest extends BankPostingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['automation_recommendation_items', 'automation_recommendation_coverage', 'automation_recommendation_snapshots'] as $table) {
            $this->db->pdo()->prepare("DELETE FROM {$table} WHERE supplier_id=?")->execute([$this->supplierId]);
        }
    }

    public function testHttpOnlyReadsCacheAndRefreshOnlyQueues(): void
    {
        $generator = $this->createMock(AutomationRecommendationService::class);
        $generator->expects(self::never())->method('snapshotForSupplier');
        $generator->expects(self::never())->method('recommendations');
        $cache = $this->cache($generator);
        $action = new AutomationRecommendationAction($cache);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/automation/recommendations')
            ->withQueryParams(['suppliers' => (string) $this->supplierId])
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
        $result = json_decode((string) $action->recommendations($request, new Response())->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([], $result['items']);
        self::assertNull($result['snapshots'][0]['generated_at']);
        self::assertSame(202, $action->refresh($request->withMethod('POST'), new Response())->getStatusCode());
        self::assertTrue($cache->recommendations(0, true, ['suppliers' => [$this->supplierId]])['snapshots'][0]['refresh_pending']);
        self::assertSame(['queued' => 0], $cache->requestRefresh(0, false, [$this->supplierId]));
        self::assertSame([], $cache->recommendations(0, false, ['suppliers' => [$this->supplierId]])['snapshots']);
    }

    public function testWorkerStoresAllRowsAndDatabaseAppliesPagingAndDateFilters(): void
    {
        $generator = $this->createMock(AutomationRecommendationService::class);
        $snapshot = $this->snapshot(205);
        $generator->expects(self::once())->method('snapshotForSupplier')->with($this->supplierId)->willReturn($snapshot);
        $cache = $this->cache($generator);
        self::assertTrue($cache->rebuildSupplier($this->supplierId));
        self::assertFalse($cache->rebuildSupplier($this->supplierId));
        $result = $cache->recommendations(0, true, ['suppliers' => [$this->supplierId], 'per_page' => 200, 'page' => 2]);
        self::assertSame(205, $result['total']);
        self::assertCount(5, $result['items']);
        self::assertNotNull($result['snapshots'][0]['generated_at']);
        self::assertFalse($result['snapshots'][0]['refresh_pending']);
        self::assertSame(['sales' => 205, 'purchases' => 0, 'bank' => 0], $result['summary']);
        $filtered = $cache->recommendations(0, true, ['suppliers' => [$this->supplierId], 'from' => '2099-07-01']);
        self::assertSame(0, $filtered['total']);
        self::assertSame(['sales' => 0, 'purchases' => 0, 'bank' => 0], $filtered['summary']);
        self::assertSame(0, $cache->recommendations(0, true, ['suppliers' => [$this->supplierId], 'type' => 'bank_rule'])['total']);
    }

    public function testFailedReplacementKeepsPreviousSnapshotAndRefreshRequest(): void
    {
        $generator = $this->createMock(AutomationRecommendationService::class);
        $good = $this->snapshot(1);
        $bad = $good;
        $bad['items'][] = $bad['items'][0];
        $generator->expects(self::exactly(2))->method('snapshotForSupplier')->willReturnOnConsecutiveCalls($good, $bad);
        $cache = $this->cache($generator);
        $cache->rebuildSupplier($this->supplierId);
        self::assertSame(['queued' => 1], $cache->requestRefresh(0, true, [$this->supplierId]));
        try {
            $cache->rebuildSupplier($this->supplierId, null, 5, true);
            self::fail('Duplicate cache keys must abort the replacement.');
        } catch (\PDOException) {
            $result = $cache->recommendations(0, true, ['suppliers' => [$this->supplierId]]);
            self::assertSame($good['items'], $result['items']);
            self::assertTrue($result['snapshots'][0]['refresh_pending']);
        }
    }

    public function testRequestArrivingDuringGenerationIsNotLost(): void
    {
        $generator = $this->createMock(AutomationRecommendationService::class);
        $cache = $this->cache($generator);
        $generator->expects(self::once())->method('snapshotForSupplier')->willReturnCallback(function () use ($cache): array {
            $cache->requestRefresh(0, true, [$this->supplierId]);
            return $this->snapshot(1);
        });
        $cache->rebuildSupplier($this->supplierId);
        $result = $cache->recommendations(0, true, ['suppliers' => [$this->supplierId]]);
        self::assertSame(1, $result['total']);
        self::assertTrue($result['snapshots'][0]['refresh_pending']);
    }

    private function cache(AutomationRecommendationService $generator): AutomationRecommendationCache
    {
        return new AutomationRecommendationCache($this->db, $this->container->get(AutomationFeedService::class), $generator);
    }

    public function testDailyForcedRefreshRespectsScopeAndRefreshesFreshCache(): void
    {
        $generator = $this->createMock(AutomationRecommendationService::class);
        $generator->expects(self::exactly(2))->method('snapshotForSupplier')->with($this->supplierId)->willReturn($this->snapshot(1));
        $cache = $this->cache($generator);
        self::assertTrue($cache->rebuildSupplier($this->supplierId));
        self::assertSame(0, $cache->run(55, true, [])['refreshed']);
        self::assertSame(0, $cache->run(55, false, [$this->supplierId])['refreshed']);
        self::assertSame(1, $cache->run(55, true, [$this->supplierId])['refreshed']);
    }

    private function snapshot(int $count): array
    {
        $items = [];
        for ($i = 0; $i < $count; ++$i) {
            $items[] = [
                'id' => 'post_invoice:' . $this->supplierId . ':' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'type' => 'post_invoice', 'supplier_id' => $this->supplierId,
                'date' => '2099-06-01', 'description' => 'Synthetic recommendation',
            ];
        }
        return ['items' => $items, 'coverage' => ['2099-06-01' => ['sales' => $count, 'purchases' => 0, 'bank' => 0]]];
    }
}
