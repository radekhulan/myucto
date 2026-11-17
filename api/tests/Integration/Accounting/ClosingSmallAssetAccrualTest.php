<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\SmallAssetRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingException;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;
use MyInvoice\Service\Accounting\PostingService;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Integrační testy Task 11: časové rozlišení drobného majetku (381) jako VOLITELNÁ
 * účetní politika (§7 ZoÚ). Ověřuje 3 režimy náhledu (none/pro_rata/flat_pct),
 * idempotentní zaúčtování MD 381 / D 501 + rozpuštění v N+1 (open_next), a klíčový
 * předpoklad Tasku 10 — že zaúčtovaný source_type='small_asset_accrual' vstupuje do
 * VH před zdaněním (profitBeforeTax). Izolovaný supplier v transakci s rollbackem.
 */
#[Group('integration')]
final class ClosingSmallAssetAccrualTest extends TestCase
{
    // 2091 = nepřestupný rok (365 dnů) → čisté pro_rata podíly.
    private const YEAR = 2091;
    /** Analytika, na kterou výchozí osnova vede položky drobného majetku; odklad faktury jde z ní. */
    private const DM = '501.900';
    private const STARTS_ON = self::YEAR . '-01-01';
    private const ENDS_ON = self::YEAR . '-12-31';

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
    private int $czId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db          = $container->get(Connection::class);
            $this->posting     = $container->get(PostingService::class);
            $this->closing     = $container->get(ClosingService::class);
            $this->periods     = $container->get(AccountingPeriodRepository::class);
            $this->journal     = $container->get(JournalEntryRepository::class);
            $this->smallAssets = $container->get(SmallAssetRepository::class);
            $seeder            = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId        = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->currencyId === 0 || $vatRateId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $stmt = $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, ?, ?, ?)'
        );
        $stmt->execute(['DM accrual test s.r.o.', $this->czId, 'dm-accrual@example.com', $this->currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::STARTS_ON, self::ENDS_ON);
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

    // ── náhled — 3 režimy ──────────────────────────────────────────────────────

    public function testPreviewNoneDefersNothing(): void
    {
        $this->card('Notebook', self::YEAR . '-07-01', 36500.00);
        $preview = $this->closing->smallAssetAccrualPreview($this->supplierId, $this->periodId, 'none');
        self::assertSame('none', $preview['mode']);
        self::assertEqualsWithDelta(0.0, $preview['total'], 0.001);
        self::assertEqualsWithDelta(0.0, (float) $preview['items'][0]['deferred_amount'], 0.001);
        self::assertEqualsWithDelta(36500.00, $preview['cards_total'], 0.001);
    }

    public function testPreviewProRataDefersFutureUnusedPart(): void
    {
        // Pořízeno 1. 7. 2091 → 184 dnů užitku UPLYNULO do rozvahového dne (spotřeba období),
        // 181 dnů ZBÝVÁ za koncem roku → odloží se BUDOUCÍ část: 36 500 × 181/365 = 18 100.
        $this->card('Notebook', self::YEAR . '-07-01', 36500.00);
        $preview = $this->closing->smallAssetAccrualPreview($this->supplierId, $this->periodId, 'pro_rata');
        self::assertSame('pro_rata', $preview['mode']);
        self::assertSame(365, $preview['period_days']);
        self::assertEqualsWithDelta(18100.00, $preview['total'], 0.001);
        self::assertEqualsWithDelta(181 / 365, (float) $preview['items'][0]['fraction'], 0.0001);
        self::assertEqualsWithDelta(18100.00, (float) $preview['items'][0]['deferred_amount'], 0.001);
        // Součtový invariant: odložená (budoucí) + spotřebovaná (náklad období) = cena.
        $deferred = (float) $preview['total'];
        $consumed = round((float) $preview['cards_total'] - $deferred, 2);
        self::assertEqualsWithDelta(18400.00, $consumed, 0.001);
        self::assertEqualsWithDelta(36500.00, round($deferred + $consumed, 2), 0.001);
    }

    public function testPreviewProRataAcquiredOnFirstDayDefersNothing(): void
    {
        // Pořízeno 1. 1. → celý roční užitek UPLYNUL v tomto období, za rozvahovým dnem nic
        // → odloží se ≈ 0, celá cena je náklad období.
        $this->card('Notebook', self::YEAR . '-01-01', 36500.00);
        $preview = $this->closing->smallAssetAccrualPreview($this->supplierId, $this->periodId, 'pro_rata');
        self::assertEqualsWithDelta(0.00, $preview['total'], 0.001);
        self::assertEqualsWithDelta(0.00, (float) $preview['items'][0]['deferred_amount'], 0.001);
        self::assertEqualsWithDelta(0.0, (float) $preview['items'][0]['fraction'], 0.0001);
        // Součtový invariant: spotřebovaná = celá cena.
        $consumed = round((float) $preview['cards_total'] - (float) $preview['total'], 2);
        self::assertEqualsWithDelta(36500.00, $consumed, 0.001);
    }

    public function testPreviewProRataAcquiredOnLastDayDefersAlmostAll(): void
    {
        // Pořízeno 31. 12. → uplynul jen 1 den užitku, 364 dnů ZBÝVÁ za rozvahovým dnem
        // → odloží se téměř celá cena: 36 500 × 364/365 = 36 400.
        $this->card('Notebook', self::YEAR . '-12-31', 36500.00);
        $preview = $this->closing->smallAssetAccrualPreview($this->supplierId, $this->periodId, 'pro_rata');
        self::assertEqualsWithDelta(36400.00, $preview['total'], 0.001);
        self::assertEqualsWithDelta(36400.00, (float) $preview['items'][0]['deferred_amount'], 0.001);
        self::assertEqualsWithDelta(364 / 365, (float) $preview['items'][0]['fraction'], 0.0001);
        // Součtový invariant: spotřebovaná = zbytek ceny (1 den užitku).
        $consumed = round((float) $preview['cards_total'] - (float) $preview['total'], 2);
        self::assertEqualsWithDelta(100.00, $consumed, 0.001);
        self::assertEqualsWithDelta(36500.00, round((float) $preview['total'] + $consumed, 2), 0.001);
    }

    public function testPreviewFlatPct(): void
    {
        $this->card('Notebook', self::YEAR . '-07-01', 36500.00);
        $preview = $this->closing->smallAssetAccrualPreview($this->supplierId, $this->periodId, 'flat_pct', 50.0);
        self::assertSame('flat_pct', $preview['mode']);
        self::assertEqualsWithDelta(18250.00, $preview['total'], 0.001);
        self::assertEqualsWithDelta(50.0, (float) $preview['pct'], 0.001);
    }

    public function testPreviewFlatPctRequiresValidPercent(): void
    {
        $this->card('Notebook', self::YEAR . '-07-01', 36500.00);
        $this->expectException(ClosingException::class);
        $this->closing->smallAssetAccrualPreview($this->supplierId, $this->periodId, 'flat_pct', 150.0);
    }

    // ── zaúčtování — idempotence, podvojnost, mazání ───────────────────────────

    public function testRunPostsDoubleEntry381Over501AndIsIdempotent(): void
    {
        $this->card('Notebook', self::YEAR . '-07-01', 36500.00);
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        $r1 = $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'flat_pct', 50.0, $this->rv(), $this->meta(), 1000000.00);
        self::assertEqualsWithDelta(18250.00, (float) $r1['total'], 0.001);

        $entry = $this->journal->findBySource($this->supplierId, 'small_asset_accrual', ClosingSourceId::smallAssetAccrual($this->periodId));
        self::assertNotNull($entry, 'Časové rozlišení musí být zaúčtované se source small_asset_accrual/period_id.');
        $entryId = (int) $entry['id'];
        $lines = $this->entryLines($entryId);
        self::assertEqualsWithDelta(18250.00, $this->sideAmount($lines, '381', 'debit'), 0.001);
        self::assertEqualsWithDelta(18250.00, $this->sideAmount($lines, '501', 'credit'), 0.001);

        // Re-run se změnou režimu (oprava) → tentýž zápis, jiná částka (in-place rewrite).
        // pro_rata karty z 1. 7.: odloží se BUDOUCÍ část 36 500 × 181/365 = 18 100.
        $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'pro_rata', null, $this->rv(), $this->meta());
        $entry2 = $this->journal->findBySource($this->supplierId, 'small_asset_accrual', ClosingSourceId::smallAssetAccrual($this->periodId));
        self::assertSame($entryId, (int) $entry2['id'], 'Re-run nesmí vytvořit duplicitní zápis.');
        self::assertEqualsWithDelta(18100.00, $this->sideAmount($this->entryLines($entryId), '381', 'debit'), 0.001);

        // Přepnutí na none → nulový návrh maže zápis.
        $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'none', null, $this->rv(), $this->meta());
        self::assertNull(
            $this->journal->findBySource($this->supplierId, 'small_asset_accrual', ClosingSourceId::smallAssetAccrual($this->periodId)),
            'Režim none musí odložený zápis smazat.',
        );
    }

    public function testRunPersistsPolicyOntoPeriodNotFirm(): void
    {
        $this->card('Notebook', self::YEAR . '-07-01', 36500.00);
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'flat_pct', 40.0, $this->rv(), $this->meta(), 1000000.00);

        // Politika §DM patří na OBDOBÍ (per-period), ne na firmu.
        $policy = $this->periods->getAccrualPolicy($this->supplierId, $this->periodId);
        self::assertSame('flat_pct', $policy['small_asset_accrual_mode']);
        self::assertEqualsWithDelta(40.0, (float) $policy['small_asset_accrual_pct'], 0.001);

        // Firemní default se uzávěrkou NESMÍ přepsat (zůstává na seedu 'none').
        $stmt = $this->db->pdo()->prepare(
            'SELECT small_asset_accrual_mode, small_asset_accrual_pct FROM accounting_supplier_settings WHERE supplier_id = ?'
        );
        $stmt->execute([$this->supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        // Buď žádný řádek (uzávěrka firmu nezaložila), nebo firma zůstala na defaultu 'none'.
        if ($row !== false) {
            self::assertSame('none', (string) $row['small_asset_accrual_mode'], 'Uzávěrka nesmí přepsat firemní §DM default.');
            self::assertNull($row['small_asset_accrual_pct']);
        } else {
            self::assertFalse($row);
        }
    }

    /**
     * Klíčový invariant §DM: uzávěrka JEDNOHO období nesmí změnit politiku JINÉHO období.
     * Účetní scénář — FY(N) zkrácené první období s flat_pct 50 %, FY(N+1) bez odkladu (none).
     */
    public function testRunOnOnePeriodDoesNotAffectAnotherPeriod(): void
    {
        // Druhé (navazující) období — čerstvě založené zdědí firemní default (none).
        $nextYear = self::YEAR + 1;
        $nextPeriodId = $this->periods->create(
            $this->supplierId,
            $nextYear,
            $nextYear . '-01-01',
            $nextYear . '-12-31',
        );
        $before = $this->periods->getAccrualPolicy($this->supplierId, $nextPeriodId);
        self::assertSame('none', $before['small_asset_accrual_mode']);
        self::assertNull($before['small_asset_accrual_pct']);

        // Uzavři PRVNÍ období s flat_pct 50 %.
        $this->card('Notebook', self::YEAR . '-07-01', 36500.00);
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'flat_pct', 50.0, $this->rv(), $this->meta(), 1000000.00);

        // První období si drží flat_pct 50 %.
        $first = $this->periods->getAccrualPolicy($this->supplierId, $this->periodId);
        self::assertSame('flat_pct', $first['small_asset_accrual_mode']);
        self::assertEqualsWithDelta(50.0, (float) $first['small_asset_accrual_pct'], 0.001);

        // DRUHÉ období zůstalo netknuté (none) — a jeho pre-fill náhledu to reflektuje.
        $after = $this->periods->getAccrualPolicy($this->supplierId, $nextPeriodId);
        self::assertSame('none', $after['small_asset_accrual_mode'], 'Uzávěrka jednoho období nesmí měnit politiku jiného.');
        self::assertNull($after['small_asset_accrual_pct']);

        // Pre-fill náhledu druhého období (mode=null) vrací jeho VLASTNÍ politiku (none).
        $preview = $this->closing->smallAssetAccrualPreview($this->supplierId, $nextPeriodId);
        self::assertSame('none', $preview['mode']);
    }

    /**
     * Pre-fill náhledu (mode=null) reflektuje politiku ULOŽENOU na období (ne firmu):
     * po zaúčtování flat_pct 50 % vrací náhled bez režimu opět flat_pct 50 %.
     */
    public function testPreviewPrefillReflectsPeriodPolicy(): void
    {
        $this->card('Notebook', self::YEAR . '-07-01', 36500.00);
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'flat_pct', 50.0, $this->rv(), $this->meta(), 1000000.00);

        $preview = $this->closing->smallAssetAccrualPreview($this->supplierId, $this->periodId);
        self::assertSame('flat_pct', $preview['mode']);
        self::assertEqualsWithDelta(50.0, (float) $preview['pct'], 0.001);
        self::assertEqualsWithDelta(18250.00, $preview['total'], 0.001);
    }

    // ── rozpuštění v N+1 (open_next) ───────────────────────────────────────────

    public function testAccrualIsReleasedInNextPeriodOnOpenNext(): void
    {
        $this->card('Notebook', self::YEAR . '-07-01', 36500.00);
        $this->driveCloseWithAccrual('flat_pct', 50.0);

        // Defer zápis N přežil uzavření knih.
        $defer = $this->journal->findBySource($this->supplierId, 'small_asset_accrual', ClosingSourceId::smallAssetAccrual($this->periodId));
        self::assertNotNull($defer);

        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $open = $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        self::assertNotNull($open['small_asset_release_entry_id'], 'Open_next musí rozpustit odklad v N+1.');
        $release = $this->journal->findBySource($this->supplierId, 'small_asset_accrual', ClosingSourceId::smallAssetAccrualRelease($this->periodId));
        self::assertNotNull($release);
        $lines = $this->entryLines((int) $release['id']);
        // Zrcadlo defer zápisu: MD 501 / D 381 = náklad se objeví v N+1.
        self::assertEqualsWithDelta(18250.00, $this->sideAmount($lines, '501', 'debit'), 0.001);
        self::assertEqualsWithDelta(18250.00, $this->sideAmount($lines, '381', 'credit'), 0.001);
        self::assertSame((self::YEAR + 1) . '-01-01', (string) $release['entry_date']);
    }

    /**
     * Regrese: období, které odloží drobný majetek na 381, šlo NATRVALO zablokovat
     * proti znovuotevření. Open_next zrcadlí rozpouštěcí zápis (§DM release) do N+1
     * se source_id ve vysokém RELEASE pásmu a period_id = N+1. hasClosingEntries()
     * ho dřív počítala jako „vlastní uzávěrku N+1" → revert open_next hodil
     * invalid_status_transition a release v N+1 osiřel. Teď: revert projde a release
     * se smaže spolu s krokem.
     */
    public function testOpenNextRevertIsNotBlockedByOwnAccrualReleaseAndDeletesIt(): void
    {
        $this->card('Notebook', self::YEAR . '-07-01', 36500.00);
        $this->driveCloseWithAccrual('flat_pct', 50.0);
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        // Sanity: rozpouštěcí zápis v N+1 existuje před revertem.
        self::assertNotNull(
            $this->journal->findBySource($this->supplierId, 'small_asset_accrual', ClosingSourceId::smallAssetAccrualRelease($this->periodId)),
            'Sanity: §DM release v N+1 má existovat před revertem.'
        );

        // Revert open_next NESMÍ být zablokován vlastním release zápisem (jádro regrese).
        $this->closing->revertStep($this->supplierId, $this->periodId, 'open_next', $this->rv(), $this->meta());

        // …a release v N+1 musí zmizet, jinak by osiřel a nafoukl náklady dalšího roku.
        self::assertNull(
            $this->journal->findBySource($this->supplierId, 'small_asset_accrual', ClosingSourceId::smallAssetAccrualRelease($this->periodId)),
            'Revert open_next musí smazat rozpouštěcí zápis §DM v N+1.'
        );
    }

    // ── Task 10 předpoklad: accrual vstupuje do VH před zdaněním ────────────────

    public function testAccrualIncreasesProfitBeforeTax(): void
    {
        $this->card('Notebook', self::YEAR . '-07-01', 36500.00);
        $vhBefore = $this->profitBeforeTax();
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'flat_pct', 50.0, $this->rv(), $this->meta(), 1000000.00);
        $vhAfter = $this->profitBeforeTax();

        // D 501 credit o 18 250 zvýší VH (náhled ř.10) přesně o odloženou částku —
        // jádro „reálných čísel" DPPO náhledu. Kdyby byl accrual zamětený jako 'closing',
        // profitBeforeTax by ho vyloučil a předpoklad by selhal.
        self::assertEqualsWithDelta(18250.00, $vhAfter - $vhBefore, 0.001);
    }

    // ── EP-15: metodika drobného majetku ───────────────────────────────────────

    public function testFlatPctBlockedWithoutMaterialityLimit(): void
    {
        $this->card('Notebook', self::YEAR . '-07-01', 36500.00);
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        try {
            $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'flat_pct', 50.0, $this->rv(), $this->meta(), null);
            self::fail('Paušál bez zdokumentovaného limitu významnosti měl být odmítnut.');
        } catch (ClosingException $e) {
            self::assertSame('flat_pct_not_documented', $e->errorCode);
        }
    }

    public function testFlatPctBlockedWhenBaseExceedsMaterialityLimit(): void
    {
        $this->card('Notebook', self::YEAR . '-07-01', 36500.00);
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        try {
            // Báze karet 36 500 > limit 10 000 → významný soubor → paušál odmítnut.
            $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'flat_pct', 50.0, $this->rv(), $this->meta(), 10000.00);
            self::fail('Paušál nad limitem významnosti měl být odmítnut.');
        } catch (ClosingException $e) {
            self::assertSame('flat_pct_not_material', $e->errorCode);
        }
    }

    public function testProRataUsesDocumentedUsefulMonthsNotPeriodLength(): void
    {
        // Karta uvedená do užívání 1.10., doložená doba 24 měsíců → odloží se BUDOUCÍ část
        // 24měsíčního intervalu za rozvahovým dnem (~638/730), NE roční proxy (~273/365).
        $id = $this->card('Server', self::YEAR . '-10-01', 24000.00);
        $this->setDuration($id, self::YEAR . '-10-01', 24);

        $preview = $this->closing->smallAssetAccrualPreview($this->supplierId, $this->periodId, 'pro_rata', null);
        $item = $preview['items'][0];
        self::assertSame(24, $item['useful_months']);
        self::assertSame(self::YEAR . '-10-01', $item['in_use_date']);
        // Doložená doba odloží výrazně víc než roční proxy (17 951) — přes 20 000.
        self::assertGreaterThan(20000.0, (float) $item['deferred_amount'], 'Doložená doba 24 měs. odloží víc než roční proxy.');
        self::assertLessThan(22000.0, (float) $item['deferred_amount']);
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function setDuration(int $id, string $inUseDate, int $months): void
    {
        $this->db->pdo()->prepare('UPDATE small_assets SET put_into_use_date = ?, useful_months = ? WHERE id = ? AND supplier_id = ?')
            ->execute([$inUseDate, $months, $id, $this->supplierId]);
    }

    private function card(string $name, string $acquisitionDate, float $price): int
    {
        return $this->smallAssets->insert($this->supplierId, [
            'name' => $name,
            'acquisition_date' => $acquisitionDate,
            'price' => $price,
            'quantity' => 1,
            'unit_price' => $price,
            'status' => 'in_use',
        ], $this->userId);
    }

    /** Zaúčtuje řádek drobného majetku na 501 přes přijatou fakturu (expense_kind='small_asset'),
     *  aby smallAssetReport::expenseBreakdown vrátil daný obrat 501.200 (báze flat_pct). */
    private function expense501(float $net): void
    {
        $pdo = $this->db->pdo();
        $vat = round($net * 0.21, 2);
        $with = round($net + $vat, 2);
        $vatRateId = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $issue = self::YEAR . '-08-01';
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
             VALUES (?, ?, "Ulice 1", "Praha", "11000", ?, "dm-vendor@example.com", ?)'
        )->execute([$this->supplierId, 'Dodavatel DM s.r.o.', $this->czId, $this->currencyId]);
        $vendorId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind,
                 vat_deduction, issue_date, tax_date, due_date, received_at, currency_id, reverse_charge,
                 is_fixed_asset, total_without_vat, total_vat, total_with_vat, status, vat_classification_code, created_by)
             VALUES (?, ?, ?, "{}", "invoice", "full", ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, "received", "40", ?)'
        )->execute([$this->supplierId, $vendorId, 'DM501-' . self::YEAR, $issue, $issue, $issue, $issue, $this->currencyId, $net, $vat, $with, $this->userId]);
        $piId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index, expense_kind)
             VALUES (?, 'Drobný majetek', 1, 'ks', ?, ?, 21.00, ?, ?, ?, 0, 'small_asset')"
        )->execute([$piId, $net, $vatRateId, $net, $vat, $with]);
    }

    /**
     * Karta s vazbou na zaúčtovanou fakturu: D 501 odkladu nese dimenzi (středisko) řádku
     * nákladu faktury, stejně jako odklad nákladů příštích období. Karta bez dokladu
     * zůstává mimo 504 na řádku bez dimenzí.
     */
    public function testDeferralCarriesCostCenterOfCardDocument(): void
    {
        [$typeId, $valueId] = $this->costCenterCards();
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")->execute([$this->periodId]);

        $run = $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'pro_rata', null, $this->rv(), $this->meta());

        self::assertEqualsWithDelta(36200.00, (float) $run['total'], 0.001);
        self::assertSame([[18100.0, null], [18100.0, $valueId]], $this->costCenterLines((int) $run['entry_id'], $typeId, 'credit'));
    }

    /**
     * Převzatá přijatá faktura bez vlastního zápisu: náklad drobného majetku účtuje ruční zápis
     * ve skupině zaúčtování dokladu (převod z jiného programu). Odklad i rozpuštění nesou jeho
     * středisko. Zápis navázaný jen informativní vazbou „Souvisí“ se jiným střediskem do poměru
     * nevstupuje.
     */
    public function testImportedInvoiceDefersFromCostCenterOfOwnedEntry(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO dimension_types (supplier_id, code, name, kind, sort_order) VALUES (?, 'STR', 'Středisko', 'custom', 1)")->execute([$this->supplierId]);
        $typeId = (int) $pdo->lastInsertId();
        $values = [];
        foreach (['S1', 'S2'] as $code) {
            $pdo->prepare('INSERT INTO dimension_values (type_id, supplier_id, code, name) VALUES (?, ?, ?, ?)')->execute([$typeId, $this->supplierId, $code, 'Středisko ' . $code]);
            $values[$code] = (int) $pdo->lastInsertId();
        }
        $date = self::YEAR . '-07-01';
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
             VALUES (?, "Syntetický dodavatel", "Ulice 1", "Praha", "11000", ?, "dm-import@example.com", ?)'
        )->execute([$this->supplierId, $this->czId, $this->currencyId]);
        $vendorId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind,
                 vat_deduction, issue_date, tax_date, due_date, received_at, currency_id, reverse_charge,
                 is_fixed_asset, total_without_vat, total_vat, total_with_vat, status, vat_classification_code, created_by)
             VALUES (?, ?, "DM-IMPORT-1", "{}", "invoice", "none", ?, ?, ?, ?, ?, 0, 0, 36500, 0, 36500, "received", "40", ?)'
        )->execute([$this->supplierId, $vendorId, $date, $date, $date, $date, $this->currencyId, $this->userId]);
        $piId = (int) $pdo->lastInsertId();
        $vatRateId = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $pdo->prepare(
            "INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index, expense_kind)
             VALUES (?, 'Notebook', 1, 'ks', 36500, ?, 0, 36500, 0, 36500, 0, 'small_asset')"
        )->execute([$piId, $vatRateId]);
        $itemId = (int) $pdo->lastInsertId();
        $opts = ['entry_date' => $date, 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId];
        $owned = $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => self::DM, 'side' => 'debit', 'amount' => 36500.00, 'dimensions' => [$typeId => $values['S1']]],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 36500.00],
        ], $opts);
        (new \MyInvoice\Service\Accounting\DocumentPostingGroup($this->db))->attach($this->supplierId, 'purchase_invoice', $piId, [$owned]);
        $related = $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => self::DM, 'side' => 'debit', 'amount' => 1000.00, 'dimensions' => [$typeId => $values['S2']]],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 1000.00],
        ], $opts);
        (new \MyInvoice\Repository\JournalEntryDocumentLinkRepository($this->db))->add($related, $this->supplierId, 'purchase_invoice', $piId, null, $this->userId);
        $this->smallAssets->insert($this->supplierId, [
            'name' => 'Převzatý notebook', 'acquisition_date' => $date, 'price' => 36500.00, 'quantity' => 1,
            'unit_price' => 36500.00, 'status' => 'in_use', 'purchase_invoice_id' => $piId, 'purchase_invoice_item_id' => $itemId,
        ], $this->userId);

        $this->driveCloseWithAccrual('pro_rata', null);
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        $defer = $this->journal->findBySource($this->supplierId, 'small_asset_accrual', ClosingSourceId::smallAssetAccrual($this->periodId));
        $release = $this->journal->findBySource($this->supplierId, 'small_asset_accrual', ClosingSourceId::smallAssetAccrualRelease($this->periodId));
        self::assertNotNull($defer);
        self::assertNotNull($release);
        self::assertSame([[18100.0, $values['S1']]], $this->costCenterLines((int) $defer['id'], $typeId, 'credit', self::DM));
        self::assertSame([[18100.0, $values['S1']]], $this->costCenterLines((int) $release['id'], $typeId, 'debit', self::DM));
    }

    /**
     * Účtotvorné středisko s mapou 501 → analytika zavedenou po zaúčtování faktury: odklad nese
     * středisko dokladu a účet, na kterém náklad v zápisu leží (501), dnešní mapa ho nepřesměruje.
     */
    public function testCostCenterDoesNotRouteDeferralToMappedAnalytic(): void
    {
        [$typeId, $valueId] = $this->costCenterCards();
        $this->mapCostCenterToAnalytic($typeId, $valueId);
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")->execute([$this->periodId]);

        $run = $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'pro_rata', null, $this->rv(), $this->meta());

        $lines = $this->entryLines((int) $run['entry_id']);
        self::assertEqualsWithDelta(36200.00, $this->sideAmount($lines, '501', 'credit'), 0.001);
        self::assertEqualsWithDelta(0.0, $this->sideAmount($lines, '501.100', 'credit'), 0.001);
        self::assertSame([[18100.0, null], [18100.0, $valueId]], $this->costCenterLines((int) $run['entry_id'], $typeId, 'credit'));
    }

    /**
     * Faktura zaúčtovaná se střediskem S1 přes mapu na 501.100: odklad snižuje náklad tam, kde
     * leží (501.100 se S1), jinak by 501.100 zůstala nadhodnocená a 501 záporná. Ruční karta
     * bez dokladu zůstává na účtu pravidla. Rozpuštění v dalším roce zrcadlí oba účty.
     */
    public function testDeferralFollowsAnalyticOfDocumentEntry(): void
    {
        [$typeId, $valueId] = $this->costCenterCards(true);
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")->execute([$this->periodId]);

        $run = $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'pro_rata', null, $this->rv(), $this->meta());

        self::assertSame([[18100.0, null]], $this->costCenterLines((int) $run['entry_id'], $typeId, 'credit'));
        self::assertSame([[18100.0, $valueId]], $this->costCenterLines((int) $run['entry_id'], $typeId, 'credit', '501.100'));

        $nextStart = (self::YEAR + 1) . '-01-01';
        $this->periods->create($this->supplierId, self::YEAR + 1, $nextStart, (self::YEAR + 1) . '-12-31');
        $defer = (new \ReflectionMethod($this->closing, 'findEntryWithLines'))->invoke($this->closing, $this->supplierId, 'small_asset_accrual', ClosingSourceId::smallAssetAccrual($this->periodId));
        $release = (new \ReflectionMethod($this->closing, 'smallAssetReleaseEntryLines'))->invoke($this->closing, $this->supplierId, $defer, $nextStart);
        $releaseId = $this->posting->postDocument($this->supplierId, 'small_asset_accrual', ClosingSourceId::smallAssetAccrualRelease($this->periodId), $release, [
            'entry_date' => $nextStart, 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId,
        ]);
        self::assertSame([[18100.0, $valueId]], $this->costCenterLines($releaseId, $typeId, 'debit', '501.100'));
        self::assertSame([[18100.0, null]], $this->costCenterLines($releaseId, $typeId, 'debit'));
    }

    /**
     * Jednoduchá forma zápisu: paušál s dobropisem na jiném středisku dá 501 na obou stranách.
     * Zápis se musí rozložit na dvojice (381 proti každému řádku 501) a zaúčtovat, i jeho rozpuštění.
     */
    public function testSingleEntryFormPostsFlatPctWithCreditNoteOnOtherCostCenter(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db->pdo()->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")->execute([$this->supplierId]);
        $container->get(\MyInvoice\Service\Accounting\EntryForm\EntryFormService::class)
            ->schedule($this->supplierId, self::STARTS_ON, \MyInvoice\Service\Accounting\EntryForm\EntryForm::Single, $this->userId);
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO dimension_types (supplier_id, code, name, kind, sort_order) VALUES (?, 'STR', 'Středisko', 'custom', 1)")->execute([$this->supplierId]);
        $typeId = (int) $pdo->lastInsertId();
        $values = [];
        foreach (['S1', 'S2'] as $code) {
            $pdo->prepare("INSERT INTO dimension_values (type_id, supplier_id, code, name) VALUES (?, ?, ?, 'Syntetické středisko')")->execute([$typeId, $this->supplierId, $code]);
            $values[] = (int) $pdo->lastInsertId();
        }
        $this->postedSmallAssetInvoice('DM-SF-1', 10000.00, [$typeId => $values[0]]);
        $this->postedSmallAssetInvoice('DM-SF-2', -2000.00, [$typeId => $values[1]]);
        $pdo->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")->execute([$this->periodId]);

        $run = $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'flat_pct', 50.0, $this->rv(), $this->meta(), 1000000.00);

        $entryId = (int) $run['entry_id'];
        self::assertEqualsWithDelta(4000.00, (float) $run['total'], 0.001);
        self::assertSame([[5000.0, $values[0]]], $this->costCenterLines($entryId, $typeId, 'credit', self::DM));
        self::assertSame([[1000.0, $values[1]]], $this->costCenterLines($entryId, $typeId, 'debit', self::DM));
        $lines = $this->entryLines($entryId);
        self::assertEqualsWithDelta(4000.00, $this->sideAmount($lines, '381', 'debit') - $this->sideAmount($lines, '381', 'credit'), 0.001);

        $defer = (new \ReflectionMethod($this->closing, 'findEntryWithLines'))->invoke($this->closing, $this->supplierId, 'small_asset_accrual', ClosingSourceId::smallAssetAccrual($this->periodId));
        $release = (new \ReflectionMethod($this->closing, 'smallAssetReleaseLines'))->invoke($this->closing, $this->supplierId, $defer, (self::YEAR + 1) . '-01-01');
        $this->periods->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
        $releaseId = $this->posting->postDocument($this->supplierId, 'small_asset_accrual', ClosingSourceId::smallAssetAccrualRelease($this->periodId), \MyInvoice\Service\Accounting\Pair\PairGroups::consecutive($release, 'small_asset'), [
            'entry_date' => (self::YEAR + 1) . '-01-01', 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId,
        ]);
        self::assertSame([[5000.0, $values[0]]], $this->costCenterLines($releaseId, $typeId, 'debit', self::DM));
        self::assertSame([[1000.0, $values[1]]], $this->costCenterLines($releaseId, $typeId, 'credit', self::DM));
    }

    /**
     * Pravidlo vede drobný majetek na syntetiku 501, kterou zápis přesměruje na její jedinou
     * analytiku. Dvě položky jedné faktury na S1 a S2 a karty s různým datem: odklad každé karty
     * nese středisko i analytiku své položky, ne poměr celé faktury. Rozhoduje zápis, ne dnešní
     * osnova: analytika přidaná až po zaúčtování faktury na tom nic nemění. Dostane-li položka po
     * zaúčtování středisko, které zápis nenese, dělí se její odklad poměrem nákladu 501 dokladu
     * a řádek bez dimenzí nevznikne.
     */
    #[DataProvider('redirectedAnalyticProvider')]
    public function testItemOnSyntheticRedirectedToSingleAnalyticKeepsItsCostCenter(bool $analyticAddedAfterPosting, bool $itemDimensionChanged): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE posting_rules SET debit_account_code = '501' WHERE supplier_id = ? AND rule_key = 'invoice.small_asset.received'")->execute([$this->supplierId]);
        $pdo->prepare("UPDATE chart_of_accounts SET is_active = 0 WHERE supplier_id = ? AND account_code LIKE '501.%' AND account_code <> '501.900'")->execute([$this->supplierId]);
        $pdo->prepare("INSERT INTO dimension_types (supplier_id, code, name, kind, sort_order) VALUES (?, 'STR', 'Středisko', 'custom', 1)")->execute([$this->supplierId]);
        $typeId = (int) $pdo->lastInsertId();
        $values = [];
        foreach (['S1', 'S2', 'S3'] as $code) {
            $pdo->prepare("INSERT INTO dimension_values (type_id, supplier_id, code, name) VALUES (?, ?, ?, 'Syntetické středisko')")->execute([$typeId, $this->supplierId, $code]);
            $values[] = (int) $pdo->lastInsertId();
        }
        $date = self::YEAR . '-07-01';
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
             VALUES (?, "Syntetický dodavatel", "Ulice 1", "Praha", "11000", ?, "dm-redir@example.com", ?)'
        )->execute([$this->supplierId, $this->czId, $this->currencyId]);
        $vendorId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind,
                 vat_deduction, issue_date, tax_date, due_date, received_at, currency_id, reverse_charge,
                 is_fixed_asset, total_without_vat, total_vat, total_with_vat, status, vat_classification_code, created_by)
             VALUES (?, ?, "DM-REDIR", "{}", "invoice", "none", ?, ?, ?, ?, ?, 0, 0, 36500, 0, 36500, "received", "40", ?)'
        )->execute([$this->supplierId, $vendorId, $date, $date, $date, $date, $this->currencyId, $this->userId]);
        $piId = (int) $pdo->lastInsertId();
        $vatRateId = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $itemIds = [];
        foreach ([0, 1] as $i) {
            $pdo->prepare(
                "INSERT INTO purchase_invoice_items
                    (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                     vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index, expense_kind)
                 VALUES (?, 'Notebook', 1, 'ks', 18250, ?, 0, 18250, 0, 18250, ?, 'small_asset')"
            )->execute([$piId, $vatRateId, $i]);
            $itemIds[] = (int) $pdo->lastInsertId();
        }
        $trace = $this->posting->purchaseItemExpenseAccounts($this->supplierId, $piId);
        self::assertSame('501', (string) $trace[$itemIds[0]], 'Sanity: trasa vrací syntetiku z pravidla.');
        $entryId = $this->posting->postDocument($this->supplierId, 'purchase_invoice', $piId, [
            ['account_code' => '501', 'side' => 'debit', 'amount' => 18250.00, 'dimensions' => [$typeId => $values[0]]],
            ['account_code' => '501', 'side' => 'debit', 'amount' => 18250.00, 'dimensions' => [$typeId => $values[1]]],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 36500.00],
        ], ['entry_date' => $date, 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        self::assertEqualsWithDelta(36500.00, $this->sideAmount($this->entryLines($entryId), '501.900', 'debit'), 0.001, 'Sanity: zápis leží na jediné analytice.');
        if ($analyticAddedAfterPosting) {
            $pdo->prepare("UPDATE chart_of_accounts SET is_active = 1 WHERE supplier_id = ? AND account_code = '501.100'")->execute([$this->supplierId]);
            self::assertArrayNotHasKey('501', $this->posting->singleAnalyticRedirects($this->supplierId, $date), 'Sanity: osnova už nepřesměrovává.');
        }
        $pdo->prepare("INSERT INTO document_dimensions (supplier_id, doc_type, doc_id, item_no, dimension_type_id, dimension_value_id) VALUES (?, 'purchase_invoice', ?, 1, ?, ?), (?, 'purchase_invoice', ?, 2, ?, ?)")
            ->execute([$this->supplierId, $piId, $typeId, $values[$itemDimensionChanged ? 2 : 0], $this->supplierId, $piId, $typeId, $values[1]]);
        foreach ([[$itemIds[0], self::YEAR . '-07-01'], [$itemIds[1], self::YEAR . '-10-01']] as [$itemId, $acquired]) {
            $this->smallAssets->insert($this->supplierId, [
                'name' => 'Notebook ' . $itemId, 'acquisition_date' => $acquired, 'price' => 18250.00, 'quantity' => 1,
                'unit_price' => 18250.00, 'status' => 'in_use', 'purchase_invoice_id' => $piId, 'purchase_invoice_item_id' => $itemId,
            ], $this->userId);
        }
        $pdo->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")->execute([$this->periodId]);
        $preview = $this->closing->smallAssetAccrualPreview($this->supplierId, $this->periodId, 'pro_rata');
        $byCard = [];
        foreach ($preview['items'] as $item) {
            $byCard[] = round((float) $item['deferred_amount'], 2);
        }

        $run = $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'pro_rata', null, $this->rv(), $this->meta());

        // Karta z 1. 7. odloží 181/365, karta z 1. 10. 273/365. Odklad jde na analytiku, na které
        // náklad leží v zápisu dokladu (501.900), i když osnova mezitím dostala další analytiku.
        self::assertEqualsCanonicalizing([9050.0, 13650.0], $byCard, 'Sanity: karty odkládají různé částky.');
        // Položka se střediskem S3, které zápis nenese: karta z 1. 7. se dělí poměrem S1 : S2 1 : 1
        // (díl S2 se sloučí s odkladem karty z 1. 10.: 4525 + 13650).
        $expected = $itemDimensionChanged
            ? [[4525.0, $values[0]], [18175.0, $values[1]]]
            : [[9050.0, $values[0]], [13650.0, $values[1]]];
        self::assertSame($expected, $this->costCenterLines((int) $run['entry_id'], $typeId, 'credit', '501.900'));
    }

    /**
     * Odklad paušálu s dobropisem na jiném středisku zaúčtovaný ve složené formě (jeden řádek 381)
     * a od 1. 1. dalšího roku jednoduchá forma: rozpuštění nejde zrcadlit 1 : N (501 proti 501),
     * každý řádek 501 dostane vlastní protějšek na 381 a zápis se zaúčtuje.
     */
    public function testReleaseInSingleFormAfterCompositeDeferWithCreditNote(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db->pdo()->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")->execute([$this->supplierId]);
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO dimension_types (supplier_id, code, name, kind, sort_order) VALUES (?, 'STR', 'Středisko', 'custom', 1)")->execute([$this->supplierId]);
        $typeId = (int) $pdo->lastInsertId();
        $values = [];
        foreach (['S1', 'S2'] as $code) {
            $pdo->prepare("INSERT INTO dimension_values (type_id, supplier_id, code, name) VALUES (?, ?, ?, 'Syntetické středisko')")->execute([$typeId, $this->supplierId, $code]);
            $values[] = (int) $pdo->lastInsertId();
        }
        $this->postedSmallAssetInvoice('DM-CS-1', 10000.00, [$typeId => $values[0]]);
        $this->postedSmallAssetInvoice('DM-CS-2', -2000.00, [$typeId => $values[1]]);
        $pdo->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")->execute([$this->periodId]);

        $run = $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'flat_pct', 50.0, $this->rv(), $this->meta(), 1000000.00);
        self::assertCount(1, array_filter($this->entryLines((int) $run['entry_id']), static fn (array $l): bool => $l['account_code'] === '381'), 'Sanity: složená forma nese 381 jedním řádkem.');
        // Řádek 381 odkladu s dimenzí (jako od pravidla dimenzí účtu): rozpuštění ji nese na každém protějšku.
        $pdo->prepare(
            "INSERT INTO journal_entry_line_dimensions (line_id, supplier_id, dimension_type_id, dimension_value_id)
             SELECT l.id, l.supplier_id, ?, ? FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND l.entry_id = ? AND a.account_code = '381'"
        )->execute([$typeId, $values[0], $this->supplierId, (int) $run['entry_id']]);

        $nextStart = (self::YEAR + 1) . '-01-01';
        $this->periods->create($this->supplierId, self::YEAR + 1, $nextStart, (self::YEAR + 1) . '-12-31');
        $container->get(\MyInvoice\Service\Accounting\EntryForm\EntryFormService::class)
            ->schedule($this->supplierId, $nextStart, \MyInvoice\Service\Accounting\EntryForm\EntryForm::Single, $this->userId);
        $defer = (new \ReflectionMethod($this->closing, 'findEntryWithLines'))->invoke($this->closing, $this->supplierId, 'small_asset_accrual', ClosingSourceId::smallAssetAccrual($this->periodId));
        $release = (new \ReflectionMethod($this->closing, 'smallAssetReleaseEntryLines'))->invoke($this->closing, $this->supplierId, $defer, $nextStart);
        $releaseId = $this->posting->postDocument($this->supplierId, 'small_asset_accrual', ClosingSourceId::smallAssetAccrualRelease($this->periodId), $release, [
            'entry_date' => $nextStart, 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId,
        ]);

        self::assertSame([[5000.0, $values[0]]], $this->costCenterLines($releaseId, $typeId, 'debit', self::DM));
        self::assertSame([[1000.0, $values[1]]], $this->costCenterLines($releaseId, $typeId, 'credit', self::DM));
        $lines = $this->entryLines($releaseId);
        self::assertEqualsWithDelta(4000.00, $this->sideAmount($lines, '381', 'credit') - $this->sideAmount($lines, '381', 'debit'), 0.001);
        self::assertSame([[1000.0, $values[0]]], $this->costCenterLines($releaseId, $typeId, 'debit', '381'));
        self::assertSame([[5000.0, $values[0]]], $this->costCenterLines($releaseId, $typeId, 'credit', '381'));
    }

    /** @return array<string,array{bool,bool}> */
    public static function redirectedAnalyticProvider(): array
    {
        return [
            'jediná analytika' => [false, false],
            'analytika přidaná po zaúčtování' => [true, false],
            'dimenze položky změněná po zaúčtování' => [false, true],
            'analytika přidaná a dimenze změněná po zaúčtování' => [true, true],
        ];
    }

    /**
     * Firma bez dimenzí, faktury drobného majetku s dopravou na 518: všechny řádky mají stejné
     * (prázdné) přiřazení, trasa zaúčtování se po fakturách nespouští a dotazy nerostou.
     */
    public function testFlatPctWithoutDimensionsDoesNotTraceInvoicesWithOtherExpense(): void
    {
        $pdo = $this->db->pdo();
        $add = function (int $from, int $to): void {
            for ($i = $from; $i < $to; ++$i) {
                $this->postedSmallAssetInvoice('DM-ND-' . $i, 1000.00, [], [
                    ['account_code' => '518', 'side' => 'debit', 'amount' => 100.00],
                    ['account_code' => '321', 'side' => 'credit', 'amount' => 100.00],
                ]);
            }
        };
        $add(0, 3);
        $pdo->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")->execute([$this->periodId]);
        $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'flat_pct', 50.0, $this->rv(), $this->meta(), 1000000.00);
        $few = $this->flatPctQueries();
        $pdo->prepare("UPDATE accounting_periods SET status = 'open' WHERE id = ?")->execute([$this->periodId]);
        $add(3, 23);
        $pdo->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")->execute([$this->periodId]);
        $many = $this->flatPctQueries();

        self::assertLessThan(10, $many - $few, 'Počet dotazů kroku nesmí růst s počtem faktur (' . $few . ' → ' . $many . ').');
    }

    private function flatPctQueries(): int
    {
        $pdo = $this->db->pdo();
        $rv = $this->rv();
        $meta = $this->meta();
        $questions = static fn (): int => (int) $pdo->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_NUM)[1];
        $before = $questions();
        $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'flat_pct', 50.0, $rv, $meta, 1000000.00);
        return $questions() - $before;
    }

    /**
     * Zaúčtovaná přijatá faktura (záporná = opravný doklad) s jednou položkou drobného majetku.
     *
     * @param array<int,int> $dimensions
     */
    private function postedSmallAssetInvoice(string $number, float $net, array $dimensions, array $extraLines = []): void
    {
        $pdo = $this->db->pdo();
        $date = self::YEAR . '-07-01';
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
             VALUES (?, "Syntetický dodavatel", "Ulice 1", "Praha", "11000", ?, "dm-sf@example.com", ?)'
        )->execute([$this->supplierId, $this->czId, $this->currencyId]);
        $vendorId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind,
                 vat_deduction, issue_date, tax_date, due_date, received_at, currency_id, reverse_charge,
                 is_fixed_asset, total_without_vat, total_vat, total_with_vat, status, vat_classification_code, created_by)
             VALUES (?, ?, ?, "{}", "invoice", "none", ?, ?, ?, ?, ?, 0, 0, ?, 0, ?, "received", "40", ?)'
        )->execute([$this->supplierId, $vendorId, $number, $date, $date, $date, $date, $this->currencyId, $net, $net, $this->userId]);
        $piId = (int) $pdo->lastInsertId();
        $vatRateId = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $pdo->prepare(
            "INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index, expense_kind)
             VALUES (?, 'Notebook', 1, 'ks', ?, ?, 0, ?, 0, ?, 0, 'small_asset')"
        )->execute([$piId, $net, $vatRateId, $net, $net]);
        $itemId = (int) $pdo->lastInsertId();
        $expense = (string) $this->posting->purchaseItemExpenseAccounts($this->supplierId, $piId)[$itemId];
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $piId, [
            ['account_code' => $expense, 'side' => $net > 0 ? 'debit' : 'credit', 'amount' => abs($net)] + ($dimensions === [] ? [] : ['dimensions' => $dimensions]),
            ['account_code' => '321', 'side' => $net > 0 ? 'credit' : 'debit', 'amount' => abs($net)],
            ...$extraLines,
        ], ['entry_date' => $date, 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
    }

    /**
     * Rozpuštění v N+1 veřejnou cestou open_next zrcadlí dimenze odkladu po řádcích;
     * revert a nové open_next zápis přepíše, řádky se nezdvojí.
     */
    public function testOpenNextReleaseCarriesCostCenterAndRerunDoesNotDuplicate(): void
    {
        [$typeId, $valueId] = $this->costCenterCards();
        $this->driveCloseWithAccrual('pro_rata', null);
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $release = $this->journal->findBySource($this->supplierId, 'small_asset_accrual', ClosingSourceId::smallAssetAccrualRelease($this->periodId));
        self::assertNotNull($release);
        self::assertSame([[18100.0, null], [18100.0, $valueId]], $this->costCenterLines((int) $release['id'], $typeId, 'debit'));

        $this->closing->revertStep($this->supplierId, $this->periodId, 'open_next', $this->rv(), $this->meta());
        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $release = $this->journal->findBySource($this->supplierId, 'small_asset_accrual', ClosingSourceId::smallAssetAccrualRelease($this->periodId));
        self::assertNotNull($release);
        self::assertSame([[18100.0, null], [18100.0, $valueId]], $this->costCenterLines((int) $release['id'], $typeId, 'debit'));
    }

    /**
     * Koncept faktury v cizí měně bez kurzu v bázi paušálu: rozpad po dimenzích ho
     * nezaúčtovaný nedohledává (trasa zaúčtování by spadla na chybějícím kurzu) a krok
     * projde se stejnou částkou jako náhled.
     */
    public function testFlatPctIgnoresUnpostedForeignDraftWithoutRate(): void
    {
        $pdo = $this->db->pdo();
        $eurId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'EUR' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        if ($eurId === 0) {
            self::markTestSkipped('Chybí měna EUR.');
        }
        $date = self::YEAR . '-07-01';
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
             VALUES (?, "Syntetický dodavatel EUR", "Ulice 1", "Praha", "11000", ?, "dm-eur@example.com", ?)'
        )->execute([$this->supplierId, $this->czId, $this->currencyId]);
        $vendorId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind,
                 vat_deduction, issue_date, tax_date, due_date, received_at, currency_id, exchange_rate, reverse_charge,
                 is_fixed_asset, total_without_vat, total_vat, total_with_vat, status, vat_classification_code, created_by)
             VALUES (?, ?, "DM-EUR-1", "{}", "invoice", "none", ?, ?, ?, ?, ?, NULL, 0, 0, 1000, 0, 1000, "draft", "40", ?)'
        )->execute([$this->supplierId, $vendorId, $date, $date, $date, $date, $eurId, $this->userId]);
        $piId = (int) $pdo->lastInsertId();
        $vatRateId = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $pdo->prepare(
            "INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index, expense_kind)
             VALUES (?, 'Monitor', 1, 'ks', 1000, ?, 0, 1000, 0, 1000, 0, 'small_asset')"
        )->execute([$piId, $vatRateId]);
        $this->expense501(36500.00);
        $pdo->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")->execute([$this->periodId]);
        $preview = $this->closing->smallAssetAccrualPreview($this->supplierId, $this->periodId, 'flat_pct', 50.0);

        $run = $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'flat_pct', 50.0, $this->rv(), $this->meta(), 1000000.00);

        self::assertGreaterThan(0.0, (float) $preview['total']);
        self::assertEqualsWithDelta((float) $preview['total'], (float) $run['total'], 0.001);
        self::assertEqualsWithDelta((float) $preview['total'], $this->sideAmount($this->entryLines((int) $run['entry_id']), '501', 'credit'), 0.001);
    }

    /**
     * Mimo 504: faktura se dvěma středisky na 501, jejíž trasu zaúčtování dnes nejde spočítat
     * (kurz po zaúčtování smazán). Krok nesmí spadnout, dělí se poměrem nákladů zápisu.
     */
    public function testTraceFailureFallsBackToInvoiceExpenseRatio(): void
    {
        [$typeId, $values, $run] = $this->traceFailureInvoice(1, 1);

        $expected = [[9050.0, $values[0]], [9050.0, $values[1]]];
        sort($expected);
        self::assertSame($expected, $this->costCenterLines((int) $run['entry_id'], $typeId, 'credit', self::DM));
    }

    /**
     * Výpadek trasy u faktury, která nese i službu na 518 s jiným střediskem: žádná karta
     * nesmí převzít středisko 518 (záložní účet trasy), všechny jdou poměrem 501 dokladu.
     */
    public function testTraceFailureDoesNotTakeDimensionOfOtherExpenseForNextCard(): void
    {
        [$typeId, $values, $run] = $this->traceFailureInvoice(2, 2, true);

        $expected = [[9050.0, $values[0]], [9050.0, $values[1]]];
        sort($expected);
        self::assertSame($expected, $this->costCenterLines((int) $run['entry_id'], $typeId, 'credit', self::DM));
    }

    /** Výpadek trasy platí pro celou fakturu: další karty téže faktury ho neopakují (dotazy nerostou po kartách). */
    public function testTraceFailureIsNotRepeatedPerCardOfSameInvoice(): void
    {
        [, , , , $piId, $itemIds] = $this->traceFailureInvoice(12, 2);
        $few = $this->proRataQueries(); // opakovaný běh, ať se srovnává přepis zápisu s přepisem
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE accounting_periods SET status = 'open' WHERE id = ?")->execute([$this->periodId]);
        foreach (array_slice($itemIds, 2) as $i => $itemId) {
            $this->smallAssets->insert($this->supplierId, [
                'name' => 'Notebook další ' . $i, 'acquisition_date' => self::YEAR . '-07-01', 'price' => 3041.67, 'quantity' => 1,
                'unit_price' => 3041.67, 'status' => 'in_use', 'purchase_invoice_id' => $piId, 'purchase_invoice_item_id' => $itemId,
            ], $this->userId);
        }
        $pdo->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")->execute([$this->periodId]);
        $many = $this->proRataQueries();

        self::assertLessThan(10, $many - $few, 'Trasa se nesmí opakovat po kartách (' . $few . ' → ' . $many . ').');
    }

    private function proRataQueries(): int
    {
        $pdo = $this->db->pdo();
        $rv = $this->rv();
        $meta = $this->meta();
        $questions = static fn (): int => (int) $pdo->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_NUM)[1];
        $before = $questions();
        $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'pro_rata', null, $rv, $meta);
        return $questions() - $before;
    }

    /**
     * Faktura v EUR (36 500 Kč) se dvěma středisky na 501 a `$items` položkami drobného
     * majetku, z nichž `$cards` má kartu; kurz po zaúčtování smazán. Spustí pro_rata.
     *
     * @return array{0:int,1:list<int>,2:array<string,mixed>,3:int,4:int,5:list<int>} typ, hodnoty, běh, dotazy, faktura, položky
     */
    private function traceFailureInvoice(int $items, int $cards, bool $service = false): array
    {
        $pdo = $this->db->pdo();
        $eurId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'EUR' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        if ($eurId === 0) {
            self::markTestSkipped('Chybí měna EUR.');
        }
        $pdo->prepare("INSERT INTO dimension_types (supplier_id, code, name, kind, sort_order) VALUES (?, 'STR', 'Středisko', 'custom', 1)")->execute([$this->supplierId]);
        $typeId = (int) $pdo->lastInsertId();
        $values = [];
        foreach (['S1', 'S2', 'S3'] as $code) {
            $pdo->prepare("INSERT INTO dimension_values (type_id, supplier_id, code, name) VALUES (?, ?, ?, 'Syntetické středisko')")->execute([$typeId, $this->supplierId, $code]);
            $values[] = (int) $pdo->lastInsertId();
        }
        $date = self::YEAR . '-07-01';
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
             VALUES (?, "Syntetický dodavatel EUR", "Ulice 1", "Praha", "11000", ?, "dm-eur2@example.com", ?)'
        )->execute([$this->supplierId, $this->czId, $this->currencyId]);
        $vendorId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind,
                 vat_deduction, issue_date, tax_date, due_date, received_at, currency_id, exchange_rate, reverse_charge,
                 is_fixed_asset, total_without_vat, total_vat, total_with_vat, status, vat_classification_code, created_by)
             VALUES (?, ?, "DM-EUR-2", "{}", "invoice", "none", ?, ?, ?, ?, ?, 25, 0, 0, 1460, 0, 1460, "received", "40", ?)'
        )->execute([$this->supplierId, $vendorId, $date, $date, $date, $date, $eurId, $this->userId]);
        $piId = (int) $pdo->lastInsertId();
        $vatRateId = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $itemIds = [];
        for ($i = 0; $i < $items; ++$i) {
            $unit = round(1460 / $items, 2);
            $pdo->prepare(
                "INSERT INTO purchase_invoice_items
                    (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                     vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index, expense_kind)
                 VALUES (?, 'Notebook', 1, 'ks', ?, ?, 0, ?, 0, ?, ?, 'small_asset')"
            )->execute([$piId, $unit, $vatRateId, $unit, $unit, $i]);
            $itemIds[] = (int) $pdo->lastInsertId();
        }
        $expense = (string) $this->posting->purchaseItemExpenseAccounts($this->supplierId, $piId)[$itemIds[0]];
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $piId, [
            ['account_code' => $expense, 'side' => 'debit', 'amount' => 18250.00, 'dimensions' => [$typeId => $values[0]]],
            ['account_code' => $expense, 'side' => 'debit', 'amount' => 18250.00, 'dimensions' => [$typeId => $values[1]]],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 36500.00],
            ...($service ? [
                ['account_code' => '518', 'side' => 'debit', 'amount' => 1000.00, 'dimensions' => [$typeId => $values[2]]],
                ['account_code' => '321', 'side' => 'credit', 'amount' => 1000.00],
            ] : []),
        ], ['entry_date' => $date, 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        $pdo->prepare('UPDATE purchase_invoices SET exchange_rate = NULL WHERE id = ?')->execute([$piId]);
        foreach (array_slice($itemIds, 0, $cards) as $i => $itemId) {
            $price = round(36500.00 / $items, 2);
            $this->smallAssets->insert($this->supplierId, [
                'name' => 'Notebook ' . $i, 'acquisition_date' => $date, 'price' => $price, 'quantity' => 1,
                'unit_price' => $price, 'status' => 'in_use', 'purchase_invoice_id' => $piId, 'purchase_invoice_item_id' => $itemId,
            ], $this->userId);
        }
        $pdo->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")->execute([$this->periodId]);

        $queries = $this->proRataQueries();
        $run = ['entry_id' => (int) ($this->journal->findBySource($this->supplierId, 'small_asset_accrual', ClosingSourceId::smallAssetAccrual($this->periodId))['id'] ?? 0)];
        return [$typeId, $values, $run, $queries, $piId, $itemIds];
    }

    /**
     * Analytika dokladu k rozvahovému dni neaktivní: odklad nese středisko, ale jde na účet
     * pravidla (501), který zaúčtování vede na jedinou aktivní analytiku osnovy (501.900).
     */
    public function testDeferralStaysOnRuleAccountWhenDocumentAnalyticIsInactive(): void
    {
        [$typeId, $valueId] = $this->costCenterCards(true);
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE chart_of_accounts SET is_active = 0 WHERE supplier_id = ? AND account_code = '501.100'")->execute([$this->supplierId]);
        $pdo->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")->execute([$this->periodId]);

        $run = $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'pro_rata', null, $this->rv(), $this->meta());

        self::assertSame([[18100.0, null], [18100.0, $valueId]], $this->costCenterLines((int) $run['entry_id'], $typeId, 'credit', self::DM));
    }

    /**
     * Firma bez dimenzí, faktura se dvěma položkami na různých analytikách 501 (501.100 a 501.900
     * mohou mít jinou daňovou uznatelnost) a karta k položce na 501.100: odklad jde celý z 501.100,
     * ne poměrem obou analytik dokladu.
     */
    public function testItemOnOneOfTwoAnalyticsDefersFromItsAnalyticWithoutDimensions(): void
    {
        $this->analytic501('501.100');
        $pdo = $this->db->pdo();
        $date = self::YEAR . '-07-01';
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
             VALUES (?, "Syntetický dodavatel", "Ulice 1", "Praha", "11000", ?, "dm-two@example.com", ?)'
        )->execute([$this->supplierId, $this->czId, $this->currencyId]);
        $vendorId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind,
                 vat_deduction, issue_date, tax_date, due_date, received_at, currency_id, reverse_charge,
                 is_fixed_asset, total_without_vat, total_vat, total_with_vat, status, vat_classification_code, created_by)
             VALUES (?, ?, "DM-TWO-1", "{}", "invoice", "none", ?, ?, ?, ?, ?, 0, 0, 73000, 0, 73000, "received", "40", ?)'
        )->execute([$this->supplierId, $vendorId, $date, $date, $date, $date, $this->currencyId, $this->userId]);
        $piId = (int) $pdo->lastInsertId();
        $vatRateId = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $itemIds = [];
        foreach (['501.100', '501.900'] as $i => $account) {
            $pdo->prepare(
                "INSERT INTO purchase_invoice_items
                    (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                     vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index, expense_kind, expense_account_code)
                 VALUES (?, 'Notebook', 1, 'ks', 36500, ?, 0, 36500, 0, 36500, ?, 'small_asset', ?)"
            )->execute([$piId, $vatRateId, $i, $account]);
            $itemIds[] = (int) $pdo->lastInsertId();
        }
        $accounts = $this->posting->purchaseItemExpenseAccounts($this->supplierId, $piId);
        self::assertSame(['501.100', '501.900'], [(string) $accounts[$itemIds[0]], (string) $accounts[$itemIds[1]]], 'Sanity: položky vedou na různé analytiky.');
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $piId, [
            ['account_code' => '501.100', 'side' => 'debit', 'amount' => 36500.00],
            ['account_code' => '501.900', 'side' => 'debit', 'amount' => 36500.00],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 73000.00],
        ], ['entry_date' => $date, 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        $this->smallAssets->insert($this->supplierId, [
            'name' => 'Notebook na 501.100', 'acquisition_date' => $date, 'price' => 36500.00, 'quantity' => 1,
            'unit_price' => 36500.00, 'status' => 'in_use', 'purchase_invoice_id' => $piId, 'purchase_invoice_item_id' => $itemIds[0],
        ], $this->userId);
        $pdo->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")->execute([$this->periodId]);

        $run = $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'pro_rata', null, $this->rv(), $this->meta());

        $lines = $this->entryLines((int) $run['entry_id']);
        self::assertEqualsWithDelta(18100.00, $this->sideAmount($lines, '501.100', 'credit'), 0.001);
        self::assertEqualsWithDelta(0.0, $this->sideAmount($lines, '501.900', 'credit'), 0.001);
    }

    /**
     * Analytika odkladu (501.100 účtotvorného střediska) od 1. 1. dalšího roku deaktivovaná:
     * rozpuštění nespadne na neaktivním účtu, jde na syntetiku, kterou osnova k tomu dni vede
     * na jedinou aktivní analytiku, a nese středisko odkladu.
     */
    public function testReleaseOfDeactivatedAnalyticGoesToSyntheticByChartOfReleaseDate(): void
    {
        [$typeId, $valueId] = $this->costCenterCards(true);
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")->execute([$this->periodId]);
        $run = $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'pro_rata', null, $this->rv(), $this->meta());
        self::assertSame([[18100.0, $valueId]], $this->costCenterLines((int) $run['entry_id'], $typeId, 'credit', '501.100'), 'Sanity: odklad leží na 501.100.');
        $this->db->pdo()->prepare("UPDATE chart_of_accounts SET is_active = 0 WHERE supplier_id = ? AND account_code = '501.100'")->execute([$this->supplierId]);

        $nextStart = (self::YEAR + 1) . '-01-01';
        $this->periods->create($this->supplierId, self::YEAR + 1, $nextStart, (self::YEAR + 1) . '-12-31');
        $defer = (new \ReflectionMethod($this->closing, 'findEntryWithLines'))->invoke($this->closing, $this->supplierId, 'small_asset_accrual', ClosingSourceId::smallAssetAccrual($this->periodId));
        $release = (new \ReflectionMethod($this->closing, 'smallAssetReleaseEntryLines'))->invoke($this->closing, $this->supplierId, $defer, $nextStart);
        $releaseId = $this->posting->postDocument($this->supplierId, 'small_asset_accrual', ClosingSourceId::smallAssetAccrualRelease($this->periodId), $release, [
            'entry_date' => $nextStart, 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId,
        ]);

        self::assertSame([[18100.0, null], [18100.0, $valueId]], $this->costCenterLines($releaseId, $typeId, 'debit', self::DM));
    }

    /**
     * Karta navázaná jen na fakturu (bez položky), jejíž zápis nese 30 000 na 501.100 a 6 500
     * na 501.900: odklad se dělí poměrem nákladu analytik (82 : 18), ne rovným dílem po účtech.
     */
    public function testInvoiceWithoutItemSplitsDeferralByAnalyticAmounts(): void
    {
        $this->analytic501('501.100');
        $pdo = $this->db->pdo();
        $date = self::YEAR . '-07-01';
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
             VALUES (?, "Syntetický dodavatel", "Ulice 1", "Praha", "11000", ?, "dm-split@example.com", ?)'
        )->execute([$this->supplierId, $this->czId, $this->currencyId]);
        $vendorId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind,
                 vat_deduction, issue_date, tax_date, due_date, received_at, currency_id, reverse_charge,
                 is_fixed_asset, total_without_vat, total_vat, total_with_vat, status, vat_classification_code, created_by)
             VALUES (?, ?, "DM-SPLIT-1", "{}", "invoice", "none", ?, ?, ?, ?, ?, 0, 0, 36500, 0, 36500, "received", "40", ?)'
        )->execute([$this->supplierId, $vendorId, $date, $date, $date, $date, $this->currencyId, $this->userId]);
        $piId = (int) $pdo->lastInsertId();
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $piId, [
            ['account_code' => '501.100', 'side' => 'debit', 'amount' => 30000.00],
            ['account_code' => '501.900', 'side' => 'debit', 'amount' => 6500.00],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 36500.00],
        ], ['entry_date' => $date, 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        $this->smallAssets->insert($this->supplierId, [
            'name' => 'Notebook bez položky', 'acquisition_date' => $date, 'price' => 36500.00, 'quantity' => 1,
            'unit_price' => 36500.00, 'status' => 'in_use', 'purchase_invoice_id' => $piId,
        ], $this->userId);
        $pdo->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")->execute([$this->periodId]);

        $run = $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'pro_rata', null, $this->rv(), $this->meta());

        $lines = $this->entryLines((int) $run['entry_id']);
        self::assertEqualsWithDelta(14876.71, $this->sideAmount($lines, '501.100', 'credit'), 0.001);
        self::assertEqualsWithDelta(3223.29, $this->sideAmount($lines, '501.900', 'credit'), 0.001);
    }

    /**
     * Odklad zaúčtovaný verzí před dimenzemi (jeden řádek D 501 bez dimenzí na účtu pravidla)
     * se rozpustí stejně bez dimenzí: rozpuštění zrcadlí zápis odkladu, z dokladu karty
     * (faktura se střediskem) se nepřepočítává.
     */
    public function testLegacyDeferralWithoutDimensionsIsReleasedWithoutDimensions(): void
    {
        [$typeId] = $this->costCenterCards();
        $this->posting->postDocument($this->supplierId, 'small_asset_accrual', ClosingSourceId::smallAssetAccrual($this->periodId), [
            ['account_code' => '381', 'side' => 'debit', 'amount' => 36200.00],
            ['account_code' => self::DM, 'side' => 'credit', 'amount' => 36200.00],
        ], ['entry_date' => self::ENDS_ON, 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        $nextStart = (self::YEAR + 1) . '-01-01';
        $this->periods->create($this->supplierId, self::YEAR + 1, $nextStart, (self::YEAR + 1) . '-12-31');

        $defer = (new \ReflectionMethod($this->closing, 'findEntryWithLines'))->invoke($this->closing, $this->supplierId, 'small_asset_accrual', ClosingSourceId::smallAssetAccrual($this->periodId));
        $release = (new \ReflectionMethod($this->closing, 'smallAssetReleaseEntryLines'))->invoke($this->closing, $this->supplierId, $defer, $nextStart);
        $entryId = $this->posting->postDocument($this->supplierId, 'small_asset_accrual', ClosingSourceId::smallAssetAccrualRelease($this->periodId), $release, [
            'entry_date' => $nextStart, 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId,
        ]);

        self::assertSame([[36200.0, null]], $this->costCenterLines($entryId, $typeId, 'debit', self::DM));
        self::assertEqualsWithDelta(36200.00, $this->sideAmount($this->entryLines($entryId), '381', 'credit'), 0.001);
    }

    /**
     * Rozpuštění: neaktivní nákladová analytika (s tečkou, víceúrovňová i bez tečky) jde na svou
     * syntetiku, aktivní analytika zůstává, řádek 381 se nemění.
     */
    public function testReleaseMovesOnlyInactiveExpenseAnalyticToSynthetic(): void
    {
        $this->analytic501('501.100');
        $defer = ['id' => 0, 'lines' => [
            ['id' => 1, 'account_code' => '381.100', 'side' => 'debit', 'amount' => 100.0],
            ['id' => 2, 'account_code' => '501.100.10', 'side' => 'credit', 'amount' => 60.0],
            ['id' => 3, 'account_code' => '501.700', 'side' => 'credit', 'amount' => 20.0],
            ['id' => 4, 'account_code' => '501001', 'side' => 'credit', 'amount' => 10.0],
            ['id' => 5, 'account_code' => '501.100', 'side' => 'credit', 'amount' => 10.0],
        ]];
        $lines = (new \ReflectionMethod($this->closing, 'smallAssetReleaseLines'))->invoke($this->closing, $this->supplierId, $defer, (self::YEAR + 1) . '-01-01');

        self::assertSame(['381.100', '501', '501', '501', '501.100'], array_column($lines, 'account_code'));
    }

    /** Analytika syntetiky 501 v osnově (aktivní). */
    private function analytic501(string $code): void
    {
        $pdo = $this->db->pdo();
        $parent = $pdo->prepare('SELECT id, account_type, normal_side, tax_deductibility FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?');
        $parent->execute([$this->supplierId, '501']);
        $p = $parent->fetch(PDO::FETCH_ASSOC);
        $pdo->prepare(
            'INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id, is_active, tax_deductibility)
             VALUES (?, ?, ?, ?, ?, 0, ?, 1, ?) ON DUPLICATE KEY UPDATE is_active = 1'
        )->execute([$this->supplierId, $code, 'Analytika ' . $code, $p['account_type'], $p['normal_side'], (int) $p['id'], $p['tax_deductibility']]);
    }

    /** Středisko S1 účtotvorné s mapou 501 → 501.100 (analytika založená v osnově). */
    private function mapCostCenterToAnalytic(int $typeId, int $valueId): void
    {
        $container = Bootstrap::buildContainer();
        $this->analytic501('501.100');
        $container->get(\MyInvoice\Service\Accounting\Dimension\DimensionService::class)->updateType($this->supplierId, $typeId, ['drives_accounts' => true]);
        $container->get(\MyInvoice\Service\Accounting\Dimension\DimensionAccountMapService::class)
            ->saveForValue($this->supplierId, $valueId, [['synthetic_code' => '501', 'analytic_code' => '501.100']], $this->userId);
    }

    /**
     * Karta z přijaté faktury zaúčtované na středisko S1 a ruční karta bez dokladu,
     * obě 36 500 Kč pořízené 1. 7.
     *
     * @param bool $mapBeforePosting účtotvorné S1 s mapou 501 → 501.100 už při zaúčtování faktury
     * @return array{0:int,1:int} typ dimenze, hodnota S1
     */
    private function costCenterCards(bool $mapBeforePosting = false): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO dimension_types (supplier_id, code, name, kind, sort_order) VALUES (?, 'STR', 'Středisko', 'custom', 1)")->execute([$this->supplierId]);
        $typeId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO dimension_values (type_id, supplier_id, code, name) VALUES (?, ?, 'S1', 'Syntetické středisko')")->execute([$typeId, $this->supplierId]);
        $valueId = (int) $pdo->lastInsertId();
        if ($mapBeforePosting) {
            $this->mapCostCenterToAnalytic($typeId, $valueId);
        }
        $date = self::YEAR . '-07-01';
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
             VALUES (?, "Syntetický dodavatel", "Ulice 1", "Praha", "11000", ?, "dm-cc@example.com", ?)'
        )->execute([$this->supplierId, $this->czId, $this->currencyId]);
        $vendorId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind,
                 vat_deduction, issue_date, tax_date, due_date, received_at, currency_id, reverse_charge,
                 is_fixed_asset, total_without_vat, total_vat, total_with_vat, status, vat_classification_code, created_by)
             VALUES (?, ?, "DM-CC-1", "{}", "invoice", "none", ?, ?, ?, ?, ?, 0, 0, 36500, 0, 36500, "received", "40", ?)'
        )->execute([$this->supplierId, $vendorId, $date, $date, $date, $date, $this->currencyId, $this->userId]);
        $piId = (int) $pdo->lastInsertId();
        $vatRateId = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $pdo->prepare(
            "INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index, expense_kind)
             VALUES (?, 'Notebook', 1, 'ks', 36500, ?, 0, 36500, 0, 36500, 0, 'small_asset')"
        )->execute([$piId, $vatRateId]);
        $itemId = (int) $pdo->lastInsertId();
        // Faktura na syntetiku 501 (jako účet pravidla kroku): s mapou ji zaúčtování přesměruje na 501.100.
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $piId, [
            ['account_code' => '501', 'side' => 'debit', 'amount' => 36500.00, 'dimensions' => [$typeId => $valueId]],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 36500.00],
        ], ['entry_date' => $date, 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        $this->smallAssets->insert($this->supplierId, [
            'name' => 'Notebook se střediskem', 'acquisition_date' => $date, 'price' => 36500.00, 'quantity' => 1,
            'unit_price' => 36500.00, 'status' => 'in_use', 'purchase_invoice_id' => $piId, 'purchase_invoice_item_id' => $itemId,
        ], $this->userId);
        $this->card('Ruční karta', $date, 36500.00);
        return [$typeId, $valueId];
    }

    /** @return list<array{0:float,1:?int}> částka a hodnota střediska řádků 501 na dané straně */
    private function costCenterLines(int $entryId, int $typeId, string $side, string $account = '501'): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT l.amount, d.dimension_value_id
               FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id
               LEFT JOIN journal_entry_line_dimensions d ON d.line_id = l.id AND d.dimension_type_id = ?
              WHERE l.supplier_id = ? AND l.entry_id = ? AND a.account_code = ? AND l.side = ?"
        );
        $stmt->execute([$typeId, $this->supplierId, $entryId, $account, $side]);
        $rows = array_map(static fn (array $r): array => [round((float) $r['amount'], 2), $r['dimension_value_id'] === null ? null : (int) $r['dimension_value_id']], $stmt->fetchAll(PDO::FETCH_ASSOC));
        sort($rows);
        return $rows;
    }

    /**
     * §DM báze flat_pct = ČISTÝ OBRAT 501.200 (net dobropisů), NE báze karet. Účetní odkládá
     * % ze skutečného nákladu; evidence karet bývá neúplná / obsahuje i majetek vrácený v témže
     * období. Per-karta deferred_amount je proto u flat_pct null (rozhoduje total).
     */
    public function testFlatPctDefersPercentOf501TurnoverNotCardBase(): void
    {
        $this->card('Notebook', self::YEAR . '-07-01', 36500.00); // cards_total 36 500
        $this->expense501(50000.00);                              // obrat 501.200 = 50 000

        $preview = $this->closing->smallAssetAccrualPreview($this->supplierId, $this->periodId, 'flat_pct', 50.0);
        self::assertEqualsWithDelta(50000.00, $preview['breakdown_501_small_asset'], 0.001);
        self::assertEqualsWithDelta(36500.00, $preview['cards_total'], 0.001);
        // 50 % z obratu 501 (25 000), NE 50 % z karet (18 250).
        self::assertEqualsWithDelta(25000.00, $preview['total'], 0.001);
        self::assertNull($preview['items'][0]['deferred_amount'], 'U flat_pct je per-karta deferred_amount null.');
    }

    /** start → precheck → povinné kroky, s zaúčtovaným rozlišením v kroku deferrals. */
    private function driveCloseWithAccrual(string $mode, ?float $pct, ?float $materialityLimit = 1000000.00): void
    {
        $sid = $this->supplierId;
        $pid = $this->periodId;
        $this->closing->start($sid, $pid, $this->rv(), $this->meta());
        $this->closing->runPrecheck($sid, $pid, $this->rv(), $this->meta());
        $this->closing->confirmStep($sid, $pid, 'depreciation', 'skipped', null, $this->rv(), $this->meta());
        $this->closing->runFxRevaluation($sid, $pid, [], $this->rv(), $this->meta());
        $this->closing->confirmStep($sid, $pid, 'estimates', 'skipped', null, $this->rv(), $this->meta());
        $this->closing->runSmallAssetAccrual($sid, $pid, $mode, $pct, $this->rv(), $this->meta(), $materialityLimit);
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
}
