<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Cash;

use MyInvoice\Action\Document\DocumentsAction;
use MyInvoice\Action\Document\LinkSearchAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\CashDocumentRepository;
use MyInvoice\Repository\DocumentLinkRepository;
use MyInvoice\Repository\DocumentRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Přílohy (DMS dokumenty) k pokladnímu dokladu přes `document_links(entity_type='cash_document')`.
 *
 * Dvě čerstvé firmy v transakci s rollbackem. Každá má pokladnu, pokladní doklad
 * a DMS dokument. Test hlídá tři vrstvy tenantové izolace: akci (scope guard
 * `entityBelongsToSupplier`), repozitář a databázi (trigger z migrace), a k tomu
 * úklid vazeb při smazání dokladu — jinak by osiřelá vazba navždy blokovala
 * vysypání dokumentu z koše (`DocumentDeletionGuard`).
 */
#[Group('integration')]
final class CashDocumentAttachmentTest extends TestCase
{
    private Connection $db;
    private DocumentsAction $documentsAction;
    private LinkSearchAction $linkSearch;
    private DocumentLinkRepository $links;
    private CashDocumentRepository $cashDocuments;
    private DocumentRepository $documents;

    private bool $inTx = false;
    private int $userId = 0;
    private int $supplierA = 0;
    private int $supplierB = 0;
    private int $cashA = 0;
    private int $cashB = 0;
    private int $docA = 0;
    private int $docB = 0;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db              = $c->get(Connection::class);
            $this->documentsAction = $c->get(DocumentsAction::class);
            $this->linkSearch      = $c->get(LinkSearchAction::class);
            $this->links           = $c->get(DocumentLinkRepository::class);
            $this->cashDocuments   = $c->get(CashDocumentRepository::class);
            $this->documents       = $c->get(DocumentRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT MIN(id) FROM users')->fetchColumn() ?: 0);
        if ($currencyId === 0 || $vatRateId === 0 || $czId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (currency/vat_rate/country/user) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $this->supplierA = $this->makeSupplier('Pokladna přílohy A s.r.o.', $czId, $currencyId, $vatRateId);
        $this->supplierB = $this->makeSupplier('Pokladna přílohy B s.r.o.', $czId, $currencyId, $vatRateId);
        $this->cashA = $this->makeCashDocument($this->supplierA, 'PPD-2093-9001', 'Nákup kancelářských potřeb');
        $this->cashB = $this->makeCashDocument($this->supplierB, 'PPD-2093-9002', 'Nákup cizí firmy');
        $this->docA = $this->makeDocument($this->supplierA, 'Účtenka A');
        $this->docB = $this->makeDocument($this->supplierB, 'Účtenka B');
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
    }

    public function testCashDocumentIsKnownEntityType(): void
    {
        self::assertContains('cash_document', DocumentLinkRepository::ENTITY_TYPES);
        self::assertTrue($this->links->entityBelongsToSupplier('cash_document', $this->cashA, $this->supplierA));
        self::assertFalse($this->links->entityBelongsToSupplier('cash_document', $this->cashB, $this->supplierA));
    }

    public function testAttachListLabelAndDetach(): void
    {
        $added = $this->call('addLink', 'POST', $this->supplierA, ['id' => (string) $this->docA],
            ['entity_type' => 'cash_document', 'entity_id' => $this->cashA]);
        self::assertSame(200, $added['status']);

        $link = $this->findLink($added['body']['links'] ?? [], $this->cashA);
        self::assertNotNull($link, 'Vazba na pokladní doklad se vrátí v odpovědi.');
        self::assertStringContainsString('PPD-2093-9001', (string) $link['label'],
            'Popisek vazby nese číslo pokladního dokladu, ne jen #id.');

        $listed = $this->call('byEntity', 'GET', $this->supplierA, ['type' => 'cash_document', 'id' => (string) $this->cashA]);
        self::assertSame(200, $listed['status']);
        self::assertSame([$this->docA], array_map(static fn($d) => (int) $d['id'], $listed['body']['documents'] ?? []));

        $removed = $this->call('removeLink', 'DELETE', $this->supplierA, ['id' => (string) $this->docA],
            ['entity_type' => 'cash_document', 'entity_id' => $this->cashA]);
        self::assertSame(200, $removed['status']);
        self::assertSame(0, $this->linkCount($this->docA));
    }

    public function testForeignCashDocumentIsRejected(): void
    {
        $res = $this->call('addLink', 'POST', $this->supplierA, ['id' => (string) $this->docA],
            ['entity_type' => 'cash_document', 'entity_id' => $this->cashB]);
        self::assertSame(404, $res['status'], 'Doklad cizí firmy se připojit nesmí.');
        self::assertSame(0, $this->linkCount($this->docA));

        // Ani výpis přes cizí firmu nic nevrátí.
        $this->links->attach($this->supplierB, $this->docB, 'cash_document', $this->cashB);
        $listed = $this->call('byEntity', 'GET', $this->supplierA, ['type' => 'cash_document', 'id' => (string) $this->cashB]);
        self::assertSame([], $listed['body']['documents'] ?? null);
    }

    public function testDatabaseRejectsCrossTenantLink(): void
    {
        $insert = $this->db->pdo()->prepare(
            'INSERT INTO document_links (document_id, supplier_id, entity_type, entity_id) VALUES (?, ?, ?, ?)'
        );

        // Vlastní doklad projde.
        $insert->execute([$this->docA, $this->supplierA, 'cash_document', $this->cashA]);
        self::assertSame(1, $this->linkCount($this->docA));

        // Doklad cizí firmy databáze odmítne i mimo aplikační guard.
        $rejected = false;
        try {
            $insert->execute([$this->docB, $this->supplierB, 'cash_document', $this->cashA]);
        } catch (\PDOException $e) {
            $rejected = true;
            self::assertSame('45000', $e->errorInfo[0] ?? null);
        }
        self::assertTrue($rejected, 'Trigger odmítne vazbu na pokladní doklad jiné firmy.');
        self::assertSame(0, $this->linkCount($this->docB));

        // Přepsání existující vazby na cizí doklad taky neprojde.
        $rejected = false;
        try {
            $this->db->pdo()->prepare(
                "UPDATE document_links SET entity_id = ? WHERE document_id = ? AND entity_type = 'cash_document'"
            )->execute([$this->cashB, $this->docA]);
        } catch (\PDOException $e) {
            $rejected = true;
            self::assertSame('45000', $e->errorInfo[0] ?? null);
        }
        self::assertTrue($rejected, 'Trigger hlídá i UPDATE vazby.');
    }

    public function testDeletingCashDocumentDropsItsLinks(): void
    {
        $this->links->attach($this->supplierA, $this->docA, 'cash_document', $this->cashA);
        $this->links->attach($this->supplierB, $this->docB, 'cash_document', $this->cashB);
        self::assertSame(1, $this->linkCount($this->docA));

        $this->cashDocuments->deleteDraft($this->supplierA, $this->cashA);

        self::assertSame(0, $this->linkCount($this->docA),
            'Po smazání dokladu nesmí zůstat osiřelá vazba — blokovala by vysypání dokumentu z koše.');
        $kept = $this->db->pdo()->prepare('SELECT COUNT(*) FROM documents WHERE id = ? AND supplier_id = ?');
        $kept->execute([$this->docA, $this->supplierA]);
        self::assertSame(1, (int) $kept->fetchColumn(), 'Samotný sken v Dokumentech zůstává.');
        self::assertSame(1, $this->linkCount($this->docB), 'Vazby jiné firmy smazání nezasáhne.');
    }

    public function testLinkSearchFindsOnlyOwnCashDocuments(): void
    {
        $req = $this->request('GET', $this->supplierA)
            ->withQueryParams(['q' => 'PPD-2093-900', 'types' => 'cash_document']);
        $resp = ($this->linkSearch)($req, new Psr7Response());
        $resp->getBody()->rewind();
        $body = json_decode((string) $resp->getBody(), true);

        $ids = array_map(
            static fn($r) => $r['entity_type'] . ':' . $r['entity_id'],
            is_array($body) ? ($body['results'] ?? []) : [],
        );
        self::assertSame(['cash_document:' . $this->cashA], $ids);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeSupplier(string $name, int $czId, int $currencyId, int $vatRateId): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, "cash-attachments@example.com", ?, ?)'
        )->execute([$name, $czId, $currencyId, $vatRateId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function makeCashDocument(int $supplierId, string $number, string $description): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO cash_registers (supplier_id, name, currency_code, account_code, is_default)
             VALUES (?, 'Hlavní pokladna', 'CZK', '211', 1)"
        )->execute([$supplierId]);
        $registerId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO cash_documents
                (supplier_id, register_id, doc_type, purpose, doc_number, issue_date, partner_name,
                 description, vat_mode, total_amount, currency_code, status, created_by)
             VALUES (?, ?, 'out', 'other', ?, '2093-03-15', 'Papírnictví', ?, 'none', 250, 'CZK', 'draft', ?)"
        )->execute([$supplierId, $registerId, $number, $description, $this->userId]);
        return (int) $pdo->lastInsertId();
    }

    private function makeDocument(int $supplierId, string $title): int
    {
        return $this->documents->insert([
            'supplier_id'   => $supplierId,
            'folder_id'     => null,
            'title'         => $title,
            'description'   => null,
            'original_name' => 'uctenka.pdf',
            'filename'      => str_repeat('c', 64),
            'sha256'        => str_repeat('c', 64),
            'mime_type'     => 'application/pdf',
            'size_bytes'    => 100,
            'doc_type'      => 'pdf',
            'uploaded_by'   => $this->userId,
            'scope'         => 'company',
            'owner_user_id' => null,
        ]);
    }

    private function linkCount(int $documentId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM document_links WHERE document_id = ? AND entity_type = 'cash_document'"
        );
        $stmt->execute([$documentId]);
        return (int) $stmt->fetchColumn();
    }

    /** @param list<array<string,mixed>> $links */
    private function findLink(array $links, int $cashId): ?array
    {
        foreach ($links as $l) {
            if (($l['entity_type'] ?? null) === 'cash_document' && (int) ($l['entity_id'] ?? 0) === $cashId) {
                return $l;
            }
        }
        return null;
    }

    private function request(string $http, int $sid): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($http, '/api/documents')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $sid)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant']);
    }

    /**
     * @param array<string,string> $args
     * @param array<string,mixed>  $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function call(string $method, string $http, int $sid, array $args, array $body = []): array
    {
        $req = $this->request($http, $sid);
        if ($body !== []) {
            $req = $req->withParsedBody($body);
        }
        $resp = $this->documentsAction->{$method}($req, new Psr7Response(), $args);
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
