<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;
use MyInvoice\Service\Accounting\PostingService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Integrační testy Task 12: časové rozlišení nákladů příštích období (381) z řádků přijatých
 * faktur označených obdobím od–do (accrual_from/accrual_to). Ověřuje pro-rata dle dnů (přesah
 * přes konec roku), EUR přepočet kurzem dokladu, idempotentní zaúčtování MD 381 / D 5xx +
 * rozpuštění v N+1 (open_next), podvojnost a předpoklad Tasku 10 — že zaúčtovaný
 * source_type='prepaid_expense_accrual' vstupuje do VH před zdaněním (profitBeforeTax).
 * Izolovaný supplier v transakci s rollbackem.
 */
#[Group('integration')]
final class ClosingPrepaidExpenseAccrualTest extends TestCase
{
    // 2091 = uzavírané období (365 dnů); rozlišení přesahuje do 2092 (přestupný, 366 dnů).
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
    private int $eurId = 0;
    private int $vatRateId = 0;
    private int $czId = 0;
    private int $vendorId = 0;
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
        $this->eurId      = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'EUR' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $stmt = $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, ?, ?, ?)'
        );
        $stmt->execute(['DČR accrual test s.r.o.', $this->czId, 'dcr-accrual@example.com', $this->currencyId, $this->vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::STARTS_ON, self::ENDS_ON);
        $this->vendorId = $this->vendor('Allianz pojišťovna');
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

    // ── náhled — pro-rata, EUR, filtr ──────────────────────────────────────────

    public function testPreviewProRataAcrossYearEnd(): void
    {
        // Pojistné 36 600 Kč, krytí 1. 7. 2091 – 30. 6. 2092 (366 dnů). Přesah do 2092 =
        // 1. 1. – 30. 6. 2092 = 182 dnů → 36 600 × 182/366 = 18 200 Kč na 381.
        $pi = $this->purchase('PF-2091-ALLIANZ', 36600.00, self::YEAR . '-11-15');
        $this->item($pi, 36600.00, '2091-07-01', '2092-06-30', '548');

        $preview = $this->closing->prepaidExpenseAccrualPreview($this->supplierId, $this->periodId);
        self::assertCount(1, $preview['items']);
        $it = $preview['items'][0];
        self::assertSame(366, $it['total_days']);
        self::assertSame(182, $it['deferred_days']);
        self::assertSame('548', $it['credit_account']);
        self::assertEqualsWithDelta(18200.00, (float) $it['deferred_amount'], 0.001);
        self::assertEqualsWithDelta(18200.00, (float) $preview['total'], 0.001);
        self::assertEqualsWithDelta(18200.00, (float) $preview['by_account']['548'], 0.001);
        self::assertCount(1, $preview['documents']);
        self::assertEqualsWithDelta(18200.00, (float) $preview['documents'][0]['deferred_amount'], 0.001);
    }

    public function testPreviewWholeCostInNextPeriod(): void
    {
        // Předplatné celé v 2092 → odloží se 100 % (accrual_from i _to za koncem období).
        $pi = $this->purchase('PF-2091-SUBS', 12000.00, self::YEAR . '-12-20');
        $this->item($pi, 12000.00, '2092-01-01', '2092-12-31', '518');

        $preview = $this->closing->prepaidExpenseAccrualPreview($this->supplierId, $this->periodId);
        self::assertEqualsWithDelta(1.0, (float) $preview['items'][0]['fraction'], 0.0001);
        self::assertEqualsWithDelta(12000.00, (float) $preview['total'], 0.001);
    }

    public function testPreviewIgnoresUnmarkedAndNonOverlapping(): void
    {
        // Řádek bez rozlišení → ignorován. Řádek končící v období → ignorován (nepřesahuje).
        $pi = $this->purchase('PF-2091-MIX', 5000.00, self::YEAR . '-06-01');
        $this->item($pi, 3000.00, null, null, '518');                    // bez rozlišení
        $this->item($pi, 2000.00, '2091-06-01', '2091-12-31', '518');    // končí přesně v období

        $preview = $this->closing->prepaidExpenseAccrualPreview($this->supplierId, $this->periodId);
        self::assertSame([], $preview['items']);
        self::assertEqualsWithDelta(0.0, (float) $preview['total'], 0.001);
    }

    public function testPreviewEurConvertedByDocumentRate(): void
    {
        if ($this->eurId === 0) {
            self::markTestSkipped('Měna EUR není v číselníku.');
        }
        // 1 200 EUR × kurz 25 = 30 000 Kč; krytí 1. 10. 2091 – 30. 9. 2092 (366 dnů),
        // přesah 1. 1. – 30. 9. 2092 = 274 dnů → 30 000 × 274/366 = 22 459,02 Kč.
        $pi = $this->purchase('PF-2091-EUR', 1200.00, self::YEAR . '-09-15', $this->eurId, 25.0);
        $this->item($pi, 1200.00, '2091-10-01', '2092-09-30', '518');

        $preview = $this->closing->prepaidExpenseAccrualPreview($this->supplierId, $this->periodId);
        self::assertEqualsWithDelta(30000.00, (float) $preview['items'][0]['total_czk'], 0.001);
        self::assertEqualsWithDelta(22459.02, (float) $preview['total'], 0.001);
    }

    public function testPreviewDefersInvoicePostedToExpenseOnce(): void
    {
        $pi = $this->purchase('PF-2091-ALLIANZ', 36600.00, self::YEAR . '-11-15');
        $this->item($pi, 36600.00, '2091-07-01', '2092-06-30', '548');
        $this->postPurchaseExpense($pi, '548', 36600.00, self::YEAR . '-11-15');

        $preview = $this->closing->prepaidExpenseAccrualPreview($this->supplierId, $this->periodId);
        self::assertCount(1, $preview['items']);
        self::assertEqualsWithDelta(18200.00, (float) $preview['total'], 0.001);
    }

    /** Faktura rozúčtovaná rovnou na 381 nese odklad sama, uzávěrka ji neodkládá podruhé. */
    public function testPreviewSkipsInvoiceAlreadyPostedTo381(): void
    {
        $pi = $this->purchase('PF-2091-SPLIT', 36600.00, self::YEAR . '-11-15');
        $this->item($pi, 36600.00, '2091-07-01', '2092-06-30', '548');
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $pi, [
            ['account_code' => '548', 'side' => 'debit', 'amount' => 18400.00],
            ['account_code' => '381', 'side' => 'debit', 'amount' => 18200.00],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 36600.00],
        ], ['entry_date' => self::YEAR . '-11-15', 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        $preview = $this->closing->prepaidExpenseAccrualPreview($this->supplierId, $this->periodId);
        self::assertSame([], $preview['items']);
        self::assertEqualsWithDelta(0.0, (float) $preview['total'], 0.001);
    }

    /** Odklad ručním interním dokladem připojeným k faktuře se neodkládá podruhé. */
    public function testPreviewSkipsInvoiceWithLinkedManualDeferral(): void
    {
        $pi = $this->purchase('PF-2091-MANUAL', 36600.00, self::YEAR . '-11-15');
        $this->item($pi, 36600.00, '2091-07-01', '2092-06-30', '548');
        $this->postPurchaseExpense($pi, '548', 36600.00, self::YEAR . '-11-15');
        $manualId = $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '381', 'side' => 'debit', 'amount' => 18200.00],
            ['account_code' => '548', 'side' => 'credit', 'amount' => 18200.00],
        ], ['entry_date' => self::ENDS_ON, 'description' => 'Náklady příštích období', 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        $this->db->pdo()->prepare(
            'INSERT INTO journal_entry_document_links (supplier_id, entry_id, doc_type, doc_id) VALUES (?, ?, "purchase_invoice", ?)'
        )->execute([$this->supplierId, $manualId, $pi]);

        $preview = $this->closing->prepaidExpenseAccrualPreview($this->supplierId, $this->periodId);
        self::assertSame([], $preview['items']);
    }

    // ── zaúčtování — podvojnost, idempotence, mazání ───────────────────────────

    public function testRunPostsDoubleEntry381Over5xxAndIsIdempotent(): void
    {
        $pi = $this->purchase('PF-2091-ALLIANZ', 36600.00, self::YEAR . '-11-15');
        $itemId = $this->item($pi, 36600.00, '2091-07-01', '2092-06-30', '548');
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        $r1 = $this->closing->runPrepaidExpenseAccrual($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        self::assertEqualsWithDelta(18200.00, (float) $r1['total'], 0.001);

        $entry = $this->journal->findBySource($this->supplierId, 'prepaid_expense_accrual', ClosingSourceId::prepaidExpenseAccrual($this->periodId));
        self::assertNotNull($entry, 'Rozlišení musí být zaúčtované se source prepaid_expense_accrual/period_id.');
        $entryId = (int) $entry['id'];
        $lines = $this->entryLines($entryId);
        self::assertEqualsWithDelta(18200.00, $this->sideAmount($lines, '381', 'debit'), 0.001);
        self::assertEqualsWithDelta(18200.00, $this->sideAmount($lines, '548', 'credit'), 0.001);
        self::assertEqualsWithDelta(0.0, $this->balance($lines), 0.001, 'Podvojnost: Σ MD = Σ D.');

        // Re-run beze změny → tentýž zápis (in-place rewrite, žádný duplikát).
        $this->closing->runPrepaidExpenseAccrual($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $entry2 = $this->journal->findBySource($this->supplierId, 'prepaid_expense_accrual', ClosingSourceId::prepaidExpenseAccrual($this->periodId));
        self::assertSame($entryId, (int) $entry2['id'], 'Re-run nesmí vytvořit duplicitní zápis.');

        // Zrušení rozlišení na řádku → nulový návrh maže zápis.
        $this->db->pdo()->prepare('UPDATE purchase_invoice_items SET accrual_from = NULL, accrual_to = NULL WHERE id = ?')->execute([$itemId]);
        $this->closing->runPrepaidExpenseAccrual($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        self::assertNull(
            $this->journal->findBySource($this->supplierId, 'prepaid_expense_accrual', ClosingSourceId::prepaidExpenseAccrual($this->periodId)),
            'Nulový návrh musí odložený zápis smazat.',
        );
    }

    // ── rozpuštění v N+1 (open_next) ───────────────────────────────────────────

    public function testAccrualIsReleasedInNextPeriodOnOpenNext(): void
    {
        $pi = $this->purchase('PF-2091-ALLIANZ', 36600.00, self::YEAR . '-11-15');
        $this->item($pi, 36600.00, '2091-07-01', '2092-06-30', '548');
        // EP-10b: doklad musí být zaúčtovaný, jinak uzavření knih blokuje nezaúčtovaný doklad.
        $this->postPurchaseExpense($pi, '548', 36600.00, self::YEAR . '-11-15');
        $this->driveCloseWithAccrual();

        $defer = $this->journal->findBySource($this->supplierId, 'prepaid_expense_accrual', ClosingSourceId::prepaidExpenseAccrual($this->periodId));
        self::assertNotNull($defer);

        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $open = $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        self::assertNotNull($open['prepaid_expense_release_entry_id'], 'Open_next musí rozpustit odklad v N+1.');
        $release = $this->journal->findBySource($this->supplierId, 'prepaid_expense_accrual', ClosingSourceId::prepaidExpenseAccrualRelease($this->periodId));
        self::assertNotNull($release);
        $lines = $this->entryLines((int) $release['id']);
        // Zrcadlo defer zápisu: MD 548 / D 381 = náklad se objeví v N+1.
        self::assertEqualsWithDelta(18200.00, $this->sideAmount($lines, '548', 'debit'), 0.001);
        self::assertEqualsWithDelta(18200.00, $this->sideAmount($lines, '381', 'credit'), 0.001);
        self::assertSame((self::YEAR + 1) . '-01-01', (string) $release['entry_date']);
        self::assertEqualsWithDelta(0.0, $this->balance381AsOf('2092-12-31'), 0.005, 'Jednoleté rozlišení se rozpustí jednou a celé.');
    }

    /**
     * Odklad zaúčtovaný mimo uzávěrku (faktura rozúčtovaná rovnou na 381, rozpuštění ručním
     * interním dokladem v N+1, typicky převzatá data) openNext nerozpouští podruhé.
     */
    public function testDeferralPostedOutsideClosingIsNotReleasedAgainOnOpenNext(): void
    {
        $this->periods->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
        $pi = $this->purchase('PF-2091-SPLIT', 36600.00, self::YEAR . '-11-15');
        $this->item($pi, 36600.00, '2091-07-01', '2092-06-30', '548');
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $pi, [
            ['account_code' => '548', 'side' => 'debit', 'amount' => 18400.00],
            ['account_code' => '381', 'side' => 'debit', 'amount' => 18200.00],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 36600.00],
        ], ['entry_date' => self::YEAR . '-11-15', 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '548', 'side' => 'debit', 'amount' => 18200.00],
            ['account_code' => '381', 'side' => 'credit', 'amount' => 18200.00],
        ], ['entry_date' => '2092-06-30', 'description' => 'Rozpuštění NPO', 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        $this->driveCloseWithAccrual(false);
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $open = $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        self::assertNull($open['prepaid_expense_release_entry_id'], 'Odklad mimo uzávěrku se rozpouští mimo openNext.');
        self::assertEqualsWithDelta(0.0, $this->balance381AsOf('2092-12-31'), 0.005, '381 se nesmí rozpustit dvakrát.');
    }

    /**
     * Faktura z cílového období N+1 nebyla v N odložena, její náklad je v N+1 celý.
     * openNext rozpustí jen odklad z N.
     */
    public function testInvoiceFromTargetPeriodIsNotReleasedOnOpenNext(): void
    {
        $this->periods->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
        $pi = $this->purchase('PF-2091-ALLIANZ', 36600.00, self::YEAR . '-11-15');
        $this->item($pi, 36600.00, '2091-07-01', '2092-06-30', '548');
        $this->postPurchaseExpense($pi, '548', 36600.00, self::YEAR . '-11-15');
        $later = $this->purchase('PF-2092-PARK', 36500.00, '2092-03-01');
        $this->item($later, 36500.00, '2092-03-01', '2093-02-28', '518');
        $this->postPurchaseExpense($later, '518', 36500.00, '2092-03-01');

        $this->driveCloseWithAccrual();
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $open = $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        $lines = $this->entryLines((int) $open['prepaid_expense_release_entry_id']);
        self::assertEqualsWithDelta(18200.00, $this->sideAmount($lines, '381', 'credit'), 0.001);
        self::assertEqualsWithDelta(0.0, $this->sideAmount($lines, '518', 'debit'), 0.001, 'Náklad faktury z N+1 už v N+1 je.');
    }

    /**
     * EP-15: víceletý odklad (2091-07-01 .. 2093-06-30) se NEROZPUSTÍ celý první den N+1.
     * Náhled nese harmonogram rozpouštění po obdobích (2092, 2093), jehož Σ = odložená
     * částka (telescopuje na haléř). Reálné open_next 2091 rozpustí PŘESNĚ tranši 2092;
     * na 381 zůstane přesně tranše 2093 (rozpustí ji open_next 2092).
     */
    public function testMultiYearAccrualReleasesTranchePerPeriodAndDrains381(): void
    {
        $pi = $this->purchase('PF-2091-3Y', 36600.00, self::YEAR . '-11-15');
        $this->item($pi, 36600.00, '2091-07-01', '2093-06-30', '548');
        $this->postPurchaseExpense($pi, '548', 36600.00, self::YEAR . '-11-15');

        // Náhled: harmonogram rozpouštění 2092 + 2093, Σ = odložená částka.
        $preview = $this->closing->prepaidExpenseAccrualPreview($this->supplierId, $this->periodId);
        $item = $preview['items'][0];
        $deferAmt = round((float) $item['deferred_amount'], 2);
        $schedule = $item['release_schedule'];
        self::assertCount(2, $schedule, 'Víceletý odklad má tranše pro 2092 i 2093.');
        self::assertSame(2092, $schedule[0]['fiscal_year']);
        self::assertSame(2093, $schedule[1]['fiscal_year']);
        self::assertLessThan($deferAmt - 1.0, round((float) $schedule[0]['amount'], 2), 'Tranše 2092 < celý odklad.');
        self::assertEqualsWithDelta(
            $deferAmt,
            round((float) $schedule[0]['amount'] + (float) $schedule[1]['amount'], 2),
            0.005,
            'Σ tranší harmonogramu = odložená částka (telescopuje).',
        );

        // Reálné uzavření + otevření 2092: rozpustí se PŘESNĚ tranše 2092.
        $this->driveCloseWithAccrual();
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        $rel2092Entry = $this->journal->findBySource($this->supplierId, 'prepaid_expense_accrual', ClosingSourceId::prepaidExpenseAccrualRelease($this->periodId));
        self::assertNotNull($rel2092Entry, 'Open_next 2091 rozpustí tranši 2092.');
        $rel2092 = $this->sideAmount($this->entryLines((int) $rel2092Entry['id']), '381', 'credit');
        self::assertEqualsWithDelta((float) $schedule[0]['amount'], $rel2092, 0.005, 'Reálná tranše 2092 = harmonogram.');
        self::assertEqualsWithDelta($rel2092, $this->sideAmount($this->entryLines((int) $rel2092Entry['id']), '548', 'debit'), 0.001);

        // Na 381 zůstává přesně tranše 2093 (rozpustí ji open_next 2092).
        self::assertEqualsWithDelta((float) $schedule[1]['amount'], $this->balance381AsOf('2092-12-31'), 0.005, '381 nese zbytek pro 2093.');
    }

    // ── neodpočitatelná DPH je součástí odkládaného nákladu (issue #276) ────────

    /**
     * Neplátce: 1 000 + 210 DPH, 1. 7. 2091 – 30. 6. 2092 (366 dnů), přesah 182 dnů.
     * Odkládá se 1 210 × 182/366 = 601,69 Kč, ne 497,27 Kč ze samotného základu.
     * Náhled, zápis na 381, rozpuštění i zůstatek musí vyjít ze stejné částky.
     */
    public function testNonPayerDefersCostIncludingVatFromPreviewToRelease(): void
    {
        $this->setNonPayer();
        $pi = $this->purchaseWithVat('PF-2091-NEPLATCE', 1000.00, 210.00, self::YEAR . '-07-01', 'full');
        $this->itemWithVat($pi, 1000.00, 210.00, '2091-07-01', '2092-06-30', '518');
        $this->postDocument($pi);
        self::assertEqualsWithDelta(1210.00, $this->documentExpense($pi, '518'), 0.001, 'Doklad účtuje náklad včetně daně.');

        $preview = $this->closing->prepaidExpenseAccrualPreview($this->supplierId, $this->periodId);
        self::assertEqualsWithDelta(1210.00, (float) $preview['items'][0]['total_czk'], 0.001);
        self::assertEqualsWithDelta(1000.00, (float) $preview['items'][0]['total_without_vat'], 0.001);
        self::assertEqualsWithDelta(601.69, (float) $preview['total'], 0.001);

        $this->driveCloseWithAccrual();
        $defer = $this->journal->findBySource($this->supplierId, 'prepaid_expense_accrual', ClosingSourceId::prepaidExpenseAccrual($this->periodId));
        $deferLines = $this->entryLines((int) $defer['id']);
        self::assertEqualsWithDelta(601.69, $this->sideAmount($deferLines, '381', 'debit'), 0.001);
        self::assertEqualsWithDelta(601.69, $this->sideAmount($deferLines, '518', 'credit'), 0.001);

        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $open = $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $releaseLines = $this->entryLines((int) $open['prepaid_expense_release_entry_id']);
        self::assertEqualsWithDelta(601.69, $this->sideAmount($releaseLines, '381', 'credit'), 0.001);
        self::assertEqualsWithDelta(601.69, $this->sideAmount($releaseLines, '518', 'debit'), 0.001);
        self::assertEqualsWithDelta(0.0, $this->balance381AsOf('2092-12-31'), 0.005);
    }

    /** Příklad z issue: 1. 7. 2026 – 30. 6. 2027 (365 dnů), přesah 181 dnů, odklad 600,03 Kč. */
    public function testNonPayerIssueExampleDefersSixHundredCrowns(): void
    {
        $periodId = $this->periods->create($this->supplierId, 2026, '2026-01-01', '2026-12-31');
        $this->setNonPayer();
        $pi = $this->purchaseWithVat('PF-2026-NEPLATCE', 1000.00, 210.00, '2026-07-01', 'none');
        $this->itemWithVat($pi, 1000.00, 210.00, '2026-07-01', '2027-06-30', '518');

        $preview = $this->closing->prepaidExpenseAccrualPreview($this->supplierId, $periodId);
        self::assertEqualsWithDelta(1210.00, (float) $preview['items'][0]['total_czk'], 0.001);
        self::assertEqualsWithDelta(600.03, (float) $preview['total'], 0.001);
    }

    /** Plátce s plným nárokem: daň je na 343, odkládá se jen základ jako dosud. */
    public function testPayerWithFullDeductionDefersBaseOnly(): void
    {
        $this->setPayerSince(self::STARTS_ON);
        $pi = $this->purchaseWithVat('PF-2091-PLNY', 1000.00, 210.00, self::YEAR . '-07-01', 'full');
        $this->itemWithVat($pi, 1000.00, 210.00, '2091-07-01', '2092-06-30', '518');

        $preview = $this->closing->prepaidExpenseAccrualPreview($this->supplierId, $this->periodId);
        self::assertEqualsWithDelta(1000.00, (float) $preview['items'][0]['total_czk'], 0.001);
        self::assertEqualsWithDelta(497.27, (float) $preview['total'], 0.001);
    }

    /** Plátce bez nároku na konkrétní doklad: daň je v nákladu i přes aktivní registraci. */
    public function testPayerWithoutDeductionDefersCostIncludingVat(): void
    {
        $this->setPayerSince(self::STARTS_ON);
        $pi = $this->purchaseWithVat('PF-2091-BEZNAROKU', 1000.00, 210.00, self::YEAR . '-07-01', 'none');
        $this->itemWithVat($pi, 1000.00, 210.00, '2091-07-01', '2092-06-30', '518');

        $preview = $this->closing->prepaidExpenseAccrualPreview($this->supplierId, $this->periodId);
        self::assertEqualsWithDelta(1210.00, (float) $preview['items'][0]['total_czk'], 0.001);
        self::assertEqualsWithDelta(601.69, (float) $preview['total'], 0.001);
    }

    /** Poměrný odpočet 60 %: do nákladu patří 40 % daně, tedy 1 000 + 84 Kč. */
    public function testProportionalDeductionDefersOnlyNonDeductibleVat(): void
    {
        $this->setPayerSince(self::STARTS_ON);
        $pi = $this->purchaseWithVat('PF-2091-POMER', 1000.00, 210.00, self::YEAR . '-07-01', 'proportional', 60.0);
        $this->itemWithVat($pi, 1000.00, 210.00, '2091-07-01', '2092-06-30', '518');
        $this->postDocument($pi);
        self::assertEqualsWithDelta(1084.00, $this->documentExpense($pi, '518'), 0.001);

        $preview = $this->closing->prepaidExpenseAccrualPreview($this->supplierId, $this->periodId);
        self::assertEqualsWithDelta(1084.00, (float) $preview['items'][0]['total_czk'], 0.001);
        self::assertEqualsWithDelta(539.04, (float) $preview['total'], 0.001);
    }

    /** Status plátce se čte k datu plnění: faktura z doby před registrací má daň v nákladu. */
    public function testVatStatusIsReadAtDocumentDate(): void
    {
        $this->setPayerSince('2091-10-01');
        $before = $this->purchaseWithVat('PF-2091-PRED', 1000.00, 210.00, '2091-09-01', 'full');
        $this->itemWithVat($before, 1000.00, 210.00, '2091-09-01', '2092-08-31', '518');
        $after = $this->purchaseWithVat('PF-2091-PO', 1000.00, 210.00, '2091-11-01', 'full');
        $this->itemWithVat($after, 1000.00, 210.00, '2091-11-01', '2092-10-31', '518');

        $byDocument = [];
        foreach ($this->closing->prepaidExpenseAccrualPreview($this->supplierId, $this->periodId)['items'] as $item) {
            $byDocument[$item['vendor_invoice_number']] = (float) $item['total_czk'];
        }
        self::assertEqualsWithDelta(1210.00, $byDocument['PF-2091-PRED'], 0.001);
        self::assertEqualsWithDelta(1000.00, $byDocument['PF-2091-PO'], 0.001);
    }

    /** Cizí měna: 100 EUR + 21 EUR DPH v kurzu 25 = náklad 3 025 Kč, přesah 182 z 366 dnů. */
    public function testForeignCurrencyCostUsesDocumentRate(): void
    {
        if ($this->eurId === 0) {
            self::markTestSkipped('Měna EUR není v číselníku.');
        }
        $this->setNonPayer();
        $pi = $this->purchaseWithVat('PF-2091-EUR-DPH', 100.00, 21.00, self::YEAR . '-07-01', 'none', 100.0, 'invoice', $this->eurId, 25.0);
        $this->itemWithVat($pi, 100.00, 21.00, '2091-07-01', '2092-06-30', '518');

        $preview = $this->closing->prepaidExpenseAccrualPreview($this->supplierId, $this->periodId);
        self::assertEqualsWithDelta(3025.00, (float) $preview['items'][0]['total_czk'], 0.001);
        self::assertEqualsWithDelta(1504.23, (float) $preview['total'], 0.001);
    }

    /** Dobropis s obdobím odkládá zápornou částku včetně daně, odklad faktury tak vynuluje. */
    public function testCreditNoteReducesDeferralIncludingVat(): void
    {
        $this->setNonPayer();
        $invoice = $this->purchaseWithVat('PF-2091-FA', 1000.00, 210.00, self::YEAR . '-07-01', 'none');
        $this->itemWithVat($invoice, 1000.00, 210.00, '2091-07-01', '2092-06-30', '518');
        $credit = $this->purchaseWithVat('PF-2091-DOB', -1000.00, -210.00, self::YEAR . '-08-01', 'none', 100.0, 'credit_note');
        $this->itemWithVat($credit, -1000.00, -210.00, '2091-07-01', '2092-06-30', '518');

        $preview = $this->closing->prepaidExpenseAccrualPreview($this->supplierId, $this->periodId);
        $byDocument = [];
        foreach ($preview['items'] as $item) {
            $byDocument[$item['vendor_invoice_number']] = (float) $item['deferred_amount'];
        }
        self::assertEqualsWithDelta(601.69, $byDocument['PF-2091-FA'], 0.001);
        self::assertEqualsWithDelta(-601.69, $byDocument['PF-2091-DOB'], 0.001);
        self::assertEqualsWithDelta(0.0, (float) $preview['total'], 0.001);
    }

    /**
     * Uzávěrka zaúčtovaná před opravou odložila jen základ a neuložila ocenění položek.
     * Rozpuštění musí vyjít z téhož základu, jinak by 381 přečerpalo o daň.
     */
    public function testReleaseOfDeferralClosedWithoutCostSnapshotStaysOnBase(): void
    {
        $this->setNonPayer();
        $pi = $this->purchaseWithVat('PF-2091-STARY', 1000.00, 210.00, self::YEAR . '-07-01', 'none');
        $this->itemWithVat($pi, 1000.00, 210.00, '2091-07-01', '2092-06-30', '518');
        $this->postDocument($pi);
        $this->driveCloseWithAccrual();
        $this->db->pdo()->prepare(
            "UPDATE accounting_closing_steps
                SET payload = JSON_REMOVE(payload, '$.prepaid_expense_accrual.item_costs')
              WHERE supplier_id = ? AND period_id = ? AND step_key = 'deferrals'"
        )->execute([$this->supplierId, $this->periodId]);

        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $open = $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        $releaseLines = $this->entryLines((int) $open['prepaid_expense_release_entry_id']);
        self::assertEqualsWithDelta(497.27, $this->sideAmount($releaseLines, '381', 'credit'), 0.001);
    }

    /** Víceletý odklad: tranše se počítají z nákladu včetně daně a 381 po poslední dojde na nulu. */
    public function testMultiYearReleaseOfNonDeductibleVatTelescopesToDeferredAmount(): void
    {
        $this->setNonPayer();
        $pi = $this->purchaseWithVat('PF-2091-2R', 1000.00, 210.00, self::YEAR . '-07-01', 'none');
        $this->itemWithVat($pi, 1000.00, 210.00, '2091-07-01', '2093-06-30', '518');
        $this->postDocument($pi);

        $item = $this->closing->prepaidExpenseAccrualPreview($this->supplierId, $this->periodId)['items'][0];
        $schedule = $item['release_schedule'];
        self::assertEqualsWithDelta(
            (float) $item['deferred_amount'],
            round((float) $schedule[0]['amount'] + (float) $schedule[1]['amount'], 2),
            0.005,
        );

        $this->driveCloseWithAccrual();
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $open = $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $releaseLines = $this->entryLines((int) $open['prepaid_expense_release_entry_id']);
        self::assertEqualsWithDelta((float) $schedule[0]['amount'], $this->sideAmount($releaseLines, '381', 'credit'), 0.005);
        self::assertEqualsWithDelta((float) $schedule[1]['amount'], $this->balance381AsOf('2092-12-31'), 0.005);
    }

    // ── Task 10 předpoklad: accrual vstupuje do VH před zdaněním ────────────────

    public function testAccrualIncreasesProfitBeforeTax(): void
    {
        $pi = $this->purchase('PF-2091-ALLIANZ', 36600.00, self::YEAR . '-11-15');
        $this->item($pi, 36600.00, '2091-07-01', '2092-06-30', '548');
        $vhBefore = $this->profitBeforeTax();
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->runPrepaidExpenseAccrual($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $vhAfter = $this->profitBeforeTax();

        // D 548 credit o 18 200 odloží náklad → zvýší VH (náhled ř.10) přesně o odloženou částku.
        // Kdyby byl accrual zamětený jako 'closing', profitBeforeTax by ho vyloučil a předpoklad selhal.
        self::assertEqualsWithDelta(18200.00, $vhAfter - $vhBefore, 0.001);
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function vendor(string $name): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "CZ12345678", "vendor@example.com", "cs", ?, 0, 1)'
        );
        $stmt->execute([$this->supplierId, $name, $this->czId, $this->currencyId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function purchase(string $number, float $base, string $issue, ?int $currencyId = null, ?float $exchangeRate = null): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, exchange_rate, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                 vat_deduction, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, ?, 0, "{}", ?, 0, ?, "received", "40", "full", ?)'
        );
        $stmt->execute([
            $this->supplierId, $this->vendorId, $number, $issue, $issue, $issue, $issue,
            $currencyId ?? $this->currencyId, $exchangeRate, $base, $base, $this->userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function item(int $purchaseId, float $base, ?string $from, ?string $to, ?string $account): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index,
                 expense_account_code, accrual_from, accrual_to)
             VALUES (?, "Pojistné", 1, "ks", ?, ?, 21.00, ?, 0, ?, 0, ?, ?, ?)'
        );
        $stmt->execute([$purchaseId, $base, $this->vatRateId, $base, $base, $account, $from, $to]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function purchaseWithVat(
        string $number,
        float $base,
        float $vat,
        string $date,
        string $deduction,
        float $deductionPercent = 100.0,
        string $kind = 'invoice',
        ?int $currencyId = null,
        ?float $exchangeRate = null,
    ): int {
        $total = round($base + $vat, 2);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, exchange_rate, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                 vat_deduction, vat_deduction_percent, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, "{}", ?, ?, ?, "received", "40", ?, ?, ?)'
        );
        $stmt->execute([
            $this->supplierId, $this->vendorId, $number, $kind, $date, $date, $date, $date,
            $currencyId ?? $this->currencyId, $exchangeRate, $base, $vat, $total,
            $deduction, $deductionPercent, $this->userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function itemWithVat(int $purchaseId, float $base, float $vat, string $from, string $to, string $account): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index,
                 expense_account_code, accrual_from, accrual_to)
             VALUES (?, "Služba", 1, "ks", ?, ?, 21.00, ?, ?, ?, 0, ?, ?, ?)'
        );
        $stmt->execute([$purchaseId, $base, $this->vatRateId, $base, $vat, round($base + $vat, 2), $account, $from, $to]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** Zaúčtuje doklad skutečnou účtovací cestou, aby test porovnával odklad se zápisem dokladu. */
    private function postDocument(int $purchaseId): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT issue_date FROM purchase_invoices WHERE id = ?');
        $stmt->execute([$purchaseId]);
        $date = (string) $stmt->fetchColumn();
        $lines = $this->posting->buildFromPurchaseInvoice($this->supplierId, $purchaseId);
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $purchaseId, $lines, [
            'entry_date' => $date,
            'posted' => true,
            'posted_by' => $this->userId,
            'user_id' => $this->userId,
        ]);
    }

    /** Částka na nákladovém účtu ze zápisu dokladu. */
    private function documentExpense(int $purchaseId, string $account): float
    {
        $entry = $this->journal->findBySource($this->supplierId, 'purchase_invoice', $purchaseId);
        self::assertNotNull($entry);
        return $this->sideAmount($this->entryLines((int) $entry['id']), $account, 'debit');
    }

    private function setNonPayer(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE supplier SET is_vat_payer = 0, is_identified = 0 WHERE id = ?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM supplier_vat_status_history WHERE supplier_id = ?')->execute([$this->supplierId]);
    }

    private function setPayerSince(string $date): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE supplier SET is_vat_payer = 1, is_identified = 0 WHERE id = ?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM supplier_vat_status_history WHERE supplier_id = ?')->execute([$this->supplierId]);
        $history = $pdo->prepare(
            'INSERT INTO supplier_vat_status_history (supplier_id, effective_from, is_vat_payer, is_identified) VALUES (?, ?, ?, 0)'
        );
        $history->execute([$this->supplierId, '1900-01-01', 0]);
        $history->execute([$this->supplierId, $date, 1]);
    }

    /** Kumulativní zůstatek 381 (MD − D) ke dni včetně. */
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

    /** EP-10b: zaúčtuje přijatý doklad (MD náklad / D 321), aby nebyl „nezaúčtovaný". */
    private function postPurchaseExpense(int $purchaseId, string $account, float $base, string $date): void
    {
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $purchaseId, [
            ['account_code' => $account, 'side' => 'debit', 'amount' => $base],
            ['account_code' => '321', 'side' => 'credit', 'amount' => $base],
        ], ['entry_date' => $date, 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
    }

    /** start → precheck → povinné kroky, se zaúčtovaným rozlišením v kroku deferrals. */
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
            $this->closing->runPrepaidExpenseAccrual($sid, $pid, $this->rv(), $this->meta());
        }
        $this->closing->confirmStep($sid, $pid, 'deferrals', 'done', null, $this->rv(), $this->meta());
        $this->closing->confirmStep($sid, $pid, 'provisions', 'skipped', null, $this->rv(), $this->meta());
        $this->closing->confirmStep($sid, $pid, 'income_tax', 'skipped', null, $this->rv(), $this->meta());
        $this->completeInventory($sid, $pid, $this->userId);
    }

    /** EP-6: dokončí inventarizaci rozvahových účtů (skutečný = účetní → resolved), aby closeBooks neblokoval. */
    private function completeInventory(int $sid, int $pid, ?int $uid): void
    {
        $rv = (int) $this->periods->findById($sid, $pid)['row_version'];
        $items = [];
        foreach ($this->closing->inventoryPreview($sid, $pid)['rows'] as $r) {
            $items[(int) $r['account_id']] = ['counted_balance' => (float) $r['book_balance'], 'resolution' => 'resolved', 'note' => null];
        }
        $this->closing->saveInventory($sid, $pid, $rv, ['complete' => true], $items, ['user_id' => $uid]);
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
