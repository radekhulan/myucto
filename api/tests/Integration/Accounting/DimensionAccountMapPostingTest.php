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
use MyInvoice\Service\Accounting\Dimension\DimensionAccountMapService;
use MyInvoice\Service\Accounting\Dimension\DimensionException;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\PostingService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Účtotvorná dimenze (PLAN-DIMENZE-UCTOVANI F2): hodnota střediska určí analytiku
 * nákladu. FVE → 518.100, Kancelář → 518.200, rozpad 60/40 → dva řádky na haléř,
 * validace mapy (daňová uznatelnost, syntetika), jediný účtotvorný typ, změna dimenze
 * zaúčtovaného dokladu → needs_repost místo tichého přerazítkování.
 */
#[Group('integration')]
final class DimensionAccountMapPostingTest extends TestCase
{
    private const YEAR = 2091;

    private Connection $db;
    private PostingService $posting;
    private JournalEntryRepository $journal;
    private DimensionService $dimensions;
    private DimensionAccountMapService $map;
    private DimensionAssignmentRepository $assignments;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $centerType = 0;
    private int $projectType = 0;
    private int $fve = 0;
    private int $office = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->posting = $container->get(PostingService::class);
        $this->journal = $container->get(JournalEntryRepository::class);
        $this->dimensions = $container->get(DimensionService::class);
        $this->map = $container->get(DimensionAccountMapService::class);
        $this->assignments = $container->get(DimensionAssignmentRepository::class);

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
        $container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $container->get(AccountingPeriodRepository::class)
            ->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry', supplier_group_id = NULL WHERE id = ?")
            ->execute([$this->supplierId]);
        $this->dimensions->setEnabled($this->supplierId, true);
        $types = $this->dimensions->ensureDefaultTypes($this->supplierId, ['projekt', 'stredisko']);
        $this->centerType = $types['cost_center'];
        $this->projectType = $types['project'];
        $this->fve = $this->value($this->centerType, 'F2-FVE');
        $this->office = $this->value($this->centerType, 'F2-KAN');
        $this->analytic('518.100', '518');
        $this->analytic('518.200', '518');
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testWithoutDrivingTypeExpenseStaysOnSynthetic(): void
    {
        $purchase = $this->purchase('F2-NONE', [[1_000.00, 210.00]]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->centerType => $this->fve], []);

        self::assertSame(['518' => 100_000], $this->expenseCents($this->postPurchase($purchase)));
    }

    public function testDimensionValueRoutesExpenseToMappedAnalytic(): void
    {
        $this->enableDriving();
        $purchase = $this->purchase('F2-FVE', [[1_000.00, 210.00]]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->centerType => $this->fve], []);

        $entryId = $this->postPurchase($purchase);

        self::assertSame(['518.100' => 100_000], $this->expenseCents($entryId), 'FVE → 518.100.');
        foreach ($this->journal->linesForEntry($entryId, $this->supplierId) as $line) {
            if (str_starts_with($this->code((int) $line['account_id']), '3')) {
                self::assertNotSame('518.100', $this->code((int) $line['account_id']));
            }
        }
    }

    public function testValueWithoutMappingStaysOnSynthetic(): void
    {
        $this->enableDriving();
        $other = $this->value($this->centerType, 'F2-OTHER');
        $purchase = $this->purchase('F2-OTHER', [[500.00, 105.00]]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->centerType => $other], []);

        self::assertSame(['518' => 50_000], $this->expenseCents($this->postPurchase($purchase)));
    }

    public function testSplitSixtyFortyBecomesTwoAnalyticLinesToTheHaler(): void
    {
        $this->enableDriving();
        $project = $this->value($this->projectType, 'F2-PRJ');
        $purchase = $this->purchase('F2-SPLIT', [[1_000.01, 210.00]]);
        $this->dimensions->saveDocument(
            $this->supplierId,
            'purchase_invoice',
            $purchase,
            [$this->projectType => $project],
            [],
            false,
            [0 => [$this->centerType => [
                ['value_id' => $this->fve, 'share' => 0.6],
                ['value_id' => $this->office, 'share' => 0.4],
            ]]],
        );

        $entryId = $this->postPurchase($purchase);

        self::assertSame(['518.100' => 60_001, '518.200' => 40_000], $this->expenseCents($entryId), 'Rozpad 60/40 na haléř.');
        $dims = $this->assignments->entryLineDimensions($this->supplierId, $entryId);
        $splits = $this->assignments->entryLineSplits($this->supplierId, $entryId);
        foreach ($this->journal->linesForEntry($entryId, $this->supplierId) as $line) {
            $code = $this->code((int) $line['account_id']);
            if (!str_starts_with($code, '518')) {
                continue;
            }
            $expected = $code === '518.100' ? $this->fve : $this->office;
            self::assertSame(
                self::sorted([$this->centerType => $expected, $this->projectType => $project]),
                self::sorted($dims[(int) $line['id']] ?? []),
                'Díl nese jen svou hodnotu střediska a ostatní dimenze zůstávají.',
            );
            self::assertArrayNotHasKey($this->centerType, $splits[(int) $line['id']] ?? [], 'Díl už nenese rozpad střediska.');
        }

        // Přerazítkování beze změny dimenzí nic nehlásí — díly odpovídají rozpadu dokladu.
        $restamp = $this->posting->restampDimensions($this->supplierId, 'purchase_invoice', $purchase);
        self::assertFalse($restamp['needs_repost']);
        self::assertSame(0, $restamp['lines']);
    }

    /** Položky s různým střediskem: řádek se rozdělí po položkách a díly jdou na své analytiky. */
    public function testItemDimensionsRouteEachItemAndRestampKeepsThem(): void
    {
        $this->enableDriving();
        $purchase = $this->purchase('F2-ITEMS', [[700.00, 147.00], [300.00, 63.00]]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [], [
            1 => [$this->centerType => $this->fve],
            2 => [$this->centerType => $this->office],
        ]);

        self::assertSame(['518.100' => 70_000, '518.200' => 30_000], $this->expenseCents($this->postPurchase($purchase)));

        $restamp = $this->posting->restampDimensions($this->supplierId, 'purchase_invoice', $purchase);
        self::assertFalse($restamp['needs_repost'], 'Díly rozdělené po položkách na různé analytiky nejsou „nerozdělený řádek".');
        self::assertSame(0, $restamp['lines']);
    }

    public function testChangingDimensionOfPostedDocumentNeedsRepost(): void
    {
        $this->enableDriving();
        $purchase = $this->purchase('F2-CHANGE', [[800.00, 168.00]]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->centerType => $this->fve], []);
        $entryId = $this->postPurchase($purchase);

        $result = $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->centerType => $this->office], []);

        self::assertTrue($result['restamp']['needs_repost'], 'Změna účtotvorné dimenze vyžaduje přeúčtování.');
        self::assertTrue($result['restamp']['account_change']);
        foreach ($this->journal->linesForEntry($entryId, $this->supplierId) as $line) {
            if ($this->code((int) $line['account_id']) === '518.100') {
                self::assertSame(
                    $this->fve,
                    $this->assignments->entryLineDimensions($this->supplierId, $entryId)[(int) $line['id']][$this->centerType] ?? null,
                    'Řádek na 518.100 si ponechá FVE — tiché přerazítkování by nesouhlasilo s účtem.',
                );
            }
        }

        // Přeúčtování pak řádek přesune na analytiku nové hodnoty.
        self::assertSame(['518.200' => 80_000], $this->expenseCents($this->postPurchase($purchase)));
    }

    public function testChangingJournalLineDimensionThatMovesAccountIsRefused(): void
    {
        $this->enableDriving();
        $purchase = $this->purchase('F2-LINE', [[300.00, 63.00]]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->centerType => $this->fve], []);
        $entryId = $this->postPurchase($purchase);
        $lineId = 0;
        foreach ($this->journal->linesForEntry($entryId, $this->supplierId) as $line) {
            if ($this->code((int) $line['account_id']) === '518.100') {
                $lineId = (int) $line['id'];
            }
        }
        self::assertGreaterThan(0, $lineId);

        try {
            $this->dimensions->saveEntryLines($this->supplierId, $entryId, [$lineId => [$this->centerType => $this->office]]);
            self::fail('Změna střediska na řádku 518.100 musí vyžadovat přeúčtování.');
        } catch (DimensionException $e) {
            self::assertSame('account_change_needs_repost', $e->errorCode);
        }

        // Jiná dimenze téhož řádku účet nemění a projde.
        $project = $this->value($this->projectType, 'F2-LINE-P');
        self::assertSame(1, $this->dimensions->saveEntryLines($this->supplierId, $entryId, [
            $lineId => [$this->centerType => $this->fve, $this->projectType => $project],
        ]));
    }

    public function testNonDeductibleAnalyticUnderDeductibleSyntheticIsRejected(): void
    {
        $this->enableDriving(false);
        try {
            $this->map->saveForValue($this->supplierId, $this->fve, [['synthetic_code' => '518', 'analytic_code' => '518.990']], $this->userId);
            self::fail('Nedaňová analytika pod daňovou syntetikou nesmí projít.');
        } catch (DimensionException $e) {
            self::assertSame('tax_deductibility_mismatch', $e->errorCode);
        }
    }

    public function testAnalyticFromAnotherSyntheticIsRejected(): void
    {
        $this->enableDriving(false);
        $this->analytic('501.100', '501');
        $this->expectException(DimensionException::class);
        $this->map->saveForValue($this->supplierId, $this->fve, [['synthetic_code' => '518', 'analytic_code' => '501.100']], $this->userId);
    }

    public function testOnlyOneDrivingTypePerCompany(): void
    {
        $this->enableDriving(false);
        $this->expectException(DimensionException::class);
        $this->dimensions->updateType($this->supplierId, $this->projectType, ['drives_accounts' => true]);
    }

    public function testMapValidityFollowsEntryDate(): void
    {
        $this->dimensions->updateType($this->supplierId, $this->centerType, ['drives_accounts' => true]);
        $this->map->saveForValue($this->supplierId, $this->fve, [
            ['synthetic_code' => '518', 'analytic_code' => '518.100', 'valid_from' => self::YEAR . '-07-01'],
        ], $this->userId);
        $purchase = $this->purchase('F2-VALID', [[100.00, 21.00]]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->centerType => $this->fve], []);

        self::assertSame(['518' => 10_000], $this->expenseCents($this->postPurchase($purchase)), 'Mapa platná od července červnový doklad nemění.');
    }

    /**
     * Zápis z doby před mapou (518 + FVE), mapa FVE → 518.100 až potom: změna JINÉ
     * dimenze (zakázka) nemění hodnotu účtotvorného typu, takže se přerazítkuje bez
     * výzvy k přeúčtování — v otevřeném i zamčeném období a na řádku deníku.
     */
    public function testMapCreatedAfterPostingAcceptsOtherDimensionInOpenPeriod(): void
    {
        [$purchase, $entryId, $line518] = $this->postedBeforeMap();
        $project = $this->value($this->projectType, 'F2-LATE-P');
        $open = $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->centerType => $this->fve, $this->projectType => $project], []);
        self::assertFalse($open['restamp']['needs_repost'], 'Zakázka účet nemění.');
        self::assertSame($project, $this->assignments->entryLineDimensions($this->supplierId, $entryId)[$line518][$this->projectType] ?? null);
    }

    public function testMapCreatedAfterPostingAcceptsOtherDimensionInLockedPeriod(): void
    {
        [$purchase] = $this->postedBeforeMap();
        $this->db->pdo()->prepare(
            'INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)'
        )->execute([$this->supplierId, self::YEAR . '-06-30']);
        $project = $this->value($this->projectType, 'F2-LATE-P2');
        $locked = $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->centerType => $this->fve, $this->projectType => $project], []);
        self::assertTrue($locked['restamp']['locked']);
        self::assertFalse($locked['restamp']['needs_repost']);
    }

    public function testMapCreatedAfterPostingAcceptsOtherDimensionOnJournalLine(): void
    {
        [, $entryId, $line518] = $this->postedBeforeMap();
        $project = $this->value($this->projectType, 'F2-LATE-P3');
        self::assertSame(1, $this->dimensions->saveEntryLines($this->supplierId, $entryId, [
            $line518 => [$this->centerType => $this->fve, $this->projectType => $project],
        ]));
    }

    /**
     * Zápis z doby před mapou (518 + FVE), mapa FVE → 518.100 až potom. Změna JINÉ
     * dimenze nemění hodnotu účtotvorného typu a nesmí vyžadovat přeúčtování.
     *
     * @return array{0:int,1:int,2:int} doklad, zápis, řádek 518
     */
    private function postedBeforeMap(): array
    {
        $this->dimensions->updateType($this->supplierId, $this->centerType, ['drives_accounts' => true]);
        $purchase = $this->purchase('F2-LATE-MAP', [[500.00, 105.00]]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->centerType => $this->fve], []);
        $entryId = $this->postPurchase($purchase);
        self::assertSame(['518' => 50_000], $this->expenseCents($entryId));
        $this->map->saveForValue($this->supplierId, $this->fve, [['synthetic_code' => '518', 'analytic_code' => '518.100']], $this->userId);
        $line518 = 0;
        foreach ($this->journal->linesForEntry($entryId, $this->supplierId) as $line) {
            if ($this->code((int) $line['account_id']) === '518') {
                $line518 = (int) $line['id'];
            }
        }
        return [$purchase, $entryId, $line518];
    }

    /** Náhled kontace ukáže, kam zaúčtování syntetiku přesune — touž cestou jako zápis. */
    public function testPostingPreviewShowsDimensionRouting(): void
    {
        $this->enableDriving();
        $purchase = $this->purchase('F2-PREVIEW', [[400.00, 84.00]]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->centerType => $this->office], []);
        $lines = $this->posting->buildFromPurchaseInvoice($this->supplierId, $purchase);

        self::assertSame([[
            'account_code' => '518',
            'side' => 'debit',
            'amount' => 400.0,
            'targets' => [['account_code' => '518.200', 'amount' => 400.0]],
        ]], $this->posting->dimensionRoutingPreview($this->supplierId, 'purchase_invoice', $purchase, $lines, self::YEAR . '-06-15'));
    }

    /** Kontrola uzávěrky: výsledkový řádek, který zůstal na syntetice s mapou, je varování. */
    public function testClosingCheckWarnsAboutExpenseLeftOnMappedSynthetic(): void
    {
        $this->enableDriving();
        $mapped = $this->purchase('F2-CHK-OK', [[100.00, 21.00]]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $mapped, [$this->centerType => $this->fve], []);
        $this->postPurchase($mapped);
        self::assertTrue($this->unmappedCheck()['ok'], 'Řádek na analytice z mapy kontrolu nespouští.');

        $this->postPurchase($this->purchase('F2-CHK-MISSING', [[250.00, 52.50]]));
        $check = $this->unmappedCheck();
        self::assertFalse($check['ok']);
        self::assertSame(['518'], array_column($check['value']['findings'], 'account_code'));
        self::assertSame(250.0, $check['value']['findings'][0]['amount']);
    }

    /**
     * Časové rozlišení (381): náklad rozdělený účtotvornou dimenzí na 518.100 / 518.200
     * se odkládá z týchž analytik ve stejném poměru — jinak by syntetika 518 šla do mínusu.
     */
    public function testPrepaidExpenseDeferralFollowsMappedAnalytics(): void
    {
        $this->enableDriving();
        $purchase = $this->purchase('F2-381', [[1_200.00, 252.00]]);
        $this->db->pdo()->prepare('UPDATE purchase_invoice_items SET accrual_from = ?, accrual_to = ? WHERE purchase_invoice_id = ?')
            ->execute([self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30', $purchase]);
        $this->dimensions->saveDocument(
            $this->supplierId,
            'purchase_invoice',
            $purchase,
            [],
            [],
            false,
            [0 => [$this->centerType => [
                ['value_id' => $this->fve, 'share' => 0.6],
                ['value_id' => $this->office, 'share' => 0.4],
            ]]],
        );
        self::assertSame(['518.100' => 72_000, '518.200' => 48_000], $this->expenseCents($this->postPurchase($purchase)));

        $preview = $this->closing()->prepaidExpenseAccrualPreview($this->supplierId, $this->periodId());
        $deferred = (int) round($preview['total'] * 100);
        self::assertGreaterThan(0, $deferred);
        $byAccount = array_map(static fn (float $a): int => (int) round($a * 100), $preview['by_account']);
        ksort($byAccount);
        self::assertSame(['518.100', '518.200'], array_keys($byAccount), 'Odklad jde z analytik, ne ze syntetiky 518.');
        self::assertSame($deferred, array_sum($byAccount), 'Haléř se neztratí.');
        self::assertEqualsWithDelta($deferred * 0.6, $byAccount['518.100'], 1);
    }

    /** Mapa změněná po zaúčtování: odklad jde z analytiky, na které náklad v deníku leží. */
    public function testDeferralFollowsPostedAnalyticAfterMapChange(): void
    {
        $this->enableDriving();
        $purchase = $this->purchase('F2-381-MAP', [[1_000.00, 210.00]]);
        $this->db->pdo()->prepare('UPDATE purchase_invoice_items SET accrual_from = ?, accrual_to = ? WHERE purchase_invoice_id = ?')
            ->execute([self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30', $purchase]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->centerType => $this->fve], []);
        self::assertSame(['518.100' => 100_000], $this->expenseCents($this->postPurchase($purchase)));
        $this->map->saveForValue($this->supplierId, $this->fve, [['synthetic_code' => '518', 'analytic_code' => '518.200']], $this->userId);

        $preview = $this->closing()->prepaidExpenseAccrualPreview($this->supplierId, $this->periodId());
        self::assertSame(['518.100'], array_keys($preview['by_account']), 'Ne z 518.200, kde náklad neleží.');
    }

    /**
     * Zápis odkladu (381) nese u každé analytiky středisko, se kterým na ní náklad leží,
     * a rozpuštění v dalším roce totéž — po středisku se 518.x vrátí přesně na nulu.
     */
    public function testPrepaidExpenseDeferralAndReleaseCarryDimensionsOfPostedLines(): void
    {
        $this->enableDriving();
        $purchase = $this->purchase('F2-381-DIM', [[1_200.00, 252.00]]);
        $this->db->pdo()->prepare('UPDATE purchase_invoice_items SET accrual_from = ?, accrual_to = ? WHERE purchase_invoice_id = ?')
            ->execute([self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30', $purchase]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [], [], false, [0 => [$this->centerType => [
            ['value_id' => $this->fve, 'share' => 0.6],
            ['value_id' => $this->office, 'share' => 0.4],
        ]]]);
        $this->postPurchase($purchase);

        $entryId = $this->runPrepaidAccrual();

        $deferred = $this->centerCentsOn($entryId, 'credit');
        $expected = AccrualDeferralService::spreadByAccount(
            AccrualDeferralService::deferredAmount(1_200.00, self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30', self::YEAR . '-12-31'),
            ['518.100' => 0.6, '518.200' => 0.4],
        );
        self::assertSame([
            '518.100|' . $this->fve => (int) round($expected['518.100'] * 100),
            '518.200|' . $this->office => (int) round($expected['518.200'] * 100),
        ], $deferred, 'Každá analytika se odkládá se svým střediskem.');

        $release = $this->releaseLines('prepaidExpenseReleaseForPeriod', [self::YEAR . '-12-31', (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31']);
        $released = [];
        foreach ($release['lines'] as $line) {
            $released[$line['account_code'] . '|' . ($line['dimensions'][$this->centerType] ?? '-')] = (int) round($line['amount'] * 100);
        }
        ksort($released);
        self::assertSame($deferred, $released, 'Rozpuštění vrací na tutéž analytiku a středisko, co odklad vzal.');
    }

    /**
     * Dvě položky se stejným nákladovým účtem a různým střediskem, rozlišená je jen jedna:
     * odklad jde jen ze střediska rozlišené položky, ne poměrem přes celý účet.
     */
    public function testPrepaidExpenseDeferralTakesOnlyDimensionOfAccruedItem(): void
    {
        $purchase = $this->purchase('F2-381-ITEMS', [[700.00, 147.00], [300.00, 63.00]]);
        $this->db->pdo()->prepare('UPDATE purchase_invoice_items SET accrual_from = ?, accrual_to = ? WHERE purchase_invoice_id = ? AND order_index = 0')
            ->execute([self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30', $purchase]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [], [
            1 => [$this->centerType => $this->fve],
            2 => [$this->centerType => $this->office],
        ]);
        $this->postPurchase($purchase);

        $entryId = $this->runPrepaidAccrual();

        $expected = AccrualDeferralService::deferredAmount(700.00, self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30', self::YEAR . '-12-31');
        self::assertSame(['518|' . $this->fve => (int) round($expected * 100)], $this->centerCentsOn($entryId, 'credit'));
    }

    /**
     * Přeúčtovaný doklad (storno a nový zápis s jiným střediskem jen na řádku zápisu, doklad
     * dimenzi nemá): odklad bere jen živý zápis, ne stornovaný.
     */
    public function testPrepaidExpenseDeferralFollowsLiveEntryAfterRepost(): void
    {
        $purchase = $this->purchase('F2-381-REPOST', [[1_000.00, 210.00]]);
        $this->db->pdo()->prepare('UPDATE purchase_invoice_items SET accrual_from = ?, accrual_to = ? WHERE purchase_invoice_id = ?')
            ->execute([self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30', $purchase]);
        $first = $this->postPurchaseWithExpenseCenter($purchase, $this->fve);
        $this->posting->reverse($this->supplierId, $first, ['entry_date' => self::YEAR . '-06-20']);
        $this->postPurchaseWithExpenseCenter($purchase, $this->office);

        $entryId = $this->runPrepaidAccrual();

        $expected = AccrualDeferralService::deferredAmount(1_000.00, self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30', self::YEAR . '-12-31');
        self::assertSame(['518|' . $this->office => (int) round($expected * 100)], $this->centerCentsOn($entryId, 'credit'));
    }

    /**
     * Účtotvorná dimenze dokladu změněná po zaúčtování (zápis čeká na přeúčtování, viz
     * {@see testChangingDimensionOfPostedDocumentNeedsRepost}): odklad jde podle zápisu,
     * kde náklad v deníku leží, ne podle nové hodnoty dokladu, a nic se neztratí.
     */
    public function testPrepaidExpenseDeferralFollowsPostedDimensionAfterDocumentChange(): void
    {
        $this->enableDriving();
        $purchase = $this->purchase('F2-381-CHANGED', [[1_000.00, 210.00]]);
        $this->db->pdo()->prepare('UPDATE purchase_invoice_items SET accrual_from = ?, accrual_to = ? WHERE purchase_invoice_id = ?')
            ->execute([self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30', $purchase]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->centerType => $this->fve], []);
        $this->postPurchase($purchase);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->centerType => $this->office], []);

        $entryId = $this->runPrepaidAccrual();

        $expected = AccrualDeferralService::deferredAmount(1_000.00, self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30', self::YEAR . '-12-31');
        self::assertSame(['518.100|' . $this->fve => (int) round($expected * 100)], $this->centerCentsOn($entryId, 'credit'));
    }

    /**
     * Účet nese v zápisu dvě střediska a dokladová dimenze položky (změněná bez
     * přeražítkování) neodpovídá žádnému: odklad se rozdělí v poměru zápisu a haléř se neztratí.
     */
    public function testPrepaidExpenseDeferralSplitsByPostedRatioWhenItemMatchesNoLine(): void
    {
        $purchase = $this->purchase('F2-381-NOMATCH', [[1_000.00, 210.00]]);
        $this->db->pdo()->prepare('UPDATE purchase_invoice_items SET accrual_from = ?, accrual_to = ? WHERE purchase_invoice_id = ?')
            ->execute([self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30', $purchase]);
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $purchase, [
            ['account_code' => '518', 'side' => 'debit', 'amount' => 700.00, 'dimensions' => [$this->centerType => $this->fve]],
            ['account_code' => '518', 'side' => 'debit', 'amount' => 300.00, 'dimensions' => [$this->centerType => $this->office]],
            ['account_code' => '343', 'side' => 'debit', 'amount' => 210.00],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 1_210.00],
        ], ['entry_date' => self::YEAR . '-06-15', 'posted_by' => $this->userId]);
        $this->assignments->replaceDocumentDimensions($this->supplierId, 'purchase_invoice', $purchase, [], [
            1 => [$this->centerType => $this->value($this->centerType, 'F2-NEW')],
        ]);

        $entryId = $this->runPrepaidAccrual();

        $expected = AccrualDeferralService::spreadByAccount(
            AccrualDeferralService::deferredAmount(1_000.00, self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30', self::YEAR . '-12-31'),
            ['fve' => 0.7, 'office' => 0.3],
        );
        self::assertSame([
            '518|' . $this->fve => (int) round($expected['fve'] * 100),
            '518|' . $this->office => (int) round($expected['office'] * 100),
        ], $this->centerCentsOn($entryId, 'credit'));
    }

    /**
     * Záporná rozlišená položka (sleva) s jiným střediskem se po dimenzi nevyruší s kladnou:
     * její řádek odkladu jde na opačnou stranu, krok projde a rozpuštění ji zrcadlí.
     */
    public function testNegativeAccruedItemWithOtherDimensionFlipsSide(): void
    {
        $purchase = $this->purchase('F2-381-NEG', [[1_000.00, 210.00], [-100.00, -21.00]]);
        $this->db->pdo()->prepare('UPDATE purchase_invoice_items SET accrual_from = ?, accrual_to = ? WHERE purchase_invoice_id = ?')
            ->execute([self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30', $purchase]);
        // Zápis nese středisko položek na řádcích (ruční rozúčtování), doklad položkám.
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $purchase, [
            ['account_code' => '518', 'side' => 'debit', 'amount' => 1_000.00, 'dimensions' => [$this->centerType => $this->fve]],
            ['account_code' => '518', 'side' => 'credit', 'amount' => 100.00, 'dimensions' => [$this->centerType => $this->office]],
            ['account_code' => '343', 'side' => 'debit', 'amount' => 189.00],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 1_089.00],
        ], ['entry_date' => self::YEAR . '-06-15', 'posted_by' => $this->userId]);
        $this->assignments->replaceDocumentDimensions($this->supplierId, 'purchase_invoice', $purchase, [], [
            1 => [$this->centerType => $this->fve],
            2 => [$this->centerType => $this->office],
        ]);

        $entryId = $this->runPrepaidAccrual();

        $positive = AccrualDeferralService::deferredAmount(1_000.00, self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30', self::YEAR . '-12-31');
        $negative = AccrualDeferralService::deferredAmount(100.00, self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30', self::YEAR . '-12-31');
        self::assertSame(['518|' . $this->fve => (int) round($positive * 100)], $this->centerCentsOn($entryId, 'credit'));
        self::assertSame(['518|' . $this->office => (int) round($negative * 100)], $this->centerCentsOn($entryId, 'debit'), 'Sleva jde na MD.');

        $release = $this->releaseLines('prepaidExpenseLines', [
            $this->releaseLines('prepaidExpenseReleaseForPeriod', [self::YEAR . '-12-31', (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31'])['lines'],
            round($positive - $negative, 2),
            true,
            (self::YEAR + 1) . '-01-01',
        ]);
        $sides = [];
        foreach ($release as $line) {
            if (str_starts_with((string) $line['account_code'], '5')) {
                $sides[(string) $line['dimensions'][$this->centerType]] = [$line['side'], (int) round($line['amount'] * 100)];
            }
        }
        ksort($sides);
        self::assertSame([
            (string) $this->fve => ['debit', (int) round($positive * 100)],
            (string) $this->office => ['credit', (int) round($negative * 100)],
        ], $sides, 'Rozpuštění vrací každé středisko na opačnou stranu než odklad.');
    }

    /** Rozlišená položka bez střediska se neodkládá ze střediska jiné položky téhož účtu. */
    public function testAccruedItemWithoutDimensionDoesNotBorrowOtherItemsDimension(): void
    {
        $purchase = $this->purchase('F2-381-NODIM', [[700.00, 147.00], [300.00, 63.00]]);
        $this->db->pdo()->prepare('UPDATE purchase_invoice_items SET accrual_from = ?, accrual_to = ? WHERE purchase_invoice_id = ? AND order_index = 1')
            ->execute([self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30', $purchase]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [], [
            1 => [$this->centerType => $this->fve],
        ]);
        $this->postPurchase($purchase);

        $entryId = $this->runPrepaidAccrual();

        $expected = AccrualDeferralService::deferredAmount(300.00, self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30', self::YEAR . '-12-31');
        self::assertSame(['518|-' => (int) round($expected * 100)], $this->centerCentsOn($entryId, 'credit'));
    }

    /** Položka se střediskem vs. položka se střediskem a zakázkou: odklad nebere cizí zakázku. */
    public function testAccruedItemDoesNotTakeCombinationWithExtraDocumentDimension(): void
    {
        $project = $this->value($this->projectType, 'F2-PRJ-X');
        $purchase = $this->purchase('F2-381-PRJ', [[700.00, 147.00], [300.00, 63.00]]);
        $this->db->pdo()->prepare('UPDATE purchase_invoice_items SET accrual_from = ?, accrual_to = ? WHERE purchase_invoice_id = ? AND order_index = 0')
            ->execute([self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30', $purchase]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->centerType => $this->fve], [
            2 => [$this->projectType => $project],
        ]);
        $this->postPurchase($purchase);

        $entryId = $this->runPrepaidAccrual();

        $expected = AccrualDeferralService::deferredAmount(700.00, self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30', self::YEAR . '-12-31');
        self::assertSame(['518|' . $this->fve => (int) round($expected * 100)], $this->centerCentsOn($entryId, 'credit'));
        self::assertSame([], $this->projectsOn($entryId), 'Odklad nenese zakázku nerozlišené položky.');
    }

    /**
     * Rozpad 60/40 rozdělený účtotvornou dimenzí na 518.100 / 518.200 a druhá položka na
     * 518.100 s jinou zakázkou: rozlišená položka se páruje s díly svého rozpadu, ne se zakázkou.
     */
    public function testSplitItemMatchesSingleValueLinesOfDrivingDimension(): void
    {
        $this->enableDriving();
        $project = $this->value($this->projectType, 'F2-PRJ-Y');
        $purchase = $this->purchase('F2-381-SPLITPRJ', [[1_000.00, 210.00], [500.00, 105.00]]);
        $this->db->pdo()->prepare('UPDATE purchase_invoice_items SET accrual_from = ?, accrual_to = ? WHERE purchase_invoice_id = ? AND order_index = 0')
            ->execute([self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30', $purchase]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [], [
            2 => [$this->centerType => $this->fve, $this->projectType => $project],
        ], false, [1 => [$this->centerType => [
            ['value_id' => $this->fve, 'share' => 0.6],
            ['value_id' => $this->office, 'share' => 0.4],
        ]]]);
        $this->postPurchase($purchase);

        $entryId = $this->runPrepaidAccrual();

        $expected = AccrualDeferralService::spreadByAccount(
            AccrualDeferralService::deferredAmount(1_000.00, self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30', self::YEAR . '-12-31'),
            ['518.100' => 0.6, '518.200' => 0.4],
        );
        self::assertSame([
            '518.100|' . $this->fve => (int) round($expected['518.100'] * 100),
            '518.200|' . $this->office => (int) round($expected['518.200'] * 100),
        ], $this->centerCentsOn($entryId, 'credit'));
        self::assertSame([], $this->projectsOn($entryId), 'Zakázka druhé položky se do odkladu nedostane.');
    }

    /**
     * Odklad zaúčtovaný uzávěrkou ještě bez dimenzí (před opravou) se rozpouští také bez
     * dimenzí: rozpuštění zrcadlí odklad, ne dnešní zápis dokladu.
     */
    public function testReleaseMirrorsLegacyDeferralWithoutDimensions(): void
    {
        $purchase = $this->purchase('F2-381-LEGACY', [[1_000.00, 210.00]]);
        $this->db->pdo()->prepare('UPDATE purchase_invoice_items SET accrual_from = ?, accrual_to = ? WHERE purchase_invoice_id = ?')
            ->execute([self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30', $purchase]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->centerType => $this->fve], []);
        $this->postPurchase($purchase);
        $deferred = AccrualDeferralService::deferredAmount(1_000.00, self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30', self::YEAR . '-12-31');
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")->execute([$this->periodId()]);
        $this->posting->postDocument($this->supplierId, 'prepaid_expense_accrual', \MyInvoice\Service\Accounting\Closing\ClosingSourceId::prepaidExpenseAccrual($this->periodId()), [
            ['account_code' => '381', 'side' => 'debit', 'amount' => $deferred],
            ['account_code' => '518', 'side' => 'credit', 'amount' => $deferred],
        ], ['entry_date' => self::YEAR . '-12-31', 'posted' => true, 'posted_by' => $this->userId, 'allow_closing_period' => true]);

        $release = $this->releaseLines('prepaidExpenseReleaseForPeriod', [self::YEAR . '-12-31', (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31']);

        self::assertCount(1, $release['lines']);
        self::assertSame('518', $release['lines'][0]['account_code']);
        self::assertArrayNotHasKey('dimensions', $release['lines'][0], 'Starý odklad bez dimenzí se rozpouští bez dimenzí.');
        self::assertEqualsWithDelta($deferred, $release['lines'][0]['amount'], 0.001);
    }

    /**
     * Kladná a záporná rozlišená položka s různým střediskem dají celkem nulu: odklad přesto
     * vznikne po střediscích (bez řádku 381), jinak by obě střediska zůstala celá v roce N.
     */
    public function testZeroTotalWithOppositeDimensionsStillDefersPerDimension(): void
    {
        $purchase = $this->purchase('F2-381-ZERO', [[1_000.00, 0.00], [-1_000.00, 0.00]]);
        $this->db->pdo()->prepare('UPDATE purchase_invoice_items SET accrual_from = ?, accrual_to = ? WHERE purchase_invoice_id = ?')
            ->execute([self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30', $purchase]);
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $purchase, [
            ['account_code' => '518', 'side' => 'debit', 'amount' => 1_000.00, 'dimensions' => [$this->centerType => $this->fve]],
            ['account_code' => '518', 'side' => 'credit', 'amount' => 1_000.00, 'dimensions' => [$this->centerType => $this->office]],
        ], ['entry_date' => self::YEAR . '-06-15', 'posted_by' => $this->userId]);
        $this->assignments->replaceDocumentDimensions($this->supplierId, 'purchase_invoice', $purchase, [], [
            1 => [$this->centerType => $this->fve],
            2 => [$this->centerType => $this->office],
        ]);

        $entryId = $this->runPrepaidAccrual();

        $expected = (int) round(AccrualDeferralService::deferredAmount(1_000.00, self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30', self::YEAR . '-12-31') * 100);
        self::assertSame(['518|' . $this->fve => $expected], $this->centerCentsOn($entryId, 'credit'));
        self::assertSame(['518|' . $this->office => $expected], $this->centerCentsOn($entryId, 'debit'));

        // Rozpuštění: řádky po střediscích na opačných stranách, bez řádku 381.
        $release = $this->releaseLines('prepaidExpenseReleaseForPeriod', [self::YEAR . '-12-31', (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31']);
        $lines = $this->releaseLines('prepaidExpenseLines', [$release['lines'], (float) $release['total'], true, (self::YEAR + 1) . '-01-01']);
        $sides = [];
        foreach ($lines as $line) {
            $sides[$line['account_code'] . '|' . ($line['dimensions'][$this->centerType] ?? '-')] = [$line['side'], (int) round($line['amount'] * 100)];
        }
        ksort($sides);
        self::assertSame([
            '518|' . $this->fve => ['debit', $expected],
            '518|' . $this->office => ['credit', $expected],
        ], $sides);
    }

    /**
     * Víc dokladů se stejnou dimenzí: odklad má jeden řádek (jako dosud jeden řádek na účet),
     * nese jediný zdrojový řádek pro důkaz přenosu a druh původu; odkaz na konkrétní doklad
     * se při sloučení ztratí.
     */
    public function testDeferralMergesDocumentsWithSameDimensionIntoOneLine(): void
    {
        foreach (['F2-381-C1', 'F2-381-C2', 'F2-381-C3'] as $number) {
            $purchase = $this->purchase($number, [[100.00, 21.00]]);
            $this->db->pdo()->prepare('UPDATE purchase_invoice_items SET accrual_from = ?, accrual_to = ? WHERE purchase_invoice_id = ?')
                ->execute([self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30', $purchase]);
            $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->centerType => $this->fve], []);
            $this->postPurchase($purchase);
        }

        $data = $this->releaseLines('prepaidExpenseAccrualData', [$this->periodId()]);

        self::assertCount(1, $data['lines'], (string) json_encode($data['lines']));
        $line = $data['lines'][0];
        self::assertCount(1, $line['dimension_carry_lines']);
        self::assertSame([$this->centerType => $this->fve], $line['dimensions']);
        self::assertSame(['origin' => 'explicit', 'ref' => null], $line['dimension_origins'][$this->centerType]);
        $single = AccrualDeferralService::deferredAmount(100.00, self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30', self::YEAR . '-12-31');
        self::assertEqualsWithDelta(round(3 * $single, 2), $line['amount'], 0.001);
    }

    /**
     * Načtení dimenzí zápisů a položek pro časové rozlišení je dávkové: počet dotazů
     * nezávisí na počtu dokladů, jejich dodavatelů s výchozími dimenzemi ani produktů.
     */
    public function testDimensionLoadingQueryCountDoesNotGrowWithDocuments(): void
    {
        $pdo = $this->db->pdo();
        $defaults = new \MyInvoice\Repository\DimensionDefaultRepository($this->db);
        $project = $this->value($this->projectType, 'F2-PRJ-Q');
        $counts = [];
        foreach ([2, 6] as $size) {
            $ids = [];
            $items = [];
            for ($i = 0; $i < $size; $i++) {
                $purchase = $this->purchase('F2-Q' . $size . '-' . $i, [[100.00, 21.00], [50.00, 10.50]]);
                $defaults->replace($this->supplierId, 'client', $this->vendorOf($purchase), [$this->projectType => $project]);
                $pdo->prepare("INSERT INTO stock_items (supplier_id, sku, name, unit) VALUES (?, ?, 'Syntetický produkt', 'ks')")->execute([$this->supplierId, 'F2-Q' . $size . '-' . $i]);
                $productId = (int) $pdo->lastInsertId();
                $defaults->replace($this->supplierId, 'product', $productId, [$this->centerType => $this->office]);
                $pdo->prepare('UPDATE purchase_invoice_items SET stock_item_id = ? WHERE purchase_invoice_id = ? AND order_index = 1')->execute([$productId, $purchase]);
                $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [], [1 => [$this->centerType => $this->fve]]);
                $this->postPurchase($purchase);
                $ids[] = $purchase;
                $items[$purchase] = (int) $pdo->query('SELECT id FROM purchase_invoice_items WHERE purchase_invoice_id = ' . $purchase . ' ORDER BY order_index LIMIT 1')->fetchColumn();
            }
            $service = Bootstrap::buildContainer()->get(AccrualDeferralService::class);
            $before = $this->selectCount();
            $cache = [];
            $service->preloadPrepaidExpenseAllocations($this->supplierId, array_map(static fn (int $id): array => ['purchase_invoice_id' => $id], $ids), $cache);
            foreach ($ids as $id) {
                $allocations = $service->dimensionAllocations($this->supplierId, 'purchase_invoice', $id, $items[$id], ['518' => 1.0], $cache);
                self::assertSame([$this->fve], array_map(fn (array $a): ?int => $a['dimensions'][$this->centerType] ?? null, $allocations), 'Položka se páruje se svým střediskem.');
            }
            $service->documentItemDimensions($this->supplierId, 'purchase_invoice', $ids);
            $counts[$size] = $this->selectCount() - $before;
        }

        self::assertSame($counts[2], $counts[6], 'Dotazy: ' . json_encode($counts));
    }

    private function selectCount(): int
    {
        $row = $this->db->pdo()->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(\PDO::FETCH_ASSOC);
        return (int) ($row['Value'] ?? 0);
    }

    /**
     * Víceleté rozpouštění se dvěma středisky na jednom účtu: odklad a dvě tranše
     * rozpuštění se po každém středisku sečtou na nulu, bez haléřového zbytku.
     */
    public function testMultiYearReleaseDrainsEachDimensionToTheHaler(): void
    {
        $purchase = $this->purchase('F2-381-MULTI', [[100.01, 0.00]]);
        $this->db->pdo()->prepare('UPDATE purchase_invoice_items SET accrual_from = ?, accrual_to = ? WHERE purchase_invoice_id = ?')
            ->execute([self::YEAR . '-07-01', (self::YEAR + 2) . '-06-30', $purchase]);
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $purchase, [
            ['account_code' => '518', 'side' => 'debit', 'amount' => 50.01, 'dimensions' => [$this->centerType => $this->fve]],
            ['account_code' => '518', 'side' => 'debit', 'amount' => 50.00, 'dimensions' => [$this->centerType => $this->office]],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 100.01],
        ], ['entry_date' => self::YEAR . '-06-15', 'posted_by' => $this->userId]);

        $entryId = $this->runPrepaidAccrual();
        $balance = $this->centerCentsOn($entryId, 'credit');
        foreach ([[self::YEAR . '-12-31', (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31'], [(self::YEAR + 1) . '-12-31', (self::YEAR + 2) . '-01-01', (self::YEAR + 2) . '-12-31']] as $tranche) {
            foreach ($this->releaseLines('prepaidExpenseReleaseForPeriod', $tranche)['lines'] as $line) {
                $k = $line['account_code'] . '|' . ($line['dimensions'][$this->centerType] ?? '-');
                $balance[$k] = ($balance[$k] ?? 0) - (int) round($line['amount'] * 100);
            }
        }

        self::assertSame(['518|' . $this->fve => 0, '518|' . $this->office => 0], $balance);
    }

    /**
     * Položka nevyhoví žádné kombinaci a účet nese kladnou i zápornou kombinaci: poměrem
     * se dělí jen mezi kombinace se znaménkem obratu účtu, sleva díl nepřevezme.
     */
    public function testFallbackSplitIgnoresCombinationOfOppositeSign(): void
    {
        $purchase = $this->purchase('F2-381-SIGN', [[1_000.00, 0.00]]);
        $this->db->pdo()->prepare('UPDATE purchase_invoice_items SET accrual_from = ?, accrual_to = ? WHERE purchase_invoice_id = ?')
            ->execute([self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30', $purchase]);
        $other = $this->value($this->centerType, 'F2-OTHER-SIGN');
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $purchase, [
            ['account_code' => '518', 'side' => 'debit', 'amount' => 1_100.00, 'dimensions' => [$this->centerType => $this->fve]],
            ['account_code' => '518', 'side' => 'debit', 'amount' => 200.00, 'dimensions' => [$this->centerType => $other]],
            ['account_code' => '518', 'side' => 'credit', 'amount' => 300.00, 'dimensions' => [$this->centerType => $this->office]],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 1_000.00],
        ], ['entry_date' => self::YEAR . '-06-15', 'posted_by' => $this->userId]);
        $this->assignments->replaceDocumentDimensions($this->supplierId, 'purchase_invoice', $purchase, [], [
            1 => [$this->centerType => $this->value($this->centerType, 'F2-NEW-SIGN')],
        ]);

        $entryId = $this->runPrepaidAccrual();

        self::assertArrayNotHasKey('518|' . $this->office, $this->centerCentsOn($entryId, 'credit') + $this->centerCentsOn($entryId, 'debit'));
        $expected = AccrualDeferralService::spreadByAccount(
            AccrualDeferralService::deferredAmount(1_000.00, self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30', self::YEAR . '-12-31'),
            ['fve' => 1_100 / 1_300, 'other' => 200 / 1_300],
        );
        self::assertSame([
            '518|' . $this->fve => (int) round($expected['fve'] * 100),
            '518|' . $other => (int) round($expected['other'] * 100),
        ], $this->centerCentsOn($entryId, 'credit'));
    }

    /**
     * Dávkové čtení dimenzí položek pro časové rozlišení skládá stejné vrstvy jako
     * {@see \MyInvoice\Service\Accounting\Dimension\DimensionStamper::itemDimensions()} pro víc
     * dokladů najednou: položka (hodnota i rozpad), výchozí dimenze produktu, hlavička
     * (hodnota i rozpad) a zděděná hlavička (zakázka > dodavatel) včetně filtru neaktivních hodnot.
     */
    public function testBatchItemDimensionsMatchStamper(): void
    {
        $pdo = $this->db->pdo();
        $project = $this->value($this->projectType, 'F2-PRJ-BATCH');
        $vendorProject = $this->value($this->projectType, 'F2-PRJ-VENDOR');
        $vendorCenter = $this->value($this->centerType, 'F2-CTR-VENDOR');
        $orderCenter = $this->value($this->centerType, 'F2-CTR-ORDER');
        $productCenter = $this->value($this->centerType, 'F2-CTR-PRODUCT');
        $closed = $this->value($this->centerType, 'F2-CTR-CLOSED');
        $defaults = new \MyInvoice\Repository\DimensionDefaultRepository($this->db);
        $pdo->prepare("INSERT INTO stock_items (supplier_id, sku, name, unit) VALUES (?, 'F2-BATCH-SKU', 'Syntetický produkt', 'ks')")->execute([$this->supplierId]);
        $productId = (int) $pdo->lastInsertId();
        $defaults->replace($this->supplierId, 'product', $productId, [$this->centerType => $productCenter]);

        // Hlavička se zakázkou, položka se střediskem a položka s rozpadem; dodavatel dává
        // středisko (zdědí ho jen položka bez vlastního) i zakázku (přebije ji hlavička).
        $first = $this->purchase('F2-BATCH-1', [[100.00, 21.00], [200.00, 42.00], [300.00, 63.00]]);
        $defaults->replace($this->supplierId, 'client', $this->vendorOf($first), [$this->centerType => $vendorCenter, $this->projectType => $vendorProject]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $first, [$this->projectType => $project], [
            2 => [$this->centerType => $this->office],
        ], false, [3 => [$this->centerType => [
            ['value_id' => $this->fve, 'share' => 0.25],
            ['value_id' => $this->office, 'share' => 0.75],
        ]]]);
        // Rozpad hlavičky přebíjí zděděné středisko; zakázku položka bez vlastní zdědí.
        $second = $this->purchase('F2-BATCH-2', [[100.00, 21.00], [50.00, 10.50]]);
        $defaults->replace($this->supplierId, 'client', $this->vendorOf($second), [$this->centerType => $vendorCenter, $this->projectType => $vendorProject]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $second, [], [
            1 => [$this->projectType => $project],
        ], false, [0 => [$this->centerType => [
            ['value_id' => $this->fve, 'share' => 0.5],
            ['value_id' => $this->office, 'share' => 0.5],
        ]]]);
        // Zakázka dokladu má přednost před dodavatelem; položka s produktem bere jeho středisko.
        $third = $this->purchase('F2-BATCH-3', [[100.00, 21.00], [50.00, 10.50]]);
        $defaults->replace($this->supplierId, 'client', $this->vendorOf($third), [$this->centerType => $vendorCenter, $this->projectType => $vendorProject]);
        $pdo->prepare('INSERT INTO projects (client_id, name, currency_id) VALUES (?, ?, ?)')->execute([$this->vendorOf($third), 'Syntetická zakázka', $this->currencyId]);
        $orderId = (int) $pdo->lastInsertId();
        $defaults->replace($this->supplierId, 'project', $orderId, [$this->centerType => $orderCenter]);
        $pdo->prepare('UPDATE purchase_invoices SET project_id = ? WHERE id = ?')->execute([$orderId, $third]);
        $pdo->prepare('UPDATE purchase_invoice_items SET stock_item_id = ? WHERE purchase_invoice_id = ? AND order_index = 1')->execute([$productId, $third]);
        // Výchozí hodnota mezitím uzavřená se nedědí.
        $fourth = $this->purchase('F2-BATCH-4', [[100.00, 21.00]]);
        $defaults->replace($this->supplierId, 'client', $this->vendorOf($fourth), [$this->centerType => $closed, $this->projectType => $vendorProject]);
        $pdo->prepare('UPDATE dimension_values SET is_active = 0 WHERE id = ?')->execute([$closed]);

        $batch = Bootstrap::buildContainer()->get(AccrualDeferralService::class)
            ->documentItemDimensions($this->supplierId, 'purchase_invoice', [$first, $second, $third, $fourth]);

        $stamper = new \MyInvoice\Service\Accounting\Dimension\DimensionStamper($this->db);
        $seen = [];
        foreach ([$first, $second, $third, $fourth] as $docId) {
            $expected = [];
            foreach ($stamper->itemDimensions($this->supplierId, 'purchase_invoice', $docId) as $itemId => $line) {
                $expected[$itemId] = array_intersect_key($line, ['dimensions' => 1, 'dimension_splits' => 1]);
                $seen[] = $expected[$itemId]['dimensions'][$this->centerType] ?? null;
            }
            self::assertNotSame([], $expected);
            self::assertEquals($expected, $batch[$docId] ?? null, 'Doklad ' . $docId);
        }
        // Fixture opravdu prochází zděděnou hlavičku dodavatele i zakázky a výchozí dimenzi produktu.
        foreach ([$vendorCenter, $orderCenter, $productCenter] as $value) {
            self::assertContains($value, $seen);
        }
        self::assertNotContains($closed, $seen);
    }

    private function vendorOf(int $purchaseId): int
    {
        return (int) $this->db->pdo()->query('SELECT vendor_id FROM purchase_invoices WHERE id = ' . $purchaseId)->fetchColumn();
    }

    /**
     * Dodavatel s výchozím střediskem, jedna položka s vlastním střediskem a druhá (časově
     * rozlišená) bez něj: druhá položka nese v deníku zděděné středisko dodavatele a odklad
     * jde celý z něj, ne poměrem i ze střediska první položky.
     */
    public function testAccruedItemWithoutOwnDimensionUsesInheritedVendorDimension(): void
    {
        $vendorCenter = $this->value($this->centerType, 'F2-VENDOR');
        $purchase = $this->purchase('F2-381-INHERIT', [[700.00, 147.00], [300.00, 63.00]]);
        $vendorId = (int) $this->db->pdo()->query("SELECT vendor_id FROM purchase_invoices WHERE id = {$purchase}")->fetchColumn();
        (new \MyInvoice\Repository\DimensionDefaultRepository($this->db))->replace($this->supplierId, 'client', $vendorId, [$this->centerType => $vendorCenter]);
        $this->db->pdo()->prepare('UPDATE purchase_invoice_items SET accrual_from = ?, accrual_to = ? WHERE purchase_invoice_id = ? AND order_index = 1')
            ->execute([self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30', $purchase]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [], [
            1 => [$this->centerType => $this->fve],
        ]);
        $this->postPurchase($purchase);

        $entryId = $this->runPrepaidAccrual();

        $expected = AccrualDeferralService::deferredAmount(300.00, self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30', self::YEAR . '-12-31');
        self::assertSame(['518|' . $vendorCenter => (int) round($expected * 100)], $this->centerCentsOn($entryId, 'credit'));
    }

    /**
     * Doklad zaúčtovaný na syntetiku 518 dřív, než vznikla mapa účtotvorné dimenze: odklad
     * i rozpuštění zůstávají na 518 (kde náklad leží), dnešní mapa je na 518.100 nepřesměruje.
     */
    public function testDeferralOfDocumentPostedBeforeMapStaysOnPostedAccount(): void
    {
        [$purchase] = $this->postedBeforeMap();
        $this->db->pdo()->prepare('UPDATE purchase_invoice_items SET accrual_from = ?, accrual_to = ? WHERE purchase_invoice_id = ?')
            ->execute([self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30', $purchase]);

        $entryId = $this->runPrepaidAccrual();

        $expected = AccrualDeferralService::deferredAmount(500.00, self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30', self::YEAR . '-12-31');
        self::assertSame(['518|' . $this->fve => (int) round($expected * 100)], $this->centerCentsOn($entryId, 'credit'));
    }

    /** Import rozvrhu změnil uznatelnost analytiky: mapa se neuplatní a kontrola ji ukáže. */
    public function testMapRowWithChangedDeductibilityIsSkippedAndReported(): void
    {
        $this->enableDriving();
        // Třetí daňová analytika, ať 518 nemá jedinou daňovou analytiku (přesměr mimo F2).
        $this->analytic('518.300', '518');
        $this->db->pdo()->prepare("UPDATE chart_of_accounts SET tax_deductibility = 'non_deductible' WHERE supplier_id = ? AND account_code = '518.100'")
            ->execute([$this->supplierId]);
        $purchase = $this->purchase('F2-DEDUCT', [[100.00, 21.00]]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->centerType => $this->fve], []);

        self::assertSame(['518' => 10_000], $this->expenseCents($this->postPurchase($purchase)), 'Nedaňová analytika nesmí převzít daňový náklad.');
        $check = $this->checkOf('dimension_account_map_invalid');
        self::assertFalse($check['ok']);
        self::assertSame(['518.100'], array_column($check['value']['findings'], 'account_code'));
    }

    /** Storno pár a zápis před platností mapy kontrolu nespouští. */
    public function testClosingCheckIgnoresReversedEntriesAndEntriesBeforeMapValidity(): void
    {
        $this->enableDriving();
        $reversed = $this->postPurchase($this->purchase('F2-CHK-REV', [[100.00, 21.00]]));
        $this->posting->reverse($this->supplierId, $reversed, ['entry_date' => self::YEAR . '-06-20']);
        $this->map->saveForValue($this->supplierId, $this->office, [
            ['synthetic_code' => '518', 'analytic_code' => '518.200', 'valid_from' => self::YEAR . '-07-01'],
        ], $this->userId);
        $this->db->pdo()->prepare('DELETE FROM dimension_account_map WHERE supplier_id = ? AND dimension_value_id = ?')
            ->execute([$this->supplierId, $this->fve]);
        $this->postPurchase($this->purchase('F2-CHK-EARLY', [[100.00, 21.00]]));

        self::assertTrue($this->checkOf('dimension_account_unmapped')['ok']);
    }

    /**
     * Výchozí středisko dodavatele je zároveň výslovným střediskem jiné položky: položka bez
     * vlastního střediska (časově rozlišená) nese v deníku zděděné středisko a odklad jde celý
     * z něj, ne poměrem i ze střediska první položky.
     */
    public function testAccruedItemTakesInheritedVendorDimensionGivenAlsoToAnotherItem(): void
    {
        $vendorCenter = $this->value($this->centerType, 'F2-VENDOR-SHARED');
        $purchase = $this->purchase('F2-381-INHERIT-SHARED', [[700.00, 147.00], [300.00, 63.00], [200.00, 42.00]]);
        $vendorId = (int) $this->db->pdo()->query("SELECT vendor_id FROM purchase_invoices WHERE id = {$purchase}")->fetchColumn();
        (new \MyInvoice\Repository\DimensionDefaultRepository($this->db))->replace($this->supplierId, 'client', $vendorId, [$this->centerType => $vendorCenter]);
        $this->db->pdo()->prepare('UPDATE purchase_invoice_items SET accrual_from = ?, accrual_to = ? WHERE purchase_invoice_id = ? AND order_index = 1')
            ->execute([self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30', $purchase]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [], [
            1 => [$this->centerType => $this->fve],
            3 => [$this->centerType => $vendorCenter],
        ]);
        $this->postPurchase($purchase);

        $entryId = $this->runPrepaidAccrual();

        $expected = AccrualDeferralService::deferredAmount(300.00, self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30', self::YEAR . '-12-31');
        self::assertSame(['518|' . $vendorCenter => (int) round($expected * 100)], $this->centerCentsOn($entryId, 'credit'));
    }

    /**
     * Výchozí středisko dodavatele změněné až po zaúčtování: položka bez vlastního střediska
     * leží v deníku na původním. Přesné párování (dnešní výchozí hodnota) nevyhoví ničemu,
     * volnější párování nad výslovnými hodnotami dokladu ale pořád vyřadí středisko jiné
     * položky a odklad jde z řádku, kde náklad skutečně leží.
     */
    public function testInheritedDimensionChangedAfterPostingStillMatchesPostedLine(): void
    {
        $posted = $this->value($this->centerType, 'F2-VENDOR-OLD');
        $purchase = $this->purchase('F2-381-INHERIT-STALE', [[700.00, 147.00], [300.00, 63.00]]);
        $vendorId = (int) $this->db->pdo()->query("SELECT vendor_id FROM purchase_invoices WHERE id = {$purchase}")->fetchColumn();
        $defaults = new \MyInvoice\Repository\DimensionDefaultRepository($this->db);
        $defaults->replace($this->supplierId, 'client', $vendorId, [$this->centerType => $posted]);
        $this->db->pdo()->prepare('UPDATE purchase_invoice_items SET accrual_from = ?, accrual_to = ? WHERE purchase_invoice_id = ? AND order_index = 1')
            ->execute([self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30', $purchase]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [], [
            1 => [$this->centerType => $this->fve],
        ]);
        $this->postPurchase($purchase);
        $defaults->replace($this->supplierId, 'client', $vendorId, [$this->centerType => $this->value($this->centerType, 'F2-VENDOR-NEW')]);

        $entryId = $this->runPrepaidAccrual();

        $expected = AccrualDeferralService::deferredAmount(300.00, self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30', self::YEAR . '-12-31');
        self::assertSame(['518|' . $posted => (int) round($expected * 100)], $this->centerCentsOn($entryId, 'credit'));
    }

    /**
     * Dvě položky téhož účtu s rozpadem mezi stejná střediska, ale v jiném poměru: odklad
     * rozlišené položky jde jen z kombinace s jejími podíly, ne váženě přes obě.
     */
    public function testSplitItemMatchesOnlyCombinationWithItsShares(): void
    {
        $purchase = $this->purchase('F2-381-SHARES', [[1_000.00, 210.00], [500.00, 105.00]]);
        $this->db->pdo()->prepare('UPDATE purchase_invoice_items SET accrual_from = ?, accrual_to = ? WHERE purchase_invoice_id = ? AND order_index = 0')
            ->execute([self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30', $purchase]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [], [], false, [
            1 => [$this->centerType => [['value_id' => $this->fve, 'share' => 0.5], ['value_id' => $this->office, 'share' => 0.5]]],
            2 => [$this->centerType => [['value_id' => $this->fve, 'share' => 0.1], ['value_id' => $this->office, 'share' => 0.9]]],
        ]);
        $this->postPurchase($purchase);

        $entryId = $this->runPrepaidAccrual();

        $expected = AccrualDeferralService::deferredAmount(1_000.00, self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30', self::YEAR . '-12-31');
        self::assertSame(
            ['518|' . $this->fve . ':0.5,' . $this->office . ':0.5' => (int) round($expected * 100)],
            $this->centerSplitCentsOn($entryId, 'credit'),
        );
    }

    /**
     * Rozpad položky mezi střediska a jiná položka téhož účtu celá na jednom z nich, středisko
     * účty neřídí: řádek s jedinou hodnotou není dílem rozpadu (na řádky analytik dělí rozpad
     * jen účtotvorná dimenze), odklad jde jen z řádku s rozpadem.
     */
    public function testSplitItemDoesNotTakeSingleValueLineOfNonDrivingDimension(): void
    {
        $purchase = $this->purchase('F2-381-SPLIT-SINGLE', [[1_000.00, 210.00], [500.00, 105.00]]);
        $this->db->pdo()->prepare('UPDATE purchase_invoice_items SET accrual_from = ?, accrual_to = ? WHERE purchase_invoice_id = ? AND order_index = 0')
            ->execute([self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30', $purchase]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [], [
            2 => [$this->centerType => $this->fve],
        ], false, [
            1 => [$this->centerType => [['value_id' => $this->fve, 'share' => 0.5], ['value_id' => $this->office, 'share' => 0.5]]],
        ]);
        $this->postPurchase($purchase);

        $entryId = $this->runPrepaidAccrual();

        $expected = AccrualDeferralService::deferredAmount(1_000.00, self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30', self::YEAR . '-12-31');
        self::assertSame(
            ['518|' . $this->fve . ':0.5,' . $this->office . ':0.5' => (int) round($expected * 100)],
            $this->centerSplitCentsOn($entryId, 'credit'),
        );
        self::assertSame(['518|-' => (int) round($expected * 100)], $this->centerCentsOn($entryId, 'credit'), 'Žádný díl nejde z řádku druhé položky.');
    }

    /**
     * Položce vyhoví dvě kombinace a obě mají nulový obrat: poměr zápisu chybí, dělí se mezi
     * ně rovným dílem (kombinace jiné položky s nenulovým obratem díl nedostane).
     */
    public function testItemMatchingOnlyZeroTurnoverCombinationsSplitsEqually(): void
    {
        $projects = [$this->value($this->projectType, 'F2-PRJ-Z1'), $this->value($this->projectType, 'F2-PRJ-Z2')];
        $purchase = $this->purchase('F2-381-ZEROEQUAL', [[1_000.00, 0.00], [500.00, 0.00]]);
        $lines = [];
        foreach ($projects as $project) {
            foreach (['debit', 'credit'] as $side) {
                $lines[] = ['account_code' => '518', 'side' => $side, 'amount' => 500.00, 'dimensions' => [$this->centerType => $this->fve, $this->projectType => $project]];
            }
        }
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $purchase, [
            ...$lines,
            ['account_code' => '518', 'side' => 'debit', 'amount' => 500.00, 'dimensions' => [$this->centerType => $this->office]],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 500.00],
        ], ['entry_date' => self::YEAR . '-06-15', 'posted_by' => $this->userId]);
        $this->assignments->replaceDocumentDimensions($this->supplierId, 'purchase_invoice', $purchase, [], [
            1 => [$this->centerType => $this->fve],
            2 => [$this->centerType => $this->office],
        ]);
        $itemId = (int) $this->db->pdo()->query('SELECT id FROM purchase_invoice_items WHERE purchase_invoice_id = ' . $purchase . ' ORDER BY order_index LIMIT 1')->fetchColumn();

        $cache = [];
        $allocations = Bootstrap::buildContainer()->get(AccrualDeferralService::class)
            ->dimensionAllocations($this->supplierId, 'purchase_invoice', $purchase, $itemId, ['518' => 1.0], $cache);

        $parts = [];
        foreach ($allocations as $allocation) {
            self::assertSame($this->fve, $allocation['dimensions'][$this->centerType] ?? null);
            $parts[$allocation['dimensions'][$this->projectType] ?? 0] = $allocation['part'];
        }
        ksort($parts);
        self::assertSame([$projects[0] => 0.5, $projects[1] => 0.5], $parts);
    }

    /**
     * Účet nese jedinou kombinaci dimenzí a ta má nulový obrat (náklad je na účtu vystornovaný):
     * není z čeho brát poměr ani s čím položku párovat, odklad zůstává bez dimenzí.
     */
    public function testAccountWithOnlyZeroTurnoverCombinationStaysWithoutDimensions(): void
    {
        $purchase = $this->purchase('F2-381-ZEROONLY', [[1_000.00, 0.00]]);
        $this->db->pdo()->prepare('UPDATE purchase_invoice_items SET accrual_from = ?, accrual_to = ? WHERE purchase_invoice_id = ?')
            ->execute([self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30', $purchase]);
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $purchase, [
            ['account_code' => '518', 'side' => 'debit', 'amount' => 1_000.00, 'dimensions' => [$this->centerType => $this->fve]],
            ['account_code' => '518', 'side' => 'credit', 'amount' => 1_000.00, 'dimensions' => [$this->centerType => $this->fve]],
            ['account_code' => '501', 'side' => 'debit', 'amount' => 1_000.00],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 1_000.00],
        ], ['entry_date' => self::YEAR . '-06-15', 'posted_by' => $this->userId]);

        $service = Bootstrap::buildContainer()->get(AccrualDeferralService::class);
        $itemId = (int) $this->db->pdo()->query('SELECT id FROM purchase_invoice_items WHERE purchase_invoice_id = ' . $purchase)->fetchColumn();
        $cache = [];
        $allocations = $service->dimensionAllocations($this->supplierId, 'purchase_invoice', $purchase, $itemId, ['518' => 1.0], $cache);

        self::assertSame([['account_code' => '518', 'account_share' => 1.0, 'part' => 1.0]], $allocations);
    }

    /**
     * Středisko rozlišené položky má na účtu nulový obrat (položku vyrovnává sleva na témže
     * středisku) a účet nese i jiné středisko: kombinace s nulovým obratem zůstává kandidátem
     * párování a odklad jde z ní, ne z jediného střediska s nenulovým obratem.
     */
    public function testAccruedItemMatchesCombinationWithZeroTurnover(): void
    {
        $purchase = $this->purchase('F2-381-ZEROGROUP', [[1_000.00, 0.00], [-1_000.00, 0.00], [500.00, 0.00]]);
        $this->db->pdo()->prepare('UPDATE purchase_invoice_items SET accrual_from = ?, accrual_to = ? WHERE purchase_invoice_id = ? AND order_index = 0')
            ->execute([self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30', $purchase]);
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $purchase, [
            ['account_code' => '518', 'side' => 'debit', 'amount' => 1_000.00, 'dimensions' => [$this->centerType => $this->fve]],
            ['account_code' => '518', 'side' => 'credit', 'amount' => 1_000.00, 'dimensions' => [$this->centerType => $this->fve]],
            ['account_code' => '518', 'side' => 'debit', 'amount' => 500.00, 'dimensions' => [$this->centerType => $this->office]],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 500.00],
        ], ['entry_date' => self::YEAR . '-06-15', 'posted_by' => $this->userId]);
        $this->assignments->replaceDocumentDimensions($this->supplierId, 'purchase_invoice', $purchase, [], [
            1 => [$this->centerType => $this->fve],
            2 => [$this->centerType => $this->fve],
            3 => [$this->centerType => $this->office],
        ]);

        $entryId = $this->runPrepaidAccrual();

        $expected = AccrualDeferralService::deferredAmount(1_000.00, self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30', self::YEAR . '-12-31');
        self::assertSame(['518|' . $this->fve => (int) round($expected * 100)], $this->centerCentsOn($entryId, 'credit'));
    }

    /**
     * Rozpuštění vybírá přijatou fakturu podle data nákladu, ne podle data zápisu: faktura
     * s nákladem v roce N zaúčtovaná až v lednu N+1, jejíž odklad zaúčtovala uzávěrka roku N
     * bez dimenzí, se rozpouští také bez dimenzí.
     */
    public function testLegacyDeferralIsRecognizedByCostDateWhenEntryIsPostedInNextYear(): void
    {
        Bootstrap::buildContainer()->get(AccountingPeriodRepository::class)
            ->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
        $purchase = $this->purchase('F2-381-LEGACY-DATE', [[1_000.00, 210.00]]);
        $this->db->pdo()->prepare('UPDATE purchase_invoice_items SET accrual_from = ?, accrual_to = ? WHERE purchase_invoice_id = ?')
            ->execute([self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30', $purchase]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->centerType => $this->fve], []);
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $purchase,
            $this->posting->buildFromPurchaseInvoice($this->supplierId, $purchase),
            ['entry_date' => (self::YEAR + 1) . '-01-10', 'posted_by' => $this->userId]);
        $deferred = AccrualDeferralService::deferredAmount(1_000.00, self::YEAR . '-07-01', (self::YEAR + 1) . '-06-30', self::YEAR . '-12-31');
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")->execute([$this->periodId()]);
        $this->posting->postDocument($this->supplierId, 'prepaid_expense_accrual', \MyInvoice\Service\Accounting\Closing\ClosingSourceId::prepaidExpenseAccrual($this->periodId()), [
            ['account_code' => '381', 'side' => 'debit', 'amount' => $deferred],
            ['account_code' => '518', 'side' => 'credit', 'amount' => $deferred],
        ], ['entry_date' => self::YEAR . '-12-31', 'posted' => true, 'posted_by' => $this->userId, 'allow_closing_period' => true]);

        $release = $this->releaseLines('prepaidExpenseReleaseForPeriod', [self::YEAR . '-12-31', (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31']);

        self::assertCount(1, $release['lines']);
        self::assertEqualsWithDelta($deferred, $release['lines'][0]['amount'], 0.001);
        self::assertArrayNotHasKey('dimensions', $release['lines'][0], 'Starý odklad bez dimenzí se rozpouští bez dimenzí i u faktury zaúčtované až v dalším roce.');
    }

    // ── pomocné ──────────────────────────────────────────────────────────────

    /** Zaúčtuje doklad se střediskem zadaným jen na nákladovém řádku zápisu (ruční zápis). */
    private function postPurchaseWithExpenseCenter(int $purchaseId, int $center): int
    {
        $lines = $this->posting->buildFromPurchaseInvoice($this->supplierId, $purchaseId);
        foreach ($lines as &$line) {
            if (str_starts_with((string) $line['account_code'], '5')) {
                $line['dimensions'] = [$this->centerType => $center];
            }
        }
        unset($line);
        return $this->posting->postDocument($this->supplierId, 'purchase_invoice', $purchaseId, $lines,
            ['entry_date' => self::YEAR . '-06-15', 'posted_by' => $this->userId]);
    }

    /** Krok uzávěrky „Časové rozlišení nákladů" nad uzavíraným obdobím; vrací id zápisu odkladu. */
    private function runPrepaidAccrual(): int
    {
        $periodId = $this->periodId();
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")->execute([$periodId]);
        $stmt = $this->db->pdo()->prepare('SELECT row_version FROM accounting_periods WHERE id = ?');
        $stmt->execute([$periodId]);
        $run = $this->closing()->runPrepaidExpenseAccrual($this->supplierId, $periodId, (int) $stmt->fetchColumn(), ['user_id' => $this->userId, 'posted_by' => $this->userId]);
        self::assertNotNull($run['entry_id']);
        return (int) $run['entry_id'];
    }

    /**
     * Tranše rozpuštění, jak ji openNext zaúčtuje (privátní výpočet uzávěrky).
     *
     * @param list<string> $args
     * @return array{by_account:array<string,float>, lines:list<array<string,mixed>>, total:float}
     */
    private function releaseLines(string $method, array $args): array
    {
        $closing = $this->closing();
        $reflection = new \ReflectionMethod($closing, $method);
        return $reflection->invoke($closing, $this->supplierId, ...$args);
    }

    /** @return list<int> zakázky na nákladových řádcích zápisu */
    private function projectsOn(int $entryId): array
    {
        $out = [];
        foreach ($this->assignments->entryLineDimensions($this->supplierId, $entryId) as $dims) {
            if (isset($dims[$this->projectType])) {
                $out[] = $dims[$this->projectType];
            }
        }
        return $out;
    }

    /** @return array<string,int> „účet|středisko" => haléře na dané straně nákladových řádků */
    private function centerCentsOn(int $entryId, string $side): array
    {
        $dims = $this->assignments->entryLineDimensions($this->supplierId, $entryId);
        $out = [];
        foreach ($this->journal->linesForEntry($entryId, $this->supplierId) as $line) {
            $code = $this->code((int) $line['account_id']);
            if (!str_starts_with($code, '5') || (string) $line['side'] !== $side) {
                continue;
            }
            $k = $code . '|' . ($dims[(int) $line['id']][$this->centerType] ?? '-');
            $out[$k] = ($out[$k] ?? 0) + (int) round((float) $line['amount'] * 100);
        }
        ksort($out);
        return $out;
    }

    /** @return array<string,int> „účet|středisko:podíl,…" => haléře na dané straně nákladových řádků (rozpad střediska) */
    private function centerSplitCentsOn(int $entryId, string $side): array
    {
        $lines = $this->journal->linesForEntry($entryId, $this->supplierId);
        $splits = $this->assignments->lineSplits($this->supplierId, array_map(static fn (array $l): int => (int) $l['id'], $lines));
        $out = [];
        foreach ($lines as $line) {
            $code = $this->code((int) $line['account_id']);
            if (!str_starts_with($code, '5') || (string) $line['side'] !== $side) {
                continue;
            }
            $shares = $splits[(int) $line['id']][$this->centerType] ?? [];
            ksort($shares);
            $parts = [];
            foreach ($shares as $valueId => $share) {
                $parts[] = $valueId . ':' . round((float) $share, 4);
            }
            $k = $code . '|' . ($parts === [] ? '-' : implode(',', $parts));
            $out[$k] = ($out[$k] ?? 0) + (int) round((float) $line['amount'] * 100);
        }
        ksort($out);
        return $out;
    }

    /** @return array<string,mixed> */
    private function checkOf(string $key): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM accounting_periods WHERE id = ?');
        $stmt->execute([$this->periodId()]);
        $checks = $this->closing()->buildChecks($this->supplierId, (array) $stmt->fetch(\PDO::FETCH_ASSOC), null, null, 50, [$key]);
        self::assertCount(1, $checks);
        return $checks[0];
    }

    private function closing(): \MyInvoice\Service\Accounting\Closing\ClosingService
    {
        return Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Closing\ClosingService::class);
    }

    private function periodId(): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM accounting_periods WHERE supplier_id = ? AND fiscal_year = ?');
        $stmt->execute([$this->supplierId, self::YEAR]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,mixed> */
    private function unmappedCheck(): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM accounting_periods WHERE id = ?');
        $stmt->execute([$this->periodId()]);
        $checks = $this->closing()->buildChecks($this->supplierId, (array) $stmt->fetch(\PDO::FETCH_ASSOC), null, null, 50, ['dimension_account_unmapped']);
        self::assertCount(1, $checks);
        return $checks[0];
    }

    private function enableDriving(bool $withMap = true): void
    {
        $type = $this->dimensions->updateType($this->supplierId, $this->centerType, ['drives_accounts' => true]);
        self::assertTrue($type['drives_accounts']);
        self::assertSame('5, 6', $type['drives_accounts_mask']);
        if ($withMap) {
            $this->map->saveForValue($this->supplierId, $this->fve, [['synthetic_code' => '518', 'analytic_code' => '518.100']], $this->userId);
            $this->map->saveForValue($this->supplierId, $this->office, [['synthetic_code' => '518', 'analytic_code' => '518.200']], $this->userId);
        }
    }

    private function analytic(string $code, string $parentCode): void
    {
        $pdo = $this->db->pdo();
        $parent = $pdo->prepare('SELECT id, account_type, normal_side, tax_deductibility FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?');
        $parent->execute([$this->supplierId, $parentCode]);
        $p = $parent->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($p, 'Syntetika ' . $parentCode . ' v osnově chybí.');
        $pdo->prepare(
            'INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id, is_active, tax_deductibility)
             VALUES (?, ?, ?, ?, ?, 0, ?, 1, ?)
             ON DUPLICATE KEY UPDATE is_active = 1'
        )->execute([$this->supplierId, $code, 'Analytika ' . $code, $p['account_type'], $p['normal_side'], (int) $p['id'], $p['tax_deductibility']]);
    }

    private function value(int $typeId, string $code): int
    {
        return (int) $this->dimensions->createValue($this->supplierId, $typeId, ['code' => $code, 'name' => 'Hodnota ' . $code])['id'];
    }

    /** @return array<string,int> kód nákladového účtu => haléře (MD) */
    private function expenseCents(int $entryId): array
    {
        $out = [];
        foreach ($this->journal->linesForEntry($entryId, $this->supplierId) as $line) {
            $code = $this->code((int) $line['account_id']);
            if (!str_starts_with($code, '5')) {
                continue;
            }
            $out[$code] = ($out[$code] ?? 0) + (int) round((float) $line['amount'] * 100);
        }
        ksort($out);
        return $out;
    }

    private function code(int $accountId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT account_code FROM chart_of_accounts WHERE id = ?');
        $stmt->execute([$accountId]);
        return (string) $stmt->fetchColumn();
    }

    /**
     * @param array<int,int> $dims
     * @return array<int,int>
     */
    private static function sorted(array $dims): array
    {
        ksort($dims);
        return $dims;
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
        $base = round(array_sum(array_column($items, 0)), 2);
        $vat = round(array_sum(array_column($items, 1)), 2);
        $issue = self::YEAR . '-06-15';
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date, due_date,
                 received_at, currency_id, reverse_charge, vendor_snapshot, total_without_vat, total_vat,
                 total_with_vat, status, vat_classification_code, vat_deduction, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, 0, "{}", ?, ?, ?, "received", "40", "full", ?)'
        )->execute([
            $this->supplierId, $vendorId, $number, $issue, $issue, $issue, $issue, $this->currencyId,
            $base, $vat, round($base + $vat, 2), $this->userId,
        ]);
        $id = (int) $pdo->lastInsertId();
        foreach ($items as $i => [$itemBase, $itemVat]) {
            $pdo->prepare(
                "INSERT INTO purchase_invoice_items
                    (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                     vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
                 VALUES (?, 'Položka', 1, 'ks', ?, ?, 21.00, ?, ?, ?, ?)"
            )->execute([$id, $itemBase, $this->vatRateId, $itemBase, $itemVat, round($itemBase + $itemVat, 2), $i]);
        }
        return $id;
    }
}
