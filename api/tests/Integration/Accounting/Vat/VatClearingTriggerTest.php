<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Vat;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Vat\VatClearingService;
use MyInvoice\Service\Accounting\Vat\VatClearingTrigger;
use MyInvoice\Service\Report\TaxSubmissionArchiver;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Spouštění zúčtovacího dokladu DPH PŘIZNÁNÍM (migrace 1332).
 *
 * Kalendářní cron neuměl vědět, že do už zúčtovaného období přibude opožděná faktura
 * nebo oprava — doklad pak ukazoval jinou daň, než jaká se skutečně podala. Tyhle testy
 * hlídají, že autoritativním okamžikem je podání přiznání, že zastaralost je vidět,
 * a že se aktualizace dělá PŘEPISEM (nikdy stornem) a jen v otevřeném období.
 */
#[Group('integration')]
final class VatClearingTriggerTest extends TestCase
{
    use IsolatedSupplierTrait;

    /**
     * Záměrně MINULÝ rok, ne budoucí jako ve {@see VatClearingServiceTest}: posun daňového
     * zámku po podání ({@see TaxSubmissionArchiver::lockDateFor()}) se dělá jen pro období,
     * které už skončilo. Nad budoucím rokem by se pořadí „zúčtování před zámkem" nedalo
     * ověřit vůbec — zámek by se nikdy neposunul.
     */
    private const YEAR = 2019;

    private Connection $db;
    private VatClearingService $clearing;
    private VatClearingTrigger $trigger;
    private TaxSubmissionArchiver $archiver;
    private PostingService $posting;
    private AccountingPeriodRepository $periods;
    private AccountingSupplierSettingsRepository $settings;

    private int $supplierId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 5);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db       = $container->get(Connection::class);
            $this->clearing = $container->get(VatClearingService::class);
            $this->trigger  = $container->get(VatClearingTrigger::class);
            $this->archiver = $container->get(TaxSubmissionArchiver::class);
            $this->posting  = $container->get(PostingService::class);
            $this->periods  = $container->get(AccountingPeriodRepository::class);
            $this->settings = $container->get(AccountingSupplierSettingsRepository::class);
            $seeder         = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($source === 0) {
            $this->markTestSkipped('Chybí supplier v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $seeder->seedForSupplier($this->supplierId);
        $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $pdo->prepare("UPDATE supplier SET vat_period = 'monthly', is_vat_payer = 1, is_identified = 0, accounting_mode = 'double_entry' WHERE id = ?")
            ->execute([$this->supplierId]);
        $this->settings->setLockedUntil($this->supplierId, null);
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

    // ── pomocníci ─────────────────────────────────────────────────────────────

    private function bookOutputVat(float $amount, string $date, int $sourceId): void
    {
        $this->posting->postDocument($this->supplierId, 'manual', $sourceId, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => $amount],
            ['account_code' => '343.200', 'side' => 'credit', 'amount' => $amount],
        ], ['entry_date' => $date, 'description' => 'DPH na výstupu']);
    }

    /** Vloží archivovaný snímek přiznání (stav `downloaded`) a vrátí jeho id. */
    private function makeSubmission(int $month, string $variant = 'B'): int
    {
        $this->db->pdo()->prepare(
            "INSERT INTO tax_submissions
                (supplier_id, form_code, period_year, period_month, form_variant,
                 xml_content, xml_size_bytes, xml_sha256, validation_status, status)
             VALUES (?, 'dphdp3', ?, ?, ?, '<x/>', 4, ?, 'passed', 'downloaded')"
        )->execute([$this->supplierId, self::YEAR, $month, $variant, str_repeat('a', 64)]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function clearingEntryCount(): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ? AND source_type = 'vat_clearing'"
        );
        $stmt->execute([$this->supplierId]);

        return (int) $stmt->fetchColumn();
    }

    private function reversedCount(): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM journal_entries
              WHERE supplier_id = ? AND (reversed_by IS NOT NULL OR source_type = ?)
                AND reversed_by IS NOT NULL'
        );
        $stmt->execute([$this->supplierId, VatClearingService::SOURCE_TYPE]);

        return (int) $stmt->fetchColumn();
    }

    // ── 1) přiznání jako spouštěč ─────────────────────────────────────────────

    /** Podané přiznání zúčtování ZALOŽÍ a zapíše, kterému podání odpovídá. */
    public function testFilingTheReturnPostsTheClearingAndLinksTheSubmission(): void
    {
        $this->bookOutputVat(9000.00, self::YEAR . '-03-10', 910001);
        $submissionId = $this->makeSubmission(3);

        self::assertSame(0, $this->clearingEntryCount(), 'Před podáním doklad neexistuje.');

        $this->archiver->markSubmitted($submissionId, $this->supplierId, self::YEAR . '-04-20 09:00:00', 'REF-1', null);

        $status = $this->clearing->status($this->supplierId, self::YEAR, 3);
        self::assertSame(VatClearingService::FRESHNESS_OK, $status['freshness']);
        self::assertNotNull($status['entry_id'], 'Podání přiznání doklad zaúčtovalo.');
        self::assertEqualsWithDelta(9000.00, $status['posted']['output_vat'], 0.001);

        self::assertNotNull($status['run']);
        self::assertSame(VatClearingService::TRIGGER_RETURN_FILED, $status['run']['trigger_source']);
        self::assertSame($submissionId, $status['run']['submission_id'], 'Doklad si pamatuje, kterému podání odpovídá.');
        self::assertSame('dphdp3', $status['run']['submission_form']);
        self::assertSame('B', $status['run']['submission_variant']);
    }

    /** Kontrolní hlášení daň nestanovuje — zúčtování nespouští. */
    public function testControlStatementDoesNotTriggerClearing(): void
    {
        $this->bookOutputVat(5000.00, self::YEAR . '-03-10', 910011);

        $result = $this->trigger->onSubmissionFiled([
            'id' => 1, 'supplier_id' => $this->supplierId, 'form_code' => 'dphkh1',
            'period_year' => self::YEAR, 'period_month' => 3, 'period_quarter' => null,
            'form_variant' => 'B', 'submitted_at' => null,
        ]);

        self::assertNull($result);
        self::assertSame(0, $this->clearingEntryCount());
    }

    /**
     * Dodatečné přiznání (§141) doklad přepočítá ZNOVU — žádná zvláštní větev,
     * ptá se na aktuální obrat období, ne na rozdíl vykázaný v přiznání.
     */
    public function testAmendedReturnRefreshesTheClearingAgain(): void
    {
        $this->bookOutputVat(1000.00, self::YEAR . '-05-10', 910021);
        $first = $this->makeSubmission(5);
        $this->archiver->markSubmitted($first, $this->supplierId, self::YEAR . '-06-20 09:00:00', 'REF-B', null);
        $entryId = (int) $this->clearing->status($this->supplierId, self::YEAR, 5)['entry_id'];

        // Účetní vrátí zámek zpět, opraví doklad a podá DODATEČNÉ přiznání.
        $this->settings->setLockedUntil($this->supplierId, null);
        $this->bookOutputVat(400.00, self::YEAR . '-05-20', 910022);
        $second = $this->makeSubmission(5, 'D');
        $this->archiver->markSubmitted($second, $this->supplierId, self::YEAR . '-07-01 09:00:00', 'REF-D', null);

        $status = $this->clearing->status($this->supplierId, self::YEAR, 5);
        self::assertSame(VatClearingService::FRESHNESS_OK, $status['freshness']);
        self::assertEqualsWithDelta(1400.00, $status['posted']['output_vat'], 0.001, 'Dodatečné přiznání doklad přepočítalo.');
        self::assertSame($entryId, (int) $status['entry_id'], 'Přepočet drží tentýž zápis.');
        self::assertSame($second, $status['run']['submission_id'], 'Poslední podání vyhrává.');
        self::assertSame('D', $status['run']['submission_variant']);
        self::assertSame(1, $this->clearingEntryCount());
    }

    /**
     * Zúčtování se počítá PŘED posunem daňového zámku. Kdyby bylo pořadí obrácené,
     * doklad by se do právě zamčeného období už nikdy nedostal (`date_locked`).
     */
    public function testClearingIsPostedBeforeTheTaxLockAdvances(): void
    {
        $this->bookOutputVat(2500.00, self::YEAR . '-02-10', 910031);
        $submissionId = $this->makeSubmission(2);

        $this->archiver->markSubmitted($submissionId, $this->supplierId, self::YEAR . '-03-20 09:00:00', 'REF-2', null);

        self::assertSame(
            self::YEAR . '-02-28',
            $this->settings->getLockedUntil($this->supplierId),
            'Podání posunulo zámek na konec vykázaného období.',
        );
        $status = $this->clearing->status($this->supplierId, self::YEAR, 2);
        self::assertNotNull($status['entry_id'], 'Doklad stihl vzniknout ještě před zámkem.');
        self::assertFalse($status['writable'], 'Po zámku už se do období nesmí.');
        self::assertSame('date_locked', $status['writable_reason']);
    }

    /** Podání se nesmí shodit kvůli zúčtování — zamčené období se jen ohlásí. */
    public function testFilingIntoLockedPeriodReportsInsteadOfFailing(): void
    {
        $this->bookOutputVat(700.00, self::YEAR . '-02-10', 910041);
        $this->settings->setLockedUntil($this->supplierId, self::YEAR . '-02-28');
        $submissionId = $this->makeSubmission(2);

        $row = $this->archiver->markSubmitted($submissionId, $this->supplierId, self::YEAR . '-03-20 09:00:00', 'REF-3', null);

        self::assertNotNull($row);
        self::assertSame('submitted', (string) $row['status'], 'Podání proběhlo.');
        self::assertSame('skipped', $row['vat_clearing']['status'] ?? null);
        self::assertSame('date_locked', $row['vat_clearing']['reason'] ?? null);
        self::assertSame(0, $this->clearingEntryCount(), 'Do zamčeného období se nezapisuje.');
    }

    /**
     * Koncept přiznání UŽ EXISTUJÍCÍ doklad obnoví, ale nový NEZALOŽÍ — archivace
     * snímku není podání (§2.4) a účetní zápis z pouhého stažení náhledu vzniknout nesmí.
     */
    public function testDraftRefreshesExistingClearingButNeverCreatesOne(): void
    {
        $this->bookOutputVat(800.00, self::YEAR . '-04-10', 910051);

        $noEntry = $this->trigger->onSubmissionDrafted([
            'id' => $this->makeSubmission(4), 'supplier_id' => $this->supplierId, 'form_code' => 'dphdp3',
            'period_year' => self::YEAR, 'period_month' => 4, 'period_quarter' => null,
            'form_variant' => 'B', 'submitted_at' => null,
        ]);
        self::assertNull($noEntry, 'Koncept sám doklad nezakládá.');
        self::assertSame(0, $this->clearingEntryCount());

        // Teď doklad existuje (ruční spuštění) a do období přibude opožděná daň.
        $this->clearing->postForPeriod($this->supplierId, self::YEAR, 4, ['trigger' => VatClearingService::TRIGGER_MANUAL]);
        $this->bookOutputVat(200.00, self::YEAR . '-04-25', 910052);
        self::assertSame(VatClearingService::FRESHNESS_STALE, $this->clearing->status($this->supplierId, self::YEAR, 4)['freshness']);

        $this->trigger->onSubmissionDrafted([
            'id' => $this->makeSubmission(4), 'supplier_id' => $this->supplierId, 'form_code' => 'dphdp3',
            'period_year' => self::YEAR, 'period_month' => 4, 'period_quarter' => null,
            'form_variant' => 'B', 'submitted_at' => null,
        ]);

        $status = $this->clearing->status($this->supplierId, self::YEAR, 4);
        self::assertSame(VatClearingService::FRESHNESS_OK, $status['freshness'], 'Koncept existující doklad srovnal.');
        self::assertEqualsWithDelta(1000.00, $status['posted']['output_vat'], 0.001);
        self::assertSame(1, $this->clearingEntryCount());
    }

    // ── 2) zastaralost ────────────────────────────────────────────────────────

    /** Opožděný doklad v už zúčtovaném období = zastaralé zúčtování, a je to vidět. */
    public function testLateDocumentMakesTheClearingStale(): void
    {
        $this->bookOutputVat(1000.00, self::YEAR . '-06-10', 910061);
        $this->clearing->postForPeriod($this->supplierId, self::YEAR, 6, ['trigger' => VatClearingService::TRIGGER_CRON]);
        self::assertSame(VatClearingService::FRESHNESS_OK, $this->clearing->status($this->supplierId, self::YEAR, 6)['freshness']);

        $this->bookOutputVat(333.00, self::YEAR . '-06-28', 910062);

        $status = $this->clearing->status($this->supplierId, self::YEAR, 6);
        self::assertSame(VatClearingService::FRESHNESS_STALE, $status['freshness']);
        self::assertEqualsWithDelta(1333.00, $status['output_vat'], 0.001, 'Dnešní obrat.');
        self::assertEqualsWithDelta(1000.00, $status['posted']['output_vat'], 0.001, 'Co na dokladu leží.');

        $stale = $this->clearing->staleForRange($this->supplierId, self::YEAR . '-06-01', self::YEAR . '-06-30');
        self::assertCount(1, $stale);
        self::assertSame('06/' . self::YEAR, $stale[0]['doc_no']);
        self::assertTrue($stale[0]['writable']);
    }

    /** Období s nepřevedenou daní a bez dokladu se hlásí jako chybějící. */
    public function testUnclearedPeriodWithoutDocumentIsReportedAsMissing(): void
    {
        $this->bookOutputVat(4200.00, self::YEAR . '-07-10', 910071);

        $stale = $this->clearing->staleForRange($this->supplierId, self::YEAR . '-07-01', self::YEAR . '-07-31');

        self::assertCount(1, $stale);
        self::assertSame(VatClearingService::FRESHNESS_MISSING, $stale[0]['freshness']);
        self::assertNull($stale[0]['entry_id']);
        self::assertSame('journal_entry', $stale[0]['doc_type'], 'Nález musí projít CheckFindingNormalizerem.');
    }

    /**
     * Období převzaté z jiného programu se nehlásí: DPH v něm vypořádal zdroj po svém
     * (POHODA dvěma doklady za rok) a zúčtovací doklad MyÚčta by převzatý deník rozbil.
     * Období po konci převodu se hlásí dál.
     */
    public function testPeriodTakenOverFromAnotherProgramIsNotReported(): void
    {
        $this->bookOutputVat(4200.00, self::YEAR . '-07-10', 910073);
        $this->bookOutputVat(1500.00, self::YEAR . '-08-10', 910074);
        $entryId = (int) $this->db->pdo()->query(
            "SELECT id FROM journal_entries WHERE supplier_id = {$this->supplierId} AND source_type = 'manual' AND source_id = 910073"
        )->fetchColumn();
        $this->db->pdo()->prepare(
            "INSERT INTO pohoda_import_map (supplier_id, kind, pohoda_key, target_id) VALUES (?, 'journal_entry', 'test|910073', ?)"
        )->execute([$this->supplierId, $entryId]);

        self::assertSame([], $this->clearing->staleForRange($this->supplierId, self::YEAR . '-07-01', self::YEAR . '-07-31'));
        $stale = $this->clearing->staleForRange($this->supplierId, self::YEAR . '-07-01', self::YEAR . '-08-31');
        self::assertCount(1, $stale);
        self::assertSame('08/' . self::YEAR, $stale[0]['doc_no']);
    }

    /**
     * Haléřový zbytek po RUČNÍM vyrovnání období není zastaralé zúčtování, ale
     * zaokrouhlení. Bez prahu materiality by v uzávěrce trvale svítil nález,
     * se kterým nejde nic udělat.
     */
    public function testRoundingResidueAfterManualClearingIsNotReported(): void
    {
        $this->bookOutputVat(1000.40, self::YEAR . '-08-10', 910081);
        // Účetní si období vyrovnala ručně, zaokrouhleně na celé koruny.
        $this->posting->postDocument($this->supplierId, 'manual', 910082, [
            ['account_code' => '343.200', 'side' => 'debit', 'amount' => 1000.00],
            ['account_code' => '343.900', 'side' => 'credit', 'amount' => 1000.00],
        ], ['entry_date' => self::YEAR . '-08-31', 'description' => 'Ruční zúčtování DPH']);

        $status = $this->clearing->status($this->supplierId, self::YEAR, 8);

        self::assertEqualsWithDelta(0.40, $status['output_vat'], 0.001, 'Zbytek je 40 haléřů.');
        self::assertSame(VatClearingService::FRESHNESS_OK, $status['freshness']);
        self::assertSame([], $this->clearing->staleForRange($this->supplierId, self::YEAR . '-08-01', self::YEAR . '-08-31'));
    }

    /**
     * Probíhající zdaňovací období se nehlásí. U ČTVRTLETNÍHO plátce spuštěného
     * uprostřed kvartálu je rozsah kontroly jeden měsíc, ale zdaňovací období celé
     * čtvrtletí — bez téhle podmínky by měsíční kontrola hlásila nález pokaždé.
     */
    public function testRunningPeriodIsNotReportedAsMissing(): void
    {
        $this->db->pdo()->prepare("UPDATE supplier SET vat_period = 'quarterly' WHERE id = ?")
            ->execute([$this->supplierId]);
        $this->bookOutputVat(3000.00, self::YEAR . '-01-15', 910131);

        // „Dnes" je uprostřed Q1 — čtvrtletí ještě neskončilo, doklad se dělat nemá.
        $midQuarter = new \DateTimeImmutable(self::YEAR . '-02-10');
        self::assertSame(
            [],
            $this->clearing->staleForRange($this->supplierId, self::YEAR . '-02-01', self::YEAR . '-02-28', $midQuarter),
        );

        // Po konci čtvrtletí už nález dává smysl.
        $afterQuarter = new \DateTimeImmutable(self::YEAR . '-04-10');
        $stale = $this->clearing->staleForRange($this->supplierId, self::YEAR . '-02-01', self::YEAR . '-02-28', $afterQuarter);
        self::assertCount(1, $stale);
        self::assertSame('Q1/' . self::YEAR, $stale[0]['doc_no']);
    }

    /** Zavřené období se NEPŘEÚČTOVÁVÁ — jen se ohlásí, že se samo neopraví. */
    public function testClosedPeriodIsReportedNotReposted(): void
    {
        $this->bookOutputVat(1500.00, self::YEAR . '-09-10', 910091);
        $period = $this->periods->findForDate($this->supplierId, self::YEAR . '-09-30');
        $this->periods->setStatus((int) $period['id'], $this->supplierId, 'approved');

        $stale = $this->clearing->staleForRange($this->supplierId, self::YEAR . '-09-01', self::YEAR . '-09-30');
        self::assertCount(1, $stale);
        self::assertFalse($stale[0]['writable']);
        self::assertSame('period_not_open', $stale[0]['writable_reason']);

        $this->expectException(PostingException::class);
        $this->clearing->postForPeriod($this->supplierId, self::YEAR, 9);
    }

    // ── 3) přepis, nikdy storno ───────────────────────────────────────────────

    /**
     * Aktualizace dokladu jde PŘEPISEM NA MÍSTĚ. Po změně v období musí v deníku
     * zůstat právě JEDEN zápis `vat_clearing` za období, ŽÁDNÝ stornovaný, a zápis
     * si musí zachovat `id`, aby na něj navázané odkazy nepadly.
     */
    public function testRecomputeRewritesInPlaceAndNeverReverses(): void
    {
        $this->bookOutputVat(1000.00, self::YEAR . '-10-10', 910101);
        $first = $this->clearing->postForPeriod($this->supplierId, self::YEAR, 10);
        $originalId = (int) $first['entry_id'];

        $this->bookOutputVat(250.00, self::YEAR . '-10-20', 910102);
        $second = $this->clearing->postForPeriod($this->supplierId, self::YEAR, 10);

        self::assertSame($originalId, (int) $second['entry_id'], 'Zápis si zachoval id.');
        self::assertSame(1, $this->clearingEntryCount(), 'Právě jeden zápis za období.');
        self::assertSame(0, $this->reversedCount(), 'V deníku nezůstala stornovaná stopa.');
        self::assertEqualsWithDelta(1250.00, $second['output_vat'], 0.001);

        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ? AND description LIKE '%torno%'"
        );
        $stmt->execute([$this->supplierId]);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'Nevznikl žádný storno zápis.');
    }

    /** Vynulované období: doklad se SMAŽE, nenechává se prázdný ani stornovaný. */
    public function testZeroedPeriodDeletesTheDocument(): void
    {
        $this->bookOutputVat(600.00, self::YEAR . '-11-10', 910111);
        $posted = $this->clearing->postForPeriod($this->supplierId, self::YEAR, 11);
        self::assertSame(VatClearingService::STATUS_POSTED, $posted['status']);
        self::assertSame(1, $this->clearingEntryCount());

        // Doklad období zmizel (dobropis / přeřazení jinam) — daň za období je nulová.
        $this->posting->postDocument($this->supplierId, 'manual', 910112, [
            ['account_code' => '343.200', 'side' => 'debit', 'amount' => 600.00],
            ['account_code' => '311', 'side' => 'credit', 'amount' => 600.00],
        ], ['entry_date' => self::YEAR . '-11-20', 'description' => 'Dobropis']);

        $result = $this->clearing->postForPeriod($this->supplierId, self::YEAR, 11);

        self::assertSame(VatClearingService::STATUS_DELETED_ZERO, $result['status']);
        self::assertNull($result['entry_id']);
        self::assertSame(0, $this->clearingEntryCount(), 'Doklad je pryč, ne prázdný.');
        self::assertSame(0, $this->reversedCount(), 'A není stornovaný.');
        self::assertNull(
            $this->clearing->status($this->supplierId, self::YEAR, 11)['run'],
            'Se smazaným dokladem zmizel i záznam běhu.',
        );
    }

    /** Do zamčeného data se ani nemaže. */
    public function testZeroedPeriodInLockedDateIsNotDeleted(): void
    {
        $this->bookOutputVat(600.00, self::YEAR . '-12-10', 910121);
        $this->clearing->postForPeriod($this->supplierId, self::YEAR, 12);
        $this->posting->postDocument($this->supplierId, 'manual', 910122, [
            ['account_code' => '343.200', 'side' => 'debit', 'amount' => 600.00],
            ['account_code' => '311', 'side' => 'credit', 'amount' => 600.00],
        ], ['entry_date' => self::YEAR . '-12-20', 'description' => 'Dobropis']);
        $this->settings->setLockedUntil($this->supplierId, self::YEAR . '-12-31');

        try {
            $this->clearing->postForPeriod($this->supplierId, self::YEAR, 12);
            self::fail('Do zamčeného data se nemá mazat.');
        } catch (PostingException $e) {
            self::assertSame('date_locked', $e->errorCode);
        }
        self::assertSame(1, $this->clearingEntryCount(), 'Doklad zůstal nedotčený.');
    }

    // ── mapování období podání ────────────────────────────────────────────────

    public function testPeriodFromSubmissionMapsQuarterToItsLastMonth(): void
    {
        self::assertSame([2026, 6], VatClearingService::periodFromSubmission(
            ['period_year' => 2026, 'period_month' => null, 'period_quarter' => 2],
        ));
        self::assertSame([2026, 5], VatClearingService::periodFromSubmission(
            ['period_year' => 2026, 'period_month' => 5, 'period_quarter' => null],
        ));
        self::assertNull(
            VatClearingService::periodFromSubmission(['period_year' => 2026, 'period_month' => null, 'period_quarter' => null]),
            'Roční výkaz nemá jednoznačné zdaňovací období.',
        );
    }
}
