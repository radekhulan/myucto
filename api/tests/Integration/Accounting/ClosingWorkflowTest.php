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
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\Dimension\DimensionSplitAllocation;
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
    private DimensionService $dimensions;
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
            $container = Bootstrap::buildContainer();
            $this->db      = $container->get(Connection::class);
            $this->posting = $container->get(PostingService::class);
            $this->closing = $container->get(ClosingService::class);
            $this->dimensions = $container->get(DimensionService::class);
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

    public function testClosingAndOpeningPreserveCombinedDimensions(): void
    {
        [$firstType, $a, $b, $secondType, $c, $d] = $this->createClosingDimensions();
        $this->manual([
            self::l('378', 'debit', 100.00) + ['dimensions' => [$firstType => $a, $secondType => $c]],
            self::l('378', 'debit', 200.00) + ['dimensions' => [$firstType => $b, $secondType => $d]],
            self::l('379', 'credit', 300.00),
        ], self::YEAR . '-04-01');

        $this->assertClosingDimensionContinuity([
            $firstType => [$a => 10000, $b => 20000],
            $secondType => [$c => 10000, $d => 20000],
        ]);
        $stmt = $this->db->pdo()->prepare(
            "SELECT d.dimension_value_id AS first_value, p.dimension_value_id AS second_value,
                    SUM(IF(l.side = 'debit', l.signed_amount, -l.signed_amount)) AS balance
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
               JOIN chart_of_accounts a ON a.id = l.account_id
               JOIN journal_entry_line_dimensions d ON d.line_id = l.id AND d.dimension_type_id = ?
               JOIN journal_entry_line_dimensions p ON p.line_id = l.id AND p.dimension_type_id = ?
              WHERE l.supplier_id = ? AND e.source_type = 'opening' AND a.account_code = '378'
              GROUP BY d.dimension_value_id, p.dimension_value_id ORDER BY first_value, second_value"
        );
        $stmt->execute([$firstType, $secondType, $this->supplierId]);
        $combined = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $combined[$row['first_value'] . '|' . $row['second_value']] = self::cents((float) $row['balance']);
        }
        self::assertSame([$a . '|' . $c => 10000, $b . '|' . $d => 20000], $combined);
    }

    public function testClosingAndOpeningPreserveDimensionBalancesWhenAccountIsZero(): void
    {
        [$typeId, $a, $b] = $this->createClosingDimensions();
        $this->manual([
            self::l('378', 'debit', 100.00) + ['dimensions' => [$typeId => $a]],
            self::l('378', 'credit', 100.00) + ['dimensions' => [$typeId => $b]],
        ], self::YEAR . '-04-01');

        $this->assertClosingDimensionContinuity([$typeId => [$a => 10000, $b => -10000]]);
    }

    public function testClosingAndOpeningPreserveSplitRoundingForEachType(): void
    {
        [$firstType, $a, $b, $secondType, $c, $d] = $this->createClosingDimensions();
        $splits = ['dimension_splits' => [
            $firstType => [$a => 0.5, $b => 0.5],
            $secondType => [$c => 0.25, $d => 0.75],
        ]];
        $this->manual([
            self::l('378', 'debit', 0.01) + $splits,
            self::l('378', 'debit', 0.01) + $splits,
            self::l('379', 'credit', 0.02),
        ], self::YEAR . '-04-01');

        $this->assertClosingDimensionContinuity([
            $firstType => [$b => 2],
            $secondType => [$d => 2],
        ]);
    }

    public function testClosingAndOpeningPreserveRedStornoSplitBalances(): void
    {
        [$typeId, $a, $b] = $this->createClosingDimensions();
        $split = ['dimension_splits' => [$typeId => [$a => 0.5, $b => 0.5]]];
        $this->manual([
            self::l('378', 'debit', 100.00) + $split,
            self::l('379', 'credit', 100.00),
        ], self::YEAR . '-04-01');
        $this->manual([
            self::l('378', 'debit', 20.00) + $split + ['is_red_storno' => true],
            self::l('379', 'credit', 20.00) + ['is_red_storno' => true],
        ], self::YEAR . '-05-01');

        $this->assertClosingDimensionContinuity([$typeId => [$a => 4000, $b => 4000]]);
    }

    /** Převzatá rozvaha, která dimenze nese, se porovnává i po dimenzích. */
    public function testOpeningTakeoverRefusesMatchingAccountsWithoutDimensions(): void
    {
        [$typeId, $a, , $projectType, $c] = $this->createClosingDimensions();
        $this->manual([
            self::l('378', 'debit', 100.00) + ['dimensions' => [$typeId => $a]],
            self::l('379', 'credit', 100.00),
        ], self::YEAR . '-04-01');
        $this->runStepsUntilCloseReady();
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $nextId = $this->periods->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
        $this->posting->postDocument($this->supplierId, 'opening', null, [
            self::l('378', 'debit', 100.00),
            self::l('379', 'credit', 100.00) + ['dimensions' => [$projectType => $c]],
        ], ['entry_date' => (self::YEAR + 1) . '-01-01', 'period_id' => $nextId] + $this->meta());

        $takeover = $this->closing->state($this->supplierId, $this->periodId)['opening_takeover'];
        self::assertSame('mismatch', $takeover['status']);
        self::assertStringContainsString('CLOSE-A', implode(' ', array_merge(...array_column($takeover['diff'], 'dimension_labels'))));
        try {
            $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());
            self::fail('Převzaté stavy nesmějí tiše ztratit dimenze.');
        } catch (ClosingException $e) {
            self::assertSame('opening_takeover_mismatch', $e->errorCode);
        }
    }

    /**
     * Rozvaha převzatá z Money S3 a jiných systémů dimenze nenese, zůstatky roku v MyÚčtu ano
     * (střediska, vozidla). Sedí-li každý účet, převezme se po účtech, jinak by rok nešlo
     * otevřít nikdy (rozdíl u každé hodnoty dimenze).
     */
    public function testOpeningTakeoverWithoutAnyDimensionsComparesByAccount(): void
    {
        [$typeId, $a, $b] = $this->createClosingDimensions();
        $this->manual([
            self::l('378', 'debit', 100.00) + ['dimensions' => [$typeId => $a]],
            self::l('378', 'debit', 50.00) + ['dimensions' => [$typeId => $b]],
            self::l('379', 'credit', 150.00),
        ], self::YEAR . '-04-01');
        $this->runStepsUntilCloseReady();
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $nextId = $this->periods->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
        $opening = $this->posting->postDocument($this->supplierId, 'opening', null, [
            self::l('378', 'debit', 150.00),
            self::l('379', 'credit', 150.00),
        ], ['entry_date' => (self::YEAR + 1) . '-01-01', 'period_id' => $nextId] + $this->meta());

        $takeover = $this->closing->state($this->supplierId, $this->periodId)['opening_takeover'];
        self::assertSame('match', $takeover['status']);
        self::assertSame('account', $takeover['compare_level']);
        self::assertSame('opening_without_dimensions', $takeover['compare_reason']);

        $result = $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        self::assertSame('taken_over', $result['opening_source']);
        self::assertSame([$opening], $result['taken_over_entry_ids']);
        self::assertSame('opening_without_dimensions', $result['taken_over_compare_reason']);
    }

    /** Rozdíl na účtu se po účtech pozná i u rozvahy bez dimenzí. */
    public function testOpeningTakeoverWithoutDimensionsStillRefusesAccountDifference(): void
    {
        [$typeId, $a] = $this->createClosingDimensions();
        $this->manual([
            self::l('378', 'debit', 100.00) + ['dimensions' => [$typeId => $a]],
            self::l('379', 'credit', 100.00),
        ], self::YEAR . '-04-01');
        $this->runStepsUntilCloseReady();
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $nextId = $this->periods->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
        $this->posting->postDocument($this->supplierId, 'opening', null, [
            self::l('378', 'debit', 90.00),
            self::l('379', 'credit', 90.00),
        ], ['entry_date' => (self::YEAR + 1) . '-01-01', 'period_id' => $nextId] + $this->meta());

        try {
            $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());
            self::fail('Rozdíl na účtu musí otevření zastavit.');
        } catch (ClosingException $e) {
            self::assertSame('opening_takeover_mismatch', $e->errorCode);
        }
    }

    public function testOpeningTakeoverAcceptsMatchingDimensions(): void
    {
        [$typeId, $a] = $this->createClosingDimensions();
        $lines = [
            self::l('378', 'debit', 100.00) + ['dimensions' => [$typeId => $a]],
            self::l('379', 'credit', 100.00),
        ];
        $this->manual($lines, self::YEAR . '-04-01');
        $this->runStepsUntilCloseReady();
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $nextId = $this->periods->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
        $this->posting->postDocument($this->supplierId, 'opening', null, $lines,
            ['entry_date' => (self::YEAR + 1) . '-01-01', 'period_id' => $nextId] + $this->meta());

        $result = $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        self::assertSame('taken_over', $result['opening_source']);
        self::assertSame([$a => 10000], $this->dimensionBalances($typeId, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31'));
    }

    public function testRepeatedClosingDoesNotMultiplySplitOpeningLines(): void
    {
        [$typeId, $a, $b] = $this->createClosingDimensions();
        $splits = ['dimension_splits' => [$typeId => [$a => 0.5, $b => 0.5]]];
        $this->manual([
            self::l('378', 'debit', 0.01) + $splits,
            self::l('378', 'debit', 0.01) + $splits,
            self::l('379', 'credit', 0.02),
        ], self::YEAR . '-04-01');
        $this->assertClosingDimensionContinuity([$typeId => [$b => 2]]);
        $this->periodId = (int) $this->periods->findByYear($this->supplierId, self::YEAR + 1)['id'];
        $opening = $this->journal->findBySource($this->supplierId, 'opening', $this->periodId);
        $lineCount = count($this->journal->linesForEntry((int) $opening['id'], $this->supplierId));

        $this->runStepsUntilCloseReady();
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $result = $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        self::assertSame($lineCount, count($this->journal->linesForEntry((int) $result['entry_id'], $this->supplierId)));
        self::assertSame([$b => 2], $this->dimensionBalances($typeId, (self::YEAR + 2) . '-01-01', (self::YEAR + 2) . '-12-31'));
    }

    public function testFollowingClosingUsesOpeningAnchorAfterHistoricalDimensionEdit(): void
    {
        [$typeId, $a, $b] = $this->createClosingDimensions();
        $entryId = $this->manual([
            self::l('378', 'debit', 100.00) + ['dimensions' => [$typeId => $a]],
            self::l('379', 'credit', 100.00),
        ], self::YEAR . '-04-01');
        $this->assertClosingDimensionContinuity([$typeId => [$a => 10000]]);
        $sourceLines = $this->journal->linesForEntry($entryId, $this->supplierId);
        (new DimensionAssignmentRepository($this->db))->replaceLineDimensions(
            $this->supplierId, (int) $sourceLines[0]['id'], [$typeId => $b],
        );
        $this->periodId = (int) $this->periods->findByYear($this->supplierId, self::YEAR + 1)['id'];
        self::assertSame([$a => 10000], $this->dimensionBalances($typeId, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31'));

        $this->runStepsUntilCloseReady();
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        self::assertSame([$a => 10000], $this->dimensionBalances($typeId, (self::YEAR + 2) . '-01-01', (self::YEAR + 2) . '-12-31'));
    }

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
        self::assertTrue($result['notes_frozen'], 'Uzavření knih zmrazí přílohu k závěrce.');
        self::assertArrayNotHasKey('notes_warning', $result);
        self::assertTrue(
            $this->categories->isFrozen($this->supplierId, $this->periodId),
            'Po uzavření je historická kategorie uložená (předpoklad schválení).',
        );
    }

    /**
     * Selhání zmražení přílohy k závěrce knihy nezastaví, ale nesmí se spolknout: krok
     * close_books vrací varování stejně jako u kategorie ÚJ (schválení ho pak zopakuje).
     */
    public function testCloseBooksReportsFailedStatementNotesFreeze(): void
    {
        $this->seedScenario();
        $this->runStepsUntilCloseReady();
        $this->db->pdo()->exec("UPDATE statement_versions SET valid_from = '2999-01-01' WHERE statement_type = 'balance_sheet' AND framework = 'cz_500'");

        $result = $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        self::assertSame('closed', $this->period()['status'], 'Knihy jsou uzavřené i bez zmražené přílohy.');
        self::assertFalse($result['notes_frozen']);
        self::assertSame('statement_notes_freeze_failed', $result['notes_warning'] ?? null);
        self::assertStringContainsString('přílohy', (string) ($result['notes_warning_detail'] ?? ''));
        $step = $this->db->pdo()->prepare("SELECT payload FROM accounting_closing_steps WHERE supplier_id = ? AND period_id = ? AND step_key = 'close_books'");
        $step->execute([$this->supplierId, $this->periodId]);
        $payload = json_decode((string) $step->fetchColumn(), true);
        self::assertSame('statement_notes_freeze_failed', $payload['notes_warning'] ?? null, 'Varování zůstává v kroku pro průvodce.');
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

    /**
     * Přiznání DPH za leden bývá podané dřív, než se uzavře minulý rok, takže zámek k datu
     * leží už na 1. dni nového roku. Otevření nového roku i se zásobami musí přesto projít.
     */
    public function testOpenNextPostsStockReleaseIntoDateLockedNextYear(): void
    {
        $this->seedScenario();
        $this->manual([self::l('112', 'debit', 3000.00), self::l('321', 'credit', 3000.00)], self::YEAR . '-04-01');
        $this->runStepsUntilCloseReady();
        $this->posting->postDocument($this->supplierId, 'closing', \MyInvoice\Service\Accounting\Closing\ClosingSourceId::stockClosing($this->periodId), [
            self::l('112', 'debit', 500.00),
            self::l('501', 'credit', 500.00),
        ], ['entry_date' => self::ENDS_ON, 'allow_closing_period' => true] + $this->meta());
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->db->pdo()->prepare('INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, ?) ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)')
            ->execute([$this->supplierId, (self::YEAR + 1) . '-01-31']);

        $result = $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        self::assertNotNull($result['entry_id']);
        self::assertNotNull($result['stock_release_entry_id'], 'Počáteční stav zásob se musí vrátit do spotřeby i v zamčeném dni.');
        $release = $this->linesByAccountCode((int) $result['stock_release_entry_id']);
        self::assertEqualsWithDelta(500.0, $release['501']['debit'], 0.001);
        self::assertEqualsWithDelta(500.0, $release['112']['credit'], 0.001);
    }

    /**
     * Nastavení čistého obratu vybírá řádek jen v druhovém členění výsledovky, takže kategorii
     * účetní jednotky nejde určit. Precheck musí doběhnout a důvod ohlásit jako varování.
     */
    public function testPrecheckReportsUndeterminableEntityCategory(): void
    {
        $this->seedScenario();
        $this->manual([self::l('221', 'debit', 100.00), self::l('648', 'credit', 100.00)], self::YEAR . '-03-01');
        (new \MyInvoice\Repository\AccountingSupplierSettingsRepository($this->db))->setNetTurnoverExtraRows($this->supplierId, [
            'income_statement' => ['III.3.'], 'income_statement_purpose' => [],
        ]);
        try {
            $this->categories->evaluate($this->supplierId, $this->periodId);
            self::fail('Předpoklad testu: kategorie nejde určit.');
        } catch (\MyInvoice\Service\Accounting\Reports\ReportException $e) {
            self::assertSame('net_turnover_membership_conflict', $e->errorCode);
        }

        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $precheck = $this->closing->runPrecheck($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        $check = $this->findCheck($precheck['checks'], 'entity_category_unavailable');
        self::assertNotNull($check);
        self::assertSame('warning', $check['severity']);
        self::assertFalse($check['ok']);
        self::assertStringContainsString('Čistý obrat', (string) $check['value']);
        self::assertNull($this->findCheck($precheck['checks'], 'section18_statements_required'));
        self::assertNotNull($this->findCheck($precheck['checks'], 'unposted_invoices'), 'Ostatní kontroly doběhnou.');
    }

    public function testPrecheckDoesNotRequireSupplementaryStatementsForAuditedMicroEntity(): void
    {
        (new \MyInvoice\Repository\AccountingSupplierSettingsRepository($this->db))
            ->upsert($this->supplierId, 0, null, statutoryAudit: true);
        $category = $this->categories->evaluate($this->supplierId, $this->periodId);
        self::assertSame('micro', $category['category']);
        self::assertSame('full', $category['scope']);

        $period = $this->periods->findById($this->supplierId, $this->periodId);
        $checks = $this->closing->buildChecks($this->supplierId, $period, onlyKeys: ['section18_statements_required']);

        self::assertSame([], $checks);
    }

    /**
     * Plátcovství z dolního limitu vzniká až 1. ledna dalšího roku. Uzávěrka roku, v němž
     * firma limit překročila, ho hodnotí k rozvahovému dni (varování), ne k dnešku, kdy už
     * plátcovství běží. Vznik plátcovství uvnitř uzavíraného roku zůstává chybou.
     */
    public function testVatRegistrationDueIsGradedAgainstPeriodEnd(): void
    {
        $year = (int) date('Y') - 1;
        $periodId = $this->periods->create($this->supplierId, $year, $year . '-01-01', $year . '-12-31');
        $period = $this->periods->findById($this->supplierId, $periodId);
        $this->db->pdo()->prepare('UPDATE supplier SET is_vat_payer = 0 WHERE id = ?')->execute([$this->supplierId]);
        $this->issuedInvoice(2100000.00, $year . '-06-01');

        $check = $this->findCheck($this->closing->buildChecks($this->supplierId, $period, onlyKeys: ['vat_registration_due']), 'vat_registration_due');
        self::assertSame(($year + 1) . '-01-01', $check['value']['becomes_payer_on']);
        self::assertSame('warning', $check['severity'], 'Povinnost začíná až po konci období.');

        $this->issuedInvoice(600000.00, $year . '-07-01');
        $check = $this->findCheck($this->closing->buildChecks($this->supplierId, $period, onlyKeys: ['vat_registration_due']), 'vat_registration_due');
        self::assertSame($year . '-07-02', $check['value']['becomes_payer_on']);
        self::assertSame('error', $check['severity'], 'Plátcovství vzniklo uvnitř období.');
    }

    /** Rozdělaná uzávěrka dalšího roku: hláška řekne, že ji je potřeba nejdřív přerušit. */
    public function testOpenNextIntoClosingNextYearTellsToAbortIt(): void
    {
        $this->seedScenario();
        $this->runStepsUntilCloseReady();
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $nextId = $this->periods->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")->execute([$nextId]);

        try {
            $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());
            self::fail('Do rozdělané uzávěrky dalšího roku se otevírat nesmí.');
        } catch (ClosingException $e) {
            self::assertSame('next_period_not_open', $e->errorCode);
            self::assertStringContainsString('Přerušit uzávěrku', $e->getMessage());
            self::assertStringNotContainsString('vezměte zpět', $e->getMessage());
        }

        $this->posting->postDocument($this->supplierId, 'income_tax', $nextId, [
            self::l('591', 'debit', 10.00), self::l('341', 'credit', 10.00),
        ], ['entry_date' => (self::YEAR + 1) . '-12-31', 'allow_closing_period' => true] + $this->meta());
        try {
            $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());
            self::fail('Do rozdělané uzávěrky dalšího roku se otevírat nesmí.');
        } catch (ClosingException $e) {
            self::assertStringContainsString('vezměte zpět její kroky', $e->getMessage());
        }
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
     * Vrácení kroku, který mění výsledek hospodaření, po zaúčtování daně z příjmů označí
     * daň k přepočtu. Knihy nejde uzavřít, dokud se daň znovu nepotvrdí nebo nespustí.
     */
    public function testRevertingProfitStepAfterIncomeTaxMarksTaxStale(): void
    {
        $this->seedScenario();
        $this->runStepsUntilCloseReady();
        $sid = $this->supplierId;
        $pid = $this->periodId;
        $this->closing->runIncomeTax($sid, $pid, 1260.0, $this->rv(), $this->meta());

        $reverted = $this->closing->revertStep($sid, $pid, 'estimates', $this->rv(), $this->meta());
        self::assertTrue($reverted['income_tax_stale'] ?? false);
        $this->closing->confirmStep($sid, $pid, 'estimates', 'skipped', null, $this->rv(), $this->meta());

        $state = $this->closing->state($sid, $pid);
        self::assertSame(['estimates'], $state['income_tax_stale']['caused_by'] ?? null);
        self::assertSame('done', $this->stepStatus($state, 'income_tax'), 'Krok daně zůstává hotový, jen se označí k přepočtu.');
        self::assertFalse($state['can_close']);
        try {
            $this->closing->closeBooks($sid, $pid, $this->rv(), $this->meta());
            self::fail('Daň označená k přepočtu musí uzavření knih blokovat.');
        } catch (ClosingException $e) {
            self::assertSame('income_tax_stale', $e->errorCode);
        }

        // Vrácení kroku, který výsledek nemění, daň neoznačí.
        $this->closing->revertStep($sid, $pid, 'precheck', $this->rv(), $this->meta());
        self::assertSame(['estimates'], $this->closing->state($sid, $pid)['income_tax_stale']['caused_by'] ?? null);
        $this->closing->runPrecheck($sid, $pid, $this->rv(), $this->meta());

        $this->closing->reconfirmIncomeTax($sid, $pid, null, $this->rv(), $this->meta());
        $state = $this->closing->state($sid, $pid);
        self::assertNull($state['income_tax_stale']);
        self::assertTrue($state['can_close']);
        $this->completeInventory($sid, $pid, $this->userId);
        self::assertSame('closed', $this->closing->closeBooks($sid, $pid, $this->rv(), $this->meta())['status']);
    }

    /**
     * Zaúčtovaná daň 591 proti spočtené dani: rozdíl nad zaokrouhlení je varování (nikdy
     * blok), shoda prochází, nezaúčtovaná daň u nehotového kroku jen informuje.
     */
    public function testIncomeTaxCheckWarnsWhenPosted591DiffersFromComputedTax(): void
    {
        $this->seedScenario();
        $this->runStepsUntilCloseReady();
        $sid = $this->supplierId;
        $pid = $this->periodId;
        $check = fn (): ?array => $this->findCheck($this->closing->buildChecks($sid, $this->period()), 'income_tax_591_mismatch');

        $suggested = (float) $this->closing->incomeTaxPreview($sid, $pid)['suggested_amount'];
        self::assertGreaterThan(0.0, $suggested);
        $this->closing->runIncomeTax($sid, $pid, $suggested + 500.0, $this->rv(), $this->meta());
        $mismatch = $check();
        self::assertNotNull($mismatch);
        self::assertSame('warning', $mismatch['severity']);
        self::assertFalse($mismatch['ok']);
        self::assertEqualsWithDelta(500.0, (float) $mismatch['value']['difference'], 0.001);
        self::assertEqualsWithDelta($suggested + 500.0, (float) $mismatch['value']['posted'], 0.001);

        // Varování neblokuje uzavření knih a uzavření knih kontrolu nevynuluje (591 → 710 se nepočítá).
        $this->completeInventory($sid, $pid, $this->userId);
        $this->closing->closeBooks($sid, $pid, $this->rv(), $this->meta());
        self::assertEqualsWithDelta(500.0, (float) $check()['value']['difference'], 0.001);
    }

    public function testIncomeTaxCheckPassesWhenPostedTaxMatchesAndInformsWhilePending(): void
    {
        $this->seedScenario();
        $this->runStepsUntilCloseReady();
        $sid = $this->supplierId;
        $pid = $this->periodId;
        $check = fn (): ?array => $this->findCheck($this->closing->buildChecks($sid, $this->period()), 'income_tax_591_mismatch');

        // runStepsUntilCloseReady daň přeskočí; zisková firma bez 591 tedy varuje.
        self::assertFalse($check()['ok']);
        $this->closing->revertStep($sid, $pid, 'income_tax', $this->rv(), $this->meta());
        $pending = $check();
        self::assertSame(['info', true, 'not_posted_yet'], [$pending['severity'], $pending['ok'], $pending['value']['reason']]);

        $suggested = (float) $this->closing->incomeTaxPreview($sid, $pid)['suggested_amount'];
        $this->closing->runIncomeTax($sid, $pid, $suggested + 0.4, $this->rv(), $this->meta());
        self::assertTrue($check()['ok'], 'Haléřový rozdíl pod korunu nevaruje.');
    }

    public function testIncomeTaxCheckDoesNotWarnLossMakingCompanyWithout591(): void
    {
        $this->manual([self::l('518', 'debit', 4000.00), self::l('321', 'credit', 4000.00)], self::YEAR . '-03-05');
        $this->runStepsUntilCloseReady();
        $check = $this->findCheck($this->closing->buildChecks($this->supplierId, $this->period()), 'income_tax_591_mismatch');
        self::assertNotNull($check);
        self::assertTrue($check['ok']);
        self::assertEqualsWithDelta(0.0, (float) $check['value']['computed'], 0.001);
    }

    /**
     * @param list<array<string,mixed>> $checks
     * @return array<string,mixed>|null
     */
    private function findCheck(array $checks, string $key): ?array
    {
        foreach ($checks as $c) {
            if (($c['key'] ?? null) === $key) {
                return $c;
            }
        }
        return null;
    }

    /**
     * Rok převedený i s uzávěrkou původního systému (zápisy `closing` bez source_id) a
     * s převzatou otevírací rozvahou dalšího roku: uzavření knih nic znovu nezaúčtuje,
     * uloží skutečný výsledek hospodaření (bez převzaté uzávěrky, ne nulu) a otevření
     * roku převzaté počáteční stavy přijme.
     */
    public function testTakenOverClosingStoresRealProfitAndAcceptsTakenOverOpening(): void
    {
        $this->seedScenario();
        // Zůstatek s dimenzí: původní systém otevírací rozvahu převádí bez dimenzí, převzetí
        // se proto po převzaté uzávěrce porovnává po účtech.
        [$typeId, $a] = $this->createClosingDimensions();
        $this->manual([self::l('378', 'debit', 100.00) + ['dimensions' => [$typeId => $a]], self::l('379', 'credit', 100.00)], self::YEAR . '-04-01');
        $takenOverIds = $this->postTakenOverClosingAndOpening(
            [self::l('702', 'debit', 100.00), self::l('378', 'credit', 100.00), self::l('379', 'debit', 100.00), self::l('702', 'credit', 100.00)],
            [self::l('378', 'debit', 100.00), self::l('701', 'credit', 100.00), self::l('701', 'debit', 100.00), self::l('379', 'credit', 100.00)],
        );

        self::assertTrue($this->closing->state($this->supplierId, $this->periodId)['closing_taken_over']);
        self::assertSame($takenOverIds, $this->closing->takenOverClosingEntryIds($this->supplierId, $this->periodId));
        $this->runStepsUntilCloseReady();
        $state = $this->closing->state($this->supplierId, $this->periodId);
        self::assertTrue($state['can_close'], json_encode($state['close_blockers']));
        self::assertSame([], $state['taken_over_residual_accounts']);
        $preview = $state['opening_takeover'];
        self::assertSame('match', $preview['status'], json_encode($preview['diff'] ?? null));
        self::assertSame('account', $preview['compare_level'] ?? null);

        $closed = $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        self::assertNull($closed['entry_id'], 'Převzatá uzávěrka se znovu neúčtuje.');
        self::assertSame(0, $closed['lines_count']);
        self::assertSame(self::cents(6000.00), self::cents((float) $closed['profit']), 'Skutečný VH bez převzatých zápisů, ne nula.');
        self::assertTrue($closed['closing_taken_over']);
        self::assertNull($this->journal->findBySource($this->supplierId, 'closing', $this->periodId));

        $opened = $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        self::assertSame('taken_over', $opened['opening_source'] ?? null);
        $nextId = (int) $this->periods->nextPeriod($this->supplierId, self::ENDS_ON)['id'];
        self::assertCount(1, $this->closing->state($this->supplierId, $this->periodId)['steps'][array_search('open_next', array_column($this->closing->state($this->supplierId, $this->periodId)['steps'], 'step_key'), true)]['payload']['taken_over_entry_ids']);
        self::assertSame(1, (int) $this->db->pdo()->query('SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ' . $this->supplierId . " AND source_type = 'opening' AND period_id = " . $nextId)->fetchColumn());
    }

    /** Kurzové rozdíly a zásoby jde přeskočit jen v roce s převzatou uzávěrkou. */
    public function testFxAndStockCanBeSkippedOnlyWithTakenOverClosing(): void
    {
        $this->seedScenario();
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        try {
            $this->closing->confirmStep($this->supplierId, $this->periodId, 'fx_revaluation', 'skipped', null, $this->rv(), $this->meta());
            self::fail('Bez převzaté uzávěrky se kurzové rozdíly nepřeskakují.');
        } catch (ClosingException $e) {
            self::assertSame('step_not_skippable', $e->errorCode);
        }

        $this->closing->abort($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->postTakenOverClosingAndOpening();
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->confirmStep($this->supplierId, $this->periodId, 'fx_revaluation', 'skipped', null, $this->rv(), $this->meta());
        $this->closing->confirmStep($this->supplierId, $this->periodId, 'stock', 'skipped', null, $this->rv(), $this->meta());
        $state = $this->closing->state($this->supplierId, $this->periodId);
        self::assertSame(['skipped', 'skipped'], [$this->stepStatus($state, 'fx_revaluation'), $this->stepStatus($state, 'stock')]);
    }

    /** Převzatá uzávěrka a za ní zápis z doby po převodu: nesmíšené uzávěrky se odmítnou. */
    public function testTakenOverClosingWithLaterEntryRefusesToCloseBooks(): void
    {
        $this->seedScenario();
        $this->postTakenOverClosingAndOpening();
        $this->manual([self::l('518', 'debit', 100.00), self::l('321', 'credit', 100.00)], self::ENDS_ON);
        $this->runStepsUntilCloseReady();
        // Průvodce uzavření nenabízí a řekne proč (stejná kontrola jako closeBooks).
        $state = $this->closing->state($this->supplierId, $this->periodId);
        self::assertFalse($state['can_close']);
        self::assertContains('closing_taken_over_residual', $state['close_blockers']);
        self::assertSame(['518', '321'], $state['taken_over_residual_accounts']);
        try {
            $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
            self::fail('Zůstatek po převzaté uzávěrce musí uzavření knih odmítnout.');
        } catch (ClosingException $e) {
            self::assertSame('closing_taken_over_residual', $e->errorCode);
        }
    }

    /**
     * Daň z příjmů zaúčtovaná v původním systému ručním zápisem a uzavřená převzatou
     * uzávěrkou: náhled kroku ji ukáže jako zaúčtovanou (ne nulu), nový předpis odmítne
     * a krok jde potvrdit jako zaúčtovaný. Uzavření knih pak projde se skutečným VH po dani.
     */
    public function testTakenOverYearIncomeTaxIsConfirmedNotPostedAgain(): void
    {
        $this->seedScenario();
        $this->manual([self::l('591', 'debit', 1260.00), self::l('341', 'credit', 1260.00)], self::ENDS_ON);
        $this->postTakenOverClosingAndOpening(
            [self::l('710', 'debit', 1260.00), self::l('591', 'credit', 1260.00), self::l('702', 'debit', 1260.00), self::l('710', 'credit', 1260.00),
             self::l('341', 'debit', 1260.00), self::l('702', 'credit', 1260.00)],
            [self::l('701', 'debit', 1260.00), self::l('341', 'credit', 1260.00), self::l('431', 'debit', 1260.00), self::l('701', 'credit', 1260.00)],
        );
        $this->runStepsUntilIncomeTax();
        $sid = $this->supplierId;
        $pid = $this->periodId;

        $preview = $this->closing->incomeTaxPreview($sid, $pid);
        self::assertTrue($preview['closing_taken_over']);
        self::assertSame(self::cents(1260.00), self::cents((float) $preview['booked_amount']));
        self::assertSame(self::cents(1260.00), self::cents((float) $preview['booked_outside_wizard']));
        self::assertSame(self::cents(1260.00), self::cents((float) $preview['balance_591']), 'Zůstatek před převzatou uzávěrkou, ne nula.');
        self::assertSame(self::cents(-1260.00), self::cents((float) $preview['balance_341']));

        try {
            $this->closing->runIncomeTax($sid, $pid, 1260.0, $this->rv(), $this->meta());
            self::fail('V roce s převzatou uzávěrkou se daň znovu neúčtuje.');
        } catch (ClosingException $e) {
            self::assertSame('closing_taken_over', $e->errorCode);
        }
        self::assertNull($this->journal->findBySource($sid, 'income_tax', $pid));

        $confirmed = $this->closing->reconfirmIncomeTax($sid, $pid, null, $this->rv(), $this->meta());
        self::assertSame('taken_over', $confirmed['booked_outside']);
        $state = $this->closing->state($sid, $pid);
        self::assertSame('done', $this->stepStatus($state, 'income_tax'));
        self::assertTrue($state['can_close'], json_encode($state['close_blockers']));

        $closed = $this->closing->closeBooks($sid, $pid, $this->rv(), $this->meta());
        self::assertSame(self::cents(4740.00), self::cents((float) $closed['profit']));
    }

    /**
     * Daň zadaná ručním zápisem v běžném roce: krok ji nezdvojí. Nový předpis odmítne
     * s jasnou hláškou a nabídne potvrzení zaúčtované daně.
     */
    public function testIncomeTaxBookedByManualEntryIsNotDoubled(): void
    {
        $this->seedScenario();
        $this->manual([self::l('591', 'debit', 1260.00), self::l('341', 'credit', 1260.00)], self::ENDS_ON);
        $this->runStepsUntilIncomeTax();
        $sid = $this->supplierId;
        $pid = $this->periodId;

        $preview = $this->closing->incomeTaxPreview($sid, $pid);
        self::assertFalse($preview['closing_taken_over']);
        self::assertSame(self::cents(1260.00), self::cents((float) $preview['booked_outside_wizard']));
        try {
            $this->closing->runIncomeTax($sid, $pid, 1260.0, $this->rv(), $this->meta());
            self::fail('Daň zaúčtovaná ručním zápisem se nesmí zdvojit.');
        } catch (ClosingException $e) {
            self::assertSame('income_tax_booked_outside', $e->errorCode);
        }
        self::assertNull($this->journal->findBySource($sid, 'income_tax', $pid));

        $this->closing->reconfirmIncomeTax($sid, $pid, null, $this->rv(), $this->meta());
        $step = $this->closing->state($sid, $pid)['steps'][array_search('income_tax', array_column($this->closing->state($sid, $pid)['steps'], 'step_key'), true)];
        self::assertSame('done', $step['status']);
        self::assertSame('other_entries', $step['payload']['booked_outside']);
        self::assertSame(self::cents(1260.00), self::cents((float) $step['payload']['amount']));
        self::assertSame('other_entries', $this->closing->incomeTaxPreview($sid, $pid)['existing_booked_outside']);
    }

    /** Bez zaúčtované daně potvrzení „zaúčtováno" odmítne. */
    public function testIncomeTaxConfirmWithoutBookedTaxIsRefused(): void
    {
        $this->seedScenario();
        $this->runStepsUntilIncomeTax();
        try {
            $this->closing->reconfirmIncomeTax($this->supplierId, $this->periodId, null, $this->rv(), $this->meta());
            self::fail('Nezaúčtovanou daň nejde potvrdit.');
        } catch (ClosingException $e) {
            self::assertSame('income_tax_not_posted', $e->errorCode);
        }
    }

    /** Stornovaný převzatý zápis ani storno samo převzatou uzávěrkou nejsou. */
    public function testReversedClosingEntryIsNotTakenOverClosing(): void
    {
        $this->seedScenario();
        $entryId = $this->posting->postDocument($this->supplierId, 'closing', null, [
            self::l('602', 'debit', 10000.00), self::l('710', 'credit', 10000.00),
        ], ['entry_date' => self::ENDS_ON, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        $this->posting->reverse($this->supplierId, $entryId, ['entry_date' => self::ENDS_ON, 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        self::assertSame([], $this->closing->takenOverClosingEntryIds($this->supplierId, $this->periodId));
        self::assertFalse($this->closing->state($this->supplierId, $this->periodId)['closing_taken_over']);
    }

    /**
     * Uzávěrka a otevírací rozvaha tak, jak je převede import z jiného systému
     * (bez source_id). VH scénáře je 6 000.
     *
     * @return list<int> id převzatých uzávěrkových zápisů
     */
    private function postTakenOverClosingAndOpening(array $extraClosing = [], array $extraOpening = []): array
    {
        $meta = ['entry_date' => self::ENDS_ON, 'posted_by' => $this->userId, 'user_id' => $this->userId];
        $ids = [
            $this->posting->postDocument($this->supplierId, 'closing', null, [
                self::l('602', 'debit', 10000.00), self::l('710', 'credit', 10000.00),
                self::l('710', 'debit', 4000.00), self::l('518', 'credit', 4000.00),
                self::l('710', 'debit', 6000.00), self::l('702', 'credit', 6000.00),
            ], $meta),
            $this->posting->postDocument($this->supplierId, 'closing', null, [
                self::l('702', 'debit', 7100.00), self::l('311', 'credit', 7100.00),
                self::l('702', 'debit', 5000.00), self::l('221', 'credit', 5000.00),
                self::l('321', 'debit', 4840.00), self::l('702', 'credit', 4840.00),
                self::l('343', 'debit', 1260.00), self::l('702', 'credit', 1260.00),
                ...$extraClosing,
            ], $meta),
        ];
        $nextYear = self::YEAR + 1;
        $this->periods->create($this->supplierId, $nextYear, $nextYear . '-01-01', $nextYear . '-12-31');
        $this->posting->postDocument($this->supplierId, 'opening', null, [
            self::l('311', 'debit', 7100.00), self::l('701', 'credit', 7100.00),
            self::l('221', 'debit', 5000.00), self::l('701', 'credit', 5000.00),
            self::l('701', 'debit', 4840.00), self::l('321', 'credit', 4840.00),
            self::l('701', 'debit', 1260.00), self::l('343', 'credit', 1260.00),
            self::l('701', 'debit', 6000.00), self::l('431', 'credit', 6000.00),
            ...$extraOpening,
        ], ['entry_date' => $nextYear . '-01-01', 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        sort($ids);
        return $ids;
    }

    /** Nové zaúčtování daně po vrácení kroku příznak přepočtu také zruší. */
    public function testRerunningIncomeTaxClearsStaleFlag(): void
    {
        $this->seedScenario();
        $this->runStepsUntilCloseReady();
        $sid = $this->supplierId;
        $pid = $this->periodId;
        $this->closing->confirmStep($sid, $pid, 'provisions', 'done', null, $this->rv(), $this->meta());
        $this->closing->runIncomeTax($sid, $pid, 1260.0, $this->rv(), $this->meta());
        $this->closing->revertStep($sid, $pid, 'provisions', $this->rv(), $this->meta());
        $this->closing->confirmStep($sid, $pid, 'provisions', 'skipped', null, $this->rv(), $this->meta());
        self::assertSame(['provisions'], $this->closing->state($sid, $pid)['income_tax_stale']['caused_by'] ?? null);

        $this->closing->runIncomeTax($sid, $pid, 1300.0, $this->rv(), $this->meta());
        self::assertNull($this->closing->state($sid, $pid)['income_tax_stale']);
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
    private function issuedInvoice(float $amount, string $date): void
    {
        $pdo = $this->db->pdo();
        $clientId = (int) ($pdo->query('SELECT id FROM clients WHERE supplier_id = ' . $this->supplierId . ' ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $currencyId = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn();
        if ($clientId === 0) {
            $czId = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
            $pdo->prepare(
                'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
                 VALUES (?, "Odběratel test s.r.o.", "Testovací 1", "Praha", "11000", ?, "odberatel@example.com", ?)'
            )->execute([$this->supplierId, $czId, $currencyId]);
            $clientId = (int) $pdo->lastInsertId();
        }
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, client_id, invoice_type, varsymbol, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, client_snapshot, supplier_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, 0, "{}", "{}", ?, 0, ?, "issued", ?)'
        )->execute([$this->supplierId, $clientId, (string) random_int(10000000, 99999999), $date, $date, $date, $currencyId, $amount, $amount, $this->userId]);
    }

    private function createClosingDimensions(): array
    {
        $dimensions = $this->dimensions;
        $dimensions->setEnabled($this->supplierId, true);
        $types = $dimensions->ensureDefaultTypes($this->supplierId, ['stredisko', 'projekt']);
        $value = fn (int $typeId, string $code): int => (int) $dimensions->createValue(
            $this->supplierId,
            $typeId,
            ['code' => $code, 'name' => $code],
        )['id'];
        return [
            $types['cost_center'],
            $value($types['cost_center'], 'CLOSE-A'),
            $value($types['cost_center'], 'CLOSE-B'),
            $types['project'],
            $value($types['project'], 'CLOSE-C'),
            $value($types['project'], 'CLOSE-D'),
        ];
    }

    private function assertClosingDimensionContinuity(array $expected): void
    {
        foreach ($expected as $typeId => $balances) {
            ksort($balances);
            self::assertSame($balances, $this->dimensionBalances($typeId, self::YEAR . '-01-01', self::ENDS_ON));
        }
        $this->runStepsUntilCloseReady();
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $closing = $this->journal->findBySource($this->supplierId, 'closing', $this->periodId);
        $this->assertPairDimensions((int) $closing['id']);
        foreach ($expected as $typeId => $balances) {
            self::assertSame([], $this->dimensionBalances($typeId, self::YEAR . '-01-01', self::ENDS_ON), 'Uzávěrka vyrovná i jednotlivé dimenze.');
        }
        $opening = $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->assertPairDimensions((int) $opening['entry_id']);
        foreach ($expected as $typeId => $balances) {
            ksort($balances);
            self::assertSame($balances, $this->dimensionBalances($typeId, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31'), 'Otevření zachová saldo každé dimenze.');
        }
    }

    private function dimensionBalances(int $typeId, string $from, string $to): array
    {
        [$splitSql, $splitParams] = DimensionSplitAllocation::partsSql($typeId, null, $this->supplierId);
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(d.dimension_value_id, s.value_id) AS value_id,
                    SUM(IF(l.side = 'debit', 1, -1) * IF(l.is_red_storno, -1, 1)
                        * COALESCE(s.amount, l.amount)) AS balance
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
               LEFT JOIN journal_entry_line_dimensions d ON d.line_id = l.id AND d.dimension_type_id = ?
               LEFT JOIN ({$splitSql}) s ON s.line_id = l.id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
                AND e.entry_date BETWEEN ? AND ? AND a.account_code = '378'
                AND COALESCE(d.dimension_value_id, s.value_id) IS NOT NULL
              GROUP BY COALESCE(d.dimension_value_id, s.value_id) HAVING balance <> 0 ORDER BY value_id"
        );
        $stmt->execute([$typeId, ...$splitParams, $this->supplierId, $from, $to]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['value_id']] = self::cents((float) $row['balance']);
        }
        return $result;
    }

    private function assertPairDimensions(int $entryId): void
    {
        $assignments = new DimensionAssignmentRepository($this->db);
        $dimensions = $assignments->entryLineDimensions($this->supplierId, $entryId);
        $splits = $assignments->entryLineSplits($this->supplierId, $entryId);
        $lines = $this->journal->linesForEntry($entryId, $this->supplierId);
        foreach (array_chunk($lines, 2) as [$first, $second]) {
            self::assertSame($dimensions[$first['id']] ?? [], $dimensions[$second['id']] ?? []);
            self::assertSame($splits[$first['id']] ?? [], $splits[$second['id']] ?? []);
        }
    }

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
        $this->runStepsUntilIncomeTax($allowedFailingError);
        $this->closing->confirmStep($this->supplierId, $this->periodId, 'income_tax', 'skipped', null, $this->rv(), $this->meta());
    }

    /** start → precheck → kroky před daní z příjmů done/skipped. */
    private function runStepsUntilIncomeTax(?string $allowedFailingError = null): void
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
