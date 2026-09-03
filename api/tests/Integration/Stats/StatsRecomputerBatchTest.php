<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stats;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Stats\StatsRecomputer;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * `StatsRecomputer::recomputeMany()` — dávkový přepočet po hromadné operaci (import,
 * bulk reissue). Nález, který ho vyvolal: šest cest zakládajících/měnících vydané
 * doklady (import souborů, Fakturoid/iDoklad sync, hromadné přefakturování, vyúčtování
 * zálohy, daňový doklad k platbě) cache seznamu klientů vůbec nevolaly — ruční
 * `recompute-stats.php` to opravil, takže data byla v pořádku, jen cache stará.
 *
 * Testuje se ZDE integrací nad reálnou DB (ne mockem `StatsRecomputer` — třída je
 * `final` a metody nejsou navržené pro spy): dedup se dokazuje END-STATE cache
 * (přesně JEDEN řádek per klient/projekt i po masivním opakování téhož id v poli —
 * naivní implementace by řádky nezdvojila taky, protože `recomputeClient()` je
 * idempotentní DELETE+INSERT, ale prázdný/neplatný vstup a mix s neexistujícími id
 * musí projít bez výjimky, což END-STATE test ověří přímo).
 *
 * Izolovaný supplier + dva klienti, uklizeno v tearDown ručně (BEZ obalové transakce —
 * `StatsRecomputer` si otevírá vlastní transakci per klient/projekt, vnořené PDO
 * transakce nejdou, viz komentář v {@see \MyInvoice\Service\Sample\SampleDataGenerator}).
 */
#[Group('integration')]
final class StatsRecomputerBatchTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const TAX_DATE = '2093-05-10';

    private Connection $db;
    private StatsRecomputer $stats;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $czkId = 0;
    private int $clientA = 0;
    private int $clientB = 0;
    private int $projectA = 0;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db    = $c->get(Connection::class);
            $this->stats = $c->get(StatsRecomputer::class);
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
        $this->czkId = $this->currency('CZK', 'Kč');
        $this->clientA = $this->client('Klient A s.r.o.', '25596641');
        $this->clientB = $this->client('Klient B s.r.o.', '27074358');
        $this->projectA = $this->project($this->clientA, 'Zakázka A');
    }

    protected function tearDown(): void
    {
        if (!isset($this->db) || $this->supplierId === 0) {
            return;
        }
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM invoices WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM project_revenue_cache WHERE project_id = ?')->execute([$this->projectA]);
        $pdo->prepare('DELETE FROM projects WHERE client_id IN (?, ?)')->execute([$this->clientA, $this->clientB]);
        $pdo->prepare('DELETE FROM clients WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM currencies WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplierId]);
        $this->db->close();
    }

    /**
     * Prázdný vstup je no-op — nesmí spadnout ani nic zapsat.
     */
    public function testEmptyInputIsNoOp(): void
    {
        $this->stats->recomputeMany([], []);
        self::assertNull($this->cacheRow($this->clientA), 'Bez volání předtím cache pro klienta neexistuje.');

        // I po no-op volání zůstává cache prázdná — recomputeMany bez id nesmí založit
        // řádek "od nuly".
        $this->stats->recomputeMany([]);
        self::assertNull($this->cacheRow($this->clientA));
    }

    /**
     * Dávka přepočte KAŽDÉHO klienta i projekt právě jednou bez ohledu na to, kolikrát
     * se jeho id v poli opakuje, a neplatná id (0, záporné) tiše ignoruje.
     */
    public function testDuplicateAndInvalidIdsAreDeduplicatedAndFiltered(): void
    {
        $this->insertIssuedInvoice($this->clientA, $this->projectA, 1000, 1210);
        $this->insertIssuedInvoice($this->clientA, $this->projectA, 500, 605);
        $this->insertIssuedInvoice($this->clientB, null, 2000, 2420);

        // Masivní opakování téhož id + neplatná id (0, záporné) v obou polích.
        $clientIds = array_merge(
            array_fill(0, 20, $this->clientA),
            [$this->clientB, 0, -5, $this->clientB],
        );
        $projectIds = array_merge(array_fill(0, 15, $this->projectA), [0, -1]);

        $this->stats->recomputeMany($clientIds, $projectIds);

        // Přesně JEDEN řádek per (klient, měna) — žádná duplicita po opakovaném přepočtu.
        $rowsA = $this->cacheRows($this->clientA);
        self::assertCount(1, $rowsA, 'Klient A musí mít v cache přesně jeden řádek za CZK.');
        self::assertSame(2, (int) $rowsA[0]['invoice_count']);
        self::assertEqualsWithDelta(1500.0, (float) $rowsA[0]['revenue'], 0.01);

        $rowsB = $this->cacheRows($this->clientB);
        self::assertCount(1, $rowsB);
        self::assertSame(1, (int) $rowsB[0]['invoice_count']);
        self::assertEqualsWithDelta(2000.0, (float) $rowsB[0]['revenue'], 0.01);

        $projectRows = $this->projectCacheRows($this->projectA);
        self::assertCount(1, $projectRows, 'Projekt musí mít v cache přesně jeden řádek za CZK.');
        self::assertSame(2, (int) $projectRows[0]['invoice_count']);
    }

    // ── pomůcky ──────────────────────────────────────────────────────────────

    private function insertIssuedInvoice(int $clientId, ?int $projectId, float $net, float $gross): int
    {
        $pdo = $this->db->pdo();
        static $seq = 0;
        $seq++;
        $pdo->prepare(
            "INSERT INTO invoices
                (invoice_type, varsymbol, client_id, project_id, supplier_id, issue_date, tax_date, due_date,
                 currency_id, status, total_without_vat, total_with_vat, created_by)
             VALUES ('invoice', ?, ?, ?, ?, ?, ?, ?, ?, 'issued', ?, ?, ?)"
        )->execute([
            'BATCH-' . self::TAX_DATE . '-' . $seq, $clientId, $projectId, $this->supplierId,
            self::TAX_DATE, self::TAX_DATE, self::TAX_DATE, $this->czkId, $net, $gross, $this->userId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    private function cacheRow(int $clientId): ?array
    {
        $rows = $this->cacheRows($clientId);

        return $rows[0] ?? null;
    }

    /** @return list<array<string,mixed>> */
    private function cacheRows(int $clientId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM client_revenue_cache WHERE client_id = ? AND currency_id = ?'
        );
        $stmt->execute([$clientId, $this->czkId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string,mixed>> */
    private function projectCacheRows(int $projectId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM project_revenue_cache WHERE project_id = ? AND currency_id = ?'
        );
        $stmt->execute([$projectId, $this->czkId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function currency(string $code, string $symbol): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, ?, ?, ?, ?, ?, 2, 1, 1)'
        )->execute([$this->supplierId, $code, $code, $symbol, $code, $code]);

        return (int) $pdo->lastInsertId();
    }

    private function client(string $name, string $ic): int
    {
        $pdo = $this->db->pdo();
        $countryId = (int) ($pdo->query("SELECT id FROM countries WHERE UPPER(iso2) = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($countryId === 0) {
            self::markTestSkipped('Stát CZ není v číselníku zemí.');
        }

        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, ic, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, ?, "Testovací 1", "Praha", "11000", ?, "odberatel@example.test", "cs", ?, 1, 0)'
        )->execute([$this->supplierId, $name, $ic, $countryId, $this->czkId]);

        return (int) $pdo->lastInsertId();
    }

    private function project(int $clientId, string $name): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO projects (client_id, name, status, payment_due_days, hourly_rate, currency_id)
             VALUES (?, ?, "active", 14, 0, ?)'
        )->execute([$clientId, $name, $this->czkId]);

        return (int) $pdo->lastInsertId();
    }
}
