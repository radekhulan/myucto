<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Import;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Import\InvoiceImportService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Přijatá faktura z mPOHODA (1. pololetí 2026): ISDOC nese `VATApplicable=false` na dokladu
 * i řádcích, přitom uvádí sazbu 21 % a vyplněnou DPH. Import ji bral jako nedaňovou,
 * uložil 0 % DPH a k úhradě jen základ. Platí částky a uživatel dostane varování.
 *
 * Data syntetická (fiktivní IČO, rok 2093), izolovaný supplier, uklizeno v tearDown.
 */
#[Group('integration')]
final class PurchaseIsdocNonTaxFlagContradictionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const TENANT_IC = '12345678';
    private const VENDOR_IC = '25596641';

    private Connection $db;
    private InvoiceImportService $import;

    private int $supplierId = 0;
    private int $userId = 0;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
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
        $pdo->prepare('UPDATE supplier SET ic = ? WHERE id = ?')->execute([self::TENANT_IC, $this->supplierId]);
        $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, "CZK", "CZK", "Kč", "CZK", "CZK", 2, 1, 1)'
        )->execute([$this->supplierId]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db) || $this->supplierId === 0) {
            return;
        }
        $pdo = $this->db->pdo();
        foreach ([
            'DELETE FROM purchase_invoice_vat_overrides WHERE purchase_invoice_id IN (SELECT id FROM purchase_invoices WHERE supplier_id = ?)',
            'DELETE FROM purchase_invoice_items WHERE purchase_invoice_id IN (SELECT id FROM purchase_invoices WHERE supplier_id = ?)',
            'DELETE FROM purchase_invoices WHERE supplier_id = ?',
            'DELETE FROM purchase_invoice_counters WHERE supplier_id = ?',
            'DELETE FROM clients WHERE supplier_id = ?',
            'DELETE FROM currencies WHERE supplier_id = ?',
            'DELETE FROM supplier WHERE id = ?',
        ] as $sql) {
            try {
                $pdo->prepare($sql)->execute([$this->supplierId]);
            } catch (\PDOException) {
                // tabulka v tomhle schématu není
            }
        }
        $this->db->close();
    }

    public function testMpohodaNonTaxFlagWithDeclaredVatImportsWithVat(): void
    {
        $out = $this->import->importBundle(
            [['name' => 'mpohoda.isdoc', 'content' => $this->mpohodaIsdoc()]],
            $this->supplierId,
            $this->userId,
            'purchase',
            null,
            null,
            'draft',
        );
        self::assertCount(1, $out['results']);
        $res = $out['results'][0];
        self::assertSame('created', $res['status'], (string) ($res['reason'] ?? ''));

        $stmt = $this->db->pdo()->prepare(
            'SELECT total_without_vat, total_vat, total_with_vat, amount_to_pay, extraction_warning
               FROM purchase_invoices WHERE id = ?'
        );
        $stmt->execute([(int) $res['purchase_invoice_id']]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        self::assertEqualsWithDelta(1515.00, (float) $row['total_without_vat'], 0.001);
        self::assertEqualsWithDelta(318.15, (float) $row['total_vat'], 0.001);
        self::assertEqualsWithDelta(1833.15, (float) $row['total_with_vat'], 0.001);
        self::assertEqualsWithDelta(1833.15, (float) $row['amount_to_pay'], 0.001);
        self::assertStringContainsString('nedaňový', (string) $row['extraction_warning']);
    }

    private function mpohodaIsdoc(): string
    {
        $tenantIc = self::TENANT_IC;
        $vendorIc = self::VENDOR_IC;
        $line = static fn (string $id, string $base, string $tax, string $incl, string $desc): string => <<<XML
                <InvoiceLine>
                  <ID>{$id}</ID>
                  <InvoicedQuantity unitCode="měs">1.0000000000</InvoicedQuantity>
                  <LineExtensionAmount>{$base}</LineExtensionAmount>
                  <LineExtensionAmountTaxInclusive>{$incl}</LineExtensionAmountTaxInclusive>
                  <LineExtensionTaxAmount>{$tax}</LineExtensionTaxAmount>
                  <UnitPrice>{$base}</UnitPrice>
                  <UnitPriceTaxInclusive>{$incl}</UnitPriceTaxInclusive>
                  <ClassifiedTaxCategory>
                    <Percent>21</Percent>
                    <VATCalculationMethod>0</VATCalculationMethod>
                    <VATApplicable>false</VATApplicable>
                  </ClassifiedTaxCategory>
                  <Item><Description>{$desc}</Description></Item>
                </InvoiceLine>
            XML;
        $lines = $line('1', '1000.0000', '210.0000', '1210.0000', 'provoz serveru')
            . $line('2', '400.0000', '84.0000', '484.0000', 'přístup navíc')
            . $line('3', '115.0000', '24.1500', '139.1500', 'navýšení disku');

        return <<<XML
            <?xml version="1.0" encoding="utf-8"?>
            <Invoice xmlns="http://isdoc.cz/namespace/2013" version="6.0.2">
              <DocumentType>1</DocumentType>
              <ID>93800235</ID>
              <UUID>577411fe-724b-45c6-a805-79278d89c4f8</UUID>
              <IssuingSystem>Aplikace mPOHODA</IssuingSystem>
              <IssueDate>2093-01-01</IssueDate>
              <TaxPointDate>2093-01-01</TaxPointDate>
              <VATApplicable>false</VATApplicable>
              <LocalCurrencyCode>CZK</LocalCurrencyCode>
              <CurrRate>1</CurrRate>
              <RefCurrRate>1</RefCurrRate>
              <AccountingSupplierParty><Party>
                <PartyIdentification><ID>{$vendorIc}</ID></PartyIdentification>
                <PartyName><Name>Testovací dodavatel s.r.o.</Name></PartyName>
                <PartyTaxScheme><CompanyID>CZ{$vendorIc}</CompanyID><TaxScheme>VAT</TaxScheme></PartyTaxScheme>
              </Party></AccountingSupplierParty>
              <AccountingCustomerParty><Party>
                <PartyIdentification><ID>{$tenantIc}</ID></PartyIdentification>
                <PartyName><Name>Test odběratel s.r.o.</Name></PartyName>
                <PartyTaxScheme><CompanyID>CZ{$tenantIc}</CompanyID><TaxScheme>VAT</TaxScheme></PartyTaxScheme>
              </Party></AccountingCustomerParty>
              <InvoiceLines>{$lines}</InvoiceLines>
              <TaxTotal>
                <TaxSubTotal>
                  <TaxableAmount>1515.0000</TaxableAmount>
                  <TaxAmount>318.1500</TaxAmount>
                  <TaxInclusiveAmount>1833.1500</TaxInclusiveAmount>
                  <TaxCategory><Percent>21</Percent><VATApplicable>false</VATApplicable></TaxCategory>
                </TaxSubTotal>
                <TaxAmount>318.1500</TaxAmount>
              </TaxTotal>
              <LegalMonetaryTotal>
                <TaxExclusiveAmount>1515.0000</TaxExclusiveAmount>
                <TaxInclusiveAmount>1833.1500</TaxInclusiveAmount>
                <PayableRoundingAmount>0.0000</PayableRoundingAmount>
                <PaidDepositsAmount>0</PaidDepositsAmount>
                <PayableAmount>1833.1500</PayableAmount>
              </LegalMonetaryTotal>
              <PaymentMeans><Payment>
                <PaidAmount>1833.1500</PaidAmount>
                <PaymentMeansCode>42</PaymentMeansCode>
                <Details><PaymentDueDate>2093-01-11</PaymentDueDate><VariableSymbol>93800235</VariableSymbol></Details>
              </Payment></PaymentMeans>
            </Invoice>
            XML;
    }
}
