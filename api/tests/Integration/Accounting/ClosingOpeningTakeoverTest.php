<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ClosingRepository;
use MyInvoice\Service\Accounting\Activation\OpeningBalanceService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingException;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\PostingService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Převzaté počáteční stavy v uzávěrce: následující rok už má otevírací zápis, který
 * nevznikl otevřením roku v průvodci (převod z jiného systému, ruční zadání při
 * zahájení účetnictví). Otevření roku ho nesmí zdvojit, při shodě ho převezme,
 * při rozdílu blokuje a nahradit ho jde jen výslovně s důvodem. Revert kroku
 * převzatý zápis nesmaže.
 *
 * Syntetický scénář (vzor ClosingWorkflowTest): výnos 602 10 000, náklad 518 4 000,
 * konečné stavy 311 = 7 100, 221 = 5 000, 343 = −1 260, 321 = −4 840, VH 6 000.
 * Vše běží v jedné transakci, tearDown ji vrátí.
 */
#[Group('integration')]
final class ClosingOpeningTakeoverTest extends TestCase
{
    private const YEAR = 2096;
    private const ENDS_ON = self::YEAR . '-12-31';
    private const NEXT_START = (self::YEAR + 1) . '-01-01';

    private ContainerInterface $container;
    private Connection $db;
    private PostingService $posting;
    private ClosingService $closing;
    private ClosingRepository $closingRepo;
    private AccountingPeriodRepository $periods;
    private ChartOfAccountsSeeder $seeder;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private int $czId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $this->container = Bootstrap::buildApp()->getContainer();
            $this->db = $this->container->get(Connection::class);
            $this->posting = $this->container->get(PostingService::class);
            $this->closing = $this->container->get(ClosingService::class);
            $this->closingRepo = $this->container->get(ClosingRepository::class);
            $this->periods = $this->container->get(AccountingPeriodRepository::class);
            $this->seeder = $this->container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $this->supplierId = $this->createSupplier('Převzaté PS test s.r.o.', 'opening-takeover@example.com');
        $this->seeder->seedForSupplier($this->supplierId);
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

    /**
     * Red-first: převzatý zápis bez source_id. Dřívější openNext ho hledal podle klíče
     * ('opening', id dalšího období), nenašel ho a založil druhý — rozvaha N+1 se zdvojila.
     */
    public function testOpenNextDoesNotDuplicateTakenOverOpening(): void
    {
        $nextId = $this->closeYearWithNextPeriod();
        $foreignId = $this->postForeignOpening($nextId, $this->matchingOpeningLines(), null);

        $result = $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        self::assertSame([$foreignId], $this->openingEntryIds($nextId), 'V dalším roce zůstává jediný otevírací zápis — převzatý.');
        self::assertSame(self::cents(7100.00), self::cents($this->openingBalance($nextId, '311')), 'Počáteční stav 311 není zdvojený.');
        self::assertSame('taken_over', $result['opening_source'] ?? null);
        self::assertNull($result['entry_id'], 'Otevírací zápis se neúčtoval.');
        self::assertSame([$foreignId], $result['taken_over_entry_ids'] ?? null);

        $state = $this->closing->state($this->supplierId, $this->periodId);
        self::assertSame('done', $this->stepStatus($state, 'open_next'), 'Krok open_next je hotový.');
        self::assertSame(1, $this->auditCount('accounting.books_opened'));
    }

    public function testStateReportsTakenOverOpeningBeforeOpening(): void
    {
        $nextId = $this->closeYearWithNextPeriod();
        $state = $this->closing->state($this->supplierId, $this->periodId);
        self::assertSame('to_create', $state['opening_takeover']['status'] ?? null, 'Bez převzatého zápisu je otevírací zápis k založení.');

        $this->postForeignOpening($nextId, $this->matchingOpeningLines(), null);
        $state = $this->closing->state($this->supplierId, $this->periodId);
        self::assertSame('match', $state['opening_takeover']['status'] ?? null);
        self::assertSame([], $state['opening_takeover']['diff']);
        self::assertCount(1, $state['opening_takeover']['entries']);
    }

    public function testMismatchBlocksOpenNextWithDiffTable(): void
    {
        $nextId = $this->closeYearWithNextPeriod();
        $foreignId = $this->postForeignOpening($nextId, $this->mismatchingOpeningLines(), null);

        try {
            $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());
            self::fail('Rozdíl proti převzatým počátečním stavům musí otevření roku zablokovat.');
        } catch (ClosingException $e) {
            self::assertSame('opening_takeover_mismatch', $e->errorCode);
            self::assertSame(422, $e->httpStatus, 'Ne 409 — to průvodce čte jako souběžnou změnu a jen znovu načte stav.');
        }

        self::assertSame([$foreignId], $this->openingEntryIds($nextId), 'Nic se nezaúčtovalo ani nesmazalo.');
        $state = $this->closing->state($this->supplierId, $this->periodId);
        self::assertSame('pending', $this->stepStatus($state, 'open_next'));
        self::assertSame('mismatch', $state['opening_takeover']['status']);

        $diff = [];
        foreach ($state['opening_takeover']['diff'] as $row) {
            $diff[$row['account_code']] = [self::cents($row['expected']), self::cents($row['existing']), self::cents($row['difference'])];
        }
        self::assertSame([
            '311' => [self::cents(7100.00), self::cents(7000.00), self::cents(100.00)],
            '431' => [self::cents(-6000.00), self::cents(-5900.00), self::cents(-100.00)],
        ], $diff, 'Tabulka rozdílů: účet po účtu, 70x vynechané.');
    }

    public function testExplicitReplaceSwapsTakenOverOpeningAndAudits(): void
    {
        $nextId = $this->closeYearWithNextPeriod();
        $foreignId = $this->postForeignOpening($nextId, $this->mismatchingOpeningLines(), null);

        $reason = 'Převzaté stavy obsahovaly chybu v saldokontu, platí vypočtené.';
        $result = $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta(), $reason);

        $ids = $this->openingEntryIds($nextId);
        self::assertCount(1, $ids, 'Po náhradě je v dalším roce jediný otevírací zápis.');
        self::assertNotSame($foreignId, $ids[0], 'Převzatý zápis je nahrazený vypočteným.');
        self::assertSame($ids[0], $result['entry_id']);
        self::assertSame('replaced', $result['opening_source']);
        self::assertSame($reason, $result['replace_reason']);
        self::assertSame(self::cents(7100.00), self::cents($this->openingBalance($nextId, '311')));

        $audit = $this->db->pdo()->prepare(
            "SELECT payload FROM activity_log WHERE supplier_id = ? AND action = 'accounting.opening_takeover_replaced'"
        );
        $audit->execute([$this->supplierId]);
        $rows = $audit->fetchAll(PDO::FETCH_COLUMN);
        self::assertCount(1, $rows, 'Náhrada má auditní událost.');
        $payload = json_decode((string) $rows[0], true);
        self::assertSame($reason, $payload['reason'] ?? null);
        self::assertSame($foreignId, (int) ($payload['entry_dump'][0]['entry']['id'] ?? 0), 'Audit nese dump nahrazeného zápisu.');
        self::assertNotEmpty($payload['diff'] ?? []);
    }

    public function testBlankReasonDoesNotReplace(): void
    {
        $nextId = $this->closeYearWithNextPeriod();
        $this->postForeignOpening($nextId, $this->mismatchingOpeningLines(), null);

        $this->expectException(ClosingException::class);
        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta(), '   ');
    }

    /**
     * Převzatý zápis se stejným klíčem jako vypočtený (ruční otevírací rozvaha při
     * zahájení účetnictví nese ('opening', id období)). Revert dřív mazal podle klíče,
     * takže by ho smazal; teď zůstává a jde vzít zpět i uzavření knih.
     */
    public function testRevertOpenNextKeepsTakenOverOpening(): void
    {
        $nextId = $this->closeYearWithNextPeriod();
        $foreignId = $this->postForeignOpening($nextId, $this->matchingOpeningLines(), $nextId);

        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $reverted = $this->closing->revertStep($this->supplierId, $this->periodId, 'open_next', $this->rv(), $this->meta());

        self::assertArrayNotHasKey('opening', $reverted['dumps'], 'Revert nic otevíracího nemaže.');
        self::assertSame([$foreignId], $this->openingEntryIds($nextId), 'Převzatý zápis revert přežil.');
        self::assertSame('pending', $this->stepStatus($this->closing->state($this->supplierId, $this->periodId), 'open_next'));

        $this->closing->revertStep($this->supplierId, $this->periodId, 'close_books', $this->rv(), $this->meta());
        self::assertSame('closing', $this->periods->findById($this->supplierId, $this->periodId)['status'],
            'Převzatý zápis neblokuje revert uzavření knih.');
        self::assertSame([$foreignId], $this->openingEntryIds($nextId));
    }

    public function testComputedOpeningStillRevertsNormally(): void
    {
        $nextId = $this->closeYearWithNextPeriod();

        $result = $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        self::assertSame('computed', $result['opening_source']);
        self::assertSame([(int) $result['entry_id']], $this->openingEntryIds($nextId));

        $reverted = $this->closing->revertStep($this->supplierId, $this->periodId, 'open_next', $this->rv(), $this->meta());
        self::assertArrayHasKey('opening', $reverted['dumps']);
        self::assertSame([], $this->openingEntryIds($nextId), 'Vypočtený zápis revert smaže.');
    }

    public function testForeignSupplierOpeningIsIgnoredAndItsPeriodRefused(): void
    {
        $nextId = $this->closeYearWithNextPeriod();

        $otherId = $this->createSupplier('Jiná firma test s.r.o.', 'opening-takeover-other@example.com');
        $this->seeder->seedForSupplier($otherId);
        $otherPeriod = $this->periods->create($otherId, self::YEAR + 1, self::NEXT_START, (self::YEAR + 1) . '-12-31');
        $this->posting->postDocument($otherId, 'opening', null, $this->matchingOpeningLines(), [
            'entry_date' => self::NEXT_START, 'posted' => true, 'posted_by' => $this->userId, 'user_id' => $this->userId,
        ]);

        self::assertSame([], $this->closingRepo->openingEntriesInPeriod($otherId, $nextId), 'Cizí firma období nevidí.');
        self::assertSame([], $this->closingRepo->openingEntriesInPeriod($this->supplierId, $otherPeriod), 'Zápis cizí firmy se nepočítá.');

        try {
            $this->closing->state($otherId, $this->periodId);
            self::fail('Období jiné firmy musí být odmítnuto.');
        } catch (ClosingException $e) {
            self::assertSame('not_found', $e->errorCode);
        }

        $result = $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        self::assertSame('computed', $result['opening_source']);
    }

    /** Průvodce zahájením účetnictví vidí převzatý zápis i bez klíče a druhý nezaloží. */
    public function testActivationSeesTakenOverOpeningRegardlessOfSourceId(): void
    {
        $nextId = $this->periods->create($this->supplierId, self::YEAR + 1, self::NEXT_START, (self::YEAR + 1) . '-12-31');
        $this->postForeignOpening($nextId, $this->matchingOpeningLines(), null);

        $opening = $this->container->get(OpeningBalanceService::class);
        self::assertTrue($opening->isPosted($this->supplierId, self::NEXT_START));
        self::assertSame('opening_already_posted', $opening->postBlocker($this->supplierId, self::NEXT_START)['code'] ?? null);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** Scénář → kroky → uzavření knih; následující období založené dopředu (sem míří převzatý zápis). */
    private function closeYearWithNextPeriod(): int
    {
        $this->seedScenario();
        $this->runStepsUntilCloseReady();
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        return $this->periods->create($this->supplierId, self::YEAR + 1, self::NEXT_START, (self::YEAR + 1) . '-12-31');
    }

    /** @return list<array{account_code:string, side:string, amount:float}> */
    private function matchingOpeningLines(): array
    {
        return [
            self::l('311', 'debit', 7100.00),
            self::l('221', 'debit', 5000.00),
            self::l('701', 'credit', 12100.00),
            self::l('701', 'debit', 12100.00),
            self::l('343', 'credit', 1260.00),
            self::l('321', 'credit', 4840.00),
            self::l('431', 'credit', 6000.00),
        ];
    }

    /** 311 o 100 méně, VH o 100 méně — 701 se vyrovná, v porovnání se neobjeví. */
    private function mismatchingOpeningLines(): array
    {
        return [
            self::l('311', 'debit', 7000.00),
            self::l('221', 'debit', 5000.00),
            self::l('701', 'credit', 12000.00),
            self::l('701', 'debit', 12000.00),
            self::l('343', 'credit', 1260.00),
            self::l('321', 'credit', 4840.00),
            self::l('431', 'credit', 5900.00),
        ];
    }

    /** @param list<array{account_code:string, side:string, amount:float}> $lines */
    private function postForeignOpening(int $nextId, array $lines, ?int $sourceId): int
    {
        return $this->posting->postDocument($this->supplierId, 'opening', $sourceId, $lines, [
            'entry_date' => self::NEXT_START,
            'document_no' => 'PS-' . (self::YEAR + 1),
            'description' => 'Převzaté počáteční stavy',
            'posted' => true,
            'posted_by' => $this->userId,
            'user_id' => $this->userId,
        ]);
    }

    /** @return list<int> */
    private function openingEntryIds(int $periodId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM journal_entries
              WHERE supplier_id = ? AND period_id = ? AND source_type = 'opening' AND posted_at IS NOT NULL
              ORDER BY id"
        );
        $stmt->execute([$this->supplierId, $periodId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function openingBalance(int $periodId, string $code): float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE e.supplier_id = ? AND e.period_id = ? AND e.source_type = 'opening'
                AND e.posted_at IS NOT NULL AND a.account_code = ?"
        );
        $stmt->execute([$this->supplierId, $periodId, $code]);
        return (float) $stmt->fetchColumn();
    }

    private function auditCount(string $action): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM activity_log WHERE supplier_id = ? AND action = ?');
        $stmt->execute([$this->supplierId, $action]);
        return (int) $stmt->fetchColumn();
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

    private function runStepsUntilCloseReady(): void
    {
        $sid = $this->supplierId;
        $pid = $this->periodId;
        $this->closing->start($sid, $pid, $this->rv(), $this->meta());
        $items = [];
        foreach ($this->closing->inventoryPreview($sid, $pid)['rows'] as $r) {
            $items[(int) $r['account_id']] = ['counted_balance' => (float) $r['book_balance'], 'resolution' => 'resolved', 'note' => null];
        }
        $this->closing->saveInventory($sid, $pid, $this->rv(), ['complete' => true], $items, ['user_id' => $this->userId]);
        $this->closing->runPrecheck($sid, $pid, $this->rv(), $this->meta());
        $this->closing->confirmStep($sid, $pid, 'depreciation', 'skipped', null, $this->rv(), $this->meta());
        $this->closing->runFxRevaluation($sid, $pid, [], $this->rv(), $this->meta());
        foreach (['estimates', 'deferrals', 'provisions', 'income_tax'] as $step) {
            $this->closing->confirmStep($sid, $pid, $step, 'skipped', null, $this->rv(), $this->meta());
        }
    }

    private function createSupplier(string $name, string $email): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, ?, ?, ?)'
        );
        $stmt->execute([$name, $this->czId, $email, $this->currencyId, $this->vatRateId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function rv(): int
    {
        return (int) $this->periods->findById($this->supplierId, $this->periodId)['row_version'];
    }

    /** @param array<string,mixed> $state */
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

    /** @param list<array{account_code:string, side:string, amount:float}> $lines */
    private function manual(array $lines, string $date): int
    {
        return $this->posting->postDocument($this->supplierId, 'manual', null, $lines, [
            'entry_date' => $date, 'posted_by' => $this->userId, 'user_id' => $this->userId,
        ]);
    }

    /** @return array{account_code:string, side:string, amount:float} */
    private static function l(string $code, string $side, float $amount): array
    {
        return ['account_code' => $code, 'side' => $side, 'amount' => $amount];
    }

    private static function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
