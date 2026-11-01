<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Repository\RecurringTemplateRepository;
use MyInvoice\Repository\WorkReportRepository;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Service\Invoice\PurchaseInvoiceCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class TimeBillingPersistenceTest extends TestCase
{
    private Connection $db;
    private \Psr\Container\ContainerInterface $container;
    private int $supplierId;
    private int $currencyId;
    private int $userId;
    private int $clientId;
    private int $vatId;

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $pdo = $this->db->pdo();
        $this->supplierId = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->currencyId = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' AND supplier_id = {$this->supplierId} LIMIT 1")->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $this->vatId = (int) $pdo->query("SELECT id FROM vat_rates WHERE code = 'CZ-21' LIMIT 1")->fetchColumn();
        self::assertGreaterThan(0, min($this->supplierId, $this->currencyId, $this->userId, $this->vatId), 'Chybí základní testovací fixture.');
        $pdo->beginTransaction();
        $pdo->prepare(
            "INSERT INTO clients (supplier_id, company_name, street, city, zip, main_email, is_vendor, is_vat_payer, country_id, currency_default_id)
             VALUES (?, 'Syntetický časový test', 'Testovací 1', 'Praha', '11000', 'time@example.test', 1, 1, (SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1), ?)"
        )->execute([$this->supplierId, $this->currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
            $this->db->close();
        }
    }

    private function draft(bool $purchase = false, bool $gross = false): int
    {
        $data = [
            'client_id' => $this->clientId, 'vendor_id' => $this->clientId,
            'vendor_invoice_number' => 'TIME-SYNTHETIC-001',
            'currency_id' => $this->currencyId, 'issue_date' => '2095-06-15',
            'tax_date' => '2095-06-15', 'due_date' => '2095-06-30',
            'prices_include_vat' => $gross,
        ];
        return $purchase
            ? $this->container->get(PurchaseInvoiceRepository::class)->createDraft($data, $this->userId, $this->supplierId)
            : $this->container->get(InvoiceRepository::class)->createDraft($data, $this->userId);
    }

    private function item(float $quantity, ?int $minutes, float $price, string $unit = 'h'): array
    {
        return [
            'description' => 'Syntetická práce', 'quantity' => $quantity,
            'duration_minutes' => $minutes, 'unit' => $unit,
            'unit_price_without_vat' => $price, 'vat_rate_id' => $this->vatId,
        ];
    }

    public static function invoiceModes(): iterable
    {
        yield 'vystavená netto' => [false, false];
        yield 'vystavená brutto' => [false, true];
        yield 'přijatá netto' => [true, false];
        yield 'přijatá brutto' => [true, true];
    }

    #[DataProvider('invoiceModes')]
    public function testExactAndLegacyRowsSurviveReloadAndResave(bool $purchase, bool $gross): void
    {
        $id = $this->draft($purchase, $gross);
        $repo = $this->container->get($purchase ? PurchaseInvoiceRepository::class : InvoiceRepository::class);
        $calc = $purchase ? new PurchaseInvoiceCalculator($this->db) : new InvoiceCalculator($this->db);
        $repo->replaceItems($id, [
            $this->item(0.017, 1, 1000),
            $this->item(0.33, null, 1000),
            $this->item(0.333, 20, 333.333333),
            $this->item(2, null, 12.345678, 'ks'),
        ]);
        $firstTotals = $calc->recompute($id);
        $first = $repo->itemsFor($id);
        self::assertCount(4, $first);
        self::assertSame(1, $first[0]['duration_minutes']);
        self::assertNull($first[1]['duration_minutes']);
        self::assertSame(0.33, $first[1]['quantity']);
        self::assertSame(20, $first[2]['duration_minutes']);
        self::assertSame(333.333333, $first[2]['unit_price_without_vat']);
        self::assertSame(12.35, $first[3]['unit_price_without_vat']);
        $amountKey = $gross ? 'total_with_vat' : 'total_without_vat';
        self::assertSame(16.67, $first[0][$amountKey]);
        self::assertSame(330.0, $first[1][$amountKey]);
        self::assertSame(111.11, $first[2][$amountKey]);
        $repo->replaceItems($id, $first);
        self::assertSame($firstTotals, $calc->recompute($id));
        $second = $repo->itemsFor($id);
        foreach ($first as $index => $row) {
            unset($row['id']);
            $again = $second[$index];
            unset($again['id']);
            self::assertSame($row, $again);
        }
    }

    public function testWorkReportPreservesLegacyHoursAndMaterialWhileSavingMinutes(): void
    {
        $id = $this->draft();
        $repo = new WorkReportRepository($this->db);
        $repo->saveMaterials($id, $this->supplierId, null, 'Materiál', $this->vatId, [
            ['description' => 'Syntetický materiál', 'quantity' => 2, 'unit' => 'ks', 'unit_price' => 12.34],
        ]);
        $repo->save($id, $this->supplierId, null, 'Práce', [
            ['description' => 'Původní hodiny', 'hours' => 0.33, 'rate' => 1000],
            ['description' => 'Jedna minuta', 'hours' => 0.02, 'duration_minutes' => 1, 'rate' => 1000],
            ['description' => 'Přesná sazba', 'hours' => 0.33, 'duration_minutes' => 20, 'rate' => 333.333333],
        ], $this->vatId);
        $first = $repo->findByInvoice($id, $this->supplierId);
        self::assertNotNull($first);
        self::assertNull($first['items'][0]['duration_minutes']);
        self::assertSame(0.33, $first['items'][0]['hours']);
        self::assertSame(330.0, $first['items'][0]['total_amount']);
        self::assertSame(16.67, $first['items'][1]['total_amount']);
        self::assertSame(111.11, $first['items'][2]['total_amount']);
        self::assertSame(457.78, $first['total_amount']);
        self::assertSame(24.68, $first['material_total']);
        $repo->save($id, $this->supplierId, null, 'Práce', $first['items'], $this->vatId);
        $again = $repo->findByInvoice($id, $this->supplierId);
        self::assertSame($first['total_amount'], $again['total_amount']);
        self::assertSame($first['materials'], $again['materials']);
        self::assertSame([null, 1, 20], array_column($again['items'], 'duration_minutes'));
    }

    public function testRecurringTemplatePreservesExactAndLegacyRows(): void
    {
        $repo = new RecurringTemplateRepository($this->db);
        $id = $repo->create([
            'supplier_id' => $this->supplierId, 'client_id' => $this->clientId,
            'currency_id' => $this->currencyId, 'name' => 'Syntetický časový test',
            'frequency' => 'monthly', 'anchor_date' => '2095-06-15',
        ], $this->userId);
        $repo->replaceItems($id, [$this->item(0.333, 20, 333.333333), $this->item(0.33, null, 1000)]);
        $first = $repo->itemsFor($id);
        self::assertSame(20, $first[0]['duration_minutes']);
        self::assertSame(333.333333, $first[0]['unit_price_without_vat']);
        self::assertNull($first[1]['duration_minutes']);
        self::assertSame(0.33, $first[1]['quantity']);
        $repo->replaceItems($id, $first);
        $again = $repo->itemsFor($id);
        self::assertSame([20, null], array_column($again, 'duration_minutes'));
        self::assertSame(array_column($first, 'unit_price_without_vat'), array_column($again, 'unit_price_without_vat'));
    }
}
