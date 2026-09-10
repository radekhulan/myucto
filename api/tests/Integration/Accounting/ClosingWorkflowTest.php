<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingException;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\EntityCategoryService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Integrační testy uzávěrkového workflow (Epic F4, §6.2 I1–I6): celý řetěz
 * start → precheck → kroky → closeBooks → openNext, bilanční kontinuita
 * 702↔701, idempotence re-run, revert kroků (R12), gating a tenant izolace.
 *
 * IZOLOVANÝ SUPPLIER (vzor FinancialStatementTest): kumulativní rozvahové
 * zůstatky nesmí záviset na sdíleném supplieru s dev daty. Vše běží v jedné
 * transakci, kterou tearDown rollbackne. Soft-skip bez cfg.php.
 */
#[Group('integration')]
final class ClosingWorkflowTest extends TestCase
{
    private const YEAR = 2098;
    private const ENDS_ON = self::YEAR . '-12-31';

    private Connection $db;
    private PostingService $posting;
    private ClosingService $closing;
    private AccountingPeriodRepository $periods;
    private JournalEntryRepository $journal;
    private EntityCategoryService $categories;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db      = $container->get(Connection::class);
            $this->posting = $container->get(PostingService::class);
            $this->closing = $container->get(ClosingService::class);
            $this->periods = $container->get(AccountingPeriodRepository::class);
            $this->journal = $container->get(JournalEntryRepository::class);
            $this->categories = $container->get(EntityCategoryService::class);
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

        $this->supplierId = $this->createSupplier('F4 uzávěrka test s.r.o.', 'f4-closing@example.com', $czId, $currencyId, $vatRateId);
        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::ENDS_ON);
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

    // ── I1: celý řetěz do closeBooks ─────────────────────────────────────────

    public function testI1FullChainClosesBooksWithBalancedClosingEntry(): void
    {
        $this->seedScenario();
        $this->runStepsUntilCloseReady();

        $result = $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        self::assertSame('UZ-' . self::YEAR . '-0001', $result['document_no'], 'Closing doklad z řady UZ (R13).');
        self::assertSame(self::cents(6000.00), self::cents((float) $result['profit']), 'VH = 10 000 − 4 000 = 6 000.');
        self::assertSame('closed', $result['status']);
        self::assertSame('closed', $this->period()['status'], 'Období je closed.');

        $entry = $this->journal->findBySource($this->supplierId, 'closing', $this->periodId);
        self::assertNotNull($entry, "Existuje zápis ('closing', period_id).");
        self::assertSame(self::ENDS_ON, (string) $entry['entry_date'], 'entry_date = ends_on (R6).');
        self::assertNotNull($entry['posted_at']);

        $byCode = $this->linesByAccountCode((int) $entry['id']);
        // VH 6 000 přes 710 → 702; Σ 702 = 0
        self::assertSame(self::cents(10000.00), self::cents($byCode['710']['debit']), '710 MD = náklady 4 000 + zisk 6 000.');
        self::assertSame(self::cents(10000.00), self::cents($byCode['710']['credit']), '710 D = výnosy 10 000.');
        self::assertSame(self::cents(12100.00), self::cents($byCode['702']['debit']), 'Σ702 MD = 311 7 100 + 221 5 000.');
        self::assertSame(self::cents(12100.00), self::cents($byCode['702']['credit']), 'Σ702 D = VH 6 000 + 321 4 840 + 343 1 260.');
        self::assertSame(
            self::cents($byCode['702']['debit']),
            self::cents($byCode['702']['credit']),
            '702 končí na nule (R8 invariant).',
        );

        // KUMULATIVNÍ zůstatek KAŽDÉHO účtu (vč. 702/710) k ends_on včetně closing zápisu == 0
        $nonZero = $this->nonZeroCumulativeBalances(self::ENDS_ON);
        self::assertSame([], $nonZero, 'Po uzavření knih má každý účet kumulativní zůstatek 0.');
    }

    /**
     * EP-14: uzavření knih zmrazí historickou kategorii ÚJ a výsledek to hlásí
     * (category_frozen=true, bez warningu). Zmražená kategorie je pak předpokladem
     * zákonného schválení (approved) — blok schválení řeší AccountingPeriodAction.
     */
    public function testCloseBooksFreezesCategoryAndReportsIt(): void
    {
        $this->seedScenario();
        $this->runStepsUntilCloseReady();

        $result = $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        self::assertTrue($result['category_frozen'], 'Uzavření knih zmrazí kategorii ÚJ.');
        self::assertArrayNotHasKey('warning', $result, 'Bez selhání zmražení není warning.');
        self::assertTrue(
            $this->categories->isFrozen($this->supplierId, $this->periodId),
            'Po uzavření je historická kategorie uložená (předpoklad schválení).',
        );
    }

    public function testReviewedPeriodAllowsOpeningAndFollowingClosing(): void
    {
        $this->seedScenario();
        $this->runStepsUntilCloseReady();
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        self::assertTrue($this->periods->setStatusCas(
            $this->periodId, $this->supplierId, 'reviewed', $this->rv(), $this->userId,
        ));
        self::assertTrue($this->closing->state($this->supplierId, $this->periodId)['can_open_next']);
        $opened = $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        self::assertSame('reviewed', $this->period()['status']);
        $nextId = (int) $opened['next_period_id'];
        $state = $this->closing->state($this->supplierId, $nextId);
        self::assertTrue($state['can_start']);
        $this->manual([self::l('431', 'debit', 6000), self::l('428', 'credit', 6000)], (self::YEAR + 1) . '-01-02');
        $this->closing->start($this->supplierId, $nextId, $state['row_version'], $this->meta());
        $period = $this->periods->findById($this->supplierId, $nextId);
        self::assertSame('closing', $period['status']);
        $checks = new \ReflectionMethod(ClosingService::class, 'buildErrorChecks');
        $results = $checks->invoke($this->closing, $this->supplierId, $period);
        $prior = array_values(array_filter($results, static fn (array $check): bool => $check['key'] === 'prior_period_open'));
        self::assertCount(1, $prior);
        self::assertTrue($prior[0]['ok']);
    }

    public function testReviewedNextYearDoesNotOfferRevertingItsOpening(): void
    {
        $this->seedScenario();
        $this->runStepsUntilCloseReady();
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $opened = $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $next = $this->periods->findById($this->supplierId, (int) $opened['next_period_id']);
        self::assertTrue($this->periods->setStatusCas(
            (int) $next['id'], $this->supplierId, 'reviewed', (int) $next['row_version'], $this->userId,
        ));
        self::assertFalse($this->closing->state($this->supplierId, $this->periodId)['can_revert_open_next']);
    }

    // ── I2: bilanční kontinuita 702↔701 ─────────────────────────────────────

    public function testI2OpenNextMirrorsClosingIntoOpening(): void
    {
        $this->seedScenario();
        $this->runStepsUntilCloseReady();
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        $result = $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        $next = $this->periods->nextPeriod($this->supplierId, self::ENDS_ON);
        self::assertNotNull($next, 'Následující období 2099 se založilo (R5).');
        self::assertSame((self::YEAR + 1) . '-01-01', (string) $next['starts_on']);
        self::assertSame((int) $next['id'], (int) $result['next_period_id']);
        self::assertSame('OT-' . (self::YEAR + 1) . '-0001', $result['document_no'], 'Opening doklad z řady OT.');

        $opening = $this->journal->findBySource($this->supplierId, 'opening', (int) $next['id']);
        self::assertNotNull($opening, "Existuje zápis ('opening', next_period_id).");
        self::assertSame((self::YEAR + 1) . '-01-01', (string) $opening['entry_date'], 'entry_date = starts_on nového období.');

        $openLines = $this->lines((int) $opening['id']);
        $this->assertLinesBalanced($openLines);

        // Zrcadlo: rozvahové řádky closing zápisu (mimo 701/702/710) == opening s prohozenou stranou
        $closingEntry = $this->journal->findBySource($this->supplierId, 'closing', $this->periodId);
        $closingLines = $this->lines((int) $closingEntry['id']);
        $mirror = [];
        foreach ($closingLines as $l) {
            if (in_array($l['account_code'], ['701', '702', '710'], true) || in_array($l['account_type'], ['revenue', 'expense'], true)) {
                continue;
            }
            $mirror[$l['account_code']] = [$l['side'] === 'debit' ? 'credit' : 'debit', self::cents((float) $l['amount'])];
        }
        self::assertNotSame([], $mirror);
        foreach ($mirror as $code => [$expectedSide, $expectedCents]) {
            $code = (string) $code; // PHP číselné klíče pole přetypuje na int
            $found = null;
            foreach ($openLines as $l) {
                if ($l['account_code'] === $code) {
                    $found = $l;
                }
            }
            self::assertNotNull($found, "Opening obsahuje účet {$code}.");
            self::assertSame($expectedSide, $found['side'], "Opening strana účtu {$code} zrcadlí closing (702↔701).");
            self::assertSame($expectedCents, self::cents((float) $found['amount']), "Opening částka účtu {$code} == closing částka.");
        }

        // VH 6 000: MD 701 / D 431
        $byCode = $this->linesByAccountCode((int) $opening['id']);
        self::assertSame(self::cents(6000.00), self::cents($byCode['431']['credit']), 'Zisk 6 000 na 431 (D).');
        self::assertSame(
            self::cents($byCode['701']['debit']),
            self::cents($byCode['701']['credit']),
            '701 končí na nule.',
        );
    }

    // ── #37: open_next jde i po schválení (approve-before-open_next past) ─────

    /**
     * Past #37: uživatel schválí období (closed→approved) DŘÍVE, než otevřel nový rok.
     * openNext musí přesto projít — je to technický přenos počátečních zůstatků do N+1,
     * do knih schváleného období nesahá. Období zůstává 'approved' (zámek §17/7 nedotčen),
     * počáteční zůstatky se přenesou správně a opakované open_next je idempotentní.
     */
    public function testOpenNextAllowedAfterApprovalKeepsPeriodApproved(): void
    {
        $this->seedScenario();
        $this->runStepsUntilCloseReady();
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        // Schválení PŘED otevřením nového roku (přesně situace, která dřív vedla k zaseknutí).
        self::assertTrue(
            $this->periods->setStatusCas($this->periodId, $this->supplierId, 'approved', $this->rv(), $this->userId),
            'closed→approved projde (CAS).',
        );
        self::assertSame('approved', $this->period()['status']);

        // state() nabízí can_open_next i nad approved.
        $state = $this->closing->state($this->supplierId, $this->periodId);
        self::assertTrue($state['can_open_next'], 'can_open_next je true i pro approved období.');

        // open_next nad approved obdobím projde.
        $result = $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        self::assertSame('approved', $this->period()['status'], 'Období zůstává approved (zámek §17/7 nedotčen).');
        $next = $this->periods->nextPeriod($this->supplierId, self::ENDS_ON);
        self::assertNotNull($next, 'Následující období se založilo.');
        $opening = $this->journal->findBySource($this->supplierId, 'opening', (int) $next['id']);
        self::assertNotNull($opening, 'Opening zápis do N+1 vznikl i po schválení.');
        self::assertSame('OT-' . (self::YEAR + 1) . '-0001', $result['document_no']);

        // Přenesené počáteční zůstatky sedí: zisk 6 000 na 431 (D), 701 na nule.
        $byCode = $this->linesByAccountCode((int) $opening['id']);
        self::assertSame(self::cents(6000.00), self::cents($byCode['431']['credit']), 'Zisk 6 000 na 431 (D).');
        self::assertSame(
            self::cents($byCode['701']['debit']),
            self::cents($byCode['701']['credit']),
            '701 končí na nule.',
        );

        // open_next krok je done.
        $after = $this->closing->state($this->supplierId, $this->periodId);
        self::assertSame('done', $this->stepStatus($after, 'open_next'));
        self::assertFalse($after['can_open_next'], 'Po doběhu už can_open_next false (open_next done).');

        // Idempotence: opakované open_next nezduplikuje počáteční zůstatky (týž source klíč).
        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $count = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM journal_entries
              WHERE supplier_id = {$this->supplierId} AND source_type = 'opening' AND source_id = " . (int) $next['id']
        )->fetchColumn();
        self::assertSame(1, $count, 'Opakované open_next nezduplikuje opening zápis (R6).');
        self::assertSame('approved', $this->period()['status'], 'Období je stále approved i po re-runu.');
    }

    /**
     * Revert schváleného open_next zůstává zakázaný (§17/7): schválenou závěrku nelze
     * revertovat, dokud se nezruší schválení. Doplňuje #37 — povolili jsme jen DOPŘEDNÝ
     * technický krok, ne mazání zápisů nad approved obdobím.
     */
    public function testRevertOpenNextBlockedWhileApproved(): void
    {
        $this->seedScenario();
        $this->runStepsUntilCloseReady();
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->periods->setStatusCas($this->periodId, $this->supplierId, 'approved', $this->rv(), $this->userId);
        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        try {
            $this->closing->revertStep($this->supplierId, $this->periodId, 'open_next', $this->rv(), $this->meta());
            self::fail('Revert nad approved obdobím musí být zakázaný.');
        } catch (ClosingException $e) {
            self::assertSame('invalid_status_transition', $e->errorCode);
        }
    }

    // ── I3: idempotence — revert + re-run = nový doklad, týž source klíč ─────

    public function testI3RevertAndRerunKeepsSingleClosingEntryWithNewDocumentNo(): void
    {
        $this->seedScenario();
        $this->runStepsUntilCloseReady();
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        $this->closing->revertStep($this->supplierId, $this->periodId, 'open_next', $this->rv(), $this->meta());
        $this->closing->revertStep($this->supplierId, $this->periodId, 'close_books', $this->rv(), $this->meta());
        self::assertSame('closing', $this->period()['status'], 'Revert close_books vrací stav closing.');

        $rerun = $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        self::assertSame('UZ-' . self::YEAR . '-0002', $rerun['document_no'], 'Nový doklad — mezera v řadě se nerecykluje (R12/R13).');
        $count = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM journal_entries
              WHERE supplier_id = {$this->supplierId} AND source_type = 'closing' AND source_id = {$this->periodId}"
        )->fetchColumn();
        self::assertSame(1, $count, "Jediný zápis ('closing', period_id) — stejný source klíč (R6).");
    }

    // ── I4: revert kroků maže zápisy a vrací dump pro audit (R12) ────────────

    public function testI4RevertStepsDeleteEntriesAndReturnAuditDumps(): void
    {
        $this->seedScenario();
        $this->runStepsUntilCloseReady();
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $nextId = (int) $this->periods->nextPeriod($this->supplierId, self::ENDS_ON)['id'];

        $revert1 = $this->closing->revertStep($this->supplierId, $this->periodId, 'open_next', $this->rv(), $this->meta());
        self::assertArrayHasKey('opening', $revert1['dumps'], 'Dump smazaného opening zápisu pro audit.');
        self::assertNotEmpty($revert1['dumps']['opening']['lines'], 'Dump obsahuje kompletní řádky (R12).');
        self::assertNull($this->journal->findBySource($this->supplierId, 'opening', $nextId), 'Opening zápis je smazán.');

        $revert2 = $this->closing->revertStep($this->supplierId, $this->periodId, 'close_books', $this->rv(), $this->meta());
        self::assertArrayHasKey('closing', $revert2['dumps']);
        self::assertSame('closing', $revert2['status'], 'close_books revert vrací status closing.');
        self::assertNull($this->journal->findBySource($this->supplierId, 'closing', $this->periodId), 'Closing zápis je smazán.');
        self::assertSame('closing', $this->period()['status']);
    }

    // ── I5: gating ───────────────────────────────────────────────────────────

    public function testI5CloseBooksWithoutStepsRefused(): void
    {
        $this->seedScenario();
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        try {
            $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
            self::fail('Očekávána ClosingException closing_steps_incomplete.');
        } catch (ClosingException $e) {
            self::assertSame('closing_steps_incomplete', $e->errorCode);
            self::assertSame(422, $e->httpStatus);
        }
    }

    public function testCloseBooksRequiresExplicitProvisionAndIncomeTaxDecision(): void
    {
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->runPrecheck($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->confirmStep($this->supplierId, $this->periodId, 'depreciation', 'skipped', null, $this->rv(), $this->meta());
        $this->closing->runFxRevaluation($this->supplierId, $this->periodId, [], $this->rv(), $this->meta());
        $this->closing->confirmStep($this->supplierId, $this->periodId, 'estimates', 'skipped', null, $this->rv(), $this->meta());
        $this->closing->confirmStep($this->supplierId, $this->periodId, 'deferrals', 'skipped', null, $this->rv(), $this->meta());

        self::assertFalse($this->closing->state($this->supplierId, $this->periodId)['can_close']);
        try {
            $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
            self::fail('Nevyřešené kroky OP a daně musí uzavření knih blokovat.');
        } catch (ClosingException $e) {
            self::assertSame('closing_steps_incomplete', $e->errorCode);
        }

        $this->closing->confirmStep($this->supplierId, $this->periodId, 'provisions', 'skipped', null, $this->rv(), $this->meta());
        $this->closing->confirmStep($this->supplierId, $this->periodId, 'income_tax', 'skipped', null, $this->rv(), $this->meta());
        self::assertTrue($this->closing->state($this->supplierId, $this->periodId)['can_close']);
    }

    /**
     * Nerozdělený zůstatek 431 už PŘED zahájením blokuje start() (uživatel by se jinak
     * zasekl uprostřed uzávěrky). Pojistka u uzavření knih ale musí zůstat funkční pro
     * případ, kdy 431 vznikne až v průběhu — proto ho sem vpravíme až po zahájení.
     */
    public function testUndistributed431BlocksCloseBooks(): void
    {
        $this->runStepsUntilCloseReady();

        $pdo = $this->db->pdo();
        $toStatus = static fn (string $s): string =>
            "UPDATE accounting_periods SET status = '{$s}' WHERE id = ? AND supplier_id = ?";
        $pdo->prepare($toStatus('open'))->execute([$this->periodId, $this->supplierId]);
        $this->manual([
            self::l('221', 'debit', 10000.00),
            self::l('431', 'credit', 10000.00),
        ], self::YEAR . '-01-01');
        $pdo->prepare($toStatus('closing'))->execute([$this->periodId, $this->supplierId]);
        $this->closing->runPrecheck($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        try {
            $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
            self::fail('Nenulový zůstatek 431 musí uzavření knih blokovat.');
        } catch (ClosingException $e) {
            self::assertSame('precheck_failed', $e->errorCode);
            self::assertStringContainsString('vh_431_undistributed', $e->getMessage());
        }
    }

    public function testI5CloseBooksWithDraftInPeriodRefused(): void
    {
        $this->seedScenario();
        // Koncept (posted_at NULL) v období → precheck error drafts_in_period
        $this->manual([
            self::l('518', 'debit', 100.00),
            self::l('321', 'credit', 100.00),
        ], self::YEAR . '-09-01', ['posted' => false]);
        $this->runStepsUntilCloseReady();

        try {
            $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
            self::fail('Očekávána ClosingException precheck_failed.');
        } catch (ClosingException $e) {
            self::assertSame('precheck_failed', $e->errorCode);
            self::assertStringContainsString('drafts_in_period', $e->getMessage());
        }
    }

    public function testI5OpenNextBeforeCloseRefused(): void
    {
        $this->seedScenario();
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        try {
            $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());
            self::fail('Očekávána ClosingException invalid_status_transition.');
        } catch (ClosingException $e) {
            self::assertSame('invalid_status_transition', $e->errorCode);
        }
    }

    public function testI5ChronologyGuardPreviousPeriodOpen(): void
    {
        // R5: uzavření roku N+1 před N → previous_period_open
        $nextYear = self::YEAR + 1;
        $nextId = $this->periods->create($this->supplierId, $nextYear, $nextYear . '-01-01', $nextYear . '-12-31');

        try {
            $this->closing->start($this->supplierId, $nextId, 1, $this->meta());
            self::fail('Očekávána ClosingException previous_period_open.');
        } catch (ClosingException $e) {
            self::assertSame('previous_period_open', $e->errorCode);
        }
    }

    // ── I6: tenant izolace ───────────────────────────────────────────────────

    public function testI6ForeignSupplierGets404(): void
    {
        $pdo = $this->db->pdo();
        $czId = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        $currencyId = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $vatRateId = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $otherSupplier = $this->createSupplier('F4 cizí tenant s.r.o.', 'f4-tenant-b@example.com', $czId, $currencyId, $vatRateId);

        try {
            $this->closing->state($otherSupplier, $this->periodId);
            self::fail('Cizí tenant nesmí číst stav uzávěrky.');
        } catch (ClosingException $e) {
            self::assertSame('not_found', $e->errorCode);
            self::assertSame(404, $e->httpStatus);
        }

        try {
            $this->closing->runPrecheck($otherSupplier, $this->periodId, 1, $this->meta());
            self::fail('Cizí tenant nesmí spouštět kroky uzávěrky.');
        } catch (ClosingException $e) {
            self::assertSame('not_found', $e->errorCode);
        }
    }

    // ── D5 (audit 2026-07): perzistence kategorie ÚJ při uzávěrce ─────────────

    /**
     * Regrese D5: closeBooks zmrazí kritéria kategorizace do entity_category_history
     * a EntityCategoryService::rawsForClosedPeriods pak čte raw kategorii ODTUD
     * (výkon + §1e kontinuita + zmražení zaměstnanci), ne přepočtem.
     */
    public function testClosingFreezesEntityCategoryHistoryAndReadsFromIt(): void
    {
        $hasSeed = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM statement_versions WHERE version_code = 'vyhl500-2002/2024'"
        )->fetchColumn();
        if ($hasSeed < 2) {
            self::markTestSkipped('Seed výkazů 1012 není aplikovaný — freeze() by neměl mapu rozvahy.');
        }

        $this->seedScenario();
        $this->runStepsUntilCloseReady();
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        // 1) po uzávěrce existuje zmražený řádek
        $row = $this->db->pdo()->query(
            "SELECT raw_category, avg_employees, assets_net, net_turnover
               FROM entity_category_history
              WHERE supplier_id = {$this->supplierId} AND period_id = {$this->periodId}"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertNotFalse($row, 'Po uzávěrce existuje řádek v entity_category_history.');
        self::assertSame('micro', (string) $row['raw_category'], 'Malá firma bez zaměstnanců → raw micro.');
        self::assertSame(0, (int) $row['avg_employees'], 'Zaměstnanci zmraženi (0).');
        self::assertSame(self::cents(10000.00), self::cents((float) $row['net_turnover']), 'Čistý obrat 602 zmražen.');

        // 2) následující období čte raw ZMRAŽENÝ z historie (ne přepočet): přepíšeme
        //    frozen raw na 'large' a ověříme, že evaluate() N+1 vidí právě 'large'.
        $this->db->pdo()->exec(
            "UPDATE entity_category_history SET raw_category = 'large'
              WHERE supplier_id = {$this->supplierId} AND period_id = {$this->periodId}"
        );
        $nextYear = self::YEAR + 1;
        $nextId = $this->periods->create($this->supplierId, $nextYear, $nextYear . '-01-01', $nextYear . '-12-31');

        $result = $this->categories->evaluate($this->supplierId, $nextId);
        self::assertSame('large', $result['raw_previous'], 'raw_previous se čte z entity_category_history, ne přepočtem.');
    }

    /**
     * EP-10b: nezaúčtovaný aktivní doklad standardně blokuje uzavření knih; oprávněný
     * override s doloženým důvodem uzavření povolí a zaznamená neměnnou auditní událost
     * i výjimku do payloadu kroku close_books (→ závěrkový balíček).
     */
    public function testUnpostedActiveDocumentBlocksCloseAndOverrideRecordsException(): void
    {
        $this->seedScenario();
        $this->runStepsUntilCloseReady();
        $this->seedUnpostedPurchase();

        // Bez override uzavření knih blokuje.
        try {
            $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
            self::fail('Uzavření knih mělo být zablokováno nezaúčtovaným dokladem.');
        } catch (ClosingException $e) {
            self::assertSame('unposted_documents_block', $e->errorCode);
        }
        self::assertSame('closing', $this->period()['status'], 'Období zůstává v closing.');

        // Override bez doloženého důvodu je odmítnut.
        try {
            $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta(), true, '   ');
            self::fail('Override bez důvodu měl selhat.');
        } catch (ClosingException $e) {
            self::assertSame('validation_failed', $e->errorCode);
        }

        // Oprávněný override s důvodem uzavře knihy a zaznamená výjimku + audit.
        $reason = 'Doklad dodán po uzávěrce, doúčtuje se v N+1';
        $result = $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta(), true, $reason);
        self::assertSame('closed', $result['status']);
        self::assertArrayHasKey('unposted_override', $result);
        self::assertSame($reason, $result['unposted_override']['reason']);
        self::assertSame(1, $result['unposted_override']['count']);

        $audit = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM activity_log
              WHERE supplier_id = {$this->supplierId} AND action = 'accounting.books_closed_unposted_override'"
        )->fetchColumn();
        self::assertSame(1, $audit, 'Override vytvoří neměnnou auditní událost.');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** EP-10b: nezaúčtovaný aktivní přijatý doklad (client + purchase_invoice) v období. */
    private function seedUnpostedPurchase(): void
    {
        $pdo = $this->db->pdo();
        $czId = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        $currencyId = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn();

        $cli = $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
             VALUES (?, "Dodavatel test s.r.o.", "Testovací 1", "Praha", "11000", ?, "dodavatel@example.com", ?)'
        );
        $cli->execute([$this->supplierId, $czId, $currencyId]);
        $vendorId = (int) $pdo->lastInsertId();

        $pi = $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, issue_date, due_date, received_at,
                 currency_id, vendor_snapshot, created_by, status, document_kind, total_without_vat, total_with_vat)
             VALUES (?, ?, "UNPOSTED-1", ?, ?, ?, ?, "{}", ?, "received", "invoice", 1000.00, 1210.00)'
        );
        $mid = self::YEAR . '-06-15';
        $pi->execute([$this->supplierId, $vendorId, $mid, $mid, $mid, $currencyId, $this->userId]);
    }

    /**
     * Syntetická data I1: výnos 602 10 000, náklad 518 4 000, zůstatky 311/321/221/343.
     * BS: 311 = 7 100, 343 = −1 260, 221 = 5 000, 321 = −4 840; VH = 6 000.
     */
    private function seedScenario(): void
    {
        $this->manual([
            self::l('311', 'debit', 12100.00),
            self::l('602', 'credit', 10000.00),
            self::l('343', 'credit', 2100.00),
        ], self::YEAR . '-03-01');
        $this->manual([
            self::l('518', 'debit', 4000.00),
            self::l('343', 'debit', 840.00),
            self::l('321', 'credit', 4840.00),
        ], self::YEAR . '-03-05');
        $this->manual([
            self::l('221', 'debit', 5000.00),
            self::l('311', 'credit', 5000.00),
        ], self::YEAR . '-06-01');
    }

    /** start → precheck → všechny povinné kroky done/skipped. */
    private function runStepsUntilCloseReady(?string $allowedFailingError = null): void
    {
        $sid = $this->supplierId;
        $pid = $this->periodId;
        $this->closing->start($sid, $pid, $this->rv(), $this->meta());
        // EP-6: inventarizaci dokonči PŘED prechekem — jinak error kontrola
        // inventory_unresolved v prechecku neprojde (resolved zůstává resolved i po dalších krocích).
        $this->completeInventory($sid, $pid, $this->userId);

        $precheck = $this->closing->runPrecheck($sid, $pid, $this->rv(), $this->meta());
        foreach ($precheck['checks'] as $check) {
            if ($check['severity'] === 'error'
                && $check['key'] !== 'drafts_in_period'
                && $check['key'] !== $allowedFailingError) {
                self::assertTrue((bool) $check['ok'], 'Precheck error kontrola ' . $check['key'] . ' musí projít.');
            }
        }
        $this->closing->confirmStep($sid, $pid, 'depreciation', 'skipped', null, $this->rv(), $this->meta());
        $this->closing->runFxRevaluation($sid, $pid, [], $this->rv(), $this->meta());
        $this->closing->confirmStep($sid, $pid, 'estimates', 'skipped', null, $this->rv(), $this->meta());
        $this->closing->confirmStep($sid, $pid, 'deferrals', 'skipped', null, $this->rv(), $this->meta());
        $this->closing->confirmStep($sid, $pid, 'provisions', 'skipped', null, $this->rv(), $this->meta());
        $this->closing->confirmStep($sid, $pid, 'income_tax', 'skipped', null, $this->rv(), $this->meta());
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

    private function createSupplier(string $name, string $email, int $czId, int $currencyId, int $vatRateId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, ?, ?, ?)'
        );
        $stmt->execute([$name, $czId, $email, $currencyId, $vatRateId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function rv(): int
    {
        return (int) $this->periods->findById($this->supplierId, $this->periodId)['row_version'];
    }

    /** @return array<string,mixed> */
    private function period(): array
    {
        return $this->periods->findById($this->supplierId, $this->periodId) ?? [];
    }

    /**
     * Stav kroku ze state() payloadu (steps je list, ne mapa).
     *
     * @param array<string,mixed> $state
     */
    private function stepStatus(array $state, string $key): string
    {
        foreach (($state['steps'] ?? []) as $s) {
            if (($s['step_key'] ?? null) === $key) {
                return (string) $s['status'];
            }
        }
        return 'pending';
    }

    /** @return array{user_id:int, posted_by:int} */
    private function meta(): array
    {
        return ['user_id' => $this->userId, 'posted_by' => $this->userId];
    }

    /**
     * @param list<array{account_code:string, side:string, amount:float}> $lines
     * @param array<string,mixed> $meta
     */
    private function manual(array $lines, string $date, array $meta = []): int
    {
        return $this->posting->postDocument(
            $this->supplierId,
            'manual',
            null,
            $lines,
            array_merge(['entry_date' => $date, 'posted_by' => $this->userId, 'user_id' => $this->userId], $meta),
        );
    }

    /** @return array{account_code:string, side:string, amount:float} */
    private static function l(string $code, string $side, float $amount): array
    {
        return ['account_code' => $code, 'side' => $side, 'amount' => $amount];
    }

    /**
     * @return list<array{account_code:string, account_type:string, side:string, amount:float}>
     */
    private function lines(int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.account_code, a.account_type, l.side, l.amount
               FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.entry_id = ? AND l.supplier_id = ?
              ORDER BY l.line_no, l.id'
        );
        $stmt->execute([$entryId, $this->supplierId]);
        return array_map(static fn (array $r): array => [
            'account_code' => (string) $r['account_code'],
            'account_type' => (string) $r['account_type'],
            'side'         => (string) $r['side'],
            'amount'       => (float) $r['amount'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array<string,array{debit:float,credit:float}>
     */
    private function linesByAccountCode(int $entryId): array
    {
        $out = [];
        foreach ($this->lines($entryId) as $l) {
            $out[$l['account_code']] ??= ['debit' => 0.0, 'credit' => 0.0];
            $out[$l['account_code']][$l['side']] += $l['amount'];
        }
        return $out;
    }

    /** @param list<array{side:string, amount:float}> $lines */
    private function assertLinesBalanced(array $lines): void
    {
        $debit = 0;
        $credit = 0;
        foreach ($lines as $l) {
            $l['side'] === 'debit' ? $debit += self::cents($l['amount']) : $credit += self::cents($l['amount']);
        }
        self::assertSame($debit, $credit, 'Σ MD == Σ D zápisu (v haléřích).');
    }

    /**
     * Účty s nenulovým kumulativním zůstatkem k datu (posted, vč. closing zápisů).
     *
     * @return list<string>
     */
    private function nonZeroCumulativeBalances(string $asOf): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT a.account_code,
                    SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END) AS bal
               FROM journal_entry_lines l
               JOIN journal_entries e   ON e.id = l.entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL AND e.entry_date <= ?
              GROUP BY a.account_code
             HAVING ABS(bal) >= 0.005"
        );
        $stmt->execute([$this->supplierId, $asOf]);
        return array_map(static fn (array $r): string => $r['account_code'] . '=' . $r['bal'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private static function cents(float $amount): int
    {
        return (int) round($amount * 100.0);
    }
}
