<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration\Shared;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class DocumentPostingBackfillTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const MIGRATION = '2074_journal_document_postings.sql';
    private Connection $db;
    private PDO $pdo;
    private string $database;
    private array $migration = [];
    /** @var list<int> */
    private array $suppliers = [];
    private int $supplier;
    private int $period;

    protected function setUp(): void
    {
        $this->db = Bootstrap::buildContainer()->get(Connection::class);
        $this->pdo = $this->db->pdo();
        $this->database = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        self::assertStringEndsWith('_test', $this->database);
        $stmt = $this->pdo->prepare('SELECT * FROM migrations WHERE filename=?');
        $stmt->execute([self::MIGRATION]);
        $migration = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertNotFalse($migration, 'Test vyžaduje schéma již postavené oficiálním migrátorem.');
        $this->migration = $migration;
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) return;
        try {
            foreach ($this->suppliers as $supplier) {
                foreach (['journal_entries', 'accounting_periods', 'purchase_invoices', 'invoices', 'clients', 'supplier_vat_status_history', 'supplier'] as $table) {
                    $column = $table === 'supplier' ? 'id' : 'supplier_id';
                    $this->pdo->prepare('DELETE FROM `' . $table . '` WHERE `' . $column . '`=?')->execute([$supplier]);
                }
            }
        } finally {
            // Selhání procesu nesmí nechat původně aplikovanou migraci jako čekající.
            if ($this->migration !== []) {
                $this->pdo->prepare('INSERT IGNORE INTO migrations (filename, applied_at, duration_ms) VALUES (?, ?, ?)')
                    ->execute([$this->migration['filename'], $this->migration['applied_at'], $this->migration['duration_ms']]);
            }
            $this->db->close();
        }
    }

    public function testBackfillOwnsOnlyProvenPrescriptionsAndKeepsIdsOnRepeat(): void
    {
        $base = (int) $this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $actor = (int) $this->pdo->query('SELECT id FROM users WHERE is_active=1 ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $base);
        self::assertGreaterThan(0, $actor);
        $this->supplier = $this->suppliers[] = $this->createIsolatedSupplier($this->pdo, $base);
        $otherSupplier = $this->suppliers[] = $this->createIsolatedSupplier($this->pdo, $base);
        $this->pdo->prepare("INSERT INTO accounting_periods (supplier_id, fiscal_year, starts_on, ends_on) VALUES (?, 2098, '2098-01-01', '2098-12-31')")
            ->execute([$this->supplier]);
        $this->period = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, currency_default_id)
            SELECT id, 'Syntetický partner', 'Test 1', 'Test', '11000', country_id, default_currency_id FROM supplier WHERE id=?")
            ->execute([$this->supplier]);
        $client = (int) $this->pdo->lastInsertId();
        $currency = (int) $this->pdo->query('SELECT default_currency_id FROM supplier WHERE id=' . $this->supplier)->fetchColumn();
        $this->pdo->prepare("INSERT INTO invoices (supplier_id, client_id, currency_id, varsymbol, issue_date, tax_date, due_date, created_by)
            VALUES (?, ?, ?, 'TEST-BACKFILL', '2098-01-01', '2098-01-01', '2098-01-15', ?)")
            ->execute([$this->supplier, $client, $currency, $actor]);
        $invoice = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO purchase_invoices (supplier_id, vendor_id, currency_id, vendor_invoice_number, vendor_snapshot,
            issue_date, tax_date, due_date, received_at, created_by)
            VALUES (?, ?, ?, 'TEST-BACKFILL', '{}', '2098-01-01', '2098-01-01', '2098-01-15', '2098-01-01', ?)")
            ->execute([$this->supplier, $client, $currency, $actor]);
        $purchase = (int) $this->pdo->lastInsertId();

        $first = $this->entry('invoice', $invoice);
        $this->link($first, 'invoice', $invoice);
        $second = $this->entry('manual');
        $this->link($second, 'invoice', $invoice);
        $third = $this->entry('manual');
        $this->link($third, 'purchase_invoice', $purchase);
        $ambiguous = $this->entry('manual');
        $this->link($ambiguous, 'invoice', $invoice);
        $this->link($ambiguous, 'purchase_invoice', $purchase);
        foreach (['bank', 'cash', 'opening'] as $source) {
            $this->link($this->entry($source), 'invoice', $invoice);
        }
        $this->link($this->entry('manual', null, false), 'invoice', $invoice);
        $this->link($this->entry('manual'), 'invoice', $invoice, 'Syntetická ruční souvislost');
        $this->link($this->entry('manual'), 'invoice', $invoice, 'Převzato z Pohody');
        $this->link($this->entry('manual'), 'invoice', 999999999);

        $this->runMigration();
        $rows = $this->postings();
        self::assertSame([
            ['invoice', $invoice, $first],
            ['invoice', $invoice, $second],
            ['purchase_invoice', $purchase, $third],
        ], array_map(static fn (array $row): array => [$row['doc_type'], (int) $row['doc_id'], (int) $row['entry_id']], $rows),
            'Pouze doložené vlastní předpisy: ne úhrady, opening, nejednoznačné nebo ruční souvislosti.');
        $this->runMigration();
        self::assertSame($rows, $this->postings(), 'Opakovaná migrace musí zachovat všechny vazby včetně stabilních ID.');

        try {
            $this->pdo->prepare("INSERT INTO journal_document_postings (supplier_id, doc_type, doc_id, entry_id) VALUES (?, 'invoice', ?, ?)")
                ->execute([$otherSupplier, $invoice, $first]);
            self::fail('Složený FK nesmí připustit zápis jiné firmy.');
        } catch (PDOException $e) {
            self::assertSame('23000', $e->getCode());
        }
    }

    private function entry(string $source, ?int $id = null, bool $mapped = true): int
    {
        $this->pdo->prepare("INSERT INTO journal_entries (supplier_id, period_id, entry_date, description, source_type, source_id)
            VALUES (?, ?, '2098-01-01', 'Syntetický backfill', ?, ?)")->execute([$this->supplier, $this->period, $source, $id]);
        $entry = (int) $this->pdo->lastInsertId();
        if ($mapped) {
            $this->pdo->prepare("INSERT INTO premier_import_map (supplier_id, kind, premier_key, target_id) VALUES (?, 'journal_entry', ?, ?)")
                ->execute([$this->supplier, 'synthetic-' . $entry, $entry]);
        }
        return $entry;
    }

    private function link(int $entry, string $type, int $id, string $note = 'Převzato z PREMIER'): void
    {
        $this->pdo->prepare('INSERT INTO journal_entry_document_links (supplier_id, entry_id, doc_type, doc_id, note) VALUES (?, ?, ?, ?, ?)')
            ->execute([$this->supplier, $entry, $type, $id, $note]);
    }

    /** @return list<array<string,mixed>> */
    private function postings(): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM journal_document_postings WHERE supplier_id=? ORDER BY entry_id');
        $stmt->execute([$this->supplier]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function runMigration(): void
    {
        $this->pdo->prepare('DELETE FROM migrations WHERE filename=?')->execute([self::MIGRATION]);
        $root = Bootstrap::rootDir();
        $env = getenv();
        $env['MYINVOICE_DB_NAME'] = $this->database;
        $process = proc_open([PHP_BINARY, $root . '/api/bin/migrate.php', '--only=' . self::MIGRATION, '--no-backfills', '--no-analyze'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $output);
    }
}
