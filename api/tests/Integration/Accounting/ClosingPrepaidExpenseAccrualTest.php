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

    /**
     * Náklad a sleva se stejným obdobím a částkou, ale různým střediskem dají celkem nulu:
     * odklad i rozpuštění přes openNext přesto vzniknou po střediscích, bez řádku 381.
     */
    public function testZeroTotalStillDefersAndReleasesPerDimensionOnOpenNext(): void
    {
        $dimensions = Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Dimension\DimensionService::class);
        $dimensions->setEnabled($this->supplierId, true);
        $center = (int) $dimensions->ensureDefaultTypes($this->supplierId, ['stredisko'])['cost_center'];
        $a = (int) $dimensions->createValue($this->supplierId, $center, ['code' => 'S-A', 'name' => 'Středisko A'])['id'];
        $b = (int) $dimensions->createValue($this->supplierId, $center, ['code' => 'S-B', 'name' => 'Středisko B'])['id'];
        $pi = $this->purchase('PF-2091-ZERO', 0.00, self::YEAR . '-11-15');
        $this->item($pi, 36600.00, '2091-07-01', '2092-06-30', '548');
        $this->item($pi, -36600.00, '2091-07-01', '2092-06-30', '548');
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $pi, [
            ['account_code' => '548', 'side' => 'debit', 'amount' => 36600.00, 'dimensions' => [$center => $a]],
            ['account_code' => '548', 'side' => 'credit', 'amount' => 36600.00, 'dimensions' => [$center => $b]],
        ], ['entry_date' => self::YEAR . '-11-15', 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        (new \MyInvoice\Repository\DimensionAssignmentRepository($this->db))->replaceDocumentDimensions($this->supplierId, 'purchase_invoice', $pi, [], [
            1 => [$center => $a],
            2 => [$center => $b],
        ]);

        $this->driveCloseWithAccrual();
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        $release = $this->journal->findBySource($this->supplierId, 'prepaid_expense_accrual', ClosingSourceId::prepaidExpenseAccrualRelease($this->periodId));
        self::assertNotNull($release, 'Rozpuštění vznikne i při nulovém součtu.');
        $lines = $this->entryLines((int) $release['id']);
        self::assertEqualsWithDelta(18200.00, $this->sideAmount($lines, '548', 'debit'), 0.001);
        self::assertEqualsWithDelta(18200.00, $this->sideAmount($lines, '548', 'credit'), 0.001);
        self::assertEqualsWithDelta(0.0, $this->sideAmount($lines, '381', 'debit') + $this->sideAmount($lines, '381', 'credit'), 0.001);
    }

    /**
     * Jednoduchá forma zápisu: faktura na středisku A a dobropis na středisku B dají v odkladu
     * řádek 548 na obou stranách. Odklad i rozpuštění v openNext se musí rozložit na dvojice
     * a zaúčtovat, jinak uzávěrka ani otevření dalšího roku neprojdou.
     */
    public function testSingleEntryFormDefersAndReleasesCreditNoteOnOtherCostCenter(): void
    {
        $this->useSingleEntryForm();
        [$center, $a, $b] = $this->costCenters();
        $this->postedCost('PF-2091-SF1', 36600.00, '548', $center, $a);
        $this->postedCost('PF-2091-SF2', -12200.00, '548', $center, $b);

        $this->driveCloseWithAccrual();
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        // 36 600 × 182/366 = 18 200; −12 200 × 182/366 = −6 066,67.
        $defer = $this->journal->findBySource($this->supplierId, 'prepaid_expense_accrual', ClosingSourceId::prepaidExpenseAccrual($this->periodId));
        $release = $this->journal->findBySource($this->supplierId, 'prepaid_expense_accrual', ClosingSourceId::prepaidExpenseAccrualRelease($this->periodId));
        self::assertNotNull($defer);
        self::assertNotNull($release);
        self::assertSame([['548', 'debit', 6066.67, $b], ['548', 'credit', 18200.0, $a]], $this->expenseByCenter((int) $defer['id'], $center));
        self::assertSame([['548', 'credit', 6066.67, $b], ['548', 'debit', 18200.0, $a]], $this->expenseByCenter((int) $release['id'], $center));
        $lines = $this->entryLines((int) $defer['id']);
        self::assertEqualsWithDelta(12133.33, $this->sideAmount($lines, '381', 'debit') - $this->sideAmount($lines, '381', 'credit'), 0.001);
        self::assertEqualsWithDelta(0.0, $this->balance381AsOf('2092-12-31'), 0.005);
        self::assertSame(['381|548|18200.00', '381|548|6066.67'], $this->pairsOf((int) $defer['id']));
        self::assertSame(['381|548|18200.00', '381|548|6066.67'], $this->pairsOf((int) $release['id']));
    }

    /**
     * Jednoduchá forma, nulový součet (faktura a dobropis stejné částky na různých
     * střediscích): každý řádek 548 má protějšek na 381, ne druhý řádek 548.
     */
    public function testSingleEntryFormPairsZeroTotalDeferralWith381(): void
    {
        $this->useSingleEntryForm();
        [$center, $a, $b] = $this->costCenters();
        $this->postedCost('PF-2091-SFZ1', 36600.00, '548', $center, $a);
        $this->postedCost('PF-2091-SFZ2', -36600.00, '548', $center, $b);

        $this->driveCloseWithAccrual();
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        $defer = $this->journal->findBySource($this->supplierId, 'prepaid_expense_accrual', ClosingSourceId::prepaidExpenseAccrual($this->periodId));
        $release = $this->journal->findBySource($this->supplierId, 'prepaid_expense_accrual', ClosingSourceId::prepaidExpenseAccrualRelease($this->periodId));
        self::assertNotNull($defer);
        self::assertNotNull($release);
        self::assertSame(['381|548|18200.00', '381|548|18200.00'], $this->pairsOf((int) $defer['id']));
        self::assertSame([['548', 'credit', 18200.0, $a], ['548', 'debit', 18200.0, $b]], $this->expenseByCenter((int) $defer['id'], $center));
        self::assertSame(['381|548|18200.00', '381|548|18200.00'], $this->pairsOf((int) $release['id']));
        self::assertSame([['548', 'debit', 18200.0, $a], ['548', 'credit', 18200.0, $b]], $this->expenseByCenter((int) $release['id'], $center));
    }

    /**
     * Složená forma: náklady na obou stranách nesou dál jeden řádek 381 (beze změny proti
     * dosavadnímu zápisu), dvojice vznikají jen v jednoduché formě.
     */
    public function testCompositeEntryFormKeepsOneDeferralLineWithCreditNote(): void
    {
        [$center, $a, $b] = $this->costCenters();
        $this->postedCost('PF-2091-CF1', 36600.00, '548', $center, $a);
        $this->postedCost('PF-2091-CF2', -12200.00, '548', $center, $b);
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")->execute([$this->periodId]);

        $run = $this->closing->runPrepaidExpenseAccrual($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        $lines = $this->entryLines((int) $run['entry_id']);
        self::assertCount(3, $lines);
        self::assertEqualsWithDelta(12133.33, $this->sideAmount($lines, '381', 'debit'), 0.001);
        self::assertEqualsWithDelta(0.0, $this->sideAmount($lines, '381', 'credit'), 0.001);
    }

    /**
     * Položky na syntetice 501, kterou zápis faktury přesměroval na její jedinou daňovou
     * analytiku (nedaňová 501.990 vedle ní se nepočítá). Dvě položky jedné faktury na S-A
     * a S-B s různým obdobím: odklad každé položky nese středisko své položky, ne řádek bez
     * dimenze. Rozpuštění zrcadlí odklad i poté, co syntetika dostala další analytiku.
     */
    public function testItemOnSyntheticRedirectedToSingleAnalyticKeepsItsCostCenter(): void
    {
        $pdo = $this->db->pdo();
        [$center, $a, $b] = $this->costCenters();
        $pdo->prepare("UPDATE chart_of_accounts SET is_active = 0 WHERE supplier_id = ? AND account_code = '501.900'")->execute([$this->supplierId]);
        $pdo->prepare("UPDATE posting_rules SET debit_account_code = '501' WHERE supplier_id = ? AND rule_key = 'invoice.material.received'")->execute([$this->supplierId]);
        $pi = $this->purchase('PF-2091-REDIR', 74200.00, self::YEAR . '-11-15');
        // S-A: celé období v 2092 (odloží se celá); S-B: 182 z 366 dnů v 2092.
        $first = $this->item($pi, 36600.00, '2092-01-01', '2092-12-31', null);
        $this->item($pi, 36600.00, '2091-07-01', '2092-06-30', null);
        $pdo->prepare("UPDATE purchase_invoice_items SET description = 'Syntetický materiál', expense_kind = 'material' WHERE purchase_invoice_id = ?")->execute([$pi]);
        self::assertSame('501', (string) $this->posting->purchaseItemExpenseAccounts($this->supplierId, $pi)[$first], 'Sanity: trasa vrací syntetiku z předkontace.');
        $entryId = $this->posting->postDocument($this->supplierId, 'purchase_invoice', $pi, [
            ['account_code' => '501', 'side' => 'debit', 'amount' => 36600.00, 'dimensions' => [$center => $a]],
            ['account_code' => '501', 'side' => 'debit', 'amount' => 36600.00, 'dimensions' => [$center => $b]],
            ['account_code' => '501.990', 'side' => 'debit', 'amount' => 1000.00, 'dimensions' => [$center => $a]],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 74200.00],
        ], ['entry_date' => self::YEAR . '-11-15', 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        self::assertEqualsWithDelta(73200.00, $this->sideAmount($this->entryLines($entryId), '501.100', 'debit'), 0.001, 'Sanity: zápis leží na jediné analytice.');
        (new \MyInvoice\Repository\DimensionAssignmentRepository($this->db))->replaceDocumentDimensions($this->supplierId, 'purchase_invoice', $pi, [], [
            1 => [$center => $a],
            2 => [$center => $b],
        ]);

        $this->driveCloseWithAccrual();
        $defer = $this->journal->findBySource($this->supplierId, 'prepaid_expense_accrual', ClosingSourceId::prepaidExpenseAccrual($this->periodId));
        self::assertNotNull($defer);
        self::assertSame([['501.100', 'credit', 18200.0, $b], ['501.100', 'credit', 36600.0, $a]], $this->expenseByCenter((int) $defer['id'], $center));

        // Po odkladu dostane syntetika další daňovou analytiku (osnova bez historie definic,
        // jako u běžné firmy): přesměrování zmizí, rozpuštění se ale řídí zápisem faktury.
        $pdo->prepare("UPDATE chart_of_accounts SET is_active = 1 WHERE supplier_id = ? AND account_code = '501.900'")->execute([$this->supplierId]);
        self::assertArrayNotHasKey('501', $this->posting->singleAnalyticRedirects($this->supplierId, self::YEAR . '-11-15'), 'Sanity: přesměrování zmizelo i k datu faktury.');
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        $release = $this->journal->findBySource($this->supplierId, 'prepaid_expense_accrual', ClosingSourceId::prepaidExpenseAccrualRelease($this->periodId));
        self::assertNotNull($release);
        self::assertSame([['501.100', 'debit', 18200.0, $b], ['501.100', 'debit', 36600.0, $a]], $this->expenseByCenter((int) $release['id'], $center));
    }

    /**
     * Syntetika má dvě daňové analytiky, zápis ji tedy nepřesměroval. Analytika jiné položky
     * (výslovný účet 501.900 se střediskem S-B) není přesměrování položky z trasy: odklad
     * položky na 501 si cizí analytiku ani její středisko nepřebírá.
     */
    public function testAnalyticOfOtherItemIsNotTakenOver(): void
    {
        $pdo = $this->db->pdo();
        [$center, $a, $b] = $this->costCenters();
        $pdo->prepare("UPDATE posting_rules SET debit_account_code = '501' WHERE supplier_id = ? AND rule_key = 'invoice.material.received'")->execute([$this->supplierId]);
        $pi = $this->purchase('PF-2091-FOREIGN', 73200.00, self::YEAR . '-11-15');
        $first = $this->item($pi, 36600.00, '2092-01-01', '2092-12-31', null);
        $this->item($pi, 36600.00, null, null, '501.900');
        $pdo->prepare("UPDATE purchase_invoice_items SET description = 'Syntetický materiál', expense_kind = 'material' WHERE purchase_invoice_id = ?")->execute([$pi]);
        self::assertSame('501', (string) $this->posting->purchaseItemExpenseAccounts($this->supplierId, $pi)[$first], 'Sanity: trasa vrací syntetiku z předkontace.');
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $pi, [
            ['account_code' => '548', 'side' => 'debit', 'amount' => 36600.00, 'dimensions' => [$center => $a]],
            ['account_code' => '501.900', 'side' => 'debit', 'amount' => 36600.00, 'dimensions' => [$center => $b]],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 73200.00],
        ], ['entry_date' => self::YEAR . '-11-15', 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        (new \MyInvoice\Repository\DimensionAssignmentRepository($this->db))->replaceDocumentDimensions($this->supplierId, 'purchase_invoice', $pi, [], [
            1 => [$center => $a],
            2 => [$center => $b],
        ]);

        $preview = $this->closing->prepaidExpenseAccrualPreview($this->supplierId, $this->periodId);

        self::assertSame(['501'], array_map('strval', array_keys($preview['by_account'])));
    }

    /**
     * Osnova syntetiku 501 přesměrovává na 501.100, na které ale leží jen jiná položka
     * (výslovný účet, středisko S-B). Položka z trasy zaúčtovaná ručně na 548 si analytiku
     * ani středisko cizí položky nepřebírá: zůstává kód z trasy jako dosud.
     */
    public function testRedirectedAnalyticCarryingOnlyOtherItemIsNotTakenOver(): void
    {
        $pdo = $this->db->pdo();
        [$center, $a, $b] = $this->costCenters();
        $pdo->prepare("UPDATE chart_of_accounts SET is_active = 0 WHERE supplier_id = ? AND account_code = '501.900'")->execute([$this->supplierId]);
        $pdo->prepare("UPDATE posting_rules SET debit_account_code = '501' WHERE supplier_id = ? AND rule_key = 'invoice.material.received'")->execute([$this->supplierId]);
        $pi = $this->purchase('PF-2091-OTHER', 73200.00, self::YEAR . '-11-15');
        $first = $this->item($pi, 36600.00, '2092-01-01', '2092-12-31', null);
        $this->item($pi, 36600.00, null, null, '501.100');
        $pdo->prepare("UPDATE purchase_invoice_items SET description = 'Syntetický materiál', expense_kind = 'material' WHERE purchase_invoice_id = ?")->execute([$pi]);
        self::assertSame('501', (string) $this->posting->purchaseItemExpenseAccounts($this->supplierId, $pi)[$first], 'Sanity: trasa vrací syntetiku z předkontace.');
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $pi, [
            ['account_code' => '548', 'side' => 'debit', 'amount' => 36600.00, 'dimensions' => [$center => $a]],
            ['account_code' => '501.100', 'side' => 'debit', 'amount' => 36600.00, 'dimensions' => [$center => $b]],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 73200.00],
        ], ['entry_date' => self::YEAR . '-11-15', 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        (new \MyInvoice\Repository\DimensionAssignmentRepository($this->db))->replaceDocumentDimensions($this->supplierId, 'purchase_invoice', $pi, [], [
            1 => [$center => $a],
            2 => [$center => $b],
        ]);
        self::assertSame('501.100', $this->posting->singleAnalyticRedirects($this->supplierId, self::YEAR . '-11-15')['501'] ?? null, 'Sanity: osnova přesměrovává.');

        $preview = $this->closing->prepaidExpenseAccrualPreview($this->supplierId, $this->periodId);

        self::assertSame(['501'], array_map('strval', array_keys($preview['by_account'])));
    }

    /**
     * Přestavba osnovy k 1. 1.: analytika odkladu je od dalšího roku neaktivní a syntetiku
     * přebírá jiná analytika. Rozpuštění se zaúčtuje podle osnovy k datu rozpuštění
     * s dimenzemi položky, otevření roku nespadne.
     */
    public function testReleaseFallsBackWhenRedirectTargetIsDeactivatedFromNextYear(): void
    {
        $pdo = $this->db->pdo();
        [$center, $a] = $this->costCenters();
        $pdo->prepare("UPDATE chart_of_accounts SET is_active = 0 WHERE supplier_id = ? AND account_code = '501.900'")->execute([$this->supplierId]);
        $pdo->prepare("UPDATE posting_rules SET debit_account_code = '501' WHERE supplier_id = ? AND rule_key = 'invoice.material.received'")->execute([$this->supplierId]);
        $pi = $this->purchase('PF-2091-REORG', 36600.00, self::YEAR . '-11-15');
        $this->item($pi, 36600.00, '2092-01-01', '2092-12-31', null);
        $pdo->prepare("UPDATE purchase_invoice_items SET description = 'Syntetický materiál', expense_kind = 'material' WHERE purchase_invoice_id = ?")->execute([$pi]);
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $pi, [
            ['account_code' => '501', 'side' => 'debit', 'amount' => 36600.00, 'dimensions' => [$center => $a]],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 36600.00],
        ], ['entry_date' => self::YEAR . '-11-15', 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        (new \MyInvoice\Repository\DimensionAssignmentRepository($this->db))->replaceDocumentDimensions($this->supplierId, 'purchase_invoice', $pi, [], [1 => [$center => $a]]);

        $this->driveCloseWithAccrual();
        $defer = $this->journal->findBySource($this->supplierId, 'prepaid_expense_accrual', ClosingSourceId::prepaidExpenseAccrual($this->periodId));
        self::assertNotNull($defer);
        self::assertSame([['501.100', 'credit', 36600.0, $a]], $this->expenseByCenter((int) $defer['id'], $center));
        $this->changeAccount('501.100', (self::YEAR + 1) . '-01-01', ['is_active' => 0]);
        $this->changeAccount('501.900', (self::YEAR + 1) . '-01-01', ['is_active' => 1]);
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        $release = $this->journal->findBySource($this->supplierId, 'prepaid_expense_accrual', ClosingSourceId::prepaidExpenseAccrualRelease($this->periodId));
        self::assertNotNull($release);
        self::assertSame([['501.900', 'debit', 36600.0, $a]], $this->expenseByCenter((int) $release['id'], $center));
    }

    /**
     * Zápis nese položce vyhovující řádky (S-A) na dvou analytikách syntetiky: není jasné,
     * ze které se odkládá, odklad zůstává na kódu z trasy jako dosud.
     */
    public function testTwoMatchingAnalyticsAreNotGuessed(): void
    {
        $pdo = $this->db->pdo();
        [$center, $a] = $this->costCenters();
        $pdo->prepare("UPDATE posting_rules SET debit_account_code = '501' WHERE supplier_id = ? AND rule_key = 'invoice.material.received'")->execute([$this->supplierId]);
        $pi = $this->purchase('PF-2091-TWO', 36600.00, self::YEAR . '-11-15');
        $first = $this->item($pi, 36600.00, '2092-01-01', '2092-12-31', null);
        $pdo->prepare("UPDATE purchase_invoice_items SET description = 'Syntetický materiál', expense_kind = 'material' WHERE purchase_invoice_id = ?")->execute([$pi]);
        self::assertSame('501', (string) $this->posting->purchaseItemExpenseAccounts($this->supplierId, $pi)[$first], 'Sanity: trasa vrací syntetiku z předkontace.');
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $pi, [
            ['account_code' => '501.100', 'side' => 'debit', 'amount' => 18300.00, 'dimensions' => [$center => $a]],
            ['account_code' => '501.900', 'side' => 'debit', 'amount' => 18300.00, 'dimensions' => [$center => $a]],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 36600.00],
        ], ['entry_date' => self::YEAR . '-11-15', 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        (new \MyInvoice\Repository\DimensionAssignmentRepository($this->db))->replaceDocumentDimensions($this->supplierId, 'purchase_invoice', $pi, [], [1 => [$center => $a]]);

        $preview = $this->closing->prepaidExpenseAccrualPreview($this->supplierId, $this->periodId);

        self::assertSame(['501'], array_map('strval', array_keys($preview['by_account'])));
    }

    /**
     * Jednoduchá forma, náklady jen na jedné straně: dvojice zůstávají stejné jako dřív, kdy
     * párovač rozdělil jeden řádek 381 proti řádkům 548 sám.
     */
    public function testSingleEntryFormWithCostsOnOneSideKeepsSamePairs(): void
    {
        $this->useSingleEntryForm();
        [$center, $a, $b] = $this->costCenters();
        $this->postedCost('PF-2091-SF3', 36600.00, '548', $center, $a);
        $this->postedCost('PF-2091-SF4', 12200.00, '548', $center, $b);
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")->execute([$this->periodId]);

        $run = $this->closing->runPrepaidExpenseAccrual($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        self::assertSame(['381|548|18200.00', '381|548|6066.67'], $this->pairsOf((int) $run['entry_id']));
        self::assertSame([['548', 'credit', 6066.67, $b], ['548', 'credit', 18200.0, $a]], $this->expenseByCenter((int) $run['entry_id'], $center));
    }

    /**
     * Osnova syntetiku přesměrovává, zápis faktury ale cílovou analytiku nenese (položka je
     * zaúčtovaná ručně jinam): odklad zůstává na kódu z trasy jako dosud.
     */
    public function testRedirectTargetMissingInEntryKeepsTraceCode(): void
    {
        $pdo = $this->db->pdo();
        [$center, $a] = $this->costCenters();
        $pdo->prepare("UPDATE chart_of_accounts SET is_active = 0 WHERE supplier_id = ? AND account_code = '501.900'")->execute([$this->supplierId]);
        $pdo->prepare("UPDATE posting_rules SET debit_account_code = '501' WHERE supplier_id = ? AND rule_key = 'invoice.material.received'")->execute([$this->supplierId]);
        $pi = $this->purchase('PF-2091-MISS', 36600.00, self::YEAR . '-11-15');
        $this->item($pi, 36600.00, '2092-01-01', '2092-12-31', null);
        $pdo->prepare("UPDATE purchase_invoice_items SET description = 'Syntetický materiál', expense_kind = 'material' WHERE purchase_invoice_id = ?")->execute([$pi]);
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $pi, [
            ['account_code' => '548', 'side' => 'debit', 'amount' => 36600.00, 'dimensions' => [$center => $a]],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 36600.00],
        ], ['entry_date' => self::YEAR . '-11-15', 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        self::assertSame('501.100', $this->posting->singleAnalyticRedirects($this->supplierId, self::YEAR . '-11-15')['501'] ?? null, 'Sanity: osnova přesměrovává.');

        $preview = $this->closing->prepaidExpenseAccrualPreview($this->supplierId, $this->periodId);

        self::assertSame(['501'], array_map('strval', array_keys($preview['by_account'])));
        $pdo->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")->execute([$this->periodId]);
        $run = $this->closing->runPrepaidExpenseAccrual($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        self::assertSame([['501.100', 'credit', 36600.0, null]], $this->expenseByCenter((int) $run['entry_id'], $center), 'Zaúčtování přesměruje podle osnovy, bez dimenzí.');
    }

    /**
     * Analytika, na kterou zápis faktury přesměroval, je k datu odkladu deaktivovaná: odklad
     * zůstane na kódu z trasy (zaúčtování ho přesměruje podle osnovy k tomu dni) s dimenzemi
     * položky a krok uzávěrky projde, místo aby padal na deaktivovaném účtu.
     */
    public function testDeactivatedRedirectTargetFallsBackToTraceCode(): void
    {
        $pdo = $this->db->pdo();
        [$center, $a] = $this->costCenters();
        $pdo->prepare("UPDATE chart_of_accounts SET is_active = 0 WHERE supplier_id = ? AND account_code = '501.900'")->execute([$this->supplierId]);
        $pdo->prepare("UPDATE posting_rules SET debit_account_code = '501' WHERE supplier_id = ? AND rule_key = 'invoice.material.received'")->execute([$this->supplierId]);
        $pi = $this->purchase('PF-2091-DEACT', 36600.00, self::YEAR . '-11-15');
        $this->item($pi, 36600.00, '2092-01-01', '2092-12-31', null);
        $pdo->prepare("UPDATE purchase_invoice_items SET description = 'Syntetický materiál', expense_kind = 'material' WHERE purchase_invoice_id = ?")->execute([$pi]);
        $entryId = $this->posting->postDocument($this->supplierId, 'purchase_invoice', $pi, [
            ['account_code' => '501', 'side' => 'debit', 'amount' => 36600.00, 'dimensions' => [$center => $a]],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 36600.00],
        ], ['entry_date' => self::YEAR . '-11-15', 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        self::assertEqualsWithDelta(36600.00, $this->sideAmount($this->entryLines($entryId), '501.100', 'debit'), 0.001, 'Sanity: zápis leží na jediné analytice.');
        $this->changeAccount('501.100', self::YEAR . '-12-01', ['is_active' => 0]);
        $this->changeAccount('501.900', self::YEAR . '-12-01', ['is_active' => 1]);
        $pdo->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")->execute([$this->periodId]);

        $run = $this->closing->runPrepaidExpenseAccrual($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        self::assertSame([['501.900', 'credit', 36600.0, $a]], $this->expenseByCenter((int) $run['entry_id'], $center));
    }

    /**
     * Daňová uznatelnost analytiky se čte k datu zápisu faktury (verzovaná osnova), stejně
     * jako při přesměrování: analytika fyzicky vedená jako nedaňová, od začátku roku ale
     * daňová, je cílem přesměrování a odklad nese středisko položky.
     */
    public function testDeductibilityOfAnalyticIsReadAtEntryDate(): void
    {
        $pdo = $this->db->pdo();
        [$center, $a] = $this->costCenters();
        $pdo->prepare("UPDATE chart_of_accounts SET is_active = 0 WHERE supplier_id = ? AND account_code = '501.900'")->execute([$this->supplierId]);
        $pdo->prepare("UPDATE chart_of_accounts SET tax_deductibility = 'non_deductible' WHERE supplier_id = ? AND account_code = '501.100'")->execute([$this->supplierId]);
        $this->changeAccount('501.100', self::STARTS_ON, ['tax_deductibility' => 'deductible']);
        $pdo->prepare("UPDATE posting_rules SET debit_account_code = '501' WHERE supplier_id = ? AND rule_key = 'invoice.material.received'")->execute([$this->supplierId]);
        $pi = $this->purchase('PF-2091-DATED', 36600.00, self::YEAR . '-11-15');
        $this->item($pi, 36600.00, '2092-01-01', '2092-12-31', null);
        $pdo->prepare("UPDATE purchase_invoice_items SET description = 'Syntetický materiál', expense_kind = 'material' WHERE purchase_invoice_id = ?")->execute([$pi]);
        $entryId = $this->posting->postDocument($this->supplierId, 'purchase_invoice', $pi, [
            ['account_code' => '501', 'side' => 'debit', 'amount' => 36600.00, 'dimensions' => [$center => $a]],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 36600.00],
        ], ['entry_date' => self::YEAR . '-11-15', 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        self::assertEqualsWithDelta(36600.00, $this->sideAmount($this->entryLines($entryId), '501.100', 'debit'), 0.001, 'Sanity: přesměrování podle definice k datu.');
        (new \MyInvoice\Repository\DimensionAssignmentRepository($this->db))->replaceDocumentDimensions($this->supplierId, 'purchase_invoice', $pi, [], [1 => [$center => $a]]);
        $pdo->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")->execute([$this->periodId]);

        $run = $this->closing->runPrepaidExpenseAccrual($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        self::assertSame([['501.100', 'credit', 36600.0, $a]], $this->expenseByCenter((int) $run['entry_id'], $center));
    }

    /**
     * Nese-li zápis řádek přímo na syntetice z trasy, odkládá se z ní, i když osnova dnes
     * syntetiku přesměrovává na analytiku, kterou zápis také nese (jiný řádek dokladu,
     * přesměrování bylo při zaúčtování vypnuté).
     */
    public function testSyntheticWithOwnLineInEntryKeepsIt(): void
    {
        $pdo = $this->db->pdo();
        [$center, $a, $b] = $this->costCenters();
        $pdo->prepare('INSERT INTO accounting_supplier_settings (supplier_id, single_analytic_redirect) VALUES (?, 0)
                       ON DUPLICATE KEY UPDATE single_analytic_redirect = 0')->execute([$this->supplierId]);
        $pdo->prepare("UPDATE chart_of_accounts SET is_active = 0 WHERE supplier_id = ? AND account_code = '501.900'")->execute([$this->supplierId]);
        $pdo->prepare("UPDATE posting_rules SET debit_account_code = '501' WHERE supplier_id = ? AND rule_key = 'invoice.material.received'")->execute([$this->supplierId]);
        $pi = $this->purchase('PF-2091-OWN', 37600.00, self::YEAR . '-11-15');
        $first = $this->item($pi, 36600.00, '2092-01-01', '2092-12-31', null);
        $this->item($pi, 1000.00, null, null, null);
        $pdo->prepare("UPDATE purchase_invoice_items SET description = 'Syntetický materiál', expense_kind = 'material' WHERE purchase_invoice_id = ?")->execute([$pi]);
        self::assertSame('501', (string) $this->posting->purchaseItemExpenseAccounts($this->supplierId, $pi)[$first], 'Sanity: trasa vrací syntetiku.');
        $entryId = $this->posting->postDocument($this->supplierId, 'purchase_invoice', $pi, [
            ['account_code' => '501', 'side' => 'debit', 'amount' => 36600.00, 'dimensions' => [$center => $a]],
            ['account_code' => '501.100', 'side' => 'debit', 'amount' => 1000.00, 'dimensions' => [$center => $b]],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 37600.00],
        ], ['entry_date' => self::YEAR . '-11-15', 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        self::assertEqualsWithDelta(36600.00, $this->sideAmount($this->entryLines($entryId), '501', 'debit'), 0.001, 'Sanity: řádek zůstal na syntetice.');
        // Přepínač přesměrování není verzovaný: po zapnutí mapa k datu faktury 501 → 501.100 vrací.
        $pdo->prepare('UPDATE accounting_supplier_settings SET single_analytic_redirect = 1 WHERE supplier_id = ?')->execute([$this->supplierId]);

        $preview = $this->closing->prepaidExpenseAccrualPreview($this->supplierId, $this->periodId);

        self::assertSame(['501'], array_map('strval', array_keys($preview['by_account'])));
    }

    /**
     * Převzatá přijatá faktura nemá vlastní zápis: účtuje ji ruční zápis ve skupině
     * zaúčtování dokladu (převod z jiného programu). Odklad i rozpuštění nesou střediska
     * a poměr z tohoto zápisu. Zápis navázaný jen informativní vazbou „Souvisí“ (oprava,
     * úhrada) zaúčtováním dokladu není a do poměru nevstupuje, stejně jako stornovaný člen
     * skupiny.
     */
    public function testDeferralOfDocumentPostedByOwnedEntryCarriesItsCostCenters(): void
    {
        [$center, $a, $b] = $this->costCenters();
        $c = (int) Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Dimension\DimensionService::class)
            ->createValue($this->supplierId, $center, ['code' => 'S-C', 'name' => 'Středisko C'])['id'];
        $pi = $this->purchase('PF-2091-OWNED', 36600.00, self::YEAR . '-11-15');
        $this->item($pi, 36600.00, '2091-07-01', '2092-06-30', '548');
        $opts = ['entry_date' => self::YEAR . '-11-15', 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId];
        $owned = $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '548', 'side' => 'debit', 'amount' => 24400.00, 'dimensions' => [$center => $a]],
            ['account_code' => '548', 'side' => 'debit', 'amount' => 12200.00, 'dimensions' => [$center => $b]],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 36600.00],
        ], $opts);
        $reversed = $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '548', 'side' => 'debit', 'amount' => 5000.00, 'dimensions' => [$center => $c]],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 5000.00],
        ], $opts);
        (new \MyInvoice\Service\Accounting\DocumentPostingGroup($this->db))->attach($this->supplierId, 'purchase_invoice', $pi, [$owned, $reversed]);
        $this->posting->reverse($this->supplierId, $reversed);
        $related = $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '548', 'side' => 'debit', 'amount' => 5000.00, 'dimensions' => [$center => $c]],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 5000.00],
        ], $opts);
        (new \MyInvoice\Repository\JournalEntryDocumentLinkRepository($this->db))->add($related, $this->supplierId, 'purchase_invoice', $pi, null, $this->userId);

        $this->driveCloseWithAccrual();
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        // 36 600 × 182/366 = 18 200 v poměru zápisu 2 : 1.
        $defer = $this->journal->findBySource($this->supplierId, 'prepaid_expense_accrual', ClosingSourceId::prepaidExpenseAccrual($this->periodId));
        $release = $this->journal->findBySource($this->supplierId, 'prepaid_expense_accrual', ClosingSourceId::prepaidExpenseAccrualRelease($this->periodId));
        self::assertNotNull($defer);
        self::assertNotNull($release);
        self::assertSame([['548', 'credit', 6066.67, $b], ['548', 'credit', 12133.33, $a]], $this->expenseByCenter((int) $defer['id'], $center));
        self::assertSame([['548', 'debit', 6066.67, $b], ['548', 'debit', 12133.33, $a]], $this->expenseByCenter((int) $release['id'], $center));
        self::assertEqualsWithDelta(0.0, $this->balance381AsOf('2092-12-31'), 0.005);
    }

    /**
     * #383, scénář 3: přijatou fakturu účtuje jen zápis ve skupině zaúčtování. Krok uzávěrky
     * ji odloží (výběr nezávisí na zápisu), a stejně ji musí upravit i průběžné časové
     * rozlišení ve výkazech, jinak výkaz nadhodnotí náklad.
     */
    public function testInterimAccrualTakesDocumentPostedOnlyByOwnedEntry(): void
    {
        $pi = $this->purchase('PF-2091-INTERIM', 36600.00, self::YEAR . '-11-15');
        $this->item($pi, 36600.00, '2091-07-01', '2092-06-30', '518');
        $owned = $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '518', 'side' => 'debit', 'amount' => 36600.00],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 36600.00],
        ], ['entry_date' => self::YEAR . '-11-15', 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        (new \MyInvoice\Service\Accounting\DocumentPostingGroup($this->db))->attach($this->supplierId, 'purchase_invoice', $pi, [$owned]);

        $closingStep = $this->closing->prepaidExpenseAccrualPreview($this->supplierId, $this->periodId);
        $interim = Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Reports\InterimAccrualAdjustment::class)
            ->compute($this->supplierId, self::STARTS_ON, self::ENDS_ON);

        self::assertEqualsWithDelta(18200.00, (float) $closingStep['total'], 0.001);
        self::assertEqualsWithDelta(18200.00, $interim['prepaid_expense'], 0.001, 'Výkaz rozliší stejný náklad jako krok uzávěrky.');
        self::assertEqualsWithDelta(-18200.00, $interim['deltas']['518'] ?? 0.0, 0.001);
    }

    /** Verzovaná změna účtu od data (stejná cesta jako úprava osnovy v aplikaci). */
    private function changeAccount(string $code, string $from, array $changes): void
    {
        $id = (int) $this->db->pdo()->query('SELECT id FROM chart_of_accounts WHERE supplier_id = ' . $this->supplierId . " AND account_code = '" . $code . "'")->fetchColumn();
        $definitions = new \MyInvoice\Repository\AccountDefinitionRepository($this->db);
        $definitions->change($this->supplierId, $id, $from, $changes, $definitions->stateHash($this->supplierId, $id, true), $this->userId, 'account_configuration');
    }

    /** @return list<string> dvojice jednoduchého zápisu „účet|účet|částka“, seřazené */
    private function pairsOf(int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT l.pair_no, a.account_code, l.amount
               FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.entry_id = ? AND l.supplier_id = ?
              ORDER BY l.pair_no, a.account_code'
        );
        $stmt->execute([$entryId, $this->supplierId]);
        $pairs = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pairs[(string) $row['pair_no']][] = $row;
        }
        $out = array_map(static fn (array $rows): string => implode('|', array_column($rows, 'account_code')) . '|' . number_format((float) $rows[0]['amount'], 2, '.', ''), $pairs);
        sort($out);
        return array_values($out);
    }

    private function useSingleEntryForm(): void
    {
        $this->db->pdo()->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")->execute([$this->supplierId]);
        Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\EntryForm\EntryFormService::class)
            ->schedule($this->supplierId, self::STARTS_ON, \MyInvoice\Service\Accounting\EntryForm\EntryForm::Single, $this->userId);
    }

    /** @return array{0:int,1:int,2:int} typ středisko, hodnota S-A, hodnota S-B */
    private function costCenters(): array
    {
        $dimensions = Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Dimension\DimensionService::class);
        $dimensions->setEnabled($this->supplierId, true);
        $center = (int) $dimensions->ensureDefaultTypes($this->supplierId, ['stredisko'])['cost_center'];
        $a = (int) $dimensions->createValue($this->supplierId, $center, ['code' => 'S-A', 'name' => 'Středisko A'])['id'];
        $b = (int) $dimensions->createValue($this->supplierId, $center, ['code' => 'S-B', 'name' => 'Středisko B'])['id'];
        return [$center, $a, $b];
    }

    /** Zaúčtovaná přijatá faktura (záporná = dobropis) s jednou rozlišenou položkou na středisku. */
    private function postedCost(string $number, float $base, string $account, int $center, int $value): void
    {
        $pi = $this->purchase($number, $base, self::YEAR . '-11-15');
        $this->item($pi, $base, '2091-07-01', '2092-06-30', $account);
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $pi, [
            ['account_code' => $account, 'side' => $base > 0 ? 'debit' : 'credit', 'amount' => abs($base), 'dimensions' => [$center => $value]],
            ['account_code' => '321', 'side' => $base > 0 ? 'credit' : 'debit', 'amount' => abs($base)],
        ], ['entry_date' => self::YEAR . '-11-15', 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        (new \MyInvoice\Repository\DimensionAssignmentRepository($this->db))->replaceDocumentDimensions($this->supplierId, 'purchase_invoice', $pi, [], [1 => [$center => $value]]);
    }

    /** @return list<array{0:string,1:string,2:float,3:?int}> nákladové řádky zápisu: účet, strana, částka, středisko */
    private function expenseByCenter(int $entryId, int $center): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.account_code, l.side, l.amount, d.dimension_value_id
               FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id
               LEFT JOIN journal_entry_line_dimensions d ON d.line_id = l.id AND d.dimension_type_id = ?
              WHERE l.entry_id = ? AND l.supplier_id = ? AND a.account_code LIKE \'5%\'
              ORDER BY l.amount, l.id'
        );
        $stmt->execute([$center, $entryId, $this->supplierId]);
        return array_map(static fn (array $r): array => [
            (string) $r['account_code'],
            (string) $r['side'],
            (float) $r['amount'],
            $r['dimension_value_id'] === null ? null : (int) $r['dimension_value_id'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

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
