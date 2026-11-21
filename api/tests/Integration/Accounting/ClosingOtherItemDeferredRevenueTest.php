<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingException;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\OtherItemException;
use MyInvoice\Service\Accounting\OtherItemScheduleService;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Service\Accounting\Pair\JournalPairCorrectionService;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\InterimAccrualAdjustment;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Časové rozlišení výnosu z ostatní pohledávky (`other_items.accrual_from/to`): uzávěrka
 * odloží na 384 tu část výnosu zaúčtovaného zápisem pohledávky, která patří do příštích
 * období, a otevření dalšího roku ji rozpustí. Zrcadlo {@see ClosingDeferredRevenueAccrualTest}
 * pro vydané faktury. Izolovaný supplier v transakci s rollbackem.
 */
#[Group('integration')]
final class ClosingOtherItemDeferredRevenueTest extends TestCase
{
    // 2091 = uzavírané období (365 dnů); období výnosu přesahuje do 2092 (přestupný, 366 dnů).
    private const YEAR = 2091;
    private const STARTS_ON = self::YEAR . '-01-01';
    private const ENDS_ON = self::YEAR . '-12-31';

    private Connection $db;
    private ClosingService $closing;
    private AccountingPeriodRepository $periods;
    private JournalEntryRepository $journal;
    private OtherItemService $items;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
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
            $this->closing = $container->get(ClosingService::class);
            $this->periods = $container->get(AccountingPeriodRepository::class);
            $this->journal = $container->get(JournalEntryRepository::class);
            $this->items   = $container->get(OtherItemService::class);
            $seeder        = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $currencyId   = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId    = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId         = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $currencyId === 0 || $vatRateId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, ?, ?, ?, "double_entry")'
        )->execute(['Ostatní pohledávky rozlišení test s.r.o.', $czId, 'other-item-accrual@example.com', $currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::STARTS_ON, self::ENDS_ON);

        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "Syntetický nájemce", "Test 1", "Praha", "11000", ?, "tenant@example.com", "cs", ?, 1, 0)'
        )->execute([$this->supplierId, $czId, $currencyId]);
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

    public function testPreviewDefersRevenueOfPostedReceivable(): void
    {
        // Roční nájem 36 600 Kč, 1. 7. 2091 – 30. 6. 2092 (366 dnů). Za koncem roku leží
        // 1. 1. – 30. 6. 2092 = 182 dnů → 36 600 × 182/366 = 18 200 Kč na 384.
        $id = $this->receivable(36600.00, self::YEAR . '-07-01', '2091-07-01', '2092-06-30');

        $preview = $this->closing->deferredRevenueAccrualPreview($this->supplierId, $this->periodId);

        self::assertCount(1, $preview['items']);
        $item = $preview['items'][0];
        self::assertSame('other_item', $item['source']);
        self::assertSame($id, $item['other_item_id']);
        self::assertSame(366, $item['total_days']);
        self::assertSame(182, $item['deferred_days']);
        self::assertSame('602', $item['debit_account']);
        self::assertEqualsWithDelta(18200.00, (float) $item['deferred_amount'], 0.001);
        self::assertEqualsWithDelta(18200.00, (float) $preview['by_account']['602'], 0.001);
        self::assertSame($id, $preview['documents'][0]['other_item_id']);
        self::assertSame($this->documentNo($id), $preview['documents'][0]['invoice_number']);
    }

    public function testDraftAndItemWithoutPeriodAreNotDeferred(): void
    {
        $this->receivable(12000.00, self::YEAR . '-07-01', null, null);
        $this->receivable(12000.00, self::YEAR . '-07-01', '2091-07-01', '2092-06-30', post: false);

        $preview = $this->closing->deferredRevenueAccrualPreview($this->supplierId, $this->periodId);

        self::assertSame([], $preview['items']);
    }

    public function testDeferralLowersProfitAndIsReleasedOnOpenNext(): void
    {
        $this->receivable(36600.00, self::YEAR . '-07-01', '2091-07-01', '2092-06-30');

        $vhBefore = $this->profitBeforeTax();
        $this->driveCloseWithAccrual();
        self::assertEqualsWithDelta(-18200.00, $this->profitBeforeTax() - $vhBefore, 0.001, 'Odklad výnosu sníží VH o odloženou část.');

        $accrual = $this->journal->findBySource($this->supplierId, 'deferred_revenue_accrual', ClosingSourceId::deferredRevenueAccrual($this->periodId));
        self::assertNotNull($accrual);
        $lines = $this->entryLines((int) $accrual['id']);
        self::assertEqualsWithDelta(18200.00, $this->sideAmount($lines, '602', 'debit'), 0.001);
        self::assertEqualsWithDelta(18200.00, $this->sideAmount($lines, '384', 'credit'), 0.001);

        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $open = $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        self::assertNotNull($open['deferred_revenue_release_entry_id']);
        $release = $this->journal->findBySource($this->supplierId, 'deferred_revenue_accrual', ClosingSourceId::deferredRevenueAccrualRelease($this->periodId));
        self::assertNotNull($release);
        $lines = $this->entryLines((int) $release['id']);
        self::assertEqualsWithDelta(18200.00, $this->sideAmount($lines, '384', 'debit'), 0.001);
        self::assertEqualsWithDelta(18200.00, $this->sideAmount($lines, '602', 'credit'), 0.001);
        self::assertSame((self::YEAR + 1) . '-01-01', (string) $release['entry_date']);
    }

    public function testPeriodIsStoredAndReturned(): void
    {
        $id = $this->receivable(1200.00, self::YEAR . '-03-01', '2091-03-01', '2092-02-29', post: false);

        $item = $this->items->get($this->supplierId, $id);

        self::assertSame('2091-03-01', substr((string) $item['accrual_from'], 0, 10));
        self::assertSame('2092-02-29', substr((string) $item['accrual_to'], 0, 10));
    }

    /** @return iterable<string,array{0:array<string,mixed>,1:string}> */
    public static function invalidPeriods(): iterable
    {
        yield 'jen začátek' => [['accrual_from' => '2091-07-01'], 'accrual_period_incomplete'];
        yield 'jen konec' => [['accrual_to' => '2092-06-30'], 'accrual_period_incomplete'];
        yield 'konec před začátkem' => [['accrual_from' => '2092-07-01', 'accrual_to' => '2092-06-30'], 'accrual_period_invalid'];
        yield 'neexistující datum' => [['accrual_from' => '2091-02-31', 'accrual_to' => '2092-06-30'], 'accrual_period_invalid'];
    }

    /** @param array<string,mixed> $override */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPeriods')]
    public function testInvalidPeriodIsRejected(array $override, string $code): void
    {
        try {
            $this->items->create($this->supplierId, $override + $this->payload(1000.00, self::YEAR . '-07-01'), $this->userId);
            self::fail('Neplatné období časového rozlišení musí skončit validační chybou.');
        } catch (OtherItemException $e) {
            self::assertSame($code, $e->errorCode);
            self::assertSame(422, $e->httpStatus);
        }
    }

    public function testTaxEvidenceRejectsPeriod(): void
    {
        $this->db->pdo()->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = ?")->execute([$this->supplierId]);

        try {
            $this->receivable(1000.00, self::YEAR . '-07-01', '2091-07-01', '2092-06-30', post: false);
            self::fail('Daňová evidence výnos v deníku nemá, období rozlišení se odmítne.');
        } catch (OtherItemException $e) {
            self::assertSame('accrual_double_entry_only', $e->errorCode);
        }
        self::assertGreaterThan(0, $this->receivable(1000.00, self::YEAR . '-07-01', null, null, post: false));
    }

    /**
     * Rozpad na dva výnosové účty a středisko dokladu: odklad i rozpuštění jdou z každého
     * účtu poměrem jeho obratu a se střediskem, které nese řádek zápisu pohledávky.
     */
    public function testSplitRevenueAccountsAndCenterAreDeferredAndReleased(): void
    {
        $dimensions = Bootstrap::buildContainer()->get(DimensionService::class);
        $dimensions->setEnabled($this->supplierId, true);
        $center = (int) $dimensions->ensureDefaultTypes($this->supplierId, ['stredisko'])['cost_center'];
        $a = (int) $dimensions->createValue($this->supplierId, $center, ['code' => 'S-A', 'name' => 'Středisko A'])['id'];

        $item = $this->items->create($this->supplierId, [
            'accrual_from' => '2091-07-01', 'accrual_to' => '2092-06-30',
            'posting_lines' => [['account_code' => '602', 'amount' => 24400.00], ['account_code' => '648', 'amount' => 12200.00]],
        ] + $this->payload(36600.00, self::YEAR . '-07-01'), $this->userId);
        $dimensions->saveDocument($this->supplierId, 'other_item', (int) $item['id'], [$center => $a], []);
        $this->items->post($this->supplierId, (int) $item['id'], $this->userId);

        $preview = $this->closing->deferredRevenueAccrualPreview($this->supplierId, $this->periodId);
        self::assertCount(2, $preview['items'], 'Jeden řádek na výnosový účet zápisu.');
        self::assertCount(1, $preview['documents']);
        self::assertEqualsWithDelta(18200.00, (float) $preview['documents'][0]['deferred_amount'], 0.001);
        self::assertEqualsWithDelta(12133.33, (float) $preview['by_account']['602'], 0.001);
        self::assertEqualsWithDelta(6066.67, (float) $preview['by_account']['648'], 0.001);

        $this->driveCloseWithAccrual();
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        $defer = $this->journal->findBySource($this->supplierId, 'deferred_revenue_accrual', ClosingSourceId::deferredRevenueAccrual($this->periodId));
        $release = $this->journal->findBySource($this->supplierId, 'deferred_revenue_accrual', ClosingSourceId::deferredRevenueAccrualRelease($this->periodId));
        self::assertNotNull($defer);
        self::assertNotNull($release);
        self::assertSame([
            '602|' . $a => ['debit', 1_213_333],
            '648|' . $a => ['debit', 606_667],
        ], $this->revenueByCenter((int) $defer['id'], $center), 'Odklad nese středisko řádků zápisu pohledávky.');
        self::assertSame([
            '602|' . $a => ['credit', 1_213_333],
            '648|' . $a => ['credit', 606_667],
        ], $this->revenueByCenter((int) $release['id'], $center), 'Rozpuštění zrcadlí odklad.');
    }

    /**
     * Uzávěrka roku N běží, oprava dvojice 602 → 648 proto jde opravným zápisem s datem N+1.
     * Odklad N i jeho dimenze berou zápisy k rozvahovému dni: 602 se střediskem originálu.
     */
    public function testPairRepostDatedNextYearKeepsDeferralOnOriginalAccountAndCenter(): void
    {
        [$id, $entryId, $center, $a] = $this->pairedReceivableWithCenter();
        $this->periods->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")->execute([$this->periodId]);
        $result = $this->pairs()->repostPair($this->supplierId, $entryId, 1, ['debit_account_code' => '315', 'credit_account_code' => '648'],
            $this->meta() + ['entry_date' => (self::YEAR + 1) . '-01-15', 'description' => 'Syntetická oprava v dalším roce']);
        self::assertSame('correction_entry', $result['mode']);

        $preview = $this->closing->deferredRevenueAccrualPreview($this->supplierId, $this->periodId);
        self::assertSame(['602'], array_map('strval', array_keys($preview['by_account'])));

        $this->closing->runDeferredRevenueAccrual($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $defer = $this->journal->findBySource($this->supplierId, 'deferred_revenue_accrual', ClosingSourceId::deferredRevenueAccrual($this->periodId));
        self::assertNotNull($defer);
        self::assertSame(['602|' . $a => ['debit', 1_820_000]], $this->revenueByCenter((int) $defer['id'], $center));
    }

    /** Oprava opravy: druhé přeúčtování jde přes dvojici opravného zápisu, odklad jde z 604. */
    public function testSecondPairRepostThroughCorrectionEntryMovesDeferralAgain(): void
    {
        [, $entryId, $center, $a] = $this->pairedReceivableWithCenter();
        $this->lockUntil(self::YEAR . '-10-31');
        $first = $this->pairs()->repostPair($this->supplierId, $entryId, 1, ['debit_account_code' => '211', 'credit_account_code' => '648'],
            $this->meta() + ['entry_date' => self::YEAR . '-11-15', 'description' => 'Syntetická oprava 1']);
        self::assertSame('correction_entry', $first['mode']);
        $this->lockUntil(self::YEAR . '-11-30');
        $second = $this->pairs()->repostPair($this->supplierId, (int) $first['entry_id'], (int) $first['pair_no'],
            ['debit_account_code' => '315', 'credit_account_code' => '604'],
            $this->meta() + ['entry_date' => self::YEAR . '-12-15', 'description' => 'Syntetická oprava 2']);
        self::assertSame('correction_entry', $second['mode']);

        $preview = $this->closing->deferredRevenueAccrualPreview($this->supplierId, $this->periodId);
        self::assertSame(['604'], array_map('strval', array_keys($preview['by_account'])));
        self::assertEqualsWithDelta(18200.00, (float) $preview['by_account']['604'], 0.001);

        $this->lockUntil(null);
        $this->driveCloseWithAccrual();
        $defer = $this->journal->findBySource($this->supplierId, 'deferred_revenue_accrual', ClosingSourceId::deferredRevenueAccrual($this->periodId));
        self::assertNotNull($defer);
        self::assertSame(['604|' . $a => ['debit', 1_820_000]], $this->revenueByCenter((int) $defer['id'], $center));
    }

    /**
     * Storno položky datované až v dalším roce (po zaúčtovaném odkladu): rozpuštění jde ze
     * zápisu, ze kterého uzávěrka odložila, takže 384 se vyrovná.
     */
    public function testReversalDatedNextYearStillReleasesDeferral(): void
    {
        $id = $this->receivable(36600.00, self::YEAR . '-07-01', '2091-07-01', '2092-06-30');
        $this->driveCloseWithAccrual();
        $this->periods->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
        $this->items->reverse($this->supplierId, $id, 'Syntetické storno v dalším roce', $this->userId, (self::YEAR + 1) . '-01-15');

        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        $release = $this->journal->findBySource($this->supplierId, 'deferred_revenue_accrual', ClosingSourceId::deferredRevenueAccrualRelease($this->periodId));
        self::assertNotNull($release);
        self::assertEqualsWithDelta(18200.00, $this->sideAmount($this->entryLines((int) $release['id']), '602', 'credit'), 0.001);
        self::assertEqualsWithDelta(0.0, $this->balance384AsOf('2092-12-31'), 0.005);
    }

    /**
     * Položka přeúčtovaná do dalšího roku: odklad roku N šel z původního zápisu a rozpuštění
     * ho čte k jeho období, proto se období nemění, dokud je rok N uzavřený.
     */
    public function testPeriodGuardCoversEarlierEntryOfRepostedItem(): void
    {
        $id = $this->receivable(36600.00, self::YEAR . '-07-01', '2091-07-01', '2092-06-30');
        $this->driveCloseWithAccrual();
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->periods->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
        $this->items->repost($this->supplierId, $id, [
            'reason' => 'Syntetický přesun', 'entry_date' => (self::YEAR + 1) . '-02-01',
            'account_code' => '315', 'counter_account_code' => '604',
        ], $this->userId);

        $this->assertAccrualRefused($id, 'period_closed', 409, ['accrual_to' => '2092-12-31']);
    }

    /**
     * Víceleté období a storno položky v N+1: storno je záporný zdroj se stejným obdobím
     * (jako dobropis), uzávěrka N+1 proto vrátí zbylou tranši do výnosů roku storna a 384 se
     * vyrovná už k jeho konci; otevření N+2 nic nerozpouští.
     */
    public function testReversalInNextYearReturnsRemainingTrancheInThatYear(): void
    {
        $id = $this->receivable(73100.00, self::YEAR . '-07-01', '2091-07-01', '2093-06-30');
        $this->driveCloseWithAccrual();
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->items->reverse($this->supplierId, $id, 'Syntetické storno', $this->userId, (self::YEAR + 1) . '-03-01');

        $this->periodId = $this->periodOf(self::YEAR + 1);
        $this->distributeProfit((self::YEAR + 1) . '-01-02');
        $this->driveCloseWithAccrual();
        $defer = $this->journal->findBySource($this->supplierId, 'deferred_revenue_accrual', ClosingSourceId::deferredRevenueAccrual($this->periodId));
        self::assertNotNull($defer);
        $lines = $this->entryLines((int) $defer['id']);
        self::assertEqualsWithDelta(18100.00, $this->sideAmount($lines, '602', 'credit'), 0.001, 'Storno vrátí zbylou tranši do výnosů roku storna.');
        self::assertEqualsWithDelta(0.0, $this->balance384AsOf('2092-12-31'), 0.005);

        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        self::assertEqualsWithDelta(0.0, $this->balance384AsOf('2093-12-31'), 0.005);
        self::assertEqualsWithDelta(0.0, $this->revenueOf('602', '2093-01-01', '2093-12-31'), 0.005, 'Zrušená položka v N+2 výnos nemá.');
    }

    /**
     * Položka přeúčtovaná do dalšího roku s jiným střediskem: odklad roku N i jeho dimenze
     * berou zápis platný k rozvahovému dni, ne nový zápis z N+1.
     */
    public function testRepostIntoNextYearWithOtherCenterKeepsDeferralDimension(): void
    {
        [$id, , $center, $a] = $this->pairedReceivableWithCenter();
        $b = (int) Bootstrap::buildContainer()->get(DimensionService::class)->createValue($this->supplierId, $center, ['code' => 'S-B', 'name' => 'Středisko B'])['id'];
        $this->periods->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
        Bootstrap::buildContainer()->get(DimensionService::class)->saveDocument($this->supplierId, 'other_item', $id, [$center => $b], []);
        $this->items->repost($this->supplierId, $id, [
            'reason' => 'Syntetický přesun', 'entry_date' => (self::YEAR + 1) . '-02-01',
            'account_code' => '378', 'counter_account_code' => '602',
        ], $this->userId);

        $this->driveCloseWithAccrual();

        $defer = $this->journal->findBySource($this->supplierId, 'deferred_revenue_accrual', ClosingSourceId::deferredRevenueAccrual($this->periodId));
        self::assertNotNull($defer);
        // Změna dimenzí dokladu přerazítkuje i řádky původního zápisu (B); nový zápis
        // z N+1 se k odkladu N nepřičte, jinak by 602 nesla dvojnásobnou váhu.
        self::assertSame(['602|' . $b => ['debit', 1_820_000]], $this->revenueByCenter((int) $defer['id'], $center));
        self::assertNotSame($a, $b);
    }

    /**
     * Víceleté období a přeúčtování položky do N+1 na jiný výnosový účet: storno původního
     * zápisu vrátí zbylou tranši z 602, nový zápis ji odloží na 604, otevření N+2 ji
     * rozpustí jen na 604. Výnos za každý rok i po účtech sedí.
     */
    public function testRepostIntoNextYearMovesRemainingTrancheToNewAccount(): void
    {
        $id = $this->receivable(73100.00, self::YEAR . '-07-01', '2091-07-01', '2093-06-30');
        $this->driveCloseWithAccrual();
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->items->repost($this->supplierId, $id, [
            'reason' => 'Syntetický přesun', 'entry_date' => (self::YEAR + 1) . '-03-01',
            'account_code' => '315', 'counter_account_code' => '604',
        ], $this->userId);

        $this->periodId = $this->periodOf(self::YEAR + 1);
        $this->distributeProfit((self::YEAR + 1) . '-01-02');
        $this->driveCloseWithAccrual();
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        // 602: rozpuštění 36 600 − storno 73 100 + vrácená tranše 18 100 = −18 400 (výnos N vrácený stornem);
        // 604: nový zápis 73 100 − odklad tranše N+2 18 100 = 55 000. Rok N+1 celkem 36 600.
        self::assertEqualsWithDelta(-18400.00, $this->revenueOf('602', '2092-01-01', '2092-12-31'), 0.005);
        self::assertEqualsWithDelta(55000.00, $this->revenueOf('604', '2092-01-01', '2092-12-31'), 0.005);
        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        $release = $this->journal->findBySource($this->supplierId, 'deferred_revenue_accrual', ClosingSourceId::deferredRevenueAccrualRelease($this->periodId));
        self::assertNotNull($release);
        $lines = $this->entryLines((int) $release['id']);
        self::assertEqualsWithDelta(18100.00, $this->sideAmount($lines, '604', 'credit'), 0.001);
        self::assertEqualsWithDelta(0.0, $this->revenueOf('602', '2093-01-01', '2093-12-31'), 0.005);
        self::assertEqualsWithDelta(0.0, $this->balance384AsOf('2093-12-31'), 0.005);
    }

    /** Koncept vrácený odúčtováním: změna období v editaci podléhá stejné pojistce jako route. */
    public function testDraftWithPostedHistoryKeepsPeriodGuard(): void
    {
        $id = $this->receivable(36600.00, self::YEAR . '-07-01', '2091-07-01', '2092-06-30');
        $this->driveCloseWithAccrual();
        $this->db->pdo()->prepare("UPDATE other_items SET status = 'draft' WHERE id = ?")->execute([$id]);

        try {
            $this->items->update($this->supplierId, $id, ['accrual_to' => '2092-12-31'], $this->userId);
            self::fail('Změna období konceptu se zaúčtovanou historií musí projít pojistkou.');
        } catch (OtherItemException $e) {
            self::assertSame('accrual_deferral_posted', $e->errorCode);
        }
        self::assertSame('Syntetický nájem upravený', $this->items->update($this->supplierId, $id, ['title' => 'Syntetický nájem upravený'], $this->userId)['title'],
            'Bez změny období se koncept upraví.');
    }

    /**
     * Víceleté období, rok N uzavřený a v N+1 oprava výnosové dvojice 602 → 648 opravným
     * zápisem: ten je samostatný zdroj roku N+1 (jako dobropis), vrátí zbylou tranši z 602
     * a odloží ji na 648 se střediskem opravy. Výnos sedí za každý rok i účet.
     */
    public function testPairRepostInNextYearMovesRemainingTrancheToNewAccount(): void
    {
        [, $entryId, $center, $a] = $this->pairedReceivableWithCenter(self::YEAR . '-07-01', 73100.00, '2093-06-30');
        $this->driveCloseWithAccrual();
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $result = $this->pairs()->repostPair($this->supplierId, $entryId, 1, ['debit_account_code' => '315', 'credit_account_code' => '648'],
            $this->meta() + ['entry_date' => (self::YEAR + 1) . '-03-01', 'description' => 'Syntetická oprava v dalším roce']);
        self::assertSame('correction_entry', $result['mode']);

        $this->periodId = $this->periodOf(self::YEAR + 1);
        $this->distributeProfit((self::YEAR + 1) . '-01-02');
        $this->driveCloseWithAccrual();
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        // 602: rozpuštění 36 600 − oprava 73 100 + vrácená tranše 18 100; 648: 73 100 − odklad 18 100.
        self::assertEqualsWithDelta(-18400.00, $this->revenueOf('602', '2092-01-01', '2092-12-31'), 0.005);
        self::assertEqualsWithDelta(55000.00, $this->revenueOf('648', '2092-01-01', '2092-12-31'), 0.005);
        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        self::assertEqualsWithDelta(0.0, $this->revenueOf('602', '2093-01-01', '2093-12-31'), 0.005);
        self::assertEqualsWithDelta(18100.00, $this->revenueOf('648', '2093-01-01', '2093-12-31'), 0.005);
        self::assertEqualsWithDelta(0.0, $this->balance384AsOf('2093-12-31'), 0.005);
        $release = $this->journal->findBySource($this->supplierId, 'deferred_revenue_accrual', ClosingSourceId::deferredRevenueAccrualRelease($this->periodId));
        self::assertNotNull($release);
        self::assertSame(['credit', 1_810_000], $this->revenueByCenter((int) $release['id'], $center)['648|' . $a] ?? null);
    }

    /**
     * Oprava dvojice zpětně datovaná do dřívějšího roku je samostatný zdroj svého roku:
     * do zdroje kořene roku N se nezapočte, odklad N jde dál z 602 celou částkou.
     */
    public function testBackdatedPairCorrectionIsSeparateSource(): void
    {
        $this->db->pdo()->prepare("INSERT INTO accounting_entry_form_history (supplier_id, valid_from, entry_form) VALUES (?, ?, 'single')")
            ->execute([$this->supplierId, (self::YEAR - 1) . '-01-01']);
        $this->periods->create($this->supplierId, self::YEAR - 1, (self::YEAR - 1) . '-01-01', (self::YEAR - 1) . '-12-31');
        $id = $this->receivable(36600.00, self::YEAR . '-07-01', '2091-07-01', '2092-06-30');
        $entryId = (int) $this->items->get($this->supplierId, $id)['journal_entry_id'];
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")->execute([$this->periodId]);
        $result = $this->pairs()->repostPair($this->supplierId, $entryId, 1, ['debit_account_code' => '315', 'credit_account_code' => '648'],
            $this->meta() + ['entry_date' => (self::YEAR - 1) . '-12-20', 'description' => 'Syntetická zpětná oprava']);
        self::assertSame('correction_entry', $result['mode']);

        $preview = $this->closing->deferredRevenueAccrualPreview($this->supplierId, $this->periodId);

        self::assertSame(['602'], array_map('strval', array_keys($preview['by_account'])));
        self::assertEqualsWithDelta(18200.00, (float) $preview['total'], 0.001);

        // Rok N−1: oprava je zdroj svého roku, celé období rozlišení leží až za ním.
        $earlier = $this->closing->deferredRevenueAccrualPreview($this->supplierId, $this->periodOf(self::YEAR - 1));
        self::assertEqualsWithDelta(-36600.00, (float) ($earlier['by_account']['602'] ?? 0), 0.001);
        self::assertEqualsWithDelta(36600.00, (float) ($earlier['by_account']['648'] ?? 0), 0.001);
    }

    /**
     * Zpětná oprava dvojice do N−1 celým cyklem N−1 → N → N+1: výnos 602 je každý rok nulový,
     * 648 nese tranše 18 400 (N) a 18 200 (N+1), 384 se vyrovná.
     */
    public function testBackdatedPairCorrectionFullCycle(): void
    {
        $this->db->pdo()->prepare("INSERT INTO accounting_entry_form_history (supplier_id, valid_from, entry_form) VALUES (?, ?, 'single')")
            ->execute([$this->supplierId, (self::YEAR - 1) . '-01-01']);
        $earlierId = $this->periods->create($this->supplierId, self::YEAR - 1, (self::YEAR - 1) . '-01-01', (self::YEAR - 1) . '-12-31');
        $id = $this->receivable(36600.00, self::YEAR . '-07-01', '2091-07-01', '2092-06-30');
        $entryId = (int) $this->items->get($this->supplierId, $id)['journal_entry_id'];
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")->execute([$this->periodId]);
        self::assertSame('correction_entry', $this->pairs()->repostPair($this->supplierId, $entryId, 1, ['debit_account_code' => '315', 'credit_account_code' => '648'],
            $this->meta() + ['entry_date' => (self::YEAR - 1) . '-12-20', 'description' => 'Syntetická zpětná oprava'])['mode']);
        $pdo->prepare("UPDATE accounting_periods SET status = 'open' WHERE id = ?")->execute([$this->periodId]);

        $current = $this->periodId;
        $this->periodId = $earlierId;
        $this->driveCloseWithAccrual();
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->periodId = $current;
        $this->distributeProfit(self::YEAR . '-01-02');
        $this->driveCloseWithAccrual();
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        foreach ([[self::YEAR - 1, 0.0, 0.0], [self::YEAR, 0.0, 18400.00], [self::YEAR + 1, 0.0, 18200.00]] as [$year, $on602, $on648]) {
            self::assertEqualsWithDelta($on602, $this->revenueOf('602', $year . '-01-01', $year . '-12-31'), 0.005, '602 v roce ' . $year);
            self::assertEqualsWithDelta($on648, $this->revenueOf('648', $year . '-01-01', $year . '-12-31'), 0.005, '648 v roce ' . $year);
        }
        self::assertEqualsWithDelta(0.0, $this->balance384AsOf((self::YEAR + 1) . '-12-31'), 0.005);
    }

    /** Storno zpětně datované do dřívějšího roku nečiní zápis roku N mrtvým: odklad N vznikne. */
    public function testBackdatedReversalIsSeparateSource(): void
    {
        $this->periods->create($this->supplierId, self::YEAR - 1, (self::YEAR - 1) . '-01-01', (self::YEAR - 1) . '-12-31');
        $id = $this->receivable(36600.00, self::YEAR . '-07-01', '2091-07-01', '2092-06-30');
        $this->items->reverse($this->supplierId, $id, 'Syntetické zpětné storno', $this->userId, (self::YEAR - 1) . '-12-20');

        $preview = $this->closing->deferredRevenueAccrualPreview($this->supplierId, $this->periodId);

        self::assertSame(['602'], array_map('strval', array_keys($preview['by_account'])));
        self::assertEqualsWithDelta(18200.00, (float) $preview['total'], 0.001);

        $earlier = $this->closing->deferredRevenueAccrualPreview($this->supplierId, $this->periodOf(self::YEAR - 1));
        self::assertEqualsWithDelta(-36600.00, (float) $earlier['total'], 0.001, 'Storno odloží celý svůj (záporný) výnos.');
    }

    /** Zápis stornovaný ještě ve svém uzavřeném roce není zdrojem odkladu, pojistku neblokuje. */
    public function testRootReversedWithinClosedYearDoesNotBlockPeriodOnNewEntry(): void
    {
        $id = $this->receivable(36600.00, self::YEAR . '-07-01', null, null);
        $this->items->repost($this->supplierId, $id, [
            'reason' => 'Syntetická oprava', 'entry_date' => self::YEAR . '-08-01',
            'account_code' => '315', 'counter_account_code' => '604',
        ], $this->userId);
        $this->periods->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
        $this->items->repost($this->supplierId, $id, [
            'reason' => 'Syntetický přesun', 'entry_date' => (self::YEAR + 1) . '-02-01',
            'account_code' => '315', 'counter_account_code' => '602',
        ], $this->userId);
        // Zbývá zápis z 1. 8. N (živý k 31. 12. N, zdroj) — ten pojistku blokuje oprávněně.
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ?")->execute([$this->periodId]);
        $this->assertAccrualRefused($id, 'period_closed', 409, ['accrual_from' => '2092-02-01', 'accrual_to' => '2093-01-31']);

        // Zdroje: zápis z 1. 8. N (živý k 31. 12. N) a v N+1 jeho storno i nový zápis. Původní
        // zápis z 1. 7. N stornovaný ještě v N zdrojem není, pojistku by neměl spouštět.
        self::assertSame([self::YEAR . '-08-01', (self::YEAR + 1) . '-02-01'],
            \MyInvoice\Service\Accounting\Closing\AccrualDeferralService::otherItemSourceDates($this->db, $this->supplierId, $id, 'receivable'));
    }

    public function testUnchangedPeriodWritesNoAudit(): void
    {
        $id = $this->receivable(36600.00, self::YEAR . '-07-01', '2091-07-01', '2092-06-30');

        $result = $this->items->updateAccrual($this->supplierId, $id, ['accrual_from' => '2091-07-01', 'accrual_to' => '2092-06-30'], $this->userId);

        self::assertSame($result['before'], $result['after']);
    }

    /**
     * Průběžný výkaz k 30. 6. s opravou dvojice datovanou 30. 9.: výkaz k 30. 6. vidí výnos
     * na původním účtu, oprava pozdější než den výkazu se do něj nepromítne.
     */
    public function testInterimAdjustmentIgnoresPairCorrectionAfterAsOf(): void
    {
        [, $entryId] = $this->pairedReceivableWithCenter(self::YEAR . '-03-01');
        $this->lockUntil(self::YEAR . '-08-31');
        $this->pairs()->repostPair($this->supplierId, $entryId, 1, ['debit_account_code' => '211', 'credit_account_code' => '648'],
            $this->meta() + ['entry_date' => self::YEAR . '-09-30', 'description' => 'Syntetická oprava']);

        $interim = Bootstrap::buildContainer()->get(InterimAccrualAdjustment::class)->compute($this->supplierId, self::STARTS_ON, self::YEAR . '-06-30');

        self::assertArrayHasKey('602', $interim['deltas']);
        self::assertArrayNotHasKey('648', $interim['deltas']);
    }

    /** Průběžný výkaz k 30. 6. a storno položky 30. 9. téhož roku: k 30. 6. položka ještě platí. */
    public function testInterimAdjustmentCountsItemReversedAfterAsOf(): void
    {
        $id = $this->receivable(36600.00, self::YEAR . '-03-01', '2091-03-01', '2092-02-29');
        $this->items->reverse($this->supplierId, $id, 'Syntetické storno', $this->userId, self::YEAR . '-09-30');

        $interim = Bootstrap::buildContainer()->get(InterimAccrualAdjustment::class)->compute($this->supplierId, self::STARTS_ON, self::YEAR . '-06-30');

        self::assertGreaterThan(0.0, (float) $interim['deferred_revenue']);
        self::assertSame([], $this->closing->deferredRevenueAccrualPreview($this->supplierId, $this->periodId)['items'], 'K rozvahovému dni je stornovaná.');
    }

    /** Opakování období nepřenáší: výskyt by jinak znovu odložil výnos zdrojové položky. */
    public function testScheduleOccurrenceDoesNotCopyPeriod(): void
    {
        $id = $this->receivable(1200.00, self::YEAR . '-01-15', '2091-01-15', '2092-01-14');
        $schedules = Bootstrap::buildContainer()->get(OtherItemScheduleService::class);
        $schedule = $schedules->create($this->supplierId, $id, ['frequency' => 'monthly'], $this->userId);
        $created = $schedules->generate($this->supplierId, (int) $schedule['id'], self::YEAR . '-03-31', $this->userId, false)['created_ids'];

        self::assertNotEmpty($created);
        foreach ($created as $occurrence) {
            $item = $this->items->get($this->supplierId, (int) $occurrence);
            self::assertNull($item['accrual_from']);
            self::assertNull($item['accrual_to']);
        }
    }

    /** Dvojice přeúčtovaná na 384 a v témže roce zpět: nulový obrat 384 odklad nevyřadí. */
    public function testPairMovedToDeferralAccountAndBackIsStillDeferred(): void
    {
        [, $entryId] = $this->pairedReceivableWithCenter();
        $this->lockUntil(self::YEAR . '-10-31');
        $first = $this->pairs()->repostPair($this->supplierId, $entryId, 1, ['debit_account_code' => '211', 'credit_account_code' => '384'],
            $this->meta() + ['entry_date' => self::YEAR . '-11-15', 'description' => 'Syntetický přesun na 384']);
        self::assertSame('correction_entry', $first['mode']);
        $this->lockUntil(self::YEAR . '-11-30');
        $second = $this->pairs()->repostPair($this->supplierId, (int) $first['entry_id'], (int) $first['pair_no'],
            ['debit_account_code' => '315', 'credit_account_code' => '602'],
            $this->meta() + ['entry_date' => self::YEAR . '-12-15', 'description' => 'Syntetický návrat na 602']);
        self::assertSame('correction_entry', $second['mode']);

        $preview = $this->closing->deferredRevenueAccrualPreview($this->supplierId, $this->periodId);

        self::assertSame(['602'], array_map('strval', array_keys($preview['by_account'])));
        self::assertEqualsWithDelta(18200.00, (float) $preview['total'], 0.001);
    }

    /**
     * Firma přepnutá do daňové evidence: koncept s uloženým obdobím a historií v uzavřeném roce
     * jde upravit (i s obdobím poslaným beze změny, jak to dělá MCP), období zůstane.
     */
    public function testTaxEvidenceDraftWithClosedHistoryCanBeEdited(): void
    {
        $id = $this->receivable(36600.00, self::YEAR . '-07-01', '2091-07-01', '2092-06-30');
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ?")->execute([$this->periodId]);
        $this->db->pdo()->prepare("UPDATE other_items SET status = 'draft' WHERE id = ?")->execute([$id]);
        $this->db->pdo()->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = ?")->execute([$this->supplierId]);

        $updated = $this->items->update($this->supplierId, $id, [
            'title' => 'Syntetický nájem v daňové evidenci', 'accrual_from' => '2091-07-01', 'accrual_to' => '2092-06-30',
        ], $this->userId);

        // Období zůstává: z něj rozpouští odklad zaúčtovaný ještě v podvojném účetnictví.
        self::assertSame('Syntetický nájem v daňové evidenci', $updated['title']);
        self::assertSame('2091-07-01', (string) $updated['accrual_from']);
        self::assertSame('2092-06-30', (string) $updated['accrual_to']);
        // Bez období v těle se také nezahodí.
        $updated = $this->items->update($this->supplierId, $id, ['title' => 'Syntetický nájem bez období v těle'], $this->userId);
        self::assertSame('2092-06-30', (string) $updated['accrual_to']);
        // Výslovné smazání období hlídá pojistka i v daňové evidenci.
        try {
            $this->items->update($this->supplierId, $id, ['accrual_from' => null, 'accrual_to' => null], $this->userId);
            self::fail('Smazání období uzavřeného roku prošlo.');
        } catch (OtherItemException $e) {
            self::assertSame('period_closed', $e->errorCode);
        }
    }

    /**
     * Smazání konceptu se zaúčtovanou historií v uzavřeném roce ruší i jeho časové rozlišení:
     * odmítne ho pojistka (period_closed). Koncept bez období se smaže.
     */
    public function testDeletingDraftWithPeriodAndClosedHistoryIsRefused(): void
    {
        $id = $this->receivable(36600.00, self::YEAR . '-07-01', '2091-07-01', '2092-06-30');
        $plain = $this->receivable(1000.00, self::YEAR . '-07-01', null, null);
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ?")->execute([$this->periodId]);
        $this->db->pdo()->prepare("UPDATE other_items SET status = 'draft' WHERE id IN (?, ?)")->execute([$id, $plain]);

        try {
            $this->items->deleteDraft($this->supplierId, $id);
            self::fail('Smazání konceptu s obdobím z uzavřeného roku prošlo.');
        } catch (OtherItemException $e) {
            self::assertSame('period_closed', $e->errorCode);
        }
        self::assertSame('draft', $this->items->get($this->supplierId, $id)['status']);
        $this->items->deleteDraft($this->supplierId, $plain);
    }

    /** Daňová evidence: PUT s uloženým obdobím beze změny projde, nové období odmítne. */
    public function testTaxEvidenceAcceptsUnchangedPeriodOnPostedItem(): void
    {
        $id = $this->receivable(36600.00, self::YEAR . '-07-01', '2091-07-01', '2092-06-30');
        $this->db->pdo()->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = ?")->execute([$this->supplierId]);

        $result = $this->items->updateAccrual($this->supplierId, $id, ['accrual_from' => '2091-07-01', 'accrual_to' => '2092-06-30'], $this->userId);

        self::assertSame($result['before'], $result['after']);
        $this->assertAccrualRefused($id, 'accrual_double_entry_only', 422, ['accrual_to' => '2092-12-31']);
    }

    /**
     * Krok Časové rozlišení potvrzený bez zápisu (nulový návrh) nebo přeskočený změnu období
     * nebrání, stejně jako u řádků faktur (AccrualDeferralService::accrualChangeBlocker): nic
     * neodložil, takže rozpuštění se nerozejde. Potvrzený krok pak zachytí uzavření knih.
     */
    public function testDeferralStepWithoutEntryDoesNotBlockPeriodChange(): void
    {
        $id = $this->receivable(36600.00, self::YEAR . '-07-01', null, null);
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")->execute([$this->periodId]);
        $this->db->pdo()->prepare("INSERT INTO accounting_closing_steps (supplier_id, period_id, step_key, status) VALUES (?, ?, 'deferrals', 'done')")
            ->execute([$this->supplierId, $this->periodId]);

        $this->items->updateAccrual($this->supplierId, $id, ['accrual_from' => '2091-07-01', 'accrual_to' => '2092-06-30'], $this->userId);
        $this->db->pdo()->prepare("UPDATE accounting_closing_steps SET status = 'skipped' WHERE supplier_id = ? AND period_id = ? AND step_key = 'deferrals'")
            ->execute([$this->supplierId, $this->periodId]);
        $this->items->updateAccrual($this->supplierId, $id, ['accrual_to' => '2092-03-31'], $this->userId);

        $stored = $this->items->get($this->supplierId, $id);
        self::assertSame('2092-03-31', substr((string) $stored['accrual_to'], 0, 10));
    }

    /**
     * Období nastavené až po potvrzení kroku bez zápisu: uzavření knih porovná zaúčtovaný
     * odklad (žádný) s náhledem (18 200) a odmítne zastaralý krok (#384).
     */
    public function testPeriodSetAfterStepWithoutEntryStopsCloseBooks(): void
    {
        $id = $this->receivable(36600.00, self::YEAR . '-07-01', null, null);
        $this->driveCloseWithAccrual();
        self::assertNull($this->journal->findBySource($this->supplierId, 'deferred_revenue_accrual', ClosingSourceId::deferredRevenueAccrual($this->periodId)));

        $this->items->updateAccrual($this->supplierId, $id, ['accrual_from' => '2091-07-01', 'accrual_to' => '2092-06-30'], $this->userId);

        try {
            $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
            self::fail('Uzavření knih mělo odmítnout zastaralý krok Časové rozlišení.');
        } catch (ClosingException $e) {
            self::assertSame('deferrals_stale', $e->errorCode);
        }
    }

    /**
     * Rozpuštění zrcadlí odklad zaúčtovaný uzávěrkou (#384): krok uloží rozpad ostatní
     * pohledávky do záznamu a openNext z něj rozpustí i po pozdější změně období položky
     * (zde přímo v databázi, mimo pojistku), stejně jako u řádků faktur.
     */
    public function testReleaseMirrorsPostedDeferralOfReceivable(): void
    {
        $id = $this->receivable(36600.00, self::YEAR . '-07-01', '2091-07-01', '2092-06-30');
        $this->driveCloseWithAccrual();
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        $stmt = $this->db->pdo()->prepare("SELECT payload FROM accounting_closing_steps WHERE supplier_id = ? AND period_id = ? AND step_key = 'deferrals'");
        $stmt->execute([$this->supplierId, $this->periodId]);
        $snapshot = json_decode((string) $stmt->fetchColumn(), true)['deferred_revenue_accrual']['items'];
        self::assertCount(1, $snapshot);
        self::assertSame('other_item', $snapshot[0]['source']);
        self::assertSame($id, (int) $snapshot[0]['doc_id']);
        self::assertEqualsWithDelta(36600.00, (float) $snapshot[0]['basis'], 0.001);

        $this->db->pdo()->prepare('UPDATE other_items SET accrual_to = ? WHERE supplier_id = ? AND id = ?')
            ->execute(['2092-12-31', $this->supplierId, $id]);
        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        $release = $this->journal->findBySource($this->supplierId, 'deferred_revenue_accrual', ClosingSourceId::deferredRevenueAccrualRelease($this->periodId));
        self::assertNotNull($release);
        self::assertEqualsWithDelta(18200.00, $this->sideAmount($this->entryLines((int) $release['id']), '602', 'credit'), 0.001);
        self::assertEqualsWithDelta(0.0, $this->balance384AsOf('2092-12-31'), 0.005);
    }

    /** Datum jako pole či objekt z JSON je validační chyba (422) bez varování PHP, ne 500. */
    public function testNonStringPeriodIsRejectedAsInvalid(): void
    {
        $id = $this->receivable(36600.00, self::YEAR . '-07-01', null, null);

        set_error_handler(static function (int $severity, string $message): never {
            throw new \ErrorException($message, 0, $severity);
        });
        try {
            $this->assertAccrualRefused($id, 'accrual_period_invalid', 422, ['accrual_from' => ['x'], 'accrual_to' => '2092-06-30']);
        } finally {
            restore_error_handler();
        }
    }

    /** Totéž při úpravě konceptu: porovnání s uloženým obdobím pole nepřevádí na text (varování). */
    public function testNonStringPeriodInDraftUpdateIsRejectedAsInvalid(): void
    {
        $id = $this->receivable(36600.00, self::YEAR . '-07-01', '2091-07-01', '2092-06-30', post: false);

        set_error_handler(static function (int $severity, string $message): never {
            throw new \ErrorException($message, 0, $severity);
        });
        try {
            foreach ([['side' => 'receivable'], ['side' => 'payable', 'kind' => 'rent']] as $side) {
                try {
                    $this->items->update($this->supplierId, $id, $side + ['accrual_from' => ['x'], 'accrual_to' => '2092-06-30'], $this->userId);
                    self::fail('Datum jako pole prošlo.');
                } catch (OtherItemException $e) {
                    self::assertSame('accrual_period_invalid', $e->errorCode);
                    self::assertSame(422, $e->httpStatus);
                }
            }
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Přerušená uzávěrka (abort jen bez závěrkových zápisů) nechá krok Časové rozlišení potvrzený,
     * období je ale znovu otevřené: krok nic neodložil a vrátit ho tam nejde, pojistka neblokuje.
     */
    public function testAbortedClosingWithConfirmedStepDoesNotBlockPeriodChange(): void
    {
        $id = $this->receivable(36600.00, self::YEAR . '-07-01', null, null);
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")->execute([$this->periodId]);
        $this->db->pdo()->prepare("INSERT INTO accounting_closing_steps (supplier_id, period_id, step_key, status) VALUES (?, ?, 'deferrals', 'skipped')")
            ->execute([$this->supplierId, $this->periodId]);
        $this->closing->abort($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        $result = $this->items->updateAccrual($this->supplierId, $id, ['accrual_from' => '2091-07-01', 'accrual_to' => '2092-06-30'], $this->userId);

        self::assertSame(['2091-07-01', '2092-06-30'], $result['after']);
    }

    /**
     * Rok N uzavřený, přeúčtování v N+1 (storno a nový zápis). Smazání nového zápisu v deníku
     * odklad roku N nemění: pojistka hlídá jen zdroj mazaného zápisu, ne uzavřený kořen N.
     */
    public function testDeletingLaterEntryOfRepostedItemIgnoresClosedEarlierSource(): void
    {
        $id = $this->receivable(36600.00, self::YEAR . '-07-01', '2091-07-01', '2092-06-30');
        $this->driveCloseWithAccrual();
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->periods->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
        $this->items->repost($this->supplierId, $id, [
            'reason' => 'Syntetický přesun', 'entry_date' => (self::YEAR + 1) . '-02-01',
            'account_code' => '315', 'counter_account_code' => '604',
        ], $this->userId);
        $newEntry = (int) $this->items->get($this->supplierId, $id)['journal_entry_id'];

        // Test běží v transakci (cesta smazání zápisu v deníku ji vyžaduje).
        $this->items->prepareEntryDeletion($this->supplierId, $id, $newEntry, $this->userId);

        self::assertSame('draft', $this->items->get($this->supplierId, $id)['status']);
    }

    /** PUT se stejným obdobím u položky z uzavřeného roku: 200 beze změny, ne 409. */
    public function testUnchangedPeriodOfClosedItemIsAccepted(): void
    {
        $id = $this->receivable(36600.00, self::YEAR . '-07-01', '2091-07-01', '2092-06-30');
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ?")->execute([$this->periodId]);

        $result = $this->items->updateAccrual($this->supplierId, $id, ['accrual_from' => '2091-07-01', 'accrual_to' => '2092-06-30'], $this->userId);

        self::assertSame(['2091-07-01', '2092-06-30'], $result['after']);
        $this->assertAccrualRefused($id, 'period_closed', 409, ['accrual_to' => '2092-12-31']);
    }

    /**
     * Přepnutí konceptu na závazek s obdobím poslaným beze změny (klient vrací celý objekt) ho
     * zahodí: období výnosu není obdobím nákladu. Nové období závazku jde zadat znovu.
     */
    public function testSwitchToPayableWithUnchangedPeriodDropsIt(): void
    {
        $id = $this->receivable(1200.00, self::YEAR . '-03-01', '2091-03-01', '2092-02-29', post: false);

        $item = $this->items->update($this->supplierId, $id, ['side' => 'payable', 'counter_account_code' => '518',
            'accrual_from' => '2091-03-01', 'accrual_to' => '2092-02-29'], $this->userId);

        self::assertNull($item['accrual_from']);
        $item = $this->items->update($this->supplierId, $id, ['accrual_from' => '2091-04-01', 'accrual_to' => '2092-02-29'], $this->userId);
        self::assertSame('2091-04-01', substr((string) $item['accrual_from'], 0, 10));
    }

    /** Uzávěrka bere jen položky, jejichž období přesahuje rozvahový den, už v rekurzivním výběru. */
    public function testSourcesSkipItemsEndedBeforeBalanceDate(): void
    {
        $ended = $this->receivable(1200.00, self::YEAR . '-01-15', '2091-01-15', '2091-06-30');
        $open = $this->receivable(36600.00, self::YEAR . '-07-01', '2091-07-01', '2092-06-30');
        $method = new \ReflectionMethod(\MyInvoice\Service\Accounting\Closing\AccrualDeferralService::class, 'otherItemSources');

        $sources = $method->invoke(null, $this->db, $this->supplierId, self::ENDS_ON, null, self::ENDS_ON, 'receivable');

        self::assertSame([$open], array_values(array_unique(array_column($sources['roots'], 'item_id'))));
        self::assertNotContains($ended, array_column($sources['roots'], 'item_id'));
    }

    /** Úprava konceptu závazku bez změny strany uložené období ponechá (je to období nákladu). */
    public function testStoredPayableDraftKeepsPeriodOnUpdate(): void
    {
        $item = $this->items->create($this->supplierId, ['side' => 'payable', 'counter_account_code' => '518'] + $this->payload(1200.00, self::YEAR . '-03-01'), $this->userId);
        $this->db->pdo()->prepare("UPDATE other_items SET accrual_from = '2091-03-01', accrual_to = '2092-02-29' WHERE id = ?")->execute([(int) $item['id']]);

        $updated = $this->items->update($this->supplierId, (int) $item['id'], ['side' => 'payable', 'title' => 'Syntetický závazek upravený'], $this->userId);

        self::assertSame('2091-03-01', substr((string) $updated['accrual_from'], 0, 10));
        self::assertSame('2092-02-29', substr((string) $updated['accrual_to'], 0, 10));
    }

    /** Úprava konceptu bez období v těle ho ponechá, i po přechodu do daňové evidence. */
    public function testDraftUpdateKeepsPeriodAlsoInTaxEvidence(): void
    {
        $id = $this->receivable(1200.00, self::YEAR . '-03-01', '2091-03-01', '2092-02-29', post: false);

        $kept = $this->items->update($this->supplierId, $id, ['title' => 'Syntetický nájem upravený'], $this->userId);
        self::assertSame('2091-03-01', substr((string) $kept['accrual_from'], 0, 10));
        self::assertSame('2092-02-29', substr((string) $kept['accrual_to'], 0, 10));

        $this->db->pdo()->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = ?")->execute([$this->supplierId]);
        $kept = $this->items->update($this->supplierId, $id, ['title' => 'Syntetický nájem v daňové evidenci'], $this->userId);
        self::assertSame('2091-03-01', substr((string) $kept['accrual_from'], 0, 10));
        self::assertSame('2092-02-29', substr((string) $kept['accrual_to'], 0, 10));
    }

    public function testPostedChangeIsRefusedWithoutOwnEntryAndInTaxEvidence(): void
    {
        $posted = $this->receivable(1000.00, self::YEAR . '-07-01', null, null);
        $this->db->pdo()->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = ?")->execute([$this->supplierId]);
        $this->assertAccrualRefused($posted, 'accrual_double_entry_only', 422);

        $confirmed = $this->receivable(1000.00, self::YEAR . '-07-01', null, null);
        self::assertSame('confirmed', $this->items->get($this->supplierId, $confirmed)['status']);
        $this->assertAccrualRefused($confirmed, 'accrual_not_posted', 409);
    }

    /**
     * Potvrzená položka bez zápisu (daňová evidence) s obdobím uloženým z konceptu před
     * přechodem: období nic neodkládá a jde odebrat, nastavit ne.
     */
    public function testPeriodWithoutOwnEntryCanOnlyBeCleared(): void
    {
        $id = $this->receivable(1200.00, self::YEAR . '-03-01', '2091-03-01', '2092-02-29', post: false);
        $this->db->pdo()->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = ?")->execute([$this->supplierId]);
        $this->items->post($this->supplierId, $id, $this->userId);
        self::assertSame('confirmed', $this->items->get($this->supplierId, $id)['status']);
        $this->assertAccrualRefused($id, 'accrual_not_posted', 409, ['accrual_from' => '2091-04-01', 'accrual_to' => '2092-03-31']);

        $cleared = $this->items->updateAccrual($this->supplierId, $id, ['accrual_from' => null, 'accrual_to' => ''], $this->userId);
        self::assertNull($cleared['item']['accrual_from']);
        self::assertNull($cleared['item']['accrual_to']);
        self::assertSame(['2091-03-01', '2092-02-29'], $cleared['before']);
    }

    /** @return iterable<string,array{0:bool}> */
    public static function multiYearChanges(): iterable
    {
        yield 'beze změny' => [false];
        yield 'období změněné po uzávěrce N mimo pojistku' => [true];
    }

    /**
     * Víceleté období: druhou tranši rozpustí otevření roku N+2, a to ze záznamu kroku roku N
     * (#384) i tehdy, když se období položky po uzávěrce N změní (zde přímo v databázi).
     */
    #[DataProvider('multiYearChanges')]
    public function testMultiYearPeriodReleasesSecondTrancheOnFollowingOpen(bool $changedAfterClose): void
    {
        $id = $this->receivable(73100.00, self::YEAR . '-07-01', '2091-07-01', '2093-06-30');
        $this->driveCloseWithAccrual();
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        if ($changedAfterClose) {
            $this->db->pdo()->prepare('UPDATE other_items SET accrual_to = ? WHERE supplier_id = ? AND id = ?')
                ->execute(['2093-03-31', $this->supplierId, $id]);
        }
        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        $this->periodId = (int) $this->db->pdo()->query('SELECT id FROM accounting_periods WHERE supplier_id = ' . $this->supplierId . ' AND fiscal_year = ' . (self::YEAR + 1))->fetchColumn();
        self::assertGreaterThan(0, $this->periodId);
        $this->distributeProfit((self::YEAR + 1) . '-01-02');
        $this->driveCloseWithAccrual();
        $defer = $this->journal->findBySource($this->supplierId, 'deferred_revenue_accrual', ClosingSourceId::deferredRevenueAccrual($this->periodId));
        self::assertNull($defer, 'Pohledávka z roku N se v N+1 znovu neodkládá.');
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        $release = $this->journal->findBySource($this->supplierId, 'deferred_revenue_accrual', ClosingSourceId::deferredRevenueAccrualRelease($this->periodId));
        self::assertNotNull($release);
        self::assertEqualsWithDelta(18100.00, $this->sideAmount($this->entryLines((int) $release['id']), '602', 'credit'), 0.001);
        self::assertEqualsWithDelta(0.0, $this->balance384AsOf('2093-12-31'), 0.005);
    }

    /** @return array{0:int,1:int,2:int,3:int} položka, její zápis, typ střediska, středisko A */
    private function pairedReceivableWithCenter(string $issuedOn = self::YEAR . '-07-01', float $amount = 36600.00, string $to = '2092-06-30'): array
    {
        $this->db->pdo()->prepare("INSERT INTO accounting_entry_form_history (supplier_id, valid_from, entry_form) VALUES (?, ?, 'single')")
            ->execute([$this->supplierId, self::STARTS_ON]);
        $dimensions = Bootstrap::buildContainer()->get(DimensionService::class);
        $dimensions->setEnabled($this->supplierId, true);
        $center = (int) $dimensions->ensureDefaultTypes($this->supplierId, ['stredisko'])['cost_center'];
        $a = (int) $dimensions->createValue($this->supplierId, $center, ['code' => 'S-A', 'name' => 'Středisko A'])['id'];
        $id = (int) $this->items->create($this->supplierId, ['accrual_from' => '2091-07-01', 'accrual_to' => $to]
            + $this->payload($amount, $issuedOn), $this->userId)['id'];
        $dimensions->saveDocument($this->supplierId, 'other_item', $id, [$center => $a], []);
        $this->items->post($this->supplierId, $id, $this->userId);
        return [$id, (int) $this->items->get($this->supplierId, $id)['journal_entry_id'], $center, $a];
    }

    /** Nerozdělený výsledek minulého roku (431) převede na 428/429, ať jde zahájit další uzávěrka. */
    private function distributeProfit(string $date): void
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'credit' THEN l.amount ELSE -l.amount END), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e   ON e.id = l.entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL AND a.account_code = '431'"
        );
        $stmt->execute([$this->supplierId]);
        $balance = round((float) $stmt->fetchColumn(), 2);
        if ((int) round($balance * 100) === 0) {
            return;
        }
        $lines = $balance > 0
            ? [['account_code' => '431', 'side' => 'debit', 'amount' => $balance], ['account_code' => '428', 'side' => 'credit', 'amount' => $balance]]
            : [['account_code' => '429', 'side' => 'debit', 'amount' => -$balance], ['account_code' => '431', 'side' => 'credit', 'amount' => -$balance]];
        Bootstrap::buildContainer()->get(PostingService::class)->postDocument($this->supplierId, 'manual', null, $lines,
            ['entry_date' => $date, 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId, 'description' => 'Syntetické rozdělení VH']);
    }

    private function periodOf(int $year): int
    {
        return (int) $this->db->pdo()->query('SELECT id FROM accounting_periods WHERE supplier_id = ' . $this->supplierId . ' AND fiscal_year = ' . $year)->fetchColumn();
    }

    /** Čistý výnos (D − MD) účtu za období, bez uzávěrkových zápisů. */
    private function revenueOf(string $code, string $from, string $to): float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'credit' THEN l.amount ELSE -l.amount END), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e   ON e.id = l.entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL AND e.source_type <> 'closing'
                AND a.account_code = ? AND e.entry_date BETWEEN ? AND ?"
        );
        $stmt->execute([$this->supplierId, $code, $from, $to]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    private function pairs(): JournalPairCorrectionService
    {
        return Bootstrap::buildContainer()->get(JournalPairCorrectionService::class);
    }

    private function lockUntil(?string $date): void
    {
        $this->db->pdo()->prepare('INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)')->execute([$this->supplierId, $date]);
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

    public function testPeriodSetOnPostedReceivableIsDeferred(): void
    {
        $id = $this->receivable(36600.00, self::YEAR . '-07-01', null, null);

        $result = $this->items->updateAccrual($this->supplierId, $id, ['accrual_from' => '2091-07-01', 'accrual_to' => '2092-06-30'], $this->userId);

        self::assertSame([null, null], $result['before']);
        self::assertSame(['2091-07-01', '2092-06-30'], $result['after']);
        $preview = $this->closing->deferredRevenueAccrualPreview($this->supplierId, $this->periodId);
        self::assertEqualsWithDelta(18200.00, (float) $preview['total'], 0.001);

        // Vynechaný klíč = uložená hodnota; obě prázdná = bez rozlišení.
        $kept = $this->items->updateAccrual($this->supplierId, $id, ['accrual_to' => '2092-12-31'], $this->userId);
        self::assertSame(['2091-07-01', '2092-12-31'], $kept['after']);
        $cleared = $this->items->updateAccrual($this->supplierId, $id, ['accrual_from' => null, 'accrual_to' => ''], $this->userId);
        self::assertSame([null, null], $cleared['after']);
        self::assertSame([], $this->closing->deferredRevenueAccrualPreview($this->supplierId, $this->periodId)['items']);
    }

    public function testPostedPeriodChangeIsRefusedForDraftAndClosedPeriod(): void
    {
        $draft = $this->receivable(1000.00, self::YEAR . '-07-01', null, null, post: false);
        $this->assertAccrualRefused($draft, 'is_draft', 409);

        $posted = $this->receivable(1000.00, self::YEAR . '-07-01', null, null);
        foreach (['closed', 'reviewed', 'approved'] as $status) {
            $this->db->pdo()->prepare('UPDATE accounting_periods SET status = ? WHERE id = ?')->execute([$status, $this->periodId]);
            $this->assertAccrualRefused($posted, 'period_closed', 409);
        }
    }

    /** Rozpuštění počítá z uloženého období: po zaúčtování kroku odkladu se období nemění. */
    public function testPeriodChangeAfterPostedDeferralStepIsRefused(): void
    {
        $id = $this->receivable(36600.00, self::YEAR . '-07-01', '2091-07-01', '2092-06-30');
        $this->driveCloseWithAccrual();

        $this->assertAccrualRefused($id, 'accrual_deferral_posted', 409, ['accrual_to' => '2092-12-31']);
    }

    public function testSwitchingDraftToPayableDropsStoredPeriod(): void
    {
        $id = $this->receivable(1200.00, self::YEAR . '-03-01', '2091-03-01', '2092-02-29', post: false);

        $item = $this->items->update($this->supplierId, $id, ['side' => 'payable', 'counter_account_code' => '518'], $this->userId);

        self::assertSame('payable', $item['side']);
        self::assertNull($item['accrual_from']);
        self::assertNull($item['accrual_to']);
    }

    /**
     * Přeúčtování výnosové dvojice zápisu pohledávky (602 → 648) jde opravným zápisem bez
     * `source_id`: odklad i jeho dimenze jdou z účtu, na kterém výnos po opravě leží.
     */
    public function testPairRepostMovesDeferralToNewRevenueAccount(): void
    {
        // Dvojice má zápis jen v jednoduché formě zápisů.
        $this->db->pdo()->prepare("INSERT INTO accounting_entry_form_history (supplier_id, valid_from, entry_form) VALUES (?, ?, 'single')")
            ->execute([$this->supplierId, self::STARTS_ON]);
        $dimensions = Bootstrap::buildContainer()->get(DimensionService::class);
        $dimensions->setEnabled($this->supplierId, true);
        $center = (int) $dimensions->ensureDefaultTypes($this->supplierId, ['stredisko'])['cost_center'];
        $a = (int) $dimensions->createValue($this->supplierId, $center, ['code' => 'S-A', 'name' => 'Středisko A'])['id'];
        $id = (int) $this->items->create($this->supplierId, ['accrual_from' => '2091-07-01', 'accrual_to' => '2092-06-30']
            + $this->payload(36600.00, self::YEAR . '-07-01'), $this->userId)['id'];
        $dimensions->saveDocument($this->supplierId, 'other_item', $id, [$center => $a], []);
        $this->items->post($this->supplierId, $id, $this->userId);
        $entryId = (int) $this->items->get($this->supplierId, $id)['journal_entry_id'];
        // Uzamčené datum zápisu a daňově neneutrální změna (mění i peněžní účet): přeúčtování
        // jde opravným zápisem, ne na místě.
        $this->db->pdo()->prepare('INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)')->execute([$this->supplierId, self::YEAR . '-07-31']);
        $result = Bootstrap::buildContainer()->get(JournalPairCorrectionService::class)->repostPair($this->supplierId, $entryId, 1,
            ['debit_account_code' => '211', 'credit_account_code' => '648'],
            $this->meta() + ['entry_date' => self::YEAR . '-09-30', 'description' => 'Syntetické přeúčtování']);
        self::assertSame('correction_entry', $result['mode']);

        $preview = $this->closing->deferredRevenueAccrualPreview($this->supplierId, $this->periodId);

        self::assertSame(['648'], array_map('strval', array_keys($preview['by_account'])));
        self::assertEqualsWithDelta(18200.00, (float) $preview['by_account']['648'], 0.001);
        self::assertCount(1, $preview['documents']);
        $correction = $this->revenueByCenter((int) $result['entry_id'], $center);
        self::assertSame(['credit', 3_660_000], $correction['648|' . $a] ?? null, 'Opravný zápis nese středisko dokladu.');
        $this->db->pdo()->prepare('DELETE FROM accounting_supplier_settings WHERE supplier_id = ?')->execute([$this->supplierId]);
        $this->driveCloseWithAccrual();
        $defer = $this->journal->findBySource($this->supplierId, 'deferred_revenue_accrual', ClosingSourceId::deferredRevenueAccrual($this->periodId));
        self::assertNotNull($defer);
        self::assertSame(['648|' . $a => ['debit', 1_820_000]], $this->revenueByCenter((int) $defer['id'], $center),
            'Odklad z přeúčtovaného účtu nese středisko opravného zápisu.');
    }

    /**
     * Storno položky ve stejném roce po opravě dvojice (602 → 648): storno i opravné zápisy
     * patří k témuž zdroji, obrat se vyruší a uzávěrka nic neodkládá (bez zbytku −X na 602).
     */
    public function testSameYearReversalAfterPairRepostDefersNothing(): void
    {
        $id = $this->itemWithPairRepost();
        $this->items->reverse($this->supplierId, $id, 'Syntetické storno', $this->userId, self::YEAR . '-10-15');

        $preview = $this->closing->deferredRevenueAccrualPreview($this->supplierId, $this->periodId);

        self::assertSame([], $preview['by_account']);
        self::assertSame([], $preview['documents']);
        $this->db->pdo()->prepare('DELETE FROM accounting_supplier_settings WHERE supplier_id = ?')->execute([$this->supplierId]);
    }

    /** Přeúčtování položky ve stejném roce po opravě dvojice odkládá jen z nového zápisu. */
    public function testSameYearRepostAfterPairRepostDefersFromNewEntryOnly(): void
    {
        $id = $this->itemWithPairRepost();
        $this->items->repost($this->supplierId, $id, [
            'reason' => 'Syntetický přesun', 'entry_date' => self::YEAR . '-10-15',
            'account_code' => '315', 'counter_account_code' => '648',
        ], $this->userId);

        $preview = $this->closing->deferredRevenueAccrualPreview($this->supplierId, $this->periodId);

        self::assertSame(['648'], array_map('strval', array_keys($preview['by_account'])));
        self::assertEqualsWithDelta(18200.00, (float) $preview['by_account']['648'], 0.001);
        $this->db->pdo()->prepare('DELETE FROM accounting_supplier_settings WHERE supplier_id = ?')->execute([$this->supplierId]);
    }

    /**
     * Zaúčtovaná pohledávka se dvěma dvojicemi (602 24 400, 604 12 200), jejíž dvojici 602
     * opravný zápis z 30. 9. přeúčtoval na 648. Druhá dvojice drží zápis stornovatelný.
     */
    private function itemWithPairRepost(): int
    {
        $id = $this->twoPairItem();
        $entryId = (int) $this->items->get($this->supplierId, $id)['journal_entry_id'];
        $this->db->pdo()->prepare('INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)')->execute([$this->supplierId, self::YEAR . '-07-31']);
        $result = Bootstrap::buildContainer()->get(JournalPairCorrectionService::class)->repostPair($this->supplierId, $entryId, 1,
            ['debit_account_code' => '211', 'credit_account_code' => '648'],
            $this->meta() + ['entry_date' => self::YEAR . '-09-30', 'description' => 'Syntetické přeúčtování']);
        self::assertSame('correction_entry', $result['mode']);
        return $id;
    }

    /** Smazaná položka se neodkládá, ani když po ní v deníku zůstal zápis. */
    public function testDeletedItemIsNotDeferred(): void
    {
        $id = $this->receivable(36600.00, self::YEAR . '-07-01', '2091-07-01', '2092-06-30');
        $this->db->pdo()->prepare('UPDATE other_items SET deleted_at = NOW() WHERE id = ? AND supplier_id = ?')->execute([$id, $this->supplierId]);

        $preview = $this->closing->deferredRevenueAccrualPreview($this->supplierId, $this->periodId);

        self::assertSame([], $preview['documents']);
    }

    /** Zaúčtovaná pohledávka v jednoduché formě se dvěma dvojicemi: 602 24 400 (dvojice 1) a 604 12 200. */
    private function twoPairItem(): int
    {
        $this->db->pdo()->prepare("INSERT INTO accounting_entry_form_history (supplier_id, valid_from, entry_form) VALUES (?, ?, 'single')")
            ->execute([$this->supplierId, self::STARTS_ON]);
        $id = (int) $this->items->create($this->supplierId, [
            'accrual_from' => '2091-07-01', 'accrual_to' => '2092-06-30',
            'posting_lines' => [['account_code' => '602', 'amount' => 24400.00], ['account_code' => '604', 'amount' => 12200.00]],
        ] + $this->payload(36600.00, self::YEAR . '-07-01'), $this->userId)['id'];
        $this->items->post($this->supplierId, $id, $this->userId);
        return $id;
    }

    /**
     * Přeúčtování dvojice 602 → 648 v N+1, potom storno položky v roce N: storno zrcadlí jen
     * dvojici 604, dvojice 602 v roce N zůstává ve výnosu a odkládá se. Storno náhrady 648 nese
     * datum storna (rok N), je proto zdrojem roku N jako dobropis a odklad 648 je záporný;
     * opravný zápis je zdroj roku N+1. Položka je zrušená, odklad roku N celkem nulový.
     */
    public function testItemReversalAfterNextYearPairReversalDefersRemainingPair(): void
    {
        $id = $this->twoPairItem();
        $this->periods->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
        $entryId = (int) $this->items->get($this->supplierId, $id)['journal_entry_id'];
        $this->db->pdo()->prepare('INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)')->execute([$this->supplierId, self::YEAR . '-07-31']);
        self::assertSame('correction_entry', $this->pairs()->repostPair($this->supplierId, $entryId, 1, ['debit_account_code' => '211', 'credit_account_code' => '648'],
            $this->meta() + ['entry_date' => (self::YEAR + 1) . '-01-10', 'description' => 'Syntetické přeúčtování'])['mode']);
        $this->items->reverse($this->supplierId, $id, 'Syntetické storno', $this->userId, self::YEAR . '-10-15');

        $preview = $this->closing->deferredRevenueAccrualPreview($this->supplierId, $this->periodId);

        self::assertCount(2, $preview['by_account']);
        self::assertEqualsWithDelta(12133.33, (float) ($preview['by_account']['602'] ?? 0), 0.001);
        self::assertEqualsWithDelta(-12133.33, (float) ($preview['by_account']['648'] ?? 0), 0.001);
        $this->db->pdo()->prepare('DELETE FROM accounting_supplier_settings WHERE supplier_id = ?')->execute([$this->supplierId]);
    }

    /**
     * Přeúčtování dvojice 602 → 648 v N, storno opravného zápisu v N+1 a storno položky v N:
     * náhradní dvojice 648 žije v roce N (ruší ji až storno v N+1), odkládá se z ní.
     */
    public function testItemReversalKeepsReplacementPairReversedNextYear(): void
    {
        $id = $this->twoPairItem();
        $this->periods->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
        $entryId = (int) $this->items->get($this->supplierId, $id)['journal_entry_id'];
        // Uzamčené datum zápisu a změna peněžního účtu: přeúčtování jde opravným zápisem.
        $this->db->pdo()->prepare('INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)')->execute([$this->supplierId, self::YEAR . '-07-31']);
        $correction = $this->pairs()->repostPair($this->supplierId, $entryId, 1, ['debit_account_code' => '211', 'credit_account_code' => '648'],
            $this->meta() + ['entry_date' => self::YEAR . '-09-30', 'description' => 'Syntetické přeúčtování']);
        self::assertSame('correction_entry', $correction['mode']);
        $correctionId = (int) $correction['entry_id'];
        Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\PostingService::class)->reverse($this->supplierId, $correctionId,
            $this->meta() + ['entry_date' => (self::YEAR + 1) . '-01-10', 'description' => 'Syntetické storno opravy']);
        $this->items->reverse($this->supplierId, $id, 'Syntetické storno', $this->userId, self::YEAR . '-10-15');

        $preview = $this->closing->deferredRevenueAccrualPreview($this->supplierId, $this->periodId);

        self::assertSame(['648'], array_map('strval', array_keys($preview['by_account'])));
        self::assertEqualsWithDelta(12133.33, (float) $preview['by_account']['648'], 0.001);
        $this->db->pdo()->prepare('DELETE FROM accounting_supplier_settings WHERE supplier_id = ?')->execute([$this->supplierId]);
    }

    /** Přeúčtování položky (nový zápis s jiným protiúčtem) odkládá jen z živého zápisu, jednou. */
    public function testRepostedItemIsDeferredOnceFromNewAccount(): void
    {
        $id = $this->receivable(36600.00, self::YEAR . '-07-01', '2091-07-01', '2092-06-30');
        $this->items->repost($this->supplierId, $id, [
            'reason' => 'Syntetická oprava protiúčtu', 'entry_date' => self::YEAR . '-07-01',
            'account_code' => '315', 'counter_account_code' => '648',
        ], $this->userId);

        $preview = $this->closing->deferredRevenueAccrualPreview($this->supplierId, $this->periodId);

        self::assertSame(['648'], array_map('strval', array_keys($preview['by_account'])));
        self::assertEqualsWithDelta(18200.00, (float) $preview['total'], 0.001);
    }

    public function testCancelledReceivableIsNotDeferred(): void
    {
        $id = $this->receivable(36600.00, self::YEAR . '-07-01', '2091-07-01', '2092-06-30');
        $this->items->reverse($this->supplierId, $id, 'Syntetické storno', $this->userId);

        self::assertSame([], $this->closing->deferredRevenueAccrualPreview($this->supplierId, $this->periodId)['items']);
    }

    /** Zápis, který už sám nese účet odkladu (384), se neodkládá podruhé. */
    public function testEntryAlreadyCarryingDeferralAccountIsSkipped(): void
    {
        $this->items->post($this->supplierId, (int) $this->items->create($this->supplierId, [
            'accrual_from' => '2091-07-01', 'accrual_to' => '2092-06-30',
            'posting_lines' => [['account_code' => '602', 'amount' => 18400.00], ['account_code' => '384', 'amount' => 18200.00]],
        ] + $this->payload(36600.00, self::YEAR . '-07-01'), $this->userId)['id'], $this->userId);

        self::assertSame([], $this->closing->deferredRevenueAccrualPreview($this->supplierId, $this->periodId)['items']);
    }

    /** Závazek s obdobím odkládá náklad (381), do odkladu výnosů nepatří ani s výnosovým protiúčtem. */
    public function testPayableWithStoredPeriodIsNotDeferredAsRevenue(): void
    {
        $item = $this->items->create($this->supplierId, ['side' => 'payable', 'counter_account_code' => '648'] + $this->payload(36600.00, self::YEAR . '-07-01'), $this->userId);
        $this->db->pdo()->prepare("UPDATE other_items SET accrual_from = '2091-07-01', accrual_to = '2092-06-30' WHERE id = ?")->execute([(int) $item['id']]);
        $this->items->post($this->supplierId, (int) $item['id'], $this->userId);

        self::assertSame([], $this->closing->deferredRevenueAccrualPreview($this->supplierId, $this->periodId)['items']);
    }

    /** Výkaz mimo deník (průběžné rozlišení) bere pohledávku jen do zaúčtování odkladu. */
    public function testInterimAdjustmentCountsReceivableUntilDeferralIsPosted(): void
    {
        $this->receivable(36600.00, self::YEAR . '-07-01', '2091-07-01', '2092-06-30');
        $this->receivable(3660.00, self::YEAR . '-07-01', '2091-07-01', '2092-06-30');
        $interim = Bootstrap::buildContainer()->get(InterimAccrualAdjustment::class);

        $before = $interim->compute($this->supplierId, self::STARTS_ON, self::ENDS_ON);
        self::assertEqualsWithDelta(20020.00, (float) $before['deferred_revenue'], 0.001);
        self::assertSame(2, $before['documents'], 'Každá pohledávka je vlastní doklad.');
        self::assertEqualsWithDelta(20020.00, (float) $before['deltas']['602'], 0.001);

        $this->driveCloseWithAccrual();
        $after = $interim->compute($this->supplierId, self::STARTS_ON, self::ENDS_ON);
        self::assertEqualsWithDelta(0.0, (float) $after['deferred_revenue'], 0.001);
    }

    /** Víceleté období: otevření dalšího roku rozpustí jen jeho tranši, zbytek zůstane na 384. */
    public function testMultiYearPeriodReleasesOnlyNextYearTranche(): void
    {
        // 1. 7. 2091 – 30. 6. 2093 = 731 dnů; 2092 = 366 dnů, 2093 = 181 dnů.
        $this->receivable(73100.00, self::YEAR . '-07-01', '2091-07-01', '2093-06-30');
        $this->driveCloseWithAccrual();
        $defer = $this->journal->findBySource($this->supplierId, 'deferred_revenue_accrual', ClosingSourceId::deferredRevenueAccrual($this->periodId));
        self::assertNotNull($defer);
        self::assertEqualsWithDelta(54700.00, $this->sideAmount($this->entryLines((int) $defer['id']), '384', 'credit'), 0.001);

        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        $release = $this->journal->findBySource($this->supplierId, 'deferred_revenue_accrual', ClosingSourceId::deferredRevenueAccrualRelease($this->periodId));
        self::assertNotNull($release);
        self::assertEqualsWithDelta(36600.00, $this->sideAmount($this->entryLines((int) $release['id']), '602', 'credit'), 0.001);
    }

    /** @param array<string,mixed>|null $input */
    private function assertAccrualRefused(int $id, string $code, int $status, ?array $input = null): void
    {
        try {
            $this->items->updateAccrual($this->supplierId, $id, $input ?? ['accrual_from' => '2091-07-01', 'accrual_to' => '2092-06-30'], $this->userId);
            self::fail('Změna období měla skončit chybou ' . $code . '.');
        } catch (OtherItemException $e) {
            self::assertSame($code, $e->errorCode);
            self::assertSame($status, $e->httpStatus);
        }
    }

    /** @return array<string,mixed> */
    private function payload(float $amount, string $issuedOn): array
    {
        return [
            'side' => 'receivable', 'kind' => 'claim', 'title' => 'Syntetický nájem',
            'partner_id' => $this->clientId, 'issued_on' => $issuedOn,
            'due_on' => $issuedOn, 'currency' => 'CZK', 'amount' => $amount,
            'counter_account_code' => '602',
        ];
    }

    private function receivable(float $amount, string $issuedOn, ?string $from, ?string $to, bool $post = true): int
    {
        $data = $this->payload($amount, $issuedOn);
        if ($from !== null) {
            $data += ['accrual_from' => $from, 'accrual_to' => $to];
        }
        $item = $this->items->create($this->supplierId, $data, $this->userId);
        if ($post) {
            $this->items->post($this->supplierId, (int) $item['id'], $this->userId);
        }
        return (int) $item['id'];
    }

    private function documentNo(int $id): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT document_no FROM other_items WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$this->supplierId, $id]);
        return (string) $stmt->fetchColumn();
    }

    private function driveCloseWithAccrual(): void
    {
        $sid = $this->supplierId;
        $pid = $this->periodId;
        $this->closing->start($sid, $pid, $this->rv(), $this->meta());
        $this->closing->runPrecheck($sid, $pid, $this->rv(), $this->meta());
        $this->closing->confirmStep($sid, $pid, 'depreciation', 'skipped', null, $this->rv(), $this->meta());
        $this->closing->runFxRevaluation($sid, $pid, [], $this->rv(), $this->meta());
        $this->closing->confirmStep($sid, $pid, 'estimates', 'skipped', null, $this->rv(), $this->meta());
        $this->closing->runDeferredRevenueAccrual($sid, $pid, $this->rv(), $this->meta());
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

    /** @return array<string,array{0:string,1:int}> účet|středisko => [strana, haléře] */
    private function revenueByCenter(int $entryId, int $centerType): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT l.id, a.account_code, l.side, l.amount
               FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.entry_id = ? AND l.supplier_id = ? AND a.account_code LIKE "6%"'
        );
        $stmt->execute([$entryId, $this->supplierId]);
        $dims = (new DimensionAssignmentRepository($this->db))->entryLineDimensions($this->supplierId, $entryId);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $line) {
            $out[$line['account_code'] . '|' . ($dims[(int) $line['id']][$centerType] ?? '-')] = [(string) $line['side'], (int) round((float) $line['amount'] * 100)];
        }
        // Přirozené řazení: id střediska v klíči se porovná jako číslo („602|98“ před „602|100“).
        ksort($out, SORT_NATURAL);
        return $out;
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
}
