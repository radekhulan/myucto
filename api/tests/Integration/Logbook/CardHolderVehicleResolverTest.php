<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Logbook;

use MyInvoice\Action\Logbook\FuelingsAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Bank\Card\CardPaymentVehicleHints;
use MyInvoice\Service\Bank\Card\PaymentCardVehicleResolver;
use MyInvoice\Service\Logbook\CashFuelingService;
use MyInvoice\Service\Logbook\FuelingImportService;
use MyInvoice\Service\Logbook\FuelInvoiceScanner;
use MyInvoice\Service\Logbook\VehicleResolver;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Vozidlo podle platební karty: koncovka + datum → karta platná k datu → držitel →
 * jeho jediné aktivní vozidlo. Ověřuje se přes skutečný kontejner (bind v Bootstrapu)
 * a přes všechny cesty, které tankování zakládají.
 */
#[Group('integration')]
final class CardHolderVehicleResolverTest extends TestCase
{
    use LogbookFixtures;

    private int $employeeA = 0;
    private int $carDriven = 0;
    private int $carDefault = 0;

    protected function setUp(): void
    {
        $this->bootLogbook();
        $this->employeeA = $this->employee($this->supplierA, 'Testovací Řidič');
        $this->carDriven = $this->car($this->supplierA, '1AB 2345');
        $this->carDefault = $this->car($this->supplierA, '2CD 6789', true);
        $this->drive($this->carDriven, $this->employeeA);
        $this->paymentCard($this->supplierA, '4242', $this->employeeA, '2099-01-01', '2099-06-30');
    }

    protected function tearDown(): void
    {
        $this->shutdownLogbook();
    }

    public function testContainerWiresCardStepIntoVehicleResolver(): void
    {
        $resolver = $this->container->get(VehicleResolver::class);
        $byCard = (new \ReflectionProperty(VehicleResolver::class, 'byCard'))->getValue($resolver);

        self::assertInstanceOf(PaymentCardVehicleResolver::class, $byCard);
        self::assertSame(
            ['car_id' => $this->carDriven, 'method' => 'card'],
            $resolver->resolve($this->supplierA, ['card_last4' => '4242', 'date' => '2099-03-05']),
            'Karta má přednost před výchozím vozidlem.',
        );
    }

    public function testCardMustBeValidOnTheDate(): void
    {
        $r = $this->cardResolver();

        self::assertSame($this->carDriven, $r->vehicleForCard($this->supplierA, '4242', '2099-06-30'));
        self::assertNull($r->vehicleForCard($this->supplierA, '4242', '2099-07-01'));
        self::assertSame(PaymentCardVehicleResolver::UNKNOWN_CARD, $r->explain($this->supplierA, '4242', '2098-12-31')['reason']);
    }

    public function testHolderWithSeveralVehiclesIsAmbiguous(): void
    {
        $second = $this->car($this->supplierA, '3GH 1111');
        $this->drive($second, $this->employeeA);

        $e = $this->cardResolver()->explain($this->supplierA, '4242', '2099-03-05');
        self::assertNull($e['car_id']);
        self::assertSame(PaymentCardVehicleResolver::AMBIGUOUS, $e['reason']);
        self::assertSame(
            ['car_id' => $this->carDefault, 'method' => 'default'],
            $this->container->get(VehicleResolver::class)->resolve($this->supplierA, ['card_last4' => '4242', 'date' => '2099-03-05']),
        );

        $this->pdo->prepare('UPDATE cars SET is_archived = 1 WHERE id = ?')->execute([$second]);
        self::assertSame($this->carDriven, $this->cardResolver()->vehicleForCard($this->supplierA, '4242', '2099-03-05'),
            'Archivované vozidlo se nepočítá.');
    }

    public function testCardOfAnotherSupplierIsRejected(): void
    {
        $employeeB = $this->employee($this->supplierB, 'Cizí Řidič');
        $carB = $this->car($this->supplierB, '9ZZ 9999');
        $this->drive($carB, $employeeB);
        $this->paymentCard($this->supplierB, '7777', $employeeB, null, null);

        self::assertSame($carB, $this->cardResolver()->vehicleForCard($this->supplierB, '7777', '2099-03-05'));
        self::assertNull($this->cardResolver()->vehicleForCard($this->supplierA, '7777', '2099-03-05'));
        self::assertSame(
            ['car_id' => null, 'method' => 'none'],
            $this->container->get(VehicleResolver::class)->resolve($this->supplierB, ['card_last4' => '4242', 'date' => '2099-03-05'], false),
            'Karta firmy A nesmí přiřadit vozidlo firmě B.',
        );
    }

    public function testCardWithoutEmployeeHolderGivesReason(): void
    {
        $this->paymentCard($this->supplierA, '5151', null, null, null);

        self::assertSame(PaymentCardVehicleResolver::NO_EMPLOYEE, $this->cardResolver()->explain($this->supplierA, '5151', '2099-03-05')['reason']);
        self::assertSame(PaymentCardVehicleResolver::INVALID_LAST4, $this->cardResolver()->explain($this->supplierA, '51', '2099-03-05')['reason']);
    }

    public function testImportCardColumnAssignsVehicleAndKeepsOnlyLast4(): void
    {
        $csv = "datum;celkem;karta\n05.03.2099;800;4000 0012 3456 4242\n";

        $r = $this->container->get(FuelingImportService::class)->import($this->supplierA, null, $csv, 'tankovani.csv');

        self::assertSame(1, $r['created']);
        $row = $this->onlyFueling();
        self::assertSame($this->carDriven, (int) $row['car_id']);
        self::assertSame('card', $row['car_assigned_by']);
        self::assertSame('4242', $row['card_last4']);
        self::assertStringNotContainsString('0012', json_encode($row, JSON_THROW_ON_ERROR));
    }

    public function testCashDocumentWithMaskedCardUsesHolderVehicle(): void
    {
        $doc = $this->cashDocument($this->supplierA, 'Nafta 40 l, karta **** 4242', 1500.00);

        $r = $this->container->get(CashFuelingService::class)->scan($this->supplierA, $doc, null, null);

        self::assertSame('card', $r['car_method']);
        $row = $this->onlyFueling();
        self::assertSame($this->carDriven, (int) $row['car_id']);
        self::assertSame('4242', $row['card_last4']);
    }

    public function testFuelingLinkedToCardPaymentGetsHolderVehicle(): void
    {
        $tx = $this->bankTransaction($this->supplierA, -1200.00);
        $this->pdo->prepare("UPDATE bank_transactions SET card_last4 = '4242' WHERE id = ?")->execute([$tx]);

        $res = $this->callCreate(['fueled_date' => '2099-03-05', 'amount_with_vat' => 1200, 'source_bank_transaction_id' => $tx]);

        self::assertSame(201, $res['status']);
        $row = $this->onlyFueling();
        self::assertSame($this->carDriven, (int) $row['car_id']);
        self::assertSame('card', $row['car_assigned_by']);
        self::assertSame('4242', $row['card_last4']);
    }

    public function testFuelInvoicePaidByCardGetsHolderVehicle(): void
    {
        $vendor = $this->fuelStationClient($this->supplierA, 'Testovací čerpací stanice', '12345678');
        $invoice = $this->purchaseInvoice($vendor, 1500.00, '4242');

        $r = $this->container->get(FuelInvoiceScanner::class)->scanInvoice($this->supplierA, $invoice, null, null, true);

        self::assertTrue($r['ok']);
        self::assertTrue($this->pdo->inTransaction(), 'Vytěžení nesmí ukončit testovací transakci.');
        $row = $this->onlyFueling();
        self::assertSame($this->carDriven, (int) $row['car_id']);
        self::assertSame('card', $row['car_assigned_by']);
        self::assertSame('4242', $row['card_last4']);
    }

    public function testCardPaymentHintShowsHolderVehicleOnlyAtFuelStation(): void
    {
        $hints = $this->container->get(CardPaymentVehicleHints::class);
        $tx = ['card_last4' => '4242', 'posted_at' => '2099-03-05'];

        $hint = $hints->hintFor($this->supplierA, $tx + ['counterparty_name' => 'SHELL 1234 PRAHA']);
        self::assertSame('1AB 2345', $hint['registration'] ?? null);
        self::assertNull($hints->hintFor($this->supplierA, $tx + ['counterparty_name' => 'TESTOVACI PAPIRNICTVI']));
        self::assertNull($hints->hintFor($this->supplierB, $tx + ['counterparty_name' => 'SHELL 1234 PRAHA']));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function cardResolver(): PaymentCardVehicleResolver
    {
        return $this->container->get(PaymentCardVehicleResolver::class);
    }

    private function drive(int $carId, int $employeeId): void
    {
        $this->pdo->prepare('UPDATE cars SET driver_employee_id = ? WHERE id = ?')->execute([$employeeId, $carId]);
    }

    private function paymentCard(int $supplierId, string $last4, ?int $employeeId, ?string $from, ?string $to): int
    {
        $this->pdo->prepare(
            'INSERT INTO payment_cards (supplier_id, label, last4, employee_id, valid_from, valid_to) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$supplierId, 'Testovací karta ' . $last4, $last4, $employeeId, $from, $to]);
        return (int) $this->pdo->lastInsertId();
    }

    private function purchaseInvoice(int $vendorId, float $total, string $cardLast4): int
    {
        $stmt = $this->pdo->prepare('SELECT MIN(id) FROM currencies WHERE supplier_id = ?');
        $stmt->execute([$this->supplierA]);
        $d = '2099-03-05';
        $this->pdo->prepare(
            "INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, issue_date, tax_date, due_date, received_at,
                 currency_id, vendor_snapshot, total_without_vat, total_vat, total_with_vat, status,
                 payment_method, card_last4, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, '{}', ?, 0, ?, 'received', 'card', ?, ?)"
        )->execute([$this->supplierA, $vendorId, 'PF-T-' . uniqid(), $d, $d, $d, $d, (int) $stmt->fetchColumn(),
            $total, $total, $cardLast4, $this->userId]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function onlyFueling(): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM fuelings WHERE supplier_id = ?');
        $stmt->execute([$this->supplierA]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $rows);
        return $rows[0];
    }

    /**
     * @param array<string,mixed> $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function callCreate(array $body): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/test')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierA)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withParsedBody($body);
        /** @var ResponseInterface $response */
        $response = $this->container->get(FuelingsAction::class)->create($request, new Psr7Response());
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
