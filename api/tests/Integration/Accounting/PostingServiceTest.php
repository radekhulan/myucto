<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Report\DphBookBuilder;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
use MyInvoice\Service\Report\VatLedgerService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Integrační testy PostingService (Epic F1 — correctness gate). Účtuje z reálně
 * naseedovaných faktur a ověřuje: vyváženost 311/6xx/343, idempotenci (2× post =
 * 1 zápis), odmítnutí uzavřeného období, tenant izolaci, storno (zrcadlo + vazba
 * reversed_by) a auto-zařazení do období dle data.
 *
 * Vše běží v jedné transakci, kterou tearDown rollbackne → DB zůstane netknutá.
 * PostingService/seeder detekují běžící transakci (inTransaction) a neotvírají
 * vlastní commit, takže celý test je atomický. Soft-skip bez cfg.php.
 */
#[Group('integration')]
final class PostingServiceTest extends TestCase
{
    private const YEAR = 2099;

    private Connection $db;
    private PostingService $posting;
    private VatLedgerService $vatLedger;
    private DphBookBuilder $dphBook;
    private DphPriznaniBuilder $dphPriznani;
    private KontrolniHlaseniBuilder $kontrolniHlaseni;
    private JournalEntryRepository $journal;
    private PurchaseInvoiceRepository $purchaseInvoices;
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
            $container = Bootstrap::buildApp()->getContainer();
            $this->db      = $container->get(Connection::class);
            $this->posting = $container->get(PostingService::class);
            $this->vatLedger = $container->get(VatLedgerService::class);
            $this->dphBook = $container->get(DphBookBuilder::class);
            $this->dphPriznani = $container->get(DphPriznaniBuilder::class);
            $this->kontrolniHlaseni = $container->get(KontrolniHlaseniBuilder::class);
            $this->journal = $container->get(JournalEntryRepository::class);
            $this->purchaseInvoices = $container->get(PurchaseInvoiceRepository::class);
            $this->periods = $container->get(AccountingPeriodRepository::class);
            $seeder        = $container->get(ChartOfAccountsSeeder::class);
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

        // Vše v transakci — rollback v tearDown.
        $pdo->beginTransaction();
        $this->inTx = true;

        // Osnova (idempotentní) — zajistí 311/602/343/518/321/648/548 pro firmu.
        $seeder->seedForSupplier($this->supplierId);

        // Otevřené účetní období roku 2099.
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

    public function testPostFromInvoiceProducesBalancedLines(): void
    {
        $client    = $this->client('Odběratel s.r.o.', true, false);
        $invoiceId = $this->sale('FV-2099-001', $client, '1', 1000.00, 210.00, 21.00);

        $lines   = $this->posting->buildFromInvoice($this->supplierId, $invoiceId);
        $entryId = $this->posting->postDocument(
            $this->supplierId,
            'invoice',
            $invoiceId,
            $lines,
            ['entry_date' => self::YEAR . '-06-15', 'document_no' => 'FV-2099-001', 'posted_by' => $this->userId, 'user_id' => $this->userId],
        );

        $entry = $this->journal->find($entryId, $this->supplierId);
        self::assertNotNull($entry);
        self::assertSame($this->periodId, $entry['period_id'], 'Auto-zařazení do období dle entry_date.');
        self::assertNotNull($entry['posted_at'], 'Doklad je zaúčtovaný (posted).');

        $byAccount = $this->linesByAccountCode($entry['lines']);
        self::assertEqualsWithDelta(1210.00, $byAccount['311']['debit'], 0.001, '311 MD = celková částka.');
        self::assertEqualsWithDelta(1000.00, $byAccount['602']['credit'], 0.001, '602 D = základ.');
        self::assertEqualsWithDelta(210.00, $byAccount['343.200']['credit'], 0.001, '343 D = DPH z VatLedgerService.');

        $this->assertBalanced($entry['lines']);
        foreach ($entry['lines'] as $l) {
            self::assertSame($this->supplierId, (int) $l['supplier_id'], 'Každý řádek nese supplier_id.');
        }
    }

    public function testPostFromPurchaseInvoiceProducesBalancedLines(): void
    {
        $vendor     = $this->client('Dodavatel a.s.', false, true);
        $purchaseId = $this->purchase('PF-2099-001', $vendor, '40', false, 2000.00, 420.00, 21.00);

        $lines   = $this->posting->buildFromPurchaseInvoice($this->supplierId, $purchaseId);
        $entryId = $this->posting->postDocument(
            $this->supplierId,
            'purchase_invoice',
            $purchaseId,
            $lines,
            ['entry_date' => self::YEAR . '-06-20', 'posted_by' => $this->userId],
        );

        $entry = $this->journal->find($entryId, $this->supplierId);
        self::assertNotNull($entry);
        $byAccount = $this->linesByAccountCode($entry['lines']);
        self::assertEqualsWithDelta(2000.00, $byAccount['518']['debit'], 0.001, '518 MD = základ nákladu.');
        self::assertEqualsWithDelta(420.00, $byAccount['343.100']['debit'], 0.001, '343 MD = odpočet DPH.');
        self::assertEqualsWithDelta(2420.00, $byAccount['321']['credit'], 0.001, '321 D = závazek.');
        $this->assertBalanced($entry['lines']);
    }

    public function testCashDocumentWithSameIdDoesNotChangePurchaseInvoicePosting(): void
    {
        $vendor = $this->client('Dodavatel s kolizním ID', false, true);
        $attempt = 0;
        do {
            $purchaseId = $this->purchase('PF-2099-CASH-ID-' . ++$attempt, $vendor, '40', false, 2000.00, 420.00, 21.00);
        } while ($this->cashDocumentIdExists($purchaseId));
        $this->insertCashVatDocumentWithId($purchaseId, 'out', 1000.00, 210.00);

        $lines = $this->posting->buildFromPurchaseInvoice($this->supplierId, $purchaseId);
        $entryId = $this->posting->postDocument(
            $this->supplierId,
            'purchase_invoice',
            $purchaseId,
            $lines,
            ['entry_date' => self::YEAR . '-06-20', 'posted_by' => $this->userId],
        );
        $entry = $this->journal->find($entryId, $this->supplierId);
        $byAccount = $this->linesByAccountCode($entry['lines']);

        self::assertEqualsWithDelta(2000.00, $byAccount['518']['debit'], 0.001);
        self::assertEqualsWithDelta(420.00, $byAccount['343.100']['debit'], 0.001);
        self::assertEqualsWithDelta(2420.00, $byAccount['321']['credit'], 0.001);
        $this->assertBalanced($entry['lines']);
    }

    public function testCashDocumentWithSameIdDoesNotChangeIssuedInvoicePosting(): void
    {
        $client = $this->client('Odběratel s kolizním ID', true, false);
        $attempt = 0;
        do {
            $invoiceId = $this->sale('FV-2099-CASH-ID-' . ++$attempt, $client, '1', 1000.00, 210.00, 21.00);
        } while ($this->cashDocumentIdExists($invoiceId));
        $this->insertCashVatDocumentWithId($invoiceId, 'in', 500.00, 105.00);

        $lines = $this->posting->buildFromInvoice($this->supplierId, $invoiceId);
        $entryId = $this->posting->postDocument(
            $this->supplierId,
            'invoice',
            $invoiceId,
            $lines,
            ['entry_date' => self::YEAR . '-06-15', 'posted_by' => $this->userId],
        );
        $entry = $this->journal->find($entryId, $this->supplierId);
        $byAccount = $this->linesByAccountCode($entry['lines']);

        self::assertEqualsWithDelta(1210.00, $byAccount['311']['debit'], 0.001);
        self::assertEqualsWithDelta(1000.00, $byAccount['602']['credit'], 0.001);
        self::assertEqualsWithDelta(210.00, $byAccount['343.200']['credit'], 0.001);
        $this->assertBalanced($entry['lines']);
    }

    public function testIdempotenceRepostSingleEntry(): void
    {
        $client    = $this->client('Odběratel s.r.o.', true, false);
        $invoiceId = $this->sale('FV-2099-002', $client, '1', 1000.00, 210.00, 21.00);

        $lines = $this->posting->buildFromInvoice($this->supplierId, $invoiceId);
        $first = $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, $lines, ['entry_date' => self::YEAR . '-06-15']);
        $second = $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, $lines, ['entry_date' => self::YEAR . '-06-15']);

        self::assertSame($first, $second, 'Re-post téhož dokladu vrací TÝŽ zápis (žádný duplikát).');

        $count = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM journal_entries WHERE supplier_id = {$this->supplierId}
              AND source_type = 'invoice' AND source_id = {$invoiceId}"
        )->fetchColumn();
        self::assertSame(1, $count, 'Existuje právě JEDEN zápis pro zdrojový doklad.');

        $lineCount = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM journal_entry_lines WHERE entry_id = {$first}"
        )->fetchColumn();
        self::assertSame(3, $lineCount, 'Řádky přepsány (recompute), ne zdvojeny.');

        $entry = $this->journal->find($first, $this->supplierId);
        self::assertSame(2, (int) $entry['row_version'], 'Přepis zvýší row_version.');
    }

    public function testDraftThenRepostPromotesToPosted(): void
    {
        $client    = $this->client('Odběratel s.r.o.', true, false);
        $invoiceId = $this->sale('FV-2099-003', $client, '1', 1000.00, 210.00, 21.00);
        $lines     = $this->posting->buildFromInvoice($this->supplierId, $invoiceId);

        $id = $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, $lines, ['entry_date' => self::YEAR . '-06-15', 'posted' => false]);
        $draft = $this->journal->find($id, $this->supplierId);
        self::assertNull($draft['posted_at'], 'První zápis je koncept.');

        $id2 = $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, $lines, ['entry_date' => self::YEAR . '-06-15', 'posted' => true, 'posted_by' => $this->userId]);
        self::assertSame($id, $id2);
        $posted = $this->journal->find($id, $this->supplierId);
        self::assertNotNull($posted['posted_at'], 'Re-post koncept → zaúčtováno.');
    }

    public function testMissingDescriptionGetsDefaultFromPurchaseInvoice(): void
    {
        $vendor     = $this->client('Vodafone Czech Republic a.s.', false, true);
        $purchaseId = $this->purchase('VF-2099-8473', $vendor, '40', false, 2000.00, 420.00, 21.00);

        $lines   = $this->posting->buildFromPurchaseInvoice($this->supplierId, $purchaseId);
        $entryId = $this->posting->postDocument(
            $this->supplierId,
            'purchase_invoice',
            $purchaseId,
            $lines,
            ['entry_date' => self::YEAR . '-06-20', 'posted_by' => $this->userId],
        );

        $entry = $this->journal->find($entryId, $this->supplierId);
        self::assertSame(
            'Přijatá faktura Vodafone Czech Republic a.s. VF-2099-8473',
            $entry['description'],
            'Bez explicitního popisu se dopočítá default z dodavatele + čísla dokladu.',
        );

        // Idempotence: re-post (přepis) vygeneruje TÝŽ deterministický popis.
        $again = $this->posting->postDocument(
            $this->supplierId,
            'purchase_invoice',
            $purchaseId,
            $lines,
            ['entry_date' => self::YEAR . '-06-20', 'posted_by' => $this->userId],
        );
        self::assertSame($entryId, $again);
        $entry = $this->journal->find($entryId, $this->supplierId);
        self::assertSame('Přijatá faktura Vodafone Czech Republic a.s. VF-2099-8473', $entry['description']);
    }

    public function testMissingDescriptionGetsDefaultFromIssuedInvoice(): void
    {
        $client    = $this->client('Odběratel s.r.o.', true, false);
        $invoiceId = $this->sale('FV-2099-042', $client, '1', 1000.00, 210.00, 21.00);

        $lines   = $this->posting->buildFromInvoice($this->supplierId, $invoiceId);
        $entryId = $this->posting->postDocument(
            $this->supplierId,
            'invoice',
            $invoiceId,
            $lines,
            ['entry_date' => self::YEAR . '-06-15', 'posted_by' => $this->userId],
        );

        $entry = $this->journal->find($entryId, $this->supplierId);
        self::assertSame(
            'Vydaná faktura Odběratel s.r.o. FV-2099-042',
            $entry['description'],
            'Bez explicitního popisu se dopočítá default z klienta + varsymbolu.',
        );
    }

    public function testExplicitDescriptionIsPreserved(): void
    {
        $vendor     = $this->client('Dodavatel a.s.', false, true);
        $purchaseId = $this->purchase('PF-2099-777', $vendor, '40', false, 100.00, 21.00, 21.00);

        $lines   = $this->posting->buildFromPurchaseInvoice($this->supplierId, $purchaseId);
        $entryId = $this->posting->postDocument(
            $this->supplierId,
            'purchase_invoice',
            $purchaseId,
            $lines,
            ['entry_date' => self::YEAR . '-06-20', 'description' => 'Můj vlastní popis', 'posted_by' => $this->userId],
        );

        $entry = $this->journal->find($entryId, $this->supplierId);
        self::assertSame('Můj vlastní popis', $entry['description'], 'Explicitní popis má přednost před defaultem.');
    }

    public function testClosedPeriodRefused(): void
    {
        $client    = $this->client('Odběratel s.r.o.', true, false);
        $invoiceId = $this->sale('FV-2099-004', $client, '1', 1000.00, 210.00, 21.00);
        $lines     = $this->posting->buildFromInvoice($this->supplierId, $invoiceId);

        $this->periods->setStatus($this->periodId, $this->supplierId, 'closed');

        $this->expectException(PostingException::class);
        $this->expectExceptionMessageMatches('/uzavřeného|closed|nelze účtovat/u');
        $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, $lines, ['entry_date' => self::YEAR . '-06-15']);
    }

    public function testTenantIsolationOnReadAndRows(): void
    {
        $client    = $this->client('Odběratel s.r.o.', true, false);
        $invoiceId = $this->sale('FV-2099-005', $client, '1', 1000.00, 210.00, 21.00);
        $lines     = $this->posting->buildFromInvoice($this->supplierId, $invoiceId);
        $entryId   = $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, $lines, ['entry_date' => self::YEAR . '-06-15']);

        $otherSupplier = $this->supplierId + 99999; // neexistující tenant
        self::assertNull($this->journal->find($entryId, $otherSupplier), 'Cross-tenant čtení hlavičky je odepřeno.');
        self::assertNull($this->journal->findBySource($otherSupplier, 'invoice', $invoiceId), 'Cross-tenant findBySource nic nevrátí.');
        self::assertSame([], $this->journal->linesForEntry($entryId, $otherSupplier), 'Cross-tenant řádky se nevrátí.');
    }

    public function testReverseCreatesBalancedMirrorAndLinks(): void
    {
        $client    = $this->client('Odběratel s.r.o.', true, false);
        $invoiceId = $this->sale('FV-2099-006', $client, '1', 1000.00, 210.00, 21.00);
        $lines     = $this->posting->buildFromInvoice($this->supplierId, $invoiceId);
        $entryId   = $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, $lines, ['entry_date' => self::YEAR . '-06-15']);

        $reversalId = $this->posting->reverse($this->supplierId, $entryId, ['entry_date' => self::YEAR . '-06-30', 'posted_by' => $this->userId]);
        self::assertNotSame($entryId, $reversalId);

        $original = $this->journal->find($entryId, $this->supplierId);
        self::assertSame($reversalId, (int) $original['reversed_by'], 'Original.reversed_by ukazuje na storno.');

        $reversal = $this->journal->find($reversalId, $this->supplierId);
        self::assertNull($reversal['source_id'], 'Storno nemá source_id (aby se neplet do idempotence).');
        $this->assertBalanced($reversal['lines']);

        // Zrcadlo: strany prohozené oproti originálu, stejné částky.
        $origByAcc = $this->linesByAccountCode($original['lines']);
        $revByAcc  = $this->linesByAccountCode($reversal['lines']);
        self::assertEqualsWithDelta($origByAcc['311']['debit'], $revByAcc['311']['credit'], 0.001, '311 se v stornu obrátí na D.');
        self::assertEqualsWithDelta($origByAcc['602']['credit'], $revByAcc['602']['debit'], 0.001, '602 se v stornu obrátí na MD.');

        // Dvojité storno je odmítnuto.
        $this->expectException(PostingException::class);
        $this->posting->reverse($this->supplierId, $entryId);
    }

    public function testReverseTruncatesLongDocumentNumberToColumnLimit(): void
    {
        $client = $this->client('Odběratel s.r.o.', true, false);
        $invoiceId = $this->sale('FV-2099-L33', $client, '1', 1000.00, 210.00, 21.00);
        $lines = $this->posting->buildFromInvoice($this->supplierId, $invoiceId);
        $entryId = $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, $lines, [
            'entry_date' => self::YEAR . '-06-15',
            'document_no' => str_repeat('X', 50),
        ]);

        $reversalId = $this->posting->reverse($this->supplierId, $entryId, [
            'entry_date' => self::YEAR . '-06-30',
        ]);
        $reversal = $this->journal->find($reversalId, $this->supplierId);
        self::assertSame(50, mb_strlen((string) $reversal['document_no']));
        self::assertStringStartsWith('STORNO ', (string) $reversal['document_no']);
    }

    public function testRepostAfterReversalCreatesNewEntryInsteadOfTouchingTheOriginal(): void
    {
        // Regrese (audit HIGH #2): re-post STORNOVANÉHO zápisu nesmí přepsat
        // original in-place — jinak zůstane viset protizápis na staré částky
        // → nevyrovnané knihy. Původně se proto odmítal úplně, jenže tím se
        // stornovaný doklad nedal zaúčtovat vůbec. Idempotence se teď ptá na
        // AKTIVNÍ zápis, takže vznikne NOVÝ; original i storno zůstanou.
        $client    = $this->client('Odběratel s.r.o.', true, false);
        $invoiceId = $this->sale('FV-2099-007', $client, '1', 1000.00, 210.00, 21.00);
        $lines     = $this->posting->buildFromInvoice($this->supplierId, $invoiceId);
        $entryId   = $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, $lines, ['entry_date' => self::YEAR . '-06-15']);
        $this->posting->reverse($this->supplierId, $entryId, ['entry_date' => self::YEAR . '-06-30']);

        $repostedId = $this->posting->postDocument(
            $this->supplierId,
            'invoice',
            $invoiceId,
            $lines,
            ['entry_date' => self::YEAR . '-06-15'],
        );

        self::assertNotSame($entryId, $repostedId, 'Přeúčtování musí založit nový zápis.');
        $original = $this->fetchEntry($entryId);
        self::assertNotNull($original['reversed_by'] ?? null, 'Původní zápis musí zůstat stornovaný.');
    }

    /** @return array<string,mixed> */
    private function fetchEntry(int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM journal_entries WHERE supplier_id = ? AND id = ?',
        );
        $stmt->execute([$this->supplierId, $entryId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row, 'Původní zápis musí v deníku zůstat.');

        return $row;
    }

    public function testRepositoryReplaceRejectsStaleReversedEntry(): void
    {
        $client = $this->client('Odběratel s.r.o.', true, false);
        $invoiceId = $this->sale('FV-2099-M35', $client, '1', 1000.00, 210.00, 21.00);
        $lines = $this->posting->buildFromInvoice($this->supplierId, $invoiceId);
        $entryId = $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, $lines, [
            'entry_date' => self::YEAR . '-06-15',
        ]);
        $stale = $this->journal->find($entryId, $this->supplierId);
        $this->posting->reverse($this->supplierId, $entryId, ['entry_date' => self::YEAR . '-06-30']);

        try {
            $this->journal->replace($entryId, [
                'supplier_id' => $this->supplierId,
                'period_id' => $stale['period_id'],
                'entry_date' => $stale['entry_date'],
                'document_date' => $stale['document_date'],
                'document_no' => $stale['document_no'],
                'description' => $stale['description'],
                'source_type' => $stale['source_type'],
                'source_id' => $stale['source_id'],
                'posted_at' => $stale['posted_at'],
                'posted_by' => $stale['posted_by'],
            ], $stale['lines']);
            self::fail('Stale replace nesmí přepsat mezitím stornovaný zápis.');
        } catch (PostingException $e) {
            self::assertSame('entry_reversed', $e->errorCode);
        }
    }

    public function testDraftAndCancelledInvoiceCannotBePosted(): void
    {
        $client = $this->client('Odběratel s.r.o.', true, false);
        $invoiceId = $this->sale('FV-2099-M36-A', $client, '1', 1000.00, 210.00, 21.00);
        foreach (['draft', 'cancelled'] as $status) {
            $this->db->pdo()->prepare('UPDATE invoices SET status = ? WHERE id = ?')->execute([$status, $invoiceId]);
            try {
                $this->posting->buildFromInvoice($this->supplierId, $invoiceId);
                self::fail('Fakturu ve stavu ' . $status . ' nelze zaúčtovat.');
            } catch (PostingException $e) {
                self::assertSame('document_not_postable', $e->errorCode);
            }
        }
    }

    public function testDraftAndCancelledPurchaseCannotBePosted(): void
    {
        $vendor = $this->client('Dodavatel a.s.', false, true);
        $purchaseId = $this->purchase('PF-2099-M36-B', $vendor, '40', false, 1000.00, 210.00, 21.00);
        foreach (['draft', 'cancelled'] as $status) {
            $this->db->pdo()->prepare('UPDATE purchase_invoices SET status = ? WHERE id = ?')->execute([$status, $purchaseId]);
            try {
                $this->posting->buildFromPurchaseInvoice($this->supplierId, $purchaseId);
                self::fail('Přijatou fakturu ve stavu ' . $status . ' nelze zaúčtovat.');
            } catch (PostingException $e) {
                self::assertSame('document_not_postable', $e->errorCode);
            }
        }
    }

    public function testRepostCannotRelocateEntryOutOfClosedPeriod(): void
    {
        // Regrese (audit HIGH #3): zápis v UZAVŘENÉM období nelze re-postem přepsat
        // ani přesunout do jiného otevřeného období (§35). Nové datum míří do
        // otevřeného období roku +1, ale PŮVODNÍ období zápisu je zavřené → odmítnout.
        $client    = $this->client('Odběratel s.r.o.', true, false);
        $invoiceId = $this->sale('FV-2099-008', $client, '1', 1000.00, 210.00, 21.00);
        $lines     = $this->posting->buildFromInvoice($this->supplierId, $invoiceId);
        $entryId   = $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, $lines, ['entry_date' => self::YEAR . '-06-15']);

        // Otevři období roku +1 a zavři původní období roku 2099.
        $nextYear = self::YEAR + 1;
        $this->periods->create($this->supplierId, $nextYear, $nextYear . '-01-01', $nextYear . '-12-31');
        $this->periods->setStatus($this->periodId, $this->supplierId, 'closed');

        $this->expectException(PostingException::class);
        $this->expectExceptionMessageMatches('/uzavřen|closed/u');
        $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, $lines, ['entry_date' => $nextYear . '-01-05']);
    }

    public function testDbUniqueGuardsAgainstDuplicateSourceEntry(): void
    {
        // Regrese (audit HIGH #1): DB-level unique (uq_je_supplier_source) tvrdě brání
        // dvěma zápisům pro týž (supplier, source_type, source_id) — pojistka proti
        // souběžnému dvojímu zaúčtování, i kdyby aplikační check-then-act prohrál race.
        $client    = $this->client('Odběratel s.r.o.', true, false);
        $invoiceId = $this->sale('FV-2099-009', $client, '1', 1000.00, 210.00, 21.00);
        $lines     = $this->posting->buildFromInvoice($this->supplierId, $invoiceId);
        $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, $lines, ['entry_date' => self::YEAR . '-06-15']);

        // Přímý druhý insert stejného zdroje (obchází findBySource) → duplicate key.
        $this->expectException(\PDOException::class);
        $this->journal->insert(
            [
                'supplier_id' => $this->supplierId,
                'period_id'   => $this->periodId,
                'entry_date'  => self::YEAR . '-06-15',
                'source_type' => 'invoice',
                'source_id'   => $invoiceId,
                'posted_at'   => date('Y-m-d H:i:s'),
            ],
            [
                ['account_id' => 1, 'side' => 'debit', 'amount' => 100.0],
                ['account_id' => 1, 'side' => 'credit', 'amount' => 100.0],
            ],
        );
    }

    public function testCzkInvoiceWithStrayExchangeRateStaysBalanced(): void
    {
        // Regrese (audit C-4): CZK doklad s omylem uloženým nenulovým kurzem se MUSÍ
        // účtovat kurzem 1.0 (jako VatLedgerService), jinak totalCzk neseděl na base/vat
        // a rozdíl by spadl do 648/548. Kurz 25.0 na CZK faktuře nesmí nic rozhodit.
        $client    = $this->client('Odběratel s.r.o.', true, false);
        $invoiceId = $this->sale('FV-2099-010', $client, '1', 1000.00, 210.00, 21.00);
        $this->db->pdo()->prepare('UPDATE invoices SET exchange_rate = 25.0 WHERE id = ?')->execute([$invoiceId]);

        $lines   = $this->posting->buildFromInvoice($this->supplierId, $invoiceId);
        $entryId = $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, $lines, ['entry_date' => self::YEAR . '-06-15']);
        $entry   = $this->journal->find($entryId, $this->supplierId);

        $byAccount = $this->linesByAccountCode($entry['lines']);
        self::assertEqualsWithDelta(1210.00, $byAccount['311']['debit'], 0.001, 'CZK doklad → kurz 1.0, 311 = 1210 (ne ×25).');
        self::assertArrayNotHasKey('648', $byAccount, 'Žádný kurzový reziduál na CZK dokladu.');
        self::assertArrayNotHasKey('548', $byAccount, 'Žádný kurzový reziduál na CZK dokladu.');
        $this->assertBalanced($entry['lines']);
        // CZK řádek nenese cizoměnovou stopu
        foreach ($entry['lines'] as $l) {
            self::assertNull($l['currency_code'], 'CZK doklad → řádky bez cizí měny.');
        }
    }

    public function testForeignInvoiceStoresCurrencyOnReceivable(): void
    {
        // Regrese (audit B-a, §4/12): u cizoměnové faktury nese saldokonto 311 měnu,
        // kurz a částku v cizí měně — podklad pro kurzové přecenění (§24/6).
        $eurId = (int) ($this->db->pdo()->query("SELECT id FROM currencies WHERE code = 'EUR' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        if ($eurId === 0) {
            self::markTestSkipped('Měna EUR není v číselníku.');
        }
        $client = $this->client('EU Odběratel', true, false);
        // 100 EUR základ + 21 EUR DPH, kurz 25 → 2500/525 CZK
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                currency_id, exchange_rate, reverse_charge, total_without_vat, total_vat, total_with_vat,
                status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, 25.0, 0, 100.00, 21.00, 121.00, "issued", "1", ?)'
        );
        $issue = self::YEAR . '-06-15';
        $stmt->execute([$this->supplierId, 'FV-2099-011', $client, $issue, $issue, $issue, $eurId, $this->userId]);
        $invoiceId = (int) $this->db->pdo()->lastInsertId();
        $this->insertItem('invoice_items', 'invoice_id', $invoiceId, 100.00, 21.00, 21.00);

        $lines   = $this->posting->buildFromInvoice($this->supplierId, $invoiceId);
        $entryId = $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, $lines, ['entry_date' => self::YEAR . '-06-15']);
        $entry   = $this->journal->find($entryId, $this->supplierId);
        $this->assertBalanced($entry['lines']);

        $receivable = null;
        foreach ($entry['lines'] as $l) {
            if ($l['currency_code'] !== null) {
                $receivable = $l;
            }
        }
        self::assertNotNull($receivable, 'Saldokontní řádek nese cizoměnovou stopu.');
        self::assertSame('EUR', $receivable['currency_code']);
        self::assertEqualsWithDelta(25.0, (float) $receivable['fx_rate'], 0.0001);
        self::assertEqualsWithDelta(121.00, (float) $receivable['amount_foreign'], 0.001, 'amount_foreign = celková částka v EUR.');
        self::assertEqualsWithDelta(3025.00, (float) $receivable['amount'], 0.001, '311 v CZK = 121 × 25.');
    }

    public function testReverseDraftRefused(): void
    {
        // Regrese (audit C-5): storno se dělá jen u ZAÚČTOVANÉHO zápisu, ne u konceptu.
        $client    = $this->client('Odběratel s.r.o.', true, false);
        $invoiceId = $this->sale('FV-2099-012', $client, '1', 1000.00, 210.00, 21.00);
        $lines     = $this->posting->buildFromInvoice($this->supplierId, $invoiceId);
        $draftId   = $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, $lines, ['entry_date' => self::YEAR . '-06-15', 'posted' => false]);

        $this->expectException(PostingException::class);
        $this->expectExceptionMessageMatches('/koncept/u');
        $this->posting->reverse($this->supplierId, $draftId, ['entry_date' => self::YEAR . '-06-20']);
    }

    public function testSetReversedByIsAtomicGuardAgainstDoubleReverse(): void
    {
        // Regrese (audit F-1): storno má source_id NULL (unique ho nechrání), proto
        // dvojí storno hlídá podmíněný UPDATE reversed_by IS NULL. Druhé navázání
        // stornu na týž (už stornovaný) zápis musí vrátit false → volající rollbackne.
        $client    = $this->client('Odběratel s.r.o.', true, false);
        $invoiceId = $this->sale('FV-2099-013', $client, '1', 1000.00, 210.00, 21.00);
        $lines     = $this->posting->buildFromInvoice($this->supplierId, $invoiceId);
        $entryId   = $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, $lines, ['entry_date' => self::YEAR . '-06-15']);
        $reversalId = $this->posting->reverse($this->supplierId, $entryId, ['entry_date' => self::YEAR . '-06-30']);

        // reversed_by je už nastaven → druhý pokus (simulace souběžného storna) selže.
        self::assertFalse(
            $this->journal->setReversedBy($entryId, $this->supplierId, $reversalId),
            'Druhé navázání stornu na týž zápis musí vrátit false (atomická pojistka).',
        );
    }

    public function testUnknownAccountThrows(): void
    {
        $this->expectException(PostingException::class);
        $this->expectExceptionMessage('999');
        $this->posting->postDocument(
            $this->supplierId,
            'manual',
            null,
            [
                ['account_code' => '999', 'side' => 'debit', 'amount' => 100.00],
                ['account_code' => '211', 'side' => 'credit', 'amount' => 100.00],
            ],
            ['entry_date' => self::YEAR . '-06-15'],
        );
    }

    public function testForeignInvoiceWithoutExchangeRateThrows(): void
    {
        // Regrese (audit H1): cizoměnový doklad BEZ kurzu se nesmí tiše zaúčtovat kurzem
        // 1.0 (nominál EUR jako CZK). fxRate() i VatLedgerService::normalize() vyhodí
        // PostingException('missing_exchange_rate', 422) — bez ocenění v Kč nelze účtovat.
        $eurId = (int) ($this->db->pdo()->query("SELECT id FROM currencies WHERE code = 'EUR' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        if ($eurId === 0) {
            self::markTestSkipped('Měna EUR není v číselníku.');
        }
        $client = $this->client('EU Odběratel bez kurzu', true, false);
        $issue  = self::YEAR . '-06-15';
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                currency_id, exchange_rate, reverse_charge, total_without_vat, total_vat, total_with_vat,
                status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, NULL, 0, 100.00, 21.00, 121.00, "issued", "1", ?)'
        );
        $stmt->execute([$this->supplierId, 'FV-2099-NORATE', $client, $issue, $issue, $issue, $eurId, $this->userId]);
        $invoiceId = (int) $this->db->pdo()->lastInsertId();
        $this->insertItem('invoice_items', 'invoice_id', $invoiceId, 100.00, 21.00, 21.00);

        try {
            $this->posting->buildFromInvoice($this->supplierId, $invoiceId);
            self::fail('Cizoměnový doklad bez kurzu musí vyhodit PostingException.');
        } catch (PostingException $e) {
            self::assertSame('missing_exchange_rate', $e->errorCode, 'Strojový kód pro 422.');
            self::assertSame(422, $e->httpStatus);
        }
    }

    public function testPurchaseInvoiceRoundingResidualPostsExpenseSide(): void
    {
        // Regrese (audit H2): u PŘIJATÉ faktury je celková částka na straně D (321),
        // takže haléřové zaokrouhlení musí padnout ZRCADLOVĚ oproti vydané faktuře.
        // Kladné reziduum (totalCzk > net+vat) = zaokrouhlovací NÁKLAD → 548 MD. Před
        // opravou appendRounding přidal 648 D → zdvojená nevyváženost → UnbalancedEntry.
        $vendor     = $this->client('Dodavatel a.s.', false, true);
        $purchaseId = $this->purchase('PF-2099-R1', $vendor, '40', false, 2000.00, 420.00, 21.00);
        // Reziduum: hlavička o haléř výš než suma položek (net+vat=2420 z VatLedgerService).
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET total_with_vat = 2420.01 WHERE id = ?')->execute([$purchaseId]);

        $lines   = $this->posting->buildFromPurchaseInvoice($this->supplierId, $purchaseId);
        $entryId = $this->posting->postDocument($this->supplierId, 'purchase_invoice', $purchaseId, $lines, ['entry_date' => self::YEAR . '-06-20']);
        $entry   = $this->journal->find($entryId, $this->supplierId);

        $byAccount = $this->linesByAccountCode($entry['lines']);
        self::assertArrayHasKey('548', $byAccount, 'Kladné reziduum přijaté faktury → 548 (náklad).');
        self::assertEqualsWithDelta(0.01, $byAccount['548']['debit'], 0.001, '548 MD = haléřové dorovnání.');
        self::assertArrayNotHasKey('648', $byAccount, 'Reziduum nesmí jít na 648 (špatná strana).');
        self::assertEqualsWithDelta(2420.01, $byAccount['321']['credit'], 0.001, '321 D = závazek vč. zaokrouhlení.');
        $this->assertBalanced($entry['lines']);
    }

    public function testPurchaseInvoiceNegativeRoundingResidualPostsIncomeSide(): void
    {
        // Regrese (audit H2): záporné reziduum (totalCzk < net+vat) u přijaté faktury =
        // zaokrouhlovací VÝNOS → 648 D.
        $vendor     = $this->client('Dodavatel a.s.', false, true);
        $purchaseId = $this->purchase('PF-2099-R2', $vendor, '40', false, 2000.00, 420.00, 21.00);
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET total_with_vat = 2419.99 WHERE id = ?')->execute([$purchaseId]);

        $lines   = $this->posting->buildFromPurchaseInvoice($this->supplierId, $purchaseId);
        $entryId = $this->posting->postDocument($this->supplierId, 'purchase_invoice', $purchaseId, $lines, ['entry_date' => self::YEAR . '-06-20']);
        $entry   = $this->journal->find($entryId, $this->supplierId);

        $byAccount = $this->linesByAccountCode($entry['lines']);
        self::assertArrayHasKey('648', $byAccount, 'Záporné reziduum přijaté faktury → 648 (výnos).');
        self::assertEqualsWithDelta(0.01, $byAccount['648']['credit'], 0.001);
        self::assertArrayNotHasKey('548', $byAccount);
        self::assertEqualsWithDelta(2419.99, $byAccount['321']['credit'], 0.001);
        $this->assertBalanced($entry['lines']);
    }

    public function testForeignPurchaseInvoiceRoundingResidualBalances(): void
    {
        // Regrese (audit H2, reálný spouštěč): cizoměnová přijatá faktura, kde totalCzk
        // (round hlavička × kurz) ≠ Σ round(řádek × kurz) o haléř. Musí se vyvážit se
        // správnou stranou (548 MD), ne spadnout na UnbalancedEntryException.
        //   base 10.03 + vat 2.14 EUR, kurz 1.1 → net=round(11.033)=11.03,
        //   vat=round(2.354)=2.35, totalCzk=round(12.17×1.1)=round(13.387)=13.39
        //   → reziduum +0.01 → 548 MD 0.01.
        $eurId = (int) ($this->db->pdo()->query("SELECT id FROM currencies WHERE code = 'EUR' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        if ($eurId === 0) {
            self::markTestSkipped('Měna EUR není v číselníku.');
        }
        $vendor = $this->client('EU dodavatel EUR', false, true);
        $issue  = self::YEAR . '-06-15';
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, exchange_rate, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                 vat_deduction, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, 1.1, 0, "{}", 10.03, 2.14, 12.17, "received", "40", "full", ?)'
        );
        $stmt->execute([$this->supplierId, $vendor, 'PF-2099-FX', $issue, $issue, $issue, $issue, $eurId, $this->userId]);
        $purchaseId = (int) $this->db->pdo()->lastInsertId();
        $this->insertItem('purchase_invoice_items', 'purchase_invoice_id', $purchaseId, 10.03, 2.14, 21.00);

        $lines   = $this->posting->buildFromPurchaseInvoice($this->supplierId, $purchaseId);
        $entryId = $this->posting->postDocument($this->supplierId, 'purchase_invoice', $purchaseId, $lines, ['entry_date' => self::YEAR . '-06-20']);
        $entry   = $this->journal->find($entryId, $this->supplierId);

        $byAccount = $this->linesByAccountCode($entry['lines']);
        self::assertArrayHasKey('548', $byAccount, 'FX reziduum přijaté faktury → 548 MD.');
        self::assertEqualsWithDelta(0.01, $byAccount['548']['debit'], 0.001);
        self::assertArrayNotHasKey('648', $byAccount, 'Nesmí spadnout na 648 (špatná strana).');
        self::assertEqualsWithDelta(13.39, $byAccount['321']['credit'], 0.001, '321 D = totalCzk.');
        $this->assertBalanced($entry['lines']);
    }

    public function testInvoiceRoundingResidualStaysOnIncomeSide(): void
    {
        // Guard (audit H2): sale geometrie (311 MD total) se opravou pro purchase NESMÍ
        // změnit — kladné reziduum vydané faktury dál míří na 648 D.
        $client    = $this->client('Odběratel s.r.o.', true, false);
        $invoiceId = $this->sale('FV-2099-R1', $client, '1', 1000.00, 210.00, 21.00);
        $this->db->pdo()->prepare('UPDATE invoices SET total_with_vat = 1210.01 WHERE id = ?')->execute([$invoiceId]);

        $lines   = $this->posting->buildFromInvoice($this->supplierId, $invoiceId);
        $entryId = $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, $lines, ['entry_date' => self::YEAR . '-06-15']);
        $entry   = $this->journal->find($entryId, $this->supplierId);

        $byAccount = $this->linesByAccountCode($entry['lines']);
        self::assertArrayHasKey('648', $byAccount, 'Kladné reziduum vydané faktury → 648 (výnos).');
        self::assertEqualsWithDelta(0.01, $byAccount['648']['credit'], 0.001);
        self::assertArrayNotHasKey('548', $byAccount);
        self::assertEqualsWithDelta(1210.01, $byAccount['311']['debit'], 0.001);
        $this->assertBalanced($entry['lines']);
    }

    public function testAssetSaleInvoiceRevenueRuleKeyPostsTo641(): void
    {
        // Prodej dlouhodobého majetku: hlavička faktury nese revenue_rule_key
        // 'asset.sale.revenue' → výnos jde na 641 (tržby z prodeje DHM), ne na default 602.
        // 311 MD (pohledávka) i 343 D (DPH) zůstávají; podvojnost musí sedět (§ carve-out ZC
        // řeší AssetService::dispose, ne faktura).
        $client    = $this->client('Kupující majetku s.r.o.', true, false);
        $invoiceId = $this->sale('FV-2099-641', $client, '1', 10000.00, 2100.00, 21.00);
        $this->db->pdo()->prepare("UPDATE invoices SET revenue_rule_key = 'asset.sale.revenue' WHERE id = ?")->execute([$invoiceId]);

        $lines   = $this->posting->buildFromInvoice($this->supplierId, $invoiceId);
        $entryId = $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, $lines, ['entry_date' => self::YEAR . '-06-15']);
        $entry   = $this->journal->find($entryId, $this->supplierId);

        $byAccount = $this->linesByAccountCode($entry['lines']);
        self::assertEqualsWithDelta(12100.00, $byAccount['311']['debit'], 0.001, '311 MD = celková částka.');
        self::assertEqualsWithDelta(10000.00, $byAccount['641']['credit'], 0.001, 'Výnos jde na 641, ne 602.');
        self::assertArrayNotHasKey('602', $byAccount, 'Prodej majetku nesmí spadnout na 602.');
        self::assertEqualsWithDelta(2100.00, $byAccount['343.200']['credit'], 0.001, '343 D = DPH.');
        $this->assertBalanced($entry['lines']);
    }

    public function testRevenueSplitsPerItemBetweenAssetKindsAndServices(): void
    {
        // 1177 — jádro rozpadu: jedna faktura prodává dlouhodobý majetek (641), drobný
        // majetek (642) a k tomu fakturuje službu (602). Před rozpadem šel celý základ na
        // jediný účet z hlavičky, takže smíšená faktura buď zdanila službu jako prodej
        // majetku, nebo naopak. 343 i 311 se rozpadem hnout NESMÍ — DPH podání na tom stojí.
        $client    = $this->client('Kupující smíšené faktury', true, false);
        $invoiceId = $this->sale('FV-2099-MIX', $client, '1', 2000.00, 420.00, 21.00);
        $this->db->pdo()->prepare(
            'UPDATE invoices SET total_without_vat = 15000.00, total_vat = 3150.00, total_with_vat = 18150.00 WHERE id = ?'
        )->execute([$invoiceId]);

        $small = $this->smallAssetCard('Notebook z evidence', 3000.00);
        $long  = $this->assetCard('Osobní automobil', 10000.00);
        $this->addLinkedItem($invoiceId, 3000.00, 630.00, 21.00, 1, $small, null);
        $this->addLinkedItem($invoiceId, 10000.00, 2100.00, 21.00, 2, null, $long);

        $lines   = $this->posting->buildFromInvoice($this->supplierId, $invoiceId);
        $entryId = $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, $lines, ['entry_date' => self::YEAR . '-06-15']);
        $entry   = $this->journal->find($entryId, $this->supplierId);

        $byAccount = $this->linesByAccountCode($entry['lines']);
        self::assertEqualsWithDelta(2000.00, $byAccount['602']['credit'], 0.001, 'Služba zůstává na 602.');
        self::assertEqualsWithDelta(3000.00, $byAccount['642']['credit'], 0.001, 'Drobný majetek na 642.');
        self::assertEqualsWithDelta(10000.00, $byAccount['641']['credit'], 0.001, 'Dlouhodobý majetek na 641.');
        self::assertEqualsWithDelta(18150.00, $byAccount['311']['debit'], 0.001, 'Pohledávka se rozpadem nehnula.');
        self::assertEqualsWithDelta(3150.00, $byAccount['343.200']['credit'], 0.001, 'DPH se rozpadem nehnula.');
        $this->assertBalanced($entry['lines']);
    }

    public function testSmallAssetSaleAlonePostsTo642WithoutHeaderRuleKey(): void
    {
        // Klasifikace řádku musí přebít default z hlavičky i tehdy, když je na dokladu
        // jediná — jinak by samostatný prodej drobného majetku spadl na 602 (rozpad by
        // vyrobil „jednu nohu" a tiše použil výchozí účet).
        $client    = $this->client('Kupující drobného majetku', true, false);
        $invoiceId = $this->sale('FV-2099-642', $client, '1', 5000.00, 1050.00, 21.00);
        $card      = $this->smallAssetCard('Prodaná tiskárna', 5000.00);
        $this->db->pdo()->prepare('UPDATE invoice_items SET small_asset_id = ? WHERE invoice_id = ?')
            ->execute([$card, $invoiceId]);

        $lines   = $this->posting->buildFromInvoice($this->supplierId, $invoiceId);
        $entryId = $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, $lines, ['entry_date' => self::YEAR . '-06-15']);
        $entry   = $this->journal->find($entryId, $this->supplierId);

        $byAccount = $this->linesByAccountCode($entry['lines']);
        self::assertEqualsWithDelta(5000.00, $byAccount['642']['credit'], 0.001);
        self::assertArrayNotHasKey('602', $byAccount, 'Drobný majetek nesmí spadnout na 602.');
        self::assertArrayNotHasKey('641', $byAccount, 'Drobný majetek nikdy nebyl na 02x → ne 641.');
        $this->assertBalanced($entry['lines']);
    }

    public function testAssetSaleCreditNoteReversesSplitSides(): void
    {
        // Dobropis prodeje majetku: rozpad musí obrátit strany stejně jako jedna noha
        // (641/642 MD = storno výnosu), ne poslat abs() částky na credit.
        $client = $this->client('Odběratel dobropisu majetku', true, false);
        $issue  = self::YEAR . '-06-15';
        $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, "credit_note", ?, ?, ?, ?, ?, 0, -8000.00, -1680.00, -9680.00, "issued", "1", ?)'
        )->execute([$this->supplierId, 'DOB-2099-MAJ', $client, $issue, $issue, $issue, $this->currencyId, $this->userId]);
        $invoiceId = (int) $this->db->pdo()->lastInsertId();

        $long = $this->assetCard('Vrácený stroj', 8000.00);
        $this->addLinkedItem($invoiceId, -8000.00, -1680.00, 21.00, 0, null, $long);

        $lines   = $this->posting->buildFromInvoice($this->supplierId, $invoiceId);
        $entryId = $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, $lines, ['entry_date' => $issue]);
        $entry   = $this->journal->find($entryId, $this->supplierId);

        $byAccount = $this->linesByAccountCode($entry['lines']);
        self::assertEqualsWithDelta(8000.00, $byAccount['641']['debit'], 0.001, '641 MD = storno výnosu z prodeje.');
        self::assertSame(0.0, $byAccount['641']['credit'], 'Dobropis nesmí výnos znovu naúčtovat na D.');
        self::assertEqualsWithDelta(9680.00, $byAccount['311']['credit'], 0.001);
        $this->assertBalanced($entry['lines']);
    }

    public function testExplicitRuleKeyOverridesItemSplit(): void
    {
        // Ruční zaúčtování s vybranou předkontací je adresný pokyn pro CELÝ doklad —
        // rozpad po položkách se pak nedělá (zrcadlo debitOverride na přijaté straně).
        $client    = $this->client('Kupující s ruční kontací', true, false);
        $invoiceId = $this->sale('FV-2099-OVR', $client, '1', 4000.00, 840.00, 21.00);
        $card      = $this->smallAssetCard('Karta k přebití', 4000.00);
        $this->db->pdo()->prepare('UPDATE invoice_items SET small_asset_id = ? WHERE invoice_id = ?')
            ->execute([$card, $invoiceId]);

        $lines   = $this->posting->buildFromInvoice($this->supplierId, $invoiceId, ['rule_key' => 'invoice.goods.issued']);
        $entryId = $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, $lines, ['entry_date' => self::YEAR . '-06-15']);
        $entry   = $this->journal->find($entryId, $this->supplierId);

        $byAccount = $this->linesByAccountCode($entry['lines']);
        self::assertEqualsWithDelta(4000.00, $byAccount['604']['credit'], 0.001, 'Vyhrává ručně zvolená předkontace.');
        self::assertArrayNotHasKey('642', $byAccount);
        $this->assertBalanced($entry['lines']);
    }

    public function testInvoiceWithoutRevenueRuleKeyStillPostsTo602(): void
    {
        // Guard: default chování (revenue_rule_key NULL) se nesmí hnout — výnos dál 602.
        $client    = $this->client('Běžný odběratel', true, false);
        $invoiceId = $this->sale('FV-2099-602D', $client, '1', 1000.00, 210.00, 21.00);

        $lines   = $this->posting->buildFromInvoice($this->supplierId, $invoiceId);
        $entryId = $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, $lines, ['entry_date' => self::YEAR . '-06-15']);
        $entry   = $this->journal->find($entryId, $this->supplierId);

        $byAccount = $this->linesByAccountCode($entry['lines']);
        self::assertEqualsWithDelta(1000.00, $byAccount['602']['credit'], 0.001, 'Bez příznaku výnos dál 602.');
        self::assertArrayNotHasKey('641', $byAccount);
        $this->assertBalanced($entry['lines']);
    }

    public function testPurchaseInvoiceVatDeductionNoneIncludesFullAmountInExpense(): void
    {
        // Regrese (audit B1): vat_deduction='none' dřív bral base/vat výhradně z
        // VatLedgerService, který takový doklad z DPH evidence úplně vyřazuje ([0,0] →
        // 'document_not_postable'). Teď se bere přímo z položek; celá částka vč. DPH
        // je náklad, žádný řádek 343.
        $vendor = $this->client('Dodavatel bez odpočtu', false, true);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                 vat_deduction, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, 0, "{}", 1000.00, 210.00, 1210.00, "received", "40", "none", ?)'
        );
        $issue = self::YEAR . '-06-15';
        $stmt->execute([$this->supplierId, $vendor, 'PF-2099-NONE', $issue, $issue, $issue, $issue, $this->currencyId, $this->userId]);
        $purchaseId = (int) $this->db->pdo()->lastInsertId();
        $this->insertItem('purchase_invoice_items', 'purchase_invoice_id', $purchaseId, 1000.00, 210.00, 21.00);

        $lines   = $this->posting->buildFromPurchaseInvoice($this->supplierId, $purchaseId);
        $entryId = $this->posting->postDocument($this->supplierId, 'purchase_invoice', $purchaseId, $lines, ['entry_date' => self::YEAR . '-06-20']);
        $entry   = $this->journal->find($entryId, $this->supplierId);

        $byAccount = $this->linesByAccountCode($entry['lines']);
        self::assertEqualsWithDelta(1210.00, $byAccount['518']['debit'], 0.001, '518 MD = základ + DPH (bez nároku na odpočet).');
        self::assertArrayNotHasKey('343.100', $byAccount, 'Bez nároku na odpočet žádný řádek 343.');
        self::assertEqualsWithDelta(1210.00, $byAccount['321']['credit'], 0.001);
        $this->assertBalanced($entry['lines']);
    }

    public function testPurchaseInvoiceVatDeductionProportionalSplitsNonDeductibleVatIntoExpense(): void
    {
        // Regrese (audit B1): §75 poměrný odpočet dřív bral zkrácené base+vat z
        // VatLedgerService (ta krátí OBĚ hodnoty pro účely DPH evidence) — pro účetní
        // zápis to není správně. Teď: základ PLNÝ, 343 jen poměrná (uplatněná) část
        // daně, neuplatněná část se přičte k nákladu.
        $vendor = $this->client('Dodavatel poměrný odpočet', false, true);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                 vat_deduction, vat_deduction_percent, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, 0, "{}", 1000.00, 210.00, 1210.00, "received", "40", "proportional", 50.00, ?)'
        );
        $issue = self::YEAR . '-06-15';
        $stmt->execute([$this->supplierId, $vendor, 'PF-2099-PROP', $issue, $issue, $issue, $issue, $this->currencyId, $this->userId]);
        $purchaseId = (int) $this->db->pdo()->lastInsertId();
        $this->insertItem('purchase_invoice_items', 'purchase_invoice_id', $purchaseId, 1000.00, 210.00, 21.00);

        $lines   = $this->posting->buildFromPurchaseInvoice($this->supplierId, $purchaseId);
        $entryId = $this->posting->postDocument($this->supplierId, 'purchase_invoice', $purchaseId, $lines, ['entry_date' => self::YEAR . '-06-20']);
        $entry   = $this->journal->find($entryId, $this->supplierId);

        $byAccount = $this->linesByAccountCode($entry['lines']);
        self::assertEqualsWithDelta(105.00, $byAccount['343.100']['debit'], 0.001, '343 MD = 50 % z 210 Kč DPH (uplatněná část).');
        self::assertEqualsWithDelta(1105.00, $byAccount['518']['debit'], 0.001, '518 MD = 1000 základ + 105 neuplatněná DPH.');
        self::assertEqualsWithDelta(1210.00, $byAccount['321']['credit'], 0.001);
        $this->assertBalanced($entry['lines']);
    }

    public function testPurchaseInvoiceVatAllocationsSplitBusinessAndPersonalUse(): void
    {
        $bookBefore = $this->dphBook->build($this->supplierId, self::YEAR, 6);
        $dphBefore = $this->dphPriznani->build($this->supplierId, self::YEAR, 6, 'monthly');
        $khBefore = new \SimpleXMLElement($this->kontrolniHlaseni->build($this->supplierId, self::YEAR, 6)['xml']);
        $khBaseBefore = (float) ($khBefore->DPHKH1->VetaB3['zakl_dane1'] ?? 0);
        $khVatBefore = (float) ($khBefore->DPHKH1->VetaB3['dan1'] ?? 0);

        $vendor = $this->client('Dodavatel smíšeného dokladu', false, true);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                 vat_deduction, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, 0, "{}", 1217.34, 255.65, 1472.99, "received", "40", "full", ?)'
        );
        $issue = self::YEAR . '-06-15';
        $stmt->execute([$this->supplierId, $vendor, 'PF-2099-ALLOC', $issue, $issue, $issue, $issue, $this->currencyId, $this->userId]);
        $purchaseId = (int) $this->db->pdo()->lastInsertId();
        $this->insertItem('purchase_invoice_items', 'purchase_invoice_id', $purchaseId, 1217.34, 255.65, 21.00);

        $this->purchaseInvoices->replaceVatAllocations($purchaseId, $this->supplierId, [
            [
                'description' => 'Mobilní služby', 'usage_type' => 'business', 'vat_rate' => 21,
                'base_amount' => 984.12, 'vat_amount' => 206.67, 'total_amount' => 1190.79,
                'vat_deduction' => 'full', 'vat_deduction_percent' => 100,
                'tax_treatment' => 'deductible', 'account_code' => '518',
                'vat_classification_code' => '40', 'order_index' => 0,
            ],
            [
                'description' => 'Vodafone TV', 'usage_type' => 'personal', 'vat_rate' => 21,
                'base_amount' => 233.22, 'vat_amount' => 48.98, 'total_amount' => 282.20,
                'vat_deduction' => 'none', 'vat_deduction_percent' => 0,
                'tax_treatment' => 'not_expense', 'account_code' => '355',
                'vat_classification_code' => '40', 'order_index' => 1,
            ],
        ]);
        self::assertCount(2, $this->purchaseInvoices->find($purchaseId, $this->supplierId)['vat_allocations']);

        $ledgerRows = array_values(array_filter(
            $this->vatLedger->rows($this->supplierId, self::YEAR . '-06-01', self::YEAR . '-06-30'),
            static fn (array $row): bool => $row['source'] === 'purchase' && $row['invoice_id'] === $purchaseId,
        ));
        self::assertCount(1, $ledgerRows, 'Osobní alokace bez nároku nesmí vstoupit do evidence DPH.');
        self::assertEqualsWithDelta(984.12, $ledgerRows[0]['base_czk'], 0.001);
        self::assertEqualsWithDelta(206.67, $ledgerRows[0]['vat_czk'], 0.001);
        self::assertFalse($ledgerRows[0]['vat_deduction_partial'], 'Přesně oddělená osobní položka není poměr §75.');

        $bookAfter = $this->dphBook->build($this->supplierId, self::YEAR, 6);
        self::assertEqualsWithDelta(
            984.12,
            $bookAfter['totals']['received']['base'] - $bookBefore['totals']['received']['base'],
            0.001,
            'Kniha DPH musí obsahovat jen podnikatelský základ.',
        );
        self::assertEqualsWithDelta(
            206.67,
            $bookAfter['totals']['received']['vat'] - $bookBefore['totals']['received']['vat'],
            0.001,
            'Kniha DPH musí obsahovat jen uplatněný odpočet.',
        );

        $dphAfter = $this->dphPriznani->build($this->supplierId, self::YEAR, 6, 'monthly');
        self::assertEqualsWithDelta(
            984.12,
            ($dphAfter['summary']['lines']['40']['base'] ?? 0) - ($dphBefore['summary']['lines']['40']['base'] ?? 0),
            0.001,
            'DPHDP3 ř. 40 musí obsahovat jen podnikatelský základ.',
        );
        self::assertEqualsWithDelta(
            206.67,
            ($dphAfter['summary']['lines']['40']['vat'] ?? 0) - ($dphBefore['summary']['lines']['40']['vat'] ?? 0),
            0.001,
            'DPHDP3 ř. 40 musí obsahovat jen uplatněný odpočet.',
        );

        $khAfter = new \SimpleXMLElement($this->kontrolniHlaseni->build($this->supplierId, self::YEAR, 6)['xml']);
        self::assertEqualsWithDelta(984.12, (float) $khAfter->DPHKH1->VetaB3['zakl_dane1'] - $khBaseBefore, 0.001);
        self::assertEqualsWithDelta(206.67, (float) $khAfter->DPHKH1->VetaB3['dan1'] - $khVatBefore, 0.001);

        $lines = $this->posting->buildFromPurchaseInvoice($this->supplierId, $purchaseId);
        $entryId = $this->posting->postDocument($this->supplierId, 'purchase_invoice', $purchaseId, $lines, ['entry_date' => $issue]);
        $entry = $this->journal->find($entryId, $this->supplierId);
        $byAccount = $this->linesByAccountCode($entry['lines']);
        self::assertEqualsWithDelta(984.12, $byAccount['518']['debit'], 0.001);
        self::assertEqualsWithDelta(206.67, $byAccount['343.100']['debit'], 0.001);
        self::assertEqualsWithDelta(282.20, $byAccount['355']['debit'], 0.001);
        self::assertEqualsWithDelta(1472.99, $byAccount['321']['credit'], 0.001);
        $this->assertBalanced($entry['lines']);
    }

    public function testPurchaseInvoiceRcWithPartialDeductionThrowsInsteadOfSilentMiscalculation(): void
    {
        // Guard (audit B1): RC (samovyměření) kombinované s omezeným nárokem na odpočet
        // není podporované — musí vyhodit srozumitelnou PostingException, ne spočítat
        // něco tiše špatně.
        $vendor = $this->client('Dodavatel RC + poměr', false, true);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                 vat_deduction, vat_deduction_percent, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, 1, "{}", 1000.00, 0.00, 1000.00, "received", "5", "proportional", 50.00, ?)'
        );
        $issue = self::YEAR . '-06-15';
        $stmt->execute([$this->supplierId, $vendor, 'PF-2099-RCPROP', $issue, $issue, $issue, $issue, $this->currencyId, $this->userId]);
        $purchaseId = (int) $this->db->pdo()->lastInsertId();
        $this->insertItem('purchase_invoice_items', 'purchase_invoice_id', $purchaseId, 1000.00, 0.00, 0.00);

        try {
            $this->posting->buildFromPurchaseInvoice($this->supplierId, $purchaseId);
            self::fail('RC + poměrný odpočet musí vyhodit PostingException.');
        } catch (PostingException $e) {
            self::assertSame('rc_partial_deduction_unsupported', $e->errorCode);
        }
    }

    public function testPurchaseInvoiceAcrossYearBoundaryUsesGreatestDateWindow(): void
    {
        // Regrese (audit B2): ledgerTotals skenoval jen kalendářní rok tax_date, ale
        // VatLedgerService zařazuje tuzemské PF do GREATEST(tax_date, issue_date) — PF
        // s DUZP na konci roku vystavená až v lednu spadla dřív mimo naskenované okno
        // → [0,0] → 'document_not_postable'.
        $vendor = $this->client('Dodavatel přelom roku', false, true);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                 vat_deduction, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, 0, "{}", 1000.00, 210.00, 1210.00, "received", "40", "full", ?)'
        );
        $taxDate   = self::YEAR . '-12-20';
        $issueDate = (self::YEAR + 1) . '-01-05';
        $stmt->execute([$this->supplierId, $vendor, 'PF-2099-BOUNDARY', $issueDate, $taxDate, $issueDate, $issueDate, $this->currencyId, $this->userId]);
        $purchaseId = (int) $this->db->pdo()->lastInsertId();
        $this->insertItem('purchase_invoice_items', 'purchase_invoice_id', $purchaseId, 1000.00, 210.00, 21.00);

        $lines   = $this->posting->buildFromPurchaseInvoice($this->supplierId, $purchaseId);
        $entryId = $this->posting->postDocument($this->supplierId, 'purchase_invoice', $purchaseId, $lines, ['entry_date' => self::YEAR . '-12-31']);
        $entry   = $this->journal->find($entryId, $this->supplierId);

        $byAccount = $this->linesByAccountCode($entry['lines']);
        self::assertEqualsWithDelta(1000.00, $byAccount['518']['debit'], 0.001);
        self::assertEqualsWithDelta(210.00, $byAccount['343.100']['debit'], 0.001);
        self::assertEqualsWithDelta(1210.00, $byAccount['321']['credit'], 0.001);
        $this->assertBalanced($entry['lines']);
    }

    public function testPurchaseInvoiceForeignReverseChargeAcrossYearBoundaryFindsLedgerRow(): void
    {
        // Regrese (audit B2 doaudit, nález 2): ledgerTotals dřív skenovalo jen
        // GREATEST(tax_date, issue_date) rok. VatLedgerService ale zahraniční
        // reverse-charge (issue #117) zařazuje čistě dle tax_date (DUZP), BEZ ohledu
        // na issue_date — na rozdíl od tuzemských dokladů (ty GREATEST používají).
        // Doklad s DUZP na konci roku a vystavením (zahraniční dodavatel, běžný
        // odstup) až v lednu: ledger řádek spadá do staršího roku (dle tax_date),
        // ale GREATEST-scanner hledal v novějším (dle issue_date) → řádek nenašel
        // → [0,0] → 'document_not_postable'. Fix skenuje celé rozpětí
        // MIN..MAX(tax_date, issue_date), takže řádek najde bez ohledu na to, které
        // přesné pravidlo VatLedgerService interně použil.
        $deId = (int) ($this->db->pdo()->query("SELECT id FROM countries WHERE iso2 = 'DE' LIMIT 1")->fetchColumn() ?: 0);
        if ($deId === 0) {
            self::markTestSkipped('Země DE není v číselníku.');
        }
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Teststrasse 1", "Berlin", "10115", ?, "DE123456789", "test@example.com", "cs", ?, 0, 1)'
        );
        $stmt->execute([$this->supplierId, 'EU dodavatel RC přelom roku', $deId, $this->currencyId]);
        $vendor = (int) $this->db->pdo()->lastInsertId();

        // RC (vendor mimo CZ) fakturuje BEZ DPH — hlavička i položka nesou jen základ
        // (vat=0), DPH se v ledgeru samovyměří ze základu × vat_rate_snapshot (21 %),
        // stejně jako reálná data (viz KhDphTaxScenariosTest P4 [8000,0,21]).
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                 vat_deduction, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, 1, "{}", 1000.00, 0.00, 1000.00, "received", "5", "full", ?)'
        );
        $taxDate   = self::YEAR . '-12-20';
        $issueDate = (self::YEAR + 1) . '-01-05';
        $stmt->execute([$this->supplierId, $vendor, 'PF-2099-EURC-BOUNDARY', $issueDate, $taxDate, $issueDate, $issueDate, $this->currencyId, $this->userId]);
        $purchaseId = (int) $this->db->pdo()->lastInsertId();
        $this->insertItem('purchase_invoice_items', 'purchase_invoice_id', $purchaseId, 1000.00, 0.00, 21.00);

        $lines   = $this->posting->buildFromPurchaseInvoice($this->supplierId, $purchaseId);
        $entryId = $this->posting->postDocument($this->supplierId, 'purchase_invoice', $purchaseId, $lines, ['entry_date' => self::YEAR . '-12-31']);
        $entry   = $this->journal->find($entryId, $this->supplierId);

        $byAccount = $this->linesByAccountCode($entry['lines']);
        self::assertEqualsWithDelta(1000.00, $byAccount['518']['debit'], 0.001, 'RC: náklad = základ.');
        self::assertEqualsWithDelta(210.00, $byAccount['343.100']['debit'], 0.001, 'RC: nárok na odpočet.');
        self::assertEqualsWithDelta(210.00, $byAccount['343.200']['credit'], 0.001, 'RC: povinnost přiznat daň (vyruší se).');
        self::assertEqualsWithDelta(1000.00, $byAccount['321']['credit'], 0.001, 'RC: závazek = jen základ (DPH samovyměřená).');
        $this->assertBalanced($entry['lines']);
    }

    public function testPurchaseInvoiceManualReceivedAtInLaterYearFindsLedgerRow(): void
    {
        // Regrese (audit C6'): u ruční PF (received_at_source='manual') zařazuje
        // VatLedgerService odpočet dle GREATEST(received_at, DUZP, vystavení) — § 73/1/a.
        // Když received_at spadá do POZDĚJŠÍHO roku než DUZP i vystavení (prosincové DUZP,
        // lednové fyzické přijetí dokladu), ledger řádek přeskočí do onoho pozdějšího roku.
        // ledgerTotals ale dřív skenoval jen MIN..MAX(tax_date, issue_date) — třetí rozměr
        // (received_at) nezná → řádek minul → [0,0] → 'document_not_postable', tj. doklad,
        // který JE v Knize DPH/DPHDP3/KH (leden dalšího roku), nešel zaúčtovat. Fix přidává
        // received_at (jen manual) do skenovacího okna.
        $vendor = $this->client('Dodavatel ruční přijetí přelom roku', false, true);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, received_at_source, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                 vat_deduction, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, "manual", ?, 0, "{}", 1000.00, 210.00, 1210.00, "received", "40", "full", ?)'
        );
        $taxDate    = self::YEAR . '-12-20';
        $issueDate  = self::YEAR . '-12-22';
        $receivedAt = (self::YEAR + 1) . '-01-05';
        $stmt->execute([$this->supplierId, $vendor, 'PF-2099-RECV', $issueDate, $taxDate, $issueDate, $receivedAt, $this->currencyId, $this->userId]);
        $purchaseId = (int) $this->db->pdo()->lastInsertId();
        $this->insertItem('purchase_invoice_items', 'purchase_invoice_id', $purchaseId, 1000.00, 210.00, 21.00);

        $lines   = $this->posting->buildFromPurchaseInvoice($this->supplierId, $purchaseId);
        $entryId = $this->posting->postDocument($this->supplierId, 'purchase_invoice', $purchaseId, $lines, ['entry_date' => self::YEAR . '-12-31']);
        $entry   = $this->journal->find($entryId, $this->supplierId);

        $byAccount = $this->linesByAccountCode($entry['lines']);
        self::assertEqualsWithDelta(1000.00, $byAccount['518']['debit'], 0.001, '518 MD = základ (ledger řádek nalezen i v pozdějším roce).');
        self::assertEqualsWithDelta(210.00, $byAccount['343.100']['debit'], 0.001, '343 MD = odpočet DPH.');
        self::assertEqualsWithDelta(1210.00, $byAccount['321']['credit'], 0.001, '321 D = závazek.');
        $this->assertBalanced($entry['lines']);
    }

    public function testPurchaseInvoiceHeaderLedgerMismatchAboveToleranceThrows(): void
    {
        // Regrese (audit B3): appendRounding dřív dorovnávala JAKKOLI velký rozdíl mezi
        // hlavičkou a DPH evidencí potichu na 648/548. Nad ROUNDING_TOLERANCE_CENTS
        // (2 Kč) teď musí vyhodit 'totals_mismatch' místo tiché klasifikace jako výnos.
        $vendor     = $this->client('Dodavatel s velkým rozdílem', false, true);
        $purchaseId = $this->purchase('PF-2099-MISMATCH', $vendor, '40', false, 2000.00, 420.00, 21.00);
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET total_with_vat = 2425.00 WHERE id = ?')->execute([$purchaseId]);

        $this->expectException(PostingException::class);
        $this->expectExceptionMessageMatches('/toleranci/u');
        $this->posting->buildFromPurchaseInvoice($this->supplierId, $purchaseId);
    }

    public function testCreditNoteInvoiceReversesLinesAndBalances(): void
    {
        // Regrese (audit B4/H3): dobropis (invoice_type='credit_note', záporný
        // total_with_vat) dřív spadl na 'nonpositive_amount' už v resolveLines —
        // standardní typ dokladu neměl cestu do deníku. Strany se teď obrací
        // (311 D / 602+343 MD), částky v abs().
        $client = $this->client('Odběratel s.r.o.', true, false);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, "credit_note", ?, ?, ?, ?, ?, 0, ?, ?, ?, "issued", ?, ?)'
        );
        $issue = self::YEAR . '-06-15';
        $stmt->execute([$this->supplierId, 'DOB-2099-001', $client, $issue, $issue, $issue, $this->currencyId, -1000.00, -210.00, -1210.00, '1', $this->userId]);
        $invoiceId = (int) $this->db->pdo()->lastInsertId();
        $this->insertItem('invoice_items', 'invoice_id', $invoiceId, -1000.00, -210.00, 21.00);

        $lines   = $this->posting->buildFromInvoice($this->supplierId, $invoiceId);
        $entryId = $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, $lines, ['entry_date' => self::YEAR . '-06-15']);
        $entry   = $this->journal->find($entryId, $this->supplierId);

        $byAccount = $this->linesByAccountCode($entry['lines']);
        self::assertEqualsWithDelta(1210.00, $byAccount['311']['credit'], 0.001, '311 D = snížení pohledávky (abs).');
        self::assertEqualsWithDelta(1000.00, $byAccount['602']['debit'], 0.001, '602 MD = storno výnosu.');
        self::assertEqualsWithDelta(210.00, $byAccount['343.200']['debit'], 0.001, '343 MD = storno DPH.');
        $this->assertBalanced($entry['lines']);
    }

    public function testCreditNoteForeignInvoiceReversesSidesAndKeepsForeignTrace(): void
    {
        // Regrese (audit B4): dobropis v cizí měně musí zachovat cizoměnovou stopu
        // (currency_code/fx_rate/amount_foreign) na saldokontním řádku, jen s obrácenou
        // stranou a abs() částkami — stejně jako CZK dobropis.
        $eurId = (int) ($this->db->pdo()->query("SELECT id FROM currencies WHERE code = 'EUR' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        if ($eurId === 0) {
            self::markTestSkipped('Měna EUR není v číselníku.');
        }
        $client = $this->client('EU Odběratel dobropis', true, false);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                currency_id, exchange_rate, reverse_charge, total_without_vat, total_vat, total_with_vat,
                status, vat_classification_code, created_by)
             VALUES (?, ?, "credit_note", ?, ?, ?, ?, ?, 25.0, 0, -100.00, -21.00, -121.00, "issued", "1", ?)'
        );
        $issue = self::YEAR . '-06-15';
        $stmt->execute([$this->supplierId, 'DOB-2099-FX', $client, $issue, $issue, $issue, $eurId, $this->userId]);
        $invoiceId = (int) $this->db->pdo()->lastInsertId();
        $this->insertItem('invoice_items', 'invoice_id', $invoiceId, -100.00, -21.00, 21.00);

        $lines   = $this->posting->buildFromInvoice($this->supplierId, $invoiceId);
        $entryId = $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, $lines, ['entry_date' => self::YEAR . '-06-15']);
        $entry   = $this->journal->find($entryId, $this->supplierId);
        $this->assertBalanced($entry['lines']);

        $receivable = null;
        foreach ($entry['lines'] as $l) {
            if ($l['currency_code'] !== null) {
                $receivable = $l;
            }
        }
        self::assertNotNull($receivable, 'Saldokontní řádek nese cizoměnovou stopu i u dobropisu.');
        self::assertSame('credit', $receivable['side'], 'Dobropis: 311 na straně D.');
        self::assertEqualsWithDelta(121.00, (float) $receivable['amount_foreign'], 0.001, 'amount_foreign = abs(celková částka v EUR).');
        self::assertEqualsWithDelta(3025.00, (float) $receivable['amount'], 0.001, '311 v CZK = abs(121 × 25).');
    }

    public function testCreditNotePurchaseInvoiceReversesLinesAndBalances(): void
    {
        // Regrese (audit B4): dobropis přijaté faktury (document_kind='credit_note')
        // obrací strany oproti běžné PF (321 MD / 5xx+343 D).
        $vendor = $this->client('Dodavatel a.s.', false, true);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                 vat_deduction, created_by)
             VALUES (?, ?, ?, "credit_note", ?, ?, ?, ?, ?, 0, "{}", -2000.00, -420.00, -2420.00, "received", "40", "full", ?)'
        );
        $issue = self::YEAR . '-06-15';
        $stmt->execute([$this->supplierId, $vendor, 'DOBPF-2099-001', $issue, $issue, $issue, $issue, $this->currencyId, $this->userId]);
        $purchaseId = (int) $this->db->pdo()->lastInsertId();
        $this->insertItem('purchase_invoice_items', 'purchase_invoice_id', $purchaseId, -2000.00, -420.00, 21.00);

        $lines   = $this->posting->buildFromPurchaseInvoice($this->supplierId, $purchaseId);
        $entryId = $this->posting->postDocument($this->supplierId, 'purchase_invoice', $purchaseId, $lines, ['entry_date' => self::YEAR . '-06-20']);
        $entry   = $this->journal->find($entryId, $this->supplierId);

        $byAccount = $this->linesByAccountCode($entry['lines']);
        self::assertEqualsWithDelta(2420.00, $byAccount['321']['debit'], 0.001, '321 MD = snížení závazku.');
        self::assertEqualsWithDelta(2000.00, $byAccount['518']['credit'], 0.001, '518 D = storno nákladu.');
        self::assertEqualsWithDelta(420.00, $byAccount['343.100']['credit'], 0.001, '343 D = storno odpočtu.');
        $this->assertBalanced($entry['lines']);
    }

    public function testCreditNoteInvoiceRoundingResidualLandsOnCorrectSide(): void
    {
        // Regrese (audit B4 doaudit, nález 1): appendRounding počítala diff ze SIGNED
        // (záporných) totalCzk/ledgerTotal, ale skutečně zaúčtované řádky jsou vždy
        // abs() — u dobropisu to spolu s $totalOnCredit flipem vrátilo dvojitou negaci
        // a dorovnání šlo na ŠPATNOU stranu (zdvojnásobilo nevyváženost místo jejího
        // vynulování → UnbalancedEntryException). Běžný 1haléřový rozdíl hlavička/
        // DPH evidence: total_with_vat = -1210.01, položky net=-1000.00, vat=-210.00.
        $client = $this->client('Odběratel s rezidui', true, false);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, "credit_note", ?, ?, ?, ?, ?, 0, ?, ?, ?, "issued", ?, ?)'
        );
        $issue = self::YEAR . '-06-15';
        $stmt->execute([$this->supplierId, 'DOB-2099-R1', $client, $issue, $issue, $issue, $this->currencyId, -1000.00, -210.00, -1210.01, '1', $this->userId]);
        $invoiceId = (int) $this->db->pdo()->lastInsertId();
        $this->insertItem('invoice_items', 'invoice_id', $invoiceId, -1000.00, -210.00, 21.00);

        $lines   = $this->posting->buildFromInvoice($this->supplierId, $invoiceId);
        $entryId = $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, $lines, ['entry_date' => self::YEAR . '-06-15']);
        $entry   = $this->journal->find($entryId, $this->supplierId);

        $byAccount = $this->linesByAccountCode($entry['lines']);
        self::assertEqualsWithDelta(1210.01, $byAccount['311']['credit'], 0.001);
        self::assertArrayHasKey('548', $byAccount, 'Dobropis: reziduum musí padnout na 548 MD, ne 648 D.');
        self::assertEqualsWithDelta(0.01, $byAccount['548']['debit'], 0.001);
        self::assertArrayNotHasKey('648', $byAccount, 'Nesmí spadnout na 648 (zdvojnásobilo by nevyváženost).');
        $this->assertBalanced($entry['lines']);
    }

    public function testCreditNotePurchaseInvoiceRoundingResidualLandsOnCorrectSide(): void
    {
        // Regrese (audit B4 doaudit, nález 1), zrcadlově pro přijatou fakturu (dobropis):
        // total_with_vat = -2420.01, položky net=-2000.00, vat=-420.00.
        $vendor = $this->client('Dodavatel s rezidui', false, true);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                 vat_deduction, created_by)
             VALUES (?, ?, ?, "credit_note", ?, ?, ?, ?, ?, 0, "{}", -2000.00, -420.00, -2420.01, "received", "40", "full", ?)'
        );
        $issue = self::YEAR . '-06-15';
        $stmt->execute([$this->supplierId, $vendor, 'DOBPF-2099-R1', $issue, $issue, $issue, $issue, $this->currencyId, $this->userId]);
        $purchaseId = (int) $this->db->pdo()->lastInsertId();
        $this->insertItem('purchase_invoice_items', 'purchase_invoice_id', $purchaseId, -2000.00, -420.00, 21.00);

        $lines   = $this->posting->buildFromPurchaseInvoice($this->supplierId, $purchaseId);
        $entryId = $this->posting->postDocument($this->supplierId, 'purchase_invoice', $purchaseId, $lines, ['entry_date' => self::YEAR . '-06-20']);
        $entry   = $this->journal->find($entryId, $this->supplierId);

        $byAccount = $this->linesByAccountCode($entry['lines']);
        self::assertEqualsWithDelta(2420.01, $byAccount['321']['debit'], 0.001);
        self::assertArrayHasKey('648', $byAccount, 'Dobropis PF: reziduum musí padnout na 648 D, ne 548 MD.');
        self::assertEqualsWithDelta(0.01, $byAccount['648']['credit'], 0.001);
        self::assertArrayNotHasKey('548', $byAccount, 'Nesmí spadnout na 548 (zdvojnásobilo by nevyváženost).');
        $this->assertBalanced($entry['lines']);
    }

    public function testInactiveAccountRefused(): void
    {
        // Regrese (audit B7a): codeToIdMap dřív nefiltrovalo is_active — deaktivovaný
        // účet šel dál použít pro nové zaúčtování. resolveLines teď vyhodí
        // 'account_inactive'.
        $accountId = (int) $this->db->pdo()
            ->query("SELECT id FROM chart_of_accounts WHERE supplier_id = {$this->supplierId} AND account_code = '518'")
            ->fetchColumn();
        self::assertGreaterThan(0, $accountId, 'Účet 518 musí být naseedovaný.');
        $this->db->pdo()->prepare('UPDATE chart_of_accounts SET is_active = 0 WHERE id = ?')->execute([$accountId]);

        $this->expectException(PostingException::class);
        $this->expectExceptionMessageMatches('/deaktivovaný/u');
        $this->posting->postDocument(
            $this->supplierId,
            'manual',
            null,
            [
                ['account_code' => '518', 'side' => 'debit', 'amount' => 100.00],
                ['account_code' => '211', 'side' => 'credit', 'amount' => 100.00],
            ],
            ['entry_date' => self::YEAR . '-06-15'],
        );
    }

    public function testClosingAccountForbiddenOutsideClosingEntry(): void
    {
        // Regrese (audit B7b): závěrkový účet (701/702/710) se dřív dal použít i
        // v ručním zápisu — resolveLines teď vynutí source_type closing/opening.
        $this->expectException(PostingException::class);
        $this->expectExceptionMessageMatches('/závěrkový/u');
        $this->posting->postDocument(
            $this->supplierId,
            'manual',
            null,
            [
                ['account_code' => '701', 'side' => 'debit', 'amount' => 100.00],
                ['account_code' => '211', 'side' => 'credit', 'amount' => 100.00],
            ],
            ['entry_date' => self::YEAR . '-06-15'],
        );
    }

    public function testOffbalanceAccountCannotMixWithBalanceSheetAccount(): void
    {
        // Regrese (audit B7b): podrozvahový účet (75x/79x) se dřív dal zaúčtovat proti
        // rozvahovému/výsledkovému, čímž by se zdvojil do bilance/VZZ.
        $this->expectException(PostingException::class);
        $this->expectExceptionMessageMatches('/[Pp]odrozvahov/u');
        $this->posting->postDocument(
            $this->supplierId,
            'manual',
            null,
            [
                ['account_code' => '751', 'side' => 'debit', 'amount' => 100.00],
                ['account_code' => '602', 'side' => 'credit', 'amount' => 100.00],
            ],
            ['entry_date' => self::YEAR . '-06-15'],
        );
    }

    public function testOffbalanceAgainstOffbalanceIsAllowed(): void
    {
        // Guard: podrozvaha jednostranně proti jiné podrozvaze (typicky 799) zůstává
        // povolená.
        $entryId = $this->posting->postDocument(
            $this->supplierId,
            'manual',
            null,
            [
                ['account_code' => '751', 'side' => 'debit', 'amount' => 100.00],
                ['account_code' => '799', 'side' => 'credit', 'amount' => 100.00],
            ],
            ['entry_date' => self::YEAR . '-06-15'],
        );
        self::assertGreaterThan(0, $entryId);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @param list<array<string,mixed>> $lines
     * @return array<string,array{debit:float,credit:float}>
     */
    private function linesByAccountCode(array $lines): array
    {
        $codeById = [];
        $stmt = $this->db->pdo()->prepare('SELECT id, account_code FROM chart_of_accounts WHERE supplier_id = ?');
        $stmt->execute([$this->supplierId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $codeById[(int) $r['id']] = (string) $r['account_code'];
        }
        $out = [];
        foreach ($lines as $l) {
            $code = $codeById[(int) $l['account_id']] ?? '?';
            $out[$code] ??= ['debit' => 0.0, 'credit' => 0.0];
            $out[$code][$l['side']] += (float) $l['amount'];
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $lines */
    private function assertBalanced(array $lines): void
    {
        $debit = 0;
        $credit = 0;
        foreach ($lines as $l) {
            $cents = (int) round((float) $l['amount'] * 100);
            if ($l['side'] === 'debit') {
                $debit += $cents;
            } else {
                $credit += $cents;
            }
        }
        self::assertSame($debit, $credit, 'Σ MD == Σ D (v haléřích).');
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
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, 0, ?, ?, ?, "issued", ?, ?)'
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

    /** Karta drobného majetku (1094) — cíl vazby invoice_items.small_asset_id. */
    private function smallAssetCard(string $name, float $price): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO small_assets (supplier_id, name, acquisition_date, quantity, unit_price, price, status)
             VALUES (?, ?, ?, 1, ?, ?, "in_use")'
        )->execute([$this->supplierId, $name, self::YEAR . '-01-15', $price, $price]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** Karta dlouhodobého majetku (F3) — cíl vazby invoice_items.asset_id. */
    private function assetCard(string $name, float $price): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO assets (supplier_id, inventory_number, name, kind, input_price,
                                 acquisition_date, put_into_use_date, status, tax_group)
             VALUES (?, ?, ?, "tangible", ?, ?, ?, "in_use", 2)'
        )->execute([
            $this->supplierId, 'INV-' . uniqid('a', false), $name, $price,
            self::YEAR . '-01-15', self::YEAR . '-01-15',
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** Další řádek faktury, volitelně navázaný na kartu majetku. */
    private function addLinkedItem(
        int $invoiceId,
        float $base,
        float $vat,
        float $rate,
        int $order,
        ?int $smallAssetId,
        ?int $assetId,
    ): void {
        $this->db->pdo()->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index,
                 vat_classification_code, small_asset_id, asset_id)
             VALUES (?, "Prodej majetku", 1, "ks", ?, ?, ?, ?, ?, ?, ?, "1", ?, ?)'
        )->execute([
            $invoiceId, $base, $this->vatRateId, $rate, $base, $vat, $base + $vat, $order,
            $smallAssetId, $assetId,
        ]);
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

    private function insertCashVatDocumentWithId(int $id, string $docType, float $base, float $vat): void
    {
        $pdo = $this->db->pdo();

        $pdo->prepare(
            'INSERT INTO cash_registers (supplier_id, name, account_code, is_default)
             VALUES (?, ?, "211", 0)'
        )->execute([$this->supplierId, 'Kolizní pokladna ' . $id]);
        $registerId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO cash_documents
                (id, supplier_id, register_id, doc_type, purpose, doc_number, issue_date, tax_date,
                 description, vat_mode, total_amount, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, "Kolizní pokladní doklad", "vat", ?, "posted", ?)'
        )->execute([
            $id,
            $this->supplierId,
            $registerId,
            $docType,
            $docType === 'in' ? 'sale' : 'purchase',
            'CASH-ID-' . $id,
            self::YEAR . '-06-15',
            self::YEAR . '-06-15',
            $base + $vat,
            $this->userId,
        ]);
        $pdo->prepare(
            'INSERT INTO cash_document_vat_lines (cash_document_id, vat_rate, base_amount, vat_amount)
             VALUES (?, 21.00, ?, ?)'
        )->execute([$id, $base, $vat]);
    }

    private function cashDocumentIdExists(int $id): bool
    {
        $statement = $this->db->pdo()->prepare('SELECT 1 FROM cash_documents WHERE id = ?');
        $statement->execute([$id]);
        return $statement->fetchColumn() !== false;
    }
}
