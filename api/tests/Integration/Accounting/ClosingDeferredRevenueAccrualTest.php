<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\PostingService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Časové rozlišení výnosů příštích období (384) z řádků vydaných faktur označených obdobím
 * od–do. Zrcadlo {@see ClosingPrepaidExpenseAccrualTest}: pro-rata dle dnů, jen faktury
 * zaúčtované v období, výnosový účet ze zápisu faktury, idempotentní MD 6xx / D 384,
 * rozpuštění v N+1 a dopad do VH. Izolovaný supplier v transakci s rollbackem.
 */
#[Group('integration')]
final class ClosingDeferredRevenueAccrualTest extends TestCase
{
    // 2091 = uzavírané období (365 dnů); předplatné přesahuje do 2092 (přestupný, 366 dnů).
    private const YEAR = 2091;
    private const STARTS_ON = self::YEAR . '-01-01';
    private const ENDS_ON = self::YEAR . '-12-31';

    private Connection $db;
    private PostingService $posting;
    private ClosingService $closing;
    private AccountingPeriodRepository $periods;
    private JournalEntryRepository $journal;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $czId = 0;
    private int $clientId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db      = $container->get(Connection::class);
            $this->posting = $container->get(PostingService::class);
            $this->closing = $container->get(ClosingService::class);
            $this->periods = $container->get(AccountingPeriodRepository::class);
            $this->journal = $container->get(JournalEntryRepository::class);
            $seeder        = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, ?, ?, ?)'
        )->execute(['Výnosy příštích období test s.r.o.', $this->czId, 'deferred-revenue@example.com', $this->currencyId, $this->vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::STARTS_ON, self::ENDS_ON);

        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "Odběratel předplatného", "Test 1", "Praha", "11000", ?, "customer@example.com", "cs", ?, 1, 0)'
        )->execute([$this->supplierId, $this->czId, $this->currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();
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

    public function testPreviewProRataAcrossYearEnd(): void
    {
        // Roční předplatné 36 600 Kč bez DPH, 1. 7. 2091 – 30. 6. 2092 (366 dnů). Za koncem
        // roku leží 1. 1. – 30. 6. 2092 = 182 dnů → 36 600 × 182/366 = 18 200 Kč na 384.
        $invoiceId = $this->invoice('20910001', 36600.00, self::YEAR . '-07-01');
        $this->item($invoiceId, 36600.00, '2091-07-01', '2092-06-30');
        $this->postInvoice($invoiceId, 36600.00, self::YEAR . '-07-01');

        $preview = $this->closing->deferredRevenueAccrualPreview($this->supplierId, $this->periodId);

        self::assertCount(1, $preview['items']);
        $item = $preview['items'][0];
        self::assertSame(366, $item['total_days']);
        self::assertSame(182, $item['deferred_days']);
        self::assertSame('602', $item['debit_account']);
        self::assertEqualsWithDelta(18200.00, (float) $item['deferred_amount'], 0.001);
        self::assertEqualsWithDelta(18200.00, (float) $preview['by_account']['602'], 0.001);
        self::assertSame('20910001', $preview['documents'][0]['invoice_number']);
    }

    public function testPreviewTakesOnlyInvoicesPostedInPeriod(): void
    {
        // Nezaúčtovaná faktura výnos ve VH nemá → nic se neodkládá. Řádek bez období
        // a období končící v roce se také ignorují.
        $unposted = $this->invoice('20910002', 12000.00, self::YEAR . '-12-01');
        $this->item($unposted, 12000.00, '2091-12-01', '2092-11-30');
        $mixed = $this->invoice('20910003', 5000.00, self::YEAR . '-06-01');
        $this->item($mixed, 3000.00, null, null);
        $this->item($mixed, 2000.00, '2091-06-01', '2091-12-31');
        $this->postInvoice($mixed, 5000.00, self::YEAR . '-06-01');

        $preview = $this->closing->deferredRevenueAccrualPreview($this->supplierId, $this->periodId);

        self::assertSame([], $preview['items']);
        self::assertEqualsWithDelta(0.0, (float) $preview['total'], 0.001);
    }

    public function testDeferralUsesRevenueAccountOfPostedEntry(): void
    {
        // Faktura ručně zaúčtovaná na 604 (jiná předkontace než výchozí 602) → odklad z 604.
        $invoiceId = $this->invoice('20910004', 36600.00, self::YEAR . '-07-01');
        $this->item($invoiceId, 36600.00, '2091-07-01', '2092-06-30');
        $this->postInvoice($invoiceId, 36600.00, self::YEAR . '-07-01', '604');

        $preview = $this->closing->deferredRevenueAccrualPreview($this->supplierId, $this->periodId);

        self::assertSame('604', $preview['items'][0]['debit_account']);
        self::assertEqualsWithDelta(18200.00, (float) $preview['by_account']['604'], 0.001);
    }

    public function testRebilledItemOnBalanceSheetAccountIsNotDeferred(): void
    {
        // Přefakturace na 378 s obdobím přes konec roku není výnos, uzávěrka ji neodkládá
        // ani tehdy, když doklad nese jediný výnosový účet.
        $invoiceId = $this->invoice('20910012', 73200.00, self::YEAR . '-07-01');
        $this->item($invoiceId, 36600.00, '2091-07-01', '2092-06-30');
        $rebillId = $this->item($invoiceId, 36600.00, '2091-07-01', '2092-06-30');
        $this->db->pdo()->prepare('UPDATE invoice_items SET revenue_account_code = ? WHERE id = ?')->execute(['378', $rebillId]);
        $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => 73200.00],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 36600.00],
            ['account_code' => '378', 'side' => 'credit', 'amount' => 36600.00],
        ], ['entry_date' => self::YEAR . '-07-01', 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        $preview = $this->closing->deferredRevenueAccrualPreview($this->supplierId, $this->periodId);

        self::assertCount(1, $preview['items']);
        self::assertEqualsWithDelta(18200.00, (float) $preview['total'], 0.001);
        self::assertSame(['602'], array_map('strval', array_keys($preview['by_account'])));
    }

    public function testRunPostsDeferralIdempotentlyAndCreditNoteReducesIt(): void
    {
        $invoiceId = $this->invoice('20910005', 36600.00, self::YEAR . '-07-01');
        $itemId = $this->item($invoiceId, 36600.00, '2091-07-01', '2092-06-30');
        $this->postInvoice($invoiceId, 36600.00, self::YEAR . '-07-01');
        // Dobropis zaúčtovaný před uzávěrkou; období dostane až po prvním běhu.
        $creditNoteId = $this->invoice('20910006', -18300.00, self::YEAR . '-08-01', 'credit_note', $invoiceId);
        $creditItemId = $this->item($creditNoteId, -18300.00, null, null);
        $this->postInvoice($creditNoteId, -18300.00, self::YEAR . '-08-01');
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        $first =$this->closing->runDeferredRevenueAccrual($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        self::assertEqualsWithDelta(18200.00, (float) $first['total'], 0.001);
        $entry = $this->journal->findBySource($this->supplierId, 'deferred_revenue_accrual', ClosingSourceId::deferredRevenueAccrual($this->periodId));
        self::assertNotNull($entry);
        $lines = $this->entryLines((int) $entry['id']);
        self::assertEqualsWithDelta(18200.00, $this->sideAmount($lines, '602', 'debit'), 0.001);
        self::assertEqualsWithDelta(18200.00, $this->sideAmount($lines, '384', 'credit'), 0.001);
        self::assertEqualsWithDelta(0.0, $this->balance($lines), 0.001);
        self::assertSame(self::ENDS_ON, (string) $entry['entry_date']);

        $this->closing->runDeferredRevenueAccrual($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $again = $this->journal->findBySource($this->supplierId, 'deferred_revenue_accrual', ClosingSourceId::deferredRevenueAccrual($this->periodId));
        self::assertSame((int) $entry['id'], (int) $again['id'], 'Opakované spuštění nesmí založit druhý zápis.');

        // Dobropis na polovinu předplatného se stejným obdobím odklad sníží na polovinu.
        $this->db->pdo()->prepare('UPDATE invoice_items SET accrual_from = ?, accrual_to = ? WHERE id = ?')
            ->execute(['2091-07-01', '2092-06-30', $creditItemId]);
        $reduced = $this->closing->runDeferredRevenueAccrual($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        self::assertEqualsWithDelta(9100.00, (float) $reduced['total'], 0.001);

        // Zrušení období na obou řádcích → nulový návrh maže zápis.
        $this->db->pdo()->prepare('UPDATE invoice_items SET accrual_from = NULL, accrual_to = NULL WHERE invoice_id IN (?, ?)')
            ->execute([$invoiceId, $creditNoteId]);
        $this->closing->runDeferredRevenueAccrual($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        self::assertNull($this->journal->findBySource($this->supplierId, 'deferred_revenue_accrual', ClosingSourceId::deferredRevenueAccrual($this->periodId)));
        self::assertGreaterThan(0, $itemId);
    }

    public function testDeferralLowersProfitAndIsReleasedOnOpenNext(): void
    {
        $invoiceId = $this->invoice('20910007', 36600.00, self::YEAR . '-07-01');
        $this->item($invoiceId, 36600.00, '2091-07-01', '2092-06-30');
        $this->postInvoice($invoiceId, 36600.00, self::YEAR . '-07-01');

        $vhBefore = $this->profitBeforeTax();
        $this->driveCloseWithAccrual();
        self::assertEqualsWithDelta(-18200.00, $this->profitBeforeTax() - $vhBefore, 0.001, 'Odklad výnosu sníží VH o odloženou část.');

        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $open = $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        self::assertNotNull($open['deferred_revenue_release_entry_id']);
        $release = $this->journal->findBySource($this->supplierId, 'deferred_revenue_accrual', ClosingSourceId::deferredRevenueAccrualRelease($this->periodId));
        self::assertNotNull($release);
        $lines = $this->entryLines((int) $release['id']);
        self::assertEqualsWithDelta(18200.00, $this->sideAmount($lines, '384', 'debit'), 0.001);
        self::assertEqualsWithDelta(18200.00, $this->sideAmount($lines, '602', 'credit'), 0.001);
        self::assertSame((self::YEAR + 1) . '-01-01', (string) $release['entry_date']);
        self::assertEqualsWithDelta(0.0, $this->balance384AsOf('2092-12-31'), 0.005, '384 se jednoletým předplatným rozpustí celý.');
    }

    /**
     * Firma bez dimenzí: odklad i rozpuštění přes veřejné openNext mají jeden řádek na účet
     * a žádné dimenze — stejně jako před přenosem dimenzí.
     */
    public function testWithoutDimensionsEntriesKeepOneLinePerAccount(): void
    {
        $invoiceId = $this->invoice('20910020', 36600.00, self::YEAR . '-07-01');
        $this->item($invoiceId, 36600.00, '2091-07-01', '2092-06-30');
        $this->postInvoice($invoiceId, 36600.00, self::YEAR . '-07-01');

        $this->driveCloseWithAccrual();
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        foreach ([ClosingSourceId::deferredRevenueAccrual($this->periodId), ClosingSourceId::deferredRevenueAccrualRelease($this->periodId)] as $sourceId) {
            $entry = $this->journal->findBySource($this->supplierId, 'deferred_revenue_accrual', $sourceId);
            self::assertNotNull($entry);
            $codes = array_map(static fn (array $l): string => (string) $l['account_code'], $this->entryLines((int) $entry['id']));
            sort($codes);
            self::assertSame(['384', '602'], $codes);
            self::assertSame([], (new DimensionAssignmentRepository($this->db))->entryLineDimensions($this->supplierId, (int) $entry['id']));
        }
    }

    /**
     * Vydaná faktura se dvěma položkami na 602 a různými středisky (rozlišená jen jedna)
     * a dobropis s rozlišeným obdobím a třetím střediskem: odklad i rozpuštění v openNext
     * jdou po střediscích, dobropis na opačné straně, a 384 se vyrovná.
     */
    public function testOpenNextReleasesPerItemDimensionAndCreditNoteFlipsSide(): void
    {
        $dimensions = Bootstrap::buildContainer()->get(DimensionService::class);
        $dimensions->setEnabled($this->supplierId, true);
        $center = (int) $dimensions->ensureDefaultTypes($this->supplierId, ['stredisko'])['cost_center'];
        $a = (int) $dimensions->createValue($this->supplierId, $center, ['code' => 'S-A', 'name' => 'Středisko A'])['id'];
        $b = (int) $dimensions->createValue($this->supplierId, $center, ['code' => 'S-B', 'name' => 'Středisko B'])['id'];
        $c = (int) $dimensions->createValue($this->supplierId, $center, ['code' => 'S-C', 'name' => 'Středisko C'])['id'];

        $invoiceId = $this->invoice('20910021', 46600.00, self::YEAR . '-07-01');
        $this->item($invoiceId, 36600.00, '2091-07-01', '2092-06-30');
        $this->item($invoiceId, 10000.00, null, null);
        $dimensions->saveDocument($this->supplierId, 'invoice', $invoiceId, [], [1 => [$center => $a], 2 => [$center => $b]]);
        $this->postBuilt($invoiceId, self::YEAR . '-07-01');
        $creditId = $this->invoice('20910022', -18300.00, self::YEAR . '-08-01', 'credit_note', $invoiceId);
        $this->item($creditId, -18300.00, '2091-07-01', '2092-06-30');
        $dimensions->saveDocument($this->supplierId, 'invoice', $creditId, [$center => $c], []);
        $this->postBuilt($creditId, self::YEAR . '-08-01');

        $this->driveCloseWithAccrual();
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        $defer = $this->journal->findBySource($this->supplierId, 'deferred_revenue_accrual', ClosingSourceId::deferredRevenueAccrual($this->periodId));
        $release = $this->journal->findBySource($this->supplierId, 'deferred_revenue_accrual', ClosingSourceId::deferredRevenueAccrualRelease($this->periodId));
        self::assertNotNull($defer);
        self::assertNotNull($release);
        self::assertSame([
            '602|' . $a => ['debit', 1_820_000],
            '602|' . $c => ['credit', 910_000],
        ], $this->revenueByCenter((int) $defer['id'], $center), 'Odklad jen z rozlišených položek, dobropis na D.');
        self::assertSame([
            '602|' . $a => ['credit', 1_820_000],
            '602|' . $c => ['debit', 910_000],
        ], $this->revenueByCenter((int) $release['id'], $center), 'Rozpuštění zrcadlí odklad po středisku.');
        self::assertEqualsWithDelta(0.0, $this->balance384AsOf('2092-12-31'), 0.005);
    }

    /**
     * Faktura a dobropis se stejným obdobím a částkou, ale různým střediskem dají celkem nulu:
     * odklad i rozpuštění přes openNext přesto vzniknou po střediscích, bez řádku 384.
     */
    public function testZeroTotalStillDefersAndReleasesPerDimensionOnOpenNext(): void
    {
        [$center, $a, , $c] = $this->centers();
        $invoiceId = $this->invoice('20910023', 36600.00, self::YEAR . '-07-01');
        $this->item($invoiceId, 36600.00, '2091-07-01', '2092-06-30');
        $this->dimensionService()->saveDocument($this->supplierId, 'invoice', $invoiceId, [$center => $a], []);
        $this->postBuilt($invoiceId, self::YEAR . '-07-01');
        $creditId = $this->invoice('20910024', -36600.00, self::YEAR . '-08-01', 'credit_note', $invoiceId);
        $this->item($creditId, -36600.00, '2091-07-01', '2092-06-30');
        $this->dimensionService()->saveDocument($this->supplierId, 'invoice', $creditId, [$center => $c], []);
        $this->postBuilt($creditId, self::YEAR . '-08-01');

        $this->driveCloseWithAccrual();
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        foreach ([ClosingSourceId::deferredRevenueAccrual($this->periodId) => ['debit', 'credit'], ClosingSourceId::deferredRevenueAccrualRelease($this->periodId) => ['credit', 'debit']] as $sourceId => [$sideA, $sideC]) {
            $entry = $this->journal->findBySource($this->supplierId, 'deferred_revenue_accrual', $sourceId);
            self::assertNotNull($entry, 'Zápis vznikne i při nulovém součtu.');
            self::assertSame(['602|' . $a => [$sideA, 1_820_000], '602|' . $c => [$sideC, 1_820_000]], $this->revenueByCenter((int) $entry['id'], $center));
            self::assertEqualsWithDelta(0.0, $this->sideAmount($this->entryLines((int) $entry['id']), '384', 'credit') + $this->sideAmount($this->entryLines((int) $entry['id']), '384', 'debit'), 0.001);
        }
    }

    /**
     * Odklad zaúčtovaný uzávěrkou ještě bez dimenzí se mimo 504 rozpustí přes openNext také
     * bez dimenzí (zrcadlí odklad), i když doklad středisko nese.
     */
    public function testLegacyDeferralWithoutDimensionsIsReleasedWithoutThemOnOpenNext(): void
    {
        [$center, $a] = $this->centers();
        $invoiceId = $this->invoice('20910025', 36600.00, self::YEAR . '-07-01');
        $this->item($invoiceId, 36600.00, '2091-07-01', '2092-06-30');
        $this->dimensionService()->saveDocument($this->supplierId, 'invoice', $invoiceId, [$center => $a], []);
        $this->postBuilt($invoiceId, self::YEAR . '-07-01');

        $this->driveCloseWithAccrual();
        $defer = $this->journal->findBySource($this->supplierId, 'deferred_revenue_accrual', ClosingSourceId::deferredRevenueAccrual($this->periodId));
        self::assertNotNull($defer);
        $this->db->pdo()->prepare('DELETE d FROM journal_entry_line_dimensions d JOIN journal_entry_lines l ON l.id = d.line_id WHERE l.entry_id = ?')
            ->execute([(int) $defer['id']]);
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        $release = $this->journal->findBySource($this->supplierId, 'deferred_revenue_accrual', ClosingSourceId::deferredRevenueAccrualRelease($this->periodId));
        self::assertNotNull($release);
        self::assertSame(['602|-' => ['credit', 1_820_000]], $this->revenueByCenter((int) $release['id'], $center));
    }

    /** @return array{0:int,1:int,2:int,3:int} typ střediska a hodnoty A, B, C */
    private function centers(): array
    {
        $dimensions = $this->dimensionService();
        $dimensions->setEnabled($this->supplierId, true);
        $center = (int) $dimensions->ensureDefaultTypes($this->supplierId, ['stredisko'])['cost_center'];
        $out = [$center];
        foreach (['S-A', 'S-B', 'S-C'] as $code) {
            $out[] = (int) $dimensions->createValue($this->supplierId, $center, ['code' => $code, 'name' => 'Středisko ' . $code])['id'];
        }
        return $out;
    }

    private function dimensionService(): DimensionService
    {
        return Bootstrap::buildContainer()->get(DimensionService::class);
    }

    /**
     * Dávkové čtení dokladových dimenzí položek vydané faktury skládá vrstvy stejně jako
     * zaúčtování ({@see \MyInvoice\Service\Accounting\Dimension\DimensionStamper::itemDimensions()}):
     * textový řádek uprostřed se do pořadí položek počítá, slevový řádek také, položka bez
     * vlastní hodnoty dostane výchozí středisko odběratele.
     */
    public function testBatchItemDimensionsOfIssuedInvoiceMatchStamper(): void
    {
        [$center, $a, $b, $c] = $this->centers();
        (new \MyInvoice\Repository\DimensionDefaultRepository($this->db))->replace($this->supplierId, 'client', $this->clientId, [$center => $b]);
        $invoiceId = $this->invoice('20910026', 1500.00, self::YEAR . '-07-01');
        $first = $this->item($invoiceId, 1000.00, '2091-07-01', '2092-06-30');
        $text = $this->item($invoiceId, 0.00, null, null);
        $discount = $this->item($invoiceId, -100.00, null, null);
        $last = $this->item($invoiceId, 600.00, null, null);
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE invoice_items SET item_kind = 'text', description = 'Poznámka', order_index = 1 WHERE id = ?")->execute([$text]);
        $pdo->prepare("UPDATE invoice_items SET item_kind = 'discount', order_index = 2 WHERE id = ?")->execute([$discount]);
        $pdo->prepare('UPDATE invoice_items SET order_index = 3 WHERE id = ?')->execute([$last]);
        $this->dimensionService()->saveDocument($this->supplierId, 'invoice', $invoiceId, [], [1 => [$center => $a], 4 => [$center => $c]]);

        $batch = Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Closing\AccrualDeferralService::class)
            ->documentItemDimensions($this->supplierId, 'invoice', [$invoiceId]);

        $expected = [];
        foreach ((new \MyInvoice\Service\Accounting\Dimension\DimensionStamper($this->db))->itemDimensions($this->supplierId, 'invoice', $invoiceId) as $itemId => $line) {
            $expected[$itemId] = array_intersect_key($line, ['dimensions' => 1, 'dimension_splits' => 1]);
        }
        self::assertSame([$center => $a], $expected[$first]['dimensions'] ?? null, 'Fixture: první položka má vlastní středisko.');
        self::assertSame([$center => $c], $expected[$last]['dimensions'] ?? null, 'Fixture: čtvrtý řádek (po textu a slevě) má středisko C.');
        self::assertEquals($expected, $batch[$invoiceId] ?? null);
    }

    /** Faktura rozúčtovaná rovnou na 384 nese odklad sama, uzávěrka ji neodkládá podruhé. */
    public function testPreviewSkipsInvoiceAlreadyPostedTo384(): void
    {
        $invoiceId = $this->invoice('20910010', 36600.00, self::YEAR . '-07-01');
        $this->item($invoiceId, 36600.00, '2091-07-01', '2092-06-30');
        $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => 36600.00],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 18400.00],
            ['account_code' => '384', 'side' => 'credit', 'amount' => 18200.00],
        ], ['entry_date' => self::YEAR . '-07-01', 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        $plain = $this->invoice('20910011', 36600.00, self::YEAR . '-07-01');
        $this->item($plain, 36600.00, '2091-07-01', '2092-06-30');
        $this->postInvoice($plain, 36600.00, self::YEAR . '-07-01');

        $preview = $this->closing->deferredRevenueAccrualPreview($this->supplierId, $this->periodId);
        self::assertCount(1, $preview['items'], 'Odloží se jen faktura zaúčtovaná na výnos.');
        self::assertSame($plain, $preview['items'][0]['invoice_id']);
        self::assertEqualsWithDelta(18200.00, (float) $preview['total'], 0.001);
    }

    /**
     * Výnos odložený mimo uzávěrku (faktura rozúčtovaná rovnou na 384, rozpuštění ručním
     * zápisem v N+1) openNext nerozpouští podruhé.
     */
    public function testDeferralPostedOutsideClosingIsNotReleasedAgainOnOpenNext(): void
    {
        $this->periods->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
        $invoiceId = $this->invoice('20910009', 36600.00, self::YEAR . '-07-01');
        $this->item($invoiceId, 36600.00, '2091-07-01', '2092-06-30');
        $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => 36600.00],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 18400.00],
            ['account_code' => '384', 'side' => 'credit', 'amount' => 18200.00],
        ], ['entry_date' => self::YEAR . '-07-01', 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '384', 'side' => 'debit', 'amount' => 18200.00],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 18200.00],
        ], ['entry_date' => '2092-06-30', 'description' => 'Rozpuštění VPO', 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        $this->driveCloseWithAccrual(false);
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $open = $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        self::assertNull($open['deferred_revenue_release_entry_id'], 'Odklad mimo uzávěrku se rozpouští mimo openNext.');
        self::assertEqualsWithDelta(0.0, $this->balance384AsOf('2092-12-31'), 0.005, '384 se nesmí rozpustit dvakrát.');
    }

    public function testPriorReleaseProjectionCountsRevenueAsNegativeCost(): void
    {
        $invoiceId = $this->invoice('20910008', 36600.00, self::YEAR . '-07-01');
        $this->item($invoiceId, 36600.00, '2091-07-01', '2092-06-30');
        $this->postInvoice($invoiceId, 36600.00, self::YEAR . '-07-01');
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->runDeferredRevenueAccrual($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $nextId = $this->periods->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');

        $projection = $this->closing->priorDeferralReleaseProjection($this->supplierId, $nextId);

        self::assertTrue($projection['applicable']);
        self::assertEqualsWithDelta(18200.00, $projection['deferred_revenue'], 0.001);
        self::assertEqualsWithDelta(-18200.00, $projection['total'], 0.001);
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function invoice(string $number, float $base, string $date, string $type = 'invoice', ?int $parentId = null): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, parent_invoice_id, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat, paid_total, status,
                 vat_classification_code, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 0, ?, 0, "sent", "1", ?)'
        )->execute([
            $this->supplierId, $number, $type, $parentId, $this->clientId, $date, $date, $date,
            $this->currencyId, $base, $base, $this->userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function item(int $invoiceId, float $base, ?string $from, ?string $to): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index,
                 accrual_from, accrual_to)
             VALUES (?, "Roční předplatné", 1, "ks", ?, ?, 0, ?, 0, ?, 0, ?, ?)'
        )->execute([$invoiceId, $base, $this->vatRateId, $base, $base, $from, $to]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function postInvoice(int $invoiceId, float $base, string $date, string $revenueAccount = '602'): void
    {
        $credit = $base < 0;
        $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, [
            ['account_code' => '311', 'side' => $credit ? 'credit' : 'debit', 'amount' => abs($base)],
            ['account_code' => $revenueAccount, 'side' => $credit ? 'debit' : 'credit', 'amount' => abs($base)],
        ], ['entry_date' => $date, 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
    }

    /** Zaúčtuje fakturu skutečnou účtovací cestou (builder + razítkování dimenzí dokladu). */
    private function postBuilt(int $invoiceId, string $date): void
    {
        $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, $this->posting->buildFromInvoice($this->supplierId, $invoiceId), [
            'entry_date' => $date, 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId,
        ]);
    }

    /** @return array<string,array{0:string,1:int}> „6xx|středisko" => [strana, haléře] výnosových řádků zápisu */
    private function revenueByCenter(int $entryId, int $centerType): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT l.id, a.account_code, l.side, l.amount
               FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.entry_id = ? AND l.supplier_id = ? AND a.account_code LIKE \'6%\''
        );
        $stmt->execute([$entryId, $this->supplierId]);
        $dims = (new DimensionAssignmentRepository($this->db))->entryLineDimensions($this->supplierId, $entryId);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $line) {
            $out[$line['account_code'] . '|' . ($dims[(int) $line['id']][$centerType] ?? '-')] = [(string) $line['side'], (int) round((float) $line['amount'] * 100)];
        }
        ksort($out);
        return $out;
    }

    private function balance384AsOf(string $date): float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side='credit' THEN l.amount ELSE -l.amount END),0)
               FROM journal_entry_lines l
               JOIN journal_entries e   ON e.id = l.entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL
                AND a.account_code = '384' AND e.entry_date <= ?"
        );
        $stmt->execute([$this->supplierId, $date]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    private function driveCloseWithAccrual(bool $runAccrual = true): void
    {
        $sid = $this->supplierId;
        $pid = $this->periodId;
        $this->closing->start($sid, $pid, $this->rv(), $this->meta());
        $this->closing->runPrecheck($sid, $pid, $this->rv(), $this->meta());
        $this->closing->confirmStep($sid, $pid, 'depreciation', 'skipped', null, $this->rv(), $this->meta());
        $this->closing->runFxRevaluation($sid, $pid, [], $this->rv(), $this->meta());
        $this->closing->confirmStep($sid, $pid, 'estimates', 'skipped', null, $this->rv(), $this->meta());
        if ($runAccrual) {
            $this->closing->runDeferredRevenueAccrual($sid, $pid, $this->rv(), $this->meta());
        }
        $this->closing->confirmStep($sid, $pid, 'deferrals', 'done', null, $this->rv(), $this->meta());
        $this->closing->confirmStep($sid, $pid, 'provisions', 'skipped', null, $this->rv(), $this->meta());
        $this->closing->confirmStep($sid, $pid, 'income_tax', 'skipped', null, $this->rv(), $this->meta());
        $rv = $this->rv();
        $items = [];
        foreach ($this->closing->inventoryPreview($sid, $pid)['rows'] as $r) {
            $items[(int) $r['account_id']] = ['counted_balance' => (float) $r['book_balance'], 'resolution' => 'resolved', 'note' => null];
        }
        $this->closing->saveInventory($sid, $pid, $rv, ['complete' => true], $items, ['user_id' => $this->userId]);
    }

    /** Zrcadlí DppoReturnDataProvider::profitBeforeTax (VH před zdaněním, ř.10). */
    private function profitBeforeTax(): float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'credit' THEN l.amount ELSE -l.amount END), 0) AS vh
               FROM journal_entry_lines l
               JOIN journal_entries e   ON e.id = l.entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
                AND e.entry_date BETWEEN ? AND ?
                AND e.source_type <> 'closing'
                AND a.account_type IN ('revenue','expense')
                AND a.account_code NOT LIKE '59%'"
        );
        $stmt->execute([$this->supplierId, self::STARTS_ON, self::ENDS_ON]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    private function rv(): int
    {
        return (int) $this->periods->findById($this->supplierId, $this->periodId)['row_version'];
    }

    /** @return array{user_id:int, posted_by:int} */
    private function meta(): array
    {
        return ['user_id' => $this->userId, 'posted_by' => $this->userId];
    }

    /** @return list<array{account_code:string, side:string, amount:float}> */
    private function entryLines(int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.account_code, l.side, l.amount
               FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.entry_id = ? AND l.supplier_id = ?'
        );
        $stmt->execute([$entryId, $this->supplierId]);
        return array_map(static fn (array $r): array => [
            'account_code' => (string) $r['account_code'],
            'side' => (string) $r['side'],
            'amount' => (float) $r['amount'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param list<array{account_code:string, side:string, amount:float}> $lines */
    private function sideAmount(array $lines, string $code, string $side): float
    {
        $sum = 0.0;
        foreach ($lines as $l) {
            if ($l['account_code'] === $code && $l['side'] === $side) {
                $sum += $l['amount'];
            }
        }
        return round($sum, 2);
    }

    /** @param list<array{account_code:string, side:string, amount:float}> $lines */
    private function balance(array $lines): float
    {
        $sum = 0.0;
        foreach ($lines as $l) {
            $sum += $l['side'] === 'debit' ? $l['amount'] : -$l['amount'];
        }
        return round($sum, 2);
    }
}
