<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\JournalAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\DocumentAutoPoster;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * A2 — hromadné zaúčtování ze seznamu (bulk) + per-firma auto-post hook.
 *
 * Bulk (JournalAction::postInvoicesBulk / postPurchasesBulk): jeden neúspěšný doklad
 * NEzablokuje zbytek dávky — report `posted`/`failed` musí obsahovat obojí správně.
 *
 * Auto-post hook (DocumentAutoPoster::maybeAutoPost): firma se zapnutým flagem →
 * doklad se po vystavení automaticky zaúčtuje; firma bez flagu → beze změny; chyba
 * zaúčtování NESMÍ probublat (jen audit warning), doklad zůstane nezaúčtovaný.
 *
 * Vše v jedné transakci, tearDown rollbackne. Soft-skip bez cfg.php / migrace 1035.
 */
#[Group('integration')]
final class AutoPostAndBulkTest extends TestCase
{
    private const YEAR = 2099;

    private Connection $db;
    private JournalAction $journalAction;
    private DocumentAutoPoster $autoPoster;
    private JournalEntryRepository $journal;
    private AccountingPeriodRepository $periods;

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
            $this->db            = $container->get(Connection::class);
            $this->journalAction = $container->get(JournalAction::class);
            $this->autoPoster    = $container->get(DocumentAutoPoster::class);
            $this->journal       = $container->get(JournalEntryRepository::class);
            $this->periods       = $container->get(AccountingPeriodRepository::class);
            $seeder              = $container->get(ChartOfAccountsSeeder::class);
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
        // Migrace 1035 (auto_post_invoices/auto_post_purchases) musí být aplikovaná.
        $hasCol = (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'supplier'
                AND column_name = 'auto_post_invoices'"
        )->fetchColumn();
        if ($hasCol === 0) {
            $this->markTestSkipped('Migrace 1035 (auto_post_*) není aplikovaná.');
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

    // ── Bulk ───────────────────────────────────────────────────────────────────

    public function testBulkPostInvoicesReportsPostedAndFailed(): void
    {
        $clientId = $this->client('Odběratel s.r.o.');
        $ok1 = $this->sale('FV-2099-B1', $clientId, '1', 1000.00, 210.00, 21.00);
        $ok2 = $this->sale('FV-2099-B2', $clientId, '1', 500.00, 105.00, 21.00);
        // Faktura BEZ položek → VatLedger nemá co sečíst → document_not_postable.
        $bad = $this->saleNoItems('FV-2099-B3', $clientId);

        $res = $this->call('postInvoicesBulk', [
            'ids' => [$ok1, $ok2, $bad],
        ]);

        self::assertSame(200, $res['status']);
        $posted = $res['body']['posted'];
        $failed = $res['body']['failed'];

        sort($posted);
        self::assertSame([$ok1, $ok2], $this->normalizeIds($posted), 'Oba validní doklady se zaúčtovaly.');
        self::assertCount(1, $failed, 'Právě jeden doklad selhal.');
        self::assertSame($bad, (int) $failed[0]['id']);
        self::assertSame('document_not_postable', $failed[0]['error_code'], 'Per-doklad strojový kód chyby.');
        self::assertNotSame('', (string) $failed[0]['message']);

        // Zaúčtované doklady jsou zamčené (booked_at), neúspěšný ne.
        self::assertNotNull($this->bookedAt('invoices', $ok1));
        self::assertNotNull($this->bookedAt('invoices', $ok2));
        self::assertNull($this->bookedAt('invoices', $bad), 'Neúspěšný doklad zůstane nezaúčtovaný.');

        // A skutečně vznikly zápisy v deníku pro validní doklady.
        self::assertNotNull($this->journal->findBySource($this->supplierId, 'invoice', $ok1));
        self::assertNotNull($this->journal->findBySource($this->supplierId, 'invoice', $ok2));
        self::assertNull($this->journal->findBySource($this->supplierId, 'invoice', $bad));
    }

    public function testBulkPostPurchasesReportsPostedAndFailed(): void
    {
        $vendorId = $this->client('Dodavatel a.s.');
        $ok1 = $this->purchase('PF-2099-B1', $vendorId, '40', 2000.00, 420.00, 21.00);
        $bad = 999000000; // neexistující doklad → entry_not_found

        $res = $this->call('postPurchasesBulk', ['ids' => [$ok1, $bad]]);

        self::assertSame(200, $res['status']);
        self::assertSame([$ok1], $this->normalizeIds($res['body']['posted']));
        self::assertCount(1, $res['body']['failed']);
        self::assertSame($bad, (int) $res['body']['failed'][0]['id']);
        self::assertSame('entry_not_found', $res['body']['failed'][0]['error_code']);
    }

    public function testBulkPostRejectsEmptyIds(): void
    {
        $res = $this->call('postInvoicesBulk', ['ids' => []]);
        self::assertSame(422, $res['status']);
        self::assertSame('validation_failed', $res['body']['error']['code']);
    }

    public function testBulkPostForbiddenForReadonly(): void
    {
        $clientId = $this->client('Odběratel s.r.o.');
        $ok1 = $this->sale('FV-2099-RO', $clientId, '1', 1000.00, 210.00, 21.00);
        $res = $this->call('postInvoicesBulk', ['ids' => [$ok1]], 'readonly');
        self::assertSame(403, $res['status']);
    }

    public function testBulkPostRejectsOversizedBatch(): void
    {
        // Strop 500 (adversariální review Fáze A) — nad limit vrátí 422, nic se nezaúčtuje.
        $ids = range(1, 501);
        $res = $this->call('postInvoicesBulk', ['ids' => $ids]);
        self::assertSame(422, $res['status']);
        self::assertSame('validation_failed', $res['body']['error']['code']);
    }

    // ── Auto-post hook ───────────────────────────────────────────────────────────

    public function testAutoPostEnabledPostsInvoice(): void
    {
        $this->setAutoPost(invoices: true);
        $clientId = $this->client('Odběratel s.r.o.');
        $invoiceId = $this->sale('FV-2099-AP1', $clientId, '1', 1000.00, 210.00, 21.00);

        $this->autoPoster->maybeAutoPost($this->supplierId, 'invoice', $invoiceId, $this->userId);

        self::assertNotNull(
            $this->journal->findBySource($this->supplierId, 'invoice', $invoiceId),
            'Firma se zapnutým hookem → FV se automaticky zaúčtuje.',
        );
        self::assertNotNull($this->bookedAt('invoices', $invoiceId), 'Auto-post zamkne doklad (booked_at).');
        $audit = $this->db->pdo()->query(
            "SELECT payload FROM activity_log
              WHERE supplier_id = {$this->supplierId} AND action = 'accounting.auto_posted'
                AND entity_type = 'invoice' AND entity_id = {$invoiceId}
              ORDER BY id DESC LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertNotFalse($audit, 'Úspěšný auto-post má dohledatelnou provenance událost.');
        $payload = json_decode((string) $audit['payload'], true);
        self::assertGreaterThan(0, (int) ($payload['journal_entry_id'] ?? 0));
    }

    public function testAutoPostPurchaseWithoutDescriptionGetsDefault(): void
    {
        $this->setAutoPost(purchases: true);
        $vendorId   = $this->client('Vodafone Czech Republic a.s.');
        $purchaseId = $this->purchase('VF-2099-8473', $vendorId, '40', 2000.00, 420.00, 21.00);

        $this->autoPoster->maybeAutoPost($this->supplierId, 'purchase_invoice', $purchaseId, $this->userId);

        $entry = $this->journal->findBySource($this->supplierId, 'purchase_invoice', $purchaseId);
        self::assertNotNull($entry, 'Auto-post PF se zapnutým flagem projde.');
        self::assertStringStartsWith(
            'PF VF-2099-8473 — Vodafone Czech Republic a.s.',
            (string) $entry['description'],
            'Auto-post bez explicitního popisu dostane čitelný default (číslo dokladu + dodavatel).',
        );
    }

    public function testAutoPostDisabledDoesNothing(): void
    {
        // Flag vypnutý (default) — i když je firma double_entry, nic se nezaúčtuje.
        $this->setAutoPost(invoices: false);
        $clientId = $this->client('Odběratel s.r.o.');
        $invoiceId = $this->sale('FV-2099-AP2', $clientId, '1', 1000.00, 210.00, 21.00);

        $this->autoPoster->maybeAutoPost($this->supplierId, 'invoice', $invoiceId, $this->userId);

        self::assertNull($this->journal->findBySource($this->supplierId, 'invoice', $invoiceId), 'Bez flagu → žádný zápis.');
        self::assertNull($this->bookedAt('invoices', $invoiceId));
    }

    public function testAutoPostIgnoredInTaxEvidenceMode(): void
    {
        // Flag zapnutý, ale režim tax_evidence — doklady se do deníku neúčtují → no-op.
        $this->db->pdo()->prepare(
            "UPDATE supplier SET accounting_mode = 'tax_evidence', auto_post_invoices = 1 WHERE id = ?"
        )->execute([$this->supplierId]);
        $clientId = $this->client('Odběratel s.r.o.');
        $invoiceId = $this->sale('FV-2099-AP3', $clientId, '1', 1000.00, 210.00, 21.00);

        $this->autoPoster->maybeAutoPost($this->supplierId, 'invoice', $invoiceId, $this->userId);

        self::assertNull($this->journal->findBySource($this->supplierId, 'invoice', $invoiceId));
    }

    public function testAutoPostSwallowsErrorAndAudits(): void
    {
        // Zapnutý hook, ale nepostovatelný doklad (bez položek) → chyba NESMÍ probublat.
        $this->setAutoPost(invoices: true);
        $clientId = $this->client('Odběratel s.r.o.');
        $invoiceId = $this->saleNoItems('FV-2099-AP4', $clientId);

        // Nesmí vyhodit výjimku.
        $this->autoPoster->maybeAutoPost($this->supplierId, 'invoice', $invoiceId, $this->userId);

        self::assertNull($this->journal->findBySource($this->supplierId, 'invoice', $invoiceId), 'Nepostovatelný doklad se nezaúčtuje.');
        self::assertNull($this->bookedAt('invoices', $invoiceId), 'Faktura zůstane vystavená nezaúčtovaná.');

        $audit = $this->db->pdo()->query(
            "SELECT payload FROM activity_log
              WHERE supplier_id = {$this->supplierId} AND action = 'accounting.auto_post_failed'
                AND entity_type = 'invoice' AND entity_id = {$invoiceId}
              ORDER BY id DESC LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertNotFalse($audit, 'Chyba auto-postu je auditovaná (accounting.auto_post_failed).');
        $payload = json_decode((string) $audit['payload'], true);
        self::assertSame('document_not_postable', $payload['error_code']);
    }

    /**
     * Zálohová PF se jako předpis neúčtuje záměrně (účtuje se její úhrada na 314).
     * Auto-post ji proto nesmí zkoušet a hlásit accounting.auto_post_failed — v historii
     * dokladu to účetní četla jako chybu „nic se nezaúčtovalo".
     */
    public function testAutoPostSkipsPurchaseAdvanceWithoutFailureAudit(): void
    {
        $this->setAutoPost(purchases: true);
        $vendorId = $this->client('Dodavatel záloha');
        $advanceId = $this->purchase('ZPF-2099-AP5', $vendorId, '40', 1000.00, 210.00, 21.00);
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET document_kind = 'advance' WHERE id = ?")->execute([$advanceId]);

        $this->autoPoster->maybeAutoPost($this->supplierId, 'purchase_invoice', $advanceId, $this->userId);

        self::assertNull($this->journal->findBySource($this->supplierId, 'purchase_invoice', $advanceId));
        $failed = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM activity_log
              WHERE supplier_id = {$this->supplierId} AND action = 'accounting.auto_post_failed'
                AND entity_type = 'purchase_invoice' AND entity_id = {$advanceId}"
        )->fetchColumn();
        self::assertSame(0, $failed, 'Záloha nemá v historii chybu zaúčtování.');
    }

    /** Hromadné zaúčtování jen zajišťuje zápis: převzatou skupinu ponechá a nehlásí chybu. */
    public function testBulkPostKeepsSplitPostingGroupIdempotent(): void
    {
        [$docId, $baseId, $vatId] = $this->splitImportedPurchase('BULK');
        $before = [$this->journal->find($baseId, $this->supplierId), $this->journal->find($vatId, $this->supplierId)];

        $res = $this->call('postPurchasesBulk', ['ids' => [$docId]]);

        self::assertSame(200, $res['status']);
        self::assertSame([$docId], $this->normalizeIds($res['body']['posted']), json_encode($res['body']) ?: '');
        self::assertSame([$before[0], $before[1]], [$this->journal->find($baseId, $this->supplierId), $this->journal->find($vatId, $this->supplierId)]);
    }

    /** Zaúčtování podle návrhu skupinu ponechá; vlastní řádky z popupu jsou oprava a dostanou 409. */
    public function testSinglePostKeepsSplitGroupButCustomLinesRequireRepost(): void
    {
        [$docId, $baseId, $vatId] = $this->splitImportedPurchase('SINGLE');
        $before = [$this->journal->find($baseId, $this->supplierId), $this->journal->find($vatId, $this->supplierId)];

        $plain = $this->callWithArgs('postPurchase', ['id' => $docId], []);
        self::assertSame(200, $plain['status'], json_encode($plain['body']) ?: '');
        self::assertSame($baseId, (int) $plain['body']['id']);

        $custom = $this->callWithArgs('postPurchase', ['id' => $docId], ['lines' => [
            ['account_code' => '501', 'side' => 'debit', 'amount' => 1210.00],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 1210.00],
        ]]);
        self::assertSame(409, $custom['status'], json_encode($custom['body']) ?: '');
        self::assertSame('posting_group_requires_repost', $custom['body']['error']['code']);
        self::assertSame($before, [$this->journal->find($baseId, $this->supplierId), $this->journal->find($vatId, $this->supplierId)]);
    }

    /** Hook po přijetí nesmí čekat na zámek období kvůli převzaté skupině (invariant auto-postu). */
    public function testAutoPostOfOwnedGroupDoesNotWaitForLockedAccountingPeriods(): void
    {
        $this->setAutoPost(purchases: true);
        $vendorId = $this->client('Dodavatel převzaté skupiny');
        $docId = $this->purchase('PF-2099-GRPLOCK', $vendorId, '40', 1000.00, 210.00, 21.00);
        // Převzatý předpis zapsaný napřímo: PostingService by v této transakci sám
        // zamkl všechna období a souběžná uzávěrka by pak nešla nasimulovat.
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO journal_entries (supplier_id, period_id, entry_date, description, source_type, source_id, posted_at)
            VALUES (?, ?, ?, 'Převzatý předpis', 'purchase_invoice', ?, NOW())")
            ->execute([$this->supplierId, $this->periodId, self::YEAR . '-06-20', $docId]);
        $entryId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO journal_document_postings (supplier_id, doc_type, doc_id, entry_id) VALUES (?, 'purchase_invoice', ?, ?)")
            ->execute([$this->supplierId, $docId, $entryId]);

        $other = Connection::withoutSharedTestConnection(static fn (): Connection => Bootstrap::buildContainer()->get(Connection::class));
        try {
            $other->pdo()->beginTransaction();
            $lock = $other->pdo()->prepare('SELECT id FROM accounting_periods WHERE supplier_id = ? ORDER BY id LIMIT 1 FOR UPDATE');
            $lock->execute([$this->supplierId]);
            if ($lock->fetchColumn() === false) {
                self::markTestSkipped('Firma nemá potvrzené účetní období, které by šlo zamknout z jiného spojení.');
            }
            $pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
            $this->autoPoster->maybeAutoPost($this->supplierId, 'purchase_invoice', $docId, $this->userId);
        } finally {
            $pdo->exec('SET SESSION innodb_lock_wait_timeout = DEFAULT');
            if ($other->pdo()->inTransaction()) {
                $other->pdo()->rollBack();
            }
            $other->close();
        }
        self::assertSame($entryId, (int) $this->journal->findBySource($this->supplierId, 'purchase_invoice', $docId)['id']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** Syntetický převod PF: samostatný základ a DPH navázané linkerem jako Stereo. */
    private function splitImportedPurchase(string $tag): array
    {
        $posting = Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\PostingService::class);
        $vendorId = $this->client('Dodavatel rozděleného zaúčtování ' . $tag);
        $docId = $this->purchase('PF-2099-G' . $tag, $vendorId, '40', 1000.00, 210.00, 21.00);
        $base = $posting->postDocument($this->supplierId, 'purchase_invoice', $docId, [
            ['account_code' => '518', 'side' => 'debit', 'amount' => 1000.0],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 1000.0],
        ], ['entry_date' => self::YEAR . '-06-20']);
        $vat = $posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '343', 'side' => 'debit', 'amount' => 210.0],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 210.0],
        ], ['entry_date' => self::YEAR . '-06-20']);
        foreach ([$base, $vat] as $entry) {
            $this->db->pdo()->prepare('INSERT INTO stereo_nx_import_map
                (supplier_id, source_ico, source_company_index, kind, source_key, source_hash, target_id)
                VALUES (?, "00000000", 1, "accounting_journal", ?, ?, ?)')
                ->execute([$this->supplierId, 'test-bulk-group-' . $entry, str_repeat('a', 64), $entry]);
        }
        (new \MyInvoice\Service\Migration\Shared\JournalEntryLinker($this->db, 'Stereo NX'))
            ->attach($this->supplierId, $this->userId, 'purchase_invoice', 'manual', 'purchase_invoice', $docId, [$base, $vat]);
        return [$docId, $base, $vat];
    }

    private function setAutoPost(bool $invoices = false, bool $purchases = false): void
    {
        $this->db->pdo()->prepare(
            "UPDATE supplier SET accounting_mode = 'double_entry',
                    auto_post_invoices = ?, auto_post_purchases = ? WHERE id = ?"
        )->execute([$invoices ? 1 : 0, $purchases ? 1 : 0, $this->supplierId]);
        $policy = $this->db->pdo()->prepare(
            "INSERT INTO auto_posting_policy (supplier_id, operation_type, level, updated_by)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE level=VALUES(level), updated_by=VALUES(updated_by)"
        );
        $policy->execute([$this->supplierId, 'document.invoice', $invoices ? 'auto' : 'off', $this->userId]);
        $policy->execute([$this->supplierId, 'document.purchase', $purchases ? 'auto' : 'off', $this->userId]);
    }

    /**
     * Kontace upravená v zaúčtovacím popupu se zapíše TAK, JAK JE.
     *
     * Návrh systému je návrh, ne verdikt: rozúčtování na střediska, jiný nákladový účet
     * nebo kurzový rozdíl umí posoudit jen účetní. Dokud šlo zaúčtovat výhradně to, co
     * postavil server, musela opravu dělat ručním zápisem mimo doklad — a vazba na doklad
     * se tím ztratila.
     */
    public function testCustomLinesFromModalAreUsedInsteadOfTheServerProposal(): void
    {
        $clientId = $this->client('Odběratel s.r.o.');
        $id = $this->sale('FV-2099-EDIT', $clientId, '1', 1000.00, 210.00, 21.00);

        $res = $this->callWithArgs('postInvoice', ['id' => $id], [
            'lines' => [
                ['account_code' => '311', 'side' => 'debit',  'amount' => 1210.00],
                ['account_code' => '518', 'side' => 'credit', 'amount' => 1000.00],
                ['account_code' => '343', 'side' => 'credit', 'amount' => 210.00],
            ],
            'description' => 'Kontace upravená účetní',
        ]);

        self::assertSame(200, $res['status'], 'Upravená vyvážená kontace se musí zaúčtovat.');

        $entry = $this->journal->findBySource($this->supplierId, 'invoice', $id);
        self::assertNotNull($entry);
        $stmt = $this->db->pdo()->prepare(
            'SELECT ca.account_code
               FROM journal_entry_lines l JOIN chart_of_accounts ca ON ca.id = l.account_id
              WHERE l.entry_id = ? ORDER BY ca.account_code'
        );
        $stmt->execute([(int) $entry['id']]);

        // 518 místo 602 — kdyby se zapsal návrh systému, byl by tu výnosový účet.
        self::assertSame(['311', '343', '518'], array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'account_code'));
    }

    /** Nevyvážená kontace se nezaúčtuje — to není zápis, ale rozbité účetnictví. */
    public function testUnbalancedCustomLinesAreRejected(): void
    {
        $clientId = $this->client('Odběratel s.r.o.');
        $id = $this->sale('FV-2099-UNBAL', $clientId, '1', 1000.00, 210.00, 21.00);

        $res = $this->callWithArgs('postInvoice', ['id' => $id], [
            'lines' => [
                ['account_code' => '311', 'side' => 'debit',  'amount' => 1210.00],
                ['account_code' => '602', 'side' => 'credit', 'amount' => 1000.00],
            ],
        ]);

        self::assertGreaterThanOrEqual(400, $res['status']);
        self::assertNull($this->journal->findBySource($this->supplierId, 'invoice', $id));
    }

    /** Řádek bez účtu je chyba v zadání — nedoplňuje se dohadem. */
    public function testMalformedCustomLinesAreRejected(): void
    {
        $clientId = $this->client('Odběratel s.r.o.');
        $id = $this->sale('FV-2099-BADLINE', $clientId, '1', 1000.00, 210.00, 21.00);

        $res = $this->callWithArgs('postInvoice', ['id' => $id], [
            'lines' => [
                ['account_code' => '', 'side' => 'debit', 'amount' => 1210.00],
                ['account_code' => '602', 'side' => 'credit', 'amount' => 1210.00],
            ],
        ]);

        self::assertSame(422, $res['status']);
        self::assertNull($this->journal->findBySource($this->supplierId, 'invoice', $id));
    }

    /**
     * Bez `lines` staví kontaci server — původní, ověřená cesta. Tenhle test hlídá,
     * že se přidáním editace nezměnilo chování běžného „Zaúčtovat".
     */
    public function testWithoutCustomLinesTheServerStillBuildsThePosting(): void
    {
        $clientId = $this->client('Odběratel s.r.o.');
        $id = $this->sale('FV-2099-AUTO', $clientId, '1', 1000.00, 210.00, 21.00);

        $res = $this->callWithArgs('postInvoice', ['id' => $id], []);

        self::assertSame(200, $res['status']);
        self::assertNotNull($this->journal->findBySource($this->supplierId, 'invoice', $id));
    }

    /**
     * Nabídka „příště účtovat stejně" se dělá jen tam, kde pravidlo jde postavit
     * bez hádání — tedy když je znám dodavatel a druh výdaje je na dokladu jednoznačný.
     */
    public function testRuleBasisIsOfferedForSingleExpenseKind(): void
    {
        $vendorId = $this->client('Dodavatel s.r.o.');
        $id = $this->purchase('PF-2099-RB1', $vendorId, '1', 1000.00, 210.00, 21.00);
        $this->db->pdo()->prepare("UPDATE purchase_invoice_items SET expense_kind='service' WHERE purchase_invoice_id=?")
            ->execute([$id]);

        $res = $this->callWithArgs('postingPreview', ['source' => 'purchase-invoices', 'id' => $id], []);

        self::assertSame(200, $res['status']);
        self::assertSame($vendorId, $res['body']['rule_basis']['vendor_client_id']);
        self::assertSame('service', $res['body']['rule_basis']['expense_kind']);
    }

    /**
     * Doklad míchající druhy výdaje nabídku NEDOSTANE.
     *
     * Druh neurčuje jen účet: `small_asset` zakládá kartu evidence, `fixed_asset` míří
     * na 042 a odpisuje se. Pravidlo postavené na jednom z několika druhů by tiše
     * rozhodovalo i o tomhle — a chybu by bylo vidět až na dalších dokladech.
     */
    public function testRuleBasisIsWithheldWhenDocumentMixesExpenseKinds(): void
    {
        $vendorId = $this->client('Dodavatel s.r.o.');
        $id = $this->purchase('PF-2099-RB2', $vendorId, '1', 1000.00, 210.00, 21.00);
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE purchase_invoice_items SET expense_kind='service' WHERE purchase_invoice_id=?")
            ->execute([$id]);
        $pdo->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index, expense_kind)
             VALUES (?, "Notebook", 1, "ks", 0, ?, 21.00, 0, 0, 0, 1, "small_asset")'
        )->execute([$id, $this->vatRateId]);

        $res = $this->callWithArgs('postingPreview', ['source' => 'purchase-invoices', 'id' => $id], []);

        self::assertSame(200, $res['status']);
        self::assertNull($res['body']['rule_basis'], 'Smíšený doklad nesmí pravidlo nabídnout.');
    }

    /** Vydaná faktura pravidlo klasifikace VÝDAJE nemá kde vzít. */
    public function testRuleBasisIsNullForIssuedInvoice(): void
    {
        $clientId = $this->client('Odběratel s.r.o.');
        $id = $this->sale('FV-2099-RB3', $clientId, '1', 1000.00, 210.00, 21.00);

        $res = $this->callWithArgs('postingPreview', ['source' => 'invoices', 'id' => $id], []);

        self::assertSame(200, $res['status']);
        self::assertNull($res['body']['rule_basis']);
    }

    /**
     * @param array<string,mixed> $args
     * @param array<string,mixed> $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function callWithArgs(string $method, array $args, array $body): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/accounting')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withParsedBody($body);
        $resp = $this->journalAction->{$method}($req, new Psr7Response(), $args);
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);

        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    /** @return list<int> */
    private function normalizeIds(array $ids): array
    {
        $out = array_map(static fn ($v) => (int) $v, $ids);
        sort($out);
        return $out;
    }

    private function bookedAt(string $table, int $id): ?string
    {
        $stmt = $this->db->pdo()->prepare("SELECT booked_at FROM {$table} WHERE id = ?");
        $stmt->execute([$id]);
        $v = $stmt->fetchColumn();
        return $v === false || $v === null ? null : (string) $v;
    }

    /**
     * @param array<string,mixed> $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function call(string $method, array $body, string $role = 'accountant'): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/accounting')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role])
            ->withParsedBody($body);
        $resp = $this->journalAction->{$method}($req, new Psr7Response());
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    private function client(string $name): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "CZ12345678", "test@example.com", "cs", ?, 1, 1)'
        );
        $stmt->execute([$this->supplierId, $name, $this->czId, $this->currencyId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function sale(string $varsymbol, int $clientId, string $code, float $base, float $vat, float $rate): int
    {
        $with = $base + $vat;
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, 0, ?, ?, ?, "issued", ?, ?)'
        );
        $issue = self::YEAR . '-06-15';
        $stmt->execute([$this->supplierId, $varsymbol, $clientId, $issue, $issue, $issue, $this->currencyId, $base, $vat, $with, $code, $this->userId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $itemStmt = $this->db->pdo()->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, "Test položka", 1, "ks", ?, ?, ?, ?, ?, ?, 0)'
        );
        $itemStmt->execute([$id, $base, $this->vatRateId, $rate, $base, $vat, $with]);
        return $id;
    }

    /** Faktura bez položek → VatLedger nemá co sečíst → document_not_postable. */
    private function saleNoItems(string $varsymbol, int $clientId): int
    {
        $issue = self::YEAR . '-06-15';
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, 0, 0, 0, 0, "issued", "1", ?)'
        );
        $stmt->execute([$this->supplierId, $varsymbol, $clientId, $issue, $issue, $issue, $this->currencyId, $this->userId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function purchase(string $number, int $vendorId, string $code, float $base, float $vat, float $rate): int
    {
        $with = $base + $vat;
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                 vat_deduction, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, 0, "{}", ?, ?, ?, "received", ?, "full", ?)'
        );
        $issue = self::YEAR . '-06-15';
        $stmt->execute([$this->supplierId, $vendorId, $number, $issue, $issue, $issue, $issue, $this->currencyId, $base, $vat, $with, $code, $this->userId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $itemStmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, "Test položka", 1, "ks", ?, ?, ?, ?, ?, ?, 0)'
        );
        $itemStmt->execute([$id, $base, $this->vatRateId, $rate, $base, $vat, $with]);
        return $id;
    }
}
