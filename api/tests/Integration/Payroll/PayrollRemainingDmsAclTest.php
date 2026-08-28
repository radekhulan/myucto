<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Document\DocumentFileAction;
use MyInvoice\Action\Document\DocumentsAction;
use MyInvoice\Action\Document\FoldersAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\DocumentFileRepository;
use MyInvoice\Repository\DocumentFolderRepository;
use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Repository\DocumentViewerContext;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Document\DocumentStorage;
use MyInvoice\Service\Document\DocumentViewerResolver;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Support\PayrollPaymentEvidenceTrait;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollRemainingDmsAclTest extends TestCase
{
    use IsolatedSupplierTrait;
    use PayrollPaymentEvidenceTrait;

    private Connection $db;
    private PDO $pdo;
    private DocumentRepository $documents;
    private DocumentFileRepository $files;
    private DocumentFolderRepository $folders;
    private DocumentsAction $documentsAction;
    private DocumentFileAction $fileAction;
    private FoldersAction $foldersAction;
    private DocumentStorage $storage;
    private int $supplierId;
    private int $userId;
    /** @var list<string> */
    private array $storedPaths = [];

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer();
        if ($container === null) {
            throw new \RuntimeException('DI kontejner není dostupný.');
        }
        $db = $container->get(Connection::class);
        $documents = $container->get(DocumentRepository::class);
        $files = $container->get(DocumentFileRepository::class);
        $folders = $container->get(DocumentFolderRepository::class);
        $documentsAction = $container->get(DocumentsAction::class);
        $fileAction = $container->get(DocumentFileAction::class);
        $foldersAction = $container->get(FoldersAction::class);
        $storage = $container->get(DocumentStorage::class);
        if (
            !$db instanceof Connection
            || !$documents instanceof DocumentRepository
            || !$files instanceof DocumentFileRepository
            || !$folders instanceof DocumentFolderRepository
            || !$documentsAction instanceof DocumentsAction
            || !$fileAction instanceof DocumentFileAction
            || !$foldersAction instanceof FoldersAction
            || !$storage instanceof DocumentStorage
        ) {
            throw new \RuntimeException('Služby Dokumentů nejsou dostupné.');
        }
        $this->db = $db;
        $this->documents = $documents;
        $this->files = $files;
        $this->folders = $folders;
        $this->documentsAction = $documentsAction;
        $this->fileAction = $fileAction;
        $this->foldersAction = $foldersAction;
        $this->storage = $storage;
        $this->pdo = $this->db->pdo();
        foreach (['payroll_enforcement_xmlzam_requests', 'payroll_document_dms_links'] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped("Migrace {$table} neproběhla.");
            }
        }

        $supplierStatement = $this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1');
        $userStatement = $this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1');
        if ($supplierStatement === false || $userStatement === false) {
            throw new \RuntimeException('Výchozí firmu nebo uživatele nelze načíst.');
        }
        $sourceSupplierId = $this->positiveId($supplierStatement->fetchColumn());
        $this->userId = $this->positiveId($userStatement->fetchColumn());
        if ($sourceSupplierId <= 0 || $this->userId <= 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }
        $this->pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($this->pdo, $sourceSupplierId);
        $this->pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        foreach (array_unique($this->storedPaths) as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    public function testXmlzamAndGeneratedPayrollDocumentsRequireTheirDedicatedSessionPermissions(): void
    {
        $marker = 'MZDMS' . strtoupper(bin2hex(random_bytes(6)));
        $folderId = $this->folders->create(
            $this->supplierId,
            null,
            "Syntetické citlivé dokumenty {$marker}",
            $this->userId,
        );
        [$revisionId, $employeeId] = $this->seedApprovedRevision(
            $this->pdo,
            $this->supplierId,
            strtolower($marker),
        );
        $runStatement = $this->pdo->prepare('SELECT run_id FROM payroll_run_revisions WHERE id = ?');
        $runStatement->execute([$revisionId]);
        $runId = $this->positiveId($runStatement->fetchColumn());
        if ($runId <= 0) {
            self::fail('Schválená syntetická revize nemá mzdový běh.');
        }

        [$xmlzamRoot, $xmlzamRootStored] = $this->storedDocument(
            $folderId,
            "{$marker}-xmlzam-container.zfo",
            "synthetic XMLZAM container {$marker}",
        );
        [$xmlzamChild, $xmlzamChildStored] = $this->storedDocument(
            $folderId,
            "{$marker}-xmlzam-request.xml",
            "<synthetic-xmlzam>{$marker}</synthetic-xmlzam>",
            $xmlzamRoot,
        );
        $xmlzamFileId = $this->files->add([
            'document_id' => $xmlzamChild,
            'supplier_id' => $this->supplierId,
            'role' => 'primary',
            'sha256' => $xmlzamChildStored['sha256'],
            'filename' => $xmlzamChildStored['filename'],
            'original_name' => "{$marker}-xmlzam-request.xml",
            'mime_type' => $xmlzamChildStored['mime_type'],
            'size_bytes' => $xmlzamChildStored['size_bytes'],
            'doc_type' => $xmlzamChildStored['doc_type'],
            'sort_order' => 0,
            'uploaded_by' => $this->userId,
        ]);
        $this->pdo->prepare(
            "INSERT INTO submission_inbox_messages
                (supplier_id, environment, channel, external_message_id, sender_box_id,
                 subject, classification, document_id, raw_sha256, processed_at)
             VALUES (?, 'test', 'isds', ?, 'abc1234', ?, 'unclassified', ?, ?, UTC_TIMESTAMP())",
        )->execute([
            $this->supplierId,
            'synthetic-' . strtolower($marker),
            "Syntetická XMLZAM výzva {$marker}",
            $xmlzamRoot,
            $xmlzamRootStored['sha256'],
        ]);
        $inboxId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO payroll_enforcement_xmlzam_requests
                (supplier_id, environment, employee_id, inbox_message_id,
                 source_document_id, source_document_file_id, request_identifier,
                 issued_on, executor_box_id, source_xml_sha256, snapshot_ciphertext,
                 snapshot_fingerprint, imported_by, imported_at)
             VALUES (?, 'test', ?, ?, ?, ?, ?, '2026-08-27', 'abc1234', ?,
                     'enc:v2:synthetic-test-only', ?, ?, UTC_TIMESTAMP())",
        )->execute([
            $this->supplierId,
            $employeeId,
            $inboxId,
            $xmlzamChild,
            $xmlzamFileId,
            substr('SYN-' . $marker, 0, 32),
            $xmlzamChildStored['sha256'],
            hash('sha256', "xmlzam-snapshot-{$marker}"),
            $this->userId,
        ]);

        [$employeeRoot] = $this->storedDocument(
            $folderId,
            "{$marker}-payslip.pdf",
            "%PDF-1.4 synthetic employee payroll document {$marker}",
        );
        [$employeeChild] = $this->storedDocument(
            $folderId,
            "{$marker}-payslip-note.txt",
            "synthetic extracted employee payroll content {$marker}",
            $employeeRoot,
        );
        $employeePayrollDocumentId = $this->generatedPayrollDocument(
            $runId,
            $revisionId,
            $employeeId,
            'payslip',
            "employee-{$marker}",
        );
        $this->linkPayrollDocument($employeePayrollDocumentId, $employeeRoot);

        [$companyRoot] = $this->storedDocument(
            $folderId,
            "{$marker}-monthly-bundle.pdf",
            "%PDF-1.4 synthetic company payroll bundle {$marker}",
        );
        [$companyChild] = $this->storedDocument(
            $folderId,
            "{$marker}-monthly-bundle-note.txt",
            "synthetic extracted company payroll content {$marker}",
            $companyRoot,
        );
        $companyPayrollDocumentId = $this->generatedPayrollDocument(
            $runId,
            $revisionId,
            null,
            'monthly_bundle',
            "company-{$marker}",
        );
        $this->linkPayrollDocument($companyPayrollDocumentId, $companyRoot);

        $xmlzamIds = [$xmlzamRoot, $xmlzamChild];
        $payrollDocumentIds = [$employeeRoot, $employeeChild, $companyRoot, $companyChild];
        $allIds = [...$payrollDocumentIds, ...$xmlzamIds];
        foreach ($allIds as $documentId) {
            $this->documents->setText($documentId, "Citlivý obsah {$marker} {$documentId}", 'extracted');
        }

        $documentsOnlyRole = $this->role(9911, ['documents' => 1]);
        $enforcementRole = $this->role(9912, ['documents' => 1, 'payroll.enforcement' => 1]);
        $payrollDocumentsRole = $this->role(9913, ['documents' => 1, 'payroll.documents' => 1]);
        $bothRole = $this->role(9914, [
            'documents' => 1,
            'payroll.enforcement' => 1,
            'payroll.documents' => 1,
        ]);

        $restricted = $this->request($documentsOnlyRole);
        $this->assertDocumentsStatus($restricted, $allIds, 404);
        $list = $this->documentsAction->list(
            $restricted->withQueryParams(['folder_id' => (string) $folderId]),
            new Response(),
        );
        $listMeta = $this->json($list)['meta'] ?? null;
        self::assertIsArray($listMeta);
        self::assertSame(0, $listMeta['total'] ?? null);
        self::assertSame([], $this->documents->rawByFolderIds(
            $this->supplierId,
            [$folderId],
            DocumentViewerContext::fromJobParams([], $this->userId),
        ));
        $folder = array_values(array_filter(
            $this->folders->listChildren(
                $this->supplierId,
                null,
                DocumentViewerContext::fromJobParams([], $this->userId),
            ),
            static fn (array $row): bool => is_int($row['id'] ?? null) && $row['id'] === $folderId,
        ))[0] ?? self::fail('Syntetická složka chybí.');
        self::assertSame(0, $folder['file_count']);
        self::assertSame(0, $folder['total_bytes']);

        $search = $this->documentsAction->search(
            $restricted
                ->withUri($restricted->getUri()->withPath('/api/documents/search'))
                ->withQueryParams(['q' => $marker]),
            new Response(),
        );
        $searchRows = $this->json($search)['documents'] ?? null;
        self::assertIsArray($searchRows);
        self::assertSame([], array_values(array_intersect($allIds, $this->ids($searchRows))));
        self::assertSame(404, $this->fileAction->bulkDownload(
            $restricted
                ->withUri($restricted->getUri()->withPath('/api/documents/bulk-download'))
                ->withQueryParams(['ids' => implode(',', $allIds)]),
            new Response(),
        )->getStatusCode());
        foreach ($allIds as $documentId) {
            self::assertSame(404, $this->documentsAction->delete(
                $restricted
                    ->withMethod('DELETE')
                    ->withUri($restricted->getUri()->withPath("/api/documents/{$documentId}")),
                new Response(),
                ['id' => (string) $documentId],
            )->getStatusCode());
        }
        self::assertSame(403, $this->foldersAction->delete(
            $restricted
                ->withMethod('DELETE')
                ->withUri($restricted->getUri()->withPath("/api/document-folders/{$folderId}")),
            new Response(),
            ['id' => (string) $folderId],
        )->getStatusCode());
        self::assertNull($this->documents->findRaw(
            $xmlzamChild,
            $this->supplierId + 999999,
            DocumentViewerContext::internalCompany(),
        ));

        $enforcement = $this->request($enforcementRole);
        $this->assertDocumentsStatus($enforcement, $xmlzamIds, 200);
        $this->assertDocumentsStatus($enforcement, $payrollDocumentIds, 404);
        $payrollDocuments = $this->request($payrollDocumentsRole);
        $this->assertDocumentsStatus($payrollDocuments, $xmlzamIds, 404);
        $this->assertDocumentsStatus($payrollDocuments, $payrollDocumentIds, 200);
        $both = $this->request($bothRole);
        $this->assertDocumentsStatus($both, $allIds, 200);
        self::assertSame(200, $this->fileAction->bulkDownload(
            $both
                ->withUri($both->getUri()->withPath('/api/documents/bulk-download'))
                ->withQueryParams(['ids' => implode(',', $allIds)]),
            new Response(),
        )->getStatusCode());
        $backgroundViewer = DocumentViewerContext::fromJobParams(
            DocumentViewerResolver::fromRequest($both)->toJobParams(),
            $this->userId,
        );
        $backgroundIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->documents->rawByFolderIds($this->supplierId, [$folderId], $backgroundViewer),
        );
        sort($backgroundIds);
        $expectedBackgroundIds = [$xmlzamRoot, $employeeRoot, $companyRoot];
        sort($expectedBackgroundIds);
        self::assertSame($expectedBackgroundIds, $backgroundIds);
        $this->assertDocumentsStatus(
            $both->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer'),
            $allIds,
            404,
        );
    }

    /** @param list<int> $documentIds */
    private function assertDocumentsStatus(
        ServerRequestInterface $request,
        array $documentIds,
        int $status,
    ): void {
        foreach ($documentIds as $documentId) {
            self::assertSame($status, $this->documentsAction->get(
                $request->withUri($request->getUri()->withPath("/api/documents/{$documentId}")),
                new Response(),
                ['id' => (string) $documentId],
            )->getStatusCode());
            self::assertSame($status, $this->documentsAction->text(
                $request->withUri($request->getUri()->withPath("/api/documents/{$documentId}/text")),
                new Response(),
                ['id' => (string) $documentId],
            )->getStatusCode());
            self::assertSame($status, $this->fileAction->download(
                $request->withUri($request->getUri()->withPath("/api/documents/{$documentId}/download")),
                new Response(),
                ['id' => (string) $documentId],
            )->getStatusCode());
        }
    }

    /**
     * @return array{0:int,1:array{sha256:string,filename:string,size_bytes:int,mime_type:string,
     *     doc_type:string,abs_path:string,ext:string}}
     */
    private function storedDocument(
        int $folderId,
        string $originalName,
        string $bytes,
        ?int $parentDocumentId = null,
    ): array {
        $stored = $this->storage->storeFromBytes($bytes, $this->supplierId, $originalName);
        $this->storedPaths[] = $stored['abs_path'];
        $documentId = $this->documents->insert([
            'supplier_id' => $this->supplierId,
            'folder_id' => $folderId,
            'title' => $originalName,
            'description' => null,
            'original_name' => $originalName,
            'filename' => $stored['filename'],
            'sha256' => $stored['sha256'],
            'mime_type' => $stored['mime_type'],
            'size_bytes' => $stored['size_bytes'],
            'doc_type' => $stored['doc_type'],
            'source' => $parentDocumentId === null ? 'manual' : 'zfo_extract',
            'parent_document_id' => $parentDocumentId,
            'uploaded_by' => $this->userId,
            'scope' => 'company',
        ]);
        return [$documentId, $stored];
    }

    private function generatedPayrollDocument(
        int $runId,
        int $revisionId,
        ?int $employeeId,
        string $kind,
        string $seed,
    ): int {
        $sha = hash('sha256', "generated-payroll-document-{$seed}");
        $this->pdo->prepare(
            'INSERT INTO payroll_generated_documents
                (supplier_id, run_id, revision_id, employee_id, document_kind,
                 revision_snapshot_hash, source_snapshot_hash, template_version,
                 renderer_version, file_sha256, size_bytes, mime_type, storage_key,
                 suggested_filename, idempotency_key_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, "v1", "v1", ?, 2048,
                     "application/pdf", ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $runId,
            $revisionId,
            $employeeId,
            $kind,
            $this->payrollResultSnapshotHash(),
            $sha,
            $sha,
            $sha,
            "{$seed}.pdf",
            hash('sha256', "generated-payroll-document-idem-{$seed}", true),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function linkPayrollDocument(int $payrollDocumentId, int $dmsDocumentId): void
    {
        $this->pdo->prepare(
            'INSERT INTO payroll_document_dms_links
                (supplier_id, payroll_document_id, dms_document_id, linked_by)
             VALUES (?, ?, ?, ?)',
        )->execute([$this->supplierId, $payrollDocumentId, $dmsDocumentId, $this->userId]);
    }

    /** @param array<string,int> $permissions */
    private function role(int $id, array $permissions): EffectiveRole
    {
        return new EffectiveRole($id, 'Syntetická ACL role', 'staff', true, $permissions);
    }

    private function request(EffectiveRole $role): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/documents')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute('auth.effective_role', $role);
    }

    /**
     * @param array<mixed> $rows
     * @return list<int>
     */
    private function ids(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if (is_array($row) && is_int($row['id'] ?? null)) {
                $ids[] = $row['id'];
            }
        }
        return $ids;
    }

    private function positiveId(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }
        if (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            return 0;
        }
        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return is_int($validated) ? $validated : 0;
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        if (!is_array($decoded)) {
            return [];
        }
        $result = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }
        return $result;
    }
}
