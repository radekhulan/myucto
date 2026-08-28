<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Action\Document\DocumentsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\DocumentFileRepository;
use MyInvoice\Repository\DocumentFolderRepository;
use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Repository\DocumentViewerContext;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Integrační test DB vrstvy sekce Dokumenty: insert, fulltext, soft-delete (koš),
 * restore, dedup (countBySha union), strom složek + per-user scope guard (Epic F7).
 * Volá repozitáře přímo (bez HTTP), potřebuje jen DB. Vše per-supplier, uklízí po sobě.
 */
#[Group('integration')]
final class DocumentRepositoryTest extends TestCase
{
    private PDO $pdo;
    private DocumentRepository $docs;
    private DocumentFileRepository $files;
    private DocumentFolderRepository $folders;
    private DocumentsAction $action;
    private int $supplierId;
    private DocumentViewerContext $admin;
    /** @var list<int> */
    private array $createdDocs = [];
    /** @var list<int> */
    private array $createdFolders = [];
    /** @var list<int> */
    private array $createdFiles = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        try {
            $app = Bootstrap::buildApp();
            $c = $app->getContainer();
            if ($c === null) $this->markTestSkipped('Container not available');
            $this->pdo = $c->get(Connection::class)->pdo();
            $this->docs = $c->get(DocumentRepository::class);
            $this->files = $c->get(DocumentFileRepository::class);
            $this->folders = $c->get(DocumentFolderRepository::class);
            $this->action = $c->get(DocumentsAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI unavailable: ' . $e->getMessage());
        }
        $this->supplierId = (int) $this->pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        if ($this->supplierId <= 0) $this->markTestSkipped('No supplier');
        $this->admin = DocumentViewerContext::admin();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $id) {
            $this->pdo->prepare('DELETE FROM document_files WHERE id = ?')->execute([$id]);
        }
        foreach ($this->createdDocs as $id) {
            // document_files na tomto dokumentu cascade-nou (fk_df_document ON DELETE CASCADE)
            $this->pdo->prepare('DELETE FROM documents WHERE id = ?')->execute([$id]);
        }
        foreach ($this->createdFolders as $id) {
            $this->pdo->prepare('DELETE FROM document_folders WHERE id = ?')->execute([$id]);
        }
    }

    private function insertDoc(string $title, string $sha, ?int $folderId = null, string $scope = 'company', ?int $ownerUserId = null): int
    {
        $id = $this->docs->insert([
            'supplier_id'   => $this->supplierId,
            'folder_id'     => $folderId,
            'title'         => $title,
            'description'   => null,
            'original_name' => $title . '.pdf',
            'filename'      => substr($sha, 0, 8) . '-' . $title . '.pdf',
            'sha256'        => $sha,
            'mime_type'     => 'application/pdf',
            'size_bytes'    => 1000,
            'doc_type'      => 'pdf',
            'uploaded_by'   => null,
            'scope'         => $scope,
            'owner_user_id' => $ownerUserId,
        ]);
        $this->createdDocs[] = $id;
        return $id;
    }

    public function testInsertFindAndSupplierScope(): void
    {
        $id = $this->insertDoc('UNIQTESTDOC alpha', str_repeat('1', 64));
        $found = $this->docs->find($id, $this->supplierId, $this->admin);
        self::assertNotNull($found);
        self::assertSame('UNIQTESTDOC alpha', $found['title']);
        // Jiný supplier nevidí
        self::assertNull($this->docs->find($id, $this->supplierId + 99999, $this->admin));
    }

    public function testFulltextSearchFindsByTitleAndContent(): void
    {
        $id = $this->insertDoc('ZZQXMARKER smlouva', str_repeat('2', 64));
        $this->docs->setText($id, 'Obsah dokumentu se slovem KONTROLNIMARKER uvnitř.', 'extracted');

        $byTitle = $this->docs->search($this->supplierId, 'ZZQXMARKER', $this->admin);
        self::assertContains($id, array_map(static fn($r) => $r['id'], $byTitle));

        $byContent = $this->docs->search($this->supplierId, 'KONTROLNIMARKER', $this->admin);
        self::assertContains($id, array_map(static fn($r) => $r['id'], $byContent));
    }

    public function testExtractedTextEndpointReturnsBoundedChunkAndKeepsSupplierScope(): void
    {
        $id = $this->insertDoc('TEXTCHUNKDOC', str_repeat('e', 64));
        $text = str_repeat('A', 1200) . str_repeat('B', 1200);
        $this->docs->setText($id, $text, 'extracted');

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', "/api/documents/$id/text")
            ->withQueryParams(['offset' => 1100, 'max_chars' => 1000])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId);
        $response = $this->action->text($request, (new ResponseFactory())->createResponse(), ['id' => $id]);
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('extracted', $body['text_status']);
        self::assertSame(2400, $body['total_chars']);
        self::assertSame(1100, $body['offset']);
        self::assertSame(1000, mb_strlen($body['content']));
        self::assertSame(str_repeat('A', 100) . str_repeat('B', 900), $body['content']);
        self::assertTrue($body['has_more']);

        $foreign = $request->withAttribute(
            SupplierScopeMiddleware::ATTR_CURRENT_ID,
            $this->supplierId + 99999,
        );
        $response = $this->action->text($foreign, (new ResponseFactory())->createResponse(), ['id' => $id]);
        self::assertSame(404, $response->getStatusCode());
    }

    public function testSoftDeleteRestoreLifecycle(): void
    {
        $id = $this->insertDoc('TRASHTESTDOC', str_repeat('3', 64));
        self::assertTrue($this->docs->softDelete($id, $this->supplierId, null));

        // Po smazání není v aktivním listu, ale je v koši.
        self::assertNull($this->docs->find($id, $this->supplierId, $this->admin));
        self::assertNotNull($this->docs->find($id, $this->supplierId, $this->admin, true));
        $trashIds = array_map(static fn($r) => $r['id'], $this->docs->listTrash($this->supplierId, $this->admin));
        self::assertContains($id, $trashIds);

        self::assertTrue($this->docs->restore($id, $this->supplierId));
        self::assertNotNull($this->docs->find($id, $this->supplierId, $this->admin));
    }

    public function testDedupCountBySha(): void
    {
        $sha = str_repeat('4', 64);
        $a = $this->insertDoc('DEDUP A', $sha);
        $b = $this->insertDoc('DEDUP B', $sha);

        // Při mazání obou: kromě [a,b] nezbývá žádný odkaz → orphan.
        self::assertSame(0, $this->docs->countBySha($this->supplierId, $sha, [$a, $b]));
        // Při mazání jen a: b stále drží soubor → není orphan.
        self::assertGreaterThanOrEqual(1, $this->docs->countBySha($this->supplierId, $sha, [$a]));
    }

    /**
     * §4.4 union ref-counting: document_files řádek sdílející sha drží bajty naživu,
     * i když je jeho documents řádek vyloučený z počtu; při součtu 0 → orphan.
     */
    public function testCountByShaUnionWithDocumentFiles(): void
    {
        $sha = str_repeat('7', 64);
        $docId = $this->insertDoc('UNIONDOC', $sha);
        $fileId = $this->files->add([
            'document_id' => $docId,
            'supplier_id' => $this->supplierId,
            'role'        => 'attachment',
            'sha256'      => $sha,
            'filename'    => $sha,
            'size_bytes'  => 1000,
            'doc_type'    => 'pdf',
        ]);
        $this->createdFiles[] = $fileId;

        // documents řádek vyloučen (mazán), ale document_files řádek stále drží bajt → není orphan.
        self::assertSame(1, $this->docs->countBySha($this->supplierId, $sha, [$docId]));
        // Vyloučíme i document_files řádek → součet 0 → orphan.
        self::assertSame(0, $this->docs->countBySha($this->supplierId, $sha, [$docId], [$fileId]));
        // Bez vyloučení: documents(1) + document_files(1) = 2.
        self::assertSame(2, $this->docs->countBySha($this->supplierId, $sha));
    }

    /**
     * MED-2 regrese: countBySha musí počítat i soft-deleted (v koši) řádky. Sdílený bajt
     * drží naživu i cizí doklad v koši — union NESMÍ filtrovat na deleted_at IS NULL, jinak
     * by se bajt odpojil, zatímco trashed doklad ho ještě potřebuje (restore → 404).
     */
    public function testCountByShaCountsSoftDeletedReferences(): void
    {
        $sha = str_repeat('c', 64);
        $doc = $this->insertDoc('SOFTDELREF', $sha);
        self::assertTrue($this->docs->softDelete($doc, $this->supplierId, null));

        // Řádek je v koši (deleted_at != NULL), ale stále referencuje sha → počítá se.
        self::assertGreaterThanOrEqual(1, $this->docs->countBySha($this->supplierId, $sha),
            'soft-deleted řádek stále drží bajt (union nefiltruje deleted_at)');
        // Vyloučíme jediný referencující řádek → 0 → teprve teď je bajt orphan.
        self::assertSame(0, $this->docs->countBySha($this->supplierId, $sha, [$doc]));
    }

    public function testListByEntityReturnsLinkedDocuments(): void
    {
        // Regrese: SELECT v listByEntity musí kvalifikovat sloupce — join s
        // document_links zavádí druhý created_at (jinak „ambiguous column" → 500).
        $id = $this->insertDoc('LINKEDDOC', str_repeat('6', 64));
        $this->pdo->prepare(
            'INSERT INTO document_links
                (supplier_id, document_id, entity_type, entity_id)
             VALUES (?, ?, "invoice", ?)'
        )->execute([$this->supplierId, $id, 987654]);

        $res = $this->docs->listByEntity($this->supplierId, 'invoice', 987654, $this->admin);
        self::assertContains($id, array_map(static fn($r) => $r['id'], $res));

        $this->pdo->prepare('DELETE FROM document_links WHERE document_id = ?')->execute([$id]);
    }

    /**
     * §4.2 per-user scope guard (bezpečnostní jádro): user-scoped doklad je neviditelný
     * jinému non-adminovi, viditelný vlastníkovi + adminovi; company doklad vidí všichni.
     */
    public function testScopeGuardUserVisibility(): void
    {
        $ownerId = (int) $this->pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        if ($ownerId <= 0) $this->markTestSkipped('No user for owner_user_id FK');
        $otherId = $ownerId + 999999; // non-owner (nemusí být reálný — jen porovnání v WHERE)

        $userDoc = $this->insertDoc('SCOPEUSERDOC', str_repeat('a', 64), null, 'user', $ownerId);
        $companyDoc = $this->insertDoc('SCOPECOMPANYDOC', str_repeat('b', 64), null, 'company', null);

        $ownerCtx = DocumentViewerContext::forUser($ownerId);
        $otherCtx = DocumentViewerContext::forUser($otherId);
        $noneCtx  = DocumentViewerContext::companyOnly();

        // find(): user doklad
        self::assertNotNull($this->docs->find($userDoc, $this->supplierId, $this->admin), 'admin vidí user doklad');
        self::assertNotNull($this->docs->find($userDoc, $this->supplierId, $ownerCtx), 'vlastník vidí svůj user doklad');
        self::assertNull($this->docs->find($userDoc, $this->supplierId, $otherCtx), 'cizí non-admin NEvidí user doklad');
        self::assertNull($this->docs->find($userDoc, $this->supplierId, $noneCtx), 'fail-closed (bez identity) NEvidí user doklad');

        // find(): company doklad — vidí všichni
        self::assertNotNull($this->docs->find($companyDoc, $this->supplierId, $otherCtx), 'cizí vidí company doklad');
        self::assertNotNull($this->docs->find($companyDoc, $this->supplierId, $noneCtx), 'company doklad i bez identity');

        // enumerace (listInFolder root)
        $idsFor = fn(DocumentViewerContext $v): array => array_map(
            static fn($r) => $r['id'],
            $this->docs->listInFolder($this->supplierId, null, $v)
        );
        self::assertContains($userDoc, $idsFor($this->admin));
        self::assertContains($userDoc, $idsFor($ownerCtx));
        self::assertNotContains($userDoc, $idsFor($otherCtx), 'cizí non-admin nevidí user doklad ve výpisu');
        self::assertContains($companyDoc, $idsFor($otherCtx), 'company doklad ve výpisu vidí všichni');

        // cross-tenant: jiný supplier nevidí nic ani jako admin
        self::assertNull($this->docs->find($userDoc, $this->supplierId + 99999, $this->admin));
    }

    public function testFolderTreeAndCascadeSoftDelete(): void
    {
        $parent = $this->folders->create($this->supplierId, null, 'ITESTPARENT', null);
        $this->createdFolders[] = $parent;
        $child = $this->folders->create($this->supplierId, $parent, 'ITESTCHILD', null);
        $this->createdFolders[] = $child;

        $docId = $this->insertDoc('INFOLDER', str_repeat('5', 64), $child);

        $descendants = $this->folders->descendantIds($parent, $this->supplierId);
        self::assertContains($child, $descendants);

        // Soft-delete podstromu → dokument uvnitř spadne do koše.
        $this->folders->softDeleteSubtree($parent, $this->supplierId, null, $this->admin);
        self::assertNull($this->docs->find($docId, $this->supplierId, $this->admin));
        self::assertNotNull($this->docs->find($docId, $this->supplierId, $this->admin, true));
    }
}
