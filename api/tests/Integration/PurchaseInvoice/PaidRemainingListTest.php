<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Seznamy dokladů ukazují „Uhrazeno" a „Zbývá uhradit".
 *
 * Přijatá faktura nemá `paid_total`; seznam tak dosud ukazoval jen `amount_to_pay`
 * a účetní neměla jak poznat částečně uhrazený doklad, natož „uhrazený" doklad
 * s nedoplatkem. Obě čísla jsou v měně dokladu a počítá je SSOT PurchaseSettledExpr.
 * Vše v transakci na izolovaném dodavateli → rollback.
 */
#[Group('integration')]
final class PaidRemainingListTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PurchaseInvoiceRepository $purchases;
    private InvoiceRepository $invoices;
    private int $supplierId = 0;
    private int $czkId = 0;
    private int $vendorId = 0;
    private int $userId = 0;
    private int $settlementAccountId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->purchases = $container->get(PurchaseInvoiceRepository::class);
        $this->invoices = $container->get(InvoiceRepository::class);

        $pdo = $this->db->pdo();
        $source = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $countryId = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        self::assertGreaterThan(0, $source);
        self::assertGreaterThan(0, $this->userId);
        self::assertGreaterThan(0, $countryId);

        $pdo->beginTransaction();
        $this->inTx = true;
        try {
            $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
            $pdo->prepare("UPDATE supplier SET company_name = 'Syntetická firma seznamu úhrad', display_name = 'Syntetická firma seznamu úhrad', accounting_mode = 'double_entry' WHERE id = ?")
                ->execute([$this->supplierId]);
            $this->setVatPayerAt($pdo, $this->supplierId, '1900-01-01', true);
            $pdo->prepare("INSERT INTO supplier_accounting_frameworks (supplier_id, effective_from, accounting_framework) VALUES (?, '1900-01-01', 'cz_500')")
                ->execute([$this->supplierId]);
            $pdo->prepare("INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default) VALUES (?, 'CZK', 'CZK', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1)")
                ->execute([$this->supplierId]);
            $this->czkId = (int) $pdo->lastInsertId();
            $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([$this->czkId, $this->supplierId]);
            $pdo->prepare(
                'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email,
                                      language, currency_default_id, is_customer, is_vendor)
                 VALUES (?, "Syntetická protistrana seznamu úhrad", "Testovací 1", "Praha", "11000", ?, "paid-remaining@example.test", "cs", ?, 1, 1)'
            )->execute([$this->supplierId, $countryId, $this->czkId]);
            $this->vendorId = (int) $pdo->lastInsertId();
            $container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
            $account = $pdo->prepare("SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = '395' AND is_active = 1");
            $account->execute([$this->supplierId]);
            $this->settlementAccountId = (int) $account->fetchColumn();
            self::assertGreaterThan(0, $this->settlementAccountId);
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->inTx = false;
            throw $error;
        }
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

    public function testPurchaseListReturnsPaidAndRemainingInDocumentCurrency(): void
    {
        $partial = $this->purchase('PF-2099-901', 1000.00, 'booked');
        $this->settle($partial, 400.00);
        $shortfall = $this->purchase('PF-2099-902', 1000.00, 'paid');
        $this->settle($shortfall, 950.00);
        $markedPaid = $this->purchase('PF-2099-903', 800.00, 'paid');
        $open = $this->purchase('PF-2099-904', 300.00, 'received');
        // Haléřový zbytek do koruny dorovnává banka — ukáže se, ale štítek nedostane.
        $rounding = $this->purchase('PF-2099-905', 1000.40, 'paid');
        $this->settle($rounding, 1000.00);
        // DDKP nic nedluží (peníze odešly na zálohové faktuře).
        $taxDocument = $this->purchase('PF-2099-906', 500.00, 'received', 'tax_document');

        $rows = $this->purchaseRows([]);

        self::assertEqualsWithDelta(400.00, $rows[$partial]['paid_amount'], 0.001);
        self::assertEqualsWithDelta(600.00, $rows[$partial]['remaining_amount'], 0.001);
        self::assertEqualsWithDelta(950.00, $rows[$shortfall]['paid_amount'], 0.001);
        self::assertEqualsWithDelta(50.00, $rows[$shortfall]['remaining_amount'], 0.001, 'Uhrazený doklad s nedoplatkem ukáže zbytek.');
        self::assertEqualsWithDelta(800.00, $rows[$markedPaid]['paid_amount'], 0.001, 'Ručně uhrazený bez evidence = uhrazený celý.');
        self::assertEqualsWithDelta(0.00, $rows[$markedPaid]['remaining_amount'], 0.001);
        self::assertEqualsWithDelta(0.00, $rows[$open]['paid_amount'], 0.001);
        self::assertEqualsWithDelta(300.00, $rows[$open]['remaining_amount'], 0.001);
        self::assertEqualsWithDelta(0.40, $rows[$rounding]['remaining_amount'], 0.001);
        self::assertEqualsWithDelta(0.00, $rows[$taxDocument]['remaining_amount'], 0.001);
        self::assertTrue($rows[$shortfall]['paid_shortfall']);
        self::assertFalse($rows[$rounding]['paid_shortfall'], 'Zbytek do 1 Kč není nedoplatek.');
        self::assertFalse($rows[$partial]['paid_shortfall'], 'Neuhrazený doklad je částečně uhrazený, ne s rozdílem.');

        $onlyShortfall = $this->purchaseRows(['paid_shortfall' => true]);
        self::assertSame([$shortfall], array_keys($onlyShortfall));

        $sorted = $this->purchaseRows(['sort_key' => 'remaining_amount', 'sort_dir' => 'desc', 'group_by_month' => false]);
        self::assertSame([$partial, $open, $shortfall, $rounding], array_slice(array_keys($sorted), 0, 4));
    }

    public function testBankDirectionSignsThePayment(): void
    {
        $partial = $this->purchase('PF-2099-911', 1000.00, 'booked');
        $this->bankMatch($partial, -300.00, 300.00);
        // Vrácený dobropis: příchozí platba k dokladu se zápornou částkou ho vyrovná.
        $creditNote = $this->purchase('PF-2099-912', -500.00, 'paid', 'credit_note');
        $this->bankMatch($creditNote, 500.00, 500.00);
        // Vrácený přeplatek úhradu snižuje (na 321 zase visí dluh).
        $refunded = $this->purchase('PF-2099-913', 1000.00, 'paid');
        $this->bankMatch($refunded, -1000.00, 1000.00);
        $this->bankMatch($refunded, 200.00, 200.00);

        $rows = $this->purchaseRows([]);

        self::assertEqualsWithDelta(300.00, $rows[$partial]['paid_amount'], 0.001);
        self::assertEqualsWithDelta(700.00, $rows[$partial]['remaining_amount'], 0.001);
        self::assertEqualsWithDelta(-500.00, $rows[$creditNote]['paid_amount'], 0.001);
        self::assertEqualsWithDelta(0.00, $rows[$creditNote]['remaining_amount'], 0.001, 'Vrácený dobropis nic nedluží.');
        self::assertFalse($rows[$creditNote]['paid_shortfall']);
        self::assertEqualsWithDelta(800.00, $rows[$refunded]['paid_amount'], 0.001);
        self::assertEqualsWithDelta(200.00, $rows[$refunded]['remaining_amount'], 0.001);
        self::assertTrue($rows[$refunded]['paid_shortfall']);
    }

    /**
     * Doklad se zaokrouhlením (12 467,80 + 0,20): dokud není uhrazený celý, zbývá částka,
     * kterou chce dodavatel zaplatit. Plná úhrada srovnaná na nominál zůstává nulou.
     */
    public function testRemainingIncludesRoundingUntilFullySettled(): void
    {
        $open = $this->purchase('PF-2099-921', 12467.80, 'received');
        $partial = $this->purchase('PF-2099-922', 12467.80, 'received');
        $settled = $this->purchase('PF-2099-923', 12467.80, 'paid');
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET rounding = 0.20 WHERE id IN (?, ?, ?)')
            ->execute([$open, $partial, $settled]);
        $this->settle($partial, 5000.00);
        $this->settle($settled, 12467.80);

        $rows = $this->purchaseRows([]);

        self::assertEqualsWithDelta(12468.00, $rows[$open]['remaining_amount'], 0.001);
        self::assertEqualsWithDelta(7468.00, $rows[$partial]['remaining_amount'], 0.001);
        self::assertEqualsWithDelta(0.00, $rows[$settled]['remaining_amount'], 0.001);
        self::assertFalse($rows[$settled]['paid_shortfall']);
    }

    public function testIssuedListReturnsRemaining(): void
    {
        $partial = $this->invoice('FV-2099-901', 1000.00, 250.00, 'sent');
        $marked = $this->invoice('FV-2099-902', 500.00, 0.00, 'paid');
        $final = $this->invoice('FV-2099-903', 0.00, 0.00, 'paid');

        $result = $this->invoices->listGroupedByMonth(['supplier_id' => $this->supplierId], 1, 50);
        $rows = [];
        foreach ($result['data'] as $group) {
            foreach ($group['invoices'] as $row) {
                $rows[(int) $row['id']] = $row;
            }
        }

        self::assertEqualsWithDelta(750.00, $rows[$partial]['remaining_amount'], 0.001);
        self::assertEqualsWithDelta(0.00, $rows[$marked]['remaining_amount'], 0.001);
        self::assertEqualsWithDelta(0.00, $rows[$final]['remaining_amount'], 0.001);
    }

    /** @return array<int, array<string,mixed>> */
    private function purchaseRows(array $filters): array
    {
        $result = $this->purchases->listGroupedByMonth($filters + ['supplier_id' => $this->supplierId], 1, 50);
        $rows = [];
        foreach ($result['data'] as $group) {
            foreach ($group['invoices'] as $row) {
                $rows[(int) $row['id']] = $row;
            }
        }
        return $rows;
    }

    private function purchase(string $number, float $total, string $status, string $kind = 'invoice'): int
    {
        $issue = '2099-06-10';
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, paid_at, vat_classification_code,
                 vat_deduction, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, "{}", ?, 0, ?, ?, ?, "40", "full", ?)'
        )->execute([
            $this->supplierId, $this->vendorId, $number, $kind, $issue, $issue, $issue, $issue,
            $this->czkId, $total, $total, $status, $status === 'paid' ? '2099-06-20' : null, $this->userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** Úhrada zápočtem proti účtu — jeden z kanálů, které SSOT sčítá. */
    private function settle(int $pfId, float $amount): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO invoice_settlements (supplier_id, doc_type, doc_id, settled_on, amount, account_id, status)
             VALUES (?, 'purchase_invoice', ?, '2099-06-20', ?, ?, 'confirmed')"
        )->execute([$this->supplierId, $pfId, $amount, $this->settlementAccountId]);
    }

    /** Bankovní párování: `$txAmount` se znaménkem pohybu, `$matched` jako ho ukládá párování (kladné). */
    private function bankMatch(int $pfId, float $txAmount, float $matched): void
    {
        $pdo = $this->db->pdo();
        $marker = '__paid_remaining2099__' . bin2hex(random_bytes(4));
        $pdo->prepare(
            "INSERT INTO bank_statements (file_name, file_hash, account_number, bank_code, currency, statement_date)
             VALUES (?, ?, '1000000005', '0100', 'CZK', '2099-06-20')"
        )->execute([$marker . '.gpc', hash('sha256', $marker)]);
        $statementId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO bank_transactions (statement_id, posted_at, amount, currency) VALUES (?, '2099-06-20', ?, 'CZK')"
        )->execute([$statementId, $txAmount]);
        $txId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO payment_matches (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type)
             VALUES (?, ?, ?, ?, 'manual')"
        )->execute([$this->supplierId, $txId, $pfId, $matched]);
    }

    private function invoice(string $varsymbol, float $total, float $paid, string $status): int
    {
        $issue = '2099-06-10';
        $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 paid_total, status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, 0, ?, 0, ?, ?, ?, "1", ?)'
        )->execute([
            $this->supplierId, $varsymbol, $this->vendorId, $issue, $issue, $issue,
            $this->czkId, $total, $total, $paid, $status, $this->userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }
}
