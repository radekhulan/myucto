<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Obligations\OtherItemForecastService;
use MyInvoice\Service\Accounting\OtherItemBankMatchService;
use MyInvoice\Service\Accounting\OtherItemException;
use MyInvoice\Service\Accounting\OtherItemPostingProfileService;
use MyInvoice\Service\Accounting\OtherItemScheduleService;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Splátky úvěru rozdělené na jistinu, úrok a poplatek (migrace 2241): validace kalendáře,
 * saldo položky jen z jistiny, úhrada z banky jedním zápisem se třemi dvojicemi řádků podle
 * profilu druhu a vlastní kontace položky, úrok a poplatek před jistinou, kontrola už
 * zaúčtované platby, daňová evidence bez účtování a předpověď s celou splátkou.
 * Syntetická firma, syntetický účet 1000000005/0100, období 2099.
 */
#[Group('integration')]
final class OtherItemLoanComponentsTest extends TestCase
{
    use IsolatedSupplierTrait;

    private \Psr\Container\ContainerInterface $container;
    private Connection $db;
    private PDO $pdo;
    private OtherItemService $items;
    private OtherItemScheduleService $schedules;
    private OtherItemBankMatchService $bankMatch;
    private OtherItemPostingProfileService $profiles;
    private ChartOfAccountsRepository $chart;
    private int $supplierId;
    private int $statementId;

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $this->items = $this->container->get(OtherItemService::class);
        $this->schedules = $this->container->get(OtherItemScheduleService::class);
        $this->bankMatch = $this->container->get(OtherItemBankMatchService::class);
        $this->profiles = $this->container->get(OtherItemPostingProfileService::class);
        $this->chart = $this->container->get(ChartOfAccountsRepository::class);
        $this->pdo = $this->db->pdo();
        $this->pdo->beginTransaction();
        $source = (int) $this->pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        self::assertGreaterThan(0, $source);
        $this->supplierId = $this->createIsolatedSupplier($this->pdo, $source);
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")
            ->execute([$this->supplierId]);
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $this->container->get(AccountingPeriodRepository::class)->create($this->supplierId, 2099, '2099-01-01', '2099-12-31');
        $this->pdo->prepare(
            'INSERT INTO bank_statements (supplier_id, file_name, file_hash, account_number, bank_code, statement_date, currency)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, 'synteticky-vypis', hash('sha256', uniqid('', true)),
            '1000000005', '0100', '2099-02-15', 'CZK']);
        $this->statementId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
        if (isset($this->db)) $this->db->close();
    }

    public function testInstallmentComponentsMustAddUpAndPrincipalMustMatchItem(): void
    {
        $item = $this->loan();
        $cases = [
            'installment_components_total' => [
                ['due_on' => '2099-02-15', 'amount' => 5120, 'principal_amount' => 5000, 'interest_amount' => 100, 'fee_amount' => 10],
                ['due_on' => '2099-03-15', 'amount' => 5070, 'principal_amount' => 5000, 'interest_amount' => 50, 'fee_amount' => 20],
            ],
            'installment_total' => [
                ['due_on' => '2099-02-15', 'principal_amount' => 5000, 'interest_amount' => 100, 'fee_amount' => 20],
                ['due_on' => '2099-03-15', 'principal_amount' => 4000, 'interest_amount' => 50, 'fee_amount' => 20],
            ],
            'invalid_installment_component' => [
                ['due_on' => '2099-02-15', 'principal_amount' => 5000, 'interest_amount' => -1],
                ['due_on' => '2099-03-15', 'principal_amount' => 5001],
            ],
        ];
        foreach ($cases as $code => $rows) {
            try {
                $this->schedules->setInstallments($this->supplierId, $item, $rows);
                self::fail('Kalendář ' . $code . ' musí být odmítnut.');
            } catch (OtherItemException $e) {
                self::assertSame($code, $e->errorCode);
            }
        }
        // Bez částky se splátka dopočítá ze složek; chybějící složka je 0.
        $saved = $this->schedules->setInstallments($this->supplierId, $item, [
            ['due_on' => '2099-02-15', 'principal_amount' => 5000, 'interest_amount' => 100, 'fee_amount' => 20],
            ['due_on' => '2099-03-15', 'principal_amount' => 5000, 'interest_amount' => 50],
        ]);
        self::assertSame([5120.0, 5050.0], array_column($saved, 'amount'));
        self::assertSame([20.0, 0.0], array_column($saved, 'fee_amount'));
        self::assertSame([5120.0, 5050.0], array_column($saved, 'remaining_amount'));

        // Nerozdělený kalendář funguje beze změny: součet splátek je částka dokladu.
        $plain = $this->schedules->setInstallments($this->supplierId, $item, [
            ['due_on' => '2099-02-15', 'amount' => 6000], ['due_on' => '2099-03-15', 'amount' => 4000],
        ]);
        self::assertSame([null, null], array_column($plain, 'principal_amount'));
        self::assertFalse($this->items->get($this->supplierId, $item)['has_split_installments']);
    }

    public function testBankPaymentOfSplitInstallmentPostsOneEntryWithThreePairsAndReducesPrincipalOnly(): void
    {
        $item = $this->loanWithSchedule();
        $tx = $this->transaction(-5120);
        $this->bankMatch->match($this->supplierId, $tx, $item, null, [], true);

        self::assertSame([
            '461:debit:5000.00', '221:credit:5000.00',
            '562:debit:100.00', '221:credit:100.00',
            '568:debit:20.00', '221:credit:20.00',
        ], $this->bankLines($tx));
        self::assertSame(1, $this->bankEntryCount($tx), 'Jedna platba, jeden zápis.');

        $detail = $this->items->get($this->supplierId, $item);
        self::assertEqualsWithDelta(5000.0, (float) $detail['remaining_amount'], 0.001, 'Saldo snižuje jen jistina.');
        self::assertEqualsWithDelta(5070.0, (float) $detail['remaining_payment'], 0.001, 'Zbývá zaplatit celá druhá splátka.');
        $allocation = $this->items->allocations($this->supplierId, $item)[0];
        self::assertSame(['5000.00', '100.00', '20.00', '5120.00'],
            [$allocation['amount'], $allocation['interest_amount'], $allocation['fee_amount'], $allocation['total_amount']]);
        $coverage = $this->items->installmentCoverage($this->supplierId, $item);
        self::assertSame(['paid', 'unpaid'], array_column($coverage, 'status'));
        self::assertSame([0.0, 5070.0], array_column($coverage, 'remaining_amount'));
        self::assertSame([0.0, 5000.0], array_column($coverage, 'remaining_principal'));
        self::assertSame(0.0, $this->freeAmount($tx), 'Pohyb je vyčerpaný celou peněžní částkou.');
        self::assertEqualsWithDelta(5000.0, $this->ledgerBalance('461'), 0.001, 'Účet položky souhlasí se saldem.');
    }

    public function testKindProfileAndItemOverrideChooseInterestAndFeeAccounts(): void
    {
        $this->analytic('562', '562.100', 'expense');
        $this->analytic('568', '568.100', 'expense');
        $this->profiles->saveForKind($this->supplierId, null, 'payable', 'loan', [
            'interest' => ['debit_account_code' => '562.100', 'description_template' => 'Úrok {document_no}'],
            'fee' => ['debit_account_code' => '568'],
        ], null);
        $item = $this->loanWithSchedule();
        $this->items->setPostingOverrides($this->supplierId, $item, ['fee' => ['debit_account_code' => '568.100']], null);
        $rules = $this->items->get($this->supplierId, $item)['posting_rules'];
        self::assertSame(['kind', '562.100'], [$rules->interest['source'], $rules->interest['account_code']]);
        self::assertSame(['item', '568.100'], [$rules->fee['source'], $rules->fee['account_code']]);

        $tx = $this->transaction(-5120);
        $this->bankMatch->match($this->supplierId, $tx, $item, null, [], true);
        self::assertSame([
            '461:debit:5000.00', '221:credit:5000.00',
            '562.100:debit:100.00', '221:credit:100.00',
            '568.100:debit:20.00', '221:credit:20.00',
        ], $this->bankLines($tx, false));

        foreach ([
            'component_money_account' => ['interest' => ['credit_account_code' => '221']],
            'invalid_component_account' => ['interest' => ['debit_account_code' => '662']],
        ] as $code => $input) {
            try {
                $this->profiles->saveForKind($this->supplierId, null, 'payable', 'loan', $input, null);
                self::fail('Profil ' . $code . ' musí být odmítnut.');
            } catch (OtherItemException $e) {
                self::assertSame($code, $e->errorCode);
            }
        }
    }

    public function testPartialPaymentCoversInterestAndFeeBeforePrincipalAndLocksCalendar(): void
    {
        $item = $this->loanWithSchedule();
        $tx = $this->transaction(-110);
        $this->bankMatch->match($this->supplierId, $tx, $item, null, [], true);
        self::assertSame(['562:debit:100.00', '221:credit:100.00', '568:debit:10.00', '221:credit:10.00'], $this->bankLines($tx));
        $allocation = $this->items->allocations($this->supplierId, $item)[0];
        self::assertSame(['0.00', '100.00', '10.00'], [$allocation['amount'], $allocation['interest_amount'], $allocation['fee_amount']]);
        self::assertEqualsWithDelta(10000.0, (float) $this->items->get($this->supplierId, $item)['remaining_amount'], 0.001);
        $first = $this->items->installmentCoverage($this->supplierId, $item)[0];
        self::assertSame([0.0, 10.0, 5000.0, 5010.0], [$first['remaining_interest'], $first['remaining_fee'],
            $first['remaining_principal'], $first['remaining_amount']]);
        try {
            $this->items->reverse($this->supplierId, $item, 'Syntetické storno', null, '2099-02-20');
            self::fail('Úhrada samotného úroku brání stornu stejně jako jiná úhrada.');
        } catch (OtherItemException $e) {
            self::assertSame('has_payments', $e->errorCode);
        }

        // Další platba doplatí poplatek a jistinu, přebytek jde na úrok další splátky.
        $tx2 = $this->transaction(-5040);
        $this->bankMatch->match($this->supplierId, $tx2, $item, null, [], true);
        self::assertSame(['461:debit:5000.00', '221:credit:5000.00', '562:debit:30.00', '221:credit:30.00', '568:debit:10.00', '221:credit:10.00'],
            $this->sortedPairs($this->bankLines($tx2)));
        $coverage = $this->items->installmentCoverage($this->supplierId, $item);
        self::assertSame(['paid', 'partial'], array_column($coverage, 'status'));
        self::assertSame([20.0, 5040.0], [$coverage[1]['remaining_interest'], $coverage[1]['remaining_amount']]);

        try {
            $this->schedules->setInstallments($this->supplierId, $item, [
                ['due_on' => '2099-02-15', 'amount' => 5000], ['due_on' => '2099-03-15', 'amount' => 5000],
            ]);
            self::fail('Kalendář s úhradou je zamčený.');
        } catch (OtherItemException $e) {
            self::assertSame('invalid_status', $e->errorCode);
        }
    }

    public function testAlreadyPostedPaymentMustMatchSplitAndOverpaymentIsRejected(): void
    {
        $item = $this->loanWithSchedule();
        $bankPosting = $this->container->get(BankPostingService::class);
        $wrong = $this->transaction(-5120);
        $bankPosting->postManual($this->supplierId, $wrong, ['debit_account_code' => '461', 'credit_account_code' => '221'], []);
        try {
            $this->items->allocate($this->supplierId, $item, ['bank_transaction_id' => $wrong, 'amount' => 5120], null);
            self::fail('Celá platba na účtu položky neodpovídá rozdělení splátky.');
        } catch (OtherItemException $e) {
            self::assertSame('payment_split_mismatch', $e->errorCode);
            self::assertStringContainsString('562', $e->getMessage());
        }

        $right = $this->transaction(-5120);
        $bankPosting->postManual($this->supplierId, $right, ['lines' => [
            ['account_code' => '461', 'side' => 'debit', 'amount' => 5000],
            ['account_code' => '562', 'side' => 'debit', 'amount' => 100],
            ['account_code' => '568', 'side' => 'debit', 'amount' => 20],
            ['account_code' => '221', 'side' => 'credit', 'amount' => 5120],
        ]], []);
        $this->items->allocate($this->supplierId, $item, ['bank_transaction_id' => $right, 'amount' => 5120], null);
        self::assertEqualsWithDelta(5000.0, (float) $this->items->get($this->supplierId, $item)['remaining_amount'], 0.001);

        $over = $this->transaction(-6000);
        $bankPosting->postManual($this->supplierId, $over, ['debit_account_code' => '461', 'credit_account_code' => '221'], []);
        try {
            $this->items->allocate($this->supplierId, $item, ['bank_transaction_id' => $over, 'amount' => 5070.01], null);
            self::fail('Přeplatek se odmítá.');
        } catch (OtherItemException $e) {
            self::assertSame('overpayment', $e->errorCode);
        }
    }

    public function testUnsplitInstallmentsBehaveExactlyAsBefore(): void
    {
        $item = $this->loan(['amount' => 1200, 'account_code' => null, 'kind' => 'rent', 'counter_account_code' => '518']);
        $this->schedules->setInstallments($this->supplierId, $item, [
            ['due_on' => '2099-02-15', 'amount' => 600], ['due_on' => '2099-03-15', 'amount' => 600],
        ]);
        $tx = $this->transaction(-600);
        $this->bankMatch->match($this->supplierId, $tx, $item, null, [], true);
        self::assertSame(['325:debit:600.00', '221:credit:600.00'], $this->bankLines($tx));
        $detail = $this->items->get($this->supplierId, $item);
        self::assertSame([600.0, 600.0], [(float) $detail['remaining_amount'], (float) $detail['remaining_payment']]);
        self::assertSame(['0.00', '0.00'], [$this->items->allocations($this->supplierId, $item)[0]['interest_amount'],
            $this->items->allocations($this->supplierId, $item)[0]['fee_amount']]);
        self::assertSame(['paid', 'unpaid'], array_column($this->items->installmentCoverage($this->supplierId, $item), 'status'));
    }

    public function testForecastShowsFullInstallmentAmount(): void
    {
        $item = $this->loanWithSchedule();
        $rows = array_values(array_filter(
            $this->container->get(OtherItemForecastService::class)->dueBetween($this->supplierId, '2099-01-01', '2099-12-31'),
            static fn (array $row): bool => $row['id'] === $item));
        self::assertSame([5120.0, 5070.0], array_column($rows, 'remaining'));
    }

    public function testTaxEvidenceStoresComponentsButPostsNothing(): void
    {
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = ?")->execute([$this->supplierId]);
        $item = $this->loanWithSchedule();
        self::assertSame('confirmed', $this->items->get($this->supplierId, $item)['status']);
        self::assertSame([], (array) $this->items->get($this->supplierId, $item)['posting_rules']);
        $tx = $this->transaction(-5120);
        $this->bankMatch->match($this->supplierId, $tx, $item, null, [], true);
        self::assertSame(0, $this->bankEntryCount($tx), 'Daňová evidence pohyb neúčtuje.');
        $allocation = $this->items->allocations($this->supplierId, $item)[0];
        self::assertSame(['5000.00', '100.00', '20.00'], [$allocation['amount'], $allocation['interest_amount'], $allocation['fee_amount']]);
        self::assertEqualsWithDelta(5000.0, (float) $this->items->get($this->supplierId, $item)['remaining_amount'], 0.001);
    }

    public function testLoanPlanIsGeneratedForTheItem(): void
    {
        $item = $this->loan();
        $plan = $this->schedules->planLoan($this->supplierId, $item, [
            'annual_rate' => 12, 'count' => 2, 'first_due_on' => '2099-02-15', 'frequency' => 'monthly', 'method' => 'annuity', 'fee' => 20,
        ]);
        self::assertSame(10000.0, round(array_sum(array_column($plan, 'principal_amount')), 2));
        self::assertSame(['2099-02-15', '2099-03-15'], array_column($plan, 'due_on'));
        $saved = $this->schedules->setInstallments($this->supplierId, $item, $plan);
        self::assertSame(array_column($plan, 'amount'), array_column($saved, 'amount'));
    }

    /** @param array<string,mixed> $changes */
    private function loan(array $changes = []): int
    {
        $draft = $this->items->create($this->supplierId, array_replace([
            'side' => 'payable', 'kind' => 'loan', 'title' => 'Syntetický úvěr',
            'issued_on' => '2099-01-01', 'accounting_on' => '2099-01-01', 'due_on' => '2099-03-15',
            'currency' => 'CZK', 'amount' => 10000, 'account_code' => '461', 'counter_account_code' => '261',
        ], $changes), null);
        $this->items->post($this->supplierId, (int) $draft['id'], null);
        return (int) $draft['id'];
    }

    private function loanWithSchedule(): int
    {
        $item = $this->loan();
        $this->schedules->setInstallments($this->supplierId, $item, [
            ['due_on' => '2099-02-15', 'amount' => 5120, 'principal_amount' => 5000, 'interest_amount' => 100, 'fee_amount' => 20],
            ['due_on' => '2099-03-15', 'amount' => 5070, 'principal_amount' => 5000, 'interest_amount' => 50, 'fee_amount' => 20],
        ]);
        return $item;
    }

    private function analytic(string $parent, string $code, string $type): void
    {
        $this->chart->insert($this->supplierId, ['account_code' => $code, 'name' => 'Syntetická analytika ' . $code,
            'account_type' => $type, 'normal_side' => 'debit', 'is_synthetic' => false,
            'parent_id' => (int) $this->chart->findByCode($this->supplierId, $parent)['id']]);
    }

    private function transaction(float $amount): int
    {
        $this->pdo->prepare(
            'INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, match_status, counterparty_name, description)
             VALUES (?, ?, ?, ?, "unmatched", "Syntetický věřitel", "Syntetická splátka")'
        )->execute([$this->statementId, '2099-02-15', $amount, 'CZK']);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<string> řádky živého bankovního zápisu „účet:strana:částka" */
    private function bankLines(int $txId, bool $synthetic = true): array
    {
        $code = $synthetic ? "SUBSTRING_INDEX(a.account_code, '.', 1)" : "IF(a.account_code LIKE '221%', '221', a.account_code)";
        $stmt = $this->pdo->prepare(
            "SELECT CONCAT({$code}, ':', l.side, ':', CAST(l.amount AS CHAR))
               FROM journal_entries e
               JOIN journal_entry_lines l ON l.entry_id = e.id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE e.supplier_id = ? AND e.source_type = 'bank' AND e.source_id = ? AND e.reversed_by IS NULL
              ORDER BY l.id"
        );
        $stmt->execute([$this->supplierId, $txId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private function sortedPairs(array $lines): array
    {
        $pairs = array_chunk($lines, 2);
        usort($pairs, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
        return array_merge(...$pairs);
    }

    private function bankEntryCount(int $txId): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM journal_entries
            WHERE supplier_id = ? AND source_type = 'bank' AND source_id = ? AND reversed_by IS NULL");
        $stmt->execute([$this->supplierId, $txId]);
        return (int) $stmt->fetchColumn();
    }

    private function freeAmount(int $txId): float
    {
        $stmt = $this->pdo->prepare('SELECT ABS(bt.amount) - COALESCE(SUM(a.amount + a.interest_amount + a.fee_amount), 0)
            FROM bank_transactions bt LEFT JOIN other_item_allocations a ON a.bank_transaction_id = bt.id AND a.reversed_on IS NULL
            WHERE bt.id = ? GROUP BY bt.id');
        $stmt->execute([$txId]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    private function ledgerBalance(string $code): float
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'credit' THEN l.amount ELSE -l.amount END), 0)
               FROM journal_entries e
               JOIN journal_entry_lines l ON l.entry_id = e.id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE e.supplier_id = ? AND e.posted_at IS NOT NULL AND a.account_code = ?"
        );
        $stmt->execute([$this->supplierId, $code]);
        return (float) $stmt->fetchColumn();
    }
}
