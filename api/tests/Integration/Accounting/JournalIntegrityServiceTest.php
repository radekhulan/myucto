<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\JournalIntegrityService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Integrační test A6 — noční integrity job nad účetním deníkem.
 *
 * Založí uměle nekonzistentní data (sirotčí zápis, nevyvážený zápis, booked_at
 * bez zápisu a naopak, doklad ≠ zápis částkou) a ověří, že JournalIntegrityService
 * je detekuje. Zároveň ověří, že check() je ČISTĚ ČTECÍ — nezasahuje do účetních
 * dat (počty řádků v journal_entries/lines/invoices se během kontroly nemění).
 *
 * Vše běží v jedné transakci, tearDown ji rollbackne → DB zůstane netknutá.
 * Synthetic data se zakládají přímým INSERTem (mimo PostingService), aby vznikly
 * i stavy, které by PostingService jinak nedovolil (nevyváženost, sirotek).
 * Soft-skip bez cfg.php / DB.
 */
#[Group('integration')]
final class JournalIntegrityServiceTest extends TestCase
{
    private const YEAR = 2099;

    private Connection $db;
    private JournalIntegrityService $service;
    private AccountingPeriodRepository $periods;

    private int $supplierId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private int $accountId = 0;
    private bool $inTx = false;
    private int $seq = 0;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db      = $container->get(Connection::class);
            $this->service = $container->get(JournalIntegrityService::class);
            $this->periods = $container->get(AccountingPeriodRepository::class);
            $seeder        = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí supplier.');
        }
        $this->clientId   = (int) ($pdo->query("SELECT id FROM clients WHERE supplier_id = {$this->supplierId} ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE supplier_id = {$this->supplierId} AND code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->clientId === 0 || $this->currencyId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí client / CZK currency / user.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        // Osnova (idempotentní) + otevřené období 2099 + jeden účet pro FK řádků.
        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $this->accountId = (int) ($pdo->query(
            "SELECT id FROM chart_of_accounts WHERE supplier_id = {$this->supplierId} ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($this->accountId === 0) {
            $this->markTestSkipped('Osnova se nenaseedovala.');
        }
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

    // ── fixtures ────────────────────────────────────────────────────────────────

    private function insertEntry(string $sourceType, ?int $sourceId, bool $posted): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO journal_entries
                 (supplier_id, period_id, entry_date, source_type, source_id, posted_at, posted_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $this->supplierId, $this->periodId, self::YEAR . '-06-15',
            $sourceType, $sourceId,
            $posted ? self::YEAR . '-06-15 10:00:00' : null,
            $posted ? $this->userId : null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function insertLine(int $entryId, string $side, float $amount, int $lineNo): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO journal_entry_lines (entry_id, supplier_id, account_id, side, amount, line_no)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([$entryId, $this->supplierId, $this->accountId, $side, $amount, $lineNo]);
    }

    /** Vyvážený zápis (debit == credit) pro daný doklad. */
    private function insertBalancedEntry(string $sourceType, ?int $sourceId, float $amount): int
    {
        $id = $this->insertEntry($sourceType, $sourceId, true);
        $this->insertLine($id, 'debit', $amount, 1);
        $this->insertLine($id, 'credit', $amount, 2);
        return $id;
    }

    private function insertInvoice(?string $bookedAt, float $totalWithVat, string $invoiceType = 'invoice'): int
    {
        $pdo = $this->db->pdo();
        $today = self::YEAR . '-06-15';
        $pdo->prepare(
            "INSERT INTO invoices
                 (invoice_type, varsymbol, client_id, supplier_id, issue_date, tax_date, due_date,
                  currency_id, status, total_without_vat, total_vat, total_with_vat, booked_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'issued', ?, ?, ?, ?, ?)"
        )->execute([
            $invoiceType, 'JIT' . (++$this->seq), $this->clientId, $this->supplierId, $today, $today, $today,
            $this->currencyId, $totalWithVat, $invoiceType === 'tax_document' ? 21.00 : 0.00,
            $totalWithVat, $bookedAt, $this->userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function tableCounts(): array
    {
        $pdo = $this->db->pdo();
        return [
            'entries'  => (int) $pdo->query("SELECT COUNT(*) FROM journal_entries WHERE supplier_id = {$this->supplierId}")->fetchColumn(),
            'lines'    => (int) $pdo->query("SELECT COUNT(*) FROM journal_entry_lines WHERE supplier_id = {$this->supplierId}")->fetchColumn(),
            'invoices' => (int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE supplier_id = {$this->supplierId}")->fetchColumn(),
        ];
    }

    // ── testy ───────────────────────────────────────────────────────────────────

    public function testDetectsAllFiveInconsistencies(): void
    {
        $svc = JournalIntegrityService::class;

        // Baseline (dev DB může mít vlastní nálezy) → porovnáváme DELTU.
        $base = $this->service->check($this->supplierId);

        // A) Sirotčí zápis — odkaz na neexistující fakturu.
        $this->insertBalancedEntry('invoice', 1900000000 + $this->supplierId, 50.00);

        // B) Nevyvážený zápis — Σ MD ≠ Σ D (manuální, aby nespadl do jiných kontrol).
        $unbalanced = $this->insertEntry('manual', null, true);
        $this->insertLine($unbalanced, 'debit', 100.00, 1);
        $this->insertLine($unbalanced, 'credit', 40.00, 2);

        // C) Zápis bez booked_at — faktura booked_at NULL, ale aktivní zápis existuje
        //    (částka 121 sedí → NENÍ amount_mismatch).
        $invNoBooked = $this->insertInvoice(null, 121.00);
        $this->insertBalancedEntry('invoice', $invNoBooked, 121.00);

        // D) booked_at bez zápisu — faktura zaúčtovaná, žádný zápis.
        $this->insertInvoice(self::YEAR . '-06-15 10:00:00', 121.00);

        // E) Doklad ≠ zápis částkou — faktura 121, řádky zápisu 999 (nesedí),
        //    booked_at set (→ ne entry_without_booked, ne booked_without_entry).
        $invMismatch = $this->insertInvoice(self::YEAR . '-06-15 10:00:00', 121.00);
        $this->insertBalancedEntry('invoice', $invMismatch, 999.00);

        $after = $this->service->check($this->supplierId);

        self::assertSame(
            $base[JournalIntegrityService::TYPE_ORPHAN_ENTRY]['count'] + 1,
            $after[JournalIntegrityService::TYPE_ORPHAN_ENTRY]['count'],
            'Sirotčí zápis detekován.'
        );
        self::assertSame(
            $base[JournalIntegrityService::TYPE_UNBALANCED_ENTRY]['count'] + 1,
            $after[JournalIntegrityService::TYPE_UNBALANCED_ENTRY]['count'],
            'Nevyvážený zápis (Σ MD ≠ Σ D) detekován.'
        );
        self::assertSame(
            $base[JournalIntegrityService::TYPE_ENTRY_WITHOUT_BOOKED]['count'] + 1,
            $after[JournalIntegrityService::TYPE_ENTRY_WITHOUT_BOOKED]['count'],
            'Aktivní zápis u dokladu s booked_at NULL detekován.'
        );
        self::assertSame(
            $base[JournalIntegrityService::TYPE_BOOKED_WITHOUT_ENTRY]['count'] + 1,
            $after[JournalIntegrityService::TYPE_BOOKED_WITHOUT_ENTRY]['count'],
            'Zaúčtovaný doklad bez zápisu detekován.'
        );
        self::assertSame(
            $base[JournalIntegrityService::TYPE_AMOUNT_MISMATCH]['count'] + 1,
            $after[JournalIntegrityService::TYPE_AMOUNT_MISMATCH]['count'],
            'Doklad ≠ zápis částkou detekován.'
        );

        // Sample (detail JSON) obsahuje offending řádky.
        self::assertNotEmpty($after[JournalIntegrityService::TYPE_ORPHAN_ENTRY]['sample']);
        self::assertNotEmpty($after[JournalIntegrityService::TYPE_UNBALANCED_ENTRY]['sample']);
    }

    public function testCheckIsReadOnly(): void
    {
        // Založ nekonzistentní data.
        $this->insertBalancedEntry('invoice', 1900000000 + $this->supplierId, 50.00);
        $unbalanced = $this->insertEntry('manual', null, true);
        $this->insertLine($unbalanced, 'debit', 100.00, 1);
        $this->insertLine($unbalanced, 'credit', 40.00, 2);
        $this->insertInvoice(self::YEAR . '-06-15 10:00:00', 121.00);

        $before = $this->tableCounts();
        $this->service->check($this->supplierId);
        $after = $this->tableCounts();

        self::assertSame($before, $after, 'check() nesmí zapisovat/mazat účetní data (read-only).');
    }

    public function testTaxDocumentVatOnlyEntryIsNotAmountMismatch(): void
    {
        $before = $this->service->check($this->supplierId);
        $ddkpId = $this->insertInvoice(self::YEAR . '-06-15 10:00:00', 121.00, 'tax_document');
        $this->insertBalancedEntry('invoice', $ddkpId, 21.00);
        $after = $this->service->check($this->supplierId);

        self::assertSame(
            $before[JournalIntegrityService::TYPE_AMOUNT_MISMATCH]['count'],
            $after[JournalIntegrityService::TYPE_AMOUNT_MISMATCH]['count'],
            'DDKP účtuje jen DPH 324/343 a nesmí být falešný amount_mismatch.',
        );
    }

    private function amountMismatchCount(): int
    {
        return $this->service->check($this->supplierId)[JournalIntegrityService::TYPE_AMOUNT_MISMATCH]['count'];
    }

    /**
     * Saldokonto rozpadlé na základ + DPH (starší zaúčtování, ruční zápisy, import):
     * ŽÁDNÝ jednotlivý řádek se celkové částce dokladu nerovná, ale součet řádků
     * téhož účtu na téže straně ano → není to nekonzistence. Dřív takový zápis
     * padal jako falešný nález (na ostrých datech jich to dělalo stovky).
     */
    public function testSplitBalanceLinesSummingToDocumentTotalAreNotAmountMismatch(): void
    {
        $before = $this->amountMismatchCount();

        $invoiceId = $this->insertInvoice(self::YEAR . '-06-15 10:00:00', 121.00);
        $entry = $this->insertEntry('invoice', $invoiceId, true);
        $this->insertLine($entry, 'debit', 100.00, 1);
        $this->insertLine($entry, 'debit', 21.00, 2);
        $this->insertLine($entry, 'credit', 100.00, 3);
        $this->insertLine($entry, 'credit', 21.00, 4);

        self::assertSame($before, $this->amountMismatchCount(),
            'Saldokonto rozpadlé na základ + DPH dává v součtu celkovou částku dokladu.');
    }

    /**
     * Protizápis na TÉMŽE účtu (haléřové vyrovnání, sleva zaúčtovaná opačnou stranou):
     * sedět musí saldo účtu, ne jednotlivý řádek.
     */
    public function testNettedBalanceOnSameAccountIsNotAmountMismatch(): void
    {
        $pdo = $this->db->pdo();
        $other = (int) ($pdo->query(
            "SELECT id FROM chart_of_accounts WHERE supplier_id = {$this->supplierId}
              AND id <> {$this->accountId} ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($other === 0) {
            self::markTestSkipped('Osnova má jen jeden účet — protistranu není kam dát.');
        }
        $line = $pdo->prepare(
            "INSERT INTO journal_entry_lines (entry_id, supplier_id, account_id, side, amount, line_no)
             VALUES (?, ?, ?, ?, ?, ?)"
        );

        $before = $this->amountMismatchCount();

        $invoiceId = $this->insertInvoice(self::YEAR . '-06-15 10:00:00', 121.00);
        $entry = $this->insertEntry('invoice', $invoiceId, true);
        // Saldokontní účet: 131 MD − 10 D = 121,00 (protizápis na tomtéž účtu).
        $this->insertLine($entry, 'debit', 131.00, 1);
        $this->insertLine($entry, 'credit', 10.00, 2);
        $line->execute([$entry, $this->supplierId, $other, 'credit', 131.00, 3]);
        $line->execute([$entry, $this->supplierId, $other, 'debit', 10.00, 4]);

        self::assertSame($before, $this->amountMismatchCount(),
            'Saldo účtu (MD − D) odpovídá celkové částce dokladu.');
    }

    /** Zaokrouhlení dokladu se do tolerance přičítá — jinak firuje každý zaokrouhlený doklad. */
    public function testDocumentRoundingWidensTolerance(): void
    {
        $before = $this->amountMismatchCount();

        // Zaokrouhlení 2 Kč je nad základní tolerancí (1 Kč) schválně — bez jeho
        // připočtení by zápis spadl jako nález.
        $invoiceId = $this->insertInvoice(self::YEAR . '-06-15 10:00:00', 121.00);
        $this->db->pdo()->prepare('UPDATE invoices SET rounding = 2.00 WHERE id = ?')->execute([$invoiceId]);
        $this->insertBalancedEntry('invoice', $invoiceId, 123.00);

        self::assertSame($before, $this->amountMismatchCount(),
            'Zápis se od hlavičky liší přesně o zaokrouhlení dokladu.');
    }

    /**
     * Doklad plně krytý zálohou: závazek/pohledávka vůbec nevzniká (uzavírá se proti
     * 314/324), takže celková částka dokladu v zápisu být nemá.
     */
    public function testFullyAdvanceSettledDocumentIsSkipped(): void
    {
        $before = $this->amountMismatchCount();

        $invoiceId = $this->insertInvoice(self::YEAR . '-06-15 10:00:00', 121.00);
        $this->db->pdo()->prepare('UPDATE invoices SET advance_paid_amount = 121.00 WHERE id = ?')->execute([$invoiceId]);
        $this->insertBalancedEntry('invoice', $invoiceId, 100.00);

        self::assertSame($before, $this->amountMismatchCount(),
            'Doklad zúčtovaný zálohou v plné výši se nekontroluje.');
    }

    /** Filtr deníku (`?integrity=amount_mismatch`) musí vracet TYTÉŽ zápisy jako kontrola. */
    public function testAmountMismatchEntryIdsMatchTheFinding(): void
    {
        $invoiceId = $this->insertInvoice(self::YEAR . '-06-15 10:00:00', 121.00);
        $entry = $this->insertBalancedEntry('invoice', $invoiceId, 999.00);

        $ids = $this->service->amountMismatchEntryIds($this->supplierId);

        self::assertContains($entry, $ids, 'Nález se musí objevit i ve filtru deníku.');
        self::assertCount($this->amountMismatchCount(), $ids, 'Filtr a počet na dashboardu se nesmí rozejít.');
    }

    /**
     * N-003: `booked_without_entry` hlásil i doklady, u kterých je „booked_at bez zápisu"
     * korektní stav.
     *
     * Storno `booked_at` nemaže (CancelInvoiceAction mění jen status/cancelled_at), takže
     * každý stornovaný zaúčtovaný doklad by firoval navždy. A proforma se do deníku
     * z principu neúčtuje — není v PostingService::POSTABLE_ISSUED_INVOICE_TYPES.
     */
    public function testBookedWithoutEntryIgnoresCancelledAndNonPostableTypes(): void
    {
        $before = $this->service->check($this->supplierId);

        // Stornovaný doklad si booked_at ponechá — nález to být nesmí.
        $cancelled = $this->insertInvoice(self::YEAR . '-06-15 10:00:00', 1000.00);
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'cancelled' WHERE id = ?")->execute([$cancelled]);

        // Proforma se do deníku neúčtuje.
        $proforma = $this->insertInvoice(self::YEAR . '-06-15 10:00:00', 500.00, 'proforma');

        $after = $this->service->check($this->supplierId);
        self::assertSame(
            $before[JournalIntegrityService::TYPE_BOOKED_WITHOUT_ENTRY]['count'],
            $after[JournalIntegrityService::TYPE_BOOKED_WITHOUT_ENTRY]['count'],
            'Storno ani proforma nesmí být booked_without_entry.',
        );
        self::assertGreaterThan(0, $cancelled + $proforma);
    }

    /**
     * N-004: `unbalanced_entry` používal INNER JOIN, takže zápis BEZ jediného řádku
     * z GROUP BY vypadl a platil za vyvážený.
     *
     * Nechytí ho ani booked_without_entry (zápis existuje), ani entry_without_booked.
     * U source_type mimo invoice/purchase_invoice ho neodhalí vůbec nic.
     */
    public function testUnbalancedDetectsEntryWithoutAnyLines(): void
    {
        $before = $this->service->check($this->supplierId);
        $this->insertEntry('manual', null, true); // zápis bez řádků
        $after = $this->service->check($this->supplierId);

        self::assertSame(
            $before[JournalIntegrityService::TYPE_UNBALANCED_ENTRY]['count'] + 1,
            $after[JournalIntegrityService::TYPE_UNBALANCED_ENTRY]['count'],
            'Prázdný zápis musí být nevyvážený — dřív z GROUP BY vypadl.',
        );
    }

    /**
     * N-005: `fx_metadata` měl falešný poplach i slepé místo.
     *
     * (a) FxRevaluationService zapisuje FX stopu s `amount_foreign = 0.0` ZÁMĚRNĚ —
     *     přeceněním se cizoměnová částka nemění (R20), stopa jen drží měnovou identitu
     *     účtu. Každé přecenění k rozvahovému dni by jinak hlásilo nález.
     * (b) Řádek s EXPLICITNÍM `currency_code = 'CZK'` dřív nespadl do žádné větve
     *     (první ho vylučovala, druhá vyžadovala NULL), takže mohl nést libovolná
     *     FX metadata neviditelně.
     */
    public function testFxMetadataAllowsRevaluationTraceButCatchesExplicitCzk(): void
    {
        $before = $this->service->checkTenantIntegrity($this->supplierId);
        $baseline = $before[JournalIntegrityService::TYPE_FX_METADATA]['count'];

        // (a) Přeceňovací stopa: cizí měna, kurz, amount_foreign = 0 → NENÍ nález.
        $fxEntry = $this->insertEntry('fx_revaluation', null, true);
        $this->insertFxLine($fxEntry, 'debit', 100.00, 'EUR', 25.43, 0.00);
        $this->insertFxLine($fxEntry, 'credit', 100.00, 'EUR', 25.43, 0.00);

        $afterA = $this->service->checkTenantIntegrity($this->supplierId);
        self::assertSame(
            $baseline,
            $afterA[JournalIntegrityService::TYPE_FX_METADATA]['count'],
            'FX stopa přecenění (amount_foreign = 0) je legitimní, ne nález.',
        );

        // (b) Domácí řádek označený 'CZK', ale s FX metadaty → nález.
        $manual = $this->insertEntry('manual', null, true);
        $this->insertFxLine($manual, 'debit', 50.00, 'CZK', 25.43, 1000.00);
        $this->insertFxLine($manual, 'credit', 50.00, null, null, null);

        $afterB = $this->service->checkTenantIntegrity($this->supplierId);
        self::assertSame(
            $baseline + 1,
            $afterB[JournalIntegrityService::TYPE_FX_METADATA]['count'],
            'Řádek s explicitním CZK a FX metadaty musí být nález.',
        );
    }

    /** Řádek s cizoměnovými metadaty (pro FX kontroly). */
    private function insertFxLine(
        int $entryId,
        string $side,
        float $amount,
        ?string $currencyCode,
        ?float $fxRate,
        ?float $amountForeign,
    ): void {
        $this->db->pdo()->prepare(
            "INSERT INTO journal_entry_lines
                 (entry_id, supplier_id, account_id, side, amount, currency_code, fx_rate, amount_foreign, line_no)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $entryId, $this->supplierId, $this->accountId, $side, $amount,
            $currencyCode, $fxRate, $amountForeign, ++$this->seq,
        ]);
    }

    public function testOrphanCheckDoesNotAcceptForeignTenantDocument(): void
    {
        $foreignSupplierId = (int) ($this->db->pdo()->query(
            "SELECT id FROM supplier WHERE id <> {$this->supplierId} ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($foreignSupplierId === 0) {
            self::markTestSkipped('Test L32 vyžaduje druhého suppliera.');
        }
        $foreignInvoiceId = (int) ($this->db->pdo()->query(
            "SELECT id FROM invoices WHERE supplier_id = {$foreignSupplierId} ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($foreignInvoiceId === 0) {
            self::markTestSkipped('Druhý supplier nemá fakturu pro cross-tenant orphan scénář.');
        }

        $before = $this->service->check($this->supplierId);
        $this->insertBalancedEntry('invoice', $foreignInvoiceId, 50.00);
        $after = $this->service->check($this->supplierId);
        self::assertSame(
            $before[JournalIntegrityService::TYPE_ORPHAN_ENTRY]['count'] + 1,
            $after[JournalIntegrityService::TYPE_ORPHAN_ENTRY]['count'],
            'Doklad jiného tenanta nesmí zamaskovat sirotčí zápis.',
        );
    }

    /**
     * Bankovní zápis nese source_id = bank_transactions.id bez cizího klíče. Smazaný
     * výpis po sobě nechal živý zápis a kontrola ho neviděla — dvojí úhrada se pak
     * v deníku nedala dohledat.
     */
    public function testOrphanBankEntriesAreDetected(): void
    {
        $before = $this->service->check($this->supplierId)[JournalIntegrityService::TYPE_ORPHAN_ENTRY]['count'];

        $this->insertBalancedEntry('bank', 1900000000 + $this->supplierId, 50.00);
        $this->insertBalancedEntry('card_settlement', 1900000001 + $this->supplierId, 30.00);

        $after = $this->service->check($this->supplierId)[JournalIntegrityService::TYPE_ORPHAN_ENTRY]['count'];
        self::assertSame($before + 2, $after);
    }

    public function testBankEntryOfExistingTransactionIsNotOrphan(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO bank_statements (supplier_id, file_name, file_hash, account_number, currency, statement_date)
             VALUES (?, 'integrity.gpc', ?, '1000000005', 'CZK', ?)"
        )->execute([$this->supplierId, hash('sha256', uniqid('jit', true)), self::YEAR . '-06-15']);
        $statementId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO bank_transactions (statement_id, posted_at, amount, currency) VALUES (?, ?, 50.00, 'CZK')"
        )->execute([$statementId, self::YEAR . '-06-15']);
        $txId = (int) $pdo->lastInsertId();

        $before = $this->service->check($this->supplierId)[JournalIntegrityService::TYPE_ORPHAN_ENTRY]['count'];
        $this->insertBalancedEntry('bank', $txId, 50.00);
        $after = $this->service->check($this->supplierId)[JournalIntegrityService::TYPE_ORPHAN_ENTRY]['count'];

        self::assertSame($before, $after, 'Zápis existujícího pohybu vlastní firmy není sirotek.');
    }

    public function testCheckAndStorePersistsFindingsWithoutTouchingAccountingData(): void
    {
        $this->insertBalancedEntry('invoice', 1900000000 + $this->supplierId, 50.00);

        $accountingBefore = $this->tableCounts();
        $findings = $this->service->checkAndStore($this->supplierId);
        $accountingAfter = $this->tableCounts();

        self::assertSame($accountingBefore, $accountingAfter,
            'checkAndStore() nesmí měnit účetní data — zapisuje jen do journal_integrity_findings.');
        self::assertGreaterThanOrEqual(1, $findings[JournalIntegrityService::TYPE_ORPHAN_ENTRY]['count']);

        // Uložený řádek pro typ orphan_entry existuje a odpovídá spočtenému počtu.
        $stored = (int) $this->db->pdo()->query(
            "SELECT finding_count FROM journal_integrity_findings
              WHERE supplier_id = {$this->supplierId} AND finding_type = 'orphan_entry'"
        )->fetchColumn();
        self::assertSame($findings[JournalIntegrityService::TYPE_ORPHAN_ENTRY]['count'], $stored);

        // latestSummary čte poslední uložený běh (dashboard zdroj).
        $summary = $this->service->latestSummary($this->supplierId);
        self::assertArrayHasKey('total', $summary);
        self::assertGreaterThanOrEqual(1, $summary['total']);
    }
}
