<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Report\DphBookBuilder;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Rozpis DPH přijaté faktury s různým nárokem na odpočet (část krácená § 76, část plná)
 * se stejným kódem a sazbou: přiznání vykazuje části na ř. 40k a ř. 40 zvlášť a Kniha DPH
 * je nesmí sloučit do jednoho řádku (dřív padly obě do oddílu první části).
 */
#[Group('integration')]
final class DphBookAllocationDeductionTest extends TestCase
{
    private const YEAR = 2097;
    private const MONTH = 11;

    private Connection $db;
    private DphBookBuilder $book;
    private DphPriznaniBuilder $priznani;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $clientId = 0;
    private int $purchaseId = 0;
    private int $coefficientId = 0;
    private bool $accountAdded = false;
    private const ACCOUNT = '502';
    private ?array $origVatFlags = null;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->book = $container->get(DphBookBuilder::class);
            $this->priznani = $container->get(DphPriznaniBuilder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }
        $this->origVatFlags = $pdo->query(
            "SELECT is_vat_payer, is_identified, dic FROM supplier WHERE id = {$this->supplierId}"
        )->fetch(\PDO::FETCH_ASSOC) ?: [];
        $pdo->prepare("UPDATE supplier SET is_vat_payer = 1, is_identified = 0, dic = 'CZ12345678' WHERE id = ?")
            ->execute([$this->supplierId]);
        // Krácený odpočet vyžaduje zálohový koeficient roku.
        $exists = $pdo->prepare('SELECT id FROM vat_coefficients WHERE supplier_id = ? AND year = ?');
        $exists->execute([$this->supplierId, self::YEAR]);
        if ($exists->fetchColumn() === false) {
            $pdo->prepare('INSERT INTO vat_coefficients (supplier_id, year, provisional_percent) VALUES (?, ?, 100)')
                ->execute([$this->supplierId, self::YEAR]);
            $this->coefficientId = (int) $pdo->lastInsertId();
        }
        // Účet rozpisu DPH musí být v osnově firmy (cizí klíč); čistá testovací DB ho mít nemusí.
        $account = $pdo->prepare('SELECT 1 FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?');
        $account->execute([$this->supplierId, self::ACCOUNT]);
        if ($account->fetchColumn() === false) {
            $pdo->prepare("INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type) VALUES (?, ?, 'Test spotřeba energie', 'expense')")
                ->execute([$this->supplierId, self::ACCOUNT]);
            $this->accountAdded = true;
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($this->origVatFlags !== null && $this->supplierId > 0) {
            $pdo->prepare('UPDATE supplier SET is_vat_payer = ?, is_identified = ?, dic = ? WHERE id = ?')
                ->execute([
                    (int) ($this->origVatFlags['is_vat_payer'] ?? 1),
                    (int) ($this->origVatFlags['is_identified'] ?? 0),
                    $this->origVatFlags['dic'] ?? null,
                    $this->supplierId,
                ]);
        }
        if ($this->purchaseId > 0) {
            $pdo->prepare('DELETE FROM purchase_invoice_vat_allocations WHERE purchase_invoice_id = ?')->execute([$this->purchaseId]);
            $pdo->prepare('DELETE FROM purchase_invoice_items WHERE purchase_invoice_id = ?')->execute([$this->purchaseId]);
            $pdo->prepare('DELETE FROM purchase_invoices WHERE id = ?')->execute([$this->purchaseId]);
        }
        if ($this->clientId > 0) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$this->clientId]);
        }
        if ($this->coefficientId > 0) {
            $pdo->prepare('DELETE FROM vat_coefficients WHERE id = ?')->execute([$this->coefficientId]);
        }
        if ($this->accountAdded) {
            $pdo->prepare('DELETE FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?')->execute([$this->supplierId, self::ACCOUNT]);
        }
        $this->db->close();
    }

    public function testReducedAndFullAllocationPartsStayOnOwnReturnLines(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "Testovací dodavatel energie", "Test 1", "Praha", "11000", ?, "CZ22222220", "test@example.com", "cs", ?, 0, 1)'
        )->execute([$this->supplierId, $this->czId, $this->currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();

        $date = sprintf('%04d-%02d-10', self::YEAR, self::MONTH);
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, exchange_rate, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                 is_fixed_asset, vat_deduction, vat_deduction_percent, created_by)
             VALUES (?, ?, "P-2097-ALLOC", "invoice", ?, ?, ?, ?, ?, NULL, 0, "{}", 1000.00, 210.00, 1210.00,
                     "received", "40", 0, "reduced", 100, ?)'
        )->execute([$this->supplierId, $this->clientId, $date, $date, $date, $date, $this->currencyId, $this->userId]);
        $this->purchaseId = (int) $pdo->lastInsertId();

        $item = $pdo->prepare(
            "INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, vat_classification_code, order_index)
             VALUES (?, ?, 1, 'ks', ?, ?, 21, ?, ?, ?, '40', ?)"
        );
        $item->execute([$this->purchaseId, 'Režie', 400.00, $this->vatRateId, 400.00, 84.00, 484.00, 0]);
        $item->execute([$this->purchaseId, 'Výroba', 600.00, $this->vatRateId, 600.00, 126.00, 726.00, 1]);

        $alloc = $pdo->prepare(
            "INSERT INTO purchase_invoice_vat_allocations
                (supplier_id, purchase_invoice_id, description, usage_type, vat_rate, base_amount, vat_amount,
                 total_amount, vat_deduction, vat_deduction_percent, tax_treatment, account_code,
                 vat_classification_code, order_index)
             VALUES (?, ?, ?, 'business', 21.00, ?, ?, ?, ?, 100, 'deductible', '502', '40', ?)"
        );
        $alloc->execute([$this->supplierId, $this->purchaseId, 'Režie', 400.00, 84.00, 484.00, 'reduced', 0]);
        $alloc->execute([$this->supplierId, $this->purchaseId, 'Výroba', 600.00, 126.00, 726.00, 'full', 1]);

        $returnLines = $this->priznani->build($this->supplierId, self::YEAR, self::MONTH)['summary']['lines'];
        $this->assertEqualsWithDelta(84.00, (float) ($returnLines['40k']['vat'] ?? 0), 0.001, 'Přiznání: krácená část na ř. 40k.');
        $this->assertEqualsWithDelta(126.00, (float) ($returnLines['40']['vat'] ?? 0), 0.001, 'Přiznání: plná část na ř. 40.');

        $book = [];
        foreach ($this->book->build($this->supplierId, self::YEAR, self::MONTH, 'monthly')['sections'] as $s) {
            foreach ($s['rows'] as $row) {
                if ((int) $row['invoice_id'] !== $this->purchaseId || ($row['direction'] ?? '') !== 'received') {
                    continue;
                }
                $line = (string) $s['dphdp3_line'];
                $book[$line] = round(($book[$line] ?? 0.0) + (float) $row['vat'], 2);
            }
        }
        $this->assertEqualsWithDelta(84.00, $book['40k'] ?? 0.0, 0.001, 'Kniha DPH: krácená část v oddílu ř. 40k.');
        $this->assertEqualsWithDelta(126.00, $book['40'] ?? 0.0, 0.001, 'Kniha DPH: plná část v oddílu ř. 40.');
    }
}
