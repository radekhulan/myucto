<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\AccrualDeferralService;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\OtherItemException;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Service\Accounting\Pair\JournalPairCorrectionService;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\InterimAccrualAdjustment;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Časové rozlišení nákladu z ostatního závazku (`other_items.accrual_from/to`): uzávěrka
 * odloží na 381 tu část nákladu zaúčtovaného zápisem závazku, která patří do příštích
 * období, a otevření dalšího roku ji rozpustí. Zrcadlo {@see ClosingOtherItemDeferredRevenueTest}
 * pro výnosy ostatních pohledávek a {@see ClosingPrepaidExpenseAccrualTest} pro přijaté faktury.
 * Izolovaný supplier v transakci s rollbackem.
 */
#[Group('integration')]
final class ClosingOtherItemPrepaidExpenseTest extends TestCase
{
    // 2091 = uzavírané období (365 dnů); období nákladu přesahuje do 2092 (přestupný, 366 dnů).
    private const YEAR = 2091;
    private const STARTS_ON = self::YEAR . '-01-01';
    private const ENDS_ON = self::YEAR . '-12-31';

    private Connection $db;
    private ClosingService $closing;
    private AccountingPeriodRepository $periods;
    private JournalEntryRepository $journal;
    private OtherItemService $items;
    private PostingService $posting;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private int $partnerId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
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
            $this->posting = $container->get(PostingService::class);
            $seeder        = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId             = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, ?, ?, ?, "double_entry")'
        )->execute(['Ostatní závazky rozlišení test s.r.o.', $czId, 'other-item-prepaid@example.com', $this->currencyId, $this->vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::STARTS_ON, self::ENDS_ON);

        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "Syntetický pronajímatel", "Test 1", "Praha", "11000", ?, "landlord@example.com", "cs", ?, 0, 1)'
        )->execute([$this->supplierId, $czId, $this->currencyId]);
        $this->partnerId = (int) $pdo->lastInsertId();
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

    public function testPreviewDefersCostOfPostedPayable(): void
    {
        // Roční nájem 36 600 Kč, 1. 7. 2091 – 30. 6. 2092 (366 dnů). Za koncem roku leží
        // 1. 1. – 30. 6. 2092 = 182 dnů → 36 600 × 182/366 = 18 200 Kč na 381.
        $id = $this->payable(36600.00, self::YEAR . '-07-01', '2091-07-01', '2092-06-30');

        $preview = $this->closing->prepaidExpenseAccrualPreview($this->supplierId, $this->periodId);

        self::assertCount(1, $preview['items']);
        $item = $preview['items'][0];
        self::assertSame('other_item', $item['source']);
        self::assertSame($id, $item['other_item_id']);
        self::assertNull($item['purchase_invoice_id']);
        self::assertSame(366, $item['total_days']);
        self::assertSame(182, $item['deferred_days']);
        self::assertSame('518', $item['credit_account']);
        self::assertEqualsWithDelta(18200.00, (float) $item['deferred_amount'], 0.001);
        self::assertEqualsWithDelta(18200.00, (float) $preview['by_account']['518'], 0.001);
        self::assertCount(1, $preview['documents']);
        self::assertSame($id, $preview['documents'][0]['other_item_id']);
        self::assertSame($this->documentNo($id), $preview['documents'][0]['vendor_invoice_number']);
    }

    public function testDraftAndPayableWithoutPeriodAreNotDeferred(): void
    {
        $this->payable(12000.00, self::YEAR . '-07-01', null, null);
        $this->payable(12000.00, self::YEAR . '-07-01', '2091-07-01', '2092-06-30', post: false);

        self::assertSame([], $this->closing->prepaidExpenseAccrualPreview($this->supplierId, $this->periodId)['items']);
    }

    /** Strana určuje stranu rozlišení: pohledávka jen 384, závazek jen 381. */
    public function testEachSideIsDeferredOnlyOnItsOwnAccount(): void
    {
        $this->payable(36600.00, self::YEAR . '-07-01', '2091-07-01', '2092-06-30');
        $receivable = $this->items->create($this->supplierId, [
            'side' => 'receivable', 'kind' => 'claim', 'counter_account_code' => '602',
            'accrual_from' => '2091-07-01', 'accrual_to' => '2092-06-30',
        ] + $this->payload(3660.00, self::YEAR . '-07-01'), $this->userId);
        $this->items->post($this->supplierId, (int) $receivable['id'], $this->userId);

        $prepaid = $this->closing->prepaidExpenseAccrualPreview($this->supplierId, $this->periodId);
        $revenue = $this->closing->deferredRevenueAccrualPreview($this->supplierId, $this->periodId);

        self::assertEqualsWithDelta(18200.00, (float) $prepaid['total'], 0.001);
        self::assertSame(['518'], array_map('strval', array_keys($prepaid['by_account'])));
        self::assertEqualsWithDelta(1820.00, (float) $revenue['total'], 0.001);
        self::assertSame(['602'], array_map('strval', array_keys($revenue['by_account'])));
    }

    public function testDeferralRaisesProfitAndIsReleasedOnOpenNext(): void
    {
        $this->payable(36600.00, self::YEAR . '-07-01', '2091-07-01', '2092-06-30');

        $vhBefore = $this->profitBeforeTax();
        $this->driveCloseWithAccrual();
        self::assertEqualsWithDelta(18200.00, $this->profitBeforeTax() - $vhBefore, 0.001, 'Odklad nákladu zvýší VH o odloženou část.');

        $accrual = $this->journal->findBySource($this->supplierId, 'prepaid_expense_accrual', ClosingSourceId::prepaidExpenseAccrual($this->periodId));
        self::assertNotNull($accrual);
        $lines = $this->entryLines((int) $accrual['id']);
        self::assertEqualsWithDelta(18200.00, $this->sideAmount($lines, '381', 'debit'), 0.001);
        self::assertEqualsWithDelta(18200.00, $this->sideAmount($lines, '518', 'credit'), 0.001);

        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $open = $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        self::assertNotNull($open['prepaid_expense_release_entry_id']);
        $release = $this->journal->findBySource($this->supplierId, 'prepaid_expense_accrual', ClosingSourceId::prepaidExpenseAccrualRelease($this->periodId));
        self::assertNotNull($release);
        $lines = $this->entryLines((int) $release['id']);
        self::assertEqualsWithDelta(18200.00, $this->sideAmount($lines, '518', 'debit'), 0.001);
        self::assertEqualsWithDelta(18200.00, $this->sideAmount($lines, '381', 'credit'), 0.001);
        self::assertSame((self::YEAR + 1) . '-01-01', (string) $release['entry_date']);
        self::assertEqualsWithDelta(0.0, $this->balance381AsOf('2092-12-31'), 0.005);
    }

    /**
     * Rozpad na dva nákladové účty a středisko dokladu: odklad i rozpuštění jdou z každého
     * účtu poměrem jeho obratu a se střediskem, které nese řádek zápisu závazku.
     */
    public function testSplitExpenseAccountsAndCenterAreDeferredAndReleased(): void
    {
        $dimensions = Bootstrap::buildContainer()->get(DimensionService::class);
        $dimensions->setEnabled($this->supplierId, true);
        $center = (int) $dimensions->ensureDefaultTypes($this->supplierId, ['stredisko'])['cost_center'];
        $a = (int) $dimensions->createValue($this->supplierId, $center, ['code' => 'S-A', 'name' => 'Středisko A'])['id'];

        $item = $this->items->create($this->supplierId, [
            'accrual_from' => '2091-07-01', 'accrual_to' => '2092-06-30',
            'posting_lines' => [['account_code' => '518', 'amount' => 24400.00], ['account_code' => '548', 'amount' => 12200.00]],
        ] + $this->payload(36600.00, self::YEAR . '-07-01'), $this->userId);
        $dimensions->saveDocument($this->supplierId, 'other_item', (int) $item['id'], [$center => $a], []);
        $this->items->post($this->supplierId, (int) $item['id'], $this->userId);

        $preview = $this->closing->prepaidExpenseAccrualPreview($this->supplierId, $this->periodId);
        self::assertCount(2, $preview['items'], 'Jeden řádek na nákladový účet zápisu.');
        self::assertCount(1, $preview['documents']);
        self::assertEqualsWithDelta(18200.00, (float) $preview['documents'][0]['deferred_amount'], 0.001);
        self::assertEqualsWithDelta(12133.33, (float) $preview['by_account']['518'], 0.001);
        self::assertEqualsWithDelta(6066.67, (float) $preview['by_account']['548'], 0.001);

        $this->driveCloseWithAccrual();
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        $defer = $this->journal->findBySource($this->supplierId, 'prepaid_expense_accrual', ClosingSourceId::prepaidExpenseAccrual($this->periodId));
        $release = $this->journal->findBySource($this->supplierId, 'prepaid_expense_accrual', ClosingSourceId::prepaidExpenseAccrualRelease($this->periodId));
        self::assertNotNull($defer);
        self::assertNotNull($release);
        self::assertSame([
            '518|' . $a => ['credit', 1_213_333],
            '548|' . $a => ['credit', 606_667],
        ], $this->expenseByCenter((int) $defer['id'], $center), 'Odklad nese středisko řádků zápisu závazku.');
        self::assertSame([
            '518|' . $a => ['debit', 1_213_333],
            '548|' . $a => ['debit', 606_667],
        ], $this->expenseByCenter((int) $release['id'], $center), 'Rozpuštění zrcadlí odklad.');
    }

    /**
     * Přijatá faktura a ostatní závazek v jednom odkladu: součet, dva doklady s vlastním
     * klíčem a uložené ocenění (`item_costs`) jen pro řádky faktur, protože rozpuštění
     * faktur ho čte podle id řádku faktury a id ostatní položky je z jiné řady.
     */
    public function testInvoiceAndPayableShareOneDeferral(): void
    {
        $pi = $this->purchase('PF-2091-SYNT', 3660.00, self::YEAR . '-07-01');
        $piItem = $this->purchaseItem($pi, 3660.00, '2091-07-01', '2092-06-30', '548');
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $pi, [
            ['account_code' => '548', 'side' => 'debit', 'amount' => 3660.00],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 3660.00],
        ], ['entry_date' => self::YEAR . '-07-01', 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        $id = $this->payable(36600.00, self::YEAR . '-07-01', '2091-07-01', '2092-06-30');

        $preview = $this->closing->prepaidExpenseAccrualPreview($this->supplierId, $this->periodId);
        self::assertEqualsWithDelta(20020.00, (float) $preview['total'], 0.001);
        self::assertCount(2, $preview['documents']);
        $byDoc = [];
        foreach ($preview['documents'] as $doc) {
            $byDoc[$doc['purchase_invoice_id'] !== null ? 'pi' : 'oi'] = $doc;
        }
        self::assertSame($pi, $byDoc['pi']['purchase_invoice_id']);
        self::assertNull($byDoc['pi']['other_item_id']);
        self::assertSame($id, $byDoc['oi']['other_item_id']);
        self::assertEqualsWithDelta(1820.00, (float) $byDoc['pi']['deferred_amount'], 0.001);
        self::assertEqualsWithDelta(18200.00, (float) $byDoc['oi']['deferred_amount'], 0.001);

        $this->driveCloseWithAccrual();
        $step = $this->db->pdo()->prepare("SELECT payload FROM accounting_closing_steps WHERE supplier_id = ? AND period_id = ? AND step_key = 'deferrals'");
        $step->execute([$this->supplierId, $this->periodId]);
        $payload = json_decode((string) $step->fetchColumn(), true)['prepaid_expense_accrual'] ?? null;
        self::assertIsArray($payload);
        self::assertSame([(string) $piItem], array_map('strval', array_keys($payload['item_costs'])), 'Ocenění jen pro řádky faktur.');

        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $release = $this->journal->findBySource($this->supplierId, 'prepaid_expense_accrual', ClosingSourceId::prepaidExpenseAccrualRelease($this->periodId));
        self::assertNotNull($release);
        $lines = $this->entryLines((int) $release['id']);
        self::assertEqualsWithDelta(1820.00, $this->sideAmount($lines, '548', 'debit'), 0.001);
        self::assertEqualsWithDelta(18200.00, $this->sideAmount($lines, '518', 'debit'), 0.001);
        self::assertEqualsWithDelta(0.0, $this->balance381AsOf('2092-12-31'), 0.005);
    }

    /**
     * Dva ostatní závazky v jednom odkladu: každý je v náhledu vlastní doklad. Klíč dokladu
     * nesloučí ani fakturu a závazek se stejným číslem id.
     */
    public function testEachPayableIsOwnDocumentInPreview(): void
    {
        $first = $this->payable(36600.00, self::YEAR . '-07-01', '2091-07-01', '2092-06-30');
        $second = $this->payable(3660.00, self::YEAR . '-07-01', '2091-07-01', '2092-06-30');

        $preview = $this->closing->prepaidExpenseAccrualPreview($this->supplierId, $this->periodId);

        self::assertCount(2, $preview['documents']);
        $amounts = array_column($preview['documents'], 'deferred_amount', 'other_item_id');
        self::assertEqualsWithDelta(18200.00, (float) $amounts[$first], 0.001);
        self::assertEqualsWithDelta(1820.00, (float) $amounts[$second], 0.001);
        self::assertNotSame(
            AccrualDeferralService::prepaidExpenseDocumentKey(['source' => 'other_item', 'other_item_id' => 5, 'purchase_invoice_id' => null]),
            AccrualDeferralService::prepaidExpenseDocumentKey(['source' => 'purchase_invoice', 'other_item_id' => null, 'purchase_invoice_id' => 5]),
            'Faktura a závazek se stejným id jsou dva doklady.',
        );
    }

    /** Víceleté období: otevření dalšího roku rozpustí jen jeho tranši, zbytek zůstane na 381. */
    public function testMultiYearPeriodReleasesOnlyNextYearTranche(): void
    {
        // 1. 7. 2091 – 30. 6. 2093 = 731 dnů; 2092 = 366 dnů, 2093 = 181 dnů.
        $this->payable(73100.00, self::YEAR . '-07-01', '2091-07-01', '2093-06-30');
        $this->driveCloseWithAccrual();
        $defer = $this->journal->findBySource($this->supplierId, 'prepaid_expense_accrual', ClosingSourceId::prepaidExpenseAccrual($this->periodId));
        self::assertNotNull($defer);
        self::assertEqualsWithDelta(54700.00, $this->sideAmount($this->entryLines((int) $defer['id']), '381', 'debit'), 0.001);

        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        $release = $this->journal->findBySource($this->supplierId, 'prepaid_expense_accrual', ClosingSourceId::prepaidExpenseAccrualRelease($this->periodId));
        self::assertNotNull($release);
        self::assertEqualsWithDelta(36600.00, $this->sideAmount($this->entryLines((int) $release['id']), '518', 'debit'), 0.001);
        self::assertEqualsWithDelta(18100.00, $this->balance381AsOf('2092-12-31'), 0.005);
    }

    /**
     * Víceleté období a storno závazku v N+1: storno je záporný zdroj se stejným obdobím
     * (jako dobropis), uzávěrka N+1 proto vrátí zbylou tranši do nákladů roku storna a 381
     * se vyrovná už k jeho konci; otevření N+2 nic nerozpouští.
     */
    public function testReversalInNextYearReturnsRemainingTrancheInThatYear(): void
    {
        $id = $this->payable(73100.00, self::YEAR . '-07-01', '2091-07-01', '2093-06-30');
        $this->driveCloseWithAccrual();
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->items->reverse($this->supplierId, $id, 'Syntetické storno', $this->userId, (self::YEAR + 1) . '-03-01');

        $this->periodId = $this->periodOf(self::YEAR + 1);
        $this->distributeProfit((self::YEAR + 1) . '-01-02');
        $this->driveCloseWithAccrual();
        $defer = $this->journal->findBySource($this->supplierId, 'prepaid_expense_accrual', ClosingSourceId::prepaidExpenseAccrual($this->periodId));
        self::assertNotNull($defer);
        $lines = $this->entryLines((int) $defer['id']);
        self::assertEqualsWithDelta(18100.00, $this->sideAmount($lines, '518', 'debit'), 0.001, 'Storno vrátí zbylou tranši do nákladů roku storna.');
        self::assertEqualsWithDelta(0.0, $this->balance381AsOf('2092-12-31'), 0.005);

        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        self::assertEqualsWithDelta(0.0, $this->balance381AsOf('2093-12-31'), 0.005);
        self::assertEqualsWithDelta(0.0, $this->costOf('518', '2093-01-01', '2093-12-31'), 0.005, 'Zrušený závazek v N+2 náklad nemá.');
    }

    /**
     * Víceleté období a přeúčtování závazku do N+1 na jiný nákladový účet: storno původního
     * zápisu vrátí zbylou tranši z 518, nový zápis ji odloží na 548, otevření N+2 ji
     * rozpustí jen na 548. Náklad za každý rok i po účtech sedí, 381 se vyrovná.
     */
    public function testRepostIntoNextYearMovesRemainingTrancheToNewAccount(): void
    {
        $id = $this->payable(73100.00, self::YEAR . '-07-01', '2091-07-01', '2093-06-30');
        $this->driveCloseWithAccrual();
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->items->repost($this->supplierId, $id, [
            'reason' => 'Syntetický přesun', 'entry_date' => (self::YEAR + 1) . '-03-01',
            'account_code' => '325', 'counter_account_code' => '548',
        ], $this->userId);

        $this->periodId = $this->periodOf(self::YEAR + 1);
        $this->distributeProfit((self::YEAR + 1) . '-01-02');
        $this->driveCloseWithAccrual();
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        // 518: rozpuštění 36 600 − storno 73 100 + vrácená tranše 18 100 = −18 400;
        // 548: nový zápis 73 100 − odklad tranše N+2 18 100 = 55 000. Rok N+1 celkem 36 600.
        self::assertEqualsWithDelta(-18400.00, $this->costOf('518', '2092-01-01', '2092-12-31'), 0.005);
        self::assertEqualsWithDelta(55000.00, $this->costOf('548', '2092-01-01', '2092-12-31'), 0.005);
        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        self::assertEqualsWithDelta(0.0, $this->costOf('518', '2093-01-01', '2093-12-31'), 0.005);
        self::assertEqualsWithDelta(18100.00, $this->costOf('548', '2093-01-01', '2093-12-31'), 0.005);
        self::assertEqualsWithDelta(0.0, $this->balance381AsOf('2093-12-31'), 0.005);
    }

    /**
     * Závazek přeúčtovaný do dalšího roku: odklad roku N šel z původního zápisu, proto se
     * období nemění, dokud je rok N uzavřený.
     */
    public function testPeriodGuardCoversEarlierEntryOfRepostedPayable(): void
    {
        $id = $this->payable(36600.00, self::YEAR . '-07-01', '2091-07-01', '2092-06-30');
        $this->driveCloseWithAccrual();
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->periods->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
        $this->items->repost($this->supplierId, $id, [
            'reason' => 'Syntetický přesun', 'entry_date' => (self::YEAR + 1) . '-02-01',
            'account_code' => '325', 'counter_account_code' => '548',
        ], $this->userId);

        $this->assertAccrualRefused($id, 'period_closed', 409, ['accrual_to' => '2092-12-31']);
    }

    /**
     * Oprava nákladové dvojice zpětně datovaná do dřívějšího roku je samostatný zdroj svého
     * roku: do zdroje roku N se nezapočte, odklad N jde dál z 518 celou částkou.
     */
    public function testBackdatedPairCorrectionIsSeparateSource(): void
    {
        $this->db->pdo()->prepare("INSERT INTO accounting_entry_form_history (supplier_id, valid_from, entry_form) VALUES (?, ?, 'single')")
            ->execute([$this->supplierId, (self::YEAR - 1) . '-01-01']);
        $this->periods->create($this->supplierId, self::YEAR - 1, (self::YEAR - 1) . '-01-01', (self::YEAR - 1) . '-12-31');
        $id = $this->payable(36600.00, self::YEAR . '-07-01', '2091-07-01', '2092-06-30');
        $entryId = (int) $this->items->get($this->supplierId, $id)['journal_entry_id'];
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")->execute([$this->periodId]);
        $result = Bootstrap::buildContainer()->get(JournalPairCorrectionService::class)->repostPair($this->supplierId, $entryId, 1,
            ['debit_account_code' => '548', 'credit_account_code' => '325'],
            $this->meta() + ['entry_date' => (self::YEAR - 1) . '-12-20', 'description' => 'Syntetická zpětná oprava']);
        self::assertSame('correction_entry', $result['mode']);

        $preview = $this->closing->prepaidExpenseAccrualPreview($this->supplierId, $this->periodId);

        self::assertSame(['518'], array_map('strval', array_keys($preview['by_account'])));
        self::assertEqualsWithDelta(18200.00, (float) $preview['total'], 0.001);

        // Rok N−1: oprava je zdroj svého roku (náklad MD − D), celé období leží až za ním.
        $earlier = $this->closing->prepaidExpenseAccrualPreview($this->supplierId, $this->periodOf(self::YEAR - 1));
        self::assertEqualsWithDelta(-36600.00, (float) ($earlier['by_account']['518'] ?? 0), 0.001);
        self::assertEqualsWithDelta(36600.00, (float) ($earlier['by_account']['548'] ?? 0), 0.001);
    }

    /** Závazek zaúčtovaný rovnou na 381 nese odklad sám, uzávěrka ho neodkládá podruhé. */
    public function testEntryAlreadyCarryingDeferralAccountIsSkipped(): void
    {
        $item = $this->items->create($this->supplierId, ['counter_account_code' => '381', 'accrual_from' => '2091-07-01', 'accrual_to' => '2092-06-30']
            + $this->payload(36600.00, self::YEAR . '-07-01'), $this->userId);
        $this->items->post($this->supplierId, (int) $item['id'], $this->userId);

        self::assertSame([], $this->closing->prepaidExpenseAccrualPreview($this->supplierId, $this->periodId)['items']);
    }

    /** Výkaz mimo deník (průběžné rozlišení) bere závazek jen do zaúčtování odkladu. */
    public function testInterimAdjustmentCountsPayableUntilDeferralIsPosted(): void
    {
        $this->payable(36600.00, self::YEAR . '-07-01', '2091-07-01', '2092-06-30');
        $this->payable(3660.00, self::YEAR . '-07-01', '2091-07-01', '2092-06-30');
        $interim = Bootstrap::buildContainer()->get(InterimAccrualAdjustment::class);

        $before = $interim->compute($this->supplierId, self::STARTS_ON, self::ENDS_ON);
        self::assertEqualsWithDelta(20020.00, (float) $before['prepaid_expense'], 0.001);
        self::assertSame(2, $before['documents'], 'Každý závazek je vlastní doklad.');
        self::assertEqualsWithDelta(-20020.00, (float) $before['deltas']['518'], 0.001);
        self::assertEqualsWithDelta(20020.00, (float) $before['deltas']['381'], 0.001);

        $this->driveCloseWithAccrual();
        $after = $interim->compute($this->supplierId, self::STARTS_ON, self::ENDS_ON);
        self::assertEqualsWithDelta(0.0, (float) $after['prepaid_expense'], 0.001);
    }

    // ── validace a pojistka ───────────────────────────────────────────────────

    public function testPayablePeriodIsStoredAndSetAfterPosting(): void
    {
        $draft = $this->payable(1200.00, self::YEAR . '-03-01', '2091-03-01', '2092-02-29', post: false);
        $item = $this->items->get($this->supplierId, $draft);
        self::assertSame('2091-03-01', substr((string) $item['accrual_from'], 0, 10));
        self::assertSame('2092-02-29', substr((string) $item['accrual_to'], 0, 10));

        $posted = $this->payable(36600.00, self::YEAR . '-07-01', null, null);
        $result = $this->items->updateAccrual($this->supplierId, $posted, ['accrual_from' => '2091-07-01', 'accrual_to' => '2092-06-30'], $this->userId);

        self::assertSame(['2091-07-01', '2092-06-30'], $result['after']);
        self::assertEqualsWithDelta(18200.00, (float) $this->closing->prepaidExpenseAccrualPreview($this->supplierId, $this->periodId)['total'], 0.001);
    }

    public function testTaxEvidenceRejectsPayablePeriod(): void
    {
        $this->db->pdo()->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = ?")->execute([$this->supplierId]);

        try {
            $this->payable(1000.00, self::YEAR . '-07-01', '2091-07-01', '2092-06-30', post: false);
            self::fail('Daňová evidence náklad v deníku nemá, období rozlišení se odmítne.');
        } catch (OtherItemException $e) {
            self::assertSame('accrual_double_entry_only', $e->errorCode);
            self::assertSame(422, $e->httpStatus);
        }
    }

    /**
     * Daňová evidence: uložené období jde ponechat jen u téže strany. U jiné strany by výnos
     * přešel v náklad, proto se odmítne jako nové období.
     */
    public function testTaxEvidenceKeepsStoredPeriodOnlyForSameSide(): void
    {
        $id = $this->payable(1000.00, self::YEAR . '-07-01', '2091-07-01', '2092-06-30', post: false);
        $this->db->pdo()->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = ?")->execute([$this->supplierId]);

        $item = $this->items->update($this->supplierId, $id, ['title' => 'Syntetické nájemné',
            'accrual_from' => '2091-07-01', 'accrual_to' => '2092-06-30'], $this->userId);
        self::assertSame('2091-07-01', substr((string) $item['accrual_from'], 0, 10));
        try {
            $this->items->update($this->supplierId, $id, ['side' => 'receivable', 'kind' => 'claim',
                'accrual_from' => '2091-07-01', 'accrual_to' => '2092-06-30', 'accrual_side' => 'receivable'], $this->userId);
            self::fail('Období jiné strany v daňové evidenci prošlo jako ponechané.');
        } catch (OtherItemException $e) {
            self::assertSame('accrual_double_entry_only', $e->errorCode);
        }
    }

    /** Uložený závazek s obdobím: úprava konceptu bez polí období ho ponechá. */
    public function testPayableDraftUpdateKeepsPeriod(): void
    {
        $id = $this->payable(1200.00, self::YEAR . '-03-01', '2091-03-01', '2092-02-29', post: false);

        $updated = $this->items->update($this->supplierId, $id, ['title' => 'Syntetický závazek upravený'], $this->userId);

        self::assertSame('2091-03-01', substr((string) $updated['accrual_from'], 0, 10));
        self::assertSame('2092-02-29', substr((string) $updated['accrual_to'], 0, 10));
    }

    /**
     * Přepnutí strany mění význam období (náklad ↔ výnos): období poslané beze změny se
     * zahodí v obou směrech, nové období zadané spolu se stranou zůstane.
     */
    public function testSwitchingSideDropsUnchangedPeriod(): void
    {
        $id = $this->payable(1200.00, self::YEAR . '-03-01', '2091-03-01', '2092-02-29', post: false);

        $item = $this->items->update($this->supplierId, $id, ['side' => 'receivable', 'kind' => 'claim', 'counter_account_code' => '602',
            'accrual_from' => '2091-03-01', 'accrual_to' => '2092-02-29'], $this->userId);
        self::assertSame('receivable', $item['side']);
        self::assertNull($item['accrual_from']);

        $item = $this->items->update($this->supplierId, $id, ['accrual_from' => '2091-04-01', 'accrual_to' => '2092-03-31'], $this->userId);
        self::assertSame('2091-04-01', substr((string) $item['accrual_from'], 0, 10));
        $item = $this->items->update($this->supplierId, $id, ['side' => 'payable', 'kind' => 'rent', 'counter_account_code' => '518'], $this->userId);
        self::assertSame('payable', $item['side']);
        self::assertNull($item['accrual_from']);
        self::assertNull($item['accrual_to']);

        $item = $this->items->update($this->supplierId, $id, ['side' => 'receivable', 'kind' => 'claim', 'counter_account_code' => '602',
            'accrual_from' => '2091-05-01', 'accrual_to' => '2092-04-30'], $this->userId);
        self::assertSame('2091-05-01', substr((string) $item['accrual_from'], 0, 10), 'Nové období spolu se stranou zůstane.');
    }

    /**
     * Stejné období zadané záměrně pro novou stranu (`accrual_side`) zůstane; bez označení
     * ho server bere jako vrácený uložený stav a zahodí. Označení jiné strany nic nezachrání.
     */
    public function testSwitchingSideKeepsSamePeriodMarkedForNewSide(): void
    {
        $id = $this->payable(1200.00, self::YEAR . '-03-01', '2091-03-01', '2092-02-29', post: false);
        $switch = ['side' => 'receivable', 'kind' => 'claim', 'counter_account_code' => '602',
            'accrual_from' => '2091-03-01', 'accrual_to' => '2092-02-29'];

        $item = $this->items->update($this->supplierId, $id, $switch + ['accrual_side' => 'payable'], $this->userId);
        self::assertNull($item['accrual_from'], 'Období označené pro původní stranu se zahodí.');

        $item = $this->items->update($this->supplierId, $id, ['side' => 'payable', 'kind' => 'rent', 'counter_account_code' => '518',
            'accrual_from' => '2091-03-01', 'accrual_to' => '2092-02-29'], $this->userId);
        self::assertSame('2091-03-01', substr((string) $item['accrual_from'], 0, 10));
        $item = $this->items->update($this->supplierId, $id, $switch + ['accrual_side' => 'receivable'], $this->userId);
        self::assertSame('receivable', $item['side']);
        self::assertSame('2091-03-01', substr((string) $item['accrual_from'], 0, 10));
        self::assertSame('2092-02-29', substr((string) $item['accrual_to'], 0, 10));
        self::assertArrayNotHasKey('accrual_side', $item);
        // Aktivita ukáže, že shodné období změnilo význam (náklad → výnos).
        $log = $this->db->pdo()->prepare("SELECT payload FROM activity_log WHERE supplier_id = ? AND entity_type = 'other_item'
            AND entity_id = ? AND action = 'other_item.accrual_changed' ORDER BY id DESC LIMIT 1");
        $log->execute([$this->supplierId, $id]);
        $payload = json_decode((string) $log->fetchColumn(), true);
        self::assertSame(['before' => 'payable', 'after' => 'receivable'], $payload['side'] ?? null);
        self::assertSame(['before' => '2091-03-01', 'after' => '2091-03-01'], $payload['accrual_from'] ?? null);

        try {
            $this->items->update($this->supplierId, $id, ['accrual_side' => 'income'], $this->userId);
            self::fail('Neplatná strana období musí skončit validační chybou.');
        } catch (OtherItemException $e) {
            self::assertSame('validation_failed', $e->errorCode);
        }
    }

    /** Závazek z uzavřeného roku: pojistka čte nákladové zdroje (5xx), ne výnosové. */
    public function testClosedPeriodRefusesPayablePeriodChange(): void
    {
        $id = $this->payable(36600.00, self::YEAR . '-07-01', '2091-07-01', '2092-06-30');
        foreach (['closed', 'reviewed', 'approved'] as $status) {
            $this->db->pdo()->prepare('UPDATE accounting_periods SET status = ? WHERE id = ?')->execute([$status, $this->periodId]);
            $this->assertAccrualRefused($id, 'period_closed', 409, ['accrual_to' => '2092-12-31']);
        }
    }

    /**
     * Zápis odkladu nákladů (381) v probíhající uzávěrce blokuje změnu období závazku už
     * před potvrzením kroku: rozpuštění by jinak počítalo z jiného období, než odložila.
     */
    public function testPostedPrepaidDeferralBlocksPayableChangeBeforeStepIsConfirmed(): void
    {
        $id = $this->payable(36600.00, self::YEAR . '-07-01', '2091-07-01', '2092-06-30');
        $sid = $this->supplierId;
        $pid = $this->periodId;
        $this->closing->start($sid, $pid, $this->rv(), $this->meta());
        $this->closing->runPrecheck($sid, $pid, $this->rv(), $this->meta());
        $this->closing->confirmStep($sid, $pid, 'depreciation', 'skipped', null, $this->rv(), $this->meta());
        $this->closing->runFxRevaluation($sid, $pid, [], $this->rv(), $this->meta());
        $this->closing->confirmStep($sid, $pid, 'estimates', 'skipped', null, $this->rv(), $this->meta());
        $this->closing->runPrepaidExpenseAccrual($sid, $pid, $this->rv(), $this->meta());

        $this->assertAccrualRefused($id, 'accrual_deferral_posted', 409, ['accrual_to' => '2092-12-31']);
    }

    public function testPeriodChangeAfterConfirmedDeferralStepIsRefused(): void
    {
        $id = $this->payable(36600.00, self::YEAR . '-07-01', '2091-07-01', '2092-06-30');
        $this->driveCloseWithAccrual();

        $this->assertAccrualRefused($id, 'accrual_deferral_posted', 409, ['accrual_to' => '2092-12-31']);
    }

    /** Zápis rozdělený na náklad 518 a 381 (odklad zadaný při zaúčtování) se neodkládá podruhé. */
    public function testSplitEntryWithDeferralAccountIsSkipped(): void
    {
        $item = $this->items->create($this->supplierId, ['accrual_from' => '2091-07-01', 'accrual_to' => '2092-06-30',
            'posting_lines' => [['account_code' => '518', 'amount' => 18400.00], ['account_code' => '381', 'amount' => 18200.00]]]
            + $this->payload(36600.00, self::YEAR . '-07-01'), $this->userId);
        $this->items->post($this->supplierId, (int) $item['id'], $this->userId);

        self::assertSame([], $this->closing->prepaidExpenseAccrualPreview($this->supplierId, $this->periodId)['items']);
    }

    /** Změna strany konceptu se zaúčtovanou historií v uzavřeném roce zahodí období → pojistka. */
    public function testSwitchingSideOfDraftWithClosedHistoryIsRefused(): void
    {
        $id = $this->payable(36600.00, self::YEAR . '-07-01', '2091-07-01', '2092-06-30');
        $this->driveCloseWithAccrual();
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->db->pdo()->prepare("UPDATE other_items SET status = 'draft' WHERE id = ?")->execute([$id]);

        try {
            $this->items->update($this->supplierId, $id, ['side' => 'receivable', 'kind' => 'claim', 'counter_account_code' => '602'], $this->userId);
            self::fail('Přepnutí strany zahodí období, ze kterého uzavřený rok odložil.');
        } catch (OtherItemException $e) {
            self::assertSame('period_closed', $e->errorCode);
        }
    }

    /** Smazání konceptu závazku s obdobím a historií v uzavřeném roce hlídá pojistka strany 381. */
    public function testDeletingPayableDraftWithPeriodAndClosedHistoryIsRefused(): void
    {
        $id = $this->payable(36600.00, self::YEAR . '-07-01', '2091-07-01', '2092-06-30');
        $plain = $this->payable(1000.00, self::YEAR . '-07-01', null, null);
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ?")->execute([$this->periodId]);
        $this->db->pdo()->prepare("UPDATE other_items SET status = 'draft' WHERE id IN (?, ?)")->execute([$id, $plain]);

        try {
            $this->items->deleteDraft($this->supplierId, $id);
            self::fail('Smazání konceptu závazku s obdobím z uzavřeného roku prošlo.');
        } catch (OtherItemException $e) {
            self::assertSame('period_closed', $e->errorCode);
        }
        self::assertSame('draft', $this->items->get($this->supplierId, $id)['status']);
        $this->items->deleteDraft($this->supplierId, $plain);
    }

    /**
     * Pohledávka zaúčtovaná proti nákladu (přefakturace, D 518) přepnutá na závazek s novým
     * obdobím: uzávěrka by z jejího zápisu odkládala náklad, proto pojistka prověří i novou
     * stranu a uzavřený rok změnu odmítne.
     */
    public function testSwitchingCrossPostedDraftToPayableWithPeriodIsRefused(): void
    {
        $id = $this->receivableDraftInClosedYear('518', false);

        try {
            $this->items->update($this->supplierId, $id, ['side' => 'payable', 'kind' => 'rent',
                'accrual_from' => '2091-07-01', 'accrual_to' => '2092-06-30'], $this->userId);
            self::fail('Nové období u změněné strany prošlo přes zdroj nákladu v uzavřeném roce.');
        } catch (OtherItemException $e) {
            self::assertSame('period_closed', $e->errorCode);
        }
        self::assertNull($this->items->get($this->supplierId, $id)['accrual_from']);
    }

    /**
     * Stejné období zadané záměrně pro novou stranu (`accrual_side`) je změna období: pojistka
     * prověří obě strany a uzavřený rok změnu odmítne v obou směrech.
     */
    public function testSwitchingSideWithSamePeriodMarkedForNewSideIsGuarded(): void
    {
        foreach ([['518', 'payable', 'rent'], ['602', 'payable', 'rent']] as [$counter, $side, $kind]) {
            $id = $this->receivableDraftInClosedYear($counter, true);
            try {
                $this->items->update($this->supplierId, $id, ['side' => $side, 'kind' => $kind,
                    'accrual_from' => '2091-07-01', 'accrual_to' => '2092-06-30', 'accrual_side' => $side], $this->userId);
                self::fail('Stejné období u nové strany prošlo přes zápis v uzavřeném roce (' . $counter . ').');
            } catch (OtherItemException $e) {
                self::assertSame('period_closed', $e->errorCode);
            }
            self::assertSame('receivable', $this->items->get($this->supplierId, $id)['side']);
            $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'open' WHERE id = ?")->execute([$this->periodId]);
        }
    }

    /**
     * Protipříklad: tatáž pohledávka s obdobím přepnutá na závazek bez období. Z jejího
     * zápisu (bez 6xx) uzávěrka nic neodložila a závazek bez období odkládat nebude, změna
     * proto projde i s uzavřeným rokem.
     */
    public function testSwitchingCrossPostedDraftToPayableWithoutPeriodIsAllowed(): void
    {
        $id = $this->receivableDraftInClosedYear('518', true);

        $updated = $this->items->update($this->supplierId, $id, ['side' => 'payable', 'kind' => 'rent'], $this->userId);

        self::assertSame('payable', $updated['side']);
        self::assertNull($updated['accrual_from']);
    }

    /**
     * Zpětná oprava nákladové dvojice do N−1 celým cyklem N−1 → N → N+1: náklad 518 je každý
     * rok nulový, 548 nese tranše 18 400 (N) a 18 200 (N+1), 381 se vyrovná.
     */
    public function testBackdatedPairCorrectionFullCycle(): void
    {
        $this->db->pdo()->prepare("INSERT INTO accounting_entry_form_history (supplier_id, valid_from, entry_form) VALUES (?, ?, 'single')")
            ->execute([$this->supplierId, (self::YEAR - 1) . '-01-01']);
        $earlierId = $this->periods->create($this->supplierId, self::YEAR - 1, (self::YEAR - 1) . '-01-01', (self::YEAR - 1) . '-12-31');
        $id = $this->payable(36600.00, self::YEAR . '-07-01', '2091-07-01', '2092-06-30');
        $entryId = (int) $this->items->get($this->supplierId, $id)['journal_entry_id'];
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")->execute([$this->periodId]);
        self::assertSame('correction_entry', Bootstrap::buildContainer()->get(JournalPairCorrectionService::class)->repostPair($this->supplierId, $entryId, 1,
            ['debit_account_code' => '548', 'credit_account_code' => '325'],
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

        foreach ([[self::YEAR - 1, 0.0, 0.0], [self::YEAR, 0.0, 18400.00], [self::YEAR + 1, 0.0, 18200.00]] as [$year, $on518, $on548]) {
            self::assertEqualsWithDelta($on518, $this->costOf('518', $year . '-01-01', $year . '-12-31'), 0.005, '518 v roce ' . $year);
            self::assertEqualsWithDelta($on548, $this->costOf('548', $year . '-01-01', $year . '-12-31'), 0.005, '548 v roce ' . $year);
        }
        self::assertEqualsWithDelta(0.0, $this->balance381AsOf((self::YEAR + 1) . '-12-31'), 0.005);
    }

    /**
     * Přeskočený krok Časové rozlišení bez zápisu změnu období závazku nebrání, stejně jako
     * u řádků faktur (AccrualDeferralService::accrualChangeBlocker): nic neodložil.
     */
    public function testSkippedDeferralStepDoesNotBlockPayableChange(): void
    {
        $id = $this->payable(36600.00, self::YEAR . '-07-01', '2091-07-01', '2092-06-30');
        $sid = $this->supplierId;
        $pid = $this->periodId;
        $this->closing->start($sid, $pid, $this->rv(), $this->meta());
        $this->closing->runPrecheck($sid, $pid, $this->rv(), $this->meta());
        $this->closing->confirmStep($sid, $pid, 'depreciation', 'skipped', null, $this->rv(), $this->meta());
        $this->closing->runFxRevaluation($sid, $pid, [], $this->rv(), $this->meta());
        $this->closing->confirmStep($sid, $pid, 'estimates', 'skipped', null, $this->rv(), $this->meta());
        $this->closing->confirmStep($sid, $pid, 'deferrals', 'skipped', 'Syntetické přeskočení', $this->rv(), $this->meta());

        $this->items->updateAccrual($sid, $id, ['accrual_to' => '2092-12-31'], $this->userId);
        self::assertSame('2092-12-31', substr((string) $this->items->get($sid, $id)['accrual_to'], 0, 10));
    }

    /**
     * Rozpuštění zrcadlí odklad nákladů zaúčtovaný uzávěrkou (#384): krok uloží rozpad
     * ostatního závazku do záznamu a openNext z něj rozpustí i po pozdější změně období
     * (zde přímo v databázi, mimo pojistku), stejně jako u řádků přijatých faktur.
     */
    public function testReleaseMirrorsPostedDeferralOfPayable(): void
    {
        $id = $this->payable(36600.00, self::YEAR . '-07-01', '2091-07-01', '2092-06-30');
        $this->driveCloseWithAccrual();
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        $stmt = $this->db->pdo()->prepare("SELECT payload FROM accounting_closing_steps WHERE supplier_id = ? AND period_id = ? AND step_key = 'deferrals'");
        $stmt->execute([$this->supplierId, $this->periodId]);
        $snapshot = json_decode((string) $stmt->fetchColumn(), true)['prepaid_expense_accrual']['items'];
        self::assertCount(1, $snapshot);
        self::assertSame('other_item', $snapshot[0]['source']);
        self::assertSame($id, (int) $snapshot[0]['doc_id']);

        $this->db->pdo()->prepare('UPDATE other_items SET accrual_to = ? WHERE supplier_id = ? AND id = ?')
            ->execute(['2092-12-31', $this->supplierId, $id]);
        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        $release = $this->journal->findBySource($this->supplierId, 'prepaid_expense_accrual', ClosingSourceId::prepaidExpenseAccrualRelease($this->periodId));
        self::assertNotNull($release);
        self::assertEqualsWithDelta(18200.00, $this->sideAmount($this->entryLines((int) $release['id']), '518', 'debit'), 0.001);
        self::assertEqualsWithDelta(0.0, $this->balance381AsOf('2092-12-31'), 0.005);
    }

    /**
     * Koncept pohledávky bez období (výnos 6xx v uzavřeném roce) přepnutý na závazek s obdobím:
     * stará strana období neměla, uzávěrka z ní nic neodložila, a zápis nemá náklad 5xx, takže
     * nová strana také nic. Změna projde.
     */
    public function testSwitchingReceivableWithoutPeriodToPayableWithPeriodIsAllowed(): void
    {
        $id = $this->receivableDraftInClosedYear('602', false);

        $updated = $this->items->update($this->supplierId, $id, ['side' => 'payable', 'kind' => 'rent',
            'accrual_from' => '2092-01-01', 'accrual_to' => '2092-12-31'], $this->userId);

        self::assertSame('2092-01-01', substr((string) $updated['accrual_from'], 0, 10));
    }

    // ── pomocné metody ────────────────────────────────────────────────────────

    /**
     * Zaúčtovaná pohledávka (36 600, 1. 7. N) s protiúčtem `$counter`, případně s obdobím
     * 1. 7. N – 30. 6. N+1, vrácená do konceptu se zaúčtovanou historií v uzavřeném roce N.
     */
    private function receivableDraftInClosedYear(string $counter, bool $withPeriod): int
    {
        $item = $this->items->create($this->supplierId, [
            'side' => 'receivable', 'kind' => 'claim', 'title' => 'Syntetický nárok',
            'partner_id' => $this->partnerId, 'issued_on' => self::YEAR . '-07-01', 'due_on' => self::YEAR . '-07-01',
            'currency' => 'CZK', 'amount' => 36600.00, 'counter_account_code' => $counter,
        ] + ($withPeriod ? ['accrual_from' => '2091-07-01', 'accrual_to' => '2092-06-30'] : []), $this->userId);
        $id = (int) $item['id'];
        $this->items->post($this->supplierId, $id, $this->userId);
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ?")->execute([$this->periodId]);
        $this->db->pdo()->prepare("UPDATE other_items SET status = 'draft' WHERE id = ?")->execute([$id]);
        return $id;
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
            'side' => 'payable', 'kind' => 'rent', 'title' => 'Syntetické nájemné',
            'partner_id' => $this->partnerId, 'issued_on' => $issuedOn,
            'due_on' => $issuedOn, 'currency' => 'CZK', 'amount' => $amount,
            'counter_account_code' => '518',
        ];
    }

    private function payable(float $amount, string $issuedOn, ?string $from, ?string $to, bool $post = true): int
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

    private function purchase(string $number, float $base, string $issue): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, exchange_rate, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                 vat_deduction, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, NULL, 0, "{}", ?, 0, ?, "received", "40", "full", ?)'
        )->execute([
            $this->supplierId, $this->partnerId, $number, $issue, $issue, $issue, $issue,
            $this->currencyId, $base, $base, $this->userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function purchaseItem(int $purchaseId, float $base, string $from, string $to, string $account): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index,
                 expense_account_code, accrual_from, accrual_to)
             VALUES (?, "Pojistné", 1, "ks", ?, ?, 21.00, ?, 0, ?, 0, ?, ?, ?)'
        )->execute([$purchaseId, $base, $this->vatRateId, $base, $base, $account, $from, $to]);
        return (int) $this->db->pdo()->lastInsertId();
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
        $this->closing->runPrepaidExpenseAccrual($sid, $pid, $this->rv(), $this->meta());
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
        $this->posting->postDocument($this->supplierId, 'manual', null, $lines,
            ['entry_date' => $date, 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId, 'description' => 'Syntetické rozdělení VH']);
    }

    private function periodOf(int $year): int
    {
        return (int) $this->db->pdo()->query('SELECT id FROM accounting_periods WHERE supplier_id = ' . $this->supplierId . ' AND fiscal_year = ' . $year)->fetchColumn();
    }

    /** Čistý náklad (MD − D) účtu za období, bez uzávěrkových zápisů. */
    private function costOf(string $code, string $from, string $to): float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e   ON e.id = l.entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL AND e.source_type <> 'closing'
                AND a.account_code = ? AND e.entry_date BETWEEN ? AND ?"
        );
        $stmt->execute([$this->supplierId, $code, $from, $to]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    private function balance381AsOf(string $date): float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side='debit' THEN l.amount ELSE -l.amount END),0)
               FROM journal_entry_lines l
               JOIN journal_entries e   ON e.id = l.entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL
                AND a.account_code = '381' AND e.entry_date <= ?"
        );
        $stmt->execute([$this->supplierId, $date]);
        return round((float) $stmt->fetchColumn(), 2);
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
    private function expenseByCenter(int $entryId, int $centerType): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT l.id, a.account_code, l.side, l.amount
               FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.entry_id = ? AND l.supplier_id = ? AND a.account_code LIKE "5%"'
        );
        $stmt->execute([$entryId, $this->supplierId]);
        $dims = (new DimensionAssignmentRepository($this->db))->entryLineDimensions($this->supplierId, $entryId);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $line) {
            $out[$line['account_code'] . '|' . ($dims[(int) $line['id']][$centerType] ?? '-')] = [(string) $line['side'], (int) round((float) $line['amount'] * 100)];
        }
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
