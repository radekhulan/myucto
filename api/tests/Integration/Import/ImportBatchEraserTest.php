<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Import;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Import\ImportBatchEraser;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Smazání importní dávky — a hlavně to, co se smazat NESMÍ.
 *
 * Zákazník migrující účetnictví si první dávku téměř nikdy nenahraje správně a potřebuje
 * ji zahodit. Mazání tisíců dokladů je ale nejdestruktivnější operace v systému, takže
 * tenhle test hlídá především hranice: zaúčtovaný doklad, doklad s úhradou ani doklad
 * mimo dávku se dotknout nesmí, a retenční lhůta se překročí VÝHRADNĚ na vědomé přání.
 *
 * Data syntetická (fiktivní IČO, rok 2093), izolovaný supplier, uklizeno v tearDown.
 */
#[Group('integration')]
final class ImportBatchEraserTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const BATCH = 'testbatch2093';

    private Connection $db;
    private ImportBatchEraser $eraser;

    private int $supplierId = 0;
    private int $clientId = 0;
    private int $czkId = 0;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db = $c->get(Connection::class);
            $this->eraser = $c->get(ImportBatchEraser::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($source === 0) {
            $this->markTestSkipped('Chybí základní data (supplier).');
        }
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);

        $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, "CZK", "CZK", "Kč", "CZK", "CZK", 2, 1, 1)'
        )->execute([$this->supplierId]);
        $this->czkId = (int) $pdo->lastInsertId();

        $countryId = (int) ($pdo->query("SELECT id FROM countries WHERE UPPER(iso2) = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($countryId === 0) {
            self::markTestSkipped('Stát CZ není v číselníku zemí.');
        }
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, ic, street, city, zip, country_id, main_email,
                                  language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "Testovací odběratel s.r.o.", "25596641", "Testovací 1", "Praha", "11000", ?, "odberatel@example.test", "cs", ?, 1, 0)'
        )->execute([$this->supplierId, $countryId, $this->czkId]);
        $this->clientId = (int) $pdo->lastInsertId();
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

    /** Koncept z dávky se smaže; doklad mimo dávku zůstane nedotčený. */
    public function testErasesOnlyDocumentsOfTheGivenBatch(): void
    {
        $inBatch = $this->invoice('93000001', 'draft', self::BATCH);
        $other   = $this->invoice('93000002', 'draft', 'jina-davka');
        $manual  = $this->invoice('93000003', 'draft', null);

        $result = $this->eraser->erase($this->supplierId, self::BATCH);

        self::assertSame(1, $result['deleted']['invoices']);
        self::assertNull($this->find($inBatch), 'Doklad z dávky měl zmizet.');
        self::assertNotNull($this->find($other), 'Doklad z jiné dávky se nesmí smazat.');
        self::assertNotNull($this->find($manual), 'Doklad bez dávky (vystavený v aplikaci) se nesmí smazat.');
    }

    /** JÁDRO: zaúčtovaný doklad se hromadně nemaže — storno zápisu má vidět člověk. */
    public function testBookedInvoiceIsSkipped(): void
    {
        $booked = $this->invoice('93000004', 'issued', self::BATCH, bookedAt: '2093-05-01 10:00:00');

        $result = $this->eraser->erase($this->supplierId, self::BATCH);

        self::assertSame(0, $result['deleted']['invoices']);
        self::assertNotNull($this->find($booked), 'Zaúčtovaný doklad musí zůstat.');
        self::assertCount(1, $result['skipped']);
        self::assertStringContainsString('zaúčtovaný', $result['skipped'][0]['reason']);
    }

    /**
     * Retenční lhůta drží vystavený doklad, dokud ji někdo vědomě nepřehlasuje —
     * stejné pravidlo jako u jednodokladového mazání (`?ack_retention=1`).
     */
    public function testRetentionBlocksIssuedInvoiceUntilAcknowledged(): void
    {
        $issued = $this->invoice('93000005', 'issued', self::BATCH);

        $blocked = $this->eraser->erase($this->supplierId, self::BATCH);
        self::assertSame(0, $blocked['deleted']['invoices'], 'Bez přehlasování se vystavený doklad nemaže.');
        self::assertNotNull($this->find($issued));
        self::assertStringContainsString('retenční', $blocked['skipped'][0]['reason']);

        $acked = $this->eraser->erase($this->supplierId, self::BATCH, null, null, true);
        self::assertSame(1, $acked['deleted']['invoices'], 'S vědomým přehlasováním se smazat má.');
        self::assertNull($this->find($issued));
        self::assertCount(1, $acked['retention_overridden'], 'Přehlasování musí zůstat dohledatelné.');
    }

    /** Průběh se hlásí a jmenovatel je počet dokladů dávky. */
    public function testReportsProgress(): void
    {
        $this->invoice('93000006', 'draft', self::BATCH);
        $this->invoice('93000007', 'draft', self::BATCH);

        $seen = [];
        $this->eraser->erase($this->supplierId, self::BATCH, function (int $processed, int $total) use (&$seen): void {
            $seen[] = [$processed, $total];
        });

        self::assertNotSame([], $seen);
        foreach ($seen as [$_, $total]) {
            self::assertSame(2, $total);
        }
        self::assertSame(2, end($seen)[0]);
    }

    // ── pomůcky ──────────────────────────────────────────────────────────────

    private function invoice(string $varsymbol, string $status, ?string $batch, ?string $bookedAt = null): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO invoices (supplier_id, client_id, invoice_type, varsymbol, status, issue_date, tax_date,
                                   due_date, currency_id, total_without_vat, total_vat, total_with_vat,
                                   import_batch_id, booked_at)
             VALUES (?, ?, "invoice", ?, ?, "2093-05-01", "2093-05-01", "2093-05-15", ?, 1000, 210, 1210, ?, ?)'
        )->execute([$this->supplierId, $this->clientId, $varsymbol, $status, $this->czkId, $batch, $bookedAt]);

        return (int) $pdo->lastInsertId();
    }

    private function find(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM invoices WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }
}
