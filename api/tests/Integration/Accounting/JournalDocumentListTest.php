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
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RoutePermissionMap;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;
use MyInvoice\Service\Accounting\DocumentPostingGroup;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\EntryForm\EntryForm;
use MyInvoice\Service\Accounting\EntryForm\EntryFormService;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\JournalDocumentListService;
use MyInvoice\Service\Accounting\Reports\ReportException;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Soupiska dokladů z filtru Deníku (#356): výběr řádků v rozsahu účtů soupisky a v dimenzi
 * filtru, součet po prvotním dokladu se znaménkem, vynechání úplného storna a uzávěrky,
 * shoda se seznamem Deníku, povinný rozsah účtů a oddělení firem. Syntetická data
 * v transakci, tearDown rollbackne.
 */
#[Group('integration')]
final class JournalDocumentListTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const YEAR = 2099;
    private const DATE = '2099-04-15';
    private const LINES = ['line_account_from' => '5', 'line_account_to' => '5'];

    private Connection $db;
    private JournalAction $action;
    private JournalEntryRepository $journal;
    private PostingService $posting;
    private DimensionService $dimensions;
    private JournalDocumentListService $service;
    private ChartOfAccountsSeeder $seeder;
    private AccountingPeriodRepository $periods;
    private EntryFormService $entryForms;

    private int $supplierId = 0;
    private int $sourceSupplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private int $type = 0;
    private int $valueA = 0;
    private int $valueA1 = 0;
    private int $valueB = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->action = $container->get(JournalAction::class);
            $this->journal = $container->get(JournalEntryRepository::class);
            $this->posting = $container->get(PostingService::class);
            $this->dimensions = $container->get(DimensionService::class);
            $this->service = $container->get(JournalDocumentListService::class);
            $this->seeder = $container->get(ChartOfAccountsSeeder::class);
            $this->periods = $container->get(AccountingPeriodRepository::class);
            $this->entryForms = $container->get(EntryFormService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/user) v DB.');
        }
        $pdo->beginTransaction();
        $this->inTx = true;

        [$this->supplierId, $this->periodId] = $this->company();
        $this->type = $this->dimensions->ensureDefaultTypes($this->supplierId, ['stredisko'])['cost_center'];
        $this->valueA = $this->value($this->supplierId, $this->type, 'DOT-A');
        $this->valueA1 = $this->value($this->supplierId, $this->type, 'DOT-A1', $this->valueA);
        $this->valueB = $this->value($this->supplierId, $this->type, 'DOT-B');
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

    public function testSplitDocumentCountsOnlyTheFilteredDimensionPart(): void
    {
        $twoLines = $this->purchase('PF-1', 'DOD-100', 10000.00);
        $this->postPurchase($twoLines, [
            ['518', 'debit', 6000.00, ['dimensions' => [$this->type => $this->valueA]]],
            ['518', 'debit', 4000.00, ['dimensions' => [$this->type => $this->valueB]]],
            ['321', 'credit', 10000.00, ['dimensions' => [$this->type => $this->valueA]]],
        ]);
        $split = $this->purchase('PF-2', 'DOD-200', 1000.00);
        $this->postPurchase($split, [
            ['518', 'debit', 1000.00, ['dimension_splits' => [$this->type => [$this->valueA => 0.6, $this->valueB => 0.4]]]],
            ['321', 'credit', 1000.00],
        ]);

        $docs = $this->byDoc($this->select([$this->valueA]));

        self::assertCount(2, $docs, 'Každá faktura jednou, i když má víc řádků s dimenzí.');
        self::assertSame(6000.00, $docs['purchase_invoice:' . $twoLines]['amount']);
        self::assertSame(10000.00, $docs['purchase_invoice:' . $twoLines]['document_total']);
        self::assertSame('PF-1', $docs['purchase_invoice:' . $twoLines]['our_no']);
        self::assertSame('DOD-100', $docs['purchase_invoice:' . $twoLines]['counterparty_no']);
        self::assertSame('Syntetický dodavatel', $docs['purchase_invoice:' . $twoLines]['partner_name']);
        self::assertSame(600.00, $docs['purchase_invoice:' . $split]['amount'], 'Rozpad: jen díl dimenze A.');
        self::assertSame('518', $docs['purchase_invoice:' . $split]['accounts']);
        self::assertSame([], $docs['purchase_invoice:' . $split]['checks']);
    }

    public function testChildValuesFollowTheDescendantsSwitch(): void
    {
        $doc = $this->purchase('PF-3', 'DOD-300', 500.00);
        $this->postPurchase($doc, [
            ['518', 'debit', 500.00, ['dimensions' => [$this->type => $this->valueA1]]],
            ['321', 'credit', 500.00],
        ]);

        self::assertSame([500.00], array_column($this->select([$this->valueA]), 'amount'));
        self::assertSame([], $this->select([$this->valueA], [], false));
    }

    public function testPaymentWithTheSameDimensionIsNotCountedButBankFeeIsItsOwnDocument(): void
    {
        $doc = $this->purchase('PF-4', 'DOD-400', 800.00);
        $this->postPurchase($doc, [
            ['518', 'debit', 800.00, ['dimensions' => [$this->type => $this->valueA]]],
            ['321', 'credit', 800.00, ['dimensions' => [$this->type => $this->valueA]]],
        ]);
        $payment = $this->posting->postDocument($this->supplierId, 'bank', $this->bankTransaction(-800.00), [
            ['account_code' => '321', 'side' => 'debit', 'amount' => 800.00, 'dimensions' => [$this->type => $this->valueA]],
            ['account_code' => '221', 'side' => 'credit', 'amount' => 800.00],
        ], ['entry_date' => self::DATE, 'posted_by' => $this->userId]);
        $feeTx = $this->bankTransaction(-25.00);
        $this->posting->postDocument($this->supplierId, 'bank', $feeTx, [
            ['account_code' => '568', 'side' => 'debit', 'amount' => 25.00, 'dimensions' => [$this->type => $this->valueA]],
            ['account_code' => '221', 'side' => 'credit', 'amount' => 25.00],
        ], ['entry_date' => self::DATE, 'posted_by' => $this->userId]);

        $filter = ['dimension' => $this->dimensions->filters($this->supplierId, [$this->valueA])];
        self::assertContains($payment, $this->journal->filteredIds($this->supplierId, $filter, 100)['ids'],
            'Deník úhradu s dimenzí ukazuje.');
        $docs = $this->byDoc($this->select([$this->valueA]));
        self::assertSame(['purchase_invoice:' . $doc, 'bank:' . $feeTx], array_keys($docs));
        self::assertSame(800.00, $docs['purchase_invoice:' . $doc]['amount']);
        self::assertSame(25.00, $docs['bank:' . $feeTx]['amount']);
        self::assertSame(25.00, $docs['bank:' . $feeTx]['document_total']);
    }

    public function testFullReversalIsLeftOutAndRepostKeepsOneRow(): void
    {
        $cancelled = $this->purchase('PF-5', 'DOD-500', 300.00);
        $entry = $this->postPurchase($cancelled, [
            ['518', 'debit', 300.00, ['dimensions' => [$this->type => $this->valueA]]],
            ['321', 'credit', 300.00],
        ]);
        $this->posting->reverse($this->supplierId, $entry, ['entry_date' => self::DATE, 'posted_by' => $this->userId]);
        $manual = $this->manual([['518', 'debit', 70.00, ['dimensions' => [$this->type => $this->valueA]]], ['211', 'credit', 70.00]]);
        $this->posting->reverse($this->supplierId, $manual, ['entry_date' => self::DATE, 'posted_by' => $this->userId]);

        $reposted = $this->purchase('PF-6', 'DOD-600', 1000.00);
        $first = $this->postPurchase($reposted, [
            ['518', 'debit', 1000.00, ['dimensions' => [$this->type => $this->valueA]]],
            ['321', 'credit', 1000.00],
        ]);
        $storno = $this->posting->reverse($this->supplierId, $first, ['entry_date' => self::DATE, 'posted_by' => $this->userId]);
        $second = $this->postPurchase($reposted, [
            ['518', 'debit', 900.00, ['dimensions' => [$this->type => $this->valueA]]],
            ['321', 'credit', 900.00],
        ]);

        $docs = $this->byDoc($this->select([$this->valueA]));
        self::assertSame(['purchase_invoice:' . $reposted], array_keys($docs), 'Úplné storno faktury i ručního zápisu se vynechá.');
        self::assertSame(900.00, $docs['purchase_invoice:' . $reposted]['amount']);
        self::assertSame([$first, $storno, $second], $docs['purchase_invoice:' . $reposted]['entry_ids']);
    }

    public function testPairCorrectionStaysWithItsDocument(): void
    {
        $this->entryForms->schedule($this->supplierId, self::YEAR . '-02-01', EntryForm::Single, $this->userId);
        $doc = $this->purchase('PF-PAIR', 'DOD-PAIR', 1000.00);
        $entry = $this->postPurchase($doc, [
            ['518', 'debit', 600.00, ['dimensions' => [$this->type => $this->valueA]]],
            ['518', 'debit', 400.00, ['dimensions' => [$this->type => $this->valueA]]],
            ['321', 'credit', 1000.00],
        ]);
        $pairOf = function (float $amount) use ($entry): int {
            $stmt = $this->db->pdo()->prepare("SELECT pair_no FROM journal_entry_lines WHERE supplier_id = ? AND entry_id = ? AND side = 'debit' AND amount = ?");
            $stmt->execute([$this->supplierId, $entry, $amount]);
            return (int) $stmt->fetchColumn();
        };
        $meta = ['entry_date' => self::DATE, 'user_id' => $this->userId];
        $this->posting->reversePairs($this->supplierId, $entry, [$pairOf(400.00)], $meta);

        self::assertSame(['purchase_invoice:' . $doc], array_keys($this->byDoc($this->select([$this->valueA]))));
        self::assertSame([600.00], array_column($this->select([$this->valueA]), 'amount'), 'Storno dvojice snižuje doklad.');

        $correction = $this->posting->reversePairs($this->supplierId, $entry, [$pairOf(600.00)], $meta,
            ['debit_account_code' => '501', 'credit_account_code' => '321']);
        $rebooked = $this->byDoc($this->journal->documentSelection($this->supplierId, [], '501', '501', 100));
        self::assertSame(['purchase_invoice:' . $doc], array_keys($rebooked), 'Přeúčtovaná dvojice patří faktuře.');
        self::assertSame(600.00, $rebooked['purchase_invoice:' . $doc]['amount']);
        self::assertContains($correction, $rebooked['purchase_invoice:' . $doc]['entry_ids']);
        self::assertSame([], $this->journal->documentSelection($this->supplierId, [], '518', '518', 100));

        $this->posting->reverse($this->supplierId, $correction, $meta);
        $back = $this->byDoc($this->journal->documentSelection($this->supplierId, [], '518', '518', 100));
        self::assertSame(['purchase_invoice:' . $doc], array_keys($back), 'Storno opravného zápisu patří faktuře.');
        self::assertSame(600.00, $back['purchase_invoice:' . $doc]['amount']);
    }

    public function testSettlementAndItsReversalBelongToTheSettledInvoice(): void
    {
        $doc = $this->purchase('PF-STL', 'DOD-STL', 1005.00);
        $this->postPurchase($doc, [['518', 'debit', 1000.00], ['321', 'credit', 1000.00]]);
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO invoice_settlements (supplier_id, doc_type, doc_id, settled_on, amount, account_id)
                       VALUES (?, 'purchase_invoice', ?, ?, 5, (SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = '548'))")
            ->execute([$this->supplierId, $doc, self::DATE, $this->supplierId]);
        $settlement = $this->posting->postDocument($this->supplierId, 'settlement', (int) $pdo->lastInsertId(), [
            ['account_code' => '548', 'side' => 'debit', 'amount' => 5.00],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 5.00],
        ], ['entry_date' => self::DATE, 'posted_by' => $this->userId]);

        $docs = $this->byDoc($this->journal->documentSelection($this->supplierId, [], '5', '5', 100));
        self::assertSame(['purchase_invoice:' . $doc], array_keys($docs));
        self::assertSame(1005.00, $docs['purchase_invoice:' . $doc]['amount']);

        $this->posting->reverse($this->supplierId, $settlement, ['entry_date' => self::DATE, 'posted_by' => $this->userId]);
        $docs = $this->byDoc($this->journal->documentSelection($this->supplierId, [], '5', '5', 100));
        self::assertSame(['purchase_invoice:' . $doc], array_keys($docs), 'Storno zápočtu se nevyčlení do vlastního řádku.');
        self::assertSame(1000.00, $docs['purchase_invoice:' . $doc]['amount']);
    }

    public function testClosingEntriesAreLeftOut(): void
    {
        $doc = $this->purchase('PF-UZ', 'DOD-UZ', 400.00);
        $this->postPurchase($doc, [['518', 'debit', 400.00], ['321', 'credit', 400.00]]);
        $closing = $this->posting->postDocument($this->supplierId, 'closing', $this->periodId, [
            ['account_code' => '710', 'side' => 'debit', 'amount' => 400.00],
            ['account_code' => '518', 'side' => 'credit', 'amount' => 400.00],
        ], ['entry_date' => self::YEAR . '-12-31', 'posted_by' => $this->userId]);

        self::assertContains($closing, $this->journal->filteredIds($this->supplierId, [], 100)['ids']);
        $docs = $this->byDoc($this->journal->documentSelection($this->supplierId, [], '5', '5', 100));
        self::assertSame(['purchase_invoice:' . $doc], array_keys($docs));
    }

    public function testRefundAndRedStornoKeepTheirSign(): void
    {
        $doc = $this->purchase('PF-7', 'DOD-700', 1000.00);
        $this->postPurchase($doc, [
            ['518', 'debit', 1000.00, ['dimensions' => [$this->type => $this->valueA]]],
            ['321', 'credit', 1000.00],
        ]);
        $credit = $this->purchase('PF-8', 'DOD-800', -300.00);
        $this->postPurchase($credit, [
            ['321', 'debit', 300.00],
            ['518', 'credit', 300.00, ['dimensions' => [$this->type => $this->valueA]]],
        ]);
        $manual = $this->manual([
            ['518', 'debit', 50.00, ['is_red_storno' => true, 'dimensions' => [$this->type => $this->valueA]]],
            ['211', 'credit', 50.00, ['is_red_storno' => true]],
        ]);

        $docs = $this->byDoc($this->select([$this->valueA]));
        self::assertSame(1000.00, $docs['purchase_invoice:' . $doc]['amount']);
        self::assertSame(-300.00, $docs['purchase_invoice:' . $credit]['amount']);
        self::assertSame(-50.00, $docs['entry:' . $manual]['amount']);
        self::assertContains('Záporná částka (vratka nebo storno)', $docs['purchase_invoice:' . $credit]['checks']);
        self::assertContains('Zápis bez dokladu v evidenci', $docs['entry:' . $manual]['checks']);
        $report = $this->service->build($this->supplierId, ['dimension' => $this->dimensions->filters($this->supplierId, [$this->valueA])], '5', '5');
        self::assertSame(650.00, $report['totals']['amount']);
        self::assertSame(3, $report['totals']['count']);
        self::assertSame(2, $report['totals']['to_check']);
    }

    public function testInternalDocumentGivesNumberPartnerAndTotalAndItsReversalDisappears(): void
    {
        $kept = $this->internalDocument('ID-SYN-1', 700.00);
        $reversed = $this->internalDocument('ID-SYN-2', 200.00);
        $this->posting->reverse($this->supplierId, $reversed['entry'], ['entry_date' => self::DATE, 'posted_by' => $this->userId]);

        $docs = $this->byDoc($this->select([$this->valueA]));

        self::assertSame(['internal_document:' . $kept['id']], array_keys($docs));
        $row = $docs['internal_document:' . $kept['id']];
        self::assertSame('ID-SYN-1', $row['our_no']);
        self::assertSame('Syntetický partner', $row['partner_name']);
        self::assertSame(700.00, $row['document_total']);
        self::assertSame(self::DATE, $row['document_date']);
        self::assertSame([], $row['checks']);
    }

    public function testSelectionUsesExactlyTheJournalListFilter(): void
    {
        $inside = $this->purchase('PF-9', 'DOD-900', 100.00);
        $this->postPurchase($inside, [['518', 'debit', 100.00, ['dimensions' => [$this->type => $this->valueA]]], ['321', 'credit', 100.00]]);
        $outside = $this->purchase('PF-10', 'DOD-1000', 100.00);
        $this->postPurchase($outside, [['518', 'debit', 100.00, ['dimensions' => [$this->type => $this->valueA]]], ['321', 'credit', 100.00]], self::YEAR . '-09-01');
        $this->manual([['501', 'debit', 40.00, ['dimensions' => [$this->type => $this->valueA]]], ['211', 'credit', 40.00]]);

        $filters = [
            'date_from' => self::YEAR . '-04-01', 'date_to' => self::YEAR . '-04-30',
            'source_type' => 'purchase_invoice',
            'dimension' => $this->dimensions->filters($this->supplierId, [$this->valueA]),
        ];
        $rows = $this->journal->documentSelection($this->supplierId, $filters, '5', '5', 100);

        self::assertSame(['purchase_invoice:' . $inside], array_keys($this->byDoc($rows)));
        $listed = $this->journal->filteredIds($this->supplierId, $filters, 100)['ids'];
        foreach ($rows as $row) {
            self::assertSame([], array_diff($row['entry_ids'], $listed), 'Soupiska nebere zápis mimo seznam Deníku.');
        }
        self::assertSame([], $this->journal->documentSelection($this->supplierId, $filters, '6', '6', 100));
    }

    public function testAccountCodeInSearchNarrowsEntriesLikeTheJournalList(): void
    {
        $material = $this->purchase('PF-501', 'DOD-501', 100.00);
        $this->postPurchase($material, [['501', 'debit', 100.00], ['321', 'credit', 100.00]]);
        $services = $this->purchase('PF-518', 'DOD-518', 200.00);
        $this->postPurchase($services, [['518', 'debit', 200.00], ['321', 'credit', 200.00]]);

        $sheet = $this->sheet($this->call(self::LINES + ['q' => '518'])['raw']);

        self::assertSame('PF-518', $sheet->getCell('C9')->getValue());
        self::assertSame('Filtru neodpovídá žádný doklad s částkou k doložení.', $this->sheet($this->call(self::LINES + ['q' => '601'])['raw'])->getCell('A9')->getValue());
        self::assertNull($sheet->getCell('C10')->getValue(), 'Doklad na 501 hledání účtu 518 nevybere.');
        self::assertStringContainsString('zápisy s účtem 518 až 518', (string) $sheet->getCell('A4')->getValue());
    }

    public function testEndpointValidatesInputAndReturnsWorkbook(): void
    {
        $doc = $this->purchase('PF-11', 'DOD-1100', 1210.00);
        $this->postPurchase($doc, [['518', 'debit', 1210.00, ['dimensions' => [$this->type => $this->valueA]]], ['321', 'credit', 1210.00]]);
        $query = ['date_from' => self::YEAR . '-01-01', 'date_to' => self::YEAR . '-12-31', 'dimension_value_id' => (string) $this->valueA];

        foreach ([
            ['line_account_from' => '5'],
            ['line_account_from' => '5', 'line_account_to' => '  '],
            ['line_account_from' => '5', 'line_account_to' => '4'],
            self::LINES + ['format' => 'pdf'],
            self::LINES + ['date_from' => self::YEAR . '-02-31'],
            self::LINES + ['date_to' => "2099-01-01\r\nX-Test: 1"],
        ] as $invalid) {
            $res = $this->call($invalid + $query);
            self::assertSame(422, $res['status'], json_encode($invalid));
            self::assertSame('validation_failed', json_decode($res['raw'], true)['error']['code'] ?? null);
        }

        $res = $this->call($query + self::LINES + ['posted' => '1', 'reversal' => 'none', 'amount_from' => '100']);
        self::assertSame(200, $res['status']);
        self::assertStringContainsString('spreadsheetml', $res['contentType']);
        self::assertSame('attachment; filename="soupiska-dokladu-2099-01-01_2099-12-31.xlsx"', $res['disposition']);
        $sheet = $this->sheet($res['raw']);
        self::assertSame('Soupiska dokladů', $sheet->getCell('A1')->getValue());
        self::assertStringContainsString('Účty soupisky: 5 až 5', (string) $sheet->getCell('A3')->getValue());
        self::assertStringContainsString('Hodnota DOT-A', (string) $sheet->getCell('A3')->getValue());
        self::assertSame(1, substr_count((string) $sheet->getCell('A3')->getValue(), 'podřízených'), 'Údaj o podřízených hodnotách jen jednou.');
        self::assertStringContainsString('Hodnota DOT-A (vč. podřízených)', (string) $sheet->getCell('A3')->getValue());
        self::assertStringContainsString('(bez podřízených)', (string) $this->sheet($this->call($query + self::LINES + ['dimension_descendants' => '0'])['raw'])->getCell('A3')->getValue());
        self::assertSame('Další filtr Deníku: jen zaúčtované · bez storen · částka zápisu od 100,00', $sheet->getCell('A4')->getValue());
        self::assertStringContainsString('Pracovní podklad, stav k ', (string) $sheet->getCell('A5')->getValue());
        self::assertStringContainsString('Vytvořil: Syntetická účetní', (string) $sheet->getCell('A5')->getValue());
        self::assertStringContainsString('v rozsahu účtů soupisky a v dimenzi filtru', (string) $sheet->getCell('A6')->getValue());
        self::assertSame('PF-11', $sheet->getCell('C9')->getValue());
        self::assertSame('DOD-1100', $sheet->getCell('D9')->getValue());
        self::assertSame('15.04.2099', $sheet->getCell('E9')->getFormattedValue());
        self::assertIsNumeric($sheet->getCell('E9')->getValue(), 'Datum je skutečné datum, ne text.');
        self::assertSame('', (string) $sheet->getCell('K9')->getValue());
        self::assertEqualsWithDelta(1210.00, (float) $sheet->getCell('M9')->getValue(), 0.001);
        self::assertNull($sheet->getCell('N9')->getValue());
        self::assertEqualsWithDelta(1210.00, (float) $sheet->getCell('O9')->getValue(), 0.001);
        self::assertSame('Počet dokladů: 1 · k ověření: 0', $sheet->getCell('A11')->getValue());
        self::assertEqualsWithDelta(1210.00, (float) $sheet->getCell('O11')->getValue(), 0.001);

        $audit = $this->db->pdo()->prepare("SELECT payload FROM activity_log WHERE supplier_id = ? AND action = 'report.accounting_export' ORDER BY id DESC LIMIT 1");
        $audit->execute([$this->supplierId]);
        self::assertSame(['report' => 'journal_document_list', 'format' => 'xlsx'], json_decode((string) $audit->fetchColumn(), true));
    }

    public function testCheckColumnListsReasons(): void
    {
        $this->manual([['518', 'debit', 30.00], ['211', 'credit', 30.00]]);
        $sheet = $this->sheet($this->call(self::LINES)['raw']);
        self::assertSame('Zápis bez dokladu v evidenci', $sheet->getCell('K9')->getValue());
        self::assertSame('Počet dokladů: 1 · k ověření: 1', $sheet->getCell('A11')->getValue());
        self::assertSame('Další filtr Deníku: žádný', $sheet->getCell('A4')->getValue());
    }

    public function testOtherCompanyDataStayOutside(): void
    {
        [$other] = $this->company();
        $otherType = $this->dimensions->ensureDefaultTypes($other, ['stredisko'])['cost_center'];
        $otherValue = $this->value($other, $otherType, 'CIZI');
        $foreign = $this->purchase('PF-CIZI', 'DOD-CIZI', 100.00, $other);
        $this->postPurchase($foreign, [['518', 'debit', 100.00, ['dimensions' => [$otherType => $otherValue]]], ['321', 'credit', 100.00]], self::DATE, $other);
        $own = $this->purchase('PF-12', 'DOD-1200', 100.00);
        $this->postPurchase($own, [['518', 'debit', 100.00], ['321', 'credit', 100.00]]);

        self::assertSame(['purchase_invoice:' . $own], array_keys($this->byDoc($this->journal->documentSelection($this->supplierId, [], '5', '5', 100))));
        $res = $this->call(self::LINES + ['dimension_value_id' => (string) $otherValue]);
        self::assertGreaterThanOrEqual(400, $res['status']);
        self::assertLessThan(500, $res['status']);
    }

    public function testAccessFollowsTheJournalExport(): void
    {
        $map = new RoutePermissionMap();
        $export = $map->match('GET', '/api/accounting/reports/journal/export');
        $list = $map->match('GET', '/api/accounting/reports/journal/document-list');
        self::assertNotNull($list);
        self::assertSame($export?->key, $list->key);
        self::assertSame(AccessLevel::READ, $list->minimum);

        $this->db->pdo()->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = ?")->execute([$this->supplierId]);
        $res = $this->call(self::LINES);
        self::assertSame(403, $res['status']);
        self::assertSame('wrong_accounting_mode', json_decode($res['raw'], true)['error']['code'] ?? null);
    }

    public function testSelectionRequiresAccountRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->journal->documentSelection($this->supplierId, [], '5', '', 10);
    }

    public function testTooManyDocumentsIsRejected(): void
    {
        foreach (['PF-13', 'PF-14'] as $number) {
            $this->postPurchase($this->purchase($number, 'D-' . $number, 10.00), [['518', 'debit', 10.00], ['321', 'credit', 10.00]]);
        }
        self::assertSame(2, $this->service->build($this->supplierId, [], '5', '5', true, null, 2)['totals']['count']);
        try {
            $this->service->build($this->supplierId, [], '5', '5', true, null, 1);
            self::fail('Nad limit dokladů se soupiska nesestaví.');
        } catch (ReportException $e) {
            self::assertSame('too_many_rows', $e->errorCode);
            self::assertSame(422, $e->httpStatus);
        }
    }

    public function testEntriesOwnedByTheDocumentGroupStayWithIt(): void
    {
        $doc = $this->purchase('PF-SKUP', 'DOD-SKUP', 1000.00);
        $first = $this->postPurchase($doc, [['518', 'debit', 600.00], ['321', 'credit', 600.00]]);
        $owned = $this->manual([['518', 'debit', 400.00], ['321', 'credit', 400.00]]);
        (new DocumentPostingGroup($this->db))->attach($this->supplierId, 'purchase_invoice', $doc, [$owned]);

        $docs = $this->byDoc($this->journal->documentSelection($this->supplierId, [], '5', '5', 100));

        self::assertSame(['purchase_invoice:' . $doc], array_keys($docs), 'Zápis vlastněný skupinou zaúčtování patří dokladu.');
        self::assertSame(1000.00, $docs['purchase_invoice:' . $doc]['amount']);
        self::assertSame([$first, $owned], $docs['purchase_invoice:' . $doc]['entry_ids']);
    }

    public function testReversalOfReversalStaysWithTheDocument(): void
    {
        $doc = $this->purchase('PF-SS', 'DOD-SS', 1000.00);
        $original = $this->postPurchase($doc, [['518', 'debit', 1000.00], ['321', 'credit', 1000.00]]);
        $meta = ['entry_date' => self::DATE, 'posted_by' => $this->userId];
        $storno = $this->posting->reverse($this->supplierId, $original, $meta);
        $back = $this->posting->reverse($this->supplierId, $storno, $meta);

        $docs = $this->byDoc($this->journal->documentSelection($this->supplierId, [], '5', '5', 100));

        self::assertSame(['purchase_invoice:' . $doc], array_keys($docs));
        self::assertSame(1000.00, $docs['purchase_invoice:' . $doc]['amount']);
        self::assertSame([$original, $storno, $back], $docs['purchase_invoice:' . $doc]['entry_ids']);
    }

    public function testCorrectionOfACorrectionStaysWithTheDocument(): void
    {
        $this->entryForms->schedule($this->supplierId, self::YEAR . '-02-01', EntryForm::Single, $this->userId);
        $doc = $this->purchase('PF-CC', 'DOD-CC', 600.00);
        $entry = $this->postPurchase($doc, [['518', 'debit', 600.00], ['321', 'credit', 600.00]]);
        $meta = ['entry_date' => self::DATE, 'user_id' => $this->userId];
        $correction = $this->posting->reversePairs($this->supplierId, $entry, [1], $meta,
            ['debit_account_code' => '501', 'credit_account_code' => '321']);
        $stmt = $this->db->pdo()->prepare("SELECT l.pair_no FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id
                                            WHERE l.supplier_id = ? AND l.entry_id = ? AND a.account_code = '501'");
        $stmt->execute([$this->supplierId, $correction]);
        $second = $this->posting->reversePairs($this->supplierId, $correction, [(int) $stmt->fetchColumn()], $meta,
            ['debit_account_code' => '549', 'credit_account_code' => '321']);

        $docs = $this->byDoc($this->journal->documentSelection($this->supplierId, [], '5', '5', 100));

        self::assertSame(['purchase_invoice:' . $doc], array_keys($docs));
        self::assertSame(600.00, $docs['purchase_invoice:' . $doc]['amount']);
        self::assertSame('501, 518, 549', $docs['purchase_invoice:' . $doc]['accounts']);
        self::assertSame([$entry, $correction, $second], $docs['purchase_invoice:' . $doc]['entry_ids']);
    }

    public function testReversedCorrectionOutsideTheFilterIsCountedOnce(): void
    {
        $this->entryForms->schedule($this->supplierId, self::YEAR . '-02-01', EntryForm::Single, $this->userId);
        $doc = $this->purchase('PF-CS', 'DOD-CS', 600.00);
        $entry = $this->postPurchase($doc, [['518', 'debit', 600.00], ['321', 'credit', 600.00]]);
        $correction = $this->posting->reversePairs($this->supplierId, $entry, [1], ['entry_date' => self::DATE, 'user_id' => $this->userId],
            ['debit_account_code' => '501', 'credit_account_code' => '321']);
        $this->posting->reverse($this->supplierId, $correction, ['entry_date' => self::YEAR . '-09-10', 'posted_by' => $this->userId]);

        $april = $this->service->build($this->supplierId, ['date_from' => self::YEAR . '-04-01', 'date_to' => self::YEAR . '-04-30'], '501', '501')['documents'];

        self::assertSame(['purchase_invoice:' . $doc], array_keys($this->byDoc($april)), 'Stornovaná oprava dvojice není vlastní kořen.');
        self::assertSame(600.00, $april[0]['amount']);
        self::assertTrue($april[0]['corrected_outside']);
    }

    public function testRevenueRangeCountsCreditSideAsPositive(): void
    {
        $client = $this->client();
        $pdo = $this->db->pdo();
        $currency = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $ids = [];
        foreach ([['FV-V1', 1000.00], ['FV-V2', -200.00]] as [$number, $total]) {
            $pdo->prepare("INSERT INTO invoices (supplier_id, invoice_type, client_id, varsymbol, issue_date, tax_date, due_date, currency_id, status, total_with_vat, created_by)
                           VALUES (?, 'invoice', ?, ?, ?, ?, ?, ?, 'issued', ?, ?)")
                ->execute([$this->supplierId, $client, $number, self::DATE, self::DATE, self::DATE, $currency, $total, $this->userId]);
            $ids[$number] = (int) $pdo->lastInsertId();
        }
        $this->posting->postDocument($this->supplierId, 'invoice', $ids['FV-V1'], self::lines([['311', 'debit', 1000.00], ['602', 'credit', 1000.00]]),
            ['entry_date' => self::DATE, 'posted_by' => $this->userId]);
        $this->posting->postDocument($this->supplierId, 'invoice', $ids['FV-V2'], self::lines([['602', 'debit', 200.00], ['311', 'credit', 200.00]]),
            ['entry_date' => self::DATE, 'posted_by' => $this->userId]);

        $docs = $this->byDoc($this->service->build($this->supplierId, [], '6', '6')['documents']);

        self::assertSame(1000.00, $docs['invoice:' . $ids['FV-V1']]['amount'], 'Výnos je kladný (Dal − MD).');
        self::assertSame([], $docs['invoice:' . $ids['FV-V1']]['checks']);
        self::assertSame(-200.00, $docs['invoice:' . $ids['FV-V2']]['amount'], 'Vratka výnosu je záporná.');
        self::assertContains('Záporná částka (vratka nebo storno)', $docs['invoice:' . $ids['FV-V2']]['checks']);

    }

    public function testOnlyRevenueAccountsFlipTheSign(): void
    {
        $doc = $this->purchase('PF-DPH', 'DOD-DPH', 1210.00);
        $this->postPurchase($doc, [['518', 'debit', 1000.00], ['343', 'debit', 210.00], ['321', 'credit', 1210.00]]);
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE chart_of_accounts SET normal_side = NULL WHERE supplier_id = ? AND account_code IN ('343', '518')")->execute([$this->supplierId]);

        $vat = $this->journal->documentSelection($this->supplierId, [], '343', '343', 100);
        self::assertSame([210.00], array_column($vat, 'amount'), 'DPH na vstupu (MD 343, typ závazek) zůstává kladná.');
        self::assertSame([-1210.00], array_column($this->journal->documentSelection($this->supplierId, [], '321', '321', 100), 'amount'), 'Závazek 321 se neotáčí.');
        self::assertSame([1000.00], array_column($this->journal->documentSelection($this->supplierId, [], '518', '518', 100), 'amount'), 'Náklad bez normal_side zůstává MD − Dal.');

        $client = $this->client();
        $currency = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $pdo->prepare("INSERT INTO invoices (supplier_id, invoice_type, client_id, varsymbol, issue_date, tax_date, due_date, currency_id, status, total_with_vat, created_by)
                       VALUES (?, 'invoice', ?, 'FV-MIX', ?, ?, ?, ?, 'issued', 1210, ?)")
            ->execute([$this->supplierId, $client, self::DATE, self::DATE, self::DATE, $currency, $this->userId]);
        $invoice = (int) $pdo->lastInsertId();
        $this->posting->postDocument($this->supplierId, 'invoice', $invoice, self::lines([['311', 'debit', 1210.00], ['602', 'credit', 1000.00], ['343', 'credit', 210.00]]),
            ['entry_date' => self::DATE, 'posted_by' => $this->userId]);

        $wide = $this->byDoc($this->service->build($this->supplierId, [], '1', '9')['documents']);
        self::assertSame(2000.00, $wide['invoice:' . $invoice]['amount']);
        $mixed = 'V rozsahu jsou účty různých druhů (náklady, výnosy, rozvaha), částka sčítá obě strany';
        self::assertContains($mixed, $wide['invoice:' . $invoice]['checks']);
        self::assertSame([0.00, [$mixed]], [$wide['purchase_invoice:' . $doc]['amount'], $wide['purchase_invoice:' . $doc]['checks']],
            'Vyrovnaný doklad přes rozvahu i náklady zůstane s nulou a upozorněním.');
        $balanceAndCost = $this->byDoc($this->service->build($this->supplierId, [], '3', '5')['documents']);
        self::assertSame([$mixed], $balanceAndCost['purchase_invoice:' . $doc]['checks']);
        self::assertFalse($vat[0]['mixed_sides'], 'Doklad s jedním druhem účtů v rozsahu se neoznačí.');
    }

    public function testReverseChargeVatIsShownWithinTheInvoiceRow(): void
    {
        $rc = $this->purchase('PF-RC', 'DOD-RC', 1000.00);
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET reverse_charge = 1 WHERE id = ?')->execute([$rc]);
        $this->postPurchase($rc, [
            ['518', 'debit', 726.00, ['dimensions' => [$this->type => $this->valueA]]],
            ['518', 'debit', 484.00, ['dimensions' => [$this->type => $this->valueB]]],
            ['343', 'credit', 210.00], ['321', 'credit', 1000.00],
        ]);
        $payer = $this->purchase('PF-RCP', 'DOD-RCP', 500.00);
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET reverse_charge = 1 WHERE id = ?')->execute([$payer]);
        $this->postPurchase($payer, [['518', 'debit', 500.00], ['343', 'debit', 105.00], ['343', 'credit', 105.00], ['321', 'credit', 500.00]]);
        $plain = $this->purchase('PF-NRC', 'DOD-NRC', 1000.00);
        $this->postPurchase($plain, [['518', 'debit', 1210.00], ['343', 'credit', 210.00], ['321', 'credit', 1000.00]]);
        $rcText = 'Reverse charge: k doložení zahrnuje DPH přiznanou odběratelem, která na faktuře není; doložte ji výpočtem k faktuře a rekapitulací přiznání a úhrady za období';

        $all = $this->service->build($this->supplierId, [], '5', '5');
        $rows = array_map(static fn (array $r): array => [$r['our_no'], $r['amount'], $r['document_total'], $r['self_assessed_vat'], $r['checks']], $all['documents']);

        self::assertContains(['PF-RC', 1210.00, 1000.00, 210.00, [$rcText]], $rows);
        self::assertContains(['PF-RCP', 500.00, 500.00, null, []], $rows, 'Plátce s odpočtem nemá DPH v nákladu.');
        self::assertContains(['PF-NRC', 1210.00, 1000.00, null, ['K doložení převyšuje celkovou částku dokladu']], $rows);
        self::assertSame([3, 2920.00], [$all['totals']['count'], $all['totals']['amount']]);

        $dim = $this->service->build($this->supplierId, ['dimension' => $this->dimensions->filters($this->supplierId, [$this->valueA])], '5', '5')['documents'];
        self::assertSame([[726.00, null]], array_map(static fn (array $r): array => [$r['amount'], $r['self_assessed_vat']], $dim),
            'Společná částka na 343 neurčuje daň jednoho z nákladových řádků.');

        $sheet = $this->sheet($this->call(self::LINES + ['source_type' => 'purchase_invoice', 'source_id' => (string) $rc])['raw']);
        self::assertSame('DPH reverse charge (Kč)', $sheet->getCell('N8')->getValue());
        self::assertSame([1000.0, 210.0, 1210.0], [(float) $sheet->getCell('M9')->getValue(), (float) $sheet->getCell('N9')->getValue(), (float) $sheet->getCell('O9')->getValue()]);
        self::assertEqualsWithDelta(1210.00, (float) $sheet->getCell('O11')->getValue(), 0.001);
    }

    public function testForeignReverseChargeUsesPostedCzkIncludingReversal(): void
    {
        $id = $this->purchase('PF-RC-EUR', 'DOD-RC-EUR', 40.00);
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET reverse_charge = 1,
            currency_id = (SELECT id FROM currencies WHERE code = 'EUR' LIMIT 1), exchange_rate = 25 WHERE id = ?")->execute([$id]);
        $entry = $this->postPurchase($id, [['518', 'debit', 1210.00], ['343', 'credit', 210.00], ['321', 'credit', 1000.00]]);
        $sheet = $this->sheet($this->call(self::LINES)['raw']);
        self::assertSame(['EUR', 40.0, 210.0, 1210.0], [$sheet->getCell('L9')->getValue(),
            (float) $sheet->getCell('M9')->getValue(), (float) $sheet->getCell('N9')->getValue(), (float) $sheet->getCell('O9')->getValue()]);

        $this->posting->reverse($this->supplierId, $entry, ['entry_date' => self::YEAR . '-05-01', 'posted_by' => $this->userId]);
        $row = $this->service->build($this->supplierId, ['date_from' => self::YEAR . '-05-01'], '5', '5')['documents'][0];
        self::assertSame([-1210.0, -210.0], [$row['amount'], $row['self_assessed_vat']]);
    }

    public function testSeparatePostingsPreserveDifferentRatesByDimension(): void
    {
        $id = $this->purchase('PF-RC-DIM', 'DOD-RC-DIM', 2000.00);
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET reverse_charge = 1 WHERE id = ?')->execute([$id]);
        $this->postPurchase($id, [
            ['518', 'debit', 1210.00, ['dimensions' => [$this->type => $this->valueA]]],
            ['343', 'credit', 210.00], ['321', 'credit', 1000.00],
        ]);
        $otherEntry = $this->manual([
            ['518', 'debit', 1120.00, ['dimensions' => [$this->type => $this->valueB]]],
            ['343', 'credit', 120.00], ['321', 'credit', 1000.00],
        ]);
        (new DocumentPostingGroup($this->db))->attach($this->supplierId, 'purchase_invoice', $id, [$otherEntry]);
        self::assertSame([210.0], array_column($this->select([$this->valueA]), 'self_assessed_vat'));
        self::assertSame([120.0], array_column($this->select([$this->valueB]), 'self_assessed_vat'));
    }

    public function testUnallocatedTaxIsLeftBlankInTheWorkbook(): void
    {
        $id = $this->purchase('PF-RC-MIX', 'DOD-RC-MIX', 2000.00);
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET reverse_charge = 1 WHERE id = ?')->execute([$id]);
        $this->postPurchase($id, [
            ['518', 'debit', 1210.00, ['dimensions' => [$this->type => $this->valueA]]],
            ['518', 'debit', 1120.00, ['dimensions' => [$this->type => $this->valueB]]],
            ['343', 'credit', 330.00], ['321', 'credit', 2000.00],
        ]);
        $sheet = $this->sheet($this->call(self::LINES + ['dimension_value_id' => (string) $this->valueA])['raw']);
        self::assertNull($sheet->getCell('N9')->getValue());
        self::assertStringContainsString('Reverse charge', (string) $sheet->getCell('K9')->getValue());
        self::assertSame(1210.00, (float) $sheet->getCell('O9')->getValue());
        self::assertSame(330.00, $this->service->build($this->supplierId, [], '5', '5')['documents'][0]['self_assessed_vat']);
    }

    public function testPortionOfOneCostLineDoesNotProveTheTaxPortion(): void
    {
        $id = $this->purchase('PF-RC-ROZPAD', 'DOD-RC-ROZPAD', 2000.00);
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET reverse_charge = 1 WHERE id = ?')->execute([$id]);
        $this->postPurchase($id, [
            ['518', 'debit', 2330.00, ['dimension_splits' => [$this->type => [$this->valueA => 0.6, $this->valueB => 0.4]]]],
            ['343', 'credit', 330.00], ['321', 'credit', 2000.00],
        ]);
        $row = $this->select([$this->valueA])[0];
        self::assertSame([1398.00, null], [$row['amount'], $row['self_assessed_vat']]);
        self::assertStringContainsString('podíl DPH', implode(' ', $row['checks']));
    }

    public function testPurchaseWithTaxOnAnotherPostingIsFlagged(): void
    {
        $id = $this->purchase('PF-RC-ZVL', 'DOD-RC-ZVL', 1000.00);
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET reverse_charge = 1 WHERE id = ?')->execute([$id]);
        $this->postPurchase($id, [
            ['518', 'debit', 1000.00, ['dimensions' => [$this->type => $this->valueA]]],
            ['321', 'credit', 1000.00],
        ]);
        $taxEntry = $this->manual([['518', 'debit', 210.00], ['343', 'credit', 210.00]]);
        (new DocumentPostingGroup($this->db))->attach($this->supplierId, 'purchase_invoice', $id, [$taxEntry]);
        $row = $this->select([$this->valueA])[0];
        self::assertNull($row['self_assessed_vat']);
        self::assertStringContainsString('podíl DPH', implode(' ', $row['checks']));
    }

    public function testMixedSupplierAndSelfAssessedVatRequireReconciliation(): void
    {
        $id = $this->purchase('PF-RC-TUZ', 'DOD-RC-TUZ', 2120.00);
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET reverse_charge = 1, total_without_vat = 2000, total_vat = 120 WHERE id = ?')->execute([$id]);
        $this->postPurchase($id, [
            ['518', 'debit', 2165.00], ['343', 'debit', 165.00], ['343', 'credit', 210.00], ['321', 'credit', 2120.00],
        ]);
        $row = $this->service->build($this->supplierId, [], '5', '5')['documents'][0];
        self::assertNull($row['self_assessed_vat']);
        self::assertStringContainsString('podíl DPH', implode(' ', $row['checks']));
    }

    public function testBothInvoiceKindsUseHistoricalPartnerIdentity(): void
    {
        $id = $this->purchase('PF-SNAP', 'DOD-SNAP', 1000.00);
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE purchase_invoices SET vendor_snapshot = ? WHERE id = ?')
            ->execute([json_encode(['company_name' => 'Původní dodavatel', 'ic' => '00123456'], JSON_THROW_ON_ERROR), $id]);
        $this->postPurchase($id, [['518', 'debit', 1000.00], ['321', 'credit', 1000.00]]);

        $client = $this->client();
        $pdo->prepare("UPDATE clients SET ic = '87654321' WHERE id = ?")->execute([$client]);
        $pdo->prepare("INSERT INTO invoices (supplier_id, invoice_type, client_id, client_snapshot, varsymbol, issue_date, due_date,
                           currency_id, status, total_with_vat, created_by)
                       SELECT ?, 'invoice', ?, ?, 'FV-SNAP', ?, ?, id, 'issued', 100, ? FROM currencies WHERE code = 'CZK' LIMIT 1")
            ->execute([$this->supplierId, $client, json_encode(['first_name' => 'Anna', 'last_name' => 'Vzorová', 'ic' => null], JSON_THROW_ON_ERROR),
                self::DATE, self::DATE, $this->userId]);
        $invoice = (int) $pdo->lastInsertId();
        $this->posting->postDocument($this->supplierId, 'invoice', $invoice, self::lines([['311', 'debit', 100.00], ['518', 'credit', 100.00]]),
            ['entry_date' => self::DATE, 'posted_by' => $this->userId]);
        $docs = $this->byDoc($this->service->build($this->supplierId, [], '5', '5')['documents']);
        self::assertSame(['Původní dodavatel', '00123456'], [$docs['purchase_invoice:' . $id]['partner_name'], $docs['purchase_invoice:' . $id]['partner_ic']]);
        self::assertSame(['Anna Vzorová', null], [$docs['invoice:' . $invoice]['partner_name'], $docs['invoice:' . $invoice]['partner_ic']]);
    }

    public function testExcludedDraftsAreCountedInTheHeader(): void
    {
        $doc = $this->purchase('PF-KONC', 'DOD-KONC', 100.00);
        $this->postPurchase($doc, [['518', 'debit', 100.00], ['321', 'credit', 100.00]]);
        $draft = $this->manual([['518', 'debit', 40.00], ['211', 'credit', 40.00]]);
        $other = $this->manual([['211', 'debit', 40.00], ['602', 'credit', 40.00]]);
        $dimensionElsewhere = $this->manual([['518', 'debit', 30.00], ['602', 'credit', 30.00, ['dimensions' => [$this->type => $this->valueA]]]]);
        $closing = $this->posting->postDocument($this->supplierId, 'closing', $this->periodId, self::lines([['710', 'debit', 40.00], ['518', 'credit', 40.00]]),
            ['entry_date' => self::YEAR . '-12-31', 'posted_by' => $this->userId]);
        $this->db->pdo()->prepare('UPDATE journal_entries SET posted_at = NULL WHERE supplier_id = ? AND id IN (?, ?, ?, ?)')
            ->execute([$this->supplierId, $draft, $other, $dimensionElsewhere, $closing]);

        self::assertSame(2, $this->service->build($this->supplierId, [], '5', '5')['filters']['drafts_excluded'],
            'Koncept mimo rozsah účtů ani uzavření knih se nepočítá.');
        self::assertSame(0, $this->service->build($this->supplierId, ['dimension' => $this->dimensions->filters($this->supplierId, [$this->valueA])], '5', '5')['filters']['drafts_excluded'],
            'Dimenze na jiném řádku než v rozsahu koncept nezapočte (stejně jako výběr soupisky).');
        $res = $this->call(self::LINES);
        $sheet = $this->sheet($res['raw']);
        self::assertSame('Další filtr Deníku: žádný', $sheet->getCell('A4')->getValue());
        self::assertStringEndsWith(' · Nezaúčtované koncepty (nezahrnuty): 2', (string) $sheet->getCell('A5')->getValue());
        self::assertSame('attachment; filename="soupiska-dokladu-vse_vse.xlsx"', $res['disposition']);
    }

    public function testCorrectionOfAReversalStaysWithTheDocument(): void
    {
        $this->entryForms->schedule($this->supplierId, self::YEAR . '-02-01', EntryForm::Single, $this->userId);
        $doc = $this->purchase('PF-CR', 'DOD-CR', 600.00);
        $original = $this->postPurchase($doc, [['518', 'debit', 600.00], ['321', 'credit', 600.00]]);
        $storno = $this->posting->reverse($this->supplierId, $original, ['entry_date' => self::DATE, 'posted_by' => $this->userId]);
        $correction = $this->posting->reversePairs($this->supplierId, $storno, [1], ['entry_date' => self::DATE, 'user_id' => $this->userId],
            ['debit_account_code' => '321', 'credit_account_code' => '501']);

        $docs = $this->byDoc($this->journal->documentSelection($this->supplierId, [], '501', '501', 100));

        self::assertSame(['purchase_invoice:' . $doc], array_keys($docs), 'Oprava protizápisu nedělá z protizápisu vlastní kořen.');
        self::assertSame(-600.00, $docs['purchase_invoice:' . $doc]['amount']);
        self::assertSame([$correction], $docs['purchase_invoice:' . $doc]['entry_ids'], 'Na 501 je jen opravný zápis.');
        self::assertNotSame($original, $storno);
    }

    public function testDraftReversalLeavesTheDocumentFlagged(): void
    {
        $doc = $this->purchase('PF-KS', 'DOD-KS', 300.00);
        $entry = $this->postPurchase($doc, [['518', 'debit', 300.00], ['321', 'credit', 300.00]]);
        $storno = $this->posting->reverse($this->supplierId, $entry, ['entry_date' => self::DATE, 'posted_by' => $this->userId]);
        $this->db->pdo()->prepare('UPDATE journal_entries SET posted_at = NULL WHERE supplier_id = ? AND id = ?')->execute([$this->supplierId, $storno]);

        $docs = $this->byDoc($this->service->build($this->supplierId, [], '5', '5')['documents']);

        self::assertSame(300.00, $docs['purchase_invoice:' . $doc]['amount']);
        self::assertTrue($docs['purchase_invoice:' . $doc]['corrected_outside']);
    }

    public function testGroupIsDescribedByItsOwnPostingNotByTheReversal(): void
    {
        $doc = $this->purchase('PF-POPIS', 'DOD-POPIS', 500.00);
        $first = $this->postPurchase($doc, [['518', 'debit', 500.00], ['321', 'credit', 500.00]], self::YEAR . '-03-10');
        $this->posting->reverse($this->supplierId, $first, ['entry_date' => self::DATE, 'posted_by' => $this->userId]);
        $second = $this->postPurchase($doc, [['518', 'debit', 450.00], ['321', 'credit', 450.00]]);
        $this->db->pdo()->prepare("UPDATE journal_entries SET description = 'Nové zaúčtování' WHERE supplier_id = ? AND id = ?")->execute([$this->supplierId, $second]);

        $rows = $this->service->build($this->supplierId, ['date_from' => self::YEAR . '-04-01', 'date_to' => self::YEAR . '-04-30'], '5', '5')['documents'];

        self::assertSame([-50.00], array_column($rows, 'amount'));
        self::assertSame('Nové zaúčtování', $rows[0]['description']);
    }

    public function testDeletedDocumentKeepsTheEntryNumber(): void
    {
        $doc = $this->purchase('PF-SMAZ', 'DOD-SMAZ', 100.00);
        $entry = $this->postPurchase($doc, [['518', 'debit', 100.00], ['321', 'credit', 100.00]]);
        $owned = $this->manual([['518', 'debit', 20.00], ['321', 'credit', 20.00]]);
        (new DocumentPostingGroup($this->db))->attach($this->supplierId, 'purchase_invoice', $doc, [$owned]);
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE journal_entries SET document_no = 'PREVZ-7' WHERE supplier_id = ? AND id = ?")->execute([$this->supplierId, $entry]);
        $pdo->prepare('DELETE FROM purchase_invoices WHERE supplier_id = ? AND id = ?')->execute([$this->supplierId, $doc]);

        $rows = $this->service->build($this->supplierId, [], '5', '5')['documents'];

        self::assertCount(1, $rows);
        self::assertSame(['PREVZ-7', 120.00, ['Celková částka dokladu není v evidenci']], [$rows[0]['our_no'], $rows[0]['amount'], $rows[0]['checks']]);
    }

    public function testTravelExpenseIsItsOwnRowAndTakenOverClosingIsLeftOut(): void
    {
        $this->posting->postDocument($this->supplierId, 'payroll_travel', 77, self::lines([['512', 'debit', 350.00], ['333', 'credit', 350.00]]),
            ['entry_date' => self::DATE, 'posted_by' => $this->userId]);
        $this->posting->postDocument($this->supplierId, 'closing', null, self::lines([['710', 'debit', 350.00], ['512', 'credit', 350.00]]),
            ['entry_date' => self::YEAR . '-12-31', 'posted_by' => $this->userId]);

        $rows = $this->service->build($this->supplierId, [], '5', '5')['documents'];

        self::assertSame([['payroll_travel', 77, 'Vyúčtování pracovní cesty', 350.00, ['Celková částka dokladu není v evidenci']]],
            array_map(static fn (array $r): array => [$r['doc_kind'], $r['doc_id'], $r['kind_label'], $r['amount'], $r['checks']], $rows),
            'Regresní pojistka: převzatá uzávěrka bez source_id se vynechá (closeBooksSql).');
    }

    public function testReversalOutsideTheFilterIsFlagged(): void
    {
        $doc = $this->purchase('PF-ZARI', 'DOD-ZARI', 300.00);
        $entry = $this->postPurchase($doc, [['518', 'debit', 300.00], ['321', 'credit', 300.00]]);
        $kept = $this->purchase('PF-DUBEN', 'DOD-DUBEN', 100.00);
        $this->postPurchase($kept, [['518', 'debit', 100.00], ['321', 'credit', 100.00]]);
        $this->posting->reverse($this->supplierId, $entry, ['entry_date' => self::YEAR . '-09-10', 'posted_by' => $this->userId]);

        $april = $this->byDoc($this->service->build($this->supplierId, ['date_from' => self::YEAR . '-04-01', 'date_to' => self::YEAR . '-04-30'], '5', '5')['documents']);
        self::assertSame(['Storno nebo oprava dokladu leží mimo filtr (jiné období, koncept nebo jiný filtr Deníku)'], $april['purchase_invoice:' . $doc]['checks']);
        self::assertSame([], $april['purchase_invoice:' . $kept]['checks']);

        $year = $this->byDoc($this->service->build($this->supplierId, [], '5', '5')['documents']);
        self::assertSame(['purchase_invoice:' . $kept], array_keys($year), 'Celý rok: storno je ve výběru, doklad vypadne.');
        self::assertFalse($year['purchase_invoice:' . $kept]['corrected_outside']);
    }

    public function testDocumentFactsForEachKind(): void
    {
        $pdo = $this->db->pdo();
        $eur = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'EUR' ORDER BY id LIMIT 1")->fetchColumn();
        $purchase = $this->purchase('PF-EUR', 'DOD-EUR', 40.00);
        $pdo->prepare('UPDATE purchase_invoices SET currency_id = ?, issue_date = ?, tax_date = ? WHERE id = ?')
            ->execute([$eur, self::YEAR . '-04-01', self::YEAR . '-04-10', $purchase]);
        $this->postPurchase($purchase, [['518', 'debit', 1000.00], ['321', 'credit', 1000.00]]);

        $client = $this->client();
        $currency = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $pdo->prepare("INSERT INTO invoices (supplier_id, invoice_type, client_id, varsymbol, issue_date, tax_date, due_date, currency_id, status, total_with_vat, created_by)
                       VALUES (?, 'invoice', ?, 'FV-1', ?, ?, ?, ?, 'issued', 250, ?)")
            ->execute([$this->supplierId, $client, self::YEAR . '-04-02', self::YEAR . '-04-03', self::DATE, $currency, $this->userId]);
        $invoice = (int) $pdo->lastInsertId();
        $this->posting->postDocument($this->supplierId, 'invoice', $invoice, self::lines([['311', 'debit', 250.00], ['518', 'credit', 250.00]]),
            ['entry_date' => self::DATE, 'posted_by' => $this->userId]);

        $pdo->prepare("INSERT INTO cash_registers (supplier_id, name, currency_code, account_code, is_default, is_active) VALUES (?, 'Pokladna soupiska', 'EUR', '211', 1, 1)")
            ->execute([$this->supplierId]);
        $pdo->prepare("INSERT INTO cash_documents (supplier_id, register_id, doc_type, purpose, doc_number, issue_date, tax_date, description, vat_mode,
                           total_amount, currency_code, amount_foreign, fx_rate, partner_name, partner_ic, status, created_by)
                       VALUES (?, ?, 'out', 'purchase', 'PD-1', ?, ?, 'Nákup', 'none', 250, 'EUR', 10, 25, 'Syntetický obchod', '12345678', 'posted', ?)")
            ->execute([$this->supplierId, (int) $pdo->lastInsertId(), self::YEAR . '-04-05', self::YEAR . '-04-06', $this->userId]);
        $cash = (int) $pdo->lastInsertId();
        $this->posting->postDocument($this->supplierId, 'cash', $cash, self::lines([['501', 'debit', 250.00], ['211', 'credit', 250.00]]),
            ['entry_date' => self::DATE, 'posted_by' => $this->userId]);

        $pdo->prepare("INSERT INTO other_items (supplier_id, side, title, partner_name, issued_on, accounting_on, due_on, currency, amount, amount_czk,
                           account_code, counter_account_code, status, document_no, posted_at)
                       VALUES (?, 'payable', 'Nájem', 'Syntetický pronajímatel', ?, ?, ?, 'CZK', 80, 80, '379', '518', 'posted', 'OST-1', NOW())")
            ->execute([$this->supplierId, self::YEAR . '-04-07', self::YEAR . '-04-07', self::DATE]);
        $other = (int) $pdo->lastInsertId();
        $this->posting->postDocument($this->supplierId, 'other_item', $other, self::lines([['518', 'debit', 80.00], ['379', 'credit', 80.00]]),
            ['entry_date' => self::DATE, 'posted_by' => $this->userId]);

        $draft = $this->manual([['501', 'debit', 5.00], ['211', 'credit', 5.00]]);
        $pdo->prepare('UPDATE journal_entries SET posted_at = NULL WHERE supplier_id = ? AND id = ?')->execute([$this->supplierId, $draft]);

        $docs = $this->byDoc($this->service->build($this->supplierId, [], '5', '5')['documents']);

        $p = $docs['purchase_invoice:' . $purchase];
        self::assertSame([self::YEAR . '-04-10', 40.00, 'EUR'], [$p['document_date'], $p['document_total'], $p['document_currency']]);
        self::assertSame(['Doklad v cizí měně, celkem v EUR'], $p['checks']);
        $i = $docs['invoice:' . $invoice];
        self::assertSame(['FV-1', self::YEAR . '-04-03', 250.00, 'CZK', -250.00, 'Syntetický odběratel'],
            [$i['our_no'], $i['document_date'], $i['document_total'], $i['document_currency'], $i['amount'], $i['partner_name']]);
        $c = $docs['cash:' . $cash];
        self::assertSame(['PD-1', self::YEAR . '-04-06', 250.00, 'CZK', 250.00, 'Syntetický obchod', '12345678', []],
            [$c['our_no'], $c['document_date'], $c['document_total'], $c['document_currency'], $c['amount'], $c['partner_name'], $c['partner_ic'], $c['checks']]);
        $o = $docs['other_item:' . $other];
        self::assertSame(['OST-1', self::YEAR . '-04-07', 80.00, 'CZK', 'Syntetický pronajímatel'],
            [$o['our_no'], $o['document_date'], $o['document_total'], $o['document_currency'], $o['partner_name']]);
        self::assertArrayNotHasKey('entry:' . $draft, $docs, 'Koncept se do soupisky nepočítá (jako výsledovka po dimenzi).');
    }

    public function testBookClosingAndOpeningAreLeftOutButStockClosingStays(): void
    {
        $doc = $this->purchase('PF-ZAS', 'DOD-ZAS', 400.00);
        $this->postPurchase($doc, [['501', 'debit', 400.00], ['321', 'credit', 400.00]]);
        $meta = ['entry_date' => self::YEAR . '-12-31', 'posted_by' => $this->userId];
        $slot = ClosingSourceId::STOCK_SLOT_BASE + $this->periodId * 10 + ClosingSourceId::SLOT_STOCK_CLOSING;
        $stock = $this->posting->postDocument($this->supplierId, 'closing', $slot, self::lines([['112', 'debit', 150.00], ['501', 'credit', 150.00]]), $meta);
        $this->posting->postDocument($this->supplierId, 'closing', $this->periodId, self::lines([['710', 'debit', 250.00], ['501', 'credit', 250.00]]), $meta);
        $this->posting->postDocument($this->supplierId, 'opening', $this->periodId, self::lines([['501', 'debit', 10.00], ['701', 'credit', 10.00]]),
            ['entry_date' => self::DATE, 'posted_by' => $this->userId]);

        $docs = $this->byDoc($this->service->build($this->supplierId, [], '5', '5')['documents']);

        self::assertSame(['purchase_invoice:' . $doc, 'closing:' . $slot], array_keys($docs));
        self::assertSame(-150.00, $docs['closing:' . $slot]['amount']);
        self::assertSame('Uzávěrka zásob', $docs['closing:' . $slot]['kind_label']);
        self::assertSame([$stock], $docs['closing:' . $slot]['entry_ids']);
    }

    public function testForeignSourceIdSelectsNothing(): void
    {
        [$other] = $this->company();
        $foreign = $this->purchase('PF-CIZI2', 'DOD-CIZI2', 100.00, $other);
        $this->postPurchase($foreign, [['518', 'debit', 100.00], ['321', 'credit', 100.00]], self::DATE, $other);
        $own = $this->purchase('PF-VLASTNI', 'DOD-VLASTNI', 100.00);
        $this->postPurchase($own, [['518', 'debit', 100.00], ['321', 'credit', 100.00]]);

        $sheet = $this->sheet($this->call(self::LINES + ['source_type' => 'purchase_invoice', 'source_id' => (string) $foreign])['raw']);
        self::assertSame('Filtru neodpovídá žádný doklad s částkou k doložení.', $sheet->getCell('A9')->getValue());
    }

    // ── pomocné ───────────────────────────────────────────────────────────────

    /** @return array{0:int, 1:int} firma a její období */
    private function company(): array
    {
        $id = $this->createIsolatedSupplier($this->db->pdo(), $this->sourceSupplierId);
        $this->seeder->seedForSupplier($id);
        $periodId = (int) $this->periods->create($id, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $this->db->pdo()->prepare("UPDATE supplier SET accounting_mode = 'double_entry', supplier_group_id = NULL WHERE id = ?")->execute([$id]);
        $this->dimensions->setEnabled($id, true);
        return [$id, $periodId];
    }

    private function value(int $supplierId, int $typeId, string $code, ?int $parentId = null): int
    {
        return (int) $this->dimensions->createValue($supplierId, $typeId, ['code' => $code, 'name' => 'Hodnota ' . $code]
            + ($parentId !== null ? ['parent_id' => $parentId] : []))['id'];
    }

    private function client(): int
    {
        $this->db->pdo()->prepare("INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
            SELECT ?, 'Syntetický odběratel', 'Testovací 2', 'Praha', '10000', country_id, 'odberatel@example.invalid', default_currency_id FROM supplier WHERE id = ?")
            ->execute([$this->supplierId, $this->supplierId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function purchase(string $number, string $vendorNumber, float $total, ?int $supplierId = null): int
    {
        $supplierId ??= $this->supplierId;
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
            SELECT ?, 'Syntetický dodavatel', 'Testovací 1', 'Praha', '10000', country_id, 'soupiska@example.invalid', default_currency_id FROM supplier WHERE id = ?")
            ->execute([$supplierId, $supplierId]);
        $vendorId = (int) $pdo->lastInsertId();
        $currencyId = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $pdo->prepare("INSERT INTO purchase_invoices (supplier_id, vendor_id, varsymbol, vendor_invoice_number, issue_date, tax_date, due_date, received_at,
                currency_id, vendor_snapshot, status, created_by, total_without_vat, total_vat, total_with_vat)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, '{}', 'received', ?, ?, 0, ?)")
            ->execute([$supplierId, $vendorId, $number, $vendorNumber, self::DATE, self::DATE, self::DATE, self::DATE, $currencyId, $this->userId, $total, $total]);
        return (int) $pdo->lastInsertId();
    }

    /** @param list<array{0:string,1:string,2:float,3?:array<string,mixed>}> $lines */
    private function postPurchase(int $purchaseId, array $lines, string $date = self::DATE, ?int $supplierId = null): int
    {
        return $this->posting->postDocument($supplierId ?? $this->supplierId, 'purchase_invoice', $purchaseId, self::lines($lines),
            ['entry_date' => $date, 'posted_by' => $this->userId]);
    }

    /** @param list<array{0:string,1:string,2:float,3?:array<string,mixed>}> $lines */
    private function manual(array $lines): int
    {
        return $this->posting->postDocument($this->supplierId, 'manual', null, self::lines($lines),
            ['entry_date' => self::DATE, 'posted_by' => $this->userId]);
    }

    /**
     * @param list<array{0:string,1:string,2:float,3?:array<string,mixed>}> $lines
     * @return list<array<string,mixed>>
     */
    private static function lines(array $lines): array
    {
        return array_map(static fn (array $l): array => ['account_code' => $l[0], 'side' => $l[1], 'amount' => $l[2]] + ($l[3] ?? []), $lines);
    }

    /** @return array{id:int, entry:int} */
    private function internalDocument(string $number, float $amount): array
    {
        $entry = $this->manual([['518', 'debit', $amount, ['dimensions' => [$this->type => $this->valueA]]], ['211', 'credit', $amount]]);
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO internal_documents (supplier_id, title, document_date, entry_date, amount, status, origin, document_no, partner_name, journal_entry_id, posted_at)
                       VALUES (?, 'Syntetický interní doklad', ?, ?, ?, 'posted', 'draft', ?, 'Syntetický partner', ?, NOW())")
            ->execute([$this->supplierId, self::DATE, self::DATE, $amount, $number, $entry]);
        return ['id' => (int) $pdo->lastInsertId(), 'entry' => $entry];
    }

    private function bankTransaction(float $amount): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO bank_statements (file_name, file_hash, account_number, statement_date, transaction_count, matched_count)
                       VALUES (?, ?, '1000000005/0100', ?, 0, 0)")
            ->execute(['soupiska-' . uniqid() . '.gpc', sha1(uniqid('', true)), self::DATE]);
        $pdo->prepare("INSERT INTO bank_transactions (statement_id, posted_at, amount, currency, description) VALUES (?, ?, ?, 'CZK', 'Syntetická úhrada')")
            ->execute([(int) $pdo->lastInsertId(), self::DATE, $amount]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * @param list<int> $valueIds
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    private function select(array $valueIds, array $filters = [], bool $descendants = true): array
    {
        $filters += ['dimension' => $this->dimensions->filters($this->supplierId, $valueIds, $descendants)];
        return $this->service->build($this->supplierId, $filters, '5', '5', $descendants)['documents'];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string, array<string,mixed>>
     */
    private function byDoc(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[$row['doc_kind'] . ':' . $row['doc_id']] = $row;
        }
        return $out;
    }

    private function sheet(string $bytes): \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
    {
        $tmp = tempnam(sys_get_temp_dir(), 'soupiska_') . '.xlsx';
        file_put_contents($tmp, $bytes);
        try {
            return IOFactory::load($tmp)->getActiveSheet();
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * @param array<string,string> $query
     * @return array{status:int, contentType:string, disposition:string, raw:string}
     */
    private function call(array $query): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/accounting/reports/journal/document-list')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant', 'name' => 'Syntetická účetní'])
            ->withQueryParams($query);
        $resp = $this->action->documentList($req, new Psr7Response());
        $resp->getBody()->rewind();
        return [
            'status' => $resp->getStatusCode(),
            'contentType' => $resp->getHeaderLine('Content-Type'),
            'disposition' => $resp->getHeaderLine('Content-Disposition'),
            'raw' => (string) $resp->getBody(),
        ];
    }
}
