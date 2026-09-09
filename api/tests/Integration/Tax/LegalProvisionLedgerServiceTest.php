<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Tax\Return\LegalProvisionLedgerService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Podklad tabulky C přílohy č. 1 II. oddílu DPPO (VetaG) nad SKUTEČNÝM deníkem:
 * zákonné OP k pohledávkám (558/391) rozpadlé podle paragrafu ZoR, zákonná rezerva
 * na opravy hmotného majetku (552/451) a odpis pohledávky (546, § 24/2/y).
 *
 * Klíčová vlastnost, kterou test hlídá: paragraf (§8a vs §8c) NELZE odvodit z hlavní
 * knihy — kontace je pro všechny stejná — takže se musí propsat z uzávěrkového kroku
 * `provisions`. Bez něj zůstane rozpad nezařazený a builder varuje místo odhadu.
 *
 * Izolovaný supplier v transakci s rollbackem (vzor ClosingProvisionsIncomeTaxTest).
 */
#[Group('integration')]
final class LegalProvisionLedgerServiceTest extends TestCase
{
    private const YEAR = 2098;
    private const STARTS_ON = self::YEAR . '-01-01';
    private const ENDS_ON = self::YEAR . '-12-31';

    private Connection $db;
    private PostingService $posting;
    private ClosingService $closing;
    private AccountingPeriodRepository $periods;
    private LegalProvisionLedgerService $service;

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
            $container = Bootstrap::buildApp()->getContainer();
            $this->db      = $container->get(Connection::class);
            $this->posting = $container->get(PostingService::class);
            $this->closing = $container->get(ClosingService::class);
            $this->periods = $container->get(AccountingPeriodRepository::class);
            $this->service = $container->get(LegalProvisionLedgerService::class);
            $seeder        = $container->get(ChartOfAccountsSeeder::class);
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
        $stmt->execute(['VetaG test s.r.o.', $this->czId, 'vetag@example.com', $this->currencyId, $vatRateId]);
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

    private function forPeriod(): array
    {
        return $this->service->forPeriod($this->supplierId, $this->periodId, self::STARTS_ON, self::ENDS_ON);
    }

    public function testEmptyLedgerHasNoActivity(): void
    {
        $data = $this->forPeriod();
        self::assertFalse($data['has_activity']);
        self::assertSame(0.0, $data['allowance_balance']);
        self::assertSame(0.0, $data['legal_reserve_balance']);
    }

    public function testLegalAllowanceSplitsBySectionFromClosingStep(): void
    {
        // §8a: 50 000 Kč, 20 měsíců po splatnosti; §8c: drobná pohledávka 20 000 Kč.
        $inv8a = $this->receivable(50000.00, self::YEAR . '-02-01', '2097-04-30');
        $inv8c = $this->receivable(20000.00, self::YEAR . '-02-01', '2097-11-30');
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        $this->closing->runProvisions($this->supplierId, $this->periodId, [
            ['invoice_id' => $inv8a, 'legal_amount' => 25000.00, 'acct_amount' => 0.0, 'legal_section' => '8a'],
            ['invoice_id' => $inv8c, 'legal_amount' => 20000.00, 'acct_amount' => 0.0, 'legal_section' => '8c'],
        ], $this->rv(), $this->meta());

        $data = $this->forPeriod();
        self::assertTrue($data['has_activity']);
        self::assertEqualsWithDelta(45000.00, $data['allowance_balance'], 0.001);
        self::assertEqualsWithDelta(45000.00, $data['legal_allowance_created'], 0.001);
        self::assertEqualsWithDelta(25000.00, $data['allowance_by_section']['8a'], 0.001);
        self::assertEqualsWithDelta(20000.00, $data['allowance_by_section']['8c'], 0.001);
        self::assertSame(0.0, $data['allowance_unassigned']);
        self::assertTrue($data['allowance_split_reliable']);
        self::assertTrue($data['allowance_created_split_reliable']);
    }

    /**
     * Paragraf z hlavní knihy odvodit nejde — bez volby účetní zůstane částka
     * nezařazená a rozpad se označí za nepoužitelný (builder pak varuje).
     */
    public function testLegalAllowanceWithoutSectionStaysUnassigned(): void
    {
        $invId = $this->receivable(50000.00, self::YEAR . '-02-01', '2097-04-30');
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        $this->closing->runProvisions($this->supplierId, $this->periodId, [
            ['invoice_id' => $invId, 'legal_amount' => 25000.00, 'acct_amount' => 0.0],
        ], $this->rv(), $this->meta());

        $data = $this->forPeriod();
        self::assertEqualsWithDelta(25000.00, $data['allowance_unassigned'], 0.001);
        self::assertSame(0.0, $data['allowance_by_section']['8a']);
        self::assertFalse($data['allowance_split_reliable']);
    }

    /** Účetní OP (559) do tabulky C nepatří, ale zůstatek 391 zvyšuje — musí se evidovat zvlášť. */
    public function testAccountingAllowanceIsTrackedSeparately(): void
    {
        $invId = $this->receivable(50000.00, self::YEAR . '-02-01', '2097-04-30');
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        $this->closing->runProvisions($this->supplierId, $this->periodId, [
            ['invoice_id' => $invId, 'legal_amount' => 25000.00, 'acct_amount' => 25000.00, 'legal_section' => '8a'],
        ], $this->rv(), $this->meta());

        $data = $this->forPeriod();
        self::assertEqualsWithDelta(50000.00, $data['allowance_balance'], 0.001);
        self::assertEqualsWithDelta(25000.00, $data['allowance_declared_acct'], 0.001);
        self::assertEqualsWithDelta(25000.00, $data['acct_allowance_created'], 0.001);
        self::assertEqualsWithDelta(25000.00, $data['allowance_by_section']['8a'], 0.001);
        self::assertTrue($data['allowance_split_reliable']);
    }

    /** Zákonná rezerva na opravy HM (ZoR §7) = 552/451; čerpání tvorbu snižuje, ne do minusu. */
    public function testLegalRepairReserveBalanceAndCreation(): void
    {
        $this->manualEntry(self::YEAR . '-06-30', '552', '451', 100000.00);
        $this->manualEntry(self::YEAR . '-11-30', '451', '552', 30000.00);

        $data = $this->forPeriod();
        self::assertEqualsWithDelta(70000.00, $data['legal_reserve_balance'], 0.001);
        self::assertEqualsWithDelta(70000.00, $data['legal_reserve_created'], 0.001);
        self::assertTrue($data['has_activity']);
    }

    public function testReserveReleaseAloneNeverProducesNegativeCreation(): void
    {
        $this->manualEntry(self::YEAR . '-11-30', '451', '552', 30000.00);

        $data = $this->forPeriod();
        self::assertSame(0.0, $data['legal_reserve_created'], 'řádky tvorby tabulky C nesmí být záporné');
    }

    /**
     * Uzavírací zápis nesmí zůstatky 391 a 451 vynulovat.
     *
     * `ClosingEntryBuilder` k rozvahovému dni převádí každý rozvahový účet proti 702,
     * takže „zůstatek k ends_on" počítaný naivně vyjde po uzavření knih nula — a přiznání
     * se sestavuje právě nad uzavřeným obdobím. Bez vyloučení uzavíracího zápisu by tabulka
     * C zůstala prázdná, u rezerv dokonce nekonzistentní: ř. 25 (tvorba z obratu 552) bez
     * ř. 26 (stav). Nákladová strana ({@see LegalProvisionLedgerService::expenseCreated})
     * ten predikát měla od začátku, rozvahová ne.
     */
    public function testClosingEntryDoesNotWipeBalances(): void
    {
        $invId = $this->receivable(50000.00, self::YEAR . '-02-01', '2097-04-30');
        $this->manualEntry(self::YEAR . '-06-30', '552', '451', 30000.00);
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->runProvisions($this->supplierId, $this->periodId, [
            ['invoice_id' => $invId, 'legal_amount' => 45000.00, 'acct_amount' => 0.0, 'legal_section' => '8a'],
        ], $this->rv(), $this->meta());

        $before = $this->forPeriod();
        self::assertEqualsWithDelta(45000.00, $before['allowance_balance'], 0.001);
        self::assertEqualsWithDelta(30000.00, $before['legal_reserve_balance'], 0.001);

        // Uzavírací zápis v podobě, v jaké ho staví close_books: source_type 'closing',
        // source_id = plain period_id, entry_date = rozvahový den, rozvahové účty proti 702.
        // Zapisuje se přímo do deníku, protože období je po `start()` ve stavu `closing`
        // a `PostingService` do něj běžnou cestou účtovat odmítne (§ 35 ZoÚ) — close_books
        // má vlastní privilegovanou cestu. Pro tenhle test je podstatný tvar řádků, ne cesta.
        $this->closingJournalEntry([
            ['391', 'debit', 45000.00],
            ['451', 'debit', 30000.00],
            ['702', 'credit', 75000.00],
        ]);

        $after = $this->forPeriod();
        self::assertEqualsWithDelta(45000.00, $after['allowance_balance'], 0.001, 'uzavírací zápis nesmí zůstatek 391 vynulovat');
        self::assertEqualsWithDelta(30000.00, $after['legal_reserve_balance'], 0.001, 'uzavírací zápis nesmí zůstatek 451 vynulovat');
        self::assertTrue($after['allowance_split_reliable'], 'rozpad podle paragrafu musí zůstat použitelný i po uzavření knih');
        self::assertTrue($after['has_activity']);
    }

    /** Odpis pohledávky (546) je ř. 12 tabulky C — § 24 odst. 2 písm. y) ZDP. */
    public function testReceivableWriteOffFeedsRow12(): void
    {
        $this->manualEntry(self::YEAR . '-09-30', '546', '311', 33000.00);

        $data = $this->forPeriod();
        self::assertEqualsWithDelta(33000.00, $data['receivable_writeoff_deductible'], 0.001);
    }

    // ── pomocné ──────────────────────────────────────────────────────────────

    private function rv(): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT row_version FROM accounting_periods WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$this->periodId, $this->supplierId]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,mixed> */
    private function meta(): array
    {
        return ['user_id' => $this->userId, 'posted_by' => $this->userId];
    }

    /**
     * Zápis se `source_type = 'closing'` a `source_id = period_id` — tvar, jaký v deníku
     * zanechá close_books. Vkládá se přímo, protože běžná cesta `PostingService` do období
     * ve stavu `closing` neúčtuje.
     *
     * @param list<array{0:string,1:string,2:float}> $lines [účet, strana, částka]
     */
    private function closingJournalEntry(array $lines): void
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO journal_entries (supplier_id, period_id, entry_date, description, source_type, source_id, posted_at, posted_by)
             VALUES (?, ?, ?, ?, "closing", ?, NOW(), ?)'
        );
        $stmt->execute([
            $this->supplierId,
            $this->periodId,
            self::ENDS_ON,
            'Uzavření účetních knih',
            $this->periodId,
            $this->userId,
        ]);
        $entryId = (int) $pdo->lastInsertId();

        $account = $pdo->prepare('SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ? LIMIT 1');
        $insert = $pdo->prepare(
            'INSERT INTO journal_entry_lines (entry_id, supplier_id, account_id, side, amount, line_no)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($lines as $index => [$code, $side, $amount]) {
            $account->execute([$this->supplierId, $code]);
            $accountId = (int) $account->fetchColumn();
            self::assertGreaterThan(0, $accountId, "účet {$code} musí být v osnově");
            $insert->execute([$entryId, $this->supplierId, $accountId, $side, $amount, $index + 1]);
        }
    }

    private function manualEntry(string $date, string $debit, string $credit, float $amount): void
    {
        $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => $debit, 'side' => 'debit', 'amount' => $amount],
            ['account_code' => $credit, 'side' => 'credit', 'amount' => $amount],
        ], ['entry_date' => $date, 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
    }

    private function receivable(float $total, string $issue, string $due): int
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
             VALUES (?, ?, "Ulice 1", "Praha", "11000", ?, ?, ?)'
        );
        $stmt->execute([$this->supplierId, 'Odběratel ' . uniqid(), $this->czId, 'c' . uniqid() . '@example.com', $this->currencyId]);
        $clientId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            'INSERT INTO invoices (supplier_id, varsymbol, client_id, issue_date, due_date, currency_id, created_by, total_with_vat, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, "issued")'
        );
        $stmt->execute([$this->supplierId, (string) random_int(1000000000, 1999999999), $clientId, $issue, $due, $this->currencyId, $this->userId, $total]);
        $invId = (int) $pdo->lastInsertId();

        $this->posting->postDocument($this->supplierId, 'invoice', $invId, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => $total],
            ['account_code' => '602', 'side' => 'credit', 'amount' => $total],
        ], ['entry_date' => $issue, 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        return $invId;
    }
}
