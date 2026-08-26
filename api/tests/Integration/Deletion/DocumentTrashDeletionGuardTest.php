<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Deletion;

use MyInvoice\Action\Document\DocumentsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Support\PayrollPaymentEvidenceTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * POST /api/documents/trash/empty — vysypání koše s živou vazbou.
 *
 * Býval to jeden hromadný `DELETE FROM documents WHERE deleted_at IS NOT NULL`.
 * Mzdový modul přidal cizí klíče RESTRICT, takže JEDINÝ navázaný doklad shodil
 * celý příkaz a koš pak nešlo vysypat nikdy — bez informace, který doklad to je.
 * Tenhle test drží obojí: blokovaný doklad zůstane, zbytek zmizí, a odpověď
 * řekne kolik jich zůstalo a proč.
 */
#[Group('integration')]
final class DocumentTrashDeletionGuardTest extends TestCase
{
    use IsolatedSupplierTrait;
    use PayrollPaymentEvidenceTrait;

    private Connection $db;
    private DocumentsAction $action;
    private int $supplierId;
    private int $otherSupplierId;
    private int $userId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        $container = Bootstrap::buildApp()->getContainer();
        $this->db = $container->get(Connection::class);
        if (!$this->db->hasTable('payroll_document_dms_links') || !$this->db->hasTable('tax_submission_artifacts')) {
            $this->markTestSkipped('Migrace mzdových dokladů / artefaktů podání neproběhly.');
        }
        $this->action = $container->get(DocumentsAction::class);

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testTrashWithoutForeignLinksIsEmptiedCompletely(): void
    {
        $first = $this->trashedDocument('a');
        $second = $this->trashedDocument('b');

        $body = $this->json($this->emptyTrash());

        self::assertSame(2, $body['deleted']);
        self::assertSame(0, $body['kept']);
        self::assertSame([], $body['kept_documents']);
        self::assertSame(0, $this->documentCount($first));
        self::assertSame(0, $this->documentCount($second));
    }

    /**
     * Jádro nálezu: doklad s mzdovou vazbou zůstane, ale ZBYTEK koše se vysype
     * a odpověď jmenuje, co zůstalo a proč.
     */
    public function testPayrollLinkedDocumentStaysAndTheRestIsEmptied(): void
    {
        $blocked = $this->trashedDocument('payroll');
        $free = $this->trashedDocument('free');
        $this->linkPayrollDocument($blocked, 'payroll');

        $body = $this->json($this->emptyTrash());

        self::assertSame(1, $body['deleted']);
        self::assertSame(1, $body['kept']);
        self::assertSame(0, $this->documentCount($free), 'Zbytek koše se vysypat MUSÍ.');
        self::assertSame(1, $this->documentCount($blocked), 'Navázaný doklad zůstává.');

        self::assertCount(1, $body['kept_documents']);
        $kept = $body['kept_documents'][0];
        self::assertSame($blocked, $kept['id']);
        self::assertSame('Testovací doklad payroll', $kept['title']);
        self::assertStringContainsString('výplatní pásce', $kept['reason']);
        self::assertStringNotContainsStringIgnoringCase('foreign key', $kept['reason']);
        self::assertStringNotContainsStringIgnoringCase('payroll_document_dms_links', $kept['reason']);
    }

    public function testProductionQualificationEvidenceStaysInTrash(): void
    {
        $blocked = $this->trashedDocument('qualification');
        $free = $this->trashedDocument('qualification-free');
        $this->linkProductionQualificationDocument($blocked);

        $body = $this->json($this->emptyTrash());

        self::assertSame(1, $body['deleted']);
        self::assertSame(1, $body['kept']);
        self::assertSame(0, $this->documentCount($free));
        self::assertSame(1, $this->documentCount($blocked));
        self::assertStringContainsString(
            'připravenost firmy k ostrému provozu mezd',
            $body['kept_documents'][0]['reason'],
        );
    }

    /**
     * Tichá kaskáda: `tax_submission_artifacts.document_id` je ON DELETE CASCADE,
     * takže vysypání koše mlčky mazalo důkaz o podání na finanční správu. Registr
     * ho blokuje výslovně, i když ho databáze sama nevynucuje.
     */
    public function testTaxSubmissionArtifactDocumentIsKeptDespiteCascade(): void
    {
        $blocked = $this->trashedDocument('artifact');
        $free = $this->trashedDocument('other');
        $artifactId = $this->linkTaxSubmissionArtifact($blocked);

        $body = $this->json($this->emptyTrash());

        self::assertSame(1, $body['deleted']);
        self::assertSame(1, $body['kept']);
        self::assertSame(0, $this->documentCount($free));
        self::assertSame(1, $this->documentCount($blocked));
        self::assertSame(1, $this->artifactCount($artifactId), 'Důkaz o podání nesmí zmizet.');
        self::assertStringContainsString('finanční správu', $body['kept_documents'][0]['reason']);
    }

    /**
     * `documents.parent_document_id` kaskáduje — smazání rodiče by vzalo i
     * blokovaného potomka, takže rodič musí zůstat taky.
     */
    public function testParentOfBlockedChildStaysToo(): void
    {
        $parent = $this->trashedDocument('parent');
        $child = $this->trashedDocument('child', $parent);
        $free = $this->trashedDocument('unrelated');
        $this->linkPayrollDocument($child, 'child');

        $body = $this->json($this->emptyTrash());

        self::assertSame(1, $body['deleted']);
        self::assertSame(2, $body['kept']);
        self::assertSame(0, $this->documentCount($free));
        self::assertSame(1, $this->documentCount($parent));
        self::assertSame(1, $this->documentCount($child));

        $reasons = [];
        foreach ($body['kept_documents'] as $row) {
            $reasons[(int) $row['id']] = (string) $row['reason'];
        }
        self::assertStringContainsString('podřízený soubor', $reasons[$parent]);
        self::assertStringContainsString('výplatní pásce', $reasons[$child]);
    }

    /** Složka, ve které blokovaný doklad zůstal, se smazat nesmí — jinak ztratí zařazení. */
    public function testFolderOfKeptDocumentSurvivesThePurge(): void
    {
        $folderId = $this->trashedFolder();
        $blocked = $this->trashedDocument('in-folder', null, $folderId);
        $this->linkPayrollDocument($blocked, 'in-folder');

        $this->emptyTrash();

        self::assertSame(1, $this->folderCount($folderId));
        $stmt = $this->db->pdo()->prepare('SELECT folder_id FROM documents WHERE id = ?');
        $stmt->execute([$blocked]);
        self::assertSame($folderId, (int) $stmt->fetchColumn());
    }

    public function testForeignTenantEmptiesNothingOfOurTrash(): void
    {
        $ours = $this->trashedDocument('ours');

        $body = $this->json($this->emptyTrash($this->otherSupplierId));

        self::assertSame(0, $body['deleted']);
        self::assertSame(1, $this->documentCount($ours));
    }

    // ── Pomocné ──────────────────────────────────────────────────────────────

    private function emptyTrash(?int $supplierId = null): ResponseInterface
    {
        return $this->action->emptyTrash($this->request($supplierId), new Response());
    }

    private function request(?int $supplierId = null): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/documents/trash/empty')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId ?? $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }

    private function trashedDocument(string $seed, ?int $parentId = null, ?int $folderId = null): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO documents
                (supplier_id, folder_id, title, original_name, filename, sha256, mime_type,
                 size_bytes, doc_type, parent_document_id, uploaded_by, deleted_at)
             VALUES (?, ?, ?, ?, ?, ?, "application/pdf", 1024, "pdf", ?, ?, NOW())'
        );
        $stmt->execute([
            $this->supplierId,
            $folderId,
            "Testovací doklad {$seed}",
            "{$seed}.pdf",
            "{$seed}.pdf",
            hash('sha256', "fkguard-document-{$seed}-{$this->supplierId}"),
            $parentId,
            $this->userId,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function trashedFolder(): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO document_folders (supplier_id, parent_id, name, deleted_at)
             VALUES (?, NULL, "Testovací složka", NOW())'
        );
        $stmt->execute([$this->supplierId]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** Mzdový vydaný doklad připojený k DMS dokladu — cizí klíč RESTRICT. */
    private function linkPayrollDocument(int $documentId, string $seed): void
    {
        $pdo = $this->db->pdo();
        [$revisionId, $employeeId] = $this->seedApprovedRevision($pdo, $this->supplierId, "dms-{$seed}");
        $runId = (int) $pdo->query(
            'SELECT run_id FROM payroll_run_revisions WHERE id = ' . $revisionId
        )->fetchColumn();

        $sha = hash('sha256', "fkguard-payroll-doc-{$seed}");
        $pdo->prepare(
            'INSERT INTO payroll_generated_documents
                (supplier_id, run_id, revision_id, employee_id, document_kind, revision_snapshot_hash,
                 source_snapshot_hash, template_version, renderer_version, file_sha256, size_bytes,
                 mime_type, storage_key, suggested_filename, idempotency_key_hash)
             VALUES (?, ?, ?, ?, "payslip", ?, ?, "v1", "v1", ?, 2048, "application/pdf", ?, ?, ?)'
        )->execute([
            $this->supplierId,
            $runId,
            $revisionId,
            $employeeId,
            $this->payrollResultSnapshotHash(),
            $sha,
            $sha,
            $sha,
            'payslip.pdf',
            hash('sha256', "fkguard-payroll-doc-idem-{$seed}", true),
        ]);
        $payrollDocumentId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO payroll_document_dms_links
                (supplier_id, payroll_document_id, dms_document_id, linked_by)
             VALUES (?, ?, ?, ?)'
        )->execute([$this->supplierId, $payrollDocumentId, $documentId, $this->userId]);
    }

    private function linkProductionQualificationDocument(int $documentId): void
    {
        $hash = hash('sha256', "qualification-document-{$documentId}");
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_production_qualifications
                (supplier_id, module_state_row_version, support_matrix_version,
                 support_matrix_sha256, evidence_json, evidence_sha256, qualified_by)
             VALUES (?, 1, "synthetic", ?, "{}", ?, ?)'
        );
        $stmt->execute([
            $this->supplierId,
            hash('sha256', 'matrix'),
            hash('sha256', 'evidence'),
            $this->userId,
        ]);
        $qualificationId = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_production_qualification_documents
                (supplier_id, qualification_id, evidence_key, sequence_no,
                 document_id, document_sha256)
             VALUES (?, ?, "expert_approval", 1, ?, ?)'
        )->execute([$this->supplierId, $qualificationId, $documentId, $hash]);
    }

    /** Artefakt podání na FS — vazba, kterou databáze kaskáduje, a proto mizela mlčky. */
    private function linkTaxSubmissionArtifact(int $documentId): int
    {
        $pdo = $this->db->pdo();
        $xml = '<fkguard/>';
        $pdo->prepare(
            'INSERT INTO tax_submissions
                (supplier_id, form_code, period_year, period_month, xml_content, xml_size_bytes,
                 xml_sha256, status)
             VALUES (?, "dphdp3", 2099, 1, ?, ?, ?, "submitted")'
        )->execute([$this->supplierId, $xml, strlen($xml), hash('sha256', $xml)]);
        $submissionId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO tax_submission_artifacts
                (supplier_id, tax_submission_id, document_id, artifact_kind, sha256, verification_status)
             VALUES (?, ?, ?, "epo_xml", ?, "valid")'
        )->execute([$this->supplierId, $submissionId, $documentId, hash('sha256', "artifact-{$documentId}")]);

        return (int) $pdo->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function documentCount(int $id): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM documents WHERE id = ?');
        $stmt->execute([$id]);

        return (int) $stmt->fetchColumn();
    }

    private function artifactCount(int $id): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM tax_submission_artifacts WHERE id = ?');
        $stmt->execute([$id]);

        return (int) $stmt->fetchColumn();
    }

    private function folderCount(int $id): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM document_folders WHERE id = ?');
        $stmt->execute([$id]);

        return (int) $stmt->fetchColumn();
    }
}
