<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Eshop;

use MyInvoice\Service\Eshop\CatalogJobService;
use MyInvoice\Tests\Integration\Stock\StockTestCase;

final class CatalogJobTest extends StockTestCase
{
    public function testExpiredWorkerCannotCommitAfterTakeover(): void
    {
        $sid = $this->createSupplier();
        $jobs = $this->container->get(CatalogJobService::class);
        $id = $jobs->enqueue($sid, 'test', [], 2);
        $first = $jobs->claim($sid, 'test');
        self::assertNull($jobs->claim($sid, 'test'));
        $this->db->pdo()->prepare('UPDATE catalog_jobs SET lease_until = DATE_SUB(NOW(6), INTERVAL 1 SECOND) WHERE id = ?')->execute([$id]);
        $second = $jobs->claim($sid, 'test');
        self::assertSame($id, $second['id']);
        self::assertNotSame($first['lease_token'], $second['lease_token']);
        self::assertFalse($jobs->heartbeat($sid, $id, $first['lease_token']));
        $called = false;
        try {
            $jobs->batch($sid, $id, $first['lease_token'], function () use (&$called): array {
                $called = true;
                return ['checkpoint' => 1];
            });
            self::fail('Old worker accepted');
        } catch (\RuntimeException $e) {
            self::assertSame('catalog_job_lease_lost', $e->getMessage());
        }
        self::assertFalse($called);
        $result = $jobs->batch($sid, $id, $second['lease_token'], fn () => ['checkpoint' => 2, 'done' => true]);
        self::assertSame('completed', $result['status']);
    }

    public function testBatchMutationAndCheckpointRollbackTogether(): void
    {
        $sid = $this->createSupplier();
        $jobs = $this->container->get(CatalogJobService::class);
        $id = $jobs->enqueue($sid, 'test', [], 2);
        $claim = $jobs->claim($sid, 'test');
        try {
            $jobs->batch($sid, $id, $claim['lease_token'], function () use ($sid): array {
                $this->item($sid, 'JOB-ROLLBACK');
                throw new \RuntimeException('synthetic_failure');
            });
            self::fail('Failure swallowed');
        } catch (\RuntimeException $e) {
            self::assertSame('synthetic_failure', $e->getMessage());
        }
        self::assertNull($this->itemsRepo->findBySku($sid, 'JOB-ROLLBACK'));
        self::assertSame(0, $jobs->find($sid, $id)['checkpoint']);
    }

    public function testRestartContinuesAfterCommittedCheckpoint(): void
    {
        $sid = $this->createSupplier();
        $jobs = $this->container->get(CatalogJobService::class);
        $id = $jobs->enqueue($sid, 'test', [], 2);
        $claim = $jobs->claim($sid, 'test');
        $jobs->batch($sid, $id, $claim['lease_token'], function () use ($sid): array {
            $this->item($sid, 'JOB-ONCE');
            return ['checkpoint' => 1, 'report' => ['created' => 1]];
        });
        $this->db->pdo()->prepare('UPDATE catalog_jobs SET lease_until = DATE_SUB(NOW(6), INTERVAL 1 SECOND) WHERE id = ?')->execute([$id]);
        $claim = $jobs->claim($sid, 'test');
        $jobs->batch($sid, $id, $claim['lease_token'], function (array $job): array {
            self::assertSame(1, $job['checkpoint']);
            self::assertSame(['created' => 1], $job['report']);
            return ['checkpoint' => 2, 'done' => true];
        });
        self::assertNotNull($this->itemsRepo->findBySku($sid, 'JOB-ONCE'));
    }

    public function testCancellationKeepsCompletedBatchAndSkipsNext(): void
    {
        $sid = $this->createSupplier();
        $jobs = $this->container->get(CatalogJobService::class);
        $id = $jobs->enqueue($sid, 'test', [], 2);
        $claim = $jobs->claim($sid, 'test');
        $jobs->batch($sid, $id, $claim['lease_token'], fn () => ['checkpoint' => 1]);
        self::assertTrue($jobs->cancel($sid, $id));
        $result = $jobs->batch($sid, $id, $claim['lease_token'], function (): array {
            self::fail('Cancelled batch executed');
        });
        self::assertSame('cancelled', $result['status']);
        self::assertSame(1, $result['checkpoint']);
        self::assertTrue($jobs->retry($sid, $id));
        self::assertSame(1, $jobs->claim($sid, 'test')['checkpoint']);
    }

    public function testTenantIsolationAndNoLeaseInPublicResult(): void
    {
        $sid = $this->createSupplier();
        $other = $this->createSupplier();
        $jobs = $this->container->get(CatalogJobService::class);
        $id = $jobs->enqueue($sid, 'test', []);
        self::assertNull($jobs->find($other, $id));
        self::assertSame([], $jobs->history($other));
        self::assertFalse($jobs->cancel($other, $id));
        self::assertArrayNotHasKey('lease_token', $jobs->find($sid, $id));
        self::assertTrue($jobs->cancel($sid, $id));
        self::assertNotNull($jobs->find($sid, $id)['finished_at']);
    }
}
