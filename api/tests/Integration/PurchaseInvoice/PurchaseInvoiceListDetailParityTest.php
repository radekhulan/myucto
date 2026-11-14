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

    public function testPaginatedListComputesDisplayBalancesOnlyForPageRows(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        $countryId = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ'")->fetchColumn();
        $currencyId = (int) $pdo->query("SELECT MIN(id) FROM currencies WHERE code = 'CZK'")->fetchColumn();
        self::assertGreaterThan(0, min($sourceSupplierId, $userId, $countryId, $currencyId));

        $pdo->beginTransaction();
        $supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id,
                 main_email, language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "Syntetický dodavatel", "Test 1", "Praha", "11000", ?,
                 "vendor@example.invalid", "cs", ?, 0, 1)'
        )->execute([$supplierId, $countryId, $currencyId]);
        $vendorId = (int) $pdo->lastInsertId();

        $insert = $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, varsymbol, vendor_invoice_number, document_kind,
                 issue_date, tax_date, due_date, received_at, currency_id, exchange_rate,
                 vendor_snapshot, total_without_vat, total_vat, total_with_vat, status,
                 vat_classification_code, vat_deduction, created_by)
             VALUES (?, ?, ?, ?, "invoice", "2026-03-01", "2026-03-01", "2026-03-15", "2026-03-01", ?, 1,
                     "{}", 100, 0, 100, "received", "40", "full", ?)'
        );
        $ids = [];
        for ($i = 1; $i <= 100; $i++) {
            $number = (string) $i;
            $insert->execute([$supplierId, $vendorId, $number, $number, $currencyId, $userId]);
            $ids[] = (int) $pdo->lastInsertId();
        }

        $reads = static fn (): int => (int) $pdo->query("SHOW SESSION STATUS LIKE 'Handler_read_key'")->fetch()['Value'];
        $before = $reads();
        $result = $container->get(PurchaseInvoiceRepository::class)->listGroupedByMonth(['supplier_id' => $supplierId], 2, 5);
        $usedReads = $reads() - $before;

        self::assertSame(['total' => 100, 'page' => 2, 'per_page' => 5, 'pages' => 20], $result['meta']);
        self::assertSame(array_slice(array_reverse($ids), 5, 5), array_column($result['data'][0]['invoices'], 'id'));
        self::assertSame([100.0, 100.0, 100.0, 100.0, 100.0], array_column($result['data'][0]['invoices'], 'remaining_amount'));
        self::assertLessThan(300, $usedReads, 'Výpočet zůstatků nemá procházet všechny vyhovující doklady.');
    }

}
