<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Action\Portal\PortalDocumentRequestAction;
use MyInvoice\Action\Portal\PortalPurchaseInvoiceSubmissionAction;
use MyInvoice\Action\PurchaseInvoice\PurchaseInvoiceSubmissionAction;
use MyInvoice\Action\PurchaseInvoice\PurchaseInvoiceSubmissionFileAction;
use MyInvoice\Repository\DocumentRequestRepository;
use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Repository\PurchaseInvoiceSubmissionRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Document\DocumentStorage;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceSubmissionCompletionService;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceSubmissionException;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceSubmissionDeletionService;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceSubmissionProcessingService;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceSubmissionUploadService;
use MyInvoice\Service\PurchaseInvoice\SubmissionDimensions;
use MyInvoice\Service\PurchaseInvoice\SubmissionOriginalFiler;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\UploadedFile;

/**
 * Vyžádání chybějících dokladů od klienta (Fáze F, audit 2026-07, nález
 * „Vyžádání chybějících dokladů od klienta (urgence přes portál)").
 *
 * Ověřuje:
 *  - založení požadavku (ručně i s vazbou na bankovní transakci),
 *  - přechod requested → uploaded skrz PortalDocumentRequestAction::upload(),
 *    přičemž vznikne jen účetně neutrální staging podání a nikoli faktura,
 *  - ruční dokončení staging podání, vazbu originálu a výsledné faktury,
 *  - resolve/reopen přechody,
 *  - KRITICKÉ: tenant izolace — listForSupplier i upload() nikdy nevidí/neovlivní
 *    požadavek jiné firmy (fail-closed 404, ne 403 — neprozrazuje existenci),
 *  - badge počty (openCounts) pro dashboard obou stran.
 *
 * Vše v jedné transakci, tearDown rollbackne. Soft-skip bez cfg.php.
 */
#[Group('integration')]
#[AllowMockObjectsWithoutExpectations]
final class DocumentRequestTest extends TestCase
{
    private Connection $db;
    private DocumentRequestRepository $repo;
    private DocumentRepository $documents;
    private PurchaseInvoiceRepository $purchaseRepo;
    private PurchaseInvoiceSubmissionRepository $submissions;
    private PurchaseInvoiceSubmissionUploadService $upload;
    private PurchaseInvoiceSubmissionCompletionService $completion;
    private PurchaseInvoiceSubmissionProcessingService $processing;
    private DocumentStorage $storage;
    private ActivityLogger $activity;
    private IpMatcher $ipMatcher;
    private SubmissionOriginalFiler $filer;
    private SubmissionDimensions $submissionDimensions;

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
            $this->filer         = $container->get(SubmissionOriginalFiler::class);
            $this->upload        = $container->get(PurchaseInvoiceSubmissionUploadService::class);
            $this->completion    = $container->get(PurchaseInvoiceSubmissionCompletionService::class);
            $this->processing    = $container->get(PurchaseInvoiceSubmissionProcessingService::class);
            $this->storage       = $container->get(DocumentStorage::class);
            $this->activity      = $container->get(ActivityLogger::class);
            $this->ipMatcher     = $container->get(IpMatcher::class);
            $this->submissionDimensions = $container->get(SubmissionDimensions::class);
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

    public function testCreateStoresFieldsAndIsFindable(): void
    {
        $id = $this->repo->create($this->supplierA, [
            'description'  => 'Chybí doklad k platbě 4 520 Kč z 12. 6.',
            'amount'       => 4520.0,
            'context_date' => '2099-06-12',
            'deadline'     => '2099-06-30',
            'bank_transaction_id' => null,
        ], $this->userId);

        $found = $this->repo->find($id, $this->supplierA);
        self::assertNotNull($found);
        self::assertSame('requested', $found['status']);
        self::assertSame(4520.0, (float) $found['amount']);
        self::assertSame($this->userId, (int) $found['created_by']);
        self::assertNull($found['purchase_invoice_id']);
    }

    public function testUploadCreatesNeutralSubmissionWithoutPurchaseInvoice(): void
    {
        $id = $this->repo->create($this->supplierA, [
            'description' => 'Chybí doklad k platbě', 'amount' => null,
            'context_date' => null, 'deadline' => null, 'bank_transaction_id' => null,
        ], $this->userId);

        $before = (int) $this->db->pdo()->query(
            'SELECT COUNT(*) FROM purchase_invoices WHERE supplier_id = ' . $this->supplierA
        )->fetchColumn();
        $action = $this->buildPortalAction();
        $request = $this->uploadRequest($this->supplierA, $id);
        $response = $action->upload($request, (new ResponseFactory())->createResponse(), ['id' => (string) $id]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        $reloaded = $this->repo->find($id, $this->supplierA);
        self::assertNotNull($reloaded);
        self::assertSame('uploaded', $reloaded['status'], 'Upload musí přepnout stav requested → uploaded.');
        self::assertNull($reloaded['purchase_invoice_id'], 'Před kontrolou účetní nesmí vzniknout účetní doklad.');
        self::assertGreaterThan(0, (int) $reloaded['submission_id']);

        $submission = $this->submissions->find((int) $reloaded['submission_id'], $this->supplierA);
        self::assertNotNull($submission);
        self::assertSame('submitted', $submission['status']);
        self::assertSame('not_started', $submission['extraction_status']);
        $documentStmt = $this->db->pdo()->prepare(
            'SELECT text_status, thumb_status FROM documents WHERE id = ? AND supplier_id = ?'
        );
        $documentStmt->execute([(int) $submission['document_id'], $this->supplierA]);
        $documentState = $documentStmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($documentState);
        self::assertSame('none', $documentState['text_status'],
            'Převzetí originálu nesmí synchronně spouštět paměťově náročný PDF parser.');
        self::assertSame('none', $documentState['thumb_status'],
            'Staging nepotřebuje při uploadu generovat odvozený náhled; servíruje originál.');
        self::assertSame($before, (int) $this->db->pdo()->query(
            'SELECT COUNT(*) FROM purchase_invoices WHERE supplier_id = ' . $this->supplierA
        )->fetchColumn(), 'Nezpracované podání je mimo náklady, cashflow i DPH, protože nevytvoří purchase_invoice.');
        $fileIdStmt = $this->db->pdo()->prepare(
            "SELECT id FROM document_files WHERE document_id = ? AND supplier_id = ? AND role = 'primary' LIMIT 1"
        );
        $fileIdStmt->execute([(int) $submission['document_id'], $this->supplierA]);
        $fileId = (int) ($fileIdStmt->fetchColumn() ?: 0);
        self::assertGreaterThan(0, $fileId);
        self::assertSame(1, $this->documents->countBySha(
            $this->supplierA,
            (string) $submission['document_sha256'],
            [(int) $submission['document_id']],
            [$fileId],
        ), 'Staging reference musí chránit originální bajty i po odpojení DMS primary řádku.');
        $this->trackSubmissionFile($submission);
    }

    public function testManualCompletionLinksOriginalAndRequestedDocument(): void
    {
        $requestId = $this->repo->create($this->supplierA, [
            'description' => 'Doklad k ručnímu přepisu', 'amount' => null,
            'context_date' => null, 'deadline' => null, 'bank_transaction_id' => null,
        ], $this->userId);
        $response = $this->buildPortalAction()->upload(
            $this->uploadRequest(
                $this->supplierA,
                $requestId,
                'uctenka.png',
                "\x89PNG\r\n\x1A\nsynthetic-" . bin2hex(random_bytes(16)),
            ),
            (new ResponseFactory())->createResponse(),
            ['id' => (string) $requestId],
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        $requestRow = $this->repo->find($requestId, $this->supplierA);
        $submissionId = (int) ($requestRow['submission_id'] ?? 0);
        $submission = $this->submissions->find($submissionId, $this->supplierA);
        self::assertNotNull($submission);
        $this->trackSubmissionFile($submission);

        $invoiceId = $this->createPurchaseInvoiceDraft($this->supplierA, 'DOCREQ-MANUAL-1');
        self::assertTrue($this->submissions->claimForManual($submissionId, $this->supplierA));
        $this->completion->complete(
            $submissionId,
            $this->supplierA,
            $invoiceId,
            $this->userId,
            'manual',
        );

        $done = $this->submissions->find($submissionId, $this->supplierA);
        self::assertSame('processed', $done['status']);
        self::assertSame($invoiceId, (int) $done['purchase_invoice_id']);
        $requestRow = $this->repo->find($requestId, $this->supplierA);
        self::assertSame($invoiceId, (int) $requestRow['purchase_invoice_id']);

        $link = $this->db->pdo()->prepare(
            "SELECT 1 FROM document_links WHERE document_id = ? AND entity_type = 'purchase_invoice' AND entity_id = ?"
        );
        $link->execute([(int) $done['document_id'], $invoiceId]);
        self::assertNotFalse($link->fetchColumn(), 'Neměnný DMS originál musí zůstat připojený k výsledné faktuře.');
    }

    /**
     * Originál, který je byte po bytu PDF výsledné faktury, se k faktuře nepřipojí (detail by
     * ho ukázal podruhé vedle vlastního PDF dokladu) a ze „Příchozí doklady / rok / měsíc"
     * se přesune do archivu. Po smazání faktury se s podáním vrátí zpátky mezi čekající.
     */
    public function testIdenticalPdfOriginalIsNotLinkedAndMovesToArchive(): void
    {
        $requestId = $this->repo->create($this->supplierA, [
            'description' => 'Faktura v PDF', 'amount' => null,
            'context_date' => null, 'deadline' => null, 'bank_transaction_id' => null,
        ], $this->userId);
        $response = $this->buildPortalAction()->upload(
            $this->uploadRequest(
                $this->supplierA,
                $requestId,
                'faktura.pdf',
                "%PDF-1.4\nsynthetic-" . bin2hex(random_bytes(16)) . "\n%%EOF\n",
            ),
            (new ResponseFactory())->createResponse(),
            ['id' => (string) $requestId],
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $submissionId = (int) ($this->repo->find($requestId, $this->supplierA)['submission_id'] ?? 0);
        $submission = $this->submissions->find($submissionId, $this->supplierA);
        self::assertNotNull($submission);
        $this->trackSubmissionFile($submission);
        $documentId = (int) $submission['document_id'];
        self::assertSame(['Příchozí doklady', date('Y'), date('m')], $this->folderPath($documentId));

        $invoiceId = $this->createPurchaseInvoiceDraft($this->supplierA, 'DOCREQ-PDF-1');
        self::assertTrue($this->submissions->claimForManual($submissionId, $this->supplierA));
        $this->completion->complete($submissionId, $this->supplierA, $invoiceId, $this->userId, 'manual');

        $pdfHash = $this->db->pdo()->prepare('SELECT pdf_hash FROM purchase_invoices WHERE id = ?');
        $pdfHash->execute([$invoiceId]);
        self::assertSame((string) $submission['document_sha256'], (string) $pdfHash->fetchColumn(),
            'Předpoklad: PDF faktury je tentýž soubor jako originál.');
        $link = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM document_links WHERE document_id = ? AND entity_type = 'purchase_invoice' AND entity_id = ?"
        );
        $link->execute([$documentId, $invoiceId]);
        self::assertSame(0, (int) $link->fetchColumn(), 'Shodný originál se k faktuře podruhé nepřipojuje.');
        self::assertSame(['Příchozí doklady', 'Archiv', date('Y'), date('m')], $this->folderPath($documentId));

        $reopened = $this->submissions->reopenForDeletedInvoice($this->supplierA, $invoiceId);
        self::assertSame([$submissionId], $reopened);
        $this->filer->restore($this->supplierA, $reopened);
        self::assertSame(['Příchozí doklady', date('Y'), date('m')], $this->folderPath($documentId),
            'Podání vrácené do fronty má originál zase mezi čekajícími.');
    }

    /** @return list<string> názvy složek od kořene k dokumentu */
    private function folderPath(int $documentId): array
    {
        $pdo = $this->db->pdo();
        $doc = $pdo->prepare('SELECT folder_id FROM documents WHERE id = ?');
        $doc->execute([$documentId]);
        $folderId = $doc->fetchColumn();
        $folder = $pdo->prepare('SELECT parent_id, name FROM document_folders WHERE id = ?');
        $path = [];
        while ($folderId !== false && $folderId !== null) {
            $folder->execute([(int) $folderId]);
            $row = $folder->fetch(\PDO::FETCH_ASSOC);
            if ($row === false) break;
            array_unshift($path, (string) $row['name']);
            $folderId = $row['parent_id'];
        }
        return $path;
    }

    /**
     * Trello MCUAD #11: dompdf za PHP skriptem s mezerou pošle před hlavičku prázdné
     * řádky. Prohlížeč PDF otevře, fronta ho odmítala („Obsah souboru neodpovídá jeho
     * příponě"). Hlavička v prvních 1024 bajtech je podle ISO 32000 v pořádku a do
     * fronty jde PDF opravené, od `%PDF-`.
     */
    public function testPdfWithLeadingBlankLinesIsRepairedAndAccepted(): void
    {
        $pdf = "%PDF-1.7\n% synthetic shifted header " . bin2hex(random_bytes(8)) . "\n%%EOF\n";
        $result = $this->upload->submit(
            $this->uploadedFile('posunuta-hlavicka.pdf', "\n\n" . $pdf),
            $this->supplierA,
            $this->userId,
            'staff',
        );
        $this->trackSubmissionFile($result['submission']);

        self::assertFalse($result['duplicate']);
        self::assertSame(hash('sha256', $pdf), (string) $result['submission']['document_sha256'],
            'Uložený originál musí začínat hlavičkou %PDF-.');
    }

    public function testNonPdfWithPdfExtensionIsStillRejected(): void
    {
        $this->expectException(PurchaseInvoiceSubmissionException::class);
        $this->upload->submit(
            $this->uploadedFile('nejde-o-pdf.pdf', str_repeat(' ', 1100) . '%PDF-1.4 za oknem 1024 bajtů'),
            $this->supplierA,
            $this->userId,
            'staff',
        );
    }

    public function testExactDedupIsPerTenantAndUnchangedReplacementIsRejected(): void
    {
        $bytes = "%PDF-1.4\n% deterministic synthetic duplicate";
        $first = $this->upload->submit(
            $this->uploadedFile('duplicate.pdf', $bytes),
            $this->supplierA,
            $this->userId,
            'portal',
        );
        $duplicate = $this->upload->submit(
            $this->uploadedFile('duplicate-renamed.pdf', $bytes),
            $this->supplierA,
            $this->userId,
            'portal',
        );
        $otherTenant = $this->upload->submit(
            $this->uploadedFile('duplicate.pdf', $bytes),
            $this->supplierB,
            $this->userId,
            'portal',
        );

        self::assertFalse($first['duplicate']);
        self::assertTrue($duplicate['duplicate']);
        self::assertSame((int) $first['submission']['id'], (int) $duplicate['submission']['id']);
        self::assertFalse($otherTenant['duplicate']);
        self::assertNotSame((int) $first['submission']['id'], (int) $otherTenant['submission']['id']);
        self::assertNull($this->submissions->find((int) $first['submission']['id'], $this->supplierB));
        $this->trackSubmissionFile($first['submission']);
        $this->trackSubmissionFile($otherTenant['submission']);

        $firstId = (int) $first['submission']['id'];
        self::assertTrue($this->submissions->needsInformation($firstId, $this->supplierA, 'Nahrajte čitelnější verzi.'));
        try {
            $this->upload->submit(
                $this->uploadedFile('same-again.pdf', $bytes),
                $this->supplierA,
                $this->userId,
                'portal',
                supersedesSubmissionId: $firstId,
            );
            self::fail('Shodný soubor nesmí uzavřít požadavek na náhradu.');
        } catch (PurchaseInvoiceSubmissionException $e) {
            self::assertSame('replacement_unchanged', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
        }
    }

    public function testSubmissionFileEndpointIsTenantAndRoleScoped(): void
    {
        $bytes = "%PDF-1.4\n% tenant scoped synthetic preview";
        $created = $this->upload->submit(
            $this->uploadedFile('tenant-preview.pdf', $bytes),
            $this->supplierA,
            $this->userId,
            'portal',
        );
        $submission = $created['submission'];
        $submissionId = (int) $submission['id'];
        $this->trackSubmissionFile($submission);

        $action = new PurchaseInvoiceSubmissionFileAction($this->submissions, $this->storage);
        $responses = new ResponseFactory();
        $requests = new ServerRequestFactory();
        $portalRequest = $requests
            ->createServerRequest('GET', "/api/portal/purchase-invoice-submissions/{$submissionId}/preview")
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierA)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'client']);

        $preview = $action->portalPreview(
            $portalRequest,
            $responses->createResponse(),
            ['id' => (string) $submissionId],
        );
        self::assertSame(200, $preview->getStatusCode());
        self::assertSame('inline; filename="tenant-preview.pdf"', $preview->getHeaderLine('Content-Disposition'));
        self::assertSame($bytes, (string) $preview->getBody());

        $otherTenant = $action->portalPreview(
            $portalRequest->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierB),
            $responses->createResponse(),
            ['id' => (string) $submissionId],
        );
        self::assertSame(404, $otherTenant->getStatusCode(), 'Cizí tenant nesmí zjistit existenci souboru.');

        $staffAsClient = $action->staffPreview(
            $portalRequest,
            $responses->createResponse(),
            ['id' => (string) $submissionId],
        );
        self::assertSame(403, $staffAsClient->getStatusCode());

        $portalAsAccountant = $action->portalPreview(
            $portalRequest->withAttribute(AuthMiddleware::ATTR_USER, [
                'id' => $this->userId,
                'role' => 'accountant',
            ]),
            $responses->createResponse(),
            ['id' => (string) $submissionId],
        );
        self::assertSame(403, $portalAsAccountant->getStatusCode());
    }

    public function testReplacementRebindsOpenRequestAndCanOnlyBeSubmittedOnce(): void
    {
        $requestId = $this->repo->create($this->supplierA, [
            'description' => 'Nahraďte nečitelný doklad', 'amount' => null,
            'context_date' => null, 'deadline' => null, 'bank_transaction_id' => null,
        ], $this->userId);
        $first = $this->upload->submit(
            $this->uploadedFile('necitelný.pdf', "%PDF-1.4\n% first synthetic original"),
            $this->supplierA,
            $this->userId,
            'document_request',
            'Kontext původního požadavku',
            'invoice',
        );
        $firstId = (int) $first['submission']['id'];
        self::assertTrue($this->repo->markSubmitted($requestId, $this->supplierA, $firstId));
        self::assertTrue($this->submissions->needsInformation(
            $firstId,
            $this->supplierA,
            'Soubor je nečitelný.',
        ));
        $this->trackSubmissionFile($first['submission']);

        $replacement = $this->upload->submit(
            $this->uploadedFile('čitelný.pdf', "%PDF-1.4\n% replacement synthetic original"),
            $this->supplierA,
            $this->userId,
            'portal',
            supersedesSubmissionId: $firstId,
        );
        $replacementId = (int) $replacement['submission']['id'];
        $this->trackSubmissionFile($replacement['submission']);
        self::assertSame('Kontext původního požadavku', $replacement['submission']['note']);
        self::assertSame('invoice', $replacement['submission']['document_kind_hint']);

        $old = $this->submissions->find($firstId, $this->supplierA);
        self::assertSame($replacementId, (int) $old['replacement_submission_id']);
        $request = $this->repo->find($requestId, $this->supplierA);
        self::assertSame($replacementId, (int) $request['submission_id'],
            'Pull požadavek musí po náhradě sledovat nový originál.');

        try {
            $this->upload->submit(
                $this->uploadedFile('další.pdf', "%PDF-1.4\n% second replacement must fail"),
                $this->supplierA,
                $this->userId,
                'portal',
                supersedesSubmissionId: $firstId,
            );
            self::fail('Jedno podání nesmí dostat dvě souběžné náhrady.');
        } catch (PurchaseInvoiceSubmissionException $e) {
            self::assertSame('invalid_replacement', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
        }

        $invoiceId = $this->createPurchaseInvoiceDraft($this->supplierA, 'DOCREQ-REPLACEMENT-1');
        self::assertTrue($this->submissions->claimForManual($replacementId, $this->supplierA));
        $this->completion->complete(
            $replacementId,
            $this->supplierA,
            $invoiceId,
            $this->userId,
            'manual',
        );
        self::assertSame(
            $invoiceId,
            (int) $this->repo->find($requestId, $this->supplierA)['purchase_invoice_id'],
            'Výsledek náhradního souboru se musí propsat zpět do původního požadavku.',
        );
    }

    public function testBatchUploadConfirmsAcceptedFilesEvenWhenAnotherFileIsRejected(): void
    {
        $action = new PortalPurchaseInvoiceSubmissionAction(
            $this->submissions,
            $this->upload,
            $this->activity,
            $this->ipMatcher,
            $this->submissionDimensions,
            $this->buildDeletionService(),
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/portal/purchase-invoice-submissions')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierA)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'client'])
            ->withUploadedFiles(['file' => [
                $this->uploadedFile('prijatý.pdf', "%PDF-1.4\n% accepted in partial batch"),
                $this->uploadedFile('odmítnutý.exe', 'MZ synthetic rejected executable'),
            ]]);

        $response = $action->upload($request, (new ResponseFactory())->createResponse());
        self::assertSame(207, $response->getStatusCode(), (string) $response->getBody());
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $payload['created']);
        self::assertSame(0, $payload['duplicates']);
        self::assertCount(1, $payload['items']);
        self::assertCount(1, $payload['errors']);
        self::assertSame('unsupported_format', $payload['errors'][0]['code']);

        $submission = $this->submissions->find((int) $payload['items'][0]['id'], $this->supplierA);
        self::assertNotNull($submission);
        self::assertSame('submitted', $submission['status']);
        $this->trackSubmissionFile($submission);
    }

    /** Doklad mimo portál (e-mail, papír) musí do fronty dostat i účetní sama za sebe. */
    public function testStaffUploadsOriginalIntoQueueAndClientCannot(): void
    {
        $action = $this->buildInboxAction();
        $responses = new ResponseFactory();
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/purchase-invoice-submissions')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierA)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withParsedBody(['note' => 'Přišlo e-mailem', 'document_kind_hint' => 'receipt'])
            ->withUploadedFiles(['file' => [
                $this->uploadedFile('od-dodavatele.pdf', "%PDF-1.4\n% staff uploaded original"),
            ]]);

        $response = $action->upload($request, $responses->createResponse());
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $payload['created']);

        $submission = $this->submissions->find((int) $payload['items'][0]['id'], $this->supplierA);
        self::assertNotNull($submission);
        $this->trackSubmissionFile($submission);
        self::assertSame('staff', $submission['submitted_via']);
        self::assertSame('submitted', $submission['status'], 'Nahraný originál čeká ve frontě, ne v účetnictví.');
        self::assertSame('receipt', $submission['document_kind_hint']);
        self::assertSame('Přišlo e-mailem', $submission['note']);
        self::assertNull($submission['purchase_invoice_id']);

        $queue = $this->submissions->paginate($this->supplierA, 'submitted', 50, 0);
        self::assertContains(
            (int) $submission['id'],
            array_map('intval', array_column($queue['items'], 'id')),
            'Nahraný doklad se musí objevit ve frontě příchozích dokladů.',
        );

        $asClient = $action->upload(
            $request->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'client']),
            $responses->createResponse(),
        );
        self::assertSame(403, $asClient->getStatusCode(), 'Klient má vlastní portálovou cestu, do účetní fronty nesahá.');
    }

    /**
     * Odmítnutí je jen stav — originál musí přežít. Trvale ho vyřadí až samostatné
     * právo, a to jen dokud podání nedrží vazbu na existující fakturu.
     */
    public function testRejectKeepsOriginalWhileDeleteRemovesItFromQueue(): void
    {
        $action = $this->buildInboxAction();
        $responses = new ResponseFactory();
        $created = $this->upload->submit(
            $this->uploadedFile('omylem.jpg', "\xFF\xD8\xFF synthetic photo " . bin2hex(random_bytes(8))),
            $this->supplierA,
            $this->userId,
            'staff',
        );
        $submission = $created['submission'];
        $submissionId = (int) $submission['id'];
        $documentId = (int) $submission['document_id'];
        $this->trackSubmissionFile($submission);

        $rejected = $action->reject(
            $this->inboxRequest('POST', "/api/purchase-invoice-submissions/{$submissionId}/reject")->withParsedBody(['reason' => 'Nesprávný originál.']),
            $responses->createResponse(),
            ['id' => (string) $submissionId],
        );
        self::assertSame(200, $rejected->getStatusCode(), (string) $rejected->getBody());
        self::assertSame('rejected', $this->submissions->find($submissionId, $this->supplierA)['status']);
        self::assertNull(
            $this->documentDeletedAt($documentId),
            'Odmítnutí nesmí originál smazat — je součástí auditní stopy.',
        );

        // Účetní bez práva na trvalé vyřazení narazí, i když frontu jinak obsluhuje.
        $withoutRight = $action->delete(
            $this->inboxRequest('DELETE', "/api/purchase-invoice-submissions/{$submissionId}", 'accountant'),
            $responses->createResponse(),
            ['id' => (string) $submissionId],
        );
        self::assertSame(403, $withoutRight->getStatusCode(), (string) $withoutRight->getBody());
        self::assertNotNull($this->submissions->find($submissionId, $this->supplierA));

        $deleted = $action->delete(
            $this->inboxRequest('DELETE', "/api/purchase-invoice-submissions/{$submissionId}"),
            $responses->createResponse(),
            ['id' => (string) $submissionId],
        );
        self::assertSame(200, $deleted->getStatusCode(), (string) $deleted->getBody());
        self::assertNull($this->submissions->find($submissionId, $this->supplierA));
        self::assertNotNull(
            $this->documentDeletedAt($documentId),
            'Originál musí skončit v koši Dokumentů, odkud ho vysypání smaže i z disku.',
        );
    }

    /** @return string|null `deleted_at` originálu, null když je aktivní */
    private function documentDeletedAt(int $documentId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT deleted_at FROM documents WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$documentId, $this->supplierA]);
        $value = $stmt->fetchColumn();
        return is_string($value) ? $value : null;
    }

    /** Zpracované podání drží vazbu na fakturu, takže se z fronty vyřadit nedá. */
    public function testProcessedSubmissionCannotBeDeleted(): void
    {
        $created = $this->upload->submit(
            $this->uploadedFile('zpracovany.pdf', "%PDF-1.4\n% processed " . bin2hex(random_bytes(8))),
            $this->supplierA,
            $this->userId,
            'staff',
        );
        $submissionId = (int) $created['submission']['id'];
        $this->trackSubmissionFile($created['submission']);
        $invoiceId = $this->createPurchaseInvoiceDraft($this->supplierA, 'DOCREQ-DELETE-GUARD-1');
        self::assertTrue($this->submissions->claimForManual($submissionId, $this->supplierA));
        $this->completion->complete($submissionId, $this->supplierA, $invoiceId, $this->userId, 'manual');

        $response = $this->buildInboxAction()->delete(
            $this->inboxRequest('DELETE', "/api/purchase-invoice-submissions/{$submissionId}"),
            (new ResponseFactory())->createResponse(),
            ['id' => (string) $submissionId],
        );
        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('invalid_status', $payload['error']['code'] ?? null);
        self::assertNotNull($this->submissions->find($submissionId, $this->supplierA));
    }

    public function testSuperadminCanUseDocumentSubmissionPortalWithinCurrentSupplier(): void
    {
        $action = new PortalPurchaseInvoiceSubmissionAction($this->submissions, $this->upload, $this->activity, $this->ipMatcher, $this->submissionDimensions, $this->buildDeletionService());
        $request = $this->inboxRequest('GET', '/api/portal/purchase-invoice-submissions')
            ->withAttribute('auth.effective_role', new EffectiveRole(1, 'Superadmin', 'superadmin', true, [], 'superadmin'));
        $responses = new ResponseFactory();
        self::assertSame(200, $action->list($request, $responses->createResponse())->getStatusCode());
        self::assertSame(200, $action->dimensions($request, $responses->createResponse())->getStatusCode());
        self::assertSame(200, $this->buildPortalAction()->list($request, $responses->createResponse())->getStatusCode());

        $bytes = "%PDF-1.4\n% synthetic superadmin submission";
        $response = $action->upload($request->withUploadedFiles(['file' => $this->uploadedFile('superadmin.pdf', $bytes)]), $responses->createResponse());
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $id = (int) $payload['items'][0]['id'];
        $submission = $this->submissions->find($id, $this->supplierA);
        $this->trackSubmissionFile($submission);
        self::assertSame('submitted', $submission['status']);
        self::assertNull($submission['purchase_invoice_id']);

        $files = new PurchaseInvoiceSubmissionFileAction($this->submissions, $this->storage);
        foreach (['portalPreview', 'portalDownload'] as $method) {
            $response = $files->$method($request, $responses->createResponse(), ['id' => (string) $id]);
            self::assertSame(200, $response->getStatusCode());
            self::assertSame($bytes, (string) $response->getBody());
            self::assertSame(404, $files->$method($request->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierB), $responses->createResponse(), ['id' => (string) $id])->getStatusCode());
        }

        self::assertTrue($this->submissions->needsInformation($id, $this->supplierA, 'Nahraďte originál.'));
        $response = $action->resubmit($request->withUploadedFiles(['file' => $this->uploadedFile('replacement.pdf', "%PDF-1.4\n% synthetic superadmin replacement")]), $responses->createResponse(), ['id' => (string) $id]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->trackSubmissionFile($this->submissions->find((int) $payload['items'][0]['id'], $this->supplierA));

        $foreign = $this->repo->create($this->supplierB, ['description' => 'Foreign synthetic request'], $this->userId);
        self::assertSame(404, $this->buildPortalAction()->upload($request, $responses->createResponse(), ['id' => (string) $foreign])->getStatusCode());
        $own = $this->repo->create($this->supplierA, ['description' => 'Own synthetic request'], $this->userId);
        $response = $this->buildPortalAction()->upload($request->withUploadedFiles(['file' => $this->uploadedFile('requested.pdf', "%PDF-1.4\n% synthetic requested superadmin upload")]), $responses->createResponse(), ['id' => (string) $own]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $row = $this->repo->find($own, $this->supplierA);
        $this->trackSubmissionFile($this->submissions->find((int) $row['submission_id'], $this->supplierA));
    }

    public function testStaffUsesDocumentPortalOnlyWithSubmissionPermission(): void
    {
        $action = new PortalPurchaseInvoiceSubmissionAction($this->submissions, $this->upload, $this->activity, $this->ipMatcher, $this->submissionDimensions, $this->buildDeletionService());
        $files = new PurchaseInvoiceSubmissionFileAction($this->submissions, $this->storage);
        $responses = new ResponseFactory();
        // Bez práva `documents.submit` portál nepomůže ani široké interní právo k dokumentům.
        foreach (['admin', 'admin_plus', 'accountant', 'readonly'] as $key) {
            $request = $this->inboxRequest('GET', '/api/portal/purchase-invoice-submissions')
                ->withAttribute('auth.effective_role', new EffectiveRole(2, $key, 'staff', true, ['documents' => 2, 'documents.inbox' => 2, 'documents.requests' => 2], $key));
            self::assertSame(403, $action->list($request, $responses->createResponse())->getStatusCode(), $key);
            self::assertSame(403, $action->upload($request, $responses->createResponse())->getStatusCode(), $key);
            self::assertSame(403, $files->portalPreview($request, $responses->createResponse(), ['id' => '1'])->getStatusCode(), $key);
            self::assertSame(403, $this->buildPortalAction()->list($request, $responses->createResponse())->getStatusCode(), $key);
        }
        // Interní role s právem čte stejný přehled jako klient, zápis jen s úrovní zápisu.
        $reader = $this->inboxRequest('GET', '/api/portal/purchase-invoice-submissions')
            ->withAttribute('auth.effective_role', new EffectiveRole(2, 'Fakturace a dokumenty', 'staff', true, ['documents.submit' => AccessLevel::READ->value]));
        self::assertSame(200, $action->list($reader, $responses->createResponse())->getStatusCode());
        self::assertSame(200, $this->buildPortalAction()->list($reader, $responses->createResponse())->getStatusCode());
        self::assertSame(403, $action->upload($reader, $responses->createResponse())->getStatusCode());
        self::assertSame(404, $files->portalPreview($reader, $responses->createResponse(), ['id' => '999999999'])->getStatusCode());
    }

    public function testPortalNeverServesInternalProcessingDiagnostics(): void
    {
        $created = $this->upload->submit(
            $this->uploadedFile('diagnostika.pdf', "%PDF-1.4\n% synthetic portal projection"),
            $this->supplierA,
            $this->userId,
            'portal',
        );
        $submission = $created['submission'];
        $submissionId = (int) $submission['id'];
        $this->trackSubmissionFile($submission);
        self::assertTrue($this->submissions->claimForExtraction($submissionId, $this->supplierA));
        $this->submissions->extractionFailed(
            $submissionId,
            $this->supplierA,
            'ai',
            'SQLSTATE[HY000]: interní hláška s cestou C:\\inetpub\\storage',
        );
        self::assertTrue($this->submissions->needsInformation(
            $submissionId,
            $this->supplierA,
            'Doklad je nečitelný, pošlete ho prosím znovu.',
        ));

        $action = new PortalPurchaseInvoiceSubmissionAction(
            $this->submissions,
            $this->upload,
            $this->activity,
            $this->ipMatcher,
            $this->submissionDimensions,
            $this->buildDeletionService(),
        );
        $response = $action->list(
            (new ServerRequestFactory())
                ->createServerRequest('GET', '/api/portal/purchase-invoice-submissions')
                ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierA)
                ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'client']),
            (new ResponseFactory())->createResponse(),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $row = null;
        foreach ($payload['items'] as $item) {
            if ((int) $item['id'] === $submissionId) $row = $item;
        }
        self::assertIsArray($row, 'Klient musí své podání ve frontě vidět.');
        self::assertSame(
            'Doklad je nečitelný, pošlete ho prosím znovu.',
            $row['status_reason'],
            'Důvod, proč po klientovi účetní něco chce, je naopak jediná užitečná zpráva.',
        );
        foreach (['extraction_error', 'extraction_source', 'extraction_status',
            'document_sha256', 'document_filename', 'processed_by_name'] as $field) {
            self::assertArrayNotHasKey(
                $field,
                $row,
                "Portál nesmí klientovi servírovat interní diagnostiku ($field).",
            );
        }
    }

    public function testUploadOnRequestOfOtherSupplierIsNotFound(): void
    {
        // Požadavek patří firmě B; klient přihlášený jako firma A ho nesmí ani vidět, ani ovlivnit.
        $idB = $this->repo->create($this->supplierB, [
            'description' => 'Cizí požadavek', 'amount' => null,
            'context_date' => null, 'deadline' => null, 'bank_transaction_id' => null,
        ], $this->userId);

        $action = $this->buildPortalAction();
        $request = $this->uploadRequest($this->supplierA, $idB);
        $response = $action->upload($request, (new ResponseFactory())->createResponse(), ['id' => (string) $idB]);

        self::assertSame(404, $response->getStatusCode(), 'Cizí požadavek musí vrátit 404 (fail-closed, ne 403 — neprozrazuje existenci).');

        // Ověř, že se přes cizí upload nic nezměnilo.
        $stillRequested = $this->repo->find($idB, $this->supplierB);
        self::assertSame('requested', $stillRequested['status']);
    }

    public function testListForSupplierOnlyReturnsOwnRequestsTenantIsolation(): void
    {
        $idA = $this->repo->create($this->supplierA, [
            'description' => 'Firma A doklad', 'amount' => null,
            'context_date' => null, 'deadline' => null, 'bank_transaction_id' => null,
        ], $this->userId);
        $idB = $this->repo->create($this->supplierB, [
            'description' => 'Firma B doklad', 'amount' => null,
            'context_date' => null, 'deadline' => null, 'bank_transaction_id' => null,
        ], $this->userId);

        $listA = $this->repo->listForSupplier($this->supplierA);
        $listB = $this->repo->listForSupplier($this->supplierB);

        $idsA = array_column($listA, 'id');
        $idsB = array_column($listB, 'id');
        self::assertContains($idA, $idsA);
        self::assertNotContains($idB, $idsA, 'Firma A nesmí vidět požadavky firmy B.');
        self::assertContains($idB, $idsB);
        self::assertNotContains($idA, $idsB, 'Firma B nesmí vidět požadavky firmy A.');

        // find() musí být stejně tenant-scoped.
        self::assertNull($this->repo->find($idB, $this->supplierA));
        self::assertNull($this->repo->find($idA, $this->supplierB));
    }

    public function testResolveAndReopenTransitions(): void
    {
        $id = $this->repo->create($this->supplierA, [
            'description' => 'K vyřízení', 'amount' => null,
            'context_date' => null, 'deadline' => null, 'bank_transaction_id' => null,
        ], $this->userId);

        self::assertTrue($this->repo->resolve($id, $this->supplierA, $this->userId));
        $resolved = $this->repo->find($id, $this->supplierA);
        self::assertSame('resolved', $resolved['status']);
        self::assertNotNull($resolved['resolved_at']);
        self::assertSame($this->userId, (int) $resolved['resolved_by']);

        // Druhé resolve je no-op (žádný řádek ve stavu != resolved).
        self::assertFalse($this->repo->resolve($id, $this->supplierA, $this->userId));

        self::assertTrue($this->repo->reopen($id, $this->supplierA));
        $reopened = $this->repo->find($id, $this->supplierA);
        self::assertSame('requested', $reopened['status']);
        self::assertNull($reopened['resolved_at']);

        // Cizí firma nesmí resolve/reopen ovlivnit vůbec (tenant scope v UPDATE WHERE).
        self::assertFalse($this->repo->resolve($id, $this->supplierB, $this->userId));
    }

    public function testOpenCountsBadgeForDashboards(): void
    {
        $this->repo->create($this->supplierA, [
            'description' => 'Bez termínu', 'amount' => null,
            'context_date' => null, 'deadline' => null, 'bank_transaction_id' => null,
        ], $this->userId);
        $overdueId = $this->repo->create($this->supplierA, [
            'description' => 'Po termínu', 'amount' => null,
            'context_date' => null, 'deadline' => '2000-01-01', 'bank_transaction_id' => null,
        ], $this->userId);
        $resolvedId = $this->repo->create($this->supplierA, [
            'description' => 'Vyřízeno', 'amount' => null,
            'context_date' => null, 'deadline' => null, 'bank_transaction_id' => null,
        ], $this->userId);
        $this->repo->resolve($resolvedId, $this->supplierA, $this->userId);

        $counts = $this->repo->openCounts($this->supplierA);
        self::assertSame(2, $counts['open'], 'Vyřízený požadavek se do open nepočítá.');
        self::assertSame(1, $counts['overdue']);

        // Jiná firma vidí nulu — badge nesmí prosakovat mezi tenanty.
        $countsB = $this->repo->openCounts($this->supplierB);
        self::assertSame(0, $countsB['open']);
        self::assertSame(0, $countsB['overdue']);
    }

    public function testDeleteRemovesRequestTenantScoped(): void
    {
        $id = $this->repo->create($this->supplierA, [
            'description' => 'Ke smazání', 'amount' => null,
            'context_date' => null, 'deadline' => null, 'bank_transaction_id' => null,
        ], $this->userId);

        self::assertFalse($this->repo->delete($id, $this->supplierB), 'Cizí firma nesmí smazat požadavek jiné firmy.');
        self::assertNotNull($this->repo->find($id, $this->supplierA));

        self::assertTrue($this->repo->delete($id, $this->supplierA));
        self::assertNull($this->repo->find($id, $this->supplierA));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function buildPortalAction(): PortalDocumentRequestAction
    {
        return new PortalDocumentRequestAction($this->repo, $this->upload, $this->activity, $this->ipMatcher);
    }

    private function buildDeletionService(): PurchaseInvoiceSubmissionDeletionService
    {
        return new PurchaseInvoiceSubmissionDeletionService($this->db, $this->submissions, $this->documents, $this->repo, $this->activity);
    }

    private function buildInboxAction(): PurchaseInvoiceSubmissionAction
    {
        $container = Bootstrap::buildContainer();
        return new PurchaseInvoiceSubmissionAction(
            $this->submissions,
            $this->processing,
            $this->upload,
            $this->documents,
            $this->db,
            $this->activity,
            $this->ipMatcher,
            $this->submissionDimensions,
            $this->buildDeletionService(),
            $container->get(\MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceSubmissionTransferService::class),
            $container->get(\MyInvoice\Security\CrossSupplierAuthorization::class),
            $container->get(\MyInvoice\Service\Import\DocumentAddresseeResolver::class),
            $this->storage,
        );
    }

    /** @return \Psr\Http\Message\ServerRequestInterface */
    private function inboxRequest(string $method, string $path, string $role = 'admin')
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierA)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role]);
    }

    private function uploadRequest(
        int $supplierId,
        int $requestId,
        string $name = 'doklad.pdf',
        ?string $contents = null,
    ): \Psr\Http\Message\ServerRequestInterface
    {
        $uploaded = $this->uploadedFile(
            $name,
            $contents ?? ("%PDF-1.4\n% synthetic " . bin2hex(random_bytes(16))),
        );

        return (new ServerRequestFactory())
            ->createServerRequest('POST', "/api/portal/document-requests/{$requestId}/upload")
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'client'])
            ->withUploadedFiles(['file' => $uploaded]);
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
