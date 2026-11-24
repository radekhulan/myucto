<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\DimensionAction;
use MyInvoice\Action\Accounting\JournalAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Dimension\DimensionException;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\PostingService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Změna dimenzí zaúčtovaného dokladu v uzavřeném / zamčeném období a v dialogu
 * Přeúčtovat (Firma → Dimenze).
 *
 * Uzavřené knihy chrání i analytické zůstatky. Zámek data otevřeného období
 * dál dovoluje změnu analytiky bez změny účtu a částky.
 * Vše v jedné transakci, tearDown rollbackne.
 */
#[Group('integration')]
final class DimensionLockedPeriodTest extends TestCase
{
    private const YEAR = 2096;

    private Connection $db;
    private PostingService $posting;
    private DimensionService $dimensions;
    private DimensionAssignmentRepository $assignments;
    private AccountingPeriodRepository $periods;
    private DimensionAction $dimensionAction;
    private JournalAction $journalAction;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $periodId = 0;
    private int $projectType = 0;
    private int $centerType = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->posting = $container->get(PostingService::class);
            $this->dimensions = $container->get(DimensionService::class);
            $this->assignments = $container->get(DimensionAssignmentRepository::class);
            $this->periods = $container->get(AccountingPeriodRepository::class);
            $this->dimensionAction = $container->get(DimensionAction::class);
            $this->journalAction = $container->get(JournalAction::class);
            $seeder = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/currency/vat_rate/user/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES ("Dimenze uzavřeného období", "Testovací 1", "Praha", "11000", ?, ?, ?, ?, "double_entry")'
        )->execute([$this->czId, 'closed-dimensions-' . uniqid() . '@example.invalid', $this->currencyId, $this->vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();

        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry', supplier_group_id = NULL WHERE id = ?")
            ->execute([$this->supplierId]);
        $this->lockUntil(null);
        $this->dimensions->setEnabled($this->supplierId, true);
        $types = $this->dimensions->ensureDefaultTypes($this->supplierId, ['projekt', 'stredisko']);
        $this->projectType = $types['project'];
        $this->centerType = $types['cost_center'];
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testDimensionChangeInClosedPeriodIsRefusedAtomically(): void
    {
        $from = $this->value($this->projectType, 'L-FROM');
        $to = $this->value($this->projectType, 'L-TO');
        $purchase = $this->purchase('LOCK-HDR', [[5_000.00, 1_050.00]]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->projectType => $from], []);
        $entryId = $this->postPurchase($purchase);
        $this->periods->setStatus($this->periodId, $this->supplierId, 'closed');
        $before = $this->assignments->entryLineDimensions($this->supplierId, $entryId);
        $document = $this->assignments->documentDimensions($this->supplierId, 'purchase_invoice', $purchase);

        foreach ([[$this->projectType => $to], []] as $header) {
            try {
                $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, $header, [1 => [$this->projectType => $to]]);
                self::fail('Dimenze uzavřeného období se nesmí změnit.');
            } catch (DimensionException $e) {
                self::assertSame('period_not_open', $e->errorCode);
                self::assertSame(409, $e->httpStatus);
            }
            self::assertSame($before, $this->assignments->entryLineDimensions($this->supplierId, $entryId));
            self::assertSame($document, $this->assignments->documentDimensions($this->supplierId, 'purchase_invoice', $purchase));
        }
        $result = $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->projectType => $from], null);
        self::assertSame(0, $result['restamp']['lines']);
    }

    public function testLineDimensionEditorRefusesClosedPeriod(): void
    {
        $center = $this->value($this->centerType, 'L-CC');
        $purchase = $this->purchase('LOCK-LINE', [[800.00, 168.00]]);
        $entryId = $this->postPurchase($purchase);
        $this->periods->setStatus($this->periodId, $this->supplierId, 'closed');
        $linesBefore = $this->linesOf($entryId);
        $lineId = (int) array_key_first($linesBefore);
        try {
            $this->dimensions->saveEntryLines($this->supplierId, $entryId, [$lineId => [$this->centerType => $center]]);
            self::fail('Editor nesmí změnit dimenze uzavřeného období.');
        } catch (DimensionException $e) {
            self::assertSame('period_not_open', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
        }
        self::assertSame($linesBefore, $this->linesOf($entryId));
        self::assertSame([], $this->assignments->entryLineDimensions($this->supplierId, $entryId));
        self::assertSame(0, $this->dimensions->saveEntryLines($this->supplierId, $entryId, [$lineId => []]));
    }

    public function testClosedStatusesPreserveSplitsAndAllowNoOp(): void
    {
        $a = $this->value($this->projectType, 'S-A');
        $b = $this->value($this->projectType, 'S-B');
        $purchase = $this->purchase('SPLIT-CLOSED', [[1_000.00, 210.00]]);
        $shares = [$this->projectType => [['value_id' => $a, 'share' => 0.6], ['value_id' => $b, 'share' => 0.4]]];
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [], [], false, [0 => $shares]);
        $entryId = $this->postPurchase($purchase);
        $lineId = (int) array_key_first($this->linesOf($entryId));
        $before = $this->assignments->entryLineSplits($this->supplierId, $entryId);
        $changed = [$this->projectType => [['value_id' => $a, 'share' => 0.5], ['value_id' => $b, 'share' => 0.5]]];
        foreach (['closed', 'reviewed', 'approved'] as $status) {
            $this->periods->setStatus($this->periodId, $this->supplierId, $status);
            self::assertSame(0, $this->dimensions->saveEntryLines($this->supplierId, $entryId, [], [$lineId => $shares]));
            self::assertSame(0, $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [], null, false, [0 => $shares])['restamp']['lines']);
            foreach ([
                fn () => $this->dimensions->saveEntryLines($this->supplierId, $entryId, [], [$lineId => $changed]),
                fn () => $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [], null, false, [0 => $changed]),
            ] as $change) {
                try {
                    $change();
                    self::fail('Změna podílů uzavřeného období nesmí projít.');
                } catch (DimensionException $e) {
                    self::assertSame('period_not_open', $e->errorCode);
                }
                self::assertSame($before, $this->assignments->entryLineSplits($this->supplierId, $entryId));
                self::assertSame([0 => [$this->projectType => [$a => 0.6, $b => 0.4]]], $this->assignments->documentSplits($this->supplierId, 'purchase_invoice', $purchase));
            }
        }
    }

    public function testClosingBeforeCloseAndLockedOpenPeriodStillAllowAnalyticalChanges(): void
    {
        $a = $this->value($this->projectType, 'OPEN-A');
        $b = $this->value($this->projectType, 'OPEN-B');
        $purchase = $this->purchase('OPEN-LOCKED', [[100.00, 21.00]]);
        $entryId = $this->postPurchase($purchase);
        $lineId = (int) array_key_first($this->linesOf($entryId));
        $this->lockUntil(self::YEAR . '-12-31');
        self::assertGreaterThan(0, $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->projectType => $a], null)['restamp']['lines']);
        self::assertSame(1, $this->dimensions->saveEntryLines($this->supplierId, $entryId, [$lineId => [$this->projectType => $b]]));
        $this->periods->setStatus($this->periodId, $this->supplierId, 'closing');
        self::assertGreaterThan(0, $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->projectType => $b], null)['restamp']['lines']);
        self::assertSame(1, $this->dimensions->saveEntryLines($this->supplierId, $entryId, [$lineId => [$this->projectType => $a]]));
    }

    public function testClosedSourceDimensionsStayContinuousWithClosingAndOpening(): void
    {
        $a = $this->value($this->projectType, 'CONT-A');
        $b = $this->value($this->projectType, 'CONT-B');
        $purchase = $this->purchase('CONTINUITY', [[1_000.00, 210.00]]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->projectType => $a], []);
        $entryId = $this->postPurchase($purchase);
        $closing = Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Closing\ClosingService::class);
        $rv = fn (): int => (int) $this->periods->findById($this->supplierId, $this->periodId)['row_version'];
        $meta = ['user_id' => $this->userId, 'posted_by' => $this->userId];
        $closing->start($this->supplierId, $this->periodId, $rv(), $meta);
        $inventory = [];
        foreach ($closing->inventoryPreview($this->supplierId, $this->periodId)['rows'] as $row) {
            $inventory[(int) $row['account_id']] = ['counted_balance' => (float) $row['book_balance'], 'resolution' => 'resolved', 'note' => null];
        }
        $closing->saveInventory($this->supplierId, $this->periodId, $rv(), ['complete' => true], $inventory, $meta);
        $closing->runPrecheck($this->supplierId, $this->periodId, $rv(), $meta);
        $closing->confirmStep($this->supplierId, $this->periodId, 'depreciation', 'skipped', null, $rv(), $meta);
        $closing->runFxRevaluation($this->supplierId, $this->periodId, [], $rv(), $meta);
        foreach (['estimates', 'deferrals', 'provisions', 'income_tax'] as $step) {
            $closing->confirmStep($this->supplierId, $this->periodId, $step, 'skipped', null, $rv(), $meta);
        }
        $closed = $closing->closeBooks($this->supplierId, $this->periodId, $rv(), $meta);
        $opened = $closing->openNext($this->supplierId, $this->periodId, $rv(), $meta);
        $closingId = (int) $closed['entry_id'];
        $openingId = (int) $opened['entry_id'];
        self::assertGreaterThan(0, $openingId);
        $source = $this->assignments->entryLineDimensions($this->supplierId, $entryId);
        $frozenClosing = $this->assignments->entryLineDimensions($this->supplierId, $closingId);
        $frozenOpening = $this->assignments->entryLineDimensions($this->supplierId, $openingId);
        self::assertContains([$this->projectType => $a], $frozenClosing);
        self::assertContains([$this->projectType => $a], $frozenOpening);
        $lineId = (int) array_key_first($source);
        foreach ([
            fn () => $this->dimensions->saveEntryLines($this->supplierId, $entryId, [$lineId => [$this->projectType => $b]]),
            fn () => $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->projectType => $b], null),
            fn () => $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->projectType => $a], [1 => [$this->projectType => $b]]),
            fn () => $this->dimensions->saveEntryLines($this->supplierId, $entryId, [], [$lineId => [$this->projectType => [['value_id' => $a, 'share' => 0.5], ['value_id' => $b, 'share' => 0.5]]]]),
        ] as $change) {
            try {
                $change();
                self::fail('Změna zdrojové dimenze po uzavření poruší návaznost.');
            } catch (DimensionException $e) {
                self::assertSame('period_not_open', $e->errorCode);
            }
            self::assertSame($source, $this->assignments->entryLineDimensions($this->supplierId, $entryId));
            self::assertSame($frozenClosing, $this->assignments->entryLineDimensions($this->supplierId, $closingId));
            self::assertSame($frozenOpening, $this->assignments->entryLineDimensions($this->supplierId, $openingId));
            $balance = $this->db->pdo()->prepare(
                "SELECT SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END)
                   FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id
                   JOIN journal_entry_line_dimensions d ON d.line_id = l.id
                  WHERE l.supplier_id = ? AND l.entry_id = ? AND a.account_code = '321'
                    AND d.dimension_type_id = ? AND d.dimension_value_id = ?"
            );
            foreach ([$entryId => -1210.0, $closingId => 1210.0, $openingId => -1210.0] as $id => $expected) {
                $balance->execute([$this->supplierId, $id, $this->projectType, $a]);
                self::assertSame($expected, (float) $balance->fetchColumn());
            }
        }
        $this->periods->setStatus($this->periodId, $this->supplierId, 'closing');
        try {
            $this->dimensions->saveEntryLines($this->supplierId, $entryId, [$lineId => [$this->projectType => $b]]);
            self::fail('Existující závěrkový zápis musí chránit zdrojové zůstatky i ve stavu closing.');
        } catch (DimensionException $e) {
            self::assertSame('period_not_open', $e->errorCode);
        }
    }

    public function testSplitOnLockedDateIsRefusedAtomically(): void
    {
        $a = $this->value($this->projectType, 'L-A');
        $b = $this->value($this->projectType, 'L-B');
        $purchase = $this->purchase('LOCK-SPLIT', [[600.00, 126.00], [400.00, 84.00]]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->projectType => $a], []);
        $entryId = $this->postPurchase($purchase);
        $this->lockUntil(self::YEAR . '-06-30');
        $dimsBefore = $this->assignments->entryLineDimensions($this->supplierId, $entryId);

        try {
            $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [], [
                1 => [$this->projectType => $a],
                2 => [$this->projectType => $b],
            ]);
            self::fail('Rozdělení řádku v zamčeném datu musí být odmítnuto.');
        } catch (DimensionException $e) {
            self::assertSame('split_in_locked_period', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
            self::assertStringContainsString('uzavřeném nebo zamčeném období', $e->getMessage());
        }

        self::assertSame(
            ['header' => [$this->projectType => $a], 'items' => []],
            $this->assignments->documentDimensions($this->supplierId, 'purchase_invoice', $purchase),
            'Odmítnuté uložení nezmění dimenze dokladu.',
        );
        self::assertSame($dimsBefore, $this->assignments->entryLineDimensions($this->supplierId, $entryId));

        // Stejné položky se shodnou dimenzí řádek dělit nepotřebují — to projde.
        $same = $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [], [
            1 => [$this->projectType => $b],
            2 => [$this->projectType => $b],
        ]);
        self::assertFalse($same['restamp']['needs_repost']);
    }

    public function testSplitOnLockedDateIsRefusedButOpenPeriodOnlyWarns(): void
    {
        $a = $this->value($this->projectType, 'D-A');
        $b = $this->value($this->projectType, 'D-B');
        $items = [1 => [$this->projectType => $a], 2 => [$this->projectType => $b]];

        $open = $this->purchase('OPEN-SPLIT', [[300.00, 63.00], [700.00, 147.00]]);
        $this->postPurchase($open);
        $result = $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $open, [], $items);
        self::assertTrue($result['restamp']['needs_repost'], 'V otevřeném období jen upozorní — přeúčtování řádek rozdělí.');
        self::assertFalse($result['restamp']['locked']);

        $locked = $this->purchase('DATE-SPLIT', [[300.00, 63.00], [700.00, 147.00]]);
        $this->postPurchase($locked);
        $this->lockUntil(self::YEAR . '-06-30');
        $this->expectExceptionObject(new DimensionException('split_in_locked_period', DimensionService::SPLIT_LOCKED_MESSAGE, 409));
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $locked, [], $items);
    }

    public function testPreviewShowsResultingLineDimensionsWithoutSaving(): void
    {
        $from = $this->value($this->projectType, 'PV-FROM');
        $to = $this->value($this->projectType, 'PV-TO');
        $b = $this->value($this->projectType, 'PV-B');
        $purchase = $this->purchase('PREVIEW', [[1_000.00, 210.00], [500.00, 105.00]]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->projectType => $from], []);
        $entryId = $this->postPurchase($purchase);
        $this->db->pdo()->prepare('UPDATE journal_entry_lines SET is_red_storno = 1 WHERE entry_id = ? AND supplier_id = ?')->execute([$entryId, $this->supplierId]);
        $this->lockUntil(self::YEAR . '-06-30');
        $dimsBefore = $this->assignments->entryLineDimensions($this->supplierId, $entryId);

        $preview = $this->dimensions->previewDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->projectType => $to], null);
        self::assertFalse($preview['refused']);
        self::assertNotEmpty($preview['lines']);
        foreach ($preview['lines'] as $line) {
            self::assertSame($entryId, $line['entry_id']);
            self::assertSame([$this->projectType => $to], $line['dimensions']);
            self::assertNotNull($line['account_code']);
            self::assertTrue($line['is_red_storno'] ?? false);
            self::assertLessThan(0, \MyInvoice\Service\Accounting\JournalLineAmount::signed($line));
        }

        $refused = $this->dimensions->previewDocument($this->supplierId, 'purchase_invoice', $purchase, [], [
            1 => [$this->projectType => $to],
            2 => [$this->projectType => $b],
        ]);
        self::assertTrue($refused['refused'], 'Náhled ohlásí, že by uložení bylo odmítnuto.');

        self::assertSame($dimsBefore, $this->assignments->entryLineDimensions($this->supplierId, $entryId), 'Náhled nic neuloží.');
        self::assertSame(
            ['header' => [$this->projectType => $from], 'items' => []],
            $this->assignments->documentDimensions($this->supplierId, 'purchase_invoice', $purchase),
        );
    }

    public function testHeaderOnlySaveKeepsItemDimensionsAndIsAudited(): void
    {
        $a = $this->value($this->projectType, 'H-A');
        $center = $this->value($this->centerType, 'H-CC');
        $purchase = $this->purchase('HDR-ONLY', [[900.00, 189.00]]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [], [1 => [$this->projectType => $a]]);
        $this->postPurchase($purchase);
        $this->lockUntil(self::YEAR . '-06-30');

        $res = $this->call($this->dimensionAction, 'saveDocument', 'PUT',
            ['doc' => 'purchase-invoices', 'id' => (string) $purchase],
            ['header' => [$this->centerType => $center]]);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_THROW_ON_ERROR));
        self::assertSame(
            ['header' => [$this->centerType => $center], 'items' => [1 => [$this->projectType => $a]]],
            $this->assignments->documentDimensions($this->supplierId, 'purchase_invoice', $purchase),
            'Panel na detailu posílá jen hlavičku — dimenze položek z editoru zůstanou.',
        );

        $log = $this->db->pdo()->prepare(
            "SELECT user_id, payload FROM activity_log
              WHERE supplier_id = ? AND action = 'dimension.document_updated' AND entity_id = ?
              ORDER BY id DESC LIMIT 1"
        );
        $log->execute([$this->supplierId, $purchase]);
        $row = $log->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row, 'Změna dimenzí zaúčtovaného dokladu je v auditním logu.');
        self::assertSame($this->userId, (int) $row['user_id']);
        $payload = json_decode((string) $row['payload'], true);
        self::assertTrue($payload['locked_period']);
        self::assertGreaterThan(0, $payload['restamped_lines']);
    }

    public function testRepostStaysBlockedAndDoesNotSaveDimensions(): void
    {
        $from = $this->value($this->projectType, 'B-FROM');
        $to = $this->value($this->projectType, 'B-TO');
        $purchase = $this->purchase('BLOCKED', [[2_000.00, 420.00]]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->projectType => $from], []);
        $entryId = $this->postPurchase($purchase);
        $this->periods->setStatus($this->periodId, $this->supplierId, 'closed');
        // Zámek až po dnešek: opravu přeúčtováním není kam zapsat (strategie blocked).
        $this->lockUntil(date('Y-m-d'));
        $linesBefore = $this->linesOf($entryId);

        $res = $this->call($this->journalAction, 'repost', 'POST',
            ['source' => 'purchase-invoices', 'id' => (string) $purchase],
            [
                'lines' => $this->repostLines($entryId),
                'dimensions' => ['header' => [$this->projectType => $to]],
            ]);
        self::assertSame(409, $res['status']);
        self::assertContains($res['body']['error']['code'], ['period_not_open', 'date_locked']);
        self::assertSame($linesBefore, $this->linesOf($entryId));
        self::assertSame(
            [$this->projectType => $from],
            $this->assignments->documentDimensions($this->supplierId, 'purchase_invoice', $purchase)['header'],
            'Odmítnuté přeúčtování neuloží ani dimenze z dialogu.',
        );

        $delete = $this->call($this->journalAction, 'delete', 'DELETE', ['id' => (string) $entryId]);
        self::assertSame(409, $delete['status'], 'Smazat zápis v uzavřeném období dál nejde.');

        // Uzavřené období chrání i samotné dimenze.
        $dimsOnly = $this->call($this->dimensionAction, 'saveDocument', 'PUT',
            ['doc' => 'purchase-invoices', 'id' => (string) $purchase],
            ['header' => [$this->projectType => $to]]);
        self::assertSame(409, $dimsOnly['status']);
        self::assertSame($linesBefore, $this->linesOf($entryId));
    }

    public function testAmountAndDimensionChangesInClosedPeriodAreRefused(): void
    {
        $from = $this->value($this->projectType, 'A-FROM');
        $to = $this->value($this->projectType, 'A-TO');
        $purchase = $this->purchase('AMOUNT', [[3_000.00, 630.00]]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->projectType => $from], []);
        $entryId = $this->postPurchase($purchase);
        $this->periods->setStatus($this->periodId, $this->supplierId, 'closed');
        $this->lockUntil(date('Y-m-d'));
        $linesBefore = $this->linesOf($entryId);
        $entriesBefore = $this->entriesOf($purchase);

        $changed = array_map(static function (array $l): array {
            if (str_starts_with($l['account_code'], '5') || str_starts_with($l['account_code'], '321')) {
                $l['amount'] += 100.0;
            }
            return $l;
        }, $this->repostLines($entryId));
        $res = $this->call($this->journalAction, 'repost', 'POST',
            ['source' => 'purchase-invoices', 'id' => (string) $purchase],
            ['lines' => $changed, 'dimensions' => ['header' => [$this->projectType => $to]]]);
        self::assertGreaterThanOrEqual(400, $res['status'], 'Změna částek v uzavřeném období neprojde.');
        self::assertSame($linesBefore, $this->linesOf($entryId));
        self::assertSame($entriesBefore, $this->entriesOf($purchase));
        self::assertSame(
            [$this->projectType => $from],
            $this->assignments->documentDimensions($this->supplierId, 'purchase_invoice', $purchase)['header'],
        );

        $dimsOnly = $this->call($this->dimensionAction, 'saveDocument', 'PUT',
            ['doc' => 'purchase-invoices', 'id' => (string) $purchase],
            ['header' => [$this->projectType => $to]]);
        self::assertSame(409, $dimsOnly['status'], json_encode($dimsOnly['body'], JSON_THROW_ON_ERROR));
        self::assertSame($linesBefore, $this->linesOf($entryId), 'Samotné dimenze nemění účet, stranu ani částku.');
        self::assertSame($entriesBefore, $this->entriesOf($purchase), 'Ani storno, ani nový zápis.');
        foreach ($this->assignments->entryLineDimensions($this->supplierId, $entryId) as $dims) {
            self::assertSame([$this->projectType => $from], $dims);
        }
    }

    public function testRepostSavesDimensionsAndSplitsInOpenPeriod(): void
    {
        $a = $this->value($this->projectType, 'R-A');
        $b = $this->value($this->projectType, 'R-B');
        $purchase = $this->purchase('REPOST-DIM', [[600.00, 126.00], [400.00, 84.00]]);
        $entryId = $this->postPurchase($purchase);

        $res = $this->call($this->journalAction, 'repost', 'POST',
            ['source' => 'purchase-invoices', 'id' => (string) $purchase],
            [
                'lines' => $this->repostLines($entryId),
                'dimensions' => ['header' => [], 'items' => [1 => [$this->projectType => $a], 2 => [$this->projectType => $b]]],
            ]);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_THROW_ON_ERROR));
        self::assertSame('replace', $res['body']['repost']['strategy']);
        $newEntry = (int) $res['body']['repost']['entry_id'];

        $byProject = [];
        $dims = $this->assignments->entryLineDimensions($this->supplierId, $newEntry);
        foreach ($this->linesOf($newEntry) as $lineId => $line) {
            if (str_starts_with((string) $line['account_code'], '5')) {
                $valueId = $dims[$lineId][$this->projectType] ?? 0;
                $byProject[$valueId] = ($byProject[$valueId] ?? 0) + (int) round($line['amount'] * 100);
            }
        }
        self::assertSame([$a => 60_000, $b => 40_000], $byProject, 'Přeúčtování rozdělí náklad podle dimenzí položek.');

        $log = $this->db->pdo()->prepare(
            "SELECT payload FROM activity_log WHERE supplier_id = ? AND action = 'dimension.document_updated' AND entity_id = ?
              ORDER BY id DESC LIMIT 1"
        );
        $log->execute([$this->supplierId, $purchase]);
        self::assertSame('repost', json_decode((string) $log->fetchColumn(), true)['via'] ?? null);
    }

    public function testRepostByReversalCarriesNotesToNewEntry(): void
    {
        $purchase = $this->purchase('REPOST-NOTE', [[500.00, 105.00]]);
        $entryId = $this->postPurchase($purchase);
        $notes = new \MyInvoice\Repository\JournalEntryNoteRepository($this->db);
        $notes->add($entryId, $this->supplierId, 'Poznámka k případu', true, $this->userId);
        $gone = $notes->add($entryId, $this->supplierId, 'Smazaná', false, $this->userId);
        $notes->softDelete($gone, $entryId, $this->supplierId, $this->userId);
        $this->periods->setStatus($this->periodId, $this->supplierId, 'closed');
        // Storno a nový zápis jdou do dnešního data; v reálu je dnešní období otevřené.
        // Testovací firma má jen vzdálené období, dnešní rok by byl „před začátkem".
        if ($this->periods->findForDate($this->supplierId, date('Y-m-d')) === null) {
            $this->periods->create($this->supplierId, (int) date('Y'), date('Y') . '-01-01', date('Y') . '-12-31');
        }

        $res = $this->call($this->journalAction, 'repost', 'POST',
            ['source' => 'purchase-invoices', 'id' => (string) $purchase],
            ['lines' => $this->repostLines($entryId), 'confirm_date_shift' => true]);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_THROW_ON_ERROR));
        self::assertSame('reverse', $res['body']['repost']['strategy']);
        $newEntry = (int) $res['body']['repost']['entry_id'];
        self::assertNotSame($entryId, $newEntry);

        $carried = $notes->list($newEntry, $this->supplierId);
        self::assertSame(['Poznámka k případu'], array_column($carried, 'body'), 'Živá poznámka jde s novým zápisem, smazaná ne.');
        self::assertTrue($carried[0]['pinned']);
        self::assertCount(1, $notes->list($entryId, $this->supplierId), 'Stornovaný zápis si poznámku nechává.');
    }

    public function testForeignTenantCannotChangeOrPreviewDimensions(): void
    {
        $project = $this->value($this->projectType, 'T-OWN');
        $purchase = $this->purchase('TENANT', [[100.00, 21.00]]);
        $this->postPurchase($purchase);

        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES ("Cizí tenant s.r.o.", "Testovací 1", "Praha", "11000", ?, ?, ?, ?, "double_entry")'
        )->execute([$this->czId, 'foreign-' . uniqid() . '@example.invalid', $this->currencyId, $this->vatRateId]);
        $foreign = (int) $pdo->lastInsertId();
        $this->dimensions->setEnabled($foreign, true);

        foreach (['saveDocument', 'previewDocument'] as $method) {
            try {
                $this->dimensions->{$method}($foreign, 'purchase_invoice', $purchase, [], null);
                self::fail($method . ': cizí firma nesmí sáhnout na doklad.');
            } catch (DimensionException $e) {
                self::assertSame(404, $e->httpStatus, $method);
            }
        }
        try {
            $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->projectType => $project + 999_999], null);
            self::fail('Neznámá hodnota dimenze musí být odmítnuta.');
        } catch (DimensionException $e) {
            self::assertSame('invalid_dimension', $e->errorCode);
        }
        self::assertSame([], $this->assignments->documentDimensions($this->supplierId, 'purchase_invoice', $purchase)['header']);
    }

    // ── pomocné ──────────────────────────────────────────────────────────────

    private function lockUntil(?string $date): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)'
        )->execute([$this->supplierId, $date]);
    }

    /**
     * @param array<string,string> $args
     * @param array<string,mixed> $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function call(object $action, string $method, string $httpMethod, array $args, array $body = []): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest($httpMethod, '/api/accounting')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant']);
        if ($body !== []) {
            $req = $req->withParsedBody($body);
        }
        $resp = $action->{$method}($req, new Psr7Response(), $args);
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    /** @return list<array{account_code:string, side:string, amount:float}> */
    private function repostLines(int $entryId): array
    {
        return array_values(array_map(static fn (array $l): array => [
            'account_code' => (string) $l['account_code'],
            'side' => (string) $l['side'],
            'amount' => (float) $l['amount'],
        ], $this->linesOf($entryId)));
    }

    /** @return array<int,array<string,mixed>> */
    private function linesOf(int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT l.id, l.line_no, l.account_id, a.account_code, l.side, l.amount, l.currency_code, l.cost_center, l.project_id
               FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND l.entry_id = ? ORDER BY l.line_no, l.id'
        );
        $stmt->execute([$this->supplierId, $entryId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['id']] = $row;
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function entriesOf(int $purchaseId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, entry_date, period_id, document_no, description, reversed_by
               FROM journal_entries WHERE supplier_id = ? AND (source_type = 'purchase_invoice' AND source_id = ? OR source_id IS NULL AND entry_date >= ?)
              ORDER BY id"
        );
        $stmt->execute([$this->supplierId, $purchaseId, self::YEAR . '-01-01']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function value(int $typeId, string $code): int
    {
        return (int) $this->dimensions->createValue($this->supplierId, $typeId, ['code' => $code, 'name' => 'Hodnota ' . $code])['id'];
    }

    private function postPurchase(int $purchaseId): int
    {
        return $this->posting->postDocument(
            $this->supplierId,
            'purchase_invoice',
            $purchaseId,
            $this->posting->buildFromPurchaseInvoice($this->supplierId, $purchaseId),
            ['entry_date' => self::YEAR . '-06-15', 'posted_by' => $this->userId],
        );
    }

    /** @param list<array{0:float,1:float}> $items základ a DPH položek */
    private function purchase(string $number, array $items): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                                  language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "CZ12345678", "dodavatel@example.invalid", "cs", ?, 0, 1)'
        )->execute([$this->supplierId, 'Dodavatel ' . $number, $this->czId, $this->currencyId]);
        $vendorId = (int) $pdo->lastInsertId();
        $base = array_sum(array_column($items, 0));
        $vat = array_sum(array_column($items, 1));
        $issue = self::YEAR . '-06-15';
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date, due_date,
                 received_at, currency_id, reverse_charge, vendor_snapshot, total_without_vat, total_vat,
                 total_with_vat, status, vat_classification_code, vat_deduction, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, 0, "{}", ?, ?, ?, "received", "40", "full", ?)'
        )->execute([
            $this->supplierId, $vendorId, $number, $issue, $issue, $issue, $issue, $this->currencyId,
            $base, $vat, $base + $vat, $this->userId,
        ]);
        $id = (int) $pdo->lastInsertId();
        foreach ($items as $i => [$itemBase, $itemVat]) {
            $pdo->prepare(
                "INSERT INTO purchase_invoice_items
                    (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                     vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
                 VALUES (?, 'Položka', 1, 'ks', ?, ?, 21.00, ?, ?, ?, ?)"
            )->execute([$id, $itemBase, $this->vatRateId, $itemBase, $itemVat, $itemBase + $itemVat, $i]);
        }
        return $id;
    }
}
