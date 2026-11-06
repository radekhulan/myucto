<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Seznam přijatých faktur nenačítal příznaky dokladu a `castInvoice()` z chybějící
 * hodnoty udělal `false`. Seznam tak o přenesené daňové povinnosti tvrdil opak
 * než detail téhož dokladu.
 */
#[Group('integration')]
final class PurchaseInvoiceListDetailParityTest extends TestCase
{
    use IsolatedSupplierTrait;

    private ?Connection $db = null;

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    /** @return array<string,array{bool}> */
    public static function flags(): array
    {
        return ['zapnuto' => [true], 'vypnuto' => [false]];
    }

    #[DataProvider('flags')]
    public function testListReportsTheSameDocumentFlagsAsDetail(bool $flag): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $czkId = (int) ($pdo->query(
            "SELECT id FROM currencies WHERE supplier_id = {$sourceSupplierId} AND code = 'CZK' ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $userId === 0 || $czId === 0 || $czkId === 0) {
            self::markTestSkipped('Chybí supplier, uživatel, země nebo CZK.');
        }

        $pdo->beginTransaction();
        $supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "Syntetický dodavatel", "Test 1", "Praha", "11000", ?, "CZ12345678",
                     "vendor@example.invalid", "cs", ?, 0, 1)'
        )->execute([$supplierId, $czId, $czkId]);
        $vendorId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, varsymbol, vendor_invoice_number, document_kind,
                 issue_date, tax_date, due_date, received_at, currency_id, exchange_rate,
                 reverse_charge, prices_include_vat, vendor_snapshot, total_without_vat, total_vat,
                 total_with_vat, status, vat_classification_code, vat_deduction, created_by)
             VALUES (?, ?, "PAR-1", "PAR-1", "invoice", "2026-03-01", "2026-03-01", "2026-03-15", "2026-03-01", ?, 1,
                     ?, ?, "{}", 100, 0, 100, "received", "40", "full", ?)'
        )->execute([$supplierId, $vendorId, $czkId, (int) $flag, (int) $flag, $userId]);
        $invoiceId = (int) $pdo->lastInsertId();

        $repository = $container->get(PurchaseInvoiceRepository::class);
        $detail = $repository->find($invoiceId, $supplierId);
        $listed = $repository->listGroupedByMonth(['supplier_id' => $supplierId])['data'][0]['invoices'][0];

        self::assertNotNull($detail);
        foreach (['reverse_charge', 'prices_include_vat'] as $field) {
            self::assertSame($flag, $detail[$field], "detail: {$field}");
            self::assertSame($flag, $listed[$field], "seznam: {$field}");
        }
    }
}
