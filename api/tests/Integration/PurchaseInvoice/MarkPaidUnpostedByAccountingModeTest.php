<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Varování „uhrazeno ručně, úhrada NENÍ zaúčtovaná (321 zůstává otevřený)" patří jen do
 * podvojného účetnictví. V daňové evidenci / paušálu žádný deník ani účet 321 neexistuje
 * a ruční „Uhrazeno" je jediný způsob, jak úhradu zaznamenat — banner by tam hlásil
 * závadu, která nemůže nastat (issue #43).
 *
 * Režim se bere k ROKU DOKLADU, ne k dnešku: po přechodu na podvojné účetnictví musí
 * starší doklady zůstat tiché a novější dál hlásit.
 *
 * Izolováno v roce 2097; historie režimu se vrací na původní hodnotu už od 2098-01-01,
 * aby testy pracující s pozdějšími roky nic nepocítily. Vše uklizeno v tearDown.
 * Soft-skip pokud chybí cfg.php (CI runner bez DB).
 */
#[Group('integration')]
final class MarkPaidUnpostedByAccountingModeTest extends TestCase
{
    private const YEAR = 2097;

    private Connection $db;
    private PurchaseInvoiceRepository $repo;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private string $originalMode = 'tax_evidence';

    /** @var int[] */
    private array $vendorIds = [];
    /** @var int[] */
    private array $piIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container  = Bootstrap::buildApp()->getContainer();
            $this->db   = $container->get(Connection::class);
            $this->repo = $container->get(PurchaseInvoiceRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code='CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn() ?: 0);

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }

        $stmt = $pdo->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
        $stmt->execute([$this->supplierId]);
        $this->originalMode = (string) ($stmt->fetchColumn() ?: 'tax_evidence');
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $pdo = $this->db->pdo();
        foreach ($this->piIds as $id) {
            $pdo->prepare('DELETE FROM purchase_invoice_items WHERE purchase_invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM purchase_invoices WHERE id = ?')->execute([$id]);
        }
        foreach ($this->vendorIds as $id) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        }
        $pdo->prepare(
            'DELETE FROM supplier_accounting_modes WHERE supplier_id = ? AND effective_from IN (?, ?)'
        )->execute([$this->supplierId, self::YEAR . '-01-01', (self::YEAR + 1) . '-01-01']);
        $this->db->close();
    }

    public function testDoubleEntryYearWarns(): void
    {
        $this->modeForTestYear('double_entry');
        $id = $this->paidPurchase('Dodavatel PU', 'CZ10970001', 'FAK-PU');

        self::assertTrue(
            $this->repo->find($id, $this->supplierId)['mark_paid_unposted'],
            'V podvojném účetnictví ruční úhrada bez zápisu v deníku hlásí otevřený 321.',
        );
    }

    public function testTaxEvidenceYearStaysSilent(): void
    {
        $this->modeForTestYear('tax_evidence');
        $id = $this->paidPurchase('Dodavatel DE', 'CZ10970002', 'FAK-DE');

        self::assertFalse(
            $this->repo->find($id, $this->supplierId)['mark_paid_unposted'],
            'V daňové evidenci / paušálu žádný účet 321 neexistuje — varování nemá co hlásit.',
        );
    }

    /**
     * Režim platný pro rok testovacích dokladů; od dalšího roku se historie vrací
     * na skutečný režim firmy, ať tenhle test neovlivní doklady jiných testů.
     */
    private function modeForTestYear(string $mode): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO supplier_accounting_modes (supplier_id, effective_from, accounting_mode)
             VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE accounting_mode = VALUES(accounting_mode)'
        );
        $stmt->execute([$this->supplierId, self::YEAR . '-01-01', $mode]);
        $stmt->execute([$this->supplierId, (self::YEAR + 1) . '-01-01', $this->originalMode]);
    }

    private function paidPurchase(string $vendorName, string $dic, string $number): int
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, dic,
                                  main_email, language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, ?, "v@example.com", "cs", ?, 0, 1)'
        );
        $stmt->execute([$this->supplierId, $vendorName, $this->czId, $dic, $this->currencyId]);
        $vendorId = (int) $pdo->lastInsertId();
        $this->vendorIds[] = $vendorId;

        $date = self::YEAR . '-06-10';
        $stmt = $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, paid_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, is_fixed_asset,
                 vat_deduction, vat_deduction_percent, tax_deductible, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, ?, 0, "{}", ?, 0, ?, "paid", 0, "full", 100, 1, ?)'
        );
        $stmt->execute([
            $this->supplierId, $vendorId, $number, $date, $date, $date, $date, $date,
            $this->currencyId, 1000.0, 1000.0, $this->userId,
        ]);
        $id = (int) $pdo->lastInsertId();
        $this->piIds[] = $id;
        return $id;
    }
}
