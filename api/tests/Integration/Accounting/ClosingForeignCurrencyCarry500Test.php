<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\ClosingRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\PostingService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Uzavření a otevření knih firmy podle vyhlášky 500 nese cizoměnové zůstatky dál
 * (dřív je ztrácelo: 221 v EUR po otevření nového roku neměl cizí zůstatek a přecenění
 * dalšího roku nemělo z čeho vycházet).
 *
 *   - EUR banka a EUR pohledávka: uzavírací i otevírací zápis nesou měnu a cizí částku,
 *     návrh přecenění banky v dalším roce vychází z přeneseného zůstatku (počítaný jednou).
 *   - Buňka účtu s korunovými i eurovými řádky se rozdělí po měnách (korunová část bez měny).
 *   - Cizí zůstatek s opačným znaménkem než korunový se nepřenáší (zůstane korunový).
 *   - Převodový účet 26x (peníze na cestě, konverze měny) se otevírá jen v korunách.
 *   - Otevření, které měnu neneslo (rok otevřený dřív), měnu nepřenáší a návrh přecenění
 *     počítá jako dřív z celého deníku.
 *   - Uzávěrka bez měny + otevření s měnou (převzatá uzávěrka): návrh přecenění počítá
 *     cizí zůstatek od počátečního stavu, ne dvakrát.
 */
#[Group('integration')]
final class ClosingForeignCurrencyCarry500Test extends TestCase
{
    private const YEAR = 2096;

    private Connection $db;
    private PostingService $posting;
    private ClosingService $closing;
    private ClosingRepository $closingRepo;
    private ChartOfAccountsRepository $accounts;
    private AccountingPeriodRepository $periods;
    private JournalEntryRepository $journal;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private int $eurId = 0;
    private int $vatRateId = 0;
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
            $this->closingRepo = $container->get(ClosingRepository::class);
            $this->accounts    = $container->get(ChartOfAccountsRepository::class);
            $this->periods     = $container->get(AccountingPeriodRepository::class);
            $this->journal     = $container->get(JournalEntryRepository::class);
            $seeder            = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->userId    = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $currencyId      = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId      = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $currencyId === 0 || $this->vatRateId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, "fx-carry-500@example.com", ?, ?)'
        )->execute(['FX přenos 500 s.r.o.', $this->czId, $currencyId, $this->vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();

        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');

        $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, "EUR", "EUR", "€", "Euro", "Euro", 2, 1, 0)'
        )->execute([$this->supplierId]);
        $this->eurId = (int) $pdo->lastInsertId();

        $parent = $this->accounts->findByCode($this->supplierId, '221');
        foreach (['221100' => 'Banka EUR', '221200' => 'Banka smíšená', '221300' => 'Banka EUR zbytek'] as $code => $name) {
            $this->accounts->insert($this->supplierId, [
                'account_code' => $code, 'name' => $name, 'account_type' => 'asset', 'normal_side' => 'debit',
                'is_synthetic' => false, 'parent_id' => $parent['id'],
            ]);
        }
        $transit = $this->accounts->findByCode($this->supplierId, '261');
        $this->accounts->insert($this->supplierId, [
            'account_code' => '261100', 'name' => 'Peníze na cestě konverze', 'account_type' => 'asset', 'normal_side' => 'debit',
            'is_synthetic' => false, 'parent_id' => $transit['id'],
        ]);

        $this->seedRate(self::YEAR . '-12-31', 'EUR', 25.10);
        $this->seedRate((self::YEAR + 1) . '-12-31', 'EUR', 26.00);
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

    public function testClosingAndOpeningCarryForeignBalancesAndNextYearRevaluesThem(): void
    {
        $sid = $this->supplierId;
        $this->postEurInvoice(1000.00, 24.50);
        $this->foreign('221100', 'debit', 10000.00, 250000.00, 25.00, self::YEAR . '-02-01');
        // Smíšená buňka: korunový vklad a eurový vklad na tomtéž účtu bez dimenzí.
        $this->manual([self::l('221200', 'debit', 5000.00), self::l('411', 'credit', 5000.00)], self::YEAR . '-03-01');
        $this->foreign('221200', 'debit', 100.00, 2500.00, 25.00, self::YEAR . '-03-02');
        // Konverze přes peníze na cestě: EUR přijde, odejde v korunách. Zbytek 261 je korunový.
        $this->foreign('261100', 'debit', 1000.00, 25000.00, 25.00, self::YEAR . '-05-01');
        $this->manual([self::l('221200', 'debit', 24800.00), self::l('261100', 'credit', 24800.00)], self::YEAR . '-05-02');
        // Cizí zůstatek -0,10 EUR proti korunovému +97,60 Kč: jedním řádkem ho nést nejde.
        $this->foreign('221300', 'debit', 100.00, 2500.00, 25.00, self::YEAR . '-04-01');
        $this->foreign('221300', 'credit', 100.10, 2402.40, 24.00, self::YEAR . '-04-02');

        $this->runChainToClosed([['account_code' => '221100', 'currency_code' => 'EUR', 'foreign_balance' => 10000.00]]);

        $closingEntry = $this->journal->findBySource($sid, 'closing', $this->periodId);
        self::assertNotNull($closingEntry);
        self::assertSame([['EUR', 251000.00, 10000.00, 'credit']], $this->accountLines((int) $closingEntry['id'], '221100'),
            'Uzavření banky nese EUR zůstatek po přecenění (250 000 + 1 000 Kč, 10 000 EUR).');
        self::assertSame([['EUR', 25100.00, 1000.00, 'credit']], $this->accountLines((int) $closingEntry['id'], '311'),
            'Uzavření pohledávky nese EUR (24 500 + přecenění 600 Kč, 1 000 EUR).');

        $opened = $this->closing->openNext($sid, $this->periodId, $this->rv(), $this->meta());
        $nextId = (int) $opened['next_period_id'];
        $opening = $this->journal->findBySource($sid, 'opening', $nextId);
        self::assertNotNull($opening);
        $openingId = (int) $opening['id'];
        self::assertSame([['EUR', 251000.00, 10000.00, 'debit']], $this->accountLines($openingId, '221100'));
        self::assertSame([['EUR', 25100.00, 1000.00, 'debit']], $this->accountLines($openingId, '311'));
        self::assertSame([[null, 29800.00, null, 'debit'], ['EUR', 2500.00, 100.00, 'debit']], $this->accountLines($openingId, '221200'),
            'Smíšená buňka: korunová část bez měny, eurová s cizí částkou.');
        self::assertSame([[null, 97.60, null, 'debit']], $this->accountLines($openingId, '221300'),
            'Cizí zůstatek opačného znaménka zůstane korunový.');
        self::assertSame([[null, 200.00, null, 'debit']], $this->accountLines($openingId, '261100'),
            'Peníze na cestě po konverzi EUR → Kč se otevírají jen v korunách, cizí částka se nehromadí.');
        self::assertSame([[null, 200.00, null, 'credit']], $this->accountLines((int) $closingEntry['id'], '261100'));

        // Další rok: návrh přecenění banky vychází z přeneseného zůstatku a počítá ho jednou.
        $this->periodId = $nextId;
        $this->foreign('221100', 'debit', 500.00, 12600.00, 25.20, (self::YEAR + 1) . '-05-01');
        $proposals = array_column($this->closingRepo->bankProposals($sid, (self::YEAR + 1) . '-12-31'), null, 'account_code');
        self::assertArrayHasKey('221100', $proposals);
        self::assertSame(1050000, self::cents($proposals['221100']['foreign_balance']));
        self::assertSame(self::cents(263600.00), self::cents($proposals['221100']['czk_balance']));
        self::assertArrayNotHasKey('221200', $proposals, 'Smíšený účet se do poloautomatu nenabízí ani po přenosu.');

        $preview = $this->closing->fxPreview($sid, $nextId, [['account_code' => '221100', 'currency_code' => 'EUR', 'foreign_balance' => 10500.00]]);
        $bank = array_column($preview['bank']['lines'], null, 'account_code');
        self::assertSame(self::cents(273000.00 - 263600.00), self::cents($bank['221100']['diff']), 'Přecenění 10 500 EUR × 26 proti 263 600 Kč.');
        self::assertCount(1, $preview['saldo']['detail'], 'Přenesená EUR pohledávka se v dalším roce dál přeceňuje po dokladu.');
    }

    /**
     * Uzavření bez měny (převzaté z původního systému) a otevření s měnou: součet přes celý
     * deník by cizí zůstatek započítal dvakrát. Návrh i rozvaha jdou od počátečního stavu.
     */
    public function testProposalCountsCarriedCurrencyOnceAfterClosingWithoutCurrency(): void
    {
        $sid = $this->supplierId;
        $this->foreign('221100', 'debit', 10000.00, 250000.00, 25.00, self::YEAR . '-02-01');
        $this->posting->postDocument($sid, 'closing', null, [
            self::l('702', 'debit', 250000.00), self::l('221100', 'credit', 250000.00),
        ], ['entry_date' => self::YEAR . '-12-31', 'posted_by' => $this->userId]);
        $nextId = $this->periods->create($sid, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
        $this->posting->postDocument($sid, 'opening', $nextId, [
            ['account_code' => '221100', 'side' => 'debit', 'amount' => 250000.00, 'currency_code' => 'EUR', 'amount_foreign' => 10000.00, 'fx_rate' => 25.00],
            self::l('701', 'credit', 250000.00),
        ], ['entry_date' => (self::YEAR + 1) . '-01-01', 'posted_by' => $this->userId]);
        $this->foreign('221100', 'debit', 500.00, 12600.00, 25.20, (self::YEAR + 1) . '-05-01');

        $proposals = array_column($this->closingRepo->bankProposals($sid, (self::YEAR + 1) . '-12-31'), null, 'account_code');
        self::assertArrayHasKey('221100', $proposals);
        self::assertSame(1050000, self::cents($proposals['221100']['foreign_balance']), '10 000 + 500 EUR, ne 20 500.');
        self::assertSame(self::cents(262600.00), self::cents($proposals['221100']['czk_balance']));

        $bs = array_values(array_filter($this->closingRepo->bsBalances($sid, $nextId, (self::YEAR + 1) . '-12-31'),
            static fn (array $r): bool => $r['account_code'] === '221100'));
        self::assertCount(1, $bs);
        self::assertSame('EUR', $bs[0]['currency_code']);
        self::assertSame(1050000, self::cents($bs[0]['amount_foreign']));
    }

    /**
     * Rok otevřený dřív bez měny (otevírací zápis má na EUR účtu jen korunový řádek): cizí
     * zůstatek od kotvy by zachytil jen letošní pohyby. Měna se proto nepřenáší a návrh
     * přecenění zůstává u součtu přes celý deník, kde se uzavření a otevření bez měny ruší.
     */
    public function testOpeningWithoutCurrencyKeepsPreviousBehaviour(): void
    {
        $sid = $this->supplierId;
        $this->foreign('221100', 'debit', 10000.00, 250000.00, 25.00, self::YEAR . '-02-01');
        $this->posting->postDocument($sid, 'closing', $this->periodId, [
            self::l('702', 'debit', 250000.00), self::l('221100', 'credit', 250000.00),
        ], ['entry_date' => self::YEAR . '-12-31', 'posted_by' => $this->userId]);
        $nextId = $this->periods->create($sid, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
        $this->posting->postDocument($sid, 'opening', $nextId, [
            self::l('221100', 'debit', 250000.00), self::l('701', 'credit', 250000.00),
        ], ['entry_date' => (self::YEAR + 1) . '-01-01', 'posted_by' => $this->userId]);
        $this->foreign('221100', 'debit', 500.00, 12600.00, 25.20, (self::YEAR + 1) . '-05-01');

        $proposals = array_column($this->closingRepo->bankProposals($sid, (self::YEAR + 1) . '-12-31'), null, 'account_code');
        self::assertArrayHasKey('221100', $proposals);
        self::assertSame(1050000, self::cents($proposals['221100']['foreign_balance']));

        $bs = array_values(array_filter($this->closingRepo->bsBalances($sid, $nextId, (self::YEAR + 1) . '-12-31'),
            static fn (array $r): bool => $r['account_code'] === '221100'));
        self::assertCount(1, $bs);
        self::assertArrayNotHasKey('currency_code', $bs[0], 'Bez měny v počátečním stavu se cizí zůstatek nepřenáší.');
        self::assertSame(self::cents(262600.00), self::cents($bs[0]['bal']));
    }

    /** @return list<array{0:?string, 1:float, 2:?float, 3:string}> měna, Kč, cizí částka, strana */
    private function accountLines(int $entryId, string $code): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT l.currency_code, l.amount, l.amount_foreign, l.side
               FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.entry_id = ? AND l.supplier_id = ? AND a.account_code = ?
              ORDER BY l.currency_code IS NOT NULL, l.currency_code, l.id'
        );
        $stmt->execute([$entryId, $this->supplierId, $code]);
        return array_map(static fn (array $r): array => [
            $r['currency_code'], (float) $r['amount'], $r['amount_foreign'] === null ? null : (float) $r['amount_foreign'], (string) $r['side'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function postEurInvoice(float $totalEur, float $docRate): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "EU Odběratel", "Test 1", "Praha", "11000", ?, "CZ12345678", "eu@example.com", "cs", ?, 1, 0)'
        )->execute([$this->supplierId, $this->czId, $this->eurId]);
        $clientId = (int) $pdo->lastInsertId();
        $issue = self::YEAR . '-06-15';
        $pdo->prepare(
            'INSERT INTO invoices (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                currency_id, exchange_rate, reverse_charge, total_without_vat, total_vat, total_with_vat,
                status, vat_classification_code, created_by)
             VALUES (?, "FV-2096-EUR-1", "invoice", ?, ?, ?, ?, ?, ?, 0, ?, 0.00, ?, "issued", "1", ?)'
        )->execute([$this->supplierId, $clientId, $issue, $issue, $issue, $this->eurId, $docRate, $totalEur, $totalEur, $this->userId]);
        $invoiceId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, "Služby", 1, "ks", ?, ?, 0, ?, 0.00, ?, 0)'
        )->execute([$invoiceId, $totalEur, $this->vatRateId, $totalEur, $totalEur]);
        $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, $this->posting->buildFromInvoice($this->supplierId, $invoiceId), [
            'entry_date' => $issue,
            'posted_by' => $this->userId,
        ]);
        return $invoiceId;
    }

    /** @param list<array{account_code:string, currency_code:string, foreign_balance:float}> $bankRows */
    private function runChainToClosed(array $bankRows): void
    {
        $sid = $this->supplierId;
        $pid = $this->periodId;
        $this->closing->start($sid, $pid, $this->rv(), $this->meta());
        $this->closing->runPrecheck($sid, $pid, $this->rv(), $this->meta());
        $this->closing->confirmStep($sid, $pid, 'depreciation', 'skipped', null, $this->rv(), $this->meta());
        $this->closing->runFxRevaluation($sid, $pid, $bankRows, $this->rv(), $this->meta());
        foreach (['estimates', 'deferrals', 'provisions', 'income_tax'] as $step) {
            $this->closing->confirmStep($sid, $pid, $step, 'skipped', null, $this->rv(), $this->meta());
        }
        $rv = $this->rv();
        $items = [];
        foreach ($this->closing->inventoryPreview($sid, $pid)['rows'] as $r) {
            $items[(int) $r['account_id']] = ['counted_balance' => (float) $r['book_balance'], 'resolution' => 'resolved', 'note' => null];
        }
        $this->closing->saveInventory($sid, $pid, $rv, ['complete' => true], $items, ['user_id' => $this->userId]);
        $this->closing->closeBooks($sid, $pid, $this->rv(), $this->meta());
    }

    /** @param 'debit'|'credit' $side */
    private function foreign(string $code, string $side, float $foreign, float $czk, float $rate, string $date): void
    {
        $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => $code, 'side' => $side, 'amount' => $czk, 'currency_code' => 'EUR', 'fx_rate' => $rate, 'amount_foreign' => $foreign],
            self::l('411', $side === 'debit' ? 'credit' : 'debit', $czk),
        ], ['entry_date' => $date, 'posted_by' => $this->userId, 'allow_closing_period' => true]);
    }

    /** @param list<array{account_code:string, side:string, amount:float}> $lines */
    private function manual(array $lines, string $date): void
    {
        $this->posting->postDocument($this->supplierId, 'manual', null, $lines, ['entry_date' => $date, 'posted_by' => $this->userId]);
    }

    private function seedRate(string $date, string $code, float $rate): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO exchange_rates (rate_date, currency_code, rate) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE rate = VALUES(rate)'
        )->execute([$date, $code, $rate]);
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

    /** @return array{account_code:string, side:string, amount:float} */
    private static function l(string $code, string $side, float $amount): array
    {
        return ['account_code' => $code, 'side' => $side, 'amount' => $amount];
    }

    private static function cents(float $amount): int
    {
        return (int) round($amount * 100.0);
    }
}
