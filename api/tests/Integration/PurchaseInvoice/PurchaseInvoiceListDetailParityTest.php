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

    /**
     * Stránka seznamu čte jen doklady firmy a řádky stránky. Počet čtení klíčů se měří
     * před a po vložení cizí firmy se stovkami dokladů, úhrad a odpočtů záloh: kdyby
     * některý dotaz šel po indexu bez firmy (sonda odpočtů zálohy dřív procházela
     * odpočtové řádky všech firem), čtení s cizími daty vzroste.
     */
    public function testPaginatedListComputesDisplayBalancesOnlyForPageRows(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        $countryId = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ'")->fetchColumn();
        $currencyId = (int) $pdo->query("SELECT MIN(id) FROM currencies WHERE code = 'CZK'")->fetchColumn();
        $vatRateId = (int) $pdo->query('SELECT MIN(id) FROM vat_rates')->fetchColumn();
        self::assertGreaterThan(0, min($sourceSupplierId, $userId, $countryId, $currencyId, $vatRateId));

        $pdo->beginTransaction();
        [$supplierId, $vendorId] = $this->supplierWithVendor($pdo, $sourceSupplierId, $countryId, $currencyId);
        $ids = [];
        for ($i = 1; $i <= 100; $i++) {
            $ids[] = $this->insertPurchaseInvoice($pdo, $supplierId, $vendorId, (string) $i, 'invoice', $currencyId, $userId);
        }

        $repository = $container->get(PurchaseInvoiceRepository::class);
        $filters = ['supplier_id' => $supplierId];
        // Zahřátí: první volání v procesu načítá číselníky a nastavení jednou pro všechny
        // a jejich počet čtení závisí na tom, co v DB nechaly předchozí testy. Měří se
        // až druhé volání, tedy jen náklad, který roste s počtem dokladů.
        $repository->listGroupedByMonth($filters, 1, 5);
        [$keyReads, $indexReads] = $this->measureReads($pdo, fn () => $repository->listGroupedByMonth($filters, 2, 5));

        $this->insertForeignSupplierNoise($pdo, $sourceSupplierId, $countryId, $currencyId, $userId, $vatRateId);
        $result = null;
        [$keyReadsWithNoise, $indexReadsWithNoise] = $this->measureReads(
            $pdo,
            function () use ($repository, $filters, &$result): void {
                $result = $repository->listGroupedByMonth($filters, 2, 5);
            },
        );

        self::assertSame(['total' => 100, 'page' => 2, 'per_page' => 5, 'pages' => 20], $result['meta']);
        self::assertSame(array_slice(array_reverse($ids), 5, 5), array_column($result['data'][0]['invoices'], 'id'));
        self::assertSame([100.0, 100.0, 100.0, 100.0, 100.0], array_column($result['data'][0]['invoices'], 'remaining_amount'));
        self::assertSame(100, $result['data'][0]['count']);
        self::assertSame(10000.0, $result['data'][0]['totals_per_currency'][0]['with_vat']);
        self::assertLessThan(300, max($keyReads, $keyReadsWithNoise), 'Výpočet zůstatků nemá procházet všechny vyhovující doklady.');
        self::assertLessThanOrEqual(
            5,
            abs($indexReadsWithNoise - $indexReads),
            "Stránka nesmí číst doklady, úhrady ani odpočty jiné firmy (čtení indexů {$indexReads} bez cizích dat, "
            . "{$indexReadsWithNoise} s nimi; klíče {$keyReads} / {$keyReadsWithNoise}).",
        );
    }

    /** @return array{0:int, 1:int} id firmy a jejího dodavatele */
    private function supplierWithVendor(\PDO $pdo, int $sourceSupplierId, int $countryId, int $currencyId): array
    {
        $supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id,
                 main_email, language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "Syntetický dodavatel", "Test 1", "Praha", "11000", ?,
                 "vendor@example.invalid", "cs", ?, 0, 1)'
        )->execute([$supplierId, $countryId, $currencyId]);

        return [$supplierId, (int) $pdo->lastInsertId()];
    }

    private function insertPurchaseInvoice(
        \PDO $pdo,
        int $supplierId,
        int $vendorId,
        string $number,
        string $kind,
        int $currencyId,
        int $userId,
        ?int $advanceId = null,
    ): int {
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, varsymbol, vendor_invoice_number, document_kind,
                 issue_date, tax_date, due_date, received_at, currency_id, exchange_rate,
                 vendor_snapshot, total_without_vat, total_vat, total_with_vat, status,
                 vat_classification_code, vat_deduction, created_by, advance_purchase_invoice_id)
             VALUES (?, ?, ?, ?, ?, "2026-03-01", "2026-03-01", "2026-03-15", "2026-03-01", ?, 1,
                     "{}", 100, 0, 100, "received", "40", "full", ?, ?)'
        )->execute([$supplierId, $vendorId, $number, $number, $kind, $currencyId, $userId, $advanceId]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Cizí firma se zálohami a konečnými fakturami ve stejném měsíci: každá konečná
     * faktura nese odpočtový řádek zálohy a úhradu bankou, pokladnou, zápočtem,
     * zápočtem proti účtu i zápočtem dobropisu.
     */
    private function insertForeignSupplierNoise(
        \PDO $pdo,
        int $sourceSupplierId,
        int $countryId,
        int $currencyId,
        int $userId,
        int $vatRateId,
    ): void {
        [$supplierId, $vendorId] = $this->supplierWithVendor($pdo, $sourceSupplierId, $countryId, $currencyId);
        // Účet vyrovnání vlastní cizí firmy: čistá testovací DB z migrací osnovu nemá.
        $pdo->prepare("INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type) VALUES (?, '221', 'Bankovní účty', 'asset')")
            ->execute([$supplierId]);
        $accountId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO bank_statements (supplier_id, file_name, file_hash, account_number, statement_date)
             VALUES (?, 'cizi.gpc', ?, '1000000005/0100', '2026-03-31')"
        )->execute([$supplierId, str_repeat('c', 64)]);
        $statementId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO cash_registers (supplier_id, name) VALUES (?, 'Cizí pokladna')")->execute([$supplierId]);
        $registerId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO offset_agreements (supplier_id, partner_id, agreement_date, document_no, status)
             VALUES (?, ?, '2026-03-20', 'ZAP-1', 'confirmed')"
        )->execute([$supplierId, $vendorId]);
        $agreementId = (int) $pdo->lastInsertId();

        $item = $pdo->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit_price_without_vat, vat_rate_id, vat_rate_snapshot,
                 total_without_vat, total_vat, total_with_vat, advance_purchase_invoice_id, order_index)
             VALUES (?, "Položka", 1, ?, ?, 0, ?, 0, ?, ?, ?)'
        );
        $transaction = $pdo->prepare(
            "INSERT INTO bank_transactions (statement_id, posted_at, amount, currency) VALUES (?, '2026-03-20', -40, 'CZK')"
        );
        $match = $pdo->prepare('INSERT INTO payment_matches (supplier_id, bank_transaction_id, purchase_invoice_id, amount) VALUES (?, ?, ?, 40)');
        $cash = $pdo->prepare(
            "INSERT INTO cash_documents (supplier_id, register_id, doc_type, purpose, issue_date, description,
                 total_amount, currency_code, purchase_invoice_id, status)
             VALUES (?, ?, 'out', 'purchase_payment', '2026-03-20', 'Úhrada', 10, 'CZK', ?, 'posted')"
        );
        $creditNote = $pdo->prepare(
            "INSERT INTO credit_note_offsets (supplier_id, doc_type, invoice_id, credit_note_id, amount, offset_on)
             VALUES (?, 'purchase_invoice', ?, ?, 5, '2026-03-20')"
        );
        $settlement = $pdo->prepare(
            "INSERT INTO invoice_settlements (supplier_id, doc_type, doc_id, settled_on, amount, account_id, status)
             VALUES (?, 'purchase_invoice', ?, '2026-03-20', 5, ?, 'confirmed')"
        );
        $offset = $pdo->prepare(
            "INSERT INTO offset_agreement_items (agreement_id, supplier_id, doc_type, doc_id, amount)
             VALUES (?, ?, 'purchase_invoice', ?, 5)"
        );
        for ($i = 1; $i <= 200; $i++) {
            $advanceId = $this->insertPurchaseInvoice($pdo, $supplierId, $vendorId, "Z{$i}", 'advance', $currencyId, $userId);
            $finalId = $this->insertPurchaseInvoice($pdo, $supplierId, $vendorId, "K{$i}", 'invoice', $currencyId, $userId, $advanceId);
            $item->execute([$finalId, 110, $vatRateId, 110, 110, null, 0]);
            $item->execute([$finalId, -10, $vatRateId, -10, -10, $advanceId, 1]);
            $transaction->execute([$statementId]);
            $match->execute([$supplierId, (int) $pdo->lastInsertId(), $finalId]);
            $cash->execute([$supplierId, $registerId, $finalId]);
            $creditNote->execute([$supplierId, $finalId, $advanceId]);
            $settlement->execute([$supplierId, $finalId, $accountId]);
            $offset->execute([$agreementId, $supplierId, $finalId]);
        }
    }

    /**
     * Čtení klíčů (`Handler_read_key`) a všech čtení indexů včetně procházení rozsahu
     * (`Handler_read_key` + `Handler_read_next`) během volání.
     *
     * @return array{0:int, 1:int}
     */
    private function measureReads(\PDO $pdo, callable $call): array
    {
        $status = static function () use ($pdo): array {
            $rows = $pdo->query(
                "SHOW SESSION STATUS WHERE Variable_name IN ('Handler_read_key', 'Handler_read_next')"
            )->fetchAll(\PDO::FETCH_KEY_PAIR);

            return [(int) $rows['Handler_read_key'], (int) $rows['Handler_read_next']];
        };
        [$keyBefore, $nextBefore] = $status();
        $call();
        [$keyAfter, $nextAfter] = $status();

        return [$keyAfter - $keyBefore, $keyAfter - $keyBefore + $nextAfter - $nextBefore];
    }
}
