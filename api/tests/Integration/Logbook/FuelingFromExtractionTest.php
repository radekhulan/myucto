<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Logbook;

use MyInvoice\Service\Logbook\CashFuelingService;
use MyInvoice\Service\Logbook\FuelingDocumentRef;
use MyInvoice\Service\Logbook\FuelingFromExtraction;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tankování z vytěžené účtenky: jeden doklad = nejvýš jedno tankování, opakované volání
 * nic nezdvojí, existující tankování dokladu se jen doplní, cizí doklad se odmítne.
 */
#[Group('integration')]
final class FuelingFromExtractionTest extends TestCase
{
    use LogbookFixtures;

    private const FIELDS = [
        'date' => '05.03.2099', 'time' => '07:15', 'amount' => '1 575,45', 'currency' => 'czk',
        'liters' => '40,5', 'fuel_type' => 'Nafta', 'plate' => '1ab-2345', 'odometer' => 120500,
        'station' => 'Testovací stanice', 'receipt_number' => 'R-1',
    ];

    private FuelingFromExtraction $service;
    private int $carA = 0;

    protected function setUp(): void
    {
        $this->bootLogbook();
        $this->service = $this->container->get(FuelingFromExtraction::class);
        $this->carA = $this->car($this->supplierA, '1AB 2345');
    }

    protected function tearDown(): void
    {
        $this->shutdownLogbook();
    }

    public function testRepeatedExtractionCreatesOneFueling(): void
    {
        $tx = $this->bankTransaction($this->supplierA, -1575.45);
        $ref = FuelingDocumentRef::bankTransaction($tx);

        $first = $this->service->fromExtraction($this->supplierA, self::FIELDS, $ref);
        $second = $this->service->fromExtraction($this->supplierA, self::FIELDS, $ref);

        self::assertSame('created', $first['status']);
        self::assertSame('plate', $first['car_method']);
        self::assertSame('unchanged', $second['status']);
        self::assertSame($first['fueling_id'], $second['fueling_id']);
        self::assertSame(1, $this->fuelingCount($this->supplierA));

        $row = $this->row((int) $first['fueling_id']);
        self::assertSame($this->carA, (int) $row['car_id']);
        self::assertSame('plate', $row['car_assigned_by']);
        self::assertSame($tx, (int) $row['source_bank_transaction_id']);
        self::assertSame('2099-03-05', $row['fueled_date']);
        self::assertSame(1575.45, (float) $row['amount_with_vat']);
        self::assertSame(38.9, (float) $row['unit_price'], 'Cena za litr se dopočítá z částky a litrů.');
        self::assertSame($ref->dedupHash($this->supplierA), $row['dedup_hash']);
    }

    public function testScanOfDocumentWithFuelingOnlyFillsMissingValues(): void
    {
        $doc = $this->cashDocument($this->supplierA, 'Nafta 40 l', 1500.00);
        $cash = $this->container->get(CashFuelingService::class)->scan($this->supplierA, $doc, null, null);
        self::assertSame(1, $cash['created']);

        $r = $this->service->fromExtraction($this->supplierA,
            ['date' => '2099-03-05', 'amount' => 1500, 'liters' => 99, 'odometer' => 98765, 'station' => 'Testovací stanice'],
            FuelingDocumentRef::cashDocument($doc));

        self::assertSame('updated', $r['status']);
        self::assertSame(1, $this->fuelingCount($this->supplierA), 'Sken dokladu s tankováním nesmí založit druhé.');
        $row = $this->row((int) $r['fueling_id']);
        self::assertSame(98765, (int) $row['odometer'], 'Chybějící tachometr se doplní.');
        self::assertSame(40.0, (float) $row['quantity'], 'Vyplněné litry se nepřepíšou.');
        self::assertSame('cash', $row['source']);
    }

    public function testFullCardNumberIsReducedToLast4(): void
    {
        $tx = $this->bankTransaction($this->supplierA, -800.00);

        $r = $this->service->fromExtraction($this->supplierA,
            ['date' => '2099-03-05', 'amount' => 800, 'card_last4' => '4000 0012 3456 4242'],
            FuelingDocumentRef::bankTransaction($tx));

        $row = $this->row((int) $r['fueling_id']);
        self::assertSame('4242', $row['card_last4']);
        self::assertStringNotContainsString('0012', json_encode($row, JSON_THROW_ON_ERROR));
    }

    public function testDocumentOfAnotherSupplierIsRejected(): void
    {
        $foreignDoc = $this->cashDocument($this->supplierB, 'Nafta 40 l', 1500.00);
        $foreignTx = $this->bankTransaction($this->supplierB, -1500.00);

        self::assertSame('rejected', $this->service->fromExtraction($this->supplierA, self::FIELDS, FuelingDocumentRef::cashDocument($foreignDoc))['status']);
        self::assertSame('rejected', $this->service->fromExtraction($this->supplierA, self::FIELDS, FuelingDocumentRef::bankTransaction($foreignTx))['status']);
        self::assertSame(0, $this->fuelingCount($this->supplierA));
        self::assertSame(0, $this->fuelingCount($this->supplierB));
    }

    public function testSeveralFuelingsOfOneDocumentAreNotGuessed(): void
    {
        $tx = $this->bankTransaction($this->supplierA, -1200.00);
        foreach ([500.00, 700.00] as $amount) {
            $this->pdo->prepare("INSERT INTO fuelings (supplier_id, fueled_date, amount_with_vat, source_bank_transaction_id) VALUES (?, '2099-03-05', ?, ?)")
                ->execute([$this->supplierA, $amount, $tx]);
        }
        $ref = FuelingDocumentRef::bankTransaction($tx);

        self::assertSame('ambiguous', $this->service->fromExtraction($this->supplierA, ['date' => '2099-03-05', 'amount' => 600], $ref)['status']);
        $hit = $this->service->fromExtraction($this->supplierA, ['date' => '2099-03-05', 'amount' => 700, 'odometer' => 5000], $ref);
        self::assertSame('updated', $hit['status']);
        self::assertSame(700.0, (float) $this->row((int) $hit['fueling_id'])['amount_with_vat']);
        self::assertSame(2, $this->fuelingCount($this->supplierA));
    }

    public function testAmbiguousCardHolderReportsReason(): void
    {
        $employee = $this->employee($this->supplierA, 'Testovací Řidič');
        $second = $this->car($this->supplierA, '2CD 6789');
        $this->pdo->prepare('UPDATE cars SET driver_employee_id = ? WHERE id IN (?, ?)')->execute([$employee, $this->carA, $second]);
        $this->pdo->prepare("INSERT INTO payment_cards (supplier_id, label, last4, employee_id) VALUES (?, 'Testovací karta', '4242', ?)")
            ->execute([$this->supplierA, $employee]);
        $tx = $this->bankTransaction($this->supplierA, -800.00);

        $r = $this->service->fromExtraction($this->supplierA, ['date' => '2099-03-05', 'amount' => 800, 'card_last4' => '4242'],
            FuelingDocumentRef::bankTransaction($tx));

        self::assertSame('ambiguous', $r['card_reason'] ?? null);
        self::assertNotSame('card', $r['car_method']);
    }

    public function testMissingAmountIsInvalid(): void
    {
        $tx = $this->bankTransaction($this->supplierA, -800.00);

        $r = $this->service->fromExtraction($this->supplierA, ['date' => '2099-03-05'], FuelingDocumentRef::bankTransaction($tx));

        self::assertFalse($r['ok']);
        self::assertSame('invalid', $r['status']);
        self::assertSame(0, $this->fuelingCount($this->supplierA));
    }

    /** @return array<string,mixed> */
    private function row(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM fuelings WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $this->supplierA]);
        return (array) $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
