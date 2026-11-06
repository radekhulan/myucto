<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use Mpdf\Mpdf;
use MyInvoice\Action\PurchaseInvoice\DeletePurchaseInvoiceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Repository\PurchaseInvoiceInboxDismissalRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Import\AiPdfExtractor;
use MyInvoice\Service\Import\InboxPairVerifier;
use MyInvoice\Service\Import\InvoiceExtractionRouter;
use MyInvoice\Service\Import\IsdocToPurchaseInvoiceMapper;
use MyInvoice\Service\Import\PurchaseInvoiceInboxScanner;
use MyInvoice\Service\Import\PurchaseInvoicePdfArchiver;
use MyInvoice\Service\Invoice\PurchaseInvoiceCalculator;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Inbox scanner páruje PDF a ISDOC, když dorazí jako dva samostatné soubory (issue #16).
 *
 * Bez párování šel každý soubor zvlášť: z ISDOC vznikl přesný draft BEZ čitelné podoby
 * a PDF vedle něj šlo na placenou AI extrakci, kde se buď utopilo v unikátním klíči,
 * nebo (při odchylce ve vyčtených údajích) vyrobilo druhý, nepřesný koncept.
 */
final class PurchaseInboxPdfIsdocPairingTest extends TestCase
{
    private Connection $db;
    private \Psr\Container\ContainerInterface $container;
    private PurchaseInvoiceInboxScanner $scanner;
    private string $inboxDir = '';
    private int $supplierId = 0;
    private int $userId = 0;
    private string $supplierIc = '';
    private ?int $vendorId = null;
    /**
     * Prefix čísel dokladů pro TENHLE běh — číselný, ať se chová jako opravdový
     * variabilní symbol (verifier ho hledá v textu PDF po číslicích). Unikátní klíč
     * `uq_pi_supplier_varsymbol` je napříč běhy sdílený, takže pevná čísla by při
     * jakémkoliv nedoklizeném doběhnutí testu shodila všechny další běhy.
     */
    private string $vsPrefix = '';

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }

        $this->vsPrefix = '99' . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->inboxDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'myucto-inbox-' . bin2hex(random_bytes(6));
        if (!@mkdir($this->inboxDir, 0777, true) && !is_dir($this->inboxDir)) {
            $this->markTestSkipped('Nelze vytvořit dočasný inbox adresář.');
        }

        try {
            $c = Bootstrap::buildContainer();
            $this->container = $c;
            $this->db = $c->get(Connection::class);
            // Config je immutable → scanner sestavíme ručně s inbox_dir mířícím do tempu.
            $config = new Config(
                array_replace_recursive($c->get(Config::class)->all(), [
                    'purchase_invoice' => ['inbox_dir' => $this->inboxDir, 'inbox_recursive' => true],
                ]),
                $c->get(Config::class)->dataDir(),
            );
            $this->scanner = new PurchaseInvoiceInboxScanner(
                $config,
                $this->db,
                $c->get(PurchaseInvoiceRepository::class),
                $c->get(ClientRepository::class),
                $c->get(PurchaseInvoiceCalculator::class),
                $c->get(InvoiceExtractionRouter::class),
                $c->get(IsdocToPurchaseInvoiceMapper::class),
                $c->get(AiPdfExtractor::class),
                $c->get(PurchaseInvoicePdfArchiver::class),
                $c->get(InboxPairVerifier::class),
                $c->get(PurchaseInvoiceInboxDismissalRepository::class),
            );
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $row = $pdo->query('SELECT id, ic FROM supplier ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
        $this->supplierId = (int) ($row['id'] ?? 0);
        $this->supplierIc = preg_replace('/\D/', '', (string) ($row['ic'] ?? '')) ?? '';
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $currencyId       = (int) ($pdo->query("SELECT id FROM currencies WHERE code='CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->supplierIc === '' || $this->userId === 0 || $currencyId === 0) {
            $this->markTestSkipped('Chybí supplier s IČO / user / měna v DB.');
        }

        $countryId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn() ?: 0);
        // Pre-create vendor, ať resolveVendor reusne a nevolá ARES.
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, ic, main_email,
                                  language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "Inbox Pair Vendor", "Test 1", "Praha", "11000", ?, "12345678", "v@example.com",
                     "cs", ?, 0, 1)'
        )->execute([$this->supplierId, $countryId, $currencyId]);
        $this->vendorId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $pdo = $this->db->pdo();
            // Podle prefixu, ne podle nasbíraných id — po neúspěšné aserci se k odchytu
            // id nemusí dojít a zbylý řádek by shodil další běh na unikátním klíči.
            $stmt = $pdo->prepare('SELECT id FROM purchase_invoices WHERE supplier_id = ? AND vendor_invoice_number LIKE ?');
            $stmt->execute([$this->supplierId, $this->vsPrefix . '%']);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $pdo->prepare('DELETE FROM purchase_invoice_items WHERE purchase_invoice_id = ?')->execute([(int) $id]);
                $pdo->prepare('DELETE FROM purchase_invoices WHERE id = ?')->execute([(int) $id]);
            }
            $pdo->prepare('DELETE FROM purchase_invoice_inbox_dismissed WHERE supplier_id = ? AND vendor_invoice_number LIKE ?')
                ->execute([$this->supplierId, $this->vsPrefix . '%']);
            if ($this->vendorId !== null) {
                $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$this->vendorId]);
            }
            $this->db->close();
        }
        if ($this->inboxDir !== '' && is_dir($this->inboxDir)) {
            foreach ((array) glob($this->inboxDir . DIRECTORY_SEPARATOR . '*') as $f) {
                @unlink((string) $f);
            }
            @rmdir($this->inboxDir);
        }
    }

    /**
     * Jádro issue: `faktura.isdoc` + `faktura.pdf` = jeden doklad s daty z ISDOC
     * a s připojeným PDF. Jeden záznam v reportu, žádný druhý koncept.
     */
    public function testIsdocAndPdfSiblingsProduceSingleInvoiceWithArchivedPdf(): void
    {
        $vs    = $this->vsPrefix . '1';
        $isdoc = $this->minimalIsdoc($vs, '1210.00');
        $pdf   = $this->pdfBytes("Faktura {$vs}<br>Variabilní symbol: {$vs}<br>Celkem k úhradě 1 210,00 Kč");
        $this->write('faktura-pair.isdoc', $isdoc);
        $this->write('faktura-pair.pdf', $pdf);

        $result = $this->scanner->scan($this->supplierId, $this->userId);

        self::assertSame(1, $result['created'], 'z dvojice vznikne právě jeden doklad');
        self::assertSame(0, $result['failed'], 'nic nesmí selhat: ' . json_encode($result['details'], JSON_UNESCAPED_UNICODE));
        self::assertCount(1, $result['details'], 'dvojice je JEDNA položka reportu, ne dvě');
        self::assertSame('faktura-pair.pdf', $result['details'][0]['paired_pdf'] ?? null);
        self::assertArrayNotHasKey('warning', $result['details'][0], 'sedící VS nesmí vyvolat varování');

        $row = $this->loadCreated($vs);
        self::assertNotNull($row, 'draft musí vzniknout');
        self::assertSame(hash('sha256', $pdf), $row['pdf_hash'], 'archivované PDF = sourozenecký soubor');
        self::assertNotEmpty($row['pdf_path'], 'doklad nesmí zůstat bez čitelné podoby');
        self::assertSame(hash('sha256', $isdoc), $row['source_hash'], 'strojový originál = ISDOC');
        self::assertSame('isdoc', $row['source_format']);
    }

    /**
     * Regrese na skrytou část vady: `.xml` se řadí AŽ ZA `.pdf`, takže dokud
     * rozhodovalo abecední pořadí, tady vyhrávalo PDF (a s ním AI extrakce).
     */
    public function testXmlSiblingWinsOverPdfDespiteAlphabeticalOrder(): void
    {
        $vs    = $this->vsPrefix . '2';
        $isdoc = $this->minimalIsdoc($vs, '1210.00');
        $this->write('faktura-xml.xml', $isdoc);
        $this->write('faktura-xml.pdf', $this->pdfBytes("Variabilní symbol: {$vs}<br>Celkem k úhradě 1 210,00 Kč"));

        $result = $this->scanner->scan($this->supplierId, $this->userId);

        self::assertSame(1, $result['created']);
        self::assertCount(1, $result['details']);
        $row = $this->loadCreated($vs);
        self::assertNotNull($row);
        self::assertSame(hash('sha256', $isdoc), $row['source_hash'], 'data pochází z XML, ne z AI');
        self::assertNotEmpty($row['pdf_path'], 'PDF se přesto archivovalo');
    }

    /**
     * Poznámka k dedupu z issue: holý `.isdoc` neukládal žádný hash, takže každý
     * další sken ho protáhl importem znovu a v reportu se objevil jako `created`,
     * i když nový doklad nevznikl.
     */
    public function testRescanOfBareIsdocIsReportedAsSkipped(): void
    {
        $vs = $this->vsPrefix . '3';
        $this->write('faktura-bare.isdoc', $this->minimalIsdoc($vs, '1210.00'));

        $first = $this->scanner->scan($this->supplierId, $this->userId);
        self::assertSame(1, $first['created'], 'první běh doklad vytvoří');
        $this->loadCreated($vs);

        $second = $this->scanner->scan($this->supplierId, $this->userId);
        self::assertSame(0, $second['created'], 'druhý běh už nic nevytváří');
        self::assertSame(1, $second['skipped'], 'a hlásí to jako skipped');
        self::assertSame('Již importováno', $second['details'][0]['reason'] ?? '');
    }

    /**
     * Stornovaný doklad dedup neblokuje: znovu naskenovaný originál po stornu založí nový
     * doklad (s dalším pořadím čísla dodavatele), nestornovaný dál blokuje jako dřív.
     */
    public function testRescanAfterCancellationCreatesNewInvoice(): void
    {
        $vs = $this->vsPrefix . '5';
        $this->write('faktura-storno.isdoc', $this->minimalIsdoc($vs, '1210.00'));

        self::assertSame(1, $this->scanner->scan($this->supplierId, $this->userId)['created']);
        $first = $this->loadCreated($vs);
        self::assertNotNull($first);
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'cancelled' WHERE id = ?")->execute([(int) $first['id']]);

        $second = $this->scanner->scan($this->supplierId, $this->userId);
        self::assertSame(1, $second['created'], 'po stornu vznikne nový doklad: ' . json_encode($second['details'], JSON_UNESCAPED_UNICODE));
        $rows = $this->db->pdo()->prepare(
            'SELECT status, vendor_number_seq FROM purchase_invoices WHERE supplier_id = ? AND vendor_invoice_number = ? ORDER BY id'
        );
        $rows->execute([$this->supplierId, $vs]);
        self::assertSame([['cancelled', 0], ['draft', 1]], array_map(
            static fn (array $r): array => [(string) $r['status'], (int) $r['vendor_number_seq']],
            $rows->fetchAll(PDO::FETCH_ASSOC),
        ));

        $third = $this->scanner->scan($this->supplierId, $this->userId);
        self::assertSame(0, $third['created'], 'nestornovaný duplikát dál blokuje');
        self::assertSame('Již importováno', $third['details'][0]['reason'] ?? '');
    }

    /** Samotné PDF bez sourozence se chová jako dřív — přes AI (tady nenakonfigurovanou). */
    public function testStandalonePdfWithoutSiblingStillGoesTheOldWay(): void
    {
        $this->write('sken-sam.pdf', $this->pdfBytes('Faktura bez dat<br>Celkem k úhradě 1 210,00 Kč'));

        $result = $this->scanner->scan($this->supplierId, $this->userId);

        self::assertSame(0, $result['created']);
        self::assertCount(1, $result['details']);
        self::assertContains($result['details'][0]['status'], ['skipped', 'imported'],
            'bez AI klíče skipped, s nakonfigurovanou AI imported — nikdy tichý propad');
    }

    /**
     * Issue #118: smazaný koncept se z inboxu nesmí vrátit. Soubory v adresáři zůstávají
     * a dedup stál jen na existující faktuře — po smazání ji další sken založil znovu.
     */
    public function testDeletedDraftIsNotReimportedOnRescan(): void
    {
        $vs = $this->vsPrefix . '4';
        $this->write('faktura-smazana.isdoc', $this->minimalIsdoc($vs, '1210.00'));
        $this->write('faktura-smazana.pdf', $this->pdfBytes("Variabilní symbol: {$vs}<br>Celkem k úhradě 1 210,00 Kč"));

        $first = $this->scanner->scan($this->supplierId, $this->userId);
        self::assertSame(1, $first['created']);
        $row = $this->loadCreated($vs);
        self::assertNotNull($row);

        $request = (new ServerRequestFactory())
            ->createServerRequest('DELETE', '/api/purchase-invoices/' . $row['id'])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
        $response = ($this->container->get(DeletePurchaseInvoiceAction::class))(
            $request, new Psr7Response(), ['id' => (string) $row['id']],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertNull($this->loadCreated($vs), 'koncept je smazaný');

        $second = $this->scanner->scan($this->supplierId, $this->userId);
        self::assertSame(0, $second['created'], 'smazaný koncept se nesmí založit znovu');
        self::assertNull($this->loadCreated($vs));
        self::assertSame(1, $second['skipped']);
        self::assertSame('Koncept z tohoto souboru byl smazán', $second['details'][0]['reason'] ?? '');
    }

    private function write(string $name, string $bytes): void
    {
        file_put_contents($this->inboxDir . DIRECTORY_SEPARATOR . $name, $bytes);
    }

    /** @return array<string,mixed>|null */
    private function loadCreated(string $varsymbol): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, pdf_path, pdf_hash, source_hash, source_format
               FROM purchase_invoices WHERE supplier_id = ? AND vendor_invoice_number = ? LIMIT 1'
        );
        $stmt->execute([$this->supplierId, $varsymbol]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function pdfBytes(string $html): string
    {
        $pdf = new Mpdf(['tempDir' => sys_get_temp_dir(), 'default_font' => 'dejavusans']);
        $pdf->WriteHTML('<p style="font-family:dejavusans">' . $html . '</p>');
        return (string) $pdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    private function minimalIsdoc(string $id, string $payable): string
    {
        $ic = $this->supplierIc;
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="http://isdoc.cz/namespace/2013">
  <DocumentType>1</DocumentType>
  <ID>{$id}</ID>
  <IssueDate>2026-05-01</IssueDate>
  <TaxPointDate>2026-05-01</TaxPointDate>
  <LocalCurrencyCode>CZK</LocalCurrencyCode>
  <CurrencyCode>CZK</CurrencyCode>
  <AccountingSupplierParty><Party>
    <PartyIdentification><ID>12345678</ID></PartyIdentification>
    <PartyName><Name>Inbox Pair Vendor</Name></PartyName>
  </Party></AccountingSupplierParty>
  <AccountingCustomerParty><Party>
    <PartyName><Name>Buyer</Name></PartyName>
    <PartyIdentification><ID>{$ic}</ID></PartyIdentification>
  </Party></AccountingCustomerParty>
  <InvoiceLines><InvoiceLine>
    <Item><Description>Test položka</Description></Item>
    <InvoicedQuantity unitCode="ks">1</InvoicedQuantity>
    <UnitPrice>1000</UnitPrice>
    <ClassifiedTaxCategory><Percent>21</Percent></ClassifiedTaxCategory>
  </InvoiceLine></InvoiceLines>
  <LegalMonetaryTotal><PayableAmount>{$payable}</PayableAmount></LegalMonetaryTotal>
</Invoice>
XML;
    }
}
