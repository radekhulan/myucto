<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Document\DocumentFileAction;
use MyInvoice\Action\Document\DocumentsAction;
use MyInvoice\Action\Payroll\PayrollEnforcementAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Repository\DocumentViewerContext;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Exekuční příkaz z datové schránky je ZFO kontejner s přílohami. Skrýt jen
 * navázanou přílohu nestačí: kontejner nese tytéž bajty. Bez oprávnění k důkazu
 * se proto skrývá celá zpráva, s oprávněním zůstává vidět celá.
 *
 * Druhá část hlídá hromadné akce Dokumentů: samotné `documents.upload` nesmí
 * hromadně mazat ani přesouvat.
 */
#[Group('integration')]
final class PayrollDmsEvidenceFamilyTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private DocumentsAction $documentsAction;
    private DocumentFileAction $documentFileAction;
    private DocumentRepository $documents;
    private PayrollEnforcementAction $enforcement;
    private int $supplierId;
    private int $employeeId;
    private int $userId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        if ($container === null) {
            throw new \RuntimeException('DI kontejner není dostupný.');
        }
        $db = $container->get(Connection::class);
        $documentsAction = $container->get(DocumentsAction::class);
        $documentFileAction = $container->get(DocumentFileAction::class);
        $documents = $container->get(DocumentRepository::class);
        $enforcement = $container->get(PayrollEnforcementAction::class);
        if (
            !$db instanceof Connection
            || !$documentsAction instanceof DocumentsAction
            || !$documentFileAction instanceof DocumentFileAction
            || !$documents instanceof DocumentRepository
            || !$enforcement instanceof PayrollEnforcementAction
        ) {
            throw new \RuntimeException('Služby Dokumentů nejsou dostupné.');
        }
        $this->db = $db;
        if (!$db->hasTable('payroll_enforcement_case_parties')) {
            $this->markTestSkipped('Migrace 1600 neproběhla.');
        }
        $this->documentsAction = $documentsAction;
        $this->documentFileAction = $documentFileAction;
        $this->documents = $documents;
        $this->enforcement = $enforcement;
        $pdo = $db->pdo();
        $sourceSupplierId = $this->firstId($pdo, 'supplier');
        $this->userId = $this->firstId($pdo, 'users');
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')
            ->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, "Syntetický povinný", "employee", "hpp", 1, 1, 0, 42000, 0, 1)'
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
    }

    public function testLinkedZfoAttachmentHidesWholeDataMessageWithoutEnforcementPermission(): void
    {
        $container = $this->document('datova-zprava.zfo', str_repeat('1', 64));
        $order = $this->document('exekucni-prikaz.pdf', str_repeat('2', 64), $container);
        $sibling = $this->document('vyrozumeni.pdf', str_repeat('3', 64), $container);
        $unrelated = $this->document('faktura.pdf', str_repeat('4', 64));

        $caseResponse = $this->enforcement->create(
            $this->request('POST', '/api/payroll/enforcement/cases')->withParsedBody([
                'employee_id' => $this->employeeId,
                'case_kind' => 'enforcement',
                'effective_from' => '2026-05-20',
            ]),
            new Response(),
        );
        self::assertSame(201, $caseResponse->getStatusCode(), (string) $caseResponse->getBody());
        $case = PayrollTimeValue::row($this->json($caseResponse)['case'] ?? null, 'case');
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_enforcement_case_parties
                (supplier_id, case_id, party_role, revision_no, effective_from,
                 party_name, source_document_id, source_document_sha256, created_by)
             VALUES (?, ?, "court", 1, "2026-05-20", "Syntetický exekutor", ?, ?, ?)',
        )->execute([
            $this->supplierId,
            PayrollTimeValue::int($case['id'] ?? null, 'id'),
            $order,
            str_repeat('2', 64),
            $this->userId,
        ]);

        $documentsOnly = new EffectiveRole(201, 'Jen Dokumenty', 'staff', true, ['documents' => 1]);
        foreach ([$container, $order, $sibling] as $documentId) {
            self::assertSame(404, $this->documentsAction->get(
                $this->request('GET', "/api/documents/{$documentId}")
                    ->withAttribute('auth.effective_role', $documentsOnly),
                new Response(),
                ['id' => (string) $documentId],
            )->getStatusCode(), "dokument {$documentId}");
            self::assertSame(404, $this->documentFileAction->download(
                $this->request('GET', "/api/documents/{$documentId}/download")
                    ->withAttribute('auth.effective_role', $documentsOnly),
                new Response(),
                ['id' => (string) $documentId],
            )->getStatusCode(), "stažení {$documentId}");
            self::assertNull($this->documents->findRaw(
                $documentId,
                $this->supplierId,
                DocumentViewerContext::forUser($this->userId),
            ));
        }
        self::assertSame(200, $this->documentsAction->get(
            $this->request('GET', "/api/documents/{$unrelated}")
                ->withAttribute('auth.effective_role', $documentsOnly),
            new Response(),
            ['id' => (string) $unrelated],
        )->getStatusCode());

        $enforcementReader = new EffectiveRole(
            202,
            'Mzdová účetní pro exekuce',
            'staff',
            true,
            ['documents' => 1, 'payroll.enforcement' => 1],
        );
        foreach ([$container, $order, $sibling] as $documentId) {
            self::assertSame(200, $this->documentsAction->get(
                $this->request('GET', "/api/documents/{$documentId}")
                    ->withAttribute('auth.effective_role', $enforcementReader),
                new Response(),
                ['id' => (string) $documentId],
            )->getStatusCode(), "dokument {$documentId} s oprávněním");
        }
    }

    public function testLinkedAttachmentHidesContainerForOtherEvidenceKindsToo(): void
    {
        if (!$this->db->hasColumn('payroll_person_health_coverage_history', 'health_evidence_document_id')) {
            $this->markTestSkipped('Migrace 1602 neproběhla.');
        }
        $container = $this->document('pojistovna.zfo', str_repeat('5', 64));
        $attachment = $this->document('potvrzeni.pdf', str_repeat('6', 64), $container);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_health_coverage_history
                (supplier_id, employee_id, jurisdiction, insurer_status, insurer_code,
                 health_evidence_document_id, health_evidence_document_sha256,
                 effective_from, created_by, updated_by)
             VALUES (?, ?, "czech_regime_verified", "verified", "111", ?, ?,
                     "2026-01-01", ?, ?)',
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $attachment,
            str_repeat('6', 64),
            $this->userId,
            $this->userId,
        ]);

        self::assertNull($this->documents->findRaw(
            $container,
            $this->supplierId,
            DocumentViewerContext::forUser($this->userId),
        ));
        self::assertNotNull($this->documents->findRaw(
            $container,
            $this->supplierId,
            DocumentViewerContext::forUser($this->userId, false, false, false, false, true),
        ));
    }

    /**
     * Podmínky rodiny důkazů se musí dát materializovat jednou za dotaz.
     * Korelovaná podmínka s OR se spouštěla pro každý řádek dokumentů, takže
     * seznam a počty u firmy s tisíci mzdovými podáními zpomalovaly úměrně
     * součinu obou tabulek.
     */
    public function testEvidenceFamilyConditionsAreNotDependentSubqueries(): void
    {
        [$clause, $params] = \MyInvoice\Repository\DocumentVisibility::clause(
            DocumentViewerContext::forUser($this->userId),
            'd',
        );
        // Na malých testovacích datech optimalizátor podle ceny zvolí IN->EXISTS.
        // Bez něj musí jít každá podmínka materializovat; korelované OR nejde nikdy.
        $pdo = $this->db->pdo();
        $switch = (string) $pdo->query("SELECT @@SESSION.optimizer_switch")->fetchColumn();
        $pdo->exec("SET SESSION optimizer_switch = 'in_to_exists=off,materialization=on'");
        try {
            $stmt = $pdo->prepare("EXPLAIN SELECT COUNT(*) FROM documents d WHERE d.supplier_id = ? {$clause}");
            $stmt->execute([$this->supplierId, ...$params]);
            $plan = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } finally {
            $pdo->exec("SET SESSION optimizer_switch = " . $pdo->quote($switch));
        }
        $dependent = [];
        foreach ($plan as $row) {
            // Odkazy na mzdové doklady mají vlastní, užší podmínku bez sourozenců.
            if (str_starts_with((string) $row['select_type'], 'DEPENDENT')
                && !in_array($row['table'], ['payroll_document_link', 'linked_payroll_document'], true)) {
                $dependent[] = $row['table'];
            }
        }

        self::assertGreaterThan(20, substr_count($clause, 'NOT IN'), 'Klauzule neobsahuje podmínky rodiny důkazů.');
        self::assertSame([], $dependent, 'Závislé poddotazy ve viditelnosti dokumentů: ' . implode(', ', $dependent));
    }

    public function testBulkDeleteAndMoveRequireTheirOwnPermissions(): void
    {
        $first = $this->document('a.pdf', str_repeat('7', 64));
        $second = $this->document('b.pdf', str_repeat('8', 64));
        $uploader = new EffectiveRole(
            203,
            'Jen nahrávání',
            'staff',
            true,
            ['documents' => 1, 'documents.upload' => 2],
        );

        foreach (['delete' => [], 'move' => ['folder_id' => null]] as $action => $extra) {
            $response = $this->documentsAction->bulk(
                $this->request('POST', '/api/documents/bulk')
                    ->withAttribute('auth.effective_role', $uploader)
                    ->withParsedBody(['action' => $action, 'ids' => [$first, $second]] + $extra),
                new Response(),
            );
            self::assertSame(403, $response->getStatusCode(), $action);
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM documents WHERE supplier_id = ? AND deleted_at IS NULL AND id IN (?, ?)'
        );
        $stmt->execute([$this->supplierId, $first, $second]);
        self::assertSame(2, (int) $stmt->fetchColumn());

        $deleter = new EffectiveRole(
            204,
            'Mazání dokumentů',
            'staff',
            true,
            ['documents' => 1, 'documents.delete' => 2],
        );
        $response = $this->documentsAction->bulk(
            $this->request('POST', '/api/documents/bulk')
                ->withAttribute('auth.effective_role', $deleter)
                ->withParsedBody(['action' => 'delete', 'ids' => [$first, $second]]),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(2, $this->json($response)['affected']);
    }

    private function document(string $name, string $sha256, ?int $parentId = null): int
    {
        $this->db->pdo()->prepare(
            "INSERT INTO documents
                (supplier_id, title, original_name, filename, sha256, mime_type,
                 size_bytes, doc_type, source, uploaded_by, scope, parent_document_id)
             VALUES (?, ?, ?, ?, ?, 'application/pdf', 1, 'pdf', 'manual', ?, 'company', ?)"
        )->execute([
            $this->supplierId,
            $name,
            $name,
            $sha256 . '.pdf',
            $sha256,
            $this->userId,
            $parentId,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function firstId(PDO $pdo, string $table): int
    {
        if (!in_array($table, ['supplier', 'users'], true)) {
            throw new \InvalidArgumentException('Nepodporovaná testovací tabulka.');
        }
        $stmt = $pdo->query("SELECT id FROM {$table} ORDER BY id LIMIT 1");
        $value = $stmt === false ? false : $stmt->fetchColumn();

        return $value === false ? 0 : (int) $value;
    }

    private function request(string $method, string $uri): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $uri)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $data = json_decode((string) $response->getBody(), true);

        return is_array($data) ? $data : [];
    }
}
