<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Invoice\CancelInvoiceAction;
use MyInvoice\Action\Invoice\DeleteInvoiceAction;
use MyInvoice\Action\PurchaseInvoice\DeletePurchaseInvoiceAction;
use MyInvoice\Action\PurchaseInvoice\TransitionPurchaseInvoiceStatusAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\DocumentJournalSync;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Integrační testy DocumentJournalSync (audit 2026-07, nálezy H4/H5, Fáze A3).
 *
 * Reprodukují PŮVODNÍ díru a ověřují opravu skrz REÁLNÉ Action třídy z DI kontejneru:
 *   (a) smazání zaúčtované FV      → aktivní zápis stornován, ne osiřelý
 *   (b) interní storno zaúčtované FV → aktivní zápis stornován
 *   (c) přechod PF na cancelled    → aktivní zápis stornován
 *   (d) tytéž operace v UZAVŘENÉM období → 409, doklad i zápis beze změny (§35)
 * + bonus: smazání zaúčtované PF (H4 pro purchase_invoices).
 *
 * Vše běží v jedné transakci, kterou tearDown rollbackne. Action i PostingService
 * detekují běžící transakci (ownTx pattern) a neotevírají vlastní commit → atomické.
 * Soft-skip bez cfg.php.
 */
#[Group('integration')]
final class DocumentJournalSyncTest extends TestCase
{
    private const YEAR = 2099;

    private Connection $db;
    private PostingService $posting;
    private DocumentJournalSync $sync;
    private JournalEntryRepository $journal;
    private AccountingPeriodRepository $periods;
    private DeleteInvoiceAction $deleteInvoice;
    private CancelInvoiceAction $cancelInvoice;
    private DeletePurchaseInvoiceAction $deletePurchase;
    private TransitionPurchaseInvoiceStatusAction $transitionPurchase;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $periodId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db                 = $container->get(Connection::class);
            $this->posting            = $container->get(PostingService::class);
            $this->sync               = $container->get(DocumentJournalSync::class);
            $this->journal            = $container->get(JournalEntryRepository::class);
            $this->periods            = $container->get(AccountingPeriodRepository::class);
            $this->deleteInvoice      = $container->get(DeleteInvoiceAction::class);
            $this->cancelInvoice      = $container->get(CancelInvoiceAction::class);
            $this->deletePurchase     = $container->get(DeletePurchaseInvoiceAction::class);
            $this->transitionPurchase = $container->get(TransitionPurchaseInvoiceStatusAction::class);
            $seeder                   = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/currency/vat_rate/user/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
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

    // ── (a) H4: smazání zaúčtované FV (jádro na úrovni DocumentJournalSync) ──
    //
    // Full DeleteInvoiceAction volá po commitu StatsRecomputer, který si otevírá
    // vlastní transakci a v testovacím režimu (vše v jedné rollback transakci) padne
    // na "already active transaction" — proto se jádro fixu (reverze + detach, žádný
    // sirotek) testuje přímo na službě; end-to-end DeleteInvoiceAction pokrývá abort
    // scénář (d) níže.

    public function testOnDeleteReversesActiveInvoiceEntryNoOrphan(): void
    {
        $client    = $this->client('Odběratel s.r.o.', true, false);
        $invoiceId = $this->sale('FV-2099-A1', $client, '1', 1000.00, 210.00, 21.00);
        $entryId   = $this->postInvoiceEntry($invoiceId);

        self::assertSame(1, $this->activeEntryCount('invoice', $invoiceId), 'Předpoklad: aktivní zápis existuje.');

        // Reverze zápisu + smazání dokladu ve stejné (testovací) transakci.
        $this->sync->onDelete($this->supplierId, 'invoice', $invoiceId, ['user_id' => $this->userId, 'posted_by' => $this->userId]);
        $this->db->pdo()->prepare('DELETE FROM invoices WHERE id = ?')->execute([$invoiceId]);

        self::assertSame(0, $this->invoiceCount($invoiceId), 'Faktura smazána.');
        self::assertSame(0, $this->activeEntryCount('invoice', $invoiceId), 'Žádný sirotčí aktivní zápis.');

        $original = $this->entryRow($entryId);
        self::assertNotNull($original);
        self::assertNotNull($original['reversed_by'], 'Původní zápis má reversed_by (stornován).');
        self::assertNull($original['source_id'], 'Zápis odpojen od smazaného dokladu (detachSource).');
        self::assertSame(1, $this->reversalCount((int) $original['reversed_by']), 'Protizápis existuje.');
    }

    public function testOnDeleteManyReversesParentAndCascadeChildEntries(): void
    {
        $client = $this->client('Odběratel s.r.o.', true, false);
        $parentId = $this->sale('FV-2099-A2', $client, '1', 1000.00, 210.00, 21.00);
        $childId = $this->sale('DOB-2099-A2', $client, '1', 100.00, 21.00, 21.00);
        $this->db->pdo()->prepare(
            "UPDATE invoices SET parent_invoice_id = ?, invoice_type = 'credit_note' WHERE id = ?"
        )->execute([$parentId, $childId]);

        $parentEntryId = $this->postInvoiceEntry($parentId);
        $childEntryId = $this->postInvoiceEntry($childId);

        $this->sync->onDeleteMany(
            $this->supplierId,
            'invoice',
            [$childId, $parentId],
            ['user_id' => $this->userId, 'posted_by' => $this->userId],
        );
        $this->db->pdo()->prepare('DELETE FROM invoices WHERE id = ?')->execute([$parentId]);

        self::assertSame(0, $this->invoiceCount($parentId));
        self::assertSame(0, $this->invoiceCount($childId), 'Child doklad byl smazán CASCADE.');
        foreach ([$parentEntryId, $childEntryId] as $entryId) {
            $entry = $this->entryRow($entryId);
            self::assertNotNull($entry['reversed_by'], 'Parent i child zápis jsou stornované.');
            self::assertNull($entry['source_id'], 'Parent i child zápis jsou odpojené od smazaného zdroje.');
        }
    }

    // ── (b) H5: interní storno zaúčtované FV (jádro na úrovni služby) ───────

    public function testOnCancelReversesActiveInvoiceEntryKeepsLink(): void
    {
        $client    = $this->client('Odběratel s.r.o.', true, false);
        $invoiceId = $this->sale('FV-2099-B1', $client, '1', 1000.00, 210.00, 21.00);
        $entryId   = $this->postInvoiceEntry($invoiceId);

        $this->sync->onCancel($this->supplierId, 'invoice', $invoiceId, ['user_id' => $this->userId, 'posted_by' => $this->userId]);

        self::assertSame(0, $this->activeEntryCount('invoice', $invoiceId), 'Aktivní zápis stornován — žádná divergence deník vs. DPH.');
        $original = $this->entryRow($entryId);
        self::assertNotNull($original['reversed_by'], 'Původní zápis stornován.');
        // U storna (doklad zůstává) se source_id NEodpojuje — vazba je součást auditní stopy.
        self::assertSame($invoiceId, (int) $original['source_id'], 'source_id na doklad zůstává.');
    }

    public function testOnCancelInClosedPeriodThrowsAndLeavesEntryActive(): void
    {
        $client    = $this->client('Odběratel s.r.o.', true, false);
        $invoiceId = $this->sale('FV-2099-B2', $client, '1', 1000.00, 210.00, 21.00);
        $entryId   = $this->postInvoiceEntry($invoiceId);
        $this->periods->setStatus($this->periodId, $this->supplierId, 'closed');

        try {
            $this->sync->onCancel($this->supplierId, 'invoice', $invoiceId, []);
            self::fail('Storno v uzavřeném období musí vyhodit PostingException.');
        } catch (PostingException $e) {
            self::assertSame('period_not_open', $e->errorCode);
        }

        self::assertSame(1, $this->activeEntryCount('invoice', $invoiceId), 'Zápis zůstal aktivní (nestornovaný).');
        self::assertNull($this->entryRow($entryId)['reversed_by'], 'Zápis nebyl stornován.');
    }

    // ── (b3) retenční lhůta § 31 ZoÚ ───────────────────────────────────────

    /**
     * Zaúčtovaný doklad v běžící retenční lhůtě nejde fyzicky smazat bez ack_retention.
     * Zápis musí být zamčený — ten, který by šel smazat i ručně v deníku, force-delete
     * odklidí sám a retenční brána se ho pak netýká (InvoiceUncancelAndJournalPurgeTest).
     */
    public function testDeletePostedInvoiceWithinRetentionPeriodIsRejected(): void
    {
        $client    = $this->client('Odběratel s.r.o.', true, false);
        $invoiceId = $this->sale('FV-2099-R1', $client, '1', 1000.00, 210.00, 21.00);
        $this->postInvoiceEntry($invoiceId);
        // Zavřené období: zápis nejde smazat ani s potvrzením zásahu, takže zbývá retenční
        // brána. (Uzamčené datum by místo ní vrátilo varování k potvrzení — viz
        // InvoiceUncancelAndJournalPurgeTest.)
        $this->periods->setStatus($this->periodId, $this->supplierId, 'closed');

        $res = $this->invoke($this->deleteInvoice, 'admin', ['id' => (string) $invoiceId], [], ['force' => '1']);

        self::assertSame(422, $res['status']);
        self::assertSame('retention_period', $res['body']['error']['code'] ?? null);
        self::assertSame(1, $this->invoiceCount($invoiceId), 'Doklad musí zůstat.');
    }

    public function testForceDeleteUnpostedParentWithPostedChildStillRequiresRetentionAck(): void
    {
        $client   = $this->client('Odběratel s.r.o.', true, false);
        $parentId = $this->sale('FV-2099-R2', $client, '1', 1000.00, 210.00, 21.00);
        $childId  = $this->sale('DOB-2099-R2', $client, '1', 100.00, 21.00, 21.00);
        $this->db->pdo()->prepare(
            "UPDATE invoices SET parent_invoice_id = ?, invoice_type = 'credit_note' WHERE id = ?"
        )->execute([$parentId, $childId]);
        $this->postInvoiceEntry($childId);
        $this->periods->setStatus($this->periodId, $this->supplierId, 'closed');

        $res = $this->invoke($this->deleteInvoice, 'admin', ['id' => (string) $parentId], [], ['force' => '1']);

        self::assertSame(422, $res['status'], 'Zaúčtovaný CASCADE child musí udržet retenční bránu.');
        self::assertSame('retention_period', $res['body']['error']['code'] ?? null);
        self::assertSame(1, $this->invoiceCount($parentId), 'Parent musí zůstat.');
        self::assertSame(1, $this->invoiceCount($childId), 'Zaúčtovaný child musí zůstat.');
    }

    // Úspěšné smazání s `ack_retention=1` (a jeho auditní stopu) ověřuje
    // StockInvoiceIntegrationTest — tady by narazilo na vlastní transakci
    // StatsRecomputeru uvnitř transakce testu.

    // ── (c) H5: přechod PF na cancelled ────────────────────────────────────

    public function testCancelTransitionPostedPurchaseInvoiceReversesEntry(): void
    {
        $webhooks = Bootstrap::buildContainer()->get(\MyInvoice\Service\Integration\WebhookEndpoints::class);
        $endpoint = $webhooks->create($this->supplierId, [
            'name' => 'Storno přijatého dokladu', 'url' => 'https://receiver.example.test/events',
            'event_types' => ['purchase_invoice.cancelled'],
        ]);
        $vendor     = $this->client('Dodavatel a.s.', false, true);
        $purchaseId = $this->purchase('PF-2099-C1', $vendor, '40', false, 2000.00, 420.00, 21.00);
        $entryId    = $this->postPurchaseEntry($purchaseId);

        $res = $this->invoke($this->transitionPurchase, 'admin', ['id' => (string) $purchaseId], ['target' => 'cancelled']);
        self::assertSame(200, $res['status'], 'Přechod PF received → cancelled projde.');

        self::assertSame(0, $this->activeEntryCount('purchase_invoice', $purchaseId), 'Aktivní zápis PF stornován.');
        $original = $this->entryRow($entryId);
        self::assertNotNull($original['reversed_by'], 'Původní zápis PF stornován.');

        $status = $this->db->pdo()->query("SELECT status FROM purchase_invoices WHERE id = {$purchaseId}")->fetchColumn();
        self::assertSame('cancelled', $status, 'PF je stornovaná.');
        $events = $webhooks->history($this->supplierId, $endpoint['id'], PHP_INT_MAX);
        self::assertSame(['purchase_invoice.cancelled'], array_column($events, 'event_type'));
    }

    // ── bonus H4: smazání zaúčtované PF ────────────────────────────────────

    public function testDeletePostedPurchaseInvoiceReversesActiveEntry(): void
    {
        $vendor     = $this->client('Dodavatel a.s.', false, true);
        $purchaseId = $this->purchase('PF-2099-D1', $vendor, '40', false, 2000.00, 420.00, 21.00);
        $entryId    = $this->postPurchaseEntry($purchaseId);

        // received PF → admin force delete.
        $res = $this->invoke($this->deletePurchase, 'admin', ['id' => (string) $purchaseId], [], ['force' => '1']);
        self::assertSame(200, $res['status'], 'Admin s force smaže zaúčtovanou PF.');

        self::assertSame(0, $this->purchaseCount($purchaseId), 'PF smazána.');
        self::assertSame(0, $this->activeEntryCount('purchase_invoice', $purchaseId), 'Žádný sirotčí aktivní zápis PF.');
        $original = $this->entryRow($entryId);
        self::assertNotNull($original['reversed_by'], 'Zápis PF stornován.');
        self::assertNull($original['source_id'], 'Zápis PF odpojen od smazaného dokladu.');
    }

    // ── (d) uzavřené období → 409, doklad i zápis beze změny ───────────────

    public function testDeleteInvoiceInClosedPeriodAbortsAndKeepsEntry(): void
    {
        $client    = $this->client('Odběratel s.r.o.', true, false);
        $invoiceId = $this->sale('FV-2099-E1', $client, '1', 1000.00, 210.00, 21.00);
        $entryId   = $this->postInvoiceEntry($invoiceId);

        // Uzavři období — storno zápisu už nelze zaúčtovat (§35).
        $this->periods->setStatus($this->periodId, $this->supplierId, 'closed');

        // Admin + force=1 překoná zámek uzavřeného období u dokladu; storno v deníku ale ne.
        // `ack_retention=1` odbaví retenční bránu (§ 31), ať test měří skutečně tu situaci,
        // kvůli které existuje — zastavení na nestornovatelném zápisu, ne na lhůtě.
        $res = $this->invoke($this->deleteInvoice, 'admin', ['id' => (string) $invoiceId], [], ['force' => '1', 'ack_retention' => '1']);
        self::assertSame(409, $res['status'], 'Smazání se zastaví — storno zápisu v uzavřeném období nelze zaúčtovat.');

        // Doklad i AKTIVNÍ zápis zůstaly beze změny — žádný sirotek, žádná ztráta.
        self::assertSame(1, $this->invoiceCount($invoiceId), 'Faktura zůstala.');
        self::assertSame(1, $this->activeEntryCount('invoice', $invoiceId), 'Zápis zůstal aktivní (nestornovaný).');
        $original = $this->entryRow($entryId);
        self::assertNull($original['reversed_by'], 'Zápis nebyl stornován.');
        self::assertSame($invoiceId, (int) $original['source_id'], 'Vazba na doklad zůstala.');
    }

    public function testInternalCancelInClosedPeriodAbortsAndKeepsEntry(): void
    {
        $client    = $this->client('Odběratel s.r.o.', true, false);
        $invoiceId = $this->sale('FV-2099-E2', $client, '1', 1000.00, 210.00, 21.00);
        $entryId   = $this->postInvoiceEntry($invoiceId);

        $this->periods->setStatus($this->periodId, $this->supplierId, 'closed');

        $res = $this->invoke($this->cancelInvoice, 'admin', ['id' => (string) $invoiceId], ['mode' => 'internal'], ['force' => '1']);
        self::assertSame(409, $res['status'], 'Storno se zastaví v uzavřeném období.');

        self::assertSame(1, $this->activeEntryCount('invoice', $invoiceId), 'Zápis zůstal aktivní.');
        $original = $this->entryRow($entryId);
        self::assertNull($original['reversed_by'], 'Zápis nebyl stornován.');
        $status = $this->db->pdo()->query("SELECT status FROM invoices WHERE id = {$invoiceId}")->fetchColumn();
        self::assertSame('issued', $status, 'Faktura zůstala vystavená (nestornovaná).');
    }

    // ── helpers ────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $body
     * @param array<string,mixed> $query
     * @param array<string,string> $args
     * @return array{status:int, body:array<mixed>}
     */
    private function invoke(object $action, string $role, array $args, array $body = [], array $query = []): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/test')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
        if ($body !== []) {
            $req = $req->withParsedBody($body);
        }
        if ($query !== []) {
            $req = $req->withQueryParams($query);
        }
        $resp = $args === []
            ? ($action)($req, new Psr7Response())
            : ($action)($req, new Psr7Response(), $args);
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    private function postInvoiceEntry(int $invoiceId): int
    {
        $lines = $this->posting->buildFromInvoice($this->supplierId, $invoiceId);
        return $this->posting->postDocument(
            $this->supplierId,
            'invoice',
            $invoiceId,
            $lines,
            ['entry_date' => self::YEAR . '-06-15', 'posted_by' => $this->userId, 'user_id' => $this->userId],
        );
    }

    private function postPurchaseEntry(int $purchaseId): int
    {
        $lines = $this->posting->buildFromPurchaseInvoice($this->supplierId, $purchaseId);
        return $this->posting->postDocument(
            $this->supplierId,
            'purchase_invoice',
            $purchaseId,
            $lines,
            ['entry_date' => self::YEAR . '-06-20', 'posted_by' => $this->userId, 'user_id' => $this->userId],
        );
    }

    /** Počet AKTIVNÍCH (posted_at NOT NULL, reversed_by NULL) zápisů pro zdroj. */
    private function activeEntryCount(string $sourceType, int $sourceId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM journal_entries
              WHERE supplier_id = ? AND source_type = ? AND source_id = ?
                AND posted_at IS NOT NULL AND reversed_by IS NULL"
        );
        $stmt->execute([$this->supplierId, $sourceType, $sourceId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,mixed>|null */
    private function entryRow(int $entryId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, source_type, source_id, posted_at, reversed_by FROM journal_entries WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$entryId, $this->supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function reversalCount(int $reversalId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM journal_entries WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$reversalId, $this->supplierId]);
        return (int) $stmt->fetchColumn();
    }

    private function invoiceCount(int $invoiceId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM invoices WHERE id = ?');
        $stmt->execute([$invoiceId]);
        return (int) $stmt->fetchColumn();
    }

    private function purchaseCount(int $purchaseId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM purchase_invoices WHERE id = ?');
        $stmt->execute([$purchaseId]);
        return (int) $stmt->fetchColumn();
    }

    private function client(string $name, bool $customer, bool $vendor): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "CZ12345678", "test@example.com", "cs", ?, ?, ?)'
        );
        $stmt->execute([$this->supplierId, $name, $this->czId, $this->currencyId, $customer ? 1 : 0, $vendor ? 1 : 0]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function sale(string $varsymbol, int $clientId, string $code, float $base, float $vat, float $rate): int
    {
        $with = $base + $vat;
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, language, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, "cs", 0, ?, ?, ?, "issued", ?, ?)'
        );
        $issue = self::YEAR . '-06-15';
        $stmt->execute([$this->supplierId, $varsymbol, $clientId, $issue, $issue, $issue, $this->currencyId, $base, $vat, $with, $code, $this->userId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->insertItem('invoice_items', 'invoice_id', $id, $base, $vat, $rate);
        return $id;
    }

    private function purchase(string $number, int $vendorId, string $code, bool $rc, float $base, float $vat, float $rate): int
    {
        $with = $base + $vat;
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                 vat_deduction, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, ?, "{}", ?, ?, ?, "received", ?, "full", ?)'
        );
        $issue = self::YEAR . '-06-15';
        $stmt->execute([$this->supplierId, $vendorId, $number, $issue, $issue, $issue, $issue, $this->currencyId, $rc ? 1 : 0, $base, $vat, $with, $code, $this->userId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->insertItem('purchase_invoice_items', 'purchase_invoice_id', $id, $base, $vat, $rate);
        return $id;
    }

    private function insertItem(string $table, string $fk, int $id, float $base, float $vat, float $rate): void
    {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO {$table}
                ({$fk}, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, 'Test položka', 1, 'ks', ?, ?, ?, ?, ?, ?, 0)"
        );
        $stmt->execute([$id, $base, $this->vatRateId, $rate, $base, $vat, $base + $vat]);
    }
}
