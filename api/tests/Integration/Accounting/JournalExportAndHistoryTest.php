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
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\JournalExportService;
use MyInvoice\Service\Accounting\Reports\ReportXlsxExporter;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * D7 (audit 2026-07) — tři funkce nad účetním deníkem:
 *  1) export deníku PDF/XLSX respektující aktuální filtry (§13 ZoÚ),
 *  2) sloupec Částka (Σ MD) + drill-down na banku/pokladnu v listu,
 *  3) auditní historie zápisu ze SYSTEM VERSIONING (FOR SYSTEM_TIME ALL).
 *
 * Vše v jedné transakci, tearDown rollbackne. Soft-skip bez cfg.php.
 */
#[Group('integration')]
final class JournalExportAndHistoryTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const YEAR = 2099;

    private Connection $db;
    private JournalAction $journalAction;
    private JournalEntryRepository $journalRepo;
    private AccountingPeriodRepository $periods;
    private PostingService $posting;
    private JournalExportService $journalExport;
    private ReportXlsxExporter $xlsx;

    private int $supplierId = 0;
    private int $userId = 0;
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
            $this->db            = $container->get(Connection::class);
            $this->journalAction = $container->get(JournalAction::class);
            $this->journalRepo   = $container->get(JournalEntryRepository::class);
            $this->periods       = $container->get(AccountingPeriodRepository::class);
            $this->posting       = $container->get(PostingService::class);
            $this->journalExport = $container->get(JournalExportService::class);
            $this->xlsx           = $container->get(ReportXlsxExporter::class);
            $seeder               = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);

        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/user) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);

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

    // ── D7/2: sloupec Částka + drill-down bank/cash v list() ────────────────

    public function testListIncludesAmountPerEntry(): void
    {
        $entryId = $this->manualEntry('X', self::YEAR . '-06-15', 1234.56);

        $res = $this->call('list', 'GET', 'accountant');
        self::assertSame(200, $res['status']);
        $row = $this->findItem($res['body']['items'], $entryId);
        self::assertNotNull($row, 'Nově vytvořený zápis musí být v listu.');
        self::assertEqualsWithDelta(1234.56, (float) $row['amount'], 0.001, 'amount = Σ MD řádků zápisu.');
    }

    /**
     * Regresní test na nález „ČÁSTKA u filtru na účet": zápis s nohou nákladu i nohou
     * zúčtování zálohy na RŮZNÝCH účtech — filtr na jeden z nich musí ukázat částku
     * TOHO účtu (a jeho stranu MD/D), ne Σ MD celého zápisu (dřív AMOUNT_SUBQUERY
     * sčítalo všechny debetní řádky bez ohledu na filtrovaný účet).
     */
    public function testListAccountFilteredAmountShowsAccountPortionNotEntryTotal(): void
    {
        $entryId = $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '518', 'side' => 'debit', 'amount' => 500.0],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 300.0],
            ['account_code' => '314', 'side' => 'credit', 'amount' => 200.0],
        ], ['entry_date' => self::YEAR . '-04-01', 'user_id' => $this->userId, 'posted_by' => $this->userId]);

        // Bez filtru na účet — beze změny oproti dosavadnímu chování: Σ MD celého zápisu.
        $all = $this->call('list', 'GET', 'accountant');
        $rowAll = $this->findItem($all['body']['items'], $entryId);
        self::assertNotNull($rowAll);
        self::assertEqualsWithDelta(500.0, (float) $rowAll['amount'], 0.001, 'Bez filtru: Σ MD celého zápisu (dosavadní chování).');
        self::assertNull($rowAll['amount_side'] ?? null, 'Bez filtru na účet strana nic neurčuje.');

        // Filtr na účet 314 (jen jedna noha zápisu, 200 Kč) — MUSÍ vrátit 200, ne 500.
        $filtered314 = $this->call('list', 'GET', 'accountant', [], [], [], ['account_from' => '314', 'account_to' => '314']);
        $row314 = $this->findItem($filtered314['body']['items'], $entryId);
        self::assertNotNull($row314, 'Zápis má nohu na 314, musí projít filtrem.');
        self::assertEqualsWithDelta(200.0, (float) $row314['amount'], 0.001, 'Filtrováno na 314: jen částka TÉTO nohy, ne Σ MD celého zápisu.');
        self::assertSame('credit', $row314['amount_side']);

        // Filtr na druhou nohu (321, jiná částka) potvrzuje, že se nepočítá z celku.
        $filtered321 = $this->call('list', 'GET', 'accountant', [], [], [], ['account_from' => '321', 'account_to' => '321']);
        $row321 = $this->findItem($filtered321['body']['items'], $entryId);
        self::assertNotNull($row321);
        self::assertEqualsWithDelta(300.0, (float) $row321['amount'], 0.001);
        self::assertSame('credit', $row321['amount_side']);

        // Filtr na debetní nohu (518) — MD strana, plná částka 500.
        $filtered518 = $this->call('list', 'GET', 'accountant', [], [], [], ['account_from' => '518', 'account_to' => '518']);
        $row518 = $this->findItem($filtered518['body']['items'], $entryId);
        self::assertNotNull($row518);
        self::assertEqualsWithDelta(500.0, (float) $row518['amount'], 0.001);
        self::assertSame('debit', $row518['amount_side']);
    }

    /**
     * Edge case: filtrovaný účet se v JEDNOM zápisu objeví na obou stranách (korekce).
     * Sloupec Částka ukáže NETTO (Σ MD − Σ D na filtrovaném účtu) a stranu podle
     * znaménka — jinak by dvě protichůdné nohy stejného účtu ukázaly zavádějící součet.
     */
    public function testListAccountFilteredAmountNetsBothSidesOfSameAccountInOneEntry(): void
    {
        $entryId = $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '211', 'side' => 'debit', 'amount' => 1000.0],
            ['account_code' => '211', 'side' => 'credit', 'amount' => 400.0],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 600.0],
        ], ['entry_date' => self::YEAR . '-04-02', 'user_id' => $this->userId, 'posted_by' => $this->userId]);

        $res = $this->call('list', 'GET', 'accountant', [], [], [], ['account_from' => '211', 'account_to' => '211']);
        $row = $this->findItem($res['body']['items'], $entryId);
        self::assertNotNull($row);
        self::assertEqualsWithDelta(600.0, (float) $row['amount'], 0.001, 'Netto na 211: 1000 MD - 400 D = 600.');
        self::assertSame('debit', $row['amount_side']);
    }

    public function testFulltextRecognizesExistingAccountCodeAndKeepsUnknownCodeAsText(): void
    {
        $parentId = (int) $this->db->pdo()->query(
            "SELECT id FROM chart_of_accounts WHERE supplier_id = {$this->supplierId} AND account_code = '221'"
        )->fetchColumn();
        $this->db->pdo()->prepare(
            "INSERT INTO chart_of_accounts
                (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id, is_active)
             VALUES (?, '221.400', 'Testovací bankovní účet', 'asset', 'debit', 0, ?, 1)"
        )->execute([$this->supplierId, $parentId]);

        $matchedId = $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '221.400', 'side' => 'debit', 'amount' => 432.10],
            ['account_code' => '311', 'side' => 'credit', 'amount' => 432.10],
        ], [
            'entry_date' => self::YEAR . '-04-03',
            'description' => 'Pohyb na analytickém účtu',
            'user_id' => $this->userId,
            'posted_by' => $this->userId,
        ]);
        $otherId = $this->manualEntry('Jiný zápis', self::YEAR . '-04-04', 50.0);

        $byAccount = $this->call('list', 'GET', 'accountant', [], [], [], ['q' => '221.400']);
        self::assertNotNull($this->findItem($byAccount['body']['items'], $matchedId));
        self::assertNull($this->findItem($byAccount['body']['items'], $otherId));
        $accountRow = $this->findItem($byAccount['body']['items'], $matchedId);
        self::assertEqualsWithDelta(432.10, (float) $accountRow['amount'], 0.001);
        self::assertSame('debit', $accountRow['amount_side']);

        $withoutSeparator = $this->call('list', 'GET', 'accountant', [], [], [], ['q' => '221400']);
        self::assertNotNull($this->findItem($withoutSeparator['body']['items'], $matchedId));

        $textId = $this->manualEntry('Kontrola účtu 999.999', self::YEAR . '-04-05', 60.0);
        $asText = $this->call('list', 'GET', 'accountant', [], [], [], ['q' => '999.999']);
        self::assertNotNull($this->findItem($asText['body']['items'], $textId));

        $explicitTextId = $this->manualEntry('Textový odkaz 221.400', self::YEAR . '-04-06', 70.0);
        $withExplicitRange = $this->call('list', 'GET', 'accountant', [], [], [], [
            'q' => '221.400',
            'account_from' => '211',
        ]);
        self::assertNotNull($this->findItem($withExplicitRange['body']['items'], $explicitTextId));
        self::assertNull($this->findItem($withExplicitRange['body']['items'], $matchedId));
    }

    public function testListEnrichesBankDrillDownWithStatementId(): void
    {
        $statementId = $this->bankStatement();
        $txId = $this->bankTransaction($statementId, 500.00);

        $entryId = $this->posting->postDocument($this->supplierId, 'bank', $txId, [
            ['account_code' => '221', 'side' => 'debit', 'amount' => 500.00],
            ['account_code' => '311', 'side' => 'credit', 'amount' => 500.00],
        ], ['entry_date' => self::YEAR . '-03-01', 'user_id' => $this->userId, 'posted_by' => $this->userId]);

        $res = $this->call('list', 'GET', 'accountant', [], [], [], ['source_type' => 'bank']);
        $row = $this->findItem($res['body']['items'], $entryId);
        self::assertNotNull($row);
        self::assertSame($statementId, (int) $row['source_statement_id'], 'Deník musí umět drill-down na bankovní výpis (statement_id, ne transaction_id).');
    }

    public function testListEnrichesCashDrillDownWithDocNumberAndRegister(): void
    {
        $registerId = $this->cashRegister();
        $docId = $this->cashDocument($registerId, 'PD-2099-001');

        $entryId = $this->posting->postDocument($this->supplierId, 'cash', $docId, [
            ['account_code' => '211', 'side' => 'debit', 'amount' => 200.00],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 200.00],
        ], ['entry_date' => self::YEAR . '-03-02', 'user_id' => $this->userId, 'posted_by' => $this->userId]);

        $res = $this->call('list', 'GET', 'accountant', [], [], [], ['source_type' => 'cash']);
        $row = $this->findItem($res['body']['items'], $entryId);
        self::assertNotNull($row);
        self::assertSame('PD-2099-001', $row['source_doc_number']);
        self::assertSame($registerId, (int) $row['source_register_id']);
    }

    public function testJournalExposesAndFiltersAutomationProvenance(): void
    {
        $autoEntry = $this->bankEntryWithProvenance('auto_posted', 'Automatické pravidlo');
        $approvedEntry = $this->bankEntryWithProvenance('approved', 'Potvrzené pravidlo');
        $manualEntry = $this->manualEntry('Ručně', self::YEAR . '-06-20', 90.0);

        $all = $this->call('list', 'GET', 'accountant');
        self::assertSame('auto', $this->findItem($all['body']['items'], $autoEntry)['automation']['mode']);
        self::assertSame('Automatické pravidlo', $this->findItem($all['body']['items'], $autoEntry)['automation']['rule_name']);
        self::assertSame('approved', $this->findItem($all['body']['items'], $approvedEntry)['automation']['mode']);
        self::assertNotEmpty($this->findItem($all['body']['items'], $approvedEntry)['automation']['decided_by']);
        self::assertNull($this->findItem($all['body']['items'], $manualEntry)['automation']);

        $detail = $this->call('get', 'GET', 'accountant', ['id' => (string) $autoEntry]);
        self::assertSame(200, $detail['status']);
        self::assertSame('rule', $detail['body']['automation']['source']);

        $auto = $this->call('list', 'GET', 'accountant', [], [], [], ['automation' => 'auto']);
        self::assertNotNull($this->findItem($auto['body']['items'], $autoEntry));
        self::assertNull($this->findItem($auto['body']['items'], $approvedEntry));
        self::assertNull($this->findItem($auto['body']['items'], $manualEntry));

        $approved = $this->call('list', 'GET', 'accountant', [], [], [], ['automation' => 'approved']);
        self::assertNotNull($this->findItem($approved['body']['items'], $approvedEntry));
        self::assertNull($this->findItem($approved['body']['items'], $autoEntry));

        $manual = $this->call('list', 'GET', 'accountant', [], [], [], ['automation' => 'manual']);
        self::assertNotNull($this->findItem($manual['body']['items'], $manualEntry));
        self::assertNull($this->findItem($manual['body']['items'], $autoEntry));
        self::assertNull($this->findItem($manual['body']['items'], $approvedEntry));

        $export = $this->journalExport->build($this->supplierId, []);
        $origins = [];
        foreach ($export['entries'] as $entry) {
            $origins[(int) $entry['id']] = $entry['automation_origin'];
        }
        self::assertSame('automaticky', $origins[$autoEntry]);
        self::assertSame('potvrzeno', $origins[$approvedEntry]);
        self::assertSame('ručně', $origins[$manualEntry]);
    }

    // ── D7/1: export PDF/XLSX respektuje filtry ──────────────────────────────

    public function testExportPdfRespectsDateFilterAndLogsAudit(): void
    {
        $this->manualEntry('V lednu', self::YEAR . '-01-10', 1000.00);
        $this->manualEntry('V červnu', self::YEAR . '-06-15', 2000.00);

        $res = $this->call('export', 'GET', 'accountant', [], [], [], [
            'date_from' => self::YEAR . '-01-01', 'date_to' => self::YEAR . '-01-31', 'format' => 'pdf',
        ]);
        self::assertSame(200, $res['status']);
        self::assertStringContainsString('application/pdf', $res['contentType']);
        self::assertGreaterThan(1000, strlen($res['raw']), 'PDF musí mít reálný obsah, ne prázdný soubor.');
        self::assertStringStartsWith('%PDF', $res['raw']);

        $audit = $this->db->pdo()->query(
            "SELECT payload FROM activity_log
              WHERE supplier_id = {$this->supplierId} AND action = 'report.accounting_export'
              ORDER BY id DESC LIMIT 1"
        )->fetch(\PDO::FETCH_ASSOC);
        self::assertNotFalse($audit, 'Export musí být auditovaný.');
        $payload = json_decode((string) $audit['payload'], true);
        self::assertSame('journal', $payload['report']);
        self::assertSame('pdf', $payload['format']);
    }

    public function testExportXlsxReturnsSpreadsheet(): void
    {
        $this->manualEntry('X', self::YEAR . '-06-15', 500.00);

        $res = $this->call('export', 'GET', 'accountant', [], [], [], ['format' => 'xlsx']);
        self::assertSame(200, $res['status']);
        self::assertStringContainsString('spreadsheetml', $res['contentType']);
        self::assertGreaterThan(500, strlen($res['raw']));
    }

    /**
     * Regresní test na task #18: export deníku měl STEJNOU chybu částky, jaká byla
     * opravena u list() (nález „ČÁSTKA u filtru na účet"). forExport() dřív pořád
     * počítal AMOUNT_SUBQUERY (Σ MD celého zápisu) bez ohledu na filtr účtu, takže
     * export s filtrem na účet vypsal cizí číslo — reálný dopad: řádek „Otevření
     * účetních knih" patnáct milionů místo tisícovky. Zápis s nohou nákladu i nohou
     * zúčtování zálohy na RŮZNÝCH účtech: filtr na jeden z nich musí ve forExport()
     * vrátit částku TOHO účtu (a jeho stranu), ne Σ MD celého zápisu.
     */
    public function testForExportAccountFilteredAmountShowsAccountPortionNotEntryTotal(): void
    {
        $entryId = $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '518', 'side' => 'debit', 'amount' => 500.0],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 300.0],
            ['account_code' => '314', 'side' => 'credit', 'amount' => 200.0],
        ], ['entry_date' => self::YEAR . '-04-03', 'user_id' => $this->userId, 'posted_by' => $this->userId]);

        // Bez filtru na účet — beze změny: Σ MD celého zápisu.
        $all = $this->journalRepo->forExport($this->supplierId, ['entry_id' => $entryId], 10);
        $rowAll = $this->findItem($all, $entryId);
        self::assertNotNull($rowAll);
        self::assertEqualsWithDelta(500.0, (float) $rowAll['amount'], 0.001, 'Bez filtru na účet: Σ MD celého zápisu (dosavadní chování).');
        self::assertNull($rowAll['amount_side'] ?? null, 'Bez filtru na účet strana nic neurčuje.');

        // Filtr na účet 314 (jen jedna noha zápisu, 200 Kč) — export MUSÍ vrátit 200, ne 500.
        $filtered = $this->journalRepo->forExport(
            $this->supplierId,
            ['entry_id' => $entryId, 'account_from' => '314', 'account_to' => '314'],
            10,
        );
        $row314 = $this->findItem($filtered, $entryId);
        self::assertNotNull($row314, 'Zápis má nohu na 314, musí projít filtrem i v exportu.');
        self::assertEqualsWithDelta(200.0, (float) $row314['amount'], 0.001, 'Export filtrovaný na 314: jen částka TÉTO nohy, ne Σ MD celého zápisu.');
        self::assertSame('credit', $row314['amount_side']);
    }

    /**
     * Stejný nález, ale end-to-end přes XLSX exportér (JournalExportService::build()
     * → ReportXlsxExporter::journal()): souhrnný řádek zápisu s filtrem na účet
     * NESMÍ dublovat NETTO částku filtrovaného účtu do obou sloupců MD i Dal —
     * to by tvrdilo Σ MD = Σ Dal i pro jeden účet, což u víc-nohého zápisu neplatí
     * (na rozdíl od nefiltrovaného řádku, kde je duplikace správná, protože
     * u vyváženého zápisu Σ MD = Σ Dal). Musí jít jen do sloupce odpovídajícího
     * straně (zde: kreditní noha 314 → sloupec Dal, MD prázdný).
     */
    public function testExportXlsxAccountFilteredEntryRowShowsAccountAmountInCorrectColumnOnly(): void
    {
        $entryId = $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '518', 'side' => 'debit', 'amount' => 500.0],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 300.0],
            ['account_code' => '314', 'side' => 'credit', 'amount' => 200.0],
        ], ['entry_date' => self::YEAR . '-04-04', 'description' => 'XLSX filtr na ucet', 'user_id' => $this->userId, 'posted_by' => $this->userId]);

        $data = $this->journalExport->build($this->supplierId, [
            'entry_id' => $entryId, 'account_from' => '314', 'account_to' => '314',
        ]);
        $out = $this->xlsx->journal($data);

        $path = tempnam(sys_get_temp_dir(), 'jexp');
        self::assertNotFalse($path);
        file_put_contents($path, $out['bytes']);
        $sheet = IOFactory::load($path)->getActiveSheet();
        unlink($path);

        // Hlavičkový řádek jediného zápisu: head=5 (viz ReportXlsxExporter::journal()) → r=6.
        $mdCell = $sheet->getCell('G6')->getValue();
        $dalCell = $sheet->getCell('H6')->getValue();

        self::assertTrue($mdCell === null || $mdCell === 0, 'Filtrováno na kreditní nohu 314 — sloupec MD nesmí dublovat 500 (Σ MD celého zápisu).');
        self::assertEqualsWithDelta(200.0, (float) $dalCell, 0.001, 'Sloupec Dal ukazuje NETTO částku filtrovaného účtu (200), ne Σ MD celého zápisu (500).');
    }

    /**
     * PDF jde přes stejnou cestu (JournalExportService::build() → journal.twig),
     * jen s jinou šablonou — nález i oprava jsou identické jako u XLSX. Test
     * ověřuje aspoň, že export s filtrem na účet (amount_side != null) neshodí
     * renderer (šablona musí umět zpracovat i tuhle větev podmínky).
     */
    public function testExportPdfWithAccountFilterRendersSuccessfully(): void
    {
        $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '518', 'side' => 'debit', 'amount' => 500.0],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 300.0],
            ['account_code' => '314', 'side' => 'credit', 'amount' => 200.0],
        ], ['entry_date' => self::YEAR . '-04-05', 'user_id' => $this->userId, 'posted_by' => $this->userId]);

        $res = $this->call('export', 'GET', 'accountant', [], [], [], [
            'format' => 'pdf', 'account_from' => '314', 'account_to' => '314',
        ]);
        self::assertSame(200, $res['status']);
        self::assertStringStartsWith('%PDF', $res['raw']);
    }

    public function testExportRejectsInvalidFormat(): void
    {
        $res = $this->call('export', 'GET', 'accountant', [], [], [], ['format' => 'csv']);
        self::assertSame(422, $res['status']);
        self::assertSame('validation_failed', $res['body']['error']['code']);
    }

    public function testForExportSignalsWhenLimitExceeded(): void
    {
        // Repository-level test mechanismu detekce překročení stropu (JournalExportService
        // limit je 5000 — netestovatelné rychle přes 5001 reálných zápisů). forExport()
        // vrací max $limit+1 řádků, takže volající pozná překročení bez druhého COUNT dotazu.
        $this->manualEntry('A', self::YEAR . '-01-05', 10.0);
        $this->manualEntry('B', self::YEAR . '-01-06', 20.0);

        $rows = $this->journalRepo->forExport($this->supplierId, [], 1);
        self::assertCount(2, $rows, 'limit=1, ale existují 2 zápisy → vrátí limit+1=2, aby volající poznal překročení.');
    }

    // ── D7/3: historie ze SYSTEM VERSIONING ──────────────────────────────────

    public function testHistorySingleVersionForNeverRepostedEntry(): void
    {
        $entryId = $this->manualEntry('Jen jednou', self::YEAR . '-06-15', 100.0);

        $res = $this->call('history', 'GET', 'accountant', ['id' => (string) $entryId]);
        self::assertSame(200, $res['status']);
        self::assertCount(1, $res['body']['versions']);
        self::assertTrue($res['body']['versions'][0]['is_current']);
        self::assertNull($res['body']['versions'][0]['header_changes']);
        self::assertNull($res['body']['versions'][0]['line_changes']);
    }

    public function testHistoryDiffsRepostedEntry(): void
    {
        $sourceId = 777001;
        $entryId = $this->posting->postDocument($this->supplierId, 'manual', $sourceId, [
            ['account_code' => '211', 'side' => 'debit', 'amount' => 1000.0],
            ['account_code' => '221', 'side' => 'credit', 'amount' => 1000.0],
        ], ['entry_date' => self::YEAR . '-05-10', 'description' => 'Prvni verze', 'user_id' => $this->userId, 'posted_by' => $this->userId]);

        $entryId2 = $this->posting->postDocument($this->supplierId, 'manual', $sourceId, [
            ['account_code' => '211', 'side' => 'debit', 'amount' => 1500.0],
            ['account_code' => '221', 'side' => 'credit', 'amount' => 1500.0],
        ], ['entry_date' => self::YEAR . '-05-10', 'description' => 'Druha verze', 'user_id' => $this->userId, 'posted_by' => $this->userId]);
        self::assertSame($entryId, $entryId2, 'Re-post stejného zdroje přepíše týž zápis in-place (idempotence).');

        $res = $this->call('history', 'GET', 'accountant', ['id' => (string) $entryId]);
        self::assertSame(200, $res['status']);
        $versions = $res['body']['versions'];
        self::assertCount(2, $versions, 'Re-post vytvoří druhou SYSTEM VERSIONING verzi.');

        // Nejnovější verze první.
        $current = $versions[0];
        self::assertTrue($current['is_current']);
        self::assertSame('Druha verze', $current['header']['description']);
        self::assertSame('Prvni verze', $current['header_changes']['description']['before']);
        self::assertSame('Druha verze', $current['header_changes']['description']['after']);

        self::assertCount(2, $current['line_changes'], 'Obě řádky (MD i Dal) se změnily z 1000 na 1500.');
        foreach ($current['line_changes'] as $change) {
            self::assertSame('changed', $change['type']);
            self::assertEqualsWithDelta(1000.0, (float) $change['before']['amount'], 0.001);
            self::assertEqualsWithDelta(1500.0, (float) $change['after']['amount'], 0.001);
        }

        $original = $versions[1];
        self::assertFalse($original['is_current']);
        self::assertNull($original['header_changes']);
        self::assertNull($original['line_changes']);
        self::assertCount(2, $original['lines']);
    }

    public function testHistoryCarriesForwardLinesOnDescriptionOnlyEdit(): void
    {
        // Regresní test pro chybu odhalenou smoke testem: §35 inline editace description
        // (JournalEntryRepository::updateDescription) journal_entry_lines VŮBEC nemění —
        // naivní časové okno (řádek patří jen do verze, do jejíhož okna spadá JEHO
        // vlastní valid_from) by u druhé verze vrátilo 0 řádků. Řádky se musí přenést
        // z předchozí verze beze změny.
        $entryId = $this->manualEntry('Puvodni popis', self::YEAR . '-06-15', 100.0);
        $this->journalRepo->updateDescription($entryId, $this->supplierId, 'Novy popis', $this->userId);

        $res = $this->call('history', 'GET', 'accountant', ['id' => (string) $entryId]);
        self::assertSame(200, $res['status']);
        $versions = $res['body']['versions'];
        self::assertCount(2, $versions);

        $current = $versions[0];
        self::assertTrue($current['is_current']);
        self::assertCount(2, $current['lines'], 'Řádky se musí přenést z předchozí verze i po editaci, která se jich netýká.');
        self::assertSame('Novy popis', $current['header_changes']['description']['after']);
        self::assertSame([], $current['line_changes'], 'Žádný řádek se nezměnil — prázdné pole, ne chybějící řádky.');
    }

    public function testHistoryAttributesReversalCorrectlyNotToOriginalPoster(): void
    {
        // Regresní test na M-1 (adversariální review Opus): storno hned po zaúčtování
        // dřív vytvořilo verzi s PRÁZDNÝM diffem (reversed_by nebylo trackované pole)
        // a `nearestActivity()` ji časovou blízkostí chybně spárovala s `accounting.posted`
        // původního zaúčtování — verze storna se tak tvářila jako „zaúčtoval X", což byla
        // nepravda (accounting.reversed se loguje s entity_id PROTIZÁPISU, ne originálu).
        $entryId = $this->manualEntry('Puvodni', self::YEAR . '-05-10', 1000.0);

        $reverseRes = $this->call('reverse', 'POST', 'accountant', ['id' => (string) $entryId]);
        self::assertSame(201, $reverseRes['status']);
        $reversalId = (int) $reverseRes['body']['id'];

        $res = $this->call('history', 'GET', 'accountant', ['id' => (string) $entryId]);
        self::assertSame(200, $res['status']);
        $versions = $res['body']['versions'];
        self::assertCount(2, $versions, 'Storno vytvoří druhou SYSTEM VERSIONING verzi na originálu (setReversedBy).');

        $reversedVersion = $versions[0];
        self::assertNotNull($reversedVersion['header']['reversed_by'], 'Aktuální verze musí nést reversed_by.');
        self::assertSame($reversalId, (int) $reversedVersion['header']['reversed_by']);

        // Diff MUSÍ zachytit storno-přechod — dřív byl header_changes prázdný ({}).
        self::assertNotNull($reversedVersion['header_changes']);
        self::assertArrayHasKey('reversed_by', $reversedVersion['header_changes']);
        self::assertNull($reversedVersion['header_changes']['reversed_by']['before']);
        self::assertSame($reversalId, (int) $reversedVersion['header_changes']['reversed_by']['after']);

        // Atribuce MUSÍ ukazovat accounting.reversed (storno), NE accounting.posted
        // (což by naznačovalo, že tuto verzi vytvořil někdo, kdo zápis jen zaúčtoval).
        self::assertNotNull($reversedVersion['changed_by'], 'Storno je v activity_log dohledatelné přesně (entity_id protizápisu).');
        self::assertSame('accounting.reversed', $reversedVersion['changed_by']['action']);
    }

    public function testHistoryReturns404ForUnknownEntry(): void
    {
        $res = $this->call('history', 'GET', 'accountant', ['id' => '999999999']);
        self::assertSame(404, $res['status']);
        self::assertSame('not_found', $res['body']['error']['code']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function manualEntry(string $description, string $entryDate, float $amount): int
    {
        $res = $this->call('create', 'POST', 'accountant', [], [
            'entry_date'  => $entryDate,
            'description' => $description,
            'lines' => [
                ['account_code' => '211', 'side' => 'debit', 'amount' => $amount],
                ['account_code' => '602', 'side' => 'credit', 'amount' => $amount],
            ],
        ]);
        self::assertSame(201, $res['status'], 'Fixture: manuální zápis se založí.');
        return (int) $res['body']['id'];
    }

    /** @return array<string,mixed>|null */
    private function findItem(array $items, int $id): ?array
    {
        foreach ($items as $item) {
            if ((int) $item['id'] === $id) return $item;
        }
        return null;
    }

    private function bankStatement(): int
    {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO bank_statements
                (file_name, file_hash, account_number, statement_date, transaction_count, matched_count)
             VALUES (?, ?, '1000000005/0100', ?, 0, 0)"
        );
        $stmt->execute(['test-' . uniqid() . '.gpc', sha1(uniqid('', true)), self::YEAR . '-03-01']);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function bankTransaction(int $statementId, float $amount): int
    {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO bank_transactions (statement_id, posted_at, amount, currency, description)
             VALUES (?, ?, ?, 'CZK', 'Test transakce')"
        );
        $stmt->execute([$statementId, self::YEAR . '-03-01', $amount]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function bankEntryWithProvenance(string $status, string $ruleName): int
    {
        $statementId = $this->bankStatement();
        $txId = $this->bankTransaction($statementId, 100.00);
        $entryId = $this->posting->postDocument($this->supplierId, 'bank', $txId, [
            ['account_code' => '221', 'side' => 'debit', 'amount' => 100.00],
            ['account_code' => '311', 'side' => 'credit', 'amount' => 100.00],
        ], ['entry_date' => self::YEAR . '-03-01', 'user_id' => $this->userId, 'posted_by' => $this->userId]);

        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO bank_posting_rules
                (supplier_id, name, direction, counterparty_account, debit_account_code,
                 credit_account_code, mode, is_active, created_by)
             VALUES (?, ?, 'incoming', '1000000005/0100', '221', '311', 'suggest', 1, ?)"
        )->execute([$this->supplierId, $ruleName, $this->userId]);
        $ruleId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO bank_posting_suggestions
                (supplier_id, bank_transaction_id, rule_id, source, debit_account_code,
                 credit_account_code, amount, status, journal_entry_id, reviewed_by, reviewed_at)
             VALUES (?, ?, ?, 'rule', '221', '311', 100.00, ?, ?, ?, NOW())"
        )->execute([
            $this->supplierId,
            $txId,
            $ruleId,
            $status,
            $entryId,
            $status === 'approved' ? $this->userId : null,
        ]);
        return $entryId;
    }

    private function cashRegister(): int
    {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO cash_registers (supplier_id, name, is_default) VALUES (?, 'Test pokladna', 1)"
        );
        $stmt->execute([$this->supplierId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function cashDocument(int $registerId, string $docNumber): int
    {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO cash_documents
                (supplier_id, register_id, doc_type, purpose, doc_number, issue_date, description, total_amount, status)
             VALUES (?, ?, 'in', 'sale', ?, ?, 'Test doklad', 200.00, 'draft')"
        );
        $stmt->execute([$this->supplierId, $registerId, $docNumber, self::YEAR . '-03-02']);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * @param array<string,string> $args
     * @param array<string,mixed>  $body
     * @param array<string,string> $headers
     * @param array<string,string> $query
     * @return array{status:int, body:array<string,mixed>, contentType:string, raw:string}
     */
    private function call(string $method, string $httpMethod, string $role, array $args = [], array $body = [], array $headers = [], array $query = []): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest($httpMethod, '/api/accounting')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role]);
        if ($body !== []) {
            $req = $req->withParsedBody($body);
        }
        if ($query !== []) {
            $req = $req->withQueryParams($query);
        }
        foreach ($headers as $name => $value) {
            $req = $req->withHeader($name, $value);
        }
        $resp = $args === []
            ? $this->journalAction->{$method}($req, new Psr7Response())
            : $this->journalAction->{$method}($req, new Psr7Response(), $args);
        $resp->getBody()->rewind();
        $raw = (string) $resp->getBody();
        $contentType = $resp->getHeaderLine('Content-Type');
        $decoded = str_starts_with($contentType, 'application/json') ? json_decode($raw, true) : null;
        return [
            'status'      => $resp->getStatusCode(),
            'body'        => is_array($decoded) ? $decoded : [],
            'contentType' => $contentType,
            'raw'         => $raw,
        ];
    }
}
