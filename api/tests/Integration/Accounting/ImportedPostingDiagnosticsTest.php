<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Action\Admin\DiagnosticsAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\System\ImportedPostingDiagnostics;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class ImportedPostingDiagnosticsTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private \DI\Container $container;
    private PDO $pdo;
    private ImportedPostingDiagnostics $diagnostics;
    private int $supplier;
    private int $otherSupplier;
    private int $period;
    private int $invoice;
    private int $purchase;
    private int $actor;
    private int $currency;
    private int $client;
    private int $documentSequence = 0;

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $this->pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        $this->pdo->beginTransaction();
        $base = (int) $this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->actor = (int) $this->pdo->query('SELECT id FROM users WHERE is_active=1 ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $base);
        self::assertGreaterThan(0, $this->actor);
        $this->supplier = $this->createIsolatedSupplier($this->pdo, $base);
        $this->otherSupplier = $this->createIsolatedSupplier($this->pdo, $base);
        $this->currency = (int) $this->pdo->query('SELECT default_currency_id FROM supplier WHERE id=' . $this->supplier)->fetchColumn();
        $this->pdo->prepare("INSERT INTO accounting_periods (supplier_id, fiscal_year, starts_on, ends_on) VALUES (?, 2098, '2098-01-01', '2098-12-31')")
            ->execute([$this->supplier]);
        $this->period = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, currency_default_id)
            SELECT id, 'Syntetický partner', 'Test 1', 'Test', '11000', country_id, default_currency_id FROM supplier WHERE id=?")
            ->execute([$this->supplier]);
        $this->client = (int) $this->pdo->lastInsertId();
        $this->invoice = $this->invoice($this->supplier);
        $this->pdo->prepare("INSERT INTO purchase_invoices (supplier_id, vendor_id, currency_id, vendor_invoice_number, vendor_snapshot,
            issue_date, tax_date, due_date, received_at, created_by)
            VALUES (?, ?, ?, 'TEST-DIAGNOSTIKA', '{}', '2098-01-01', '2098-01-01', '2098-01-15', '2098-01-01', ?)")
            ->execute([$this->supplier, $this->client, $this->currency, $this->actor]);
        $this->purchase = (int) $this->pdo->lastInsertId();
        $this->diagnostics = new ImportedPostingDiagnostics($this->db);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
        if (isset($this->db)) $this->db->close();
    }

    public function testReportsOnlyProvenAmbiguitiesWithoutChangingAccounting(): void
    {
        $expected = [];
        foreach (['premier', 'stereo_nx', 'money_s3', 'pohoda', 'abra_flexi'] as $source) {
            $expected[] = $entry = $this->entry();
            $this->map($entry, $source);
            $this->links($entry, $this->note($source));
        }
        // Dvě mapy stejného zápisu a stejného dokladu nejsou další vlastník.
        $this->map($expected[0], 'premier', '-duplicate');
        $single = $this->entry();
        $this->map($single, 'premier');
        $this->link($single, 'invoice', $this->invoice);
        foreach (['bank', 'cash', 'opening'] as $source) {
            $entry = $this->entry($source);
            $this->map($entry, 'premier');
            $this->links($entry);
        }
        $this->links($this->entry()); // Bez importní mapy.
        $wrongNote = $this->entry();
        $this->map($wrongNote, 'premier');
        $this->links($wrongNote, 'Převzato z Pohody');
        $manual = $this->entry();
        $this->map($manual, 'premier');
        $this->links($manual, 'Syntetická ruční souvislost');
        $direct = $this->entry('invoice', $this->invoice);
        $this->map($direct, 'premier');
        $this->links($direct); // Cizí doklad nesplní způsobilost přímého zdroje.
        $otherHeader = $this->invoice($this->otherSupplier);
        foreach ([$otherHeader, 999999999] as $invalidHeader) {
            $entry = $this->entry();
            $this->map($entry, 'premier');
            $this->link($entry, 'invoice', $invalidHeader);
            $this->link($entry, 'purchase_invoice', $this->purchase);
        }
        $this->pdo->prepare('UPDATE journal_entries SET description = NULL WHERE supplier_id = ? AND id = ?')
            ->execute([$this->supplier, $expected[0]]);
        $before = $this->accountingState();
        // Importní mapa může obsahovat staré/cizí cílové ID; nesmí otevřít cizí deník.
        $this->pdo->prepare("INSERT INTO premier_import_map (supplier_id, kind, premier_key, target_id) VALUES (?, 'journal_entry', 'synthetic-foreign-target', ?)")
            ->execute([$this->otherSupplier, $expected[0]]);
        $report = $this->diagnostics->report($this->supplier);
        self::assertSame(5, $report['total']);
        self::assertSame($expected, array_column($report['entries'], 'entry_id'));
        self::assertSame('', $report['entries'][0]['description']);
        foreach ($report['entries'] as $entry) {
            self::assertSame(2, $entry['owner_count']);
            self::assertSame([['invoice', $this->invoice], ['purchase_invoice', $this->purchase]],
                array_map(static fn (array $doc): array => [$doc['doc_type'], $doc['doc_id']], $entry['candidates']));
        }
        self::assertSame($before, $this->accountingState(), 'Diagnostika nesmí měnit zápisy ani jejich vazby.');
        self::assertSame(0, $this->diagnostics->report($this->otherSupplier)['total']);
        self::assertSame([], $this->diagnostics->report(0)['entries']);
    }

    public function testResolvedOwnerIsNotReportedEvenIfInformationalLinksRemain(): void
    {
        $entry = $this->entry();
        $this->map($entry, 'premier');
        $this->links($entry);
        self::assertSame(1, $this->diagnostics->report($this->supplier)['total']);
        $this->pdo->prepare("INSERT INTO journal_document_postings (supplier_id, doc_type, doc_id, entry_id) VALUES (?, 'invoice', ?, ?)")
            ->execute([$this->supplier, $this->invoice, $entry]);
        self::assertSame(0, $this->diagnostics->report($this->supplier)['total']);
    }

    public function testAdminReportExposesOnlySelectedCompanyAndKeepsAccessGuard(): void
    {
        $entry = $this->entry();
        $this->map($entry, 'premier');
        $this->links($entry);
        $action = $this->container->get(DiagnosticsAction::class);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/admin/diagnostics')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->actor, 'role' => 'admin'])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier);
        $response = $action->report($request, new Response());
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('imported_posting_ambiguities', $body);
        self::assertSame($this->supplier, $body['imported_posting_ambiguities']['supplier_id']);
        self::assertSame([$entry], array_column($body['imported_posting_ambiguities']['entries'], 'entry_id'));
        $response = $action->report($request->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->otherSupplier), new Response());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $body['imported_posting_ambiguities']['total']);
        self::assertSame([], $body['imported_posting_ambiguities']['entries']);
        $denied = $action->report($request->withAttribute(AuthMiddleware::ATTR_USER, ['role' => 'accountant']), new Response());
        self::assertSame(403, $denied->getStatusCode());
    }

    public function testCursorPagesEntriesAndBoundsCandidatePreview(): void
    {
        $expected = [];
        for ($i = 0; $i < ImportedPostingDiagnostics::PAGE_SIZE + 2; $i++) {
            $expected[] = $entry = $this->entry();
            $this->map($entry, 'premier');
            $this->links($entry);
        }
        for ($i = 0; $i < ImportedPostingDiagnostics::CANDIDATE_LIMIT + 2; $i++) {
            $this->link($expected[0], 'invoice', $this->invoice($this->supplier));
        }
        $first = $this->diagnostics->report($this->supplier);
        self::assertSame(count($expected), $first['total']);
        self::assertCount(ImportedPostingDiagnostics::PAGE_SIZE, $first['entries']);
        self::assertSame($expected[ImportedPostingDiagnostics::PAGE_SIZE - 1], $first['next_after']);
        self::assertSame(ImportedPostingDiagnostics::CANDIDATE_LIMIT + 4, $first['entries'][0]['owner_count']);
        self::assertCount(ImportedPostingDiagnostics::CANDIDATE_LIMIT, $first['entries'][0]['candidates']);
        $second = $this->diagnostics->report($this->supplier, $first['next_after']);
        self::assertSame(array_slice($expected, ImportedPostingDiagnostics::PAGE_SIZE), array_column($second['entries'], 'entry_id'));
        self::assertNull($second['next_after']);
        self::assertSame(count($expected), $second['total']);
    }

    private function invoice(int $supplier): int
    {
        // Cizí firma potřebuje vlastní číselníkový odkaz, nikoli cizího klienta.
        if ($supplier === $this->otherSupplier) {
            $this->pdo->prepare("INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, currency_default_id)
                SELECT id, 'Syntetický cizí partner', 'Test 1', 'Test', '11000', country_id, default_currency_id FROM supplier WHERE id=?")
                ->execute([$supplier]);
            $client = (int) $this->pdo->lastInsertId();
        } else $client = $this->client;
        $this->pdo->prepare("INSERT INTO invoices (supplier_id, client_id, currency_id, varsymbol, issue_date, tax_date, due_date, created_by)
            VALUES (?, ?, ?, ?, '2098-01-01', '2098-01-01', '2098-01-15', ?)")
            ->execute([$supplier, $client, $this->currency, 'TEST-DIAG-' . ++$this->documentSequence, $this->actor]);
        return (int) $this->pdo->lastInsertId();
    }

    private function entry(string $source = 'manual', ?int $sourceId = null): int
    {
        $this->pdo->prepare("INSERT INTO journal_entries (supplier_id, period_id, entry_date, description, source_type, source_id)
            VALUES (?, ?, '2098-01-01', 'Syntetická diagnostika', ?, ?)")->execute([$this->supplier, $this->period, $source, $sourceId]);
        return (int) $this->pdo->lastInsertId();
    }

    private function map(int $entry, string $source, string $suffix = ''): void
    {
        $key = 'synthetic-' . $entry . $suffix;
        if ($source === 'stereo_nx') {
            $this->pdo->prepare("INSERT INTO stereo_nx_import_map (supplier_id, source_ico, source_company_index, kind, source_key, source_hash, target_id)
                VALUES (?, '00000000', 1, 'accounting_journal', ?, ?, ?)")->execute([$this->supplier, $key, str_repeat('0', 64), $entry]);
        } elseif ($source === 'abra_flexi') {
            $this->pdo->prepare("INSERT INTO abra_flexi_import_map (supplier_id, kind, abra_key, target_type, target_id, source_hash)
                VALUES (?, 'ucetni-denik', ?, 'journal_entry', ?, ?)")->execute([$this->supplier, $key, $entry, str_repeat('0', 64)]);
        } else {
            $keyColumn = ['premier' => 'premier_key', 'money_s3' => 'money_key', 'pohoda' => 'pohoda_key'][$source];
            $this->pdo->prepare("INSERT INTO {$source}_import_map (supplier_id, kind, {$keyColumn}, target_id) VALUES (?, 'journal_entry', ?, ?)")
                ->execute([$this->supplier, $key, $entry]);
        }
    }

    private function note(string $source): string
    {
        return 'Převzato z ' . ['premier' => 'PREMIER', 'stereo_nx' => 'Stereo NX', 'money_s3' => 'Money S3', 'pohoda' => 'Pohody', 'abra_flexi' => 'ABRA Flexi'][$source];
    }

    private function links(int $entry, string $note = 'Převzato z PREMIER'): void
    {
        $this->link($entry, 'invoice', $this->invoice, $note);
        $this->link($entry, 'purchase_invoice', $this->purchase, $note);
    }

    private function link(int $entry, string $type, int $doc, string $note = 'Převzato z PREMIER'): void
    {
        $this->pdo->prepare('INSERT INTO journal_entry_document_links (supplier_id, entry_id, doc_type, doc_id, note) VALUES (?, ?, ?, ?, ?)')
            ->execute([$this->supplier, $entry, $type, $doc, $note]);
    }

    private function accountingState(): array
    {
        $result = [];
        foreach (['journal_entries', 'journal_entry_lines', 'journal_entry_document_links', 'journal_document_postings'] as $table) {
            $stmt = $this->pdo->prepare("SELECT * FROM {$table} WHERE supplier_id = ? ORDER BY id");
            $stmt->execute([$this->supplier]);
            $result[$table] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        return $result;
    }
}
