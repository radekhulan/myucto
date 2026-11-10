<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Action\Portal\PortalPurchaseInvoiceSubmissionAction;
use MyInvoice\Action\PurchaseInvoice\PurchaseInvoiceSubmissionAction;
use MyInvoice\Repository\DocumentRequestRepository;
use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Repository\PurchaseInvoiceSubmissionRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Document\DocumentStorage;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceSubmissionUploadService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\UploadedFile;

#[Group('integration')]
#[AllowMockObjectsWithoutExpectations]
final class PurchaseInvoiceSubmissionLifecycleTest extends TestCase
{
    private PortalPurchaseInvoiceSubmissionAction $portalAction;
    private PurchaseInvoiceSubmissionAction $inboxAction;
    private Connection $db;
    private DocumentRequestRepository $repo;
    private DocumentRepository $documents;
    private PurchaseInvoiceRepository $purchaseRepo;
    private PurchaseInvoiceSubmissionRepository $submissions;
    private PurchaseInvoiceSubmissionUploadService $upload;
    private DocumentStorage $storage;

    private int $supplierA = 0;
    private int $supplierB = 0;
    private int $userId = 0;
    private int $currencyId = 0;
    private int $czId = 0;
    private bool $inTx = false;
    /** @var list<string> */
    private array $createdFiles = [];
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db           = $container->get(Connection::class);
            $this->repo          = $container->get(DocumentRequestRepository::class);
            $this->documents     = $container->get(DocumentRepository::class);
            $this->purchaseRepo  = $container->get(PurchaseInvoiceRepository::class);
            $this->submissions   = $container->get(PurchaseInvoiceSubmissionRepository::class);
            $this->upload        = $container->get(PurchaseInvoiceSubmissionUploadService::class);
            $this->storage       = $container->get(DocumentStorage::class);
            $this->portalAction = $container->get(PortalPurchaseInvoiceSubmissionAction::class);
            $this->inboxAction = $container->get(PurchaseInvoiceSubmissionAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $baseSupplier = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code='CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($baseSupplier === 0 || $this->userId === 0 || $this->currencyId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }
        if ($pdo->query("SHOW TABLES LIKE 'purchase_invoice_submissions'")->fetchColumn() === false) {
            $this->markTestSkipped('Chybí migrace purchase_invoice_submissions (1404).');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $mk = static function (string $name) use ($pdo, $baseSupplier): int {
            $stmt = $pdo->prepare(
                'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
                 SELECT ?, "Testovací", "Praha", "11000", country_id, ?, default_currency_id, default_vat_rate_id
                   FROM supplier WHERE id = ?'
            );
            $stmt->execute([$name, strtolower(str_replace(' ', '', $name)) . '@example.com', $baseSupplier]);
            return (int) $pdo->lastInsertId();
        };
        $this->supplierA = $mk('DocReq Firma A');
        $this->supplierB = $mk('DocReq Firma B');
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
        foreach (array_unique($this->createdFiles) as $path) {
            if (is_file($path)) @unlink($path);
            @rmdir(dirname($path));
            @rmdir(dirname(dirname($path)));
        }
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) @unlink($path);
        }
    }

    public function testStaffRejectionRequiresReasonAndCanRejectReturnedDocument(): void
    {
        $row = $this->submission('staff');
        $request = $this->inboxRequest('POST', '/reject')->withParsedBody(['reason' => '   ']);
        $response = $this->inboxAction->reject($request, (new ResponseFactory())->createResponse(), ['id' => $row['id']]);
        self::assertSame(400, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('submitted', $this->submissions->find($row['id'], $this->supplierA)['status']);
        self::assertTrue($this->submissions->needsInformation($row['id'], $this->supplierA, 'Doplňte originál.'));
        $response = $this->inboxAction->reject($request->withParsedBody(['reason' => '  Není účetním dokladem.  ']), (new ResponseFactory())->createResponse(), ['id' => $row['id']]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $rejected = $this->submissions->find($row['id'], $this->supplierA);
        self::assertSame('rejected', $rejected['status']);
        self::assertSame('Není účetním dokladem.', $rejected['status_reason']);
        self::assertNull($this->documentDeletedAt($row['document_id']));
        self::assertFileExists($this->createdFiles[0]);
        self::assertNull($rejected['purchase_invoice_id']);
        $response = $this->portalAction->list($this->clientRequest('GET'), (new ResponseFactory())->createResponse());
        $items = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['items'];
        self::assertSame('Není účetním dokladem.', $items[0]['status_reason']);
    }

    public function testClientCanDeleteOnlyOwnPendingDocumentAndReopensRequest(): void
    {
        $row = $this->submission();
        $requestId = $this->repo->create($this->supplierA, ['description' => 'Synthetic missing document'], $this->userId);
        self::assertTrue($this->repo->markSubmitted($requestId, $this->supplierA, $row['id']));
        $list = $this->portalAction->list($this->clientRequest('GET'), (new ResponseFactory())->createResponse());
        $items = json_decode((string) $list->getBody(), true, flags: JSON_THROW_ON_ERROR)['items'];
        self::assertTrue($items[0]['can_delete']);
        $response = $this->portalAction->delete($this->clientRequest('DELETE'), (new ResponseFactory())->createResponse(), ['id' => $row['id']]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertNull($this->submissions->find($row['id'], $this->supplierA));
        self::assertNotNull($this->documentDeletedAt($row['document_id']));
        self::assertFileExists($this->createdFiles[0]);
        $request = $this->repo->find($requestId, $this->supplierA);
        self::assertSame('requested', $request['status']);
        self::assertNull($request['submission_id']);
        $audit = $this->db->pdo()->prepare("SELECT COUNT(*) FROM activity_log WHERE supplier_id = ? AND entity_type = 'purchase_invoice_submission' AND entity_id = ? AND action = 'purchase_invoice_submission.deleted'");
        $audit->execute([$this->supplierA, $row['id']]);
        self::assertSame(1, (int) $audit->fetchColumn());
    }

    public function testDeleteOwnershipTenantAndPermissionsFailClosed(): void
    {
        $row = $this->submission();
        $this->db->pdo()->prepare("INSERT INTO users (email, password_hash, name, locale, is_active) VALUES (?, 'disabled-test-password', 'Synthetic other submitter', 'cs', 1)")->execute(['submission-other-' . bin2hex(random_bytes(8)) . '@example.invalid']);
        $otherId = (int) $this->db->pdo()->lastInsertId();
        $foreign = $this->clientRequest('DELETE')->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $otherId, 'role' => 'client']);
        self::assertSame(404, $this->portalAction->delete($foreign, (new ResponseFactory())->createResponse(), ['id' => $row['id']])->getStatusCode());
        $foreignList = $this->portalAction->list($foreign, (new ResponseFactory())->createResponse());
        $items = json_decode((string) $foreignList->getBody(), true, flags: JSON_THROW_ON_ERROR)['items'];
        self::assertFalse($items[0]['can_delete']);
        $tenant = $this->clientRequest('DELETE')->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierB);
        self::assertSame(404, $this->portalAction->delete($tenant, (new ResponseFactory())->createResponse(), ['id' => $row['id']])->getStatusCode());
        $readOnly = $this->clientRequest('DELETE')->withAttribute('auth.effective_role', new EffectiveRole(1, 'client', 'client', true, ['documents.submit' => AccessLevel::READ->value], 'client'));
        self::assertSame(403, $this->portalAction->delete($readOnly, (new ResponseFactory())->createResponse(), ['id' => $row['id']])->getStatusCode());
        // Interní role předává jen s právem `documents.submit` na zápis; účetní fronta ho nenahradí.
        foreach ([['documents.submit' => AccessLevel::READ->value], ['documents.inbox' => AccessLevel::WRITE->value]] as $permissions) {
            $staff = $this->clientRequest('DELETE')->withAttribute('auth.effective_role', new EffectiveRole(1, 'staff', 'staff', true, $permissions, 'accountant'));
            self::assertSame(403, $this->portalAction->delete($staff, (new ResponseFactory())->createResponse(), ['id' => $row['id']])->getStatusCode());
        }
        self::assertNull($this->documentDeletedAt($row['document_id']));
        $superadmin = $foreign->withAttribute('auth.effective_role', new EffectiveRole(1, 'superadmin', 'superadmin', true, [], 'superadmin'));
        self::assertSame(200, $this->portalAction->delete($superadmin, (new ResponseFactory())->createResponse(), ['id' => $row['id']])->getStatusCode());
    }

    public function testDeleteGuardsProcessingHistoryAndFinancialLink(): void
    {
        foreach (['processing', 'needs_information', 'rejected', 'processed', 'started', 'running', 'invoice'] as $state) {
            $row = $this->submission();
            $stmt = $this->db->pdo()->prepare('UPDATE purchase_invoice_submissions SET status = ?, processing_started_at = ?, extraction_status = ?, purchase_invoice_id = ? WHERE id = ?');
            $stmt->execute([in_array($state, ['started', 'running', 'invoice'], true) ? 'submitted' : $state, $state === 'started' ? '2099-01-01 00:00:00' : null, $state === 'running' ? 'running' : 'not_started', $state === 'invoice' ? $this->createPurchaseInvoiceDraft($this->supplierA, 'SYNTHETIC-GUARD') : null, $row['id']]);
            $response = $this->portalAction->delete($this->clientRequest('DELETE'), (new ResponseFactory())->createResponse(), ['id' => $row['id']]);
            self::assertSame(409, $response->getStatusCode(), $state . ': ' . (string) $response->getBody());
            self::assertNotNull($this->submissions->find($row['id'], $this->supplierA));
            self::assertNull($this->documentDeletedAt($row['document_id']));
        }
    }

    public function testRejectedDocumentCanBeReplacedOnceWithHistoryPreserved(): void
    {
        $row = $this->submission();
        self::assertTrue($this->submissions->reject($row['id'], $this->supplierA, 'Nesprávný originál.'));
        $request = $this->clientRequest('POST')->withUploadedFiles(['file' => $this->uploadedFile('replacement.pdf', "%PDF-1.4\n% synthetic replacement " . bin2hex(random_bytes(8)))]);
        $response = $this->portalAction->resubmit($request, (new ResponseFactory())->createResponse(), ['id' => $row['id']]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $items = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['items'];
        $new = $this->submissions->find((int) $items[0]['id'], $this->supplierA);
        $this->trackSubmissionFile($new);
        self::assertSame($row['id'], $new['supersedes_submission_id']);
        self::assertSame('submitted', $new['status']);
        $old = $this->submissions->find($row['id'], $this->supplierA);
        self::assertSame('rejected', $old['status']);
        self::assertSame('Nesprávný originál.', $old['status_reason']);
        self::assertNull($this->documentDeletedAt($row['document_id']));
        $repeat = $this->clientRequest('POST')->withUploadedFiles(['file' => $this->uploadedFile('another.pdf', "%PDF-1.4\n% synthetic repeat " . bin2hex(random_bytes(8)))]);
        self::assertSame(409, $this->portalAction->resubmit($repeat, (new ResponseFactory())->createResponse(), ['id' => $row['id']])->getStatusCode());
    }

    public function testConditionalDeleteRechecksOwnershipAndClaimedStatus(): void
    {
        $row = $this->submission();
        self::assertNull($this->submissions->deleteFromQueue($row['id'], $this->supplierA, $this->userId + 1000000, true));
        self::assertNotNull($this->submissions->find($row['id'], $this->supplierA));
        self::assertTrue($this->submissions->claimForManual($row['id'], $this->supplierA));
        self::assertNull($this->submissions->deleteFromQueue($row['id'], $this->supplierA, $this->userId, true));
        self::assertSame('processing', $this->submissions->find($row['id'], $this->supplierA)['status']);
        self::assertNull($this->documentDeletedAt($row['document_id']));
    }

    /**
     * Přerušené vytěžení (požadavek nedoběhl) nechalo podání ve stavu `processing`:
     * fronta ho neukázala mezi čekajícími a nešlo vytěžit znovu ani smazat.
     */
    public function testInterruptedExtractionReturnsToQueueAndCanBeRetriedOrDeleted(): void
    {
        $row = $this->submission('staff');
        $markStale = $this->db->pdo()->prepare(
            "UPDATE purchase_invoice_submissions
                SET status = 'processing', extraction_status = 'running',
                    processing_started_at = NOW() - INTERVAL " . (PurchaseInvoiceSubmissionRepository::STALE_PROCESSING_MINUTES + 1) . ' MINUTE
              WHERE id = ?'
        );
        $markStale->execute([$row['id']]);

        self::assertTrue($this->submissions->find($row['id'], $this->supplierA)['processing_stale']);
        self::assertContains($row['id'], $this->pendingIds());

        self::assertTrue($this->submissions->claimForExtraction($row['id'], $this->supplierA), 'Přerušené vytěžení jde spustit znovu.');
        self::assertFalse($this->submissions->find($row['id'], $this->supplierA)['processing_stale']);
        self::assertNotContains($row['id'], $this->pendingIds(), 'Právě běžící vytěžení mezi čekajícími není.');
        self::assertFalse($this->submissions->claimForExtraction($row['id'], $this->supplierA), 'Běžící vytěžení se nesmí spustit podruhé.');

        $markStale->execute([$row['id']]);
        $response = $this->inboxAction->delete($this->inboxRequest('DELETE', '/api/purchase-invoice-submissions/' . $row['id']), (new ResponseFactory())->createResponse(), ['id' => $row['id']]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertNull($this->submissions->find($row['id'], $this->supplierA));
    }

    /** @return list<int> */
    private function pendingIds(): array
    {
        return array_map(static fn (array $i): int => (int) $i['id'], $this->submissions->paginate($this->supplierA, 'submitted', 100, 0)['items']);
    }

    public function testFailedStaffDeleteRollsBackRequestReopening(): void
    {
        $row = $this->submission();
        $requestId = $this->repo->create($this->supplierA, ['description' => 'Synthetic processing request'], $this->userId);
        self::assertTrue($this->repo->markSubmitted($requestId, $this->supplierA, $row['id']));
        self::assertTrue($this->submissions->claimForManual($row['id'], $this->supplierA));
        $response = $this->inboxAction->delete($this->inboxRequest('DELETE', '/api/purchase-invoice-submissions/' . $row['id']), (new ResponseFactory())->createResponse(), ['id' => $row['id']]);
        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        $request = $this->repo->find($requestId, $this->supplierA);
        self::assertSame('uploaded', $request['status']);
        self::assertSame($row['id'], (int) $request['submission_id']);
        self::assertSame('processing', $this->submissions->find($row['id'], $this->supplierA)['status']);
        self::assertNull($this->documentDeletedAt($row['document_id']));
    }

    public function testPendingSubmissionCannotTrashFinancialOriginOrResolvedRequest(): void
    {
        $row = $this->submission();
        $invoiceId = $this->createPurchaseInvoiceDraft($this->supplierA, 'SYNTHETIC-LINKED-ORIGIN');
        (new \MyInvoice\Repository\DocumentLinkRepository($this->db))->attach($this->supplierA, $row['document_id'], 'purchase_invoice', $invoiceId);
        $response = $this->portalAction->delete($this->clientRequest('DELETE'), (new ResponseFactory())->createResponse(), ['id' => $row['id']]);
        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        self::assertNotNull($this->purchaseRepo->find($invoiceId, $this->supplierA));
        self::assertNull($this->documentDeletedAt($row['document_id']));
        self::assertNotNull($this->submissions->find($row['id'], $this->supplierA));
        $list = $this->portalAction->list($this->clientRequest('GET'), (new ResponseFactory())->createResponse());
        self::assertFalse(json_decode((string) $list->getBody(), true, flags: JSON_THROW_ON_ERROR)['items'][0]['can_delete']);

        $resolvedRow = $this->submission();
        $requestId = $this->repo->create($this->supplierA, ['description' => 'Synthetic resolved request'], $this->userId);
        self::assertTrue($this->repo->markSubmitted($requestId, $this->supplierA, $resolvedRow['id']));
        self::assertTrue($this->repo->resolve($requestId, $this->supplierA, $this->userId));
        $response = $this->portalAction->delete($this->clientRequest('DELETE'), (new ResponseFactory())->createResponse(), ['id' => $resolvedRow['id']]);
        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('resolved', $this->repo->find($requestId, $this->supplierA)['status']);
        self::assertSame($resolvedRow['id'], (int) $this->repo->find($requestId, $this->supplierA)['submission_id']);
        self::assertNull($this->documentDeletedAt($resolvedRow['document_id']));
    }

    private function clientRequest(string $method): \Psr\Http\Message\ServerRequestInterface
    {
        return $this->inboxRequest($method, '/api/portal/purchase-invoice-submissions', 'client');
    }

    private function submission(string $via = 'portal'): array
    {
        $row = $this->upload->submit($this->uploadedFile('synthetic.pdf', "%PDF-1.4\n% synthetic lifecycle " . bin2hex(random_bytes(8))), $this->supplierA, $this->userId, $via)['submission'];
        $this->trackSubmissionFile($row);
        return $row;
    }

    private function documentDeletedAt(int $id): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT deleted_at FROM documents WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $this->supplierA]);
        return $stmt->fetchColumn() ?: null;
    }

    private function inboxRequest(string $method, string $path, string $role = 'admin')
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierA)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role]);
    }

    private function uploadedFile(string $name, string $contents): UploadedFile
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'docreq');
        $this->temporaryFiles[] = $tmpFile;
        file_put_contents($tmpFile, $contents);
        return new UploadedFile(
            $tmpFile,
            $name,
            'application/octet-stream',
            (int) filesize($tmpFile),
            UPLOAD_ERR_OK,
        );
    }

    private function createPurchaseInvoiceDraft(int $supplierId, string $vendorInvoiceNumber): int
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id,
                                  main_email, language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "vendor@example.com", "cs", ?, 0, 1)'
        );
        $stmt->execute([$supplierId, 'DocReq Vendor', $this->czId, $this->currencyId]);
        $vendorId = (int) $pdo->lastInsertId();

        return $this->purchaseRepo->createDraft([
            'vendor_id'             => $vendorId,
            'vendor_invoice_number' => $vendorInvoiceNumber,
            'document_kind'         => 'invoice',
            'issue_date'            => '2099-06-10',
            'tax_date'              => '2099-06-10',
            'due_date'              => '2099-06-24',
            'currency_id'           => $this->currencyId,
        ], $this->userId, $supplierId);
    }

    /** @param array<string,mixed> $submission */
    private function trackSubmissionFile(array $submission): void
    {
        $this->createdFiles[] = $this->storage->pathFor(
            (int) $submission['supplier_id'],
            (string) $submission['document_sha256'],
            (string) $submission['document_filename'],
        );
    }
}
