<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Import;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Import\InvoiceImportService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Doklad z ISDOC založený rovnou jako přijatý se musí zaúčtovat, má-li firma zapnuté
 * automatické zaúčtování přijatých dokladů. Import přeskakuje přechod draft → received,
 * ve kterém automatika běží, takže doklad zůstával přijatý a nezaúčtovaný.
 *
 * Vše v jedné transakci, tearDown rollbackne. Data syntetická, rok 2093.
 */
#[Group('integration')]
final class PurchaseImportAutoPostTest extends TestCase
{
    private const YEAR = 2093;
    private const TENANT_IC = '12345678';

    private Connection $db;
    private InvoiceImportService $import;
    private JournalEntryRepository $journal;

    private int $supplierId = 0;
    private int $userId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db      = $c->get(Connection::class);
            $this->import  = $c->get(InvoiceImportService::class);
            $this->journal = $c->get(JournalEntryRepository::class);
            $seeder        = $c->get(ChartOfAccountsSeeder::class);
            $periods       = $c->get(AccountingPeriodRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier / users).');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $pdo->prepare("UPDATE supplier SET ic = ?, is_vat_payer = 1, accounting_mode = 'double_entry' WHERE id = ?")
            ->execute([self::TENANT_IC, $this->supplierId]);
        $seeder->seedForSupplier($this->supplierId);
        $periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
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

    public function testReceivedImportIsPostedWhenAutomationIsOn(): void
    {
        $this->setAutomation('auto');

        $id = $this->importOne('AP-2093-001', 'received');

        self::assertNotNull(
            $this->journal->findBySource($this->supplierId, 'purchase_invoice', $id),
            'Přijatý doklad z importu se má zaúčtovat stejně jako při ručním přijetí.',
        );
    }

    public function testReceivedImportStaysUnpostedWhenAutomationIsOff(): void
    {
        $this->setAutomation('off');

        $id = $this->importOne('AP-2093-002', 'received');

        self::assertNull($this->journal->findBySource($this->supplierId, 'purchase_invoice', $id));
    }

    public function testDraftImportIsNotPosted(): void
    {
        $this->setAutomation('auto');

        $id = $this->importOne('AP-2093-003', 'draft');

        self::assertNull($this->journal->findBySource($this->supplierId, 'purchase_invoice', $id));
    }

    /** Opakovaný import téhož souboru nesmí doklad zaúčtovat podruhé. */
    public function testDuplicateImportDoesNotPostAgain(): void
    {
        $this->setAutomation('auto');

        $id = $this->importOne('AP-2093-004', 'received');
        $again = $this->importOne('AP-2093-004', 'received');

        self::assertSame($id, $again);
        $count = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ? AND source_type = 'purchase_invoice' AND source_id = ?"
        );
        $count->execute([$this->supplierId, $id]);
        self::assertSame(1, (int) $count->fetchColumn());
    }

    private function setAutomation(string $level): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET auto_post_purchases = ? WHERE id = ?')
            ->execute([$level === 'auto' ? 1 : 0, $this->supplierId]);
        $this->db->pdo()->prepare(
            "INSERT INTO auto_posting_policy (supplier_id, operation_type, level, updated_by)
             VALUES (?, 'document.purchase', ?, ?)
             ON DUPLICATE KEY UPDATE level = VALUES(level), updated_by = VALUES(updated_by)"
        )->execute([$this->supplierId, $level, $this->userId]);
    }

    private function importOne(string $number, string $purchaseStatus): int
    {
        $out = $this->import->importBundle(
            [['name' => $number . '.isdoc', 'content' => $this->isdoc($number)]],
            $this->supplierId,
            $this->userId,
            'purchase',
            null,
            null,
            $purchaseStatus,
        );
        self::assertCount(1, $out['results']);
        self::assertSame('created', $out['results'][0]['status'], (string) ($out['results'][0]['reason'] ?? ''));

        return (int) $out['results'][0]['purchase_invoice_id'];
    }

    private function isdoc(string $id): string
    {
        $tenantIc = self::TENANT_IC;
        $date = self::YEAR . '-05-14';

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <Invoice xmlns="http://isdoc.cz/namespace/2013">
              <DocumentType>1</DocumentType>
              <ID>{$id}</ID>
              <IssueDate>{$date}</IssueDate>
              <TaxPointDate>{$date}</TaxPointDate>
              <LocalCurrencyCode>CZK</LocalCurrencyCode>
              <AccountingSupplierParty><Party>
                <PartyIdentification><ID>25596641</ID></PartyIdentification>
                <PartyName><Name>Testovací dodavatel s.r.o.</Name></PartyName>
                <PostalAddress><StreetName>Dodavatelská</StreetName><BuildingNumber>5</BuildingNumber><CityName>Brno</CityName><PostalZone>60200</PostalZone><Country><IdentificationCode>CZ</IdentificationCode><Name>Česko</Name></Country></PostalAddress>
                <PartyTaxScheme><CompanyID>CZ25596641</CompanyID><TaxScheme>VAT</TaxScheme></PartyTaxScheme>
              </Party></AccountingSupplierParty>
              <AccountingCustomerParty><Party>
                <PartyIdentification><ID>{$tenantIc}</ID></PartyIdentification>
                <PartyName><Name>Tenant a.s.</Name></PartyName>
                <PostalAddress><StreetName>Testovací</StreetName><BuildingNumber>1</BuildingNumber><CityName>Praha</CityName><PostalZone>11000</PostalZone><Country><IdentificationCode>CZ</IdentificationCode><Name>Česko</Name></Country></PostalAddress>
              </Party></AccountingCustomerParty>
              <InvoiceLines>
                <InvoiceLine>
                  <ID>1</ID>
                  <InvoicedQuantity unitCode="ks">1</InvoicedQuantity>
                  <LineExtensionAmount>1000.00</LineExtensionAmount>
                  <LineExtensionAmountTaxInclusive>1210.00</LineExtensionAmountTaxInclusive>
                  <LineExtensionTaxAmount>210.00</LineExtensionTaxAmount>
                  <UnitPrice>1000.00</UnitPrice>
                  <UnitPriceTaxInclusive>1210.00</UnitPriceTaxInclusive>
                  <ClassifiedTaxCategory><Percent>21</Percent><VATCalculationMethod>0</VATCalculationMethod></ClassifiedTaxCategory>
                  <Item><Description>Papír A4</Description></Item>
                </InvoiceLine>
              </InvoiceLines>
              <TaxTotal>
                <TaxSubTotal>
                  <TaxableAmount>1000.00</TaxableAmount>
                  <TaxAmount>210.00</TaxAmount>
                  <TaxInclusiveAmount>1210.00</TaxInclusiveAmount>
                  <AlreadyClaimedTaxableAmount>0</AlreadyClaimedTaxableAmount>
                  <AlreadyClaimedTaxAmount>0</AlreadyClaimedTaxAmount>
                  <AlreadyClaimedTaxInclusiveAmount>0</AlreadyClaimedTaxInclusiveAmount>
                  <DifferenceTaxableAmount>1000.00</DifferenceTaxableAmount>
                  <DifferenceTaxAmount>210.00</DifferenceTaxAmount>
                  <DifferenceTaxInclusiveAmount>1210.00</DifferenceTaxInclusiveAmount>
                  <TaxCategory><Percent>21</Percent></TaxCategory>
                </TaxSubTotal>
                <TaxAmount>210.00</TaxAmount>
              </TaxTotal>
              <LegalMonetaryTotal>
                <TaxExclusiveAmount>1000.00</TaxExclusiveAmount>
                <TaxInclusiveAmount>1210.00</TaxInclusiveAmount>
                <AlreadyClaimedTaxExclusiveAmount>0</AlreadyClaimedTaxExclusiveAmount>
                <AlreadyClaimedTaxInclusiveAmount>0</AlreadyClaimedTaxInclusiveAmount>
                <DifferenceTaxExclusiveAmount>1000.00</DifferenceTaxExclusiveAmount>
                <DifferenceTaxInclusiveAmount>1210.00</DifferenceTaxInclusiveAmount>
                <PayableRoundingAmount>0</PayableRoundingAmount>
                <PaidDepositsAmount>0</PaidDepositsAmount>
                <PayableAmount>1210.00</PayableAmount>
              </LegalMonetaryTotal>
            </Invoice>
            XML;
    }
}
