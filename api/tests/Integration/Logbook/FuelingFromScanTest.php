<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Logbook;

use MyInvoice\Repository\DocumentExtractionRepository;
use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Repository\ScanBatchRepository;
use MyInvoice\Service\Document\ScanAttach\ScanBatchService;
use MyInvoice\Service\Document\ScanAttach\ScanExtractionNormalizer;
use MyInvoice\Service\Logbook\CashFuelingService;
use MyInvoice\Service\Logbook\FuelingFromScan;
use MyInvoice\Service\Logbook\FuelInvoiceScanner;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Sken účtenky připojený k dokladu (dávka skenů) → tankování v knize jízd. Jeden doklad
 * dá nejvýš jedno tankování bez ohledu na to, kolikrát a kterou cestou se zpracuje.
 */
#[Group('integration')]
final class FuelingFromScanTest extends TestCase
{
    private const STATION_IC = '12345678';

    use LogbookFixtures;

    private int $carA = 0;

    protected function setUp(): void
    {
        $this->bootLogbook();
        $this->carA = $this->car($this->supplierA, '1AB 2345');
        $this->fuelStationClient($this->supplierA, 'Testovací čerpací stanice', self::STATION_IC);
    }

    protected function tearDown(): void
    {
        $this->shutdownLogbook();
    }

    public function testStationReceiptAttachedByBatchBecomesOneFueling(): void
    {
        $doc = $this->cashDocument($this->supplierA, 'Nákup', 1575.45, ['partner_name' => 'Testovací čerpací stanice', 'partner_ic' => self::STATION_IC]);
        [$documentId, $sha] = $this->scan($this->supplierA, $this->receipt(1575.45, [$this->fuelLine(40.5, 38.9)], plate: '1AB-2345', card: '**** 4242'));

        $this->container->get(ScanBatchService::class)->attachItem($this->supplierA, 'cash_document', $doc, $this->batchItem($this->supplierA, $documentId, $sha));

        $row = $this->onlyFueling($this->supplierA);
        self::assertSame($doc, (int) $row['source_cash_document_id']);
        self::assertSame('cash', $row['source']);
        self::assertSame(40.5, (float) $row['quantity']);
        self::assertSame($this->carA, (int) $row['car_id']);
        self::assertSame('plate', $row['car_assigned_by']);
        self::assertSame('4242', $row['card_last4']);

        // Opakované zpracování i vytěžení popisu téhož dokladu skončí v jednom záznamu.
        $again = $this->fromScan()->afterAttach($this->supplierA, 'cash_document', $doc, $documentId);
        self::assertSame('unchanged', $again['status'] ?? null);
        $this->container->get(CashFuelingService::class)->scan($this->supplierA, $doc, null, null);
        self::assertSame(1, $this->fuelingCount($this->supplierA));
    }

    public function testDocumentWithFuelingIsOnlyFilled(): void
    {
        $doc = $this->cashDocument($this->supplierA, 'Nafta 40 l', 1500.00);
        $this->container->get(CashFuelingService::class)->scan($this->supplierA, $doc, null, null);
        [$documentId] = $this->scan($this->supplierA, $this->receipt(1500.00, [$this->fuelLine(40.0, 37.5)], card: '4242'));

        $r = $this->fromScan()->afterAttach($this->supplierA, 'cash_document', $doc, $documentId);

        self::assertSame('updated', $r['status'] ?? null);
        $row = $this->onlyFueling($this->supplierA);
        self::assertSame('Testovací čerpací stanice', $row['station'], 'Chybějící stanice se doplní.');
        self::assertSame('4242', $row['card_last4']);
    }

    public function testFuelInvoiceScannerDoesNotDuplicateScannedReceipt(): void
    {
        $vendor = (int) $this->pdo->query("SELECT id FROM clients WHERE supplier_id = {$this->supplierA} AND ic = '" . self::STATION_IC . "'")->fetchColumn();
        $invoice = $this->purchaseInvoice($vendor, 1210.00);
        [$documentId] = $this->scan($this->supplierA, $this->receipt(1210.00, [$this->fuelLine(31.0, 39.0)]));

        self::assertSame('created', $this->fromScan()->afterAttach($this->supplierA, 'purchase_invoice', $invoice, $documentId)['status'] ?? null);
        $this->container->get(FuelInvoiceScanner::class)->scanInvoice($this->supplierA, $invoice, null, null, true);

        self::assertSame(1, $this->fuelingCount($this->supplierA), 'Vytěžení faktury nesmí zdvojit tankování ze skenu.');
    }

    public function testNonFuelScansCreateNothing(): void
    {
        $doc = $this->cashDocument($this->supplierA, 'Nákup', 300.00);
        [$paper] = $this->scan($this->supplierA, $this->receipt(300.00, [['description' => 'Kancelářský papír', 'quantity' => 2, 'unit' => 'ks', 'unit_price_without_vat' => 150, 'vat_rate' => 21]], vendorIc: '87654321'));
        [$wash] = $this->scan($this->supplierA, $this->receipt(250.00, [['description' => 'Mytí vozu', 'quantity' => 1, 'unit' => 'ks', 'unit_price_without_vat' => 250, 'vat_rate' => 21]]));

        self::assertNull($this->fromScan()->afterAttach($this->supplierA, 'cash_document', $doc, $paper));
        self::assertNull($this->fromScan()->afterAttach($this->supplierA, 'cash_document', $doc, $wash), 'Mytí u stanice není tankování.');
        self::assertSame(0, $this->fuelingCount($this->supplierA));
    }

    public function testScanOfAnotherSupplierOrWithoutLogbookIsIgnored(): void
    {
        $docA = $this->cashDocument($this->supplierA, 'Nákup', 1500.00);
        $docB = $this->cashDocument($this->supplierB, 'Nákup', 1500.00);
        [$foreignScan] = $this->scan($this->supplierB, $this->receipt(1500.00, [$this->fuelLine(40.0, 37.5)]));

        self::assertNull($this->fromScan()->afterAttach($this->supplierA, 'cash_document', $docA, $foreignScan), 'Sken cizí firmy se nečte.');
        self::assertNull($this->fromScan()->afterAttach($this->supplierB, 'cash_document', $docB, $foreignScan), 'Firma bez vozidel knihu jízd nevede.');
        self::assertSame(0, $this->fuelingCount($this->supplierA));
        self::assertSame(0, $this->fuelingCount($this->supplierB));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function fromScan(): FuelingFromScan
    {
        return $this->container->get(FuelingFromScan::class);
    }

    /** @return array<string,mixed> syntetická odpověď AI vytěžení účtenky */
    private function receipt(float $total, array $items, ?string $plate = null, ?string $card = null, string $vendorIc = self::STATION_IC): array
    {
        return [
            'vendor' => ['company_name' => 'Testovací čerpací stanice', 'ic' => $vendorIc, 'dic' => null],
            'customer' => ['company_name' => 'Testovací odběratel', 'ic' => null, 'dic' => null],
            'vendor_invoice_number' => 'UCT-' . uniqid(), 'varsymbol' => null, 'document_kind' => 'receipt',
            'issue_date' => '2099-03-05', 'tax_date' => '2099-03-05', 'total_with_vat' => $total, 'currency' => 'CZK',
            'barcode' => null, 'license_plate' => $plate, 'card_last4' => $card, 'company_role' => 'buyer',
            'items' => $items, 'unit_prices_include_vat' => true,
        ];
    }

    /** @return array<string,mixed> */
    private function fuelLine(float $liters, float $pricePerLiter): array
    {
        return ['description' => 'Nafta', 'quantity' => $liters, 'unit' => 'l', 'unit_price_without_vat' => $pricePerLiter, 'vat_rate' => 21];
    }

    /** @return array{0:int, 1:string} documents.id a sha256 skenu s uloženým vytěžením */
    private function scan(int $supplierId, array $data): array
    {
        $sha = hash('sha256', uniqid('scan', true));
        $documentId = $this->container->get(DocumentRepository::class)->insert([
            'supplier_id' => $supplierId, 'folder_id' => null, 'title' => 'Účtenka', 'description' => null,
            'original_name' => 'uctenka.pdf', 'filename' => 'uctenka.pdf', 'sha256' => $sha, 'mime_type' => 'application/pdf',
            'size_bytes' => 100, 'doc_type' => 'pdf', 'uploaded_by' => null,
        ]);
        $this->container->get(DocumentExtractionRepository::class)->save(
            $supplierId, $documentId, $sha, ScanExtractionNormalizer::SCHEMA_VERSION, 'ok',
            ScanExtractionNormalizer::normalize($data), $data, 'fake', 'fake-model', null,
        );
        return [$documentId, $sha];
    }

    private function batchItem(int $supplierId, int $documentId, string $sha): int
    {
        $jobId = $this->container->get(ImportJobRepository::class)->create($supplierId, 'scan_attach', [], $this->userId);
        return $this->container->get(ScanBatchRepository::class)->insertItem($supplierId, $jobId, 'uctenka.pdf', $sha, 100, $documentId, 'extracted', null);
    }

    private function purchaseInvoice(int $vendorId, float $total): int
    {
        $stmt = $this->pdo->prepare('SELECT MIN(id) FROM currencies WHERE supplier_id = ?');
        $stmt->execute([$this->supplierA]);
        $d = '2099-03-05';
        $this->pdo->prepare(
            "INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, issue_date, tax_date, due_date, received_at,
                 currency_id, vendor_snapshot, total_without_vat, total_vat, total_with_vat, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, '{}', ?, 0, ?, 'received', ?)"
        )->execute([$this->supplierA, $vendorId, 'PF-T-' . uniqid(), $d, $d, $d, $d, (int) $stmt->fetchColumn(), $total, $total, $this->userId]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function onlyFueling(int $supplierId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM fuelings WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $rows);
        return $rows[0];
    }
}
