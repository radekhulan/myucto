<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Eshop;

use MyInvoice\Action\Eshop\CatalogExportAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Eshop\CatalogExportService;
use MyInvoice\Service\Eshop\CatalogExportWorker;
use MyInvoice\Service\Eshop\CatalogJobService;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class CatalogExportTest extends StockTestCase
{
    public function testExportFreezesSelectionAndVersionsAndDownloadsCapturedValues(): void
    {
        $sid = $this->createSupplier();
        $ids = [];
        for ($i = 0; $i < 105; $i++) {
            $ids[] = $this->item($sid, 'EXPORT-' . $i);
        }
        $foreign = $this->item($this->createSupplier(), 'FOREIGN');
        $service = $this->container->get(CatalogExportService::class);
        $job = $service->enqueue($sid, ['ids' => [...$ids, $foreign, 2147483647]], ['fields' => ['sku', 'availability', 'prices']], $this->userId);
        $this->db->pdo()->prepare('UPDATE stock_items SET name = ?, row_version = row_version + 1 WHERE id = ?')->execute(['Changed', $ids[1]]);
        $worker = $this->container->get(CatalogExportWorker::class);
        $partial = $worker->tick($sid, 1);
        self::assertSame(100, $partial['checkpoint']);
        self::assertSame('queued', $partial['status']);
        $this->db->pdo()->prepare('UPDATE stock_items SET sku = ?, row_version = row_version + 1 WHERE id = ?')->execute(['LATER', $ids[0]]);
        $done = $worker->tick($sid);
        self::assertSame('completed', $done['status']);
        self::assertSame(107, $done['checkpoint']);
        $stream = $service->download($sid, $job['id']);
        $lines = array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), explode("\n", trim(stream_get_contents($stream))));
        fclose($stream);
        self::assertCount(108, $lines);
        self::assertSame('manifest', $lines[0]['type']);
        self::assertSame(107, $lines[0]['total']);
        self::assertSame('ok', $lines[1]['status']);
        self::assertSame('EXPORT-0', $lines[1]['data']['sku']);
        self::assertNotEmpty($lines[1]['captured_at']);
        self::assertSame('conflict', $lines[2]['status']);
        self::assertNull($lines[2]['data']);
        self::assertSame('unavailable', $lines[106]['error_code']);
        self::assertSame('unavailable', $lines[107]['error_code']);
        self::assertArrayNotHasKey('costs', $lines[1]['data']);
        self::assertFalse($this->db->pdo()->inTransaction());
    }

    public function testReadOnlyCanCreateAndDownloadButCostsAndForeignJobAreDenied(): void
    {
        $sid = $this->createSupplier();
        $id = $this->item($sid, 'ACL');
        $action = $this->container->get(CatalogExportAction::class);
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/catalog/exports')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $sid)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'readonly']);
        $response = $action->create($request->withParsedBody(['selection' => ['ids' => [$id]]]), new Response());
        self::assertSame(202, $response->getStatusCode());
        $job = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('input', $job);
        self::assertSame(409, $action->download($request, new Response(), ['id' => $job['id']])->getStatusCode());
        self::assertSame(404, $action->download($request->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->createSupplier()), new Response(), ['id' => $job['id']])->getStatusCode());
        self::assertSame(403, $action->create($request->withParsedBody(['selection' => ['ids' => [$id]], 'projection' => ['fields' => ['costs']]]), new Response())->getStatusCode());
        self::assertSame(400, $action->create($request->withParsedBody(['selection' => ['ids' => [$id]], 'projection' => ['ids' => [$id]]]), new Response())->getStatusCode());
        $this->container->get(CatalogExportWorker::class)->tick($sid);
        $download = $action->download($request, new Response(), ['id' => $job['id']]);
        self::assertSame(200, $download->getStatusCode());
        self::assertSame('application/x-ndjson; charset=utf-8', $download->getHeaderLine('Content-Type'));
    }

    public function testCancelledExportResumesWithoutDuplicatingResults(): void
    {
        $sid = $this->createSupplier();
        $job = $this->container->get(CatalogExportService::class)->enqueue($sid, ['ids' => [$this->item($sid, 'RETRY')]], []);
        $jobs = $this->container->get(CatalogJobService::class);
        self::assertTrue($jobs->cancel($sid, $job['id']));
        self::assertTrue($jobs->retry($sid, $job['id']));
        self::assertSame('completed', $this->container->get(CatalogExportWorker::class)->tick($sid)['status']);
        self::assertNull($this->container->get(CatalogExportWorker::class)->tick($sid));
        self::assertSame(1, $jobs->find($sid, $job['id'])['report']['counts']['ready']);
    }
}
