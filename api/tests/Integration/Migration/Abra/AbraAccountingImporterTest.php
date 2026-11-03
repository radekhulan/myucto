<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Migration\Abra;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AbraImportRepository;
use MyInvoice\Service\Accounting\TakenOverRecord;
use MyInvoice\Service\Migration\Abra\AbraAccountingImporter;
use MyInvoice\Service\Migration\Abra\AbraCatalogImporter;
use MyInvoice\Service\Migration\Shared\BankStatementImportWriter;
use MyInvoice\Service\Migration\Shared\MigratedDocumentWriter;
use MyInvoice\Service\Migration\Shared\MigratedPaymentWriter;
use MyInvoice\Service\Migration\Shared\MigratedPurchaseDocument;
use MyInvoice\Service\Report\VatLedgerService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class AbraAccountingImporterTest extends TestCase
{
    private Connection $db;
    private AbraAccountingImporter $importer;
    private AbraCatalogImporter $catalogImporter;
    private int $supplier;
    private int $user;
    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->importer = $container->get(AbraAccountingImporter::class);
        $this->catalogImporter = $container->get(AbraCatalogImporter::class);
        $pdo = $this->db->pdo();
        $base = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->user = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $base);
        self::assertGreaterThan(0, $this->user);
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO supplier (company_name, ic, street, city, zip, country_id, email, default_currency_id,
            default_vat_rate_id, accounting_enabled, accounting_mode)
            SELECT "Synthetic ABRA target", "88888888", "Testovací", "Praha", "11000", country_id,
                "abra-target@example.com", default_currency_id, default_vat_rate_id, 1, "double_entry" FROM supplier WHERE id = ?')->execute([$base]);
        $this->supplier = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
            VALUES (?, "CZK", "CZK", "Kč", "Koruna", "Koruna", 2, 1, 1)')->execute([$this->supplier]);
        $currency = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([$currency, $this->supplier]);
    }
    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
    }
    private function transfer(array $snapshot): array
    {
        return $this->importer->import($this->supplier, $this->user, $snapshot, [2025], static function (): void {}, static fn (): bool => false);
    }
    private function snapshot(): array
    {
        $period = ['id' => '1', 'kod' => '2025', 'platiOdData' => '2025-01-01', 'platiDoData' => '2025-12-31'];
        $entries = [
            ['idUcetniDenik' => '1', 'datUcto' => '2025-06-01', 'mdUcet' => 'code:311000', 'dalUcet' => 'code:602000', 'sumTuz' => '100.00', 'idDokl@evidencePath' => 'faktura-vydana/101'],
            ['idUcetniDenik' => '2', 'datUcto' => '2025-06-01', 'mdUcet' => 'code:311000', 'dalUcet' => 'code:343000', 'sumTuz' => '21.00', 'idDokl@evidencePath' => 'faktura-vydana/101'],
            ['idUcetniDenik' => '3', 'datUcto' => '2025-06-02', 'mdUcet' => 'code:221000', 'dalUcet' => 'code:311000', 'sumTuz' => '121.00', 'idDokl@evidencePath' => 'banka/301'],
        ];
        return [
            '_meta' => ['mode' => 'initial', 'company' => ['ico' => '88888888', 'base_currency' => 'CZK'], 'periods' => [$period]],
            'ucetni-obdobi' => [$period],
            'ucetni-osnova' => array_map(static fn ($code) => ['id' => $code, 'kod' => $code, 'nazev' => 'Synthetic account'], ['221', '311', '343', '602', '221000', '311000', '343000', '602000']),
            'ucetni-denik' => $entries,
            'pohyb-na-uctech' => array_map(static fn ($row) => $row + ['sumTuzMd' => $row['sumTuz'], 'sumTuzDal' => $row['sumTuz']], $entries),
            'faktura-vydana' => [[
                'id' => '101', 'kod' => 'SYN-2025-001', 'datVyst' => '2025-06-01', 'datUcto' => '2025-06-01', 'datSplat' => '2025-06-15',
                'mena' => 'code:CZK', 'sumZklCelkem' => '100.00', 'sumDphCelkem' => '21.00', 'sumCelkem' => '121.00', 'zuctovano' => true,
                'nazFirmy' => 'Synthetic partner', 'firma' => ['id' => '7', 'evidencePath' => 'adresar/7'], 'stat' => 'code:CZ',
                'polozkyDokladu' => [['id' => '201', 'popis' => 'Synthetic item', 'mnozMj' => '1', 'szbDph' => '21', 'sumZkl' => '100.00', 'sumDph' => '21.00', 'sumCelkem' => '121.00', 'clenDph' => 'code:01-02']],
            ]],
            'faktura-prijata' => [], 'pokladni-pohyb' => [],
            'banka' => [['id' => '301', 'kod' => 'SYN-B-001', 'datUcto' => '2025-06-02', 'mena' => 'code:CZK',
                'sumCelkem' => '121.00', 'typPohybuK' => 'typPohybu.prijem', 'banka' => ['id' => '1', 'evidencePath' => 'bankovni-ucet/1'],
                'bankaUcet' => '1000000005', 'bankaKod' => '0100']],
            'vazba' => [['id' => '401', 'a' => ['id' => '101', 'evidencePath' => 'faktura-vydana/101'],
                'b' => ['id' => '301', 'evidencePath' => 'banka/301'], 'castka' => '121.00', 'mena' => 'code:CZK']],
        ];
    }
    private function countRows(string $table): int
    {
        $stmt = $this->db->pdo()->prepare(($table === 'bank_transactions' ? 'SELECT COUNT(*) FROM bank_transactions bt JOIN bank_statements bs ON bs.id = bt.statement_id WHERE bs.supplier_id = ?' : 'SELECT COUNT(*) FROM ' . $table . ' WHERE supplier_id = ?'));
        $stmt->execute([$this->supplier]);
        return (int) $stmt->fetchColumn();
    }

    public function test504IntervalInsideImportedYearBlocksBeforeAnyMutation(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO supplier_accounting_frameworks (supplier_id, effective_from, accounting_framework) VALUES (?, '2025-01-01', 'cz_500'), (?, '2025-06-01', 'cz_504'), (?, '2025-09-01', 'cz_500')")
            ->execute([$this->supplier, $this->supplier, $this->supplier]);
        $snapshot = $this->snapshot();
        $snapshot['_meta']['source_oss'] = ['eu' => true, 'valid_from' => '2025-01-01'];
        $before = $this->frameworkGateState();
        $report = $this->transfer($snapshot);
        self::assertSame($before, $this->frameworkGateState(), 'Unsupported framework must not write documents, settings or import maps.');
        self::assertTrue($report['blocked']);
        self::assertContains('accounting_framework_import_unsupported', $report['warnings']);
    }

    public function test500ImportedYearIsIndependentOfLater504Framework(): void
    {
        $this->db->pdo()->prepare("INSERT INTO supplier_accounting_frameworks (supplier_id, effective_from, accounting_framework) VALUES (?, '2026-01-01', 'cz_504')")->execute([$this->supplier]);
        $report = $this->transfer($this->snapshot());
        self::assertFalse($report['blocked'], json_encode($report));
        self::assertSame(0, $report['failed']);
        self::assertSame(3, $this->countRows('journal_entries'));
        self::assertSame(1, $this->countRows('invoices'));
    }

    private function frameworkGateState(): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM supplier WHERE id = ?');
        $stmt->execute([$this->supplier]);
        $state = ['supplier' => $stmt->fetch(\PDO::FETCH_ASSOC)];
        foreach (['accounting_periods', 'chart_of_accounts', 'journal_entries', 'invoices', 'purchase_invoices', 'clients',
            'bank_statements', 'cash_documents', 'abra_flexi_import_map', 'activity_log'] as $table) {
            $stmt = $this->db->pdo()->prepare('SELECT * FROM ' . $table . ' WHERE supplier_id = ?');
            $stmt->execute([$this->supplier]);
            $state[$table] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }
        return $state;
    }

    public function testPaymentLinkCursorOnlyUsesNumericMappedLinksForSelectedSupplier(): void
    {
        $runs = new AbraImportRepository($this->db);
        $hash = hash('sha256', 'synthetic-link');
        self::assertSame(0, $runs->maxMappedLinkId($this->supplier));
        $runs->remember($this->supplier, 'vazba', '12', $hash, 'payment_link', 1, 2025);
        $runs->remember($this->supplier, 'vazba', 'code:ignored', $hash, 'payment_link', 2, 2025);
        $runs->remember($this->supplier, 'banka', '999', $hash, 'bank_transaction', 3, 2025);
        self::assertSame(12, $runs->maxMappedLinkId($this->supplier));
    }
    public function testImportsLinkedDocumentBankAndJournalAndRepeatsWithoutDuplicates(): void
    {
        $snapshot = $this->snapshot();
        $report = $this->transfer($snapshot);
        self::assertFalse($report['blocked'], json_encode($report));
        self::assertSame(0, $report['failed']);
        self::assertSame(3, $this->countRows('journal_entries'));
        $audit = $this->db->pdo()->prepare('SELECT COUNT(*) FROM activity_log a
            JOIN journal_entries e ON e.id = a.entity_id AND e.supplier_id = a.supplier_id
            WHERE e.supplier_id = ? AND a.entity_type = "journal_entry"
              AND a.action = "migration.abra.journal_imported"');
        $audit->execute([$this->supplier]);
        self::assertSame(3, (int) $audit->fetchColumn());
        self::assertSame(1, $this->countRows('invoices'));
        self::assertSame(1, $this->countRows('bank_transactions'));
        self::assertSame(1, $this->countRows('payment_matches'));
        $stmt = $this->db->pdo()->prepare('SELECT status, total_with_vat, paid_total, booked_at FROM invoices WHERE supplier_id = ?');
        $stmt->execute([$this->supplier]);
        $invoice = $stmt->fetch();
        self::assertSame('paid', $invoice['status']);
        self::assertNotNull($invoice['booked_at']);
        self::assertEquals(121.0, (float) $invoice['total_with_vat']);
        self::assertEquals(121.0, (float) $invoice['paid_total']);
        $itemCode = $this->db->pdo()->query('SELECT vat_classification_code FROM invoice_items WHERE invoice_id = '
            . (int) $this->db->pdo()->query('SELECT id FROM invoices WHERE supplier_id = ' . $this->supplier)->fetchColumn())->fetchColumn();
        self::assertSame('1', $itemCode);
        self::assertSame(0, $this->countRows('stock_documents'));
        $again = $this->transfer($snapshot);
        self::assertFalse($again['blocked'], json_encode($again));
        self::assertSame(0, $again['created']);
        self::assertSame(3, $this->countRows('journal_entries'));
        $audit->execute([$this->supplier]);
        self::assertSame(3, (int) $audit->fetchColumn());
        self::assertSame(1, $this->countRows('invoices'));
    }

    public function testPartialCreditNoteRefundRemainsOutstanding(): void
    {
        $snapshot = $this->snapshot();
        $invoice = &$snapshot['faktura-vydana'][0];
        $invoice['sumZklCelkem'] = '-100.00';
        $invoice['sumDphCelkem'] = '-21.00';
        $invoice['sumCelkem'] = '-121.00';
        $invoice['polozkyDokladu'][0]['sumZkl'] = '-100.00';
        $invoice['polozkyDokladu'][0]['sumDph'] = '-21.00';
        $invoice['polozkyDokladu'][0]['sumCelkem'] = '-121.00';
        unset($invoice);
        $snapshot['banka'][0]['sumCelkem'] = '-50.00';
        $snapshot['banka'][0]['typPohybuK'] = 'typPohybu.vydej';
        $snapshot['vazba'][0]['castka'] = '50.00';
        $snapshot['ucetni-denik'][0]['sumTuz'] = '-100.00';
        $snapshot['ucetni-denik'][1]['sumTuz'] = '-21.00';
        $snapshot['ucetni-denik'][2]['mdUcet'] = 'code:311000';
        $snapshot['ucetni-denik'][2]['dalUcet'] = 'code:221000';
        $snapshot['ucetni-denik'][2]['sumTuz'] = '50.00';
        $snapshot['pohyb-na-uctech'] = array_map(static fn (array $row): array => $row
            + ['sumTuzMd' => $row['sumTuz'], 'sumTuzDal' => $row['sumTuz']], $snapshot['ucetni-denik']);
        foreach ($snapshot['pohyb-na-uctech'] as &$row) {
            $row['sumTuzMd'] = $row['sumTuzDal'] = $row['sumTuz'];
        }
        unset($row);

        $report = $this->transfer($snapshot);

        self::assertFalse($report['blocked'], json_encode($report));
        $invoice = $this->db->pdo()->query("SELECT invoice_type, status, total_with_vat, paid_total
            FROM invoices WHERE supplier_id = {$this->supplier}")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('credit_note', $invoice['invoice_type']);
        self::assertSame('sent', $invoice['status']);
        self::assertEquals(-121.0, (float) $invoice['total_with_vat']);
        self::assertEquals(50.0, (float) $invoice['paid_total']);
    }

    public function testPartialPurchaseCreditNoteRefundRemainsOutstanding(): void
    {
        $snapshot = $this->snapshot();
        $purchase = $snapshot['faktura-vydana'][0];
        $purchase['id'] = '701';
        $purchase['kod'] = 'SYN-PF-CREDIT';
        $purchase['cisDosle'] = 'SYN-VENDOR-CREDIT';
        $purchase['sumZklCelkem'] = '-100.00';
        $purchase['sumDphCelkem'] = '-21.00';
        $purchase['sumCelkem'] = '-121.00';
        $purchase['polozkyDokladu'][0]['sumZkl'] = '-100.00';
        $purchase['polozkyDokladu'][0]['sumDph'] = '-21.00';
        $purchase['polozkyDokladu'][0]['sumCelkem'] = '-121.00';
        $purchase['polozkyDokladu'][0]['clenDph'] = 'code:40-41';
        $snapshot['faktura-vydana'] = [];
        $snapshot['faktura-prijata'] = [$purchase];
        $snapshot['banka'][0]['sumCelkem'] = '50.00';
        $snapshot['vazba'][0]['a'] = ['id' => '701', 'evidencePath' => 'faktura-prijata/701'];
        $snapshot['vazba'][0]['castka'] = '50.00';
        $snapshot['ucetni-denik'][0]['sumTuz'] = '-100.00';
        $snapshot['ucetni-denik'][1]['sumTuz'] = '-21.00';
        foreach ($snapshot['ucetni-denik'] as &$entry) {
            if ($entry['idUcetniDenik'] !== '3') $entry['idDokl@evidencePath'] = 'faktura-prijata/701';
        }
        unset($entry);
        $snapshot['pohyb-na-uctech'] = array_map(static fn (array $row): array => $row + [
            'sumTuzMd' => $row['sumTuz'], 'sumTuzDal' => $row['sumTuz'],
        ], $snapshot['ucetni-denik']);
        foreach ($snapshot['pohyb-na-uctech'] as &$row) {
            $row['sumTuzMd'] = $row['sumTuzDal'] = $row['sumTuz'];
        }
        unset($row);

        $report = $this->transfer($snapshot);

        self::assertFalse($report['blocked'], json_encode($report));
        $purchase = $this->db->pdo()->query("SELECT document_kind, status, total_with_vat,
            paid_amount_invoice_ccy, paid_at FROM purchase_invoices WHERE supplier_id = {$this->supplier}")
            ->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('credit_note', $purchase['document_kind']);
        self::assertSame('booked', $purchase['status']);
        self::assertEquals(-121.0, (float) $purchase['total_with_vat']);
        self::assertEquals(50.0, (float) $purchase['paid_amount_invoice_ccy']);
        self::assertNull($purchase['paid_at']);
    }

    public function testPaymentLinkCurrencyMustMatchDocumentAndMovement(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['vazba'][0]['mena'] = 'code:EUR';
        $snapshot['vazba'][0]['castkaMen'] = '121.00';

        $report = $this->transfer($snapshot);

        self::assertFalse($report['blocked'], json_encode($report));
        self::assertContains('payment_currency_conversion_requires_review', $report['warnings']);
        self::assertSame(0, $this->countRows('payment_matches'));
        $invoice = $this->db->pdo()->query("SELECT status FROM invoices WHERE supplier_id = {$this->supplier}")->fetchColumn();
        self::assertSame('sent', $invoice);
    }

    public function testPartialCashAllocationDoesNotSettleWholePurchase(): void
    {
        $snapshot = $this->snapshot();
        $purchase = $snapshot['faktura-vydana'][0];
        $purchase['id'] = '701';
        $purchase['kod'] = 'SYN-PURCHASE-CASH';
        $purchase['cisDosle'] = 'SYN-VENDOR-CASH';
        $purchase['polozkyDokladu'][0]['clenDph'] = 'code:40-41';
        $snapshot['faktura-prijata'] = [$purchase];
        $snapshot['pokladni-pohyb'] = [[
            'id' => '501', 'kod' => 'SYN-CASH-501', 'datUcto' => '2025-06-02',
            'mena' => 'code:CZK', 'sumCelkem' => '200.00', 'typPohybuK' => 'typPohybu.vydej',
            'pokladna' => ['id' => '1', 'evidencePath' => 'pokladna/1'],
        ]];
        $snapshot['vazba'][] = [
            'id' => '402', 'a' => ['id' => '701', 'evidencePath' => 'faktura-prijata/701'],
            'b' => ['id' => '501', 'evidencePath' => 'pokladni-pohyb/501'],
            'castka' => '60.00', 'mena' => 'code:CZK',
        ];

        $report = $this->transfer($snapshot);

        self::assertFalse($report['blocked'], json_encode($report));
        self::assertContains('payment_cash_allocation_requires_review', $report['warnings']);
        $paid = $this->db->pdo()->query("SELECT paid_amount_invoice_ccy FROM purchase_invoices
            WHERE supplier_id = {$this->supplier} AND varsymbol = 'SYN-PURCHASE-CASH'")->fetchColumn();
        self::assertEquals(0.0, (float) $paid);
    }

    public function testSyncDoesNotReactivateLocallyCancelledInvoice(): void
    {
        $snapshot = $this->snapshot();
        self::assertFalse($this->transfer($snapshot)['blocked']);
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'cancelled' WHERE supplier_id = ?")
            ->execute([$this->supplier]);
        $movement = $snapshot['banka'][0];
        $movement['id'] = '302';
        $movement['kod'] = 'SYN-B-002';
        $movement['sumCelkem'] = '1.00';
        $snapshot['banka'][] = $movement;
        $snapshot['vazba'][] = [
            'id' => '402', 'a' => ['id' => '101', 'evidencePath' => 'faktura-vydana/101'],
            'b' => ['id' => '302', 'evidencePath' => 'banka/302'],
            'castka' => '1.00', 'mena' => 'code:CZK',
        ];

        $report = $this->transfer($snapshot);

        self::assertFalse($report['blocked'], json_encode($report));
        self::assertContains('payment_cancelled_document_requires_review', $report['warnings']);
        $status = $this->db->pdo()->query("SELECT status FROM invoices WHERE supplier_id = {$this->supplier}")->fetchColumn();
        self::assertSame('cancelled', $status);
    }

    public function testDistinctSourceCashRegistersAreNotMerged(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['pokladni-pohyb'] = [
            ['id' => '501', 'kod' => 'SYN-CASH-A', 'datUcto' => '2025-06-02',
                'mena' => 'code:CZK', 'sumCelkem' => '10.00',
                'pokladna' => ['id' => '1', 'evidencePath' => 'pokladna/1']],
            ['id' => '502', 'kod' => 'SYN-CASH-B', 'datUcto' => '2025-06-02',
                'mena' => 'code:CZK', 'sumCelkem' => '20.00',
                'pokladna' => ['id' => '2', 'evidencePath' => 'pokladna/2']],
        ];

        $report = $this->transfer($snapshot);

        self::assertTrue($report['blocked']);
        self::assertContains('cash_register_mapping_ambiguous', $report['warnings']);
        self::assertSame(0, $this->countRows('cash_documents'));
    }

    public function testRoundedPurchasePaymentsSettleAtPayableAmount(): void
    {
        $pdo = $this->db->pdo();
        $supplier = $pdo->query("SELECT country_id, default_currency_id, default_vat_rate_id
            FROM supplier WHERE id = {$this->supplier}")->fetch(\PDO::FETCH_ASSOC);
        $pdo->prepare('INSERT INTO clients
            (supplier_id, company_name, street, city, zip, country_id, main_email,
             currency_default_id, vat_rate_default_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            $this->supplier, 'Synthetic partner', 'Testovací 1', 'Vzorov', '10000',
            $supplier['country_id'], 'partner@example.invalid', $supplier['default_currency_id'],
            $supplier['default_vat_rate_id'],
        ]);
        $clientId = (int) $pdo->lastInsertId();
        $documents = new MigratedDocumentWriter($this->db);
        $create = fn (string $number): int => $documents->insertPurchase(new MigratedPurchaseDocument(
            $this->supplier, $clientId, true, $number, $number, 'invoice',
            '2025-03-01', '2025-03-01', '2025-03-14', '2025-03-01', 'import',
            (int) $supplier['default_currency_id'], null, false, false, '{}',
            100.0, 21.2, 121.2, -0.2, 'booked', 'none', null, null, $this->user,
        ));
        $bankPurchase = $create('SYN-ROUND-BANK');
        $cashPurchase = $create('SYN-ROUND-CASH');
        $bank = new BankStatementImportWriter($this->db, 'abra-test');
        $statement = $bank->createStatement($this->supplier, 'SYN-ROUND', 'SYN-ROUND',
            '1000000005', '0100', 'CZK', '2025-03-02', $this->user);
        $transaction = $bank->insertTransaction($this->supplier, $statement, 'SYN-ROUND-BANK', [
            'source_ref' => 'SYN-ROUND-BANK', 'posted_at' => '2025-03-02', 'amount' => '121.00',
            'currency' => 'CZK', 'bank_ref' => null,
        ]);
        $attach = new \ReflectionMethod(AbraAccountingImporter::class, 'attachBankPayment');
        $attach->invoke($this->importer, $this->supplier, $this->user, 'purchase',
            $bankPurchase, $transaction, 121.0, 'CZK');
        $bankStatus = $pdo->query("SELECT status FROM purchase_invoices WHERE id = {$bankPurchase}")->fetchColumn();
        self::assertSame('paid', $bankStatus);

        $payments = new MigratedPaymentWriter($this->db);
        $register = $payments->cashRegister($this->supplier, 'Synthetic register');
        $cash = $payments->insertCash($this->supplier, $register, 'SYN-ROUND-CASH',
            '2025-03-02', 'Rounded payment', 121.0, true, $this->user);
        $payments->attach($this->supplier, $this->user, 'purchase', 'cash', $cashPurchase, $cash, 121.0);
        (new AbraImportRepository($this->db))->remember($this->supplier, 'pokladni-pohyb', '501',
            hash('sha256', 'synthetic-cash'), 'cash_document', $cash, 2025);
        $refresh = new \ReflectionMethod(AbraAccountingImporter::class, 'refreshCashPaymentBalances');
        $refresh->invoke($this->importer, $this->supplier, $payments);
        $cashStatus = $pdo->query("SELECT status FROM purchase_invoices WHERE id = {$cashPurchase}")->fetchColumn();
        self::assertSame('paid', $cashStatus);
    }

    public function testInvalidAssetRollsBackWholeAccountingImport(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['majetek'] = [['id' => '900', 'kod' => 'SYN-ASSET-900', 'nazev' => 'Invalid asset']];
        $report = $this->transfer($snapshot);
        self::assertTrue($report['blocked']);
        self::assertGreaterThan(0, $report['failed']);
        self::assertSame(0, $this->countRows('invoices'));
        self::assertSame(0, $this->countRows('journal_entries'));
    }

    public function testDocumentSnapshotKeepsHistoricalPartnerName(): void
    {
        $source = $this->snapshot();
        $report = $this->transfer($source);
        self::assertFalse($report['blocked'], json_encode($report));
        $snapshot = $this->db->pdo()->query("SELECT client_snapshot FROM invoices WHERE supplier_id = {$this->supplier}")->fetchColumn();
        $partner = json_decode((string) $snapshot, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Synthetic partner', $partner['company_name'] ?? null);
        self::assertSame('CZ', $partner['country_iso2'] ?? null);
        $legacy = json_encode((new \MyInvoice\Service\Migration\Abra\AbraDocumentMapper())
            ->map($source['faktura-vydana'][0], 'faktura-vydana')['partner'], JSON_THROW_ON_ERROR);
        $this->db->pdo()->prepare('UPDATE invoices SET client_snapshot = ? WHERE supplier_id = ?')
            ->execute([$legacy, $this->supplier]);
        self::assertFalse($this->transfer($source)['blocked']);
        $repaired = $this->db->pdo()->query("SELECT client_snapshot FROM invoices WHERE supplier_id = {$this->supplier}")->fetchColumn();
        self::assertSame('Synthetic partner', json_decode((string) $repaired, true)['company_name'] ?? null);
    }

    public function testIssuedDocumentNumberStaysSeparateFromPaymentSymbol(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['faktura-vydana'][0]['kod'] = 'SYN/2025/001';
        $snapshot['faktura-vydana'][0]['varSym'] = '1234567890';
        $report = $this->transfer($snapshot);
        self::assertFalse($report['blocked'], json_encode($report));
        $invoice = $this->db->pdo()->query("SELECT varsymbol, payment_variable_symbol
            FROM invoices WHERE supplier_id = {$this->supplier}")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('SYN/2025/001', $invoice['varsymbol']);
        self::assertSame('1234567890', $invoice['payment_variable_symbol']);
        $this->db->pdo()->prepare("UPDATE invoices SET varsymbol = '1234567890' WHERE supplier_id = ?")
            ->execute([$this->supplier]);
        self::assertFalse($this->transfer($snapshot)['blocked']);
        $number = $this->db->pdo()->query("SELECT varsymbol FROM invoices WHERE supplier_id = {$this->supplier}")->fetchColumn();
        self::assertSame('SYN/2025/001', $number);
        $sourceKey = \MyInvoice\Service\Migration\Abra\AbraSource::sourceKey($snapshot['faktura-vydana'][0]);
        $legacyCollision = '1234567890-' . substr(hash('sha256', $sourceKey), 0, 8);
        $this->db->pdo()->prepare('UPDATE invoices SET varsymbol = ? WHERE supplier_id = ?')
            ->execute([$legacyCollision, $this->supplier]);
        self::assertFalse($this->transfer($snapshot)['blocked']);
        $number = $this->db->pdo()->query("SELECT varsymbol FROM invoices WHERE supplier_id = {$this->supplier}")->fetchColumn();
        self::assertSame('SYN/2025/001', $number);
    }

    public function testForeignBankJournalStoresCurrencyForClosing(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['ucetni-denik'][2]['mena'] = 'code:EUR';
        $snapshot['ucetni-denik'][2]['sumMen'] = '5.00';
        $report = $this->transfer($snapshot);
        self::assertFalse($report['blocked'], json_encode($report));
        $line = $this->db->pdo()->query("SELECT l.currency_code, l.amount_foreign, l.fx_rate
            FROM abra_flexi_import_map m JOIN journal_entry_lines l ON l.entry_id = m.target_id
            JOIN chart_of_accounts a ON a.id = l.account_id
            WHERE m.supplier_id = {$this->supplier} AND m.kind = 'ucetni-denik'
              AND m.abra_key = '3' AND a.account_code = '221.000'")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('EUR', $line['currency_code']);
        self::assertSame('5.00', $line['amount_foreign']);
        self::assertSame('24.200000', $line['fx_rate']);
    }

    public function testCompletedImportMarksBankMovementWithoutSourceJournalForReview(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['banka'][] = [
            'id' => '302', 'kod' => 'SYN-B-002', 'datUcto' => '2025-06-03', 'mena' => 'code:CZK',
            'sumCelkem' => '50.00', 'typPohybuK' => 'typPohybu.prijem',
            'banka' => ['id' => '1', 'evidencePath' => 'bankovni-ucet/1'],
            'bankaUcet' => '1000000005', 'bankaKod' => '0100',
        ];

        $report = $this->transfer($snapshot);
        self::assertFalse($report['blocked'], json_encode($report));
        $row = $this->db->pdo()->query("SELECT bt.match_status, bt.match_reason FROM abra_flexi_import_map m
            JOIN bank_transactions bt ON bt.id = m.target_id
            WHERE m.supplier_id = {$this->supplier} AND m.kind = 'banka' AND m.abra_key = '302'")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('ignored', $row['match_status']);
        self::assertSame('migration_review', $row['match_reason']);
        $supplier = $this->db->pdo()->query("SELECT accounting_starts_on, accounting_activation_status
            FROM supplier WHERE id = {$this->supplier}")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('2025-01-01', $supplier['accounting_starts_on']);
        self::assertSame('completed', $supplier['accounting_activation_status']);
    }

    public function testEquivalentImportedBankMovementCanRefreshLegacyHashButChangedAmountBlocks(): void
    {
        $snapshot = $this->snapshot();
        $initial = $this->transfer($snapshot);
        self::assertFalse($initial['blocked'], json_encode($initial));
        $this->db->pdo()->prepare("UPDATE abra_flexi_import_map SET source_hash = ?
            WHERE supplier_id = ? AND kind = 'banka' AND abra_key = '301'")
            ->execute([hash('sha256', 'legacy-bank-shape'), $this->supplier]);

        $same = $this->transfer($snapshot);
        self::assertFalse($same['blocked'], json_encode($same));
        self::assertSame(0, $same['changed']);
        $mapped = $this->db->pdo()->query("SELECT source_hash FROM abra_flexi_import_map
            WHERE supplier_id = {$this->supplier} AND kind = 'banka' AND abra_key = '301'")->fetchColumn();
        self::assertSame(\MyInvoice\Service\Migration\Abra\AbraSource::movementHash($snapshot['banka'][0]), $mapped);

        $snapshot['banka'][0]['sumCelkem'] = '122.00';
        $changed = $this->transfer($snapshot);
        self::assertTrue($changed['blocked']);
        self::assertGreaterThan(0, $changed['changed']);
    }

    public function testSyncReclassifiesOpeningBalancesAndRemainsIdempotent(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['ucetni-osnova'][] = ['id' => '701', 'kod' => '701', 'nazev' => 'Synthetic opening account'];
        $snapshot['stav-uctu'] = [
            ['ucet' => 'code:311000', 'mena' => 'code:CZK', 'pocatekMD' => '100.00', 'pocatekDal' => '0.00'],
            ['ucet' => 'code:602000', 'mena' => 'code:CZK', 'pocatekMD' => '0.00', 'pocatekDal' => '100.00'],
        ];
        $initial = $this->transfer($snapshot);
        self::assertFalse($initial['blocked'], json_encode($initial));

        $snapshot['_meta']['mode'] = 'sync';
        $snapshot['_meta']['delta'] = true;
        $snapshot['stav-uctu'][0]['ucet'] = 'code:221000';
        $result = $this->transfer($snapshot);
        self::assertFalse($result['blocked'], json_encode($result));
        self::assertSame(0, $result['failed']);
        self::assertSame(2, $result['counts']['opening_balances_adjusted'] ?? 0);
        $adjustments = $this->db->pdo()->query("SELECT COUNT(*) FROM abra_flexi_import_map
            WHERE supplier_id = {$this->supplier} AND kind = 'stav-uctu-uprava'")->fetchColumn();
        self::assertSame(2, (int) $adjustments);
        $repeat = $this->transfer($snapshot);
        self::assertFalse($repeat['blocked'], json_encode($repeat));
        self::assertSame(0, $repeat['counts']['opening_balances_adjusted'] ?? 0);
    }

    public function testOpeningAdjustmentCannotEnterClosedOrDateLockedPeriod(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['ucetni-osnova'][] = ['id' => '701', 'kod' => '701', 'nazev' => 'Synthetic opening account'];
        $snapshot['stav-uctu'] = [
            ['ucet' => 'code:311000', 'mena' => 'code:CZK', 'pocatekMD' => '100.00', 'pocatekDal' => '0.00'],
            ['ucet' => 'code:602000', 'mena' => 'code:CZK', 'pocatekMD' => '0.00', 'pocatekDal' => '100.00'],
        ];
        self::assertFalse($this->transfer($snapshot)['blocked']);
        $snapshot['_meta']['mode'] = 'sync';
        $snapshot['_meta']['delta'] = true;
        $snapshot['stav-uctu'][0]['ucet'] = 'code:221000';
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closed'
            WHERE supplier_id = ? AND fiscal_year = 2025")->execute([$this->supplier]);

        $closed = $this->transfer($snapshot);

        self::assertTrue($closed['blocked']);
        self::assertContains('target_period_closed:2025', $closed['warnings']);
        self::assertSame(0, $this->db->pdo()->query("SELECT COUNT(*) FROM abra_flexi_import_map
            WHERE supplier_id = {$this->supplier} AND kind = 'stav-uctu-uprava'")->fetchColumn());

        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'open'
            WHERE supplier_id = ? AND fiscal_year = 2025")->execute([$this->supplier]);
        $this->db->pdo()->prepare('INSERT INTO accounting_supplier_settings (supplier_id, locked_until)
            VALUES (?, ?) ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)')
            ->execute([$this->supplier, '2025-01-01']);

        $locked = $this->transfer($snapshot);

        self::assertTrue($locked['blocked']);
        self::assertContains('target_date_locked:2025', $locked['warnings']);
        self::assertSame(0, $this->db->pdo()->query("SELECT COUNT(*) FROM abra_flexi_import_map
            WHERE supplier_id = {$this->supplier} AND kind = 'stav-uctu-uprava'")->fetchColumn());
    }

    public function testSyncRecognizesLegacyUndottedBankOpeningAccount(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['ucetni-osnova'][] = ['id' => '701', 'kod' => '701', 'nazev' => 'Synthetic opening account'];
        $snapshot['stav-uctu'] = [
            ['ucet' => 'code:221000', 'mena' => 'code:CZK', 'pocatekMD' => '100.00', 'pocatekDal' => '0.00'],
            ['ucet' => 'code:602000', 'mena' => 'code:CZK', 'pocatekMD' => '0.00', 'pocatekDal' => '100.00'],
        ];
        $initial = $this->transfer($snapshot);
        self::assertFalse($initial['blocked'], json_encode($initial));
        $this->db->pdo()->prepare("UPDATE abra_flexi_import_map SET abra_key = '2025|221000', source_hash = ?
            WHERE supplier_id = ? AND kind = 'stav-uctu' AND abra_key = '2025|221.000'")
            ->execute([\MyInvoice\Service\Migration\Abra\AbraSource::hash([
                'year' => 2025, 'account' => 221000, 'net_cents' => 10000,
            ]), $this->supplier]);

        $snapshot['_meta']['mode'] = 'sync';
        $snapshot['_meta']['delta'] = true;
        $result = $this->transfer($snapshot);
        self::assertFalse($result['blocked'], json_encode($result));
        self::assertSame(0, $result['counts']['opening_balances_adjusted'] ?? 0);
    }

    public function testBookedSalesReceiptBecomesIssuedDocumentLinkedToSourceJournal(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['prodejka'] = [$snapshot['faktura-vydana'][0]];
        $snapshot['prodejka'][0]['id'] = '501';
        $snapshot['prodejka'][0]['kod'] = 'SYN-RECEIPT-001';
        unset($snapshot['prodejka'][0]['firma'], $snapshot['prodejka'][0]['nazFirmy']);
        $snapshot['faktura-vydana'] = $snapshot['banka'] = $snapshot['vazba'] = [];
        $snapshot['ucetni-denik'] = array_slice($snapshot['ucetni-denik'], 0, 2);
        foreach ($snapshot['ucetni-denik'] as &$entry) {
            $entry['idDokl@evidencePath'] = 'prodejka/501';
        }
        unset($entry);
        $snapshot['pohyb-na-uctech'] = array_slice($snapshot['pohyb-na-uctech'], 0, 2);

        $report = $this->transfer($snapshot);
        self::assertFalse($report['blocked'], json_encode($report));
        self::assertSame(0, $report['failed']);
        self::assertSame(1, $this->countRows('invoices'));
        $row = $this->db->pdo()->query('SELECT i.id, i.status, i.booked_at, ii.vat_classification_code
            FROM invoices i JOIN invoice_items ii ON ii.invoice_id = i.id
            WHERE i.supplier_id = ' . $this->supplier)->fetch();
        self::assertSame('sent', $row['status']);
        self::assertNotNull($row['booked_at']);
        self::assertSame('1', $row['vat_classification_code']);
        self::assertTrue((new TakenOverRecord($this->db))->isDocument($this->supplier, 'invoice', (int) $row['id']));
        self::assertSame(2, $this->countRows('journal_entry_document_links'));
        $repeat = $this->transfer($snapshot);
        self::assertFalse($repeat['blocked'], json_encode($repeat));
        self::assertSame(1, $this->countRows('invoices'));
    }

    public function testBookedPayableBecomesPurchaseDocumentLinkedToSourceJournal(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['zavazek'] = [$snapshot['faktura-vydana'][0]];
        $snapshot['zavazek'][0]['id'] = '601';
        $snapshot['zavazek'][0]['kod'] = 'SYN-PAYABLE-001';
        $snapshot['zavazek'][0]['cisDosle'] = 'SYN-VENDOR-001';
        $snapshot['zavazek'][0]['polozkyDokladu'][0]['clenDph'] = 'code:40-41';
        $snapshot['faktura-vydana'] = $snapshot['banka'] = $snapshot['vazba'] = [];
        $snapshot['ucetni-denik'] = array_slice($snapshot['ucetni-denik'], 0, 2);
        $snapshot['ucetni-denik'][0]['mdUcet'] = 'code:501000';
        $snapshot['ucetni-denik'][0]['dalUcet'] = 'code:321000';
        $snapshot['ucetni-denik'][1]['mdUcet'] = 'code:343000';
        $snapshot['ucetni-denik'][1]['dalUcet'] = 'code:321000';
        foreach ($snapshot['ucetni-denik'] as &$entry) {
            $entry['idDokl@evidencePath'] = 'zavazek/601';
        }
        unset($entry);
        $snapshot['pohyb-na-uctech'] = array_map(static fn (array $row): array => $row + [
            'sumTuzMd' => $row['sumTuz'], 'sumTuzDal' => $row['sumTuz'],
        ], $snapshot['ucetni-denik']);
        foreach (['501', '321', '501000', '321000'] as $code) {
            $snapshot['ucetni-osnova'][] = ['id' => $code, 'kod' => $code, 'nazev' => 'Synthetic account'];
        }

        $report = $this->transfer($snapshot);
        self::assertFalse($report['blocked'], json_encode($report));
        self::assertSame(1, $this->countRows('purchase_invoices'));
        $row = $this->db->pdo()->query('SELECT pi.id, pi.status, pi.booked_at, pii.vat_classification_code
            FROM purchase_invoices pi JOIN purchase_invoice_items pii ON pii.purchase_invoice_id = pi.id
            WHERE pi.supplier_id = ' . $this->supplier)->fetch();
        self::assertSame('booked', $row['status']);
        self::assertNotNull($row['booked_at']);
        self::assertSame('40', $row['vat_classification_code']);
        self::assertTrue((new TakenOverRecord($this->db))->isDocument($this->supplier, 'purchase_invoice', (int) $row['id']));
        self::assertSame(2, $this->countRows('journal_entry_document_links'));
        $repeat = $this->transfer($snapshot);
        self::assertFalse($repeat['blocked'], json_encode($repeat));
        self::assertSame(1, $this->countRows('purchase_invoices'));
    }

    public function testForeignPurchaseUsesSourceCzkTaxBaseWithoutTaxingAdvanceOffset(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE supplier SET is_vat_payer = 1 WHERE id = ?')->execute([$this->supplier]);
        $pdo->prepare('INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
            VALUES (?, "EUR", "EUR", "€", "Euro", "Euro", 2, 1, 0)')->execute([$this->supplier]);
        $snapshot = $this->snapshot();
        $snapshot['faktura-vydana'] = $snapshot['banka'] = $snapshot['vazba'] = [];
        foreach (['321', '501', '321000', '501000'] as $code) {
            $snapshot['ucetni-osnova'][] = ['id' => $code, 'kod' => $code, 'nazev' => 'Synthetic account'];
        }
        $entry = ['idUcetniDenik' => '701', 'datUcto' => '2025-06-01',
            'mdUcet' => 'code:501000', 'dalUcet' => 'code:321000', 'sumTuz' => '2000.00',
            'idDokl@evidencePath' => 'faktura-prijata/701'];
        $snapshot['ucetni-denik'] = [$entry];
        $snapshot['pohyb-na-uctech'] = [$entry + ['sumTuzMd' => '2000.00', 'sumTuzDal' => '2000.00']];
        $snapshot['faktura-prijata'] = [[
            'id' => '701', 'kod' => 'SYN-PF-EUR-1', 'cisDosle' => 'SYN-VENDOR-EUR-1',
            'datVyst' => '2025-06-01', 'datUcto' => '2025-06-01', 'duzpUcto' => '2025-06-01',
            'datSplat' => '2025-06-15', 'mena' => 'code:EUR', 'kurz' => 25,
            'zuctovano' => true, 'stat' => 'code:DE', 'statDph' => 'code:DE',
            'nazFirmy' => 'Synthetic EU vendor', 'firma' => ['id' => '70', 'evidencePath' => 'adresar/70'],
            'sumZklCelkemMen' => 80, 'sumDphCelkemMen' => 21, 'sumCelkemMen' => 80,
            'polozkyDokladu' => [
                ['id' => '702', 'mnozMj' => 1, 'sumZklMen' => 100, 'sumDphMen' => 21,
                    'sumCelkemMen' => 121, 'sumZkl' => 2571.50, 'sumDph' => 540.02,
                    'szbDph' => 21, 'clenDph' => 'code:03-04, 43-44'],
                ['id' => '703', 'mnozMj' => 1, 'sumZklMen' => -20, 'sumDphMen' => 0,
                    'sumCelkemMen' => -20, 'sumZkl' => -514.30, 'sumDph' => 0,
                    'szbDph' => 0, 'clenDph' => 'code:000P'],
            ],
        ]];

        $report = $this->transfer($snapshot);
        self::assertFalse($report['blocked'], json_encode($report));
        $invoice = $pdo->query('SELECT id, total_without_vat, total_with_vat, exchange_rate
            FROM purchase_invoices WHERE supplier_id = ' . $this->supplier)->fetch();
        self::assertNotFalse($invoice);
        self::assertEquals(80.0, (float) $invoice['total_without_vat']);
        self::assertEquals(80.0, (float) $invoice['total_with_vat']);
        self::assertEquals(25.0, (float) $invoice['exchange_rate']);
        $rows = array_values(array_filter(
            Bootstrap::buildContainer()->get(VatLedgerService::class)->rows($this->supplier, '2025-06-01', '2025-06-30'),
            static fn (array $row): bool => $row['source'] === 'purchase' && (int) $row['invoice_id'] === (int) $invoice['id'],
        ));
        self::assertCount(1, $rows);
        self::assertEquals(2571.50, $rows[0]['base_czk']);
        self::assertEquals(540.02, $rows[0]['vat_czk']);
    }

    public function testCancelledSourcePurchaseIsExcludedFromVatOnFirstAndRepeatedImport(): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET is_vat_payer = 1 WHERE id = ?')->execute([$this->supplier]);
        $snapshot = $this->snapshot();
        $purchase = $snapshot['faktura-vydana'][0];
        $purchase['id'] = '701';
        $purchase['kod'] = 'SYN-CANCELLED-701';
        $purchase['cisDosle'] = 'SYN-VENDOR-CANCELLED-701';
        $purchase['zuctovano'] = false;
        $purchase['storno'] = true;
        $purchase['polozkyDokladu'][0]['clenDph'] = 'code:40-41';
        $snapshot['faktura-prijata'] = [$purchase];

        $first = $this->transfer($snapshot);
        self::assertFalse($first['blocked'], json_encode($first));
        $stmt = $this->db->pdo()->prepare('SELECT id, status FROM purchase_invoices WHERE supplier_id = ?');
        $stmt->execute([$this->supplier]);
        $target = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('cancelled', $target['status']);
        $vat = Bootstrap::buildContainer()->get(VatLedgerService::class);
        self::assertSame([], array_values(array_filter($vat->rows($this->supplier, '2025-06-01', '2025-06-30'),
            static fn (array $row): bool => $row['source'] === 'purchase' && $row['invoice_id'] === (int) $target['id'])));

        $this->db->pdo()->prepare('UPDATE purchase_invoices SET status = "received" WHERE id = ?')->execute([$target['id']]);
        $again = $this->transfer($snapshot);
        self::assertFalse($again['blocked'], json_encode($again));
        $stmt->execute([$this->supplier]);
        self::assertSame('cancelled', $stmt->fetch(\PDO::FETCH_ASSOC)['status']);
        self::assertSame(1, $this->countRows('purchase_invoices'));
    }

    public function testCancelledBankAndCashMovementsAreNotImported(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['ucetni-denik'] = array_slice($snapshot['ucetni-denik'], 0, 2);
        $snapshot['pohyb-na-uctech'] = array_slice($snapshot['pohyb-na-uctech'], 0, 2);
        $snapshot['vazba'] = [];
        $snapshot['banka'][0]['storno'] = true;
        $snapshot['pokladni-pohyb'] = [[
            'id' => '302', 'kod' => 'SYN-C-302', 'datUcto' => '2025-06-02',
            'mena' => 'code:CZK', 'sumCelkem' => '50.00', 'storno' => true,
        ]];

        $result = $this->transfer($snapshot);

        self::assertFalse($result['blocked'], json_encode($result));
        self::assertSame(0, $this->countRows('bank_transactions'));
        self::assertSame(0, $this->countRows('cash_documents'));
    }

    public function testTaxedCashMovementBlocksImportInsteadOfDroppingVat(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['pokladni-pohyb'] = [['id' => '501', 'datUcto' => '2025-06-02',
            'mena' => 'code:CZK', 'sumCelkem' => '121.00', 'sumDphCelkem' => '21.00']];

        $report = $this->transfer($snapshot);

        self::assertTrue($report['blocked']);
        self::assertContains('cash_vat_requires_review', $report['warnings']);
        self::assertSame(0, $this->countRows('cash_documents'));
        self::assertSame(0, $this->countRows('invoices'));
    }

    public function testCancellationDuringAssetStageIsReportedAsCancellation(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['majetek'] = [['id' => '901', 'kod' => 'SYN-ASSET-1']];
        $cancelled = static function (): bool {
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5) as $frame) {
                if (($frame['class'] ?? '') === \MyInvoice\Service\Migration\Abra\AbraAssetImporter::class) return true;
            }
            return false;
        };

        $report = $this->importer->import($this->supplier, $this->user, $snapshot, [2025],
            static function (): void {}, $cancelled);

        self::assertTrue($report['cancelled'], json_encode($report));
        self::assertFalse($report['blocked']);
        self::assertSame(0, $report['failed']);
        self::assertSame(0, $this->countRows('invoices'));
    }

    public function testCancellationOfPreviouslyImportedBankMovementIsReportedAsSourceChange(): void
    {
        $snapshot = $this->snapshot();
        $first = $this->transfer($snapshot);
        self::assertFalse($first['blocked'], json_encode($first));
        self::assertSame(1, $this->countRows('bank_transactions'));

        $snapshot['banka'][0]['storno'] = true;
        $again = $this->transfer($snapshot);

        self::assertTrue($again['blocked']);
        self::assertGreaterThanOrEqual(1, $again['changed']);
        self::assertSame(1, $this->countRows('bank_transactions'));
    }

    public function testSourceVatProjectionOverridesImportedReverseChargeAndDomesticTax(): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET is_vat_payer = 1 WHERE id = ?')->execute([$this->supplier]);
        $snapshot = $this->snapshot();
        $purchase = $snapshot['faktura-vydana'][0];
        $purchase['id'] = '702';
        $purchase['kod'] = 'SYN-IMPORT-RC-702';
        $purchase['cisDosle'] = 'SYN-VENDOR-RC-702';
        $purchase['sumDphCelkem'] = '0.00';
        $purchase['sumCelkem'] = '100.00';
        $purchase['polozkyDokladu'][0]['sumDph'] = '0.00';
        $purchase['polozkyDokladu'][0]['sumCelkem'] = '100.00';
        $purchase['polozkyDokladu'][0]['szbDph'] = '0';
        $purchase['polozkyDokladu'][0]['clenDph'] = 'code:07-08, 43-44';
        $snapshot['faktura-prijata'] = [$purchase];
        $result = $this->transfer($snapshot);
        self::assertFalse($result['blocked'], json_encode($result));
        $id = (int) $this->db->pdo()->query('SELECT id FROM purchase_invoices WHERE supplier_id = '
            . $this->supplier)->fetchColumn();
        $this->db->pdo()->prepare('UPDATE purchase_invoice_items SET import_projection_vat_czk = 0
            WHERE purchase_invoice_id = ?')->execute([$id]);
        $ledger = Bootstrap::buildContainer()->get(VatLedgerService::class);
        $rows = array_values(array_filter($ledger->rows($this->supplier, '2025-06-01', '2025-06-30'),
            static fn (array $row): bool => $row['source'] === 'purchase' && $row['invoice_id'] === $id));
        self::assertCount(1, $rows);
        self::assertEquals(100.0, $rows[0]['base_czk']);
        self::assertEquals(0.0, $rows[0]['vat_czk']);
        self::assertEquals(0.0, $rows[0]['vat_rate']);

        $this->db->pdo()->prepare('UPDATE purchase_invoices SET reverse_charge = 0 WHERE id = ?')->execute([$id]);
        $this->db->pdo()->prepare('UPDATE purchase_invoice_items
            SET vat_classification_code = "40", vat_rate_snapshot = 21, total_vat = 21,
                import_projection_vat_czk = 20.9 WHERE purchase_invoice_id = ?')->execute([$id]);
        $rows = array_values(array_filter($ledger->rows($this->supplier, '2025-06-01', '2025-06-30'),
            static fn (array $row): bool => $row['source'] === 'purchase' && $row['invoice_id'] === $id));
        self::assertCount(1, $rows);
        self::assertEquals(20.9, $rows[0]['vat_czk']);

        $this->db->pdo()->prepare('UPDATE purchase_invoices SET vat_deduction = "proportional",
            vat_deduction_percent = 50 WHERE id = ?')->execute([$id]);
        $rows = array_values(array_filter($ledger->rows($this->supplier, '2025-06-01', '2025-06-30'),
            static fn (array $row): bool => $row['source'] === 'purchase' && $row['invoice_id'] === $id));
        self::assertCount(1, $rows);
        self::assertEquals(50.0, $rows[0]['base_czk']);
        self::assertEquals(10.45, $rows[0]['vat_czk']);
    }

    public function testSourceTaxSignOverridesPositiveCreditNoteForDomesticAndOss(): void
    {
        $snapshot = $this->snapshot();
        $result = $this->transfer($snapshot);
        self::assertFalse($result['blocked'], json_encode($result));
        $id = (int) $this->db->pdo()->query('SELECT id FROM invoices WHERE supplier_id = '
            . $this->supplier)->fetchColumn();
        $this->db->pdo()->prepare('UPDATE invoices SET invoice_type = "credit_note", import_tax_sign = 1
            WHERE id = ?')->execute([$id]);
        $ledger = Bootstrap::buildContainer()->get(VatLedgerService::class);
        $rows = array_values(array_filter($ledger->rows($this->supplier, '2025-06-01', '2025-06-30'),
            static fn (array $row): bool => $row['source'] === 'sale' && $row['invoice_id'] === $id));
        self::assertCount(1, $rows);
        self::assertEquals(100.0, $rows[0]['base_czk']);
        $this->db->pdo()->prepare('UPDATE invoice_items SET oss_applicable = 1,
            vat_classification_code = NULL WHERE invoice_id = ?')->execute([$id]);
        $ossRows = $ledger->ossSelectedSupplyRows($this->supplier, '2025-06-01', '2025-06-30');
        self::assertCount(1, $ossRows);
        self::assertEquals(100.0, $ossRows[0]['base_czk']);
    }

    public function testImportAppliesSafeSourceVatProjectionAndReappliesOnRepeat(): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET is_vat_payer = 1 WHERE id = ?')->execute([$this->supplier]);
        $snapshot = $this->snapshot();
        $purchase = $snapshot['faktura-vydana'][0];
        $purchase['id'] = '703';
        $purchase['kod'] = 'SYN-PROJECTION-703';
        $purchase['cisDosle'] = 'SYN-VENDOR-PROJECTION-703';
        $purchase['sumDphCelkem'] = '0.00';
        $purchase['sumCelkem'] = '100.00';
        $purchase['polozkyDokladu'][0]['sumDph'] = '0.00';
        $purchase['polozkyDokladu'][0]['sumCelkem'] = '100.00';
        $purchase['polozkyDokladu'][0]['szbDph'] = '0';
        $purchase['polozkyDokladu'][0]['clenDph'] = 'code:07-08, 43-44';
        $snapshot['faktura-prijata'] = [$purchase];
        $snapshot['_meta']['vat_projection']['faktura-prijata|703'] = [[
            'year' => 2025, 'month' => 6, 'class' => '07-08, 43-44',
            'base' => 100.0, 'vat' => 0.0, 'rows' => 1,
        ]];
        $first = $this->transfer($snapshot);
        self::assertFalse($first['blocked'], json_encode($first));
        $id = (int) $this->db->pdo()->query('SELECT id FROM purchase_invoices WHERE supplier_id = '
            . $this->supplier)->fetchColumn();
        $projection = $this->db->pdo()->prepare('SELECT import_projection_vat_czk
            FROM purchase_invoice_items WHERE purchase_invoice_id = ?');
        $projection->execute([$id]);
        self::assertSame('0.00', $projection->fetchColumn());
        $this->db->pdo()->prepare('UPDATE purchase_invoice_items SET import_projection_vat_czk = NULL
            WHERE purchase_invoice_id = ?')->execute([$id]);
        $again = $this->transfer($snapshot);
        self::assertFalse($again['blocked'], json_encode($again));
        $projection->execute([$id]);
        self::assertSame('0.00', $projection->fetchColumn());
    }

    public function testImportKeepsPositiveTaxEffectOfSourceCreditNote(): void
    {
        $snapshot = $this->snapshot();
        $sale = &$snapshot['faktura-vydana'][0];
        $sale['typDokl'] = 'code:DOBROPIS';
        $sale['polozkyDokladu'][0]['clenDph'] = 'code:01-02';
        unset($sale);
        $snapshot['_meta']['vat_projection']['faktura-vydana|101'] = [[
            'year' => 2025, 'month' => 6, 'class' => '01-02',
            'base' => 100.0, 'vat' => 21.0, 'rows' => 1,
        ]];
        $result = $this->transfer($snapshot);
        self::assertFalse($result['blocked'], json_encode($result));
        $invoice = $this->db->pdo()->query('SELECT id, invoice_type, import_tax_sign FROM invoices
            WHERE supplier_id = ' . $this->supplier)->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('credit_note', $invoice['invoice_type']);
        self::assertSame(1, (int) $invoice['import_tax_sign']);
        $rows = array_values(array_filter(Bootstrap::buildContainer()->get(VatLedgerService::class)
            ->rows($this->supplier, '2025-06-01', '2025-06-30'),
            static fn (array $row): bool => $row['source'] === 'sale' && $row['invoice_id'] === (int) $invoice['id']));
        self::assertCount(1, $rows);
        self::assertEquals(100.0, $rows[0]['base_czk']);
    }

    public function testImportKeepsCalculatedVatOfPurchaseCreditNoteApartFromInvoiceAmount(): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET is_vat_payer = 1 WHERE id = ?')->execute([$this->supplier]);
        $snapshot = $this->snapshot();
        $purchase = $snapshot['faktura-vydana'][0];
        $purchase['id'] = '704';
        $purchase['kod'] = 'SYN-CREDIT-704';
        $purchase['cisDosle'] = 'SYN-VENDOR-CREDIT-704';
        $purchase['typDokl'] = 'code:DOBROPIS';
        $purchase['sumZklCelkem'] = '-100.00';
        $purchase['sumDphCelkem'] = '-21.00';
        $purchase['sumCelkem'] = '-121.00';
        $purchase['polozkyDokladu'][0]['sumZkl'] = '-100.00';
        $purchase['polozkyDokladu'][0]['sumDph'] = '-21.00';
        $purchase['polozkyDokladu'][0]['sumCelkem'] = '-121.00';
        $purchase['polozkyDokladu'][0]['clenDph'] = 'code:40-41';
        $purchase['polozkyDokladu'][] = ['id' => '705', 'mnozMj' => 1, 'szbDph' => 0,
            'sumZkl' => 0, 'sumDph' => 0, 'sumCelkem' => 0, 'clenDph' => 'code:000P'];
        $snapshot['faktura-prijata'] = [$purchase];
        $snapshot['_meta']['vat_projection']['faktura-prijata|704'] = [[
            'year' => 2025, 'month' => 6, 'class' => '40-41',
            'base' => -100.0, 'vat' => -20.9, 'rows' => 1,
        ], [
            'year' => 2025, 'month' => 6, 'class' => '000P',
            'base' => 0.0, 'vat' => 0.0, 'rows' => 1,
        ]];
        $result = $this->transfer($snapshot);
        self::assertFalse($result['blocked'], json_encode($result));
        $id = (int) $this->db->pdo()->query('SELECT id FROM purchase_invoices WHERE supplier_id = '
            . $this->supplier)->fetchColumn();
        $items = $this->db->pdo()->prepare('SELECT import_projection_vat_czk
            FROM purchase_invoice_items WHERE purchase_invoice_id = ? ORDER BY order_index');
        $items->execute([$id]);
        self::assertSame('-20.90', $items->fetchColumn());
        $rows = array_values(array_filter(Bootstrap::buildContainer()->get(VatLedgerService::class)
            ->rows($this->supplier, '2025-06-01', '2025-06-30'),
            static fn (array $row): bool => $row['source'] === 'purchase' && $row['invoice_id'] === $id));
        self::assertCount(1, $rows);
        self::assertEquals(-20.9, $rows[0]['vat_czk']);
    }

    public function testImportUsesPositiveSourceTaxSignForOssCreditNote(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE supplier SET is_vat_payer = 1, oss_enabled = 1, oss_valid_from = "2024-01-01"
            WHERE id = ?')->execute([$this->supplier]);
        $pdo->exec('INSERT INTO vat_rates (code, rate_percent, country, label_cs, label_en,
            is_default, is_reverse_charge, valid_from, valid_to, display_order)
            VALUES ("SYN-PL23-CR", 23, "PL", "Syntetická PL", "Synthetic PL",
                0, 0, "2024-01-01", NULL, 900)');
        $snapshot = $this->snapshot();
        $sale = &$snapshot['faktura-vydana'][0];
        $sale['typDokl'] = 'code:DOBROPIS EUR - EU';
        $sale['stat'] = 'code:PL';
        $sale['statDph'] = 'code:PL';
        $sale['sumDphCelkem'] = '23.00';
        $sale['sumCelkem'] = '123.00';
        $sale['polozkyDokladu'][0]['szbDph'] = '23';
        $sale['polozkyDokladu'][0]['sumDph'] = '23.00';
        $sale['polozkyDokladu'][0]['sumCelkem'] = '123.00';
        $sale['polozkyDokladu'][0]['clenDph'] = 'code:24';
        unset($sale);
        $snapshot['ucetni-denik'][1]['sumTuz'] = '23.00';
        $snapshot['ucetni-denik'][2]['sumTuz'] = '123.00';
        foreach ([1 => '23.00', 2 => '123.00'] as $index => $amount) {
            $snapshot['pohyb-na-uctech'][$index]['sumTuz'] = $amount;
            $snapshot['pohyb-na-uctech'][$index]['sumTuzMd'] = $amount;
            $snapshot['pohyb-na-uctech'][$index]['sumTuzDal'] = $amount;
        }
        $snapshot['banka'][0]['sumCelkem'] = '123.00';
        $snapshot['vazba'][0]['castka'] = '123.00';
        $snapshot['_meta']['vat_projection']['faktura-vydana|101'] = [[
            'year' => 2025, 'month' => 6, 'class' => '24',
            'base' => 100.0, 'vat' => 0.0, 'rows' => 1,
        ]];

        $result = $this->transfer($snapshot);
        self::assertFalse($result['blocked'], json_encode($result));
        $invoice = $pdo->query('SELECT id, import_tax_sign FROM invoices WHERE supplier_id = '
            . $this->supplier)->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(1, (int) $invoice['import_tax_sign']);
        $oss = Bootstrap::buildContainer()->get(VatLedgerService::class)
            ->ossSelectedSupplyRows($this->supplier, '2025-06-01', '2025-06-30');
        self::assertCount(1, $oss);
        self::assertEquals(100.0, $oss[0]['base_czk']);
    }

    public function testPreviouslyReviewedRoundingDraftCanBePromotedOnRepeat(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['zavazek'] = [$snapshot['faktura-vydana'][0]];
        $source = &$snapshot['zavazek'][0];
        $source['id'] = '801';
        $source['kod'] = 'SYN-PAYABLE-ROUND-1';
        $source['cisDosle'] = 'SYN-VENDOR-ROUND-1';
        $source['sumZklCelkem'] = 99.70;
        $source['sumCelkem'] = 120.70;
        $source['polozkyDokladu'][0]['clenDph'] = 'code:40-41';
        $source['polozkyDokladu'][] = ['id' => '802', 'mnozMj' => 1, 'szbDph' => 0,
            'sumZkl' => -0.30, 'sumDph' => 0, 'sumCelkem' => -0.30, 'clenDph' => 'code:40-41'];
        unset($source);
        $snapshot['faktura-vydana'] = $snapshot['banka'] = $snapshot['vazba'] = [];
        $snapshot['ucetni-denik'] = array_slice($snapshot['ucetni-denik'], 0, 2);
        $snapshot['ucetni-denik'][0]['sumTuz'] = '99.70';
        $snapshot['ucetni-denik'][0]['mdUcet'] = 'code:501000';
        $snapshot['ucetni-denik'][0]['dalUcet'] = 'code:321000';
        $snapshot['ucetni-denik'][1]['mdUcet'] = 'code:343000';
        $snapshot['ucetni-denik'][1]['dalUcet'] = 'code:321000';
        foreach ($snapshot['ucetni-denik'] as &$entry) $entry['idDokl@evidencePath'] = 'zavazek/801';
        unset($entry);
        $snapshot['pohyb-na-uctech'] = array_map(static fn (array $row): array => $row + [
            'sumTuzMd' => $row['sumTuz'], 'sumTuzDal' => $row['sumTuz'],
        ], $snapshot['ucetni-denik']);
        foreach (['501', '321', '501000', '321000'] as $code) {
            $snapshot['ucetni-osnova'][] = ['id' => $code, 'kod' => $code, 'nazev' => 'Synthetic account'];
        }

        $first = $this->transfer($snapshot);
        self::assertFalse($first['blocked'], json_encode($first));
        $pdo = $this->db->pdo();
        $id = (int) $pdo->query('SELECT id FROM purchase_invoices WHERE supplier_id = ' . $this->supplier)->fetchColumn();
        self::assertGreaterThan(0, $id);
        $pdo->prepare('UPDATE purchase_invoices SET status = "draft", booked_at = NULL, booked_by = NULL WHERE id = ?')->execute([$id]);

        $repeat = $this->transfer($snapshot);
        self::assertFalse($repeat['blocked'], json_encode($repeat));
        $status = $pdo->query('SELECT status FROM purchase_invoices WHERE id = ' . $id)->fetchColumn();
        self::assertSame('booked', $status);
    }

    public function testForeignTaxUsesSharedOssPolicyAndSourceVatCountry(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE supplier SET oss_enabled = 1, oss_valid_from = "2024-01-01" WHERE id = ?')
            ->execute([$this->supplier]);
        $pdo->prepare('INSERT INTO vat_rates (code, rate_percent, country, label_cs, label_en,
            is_default, is_reverse_charge, valid_from, valid_to, display_order)
            VALUES ("SYN-ABRA-PL-23", 23, "PL", "Syntetická PL", "Synthetic PL", 0, 0, "2024-01-01", NULL, 900)')
            ->execute();
        $snapshot = $this->snapshot();
        $snapshot['faktura-vydana'][0]['stat'] = 'code:PL';
        $snapshot['faktura-vydana'][0]['statDph'] = 'code:PL';
        $snapshot['faktura-vydana'][0]['sumDphCelkem'] = '23.00';
        $snapshot['faktura-vydana'][0]['sumCelkem'] = '123.00';
        $snapshot['faktura-vydana'][0]['polozkyDokladu'][0]['szbDph'] = '23';
        $snapshot['faktura-vydana'][0]['polozkyDokladu'][0]['sumDph'] = '23.00';
        $snapshot['faktura-vydana'][0]['polozkyDokladu'][0]['sumCelkem'] = '123.00';
        $snapshot['faktura-vydana'][0]['polozkyDokladu'][0]['clenDph'] = 'code:22';
        $snapshot['faktura-vydana'][0]['polozkyDokladu'][] = [
            'id' => '202', 'popis' => 'Synthetic zero row', 'mnozMj' => '1', 'szbDph' => '23',
            'sumZkl' => '0.00', 'sumDph' => '0.00', 'sumCelkem' => '0.00', 'clenDph' => 'code:22',
        ];
        $snapshot['ucetni-denik'][1]['sumTuz'] = '23.00';
        $snapshot['ucetni-denik'][2]['sumTuz'] = '123.00';
        $snapshot['pohyb-na-uctech'][1]['sumTuz'] = '23.00';
        $snapshot['pohyb-na-uctech'][1]['sumTuzMd'] = '23.00';
        $snapshot['pohyb-na-uctech'][1]['sumTuzDal'] = '23.00';
        $snapshot['pohyb-na-uctech'][2]['sumTuz'] = '123.00';
        $snapshot['pohyb-na-uctech'][2]['sumTuzMd'] = '123.00';
        $snapshot['pohyb-na-uctech'][2]['sumTuzDal'] = '123.00';
        $snapshot['banka'][0]['sumCelkem'] = '123.00';
        $snapshot['vazba'][0]['castka'] = '123.00';

        $report = $this->transfer($snapshot);

        self::assertFalse($report['blocked'], json_encode($report));
        self::assertNotContains('document_mixed_oss_and_domestic_requires_review', $report['warnings']);
        $stmt = $pdo->prepare('SELECT i.status, ii.vat_classification_code, ii.oss_applicable,
            ii.oss_consumer_country, vr.country AS rate_country
            FROM invoices i JOIN invoice_items ii ON ii.invoice_id = i.id
            JOIN vat_rates vr ON vr.id = ii.vat_rate_id WHERE i.supplier_id = ? AND ii.order_index = 0');
        $stmt->execute([$this->supplier]);
        $item = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('paid', $item['status']);
        self::assertNull($item['vat_classification_code']);
        self::assertSame(1, (int) $item['oss_applicable']);
        self::assertSame('PL', $item['oss_consumer_country']);
        self::assertSame('PL', $item['rate_country']);
    }

    public function testBookedThirdCountrySaleKeepsForeignVatOnInvoiceOutsideCzechReturn(): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec('INSERT INTO vat_rates (code, rate_percent, country, label_cs, label_en,
            is_default, is_reverse_charge, valid_from, valid_to, display_order)
            VALUES ("SYN-ABRA-GB-20", 20, "GB", "Syntetická GB", "Synthetic GB", 0, 0,
            "2024-01-01", NULL, 900)');
        $snapshot = $this->snapshot();
        $sale = &$snapshot['faktura-vydana'][0];
        $sale['stat'] = 'code:GB';
        $sale['statDph'] = 'code:GB';
        $sale['sumDphCelkem'] = '20.00';
        $sale['sumCelkem'] = '120.00';
        $sale['polozkyDokladu'][0]['szbDph'] = '20';
        $sale['polozkyDokladu'][0]['sumDph'] = '20.00';
        $sale['polozkyDokladu'][0]['sumCelkem'] = '120.00';
        $sale['polozkyDokladu'][0]['clenDph'] = 'code:22';
        unset($sale);
        $snapshot['ucetni-denik'][1]['sumTuz'] = '20.00';
        $snapshot['ucetni-denik'][2]['sumTuz'] = '120.00';
        $snapshot['pohyb-na-uctech'][1]['sumTuz'] = '20.00';
        $snapshot['pohyb-na-uctech'][1]['sumTuzMd'] = '20.00';
        $snapshot['pohyb-na-uctech'][1]['sumTuzDal'] = '20.00';
        $snapshot['pohyb-na-uctech'][2]['sumTuz'] = '120.00';
        $snapshot['pohyb-na-uctech'][2]['sumTuzMd'] = '120.00';
        $snapshot['pohyb-na-uctech'][2]['sumTuzDal'] = '120.00';
        $snapshot['banka'][0]['sumCelkem'] = '120.00';
        $snapshot['vazba'][0]['castka'] = '120.00';

        $report = $this->transfer($snapshot);

        self::assertFalse($report['blocked'], json_encode($report));
        $stmt = $pdo->prepare('SELECT i.id, i.status, i.booked_at, i.total_with_vat,
            ii.total_vat, ii.vat_classification_code, ii.oss_applicable, vr.country AS rate_country
            FROM invoices i JOIN invoice_items ii ON ii.invoice_id = i.id
            JOIN vat_rates vr ON vr.id = ii.vat_rate_id WHERE i.supplier_id = ?');
        $stmt->execute([$this->supplier]);
        $invoice = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('paid', $invoice['status']);
        self::assertNotNull($invoice['booked_at']);
        self::assertEquals(120.0, (float) $invoice['total_with_vat']);
        self::assertEquals(20.0, (float) $invoice['total_vat']);
        self::assertSame('26', $invoice['vat_classification_code']);
        self::assertSame(0, (int) $invoice['oss_applicable']);
        self::assertSame('GB', $invoice['rate_country']);

        $ledger = Bootstrap::buildContainer()->get(VatLedgerService::class);
        $rows = array_values(array_filter($ledger->rows($this->supplier, '2025-06-01', '2025-06-30'),
            static fn (array $row): bool => $row['source'] === 'sale' && $row['invoice_id'] === (int) $invoice['id']));
        self::assertCount(1, $rows);
        self::assertSame('22', $rows[0]['dphdp3_line']);
        self::assertEquals(100.0, $rows[0]['base_czk']);
        self::assertEquals(0.0, $rows[0]['vat_czk']);
    }

    public function testRepeatedImportEnrichesUntouchedLegacyDraftWithoutDuplicatingIt(): void
    {
        $snapshot = $this->snapshot();
        $this->transfer($snapshot);
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE invoices SET status = "draft", booked_at = NULL, booked_by = NULL
            WHERE supplier_id = ?')->execute([$this->supplier]);
        $pdo->prepare('UPDATE invoice_items SET vat_classification_code = NULL
            WHERE invoice_id IN (SELECT id FROM invoices WHERE supplier_id = ?)')->execute([$this->supplier]);

        $report = $this->transfer($snapshot);

        self::assertFalse($report['blocked'], json_encode($report));
        self::assertSame(0, $report['created']);
        $stmt = $pdo->prepare('SELECT i.status, i.booked_at, ii.vat_classification_code
            FROM invoices i JOIN invoice_items ii ON ii.invoice_id = i.id WHERE i.supplier_id = ?');
        $stmt->execute([$this->supplier]);
        $doc = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('paid', $doc['status']);
        self::assertNotNull($doc['booked_at']);
        self::assertSame('1', $doc['vat_classification_code']);
        self::assertSame(1, $this->countRows('invoices'));
    }

    public function testRepeatedImportCorrectsLegacySelfAssessedPurchasePayable(): void
    {
        $snapshot = $this->snapshot();
        $purchase = $snapshot['faktura-vydana'][0];
        $purchase['id'] = '701';
        $purchase['kod'] = 'SYN-RC-PF-1';
        $purchase['cisDosle'] = 'SYN-RC-VENDOR-1';
        $purchase['sumCelkem'] = '100.00';
        $purchase['polozkyDokladu'][0]['sumCelkem'] = '121.00';
        $purchase['polozkyDokladu'][0]['clenDph'] = 'code:03-04, 43-44';
        $snapshot['faktura-vydana'] = [];
        $snapshot['faktura-prijata'] = [$purchase];
        $snapshot['banka'] = [];
        $snapshot['vazba'] = [];
        $this->transfer($snapshot);
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE purchase_invoices SET status = "draft", booked_at = NULL,
            booked_by = NULL, total_vat = 21, total_with_vat = 121, reverse_charge = 0,
            vat_deduction = "none" WHERE supplier_id = ?')->execute([$this->supplier]);
        $pdo->prepare('UPDATE purchase_invoice_items SET total_vat = 21, total_with_vat = 121,
            vat_classification_code = NULL WHERE purchase_invoice_id IN
            (SELECT id FROM purchase_invoices WHERE supplier_id = ?)')->execute([$this->supplier]);

        $report = $this->transfer($snapshot);

        self::assertFalse($report['blocked'], json_encode($report));
        $stmt = $pdo->prepare('SELECT pi.status, pi.total_with_vat, pi.total_vat, pi.reverse_charge,
            pi.vat_deduction, pii.total_with_vat AS item_total, pii.total_vat AS item_vat,
            pii.vat_classification_code AS item_code
            FROM purchase_invoices pi JOIN purchase_invoice_items pii ON pii.purchase_invoice_id = pi.id
            WHERE pi.supplier_id = ?');
        $stmt->execute([$this->supplier]);
        $doc = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('booked', $doc['status']);
        self::assertEquals(100.0, (float) $doc['total_with_vat']);
        self::assertEquals(0.0, (float) $doc['total_vat']);
        self::assertSame(1, (int) $doc['reverse_charge']);
        self::assertSame('full', $doc['vat_deduction']);
        self::assertEquals(100.0, (float) $doc['item_total']);
        self::assertEquals(0.0, (float) $doc['item_vat']);
        self::assertSame('23', $doc['item_code']);
    }

    public function testBankMovementsShareMonthlyStatementsAndCarryVerifiedOpening(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['ucetni-osnova'][] = ['id' => '701', 'kod' => '701', 'nazev' => 'Synthetic opening account'];
        $snapshot['bankovni-ucet'] = [[
            'id' => '1', 'kod' => 'SYN-BANK', 'nazev' => 'Synthetic bank', 'buc' => '9000000001',
            'mena' => 'code:CZK', 'primUcet' => 'code:221000',
        ], [
            'id' => '2', 'kod' => 'SYN-VIRTUAL', 'nazev' => 'Synthetic virtual account', 'buc' => 'VIRTUAL-CZK',
            'mena' => 'code:CZK', 'primUcet' => 'code:221001',
        ]];
        $snapshot['ucetni-osnova'][] = ['id' => '221001', 'kod' => '221001', 'nazev' => 'Synthetic virtual analytic'];
        $snapshot['stav-uctu'] = [
            ['ucet' => 'code:221000', 'mena' => 'code:CZK', 'pocatekMD' => '50.00',
                'pocatekDal' => '0.00', 'zustatekMD' => '171.00', 'zustatekDal' => '0.00',
                'postingPeriod' => 'code:2025'],
            ['ucet' => 'code:311000', 'mena' => 'code:CZK', 'pocatekMD' => '0.00',
                'pocatekDal' => '50.00', 'zustatekMD' => '0.00', 'zustatekDal' => '50.00',
                'postingPeriod' => 'code:2025'],
        ];
        $snapshot['banka'][0]['banka@ref'] = '/bankovni-ucet/1';
        $snapshot['banka'][0]['zuctovano'] = 'true';
        $second = $snapshot['banka'][0];
        $second['id'] = '302';
        $second['kod'] = 'SYN-B-002';
        $second['datUcto'] = '2025-06-15';
        $second['sumCelkem'] = '20.00';
        $second['typPohybuK'] = 'typPohybu.vydej';
        $second['zuctovano'] = 'false';
        $snapshot['banka'][] = $second;
        $third = $second;
        $third['id'] = '303';
        $third['kod'] = 'SYN-B-003';
        $third['datUcto'] = '2025-07-01';
        $third['sumCelkem'] = '5.00';
        $snapshot['banka'][] = $third;

        $report = $this->transfer($snapshot);

        self::assertFalse($report['blocked'], json_encode($report));
        self::assertSame(3, $this->countRows('bank_transactions'));
        self::assertSame(2, $this->countRows('bank_statements'));
        $stmt = $this->db->pdo()->prepare('SELECT statement_number, transaction_count, account_number,
            prev_balance, curr_balance FROM bank_statements WHERE supplier_id = ? ORDER BY statement_number');
        $stmt->execute([$this->supplier]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame(['2025-06', '2025-07'], array_column($rows, 'statement_number'));
        self::assertSame([2, 1], array_map('intval', array_column($rows, 'transaction_count')));
        self::assertSame(['9000000001', '9000000001'], array_column($rows, 'account_number'));
        self::assertSame(['50.00', '151.00'], array_column($rows, 'prev_balance'));
        self::assertSame(['151.00', '146.00'], array_column($rows, 'curr_balance'));
        $account = $this->db->pdo()->prepare('SELECT a.analytic_suffix, a.currency_id, c.account_code
            FROM supplier_bank_accounts a JOIN chart_of_accounts c ON c.supplier_id = a.supplier_id
                AND c.account_code = CONCAT("221.", a.analytic_suffix)
            WHERE a.supplier_id = ? AND a.account_number = ?');
        $account->execute([$this->supplier, '9000000001']);
        $registered = $account->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($registered);
        self::assertSame('000', $registered['analytic_suffix']);
        self::assertSame('221.000', $registered['account_code']);
        self::assertNotNull($registered['currency_id']);
        $virtual = $this->db->pdo()->prepare('SELECT account_number, analytic_suffix, currency_id
            FROM supplier_bank_accounts WHERE supplier_id = ? AND label = ?');
        $virtual->execute([$this->supplier, 'Synthetic virtual account']);
        $virtualAccount = $virtual->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($virtualAccount);
        self::assertNull($virtualAccount['account_number']);
        self::assertSame('001', $virtualAccount['analytic_suffix']);
        self::assertNotNull($virtualAccount['currency_id']);
        $snapshot['bankovni-ucet'][0]['smerKod'] = 'code:0100';
        $again = $this->transfer($snapshot);
        self::assertFalse($again['blocked'], json_encode($again));
        $stmt = $this->db->pdo()->prepare('SELECT DISTINCT bank_code FROM bank_statements WHERE supplier_id = ?');
        $stmt->execute([$this->supplier]);
        self::assertSame(['0100'], $stmt->fetchAll(\PDO::FETCH_COLUMN));
        $stmt = $this->db->pdo()->prepare('SELECT c.bank_code FROM supplier_bank_accounts a
            JOIN currencies c ON c.id = a.currency_id WHERE a.supplier_id = ? AND a.account_number = ?');
        $stmt->execute([$this->supplier, '9000000001']);
        self::assertSame('0100', $stmt->fetchColumn());
        self::assertSame(2, $this->countRows('supplier_bank_accounts'));
    }

    public function testImportsFixedAndSmallAssetCardsWithoutRepostingHistoricalDepreciation(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['majetek'] = [
            ['id' => '501', 'kod' => 'SYN-ASSET-1', 'nazev' => 'Synthetic fixed asset',
                'druhK' => 'druhMaj.hmDl', 'cena' => '100000.00', 'datKoupe' => '2024-03-01',
                'datZar' => '2024-03-02', 'primarniUcet' => 'code:022', 'opravnyUcet' => 'code:082'],
            ['id' => '502', 'kod' => 'SYN-ASSET-2', 'nazev' => 'Synthetic small asset',
                'druhK' => 'druhMaj.drobny', 'cena' => '1000.00', 'datKoupe' => '2025-03-01',
                'datZar' => '2025-03-02', 'primarniUcet' => 'code:022'],
        ];

        $report = $this->transfer($snapshot);

        self::assertFalse($report['blocked'], json_encode($report));
        self::assertSame(1, $this->countRows('assets'));
        self::assertSame(1, $this->countRows('small_assets'));
        $stmt = $this->db->pdo()->prepare('SELECT status, tax_method FROM assets WHERE supplier_id = ?');
        $stmt->execute([$this->supplier]);
        self::assertSame(['status' => 'draft', 'tax_method' => 'none'], $stmt->fetch(\PDO::FETCH_ASSOC));
        $stmt = $this->db->pdo()->prepare('SELECT status FROM small_assets WHERE supplier_id = ?');
        $stmt->execute([$this->supplier]);
        self::assertSame('in_use', $stmt->fetchColumn());
        $repeat = $this->transfer($snapshot);
        self::assertFalse($repeat['blocked'], json_encode($repeat));
        self::assertSame(0, $repeat['created']);
        self::assertSame(1, $this->countRows('assets'));
        self::assertSame(1, $this->countRows('small_assets'));
    }

    public function testFiscalPeriodIncludesEntriesFromFollowingCalendarYear(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['_meta']['periods'][0]['kod'] = 'FY';
        $snapshot['_meta']['periods'][0]['platiOdData'] = '2025-07-01';
        $snapshot['_meta']['periods'][0]['platiDoData'] = '2026-06-30';
        $snapshot['ucetni-obdobi'] = $snapshot['_meta']['periods'];
        foreach ($snapshot['ucetni-denik'] as &$entry) $entry['datUcto'] = '2026-02-01';
        unset($entry);
        foreach ($snapshot['pohyb-na-uctech'] as &$movement) $movement['datUcto'] = '2026-02-01';
        unset($movement);
        $snapshot['faktura-vydana'][0]['datVyst'] = '2026-02-01';
        $snapshot['faktura-vydana'][0]['datUcto'] = '2026-02-01';
        $snapshot['faktura-vydana'][0]['datSplat'] = '2026-02-15';
        $snapshot['banka'][0]['datUcto'] = '2026-02-02';
        // Hospodářský rok převod přijme jen do firmy, která ho má nastavený (jinak se zastaví).
        $this->db->pdo()->prepare('UPDATE supplier SET fiscal_year_start_month = 7 WHERE id = ?')->execute([$this->supplier]);

        $report = $this->transfer($snapshot);

        self::assertFalse($report['blocked'], json_encode($report));
        self::assertSame(3, $this->countRows('journal_entries'));
        self::assertSame(1, $this->countRows('invoices'));
        self::assertSame(1, $this->countRows('bank_transactions'));
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM journal_entries e
            JOIN accounting_periods p ON p.id = e.period_id WHERE e.supplier_id = ? AND p.fiscal_year = 2025
                AND e.entry_date = "2026-02-01"');
        $stmt->execute([$this->supplier]);
        self::assertSame(3, (int) $stmt->fetchColumn());
    }

    public function testMultiYearSyncReconcilesOnlyYearsWithJournalChanges(): void
    {
        $snapshot = $this->snapshot();
        $period2026 = ['id' => '2', 'kod' => '2026', 'platiOdData' => '2026-01-01', 'platiDoData' => '2026-12-31'];
        $snapshot['_meta']['periods'][] = $period2026;
        $snapshot['ucetni-obdobi'][] = $period2026;
        $prior = ['idUcetniDenik' => '4', 'datUcto' => '2026-03-01', 'mdUcet' => 'code:311000',
            'dalUcet' => 'code:602000', 'sumTuz' => '50.00'];
        $snapshot['ucetni-denik'][] = $prior;
        $snapshot['pohyb-na-uctech'][] = $prior + ['sumTuzMd' => '50.00', 'sumTuzDal' => '50.00'];
        $initial = $this->importer->import($this->supplier, $this->user, $snapshot, [2025, 2026],
            static function (): void {}, static fn (): bool => false);
        self::assertFalse($initial['blocked'], json_encode($initial));

        $new = ['idUcetniDenik' => '5', 'datUcto' => '2026-04-01', 'mdUcet' => 'code:311000',
            'dalUcet' => 'code:602000', 'sumTuz' => '25.00'];
        $snapshot['_meta']['mode'] = 'sync';
        $snapshot['ucetni-denik'] = [$new];
        $snapshot['pohyb-na-uctech'] = [$new + ['sumTuzMd' => '25.00', 'sumTuzDal' => '25.00']];
        $snapshot['faktura-vydana'] = $snapshot['banka'] = $snapshot['vazba'] = [];
        $report = $this->importer->import($this->supplier, $this->user, $snapshot, [2025, 2026],
            static function (): void {}, static fn (): bool => false);

        self::assertFalse($report['blocked'], json_encode($report));
        self::assertSame(5, $this->countRows('journal_entries'));
        self::assertSame([2026], array_column($report['reconciliation']['years'], 'year'));
    }

    public function testDocumentSelectionUsesAccountingPeriodInsteadOfIssueYear(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['faktura-vydana'][0]['datVyst'] = '2024-12-31';
        $snapshot['faktura-vydana'][0]['datUcto'] = '2025-01-02';
        $snapshot['vazba'] = [];

        $report = $this->transfer($snapshot);

        self::assertFalse($report['blocked'], json_encode($report));
        self::assertSame(1, $this->countRows('invoices'));
    }

    public function testAnalyticAccountsFromUcetEvidenceAreImportedWithSyntheticChart(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['ucet'] = array_splice($snapshot['ucetni-osnova'], 4);

        $report = $this->transfer($snapshot);

        self::assertFalse($report['blocked'], json_encode($report));
        self::assertSame(3, $this->countRows('journal_entries'));
        self::assertSame(8, $this->countRows('chart_of_accounts'));
    }

    public function testOpeningBalancesUseBaseCurrencyAndStaySeparateFromYearTurnover(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['ucetni-osnova'][] = ['id' => '701', 'kod' => '701', 'nazev' => 'Synthetic opening account'];
        $snapshot['ucet'] = array_splice($snapshot['ucetni-osnova'], 4, 4);
        $snapshot['stav-uctu'] = [
            ['ucet' => 'code:221000', 'mena' => 'code:CZK', 'pocatekMD' => '50.00', 'pocatekDal' => '0.00', 'postingPeriod' => 'code:2025'],
            ['ucet' => 'code:311000', 'mena' => 'code:CZK', 'pocatekMD' => '0.00', 'pocatekDal' => '50.00', 'postingPeriod' => 'code:2025'],
            ['ucet' => 'code:221000', 'mena' => 'code:EUR', 'pocatekMD' => '7.00', 'pocatekDal' => '0.00', 'postingPeriod' => 'code:2025'],
            ['ucet' => 'code:211000', 'mena' => 'code:USD', 'pocatekMD' => '3.00', 'pocatekDal' => '0.00', 'postingPeriod' => 'code:2025'],
        ];

        $report = $this->transfer($snapshot);

        self::assertFalse($report['blocked'], json_encode($report));
        self::assertContains('opening_foreign_base_missing', $report['warnings']);
        self::assertSame(5, $this->countRows('journal_entries'));
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*), SUM(l.amount) FROM journal_entries e
            JOIN journal_entry_lines l ON l.entry_id = e.id WHERE e.supplier_id = ? AND e.source_type = "opening" AND l.side = "debit"');
        $stmt->execute([$this->supplier]);
        [$count, $amount] = $stmt->fetch(\PDO::FETCH_NUM);
        self::assertSame(2, (int) $count);
        self::assertEquals(100.0, (float) $amount);
        $fx = $this->db->pdo()->query("SELECT l.currency_code, l.amount_foreign, l.fx_rate
            FROM journal_entries e JOIN journal_entry_lines l ON l.entry_id = e.id
            JOIN chart_of_accounts a ON a.id = l.account_id
            WHERE e.supplier_id = {$this->supplier} AND e.source_type = 'opening'
              AND a.account_code = '221.000'")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('EUR', $fx['currency_code']);
        self::assertSame('7.00', $fx['amount_foreign']);
        self::assertSame('7.142857', $fx['fx_rate']);
        $again = $this->transfer($snapshot);
        self::assertFalse($again['blocked'], json_encode($again));
        self::assertSame(5, $this->countRows('journal_entries'));
        $snapshot['stav-uctu'][0]['pocatekMD'] = '51.00';
        $snapshot['stav-uctu'][1]['pocatekDal'] = '51.00';
        $changed = $this->transfer($snapshot);
        self::assertTrue($changed['blocked']);
        self::assertGreaterThan(0, $changed['changed']);
        self::assertSame(5, $this->countRows('journal_entries'));
    }

    public function testZeroValueSourceJournalRowsDoNotBlockBalancedAccounting(): void
    {
        $snapshot = $this->snapshot();
        $zero = ['idUcetniDenik' => '4', 'datUcto' => '2025-06-03', 'mdUcet' => 'code:311000',
            'dalUcet' => 'code:602000', 'sumTuz' => '0.00'];
        $snapshot['ucetni-denik'][] = $zero;
        $snapshot['pohyb-na-uctech'][] = $zero + ['sumTuzMd' => '0.00', 'sumTuzDal' => '0.00'];

        $report = $this->transfer($snapshot);

        self::assertFalse($report['blocked'], json_encode($report));
        self::assertSame(0, $report['failed']);
        self::assertSame(3, $this->countRows('journal_entries'));
        self::assertSame(1, $report['counts']['journal_zero_entries_skipped']);
    }

    public function testSourceVatRateOutsideCzechScheduleKeepsItemSnapshotForReview(): void
    {
        $snapshot = $this->snapshot();
        $extra = $snapshot['faktura-vydana'][0];
        $extra['id'] = '102';
        $extra['kod'] = 'SYN-2025-002';
        $extra['sumDphCelkem'] = '23.00';
        $extra['sumCelkem'] = '123.00';
        $extra['polozkyDokladu'][0]['id'] = '202';
        $extra['polozkyDokladu'][0]['szbDph'] = '23';
        $extra['polozkyDokladu'][0]['sumDph'] = '23.00';
        $extra['polozkyDokladu'][0]['sumCelkem'] = '123.00';
        $snapshot['faktura-vydana'][] = $extra;

        $report = $this->transfer($snapshot);

        self::assertFalse($report['blocked'], json_encode($report));
        self::assertSame(2, $this->countRows('invoices'));
        self::assertContains('document_vat_rate_placeholder_requires_review', $report['warnings']);
        $stmt = $this->db->pdo()->prepare('SELECT i.vat_rate_snapshot, i.total_vat FROM invoice_items i
            JOIN invoices d ON d.id = i.invoice_id WHERE d.supplier_id = ? AND d.total_with_vat = 123.00');
        $stmt->execute([$this->supplier]);
        $item = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertEquals(23.0, (float) $item['vat_rate_snapshot']);
        self::assertEquals(23.0, (float) $item['total_vat']);
    }

    public function testPreviouslyImportedJournalRowChangedToZeroIsReportedAsConflict(): void
    {
        $snapshot = $this->snapshot();
        self::assertFalse($this->transfer($snapshot)['blocked']);
        $snapshot['_meta']['mode'] = 'sync';
        $snapshot['ucetni-denik'][0]['sumTuz'] = '0.00';
        $snapshot['pohyb-na-uctech'][0]['sumTuz'] = '0.00';
        $snapshot['pohyb-na-uctech'][0]['sumTuzMd'] = '0.00';
        $snapshot['pohyb-na-uctech'][0]['sumTuzDal'] = '0.00';

        $report = $this->transfer($snapshot);

        self::assertTrue($report['blocked']);
        self::assertGreaterThan(0, $report['changed']);
        self::assertSame(3, $this->countRows('journal_entries'));
    }

    public function testForeignDocumentCreatesMissingTenantCurrency(): void
    {
        $snapshot = $this->snapshot();
        $extra = $snapshot['faktura-vydana'][0];
        $extra['id'] = '102';
        $extra['kod'] = 'SYN-2025-USD';
        $extra['mena'] = 'code:USD';
        $extra['kurz'] = '23.50';
        $extra['sumZklCelkemMen'] = '100.00';
        $extra['sumDphCelkemMen'] = '21.00';
        $extra['sumCelkemMen'] = '121.00';
        $extra['polozkyDokladu'][0]['id'] = '202';
        $extra['polozkyDokladu'][0]['sumZklMen'] = '100.00';
        $extra['polozkyDokladu'][0]['sumDphMen'] = '21.00';
        $extra['polozkyDokladu'][0]['sumCelkemMen'] = '121.00';
        $snapshot['faktura-vydana'][] = $extra;

        $report = $this->transfer($snapshot);

        self::assertFalse($report['blocked'], json_encode($report));
        self::assertSame(2, $this->countRows('invoices'));
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM currencies WHERE supplier_id = ? AND code = "USD"');
        $stmt->execute([$this->supplier]);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }
    public function testFailedSourceControlRollsBackEveryTargetAndMap(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['pohyb-na-uctech'][0]['sumTuzMd'] = '100.01';
        $report = $this->transfer($snapshot);
        self::assertTrue($report['blocked']);
        self::assertSame(0, $report['created']);
        foreach (['invoices', 'journal_entries', 'bank_transactions', 'abra_flexi_import_map'] as $table) self::assertSame(0, $this->countRows($table), $table);
    }

    public function testLongSourceSymbolDoesNotOverflowPaymentSymbol(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['faktura-vydana'][0]['varSym'] = '123456789012345';

        $report = $this->transfer($snapshot);

        self::assertFalse($report['blocked'], json_encode($report));
        $stmt = $this->db->pdo()->prepare('SELECT varsymbol, payment_variable_symbol FROM invoices WHERE supplier_id = ?');
        $stmt->execute([$this->supplier]);
        $invoice = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('SYN-2025-001', $invoice['varsymbol']);
        self::assertNull($invoice['payment_variable_symbol']);
    }
    public function testPurchaseDocumentsSharingVendorNumberAndDateRemainDistinct(): void
    {
        $snapshot = $this->snapshot();
        $purchase = [
            'kod' => 'SYN-PF-1', 'cisDosle' => 'SYN-VENDOR-1', 'datVyst' => '2025-06-01',
            'datSplat' => '2025-06-15', 'mena' => 'code:CZK',
            'sumZklCelkem' => '100.00', 'sumDphCelkem' => '21.00', 'sumCelkem' => '121.00',
            'firma' => ['id' => '7', 'evidencePath' => 'adresar/7'], 'nazFirmy' => 'Synthetic partner',
            'polozkyDokladu' => [['id' => '801', 'mnozMj' => '1', 'sumZkl' => '100.00', 'sumDph' => '21.00', 'sumCelkem' => '121.00']],
        ];
        $snapshot['faktura-prijata'] = [
            ['id' => '701'] + $purchase,
            ['id' => '702', 'kod' => 'SYN-PF-2'] + $purchase,
        ];

        $report = $this->transfer($snapshot);

        self::assertFalse($report['blocked'], json_encode($report));
        self::assertSame(2, $this->countRows('purchase_invoices'));
        self::assertContains('document_vendor_number_collision_requires_review', $report['warnings']);
        $stmt = $this->db->pdo()->prepare('SELECT vendor_invoice_number FROM purchase_invoices WHERE supplier_id = ? ORDER BY id');
        $stmt->execute([$this->supplier]);
        self::assertCount(2, array_unique($stmt->fetchAll(\PDO::FETCH_COLUMN)));
    }
    public function testNonFinancialAndUnmappedInternalLinksDoNotBlockAccounting(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['vazba'][] = [
            'id' => '402', 'a' => 'display', 'a@ref' => '/c/demo/faktura-vydana/101',
            'b' => 'display', 'b@ref' => '/c/demo/skladovy-pohyb/900',
            'castka' => '0.00', 'mena' => 'code:CZK',
        ];
        $snapshot['vazba'][] = [
            'id' => '403', 'a' => 'display', 'a@ref' => '/c/demo/banka/301',
            'b' => 'display', 'b@ref' => '/c/demo/interni-doklad/901',
            'castka' => '10.00', 'mena' => 'code:CZK',
        ];

        $report = $this->transfer($snapshot);

        self::assertFalse($report['blocked'], json_encode($report));
        self::assertSame(1, $this->countRows('payment_matches'));
        self::assertContains('zero_amount_link_skipped', $report['warnings']);
        self::assertContains('unsupported_document_link:unknown', $report['warnings']);
    }
    public function testPaymentLinkWithApiReferencesCreatesMatch(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['vazba'][0]['a'] = '999';
        $snapshot['vazba'][0]['a@ref'] = '/c/demo/faktura-vydana/101.json';
        $snapshot['vazba'][0]['b'] = '888';
        $snapshot['vazba'][0]['b@ref'] = '/c/demo/banka/301.json';

        $report = $this->transfer($snapshot);

        self::assertFalse($report['blocked'], json_encode($report));
        self::assertSame(1, $this->countRows('payment_matches'));
    }
    public function testDifferentPaymentCurrencyLeavesLinkForManualReviewWithoutLosingJournal(): void
    {
        $snapshot = $this->snapshot();
        $invoice = &$snapshot['faktura-vydana'][0];
        $invoice['mena'] = 'code:EUR';
        $invoice['kurz'] = '25.00';
        $invoice['sumZklCelkemMen'] = '4.00';
        $invoice['sumDphCelkemMen'] = '0.84';
        $invoice['sumCelkemMen'] = '4.84';
        $invoice['polozkyDokladu'][0]['sumZklMen'] = '4.00';
        $invoice['polozkyDokladu'][0]['sumDphMen'] = '0.84';
        $invoice['polozkyDokladu'][0]['sumCelkemMen'] = '4.84';
        unset($invoice);

        $report = $this->transfer($snapshot);

        self::assertFalse($report['blocked'], json_encode($report));
        self::assertSame(3, $this->countRows('journal_entries'));
        self::assertSame(0, $this->countRows('payment_matches'));
        self::assertContains('payment_currency_conversion_requires_review', $report['warnings']);
    }

    public function testForeignInvoiceAndCzkCashRemainUnlinkedForReview(): void
    {
        $snapshot = $this->snapshot();
        $invoice = &$snapshot['faktura-vydana'][0];
        $invoice['mena'] = 'code:EUR';
        $invoice['kurz'] = '25.00';
        $invoice['sumZklCelkemMen'] = '4.00';
        $invoice['sumDphCelkemMen'] = '0.84';
        $invoice['sumCelkemMen'] = '4.84';
        $invoice['polozkyDokladu'][0]['sumZklMen'] = '4.00';
        $invoice['polozkyDokladu'][0]['sumDphMen'] = '0.84';
        $invoice['polozkyDokladu'][0]['sumCelkemMen'] = '4.84';
        unset($invoice);
        $snapshot['pokladni-pohyb'] = [[
            'id' => '501', 'datUcto' => '2025-06-02', 'mena' => 'code:CZK',
            'sumCelkem' => '121.00', 'typPohybuK' => 'typPohybu.prijem',
        ]];
        $snapshot['banka'] = [];
        $snapshot['vazba'][0]['b'] = ['id' => '501', 'evidencePath' => 'pokladni-pohyb/501'];

        $report = $this->transfer($snapshot);

        self::assertFalse($report['blocked'], json_encode($report));
        self::assertContains('payment_currency_conversion_requires_review', $report['warnings']);
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM cash_documents WHERE supplier_id = ? AND invoice_id IS NOT NULL');
        $stmt->execute([$this->supplier]);
        self::assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testPurchaseCashPaymentUpdatesImportedBalance(): void
    {
        $snapshot = $this->snapshot();
        $purchase = $snapshot['faktura-vydana'][0];
        $purchase['id'] = '701';
        $purchase['kod'] = 'SYN-PF-1';
        $purchase['cisDosle'] = 'SYN-VENDOR-1';
        $purchase['polozkyDokladu'][0]['clenDph'] = 'code:40-41';
        $snapshot['faktura-vydana'] = [];
        $snapshot['faktura-prijata'] = [$purchase];
        $snapshot['pokladni-pohyb'] = [[
            'id' => '501', 'datUcto' => '2025-06-02', 'mena' => 'code:CZK',
            'sumCelkem' => '121.00', 'typPohybuK' => 'typPohybu.vydej',
        ]];
        $snapshot['banka'] = [];
        $snapshot['vazba'][0]['a'] = ['id' => '701', 'evidencePath' => 'faktura-prijata/701'];
        $snapshot['vazba'][0]['b'] = ['id' => '501', 'evidencePath' => 'pokladni-pohyb/501'];
        foreach ($snapshot['ucetni-denik'] as &$entry) {
            $entry['idDokl@evidencePath'] = $entry['idUcetniDenik'] === '3'
                ? 'pokladni-pohyb/501' : 'faktura-prijata/701';
        }
        unset($entry);
        foreach ($snapshot['pohyb-na-uctech'] as &$movement) {
            $movement['idDokl@evidencePath'] = $movement['idUcetniDenik'] === '3'
                ? 'pokladni-pohyb/501' : 'faktura-prijata/701';
        }
        unset($movement);

        $report = $this->transfer($snapshot);

        self::assertFalse($report['blocked'], json_encode($report));
        $stmt = $this->db->pdo()->prepare('SELECT paid_amount_invoice_ccy, status, booked_at FROM purchase_invoices WHERE supplier_id = ?');
        $stmt->execute([$this->supplier]);
        $purchase = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertEquals(121.0, (float) $purchase['paid_amount_invoice_ccy']);
        self::assertSame('paid', $purchase['status']);
        self::assertNotNull($purchase['booked_at']);
    }
    public function testCatalogPageCreatesAndReusesProductWithoutChangingManualEdit(): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET stock_enabled = 1 WHERE id = ?')->execute([$this->supplier]);
        $rows = [[
            'id' => '1', 'kod' => 'SYN-GOODS-1', 'nazev' => 'Syntetické zboží', 'mj1' => 'code:KS',
            'typZasobyK' => 'typZasoby.zbozi', 'typSzbDphK' => 'typSzbDph.dphZakl',
            'cenaZaklBezDph' => '100.00', 'szbDph' => 21, 'skladove' => true,
            'typCenyDphK' => 'typCeny.sDph',
            'odberatele' => [
                ['mena' => 'code:EUR', 'prodejCena' => '121.00'],
                ['mena' => 'code:GBP', 'prodejCena' => '24.20'],
                ['mena' => 'code:USD', 'prodejCena' => '30.00'],
            ],
        ]];
        $cancelled = static fn (): bool => false;

        $first = $this->catalogImporter->importPage($this->supplier, $rows, $cancelled);
        $this->db->pdo()->prepare("UPDATE stock_item_prices SET fixed_price = '30.00', computed_price = '30.00'
            WHERE supplier_id = ? AND currency_code = 'USD'")->execute([$this->supplier]);
        $again = $this->catalogImporter->importPage($this->supplier, $rows, $cancelled);

        self::assertSame(1, $first['created']);
        self::assertSame(1, $again['skipped'], json_encode($again));
        self::assertSame(1, $this->countRows('stock_items'));
        $price = $this->db->pdo()->prepare('SELECT currency_code, price_mode, fixed_price, computed_price
            FROM stock_item_prices WHERE supplier_id = ? ORDER BY currency_code');
        $price->execute([$this->supplier]);
        self::assertSame([
            ['currency_code' => 'CZK', 'price_mode' => 'fixed', 'fixed_price' => '100.000000', 'computed_price' => '100.000000'],
            ['currency_code' => 'EUR', 'price_mode' => 'fixed', 'fixed_price' => '100.000000', 'computed_price' => '100.000000'],
            ['currency_code' => 'GBP', 'price_mode' => 'fixed', 'fixed_price' => '20.000000', 'computed_price' => '20.000000'],
            ['currency_code' => 'USD', 'price_mode' => 'fixed', 'fixed_price' => '24.793388', 'computed_price' => '24.793388'],
        ], $price->fetchAll(\PDO::FETCH_ASSOC));
        $this->db->pdo()->prepare('UPDATE stock_items SET name = "Manually edited" WHERE supplier_id = ?')->execute([$this->supplier]);
        $changed = $this->catalogImporter->importPage($this->supplier, $rows, $cancelled);
        self::assertSame(1, $changed['changed']);
        self::assertContains('catalog_target_changed_requires_review', $changed['warnings']);
    }
    public function testChangedSourceNeverOverwritesImportedDocument(): void
    {
        $snapshot = $this->snapshot();
        self::assertFalse($this->transfer($snapshot)['blocked']);
        $snapshot['faktura-vydana'][0]['popis'] = 'Changed synthetic source';
        $report = $this->transfer($snapshot);
        self::assertTrue($report['blocked']);
        self::assertGreaterThan(0, $report['changed']);
        self::assertSame(1, $this->countRows('invoices'));
        self::assertSame(3, $this->countRows('journal_entries'));
    }
    public function testNewPaymentSynchronizesWithoutConflictingWithInvoicePaymentState(): void
    {
        $paid = $this->snapshot();
        $unpaid = $paid;
        $unpaid['banka'] = [];
        $unpaid['vazba'] = [];
        $unpaid['ucetni-denik'] = array_slice($unpaid['ucetni-denik'], 0, 2);
        $unpaid['pohyb-na-uctech'] = array_slice($unpaid['pohyb-na-uctech'], 0, 2);
        $unpaid['faktura-vydana'][0] += ['lastUpdate' => '2025-06-01T12:00:00Z', 'stavUhrK' => 'stavUhr.neuhrazeno', 'zbyvaUhradit' => '121.00'];
        self::assertFalse($this->transfer($unpaid)['blocked']);
        $paid['_meta']['mode'] = 'sync';
        $paid['faktura-vydana'][0] += ['lastUpdate' => '2025-06-02T12:00:00Z', 'stavUhrK' => 'stavUhr.uhrazeno', 'zbyvaUhradit' => '0.00',
            'datUhr' => '2025-06-02', 'vazby' => [['id' => '401']]];
        $report = $this->transfer($paid);
        self::assertFalse($report['blocked'], json_encode($report));
        self::assertSame(0, $report['changed']);
        self::assertSame(1, $this->countRows('invoices'));
        self::assertSame(3, $this->countRows('journal_entries'));
        self::assertSame(1, $this->countRows('payment_matches'));
        $stmt = $this->db->pdo()->prepare('SELECT paid_total FROM invoices WHERE supplier_id = ?');
        $stmt->execute([$this->supplier]);
        self::assertEquals(121.0, (float) $stmt->fetchColumn());
    }

    public function testCashPaymentSyncRefreshesIssuedInvoiceBalance(): void
    {
        $initial = $this->snapshot();
        $initial['banka'] = $initial['vazba'] = [];
        $initial['ucetni-denik'] = array_slice($initial['ucetni-denik'], 0, 2);
        $initial['pohyb-na-uctech'] = array_slice($initial['pohyb-na-uctech'], 0, 2);
        $initial['faktura-vydana'][0]['zbyvaUhradit'] = '121.00';
        self::assertFalse($this->transfer($initial)['blocked']);

        $delta = $initial;
        $delta['_meta']['mode'] = 'sync';
        $delta['faktura-vydana'] = [];
        $cash = ['id' => '501', 'kod' => 'SYN-CASH-1', 'datUcto' => '2025-06-02',
            'mena' => 'code:CZK', 'sumCelkem' => '121.00', 'typPohybuK' => 'typPohybu.prijem',
            'pokladna' => ['id' => '1', 'evidencePath' => 'pokladna/1']];
        $entry = ['idUcetniDenik' => '3', 'datUcto' => '2025-06-02', 'mdUcet' => 'code:221000',
            'dalUcet' => 'code:311000', 'sumTuz' => '121.00', 'idDokl@evidencePath' => 'pokladni-pohyb/501'];
        $delta['pokladni-pohyb'] = [$cash];
        $delta['ucetni-denik'] = [$entry];
        $delta['pohyb-na-uctech'] = [$entry + ['sumTuzMd' => '121.00', 'sumTuzDal' => '121.00']];
        $delta['vazba'] = [['id' => '402', 'a' => ['id' => '101', 'evidencePath' => 'faktura-vydana/101'],
            'b' => ['id' => '501', 'evidencePath' => 'pokladni-pohyb/501'], 'castka' => '121.00', 'mena' => 'code:CZK']];

        $report = $this->transfer($delta);

        self::assertFalse($report['blocked'], json_encode($report));
        $stmt = $this->db->pdo()->prepare('SELECT paid_total FROM invoices WHERE supplier_id = ?');
        $stmt->execute([$this->supplier]);
        self::assertEquals(121.0, (float) $stmt->fetchColumn());
    }

    public function testSelectedYearPaymentKeepsReferencedPreviousYearInvoice(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['faktura-vydana'][0]['datVyst'] = $snapshot['faktura-vydana'][0]['datUcto'] = '2024-12-20';
        $snapshot['faktura-vydana'][0]['datSplat'] = '2025-01-05';
        $snapshot['faktura-vydana'][0]['zbyvaUhradit'] = '0.00';
        $snapshot['banka'][0]['sumCelkem'] = '71.00';
        $snapshot['vazba'][0]['castka'] = '71.00';
        $snapshot['ucetni-denik'][2]['sumTuz'] = '71.00';
        $snapshot['ucetni-denik'] = array_slice($snapshot['ucetni-denik'], 2);
        $snapshot['pohyb-na-uctech'] = array_slice($snapshot['pohyb-na-uctech'], 2);
        $snapshot['pohyb-na-uctech'][0]['sumTuz'] = '71.00';
        $snapshot['pohyb-na-uctech'][0]['sumTuzMd'] = '71.00';
        $snapshot['pohyb-na-uctech'][0]['sumTuzDal'] = '71.00';
        $report = $this->transfer($snapshot);
        self::assertFalse($report['blocked'], json_encode($report));
        self::assertSame(1, $this->countRows('invoices'));
        self::assertSame(1, $this->countRows('payment_matches'));
        self::assertSame(1, $this->countRows('journal_entries'));
        $invoice = $this->db->pdo()->query("SELECT status, paid_total FROM invoices WHERE supplier_id = {$this->supplier}")
            ->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('paid', $invoice['status']);
        self::assertEquals(121.0, (float) $invoice['paid_total']);
    }

    public function testSelectedYearPaymentKeepsReferencedPreviousYearPurchaseSettlement(): void
    {
        $snapshot = $this->snapshot();
        $purchase = $snapshot['faktura-vydana'][0];
        $purchase['id'] = '701';
        $purchase['kod'] = 'SYN-PF-1';
        $purchase['cisDosle'] = 'SYN-VENDOR-1';
        $purchase['datVyst'] = $purchase['datUcto'] = '2024-12-20';
        $purchase['datSplat'] = '2025-01-05';
        $purchase['zbyvaUhradit'] = '0.00';
        $purchase['polozkyDokladu'][0]['clenDph'] = 'code:40-41';
        $snapshot['faktura-vydana'] = [];
        $snapshot['faktura-prijata'] = [$purchase];
        $snapshot['banka'][0]['sumCelkem'] = '-71.00';
        $snapshot['banka'][0]['typPohybuK'] = 'typPohybu.vydej';
        $snapshot['vazba'][0]['a'] = ['id' => '701', 'evidencePath' => 'faktura-prijata/701'];
        $snapshot['vazba'][0]['castka'] = '71.00';
        $snapshot['ucetni-denik'] = array_slice($snapshot['ucetni-denik'], 2);
        $snapshot['ucetni-denik'][0]['sumTuz'] = '71.00';
        $snapshot['pohyb-na-uctech'] = array_slice($snapshot['pohyb-na-uctech'], 2);
        $snapshot['pohyb-na-uctech'][0]['sumTuz'] = '71.00';
        $snapshot['pohyb-na-uctech'][0]['sumTuzMd'] = '71.00';
        $snapshot['pohyb-na-uctech'][0]['sumTuzDal'] = '71.00';

        $report = $this->transfer($snapshot);

        self::assertFalse($report['blocked'], json_encode($report));
        $stmt = $this->db->pdo()->prepare('SELECT status, paid_amount_invoice_ccy
            FROM purchase_invoices WHERE supplier_id = ?');
        $stmt->execute([$this->supplier]);
        $target = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('paid', $target['status']);
        self::assertEquals(121.0, (float) $target['paid_amount_invoice_ccy']);
    }

    public function testSourcePaidPurchaseWithoutSelectedPaymentKeepsSettlement(): void
    {
        $snapshot = $this->snapshot();
        $purchase = $snapshot['faktura-vydana'][0];
        $purchase['id'] = '701';
        $purchase['kod'] = 'SYN-PF-1';
        $purchase['cisDosle'] = 'SYN-VENDOR-1';
        $purchase['zbyvaUhradit'] = '0.00';
        $purchase['datUhr'] = '2025-06-03';
        $purchase['polozkyDokladu'][0]['clenDph'] = 'code:40-41';
        $snapshot['faktura-vydana'] = [];
        $snapshot['faktura-prijata'] = [$purchase];
        $snapshot['banka'] = $snapshot['vazba'] = [];
        $snapshot['ucetni-denik'] = array_slice($snapshot['ucetni-denik'], 0, 2);
        $snapshot['pohyb-na-uctech'] = array_slice($snapshot['pohyb-na-uctech'], 0, 2);

        $report = $this->transfer($snapshot);

        self::assertFalse($report['blocked'], json_encode($report));
        $stmt = $this->db->pdo()->prepare('SELECT status, paid_amount_invoice_ccy, paid_at
            FROM purchase_invoices WHERE supplier_id = ?');
        $stmt->execute([$this->supplier]);
        $target = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('paid', $target['status']);
        self::assertEquals(121.0, (float) $target['paid_amount_invoice_ccy']);
        self::assertSame('2025-06-03', $target['paid_at']);
    }

    /**
     * Závazek bez DPH (zde 518/325) s platbou z banky. Synteticky: 1 000 Kč.
     *
     * @param list<array{0:string,1:string,2:string}> $documentEntries [MD, D, částka]
     */
    private function payableSnapshot(string $account, array $documentEntries, string $total, bool $paidByBank, array $extra = []): array
    {
        $snapshot = $this->snapshot();
        $snapshot['faktura-vydana'] = [];
        $snapshot['zavazek'] = [array_replace([
            'id' => '801', 'kod' => 'SYN-ZAV-1', 'datVyst' => '2025-06-01', 'datUcto' => '2025-06-01', 'datSplat' => '2025-06-15',
            'mena' => 'code:CZK', 'sumZklCelkem' => $total, 'sumOsv' => $total, 'sumDphCelkem' => '0.00', 'sumCelkem' => $total,
            'zuctovano' => true, 'nazFirmy' => 'Synthetic lender', 'firma' => ['id' => '8', 'evidencePath' => 'adresar/8'],
            'stat' => 'code:CZ', 'popis' => 'Synthetic payable', 'primUcet' => 'code:' . $account, 'protiUcet' => 'code:' . $account,
            'clenDph' => 'code:000P', 'typDokl' => 'code:OST', 'bezPolozek' => true,
        ], $extra)];
        $entries = [];
        foreach ($documentEntries as $index => [$debit, $credit, $amount]) {
            $entries[] = ['idUcetniDenik' => (string) (11 + $index), 'datUcto' => '2025-06-01', 'mdUcet' => 'code:' . $debit,
                'dalUcet' => 'code:' . $credit, 'sumTuz' => $amount, 'idDokl@evidencePath' => 'zavazek/801'];
        }
        $snapshot['banka'] = $snapshot['vazba'] = [];
        if ($paidByBank) {
            $entries[] = ['idUcetniDenik' => '21', 'datUcto' => '2025-06-10', 'mdUcet' => 'code:' . $account,
                'dalUcet' => 'code:221000', 'sumTuz' => $total, 'idDokl@evidencePath' => 'banka/302'];
            $snapshot['banka'] = [['id' => '302', 'kod' => 'SYN-B-002', 'datUcto' => '2025-06-10', 'mena' => 'code:CZK',
                'sumCelkem' => $total, 'typPohybuK' => 'typPohybu.vydej', 'banka' => ['id' => '1', 'evidencePath' => 'bankovni-ucet/1'],
                'bankaUcet' => '1000000005', 'bankaKod' => '0100']];
            $snapshot['vazba'] = [['id' => '402', 'a' => ['id' => '801', 'evidencePath' => 'zavazek/801'],
                'b' => ['id' => '302', 'evidencePath' => 'banka/302'], 'castka' => $total, 'mena' => 'code:CZK']];
        }
        $snapshot['ucetni-denik'] = $entries;
        $snapshot['pohyb-na-uctech'] = array_map(static fn (array $row): array => $row + [
            'sumTuzMd' => $row['sumTuz'], 'sumTuzDal' => $row['sumTuz'],
        ], $entries);
        foreach (['518', '325', '331', '379', '562', '518000', '325000', '331000', '379000', '562000'] as $code) {
            $snapshot['ucetni-osnova'][] = ['id' => $code, 'kod' => $code, 'nazev' => 'Synthetic account'];
        }
        return $snapshot;
    }

    private function otherItem(): array|false
    {
        $stmt = $this->db->pdo()->prepare('SELECT oi.*, (SELECT COALESCE(SUM(a.amount), 0) FROM other_item_allocations a
                WHERE a.other_item_id = oi.id AND a.reversed_on IS NULL) AS allocated
            FROM other_items oi WHERE oi.supplier_id = ?');
        $stmt->execute([$this->supplier]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    public function testPayableWithoutVatBecomesPostedOtherItemPaidFromBank(): void
    {
        $snapshot = $this->payableSnapshot('325000', [['518000', '325000', '1000.00']], '1000.00', true);

        $report = $this->transfer($snapshot);

        self::assertFalse($report['blocked'], json_encode($report));
        self::assertSame(0, $this->countRows('purchase_invoices'));
        $item = $this->otherItem();
        self::assertNotFalse($item);
        self::assertSame(['payable', 'posted', '325000', '518000'], [$item['side'], $item['status'], $item['account_code'], $item['counter_account_code']]);
        self::assertEquals(1000.0, (float) $item['allocated']);
        self::assertEquals(0.0, (float) $item['settled_outside_amount']);
        $owner = $this->db->pdo()->prepare('SELECT source_type, source_id FROM journal_entries WHERE id = ?');
        $owner->execute([$item['journal_entry_id']]);
        self::assertSame(['source_type' => 'other_item', 'source_id' => (int) $item['id']], array_map(
            static fn ($v) => is_numeric($v) ? (int) $v : $v, $owner->fetch(\PDO::FETCH_ASSOC)));
        $kinds = $this->db->pdo()->prepare('SELECT kind FROM abra_flexi_import_map WHERE supplier_id = ? AND target_type = "other_item"');
        $kinds->execute([$this->supplier]);
        self::assertSame(['ostatni-zavazek'], $kinds->fetchAll(\PDO::FETCH_COLUMN));
        self::assertFalse((new TakenOverRecord($this->db))->isDocument($this->supplier, 'purchase_invoice', (int) $item['id']));

        $repeat = $this->transfer($snapshot);
        self::assertFalse($repeat['blocked'], json_encode($repeat));
        self::assertSame(1, $this->countRows('other_items'));
        self::assertSame(1, $this->countRows('other_item_allocations'));
    }

    public function testPayrollPayableStaysOnlyInJournal(): void
    {
        $snapshot = $this->payableSnapshot('331000', [['518000', '331000', '1000.00']], '1000.00', true);

        $report = $this->transfer($snapshot);

        self::assertFalse($report['blocked'], json_encode($report));
        self::assertSame(0, $this->countRows('purchase_invoices'));
        self::assertSame(0, $this->countRows('other_items'));
        self::assertSame(1, $report['counts']['other_items_payroll_or_tax_skipped'] ?? 0);
        self::assertSame(1, $report['counts']['payment_links_not_transferred_skipped'] ?? 0);
    }

    public function testLoanInstalmentWithUnchangedPrincipalIsUnpostedAndSettledFromSource(): void
    {
        // Splátka 1 000 Kč: jistina 379/379 nic nemění, úrok 562/379. Zdroj ji vede jako uhrazenou
        // zápočtem (bez platby v bance).
        $snapshot = $this->payableSnapshot('379000', [['379000', '379000', '900.00'], ['562000', '379000', '100.00']], '1000.00', false,
            ['typDokl' => 'code:SPK', 'zbyvaUhradit' => '0.00', 'datUhr' => '2025-06-15']);

        $report = $this->transfer($snapshot);

        self::assertFalse($report['blocked'], json_encode($report));
        $item = $this->otherItem();
        self::assertNotFalse($item);
        self::assertSame(['loan', 'confirmed', '379000'], [$item['kind'], $item['status'], $item['account_code']]);
        self::assertNull($item['journal_entry_id']);
        self::assertEquals(1000.0, (float) $item['settled_outside_amount']);
        $manual = $this->db->pdo()->prepare('SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ? AND source_type = "manual"');
        $manual->execute([$this->supplier]);
        self::assertSame(2, (int) $manual->fetchColumn());
        $found = (new \MyInvoice\Repository\OtherItemRepository($this->db))->find($this->supplier, (int) $item['id']);
        self::assertEquals(0.0, (float) $found['remaining_amount']);
        self::assertEquals(1000.0, (float) $found['paid_amount']);
        self::assertSame([], (new \MyInvoice\Service\Accounting\Obligations\OtherItemForecastService($this->db))
            ->dueBetween($this->supplier, '2025-01-01', '2025-12-31'));
    }

    public function testLoanBankAccountIsNotTransferredAsCompanyBankAccount(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['faktura-vydana'] = $snapshot['vazba'] = [];
        $snapshot['bankovni-ucet'] = [
            ['id' => '1', 'kod' => 'KB', 'buc' => '1000000005/0100', 'mena' => 'code:CZK', 'primUcet' => 'code:221000'],
            ['id' => '2', 'kod' => 'UVER', 'buc' => '1000000013/0100', 'mena' => 'code:CZK', 'primUcet' => 'code:461000'],
        ];
        $snapshot['banka'] = [['id' => '303', 'kod' => 'SYN-B-003', 'datUcto' => '2025-06-02', 'mena' => 'code:CZK',
            'sumCelkem' => '121.00', 'typPohybuK' => 'typPohybu.prijem', 'banka' => ['id' => '2', 'evidencePath' => 'bankovni-ucet/2']]];
        $snapshot['ucetni-denik'] = [['idUcetniDenik' => '31', 'datUcto' => '2025-06-02', 'mdUcet' => 'code:461000',
            'dalUcet' => 'code:311000', 'sumTuz' => '121.00', 'idDokl@evidencePath' => 'banka/303']];
        $snapshot['pohyb-na-uctech'] = array_map(static fn (array $row): array => $row + [
            'sumTuzMd' => $row['sumTuz'], 'sumTuzDal' => $row['sumTuz'],
        ], $snapshot['ucetni-denik']);
        foreach (['461', '461000'] as $code) {
            $snapshot['ucetni-osnova'][] = ['id' => $code, 'kod' => $code, 'nazev' => 'Synthetic account'];
        }

        $report = $this->transfer($snapshot);

        self::assertFalse($report['blocked'], json_encode($report));
        self::assertSame(0, $this->countRows('bank_transactions'));
        self::assertSame(1, $report['counts']['loan_account_movements_skipped'] ?? 0);
        self::assertContains('bank_loan_account_not_transferred', $report['warnings']);
        $accounts = $this->db->pdo()->prepare('SELECT account_number FROM supplier_bank_accounts WHERE supplier_id = ?');
        $accounts->execute([$this->supplier]);
        self::assertNotContains('1000000013', $accounts->fetchAll(\PDO::FETCH_COLUMN));
        self::assertSame(1, $this->countRows('journal_entries'));
    }
}
