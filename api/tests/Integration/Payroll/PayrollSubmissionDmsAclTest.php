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
use MyInvoice\Repository\DocumentFolderRepository;
use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Repository\DocumentViewerContext;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Document\DocumentStorage;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollSubmissionDmsAclTest extends TestCase
{
    private Connection $db;
    private DocumentRepository $documents;
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
        $folders = $container->get(DocumentFolderRepository::class);
        $documentsAction = $container->get(DocumentsAction::class);
        $fileAction = $container->get(DocumentFileAction::class);
        $foldersAction = $container->get(FoldersAction::class);
        $storage = $container->get(DocumentStorage::class);
        if (
            !$db instanceof Connection
            || !$documents instanceof DocumentRepository
            || !$folders instanceof DocumentFolderRepository
            || !$documentsAction instanceof DocumentsAction
            || !$fileAction instanceof DocumentFileAction
            || !$foldersAction instanceof FoldersAction
            || !$storage instanceof DocumentStorage
        ) {
            throw new \RuntimeException('Služby Dokumentů nejsou dostupné.');
        }
        $this->db = $db;
        if (!$this->db->hasTable('submission_inbox_messages')) {
            $this->markTestSkipped('Migrace příchozích zpráv neproběhla.');
        }
        $this->documents = $documents;
        $this->folders = $folders;
        $this->documentsAction = $documentsAction;
        $this->fileAction = $fileAction;
        $this->foldersAction = $foldersAction;
        $this->storage = $storage;

        $pdo = $this->db->pdo();
        $supplierStatement = $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1');
        $userStatement = $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1');
        if ($supplierStatement === false || $userStatement === false) {
            throw new \RuntimeException('Výchozí firmu nebo uživatele nelze načíst.');
        }
        $this->supplierId = $this->positiveId($supplierStatement->fetchColumn());
        $this->userId = $this->positiveId($userStatement->fetchColumn());
        if ($this->supplierId <= 0 || $this->userId <= 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }
        $pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
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

    public function testClassifiedUnmatchedPayrollInboxDocumentsStayBehindSubmissionPermission(): void
    {
        $folderId = $this->folders->create(
            $this->supplierId,
            null,
            'Syntetické neveřejné protokoly ' . bin2hex(random_bytes(4)),
            $this->userId,
        );
        $documentIds = [];
        $rootIds = [];
        foreach (['cssz_protocol', 'health_insurer_response'] as $classification) {
            $marker = 'ACL' . strtoupper(bin2hex(random_bytes(8)));
            $rootId = $this->storedDocument(
                $folderId,
                "{$marker}-container.zfo",
                "synthetic {$classification} container {$marker}",
            );
            $childId = $this->storedDocument(
                $folderId,
                "{$marker}-protocol.xml",
                "<protocol>{$marker}</protocol>",
                $rootId,
            );
            $this->documents->setText($childId, "Citlivý obsah {$marker}", 'extracted');
            $rootDocument = $this->documents->findRaw(
                $rootId,
                $this->supplierId,
                DocumentViewerContext::internalCompany(),
            );
            if (!is_array($rootDocument) || !is_string($rootDocument['sha256'] ?? null)) {
                self::fail('Syntetický kořenový dokument nelze znovu načíst.');
            }
            $this->db->pdo()->prepare(
                'INSERT INTO submission_inbox_messages
                    (supplier_id, environment, channel, external_message_id, subject,
                     classification, document_id, raw_sha256)
                 VALUES (?, "test", "isds", ?, ?, ?, ?, ?)',
            )->execute([
                $this->supplierId,
                'synthetic-acl-' . bin2hex(random_bytes(8)),
                "Syntetický protokol {$marker}",
                $classification,
                $rootId,
                $rootDocument['sha256'],
            ]);
            $rootIds[] = $rootId;
            $documentIds[] = $rootId;
            $documentIds[] = $childId;
        }

        $documentsOnly = new EffectiveRole(
            9801,
            'Dokumenty bez mzdových podání',
            'staff',
            true,
            ['documents' => 1],
        );
        $submissionReader = new EffectiveRole(
            9802,
            'Mzdová účetní pro podání',
            'staff',
            true,
            ['documents' => 1, 'payroll.submissions' => 1],
        );
        $restricted = $this->request('GET', '/api/documents', $documentsOnly);

        foreach ($documentIds as $documentId) {
            self::assertSame(404, $this->documentsAction->get(
                $restricted->withUri($restricted->getUri()->withPath("/api/documents/{$documentId}")),
                new Response(),
                ['id' => (string) $documentId],
            )->getStatusCode());
            self::assertSame(404, $this->documentsAction->text(
                $restricted->withUri($restricted->getUri()->withPath("/api/documents/{$documentId}/text")),
                new Response(),
                ['id' => (string) $documentId],
            )->getStatusCode());
            self::assertSame(404, $this->fileAction->download(
                $restricted->withUri($restricted->getUri()->withPath("/api/documents/{$documentId}/download")),
                new Response(),
                ['id' => (string) $documentId],
            )->getStatusCode());
            self::assertNull($this->documents->findRaw(
                $documentId,
                $this->supplierId,
                DocumentViewerContext::forUser($this->userId),
            ));
        }

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
            DocumentViewerContext::forUser($this->userId),
        ));
        $folder = array_values(array_filter(
            $this->folders->listChildren(
                $this->supplierId,
                null,
                DocumentViewerContext::forUser($this->userId),
            ),
            static fn (array $row): bool => is_int($row['id'] ?? null) && $row['id'] === $folderId,
        ))[0] ?? self::fail('Syntetická složka chybí.');
        self::assertSame(0, $folder['file_count']);
        self::assertSame(0, $folder['total_bytes']);

        $search = $this->documentsAction->search(
            $restricted
                ->withUri($restricted->getUri()->withPath('/api/documents/search'))
                ->withQueryParams(['q' => 'Syntetický protokol']),
            new Response(),
        );
        $searchRows = $this->json($search)['documents'] ?? null;
        self::assertIsArray($searchRows);
        $searchIds = [];
        foreach ($searchRows as $row) {
            if (is_array($row) && is_int($row['id'] ?? null)) {
                $searchIds[] = $row['id'];
            }
        }
        foreach ($rootIds as $rootId) {
            self::assertNotContains($rootId, $searchIds);
        }

        self::assertSame(404, $this->fileAction->bulkDownload(
            $restricted
                ->withUri($restricted->getUri()->withPath('/api/documents/bulk-download'))
                ->withQueryParams(['ids' => implode(',', $documentIds)]),
            new Response(),
        )->getStatusCode());
        $delete = $this->documentsAction->delete(
            $restricted
                ->withMethod('DELETE')
                ->withUri($restricted->getUri()->withPath('/api/documents/' . $rootIds[0])),
            new Response(),
            ['id' => (string) $rootIds[0]],
        );
        self::assertSame(404, $delete->getStatusCode());
        self::assertNull($this->documents->findRaw(
            $rootIds[0],
            $this->supplierId + 999999,
            DocumentViewerContext::forUser($this->userId, false, false, true),
        ));
        $folderDelete = $this->foldersAction->delete(
            $restricted
                ->withMethod('DELETE')
                ->withUri($restricted->getUri()->withPath("/api/document-folders/{$folderId}")),
            new Response(),
            ['id' => (string) $folderId],
        );
        self::assertSame(403, $folderDelete->getStatusCode());

        $allowed = $restricted->withAttribute('auth.effective_role', $submissionReader);
        foreach ($documentIds as $documentId) {
            self::assertSame(200, $this->documentsAction->get(
                $allowed->withUri($allowed->getUri()->withPath("/api/documents/{$documentId}")),
                new Response(),
                ['id' => (string) $documentId],
            )->getStatusCode());
            self::assertSame(200, $this->fileAction->download(
                $allowed->withUri($allowed->getUri()->withPath("/api/documents/{$documentId}/download")),
                new Response(),
                ['id' => (string) $documentId],
            )->getStatusCode());
        }
        self::assertSame(200, $this->fileAction->bulkDownload(
            $allowed
                ->withUri($allowed->getUri()->withPath('/api/documents/bulk-download'))
                ->withQueryParams(['ids' => implode(',', $documentIds)]),
            new Response(),
        )->getStatusCode());
        self::assertSame(404, $this->documentsAction->get(
            $allowed
                ->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer')
                ->withUri($allowed->getUri()->withPath('/api/documents/' . $rootIds[0])),
            new Response(),
            ['id' => (string) $rootIds[0]],
        )->getStatusCode());
    }

    private function storedDocument(
        int $folderId,
        string $originalName,
        string $bytes,
        ?int $parentDocumentId = null,
    ): int {
        $stored = $this->storage->storeFromBytes($bytes, $this->supplierId, $originalName);
        $this->storedPaths[] = $stored['abs_path'];
        return $this->documents->insert([
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
    }

    private function request(
        string $method,
        string $uri,
        EffectiveRole $role,
    ): ServerRequestInterface {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $uri)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute('auth.effective_role', $role);
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
