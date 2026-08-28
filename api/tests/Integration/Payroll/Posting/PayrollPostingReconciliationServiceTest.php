<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll\Posting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\Payroll\PayrollPostingReconciliationRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Payroll\Posting\PayrollPostingReconciliationService;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * MZ-18-W07 — reconciliation je odvozený read model: porovnává kontrolní
 * součty MZ-13-W06, skutečně zaúčtovaný deník a platební závazky MZ-17.
 * Fixture staví jeden vyvážený mzdový předpis (521/524 MD, 336/342/379/331
 * D) s konzistentními čísly napříč všemi třemi zdroji, aby šlo cíleně
 * rozbít právě jeden z nich a ověřit, že rozdíl padne do správné kategorie.
 */
#[Group('integration')]
final class PayrollPostingReconciliationServiceTest extends TestCase
{
    private const YEAR = 2098;

    private Connection $db;
    private PostingService $posting;
    private PayrollPostingReconciliationService $reconciliation;
    private PayrollPostingReconciliationRepository $reconciliationRepository;
    private AccountingPeriodRepository $periods;
    private ChartOfAccountsSeeder $seeder;
    private int $supplierId = 0;
    private int $userId = 0;
    private bool $inTransaction = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 5);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped(
                'cfg.php neexistuje — test vyžaduje DB connection.',
            );
        }

        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->posting = $container->get(PostingService::class);
            $this->reconciliation = $container->get(
                PayrollPostingReconciliationService::class,
            );
            $this->reconciliationRepository = $container->get(
                PayrollPostingReconciliationRepository::class,
            );
            $this->periods = $container->get(AccountingPeriodRepository::class);
            $this->seeder = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $exception) {
            $this->markTestSkipped('DI nedostupné: ' . $exception->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->userId = (int) (
            $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn()
                ?: 0
        );
        $currencyId = (int) (
            $pdo->query(
                "SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1",
            )->fetchColumn() ?: 0
        );
        $vatRateId = (int) (
            $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn()
                ?: 0
        );
        $countryId = (int) (
            $pdo->query(
                "SELECT id FROM countries WHERE iso2 = 'CZ' ORDER BY id LIMIT 1",
            )->fetchColumn() ?: 0
        );
        if ($this->userId === 0
            || $currencyId === 0
            || $vatRateId === 0
            || $countryId === 0
        ) {
            $this->markTestSkipped(
                'Chybí základní data (user/currency/vat_rate/country) v DB.',
            );
        }

        $pdo->beginTransaction();
        $this->inTransaction = true;
        $this->supplierId = $this->createSupplier(
            $pdo,
            'double_entry',
            $countryId,
            $currencyId,
            $vatRateId,
        );
    }

    protected function tearDown(): void
    {
        if (!isset($this->db) || !$this->inTransaction) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $this->db->close();
    }

    public function testMatchingPeriodReportsZeroDiffs(): void
    {
        $fixture = $this->buildBalancedRevision(month: 6, deducted: 445, tag: 'A');
        $this->createEmployee($fixture['employeeId']);
        $this->materializeLiabilities($fixture['revisionId'], $fixture['employeeId'], [
            'net_wage' => 10_100,
            'social_insurance' => 1_400,
            'health_insurance' => 1_000,
            'advance_tax' => 900,
            'deduction' => 445,
        ]);

        $result = $this->reconciliation->forPeriod(
            $this->supplierId,
            self::YEAR . '-06',
        );

        self::assertSame('posted', $result['journal_state']);
        self::assertSame('materialized', $result['payments_state']);
        self::assertSame('reconciled', $result['overall_status']);
        $byKey = array_column($result['categories'], null, 'key');
        foreach ([
            'gross_wages' => 12_345,
            'employer_contributions' => 1_500,
            'social_health_insurance' => 2_400,
            'income_tax' => 900,
            'other_deductions' => 445,
            'enforcement' => 0,
            'net_wage' => 10_100,
        ] as $key => $expected) {
            self::assertSame($expected, $byKey[$key]['payroll_minor'], "payroll_minor {$key}");
            self::assertSame('match', $byKey[$key]['status'], "status {$key}");
        }
        self::assertSame(12_345, $byKey['gross_wages']['journal_minor']);
        self::assertSame(2_400, $byKey['social_health_insurance']['journal_minor']);
        self::assertSame(0, $byKey['social_health_insurance']['diff_payroll_journal_minor']);
    }

    /**
     * Nepeněžní plnění BEZ vlastní předkontace (1 % vstupní ceny vozidla,
     * přechodné ubytování) se záměrně neúčtuje — náklad je v knihách už ze
     * zdrojového dokladu. Kontrolní součty MZ-13 ho ale do hrubého příjmu
     * počítají, takže bez odpočtu by takové období trvale svítilo rozdílem.
     */
    public function testNeutralNonMonetaryComponentIsNotADifference(): void
    {
        $this->buildBalancedRevision(
            month: 3,
            deducted: 445,
            tag: 'NON-MONETARY-NEUTRAL',
            nonMonetaryMinor: 2_000,
        );

        $result = $this->reconciliation->forPeriod(
            $this->supplierId,
            self::YEAR . '-03',
        );

        $byKey = array_column($result['categories'], null, 'key');
        self::assertSame('reconciled', $result['overall_status']);
        self::assertSame(12_345, $byKey['gross_wages']['payroll_minor']);
        self::assertSame(12_345, $byKey['gross_wages']['journal_minor']);
        self::assertSame(0, $byKey['gross_wages']['diff_payroll_journal_minor']);
        self::assertSame('match', $byKey['gross_wages']['status']);
        // Číslo nemizí — jen se nepočítá jako rozdíl.
        self::assertSame(2_000, $byKey['non_monetary_neutral']['payroll_minor']);
        self::assertNull($byKey['non_monetary_neutral']['journal_minor']);
        self::assertNull(
            $byKey['non_monetary_neutral']['diff_payroll_journal_minor'],
        );
        self::assertNull(
            $byKey['non_monetary_neutral']['diff_payroll_payments_minor'],
        );
        self::assertSame(
            'not_applicable',
            $byKey['non_monetary_neutral']['status'],
        );
    }

    /**
     * Opačný případ: nepeněžní složka, která vlastní předkontaci MÁ, se účtuje
     * a do porovnání patří celá. Vyloučit se smí jen to, co se neúčtuje.
     */
    public function testNonMonetaryComponentWithOwnAccountsStaysInComparison(): void
    {
        $this->buildBalancedRevision(
            month: 4,
            deducted: 445,
            tag: 'NON-MONETARY-POSTED',
            nonMonetaryMinor: 2_000,
            nonMonetaryAccounting: [
                'debit_code' => '528',
                'credit_code' => '333',
            ],
        );

        $result = $this->reconciliation->forPeriod(
            $this->supplierId,
            self::YEAR . '-04',
        );

        $byKey = array_column($result['categories'], null, 'key');
        self::assertSame('reconciled', $result['overall_status']);
        self::assertSame(14_345, $byKey['gross_wages']['payroll_minor']);
        self::assertSame(14_345, $byKey['gross_wages']['journal_minor']);
        self::assertSame(0, $byKey['gross_wages']['diff_payroll_journal_minor']);
        self::assertSame(0, $byKey['non_monetary_neutral']['payroll_minor']);
        self::assertSame(
            'not_applicable',
            $byKey['non_monetary_neutral']['status'],
        );
    }

    /**
     * Rozpad 336 na analytiku ČSSZ (336.100) a zdravotních pojišťoven
     * (336.200) nesmí kategorii rozbít — páruje se přes syntetiku.
     */
    public function testInsuranceAnalyticAccountsPairIntoOneCategory(): void
    {
        $this->buildBalancedRevision(
            month: 2,
            deducted: 445,
            tag: 'INSURANCE-ANALYTICS',
            splitInsurance: true,
        );

        $result = $this->reconciliation->forPeriod(
            $this->supplierId,
            self::YEAR . '-02',
        );

        $byKey = array_column($result['categories'], null, 'key');
        self::assertSame('reconciled', $result['overall_status']);
        self::assertSame(2_400, $byKey['social_health_insurance']['payroll_minor']);
        self::assertSame(2_400, $byKey['social_health_insurance']['journal_minor']);
        self::assertSame(
            0,
            $byKey['social_health_insurance']['diff_payroll_journal_minor'],
        );
    }

    /**
     * Cestovní náhrada je náhrada výdaje, ne mzda — účtuje se na 512, ale
     * z kategorie hrubých mezd vypadnout nesmí (a nesmí se do ní započítat
     * dvakrát: jednou přes cílovou alokaci a podruhé přes prefix).
     */
    public function testTravelExpenseAccountCountsOnceInGrossWages(): void
    {
        $this->buildBalancedRevision(
            month: 8,
            deducted: 445,
            tag: 'TRAVEL-EXPENSE',
            grossAccount: '512',
        );

        $result = $this->reconciliation->forPeriod(
            $this->supplierId,
            self::YEAR . '-08',
        );

        $byKey = array_column($result['categories'], null, 'key');
        self::assertSame('reconciled', $result['overall_status']);
        self::assertSame(12_345, $byKey['gross_wages']['payroll_minor']);
        self::assertSame(12_345, $byKey['gross_wages']['journal_minor']);
        self::assertSame(0, $byKey['gross_wages']['diff_payroll_journal_minor']);
    }

    /**
     * Povinné spoření u rizikové práce účtuje 527 MD / 379 D BEZ analytické
     * dimenze srážky. Sdílená 379 ho proto nesmí vtáhnout do `other_deductions`
     * ani do `enforcement` — ty se rozlišují právě dimenzí MZ-SR-/MZ-EX-.
     */
    public function testRiskySavingsDoesNotLeakIntoDeductionCategories(): void
    {
        $this->buildBalancedRevision(
            month: 1,
            deducted: 445,
            tag: 'RISKY-SAVINGS-379',
            riskySavingsMinor: 400,
        );

        $result = $this->reconciliation->forPeriod(
            $this->supplierId,
            self::YEAR . '-01',
        );

        $byKey = array_column($result['categories'], null, 'key');
        self::assertSame('reconciled', $result['overall_status']);
        self::assertSame(400, $byKey['risky_savings']['payroll_minor']);
        self::assertSame(400, $byKey['risky_savings']['journal_minor']);
        self::assertSame(445, $byKey['other_deductions']['payroll_minor']);
        self::assertSame(445, $byKey['other_deductions']['journal_minor']);
        self::assertSame(0, $byKey['enforcement']['journal_minor']);
    }

    /**
     * Pohledávka za zaměstnancem z přeplatku čisté mzdy (335) je jiná veličina
     * než závazek 331 — do kategorie čisté mzdy nepatří ani kladně, ani jako
     * záporná čistá mzda. Kdyby 335 spadla mezi účty čisté mzdy, kategorie by
     * o částku přeplatku klesla a období by svítilo rozdílem.
     */
    public function testEmployeeReceivableAccountStaysOutOfNetWage(): void
    {
        $this->buildBalancedRevision(
            month: 6,
            deducted: 445,
            tag: 'EMPLOYEE-RECEIVABLE',
            additionalLines: [
                ['account_code' => '335', 'side' => 'debit', 'amount' => '5.00'],
                ['account_code' => '333', 'side' => 'credit', 'amount' => '5.00'],
            ],
        );

        $result = $this->reconciliation->forPeriod(
            $this->supplierId,
            self::YEAR . '-06',
        );

        $byKey = array_column($result['categories'], null, 'key');
        self::assertSame('reconciled', $result['overall_status']);
        self::assertSame(10_100, $byKey['net_wage']['payroll_minor']);
        self::assertSame(10_100, $byKey['net_wage']['journal_minor']);
        self::assertSame(0, $byKey['net_wage']['diff_payroll_journal_minor']);
    }

    /**
     * Výchozí účet dimenze je záměrně obecný analytický účet, ne jen analytika
     * syntetik 521/522/523. Reconciliation proto nesmí ztratit hrubou mzdu jen
     * proto, že zmrazená dimenze poslala její náklad například na účet 518.
     */
    public function testDimensionDefaultAccountRemainsGrossWageInReconciliation(): void
    {
        $this->buildBalancedRevision(
            month: 11,
            deducted: 445,
            tag: 'DIMENSION-ACCOUNT',
            grossAccount: '518',
        );

        $result = $this->reconciliation->forPeriod(
            $this->supplierId,
            self::YEAR . '-11',
        );

        $byKey = array_column($result['categories'], null, 'key');
        self::assertSame('reconciled', $result['overall_status']);
        self::assertSame(12_345, $byKey['gross_wages']['journal_minor']);
        self::assertSame(0, $byKey['gross_wages']['diff_payroll_journal_minor']);
    }

    /** Přesun dimenze na jiný účet musí správně zahrnout i storno starého účtu. */
    public function testDimensionAccountCorrectionKeepsBothAccountsInGrossWages(): void
    {
        $fixture = $this->buildBalancedRevision(
            month: 12,
            deducted: 445,
            tag: 'DIMENSION-CORRECTION',
            grossAccount: '518',
        );
        $correctionResult = $this->buildResultSnapshot(
            $fixture['employeeId'],
            $fixture['employmentId'],
            deducted: 445,
        );
        $revisionId = $this->insertRevision(
            $fixture['runId'],
            2,
            $fixture['inputJson'],
            $correctionResult['json'],
            approved: true,
        );
        $this->insertStatutoryResult(
            $revisionId,
            'social_insurance',
            ['employer_contribution_minor_units' => 800],
        );
        $this->insertStatutoryResult(
            $revisionId,
            'health_insurance',
            ['employer_contribution_minor_units' => 700],
        );
        $this->db->pdo()->prepare(
            'UPDATE payroll_runs SET current_revision_no = 2 WHERE id = ?',
        )->execute([$fixture['runId']]);

        $entryDate = self::YEAR . '-12-31';
        $batchId = $this->preparePostingBatch(
            $fixture['runId'],
            $revisionId,
            $entryDate,
            $fixture['batchId'],
        );
        $this->insertPostingAllocation(
            $batchId,
            "gross:employment:{$fixture['employmentId']}:input:1:debit",
            '521',
            12_345,
        );
        $entryId = $this->postJournal($revisionId, $entryDate, [
            ['account_code' => '518', 'side' => 'credit', 'amount' => '123.45'],
            ['account_code' => '521', 'side' => 'debit', 'amount' => '123.45'],
        ]);
        $this->finalizeBatch($batchId, $entryId);

        $result = $this->reconciliation->forPeriod(
            $this->supplierId,
            self::YEAR . '-12',
        );

        $byKey = array_column($result['categories'], null, 'key');
        self::assertSame('reconciled', $result['overall_status']);
        self::assertSame(12_345, $byKey['gross_wages']['journal_minor']);
        self::assertSame(0, $byKey['gross_wages']['diff_payroll_journal_minor']);
    }

    /** Dvě analytiky stejné syntetiky musí zůstat dvěma řádky repository. */
    public function testJournalTotalsKeepFullAnalyticAccountInGroupKey(): void
    {
        $this->createAnalyticAccount('518.100');
        $this->createAnalyticAccount('518.200');
        $fixture = $this->buildBalancedRevision(
            month: 10,
            deducted: 445,
            tag: 'ANALYTIC-GROUPING',
            grossAccount: '518.100',
            additionalLines: [
                ['account_code' => '518.200', 'side' => 'debit', 'amount' => '1.00'],
                ['account_code' => '331', 'side' => 'credit', 'amount' => '1.00'],
            ],
        );

        $rows = array_values(array_filter(
            $this->reconciliationRepository->journalTotals(
                $this->supplierId,
                [$fixture['revisionId']],
            ),
            static fn (array $row): bool =>
                $row['prefix'] === '518' && $row['side'] === 'debit',
        ));

        self::assertSame(
            ['518.100', '518.200'],
            array_column($rows, 'account_code'),
        );
        self::assertSame([12_345, 100], array_column($rows, 'amount_minor'));
    }

    /** Rezervovaný účet 524 nesmí jednu částku vykázat ve dvou kategoriích. */
    public function testReservedGrossAccountFailsClosedInsteadOfDoubleCounting(): void
    {
        $this->buildBalancedRevision(
            month: 9,
            deducted: 445,
            tag: 'RESERVED-GROSS-ACCOUNT',
            grossAccount: '524',
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('kolizní');
        $this->reconciliation->forPeriod($this->supplierId, self::YEAR . '-09');
    }

    public function testTamperedJournalIsDetectedAndAttributedToTheRightCategory(): void
    {
        $fixture = $this->buildBalancedRevision(month: 6, deducted: 445, tag: 'B');
        $this->createEmployee($fixture['employeeId']);
        $this->materializeLiabilities($fixture['revisionId'], $fixture['employeeId'], [
            'net_wage' => 10_100,
            'social_insurance' => 1_400,
            'health_insurance' => 1_000,
            'advance_tax' => 900,
            'deduction' => 445,
        ]);

        // Simuluje poškozený/ručně upravený deník mimo aplikační vrstvu —
        // reconciliation musí porovnávat SKUTEČNÝ deník, ne interní cache.
        $this->db->pdo()->prepare(
            'UPDATE journal_entry_lines line
               JOIN chart_of_accounts account ON account.id = line.account_id
                SET line.amount = line.amount + 0.50
              WHERE line.supplier_id = ?
                AND account.account_code = "336"
                AND line.side = "credit"',
        )->execute([$this->supplierId]);

        $result = $this->reconciliation->forPeriod(
            $this->supplierId,
            self::YEAR . '-06',
        );

        self::assertSame('diff', $result['overall_status']);
        $byKey = array_column($result['categories'], null, 'key');
        self::assertSame('diff', $byKey['social_health_insurance']['status']);
        self::assertSame(2_450, $byKey['social_health_insurance']['journal_minor']);
        self::assertSame(-50, $byKey['social_health_insurance']['diff_payroll_journal_minor']);
        // Ostatní kategorie zůstávají nedotčené.
        foreach (['gross_wages', 'employer_contributions', 'income_tax', 'other_deductions', 'net_wage'] as $key) {
            self::assertSame('match', $byKey[$key]['status'], "status {$key} nemá zůstat rozdíl");
        }
    }

    public function testCorrectionRevisionSumsWithOriginalAgainstJournal(): void
    {
        $fixture = $this->buildBalancedRevision(month: 7, deducted: 445, tag: 'C');

        // Opravná revize: standardní srážka naroste o 100 Kč, čistá mzda o 100 Kč klesne.
        $correctionResult = $this->buildResultSnapshot(
            $fixture['employeeId'],
            $fixture['employmentId'],
            deducted: 545,
        );
        $correctionRevisionId = $this->insertRevision(
            $fixture['runId'],
            2,
            $fixture['inputJson'],
            $correctionResult['json'],
            approved: true,
        );
        $this->insertStatutoryResult(
            $correctionRevisionId,
            'social_insurance',
            ['employer_contribution_minor_units' => 800],
        );
        $this->insertStatutoryResult(
            $correctionRevisionId,
            'health_insurance',
            ['employer_contribution_minor_units' => 700],
        );
        $this->db->pdo()->prepare(
            'UPDATE payroll_runs SET current_revision_no = 2 WHERE id = ?',
        )->execute([$fixture['runId']]);

        // Deník opravné revize nese jen DELTU: +100 na 379 (dimenze MZ-SR-),
        // -100 (debit) na 331 — přesně mechanika PayrollPostingLineBuilder::deltaLines.
        $correctionBatchId = $this->preparePostingBatch(
            $fixture['runId'],
            $correctionRevisionId,
            self::YEAR . '-07-31',
            $fixture['batchId'],
        );
        $correctionEntryId = $this->postJournal(
            $correctionRevisionId,
            self::YEAR . '-07-31',
            [
                ['account_code' => '379', 'side' => 'credit', 'amount' => '1.00', 'cost_center' => 'MZ-SR-CORR0000000001'],
                ['account_code' => '331', 'side' => 'debit', 'amount' => '1.00'],
            ],
        );
        $this->finalizeBatch($correctionBatchId, $correctionEntryId);

        $result = $this->reconciliation->forPeriod(
            $this->supplierId,
            self::YEAR . '-07',
        );

        self::assertSame('posted', $result['journal_state']);
        $byKey = array_column($result['categories'], null, 'key');
        self::assertSame(545, $byKey['other_deductions']['payroll_minor']);
        self::assertSame(545, $byKey['other_deductions']['journal_minor']);
        self::assertSame(0, $byKey['other_deductions']['diff_payroll_journal_minor']);
        self::assertSame(10_000, $byKey['net_wage']['payroll_minor']);
        self::assertSame(10_000, $byKey['net_wage']['journal_minor']);
        self::assertSame(0, $byKey['net_wage']['diff_payroll_journal_minor']);
        self::assertSame('reconciled', $result['overall_status']);
    }

    public function testCorrectionLiabilityDeltasReconstructCurrentPayrollAmount(): void
    {
        $fixture = $this->buildBalancedRevision(
            month: 5,
            deducted: 445,
            tag: 'LIABILITY-CORRECTION',
        );
        $this->materializeLiabilities(
            $fixture['revisionId'],
            $fixture['employeeId'],
            ['net_wage' => 10_100],
        );
        $originalLiabilityId = (int) $this->db->pdo()->query(
            'SELECT id FROM payroll_payment_liabilities
              WHERE supplier_id = ' . $this->supplierId
            . ' AND revision_id = ' . $fixture['revisionId']
            . ' AND liability_kind = "net_wage"',
        )->fetchColumn();
        self::assertGreaterThan(0, $originalLiabilityId);

        $correctionResult = $this->buildResultSnapshot(
            $fixture['employeeId'],
            $fixture['employmentId'],
            deducted: 545,
        );
        $correctionRevisionId = $this->insertRevision(
            $fixture['runId'],
            2,
            $fixture['inputJson'],
            $correctionResult['json'],
            approved: true,
            previousRevisionId: $fixture['revisionId'],
            revisionKind: 'correction',
        );
        $this->createRunPerson($correctionRevisionId, $fixture['employeeId']);
        $snapshot = CanonicalJson::encode(['kind' => 'net_wage', 'delta' => -100]);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_payment_liabilities
                (supplier_id, revision_id, employee_id, liability_reference,
                 liability_kind, direction, recipient_reference, due_on,
                 currency_code, amount_minor, previous_liability_id,
                 source_snapshot_json, source_snapshot_hash,
                 idempotency_key_hash, created_by)
             VALUES (?, ?, ?, ?, "net_wage", "incoming", "test-recipient",
                     ?, "CZK", 100, ?, ?, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $correctionRevisionId,
            $fixture['employeeId'],
            'test-net_wage-' . $fixture['revisionId'],
            self::YEAR . '-06-15',
            $originalLiabilityId,
            $snapshot,
            hash('sha256', $snapshot),
            random_bytes(32),
            $this->userId,
        ]);

        self::assertSame([[
            'liability_kind' => 'net_wage',
            'liability_minor' => 10_000,
            'paid_minor' => 0,
        ]], $this->reconciliationRepository->liabilityTotals(
            $this->supplierId,
            [$fixture['revisionId'], $correctionRevisionId],
        ));
    }

    public function testUnapprovedMonthIsUnpostedNotADiff(): void
    {
        $employeeId = 9_001;
        $employmentId = 9_101;
        $runId = $this->createRun(month: 8);
        $input = $this->buildInputSnapshot($employeeId, $employmentId);
        $resultData = $this->buildResultSnapshot($employeeId, $employmentId, deducted: 100);
        // Revize zůstává ve stavu 'reviewed' — otevřený, neschválený měsíc.
        $this->insertRevision(
            $runId,
            1,
            CanonicalJson::encode($input),
            $resultData['json'],
            approved: false,
        );

        $result = $this->reconciliation->forPeriod(
            $this->supplierId,
            self::YEAR . '-08',
        );

        self::assertSame('no_revision', $result['journal_state']);
        self::assertSame('info', $result['overall_status']);
        self::assertSame([], $result['categories']);
    }

    public function testApprovedButNotYetPostedMonthIsUnposted(): void
    {
        $employeeId = 9_002;
        $employmentId = 9_102;
        $runId = $this->createRun(month: 9);
        $input = $this->buildInputSnapshot($employeeId, $employmentId);
        $resultData = $this->buildResultSnapshot($employeeId, $employmentId, deducted: 100);
        $revisionId = $this->insertRevision(
            $runId,
            1,
            CanonicalJson::encode($input),
            $resultData['json'],
            approved: true,
        );
        $this->insertStatutoryResult(
            $revisionId,
            'social_insurance',
            ['employer_contribution_minor_units' => 800],
        );
        $this->insertStatutoryResult(
            $revisionId,
            'health_insurance',
            ['employer_contribution_minor_units' => 700],
        );
        // Žádná payroll_posting_batches — automatické zaúčtování je vypnuté/zatím neproběhlo.

        $result = $this->reconciliation->forPeriod(
            $this->supplierId,
            self::YEAR . '-09',
        );

        self::assertSame('unposted', $result['journal_state']);
        self::assertNotSame('diff', $result['overall_status']);
        foreach ($result['categories'] as $category) {
            self::assertNull($category['journal_minor']);
            self::assertNull($category['diff_payroll_journal_minor']);
        }
    }

    public function testTaxEvidenceSupplierIsNotApplicableAndDoesNotCrash(): void
    {
        $pdo = $this->db->pdo();
        $currencyId = (int) $pdo->query(
            "SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1",
        )->fetchColumn();
        $vatRateId = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $countryId = (int) $pdo->query(
            "SELECT id FROM countries WHERE iso2 = 'CZ' ORDER BY id LIMIT 1",
        )->fetchColumn();
        $deSupplierId = $this->createSupplier(
            $pdo,
            'tax_evidence',
            $countryId,
            $currencyId,
            $vatRateId,
        );
        $employeeId = 9_003;
        $employmentId = 9_103;
        $statement = $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status,
                 current_revision_no, created_by, updated_by)
             VALUES (?, ?, ?, "approved", 1, ?, ?)',
        );
        $statement->execute([
            $deSupplierId,
            self::YEAR . '-10-01',
            self::YEAR . '-11-15',
            $this->userId,
            $this->userId,
        ]);
        $runId = (int) $pdo->lastInsertId();
        $input = $this->buildInputSnapshot($employeeId, $employmentId);
        $resultData = $this->buildResultSnapshot($employeeId, $employmentId, deducted: 100);
        $inputJson = CanonicalJson::encode($input);
        $deRevisionId = $this->insertRevisionFor(
            $deSupplierId,
            $runId,
            1,
            $inputJson,
            $resultData['json'],
            true,
        );
        $this->insertStatutoryResultFor(
            $deSupplierId,
            $deRevisionId,
            'social_insurance',
            ['employer_contribution_minor_units' => 800],
        );
        $this->insertStatutoryResultFor(
            $deSupplierId,
            $deRevisionId,
            'health_insurance',
            ['employer_contribution_minor_units' => 700],
        );

        $result = $this->reconciliation->forPeriod($deSupplierId, self::YEAR . '-10');

        self::assertSame('tax_evidence', $result['accounting_mode']);
        self::assertSame('not_applicable', $result['journal_state']);
        self::assertNotSame('diff', $result['overall_status']);
    }

    public function testForeignSupplierCannotSeeAnotherCompanysPeriod(): void
    {
        $this->buildBalancedRevision(month: 6, deducted: 445, tag: 'D');

        $result = $this->reconciliation->forPeriod(
            $this->supplierId + 1_000_000,
            self::YEAR . '-06',
        );

        self::assertNull($result['run']);
        self::assertSame('no_revision', $result['journal_state']);
        self::assertSame([], $result['categories']);
    }

    public function testReconciliationNeverMutatesJournalOrRevision(): void
    {
        $fixture = $this->buildBalancedRevision(month: 6, deducted: 445, tag: 'E');
        $this->createEmployee($fixture['employeeId']);
        $this->materializeLiabilities($fixture['revisionId'], $fixture['employeeId'], [
            'net_wage' => 10_100,
        ]);
        $pdo = $this->db->pdo();
        $countBefore = [
            'revisions' => (int) $pdo->query('SELECT COUNT(*) FROM payroll_run_revisions')->fetchColumn(),
            'journal_entries' => (int) $pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn(),
            'journal_entry_lines' => (int) $pdo->query('SELECT COUNT(*) FROM journal_entry_lines')->fetchColumn(),
            'posting_batches' => (int) $pdo->query('SELECT COUNT(*) FROM payroll_posting_batches')->fetchColumn(),
            'liabilities' => (int) $pdo->query('SELECT COUNT(*) FROM payroll_payment_liabilities')->fetchColumn(),
        ];

        $first = $this->reconciliation->forPeriod($this->supplierId, self::YEAR . '-06');
        $second = $this->reconciliation->forPeriod($this->supplierId, self::YEAR . '-06');

        $countAfter = [
            'revisions' => (int) $pdo->query('SELECT COUNT(*) FROM payroll_run_revisions')->fetchColumn(),
            'journal_entries' => (int) $pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn(),
            'journal_entry_lines' => (int) $pdo->query('SELECT COUNT(*) FROM journal_entry_lines')->fetchColumn(),
            'posting_batches' => (int) $pdo->query('SELECT COUNT(*) FROM payroll_posting_batches')->fetchColumn(),
            'liabilities' => (int) $pdo->query('SELECT COUNT(*) FROM payroll_payment_liabilities')->fetchColumn(),
        ];
        self::assertSame($countBefore, $countAfter);
        self::assertSame($first, $second);
    }

    // ---------------------------------------------------------------
    // Fixture helpers
    // ---------------------------------------------------------------

    /**
     * @return array{
     *   runId:int,revisionId:int,batchId:int,employeeId:int,employmentId:int,
     *   inputJson:string
     * }
     */
    /**
     * @param list<array{account_code:string,side:string,amount:string,cost_center?:string}> $additionalLines
     * @param array{debit_code:string,credit_code:string}|null $nonMonetaryAccounting
     */
    private function buildBalancedRevision(
        int $month,
        int $deducted,
        string $tag,
        string $grossAccount = '521',
        array $additionalLines = [],
        int $nonMonetaryMinor = 0,
        ?array $nonMonetaryAccounting = null,
        bool $splitInsurance = false,
        int $riskySavingsMinor = 0,
    ): array
    {
        $employeeId = 8_000 + crc32($tag) % 900;
        $employmentId = 8_500 + crc32($tag . 'e') % 900;
        $this->createEmployee($employeeId);
        $runId = $this->createRun($month);
        $input = $this->buildInputSnapshot($employeeId, $employmentId);
        $inputJson = CanonicalJson::encode($input);
        $resultData = $this->buildResultSnapshot(
            $employeeId,
            $employmentId,
            $deducted,
            $nonMonetaryMinor,
            $nonMonetaryAccounting,
            $riskySavingsMinor,
        );
        $revisionId = $this->insertRevision($runId, 1, $inputJson, $resultData['json'], true);
        $this->createRunPerson($revisionId, $employeeId);
        $this->insertStatutoryResult(
            $revisionId,
            'social_insurance',
            ['employer_contribution_minor_units' => 800],
        );
        $this->insertStatutoryResult(
            $revisionId,
            'health_insurance',
            ['employer_contribution_minor_units' => 700],
        );

        $netWage = 12_345 - 600 - 300 - 900 - $deducted;
        // 336.100/336.200 jsou od migrace 1618 přímo ve směrné osnově.
        $insuranceLines = $splitInsurance
            ? [
                ['account_code' => '336.100', 'side' => 'credit', 'amount' => '14.00'],
                ['account_code' => '336.200', 'side' => 'credit', 'amount' => '10.00'],
            ]
            : [['account_code' => '336', 'side' => 'credit', 'amount' => '24.00']];
        $lines = [
            ['account_code' => $grossAccount, 'side' => 'debit', 'amount' => '123.45'],
            ['account_code' => '524', 'side' => 'debit', 'amount' => '15.00'],
            ...$insuranceLines,
            ['account_code' => '342', 'side' => 'credit', 'amount' => '9.00'],
            [
                'account_code' => '379',
                'side' => 'credit',
                'amount' => number_format($deducted / 100, 2, '.', ''),
                'cost_center' => 'MZ-SR-' . strtoupper(substr(hash('sha256', $tag), 0, 16)),
            ],
            [
                'account_code' => '331',
                'side' => 'credit',
                'amount' => number_format($netWage / 100, 2, '.', ''),
            ],
        ];
        if ($riskySavingsMinor > 0) {
            // 527 MD / 379 D BEZ analytické dimenze srážky — nesmí spadnout
            // do `other_deductions` ani do `enforcement`.
            $lines[] = [
                'account_code' => '527',
                'side' => 'debit',
                'amount' => number_format($riskySavingsMinor / 100, 2, '.', ''),
            ];
            $lines[] = [
                'account_code' => '379',
                'side' => 'credit',
                'amount' => number_format($riskySavingsMinor / 100, 2, '.', ''),
            ];
        }
        if ($nonMonetaryAccounting !== null && $nonMonetaryMinor > 0) {
            $lines[] = [
                'account_code' => $nonMonetaryAccounting['debit_code'],
                'side' => 'debit',
                'amount' => number_format($nonMonetaryMinor / 100, 2, '.', ''),
            ];
            $lines[] = [
                'account_code' => $nonMonetaryAccounting['credit_code'],
                'side' => 'credit',
                'amount' => number_format($nonMonetaryMinor / 100, 2, '.', ''),
            ];
        }
        array_push($lines, ...$additionalLines);
        $entryDate = sprintf('%d-%02d-%02d', self::YEAR, $month, cal_days_in_month(CAL_GREGORIAN, $month, self::YEAR));
        $batchId = $this->preparePostingBatch($runId, $revisionId, $entryDate, null);
        $this->insertPostingAllocation(
            $batchId,
            "gross:employment:{$employmentId}:input:1:debit",
            $grossAccount,
            12_345,
        );
        if ($nonMonetaryAccounting !== null && $nonMonetaryMinor > 0) {
            $this->insertPostingAllocation(
                $batchId,
                "gross:employment:{$employmentId}:input:2:debit",
                $nonMonetaryAccounting['debit_code'],
                $nonMonetaryMinor,
            );
        }
        $entryId = $this->postJournal($revisionId, $entryDate, $lines);
        $this->finalizeBatch($batchId, $entryId);

        return [
            'runId' => $runId,
            'revisionId' => $revisionId,
            'batchId' => $batchId,
            'employeeId' => $employeeId,
            'employmentId' => $employmentId,
            'inputJson' => $inputJson,
        ];
    }

    private function createSupplier(
        PDO $pdo,
        string $accountingMode,
        int $countryId,
        int $currencyId,
        int $vatRateId,
    ): int {
        $statement = $pdo->prepare(
            'INSERT INTO supplier
                (company_name, street, city, zip, country_id, email,
                 default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, ?, ?, ?, ?)',
        );
        $statement->execute([
            'Payroll Posting Reconciliation ' . bin2hex(random_bytes(4)) . ' s.r.o.',
            $countryId,
            'payroll-posting-reconciliation-' . bin2hex(random_bytes(4)) . '@example.com',
            $currencyId,
            $vatRateId,
            $accountingMode,
        ]);
        $supplierId = (int) $pdo->lastInsertId();
        if ($accountingMode === 'double_entry') {
            $this->seeder->seedForSupplier($supplierId);
            $this->periods->create(
                $supplierId,
                self::YEAR,
                self::YEAR . '-01-01',
                self::YEAR . '-12-31',
            );
        }

        return $supplierId;
    }

    private function createRun(int $month): int
    {
        $pdo = $this->db->pdo();
        $statement = $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status,
                 current_revision_no, created_by, updated_by)
             VALUES (?, ?, ?, "approved", 1, ?, ?)',
        );
        $paymentMonth = $month === 12 ? 1 : $month + 1;
        $paymentYear = $month === 12 ? self::YEAR + 1 : self::YEAR;
        $statement->execute([
            $this->supplierId,
            sprintf('%d-%02d-01', self::YEAR, $month),
            sprintf('%d-%02d-15', $paymentYear, $paymentMonth),
            $this->userId,
            $this->userId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function buildInputSnapshot(int $employeeId, int $employmentId): array
    {
        return [
            'schema_version' => 'payroll-run-input.v2',
            'people' => [[
                'employee' => ['id' => $employeeId],
                'employments' => [[
                    'employment' => ['id' => $employmentId, 'office_id' => 1],
                    'inputs' => [],
                ]],
            ]],
        ];
    }

    /**
     * @param array{debit_code:string,credit_code:string}|null $nonMonetaryAccounting
     *        vlastní předkontace nepeněžní složky; `null` = složka ji nemá
     *        a můstek ji záměrně neúčtuje
     * @return array{data:array<string,mixed>,json:string}
     */
    private function buildResultSnapshot(
        int $employeeId,
        int $employmentId,
        int $deducted,
        int $nonMonetaryMinor = 0,
        ?array $nonMonetaryAccounting = null,
        int $riskySavingsMinor = 0,
    ): array {
        $input = $this->buildInputSnapshot($employeeId, $employmentId);
        $inputJson = CanonicalJson::encode($input);
        $metrics = [
            'source_amount_minor' => 12_345 + $nonMonetaryMinor,
            'cash_payable_minor' => 12_345,
            'tax_base_minor' => 12_345,
            'social_base_minor' => 12_345,
            'health_base_minor' => 12_345,
            'average_earning_base_minor' => 12_345,
            'enforcement_base_minor' => 12_345,
            'jmhz_amount_minor' => 12_345,
        ];
        $netBeforeDeductions = 12_345 - 600 - 300 - 900;
        $netPayable = $netBeforeDeductions - $deducted;
        $inputs = [[
            'input_id' => 1,
            'accounting' => [
                'debit_code' => '521',
                'credit_code' => '331',
                'amount_minor' => 12_345,
            ],
            'totals' => [
                'source_amount_minor' => 12_345,
                'cash_payable_minor' => 12_345,
            ],
        ]];
        $accountingTotals = [[
            'debit_code' => '521',
            'credit_code' => '331',
            'amount_minor' => 12_345,
        ]];
        if ($nonMonetaryMinor > 0) {
            $inputs[] = [
                'input_id' => 2,
                'accounting' => [
                    'debit_code' => $nonMonetaryAccounting['debit_code'] ?? null,
                    'credit_code' => $nonMonetaryAccounting['credit_code'] ?? null,
                    'amount_minor' => $nonMonetaryMinor,
                ],
                'totals' => [
                    'source_amount_minor' => $nonMonetaryMinor,
                    'cash_payable_minor' => 0,
                ],
            ];
            if ($nonMonetaryAccounting !== null) {
                $accountingTotals[] = [
                    'debit_code' => $nonMonetaryAccounting['debit_code'],
                    'credit_code' => $nonMonetaryAccounting['credit_code'],
                    'amount_minor' => $nonMonetaryMinor,
                ];
            }
        }
        $result = [
            'schema_version' => 'payroll-run-result.v2',
            'source_snapshot_hash' => hash('sha256', $inputJson),
            'people' => [[
                'employee_id' => $employeeId,
                'employments' => [[
                    'employment_id' => $employmentId,
                    'inputs' => $inputs,
                    'totals' => $metrics,
                ]],
                'totals' => $metrics,
                'statutory' => [
                    'person_reference' => "employee:{$employeeId}",
                    'status' => 'calculated',
                    'social_insurance' => [
                        'employee_contribution_minor_units' => 600,
                    ],
                    'health_insurance' => [
                        'employee_contribution_minor_units' => 300,
                        'employer_contribution_minor_units' => 700,
                        'total_contribution_minor_units' => 1_000,
                    ],
                    'income_tax' => [
                        'advance_tax' => [
                            'tax_after_credits_minor_units' => 900,
                        ],
                        'withholding_tax_minor_units' => 0,
                    ],
                    'net_pay' => [
                        'person_reference' => "employee:{$employeeId}",
                        'relationships' => [[
                            'relationship_reference' => "employment:{$employmentId}",
                            'cash_income_minor_units' => 12_345,
                            'non_cash_income_minor_units' => $nonMonetaryMinor,
                        ]],
                        'cash_income_minor_units' => 12_345,
                        'non_cash_income_minor_units' => $nonMonetaryMinor,
                        'employee_social_minor_units' => 600,
                        'employee_health_minor_units' => 300,
                        'advance_tax_minor_units' => 900,
                        'withholding_tax_minor_units' => 0,
                        'tax_bonus_minor_units' => 0,
                        'correction_minor_units' => 0,
                        'net_before_deductions_minor_units' => $netBeforeDeductions,
                        'deducted_minor_units' => $deducted,
                        'net_payable_minor_units' => $netPayable,
                        'deductions' => [[
                            'applied_minor_units' => $deducted,
                            'deduction_reference' => 'test-deduction',
                        ]],
                    ],
                    'net_payable_minor_units' => $netPayable,
                ],
            ]],
            'totals' => $metrics,
            'accounting_totals' => $accountingTotals,
            'statutory' => [
                'status' => 'calculated',
                'employer_social_minor_units' => 800,
            ] + ($riskySavingsMinor > 0
                ? ['risky_savings' => [[
                    'employment_id' => $employmentId,
                    'status' => 'calculated',
                    'contribution_minor' => $riskySavingsMinor,
                ]]]
                : []),
        ];

        return ['data' => $result, 'json' => CanonicalJson::encode($result)];
    }

    private function insertRevision(
        int $runId,
        int $revisionNo,
        string $inputJson,
        string $resultJson,
        bool $approved,
        ?int $previousRevisionId = null,
        string $revisionKind = 'regular',
    ): int {
        return $this->insertRevisionFor(
            $this->supplierId,
            $runId,
            $revisionNo,
            $inputJson,
            $resultJson,
            $approved,
            $previousRevisionId,
            $revisionKind,
        );
    }

    private function insertRevisionFor(
        int $supplierId,
        int $runId,
        int $revisionNo,
        string $inputJson,
        string $resultJson,
        bool $approved,
        ?int $previousRevisionId = null,
        string $revisionKind = 'regular',
    ): int {
        $pdo = $this->db->pdo();
        $statement = $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, previous_revision_id,
                 revision_kind, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json,
                 result_snapshot_hash, idempotency_key_hash, approved_by, approved_at)
             VALUES (?, ?, ?, ?, ?, ?, "payroll-run-input.v2",
                     ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->execute([
            $supplierId,
            $runId,
            $revisionNo,
            $previousRevisionId,
            $revisionKind,
            $approved ? 'approved' : 'reviewed',
            str_repeat('a', 64),
            $inputJson,
            hash('sha256', $inputJson),
            $resultJson,
            hash('sha256', $resultJson),
            random_bytes(32),
            $approved ? $this->userId : null,
            $approved ? (self::YEAR . '-01-01 10:00:00') : null,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** @param array<string,mixed> $resultSnapshot */
    private function insertStatutoryResult(
        int $revisionId,
        string $calculationKind,
        array $resultSnapshot,
    ): void {
        $this->insertStatutoryResultFor(
            $this->supplierId,
            $revisionId,
            $calculationKind,
            $resultSnapshot,
        );
    }

    /** @param array<string,mixed> $resultSnapshot */
    private function insertStatutoryResultFor(
        int $supplierId,
        int $revisionId,
        string $calculationKind,
        array $resultSnapshot,
    ): void {
        $pdo = $this->db->pdo();
        $inputJson = CanonicalJson::encode([]);
        $resultJson = CanonicalJson::encode($resultSnapshot);
        $statement = $pdo->prepare(
            'INSERT INTO payroll_statutory_results
                (supplier_id, revision_id, calculation_kind, schema_version,
                 result_status, ruleset_id, ruleset_hash,
                 input_snapshot_json, input_snapshot_hash,
                 result_snapshot_json, result_snapshot_hash, result_set_hash, created_by)
             VALUES (?, ?, ?, "payroll-statutory-result.v1", "calculated",
                     "test-ruleset", ?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->execute([
            $supplierId,
            $revisionId,
            $calculationKind,
            str_repeat('b', 64),
            $inputJson,
            hash('sha256', $inputJson),
            $resultJson,
            hash('sha256', $resultJson),
            hash('sha256', $calculationKind . $revisionId . $supplierId),
            $this->userId,
        ]);
    }

    /**
     * PostingService::assertPayrollPostingContext (F1) vyžaduje existující
     * "prepared" dávku PŘED zápisem do deníku — dávka se proto vždy zakládá
     * dřív, než se zavolá postJournal().
     */
    private function preparePostingBatch(
        int $runId,
        int $revisionId,
        string $entryDate,
        ?int $previousBatchId,
    ): int {
        $pdo = $this->db->pdo();
        $insert = $pdo->prepare(
            'INSERT INTO payroll_posting_batches
                (supplier_id, run_id, revision_id, previous_batch_id,
                 entry_date, status, target_hash, delta_hash, created_by)
             VALUES (?, ?, ?, ?, ?, "prepared", ?, ?, ?)',
        );
        $insert->execute([
            $this->supplierId,
            $runId,
            $revisionId,
            $previousBatchId,
            $entryDate,
            hash('sha256', 'target:' . $revisionId),
            hash('sha256', 'delta:' . $revisionId),
            $this->userId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertPostingAllocation(
        int $batchId,
        string $allocationKey,
        string $accountCode,
        int $signedMinor,
    ): void {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_posting_allocations
                (supplier_id, batch_id, allocation_key, account_code,
                 signed_minor, description)
             VALUES (?, ?, ?, ?, ?, "Testovací hrubá mzda")',
        )->execute([
            $this->supplierId,
            $batchId,
            $allocationKey,
            $accountCode,
            $signedMinor,
        ]);
    }

    private function createAnalyticAccount(string $accountCode): void
    {
        $prefix = substr($accountCode, 0, 3);
        $this->db->pdo()->prepare(
            'INSERT INTO chart_of_accounts
                (supplier_id, account_code, name, account_type, normal_side,
                 is_synthetic, parent_id)
             SELECT ?, ?, ?, account_type, normal_side, 0, id
               FROM chart_of_accounts
              WHERE supplier_id = ? AND account_code = ?',
        )->execute([
            $this->supplierId,
            $accountCode,
            'Testovací analytika ' . $accountCode,
            $this->supplierId,
            $prefix,
        ]);
    }

    /**
     * @param list<array{account_code:string,side:string,amount:string,cost_center?:string}> $lines
     */
    private function postJournal(int $revisionId, string $entryDate, array $lines): int
    {
        return $this->posting->postDocument(
            $this->supplierId,
            'payroll',
            $revisionId,
            $lines,
            [
                'entry_date' => $entryDate,
                'document_no' => 'MZ-TEST-' . $revisionId,
                'description' => 'Testovací mzdový předpis revize ' . $revisionId,
                'posted_by' => $this->userId,
                'user_id' => $this->userId,
            ],
        );
    }

    private function finalizeBatch(int $batchId, int $journalEntryId): void
    {
        $update = $this->db->pdo()->prepare(
            'UPDATE payroll_posting_batches
                SET status = "posted", journal_entry_id = ?, posted_at = NOW()
              WHERE supplier_id = ? AND id = ? AND status = "prepared"',
        );
        $update->execute([$journalEntryId, $this->supplierId, $batchId]);
        self::assertSame(1, $update->rowCount());
    }

    private function createEmployee(int $employeeId): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employees
                (id, supplier_id, full_name, taxpayer_type)
             VALUES (?, ?, "Testovací zaměstnanec", "employee")
             ON DUPLICATE KEY UPDATE full_name = full_name',
        )->execute([$employeeId, $this->supplierId]);
    }

    /**
     * Vyžaduje trigger `trg_payroll_payment_liability_validate_insert` pro
     * závazek 'net_wage' s vyplněným employee_id.
     */
    private function createRunPerson(int $revisionId, int $employeeId): void
    {
        $resultJson = CanonicalJson::encode(['status' => 'calculated']);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, employee_id, result_json, result_hash, status)
             VALUES (?, ?, ?, ?, ?, "calculated")
             ON DUPLICATE KEY UPDATE status = status',
        )->execute([
            $this->supplierId,
            $revisionId,
            $employeeId,
            $resultJson,
            hash('sha256', $resultJson),
        ]);
    }

    /** @param array<string,int> $amountsByKind */
    private function materializeLiabilities(
        int $revisionId,
        int $revisionEmployeeId,
        array $amountsByKind,
    ): void {
        $pdo = $this->db->pdo();
        foreach ($amountsByKind as $kind => $amountMinor) {
            $reference = 'test-' . $kind . '-' . $revisionId;
            $employeeId = $kind === 'net_wage' ? $revisionEmployeeId : null;
            $snapshot = CanonicalJson::encode(['kind' => $kind]);
            $statement = $pdo->prepare(
                'INSERT INTO payroll_payment_liabilities
                    (supplier_id, revision_id, employee_id, liability_reference,
                     liability_kind, direction, recipient_reference, due_on,
                     currency_code, amount_minor, source_snapshot_json,
                     source_snapshot_hash, idempotency_key_hash, created_by)
                 VALUES (?, ?, ?, ?, ?, "outgoing", "test-recipient",
                         ?, "CZK", ?, ?, ?, ?, ?)',
            );
            $statement->execute([
                $this->supplierId,
                $revisionId,
                $employeeId,
                $reference,
                $kind,
                self::YEAR . '-07-15',
                $amountMinor,
                $snapshot,
                hash('sha256', $snapshot),
                random_bytes(32),
                $this->userId,
            ]);
        }
    }
}
