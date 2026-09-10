<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Logbook;

use MyInvoice\Service\Logbook\CashFuelingService;
use MyInvoice\Service\Logbook\FuelingOdometerWarnings;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Tankování z pokladního dokladu: rozpoznání, vytěžení, idempotence, vazba na firmu. */
#[Group('integration')]
final class CashFuelingServiceTest extends TestCase
{
    use LogbookFixtures;

    private CashFuelingService $service;
    private int $carA = 0;

    protected function setUp(): void
    {
        $this->bootLogbook();
        $this->service = $this->container->get(CashFuelingService::class);
        $this->carA = $this->car($this->supplierA, '1AB 2345');
    }

    protected function tearDown(): void
    {
        $this->shutdownLogbook();
    }

    public function testFuelReceiptBecomesLinkedFueling(): void
    {
        $doc = $this->cashDocument($this->supplierA, 'Nafta 42,50 l, tach. 98 765, 1AB 2345', 1650.00);
        $this->pdo->prepare('INSERT INTO cash_document_vat_lines (cash_document_id, vat_rate, base_amount, vat_amount) VALUES (?, 21, 1363.64, 286.36)')
            ->execute([$doc]);

        $r = $this->service->scan($this->supplierA, $doc, null, null);

        self::assertTrue($r['ok']);
        self::assertSame(1, $r['created']);
        self::assertSame('plate', $r['car_method']);
        $row = $this->fueling($doc);
        self::assertSame($this->carA, (int) $row['car_id']);
        self::assertSame('cash', $row['source']);
        self::assertSame(42.5, (float) $row['quantity']);
        self::assertSame(98765, (int) $row['odometer']);
        self::assertSame(286.36, (float) $row['amount_vat']);
        self::assertSame(38.8235, (float) $row['unit_price']);
    }

    public function testRepeatedScanDoesNotDuplicate(): void
    {
        $doc = $this->cashDocument($this->supplierA, 'Nafta 40 l', 1500.00);

        $this->service->scan($this->supplierA, $doc, null, null);
        $again = $this->service->scan($this->supplierA, $doc, null, null);

        self::assertSame(0, $again['created']);
        self::assertSame(1, $this->fuelingCount($this->supplierA));
    }

    public function testCandidatesRecognizeFuelStationAndSkipOtherExpenses(): void
    {
        $this->fuelStationClient($this->supplierA, 'Testovací čerpací stanice', '12345678');
        $station = $this->cashDocument($this->supplierA, 'Nákup', 900.00, ['partner_name' => 'Testovací čerpací stanice', 'partner_ic' => '12345678']);
        $fuel = $this->cashDocument($this->supplierA, 'Natural 95', 1200.00);
        $office = $this->cashDocument($this->supplierA, 'Kancelářské potřeby', 300.00);
        $wash = $this->cashDocument($this->supplierA, 'Mytí vozu', 250.00, ['partner_ic' => '12345678']);

        $ids = array_column($this->service->candidates($this->supplierA), 'id');

        self::assertContains($station, $ids);
        self::assertContains($fuel, $ids);
        self::assertNotContains($office, $ids);
        self::assertNotContains($wash, $ids);
    }

    public function testDocumentOfAnotherSupplierIsInvisible(): void
    {
        $doc = $this->cashDocument($this->supplierA, 'Nafta 40 l', 1500.00);

        self::assertNotContains($doc, array_column($this->service->candidates($this->supplierB), 'id'));
        $r = $this->service->scan($this->supplierB, $doc, null, null);
        self::assertFalse($r['ok']);
        self::assertSame(0, $this->fuelingCount($this->supplierB));
        self::assertSame(0, $this->fuelingCount($this->supplierA));
    }

    public function testAutomaticFuelingOnlyForSuppliersKeepingLogbook(): void
    {
        $docA = $this->cashDocument($this->supplierA, 'Nafta 40 l', 1500.00);
        $docB = $this->cashDocument($this->supplierB, 'Nafta 40 l', 1500.00);

        self::assertNotNull($this->service->autoFromCashDocument($this->supplierA, $docA, null));
        self::assertNull($this->service->autoFromCashDocument($this->supplierA, $docA, null), 'Podruhé už nic.');
        self::assertNull($this->service->autoFromCashDocument($this->supplierB, $docB, null), 'Firma bez vozidel knihu jízd nevede.');
        self::assertSame(1, $this->fuelingCount($this->supplierA));
        self::assertSame(0, $this->fuelingCount($this->supplierB));
    }

    public function testVatDeductionMismatchWithVehiclePolicyIsWarned(): void
    {
        $this->pdo->prepare("UPDATE cars SET usage_mode = 'mixed', vat_deduction_mode = 'proportional', vat_deduction_percent = 60 WHERE id = ?")
            ->execute([$this->carA]);
        $doc = $this->cashDocument($this->supplierA, 'Nafta 40 l, 1AB 2345', 1210.00);
        $this->pdo->prepare('INSERT INTO cash_document_vat_lines (cash_document_id, vat_rate, base_amount, vat_amount) VALUES (?, 21, 1000, 210)')
            ->execute([$doc]);
        $this->service->scan($this->supplierA, $doc, null, null);

        $w = $this->container->get(FuelingOdometerWarnings::class)->forTenant($this->supplierA);

        self::assertSame(1, $w['totals']['vat_mismatches']);
        self::assertSame('over', $w['cars'][0]['vat_mismatches'][0]['direction']);
        self::assertSame(1, $w['totals']['missing'], 'Doklad bez tachometru → chybějící stav.');
    }

    /** @return array<string,mixed> */
    private function fueling(int $cashDocumentId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM fuelings WHERE supplier_id = ? AND source_cash_document_id = ?');
        $stmt->execute([$this->supplierA, $cashDocumentId]);
        return (array) $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
