<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollXmlzamCooperationAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Deletion\DocumentDeletionGuard;
use MyInvoice\Repository\DocumentFileRepository;
use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Repository\Payroll\XmlzamCooperationRepository;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Document\DocumentStorage;
use MyInvoice\Service\Payroll\Garnishment\Xmlzam\XmlzamCooperationFlowService;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Service\Submission\SubmissionInboxPrivacyService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class XmlzamCooperationFlowTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $database;
    private PDO $pdo;
    private XmlzamCooperationFlowService $flow;
    private PayrollXmlzamCooperationAction $action;
    private DocumentStorage $storage;
    private DocumentRepository $documents;
    private DocumentFileRepository $files;
    private DocumentDeletionGuard $deletionGuard;
    private SubmissionInboxPrivacyService $privacy;
    private PayrollSensitiveData $sensitive;
    private int $supplierId;
    private int $otherSupplierId;
    private int $userId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->database = $container->get(Connection::class);
        $this->pdo = $this->database->pdo();
        $this->pdo->beginTransaction();
        $sourceSupplier = (int) $this->pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->supplierId = $this->createIsolatedSupplier($this->pdo, $sourceSupplier);
        $this->otherSupplierId = $this->createIsolatedSupplier($this->pdo, $sourceSupplier);
        $this->userId = (int) $this->pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        $this->pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)')
            ->execute([$this->supplierId, $this->otherSupplierId]);
        $this->flow = $container->get(XmlzamCooperationFlowService::class);
        $this->action = $container->get(PayrollXmlzamCooperationAction::class);
        $this->storage = $container->get(DocumentStorage::class);
        $this->documents = $container->get(DocumentRepository::class);
        $this->files = $container->get(DocumentFileRepository::class);
        $this->deletionGuard = $container->get(DocumentDeletionGuard::class);
        $this->privacy = $container->get(SubmissionInboxPrivacyService::class);
        $this->sensitive = $container->get(PayrollSensitiveData::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testFlowIsRegisteredAndUsesDedicatedStorage(): void
    {
        $container = Bootstrap::buildContainer();
        $database = $container->get(Connection::class);

        self::assertInstanceOf(XmlzamCooperationFlowService::class, $container->get(XmlzamCooperationFlowService::class));
        self::assertInstanceOf(XmlzamCooperationRepository::class, $container->get(XmlzamCooperationRepository::class));
        self::assertTrue($database->hasTable('payroll_enforcement_xmlzam_requests'));
        self::assertTrue($database->hasTable('payroll_enforcement_xmlzam_responses'));
    }

    public function testCandidateEndpointListsOnlyOwnPendingXmlMetadataWithoutTrustingContent(): void
    {
        [$inboxId, $fileId] = $this->source(
            '<?xml version="1.0" encoding="UTF-8"?><synthetic-not-an-xmlzam/>',
            'abc1234',
        );
        [$foreignInboxId] = $this->sourceForSupplier(
            $this->otherSupplierId,
            '<?xml version="1.0" encoding="UTF-8"?><synthetic-foreign-not-an-xmlzam/>',
            'abc1234',
        );

        $readOnlyRole = new EffectiveRole(
            901,
            'Syntetické čtení součinnosti',
            'staff',
            true,
            ['payroll.enforcement.cooperation' => AccessLevel::READ->value],
        );
        $response = $this->action->candidates(
            $this->apiRequest(
                'GET',
                '/api/payroll/enforcement/cooperation/candidates',
                $this->supplierId,
                ['environment' => 'test'],
            )->withAttribute('auth.effective_role', $readOnlyRole),
            new Response(),
        );
        $body = $this->jsonResponse($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $body['candidates']);
        self::assertSame($inboxId, $body['candidates'][0]['inbox_message_id']);
        self::assertSame($fileId, $body['candidates'][0]['document_file_id']);
        self::assertArrayNotHasKey('content', $body['candidates'][0]);
        self::assertArrayNotHasKey('employee_id', $body['candidates'][0]);
        self::assertNotSame($foreignInboxId, $body['candidates'][0]['inbox_message_id']);

        $this->employee(true);
        [$processedInboxId, $processedFileId] = $this->source(self::requestXml(), 'abc1234');
        $this->flow->import(
            $this->supplierId,
            'test',
            $processedInboxId,
            $processedFileId,
            $this->userId,
        );
        $afterImport = $this->jsonResponse($this->action->candidates(
            $this->apiRequest(
                'GET',
                '/api/payroll/enforcement/cooperation/candidates',
                $this->supplierId,
                ['environment' => 'test'],
            ),
            new Response(),
        ));
        self::assertCount(1, $afterImport['candidates']);
        self::assertNotSame($processedInboxId, $afterImport['candidates'][0]['inbox_message_id']);
    }

    public function testRequestDetailReturnsVerifiedScopesEmployeeAndOnlyExactActiveRecipient(): void
    {
        $employeeId = $this->employee(true);
        [$inboxId, $fileId] = $this->source(self::requestXml(), 'abc1234');
        $imported = $this->flow->import($this->supplierId, 'test', $inboxId, $fileId, $this->userId);
        $this->insertRecipient('xmlzam_inactive_' . $employeeId, 'abc1234', false);
        $this->insertRecipient('xmlzam_wrong_' . $employeeId, 'zzz9999', true);

        $detailResponse = $this->action->detail(
            $this->apiRequest(
                'GET',
                '/api/payroll/enforcement/cooperation/requests/' . $imported['id'],
                $this->supplierId,
                ['environment' => 'test'],
            ),
            new Response(),
            ['id' => (string) $imported['id']],
        );
        $detail = $this->jsonResponse($detailResponse)['request'];
        self::assertSame($employeeId, $detail['employee']['id']);
        self::assertSame('Synthetic Employee', $detail['employee']['full_name']);
        self::assertSame(
            ['vyse_srazek', 'trvani_praconiho_pomeru', 'poradi_exekuce'],
            $detail['requested_scopes'],
        );
        self::assertSame('EX 123456/26', $detail['case_reference']);
        self::assertNull($detail['recipient']);
        self::assertSame('missing', $detail['recipient_match_status']);
        self::assertStringNotContainsString('900101', json_encode($detail, JSON_THROW_ON_ERROR));

        $exactRecipientId = $this->insertRecipient('xmlzam_exact_' . $employeeId, 'abc1234', true);
        $matchedResponse = $this->action->detail(
            $this->apiRequest(
                'GET',
                '/api/payroll/enforcement/cooperation/requests/' . $imported['id'],
                $this->supplierId,
                ['environment' => 'test'],
            ),
            new Response(),
            ['id' => (string) $imported['id']],
        );
        $matched = $this->jsonResponse($matchedResponse)['request'];
        self::assertSame('matched', $matched['recipient_match_status']);
        self::assertSame($exactRecipientId, $matched['recipient']['id']);
        self::assertSame('abc1234', $matched['recipient']['isds_box_id']);

        $this->insertRecipient('xmlzam_ambiguous_' . $employeeId, 'abc1234', true);
        $ambiguousResponse = $this->action->detail(
            $this->apiRequest(
                'GET',
                '/api/payroll/enforcement/cooperation/requests/' . $imported['id'],
                $this->supplierId,
                ['environment' => 'test'],
            ),
            new Response(),
            ['id' => (string) $imported['id']],
        );
        $ambiguous = $this->jsonResponse($ambiguousResponse)['request'];
        self::assertSame('ambiguous', $ambiguous['recipient_match_status']);
        self::assertNull($ambiguous['recipient']);

        $foreignResponse = $this->action->detail(
            $this->apiRequest(
                'GET',
                '/api/payroll/enforcement/cooperation/requests/' . $imported['id'],
                $this->otherSupplierId,
                ['environment' => 'test'],
            ),
            new Response(),
            ['id' => (string) $imported['id']],
        );
        self::assertSame(404, $foreignResponse->getStatusCode());
    }

    public function testCleanInstallAndUpgradeGuardUseBinaryManifestComparisons(): void
    {
        $root = dirname(__DIR__, 4);
        $schema = file_get_contents($root . '/db/migrations/1583_payroll_enforcement_xmlzam_cooperation.sql');
        $cleanInstall = file_get_contents($root . '/db/migrations/1584_payroll_enforcement_xmlzam_guards.sql');
        $upgrade = file_get_contents($root . '/db/migrations/1585_payroll_enforcement_xmlzam_guard_collation.sql');
        $manifestUpgrade = file_get_contents($root . '/db/migrations/1586_payroll_enforcement_xmlzam_manifest_integrity.sql');
        self::assertIsString($schema);
        self::assertIsString($cleanInstall);
        self::assertIsString($upgrade);
        self::assertIsString($manifestUpgrade);
        foreach ([
            'BINARY revision.input_snapshot_hash = BINARY manifest.input_hash',
            'BINARY revision.result_snapshot_hash = BINARY manifest.result_hash',
            "BINARY DATE_FORMAT(run_row.period_start, '%Y-%m') = BINARY manifest.period_start",
            'BINARY enforcement.input_snapshot_hash = BINARY manifest.enforcement_input_hash',
        ] as $guard) {
            self::assertStringContainsString($guard, $cleanInstall);
            self::assertStringContainsString($guard, $upgrade);
        }
        self::assertStringContainsString('SHA2(NEW.source_manifest_json, 256)', $cleanInstall);
        self::assertStringContainsString('SHA2(NEW.source_manifest_json, 256)', $manifestUpgrade);
        self::assertStringContainsString('includes_wages', $schema);
        self::assertStringContainsString('NEW.includes_wages = 0 AND manifest_rows <> 0', $manifestUpgrade);
    }

    public function testImportRejectsCrossTenantAndNonChildFile(): void
    {
        $employeeId = $this->employee(true);
        [$inboxId, $fileId] = $this->source(self::requestXml(), 'abc1234');

        try {
            $this->flow->import($this->otherSupplierId, 'test', $inboxId, $fileId, $this->userId);
            self::fail('Cross-tenant import must fail.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('přílohy konkrétní', $e->getMessage());
        }

        $stored = $this->storage->storeZfoAttachmentFromBytes(
            self::requestXml(),
            $this->supplierId,
            'detached.xml',
            'application/xml',
        );
        $detachedDocument = $this->insertDocument($stored, null, 'manual', 'detached.xml');
        $detachedFile = $this->insertFile($stored, $detachedDocument, 'detached.xml');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('přílohy konkrétní');
        $this->flow->import($this->supplierId, 'test', $inboxId, $detachedFile, $this->userId);
        self::assertGreaterThan(0, $employeeId);
    }

    public function testImportRejectsForgedSenderAndAmbiguousIdentity(): void
    {
        $this->employee(true);
        [$inboxId, $fileId] = $this->source(self::requestXml(), 'zzz9999');
        try {
            $this->flow->import($this->supplierId, 'test', $inboxId, $fileId, $this->userId);
            self::fail('Forged sender must fail.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('odesílatele', $e->getMessage());
        }

        $this->employee(false);
        $this->employee(false);
        [$ambiguousInbox, $ambiguousFile] = $this->source(
            str_replace(
                ['123-12345678-A1', '900101/0007'],
                ['123-12345678-A2', '900101/0015'],
                self::requestXml(),
            ),
            'abc1234',
        );
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('nejednoznačná');
        $this->flow->import($this->supplierId, 'test', $ambiguousInbox, $ambiguousFile, $this->userId);
    }

    public function testImportedSourceDocumentIsRegisteredAsDeletionBlocker(): void
    {
        $this->employee(true);
        [$inboxId, $fileId] = $this->source(self::requestXml(), 'abc1234');
        $request = $this->flow->import($this->supplierId, 'test', $inboxId, $fileId, $this->userId);
        $sourceDocumentId = (int) $this->pdo->query(
            'SELECT source_document_id FROM payroll_enforcement_xmlzam_requests WHERE id = ' . (int) $request['id'],
        )->fetchColumn();
        self::assertTrue($this->documents->softDelete($sourceDocumentId, $this->supplierId, $this->userId));

        $blocked = $this->deletionGuard->blockedTrashDocuments($this->supplierId, [$sourceDocumentId]);

        self::assertArrayHasKey($sourceDocumentId, $blocked);
        self::assertStringContainsString('XMLZAM', $blocked[$sourceDocumentId]->message);
    }

    public function testImportedSourceFileAndDocumentCannotBePhysicallyDeleted(): void
    {
        $this->employee(true);
        [$inboxId, $fileId] = $this->source(self::requestXml(), 'abc1234');
        $request = $this->flow->import($this->supplierId, 'test', $inboxId, $fileId, $this->userId);
        $sourceDocumentId = (int) $this->pdo->query(
            'SELECT source_document_id FROM payroll_enforcement_xmlzam_requests WHERE id = ' . (int) $request['id'],
        )->fetchColumn();

        foreach ([
            ['document_files', $fileId],
            ['documents', $sourceDocumentId],
        ] as [$table, $id]) {
            try {
                $this->pdo->exec('DELETE FROM ' . $table . ' WHERE id = ' . $id);
                self::fail("XMLZAM source in {$table} must be protected by a foreign key.");
            } catch (\PDOException $e) {
                self::assertSame('23000', (string) $e->getCode());
            }
        }
    }

    public function testImportedRequestBlocksInboxHideAndPrivacyPurge(): void
    {
        $this->employee(true);
        [$inboxId, $fileId] = $this->source(self::requestXml(), 'abc1234');
        $this->flow->import($this->supplierId, 'test', $inboxId, $fileId, $this->userId);

        foreach (['hide', 'purgeLocalContent'] as $operation) {
            try {
                $this->privacy->{$operation}($this->supplierId, $inboxId, 1, $this->userId);
                self::fail("Imported XMLZAM inbox message must block {$operation}.");
            } catch (SubmissionChannelException $e) {
                self::assertSame('isds_inbox_message_has_business_link', $e->errorCode);
                self::assertSame(409, $e->httpStatus);
            }
        }
        $stored = $this->pdo->query(
            'SELECT hidden_at, local_content_state, lifecycle_row_version
               FROM submission_inbox_messages WHERE id = ' . $inboxId,
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($stored);
        self::assertNull($stored['hidden_at']);
        self::assertSame('available', $stored['local_content_state']);
        self::assertSame(1, (int) $stored['lifecycle_row_version']);
    }

    public function testImportIsIdempotentAndDraftPayrollPeriodIsBlocked(): void
    {
        $employeeId = $this->employee(true);
        [$inboxId, $fileId] = $this->source(self::requestXml(), 'abc1234');
        $first = $this->flow->import($this->supplierId, 'test', $inboxId, $fileId, $this->userId);
        $second = $this->flow->import($this->supplierId, 'test', $inboxId, $fileId, $this->userId);
        self::assertTrue($first['created']);
        self::assertFalse($second['created']);
        self::assertSame($first['id'], $second['id']);
        $storedRequest = $this->pdo->query(
            'SELECT snapshot_ciphertext FROM payroll_enforcement_xmlzam_requests WHERE id = ' . (int) $first['id'],
        )->fetchColumn();
        self::assertIsString($storedRequest);
        self::assertStringStartsWith('enc:v2:', $storedRequest);
        self::assertStringNotContainsString('<dokument', $storedRequest);

        $caseStmt = $this->pdo->prepare(
            "INSERT INTO payroll_enforcement_cases
                (supplier_id, employee_id, case_key, case_kind, status, effective_from,
                 evidence_complete, recipient_verified, created_by, updated_by)
             VALUES (?, ?, ?, 'enforcement', 'withhold_and_hold', '2026-01-01', 1, 1, ?, ?)"
        );
        $caseStmt->execute([$this->supplierId, $employeeId, 'xmlzam-case-' . $employeeId, $this->userId, $this->userId]);
        $caseId = (int) $this->pdo->lastInsertId();
        $claimStmt = $this->pdo->prepare(
            "INSERT INTO payroll_enforcement_claims
                (supplier_id, case_id, claim_key, enforcement_order_key, legal_basis, category,
                 outstanding_minor_units, priority_date, first_payer_delivered_on, order_issued_on, legal_title_verified,
                 order_or_notice_delivered, priority_classification_verified,
                 agreement_verified, due_monetary_claim_verified, is_active)
             VALUES (?, ?, ?, ?, 'statutory', 'non_priority', 100000, '2026-04-03', '2026-04-03',
                     '2026-04-01', 1, 1, 1, 0, 1, 1)"
        );
        $claimStmt->execute([$this->supplierId, $caseId, 'xmlzam-claim-' . $employeeId, 'xmlzam-order-' . $employeeId]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('nemá schválenou');
        $this->flow->preview($this->supplierId, 'test', $first['id'], $caseId, ['2026-07']);
    }

    public function testPreviewRejectsRequestSnapshotWithMismatchedFingerprintContext(): void
    {
        $employeeId = $this->employee(true);
        [$inboxId, $fileId] = $this->source(self::requestXml(), 'abc1234');
        $imported = $this->flow->import($this->supplierId, 'test', $inboxId, $fileId, $this->userId);
        [$secondInboxId, $secondFileId] = $this->source(self::requestXml(), 'abc1234');
        $secondDocumentId = (int) $this->pdo->query(
            'SELECT document_id FROM document_files WHERE id = ' . $secondFileId,
        )->fetchColumn();
        $this->pdo->prepare(
            "INSERT INTO payroll_enforcement_xmlzam_requests
                (supplier_id, environment, employee_id, inbox_message_id,
                 source_document_id, source_document_file_id, request_identifier,
                 issued_on, executor_box_id, source_xml_sha256, snapshot_ciphertext,
                 snapshot_fingerprint, imported_by, imported_at)
             SELECT supplier_id, environment, employee_id, ?, ?, ?, '123-12345678-Z9',
                    issued_on, executor_box_id, source_xml_sha256, snapshot_ciphertext,
                    REPEAT('0', 64), imported_by, UTC_TIMESTAMP()
               FROM payroll_enforcement_xmlzam_requests WHERE id = ?"
        )->execute([$secondInboxId, $secondDocumentId, $secondFileId, $imported['id']]);
        $forgedRequestId = (int) $this->pdo->lastInsertId();
        $caseId = $this->caseWithClaim($employeeId);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('snapshot požadavku XMLZAM nelze bezpečně ověřit');
        $this->flow->preview($this->supplierId, 'test', $forgedRequestId, $caseId, ['2026-07']);
    }

    public function testPreviewWithoutWageScopeFailsClosedBecauseOfficialXsdRequiresIt(): void
    {
        $employeeId = $this->employee(true);
        $xml = str_replace(
            'vyse_srazek trvani_praconiho_pomeru poradi_exekuce',
            'trvani_praconiho_pomeru poradi_exekuce',
            self::requestXml(),
        );
        [$inboxId, $fileId] = $this->source($xml, 'abc1234');
        $request = $this->flow->import($this->supplierId, 'test', $inboxId, $fileId, $this->userId);
        $caseId = $this->caseWithClaim($employeeId);
        $this->approvedRevision($employeeId, '2026-07-01');

        try {
            $this->flow->preview($this->supplierId, 'test', $request['id'], $caseId, ['2026-07']);
            self::fail('Selective XMLZAM response must fail closed against the pinned official XSD.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('neodpovídá oficiálnímu schématu', $e->getMessage());
        }
        self::assertSame(
            0,
            (int) $this->pdo->query(
                'SELECT COUNT(*) FROM payroll_enforcement_xmlzam_responses WHERE request_id = ' . (int) $request['id'],
            )->fetchColumn(),
        );
    }

    public function testUnsupportedRequestScopeBlocksFreeze(): void
    {
        $employeeId = $this->employee(true);
        $xml = str_replace(
            'vyse_srazek trvani_praconiho_pomeru poradi_exekuce',
            'telefon',
            self::requestXml(),
        );
        [$inboxId, $fileId] = $this->source($xml, 'abc1234');
        $request = $this->flow->import($this->supplierId, 'test', $inboxId, $fileId, $this->userId);
        $caseId = $this->caseWithClaim($employeeId);
        $this->approvedRevision($employeeId, '2026-07-01');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('nepodporovaný rozsah součinnosti');
        $this->flow->freeze(
            $this->supplierId,
            'test',
            $request['id'],
            $caseId,
            ['2026-07'],
            'synthetic-unsupported-scope',
            $this->userId,
        );
    }

    public function testApprovedResponseAndReadyOutboxAreEncryptedImmutableAndIdempotent(): void
    {
        $employeeId = $this->employee(true);
        [$inboxId, $fileId] = $this->source(self::requestXml(), 'abc1234');
        $request = $this->flow->import($this->supplierId, 'test', $inboxId, $fileId, $this->userId);
        $caseId = $this->caseWithClaim($employeeId);
        $this->approvedRevision($employeeId, '2026-07-01');

        $first = $this->flow->freeze(
            $this->supplierId,
            'test',
            $request['id'],
            $caseId,
            ['2026-07'],
            'synthetic-response-idempotency',
            $this->userId,
        );
        $second = $this->flow->freeze(
            $this->supplierId,
            'test',
            $request['id'],
            $caseId,
            ['2026-07'],
            'synthetic-response-idempotency',
            $this->userId,
        );
        self::assertTrue($first['created']);
        self::assertFalse($second['created']);
        self::assertSame($first['id'], $second['id']);
        $stored = $this->pdo->query(
            'SELECT snapshot_ciphertext, xml_ciphertext FROM payroll_enforcement_xmlzam_responses WHERE id = ' . (int) $first['id'],
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($stored);
        self::assertStringStartsWith('enc:v2:', (string) $stored['snapshot_ciphertext']);
        self::assertStringStartsWith('enc:v2:', (string) $stored['xml_ciphertext']);
        self::assertStringNotContainsString('<dokument', (string) $stored['xml_ciphertext']);
        try {
            $this->pdo->exec(
                'UPDATE payroll_enforcement_xmlzam_responses SET response_identifier = response_identifier WHERE id = ' . (int) $first['id'],
            );
            self::fail('Approved XMLZAM response must be immutable.');
        } catch (\PDOException $e) {
            self::assertStringContainsString('immutable', $e->getMessage());
        }

        try {
            $this->pdo->exec(
                "INSERT INTO payroll_enforcement_xmlzam_responses
                    (supplier_id, environment, request_id, case_id, response_identifier,
                     source_manifest_json, source_manifest_sha256, snapshot_ciphertext,
                     snapshot_fingerprint, xml_ciphertext, xml_sha256, idempotency_key_hash,
                     approved_by, approved_at)
                 SELECT supplier_id, environment, request_id, case_id, '999-20991231-Z1',
                        source_manifest_json, REPEAT('0', 64), snapshot_ciphertext,
                        snapshot_fingerprint, xml_ciphertext, xml_sha256,
                        UNHEX(SHA2(CONCAT('tampered-manifest-', id), 256)), approved_by, UTC_TIMESTAMP()
                   FROM payroll_enforcement_xmlzam_responses
                  WHERE id = " . (int) $first['id'],
            );
            self::fail('Response with a forged source manifest hash must be rejected.');
        } catch (\PDOException $e) {
            self::assertStringContainsString('manifest hash', $e->getMessage());
        }

        $this->pdo->prepare(
            "INSERT INTO submission_recipients
                (supplier_id, code, name, kind, isds_box_id, source_url, source_note, is_active, created_by)
             VALUES (?, ?, 'Syntetický exekutor', 'other', 'abc1234',
                     'https://example.invalid/synthetic-evidence', 'synthetic fixture', 0, ?)"
        )->execute([$this->supplierId, 'xmlzam_executor_' . $employeeId, $this->userId]);
        $recipientId = (int) $this->pdo->lastInsertId();
        try {
            $this->flow->enqueue(
                $this->supplierId,
                'test',
                $first['id'],
                $recipientId,
                $this->userId,
            );
            self::fail('Inactive recipient must not be enqueued.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('aktivní', $e->getMessage());
        }
        $this->pdo->exec('UPDATE submission_recipients SET is_active = 1 WHERE id = ' . $recipientId);
        $queuedFirst = $this->flow->enqueue(
            $this->supplierId,
            'test',
            $first['id'],
            $recipientId,
            $this->userId,
        );
        $queuedSecond = $this->flow->enqueue(
            $this->supplierId,
            'test',
            $first['id'],
            $recipientId,
            $this->userId,
        );
        self::assertTrue($queuedFirst['created']);
        self::assertFalse($queuedSecond['created']);
        self::assertSame($queuedFirst['outbox_id'], $queuedSecond['outbox_id']);
        $outbox = $this->pdo->query(
            'SELECT dispatch_state, confirmed_by, confirmed_at, artifact_kind
               FROM submission_outbox WHERE id = ' . (int) $queuedFirst['outbox_id'],
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame('ready', $outbox['dispatch_state']);
        self::assertNull($outbox['confirmed_by']);
        self::assertNull($outbox['confirmed_at']);
        self::assertSame('payroll_xmlzam', $outbox['artifact_kind']);
    }

    private function employee(bool $withIdentifier): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO payroll_employees
                (supplier_id, full_name, birth_date, taxpayer_type, is_active)
             VALUES (?, 'Synthetic Employee', '1990-01-01', 'employee', 1)"
        );
        $stmt->execute([$this->supplierId]);
        $employeeId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO payroll_employee_profiles (supplier_id, employee_id, profile_status)
             VALUES (?, ?, 'ready')"
        )->execute([$this->supplierId, $employeeId]);
        $this->pdo->prepare(
            'INSERT INTO payroll_person_identity_history
                (supplier_id, employee_id, full_name, first_name, last_name, birth_date, effective_from)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, $employeeId, 'Synthetic Employee', 'Synthetic', 'Employee', '1990-01-01', '2020-01-01']);
        $this->pdo->prepare(
            "INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status, start_date, row_version)
             VALUES (?, ?, ?, 'employment', 'active', '2020-01-01', 1)"
        )->execute([$this->supplierId, $employeeId, 'EMP-' . $employeeId]);
        if ($withIdentifier) {
            $hash = $this->sensitive->lookupHash(
                '900101/0007',
                PayrollSensitiveField::PERSONAL_IDENTIFIER,
                $this->supplierId,
            );
            $this->pdo->prepare(
                "INSERT INTO payroll_person_identifiers
                    (supplier_id, employee_id, identifier_type, value_ciphertext, value_hash, value_masked)
                 VALUES (?, ?, 'birth_number', 'enc:v2:synthetic-test-only', ?, '******/**07')"
            )->execute([$this->supplierId, $employeeId, $hash]);
        }
        return $employeeId;
    }

    private function caseWithClaim(int $employeeId): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO payroll_enforcement_cases
                (supplier_id, employee_id, case_key, case_kind, status, effective_from,
                 evidence_complete, recipient_verified, created_by, updated_by)
             VALUES (?, ?, ?, 'enforcement', 'withhold_and_hold', '2026-01-01', 1, 1, ?, ?)"
        );
        $stmt->execute([$this->supplierId, $employeeId, 'xmlzam-ready-case-' . $employeeId, $this->userId, $this->userId]);
        $caseId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO payroll_enforcement_claims
                (supplier_id, case_id, claim_key, enforcement_order_key, legal_basis, category,
                 outstanding_minor_units, priority_date, first_payer_delivered_on, order_issued_on, legal_title_verified,
                 order_or_notice_delivered, priority_classification_verified,
                 agreement_verified, due_monetary_claim_verified, is_active)
             VALUES (?, ?, ?, ?, 'statutory', 'non_priority', 100000, '2026-04-03', '2026-04-03',
                     '2026-04-01', 1, 1, 1, 0, 1, 1)"
        )->execute([$this->supplierId, $caseId, 'xmlzam-ready-claim-' . $employeeId, 'xmlzam-ready-order-' . $employeeId]);
        return $caseId;
    }

    private function approvedRevision(int $employeeId, string $periodStart): int
    {
        $input = [
            'schema_version' => 'payroll-run-input.v2',
            'supplier_id' => $this->supplierId,
            'period_start' => $periodStart,
            'people' => [[
                'employee' => ['id' => $employeeId, 'full_name' => 'Synthetic Employee'],
                'employments' => [],
                'payout_rules' => [],
                'payout_accounts' => [],
                'deduction_agreements' => [],
            ]],
        ];
        $net = [
            // Kanonický tvar z pipeline. Holé id tu dřív zakrývalo, že rozklad
            // čisté mzdy — a s ním celá součinnost — na ostré revizi padal.
            'person_reference' => "employee:{$employeeId}",
            'cash_income_minor_units' => 3_500_000,
            'non_cash_income_minor_units' => 0,
            'employee_social_minor_units' => 0,
            'employee_health_minor_units' => 0,
            'advance_tax_minor_units' => 0,
            'withholding_tax_minor_units' => 0,
            'tax_bonus_minor_units' => 0,
            'correction_minor_units' => 0,
            'annual_settlement_minor_units' => 0,
            'net_before_deductions_minor_units' => 3_000_000,
            'deductions' => [],
            'deducted_minor_units' => 0,
            'net_payable_minor_units' => 3_000_000,
            'relationships' => [],
        ];
        $personResult = [
            'employee_id' => $employeeId,
            'payable_after_enforcement_minor' => 2_900_000,
            'statutory' => ['status' => 'calculated', 'net_pay' => $net],
        ];
        $result = [
            'schema_version' => 'payroll-run-result.v2',
            'people' => [$personResult],
        ];
        $inputJson = CanonicalJson::encode($input);
        $resultJson = CanonicalJson::encode($result);
        $this->pdo->prepare(
            "INSERT INTO payroll_runs (supplier_id, period_start, payment_date, status, current_revision_no)
             VALUES (?, ?, '2026-08-10', 'approved', 1)"
        )->execute([$this->supplierId, $periodStart]);
        $runId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json, input_snapshot_hash,
                 result_snapshot_json, result_snapshot_hash, idempotency_key_hash,
                 approved_by, approved_at)
             VALUES (?, ?, 1, 'regular', 'approved', 'payroll-run-input.v2', ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
        )->execute([
            $this->supplierId,
            $runId,
            str_repeat('a', 64),
            $inputJson,
            hash('sha256', $inputJson),
            $resultJson,
            hash('sha256', $resultJson),
            hash('sha256', 'xmlzam-approved-' . $runId, true),
            $this->userId,
        ]);
        $revisionId = (int) $this->pdo->lastInsertId();
        $personJson = CanonicalJson::encode($personResult);
        $this->pdo->prepare(
            "INSERT INTO payroll_run_persons
                (supplier_id, revision_id, employee_id, result_json, result_hash, status)
             VALUES (?, ?, ?, ?, ?, 'calculated')"
        )->execute([$this->supplierId, $revisionId, $employeeId, $personJson, hash('sha256', $personJson)]);
        $enforcementInput = CanonicalJson::encode(['evidence' => ['eligible_dependants' => 1]]);
        $enforcementResult = CanonicalJson::encode(['total_withheld_minor_units' => 100000]);
        $this->pdo->prepare(
            "INSERT INTO payroll_enforcement_month_results
                (supplier_id, revision_id, employee_id, period_start, result_status,
                 ruleset_id, ruleset_hash, input_snapshot_json, input_snapshot_hash,
                 result_snapshot_json, result_snapshot_hash, total_withheld_minor_units,
                 employee_payment_minor_units, employer_fee_minor_units, idempotency_key_hash)
             VALUES (?, ?, ?, ?, 'supported', 'synthetic-ruleset', ?, ?, ?, ?, ?, 100000, 0, 0, ?)"
        )->execute([
            $this->supplierId,
            $revisionId,
            $employeeId,
            $periodStart,
            str_repeat('b', 64),
            $enforcementInput,
            hash('sha256', $enforcementInput),
            $enforcementResult,
            hash('sha256', $enforcementResult),
            hash('sha256', 'xmlzam-enforcement-' . $revisionId, true),
        ]);
        return $revisionId;
    }

    /** @return array{0:int,1:int} */
    private function source(string $xml, string $senderBoxId): array
    {
        return $this->sourceForSupplier($this->supplierId, $xml, $senderBoxId);
    }

    /** @return array{0:int,1:int} */
    private function sourceForSupplier(int $supplierId, string $xml, string $senderBoxId): array
    {
        $containerStored = $this->storage->storeZfoAttachmentFromBytes(
            'synthetic-zfo-' . hash('sha256', $xml . $senderBoxId),
            $supplierId,
            'message.zfo',
            'application/octet-stream',
        );
        $containerId = $this->insertDocument($containerStored, null, 'manual', 'message.zfo', $supplierId);
        $this->insertFile($containerStored, $containerId, 'message.zfo', $supplierId);
        $childStored = $this->storage->storeZfoAttachmentFromBytes(
            $xml,
            $supplierId,
            'request.xml',
            'application/xml',
        );
        $childId = $this->insertDocument($childStored, $containerId, 'zfo_extract', 'request.xml', $supplierId);
        $fileId = $this->insertFile($childStored, $childId, 'request.xml', $supplierId);
        $stmt = $this->pdo->prepare(
            "INSERT INTO submission_inbox_messages
                (supplier_id, environment, channel, external_message_id, sender_box_id,
                 classification, document_id, raw_sha256, processed_at)
             VALUES (?, 'test', 'isds', ?, ?, 'unclassified', ?, ?, UTC_TIMESTAMP())"
        );
        $stmt->execute([
            $supplierId,
            'dm-' . substr(hash('sha256', $xml . $senderBoxId . $containerId), 0, 20),
            $senderBoxId,
            $containerId,
            hash('sha256', 'synthetic-container'),
        ]);
        return [(int) $this->pdo->lastInsertId(), $fileId];
    }

    /** @param array<string,mixed> $stored */
    private function insertDocument(
        array $stored,
        ?int $parentId,
        string $source,
        string $name,
        ?int $supplierId = null,
    ): int
    {
        return $this->documents->insert([
            'supplier_id' => $supplierId ?? $this->supplierId,
            'folder_id' => null,
            'title' => $name,
            'description' => null,
            'original_name' => $name,
            'filename' => $stored['filename'],
            'sha256' => $stored['sha256'],
            'mime_type' => $stored['mime_type'],
            'size_bytes' => $stored['size_bytes'],
            'doc_type' => $stored['doc_type'],
            'source' => $source,
            'parent_document_id' => $parentId,
            'uploaded_by' => null,
        ]);
    }

    /** @param array<string,mixed> $stored */
    private function insertFile(array $stored, int $documentId, string $name, ?int $supplierId = null): int
    {
        return $this->files->add([
            'document_id' => $documentId,
            'supplier_id' => $supplierId ?? $this->supplierId,
            'role' => 'primary',
            'sha256' => $stored['sha256'],
            'filename' => $stored['filename'],
            'original_name' => $name,
            'mime_type' => $stored['mime_type'],
            'size_bytes' => $stored['size_bytes'],
            'doc_type' => $stored['doc_type'],
            'sort_order' => 0,
            'uploaded_by' => null,
        ]);
    }

    private function insertRecipient(string $code, string $boxId, bool $active): int
    {
        $this->pdo->prepare(
            "INSERT INTO submission_recipients
                (supplier_id, code, name, kind, isds_box_id, source_url, source_note, is_active, created_by)
             VALUES (?, ?, 'Syntetický exekutor', 'other', ?,
                     'https://example.invalid/synthetic-evidence', 'synthetic fixture', ?, ?)"
        )->execute([$this->supplierId, $code, $boxId, $active ? 1 : 0, $this->userId]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,string> $query */
    private function apiRequest(string $method, string $uri, int $supplierId, array $query = []): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $uri)
            ->withQueryParams($query)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }

    /** @return array<string,mixed> */
    private function jsonResponse(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        return $payload;
    }

    private static function requestXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<dokument xs:type="soucinnost" xmlns:xs="http://www.w3.org/2001/XMLSchema-instance">
  <identifikator>123-12345678-A1</identifikator><znacka_rizeni>EX 123456/26</znacka_rizeni>
  <datum>2026-08-26</datum><druh_dokumentu>soucinnost</druh_dokumentu>
  <druh_soucinnosti>vyse_srazek trvani_praconiho_pomeru poradi_exekuce</druh_soucinnosti>
  <exekutor><nazev>Syntetický exekutorský úřad</nazev><adresa>Testovací 1, Praha</adresa><senat>123</senat><ic>12345679</ic><idds>abc1234</idds><platebni_udaje vs="1234567890">1000000005/0100</platebni_udaje></exekutor>
  <opravneny><nazev>Syntetický oprávněný</nazev><adresa>Vzorová 2, Praha</adresa><ic>87654321</ic><narozen></narozen></opravneny>
  <povinny><jmeno>Synthetic</jmeno><prijmeni>Employee</prijmeni><narozen>1990-01-01</narozen><rc>900101/0007</rc></povinny>
  <povereni_prehled><povereni vydal="Syntetický soud" cislo="1 EXE 1/2026" vydano="2026-04-01" pravni_moc="2026-04-02"></povereni></povereni_prehled><prilohy></prilohy>
</dokument>
XML;
    }
}
