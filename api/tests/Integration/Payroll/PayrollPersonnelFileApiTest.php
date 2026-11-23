<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollEmploymentAgendaSummaryAction;
use MyInvoice\Action\Payroll\PayrollPersonnelFileAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollEmployeeDeletionRepository;
use MyInvoice\Service\Payroll\Document\PayrollDocumentKeyRing;
use MyInvoice\Service\Payroll\Personnel\PayrollPersonnelFileService;
use MyInvoice\Service\Payroll\Personnel\PayrollPersonnelFileStorage;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Psr7\UploadedFile;

/**
 * Personální spis: soubory mimo DMS, šifrované klíčem osoby, s náhledem,
 * stažením a smazáním; poznámky šifrované v databázi.
 */
#[Group('integration')]
final class PayrollPersonnelFileApiTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PDF_MARKER = 'SYNTETICKA-PRACOVNI-SMLOUVA';

    private Connection $db;
    private PayrollPersonnelFileAction $action;
    private ContainerInterface $container;
    private int $userId;
    private int $supplierId;
    private int $otherSupplierId;
    private int $employeeId;
    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $this->container = $container;
        $connection = $container->get(Connection::class);
        $action = $container->get(PayrollPersonnelFileAction::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(PayrollPersonnelFileAction::class, $action);
        $this->db = $connection;
        $this->action = $action;

        $pdo = $connection->pdo();
        $sourceSupplierId = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $sourceSupplierId);
        self::assertGreaterThan(0, $this->userId);

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)')
            ->execute([$this->supplierId, $this->otherSupplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetický zaměstnanec spisu", "employee", 1)'
        )->execute([$this->supplierId]);
        $this->employeeId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        if (isset($this->db)) {
            $this->db->close();
        }
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        foreach ([$this->supplierId ?? 0, $this->otherSupplierId ?? 0] as $supplierId) {
            if ($supplierId > 0) {
                self::removeTree(PayrollPersonnelFileStorage::baseDir($supplierId));
            }
        }
    }

    public function testUploadedPdfIsEncryptedOnDiskAndServedForPreviewAndDownload(): void
    {
        $bytes = self::pdf();
        $document = $this->uploadOk('smlouva.pdf', $bytes, [
            'category' => 'employment_contract',
            'title' => 'Pracovní smlouva',
            'document_date' => '2026-01-01',
        ]);
        self::assertTrue($document['previewable']);
        self::assertArrayNotHasKey('file_sha256', $document);

        // Soubor leží ve vlastním kořeni mimo DMS a není čitelný.
        $sha = hash('sha256', $bytes);
        $path = PayrollPersonnelFileStorage::baseDir($this->supplierId)
            . '/subj-' . $this->employeeId . '/' . substr($sha, 0, 2) . '/' . $sha;
        self::assertFileExists($path);
        self::assertStringNotContainsString(self::PDF_MARKER, (string) file_get_contents($path));
        $inDms = $this->db->pdo()->prepare('SELECT COUNT(*) FROM documents WHERE supplier_id = ? AND sha256 = ?');
        $inDms->execute([$this->supplierId, $sha]);
        self::assertSame(0, (int) $inDms->fetchColumn());

        $preview = $this->content((int) $document['id'], true);
        self::assertSame(200, $preview->getStatusCode());
        self::assertSame('application/pdf', $preview->getHeaderLine('Content-Type'));
        self::assertStringStartsWith('inline;', $preview->getHeaderLine('Content-Disposition'));
        self::assertSame('nosniff', $preview->getHeaderLine('X-Content-Type-Options'));
        self::assertSame($bytes, (string) $preview->getBody());

        $download = $this->content((int) $document['id'], false);
        self::assertSame('application/octet-stream', $download->getHeaderLine('Content-Type'));
        self::assertStringStartsWith('attachment;', $download->getHeaderLine('Content-Disposition'));
        self::assertSame($bytes, (string) $download->getBody());

        $overview = $this->overview();
        self::assertCount(1, $overview['documents']);
        self::assertSame('Pracovní smlouva', $overview['documents'][0]['title']);
    }

    public function testNonPdfIsNeverServedInlineEvenWhenPreviewIsRequested(): void
    {
        $document = $this->uploadOk('popis.txt', "Syntetický popis pozice\n", ['category' => 'job_description']);
        self::assertFalse($document['previewable']);

        $response = $this->content((int) $document['id'], true);
        self::assertSame('application/octet-stream', $response->getHeaderLine('Content-Type'));
        self::assertStringStartsWith('attachment;', $response->getHeaderLine('Content-Disposition'));
    }

    public function testDisallowedTypesAndDuplicatesAreRejected(): void
    {
        self::assertSame(415, $this->upload('skript.exe', 'MZ' . str_repeat("\0", 64))->getStatusCode());
        // HTML převlečené za .txt se nepřijme podle obsahu.
        self::assertSame(
            415,
            $this->upload('stranka.txt', '<!DOCTYPE html><html><body><script>x</script></body></html>')->getStatusCode(),
        );

        $this->uploadOk('smlouva.pdf', self::pdf());
        self::assertSame(409, $this->upload('kopie.pdf', self::pdf())->getStatusCode());
    }

    public function testDeleteRemovesRowAndEncryptedFile(): void
    {
        $bytes = self::pdf();
        $document = $this->uploadOk('smlouva.pdf', $bytes);
        $sha = hash('sha256', $bytes);
        $path = PayrollPersonnelFileStorage::baseDir($this->supplierId)
            . '/subj-' . $this->employeeId . '/' . substr($sha, 0, 2) . '/' . $sha;
        self::assertFileExists($path);

        $response = $this->action->deleteDocument(
            $this->request('DELETE'),
            new Response(),
            ['id' => (string) $this->employeeId, 'documentId' => (string) $document['id']],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertFileDoesNotExist($path);
        self::assertSame([], $this->overview()['documents']);
        self::assertSame(404, $this->content((int) $document['id'], false)->getStatusCode());
    }

    public function testNotesAreStoredEncryptedAndCanBeEditedAndDeleted(): void
    {
        $created = $this->json($this->action->createNote(
            $this->request('POST', ['body' => 'Syntetická citlivá poznámka', 'pinned' => true]),
            new Response(),
            ['id' => (string) $this->employeeId],
        ));
        self::assertCount(1, $created['notes']);
        $note = $created['notes'][0];
        self::assertSame('Syntetická citlivá poznámka', $note['body']);
        self::assertTrue($note['pinned']);

        $raw = $this->db->pdo()->prepare('SELECT body_ciphertext FROM payroll_personnel_notes WHERE id = ?');
        $raw->execute([$note['id']]);
        self::assertStringNotContainsString('citlivá', (string) $raw->fetchColumn());

        $updated = $this->json($this->action->updateNote(
            $this->request('PUT', ['body' => 'Upravená poznámka', 'pinned' => false]),
            new Response(),
            ['id' => (string) $this->employeeId, 'noteId' => (string) $note['id']],
        ));
        self::assertSame('Upravená poznámka', $updated['notes'][0]['body']);
        self::assertFalse($updated['notes'][0]['pinned']);

        $deleted = $this->json($this->action->deleteNote(
            $this->request('DELETE'),
            new Response(),
            ['id' => (string) $this->employeeId, 'noteId' => (string) $note['id']],
        ));
        self::assertSame([], $deleted['notes']);
    }

    public function testCryptoErasureMakesDocumentsAndNotesUnreadable(): void
    {
        $document = $this->uploadOk('smlouva.pdf', self::pdf());
        $this->action->createNote(
            $this->request('POST', ['body' => 'Poznámka před výmazem']),
            new Response(),
            ['id' => (string) $this->employeeId],
        );

        $keyRing = $this->container->get(PayrollDocumentKeyRing::class);
        self::assertInstanceOf(PayrollDocumentKeyRing::class, $keyRing);
        $keyRing->destroy($this->supplierId, $this->employeeId, $this->userId, 'Syntetický test výmazu');

        self::assertSame(410, $this->content((int) $document['id'], false)->getStatusCode());
        $overview = $this->overview();
        self::assertTrue($overview['notes'][0]['erased']);
        self::assertNull($overview['notes'][0]['body']);
    }

    public function testErasurePurgeRemovesWholeFileSoThePersonCanBeDeleted(): void
    {
        $bytes = self::pdf();
        $this->uploadOk('smlouva.pdf', $bytes);
        $this->action->createNote(
            $this->request('POST', ['body' => 'Poznámka k výmazu']),
            new Response(),
            ['id' => (string) $this->employeeId],
        );

        $service = $this->container->get(PayrollPersonnelFileService::class);
        self::assertInstanceOf(PayrollPersonnelFileService::class, $service);
        self::assertSame(
            ['personnel_documents_purged' => 1],
            $service->purge($this->supplierId, $this->employeeId, $this->userId, null, ''),
        );

        $sha = hash('sha256', $bytes);
        self::assertFileDoesNotExist(PayrollPersonnelFileStorage::baseDir($this->supplierId)
            . '/subj-' . $this->employeeId . '/' . substr($sha, 0, 2) . '/' . $sha);
        $overview = $this->overview();
        self::assertSame([], $overview['documents']);
        self::assertSame([], $overview['notes']);

        $deletion = $this->container->get(PayrollEmployeeDeletionRepository::class);
        self::assertInstanceOf(PayrollEmployeeDeletionRepository::class, $deletion);
        $decision = $deletion->canDelete($this->supplierId, $this->employeeId);
        self::assertNotNull($decision);
        self::assertNotSame('payroll_employee_has_personnel_file', $decision->blockerCode);
    }

    public function testForeignSupplierCannotReachTheFile(): void
    {
        $document = $this->uploadOk('smlouva.pdf', self::pdf());

        $show = $this->action->show(
            $this->request('GET', null, $this->otherSupplierId),
            new Response(),
            ['id' => (string) $this->employeeId],
        );
        self::assertSame(404, $show->getStatusCode());
        self::assertSame(404, $this->content((int) $document['id'], false, $this->otherSupplierId)->getStatusCode());
    }

    public function testReadOnlyRoleWithoutPersonnelPermissionIsRejected(): void
    {
        $response = $this->action->show(
            $this->request('GET', null, null, 'readonly'),
            new Response(),
            ['id' => (string) $this->employeeId],
        );
        self::assertSame(403, $response->getStatusCode());
    }

    public function testAgendaSummaryCountsThePersonnelFileAndDeletionIsBlocked(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status, start_date)
             VALUES (?, ?, "spis-1", "employment", "active", "2026-01-01")'
        )->execute([$this->supplierId, $this->employeeId]);
        $employmentId = (int) $pdo->lastInsertId();

        $this->uploadOk('smlouva.pdf', self::pdf());
        $this->action->createNote(
            $this->request('POST', ['body' => 'Poznámka']),
            new Response(),
            ['id' => (string) $this->employeeId],
        );

        $summaryAction = $this->container->get(PayrollEmploymentAgendaSummaryAction::class);
        self::assertInstanceOf(PayrollEmploymentAgendaSummaryAction::class, $summaryAction);
        $summary = $this->json($summaryAction->show($this->request('GET'), new Response(), ['id' => (string) $employmentId]));
        $byKey = array_column($summary['summary']['agendas'], null, 'key');
        self::assertSame(2, $byKey['personnel_file']['count']);

        $deletion = $this->container->get(PayrollEmployeeDeletionRepository::class);
        self::assertInstanceOf(PayrollEmployeeDeletionRepository::class, $deletion);
        $decision = $deletion->canDelete($this->supplierId, $this->employeeId);
        self::assertNotNull($decision);
        self::assertFalse($decision->canDelete);
        self::assertSame('payroll_employee_has_personnel_file', $decision->blockerCode);
    }

    /** @param array<string,string> $fields */
    private function upload(string $name, string $bytes, array $fields = []): ResponseInterface
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ppf');
        self::assertIsString($tmp);
        file_put_contents($tmp, $bytes);
        $this->tempFiles[] = $tmp;
        $file = new UploadedFile($tmp, $name, 'application/octet-stream', strlen($bytes), UPLOAD_ERR_OK);
        $request = $this->request('POST', $fields)->withUploadedFiles(['file' => $file]);

        return $this->action->upload($request, new Response(), ['id' => (string) $this->employeeId]);
    }

    /**
     * @param array<string,string> $fields
     * @return array<string,mixed>
     */
    private function uploadOk(string $name, string $bytes, array $fields = []): array
    {
        $response = $this->upload($name, $bytes, $fields);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $document = $this->json($response)['document'];
        self::assertIsArray($document);

        return $document;
    }

    private function content(int $documentId, bool $inline, ?int $supplierId = null): ResponseInterface
    {
        $request = $this->request('GET', null, $supplierId)
            ->withQueryParams($inline ? ['inline' => '1'] : []);
        $response = $this->action->content(
            $request,
            new Response(),
            ['id' => (string) $this->employeeId, 'documentId' => (string) $documentId],
        );
        $response->getBody()->rewind();

        return $response;
    }

    /** @return array<string,mixed> */
    private function overview(): array
    {
        return $this->json($this->action->show(
            $this->request('GET'),
            new Response(),
            ['id' => (string) $this->employeeId],
        ));
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $body = (string) $response->getBody();
        self::assertSame(200, $response->getStatusCode(), $body);
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @param array<string,mixed>|null $body */
    private function request(
        string $method,
        ?array $body = null,
        ?int $supplierId = null,
        string $role = 'admin',
    ): ServerRequestInterface {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, '/api/payroll/people/' . $this->employeeId . '/personnel-file')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId ?? $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');

        return $body === null ? $request : $request->withParsedBody($body);
    }

    private static function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
            . "2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\n"
            . '% ' . self::PDF_MARKER . "\ntrailer<</Root 1 0 R>>\n%%EOF\n";
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
