<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\DepreciationEntryRepository;
use MyInvoice\Service\Accounting\Assets\AssetService;
use MyInvoice\Service\Accounting\Assets\DepreciationPostingService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Tax\Return\BookedAccountingDepreciation;
use MyInvoice\Service\Tax\Return\DppoReturnCalculator;
use MyInvoice\Service\Tax\Return\DppoReturnDataProvider;
use MyInvoice\Service\Tax\Return\TaxReturnException;
use MyInvoice\Service\Tax\Return\TaxReturnService;
use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Rozdíl účetních a daňových odpisů (ř. 50/150) po kartách majetku. Firma převedená
 * z jiného programu má účetní odpisy roku v převzatém deníku jako ruční zápisy na 551
 * s inventárním číslem karty (bez řádků odpisů karet) a karty s počátečním stavem
 * (`opening_*`) i s měsíci běžného roku. Přiznání je bere z knih po kartách, projekce
 * uzávěrky k nim nepřičte účetní odpis roku, který převzatý deník pokrývá, karty
 * v konceptu a 551 mimo karty jsou neutrální. Firma, která odpisuje modulem majetku,
 * dostane stejná čísla jako dřív.
 */
#[Group('integration')]
final class DppoTakenOverDepreciationTest extends TestCase
{
    private const YEAR = 2026;

    private Connection $db;
    private DppoReturnDataProvider $provider;
    private AssetService $assets;
    private DepreciationPostingService $depreciation;
    private AccountingPeriodRepository $periods;
    private PostingService $posting;
    private ChartOfAccountsSeeder $seeder;
    private TaxReturnService $returns;
    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private int $czId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->provider = $container->get(DppoReturnDataProvider::class);
            $this->assets = $container->get(AssetService::class);
            $this->depreciation = $container->get(DepreciationPostingService::class);
            $this->periods = $container->get(AccountingPeriodRepository::class);
            $this->posting = $container->get(PostingService::class);
            $this->seeder = $container->get(ChartOfAccountsSeeder::class);
            $this->returns = $container->get(TaxReturnService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->czId === 0 || $this->currencyId === 0 || $this->vatRateId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }
        $pdo->beginTransaction();
        $this->inTx = true;
        $this->company(self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $this->post(self::YEAR . '-03-15', [['311', 'debit', 1000000], ['602', 'credit', 1000000]]);
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

    public function testTakenOverJournalDepreciationDrivesLines50And150AndProjection(): void
    {
        // A: účetní = daňové (by_tax), 2. skupina rovnoměrně, VC 1 200 000, zařazení 6/2023.
        //    Daňově odepsáno 2023-2025 (132 000 + 2 × 267 000), účetní plán původního programu
        //    20 000 měsíčně do 12/2026 (42 měsíců, 840 000): účetně zaostává o 66 000.
        $this->card('PREV-A', 1200000, '2023-06-15', 'by_tax', 3, 666000, 42, 840000, null);
        // B: účetně rovnoměrně 36 měsíců (10 000), 1. skupina, VC 360 000, zařazení 12/2024;
        //    daňový odpis 2026 (144 000) už potvrzený.
        $b = $this->card('PREV-B', 360000, '2024-12-10', 'straight_line', 2, 216000, 24, 240000, 36, 1);
        $this->db->pdo()->prepare(
            "INSERT INTO depreciation_entries (supplier_id, asset_id, kind, fiscal_year, amount, full_amount, residual_value_end, status)
             VALUES (?, ?, 'tax', ?, 144000, 144000, 0, 'confirmed')"
        )->execute([$this->supplierId, $b, self::YEAR]);
        // C: karta v konceptu, odpisy jen v deníku.
        $this->assets->create($this->supplierId, [
            'inventory_number' => 'PREV-C', 'name' => 'Koncept', 'input_price' => 50000.00,
            'acquisition_date' => '2025-01-10', 'put_into_use_date' => '2025-01-10', 'status' => 'draft',
            'tax_method' => 'straight', 'tax_group' => 1, 'acc_useful_life_months' => 50,
        ], ['user_id' => $this->userId]);

        // Převzatý deník: měsíční odpisy 551/082 s inventárním číslem karty, k tomu ruční 551 mimo karty.
        $this->monthly(self::YEAR, 1, 12, ['PREV-A' => 20000, 'PREV-B' => 10000, 'PREV-C' => 1000]);
        $this->post(self::YEAR . '-12-31', [['551', 'debit', 5000], ['082', 'credit', 5000]]);

        $plan = $this->depreciation->previewYear($this->supplierId, self::YEAR);
        self::assertSame(0.0, $plan['pending_accounting'], 'Rok pokrytý převzatým deníkem se účetně znovu neodpisuje.');
        self::assertSame(267000.0, $plan['pending_tax']);

        $booked = $this->booked(self::YEAR . '-01-01', self::YEAR . '-12-31');
        self::assertSame([360000.0, 0.0, 360000.0, 377000.0, 5000.0], [$booked['total'], $booked['module'], $booked['taken_over'], $booked['journal_551'], $booked['outside_cards']]);
        self::assertSame(['assets' => 1, 'amount' => 12000.0, 'taken_over' => 0.0], $booked['drafts']);
        self::assertSame(0, $booked['estimated']['assets']);

        $data = $this->provider->gather($this->supplierId, self::YEAR);
        self::assertSame(['tax' => 144000.0, 'accounting' => 360000.0], $data['depreciation'], 'Koncept C ani 551 mimo karty se nepočítají.');
        self::assertSame(['accounting' => 0.0, 'tax' => 267000.0], $data['closing_projection']['depreciation']);
        $warnings = implode("\n", $data['depreciation_warnings']);
        self::assertStringContainsString('Karty majetku v konceptu (1) se neodpisují, jejich účetní odpisy v deníku jsou 12 000,00 Kč', $warnings);
        self::assertStringContainsString('Obrat účtu 551 mimo odpisy karet majetku je 5 000,00 Kč', $warnings);

        $result = (new DppoReturnCalculator())->compute($data, [], TaxConstants::forYear(self::YEAR));
        $lines = array_column($result['lines'], 'value', 'line');
        self::assertSame(216000.0, $lines[50] ?? 0.0, 'Účetní 360 000 − daňové 144 000 (zaúčtované).');
        self::assertSame(0.0, $lines[150] ?? 0.0);
        // Projekce: daňové 144 000 + 267 000 = 411 000 proti účetním 360 000 → ř. 150 51 000.
        $projection = $result['projection'];
        self::assertNotNull($projection);
        self::assertSame(round($data['vh'], 2), $projection['vh_projected'], 'Žádný účetní odpis navíc ve VH.');
        self::assertSame((float) ($lines[70] ?? 0) - 216000.0, (float) $projection['projected_increases']);
        self::assertSame((float) ($lines[170] ?? 0) + 51000.0, (float) $projection['projected_decreases']);

        // Zaúčtování roku: jen daňový odpis karty A, účetní odpis se nezaúčtuje.
        $this->depreciation->bookYear($this->supplierId, self::YEAR, ['posted_by' => $this->userId]);
        self::assertSame(0, $this->moduleEntries());
        $after = $this->provider->gather($this->supplierId, self::YEAR);
        self::assertSame(['tax' => 411000.0, 'accounting' => 360000.0], $after['depreciation']);
        $afterLines = $this->lines($after);
        self::assertSame(51000.0, $afterLines[150] ?? 0.0, 'Po zaúčtování stejný rozdíl jako v projekci.');
        self::assertSame(0.0, $afterLines[50] ?? 0.0);

        // Zaostávání karty A (93 000) dožene první nepokrytý rok.
        self::assertSame(480000.0, $this->depreciation->previewYear($this->supplierId, self::YEAR + 1)['planned_accounting'],
            'A: 267 000 + 93 000, B: zbylých 12 měsíců po 10 000.');
    }

    /**
     * Nehmotný majetek „daňový = účetní" (§ 32a) z POHODY: daňový plán začíná až po počátečním
     * stavu, daňový odpis převzatých měsíců je proto jejich účetní odpis a rozdíl je nulový.
     */
    public function testByAccountingIntangibleTakenOverMonthsAreAlsoTaxDepreciation(): void
    {
        $this->assets->create($this->supplierId, [
            'inventory_number' => 'SW-01', 'name' => 'Software', 'kind' => 'intangible', 'input_price' => 360000.00,
            'acquisition_date' => '2024-03-10', 'put_into_use_date' => '2024-03-10', 'status' => 'in_use',
            'tax_method' => 'by_accounting', 'acc_method' => 'straight_line', 'acc_useful_life_months' => 35,
            'opening_tax_years' => 0, 'opening_tax_amount' => 0,
            'opening_acc_months' => 33, 'opening_acc_amount' => 340000,
        ], ['user_id' => $this->userId]);
        $this->monthly(self::YEAR, 1, 12, ['SW-01' => 10000], '551', '073');

        $plan = $this->depreciation->previewYear($this->supplierId, self::YEAR);
        self::assertSame([0.0, 0.0], [$plan['pending_accounting'], $plan['pending_tax']], 'Rok 2026 je celý v počátečním stavu.');
        $data = $this->provider->gather($this->supplierId, self::YEAR);
        self::assertSame(['tax' => 120000.0, 'accounting' => 120000.0], $data['depreciation']);
        self::assertSame(120000.0, $data['depreciation_by_group']['intangible']);
        $lines = $this->lines($data);
        self::assertSame([0.0, 0.0], [$lines[50] ?? 0.0, $lines[150] ?? 0.0]);
        self::assertSame([], $data['depreciation_warnings']);
    }

    /** Hospodářský rok 7/2025-6/2026: převzaté měsíce se počítají podle hranic období. */
    public function testFiscalYearTakesOverMonthsOfThePeriod(): void
    {
        $this->company(2025, '2025-07-01', '2026-06-30');
        $this->card('HR-01', 600000, '2024-07-10', 'straight_line', 0, 0, 23, 240000, 60, 2);
        $this->monthly(2025, 7, 12, ['HR-01' => 10000]);
        $this->monthly(2026, 1, 6, ['HR-01' => 10000]);
        $this->monthly(2026, 7, 7, ['HR-01' => 10000]);

        $booked = $this->booked('2025-07-01', '2026-06-30');
        self::assertSame([120000.0, 120000.0, 0.0], [$booked['total'], $booked['taken_over'], $booked['outside_cards']]);
        self::assertSame(120000.0, $this->provider->gather($this->supplierId, 2025)['depreciation']['accounting']);
        self::assertSame(0.0, $this->depreciation->previewYear($this->supplierId, 2025)['pending_accounting']);
    }

    /**
     * Převod z PREMIER / Money S3 zapisuje účetní řádek roku „zaúčtovaný převzatým deníkem“
     * vedle zápisů deníku na 551: účetní odpis se smí započítat jen jednou.
     */
    public function testMigratedAccountingRowIsNotCountedNextToItsJournal(): void
    {
        $id = $this->card('PREV-D', 120000, '2024-01-15', 'straight_line', 2, 39900, 23, 46000, 60);
        (new DepreciationEntryRepository($this->db))->upsert([
            'supplier_id' => $this->supplierId, 'asset_id' => $id, 'kind' => 'accounting', 'fiscal_year' => self::YEAR,
            'amount' => 24000.0, 'full_amount' => 24000.0, 'residual_value_end' => 50000.0, 'is_paused' => false, 'is_half' => false,
            'months_count' => 12, 'detail' => json_encode(['journal' => DepreciationEntryRepository::MIGRATED_JOURNAL, 'program' => 'PREMIER']),
            'status' => 'posted',
        ]);
        $this->post(self::YEAR . '-12-31', [['551', 'debit', 24000], ['082', 'credit', 24000]]);

        $data = $this->provider->gather($this->supplierId, self::YEAR);
        self::assertSame(24000.0, $data['depreciation']['accounting']);
        self::assertSame([], $data['depreciation_warnings']);
    }

    /** Firma bez převodu: odpisy účtuje modul majetku, čísla zůstávají ze zaúčtovaných řádků. */
    public function testModuleOnlyCompanyKeepsPostedRows(): void
    {
        $this->moduleCard('MOD-001');
        $this->depreciation->bookYear($this->supplierId, self::YEAR, ['posted_by' => $this->userId]);
        self::assertSame(['accounting' => 22000.0, 'tax' => 13200.0], $this->rowSums(), '11 měsíců × 2 000; 11 % z 120 000.');

        $data = $this->provider->gather($this->supplierId, self::YEAR);
        self::assertSame(['tax' => 13200.0, 'accounting' => 22000.0], $data['depreciation']);
        self::assertSame([], $data['depreciation_warnings']);
        $booked = $this->booked(self::YEAR . '-01-01', self::YEAR . '-12-31');
        self::assertSame([22000.0, 0.0, 0.0, 0.0], [$booked['module'], $booked['evidence'], $booked['taken_over'], $booked['journal_551']]);
        self::assertSame(8800.0, $this->lines($data)[50] ?? 0.0);
    }

    /** Ruční likvidace se zůstatkovou cenou na 551 (firma bez převodu) rozdíl odpisů nemění. */
    public function testManualLiquidationResidualOn551DoesNotChangeLine50(): void
    {
        $this->moduleCard('MOD-002');
        $this->depreciation->bookYear($this->supplierId, self::YEAR, ['posted_by' => $this->userId]);
        $before = $this->lines($this->provider->gather($this->supplierId, self::YEAR));

        $this->post(self::YEAR . '-12-31', [['551', 'debit', 98000], ['082', 'credit', 98000]]);
        $data = $this->provider->gather($this->supplierId, self::YEAR);
        self::assertSame(22000.0, $data['depreciation']['accounting']);
        self::assertSame($before[50] ?? 0.0, $this->lines($data)[50] ?? 0.0);
        self::assertStringContainsString('98 000,00 Kč', implode("\n", $data['depreciation_warnings']));
    }

    /** Storno zápisu odpisu modulu: účetní odpis v knihách už není. */
    public function testReversedModuleEntryIsNotAccountingDepreciation(): void
    {
        $this->moduleCard('MOD-003');
        $this->depreciation->bookYear($this->supplierId, self::YEAR, ['posted_by' => $this->userId]);
        $entry = $this->db->pdo()->prepare("SELECT id FROM journal_entries WHERE supplier_id = ? AND source_type = 'depreciation'");
        $entry->execute([$this->supplierId]);
        $this->posting->reverse($this->supplierId, (int) $entry->fetchColumn(), ['entry_date' => self::YEAR . '-12-31', 'posted_by' => $this->userId]);

        $data = $this->provider->gather($this->supplierId, self::YEAR);
        self::assertSame(['tax' => 13200.0, 'accounting' => 0.0], $data['depreciation']);
        self::assertSame(13200.0, $this->lines($data)[150] ?? 0.0);
    }

    /** Vyřazení v modulu: odpis roku vyřazení je účetní odpis, zůstatková cena na 551 ne. */
    public function testDisposalYearDepreciationWithoutResidual(): void
    {
        $id = $this->moduleCard('MOD-004');
        $this->assets->dispose($this->supplierId, $id, ['date' => self::YEAR . '-06-30', 'type' => 'liquidated'], ['user_id' => $this->userId, 'posted_by' => $this->userId]);
        $rows = $this->rowSums();
        self::assertSame(10000.0, $rows['accounting'], 'Odpis února až června 5 × 2 000.');

        $data = $this->provider->gather($this->supplierId, self::YEAR);
        self::assertSame(['tax' => $rows['tax'], 'accounting' => 10000.0], $data['depreciation']);
        $residual = array_column($data['disposals'], null, 'inventory_number')['MOD-004']['book_residual_value'];
        self::assertSame(110000.0, $residual, 'Zůstatková cena likvidace jde do můstku ZC.');
        self::assertSame([], $data['depreciation_warnings']);
    }

    /** Storno převzatého zápisu se přiřadí ke kartě podle původního zápisu, ne podle čísla „STORNO …“. */
    public function testReversalOfTakenOverEntryFollowsOriginalCard(): void
    {
        $this->card('PREV-S', 360000, '2024-12-10', 'straight_line', 2, 216000, 24, 240000, 36, 1);
        $ids = [];
        for ($month = 1; $month <= 12; $month++) {
            $date = date('Y-m-t', (int) strtotime(sprintf('%d-%02d-01', self::YEAR, $month)));
            $ids[$month] = $this->post($date, [['551', 'debit', 10000], ['082', 'credit', 10000]], 'PREV-S');
        }
        $this->posting->reverse($this->supplierId, $ids[3], ['entry_date' => self::YEAR . '-04-15', 'posted_by' => $this->userId]);

        $booked = $this->booked(self::YEAR . '-01-01', self::YEAR . '-12-31');
        self::assertSame([110000.0, 110000.0, 0.0], [$booked['total'], $booked['taken_over'], $booked['outside_cards']]);
        self::assertSame([], $this->provider->gather($this->supplierId, self::YEAR)['depreciation_warnings']);
    }

    /** Zůstatková cena v zápisu vyřazení (D 022, MD 082) ve stejný den i jiný den měsíce vyřazení. */
    public function testResidualInTakenOverDisposalEntryIsNotDepreciation(): void
    {
        foreach (['2026-06-30', '2026-06-24'] as $i => $disposalEntryDate) {
            $number = 'VYR-0' . ($i + 1);
            $this->disposedCard($number);
            $this->monthly(self::YEAR, 1, 6, [$number => 2000]);
            $this->post($disposalEntryDate, [['082', 'debit', 108000], ['022', 'credit', 108000], ['551', 'debit', 12000], ['022', 'credit', 12000]], $number);
        }

        $booked = $this->booked(self::YEAR . '-01-01', self::YEAR . '-12-31');
        self::assertSame([24000.0, 24000.0, 24000.0, 0.0], [$booked['total'], $booked['taken_over'], $booked['residuals'], $booked['outside_cards']]);
        self::assertSame([], $booked['disposals_unsplit']['assets']);
    }

    /** Odpis v den vyřazení vyšší než ZC karty bez zápisu vyřazení není zůstatková cena. */
    public function testDepreciationOnDisposalDayIsNotMistakenForResidual(): void
    {
        $this->disposedCard('VYR-03');
        $this->monthly(self::YEAR, 1, 6, ['VYR-03' => 2000]);
        $this->post('2026-06-30', [['551', 'debit', 15000], ['082', 'credit', 15000]], 'VYR-03');

        $booked = $this->booked(self::YEAR . '-01-01', self::YEAR . '-12-31');
        self::assertSame([27000.0, 0.0, 0.0], [$booked['total'], $booked['residuals'], $booked['outside_cards']]);
    }

    /** Zápis vyřazení, z jehož řádků ZC určit nejde (MD 551 / D 082, MD 082 / D 022), zůstane mimo karty. */
    public function testUnsplittableDisposalEntryStaysOutsideCards(): void
    {
        $this->disposedCard('VYR-04');
        $this->monthly(self::YEAR, 1, 6, ['VYR-04' => 2000]);
        $this->post('2026-06-30', [['551', 'debit', 12000], ['082', 'credit', 12000], ['082', 'debit', 120000], ['022', 'credit', 120000]], 'VYR-04');

        $booked = $this->booked(self::YEAR . '-01-01', self::YEAR . '-12-31');
        self::assertSame([12000.0, 12000.0], [$booked['total'], $booked['outside_cards']]);
        self::assertSame(['assets' => ['VYR-04'], 'amount' => 12000.0], $booked['disposals_unsplit']);
        $warnings = implode("\n", $this->provider->gather($this->supplierId, self::YEAR)['depreciation_warnings']);
        self::assertStringContainsString('Zápis vyřazení převzaté karty (VYR-04) má na účtu 551 12 000,00 Kč', $warnings);
        self::assertStringNotContainsString('mimo odpisy karet majetku je', $warnings);
    }

    /** Ručně zadaný historický majetek „účetní = daňový“ s počátečním stavem: odpisy jako dřív. */
    public function testManualHistoricalByTaxCardKeepsTaxAmounts(): void
    {
        $this->assets->create($this->supplierId, [
            'inventory_number' => 'HIST-01', 'name' => 'Auto', 'input_price' => 1157025.00,
            'acquisition_date' => '2025-09-30', 'put_into_use_date' => '2025-09-30', 'status' => 'in_use',
            'tax_method' => 'straight', 'tax_group' => 2, 'acc_method' => 'by_tax',
            'opening_tax_years' => 1, 'opening_tax_amount' => 127273, 'opening_acc_months' => 3, 'opening_acc_amount' => 57852,
        ], ['user_id' => $this->userId]);

        self::assertSame(257439.0, $this->depreciation->previewYear($this->supplierId, self::YEAR)['planned_accounting']);
        self::assertSame(257439.0, $this->depreciation->previewYear($this->supplierId, self::YEAR + 1)['planned_accounting']);
    }

    /** Rok celý v převzatém deníku, který modul přesto zaúčtoval: upozornění v podkladech i v kontrole 551. */
    public function testModuleEntryForTakenOverYearIsReportedAsDuplicate(): void
    {
        $id = $this->card('PREV-A', 1200000, '2023-06-15', 'by_tax', 3, 666000, 42, 840000, null);
        $this->monthly(self::YEAR, 1, 12, ['PREV-A' => 20000]);
        $asset = $this->db->pdo()->query('SELECT * FROM assets WHERE id = ' . $id)->fetch(\PDO::FETCH_ASSOC);
        $this->depreciation->postAccountingEntry($this->supplierId, $asset, ['fiscal_year' => self::YEAR, 'amount' => 93000.0, 'residual_end' => 267000.0], self::YEAR . '-12-31', ['posted_by' => $this->userId]);

        $data = $this->provider->gather($this->supplierId, self::YEAR);
        self::assertSame(333000.0, $data['depreciation']['accounting'], 'V knihách je odpis dvakrát.');
        self::assertStringContainsString('u karet PREV-A (93 000,00 Kč)', implode("\n", $data['depreciation_warnings']));
        $check = array_column($this->returns->getReturn($this->supplierId, self::YEAR, 'po', $this->userId)['prefinalize_check']['checks'], null, 'key')['depreciation_551'];
        self::assertFalse($check['ok']);
        self::assertSame([['inventory_number' => 'PREV-A', 'amount' => 93000.0]], $check['value']['duplicates']);
    }

    /** Karta v konceptu s odpisy na 551 blokuje finalizaci DPPO. */
    public function testDraftCardWith551BlocksFinalization(): void
    {
        $this->assets->create($this->supplierId, [
            'inventory_number' => 'KON-01', 'name' => 'Koncept', 'input_price' => 50000.00,
            'acquisition_date' => '2025-01-10', 'put_into_use_date' => '2025-01-10', 'status' => 'draft',
            'tax_method' => 'straight', 'tax_group' => 1, 'acc_useful_life_months' => 50,
        ], ['user_id' => $this->userId]);
        $this->takeOver($this->monthly(self::YEAR, 1, 12, ['KON-01' => 1000]));

        $state = $this->returns->getReturn($this->supplierId, self::YEAR, 'po', $this->userId);
        $check = array_column($state['prefinalize_check']['checks'], null, 'key')['draft_assets_551'];
        self::assertSame(['blocker', false, ['assets' => 1, 'amount' => 12000.0, 'taken_over' => 12000.0]], [$check['severity'], $check['ok'], $check['value']]);
        self::assertFalse($state['prefinalize_check']['can_finalize']);
        try {
            $this->returns->finalize($this->supplierId, self::YEAR, 'po', (int) $state['return']['row_version'], $this->userId);
            self::fail('Finalizace měla skončit chybou.');
        } catch (TaxReturnException $e) {
            self::assertSame('prefinalize_blocked', $e->errorCode);
            self::assertStringContainsString('Karty majetku v konceptu (1) mají v deníku roku účetní odpisy 12 000,00 Kč', $e->getMessage());
        }
    }

    /** Odhad převzatých měsíců bez zápisů karty: měsíce po zařazení, strop obratem 551 mimo karty. */
    public function testEstimateUsesMonthsAfterPutIntoUseAndAvailable551(): void
    {
        $this->card('ODH-01', 120000, '2025-12-10', 'straight_line', 0, 0, 12, 12000, 120, 2);
        $this->post(self::YEAR . '-12-31', [['551', 'debit', 12000], ['082', 'credit', 12000]]);
        $booked = $this->booked(self::YEAR . '-01-01', self::YEAR . '-12-31');
        self::assertSame([12000.0, 0.0], [$booked['total'], $booked['outside_cards']], '12 měsíců 2026 × 12 000 / 12.');
        self::assertSame(['assets' => 1, 'amount' => 12000.0], $booked['estimated']);
    }

    public function testEstimateIsCappedByAvailable551(): void
    {
        $this->card('ODH-02', 120000, '2025-12-10', 'straight_line', 0, 0, 12, 12000, 120, 2);
        $this->post(self::YEAR . '-12-31', [['551', 'debit', 6000], ['082', 'credit', 6000]]);
        self::assertSame(6000.0, $this->booked(self::YEAR . '-01-01', self::YEAR . '-12-31')['total']);
    }

    /** Bez převzatého deníku roku (žádný obrat 551 mimo karty) ani u plně odepsané karty se neodhaduje. */
    public function testNoEstimateWithoutTakenOverJournalOrForFullyDepreciatedCard(): void
    {
        $this->card('ODH-03', 120000, '2025-12-10', 'straight_line', 0, 0, 12, 12000, 120, 2);
        self::assertSame(['assets' => 0, 'amount' => 0.0], $this->booked(self::YEAR . '-01-01', self::YEAR . '-12-31')['estimated']);

        $this->card('ODH-04', 10000, '2025-12-10', 'straight_line', 0, 0, 12, 10000, 12, 2);
        $this->post(self::YEAR . '-12-31', [['551', 'debit', 5000], ['082', 'credit', 5000]]);
        $booked = $this->booked(self::YEAR . '-01-01', self::YEAR . '-12-31');
        self::assertSame(['assets' => 1, 'amount' => 5000.0], $booked['estimated'], 'Odhad jen u ODH-03, plně odepsaná ODH-04 bez odhadu.');
    }

    /** Ruční zápis, který jen nese stejné (krátké) číslo jako karta v konceptu, finalizaci neblokuje. */
    public function testManualEntryMatchingShortDraftNumberOnlyWarns(): void
    {
        $this->assets->create($this->supplierId, [
            'inventory_number' => '1', 'name' => 'Koncept', 'input_price' => 50000.00,
            'acquisition_date' => '2025-01-10', 'put_into_use_date' => '2025-01-10', 'status' => 'draft',
            'tax_method' => 'straight', 'tax_group' => 1, 'acc_useful_life_months' => 50,
        ], ['user_id' => $this->userId]);
        $this->post(self::YEAR . '-05-31', [['551', 'debit', 3000], ['082', 'credit', 3000]], '1');

        $state = $this->returns->getReturn($this->supplierId, self::YEAR, 'po', $this->userId);
        $check = array_column($state['prefinalize_check']['checks'], null, 'key')['draft_assets_551'];
        self::assertSame(['warning', false], [$check['severity'], $check['ok']]);
        self::assertTrue($state['prefinalize_check']['can_finalize']);
    }

    /** Záporný převzatý obrat konceptu (storno) i nezisková organizace: jen varování. */
    public function testNegativeDraftAmountAndNonprofitOnlyWarn(): void
    {
        $method = new \ReflectionMethod(\MyInvoice\Service\Tax\Return\PreFinalizeCheckService::class, 'checkDraftAssets551');
        $service = (new \ReflectionClass(\MyInvoice\Service\Tax\Return\PreFinalizeCheckService::class))->newInstanceWithoutConstructor();
        $booked = static fn (float $amount): array => ['drafts' => ['assets' => 1, 'amount' => $amount, 'taken_over' => $amount]];

        self::assertSame('blocker', $method->invoke($service, 'po', $booked(5000.0), false)['severity']);
        self::assertSame('warning', $method->invoke($service, 'po', $booked(-5000.0), false)['severity']);
        self::assertSame('warning', $method->invoke($service, 'po', $booked(5000.0), true)['severity']);
    }

    /** Zápis vyřazení s odpisem posledního měsíce (MD 551 / D 082) a ZC na 541: odpis zůstává. */
    public function testDepreciationInDisposalEntryWithResidualOn541StaysDepreciation(): void
    {
        $this->disposedCard('VYR-05');
        $this->monthly(self::YEAR, 1, 5, ['VYR-05' => 2000]);
        $this->post('2026-06-30', [['551', 'debit', 2000], ['082', 'credit', 2000], ['082', 'debit', 100000], ['022', 'credit', 100000],
            ['541', 'debit', 20000], ['022', 'credit', 20000]], 'VYR-05');

        $booked = $this->booked(self::YEAR . '-01-01', self::YEAR . '-12-31');
        self::assertSame([12000.0, 0.0, 0.0], [$booked['total'], $booked['residuals'], $booked['outside_cards']]);
        self::assertSame([], $booked['disposals_unsplit']['assets']);
    }

    /** Plně odepsaná karta s odpisem posledního měsíce v zápisu vyřazení není nerozdělitelná. */
    public function testFullyDepreciatedCardLastMonthInDisposalEntryIsDepreciation(): void
    {
        $id = $this->card('VYR-06', 120000, '2022-01-15', 'straight_line', 4, 90000, 53, 120000, 60);
        $this->db->pdo()->prepare("UPDATE assets SET status = 'disposed', disposal_date = '2026-06-30', disposal_type = 'liquidated' WHERE id = ?")->execute([$id]);
        $this->monthly(self::YEAR, 1, 5, ['VYR-06' => 2000]);
        $this->post('2026-06-30', [['551', 'debit', 2000], ['082', 'credit', 2000], ['082', 'debit', 120000], ['022', 'credit', 120000]], 'VYR-06');

        $booked = $this->booked(self::YEAR . '-01-01', self::YEAR . '-12-31');
        self::assertSame([12000.0, 0.0], [$booked['total'], $booked['outside_cards']]);
        self::assertSame([], $booked['disposals_unsplit']['assets']);
    }

    /** Storno převzatého zápisu vyřazení vrací i jeho zůstatkovou cenu: karta zůstane jen s odpisy. */
    public function testReversedDisposalEntryCancelsItsResidual(): void
    {
        $this->disposedCard('VYR-07');
        $this->monthly(self::YEAR, 1, 6, ['VYR-07' => 2000]);
        $entry = $this->post('2026-06-30', [['082', 'debit', 108000], ['022', 'credit', 108000], ['551', 'debit', 12000], ['022', 'credit', 12000]], 'VYR-07');
        $this->posting->reverse($this->supplierId, $entry, ['entry_date' => '2026-07-15', 'posted_by' => $this->userId]);

        $booked = $this->booked(self::YEAR . '-01-01', self::YEAR . '-12-31');
        self::assertSame([12000.0, 0.0, 0.0], [$booked['total'], $booked['residuals'], $booked['outside_cards']]);
    }

    /** Zápis vyřazení v měsíci po vyřazení se najde také. */
    public function testDisposalEntryInFollowingMonthIsFound(): void
    {
        $this->disposedCard('VYR-08');
        $this->monthly(self::YEAR, 1, 6, ['VYR-08' => 2000]);
        $this->post('2026-07-10', [['082', 'debit', 108000], ['022', 'credit', 108000], ['551', 'debit', 12000], ['022', 'credit', 12000]], 'VYR-08');

        $booked = $this->booked(self::YEAR . '-01-01', self::YEAR . '-12-31');
        self::assertSame([12000.0, 12000.0, 0.0], [$booked['total'], $booked['residuals'], $booked['outside_cards']]);
    }

    /** Kontrola obratu 551 bez účetního období počítá v kalendářním roce jako dřív. */
    public function testDepreciation551CheckWithoutPeriodUsesCalendarYear(): void
    {
        $year = self::YEAR + 1;
        $this->post($year . '-12-31', [['551', 'debit', 7000], ['082', 'credit', 7000]]);
        self::assertNull($this->periods->findByYear($this->supplierId, $year));
        $checks = Bootstrap::buildContainer()->get(\MyInvoice\Service\Tax\Return\PreFinalizeCheckService::class)
            ->run($this->supplierId, $year, 'po', [], ['podklady' => [], 'result' => [], 'warnings' => []]);
        $check = array_column($checks['checks'], null, 'key')['depreciation_551'];
        self::assertFalse($check['na'] ?? false);
        self::assertSame([7000.0, 0.0, 7000.0], [$check['value']['turnover'], $check['value']['accounting_entries'], $check['value']['diff']]);
    }

    private function disposedCard(string $number): int
    {
        $id = $this->card($number, 120000, '2022-01-15', 'straight_line', 4, 90000, 53, 106000, 60);
        $this->db->pdo()->prepare("UPDATE assets SET status = 'disposed', disposal_date = '2026-06-30', disposal_type = 'liquidated' WHERE id = ?")
            ->execute([$id]);
        return $id;
    }

    private function company(int $year, string $startsOn, string $endsOn): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id,
                                   taxpayer_type, ic, dic, accounting_mode)
             VALUES (?, "Zkušební 1", "Vzorov", "10000", ?, "odpisy-prevod@example.com", ?, ?, "po", "12345678", "CZ12345678", "double_entry")'
        )->execute(['Převzaté odpisy test s.r.o.', $this->czId, $this->currencyId, $this->vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $this->seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, $year, $startsOn, $endsOn);
    }

    private function moduleCard(string $number): int
    {
        $created = $this->assets->create($this->supplierId, [
            'inventory_number' => $number, 'name' => 'Stroj', 'input_price' => 120000.00,
            'acquisition_date' => self::YEAR . '-01-10', 'put_into_use_date' => self::YEAR . '-01-10', 'status' => 'in_use',
            'tax_method' => 'straight', 'tax_group' => 2, 'acc_useful_life_months' => 60,
        ], ['user_id' => $this->userId]);
        return (int) $created['asset']['id'];
    }

    private function card(
        string $number,
        float $price,
        string $inUse,
        string $accMethod,
        int $taxYears,
        float $taxAmount,
        int $accMonths,
        float $accAmount,
        ?int $lifeMonths,
        int $taxGroup = 2,
    ): int {
        $created = $this->assets->create($this->supplierId, [
            'inventory_number' => $number, 'name' => 'Karta ' . $number, 'input_price' => $price,
            'acquisition_date' => $inUse, 'put_into_use_date' => $inUse, 'status' => 'in_use',
            'tax_method' => 'straight', 'tax_group' => $taxGroup,
            'opening_tax_years' => $taxYears, 'opening_tax_amount' => $taxAmount,
            'opening_acc_months' => $accMonths, 'opening_acc_amount' => $accAmount,
            'acc_method' => $accMethod, 'acc_useful_life_months' => $lifeMonths,
        ], ['user_id' => $this->userId]);
        return (int) $created['asset']['id'];
    }

    /** @param array<string,int> $amounts inventární číslo => měsíční odpis */
    /** @return list<int> */
    private function monthly(int $year, int $from, int $to, array $amounts, string $expense = '551', string $accumulated = '082'): array
    {
        $ids = [];
        for ($month = $from; $month <= $to; $month++) {
            $date = date('Y-m-t', (int) strtotime(sprintf('%d-%02d-01', $year, $month)));
            foreach ($amounts as $number => $amount) {
                $ids[] = $this->post($date, [[$expense, 'debit', $amount], [$accumulated, 'credit', $amount]], (string) $number);
            }
        }
        return $ids;
    }

    /** Zápisy označí jako převzaté převodem z POHODY (mapa převodu). @param list<int> $ids */
    private function takeOver(array $ids): void
    {
        $stmt = $this->db->pdo()->prepare("INSERT INTO pohoda_import_map (supplier_id, kind, pohoda_key, target_id) VALUES (?, 'journal_entry', ?, ?)");
        foreach ($ids as $id) {
            $stmt->execute([$this->supplierId, 'test|' . $id, $id]);
        }
    }

    /** @return array<string,mixed> */
    private function booked(string $startsOn, string $endsOn): array
    {
        return (new BookedAccountingDepreciation($this->db))->forPeriod($this->supplierId, (int) substr($startsOn, 0, 4), $startsOn, $endsOn);
    }

    /** @return array<int,float> */
    private function lines(array $data): array
    {
        return array_column((new DppoReturnCalculator())->compute($data, [], TaxConstants::forYear((int) substr((string) $data['period']['starts_on'], 0, 4)))['lines'], 'value', 'line');
    }

    /** @return array{accounting:float,tax:float} */
    private function rowSums(): array
    {
        $rows = $this->db->pdo()->prepare('SELECT kind, SUM(amount) FROM depreciation_entries WHERE supplier_id = ? AND fiscal_year = ? GROUP BY kind');
        $rows->execute([$this->supplierId, self::YEAR]);
        $sums = array_map('floatval', $rows->fetchAll(\PDO::FETCH_KEY_PAIR));
        return ['accounting' => $sums['accounting'] ?? 0.0, 'tax' => $sums['tax'] ?? 0.0];
    }

    private function moduleEntries(): int
    {
        $journal = $this->db->pdo()->prepare("SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ? AND source_type = 'depreciation'");
        $journal->execute([$this->supplierId]);
        return (int) $journal->fetchColumn();
    }

    /** @param list<array{0:string,1:string,2:float|int}> $lines */
    private function post(string $date, array $lines, ?string $documentNo = null): int
    {
        $pdo = $this->db->pdo();
        $period = $this->periods->findForDate($this->supplierId, $date);
        $pdo->prepare(
            "INSERT INTO journal_entries (supplier_id, period_id, entry_date, document_no, posted_at, source_type) VALUES (?, ?, ?, ?, NOW(), 'manual')"
        )->execute([$this->supplierId, $period !== null ? (int) $period['id'] : $this->periodId, $date, $documentNo]);
        $entryId = (int) $pdo->lastInsertId();
        $ids = $pdo->prepare('SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?');
        $ins = $pdo->prepare('INSERT INTO journal_entry_lines (entry_id, supplier_id, account_id, side, amount) VALUES (?, ?, ?, ?, ?)');
        foreach ($lines as [$code, $side, $amount]) {
            $ids->execute([$this->supplierId, $code]);
            $ins->execute([$entryId, $this->supplierId, (int) $ids->fetchColumn(), $side, $amount]);
        }
        return $entryId;
    }
}
