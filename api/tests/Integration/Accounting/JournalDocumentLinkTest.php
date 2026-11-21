<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\JournalAction;
use MyInvoice\Action\Accounting\JournalDocumentLinkAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\JournalEntryDocumentLinkRepository;
use MyInvoice\Service\Accounting\JournalLinkService;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Měkká vazba ručního zápisu na existující doklad (migrace 1514).
 *
 * TĚŽIŠTĚ TESTU:
 *  1. IDEMPOTENCE DENÍKU ZŮSTÁVÁ NEDOTČENÁ — ruční zápis s vazbou má pořád
 *     source_type='manual' a source_id NULL. Kdyby vazba sáhla na tu dvojici,
 *     spadne na UNIQUE z migrace 1007 proti zaúčtování dokladu, nebo se zápis
 *     začne tvářit jako zaúčtování faktury (dvojí zaúčtování v Σ).
 *  2. SYMETRIE — z ručního zápisu se musí dát na doklad a z dokladu zpět na zápis.
 *     Vazba jedním směrem je horší než žádná: účetní se z ní nedostane zpátky.
 *  3. SHODA ODZNAKU S PANELEM — hasRelatedMap() a related() jsou dvě různé SQL
 *     cesty; když se rozejdou, seznam deníku lže.
 *  4. BEZPEČNOST — doc_id nemá FK, takže tenanta hlídá jen aplikace (IDOR).
 *
 * DB běží v transakci (rollback v tearDown).
 */
#[Group('integration')]
final class JournalDocumentLinkTest extends BankPostingTestCase
{
    private JournalLinkService $links;
    private JournalEntryDocumentLinkRepository $repo;
    private JournalDocumentLinkAction $action;
    private JournalAction $journalAction;

    protected function setUp(): void
    {
        parent::setUp();
        $this->links         = $this->container->get(JournalLinkService::class);
        $this->repo          = $this->container->get(JournalEntryDocumentLinkRepository::class);
        $this->action        = $this->container->get(JournalDocumentLinkAction::class);
        $this->journalAction = $this->container->get(JournalAction::class);
    }

    public function testLinkedManualEntryAndDocumentSeeEachOther(): void
    {
        $clientId  = $this->client('Odběratel DL');
        $invoiceId = $this->saleInvoice('DL0001', $clientId, 1000.0);
        $invoiceEntry = $this->postPredpis('invoice', $invoiceId, '311', '604', 1000.0);
        $manualEntry  = $this->manualEntry();

        $res = $this->invoke('create', 'POST', ['id' => (string) $manualEntry], [
            'doc_type' => 'invoice',
            'doc_id'   => $invoiceId,
            'note'     => 'Dohad k této faktuře.',
        ]);
        self::assertSame(201, $res['status']);
        self::assertSame('invoice', $res['body']['link']['doc_type']);
        self::assertSame($invoiceId, $res['body']['link']['doc_id']);
        self::assertSame('Dohad k této faktuře.', $res['body']['link']['note']);

        // Zdroj zápisu se navázáním NESMÍ změnit — jinak by šlo o zaúčtování faktury.
        $stored = $this->journal->find($manualEntry, $this->supplierId);
        self::assertSame('manual', $stored['source_type']);
        self::assertNull($stored['source_id']);

        // Uložení, výpis i detail zápisu musí vazbu popsat STEJNĚ. Když detail vracel
        // holý řádek bez `document`, tvářila se navázaná faktura v UI jako smazaná.
        foreach ([
            'create' => $res['body']['items'][0],
            'list'   => $this->invoke('list', 'GET', ['id' => (string) $manualEntry])['body']['items'][0],
            'detail' => $this->invokeJournal('get', 'GET', ['id' => (string) $manualEntry])['body']['links'][0],
        ] as $where => $item) {
            self::assertSame($invoiceId, $item['doc_id'], "{$where}: chybí doc_id.");
            self::assertIsArray($item['document'] ?? null, "{$where}: vazba přišla bez popisu dokladu.");
            self::assertSame('DL0001', $item['document']['title'], "{$where}: jiný popis dokladu.");
            self::assertSame($invoiceEntry, $item['document']['entry_id'], "{$where}: chybí zaúčtování dokladu.");
        }

        $fromManual = $this->related($manualEntry);
        self::assertCount(1, $fromManual['items']);
        self::assertSame('linked_document', $fromManual['items'][0]['relation']);
        self::assertSame('invoice', $fromManual['items'][0]['source_type']);
        self::assertSame($invoiceId, $fromManual['items'][0]['source_id']);
        self::assertSame($invoiceEntry, $fromManual['items'][0]['entry_id'], 'Vazba musí nést i zaúčtování dokladu.');
        self::assertSame('invoice-detail', $fromManual['items'][0]['route']['name']);

        $fromInvoice = $this->related($invoiceEntry);
        self::assertCount(1, $fromInvoice['items'], 'Zpětná hrana chybí — z faktury se na doúčtování nedostaneš.');
        self::assertSame('linked_entry', $fromInvoice['items'][0]['relation']);
        self::assertSame('journal_entry', $fromInvoice['items'][0]['source_type']);
        self::assertSame($manualEntry, $fromInvoice['items'][0]['entry_id']);
        self::assertTrue($fromInvoice['items'][0]['entry_posted']);
    }

    public function testDerivedAndManualEdgesCoexist(): void
    {
        // Faktura má úhradu (odvozená hrana) A ruční doúčtování (měkká vazba).
        // Panel musí ukázat obojí, ne aby si jedna hrana přebila druhou.
        $clientId  = $this->client('Odběratel DL mix');
        $invoiceId = $this->saleInvoice('DL0002', $clientId, 800.0);
        $txId      = $this->transaction($this->statement(), 800.0);
        $this->invoicePayment($invoiceId, $txId, 800.0);

        $invoiceEntry = $this->postPredpis('invoice', $invoiceId, '311', '604', 800.0);
        $bankEntry    = $this->postPredpis('bank', $txId, '221', '311', 800.0);
        $manualEntry  = $this->manualEntry();
        $this->repo->add($manualEntry, $this->supplierId, 'invoice', $invoiceId, null, $this->userId);

        $items = $this->related($invoiceEntry)['items'];
        self::assertCount(2, $items);
        $byRelation = [];
        foreach ($items as $it) $byRelation[$it['relation']] = $it;
        self::assertArrayHasKey('payment', $byRelation, 'Odvozená hrana (úhrada) se ztratila.');
        self::assertSame($bankEntry, $byRelation['payment']['entry_id']);
        self::assertArrayHasKey('linked_entry', $byRelation, 'Měkká vazba se ztratila.');
        self::assertSame($manualEntry, $byRelation['linked_entry']['entry_id']);
    }

    public function testBadgeAgreesWithPanel(): void
    {
        $clientId  = $this->client('Odběratel DL odznak');
        $invoiceId = $this->saleInvoice('DL0003', $clientId, 600.0);
        $invoiceEntry = $this->postPredpis('invoice', $invoiceId, '311', '604', 600.0);
        $linkedManual = $this->manualEntry();
        $this->repo->add($linkedManual, $this->supplierId, 'invoice', $invoiceId, null, $this->userId);
        $lonelyManual = $this->manualEntry();

        $page = [];
        foreach ([$invoiceEntry, $linkedManual, $lonelyManual] as $id) {
            $page[] = $this->journal->find($id, $this->supplierId);
        }
        $map = $this->links->hasRelatedMap($this->supplierId, $page, \MyInvoice\Service\Accounting\JournalRelatedAccess::all());

        foreach ($page as $entry) {
            $entryId = (int) $entry['id'];
            self::assertSame(
                $this->related($entryId)['items'] !== [],
                isset($map[$entryId]),
                "Odznak a panel se u zápisu #{$entryId} rozcházejí."
            );
        }
        self::assertArrayHasKey($linkedManual, $map);
        self::assertArrayHasKey($invoiceEntry, $map);
        self::assertArrayNotHasKey($lonelyManual, $map);
    }

    public function testEntryLinkedToItsOwnDocumentDoesNotRepeatItself(): void
    {
        // Převod z Money S3 váže každý zápis i na jeho VLASTNÍ zdrojový doklad
        // (banka BV… → bank_transactions téhož pohybu). Panel pak ukazoval tentýž
        // zápis ještě dvakrát: jako „Navázaný doklad" (vlastní pohyb) a jako
        // „Navázaný zápis" (zpětná hrana z pohybu na sebe) — účetní to čte jako
        // dvojí zaúčtování.
        $vendorId  = $this->client('Dodavatel DL zdroj');
        $advanceId = $this->purchaseInvoice('DL-ZF-0001', $vendorId, 500.0, 'advance');
        $txId      = $this->transaction($this->statement(), -500.0);
        $this->paymentMatch($txId, $advanceId, 500.0);
        $bankEntry = $this->postPredpis('bank', $txId, '321', '221', 500.0);
        $this->repo->add($bankEntry, $this->supplierId, 'bank', $txId, 'Převzato z Money S3', $this->userId);

        $items = $this->related($bankEntry)['items'];
        self::assertCount(1, $items, 'Zápis nesmí v panelu vidět sám sebe.');
        self::assertSame('purchase_invoice', $items[0]['source_type']);
        self::assertSame($advanceId, $items[0]['source_id']);
        self::assertSame('document', $items[0]['relation']);
        foreach ($items as $it) {
            self::assertNotSame($bankEntry, $it['entry_id'], 'Vlastní zápis se vrátil jako protějšek.');
        }

        // Evidence vazby zůstává — seznam „Vazba na doklad" ji dál ukáže a dá zrušit.
        self::assertCount(1, $this->links->documentLinks($this->supplierId, $bankEntry));
    }

    public function testEntryOfLinkedDocumentIsNotRepeatedAsLinkedEntry(): void
    {
        // Bankovní zápis hradí fakturu (odvozená hrana) A zároveň je na ni ručně
        // navázaný. Z faktury jde o JEDEN protějšek — úhradu i s jejím zápisem;
        // druhá položka „Navázaný zápis" s týmiž řádky je duplicita.
        $clientId  = $this->client('Odběratel DL dvojí hrana');
        $invoiceId = $this->saleInvoice('DL0010', $clientId, 400.0);
        $txId      = $this->transaction($this->statement(), 400.0);
        $this->invoicePayment($invoiceId, $txId, 400.0);
        $invoiceEntry = $this->postPredpis('invoice', $invoiceId, '311', '604', 400.0);
        $bankEntry    = $this->postPredpis('bank', $txId, '221', '311', 400.0);
        $this->repo->add($bankEntry, $this->supplierId, 'invoice', $invoiceId, null, $this->userId);
        // Skutečně jiný zápis navázaný na fakturu zůstat musí.
        $manualEntry = $this->manualEntry();
        $this->repo->add($manualEntry, $this->supplierId, 'invoice', $invoiceId, null, $this->userId);

        $items = $this->related($invoiceEntry)['items'];
        $entryIds = array_map(static fn (array $it): ?int => $it['entry_id'], $items);
        self::assertSame(1, count(array_keys($entryIds, $bankEntry, true)), 'Zápis úhrady se v panelu opakuje.');
        self::assertContains($manualEntry, $entryIds, 'Jiný navázaný zápis se ztratil.');
        self::assertCount(2, $items);
    }

    public function testLinkToOwnDocumentAloneGivesNoBadge(): void
    {
        $txId      = $this->transaction($this->statement(), 250.0);
        $bankEntry = $this->postPredpis('bank', $txId, '221', '604', 250.0);
        $this->repo->add($bankEntry, $this->supplierId, 'bank', $txId, 'Převzato z Money S3', $this->userId);

        self::assertSame([], $this->related($bankEntry)['items']);
        $map = $this->links->hasRelatedMap($this->supplierId, [$this->journal->find($bankEntry, $this->supplierId)], \MyInvoice\Service\Accounting\JournalRelatedAccess::all());
        self::assertArrayNotHasKey($bankEntry, $map, 'Odznak svítí, ale panel je prázdný.');
    }

    public function testForeignAndUnknownDocumentsAreRejected(): void
    {
        $manualEntry = $this->manualEntry();

        $unknown = $this->invoke('create', 'POST', ['id' => (string) $manualEntry], [
            'doc_type' => 'invoice',
            'doc_id'   => 999999999,
        ]);
        self::assertSame(404, $unknown['status']);
        self::assertSame('not_found', $unknown['body']['error']['code']);

        $badType = $this->invoke('create', 'POST', ['id' => (string) $manualEntry], [
            'doc_type' => 'journal_entry',
            'doc_id'   => 1,
        ]);
        self::assertSame(422, $badType['status']);

        // Cizí tenant: doklad existuje, ale ne pro tohohle dodavatele.
        $invoiceId = $this->saleInvoice('DL0004', $this->client('Odběratel DL cizí'), 100.0);
        self::assertTrue($this->repo->documentExists($this->supplierId, 'invoice', $invoiceId));
        self::assertFalse(
            $this->repo->documentExists($this->supplierId + 99999, 'invoice', $invoiceId),
            'Doklad cizího tenanta nesmí projít validací (doc_id nemá FK).'
        );
    }

    public function testDuplicateLinkIsIdempotent(): void
    {
        $invoiceId   = $this->saleInvoice('DL0005', $this->client('Odběratel DL dup'), 300.0);
        $manualEntry = $this->manualEntry();

        $first  = $this->invoke('create', 'POST', ['id' => (string) $manualEntry], ['doc_type' => 'invoice', 'doc_id' => $invoiceId]);
        $second = $this->invoke('create', 'POST', ['id' => (string) $manualEntry], ['doc_type' => 'invoice', 'doc_id' => $invoiceId]);

        self::assertSame(201, $second['status'], 'Dvojklik nesmí skončit chybou.');
        self::assertSame($first['body']['link']['id'], $second['body']['link']['id']);
        self::assertCount(1, $second['body']['items']);
        self::assertSame(1, $this->repo->countForEntry($manualEntry, $this->supplierId));
    }

    public function testCreateEntryWithLinksInOneRequest(): void
    {
        $invoiceId = $this->saleInvoice('DL0006', $this->client('Odběratel DL create'), 500.0);
        $map = $this->accounts->codeToIdMap($this->supplierId);
        self::assertArrayHasKey('518', $map);

        $res = $this->invokeJournal('create', 'POST', [], [
            'entry_date'  => self::YEAR . '-06-20',
            'description' => 'Dohad k faktuře DL0006',
            'lines'       => [
                ['account_code' => '518', 'side' => 'debit',  'amount' => 500.0],
                ['account_code' => '321', 'side' => 'credit', 'amount' => 500.0],
            ],
            'links' => [['doc_type' => 'invoice', 'doc_id' => $invoiceId, 'note' => 'Souvisí']],
        ]);

        self::assertSame(201, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('manual', $res['body']['source_type']);
        self::assertNull($res['body']['source_id'], 'Vazba nesmí obsadit source_id (idempotence deníku).');
        self::assertCount(1, $res['body']['links']);
        self::assertSame($invoiceId, $res['body']['links'][0]['doc_id']);
    }

    public function testInvalidLinkAbortsEntryCreation(): void
    {
        $before = $this->countManualEntries();

        $res = $this->invokeJournal('create', 'POST', [], [
            'entry_date' => self::YEAR . '-06-21',
            'lines'      => [
                ['account_code' => '518', 'side' => 'debit',  'amount' => 100.0],
                ['account_code' => '321', 'side' => 'credit', 'amount' => 100.0],
            ],
            'links' => [['doc_type' => 'invoice', 'doc_id' => 999999999]],
        ]);

        self::assertSame(404, $res['status']);
        self::assertSame(
            $before,
            $this->countManualEntries(),
            'Neplatná vazba nesmí nechat v deníku zápis, o kterém si uživatel myslí, že doklad nese.'
        );
    }

    public function testDeleteLinkRemovesEdge(): void
    {
        $invoiceId   = $this->saleInvoice('DL0007', $this->client('Odběratel DL del'), 200.0);
        $manualEntry = $this->manualEntry();
        $linkId = $this->repo->add($manualEntry, $this->supplierId, 'invoice', $invoiceId, null, $this->userId);
        self::assertCount(1, $this->related($manualEntry)['items']);

        $res = $this->invoke('delete', 'DELETE', ['id' => (string) $manualEntry, 'linkId' => (string) $linkId]);
        self::assertSame(200, $res['status']);
        self::assertSame($linkId, $res['body']['deleted']);
        self::assertSame([], $res['body']['items']);
        self::assertSame([], $this->related($manualEntry)['items']);

        $again = $this->invoke('delete', 'DELETE', ['id' => (string) $manualEntry, 'linkId' => (string) $linkId]);
        self::assertSame(404, $again['status']);
    }

    public function testLinksDieWithTheirEntry(): void
    {
        $invoiceId   = $this->saleInvoice('DL0008', $this->client('Odběratel DL cascade'), 150.0);
        $manualEntry = $this->manualEntry();
        $this->repo->add($manualEntry, $this->supplierId, 'invoice', $invoiceId, null, $this->userId);

        $this->db->pdo()->prepare('DELETE FROM journal_entries WHERE id = ? AND supplier_id = ?')
            ->execute([$manualEntry, $this->supplierId]);

        self::assertSame(
            0,
            (int) $this->db->pdo()->query(
                "SELECT COUNT(*) FROM journal_entry_document_links WHERE entry_id = {$manualEntry}"
            )->fetchColumn(),
            'Vazba musí zmizet se zápisem (složené FK z migrace 1514).'
        );
    }

    public function testCandidatesSearchIsScopedAndNeedsTwoChars(): void
    {
        $invoiceId = $this->saleInvoice('DL0009', $this->client('Odběratel DL search'), 400.0);

        $short = $this->invoke('candidates', 'GET', [], [], ['q' => 'D']);
        self::assertSame([], $short['body']['items'], 'Jednoznakový dotaz netahá celou tabulku.');

        $hit = $this->invoke('candidates', 'GET', [], [], ['q' => 'DL0009']);
        $ids = array_column(array_filter(
            $hit['body']['items'],
            static fn (array $i): bool => $i['doc_type'] === 'invoice'
        ), 'doc_id');
        self::assertContains($invoiceId, $ids);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** Ruční zápis (source_type='manual', source_id NULL) — přesně ten z UI. */
    private function manualEntry(): int
    {
        $map = $this->accounts->codeToIdMap($this->supplierId);
        return $this->journal->insert([
            'supplier_id' => $this->supplierId,
            'period_id'   => $this->periodId,
            'entry_date'  => self::YEAR . '-06-12',
            'document_no' => 'ID-' . uniqid(),
            'description' => 'Interní doklad',
            'source_type' => 'manual',
            'source_id'   => null,
            'posted_at'   => date('Y-m-d H:i:s'),
            'posted_by'   => $this->userId,
        ], [
            ['account_id' => $map['518']['id'], 'side' => 'debit', 'amount' => 100.0],
            ['account_id' => $map['321']['id'], 'side' => 'credit', 'amount' => 100.0],
        ]);
    }

    private function countManualEntries(): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ? AND source_type = 'manual'"
        );
        $stmt->execute([$this->supplierId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array{items:list<array<string,mixed>>, truncated:bool} */
    private function related(int $entryId): array
    {
        $entry = $this->journal->find($entryId, $this->supplierId);
        self::assertIsArray($entry, "Zápis #{$entryId} nenalezen.");
        return $this->links->related($this->supplierId, $entry, \MyInvoice\Service\Accounting\JournalRelatedAccess::all());
    }

    /**
     * @param  array<string,string> $args
     * @param  array<string,mixed>  $body
     * @param  array<string,string> $query
     * @return array{status:int, body:array<string,mixed>}
     */
    private function invoke(string $method, string $httpMethod, array $args, array $body = [], array $query = []): array
    {
        $req = $this->request($httpMethod, $query);
        if ($body !== []) {
            $req = $req->withParsedBody($body);
        }
        return $this->decode($args === []
            ? $this->action->{$method}($req, new Psr7Response())
            : $this->action->{$method}($req, new Psr7Response(), $args));
    }

    /**
     * @param  array<string,string> $args
     * @param  array<string,mixed>  $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function invokeJournal(string $method, string $httpMethod, array $args, array $body = []): array
    {
        $req = $this->request($httpMethod)->withParsedBody($body);
        return $this->decode($args === []
            ? $this->journalAction->{$method}($req, new Psr7Response())
            : $this->journalAction->{$method}($req, new Psr7Response(), $args));
    }

    /** @param array<string,string> $query */
    private function request(string $httpMethod, array $query = []): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($httpMethod, '/api/accounting')
            ->withQueryParams($query)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant']);
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private function decode(\Psr\Http\Message\ResponseInterface $resp): array
    {
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
