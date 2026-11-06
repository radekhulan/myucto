<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Action\Eshop\CatalogJobAction;
use MyInvoice\Action\Stock\StockHistoryImportAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Eshop\CatalogJobService;
use MyInvoice\Service\Eshop\EshopException;
use MyInvoice\Service\Eshop\Import\CatalogImportSourceStore;
use MyInvoice\Service\Stock\CycleCountService;
use MyInvoice\Service\Stock\InTransitService;
use MyInvoice\Service\Stock\StockException;
use MyInvoice\Service\Stock\StockHistoryImportService;
use MyInvoice\Service\Stock\StockHistoryImportWorker;
use MyInvoice\Service\Stock\StockReportService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Psr7\UploadedFile;

/**
 * Životní cyklus importu historie skladu: souběh s živými doklady, opakované a zastaralé
 * úlohy, osiřelá historie a shoda živého stavu s firmou bez importu.
 */
final class StockHistoryImportLifecycleTest extends StockTestCase
{
    private const HEADER = 'doklad;typ;datum;sklad;sklad_cil;sku;mnozstvi;cena;partner;faktura_vydana;faktura_prijata;poznamka';

    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    /** Dvě shodné firmy, jedna s importem historie: stavy a ocenění od D se shodují i po vrácení. */
    public function testCutoverImportKeepsLiveStateEqualToControlCompany(): void
    {
        $build = function (): array {
            $sid = $this->createSupplier();
            $hl = $this->warehouse($sid, 'HL');
            $pob = $this->warehouse($sid, 'POB', false);
            $a = $this->item($sid, 'ZB-100');
            $b = $this->item($sid, 'ZB-200');
            $this->receiveStock($sid, $hl, $a, '10', 100.0, '2021-01-01');
            $this->receiveStock($sid, $hl, $b, '7', 33.33, '2021-01-01');
            $this->post($sid, 'issue', $hl, null, '2021-02-01', [[$a, '3']]);
            $this->post($sid, 'transfer', $hl, $pob, '2021-02-15', [[$a, '2'], [$b, '1']]);
            $this->post($sid, 'issue', $hl, null, '2021-03-01', [[$b, '2.5']]);
            return [$sid, [$hl, $pob], [$a, $b]];
        };
        [$control, $controlWarehouses, $controlItems] = $build();
        [$sid, $warehouses, $items] = $build();
        self::assertSame($this->fingerprint($control, $controlWarehouses, $controlItems), $this->fingerprint($sid, $warehouses, $items));

        $csv = self::csv([
            'P1;prijemka;2020-01-05;HL;;ZB-100;10;50.123;;;;',
            'P1;prijemka;2020-01-05;HL;;ZB-200;3;7;;;;',
            'V1;vydejka;2020-02-01;HL;;ZB-100;4;;;;;',
            'T1;prevodka;2020-03-01;HL;POB;ZB-100;3;;;;;',
            'T1;prevodka;2020-03-01;HL;POB;ZB-200;5;;;;;',
            'V2;vydejka;2020-04-01;POB;;ZB-200;5;;;;;',
            'P2;prijemka;2020-05-01;HL;;ZB-100;1;77.77;;;;',
            'V3;vydejka;2020-12-31;HL;;ZB-100;1;;;;;',
            'I1;prebytek;2020-12-31;POB;;ZB-100;0.333;12.5;;;;',
        ]);
        $preview = $this->stage($sid, $csv, $this->config('2021-01-01', ['fill_negative' => true]));
        self::assertSame('completed', $preview['status']);
        $service = $this->container->get(StockHistoryImportService::class);
        $job = $service->apply($sid, $preview['id'], $this->userId);
        $this->container->get(StockHistoryImportWorker::class)->tickKind($sid, StockHistoryImportService::APPLY_KIND, 1);
        // Zpětný živý doklad během importu (historie zapsaná, ještě neoceněná).
        $this->post($sid, 'issue', $warehouses[0], null, '2021-01-20', [[$items[1], '1']]);
        $this->post($control, 'issue', $controlWarehouses[0], null, '2021-01-20', [[$controlItems[1], '1']]);
        $applied = $this->drain($sid, StockHistoryImportService::APPLY_KIND, $job['id']);
        self::assertSame('completed', $applied['status']);
        self::assertSame($this->fingerprint($control, $controlWarehouses, $controlItems), $this->fingerprint($sid, $warehouses, $items));
        self::assertSame([], $this->container->get(StockReportService::class)->valuation($sid, '2020-12-31', [])['items']);
        self::assertNotSame([], $this->container->get(StockReportService::class)->valuation($sid, '2020-06-30', [])['items']);

        $this->post($sid, 'issue', $warehouses[0], null, '2021-03-05', [[$items[0], '1']]);
        $this->post($control, 'issue', $controlWarehouses[0], null, '2021-03-05', [[$controlItems[0], '1']]);
        self::assertSame($this->fingerprint($control, $controlWarehouses, $controlItems), $this->fingerprint($sid, $warehouses, $items));

        $rollback = $service->rollback($sid, $applied['id'], $this->userId);
        self::assertSame('completed', $this->drain($sid, StockHistoryImportService::ROLLBACK_KIND, $rollback['id'])['status']);
        self::assertSame($this->fingerprint($control, $controlWarehouses, $controlItems), $this->fingerprint($sid, $warehouses, $items));
        self::assertSame(0, $this->historyCount($sid));
    }

    /** Zastaralé vrácení (zrušené, pak spuštěné znovu po novém importu) nesmí sáhnout na nový import. */
    public function testRetriedStaleRollbackCannotTouchNewerImport(): void
    {
        $service = $this->container->get(StockHistoryImportService::class);
        $jobs = $this->container->get(CatalogJobService::class);
        $sid = $this->createSupplier();
        $main = $this->warehouse($sid, 'HL');
        $a = $this->item($sid, 'ZB-100');

        $applied = $this->applyAll($sid, $this->stage($sid, self::csv(['P1;receipt;2020-01-05;HL;;ZB-100;10;50;;;;']), $this->config(null))['id']);
        $stale = $service->rollback($sid, $applied['id'], $this->userId);
        self::assertTrue($jobs->cancel($sid, $stale['id']));
        $this->db->pdo()->prepare('UPDATE supplier SET stock_history_locked_until = ? WHERE id = ?')->execute(['2020-01-05', $sid]);
        $rollback = $service->rollback($sid, $applied['id'], $this->userId);
        self::assertSame('completed', $this->drain($sid, StockHistoryImportService::ROLLBACK_KIND, $rollback['id'])['status']);
        $newer = $this->applyAll($sid, $this->stage($sid, self::csv(['P9;receipt;2020-01-05;HL;;ZB-100;4;10;;;;']), $this->config(null))['id']);
        self::assertSame('completed', $newer['status']);

        self::assertSame(409, $this->retryThroughAction($sid, $stale['id']));
        self::assertTrue($jobs->retry($sid, $stale['id']));
        $this->expectStale(fn () => $this->drain($sid, StockHistoryImportService::ROLLBACK_KIND, $stale['id']));
        self::assertSame('failed', $jobs->find($sid, $stale['id'])['status']);
        self::assertSame(['qtyT' => 4000, 'valueC' => 4000], $this->level($sid, $main, $a));
        self::assertSame(1, $this->historyCount($sid));
        self::assertSame('2020-01-05', $this->lockedUntil($sid));
    }

    /** Zrušená aplikace po vrácení nejde dopsat: zbytek historie by vznikl bez zámku. */
    public function testRetriedApplyAfterRollbackWritesNothing(): void
    {
        $service = $this->container->get(StockHistoryImportService::class);
        $jobs = $this->container->get(CatalogJobService::class);
        $worker = $this->container->get(StockHistoryImportWorker::class);
        $sid = $this->createSupplier();
        $this->warehouse($sid, 'HL');
        $this->item($sid, 'ZB-100');
        $rows = [];
        for ($i = 1; $i <= 600; $i++) {
            $rows[] = sprintf('P%04d;receipt;2020-01-%02d;HL;;ZB-100;1;10;;;;', $i, 1 + intdiv($i, 30));
        }
        $job = $service->apply($sid, $this->stage($sid, self::csv($rows), $this->config(null))['id'], $this->userId);
        $worker->tickKind($sid, StockHistoryImportService::APPLY_KIND, 1);
        self::assertTrue($jobs->cancel($sid, $job['id']));
        $worker->tickKind($sid, StockHistoryImportService::APPLY_KIND, 1);
        self::assertSame('cancelled', $jobs->find($sid, $job['id'])['status']);
        $rollback = $service->rollback($sid, $job['id'], $this->userId);
        self::assertSame('completed', $this->drain($sid, StockHistoryImportService::ROLLBACK_KIND, $rollback['id'])['status']);
        self::assertNull($this->lockedUntil($sid));

        self::assertSame(409, $this->retryThroughAction($sid, $job['id']));
        self::assertTrue($jobs->retry($sid, $job['id']));
        $this->expectStale(fn () => $this->drain($sid, StockHistoryImportService::APPLY_KIND, $job['id']));
        self::assertSame(0, $this->historyCount($sid));
        self::assertNull($this->lockedUntil($sid));
    }

    /** Bez data přechodu: během aplikace i vrácení nejde zaúčtovat živý doklad (žádná slepá ulička). */
    public function testLiveModeLocksWholeStockWhileApplyingAndRollingBack(): void
    {
        $service = $this->container->get(StockHistoryImportService::class);
        $worker = $this->container->get(StockHistoryImportWorker::class);
        $sid = $this->createSupplier();
        $main = $this->warehouse($sid, 'HL');
        $a = $this->item($sid, 'ZB-100');
        $preview = $this->stage($sid, self::csv(['P1;receipt;2020-01-05;HL;;ZB-100;10;50;;;;', 'V1;issue;2020-02-01;HL;;ZB-100;3;;;;;']), $this->config(null));
        $job = $service->apply($sid, $preview['id'], $this->userId);
        $worker->tickKind($sid, StockHistoryImportService::APPLY_KIND, 1);
        $this->expectLocked(fn () => $this->receiveStock($sid, $main, $a, '1', 5.0, '2021-05-01'));
        $applied = $this->drain($sid, StockHistoryImportService::APPLY_KIND, $job['id']);
        self::assertSame('completed', $applied['status']);
        self::assertSame('2020-02-01', $this->lockedUntil($sid));

        $rollback = $service->rollback($sid, $applied['id'], $this->userId);
        $worker->tickKind($sid, StockHistoryImportService::ROLLBACK_KIND, 1);
        $this->expectLocked(fn () => $this->post($sid, 'issue', $main, null, '2021-05-01', [[$a, '4']]));
        self::assertSame('completed', $this->drain($sid, StockHistoryImportService::ROLLBACK_KIND, $rollback['id'])['status']);
        self::assertSame(['qtyT' => 0, 'valueC' => 0], $this->level($sid, $main, $a));
        self::assertNull($this->lockedUntil($sid));
        $this->receiveStock($sid, $main, $a, '1', 5.0, '2021-05-01');
    }

    /**
     * Historie bez úlohy (obnova z archivu nepřenáší catalog_jobs): další import odmítne,
     * správce ji vrátí podle převzatých dokladů a živý stav zůstane.
     */
    public function testOrphanHistoryBlocksNewImportAndCanBeRolledBack(): void
    {
        $service = $this->container->get(StockHistoryImportService::class);
        $sid = $this->createSupplier();
        $main = $this->warehouse($sid, 'HL');
        $a = $this->item($sid, 'ZB-100');
        $this->receiveStock($sid, $main, $a, '10', 100.0, '2021-01-01');
        $level = $this->level($sid, $main, $a);
        $applied = $this->applyAll($sid, $this->stage($sid, self::csv(['P1;prijemka;2020-01-05;HL;;ZB-100;5;50;;;;']), $this->config('2021-01-01'))['id']);
        $this->db->pdo()->prepare('DELETE FROM catalog_job_items WHERE job_id = ?')->execute([$applied['id']]);
        $this->db->pdo()->prepare('DELETE FROM catalog_jobs WHERE id = ?')->execute([$applied['id']]);

        self::assertNull($service->active($sid));
        self::assertSame($applied['id'], $service->orphan($sid));
        try {
            $this->stage($sid, self::csv(['Q1;prijemka;2020-03-05;HL;;ZB-100;2;10;;;;']), $this->config('2020-06-01'));
            self::fail('Druhý import nad osiřelou historií musí selhat.');
        } catch (EshopException $e) {
            self::assertSame('history_import_active', $e->errorCode);
        }

        $rollback = $service->rollback($sid, $applied['id'], $this->userId);
        self::assertSame('2021-01-01', $rollback['input']['cutover_date']);
        self::assertSame('completed', $this->drain($sid, StockHistoryImportService::ROLLBACK_KIND, $rollback['id'])['status']);
        self::assertSame(0, $this->historyCount($sid));
        self::assertNull($this->lockedUntil($sid));
        self::assertSame($level, $this->level($sid, $main, $a));
        self::assertNull($service->orphan($sid));
    }

    /** Vrácení převzatých dokladů v uzavřeném období chce stejné potvrzení jako jejich převzetí. */
    public function testRollbackInClosedPeriodNeedsConfirmation(): void
    {
        $service = $this->container->get(StockHistoryImportService::class);
        $sid = $this->createSupplier('double_entry');
        $this->warehouse($sid, 'HL');
        $this->item($sid, 'ZB-100');
        $this->db->pdo()->prepare("INSERT INTO accounting_periods (supplier_id, fiscal_year, starts_on, ends_on, status)
            VALUES (?, 2020, '2020-01-01', '2020-12-31', 'closed')")->execute([$sid]);
        $preview = $this->stage($sid, self::csv(['P1;receipt;2020-01-05;HL;;ZB-100;1;10;;;;']), $this->config(null));
        $applied = $service->apply($sid, $preview['id'], $this->userId, true);
        $applied = $this->drain($sid, StockHistoryImportService::APPLY_KIND, $applied['id']);
        try {
            $service->rollback($sid, $applied['id'], $this->userId);
            self::fail('Vrácení v uzavřeném období bez potvrzení musí selhat.');
        } catch (EshopException $e) {
            self::assertSame('history_import_closed_periods_unconfirmed', $e->errorCode);
        }
        $rollback = $service->rollback($sid, $applied['id'], $this->userId, true);
        self::assertSame('completed', $this->drain($sid, StockHistoryImportService::ROLLBACK_KIND, $rollback['id'])['status']);
    }

    /** Vyrovnávací výdejky a technické příjemky mají jednoznačná čísla i u dlouhých kódů a čísel. */
    public function testGeneratedNumbersStayUniqueForLongCodesAndNumbers(): void
    {
        $sid = $this->createSupplier();
        $first = $this->warehouse($sid, 'SKLAD-PRODEJNA-01A');
        $second = $this->warehouse($sid, 'SKLAD-PRODEJNA-01B', false);
        $this->item($sid, 'ZB-100');
        $long = str_repeat('X', 28);
        $csv = self::csv([
            "{$long}-1;vydejka;2020-01-05;SKLAD-PRODEJNA-01A;;ZB-100;2;;;;;",
            "{$long}-2;vydejka;2020-01-06;SKLAD-PRODEJNA-01A;;ZB-100;1;;;;;",
            'P1;prijemka;2020-02-01;SKLAD-PRODEJNA-01A;;ZB-100;10;20;;;;',
            'P2;prijemka;2020-02-01;SKLAD-PRODEJNA-01B;;ZB-100;5;20;;;;',
        ]);
        $preview = $this->stage($sid, $csv, $this->config('2021-01-01', ['fill_negative' => true]));
        self::assertSame(0, $preview['report']['counts']['failed'] ?? 0);
        $applied = $this->applyAll($sid, $preview['id']);
        self::assertSame('completed', $applied['status']);
        $stmt = $this->db->pdo()->prepare('SELECT doc_number FROM stock_documents WHERE supplier_id = ? AND history_import_job_id IS NOT NULL ORDER BY id');
        $stmt->execute([$sid]);
        self::assertSame([
            StockHistoryImportService::fillNumber("{$long}-1", 2), "{$long}-1",
            StockHistoryImportService::fillNumber("{$long}-2", 3), "{$long}-2",
            'P1', 'P2',
            "UH-{$first}-2020-12-31", "UH-{$second}-2020-12-31",
        ], $stmt->fetchAll(\PDO::FETCH_COLUMN));
        self::assertSame(str_repeat('X', 26) . '-TP2', StockHistoryImportService::fillNumber("{$long}-1", 2));
    }

    /** Náhled odmítne doklad, jehož číslo si vezme technická příjemka nebo vyrovnávací výdejka. */
    public function testPreviewRejectsNumbersReservedForGeneratedDocuments(): void
    {
        $sid = $this->createSupplier();
        $main = $this->warehouse($sid, 'HL');
        $this->item($sid, 'ZB-100');
        $fill = StockHistoryImportService::fillNumber('V1', 2);
        $preview = $this->stage($sid, self::csv([
            'V1;vydejka;2020-01-05;HL;;ZB-100;2;;;;;',
            "{$fill};prijemka;2020-02-01;HL;;ZB-100;10;20;;;;",
            "UH-{$main}-2020-12-31;prijemka;2020-02-01;HL;;ZB-100;1;20;;;;",
            'P3;prijemka;2020-02-01;HL;;ZB-100;1;20;;;;',
        ]), $this->config('2021-01-01', ['fill_negative' => true]));
        self::assertSame([
            [3, 'failed', 'history_import_number_reserved'],
            [4, 'failed', 'history_import_number_reserved'],
        ], $this->failedRows($preview['id']));

        // Technická příjemka by kolidovala s dokladem, který už ve firmě je.
        $this->db->pdo()->prepare("INSERT INTO stock_documents (supplier_id, doc_type, origin, warehouse_id, doc_number, doc_date, status, created_by, description)
            VALUES (?, 'receipt', 'manual', ?, ?, '2020-01-01', 'draft', ?, '')")->execute([$sid, $main, $fill, $this->userId]);
        $preview = $this->stage($sid, self::csv(['V1;vydejka;2020-01-05;HL;;ZB-100;2;;;;;']), $this->config('2021-01-01', ['fill_negative' => true]));
        self::assertSame([[2, 'failed', 'history_import_number_exists']], $this->failedRows($preview['id']));

        // Vyrovnávací výdejka by kolidovala s dokladem firmy: převzetí se vůbec nespustí.
        $this->db->pdo()->prepare('UPDATE stock_documents SET doc_number = ? WHERE supplier_id = ? AND doc_number = ?')
            ->execute(["UH-{$main}-2020-12-31", $sid, $fill]);
        $preview = $this->stage($sid, self::csv(['P1;prijemka;2020-01-05;HL;;ZB-100;2;10;;;;']), $this->config('2021-01-01'));
        try {
            $this->container->get(StockHistoryImportService::class)->apply($sid, $preview['id'], $this->userId);
            self::fail('Kolize čísla vyrovnávací výdejky musí selhat před převzetím.');
        } catch (StockException $e) {
            self::assertSame('history_import_number_exists', $e->errorCode);
        }
        self::assertNull($this->lockedUntil($sid));
    }

    /** Rozpracovaná inventura (i cyklická) zastaví náhled, převzetí i vrácení importu. */
    public function testOpenStockTakeBlocksImportAndRollback(): void
    {
        $service = $this->container->get(StockHistoryImportService::class);
        $sid = $this->createSupplier();
        $main = $this->warehouse($sid, 'HL');
        $this->item($sid, 'ZB-100');
        $csv = self::csv(['P1;prijemka;2020-01-05;HL;;ZB-100;2;10;;;;']);
        $preview = $this->stage($sid, $csv, $this->config(null));
        foreach (['stock_takes' => 'counting', 'stock_cycle_counts' => 'running'] as $table => $status) {
            $this->db->pdo()->prepare("INSERT INTO {$table} (supplier_id, warehouse_id, take_date, status) VALUES (?, ?, '2021-01-01', ?)")
                ->execute([$sid, $main, $status]);
            $this->expectOpenStockTake(fn () => $this->stage($sid, $csv, $this->config(null)));
            $this->expectOpenStockTake(fn () => $service->apply($sid, $preview['id'], $this->userId));
            $this->db->pdo()->prepare("UPDATE {$table} SET status = 'closed' WHERE supplier_id = ?")->execute([$sid]);
        }
        $applied = $this->applyAll($sid, $preview['id']);
        $this->db->pdo()->prepare("INSERT INTO stock_takes (supplier_id, warehouse_id, take_date, status) VALUES (?, ?, '2021-02-01', 'preparing')")
            ->execute([$sid, $main]);
        $this->expectOpenStockTake(fn () => $service->rollback($sid, $applied['id'], $this->userId));
    }

    /** Koncový stav historie po skladech a kartách proti počátečnímu stavu v MyÚčtu k datu přechodu, i jako XLSX. */
    public function testPreviewEndStateComparesWithStockAtCutover(): void
    {
        $sid = $this->createSupplier();
        $main = $this->warehouse($sid, 'HL');
        $branch = $this->warehouse($sid, 'POB', false);
        $a = $this->item($sid, 'ZB-100');
        $b = $this->item($sid, 'ZB-200');
        $this->receiveStock($sid, $main, $a, '10', 100.0, '2021-01-01');
        $this->receiveStock($sid, $branch, $b, '5', 10.0, '2021-01-01');
        $preview = $this->stage($sid, self::csv([
            'P1;prijemka;2020-01-05;HL;;ZB-100;12;50;;;;',
            'V1;vydejka;2020-02-01;HL;;ZB-100;1;;;;;',
            'T1;prevodka;2020-03-01;HL;POB;ZB-100;1;;;;;',
            'P2;prijemka;2020-04-01;POB;;ZB-200;4;10;;;;',
        ]), $this->config('2021-01-01'));
        $state = $this->container->get(StockHistoryImportService::class)->endState($sid, $preview['id']);
        self::assertSame('2021-01-01', $state['cutover_date']);
        self::assertSame(['count' => 3, 'differences' => 2], $state['totals']);
        self::assertSame([
            ['HL', 'ZB-100', '10.000', '10.000', '0.000'],
            ['POB', 'ZB-100', '1.000', '0.000', '1.000'],
            ['POB', 'ZB-200', '4.000', '5.000', '-1.000'],
        ], array_map(static fn (array $row): array => [$row['warehouse_code'], $row['sku'], $row['history_qty'], $row['stock_qty'], $row['difference']], $state['items']));

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/stock/history-import/jobs/' . $preview['id'] . '/end-state?format=xlsx')
            ->withQueryParams(['format' => 'xlsx'])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $sid)
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
        $response = $this->container->get(StockHistoryImportAction::class)->endState($request, new Response(), ['id' => $preview['id']]);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('spreadsheetml', $response->getHeaderLine('Content-Type'));
        $path = tempnam(sys_get_temp_dir(), 'history-end-state-');
        $this->files[] = $path;
        file_put_contents($path, (string) $response->getBody());
        $sheet = IOFactory::load($path)->getActiveSheet();
        self::assertSame(['POB', 'ZB-200', 4.0, 5.0, -1.0], [
            $sheet->getCell('A6')->getValue(), $sheet->getCell('B6')->getValue(),
            $sheet->getCell('E6')->getValue(), $sheet->getCell('F6')->getValue(), $sheet->getCell('G6')->getValue(),
        ]);
        self::assertSame('CELKEM (3 položek, s rozdílem 2)', $sheet->getCell('A7')->getValue());
    }

    /** Bez data přechodu nejde během převzetí spustit inventuru; dokončení převzetí ji hlídá znovu. */
    public function testLiveImportBlocksStockTakesUntilFinished(): void
    {
        $service = $this->container->get(StockHistoryImportService::class);
        $jobs = $this->container->get(CatalogJobService::class);
        $sid = $this->createSupplier();
        $main = $this->warehouse($sid, 'HL');
        $a = $this->item($sid, 'ZB-100');
        $take = $this->takes->create($sid, ['warehouse_id' => $main, 'take_date' => date('Y-m-d'), 'counting_method' => 'physical_count',
            'responsible_count_name' => 'A', 'responsible_inventory_name' => 'B'], $this->userId);
        $job = $service->apply($sid, $this->stage($sid, self::csv(['P1;receipt;2020-01-05;HL;;ZB-100;10;50;;;;']), $this->config(null))['id'], $this->userId);

        // Inventura spuštěná teď by čekala nulový stav a po převzetí by přebytek zásobu zdvojil.
        $this->expectLocked(fn () => $this->takes->start($sid, (int) $take['id'], $this->userId));
        $this->expectLocked(fn () => $this->takes->prepare($sid, (int) $take['id'], $this->userId));
        $this->expectLocked(fn () => $this->container->get(CycleCountService::class)->create($sid, ['warehouse_id' => $main], $this->userId));
        self::assertSame('draft', $this->takes->get($sid, (int) $take['id'])['status']);

        // Inventura, která se ke spuštění dostala jinou cestou, převzetí nedokončí.
        $this->db->pdo()->prepare("UPDATE stock_takes SET status = 'counting' WHERE id = ?")->execute([$take['id']]);
        $this->expectOpenStockTake(fn () => $this->drain($sid, StockHistoryImportService::APPLY_KIND, $job['id']));
        self::assertSame('failed', $jobs->find($sid, $job['id'])['status']);
        self::assertSame(StockHistoryImportService::LOCK_RUNNING, $this->lockedUntil($sid));

        $this->db->pdo()->prepare("UPDATE stock_takes SET status = 'draft' WHERE id = ?")->execute([$take['id']]);
        self::assertSame(200, $this->changeThroughAction($sid, $job['id'], 'retry')->getStatusCode());
        self::assertSame('completed', $this->drain($sid, StockHistoryImportService::APPLY_KIND, $job['id'])['status']);
        self::assertSame('counting', $this->takes->start($sid, (int) $take['id'], $this->userId)['status']);
        self::assertSame(['qtyT' => 10000, 'valueC' => 50000], $this->level($sid, $main, $a));
    }

    /** Koncový stav se porovnává s počátečním stavem k D, prodej v den přechodu rozdíl nedělá. */
    public function testEndStateIgnoresOtherMovementsOnCutoverDay(): void
    {
        $sid = $this->createSupplier();
        $main = $this->warehouse($sid, 'HL');
        $a = $this->item($sid, 'ZB-100');
        $this->receiveStock($sid, $main, $a, '10', 100.0, '2021-01-01');
        $this->post($sid, 'issue', $main, null, '2021-01-01', [[$a, '3']]);
        $this->post($sid, 'issue', $main, null, '2021-01-02', [[$a, '1']]);
        $preview = $this->stage($sid, self::csv(['P1;prijemka;2020-01-05;HL;;ZB-100;10;50;;;;']), $this->config('2021-01-01'));
        $state = $this->container->get(StockHistoryImportService::class)->endState($sid, $preview['id']);
        self::assertSame(['count' => 1, 'differences' => 0], $state['totals']);
        self::assertSame(['10.000', '10.000', '0.000'], [$state['items'][0]['history_qty'], $state['items'][0]['stock_qty'], $state['items'][0]['difference']]);
    }

    /** Převzetí s D přerušené obnovou z archivu (doklady bez vyrovnání): historie jde vrátit, živý stav zůstane. */
    public function testOrphanFromInterruptedCutoverApplyCanBeRolledBack(): void
    {
        $service = $this->container->get(StockHistoryImportService::class);
        $sid = $this->createSupplier();
        $main = $this->warehouse($sid, 'HL');
        $a = $this->item($sid, 'ZB-100');
        $this->receiveStock($sid, $main, $a, '10', 100.0, '2021-01-01');
        $level = $this->level($sid, $main, $a);
        $job = $service->apply($sid, $this->stage($sid, self::csv(['P1;prijemka;2020-01-05;HL;;ZB-100;5;50;;;;']), $this->config('2021-01-01'))['id'], $this->userId);
        $this->container->get(StockHistoryImportWorker::class)->tickKind($sid, StockHistoryImportService::APPLY_KIND, 1);
        $this->forgetJobs($sid);

        $rollback = $service->rollback($sid, (int) $service->orphan($sid), $this->userId);
        self::assertSame('2021-01-01', $rollback['input']['cutover_date']);
        self::assertSame('completed', $this->drain($sid, StockHistoryImportService::ROLLBACK_KIND, $rollback['id'])['status']);
        self::assertSame(0, $this->historyCount($sid));
        self::assertNull($this->lockedUntil($sid));
        self::assertSame($level, $this->level($sid, $main, $a));
    }

    /** Osiřelá historie přerušeného převzetí bez D: hranice je poslední převzatý den, ne zámek 9999-12-31. */
    public function testOrphanUnderRunningLockUsesLastHistoryDay(): void
    {
        $service = $this->container->get(StockHistoryImportService::class);
        $sid = $this->createSupplier();
        $main = $this->warehouse($sid, 'HL');
        $a = $this->item($sid, 'ZB-100');
        $job = $service->apply($sid, $this->stage($sid, self::csv(['P1;receipt;2020-01-05;HL;;ZB-100;5;50;;;;', 'V1;issue;2020-02-01;HL;;ZB-100;5;;;;;']), $this->config(null))['id'], $this->userId);
        $this->container->get(StockHistoryImportWorker::class)->tickKind($sid, StockHistoryImportService::APPLY_KIND, 1);
        $this->forgetJobs($sid);
        $this->db->pdo()->prepare("INSERT INTO stock_valuation_snapshots (supplier_id, warehouse_id, stock_item_id, cutoff_date, qty, value_total, source_version)
            VALUES (?, ?, ?, '2020-01-31', 5, 250, 1)")->execute([$sid, $main, $a]);

        $rollback = $service->rollback($sid, (int) $service->orphan($sid), $this->userId);
        self::assertSame('2020-02-02', $rollback['input']['cutover_date']);
        self::assertSame('completed', $this->drain($sid, StockHistoryImportService::ROLLBACK_KIND, $rollback['id'])['status']);
        self::assertSame(0, $this->historyCount($sid));
        self::assertNull($this->lockedUntil($sid));
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM stock_valuation_snapshots WHERE supplier_id = ?');
        $stmt->execute([$sid]);
        self::assertSame(0, (int) $stmt->fetchColumn());
    }

    /** Poslední převzetí zastavené nebo přerušené chybou jde dokončit opakováním, zrušit ho smí jen správce. */
    public function testCurrentApplyCanBeRetriedAndOnlyAdminCancelsIt(): void
    {
        $service = $this->container->get(StockHistoryImportService::class);
        $jobs = $this->container->get(CatalogJobService::class);
        $worker = $this->container->get(StockHistoryImportWorker::class);
        $sid = $this->createSupplier();
        $main = $this->warehouse($sid, 'HL');
        $a = $this->item($sid, 'ZB-100');
        $preview = $this->stage($sid, self::csv(['P1;receipt;2020-01-05;HL;;ZB-100;10;50;;;;']), $this->config(null));
        $job = $service->apply($sid, $preview['id'], $this->userId);

        // Zrušení převzetí bez D nechá zamčený celý sklad: rozhoduje o něm správce jako o vrácení.
        $response = $this->changeThroughAction($sid, $job['id'], 'cancel', 'accountant');
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('forbidden', json_decode((string) $response->getBody(), true)['error']['code']);
        self::assertSame('queued', $jobs->find($sid, $job['id'])['status']);

        $worker->tickKind($sid, StockHistoryImportService::APPLY_KIND, 1);
        self::assertSame(200, $this->changeThroughAction($sid, $job['id'], 'cancel')->getStatusCode());
        $worker->tickKind($sid, StockHistoryImportService::APPLY_KIND, 1);
        self::assertSame('cancelled', $jobs->find($sid, $job['id'])['status']);

        self::assertSame(200, $this->changeThroughAction($sid, $job['id'], 'retry', 'accountant')->getStatusCode());
        self::assertSame('completed', $this->drain($sid, StockHistoryImportService::APPLY_KIND, $job['id'])['status']);
        self::assertSame(['qtyT' => 10000, 'valueC' => 50000], $this->level($sid, $main, $a));
        self::assertSame('2020-01-05', $this->lockedUntil($sid));
        self::assertSame(1, $this->historyCount($sid));
    }

    /** Bez data přechodu musí historie skončit nejpozději včera, jinak by zamkla i dnešní doklady. */
    public function testLiveHistoryCannotEndToday(): void
    {
        $sid = $this->createSupplier();
        $this->warehouse($sid, 'HL');
        $this->item($sid, 'ZB-100');
        $today = date('Y-m-d');
        $preview = $this->stage($sid, self::csv(['P1;receipt;2020-01-05;HL;;ZB-100;10;50;;;;', "P2;receipt;{$today};HL;;ZB-100;1;50;;;;"]), $this->config(null));
        self::assertSame([[3, 'failed', 'history_import_doc_date_not_past']], $this->failedRows($preview['id']));
    }

    /** Storno faktury převzatou výdejku nestornuje a obnovení faktury zboží podruhé nevydá. */
    public function testInvoiceCancelAndRestoreKeepHistoryIssue(): void
    {
        $sid = $this->createSupplier();
        $main = $this->warehouse($sid, 'HL');
        $a = $this->item($sid, 'ZB-100');
        $invoiceId = $this->invoiceDraft($sid, $this->client($sid), 'invoice', ['issue_date' => '2020-03-01', 'varsymbol' => '2020001']);
        $this->invoiceItem($invoiceId, $a, $main, '4.000');
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'issued' WHERE id = ?")->execute([$invoiceId]);
        $applied = $this->applyAll($sid, $this->stage($sid, self::csv([
            'P1;receipt;2020-01-05;HL;;ZB-100;10;50;;;;',
            'V1;issue;2020-02-01;HL;;ZB-100;4;;;2020001;;',
        ]), $this->config(null))['id']);
        self::assertSame('completed', $applied['status']);
        $documents = fn (): array => array_map(
            static fn (array $doc): array => [$doc['doc_number'], $doc['status']],
            $this->docsRepo->listByInvoice($sid, $invoiceId),
        );
        self::assertSame([['V1', 'posted']], $documents());

        $this->inTransaction(fn () => $this->issue->reverseForInvoice($sid, $invoiceId, $this->userId));
        self::assertSame([['V1', 'posted']], $documents());
        self::assertSame(6000, $this->level($sid, $main, $a)['qtyT']);

        $this->inTransaction(fn () => $this->issue->issueForInvoice($sid, [
            'id' => $invoiceId, 'supplier_id' => $sid, 'invoice_type' => 'invoice', 'parent_invoice_id' => null,
            'issue_date' => '2020-03-01', 'tax_date' => '2020-03-01', 'varsymbol' => '2020001',
        ], $this->userId));
        self::assertSame([['V1', 'posted']], $documents());
        self::assertSame(6000, $this->level($sid, $main, $a)['qtyT']);
    }

    /**
     * Převzatý doklad se naváže jen na vystavenou fakturu nebo dobropis bez vlastního skladového
     * dokladu. Jinak se převezme bez vazby a náhled i převzetí to vypíšou; živé faktury dál vydávají.
     */
    public function testHistoryDocumentLinksOnlyToIssuedInvoiceWithoutOwnStock(): void
    {
        $sid = $this->createSupplier();
        $main = $this->warehouse($sid, 'HL');
        $a = $this->item($sid, 'ZB-100');
        $this->receiveStock($sid, $main, $a, '10', 100.0, '2021-01-01');
        $live = $this->issuedInvoice($sid, 'invoice', '2025001', '2021-02-10', $main, $a, '2.000');
        $this->inTransaction(fn () => $this->issue->issueForInvoice($sid, $live, $this->userId));
        $draft = $this->issuedInvoice($sid, 'invoice', '2025002', '2021-02-11', $main, $a, '2.000', 'draft');
        $this->issuedInvoice($sid, 'proforma', '2025003', '2020-03-01', $main, $a, '1.000');
        $later = $this->issuedInvoice($sid, 'invoice', '2025004', '2021-03-01', $main, $a, '1.000');
        $old = $this->issuedInvoice($sid, 'invoice', '2025005', '2020-03-01', null, null, '1.000');
        self::assertSame(8000, $this->level($sid, $main, $a)['qtyT']);

        $preview = $this->stage($sid, self::csv([
            'P1;receipt;2020-01-05;HL;;ZB-100;15;50;;;;',
            'V1;issue;2020-06-01;HL;;ZB-100;5;;;2025001;;',
            'V2;issue;2020-06-02;HL;;ZB-100;1;;;2025002;;',
            'V3;issue;2020-06-03;HL;;ZB-100;1;;;2025003;;',
            'V4;issue;2020-06-04;HL;;ZB-100;1;;;2025004;;',
            'V5;issue;2020-06-05;HL;;ZB-100;1;;;2025005;;',
        ]), $this->config('2021-01-01'));
        self::assertSame('completed', $preview['status']);
        self::assertSame([], $this->failedRows($preview['id']));
        self::assertSame(3, $preview['report']['invoice_unlinked_count']);
        self::assertSame([
            [3, 'V1', '2025001', 'own_stock'],
            [4, 'V2', '2025002', 'draft'],
            [5, 'V3', '2025003', 'type'],
        ], array_map(static fn (array $row): array => [$row['source_row'], $row['doc_number'], $row['invoice_number'], $row['reason']], $preview['report']['invoice_unlinked']));

        // Mezi náhledem a převzetím faktura 2025004 dostala vlastní výdejku: převzetí vazbu vynechá.
        $this->inTransaction(fn () => $this->issue->issueForInvoice($sid, $later, $this->userId));
        $applied = $this->applyAll($sid, $preview['id']);
        self::assertSame('completed', $applied['status']);
        self::assertSame(1, $applied['report']['invoice_unlinked_on_apply']);
        self::assertSame([[6, 'V4', '2025004', 'own_stock']],
            array_map(static fn (array $row): array => [$row['source_row'], $row['doc_number'], $row['invoice_number'], $row['reason']], $applied['report']['invoice_unlinked_on_apply_list']));
        $stmt = $this->db->pdo()->prepare('SELECT doc_number, invoice_id FROM stock_documents WHERE supplier_id = ? AND history_import_job_id IS NOT NULL AND doc_type = ? AND doc_number LIKE ? ORDER BY id');
        $stmt->execute([$sid, 'issue', 'V%']);
        self::assertSame([['V1', null], ['V2', null], ['V3', null], ['V4', null], ['V5', $old['id']]],
            array_map(static fn (array $row): array => [$row[0], $row[1] === null ? null : (int) $row[1]], $stmt->fetchAll(\PDO::FETCH_NUM)));

        // Živá faktura po stornu a obnovení zboží znovu vydá, vystavený koncept také.
        $level = $this->level($sid, $main, $a)['qtyT'];
        $this->inTransaction(fn () => $this->issue->reverseForInvoice($sid, $live['id'], $this->userId));
        $this->inTransaction(fn () => $this->issue->issueForInvoice($sid, $live, $this->userId));
        self::assertSame($level, $this->level($sid, $main, $a)['qtyT']);
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'issued' WHERE id = ?")->execute([$draft['id']]);
        $this->inTransaction(fn () => $this->issue->issueForInvoice($sid, $draft, $this->userId));
        self::assertSame($level - 2000, $this->level($sid, $main, $a)['qtyT']);
    }

    /** Storno dobropisu převzatou příjemku nestornuje a obnovení dobropisu zboží podruhé nevrátí. */
    public function testCreditNoteCancelAndRestoreKeepHistoryReturn(): void
    {
        $sid = $this->createSupplier();
        $main = $this->warehouse($sid, 'HL');
        $a = $this->item($sid, 'ZB-100');
        $creditNote = $this->issuedInvoice($sid, 'credit_note', 'D2020009', '2020-07-01', $main, $a, '-2.000');
        $applied = $this->applyAll($sid, $this->stage($sid, self::csv([
            'P1;receipt;2020-01-05;HL;;ZB-100;10;50;;;;',
            'P2;receipt;2020-06-01;HL;;ZB-100;2;50;;D2020009;;',
        ]), $this->config(null))['id']);
        self::assertSame('completed', $applied['status']);
        self::assertSame(12000, $this->level($sid, $main, $a)['qtyT']);
        $this->inTransaction(fn () => $this->issue->reverseForInvoice($sid, $creditNote['id'], $this->userId));
        $this->inTransaction(fn () => $this->issue->issueForInvoice($sid, $creditNote, $this->userId));
        self::assertSame([['P2', 'posted']], array_map(
            static fn (array $doc): array => [$doc['doc_number'], $doc['status']],
            $this->docsRepo->listByInvoice($sid, $creditNote['id']),
        ));
        self::assertSame(12000, $this->level($sid, $main, $a)['qtyT']);
    }

    /** Má-li firma import počátečních zásob, je počátečním stavem k D jen on, ne další příjemka toho dne. */
    public function testEndStateUsesOpeningImportLinesWhenPresent(): void
    {
        $sid = $this->createSupplier();
        $main = $this->warehouse($sid, 'HL');
        $a = $this->item($sid, 'ZB-100');
        $opening = $this->receiveStock($sid, $main, $a, '10', 100.0, '2021-01-01');
        $stmt = $this->db->pdo()->prepare('SELECT id FROM stock_document_lines WHERE document_id = ?');
        $stmt->execute([$opening['id']]);
        $this->db->pdo()->prepare("INSERT INTO external_entity_map (supplier_id, connection_id, source_key, entity_type, external_id, internal_id)
            VALUES (?, NULL, 'stary-sklad', 'stock_opening_line', 'ZB-100', ?)")->execute([$sid, (int) $stmt->fetchColumn()]);
        $this->receiveStock($sid, $main, $a, '4', 100.0, '2021-01-01');
        $this->post($sid, 'issue', $main, null, '2021-01-01', [[$a, '3']]);
        $preview = $this->stage($sid, self::csv(['P1;prijemka;2020-01-05;HL;;ZB-100;10;50;;;;']), $this->config('2021-01-01'));
        $state = $this->container->get(StockHistoryImportService::class)->endState($sid, $preview['id']);
        self::assertSame(['count' => 1, 'differences' => 0], $state['totals']);
        self::assertSame('10.000', $state['items'][0]['stock_qty']);
    }

    /** Faktura krytá převzatou výdejkou nic nerezervuje: převzatá výdejka platí za výdej celé faktury. */
    public function testInvoiceCoveredByHistoryIssueReservesNothing(): void
    {
        $sid = $this->createSupplier();
        $main = $this->warehouse($sid, 'HL');
        $a = $this->item($sid, 'ZB-100');
        $this->receiveStock($sid, $main, $a, '25', 100.0, '2021-01-01');
        $this->issuedInvoice($sid, 'invoice', '2020001', '2020-03-01', $main, $a, '4.000');
        $open = $this->issuedInvoice($sid, 'invoice', '2020002', '2020-03-02', $main, $a, '1.000');
        $applied = $this->applyAll($sid, $this->stage($sid, self::csv([
            'P1;receipt;2020-01-05;HL;;ZB-100;10;50;;;;',
            'V1;issue;2020-03-01;HL;;ZB-100;4;;;2020001;;',
        ]), $this->config('2021-01-01'))['id']);
        self::assertSame('completed', $applied['status']);

        $quantities = $this->container->get(InTransitService::class);
        $row = $quantities->quantities($sid, [$a])[0];
        self::assertSame(['25.000', '1.000', '24.000'], [$row['on_hand'], $row['reserved'], $row['sellable']]);
        $reservations = $quantities->reservations($sid, [$a]);
        self::assertSame([$open['id']], array_column($reservations[0]['invoices'] ?? [], 'invoice_id'));
    }

    /** Import počátečních zásob v jiném skladu nebo k jinému datu nevypne ruční počáteční příjemku skladu s datem D. */
    public function testEndStateOpeningImportCountsOnlyForItsWarehouseAndDay(): void
    {
        $sid = $this->createSupplier();
        $main = $this->warehouse($sid, 'HL');
        $branch = $this->warehouse($sid, 'POB', false);
        $a = $this->item($sid, 'ZB-100');
        $opening = $this->receiveStock($sid, $branch, $a, '5', 100.0, '2021-03-01');
        $stmt = $this->db->pdo()->prepare('SELECT id FROM stock_document_lines WHERE document_id = ?');
        $stmt->execute([$opening['id']]);
        $this->db->pdo()->prepare("INSERT INTO external_entity_map (supplier_id, connection_id, source_key, entity_type, external_id, internal_id)
            VALUES (?, NULL, 'jiny-sklad', 'stock_opening_line', 'ZB-100', ?)")->execute([$sid, (int) $stmt->fetchColumn()]);
        $this->receiveStock($sid, $main, $a, '10', 100.0, '2021-01-01');
        $preview = $this->stage($sid, self::csv(['P1;prijemka;2020-01-05;HL;;ZB-100;10;50;;;;']), $this->config('2021-01-01'));
        $state = $this->container->get(StockHistoryImportService::class)->endState($sid, $preview['id']);
        self::assertSame(['count' => 1, 'differences' => 0], $state['totals']);
        self::assertSame(['HL', '10.000', '10.000'], [$state['items'][0]['warehouse_code'], $state['items'][0]['history_qty'], $state['items'][0]['stock_qty']]);
    }

    // ── pomocníci ────────────────────────────────────────────────────────────

    private function expectStale(callable $run): void
    {
        try {
            $run();
            self::fail('Zastaralá úloha musí selhat.');
        } catch (StockException $e) {
            self::assertSame('history_import_job_stale', $e->errorCode);
        }
    }

    private function expectOpenStockTake(callable $run): void
    {
        try {
            $run();
            self::fail('Import historie během inventury musí selhat.');
        } catch (EshopException $e) {
            self::assertSame('history_import_stock_take_open', $e->errorCode);
        }
    }

    private function expectLocked(callable $run): void
    {
        try {
            $run();
            self::fail('Doklad během importu historie musí selhat.');
        } catch (StockException $e) {
            self::assertSame('stock_history_import_running', $e->errorCode);
        }
    }

    /** Faktura (dobropis, proforma) s jedním řádkem; bez karty jde o fakturu bez skladových řádků. */
    private function issuedInvoice(int $supplierId, string $type, string $number, string $date, ?int $warehouseId, ?int $itemId, string $qty, string $status = 'issued'): array
    {
        $id = $this->invoiceDraft($supplierId, $this->client($supplierId), $type, ['issue_date' => $date, 'varsymbol' => $number]);
        $this->invoiceItem($id, $itemId, $warehouseId, $qty);
        $this->db->pdo()->prepare('UPDATE invoices SET status = ? WHERE id = ?')->execute([$status, $id]);
        return ['id' => $id, 'supplier_id' => $supplierId, 'invoice_type' => $type, 'parent_invoice_id' => null,
            'issue_date' => $date, 'tax_date' => $date, 'varsymbol' => $number];
    }

    private function inTransaction(callable $run): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $pdo->beginTransaction();
        try {
            $run();
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private function retryThroughAction(int $supplierId, int $jobId): int
    {
        $response = $this->changeThroughAction($supplierId, $jobId, 'retry');
        if ($response->getStatusCode() === 409) {
            self::assertSame('history_import_retry_unsupported', json_decode((string) $response->getBody(), true)['error']['code']);
        }
        return $response->getStatusCode();
    }

    private function changeThroughAction(int $supplierId, int $jobId, string $operation, string $role = 'admin'): \Psr\Http\Message\ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/stock/history-import/jobs/' . $jobId . '/' . $operation)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role]);
        return $this->container->get(CatalogJobAction::class)->change($request, new Response(), ['id' => $jobId, 'operation' => $operation]);
    }

    /** Obnova z archivu nepřenáší úlohy importu. */
    private function forgetJobs(int $supplierId): void
    {
        $this->db->pdo()->prepare('DELETE FROM catalog_job_items WHERE supplier_id = ?')->execute([$supplierId]);
        $this->db->pdo()->prepare('DELETE FROM catalog_jobs WHERE supplier_id = ?')->execute([$supplierId]);
    }

    private function post(int $supplierId, string $type, int $warehouseId, ?int $warehouseToId, string $date, array $lines): array
    {
        $document = $this->documents->create($supplierId, [
            'doc_type' => $type, 'warehouse_id' => $warehouseId, 'warehouse_to_id' => $warehouseToId, 'doc_date' => $date, 'description' => 'Živý doklad',
            'lines' => array_map(static fn (array $line): array => ['stock_item_id' => $line[0], 'qty' => $line[1]], $lines),
        ], $this->userId);
        return $this->documents->post($supplierId, (int) $document['id'], $this->userId);
    }

    /** Stavy, živé řádky a ocenění k několika dnům od data přechodu. */
    private function fingerprint(int $supplierId, array $warehouses, array $items): array
    {
        $out = [];
        foreach ($warehouses as $w => $warehouse) {
            foreach ($items as $i => $item) {
                $out["level {$w}/{$i}"] = $this->level($supplierId, $warehouse, $item);
            }
        }
        $stmt = $this->db->pdo()->prepare('SELECT d.doc_date, d.doc_type, l.qty, l.unit_cost, l.value_total FROM stock_documents d
            JOIN stock_document_lines l ON l.document_id = d.id WHERE d.supplier_id = ? AND d.history_import_job_id IS NULL ORDER BY d.id, l.line_no');
        $stmt->execute([$supplierId]);
        $out['live lines'] = $stmt->fetchAll(\PDO::FETCH_NUM);
        $report = $this->container->get(StockReportService::class);
        foreach (['2021-01-01', '2021-01-31', '2021-02-15', '2021-03-02', '2021-12-31'] as $date) {
            $out["valuation {$date}"] = array_map(
                static fn (array $row): array => [$row['warehouse_code'], $row['sku'], $row['qty'], $row['value_total']],
                $report->valuation($supplierId, $date, [])['items'],
            );
        }
        return $out;
    }

    private function failedRows(int $jobId): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT source_row, status, error_code FROM catalog_job_items WHERE job_id = ? AND status = 'failed' ORDER BY source_row");
        $stmt->execute([$jobId]);
        return array_map(static fn (array $row): array => [(int) $row[0], $row[1], $row[2]], $stmt->fetchAll(\PDO::FETCH_NUM));
    }

    private function historyCount(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM stock_documents WHERE supplier_id = ? AND history_import_job_id IS NOT NULL');
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn();
    }

    private function lockedUntil(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT stock_history_locked_until FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : $value;
    }

    private static function csv(array $rows): string
    {
        return self::HEADER . "\n" . implode("\n", $rows) . "\n";
    }

    private function config(?string $cutover, array $over = []): array
    {
        return $over + [
            'source_key' => 'stary-sklad',
            'cutover_date' => $cutover,
            'mapping' => [
                'doc_number' => 'doklad', 'doc_type' => 'typ', 'doc_date' => 'datum', 'warehouse' => 'sklad',
                'warehouse_to' => 'sklad_cil', 'sku' => 'sku', 'quantity' => 'mnozstvi', 'unit_cost' => 'cena',
                'partner' => 'partner', 'invoice_number' => 'faktura_vydana', 'purchase_invoice_number' => 'faktura_prijata',
                'note' => 'poznamka',
            ],
        ];
    }

    private function stage(int $supplierId, string $csv, array $config): array
    {
        $path = tempnam(sys_get_temp_dir(), 'history-lifecycle-');
        $this->files[] = $path;
        file_put_contents($path, $csv);
        $sources = $this->container->get(CatalogImportSourceStore::class);
        $source = $sources->upload($supplierId, new UploadedFile($path, 'historie.csv', 'text/csv', strlen($csv)), $this->userId);
        $this->files[] = $sources->path($supplierId, $source['id']);
        $job = $this->container->get(StockHistoryImportService::class)->preview($supplierId, $source['id'], $config, $this->userId);
        return $this->drain($supplierId, StockHistoryImportService::STAGE_KIND, $job['id']);
    }

    private function applyAll(int $supplierId, int $previewId): array
    {
        $job = $this->container->get(StockHistoryImportService::class)->apply($supplierId, $previewId, $this->userId);
        return $this->drain($supplierId, StockHistoryImportService::APPLY_KIND, $job['id']);
    }

    private function drain(int $supplierId, string $kind, int $jobId): array
    {
        $worker = $this->container->get(StockHistoryImportWorker::class);
        for ($i = 0; $i < 50; $i++) {
            $job = $worker->tickKind($supplierId, $kind);
            if ($job === null || in_array($job['status'], ['completed', 'failed', 'cancelled'], true)) {
                break;
            }
        }
        $job = $this->container->get(CatalogJobService::class)->find($supplierId, $jobId);
        self::assertNotNull($job);
        return $job;
    }
}
