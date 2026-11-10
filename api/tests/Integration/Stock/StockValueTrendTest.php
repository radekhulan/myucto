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

    private function service(int $batchMovements = 2000): StockValueTrendService
    {
        return new StockValueTrendService(
            $this->db,
            $this->container->get(StockValuationCategoryGroups::class),
            $this->container->get(\MyInvoice\Service\Eshop\CatalogJobService::class),
            $this->container->get(\MyInvoice\Repository\StockValuationSnapshotRepository::class),
            $this->container->get(\MyInvoice\Repository\WarehouseRepository::class),
            $this->container->get(\MyInvoice\Service\Stock\StockLedgerReader::class),
            $batchMovements,
        );
    }

    /** Spustí úlohu do konce (po dávkách) a vrátí výsledek. @param list<string> $dates @return array<string,mixed> */
    private function trend(int $sid, array $dates, ?int $warehouse, ?int $parent, bool $grouped = true, bool $withGroups = true, ?StockValueTrendService $svc = null): array
    {
        $svc ??= $this->service();
        $job = $svc->enqueue($sid, $dates, $warehouse, $parent, $grouped);
        $state = $job;
        for ($i = 0; $i < 500 && !in_array($state['status'], ['completed', 'failed', 'cancelled'], true); $i++) {
            $state = $svc->tick($sid, 1) ?? $state;
        }
        self::assertSame('completed', $state['status']);
        return $svc->result($sid, (int) $job['id'], $withGroups);
    }

    /** Posune úlohu do fáze výpočtu (po naplnění fronty), kde se kontroluje změna skladu. */
    private function advanceToCompute(StockValueTrendService $svc, int $sid, int $jobId): void
    {
        $jobs = $this->container->get(\MyInvoice\Service\Eshop\CatalogJobService::class);
        for ($i = 0; $i < 50; $i++) {
            $svc->tick($sid, 1);
            if (($jobs->find($sid, $jobId)['report']['phase'] ?? null) === 'compute') {
                return;
            }
        }
        self::fail('Úloha se nedostala do fáze výpočtu.');
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

    public function testRelevantChangeRestartsThenFailsWithReasonAndRetryStartsAgain(): void
    {
        ['sid' => $sid, 'wh' => $wh, 'root' => $root] = $this->fiveCards();
        $svc = $this->service(2);
        $jobs = $this->container->get(\MyInvoice\Service\Eshop\CatalogJobService::class);
        $job = $svc->enqueue($sid, ['2026-01-31'], null, $root, true);
        $id = (int) $job['id'];
        self::assertSame(0, $this->queueRows($sid, $id)); // fronta se plní až ve workeru, ne v požadavku
        $this->advanceToCompute($svc, $sid, $id);
        self::assertGreaterThan(0, $this->queueRows($sid, $id));
        $this->receiveStock($sid, $wh, $this->item($sid, 'S-X1'), '1', 5.0, '2026-01-11');
        $svc->tick($sid, 1); // první restart
        self::assertSame(1, $jobs->find($sid, $id)['report']['restarts']);
        $this->advanceToCompute($svc, $sid, $id);
        $this->receiveStock($sid, $wh, $this->item($sid, 'S-X2'), '1', 5.0, '2026-01-12');
        $svc->tick($sid, 1); // druhý restart
        $this->advanceToCompute($svc, $sid, $id);
        $this->receiveStock($sid, $wh, $this->item($sid, 'S-X3'), '1', 5.0, '2026-01-13');
        try {
            $svc->tick($sid, 1);
            self::fail('Čekána chyba o změně skladu.');
        } catch (StockException $e) {
            self::assertSame('stock_value_trend_stale', $e->errorCode);
            self::assertStringContainsString('změnil sklad', $e->getMessage());
        }
        $failed = $jobs->find($sid, $id);
        self::assertSame('failed', $failed['status']);
        self::assertSame('stock_value_trend_stale', $failed['error_code']);
        self::assertSame(0, $this->queueRows($sid, $id));

        // obecné opakování (retry) začne znovu s nulovými restarty a uspěje
        self::assertTrue($jobs->retry($sid, $id));
        $state = $jobs->find($sid, $id);
        for ($i = 0; $i < 200 && $state['status'] !== 'completed'; $i++) {
            $state = $svc->tick($sid, 1) ?? $state;
        }
        self::assertSame('completed', $state['status']);
        $day = $this->container->get(StockReportService::class)->valuation($sid, '2026-01-31', []);
        self::assertSame($day['totals']['value_total'], $svc->result($sid, $id, true)['totals']['2026-01-31']['value_total']);
    }

    /** Selhání (fronta se smaže, fill_cursor i checkpoint zůstanou) a opakování musí začít z čistého stavu a dát stejné součty. */
    #[\PHPUnit\Framework\Attributes\DataProvider('failurePhases')]
    public function testRetryAfterFailureStartsCleanAndSumsMatch(int $ticksBeforeFailure): void
    {
        ['sid' => $sid, 'root' => $root] = $this->fiveCards();
        $svc = $this->service(2);
        $jobs = $this->container->get(\MyInvoice\Service\Eshop\CatalogJobService::class);
        $id = (int) $svc->enqueue($sid, ['2026-01-31'], null, $root, true)['id'];
        for ($i = 0; $i < $ticksBeforeFailure; $i++) {
            $svc->tick($sid, 1);
        }
        $before = $jobs->find($sid, $id);
        if ($ticksBeforeFailure === 2) {
            self::assertGreaterThan(0, $before['report']['fill_cursor']); // selhání po první dávce plnění
        } else {
            self::assertGreaterThan(0, $before['checkpoint']); // selhání uprostřed výpočtu
        }
        $svc->dropQueue($sid, $id);
        $this->db->pdo()
            ->prepare("UPDATE catalog_jobs SET status = 'failed', error_code = 'stock_value_trend_failed', finished_at = NOW(), lease_token = NULL, lease_until = NULL WHERE id = ?")->execute([$id]);
        self::assertTrue($jobs->retry($sid, $id));
        $state = $jobs->find($sid, $id);
        for ($i = 0; $i < 300 && $state['status'] !== 'completed'; $i++) {
            $state = $svc->tick($sid, 1) ?? $state;
        }
        self::assertSame('completed', $state['status']);
        $day = $this->container->get(StockReportService::class)->valuation($sid, '2026-01-31', []);
        self::assertSame($day['totals']['value_total'], $svc->result($sid, $id, true)['totals']['2026-01-31']['value_total']);
    }

    public static function failurePhases(): array
    {
        return ['po první dávce plnění' => [2], 'uprostřed výpočtu' => [5]];
    }

    public function testMovementAfterLastDateDoesNotRestart(): void
    {
        ['sid' => $sid, 'wh' => $wh, 'root' => $root] = $this->fiveCards();
        $svc = $this->service(2);
        $jobs = $this->container->get(\MyInvoice\Service\Eshop\CatalogJobService::class);
        $id = (int) $svc->enqueue($sid, ['2026-01-31'], null, $root, true)['id'];
        $this->advanceToCompute($svc, $sid, $id);
        $this->receiveStock($sid, $wh, $this->item($sid, 'S-AFTER'), '1', 5.0, '2026-02-15'); // po datu sestavy
        $state = $jobs->find($sid, $id);
        for ($i = 0; $i < 100 && $state['status'] !== 'completed'; $i++) {
            $state = $svc->tick($sid, 1) ?? $state;
        }
        self::assertSame('completed', $state['status']);
        self::assertSame(0, $state['report']['restarts']);
        self::assertSame('150.00', $svc->result($sid, $id, true)['totals']['2026-01-31']['value_total']);
    }

    public function testWarehouseFilterIncludesTransferTargetAndLargeCardIsPagedByCursor(): void
    {
        $sid = $this->createSupplier();
        $wh = $this->warehouse($sid);
        $wh2 = $this->warehouse($sid, 'DRUHY', false);
        $root = $this->category($sid, 'W-ROOT');
        $big = $this->item($sid, 'W-BIG');
        $small = $this->item($sid, 'W-SMALL');
        $this->assignCategory($sid, $big, $root, true);
        foreach (range(1, 9) as $n) { // 9 pohybů: při stropu 2 pohyby na dávku se karta čte po stránkách s kurzorem
            $this->receiveStock($sid, $wh, $big, '2', 5.0 + $n, sprintf('2026-01-%02d', $n));
        }
        $this->receiveStock($sid, $wh, $small, '4', 3.0, '2026-01-02');
        $this->move($sid, 'transfer', $wh, $big, '5', '2026-02-05', $wh2);
        $this->move($sid, 'issue', $wh2, $big, '1', '2026-02-20');
        $dates = ['2025-12-31', '2026-01-31', '2026-02-28'];
        foreach ([null, $wh, $wh2] as $warehouse) {
            $svc = $this->service(2);
            $trend = $this->trend($sid, $dates, $warehouse, $root, true, true, $svc);
            $single = $this->container->get(StockReportService::class);
            foreach ($dates as $date) {
                $day = $single->valuation($sid, $date, $warehouse !== null ? ['warehouse_id' => $warehouse] : []);
                self::assertSame($day['totals']['value_total'], $trend['totals'][$date]['value_total'], "sklad " . var_export($warehouse, true) . " $date");
                self::assertSame($day['totals']['count'], $trend['totals'][$date]['count']);
            }
        }
        self::assertNotSame('0.00', $trend['totals']['2026-02-28']['value_total']);
    }

    public function testQueueIsKeptWhenLeaseIsLost(): void
    {
        ['sid' => $sid, 'root' => $root] = $this->fiveCards();
        $svc = $this->service(2);
        $id = (int) $svc->enqueue($sid, ['2026-01-31'], null, $root, true)['id'];
        $this->advanceToCompute($svc, $sid, $id);
        $abort = new \ReflectionMethod($svc, 'abort');
        $e = new \RuntimeException('boom');
        // úlohu už drží jiný worker (jiný token): fronta musí zůstat
        self::assertFalse($abort->invoke($svc, $sid, $id, 'cizi-token', $e));
        self::assertGreaterThan(0, $this->queueRows($sid, $id));
        // vlastní lease: úloha selže a fronta se smaže
        $token = $this->container->get(\MyInvoice\Service\Eshop\CatalogJobService::class)->claim($sid, StockValueTrendService::KIND)['lease_token'];
        self::assertTrue($abort->invoke($svc, $sid, $id, $token, $e));
        self::assertSame(0, $this->queueRows($sid, $id));
    }

    public function testCancelDropsQueueRows(): void
    {
        ['sid' => $sid, 'root' => $root] = $this->fiveCards();
        $svc = $this->service();
        $job = $svc->enqueue($sid, ['2026-01-31'], null, $root, true);
        $this->advanceToCompute($svc, $sid, (int) $job['id']);
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
        $this->advanceToCompute($svc, $sid, (int) $job['id']);
        $svc->tick($sid, 1); // dvě karty započítané pod kategorii A
        $this->db->pdo()->prepare('UPDATE stock_categories SET parent_id = NULL, path = ? WHERE supplier_id = ? AND id = ?')
            ->execute(['/' . $a . '/', $sid, $a]);
        $state = $svc->tick($sid, 50);
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
        $svc = $this->service();
        $job = $svc->enqueue($sid, ['2026-01-31'], null, $root, true);
        $this->advanceToCompute($svc, $sid, (int) $job['id']);
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

    public function testReadOnlyUserCancelsOnlyOwnJob(): void
    {
        ['sid' => $sid, 'root' => $root] = $this->fiveCards();
        $svc = $this->service();
        $mine = $svc->enqueue($sid, ['2026-01-31'], null, $root, true, $this->userId);
        $foreign = $svc->enqueue($sid, ['2026-02-28'], null, $root, true, $this->userId + 1000000);
        $action = $this->container->get(\MyInvoice\Action\Stock\StockReportAction::class);
        $cancel = fn (int $id) => $action->cancelValueTrendJob(
            (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', "/api/stock/reports/value-trend-jobs/$id/cancel")
                ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD, 'session')
                ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $sid)
                ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'readonly']),
            new \Slim\Psr7\Response(), ['id' => $id]);
        self::assertSame(403, $cancel((int) $foreign['id'])->getStatusCode());
        self::assertSame(200, $cancel((int) $mine['id'])->getStatusCode());
    }
}
