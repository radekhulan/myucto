<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\SaldoRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\InvoiceSettlementService;
use MyInvoice\Service\Accounting\SettlementException;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Integrační testy úhrady faktury zápočtem proti zvolenému účtu (migrace 1126):
 * kontace 355/311 a 321/365, částečný zápočet vydané faktury, plná výše u přijaté,
 * storno, cizí měna a překročení zbytku. Vše v transakci → rollback.
 */
#[Group('integration')]
final class InvoiceSettlementServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const YEAR = 2099;

    private Connection $db;
    private InvoiceSettlementService $service;
    private JournalEntryRepository $journal;
    private AccountingPeriodRepository $periods;
    private ChartOfAccountsRepository $accounts;
    private SaldoRepository $saldo;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
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
            $this->db       = $container->get(Connection::class);
            $this->service  = $container->get(InvoiceSettlementService::class);
            $this->journal  = $container->get(JournalEntryRepository::class);
            $this->periods  = $container->get(AccountingPeriodRepository::class);
            $this->accounts = $container->get(ChartOfAccountsRepository::class);
            $this->saldo    = $container->get(SaldoRepository::class);
            $seeder         = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->currencyId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/currency/user/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $seeder->seedForSupplier($this->supplierId);
        $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
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

    public function testDefaultAccountsFromPostingRules(): void
    {
        self::assertSame('355', $this->service->defaultAccount($this->supplierId, 'invoice')['account_code']);
        self::assertSame('365', $this->service->defaultAccount($this->supplierId, 'purchase_invoice')['account_code']);
    }

    public function testSaleInvoiceSettlementPostsAgainstChosenAccount(): void
    {
        $client = $this->client('Odběratel', true, false);
        $invoiceId = $this->saleInvoice('FV-2099-500', $client, 12100.00);

        $res = $this->service->create($this->supplierId, 'invoice', $invoiceId, [
            'settled_on' => self::YEAR . '-06-30',
            'amount'     => 12100.00,
            'account_id' => $this->accountId('355'),
            'note'       => 'Zápočet za společníkem',
        ], $this->userId);

        self::assertSame('confirmed', $res['status']);
        $byAcc = $this->linesByAccountCode((int) $res['journal_entry_id']);
        self::assertEqualsWithDelta(12100.00, $byAcc['355']['debit'], 0.001);
        self::assertEqualsWithDelta(12100.00, $byAcc['311']['credit'], 0.001);

        $inv = $this->invoiceRow($invoiceId);
        self::assertSame('paid', $inv['status']);
        self::assertEqualsWithDelta(12100.00, (float) $inv['paid_total'], 0.001);

        $payments = $this->paymentRows($invoiceId);
        self::assertCount(1, $payments);
        self::assertSame('settlement', $payments[0]['source']);
    }

    public function testPartialSettlementOfSaleInvoice(): void
    {
        $client = $this->client('Odběratel', true, false);
        $invoiceId = $this->saleInvoice('FV-2099-501', $client, 10000.00);

        $this->service->create($this->supplierId, 'invoice', $invoiceId, [
            'settled_on' => self::YEAR . '-06-30', 'amount' => 4000.00, 'account_id' => $this->accountId('355'),
        ], $this->userId);

        $inv = $this->invoiceRow($invoiceId);
        self::assertEqualsWithDelta(4000.00, (float) $inv['paid_total'], 0.001);
        self::assertNotSame('paid', $inv['status'], 'Částečný zápočet fakturu neuzavře.');

        // Zbytek nad rámec zůstatku musí selhat.
        try {
            $this->service->create($this->supplierId, 'invoice', $invoiceId, [
                'settled_on' => self::YEAR . '-06-30', 'amount' => 6500.00, 'account_id' => $this->accountId('355'),
            ], $this->userId);
            self::fail('Zápočet nad zbytek k úhradě musí selhat.');
        } catch (SettlementException $e) {
            self::assertSame('amount_over_remaining', $e->errorCode);
        }

        // Doplacení přesně na zbytek uzavře fakturu.
        $this->service->create($this->supplierId, 'invoice', $invoiceId, [
            'settled_on' => self::YEAR . '-06-30', 'amount' => 6000.00, 'account_id' => $this->accountId('355'),
        ], $this->userId);
        self::assertSame('paid', $this->invoiceRow($invoiceId)['status']);
    }

    public function testPurchaseInvoiceSettlementInFullAmount(): void
    {
        $vendor = $this->client('Dodavatel', false, true);
        $pfId = $this->purchaseInvoice('PF-2099-500', $vendor, 5000.00);

        $res = $this->service->create($this->supplierId, 'purchase_invoice', $pfId, [
            'settled_on' => self::YEAR . '-06-30', 'amount' => 5000.00, 'account_id' => $this->accountId('365'),
        ], $this->userId);

        $byAcc = $this->linesByAccountCode((int) $res['journal_entry_id']);
        self::assertEqualsWithDelta(5000.00, $byAcc['321']['debit'], 0.001);
        self::assertEqualsWithDelta(5000.00, $byAcc['365']['credit'], 0.001);
        self::assertSame('paid', $this->purchaseStatus($pfId));
    }

    /**
     * ČÁSTEČNÝ zápočet přijaté faktury. Doklad nemá `paid_total`, zbytek se dopočítává
     * ze všech kanálů úhrady ({@see PurchaseSettledExpr}) a `invoice_settlements` je
     * jedním z nich — druhý zápočet se tedy odečítá už od zbytku po prvním. Dřív směl
     * být zápočet jen v plné výši a účetní, která započítávala část, musela doklad
     * označit jako uhrazený ručně (a tím ho vyřadit z evidence úplně).
     */
    public function testPurchaseInvoicePartialSettlementKeepsDocumentOpen(): void
    {
        $vendor = $this->client('Dodavatel částečně', false, true);
        $pfId = $this->purchaseInvoice('PF-2099-501', $vendor, 5000.00);

        $first = $this->service->create($this->supplierId, 'purchase_invoice', $pfId, [
            'settled_on' => self::YEAR . '-06-30', 'amount' => 2000.00, 'account_id' => $this->accountId('365'),
        ], $this->userId);

        $byAcc = $this->linesByAccountCode((int) $first['journal_entry_id']);
        self::assertEqualsWithDelta(2000.00, $byAcc['321']['debit'], 0.001, 'Zaúčtuje se jen započtená část.');
        self::assertNotSame('paid', $this->purchaseStatus($pfId), 'Částečný zápočet doklad neuzavře.');

        // Víc, než je zbytek (3 000), projít nesmí.
        try {
            $this->service->create($this->supplierId, 'purchase_invoice', $pfId, [
                'settled_on' => self::YEAR . '-06-30', 'amount' => 3500.00, 'account_id' => $this->accountId('365'),
            ], $this->userId);
            self::fail('Zápočet nad zbytek k úhradě musí selhat.');
        } catch (SettlementException $e) {
            self::assertSame('amount_over_remaining', $e->errorCode);
        }

        // Doplacení přesně na zbytek doklad uzavře.
        $this->service->create($this->supplierId, 'purchase_invoice', $pfId, [
            'settled_on' => self::YEAR . '-06-30', 'amount' => 3000.00, 'account_id' => $this->accountId('365'),
        ], $this->userId);
        self::assertSame('paid', $this->purchaseStatus($pfId));

        // Na plně uhrazeném dokladu už není co započíst.
        try {
            $this->service->create($this->supplierId, 'purchase_invoice', $pfId, [
                'settled_on' => self::YEAR . '-06-30', 'amount' => 100.00, 'account_id' => $this->accountId('365'),
            ], $this->userId);
            self::fail('Zápočet na uhrazeném dokladu musí selhat.');
        } catch (SettlementException $e) {
            self::assertSame('doc_not_payable', $e->errorCode);
        }
    }

    /**
     * Storno JEDNOHO z několika zápočtů doklad zase otevře — a jen tehdy, když po vrácení
     * jeho částky opravdu zbytek vznikne. U dokladu, který zůstává uhrazený jiným kanálem,
     * by ho storno chybně otevřelo.
     */
    public function testCancelOfPartialSettlementReopensPurchaseInvoice(): void
    {
        $vendor = $this->client('Dodavatel storno', false, true);
        $pfId = $this->purchaseInvoice('PF-2099-502', $vendor, 5000.00);

        $first = $this->service->create($this->supplierId, 'purchase_invoice', $pfId, [
            'settled_on' => self::YEAR . '-06-30', 'amount' => 2000.00, 'account_id' => $this->accountId('365'),
        ], $this->userId);
        $second = $this->service->create($this->supplierId, 'purchase_invoice', $pfId, [
            'settled_on' => self::YEAR . '-06-30', 'amount' => 3000.00, 'account_id' => $this->accountId('365'),
        ], $this->userId);
        self::assertSame('paid', $this->purchaseStatus($pfId));

        $this->service->cancel($this->supplierId, (int) $second['id'], ['entry_date' => self::YEAR . '-06-30']);
        self::assertNotSame('paid', $this->purchaseStatus($pfId), 'Po stornu doplatku je doklad zase otevřený.');

        // Storno prvního (na už otevřeném dokladu) status nemění a nespadne.
        $this->service->cancel($this->supplierId, (int) $first['id'], ['entry_date' => self::YEAR . '-06-30']);
        self::assertNotSame('paid', $this->purchaseStatus($pfId));
    }

    /**
     * Zápočet musí vidět i SALDO — a to i tehdy, když stav dokladu o něm mlčí.
     *
     * Reálný nález: přijatá faktura vyrovnaná zápočtem proti 365 svítila v kontrole
     * úplnosti dokladů jako 168 dní po splatnosti, přestože 321 bylo na nulu.
     * `SaldoRepository` skládal poměr úhrady jen z bankovního párování a jinak se
     * spoléhal na `status='paid'`. Ten je u zápočtu nespolehlivý ze dvou stran:
     * doúčtování zápočtu bez účetní stopy stav dokladu záměrně nepřestavuje
     * ({@see \MyInvoice\Service\Accounting\InvoiceSettlementService}) a ČÁSTEČNÝ
     * zápočet ho na `paid` nepřeklápí vůbec.
     *
     * Hranice je časová: k datu PŘED zápočtem musí doklad zůstat otevřený, jinak by
     * dnešní zápočet zpětně přepsal loňské saldo.
     */
    public function testSettlementClosesPurchaseInvoiceInSaldoEvenWhenStatusDoesNot(): void
    {
        $vendor = $this->client('Dodavatel saldo', false, true);
        $pfId = $this->purchaseInvoice('PF-2099-700', $vendor, 5000.00);
        $this->postPurchasePredpis($pfId, 5000.00);

        $this->service->create($this->supplierId, 'purchase_invoice', $pfId, [
            'settled_on' => self::YEAR . '-06-30', 'amount' => 5000.00, 'account_id' => $this->accountId('365'),
        ], $this->userId);

        // Doklad, jehož status o zápočtu nic neříká — přesně stav z produkčního nálezu.
        $reset = $this->db->pdo()->prepare(
            'UPDATE purchase_invoices SET status = "received", paid_at = NULL WHERE id = ?'
        );
        $reset->execute([$pfId]);

        self::assertEqualsWithDelta(
            0.0,
            $this->purchasePaidRatio($pfId, self::YEAR . '-06-29'),
            0.0001,
            'Den před zápočtem je závazek pořád otevřený.'
        );
        // Vyrovnaný doklad ze seznamu otevřených položek MIZÍ — poměr 1,0 znamená
        // nulový zbytek a `SaldoRepository` uzavřené položky nevrací (dřív je vracel
        // a zahazoval je až `SaldoService`). Chování sestavy je totožné, mění se jen
        // to, kde padne rozhodnutí; test proto tvrdí totéž jinak vyjádřené.
        self::assertNull(
            $this->purchasePaidRatioOrNull($pfId, self::YEAR . '-06-30'),
            'Ode dne zápočtu je vyrovnaný (mimo otevřené položky), i když status dál tvrdí „received".'
        );
    }

    /** Částečný zápočet snižuje otevřenou položku o svou část, ne o celou fakturu. */
    public function testPartialSettlementReducesSaldoProportionally(): void
    {
        $vendor = $this->client('Dodavatel saldo částečně', false, true);
        $pfId = $this->purchaseInvoice('PF-2099-701', $vendor, 5000.00);
        $this->postPurchasePredpis($pfId, 5000.00);

        $this->service->create($this->supplierId, 'purchase_invoice', $pfId, [
            'settled_on' => self::YEAR . '-06-30', 'amount' => 2000.00, 'account_id' => $this->accountId('365'),
        ], $this->userId);

        self::assertNotSame('paid', $this->purchaseStatus($pfId));
        self::assertEqualsWithDelta(
            0.4,
            $this->purchasePaidRatio($pfId, self::YEAR . '-06-30'),
            0.0001,
            'Otevřený zbytek je 3 000 z 5 000, ne celá faktura.'
        );
    }

    /**
     * Doúčtování zápočtu, kterému chybí účetní zápis.
     *
     * Reálný případ: `invoice_settlements.journal_entry_id` má `ON DELETE SET NULL`, takže
     * hromadné přeúčtování deníku — které zápočty neumělo — vazbu tiše zruší. Zůstane
     * evidovaná úhrada bez zápisu: doklad tvrdí „uhrazeno", 321 je otevřené, v detailu
     * chybí proklik a uzávěrková kontrola hlásí díru, kterou uživatel nemá jak zavřít.
     */
    public function testPostMissingEntriesRepostsOrphanedSettlement(): void
    {
        $vendor = $this->client('Dodavatel osiřelý zápočet', false, true);
        $pfId = $this->purchaseInvoice('PF-2099-600', $vendor, 1500.00);

        $res = $this->service->create($this->supplierId, 'purchase_invoice', $pfId, [
            'settled_on' => self::YEAR . '-06-30', 'amount' => 1500.00, 'account_id' => $this->accountId('365'),
        ], $this->userId);
        $settlementId = (int) $res['id'];
        $originalEntry = (int) $res['journal_entry_id'];

        // Simulace hromadného přeúčtování: zápis zmizí, FK vazbu vynuluje.
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM journal_entry_lines WHERE entry_id = ?')->execute([$originalEntry]);
        $pdo->prepare('DELETE FROM journal_entries WHERE id = ?')->execute([$originalEntry]);
        $check = $pdo->prepare('SELECT journal_entry_id FROM invoice_settlements WHERE id = ?');
        $check->execute([$settlementId]);
        self::assertNull($check->fetchColumn(), 'FK ON DELETE SET NULL vazbu opravdu zruší.');

        $report = $this->service->postMissingEntries($this->supplierId, $this->userId);

        self::assertGreaterThanOrEqual(1, $report['candidates']);
        self::assertGreaterThanOrEqual(1, $report['posted']);
        self::assertSame(0, $report['failed'], implode(' | ', $report['errors']));

        $check->execute([$settlementId]);
        $newEntry = $check->fetchColumn();
        self::assertNotFalse($newEntry);
        self::assertNotNull($newEntry, 'Zápočet má zase účetní zápis.');

        $byAcc = $this->linesByAccountCode((int) $newEntry);
        self::assertEqualsWithDelta(1500.00, $byAcc['321']['debit'], 0.001, 'Doúčtuje se táž kontace jako původně.');
        self::assertEqualsWithDelta(1500.00, $byAcc['365']['credit'], 0.001);

        // Opakované spuštění už nemá co dělat — doúčtování je idempotentní.
        $again = $this->service->postMissingEntries($this->supplierId, $this->userId);
        self::assertSame(0, $again['posted']);
    }

    public function testCancelReversesEntryAndUndoesPayment(): void
    {
        $client = $this->client('Odběratel', true, false);
        $invoiceId = $this->saleInvoice('FV-2099-502', $client, 3000.00);
        $res = $this->service->create($this->supplierId, 'invoice', $invoiceId, [
            'settled_on' => self::YEAR . '-06-30', 'amount' => 3000.00, 'account_id' => $this->accountId('355'),
        ], $this->userId);

        $cancelled = $this->service->cancel($this->supplierId, (int) $res['id'], ['entry_date' => self::YEAR . '-06-30']);
        self::assertSame('cancelled', $cancelled['status']);
        self::assertNotNull($cancelled['reversal_entry_id']);

        // Původní zápis zůstává + má protizápis (storno, ne smazání).
        self::assertNotNull($this->journal->find((int) $res['journal_entry_id'], $this->supplierId));
        self::assertCount(0, $this->paymentRows($invoiceId));
        $inv = $this->invoiceRow($invoiceId);
        self::assertEqualsWithDelta(0.0, (float) $inv['paid_total'], 0.001);
        self::assertNotSame('paid', $inv['status']);

        // Druhé zrušení už selže.
        try {
            $this->service->cancel($this->supplierId, (int) $res['id'], []);
            self::fail('Druhé zrušení musí selhat.');
        } catch (SettlementException $e) {
            self::assertSame('already_cancelled', $e->errorCode);
        }
    }

    public function testCounterAccountCannotBeDocumentAccount(): void
    {
        $client = $this->client('Odběratel', true, false);
        $invoiceId = $this->saleInvoice('FV-2099-503', $client, 1000.00);

        try {
            $this->service->create($this->supplierId, 'invoice', $invoiceId, [
                'settled_on' => self::YEAR . '-06-30', 'amount' => 1000.00, 'account_id' => $this->accountId('311'),
            ], $this->userId);
            self::fail('Protiúčet 311 u vydané faktury musí selhat.');
        } catch (SettlementException $e) {
            self::assertSame('account_same_as_document', $e->errorCode);
        }
    }

    public function testForeignCurrencyRefused(): void
    {
        $eurId = (int) ($this->db->pdo()->query("SELECT id FROM currencies WHERE code = 'EUR' LIMIT 1")->fetchColumn() ?: 0);
        if ($eurId === 0) {
            self::markTestSkipped('EUR není v číselníku měn.');
        }
        $client = $this->client('Odběratel EU', true, false);
        $invoiceId = $this->saleInvoice('FV-2099-504', $client, 500.00, $eurId);

        try {
            $this->service->create($this->supplierId, 'invoice', $invoiceId, [
                'settled_on' => self::YEAR . '-06-30', 'amount' => 500.00, 'account_id' => $this->accountId('355'),
            ], $this->userId);
            self::fail('Cizoměnový doklad musí selhat.');
        } catch (SettlementException $e) {
            self::assertSame('foreign_currency', $e->errorCode);
        }
    }

    // ── Helpery ───────────────────────────────────────────────────────────────

    private function accountId(string $code): int
    {
        $account = $this->accounts->findByCode($this->supplierId, $code);
        self::assertNotNull($account, 'Účet ' . $code . ' chybí v osnově.');
        return (int) $account['id'];
    }

    /** @return array<string, array{debit:float, credit:float}> */
    private function linesByAccountCode(int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.account_code, l.side, l.amount
               FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.entry_id = ?'
        );
        $stmt->execute([$entryId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $code = (string) $row['account_code'];
            $out[$code] ??= ['debit' => 0.0, 'credit' => 0.0];
            $out[$code][(string) $row['side']] += (float) $row['amount'];
        }
        return $out;
    }

    private function client(string $name, bool $customer, bool $vendor): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "CZ12345678", "test@example.com", "cs", ?, ?, ?)'
        );
        $stmt->execute([$this->supplierId, $name, $this->czId, $this->currencyId, $customer ? 1 : 0, $vendor ? 1 : 0]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function saleInvoice(string $varsymbol, int $clientId, float $total, ?int $currencyId = null): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 paid_total, status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, 0, ?, 0, ?, 0, "issued", "1", ?)'
        );
        $issue = self::YEAR . '-06-10';
        $stmt->execute([
            $this->supplierId, $varsymbol, $clientId, $issue, $issue, $issue,
            $currencyId ?? $this->currencyId, $total, $total, $this->userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function purchaseInvoice(string $number, int $vendorId, float $total): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                 vat_deduction, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, 0, "{}", ?, 0, ?, "received", "40", "full", ?)'
        );
        $issue = self::YEAR . '-06-10';
        $stmt->execute([
            $this->supplierId, $vendorId, $number, $issue, $issue, $issue, $issue,
            $this->currencyId, $total, $total, $this->userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function invoiceRow(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT status, paid_total, amount_to_pay FROM invoices WHERE id = ?');
        $stmt->execute([$id]);
        return (array) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    private function paymentRows(int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, source, amount FROM invoice_payments WHERE invoice_id = ?');
        $stmt->execute([$invoiceId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function purchaseStatus(int $id): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT status FROM purchase_invoices WHERE id = ?');
        $stmt->execute([$id]);
        return (string) $stmt->fetchColumn();
    }

    /** Předpis přijaté faktury (501 MD / 321 D) — bez něj doklad na saldokontě vůbec není. */
    private function postPurchasePredpis(int $pfId, float $amount): int
    {
        $map = $this->accounts->codeToIdMap($this->supplierId);
        $periodId = (int) ($this->periods->findByYear($this->supplierId, self::YEAR)['id'] ?? 0);

        return $this->journal->insert([
            'supplier_id' => $this->supplierId,
            'period_id'   => $periodId,
            'entry_date'  => self::YEAR . '-06-10',
            'document_no' => 'PREDPIS-PF-' . $pfId,
            'description' => 'Předpis přijaté faktury',
            'source_type' => 'purchase_invoice',
            'source_id'   => $pfId,
            'posted_at'   => date('Y-m-d H:i:s'),
            'posted_by'   => $this->userId,
        ], [
            ['account_id' => $map['501']['id'], 'side' => 'debit', 'amount' => $amount],
            ['account_id' => $map['321']['id'], 'side' => 'credit', 'amount' => $amount],
        ]);
    }

    /** `paid_ratio` přijaté faktury na účtu 321 tak, jak ho k datu vidí saldo. */
    private function purchasePaidRatio(int $pfId, string $asOf): float
    {
        $ratio = $this->purchasePaidRatioOrNull($pfId, $asOf);
        if ($ratio === null) {
            self::fail('Přijatá faktura ' . $pfId . ' není k ' . $asOf . ' mezi otevřenými položkami 321.');
        }

        return $ratio;
    }

    /** Totéž, ale `null` = doklad je k datu vyrovnaný, takže mezi otevřenými položkami není. */
    private function purchasePaidRatioOrNull(int $pfId, string $asOf): ?float
    {
        $account = $this->saldo->resolveAccount($this->supplierId, '321');
        self::assertNotNull($account, 'Účet 321 chybí v osnově.');

        foreach ($this->saldo->openItems($this->supplierId, (int) $account['id'], $asOf, '321') as $item) {
            if ($item['doc_type'] === 'purchase_invoice' && (int) $item['doc_id'] === $pfId) {
                return (float) $item['paid_ratio'];
            }
        }

        return null;
    }
}
