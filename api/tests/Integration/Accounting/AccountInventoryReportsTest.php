<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\AccountStatementService;
use MyInvoice\Service\Accounting\Reports\BalanceInventoryService;
use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use MyInvoice\Service\Accounting\Reports\OpenItemsService;
use MyInvoice\Service\Accounting\Reports\ReportException;
use MyInvoice\Service\Accounting\Reports\ReportXlsxExporter;
use MyInvoice\Service\Export\ClosingPackageService;
use MyInvoice\Service\Pdf\BalanceInventoryPdfRenderer;
use MyInvoice\Service\Pdf\OpenItemsListPdfRenderer;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Inventura účtů a rozvaha s výsledovkou analyticky: znaménka podle typu účtu, PS + obraty
 * = KS, součty podle protiúčtu, inventarizace po analytikách, vyplněný protokol, soupis
 * otevřených položek a shoda součtů s rozvahou a výsledovkou.
 *
 * Izolovaná firma v transakci s rollbackem.
 */
#[Group('integration')]
final class AccountInventoryReportsTest extends TestCase
{
    private const YEAR = 2091;
    private const FROM = self::YEAR . '-01-01';
    private const TO = self::YEAR . '-12-31';

    private Connection $db;
    private PostingService $posting;
    private AccountStatementService $statement;
    private BalanceInventoryService $inventory;
    private FinancialStatementService $statements;
    private OpenItemsService $openItems;
    private ClosingService $closing;
    private ReportXlsxExporter $xlsx;
    private AccountingPeriodRepository $periods;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private bool $inTx = false;
    /** @var list<string> */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje, test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db         = $container->get(Connection::class);
            $this->posting    = $container->get(PostingService::class);
            $this->statement  = $container->get(AccountStatementService::class);
            $this->inventory  = $container->get(BalanceInventoryService::class);
            $this->statements = $container->get(FinancialStatementService::class);
            $this->openItems  = $container->get(OpenItemsService::class);
            $this->closing    = $container->get(ClosingService::class);
            $this->xlsx       = $container->get(ReportXlsxExporter::class);
            $this->periods    = $container->get(AccountingPeriodRepository::class);
            $seeder           = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $currencyId   = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId    = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId         = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $currencyId === 0 || $vatRateId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, ic, accounting_mode)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, "inventura@example.com", ?, ?, "12345678", "double_entry")'
        )->execute(['Inventura účtů s.r.o.', $czId, $currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::FROM, self::TO);
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    // ── Bod 12: opis účtu ─────────────────────────────────────────────────

    public function testStatementBalanceIsSignedByAccountType(): void
    {
        $this->post([['321', 'credit', 500.0], ['518', 'debit', 500.0]], '02-01');
        $this->post([['311', 'credit', 70.0], ['602', 'debit', 70.0]], '02-02');
        $this->post([['551', 'debit', 40.0], ['082', 'credit', 40.0]], '02-03');

        $liability = $this->statementOf('321');
        self::assertSame(-500.0, $liability['closing_balance'], 'Interní báze zůstává MD − D.');
        self::assertSame(500.0, $liability['closing_balance_signed'], 'Závazek na Dal je kladný.');
        self::assertFalse($liability['closing_balance_unusual']);
        self::assertSame(-1, $liability['account']['balance_sign']);

        $receivable = $this->statementOf('311');
        self::assertSame(-70.0, $receivable['closing_balance_signed'], 'Pohledávka v kreditu je záporná.');
        self::assertTrue($receivable['closing_balance_unusual'], 'Pohledávka v kreditu je neobvyklá.');
        self::assertTrue($receivable['items'][0]['balance_unusual']);

        $contra = $this->statementOf('082');
        self::assertSame(-40.0, $contra['closing_balance_signed'], 'Oprávky mají typ aktiva, jsou záporné.');
        self::assertFalse($contra['closing_balance_unusual'], 'Oprávky na Dal jsou obvyklé.');

        $revenue = $this->statementOf('602');
        self::assertSame(-70.0, $revenue['closing_balance_signed'], 'Výnos na MD je záporný.');
        self::assertTrue($revenue['closing_balance_unusual']);
        self::assertSame(40.0, $this->statementOf('551')['closing_balance_signed'], 'Náklad na MD je kladný.');
    }

    public function testOpeningPlusTurnoversEqualsClosingInSignedBalances(): void
    {
        $this->posting->postDocument($this->supplierId, 'opening', null, [
            self::l('321', 'credit', 800.0), self::l('701', 'debit', 800.0),
        ], ['entry_date' => self::FROM, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        $this->post([['321', 'debit', 300.0], ['221', 'credit', 300.0]], '03-01');
        $this->post([['321', 'credit', 50.0], ['518', 'debit', 50.0]], '03-02');

        $data = $this->statementOf('321');

        self::assertSame(800.0, $data['opening_balance_signed']);
        self::assertSame(550.0, $data['closing_balance_signed']);
        self::assertSame(
            self::cents($data['closing_balance_signed']),
            self::cents($data['opening_balance_signed']) + $data['account']['balance_sign'] * (self::cents($data['turnover_md']) - self::cents($data['turnover_d'])),
        );
        self::assertSame(550.0, $data['items'][1]['balance_signed'], 'Běžící zůstatek má stejné znaménko jako PS a KS.');
    }

    public function testCounterTotalsSplitCompoundEntryAndMatchTurnover(): void
    {
        $this->post([['311', 'debit', 1210.0], ['602', 'credit', 1000.0], ['343', 'credit', 210.0]], '03-10');
        $this->post([['221', 'debit', 605.0], ['311', 'credit', 605.0]], '04-10');
        // M : N: tři řádky protistrany s dělením na haléře.
        $this->post([['311', 'debit', 100.0], ['602', 'credit', 33.33], ['604', 'credit', 33.33], ['648', 'credit', 33.34]], '05-10');

        $data = $this->statementOf('311');
        $totals = array_column($data['counter_totals'], null, 'account_code');

        self::assertSame(1033.33, $totals['602']['md']);
        self::assertSame(210.0, $totals['343']['md']);
        self::assertSame(605.0, $totals['221']['d']);
        self::assertSame(self::cents($data['turnover_md']), array_sum(array_map(static fn (array $r): int => self::cents($r['md']), $data['counter_totals'])), 'Σ protiúčtů MD == obrat MD.');
        self::assertSame(self::cents($data['turnover_d']), array_sum(array_map(static fn (array $r): int => self::cents($r['d']), $data['counter_totals'])), 'Σ protiúčtů D == obrat D.');
    }

    public function testCompoundEntryOnBothSidesIsAllocatedProportionally(): void
    {
        $this->post([['518', 'debit', 100.0], ['343', 'debit', 21.0], ['321', 'credit', 96.8], ['365', 'credit', 24.2]], '03-10');

        $data = $this->statementOf('518');
        $totals = array_column($data['counter_totals'], null, 'account_code');

        self::assertSame(80.0, $totals['321']['md']);
        self::assertSame(20.0, $totals['365']['md']);
    }

    public function testCounterFilterKeepsRunningBalanceOfTheWholeAccount(): void
    {
        $this->post([['211', 'debit', 100.0], ['602', 'credit', 100.0]], '01-10');
        $this->post([['518', 'debit', 30.0], ['211', 'credit', 30.0]], '02-05');
        $this->post([['211', 'debit', 50.0], ['602', 'credit', 50.0]], '03-03');

        $filtered = $this->statementOf('211', $this->accountId('518'));

        self::assertSame(1, $filtered['total'], 'Filtr nechá jen řádky s tímto protiúčtem.');
        self::assertCount(1, $filtered['items']);
        self::assertSame(70.0, $filtered['items'][0]['balance'], 'Zůstatek je zůstatek celého účtu po pohybu.');
        self::assertSame(120.0, $filtered['closing_balance'], 'KS filtr neovlivní.');
        self::assertSame(150.0, $filtered['turnover_md'], 'Obraty karty zůstávají za celý účet.');
        self::assertSame(['md' => 0.0, 'd' => 30.0], array_intersect_key($filtered['counter_filter'], ['md' => 1, 'd' => 1]));
        self::assertCount(2, $filtered['counter_totals'], 'Součty ukazují všechny protiúčty i při filtru.');
    }

    public function testCounterFilterOnSyntheticCoversAnalytics(): void
    {
        $this->analytic('221', '221007', 'asset', 'debit');
        $this->post([['221007', 'debit', 400.0], ['311', 'credit', 400.0]], '03-01');

        $filtered = $this->statementOf('311', $this->accountId('221'));

        self::assertSame(1, $filtered['total']);
        self::assertSame(400.0, $filtered['counter_filter']['d']);
    }

    public function testRedStornoCounterAccountUsesEffectiveSide(): void
    {
        $entry = $this->post([['311', 'debit', 100.0], ['602', 'credit', 100.0]], '03-01');
        $this->db->pdo()->prepare('UPDATE journal_entry_lines SET is_red_storno = 1 WHERE entry_id = ? AND supplier_id = ?')->execute([$entry, $this->supplierId]);

        $data = $this->statementOf('311');
        $totals = array_column($data['counter_totals'], null, 'account_code');

        self::assertSame(-100.0, $data['turnover_md']);
        self::assertSame(-100.0, $totals['602']['md'], 'Červené storno snižuje obrat i u protiúčtu.');
        self::assertSame('602', $data['items'][0]['counter_accounts']);
        self::assertSame(1, $this->statementOf('311', $this->accountId('602'))['total']);
    }

    public function testOneSidedRedStornoHasCounterAccountOnEffectiveSide(): void
    {
        // MD 311 −100 / MD 335 +100: přeúčtování na jedné straně. Účinně je 311 na Dal.
        $entry = $this->post([['311', 'debit', 100.0], ['335', 'debit', 100.0], ['602', 'credit', 100.0], ['518', 'debit', 100.0], ['321', 'credit', 200.0]], '03-01');
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM journal_entry_lines WHERE entry_id = ? AND supplier_id = ? AND account_id IN (?, ?, ?)')
            ->execute([$entry, $this->supplierId, $this->accountId('602'), $this->accountId('518'), $this->accountId('321')]);
        $pdo->prepare('UPDATE journal_entry_lines SET is_red_storno = 1 WHERE entry_id = ? AND supplier_id = ? AND account_id = ?')
            ->execute([$entry, $this->supplierId, $this->accountId('311')]);

        $data = $this->statementOf('311');

        self::assertSame(-100.0, array_column($data['counter_totals'], null, 'account_code')['335']['md']);
        self::assertSame('335', $data['items'][0]['counter_accounts']);
        self::assertSame(1, $this->statementOf('311', $this->accountId('335'))['total'], 'Protiúčet na opačné účinné straně jde vyfiltrovat.');
    }

    public function testDisplayedCounterAccountsAgreeWithFilterInMixedEntry(): void
    {
        // Zápis mísí běžné a červené řádky: 311 MD 100 proti 602 Dal 100 a 518 MD −50 (účinně Dal).
        $entry = $this->post([['311', 'debit', 100.0], ['602', 'credit', 100.0], ['518', 'debit', 50.0], ['321', 'credit', 50.0]], '03-01');
        $this->db->pdo()->prepare('UPDATE journal_entry_lines SET is_red_storno = 1 WHERE entry_id = ? AND supplier_id = ? AND account_id IN (?, ?)')
            ->execute([$entry, $this->supplierId, $this->accountId('518'), $this->accountId('321')]);

        $data = $this->statementOf('311');
        $totals = array_column($data['counter_totals'], null, 'account_code');

        self::assertSame('518, 602', $data['items'][0]['counter_accounts'], 'Řádek ukazuje účty opačné účinné strany.');
        self::assertSame(33.33, $totals['518']['md']);
        self::assertSame(66.67, $totals['602']['md']);
        self::assertArrayNotHasKey('321', $totals, '321 je účinně na stejné straně jako 311.');
        self::assertSame(0, $this->statementOf('311', $this->accountId('321'))['total']);
        self::assertSame('311, 321', $this->statementOf('518')['items'][0]['counter_accounts'], 'Červený řádek je účinně na Dal.');
    }

    public function testForeignCounterAccountIsNotFound(): void
    {
        $this->post([['211', 'debit', 100.0], ['602', 'credit', 100.0]], '01-10');
        $this->expectException(ReportException::class);
        $this->statement->build($this->supplierId, $this->accountId('211'), self::FROM, self::TO, 1, 50, false, 999999999);
    }

    // ── Bod 12: inventarizace po analytikách a protokol ───────────────────

    public function testInventoryHasRowPerAnalyticAndMatchesStatements(): void
    {
        $this->bankScenario();

        $report = $this->inventory->buildWithSaved($this->supplierId, $this->periodId);
        $rows = array_column($report['rows'], null, 'account_code');

        self::assertArrayHasKey('221001', $rows);
        self::assertArrayHasKey('221002', $rows);
        self::assertArrayNotHasKey('221', $rows, 'Syntetika bez vlastních pohybů nemá vlastní řádek k počítání.');
        self::assertEquals(5000.0, $rows['221001']['ks_md']);
        self::assertEquals(2000.0, $rows['221002']['ks_d']);

        $summary = array_column(array_filter($report['protocol_rows'], static fn (array $r): bool => $r['kind'] === 'synthetic'), null, 'account_code')['221'];
        self::assertEquals(5000.0, $summary['ks_md'], 'Součet syntetiky je bez kompenzace.');
        self::assertEquals(2000.0, $summary['ks_d']);
        self::assertSame(3000.0, $summary['book_display']);
        self::assertNull($summary['counted_display'], 'Nenapočítaná syntetika nemá skutečný stav.');

        $view = $this->statements->accountView($this->supplierId, $this->periodId, self::TO);
        self::assertSame(self::cents($view['balance']['md']), self::cents($report['totals']['ks_md']), 'Σ MD inventarizace == Σ MD rozvahy po účtech.');
        self::assertSame(self::cents($view['balance']['d']), self::cents($report['totals']['ks_d']), 'Σ D inventarizace == Σ D rozvahy po účtech.');
    }

    public function testEveryAnalyticMustBeCountedBeforeCompletion(): void
    {
        $this->bankScenario();
        $rows = $this->inventory->buildWithSaved($this->supplierId, $this->periodId)['rows'];

        $items = [];
        foreach ($rows as $r) {
            if ($r['account_code'] !== '221002') {
                $items[(int) $r['account_id']] = ['counted_balance' => $r['book_balance']];
            }
        }
        $res = $this->closing->saveInventory($this->supplierId, $this->periodId, $this->rowVersion(), ['complete' => true], $items, ['user_id' => $this->userId]);
        self::assertSame(1, $res['unresolved_count'], 'Nenapočítaná analytika blokuje dokončení.');
        self::assertFalse($res['completed']);

        $partial = $this->inventory->buildWithSaved($this->supplierId, $this->periodId)['protocol_rows'];
        $summary = array_column(array_filter($partial, static fn (array $r): bool => $r['kind'] === 'synthetic'), null, 'account_code')['221'];
        self::assertNull($summary['counted_display'], 'Součet syntetiky čeká na všechny analytiky.');
        self::assertNull($summary['difference_display']);

        $items[$this->accountId('221002')] = ['counted_balance' => -2000.0];
        $res = $this->closing->saveInventory($this->supplierId, $this->periodId, $this->rowVersion(), ['complete' => true], $items, ['user_id' => $this->userId]);
        self::assertTrue($res['completed']);
        self::assertTrue($this->inventory->inventoryStatus($this->supplierId, $this->periodId)['ok']);
    }

    public function testInventorySavedForSyntheticStaysRolledUpUntilCleared(): void
    {
        $this->bankScenario();
        $inventoryId = $this->savedSyntheticInventory();

        $report = $this->inventory->buildWithSaved($this->supplierId, $this->periodId);
        $bank = array_column($report['rows'], null, 'account_code')['221'] ?? null;
        self::assertNotNull($bank, 'Protokol vedený za syntetiku se nesmí sám rozpadnout.');
        self::assertTrue($bank['rolled_up']);
        self::assertSame(3000.0, $bank['book_balance']);
        self::assertEquals(3000.0, $bank['ks_md'], 'Souhrnný řádek je netto jako dřív.');
        self::assertArrayNotHasKey('221001', array_column($report['rows'], null, 'account_code'));
        self::assertTrue($bank['resolved']);
        self::assertGreaterThan(0, $inventoryId);

        $items = [];
        foreach ($report['rows'] as $r) {
            $items[(int) $r['account_id']] = ['counted_balance' => $r['book_balance'], 'resolution' => 'resolved'];
        }
        $res = $this->closing->saveInventory($this->supplierId, $this->periodId, $this->rowVersion(), ['complete' => true], $items, ['user_id' => $this->userId]);
        self::assertTrue($res['completed'], 'Další uložení souhrnný řádek zachová.');
        self::assertTrue($this->inventory->inventoryStatus($this->supplierId, $this->periodId)['ok']);

        $items[$this->accountId('221')] = ['counted_balance' => null, 'resolution' => 'open'];
        $this->closing->saveInventory($this->supplierId, $this->periodId, $this->rowVersion(), [], $items, ['user_id' => $this->userId]);
        self::assertArrayHasKey('221001', array_column($this->inventory->buildWithSaved($this->supplierId, $this->periodId)['rows'], null, 'account_code'), 'Po vymazání se rozpadne na analytiky.');
    }

    public function testFilledProtocolExportShowsScreenValuesAndSignatures(): void
    {
        $this->post([['518', 'debit', 1000.0], ['321', 'credit', 1000.0]], '03-01');
        $this->post([['221', 'debit', 500.0], ['411', 'credit', 500.0]], '03-02');
        $this->post([['518', 'debit', 500.0], ['221', 'credit', 0.01], ['211', 'credit', 499.99]], '03-03');
        $liability = $this->accountId('321');
        $bank = $this->accountId('221');

        // Závazek napočítaný na 900 Kč: na obrazovce rozdíl −100, v MD − D by byl +100.
        $this->closing->saveInventory($this->supplierId, $this->periodId, $this->rowVersion(), [
            'responsible_person' => 'Jana Účetní',
            'inventory_date'     => self::TO,
            'protocol_ref'       => 'INV-2091',
            'note'               => 'Poznámka k protokolu',
        ], [
            $liability => ['counted_balance' => -900.0, 'resolution' => 'resolved', 'note' => 'Dobropis v lednu'],
        ], ['user_id' => $this->userId]);
        self::assertNotSame(0, $bank);

        $report = $this->inventory->buildWithSaved($this->supplierId, $this->periodId);
        $row = array_column($report['rows'], null, 'account_code')['321'];
        self::assertSame(1000.0, $row['book_display']);
        self::assertSame(900.0, $row['counted_display']);
        self::assertSame(-100.0, $row['difference_display'], 'Podepsaný rozdíl je stejný jako na obrazovce.');

        $sheet = $this->spreadsheet($this->xlsx->balanceInventory($report))->getActiveSheet();
        $cells = $this->rowByFirstCell($sheet, '321');
        self::assertSame('Dal', $cells[2]);
        self::assertEquals(1000, $cells[4]);
        self::assertEquals(900, $cells[5]);
        self::assertEquals(-100, $cells[6]);
        self::assertSame('ano', $cells[7]);
        self::assertSame('Dobropis v lednu', $cells[8]);
        $text = $this->sheetText($sheet);
        self::assertStringContainsString('Jana Účetní', $text);
        self::assertStringContainsString('INV-2091', $text);
        self::assertStringContainsString('Poznámka k protokolu', $text);
        self::assertStringContainsString('Inventarizaci provedl (datum, podpis)', $text);
        self::assertStringContainsString('Schválil, odpovědná osoba Jana Účetní (datum, podpis)', $text);

        $renderer = Bootstrap::buildContainer()->get(BalanceInventoryPdfRenderer::class);
        $html = (string) (new \ReflectionMethod($renderer, 'renderTemplate'))->invoke($renderer, 'balance_inventory.twig', $report);
        self::assertStringContainsString('Dobropis v lednu', $html);
        self::assertStringContainsString('900,00', $html);
        self::assertStringContainsString('-100,00', $html);
        self::assertStringContainsString('Jana Účetní', $html);
        self::assertStringStartsWith('%PDF', $renderer->render($report));
    }

    public function testUncountedRowsStayBlankInTheProtocol(): void
    {
        $this->post([['221', 'debit', 500.0], ['411', 'credit', 500.0]], '03-02');
        $report = $this->inventory->buildWithSaved($this->supplierId, $this->periodId);

        $cells = $this->rowByFirstCell($this->spreadsheet($this->xlsx->balanceInventory($report))->getActiveSheet(), '221');
        self::assertSame('', (string) $cells[5], 'Nenapočítaný účet nemá skutečný stav.');
        self::assertSame('', (string) $cells[6]);
        self::assertSame('ne', $cells[7]);
    }

    public function testBackFilledProtocolSaysItIsNotAnInventory(): void
    {
        $this->post([['221', 'debit', 500.0], ['411', 'credit', 500.0]], '03-02');
        $this->db->pdo()->prepare('UPDATE accounting_periods SET status = "closed" WHERE id = ?')->execute([$this->periodId]);

        $sheet = $this->spreadsheet($this->xlsx->balanceInventory($this->inventory->buildWithSaved($this->supplierId, $this->periodId)))->getActiveSheet();

        self::assertStringContainsString('nejde o doklad o provedené inventuře', $this->sheetText($sheet));
    }

    public function testAccountInventoryReturnsRowsOfTheAccountAndItsAnalytics(): void
    {
        $this->bankScenario();

        $synthetic = $this->inventory->accountInventory($this->supplierId, $this->accountId('221'), self::YEAR . '-06-30');
        self::assertTrue($synthetic['inventoriable']);
        self::assertSame(['221001', '221002'], array_column($synthetic['rows'], 'account_code'));
        self::assertSame(self::TO, $synthetic['as_of'], 'Inventura je vždy k rozvahovému dni období.');

        $analytic = $this->inventory->accountInventory($this->supplierId, $this->accountId('221001'), self::TO);
        self::assertSame(['221001'], array_column($analytic['rows'], 'account_code'));

        $expense = $this->inventory->accountInventory($this->supplierId, $this->accountId('518'), self::TO);
        self::assertFalse($expense['inventoriable'], 'Výsledkový účet se neinventarizuje.');
        self::assertSame([], $expense['rows']);
    }

    // ── Bod 12: soupis otevřených položek ─────────────────────────────────

    public function testOpenItemsListCoversDefaultAccountsAndMatchesBalance(): void
    {
        // 261: převod spárovaný okruhem, jedna částka zůstává otevřená.
        $out = $this->post([['261', 'debit', 1000.0], ['211', 'credit', 1000.0]], '03-01');
        $in = $this->post([['221', 'debit', 1000.0], ['261', 'credit', 1000.0]], '03-02');
        $this->post([['261', 'debit', 250.0], ['211', 'credit', 250.0]], '03-03');
        $this->pair('261', [$out, $in]);
        // 335: záloha zaměstnanci. 395: bez otevřené položky.
        $this->post([['335', 'debit', 400.0], ['211', 'credit', 400.0]], '04-01');
        $this->post([['395', 'debit', 70.0], ['221', 'credit', 70.0]], '04-02');
        $this->post([['221', 'debit', 70.0], ['395', 'credit', 70.0]], '04-03');
        // 311 do výchozího soupisu nepatří.
        $this->post([['311', 'debit', 900.0], ['602', 'credit', 900.0]], '04-04');

        $data = $this->openItems->listing($this->supplierId, self::TO, [], 1000);
        $accounts = array_column(array_map(static fn (array $a): array => $a + ['code' => $a['account']['code']], $data['accounts']), null, 'code');

        self::assertArrayHasKey('261', $accounts);
        self::assertArrayHasKey('335', $accounts);
        self::assertArrayHasKey('395', $accounts);
        self::assertArrayNotHasKey('311', $accounts);

        self::assertSame(1, $accounts['261']['open_count'], 'Spárovaný převod je uzavřený.');
        self::assertSame(250.0, $accounts['261']['open_total']);
        self::assertSame(250.0, $accounts['261']['balance']);
        self::assertTrue($accounts['261']['matches']);
        self::assertSame(400.0, $accounts['335']['balance_signed']);

        $withExtra = $this->openItems->listing($this->supplierId, self::TO, ['311'], 1000);
        self::assertSame(['311'], array_map(static fn (array $a): string => $a['account']['code'], $withExtra['accounts']), 'Výběr účtů nahrazuje výchozí seznam.');

        $sheet = $this->spreadsheet($this->xlsx->openItemsList($data))->getActiveSheet();
        self::assertStringContainsString('261 Peníze na cestě', $this->sheetText($sheet));
        self::assertStringStartsWith('%PDF', Bootstrap::buildContainer()->get(OpenItemsListPdfRenderer::class)->render($data));
    }

    public function testClosingPackageCarriesFilledProtocolAndOpenItems(): void
    {
        $this->post([['335', 'debit', 400.0], ['411', 'credit', 400.0]], '04-01');
        $account = $this->accountId('335');
        $this->closing->saveInventory($this->supplierId, $this->periodId, $this->rowVersion(), ['responsible_person' => 'Jana Účetní'], [
            $account => ['counted_balance' => 400.0, 'note' => 'Odsouhlaseno'],
        ], ['user_id' => $this->userId]);

        $entries = $this->runPackage(['balance_inventory', 'open_items'], $manifest);

        self::assertSame('completed_with_warnings', $manifest['status'], 'Nedokončený protokol balíček hlásí.');
        self::assertStringContainsString('Inventarizace rozvahových účtů není dokončená', implode("\n", $manifest['warnings']));
        self::assertSame(1, $manifest['parts']['open_items'] ?? null);
        self::assertStringStartsWith('%PDF', $entries['Inventarizace-uctu/inventarizace-uctu-' . self::YEAR . '.pdf']);
        self::assertStringStartsWith('%PDF', $entries['Inventarizace-uctu/otevrene-polozky-' . self::YEAR . '.pdf']);

        $sheet = $this->spreadsheet(['bytes' => $entries['Inventarizace-uctu/inventarizace-uctu-' . self::YEAR . '.xlsx']])->getActiveSheet();
        $cells = $this->rowByFirstCell($sheet, '335');
        self::assertEquals(400, $cells[5], 'Balíček nese vyplněný skutečný stav.');
        self::assertSame('Odsouhlaseno', $cells[8]);
    }

    public function testCompletedInventoryProducesNoPackageWarning(): void
    {
        $this->post([['221', 'debit', 400.0], ['411', 'credit', 400.0]], '04-01');
        $items = [];
        foreach ($this->inventory->buildWithSaved($this->supplierId, $this->periodId)['rows'] as $r) {
            $items[(int) $r['account_id']] = ['counted_balance' => $r['book_balance']];
        }
        $this->closing->saveInventory($this->supplierId, $this->periodId, $this->rowVersion(), ['complete' => true], $items, ['user_id' => $this->userId]);

        $this->runPackage(['balance_inventory'], $manifest);

        self::assertSame('completed', $manifest['status'], implode("\n", $manifest['warnings']));
    }

    // ── Bod 13: sloupce a shoda s výkazy ──────────────────────────────────

    public function testColumnsSatisfyOpeningPlusTurnoverEqualsClosing(): void
    {
        $this->statementScenario();

        $columns = $this->columns(self::YEAR . '-06-30');

        foreach (['balance', 'profit_loss'] as $part) {
            foreach ($this->cellsOf($columns[$part]['lines']) as $cell) {
                self::assertSame(
                    self::cents($cell['ks']),
                    self::cents($cell['ps']) + self::cents($cell['ytd']),
                    "PS + obrat od začátku období = KS: {$cell['account_code']}",
                );
            }
        }
        $byCode = $this->cellsByCode($columns['balance']['lines']);
        self::assertSame(800.0, $byCode['321']['ps'], 'PS závazku z otevíracího zápisu.');
        self::assertSame(1000.0, $byCode['321']['ks'], 'Závazek na Dal je kladný.');
        self::assertSame(200.0, $byCode['321']['ytd']);
        self::assertSame(200.0, $byCode['321']['month'], 'Obrat měsíce k as_of.');
        self::assertSame(-40.0, $byCode['082']['ks'], 'Oprávky mají záporné znaménko aktiv.');
        self::assertFalse($byCode['082']['ks_unusual']);
        $byCodePl = $this->cellsByCode($columns['profit_loss']['lines']);
        self::assertSame(1000.0, $byCodePl['602']['ks'], 'Výnos na Dal je kladný.');
        self::assertSame(0.0, $byCodePl['602']['month'], 'V červnu výnos bez pohybu.');
        self::assertSame(self::YEAR . '-06-01', $columns['month_from']);
    }

    public function testColumnsPutAssetsNextToLiabilitiesAndFlagUnusualSide(): void
    {
        $this->statementScenario();
        $this->post([['311', 'credit', 5000.0], ['221', 'debit', 5000.0]], '06-20');

        $lines = $this->columns(self::YEAR . '-06-30')['balance']['lines'];
        $pair = array_values(array_filter($lines, static fn (array $l): bool => $l['kind'] === 'cells' && ($l['left']['kind'] ?? '') === 'synthetic' && ($l['right']['kind'] ?? '') === 'synthetic'));

        self::assertNotSame([], $pair, 'Aktiva a pasiva leží na jednom řádku.');
        foreach ($this->cellsOf($lines) as $cell) {
            self::assertNotSame('expense', $cell['account_type']);
        }
        foreach ($lines as $line) {
            if ($line['kind'] === 'cells') {
                self::assertContains($line['left']['account_type'] ?? 'asset', ['asset']);
                self::assertContains($line['right']['account_type'] ?? 'liability', ['liability', 'equity']);
            }
        }
        $receivable = $this->cellsByCode($lines)['311'];
        self::assertSame(-4000.0, $receivable['ks'], 'Pohledávka v kreditu zůstává v aktivech záporně.');
        self::assertTrue($receivable['ks_unusual']);
    }

    public function testColumnsTotalsMatchBalanceSheetAndIncomeStatement(): void
    {
        $this->statementScenario();

        $view = $this->statements->accountView($this->supplierId, $this->periodId, self::TO, null, ['hide_zero' => true, 'analytics' => true]);
        $columns = $view['columns'];
        $bs = $this->statements->balanceSheet($this->supplierId, $this->periodId, self::TO, 'full');
        $vzz = $this->statements->incomeStatement($this->supplierId, $this->periodId, self::TO, 'full');

        self::assertSame(self::cents($bs['checks']['assets_net']), self::cents($columns['balance']['left_total']['ks']), 'Σ aktiv == aktiva netto rozvahy.');
        self::assertSame(
            self::cents($bs['checks']['liabilities_total']),
            self::cents($columns['balance']['right_total']['ks']) + self::cents($columns['balance']['result']['ks']),
            'Σ pasiv + VH == pasiva rozvahy.',
        );
        self::assertSame(self::cents($vzz['checks']['profit_current']), self::cents($columns['profit_loss']['result']['ks']), 'VH sloupců == VH výkazu.');
        self::assertSame(self::cents($view['balance']['profit']), self::cents($columns['balance']['result']['ks']), 'VH rozvahových účtů.');
        self::assertSame(self::cents($view['profit_loss']['profit']), self::cents($columns['profit_loss']['result']['ks']), 'VH výsledkových účtů.');
        self::assertSame(self::cents($columns['profit_loss']['right_total']['ks']) - self::cents($columns['profit_loss']['left_total']['ks']), self::cents($columns['profit_loss']['result']['ks']));
        self::assertSame(0, self::cents($columns['balance']['result']['ps']), 'Vyrovnaný PS nemá výsledek.');
        $grand = array_values(array_filter($columns['balance']['lines'], static fn (array $l): bool => ($l['key'] ?? '') === 'balance_with_result'));
        self::assertNotSame([], $grand);
    }

    public function testHidingZeroAccountsDoesNotChangeTotals(): void
    {
        $this->statementScenario();
        // 261 s pohybem, který se vyrovnal: všechny čtyři sloupce nulové.
        $this->post([['261', 'debit', 90.0], ['211', 'credit', 90.0]], '02-01');
        $this->post([['211', 'debit', 90.0], ['261', 'credit', 90.0]], '02-02');
        $shown = $this->columns(self::TO, false);
        $hidden = $this->columns(self::TO, true);

        self::assertArrayHasKey('261', $this->cellsByCode($shown['balance']['lines']), 'Bez skrytí je nulový účet vidět.');
        self::assertTrue($this->cellsByCode($shown['balance']['lines'])['261']['is_zero']);
        self::assertArrayNotHasKey('261', $this->cellsByCode($hidden['balance']['lines']));
        self::assertSame($shown['balance']['left_total'], $hidden['balance']['left_total']);
        self::assertSame($shown['balance']['right_total'], $hidden['balance']['right_total']);
        self::assertSame($shown['profit_loss']['result'], $hidden['profit_loss']['result']);
    }

    public function testAccountWithClosingZeroButOpeningBalanceIsNotHidden(): void
    {
        $this->posting->postDocument($this->supplierId, 'opening', null, [
            self::l('321', 'credit', 800.0), self::l('701', 'debit', 800.0),
        ], ['entry_date' => self::FROM, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        $this->post([['321', 'debit', 800.0], ['221', 'credit', 800.0]], '03-01');

        $cell = $this->cellsByCode($this->columns(self::TO, true)['balance']['lines'])['321'] ?? null;

        self::assertNotNull($cell, 'Účet s PS a obratem není nulový, i když má KS 0.');
        self::assertSame(800.0, $cell['ps']);
        self::assertSame(-800.0, $cell['ytd']);
        self::assertSame(0.0, $cell['ks']);
    }

    public function testAnalyticsToggleAndClosingEntryExclusion(): void
    {
        $this->bankScenario();
        $with = $this->columns(self::TO);
        $without = $this->statements->accountView($this->supplierId, $this->periodId, self::TO, null, ['hide_zero' => true, 'analytics' => false])['columns'];

        self::assertArrayHasKey('221001', $this->cellsByCode($with['balance']['lines']));
        self::assertArrayNotHasKey('221001', $this->cellsByCode($without['balance']['lines']));
        self::assertSame($with['balance']['left_total'], $without['balance']['left_total']);

        $bank = $this->cellsByCode($with['balance']['lines'])['221'];
        self::assertSame(3000.0, $bank['ks'], 'Syntetika je součet analytik se znaménkem.');
        self::assertSame(-2000.0, $this->cellsByCode($with['balance']['lines'])['221002']['ks']);
    }

    public function testXlsxHasTheSameShapeAsTheScreen(): void
    {
        $this->statementScenario();
        $view = $this->statements->accountView($this->supplierId, $this->periodId, self::YEAR . '-06-30', null, ['hide_zero' => true, 'analytics' => true]);

        foreach (['balance' => ['AKTIVA', 'PASIVA'], 'profit_loss' => ['NÁKLADY', 'VÝNOSY']] as $part => [$left, $right]) {
            $sheet = $this->spreadsheet($this->xlsx->accountView($view, $part))->getActiveSheet();
            self::assertSame($left, $sheet->getCell('A7')->getValue());
            self::assertSame($right, $sheet->getCell('G7')->getValue());
            self::assertSame('Počáteční stav', $sheet->getCell('C8')->getValue());
            self::assertSame('Obrat 06/' . self::YEAR, $sheet->getCell('D8')->getValue());
            self::assertSame('Obrat od 01.01.' . self::YEAR, $sheet->getCell('E8')->getValue());
            self::assertSame('Konečný stav', $sheet->getCell('F8')->getValue());
            self::assertSame('Konečný stav', $sheet->getCell('L8')->getValue());

            $row = 9;
            foreach ($view['columns'][$part]['lines'] as $line) {
                if ($line['kind'] === 'cells') {
                    foreach (['left' => ['A', 'C', 'F'], 'right' => ['G', 'I', 'L']] as $side => [$codeCol, $psCol, $ksCol]) {
                        $cell = $line[$side];
                        if ($cell === null) {
                            self::assertNull($sheet->getCell($codeCol . $row)->getValue(), "{$part} řádek {$row}");
                            continue;
                        }
                        if ($cell['kind'] !== 'class') {
                            self::assertSame($cell['account_code'], trim((string) $sheet->getCell($codeCol . $row)->getValue()));
                        }
                        self::assertEquals($cell['ps'], $sheet->getCell($psCol . $row)->getValue());
                        self::assertEquals($cell['ks'], $sheet->getCell($ksCol . $row)->getValue());
                    }
                }
                $row++;
            }
        }
    }

    public function testXlsxMarksUnusualSideRed(): void
    {
        $this->post([['311', 'credit', 70.0], ['602', 'debit', 70.0]], '02-02');
        $view = $this->statements->accountView($this->supplierId, $this->periodId, self::TO, null, ['hide_zero' => true, 'analytics' => true]);

        $sheet = $this->spreadsheet($this->xlsx->accountView($view, 'balance'))->getActiveSheet();
        $row = $this->rowNumberByCell($sheet, 'A', '311');

        self::assertSame('B91C1C', $sheet->getCell('F' . $row)->getStyle()->getFont()->getColor()->getRGB());
    }

    public function testColumnsAreNotAddedWithoutRequest(): void
    {
        $this->statementScenario();
        self::assertArrayNotHasKey('columns', $this->statements->accountView($this->supplierId, $this->periodId, self::TO));
    }

    public function testDimensionFilterReachesAllColumns(): void
    {
        $dimensions = Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Dimension\DimensionService::class);
        $dimensions->setEnabled($this->supplierId, true);
        $type = $dimensions->ensureDefaultTypes($this->supplierId, ['stredisko'])['cost_center'];
        $value = (int) $dimensions->createValue($this->supplierId, $type, ['code' => 'A', 'name' => 'Středisko A'])['id'];
        $tag = ['dimensions' => [$type => $value]];
        $post = fn (string $date, float $amount, array $extra): int => $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '518', 'side' => 'debit', 'amount' => $amount] + $extra,
            ['account_code' => '321', 'side' => 'credit', 'amount' => $amount] + $extra,
        ], ['entry_date' => $date, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        $post(self::YEAR . '-03-01', 100.0, $tag);
        $post(self::YEAR . '-06-10', 40.0, $tag);
        $post(self::YEAR . '-06-11', 900.0, []);

        $filter = $dimensions->filter($this->supplierId, $value);
        $columns = $this->statements->accountView($this->supplierId, $this->periodId, self::YEAR . '-06-30', $filter, ['hide_zero' => true, 'analytics' => true])['columns'];
        $expense = $this->cellsByCode($columns['profit_loss']['lines'])['518'];

        self::assertSame(140.0, $expense['ks'], 'KS jen s hodnotou dimenze.');
        self::assertSame(140.0, $expense['ytd']);
        self::assertSame(40.0, $expense['month'], 'Obrat měsíce jen s hodnotou dimenze.');
    }

    public function testCounterTotalsComeOnlyWithTheFirstPage(): void
    {
        $this->post([['211', 'debit', 100.0], ['602', 'credit', 100.0]], '01-10');
        $this->post([['211', 'debit', 50.0], ['602', 'credit', 50.0]], '03-03');
        $id = $this->accountId('211');

        self::assertCount(1, $this->statement->build($this->supplierId, $id, self::FROM, self::TO, 1, 1)['counter_totals']);
        self::assertNull($this->statement->build($this->supplierId, $id, self::FROM, self::TO, 2, 1)['counter_totals']);
    }

    // ── HTTP vrstva ───────────────────────────────────────────────────────

    public function testStatementActionFiltersAndRejectsInvalidCounterAccount(): void
    {
        $this->post([['211', 'debit', 100.0], ['602', 'credit', 100.0]], '01-10');
        $this->post([['518', 'debit', 30.0], ['211', 'credit', 30.0]], '02-05');
        $action = Bootstrap::buildContainer()->get(\MyInvoice\Action\Accounting\Reports\AccountStatementAction::class);
        $args = ['accountId' => (string) $this->accountId('211')];
        $query = ['from' => self::FROM, 'to' => self::TO];

        $bad = $action->get($this->request($query + ['counter_account_id' => 'abc']), new \Slim\Psr7\Response(), $args);
        self::assertSame(422, $bad->getStatusCode());

        $ok = $action->get($this->request($query + ['counter_account_id' => (string) $this->accountId('518')]), new \Slim\Psr7\Response(), $args);
        $data = json_decode((string) $ok->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, ($data['data'] ?? $data)['total']);

        $export = $action->export($this->request($query + ['counter_account_id' => (string) $this->accountId('518'), 'format' => 'xlsx']), new \Slim\Psr7\Response(), $args);
        self::assertSame(200, $export->getStatusCode());
        $sheet = $this->spreadsheet(['bytes' => (string) $export->getBody()])->getActiveSheet();
        self::assertStringContainsString('Filtr protiúčtu: 518', $this->sheetText($sheet));
        self::assertStringContainsString('Součty podle protiúčtu', $this->sheetText($sheet));
    }

    public function testStatementActionAddsColumnsOnlyOnRequestAndExportsThem(): void
    {
        $this->statementScenario();
        $action = Bootstrap::buildContainer()->get(\MyInvoice\Action\Accounting\Reports\FinancialStatementAction::class);
        $query = ['period_id' => (string) $this->periodId, 'as_of' => self::TO];

        $plain = json_decode((string) $action->accountView($this->request($query), new \Slim\Psr7\Response())->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('columns', $plain['data'] ?? $plain);

        $body = json_decode((string) $action->accountView($this->request($query + ['columns' => '1', 'hide_zero' => '0', 'analytics' => '0']), new \Slim\Psr7\Response())->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $columns = ($body['data'] ?? $body)['columns'];
        self::assertFalse($columns['hide_zero']);
        self::assertFalse($columns['analytics']);

        $export = $action->exportAccountView($this->request($query + ['format' => 'xlsx', 'part' => 'profit_loss']), new \Slim\Psr7\Response());
        self::assertSame(200, $export->getStatusCode());
        self::assertSame('NÁKLADY', $this->spreadsheet(['bytes' => (string) $export->getBody()])->getActiveSheet()->getCell('A7')->getValue());
    }

    public function testInventoryExportActionWritesSavedValues(): void
    {
        $this->post([['335', 'debit', 400.0], ['411', 'credit', 400.0]], '04-01');
        $this->closing->saveInventory($this->supplierId, $this->periodId, $this->rowVersion(), [], [
            $this->accountId('335') => ['counted_balance' => 350.0, 'note' => 'Doloženo'],
        ], ['user_id' => $this->userId]);
        $action = Bootstrap::buildContainer()->get(\MyInvoice\Action\Accounting\Reports\BalanceInventoryAction::class);

        $export = $action->export($this->request(['period_id' => (string) $this->periodId, 'format' => 'xlsx']), new \Slim\Psr7\Response());

        self::assertSame(200, $export->getStatusCode());
        $cells = $this->rowByFirstCell($this->spreadsheet(['bytes' => (string) $export->getBody()])->getActiveSheet(), '335');
        self::assertEquals(350, $cells[5]);
        self::assertEquals(-50, $cells[6]);
        self::assertSame('Doloženo', $cells[8]);
    }

    public function testOpenItemsListActionValidatesCodes(): void
    {
        $action = Bootstrap::buildContainer()->get(\MyInvoice\Action\Accounting\Reports\OpenItemsAction::class);

        self::assertSame(422, $action->listing($this->request(['as_of' => self::TO, 'codes' => "261'; DROP"]), new \Slim\Psr7\Response())->getStatusCode());
        self::assertSame(422, $action->listing($this->request(['codes' => '261']), new \Slim\Psr7\Response())->getStatusCode());
        self::assertSame(200, $action->listing($this->request(['as_of' => self::TO, 'codes' => '261, 395']), new \Slim\Psr7\Response())->getStatusCode());
    }

    public function testAccountInventoryActionRequiresDate(): void
    {
        $action = Bootstrap::buildContainer()->get(\MyInvoice\Action\Accounting\Reports\BalanceInventoryAction::class);
        $args = ['accountId' => (string) $this->accountId('221')];

        self::assertSame(422, $action->account($this->request(['as_of' => '2091-02-31']), new \Slim\Psr7\Response(), $args)->getStatusCode());
        self::assertSame(200, $action->account($this->request(['as_of' => self::TO]), new \Slim\Psr7\Response(), $args)->getStatusCode());
        self::assertSame(404, $action->account($this->request(['as_of' => self::TO]), new \Slim\Psr7\Response(), ['accountId' => '999999999'])->getStatusCode());
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /** @param array<string,string> $query */
    private function request(array $query): \Psr\Http\Message\ServerRequestInterface
    {
        return (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/')
            ->withQueryParams($query)
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
    }

    private function bankScenario(): void
    {
        $this->analytic('221', '221001', 'asset', 'debit');
        $this->analytic('221', '221002', 'asset', 'debit');
        $this->post([['221001', 'debit', 5000.0], ['411', 'credit', 5000.0]], '01-10');
        $this->post([['518', 'debit', 2000.0], ['221002', 'credit', 2000.0]], '02-10');
    }

    /** Protokol ve stavu z doby před analytikami: položka jen za syntetiku. */
    private function savedSyntheticInventory(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO accounting_balance_inventory (supplier_id, period_id, status) VALUES (?, ?, "completed")')
            ->execute([$this->supplierId, $this->periodId]);
        $inventoryId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO accounting_balance_inventory_items (supplier_id, inventory_id, account_id, account_code, book_balance, counted_balance, difference, resolution)
             VALUES (?, ?, ?, "221", 3000, 3000, 0, "resolved")'
        )->execute([$this->supplierId, $inventoryId, $this->accountId('221')]);
        return $inventoryId;
    }

    /** PS z otevíracího zápisu, pohyby v březnu a v červnu. */
    private function statementScenario(): void
    {
        $this->posting->postDocument($this->supplierId, 'opening', null, [
            self::l('221', 'debit', 1200.0), self::l('701', 'credit', 1200.0),
            self::l('701', 'debit', 1200.0), self::l('321', 'credit', 800.0), self::l('411', 'credit', 400.0),
        ], ['entry_date' => self::FROM, 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        $this->post([['311', 'debit', 1000.0], ['602', 'credit', 1000.0]], '03-01');
        $this->post([['551', 'debit', 40.0], ['082', 'credit', 40.0]], '03-02');
        $this->post([['518', 'debit', 200.0], ['321', 'credit', 200.0]], '06-15');
    }

    /** @return array<string,mixed> */
    private function statementOf(string $code, ?int $counter = null): array
    {
        return $this->statement->build($this->supplierId, $this->accountId($code), self::FROM, self::TO, 1, 200, false, $counter);
    }

    /** @return array<string,mixed> */
    private function columns(string $asOf, bool $hideZero = true): array
    {
        return $this->statements->accountView($this->supplierId, $this->periodId, $asOf, null, ['hide_zero' => $hideZero, 'analytics' => true])['columns'];
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @return list<array<string,mixed>>
     */
    private function cellsOf(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            if ($line['kind'] !== 'cells') {
                continue;
            }
            foreach ([$line['left'], $line['right']] as $cell) {
                if ($cell !== null && $cell['kind'] !== 'class') {
                    $out[] = $cell;
                }
            }
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @return array<string,array<string,mixed>>
     */
    private function cellsByCode(array $lines): array
    {
        return array_column($this->cellsOf($lines), null, 'account_code');
    }

    private function analytic(string $parent, string $code, string $type, ?string $normalSide): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id)
             VALUES (?, ?, ?, ?, ?, 0, ?)'
        )->execute([$this->supplierId, $code, 'Analytika ' . $code, $type, $normalSide, $this->accountId($parent)]);
    }

    /**
     * @param list<array{0:string,1:string,2:float}> $lines
     */
    private function post(array $lines, string $monthDay): int
    {
        return $this->posting->postDocument(
            $this->supplierId,
            'manual',
            null,
            array_map(static fn (array $l): array => self::l($l[0], $l[1], $l[2]), $lines),
            ['entry_date' => self::YEAR . '-' . $monthDay, 'posted_by' => $this->userId, 'user_id' => $this->userId],
        );
    }

    /** @param list<int> $entries */
    private function pair(string $code, array $entries): void
    {
        $in = implode(',', array_map('intval', $entries));
        $id = $this->accountId($code);
        $lineIds = array_map('intval', $this->db->pdo()->query(
            "SELECT id FROM journal_entry_lines WHERE supplier_id = {$this->supplierId} AND account_id = {$id} AND entry_id IN ({$in})"
        )->fetchAll(\PDO::FETCH_COLUMN));
        $this->openItems->create($this->supplierId, $id, $lineIds, null, ['user_id' => $this->userId, 'ip' => null, 'user_agent' => null]);
    }

    private function accountId(string $code): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?');
        $stmt->execute([$this->supplierId, $code]);
        $id = (int) $stmt->fetchColumn();
        self::assertGreaterThan(0, $id, 'Účet ' . $code . ' v osnově chybí.');
        return $id;
    }

    private function rowVersion(): int
    {
        return (int) $this->periods->findById($this->supplierId, $this->periodId)['row_version'];
    }

    /**
     * @param list<string> $parts
     * @param array<string,mixed>|null $manifest
     * @return array<string,string>
     */
    private function runPackage(array $parts, ?array &$manifest): array
    {
        $container = Bootstrap::buildContainer();
        $jobs = $container->get(ImportJobRepository::class);
        $package = $container->get(ClosingPackageService::class);
        $jobId = $jobs->create($this->supplierId, 'closing_package', ['period_id' => $this->periodId, 'parts' => $parts, 'include_xlsx' => true], $this->userId);
        $package->run($jobId);
        $job = $jobs->findById($jobId);
        self::assertNotEmpty($job['result_path'], (string) ($job['last_error'] ?? ''));
        $file = $package->resolveResultPath((string) $job['result_path']);
        $this->tmpFiles[] = $file;
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($file) === true);
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        $manifest = json_decode($entries['manifest.json'], true, 512, JSON_THROW_ON_ERROR);
        return $entries;
    }

    /** @param array{bytes:string} $out */
    private function spreadsheet(array $out): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $file = tempnam(sys_get_temp_dir(), 'inv') . '.xlsx';
        file_put_contents($file, $out['bytes']);
        $this->tmpFiles[] = $file;
        return IOFactory::load($file);
    }

    /** @return list<mixed> */
    private function rowByFirstCell(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $code): array
    {
        $row = $this->rowNumberByCell($sheet, 'A', $code);
        return $sheet->rangeToArray("A{$row}:J{$row}", null, false, false)[0];
    }

    private function rowNumberByCell(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $column, string $value): int
    {
        for ($r = 1, $n = $sheet->getHighestRow(); $r <= $n; $r++) {
            if (trim((string) $sheet->getCell($column . $r)->getValue()) === $value) {
                return $r;
            }
        }
        self::fail('Řádek ' . $value . ' v listu chybí.');
    }

    private function sheetText(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): string
    {
        $out = '';
        foreach ($sheet->toArray(null, false, false) as $row) {
            $out .= implode(' ', array_map(static fn (mixed $v): string => (string) $v, $row)) . "\n";
        }
        return $out;
    }

    /**
     * @return array{account_code:string, side:string, amount:float}
     */
    private static function l(string $code, string $side, float $amount): array
    {
        return ['account_code' => $code, 'side' => $side, 'amount' => $amount];
    }

    private static function cents(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100.0);
    }
}
