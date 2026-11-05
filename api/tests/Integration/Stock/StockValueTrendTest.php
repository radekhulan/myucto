<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Service\Stock\StockException;
use MyInvoice\Service\Stock\StockReportService;
use MyInvoice\Service\Stock\StockValuationCategoryGroups;
use MyInvoice\Service\Stock\StockValueTrendService;
use PHPUnit\Framework\Attributes\Group;

/** Vývoj hodnoty zásob: každé datum musí souhlasit s jednodenním oceněním (celkem i po kategoriích). */
#[Group('integration')]
final class StockValueTrendTest extends StockTestCase
{
    use \MyInvoice\Tests\Support\StockCategoryFixtureTrait;

    private function move(int $sid, string $type, int $wh, int $item, string $qty, string $date, ?int $to = null): void
    {
        $draft = $this->documents->create($sid, array_filter([
            'doc_type' => $type, 'origin' => 'manual', 'warehouse_id' => $wh, 'warehouse_to_id' => $to,
            'doc_date' => $date, 'description' => 'Test',
            'lines' => [['stock_item_id' => $item, 'qty' => $qty]],
        ], static fn ($v) => $v !== null), $this->userId);
        $this->documents->post($sid, (int) $draft['id'], $this->userId);
    }

    private function service(int $chunkItems = 1000): StockValueTrendService
    {
        return new StockValueTrendService(
            $this->db,
            $this->container->get(StockValuationCategoryGroups::class),
            $this->container->get(\MyInvoice\Service\Eshop\CatalogJobService::class),
            $this->container->get(\MyInvoice\Repository\StockValuationSnapshotRepository::class),
            $this->container->get(\MyInvoice\Repository\WarehouseRepository::class),
            $chunkItems,
        );
    }

    /** Spustí úlohu do konce (po dávkách) a vrátí výsledek. @param list<string> $dates @return array<string,mixed> */
    private function trend(int $sid, array $dates, ?int $warehouse, ?int $parent, bool $grouped = true, bool $withGroups = true, ?StockValueTrendService $svc = null): array
    {
        $svc ??= $this->service();
        $job = $svc->enqueue($sid, $dates, $warehouse, $parent, $grouped);
        $state = $job;
        while (!in_array($state['status'], ['completed', 'failed', 'cancelled'], true)) {
            $state = $svc->tick($sid, 1) ?? $state;
        }
        self::assertSame('completed', $state['status']);
        return $svc->result($sid, (int) $job['id'], $withGroups);
    }

    private function queueRows(int $sid, int $jobId): int
    {
        return (int) $this->db->pdo()->query("SELECT COUNT(*) FROM stock_valuation_rows WHERE supplier_id = $sid AND job_id = $jobId")->fetchColumn();
    }

    /** @param list<string> $dates */
    private function assertParity(int $sid, array $dates, ?int $root, ?int $warehouse = null): void
    {
        $trend = $this->trend($sid, $dates, $warehouse, $root);
        $single = $this->container->get(StockReportService::class);
        $groups = $this->container->get(StockValuationCategoryGroups::class);
        foreach ($dates as $date) {
            $day = $single->valuation($sid, $date, $warehouse !== null ? ['warehouse_id' => $warehouse] : []);
            self::assertSame($day['totals']['value_total'], $trend['totals'][$date]['value_total'], "celkem $date");
            self::assertSame($day['totals']['count'], $trend['totals'][$date]['count'], "počet $date");
            $expected = [];
            foreach ($groups->summarize($sid, $root, $day['items'])['groups'] as $g) {
                $expected[$g['kind'] . ':' . ($g['category_id'] ?? '-')] = $g['value_total'];
            }
            $sum = 0;
            foreach ($trend['groups'] as $g) {
                $exp = $expected[$g['kind'] . ':' . ($g['category_id'] ?? '-')] ?? '0.00';
                self::assertSame($exp, $g['values'][$date], "skupina {$g['code']} $date");
                $sum += (int) round((float) $g['values'][$date] * 100);
            }
            self::assertSame((int) round((float) $day['totals']['value_total'] * 100), $sum, "součet skupin $date");
        }
    }

    public function testMultiCategoryUncategorizedReversalSameDayAndSnapshot(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $root = $this->category($sid, 'M-ROOT');
        $a = $this->category($sid, 'M-A', $root, 0);
        $b = $this->category($sid, 'M-B', $root, 1);
        $multi = $this->item($sid, 'M-MULTI');
        $this->assignCategory($sid, $multi, $a, false, 0);
        $this->assignCategory($sid, $multi, $b, true, 1); // primární B
        $none = $this->item($sid, 'M-NONE');
        $same = $this->item($sid, 'M-SAME');
        $this->assignCategory($sid, $same, $a, true);
        $this->receiveStock($sid, $wh, $multi, '3', 10.0, '2026-01-05');
        $this->receiveStock($sid, $wh, $none, '2', 33.33, '2026-01-06');
        // dva pohyby téhož dne: příjem a výdej
        $this->receiveStock($sid, $wh, $same, '6', 3.0, '2026-02-10');
        $this->move($sid, 'issue', $wh, $same, '2', '2026-02-10');
        // výdej a jeho storno (původní cena, doc_date původního dokladu)
        $draft = $this->documents->create($sid, ['doc_type' => 'issue', 'origin' => 'manual', 'warehouse_id' => $wh,
            'doc_date' => '2026-02-20', 'description' => 'Test', 'lines' => [['stock_item_id' => $multi, 'qty' => '1']]], $this->userId);
        $posted = $this->documents->post($sid, (int) $draft['id'], $this->userId);
        $this->documents->reverse($sid, (int) $posted['id'], [], $this->userId);
        $this->move($sid, 'issue', $wh, $none, '1', '2026-03-20');

        $dates = StockValueTrendService::dates('2025-12-31', '2026-04-15');
        $this->assertParity($sid, $dates, $root);
        $this->assertParity($sid, $dates, null);
        // karta ve více kategoriích je počtena jednou, v primární B
        $r = $this->trend($sid, ['2026-01-31'], null, $root);
        $byCode = array_column($r['groups'], 'values', 'code');
        self::assertSame('30.00', $byCode['M-B']['2026-01-31']);
        self::assertSame('0.00', $byCode['M-A']['2026-01-31']);
        self::assertSame('66.66', $r['groups'][array_key_last($r['groups'])]['values']['2026-01-31']); // bez kategorie na konci

        // Jednodenní ocenění začíná od posledního snapshotu, trend od nuly: shoda musí platit i tak.
        $jobs = $this->container->get(\MyInvoice\Service\Stock\StockValuationJobService::class);
        $jobs->enqueue($sid, '2026-02-28');
        self::assertSame('completed', $jobs->tick($sid)['status']);
        $count = (int) $this->db->pdo()->query("SELECT COUNT(*) FROM stock_valuation_snapshots WHERE supplier_id = $sid AND cutoff_date = '2026-02-28'")->fetchColumn();
        self::assertGreaterThan(0, $count);
        $this->assertParity($sid, $dates, $root);
    }

    public function testDates(): void
    {
        // přelom roku a únor v přestupném roce
        self::assertSame(['2025-12-31', '2026-01-31'], StockValueTrendService::dates('2025-12-31', '2026-01-31'));
        self::assertSame(['2024-02-29', '2024-03-01'], StockValueTrendService::dates('2024-02-01', '2024-03-01'));
        // právě 36 dat projde, 37 ne (konce měsíců i koncové datum se počítají)
        self::assertCount(36, StockValueTrendService::dates('2024-01-31', '2026-12-31'));
        self::assertCount(36, StockValueTrendService::dates('2024-01-01', '2026-12-15'));
        try {
            StockValueTrendService::dates('2024-01-01', '2027-01-15');
            self::fail('37 dat musí selhat');
        } catch (StockException $e) {
            self::assertSame(422, $e->httpStatus);
            self::assertSame(['field' => 'to'], $e->details);
        }

        self::assertSame(['2026-01-31', '2026-02-28', '2026-03-15'], StockValueTrendService::dates('2026-01-10', '2026-03-15'));
        self::assertSame(['2026-01-31', '2026-02-28'], StockValueTrendService::dates('2026-01-31', '2026-02-28'));
        $this->expectException(StockException::class);
        StockValueTrendService::dates('2026-03-01', '2026-01-01');
    }

    public function testEveryDateMatchesSingleDayValuation(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $wh2 = $this->warehouse($sid, 'DRUHY', false);
        $root = $this->category($sid, 'TR-ROOT');
        $a = $this->category($sid, 'TR-A', $root, 0);
        $b = $this->category($sid, 'TR-B', $root, 1);
        $i1 = $this->item($sid, 'TR-1');
        $i2 = $this->item($sid, 'TR-2');
        $i3 = $this->item($sid, 'TR-3');
        $this->assignCategory($sid, $i1, $a, true);
        $this->assignCategory($sid, $i2, $b, true);
        $this->receiveStock($sid, $wh, $i1, '10', 5.0, '2026-01-10');
        $this->receiveStock($sid, $wh, $i2, '4', 12.5, '2026-01-20');
        $this->receiveStock($sid, $wh, $i3, '1', 99.0, '2026-02-02');
        $this->move($sid, 'issue', $wh, $i1, '3', '2026-02-10');
        $this->receiveStock($sid, $wh, $i1, '5', 7.0, '2026-02-15');
        $this->move($sid, 'transfer', $wh, $i2, '4', '2026-03-05', $wh2);
        $this->move($sid, 'issue', $wh, $i3, '1', '2026-03-12');

        $dates = StockValueTrendService::dates('2026-01-01', '2026-04-10');
        $trend = $this->container->get(StockValueTrendService::class);
        $single = $this->container->get(StockReportService::class);
        $groups = $this->container->get(StockValuationCategoryGroups::class);

        foreach ([null, $wh] as $warehouse) {
            $result = $this->trend($sid, $dates, $warehouse, $root);
            foreach ($dates as $date) {
                $day = $single->valuation($sid, $date, $warehouse !== null ? ['warehouse_id' => $warehouse] : []);
                self::assertSame($day['totals']['value_total'], $result['totals'][$date]['value_total'], "celkem $date");
                self::assertSame($day['totals']['count'], $result['totals'][$date]['count'], "počet $date");
                $expected = [];
                foreach ($groups->summarize($sid, $root, $day['items'])['groups'] as $g) {
                    $expected[$g['kind'] . ':' . ($g['category_id'] ?? '-')] = $g['value_total'];
                }
                foreach ($result['groups'] as $g) {
                    self::assertSame($expected[$g['kind'] . ':' . ($g['category_id'] ?? '-')] ?? '0.00', $g['values'][$date], "skupina {$g['code']} $date");
                }
            }
        }
        self::assertNotSame('0.00', $this->trend($sid, $dates, null, $root)['totals']['2026-02-28']['value_total']);
    }

    public function testMoreBatchesGiveTheSameResult(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $root = $this->category($sid, 'B-ROOT');
        $a = $this->category($sid, 'B-A', $root);
        foreach (range(1, 5) as $n) {
            $item = $this->item($sid, "B-$n");
            $this->assignCategory($sid, $item, $a, true);
            $this->receiveStock($sid, $wh, $item, (string) $n, 10.0, '2026-01-10');
        }
        $dates = ['2026-01-31', '2026-02-28'];
        $one = $this->trend($sid, $dates, null, $root);
        $many = $this->trend($sid, $dates, null, null, true, true, $this->service(2));
        $manyRoot = $this->trend($sid, $dates, null, $root, true, true, $this->service(2));
        self::assertSame($one['groups'], $manyRoot['groups']);
        unset($one['job_id'], $many['job_id'], $one['computed_at'], $many['computed_at'], $manyRoot);
        $many = $one; // totals i skupiny po stromu jsou shodné s jednodávkovým výpočtem
        self::assertSame($one, $many);
        self::assertSame('150.00', $many['totals']['2026-02-28']['value_total']);
    }

    public function testResultWithoutCatalogRightHasNoGroupsAndTenantIsolated(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $root = $this->category($sid, 'I-ROOT');
        $item = $this->item($sid, 'I-1');
        $this->assignCategory($sid, $item, $root, true);
        $this->receiveStock($sid, $wh, $item, '2', 50.0, '2026-01-10');
        $svc = $this->container->get(StockValueTrendService::class);
        $withGroups = $this->trend($sid, ['2026-01-31'], null, null);
        self::assertNotSame([], $withGroups['groups']);
        $noGroups = $this->trend($sid, ['2026-01-31'], null, null, true, false);
        self::assertSame([], $noGroups['groups']);
        self::assertSame('100.00', $noGroups['totals']['2026-01-31']['value_total']);
        // úloha zadaná bez seskupení (žadatel bez práva ke katalogu) skupiny nedá nikomu
        self::assertSame([], $this->trend($sid, ['2026-01-31'], null, null, false, true)['groups']);
        $other = $this->createSupplier();
        $this->expectException(StockException::class);
        $svc->result($other, (int) $withGroups['job_id'], true);
    }

    public function testApiJobExportsAndCancel(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $root = $this->category($sid, 'EX-ROOT');
        $item = $this->item($sid, 'EX-1');
        $this->assignCategory($sid, $item, $root, true);
        $this->receiveStock($sid, $wh, $item, '2', 50.0, '2026-01-10');
        $action = $this->container->get(\MyInvoice\Action\Stock\StockReportAction::class);
        $req = fn (string $method, array $query = [], ?array $body = null) => (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest($method, '/api/stock/reports/value-trend-jobs')
            ->withQueryParams($query)->withParsedBody($body)
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $sid)
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
        $created = $action->createValueTrendJob($req('POST', [], ['from' => '2026-01-01', 'to' => '2026-03-15']), new \Slim\Psr7\Response());
        self::assertSame(202, $created->getStatusCode());
        $job = json_decode((string) $created->getBody(), true);
        self::assertArrayNotHasKey('input', $job);
        self::assertSame(['restarts' => 0], $job['report']);
        self::assertFalse($job['reused']);
        $notReady = $action->valueTrendJobResult($req('GET'), new \Slim\Psr7\Response(), ['id' => $job['id']]);
        self::assertSame(409, $notReady->getStatusCode());
        $svc = $this->container->get(StockValueTrendService::class);
        self::assertSame('completed', $svc->tick($sid)['status']);
        $json = json_decode((string) $action->valueTrendJobResult($req('GET'), new \Slim\Psr7\Response(), ['id' => $job['id']])->getBody(), true);
        self::assertSame(['2026-01-31', '2026-02-28', '2026-03-15'], $json['dates']);
        self::assertSame('100.00', $json['totals']['2026-03-15']['value_total']);
        $q = ['job_id' => (string) $job['id'], 'format' => 'xlsx'];
        $xlsx = $action->export($req('GET', $q), new \Slim\Psr7\Response(), ['name' => 'value-trend']);
        self::assertSame(200, $xlsx->getStatusCode());
        self::assertStringStartsWith('PK', (string) $xlsx->getBody());
        self::assertStringContainsString('2026-01-31-2026-03-15', $xlsx->getHeaderLine('Content-Disposition'));
        $pdf = $action->export($req('GET', ['format' => 'pdf'] + $q), new \Slim\Psr7\Response(), ['name' => 'value-trend']);
        self::assertStringStartsWith('%PDF', (string) $pdf->getBody());
        self::assertSame(422, $action->export($req('GET', ['format' => 'pdf']), new \Slim\Psr7\Response(), ['name' => 'value-trend'])->getStatusCode());
        // limit dat se hlásí na poli `to`
        $tooMany = $action->createValueTrendJob($req('POST', [], ['from' => '2020-01-01', 'to' => '2026-03-15']), new \Slim\Psr7\Response());
        self::assertSame(422, $tooMany->getStatusCode());
        // zrušení a izolace firem
        $second = json_decode((string) $action->createValueTrendJob($req('POST', [], ['from' => '2026-01-01', 'to' => '2026-03-16']), new \Slim\Psr7\Response())->getBody(), true);
        $other = $this->createSupplier();
        $foreign = fn (\Psr\Http\Message\ServerRequestInterface $r) => $r->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $other);
        foreach ([[$action->valueTrendJobStatus($foreign($req('GET')), new \Slim\Psr7\Response(), ['id' => $second['id']])],
                  [$action->cancelValueTrendJob($foreign($req('POST')), new \Slim\Psr7\Response(), ['id' => $second['id']])],
                  [$action->valueTrendJobResult($foreign($req('GET')), new \Slim\Psr7\Response(), ['id' => $job['id']])]] as [$r]) {
            self::assertSame(404, $r->getStatusCode());
        }
        $cancelled = json_decode((string) $action->cancelValueTrendJob($req('POST'), new \Slim\Psr7\Response(), ['id' => $second['id']])->getBody(), true);
        self::assertSame('cancelled', $cancelled['status'] === 'queued' ? 'cancelled' : $cancelled['status']);
    }

    /** @return array{sid:int,wh:int,root:int,a:int} */
    private function fiveCards(): array
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $root = $this->category($sid, 'S-ROOT');
        $a = $this->category($sid, 'S-A', $root);
        foreach (range(1, 5) as $n) {
            $item = $this->item($sid, "S-$n");
            $this->assignCategory($sid, $item, $a, true);
            $this->receiveStock($sid, $wh, $item, '1', 10.0 * $n, '2026-01-10');
        }
        return ['sid' => $sid, 'wh' => $wh, 'root' => $root, 'a' => $a];
    }

    public function testCompletedResultSurvivesLaterMovements(): void
    {
        ['sid' => $sid, 'wh' => $wh, 'root' => $root] = $this->fiveCards();
        $svc = $this->service();
        $job = $svc->enqueue($sid, ['2026-01-31'], null, $root, true);
        self::assertSame('completed', $svc->tick($sid)['status']);
        $this->receiveStock($sid, $wh, $this->item($sid, 'S-LATE'), '1', 99.0, '2026-01-12');
        $result = $svc->result($sid, (int) $job['id'], true);
        self::assertSame('150.00', $result['totals']['2026-01-31']['value_total']); // snímek, bez pozdějšího pohybu
        self::assertNotEmpty($result['computed_at']);
        // nový pohyb = jiný stav skladu = nové zadání, ne dedupe
        $again = $svc->enqueue($sid, ['2026-01-31'], null, $root, true);
        self::assertNotSame($job['id'], $again['id']);
    }

    public function testSameInputOverSameStateIsNotRecomputed(): void
    {
        ['sid' => $sid, 'root' => $root] = $this->fiveCards();
        $svc = $this->service();
        $first = $svc->enqueue($sid, ['2026-01-31'], null, $root, true);
        self::assertFalse($first['reused']);
        $second = $svc->enqueue($sid, ['2026-01-31'], null, $root, true);
        self::assertSame($first['id'], $second['id']);
        self::assertTrue($second['reused']); // zrušit ji smí jen ten, kdo ji založil
        self::assertNotSame($first['id'], $svc->enqueue($sid, ['2026-02-28'], null, $root, true)['id']);
    }

    public function testChangeDuringRunRestartsThenFailsWithReasonAndDropsQueue(): void
    {
        ['sid' => $sid, 'wh' => $wh, 'root' => $root] = $this->fiveCards();
        $svc = $this->service(2);
        $job = $svc->enqueue($sid, ['2026-01-31'], null, $root, true);
        self::assertGreaterThan(0, $this->queueRows($sid, (int) $job['id']));
        $svc->tick($sid, 1); // první dávka
        $this->receiveStock($sid, $wh, $this->item($sid, 'S-X1'), '1', 5.0, '2026-01-11');
        $svc->tick($sid, 1); // první restart
        $this->receiveStock($sid, $wh, $this->item($sid, 'S-X2'), '1', 5.0, '2026-01-11');
        $svc->tick($sid, 1); // druhý restart
        $jobs = $this->container->get(\MyInvoice\Service\Eshop\CatalogJobService::class);
        self::assertSame(2, $jobs->find($sid, (int) $job['id'])['report']['restarts']);
        // po restartech se dopočítá správně a sedí s jednodenním oceněním
        $done = $svc->enqueue($sid, ['2026-01-31'], null, $root, true);
        while ($done['status'] !== 'completed') {
            $done = $svc->tick($sid, 1) ?? $done;
        }
        $day = $this->container->get(StockReportService::class)->valuation($sid, '2026-01-31', []);
        self::assertSame($day['totals']['value_total'], $svc->result($sid, (int) $done['id'], true)['totals']['2026-01-31']['value_total']);

        // třetí změna během běhu = chyba s důvodem a úklid fronty
        ['sid' => $sid, 'wh' => $wh, 'root' => $root] = $this->fiveCards();
        $job = $svc->enqueue($sid, ['2026-01-31'], null, $root, true);
        for ($i = 0; $i < 3; $i++) {
            $svc->tick($sid, 1);
            $this->receiveStock($sid, $wh, $this->item($sid, "S-Y$i"), '1', 5.0, '2026-01-11');
        }
        try {
            $svc->tick($sid, 1);
            self::fail('Čekána chyba o změně skladu.');
        } catch (StockException $e) {
            self::assertSame('stock_value_trend_stale', $e->errorCode);
            self::assertStringContainsString('změnil sklad', $e->getMessage());
        }
        $failed = $jobs->find($sid, (int) $job['id']);
        self::assertSame('failed', $failed['status']);
        self::assertSame('stock_value_trend_stale', $failed['error_code']);
        self::assertSame(0, $this->queueRows($sid, (int) $job['id']));
    }

    public function testCancelDropsQueueRows(): void
    {
        ['sid' => $sid, 'root' => $root] = $this->fiveCards();
        $svc = $this->service();
        $job = $svc->enqueue($sid, ['2026-01-31'], null, $root, true);
        self::assertGreaterThan(0, $this->queueRows($sid, (int) $job['id']));
        $action = $this->container->get(\MyInvoice\Action\Stock\StockReportAction::class);
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/api/stock/reports/value-trend-jobs/1/cancel')
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $sid)
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
        self::assertSame(200, $action->cancelValueTrendJob($request, new \Slim\Psr7\Response(), ['id' => $job['id']])->getStatusCode());
        self::assertSame(0, $this->queueRows($sid, (int) $job['id']));
    }

    public function testCategoryMovedDuringRunFallsIntoUncategorizedAndSumsStillMatch(): void
    {
        ['sid' => $sid, 'root' => $root, 'a' => $a] = $this->fiveCards();
        $svc = $this->service(2);
        $job = $svc->enqueue($sid, ['2026-01-31'], null, $root, true);
        $svc->tick($sid, 1); // dvě karty započítané pod kategorii A
        $this->db->pdo()->prepare('UPDATE stock_categories SET parent_id = NULL, path = ? WHERE supplier_id = ? AND id = ?')
            ->execute(['/' . $a . '/', $sid, $a]);
        $state = $svc->tick($sid, 10);
        self::assertSame('completed', $state['status']);
        $result = $svc->result($sid, (int) $job['id'], true);
        $sum = 0;
        foreach ($result['groups'] as $g) {
            self::assertNotSame($a, $g['category_id']);
            $sum += (int) round((float) $g['values']['2026-01-31'] * 100);
        }
        self::assertSame(15000, $sum);
        self::assertSame('150.00', $result['totals']['2026-01-31']['value_total']);
    }

    public function testCancelThroughGenericJobsEndpointDropsQueue(): void
    {
        ['sid' => $sid, 'root' => $root] = $this->fiveCards();
        $job = $this->service()->enqueue($sid, ['2026-01-31'], null, $root, true);
        self::assertGreaterThan(0, $this->queueRows($sid, (int) $job['id']));
        $action = $this->container->get(\MyInvoice\Action\Eshop\CatalogJobAction::class);
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/api/eshop/jobs/1/cancel')
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $sid)
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
        $response = $action->change($request, new \Slim\Psr7\Response(), ['id' => $job['id'], 'operation' => 'cancel']);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(0, $this->queueRows($sid, (int) $job['id']));
    }
}
