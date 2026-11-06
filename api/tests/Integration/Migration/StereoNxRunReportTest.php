<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Action\Admin\Import\StereoNxMigrationAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Migration\StereoNx\StereoNxImportJobService;
use MyInvoice\Service\Migration\StereoNx\StereoNxRunReportStore;
use MyInvoice\Service\Migration\StereoNx\StereoNxUploads;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

#[Group('integration')]
final class StereoNxRunReportTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private StereoNxMigrationAction $action;
    private ImportJobRepository $jobs;
    private StereoNxRunReportStore $store;
    private int $supplierId;
    private int $userId;
    private string $root;
    private string|false $previous;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php') && !getenv('MYINVOICE_DB_NAME')) {
            self::markTestSkipped('Integration database is not configured.');
        }
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->action = $container->get(StereoNxMigrationAction::class);
        $this->jobs = $container->get(ImportJobRepository::class);
        $this->store = $container->get(StereoNxRunReportStore::class);
        $pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
        $source = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        self::assertGreaterThan(0, $source);
        self::assertGreaterThan(0, $this->userId);
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare('UPDATE supplier SET company_name = ? WHERE id = ?')
            ->execute(['Syntetická cílová společnost', $this->supplierId]);
        $this->previous = getenv('MYINVOICE_DATA_DIR');
        $this->root = sys_get_temp_dir() . '/stereo-protocol-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        putenv('MYINVOICE_DATA_DIR=' . $this->root);
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            if ($this->previous === false) putenv('MYINVOICE_DATA_DIR');
            else putenv('MYINVOICE_DATA_DIR=' . $this->previous);
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) ($file->isDir() && !$file->isLink()) ? rmdir($file->getPathname()) : unlink($file->getPathname());
            rmdir($this->root);
        }
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
            $this->db->close();
        }
    }

    public function testFailedProtocolCanBeDownloadedAfterUploadDeletionWithoutExposingJobParams(): void
    {
        [$id, $token] = $this->job();
        $report = ['ok' => false, 'database_writes' => false,
            'errors' => [['code' => 'synthetic_error', 'message' => 'Syntetická chyba kontroly']],
            'warnings' => [], 'counts' => ['assets_source' => 1, 'assets_ready' => 0, 'assets_skipped' => 1]];
        $stored = $this->save($id, $report);
        $this->jobs->markFailed($id, 'Kontrola našla chyby.');
        $this->upload($token);
        StereoNxUploads::delete($this->supplierId, $token);
        self::assertDirectoryDoesNotExist(StereoNxUploads::dir($this->supplierId, $token));
        self::assertStringNotContainsString('Synthetic-encrypted-secret', (string) file_get_contents(RuntimePaths::storage($stored['path'])));
        self::assertArrayNotHasKey('params', $this->store->read($this->supplierId, $id, $stored['path']));
        $json = $this->call('result', $id, $token);
        self::assertSame(200, $json->getStatusCode(), (string) $json->getBody());
        self::assertSame(['mode' => 'dry_run', 'report' => $report], $this->json($json));
        foreach (['cs', 'en'] as $language) {
            $pdf = $this->call('resultPdf', $id, $token, $this->request()->withQueryParams(['lang' => $language]));
            self::assertSame(200, $pdf->getStatusCode(), (string) $pdf->getBody());
            self::assertSame('application/pdf', $pdf->getHeaderLine('Content-Type'));
            self::assertSame('private, no-store', $pdf->getHeaderLine('Cache-Control'));
            self::assertSame('attachment; filename="stereo-nx-protocol-' . $id . '.pdf"', $pdf->getHeaderLine('Content-Disposition'));
            self::assertStringStartsWith('%PDF-', (string) $pdf->getBody());
        }
    }

    public function testJsonAndPdfEnforcePermissionTenantSourceTokenAndJobOwner(): void
    {
        [$id, $token] = $this->job();
        $this->save($id, ['ok' => true]);
        foreach (['result', 'resultPdf'] as $method) {
            self::assertSame(403, $this->call($method, $id, $token,
                $this->request()->withAttribute('auth.effective_role', new EffectiveRole(9, 'Limited', 'staff', false, [])))->getStatusCode());
            self::assertSame(404, $this->call($method, $id, $token,
                $this->request()->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId + 999999))->getStatusCode());
            self::assertSame(404, $this->call($method, $id, $token,
                $this->request()->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId + 999999]))->getStatusCode());
            self::assertSame(404, $this->call($method, $id, str_repeat('b', 32))->getStatusCode());
            $this->db->pdo()->prepare('UPDATE import_jobs SET source = ? WHERE id = ?')->execute(['file_import', $id]);
            self::assertSame(404, $this->call($method, $id, $token)->getStatusCode());
            $this->db->pdo()->prepare('UPDATE import_jobs SET source = ? WHERE id = ?')->execute([StereoNxImportJobService::SOURCE, $id]);
        }
    }

    public function testMissingAndInvalidCanonicalReportsReturnConflict(): void
    {
        [$id, $token] = $this->job();
        foreach (['result', 'resultPdf'] as $method) {
            $response = $this->call($method, $id, $token);
            self::assertSame(409, $response->getStatusCode());
            self::assertSame('result_missing', $this->json($response)['error']['code']);
        }
        $stored = $this->save($id, ['ok' => true]);
        file_put_contents(RuntimePaths::storage($stored['path']), '{invalid');
        foreach (['result', 'resultPdf'] as $method) self::assertSame(409, $this->call($method, $id, $token)->getStatusCode());
        $this->jobs->setResult($id, 'import-jobs/' . ($this->supplierId + 1) . '/reports/stereo-nx-' . $id . '.json', 'report.json', 1, 'application/json');
        foreach (['result', 'resultPdf'] as $method) self::assertSame(409, $this->call($method, $id, $token)->getStatusCode());
    }

    public function testLegacyUploadReportsRemainReadableOnlyToOriginalUploadOwner(): void
    {
        [$id, $token] = $this->job();
        $this->upload($token);
        $report = ['ok' => true, 'warnings' => ['Syntetické upozornění']];
        StereoNxUploads::saveResult($this->supplierId, $token, $id, $report);
        $state = StereoNxUploads::state($this->supplierId, $token);
        $state['uploaded_by'] = $this->userId + 999999;
        StereoNxUploads::save($this->supplierId, $token, $state);
        foreach (['result', 'resultPdf'] as $method) self::assertSame(404, $this->call($method, $id, $token)->getStatusCode());
        self::assertNull($this->jobs->find($id, $this->supplierId)['result_path']);
        $state['uploaded_by'] = $this->userId;
        StereoNxUploads::save($this->supplierId, $token, $state);
        self::assertSame(200, $this->call('result', $id, $token)->getStatusCode());
        $job = $this->jobs->find($id, $this->supplierId);
        self::assertSame('import-jobs/' . $this->supplierId . '/reports/stereo-nx-' . $id . '.json', $job['result_path']);
        self::assertSame('application/json', $job['result_mime']);
        self::assertSame($report, $this->store->read($this->supplierId, $id, $job['result_path']));
        StereoNxUploads::delete($this->supplierId, $token);
        // First authorized access preserves old reports independently of the input upload.
        foreach (['result', 'resultPdf'] as $method) self::assertSame(200, $this->call($method, $id, $token)->getStatusCode());
    }

    /** @return array{int,string} */
    private function job(): array
    {
        $token = str_repeat('a', 32);
        $id = $this->jobs->create($this->supplierId, StereoNxImportJobService::SOURCE,
            ['token' => $token, 'mode' => 'dry_run', 'password_enc' => 'Synthetic-encrypted-secret'], $this->userId);
        return [$id, $token];
    }

    private function save(int $id, array $report): array
    {
        $stored = $this->store->save($this->supplierId, $id, $report);
        $this->jobs->setResult($id, $stored['path'], $stored['name'], $stored['size'], $stored['mime']);
        return $stored;
    }

    private function upload(string $token): void
    {
        mkdir(StereoNxUploads::dir($this->supplierId, $token), 0700, true);
        StereoNxUploads::save($this->supplierId, $token, ['uploaded_by' => $this->userId, 'status' => 'ready']);
    }

    private function request(): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', '/api/admin/imports/stereo-nx')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId])
            ->withAttribute('auth.effective_role', new EffectiveRole(9, 'Test', 'staff', true, ['utilities.import' => 2]));
    }

    private function call(string $method, int $id, string $token, ?ServerRequestInterface $request = null): ResponseInterface
    {
        return $this->action->{$method}($request ?? $this->request(), (new ResponseFactory())->createResponse(),
            ['token' => $token, 'id' => (string) $id]);
    }

    private function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}
