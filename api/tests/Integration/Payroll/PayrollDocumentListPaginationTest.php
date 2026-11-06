<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollDocumentAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollDocumentRepository;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * `GET /payroll/documents` nesmí vrátit celý archiv naráz.
 *
 * Za měsíc vzniká výplatní páska na každý pracovní poměr plus balíček, za rok
 * mzdový list na každého zaměstnance — seznam tedy roste součinem počtu lidí
 * a počtu období. Strop stránky je proto tvrdý: nesmí ho zvednout parametr
 * z URL, a `total` musí hlásit VŠECHNY dokumenty, jinak by uživatel neměl jak
 * poznat, že za stránkou něco je.
 */
#[Group('integration')]
final class PayrollDocumentListPaginationTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD = '2026-06';

    /**
     * Trigger `trg_payroll_document_approved_revision_insert` vyžaduje, aby se
     * `revision_snapshot_hash` dokumentu shodoval s otiskem výsledku revize.
     */
    private const RESULT_HASH = '1111111111111111111111111111111111111111111111111111111111111111';

    private const ANNUAL_HASH = '2222222222222222222222222222222222222222222222222222222222222222';

    private Connection $db;
    private PayrollDocumentAction $action;
    private int $supplierId;
    private int $userId;
    private int $employeeId;
    private int $revisionId;
    private int $annualRevisionId;
    private int $documentSequence = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
            $this->db = $container->get(Connection::class);
            $this->action = $container->get(PayrollDocumentAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        foreach ([
            'payroll_runs',
            'payroll_run_revisions',
            'payroll_generated_documents',
            'payroll_annual_document_revisions',
        ] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped("Chybí integrační tabulka {$table}.");
            }
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')
            ->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')
            ->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')
            ->execute([$this->supplierId]);
        $this->seedAnchors();
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

    /** Strop je tvrdý a `total` počítá všechny dokumenty období. */
    public function testCapCannotBeLiftedFromTheUrl(): void
    {
        $seeded = 12;
        $this->seedPeriodDocuments($seeded);

        $payload = $this->listPeriod(['limit' => '10000']);

        self::assertLessThanOrEqual(
            PayrollDocumentRepository::LIST_MAX_LIMIT,
            count((array) $payload['items']),
            'Strop nejde obejít vyšším limitem z URL.',
        );
        self::assertSame(
            PayrollDocumentRepository::LIST_MAX_LIMIT,
            $payload['limit'],
            'Limit v odpovědi je oříznutý strop, ne to, co přišlo z URL.',
        );
        self::assertSame($seeded, $payload['total']);
    }

    /** Offset musí seznam skutečně posunout, ne vrátit tutéž stránku. */
    public function testOffsetShiftsThePage(): void
    {
        $this->seedPeriodDocuments(5);

        $first = $this->listPeriod(['limit' => '2', 'offset' => '0']);
        $second = $this->listPeriod(['limit' => '2', 'offset' => '2']);

        self::assertCount(2, (array) $first['items']);
        self::assertCount(2, (array) $second['items']);
        self::assertSame(5, $first['total']);
        self::assertSame(2, $second['offset']);
        self::assertSame(
            [],
            array_intersect($this->ids($first), $this->ids($second)),
            'Druhá stránka nesmí zopakovat řádky z první.',
        );
    }

    /** Klíče `items` i `revisions` zůstávají, aby stávající volající nespadli. */
    public function testCollectionKeysArePreserved(): void
    {
        $this->seedPeriodDocuments(1);

        $payload = $this->listPeriod([]);

        self::assertArrayHasKey('items', $payload);
        self::assertArrayHasKey('revisions', $payload);
        self::assertSame(self::PERIOD, $payload['period']);
        self::assertCount(1, (array) $payload['items']);
        self::assertSame(1, $payload['total']);
        self::assertSame(PayrollDocumentRepository::LIST_DEFAULT_LIMIT, $payload['limit']);
        self::assertSame(0, $payload['offset']);
    }

    /** Roční dokumenty mají tentýž strop i tentýž `total`. */
    public function testAnnualListIsPagedToo(): void
    {
        $this->seedAnnualDocuments(4);

        $first = $this->listAnnual(['limit' => '3', 'offset' => '0']);
        $second = $this->listAnnual(['limit' => '3', 'offset' => '3']);

        self::assertCount(3, (array) $first['items']);
        self::assertCount(1, (array) $second['items']);
        self::assertSame(4, $first['total']);
        self::assertSame(2026, $first['year']);
        self::assertSame(
            [],
            array_intersect($this->ids($first), $this->ids($second)),
        );

        $greedy = $this->listAnnual(['limit' => '10000']);
        self::assertSame(PayrollDocumentRepository::LIST_MAX_LIMIT, $greedy['limit']);
    }

    /**
     * Zúžení na osobu musí najít i dokument, který na první stránce není.
     *
     * Dokud zužoval prohlížeč nad načtenou stránkou, vypadalo zúžení na osobu
     * z jiné strany jako „ten člověk tu nic nemá". Druhá osoba se jmenuje tak,
     * aby se v řazení podle jména propadla na konec.
     */
    public function testPeriodNarrowingReachesADocumentBeyondTheFirstPage(): void
    {
        $this->seedPeriodDocuments(4);
        $otherEmployeeId = $this->seedEmployee('Zúžený dokladovaný');
        $offPageId = $this->insertDocument([
            'run_id' => $this->runId(),
            'revision_id' => $this->revisionId,
            'annual_revision_id' => null,
            'document_kind' => 'payslip',
            'revision_snapshot_hash' => self::RESULT_HASH,
        ], $otherEmployeeId);

        $firstPage = $this->listPeriod(['limit' => '2', 'offset' => '0']);
        self::assertNotContains(
            $offPageId,
            $this->ids($firstPage),
            'Předpoklad testu: hledaný dokument na první stránce být nesmí.',
        );

        $narrowed = $this->listPeriod([
            'limit' => '2',
            'offset' => '0',
            'employee_id' => (string) $otherEmployeeId,
        ]);

        self::assertSame([$offPageId], $this->ids($narrowed));
        self::assertSame(1, $narrowed['total'], 'Total musí být zúžený stejně jako stránka.');
        self::assertSame($otherEmployeeId, $narrowed['employee_id']);
    }

    /** Roční záložka zužuje stejně — a taky až za koncem první stránky. */
    public function testAnnualNarrowingReachesADocumentBeyondTheFirstPage(): void
    {
        $this->seedAnnualDocuments(4);
        $otherEmployeeId = $this->seedEmployee('Zúžený dokladovaný');
        $offPageId = $this->insertDocument([
            'run_id' => null,
            'revision_id' => null,
            'annual_revision_id' => $this->seedAnnualRevision($otherEmployeeId),
            'document_kind' => 'payroll_sheet',
            'revision_snapshot_hash' => self::ANNUAL_HASH,
        ], $otherEmployeeId);

        $firstPage = $this->listAnnual(['limit' => '2', 'offset' => '0']);
        self::assertNotContains($offPageId, $this->ids($firstPage));

        $narrowed = $this->listAnnual([
            'limit' => '2',
            'offset' => '0',
            'employee_id' => (string) $otherEmployeeId,
        ]);

        self::assertSame([$offPageId], $this->ids($narrowed));
        self::assertSame(1, $narrowed['total']);
        self::assertSame($otherEmployeeId, $narrowed['employee_id']);
    }

    /**
     * Cizí ani neexistující osoba nesmí vrátit celý archiv období.
     *
     * Prázdný výsledek je poznatelný stav; tiché zobrazení všech je lež.
     */
    public function testUnknownNarrowingReturnsNothingInsteadOfEverything(): void
    {
        $this->seedPeriodDocuments(3);

        $payload = $this->listPeriod(['employee_id' => (string) ($this->employeeId + 10_000)]);

        self::assertSame([], $this->ids($payload));
        self::assertSame(0, $payload['total']);
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<int>
     */
    private function ids(array $payload): array
    {
        $ids = [];
        foreach ((array) $payload['items'] as $document) {
            self::assertIsArray($document);
            $ids[] = (int) $document['id'];
        }

        return $ids;
    }

    /**
     * @param array<string,string> $query
     * @return array<string,mixed>
     */
    private function listPeriod(array $query): array
    {
        $response = $this->action->list(
            $this->request()->withQueryParams(['period' => self::PERIOD, ...$query]),
            new Response(),
            [],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response);
    }

    /**
     * @param array<string,string> $query
     * @return array<string,mixed>
     */
    private function listAnnual(array $query): array
    {
        $response = $this->action->listAnnual(
            $this->request()->withQueryParams(['year' => '2026', ...$query]),
            new Response(),
            [],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response);
    }

    /** Běh, revize a roční revize, ke kterým se dokumenty kotví. */
    private function seedAnchors(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetický dokladovaný", "employee", 1)'
        )->execute([$this->supplierId]);
        $this->employeeId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status, current_revision_no)
             VALUES (?, ?, ?, "approved", 1)'
        )->execute([$this->supplierId, self::PERIOD . '-01', self::PERIOD . '-15']);
        $runId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind, status,
                 schema_version, ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json, result_snapshot_hash,
                 idempotency_key_hash)
             VALUES (?, ?, 1, "regular", "approved", "v1", ?, "{}", ?, "{}", ?,
                     UNHEX(?))'
        )->execute([
            $this->supplierId,
            $runId,
            str_repeat('a', 64),
            str_repeat('b', 64),
            self::RESULT_HASH,
            str_repeat('c', 64),
        ]);
        $this->revisionId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO payroll_annual_document_revisions
                (supplier_id, employee_id, tax_year, purpose, revision_no,
                 snapshot_ciphertext, snapshot_hash, source_manifest_json,
                 source_manifest_hash, approved_at)
             VALUES (?, ?, 2026, "payroll_sheet", 1, "", ?, "{}", ?,
                     "2026-12-31 12:00:00")'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            self::ANNUAL_HASH,
            str_repeat('e', 64),
        ]);
        $this->annualRevisionId = (int) $pdo->lastInsertId();
    }

    private function seedEmployee(string $fullName): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, ?, "employee", 1)'
        )->execute([$this->supplierId, $fullName]);

        return (int) $pdo->lastInsertId();
    }

    private function seedAnnualRevision(int $employeeId): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_annual_document_revisions
                (supplier_id, employee_id, tax_year, purpose, revision_no,
                 snapshot_ciphertext, snapshot_hash, source_manifest_json,
                 source_manifest_hash, approved_at)
             VALUES (?, ?, 2026, "payroll_sheet", 1, "", ?, "{}", ?,
                     "2026-12-31 12:00:00")'
        )->execute([
            $this->supplierId,
            $employeeId,
            self::ANNUAL_HASH,
            str_repeat('f', 64),
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function seedPeriodDocuments(int $count): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $this->insertDocument([
                'run_id' => $this->runId(),
                'revision_id' => $this->revisionId,
                'annual_revision_id' => null,
                'document_kind' => 'payslip',
                'revision_snapshot_hash' => self::RESULT_HASH,
            ]);
        }
    }

    private function seedAnnualDocuments(int $count): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $this->insertDocument([
                'run_id' => null,
                'revision_id' => null,
                'annual_revision_id' => $this->annualRevisionId,
                'document_kind' => 'payroll_sheet',
                'revision_snapshot_hash' => self::ANNUAL_HASH,
            ]);
        }
    }

    /**
     * @param array<string,mixed> $anchor
     * @return int id vloženého dokumentu
     */
    private function insertDocument(array $anchor, ?int $employeeId = null): int
    {
        $ordinal = ++$this->documentSequence;
        // `chk_payroll_document_hashes` vyžaduje `storage_key = file_sha256`.
        $fileHash = hash('sha256', 'file-' . $ordinal);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_generated_documents
                (supplier_id, run_id, revision_id, annual_revision_id, employee_id,
                 document_kind, document_revision_no, revision_snapshot_hash,
                 source_snapshot_hash, template_version, renderer_version,
                 file_sha256, size_bytes, mime_type, storage_key,
                 suggested_filename, idempotency_key_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "t1", "r1", ?, 1024,
                     "application/pdf", ?, ?, UNHEX(?))'
        )->execute([
            $this->supplierId,
            $anchor['run_id'],
            $anchor['revision_id'],
            $anchor['annual_revision_id'],
            $employeeId ?? $this->employeeId,
            $anchor['document_kind'],
            $ordinal,
            $anchor['revision_snapshot_hash'],
            str_repeat('2', 64),
            $fileHash,
            $fileHash,
            'doklad-' . $ordinal . '.pdf',
            hash('sha256', 'idem-' . $ordinal),
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function runId(): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT run_id FROM payroll_run_revisions WHERE supplier_id = ? AND id = ?',
        );
        $statement->execute([$this->supplierId, $this->revisionId]);

        return (int) $statement->fetchColumn();
    }

    private function request(): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/payroll/documents')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
