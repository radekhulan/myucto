<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Action\Invoice\InvoiceOriginalPdfAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\IssuedInvoiceOriginalPdfStore;
use MyInvoice\Service\IpMatcher;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;
use Slim\Psr7\UploadedFile;

/**
 * Ruční nahrání originálu PDF k vydané faktuře (POST/DELETE /api/invoices/{id}/imported-pdf)
 * a náhled hromadného párování podle názvu souboru. Úložiště míří do dočasného adresáře,
 * DB běží v transakci s rollbackem.
 */
#[Group('integration')]
final class InvoiceOriginalPdfUploadTest extends TestCase
{
    private const PDF_A = "%PDF-1.4\n1 0 obj<< /Type /Catalog >>endobj\ntrailer<< /Root 1 0 R >>\n%%EOF\n";
    private const PDF_B = "%PDF-1.7\n1 0 obj<< /Type /Catalog /Lang (cs) >>endobj\ntrailer<< /Root 1 0 R >>\n%%EOF\n";

    private Connection $db;
    private IssuedInvoiceOriginalPdfStore $store;
    private InvoiceOriginalPdfAction $action;
    private string $archiveRoot = '';
    private int $supplierId = 0;
    private int $userId = 0;
    private int $invoiceId = 0;
    private string $varsymbol = '';
    private bool $inTx = false;
    /** @var list<string> */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);

        $this->archiveRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mi-original-pdf-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->archiveRoot, 0777, true);
        $this->store = new IssuedInvoiceOriginalPdfStore(
            $this->db,
            new Config(['invoice' => ['import_archive_storage' => $this->archiveRoot]]),
        );
        $this->action = new InvoiceOriginalPdfAction(
            $container->get(InvoiceRepository::class),
            $this->store,
            $this->db,
            $container->get(ActivityLogger::class),
            $container->get(IpMatcher::class),
        );

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        self::assertGreaterThan(0, $this->supplierId, 'Testovací DB musí mít firmu.');
        self::assertGreaterThan(0, $this->userId, 'Testovací DB musí mít uživatele.');

        $pdo->beginTransaction();
        $this->inTx = true;

        $currencyId = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "Syntetický odběratel", "Testovací 1", "Praha", "11000",
                     (SELECT id FROM countries WHERE iso2 = "CZ" LIMIT 1), "synthetic@example.invalid", "cs", ?, 1, 0)'
        )->execute([$this->supplierId, $currencyId]);
        $clientId = (int) $pdo->lastInsertId();

        $this->varsymbol = 'OP' . random_int(10000000, 99999999);
        $pdo->prepare(
            "INSERT INTO invoices (supplier_id, client_id, invoice_type, issue_date, due_date, currency_id, created_by, status, varsymbol)
             VALUES (?, ?, 'invoice', '2099-06-01', '2099-06-15', ?, ?, 'draft', ?)"
        )->execute([$this->supplierId, $clientId, $currencyId, $this->userId, $this->varsymbol]);
        $this->invoiceId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        if ($this->archiveRoot !== '') {
            $this->rrmdir($this->archiveRoot);
        }
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testPdfUploadStoresOriginalAndLogsActivity(): void
    {
        $before = $this->invoiceRow();

        $res = $this->upload($this->supplierId, self::PDF_A, 'Faktura OP.pdf');

        self::assertSame(200, $res['status'], json_encode($res['body']));
        $row = $this->invoiceRow();
        self::assertSame($res['body']['imported_pdf_path'], $row['imported_pdf_path']);
        self::assertSame(hash('sha256', self::PDF_A), $row['imported_pdf_hash']);
        self::assertSame(strlen(self::PDF_A), (int) $row['imported_pdf_size_bytes']);
        self::assertSame('Faktura OP.pdf', $row['imported_pdf_original_name']);
        self::assertSame(self::PDF_A, file_get_contents($this->absolute((string) $row['imported_pdf_path'])));
        // Originál nemění stav dokladu.
        self::assertSame($before['status'], $row['status']);

        $logged = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM activity_log WHERE action = 'invoice.imported_pdf_uploaded' AND entity_id = {$this->invoiceId}"
        )->fetchColumn();
        self::assertSame(1, $logged);
    }

    public function testNonPdfIsRejectedWith422(): void
    {
        $res = $this->upload($this->supplierId, "<html><body>není to PDF</body></html>", 'faktura.pdf');

        self::assertSame(422, $res['status']);
        self::assertSame('invalid_pdf', $res['body']['error']['code']);
        self::assertNull($this->invoiceRow()['imported_pdf_path']);
    }

    public function testInvoiceOfOtherCompanyIs404(): void
    {
        $res = $this->upload($this->supplierId + 999999, self::PDF_A, 'a.pdf');
        self::assertSame(404, $res['status']);
        self::assertNull($this->invoiceRow()['imported_pdf_path']);

        $del = $this->decode($this->action->remove(
            $this->request('DELETE', $this->supplierId + 999999), new Psr7Response(), ['id' => (string) $this->invoiceId],
        ));
        self::assertSame(404, $del['status']);
    }

    public function testReplacementDeletesPreviousFileOnlyAfterStoringNewOne(): void
    {
        $first = $this->upload($this->supplierId, self::PDF_A, 'a.pdf');
        self::assertSame(200, $first['status']);
        $oldPath = $this->absolute($first['body']['imported_pdf_path']);
        self::assertFileExists($oldPath);

        $second = $this->upload($this->supplierId, self::PDF_B, 'b.pdf');

        self::assertSame(200, $second['status']);
        self::assertTrue($second['body']['replaced']);
        self::assertFileDoesNotExist($oldPath);
        $newPath = $this->absolute($second['body']['imported_pdf_path']);
        self::assertSame(self::PDF_B, file_get_contents($newPath));
        self::assertSame('b.pdf', $this->invoiceRow()['imported_pdf_original_name']);
    }

    public function testReplacementKeepsFileSharedWithAnotherInvoice(): void
    {
        $first = $this->upload($this->supplierId, self::PDF_A, 'a.pdf');
        $sharedPath = (string) $first['body']['imported_pdf_path'];
        // Druhá faktura firmy ukazuje na tentýž soubor (content-addressed dedup importu).
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO invoices (supplier_id, client_id, invoice_type, issue_date, due_date, currency_id, created_by, status, imported_pdf_path)
             SELECT supplier_id, client_id, invoice_type, issue_date, due_date, currency_id, created_by, 'draft', ? FROM invoices WHERE id = ?"
        )->execute([$sharedPath, $this->invoiceId]);

        $this->upload($this->supplierId, self::PDF_B, 'b.pdf');

        self::assertFileExists($this->absolute($sharedPath));
    }

    public function testRemoveClearsMetadataAndDeletesFile(): void
    {
        $first = $this->upload($this->supplierId, self::PDF_A, 'a.pdf');
        $path = $this->absolute($first['body']['imported_pdf_path']);

        $res = $this->decode($this->action->remove(
            $this->request('DELETE', $this->supplierId), new Psr7Response(), ['id' => (string) $this->invoiceId],
        ));

        self::assertSame(200, $res['status']);
        $row = $this->invoiceRow();
        self::assertNull($row['imported_pdf_path']);
        self::assertNull($row['imported_pdf_hash']);
        self::assertFileDoesNotExist($path);
    }

    public function testMatchPairsFilenameWithInvoiceOfCurrentCompanyOnly(): void
    {
        $files = ['Faktura_' . strtolower($this->varsymbol) . '.pdf', 'neznamy-doklad.pdf'];

        $res = $this->decode($this->action->match(
            $this->request('POST', $this->supplierId)->withParsedBody(['files' => $files]), new Psr7Response(),
        ));

        self::assertSame(200, $res['status']);
        self::assertSame('matched', $res['body']['items'][0]['status']);
        self::assertSame($this->invoiceId, $res['body']['items'][0]['invoice']['id']);
        self::assertSame('Syntetický odběratel', $res['body']['items'][0]['invoice']['client_name']);
        self::assertSame('unmatched', $res['body']['items'][1]['status']);

        $foreign = $this->decode($this->action->match(
            $this->request('POST', $this->supplierId + 999999)->withParsedBody(['files' => $files]), new Psr7Response(),
        ));
        self::assertSame('unmatched', $foreign['body']['items'][0]['status']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function invoiceRow(): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT status, imported_pdf_path, imported_pdf_hash, imported_pdf_size_bytes, imported_pdf_original_name
               FROM invoices WHERE id = ?'
        );
        $stmt->execute([$this->invoiceId]);
        return (array) $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    private function absolute(string $relPath): string
    {
        return $this->archiveRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private function upload(int $supplierId, string $content, string $filename): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'opdf_');
        file_put_contents($tmp, $content);
        $this->tmpFiles[] = $tmp;
        $file = new UploadedFile($tmp, $filename, 'application/pdf', strlen($content), UPLOAD_ERR_OK);

        $req = $this->request('POST', $supplierId)->withUploadedFiles(['file' => $file]);
        return $this->decode($this->action->upload($req, new Psr7Response(), ['id' => (string) $this->invoiceId]));
    }

    private function request(string $method, int $supplierId): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, '/api/invoices/' . $this->invoiceId . '/imported-pdf')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private function decode(\Psr\Http\Message\ResponseInterface $resp): array
    {
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $p = $dir . DIRECTORY_SEPARATOR . $e;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
