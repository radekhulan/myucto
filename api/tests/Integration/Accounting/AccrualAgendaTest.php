<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Accrual\AccrualAgendaGuard;
use MyInvoice\Service\Accounting\Accrual\AccrualException;
use MyInvoice\Service\Accounting\Accrual\AccrualItemService;
use MyInvoice\Service\Accounting\Accrual\AccrualPostingService;
use MyInvoice\Service\Accounting\Accrual\AccrualReconciliationService;
use MyInvoice\Service\Accounting\Accrual\AccrualScheduleCalculator;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Agenda Časové rozlišení: založení položky, plán, zaúčtování rozpuštění a storno,
 * předčasné ukončení a jeho zrušení, zámky období a odsouhlasení s hlavní knihou.
 * Syntetická firma v transakci s rollbackem.
 */
#[Group('integration')]
final class AccrualAgendaTest extends TestCase
{
    private const YEAR = 2091;

    private Connection $db;
    private PostingService $posting;
    private AccrualItemService $items;
    private AccrualPostingService $accrualPosting;
    private AccrualReconciliationService $reconciliation;
    private AccountingPeriodRepository $periods;

    private int $supplierId = 0;
    private int $userId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->posting = $container->get(PostingService::class);
        $this->items = $container->get(AccrualItemService::class);
        $this->accrualPosting = $container->get(AccrualPostingService::class);
        $this->reconciliation = $container->get(AccrualReconciliationService::class);
        $this->periods = $container->get(AccountingPeriodRepository::class);
        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $currencyId === 0 || $vatRateId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }
        $pdo->beginTransaction();
        $this->inTx = true;
        $pdo->prepare('INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
                       VALUES (?, "Testovací 1", "Praha", "11000", ?, ?, ?, ?)')
            ->execute(['Agenda ČR test s.r.o.', $czId, 'agenda-cr@example.com', $currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $this->periods->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
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

    // ── založení a plán ───────────────────────────────────────────────────────

    public function testCreateFromOriginEntryBuildsMonthlyPlanSummingToBasis(): void
    {
        $item = $this->revenueItem(36_500.00, '2091-01-07', '2092-01-06');
        self::assertSame('active', $item['status']);
        self::assertSame(36_500.00, $item['balance_after_all']);
        $releases = array_values(array_filter($item['schedule'], static fn (array $r): bool => $r['kind'] === 'release'));
        self::assertCount(13, $releases);
        self::assertSame('2091-01-31', $releases[0]['due_on']);
        self::assertSame('2091-01-07', $releases[0]['from_on']);
        self::assertSame('2092-01-06', end($releases)['due_on']);
        self::assertSame(36_500.00, round(array_sum(array_column($releases, 'amount_planned')), 2));
        // Úsek podle dnů stejný jako uzávěrka (sdílený výpočet).
        self::assertSame(AccrualScheduleCalculator::recognizedBetween(36_500.00, '2091-01-07', '2092-01-06', '2091-01-31', '2091-02-28'),
            $releases[1]['amount_planned']);
    }

    public function testValidationRejectsWrongAccountAndUnsupportedKind(): void
    {
        $this->assertAccrualError('invalid_account', fn () => $this->items->create($this->supplierId, $this->input(['accrual_account_code' => '381']), $this->userId));
        $this->assertAccrualError('unsupported_kind', fn () => $this->items->create($this->supplierId,
            $this->input(['kind' => 'accrued_revenue', 'accrual_account_code' => '385']), $this->userId));
        $this->assertAccrualError('invalid_counter_account', fn () => $this->items->create($this->supplierId, $this->input(['counter_account_code' => '381']), $this->userId));
    }

    // ── zaúčtování rozpuštění ────────────────────────────────────────────────

    public function testPostReleaseRowCreatesEntryLinkAndStornoReturnsRowToPlan(): void
    {
        $item = $this->revenueItem(12_000.00, '2091-01-01', '2091-12-31');
        $row = $this->firstPlanned($item);
        $after = $this->accrualPosting->postRow($this->supplierId, $item['id'], $row['id'], $this->meta());
        $posted = $this->row($after, $row['id']);
        self::assertSame('posted', $posted['status']);
        $entry = $this->entry((int) $posted['journal_entry_id']);
        self::assertSame('accrual_release', $entry['source_type']);
        self::assertSame($row['id'], (int) $entry['source_id']);
        self::assertSame($item['item_no'], $entry['document_no']);
        self::assertSame(['384|debit' => $row['amount_planned'], '602|credit' => $row['amount_planned']], $this->lines((int) $posted['journal_entry_id']));
        self::assertSame(round(12_000.00 - $row['amount_planned'], 2), $after['balance_after_all']);

        try {
            $this->accrualPosting->postRow($this->supplierId, $item['id'], $row['id'], $this->meta());
            self::fail('Dvojí zaúčtování téhož řádku musí selhat.');
        } catch (AccrualException $e) {
            self::assertSame('row_not_planned', $e->errorCode);
        }

        $back = $this->accrualPosting->unpostRow($this->supplierId, $item['id'], $row['id'], $this->meta());
        self::assertSame('planned', $this->row($back, $row['id'])['status']);
        self::assertSame(12_000.00, $back['balance_after_all']);
        self::assertNotNull($this->entry((int) $posted['journal_entry_id'])['reversed_by']);
    }

    public function testPostingRespectsDateLockAndClosedPeriod(): void
    {
        $item = $this->revenueItem(12_000.00, '2091-01-01', '2091-12-31');
        $row = $this->firstPlanned($item);
        $this->lockUntil('2091-03-31');
        $preview = $this->accrualPosting->previewRow($this->supplierId, $item['id'], $row['id']);
        self::assertFalse($preview['lock']['postable']);
        self::assertTrue($preview['lock']['date_locked']);
        try {
            $this->accrualPosting->postRow($this->supplierId, $item['id'], $row['id'], $this->meta());
            self::fail('Zaúčtování do uzamčeného data musí selhat.');
        } catch (PostingException $e) {
            self::assertSame('date_locked', $e->errorCode);
        }
        $this->lockUntil(null);
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closed' WHERE supplier_id = ? AND fiscal_year = ?")
            ->execute([$this->supplierId, self::YEAR]);
        try {
            $this->accrualPosting->postRow($this->supplierId, $item['id'], $row['id'], $this->meta());
            self::fail('Zaúčtování do uzavřeného období musí selhat.');
        } catch (PostingException $e) {
            self::assertSame('period_not_open', $e->errorCode);
        }
    }

    // ── předčasné ukončení ───────────────────────────────────────────────────

    public function testTerminationReleasesToDateAndRemainderInOneEntryAndCanBeCancelled(): void
    {
        $item = $this->revenueItem(36_500.00, '2091-01-01', '2091-12-31', ['termination_account_code' => '641']);
        foreach (array_slice($this->planned($item), 0, 2) as $row) {
            $this->accrualPosting->postRow($this->supplierId, $item['id'], $row['id'], $this->meta());
        }
        // Pokryto do 28. 2. (59 dní), ukončení 15. 3. → rozpuštění 15 dní, zbytek na 641.
        $preview = $this->accrualPosting->terminationPreview($this->supplierId, $item['id'], '2091-03-15');
        $release = AccrualScheduleCalculator::recognizedBetween(36_500.00, '2091-01-01', '2091-12-31', '2091-02-28', '2091-03-15');
        $balance = round(36_500.00 - AccrualScheduleCalculator::cumRecognized(36_500.00, '2091-01-01', '2091-12-31', '2091-02-28'), 2);
        self::assertSame('2091-02-28', $preview['covered_through']);
        self::assertSame($balance, $preview['balance']);
        self::assertSame($release, $preview['release_amount']);
        self::assertSame(round($balance - $release, 2), $preview['remainder_amount']);
        self::assertTrue($preview['lock']['postable']);

        $terminated = $this->accrualPosting->terminate($this->supplierId, $item['id'], ['date' => '2091-03-15', 'reason' => 'Doplacení'], $this->meta());
        self::assertSame('terminated', $terminated['status']);
        self::assertSame(0.0, $terminated['balance_after_all']);
        $entry = $this->entry((int) $terminated['termination_entry_id']);
        self::assertSame('accrual_termination', $entry['source_type']);
        self::assertSame(['384|debit' => $balance, '602|credit' => $release, '641|credit' => round($balance - $release, 2)],
            $this->lines((int) $terminated['termination_entry_id']));
        self::assertSame([], $this->planned($terminated), 'Plán po ukončení se ruší.');

        $restored = $this->accrualPosting->cancelTermination($this->supplierId, $item['id'], $this->meta());
        self::assertSame('active', $restored['status']);
        self::assertSame($balance, $restored['balance_after_all']);
        self::assertNotNull($this->entry((int) $terminated['termination_entry_id'])['reversed_by']);
        self::assertSame($balance, round(array_sum(array_column($this->planned($restored), 'amount_planned')), 2));
    }

    public function testTerminationGuards(): void
    {
        $this->accrualPosting->setToday('2091-03-15');
        $item = $this->revenueItem(36_500.00, '2091-01-01', '2091-12-31');
        foreach (array_slice($this->planned($item), 0, 3) as $row) {
            $this->accrualPosting->postRow($this->supplierId, $item['id'], $row['id'], $this->meta());
        }
        // Únor je zaúčtovaný do dneška → ukončení před jeho koncem nejde.
        $this->assertAccrualError('before_last_release', fn () => $this->accrualPosting->terminationPreview($this->supplierId, $item['id'], '2091-02-15'));
        // Březen je zaúčtovaný dopředu (zápis 31. 3. je po dnešku) → nabídne se storno.
        $preview = $this->accrualPosting->terminationPreview($this->supplierId, $item['id'], '2091-03-10');
        self::assertCount(1, $preview['future_rows']);
        self::assertSame('2091-02-28', $preview['covered_through']);
        self::assertFalse($preview['requires_linked_confirmation']);
        $this->assertAccrualError('future_rows', fn () => $this->accrualPosting->terminate($this->supplierId, $item['id'], ['date' => '2091-03-10'], $this->meta()));
        $done = $this->accrualPosting->terminate($this->supplierId, $item['id'], ['date' => '2091-03-10', 'reverse_future' => true], $this->meta());
        self::assertSame('terminated', $done['status']);
        self::assertSame(0.0, $done['balance_after_all']);

        // Zrušení ukončení v uzamčeném období nejde.
        $this->lockUntil('2091-06-30');
        $this->assertAccrualError('period_locked', fn () => $this->accrualPosting->cancelTermination($this->supplierId, $item['id'], $this->meta()));
    }

    public function testImportedFutureRowsNeedExplicitConfirmation(): void
    {
        $this->accrualPosting->setToday('2091-07-15');
        $item = $this->revenueItem(36_500.00, '2091-01-01', '2091-12-31');
        // Převzatý zápis rozpuštění za prosinec (jako předgenerovaný POHODOU) navázaný jako linked.
        $entryId = $this->manualEntry('2091-12-31', '384', '602', 3_100.00, 'CR-SYN-1');
        $rowId = $this->linkedRow($item['id'], $entryId, '2091-12-01', '2091-12-31', 3_100.00);
        $preview = $this->accrualPosting->terminationPreview($this->supplierId, $item['id'], '2091-06-30');
        self::assertTrue($preview['requires_linked_confirmation']);
        self::assertSame($rowId, $preview['future_rows'][0]['id']);
        $this->assertAccrualError('linked_confirmation', fn () => $this->accrualPosting->terminate($this->supplierId, $item['id'],
            ['date' => '2091-06-30', 'reverse_future' => true], $this->meta()));
        $done = $this->accrualPosting->terminate($this->supplierId, $item['id'],
            ['date' => '2091-06-30', 'reverse_future' => true, 'confirm_linked' => true], $this->meta());
        self::assertSame(0.0, $done['balance_after_all']);
        self::assertNotNull($this->entry($entryId)['reversed_by'], 'Převzatý budoucí zápis se stornuje.');
    }

    // ── odsouhlasení s hlavní knihou ─────────────────────────────────────────

    public function testReconciliationMatchesLedgerAndListsUnallocatedLines(): void
    {
        $item = $this->revenueItem(10_000.00, '2091-01-01', '2091-12-31');
        $stray = $this->manualEntry('2091-05-10', '311', '384', 2_500.00, 'ID-SYN-9');

        $rec = $this->reconciliation->reconcile($this->supplierId, '2091-06-30');
        $acc = $this->account($rec, '384');
        self::assertSame(12_500.00, $acc['ledger_balance']);
        self::assertSame(10_000.00, $acc['items_balance']);
        self::assertSame(2_500.00, $acc['difference']);
        $un = $this->reconciliation->unallocated($this->supplierId, '2091-06-30');
        self::assertSame(2_500.00, $un['total']);
        self::assertSame($stray, $un['rows'][0]['entry_id']);

        $this->reconciliation->assign($this->supplierId, $item['id'], $stray, '384', null, $this->userId);
        self::assertSame(0.0, $this->account($this->reconciliation->reconcile($this->supplierId, '2091-06-30'), '384')['difference']);
        $this->assertAccrualError('allocation_exceeds', fn () => $this->reconciliation->assign($this->supplierId, $item['id'], $stray, '384', '1', $this->userId));

        // Zaúčtované rozpuštění agendy je v hlavní knize i v položce, rozdíl zůstává nulový.
        $this->accrualPosting->postRow($this->supplierId, $item['id'], $this->firstPlanned($item)['id'], $this->meta());
        self::assertSame(0.0, $this->account($this->reconciliation->reconcile($this->supplierId, '2091-06-30'), '384')['difference']);
    }

    // ── průběžný výkaz a projekce DPPO ───────────────────────────────────────

    public function testInterimAddsOnlyUnpostedPlanAndYearlyProRata(): void
    {
        $guard = new AccrualAgendaGuard($this->db);
        $monthly = $this->revenueItem(12_000.00, '2091-01-01', '2091-12-31');
        // K 15. 3.: splatné jsou leden a únor, rozpracovaný březen ne.
        $jan = AccrualScheduleCalculator::recognizedBetween(12_000.00, '2091-01-01', '2091-12-31', '2090-12-31', '2091-02-28');
        $interim = $guard->interimDeltas($this->supplierId, '2091-01-01', '2091-03-15');
        self::assertSame($jan, $interim['total']);
        self::assertSame($jan, $interim['deltas']['384']);
        self::assertSame(-$jan, $interim['deltas']['602']);
        // Zaúčtovaný leden už je v deníku, výkaz ho nepřidá podruhé.
        $this->accrualPosting->postRow($this->supplierId, $monthly['id'], $this->firstPlanned($monthly)['id'], $this->meta());
        $janOnly = AccrualScheduleCalculator::recognizedBetween(12_000.00, '2091-01-01', '2091-12-31', '2090-12-31', '2091-01-31');
        self::assertSame(round($jan - $janOnly, 2), $guard->interimDeltas($this->supplierId, '2091-01-01', '2091-03-15')['total']);

        $this->revenueItem(36_500.00, '2091-01-01', '2092-12-31', ['method' => 'yearly']);
        $yearlyPart = AccrualScheduleCalculator::recognizedBetween(36_500.00, '2091-01-01', '2092-12-31', '2090-12-31', '2091-03-15');
        self::assertSame(round($jan - $janOnly + $yearlyPart, 2), $guard->interimDeltas($this->supplierId, '2091-01-01', '2091-03-15')['total']);

        // Projekce DPPO: všechny nezaúčtované řádky období do konce roku (výnos 384 → +VH).
        $projection = $guard->projectionPending($this->supplierId, '2091-01-01', '2091-12-31');
        $yearly2091 = AccrualScheduleCalculator::recognizedBetween(36_500.00, '2091-01-01', '2092-12-31', '2090-12-31', '2091-12-31');
        self::assertSame(round(12_000.00 - $janOnly + $yearly2091, 2), $projection['revenue']);
        self::assertSame(0.0, $projection['expense']);
    }

    // ── krok uzávěrky a životní cyklus ───────────────────────────────────────

    public function testClosingStepPostsDueRowsIntoClosingPeriod(): void
    {
        $yearly = $this->revenueItem(36_500.00, '2091-01-01', '2092-12-31', ['method' => 'yearly']);
        $this->revenueItem(12_000.00, '2091-01-01', '2091-12-31');
        $pending = $this->accrualPosting->closingPending($this->supplierId, '2091-01-01', '2091-12-31');
        self::assertCount(1, $pending['yearly']);
        self::assertCount(12, $pending['monthly']);
        self::assertSame(12_000.00, $pending['monthly_total']);
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closing' WHERE supplier_id = ? AND fiscal_year = ?")
            ->execute([$this->supplierId, self::YEAR]);
        $period = $this->db->pdo()->query("SELECT id, row_version FROM accounting_periods WHERE supplier_id = {$this->supplierId} AND fiscal_year = 2091")->fetch(\PDO::FETCH_ASSOC);
        $this->lockUntil('2091-12-31');
        $result = Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Closing\ClosingService::class)
            ->runAccrualAgendaRelease($this->supplierId, (int) $period['id'], (int) $period['row_version'], ['user_id' => $this->userId]);
        // Krok uzávěrky zaúčtuje roční i měsíční řádky splatné do konce období (i za zámkem data).
        self::assertSame(13, $result['posted']);
        self::assertSame(round($pending['yearly_total'] + $pending['monthly_total'], 2), $result['total']);
        $after = $this->accrualPosting->closingPending($this->supplierId, '2091-01-01', '2091-12-31');
        self::assertSame([], $after['yearly']);
        self::assertSame([], $after['monthly']);
        $after = $this->items->get($this->supplierId, $yearly['id']);
        self::assertSame(round(36_500.00 - $pending['yearly_total'], 2), $after['balance_after_all']);
    }

    public function testCancelAndDeleteOnlyWithoutAgendaPostings(): void
    {
        $item = $this->revenueItem(12_000.00, '2091-01-01', '2091-12-31');
        $this->accrualPosting->postRow($this->supplierId, $item['id'], $this->firstPlanned($item)['id'], $this->meta());
        $this->assertAccrualError('has_postings', fn () => $this->items->cancel($this->supplierId, $item['id'], $this->userId));
        $this->assertAccrualError('not_deletable', fn () => $this->items->delete($this->supplierId, $item['id']));

        $draft = $this->items->create($this->supplierId, $this->input(), $this->userId);
        self::assertSame('draft', $draft['status']);
        self::assertSame([], $draft['schedule']);
        self::assertSame('active', $this->items->activate($this->supplierId, $draft['id'], $this->userId)['status']);
        self::assertSame('cancelled', $this->items->cancel($this->supplierId, $draft['id'], $this->userId)['status']);

        // Zrušení uvolní vazbu vzniku: zápis se v odsouhlasení vrátí mezi řádky bez položky.
        $other = $this->revenueItem(5_000.00, '2091-01-01', '2091-12-31');
        $cancelled = $this->items->cancel($this->supplierId, $other['id'], $this->userId);
        self::assertSame([], $cancelled['links']);
        $rec = $this->account($this->reconciliation->reconcile($this->supplierId, '2091-06-30'), '384');
        self::assertSame(5_000.00, $rec['difference']);
        self::assertSame(5_000.00, $this->reconciliation->unallocated($this->supplierId, '2091-06-30')['total']);
        $this->items->delete($this->supplierId, $draft['id']);
        $this->assertAccrualError('not_found', fn () => $this->items->get($this->supplierId, $draft['id']));
    }

    // ── regrese z review: plán, vazby, uzávěrka ──────────────────────────────

    /**
     * Nezaúčtovaný řádek před navázaným (převzatým) rozpuštěním zůstává na svém místě po úpravě
     * poznámky i po změně plánu; účtovat jde jen nejstarší řádek a stornovat jen poslední.
     */
    public function testEditKeepsUnpostedRowBeforePostedOne(): void
    {
        $item = $this->revenueItem(12_000.00, '2091-07-01', '2092-06-30');
        $dec = $this->rowOn($item, '2091-12-31');
        $jan = $this->rowOn($item, '2092-01-31');
        $entry = $this->manualEntry('2092-01-31', '384', '602', $jan['amount_planned'], 'CR-SYN-JAN');
        $this->db->pdo()->prepare('DELETE FROM accrual_schedule WHERE id = ?')->execute([$jan['id']]);
        $this->linkedRow($item['id'], $entry, '2092-01-01', '2092-01-31', $jan['amount_planned']);
        $before = $this->items->get($this->supplierId, $item['id']);
        self::assertSame('planned', $this->rowOn($before, '2091-12-31')['status']);
        $planned2091 = $this->sumPlannedIn($before, '2091');

        $after = $this->items->update($this->supplierId, $item['id'], ['note' => 'jen poznámka', 'row_version' => $before['row_version']], $this->userId);
        self::assertSame($planned2091, $this->sumPlannedIn($after, '2091'), 'Úprava poznámky přesunula rozpuštění roku 2091.');
        $rebuilt = $this->items->update($this->supplierId, $item['id'], ['period_to' => '2092-06-30', 'basis_amount' => 12_000.00,
            'method' => 'monthly_days', 'title' => 'Přejmenovaná smlouva', 'row_version' => $after['row_version']], $this->userId);
        self::assertSame($planned2091, $this->sumPlannedIn($rebuilt, '2091'));
        $this->items->rebuildPlan($this->supplierId, $item['id']);
        $forced = $this->items->get($this->supplierId, $item['id']);
        self::assertSame('planned', $this->rowOn($forced, '2091-12-31')['status'], 'Přepočet plánu zahodil řádek před navázaným rozpuštěním.');
        self::assertSame(round($forced['balance_after_all'], 2), round(array_sum(array_column($this->planned($forced), 'amount_planned')), 2));

        $this->assertAccrualError('row_not_first', fn () => $this->accrualPosting->postRow($this->supplierId, $item['id'], $this->rowOn($forced, '2092-02-29')['id'], $this->meta()));
        $first = $this->accrualPosting->postRow($this->supplierId, $item['id'], $this->planned($forced)[0]['id'], $this->meta());
        $second = $this->accrualPosting->postRow($this->supplierId, $item['id'], $this->planned($first)[0]['id'], $this->meta());
        $this->assertAccrualError('row_not_last', fn () => $this->accrualPosting->unpostRow($this->supplierId, $item['id'], $this->rowOn($second, '2091-07-31')['id'], $this->meta()));
    }

    /** Změna základu po zaúčtované části: zbytek se rozloží poměrem dnů, nenaskočí do posledního řádku. */
    public function testBasisChangeIsSpreadNotLumped(): void
    {
        $item = $this->revenueItem(12_000.00, '2091-01-01', '2091-12-31');
        foreach (range(1, 6) as $_) {
            $item = $this->accrualPosting->postRow($this->supplierId, $item['id'], $this->planned($item)[0]['id'], $this->meta());
        }
        $this->reconciliation->assign($this->supplierId, $item['id'], $this->manualEntry('2091-01-01', '311', '384', 12_000.00, 'FV-SYN-DOPL'), '384', null, $this->userId);
        $after = $this->items->update($this->supplierId, $item['id'], ['basis_amount' => 24_000.00, 'row_version' => $this->items->get($this->supplierId, $item['id'])['row_version']], $this->userId);
        $rows = $this->planned($after);
        self::assertSame(round($after['balance_after_all'], 2), round(array_sum(array_column($rows, 'amount_planned')), 2));
        $perDay = array_map(static fn (array $r): float => $r['amount_planned'] / $r['days'], $rows);
        self::assertLessThan(0.05, max($perDay) - min($perDay), 'Zbytek naskočil do posledního řádku: ' . json_encode(array_column($rows, 'amount_planned')));
    }

    /** Ručně přiřazené rozpuštění zkrátí plán a zrušení vazby ho vrátí. */
    public function testManualReleaseLinkShortensPlan(): void
    {
        $item = $this->revenueItem(12_000.00, '2091-01-01', '2091-12-31');
        $manual = $this->manualEntry('2091-03-15', '384', '602', 1_000.00, 'ID-SYN-1');
        $this->reconciliation->assign($this->supplierId, $item['id'], $manual, '384', null, $this->userId);
        $after = $this->items->get($this->supplierId, $item['id']);
        self::assertSame(11_000.00, $after['balance_after_all']);
        self::assertSame($after['balance_after_all'], round(array_sum(array_column($this->planned($after), 'amount_planned')), 2), 'Plán rozpustí víc, než je zůstatek položky.');
        $link = array_values(array_filter($after['links'], static fn (array $l): bool => $l['journal_entry_id'] === $manual))[0];
        $restored = $this->items->get($this->supplierId, $item['id']);
        $this->reconciliation->unassign($this->supplierId, $item['id'], $link['id']);
        $restored = $this->items->get($this->supplierId, $item['id']);
        self::assertSame(12_000.00, round(array_sum(array_column($this->planned($restored), 'amount_planned')), 2));
    }

    /** Rozpuštění nikdy nepřekročí zůstatek položky v deníku. */
    public function testReleaseIsCappedAtBalance(): void
    {
        $item = $this->revenueItem(1_000.00, '2091-01-01', '2091-02-28');
        $this->db->pdo()->prepare("UPDATE accrual_schedule SET amount_planned = 900 WHERE accrual_item_id = ? AND due_on = '2091-01-31'")->execute([$item['id']]);
        $this->manualEntry('2091-01-20', '384', '602', 500.00, 'ID-SYN-CAP');
        $this->reconciliation->assign($this->supplierId, $item['id'], (int) $this->db->pdo()->query("SELECT id FROM journal_entries WHERE supplier_id = {$this->supplierId} AND document_no = 'ID-SYN-CAP'")->fetchColumn(), '384', null, $this->userId);
        $this->db->pdo()->prepare("UPDATE accrual_schedule SET amount_planned = 900 WHERE accrual_item_id = ? AND due_on = '2091-01-31' AND status = 'planned'")->execute([$item['id']]);
        $after = $this->accrualPosting->postRow($this->supplierId, $item['id'], $this->rowOn($this->items->get($this->supplierId, $item['id']), '2091-01-31')['id'], $this->meta());
        self::assertSame(0.0, $after['balance_after_all']);
        self::assertSame(500.00, $this->rowOn($after, '2091-01-31')['amount_planned']);
    }

    /** Druh ani účet položky s navázaným zápisem vzniku nejde změnit (vazby by zůstaly na starém účtu). */
    public function testKindChangeWithOriginLink(): void
    {
        $item = $this->revenueItem(12_000.00, '2091-01-01', '2091-12-31');
        $this->assertAccrualError('has_links', fn () => $this->items->update($this->supplierId, $item['id'],
            ['kind' => 'prepaid_expense', 'accrual_account_code' => '381', 'counter_account_code' => '518', 'row_version' => $item['row_version']], $this->userId));
        $this->assertAccrualError('changed', fn () => $this->items->update($this->supplierId, $item['id'], ['note' => 'bez verze'], $this->userId));
        self::assertSame(0.0, $this->reconciliation->reconcile($this->supplierId, '2091-12-31')['totals']['difference']);
    }

    /**
     * Roční rozpuštění zaúčtuje uzávěrka jen v období ve stavu uzavírání. V otevřeném období
     * platí zámek k datu jako u každého zápisu agendy.
     */
    public function testYearlyClosingPostRespectsDateLockInOpenPeriod(): void
    {
        $item = $this->revenueItem(36_600.00, '2091-07-01', '2092-06-30', ['method' => 'yearly']);
        $this->lockUntil('2091-12-31');
        $closing = Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Closing\ClosingService::class);
        $period = $this->db->pdo()->query("SELECT id, row_version FROM accounting_periods WHERE supplier_id = {$this->supplierId} AND fiscal_year = 2091")->fetch(\PDO::FETCH_ASSOC);
        try {
            $closing->runAccrualAgendaRelease($this->supplierId, (int) $period['id'], (int) $period['row_version'], ['user_id' => $this->userId]);
            self::fail('Uzávěrka zaúčtovala rozpuštění agendy v otevřeném období.');
        } catch (\MyInvoice\Service\Accounting\Closing\ClosingException $e) {
            self::assertSame('period_not_closing', $e->errorCode);
        }
        try {
            $this->accrualPosting->postRow($this->supplierId, $item['id'], $this->planned($item)[0]['id'], $this->meta());
            self::fail('Zaúčtovalo se do uzamčeného data v otevřeném období.');
        } catch (PostingException $e) {
            self::assertSame('date_locked', $e->errorCode);
        }
    }

    // ── pomocné ──────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $extra */
    private function revenueItem(float $basis, string $from, string $to, array $extra = []): array
    {
        $origin = $this->manualEntry($from, '311', '384', $basis, 'FV-SYN-' . random_int(1000, 9999));
        return $this->items->create($this->supplierId, $this->input([
            'basis_amount' => $basis, 'period_from' => $from, 'period_to' => $to, 'origin_entry_id' => $origin, 'activate' => true,
        ] + $extra), $this->userId);
    }

    /** @param array<string,mixed> $override @return array<string,mixed> */
    private function input(array $override = []): array
    {
        return $override + [
            'title' => 'Syntetická smlouva o pronájmu',
            'kind' => 'deferred_revenue',
            'accrual_account_code' => '384',
            'counter_account_code' => '602',
            'basis_amount' => 12_000.00,
            'period_from' => self::YEAR . '-01-01',
            'period_to' => self::YEAR . '-12-31',
            'method' => 'monthly_days',
        ];
    }

    private function manualEntry(string $date, string $debit, string $credit, float $amount, string $docNo): int
    {
        return $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => $debit, 'side' => 'debit', 'amount' => $amount],
            ['account_code' => $credit, 'side' => 'credit', 'amount' => $amount],
        ], ['entry_date' => $date, 'document_no' => $docNo, 'description' => 'Syntetický zápis ' . $docNo, 'posted' => true,
            'user_id' => $this->userId, 'posted_by' => $this->userId]);
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function rowOn(array $item, string $due): array
    {
        foreach ($item['schedule'] as $r) {
            if ($r['due_on'] === $due && $r['kind'] === 'release') {
                return $r;
            }
        }
        self::fail('Řádek ' . $due . ' chybí.');
    }

    /** @param array<string,mixed> $item */
    private function sumPlannedIn(array $item, string $year): float
    {
        $sum = 0.0;
        foreach ($this->planned($item) as $r) {
            if (str_starts_with($r['due_on'], $year)) {
                $sum += $r['amount_planned'];
            }
        }
        return round($sum, 2);
    }

    private function linkedRow(int $itemId, int $entryId, string $from, string $to, float $amount): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("DELETE FROM accrual_schedule WHERE supplier_id = ? AND accrual_item_id = ? AND due_on = ? AND kind = 'release'")
            ->execute([$this->supplierId, $itemId, $to]);
        $pdo->prepare("INSERT INTO accrual_schedule (supplier_id, accrual_item_id, kind, due_on, from_on, to_on, amount_planned, status, journal_entry_id)
                       VALUES (?, ?, 'release', ?, ?, ?, ?, 'linked', ?)")
            ->execute([$this->supplierId, $itemId, $to, $from, $to, $amount, $entryId]);
        $rowId = (int) $pdo->lastInsertId();
        $accountId = (int) $pdo->query("SELECT id FROM chart_of_accounts WHERE supplier_id = {$this->supplierId} AND account_code = '384'")->fetchColumn();
        $pdo->prepare("INSERT INTO accrual_entry_links (supplier_id, accrual_item_id, journal_entry_id, account_id, schedule_id, amount, role, matched_by)
                       VALUES (?, ?, ?, ?, ?, ?, 'release', 'auto_document_no')")
            ->execute([$this->supplierId, $itemId, $entryId, $accountId, $rowId, $amount]);
        return $rowId;
    }

    private function lockUntil(?string $date): void
    {
        $this->db->pdo()->prepare('INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, ?) ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)')
            ->execute([$this->supplierId, $date]);
    }

    /** @return array<string,mixed> */
    private function meta(): array
    {
        return ['user_id' => $this->userId, 'allow_future' => true];
    }

    /** @param array<string,mixed> $item @return list<array<string,mixed>> */
    private function planned(array $item): array
    {
        return array_values(array_filter($item['schedule'], static fn (array $r): bool => $r['kind'] === 'release' && $r['status'] === 'planned'));
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function firstPlanned(array $item): array
    {
        return $this->planned($item)[0];
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function row(array $item, int $rowId): array
    {
        foreach ($item['schedule'] as $r) {
            if ($r['id'] === $rowId) {
                return $r;
            }
        }
        self::fail('Řádek plánu chybí.');
    }

    /** @return array<string,mixed> */
    private function entry(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM journal_entries WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $this->supplierId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    /** @return array<string,float> "účet|strana" => částka */
    private function lines(int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ca.account_code, l.side, SUM(l.amount) AS a FROM journal_entry_lines l JOIN chart_of_accounts ca ON ca.id = l.account_id
                                            WHERE l.entry_id = ? AND l.supplier_id = ? GROUP BY ca.account_code, l.side ORDER BY ca.account_code, l.side');
        $stmt->execute([$entryId, $this->supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[$r['account_code'] . '|' . $r['side']] = round((float) $r['a'], 2);
        }
        return $out;
    }

    /** @param array<string,mixed> $rec @return array<string,mixed> */
    private function account(array $rec, string $code): array
    {
        foreach ($rec['accounts'] as $a) {
            if ($a['account_code'] === $code) {
                return $a;
            }
        }
        self::fail('Účet ' . $code . ' v odsouhlasení chybí.');
    }

    private function assertAccrualError(string $code, callable $fn): void
    {
        try {
            $fn();
            self::fail('Očekávána chyba ' . $code);
        } catch (AccrualException $e) {
            self::assertSame($code, $e->errorCode, $e->getMessage());
        }
    }
}
