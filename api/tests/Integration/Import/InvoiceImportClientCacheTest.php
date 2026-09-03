<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Import;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Import\InvoiceImportService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Reprodukce nálezu: import naimportuje faktury správně (detail klienta, počítaný živě
 * z `invoices`, ukazuje správný počet), ale seznam klientů (`Firma → Klienti`) zůstává
 * u pomlček/starých čísel, protože `client_revenue_cache`/`project_revenue_cache` se
 * po importu nikdy nepřepočte. Ruční `php api/bin/recompute-stats.php` to spravilo —
 * proto tenhle test ověřuje, že po `importBundle()` je cache aktuální BEZ ručního
 * zásahu, ne že se faktury naimportovaly (to pokrývají jiné testy importu).
 *
 * Data syntetická (fiktivní IČO, rok 2093), izolovaný supplier, uklizeno v tearDown.
 */
#[Group('integration')]
final class InvoiceImportClientCacheTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const TAX_DATE = '2093-04-10';
    private const DUE_DATE = '2093-04-24';
    private const SUPPLIER_IC = '12345678';
    private const CUSTOMER = 'Testovací odběratel cache s.r.o.';
    private const CUSTOMER_IC = '25596641';

    private Connection $db;
    private InvoiceImportService $import;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $czkId = 0;
    private int $clientId = 0;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db     = $c->get(Connection::class);
            $this->import = $c->get(InvoiceImportService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($source === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier / users).');
        }

        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare('UPDATE supplier SET ic = ? WHERE id = ?')->execute([self::SUPPLIER_IC, $this->supplierId]);
        $this->czkId = $this->currency('CZK', 'Kč');
        $this->clientId = $this->client(self::CUSTOMER, self::CUSTOMER_IC);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db) || $this->supplierId === 0) {
            return;
        }
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM invoices WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM clients WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM currencies WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplierId]);
        $this->db->close();
    }

    /**
     * JÁDRO NÁLEZU: po importu dvou faktur téhož klienta ukazuje `client_revenue_cache`
     * hned (bez volání `recompute-stats.php`) invoice_count=2 a součet obratu.
     */
    public function testImportPopulatesClientRevenueCacheWithoutManualRecompute(): void
    {
        self::assertNull($this->cacheRow(), 'Před importem cache pro klienta neexistuje.');

        $first = $this->importOne('faktura-1.xml', $this->pohodaInvoice('2093100001', '9310000001', '1000', '210'));
        self::assertSame('created', $first['status'], (string) ($first['reason'] ?? ''));

        $row = $this->cacheRow();
        self::assertNotNull($row, 'Po prvním importu musí cache existovat BEZ ručního přepočtu.');
        self::assertSame(1, (int) $row['invoice_count']);
        self::assertEqualsWithDelta(1000.0, (float) $row['revenue'], 0.01);

        $second = $this->importOne('faktura-2.xml', $this->pohodaInvoice('2093100002', '9310000002', '500', '105'));
        self::assertSame('created', $second['status'], (string) ($second['reason'] ?? ''));

        $row = $this->cacheRow();
        self::assertNotNull($row);
        self::assertSame(2, (int) $row['invoice_count'], 'Druhý import musí přičíst, ne přepsat na 1.');
        self::assertEqualsWithDelta(1500.0, (float) $row['revenue'], 0.01);
    }

    // ── pomůcky ──────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function importOne(string $name, string $content): array
    {
        $out = $this->import->importBundle(
            [['name' => $name, 'content' => $content]],
            $this->supplierId,
            $this->userId,
            'issued',
        );
        self::assertCount(1, $out['results'], 'Očekává se právě jeden výsledek na jeden doklad.');

        return $out['results'][0];
    }

    /** @return array<string,mixed>|null */
    private function cacheRow(): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM client_revenue_cache WHERE client_id = ? AND currency_id = ?'
        );
        $stmt->execute([$this->clientId, $this->czkId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function currency(string $code, string $symbol): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, ?, ?, ?, ?, ?, 2, 1, 1)'
        )->execute([$this->supplierId, $code, $code, $symbol, $code, $code]);

        return (int) $pdo->lastInsertId();
    }

    private function client(string $name, string $ic): int
    {
        $pdo = $this->db->pdo();
        $countryId = (int) ($pdo->query("SELECT id FROM countries WHERE UPPER(iso2) = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($countryId === 0) {
            self::markTestSkipped('Stát CZ není v číselníku zemí.');
        }

        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, ic, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, ?, "Testovací 1", "Praha", "11000", ?, "odberatel@example.test", "cs", ?, 1, 0)'
        )->execute([$this->supplierId, $name, $ic, $countryId, $this->czkId]);

        return (int) $pdo->lastInsertId();
    }

    /** Běžná tuzemská faktura v Kč se sazbou 21 % — schéma shodné s ImportCreditNoteVarsymbolTest. */
    private function pohodaInvoice(string $number, string $symVar, string $base, string $vat): string
    {
        $supplierIc = self::SUPPLIER_IC;
        $taxDate    = self::TAX_DATE;
        $dueDate    = self::DUE_DATE;
        $customer   = self::CUSTOMER;
        $customerIc = self::CUSTOMER_IC;

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <dat:dataPack xmlns:dat="http://www.stormware.cz/schema/version_2/data.xsd"
                          xmlns:inv="http://www.stormware.cz/schema/version_2/invoice.xsd"
                          xmlns:typ="http://www.stormware.cz/schema/version_2/type.xsd"
                          version="2.0" ico="{$supplierIc}">
              <dat:dataPackItem version="2.0">
                <inv:invoice version="2.0">
                  <inv:invoiceHeader>
                    <inv:invoiceType>issuedInvoice</inv:invoiceType>
                    <inv:number><typ:numberRequested>{$number}</typ:numberRequested></inv:number>
                    <inv:symVar>{$symVar}</inv:symVar>
                    <inv:date>{$taxDate}</inv:date>
                    <inv:dateTax>{$taxDate}</inv:dateTax>
                    <inv:dateDue>{$dueDate}</inv:dateDue>
                    <inv:text>Tuzemské plnění</inv:text>
                    <inv:partnerIdentity>
                      <typ:address>
                        <typ:company>{$customer}</typ:company>
                        <typ:ico>{$customerIc}</typ:ico>
                        <typ:street>Testovací 1</typ:street>
                        <typ:city>Praha</typ:city>
                        <typ:zip>11000</typ:zip>
                        <typ:country><typ:ids>CZ</typ:ids></typ:country>
                      </typ:address>
                    </inv:partnerIdentity>
                  </inv:invoiceHeader>
                  <inv:invoiceDetail>
                    <inv:invoiceItem>
                      <inv:text>Konzultace</inv:text>
                      <inv:quantity>1</inv:quantity>
                      <inv:unit>ks</inv:unit>
                      <inv:rateVAT>high</inv:rateVAT>
                      <inv:homeCurrency><typ:unitPrice>{$base}</typ:unitPrice></inv:homeCurrency>
                    </inv:invoiceItem>
                  </inv:invoiceDetail>
                  <inv:invoiceSummary>
                    <inv:homeCurrency>
                      <typ:priceHigh>{$base}</typ:priceHigh>
                      <typ:priceHighVAT rate="21">{$vat}</typ:priceHighVAT>
                    </inv:homeCurrency>
                  </inv:invoiceSummary>
                </inv:invoice>
              </dat:dataPackItem>
            </dat:dataPack>
            XML;
    }
}
