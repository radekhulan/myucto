<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\WorkReport;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\WorkReportRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Výkaz materiálu vedle výkazu práce (migrace 0114).
 *
 * Pokrývá ukládání/čtení obou částí téže work_reports řádky nezávisle:
 *   • save()         — práce + vat_rate_id (nesahá na materiál)
 *   • saveMaterials()— materiál + material_vat_rate_id + material_total (nesahá na práci)
 *   • lazy vznik řádky když přijde materiál dřív než práce
 *   • prázdný materiál → material_total = 0, řádky smazány
 *
 * Izolováno pod existujícím supplierem, vše uklizeno v tearDown.
 * Soft-skip pokud chybí cfg.php / DB.
 */
#[Group('integration')]
final class WorkReportMaterialsRepositoryTest extends TestCase
{
    private Connection $db;
    private WorkReportRepository $repo;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $czId = 0;
    private int $userId = 0;
    private int $clientId = 0;
    private int $vat21 = 0;
    private int $vat12 = 0;

    /** @var int[] */
    private array $invoiceIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->repo = $container->get(WorkReportRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code='CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->vat21      = (int) ($pdo->query("SELECT id FROM vat_rates WHERE code='CZ-21' LIMIT 1")->fetchColumn() ?: 0);
        $this->vat12      = (int) ($pdo->query("SELECT id FROM vat_rates WHERE code='CZ-12' LIMIT 1")->fetchColumn() ?: 0);
        if (!$this->supplierId || !$this->currencyId || !$this->czId || !$this->userId || !$this->vat21 || !$this->vat12) {
            $this->markTestSkipped('Chybí základní data v DB (supplier/currency/country/user/vat_rates).');
        }

        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, 'WRM Test klient', 'Ulice 1', 'Praha', '11000', $this->czId, 'wrm@example.cz', $this->currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $pdo = $this->db->pdo();
        foreach ($this->invoiceIds as $id) {
            $wrId = (int) ($pdo->query('SELECT id FROM work_reports WHERE invoice_id = ' . $id)->fetchColumn() ?: 0);
            if ($wrId > 0) {
                $pdo->prepare('DELETE FROM work_report_materials WHERE work_report_id = ?')->execute([$wrId]);
                $pdo->prepare('DELETE FROM work_report_items WHERE work_report_id = ?')->execute([$wrId]);
            }
            $pdo->prepare('DELETE FROM work_reports WHERE invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        }
        if ($this->clientId) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$this->clientId]);
        }
        $this->db->close();
    }

    private function draftInvoice(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO invoices (supplier_id, client_id, project_id, issue_date, due_date, currency_id, created_by, status, invoice_type)
             VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, $this->clientId, '2026-06-01', '2026-06-15', $this->currencyId, $this->userId, 'draft', 'invoice']);
        $invId = (int) $pdo->lastInsertId();
        $this->invoiceIds[] = $invId;
        return $invId;
    }

    public function testSaveWorkThenMaterialsCoexist(): void
    {
        $inv = $this->draftInvoice();

        $this->repo->save($inv, $this->supplierId, null, 'Práce 6/2026', [
            ['description' => 'Programování', 'hours' => 10, 'rate' => 1500, 'order_index' => 0],
        ], $this->vat21);

        $this->repo->saveMaterials($inv, $this->supplierId, null, 'Materiál', $this->vat12, [
            ['description' => 'Kabel UTP', 'quantity' => 50, 'unit' => 'm', 'unit_price' => 12.50, 'order_index' => 0],
            ['description' => 'Konektor RJ45', 'quantity' => 20, 'unit' => 'ks', 'unit_price' => 5, 'order_index' => 1],
        ]);

        $wr = $this->repo->findByInvoice($inv, $this->supplierId);
        self::assertNotNull($wr);

        // Práce zůstala netknutá.
        self::assertSame('Práce 6/2026', $wr['title']);
        self::assertSame(10.0, $wr['total_hours']);
        self::assertSame(15000.0, $wr['total_amount']);
        self::assertSame($this->vat21, $wr['vat_rate_id']);
        self::assertCount(1, $wr['items']);

        // Materiál: total = 50*12.50 + 20*5 = 625 + 100 = 725.
        self::assertSame('Materiál', $wr['material_title']);
        self::assertSame($this->vat12, $wr['material_vat_rate_id']);
        self::assertSame(725.0, $wr['material_total']);
        self::assertCount(2, $wr['materials']);
        self::assertSame(625.0, $wr['materials'][0]['total_amount']);
        self::assertSame('m', $wr['materials'][0]['unit']);
    }

    public function testSaveMaterialsLazyCreatesRow(): void
    {
        $inv = $this->draftInvoice();

        // Materiál přijde dřív než práce → řádka work_reports musí vzniknout.
        $this->repo->saveMaterials($inv, $this->supplierId, null, 'Jen materiál', $this->vat12, [
            ['description' => 'Barva', 'quantity' => 3, 'unit' => 'ks', 'unit_price' => 200, 'order_index' => 0],
        ]);

        $wr = $this->repo->findByInvoice($inv, $this->supplierId);
        self::assertNotNull($wr);
        self::assertSame(600.0, $wr['material_total']);
        self::assertSame(0.0, $wr['total_hours']);
        self::assertSame(0.0, $wr['total_amount']);
        self::assertCount(1, $wr['materials']);
    }

    public function testSaveMaterialsDoesNotTouchWorkAndViceVersa(): void
    {
        $inv = $this->draftInvoice();

        $this->repo->save($inv, $this->supplierId, null, 'Práce', [
            ['description' => 'Konzultace', 'hours' => 2, 'rate' => 1000, 'order_index' => 0],
        ], $this->vat21);
        $this->repo->saveMaterials($inv, $this->supplierId, null, 'Materiál', $this->vat12, [
            ['description' => 'Šroub', 'quantity' => 100, 'unit' => 'ks', 'unit_price' => 1.5, 'order_index' => 0],
        ]);

        // Přeuložení práce nesmí smazat materiál.
        $this->repo->save($inv, $this->supplierId, null, 'Práce v2', [
            ['description' => 'Konzultace', 'hours' => 3, 'rate' => 1000, 'order_index' => 0],
        ], $this->vat12);
        $wr = $this->repo->findByInvoice($inv, $this->supplierId);
        self::assertSame(3000.0, $wr['total_amount']);
        self::assertSame($this->vat12, $wr['vat_rate_id']);
        self::assertSame(150.0, $wr['material_total'], 'Materiál přežil přeuložení práce');

        // Přeuložení materiálu nesmí smazat práci.
        $this->repo->saveMaterials($inv, $this->supplierId, null, 'Materiál v2', $this->vat21, []);
        $wr = $this->repo->findByInvoice($inv, $this->supplierId);
        self::assertSame(3000.0, $wr['total_amount'], 'Práce přežila přeuložení materiálu');
        self::assertSame(0.0, $wr['material_total'], 'Prázdný materiál → total 0');
        self::assertCount(0, $wr['materials']);
        self::assertSame('Materiál v2', $wr['material_title']);
    }

    public function testExactMinuteHeaderSumsRoundedRowsWithoutChangingLegacyAggregation(): void
    {
        $inv = $this->draftInvoice();

        $this->repo->save($inv, $this->supplierId, null, 'Přesné minuty', [
            ['description' => 'Minuta A', 'hours' => 1 / 60, 'duration_minutes' => 1, 'rate' => 0.9],
            ['description' => 'Minuta B', 'hours' => 1 / 60, 'duration_minutes' => 1, 'rate' => 0.9],
        ]);

        $exact = $this->repo->findByInvoice($inv, $this->supplierId);
        self::assertSame(0.04, $exact['total_amount']);
        self::assertSame([0.02, 0.02], array_column($exact['items'], 'total_amount'));

        $this->repo->save($inv, $this->supplierId, null, 'Legacy hodiny', [
            ['description' => 'Legacy A', 'hours' => 0.5, 'duration_minutes' => null, 'rate' => 0.03],
            ['description' => 'Legacy B', 'hours' => 0.5, 'duration_minutes' => null, 'rate' => 0.03],
        ]);

        $legacy = $this->repo->findByInvoice($inv, $this->supplierId);
        self::assertSame(0.03, $legacy['total_amount']);
        self::assertSame([0.02, 0.02], array_column($legacy['items'], 'total_amount'));

        $this->repo->save($inv, $this->supplierId, null, 'Smíšené řádky', [
            ['description' => 'Přesná minuta', 'hours' => 1 / 60, 'duration_minutes' => 1, 'rate' => 0.9],
            ['description' => 'Legacy půlhodina', 'hours' => 0.5, 'duration_minutes' => null, 'rate' => 0.03],
        ]);

        $mixed = $this->repo->findByInvoice($inv, $this->supplierId);
        self::assertSame(0.04, $mixed['total_amount']);
    }
}
