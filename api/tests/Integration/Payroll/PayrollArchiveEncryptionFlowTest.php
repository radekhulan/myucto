<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Admin\DiagnosticsAction;
use MyInvoice\Action\Admin\PayrollArchiveEncryptionAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\Payroll\Document\PayrollArchiveDiagnostics;
use MyInvoice\Service\Payroll\Document\PayrollDocumentStorage;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Celý tok z Diagnostiky: kontrola ukáže nešifrované dokumenty, správce
 * spustí přešifrování (náhled, pak potvrzený zápis), kontrola pak ukáže nulu
 * a dokument jde dál vydat, už ze šifrované kopie.
 */
#[Group('integration')]
final class PayrollArchiveEncryptionFlowTest extends TestCase
{
    use IsolatedSupplierTrait;

    private ContainerInterface $container;
    private Connection $db;
    private int $supplierId;
    private int $userId;
    private int $employeeId;
    private string|false $previousDataDir;
    private string $dataDir;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            self::markTestSkipped('cfg.php neexistuje - test vyžaduje DB.');
        }
        $this->previousDataDir = getenv('MYINVOICE_DATA_DIR');
        $this->dataDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'myucto-archiv-flow-' . bin2hex(random_bytes(6));
        mkdir($this->dataDir, 0750, true);
        putenv('MYINVOICE_DATA_DIR=' . $this->dataDir);

        $this->container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $this->container->get(Connection::class);
        foreach (['payroll_document_data_keys', 'payroll_generated_documents', 'payroll_annual_document_revisions'] as $table) {
            if (!$this->db->hasTable($table)) {
                self::markTestSkipped("Chybí tabulka {$table}.");
            }
        }
        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            self::markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        if (isset($this->dataDir)) {
            $this->removeDirectory($this->dataDir);
            $this->previousDataDir === false
                ? putenv('MYINVOICE_DATA_DIR')
                : putenv('MYINVOICE_DATA_DIR=' . $this->previousDataDir);
        }
    }

    public function testDiagnosticsShowsCountAdminEncryptsAndDocumentStaysReadable(): void
    {
        $bytes = '%PDF-1.4 syntetický mzdový list';
        $key = $this->writeLegacy($bytes);
        $this->seedDocument($key);

        $report = $this->json($this->container->get(DiagnosticsAction::class)->report(
            $this->request('GET', '/api/admin/diagnostics'),
            new Response(),
        ));
        $check = $this->findCheck($report['checks'], PayrollArchiveDiagnostics::CHECK_ARCHIVE);
        self::assertSame('warn', $check['status']);
        self::assertSame('1', $check['actual']);
        self::assertCount(1, $check['meta']['suppliers']);
        self::assertSame($this->supplierId, $check['meta']['suppliers'][0]['supplier_id']);
        self::assertSame(1, $check['meta']['suppliers'][0]['files']);
        self::assertIsString($check['meta']['suppliers'][0]['name']);
        self::assertContains($report['summary']['status'], ['warn', 'fail']);

        $action = $this->container->get(PayrollArchiveEncryptionAction::class);

        $preview = $this->json($action->reencrypt($this->request('POST', '/x', ['dry_run' => true]), new Response()));
        self::assertSame(1, $preview['counts']['would_encrypt']);
        self::assertFileExists($this->legacyPath($key));

        $unconfirmed = $action->reencrypt($this->request('POST', '/x', []), new Response());
        self::assertSame(422, $unconfirmed->getStatusCode());
        self::assertFileExists($this->legacyPath($key));

        $done = $this->json($action->reencrypt($this->request('POST', '/x', ['confirm' => true]), new Response()));
        self::assertSame(1, $done['counts']['encrypted'], json_encode($done));
        self::assertSame(0, $done['remaining']);
        self::assertFileDoesNotExist($this->legacyPath($key));

        $after = $this->container->get(PayrollArchiveDiagnostics::class)->checks();
        $check = $this->findCheck($after, PayrollArchiveDiagnostics::CHECK_ARCHIVE);
        self::assertSame('ok', $check['status']);
        self::assertSame('0', $check['actual']);

        self::assertSame(
            $bytes,
            $this->container->get(PayrollDocumentStorage::class)->readVerified($this->supplierId, $key, $this->employeeId),
        );
        $logged = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM activity_log WHERE action = 'payroll.archive.reencrypt' AND user_id = ?",
        );
        $logged->execute([$this->userId]);
        self::assertGreaterThanOrEqual(1, (int) $logged->fetchColumn());
    }

    public function testOnlySuperadminMayRunAndPurgeNeedsItsOwnConfirmation(): void
    {
        $action = $this->container->get(PayrollArchiveEncryptionAction::class);

        $forbidden = $action->reencrypt(
            $this->request('POST', '/x', ['confirm' => true], 'accountant'),
            new Response(),
        );
        self::assertSame(403, $forbidden->getStatusCode());
        self::assertSame(403, $action->rewrap($this->request('POST', '/x', ['confirm' => true], 'accountant'), new Response())->getStatusCode());

        $purge = $action->reencrypt(
            $this->request('POST', '/x', ['confirm' => true, 'purge_erased' => true]),
            new Response(),
        );
        self::assertSame(422, $purge->getStatusCode());
        self::assertSame('purge_confirmation_required', $this->json($purge)['error']['code']);
    }

    public function testRewrapWithoutRotationReportsNothingToDo(): void
    {
        $action = $this->container->get(PayrollArchiveEncryptionAction::class);

        self::assertSame(422, $action->rewrap($this->request('POST', '/x', []), new Response())->getStatusCode());
        $result = $this->json($action->rewrap($this->request('POST', '/x', ['dry_run' => true]), new Response()));
        self::assertSame(0, $result['failed']);
        self::assertSame(0, $result['unknown']);
    }

    /** @param array<string,mixed> $body */
    private function request(string $method, string $path, array $body = [], string $role = 'admin'): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, $path)
            ->withParsedBody($body)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role]);
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded, $body);

        return $decoded;
    }

    /**
     * @param list<array<string,mixed>> $checks
     * @return array<string,mixed>
     */
    private function findCheck(array $checks, string $id): array
    {
        foreach ($checks as $check) {
            if ($check['id'] === $id) {
                return $check;
            }
        }
        self::fail("Kontrola {$id} v reportu chybí.");
    }

    private function seedDocument(string $storageKey): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetický zaměstnanec", "employee", 1)',
        )->execute([$this->supplierId]);
        $this->employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_annual_document_revisions
                (supplier_id, employee_id, tax_year, purpose, revision_no,
                 snapshot_ciphertext, snapshot_hash, source_manifest_json,
                 source_manifest_hash, approved_at)
             VALUES (?, ?, 2019, "payroll_sheet", 1, "", ?, "{}", ?, "2019-12-31 12:00:00")',
        )->execute([$this->supplierId, $this->employeeId, str_repeat('2', 64), str_repeat('e', 64)]);
        $annualId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_generated_documents
                (supplier_id, run_id, revision_id, annual_revision_id, employee_id,
                 document_kind, document_revision_no, revision_snapshot_hash,
                 source_snapshot_hash, template_version, renderer_version,
                 file_sha256, size_bytes, mime_type, storage_key,
                 suggested_filename, idempotency_key_hash)
             VALUES (?, NULL, NULL, ?, ?, "payroll_sheet", 1, ?, ?, "t1", "r1", ?, 1024,
                     "application/pdf", ?, "mzdovy-list.pdf", UNHEX(?))',
        )->execute([
            $this->supplierId,
            $annualId,
            $this->employeeId,
            str_repeat('2', 64),
            str_repeat('3', 64),
            $storageKey,
            $storageKey,
            hash('sha256', 'idem-flow'),
        ]);
    }

    private function writeLegacy(string $bytes): string
    {
        $key = hash('sha256', $bytes);
        $path = $this->legacyPath($key);
        mkdir(dirname($path), 0750, true);
        file_put_contents($path, $bytes);

        return $key;
    }

    private function legacyPath(string $key): string
    {
        return PayrollDocumentStorage::baseDir($this->supplierId) . '/' . substr($key, 0, 2) . '/' . $key;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
