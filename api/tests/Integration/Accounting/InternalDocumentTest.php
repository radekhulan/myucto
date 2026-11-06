<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\InternalDocumentAction;
use MyInvoice\Action\Accounting\JournalAction;
use MyInvoice\Action\Document\DocumentsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\DocumentLinkRepository;
use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\InternalDocumentException;
use MyInvoice\Service\Accounting\InternalDocumentService;
use MyInvoice\Service\Accounting\ManualJournalEntryService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Interní doklady (migrace 2193): číslování z řady ID, zaúčtování ručním zápisem
 * deníku s vazbou v hlavičce, storno protizápisem, zamčené a uzavřené období,
 * izolace firem, práva akcí a přílohy přes Dokumenty.
 */
#[Group('integration')]
final class InternalDocumentTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const YEAR = 2099;

    private ContainerInterface $container;
    private Connection $db;
    private PDO $pdo;
    private InternalDocumentService $service;
    private int $supplierId;
    private int $otherSupplierId;
    private int $periodId;
    private int $userId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('Test vyžaduje místní databázi.');
        }
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $this->pdo = $this->db->pdo();
        $this->service = $this->container->get(InternalDocumentService::class);
        $this->userId = (int) ($this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->pdo->beginTransaction();
        $source = (int) $this->pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        self::assertGreaterThan(0, $source);
        $this->supplierId = $this->doubleEntrySupplier($source);
        $this->otherSupplierId = $this->doubleEntrySupplier($source);
        $this->periodId = $this->container->get(AccountingPeriodRepository::class)
            ->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
        if (isset($this->db)) $this->db->close();
    }

    public function testDraftPostsBalancedManualEntryWithLinkAndSharedIdSeries(): void
    {
        $draft = $this->service->create($this->supplierId, $this->input(), $this->userId ?: null);
        self::assertSame('draft', $draft['status']);
        self::assertNull($draft['document_no']);
        self::assertNull($draft['journal_entry_id']);
        self::assertSame(1500.0, $draft['amount']);
        self::assertCount(2, $draft['lines']);

        $posted = $this->service->post($this->supplierId, (int) $draft['id'], $this->audit());
        self::assertSame('posted', $posted['status']);
        self::assertSame('ID-' . self::YEAR . '-0001', $posted['document_no']);
        $entryId = (int) $posted['journal_entry_id'];
        self::assertGreaterThan(0, $entryId);

        $entry = $this->entry($entryId);
        self::assertSame('manual', $entry['source_type']);
        self::assertNull($entry['source_id']);
        self::assertSame('ID-' . self::YEAR . '-0001', $entry['document_no']);
        self::assertSame('Zápočet pohledávky a závazku', $entry['description']);
        self::assertSame(self::YEAR . '-03-31', $entry['entry_date']);
        self::assertSame(self::YEAR . '-03-30', $entry['document_date']);
        self::assertNotNull($entry['posted_at']);
        self::assertSame(['321:debit:1500.00', '311:credit:1500.00'], $this->lines($entryId));

        // Ruční zápis s automatickým číslováním bere číslo ze stejné řady ID.
        $this->pdo->prepare(
            'INSERT INTO accounting_supplier_settings (supplier_id, manual_doc_series) VALUES (?, 1)
             ON DUPLICATE KEY UPDATE manual_doc_series = 1'
        )->execute([$this->supplierId]);
        $manualEntryId = $this->container->get(ManualJournalEntryService::class)->post($this->supplierId, [
            ['account_code' => '518', 'side' => 'debit', 'amount' => 100.0],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 100.0],
        ], ['entry_date' => self::YEAR . '-04-01', 'description' => 'Ruční zápis', 'document_no' => null]);
        self::assertSame('ID-' . self::YEAR . '-0002', $this->entry($manualEntryId)['document_no']);

        $second = $this->service->post($this->supplierId,
            (int) $this->service->create($this->supplierId, $this->input(), null)['id'], $this->audit());
        self::assertSame('ID-' . self::YEAR . '-0003', $second['document_no']);

        try {
            $this->service->post($this->supplierId, (int) $draft['id'], $this->audit());
            self::fail('Druhé zaúčtování musí být odmítnuto.');
        } catch (InternalDocumentException $e) {
            self::assertSame('not_draft', $e->errorCode);
        }
    }

    public function testDocumentLinksTravelToJournalEntry(): void
    {
        $bankId = $this->bankTransaction($this->supplierId);
        $draft = $this->service->create($this->supplierId, $this->input([
            'document_links' => [['doc_type' => 'bank', 'doc_id' => $bankId, 'note' => 'Úhrada zápočtem']],
        ]), null);
        self::assertSame([['doc_type' => 'bank', 'doc_id' => $bankId, 'note' => 'Úhrada zápočtem']], $draft['document_links']);
        $posted = $this->service->post($this->supplierId, (int) $draft['id'], $this->audit());
        $stmt = $this->pdo->prepare('SELECT doc_type, doc_id, note FROM journal_entry_document_links WHERE supplier_id = ? AND entry_id = ?');
        $stmt->execute([$this->supplierId, (int) $posted['journal_entry_id']]);
        self::assertSame([['doc_type' => 'bank', 'doc_id' => $bankId, 'note' => 'Úhrada zápočtem']],
            array_map(static fn (array $r): array => ['doc_type' => $r['doc_type'], 'doc_id' => (int) $r['doc_id'], 'note' => $r['note']],
                $stmt->fetchAll(PDO::FETCH_ASSOC)));

        // Doklad cizí firmy se navázat nedá.
        $foreignBank = $this->bankTransaction($this->otherSupplierId);
        try {
            $this->service->create($this->supplierId, $this->input([
                'document_links' => [['doc_type' => 'bank', 'doc_id' => $foreignBank]],
            ]), null);
            self::fail('Cizí doklad musí být odmítnut.');
        } catch (InternalDocumentException $e) {
            self::assertSame('not_found', $e->errorCode);
        }
    }

    public function testUnbalancedDraftCanBeSavedButNotPosted(): void
    {
        $draft = $this->service->create($this->supplierId, $this->input(['lines' => [
            ['account_code' => '321', 'side' => 'debit', 'amount' => 1500],
            ['account_code' => '311', 'side' => 'credit', 'amount' => 1400],
        ]]), null);
        try {
            $this->service->post($this->supplierId, (int) $draft['id'], $this->audit());
            self::fail('Nevyrovnaný doklad nesmí jít zaúčtovat.');
        } catch (InternalDocumentException $e) {
            self::assertSame('unbalanced', $e->errorCode);
        }
        self::assertSame('draft', $this->service->get($this->supplierId, (int) $draft['id'])['status']);
        self::assertSame(0, $this->manualEntryCount());
    }

    public function testUnknownAccountIsRejectedByPostingAndDocumentStaysDraft(): void
    {
        $draft = $this->service->create($this->supplierId, $this->input(['lines' => [
            ['account_code' => '999999', 'side' => 'debit', 'amount' => 1500],
            ['account_code' => '311', 'side' => 'credit', 'amount' => 1500],
        ]]), null);
        try {
            $this->service->post($this->supplierId, (int) $draft['id'], $this->audit());
            self::fail('Neexistující účet musí odmítnout účtová osnova.');
        } catch (PostingException) {
        }
        $doc = $this->service->get($this->supplierId, (int) $draft['id']);
        self::assertSame('draft', $doc['status']);
        self::assertNull($doc['document_no']);
        self::assertSame(0, $this->manualEntryCount());
    }

    public function testReverseCreatesMirrorEntryAndBlocksJournalShortcuts(): void
    {
        $posted = $this->service->post($this->supplierId,
            (int) $this->service->create($this->supplierId, $this->input(), null)['id'], $this->audit());
        $entryId = (int) $posted['journal_entry_id'];

        $journal = $this->container->get(JournalAction::class);
        $blocked = $journal->reverse($this->request('POST', ['accounting' => 2, 'accounting.journal.write' => 2]),
            new Response(), ['id' => (string) $entryId]);
        self::assertSame(409, $blocked->getStatusCode());
        self::assertSame('internal_document_use_detail', $this->error($blocked));

        try {
            $this->service->reverse($this->supplierId, (int) $posted['id'], 'x', null, $this->audit());
            self::fail('Storno bez důvodu musí být odmítnuto.');
        } catch (InternalDocumentException $e) {
            self::assertSame('reason_required', $e->errorCode);
        }

        $reversed = $this->service->reverse($this->supplierId, (int) $posted['id'], 'Chybný zápočet', self::YEAR . '-04-15', $this->audit());
        self::assertSame('reversed', $reversed['status']);
        self::assertSame('Chybný zápočet', $reversed['reversal_reason']);
        $reversalId = (int) $reversed['reversal_entry_id'];
        self::assertSame($reversalId, (int) $this->entry($entryId)['reversed_by']);
        self::assertSame(self::YEAR . '-04-15', $this->entry($reversalId)['entry_date']);
        self::assertSame(['321:credit:1500.00', '311:debit:1500.00'], $this->lines($reversalId));

        $pair = $journal->deleteReversalPair($this->request('DELETE', ['accounting' => 2, 'accounting.journal.write' => 2]),
            new Response(), ['id' => (string) $entryId]);
        self::assertSame(409, $pair->getStatusCode());

        // Odkaz z deníku na doklad u zaúčtování i storna.
        $map = $this->service->forJournalEntries($this->supplierId, [$entryId, $reversalId]);
        self::assertSame('posting', $map[$entryId]['role']);
        self::assertSame('reversal', $map[$reversalId]['role']);
        self::assertSame((int) $posted['id'], $map[$reversalId]['id']);
    }

    public function testDeletingPostedEntryInJournalReturnsDocumentToDraft(): void
    {
        $posted = $this->service->post($this->supplierId,
            (int) $this->service->create($this->supplierId, $this->input(), null)['id'], $this->audit());
        $journal = $this->container->get(JournalAction::class);
        $args = ['id' => (string) $posted['journal_entry_id']];

        $denied = $journal->delete($this->request('DELETE', ['accounting' => 2, 'accounting.journal.write' => 2]), new Response(), $args);
        self::assertSame(403, $denied->getStatusCode());
        self::assertSame('posted', $this->service->get($this->supplierId, (int) $posted['id'])['status']);

        $deleted = $journal->delete($this->request('DELETE', ['accounting' => 2, 'accounting.journal.write' => 2, 'accounting.journal.post' => 2]),
            new Response(), $args);
        self::assertSame(200, $deleted->getStatusCode());
        $draft = $this->service->get($this->supplierId, (int) $posted['id']);
        self::assertSame('draft', $draft['status']);
        self::assertNull($draft['journal_entry_id']);
        self::assertSame($posted['document_no'], $draft['document_no']);

        // Nové zaúčtování znovu použije původní číslo.
        $again = $this->service->post($this->supplierId, (int) $posted['id'], $this->audit());
        self::assertSame($posted['document_no'], $again['document_no']);
    }

    public function testLockedDateAndClosedPeriodRejectPosting(): void
    {
        $draft = $this->service->create($this->supplierId, $this->input(), null);
        $this->pdo->prepare(
            'INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)'
        )->execute([$this->supplierId, self::YEAR . '-06-30']);
        $service = Bootstrap::buildContainer()->get(InternalDocumentService::class);
        try {
            $service->post($this->supplierId, (int) $draft['id'], $this->audit());
            self::fail('Zamčené datum musí zaúčtování odmítnout.');
        } catch (PostingException $e) {
            self::assertSame('date_locked', $e->errorCode);
        }
        self::assertSame('draft', $service->get($this->supplierId, (int) $draft['id'])['status']);
        self::assertSame(0, $this->manualEntryCount());

        $this->pdo->prepare('UPDATE accounting_supplier_settings SET locked_until = NULL WHERE supplier_id = ?')
            ->execute([$this->supplierId]);
        $this->container->get(AccountingPeriodRepository::class)->setStatus($this->periodId, $this->supplierId, 'closed');
        $service = Bootstrap::buildContainer()->get(InternalDocumentService::class);
        try {
            $service->post($this->supplierId, (int) $draft['id'], $this->audit());
            self::fail('Uzavřené období musí zaúčtování odmítnout.');
        } catch (PostingException $e) {
            self::assertSame('period_not_open', $e->errorCode);
        }
        self::assertSame(0, $this->manualEntryCount());
    }

    public function testCompaniesAreIsolated(): void
    {
        $draft = $this->service->create($this->supplierId, $this->input(), null);
        foreach (['get', 'post', 'delete'] as $operation) {
            try {
                match ($operation) {
                    'get' => $this->service->get($this->otherSupplierId, (int) $draft['id']),
                    'post' => $this->service->post($this->otherSupplierId, (int) $draft['id'], $this->audit()),
                    'delete' => $this->service->deleteDraft($this->otherSupplierId, (int) $draft['id']),
                };
                self::fail('Cizí firma nesmí doklad vidět ani měnit: ' . $operation);
            } catch (InternalDocumentException $e) {
                self::assertSame(404, $e->httpStatus);
            }
        }
        self::assertSame(0, $this->service->list($this->otherSupplierId, [], 1, 50)['total']);
        self::assertSame(1, $this->service->list($this->supplierId, [], 1, 50)['total']);

        $foreignClient = $this->client($this->otherSupplierId);
        try {
            $this->service->create($this->supplierId, $this->input(['partner_id' => $foreignClient]), null);
            self::fail('Partner cizí firmy musí být odmítnut.');
        } catch (InternalDocumentException $e) {
            self::assertSame('invalid_partner', $e->errorCode);
        }
    }

    public function testListFiltersAndSorting(): void
    {
        $client = $this->client($this->supplierId);
        $a = $this->service->create($this->supplierId, $this->input(['title' => 'Kurzový rozdíl březen', 'kind' => 'fx_difference',
            'document_date' => self::YEAR . '-03-10', 'entry_date' => self::YEAR . '-03-10', 'partner_id' => $client]), null);
        $b = $this->service->create($this->supplierId, $this->input(['title' => 'Odpis pohledávky', 'kind' => 'write_off',
            'document_date' => self::YEAR . '-05-10', 'entry_date' => self::YEAR . '-05-10']), null);
        $this->service->post($this->supplierId, (int) $b['id'], $this->audit());

        $ids = fn (array $filters): array => array_map(static fn (array $r): int => (int) $r['id'],
            $this->service->list($this->supplierId, $filters, 1, 50)['items']);
        self::assertSame([(int) $b['id'], (int) $a['id']], $ids([]));
        self::assertSame([(int) $a['id'], (int) $b['id']], $ids(['sort_by' => 'document_date', 'sort_dir' => 'asc']));
        self::assertSame([(int) $b['id']], $ids(['status' => 'posted']));
        self::assertSame([(int) $a['id']], $ids(['status' => 'draft']));
        self::assertSame([(int) $a['id']], $ids(['to' => self::YEAR . '-04-01']));
        self::assertSame([(int) $a['id']], $ids(['partner_id' => $client]));
        self::assertSame([(int) $b['id']], $ids(['q' => 'Odpis']));
        self::assertSame([(int) $b['id']], $ids(['q' => 'ID-' . self::YEAR]));
        self::assertSame([(int) $a['id']], $ids(['kind' => 'fx_difference']));
        $page = $this->service->list($this->supplierId, [], 2, 1);
        self::assertSame(2, $page['total']);
        self::assertSame([(int) $a['id']], array_map(static fn (array $r): int => (int) $r['id'], $page['items']));
        self::assertSame(1500.0, $page['totals']['posted_amount']);
        self::assertCount(2, iterator_to_array($this->service->exportRows($this->supplierId, []), false));

        try {
            $this->service->list($this->supplierId, ['from' => self::YEAR . '-02-31'], 1, 50);
            self::fail('Neexistující datum musí být validační chyba.');
        } catch (InternalDocumentException $e) {
            self::assertSame(422, $e->httpStatus);
        }
    }

    public function testActionPermissionsMirrorManualJournalEntry(): void
    {
        $action = $this->container->get(InternalDocumentAction::class);
        $read = ['accounting' => 1];
        $write = ['accounting' => 2, 'accounting.journal.write' => 2];
        $post = $write + ['accounting.journal.post' => 2];

        self::assertSame(200, $action->list($this->request('GET', $read), new Response())->getStatusCode());
        self::assertSame(403, $action->list($this->request('GET', []), new Response())->getStatusCode());
        self::assertSame(403, $action->create($this->request('POST', $read)->withParsedBody($this->input()), new Response())->getStatusCode());

        $created = $action->create($this->request('POST', $write)->withParsedBody($this->input()), new Response());
        self::assertSame(201, $created->getStatusCode());
        $id = (int) $this->data($created)['id'];

        self::assertSame(403, $action->post($this->request('POST', $write), new Response(), ['id' => (string) $id])->getStatusCode());
        $posted = $action->post($this->request('POST', $post), new Response(), ['id' => (string) $id]);
        self::assertSame(200, $posted->getStatusCode());
        self::assertSame('posted', $this->data($posted)['status']);

        $put = $action->update($this->request('PUT', $write)->withParsedBody(['title' => 'Změna']), new Response(), ['id' => (string) $id]);
        self::assertSame(409, $put->getStatusCode());
        self::assertSame('internal_documents.error.not_draft', $this->error($put));
        self::assertSame(403, $action->reverse($this->request('POST', $write)->withParsedBody(['reason' => 'Oprava']),
            new Response(), ['id' => (string) $id])->getStatusCode());

        $log = $this->pdo->prepare("SELECT action FROM activity_log WHERE entity_type = 'internal_document' AND entity_id = ? ORDER BY id");
        $log->execute([$id]);
        self::assertSame(['internal_document.created', 'internal_document.posted'], $log->fetchAll(PDO::FETCH_COLUMN));

        // Daňová evidence agendu nemá.
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = ?")->execute([$this->supplierId]);
        self::assertSame(403, $action->list($this->request('GET', $read), new Response())->getStatusCode());
    }

    public function testCreateFromExistingManualEntryNumbersItOnce(): void
    {
        $entryId = $this->container->get(ManualJournalEntryService::class)->post($this->supplierId, [
            ['account_code' => '546', 'side' => 'debit', 'amount' => 800.0],
            ['account_code' => '311', 'side' => 'credit', 'amount' => 800.0],
        ], ['entry_date' => self::YEAR . '-02-01', 'description' => 'Odpis nedobytné pohledávky', 'document_no' => null, 'posted' => true]);
        self::assertNull($this->entry($entryId)['document_no']);

        $result = $this->service->createFromEntry($this->supplierId, $entryId, ['kind' => 'write_off'], $this->audit());
        $doc = $result['document'];
        self::assertTrue($result['numbered']);
        self::assertSame('posted', $doc['status']);
        self::assertSame('ID-' . self::YEAR . '-0001', $doc['document_no']);
        self::assertSame('Odpis nedobytné pohledávky', $doc['title']);
        self::assertSame(800.0, $doc['amount']);
        self::assertSame($entryId, $doc['journal_entry_id']);
        self::assertSame('ID-' . self::YEAR . '-0001', $this->entry($entryId)['document_no']);
        self::assertSame(['546:debit:800.00', '311:credit:800.00'],
            array_map(static fn (array $l): string => $l['account_code'] . ':' . $l['side'] . ':' . number_format($l['amount'], 2, '.', ''), $doc['lines']));

        try {
            $this->service->createFromEntry($this->supplierId, $entryId, [], $this->audit());
            self::fail('Druhá hlavička nad týmž zápisem musí být odmítnuta.');
        } catch (InternalDocumentException $e) {
            self::assertSame('entry_has_document', $e->errorCode);
        }
        try {
            $this->service->createFromEntry($this->otherSupplierId, $entryId, [], $this->audit());
            self::fail('Zápis cizí firmy nesmí být dostupný.');
        } catch (InternalDocumentException $e) {
            self::assertSame(404, $e->httpStatus);
        }
    }

    public function testAttachmentsGoThroughDocumentLinks(): void
    {
        if ($this->userId === 0) $this->markTestSkipped('Chybí uživatel.');
        $draft = $this->service->create($this->supplierId, $this->input(), null);
        $foreign = $this->container->get(InternalDocumentService::class)->create($this->otherSupplierId, $this->input(), null);
        $documentId = $this->container->get(DocumentRepository::class)->insert([
            'supplier_id' => $this->supplierId, 'folder_id' => null, 'title' => 'Syntetická dohoda o zápočtu',
            'description' => null, 'original_name' => 'dohoda.pdf', 'filename' => str_repeat('f', 64),
            'sha256' => str_repeat('f', 64), 'mime_type' => 'application/pdf', 'size_bytes' => 100,
            'doc_type' => 'pdf', 'uploaded_by' => $this->userId, 'scope' => 'company', 'owner_user_id' => null,
        ]);
        $links = $this->container->get(DocumentLinkRepository::class);
        self::assertTrue($links->entityBelongsToSupplier('internal_document', (int) $draft['id'], $this->supplierId));
        self::assertFalse($links->entityBelongsToSupplier('internal_document', (int) $foreign['id'], $this->supplierId));

        $documents = $this->container->get(DocumentsAction::class);
        $readOnly = ['documents' => 2, 'accounting' => 1];
        $writer = ['documents' => 2, 'accounting' => 2, 'accounting.journal.write' => 2];
        self::assertSame(403, $documents->addLink($this->request('POST', $readOnly)->withParsedBody(
            ['entity_type' => 'internal_document', 'entity_id' => (int) $draft['id']]), new Response(), ['id' => $documentId])->getStatusCode());
        self::assertSame(404, $documents->addLink($this->request('POST', $writer)->withParsedBody(
            ['entity_type' => 'internal_document', 'entity_id' => (int) $foreign['id']]), new Response(), ['id' => $documentId])->getStatusCode());
        $added = $documents->addLink($this->request('POST', $writer)->withParsedBody(
            ['entity_type' => 'internal_document', 'entity_id' => (int) $draft['id']]), new Response(), ['id' => $documentId]);
        self::assertSame(200, $added->getStatusCode());
        self::assertStringContainsString('Zápočet pohledávky a závazku', $this->data($added)['links'][0]['label']);
        self::assertSame(403, $documents->byEntity($this->request('GET', ['documents' => 1]), new Response(),
            ['type' => 'internal_document', 'id' => (string) $draft['id']])->getStatusCode());
        self::assertCount(1, $this->data($documents->byEntity($this->request('GET', $readOnly), new Response(),
            ['type' => 'internal_document', 'id' => (string) $draft['id']]))['documents']);
        self::assertSame(1, $this->service->list($this->supplierId, [], 1, 50)['items'][0]['document_count']);

        try {
            $this->pdo->prepare('INSERT INTO document_links (document_id, supplier_id, entity_type, entity_id) VALUES (?, ?, ?, ?)')
                ->execute([$documentId, $this->supplierId, 'internal_document', (int) $foreign['id']]);
            self::fail('Trigger musí odmítnout doklad cizí firmy.');
        } catch (\PDOException $e) {
            self::assertSame('45000', $e->errorInfo[0] ?? null);
        }

        $this->service->deleteDraft($this->supplierId, (int) $draft['id']);
        $left = $this->pdo->prepare("SELECT COUNT(*) FROM document_links WHERE supplier_id = ? AND entity_type = 'internal_document'");
        $left->execute([$this->supplierId]);
        self::assertSame(0, (int) $left->fetchColumn());
    }

    public function testFromEntryRejectsEntryOwnedByDocumentPosting(): void
    {
        $entryId = $this->manualEntry(['document_no' => 'SYN-1']);
        $invoiceId = (int) $this->pdo->query('SELECT MAX(id) FROM invoices')->fetchColumn() + 1000000;
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $this->pdo->prepare("INSERT INTO journal_document_postings (supplier_id, doc_type, doc_id, entry_id) VALUES (?, 'invoice', ?, ?)")
            ->execute([$this->supplierId, $invoiceId, $entryId]);
        try {
            $this->service->createFromEntry($this->supplierId, $entryId, [], $this->audit());
            self::fail('Zápis převzatý dokladem nesmí dostat interní doklad.');
        } catch (InternalDocumentException $e) {
            self::assertSame('entry_owned_by_document', $e->errorCode);
        }
        self::assertSame(0, $this->service->list($this->supplierId, [], 1, 50)['total']);
    }

    public function testDatabaseRefusesDeletingEntryBehindTheHeader(): void
    {
        $posted = $this->service->post($this->supplierId,
            (int) $this->service->create($this->supplierId, $this->input(), null)['id'], $this->audit());
        try {
            $this->pdo->prepare('DELETE FROM journal_entries WHERE id = ? AND supplier_id = ?')
                ->execute([(int) $posted['journal_entry_id'], $this->supplierId]);
            self::fail('Smazání zápisu mimo deník musí cizí klíč odmítnout.');
        } catch (\PDOException $e) {
            self::assertSame('23000', $e->errorInfo[0] ?? null);
        }
        self::assertSame('posted', $this->service->get($this->supplierId, (int) $posted['id'])['status']);
    }

    public function testJournalDeleteRefusesEntryUnderHeaderCreatedFromEntry(): void
    {
        $entryId = $this->manualEntry(['document_no' => 'SYN-2']);
        $doc = $this->service->createFromEntry($this->supplierId, $entryId, [], $this->audit())['document'];
        self::assertSame('journal', $doc['origin']);
        $response = $this->container->get(JournalAction::class)->delete(
            $this->request('DELETE', ['accounting' => 2, 'accounting.journal.write' => 2, 'accounting.journal.post' => 2]),
            new Response(), ['id' => (string) $entryId]);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('internal_document_use_detail', $this->error($response));
        self::assertSame('posted', $this->service->get($this->supplierId, (int) $doc['id'])['status']);
        self::assertSame(1, $this->manualEntryCount());
    }

    public function testAssignedEntryNumberIsAuditedAndRespectsClosedPeriod(): void
    {
        $entryId = $this->manualEntry();
        $this->service->createFromEntry($this->supplierId, $entryId, [], $this->audit());
        $log = $this->pdo->prepare("SELECT payload FROM activity_log
            WHERE entity_type = 'journal_entry' AND entity_id = ? AND action = 'accounting.document_no_assigned'");
        $log->execute([$entryId]);
        $payload = json_decode((string) $log->fetchColumn(), true);
        self::assertSame('ID-' . self::YEAR . '-0001', $payload['after'] ?? null);

        $second = $this->manualEntry();
        $this->container->get(AccountingPeriodRepository::class)->setStatus($this->periodId, $this->supplierId, 'closed');
        try {
            $this->service->createFromEntry($this->supplierId, $second, [], $this->audit());
            self::fail('Zápisu v uzavřeném období číslo přidělit nelze.');
        } catch (PostingException $e) {
            self::assertSame('period_not_open', $e->errorCode);
        }
        self::assertNull($this->entry($second)['document_no']);
    }

    public function testSeriesCollisionIsSkippedAndTakenEntryNumberIsConflict(): void
    {
        // Doklad převzal číslo, které řada ID vydá jako první.
        $this->service->createFromEntry($this->supplierId,
            $this->manualEntry(['document_no' => 'ID-' . self::YEAR . '-0001']), [], $this->audit());
        $posted = $this->service->post($this->supplierId,
            (int) $this->service->create($this->supplierId, $this->input(), null)['id'], $this->audit());
        self::assertSame('ID-' . self::YEAR . '-0002', $posted['document_no']);

        try {
            $this->service->createFromEntry($this->supplierId,
                $this->manualEntry(['document_no' => 'ID-' . self::YEAR . '-0002']), [], $this->audit());
            self::fail('Číslo, které nese jiný interní doklad, musí skončit konfliktem.');
        } catch (InternalDocumentException $e) {
            self::assertSame('document_no_taken', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
        }
    }

    public function testJournalReversalCheckRunsUnderLockInsideTransaction(): void
    {
        $posted = $this->service->post($this->supplierId,
            (int) $this->service->create($this->supplierId, $this->input(), null)['id'], $this->audit());
        try {
            $this->service->assertJournalReversible($this->supplierId, (int) $posted['journal_entry_id']);
            self::fail('Zápis interního dokladu nesmí jít stornovat z deníku.');
        } catch (PostingException $e) {
            self::assertSame('internal_document_use_detail', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
        }
        $this->service->assertJournalReversible($this->supplierId, $this->manualEntry());
        self::assertTrue(true);
    }

    public function testNumberIsDroppedWhenDraftMovesToAnotherFiscalYear(): void
    {
        $posted = $this->service->post($this->supplierId,
            (int) $this->service->create($this->supplierId, $this->input(), null)['id'], $this->audit());
        $this->container->get(JournalAction::class)->delete(
            $this->request('DELETE', ['accounting' => 2, 'accounting.journal.write' => 2, 'accounting.journal.post' => 2]),
            new Response(), ['id' => (string) $posted['journal_entry_id']]);
        $sameYear = $this->service->update($this->supplierId, (int) $posted['id'], ['entry_date' => self::YEAR . '-05-01'], null);
        self::assertSame($posted['document_no'], $sameYear['document_no']);
        $nextYear = $this->service->update($this->supplierId, (int) $posted['id'],
            ['document_date' => (self::YEAR + 1) . '-01-05', 'entry_date' => (self::YEAR + 1) . '-01-05'], null);
        self::assertNull($nextYear['document_no']);
    }

    public function testPartialUpdateKeepsClosedDimensionAndParsesRedStornoFlag(): void
    {
        $this->pdo->prepare("UPDATE supplier SET dimensions_enabled = 1 WHERE id = ?")->execute([$this->supplierId]);
        $this->pdo->prepare("INSERT INTO dimension_types (supplier_id, code, name) VALUES (?, 'ZAK', 'Syntetická zakázka')")
            ->execute([$this->supplierId]);
        $typeId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO dimension_values (type_id, supplier_id, code, name) VALUES (?, ?, 'Z1', 'Syntetická hodnota')")
            ->execute([$typeId, $this->supplierId]);
        $valueId = (int) $this->pdo->lastInsertId();
        $draft = $this->service->create($this->supplierId, $this->input(['lines' => [
            ['account_code' => '321', 'side' => 'debit', 'amount' => 1500, 'is_red_storno' => 'false', 'dimensions' => [$typeId => $valueId]],
            ['account_code' => '311', 'side' => 'credit', 'amount' => 1500, 'is_red_storno' => '0'],
        ]]), null);
        self::assertFalse($draft['lines'][0]['is_red_storno']);
        self::assertSame(1500.0, $draft['amount']);

        $this->pdo->prepare('UPDATE dimension_values SET is_active = 0 WHERE id = ?')->execute([$valueId]);
        $updated = $this->service->update($this->supplierId, (int) $draft['id'], ['title' => 'Přejmenovaný koncept'], null);
        self::assertSame('Přejmenovaný koncept', $updated['title']);
        self::assertSame([$typeId => $valueId], array_map('intval', $updated['lines'][0]['dimensions']));

        try {
            $this->service->create($this->supplierId, $this->input(['lines' => [
                ['account_code' => '321', 'side' => 'debit', 'amount' => 1, 'is_red_storno' => 'nevím'],
            ]]), null);
            self::fail('Nečitelný příznak červeného storna musí být validační chyba.');
        } catch (InternalDocumentException $e) {
            self::assertSame('invalid_lines', $e->errorCode);
        }
    }

    private function manualEntry(array $meta = []): int
    {
        return $this->container->get(ManualJournalEntryService::class)->post($this->supplierId, [
            ['account_code' => '546', 'side' => 'debit', 'amount' => 800.0],
            ['account_code' => '311', 'side' => 'credit', 'amount' => 800.0],
        ], $meta + ['entry_date' => self::YEAR . '-02-01', 'description' => 'Syntetický ruční zápis', 'document_no' => null, 'posted' => true]);
    }

    private function doubleEntrySupplier(int $source): int
    {
        $id = $this->createIsolatedSupplier($this->pdo, $source);
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")->execute([$id]);
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($id);
        return $id;
    }

    private function input(array $override = []): array
    {
        return $override + [
            'kind' => 'offset',
            'title' => 'Zápočet pohledávky a závazku',
            'document_date' => self::YEAR . '-03-30',
            'entry_date' => self::YEAR . '-03-31',
            'note' => 'Syntetický zápočet',
            'lines' => [
                ['account_code' => '321', 'side' => 'debit', 'amount' => 1500],
                ['account_code' => '311', 'side' => 'credit', 'amount' => 1500],
            ],
        ];
    }

    private function audit(): array
    {
        $uid = $this->userId > 0 ? $this->userId : null;
        return ['user_id' => $uid, 'posted_by' => $uid, 'ip' => '127.0.0.1', 'user_agent' => 'phpunit'];
    }

    private function entry(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM journal_entries WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $this->supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        return $row;
    }

    /** @return list<string> */
    private function lines(int $entryId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT CONCAT(a.account_code, ':', l.side, ':', l.amount)
               FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.entry_id = ? AND l.supplier_id = ? ORDER BY l.line_no, l.id"
        );
        $stmt->execute([$entryId, $this->supplierId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function manualEntryCount(): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ? AND source_type = 'manual'");
        $stmt->execute([$this->supplierId]);
        return (int) $stmt->fetchColumn();
    }

    private function bankTransaction(int $supplierId): int
    {
        $this->pdo->prepare(
            'INSERT INTO bank_statements (supplier_id, file_name, file_hash, account_number, statement_date, currency)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$supplierId, 'synteticky-vypis', hash('sha256', uniqid('', true)), '1000000005/0100', self::YEAR . '-03-31', 'CZK']);
        $statementId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO bank_transactions (statement_id, posted_at, amount, currency, description)
             VALUES (?, ?, 1500, 'CZK', 'Syntetická platba')"
        )->execute([$statementId, self::YEAR . '-03-31']);
        return (int) $this->pdo->lastInsertId();
    }

    private function client(int $supplierId): int
    {
        $this->pdo->prepare(
            "INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
             VALUES (?, 'Syntetický partner', 'Ulice 1', 'Praha', '11000',
                     (SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1), 'partner@example.invalid',
                     (SELECT id FROM currencies WHERE code = 'CZK' LIMIT 1))"
        )->execute([$supplierId]);
        return (int) $this->pdo->lastInsertId();
    }

    private function request(string $method, array $permissions)
    {
        return (new ServerRequestFactory())->createServerRequest($method, '/api/accounting/internal-documents')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withAttribute('auth.effective_role', new EffectiveRole(0, 'Test', 'staff', true, $permissions, 'custom'));
    }

    private function data(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        return $body['data'] ?? $body;
    }

    private function error(ResponseInterface $response): ?string
    {
        $response->getBody()->rewind();
        $body = json_decode((string) $response->getBody(), true);
        return $body['error']['code'] ?? null;
    }
}
