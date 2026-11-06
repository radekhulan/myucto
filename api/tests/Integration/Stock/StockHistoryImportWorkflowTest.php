<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Repository\IntrastatRepository;
use MyInvoice\Service\Accounting\Closing\StockClosingValuation;
use MyInvoice\Service\Eshop\CatalogJobService;
use MyInvoice\Service\Eshop\EshopException;
use MyInvoice\Service\Eshop\Import\CatalogImportSourceStore;
use MyInvoice\Service\Stock\StockException;
use MyInvoice\Service\Stock\StockHistoryImportService;
use MyInvoice\Service\Stock\StockHistoryImportWorker;
use Slim\Psr7\UploadedFile;

final class StockHistoryImportWorkflowTest extends StockTestCase
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

    /**
     * Firma už vede sklad od D (počáteční stav + výdej). Historie před D se ocení klouzavým
     * průměrem přes sklady, k D−1 ji uzavře vyrovnávací výdejka a živý stav, ocenění živých
     * dokladů ani snapshot k D−1 se nezmění.
     */
    public function testHistoryBeforeCutoverKeepsLiveStateAndClosesWithSettlement(): void
    {
        $sid = $this->createSupplier();
        $main = $this->warehouse($sid, 'HL');
        $branch = $this->warehouse($sid, 'POB', false);
        $a = $this->item($sid, 'ZB-100');
        $b = $this->item($sid, 'ZB-200');
        $client = $this->client($sid);
        $invoice = $this->invoiceDraft($sid, $client, 'invoice', ['varsymbol' => '2020000001', 'issue_date' => '2020-02-01']);
        // Převzatý doklad se váže jen na vystavenou fakturu.
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'issued' WHERE id = ?")->execute([$invoice]);
        $purchase = $this->purchaseInvoice($sid, $client, ['vendor_invoice_number' => 'FP98-7', 'issue_date' => '2020-01-05']);

        $this->receiveStock($sid, $main, $a, '10', 100.0, '2021-01-01');
        $liveIssue = $this->documents->post($sid, (int) $this->documents->create($sid, [
            'doc_type' => 'issue', 'warehouse_id' => $main, 'doc_date' => '2021-02-01', 'description' => 'Živý výdej',
            'lines' => [['stock_item_id' => $a, 'qty' => '3']],
        ], $this->userId)['id'], $this->userId);
        $levelsBefore = [$this->level($sid, $main, $a), $this->level($sid, $branch, $a), $this->level($sid, $main, $b)];

        $csv = self::csv([
            'P98-1;prijemka;2020-01-05;HL;;ZB-100;10;50;Dodavatel A;;FP98-7;',
            'P98-1;prijemka;2020-01-05;HL;;ZB-200;4;20;Dodavatel A;;FP98-7;',
            'V98-1;vydejka;1.2.2020;HL;;ZB-100;4;;;2020000001;;',
            'T98-1;prevodka;2020-03-01;HL;POB;ZB-100;2;;;;;',
            'I98-1;prebytek;2020-04-01;POB;;ZB-100;1;80;;;;inventura',
            'M98-1;manko;2020-05-01;HL;;ZB-200;1;;;;;',
            'P98-2;prijemka;2020-06-01;HL;;ZB-200;1;30;;;;bez faktury',
        ]);
        $preview = $this->stage($sid, $csv, $this->config('2021-01-01'));
        self::assertSame('completed', $preview['status']);
        self::assertSame(7, $preview['report']['counts']['ready']);
        self::assertSame(['receipt' => 2, 'issue' => 1, 'transfer' => 1, 'surplus' => 1, 'shortage' => 1], $preview['report']['documents']);
        self::assertSame(0, $preview['report']['negative_count']);
        self::assertSame(['2020-01-05', '2020-06-01'], [$preview['report']['date_from'], $preview['report']['date_to']]);

        $applied = $this->applyAll($sid, $preview['id']);
        self::assertSame('completed', $applied['status']);
        self::assertSame(6, $applied['report']['documents_created']);

        self::assertSame($levelsBefore, [$this->level($sid, $main, $a), $this->level($sid, $branch, $a), $this->level($sid, $main, $b)]);
        self::assertSame($liveIssue['lines'][0]['value_total'], $this->docsRepo->findWithLines($sid, (int) $liveIssue['id'])['lines'][0]['value_total']);

        $docs = $this->historyDocuments($sid);
        self::assertSame(['P98-1', 'V98-1', 'T98-1', 'I98-1', 'M98-1', 'P98-2', "UH-{$main}-2020-12-31", "UH-{$branch}-2020-12-31"], array_column($docs, 'doc_number'));
        $byNumber = array_column($docs, null, 'doc_number');
        self::assertSame($purchase, $byNumber['P98-1']['purchase_invoice_id']);
        self::assertSame($invoice, $byNumber['V98-1']['invoice_id']);
        self::assertSame('2020-02-01', $byNumber['V98-1']['doc_date']);
        self::assertSame(['inventory', 'receipt'], [$byNumber['I98-1']['origin'], $byNumber['I98-1']['doc_type']]);
        self::assertSame(['inventory', 'issue'], [$byNumber['M98-1']['origin'], $byNumber['M98-1']['doc_type']]);
        self::assertSame('posted', $byNumber['P98-1']['status']);

        // Výdej 4 ks za průměr 50, převod 2 ks nese 100 Kč na POB, POB: 2×50 + 1×80.
        self::assertSame(['50.000000', '200.00'], $this->lineValuation($byNumber['V98-1']['id']));
        self::assertSame(['50.000000', '100.00'], $this->lineValuation($byNumber['T98-1']['id']));
        self::assertSame(['20.000000', '20.00'], $this->lineValuation($byNumber['M98-1']['id']));
        $settlement = $this->settlementLines($sid);
        self::assertSame([
            ['HL', 'ZB-100', '4.000', '200.00'],
            ['HL', 'ZB-200', '4.000', '90.00'],
            ['POB', 'ZB-100', '3.000', '180.00'],
        ], $settlement);

        $locked = $this->db->pdo()->prepare('SELECT stock_history_locked_until FROM supplier WHERE id = ?');
        $locked->execute([$sid]);
        self::assertSame('2020-12-31', $locked->fetchColumn());
        $snapshot = $this->db->pdo()->prepare("SELECT qty, value_total FROM stock_valuation_snapshots
            WHERE supplier_id = ? AND warehouse_id = ? AND stock_item_id = ? AND cutoff_date = '2020-12-31'");
        $snapshot->execute([$sid, $branch, $a]);
        self::assertSame(['qty' => '0.000', 'value_total' => '0.00'], $snapshot->fetch(\PDO::FETCH_ASSOC));

        // Stav skladu z knihy k D−1 je nulový, kniha karty ukazuje celou historii.
        $ledger = $this->levelsRepo->ledgerForItem($sid, $a, ['to' => '2020-12-31', 'limit' => 500]);
        self::assertCount(7, $ledger);
        self::assertSame([true], array_values(array_unique(array_column($ledger, 'imported'))));
        self::assertFalse($this->levelsRepo->ledgerForItem($sid, $a, ['from' => '2021-01-01', 'limit' => 500])[0]['imported']);
        self::assertSame('0.000', array_reduce($ledger, static fn (string $sum, array $row): string => bcadd($sum, $row['qty_signed'], 3), '0'));

        // Převzatou historii nejde stornovat ani do ní zaúčtovat nový doklad.
        try {
            $this->documents->reverse($sid, $byNumber['V98-1']['id'], [], $this->userId);
            self::fail('Storno převzatého dokladu musí selhat.');
        } catch (StockException $e) {
            self::assertSame('stock_history_locked', $e->errorCode);
        }
        try {
            $this->receiveStock($sid, $main, $a, '1', 1.0, '2020-12-31');
            self::fail('Doklad do převzaté historie musí selhat.');
        } catch (StockException $e) {
            self::assertSame('stock_history_locked', $e->errorCode);
        }
        $this->receiveStock($sid, $main, $a, '1', 1.0, '2021-01-01');

        $closing = $this->container->get(StockClosingValuation::class)->unmatchedDocuments($sid, '2021-12-31');
        self::assertSame('stock_unbilled_receipts', $closing[0]['key']);
        $unbilled = array_column($closing[0]['items'], 'doc_number');
        self::assertCount(2, $unbilled);
        self::assertNotContains('P98-2', $unbilled);
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'issued' WHERE id = ?")->execute([$invoice]);
        $intrastat = $this->container->get(IntrastatRepository::class)->movementRows($sid, '2020-01-01', '2021-01-01', 'dispatch');
        self::assertSame([], $intrastat);

        try {
            $this->stage($sid, $csv, $this->config('2021-01-01'));
            self::fail('Druhý aktivní import musí selhat.');
        } catch (EshopException $e) {
            self::assertSame('history_import_active', $e->errorCode);
        }
    }

    /**
     * Zpětný živý doklad během importu (historie zapsaná, ještě nevyrovnaná) přepočítá kartu
     * od nulového snapshotu k D−1, ne přes rozpracovanou historii.
     */
    public function testBackdatedLivePostDuringImportIgnoresUnfinishedHistory(): void
    {
        $sid = $this->createSupplier();
        $main = $this->warehouse($sid, 'HL');
        $a = $this->item($sid, 'ZB-100');
        $this->receiveStock($sid, $main, $a, '10', 100.0, '2021-01-01');
        $this->documents->post($sid, (int) $this->documents->create($sid, [
            'doc_type' => 'issue', 'warehouse_id' => $main, 'doc_date' => '2021-03-01', 'description' => 'Živý výdej',
            'lines' => [['stock_item_id' => $a, 'qty' => '2']],
        ], $this->userId)['id'], $this->userId);

        $preview = $this->stage($sid, self::csv(['P1;prijemka;2020-01-05;HL;;ZB-100;5;50;;;;']), $this->config('2021-01-01'));
        $job = $this->container->get(StockHistoryImportService::class)->apply($sid, $preview['id'], $this->userId);
        $this->container->get(StockHistoryImportWorker::class)->tickKind($sid, StockHistoryImportService::APPLY_KIND, 1);
        self::assertCount(1, $this->historyDocuments($sid));

        $this->receiveStock($sid, $main, $a, '1', 100.0, '2021-02-01');
        self::assertSame(['qtyT' => 9000, 'valueC' => 90000], $this->level($sid, $main, $a));
        self::assertSame('completed', $this->drain($sid, StockHistoryImportService::APPLY_KIND, $job['id'])['status']);
        self::assertSame(['qtyT' => 9000, 'valueC' => 90000], $this->level($sid, $main, $a));
    }

    /** Firma bez skladových dokladů: konec historie je stav skladu, bez vyrovnání. */
    public function testHistoryWithoutCutoverBecomesLiveState(): void
    {
        $sid = $this->createSupplier();
        $main = $this->warehouse($sid, 'HL');
        $a = $this->item($sid, 'ZB-100');

        $preview = $this->stage($sid, self::csv([
            'P1;receipt;2020-01-05;HL;;ZB-100;10;50;;;;',
            'P2;receipt;2020-01-06;HL;;ZB-100;10;80;;;;',
            'V1;issue;2020-03-01;HL;;ZB-100;5;;;;;',
        ]), $this->config(null));
        $applied = $this->applyAll($sid, $preview['id']);
        self::assertSame('completed', $applied['status']);

        self::assertSame(['qtyT' => 15000, 'valueC' => 97500], $this->level($sid, $main, $a));
        self::assertSame(['P1', 'P2', 'V1'], array_column($this->historyDocuments($sid), 'doc_number'));
        $locked = $this->db->pdo()->prepare('SELECT stock_history_locked_until FROM supplier WHERE id = ?');
        $locked->execute([$sid]);
        self::assertSame('2020-03-01', $locked->fetchColumn());

        $issue = $this->documents->post($sid, (int) $this->documents->create($sid, [
            'doc_type' => 'issue', 'warehouse_id' => $main, 'doc_date' => '2020-03-02', 'description' => 'Pokračování',
            'lines' => [['stock_item_id' => $a, 'qty' => '15']],
        ], $this->userId)['id'], $this->userId);
        self::assertSame('975.00', $issue['lines'][0]['value_total']);
    }

    public function testLiveModeRequiresFirmWithoutStockDocuments(): void
    {
        $sid = $this->createSupplier();
        $main = $this->warehouse($sid, 'HL');
        $a = $this->item($sid, 'ZB-100');
        $this->receiveStock($sid, $main, $a, '1', 1.0, '2021-01-01');
        foreach ([[null, 'history_import_cutover_required'], ['2021-02-01', 'history_import_cutover_after_documents']] as [$cutover, $code]) {
            try {
                $this->stage($sid, self::csv(['P1;receipt;2020-01-05;HL;;ZB-100;1;1;;;;']), $this->config($cutover));
                self::fail($code);
            } catch (EshopException $e) {
                self::assertSame($code, $e->errorCode);
            }
        }
    }

    public function testPreviewRejectsInvalidRowsWholeDocumentsAndSeriesNumbers(): void
    {
        $sid = $this->createSupplier();
        $this->warehouse($sid, 'HL');
        $this->warehouse($sid, 'POB', false);
        $this->item($sid, 'ZB-100');
        $tracked = $this->item($sid, 'SN-1');
        $this->db->pdo()->prepare("UPDATE stock_items SET tracking_mode = 'serial' WHERE id = ?")->execute([$tracked]);

        $preview = $this->stage($sid, self::csv([
            'A1;prijemka;2020-01-05;HL;;ZB-100;1;10;;;;',
            'A1;prijemka;2020-01-05;HL;;NEZNAMA;1;10;;;;',
            'A2;prijemka;2020-01-05;HL;;ZB-100;1;;;;;',
            'A3;vydejka;2020-01-06;XX;;ZB-100;1;;;;;',
            'A4;prevodka;2020-01-07;HL;;ZB-100;1;;;;;',
            'A5;vydejka;2021-01-01;HL;;ZB-100;1;;;;;',
            'A6;vydejka;2020-01-08;HL;;ZB-100;1;;;NEEXISTUJE;;',
            'VYD-2020-0001;vydejka;2020-01-08;HL;;ZB-100;1;;;;;',
            'A7;prijemka;2020-01-09;HL;;ZB-100;1;10;;;;',
            'A7;prijemka;2020-01-10;HL;;ZB-100;1;10;;;;',
            'A8;prijemka;2020-01-11;HL;;SN-1;1;10;;;;',
            ';;;;;;;;;;;',
            'A9;neznamy;2020-01-11;HL;;ZB-100;1;10;;;;',
        ]), $this->config('2021-01-01'));
        $errors = $this->errors($sid, $preview['id']);
        self::assertSame([
            2 => 'history_import_document_incomplete',
            3 => 'history_import_item_not_found',
            4 => 'history_import_unit_cost_required',
            5 => 'history_import_warehouse_not_found',
            6 => 'history_import_warehouse_to_invalid',
            7 => 'history_import_doc_date_after_cutover',
            8 => 'history_import_invoice_not_found',
            9 => 'history_import_number_series_collision',
            10 => 'history_import_document_inconsistent',
            11 => 'history_import_document_inconsistent',
            12 => 'history_import_tracking_unsupported',
            14 => 'history_import_doc_type_invalid',
        ], $errors);
        self::assertSame(1, $preview['report']['counts']['skipped']);
        try {
            $this->container->get(StockHistoryImportService::class)->apply($sid, $preview['id'], $this->userId);
            self::fail('Náhled s chybami nejde aplikovat.');
        } catch (EshopException $e) {
            self::assertSame('job_state_conflict', $e->errorCode);
        }
    }

    /**
     * Záporný stav: bez volby import neprojde a náhled vyjmenuje místa, s volbou vznikne
     * technická příjemka těsně před výdejem za poslední známou cenu karty (bez ní 0).
     */
    public function testNegativeStockIsReportedOrFilledWithTechnicalReceipts(): void
    {
        $sid = $this->createSupplier();
        $main = $this->warehouse($sid, 'HL');
        $a = $this->item($sid, 'ZB-100');
        $b = $this->item($sid, 'ZB-200');
        $csv = self::csv([
            'V0;vydejka;2020-01-02;HL;;ZB-200;2;;;;;',
            'P1;prijemka;2020-01-05;HL;;ZB-100;2;40;;;;',
            'V1;vydejka;2020-01-06;HL;;ZB-100;3;;;;;',
            'V2;vydejka;2020-01-07;HL;;ZB-100;1;;;;;',
            'P2;prijemka;2020-01-08;HL;;ZB-100;5;60;;;;',
        ]);

        $preview = $this->stage($sid, $csv, $this->config(null));
        self::assertSame(3, $preview['report']['negative_count']);
        self::assertSame(
            [['V0', 'ZB-200', '2.000', '0.000000'], ['V1', 'ZB-100', '1.000', '40.000000'], ['V2', 'ZB-100', '1.000', '40.000000']],
            array_map(static fn (array $n): array => [$n['doc_number'], $n['sku'], $n['missing_quantity'], $n['unit_cost']], $preview['report']['negatives']),
        );
        try {
            $this->container->get(StockHistoryImportService::class)->apply($sid, $preview['id'], $this->userId);
            self::fail('Záporný stav bez volby musí selhat.');
        } catch (EshopException $e) {
            self::assertSame('history_import_negative_stock', $e->errorCode);
        }

        $preview = $this->stage($sid, $csv, $this->config(null, ['fill_negative' => true]));
        $applied = $this->applyAll($sid, $preview['id']);
        self::assertSame('completed', $applied['status']);
        self::assertSame(3, $applied['report']['fill_documents']);
        self::assertSame(['V0-TP2', 'V0', 'P1', 'V1-TP4', 'V1', 'V2-TP5', 'V2', 'P2'], array_column($this->historyDocuments($sid), 'doc_number'));
        $fill = array_column($this->historyDocuments($sid), null, 'doc_number')['V1-TP4'];
        self::assertStringContainsString('doplnění záporného stavu před V1', $fill['description']);
        self::assertSame(['qtyT' => 5000, 'valueC' => 30000], $this->level($sid, $main, $a));
        self::assertSame(['qtyT' => 0, 'valueC' => 0], $this->level($sid, $main, $b));
    }

    public function testHistoryOperationsCannotAccessAnotherCompany(): void
    {
        $sid = $this->createSupplier();
        $other = $this->createSupplier();
        $warehouse = $this->warehouse($sid, 'HL');
        $item = $this->item($sid, 'ZB-100');
        $preview = $this->stage($sid, self::csv(['P1;receipt;2020-01-05;HL;;ZB-100;1;10;;;;']), $this->config(null));
        $service = $this->container->get(StockHistoryImportService::class);
        foreach ([
            fn () => $service->preview($other, $preview['input']['source_id'], $this->config(null), $this->userId),
            fn () => $service->endState($other, $preview['id']),
            fn () => $service->apply($other, $preview['id'], $this->userId),
        ] as $operation) {
            try {
                $operation();
                self::fail('Cizí firma nesmí získat zdroj ani náhled importu.');
            } catch (EshopException $e) {
                self::assertSame('not_found', $e->errorCode);
            }
        }
        $applied = $this->applyAll($sid, $preview['id']);
        try {
            $service->rollback($other, $applied['id'], $this->userId);
            self::fail('Cizí firma nesmí vrátit import.');
        } catch (EshopException $e) {
            self::assertSame('not_found', $e->errorCode);
        }
        self::assertNull($service->active($other));
        self::assertSame(['qtyT' => 1000, 'valueC' => 1000], $this->level($sid, $warehouse, $item));
    }

    public function testPeriodClosedAfterApplyWasQueuedBlocksWorkerAndRetry(): void
    {
        $sid = $this->createSupplier('double_entry');
        $this->warehouse($sid, 'HL');
        $this->item($sid, 'ZB-100');
        $preview = $this->stage($sid, self::csv(['P1;receipt;2020-01-05;HL;;ZB-100;1;10;;;;']), $this->config(null));
        $service = $this->container->get(StockHistoryImportService::class);
        $jobs = $this->container->get(CatalogJobService::class);
        $apply = $service->apply($sid, $preview['id'], $this->userId);
        $this->db->pdo()->prepare("INSERT INTO accounting_periods (supplier_id, fiscal_year, starts_on, ends_on, status)
            VALUES (?, 2020, '2020-01-01', '2020-12-31', 'closed')")->execute([$sid]);
        foreach ([false, true] as $retry) {
            if ($retry) {
                self::assertTrue($jobs->retry($sid, $apply['id']));
            }
            try {
                $this->drain($sid, StockHistoryImportService::APPLY_KIND, $apply['id']);
                self::fail('Uzávěrka po zařazení úlohy musí zastavit zápis dokladů.');
            } catch (EshopException $e) {
                self::assertSame('history_import_closed_periods_unconfirmed', $e->errorCode);
            }
            self::assertSame([], $this->historyDocuments($sid));
        }
        $rollback = $service->rollback($sid, $apply['id'], $this->userId, true);
        self::assertSame('completed', $this->drain($sid, StockHistoryImportService::ROLLBACK_KIND, $rollback['id'])['status']);
        self::assertSame('completed', $this->applyAll($sid, $preview['id'], true)['status']);
    }

    public function testPeriodClosedAfterRollbackWasQueuedBlocksWorkerAndRetry(): void
    {
        $sid = $this->createSupplier('double_entry');
        $warehouse = $this->warehouse($sid, 'HL');
        $item = $this->item($sid, 'ZB-100');
        $applied = $this->applyAll($sid, $this->stage($sid, self::csv(['P1;receipt;2020-01-05;HL;;ZB-100;1;10;;;;']), $this->config(null))['id']);
        $service = $this->container->get(StockHistoryImportService::class);
        $jobs = $this->container->get(CatalogJobService::class);
        $rollback = $service->rollback($sid, $applied['id'], $this->userId);
        $this->db->pdo()->prepare("INSERT INTO accounting_periods (supplier_id, fiscal_year, starts_on, ends_on, status)
            VALUES (?, 2020, '2020-01-01', '2020-12-31', 'closed')")->execute([$sid]);
        foreach ([false, true] as $retry) {
            if ($retry) {
                self::assertTrue($jobs->retry($sid, $rollback['id']));
            }
            try {
                $this->drain($sid, StockHistoryImportService::ROLLBACK_KIND, $rollback['id']);
                self::fail('Uzávěrka po zařazení vrácení musí zastavit mazání dokladů.');
            } catch (EshopException $e) {
                self::assertSame('history_import_closed_periods_unconfirmed', $e->errorCode);
            }
            self::assertCount(1, $this->historyDocuments($sid));
            self::assertSame(['qtyT' => 1000, 'valueC' => 1000], $this->level($sid, $warehouse, $item));
        }
        $confirmed = $service->rollback($sid, $applied['id'], $this->userId, true);
        self::assertSame('completed', $this->drain($sid, StockHistoryImportService::ROLLBACK_KIND, $confirmed['id'])['status']);
        self::assertSame([], $this->historyDocuments($sid));
        self::assertSame(['qtyT' => 0, 'valueC' => 0], $this->level($sid, $warehouse, $item));
    }

    public function testLargeHistoryDocumentPreservesAllLinesAndValuation(): void
    {
        $sid = $this->createSupplier();
        $warehouse = $this->warehouse($sid, 'HL');
        $item = $this->item($sid, 'ZB-100');
        $lineCount = 6554;
        $preview = $this->stage($sid, self::csv(array_fill(0, $lineCount,
            'P1;receipt;2020-01-05;HL;;ZB-100;1;10;;;;')), $this->config(null));
        self::assertSame($lineCount, $preview['report']['counts']['ready']);

        $service = $this->container->get(StockHistoryImportService::class);
        $jobs = $this->container->get(CatalogJobService::class);
        $applyJob = $service->apply($sid, $preview['id'], $this->userId);
        $trigger = 'history_review_failure_' . $sid;
        $this->db->pdo()->exec("CREATE TRIGGER `{$trigger}` BEFORE INSERT ON stock_document_lines FOR EACH ROW
            BEGIN IF NEW.supplier_id = {$sid} AND NEW.line_no = {$lineCount} THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synteticke selhani posledni davky';
            END IF; END");
        try {
            try {
                $this->drain($sid, StockHistoryImportService::APPLY_KIND, $applyJob['id']);
                self::fail('Chyba poslední dávky musí vrátit celý doklad.');
            } catch (\PDOException $e) {
                self::assertSame('45000', $e->errorInfo[0]);
            }
            self::assertSame([], $this->historyDocuments($sid));
            $remaining = $this->db->pdo()->prepare('SELECT COUNT(*) FROM stock_document_lines WHERE supplier_id = ?');
            $remaining->execute([$sid]);
            self::assertSame(0, (int) $remaining->fetchColumn());
        } finally {
            $this->db->pdo()->exec("DROP TRIGGER IF EXISTS `{$trigger}`");
        }
        self::assertTrue($jobs->retry($sid, $applyJob['id']));
        $applied = $this->drain($sid, StockHistoryImportService::APPLY_KIND, $applyJob['id']);
        self::assertSame('completed', $applied['status']);
        self::assertSame(1, $applied['report']['documents_created']);
        self::assertSame(['qtyT' => $lineCount * 1000, 'valueC' => $lineCount * 1000], $this->level($sid, $warehouse, $item));
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*), MIN(line_no), MAX(line_no) FROM stock_document_lines WHERE supplier_id = ?');
        $stmt->execute([$sid]);
        self::assertSame([$lineCount, 1, $lineCount], array_map('intval', $stmt->fetch(\PDO::FETCH_NUM)));
    }

    public function testPeriodClosedAfterPreviewStillNeedsExplicitConfirmation(): void
    {
        $sid = $this->createSupplier('double_entry');
        $this->warehouse($sid, 'HL');
        $this->item($sid, 'ZB-100');
        $preview = $this->stage($sid, self::csv(['P1;receipt;2020-01-05;HL;;ZB-100;1;10;;;;']), $this->config(null));
        self::assertSame(0, $preview['report']['closed_period_documents']);
        $this->db->pdo()->prepare("INSERT INTO accounting_periods (supplier_id, fiscal_year, starts_on, ends_on, status)
            VALUES (?, 2020, '2020-01-01', '2020-12-31', 'closed')")->execute([$sid]);
        $service = $this->container->get(StockHistoryImportService::class);

        try {
            $service->apply($sid, $preview['id'], $this->userId);
            self::fail('Období uzavřené po náhledu musí vyžadovat potvrzení.');
        } catch (EshopException $e) {
            self::assertSame('history_import_closed_periods_unconfirmed', $e->errorCode);
        }
        self::assertNull($service->active($sid));
        self::assertSame([], $this->historyDocuments($sid));
        self::assertSame('completed', $this->applyAll($sid, $preview['id'], true)['status']);
    }

    public function testClosedPeriodNeedsExplicitConfirmation(): void
    {
        $sid = $this->createSupplier('double_entry');
        $this->warehouse($sid, 'HL');
        $this->item($sid, 'ZB-100');
        $this->db->pdo()->prepare("INSERT INTO accounting_periods (supplier_id, fiscal_year, starts_on, ends_on, status)
            VALUES (?, 2020, '2020-01-01', '2020-12-31', 'closed')")->execute([$sid]);

        $preview = $this->stage($sid, self::csv(['P1;receipt;2020-01-05;HL;;ZB-100;1;10;;;;']), $this->config(null));
        self::assertSame(1, $preview['report']['closed_period_documents']);
        $service = $this->container->get(StockHistoryImportService::class);
        try {
            $service->apply($sid, $preview['id'], $this->userId);
            self::fail('Uzavřené období bez potvrzení musí selhat.');
        } catch (EshopException $e) {
            self::assertSame('history_import_closed_periods_unconfirmed', $e->errorCode);
        }
        self::assertSame('completed', $this->applyAll($sid, $preview['id'], true)['status']);
    }

    /**
     * Vrácení importu s datem přechodu smaže převzaté doklady včetně vyrovnání, odemkne
     * historii a živý stav nechá beze změny; pak jde import spustit znovu.
     */
    public function testRollbackBeforeCutoverRemovesHistoryAndUnlocks(): void
    {
        $sid = $this->createSupplier();
        $main = $this->warehouse($sid, 'HL');
        $a = $this->item($sid, 'ZB-100');
        $this->receiveStock($sid, $main, $a, '10', 100.0, '2021-01-01');
        $levelBefore = $this->level($sid, $main, $a);
        $csv = self::csv(['P1;prijemka;2020-01-05;HL;;ZB-100;5;50;;;;', 'V1;vydejka;2020-02-01;HL;;ZB-100;2;;;;;']);
        $applied = $this->applyAll($sid, $this->stage($sid, $csv, $this->config('2021-01-01'))['id']);
        self::assertCount(3, $this->historyDocuments($sid));

        $service = $this->container->get(StockHistoryImportService::class);
        $rollback = $service->rollback($sid, $applied['id'], $this->userId);
        try {
            $service->rollback($sid, $applied['id'], $this->userId);
            self::fail('Druhé vrácení téhož importu musí selhat.');
        } catch (EshopException $e) {
            self::assertSame('job_state_conflict', $e->errorCode);
        }
        $done = $this->drain($sid, StockHistoryImportService::ROLLBACK_KIND, $rollback['id']);
        self::assertSame('completed', $done['status']);
        self::assertSame(3, $done['report']['documents_deleted']);

        self::assertSame([], $this->historyDocuments($sid));
        self::assertSame($levelBefore, $this->level($sid, $main, $a));
        self::assertNull($service->active($sid));
        $locked = $this->db->pdo()->prepare('SELECT stock_history_locked_until FROM supplier WHERE id = ?');
        $locked->execute([$sid]);
        self::assertNull($locked->fetchColumn());
        $snapshots = $this->db->pdo()->prepare("SELECT COUNT(*) FROM stock_valuation_snapshots WHERE supplier_id = ? AND cutoff_date < '2021-01-01'");
        $snapshots->execute([$sid]);
        self::assertSame(0, (int) $snapshots->fetchColumn());

        $this->receiveStock($sid, $main, $a, '1', 1.0, '2020-12-31');
        self::assertSame('completed', $this->applyAll($sid, $this->stage($sid, self::csv(['P2;prijemka;2020-01-05;HL;;ZB-100;5;50;;;;']), $this->config('2020-12-31'))['id'])['status']);
    }

    /**
     * Vrácení živého stavu vynuluje sklad. Jakmile firma po importu zaúčtovala vlastní
     * doklad, vrácení odmítne: stav skladu by přestal odpovídat dokladům.
     */
    public function testRollbackOfLiveStateZeroesStockAndRefusesAfterLiveDocuments(): void
    {
        $service = $this->container->get(StockHistoryImportService::class);
        $csv = self::csv(['P1;receipt;2020-01-05;HL;;ZB-100;10;50;;;;', 'V1;issue;2020-03-01;HL;;ZB-100;4;;;;;']);

        $sid = $this->createSupplier();
        $main = $this->warehouse($sid, 'HL');
        $a = $this->item($sid, 'ZB-100');
        $applied = $this->applyAll($sid, $this->stage($sid, $csv, $this->config(null))['id']);
        self::assertSame(['qtyT' => 6000, 'valueC' => 30000], $this->level($sid, $main, $a));
        $done = $this->drain($sid, StockHistoryImportService::ROLLBACK_KIND, $service->rollback($sid, $applied['id'], $this->userId)['id']);
        self::assertSame('completed', $done['status']);
        self::assertSame(['qtyT' => 0, 'valueC' => 0], $this->level($sid, $main, $a));
        self::assertSame([], $this->historyDocuments($sid));
        self::assertNull($service->active($sid));

        $other = $this->createSupplier();
        $otherMain = $this->warehouse($other, 'HL');
        $otherItem = $this->item($other, 'ZB-100');
        $applied = $this->applyAll($other, $this->stage($other, $csv, $this->config(null))['id']);
        $this->receiveStock($other, $otherMain, $otherItem, '1', 1.0, '2020-04-01');
        try {
            $service->rollback($other, $applied['id'], $this->userId);
            self::fail('Vrácení po živém dokladu musí selhat.');
        } catch (StockException $e) {
            self::assertSame('history_import_live_documents', $e->errorCode);
        }
        self::assertCount(2, $this->historyDocuments($other));
    }

    // ── pomocníci ────────────────────────────────────────────────────────────

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
        $path = tempnam(sys_get_temp_dir(), 'history-flow-');
        $this->files[] = $path;
        file_put_contents($path, $csv);
        $sources = $this->container->get(CatalogImportSourceStore::class);
        $source = $sources->upload($supplierId, new UploadedFile($path, 'historie.csv', 'text/csv', strlen($csv)), $this->userId);
        $this->files[] = $sources->path($supplierId, $source['id']);
        $job = $this->container->get(StockHistoryImportService::class)->preview($supplierId, $source['id'], $config, $this->userId);
        return $this->drain($supplierId, StockHistoryImportService::STAGE_KIND, $job['id']);
    }

    private function applyAll(int $supplierId, int $previewId, bool $confirm = false): array
    {
        $job = $this->container->get(StockHistoryImportService::class)->apply($supplierId, $previewId, $this->userId, $confirm);
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
        $job = $this->container->get(\MyInvoice\Service\Eshop\CatalogJobService::class)->find($supplierId, $jobId);
        self::assertNotNull($job);
        return $job;
    }

    private function historyDocuments(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, doc_number, doc_type, origin, doc_date, status, description, invoice_id, purchase_invoice_id
            FROM stock_documents WHERE supplier_id = ? AND history_import_job_id IS NOT NULL ORDER BY id');
        $stmt->execute([$supplierId]);
        return array_map(static function (array $row): array {
            foreach (['id', 'invoice_id', 'purchase_invoice_id'] as $key) {
                $row[$key] = $row[$key] === null ? null : (int) $row[$key];
            }
            return $row;
        }, $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    private function lineValuation(int $documentId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT unit_cost, value_total FROM stock_document_lines WHERE document_id = ? ORDER BY line_no LIMIT 1');
        $stmt->execute([$documentId]);
        return array_values($stmt->fetch(\PDO::FETCH_ASSOC));
    }

    private function settlementLines(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT w.code, i.sku, l.qty, l.value_total FROM stock_documents d
            JOIN stock_document_lines l ON l.document_id = d.id
            JOIN warehouses w ON w.id = d.warehouse_id
            JOIN stock_items i ON i.id = l.stock_item_id
            WHERE d.supplier_id = ? AND d.history_import_job_id IS NOT NULL AND d.doc_number LIKE 'UH-%'
            ORDER BY w.code, i.sku");
        $stmt->execute([$supplierId]);
        return array_map('array_values', $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    private function errors(int $supplierId, int $jobId): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT source_row, error_code FROM catalog_job_items
            WHERE supplier_id = ? AND job_id = ? AND status = 'failed' ORDER BY source_row");
        $stmt->execute([$supplierId, $jobId]);
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_KEY_PAIR));
    }
}
