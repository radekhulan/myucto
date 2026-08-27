<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollEnforcementFactsAction;
use MyInvoice\Action\Payroll\PayrollEnforcementAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollInstitutionAccountRepository;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollEnforcementFactsApiTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollEnforcementFactsAction $action;
    private PayrollEnforcementAction $enforcementAction;
    private PayrollInstitutionAccountRepository $institutions;
    private int $supplierId;
    private int $otherSupplierId;
    private int $userId;
    private int $caseId;
    private int $claimId;
    private int $documentId;
    private int $foreignDocumentId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        if (!$this->db->hasTable('payroll_enforcement_case_parties')
            || !$this->db->hasTable('payroll_enforcement_claim_breakdowns')
            || !$this->db->hasTable('payroll_enforcement_recipient_instructions')) {
            self::fail('Migrace právních faktů a instrukcí příjemce musí být spuštěna před testem exekuce.');
        }
        $this->action = $container->get(PayrollEnforcementFactsAction::class);
        $this->enforcementAction = $container->get(PayrollEnforcementAction::class);
        $this->institutions = $container->get(PayrollInstitutionAccountRepository::class);
        $pdo = $this->db->pdo();
        $sourceSupplier = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        if ($sourceSupplier <= 0 || $this->userId <= 0) {
            $this->markTestSkipped('Chybí syntetický výchozí tenant nebo uživatel.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplier);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplier);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)')
            ->execute([$this->supplierId, $this->otherSupplierId]);
        $employeeId = $this->employee($this->supplierId);
        $this->caseId = $this->case($this->supplierId, $employeeId);
        $this->claimId = $this->claim($this->supplierId, $this->caseId);
        $this->documentId = $this->document($this->supplierId, 'facts-own');
        $this->foreignDocumentId = $this->document($this->otherSupplierId, 'facts-foreign');
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        isset($this->db) && $this->db->close();
    }

    public function testPartyIsTenantBoundDmsBackedRevisionAndAppendOnly(): void
    {
        $created = $this->action->appendParty(
            $this->request('POST', "/api/payroll/enforcement/cases/{$this->caseId}/parties")
                ->withParsedBody([
                    'party_role' => 'executor',
                    'effective_from' => '2026-08-01',
                    'party_name' => 'Syntetický exekutorský úřad',
                    'party_reference' => 'EX-SYNTH-1',
                    'source_document_id' => $this->documentId,
                ]),
            new Response(),
            ['id' => (string) $this->caseId],
        );
        self::assertSame(201, $created->getStatusCode());
        $party = $this->json($created)['party'];
        self::assertSame(1, $party['revision_no']);
        $audit = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM activity_log WHERE supplier_id = ?
              AND action = "payroll.enforcement.case_party_recorded"
              AND entity_id = ?',
        );
        $audit->execute([$this->supplierId, $party['id']]);
        self::assertSame(1, (int) $audit->fetchColumn());

        $second = $this->action->appendParty(
            $this->request('POST', "/api/payroll/enforcement/cases/{$this->caseId}/parties")
                ->withParsedBody([
                    'party_role' => 'executor',
                    'effective_from' => '2026-09-01',
                    'party_name' => 'Syntetický exekutorský úřad II',
                    'source_document_id' => $this->documentId,
                ]),
            new Response(),
            ['id' => (string) $this->caseId],
        );
        self::assertSame(201, $second->getStatusCode());
        self::assertSame(2, $this->json($second)['party']['revision_no']);

        $this->expectException(\PDOException::class);
        $this->db->pdo()->prepare('UPDATE payroll_enforcement_case_parties SET party_name = "mutated" WHERE supplier_id = ? AND id = ?')
            ->execute([$this->supplierId, $party['id']]);
    }

    public function testActivationEvidenceWithoutLegalPartiesReturns422(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_enforcement_claims
                SET legal_title_verified = 1,
                    order_or_notice_delivered = 1,
                    priority_classification_verified = 1,
                    due_monetary_claim_verified = 1,
                    enforcement_order_key = "SYNTH-ORDER-1",
                    order_issued_on = "2026-08-01"
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $this->claimId]);
        $account = $this->institutions->create($this->supplierId, [
            'institution_type' => 'other_recipient',
            'institution_code' => 'SYNTH-RECIPIENT',
            'institution_name' => 'Syntetický příjemce',
            'bank_account' => '1000000005/0100',
            'currency_code' => 'CZK',
            'variable_symbol' => '20260801',
            'specific_symbol' => null,
            'constant_symbol' => '0558',
            'valid_from' => '2026-08-01',
            'valid_to' => null,
            'source_kind' => 'official_document',
            'source_reference' => 'synthetic:recipient',
            'verified_on' => '2026-08-01',
        ], $this->userId);

        $response = $this->enforcementAction->updateEvidence(
            $this->request('PUT', "/api/payroll/enforcement/cases/{$this->caseId}/evidence")
                ->withParsedBody([
                    'evidence_complete' => true,
                    'recipient_verified' => true,
                    'recipient_institution_id' => $account['institution_id'],
                    'row_version' => 1,
                ]),
            new Response(),
            ['id' => (string) $this->caseId],
        );

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('validation_failed', $this->json($response)['error']['code'] ?? null);
    }

    public function testForeignDmsDocumentAndWrongBreakdownTotalAreRejectedBeforeWrites(): void
    {
        $foreign = $this->action->appendParty(
            $this->request('POST', "/api/payroll/enforcement/cases/{$this->caseId}/parties")
                ->withParsedBody([
                    'party_role' => 'court', 'effective_from' => '2026-08-01',
                    'party_name' => 'Syntetický soud', 'source_document_id' => $this->foreignDocumentId,
                ]),
            new Response(),
            ['id' => (string) $this->caseId],
        );
        self::assertSame(404, $foreign->getStatusCode());

        $wrong = $this->action->appendBreakdown(
            $this->request('POST', "/api/payroll/enforcement/cases/{$this->caseId}/claims/{$this->claimId}/breakdowns")
                ->withParsedBody([
                    'principal_minor_units' => 999,
                    'interest_minor_units' => 0,
                    'costs_minor_units' => 0,
                    'maintenance_minor_units' => 0,
                    'source_document_id' => $this->documentId,
                ]),
            new Response(),
            ['id' => (string) $this->caseId, 'claimId' => (string) $this->claimId],
        );
        self::assertSame(422, $wrong->getStatusCode());
        self::assertSame(0, $this->breakdownCount());
    }

    public function testBreakdownHasDerivedSumIsImmutableAndUsedClaimCannotBeSilentlyReclassified(): void
    {
        $response = $this->action->appendBreakdown(
            $this->request('POST', "/api/payroll/enforcement/cases/{$this->caseId}/claims/{$this->claimId}/breakdowns")
                ->withParsedBody([
                    'principal_minor_units' => 700,
                    'interest_minor_units' => 100,
                    'costs_minor_units' => 150,
                    'maintenance_minor_units' => 50,
                    'change_reason' => 'Syntetický rozpad z rozhodnutí.',
                    'source_document_id' => $this->documentId,
                ]),
            new Response(),
            ['id' => (string) $this->caseId, 'claimId' => (string) $this->claimId],
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $breakdown = $this->json($response)['breakdown'];
        self::assertSame(1_000, $breakdown['total_minor_units']);

        $this->expectException(\PDOException::class);
        $this->db->pdo()->prepare('UPDATE payroll_enforcement_claim_breakdowns SET principal_minor_units = 1 WHERE supplier_id = ? AND id = ?')
            ->execute([$this->supplierId, $breakdown['id']]);
    }

    public function testRecipientInstructionKeepsReasonWithPartyAccountAndDmsProof(): void
    {
        self::assertNotFalse($this->db->pdo()->query(
            'SHOW COLUMNS FROM payroll_enforcement_recipient_instructions LIKE "change_reason"',
        )->fetchColumn(), 'Migrace 1603 musí přidat důvod platební instrukce.');
        $executor = $this->appendParty('executor', 'Syntetický exekutorský úřad');
        $account = $this->institutions->create($this->supplierId, [
            'institution_type' => 'other_recipient',
            'institution_code' => 'SYNTH-RECIPIENT',
            'institution_name' => 'Syntetický exekutor',
            'bank_account' => '1000000005/0100',
            'currency_code' => 'CZK',
            'variable_symbol' => '20260801',
            'specific_symbol' => null,
            'constant_symbol' => '0558',
            'valid_from' => '2026-08-01',
            'valid_to' => null,
            'source_kind' => 'official_document',
            'source_reference' => 'synthetic:recipient',
            'verified_on' => '2026-08-01',
        ], $this->userId);

        $response = $this->action->appendRecipientInstruction(
            $this->request('POST', "/api/payroll/enforcement/cases/{$this->caseId}/recipient-instructions")
                ->withParsedBody([
                    'effective_from' => '2026-08-01',
                    'recipient_party_id' => $executor['id'],
                    'payment_account_id' => $account['id'],
                    'source_document_id' => $this->documentId,
                    'change_reason' => 'Syntetická změna platební instrukce.',
                ]),
            new Response(),
            ['id' => (string) $this->caseId],
        );

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $instruction = $this->json($response)['instruction'];
        self::assertSame('Syntetická změna platební instrukce.', $instruction['change_reason']);
        self::assertSame($executor['id'], $instruction['recipient_party_id']);
        self::assertSame($account['id'], $instruction['payment_account_id']);

        $futureParty = $this->action->appendParty(
            $this->request('POST', "/api/payroll/enforcement/cases/{$this->caseId}/parties")
                ->withParsedBody([
                    'party_role' => 'executor',
                    'effective_from' => '2026-09-01',
                    'party_name' => 'Syntetický budoucí exekutor',
                    'source_document_id' => $this->documentId,
                ]),
            new Response(),
            ['id' => (string) $this->caseId],
        );
        $futurePartyId = $this->json($futureParty)['party']['id'];
        $notCurrent = $this->action->appendRecipientInstruction(
            $this->request('POST', "/api/payroll/enforcement/cases/{$this->caseId}/recipient-instructions")
                ->withParsedBody([
                    'effective_from' => '2026-08-01',
                    'recipient_party_id' => $futurePartyId,
                    'payment_account_id' => $account['id'],
                    'source_document_id' => $this->documentId,
                ]),
            new Response(),
            ['id' => (string) $this->caseId],
        );
        self::assertSame(422, $notCurrent->getStatusCode());

        $history = $this->action->recipientInstructions(
            $this->request('GET', "/api/payroll/enforcement/cases/{$this->caseId}/recipient-instructions"),
            new Response(),
            ['id' => (string) $this->caseId],
        );
        self::assertSame('Syntetická změna platební instrukce.', $this->json($history)['items'][0]['change_reason']);
    }

    public function testUsedClaimCannotReceiveAnotherBreakdownRevision(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_enforcement_cases SET status = "withhold_and_hold"
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $this->caseId]);
        $blocked = $this->action->appendBreakdown(
            $this->request('POST', "/api/payroll/enforcement/cases/{$this->caseId}/claims/{$this->claimId}/breakdowns")
                ->withParsedBody([
                    'principal_minor_units' => 700,
                    'interest_minor_units' => 100,
                    'costs_minor_units' => 150,
                    'maintenance_minor_units' => 50,
                    'source_document_id' => $this->documentId,
                ]),
            new Response(),
            ['id' => (string) $this->caseId, 'claimId' => (string) $this->claimId],
        );
        self::assertSame(409, $blocked->getStatusCode());
        self::assertSame(0, $this->breakdownCount());
    }

    private function employee(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare('INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active) VALUES (?, "Syntetická osoba", "employee", 1)');
        $stmt->execute([$supplierId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function appendParty(string $role, string $name): array
    {
        $response = $this->action->appendParty(
            $this->request('POST', "/api/payroll/enforcement/cases/{$this->caseId}/parties")
                ->withParsedBody([
                    'party_role' => $role,
                    'effective_from' => '2026-08-01',
                    'party_name' => $name,
                    'source_document_id' => $this->documentId,
                ]),
            new Response(),
            ['id' => (string) $this->caseId],
        );
        self::assertSame(201, $response->getStatusCode());
        return $this->json($response)['party'];
    }

    private function case(int $supplierId, int $employeeId): int
    {
        $stmt = $this->db->pdo()->prepare('INSERT INTO payroll_enforcement_cases (supplier_id, employee_id, case_key, case_kind, effective_from) VALUES (?, ?, ?, "enforcement", "2026-08-01")');
        $stmt->execute([$supplierId, $employeeId, 'facts-case-' . $supplierId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function claim(int $supplierId, int $caseId): int
    {
        $stmt = $this->db->pdo()->prepare('INSERT INTO payroll_enforcement_claims (supplier_id, case_id, claim_key, legal_basis, category, outstanding_minor_units, priority_date, first_payer_delivered_on) VALUES (?, ?, ?, "statutory", "non_priority", 1000, "2026-08-01", "2026-08-01")');
        $stmt->execute([$supplierId, $caseId, 'facts-claim-' . $supplierId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function document(int $supplierId, string $suffix): int
    {
        $hash = hash('sha256', $suffix . '-' . $supplierId);
        $stmt = $this->db->pdo()->prepare('INSERT INTO documents (supplier_id, title, original_name, filename, sha256, mime_type, size_bytes, doc_type, source, uploaded_by, scope) VALUES (?, "Syntetický právní podklad", "legal.pdf", ?, ?, "application/pdf", 1, "pdf", "manual", ?, "company")');
        $stmt->execute([$supplierId, $hash . '.pdf', $hash, $this->userId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function breakdownCount(): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM payroll_enforcement_claim_breakdowns WHERE supplier_id = ?');
        $stmt->execute([$this->supplierId]);
        return (int) $stmt->fetchColumn();
    }

    private function request(string $method, string $uri): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, $uri)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }

    /** @return array<string,mixed> */
    private function json(Response $response): array
    {
        $response->getBody()->rewind();
        $value = json_decode((string) $response->getBody(), true);
        self::assertIsArray($value);
        return $value;
    }
}
