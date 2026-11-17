<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\SmallAssetRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingException;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use MyInvoice\Service\Accounting\PostingService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Časové rozlišení drobného majetku (381/501) u účetní jednotky podle vyhlášky 504/2002 Sb.
 * Nákladový řádek 501 tam musí nést systémové osy činnosti, jinak zápis uzávěrky nejde
 * zaúčtovat. Osy se berou z řádku 501 dokladu, ze kterého náklad pochází. Izolovaný
 * supplier v transakci s rollbackem, jen syntetická data.
 */
#[Group('integration')]
final class ClosingSmallAssetAccrual504Test extends TestCase
{
    private const YEAR = 2093;
    /** Analytika, na kterou výchozí osnova vede položky drobného majetku; odklad jde z ní, ne z 501. */
    private const DM = '501.900';

    private Connection $db;
    private PostingService $posting;
    private ClosingService $closing;
    private AccountingPeriodRepository $periods;
    private JournalEntryRepository $journal;
    private SmallAssetRepository $smallAssets;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $czId = 0;
    private int $activityKindTypeId = 0;
    /** @var array<int,int> typ dimenze => hodnota (osy hlavní činnosti) */
    private array $axes = [];
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        $container = Bootstrap::buildContainer();
        $this->db          = $container->get(Connection::class);
        $this->posting     = $container->get(PostingService::class);
        $this->closing     = $container->get(ClosingService::class);
        $this->periods     = $container->get(AccountingPeriodRepository::class);
        $this->journal     = $container->get(JournalEntryRepository::class);
        $this->smallAssets = $container->get(SmallAssetRepository::class);
        $seeder            = $container->get(ChartOfAccountsSeeder::class);

        $pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
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
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Syntetická 1", "Praha", "11000", ?, ?, ?, ?, "double_entry")'
        )->execute(['Syntetický spolek 504 DM', $this->czId, 'small-asset-504@example.test', $this->currencyId, $this->vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $pdo->prepare("INSERT INTO supplier_accounting_frameworks (supplier_id, effective_from, accounting_framework) VALUES (?, ?, 'cz_504')")
            ->execute([$this->supplierId, self::YEAR . '-01-01']);
        $catalog = (new SystemDimensionCatalog($this->db))->ensureForFramework($this->supplierId, self::YEAR . '-01-01', $this->userId);
        $this->activityKindTypeId = (int) $catalog['activity_kind']['id'];
        $this->axes = [
            (int) $catalog['activity']['id'] => (int) $catalog['activity']['values']['main'],
            $this->activityKindTypeId => $this->activityKindValue('SUBS'),
            (int) $catalog['tax_regime']['id'] => (int) $catalog['tax_regime']['values']['subject'],
        ];
        foreach (['211' => 'asset', '321' => 'liability', '381' => 'asset', '501' => 'expense', '518' => 'expense'] as $code => $type) {
            $pdo->prepare(
                'INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type)
                 SELECT ?, ?, ?, ? FROM DUAL
                  WHERE NOT EXISTS (SELECT 1 FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?)'
            )->execute([$this->supplierId, $code, 'Syntetický ' . $code, $type, $this->supplierId, $code]);
        }
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

    /** pro_rata: notebook 36 500 Kč pořízený 1. 7. → 181/365 za rozvahovým dnem = 18 100 Kč na 381, D 501 s osami faktury. */
    public function testProRataDeferralCarriesAxesOfDocumentLine(): void
    {
        $this->smallAssetPurchase('DM504-1', 36500.00);
        $this->toClosing();

        $run = $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'pro_rata', null, $this->rv(), $this->meta());

        self::assertEqualsWithDelta(18100.00, (float) $run['total'], 0.001);
        $this->assertCarriesAxes((int) $run['entry_id'], self::DM, 'credit', 18100.00, $this->axes);
        self::assertEqualsWithDelta(18100.00, $this->sideTotal((int) $run['entry_id'], '381', 'debit'), 0.001);
    }

    /** flat_pct: 50 % z obratu 501 drobného majetku = 18 250 Kč na 381, D 501 s osami faktury. */
    public function testFlatPctDeferralCarriesAxesOfDocumentLine(): void
    {
        $this->smallAssetPurchase('DM504-2', 36500.00);
        $this->toClosing();

        $run = $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'flat_pct', 50.0, $this->rv(), $this->meta(), 1000000.00);

        self::assertEqualsWithDelta(18250.00, (float) $run['total'], 0.001);
        $this->assertCarriesAxes((int) $run['entry_id'], self::DM, 'credit', 18250.00, $this->axes);
    }

    /**
     * Převzatá přijatá faktura bez vlastního zápisu, jejíž náklad účtuje ruční zápis ve skupině
     * zaúčtování dokladu: odklad nese osy tohoto zápisu (poměrně i paušálem) a krok ji
     * neodmítne jako doklad bez os. Zápis navázaný jen jako „Souvisí“ s jiným druhem činnosti
     * se nepočítá.
     */
    #[DataProvider('importedModeProvider')]
    public function testImportedInvoiceDefersWithAxesOfOwnedEntry(string $mode, ?float $pct, float $total): void
    {
        [$piId, $itemId] = $this->purchase('DM504-IMP', 36500.00, null, false);
        $date = self::YEAR . '-07-01';
        $opts = ['entry_date' => $date, 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId];
        $owned = $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => self::DM, 'side' => 'debit', 'amount' => 36500.00, 'dimensions' => $this->axes, 'dimension_origins' => $this->origins()],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 36500.00],
        ], $opts);
        (new \MyInvoice\Service\Accounting\DocumentPostingGroup($this->db))->attach($this->supplierId, 'purchase_invoice', $piId, [$owned]);
        $other = $this->axes;
        $other[$this->activityKindTypeId] = $this->activityKindValue('SUB2');
        $related = $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => self::DM, 'side' => 'debit', 'amount' => 1000.00, 'dimensions' => $other, 'dimension_origins' => $this->origins($other)],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 1000.00],
        ], $opts);
        (new \MyInvoice\Repository\JournalEntryDocumentLinkRepository($this->db))->add($related, $this->supplierId, 'purchase_invoice', $piId, null, $this->userId);
        $this->smallAssets->insert($this->supplierId, [
            'name' => 'Převzatý notebook', 'acquisition_date' => $date, 'price' => 36500.00, 'quantity' => 1,
            'unit_price' => 36500.00, 'status' => 'in_use', 'purchase_invoice_id' => $piId, 'purchase_invoice_item_id' => $itemId,
        ], $this->userId);
        $this->toClosing();

        $run = $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, $mode, $pct, $this->rv(), $this->meta(), 1000000.00);

        self::assertEqualsWithDelta($total, (float) $run['total'], 0.001);
        $this->assertCarriesAxes((int) $run['entry_id'], self::DM, 'credit', $total, $this->axes);
    }

    /** @return array<string,array{0:string,1:?float,2:float}> */
    public static function importedModeProvider(): array
    {
        return ['poměrně' => ['pro_rata', null, 18100.00], 'paušál 50 %' => ['flat_pct', 50.0, 18250.00]];
    }

    /** Paušál se dělí poměrem obratu 501 drobného majetku po kombinacích os (jiný druh činnosti). */
    public function testFlatPctSplitsByAxisCombinationOfTurnover(): void
    {
        $other = $this->axes;
        $other[$this->activityKindTypeId] = $this->activityKindValue('SUB2');
        $this->smallAssetPurchase('DM504-3', 30000.00);
        $this->smallAssetPurchase('DM504-4', 10000.00, $other);
        $this->toClosing();

        $run = $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'flat_pct', 50.0, $this->rv(), $this->meta(), 1000000.00);

        self::assertEqualsWithDelta(20000.00, (float) $run['total'], 0.001);
        $byKind = $this->creditByActivityKind((int) $run['entry_id']);
        self::assertCount(2, $byKind);
        self::assertEqualsWithDelta(15000.00, $byKind[$this->axes[$this->activityKindTypeId]] ?? 0.0, 0.001);
        self::assertEqualsWithDelta(5000.00, $byKind[$other[$this->activityKindTypeId]] ?? 0.0, 0.001);
    }

    /** pro_rata se dělí po kartách: každá karta nese osy svého dokladu. */
    public function testProRataSplitsByCardDocument(): void
    {
        $other = $this->axes;
        $other[$this->activityKindTypeId] = $this->activityKindValue('SUB2');
        $this->smallAssetPurchase('DM504-5', 36500.00);
        $this->smallAssetPurchase('DM504-6', 36500.00, $other);
        $this->toClosing();

        $run = $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'pro_rata', null, $this->rv(), $this->meta());

        self::assertEqualsWithDelta(36200.00, (float) $run['total'], 0.001);
        $byKind = $this->creditByActivityKind((int) $run['entry_id']);
        self::assertCount(2, $byKind);
        self::assertEqualsWithDelta(18100.00, $byKind[$this->axes[$this->activityKindTypeId]] ?? 0.0, 0.001);
        self::assertEqualsWithDelta(18100.00, $byKind[$other[$this->activityKindTypeId]] ?? 0.0, 0.001);
    }

    /** Stornovaný zápis pokladního dokladu s jinou činností se nepočítá, osy dá živý zápis. */
    public function testCashDocumentCardIgnoresReversedEntry(): void
    {
        $this->cashSmallAsset('reposted');
        $this->toClosing();

        $run = $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'pro_rata', null, $this->rv(), $this->meta());

        $this->assertCarriesAxes((int) $run['entry_id'], '501', 'credit', 18100.00, $this->axes);
    }

    /** Pokladní doklad se zápisem jen v konceptu není zaúčtovaný: krok kartu odmítne. */
    public function testCashDocumentCardWithDraftEntryIsRejected(): void
    {
        $this->cashSmallAsset('draft');
        $this->toClosing();

        try {
            $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'pro_rata', null, $this->rv(), $this->meta());
            self::fail('Karta pokladního dokladu bez zaúčtovaného zápisu musí krok ve 504 odmítnout.');
        } catch (ClosingException $e) {
            self::assertStringContainsString('Notebook z pokladny', $e->getMessage());
        }
    }

    /**
     * Karta z pokladního dokladu (doklad bez položek): osy se berou z nákladového řádku
     * syntetiky účtu odkladu (501), ne z jiného nákladu téhož dokladu (518 s jinou činností).
     */
    public function testCashDocumentCardTakesAxesOfItsSmallAssetAccount(): void
    {
        $this->cashSmallAsset();
        $this->toClosing();

        $run = $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'pro_rata', null, $this->rv(), $this->meta());

        $this->assertCarriesAxes((int) $run['entry_id'], '501', 'credit', 18100.00, $this->axes);
    }

    /** Paušál bez faktur drobného majetku v rozpisu 501 bere bázi z karet a osy z jejich dokladů. */
    public function testFlatPctFromCardBaseTakesAxesOfCardDocument(): void
    {
        $this->cashSmallAsset();
        $this->toClosing();

        $run = $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'flat_pct', 50.0, $this->rv(), $this->meta(), 1000000.00);

        self::assertEqualsWithDelta(18250.00, (float) $run['total'], 0.001);
        $this->assertCarriesAxes((int) $run['entry_id'], '501', 'credit', 18250.00, $this->axes);
    }

    /**
     * Opravný doklad drobného majetku na jiné činnosti: záporná kombinace obratu jde na stranu
     * MD 501 se svými osami, kladná na D 501, 381 nese saldo.
     */
    public function testFlatPctCreditNoteOnOtherAxisGoesToDebitWithItsAxes(): void
    {
        $other = $this->axes;
        $other[$this->activityKindTypeId] = $this->activityKindValue('SUB2');
        $this->smallAssetPurchase('DM504-9', 30000.00);
        $this->purchase('DM504-9D', -10000.00, $other);
        $this->toClosing();

        $run = $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'flat_pct', 50.0, $this->rv(), $this->meta(), 1000000.00);

        $entryId = (int) $run['entry_id'];
        self::assertEqualsWithDelta(10000.00, (float) $run['total'], 0.001);
        // 381 nese saldo odkladu; ve složené formě zápisu jedním řádkem jako odklad 381 z faktur
        // (dvojice dostane každý řádek 501 jen v jednoduché formě).
        self::assertEqualsWithDelta(10000.00, $this->sideTotal($entryId, '381', 'debit') - $this->sideTotal($entryId, '381', 'credit'), 0.001);
        self::assertCount(1, $this->linesOn($entryId, '381'));
        $dims = (new DimensionAssignmentRepository($this->db))->entryLineDimensions($this->supplierId, $entryId);
        $bySide = [];
        foreach ($this->linesOn($entryId, self::DM) as $line) {
            $bySide[$line['side']][] = [(int) ($dims[(int) $line['id']][$this->activityKindTypeId] ?? 0), round((float) $line['amount'], 2)];
        }
        self::assertSame([[$this->axes[$this->activityKindTypeId], 15000.0]], $bySide['credit'] ?? []);
        self::assertSame([[$other[$this->activityKindTypeId], 5000.0]], $bySide['debit'] ?? []);
    }

    /** Paušál po řádcích: haléřový rozdíl zaokrouhlení jde na největší řádek, zápis sedí na celek. */
    public function testFlatPctRoundingDifferenceGoesToLargestLine(): void
    {
        foreach (['SUBS' => null, 'SUB2' => 'SUB2', 'SUB3' => 'SUB3'] as $number => $code) {
            $axes = $this->axes;
            if ($code !== null) {
                $axes[$this->activityKindTypeId] = $this->activityKindValue($code);
            }
            $this->smallAssetPurchase('DM504-R' . $number, 100.01, $axes);
        }
        $this->toClosing();

        $run = $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'flat_pct', 33.0, $this->rv(), $this->meta(), 1000000.00);

        // 300,03 × 33 % = 99,01; po řádcích 3 × 33,00 = 99,00 → +0,01 na jeden řádek.
        self::assertEqualsWithDelta(99.01, (float) $run['total'], 0.001);
        $amounts = array_values($this->creditByActivityKind((int) $run['entry_id']));
        sort($amounts);
        self::assertSame([33.0, 33.0, 33.01], $amounts);
    }

    /** Karta jen s fakturou (bez položky): osy z nákladu 501 faktury, ne z jiného nákladu téže faktury. */
    public function testCardWithInvoiceButNoItemTakesAxesOfInvoiceSmallAssetAccount(): void
    {
        $other = $this->axes;
        $other[$this->activityKindTypeId] = $this->activityKindValue('SUB2');
        [$piId] = $this->purchase('DM504-10', 36500.00, null, true, 'received', [
            ['account_code' => '518', 'side' => 'debit', 'amount' => 1000.00, 'dimensions' => $other, 'dimension_origins' => $this->origins()],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 1000.00],
        ]);
        $this->smallAssets->insert($this->supplierId, [
            'name' => 'Notebook jen s fakturou', 'acquisition_date' => self::YEAR . '-07-01', 'price' => 36500.00, 'quantity' => 1,
            'unit_price' => 36500.00, 'status' => 'in_use', 'purchase_invoice_id' => $piId,
        ], $this->userId);
        $this->toClosing();

        $run = $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'pro_rata', null, $this->rv(), $this->meta());

        $this->assertCarriesAxes((int) $run['entry_id'], self::DM, 'credit', 18100.00, $this->axes);
    }

    /**
     * Účet položky, který zápis faktury nenese (faktura zaúčtovaná ručně na syntetiku 501,
     * trasa položky vede na analytiku): dimenze se vezmou poměrem nákladu 501 faktury,
     * ne nic s výzvou doplnit vazbu.
     */
    public function testItemAccountMissingInEntryFallsBackToInvoiceExpense(): void
    {
        $other = $this->axes;
        $other[$this->activityKindTypeId] = $this->activityKindValue('SUB2');
        [$piId, $itemId] = $this->purchase('DM504-14', 36500.00, null, false);
        self::assertNotSame('501', (string) $this->posting->purchaseItemExpenseAccounts($this->supplierId, $piId)[$itemId], 'Sanity: položka vede na analytiku.');
        $date = self::YEAR . '-07-01';
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $piId, [
            ['account_code' => '501', 'side' => 'debit', 'amount' => 18250.00, 'dimensions' => $this->axes, 'dimension_origins' => $this->origins()],
            ['account_code' => '501', 'side' => 'debit', 'amount' => 18250.00, 'dimensions' => $other, 'dimension_origins' => $this->origins()],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 36500.00],
        ], ['entry_date' => $date, 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        $this->smallAssets->insert($this->supplierId, [
            'name' => 'Notebook ručně zaúčtovaný', 'acquisition_date' => $date, 'price' => 36500.00, 'quantity' => 1,
            'unit_price' => 36500.00, 'status' => 'in_use', 'purchase_invoice_id' => $piId, 'purchase_invoice_item_id' => $itemId,
        ], $this->userId);
        $this->toClosing();

        $run = $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'pro_rata', null, $this->rv(), $this->meta());

        $byKind = $this->creditByActivityKind((int) $run['entry_id'], '501');
        self::assertEqualsWithDelta(9050.00, $byKind[$this->axes[$this->activityKindTypeId]] ?? 0.0, 0.001);
        self::assertEqualsWithDelta(9050.00, $byKind[$other[$this->activityKindTypeId]] ?? 0.0, 0.001);
    }

    /** Paušál z faktur v Kč a v EUR na různých osách se dělí podle částek v Kč, které nesou zápisy. */
    public function testFlatPctSplitsForeignInvoiceByCzkAmount(): void
    {
        $eurId = (int) ($this->db->pdo()->query("SELECT id FROM currencies WHERE code = 'EUR' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        if ($eurId === 0) {
            self::markTestSkipped('Chybí měna EUR.');
        }
        $other = $this->axes;
        $other[$this->activityKindTypeId] = $this->activityKindValue('SUB2');
        $this->smallAssetPurchase('DM504-15', 30000.00);
        $this->purchase('DM504-15E', 1000.00, $other, true, 'received', [], $eurId, 25.0);
        $this->toClosing();

        $run = $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'flat_pct', 50.0, $this->rv(), $this->meta(), 1000000.00);

        // Báze rozpisu 501 se sčítá v měně dokladu; dělí se poměrem 30 000 : 25 000 Kč.
        $total = (float) $run['total'];
        $byKind = $this->creditByActivityKind((int) $run['entry_id']);
        self::assertEqualsWithDelta(round($total * 30000 / 55000, 2), $byKind[$this->axes[$this->activityKindTypeId]] ?? 0.0, 0.011);
        self::assertEqualsWithDelta(round($total * 25000 / 55000, 2), $byKind[$other[$this->activityKindTypeId]] ?? 0.0, 0.011);
    }

    /**
     * Dobropis v EUR váhu faktury v Kč skoro vyrovná (1 000 Kč proti 39,90 EUR po 25 Kč):
     * dělí se částkami v měně dokladu, žádný řádek nepřesáhne celek a strany osám zůstanou.
     */
    public function testFlatPctWithMixedSignCzkWeightsSplitsByDocumentAmounts(): void
    {
        $eurId = (int) ($this->db->pdo()->query("SELECT id FROM currencies WHERE code = 'EUR' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        if ($eurId === 0) {
            self::markTestSkipped('Chybí měna EUR.');
        }
        $other = $this->axes;
        $other[$this->activityKindTypeId] = $this->activityKindValue('SUB2');
        $this->smallAssetPurchase('DM504-20', 1000.00);
        $this->purchase('DM504-20D', -39.90, $other, true, 'received', [], $eurId, 25.0);
        $this->toClosing();

        $run = $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'flat_pct', 50.0, $this->rv(), $this->meta(), 1000000.00);

        $entryId = (int) $run['entry_id'];
        self::assertEqualsWithDelta(480.05, (float) $run['total'], 0.001);
        $dims = (new DimensionAssignmentRepository($this->db))->entryLineDimensions($this->supplierId, $entryId);
        $bySide = [];
        foreach ($this->linesOn($entryId, self::DM) as $line) {
            $bySide[$line['side']][] = [(int) ($dims[(int) $line['id']][$this->activityKindTypeId] ?? 0), round((float) $line['amount'], 2)];
        }
        self::assertSame([[$this->axes[$this->activityKindTypeId], 500.0]], $bySide['credit'] ?? []);
        self::assertSame([[$other[$this->activityKindTypeId], 19.95]], $bySide['debit'] ?? []);
    }

    /**
     * Malý dobropis v Kč vedle faktur v Kč a v EUR: váhy v Kč zůstávají (dobropis odečte jen
     * zlomek), EUR faktura nese podíl podle Kč, ne podle částky v měně dokladu.
     */
    public function testFlatPctSmallCreditNoteKeepsCzkWeights(): void
    {
        $eurId = (int) ($this->db->pdo()->query("SELECT id FROM currencies WHERE code = 'EUR' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        if ($eurId === 0) {
            self::markTestSkipped('Chybí měna EUR.');
        }
        $other = $this->axes;
        $other[$this->activityKindTypeId] = $this->activityKindValue('SUB2');
        $this->smallAssetPurchase('DM504-21', 30000.00);
        $this->purchase('DM504-21E', 1000.00, $other, true, 'received', [], $eurId, 25.0);
        $this->purchase('DM504-21D', -100.00);
        $this->toClosing();

        $run = $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'flat_pct', 50.0, $this->rv(), $this->meta(), 1000000.00);

        $total = (float) $run['total'];
        $byKind = $this->creditByActivityKind((int) $run['entry_id']);
        self::assertEqualsWithDelta(round($total * 29900 / 54900, 2), $byKind[$this->axes[$this->activityKindTypeId]] ?? 0.0, 0.011);
        self::assertEqualsWithDelta(round($total * 25000 / 54900, 2), $byKind[$other[$this->activityKindTypeId]] ?? 0.0, 0.011);
    }

    /** Dva koncepty se stejným číslem od různých dodavatelů: hláška jmenuje oba. */
    public function testDraftsWithSameNumberFromDifferentVendorsAreBothListed(): void
    {
        $this->smallAssetPurchase('DM504-22', 36500.00);
        $this->purchase('DUP-1', 1000.00, null, false, 'draft');
        $this->purchase('DUP-1', 2000.00, null, false, 'draft');
        $this->toClosing();

        try {
            $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'flat_pct', 50.0, $this->rv(), $this->meta(), 1000000.00);
            self::fail('Nezaúčtované koncepty musí ve 504 skončit validační chybou.');
        } catch (ClosingException $e) {
            self::assertSame(2, substr_count($e->getMessage(), 'přijatá faktura DUP-1'), $e->getMessage());
        }
    }

    /** Zálohová výzva s položkou drobného majetku v rozpisu 501: do dělení nevstupuje a krok nezablokuje. */
    public function testFlatPctIgnoresAdvanceInvoiceInWeights(): void
    {
        $this->smallAssetPurchase('DM504-23', 36500.00);
        $this->purchase('DM504-23Z', 36500.00, null, false, 'received', [], null, null, 'advance');
        $this->toClosing();

        $run = $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'flat_pct', 50.0, $this->rv(), $this->meta(), 1000000.00);

        // Báze rozpisu zálohu dál obsahuje (převzaté chování), celý odklad nese osy faktury.
        $this->assertCarriesAxes((int) $run['entry_id'], self::DM, 'credit', (float) $run['total'], $this->axes);
    }

    /** Daňový doklad k záloze s položkou drobného majetku: do dělení nevstupuje a krok nezablokuje. */
    public function testFlatPctIgnoresAdvanceTaxDocumentInWeights(): void
    {
        $this->smallAssetPurchase('DM504-27', 36500.00);
        $this->purchase('DM504-27Z', 36500.00, null, false, 'received', [], null, null, 'tax_document');
        $this->toClosing();

        $run = $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'flat_pct', 50.0, $this->rv(), $this->meta(), 1000000.00);

        $this->assertCarriesAxes((int) $run['entry_id'], self::DM, 'credit', (float) $run['total'], $this->axes);
    }

    /** Rozpis 501 jen ze zálohy a žádná karta: ve 504 srozumitelná 422 s výzvou zaúčtovat konečnou fakturu. */
    public function testFlatPctWithOnlyAdvanceInBaseIsRejectedWithGuidance(): void
    {
        $this->purchase('DM504-28Z', 36500.00, null, false, 'received', [], null, null, 'advance');
        $this->toClosing();

        try {
            $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'flat_pct', 50.0, $this->rv(), $this->meta(), 1000000.00);
            self::fail('Odklad bez dokladu s osami musí ve 504 skončit validační chybou.');
        } catch (ClosingException $e) {
            self::assertSame('small_asset_document_missing', $e->errorCode);
            self::assertStringContainsString('Zaúčtuj konečnou fakturu', $e->getMessage());
        }
    }

    /** Hranice pravidla vah: dobropis 40 % faktur (nad třetinou) → dělí se částkami v měně dokladu. */
    public function testFlatPctCreditNoteAboveThirdSplitsByDocumentAmounts(): void
    {
        [$a, $b] = $this->mixedCurrencyBase(-40000.00);
        // Měna dokladu: A 75 000 − 40 000 = 35 000, B 1 000 EUR; báze 36 000 → odklad 18 000.
        self::assertEqualsWithDelta(17500.00, $a, 0.011);
        self::assertEqualsWithDelta(500.00, $b, 0.011);
    }

    /** Hranice pravidla vah: dobropis 20 % faktur (pod třetinou) → váhy v Kč zůstávají. */
    public function testFlatPctCreditNoteBelowThirdKeepsCzkWeights(): void
    {
        [$a, $b] = $this->mixedCurrencyBase(-20000.00);
        // Kč: A 55 000, B 25 000; báze 56 000 → odklad 28 000 dělený 55 : 25.
        self::assertEqualsWithDelta(19250.00, $a, 0.011);
        self::assertEqualsWithDelta(8750.00, $b, 0.011);
    }

    /** Těsně pod hranicí: dobropis 30 % faktur → váhy v Kč zůstávají. */
    public function testFlatPctCreditNoteJustBelowThirdKeepsCzkWeights(): void
    {
        [$a, $b] = $this->mixedCurrencyBase(-30000.00);
        // Kč: A 45 000, B 25 000; báze 46 000 → odklad 23 000 dělený 45 : 25.
        self::assertEqualsWithDelta(14785.71, $a, 0.011);
        self::assertEqualsWithDelta(8214.29, $b, 0.011);
    }

    /** Těsně nad hranicí: dobropis 36 % faktur → dělí se částkami v měně dokladu. */
    public function testFlatPctCreditNoteJustAboveThirdSplitsByDocumentAmounts(): void
    {
        [$a, $b] = $this->mixedCurrencyBase(-36000.00);
        // Měna dokladu: A 39 000, B 1 000 EUR; báze 40 000 → odklad 20 000.
        self::assertEqualsWithDelta(19500.00, $a, 0.011);
        self::assertEqualsWithDelta(500.00, $b, 0.011);
    }

    /** Rozpis jen ze zálohy, ale karta z pokladního dokladu s osami: odklad se dělí podle karty. */
    public function testFlatPctWithOnlyAdvanceInBaseSplitsByCardDocument(): void
    {
        $this->cashSmallAsset();
        $this->purchase('DM504-29Z', 36500.00, null, false, 'received', [], null, null, 'advance');
        $this->toClosing();

        $run = $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'flat_pct', 50.0, $this->rv(), $this->meta(), 1000000.00);

        $this->assertCarriesAxes((int) $run['entry_id'], '501', 'credit', (float) $run['total'], $this->axes);
    }

    /**
     * Záloha drží bázi kladnou, dobropis (osa B) převáží fakturu (osa A): dělení by obrátilo
     * znaménka řádků. Ve 504 srozumitelná 422, ne MD 501 na ose A.
     */
    public function testFlatPctCreditNotesOutweighingInvoicesAreRejectedIn504(): void
    {
        $this->creditNotesOutweighInvoices();

        try {
            $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'flat_pct', 50.0, $this->rv(), $this->meta(), 1000000.00);
            self::fail('Dělení s obrácenými znaménky musí ve 504 skončit validační chybou.');
        } catch (ClosingException $e) {
            self::assertSame('small_asset_document_missing', $e->errorCode);
            self::assertStringContainsString('převáží', $e->getMessage());
        }
    }

    /** Totéž mimo 504 (rok před přechodem): jeden řádek D 501 bez dimenzí, jako před dimenzemi. */
    public function testFlatPctCreditNotesOutweighingInvoicesStayUndividedOutside504(): void
    {
        $this->db->pdo()->prepare('UPDATE supplier_accounting_frameworks SET effective_from = ? WHERE supplier_id = ?')
            ->execute([(self::YEAR + 1) . '-01-01', $this->supplierId]);
        $this->creditNotesOutweighInvoices();

        $run = $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'flat_pct', 50.0, $this->rv(), $this->meta(), 1000000.00);

        $lines = $this->linesOn((int) $run['entry_id'], '501');
        self::assertCount(1, $lines);
        self::assertSame('credit', $lines[0]['side']);
        self::assertEqualsWithDelta(4000.00, (float) $lines[0]['amount'], 0.001);
        self::assertSame([], (new DimensionAssignmentRepository($this->db))->entryLineDimensions($this->supplierId, (int) $run['entry_id'])[(int) $lines[0]['id']] ?? []);
    }

    /**
     * Záloha v bázi, faktura 1 000 EUR po 25 Kč (osa A), dobropis −10 000 Kč (osa B): v měně
     * dokladu dobropis převáží, v Kč ne. Dělí se podle vah v Kč, krok nesmí odmítnout.
     */
    public function testFlatPctKeepsCzkWeightsWhenOnlyDocumentAmountsFlipSign(): void
    {
        $eurId = $this->eurId();
        $other = $this->axes;
        $other[$this->activityKindTypeId] = $this->activityKindValue('SUB2');
        $this->purchase('DM504-31Z', 10000.00, null, false, 'received', [], null, null, 'advance');
        $this->purchase('DM504-31E', 1000.00, null, true, 'received', [], $eurId, 25.0);
        $this->purchase('DM504-31D', -10000.00, $other);
        $this->toClosing();

        $run = $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'flat_pct', 50.0, $this->rv(), $this->meta(), 1000000.00);

        // Báze 10 000 + 1 000 − 10 000 = 1 000 → odklad 500; Kč: A 25 000, B −10 000.
        self::assertEqualsWithDelta(500.00, (float) $run['total'], 0.001);
        $signed = $this->signedByActivityKind((int) $run['entry_id']);
        self::assertEqualsWithDelta(833.33, $signed[$this->axes[$this->activityKindTypeId]] ?? 0.0, 0.011);
        self::assertEqualsWithDelta(-333.33, $signed[$other[$this->activityKindTypeId]] ?? 0.0, 0.011);
    }

    /** Záloha 10 000, faktura 1 000 (osa A), dobropis −3 000 (osa B): báze 8 000, součet vah −2 000. */
    private function creditNotesOutweighInvoices(): void
    {
        $other = $this->axes;
        $other[$this->activityKindTypeId] = $this->activityKindValue('SUB2');
        $this->purchase('DM504-30Z', 10000.00, null, false, 'received', [], null, null, 'advance');
        $this->smallAssetPurchase('DM504-30', 1000.00);
        $this->purchase('DM504-30D', -3000.00, $other);
        $this->toClosing();
    }

    /** Součet vah v Kč opačného znaménka než báze (+1 000 Kč, −200 EUR): dělí se částkami v měně dokladu. */
    public function testFlatPctNegativeCzkWeightSumSplitsByDocumentAmounts(): void
    {
        $eurId = $this->eurId();
        $other = $this->axes;
        $other[$this->activityKindTypeId] = $this->activityKindValue('SUB2');
        $this->smallAssetPurchase('DM504-26', 1000.00);
        $this->purchase('DM504-26D', -200.00, $other, true, 'received', [], $eurId, 25.0);
        $this->toClosing();

        $run = $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'flat_pct', 50.0, $this->rv(), $this->meta(), 1000000.00);

        self::assertEqualsWithDelta(400.00, (float) $run['total'], 0.001);
        $signed = $this->signedByActivityKind((int) $run['entry_id']);
        self::assertEqualsWithDelta(500.00, $signed[$this->axes[$this->activityKindTypeId]] ?? 0.0, 0.001);
        self::assertEqualsWithDelta(-100.00, $signed[$other[$this->activityKindTypeId]] ?? 0.0, 0.001);
    }

    /**
     * Faktura 75 000 Kč (osa A), faktura 1 000 EUR po 25 Kč (osa B), dobropis v Kč (osa A); paušál 50 %.
     *
     * @return array{0:float,1:float} saldo D 501 na ose A a B
     */
    private function mixedCurrencyBase(float $creditNote): array
    {
        $eurId = $this->eurId();
        $other = $this->axes;
        $other[$this->activityKindTypeId] = $this->activityKindValue('SUB2');
        $this->smallAssetPurchase('DM504-24', 75000.00);
        $this->purchase('DM504-24E', 1000.00, $other, true, 'received', [], $eurId, 25.0);
        $this->purchase('DM504-24D', $creditNote);
        $this->toClosing();
        $run = $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'flat_pct', 50.0, $this->rv(), $this->meta(), 1000000.00);
        $signed = $this->signedByActivityKind((int) $run['entry_id']);
        return [$signed[$this->axes[$this->activityKindTypeId]] ?? 0.0, $signed[$other[$this->activityKindTypeId]] ?? 0.0];
    }

    private function eurId(): int
    {
        $eurId = (int) ($this->db->pdo()->query("SELECT id FROM currencies WHERE code = 'EUR' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        if ($eurId === 0) {
            self::markTestSkipped('Chybí měna EUR.');
        }
        return $eurId;
    }

    /** @return array<int,float> hodnota druhu činnosti => saldo 501 (Dal kladně, MD záporně) */
    private function signedByActivityKind(int $entryId): array
    {
        $dims = (new DimensionAssignmentRepository($this->db))->entryLineDimensions($this->supplierId, $entryId);
        $out = [];
        foreach ($this->linesOn($entryId, self::DM) as $line) {
            $kind = (int) ($dims[(int) $line['id']][$this->activityKindTypeId] ?? 0);
            $out[$kind] = round(($out[$kind] ?? 0.0) + ($line['side'] === 'credit' ? 1 : -1) * (float) $line['amount'], 2);
        }
        return $out;
    }

    /** Dvě stejnojmenné karty bez dokladu: hláška jmenuje obě, odliší je inventárním číslem nebo id. */
    public function testSameNamedCardsAreBothListed(): void
    {
        foreach (['INV-1', null] as $inventory) {
            $this->smallAssets->insert($this->supplierId, [
                'name' => 'Notebook', 'acquisition_date' => self::YEAR . '-07-01', 'price' => 36500.00, 'quantity' => 1,
                'unit_price' => 36500.00, 'status' => 'in_use', 'inventory_number' => $inventory,
            ], $this->userId);
        }
        $this->toClosing();

        try {
            $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'pro_rata', null, $this->rv(), $this->meta());
            self::fail('Karty bez dokladu musí ve 504 skončit validační chybou.');
        } catch (ClosingException $e) {
            self::assertSame(2, substr_count($e->getMessage(), 'karta „Notebook“'), $e->getMessage());
            self::assertStringContainsString('karta „Notebook“ (INV-1)', $e->getMessage());
        }
    }

    /**
     * Váhy v Kč se vynulují (faktura 1 000 Kč, dobropis 40 EUR po 25 Kč na jiné ose): paušál se
     * dělí podle částek v měně dokladu a řádky dál nesou osy.
     */
    public function testFlatPctWithZeroCzkWeightsSplitsByDocumentAmounts(): void
    {
        $eurId = (int) ($this->db->pdo()->query("SELECT id FROM currencies WHERE code = 'EUR' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        if ($eurId === 0) {
            self::markTestSkipped('Chybí měna EUR.');
        }
        $other = $this->axes;
        $other[$this->activityKindTypeId] = $this->activityKindValue('SUB2');
        $this->smallAssetPurchase('DM504-19', 1000.00);
        $this->purchase('DM504-19D', -40.00, $other, true, 'received', [], $eurId, 25.0);
        $this->toClosing();

        $run = $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'flat_pct', 50.0, $this->rv(), $this->meta(), 1000000.00);

        $entryId = (int) $run['entry_id'];
        self::assertEqualsWithDelta(480.00, (float) $run['total'], 0.001);
        $dims = (new DimensionAssignmentRepository($this->db))->entryLineDimensions($this->supplierId, $entryId);
        $bySide = [];
        foreach ($this->linesOn($entryId, self::DM) as $line) {
            $bySide[$line['side']][] = [(int) ($dims[(int) $line['id']][$this->activityKindTypeId] ?? 0), round((float) $line['amount'], 2)];
        }
        self::assertSame([[$this->axes[$this->activityKindTypeId], 500.0]], $bySide['credit'] ?? []);
        self::assertSame([[$other[$this->activityKindTypeId], 20.0]], $bySide['debit'] ?? []);
    }

    /**
     * Doklad zaúčtovaný před přechodem na 504 nese jen vlastní dimenzi, osu činnosti ne:
     * předkontrola to pozná a jmenuje kartu, místo obecné chyby zaúčtování.
     */
    public function testDocumentWithoutActivityAxisIsRejectedWithPromptToPostIt(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO dimension_types (supplier_id, code, name, kind, sort_order) VALUES (?, 'STR', 'Středisko', 'custom', 1)")->execute([$this->supplierId]);
        $typeId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO dimension_values (type_id, supplier_id, code, name) VALUES (?, ?, 'S1', 'Syntetické středisko')")->execute([$typeId, $this->supplierId]);
        $valueId = (int) $pdo->lastInsertId();
        $framework = $pdo->prepare('UPDATE supplier_accounting_frameworks SET effective_from = ? WHERE supplier_id = ?');
        $framework->execute([(self::YEAR + 1) . '-01-01', $this->supplierId]);
        $this->smallAssetPurchase('DM504-11', 36500.00, [$typeId => $valueId]);
        $framework->execute([self::YEAR . '-01-01', $this->supplierId]);
        $this->toClosing();

        try {
            $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'pro_rata', null, $this->rv(), $this->meta());
            self::fail('Doklad bez osy činnosti musí ve 504 skončit validační chybou.');
        } catch (ClosingException $e) {
            self::assertSame('small_asset_document_missing', $e->errorCode);
            self::assertStringContainsString('Notebook DM504-11', $e->getMessage());
            self::assertStringContainsString('Zaúčtuj doklad', $e->getMessage());
            self::assertStringNotContainsString('Doplň u karty vazbu', $e->getMessage());
        }
    }

    /** pro_rata: karta navázaná na nezaúčtovanou fakturu (koncept) → výzva zaúčtovat doklad, ne doplnit vazbu. */
    public function testProRataCardOfDraftInvoiceIsRejectedWithPromptToPostIt(): void
    {
        [$piId, $itemId] = $this->purchase('DM504-17', 36500.00, null, false, 'draft');
        $this->smallAssets->insert($this->supplierId, [
            'name' => 'Notebook z konceptu', 'acquisition_date' => self::YEAR . '-07-01', 'price' => 36500.00, 'quantity' => 1,
            'unit_price' => 36500.00, 'status' => 'in_use', 'purchase_invoice_id' => $piId, 'purchase_invoice_item_id' => $itemId,
        ], $this->userId);
        $this->toClosing();

        try {
            $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'pro_rata', null, $this->rv(), $this->meta());
            self::fail('Karta nezaúčtované faktury musí ve 504 skončit validační chybou.');
        } catch (ClosingException $e) {
            self::assertSame('small_asset_document_missing', $e->errorCode);
            self::assertStringContainsString('(doklad není zaúčtovaný, je to záloha, nebo jeho náklad osy nenese): karta „Notebook z konceptu“', $e->getMessage());
            self::assertStringNotContainsString('Doplň u karty vazbu', $e->getMessage());
        }
    }

    /** Nezaúčtovaný koncept v bázi paušálu: hláška jmenuje číslo faktury a nemluví o kartě. */
    public function testFlatPctDraftInvoiceIsNamedByNumber(): void
    {
        $this->smallAssetPurchase('DM504-12', 36500.00);
        [$draftId] = $this->purchase('DM504-KONCEPT', 1000.00, null, false, 'draft');
        $this->toClosing();

        try {
            $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'flat_pct', 50.0, $this->rv(), $this->meta(), 1000000.00);
            self::fail('Nezaúčtovaný doklad v bázi paušálu musí ve 504 skončit validační chybou.');
        } catch (ClosingException $e) {
            self::assertSame('small_asset_document_missing', $e->errorCode);
            self::assertStringContainsString('přijatá faktura DM504-KONCEPT', $e->getMessage());
            self::assertStringNotContainsString('#' . $draftId, $e->getMessage());
            self::assertStringNotContainsString('Doplň u karty vazbu', $e->getMessage());
            self::assertStringNotContainsString('DM504-12', $e->getMessage());
        }
    }

    /** Opakovaný běh kroku přepíše zápis: řádek 501 s osami zůstane jeden. */
    public function testRerunKeepsSingleLineWithAxes(): void
    {
        $this->smallAssetPurchase('DM504-13', 36500.00);
        $this->toClosing();

        $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'pro_rata', null, $this->rv(), $this->meta());
        $run = $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'pro_rata', null, $this->rv(), $this->meta());

        $this->assertCarriesAxes((int) $run['entry_id'], self::DM, 'credit', 18100.00, $this->axes);
    }

    /**
     * Paušál nedohledává účty položek po fakturách (trasa zaúčtování, několik dotazů na fakturu
     * pod zámkem období): 20 dalších faktur s jedinou kombinací os nepřidá dotaz na fakturu.
     */
    public function testFlatPctQueryCountDoesNotGrowWithInvoices(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            $this->smallAssetPurchase('DM504-Q' . $i, 1000.00);
        }
        $this->toClosing();
        $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'flat_pct', 50.0, $this->rv(), $this->meta(), 1000000.00);
        $few = $this->queriesOfRun();
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'open' WHERE id = ?")->execute([$this->periodId]);
        for ($i = 3; $i < 23; ++$i) {
            $this->smallAssetPurchase('DM504-Q' . $i, 1000.00);
        }
        $this->toClosing();
        $many = $this->queriesOfRun();

        self::assertLessThan(10, $many - $few, 'Počet dotazů kroku nesmí růst s počtem faktur (' . $few . ' → ' . $many . ').');
    }

    private function queriesOfRun(): int
    {
        $rv = $this->rv();
        $meta = $this->meta();
        $questions = fn (): int => (int) $this->db->pdo()->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(\PDO::FETCH_NUM)[1];
        $before = $questions();
        $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'flat_pct', 50.0, $rv, $meta, 1000000.00);
        return $questions() - $before;
    }

    /** Karta bez vazby na doklad nemá odkud vzít osy: ve 504 validační chyba s výzvou doplnit vazbu. */
    public function testCardWithoutDocumentIsRejectedWithPromptToLinkIt(): void
    {
        $this->smallAssets->insert($this->supplierId, [
            'name' => 'Ruční karta bez dokladu',
            'acquisition_date' => self::YEAR . '-07-01',
            'price' => 36500.00,
            'quantity' => 1,
            'unit_price' => 36500.00,
            'status' => 'in_use',
        ], $this->userId);
        $this->toClosing();

        try {
            $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'pro_rata', null, $this->rv(), $this->meta());
            self::fail('Karta bez dokladu musí ve 504 skončit validační chybou.');
        } catch (ClosingException $e) {
            self::assertSame('small_asset_document_missing', $e->errorCode);
            self::assertSame(422, $e->httpStatus);
            self::assertStringContainsString('Doplň u karty vazbu na přijatou fakturu nebo pokladní doklad: karta „Ruční karta bez dokladu“', $e->getMessage());
        }
        self::assertNull($this->journal->findBySource($this->supplierId, 'small_asset_accrual', ClosingSourceId::smallAssetAccrual($this->periodId)));
    }

    /**
     * Položka drobného majetku přepsaná na 518 a materiál na 501 s jinou činností: osy patří
     * nákladu položky (518), ne jediné kombinaci na 501.
     */
    public function testItemOverriddenToOtherExpenseAccountTakesItsAxes(): void
    {
        $other = $this->axes;
        $other[$this->activityKindTypeId] = $this->activityKindValue('SUB2');
        [$piId, $itemId] = $this->purchase('DM504-18', 36500.00, null, false);
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE purchase_invoice_items SET expense_account_code = '518' WHERE id = ?")->execute([$itemId]);
        $expense = (string) $this->posting->purchaseItemExpenseAccounts($this->supplierId, $piId)[$itemId];
        self::assertStringStartsWith('518', $expense, 'Sanity: položka vede na 518.');
        $date = self::YEAR . '-07-01';
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $piId, [
            ['account_code' => $expense, 'side' => 'debit', 'amount' => 36500.00, 'dimensions' => $this->axes, 'dimension_origins' => $this->origins()],
            ['account_code' => '501', 'side' => 'debit', 'amount' => 1000.00, 'dimensions' => $other, 'dimension_origins' => $this->origins()],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 37500.00],
        ], ['entry_date' => $date, 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        $this->smallAssets->insert($this->supplierId, [
            'name' => 'Notebook na 518', 'acquisition_date' => $date, 'price' => 36500.00, 'quantity' => 1,
            'unit_price' => 36500.00, 'status' => 'in_use', 'purchase_invoice_id' => $piId, 'purchase_invoice_item_id' => $itemId,
        ], $this->userId);
        $this->toClosing();

        $run = $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'pro_rata', null, $this->rv(), $this->meta());

        $this->assertCarriesAxes((int) $run['entry_id'], '501', 'credit', 18100.00, $this->axes);
    }

    /** Firma 504 bez katalogu os (nezaložen): zaúčtování osy nevyžaduje, krok nesmí hlásit chybějící osu. */
    public function testWithoutActivityCatalogTheStepDoesNotDemandAxes(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM dimension_account_rules WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE v FROM dimension_values v JOIN dimension_types t ON t.id = v.type_id WHERE t.supplier_id = ? AND t.system_key IS NOT NULL')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM dimension_types WHERE supplier_id = ? AND system_key IS NOT NULL')->execute([$this->supplierId]);
        $this->smallAssets->insert($this->supplierId, [
            'name' => 'Ruční karta', 'acquisition_date' => self::YEAR . '-07-01', 'price' => 36500.00, 'quantity' => 1,
            'unit_price' => 36500.00, 'status' => 'in_use',
        ], $this->userId);
        $this->toClosing();

        $run = $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'pro_rata', null, $this->rv(), $this->meta());

        self::assertEqualsWithDelta(18100.00, $this->sideTotal((int) $run['entry_id'], '501', 'credit'), 0.001);
    }

    /** Hodnota osy uzavřená po zaúčtování faktury: odklad ji převezme z řádku dokladu (i v jednoduché formě). */
    #[DataProvider('entryFormProvider')]
    public function testDeferralCarriesValueClosedAfterDocumentPosting(bool $single): void
    {
        $this->singleForm($single);
        $this->smallAssetPurchase('DM504-7', 36500.00);
        $this->closeActivityKind();
        $this->toClosing();

        $run = $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'pro_rata', null, $this->rv(), $this->meta());

        $this->assertCarriesAxes((int) $run['entry_id'], self::DM, 'credit', 18100.00, $this->axes);
    }

    /** Rozpuštění v N+1 zrcadlí odklad i s osami a původem a ve 504 jde zaúčtovat i po uzavření hodnoty (i v jednoduché formě). */
    #[DataProvider('entryFormProvider')]
    public function testReleaseMirrorsAxesAndPostsInNextPeriod(bool $single): void
    {
        $this->singleForm($single);
        $this->smallAssetPurchase('DM504-8', 36500.00);
        $this->toClosing();
        $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'pro_rata', null, $this->rv(), $this->meta());
        $this->closeActivityKind();
        $this->periods->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');

        $defer = $this->invokePrivate('findEntryWithLines', 'small_asset_accrual', ClosingSourceId::smallAssetAccrual($this->periodId));
        $lines = $this->invokePrivate('smallAssetReleaseEntryLines', $defer, (self::YEAR + 1) . '-01-01');
        $entryId = $this->posting->postDocument($this->supplierId, 'small_asset_accrual', ClosingSourceId::smallAssetAccrualRelease($this->periodId), $lines, [
            'entry_date' => (self::YEAR + 1) . '-01-01', 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId,
        ]);

        $this->assertCarriesAxes($entryId, self::DM, 'debit', 18100.00, $this->axes);
        self::assertEqualsWithDelta(18100.00, $this->sideTotal($entryId, '381', 'credit'), 0.001);
    }

    /** @return array<string,array{bool}> */
    public static function entryFormProvider(): array
    {
        return ['složená forma' => [false], 'jednoduchá forma' => [true]];
    }

    private function singleForm(bool $single): void
    {
        if ($single) {
            Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\EntryForm\EntryFormService::class)
                ->schedule($this->supplierId, self::YEAR . '-01-01', \MyInvoice\Service\Accounting\EntryForm\EntryForm::Single, $this->userId);
        }
    }

    /** Odklad s osami se rozpouští zrcadlem i po změně karet; podle dokladů karet se nepřepočítává. */
    public function testReleaseOfDeferralWithAxesIgnoresLaterCardChanges(): void
    {
        $this->smallAssetPurchase('DM504-16', 36500.00);
        $this->toClosing();
        $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'pro_rata', null, $this->rv(), $this->meta());
        $this->db->pdo()->prepare('DELETE FROM small_assets WHERE supplier_id = ?')->execute([$this->supplierId]);
        $this->periods->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');

        $defer = $this->invokePrivate('findEntryWithLines', 'small_asset_accrual', ClosingSourceId::smallAssetAccrual($this->periodId));
        $lines = $this->invokePrivate('smallAssetReleaseLines', $defer, (self::YEAR + 1) . '-01-01');
        $entryId = $this->posting->postDocument($this->supplierId, 'small_asset_accrual', ClosingSourceId::smallAssetAccrualRelease($this->periodId), $lines, [
            'entry_date' => (self::YEAR + 1) . '-01-01', 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId,
        ]);

        $this->assertCarriesAxes($entryId, self::DM, 'debit', 18100.00, $this->axes);
    }

    /**
     * Přijatá faktura s řádkem drobného majetku zaúčtovaná na 501 s osami a karta k ní.
     *
     * @param array<int,int>|null $axes null = osy hlavní činnosti
     */
    private function smallAssetPurchase(string $number, float $net, ?array $axes = null): void
    {
        [$piId, $itemId] = $this->purchase($number, $net, $axes);
        $this->smallAssets->insert($this->supplierId, [
            'name' => 'Notebook ' . $number,
            'acquisition_date' => self::YEAR . '-07-01',
            'price' => $net,
            'quantity' => 1,
            'unit_price' => $net,
            'status' => 'in_use',
            'purchase_invoice_id' => $piId,
            'purchase_invoice_item_id' => $itemId,
        ], $this->userId);
    }

    /**
     * Přijatá faktura s jedinou položkou drobného majetku. Záporná částka je opravný doklad
     * (náklad na straně Dal). Bez `$post` zůstane nezaúčtovaná (koncept).
     *
     * @param array<int,int>|null $axes null = osy hlavní činnosti
     * @param list<array<string,mixed>> $extraLines další řádky zápisu (vyrovnané samy o sobě)
     * @return array{0:int,1:int} id faktury, id položky
     */
    private function purchase(string $number, float $net, ?array $axes = null, bool $post = true, string $status = 'received', array $extraLines = [], ?int $currencyId = null, ?float $rate = null, string $kind = 'invoice'): array
    {
        $pdo = $this->db->pdo();
        $date = self::YEAR . '-07-01';
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id, is_customer, is_vendor)
             VALUES (?, "Syntetický dodavatel", "Test 1", "Praha", "11000", ?, "vendor-504@example.test", ?, 0, 1)'
        )->execute([$this->supplierId, $this->czId, $this->currencyId]);
        $vendorId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind,
                 vat_deduction, issue_date, tax_date, due_date, received_at, currency_id, exchange_rate, reverse_charge,
                 is_fixed_asset, total_without_vat, total_vat, total_with_vat, status, vat_classification_code, created_by)
             VALUES (?, ?, ?, "{}", ?, "none", ?, ?, ?, ?, ?, ?, 0, 0, ?, 0, ?, ?, "40", ?)'
        )->execute([$this->supplierId, $vendorId, $number, $kind, $date, $date, $date, $date, $currencyId ?? $this->currencyId, $rate, $net, $net, $status, $this->userId]);
        $piId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index, expense_kind)
             VALUES (?, 'Notebook', 1, 'ks', ?, ?, 0, ?, 0, ?, 0, 'small_asset')"
        )->execute([$piId, $net, $this->vatRateId, $net, $net]);
        $itemId = (int) $pdo->lastInsertId();
        if ($post) {
            // Náklad na účet, který položce dává zaúčtování faktury (drobný majetek bez odpočtu DPH).
            $expense = (string) $this->posting->purchaseItemExpenseAccounts($this->supplierId, $piId)[$itemId];
            $axes ??= $this->axes;
            $czk = round(abs($net) * ($rate ?? 1.0), 2);
            $this->posting->postDocument($this->supplierId, 'purchase_invoice', $piId, [
                ['account_code' => $expense, 'side' => $net > 0 ? 'debit' : 'credit', 'amount' => $czk, 'dimensions' => $axes, 'dimension_origins' => $this->origins($axes)],
                ['account_code' => '321', 'side' => $net > 0 ? 'credit' : 'debit', 'amount' => $czk],
                ...$extraLines,
            ], ['entry_date' => $date, 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        }
        return [$piId, $itemId];
    }

    /**
     * Pokladní doklad: 501 s osami hlavní činnosti, 518 s jinými osami, a karta k němu.
     * 'reposted' = nejdřív zaúčtovaný s jinou činností na 501, stornovaný a zaúčtovaný znovu;
     * 'draft' = zápis jen jako koncept.
     */
    private function cashSmallAsset(string $state = 'posted'): void
    {
        $other = $this->axes;
        $other[$this->activityKindTypeId] = $this->activityKindValue('SUB2');
        $pdo = $this->db->pdo();
        $date = self::YEAR . '-07-01';
        $pdo->prepare("INSERT INTO cash_registers (supplier_id, name, account_code) VALUES (?, 'Syntetická pokladna', '211')")->execute([$this->supplierId]);
        $registerId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO cash_documents (supplier_id, register_id, doc_type, purpose, issue_date, description, vat_mode, total_amount, currency_code, fx_rate, status, created_by)
             VALUES (?, ?, 'out', 'purchase', ?, 'Syntetický nákup', 'none', 37500, 'CZK', 1, 'posted', ?)"
        )->execute([$this->supplierId, $registerId, $date, $this->userId]);
        $cashId = (int) $pdo->lastInsertId();
        $opts = ['entry_date' => $date, 'posted' => $state !== 'draft', 'posted_by' => $this->userId, 'user_id' => $this->userId];
        if ($state === 'reposted') {
            $first = $this->posting->postDocument($this->supplierId, 'cash', $cashId, [
                ['account_code' => '501', 'side' => 'debit', 'amount' => 36500.00, 'dimensions' => $other, 'dimension_origins' => $this->origins()],
                ['account_code' => '211', 'side' => 'credit', 'amount' => 36500.00],
            ], $opts);
            $this->posting->reverse($this->supplierId, $first);
        }
        $this->posting->postDocument($this->supplierId, 'cash', $cashId, [
            ['account_code' => '501', 'side' => 'debit', 'amount' => 36500.00, 'dimensions' => $this->axes, 'dimension_origins' => $this->origins()],
            ['account_code' => '518', 'side' => 'debit', 'amount' => 1000.00, 'dimensions' => $other, 'dimension_origins' => $this->origins()],
            ['account_code' => '211', 'side' => 'credit', 'amount' => 37500.00],
        ], $opts);
        $this->smallAssets->insert($this->supplierId, [
            'name' => 'Notebook z pokladny',
            'acquisition_date' => $date,
            'price' => 36500.00,
            'quantity' => 1,
            'unit_price' => 36500.00,
            'status' => 'in_use',
            'cash_document_id' => $cashId,
        ], $this->userId);
    }

    private function activityKindValue(string $code): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO dimension_values (type_id, supplier_id, code, name) VALUES (?, ?, ?, ?)')
            ->execute([$this->activityKindTypeId, $this->supplierId, $code, 'Syntetická činnost ' . $code]);
        return (int) $pdo->lastInsertId();
    }

    private function closeActivityKind(): void
    {
        $this->db->pdo()->prepare("UPDATE dimension_values SET is_active = 0 WHERE supplier_id = ? AND code = 'SUBS'")->execute([$this->supplierId]);
    }

    /**
     * Řádek zápisu na účtu nese osy i jejich původ z řádku dokladu (daňové posouzení NNO čte obojí).
     *
     * @param array<int,int> $axes
     */
    private function assertCarriesAxes(int $entryId, string $accountCode, string $side, float $amount, array $axes): void
    {
        $lines = $this->linesOn($entryId, $accountCode);
        self::assertCount(1, $lines);
        self::assertSame($side, $lines[0]['side']);
        self::assertEqualsWithDelta($amount, (float) $lines[0]['amount'], 0.001);
        $repo = new DimensionAssignmentRepository($this->db);
        $lineId = (int) $lines[0]['id'];
        $actual = $repo->entryLineDimensions($this->supplierId, $entryId)[$lineId] ?? [];
        ksort($actual);
        ksort($axes);
        self::assertSame($axes, $actual, 'Zápis musí nést osy činnosti řádku 501 dokladu.');
        $origins = $repo->entryLineOrigins($this->supplierId, $entryId)[$lineId] ?? [];
        ksort($origins);
        self::assertSame($this->origins(), $origins, 'Zápis musí nést i původ přiřazení os.');
    }

    /** @return array<int,float> hodnota druhu činnosti => částka D 501 */
    private function creditByActivityKind(int $entryId, string $account = self::DM): array
    {
        $dims = (new DimensionAssignmentRepository($this->db))->entryLineDimensions($this->supplierId, $entryId);
        $out = [];
        foreach ($this->linesOn($entryId, $account) as $line) {
            self::assertSame('credit', $line['side']);
            $kind = (int) ($dims[(int) $line['id']][$this->activityKindTypeId] ?? 0);
            $out[$kind] = round(($out[$kind] ?? 0.0) + (float) $line['amount'], 2);
        }
        return $out;
    }

    private function sideTotal(int $entryId, string $accountCode, string $side): float
    {
        $total = 0.0;
        foreach ($this->linesOn($entryId, $accountCode) as $line) {
            if ($line['side'] === $side) {
                $total += (float) $line['amount'];
            }
        }
        return round($total, 2);
    }

    /** @return list<array<string,mixed>> */
    private function linesOn(int $entryId, string $accountCode): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT l.id, l.side, l.amount
               FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND l.entry_id = ? AND a.account_code = ?'
        );
        $stmt->execute([$this->supplierId, $entryId, $accountCode]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    private function invokePrivate(string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod($this->closing, $method))->invoke($this->closing, $this->supplierId, ...$args);
    }

    private function toClosing(): void
    {
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")->execute([$this->periodId]);
    }

    /**
     * @param array<int,int>|null $axes null = osy hlavní činnosti
     * @return array<int,array{origin:string,ref:?string}>
     */
    private function origins(?array $axes = null): array
    {
        $out = [];
        foreach (array_keys($axes ?? $this->axes) as $typeId) {
            $out[$typeId] = ['origin' => 'explicit', 'ref' => null];
        }
        ksort($out);
        return $out;
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
}
