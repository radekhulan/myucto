<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ClosingRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\PostingService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Kontroly uzávěrky nesmí hlásit nález tam, kde účetnictví sedí. Plané nálezy přehluší ty
 * skutečné a účetní přestane kontroly číst. Izolovaná firma v transakci s rollbackem.
 */
#[Group('integration')]
final class ClosingChecksAccuracyTest extends TestCase
{
    private const YEAR = 2093;

    private Connection $db;
    private ClosingService $closing;
    private ClosingRepository $closingRepo;
    private PostingService $posting;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private int $vendorId = 0;
    private int $currencyId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje.');
        }
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->closing = $container->get(ClosingService::class);
        $this->closingRepo = $container->get(ClosingRepository::class);
        $this->posting = $container->get(PostingService::class);
        $periods = $container->get(AccountingPeriodRepository::class);
        $seeder = $container->get(ChartOfAccountsSeeder::class);

        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->currencyId === 0 || $vatRateId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES ("Kontroly s.r.o.", "Testovací 1", "Praha", "11000", ?, "kontroly@example.com", ?, ?, "double_entry")'
        )->execute([$czId, $this->currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, currency_default_id, is_vendor)
             VALUES (?, "Dodavatel s.r.o.", "Ulice 1", "Praha", "11000", ?, ?, 1)'
        )->execute([$this->supplierId, $czId, $this->currencyId]);
        $this->vendorId = (int) $pdo->lastInsertId();
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

    /** Doplatek jiným kanálem: dokud je saldo otevřené, nález platí, po vyrovnání zmizí. */
    public function testAmountMismatchDisappearsOnceDocumentIsSettledOtherwise(): void
    {
        $doc = $this->purchase(1000.00, self::YEAR . '-03-01', 'paid', self::YEAR . '-03-10', $this->currencyId);
        $this->post($doc, 1000.00, self::YEAR . '-03-01');
        $tx = $this->bankPayment($doc, 600.00, self::YEAR . '-03-10', 'CZK');
        $this->postBank($tx, 600.00, self::YEAR . '-03-10');

        self::assertSame(['amount_mismatch'], $this->auditIssues(self::YEAR . '-03-01', self::YEAR . '-03-31', $doc));

        $entry = $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '321', 'side' => 'debit', 'amount' => 400.0],
            ['account_code' => '365', 'side' => 'credit', 'amount' => 400.0],
        ], ['entry_date' => self::YEAR . '-04-15', 'description' => 'Doplatek zápočtem', 'posted' => true, 'user_id' => $this->userId]);
        $this->db->pdo()->prepare('INSERT INTO journal_entry_document_links (supplier_id, entry_id, doc_type, doc_id, created_by) VALUES (?, ?, "purchase_invoice", ?, ?)')
            ->execute([$this->supplierId, $entry, $doc, $this->userId]);

        self::assertSame(['amount_mismatch'], $this->auditIssues(self::YEAR . '-03-01', self::YEAR . '-03-31', $doc), 'K 31. 3. zůstatek ještě trvá.');
        self::assertNull($this->auditIssues(self::YEAR . '-01-01', self::YEAR . '-12-31', $doc), 'K 31. 12. je doklad vyrovnaný.');
    }

    /** Hlavička tvrdí dřívější datum úhrady než banka: k rozvahovému dni nejde o chybějící úhradu. */
    public function testPaymentPostedAfterAsOfIsNotMissingPayment(): void
    {
        $withLater = $this->purchase(500.00, self::YEAR . '-02-28', 'paid', self::YEAR . '-02-28', $this->currencyId);
        $this->post($withLater, 500.00, self::YEAR . '-02-28');
        $tx = $this->bankPayment($withLater, 500.00, self::YEAR . '-03-23', 'CZK');
        $this->postBank($tx, 500.00, self::YEAR . '-03-23');
        $withoutAny = $this->purchase(700.00, self::YEAR . '-02-27', 'paid', self::YEAR . '-02-27', $this->currencyId);
        $this->post($withoutAny, 700.00, self::YEAR . '-02-27');

        $found = array_column($this->closingRepo->paidPurchasesOpenSaldo($this->supplierId, self::YEAR . '-02-28'), 'id');

        self::assertNotContains($withLater, $found);
        self::assertContains($withoutAny, $found, 'Doklad bez jakékoli úhrady se hlásí dál.');
    }

    /** Otevřená záloha na 324 je jeden nález, ne dva řádky kontrol. */
    public function testAccountWithOwnCheckIsNotReportedAsClearingAccount(): void
    {
        $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '221', 'side' => 'debit', 'amount' => 900.0],
            ['account_code' => '324', 'side' => 'credit', 'amount' => 900.0],
        ], ['entry_date' => self::YEAR . '-05-01', 'description' => 'Přijatá záloha', 'posted' => true, 'user_id' => $this->userId]);

        $checks = array_column($this->closing->monthlyCheck($this->supplierId, $this->periodId, null, null)['checks'], null, 'key');

        self::assertFalse($checks['deposits_314_324_open']['ok']);
        self::assertTrue($checks['clearing_accounts_open']['ok']);
    }

    /** Karta z EUR účtu na fakturu v USD: řádek bankovního zápisu v USD úhradu dokládá. */
    public function testForeignPaymentPostedInDocumentCurrencyIsProven(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, is_active) VALUES (?, "USD", "USD", "$", "Dolar", "US dollar", 1)')
            ->execute([$this->supplierId]);
        $usd = (int) $pdo->lastInsertId();
        $doc = $this->purchase(25.00, self::YEAR . '-10-27', 'paid', self::YEAR . '-10-29', $usd, 20.0);
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $doc, [
            ['account_code' => '518', 'side' => 'debit', 'amount' => 500.0],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 500.0, 'currency_code' => 'USD', 'fx_rate' => 20.0, 'amount_foreign' => 25.0],
        ], ['entry_date' => self::YEAR . '-10-27', 'description' => 'Faktura v USD', 'posted' => true, 'user_id' => $this->userId]);
        $tx = $this->bankPayment($doc, 22.00, self::YEAR . '-10-29', 'EUR');
        $this->posting->postDocument($this->supplierId, 'bank', $tx, [
            ['account_code' => '321', 'side' => 'debit', 'amount' => 500.0, 'currency_code' => 'USD', 'fx_rate' => 20.0, 'amount_foreign' => 25.0],
            ['account_code' => '221', 'side' => 'credit', 'amount' => 500.0],
        ], ['entry_date' => self::YEAR . '-10-29', 'description' => 'Platba kartou', 'posted' => true, 'user_id' => $this->userId]);

        $projection = array_values(array_filter(
            $this->closingRepo->fxItemsWithSettlement($this->supplierId, self::YEAR . '-12-31'),
            static fn (array $p): bool => (int) $p['item']['doc_id'] === $doc,
        ));

        self::assertCount(1, $projection);
        self::assertNotContains('legacy_payment_currency_unproven', $projection[0]['evidence']['findings']);
    }

    /** Doklad bez důkazu: vyrovnaný v deníku přecenění nezastaví, otevřené se vypíšou všechny. */
    public function testRevaluationListsAllUnprovenDocumentsAndSkipsSettledOnes(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, is_active) VALUES (?, "USD", "USD", "$", "Dolar", "US dollar", 1)')
            ->execute([$this->supplierId]);
        $usd = (int) $pdo->lastInsertId();
        $service = Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Closing\FxRevaluationService::class);
        $period = $pdo->query("SELECT * FROM accounting_periods WHERE id = {$this->periodId}")->fetch(\PDO::FETCH_ASSOC);

        $document = function (string $status, ?float $bankCzk) use ($usd): array {
            $date = self::YEAR . '-06-01';
            $doc = $this->purchase(25.00, $date, $status, $date, $usd, 20.0);
            $this->posting->postDocument($this->supplierId, 'purchase_invoice', $doc, [
                ['account_code' => '518', 'side' => 'debit', 'amount' => 500.0],
                ['account_code' => '321', 'side' => 'credit', 'amount' => 500.0, 'currency_code' => 'USD', 'fx_rate' => 20.0, 'amount_foreign' => 25.0],
            ], ['entry_date' => $date, 'description' => 'Faktura', 'posted' => true, 'user_id' => $this->userId]);
            $tx = $this->bankPayment($doc, 22.00, $date, 'EUR');
            $this->postBank($tx, (float) $bankCzk, $date);
            return [$doc, (string) $this->db->pdo()->query("SELECT vendor_invoice_number FROM purchase_invoices WHERE id = {$doc}")->fetchColumn()];
        };

        $document('paid', 500.0);
        try {
            $service->preview($this->supplierId, $period);
        } catch (\MyInvoice\Service\Accounting\Closing\ClosingException $e) {
            self::assertNotSame('fx_settlement_unproven', $e->errorCode, 'Vyrovnaný doklad nesmí přecenění zastavit.');
        }

        $document('paid', 300.0);
        $document('paid', 200.0);
        try {
            $service->preview($this->supplierId, $period);
            self::fail('Otevřené doklady bez důkazu musí přecenění zastavit.');
        } catch (\MyInvoice\Service\Accounting\Closing\ClosingException $e) {
            self::assertSame('fx_settlement_unproven', $e->errorCode);
            self::assertSame(2, substr_count($e->getMessage(), 'přijatá faktura'), $e->getMessage());
        }
    }

    /** Zámek po podaném DPH nezastaví zápis kroku uzávěrky, běžný zápis ano. */
    public function testDateLockAllowsClosingStepEntriesOnly(): void
    {
        $this->db->pdo()->prepare('INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, ?) ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)')
            ->execute([$this->supplierId, self::YEAR . '-12-31']);
        $posting = Bootstrap::buildContainer()->get(PostingService::class);
        $lines = [['account_code' => '551', 'side' => 'debit', 'amount' => 100.0], ['account_code' => '082', 'side' => 'credit', 'amount' => 100.0]];
        $meta = ['entry_date' => self::YEAR . '-12-31', 'description' => 'Odpisy', 'posted' => true, 'user_id' => $this->userId];

        try {
            $posting->postDocument($this->supplierId, 'manual', null, $lines, $meta);
            self::fail('Běžný zápis do zamčeného data musí být odmítnut.');
        } catch (\MyInvoice\Service\Accounting\PostingException $e) {
            self::assertSame('date_locked', $e->errorCode);
        }

        self::assertGreaterThan(0, $posting->postDocument($this->supplierId, 'manual', null, $lines, $meta + ['allow_closing_period' => true]));
    }

    /** Nedokončené otevření minulého roku zastaví už zahájení, dokud se dá ještě spustit. */
    public function testClosingCannotStartWhilePreviousOpenNextIsMissing(): void
    {
        $pdo = $this->db->pdo();
        $periods = Bootstrap::buildContainer()->get(AccountingPeriodRepository::class);
        $prior = $periods->create($this->supplierId, self::YEAR - 1, (self::YEAR - 1) . '-01-01', (self::YEAR - 1) . '-12-31');
        $pdo->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ?")->execute([$prior]);
        $this->closingRepo->upsertStep($this->supplierId, $prior, 'close_books', 'done', [], null, $this->userId);
        $version = (int) $pdo->query("SELECT row_version FROM accounting_periods WHERE id = {$this->periodId}")->fetchColumn();

        try {
            $this->closing->start($this->supplierId, $this->periodId, $version);
            self::fail('Uzávěrka nesmí začít bez otevření minulého roku.');
        } catch (\MyInvoice\Service\Accounting\Closing\ClosingException $e) {
            self::assertSame('previous_open_next_missing', $e->errorCode);
        }
    }

    /** @return list<string>|null kódy nálezu dokladu, null = doklad se nehlásí */
    private function auditIssues(string $from, string $to, int $doc): ?array
    {
        $check = $this->closing->checkFindings($this->supplierId, $this->periodId, 'payment_match_audit', $from, $to);
        foreach ($check['value']['findings'] as $finding) {
            if ((int) $finding['doc_id'] === $doc) {
                return $finding['issues'];
            }
        }
        return null;
    }

    private function purchase(float $total, string $issue, string $status, string $paidAt, int $currencyId, float $rate = 1.0): int
    {
        $pdo = $this->db->pdo();
        $number = 'PF-' . random_int(100000, 999999);
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind, issue_date, tax_date, due_date, received_at,
                 currency_id, exchange_rate, reverse_charge, total_without_vat, total_vat, total_with_vat, tax_deductible, status, paid_at, created_by)
             VALUES (?, ?, ?, "{}", "invoice", ?, ?, ?, ?, ?, ?, 0, ?, 0, ?, 1, ?, ?, ?)'
        )->execute([$this->supplierId, $this->vendorId, $number, $issue, $issue, $issue, $issue, $currencyId, $rate, $total, $total, $status, $paidAt, $this->userId]);
        return (int) $pdo->lastInsertId();
    }

    private function post(int $doc, float $amount, string $date): void
    {
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $doc, [
            ['account_code' => '518', 'side' => 'debit', 'amount' => $amount],
            ['account_code' => '321', 'side' => 'credit', 'amount' => $amount],
        ], ['entry_date' => $date, 'description' => 'Přijatá faktura', 'posted' => true, 'user_id' => $this->userId]);
    }

    private function postBank(int $tx, float $amount, string $date): void
    {
        $this->posting->postDocument($this->supplierId, 'bank', $tx, [
            ['account_code' => '321', 'side' => 'debit', 'amount' => $amount],
            ['account_code' => '221', 'side' => 'credit', 'amount' => $amount],
        ], ['entry_date' => $date, 'description' => 'Úhrada', 'posted' => true, 'user_id' => $this->userId]);
    }

    private function bankPayment(int $doc, float $amount, string $date, string $currency): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO bank_statements (supplier_id, source, file_name, file_hash, account_number, bank_code, statement_date, currency)
             VALUES (?, "gpc", "kontroly.gpc", ?, "1000000005", "0100", ?, ?)'
        )->execute([$this->supplierId, hash('sha256', uniqid('kontroly', true)), $date, $currency]);
        $statement = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO bank_transactions (statement_id, source, posted_at, amount, currency, counterparty_name, description, match_status)
             VALUES (?, "statement", ?, ?, ?, "Dodavatel s.r.o.", "Úhrada", "manual")'
        )->execute([$statement, $date, -$amount, $currency]);
        $tx = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO payment_matches (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type) VALUES (?, ?, ?, ?, "manual")')
            ->execute([$this->supplierId, $tx, $doc, $amount]);
        return $tx;
    }
}
