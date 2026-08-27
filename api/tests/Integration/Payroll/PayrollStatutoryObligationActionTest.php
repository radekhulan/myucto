<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollStatutoryObligationAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Deletion\DocumentDeletionGuard;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollStatutoryObligationActionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollStatutoryObligationAction $action;
    private DocumentDeletionGuard $documentDeletionGuard;
    private int $supplierId;
    private int $otherSupplierId;
    private int $userId;
    private int $employeeId;
    private int $documentId;
    private int $otherDocumentId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer();
        $this->db = $container->get(Connection::class);
        if (!$this->db->hasTable('payroll_statutory_obligation_evidence')) {
            $this->markTestSkipped('Migrace 1588 neproběhla.');
        }
        $this->action = $container->get(PayrollStatutoryObligationAction::class);
        $this->documentDeletionGuard = $container->get(DocumentDeletionGuard::class);
        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1',
        )->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query(
            'SELECT id FROM users ORDER BY id LIMIT 1',
        )->fetchColumn() ?: 0);
        if ($sourceSupplierId <= 0 || $this->userId <= 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare(
            'UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)',
        )->execute([$this->supplierId, $this->otherSupplierId]);
        $employee = $pdo->prepare(
            'INSERT INTO payroll_employees (supplier_id, full_name)
             VALUES (?, ?)'
        );
        $employee->execute([$this->supplierId, 'Syntetická Zaměstnankyně']);
        $this->employeeId = (int) $pdo->lastInsertId();
        $this->documentId = $this->document($this->supplierId, 'receipt-a');
        $this->otherDocumentId = $this->document($this->otherSupplierId, 'receipt-b');
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

    public function testSessionRecordsImmutableServerHashedEvidenceIdempotently(): void
    {
        $body = $this->validBody();
        $first = $this->record($body, 'statutory-evidence-1');
        self::assertSame(201, $first->getStatusCode());
        $firstJson = $this->json($first);
        self::assertTrue($firstJson['created']);
        self::assertSame(
            hash('sha256', 'receipt-a'),
            $firstJson['evidence']['document_sha256'],
        );
        self::assertArrayNotHasKey(
            'idempotency_key_hash',
            $firstJson['evidence'],
        );

        $replay = $this->record($body, 'statutory-evidence-1');
        self::assertSame(200, $replay->getStatusCode());
        self::assertFalse($this->json($replay)['created']);
        self::assertSame(
            1,
            (int) $this->db->pdo()->query(
                'SELECT COUNT(*) FROM payroll_statutory_obligation_evidence',
            )->fetchColumn(),
        );

        $overview = $this->overview();
        self::assertSame(200, $overview->getStatusCode());
        $overviewJson = $this->json($overview);
        self::assertSame('partially_replaced', $overviewJson['agendas'][0]['replacement_mode']);
        self::assertCount(1, $overviewJson['evidence']);
        self::assertSame('NEMPRI', $overviewJson['evidence'][0]['agenda_code']);

        $audit = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM activity_log
              WHERE supplier_id = ?
                AND action = 'payroll.stat_obligation.evidence_recorded'
                AND entity_type = 'payroll_statutory_obligation_evidence'
                AND entity_id = ?
                AND hash IS NOT NULL"
        );
        $audit->execute([$this->supplierId, $firstJson['evidence']['id']]);
        self::assertSame(1, (int) $audit->fetchColumn());

        $blockedDocument = $this->documentDeletionGuard->blockedTrashDocuments(
            $this->supplierId,
            [$this->documentId],
        );
        self::assertArrayHasKey($this->documentId, $blockedDocument);
        self::assertSame(
            ['payroll_statutory_obligation_evidence' => 1],
            $blockedDocument[$this->documentId]->counts,
        );

        foreach ([
            'UPDATE payroll_statutory_obligation_evidence
                SET receipt_reference = receipt_reference WHERE id = ?',
            'DELETE FROM payroll_statutory_obligation_evidence WHERE id = ?',
        ] as $mutation) {
            $rejected = false;
            try {
                $this->db->pdo()->prepare($mutation)->execute([
                    $firstJson['evidence']['id'],
                ]);
            } catch (\PDOException) {
                $rejected = true;
            }
            self::assertTrue($rejected, 'Důkaz musí odmítnout UPDATE i DELETE.');
        }
    }

    public function testFailsClosedForBearerForeignDocumentAndIdempotencyConflict(): void
    {
        $bearer = ($this->action)->record(
            $this->request('POST', 'bearer')
                ->withParsedBody($this->validBody())
                ->withHeader('Idempotency-Key', 'bearer-denied'),
            new Response(),
        );
        self::assertSame(403, $bearer->getStatusCode());
        self::assertSame('session_required', $this->json($bearer)['error']['code']);

        $foreign = $this->validBody();
        $foreign['document_id'] = $this->otherDocumentId;
        $foreignResponse = $this->record($foreign, 'foreign-document');
        self::assertSame(422, $foreignResponse->getStatusCode());

        $first = $this->record($this->validBody(), 'conflict-key');
        self::assertSame(201, $first->getStatusCode());
        $changed = $this->validBody();
        $changed['receipt_reference'] = 'CSSZ-OTHER';
        $conflict = $this->record($changed, 'conflict-key');
        self::assertSame(409, $conflict->getStatusCode());
    }

    public function testAccidentInsuranceRecordsOnlyConfirmedExternalPayment(): void
    {
        $body = $this->validBody();
        $body['agenda_code'] = 'STATUTORY_ACCIDENT_INSURANCE';
        unset($body['employee_id'], $body['manual_submission_confirmed']);
        $body['case_reference'] = 'SYNTH-Q3-2026';
        $body['receipt_reference'] = 'SYNTH-PAYMENT-001';
        $body['payment_amount'] = '1234,56';
        $body['manual_payment_confirmed'] = true;

        $response = $this->record($body, 'accident-payment-1');
        self::assertSame(201, $response->getStatusCode());
        $evidence = $this->json($response)['evidence'];
        self::assertSame('STATUTORY_ACCIDENT_INSURANCE', $evidence['agenda_code']);
        self::assertNull($evidence['employee_id']);
        self::assertSame(123456, (int) $evidence['payment_amount_minor']);
        self::assertSame('CZK', $evidence['payment_currency']);

        $overview = $this->json($this->overview());
        $accidentEvidence = array_values(array_filter(
            $overview['evidence'],
            static fn (array $item): bool => $item['agenda_code']
                === 'STATUTORY_ACCIDENT_INSURANCE',
        ));
        self::assertCount(1, $accidentEvidence);
        self::assertNull($accidentEvidence[0]['employee_id']);
        self::assertNull($accidentEvidence[0]['full_name']);
        self::assertSame(
            123456,
            (int) $accidentEvidence[0]['payment_amount_minor'],
        );

        $unconfirmed = $body;
        $unconfirmed['manual_payment_confirmed'] = false;
        self::assertSame(
            422,
            $this->record($unconfirmed, 'accident-unconfirmed')->getStatusCode(),
        );

        $invalidAmount = $body;
        $invalidAmount['payment_amount'] = '0';
        self::assertSame(
            422,
            $this->record($invalidAmount, 'accident-zero')->getStatusCode(),
        );
    }

    public function testWritePermissionIsRequiredForEvidence(): void
    {
        $readonly = new EffectiveRole(
            77,
            'Syntetické čtení',
            'staff',
            true,
            ['payroll.submissions' => AccessLevel::READ->value],
        );
        $response = ($this->action)->record(
            $this->request('POST', 'session', $readonly)
                ->withParsedBody($this->validBody())
                ->withHeader('Idempotency-Key', 'readonly-denied'),
            new Response(),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('forbidden', $this->json($response)['error']['code']);
    }

    /** @return array<string,mixed> */
    private function validBody(): array
    {
        return [
            'environment' => 'production',
            'period' => '2026-08',
            'agenda_code' => 'NEMPRI',
            'employee_id' => $this->employeeId,
            'case_reference' => 'EDPN-SYNTH-001',
            'receipt_reference' => 'CSSZ-SYNTH-001',
            'completed_on' => '2026-08-20',
            'document_id' => $this->documentId,
            'manual_submission_confirmed' => true,
        ];
    }

    private function record(array $body, string $key): Response
    {
        return ($this->action)->record(
            $this->request('POST')
                ->withParsedBody($body)
                ->withHeader('Idempotency-Key', $key),
            new Response(),
        );
    }

    private function overview(): Response
    {
        return ($this->action)->overview(
            $this->request('GET')->withQueryParams([
                'environment' => 'production',
                'period' => '2026-08',
            ]),
            new Response(),
        );
    }

    private function request(
        string $method,
        string $authMethod = 'session',
        ?EffectiveRole $role = null,
    ): \Psr\Http\Message\ServerRequestInterface {
        $request = (new ServerRequestFactory())
            ->createServerRequest(
                $method,
                '/api/payroll/submissions/statutory-obligations',
            )
            ->withAttribute(
                SupplierScopeMiddleware::ATTR_CURRENT_ID,
                $this->supplierId,
            )
            ->withAttribute(
                AuthMiddleware::ATTR_USER,
                ['id' => $this->userId, 'role' => 'accountant'],
            )
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod);
        if ($role !== null) {
            $request = $request->withAttribute('auth.effective_role', $role);
        }

        return $request;
    }

    private function document(int $supplierId, string $seed): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO documents
                (supplier_id, title, original_name, filename, sha256,
                 mime_type, size_bytes, doc_type, source, uploaded_by,
                 scope, owner_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $supplierId,
            'Syntetická doručenka',
            'synthetic.pdf',
            $seed . '.pdf',
            hash('sha256', $seed),
            'application/pdf',
            128,
            'pdf',
            'manual',
            $this->userId,
            'company',
            null,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
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
