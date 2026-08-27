<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Document\DocumentsAction;
use MyInvoice\Action\Payroll\PayrollForeignPermitAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Repository\DocumentViewerContext;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollForeignPermitApiTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollForeignPermitAction $action;
    private DocumentsAction $documentsAction;
    private DocumentRepository $documents;
    private int $supplierId;
    private int $otherSupplierId;
    private int $employeeId;
    private int $userId;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }

        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->action = $container->get(PayrollForeignPermitAction::class);
            $this->documentsAction = $container->get(DocumentsAction::class);
            $this->documents = $container->get(DocumentRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        if (!$this->db->hasTable('payroll_person_foreign_permits')) {
            $this->markTestSkipped('Migrace 1596 neproběhla.');
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)')
            ->execute([$this->supplierId, $this->otherSupplierId]);
        $this->employeeId = $this->employee($this->supplierId);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testEffectiveHistoryUsesAnAuthoritativeCompanyDmsDocumentAndReportsExpiry(): void
    {
        $documentId = $this->document($this->supplierId, 'company');
        $response = $this->create([
            'permit_kind' => 'residence',
            'permit_label' => 'Syntetické povolení k pobytu',
            'issuing_country_code' => 'CZ',
            'effective_from' => '2026-01-01',
            'valid_until' => '2026-09-15',
            'document_id' => $documentId,
        ], '2026-08-20');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        $view = $this->json($response)['permits'];
        self::assertCount(1, $view['history']);
        self::assertSame($documentId, $view['history'][0]['document_id']);
        self::assertArrayNotHasKey('document_sha256', $view['history'][0]);
        self::assertSame('expiring', $view['history'][0]['status']);
        self::assertSame('expiring', $view['alerts'][0]['status']);

        $reused = $this->create([
            'permit_kind' => 'work',
            'permit_label' => 'Syntetické pracovní oprávnění',
            'issuing_country_code' => 'CZ',
            'effective_from' => '2026-01-01',
            'valid_until' => '2026-09-15',
            'document_id' => $documentId,
        ], '2026-08-20');
        self::assertSame(200, $reused->getStatusCode(), (string) $reused->getBody());
        self::assertCount(2, $this->json($reused)['permits']['history']);

        $stored = $this->db->pdo()->prepare(
            'SELECT document_sha256 FROM payroll_person_foreign_permits
              WHERE supplier_id = ? AND employee_id = ?',
        );
        $stored->execute([$this->supplierId, $this->employeeId]);
        self::assertSame(
            hash('sha256', "permit-company-{$this->supplierId}"),
            $stored->fetchColumn(),
        );
    }

    public function testPersonalAndCrossTenantDmsDocumentsFailClosed(): void
    {
        $personal = $this->document($this->supplierId, 'user');
        $personalResponse = $this->create($this->payload($personal), '2026-08-20');
        self::assertSame(422, $personalResponse->getStatusCode());
        self::assertSame(
            'company_evidence_document_required',
            $this->json($personalResponse)['error']['code'],
        );

        $foreign = $this->document($this->otherSupplierId, 'company');
        $foreignResponse = $this->create($this->payload($foreign), '2026-08-20');
        self::assertSame(422, $foreignResponse->getStatusCode());
        self::assertSame(
            'company_evidence_document_required',
            $this->json($foreignResponse)['error']['code'],
        );
        self::assertSame(0, $this->countPermits());
    }

    public function testPermitRowsAreAppendOnlyAndAnOverlappingRenewalMustNameItsPredecessor(): void
    {
        $first = $this->json($this->create($this->payload($this->document($this->supplierId, 'company')), '2026-08-20'))['permits']['history'][0];

        $missingPredecessor = $this->create([
            ...$this->payload($this->document($this->supplierId, 'company')),
            'effective_from' => '2026-09-01',
            'valid_until' => '2027-09-01',
        ], '2026-08-20');
        self::assertSame(422, $missingPredecessor->getStatusCode());
        self::assertSame('permit_overlap_requires_predecessor', $this->json($missingPredecessor)['error']['code']);

        $retroactiveSuccessor = $this->create([
            ...$this->payload($this->document($this->supplierId, 'company')),
            'effective_from' => '2025-12-01',
            'valid_until' => '2026-02-01',
            'supersedes_permit_id' => $first['id'],
        ], '2026-08-20');
        self::assertSame(422, $retroactiveSuccessor->getStatusCode());
        self::assertSame(
            'permit_successor_must_be_later',
            $this->json($retroactiveSuccessor)['error']['code'],
        );

        $renewed = $this->create([
            ...$this->payload($this->document($this->supplierId, 'company')),
            'effective_from' => '2026-09-01',
            'valid_until' => '2027-09-01',
            'supersedes_permit_id' => $first['id'],
        ], '2026-09-10');
        self::assertSame(200, $renewed->getStatusCode());
        self::assertCount(2, $this->json($renewed)['permits']['history']);

        $pdo = $this->db->pdo();
        $this->expectException(\PDOException::class);
        $pdo->prepare('UPDATE payroll_person_foreign_permits SET permit_label = "tampered" WHERE id = ?')
            ->execute([$first['id']]);
    }

    public function testBearerRequestFailsClosedEvenWhenItCarriesACompanyDocumentId(): void
    {
        $request = $this->request('POST')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer')
            ->withParsedBody($this->payload($this->document($this->supplierId, 'company')));
        $response = $this->action->create(
            $request,
            new Response(),
            ['id' => (string) $this->employeeId],
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('session_required', $this->json($response)['error']['code']);
        self::assertSame(0, $this->countPermits());
    }

    public function testOnePermitCannotHaveTwoDirectSuccessors(): void
    {
        $first = $this->json($this->create(
            $this->payload($this->document($this->supplierId, 'company')),
            '2026-08-20',
        ))['permits']['history'][0];

        $firstSuccessor = $this->create([
            ...$this->payload($this->document($this->supplierId, 'company')),
            'effective_from' => '2026-09-01',
            'valid_until' => '2027-09-01',
            'supersedes_permit_id' => $first['id'],
        ], '2026-09-10');
        self::assertSame(200, $firstSuccessor->getStatusCode(), (string) $firstSuccessor->getBody());

        $secondSuccessor = $this->create([
            ...$this->payload($this->document($this->supplierId, 'company')),
            'effective_from' => '2028-01-01',
            'valid_until' => '2028-12-31',
            'supersedes_permit_id' => $first['id'],
        ], '2028-01-10');

        self::assertSame(422, $secondSuccessor->getStatusCode(), (string) $secondSuccessor->getBody());
        self::assertSame(
            'permit_predecessor_already_superseded',
            $this->json($secondSuccessor)['error']['code'],
        );
    }

    public function testMissingAuthMethodFailsClosedToo(): void
    {
        $response = $this->action->show(
            $this->request('GET')->withoutAttribute(AuthMiddleware::ATTR_METHOD),
            new Response(),
            ['id' => (string) $this->employeeId],
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('session_required', $this->json($response)['error']['code']);
    }

    public function testPayrollReaderCanReadPermitMetadataWithoutDocumentPermission(): void
    {
        $documentId = $this->document($this->supplierId, 'company');
        $this->insertPermitFixture($documentId);
        $payrollReader = new EffectiveRole(
            703,
            'Mzdový čtenář',
            'staff',
            true,
            ['payroll' => AccessLevel::READ->value],
        );
        $response = $this->action->show(
            $this->request('GET')->withAttribute('auth.effective_role', $payrollReader),
            new Response(),
            ['id' => (string) $this->employeeId],
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        $history = $this->json($response)['permits']['history'];
        self::assertCount(1, $history);
        self::assertNull($history[0]['document_id']);
        self::assertArrayNotHasKey('document_sha256', $history[0]);
    }

    public function testPermitDocumentIsHiddenFromGenericDmsListDetailAndBearerWithoutSessionPayrollRead(): void
    {
        $documentId = $this->document($this->supplierId, 'company');
        $this->insertPermitFixture($documentId);
        $documentsOnly = new EffectiveRole(
            701,
            'Dokumenty bez mezd',
            'staff',
            true,
            ['documents' => AccessLevel::READ->value],
        );
        $restricted = $this->request('GET')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute('auth.effective_role', $documentsOnly);

        self::assertSame(404, $this->documentsAction->get(
            $restricted->withUri($restricted->getUri()->withPath("/api/documents/{$documentId}")),
            new Response(),
            ['id' => (string) $documentId],
        )->getStatusCode());
        self::assertSame([], $this->json($this->documentsAction->list(
            $restricted->withUri($restricted->getUri()->withPath('/api/documents')),
            new Response(),
        ))['data']);
        self::assertNull($this->documents->findRaw(
            $documentId,
            $this->supplierId,
            DocumentViewerContext::forUser($this->userId),
        ));

        $payrollReader = new EffectiveRole(
            702,
            'Mzdová účetní',
            'staff',
            true,
            ['documents' => AccessLevel::READ->value, 'payroll' => AccessLevel::READ->value],
        );
        $allowed = $restricted->withAttribute('auth.effective_role', $payrollReader);
        self::assertSame(200, $this->documentsAction->get(
            $allowed,
            new Response(),
            ['id' => (string) $documentId],
        )->getStatusCode());
        self::assertCount(1, $this->json($this->documentsAction->list(
            $allowed->withUri($allowed->getUri()->withPath('/api/documents')),
            new Response(),
        ))['data']);
        self::assertSame(404, $this->documentsAction->get(
            $allowed->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer'),
            new Response(),
            ['id' => (string) $documentId],
        )->getStatusCode());
    }

    /** @param array<string,mixed> $payload */
    private function create(array $payload, string $asOf): Response
    {
        return $this->action->create(
            $this->request('POST')->withParsedBody($payload),
            new Response(),
            ['id' => (string) $this->employeeId],
        );
    }

    /** @return array<string,mixed> */
    private function payload(int $documentId): array
    {
        return [
            'permit_kind' => 'residence',
            'permit_label' => 'Syntetické povolení k pobytu',
            'issuing_country_code' => 'CZ',
            'effective_from' => '2026-01-01',
            'valid_until' => '2026-09-15',
            'document_id' => $documentId,
        ];
    }

    private function employee(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, "Syntetická zahraniční osoba", "employee", "hpp", 0, 0, 0, NULL, 0, 1)',
        );
        $stmt->execute([$supplierId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function document(int $supplierId, string $scope): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO documents
                (supplier_id, title, original_name, filename, sha256, mime_type,
                 size_bytes, doc_type, uploaded_by, scope, owner_user_id)
             VALUES (?, "Syntetický podklad oprávnění", "permit.pdf", "permit.pdf", ?,
                     "application/pdf", 128, "pdf", ?, ?, ?)',
        );
        $stmt->execute([
            $supplierId,
            hash('sha256', "permit-{$scope}-{$supplierId}"),
            $this->userId,
            $scope,
            $scope === 'user' ? $this->userId : null,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function countPermits(): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_person_foreign_permits WHERE supplier_id = ?',
        );
        $stmt->execute([$this->supplierId]);
        return (int) $stmt->fetchColumn();
    }

    private function insertPermitFixture(int $documentId): void
    {
        $hasCompositeDocumentScope = $this->db->hasColumn(
            'payroll_person_foreign_permits',
            'document_supplier_id',
        );
        $columns = 'supplier_id, employee_id, permit_kind, permit_label, issuing_country_code, '
            . 'effective_from, valid_until, ' . ($hasCompositeDocumentScope ? 'document_supplier_id, ' : '')
            . 'document_id, document_sha256, recorded_by';
        $values = [$this->supplierId, $this->employeeId, 'residence', 'Syntetický pobytový doklad', 'CZ',
            '2026-01-01', '2026-12-31'];
        if ($hasCompositeDocumentScope) {
            $values[] = $this->supplierId;
        }
        $values[] = $documentId;
        $values[] = hash('sha256', "permit-company-{$this->supplierId}");
        $values[] = $this->userId;
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_foreign_permits (' . $columns . ') VALUES ('
            . implode(', ', array_fill(0, count($values), '?')) . ')',
        )->execute($values);
    }

    private function request(string $method): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, '/api/payroll/people/' . $this->employeeId . '/foreign-permits')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
    }

    /** @return array<string,mixed> */
    private function json(Response $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);
        return $decoded;
    }
}
