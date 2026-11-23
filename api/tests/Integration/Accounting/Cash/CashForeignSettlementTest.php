<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Cash;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Cash\CashDocumentService;
use MyInvoice\Service\Accounting\Cash\CashException;
use MyInvoice\Service\Accounting\Cash\CashRegisterService;
use MyInvoice\Service\Accounting\Cash\CashSettlementService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Korunová úhrada cizoměnové faktury a účtenky z korunové pokladny (požadavek #39).
 *
 * Saldokonto (311 / závazek dokladu) se odúčtuje v účetní hodnotě dokladu (kurz
 * předpisu), pokladna 211 skutečnými korunami a rozdíl je kurzový zisk 663 nebo
 * ztráta 563 — stejně jako u bankovní korunové úhrady. Jen syntetická data.
 */
#[Group('integration')]
final class CashForeignSettlementTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const YEAR = 2099;

    private Connection $db;
    private CashDocumentService $service;
    private CashRegisterService $registers;
    private CashSettlementService $settlement;
    private PostingService $posting;
    private AccountingPeriodRepository $periods;

    private int $supplierId = 0;
    private int $eurId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $czkId = 0;
    private int $periodId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 5);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->service = $container->get(CashDocumentService::class);
        $this->registers = $container->get(CashRegisterService::class);
        $this->settlement = $container->get(CashSettlementService::class);
        $this->posting = $container->get(PostingService::class);
        $this->periods = $container->get(AccountingPeriodRepository::class);

        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $this->czkId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        if ($source === 0 || $this->userId === 0 || $this->czId === 0 || $this->czkId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/user/country/currency) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")->execute([$this->supplierId]);
        $container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $pdo->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, 'EUR', 'EUR', 'EUR', 'Euro', 'Euro', 2, 1, 0)"
        )->execute([$this->supplierId]);
        $this->eurId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    /** Kurz úhrady nad kurzem předpisu = ztráta 563, pod ním = zisk 663. */
    public static function purchaseRates(): iterable
    {
        yield 'kurz nahoru, ztráta' => [24500.00, '563', 'debit', 500.00];
        yield 'kurz dolů, zisk' => [23600.00, '663', 'credit', 400.00];
    }

    #[DataProvider('purchaseRates')]
    public function testForeignPurchaseInvoicePaidInCzkPostsExchangeDifference(float $czk, string $fxAccount, string $fxSide, float $fxAmount): void
    {
        $register = $this->register();
        $pf = $this->purchase('invoice', 1000.00, 24.0, '321');

        $result = $this->service->create($this->supplierId, $this->payment('purchase_payment', 'out', $czk, $register, ['purchase_invoice_id' => $pf]), $this->userId);

        self::assertSame('posted', $result['status']);
        $lines = $this->lines((int) $result['journal_entry_id']);
        self::assertSame(['debit' => 24000.00, 'credit' => 0.0], $lines['321']['sum']);
        self::assertSame(['EUR', 24.0, 1000.00], $lines['321']['trace']);
        self::assertSame(['debit' => 0.0, 'credit' => $czk], $lines['211']['sum']);
        self::assertSame($fxAmount, $lines[$fxAccount]['sum'][$fxSide]);
        self::assertSame('paid', $this->purchaseValue($pf, 'status'));
        self::assertSame(1000.00, $this->settledDocumentAmount((int) $result['id']));
        self::assertSame(0.0, $this->service->purchaseRemaining($this->supplierId, $pf));
    }

    public function testForeignReceiptIsPaidFromItsOwnPayableAccount(): void
    {
        $register = $this->register();
        $this->db->pdo()->prepare("UPDATE supplier SET receipt_payable_account_code = '325' WHERE id = ?")->execute([$this->supplierId]);
        $receipt = $this->purchase('receipt', 100.00, 25.0, '325');

        $result = $this->service->create($this->supplierId, $this->payment('purchase_payment', 'out', 2600.00, $register, ['purchase_invoice_id' => $receipt]), $this->userId);

        $lines = $this->lines((int) $result['journal_entry_id']);
        self::assertSame(['debit' => 2500.00, 'credit' => 0.0], $lines['325']['sum']);
        self::assertSame(['debit' => 0.0, 'credit' => 2600.00], $lines['211']['sum']);
        self::assertSame(100.00, $lines['563']['sum']['debit']);
        self::assertArrayNotHasKey('321', $lines);
        self::assertSame('paid', $this->purchaseValue($receipt, 'status'));
    }

    public static function invoiceRates(): iterable
    {
        yield 'kurz nahoru, zisk' => [24500.00, '663', 'credit', 500.00];
        yield 'kurz dolů, ztráta' => [23500.00, '563', 'debit', 500.00];
    }

    #[DataProvider('invoiceRates')]
    public function testForeignIssuedInvoiceCollectedInCzkPostsExchangeDifference(float $czk, string $fxAccount, string $fxSide, float $fxAmount): void
    {
        $register = $this->register();
        $invoice = $this->invoice(1000.00, 24.0);

        $result = $this->service->create($this->supplierId, $this->payment('invoice_payment', 'in', $czk, $register, ['invoice_id' => $invoice]), $this->userId);

        $lines = $this->lines((int) $result['journal_entry_id']);
        self::assertSame(['debit' => $czk, 'credit' => 0.0], $lines['211']['sum']);
        self::assertSame(['debit' => 0.0, 'credit' => 24000.00], $lines['311']['sum']);
        self::assertSame(['EUR', 24.0, 1000.00], $lines['311']['trace']);
        self::assertSame($fxAmount, $lines[$fxAccount]['sum'][$fxSide]);
        $row = $this->invoiceRow($invoice);
        self::assertSame('paid', $row['status']);
        self::assertSame('1000.00', $row['paid_total']);
        $payment = $this->db->pdo()->query('SELECT amount, currency FROM invoice_payments WHERE invoice_id = ' . $invoice)->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['amount' => '1000.00', 'currency' => 'EUR'], $payment);
    }

    /** Částečná úhrada vydané faktury korunami se přepočte kurzem dokladu jako u banky. */
    public function testPartialForeignIssuedInvoicePaymentUsesDocumentRate(): void
    {
        $register = $this->register();
        $invoice = $this->invoice(1000.00, 24.0);

        $result = $this->service->create($this->supplierId, $this->payment('invoice_payment', 'in', 12000.00, $register, ['invoice_id' => $invoice]), $this->userId);

        $lines = $this->lines((int) $result['journal_entry_id']);
        self::assertSame(['EUR', 24.0, 500.00], $lines['311']['trace']);
        self::assertSame(12000.00, $lines['311']['sum']['credit']);
        self::assertArrayNotHasKey('563', $lines);
        self::assertArrayNotHasKey('663', $lines);
        self::assertSame('500.00', $this->invoiceRow($invoice)['paid_total']);
    }

    /** Storno vrátí fakturu mezi neuhrazené a protizápis zruší i kurzový rozdíl. */
    public function testReversalReopensForeignPurchase(): void
    {
        $register = $this->register();
        $pf = $this->purchase('invoice', 1000.00, 24.0, '321');
        $result = $this->service->create($this->supplierId, $this->payment('purchase_payment', 'out', 24500.00, $register, ['purchase_invoice_id' => $pf]), $this->userId);

        $this->service->reverse($this->supplierId, (int) $result['id'], ['reason' => 'Chybná úhrada'], $this->userId);

        self::assertSame('booked', $this->purchaseValue($pf, 'status'));
        self::assertSame(1000.00, $this->service->purchaseRemaining($this->supplierId, $pf));
        $balance = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END), 0)
               FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND a.account_code = '563'"
        );
        $balance->execute([$this->supplierId]);
        self::assertSame(0.0, round((float) $balance->fetchColumn(), 2));
    }

    public function testPurchaseOutsideToleranceIsRejected(): void
    {
        $register = $this->register();
        $pf = $this->purchase('invoice', 1000.00, 24.0, '321');
        try {
            $this->service->create($this->supplierId, $this->payment('purchase_payment', 'out', 12000.00, $register, ['purchase_invoice_id' => $pf]), $this->userId);
            self::fail('Korunová částka mimo kurzovou toleranci nesmí cizoměnový doklad uhradit.');
        } catch (CashException $e) {
            self::assertSame('fx_amount_out_of_tolerance', $e->errorCode);
            self::assertSame(24000.00, $e->extra['expected_czk']);
        }
        self::assertSame('booked', $this->purchaseValue($pf, 'status'));
    }

    public function testPartiallySettledForeignPurchaseIsRejected(): void
    {
        $register = $this->register();
        $pf = $this->purchase('invoice', 1000.00, 24.0, '321');
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET settled_outside_amount = 100 WHERE id = ?')->execute([$pf]);
        try {
            $this->service->create($this->supplierId, $this->payment('purchase_payment', 'out', 21600.00, $register, ['purchase_invoice_id' => $pf]), $this->userId);
            self::fail('Částečně uhrazený cizoměnový doklad se korunami z pokladny doplatit nesmí.');
        } catch (CashException $e) {
            self::assertSame('cross_currency_partial_unsupported', $e->errorCode);
        }
    }

    public function testForeignCurrencyRegisterStillCannotPayInvoices(): void
    {
        $register = $this->registers->create($this->supplierId, ['name' => 'Pokladna EUR', 'account_code' => '211.900', 'currency_code' => 'EUR']);
        $pf = $this->purchase('invoice', 1000.00, 24.0, '321');
        try {
            $this->service->create($this->supplierId, $this->payment('purchase_payment', 'out', 1000.00, $register, [
                'purchase_invoice_id' => $pf, 'fx_rate' => 24.0,
            ]), $this->userId);
            self::fail('Valutová pokladna fakturu hradit nesmí.');
        } catch (CashException $e) {
            self::assertSame('foreign_register_purpose_unsupported', $e->errorCode);
        }
    }

    public function testClosedPeriodRefusesForeignSettlement(): void
    {
        $register = $this->register();
        $pf = $this->purchase('invoice', 1000.00, 24.0, '321');
        $this->periods->setStatus($this->periodId, $this->supplierId, 'closed');
        try {
            $this->service->create($this->supplierId, $this->payment('purchase_payment', 'out', 24500.00, $register, ['purchase_invoice_id' => $pf]), $this->userId);
            self::fail('Do zavřeného období se úhrada zaúčtovat nesmí.');
        } catch (PostingException $e) {
            self::assertNotSame('', $e->errorCode);
        }
        self::assertSame('booked', $this->purchaseValue($pf, 'status'));
    }

    /** Hotovostní vyrovnání z editoru dokladu zaplatí cizoměnovou fakturu korunami podle kurzu dokladu. */
    public function testEditorCashSettlementPaysForeignPurchaseAtDocumentRate(): void
    {
        $register = $this->register();
        $pf = $this->purchase('invoice', 1000.00, 24.0, '321');
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET payment_method = 'cash', cash_register_id = ? WHERE id = ?")->execute([$register, $pf]);

        $result = $this->settlement->syncPurchase($this->supplierId, $pf, $this->userId);

        self::assertSame(CashSettlementService::CREATED, $result['status']);
        $doc = $this->service->get($this->supplierId, (int) $result['cash_document_id']);
        self::assertSame(24000.00, $doc['total_amount']);
        self::assertSame(1000.00, $doc['settled_document_amount']);
        self::assertSame('paid', $this->purchaseValue($pf, 'status'));
        self::assertSame(CashSettlementService::UNCHANGED, $this->settlement->syncPurchase($this->supplierId, $pf, $this->userId)['status']);
    }

    public function testUnpaidSearchOffersForeignDocumentWithCzkHint(): void
    {
        $pf = $this->purchase('invoice', 1000.00, 24.0, '321');
        $items = $this->service->searchUnpaid($this->supplierId, 'purchase_invoice', 'SYN-FX');
        $item = array_values(array_filter($items, static fn (array $i): bool => $i['id'] === $pf))[0] ?? null;
        self::assertNotNull($item);
        self::assertSame('EUR', $item['currency_code']);
        self::assertSame(1000.00, $item['remaining']);
        self::assertSame(24000.00, $item['remaining_czk']);
        self::assertSame(960.00, $item['czk_tolerance']);
    }

    // ── pomocné ───────────────────────────────────────────────────────────────

    private function register(): int
    {
        return $this->registers->create($this->supplierId, ['name' => 'Pokladna CZK', 'account_code' => '211', 'is_default' => true]);
    }

    /** @return array<string,mixed> */
    private function payment(string $purpose, string $docType, float $amount, int $register, array $over): array
    {
        return array_merge([
            'register_id' => $register,
            'purpose' => $purpose,
            'doc_type' => $docType,
            'issue_date' => self::YEAR . '-07-15',
            'description' => 'Syntetická korunová úhrada',
            'total_amount' => $amount,
            'post' => true,
        ], $over);
    }

    private function vendor(): int
    {
        $this->db->pdo()->prepare(
            "INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id, is_customer, is_vendor)
             VALUES (?, 'Synthetic FX partner', 'Test 1', 'Praha', '11000', ?, 'fx-partner@example.com', ?, 1, 1)"
        )->execute([$this->supplierId, $this->czId, $this->eurId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** Přijatý doklad v EUR se zaúčtovaným předpisem 518 / závazek (s cizoměnovou stopou). */
    private function purchase(string $kind, float $eur, float $rate, string $payable): int
    {
        $pdo = $this->db->pdo();
        $date = self::YEAR . '-06-15';
        $pdo->prepare(
            "INSERT INTO purchase_invoices (supplier_id, varsymbol, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                    due_date, received_at, currency_id, exchange_rate, vendor_snapshot, total_without_vat, total_with_vat, status, created_by)
             VALUES (?, '2099000777', ?, CONCAT('SYN-FX-', UUID()), ?, ?, ?, ?, ?, ?, ?, '{}', ?, ?, 'booked', ?)"
        )->execute([$this->supplierId, $this->vendor(), $kind, $date, $date, $date, $date, $this->eurId, $rate, $eur, $eur, $this->userId]);
        $id = (int) $pdo->lastInsertId();
        $czk = round($eur * $rate, 2);
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $id, [
            ['account_code' => '518', 'side' => 'debit', 'amount' => $czk],
            ['account_code' => $payable, 'side' => 'credit', 'amount' => $czk, 'currency_code' => 'EUR', 'fx_rate' => $rate, 'amount_foreign' => $eur],
        ], ['entry_date' => $date, 'posted' => true]);
        return $id;
    }

    /** Vydaná faktura v EUR se zaúčtovaným předpisem 311 / 602 (s cizoměnovou stopou). */
    private function invoice(float $eur, float $rate): int
    {
        $pdo = $this->db->pdo();
        $date = self::YEAR . '-06-15';
        $pdo->prepare(
            "INSERT INTO invoices (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date, currency_id, exchange_rate,
                    total_without_vat, total_vat, total_with_vat, paid_total, status, vat_classification_code, created_by)
             VALUES (?, '2099000778', 'invoice', ?, ?, ?, ?, ?, ?, ?, 0, ?, 0, 'issued', '1', ?)"
        )->execute([$this->supplierId, $this->vendor(), $date, $date, $date, $this->eurId, $rate, $eur, $eur, $this->userId]);
        $id = (int) $pdo->lastInsertId();
        $czk = round($eur * $rate, 2);
        $this->posting->postDocument($this->supplierId, 'invoice', $id, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => $czk, 'currency_code' => 'EUR', 'fx_rate' => $rate, 'amount_foreign' => $eur],
            ['account_code' => '602', 'side' => 'credit', 'amount' => $czk],
        ], ['entry_date' => $date, 'posted' => true]);
        return $id;
    }

    /** @return array<string, array{sum: array{debit: float, credit: float}, trace: array{0:?string, 1:?float, 2:?float}}> */
    private function lines(int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.account_code, l.side, l.amount, l.currency_code, l.fx_rate, l.amount_foreign
               FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE l.entry_id = ? AND l.supplier_id = ? ORDER BY l.line_no, l.id'
        );
        $stmt->execute([$entryId, $this->supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $code = (string) $row['account_code'];
            $out[$code] ??= ['sum' => ['debit' => 0.0, 'credit' => 0.0], 'trace' => [null, null, null]];
            $out[$code]['sum'][$row['side']] = round($out[$code]['sum'][$row['side']] + (float) $row['amount'], 2);
            if ($row['currency_code'] !== null) {
                $out[$code]['trace'] = [(string) $row['currency_code'], (float) $row['fx_rate'], (float) $row['amount_foreign']];
            }
        }
        return $out;
    }

    private function purchaseValue(int $id, string $column): string
    {
        $stmt = $this->db->pdo()->prepare("SELECT {$column} FROM purchase_invoices WHERE id = ?");
        $stmt->execute([$id]);
        return (string) $stmt->fetchColumn();
    }

    /** @return array<string,string> */
    private function invoiceRow(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT status, paid_total FROM invoices WHERE id = ?');
        $stmt->execute([$id]);
        return (array) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function settledDocumentAmount(int $cashDocumentId): ?float
    {
        $stmt = $this->db->pdo()->prepare('SELECT settled_document_amount FROM cash_documents WHERE id = ?');
        $stmt->execute([$cashDocumentId]);
        $value = $stmt->fetchColumn();
        return $value === null || $value === false ? null : (float) $value;
    }
}
